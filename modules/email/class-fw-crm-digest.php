<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Post digest: a recurring email of the latest posts, sent to a list, tag or segment.
 *
 * Each time it is due (weekly on a chosen day, or monthly on the 1st, after a chosen hour)
 * it collects the posts published since the last digest and, if there are any, creates an
 * ordinary campaign from them and queues it. Reusing campaigns means the digest gets the
 * batched sender, tracking, the unsubscribe line and a report for free, and every digest
 * stays in the Campaigns list. A week with no new posts sends nothing.
 */
class FW_CRM_Digest {

	const OPTION    = 'fw_crm_digest';
	const CRON_HOOK = 'fw_crm_digest_tick';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send' ) );
		if ( self::settings()['on'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/** @return array */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( array(
			'on'        => false,
			'frequency' => 'weekly',
			'weekday'   => 1,
			'hour'      => 9,
			'list'      => '',
			'tag'       => '',
			'segment'   => 0,
			'subject'   => __( 'This week on {{site_name}}', 'fw' ),
			'intro'     => __( 'Here is what we published since the last email.', 'fw' ),
			'count'     => 5,
			'post_type' => 'post',
			'sender_id' => '',
			'last_sent' => '',
		), is_array( $s ) ? $s : array() );
	}

	/** @param array $changes */
	public static function save_settings( array $changes ) {
		$s = self::settings();
		$s['on']        = ! empty( $changes['on'] );
		$s['frequency'] = isset( $changes['frequency'] ) && 'monthly' === $changes['frequency'] ? 'monthly' : 'weekly';
		$s['weekday']   = isset( $changes['weekday'] ) ? max( 1, min( 7, (int) $changes['weekday'] ) ) : $s['weekday'];
		$s['hour']      = isset( $changes['hour'] ) ? max( 0, min( 23, (int) $changes['hour'] ) ) : $s['hour'];
		$s['list']      = sanitize_key( (string) ( $changes['list'] ?? '' ) );
		$s['tag']       = sanitize_key( (string) ( $changes['tag'] ?? '' ) );
		$s['segment']   = (int) ( $changes['segment'] ?? 0 );
		$s['subject']   = sanitize_text_field( (string) ( $changes['subject'] ?? $s['subject'] ) );
		$s['intro']     = sanitize_textarea_field( (string) ( $changes['intro'] ?? $s['intro'] ) );
		$s['count']     = max( 1, min( 20, (int) ( $changes['count'] ?? 5 ) ) );
		$s['post_type'] = post_type_exists( (string) ( $changes['post_type'] ?? '' ) ) ? (string) $changes['post_type'] : 'post';
		$s['sender_id'] = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $changes['sender_id'] ?? '' ) ) );
		update_option( self::OPTION, $s, false );

		if ( $s['on'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		} elseif ( ! $s['on'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Is a digest due now?
	 *
	 * @param array    $s
	 * @param int|null $now Site-local timestamp.
	 *
	 * @return bool
	 */
	public static function is_due( array $s, $now = null ) {
		$now = null === $now ? current_time( 'timestamp' ) : (int) $now; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		if ( ! $s['on'] || (int) gmdate( 'G', $now ) < (int) $s['hour'] ) {
			return false;
		}
		if ( 'monthly' === $s['frequency'] ) {
			$period = gmdate( 'Y-m', $now );
			$today  = 1 === (int) gmdate( 'j', $now );
		} else {
			$period = gmdate( 'o-W', $now );
			$today  = (int) gmdate( 'N', $now ) === (int) $s['weekday'];
		}
		$last = '' !== $s['last_sent'] ? strtotime( $s['last_sent'] ) : 0;
		$done = $last && ( 'monthly' === $s['frequency'] ? gmdate( 'Y-m', $last ) : gmdate( 'o-W', $last ) ) === $period;
		return $today && ! $done;
	}

	/**
	 * The cron job: send when due.
	 *
	 * @return int|WP_Error|null The campaign id, an error, or null (not due / nothing new).
	 */
	public static function maybe_send() {
		$s = self::settings();
		if ( ! self::is_due( $s ) ) {
			return null;
		}
		return self::send_now();
	}

	/**
	 * Build the digest from the posts since the last one and queue it.
	 *
	 * @param bool $record Remember it as sent (false for a preview build).
	 *
	 * @return int|WP_Error|null
	 */
	public static function send_now( $record = true ) {
		$s     = self::settings();
		$since = '' !== $s['last_sent'] ? $s['last_sent'] : gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 'monthly' === $s['frequency'] ? 31 : 7 ) * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$posts = self::posts( $since, (int) $s['count'], (string) $s['post_type'] );
		if ( $record ) {
			// Recorded even when there is nothing new, so the job does not ask again until the next period.
			$s['last_sent'] = current_time( 'mysql' );
			update_option( self::OPTION, $s, false );
		}
		if ( ! $posts ) {
			return null;
		}
		$campaign = FW_CRM_Service::save_campaign( array(
			'title'     => sprintf( __( 'Post digest, %s', 'fw' ), date_i18n( get_option( 'date_format' ) ) ),
			'subject'   => $s['subject'],
			'body'      => self::body( $posts, (string) $s['intro'] ),
			'audience'  => array( 'list' => $s['list'], 'tag' => $s['tag'], 'segment' => (int) $s['segment'] ),
			'sender_id' => $s['sender_id'],
		) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		$queued = FW_CRM_Service::schedule_campaign( (int) $campaign->id );
		if ( is_wp_error( $queued ) ) {
			return $queued;
		}
		/** Fires after a post digest was queued as a campaign. */
		do_action( 'unysonplus_crm_digest_queued', $campaign, $posts );
		return (int) $campaign->id;
	}

	/**
	 * @param string $since MySQL datetime (site time).
	 * @param int    $count
	 * @param string $post_type
	 *
	 * @return WP_Post[]
	 */
	public static function posts( $since, $count, $post_type = 'post' ) {
		return get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'posts_per_page'   => max( 1, (int) $count ),
			'orderby'          => 'date',
			'order'            => 'DESC',
			'date_query'       => array( array( 'after' => $since, 'inclusive' => false ) ),
			'has_password'     => false,
			'suppress_filters' => false,
		) );
	}

	/**
	 * The email body: the intro, then each post with its image, title, excerpt and a link.
	 *
	 * @param WP_Post[] $posts
	 * @param string    $intro
	 *
	 * @return string
	 */
	public static function body( array $posts, $intro ) {
		$html = '' !== trim( $intro ) ? '<p style="margin:0 0 24px">' . nl2br( esc_html( $intro ) ) . '</p>' : '';
		foreach ( $posts as $post ) {
			$url   = get_permalink( $post );
			$img   = get_the_post_thumbnail_url( $post, 'medium_large' );
			$text  = wp_trim_words( wp_strip_all_tags( has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content ) ), 40, '…' );
			$html .= '<div style="margin:0 0 32px">';
			if ( $img ) {
				$html .= '<a href="' . esc_url( $url ) . '"><img src="' . esc_url( $img ) . '" alt="" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:4px;margin:0 0 12px" /></a>';
			}
			$html .= '<h2 style="margin:0 0 8px;font-size:20px;line-height:1.3"><a href="' . esc_url( $url ) . '" style="color:inherit;text-decoration:none">' . esc_html( get_the_title( $post ) ) . '</a></h2>';
			$html .= '<p style="margin:0 0 8px">' . esc_html( $text ) . '</p>';
			$html .= '<p style="margin:0"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Read more', 'fw' ) . '</a></p>';
			$html .= '</div>';
		}
		return $html;
	}
}
