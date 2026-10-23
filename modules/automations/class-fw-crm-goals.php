<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Goals and exit conditions.
 *
 * - A **goal** is a step (type `goal`) that names a trigger, such as "deal won" or "form submitted".
 *   When that event happens to someone who is still on an earlier step, they jump straight to the
 *   goal and carry on from there: a follow-up series stops chasing a person who has already booked.
 *   Reached in the normal course of the flow, a goal is simply passed.
 * - **Exit conditions** belong to the automation ("leave when the contact has the tag customer").
 *   They are checked before every step; when they are met, the contact leaves.
 */
class FW_CRM_Goals {

	/**
	 * An event happened: move anyone in an automation that has it as a goal.
	 *
	 * @param string $slug
	 * @param int    $contact_id
	 * @param array  $context
	 */
	public static function on_event( $slug, $contact_id, array $context ) {
		global $wpdb;

		$t    = FW_CRM_Automations::table( 'automation_runs' );
		$runs = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, automation_id, version, step_id FROM {$t} WHERE contact_id = %d AND status IN ('active','waiting')", (int) $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $runs as $r ) {
			$index = FW_CRM_Automations::index( FW_CRM_Automations::steps( (int) $r->automation_id, (int) $r->version ) );
			foreach ( $index as $id => $node ) {
				$step = $node['step'];
				if ( 'goal' !== ( $step['type'] ?? '' ) || ( $step['settings']['trigger'] ?? '' ) !== $slug || (string) $r->step_id === (string) $id ) {
					continue;
				}
				$fake = (object) array( 'trigger_type' => $slug, 'trigger_settings' => (array) ( $step['settings']['match'] ?? array() ) );
				if ( ! FW_CRM_Automations::trigger_matches( $fake, $context ) || ! self::is_ahead( $index, (string) $r->step_id, (string) $id ) ) {
					continue;
				}
				FW_CRM_Automations::update_run( (int) $r->id, array( 'step_id' => (string) $id, 'status' => 'active', 'next_run_at' => current_time( 'mysql' ) ) );
				FW_CRM_Run_Log::write( (int) $r->id, (int) $r->automation_id, (int) $contact_id, (string) $id, 'goal', 'ok', sprintf( __( 'Reached the goal "%s"', 'fw' ), FW_CRM_Automations::step_label( $step ) ) );
				FW_CRM_Runner::run_one( (int) $r->id );
				break;
			}
		}
	}

	/**
	 * Is the goal later in the flow than where the contact is now (so a goal never sends people back)?
	 *
	 * @param array  $index
	 * @param string $current
	 * @param string $goal
	 *
	 * @return bool
	 */
	private static function is_ahead( array $index, $current, $goal ) {
		$seen = array();
		$id   = $current;
		while ( '' !== $id && isset( $index[ $id ] ) && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$step        = $index[ $id ]['step'];
			if ( $id === $goal ) {
				return true;
			}
			// A goal inside a branch ahead of us counts as ahead too.
			foreach ( (array) ( $step['branches'] ?? array() ) as $branch ) {
				foreach ( FW_CRM_Automations::index( (array) $branch ) as $bid => $n ) {
					if ( (string) $bid === $goal ) {
						return true;
					}
				}
			}
			$id = $index[ $id ]['next'];
		}
		return false;
	}

	/**
	 * Should the contact leave the automation now?
	 *
	 * @param object $automation
	 * @param object $run
	 *
	 * @return bool
	 */
	public static function should_exit( $automation, $run ) {
		$group = (array) ( $automation->settings['exit'] ?? array() );
		return ! empty( $group['rules'] ) && FW_CRM_Conditions::check( $group, (int) $run->contact_id, $run );
	}
}
