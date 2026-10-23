<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * An email service the CRM can send through by API instead of the site's own mail
 * (wp_mail and whatever SMTP plugin is behind it).
 *
 * A service is chosen per sender profile (FW_CRM_Senders). While a CRM email is being
 * sent with such a profile, `pre_wp_mail` hands the message to the service here, so
 * every sending path (campaigns, confirmations, one-to-one email, case replies) keeps
 * calling wp_mail() and needs to know nothing about services.
 *
 * A service also turns its bounce and complaint notifications (its webhook) into the
 * same contact updates an incoming bounce email makes (FW_CRM_Tracking::service_report).
 *
 * Register more with the `unysonplus_crm_mail_services` filter: id => class name.
 */
abstract class FW_CRM_Mail_Service {

	/** Where every service's settings live: id => { field => value }. Secrets are encrypted. */
	const OPTION = 'fw_crm_mail_services';

	/** @return string */
	abstract public function id();

	/** @return string */
	abstract public function label();

	/**
	 * The settings fields: key => { label, type (text|password|select), choices?, desc? }.
	 * A `password` field is stored encrypted and never printed back.
	 *
	 * @return array
	 */
	abstract public function fields();

	/**
	 * Send one message.
	 *
	 * @param array $m { to: string[], cc: string[], bcc: string[], from_email, from_name, reply_to, subject, html,
	 *                 text, headers: name => value (List-Unsubscribe, Message-ID…), attachments: paths }
	 *
	 * @return true|WP_Error
	 */
	abstract public function send( array $m );

	/**
	 * Turn a webhook request into reports.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array[] Each { type: bounce|soft_bounce|complaint|unsubscribe, email, message_id, detail }.
	 */
	public function parse_events( $request ) {
		return array();
	}

	/** @return bool Can it attach files? */
	public function supports_attachments() {
		return true;
	}

	/* ---------------------------------------------------------------------- */

	/** @return array id => FW_CRM_Mail_Service */
	public static function all() {
		static $all = null;
		if ( null === $all ) {
			$dir     = dirname( __FILE__ ) . '/';
			$classes = array(
				'sendgrid' => 'FW_CRM_Mail_Service_SendGrid',
				'mailgun'  => 'FW_CRM_Mail_Service_Mailgun',
				'postmark' => 'FW_CRM_Mail_Service_Postmark',
				'brevo'    => 'FW_CRM_Mail_Service_Brevo',
				'ses'      => 'FW_CRM_Mail_Service_SES',
			);
			foreach ( $classes as $id => $class ) {
				if ( ! class_exists( $class ) && is_file( $dir . 'class-fw-crm-mail-service-' . $id . '.php' ) ) {
					require_once $dir . 'class-fw-crm-mail-service-' . $id . '.php';
				}
			}
			/** Filters the email services: id => class name (a subclass of FW_CRM_Mail_Service). */
			$classes = (array) apply_filters( 'unysonplus_crm_mail_services', $classes );
			$all     = array();
			foreach ( $classes as $id => $class ) {
				if ( class_exists( $class ) && is_subclass_of( $class, __CLASS__ ) ) {
					$all[ $id ] = new $class();
				}
			}
		}
		return $all;
	}

	/**
	 * @param string $id
	 *
	 * @return FW_CRM_Mail_Service|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/** @return array This service's saved settings, secrets decrypted. */
	public function settings() {
		$all = get_option( self::OPTION, array() );
		$s   = is_array( $all ) && isset( $all[ $this->id() ] ) && is_array( $all[ $this->id() ] ) ? $all[ $this->id() ] : array();
		foreach ( $this->fields() as $k => $f ) {
			if ( 'password' === ( $f['type'] ?? '' ) && isset( $s[ $k ] ) ) {
				$s[ $k ] = self::decrypt( (string) $s[ $k ] );
			}
		}
		return $s;
	}

	/**
	 * Save settings. An empty password field keeps the stored secret.
	 *
	 * @param array $values
	 */
	public function save_settings( array $values ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$old = isset( $all[ $this->id() ] ) && is_array( $all[ $this->id() ] ) ? $all[ $this->id() ] : array();
		$new = array();
		foreach ( $this->fields() as $k => $f ) {
			$v = isset( $values[ $k ] ) ? trim( (string) $values[ $k ] ) : '';
			if ( 'password' === ( $f['type'] ?? '' ) ) {
				$new[ $k ] = '' !== $v ? self::encrypt( $v ) : (string) ( $old[ $k ] ?? '' );
			} elseif ( 'select' === ( $f['type'] ?? '' ) ) {
				$new[ $k ] = array_key_exists( $v, (array) $f['choices'] ) ? $v : (string) key( (array) $f['choices'] );
			} else {
				$new[ $k ] = sanitize_text_field( $v );
			}
		}
		$all[ $this->id() ] = $new;
		update_option( self::OPTION, $all, false );
	}

	/** @return bool Every field filled in. */
	public function is_configured() {
		$s = $this->settings();
		foreach ( $this->fields() as $k => $f ) {
			if ( empty( $f['optional'] ) && '' === (string) ( $s[ $k ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The address a service posts its bounce and complaint events to. Carries a secret token,
	 * because a webhook cannot log in.
	 *
	 * @return string
	 */
	public function webhook_url() {
		return add_query_arg( 'token', self::webhook_token(), rest_url( 'fw-crm/v1/mail-services/' . $this->id() . '/events' ) );
	}

	/** @return string */
	public static function webhook_token() {
		$t = (string) get_option( 'fw_crm_mail_services_token', '' );
		if ( '' === $t ) {
			$t = wp_generate_password( 32, false );
			update_option( 'fw_crm_mail_services_token', $t, false );
		}
		return $t;
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers for subclasses
	 * ---------------------------------------------------------------------- */

	/**
	 * POST JSON and read JSON back.
	 *
	 * @param string $url
	 * @param array  $body
	 * @param array  $headers
	 *
	 * @return array|WP_Error The decoded reply.
	 */
	protected function post_json( $url, array $body, array $headers ) {
		$res = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array_merge( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		) );
		return $this->reply( $res );
	}

	/**
	 * @param array|WP_Error $res
	 *
	 * @return array|WP_Error
	 */
	protected function reply( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) ? (string) ( $data['message'] ?? ( $data['Message'] ?? ( $data['errors'][0]['message'] ?? '' ) ) ) : '';
			return new WP_Error( 'fw_crm_service_' . $code, sprintf(
				/* translators: 1: service, 2: HTTP status, 3: its message */
				__( '%1$s refused the email (%2$d): %3$s', 'fw' ),
				$this->label(),
				$code,
				'' !== $msg ? $msg : wp_html_excerpt( wp_strip_all_tags( $raw ), 200, '…' )
			) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * "Name <a@b.c>" or "a@b.c" → { email, name }.
	 *
	 * @param string $addr
	 *
	 * @return array
	 */
	protected static function split_address( $addr ) {
		$addr = trim( (string) $addr );
		if ( preg_match( '/^(.*)<([^>]+)>$/', $addr, $m ) ) {
			return array( 'email' => trim( $m[2] ), 'name' => trim( trim( $m[1] ), '"\' ' ) );
		}
		return array( 'email' => $addr, 'name' => '' );
	}

	/**
	 * @param string $path
	 *
	 * @return array|null { name, type, content (base64) }
	 */
	protected static function attachment( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$type = wp_check_filetype( $path );
		return array(
			'name'    => basename( $path ),
			'type'    => $type['type'] ? $type['type'] : 'application/octet-stream',
			'content' => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions
		);
	}

	/** @param string $plain */
	public static function encrypt( $plain ) {
		if ( '' === (string) $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return (string) $plain;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( (string) $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** @param string $stored */
	public static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}
}
