<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The campaign sender — a locked, batched, resumable cron worker.
 *
 * Three facts about WP-Cron shape this entire class, and none of them are
 * optional:
 *
 *  1. **WP-Cron is not a scheduler.** It fires on page loads. A site with no
 *     traffic sends nothing; a busy site can fire the same event twice
 *     concurrently. Hence the LOCK — without it two overlapping ticks read the
 *     same pending rows and everyone gets the email twice.
 *  2. **A PHP timeout mid-batch is the normal case, not an edge case.** That is
 *     why state lives per-recipient in the queue table and each row is flipped
 *     the moment it is handled. A killed request loses at most the row in
 *     flight; the next tick picks up exactly where it stopped.
 *  3. **The real limit is the SMTP host's rate cap, not PHP.** So the batch size
 *     is a setting, and deliberately small by default.
 *
 * The lock uses `add_option()`, which is atomic because `option_name` carries a
 * UNIQUE index — two racing processes cannot both create it. A transient would
 * not do: on a site with an external object cache, `get`/`set` is not atomic and
 * both racers can win.
 *
 * Belt and braces under the lock: each batch CLAIMS its rows with one UPDATE
 * (FW_CRM_Campaigns::claim_batch()), so even two workers that both believe they
 * hold the lock cannot send to the same person. A row left claimed by a worker
 * that died is marked failed, never retried: a missed email is better than a
 * duplicate.
 *
 * Speed: a sending-rate preset (shared hosting, VPS, email service, custom)
 * caps emails across all campaigns over a rolling hour, and paces them within a
 * tick; a campaign can also have its own hourly limit, so the opens and clicks
 * of a big list arrive gradually instead of all at once.
 */
class FW_CRM_Sender {

	const CRON_HOOK  = 'fw_crm_send_tick';
	const LOCK       = 'fw_crm_send_lock';
	const LOCK_TTL   = 600; // Steal a lock older than this — the holder died.

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, '_add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, '_maybe_schedule' ) );
		// The CRM's daily housekeeping job.
		add_action( 'fw_crm_empty_bin', array( __CLASS__, 'purge_queues' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Speed
	 * ---------------------------------------------------------------------- */

	/**
	 * The sending-rate presets.
	 *
	 * @return array id => { label, per_hour, per_second }
	 */
	public static function host_presets() {
		/** Filters the CRM's sending-rate presets. */
		return (array) apply_filters( 'unysonplus_crm_send_presets', array(
			'shared'  => array( 'label' => __( 'Shared hosting', 'fw' ), 'per_hour' => 300, 'per_second' => 2 ),
			'vps'     => array( 'label' => __( 'VPS or managed hosting', 'fw' ), 'per_hour' => 1500, 'per_second' => 5 ),
			'service' => array( 'label' => __( 'An email service', 'fw' ), 'per_hour' => 36000, 'per_second' => 10 ),
		) );
	}

	/**
	 * The current limits.
	 *
	 * @return array { preset, per_hour, per_second }
	 */
	public static function rate() {
		$preset  = (string) FW_CRM_Service::setting( 'send_rate', 'shared' );
		$presets = self::host_presets();
		if ( 'custom' === $preset ) {
			$per_hour = max( 1, (int) FW_CRM_Service::setting( 'send_rate_custom', 300 ) );
			$rate     = array( 'preset' => 'custom', 'per_hour' => $per_hour, 'per_second' => max( 1, min( 20, (int) ceil( $per_hour / 3600 ) ) ) );
		} else {
			$p    = $presets[ $preset ] ?? $presets['shared'];
			$rate = array( 'preset' => isset( $presets[ $preset ] ) ? $preset : 'shared', 'per_hour' => (int) $p['per_hour'], 'per_second' => (int) $p['per_second'] );
		}
		/** Filters the effective sending rate { preset, per_hour, per_second }. */
		return (array) apply_filters( 'unysonplus_crm_send_rate', $rate );
	}

	/**
	 * How many more emails may go out right now under the hourly cap.
	 *
	 * @return int
	 */
	public static function allowance() {
		return max( 0, (int) self::rate()['per_hour'] - FW_CRM_Campaigns::sent_last_hour() );
	}

	/**
	 * Daily: clear the per-recipient rows of campaigns finished long ago.
	 *
	 * @return int Campaigns cleared.
	 */
	public static function purge_queues() {
		return FW_CRM_Campaigns::purge_queues( (int) FW_CRM_Service::setting( 'queue_retention_days', 180 ) );
	}

	/**
	 * @internal
	 *
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function _add_schedule( $schedules ) {
		if ( ! isset( $schedules['fw_crm_minute'] ) ) {
			$schedules['fw_crm_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every minute (UnysonPlus newsletter sending)', 'fw' ),
			);
		}

		return $schedules;
	}

	/**
	 * @internal
	 */
	public function _maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'fw_crm_minute', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the schedule (called when the extension's data is removed).
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}

		delete_option( self::LOCK );
	}

	/* ---------------------------------------------------------------------- *
	 * The lock
	 * ---------------------------------------------------------------------- */

	/**
	 * @return bool True when this process now holds the lock.
	 */
	public static function acquire_lock() {
		// add_option() is atomic — the UNIQUE index on option_name means exactly
		// one racer can succeed. autoload 'no' keeps it out of the alloptions cache.
		if ( add_option( self::LOCK, time(), '', 'no' ) ) {
			return true;
		}

		// Someone holds it. If they have held it implausibly long they died
		// mid-send (fatal, timeout, killed worker) — steal it, or the queue would
		// stall for ever.
		$held = (int) get_option( self::LOCK );

		if ( $held && ( time() - $held ) > self::LOCK_TTL ) {
			update_option( self::LOCK, time(), 'no' );

			return true;
		}

		return false;
	}

	public static function release_lock() {
		delete_option( self::LOCK );
	}

	/* ---------------------------------------------------------------------- *
	 * The tick
	 * ---------------------------------------------------------------------- */

	/**
	 * One pass: start anything due, then push a batch for anything sending.
	 *
	 * @return array A small report, handy for tests and WP-CLI.
	 */
	public function run() {
		$report = array( 'started' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'finished' => 0 );

		if ( ! self::acquire_lock() ) {
			return $report;
		}

		try {
			FW_CRM_Campaigns::release_stale_claims();

			foreach ( FW_CRM_Campaigns::due() as $campaign ) {
				if ( $this->start( $campaign ) ) {
					$report['started']++;
				}
			}

			$this->tick_started = microtime( true );
			$this->tick_sent    = 0;
			foreach ( FW_CRM_Campaigns::sending() as $campaign ) {
				$batch = $this->send_batch( $campaign );

				$report['sent']    += $batch['sent'];
				$report['failed']  += $batch['failed'];
				$report['skipped'] += $batch['skipped'];
				$report['finished'] += $batch['finished'] ? 1 : 0;
			}
		} finally {
			// Always release, even on a fatal-ish path — otherwise one bad send
			// wedges every future tick until the TTL expires.
			self::release_lock();
		}

		return $report;
	}

	/**
	 * Move a due campaign into sending and freeze its recipient list.
	 *
	 * @param object $campaign
	 *
	 * @return bool
	 */
	public function start( $campaign ) {
		$queued = FW_CRM_Campaigns::build_queue( $campaign );

		FW_CRM_Campaigns::update( $campaign->id, array(
			'status'     => $queued ? 'sending' : 'sent',
			'started_at' => current_time( 'mysql' ),
			'total'      => $queued,
			'finished_at' => $queued ? null : current_time( 'mysql' ),
		) );

		$campaign = FW_CRM_Campaigns::find( $campaign->id );

		/** Fired when a campaign's queue has been built and sending begins. */
		do_action( 'unysonplus_crm_campaign_started', $campaign, $queued );

		return (bool) $queued;
	}

	/**
	 * Send one batch for a campaign.
	 *
	 * @param object $campaign
	 *
	 * @return array
	 */
	public function send_batch( $campaign ) {
		$out  = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'finished' => false, 'limited' => false );
		$size = min( self::batch_size(), self::allowance() );
		if ( (int) $campaign->max_per_hour > 0 ) {
			$size = min( $size, max( 0, (int) $campaign->max_per_hour - FW_CRM_Campaigns::sent_last_hour( (int) $campaign->id ) ) );
		}
		if ( $size < 1 ) {
			// Over the hourly limit: wait for the next tick. Not finished.
			$counts          = FW_CRM_Campaigns::queue_counts( $campaign->id );
			$out['limited']  = $counts['pending'] > 0;
			$out['finished'] = ! $counts['pending'] && ! $counts['sending'];
			if ( $out['finished'] ) {
				$this->finish( $campaign );
			}
			return $out;
		}
		$rows = FW_CRM_Campaigns::claim_batch( $campaign->id, $size );

		if ( ! $rows ) {
			$counts = FW_CRM_Campaigns::queue_counts( $campaign->id );
			if ( ! $counts['pending'] && ! $counts['sending'] ) {
				$this->finish( $campaign );
				$out['finished'] = true;
			}

			return $out;
		}

		$rate = self::rate();
		foreach ( $rows as $row ) {
			// Pace within the tick, and stop well before a host's time limit; the
			// rows not reached go back to pending for the next tick.
			if ( ! $this->pace( (int) $rate['per_second'] ) ) {
				FW_CRM_Campaigns::unclaim( $row->claim );
				break;
			}
			$subscriber = FW_CRM_Subscribers::find( $row->subscriber_id );

			// Re-check at SEND time, not just at queue time. Someone can
			// unsubscribe between the queue being built and their row coming up,
			// and mailing them anyway would be exactly the complaint we must never
			// earn.
			if ( ! $subscriber || 'subscribed' !== $subscriber->status ) {
				FW_CRM_Campaigns::mark( $row->id, 'skipped', __( 'No longer subscribed at send time.', 'fw' ) );
				$out['skipped']++;
				continue;
			}

			$sent = $this->send_one( $campaign, $subscriber, $row );
			$this->tick_sent++;

			if ( is_wp_error( $sent ) ) {
				FW_CRM_Campaigns::mark( $row->id, 'failed', $sent->get_error_message() );
				$out['failed']++;
			} else {
				FW_CRM_Campaigns::mark( $row->id, 'sent' );
				$out['sent']++;
			}
		}

		$counts = FW_CRM_Campaigns::queue_counts( $campaign->id );

		FW_CRM_Campaigns::update( $campaign->id, array(
			'sent'    => $counts['sent'],
			'failed'  => $counts['failed'],
			'skipped' => $counts['skipped'],
		) );

		if ( ! $counts['pending'] && ! $counts['sending'] ) {
			$this->finish( $campaign );
			$out['finished'] = true;
		}

		return $out;
	}

	/**
	 * @param object      $campaign
	 * @param object      $subscriber
	 * @param object|null $row The queue row (none for a test send: no tracking).
	 *
	 * @return true|WP_Error
	 */
	public function send_one( $campaign, $subscriber, $row = null ) {
		$track = $row && class_exists( 'FW_CRM_Tracking' );
		if ( $track ) {
			FW_CRM_Tracking::sending( $row ); // Unsubscribe links built now carry the campaign.
		}
		$subject = FW_CRM_Mail::replace( $campaign->subject, $subscriber );
		$body    = FW_CRM_Mail::replace( self::with_unsubscribe( $campaign->body ), $subscriber );

		/** Filters the campaign email array (to, subject, body, headers) for a subscriber just before wp_mail() sends it. */
		$mail = apply_filters( 'unysonplus_crm_campaign_mail', array(
			'to'      => $subscriber->email,
			'subject' => $subject,
			'body'    => $body,
			'headers' => array_merge(
				array( 'Content-Type: text/html; charset=UTF-8' ),
				FW_CRM_Mail::unsubscribe_headers( $subscriber )
			),
		), $campaign, $subscriber );

		// Same body pipeline as every other email we send (sanitise → linkify →
		// autop ONLY if the content has no block markup of its own → email shell).
		// A campaign written in the visual editor already carries <p> tags, and
		// re-running wpautop over it double-wraps and wrecks the spacing.
		$html = FW_CRM_Mail::render_body( $mail['body'], $subscriber );
		if ( $track ) {
			$html              = FW_CRM_Tracking::instrument( $html, $row );
			$mail['headers'][] = 'Message-ID: <' . FW_CRM_Tracking::message_id( (int) $row->id ) . '>';
			FW_CRM_Tracking::sending( null );
		}
		if ( class_exists( 'FW_CRM_Smart_Links' ) ) {
			$html = FW_CRM_Smart_Links::strip( $html ); // Tag markers that tracking did not turn into tracked links.
		}
		if ( class_exists( 'FW_CRM_Senders' ) ) {
			FW_CRM_Senders::begin( 'marketing', (string) ( $campaign->sender_id ?? '' ) );
		}
		try {
			$ok = wp_mail(
				$mail['to'],
				$mail['subject'],
				$html,
				$mail['headers']
			);
		} finally {
			if ( class_exists( 'FW_CRM_Senders' ) ) {
				FW_CRM_Senders::end();
			}
		}

		return $ok ? true : new WP_Error( 'fw_crm_send_failed', __( 'wp_mail() refused the message.', 'fw' ) );
	}

	/**
	 * Every bulk email must carry a visible opt-out, so if the author forgot one
	 * we append it rather than sending mail nobody can escape.
	 *
	 * @param string $body
	 *
	 * @return string
	 */
	public static function with_unsubscribe( $body ) {
		$body = (string) $body;

		if ( false !== strpos( $body, '{{unsubscribe_url}}' ) ) {
			return $body;
		}

		$line = sprintf(
			/* translators: %s: the unsubscribe URL placeholder */
			__( 'Don\'t want these emails? Unsubscribe: %s', 'fw' ),
			'{{unsubscribe_url}}'
		);

		$html = '<p style="margin-top:2em;font-size:13px;color:#787c82">' . $line . '</p>';

		// A compiled builder email is a whole document, so the line has to go
		// INSIDE it — appended after `</html>` it lands outside the document and
		// clients are free to drop it, which would silently produce bulk mail
		// with no opt-out at all.
		if ( FW_CRM_Mail::is_document( $body ) && false !== stripos( $body, '</body>' ) ) {
			return preg_replace( '#</body>#i', $html . '</body>', $body, 1 );
		}

		// Match the body's own format. Appended plain text after a </p> renders
		// glued to the last paragraph, so an HTML body gets an HTML footer.
		if ( preg_match( '#<(p|div|table|ul|ol|h[1-6]|blockquote|figure)\b#i', $body ) ) {
			return $body . $html;
		}

		return $body . "\n\n" . $line;
	}

	/**
	 * @param object $campaign
	 */
	private function finish( $campaign ) {
		$counts = FW_CRM_Campaigns::queue_counts( $campaign->id );

		FW_CRM_Campaigns::update( $campaign->id, array(
			'status'      => 'sent',
			'finished_at' => current_time( 'mysql' ),
			'sent'        => $counts['sent'],
			'failed'      => $counts['failed'],
			'skipped'     => $counts['skipped'],
		) );

		/** Fired once a campaign's queue is drained. */
		do_action( 'unysonplus_crm_campaign_sent', FW_CRM_Campaigns::find( $campaign->id ), $counts );
	}

	/** @var float When this tick's sending began. */
	private $tick_started = 0.0;

	/** @var int Emails handed to the mailer in this tick. */
	private $tick_sent = 0;

	/**
	 * Wait as needed to stay under $per_second, and say whether there is still
	 * time in this tick (30 seconds of sending; the cron tick is one minute).
	 *
	 * @param int $per_second
	 *
	 * @return bool
	 */
	private function pace( $per_second ) {
		if ( ! $this->tick_started ) {
			$this->tick_started = microtime( true );
		}
		/** Filters how many seconds one sending tick may spend sending (default 30). */
		$budget  = (float) apply_filters( 'unysonplus_crm_tick_seconds', 30 );
		$elapsed = microtime( true ) - $this->tick_started;
		if ( $elapsed >= $budget ) {
			return false;
		}
		$due = $this->tick_sent / max( 1, $per_second );
		if ( $due > $elapsed ) {
			usleep( (int) min( 1000000, ( $due - $elapsed ) * 1000000 ) );
		}
		return true;
	}

	/**
	 * Recipients per tick. Small by default — the ceiling is the SMTP host's
	 * rate limit, not PHP's.
	 *
	 * @return int
	 */
	public static function batch_size() {
		$size = (int) FW_CRM_Service::setting( 'batch_size', 50 );

		if ( $size < 1 ) {
			$size = 50;
		}

		/** Filters the number of recipients sent per campaign tick (capped at 500), letting hosts tune against SMTP rate limits. */
		return (int) apply_filters( 'unysonplus_crm_batch_size', min( 500, $size ) );
	}
}
