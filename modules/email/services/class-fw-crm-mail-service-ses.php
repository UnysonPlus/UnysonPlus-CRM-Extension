<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Amazon SES: the v2 SendEmail API, signed with AWS Signature Version 4. Bounces and
 * complaints arrive as SNS notifications (the subscription is confirmed automatically).
 */
class FW_CRM_Mail_Service_SES extends FW_CRM_Mail_Service {

	public function id() {
		return 'ses';
	}

	public function label() {
		return 'Amazon SES';
	}

	public function fields() {
		return array(
			'access_key' => array( 'label' => __( 'Access key ID', 'fw' ), 'type' => 'text' ),
			'secret_key' => array( 'label' => __( 'Secret access key', 'fw' ), 'type' => 'password' ),
			'region'     => array( 'label' => __( 'Region', 'fw' ), 'type' => 'text', 'desc' => __( 'For example us-east-1 or eu-west-1', 'fw' ) ),
		);
	}

	/** The simple message format carries no files; the site's own mail sends those instead. */
	public function supports_attachments() {
		return false;
	}

	public function send( array $m ) {
		$s      = $this->settings();
		$from   = '' !== $m['from_name'] ? sprintf( '"%s" <%s>', str_replace( '"', '', $m['from_name'] ), $m['from_email'] ) : $m['from_email'];
		$simple = array(
			'Subject' => array( 'Data' => $m['subject'], 'Charset' => 'UTF-8' ),
			'Body'    => array( 'Html' => array( 'Data' => $m['html'], 'Charset' => 'UTF-8' ) ),
		);
		if ( '' !== $m['text'] ) {
			$simple['Body']['Text'] = array( 'Data' => $m['text'], 'Charset' => 'UTF-8' );
		}
		foreach ( $m['headers'] as $name => $value ) {
			if ( 'message-id' !== strtolower( $name ) ) { // SES assigns its own Message-ID.
				$simple['Headers'][] = array( 'Name' => $name, 'Value' => $value );
			}
		}
		$body = array(
			'FromEmailAddress' => $from,
			'Destination'      => array_filter( array( 'ToAddresses' => $m['to'], 'CcAddresses' => $m['cc'], 'BccAddresses' => $m['bcc'] ) ),
			'Content'          => array( 'Simple' => $simple ),
		);
		if ( '' !== $m['reply_to'] ) {
			$body['ReplyToAddresses'] = array( $m['reply_to'] );
		}
		$region = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $s['region'] ) );
		$host   = 'email.' . $region . '.amazonaws.com';
		$path   = '/v2/email/outbound-emails';
		$json   = wp_json_encode( $body );
		$res    = wp_remote_post( 'https://' . $host . $path, array(
			'timeout' => 20,
			'headers' => self::sign( 'POST', $host, $path, $json, $region, (string) $s['access_key'], (string) $s['secret_key'] ),
			'body'    => $json,
		) );
		$res = $this->reply( $res );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * AWS Signature Version 4 headers for one request.
	 *
	 * @return array
	 */
	public static function sign( $method, $host, $path, $payload, $region, $key, $secret, $amz = null ) {
		$service = 'ses';
		$amz     = $amz ? $amz : gmdate( 'Ymd\THis\Z' );
		$date    = substr( $amz, 0, 8 );
		$hash    = hash( 'sha256', $payload );
		$headers = array(
			'content-type'         => 'application/json',
			'host'                 => $host,
			'x-amz-content-sha256' => $hash,
			'x-amz-date'           => $amz,
		);
		ksort( $headers );
		$canon_h = '';
		foreach ( $headers as $k => $v ) {
			$canon_h .= $k . ':' . trim( $v ) . "\n";
		}
		$signed    = implode( ';', array_keys( $headers ) );
		$canonical = implode( "\n", array( $method, $path, '', $canon_h, $signed, $hash ) );
		$scope     = $date . '/' . $region . '/' . $service . '/aws4_request';
		$to_sign   = implode( "\n", array( 'AWS4-HMAC-SHA256', $amz, $scope, hash( 'sha256', $canonical ) ) );
		$k         = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', $service, hash_hmac( 'sha256', $region, hash_hmac( 'sha256', $date, 'AWS4' . $secret, true ), true ), true ), true );

		$headers['Authorization'] = sprintf( 'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $key, $scope, $signed, hash_hmac( 'sha256', $to_sign, $k ) );
		unset( $headers['host'] ); // The HTTP library sends it.
		return $headers;
	}

	public function parse_events( $request ) {
		$sns = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $sns ) ) {
			return array();
		}
		// Subscribing the webhook: SNS asks us to visit a confirmation address on its own domain.
		if ( 'SubscriptionConfirmation' === ( $sns['Type'] ?? '' ) ) {
			$url  = (string) ( $sns['SubscribeURL'] ?? '' );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && preg_match( '/^sns\.[a-z0-9\-]+\.amazonaws\.com$/', $host ) ) {
				wp_remote_get( $url, array( 'timeout' => 10 ) );
			}
			return array();
		}
		$n   = json_decode( (string) ( $sns['Message'] ?? '' ), true );
		$out = array();
		if ( ! is_array( $n ) ) {
			return $out;
		}
		$mid = (string) ( $n['mail']['messageId'] ?? '' );
		foreach ( (array) ( $n['mail']['headers'] ?? array() ) as $h ) {
			if ( 'message-id' === strtolower( (string) ( $h['name'] ?? '' ) ) ) {
				$mid = (string) $h['value'];
			}
		}
		$kind = (string) ( $n['notificationType'] ?? ( $n['eventType'] ?? '' ) );
		if ( 'Bounce' === $kind ) {
			$type = 'Permanent' === ( $n['bounce']['bounceType'] ?? '' ) ? 'bounce' : 'soft_bounce';
			foreach ( (array) ( $n['bounce']['bouncedRecipients'] ?? array() ) as $r ) {
				$out[] = array( 'type' => $type, 'email' => (string) ( $r['emailAddress'] ?? '' ), 'message_id' => $mid, 'detail' => (string) ( $r['diagnosticCode'] ?? ( $n['bounce']['bounceSubType'] ?? '' ) ) );
			}
		} elseif ( 'Complaint' === $kind ) {
			foreach ( (array) ( $n['complaint']['complainedRecipients'] ?? array() ) as $r ) {
				$out[] = array( 'type' => 'complaint', 'email' => (string) ( $r['emailAddress'] ?? '' ), 'message_id' => $mid, 'detail' => __( 'Marked as spam', 'fw' ) );
			}
		}
		return $out;
	}
}
