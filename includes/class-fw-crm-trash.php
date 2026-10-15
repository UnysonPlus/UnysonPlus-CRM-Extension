<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The bin. Deleting a contact or company only stamps `deleted_at`, which hides it
 * everywhere (lists, search, segments, campaign audiences); it can be restored
 * until the bin is emptied. A daily job deletes for good whatever has been in the
 * bin longer than the retention period (30 days; filter unysonplus_crm_bin_days,
 * 0 keeps everything until it is emptied by hand).
 */
class FW_CRM_Trash {

	const CRON_HOOK = 'fw_crm_empty_bin';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge_expired' ) );
		add_action( 'init', array( __CLASS__, '_maybe_schedule' ) );
	}

	/** @internal */
	public static function _maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Days a record stays in the bin before it is deleted for good.
	 *
	 * @return int
	 */
	public static function retention_days() {
		/** Filters how many days contacts and companies stay in the CRM bin (0 = until emptied by hand). */
		return max( 0, (int) apply_filters( 'unysonplus_crm_bin_days', 30 ) );
	}

	/**
	 * @return array { contacts: int, companies: int }
	 */
	public static function counts() {
		return array(
			'contacts'  => FW_CRM_Subscribers::count( array( 'trashed' => 'only' ) ),
			'companies' => FW_CRM_Companies::count( array( 'trashed' => 'only' ) ),
		);
	}

	/**
	 * Delete for good everything in the bin, or only what was binned before a date.
	 *
	 * @param string|null $before A local MySQL datetime, or null for everything.
	 *
	 * @return int Records deleted.
	 */
	public static function purge( $before = null ) {
		$args = array( 'trashed' => 'only', 'per_page' => 200, 'paged' => 1 );
		if ( $before ) {
			$args['deleted_before'] = $before;
		}
		$done = 0;

		// Batches of 200, re-querying page 1 each time because each batch removes its rows.
		do {
			$rows = FW_CRM_Subscribers::query( $args );
			foreach ( $rows as $row ) {
				$done += FW_CRM_Contacts::purge( (int) $row->id ) ? 1 : 0;
			}
		} while ( count( $rows ) === 200 );

		do {
			$rows = FW_CRM_Companies::query( $args );
			foreach ( $rows as $row ) {
				$done += FW_CRM_Companies::purge( (int) $row->id ) ? 1 : 0;
			}
		} while ( count( $rows ) === 200 );

		if ( class_exists( 'FW_CRM_Deals' ) ) {
			global $wpdb;
			$table = FW_CRM_Deals::table();
			$ids   = $before
				? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at < %s", $before ) ) // phpcs:ignore WordPress.DB.PreparedSQL
				: $wpdb->get_col( "SELECT id FROM {$table} WHERE deleted_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $ids as $deal_id ) {
				$done += FW_CRM_Deals::purge( (int) $deal_id ) ? 1 : 0;
			}
		}

		return $done;
	}

	/**
	 * The daily job: empty what has been in the bin longer than the retention period.
	 *
	 * @return int
	 */
	public static function purge_expired() {
		$days = self::retention_days();
		if ( ! $days ) {
			return 0;
		}
		return self::purge( gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}
}
