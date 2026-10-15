<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Labelled, two-way links between any two CRM records.
 *
 * A link is stored once (from -> to) and read from both ends, so "the companies
 * of this contact" and "the contacts of this company" are the same rows. New
 * record types (deals, cases, a Membership extension's memberships) attach here
 * without a schema change. Types are short slugs: contact, company, deal, case…
 */
class FW_CRM_Relations {

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'relations' );
	}

	/**
	 * @param string $type
	 * @return string
	 */
	private static function type( $type ) {
		return substr( sanitize_key( (string) $type ), 0, 30 );
	}

	/**
	 * Link two records. Linking twice is a no-op.
	 *
	 * @param string $from_type
	 * @param int    $from_id
	 * @param string $to_type
	 * @param int    $to_id
	 * @param string $label e.g. works_at, contact, owner (optional)
	 *
	 * @return bool True when a new link was created.
	 */
	public static function link( $from_type, $from_id, $to_type, $to_id, $label = '' ) {
		global $wpdb;

		if ( ! $from_id || ! $to_id ) {
			return false;
		}

		$table = self::table();

		$created = 0 < (int) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (from_type, from_id, to_type, to_id, label, created_at) VALUES (%s, %d, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL
			self::type( $from_type ),
			(int) $from_id,
			self::type( $to_type ),
			(int) $to_id,
			substr( sanitize_key( (string) $label ), 0, 50 ),
			current_time( 'mysql' )
		) );

		if ( $created ) {
			/** Fires after two CRM records are linked. */
			do_action( 'unysonplus_crm_linked', self::type( $from_type ), (int) $from_id, self::type( $to_type ), (int) $to_id, (string) $label );
		}

		return $created;
	}

	/**
	 * Remove a link, whichever way round it was stored.
	 *
	 * @param string      $a_type
	 * @param int         $a_id
	 * @param string      $b_type
	 * @param int         $b_id
	 * @param string|null $label Null removes every label.
	 *
	 * @return int Links removed.
	 */
	public static function unlink( $a_type, $a_id, $b_type, $b_id, $label = null ) {
		global $wpdb;

		$table = self::table();
		$a     = self::type( $a_type );
		$b     = self::type( $b_type );
		$extra = null === $label ? '' : $wpdb->prepare( ' AND label = %s', sanitize_key( (string) $label ) );

		$removed = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE ( (from_type = %s AND from_id = %d AND to_type = %s AND to_id = %d) OR (from_type = %s AND from_id = %d AND to_type = %s AND to_id = %d) ){$extra}", // phpcs:ignore WordPress.DB.PreparedSQL
			$a, (int) $a_id, $b, (int) $b_id,
			$b, (int) $b_id, $a, (int) $a_id
		) );

		if ( $removed ) {
			/** Fires after two CRM records are unlinked. */
			do_action( 'unysonplus_crm_unlinked', $a, (int) $a_id, $b, (int) $b_id, $label );
		}

		return $removed;
	}

	/**
	 * Records linked to one record, read from both ends.
	 *
	 * @param string      $type
	 * @param int         $id
	 * @param string|null $other_type Only links to this type.
	 * @param string|null $label      Only links with this label.
	 *
	 * @return array [ { type, id, label, created_at } ]
	 */
	public static function related( $type, $id, $other_type = null, $label = null ) {
		global $wpdb;

		$table = self::table();
		$t     = self::type( $type );
		$where = '';
		$args  = array( $t, (int) $id, $t, (int) $id );

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT from_type, from_id, to_type, to_id, label, created_at FROM {$table} WHERE (from_type = %s AND from_id = %d) OR (to_type = %s AND to_id = %d) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			$args
		) );

		$out = array();
		foreach ( $rows as $r ) {
			$is_from = $r->from_type === $t && (int) $r->from_id === (int) $id;
			$o_type  = $is_from ? $r->to_type : $r->from_type;
			$o_id    = (int) ( $is_from ? $r->to_id : $r->from_id );

			if ( null !== $other_type && self::type( $other_type ) !== $o_type ) {
				continue;
			}
			if ( null !== $label && sanitize_key( (string) $label ) !== $r->label ) {
				continue;
			}
			$out[] = array( 'type' => $o_type, 'id' => $o_id, 'label' => $r->label, 'created_at' => $r->created_at );
		}

		return $out;
	}

	/**
	 * Ids of the records of one type linked to a record.
	 *
	 * @param string      $type
	 * @param int         $id
	 * @param string      $other_type
	 * @param string|null $label
	 *
	 * @return int[]
	 */
	public static function ids( $type, $id, $other_type, $label = null ) {
		return array_values( array_unique( array_map( function ( $r ) {
			return (int) $r['id'];
		}, self::related( $type, $id, $other_type, $label ) ) ) );
	}

	/**
	 * Drop every link of a record (when it is deleted for good).
	 *
	 * @param string $type
	 * @param int    $id
	 *
	 * @return int
	 */
	public static function delete_for( $type, $id ) {
		global $wpdb;

		$table = self::table();
		$t     = self::type( $type );

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE (from_type = %s AND from_id = %d) OR (to_type = %s AND to_id = %d)", // phpcs:ignore WordPress.DB.PreparedSQL
			$t, (int) $id, $t, (int) $id
		) );
	}
}
