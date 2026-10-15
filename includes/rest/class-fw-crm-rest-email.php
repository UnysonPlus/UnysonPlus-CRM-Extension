<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST: send a one-to-one email to a contact, on `fw-crm/v1`.
 *
 *   /contacts/{id}/email  POST { subject, body, cc?, deal_id? }
 *   /inbound/{token}      POST an incoming email (public; the token is the secret)
 */
class FW_CRM_REST_Email extends FW_CRM_REST {

	/**
	 * @internal
	 */
	public function _register_routes() {
		if ( class_exists( 'FW_CRM_Direct_Email' ) ) {
			register_rest_route( self::NAMESPACE_, '/contacts/(?P<id>\d+)/email', array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_send' ),
				'permission_callback' => array( $this, '_authorize' ),
				'args'                => array(
					'subject' => array( 'type' => 'string', 'required' => true ),
					'body'    => array( 'type' => 'string', 'required' => true ),
					'cc'      => array( 'type' => 'string', 'default' => '' ),
					'deal_id' => array( 'type' => 'integer', 'default' => 0 ),
				),
			) );
		}

		// The inbound address. Public by design: whoever knows the token can post mail
		// to it, which is what an email service's inbound forwarding needs.
		register_rest_route( self::NAMESPACE_, '/inbound/(?P<token>[A-Za-z0-9]{20,64})', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_inbound' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** @internal */
	public function _inbound( $request ) {
		if ( ! hash_equals( FW_CRM_Inbound_Email::token(), (string) $request['token'] ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'Unknown inbound address.', 'fw' ), array( 'status' => 403 ) );
		}
		$m = FW_CRM_Inbound_Email::from_request( $request );
		if ( ! $m ) {
			return new WP_Error( 'fw_crm_bad_message', __( 'No email found in the request.', 'fw' ), array( 'status' => 400 ) );
		}
		// Answer 200 for every message read, even ones that matched nobody, so the
		// service does not keep retrying.
		return new WP_REST_Response( FW_CRM_Inbound_Email::receive( $m, 'inbound' ) );
	}

	/** @internal */
	public function _send( $request ) {
		$id   = (int) $request['id'];
		$deal = (int) $request->get_param( 'deal_id' );
		if ( ! $this->exists( 'contact', $id ) ) {
			return $this->not_found( $this->label( 'contact' ) );
		}
		// Allowed for the contact's owner, or the deal's when written from a deal the contact is on.
		$via_deal = $deal && class_exists( 'FW_CRM_Deals' ) && FW_CRM_Capabilities::can_edit_deal( $deal ) && isset( FW_CRM_Direct_Email::deal_recipients( $deal )[ $id ] );
		if ( ! $via_deal && ( $no = $this->forbid( 'contact', $id ) ) ) {
			return $no;
		}
		$result = FW_CRM_Direct_Email::send( $id, array(
			'subject' => (string) $request->get_param( 'subject' ),
			'body'    => sanitize_textarea_field( (string) $request->get_param( 'body' ) ),
			'cc'      => (string) $request->get_param( 'cc' ),
			'deal_id' => $via_deal ? $deal : 0,
		) );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 'fw_crm_send_failed' === $result->get_error_code() ? 502 : 400 );
		}
		return new WP_REST_Response( array( 'sent' => true ), 201 );
	}
}
