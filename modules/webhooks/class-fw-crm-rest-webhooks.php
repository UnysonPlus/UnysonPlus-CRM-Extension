<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST: manage webhooks, on `fw-crm/v1` (CRM managers only).
 *
 *   /webhooks                       GET list, POST create { name, url, events[], active }
 *   /webhooks/{id}                  GET, PUT/PATCH, DELETE
 *   /webhooks/{id}/test             POST send a ping now
 *   /webhooks/{id}/secret           POST make a new signing secret
 *   /webhooks/{id}/deliveries       GET the delivery log (?status=, page, per_page)
 *   /webhook-deliveries/{id}/retry  POST send a delivery again now
 *   /webhooks/events                GET the events an endpoint can subscribe to
 */
class FW_CRM_REST_Webhooks extends FW_CRM_REST {

	/**
	 * @internal
	 */
	public function _register_routes() {
		$auth = array( $this, '_authorize_manager' );
		$id   = '(?P<id>\d+)';

		register_rest_route( self::NAMESPACE_, '/webhooks', array(
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return new WP_REST_Response( array_map( array( $this, 'hook' ), FW_CRM_Webhooks::all() ) );
				},
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( self::NAMESPACE_, '/webhooks/events', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return new WP_REST_Response( FW_CRM_Webhooks::events() );
			},
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/webhooks/' . $id, array(
			array(
				'methods'             => 'GET',
				'callback'            => function ( $request ) {
					$h = FW_CRM_Webhooks::find( (int) $request['id'] );
					return $h ? new WP_REST_Response( $this->hook( $h, true ) ) : $this->not_found( __( 'Webhook', 'fw' ) );
				},
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, '_update' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => function ( $request ) {
					if ( ! FW_CRM_Webhooks::find( (int) $request['id'] ) ) {
						return $this->not_found( __( 'Webhook', 'fw' ) );
					}
					FW_CRM_Webhooks::delete( (int) $request['id'] );
					return new WP_REST_Response( array( 'deleted' => true, 'id' => (int) $request['id'] ) );
				},
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( self::NAMESPACE_, '/webhooks/' . $id . '/test', array(
			'methods'             => 'POST',
			'callback'            => function ( $request ) {
				$r = FW_CRM_Webhooks::test( (int) $request['id'] );
				return is_wp_error( $r ) ? $this->fail( $r, 404 ) : new WP_REST_Response( $this->result( $r ) );
			},
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/webhooks/' . $id . '/secret', array(
			'methods'             => 'POST',
			'callback'            => function ( $request ) {
				if ( ! FW_CRM_Webhooks::find( (int) $request['id'] ) ) {
					return $this->not_found( __( 'Webhook', 'fw' ) );
				}
				return new WP_REST_Response( array( 'secret' => FW_CRM_Webhooks::rotate_secret( (int) $request['id'] ) ) );
			},
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/webhooks/' . $id . '/deliveries', array(
			'methods'             => 'GET',
			'callback'            => function ( $request ) {
				if ( ! FW_CRM_Webhooks::find( (int) $request['id'] ) ) {
					return $this->not_found( __( 'Webhook', 'fw' ) );
				}
				$per = max( 1, min( 200, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
				list( $rows, $total ) = FW_CRM_Webhooks::deliveries( array(
					'webhook_id' => (int) $request['id'],
					'status'     => sanitize_key( (string) $request->get_param( 'status' ) ),
					'per_page'   => $per,
					'paged'      => max( 1, (int) $request->get_param( 'page' ) ),
				) );
				return $this->collection( array_map( array( $this, 'delivery' ), $rows ), $total, $per );
			},
			'permission_callback' => $auth,
			'args'                => array(
				'status'   => array( 'type' => 'string', 'enum' => array( '', 'pending', 'sending', 'sent', 'failed' ) ),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/webhook-deliveries/' . $id . '/retry', array(
			'methods'             => 'POST',
			'callback'            => function ( $request ) {
				$r = FW_CRM_Webhooks::retry( (int) $request['id'] );
				return is_wp_error( $r ) ? $this->fail( $r, 400 ) : new WP_REST_Response( $this->result( $r ) );
			},
			'permission_callback' => $auth,
		) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function _create( $request ) {
		$id = FW_CRM_Webhooks::save( $this->body( $request ) );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id );
		}
		$response = new WP_REST_Response( $this->hook( FW_CRM_Webhooks::find( $id ), true ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function _update( $request ) {
		if ( ! FW_CRM_Webhooks::find( (int) $request['id'] ) ) {
			return $this->not_found( __( 'Webhook', 'fw' ) );
		}
		$id = FW_CRM_Webhooks::save( $this->body( $request ), (int) $request['id'] );
		return is_wp_error( $id ) ? $this->fail( $id ) : new WP_REST_Response( $this->hook( FW_CRM_Webhooks::find( $id ), true ) );
	}

	/**
	 * An endpoint as the API returns it. The secret only on a single read.
	 *
	 * @param object $h
	 * @param bool   $secret
	 *
	 * @return array
	 */
	public function hook( $h, $secret = false ) {
		$out = array(
			'id'               => (int) $h->id,
			'name'             => (string) $h->name,
			'url'              => (string) $h->url,
			'events'           => array_values( (array) $h->events ),
			'active'           => (bool) (int) $h->active,
			'failures'         => (int) $h->failures,
			'last_status'      => (int) $h->last_status,
			'last_delivery_at' => $h->last_delivery_at,
			'created_at'       => $h->created_at,
			'stats_7d'         => FW_CRM_Webhooks::stats( (int) $h->id ),
		);
		if ( $secret ) {
			$out['secret'] = (string) $h->secret;
		}
		return $out;
	}

	/**
	 * @param object $r
	 *
	 * @return array
	 */
	public function delivery( $r ) {
		$payload = json_decode( (string) $r->payload, true );
		return array(
			'id'              => (int) $r->id,
			'webhook_id'      => (int) $r->webhook_id,
			'uuid'            => (string) $r->uuid,
			'event'           => (string) $r->event,
			'status'          => (string) $r->status,
			'attempts'        => (int) $r->attempts,
			'response_code'   => (int) $r->response_code,
			'response_body'   => (string) $r->response_body,
			'duration_ms'     => (int) $r->duration_ms,
			'next_attempt_at' => $r->next_attempt_at,
			'created_at'      => $r->created_at,
			'delivered_at'    => $r->delivered_at,
			'payload'         => is_array( $payload ) ? $payload : null,
		);
	}

	/**
	 * @param array $r
	 *
	 * @return array
	 */
	private function result( array $r ) {
		return array(
			'ok'            => (bool) $r['ok'],
			'response_code' => (int) $r['code'],
			'error'         => (string) $r['error'],
			'duration_ms'   => (int) ( $r['ms'] ?? 0 ),
			'delivery_id'   => (int) ( $r['delivery_id'] ?? 0 ),
		);
	}
}
