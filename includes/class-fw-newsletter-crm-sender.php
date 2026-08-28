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
 */
class FW_Newsletter_CRM_Sender {

	const CRON_HOOK  = 'fw_crm_send_tick';
	const LOCK       = 'fw_crm_send_lock';
	const LOCK_TTL   = 600; // Steal a lock older than this — the holder died.

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, '_add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, '_maybe_schedule' ) );
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
			foreach ( FW_Newsletter_CRM_Campaigns::due() as $campaign ) {
				if ( $this->start( $campaign ) ) {
					$report['started']++;
				}
			}

			foreach ( FW_Newsletter_CRM_Campaigns::sending() as $campaign ) {
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
		$queued = FW_Newsletter_CRM_Campaigns::build_queue( $campaign );

		FW_Newsletter_CRM_Campaigns::update( $campaign->id, array(
			'status'     => $queued ? 'sending' : 'sent',
			'started_at' => current_time( 'mysql' ),
			'total'      => $queued,
			'finished_at' => $queued ? null : current_time( 'mysql' ),
		) );

		$campaign = FW_Newsletter_CRM_Campaigns::find( $campaign->id );

		/** Fired when a campaign's queue has been built and sending begins. */
		do_action( 'unysonplus_newsletter_crm_campaign_started', $campaign, $queued );

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
		$out   = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'finished' => false );
		$size  = self::batch_size();
		$rows  = FW_Newsletter_CRM_Campaigns::next_batch( $campaign->id, $size );

		if ( ! $rows ) {
			$this->finish( $campaign );
			$out['finished'] = true;

			return $out;
		}

		foreach ( $rows as $row ) {
			$subscriber = FW_Newsletter_CRM_Subscribers::find( $row->subscriber_id );

			// Re-check at SEND time, not just at queue time. Someone can
			// unsubscribe between the queue being built and their row coming up,
			// and mailing them anyway would be exactly the complaint we must never
			// earn.
			if ( ! $subscriber || 'subscribed' !== $subscriber->status ) {
				FW_Newsletter_CRM_Campaigns::mark( $row->id, 'skipped', __( 'No longer subscribed at send time.', 'fw' ) );
				$out['skipped']++;
				continue;
			}

			$sent = $this->send_one( $campaign, $subscriber );

			if ( is_wp_error( $sent ) ) {
				FW_Newsletter_CRM_Campaigns::mark( $row->id, 'failed', $sent->get_error_message() );
				$out['failed']++;
			} else {
				FW_Newsletter_CRM_Campaigns::mark( $row->id, 'sent' );
				$out['sent']++;
			}
		}

		$counts = FW_Newsletter_CRM_Campaigns::queue_counts( $campaign->id );

		FW_Newsletter_CRM_Campaigns::update( $campaign->id, array(
			'sent'   => $counts['sent'],
			'failed' => $counts['failed'],
		) );

		if ( ! $counts['pending'] ) {
			$this->finish( $campaign );
			$out['finished'] = true;
		}

		return $out;
	}

	/**
	 * @param object $campaign
	 * @param object $subscriber
	 *
	 * @return true|WP_Error
	 */
	public function send_one( $campaign, $subscriber ) {
		$subject = FW_Newsletter_CRM_Mail::replace( $campaign->subject, $subscriber );
		$body    = FW_Newsletter_CRM_Mail::replace( self::with_unsubscribe( $campaign->body ), $subscriber );

		/** Filters the campaign email array (to, subject, body, headers) for a subscriber just before wp_mail() sends it. */
		$mail = apply_filters( 'unysonplus_newsletter_crm_campaign_mail', array(
			'to'      => $subscriber->email,
			'subject' => $subject,
			'body'    => $body,
			'headers' => array_merge(
				array( 'Content-Type: text/html; charset=UTF-8' ),
				FW_Newsletter_CRM_Mail::unsubscribe_headers( $subscriber )
			),
		), $campaign, $subscriber );

		// Same body pipeline as every other email we send (sanitise → linkify →
		// autop ONLY if the content has no block markup of its own → email shell).
		// A campaign written in the visual editor already carries <p> tags, and
		// re-running wpautop over it double-wraps and wrecks the spacing.
		$ok = wp_mail(
			$mail['to'],
			$mail['subject'],
			FW_Newsletter_CRM_Mail::render_body( $mail['body'], $subscriber ),
			$mail['headers']
		);

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
		if ( FW_Newsletter_CRM_Mail::is_document( $body ) && false !== stripos( $body, '</body>' ) ) {
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
		$counts = FW_Newsletter_CRM_Campaigns::queue_counts( $campaign->id );

		FW_Newsletter_CRM_Campaigns::update( $campaign->id, array(
			'status'      => 'sent',
			'finished_at' => current_time( 'mysql' ),
			'sent'        => $counts['sent'],
			'failed'      => $counts['failed'],
		) );

		/** Fired once a campaign's queue is drained. */
		do_action( 'unysonplus_newsletter_crm_campaign_sent', FW_Newsletter_CRM_Campaigns::find( $campaign->id ), $counts );
	}

	/**
	 * Recipients per tick. Small by default — the ceiling is the SMTP host's
	 * rate limit, not PHP's.
	 *
	 * @return int
	 */
	public static function batch_size() {
		$size = (int) FW_Newsletter_CRM_Service::setting( 'batch_size', 50 );

		if ( $size < 1 ) {
			$size = 50;
		}

		/** Filters the number of recipients sent per campaign tick (capped at 500), letting hosts tune against SMTP rate limits. */
		return (int) apply_filters( 'unysonplus_newsletter_crm_batch_size', min( 500, $size ) );
	}
}
