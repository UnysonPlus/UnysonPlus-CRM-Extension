<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Runs automations: every minute it claims the runs that are due, in small batches, and walks
 * each one through its steps until it waits, finishes or the time budget is used up.
 *
 * - Claiming is one UPDATE … LIMIT with a random token, so two overlapping scheduler calls never
 *   run the same contact's step twice. A claim older than STALE minutes (a request that died)
 *   is released.
 * - The step a run is on is saved after every step, so a timeout resumes where it stopped.
 * - An action that fails is logged and the run carries on (it ends "partly done"); an automation
 *   that was deleted ends its runs as failed. A paused automation keeps its people where they are.
 */
class FW_CRM_Runner {

	const CRON_HOOK  = 'fw_crm_automations_tick';
	const DAILY_HOOK = 'fw_crm_automations_daily';
	const BATCH      = 50;
	const BUDGET     = 20;   // seconds per tick
	const STALE      = 10;   // minutes
	const MAX_STEPS  = 60;   // per run per pass: a safety net against a malformed flow

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, '_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'fw_crm_minute', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/** @internal */
	public static function _schedule( $schedules ) {
		if ( ! isset( $schedules['fw_crm_minute'] ) ) {
			$schedules['fw_crm_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => __( 'Every minute (CRM)', 'fw' ) );
		}
		return $schedules;
	}

	/** Stop the jobs (module switched off, data removed). */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * One scheduler tick: run what is due.
	 *
	 * @param int $budget Seconds.
	 *
	 * @return int How many runs were processed.
	 */
	public static function tick( $budget = self::BUDGET ) {
		global $wpdb;

		FW_CRM_Cron_Check::beat( 'automations' );
		$t     = FW_CRM_Automations::table( 'automation_runs' );
		$start = microtime( true );
		$done  = 0;

		// Release claims from requests that died.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET claim_token = '', claimed_at = NULL WHERE claim_token <> '' AND claimed_at < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STALE * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp

		while ( microtime( true ) - $start < $budget ) {
			$token = wp_generate_password( 20, false );
			$now   = current_time( 'mysql' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET claim_token = %s, claimed_at = %s WHERE status IN ('active','waiting') AND next_run_at <= %s AND claim_token = '' ORDER BY next_run_at ASC LIMIT %d", $token, $now, $now, self::BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE claim_token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				if ( microtime( true ) - $start >= $budget ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET claim_token = '', claimed_at = NULL WHERE claim_token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					break 2;
				}
				self::process( (int) $id, $token );
				$done++;
			}
		}
		return $done;
	}

	/** The daily job: date triggers, then log pruning. */
	public static function daily() {
		FW_CRM_Cron_Check::beat( 'automations_daily' );
		FW_CRM_Trigger::scan_dates();
		FW_CRM_Run_Log::prune();
	}

	/**
	 * Run one contact's steps now (used right after someone enters, and when a goal is reached).
	 *
	 * @param int $run_id
	 */
	public static function run_one( $run_id ) {
		global $wpdb;

		$t     = FW_CRM_Automations::table( 'automation_runs' );
		$token = wp_generate_password( 20, false );
		$now   = current_time( 'mysql' );
		$got   = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET claim_token = %s, claimed_at = %s WHERE id = %d AND status IN ('active','waiting') AND next_run_at <= %s AND claim_token = ''", $token, $now, (int) $run_id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $got ) {
			self::process( (int) $run_id, $token );
		}
	}

	/**
	 * Walk a claimed run forward.
	 *
	 * @param int    $run_id
	 * @param string $token
	 */
	private static function process( $run_id, $token ) {
		$run = FW_CRM_Automations::run( $run_id );
		if ( ! $run || $run->claim_token !== $token ) {
			return;
		}
		$a = FW_CRM_Automations::find( (int) $run->automation_id );
		if ( ! $a ) {
			FW_CRM_Automations::update_run( $run_id, array( 'status' => 'failed', 'last_error' => 'automation deleted', 'finished_at' => current_time( 'mysql' ), 'claim_token' => '' ) );
			return;
		}
		if ( 'active' !== $a->status && ! $run->is_test ) {
			// Paused or back to draft: nobody moves until it is switched on again.
			FW_CRM_Automations::update_run( $run_id, array( 'claim_token' => '', 'claimed_at' => null, 'next_run_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 15 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			return;
		}
		$index   = FW_CRM_Automations::index( FW_CRM_Automations::steps( (int) $a->id, (int) $run->version ) );
		$step_id = (string) $run->step_id;

		for ( $i = 0; $i < self::MAX_STEPS; $i++ ) {
			if ( FW_CRM_Goals::should_exit( $a, $run ) ) {
				FW_CRM_Automations::update_run( $run_id, array( 'status' => 'exited', 'finished_at' => current_time( 'mysql' ), 'claim_token' => '' ) );
				FW_CRM_Run_Log::write( $run_id, $a, (int) $run->contact_id, $step_id, 'exit', 'ok', __( 'Met the exit conditions and left', 'fw' ) );
				return;
			}
			if ( '' === $step_id || ! isset( $index[ $step_id ] ) ) {
				self::finish( $run, $a );
				return;
			}
			$node = $index[ $step_id ];
			$step = $node['step'];
			$next = (string) $node['next'];

			switch ( $step['type'] ) {
				case 'wait':
					$until = FW_CRM_Action::wait_until( (array) $step['settings'], $run );
					if ( $run->is_test ) {
						FW_CRM_Run_Log::write( $run_id, $a, (int) $run->contact_id, $step_id, 'wait', 'skipped', sprintf( __( 'Test run: skipped a wait until %s', 'fw' ), $until ) );
						break;
					}
					FW_CRM_Automations::update_run( $run_id, array( 'step_id' => $next, 'status' => 'waiting', 'next_run_at' => $until, 'claim_token' => '', 'claimed_at' => null ) );
					FW_CRM_Run_Log::write( $run_id, $a, (int) $run->contact_id, $step_id, 'wait', 'ok', sprintf( __( 'Waiting until %s', 'fw' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $until ) ) ) );
					return;

				case 'condition':
					$yes  = FW_CRM_Conditions::check( (array) ( $step['settings']['conditions'] ?? array() ), (int) $run->contact_id, $run );
					$list = (array) ( $step['branches'][ $yes ? 'yes' : 'no' ] ?? array() );
					$next = $list ? (string) $list[0]['id'] : $next;
					FW_CRM_Run_Log::write( $run_id, $a, (int) $run->contact_id, $step_id, 'condition', 'ok', $yes ? __( 'Condition met: took the Yes path', 'fw' ) : __( 'Condition not met: took the No path', 'fw' ) );
					break;

				case 'split':
					$branch = FW_CRM_Action_Split::choose( (array) $step['settings'] );
					$list   = (array) ( $step['branches'][ $branch ] ?? array() );
					$next   = $list ? (string) $list[0]['id'] : $next;
					FW_CRM_Run_Log::write( $run_id, $a, (int) $run->contact_id, $step_id, 'split', 'ok', 'a' === $branch ? __( 'Split: path A', 'fw' ) : __( 'Split: path B', 'fw' ) );
					break;

				case 'goal':
					break;

				default:
					$result = self::call_action( $step, $run, $a );
					if ( isset( $result['context'] ) && is_array( $result['context'] ) ) {
						$run->context = array_merge( $run->context, $result['context'] );
						FW_CRM_Automations::update_run( $run_id, array( 'context' => $run->context ) );
					}
					FW_CRM_Run_Log::write( $run_id, $a, (int) $run->contact_id, $step_id, (string) $step['action'], $result['status'], $result['message'] );
			}

			$step_id = $next;
			FW_CRM_Automations::update_run( $run_id, array( 'step_id' => $step_id, 'status' => 'active' ) );
			$run->step_id = $step_id;
		}

		// Too many steps in one go: carry on next tick rather than spin.
		FW_CRM_Automations::update_run( $run_id, array( 'claim_token' => '', 'claimed_at' => null, 'next_run_at' => current_time( 'mysql' ) ) );
	}

	/**
	 * Call an action's callback, catching anything it throws.
	 *
	 * @param array  $step
	 * @param object $run
	 * @param object $a
	 *
	 * @return array { status: ok|failed|skipped, message, context? }
	 */
	private static function call_action( array $step, $run, $a ) {
		$def = FW_CRM_Automations::actions()[ $step['action'] ] ?? null;
		if ( ! $def || ! is_callable( $def['callback'] ?? null ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( __( 'The action "%s" is no longer available.', 'fw' ), $step['action'] ) );
		}
		$ctx = array_merge( (array) $run->context, array(
			'run_id'        => (int) $run->id,
			'automation_id' => (int) $a->id,
			'step_id'       => (string) $step['id'],
			'test'          => (bool) $run->is_test,
		) );
		FW_CRM_Automation_Guard::enter_action();
		try {
			$res = call_user_func( $def['callback'], (int) $run->contact_id, (array) $step['settings'], $ctx );
		} catch ( \Throwable $e ) {
			$res = new WP_Error( 'fw_crm_auto_exception', $e->getMessage() );
		} finally {
			FW_CRM_Automation_Guard::leave_action();
		}
		$label = (string) ( $def['label'] ?? $step['action'] );
		if ( is_wp_error( $res ) ) {
			$skip = 0 === strpos( (string) $res->get_error_code(), 'skip' );
			return array( 'status' => $skip ? 'skipped' : 'failed', 'message' => $label . ': ' . $res->get_error_message() );
		}
		if ( is_array( $res ) ) {
			return array(
				'status'  => in_array( $res['status'] ?? 'ok', array( 'ok', 'failed', 'skipped' ), true ) ? ( $res['status'] ?? 'ok' ) : 'ok',
				'message' => $label . ( ! empty( $res['message'] ) ? ': ' . $res['message'] : '' ),
				'context' => $res['context'] ?? null,
			);
		}
		return array( 'status' => false === $res ? 'failed' : 'ok', 'message' => $label );
	}

	/**
	 * @param object $run
	 * @param object $a
	 */
	private static function finish( $run, $a ) {
		FW_CRM_Automations::update_run( (int) $run->id, array( 'status' => 'completed', 'step_id' => '', 'finished_at' => current_time( 'mysql' ), 'claim_token' => '', 'claimed_at' => null ) );
		FW_CRM_Run_Log::write( (int) $run->id, $a, (int) $run->contact_id, '', 'done', 'ok', __( 'Finished the automation', 'fw' ) );
		/** Fires after a contact finishes an automation. */
		do_action( 'unysonplus_crm_automation_finished', (int) $run->id, $a, (int) $run->contact_id );
	}
}
