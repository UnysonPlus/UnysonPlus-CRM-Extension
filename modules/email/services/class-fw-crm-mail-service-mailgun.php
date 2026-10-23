<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/** Mailgun: the Messages API (US or EU region); events from its webhooks, signature checked. */
class FW_CRM_Mail_Service_Mailgun extends FW_CRM_Mail_Service {

	public function id() {
		return 'mailgun';
	}

	public function label() {
		return 'Mailgun';
	}

	public function fields() {
		return array(
			'api_key'     => array( 'label' => __( 'API key', 'fw' ), 'type' => 'password' ),
			'domain'      => array( 'label' => __( 'Sending domain', 'fw' ), 'type' => 'text', 'desc' => __( 'For example mg.example.com', 'fw' ) ),
			'region'      => array( 'label' => __( 'Region', 'fw' ), 'type' => 'select', 'choices' => array( 'us' => __( 'US', 'fw' ), 'eu' => __( 'EU', 'fw' ) ) ),
			'signing_key' => array( 'label' => __( 'Webhook signing key', 'fw' ), 'type' => 'password', 'optional' => true, 'desc' => __( 'Optional. With it, events that were not signed by Mailgun are refused.', 'fw' ) ),
		);
	}

	/** Attachments need a multipart upload; the site's own mail sends those instead. */
	public function supports_attachments() {
		return false;
	}

	public function send( array $m ) {
		$s    = $this->settings();
		$host = 'eu' === ( $s['region'] ?? 'us' ) ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$body = array(
			'from'    => self::from_header( $m ),
			'to'      => implode( ',', $m['to'] ),
			'subject' => $m['subject'],
			'html'    => $m['html'],
		);
		if ( '' !== $m['text'] ) {
			$body['text'] = $m['text'];
		}
		if ( $m['cc'] ) {
			$body['cc'] = implode( ',', $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$body['bcc'] = implode( ',', $m['bcc'] );
		}
		if ( '' !== $m['reply_to'] ) {
			$body['h:Reply-To'] = $m['reply_to'];
		}
		foreach ( $m['headers'] as $name => $value ) {
			$body[ 'h:' . $name ] = $value;
		}
		$res = wp_remote_post( $host . '/v3/' . rawurlencode( (string) $s['domain'] ) . '/messages', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( 'api:' . $s['api_key'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			'body'    => $body,
		) );
		$res = $this->reply( $res );
		return is_wp_error( $res ) ? $res : true;
	}

	public function parse_events( $request ) {
		$data = (array) $request->get_json_params();
		$sig  = (array) ( $data['signature'] ?? array() );
		$key  = (string) ( $this->settings()['signing_key'] ?? '' );
		if ( '' !== $key && ! hash_equals( hash_hmac( 'sha256', ( $sig['timestamp'] ?? '' ) . ( $sig['token'] ?? '' ), $key ), (string) ( $sig['signature'] ?? '' ) ) ) {
			return array();
		}
		$e     = (array) ( $data['event-data'] ?? array() );
		$event = (string) ( $e['event'] ?? '' );
		$type  = '';
		if ( 'failed' === $event ) {
			$type = 'permanent' === ( $e['severity'] ?? '' ) ? 'bounce' : 'soft_bounce';
		} elseif ( 'complained' === $event ) {
			$type = 'complaint';
		} elseif ( 'unsubscribed' === $event ) {
			$type = 'unsubscribe';
		}
		if ( '' === $type ) {
			return array();
		}
		return array( array(
			'type'       => $type,
			'email'      => (string) ( $e['recipient'] ?? '' ),
			'message_id' => (string) ( $e['message']['headers']['message-id'] ?? '' ),
			'detail'     => (string) ( $e['delivery-status']['description'] ?? ( $e['delivery-status']['message'] ?? $event ) ),
		) );
	}

	/**
	 * @param array $m
	 *
	 * @return string "Name" <address>
	 */
	public static function from_header( array $m ) {
		return '' !== $m['from_name'] ? sprintf( '"%s" <%s>', str_replace( '"', '', $m['from_name'] ), $m['from_email'] ) : $m['from_email'];
	}
}
