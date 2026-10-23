<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Keeps automations safe:
 *
 * - **Loop guard.** An action can cause an event (adding a tag fires "tag added"), which can start
 *   another automation, whose action fires another event… Events raised while actions are running
 *   carry a depth; past MAX_DEPTH they start nothing. On top of that, one contact cannot enter the
 *   same automation more than once a minute, whatever the chain.
 * - **Re-entry.** `once` (default): a contact goes through an automation only once, ever.
 *   `after_finish`: again, but only when they are no longer inside it. `always`: every time.
 * - **Duplicate events.** The same event (its `dedupe` key) never starts the same automation twice.
 * - **Staged edits** live in FW_CRM_Automations::save_steps(): people inside keep their version.
 * - **Test runs** (test_run()): one contact, right now, waits skipped and emails sent to you.
 */
class FW_CRM_Automation_Guard {

	const MAX_DEPTH = 3;

	/** @var int How many actions deep we are in the current request. */
	private static $depth = 0;

	/** @var bool A test run is executing. */
	private static $testing = false;

	public static function enter_action() {
		self::$depth++;
	}

	public static function leave_action() {
		self::$depth = max( 0, self::$depth - 1 );
	}

	/** @return bool */
	public static function testing() {
		return self::$testing;
	}

	/**
	 * Is this event part of a chain that has gone on too long?
	 *
	 * @param string $slug
	 * @param int    $contact_id
	 *
	 * @return bool
	 */
	public static function too_deep( $slug, $contact_id ) {
		if ( self::$depth < self::MAX_DEPTH ) {
			return false;
		}
		/** Fires when the loop guard stops an event from starting automations. */
		do_action( 'unysonplus_crm_automation_loop_stopped', $slug, $contact_id, self::$depth );
		return true;
	}

	/**
	 * May this contact enter this automation now?
	 *
	 * @param object $a
	 * @param int    $contact_id
	 * @param string $dedupe
	 *
	 * @return true|WP_Error
	 */
	public static function may_enter( $a, $contact_id, $dedupe = '' ) {
		global $wpdb;

		$t = FW_CRM_Automations::table( 'automation_runs' );
		if ( '' !== $dedupe && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE automation_id = %d AND dedupe = %s LIMIT 1", (int) $a->id, $dedupe ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return new WP_Error( 'fw_crm_auto_duplicate', __( 'That event already started this automation.', 'fw' ) );
		}
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT status, started_at FROM {$t} WHERE automation_id = %d AND contact_id = %d AND is_test = 0 ORDER BY id DESC LIMIT 1", (int) $a->id, (int) $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $last ) {
			return true;
		}
		if ( strtotime( $last->started_at ) > current_time( 'timestamp' ) - MINUTE_IN_SECONDS ) { // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp
			return new WP_Error( 'fw_crm_auto_rate', __( 'This contact entered the automation less than a minute ago.', 'fw' ) );
		}
		$reentry = (string) ( $a->settings['reentry'] ?? 'once' );
		if ( 'once' === $reentry ) {
			return new WP_Error( 'fw_crm_auto_once', __( 'This contact has already been through this automation.', 'fw' ) );
		}
		if ( 'after_finish' === $reentry && in_array( $last->status, array( 'active', 'waiting' ), true ) ) {
			return new WP_Error( 'fw_crm_auto_inside', __( 'This contact is still inside this automation.', 'fw' ) );
		}
		return true;
	}

	/**
	 * Run an automation for one contact now, to see what it does: waits are skipped and every
	 * email goes to the person testing instead of the contact. Other actions (tags, fields, deals)
	 * really happen, on that contact.
	 *
	 * @param int $automation_id
	 * @param int $contact_id
	 *
	 * @return array|WP_Error The log of the run.
	 */
	public static function test_run( $automation_id, $contact_id ) {
		$a = FW_CRM_Automations::find( $automation_id );
		if ( ! $a ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Automation not found.', 'fw' ) );
		}
		self::$testing = true;
		try {
			$run_id = FW_CRM_Automations::start( $a, (int) $contact_id, array( 'test' => 1 ), array( 'test' => true, 'force' => true ) );
		} finally {
			self::$testing = false;
		}
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}
		return FW_CRM_Run_Log::for_run( (int) $run_id );
	}
}
