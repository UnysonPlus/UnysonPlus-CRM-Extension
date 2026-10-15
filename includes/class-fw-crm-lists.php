<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Repository for lists, tags and segments.
 *
 * Lists and tags are ONE table discriminated by `type` (the FluentCRM shape) —
 * they are structurally identical, so two tables would be two copies of the same
 * code. Membership for both lives in the single polymorphic pivot.
 *
 * Like the subscribers repository, this class holds NO business rules and fires
 * NO hooks. It is the only place (with FW_CRM_Subscribers) that talks
 * to $wpdb about these tables.
 */
class FW_CRM_Lists {

	/** @return string */
	private static function table() {
		return FW_CRM_Installer::table( 'lists' );
	}

	/** @return string */
	private static function pivot() {
		return FW_CRM_Installer::table( 'subscriber_pivot' );
	}

	/** @return string */
	private static function segments_table() {
		return FW_CRM_Installer::table( 'segments' );
	}

	/* ---------------------------------------------------------------------- *
	 * Lists / tags
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param string $slug
	 * @param string $type list|tag
	 *
	 * @return object|null
	 */
	public static function find_by_slug( $slug, $type = 'list' ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug = %s AND type = %s", // phpcs:ignore WordPress.DB.PreparedSQL
			(string) $slug,
			(string) $type
		) );
	}

	/**
	 * @param string $type list|tag (null for both)
	 *
	 * @return array Objects, alphabetical by title.
	 */
	public static function all( $type = 'list' ) {
		global $wpdb;

		$table = self::table();

		if ( null === $type ) {
			return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY type ASC, title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE type = %s ORDER BY title ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			(string) $type
		) );
	}

	/**
	 * Find by slug, creating the row if it is missing. Used by the capture hook
	 * so a free-text `list_id` on the [newsletter] element resolves to a real
	 * list (auto-creation is settings-controlled by the caller, not here).
	 *
	 * @param string $slug
	 * @param string $type
	 * @param string $title Optional human title; defaults to a prettified slug.
	 *
	 * @return object|null
	 */
	public static function get_or_create( $slug, $type = 'list', $title = '', array $args = array() ) {
		// Also accepts ( $slug, $type, $args ): array( 'title' => …, 'managed_by' => 'membership' ).
		if ( is_array( $title ) ) {
			$args  = $title;
			$title = isset( $args['title'] ) ? (string) $args['title'] : '';
		}
		$managed = isset( $args['managed_by'] ) ? sanitize_key( (string) $args['managed_by'] ) : '';
		$slug    = sanitize_key( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$existing = self::find_by_slug( $slug, $type );

		if ( $existing ) {
			// An extension claiming a tag that already exists takes it over.
			if ( '' !== $managed && $managed !== (string) $existing->managed_by ) {
				self::update( (int) $existing->id, array( 'managed_by' => $managed ) );
				$existing = self::find( (int) $existing->id );
			}
			return $existing;
		}

		$id = self::insert( array(
			'slug'       => $slug,
			'type'       => $type,
			'title'      => '' !== $title ? $title : ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
			'managed_by' => $managed,
		) );

		return $id ? self::find( $id ) : null;
	}

	/**
	 * @param array $data slug, type, title, description
	 *
	 * @return int Inserted ID, or 0.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array(
			'slug'        => sanitize_key( isset( $data['slug'] ) ? $data['slug'] : '' ),
			'type'        => isset( $data['type'] ) && 'tag' === $data['type'] ? 'tag' : 'list',
			'title'       => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'managed_by'  => isset( $data['managed_by'] ) ? sanitize_key( (string) $data['managed_by'] ) : '',
			'created_at'  => $now,
		);

		if ( '' === $row['slug'] ) {
			return 0;
		}

		$ok = $wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );

		if ( $ok && class_exists( 'FW_CRM_Segments' ) ) {
			FW_CRM_Segments::flush_fields(); // A new tag or list is a new rule choice.
		}

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$row = array();

		if ( isset( $data['managed_by'] ) ) {
			$row['managed_by'] = sanitize_key( (string) $data['managed_by'] );
		}

		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['description'] ) ) {
			$row['description'] = sanitize_textarea_field( $data['description'] );
		}
		if ( isset( $data['slug'] ) ) {
			$row['slug'] = sanitize_key( $data['slug'] );
		}

		if ( ! $row ) {
			return false;
		}

		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a list/tag and every membership row pointing at it.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$list = self::find( $id );

		if ( ! $list ) {
			return false;
		}

		$wpdb->delete( self::pivot(), array(
			'object_id'   => (int) $id,
			'object_type' => $list->type,
		), array( '%d', '%s' ) );

		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Subscriber counts keyed by list/tag id.
	 *
	 * @param string $type
	 * @param string $subscriber_status Only count subscribers in this status ('' = all).
	 *
	 * @return array [ object_id => count ]
	 */
	public static function counts( $type = 'list', $subscriber_status = 'subscribed' ) {
		global $wpdb;

		$pivot       = self::pivot();
		$subscribers = FW_CRM_Installer::table( 'subscribers' );

		if ( '' !== $subscriber_status ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.object_id, COUNT(*) AS total
				 FROM {$pivot} p
				 INNER JOIN {$subscribers} s ON s.id = p.subscriber_id
				 WHERE p.object_type = %s AND s.status = %s AND s.deleted_at IS NULL AND ( p.object_type = 'tag' OR p.status = 'subscribed' )
				 GROUP BY p.object_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$type,
				$subscriber_status
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.object_id, COUNT(*) AS total
				 FROM {$pivot} p
				 INNER JOIN {$subscribers} s ON s.id = p.subscriber_id AND s.deleted_at IS NULL
				 WHERE p.object_type = %s AND ( p.object_type = 'tag' OR p.status = 'subscribed' )
				 GROUP BY p.object_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$type
			) );
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->object_id ] = (int) $row->total;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Segments (a saved query — never denormalised membership)
	 * ---------------------------------------------------------------------- */

	/**
	 * @return array
	 */
	public static function segments() {
		global $wpdb;

		$table = self::segments_table();

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find_segment( $id ) {
		global $wpdb;

		$table = self::segments_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $data slug, title, filters (array — stored as JSON)
	 *
	 * @return int
	 */
	public static function insert_segment( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array(
			'slug'       => sanitize_key( isset( $data['slug'] ) ? $data['slug'] : '' ),
			'title'      => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'filters'    => wp_json_encode( isset( $data['filters'] ) ? (array) $data['filters'] : array() ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		if ( '' === $row['slug'] ) {
			return 0;
		}

		$ok = $wpdb->insert( self::segments_table(), $row, array( '%s', '%s', '%s', '%s', '%s' ) );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param string $slug
	 *
	 * @return object|null
	 */
	public static function find_segment_by_slug( $slug ) {
		global $wpdb;

		$table = self::segments_table();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL
			sanitize_key( $slug )
		) );
	}

	/**
	 * @param int   $id
	 * @param array $data title, filters
	 *
	 * @return bool
	 */
	public static function update_segment( $id, array $data ) {
		global $wpdb;

		$row = array( 'updated_at' => current_time( 'mysql' ) );

		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['filters'] ) ) {
			$row['filters'] = wp_json_encode( (array) $data['filters'] );
		}

		return false !== $wpdb->update( self::segments_table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete_segment( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( self::segments_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Decode a segment's stored filters into a query args array the subscribers
	 * repository understands. This is deliberately the SAME arg shape the admin
	 * list table uses, so segments and ad-hoc filtering are one code path.
	 *
	 * @param object|int $segment
	 *
	 * @return array
	 */
	public static function segment_query_args( $segment ) {
		if ( is_numeric( $segment ) ) {
			$segment = self::find_segment( $segment );
		}

		if ( ! $segment || empty( $segment->filters ) ) {
			return array();
		}

		$filters = json_decode( $segment->filters, true );

		return is_array( $filters ) ? $filters : array();
	}
}
