<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The consent log: what each contact agreed to, the exact wording they saw, when
 * and where. Rows are only ever appended, so the log stays a record; withdrawing
 * consent is a new row with granted = 0, not an edit.
 *
 * init() records consent from the CRM's own signup events (the contact form's
 * consent box, the newsletter element, double opt-in confirmation, unsubscribing);
 * other code calls record() directly.
 */
class FW_CRM_Consent {

	public static function init() {
		add_action( 'unysonplus_crm_subscriber_added', array( __CLASS__, '_on_signup' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_resubscribed', array( __CLASS__, '_on_signup' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_opted_in', array( __CLASS__, '_on_signup' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_confirmed', array( __CLASS__, '_on_confirmed' ) );
		add_action( 'unysonplus_crm_subscriber_unsubscribed', array( __CLASS__, '_on_unsubscribed' ) );
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'consent' );
	}

	/**
	 * Append a consent record.
	 *
	 * @param int    $contact_id
	 * @param string $purpose    newsletter, marketing, terms… (a short slug)
	 * @param string $text       The wording the person agreed to.
	 * @param array  $args       granted (bool, default true), source, source_url, ip
	 *
	 * @return int
	 */
	public static function record( $contact_id, $purpose, $text, array $args = array() ) {
		global $wpdb;

		if ( ! $contact_id ) {
			return 0;
		}

		$ok = $wpdb->insert( self::table(), array(
			'contact_id' => (int) $contact_id,
			'purpose'    => substr( sanitize_key( (string) $purpose ), 0, 50 ),
			'granted'    => isset( $args['granted'] ) && ! $args['granted'] ? 0 : 1,
			'text'       => sanitize_textarea_field( (string) $text ),
			'source'     => substr( sanitize_key( (string) ( $args['source'] ?? '' ) ), 0, 50 ),
			'source_url' => esc_url_raw( (string) ( $args['source_url'] ?? '' ) ),
			'ip'         => substr( sanitize_text_field( (string) ( $args['ip'] ?? '' ) ), 0, 45 ),
			'created_at' => current_time( 'mysql' ),
		) );

		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		/** Fires after a consent record is written (granted or withdrawn). */
		do_action( 'unysonplus_crm_consent_recorded', $id, (int) $contact_id, (string) $purpose, ! ( isset( $args['granted'] ) && ! $args['granted'] ) );

		return $id;
	}

	/**
	 * A contact's consent history, newest first.
	 *
	 * @param int $contact_id
	 *
	 * @return array
	 */
	public static function for_contact( $contact_id ) {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $contact_id
		) );
	}

	/**
	 * Whether the latest record for a purpose grants consent.
	 *
	 * @param int    $contact_id
	 * @param string $purpose
	 *
	 * @return bool|null Null when there is no record at all.
	 */
	public static function has( $contact_id, $purpose ) {
		global $wpdb;

		$table = self::table();
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT granted FROM {$table} WHERE contact_id = %d AND purpose = %s ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $contact_id,
			sanitize_key( (string) $purpose )
		) );

		return null === $value ? null : (bool) (int) $value;
	}

	/**
	 * @param int $contact_id
	 *
	 * @return int
	 */
	public static function delete_for( $contact_id ) {
		global $wpdb;

		return (int) $wpdb->delete( self::table(), array( 'contact_id' => (int) $contact_id ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Built-in listeners
	 * ---------------------------------------------------------------------- */

	/**
	 * A signup records what the person agreed to: the consent box's own wording when a
	 * form had one, otherwise what the signup itself meant.
	 *
	 * @internal
	 */
	public static function _on_signup( $subscriber, $context = array() ) {
		if ( ! is_object( $subscriber ) ) {
			return;
		}
		$context = (array) $context;
		$source  = (string) ( $context['source'] ?? '' );

		if ( in_array( $source, array( 'admin', 'import', 'rest' ), true ) && empty( $context['consent_text'] ) ) {
			return; // Added by staff or a system: no statement from the person to record.
		}

		$text = ! empty( $context['consent_text'] )
			? (string) $context['consent_text']
			: __( 'Signed up to receive emails through the newsletter form.', 'fw' );

		self::record( $subscriber->id, 'newsletter', $text, array(
			'source'     => $source,
			'source_url' => (string) ( $context['source_url'] ?? '' ),
			'ip'         => (string) ( $context['ip'] ?? '' ),
		) );
	}

	/** @internal */
	public static function _on_confirmed( $subscriber ) {
		if ( is_object( $subscriber ) ) {
			self::record( $subscriber->id, 'newsletter', __( 'Confirmed the subscription from the confirmation email.', 'fw' ), array(
				'source' => 'double_opt_in',
				'ip'     => class_exists( 'FW_CRM_Service' ) ? FW_CRM_Service::client_ip() : '',
			) );
		}
	}

	/** @internal */
	public static function _on_unsubscribed( $subscriber ) {
		if ( is_object( $subscriber ) ) {
			self::record( $subscriber->id, 'newsletter', __( 'Unsubscribed.', 'fw' ), array( 'granted' => false, 'source' => 'unsubscribe' ) );
		}
	}
}
