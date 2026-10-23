<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Is the scheduler running on time?
 *
 * WordPress runs its scheduled jobs only when someone visits the site, so on a quiet site a
 * "wait 1 hour" can turn into a wait of half a day, and the campaign queue stalls. Each CRM job
 * records a heartbeat when it runs; this compares the last beat with how often it should run and
 * gives the exact line for a real server cron job (cPanel and similar) as the fix.
 */
class FW_CRM_Cron_Check {

	const OPTION = 'fw_crm_cron_beats';

	public static function init() {
		// Nothing to hook: the jobs call beat() themselves.
	}

	/**
	 * Record that a job ran now.
	 *
	 * @param string $job
	 */
	public static function beat( $job ) {
		$beats         = get_option( self::OPTION, array() );
		$beats         = is_array( $beats ) ? $beats : array();
		$beats[ $job ] = time();
		update_option( self::OPTION, $beats, false );
	}

	/**
	 * @return array { status: ok|late|never|off, last: timestamp|0, message, wp_cron_disabled: bool }
	 */
	public static function status() {
		$beats = get_option( self::OPTION, array() );
		$last  = (int) ( is_array( $beats ) ? ( $beats['automations'] ?? 0 ) : 0 );
		$off   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$age   = $last ? time() - $last : PHP_INT_MAX;
		if ( ! $last ) {
			$status  = 'never';
			$message = __( 'The scheduler has not run the automations yet. It runs on the next visit to the site, or from a server cron job.', 'fw' );
		} elseif ( $age <= 5 * MINUTE_IN_SECONDS ) {
			$status  = 'ok';
			$message = sprintf( __( 'Running on time: last run %s ago.', 'fw' ), human_time_diff( $last ) );
		} else {
			$status  = 'late';
			$message = sprintf( __( 'Late: the last run was %s ago. Waits and scheduled emails go out late when the site has few visitors. Set up a server cron job (below).', 'fw' ), human_time_diff( $last ) );
		}
		if ( $off && 'ok' !== $status ) {
			$message .= ' ' . __( 'WordPress\'s own scheduler is switched off on this site (DISABLE_WP_CRON), so a server cron job is required.', 'fw' );
		}
		return array( 'status' => $status, 'last' => $last, 'message' => $message, 'wp_cron_disabled' => $off );
	}

	/**
	 * The lines to paste into a server's cron settings.
	 *
	 * @return array { url: the wget line, cli: the WP-CLI line, config: the wp-config.php line }
	 */
	public static function lines() {
		$url = site_url( 'wp-cron.php?doing_wp_cron' );
		return array(
			'url'    => '*/5 * * * * wget -q -O - "' . $url . '" >/dev/null 2>&1',
			'cli'    => '* * * * * cd ' . untrailingslashit( ABSPATH ) . ' && wp crm automations run --quiet >/dev/null 2>&1',
			'config' => "define( 'DISABLE_WP_CRON', true );",
		);
	}
}
