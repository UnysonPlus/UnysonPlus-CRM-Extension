<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/** Brevo: the transactional email API; events from its transactional webhook. */
class FW_CRM_Mail_Service_Brevo extends FW_CRM_Mail_Service {

	public function id() {
		return 'brevo';
	}

	public function label() {
		return 'Brevo';
	}

	public function fields() {
		return array(
			'api_key' => array( 'label' => __( 'API key', 'fw' ), 'type' => 'password' ),
		);
	}

	public function send( array $m ) {
		$body = array(
			'sender'      => array_filter( array( 'email' => $m['from_email'], 'name' => $m['from_name'] ) ),
			'to'          => self::people( $m['to'] ),
			'subject'     => $m['subject'],
			'htmlContent' => $m['html'],
		);
		if ( '' !== $m['text'] ) {
			$body['textContent'] = $m['text'];
		}
		if ( $m['cc'] ) {
			$body['cc'] = self::people( $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$body['bcc'] = self::people( $m['bcc'] );
		}
		if ( '' !== $m['reply_to'] ) {
			$body['replyTo'] = array_filter( self::split_address( $m['reply_to'] ) );
		}
		if ( $m['headers'] ) {
			$body['headers'] = (object) $m['headers'];
		}
		foreach ( $m['attachments'] as $path ) {
			$a = self::attachment( $path );
			if ( $a ) {
				$body['attachment'][] = array( 'content' => $a['content'], 'name' => $a['name'] );
			}
		}
		$res = $this->post_json( 'https://api.brevo.com/v3/smtp/email', $body, array( 'api-key' => $this->settings()['api_key'] ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * @param string[] $list
	 *
	 * @return array[]
	 */
	private static function people( array $list ) {
		return array_map( function ( $a ) {
			return array_filter( self::split_address( $a ) );
		}, $list );
	}

	public function parse_events( $request ) {
		$e   = (array) $request->get_json_params();
		$map = array( 'hard_bounce' => 'bounce', 'invalid_email' => 'bounce', 'soft_bounce' => 'soft_bounce', 'blocked' => 'soft_bounce', 'spam' => 'complaint', 'complaint' => 'complaint', 'unsubscribed' => 'unsubscribe' );
		$ev  = (string) ( $e['event'] ?? '' );
		if ( ! isset( $map[ $ev ] ) ) {
			return array();
		}
		return array( array(
			'type'       => $map[ $ev ],
			'email'      => (string) ( $e['email'] ?? '' ),
			'message_id' => (string) ( $e['message-id'] ?? '' ),
			'detail'     => (string) ( $e['reason'] ?? $ev ),
		) );
	}
}
