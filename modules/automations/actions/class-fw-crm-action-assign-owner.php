<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Round-robin owner assignment: each contact goes to the next person in the list, so new leads
 * are shared out evenly. The turn is kept per automation step, so two automations sharing out
 * different teams never disturb each other.
 */
class FW_CRM_Action_Assign_Owner {

	const OPTION = 'fw_crm_round_robin';

	/**
	 * @param int   $contact_id
	 * @param array $s   { users: user ids, only_unowned: bool }
	 * @param array $ctx
	 *
	 * @return array|WP_Error
	 */
	public static function run( $contact_id, array $s, array $ctx ) {
		$users = array_values( array_filter( array_map( 'intval', (array) ( $s['users'] ?? array() ) ), function ( $id ) {
			return $id > 0 && get_userdata( $id );
		} ) );
		if ( ! $users ) {
			return new WP_Error( 'fw_crm_no_users', __( 'Choose who takes turns.', 'fw' ) );
		}
		$c = FW_CRM_Subscribers::find( (int) $contact_id );
		if ( ! $c ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		if ( ! empty( $s['only_unowned'] ) && (int) $c->owner_id ) {
			return new WP_Error( 'skip_owned', __( 'The contact already has an owner.', 'fw' ) );
		}
		$key   = (int) ( $ctx['automation_id'] ?? 0 ) . ':' . (string) ( $ctx['step_id'] ?? '' );
		$turns = get_option( self::OPTION, array() );
		$turns = is_array( $turns ) ? $turns : array();
		$next  = isset( $turns[ $key ] ) ? ( (int) $turns[ $key ] + 1 ) % count( $users ) : 0;
		$owner = $users[ $next ];
		$turns[ $key ] = $next;
		update_option( self::OPTION, $turns, false );
		$res = FW_CRM_Contacts::update( (int) $contact_id, array( 'owner_id' => $owner ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'message' => get_userdata( $owner )->display_name );
	}
}
