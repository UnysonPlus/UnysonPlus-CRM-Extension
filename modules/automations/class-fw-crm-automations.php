<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Automations: an automation starts on a trigger and runs steps for one contact at a time.
 *
 * Storage:
 * - `automations`: the title, status (draft | active | paused), the trigger and its settings,
 *   settings (re-entry, exit conditions) and the current version number.
 * - `automation_versions`: the steps of each version. Saving the steps of an automation that
 *   people are already inside creates a new version; their runs keep the version they started on.
 * - `automation_runs`: one row per contact per run, with the step it is on and when it is next due.
 *   FW_CRM_Runner picks up due rows in small batches.
 *
 * Steps are a tree:
 *     { id, type: action|wait|condition|split|goal, action?, settings{}, branches?{ yes:[], no:[] } | { a:[], b:[] } }
 * A step's successor is the next step in its list, or, at the end of a branch, the successor of
 * the step that owns the branch. Running out of steps completes the run.
 *
 * Triggers and actions are registered by filter (see FW_CRM_Trigger, FW_CRM_Action and the
 * "Automation registration" section of the developer docs). Anything can start automations with
 *     do_action( 'unysonplus_crm_automation_event', $slug, $contact_id, $context );
 */
class FW_CRM_Automations {

	public static function init() {
		add_action( 'unysonplus_crm_automation_event', array( __CLASS__, 'event' ), 10, 3 );
		FW_CRM_Trigger::init();
		FW_CRM_Runner::init();
		FW_CRM_Cron_Check::init();
	}

	/* ---------------------------------------------------------------------- *
	 * Registry
	 * ---------------------------------------------------------------------- */

	/** @return array slug => { label, group, fields, match? } */
	public static function triggers() {
		static $cache = null;
		if ( null === $cache ) {
			/** Filters the automation triggers. See the developer docs, "Automation registration". */
			$cache = (array) apply_filters( 'unysonplus_crm_automation_triggers', FW_CRM_Trigger::builtin() );
		}
		return $cache;
	}

	/** @return array slug => { label, group, settings, callback } */
	public static function actions() {
		static $cache = null;
		if ( null === $cache ) {
			/** Filters the automation actions. See the developer docs, "Automation registration". */
			$cache = (array) apply_filters( 'unysonplus_crm_automation_actions', FW_CRM_Action::builtin() );
		}
		return $cache;
	}

	/* ---------------------------------------------------------------------- *
	 * Repository
	 * ---------------------------------------------------------------------- */

	/** @param string $name */
	public static function table( $name = 'automations' ) {
		return FW_CRM_Installer::table( $name );
	}

	/**
	 * @param int $id
	 *
	 * @return object|null With decoded trigger_settings and settings.
	 */
	public static function find( $id ) {
		global $wpdb;

		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * @param array $args status, trigger
	 *
	 * @return object[]
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$t = self::table();
		$w = array( '1=1' );
		if ( ! empty( $args['status'] ) ) {
			$w[] = $wpdb->prepare( 'status = %s', (string) $args['status'] );
		}
		if ( ! empty( $args['trigger'] ) ) {
			$w[] = $wpdb->prepare( 'trigger_type = %s', (string) $args['trigger'] );
		}
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE " . implode( ' AND ', $w ) . ' ORDER BY updated_at DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	/** @param object $row */
	private static function decode( $row ) {
		$row->trigger_settings = json_decode( (string) $row->trigger_settings, true ) ?: array();
		$row->settings         = array_merge( self::default_settings(), json_decode( (string) $row->settings, true ) ?: array() );
		return $row;
	}

	/** @return array */
	public static function default_settings() {
		return array(
			'reentry' => 'once',          // once | after_finish | always
			'exit'    => array( 'match' => 'any', 'rules' => array() ), // leave when these are true
		);
	}

	/**
	 * The steps of a version (the current one by default).
	 *
	 * @param int      $id
	 * @param int|null $version
	 *
	 * @return array
	 */
	public static function steps( $id, $version = null ) {
		global $wpdb;

		if ( null === $version ) {
			$a       = self::find( $id );
			$version = $a ? (int) $a->version : 0;
		}
		$t    = self::table( 'automation_versions' );
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT steps FROM {$t} WHERE automation_id = %d AND version = %d", (int) $id, (int) $version ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$s    = json_decode( (string) $json, true );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Create or change an automation. New steps become a new version only when they differ
	 * and someone is already inside the current one; otherwise the current version is rewritten.
	 *
	 * @param array $data id, title, trigger_type, trigger_settings, settings, steps
	 *
	 * @return int|WP_Error
	 */
	public static function save( array $data ) {
		global $wpdb;

		$id    = (int) ( $data['id'] ?? 0 );
		$title = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Give the automation a name.', 'fw' ) );
		}
		$trigger = (string) ( $data['trigger_type'] ?? '' );
		if ( '' !== $trigger && ! isset( self::triggers()[ $trigger ] ) ) {
			return new WP_Error( 'fw_crm_trigger', __( 'Unknown trigger.', 'fw' ) );
		}
		$now = current_time( 'mysql' );
		$row = array(
			'title'            => $title,
			'trigger_type'     => $trigger,
			'trigger_settings' => wp_json_encode( self::clean_values( (array) ( $data['trigger_settings'] ?? array() ) ) ),
			'settings'         => wp_json_encode( self::clean_settings( (array) ( $data['settings'] ?? array() ) ) ),
			'updated_at'       => $now,
		);
		if ( $id ) {
			$before = self::find( $id );
			if ( ! $before ) {
				return new WP_Error( 'fw_crm_not_found', __( 'Automation not found.', 'fw' ) );
			}
			$wpdb->update( self::table(), $row, array( 'id' => $id ) );
		} else {
			$row = array_merge( $row, array( 'status' => 'draft', 'version' => 0, 'created_by' => get_current_user_id(), 'created_at' => $now ) );
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the automation.', 'fw' ) );
			}
		}
		if ( array_key_exists( 'steps', $data ) ) {
			self::save_steps( $id, self::clean_steps( (array) $data['steps'] ) );
		}
		/** Fires after an automation is saved. */
		do_action( 'unysonplus_crm_automation_saved', self::find( $id ) );
		return $id;
	}

	/**
	 * @param int   $id
	 * @param array $steps
	 */
	private static function save_steps( $id, array $steps ) {
		global $wpdb;

		$a       = self::find( $id );
		$version = (int) $a->version;
		$json    = wp_json_encode( $steps );
		$t       = self::table( 'automation_versions' );
		if ( $version && wp_json_encode( self::steps( $id, $version ) ) === $json ) {
			return;
		}
		$inside = $version ? self::count_runs( $id, array( 'active', 'waiting' ), $version ) : 0;
		if ( ! $version || $inside ) {
			$version++;
			$wpdb->insert( $t, array( 'automation_id' => $id, 'version' => $version, 'steps' => $json, 'created_at' => current_time( 'mysql' ) ) );
			$wpdb->update( self::table(), array( 'version' => $version ), array( 'id' => $id ) );
		} else {
			$wpdb->update( $t, array( 'steps' => $json ), array( 'automation_id' => $id, 'version' => $version ) );
		}
	}

	/**
	 * @param int    $id
	 * @param string $status active|paused|draft
	 *
	 * @return true|WP_Error
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$a = self::find( $id );
		if ( ! $a ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Automation not found.', 'fw' ) );
		}
		if ( 'active' === $status ) {
			if ( '' === $a->trigger_type ) {
				return new WP_Error( 'fw_crm_no_trigger', __( 'Choose what starts the automation first.', 'fw' ) );
			}
			if ( ! self::steps( $id ) ) {
				return new WP_Error( 'fw_crm_no_steps', __( 'Add at least one step first.', 'fw' ) );
			}
		}
		$wpdb->update( self::table(), array( 'status' => in_array( $status, array( 'active', 'paused', 'draft' ), true ) ? $status : 'paused', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Delete an automation, its versions, runs and log.
	 *
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;

		foreach ( array( 'automation_versions', 'automation_runs', 'automation_log' ) as $t ) {
			$wpdb->delete( self::table( $t ), array( 'automation_id' => (int) $id ) );
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * A copy, as a draft.
	 *
	 * @param int $id
	 *
	 * @return int|WP_Error
	 */
	public static function duplicate( $id ) {
		$a = self::find( $id );
		if ( ! $a ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Automation not found.', 'fw' ) );
		}
		return self::save( array(
			'title'            => sprintf( __( '%s (copy)', 'fw' ), $a->title ),
			'trigger_type'     => $a->trigger_type,
			'trigger_settings' => $a->trigger_settings,
			'settings'         => $a->settings,
			'steps'            => self::steps( $id ),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Starting
	 * ---------------------------------------------------------------------- */

	/**
	 * Something happened to a contact: start every active automation whose trigger matches,
	 * and move people already inside automations whose goal it is.
	 *
	 * @param string $slug
	 * @param int    $contact_id
	 * @param array  $context Plain values. `dedupe` stops the same event starting a run twice.
	 */
	public static function event( $slug, $contact_id, $context = array() ) {
		$contact_id = (int) $contact_id;
		$context    = is_array( $context ) ? $context : array();
		if ( ! $contact_id || FW_CRM_Automation_Guard::too_deep( $slug, $contact_id ) ) {
			return;
		}
		FW_CRM_Goals::on_event( (string) $slug, $contact_id, $context );
		foreach ( self::all( array( 'status' => 'active', 'trigger' => (string) $slug ) ) as $a ) {
			if ( self::trigger_matches( $a, $context ) ) {
				self::start( $a, $contact_id, $context );
			}
		}
	}

	/**
	 * @param object $a
	 * @param array  $context
	 *
	 * @return bool
	 */
	public static function trigger_matches( $a, array $context ) {
		$def = self::triggers()[ $a->trigger_type ] ?? null;
		if ( ! $def ) {
			return false;
		}
		if ( isset( $def['match'] ) && is_callable( $def['match'] ) ) {
			return (bool) call_user_func( $def['match'], $a->trigger_settings, $context );
		}
		// Default: every set field equals the event's value (or is among the chosen values).
		foreach ( (array) $a->trigger_settings as $key => $want ) {
			if ( '' === $want || array() === $want || null === $want ) {
				continue;
			}
			$have = $context[ $key ] ?? null;
			if ( is_array( $want ) ? ! in_array( (string) $have, array_map( 'strval', $want ), true ) : (string) $have !== (string) $want ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Put a contact into an automation (subject to its re-entry rule and the loop guard).
	 *
	 * @param object|int $automation
	 * @param int        $contact_id
	 * @param array      $context
	 * @param array      $opts { test: bool, force: bool (skip re-entry rules) }
	 *
	 * @return int|WP_Error The run id.
	 */
	public static function start( $automation, $contact_id, array $context = array(), array $opts = array() ) {
		global $wpdb;

		$a = is_object( $automation ) ? $automation : self::find( (int) $automation );
		if ( ! $a ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Automation not found.', 'fw' ) );
		}
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		if ( ! $contact || ! empty( $contact->deleted_at ) ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		$dedupe = substr( (string) ( $context['dedupe'] ?? '' ), 0, 190 );
		if ( empty( $opts['force'] ) ) {
			$ok = FW_CRM_Automation_Guard::may_enter( $a, (int) $contact_id, $dedupe );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		unset( $context['dedupe'] );
		$steps = self::steps( (int) $a->id, (int) $a->version );
		$first = $steps ? (string) $steps[0]['id'] : '';
		$now   = current_time( 'mysql' );
		$wpdb->insert( self::table( 'automation_runs' ), array(
			'automation_id' => (int) $a->id,
			'contact_id'    => (int) $contact_id,
			'version'       => (int) $a->version,
			'status'        => '' === $first ? 'completed' : 'active',
			'step_id'       => $first,
			'next_run_at'   => $now,
			'context'       => wp_json_encode( self::plain( $context ) ),
			'dedupe'        => $dedupe,
			'is_test'       => empty( $opts['test'] ) ? 0 : 1,
			'started_at'    => $now,
			'finished_at'   => '' === $first ? $now : null,
		) );
		$run_id = (int) $wpdb->insert_id;
		FW_CRM_Run_Log::write( $run_id, $a, (int) $contact_id, '', 'start', 'ok', empty( $opts['test'] ) ? __( 'Entered the automation', 'fw' ) : __( 'Test run started', 'fw' ) );
		FW_CRM_Activity::log( 'contact', (int) $contact_id, 'automation', sprintf( __( 'Entered the automation "%s"', 'fw' ), $a->title ), array( 'meta' => array( 'automation_id' => (int) $a->id, 'run_id' => $run_id ), 'actor_id' => 0 ) );
		/** Fires after a contact enters an automation. */
		do_action( 'unysonplus_crm_automation_started', $run_id, $a, (int) $contact_id );

		// The first steps run straight away when this is not a background request, so a welcome email
		// does not wait for the next scheduler tick; anything that waits is left to the runner.
		if ( ! empty( $opts['test'] ) || apply_filters( 'unysonplus_crm_automation_run_now', true, $a ) ) {
			FW_CRM_Runner::run_one( $run_id );
		}
		return $run_id;
	}

	/* ---------------------------------------------------------------------- *
	 * Runs
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $run_id
	 *
	 * @return object|null With decoded context.
	 */
	public static function run( $run_id ) {
		global $wpdb;

		$t = self::table( 'automation_runs' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $r ) {
			$r->context = json_decode( (string) $r->context, true ) ?: array();
		}
		return $r;
	}

	/**
	 * @param int $run_id
	 * @param array $data
	 */
	public static function update_run( $run_id, array $data ) {
		global $wpdb;

		if ( isset( $data['context'] ) && is_array( $data['context'] ) ) {
			$data['context'] = wp_json_encode( self::plain( $data['context'] ) );
		}
		$wpdb->update( self::table( 'automation_runs' ), $data, array( 'id' => (int) $run_id ) );
	}

	/**
	 * @param int         $automation_id
	 * @param string[]    $statuses
	 * @param int|null    $version
	 *
	 * @return int
	 */
	public static function count_runs( $automation_id, array $statuses = array(), $version = null ) {
		global $wpdb;

		$t   = self::table( 'automation_runs' );
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE automation_id = %d", (int) $automation_id ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $statuses ) {
			$sql .= " AND status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		}
		if ( null !== $version ) {
			$sql .= $wpdb->prepare( ' AND version = %d', (int) $version );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * People currently at each step (for the editor).
	 *
	 * @param int $automation_id
	 *
	 * @return array step_id => count
	 */
	public static function counts_by_step( $automation_id ) {
		global $wpdb;

		$t    = self::table( 'automation_runs' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT step_id, COUNT(*) AS n FROM {$t} WHERE automation_id = %d AND status IN ('active','waiting') AND is_test = 0 GROUP BY step_id", (int) $automation_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r->step_id ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * A contact's runs, newest first.
	 *
	 * @param int $contact_id
	 * @param int $limit
	 *
	 * @return object[]
	 */
	public static function runs_for_contact( $contact_id, $limit = 20 ) {
		global $wpdb;

		$t = self::table( 'automation_runs' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE contact_id = %d ORDER BY id DESC LIMIT %d", (int) $contact_id, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * End a contact's open runs of an automation (or of every automation but one).
	 *
	 * @param int    $contact_id
	 * @param int    $automation_id 0 = all
	 * @param int    $except_run
	 * @param string $reason
	 *
	 * @return int How many were ended.
	 */
	public static function exit_runs( $contact_id, $automation_id = 0, $except_run = 0, $reason = '' ) {
		global $wpdb;

		$t   = self::table( 'automation_runs' );
		$sql = $wpdb->prepare( "SELECT id, automation_id FROM {$t} WHERE contact_id = %d AND status IN ('active','waiting') AND id <> %d", (int) $contact_id, (int) $except_run ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $automation_id ) {
			$sql .= $wpdb->prepare( ' AND automation_id = %d', (int) $automation_id );
		}
		$n = 0;
		foreach ( (array) $wpdb->get_results( $sql ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			self::update_run( (int) $r->id, array( 'status' => 'exited', 'finished_at' => current_time( 'mysql' ), 'claim_token' => '' ) );
			FW_CRM_Run_Log::write( (int) $r->id, (int) $r->automation_id, (int) $contact_id, '', 'exit', 'ok', '' !== $reason ? $reason : __( 'Left the automation', 'fw' ) );
			$n++;
		}
		return $n;
	}

	/* ---------------------------------------------------------------------- *
	 * Step tree helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Flatten the tree: id => { step, successor id ('' = end) }.
	 *
	 * @param array  $steps
	 * @param string $after The successor of the list's last step.
	 * @param array  $index
	 *
	 * @return array
	 */
	public static function index( array $steps, $after = '', array &$index = array() ) {
		$count = count( $steps );
		foreach ( array_values( $steps ) as $i => $s ) {
			$next                    = $i + 1 < $count ? (string) $steps[ $i + 1 ]['id'] : $after;
			$index[ (string) $s['id'] ] = array( 'step' => $s, 'next' => $next );
			foreach ( (array) ( $s['branches'] ?? array() ) as $branch ) {
				self::index( (array) $branch, $next, $index );
			}
		}
		return $index;
	}

	/**
	 * A step's label for logs and the contact page.
	 *
	 * @param array $step
	 *
	 * @return string
	 */
	public static function step_label( array $step ) {
		switch ( $step['type'] ?? '' ) {
			case 'wait':
				return __( 'Wait', 'fw' );
			case 'condition':
				return __( 'If / else', 'fw' );
			case 'split':
				return __( 'Random split', 'fw' );
			case 'goal':
				return sprintf( __( 'Goal: %s', 'fw' ), self::triggers()[ $step['settings']['trigger'] ?? '' ]['label'] ?? '' );
		}
		return self::actions()[ $step['action'] ?? '' ]['label'] ?? ( $step['action'] ?? '' );
	}

	/* ---------------------------------------------------------------------- *
	 * Cleaning input
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $steps
	 * @param int   $depth
	 *
	 * @return array
	 */
	public static function clean_steps( array $steps, $depth = 0 ) {
		$out = array();
		if ( $depth > 8 ) {
			return $out;
		}
		foreach ( $steps as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$type = in_array( $s['type'] ?? '', array( 'action', 'wait', 'condition', 'split', 'goal' ), true ) ? $s['type'] : 'action';
			$id   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $s['id'] ?? '' ) ) );
			$step = array(
				'id'       => '' !== $id ? substr( $id, 0, 20 ) : 's' . substr( md5( uniqid( '', true ) ), 0, 8 ),
				'type'     => $type,
				'settings' => self::clean_values( (array) ( $s['settings'] ?? array() ) ),
			);
			if ( 'action' === $type ) {
				$step['action'] = sanitize_text_field( (string) ( $s['action'] ?? '' ) );
				if ( ! isset( self::actions()[ $step['action'] ] ) ) {
					continue;
				}
			}
			if ( 'condition' === $type ) {
				$step['branches'] = array(
					'yes' => self::clean_steps( (array) ( $s['branches']['yes'] ?? array() ), $depth + 1 ),
					'no'  => self::clean_steps( (array) ( $s['branches']['no'] ?? array() ), $depth + 1 ),
				);
			}
			if ( 'split' === $type ) {
				$step['branches'] = array(
					'a' => self::clean_steps( (array) ( $s['branches']['a'] ?? array() ), $depth + 1 ),
					'b' => self::clean_steps( (array) ( $s['branches']['b'] ?? array() ), $depth + 1 ),
				);
			}
			$out[] = $step;
		}
		return $out;
	}

	/**
	 * Settings values: strings, numbers, booleans and nested arrays of them. Email bodies keep safe HTML.
	 *
	 * @param array $v
	 * @param int   $depth
	 *
	 * @return array
	 */
	public static function clean_values( array $v, $depth = 0 ) {
		$out = array();
		foreach ( $v as $k => $val ) {
			$k = is_int( $k ) ? $k : preg_replace( '/[^a-zA-Z0-9_\-:.]/', '', (string) $k );
			if ( is_array( $val ) ) {
				$out[ $k ] = $depth < 5 ? self::clean_values( $val, $depth + 1 ) : array();
			} elseif ( is_bool( $val ) || is_int( $val ) || is_float( $val ) ) {
				$out[ $k ] = $val;
			} else {
				$out[ $k ] = 'body' === $k || 'message' === $k ? wp_kses_post( (string) $val ) : sanitize_textarea_field( (string) $val );
			}
		}
		return $out;
	}

	/**
	 * @param array $s
	 *
	 * @return array
	 */
	private static function clean_settings( array $s ) {
		$d = self::default_settings();
		return array(
			'reentry' => in_array( $s['reentry'] ?? '', array( 'once', 'after_finish', 'always' ), true ) ? $s['reentry'] : $d['reentry'],
			'exit'    => FW_CRM_Conditions::clean( (array) ( $s['exit'] ?? array() ) ),
		);
	}

	/**
	 * Context is stored with the run, so only plain values survive.
	 *
	 * @param array $c
	 *
	 * @return array
	 */
	public static function plain( array $c ) {
		$out = array();
		foreach ( $c as $k => $v ) {
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = $v;
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = self::plain( $v );
			}
		}
		return $out;
	}
}
