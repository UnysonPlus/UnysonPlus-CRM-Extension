<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Custom fields for CRM records (contacts, companies, later deals and cases).
 *
 * Definitions live in one option; values live in the `field_values` table, one row
 * per record and field. Numbers, money and dates are mirrored into indexed
 * value_num / value_date columns so lists can sort and filter by them.
 *
 * Definition: { key, label, type, object, group, choices (for choice types),
 * required, settings } where settings carries type options: lookup `target`
 * (contact|company|deal|post), autonumber `prefix` and `pad`.
 */
class FW_CRM_Fields {

	const OPTION = 'fw_crm_fields';

	/**
	 * Field types and how they store.
	 *
	 * @return array type => { label, store: text|num|date|json }
	 */
	public static function types() {
		$types = array(
			'text'        => array( 'label' => __( 'Text', 'fw' ), 'store' => 'text' ),
			'textarea'    => array( 'label' => __( 'Long text', 'fw' ), 'store' => 'text' ),
			'number'      => array( 'label' => __( 'Number', 'fw' ), 'store' => 'num' ),
			'money'       => array( 'label' => __( 'Money', 'fw' ), 'store' => 'num' ),
			'date'        => array( 'label' => __( 'Date', 'fw' ), 'store' => 'date' ),
			'datetime'    => array( 'label' => __( 'Date and time', 'fw' ), 'store' => 'date' ),
			'choice'      => array( 'label' => __( 'Choice', 'fw' ), 'store' => 'text' ),
			'multichoice' => array( 'label' => __( 'Multiple choice', 'fw' ), 'store' => 'json' ),
			'checkbox'    => array( 'label' => __( 'Yes / no', 'fw' ), 'store' => 'num' ),
			'url'         => array( 'label' => __( 'Web address', 'fw' ), 'store' => 'text' ),
			'email'       => array( 'label' => __( 'Email', 'fw' ), 'store' => 'text' ),
			'phone'       => array( 'label' => __( 'Phone', 'fw' ), 'store' => 'text' ),
			'lookup'      => array( 'label' => __( 'Link to a record', 'fw' ), 'store' => 'num' ),
			'address'     => array( 'label' => __( 'Address', 'fw' ), 'store' => 'json' ),
			'autonumber'  => array( 'label' => __( 'Auto-number', 'fw' ), 'store' => 'text' ),
		);

		/** Filters the CRM custom field types. */
		return (array) apply_filters( 'unysonplus_crm_field_types', $types );
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'field_values' );
	}

	/* ---------------------------------------------------------------------- *
	 * Definitions
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string|null $object Only this record type's fields.
	 *
	 * @return array
	 */
	public static function definitions( $object = null ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? array_values( $all ) : array();

		if ( null === $object ) {
			return $all;
		}

		return array_values( array_filter( $all, function ( $d ) use ( $object ) {
			return ( $d['object'] ?? 'contact' ) === $object;
		} ) );
	}

	/**
	 * @param string $object
	 * @param string $key
	 *
	 * @return array|null
	 */
	public static function definition( $object, $key ) {
		foreach ( self::definitions( $object ) as $d ) {
			if ( $d['key'] === $key ) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * Create or update a field definition (matched by object + key).
	 *
	 * @param array $def
	 *
	 * @return array|WP_Error The stored definition.
	 */
	public static function save_definition( array $def ) {
		$types  = self::types();
		$object = sanitize_key( (string) ( $def['object'] ?? 'contact' ) );
		$key    = sanitize_key( (string) ( $def['key'] ?? '' ) );
		$type   = (string) ( $def['type'] ?? 'text' );

		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) ) {
			return new WP_Error( 'fw_crm_field_key', __( 'A field key must be lowercase letters, numbers and underscores, starting with a letter.', 'fw' ) );
		}
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'fw_crm_field_type', sprintf( __( 'Unknown field type "%s".', 'fw' ), $type ) );
		}

		$clean = array(
			'key'      => $key,
			'label'    => sanitize_text_field( (string) ( $def['label'] ?? $key ) ),
			'type'     => $type,
			'object'   => $object,
			'group'    => sanitize_text_field( (string) ( $def['group'] ?? '' ) ),
			'required' => ! empty( $def['required'] ),
			// An extension that owns this field (its values are written by code, not staff).
			'managed_by' => sanitize_key( (string) ( $def['managed_by'] ?? '' ) ),
			'choices'  => array(),
			'settings' => array(),
		);

		if ( in_array( $type, array( 'choice', 'multichoice' ), true ) ) {
			$choices = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $def['choices'] ?? array() ) ) ), 'strlen' ) );
			if ( ! $choices ) {
				return new WP_Error( 'fw_crm_field_choices', __( 'A choice field needs at least one choice.', 'fw' ) );
			}
			$clean['choices'] = $choices;
		}
		if ( 'lookup' === $type ) {
			$target = sanitize_key( (string) ( $def['settings']['target'] ?? 'contact' ) );
			if ( ! in_array( $target, array( 'contact', 'company', 'deal', 'case', 'post', 'user' ), true ) ) {
				return new WP_Error( 'fw_crm_field_target', __( 'A link field points to a contact, company, deal, case, post or user.', 'fw' ) );
			}
			$clean['settings']['target'] = $target;
		}
		if ( 'autonumber' === $type ) {
			$clean['settings']['prefix'] = sanitize_text_field( (string) ( $def['settings']['prefix'] ?? '' ) );
			$clean['settings']['pad']    = max( 0, min( 12, (int) ( $def['settings']['pad'] ?? 4 ) ) );
		}

		$all   = self::definitions();
		$found = false;
		foreach ( $all as $i => $d ) {
			if ( $d['key'] === $key && ( $d['object'] ?? 'contact' ) === $object ) {
				$all[ $i ] = $clean;
				$found     = true;
			}
		}
		if ( ! $found ) {
			$all[] = $clean;
		}
		update_option( self::OPTION, $all, false );
		if ( class_exists( 'FW_CRM_Segments' ) ) {
			FW_CRM_Segments::flush_fields();
		}

		return $clean;
	}

	/**
	 * Remove a field and every value stored for it.
	 *
	 * @param string $object
	 * @param string $key
	 *
	 * @return bool
	 */
	public static function delete_definition( $object, $key ) {
		global $wpdb;

		$all  = self::definitions();
		$kept = array_values( array_filter( $all, function ( $d ) use ( $object, $key ) {
			return ! ( $d['key'] === $key && ( $d['object'] ?? 'contact' ) === $object );
		} ) );
		if ( count( $kept ) === count( $all ) ) {
			return false;
		}
		update_option( self::OPTION, $kept, false );
		$wpdb->delete( self::table(), array( 'object_type' => $object, 'field_key' => $key ) );

		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * Values
	 * ---------------------------------------------------------------------- */

	/**
	 * All custom field values of one record, decoded.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 *
	 * @return array key => value
	 */
	public static function get_values( $object_type, $object_id ) {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT field_key, value FROM {$table} WHERE object_type = %s AND object_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$object_type,
			(int) $object_id
		) );

		$types = self::types();
		$out   = array();
		foreach ( $rows as $r ) {
			$def   = self::definition( $object_type, $r->field_key );
			$store = $def && isset( $types[ $def['type'] ] ) ? $types[ $def['type'] ]['store'] : 'text';
			if ( 'json' === $store ) {
				$v = json_decode( (string) $r->value, true );
				$out[ $r->field_key ] = is_array( $v ) ? $v : array();
			} elseif ( 'num' === $store ) {
				$out[ $r->field_key ] = '' === $r->value ? null : $r->value + 0;
			} else {
				$out[ $r->field_key ] = (string) $r->value;
			}
		}

		return $out;
	}

	/**
	 * Check and store values (only the keys given change). Nothing is stored if any
	 * value is invalid.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @param array  $values key => value; null removes a value
	 *
	 * @return true|WP_Error
	 */
	public static function set_values( $object_type, $object_id, array $values ) {
		global $wpdb;

		$rows   = array();
		$errors = array();

		foreach ( $values as $key => $value ) {
			$def = self::definition( $object_type, (string) $key );
			if ( ! $def ) {
				$errors[] = sprintf( __( '"%s" is not a field of this record type.', 'fw' ), $key );
				continue;
			}
			$cast = self::cast( $def, $value );
			if ( is_wp_error( $cast ) ) {
				$errors[] = $def['label'] . ': ' . $cast->get_error_message();
				continue;
			}
			$rows[ $def['key'] ] = $cast;
		}

		if ( $errors ) {
			return new WP_Error( 'fw_crm_field_values', implode( ' ', $errors ) );
		}

		$table = self::table();
		foreach ( $rows as $key => $cast ) {
			if ( null === $cast ) {
				$wpdb->delete( $table, array( 'object_type' => $object_type, 'object_id' => (int) $object_id, 'field_key' => $key ) );
				continue;
			}
			// Real NULLs for the index columns (a prepared %s would turn null into '').
			$num  = null === $cast['num'] ? 'NULL' : $wpdb->prepare( '%s', $cast['num'] );
			$date = null === $cast['date'] ? 'NULL' : $wpdb->prepare( '%s', $cast['date'] );
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (object_type, object_id, field_key, value, value_num, value_date) VALUES (%s, %d, %s, %s, {$num}, {$date})
				 ON DUPLICATE KEY UPDATE value = VALUES(value), value_num = VALUES(value_num), value_date = VALUES(value_date)", // phpcs:ignore WordPress.DB.PreparedSQL
				$object_type,
				(int) $object_id,
				$key,
				$cast['value']
			) );
		}

		/** Fires after a record's custom field values change. */
		do_action( 'unysonplus_crm_fields_updated', (string) $object_type, (int) $object_id, array_keys( $rows ) );

		return true;
	}

	/**
	 * Give a new record its auto-numbers.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 */
	public static function assign_autonumbers( $object_type, $object_id ) {
		foreach ( self::definitions( $object_type ) as $def ) {
			if ( 'autonumber' !== $def['type'] ) {
				continue;
			}
			$counter = 'fw_crm_autonumber_' . $object_type . '_' . $def['key'];
			$next    = (int) get_option( $counter, 0 ) + 1;
			update_option( $counter, $next, false );
			$value = $def['settings']['prefix'] . str_pad( (string) $next, (int) $def['settings']['pad'], '0', STR_PAD_LEFT );
			self::set_values( $object_type, $object_id, array( $def['key'] => $value ) );
		}
	}

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 *
	 * @return int
	 */
	public static function delete_for( $object_type, $object_id ) {
		global $wpdb;

		return (int) $wpdb->delete( self::table(), array( 'object_type' => $object_type, 'object_id' => (int) $object_id ) );
	}

	/**
	 * Validate one value against its field.
	 *
	 * @param array $def
	 * @param mixed $value
	 *
	 * @return array|null|WP_Error { value, num, date } ready to store; null = remove.
	 */
	private static function cast( array $def, $value ) {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) || ( is_array( $value ) && ! $value ) ) {
			return $def['required'] ? new WP_Error( 'required', __( 'is required.', 'fw' ) ) : null;
		}

		$out = array( 'value' => '', 'num' => null, 'date' => null );

		switch ( $def['type'] ) {
			case 'number':
			case 'money':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'num', __( 'must be a number.', 'fw' ) );
				}
				$out['value'] = (string) ( $value + 0 );
				$out['num']   = (string) ( $value + 0 );
				break;
			case 'checkbox':
				$on           = in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );
				$out['value'] = $on ? '1' : '0';
				$out['num']   = $out['value'];
				break;
			case 'date':
			case 'datetime':
				$ts = strtotime( (string) $value );
				if ( false === $ts ) {
					return new WP_Error( 'date', __( 'must be a date.', 'fw' ) );
				}
				$out['value'] = 'date' === $def['type'] ? gmdate( 'Y-m-d', $ts ) : gmdate( 'Y-m-d H:i:s', $ts );
				$out['date']  = gmdate( 'Y-m-d H:i:s', $ts );
				break;
			case 'choice':
				if ( ! in_array( (string) $value, $def['choices'], true ) ) {
					return new WP_Error( 'choice', sprintf( __( 'must be one of: %s.', 'fw' ), implode( ', ', $def['choices'] ) ) );
				}
				$out['value'] = (string) $value;
				break;
			case 'multichoice':
				$picked = array_values( array_intersect( array_map( 'strval', (array) $value ), $def['choices'] ) );
				if ( count( $picked ) !== count( (array) $value ) ) {
					return new WP_Error( 'choice', sprintf( __( 'can only use: %s.', 'fw' ), implode( ', ', $def['choices'] ) ) );
				}
				$out['value'] = wp_json_encode( $picked );
				break;
			case 'url':
				$url = esc_url_raw( (string) $value );
				if ( '' === $url ) {
					return new WP_Error( 'url', __( 'must be a web address.', 'fw' ) );
				}
				$out['value'] = $url;
				break;
			case 'email':
				if ( ! is_email( (string) $value ) ) {
					return new WP_Error( 'email', __( 'must be an email address.', 'fw' ) );
				}
				$out['value'] = sanitize_email( (string) $value );
				break;
			case 'lookup':
				$id = (int) $value;
				if ( $id <= 0 || ! self::target_exists( $def['settings']['target'] ?? 'contact', $id ) ) {
					return new WP_Error( 'lookup', __( 'must point to an existing record.', 'fw' ) );
				}
				$out['value'] = (string) $id;
				$out['num']   = (string) $id;
				break;
			case 'address':
				$parts = array();
				foreach ( array( 'line1', 'line2', 'city', 'region', 'postcode', 'country' ) as $k ) {
					$parts[ $k ] = sanitize_text_field( (string) ( ( (array) $value )[ $k ] ?? '' ) );
				}
				$out['value'] = wp_json_encode( $parts );
				break;
			case 'textarea':
				$out['value'] = sanitize_textarea_field( (string) $value );
				break;
			default:
				$out['value'] = sanitize_text_field( (string) $value );
		}

		return $out;
	}

	/**
	 * @param string $target
	 * @param int    $id
	 *
	 * @return bool
	 */
	private static function target_exists( $target, $id ) {
		switch ( $target ) {
			case 'contact':
				return (bool) FW_CRM_Subscribers::find( $id );
			case 'company':
				return class_exists( 'FW_CRM_Companies' ) && (bool) FW_CRM_Companies::find( $id );
			case 'post':
				return (bool) get_post( $id );
			case 'user':
				return (bool) get_userdata( $id );
			default:
				/** Filters whether a record a link field points to exists (deal, case and future types). */
				return (bool) apply_filters( 'unysonplus_crm_record_exists', false, $target, $id );
		}
	}
}
