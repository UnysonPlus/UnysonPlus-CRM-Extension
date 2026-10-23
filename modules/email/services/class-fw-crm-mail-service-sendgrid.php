<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/** SendGrid: v3 Mail Send API; events from its Event Webhook. */
class FW_CRM_Mail_Service_SendGrid extends FW_CRM_Mail_Service {

	public function id() {
		return 'sendgrid';
	}

	public function label() {
		return 'SendGrid';
	}

	public function fields() {
		return array(
			'api_key' => array( 'label' => __( 'API key', 'fw' ), 'type' => 'password', 'desc' => __( 'A key with Mail Send access.', 'fw' ) ),
		);
	}

	public function send( array $m ) {
		$p = array( 'to' => array_map( array( __CLASS__, 'person' ), $m['to'] ) );
		if ( $m['cc'] ) {
			$p['cc'] = array_map( array( __CLASS__, 'person' ), $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$p['bcc'] = array_map( array( __CLASS__, 'person' ), $m['bcc'] );
		}
		$body = array(
			'personalizations' => array( $p ),
			'from'             => array_filter( array( 'email' => $m['from_email'], 'name' => $m['from_name'] ) ),
			'subject'          => $m['subject'],
			'content'          => array_values( array_filter( array(
				'' !== $m['text'] ? array( 'type' => 'text/plain', 'value' => $m['text'] ) : null,
				array( 'type' => 'text/html', 'value' => $m['html'] ),
			) ) ),
		);
		if ( '' !== $m['reply_to'] ) {
			$body['reply_to'] = self::person( $m['reply_to'] );
		}
		if ( $m['headers'] ) {
			$body['headers'] = (object) $m['headers'];
		}
		foreach ( $m['attachments'] as $path ) {
			$a = self::attachment( $path );
			if ( $a ) {
				$body['attachments'][] = array( 'content' => $a['content'], 'filename' => $a['name'], 'type' => $a['type'] );
			}
		}
		$res = $this->post_json( 'https://api.sendgrid.com/v3/mail/send', $body, array( 'Authorization' => 'Bearer ' . $this->settings()['api_key'] ) );
		return is_wp_error( $res ) ? $res : true;
	}

	public function parse_events( $request ) {
		$map = array( 'bounce' => 'bounce', 'dropped' => 'bounce', 'spamreport' => 'complaint', 'unsubscribe' => 'unsubscribe', 'group_unsubscribe' => 'unsubscribe', 'deferred' => 'soft_bounce' );
		$out = array();
		foreach ( (array) $request->get_json_params() as $e ) {
			$event = (string) ( $e['event'] ?? '' );
			if ( ! isset( $map[ $event ] ) ) {
				continue;
			}
			$out[] = array(
				'type'       => 'bounce' === $event && 'blocked' === ( $e['type'] ?? '' ) ? 'soft_bounce' : $map[ $event ],
				'email'      => (string) ( $e['email'] ?? '' ),
				'message_id' => (string) ( $e['smtp-id'] ?? '' ),
				'detail'     => (string) ( $e['reason'] ?? $event ),
			);
		}
		return $out;
	}

	/** @param string $addr */
	private static function person( $addr ) {
		return array_filter( self::split_address( $addr ) );
	}
}
