<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Custom objects: new record types registered by other extensions (a Property for an estate
 * agent, a Vehicle for a garage, a Policy for an insurer), with fields, relations to contacts,
 * companies and deals, a timeline and permissions, using the CRM's shared tables:
 *
 * - the record itself in `objects` (type, title, status, owner, data);
 * - its custom fields through FW_CRM_Fields with `object` = the type (define them with
 *   fw_crm()->add_field() or FW_CRM_Fields::save_definition());
 * - its links through FW_CRM_Relations (from_type = the type);
 * - its history through FW_CRM_Activity (object_type = the type).
 *
 *     fw_crm()->register_object( 'property', array(
 *         'label'    => 'Property',
 *         'plural'   => 'Properties',
 *         'icon'     => 'dashicons-admin-home',
 *         'statuses' => array( 'available' => 'Available', 'sold' => 'Sold' ),
 *         'relates'  => array( 'contact', 'deal' ),
 *         'capability' => 'edit_posts', // who may change records (reading follows the CRM's own access)
 *     ) );
 *
 * Every contact (and company, deal) page shows a panel listing the linked records of each type
 * that relates to it. A full admin screen per type comes later; the API is stable now.
 */
class FW_CRM_Objects {

	/** @var array type => definition */
	private static $types = array();

	public static function init() {
		add_action( 'init', function () {
			/** Register custom objects here: do_action( … ) runs once, after every extension has loaded. */
			do_action( 'unysonplus_crm_register_objects' );
			self::panels();
		}, 20 );
	}

	/**
	 * @param string $type A slug, 2-30 characters, not a built-in record type.
	 * @param array  $args label, plural, icon, statuses, relates (contact|company|deal), capability
	 *
	 * @return true|WP_Error
	 */
	public static function register( $type, array $args ) {
		$type = substr( sanitize_key( (string) $type ), 0, 30 ); // The timeline and relations keep 30 characters.
		if ( strlen( $type ) < 2 || in_array( $type, array( 'contact', 'company', 'deal', 'task', 'case', 'campaign', 'automation', 'user', 'post' ), true ) ) {
			return new WP_Error( 'fw_crm_object_type', __( 'That object type name is reserved or too short.', 'fw' ) );
		}
		self::$types[ $type ] = array(
			'type'       => $type,
			'label'      => (string) ( $args['label'] ?? $type ),
			'plural'     => (string) ( $args['plural'] ?? ( $args['label'] ?? $type ) ),
			'icon'       => (string) ( $args['icon'] ?? 'dashicons-database' ),
			'statuses'   => (array) ( $args['statuses'] ?? array() ),
			'relates'    => array_values( array_intersect( (array) ( $args['relates'] ?? array( 'contact' ) ), array( 'contact', 'company', 'deal' ) ) ),
			'capability' => (string) ( $args['capability'] ?? FW_CRM_Service::capability() ),
		);
		return true;
	}

	/** @return array type => definition */
	public static function types() {
		return self::$types;
	}

	/**
	 * @param string $type
	 *
	 * @return array|null
	 */
	public static function type( $type ) {
		return self::$types[ (string) $type ] ?? null;
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'objects' );
	}

	/**
	 * Create a record.
	 *
	 * @param string $type
	 * @param array  $data title (required), status, owner_id, data (plain values), fields (custom field values), links ( [ type, id, label? ] )
	 *
	 * @return int|WP_Error
	 */
	public static function create( $type, array $data ) {
		global $wpdb;

		$def = self::type( $type );
		if ( ! $def ) {
			return new WP_Error( 'fw_crm_object_type', __( 'Unknown object type.', 'fw' ) );
		}
		$title = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return new WP_Error( 'fw_crm_no_title', __( 'A record needs a title.', 'fw' ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array(
			'type'       => $def['type'],
			'title'      => substr( $title, 0, 190 ),
			'status'     => self::status( $def, (string) ( $data['status'] ?? '' ) ),
			'owner_id'   => (int) ( $data['owner_id'] ?? get_current_user_id() ),
			'data'       => wp_json_encode( FW_CRM_Automations_Plain::plain( (array) ( $data['data'] ?? array() ) ) ),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the record.', 'fw' ) );
		}
		if ( ! empty( $data['fields'] ) ) {
			FW_CRM_Fields::set_values( $def['type'], $id, (array) $data['fields'] );
		}
		foreach ( (array) ( $data['links'] ?? array() ) as $l ) {
			self::link( $def['type'], $id, (string) ( $l[0] ?? '' ), (int) ( $l[1] ?? 0 ), (string) ( $l[2] ?? '' ) );
		}
		FW_CRM_Activity::log( $def['type'], $id, 'created', sprintf( __( '%s created', 'fw' ), $def['label'] ) );
		FW_CRM_Events::emit( 'object.created', 0, $def['type'], $id, array( 'type' => $def['type'], 'title' => $title ) );
		return $id;
	}

	/**
	 * @param int   $id
	 * @param array $data title, status, owner_id, data (merged), fields
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$r = self::get( $id );
		if ( ! $r ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Record not found.', 'fw' ) );
		}
		$def = self::type( $r['type'] );
		$row = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['title'] ) && '' !== trim( (string) $data['title'] ) ) {
			$row['title'] = substr( sanitize_text_field( (string) $data['title'] ), 0, 190 );
		}
		if ( isset( $data['status'] ) && $def ) {
			$row['status'] = self::status( $def, (string) $data['status'] );
			if ( $row['status'] !== $r['status'] ) {
				FW_CRM_Activity::log( $r['type'], (int) $id, 'stage', sprintf( __( 'Status: %s', 'fw' ), $def['statuses'][ $row['status'] ] ?? $row['status'] ) );
			}
		}
		if ( isset( $data['owner_id'] ) ) {
			$row['owner_id'] = (int) $data['owner_id'];
		}
		if ( isset( $data['data'] ) ) {
			$row['data'] = wp_json_encode( FW_CRM_Automations_Plain::plain( array_merge( $r['data'], (array) $data['data'] ) ) );
		}
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		if ( ! empty( $data['fields'] ) ) {
			FW_CRM_Fields::set_values( $r['type'], (int) $id, (array) $data['fields'] );
		}
		FW_CRM_Events::emit( 'object.updated', 0, $r['type'], (int) $id, array( 'type' => $r['type'] ) );
		return true;
	}

	/**
	 * @param int $id
	 *
	 * @return array|null id, type, title, status, owner_id, data, fields, created_at, updated_at
	 */
	public static function get( $id ) {
		global $wpdb;

		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND deleted_at IS NULL", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $r ) {
			return null;
		}
		return array(
			'id'         => (int) $r->id,
			'type'       => (string) $r->type,
			'title'      => (string) $r->title,
			'status'     => (string) $r->status,
			'owner_id'   => (int) $r->owner_id,
			'data'       => json_decode( (string) $r->data, true ) ?: array(),
			'fields'     => FW_CRM_Fields::get_values( (string) $r->type, (int) $r->id ),
			'created_at' => $r->created_at,
			'updated_at' => $r->updated_at,
		);
	}

	/**
	 * @param string $type
	 * @param array  $args status, owner, search, related ( [ type, id ] ), per_page, paged
	 *
	 * @return array[] As get(), without fields.
	 */
	public static function query( $type, array $args = array() ) {
		global $wpdb;

		$t = self::table();
		$w = array( $wpdb->prepare( 'o.type = %s', sanitize_key( (string) $type ) ), 'o.deleted_at IS NULL' );
		if ( ! empty( $args['status'] ) ) {
			$w[] = $wpdb->prepare( 'o.status = %s', (string) $args['status'] );
		}
		if ( isset( $args['owner'] ) && '' !== $args['owner'] ) {
			$w[] = $wpdb->prepare( 'o.owner_id = %d', (int) $args['owner'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$w[] = $wpdb->prepare( 'o.title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' );
		}
		if ( ! empty( $args['related'] ) && 2 === count( (array) $args['related'] ) ) {
			$ids = FW_CRM_Relations::ids( (string) $args['related'][0], (int) $args['related'][1], sanitize_key( (string) $type ) );
			$w[] = $ids ? 'o.id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : '1=0';
		}
		$sql = "SELECT * FROM {$t} o WHERE " . implode( ' AND ', $w ) . ' ORDER BY o.updated_at DESC';
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', max( 1, (int) ( $args['per_page'] ?? 50 ) ), ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r->id, 'type' => $r->type, 'title' => $r->title, 'status' => $r->status, 'owner_id' => (int) $r->owner_id, 'data' => json_decode( (string) $r->data, true ) ?: array(), 'created_at' => $r->created_at, 'updated_at' => $r->updated_at );
		}, (array) $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Delete a record with its fields, links and history.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$r = self::get( $id );
		if ( ! $r ) {
			return false;
		}
		FW_CRM_Relations::delete_for( $r['type'], (int) $id );
		FW_CRM_Activity::delete_for( $r['type'], (int) $id );
		$wpdb->delete( FW_CRM_Installer::table( 'field_values' ), array( 'object_type' => $r['type'], 'object_id' => (int) $id ) );
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Link a record to a contact, company or deal (or another record).
	 *
	 * @param string $type
	 * @param int    $id
	 * @param string $to_type
	 * @param int    $to_id
	 * @param string $label
	 *
	 * @return bool
	 */
	public static function link( $type, $id, $to_type, $to_id, $label = '' ) {
		return FW_CRM_Relations::link( (string) $type, (int) $id, (string) $to_type, (int) $to_id, (string) $label );
	}

	/**
	 * May the current person change records of this type?
	 *
	 * @param string $type
	 *
	 * @return bool
	 */
	public static function can_edit( $type ) {
		$def = self::type( $type );
		return $def && current_user_can( $def['capability'] );
	}

	/**
	 * @param array  $def
	 * @param string $status
	 *
	 * @return string
	 */
	private static function status( array $def, $status ) {
		if ( ! $def['statuses'] ) {
			return sanitize_key( $status );
		}
		return array_key_exists( $status, $def['statuses'] ) ? $status : (string) key( $def['statuses'] );
	}

	/** A panel per object type on the record pages it relates to. */
	private static function panels() {
		foreach ( self::$types as $type => $def ) {
			foreach ( $def['relates'] as $object ) {
				add_filter( 'unysonplus_crm_' . $object . '_panels', function ( $panels, $record ) use ( $type, $def, $object ) {
					$id = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
					$panels[ 'object_' . $type ] = array(
						'label'    => $def['plural'],
						'priority' => 20,
						'column'   => 'side',
						'callback' => function () use ( $type, $def, $object, $id ) {
							$rows = self::query( $type, array( 'related' => array( $object, $id ), 'per_page' => 20 ) );
							if ( ! $rows ) {
								echo '<p class="fw-crm-muted">' . esc_html( sprintf( __( 'No %s linked.', 'fw' ), strtolower( $def['plural'] ) ) ) . '</p>';
								return;
							}
							echo '<ul class="fw-crm-case-links">';
							foreach ( $rows as $r ) {
								$st = '' !== $r['status'] ? ( $def['statuses'][ $r['status'] ] ?? $r['status'] ) : '';
								/** Filters the link of a custom object record in its panel ('' = plain text). */
								$url = (string) apply_filters( 'unysonplus_crm_object_url', '', $r );
								printf( '<li>%1$s %2$s</li>', '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $r['title'] ) . '</a>' : esc_html( $r['title'] ), '' !== $st ? '<span class="fw-crm-muted">' . esc_html( $st ) . '</span>' : '' );
							}
							echo '</ul>';
						},
					);
					return $panels;
				}, 20, 2 );
			}
		}
	}
}
