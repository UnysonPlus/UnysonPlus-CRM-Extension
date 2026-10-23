<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Lead scoring: rules give or take points when a contact does something, so the warmest leads
 * rise to the top.
 *
 * - **Scores**: "default" (the contact's Score, also kept in `subscribers.score` so lists, segments
 *   and imports keep working) plus any named scores (one per product or interest). Rows live in
 *   `contact_scores`; every change is written to `score_log` with its reason.
 * - **Rules** (option `fw_crm_scoring` → rules): { id, label, score, event, match, points, limit }.
 *   `event` is any contact event (page_view, email_open, email_click, form_submitted, purchase,
 *   login, a custom event), or `tag_added` / `field` (a field set to a value). `match` narrows it
 *   (a URL fragment, a form id, a tag, field=value). `limit` caps how many times one contact can
 *   earn the rule (0 = no cap).
 * - **Fading**: once a day, a score untouched for `days` loses `percent` of its points, so old
 *   interest does not look like new interest.
 * - Crossing a value fires the `crm.score_changed` automation trigger; segments and automation
 *   conditions can test any score; a deal's score is the highest default score of its contacts.
 */
class FW_CRM_Scoring {

	const OPTION   = 'fw_crm_scoring';
	const CRON     = 'fw_crm_scoring_daily';

	public static function init() {
		add_action( 'unysonplus_crm_event_recorded', array( __CLASS__, '_on_event' ) );
		add_action( 'unysonplus_crm_pivot_changed', function ( $contact_id, $object_id, $type, $op ) {
			if ( 'tag' === $type && 'add' === $op ) {
				$tag = FW_CRM_Lists::find( (int) $object_id );
				self::apply( (int) $contact_id, 'tag_added', array( 'value' => $tag ? (string) $tag->slug : '' ) );
			}
		}, 10, 4 );
		add_action( 'unysonplus_crm_contact_updated', function ( $contact, $before, $changes ) {
			foreach ( (array) $changes as $field => $c ) {
				self::apply( (int) ( is_array( $contact ) ? $contact['id'] : $contact->id ), 'field', array( 'field' => (string) $field, 'value' => (string) ( $c['new'] ?? '' ) ) );
			}
		}, 10, 3 );
		add_action( 'unysonplus_crm_fields_updated', function ( $object_type, $object_id, $keys ) {
			if ( 'contact' !== $object_type ) {
				return;
			}
			$vals = FW_CRM_Fields::get_values( 'contact', (int) $object_id );
			foreach ( (array) $keys as $k ) {
				$v = $vals[ $k ] ?? '';
				self::apply( (int) $object_id, 'field', array( 'field' => 'cf:' . $k, 'value' => is_array( $v ) ? implode( ', ', $v ) : (string) $v ) );
			}
		}, 10, 3 );

		add_action( self::CRON, array( __CLASS__, 'decay' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}

		add_filter( 'unysonplus_crm_segment_fields', array( __CLASS__, '_segment_fields' ) );
		add_filter( 'unysonplus_crm_automation_condition_fields', array( __CLASS__, '_condition_fields' ) );
		add_filter( 'unysonplus_crm_automation_triggers', array( __CLASS__, '_triggers' ) );
		add_filter( 'unysonplus_crm_automation_actions', array( __CLASS__, '_actions' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------------- */

	/** @return array { scores: key => label, rules: [], decay: { on, percent, days } } */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$s = array_merge( array( 'scores' => array(), 'rules' => array(), 'decay' => array() ), $s );
		$s['scores'] = array_merge( array( 'default' => __( 'Score', 'fw' ) ), (array) $s['scores'] );
		$s['decay']  = array_merge( array( 'on' => false, 'percent' => 25, 'days' => 30 ), (array) $s['decay'] );
		return $s;
	}

	/** @param array $s */
	public static function save_settings( array $s ) {
		$cur = self::settings();
		$out = array( 'scores' => array(), 'rules' => array(), 'decay' => array() );
		foreach ( (array) ( $s['scores'] ?? $cur['scores'] ) as $k => $label ) {
			$k = 'default' === $k ? 'default' : substr( sanitize_key( (string) $k ), 0, 40 );
			if ( '' !== $k ) {
				$out['scores'][ $k ] = sanitize_text_field( (string) $label );
			}
		}
		$known = array_merge( array( 'default' => '' ), $out['scores'] );
		foreach ( (array) ( $s['rules'] ?? $cur['rules'] ) as $r ) {
			$r = self::clean_rule( (array) $r, $known );
			if ( $r ) {
				$out['rules'][] = $r;
			}
		}
		$d            = (array) ( $s['decay'] ?? $cur['decay'] );
		$out['decay'] = array(
			'on'      => ! empty( $d['on'] ),
			'percent' => max( 1, min( 100, (int) ( $d['percent'] ?? 25 ) ) ),
			'days'    => max( 1, min( 365, (int) ( $d['days'] ?? 30 ) ) ),
		);
		unset( $out['scores']['default'] ); // Always present; not stored.
		update_option( self::OPTION, $out, false );
	}

	/**
	 * @param array $r
	 *
	 * @return array|null
	 */
	public static function clean_rule( array $r, $scores = null ) {
		$scores = null === $scores ? self::settings()['scores'] : (array) $scores;
		$events = array_keys( self::rule_events() );
		$event  = (string) ( $r['event'] ?? '' );
		if ( ! in_array( $event, $events, true ) && 0 !== strpos( $event, 'event:' ) ) {
			return null;
		}
		$points = (int) ( $r['points'] ?? 0 );
		if ( ! $points ) {
			return null;
		}
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $r['id'] ?? '' ) ) );
		return array(
			'id'     => '' !== $id ? substr( $id, 0, 20 ) : 'r' . substr( md5( uniqid( '', true ) ), 0, 8 ),
			'label'  => sanitize_text_field( (string) ( $r['label'] ?? '' ) ),
			'score'  => isset( $scores[ $r['score'] ?? '' ] ) ? (string) $r['score'] : 'default',
			'event'  => 0 === strpos( $event, 'event:' ) ? 'event:' . FW_CRM_Events_Store::slug( substr( $event, 6 ) ) : $event,
			'match'  => sanitize_text_field( (string) ( $r['match'] ?? '' ) ),
			'points' => max( -1000, min( 1000, $points ) ),
			'limit'  => max( 0, min( 1000, (int) ( $r['limit'] ?? 0 ) ) ),
		);
	}

	/**
	 * What a rule can react to, and what its "match" means for each.
	 *
	 * @return array event => { label, match }
	 */
	public static function rule_events() {
		return array(
			'page_view'      => array( 'label' => __( 'Visits a page', 'fw' ), 'match' => __( 'Address contains (empty = any page)', 'fw' ) ),
			'email_open'     => array( 'label' => __( 'Opens a campaign email', 'fw' ), 'match' => __( 'Campaign ID (empty = any)', 'fw' ) ),
			'email_click'    => array( 'label' => __( 'Clicks a campaign link', 'fw' ), 'match' => __( 'Campaign ID (empty = any)', 'fw' ) ),
			'form_submitted' => array( 'label' => __( 'Submits a form', 'fw' ), 'match' => __( 'Form ID (empty = any form)', 'fw' ) ),
			'purchase'       => array( 'label' => __( 'Makes a purchase', 'fw' ), 'match' => __( 'Order total at least (empty = any)', 'fw' ) ),
			'login'          => array( 'label' => __( 'Logs in', 'fw' ), 'match' => '' ),
			'tag_added'      => array( 'label' => __( 'Gets a tag', 'fw' ), 'match' => __( 'Tag (slug)', 'fw' ) ),
			'field'          => array( 'label' => __( 'Has a field set to a value', 'fw' ), 'match' => __( 'field = value, e.g. lifecycle_stage = lead or cf:budget = high', 'fw' ) ),
			'event:'         => array( 'label' => __( 'Does a custom event', 'fw' ), 'match' => __( 'Value (empty = any)', 'fw' ) ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Applying rules
	 * ---------------------------------------------------------------------- */

	/** @internal A contact event was stored. */
	public static function _on_event( $row ) {
		if ( ! $row || empty( $row->contact_id ) ) {
			return;
		}
		$known = array( 'page_view', 'email_open', 'email_click', 'form_submitted', 'purchase', 'login' );
		$kind  = in_array( $row->event, $known, true ) ? $row->event : 'event:' . $row->event;
		self::apply( (int) $row->contact_id, $kind, array( 'value' => (string) $row->value, 'url' => (string) $row->url ) );
	}

	/**
	 * Run every rule for an event.
	 *
	 * @param int    $contact_id
	 * @param string $event
	 * @param array  $ctx value, url, field
	 *
	 * @return int Points added in total.
	 */
	public static function apply( $contact_id, $event, array $ctx = array() ) {
		$total = 0;
		foreach ( self::settings()['rules'] as $r ) {
			if ( $r['event'] !== $event || ! self::matches( $r, $ctx ) ) {
				continue;
			}
			if ( $r['limit'] && self::times( $contact_id, $r['id'] ) >= $r['limit'] ) {
				continue;
			}
			$reason = '' !== $r['label'] ? $r['label'] : self::describe( $r );
			self::add( $contact_id, $r['points'], $r['score'], $reason, $r['id'] );
			$total += $r['points'];
		}
		return $total;
	}

	/**
	 * @param array $r
	 * @param array $ctx
	 *
	 * @return bool
	 */
	private static function matches( array $r, array $ctx ) {
		$m = trim( (string) $r['match'] );
		if ( '' === $m ) {
			return 'field' !== $r['event'];
		}
		switch ( $r['event'] ) {
			case 'page_view':
				return false !== stripos( (string) ( $ctx['url'] ?? '' ), $m );
			case 'purchase':
				return (float) ( $ctx['value'] ?? 0 ) >= (float) $m;
			case 'field':
				$parts = array_map( 'trim', explode( '=', $m, 2 ) );
				return 2 === count( $parts ) && $parts[0] === ( $ctx['field'] ?? '' ) && 0 === strcasecmp( $parts[1], (string) ( $ctx['value'] ?? '' ) );
			default:
				return 0 === strcasecmp( $m, (string) ( $ctx['value'] ?? '' ) );
		}
	}

	/**
	 * @param array $r
	 *
	 * @return string
	 */
	public static function describe( array $r ) {
		$events = self::rule_events();
		$label  = 0 === strpos( $r['event'], 'event:' ) ? sprintf( __( 'Event "%s"', 'fw' ), substr( $r['event'], 6 ) ) : ( $events[ $r['event'] ]['label'] ?? $r['event'] );
		return $label . ( '' !== $r['match'] ? ' (' . $r['match'] . ')' : '' );
	}

	/**
	 * How many times a contact earned a rule.
	 *
	 * @param int    $contact_id
	 * @param string $rule_id
	 *
	 * @return int
	 */
	public static function times( $contact_id, $rule_id ) {
		global $wpdb;

		$t = FW_CRM_Installer::table( 'score_log' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE contact_id = %d AND rule_id = %s", (int) $contact_id, (string) $rule_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ---------------------------------------------------------------------- *
	 * Scores
	 * ---------------------------------------------------------------------- */

	/**
	 * Add (or take away) points.
	 *
	 * @param int    $contact_id
	 * @param int    $points
	 * @param string $score
	 * @param string $reason
	 * @param string $rule_id '' for a change by hand or by an automation.
	 *
	 * @return int The new score.
	 */
	public static function add( $contact_id, $points, $score = 'default', $reason = '', $rule_id = '' ) {
		global $wpdb;

		$score = '' !== (string) $score ? substr( sanitize_key( (string) $score ), 0, 40 ) : 'default';
		$t     = FW_CRM_Installer::table( 'contact_scores' );
		$now   = current_time( 'mysql' );
		$from  = self::get( $contact_id, $score );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (contact_id, score_key, points, updated_at) VALUES (%d, %s, %d, %s) ON DUPLICATE KEY UPDATE points = points + VALUES(points), updated_at = VALUES(updated_at)", (int) $contact_id, $score, (int) $points, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$to = self::get( $contact_id, $score );
		$wpdb->insert( FW_CRM_Installer::table( 'score_log' ), array(
			'contact_id' => (int) $contact_id,
			'score_key'  => $score,
			'rule_id'    => (string) $rule_id,
			'points'     => (int) $points,
			'reason'     => substr( (string) $reason, 0, 190 ),
			'created_at' => $now,
		) );
		self::changed( (int) $contact_id, $score, $from, $to );
		return $to;
	}

	/**
	 * Set a score outright (decay, imports, a manual correction).
	 *
	 * @param int    $contact_id
	 * @param int    $points
	 * @param string $score
	 * @param string $reason
	 */
	public static function set( $contact_id, $points, $score = 'default', $reason = '' ) {
		$from = self::get( $contact_id, $score );
		if ( (int) $points !== $from ) {
			self::add( $contact_id, (int) $points - $from, $score, $reason );
		}
	}

	/**
	 * @param int    $contact_id
	 * @param string $score
	 *
	 * @return int
	 */
	public static function get( $contact_id, $score = 'default' ) {
		global $wpdb;

		$t = FW_CRM_Installer::table( 'contact_scores' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT points FROM {$t} WHERE contact_id = %d AND score_key = %s", (int) $contact_id, (string) $score ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param int $contact_id
	 *
	 * @return array score key => points (every named score, 0 when never earned)
	 */
	public static function all_for( $contact_id ) {
		global $wpdb;

		$out = array_fill_keys( array_keys( self::settings()['scores'] ), 0 );
		$t   = FW_CRM_Installer::table( 'contact_scores' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT score_key, points FROM {$t} WHERE contact_id = %d", (int) $contact_id ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $r->score_key ] = (int) $r->points;
		}
		return $out;
	}

	/**
	 * A score changed: keep `subscribers.score` in step and tell automations.
	 *
	 * @param int    $contact_id
	 * @param string $score
	 * @param int    $from
	 * @param int    $to
	 */
	private static function changed( $contact_id, $score, $from, $to ) {
		global $wpdb;

		if ( $from === $to ) {
			return;
		}
		if ( 'default' === $score ) {
			$wpdb->update( FW_CRM_Installer::table( 'subscribers' ), array( 'score' => $to ), array( 'id' => (int) $contact_id ) );
		}
		/** Fires after a contact's score changes. */
		do_action( 'unysonplus_crm_score_changed', (int) $contact_id, $score, $from, $to );
		do_action( 'unysonplus_crm_automation_event', 'crm.score_changed', (int) $contact_id, array( 'score' => $score, 'from' => $from, 'to' => $to ) );
	}

	/**
	 * The latest score changes for a contact.
	 *
	 * @param int $contact_id
	 * @param int $limit
	 *
	 * @return object[]
	 */
	public static function history( $contact_id, $limit = 20 ) {
		global $wpdb;

		$t = FW_CRM_Installer::table( 'score_log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE contact_id = %d ORDER BY id DESC LIMIT %d", (int) $contact_id, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * The highest scores.
	 *
	 * @param string $score
	 * @param int    $limit
	 * @param int    $owner 0 = everyone's
	 *
	 * @return object[] { contact_id, points }
	 */
	public static function top( $score = 'default', $limit = 20, $owner = 0 ) {
		global $wpdb;

		$t   = FW_CRM_Installer::table( 'contact_scores' );
		$s   = FW_CRM_Installer::table( 'subscribers' );
		$sql = $wpdb->prepare( "SELECT c.contact_id, c.points FROM {$t} c INNER JOIN {$s} s ON s.id = c.contact_id AND s.deleted_at IS NULL WHERE c.score_key = %s AND c.points > 0", (string) $score ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $owner ) {
			$sql .= $wpdb->prepare( ' AND s.owner_id = %d', (int) $owner );
		}
		return (array) $wpdb->get_results( $sql . $wpdb->prepare( ' ORDER BY c.points DESC LIMIT %d', (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * A deal's score: the highest default score among its contacts.
	 *
	 * @param int $deal_id
	 *
	 * @return int
	 */
	public static function deal_score( $deal_id ) {
		$d = class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::get( (int) $deal_id ) : null;
		$best = 0;
		foreach ( $d ? (array) $d['contact_ids'] : array() as $cid ) {
			$best = max( $best, self::get( (int) $cid ) );
		}
		return $best;
	}

	/**
	 * Daily: scores nobody has touched for a while fade.
	 *
	 * @param int|null $now Timestamp (tests).
	 *
	 * @return int How many scores faded.
	 */
	public static function decay( $now = null ) {
		global $wpdb;

		$d = self::settings()['decay'];
		if ( empty( $d['on'] ) ) {
			return 0;
		}
		$now   = null === $now ? current_time( 'timestamp' ) : (int) $now; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$since = gmdate( 'Y-m-d H:i:s', $now - (int) $d['days'] * DAY_IN_SECONDS );
		$t     = FW_CRM_Installer::table( 'contact_scores' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT contact_id, score_key, points FROM {$t} WHERE points > 0 AND updated_at < %s AND ( decayed_at IS NULL OR decayed_at < %s ) LIMIT 2000", $since, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			$new = (int) floor( (int) $r->points * ( 100 - (int) $d['percent'] ) / 100 );
			self::set( (int) $r->contact_id, $new, (string) $r->score_key, sprintf( __( 'Faded: no activity for %d days', 'fw' ), (int) $d['days'] ) );
			// set() refreshed updated_at; record the fade and keep the original quiet date so it fades again later.
			$wpdb->update( $t, array( 'decayed_at' => gmdate( 'Y-m-d H:i:s', $now ), 'updated_at' => $since ), array( 'contact_id' => (int) $r->contact_id, 'score_key' => (string) $r->score_key ) );
		}
		return count( $rows );
	}

	/* ---------------------------------------------------------------------- *
	 * Segments, automations
	 * ---------------------------------------------------------------------- */

	/** @internal Named scores as segment fields (the default one is already "Score"). */
	public static function _segment_fields( $fields ) {
		foreach ( self::settings()['scores'] as $key => $label ) {
			if ( 'default' === $key ) {
				continue;
			}
			$fields[ 'score:' . $key ] = array(
				'label' => sprintf( __( 'Score: %s', 'fw' ), $label ),
				'type'  => 'number',
				'group' => __( 'Contact', 'fw' ),
				'sql'   => function ( $op, $value ) use ( $key ) {
					global $wpdb;
					$t   = FW_CRM_Installer::table( 'contact_scores' );
					$col = "COALESCE((SELECT points FROM {$t} cs WHERE cs.contact_id = s.id AND cs.score_key = " . $wpdb->prepare( '%s', $key ) . '), 0)';
					$map = array( 'eq' => '=', 'is' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=' );
					return isset( $map[ $op ] ) ? $col . ' ' . $map[ $op ] . ' ' . (float) $value : '1=0';
				},
			);
		}
		return $fields;
	}

	/** @internal */
	public static function _condition_fields( $fields ) {
		foreach ( self::settings()['scores'] as $key => $label ) {
			if ( 'default' === $key ) {
				continue;
			}
			$fields[ 'score:' . $key ] = array(
				'label' => sprintf( __( 'Score: %s', 'fw' ), $label ),
				'kind'  => 'number',
				'test'  => function ( $contact, $rule ) use ( $key ) {
					return FW_CRM_Conditions::compare( FW_CRM_Scoring::get( (int) $contact->id, $key ), (string) $rule['op'], $rule['value'] ?? '' );
				},
			);
		}
		return $fields;
	}

	/** @internal */
	public static function _triggers( $t ) {
		$t['crm.score_changed'] = array(
			'label'  => __( 'Score reached a value', 'fw' ),
			'group'  => __( 'Activity', 'fw' ),
			'fields' => array(
				'score'     => array( 'label' => __( 'Score', 'fw' ), 'type' => 'select', 'options' => self::settings()['scores'] ),
				'threshold' => array( 'label' => __( 'Reached at least', 'fw' ), 'type' => 'number', 'required' => true ),
			),
			// Fires once, on the way up past the value (not every time a score above it changes).
			'match'  => function ( $s, $ctx ) {
				$key = (string) ( $s['score'] ?? 'default' );
				$at  = (int) ( $s['threshold'] ?? 0 );
				return ( '' === $key ? 'default' : $key ) === ( $ctx['score'] ?? '' ) && (int) $ctx['from'] < $at && (int) $ctx['to'] >= $at;
			},
		);
		$t['crm.page_visited'] = array(
			'label'  => __( 'Visited a page', 'fw' ),
			'group'  => __( 'Activity', 'fw' ),
			'fields' => array( 'url' => array( 'label' => __( 'Address contains (empty = any page)', 'fw' ), 'type' => 'text' ) ),
			'match'  => function ( $s, $ctx ) {
				return '' === (string) ( $s['url'] ?? '' ) || false !== stripos( (string) ( $ctx['url'] ?? '' ), (string) $s['url'] );
			},
		);
		return $t;
	}

	/** @internal */
	public static function _actions( $a ) {
		$a['crm.add_points'] = array(
			'label'    => __( 'Add or remove points', 'fw' ),
			'group'    => __( 'Contact', 'fw' ),
			'settings' => array(
				'points' => array( 'label' => __( 'Points (negative to remove)', 'fw' ), 'type' => 'number', 'required' => true ),
				'score'  => array( 'label' => __( 'Score', 'fw' ), 'type' => 'select', 'options' => self::settings()['scores'] ),
			),
			'callback' => function ( $contact_id, $s, $ctx ) {
				$a  = FW_CRM_Automations::find( (int) ( $ctx['automation_id'] ?? 0 ) );
				$to = FW_CRM_Scoring::add( (int) $contact_id, (int) ( $s['points'] ?? 0 ), (string) ( $s['score'] ?? 'default' ), $a ? sprintf( __( 'Automation: %s', 'fw' ), $a->title ) : __( 'Automation', 'fw' ) );
				return array( 'message' => sprintf( __( 'now %d', 'fw' ), $to ) );
			},
		);
		return $a;
	}
}
