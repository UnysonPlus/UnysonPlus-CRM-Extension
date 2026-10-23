<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Sender profiles: who an email comes from (name, address, reply-to) and how it is sent
 * (the site's own mail, or an email service by API).
 *
 * Two kinds of email, kept apart on purpose:
 * - **marketing**: campaigns and digests. Bulk mail, unsubscribe headers, often a
 *   separate service or stream so its complaints never hurt the other kind.
 * - **transactional**: confirmations, welcomes, one-to-one email and case replies.
 *   People expect these, so they get their own profile.
 * Each kind has a default profile; a campaign can pick another one.
 *
 * Every CRM sending path still calls wp_mail(). It wraps the call in begin()/end(), and
 * while a profile is active the filters here set the From name and address, add its
 * Reply-To and, when the profile uses a service, hand the message to that service
 * (`pre_wp_mail`). Outside begin()/end() nothing here changes any email the site sends.
 */
class FW_CRM_Tracking_Bridge {

	/**
	 * A service event: tracking handles it when email marketing is on; otherwise a hard bounce or
	 * complaint still stops campaigns to that address and an unsubscribe still unsubscribes.
	 *
	 * @param array $e { type, email, message_id, detail }
	 */
	public static function report( array $e ) {
		if ( class_exists( 'FW_CRM_Tracking' ) ) {
			FW_CRM_Tracking::service_report( $e['type'], $e['email'], $e['message_id'], $e['detail'] );
			return;
		}
		$c = FW_CRM_Subscribers::find_by_email( strtolower( (string) $e['email'] ) );
		if ( ! $c ) {
			return;
		}
		if ( 'unsubscribe' === $e['type'] ) {
			FW_CRM_Service::unsubscribe( $c );
		} elseif ( in_array( $e['type'], array( 'bounce', 'complaint' ), true ) ) {
			FW_CRM_Subscribers::update( (int) $c->id, array( 'status' => 'bounce' === $e['type'] ? 'bounced' : 'complained' ) );
		}
	}
}

class FW_CRM_Senders {

	const OPTION = 'fw_crm_senders';

	/** @var array|null The profile in use, with its purpose. */
	private static $active = null;

	public static function init() {
		add_filter( 'wp_mail_from', array( __CLASS__, '_from' ), 50 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, '_from_name' ), 50 );
		add_filter( 'wp_mail', array( __CLASS__, '_reply_to' ), 50 );
		add_filter( 'pre_wp_mail', array( __CLASS__, '_via_service' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, '_routes' ) );
	}

	/** @internal Each service posts its bounce, complaint and unsubscribe events here. */
	public static function _routes() {
		register_rest_route( 'fw-crm/v1', '/mail-services/(?P<service>[a-z0-9_-]+)/events', array(
			'methods'             => 'POST',
			'permission_callback' => function ( $request ) {
				// A webhook cannot log in, so the secret token in its address is the credential.
				return hash_equals( FW_CRM_Mail_Service::webhook_token(), (string) $request->get_param( 'token' ) );
			},
			'callback'            => function ( $request ) {
				$service = FW_CRM_Mail_Service::get( (string) $request['service'] );
				if ( ! $service ) {
					return new WP_Error( 'fw_crm_no_service', 'Unknown service.', array( 'status' => 404 ) );
				}
				$done = 0;
				foreach ( $service->parse_events( $request ) as $e ) {
					if ( '' !== (string) $e['email'] ) {
						FW_CRM_Tracking_Bridge::report( $e );
						$done++;
					}
				}
				return array( 'ok' => true, 'handled' => $done );
			},
		) );
	}

	/**
	 * "Newsletter: Jane at Example <news@example.com>"
	 *
	 * @param array $p
	 *
	 * @return string
	 */
	public static function describe( array $p ) {
		$from = '' !== $p['from_name'] ? $p['from_name'] . ' <' . $p['from_email'] . '>' : $p['from_email'];
		return $p['name'] . ': ' . $from;
	}

	/** @return array { profiles: id => profile, marketing: id, transactional: id } */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = array_merge( array( 'profiles' => array(), 'marketing' => '', 'transactional' => '' ), is_array( $s ) ? $s : array() );
		foreach ( $s['profiles'] as $id => $p ) {
			$s['profiles'][ $id ] = self::normalize( (array) $p, (string) $id );
		}
		return $s;
	}

	/** @return array id => profile */
	public static function profiles() {
		return self::settings()['profiles'];
	}

	/**
	 * @param string $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::profiles();
		return isset( $all[ (string) $id ] ) ? $all[ (string) $id ] : null;
	}

	/**
	 * The profile for a kind of email: the chosen one, else the purpose's default, else none.
	 *
	 * @param string $purpose marketing|transactional
	 * @param string $id
	 *
	 * @return array|null
	 */
	public static function resolve( $purpose, $id = '' ) {
		if ( '' !== (string) $id && ( $p = self::get( $id ) ) ) {
			return $p;
		}
		$s = self::settings();
		return self::get( (string) ( $s[ 'transactional' === $purpose ? 'transactional' : 'marketing' ] ?? '' ) );
	}

	/**
	 * Save a profile (new when $data['id'] is empty).
	 *
	 * @param array $data name, from_name, from_email, reply_to, service
	 *
	 * @return string|WP_Error The id.
	 */
	public static function save( array $data ) {
		$p = self::normalize( $data, (string) ( $data['id'] ?? '' ) );
		if ( '' === $p['name'] ) {
			return new WP_Error( 'fw_crm_sender_name', __( 'Give the sender a name, such as "Newsletter" or "Support".', 'fw' ) );
		}
		if ( ! is_email( $p['from_email'] ) ) {
			return new WP_Error( 'fw_crm_sender_email', __( 'The From address is not a valid email address.', 'fw' ) );
		}
		if ( '' !== $p['reply_to'] && ! is_email( $p['reply_to'] ) ) {
			return new WP_Error( 'fw_crm_sender_reply', __( 'The Reply-To address is not a valid email address.', 'fw' ) );
		}
		$s = self::settings();
		if ( '' === $p['id'] ) {
			$p['id'] = 's' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		$s['profiles'][ $p['id'] ] = $p;
		foreach ( array( 'marketing', 'transactional' ) as $kind ) {
			if ( '' === $s[ $kind ] ) {
				$s[ $kind ] = $p['id']; // The first profile becomes the default for both kinds.
			}
		}
		update_option( self::OPTION, $s, false );
		return $p['id'];
	}

	/** @param string $id */
	public static function delete( $id ) {
		$s = self::settings();
		unset( $s['profiles'][ (string) $id ] );
		foreach ( array( 'marketing', 'transactional' ) as $kind ) {
			if ( $s[ $kind ] === (string) $id ) {
				$s[ $kind ] = (string) ( key( $s['profiles'] ) ?? '' );
			}
		}
		update_option( self::OPTION, $s, false );
	}

	/**
	 * @param string $marketing
	 * @param string $transactional
	 */
	public static function set_defaults( $marketing, $transactional ) {
		$s                  = self::settings();
		$s['marketing']     = isset( $s['profiles'][ $marketing ] ) ? $marketing : '';
		$s['transactional'] = isset( $s['profiles'][ $transactional ] ) ? $transactional : '';
		update_option( self::OPTION, $s, false );
	}

	/**
	 * Use a profile for the wp_mail() calls that follow, until end().
	 *
	 * @param string $purpose marketing|transactional
	 * @param string $id      A profile id, or '' for the purpose's default.
	 */
	public static function begin( $purpose, $id = '' ) {
		$p             = self::resolve( $purpose, $id );
		self::$active  = $p ? array_merge( $p, array( 'purpose' => $purpose ) ) : null;
	}

	public static function end() {
		self::$active = null;
	}

	/** @return array|null */
	public static function active() {
		return self::$active;
	}

	/* ---------------------------------------------------------------------- *
	 * wp_mail filters (only while a profile is active)
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _from( $from ) {
		return self::$active && '' !== self::$active['from_email'] ? self::$active['from_email'] : $from;
	}

	/** @internal */
	public static function _from_name( $name ) {
		return self::$active && '' !== self::$active['from_name'] ? self::$active['from_name'] : $name;
	}

	/** @internal Add the profile's Reply-To unless the email already has one. */
	public static function _reply_to( $args ) {
		if ( ! self::$active || '' === self::$active['reply_to'] ) {
			return $args;
		}
		$headers = self::header_lines( $args['headers'] ?? array() );
		foreach ( $headers as $h ) {
			if ( 0 === stripos( $h, 'reply-to:' ) ) {
				return $args;
			}
		}
		$headers[]       = 'Reply-To: ' . self::$active['reply_to'];
		$args['headers'] = $headers;
		return $args;
	}

	/**
	 * Hand the message to the profile's email service. Returns null (let wp_mail send it)
	 * when no service is set, it is not configured, or it cannot carry the attachments.
	 *
	 * @internal
	 *
	 * @param null|bool $short
	 * @param array     $atts
	 *
	 * @return null|bool
	 */
	public static function _via_service( $short, $atts ) {
		if ( null !== $short || ! self::$active || '' === self::$active['service'] ) {
			return $short;
		}
		$service = FW_CRM_Mail_Service::get( self::$active['service'] );
		if ( ! $service || ! $service->is_configured() ) {
			return $short;
		}
		$attachments = array_filter( (array) ( $atts['attachments'] ?? array() ) );
		if ( $attachments && ! $service->supports_attachments() ) {
			return $short;
		}
		$m   = self::message( $atts, $attachments );
		$res = $service->send( $m );
		if ( is_wp_error( $res ) ) {
			/** Same signal wp_mail() gives, so every caller that listens for failures hears this one. */
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $res->get_error_message(), array( 'to' => $m['to'], 'subject' => $m['subject'], 'service' => $service->id() ) ) );
			return false;
		}
		/** Fires after a CRM email was handed to an email service. */
		do_action( 'unysonplus_crm_mail_service_sent', $service->id(), $m );
		return true;
	}

	/**
	 * wp_mail's arguments as a service message.
	 *
	 * @param array    $atts
	 * @param string[] $attachments
	 *
	 * @return array
	 */
	public static function message( array $atts, array $attachments = array() ) {
		$p = self::$active ? self::$active : self::normalize( array(), '' );
		$m = array(
			'to'          => self::addresses( $atts['to'] ?? array() ),
			'cc'          => array(),
			'bcc'         => array(),
			'from_email'  => apply_filters( 'wp_mail_from', '' !== $p['from_email'] ? $p['from_email'] : get_option( 'admin_email' ) ),
			'from_name'   => apply_filters( 'wp_mail_from_name', '' !== $p['from_name'] ? $p['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'reply_to'    => $p['reply_to'],
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'html'        => (string) ( $atts['message'] ?? '' ),
			'text'        => '',
			'headers'     => array(),
			'attachments' => $attachments,
			'purpose'     => (string) ( $p['purpose'] ?? '' ),
		);
		$html = false;
		foreach ( self::header_lines( $atts['headers'] ?? array() ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			switch ( strtolower( $name ) ) {
				case 'content-type':
					$html = false !== stripos( $value, 'text/html' );
					break;
				case 'cc':
					$m['cc'] = array_merge( $m['cc'], self::addresses( $value ) );
					break;
				case 'bcc':
					$m['bcc'] = array_merge( $m['bcc'], self::addresses( $value ) );
					break;
				case 'reply-to':
					$m['reply_to'] = $value;
					break;
				case 'from':
					$f = preg_match( '/^(.*)<([^>]+)>$/', $value, $mm ) ? array( trim( $mm[1], ' "' ), trim( $mm[2] ) ) : array( '', $value );
					if ( is_email( $f[1] ) ) {
						$m['from_email'] = $f[1];
						$m['from_name']  = '' !== $f[0] ? $f[0] : $m['from_name'];
					}
					break;
				default:
					$m['headers'][ $name ] = $value;
			}
		}
		if ( ! $html ) {
			$m['text'] = $m['html'];
			$m['html'] = nl2br( esc_html( $m['html'] ) );
		} else {
			$m['text'] = self::to_text( $m['html'] );
		}
		return $m;
	}

	/**
	 * A plain-text copy of an HTML email, for the text/plain part.
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	public static function to_text( $html ) {
		if ( class_exists( 'FW_CRM_Mime' ) && method_exists( 'FW_CRM_Mime', 'html_to_text' ) ) {
			return FW_CRM_Mime::html_to_text( $html );
		}
		$html = preg_replace( '#<(style|script|head)[^>]*>.*?</\1>#si', '', (string) $html );
		$html = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#si', '$2 ($1)', $html );
		$html = preg_replace( '#<(br|/p|/div|/tr|/h[1-6]|/li)[^>]*>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( "/[ \t]+/", ' ', $text ) ) );
	}

	/**
	 * @param string|string[] $headers
	 *
	 * @return string[]
	 */
	private static function header_lines( $headers ) {
		return is_array( $headers ) ? array_values( $headers ) : array_filter( explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) ) );
	}

	/**
	 * @param string|string[] $value
	 *
	 * @return string[]
	 */
	private static function addresses( $value ) {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_filter( array_map( 'trim', $list ) ) );
	}

	/**
	 * @param array  $p
	 * @param string $id
	 *
	 * @return array
	 */
	private static function normalize( array $p, $id ) {
		$service = sanitize_key( (string) ( $p['service'] ?? '' ) );
		return array(
			'id'         => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $id ) ),
			'name'       => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
			'from_name'  => sanitize_text_field( (string) ( $p['from_name'] ?? '' ) ),
			'from_email' => sanitize_email( (string) ( $p['from_email'] ?? '' ) ),
			'reply_to'   => sanitize_email( (string) ( $p['reply_to'] ?? '' ) ),
			'service'    => $service,
		);
	}
}
