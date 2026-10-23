<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Duplicate contacts: finding them, and merging two into one.
 *
 * Email is unique, so duplicates are people who signed up twice with different
 * addresses, or were added by hand without one: the same phone number, or the
 * same full name. merge() keeps one record and moves everything of the other onto
 * it (lists and tags, company links, timeline, consent log, custom field values,
 * meta, campaign history, the WordPress user link), then deletes the other.
 *
 * Email consent errs on the safe side: if either record had opted out, bounced or
 * complained, the merged contact keeps that status, so a merge can never re-subscribe
 * someone.
 */
class FW_CRM_Merge {

	/** Email statuses, safest (most restrictive) first. */
	const STATUS_ORDER = array( 'complained', 'bounced', 'unsubscribed', 'subscribed', 'pending', 'none' );

	/** Core fields offered as a choice on the merge screen. */
	const CHOICE_FIELDS = array( 'email', 'first_name', 'last_name', 'phone', 'job_title', 'owner_id', 'company_id', 'timezone', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country', 'source' );

	/**
	 * Likely duplicates of one contact: other live contacts with the same phone
	 * digits or the same full name.
	 *
	 * @param int $contact_id
	 * @param int $limit
	 *
	 * @return array Contact rows, each with a `why` property (phone|name).
	 */
	public static function duplicates_of( $contact_id, $limit = 10 ) {
		global $wpdb;

		$c = FW_CRM_Subscribers::find( $contact_id );
		if ( ! $c ) {
			return array();
		}
		$table = FW_CRM_Installer::table( 'subscribers' );
		$found = array();

		$digits = preg_replace( '/\D/', '', (string) $c->phone );
		if ( strlen( $digits ) >= 6 ) {
			// Compare the last nine digits, so "+44 7700 900001" matches "07700900001".
			$tail = substr( $digits, -9 );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE id <> %d AND deleted_at IS NULL AND phone <> '' AND phone LIKE %s LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $contact_id,
				'%' . $wpdb->esc_like( substr( $tail, -4 ) ) . '%'
			) );
			foreach ( (array) $rows as $r ) {
				if ( substr( preg_replace( '/\D/', '', (string) $r->phone ), -9 ) === $tail ) {
					$r->why               = 'phone';
					$found[ (int) $r->id ] = $r;
				}
			}
		}

		if ( '' !== trim( $c->first_name ) && '' !== trim( $c->last_name ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE id <> %d AND deleted_at IS NULL AND first_name = %s AND last_name = %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $contact_id,
				$c->first_name,
				$c->last_name,
				(int) $limit
			) );
			foreach ( (array) $rows as $r ) {
				if ( ! isset( $found[ (int) $r->id ] ) ) {
					$r->why               = 'name';
					$found[ (int) $r->id ] = $r;
				}
			}
		}

		return array_slice( array_values( $found ), 0, (int) $limit );
	}

	/**
	 * Every group of likely duplicates across the CRM (same phone, or same full name).
	 *
	 * @param int $limit Groups.
	 *
	 * @return array [ { why, value, ids[] } ]
	 */
	public static function find_groups( $limit = 50 ) {
		global $wpdb;

		$table  = FW_CRM_Installer::table( 'subscribers' );
		$groups = array();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT first_name, last_name, GROUP_CONCAT(id ORDER BY id) AS ids FROM {$table}
			 WHERE deleted_at IS NULL AND first_name <> '' AND last_name <> ''
			 GROUP BY first_name, last_name HAVING COUNT(*) > 1 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $limit
		) );
		foreach ( (array) $rows as $r ) {
			$groups[] = array( 'why' => 'name', 'value' => trim( $r->first_name . ' ' . $r->last_name ), 'ids' => array_map( 'intval', explode( ',', $r->ids ) ) );
		}

		// Phones are compared on their digits, which SQL cannot normalise portably,
		// so bucket them here.
		$phones  = $wpdb->get_results( "SELECT id, phone FROM {$table} WHERE deleted_at IS NULL AND phone <> '' ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$buckets = array();
		foreach ( (array) $phones as $p ) {
			$digits = preg_replace( '/\D/', '', (string) $p->phone );
			if ( strlen( $digits ) >= 6 ) {
				$buckets[ substr( $digits, -9 ) ][] = (int) $p->id;
			}
		}
		foreach ( $buckets as $key => $ids ) {
			if ( count( $ids ) > 1 ) {
				$groups[] = array( 'why' => 'phone', 'value' => $key, 'ids' => $ids );
			}
		}

		// The same people matched twice (by name and by phone) are one group.
		$seen = array();
		$out  = array();
		foreach ( $groups as $g ) {
			sort( $g['ids'] );
			$key = implode( ',', $g['ids'] );
			if ( isset( $seen[ $key ] ) ) {
				$out[ $seen[ $key ] ]['why'] = 'name_phone';
				continue;
			}
			$seen[ $key ] = count( $out );
			$out[]        = $g;
		}

		return array_slice( $out, 0, (int) $limit );
	}

	/**
	 * Merge $other_id into $keep_id.
	 *
	 * @param int   $keep_id
	 * @param int   $other_id
	 * @param array $prefer  field => 'keep' | 'other' for CHOICE_FIELDS (default: keep's
	 *                       value, or the other's when keep's is empty).
	 *
	 * @return true|WP_Error
	 */
	public static function merge( $keep_id, $other_id, array $prefer = array() ) {
		global $wpdb;

		$keep  = FW_CRM_Subscribers::find( $keep_id );
		$other = FW_CRM_Subscribers::find( $other_id );
		if ( ! $keep || ! $other ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		if ( (int) $keep->id === (int) $other->id ) {
			return new WP_Error( 'fw_crm_same', __( 'A contact cannot be merged with itself.', 'fw' ) );
		}
		if ( (int) $keep->user_id && (int) $other->user_id && (int) $keep->user_id !== (int) $other->user_id ) {
			return new WP_Error( 'fw_crm_two_users', __( 'Both contacts are linked to different site accounts. Unlink one of them first.', 'fw' ) );
		}

		$t     = array(
			'subscribers' => FW_CRM_Installer::table( 'subscribers' ),
			'pivot'       => FW_CRM_Installer::table( 'subscriber_pivot' ),
			'meta'        => FW_CRM_Installer::table( 'subscriber_meta' ),
			'queue'       => FW_CRM_Installer::table( 'campaign_queue' ),
			'relations'   => FW_CRM_Installer::table( 'relations' ),
			'activity'    => FW_CRM_Installer::table( 'activity' ),
			'consent'     => FW_CRM_Installer::table( 'consent' ),
			'values'      => FW_CRM_Installer::table( 'field_values' ),
		);
		$k     = (int) $keep->id;
		$o     = (int) $other->id;
		$other_name = trim( $other->first_name . ' ' . $other->last_name );
		$other_name = '' !== $other_name ? $other_name : (string) ( $other->email ?: $other->phone );

		/** Fires before two contacts are merged: the one kept, the one that will be deleted. */
		do_action( 'unysonplus_crm_contacts_merging', $keep, $other );

		// 1. Field values for the kept row.
		$row = array();
		foreach ( self::CHOICE_FIELDS as $f ) {
			$mine   = (string) $keep->$f;
			$theirs = (string) $other->$f;
			$empty  = '' === $mine || ( in_array( $f, array( 'owner_id', 'company_id' ), true ) && ! (int) $mine );
			$use    = $prefer[ $f ] ?? ( $empty ? 'other' : 'keep' );
			if ( 'other' === $use && $theirs !== $mine && ( '' !== $theirs || 'email' !== $f ) ) {
				$row[ $f ] = $other->$f;
			}
		}

		$stages     = array_keys( FW_CRM_Contacts::stages() );
		$rank       = function ( $s ) use ( $stages ) {
			$i = array_search( $s, $stages, true );
			return 'inactive' === $s || false === $i ? -1 : $i;
		};
		if ( $rank( $other->lifecycle_stage ) > $rank( $keep->lifecycle_stage ) ) {
			$row['lifecycle_stage'] = $other->lifecycle_stage;
		}

		$order = array_flip( self::STATUS_ORDER );
		if ( ( $order[ $other->status ] ?? 99 ) < ( $order[ $keep->status ] ?? 99 ) ) {
			$row['status'] = $other->status;
			foreach ( array( 'confirmed_at', 'unsubscribed_at', 'consent_at', 'unsubscribe_token' ) as $col ) {
				if ( ! empty( $other->$col ) && empty( $keep->$col ) ) {
					$row[ $col ] = $other->$col;
				}
			}
		}

		$row['score']          = max( (int) $keep->score, (int) $other->score );
		$row['lifetime_value'] = round( (float) $keep->lifetime_value + (float) $other->lifetime_value, 2 );
		if ( $other->created_at && ( ! $keep->created_at || $other->created_at < $keep->created_at ) ) {
			$row['created_at'] = $other->created_at;
		}
		if ( $other->last_activity_at > $keep->last_activity_at ) {
			$row['last_activity_at'] = $other->last_activity_at;
		}
		if ( ! (int) $keep->user_id && (int) $other->user_id ) {
			$row['user_id'] = (int) $other->user_id;
		}

		// The email is unique: free it on the other row before the kept row takes it.
		if ( isset( $row['email'] ) || isset( $row['user_id'] ) ) {
			$wpdb->update( $t['subscribers'], array( 'email' => null, 'user_id' => 0 ), array( 'id' => $o ) );
		}

		// 2. Everything that points at the other contact moves to the kept one.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['pivot']} (subscriber_id, object_id, object_type, status, created_at) SELECT %d, object_id, object_type, status, created_at FROM {$t['pivot']} WHERE subscriber_id = %d", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->delete( $t['pivot'], array( 'subscriber_id' => $o ) );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['meta']} (subscriber_id, meta_key, meta_value) SELECT %d, meta_key, meta_value FROM {$t['meta']} WHERE subscriber_id = %d", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->delete( $t['meta'], array( 'subscriber_id' => $o ) );
		$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$t['queue']} SET subscriber_id = %d WHERE subscriber_id = %d", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->delete( $t['queue'], array( 'subscriber_id' => $o ) ); // Left over only where both were queued for the same campaign.
		$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$t['relations']} SET from_id = %d WHERE from_type = 'contact' AND from_id = %d", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$t['relations']} SET to_id = %d WHERE to_type = 'contact' AND to_id = %d", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['relations']} WHERE (from_type = 'contact' AND from_id = %d) OR (to_type = 'contact' AND to_id = %d)", $o, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->update( $t['activity'], array( 'object_id' => $k ), array( 'object_type' => 'contact', 'object_id' => $o ) );
		$wpdb->update( $t['consent'], array( 'contact_id' => $k ), array( 'contact_id' => $o ) );
		$wpdb->update( FW_CRM_Installer::table( 'deals' ), array( 'contact_id' => $k ), array( 'contact_id' => $o ) );
		$wpdb->update( FW_CRM_Installer::table( 'cases' ), array( 'contact_id' => $k ), array( 'contact_id' => $o ) );
		$wpdb->update( FW_CRM_Installer::table( 'contact_events' ), array( 'contact_id' => $k ), array( 'contact_id' => $o ) );
		$wpdb->update( FW_CRM_Installer::table( 'score_log' ), array( 'contact_id' => $k ), array( 'contact_id' => $o ) );
		// Scores add up: the kept contact gets the other's points for every named score.
		$scores = FW_CRM_Installer::table( 'contact_scores' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$scores} (contact_id, score_key, points, updated_at) SELECT %d, score_key, points, updated_at FROM {$scores} WHERE contact_id = %d ON DUPLICATE KEY UPDATE points = {$scores}.points + VALUES(points)", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->delete( $scores, array( 'contact_id' => $o ) );
		$wpdb->update( FW_CRM_Installer::table( 'tasks' ), array( 'object_id' => $k ), array( 'object_type' => 'contact', 'object_id' => $o ) );
		// Custom fields: the kept contact's values win; the other fills the gaps.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['values']} (object_type, object_id, field_key, value, value_num, value_date) SELECT 'contact', %d, field_key, value, value_num, value_date FROM {$t['values']} WHERE object_type = 'contact' AND object_id = %d", $k, $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->delete( $t['values'], array( 'object_type' => 'contact', 'object_id' => $o ) );

		// 3. The kept row, then the other row goes.
		if ( $row ) {
			$row['updated_at'] = current_time( 'mysql' );
			$wpdb->update( $t['subscribers'], $row, array( 'id' => $k ) );
		}
		$wpdb->delete( $t['subscribers'], array( 'id' => $o ) );
		FW_CRM_Subscribers::flush_has_cache();
		FW_CRM_Contacts::flush_user_cache();
		if ( class_exists( 'FW_CRM_Segments' ) ) {
			FW_CRM_Segments::flush_cache();
		}

		FW_CRM_Activity::log( 'contact', $k, 'merged', sprintf( __( 'Merged with %s', 'fw' ), $other_name ), array(
			'meta' => array( 'merged_id' => $o, 'email' => (string) $other->email, 'phone' => (string) $other->phone ),
		) );

		/**
		 * Fires after a merge: the kept contact's id, and the deleted contact's row as it
		 * was. Extensions holding their own references to $removed->id move them here.
		 */
		do_action( 'unysonplus_crm_contacts_merged', $k, $other );

		return true;
	}
}
