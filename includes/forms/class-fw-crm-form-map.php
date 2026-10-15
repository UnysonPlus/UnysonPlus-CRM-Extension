<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Where a form's answers go in the CRM.
 *
 * A form with "Add the sender to the CRM" on carries a map in its settings
 * (`action_crm_map`, JSON): { fields: { <field id>: <target> }, deal_title: <field id>,
 * date: <field id> }. A target is one of:
 *
 *   timeline            only on the timeline entry (the default for anything unmatched)
 *   name                the full name, split into first and last
 *   first_name, last_name, phone, job_title, address_line1, address_line2, city,
 *   region, postcode, country
 *   company             the contact's main company, found by name or created (Companies module)
 *   cf:<key>            an existing contact custom field
 *   new                 a new contact custom field, created from the form field
 *
 * A field missing from the map gets the automatic target (auto_target()), so a form
 * that was never opened since this arrived still lands name, email and phone.
 *
 * A "new" field is linked to the form field by "<form id>:<field id>" in the option
 * `fw_crm_form_fields`, so renaming the form field later keeps the same CRM field.
 */
class FW_CRM_Form_Map {

	const LINKS_OPTION = 'fw_crm_form_fields';

	/** Form field types that are never offered: anti-spam, secrets, layout, files. */
	const SKIP_TYPES = array( 'honeypot', 'recaptcha', 'hidden', 'form-header-title', 'file-upload', 'member-password', 'password' );

	/**
	 * @return array target => label (contact fields only; custom fields come from custom_fields())
	 */
	public static function contact_targets() {
		$t = array(
			'name'          => __( 'Name (split into first and last)', 'fw' ),
			'first_name'    => __( 'First name', 'fw' ),
			'last_name'     => __( 'Last name', 'fw' ),
			'phone'         => __( 'Phone', 'fw' ),
			'job_title'     => __( 'Job title', 'fw' ),
		);
		if ( FW_CRM_Modules::enabled( 'companies' ) ) {
			$t['company'] = __( 'Company', 'fw' );
		}
		$t += array(
			'address_line1' => __( 'Street', 'fw' ),
			'address_line2' => __( 'Street, line 2', 'fw' ),
			'city'          => __( 'City', 'fw' ),
			'region'        => __( 'Region', 'fw' ),
			'postcode'      => __( 'Postcode', 'fw' ),
			'country'       => __( 'Country', 'fw' ),
		);

		/** Filters the contact fields a form answer can be saved to: target => label. */
		return (array) apply_filters( 'unysonplus_crm_form_targets', $t );
	}

	/**
	 * Contact custom fields a form answer can fill (not auto-numbers or record links).
	 *
	 * @return array key => { label, type }
	 */
	public static function custom_fields() {
		$out = array();
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $def ) {
			$def = (array) $def;
			if ( in_array( $def['type'], array( 'autonumber', 'lookup', 'address' ), true ) ) {
				continue;
			}
			$out[ $def['key'] ] = array( 'label' => (string) $def['label'], 'type' => (string) $def['type'] );
		}
		return $out;
	}

	/**
	 * The CRM field type a new custom field gets from a form field.
	 *
	 * @param array $item A form-builder item.
	 *
	 * @return string
	 */
	public static function field_type_for( array $item ) {
		switch ( (string) ( $item['type'] ?? '' ) ) {
			case 'textarea':
				return 'textarea';
			case 'number':
			case 'rating':
				return 'number';
			case 'select':
			case 'radio':
				return 'choice';
			case 'checkboxes':
				return 'multichoice';
			case 'email':
				return 'email';
			case 'website':
				return 'url';
			case 'date':
				return 'datetime' === ( $item['options']['mode'] ?? '' ) ? 'datetime' : ( 'time' === ( $item['options']['mode'] ?? '' ) ? 'text' : 'date' );
		}
		return 'text';
	}

	/**
	 * The target a field gets when nobody chose one. Mirrored in static/js/form-map.js.
	 *
	 * @param array $item
	 * @param array $custom custom_fields()
	 *
	 * @return string
	 */
	public static function auto_target( array $item, array $custom = null ) {
		$type  = (string) ( $item['type'] ?? '' );
		$label = strtolower( trim( wp_strip_all_tags( (string) ( $item['options']['label'] ?? '' ) ) ) );
		if ( in_array( $type, array( 'email', 'consent' ), true ) || in_array( $type, self::SKIP_TYPES, true ) ) {
			return $type;
		}
		$custom = null === $custom ? self::custom_fields() : $custom;
		foreach ( $custom as $key => $def ) {
			if ( '' !== $label && strtolower( $def['label'] ) === $label ) {
				return 'cf:' . $key;
			}
		}
		if ( ! in_array( $type, array( 'text', 'number', 'member-username' ), true ) ) {
			return 'timeline';
		}
		$rules = array(
			'company'       => '/\b(company|organi[sz]ation|business)\b/',
			'first_name'    => '/\b(first|given)\s*name\b/',
			'last_name'     => '/\b(last\s*name|surname|family\s*name)\b/',
			'name'          => '/\bname\b/',
			'phone'         => '/\b(phone|mobile|tel|telephone|cell)\b/',
			'job_title'     => '/\b(job|position|role)\b/',
			'postcode'      => '/\b(post\s*code|zip)\b/',
			'city'          => '/\b(city|town)\b/',
			'region'        => '/\b(state|province|region|county)\b/',
			'country'       => '/\bcountry\b/',
			'address_line1' => '/\b(address|street)\b/',
		);
		$targets = self::contact_targets();
		foreach ( $rules as $target => $re ) {
			if ( isset( $targets[ $target ] ) && preg_match( $re, $label ) ) {
				return $target;
			}
		}
		return 'timeline';
	}

	/**
	 * The stored map, decoded.
	 *
	 * @param array $form The form's settings.
	 *
	 * @return array { fields: [], deal_title: '', date: '' }
	 */
	public static function stored( array $form ) {
		$raw = $form['action_crm_map'] ?? '';
		$map = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$map = is_array( $map ) ? $map : array();
		return array(
			'fields'     => isset( $map['fields'] ) && is_array( $map['fields'] ) ? array_map( 'strval', $map['fields'] ) : array(),
			'deal_title' => (string) ( $map['deal_title'] ?? '' ),
			'date'       => (string) ( $map['date'] ?? '' ),
		);
	}

	/**
	 * The effective target of every field: the stored choice, else the automatic one.
	 *
	 * @param array $form
	 * @param array $items field id => form-builder item
	 *
	 * @return array field id => target
	 */
	public static function resolve( array $form, array $items ) {
		$stored = self::stored( $form )['fields'];
		$custom = self::custom_fields();
		$out    = array();
		foreach ( $items as $id => $item ) {
			$auto = self::auto_target( (array) $item, $custom );
			if ( in_array( $auto, array_merge( array( 'email', 'consent' ), self::SKIP_TYPES ), true ) ) {
				$out[ $id ] = $auto; // Not a choice.
				continue;
			}
			$t = $stored[ $id ] ?? '';
			if ( 0 === strpos( $t, 'cf:' ) && ! isset( $custom[ substr( $t, 3 ) ] ) ) {
				$t = ''; // The custom field was deleted since.
			}
			if ( '' !== $t && 'cf:' !== substr( $t, 0, 3 ) && ! in_array( $t, array( 'timeline', 'new' ), true ) && ! isset( self::contact_targets()[ $t ] ) ) {
				$t = ''; // e.g. "company" while the Companies module is off.
			}
			$out[ $id ] = '' !== $t ? $t : $auto;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * New custom fields
	 * ---------------------------------------------------------------------- */

	/**
	 * The custom field linked to a form field, created (or its choices extended) as needed.
	 *
	 * @param string $form_id
	 * @param string $field_id
	 * @param array  $item
	 *
	 * @return string|WP_Error the key
	 */
	public static function ensure_field( $form_id, $field_id, array $item ) {
		$links = get_option( self::LINKS_OPTION, array() );
		$links = is_array( $links ) ? $links : array();
		$link  = $form_id . ':' . $field_id;
		$label = trim( wp_strip_all_tags( (string) ( $item['options']['label'] ?? '' ) ) );
		$label = '' !== $label ? $label : $field_id;
		$type  = self::field_type_for( $item );
		$opts  = array_values( array_filter( array_map( 'strval', (array) ( $item['options']['choices'] ?? array() ) ), 'strlen' ) );

		$key = $links[ $link ] ?? '';
		$def = '' !== $key ? FW_CRM_Fields::definition( 'contact', $key ) : null;
		if ( ! $def ) {
			// A field of that name already exists: use it rather than make a twin.
			foreach ( self::custom_fields() as $k => $d ) {
				if ( strtolower( $d['label'] ) === strtolower( $label ) ) {
					$key = $k;
					$def = FW_CRM_Fields::definition( 'contact', $k );
					break;
				}
			}
		}
		if ( ! $def ) {
			$base = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( remove_accents( $label ) ) ), '_' );
			$base = '' === $base || ! preg_match( '/^[a-z]/', $base ) ? 'form_' . $base : $base;
			$base = substr( rtrim( $base, '_' ), 0, 56 );
			$key  = $base;
			for ( $i = 2; FW_CRM_Fields::definition( 'contact', $key ); $i++ ) {
				$key = $base . '_' . $i;
			}
			$saved = FW_CRM_Fields::save_definition( array(
				'object'  => 'contact',
				'key'     => $key,
				'label'   => $label,
				'type'    => $type,
				'choices' => in_array( $type, array( 'choice', 'multichoice' ), true ) ? ( $opts ? $opts : array( $label ) ) : array(),
			) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		} else {
			$def = (array) $def;
			// A choice added to the form later is added to the CRM field too.
			if ( in_array( $def['type'], array( 'choice', 'multichoice' ), true ) && $opts ) {
				$have = array_map( 'strval', (array) $def['choices'] );
				$add  = array_values( array_diff( $opts, $have ) );
				if ( $add ) {
					$def['choices'] = array_merge( $have, $add );
					FW_CRM_Fields::save_definition( $def );
				}
			}
		}
		if ( ( $links[ $link ] ?? '' ) !== $key ) {
			$links[ $link ] = $key;
			update_option( self::LINKS_OPTION, $links, false );
		}
		return $key;
	}

	/**
	 * Create the "new" fields of every contact form saved in a page, so they show
	 * under CRM → Custom fields as soon as the page is saved (not only after the
	 * first submission).
	 *
	 * @internal Hooked to added_post_meta / updated_post_meta.
	 *
	 * @param int    $meta_id
	 * @param int    $post_id
	 * @param string $meta_key
	 * @param mixed  $value
	 */
	public static function _on_builder_saved( $meta_id, $post_id, $meta_key, $value ) {
		if ( 'fw:opt:ext:pb:page-builder:json' !== $meta_key ) {
			return;
		}
		$tree = is_string( $value ) ? json_decode( wp_unslash( $value ), true ) : $value;
		if ( ! is_array( $tree ) ) {
			$tree = is_string( $value ) ? json_decode( $value, true ) : null;
		}
		if ( is_array( $tree ) ) {
			self::walk( $tree );
		}
	}

	/**
	 * @param array $nodes
	 */
	private static function walk( array $nodes ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( 'contact_form' === ( $node['shortcode'] ?? '' ) && is_array( $node['atts'] ?? null ) ) {
				self::create_new_fields( $node['atts'] );
			}
			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				self::walk( $node['_items'] );
			}
		}
	}

	/**
	 * @param array $atts A contact form's settings.
	 */
	public static function create_new_fields( array $atts ) {
		if ( 'yes' !== ( $atts['action_crm_contact'] ?? '' ) || empty( $atts['id'] ) ) {
			return;
		}
		$fields = self::stored( $atts )['fields'];
		if ( ! in_array( 'new', $fields, true ) ) {
			return;
		}
		$items = self::items_of( $atts );
		foreach ( $fields as $field_id => $target ) {
			if ( 'new' === $target && isset( $items[ $field_id ] ) ) {
				self::ensure_field( (string) $atts['id'], (string) $field_id, $items[ $field_id ] );
			}
		}
	}

	/**
	 * A contact form's fields as field id => item, from its saved settings.
	 *
	 * @param array $atts
	 *
	 * @return array
	 */
	public static function items_of( array $atts ) {
		$json  = $atts['form']['json'] ?? ( is_string( $atts['form'] ?? null ) ? $atts['form'] : '[]' );
		$list  = is_array( $json ) ? $json : json_decode( (string) $json, true );
		$items = array();
		foreach ( (array) $list as $item ) {
			if ( is_array( $item ) && ! empty( $item['shortcode'] ) ) {
				$items[ (string) $item['shortcode'] ] = $item;
			}
		}
		return $items;
	}

	/* ---------------------------------------------------------------------- *
	 * Applying a submission
	 * ---------------------------------------------------------------------- */

	/**
	 * Create or update the sender's contact from a submission.
	 *
	 * @param string $email
	 * @param array  $ctx   The Forms action context.
	 * @param array  $map   resolve()
	 * @param bool   $fill_blanks_only
	 *
	 * @return array|WP_Error { id, created (bool), kept: [ label => [ stored, submitted ] ] }
	 */
	public static function apply( $email, array $ctx, array $map, $fill_blanks_only ) {
		$items  = (array) ( $ctx['payload']['shortcode_to_item'] ?? array() );
		$data   = array();
		$fields = array();
		$co     = '';
		$labels = array();

		foreach ( (array) $ctx['fields'] as $row ) {
			$id     = (string) ( $row['id'] ?? '' );
			$target = $map[ $id ] ?? '';
			$value  = $row['value'];
			$flat   = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : trim( (string) $value );
			if ( '' === $flat || '' === $target || in_array( $target, array( 'timeline', 'email', 'consent' ), true ) || in_array( $target, self::SKIP_TYPES, true ) ) {
				continue;
			}
			if ( 'name' === $target ) {
				$parts              = preg_split( '/\s+/', $flat, 2 );
				$data['first_name'] = $parts[0];
				$data['last_name']  = $parts[1] ?? '';
				$labels['first_name'] = $row['label'];
				$labels['last_name']  = $row['label'];
			} elseif ( 'company' === $target ) {
				$co = $flat;
			} elseif ( 'new' === $target || 0 === strpos( $target, 'cf:' ) ) {
				$key = 'new' === $target ? self::ensure_field( (string) $ctx['form_id'], $id, (array) ( $items[ $id ] ?? array( 'type' => $row['type'], 'options' => array( 'label' => $row['label'] ) ) ) ) : substr( $target, 3 );
				if ( is_wp_error( $key ) ) {
					continue;
				}
				$fields[ $key ]          = is_array( $value ) ? array_values( $value ) : $flat;
				$labels[ 'cf:' . $key ] = $row['label'];
			} else {
				$data[ $target ]   = $flat;
				$labels[ $target ] = $row['label'];
			}
		}

		$existing = FW_CRM_Subscribers::find_by_email( $email );
		$kept     = array();
		if ( $existing && $existing->deleted_at ) {
			FW_CRM_Contacts::restore( (int) $existing->id ); // They came back: out of the bin.
		}

		if ( $existing && $fill_blanks_only ) {
			foreach ( $data as $k => $v ) {
				$now = (string) ( $existing->$k ?? '' );
				if ( '' !== $now ) {
					if ( strtolower( $now ) !== strtolower( (string) $v ) ) {
						$kept[ $labels[ $k ] ?? $k ] = array( $now, $v );
					}
					unset( $data[ $k ] );
				}
			}
			$current = FW_CRM_Fields::get_values( 'contact', (int) $existing->id );
			foreach ( $fields as $k => $v ) {
				$now = $current[ $k ] ?? '';
				$now = is_array( $now ) ? implode( ', ', $now ) : (string) $now;
				if ( '' !== $now ) {
					$new = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
					if ( strtolower( $now ) !== strtolower( $new ) ) {
						$kept[ $labels[ 'cf:' . $k ] ?? $k ] = array( $now, $new );
					}
					unset( $fields[ $k ] );
				}
			}
		}
		if ( $fields ) {
			$data['fields'] = $fields;
		}

		if ( $existing ) {
			$id = (int) $existing->id;
			if ( $data ) {
				$ok = FW_CRM_Contacts::update( $id, $data );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		} else {
			$id = FW_CRM_Contacts::create( array_merge( $data, array( 'email' => $email, 'source' => 'form' ) ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}

		if ( '' !== $co && class_exists( 'FW_CRM_Companies' ) ) {
			$company = self::company_by_name( $co );
			if ( ! $company ) {
				$company = FW_CRM_Companies::create( array( 'name' => $co ) );
			}
			if ( $company && ! is_wp_error( $company ) && ! in_array( (int) $company, FW_CRM_Relations::ids( 'contact', $id, 'company' ), true ) ) {
				$primary = ! $existing || ! (int) $existing->company_id;
				FW_CRM_Contacts::add_company( $id, (int) $company, $primary );
			}
		}

		return array( 'id' => (int) $id, 'created' => ! $existing, 'kept' => $kept );
	}

	/**
	 * @param string $name
	 *
	 * @return int
	 */
	private static function company_by_name( $name ) {
		global $wpdb;
		$t = FW_CRM_Companies::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE name = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * The submission as HTML for the timeline: every answer, then what was kept.
	 *
	 * @param array $fields The entry-style rows.
	 * @param array $kept   label => [ stored, submitted ]
	 * @param int   $entry_id
	 *
	 * @return string
	 */
	public static function answers_html( array $fields, array $kept = array(), $entry_id = 0 ) {
		$rows = '';
		foreach ( $fields as $f ) {
			if ( in_array( (string) ( $f['type'] ?? '' ), array( 'notice' ), true ) ) {
				continue;
			}
			$v = $f['value'];
			if ( 'consent' === ( $f['type'] ?? '' ) ) {
				$v = 'yes' === $v ? __( 'Yes', 'fw' ) : __( 'No', 'fw' );
			}
			$v = is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v;
			if ( '' === trim( $v ) ) {
				continue;
			}
			$rows .= '<dt>' . esc_html( (string) $f['label'] ) . '</dt><dd>' . nl2br( esc_html( $v ) ) . '</dd>';
		}
		$html = '' !== $rows ? '<dl class="fw-crm-answers">' . $rows . '</dl>' : '';
		if ( $kept ) {
			$bits = array();
			foreach ( $kept as $label => $pair ) {
				$bits[] = sprintf(
					/* translators: 1: field, 2: value kept, 3: value in the form */
					__( '%1$s: kept "%2$s", the form said "%3$s"', 'fw' ),
					$label,
					$pair[0],
					$pair[1]
				);
			}
			$html .= '<p class="fw-crm-answers__kept">' . esc_html( implode( '; ', $bits ) ) . '.</p>';
		}
		if ( $entry_id && class_exists( 'FW_Forms_Entries' ) ) {
			// Forms loads its entries screen class in the admin only; a visitor's submission
			// is handled on the front end, so the link is built the same way it builds it.
			$url   = class_exists( 'FW_Forms_Entries_Admin' ) ? FW_Forms_Entries_Admin::entry_url( (int) $entry_id ) : add_query_arg( 'entry', (int) $entry_id, admin_url( 'admin.php?page=fw-form-entries' ) );
			$html .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the form entry', 'fw' ) . '</a></p>';
		}
		return $html;
	}

	/**
	 * Data for the mapping table in the form's Settings tab (form-map.js).
	 *
	 * @return array
	 */
	public static function js_data() {
		$custom = array();
		foreach ( self::custom_fields() as $key => $def ) {
			$custom[] = array( 'key' => $key, 'label' => $def['label'] );
		}
		$targets = array();
		foreach ( self::contact_targets() as $k => $label ) {
			$targets[] = array( 'key' => $k, 'label' => $label );
		}
		return array(
			'targets' => $targets,
			'custom'  => $custom,
			'skip'    => self::SKIP_TYPES,
			'deal'    => FW_CRM_Modules::enabled( 'pipeline' ),
			'i18n'    => array(
				'field'      => __( 'Form field', 'fw' ),
				'saveTo'     => __( 'Save the answer to', 'fw' ),
				'timeline'   => __( 'Only on the timeline', 'fw' ),
				'contact'    => __( 'Contact', 'fw' ),
				'customs'    => __( 'Custom fields', 'fw' ),
				'newField'   => __( '+ New custom field "%s"', 'fw' ),
				'email'      => __( 'Identifies the contact', 'fw' ),
				'consent'    => __( 'Newsletter consent (see below)', 'fw' ),
				'consentAny' => __( 'Consent, kept on the timeline', 'fw' ),
				'noFields'   => __( 'Add fields on the Form Fields tab first.', 'fw' ),
				'noEmail'    => __( 'This form has no Email field yet. Add one: the CRM finds and creates contacts by email address.', 'fw' ),
				'dealTitle'  => __( 'Deal name from', 'fw' ),
				'taskTitle'  => __( 'Task name from', 'fw' ),
				'date'       => __( 'Date from', 'fw' ),
				'formTitle'  => __( 'The form title', 'fw' ),
				'noDate'     => __( 'No date', 'fw' ),
				'note'       => __( 'Every answer is also kept in full on the contact\'s timeline and under Form Entries.', 'fw' ),
			),
		);
	}
}
