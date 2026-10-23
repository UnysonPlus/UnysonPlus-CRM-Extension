<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/** Postmark: the Email API, campaigns on their own message stream; bounce, spam and unsubscribe webhooks. */
class FW_CRM_Mail_Service_Postmark extends FW_CRM_Mail_Service {

	public function id() {
		return 'postmark';
	}

	public function label() {
		return 'Postmark';
	}

	public function fields() {
		return array(
			'token'  => array( 'label' => __( 'Server API token', 'fw' ), 'type' => 'password' ),
			'stream' => array( 'label' => __( 'Message stream for campaigns', 'fw' ), 'type' => 'text', 'optional' => true, 'desc' => __( 'Usually "broadcast". Other email uses the server default (transactional) stream.', 'fw' ) ),
		);
	}

	public function send( array $m ) {
		$from = '' !== $m['from_name'] ? sprintf( '"%s" <%s>', str_replace( '"', '', $m['from_name'] ), $m['from_email'] ) : $m['from_email'];
		$body = array(
			'From'     => $from,
			'To'       => implode( ',', $m['to'] ),
			'Subject'  => $m['subject'],
			'HtmlBody' => $m['html'],
		);
		if ( '' !== $m['text'] ) {
			$body['TextBody'] = $m['text'];
		}
		if ( $m['cc'] ) {
			$body['Cc'] = implode( ',', $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$body['Bcc'] = implode( ',', $m['bcc'] );
		}
		if ( '' !== $m['reply_to'] ) {
			$body['ReplyTo'] = $m['reply_to'];
		}
		foreach ( $m['headers'] as $name => $value ) {
			$body['Headers'][] = array( 'Name' => $name, 'Value' => $value );
		}
		$stream = trim( (string) ( $this->settings()['stream'] ?? '' ) );
		if ( 'marketing' === ( $m['purpose'] ?? '' ) && '' !== $stream ) {
			$body['MessageStream'] = $stream;
		}
		foreach ( $m['attachments'] as $path ) {
			$a = self::attachment( $path );
			if ( $a ) {
				$body['Attachments'][] = array( 'Name' => $a['name'], 'Content' => $a['content'], 'ContentType' => $a['type'] );
			}
		}
		$res = $this->post_json( 'https://api.postmarkapp.com/email', $body, array( 'X-Postmark-Server-Token' => $this->settings()['token'] ) );
		return is_wp_error( $res ) ? $res : true;
	}

	public function parse_events( $request ) {
		$e    = (array) $request->get_json_params();
		$kind = (string) ( $e['RecordType'] ?? '' );
		$type = '';
		if ( 'Bounce' === $kind ) {
			$type = in_array( $e['Type'] ?? '', array( 'HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'Unknown' ), true ) ? 'bounce' : 'soft_bounce';
		} elseif ( 'SpamComplaint' === $kind ) {
			$type = 'complaint';
		} elseif ( 'SubscriptionChange' === $kind && ! empty( $e['SuppressSending'] ) && 'ManualSuppression' === ( $e['SuppressionReason'] ?? '' ) ) {
			$type = 'unsubscribe';
		}
		if ( '' === $type ) {
			return array();
		}
		return array( array(
			'type'       => $type,
			'email'      => (string) ( $e['Email'] ?? ( $e['Recipient'] ?? '' ) ),
			'message_id' => (string) ( $e['MessageID'] ?? '' ),
			'detail'     => (string) ( $e['Description'] ?? ( $e['Type'] ?? $kind ) ),
		) );
	}
}
