<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Campaigns repository (DAO) — campaigns and their per-recipient send queue.
 *
 * Same contract as the other repositories: the only place that writes SQL about
 * these two tables, no business rules, no hooks. The rules (who is eligible,
 * when a send may start, what happens on failure) live in the service and the
 * sender.
 */
class FW_CRM_Campaigns {

	/**
	 * draft      — being written.
	 * scheduled  — waiting for scheduled_at.
	 * sending    — queue built, batches going out.
	 * paused     — stopped mid-send; the queue is intact and resumable.
	 * sent       — queue drained.
	 */
	const STATUSES = array( 'draft', 'scheduled', 'sending', 'paused', 'sent' );

	/** @return string */
	private static function table() {
		return FW_CRM_Installer::table( 'campaigns' );
	}

	/** @return string */
	private static function queue_table() {
		return FW_CRM_Installer::table( 'campaign_queue' );
	}

	/* ---------------------------------------------------------------------- *
	 * Campaigns
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args status, per_page, paged
	 *
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		$where = '1=1';

		if ( ! empty( $args['status'] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC";

		if ( ! empty( $args['per_page'] ) ) {
			$per  = (int) $args['per_page'];
			$page = max( 1, isset( $args['paged'] ) ? (int) $args['paged'] : 1 );
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per );
		}

		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Campaigns whose moment has come — scheduled and due.
	 *
	 * @return array
	 */
	public static function due() {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = %s AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY scheduled_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			'scheduled',
			current_time( 'mysql' )
		) );
	}

	/**
	 * Campaigns with a queue still draining.
	 *
	 * @return array
	 */
	public static function sending() {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = %s ORDER BY started_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			'sending'
		) );
	}

	/**
	 * @param array $data
	 *
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array_merge( self::sanitize( $data ), array(
			'created_at' => $now,
			'updated_at' => $now,
		) );

		$ok = $wpdb->insert( self::table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$row = self::sanitize( $data );

		if ( ! $row ) {
			return false;
		}

		$row['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	private static function sanitize( array $data ) {
		$row = array();

		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['subject'] ) ) {
			$row['subject'] = sanitize_text_field( $data['subject'] );
		}

		if ( isset( $data['body'] ) ) {
			// wp_kses_post, not sanitize_textarea_field — a campaign body is HTML
			// on purpose, but must not be able to carry script.
			//
			// EXCEPT when the body is a complete document compiled by the Email
			// Builder. `wp_kses_post()` strips `<style>` (which dumped the
			// mobile-stacking CSS into the stored body as visible text) and
			// entity-escapes the Outlook conditional comments that carry the
			// ghost tables and the VML button fallback — so a builder campaign
			// was being MANGLED ON SAVE, before the sender ever saw it.
			//
			// Nothing is trusted that was not already sanitised: a compiled
			// document is assembled by our own compiler out of per-block option
			// values, each of which went through the option type's own
			// sanitisation on the way in. The author never hands us this string.
			// Anything else — a body typed into the visual editor — still goes
			// through kses, which is where author markup actually arrives.
			$row['body'] = FW_CRM_Mail::is_document( $data['body'] )
				? (string) $data['body']
				: wp_kses_post( $data['body'] );
		}

		if ( isset( $data['body_json'] ) ) {
			// Already JSON-encoded by the service; stored verbatim so the builder
			// gets back exactly the tree it saved.
			$row['body_json'] = is_array( $data['body_json'] )
				? wp_json_encode( $data['body_json'] )
				: (string) $data['body_json'];
		}

		if ( isset( $data['audience'] ) ) {
			$row['audience'] = is_array( $data['audience'] )
				? wp_json_encode( $data['audience'] )
				: (string) $data['audience'];
		}

		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$row['status'] = $data['status'];
		}

		foreach ( array( 'scheduled_at', 'started_at', 'finished_at', 'queue_purged_at' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ] ? $data[ $key ] : null;
			}
		}

		foreach ( array( 'total', 'sent', 'failed', 'skipped', 'max_per_hour' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$row[ $key ] = max( 0, (int) $data[ $key ] );
			}
		}

		if ( isset( $data['sender_id'] ) ) {
			$row['sender_id'] = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $data['sender_id'] ) ), 0, 20 );
		}

		return $row;
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::queue_table(), array( 'campaign_id' => (int) $id ), array( '%d' ) );
		if ( class_exists( 'FW_CRM_Tracking' ) ) {
			FW_CRM_Tracking::delete_for_campaign( (int) $id );
		}

		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Decode the stored audience into subscriber query args.
	 *
	 * @param object $campaign
	 *
	 * @return array
	 */
	public static function audience_args( $campaign ) {
		$args = array();

		if ( ! empty( $campaign->audience ) ) {
			$decoded = json_decode( $campaign->audience, true );
			$args    = is_array( $decoded ) ? $decoded : array();
		}

		// A campaign ALWAYS goes to confirmed subscribers only. Pending people
		// never agreed, and unsubscribed/bounced/complained ones must never be
		// mailed again — so this is forced, not merely defaulted.
		$args['status'] = 'subscribed';

		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Queue
	 * ---------------------------------------------------------------------- */

	/**
	 * Materialise the recipient list into the queue.
	 *
	 * The audience is a live query, so it is resolved ONCE here — at send time —
	 * and frozen into rows. That is deliberate: a send must have a fixed,
	 * countable recipient set, or progress is meaningless and someone added
	 * mid-send might get the mail while someone removed might already have.
	 *
	 * @param object $campaign
	 *
	 * @return int Rows queued.
	 */
	public static function build_queue( $campaign ) {
		global $wpdb;

		$args = self::audience_args( $campaign );
		$args['per_page'] = 0; // everything

		$subscribers = FW_CRM_Subscribers::query( $args );
		$queue       = self::queue_table();
		$now         = current_time( 'mysql' );
		$queued      = 0;

		foreach ( array_chunk( $subscribers, 200 ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $subscriber ) {
				$values[] = $wpdb->prepare(
					'(%d, %d, %s, %s, %s)',
					$campaign->id,
					$subscriber->id,
					$subscriber->email,
					'pending',
					$now
				);
			}

			if ( ! $values ) {
				continue;
			}

			// INSERT IGNORE against the UNIQUE (campaign_id, subscriber_id) index:
			// rebuilding a queue can never duplicate a recipient.
			$queued += (int) $wpdb->query(
				"INSERT IGNORE INTO {$queue} (campaign_id, subscriber_id, email, status, created_at) VALUES " // phpcs:ignore WordPress.DB.PreparedSQL
				. implode( ',', $values )
			);
		}

		return $queued;
	}

	/**
	 * Pending rows, without claiming them (previews and tests).
	 *
	 * @param int $campaign_id
	 * @param int $limit
	 *
	 * @return array Pending queue rows.
	 */
	public static function next_batch( $campaign_id, $limit ) {
		global $wpdb;

		$queue = self::queue_table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$queue} WHERE campaign_id = %d AND status = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $campaign_id,
			'pending',
			(int) $limit
		) );
	}

	/**
	 * Claim up to $limit pending rows for this worker, in one UPDATE.
	 *
	 * The UPDATE is the claim: MySQL lets only one statement flip a given row from
	 * pending to sending, so two workers that overlap (a stolen lock, a manual
	 * "run now" during a cron tick) can never pick up the same recipient.
	 *
	 * @param int $campaign_id
	 * @param int $limit
	 *
	 * @return array The claimed rows.
	 */
	public static function claim_batch( $campaign_id, $limit ) {
		global $wpdb;

		if ( $limit < 1 ) {
			return array();
		}
		$queue = self::queue_table();
		$token = wp_generate_password( 24, false );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$queue} SET status = 'sending', claim = %s, claimed_at = %s WHERE campaign_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$token,
			current_time( 'mysql' ),
			(int) $campaign_id,
			(int) $limit
		) );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$queue} WHERE claim = %s ORDER BY id ASC", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Hand back the rows of a claim that were not reached (out of time).
	 *
	 * @param string $token
	 *
	 * @return int
	 */
	public static function unclaim( $token ) {
		global $wpdb;

		$queue = self::queue_table();
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'pending', claim = '', claimed_at = NULL WHERE claim = %s AND status = 'sending'", (string) $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Rows claimed long ago and never marked: the worker died mid-send. Whether
	 * that email left is unknown, so it is NOT retried (a duplicate is worse than
	 * a miss); it is marked failed with the reason.
	 *
	 * @param int $minutes
	 *
	 * @return int Rows released.
	 */
	public static function release_stale_claims( $minutes = 15 ) {
		global $wpdb;

		$queue = self::queue_table();
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$queue} SET status = 'failed', error = %s, sent_at = %s WHERE status = 'sending' AND claimed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL
			__( 'Interrupted while sending. Not retried, so nobody gets it twice.', 'fw' ),
			current_time( 'mysql' ),
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - (int) $minutes * MINUTE_IN_SECONDS ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		) );
	}

	/**
	 * Emails handed to the mailer in the last hour (all campaigns, or one).
	 *
	 * @param int $campaign_id 0 = every campaign.
	 *
	 * @return int
	 */
	public static function sent_last_hour( $campaign_id = 0 ) {
		global $wpdb;

		$queue = self::queue_table();
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$sql   = $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE status IN ('sent','failed') AND sent_at >= %s", $since ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $campaign_id ) {
			$sql .= $wpdb->prepare( ' AND campaign_id = %d', (int) $campaign_id );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Clear the per-recipient rows of campaigns that finished more than $days
	 * ago. The campaign keeps its totals (total, sent, failed, skipped).
	 *
	 * @param int $days
	 *
	 * @return int Campaigns cleared.
	 */
	public static function purge_queues( $days ) {
		global $wpdb;

		if ( (int) $days < 1 ) {
			return 0;
		}
		$table  = self::table();
		$before = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - (int) $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'sent' AND queue_purged_at IS NULL AND finished_at IS NOT NULL AND finished_at < %s LIMIT 50", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $ids as $id ) {
			$c = self::queue_counts( (int) $id );
			if ( $c['total'] ) {
				self::update( (int) $id, array( 'sent' => $c['sent'], 'failed' => $c['failed'], 'skipped' => $c['skipped'] ) );
			}
			self::clear_queue( (int) $id );
			self::update( (int) $id, array( 'queue_purged_at' => current_time( 'mysql' ) ) );
		}
		return count( $ids );
	}

	/**
	 * @param int    $queue_id
	 * @param string $status sent|failed|skipped
	 * @param string $error
	 *
	 * @return bool
	 */
	public static function mark( $queue_id, $status, $error = '' ) {
		global $wpdb;

		return false !== $wpdb->update(
			self::queue_table(),
			array(
				'status'  => $status,
				'error'   => substr( (string) $error, 0, 255 ),
				'sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $queue_id )
		);
	}

	/**
	 * @param int $campaign_id
	 *
	 * @return array [ status => count ]
	 */
	public static function queue_counts( $campaign_id ) {
		global $wpdb;

		$queue = self::queue_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS total FROM {$queue} WHERE campaign_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $campaign_id
		) );

		$out = array( 'pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0 );

		foreach ( (array) $rows as $row ) {
			$out[ $row->status ] = (int) $row->total;
			$out['total']       += (int) $row->total;
		}

		return $out;
	}

	/**
	 * @param int $campaign_id
	 *
	 * @return bool
	 */
	public static function clear_queue( $campaign_id ) {
		global $wpdb;

		return false !== $wpdb->delete( self::queue_table(), array( 'campaign_id' => (int) $campaign_id ), array( '%d' ) );
	}
}
