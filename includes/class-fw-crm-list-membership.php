<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Subscription status per contact and list.
 *
 * Two levels decide whether someone may be emailed about a list:
 *  - the contact's own `status` is the ADDRESS-level permission (pending, subscribed,
 *    unsubscribed, bounced, complained): may this address receive marketing at all;
 *  - this class keeps the LIST-level choice: subscribed to this list, or left it, with
 *    when they joined, when they left and where from.
 *
 * Someone receives a list's emails only while both say subscribed. Leaving a list
 * keeps the membership row (status `unsubscribed`) so the CRM remembers the choice:
 * a re-import does not quietly add them back, and their history stays visible.
 * Tags have no status: they are labels, added and removed outright.
 */
class FW_CRM_List_Membership {

	const SUBSCRIBED   = 'subscribed';
	const UNSUBSCRIBED = 'unsubscribed';

	/** @return string */
	private static function pivot() {
		return FW_CRM_Installer::table( 'subscriber_pivot' );
	}

	/**
	 * A contact's status on one list.
	 *
	 * @param int $contact_id
	 * @param int $list_id
	 *
	 * @return string|null subscribed | unsubscribed, or null when never on it.
	 */
	public static function status( $contact_id, $list_id ) {
		global $wpdb;

		$pivot = self::pivot();
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$pivot} WHERE subscriber_id = %d AND object_id = %d AND object_type = 'list'", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $contact_id,
			(int) $list_id
		) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Every list a contact is or was on, with its status and dates.
	 *
	 * @param int $contact_id
	 *
	 * @return array [ { id, slug, title, status, subscribed_at, unsubscribed_at, source } ]
	 */
	public static function for_contact( $contact_id ) {
		global $wpdb;

		$pivot = self::pivot();
		$lists = FW_CRM_Installer::table( 'lists' );

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT l.id, l.slug, l.title, p.status, p.subscribed_at, p.unsubscribed_at, p.source
			 FROM {$pivot} p INNER JOIN {$lists} l ON l.id = p.object_id AND l.type = 'list'
			 WHERE p.subscriber_id = %d AND p.object_type = 'list'
			 ORDER BY l.title ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $contact_id
		) );
	}

	/**
	 * Put a contact on a list (or back on it).
	 *
	 * @param int    $contact_id
	 * @param int    $list_id
	 * @param string $source A short slug: form, preferences, admin, import, rest, …
	 *
	 * @return bool True when something changed.
	 */
	public static function subscribe( $contact_id, $list_id, $source = '' ) {
		global $wpdb;

		if ( ! $contact_id || ! $list_id ) {
			return false;
		}
		$before = self::status( $contact_id, $list_id );
		if ( self::SUBSCRIBED === $before ) {
			return false;
		}
		$now   = current_time( 'mysql' );
		$pivot = self::pivot();

		if ( null === $before ) {
			$wpdb->insert( $pivot, array(
				'subscriber_id' => (int) $contact_id,
				'object_id'     => (int) $list_id,
				'object_type'   => 'list',
				'status'        => self::SUBSCRIBED,
				'subscribed_at' => $now,
				'source'        => substr( sanitize_key( (string) $source ), 0, 50 ),
				'created_at'    => $now,
			) );
		} else {
			$wpdb->update( $pivot, array(
				'status'          => self::SUBSCRIBED,
				'subscribed_at'   => $now,
				'unsubscribed_at' => null,
				'source'          => substr( sanitize_key( (string) $source ), 0, 50 ),
			), array( 'subscriber_id' => (int) $contact_id, 'object_id' => (int) $list_id, 'object_type' => 'list' ) );
		}

		self::changed( $contact_id, $list_id, $before, self::SUBSCRIBED, $source );

		return true;
	}

	/**
	 * Take a contact off a list, remembering that they left.
	 *
	 * @param int    $contact_id
	 * @param int    $list_id
	 * @param string $source
	 *
	 * @return bool True when something changed.
	 */
	public static function unsubscribe( $contact_id, $list_id, $source = '' ) {
		global $wpdb;

		$before = self::status( $contact_id, $list_id );
		if ( self::SUBSCRIBED !== $before ) {
			return false;
		}
		$wpdb->update( self::pivot(), array(
			'status'          => self::UNSUBSCRIBED,
			'unsubscribed_at' => current_time( 'mysql' ),
			'source'          => substr( sanitize_key( (string) $source ), 0, 50 ),
		), array( 'subscriber_id' => (int) $contact_id, 'object_id' => (int) $list_id, 'object_type' => 'list' ) );

		self::changed( $contact_id, $list_id, $before, self::UNSUBSCRIBED, $source );

		return true;
	}

	/**
	 * Did this contact leave this list (so an import should not add them back)?
	 *
	 * @param int $contact_id
	 * @param int $list_id
	 *
	 * @return bool
	 */
	public static function left( $contact_id, $list_id ) {
		return self::UNSUBSCRIBED === self::status( $contact_id, $list_id );
	}

	/**
	 * @param int         $contact_id
	 * @param int         $list_id
	 * @param string|null $from
	 * @param string      $to
	 * @param string      $source
	 */
	private static function changed( $contact_id, $list_id, $from, $to, $source ) {
		FW_CRM_Subscribers::flush_has_cache();
		if ( class_exists( 'FW_CRM_Segments' ) ) {
			FW_CRM_Segments::flush_cache();
		}
		$list = FW_CRM_Lists::find( (int) $list_id );
		FW_CRM_Activity::log( 'contact', (int) $contact_id, 'list', sprintf(
			self::SUBSCRIBED === $to ? __( 'Joined the list %s', 'fw' ) : __( 'Left the list %s', 'fw' ),
			$list ? $list->title : '#' . (int) $list_id
		), array( 'meta' => array( 'list_id' => (int) $list_id, 'from' => $from, 'to' => $to, 'source' => (string) $source ) ) );

		/**
		 * Fires when a contact's status on a list changes: contact id, list id, the old
		 * status (null = never on it) and the new one.
		 */
		do_action( 'unysonplus_crm_list_status_changed', (int) $contact_id, (int) $list_id, $from, $to, (string) $source );
	}
}
