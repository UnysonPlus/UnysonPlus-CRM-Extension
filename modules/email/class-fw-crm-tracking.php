<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Opens, clicks, bounces, complaints and unsubscribes for campaigns.
 *
 * - Opens: a 1x1 image at the end of each campaign email (`?fw-crm-o=<token>`).
 * - Clicks: every link goes through `?fw-crm-c=<token>`, then on to the real
 *   address. Each address is stored once in the `email_links` table; the token
 *   carries the queue row and the link id, signed, so a link cannot be forged or
 *   turned into an open redirect.
 * - Link scanners (corporate mail filters that open every link to check it) are
 *   recorded but not counted: known scanner user agents, HEAD and prefetch
 *   requests, clicks within seconds of sending, and bursts of several links at
 *   once by the same recipient.
 * - Bounces and complaints arrive as email (delivery status notifications and
 *   feedback reports) through the inbound address or mailbox, and are matched to
 *   the campaign by the Message-ID campaign emails carry.
 * - Unsubscribes from a campaign's link or its List-Unsubscribe header are
 *   counted against that campaign.
 *
 * Opens and clicks can each be switched off in CRM settings.
 */
class FW_CRM_Tracking {

	const OPEN_ARG  = 'fw-crm-o';
	const CLICK_ARG = 'fw-crm-c';
	const UNSUB_ARG = 'fwq';

	/** @var object|null The queue row being sent (for links built during the send). */
	private static $current = null;

	/** @var int The queue row an unsubscribe request came from. */
	private static $unsub_row = 0;

	public static function init() {
		add_action( 'init', array( __CLASS__, '_handle' ), 0 );
		add_filter( 'unysonplus_crm_unsubscribe_url', array( __CLASS__, '_tag_unsubscribe_url' ), 20, 2 );
		add_action( 'unysonplus_crm_subscriber_unsubscribed', array( __CLASS__, '_on_unsubscribed' ), 10, 1 );
	}

	/** @return string */
	public static function links_table() {
		return FW_CRM_Installer::table( 'email_links' );
	}

	/** @return string */
	public static function events_table() {
		return FW_CRM_Installer::table( 'email_events' );
	}

	/**
	 * @param string $what opens|clicks
	 *
	 * @return bool
	 */
	public static function enabled( $what ) {
		return FW_CRM_Service::setting_is_on( 'opens' === $what ? 'track_opens' : 'track_clicks', true );
	}

	/* ---------------------------------------------------------------------- *
	 * Tokens
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $kind o|c|u
	 * @param int    $queue_id
	 * @param int    $link_id
	 *
	 * @return string
	 */
	public static function token( $kind, $queue_id, $link_id = 0 ) {
		$payload = (int) $queue_id . '.' . (int) $link_id;
		return $payload . '.' . substr( hash_hmac( 'sha256', $kind . '|' . $payload, wp_salt( 'auth' ) ), 0, 12 );
	}

	/**
	 * @param string $kind
	 * @param string $token
	 *
	 * @return array|null [ queue_id, link_id ]
	 */
	public static function parse( $kind, $token ) {
		if ( ! preg_match( '/^(\d+)\.(\d+)\.([a-f0-9]{12})$/', (string) $token, $m ) ) {
			return null;
		}
		$expected = substr( hash_hmac( 'sha256', $kind . '|' . $m[1] . '.' . $m[2], wp_salt( 'auth' ) ), 0, 12 );
		return hash_equals( $expected, $m[3] ) ? array( (int) $m[1], (int) $m[2] ) : null;
	}

	/* ---------------------------------------------------------------------- *
	 * Sending
	 * ---------------------------------------------------------------------- */

	/**
	 * Set or clear the queue row being sent (links and the unsubscribe URL built
	 * meanwhile carry it).
	 *
	 * @param object|null $row
	 */
	public static function sending( $row ) {
		self::$current = $row;
	}

	/**
	 * A Message-ID for a campaign email, so a bounce can be matched to it.
	 *
	 * @param int $queue_id
	 *
	 * @return string Without angle brackets.
	 */
	public static function message_id( $queue_id ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return sprintf( 'fwcrm-q%d-%s@%s', (int) $queue_id, strtolower( wp_generate_password( 10, false ) ), $host ? $host : 'localhost' );
	}

	/**
	 * Add the open pixel and route links through the click tracker.
	 *
	 * @param string $html The finished email.
	 * @param object $row  The queue row.
	 *
	 * @return string
	 */
	public static function instrument( $html, $row ) {
		if ( self::enabled( 'clicks' ) ) {
			$html = preg_replace_callback( '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is', function ( $m ) use ( $row ) {
				$url = html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' );
				if ( ! self::trackable( $url ) ) {
					return $m[0];
				}
				$link = self::link_id( $url );
				if ( ! $link ) {
					return $m[0];
				}
				$tracked = add_query_arg( self::CLICK_ARG, self::token( 'c', (int) $row->id, $link ), home_url( '/' ) );
				return $m[1] . $m[2] . esc_url( $tracked ) . $m[2];
			}, (string) $html );
		}
		if ( self::enabled( 'opens' ) ) {
			$src   = add_query_arg( self::OPEN_ARG, self::token( 'o', (int) $row->id ), home_url( '/' ) );
			$pixel = '<img src="' . esc_url( $src ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;overflow:hidden" />';
			$html  = false !== stripos( $html, '</body>' ) ? preg_replace( '#</body>#i', $pixel . '</body>', $html, 1 ) : $html . $pixel;
		}
		return $html;
	}

	/**
	 * Links worth tracking: web addresses that are not the CRM's own
	 * unsubscribe, preference or confirmation links.
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	private static function trackable( $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		foreach ( array( 'fw-crm-unsubscribe', 'fw-crm-preferences', 'fw-crm-confirm', self::CLICK_ARG . '=', self::OPEN_ARG . '=' ) as $own ) {
			if ( false !== strpos( $url, $own ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The id of a link, stored once.
	 *
	 * @param string $url
	 *
	 * @return int
	 */
	public static function link_id( $url ) {
		global $wpdb;

		$url   = substr( (string) $url, 0, 2000 );
		$hash  = md5( $url );
		$table = self::links_table();
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $id ) {
			return $id;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (hash, url, created_at) VALUES (%s, %s, %s)", $hash, $url, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Add the queue row to the unsubscribe link built while a campaign email is
	 * being put together, so the unsubscribe is counted against the campaign.
	 *
	 * @internal
	 *
	 * @param string $url
	 * @param object $subscriber
	 *
	 * @return string
	 */
	public static function _tag_unsubscribe_url( $url, $subscriber ) {
		if ( self::$current && '' !== (string) $url && (int) self::$current->subscriber_id === (int) $subscriber->id ) {
			$url = add_query_arg( self::UNSUB_ARG, self::token( 'u', (int) self::$current->id ), $url );
		}
		return $url;
	}

	/* ---------------------------------------------------------------------- *
	 * Requests
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public static function _handle() {
		// phpcs:disable WordPress.Security.NonceVerification -- the signed token is the credential.
		if ( isset( $_GET[ self::OPEN_ARG ] ) ) {
			$p = self::parse( 'o', sanitize_text_field( wp_unslash( $_GET[ self::OPEN_ARG ] ) ) );
			if ( $p ) {
				self::record_request( 'open', $p[0], 0 );
			}
			self::pixel();
		}
		if ( isset( $_GET[ self::CLICK_ARG ] ) ) {
			$p   = self::parse( 'c', sanitize_text_field( wp_unslash( $_GET[ self::CLICK_ARG ] ) ) );
			$url = $p ? self::link_url( $p[1] ) : '';
			if ( ! $p || '' === $url ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			self::record_request( 'click', $p[0], $p[1] );
			// The address came from our own link table, never from the request.
			if ( class_exists( 'FW_CRM_Smart_Links' ) ) {
				$url = FW_CRM_Smart_Links::strip( $url );
			}
			wp_redirect( $url, 302, 'Unyson+ CRM' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
		if ( isset( $_REQUEST[ self::UNSUB_ARG ] ) ) {
			$p = self::parse( 'u', sanitize_text_field( wp_unslash( $_REQUEST[ self::UNSUB_ARG ] ) ) );
			self::$unsub_row = $p ? $p[0] : 0;
		}
		// phpcs:enable
	}

	/** Output a transparent GIF and stop. */
	private static function pixel() {
		nocache_headers();
		header( 'Content-Type: image/gif' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * @param int $link_id
	 *
	 * @return string
	 */
	public static function link_url( $link_id ) {
		global $wpdb;

		$table = self::links_table();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$table} WHERE id = %d", (int) $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param object $row
	 *
	 * @return object|null
	 */
	private static function queue_row( $queue_id ) {
		global $wpdb;

		$table = FW_CRM_Installer::table( 'campaign_queue' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $queue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Record an open or click from the current request.
	 *
	 * @param string $type open|click
	 * @param int    $queue_id
	 * @param int    $link_id
	 */
	private static function record_request( $type, $queue_id, $link_id ) {
		$row = self::queue_row( $queue_id );
		if ( ! $row ) {
			return;
		}
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 190 ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$prefetch = isset( $_SERVER['HTTP_PURPOSE'] ) || isset( $_SERVER['HTTP_SEC_PURPOSE'] ) || isset( $_SERVER['HTTP_X_PURPOSE'] ) || isset( $_SERVER['HTTP_X_MOZ'] );
		self::record( $type, $row, $link_id, array( 'ua' => $ua, 'method' => $method, 'prefetch' => $prefetch ) );
	}

	/**
	 * Store an event and, the first time, put it on the contact's timeline.
	 *
	 * @param string $type    open|click|bounce|soft_bounce|complaint|unsubscribe
	 * @param object $row     The queue row.
	 * @param int    $link_id
	 * @param array  $ctx     ua, method (GET|HEAD), prefetch, detail
	 *
	 * @return int The event id.
	 */
	public static function record( $type, $row, $link_id = 0, array $ctx = array() ) {
		global $wpdb;

		$ctx   = array_merge( array( 'ua' => '', 'method' => 'GET', 'prefetch' => false, 'detail' => '' ), $ctx );
		$table = self::events_table();
		$bot   = in_array( $type, array( 'open', 'click' ), true ) ? self::is_bot( $type, $row, $ctx ) : false;
		$first = ! $bot && ! (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND subscriber_id = %d AND type = %s AND link_id = %d AND bot = 0", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $row->campaign_id,
			(int) $row->subscriber_id,
			$type,
			(int) $link_id
		) );
		$wpdb->insert( $table, array(
			'campaign_id'   => (int) $row->campaign_id,
			'subscriber_id' => (int) $row->subscriber_id,
			'queue_id'      => (int) $row->id,
			'type'          => $type,
			'link_id'       => (int) $link_id,
			'bot'           => $bot ? 1 : 0,
			'user_agent'    => (string) $ctx['ua'],
			'detail'        => substr( (string) $ctx['detail'], 0, 255 ),
			'created_at'    => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;

		if ( $first && $row->subscriber_id ) {
			$campaign = FW_CRM_Campaigns::find( (int) $row->campaign_id );
			$subject  = $campaign ? $campaign->subject : '';
			switch ( $type ) {
				case 'open':
					FW_CRM_Activity::log( 'contact', (int) $row->subscriber_id, 'email_open', sprintf( __( 'Opened: %s', 'fw' ), $subject ), array( 'meta' => array( 'campaign_id' => (int) $row->campaign_id ), 'actor_id' => 0 ) );
					break;
				case 'click':
					$url = self::link_url( $link_id );
					FW_CRM_Activity::log( 'contact', (int) $row->subscriber_id, 'email_click', sprintf( __( 'Clicked %1$s in: %2$s', 'fw' ), self::short_url( $url ), $subject ), array( 'meta' => array( 'campaign_id' => (int) $row->campaign_id, 'url' => $url ), 'actor_id' => 0 ) );
					break;
			}
		}
		if ( ! $bot ) {
			/** Fires for each counted campaign event (open, click, bounce, soft_bounce, complaint, unsubscribe). */
			do_action( 'unysonplus_crm_email_event', $type, $row, (int) $link_id, $ctx );
			if ( $first && in_array( $type, array( 'open', 'click' ), true ) && $row->subscriber_id ) {
				do_action( 'unysonplus_crm_automation_event', 'crm.email_' . ( 'open' === $type ? 'opened' : 'clicked' ), (int) $row->subscriber_id, array(
					'campaign_id' => (int) $row->campaign_id,
					'url'         => 'click' === $type ? self::link_url( $link_id ) : '',
					'dedupe'      => $type . ':' . $row->id . ':' . (int) $link_id,
				) );
			}
		}
		return $id;
	}

	/**
	 * Is this a machine rather than a person?
	 *
	 * @param string $type
	 * @param object $row
	 * @param array  $ctx
	 *
	 * @return bool
	 */
	private static function is_bot( $type, $row, array $ctx ) {
		global $wpdb;

		if ( 'HEAD' === $ctx['method'] || ! empty( $ctx['prefetch'] ) ) {
			return true;
		}
		$ua = strtolower( (string) $ctx['ua'] );
		// Image proxies of webmail (they fetch the pixel when a person opens the email) are real opens.
		$scanners = '/bot\b|crawler|spider|scanner|safelinks|safe ?links|proofpoint|mimecast|barracuda|symantec|messagelabs|trendmicro|forcepoint|sophos|fireeye|urldefense|linkscan|bitdefender|kaspersky|checkpoint|cisco|ironport|bingpreview|slackbot|facebookexternalhit|curl|wget|python|java\/|go-http|okhttp|headless|phantomjs|libwww/';
		if ( '' === $ua || preg_match( $scanners, $ua ) ) {
			return 'click' === $type || '' !== $ua; // An open with no user agent is usually an image proxy.
		}
		if ( 'click' !== $type ) {
			return false;
		}
		// A click within seconds of sending is a filter checking the link.
		/** Filters how many seconds after sending a click is treated as a link scanner (default 5). */
		$window = (int) apply_filters( 'unysonplus_crm_scanner_seconds', 5 );
		if ( $row->sent_at && ( current_time( 'timestamp' ) - strtotime( $row->sent_at ) ) <= $window ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			return true;
		}
		// Several different links within two seconds: a scanner walking the email.
		$table = self::events_table();
		$burst = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT COUNT(DISTINCT link_id) FROM {$table} WHERE queue_id = %d AND type = 'click' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $row->id,
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		) );
		return $burst >= 2;
	}

	/**
	 * @param string $url
	 *
	 * @return string host/path, shortened
	 */
	public static function short_url( $url ) {
		$p    = wp_parse_url( (string) $url );
		$text = ( $p['host'] ?? '' ) . ( $p['path'] ?? '' );
		$text = preg_replace( '#^www\.#', '', rtrim( $text, '/' ) );
		return strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '…' : $text;
	}

	/**
	 * @internal Count an unsubscribe against the campaign whose link was used.
	 *
	 * @param object|int $subscriber
	 */
	public static function _on_unsubscribed( $subscriber ) {
		if ( ! self::$unsub_row ) {
			return;
		}
		$row = self::queue_row( self::$unsub_row );
		$sid = is_object( $subscriber ) ? (int) $subscriber->id : (int) $subscriber;
		if ( $row && (int) $row->subscriber_id === $sid ) {
			self::record( 'unsubscribe', $row );
		}
		self::$unsub_row = 0;
	}

	/* ---------------------------------------------------------------------- *
	 * Bounces and complaints
	 * ---------------------------------------------------------------------- */

	/**
	 * Handle a delivery report or a complaint that reached the inbound address or
	 * mailbox. Returns null when the message is neither.
	 *
	 * @param array $m A message from FW_CRM_Mime::parse().
	 *
	 * @return array|null { status: bounce|soft_bounce|complaint, email, contact_id, campaign_id }
	 */
	public static function handle_report( array $m ) {
		$reports = (array) ( $m['reports'] ?? array() );
		$type    = (string) ( $m['report_type'] ?? '' );
		$headers = (string) ( $reports['text/rfc822-headers'] ?? ( $reports['message/rfc822'] ?? ( $reports['message/rfc822-headers'] ?? '' ) ) );

		if ( 'feedback-report' === $type || isset( $reports['message/feedback-report'] ) ) {
			$fb    = FW_CRM_Mime::headers( (string) ( $reports['message/feedback-report'] ?? '' ) );
			$email = self::address( $fb['original-rcpt-to'] ?? '' ) ?: self::address( FW_CRM_Mime::headers( $headers )['to'] ?? '' );
			return self::apply( 'complaint', $email, $headers, 'abuse' === strtolower( (string) ( $fb['feedback-type'] ?? 'abuse' ) ) ? __( 'Marked as spam', 'fw' ) : (string) ( $fb['feedback-type'] ?? '' ) );
		}

		if ( 'delivery-status' === $type || isset( $reports['message/delivery-status'] ) ) {
			$ds = (string) ( $reports['message/delivery-status'] ?? '' );
			// Per-recipient blocks are separated by a blank line; the first is per-message.
			foreach ( preg_split( "/\n\s*\n/", str_replace( array( "\r\n", "\r" ), "\n", $ds ) ) as $block ) {
				$h = FW_CRM_Mime::headers( $block );
				if ( empty( $h['final-recipient'] ) && empty( $h['original-recipient'] ) ) {
					continue;
				}
				$email  = self::address( $h['final-recipient'] ?? $h['original-recipient'] );
				$action = strtolower( (string) ( $h['action'] ?? '' ) );
				$status = (string) ( $h['status'] ?? '' );
				if ( 'delivered' === $action || 'relayed' === $action || 'expanded' === $action ) {
					continue;
				}
				$hard = 'failed' === $action && 0 === strpos( $status, '5' );
				return self::apply( $hard ? 'bounce' : 'soft_bounce', $email, $headers, trim( $status . ' ' . ( $h['diagnostic-code'] ?? '' ) ) );
			}
			return array( 'status' => 'ignored', 'email' => '', 'contact_id' => 0, 'campaign_id' => 0 );
		}

		// A bounce without a machine-readable report: from the mail system, with a
		// permanent SMTP error and the address it was about.
		$from = strtolower( (string) ( $m['from_email'] ?? '' ) );
		if ( preg_match( '/^(mailer-daemon|postmaster|mail-daemon)@/', $from ) && preg_match( '/\b5\d\d[ \-]5\.\d{1,3}\.\d{1,3}\b|\b5\.\d\.\d+\b/', (string) $m['text'], $code ) ) {
			if ( preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', preg_replace( '/(mailer-daemon|postmaster)@\S+/i', '', (string) $m['text'] ), $addr ) ) {
				return self::apply( 'bounce', strtolower( $addr[0] ), '', $code[0] );
			}
		}
		return null;
	}

	/**
	 * @param string $value "rfc822; a@b.c" or "Name <a@b.c>"
	 *
	 * @return string
	 */
	private static function address( $value ) {
		return preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string) $value, $m ) ? strtolower( $m[0] ) : '';
	}

	/**
	 * Record a bounce or complaint and update the contact.
	 *
	 * @param string $type   bounce|soft_bounce|complaint
	 * @param string $email
	 * @param string $headers The original message's headers (to find the campaign).
	 * @param string $detail
	 *
	 * @return array
	 */
	private static function apply( $type, $email, $headers, $detail ) {
		$row = null;
		if ( preg_match( '/fwcrm-q(\d+)-/', (string) $headers, $mm ) ) {
			$row = self::queue_row( (int) $mm[1] );
		}
		$contact = $row ? FW_CRM_Subscribers::find( (int) $row->subscriber_id ) : ( $email ? FW_CRM_Subscribers::find_by_email( $email ) : null );
		if ( ! $row && $contact ) {
			// No CRM Message-ID: count it against the latest campaign sent to them.
			global $wpdb;
			$q   = FW_CRM_Installer::table( 'campaign_queue' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$q} WHERE subscriber_id = %d AND status = 'sent' ORDER BY sent_at DESC LIMIT 1", (int) $contact->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( $row ) {
			self::record( $type, $row, 0, array( 'detail' => $detail ) );
		}
		if ( $contact ) {
			if ( 'bounce' === $type ) {
				FW_CRM_Subscribers::update( (int) $contact->id, array( 'status' => 'bounced' ) );
				FW_CRM_Activity::log( 'contact', (int) $contact->id, 'email', sprintf( __( 'Email bounced: %s', 'fw' ), $detail ), array( 'actor_id' => 0 ) );
			} elseif ( 'complaint' === $type ) {
				FW_CRM_Subscribers::update( (int) $contact->id, array( 'status' => 'complained' ) );
				FW_CRM_Activity::log( 'contact', (int) $contact->id, 'email', __( 'Marked an email as spam: no more campaigns are sent to this address', 'fw' ), array( 'actor_id' => 0 ) );
			}
		}
		return array(
			'status'      => $type,
			'email'       => $email,
			'contact_id'  => $contact ? (int) $contact->id : 0,
			'campaign_id' => $row ? (int) $row->campaign_id : 0,
		);
	}

	/**
	 * A bounce, complaint or unsubscribe reported by an email service's webhook.
	 *
	 * @param string $type       bounce|soft_bounce|complaint|unsubscribe
	 * @param string $email
	 * @param string $message_id The Message-ID the CRM gave the email, when the service passes it back.
	 * @param string $detail
	 *
	 * @return array
	 */
	public static function service_report( $type, $email, $message_id, $detail ) {
		$email = strtolower( trim( (string) $email ) );
		if ( 'unsubscribe' === $type ) {
			$c = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;
			if ( $c && 'unsubscribed' !== $c->status ) {
				FW_CRM_Service::unsubscribe( $c );
			}
			return array( 'status' => 'unsubscribe', 'email' => $email, 'contact_id' => $c ? (int) $c->id : 0, 'campaign_id' => 0 );
		}
		return self::apply( $type, $email, 'Message-ID: <' . trim( (string) $message_id, '<> ' ) . '>', (string) $detail );
	}

	/* ---------------------------------------------------------------------- *
	 * Reports
	 * ---------------------------------------------------------------------- */

	/**
	 * A campaign's numbers (people, not raw events; scanners left out).
	 *
	 * @param int $campaign_id
	 *
	 * @return array
	 */
	public static function stats( $campaign_id ) {
		global $wpdb;

		$table    = self::events_table();
		$campaign = FW_CRM_Campaigns::find( $campaign_id );
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT type, COUNT(*) AS total, COUNT(DISTINCT subscriber_id) AS people FROM {$table} WHERE campaign_id = %d AND bot = 0 GROUP BY type", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $campaign_id
		), OBJECT_K );
		$get      = function ( $t, $k ) use ( $rows ) {
			return isset( $rows[ $t ] ) ? (int) $rows[ $t ]->$k : 0;
		};
		$bots     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND bot = 1", (int) $campaign_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sent     = $campaign ? (int) $campaign->sent : 0;
		$opened   = $get( 'open', 'people' );
		$clicked  = $get( 'click', 'people' );
		$pct      = function ( $n, $d ) {
			return $d ? round( 100 * $n / $d, 1 ) : 0.0;
		};
		return array(
			'sent'          => $sent,
			'opened'        => $opened,
			'opens'         => $get( 'open', 'total' ),
			'open_rate'     => $pct( $opened, $sent ),
			'clicked'       => $clicked,
			'clicks'        => $get( 'click', 'total' ),
			'click_rate'    => $pct( $clicked, $sent ),
			'click_to_open' => $pct( $clicked, $opened ),
			'bounced'       => $get( 'bounce', 'people' ),
			'soft_bounced'  => $get( 'soft_bounce', 'people' ),
			'complained'    => $get( 'complaint', 'people' ),
			'unsubscribed'  => $get( 'unsubscribe', 'people' ),
			'scanner_hits'  => $bots,
		);
	}

	/**
	 * Links by clicks.
	 *
	 * @param int $campaign_id
	 * @param int $limit
	 *
	 * @return array [ { url, people, clicks } ]
	 */
	public static function top_links( $campaign_id, $limit = 10 ) {
		global $wpdb;

		$e = self::events_table();
		$l = self::links_table();
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT l.url, COUNT(DISTINCT e.subscriber_id) AS people, COUNT(*) AS clicks FROM {$e} e JOIN {$l} l ON l.id = e.link_id WHERE e.campaign_id = %d AND e.type = 'click' AND e.bot = 0 GROUP BY e.link_id ORDER BY people DESC, clicks DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $campaign_id,
			(int) $limit
		) );
	}

	/**
	 * First opens and clicks per hour since the campaign started (up to 72 hours).
	 *
	 * @param int $campaign_id
	 *
	 * @return array [ { hour (0…), opens, clicks } ]
	 */
	public static function by_hour( $campaign_id ) {
		global $wpdb;

		$campaign = FW_CRM_Campaigns::find( $campaign_id );
		if ( ! $campaign || ! $campaign->started_at ) {
			return array();
		}
		$e     = self::events_table();
		$start = $campaign->started_at;
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT type, FLOOR(TIMESTAMPDIFF(MINUTE, %s, first_at) / 60) AS h, COUNT(*) AS n FROM (SELECT type, subscriber_id, MIN(created_at) AS first_at FROM {$e} WHERE campaign_id = %d AND bot = 0 AND type IN ('open','click') GROUP BY type, subscriber_id) t GROUP BY type, h", // phpcs:ignore WordPress.DB.PreparedSQL
			$start,
			(int) $campaign_id
		) );
		$max = 0;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r->h );
		}
		$max = min( 71, $max );
		$out = array();
		for ( $h = 0; $h <= max( 23, $max ); $h++ ) {
			$out[ $h ] = array( 'hour' => $h, 'opens' => 0, 'clicks' => 0 );
		}
		foreach ( $rows as $r ) {
			$h = (int) $r->h;
			if ( isset( $out[ $h ] ) ) {
				$out[ $h ][ 'open' === $r->type ? 'opens' : 'clicks' ] = (int) $r->n;
			}
		}
		return array_values( $out );
	}

	/**
	 * The latest events (people, not scanners).
	 *
	 * @param int $campaign_id
	 * @param int $limit
	 *
	 * @return array
	 */
	public static function recent( $campaign_id, $limit = 20 ) {
		global $wpdb;

		$e = self::events_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$e} WHERE campaign_id = %d AND bot = 0 ORDER BY created_at DESC, id DESC LIMIT %d", (int) $campaign_id, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Remove a campaign's events (when it is deleted).
	 *
	 * @param int $campaign_id
	 */
	public static function delete_for_campaign( $campaign_id ) {
		global $wpdb;

		$wpdb->delete( self::events_table(), array( 'campaign_id' => (int) $campaign_id ) );
	}
}
