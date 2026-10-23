<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * One events table for anything a known contact does: logins, purchases, campaign opens and
 * clicks, form submissions, pages visited (site activity) and custom events from code or from
 * clicks on marked elements. Scores, segments and automations all read from here.
 *
 * Record from code:
 *     do_action( 'unysonplus_crm_track', $contact_id_or_email, 'webinar_attended', array( 'value' => 'October' ) );
 *
 * Every recorded event fires `unysonplus_crm_event_recorded` and, for automations, the
 * `crm.event` trigger (page visits use their own `crm.page_visited`).
 *
 * Event names are lowercase slugs (letters, numbers, _ and -), at most 64 characters.
 */
class FW_CRM_Events_Store {

	public static function init() {
		add_action( 'unysonplus_crm_track', array( __CLASS__, '_track' ), 10, 3 );
		add_action( 'wp_login', array( __CLASS__, '_on_login' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, '_on_order' ), 20 );
		add_action( 'unysonplus_crm_form_submitted', function ( $contact_id, $ctx ) {
			self::record( (int) $contact_id, 'form_submitted', array( 'value' => (string) ( $ctx['form_id'] ?? '' ) ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_email_event', function ( $type, $row ) {
			if ( in_array( $type, array( 'open', 'click' ), true ) && ! empty( $row->subscriber_id ) ) {
				self::record( (int) $row->subscriber_id, 'email_' . $type, array( 'value' => (string) $row->campaign_id ) );
			}
		}, 20, 2 );

		// Segments: "did <event> in the last N days".
		add_filter( 'unysonplus_crm_segment_fields', function ( $fields ) {
			$fields['event'] = array(
				'label'   => __( 'Did something (event)', 'fw' ),
				'type'    => 'activity',
				'group'   => __( 'Dates and activity', 'fw' ),
				'choices' => self::names(),
				'sql'     => array( __CLASS__, 'segment_sql' ),
			);
			return $fields;
		} );
		// Automations: "an event happened" (any event but page visits).
		add_filter( 'unysonplus_crm_automation_triggers', function ( $t ) {
			$names = self::names();
			unset( $names['page_view'] );
			$t['crm.event'] = array(
				'label'  => __( 'Event happened', 'fw' ),
				'group'  => __( 'Activity', 'fw' ),
				'fields' => array(
					'event' => array( 'label' => __( 'Event', 'fw' ), 'type' => 'select', 'options' => array( '' => __( 'Any event', 'fw' ) ) + $names ),
					'value' => array( 'label' => __( 'With the value (empty = any)', 'fw' ), 'type' => 'text' ),
				),
			);
			return $t;
		} );
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'contact_events' );
	}

	/**
	 * Store an event.
	 *
	 * @param int    $contact_id
	 * @param string $event
	 * @param array  $args value (a short string: a form id, an amount, a campaign id), url, props (plain values)
	 *
	 * @return int The event id, or 0.
	 */
	public static function record( $contact_id, $event, array $args = array() ) {
		global $wpdb;

		$event = self::slug( $event );
		if ( ! $contact_id || '' === $event ) {
			return 0;
		}
		$props = isset( $args['props'] ) && is_array( $args['props'] ) ? self::plain( $args['props'] ) : array();
		$wpdb->insert( self::table(), array(
			'contact_id' => (int) $contact_id,
			'event'      => $event,
			'value'      => substr( sanitize_text_field( (string) ( $args['value'] ?? '' ) ), 0, 255 ),
			'url'        => substr( esc_url_raw( (string) ( $args['url'] ?? '' ) ), 0, 500 ),
			'props'      => $props ? wp_json_encode( $props ) : '',
			'created_at' => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return 0;
		}
		$row = self::find( $id );
		/**
		 * Fires after a contact event is stored.
		 *
		 * @param object $row The event: id, contact_id, event, value, url, props (array), created_at.
		 */
		do_action( 'unysonplus_crm_event_recorded', $row );
		if ( 'page_view' !== $event ) {
			do_action( 'unysonplus_crm_automation_event', 'crm.event', (int) $contact_id, array( 'event' => $event, 'value' => $row->value ) );
		}
		return $id;
	}

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $r ) {
			$r->props = json_decode( (string) $r->props, true ) ?: array();
		}
		return $r;
	}

	/**
	 * A contact's events, newest first.
	 *
	 * @param int    $contact_id
	 * @param int    $limit
	 * @param string $event Only this event ('' = all).
	 *
	 * @return object[]
	 */
	public static function for_contact( $contact_id, $limit = 50, $event = '' ) {
		global $wpdb;

		$t   = self::table();
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE contact_id = %d", (int) $contact_id ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' !== $event ) {
			$sql .= $wpdb->prepare( ' AND event = %s', self::slug( $event ) );
		}
		return (array) $wpdb->get_results( $sql . $wpdb->prepare( ' ORDER BY id DESC LIMIT %d', (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * How many times a contact did something, optionally within the last N days.
	 *
	 * @param int    $contact_id
	 * @param string $event
	 * @param int    $days 0 = ever
	 *
	 * @return int
	 */
	public static function count( $contact_id, $event, $days = 0 ) {
		global $wpdb;

		$t   = self::table();
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE contact_id = %d AND event = %s", (int) $contact_id, self::slug( $event ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $days > 0 ) {
			$sql .= $wpdb->prepare( ' AND created_at >= %s', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - (int) $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Every event name seen so far (plus the built-in ones), for pickers.
	 *
	 * @return array name => label
	 */
	public static function names() {
		global $wpdb;

		$out = array(
			'page_view'      => __( 'Visited a page', 'fw' ),
			'login'          => __( 'Logged in', 'fw' ),
			'purchase'       => __( 'Made a purchase', 'fw' ),
			'form_submitted' => __( 'Submitted a form', 'fw' ),
			'email_open'     => __( 'Opened a campaign email', 'fw' ),
			'email_click'    => __( 'Clicked a campaign link', 'fw' ),
		);
		$t = self::table();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT event FROM {$t} ORDER BY event LIMIT 200" ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! isset( $out[ $e ] ) ) {
				$out[ $e ] = $e;
			}
		}
		/** Filters the event names offered in scoring rules, segments and automations. */
		return (array) apply_filters( 'unysonplus_crm_event_names', $out );
	}

	/**
	 * Segment rule: "did <event> in the last N days" (or did not).
	 *
	 * @param string $op    happened|not_happened
	 * @param array  $value { type: event name, days }
	 *
	 * @return string SQL against the contact row `s`.
	 */
	public static function segment_sql( $op, $value ) {
		global $wpdb;

		$t   = self::table();
		$sql = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$t} e WHERE e.contact_id = s.id AND e.event = %s AND e.created_at >= %s)", // phpcs:ignore WordPress.DB.PreparedSQL
			self::slug( (string) ( $value['type'] ?? '' ) ),
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - max( 1, (int) ( $value['days'] ?? 30 ) ) * DAY_IN_SECONDS ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		);
		return 'not_happened' === $op ? 'NOT ' . $sql : $sql;
	}

	/* ---------------------------------------------------------------------- *
	 * Sources
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal unysonplus_crm_track
	 *
	 * @param int|string $who   A contact id or an email address.
	 * @param string     $event
	 * @param array      $args  value, url, props
	 */
	public static function _track( $who, $event, $args = array() ) {
		$c = is_numeric( $who ) ? FW_CRM_Subscribers::find( (int) $who ) : FW_CRM_Subscribers::find_by_email( strtolower( (string) $who ) );
		if ( $c && empty( $c->deleted_at ) ) {
			self::record( (int) $c->id, (string) $event, is_array( $args ) ? $args : array() );
		}
	}

	/**
	 * The contact behind a site account: linked by user id, else by email.
	 *
	 * @param WP_User $user
	 *
	 * @return object|null
	 */
	public static function contact_for_user( $user ) {
		global $wpdb;

		if ( ! $user || ! $user->ID ) {
			return null;
		}
		$t  = FW_CRM_Installer::table( 'subscribers' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND deleted_at IS NULL LIMIT 1", (int) $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$c  = $id ? FW_CRM_Subscribers::find( $id ) : FW_CRM_Subscribers::find_by_email( strtolower( (string) $user->user_email ) );
		return $c && empty( $c->deleted_at ) ? $c : null;
	}

	/** @internal */
	public static function _on_login( $login, $user ) {
		$c = self::contact_for_user( $user );
		if ( $c ) {
			self::record( (int) $c->id, 'login' );
		}
	}

	/** @internal */
	public static function _on_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		$c     = $order ? FW_CRM_Subscribers::find_by_email( strtolower( (string) $order->get_billing_email() ) ) : null;
		if ( $c ) {
			self::record( (int) $c->id, 'purchase', array( 'value' => (string) $order->get_total(), 'props' => array( 'order_id' => (int) $order_id ) ) );
		}
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public static function slug( $name ) {
		return substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( ' ', '_', trim( (string) $name ) ) ) ), 0, 64 );
	}

	/**
	 * @param array $a
	 *
	 * @return array
	 */
	private static function plain( array $a ) {
		$out = array();
		foreach ( $a as $k => $v ) {
			if ( is_scalar( $v ) || null === $v ) {
				$out[ sanitize_key( (string) $k ) ] = is_string( $v ) ? substr( sanitize_text_field( $v ), 0, 500 ) : $v;
			} elseif ( is_array( $v ) ) {
				$out[ sanitize_key( (string) $k ) ] = self::plain( $v );
			}
		}
		return $out;
	}
}
