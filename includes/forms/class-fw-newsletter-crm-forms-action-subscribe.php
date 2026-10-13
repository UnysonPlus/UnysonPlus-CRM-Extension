<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Forms action: subscribe the submitter to a list — with consent, or not at all.
 */
class FW_Newsletter_CRM_Forms_Action_Subscribe extends FW_Forms_Action {

	public function get_id() {
		return 'subscribe';
	}

	public function get_title() {
		return __( 'Subscribe to the newsletter', 'fw' );
	}

	public function get_options() {
		$lists   = array( '' => __( '— Default list —', 'fw' ) );
		$default = FW_Newsletter_CRM_Service::setting( 'default_list', '' );

		foreach ( FW_Newsletter_CRM_Lists::all( 'list' ) as $l ) {
			$lists[ $l->slug ] = $l->title;
		}

		return array(
			'action_subscribe'      => $this->switch_option(
				__( 'Add the sender to the newsletter when they opt in', 'fw' ),
				__( 'Runs ONLY when the form has a Consent field set to "Newsletter" and the sender ticked it. An email field alone never subscribes anyone. Double opt-in applies if it is on, so the confirmation email is the consent record.', 'fw' )
			),
			'action_subscribe_list' => array(
				'type'    => 'select',
				'label'   => __( 'List', 'fw' ),
				'desc'    => '' !== $default
					? sprintf( __( 'Default list is "%s" (Newsletter → Settings).', 'fw' ), $default )
					: __( 'Leave on Default to use the newsletter default list.', 'fw' ),
				'value'   => '',
				'choices' => $lists,
			),
			'action_subscribe_tags' => array(
				'type'  => 'text',
				'label' => __( 'Tags', 'fw' ),
				'desc'  => __( 'Comma-separated, e.g. booked, event-2026. Tags are created if they do not exist, so a form can mark where a subscriber came from.', 'fw' ),
				'value' => '',
			),
			'action_subscribe_name' => array(
				'type'  => 'text',
				'label' => __( 'Name field', 'fw' ),
				'desc'  => __( 'Optional: the field label that holds the name (e.g. "Your name"). Left empty, the first text field is used.', 'fw' ),
				'value' => '',
			),
		);
	}

	public function run( array $ctx ) {
		$fields = $ctx['fields'];

		// The gate. Consent is a TYPE, checked by Forms' own helper.
		if ( ! class_exists( 'FW_Option_Type_Form_Builder_Item_Consent' )
			|| ! FW_Option_Type_Form_Builder_Item_Consent::newsletter_consent_given( $fields ) ) {
			return; // Not an error: the person simply did not opt in.
		}

		$email = $ctx['email'];

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'fw_crm_forms_no_email', __( 'Consent was given but the form has no email field.', 'fw' ) );
		}

		$form = $ctx['form'];
		$tags = array_filter( array_map( 'trim', explode( ',', (string) self::opt( $form, 'action_subscribe_tags', '' ) ) ) );

		// The consent statement is the record of what they agreed to.
		$consent_text = '';
		foreach ( $fields as $f ) {
			if ( 'consent' === $f['type'] && 'newsletter' === ( $f['purpose'] ?? '' ) && 'yes' === $f['value'] ) {
				$consent_text = $f['label'];
				break;
			}
		}

		$args = array(
			'name'         => $this->name_from( $fields, (string) self::opt( $form, 'action_subscribe_name', '' ) ),
			'tags'         => $tags,
			'source'       => 'form',
			// Scheme and host only: REQUEST_URI already includes a subfolder install's folder.
			'source_url'   => isset( $_SERVER['REQUEST_URI'] ) ? preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() ) . wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
			'consent_text' => $consent_text,
			'meta'         => array( 'form_id' => $ctx['form_id'], 'entry_id' => (int) $ctx['entry_id'] ),
		);

		$list = (string) self::opt( $form, 'action_subscribe_list', '' );

		if ( '' !== $list ) {
			$args['list'] = $list;
		}

		$result = FW_Newsletter_CRM_Service::subscribe( $email, $args );

		return is_wp_error( $result ) ? $result : null;
	}

	/**
	 * @param array  $fields
	 * @param string $label Preferred field label, or ''.
	 *
	 * @return string
	 */
	private function name_from( array $fields, $label ) {
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
