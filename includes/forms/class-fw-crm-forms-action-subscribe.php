<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Forms action "Add to the CRM". Two independent switches:
 *
 * - Add the sender to the CRM: every submission with an email address creates or
 *   updates the contact (status "none": in the CRM, not subscribed), saves the
 *   answers where the map says (FW_CRM_Form_Map), puts the whole submission on
 *   the timeline and can create a deal or a task from it.
 * - Subscribe them to the newsletter: only when the form has a Consent field set
 *   to "Newsletter" and the sender ticked it. Double opt-in applies.
 *
 * The id stays "subscribe" so forms saved before 1.0.68 keep their newsletter switch.
 */
class FW_CRM_Forms_Action_Subscribe extends FW_Forms_Action {

	public function get_id() {
		return 'subscribe';
	}

	public function get_title() {
		return __( 'Add to the CRM', 'fw' );
	}

	/**
	 * On when either switch is.
	 *
	 * @param array $form
	 *
	 * @return bool
	 */
	public function is_enabled( array $form ) {
		return 'yes' === self::opt( $form, 'action_crm_contact', 'no' ) || 'yes' === self::opt( $form, 'action_subscribe', 'no' );
	}

	public function get_options() {
		$lists   = array( '' => __( '— Default list —', 'fw' ) );
		$default = FW_CRM_Service::setting( 'default_list', '' );
		foreach ( FW_CRM_Lists::all( 'list' ) as $l ) {
			$lists[ $l->slug ] = $l->title;
		}

		$create = array( '' => __( 'Nothing', 'fw' ) );
		if ( class_exists( 'FW_CRM_Deals' ) ) {
			$create['deal'] = __( 'A deal in the sales pipeline', 'fw' );
		}
		$create['task'] = __( 'A task, call or meeting', 'fw' );
		if ( class_exists( 'FW_CRM_Cases' ) ) {
			$create['case'] = __( 'A support case', 'fw' );
		}

		$options = array(
			'action_crm_contact' => $this->switch_option(
				__( 'Add the sender to the CRM', 'fw' ),
				__( 'Every submission with an email address creates the contact, or updates them if they are already in the CRM. The answers go where you choose below, and the whole submission goes on their timeline. This never subscribes anyone to email.', 'fw' )
			),
			'action_crm_map'     => array(
				'type'  => 'html',
				'label' => __( 'Save the answers', 'fw' ),
				'value' => '',
				'html'  => '<div class="fw-crm-form-map" data-fw-crm-form-map="' . esc_attr( wp_json_encode( FW_CRM_Form_Map::js_data() ) ) . '"></div>',
			),
			'action_crm_update'  => array(
				'type'    => 'select',
				'label'   => __( 'When the contact already exists', 'fw' ),
				'value'   => 'blank',
				'choices' => array(
					'blank'  => __( 'Fill empty fields only (keep what your team typed)', 'fw' ),
					'always' => __( 'Replace their details with the newest answers', 'fw' ),
				),
				'desc'    => __( 'An answer that is not saved because the field already has a value is noted on the timeline.', 'fw' ),
			),
			'action_crm_create'  => array(
				'type'    => 'select',
				'label'   => __( 'Also create', 'fw' ),
				'value'   => '',
				'choices' => $create,
				'desc'    => __( 'For a booking or quote form: each submission becomes a deal to follow up, or a task or meeting on the date the person chose. For a help or support form: a case, with the whole message on it.', 'fw' ),
			),
		);
		if ( class_exists( 'FW_CRM_Pipelines' ) ) {
			$pipes = array();
			foreach ( FW_CRM_Pipelines::all() as $p ) {
				$pipes[ (string) $p->id ] = $p->name;
			}
			$options['action_crm_pipeline'] = array(
				'type'    => 'select',
				'label'   => __( 'Pipeline', 'fw' ),
				'value'   => '',
				'choices' => array( '' => __( '— Default pipeline —', 'fw' ) ) + $pipes,
				'desc'    => __( 'The deal starts in the first stage.', 'fw' ),
			);
		}
		$types = array();
		foreach ( FW_CRM_Tasks::types() as $k => $t ) {
			$types[ $k ] = is_array( $t ) ? ( $t['label'] ?? $k ) : $t;
		}
		$options['action_crm_task_type'] = array(
			'type'    => 'select',
			'label'   => __( 'Task type', 'fw' ),
			'value'   => 'meeting',
			'choices' => $types,
		);

		return $options + array(
			'action_subscribe'      => $this->switch_option(
				__( 'Subscribe them to the newsletter when they tick consent', 'fw' ),
				__( 'Runs ONLY when the form has a Consent field set to "Newsletter" and the sender ticked it. An email field alone never subscribes anyone. Double opt-in applies if it is on, so the confirmation email is the consent record.', 'fw' )
			),
			'action_subscribe_list' => array(
				'type'    => 'select',
				'label'   => __( 'Newsletter list', 'fw' ),
				'desc'    => '' !== $default
					? sprintf( __( 'Default list is "%s" (CRM → Settings).', 'fw' ), $default )
					: __( 'Leave on Default to use the newsletter default list.', 'fw' ),
				'value'   => '',
				'choices' => $lists,
			),
			'action_subscribe_tags' => array(
				'type'  => 'text',
				'label' => __( 'Tags', 'fw' ),
				'desc'  => __( 'Comma-separated, e.g. booked, event-2026. Added to the contact, so you can see which form they came from. Tags are created if they do not exist.', 'fw' ),
				'value' => '',
			),
		);
	}

	public function run( array $ctx ) {
		$form    = $ctx['form'];
		$fields  = $ctx['fields'];
		$email   = (string) $ctx['email'];
		$crm     = 'yes' === self::opt( $form, 'action_crm_contact', 'no' );
		$nl      = 'yes' === self::opt( $form, 'action_subscribe', 'no' );
		$consent = class_exists( 'FW_Option_Type_Form_Builder_Item_Consent' ) && FW_Option_Type_Form_Builder_Item_Consent::newsletter_consent_given( $fields );
		$tags    = array_filter( array_map( 'trim', explode( ',', (string) self::opt( $form, 'action_subscribe_tags', '' ) ) ) );

		if ( ! is_email( $email ) ) {
			if ( $crm || ( $nl && $consent ) ) {
				return new WP_Error( 'fw_crm_forms_no_email', __( 'The form has no email address to find or create the contact by.', 'fw' ) );
			}
			return null;
		}

		if ( ! (int) $ctx['entry_id'] ) {
			$ctx['entry_id'] = FW_CRM_Activity::pending_entry_id();
		}
		$items = (array) ( $ctx['payload']['shortcode_to_item'] ?? array() );
		$map   = FW_CRM_Form_Map::resolve( $form, $items );
		$name  = $this->name_from( $fields, $map, (string) self::opt( $form, 'action_subscribe_name', '' ) );

		$result = null;
		if ( $crm ) {
			$result = FW_CRM_Form_Map::apply( $email, $ctx, $map, 'always' !== self::opt( $form, 'action_crm_update', 'blank' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $nl && $consent ) {
			$consent_text = '';
			foreach ( $fields as $f ) {
				if ( 'consent' === $f['type'] && 'newsletter' === ( $f['purpose'] ?? '' ) && 'yes' === $f['value'] ) {
					$consent_text = $f['label'];
					break;
				}
			}
			$args = array(
				'name'         => $name,
				'tags'         => $tags,
				'source'       => 'form',
				'source_url'   => isset( $_SERVER['REQUEST_URI'] ) ? preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
				'consent_text' => $consent_text,
				'meta'         => array( 'form_id' => $ctx['form_id'], 'entry_id' => (int) $ctx['entry_id'] ),
			);
			$list = (string) self::opt( $form, 'action_subscribe_list', '' );
			if ( '' !== $list ) {
				$args['list'] = $list;
			}
			$sub = FW_CRM_Service::subscribe( $email, $args );
			if ( is_wp_error( $sub ) && ! $crm ) {
				return $sub;
			}
		} elseif ( $crm && $tags ) {
			FW_CRM_Tags::add( $result['id'], $tags, 'form' );
		}

		if ( ! $crm ) {
			return null;
		}

		$contact_id = (int) $result['id'];
		$title      = (string) apply_filters( 'fw_ext_forms_entry_form_title', (string) $ctx['form_id'], $ctx['payload'] );
		$title      = '' !== $title ? $title : __( 'a form', 'fw' );

		// The whole submission on the timeline. FW_CRM_Activity::_after_form() skips its
		// shorter entry for this person, so it is logged once.
		FW_CRM_Activity::log( 'contact', $contact_id, 'form', sprintf( __( 'Sent the form "%s"', 'fw' ), $title ), array(
			'body'     => FW_CRM_Form_Map::answers_html( $fields, $result['kept'], (int) $ctx['entry_id'] ),
			'meta'     => array( 'form_id' => (string) $ctx['form_id'], 'entry_id' => (int) $ctx['entry_id'], 'created' => $result['created'] ? 1 : 0 ),
			'actor_id' => 0,
		) );
		FW_CRM_Activity::$form_logged[ strtolower( $email ) ] = true;

		$this->create_record( $form, $ctx, $contact_id, $title, $name );

		/** Fires after a form submission reached the CRM: ( $contact_id, $ctx, $created ). */
		do_action( 'unysonplus_crm_form_submitted', $contact_id, $ctx, $result['created'] );
		do_action( 'unysonplus_crm_automation_event', 'crm.form_submitted', $contact_id, array(
			'form_id'    => (string) $ctx['form_id'],
			'form_title' => $title,
			'dedupe'     => 'form:' . ( (int) $ctx['entry_id'] ? (int) $ctx['entry_id'] : md5( $email . microtime() ) ),
		) );

		return null;
	}

	/**
	 * A deal or a task from the submission, as the form asks.
	 *
	 * @param array  $form
	 * @param array  $ctx
	 * @param int    $contact_id
	 * @param string $form_title
	 * @param string $name
	 */
	private function create_record( array $form, array $ctx, $contact_id, $form_title, $name ) {
		$kind = (string) self::opt( $form, 'action_crm_create', '' );
		if ( '' === $kind ) {
			return;
		}
		$stored = FW_CRM_Form_Map::stored( $form );
		$values = array();
		$types  = array();
		foreach ( (array) $ctx['fields'] as $f ) {
			$values[ (string) $f['id'] ] = is_array( $f['value'] ) ? implode( ', ', array_map( 'strval', $f['value'] ) ) : trim( (string) $f['value'] );
			$types[ (string) $f['id'] ]  = (string) $f['type'];
		}
		// Defaults: the first dropdown or single choice names it, the first date dates it.
		$title_field = $stored['deal_title'];
		$date_field  = $stored['date'];
		if ( '' === $title_field ) {
			foreach ( $types as $id => $t ) {
				if ( in_array( $t, array( 'select', 'radio' ), true ) ) {
					$title_field = $id;
					break;
				}
			}
		}
		if ( '' === $date_field ) {
			$date_field = array_search( 'date', $types, true );
			$date_field = false === $date_field ? '' : $date_field;
		}
		$what  = '-' !== $title_field && '' !== ( $values[ $title_field ] ?? '' ) ? $values[ $title_field ] : $form_title;
		$who   = '' !== $name ? $name : (string) $ctx['email'];
		$label = sprintf( '%1$s: %2$s', $what, $who );
		$date  = '-' !== $date_field ? $this->date_value( $values[ $date_field ] ?? '' ) : '';
		$notes = wp_strip_all_tags( str_replace( array( '</dd>', '</dt>' ), array( "\n", ': ' ), FW_CRM_Form_Map::answers_html( $ctx['fields'] ) ) );

		if ( 'deal' === $kind && class_exists( 'FW_CRM_Deals' ) ) {
			$contact = FW_CRM_Subscribers::find( $contact_id );
			$deal    = FW_CRM_Deals::create( array(
				'title'          => $label,
				'pipeline_id'    => (int) self::opt( $form, 'action_crm_pipeline', 0 ),
				'contact_id'     => $contact_id,
				'company_id'     => $contact ? (int) $contact->company_id : 0,
				'owner_id'       => $contact ? (int) $contact->owner_id : 0,
				'expected_close' => '' !== $date ? substr( $date, 0, 10 ) : null,
			) );
			if ( is_wp_error( $deal ) ) {
				return;
			}
			// A chosen package that matches a product (the CRM's or a shop product) becomes the deal's line and value.
			if ( class_exists( 'FW_CRM_Products' ) && '' !== ( $values[ $title_field ] ?? '' ) ) {
				foreach ( FW_CRM_Products::catalogue( $values[ $title_field ], 20 ) as $p ) {
					if ( 0 === strcasecmp( trim( $p['name'] ), $values[ $title_field ] ) ) {
						FW_CRM_Products::add_item( (int) $deal, array( 'product' => $p['key'] ) );
						break;
					}
				}
			}
			FW_CRM_Activity::log( 'deal', (int) $deal, 'form', sprintf( __( 'Created from the form "%s"', 'fw' ), $form_title ), array(
				'body'     => FW_CRM_Form_Map::answers_html( $ctx['fields'], array(), (int) $ctx['entry_id'] ),
				'actor_id' => 0,
			) );
		} elseif ( 'case' === $kind && class_exists( 'FW_CRM_Cases_Intake' ) ) {
			FW_CRM_Cases_Intake::from_form( $contact_id, $form_title, $ctx, $values, $types );
		} elseif ( 'task' === $kind ) {
			$contact = FW_CRM_Subscribers::find( $contact_id );
			$type    = (string) self::opt( $form, 'action_crm_task_type', 'meeting' );
			FW_CRM_Tasks::create( array(
				'title'       => $label,
				'type'        => isset( FW_CRM_Tasks::types()[ $type ] ) ? $type : 'todo',
				'object_type' => 'contact',
				'object_id'   => $contact_id,
				'owner_id'    => $contact ? (int) $contact->owner_id : 0,
				'due_at'      => '' !== $date ? $date : null,
				'notes'       => trim( $notes ),
			) );
		}
	}

	/**
	 * A form date or date-time as the CRM stores it, or ''.
	 *
	 * @param string $v
	 *
	 * @return string
	 */
	private function date_value( $v ) {
		$v = trim( str_replace( 'T', ' ', (string) $v ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?/', $v, $m ) ) {
			return $m[0];
		}
		$t = strtotime( $v );
		return $t ? gmdate( 'Y-m-d', $t ) : '';
	}

	/**
	 * The sender's name: the mapped name fields, then the old "Name field" setting,
	 * then the first text answer.
	 *
	 * @param array  $fields
	 * @param array  $map
	 * @param string $label
	 *
	 * @return string
	 */
	private function name_from( array $fields, array $map, $label ) {
		$first = '';
		$last  = '';
		foreach ( $fields as $f ) {
			$t = $map[ (string) ( $f['id'] ?? '' ) ] ?? '';
			if ( ! is_string( $f['value'] ) ) {
				continue;
			}
			if ( 'name' === $t ) {
				return trim( $f['value'] );
			}
			if ( 'first_name' === $t ) {
				$first = trim( $f['value'] );
			} elseif ( 'last_name' === $t ) {
				$last = trim( $f['value'] );
			}
		}
		if ( '' !== $first . $last ) {
			return trim( $first . ' ' . $last );
		}
		if ( '' !== $label ) {
			foreach ( $fields as $f ) {
				if ( 0 === strcasecmp( trim( $f['label'] ), trim( $label ) ) && is_string( $f['value'] ) ) {
					return $f['value'];
				}
			}
		}
		foreach ( $fields as $f ) {
			if ( 'text' === $f['type'] && is_string( $f['value'] ) && '' !== trim( $f['value'] ) ) {
				return $f['value'];
			}
		}
		return '';
	}
}
