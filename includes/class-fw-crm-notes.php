<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Notes on any record (contact, company, deal), stored as timeline entries of
 * type `note` so they sit in the record's history with everything else.
 * Only the person who wrote a note, or a CRM manager, can edit or delete it.
 */
class FW_CRM_Notes {

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 * @param string $body
	 *
	 * @return int|WP_Error The note id.
	 */
	public static function add( $object_type, $object_id, $body ) {
		$body = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $body ) {
			return new WP_Error( 'fw_crm_empty_note', __( 'Write something before saving the note.', 'fw' ) );
		}
		$id = FW_CRM_Activity::log( (string) $object_type, (int) $object_id, 'note', __( 'Note', 'fw' ), array( 'body' => $body ) );
		return $id ? $id : new WP_Error( 'fw_crm_store_failed', __( 'Could not save the note.', 'fw' ) );
	}

	/**
	 * @param int $id
	 *
	 * @return object|null The timeline row, when it is a note.
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = FW_CRM_Activity::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND type = 'note'", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param object $note
	 *
	 * @return bool May the current person change it?
	 */
	public static function can_edit( $note ) {
		return $note && ( (int) $note->actor_id === get_current_user_id() || ( class_exists( 'FW_CRM_Capabilities' ) && FW_CRM_Capabilities::can_manage() ) );
	}

	/**
	 * @param int    $id
	 * @param string $body
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, $body ) {
		global $wpdb;

		$note = self::find( $id );
		if ( ! $note ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Note not found.', 'fw' ) );
		}
		if ( ! self::can_edit( $note ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'Only the person who wrote a note can change it.', 'fw' ) );
		}
		$body = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $body ) {
			return new WP_Error( 'fw_crm_empty_note', __( 'A note cannot be empty. Delete it instead.', 'fw' ) );
		}
		$wpdb->update( FW_CRM_Activity::table(), array( 'body' => $body ), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$note = self::find( $id );
		if ( ! $note ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Note not found.', 'fw' ) );
		}
		if ( ! self::can_edit( $note ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'Only the person who wrote a note can delete it.', 'fw' ) );
		}
		$wpdb->delete( FW_CRM_Activity::table(), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * A record's notes, newest first.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 *
	 * @return array
	 */
	public static function for_record( $object_type, $object_id ) {
		return array_values( array_filter( FW_CRM_Activity::for_record( (string) $object_type, (int) $object_id, 500 ), function ( $r ) {
			return 'note' === $r->type;
		} ) );
	}
}
