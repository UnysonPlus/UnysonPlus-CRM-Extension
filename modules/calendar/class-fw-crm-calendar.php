<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * What is on the CRM calendar between two dates: tasks by their due time, open
 * deals on their expected close date, and campaigns on the day they are
 * scheduled or were sent. Other extensions add their own through the
 * `unysonplus_crm_calendar_events` filter.
 *
 * An event is { id, kind, date (Y-m-d), time (H:i or ''), title, sub, url, icon,
 * done, late }. Scoping follows the CRM's roles: a sales rep sees their own
 * tasks and deals.
 */
class FW_CRM_Calendar {

	/**
	 * @param string    $from  Y-m-d, inclusive.
	 * @param string    $to    Y-m-d, inclusive.
	 * @param int|string $owner A user id, or '' for everyone (only for people who see everything).
	 *
	 * @return array date => events sorted by time (all-day first).
	 */
	public static function events( $from, $to, $owner = 0 ) {
		global $wpdb;

		$owner = FW_CRM_Capabilities::sees_all() ? $owner : get_current_user_id();
		$base  = class_exists( 'FW_CRM_Admin_Page' ) ? FW_CRM_Admin_Page::get_page_url() : admin_url( 'admin.php?page=fw-crm' );
		$now   = current_time( 'mysql' );
		$out   = array();

		// Tasks.
		$table = FW_CRM_Tasks::table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE due_at BETWEEN %s AND %s", $from . ' 00:00:00', $to . ' 23:59:59' ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' !== $owner && null !== $owner ) {
			$sql .= $wpdb->prepare( ' AND owner_id = %d', (int) $owner );
		}
		$types = FW_CRM_Tasks::types();
		foreach ( (array) $wpdb->get_results( $sql . ' ORDER BY due_at ASC' ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$target = FW_CRM_Tasks::target( $t );
			$out[]  = array(
				'id'    => 'task-' . $t->id,
				'kind'  => 'task',
				'type'  => (string) $t->type,
				'task'  => (int) $t->id,
				'date'  => substr( $t->due_at, 0, 10 ),
				'time'  => substr( $t->due_at, 11, 5 ),
				'title' => (string) $t->title,
				'sub'   => implode( ' · ', array_filter( array(
					$types[ $t->type ]['label'] ?? '',
					(int) $t->duration ? FW_CRM_Tasks::duration_label( (int) $t->duration ) : '',
					(string) $t->location,
					$target['label'],
				) ) ),
				'url'   => '' !== $target['url'] ? $target['url'] : add_query_arg( 'tab', 'tasks', $base ),
				'icon'  => $types[ $t->type ]['icon'] ?? 'dashicons-yes-alt',
				'done'  => (bool) $t->done_at,
				'late'  => ! $t->done_at && $t->due_at < $now,
			);
		}

		// Deals closing.
		if ( class_exists( 'FW_CRM_Deals' ) ) {
			$args = array( 'status' => 'open', 'closing_before' => $to );
			if ( '' !== $owner && null !== $owner ) {
				$args['owner'] = (int) $owner;
			}
			foreach ( FW_CRM_Deals::query( $args ) as $d ) {
				if ( ! $d->expected_close || $d->expected_close < $from ) {
					continue;
				}
				$out[] = array(
					'id'    => 'deal-' . $d->id,
					'kind'  => 'deal',
					'date'  => (string) $d->expected_close,
					'time'  => '',
					'title' => (string) $d->title,
					'sub'   => sprintf( __( 'Expected to close · %s', 'fw' ), FW_CRM_Deals::money( $d->value, $d->currency ) ),
					'url'   => add_query_arg( array( 'tab' => 'deals', 'deal' => (int) $d->id ), $base ),
					'icon'  => 'dashicons-money-alt',
					'done'  => false,
					'late'  => $d->expected_close < current_time( 'Y-m-d' ),
				);
			}
		}

		// Campaigns (managers only: they are the ones who can open them).
		if ( class_exists( 'FW_CRM_Campaigns' ) && FW_CRM_Capabilities::can_manage() ) {
			$table = FW_CRM_Installer::table( 'campaigns' );
			$rows  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				"SELECT id, title, status, scheduled_at, started_at FROM {$table} WHERE ( scheduled_at BETWEEN %s AND %s ) OR ( scheduled_at IS NULL AND started_at BETWEEN %s AND %s )", // phpcs:ignore WordPress.DB.PreparedSQL
				$from . ' 00:00:00',
				$to . ' 23:59:59',
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			) );
			foreach ( $rows as $c ) {
				$when  = $c->scheduled_at ? $c->scheduled_at : $c->started_at;
				$out[] = array(
					'id'    => 'campaign-' . $c->id,
					'kind'  => 'campaign',
					'date'  => substr( $when, 0, 10 ),
					'time'  => substr( $when, 11, 5 ),
					'title' => (string) $c->title,
					'sub'   => 'sent' === $c->status ? __( 'Campaign sent', 'fw' ) : ( 'scheduled' === $c->status ? __( 'Campaign scheduled', 'fw' ) : __( 'Campaign', 'fw' ) ),
					'url'   => add_query_arg( array( 'tab' => 'campaigns', 'campaign' => (int) $c->id ), $base ),
					'icon'  => 'dashicons-email-alt',
					'done'  => 'sent' === $c->status,
					'late'  => false,
				);
			}
		}

		/**
		 * Add events to the CRM calendar.
		 *
		 * @param array      $out   Events, see the class docblock.
		 * @param string     $from  Y-m-d.
		 * @param string     $to    Y-m-d.
		 * @param int|string $owner The user whose calendar it is ('' = everyone).
		 */
		$out = (array) apply_filters( 'unysonplus_crm_calendar_events', $out, $from, $to, $owner );

		$by_day = array();
		foreach ( $out as $e ) {
			if ( empty( $e['date'] ) || $e['date'] < $from || $e['date'] > $to ) {
				continue;
			}
			$by_day[ $e['date'] ][] = array_merge( array( 'time' => '', 'sub' => '', 'url' => '', 'icon' => 'dashicons-marker', 'done' => false, 'late' => false, 'kind' => 'other' ), $e );
		}
		foreach ( $by_day as &$list ) {
			usort( $list, function ( $a, $b ) {
				return strcmp( (string) $a['time'], (string) $b['time'] );
			} );
		}
		unset( $list );
		return $by_day;
	}
}
