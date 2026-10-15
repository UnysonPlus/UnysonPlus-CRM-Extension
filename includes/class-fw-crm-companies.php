<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Companies (accounts): the organisations contacts work for.
 *
 * A contact links to any number of companies through FW_CRM_Relations (label
 * `works_at`); the one shown first is the contact's `company_id` column, so lists
 * and segments can filter by company with an index. Deleting moves a company to
 * the bin (`deleted_at`); purge() removes it for good.
 */
class FW_CRM_Companies {

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'companies' );
	}

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
	 * A live company by its web domain (example.com), for matching contacts by email.
	 *
	 * @param string $domain
	 *
	 * @return object|null
	 */
	public static function find_by_domain( $domain ) {
		global $wpdb;

		$domain = self::normalize_domain( $domain );
		if ( '' === $domain ) {
			return null;
		}
		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE domain = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $domain ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param string $value A domain, a URL or an email address.
	 *
	 * @return string
	 */
	public static function normalize_domain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( false !== strpos( $value, '@' ) ) {
			$value = substr( strrchr( $value, '@' ), 1 );
		}
		$host = wp_parse_url( false === strpos( $value, '://' ) ? 'http://' . $value : $value, PHP_URL_HOST );

		return preg_replace( '/^www\./', '', (string) $host );
	}

	/**
	 * @param array $args search, owner, trashed (exclude|only|include), orderby, order, per_page, paged
	 *
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = array_merge( array( 'orderby' => 'name', 'order' => 'ASC', 'per_page' => 20, 'paged' => 1 ), $args );
		$where = self::where( $args );
		$table = self::table();

		$orderby = in_array( $args['orderby'], array( 'id', 'name', 'domain', 'created_at', 'updated_at', 'last_activity_at', 'lifetime_value', 'deleted_at' ), true ) ? $args['orderby'] : 'name';
		$order   = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$sql     = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}";

		if ( (int) $args['per_page'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], ( max( 1, (int) $args['paged'] ) - 1 ) * (int) $args['per_page'] );
		}

		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . self::where( $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args
	 *
	 * @return string
	 */
	private static function where( array $args ) {
		global $wpdb;

		$where   = array( '1=1' );
		$trashed = (string) ( $args['trashed'] ?? 'exclude' );
		if ( 'only' === $trashed ) {
			$where[] = 'deleted_at IS NOT NULL';
		} elseif ( 'include' !== $trashed ) {
			$where[] = 'deleted_at IS NULL';
		}
		if ( ! empty( $args['deleted_before'] ) ) {
			$where[] = $wpdb->prepare( 'deleted_at < %s', (string) $args['deleted_before'] );
		}
		if ( isset( $args['owner'] ) && '' !== $args['owner'] ) {
			$where[] = $wpdb->prepare( 'owner_id = %d', (int) $args['owner'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '( name LIKE %s OR domain LIKE %s OR email LIKE %s )', $like, $like, $like );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	private static function sanitize( array $data ) {
		$row = array();
		foreach ( array( 'name', 'phone', 'industry', 'size', 'address_line1', 'address_line2', 'city', 'region', 'postcode' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$row[ $k ] = sanitize_text_field( (string) $data[ $k ] );
			}
		}
		if ( isset( $data['website'] ) ) {
			$row['website'] = esc_url_raw( (string) $data['website'] );
		}
		if ( isset( $data['email'] ) ) {
			$row['email'] = sanitize_email( (string) $data['email'] );
		}
		if ( isset( $data['domain'] ) || isset( $data['website'] ) ) {
			$row['domain'] = self::normalize_domain( isset( $data['domain'] ) && '' !== $data['domain'] ? $data['domain'] : ( $data['website'] ?? '' ) );
		}
		if ( isset( $data['owner_id'] ) ) {
			$row['owner_id'] = (int) $data['owner_id'];
		}
		if ( isset( $data['lifetime_value'] ) ) {
			$row['lifetime_value'] = round( (float) $data['lifetime_value'], 2 );
		}
		if ( isset( $data['country'] ) ) {
			$row['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $data['country'] ), 0, 2 ) );
		}
		if ( array_key_exists( 'deleted_at', $data ) ) {
			$row['deleted_at'] = $data['deleted_at'] ? $data['deleted_at'] : null;
		}
		return $row;
	}

	/**
	 * @param array $data name (required), domain, website, phone, email, industry, size, owner_id, address…, fields (custom)
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::sanitize( $data );
		if ( empty( $row['name'] ) ) {
			return new WP_Error( 'fw_crm_company_name', __( 'A company needs a name.', 'fw' ) );
		}
		$now = current_time( 'mysql' );
		$row = array_merge( $row, array( 'created_at' => $now, 'updated_at' => $now ) );

		if ( ! $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the company.', 'fw' ) );
		}
		$id = (int) $wpdb->insert_id;

		FW_CRM_Fields::assign_autonumbers( 'company', $id );
		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			FW_CRM_Fields::set_values( 'company', $id, $data['fields'] );
		}
		FW_CRM_Activity::log( 'company', $id, 'created', __( 'Company added', 'fw' ) );

		/** Fires after a company is created. */
		do_action( 'unysonplus_crm_company_created', self::find( $id ) );

		return $id;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Company not found.', 'fw' ) );
		}
		$row = self::sanitize( $data );
		if ( isset( $row['name'] ) && '' === $row['name'] ) {
			return new WP_Error( 'fw_crm_company_name', __( 'A company needs a name.', 'fw' ) );
		}
		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$set = FW_CRM_Fields::set_values( 'company', $id, $data['fields'] );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}
		if ( $row ) {
			$row['updated_at'] = current_time( 'mysql' );
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		}

		/** Fires after a company changes. */
		do_action( 'unysonplus_crm_company_updated', self::find( $id ), $before );

		return true;
	}

	/** Move to the bin. */
	public static function trash( $id ) {
		$result = self::update( $id, array( 'deleted_at' => current_time( 'mysql' ) ) );
		if ( true === $result ) {
			FW_CRM_Activity::log( 'company', $id, 'trashed', __( 'Moved to the bin', 'fw' ) );
		}
		return $result;
	}

	/** Bring back from the bin. */
	public static function restore( $id ) {
		$result = self::update( $id, array( 'deleted_at' => null ) );
		if ( true === $result ) {
			FW_CRM_Activity::log( 'company', $id, 'restored', __( 'Restored from the bin', 'fw' ) );
		}
		return $result;
	}

	/**
	 * Delete for good, with its links, timeline and field values. Contacts stay;
	 * their primary company is cleared.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function purge( $id ) {
		global $wpdb;

		$company = self::find( $id );
		if ( ! $company ) {
			return false;
		}
		$wpdb->update( FW_CRM_Installer::table( 'subscribers' ), array( 'company_id' => 0 ), array( 'company_id' => (int) $id ) );
		FW_CRM_Relations::delete_for( 'company', $id );
		FW_CRM_Activity::delete_for( 'company', $id );
		FW_CRM_Fields::delete_for( 'company', $id );
		FW_CRM_Tasks::delete_for( 'company', $id );
		$wpdb->update( FW_CRM_Installer::table( 'deals' ), array( 'company_id' => 0 ), array( 'company_id' => (int) $id ) ); // Deals stay.
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );

		/** Fires after a company is deleted for good. */
		do_action( 'unysonplus_crm_company_deleted', $company );

		return true;
	}

	/**
	 * Ids of the contacts linked to a company.
	 *
	 * @param int $id
	 *
	 * @return int[]
	 */
	public static function contact_ids( $id ) {
		return FW_CRM_Relations::ids( 'company', $id, 'contact' );
	}
}
