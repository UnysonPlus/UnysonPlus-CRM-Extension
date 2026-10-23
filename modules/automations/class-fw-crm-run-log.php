<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The run log: one row per thing that happened in a run (entered, each step done, failed or
 * skipped with the reason, goal reached, left, finished). Old rows are pruned after RETENTION days.
 */
class FW_CRM_Run_Log {

	const RETENTION = 90;

	/**
	 * @param int        $run_id
	 * @param object|int $automation
	 * @param int        $contact_id
	 * @param string     $step_id
	 * @param string     $action  What ran: an action slug, or start|wait|condition|split|goal|exit|done.
	 * @param string     $status  ok|failed|skipped
	 * @param string     $message
	 */
	public static function write( $run_id, $automation, $contact_id, $step_id, $action, $status, $message ) {
		global $wpdb;

		$wpdb->insert( FW_CRM_Automations::table( 'automation_log' ), array(
			'run_id'        => (int) $run_id,
			'automation_id' => is_object( $automation ) ? (int) $automation->id : (int) $automation,
			'contact_id'    => (int) $contact_id,
			'step_id'       => substr( (string) $step_id, 0, 20 ),
			'action'        => substr( (string) $action, 0, 64 ),
			'status'        => in_array( $status, array( 'ok', 'failed', 'skipped' ), true ) ? $status : 'ok',
			'message'       => substr( wp_strip_all_tags( (string) $message ), 0, 255 ),
			'created_at'    => current_time( 'mysql' ),
		) );
	}

	/**
	 * @param int $run_id
	 *
	 * @return object[] Oldest first.
	 */
	public static function for_run( $run_id ) {
		global $wpdb;

		$t = FW_CRM_Automations::table( 'automation_log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d ORDER BY id ASC", (int) $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param int    $automation_id
	 * @param int    $limit
	 * @param string $status ''|ok|failed|skipped
	 *
	 * @return object[] Newest first.
	 */
	public static function for_automation( $automation_id, $limit = 100, $status = '' ) {
		global $wpdb;

		$t   = FW_CRM_Automations::table( 'automation_log' );
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE automation_id = %d", (int) $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' !== $status ) {
			$sql .= $wpdb->prepare( ' AND status = %s', $status );
		}
		return (array) $wpdb->get_results( $sql . $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * How runs ended: succeeded (finished with nothing failed), partly done (finished, something
	 * failed), failed (stopped on an error), plus those still inside.
	 *
	 * @param int $automation_id
	 *
	 * @return array { inside, succeeded, partly, failed, exited }
	 */
	public static function summary( $automation_id ) {
		global $wpdb;

		$r   = FW_CRM_Automations::table( 'automation_runs' );
		$l   = FW_CRM_Automations::table( 'automation_log' );
		$out = array( 'inside' => 0, 'succeeded' => 0, 'partly' => 0, 'failed' => 0, 'exited' => 0 );
		$out['inside'] = FW_CRM_Automations::count_runs( $automation_id, array( 'active', 'waiting' ) );
		$out['failed'] = FW_CRM_Automations::count_runs( $automation_id, array( 'failed' ) );
		$out['exited'] = FW_CRM_Automations::count_runs( $automation_id, array( 'exited' ) );
		$done          = FW_CRM_Automations::count_runs( $automation_id, array( 'completed' ) );
		$out['partly'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT r.id) FROM {$r} r JOIN {$l} g ON g.run_id = r.id WHERE r.automation_id = %d AND r.status = 'completed' AND g.status = 'failed'", (int) $automation_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out['succeeded'] = max( 0, $done - $out['partly'] );
		return $out;
	}

	/** Remove log rows older than the retention period. */
	public static function prune() {
		global $wpdb;

		$t = FW_CRM_Automations::table( 'automation_log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s LIMIT 5000", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::RETENTION * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp
	}
}
