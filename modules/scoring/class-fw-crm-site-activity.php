<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site activity for known contacts: the pages a contact visits once they have identified
 * themselves, on their timeline, in their score and as an automation trigger.
 *
 * - **Who**: a visitor becomes known when they send a form that adds them to the CRM, click a
 *   link in a campaign, or log in. The site then sets a first-party cookie holding the contact
 *   id and a signature (HMAC with the site's salt), so it cannot be forged or used to look up
 *   anyone else. Nothing is ever recorded for an anonymous visitor.
 * - **Consent**: off until a manager switches it on. "With consent" can require a cookie that
 *   the site's consent banner sets; a browser sending Do Not Track or Global Privacy Control is
 *   never tracked. Filter `unysonplus_crm_site_activity_allowed` for anything else.
 * - **How**: a small script on every page (so it works with page caching) checks for the cookie
 *   in the browser and only then sends the page to the REST endpoint, which re-checks the
 *   signature and consent. Clicks on elements marked `data-crm-event="name"` are sent as events.
 * - **Timeline**: one "Visited the site" entry per visit (a new visit after 30 minutes away),
 *   not one per page; the pages themselves are on the contact's Score & activity panel.
 */
class FW_CRM_Site_Activity {

	const OPTION  = 'fw_crm_site_activity';
	const COOKIE  = 'fw_crm_vid';
	const SESSION = 1800; // seconds without a page before the next one starts a new visit

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, '_routes' ) );
		add_action( 'wp_footer', array( __CLASS__, '_script' ), 99 );
		// Becoming known.
		add_action( 'unysonplus_crm_form_submitted', function ( $contact_id ) {
			self::identify( (int) $contact_id );
		} );
		add_action( 'unysonplus_crm_email_event', function ( $type, $row ) {
			if ( 'click' === $type && ! empty( $row->subscriber_id ) ) {
				self::identify( (int) $row->subscriber_id );
			}
		}, 30, 2 );
		add_action( 'wp_login', function ( $login, $user ) {
			$c = FW_CRM_Events_Store::contact_for_user( $user );
			if ( $c ) {
				self::identify( (int) $c->id );
			}
		}, 20, 2 );
	}

	/** @return array { on, consent: always|cookie, consent_cookie, exclude } */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( array( 'on' => false, 'consent' => 'cookie', 'consent_cookie' => '', 'exclude' => "/wp-admin\n/cart\n/checkout\n/my-account" ), is_array( $s ) ? $s : array() );
	}

	/** @param array $c */
	public static function save_settings( array $c ) {
		$c = array_merge( self::settings(), $c ); // Anything not passed keeps its current value.
		update_option( self::OPTION, array(
			'on'             => ! empty( $c['on'] ),
			'consent'        => 'always' === ( $c['consent'] ?? '' ) ? 'always' : 'cookie',
			'consent_cookie' => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $c['consent_cookie'] ?? '' ) ),
			'exclude'        => sanitize_textarea_field( (string) ( $c['exclude'] ?? '' ) ),
		), false );
	}

	/* ---------------------------------------------------------------------- *
	 * Who is this?
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $contact_id
	 *
	 * @return string "<id>.<signature>"
	 */
	public static function token( $contact_id ) {
		return (int) $contact_id . '.' . substr( hash_hmac( 'sha256', 'fw-crm-vid|' . (int) $contact_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * @param string $token
	 *
	 * @return int The contact id, or 0 when the signature is wrong.
	 */
	public static function verify( $token ) {
		$parts = explode( '.', (string) $token, 2 );
		return 2 === count( $parts ) && ctype_digit( $parts[0] ) && hash_equals( self::token( (int) $parts[0] ), (string) $token ) ? (int) $parts[0] : 0;
	}

	/**
	 * Remember this browser as a contact (only while site activity is on).
	 *
	 * @param int $contact_id
	 */
	public static function identify( $contact_id ) {
		if ( ! $contact_id || ! self::settings()['on'] || headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, self::token( $contact_id ), array(
			'expires'  => time() + YEAR_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => false, // The page script only checks that it exists; the server checks the signature.
			'samesite' => 'Lax',
		) );
		$_COOKIE[ self::COOKIE ] = self::token( $contact_id );
	}

	/**
	 * May we record for this request?
	 *
	 * @param WP_REST_Request|null $request
	 *
	 * @return bool
	 */
	public static function allowed( $request = null ) {
		$s  = self::settings();
		$ok = ! empty( $s['on'] );
		if ( $ok && ( ( isset( $_SERVER['HTTP_DNT'] ) && '1' === $_SERVER['HTTP_DNT'] ) || ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === $_SERVER['HTTP_SEC_GPC'] ) ) ) {
			$ok = false;
		}
		if ( $ok && 'cookie' === $s['consent'] ) {
			$ok = '' !== $s['consent_cookie'] && ! empty( $_COOKIE[ $s['consent_cookie'] ] ) && ! in_array( strtolower( (string) $_COOKIE[ $s['consent_cookie'] ] ), array( '0', 'no', 'false', 'deny', 'denied' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		/** Filters whether site activity may be recorded for this request. */
		return (bool) apply_filters( 'unysonplus_crm_site_activity_allowed', $ok, $request );
	}

	/* ---------------------------------------------------------------------- *
	 * Recording
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _routes() {
		register_rest_route( 'fw-crm/v1', '/activity', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // The signed cookie is the credential; checked below.
			'callback'            => array( __CLASS__, '_rest' ),
		) );
	}

	/**
	 * @internal
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	public static function _rest( $request ) {
		$contact = self::verify( isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '' );
		if ( ! $contact || ! self::allowed( $request ) ) {
			return array( 'ok' => false );
		}
		$c = FW_CRM_Subscribers::find( $contact );
		if ( ! $c || ! empty( $c->deleted_at ) ) {
			return array( 'ok' => false );
		}
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return array( 'ok' => false );
		}
		$event = (string) $request->get_param( 'event' );
		if ( '' !== $event ) {
			FW_CRM_Events_Store::record( $contact, $event, array( 'value' => (string) $request->get_param( 'value' ), 'url' => $url ) );
			return array( 'ok' => true );
		}
		return array( 'ok' => (bool) self::page_view( $contact, $url, sanitize_text_field( (string) $request->get_param( 'title' ) ) ) );
	}

	/**
	 * Record one page view.
	 *
	 * @param int      $contact_id
	 * @param string   $url
	 * @param string   $title
	 * @param int|null $now
	 *
	 * @return int The event id, or 0 (excluded, or the same page again within a few minutes).
	 */
	public static function page_view( $contact_id, $url, $title = '', $now = null ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( preg_split( '/\R/', (string) self::settings()['exclude'] ) as $ex ) {
			$ex = trim( $ex );
			if ( '' !== $ex && 0 === strpos( $path, $ex ) ) {
				return 0;
			}
		}
		$now  = null === $now ? current_time( 'timestamp' ) : (int) $now; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$last = FW_CRM_Events_Store::for_contact( $contact_id, 1, 'page_view' );
		$last = $last ? $last[0] : null;
		if ( $last && $last->url === $url && strtotime( $last->created_at ) > $now - 5 * MINUTE_IN_SECONDS ) {
			return 0; // A reload, not a visit.
		}
		$id = FW_CRM_Events_Store::record( $contact_id, 'page_view', array( 'url' => $url, 'value' => substr( $title, 0, 255 ) ) );
		if ( ! $last || strtotime( $last->created_at ) < $now - self::SESSION ) {
			FW_CRM_Activity::log( 'contact', (int) $contact_id, 'visit', sprintf( __( 'Visited the site: %s', 'fw' ), '' !== $title ? $title : $path ), array( 'meta' => array( 'url' => $url ), 'actor_id' => 0 ) );
		}
		do_action( 'unysonplus_crm_automation_event', 'crm.page_visited', (int) $contact_id, array( 'url' => $url ) );
		return $id;
	}

	/** @internal The page script: only does anything for a known visitor, in the browser. */
	public static function _script() {
		if ( ! self::settings()['on'] || is_admin() ) {
			return;
		}
		$endpoint = esc_url_raw( rest_url( 'fw-crm/v1/activity' ) );
		?>
		<script>
		( function () {
			if ( -1 === document.cookie.indexOf( '<?php echo esc_js( self::COOKIE ); ?>=' ) || '1' === navigator.doNotTrack || navigator.globalPrivacyControl ) {
				return;
			}
			var url = '<?php echo esc_js( $endpoint ); ?>';
			function send( data ) {
				var body = new Blob( [ JSON.stringify( data ) ], { type: 'application/json' } );
				if ( ! ( navigator.sendBeacon && navigator.sendBeacon( url, body ) ) ) {
					fetch( url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } );
				}
			}
			send( { url: location.href, title: document.title } );
			document.addEventListener( 'click', function ( e ) {
				var el = e.target.closest && e.target.closest( '[data-crm-event]' );
				if ( el ) {
					send( { url: location.href, event: el.getAttribute( 'data-crm-event' ), value: el.getAttribute( 'data-crm-value' ) || '' } );
				}
			} );
		}() );
		</script>
		<?php
	}
}
