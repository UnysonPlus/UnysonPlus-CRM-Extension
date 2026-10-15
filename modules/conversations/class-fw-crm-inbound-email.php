<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Replies on the timeline: email that comes back in, through
 *
 *  - an inbound address: a private URL (REST `POST /fw-crm/v1/inbound/{token}`) that an
 *    email service's inbound forwarding posts each message to, raw or parsed; or
 *  - a mailbox the CRM checks over IMAP every five minutes (only mail that arrives
 *    after it is connected).
 *
 * A message FROM a contact is logged on their timeline as "Email received" (and on
 * the deal, when it answers an email written from a deal). A message from someone
 * on the team, sent with the CRM's address in Bcc or forwarded to it, is logged as
 * sent to the contacts it went to. Each message is handled once (by Message-ID).
 */
class FW_CRM_Inbound_Email {

	const OPTION   = 'fw_crm_inbound';
	const SEEN     = 'fw_crm_inbound_seen';
	const CRON     = 'fw_crm_inbound_poll';
	const SCHEDULE = 'fw_crm_five_minutes';

	public static function init() {
		add_filter( 'cron_schedules', function ( $s ) {
			$s[ self::SCHEDULE ] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every five minutes (CRM)', 'fw' ) );
			return $s;
		} );
		add_action( self::CRON, array( __CLASS__, 'poll' ) );
		$on = self::imap_on();
		$at = wp_next_scheduled( self::CRON );
		if ( $on && ! $at ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON );
		} elseif ( ! $on && $at ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------------- */

	/**
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION );
		$s = is_array( $s ) ? $s : array();
		return array_merge( array(
			'token'          => '',
			'imap_on'        => false,
			'host'           => '',
			'port'           => 993,
			'security'       => 'ssl',
			'user'           => '',
			'pass'           => '',
			'folder'         => 'INBOX',
			'uidvalidity'    => 0,
			'last_uid'       => 0,
			'last_run'       => '',
			'last_result'    => '',
			'create_unknown' => false,
		), $s );
	}

	/**
	 * @param array $changes
	 */
	public static function save_settings( array $changes ) {
		update_option( self::OPTION, array_merge( self::settings(), $changes ), false );
	}

	/** @return bool */
	public static function imap_on() {
		$s = self::settings();
		return $s['imap_on'] && '' !== $s['host'] && '' !== $s['user'];
	}

	/** @return string The inbound token (created on first use). */
	public static function token() {
		$s = self::settings();
		if ( '' === $s['token'] ) {
			$s['token'] = wp_generate_password( 32, false );
			self::save_settings( array( 'token' => $s['token'] ) );
		}
		return $s['token'];
	}

	/** @return string */
	public static function inbound_url() {
		return rest_url( 'fw-crm/v1/inbound/' . self::token() );
	}

	/** A new token: the old address stops working. */
	public static function new_token() {
		self::save_settings( array( 'token' => wp_generate_password( 32, false ) ) );
	}

	/**
	 * Encrypt a mailbox password with the site's salts.
	 *
	 * @param string $plain
	 *
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === (string) $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return (string) $plain;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( (string) $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * @param string $stored
	 *
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Receiving
	 * ---------------------------------------------------------------------- */

	/**
	 * A Message-ID for an email the CRM sends, carrying the contact and deal so a
	 * reply can be filed against both.
	 *
	 * @param int $contact_id
	 * @param int $deal_id
	 *
	 * @return string Without angle brackets.
	 */
	public static function message_id( $contact_id, $deal_id = 0 ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return sprintf( 'fwcrm-c%d-d%d-%s@%s', (int) $contact_id, (int) $deal_id, strtolower( wp_generate_password( 12, false ) ), $host ? $host : 'localhost' );
	}

	/**
	 * Handle one incoming message.
	 *
	 * @param array  $m      { from_email, from_name, to[], cc[], subject, text, html, message_id, in_reply_to, references[], attachments }
	 * @param string $source inbound | imap
	 *
	 * @return array { status: received|sent|unknown|duplicate|ignored, contacts: ids, deal: id }
	 */
	public static function receive( array $m, $source = 'inbound' ) {
		$m = array_merge( array( 'from_email' => '', 'from_name' => '', 'to' => array(), 'cc' => array(), 'subject' => '', 'text' => '', 'html' => '', 'message_id' => '', 'in_reply_to' => '', 'references' => array(), 'attachments' => 0, 'reports' => array(), 'report_type' => '' ), $m );
		$from = strtolower( sanitize_email( (string) $m['from_email'] ) );
		if ( ! is_email( $from ) ) {
			return array( 'status' => 'ignored', 'contacts' => array(), 'deal' => 0 );
		}
		if ( self::seen( (string) $m['message_id'] ) ) {
			return array( 'status' => 'duplicate', 'contacts' => array(), 'deal' => 0 );
		}
		// A bounce or a spam complaint about a campaign email (email marketing module).
		if ( class_exists( 'FW_CRM_Tracking' ) ) {
			$report = FW_CRM_Tracking::handle_report( $m );
			if ( $report ) {
				self::remember( (string) $m['message_id'] );
				return array( 'status' => $report['status'], 'contacts' => $report['contact_id'] ? array( $report['contact_id'] ) : array(), 'deal' => 0 );
			}
		}

		$subject = '' !== trim( (string) $m['subject'] ) ? trim( (string) $m['subject'] ) : __( '(no subject)', 'fw' );
		$text    = '' !== trim( (string) $m['text'] ) ? (string) $m['text'] : FW_CRM_Mime::html_to_text( (string) $m['html'] );
		$reply   = FW_CRM_Mime::reply_part( $text );
		$reply   = '' !== $reply ? $reply : trim( $text );
		if ( function_exists( 'mb_substr' ) ) {
			$reply = mb_substr( $reply, 0, 5000 );
		}
		$meta = array(
			'from'        => $from,
			'subject'     => $subject,
			'message_id'  => (string) $m['message_id'],
			'in_reply_to' => (string) $m['in_reply_to'],
			'attachments' => (int) $m['attachments'],
			'source'      => $source,
		);

		// From someone on the team: an email they sent, copied in by Bcc or forwarding.
		$user = get_user_by( 'email', $from );
		if ( $user && user_can( $user, FW_CRM_Service::capability() ) ) {
			$logged = array();
			foreach ( array_unique( array_merge( (array) $m['to'], (array) $m['cc'] ) ) as $addr ) {
				$c = FW_CRM_Subscribers::find_by_email( strtolower( (string) $addr ) );
				if ( $c && ! $c->deleted_at ) {
					FW_CRM_Activity::log( 'contact', (int) $c->id, 'email', sprintf( __( 'Email sent: %s', 'fw' ), $subject ), array(
						'body'     => esc_html( $reply ),
						'meta'     => array_merge( $meta, array( 'direction' => 'out', 'to' => $c->email ) ),
						'actor_id' => (int) $user->ID,
					) );
					$logged[] = (int) $c->id;
				}
			}
			self::remember( (string) $m['message_id'] );
			return array( 'status' => $logged ? 'sent' : 'unknown', 'contacts' => $logged, 'deal' => 0 );
		}

		// From a contact (or someone new).
		$c = FW_CRM_Subscribers::find_by_email( $from );
		if ( $c && $c->deleted_at ) {
			$c = null;
		}
		if ( ! $c && self::settings()['create_unknown'] ) {
			$name = trim( (string) $m['from_name'] );
			$parts = '' !== $name ? preg_split( '/\s+/', $name, 2 ) : array( '', '' );
			$id    = FW_CRM_Contacts::create( array( 'email' => $from, 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'source' => 'email' ) );
			$c     = is_wp_error( $id ) ? null : FW_CRM_Subscribers::find( $id );
		}
		if ( ! $c ) {
			self::remember( (string) $m['message_id'] );
			return array( 'status' => 'unknown', 'contacts' => array(), 'deal' => 0 );
		}

		$deal = self::thread_deal( $m, (int) $c->id );
		FW_CRM_Activity::log( 'contact', (int) $c->id, 'email_in', sprintf( __( 'Email received: %s', 'fw' ), $subject ), array(
			'body'     => esc_html( $reply ),
			'meta'     => array_merge( $meta, array( 'direction' => 'in', 'deal_id' => $deal ) ),
			'actor_id' => 0,
		) );
		if ( $deal ) {
			$who = trim( $c->first_name . ' ' . $c->last_name );
			FW_CRM_Activity::log( 'deal', $deal, 'email_in', sprintf( __( 'Reply from %1$s: %2$s', 'fw' ), '' !== $who ? $who : $c->email, $subject ), array(
				'body'     => esc_html( $reply ),
				'meta'     => array_merge( $meta, array( 'direction' => 'in', 'contact_id' => (int) $c->id ) ),
				'actor_id' => 0,
			) );
		}
		self::remember( (string) $m['message_id'] );

		/**
		 * Fires when a contact's email reaches the timeline.
		 *
		 * @param int   $contact_id
		 * @param array $message
		 * @param int   $deal_id 0 when it is not part of a deal's thread.
		 */
		do_action( 'unysonplus_crm_email_received', (int) $c->id, $m, $deal );

		/** Automations: a contact replied (see "Automation registration" in the docs). */
		do_action( 'unysonplus_crm_automation_event', 'crm.email_replied', (int) $c->id, array(
			'subject' => $subject,
			'deal_id' => $deal,
			'dedupe'  => 'reply:' . ( '' !== (string) $m['message_id'] ? $m['message_id'] : md5( $from . $subject . $reply ) ),
		) );

		return array( 'status' => 'received', 'contacts' => array( (int) $c->id ), 'deal' => $deal );
	}

	/**
	 * The deal a reply belongs to, from the Message-ID of the CRM email it answers.
	 *
	 * @param array $m
	 * @param int   $contact_id
	 *
	 * @return int
	 */
	private static function thread_deal( array $m, $contact_id ) {
		if ( ! class_exists( 'FW_CRM_Deals' ) ) {
			return 0;
		}
		foreach ( array_merge( array( (string) $m['in_reply_to'] ), (array) $m['references'] ) as $ref ) {
			if ( preg_match( '/^fwcrm-c(\d+)-d(\d+)-/', (string) $ref, $x ) && (int) $x[2] ) {
				$d = FW_CRM_Deals::get( (int) $x[2] );
				if ( $d && in_array( (int) $contact_id, $d['contact_ids'], true ) ) {
					return (int) $x[2];
				}
			}
		}
		return 0;
	}

	/**
	 * @param string $message_id
	 *
	 * @return bool
	 */
	private static function seen( $message_id ) {
		if ( '' === $message_id ) {
			return false;
		}
		return in_array( md5( $message_id ), (array) get_option( self::SEEN, array() ), true );
	}

	/**
	 * @param string $message_id
	 */
	private static function remember( $message_id ) {
		if ( '' === $message_id ) {
			return;
		}
		$seen   = (array) get_option( self::SEEN, array() );
		$seen[] = md5( $message_id );
		update_option( self::SEEN, array_slice( array_values( array_unique( $seen ) ), -1000 ), false );
	}

	/**
	 * Read a request to the inbound address into a message: a raw MIME message (in a
	 * `mime` / `body-mime` / `email` / `raw` field, or as the request body), or the
	 * parsed fields email services post.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|null
	 */
	public static function from_request( $request ) {
		$p = array_merge( (array) $request->get_body_params(), (array) $request->get_json_params(), (array) $request->get_query_params() );
		foreach ( array( 'mime', 'body-mime', 'email', 'raw', 'RawEmail' ) as $k ) {
			if ( ! empty( $p[ $k ] ) && is_string( $p[ $k ] ) ) {
				return FW_CRM_Mime::parse( $p[ $k ] );
			}
		}
		$ct   = $request->get_content_type();
		$type = is_array( $ct ) ? strtolower( (string) $ct['value'] ) : '';
		$body = (string) $request->get_body();
		if ( 'message/rfc822' === $type || ( '' !== $body && preg_match( '/^(Return-Path|Received|From|Message-ID|Delivered-To|MIME-Version|Date):/mi', substr( $body, 0, 2000 ) ) && ! $p ) ) {
			return FW_CRM_Mime::parse( $body );
		}
		if ( ! $p ) {
			return null;
		}
		$get = function ( array $keys ) use ( $p ) {
			foreach ( $keys as $k ) {
				if ( isset( $p[ $k ] ) && '' !== $p[ $k ] ) {
					return $p[ $k ];
				}
			}
			return '';
		};
		// Header lists some services send: [ { Name, Value } ] or a raw block.
		$headers = array();
		if ( ! empty( $p['Headers'] ) && is_array( $p['Headers'] ) ) {
			foreach ( $p['Headers'] as $h ) {
				if ( isset( $h['Name'], $h['Value'] ) ) {
					$headers[ strtolower( $h['Name'] ) ] = $h['Value'];
				}
			}
		} elseif ( ! empty( $p['headers'] ) && is_string( $p['headers'] ) ) {
			$headers = FW_CRM_Mime::headers( str_replace( "\r\n", "\n", $p['headers'] ) );
		}
		$from_full = isset( $p['FromFull'] ) && is_array( $p['FromFull'] ) ? $p['FromFull'] : array();
		$from      = FW_CRM_Mime::addresses( (string) ( $from_full['Email'] ?? $get( array( 'sender', 'from', 'From' ) ) ) );
		$ids       = function ( $v ) {
			preg_match_all( '/<([^>]+)>/', (string) $v, $mm );
			return $mm[1] ? $mm[1] : ( '' !== trim( (string) $v ) ? array( trim( (string) $v ) ) : array() );
		};
		$reply_to  = $ids( $get( array( 'In-Reply-To', 'in_reply_to', 'in-reply-to' ) ) ?: ( $headers['in-reply-to'] ?? '' ) );
		$msgid     = $ids( $get( array( 'Message-Id', 'Message-ID', 'MessageID', 'message_id', 'message-id' ) ) ?: ( $headers['message-id'] ?? '' ) );
		return array(
			'from_email'  => $from ? $from[0]['email'] : '',
			'from_name'   => (string) ( $from_full['Name'] ?? ( $from ? $from[0]['name'] : '' ) ),
			'to'          => wp_list_pluck( FW_CRM_Mime::addresses( (string) $get( array( 'recipient', 'to', 'To' ) ) ), 'email' ),
			'cc'          => wp_list_pluck( FW_CRM_Mime::addresses( (string) $get( array( 'cc', 'Cc' ) ) ), 'email' ),
			'subject'     => (string) $get( array( 'subject', 'Subject' ) ),
			'text'        => (string) $get( array( 'stripped-text', 'StrippedTextReply', 'body-plain', 'text', 'TextBody', 'plain' ) ),
			'html'        => (string) $get( array( 'stripped-html', 'body-html', 'html', 'HtmlBody' ) ),
			'message_id'  => $msgid ? $msgid[0] : '',
			'in_reply_to' => $reply_to ? $reply_to[0] : '',
			'references'  => $ids( $get( array( 'References', 'references' ) ) ?: ( $headers['references'] ?? '' ) ),
			'attachments' => (int) $get( array( 'attachment-count', 'attachments' ) ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * The mailbox
	 * ---------------------------------------------------------------------- */

	/**
	 * Connect and open the folder.
	 *
	 * @param array $s Settings (defaults to the saved ones).
	 *
	 * @return FW_CRM_Imap|WP_Error The client, with the folder's state in ->box.
	 */
	public static function open( array $s = array() ) {
		$s    = $s ? $s : self::settings();
		$imap = new FW_CRM_Imap();
		if ( ! $imap->connect( (string) $s['host'], (int) $s['port'], (string) $s['security'] ) || ! $imap->login( (string) $s['user'], self::decrypt( (string) $s['pass'] ) ) ) {
			$err = $imap->error;
			$imap->logout();
			return new WP_Error( 'fw_crm_imap', $err );
		}
		$box = $imap->select( '' !== (string) $s['folder'] ? (string) $s['folder'] : 'INBOX' );
		if ( false === $box ) {
			$err = $imap->error;
			$imap->logout();
			return new WP_Error( 'fw_crm_imap', $err );
		}
		$imap->box = $box;
		return $imap;
	}

	/**
	 * Check the mailbox for new mail (the cron job, or "Check now").
	 *
	 * @param int $max Messages per run.
	 *
	 * @return array|WP_Error { checked, received, sent, unknown }
	 */
	public static function poll( $max = 25 ) {
		if ( ! self::imap_on() ) {
			return new WP_Error( 'fw_crm_imap_off', __( 'No mailbox is connected.', 'fw' ) );
		}
		if ( get_transient( 'fw_crm_inbound_lock' ) ) {
			return new WP_Error( 'fw_crm_busy', __( 'The mailbox is already being checked.', 'fw' ) );
		}
		set_transient( 'fw_crm_inbound_lock', 1, 4 * MINUTE_IN_SECONDS );
		$s    = self::settings();
		$imap = self::open( $s );
		$out  = array( 'checked' => 0, 'received' => 0, 'sent' => 0, 'unknown' => 0 );
		if ( is_wp_error( $imap ) ) {
			self::save_settings( array( 'last_run' => current_time( 'mysql' ), 'last_result' => $imap->get_error_message() ) );
			delete_transient( 'fw_crm_inbound_lock' );
			return $imap;
		}
		$box = $imap->box;
		// First check, or the folder was rebuilt: start from now, never import old mail.
		if ( ! $s['last_uid'] || (int) $s['uidvalidity'] !== (int) $box['uidvalidity'] ) {
			$start = max( 0, (int) $box['uidnext'] - 1 );
			self::save_settings( array( 'uidvalidity' => (int) $box['uidvalidity'], 'last_uid' => $start, 'last_run' => current_time( 'mysql' ), 'last_result' => __( 'Connected. New mail from now on will be read.', 'fw' ) ) );
			$imap->logout();
			delete_transient( 'fw_crm_inbound_lock' );
			return $out;
		}
		$uids = $imap->uids_after( (int) $s['last_uid'] );
		$last = (int) $s['last_uid'];
		foreach ( array_slice( (array) $uids, 0, (int) $max ) as $uid ) {
			$raw = $imap->fetch( $uid );
			if ( false !== $raw && '' !== $raw ) {
				$r = self::receive( FW_CRM_Mime::parse( $raw ), 'imap' );
				$out['checked']++;
				if ( isset( $out[ $r['status'] ] ) ) {
					$out[ $r['status'] ]++;
				}
			}
			$last = max( $last, (int) $uid );
		}
		$imap->logout();
		self::save_settings( array(
			'last_uid'    => $last,
			'last_run'    => current_time( 'mysql' ),
			'last_result' => sprintf( __( '%1$s new: %2$s replies, %3$s sent by the team, %4$s from unknown senders.', 'fw' ), number_format_i18n( $out['checked'] ), number_format_i18n( $out['received'] ), number_format_i18n( $out['sent'] ), number_format_i18n( $out['unknown'] ) ),
		) );
		delete_transient( 'fw_crm_inbound_lock' );
		return $out;
	}
}
