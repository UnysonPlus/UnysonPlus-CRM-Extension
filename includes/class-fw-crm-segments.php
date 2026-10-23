<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Rule-based segments: the CRM's one filter language.
 *
 * A rule tree is stored in a segment's filters JSON under `rules`:
 *
 *   { "match": "all" | "any", "rules": [ rule | group, … ] }
 *   rule  = { "field": "lifecycle_stage", "op": "is", "value": "customer" }
 *   group = { "match": "any", "rules": [ rule, … ] }   (one level of nesting)
 *
 * compile() turns a tree into one SQL condition on the contact alias `s`, which
 * FW_CRM_Subscribers::build_clauses() adds to every query given `rules` — so the
 * contacts list, segment counts, campaign audiences, REST and access checks all
 * share it. Tags, lists, custom fields and activity are EXISTS subqueries on
 * their indexed tables; nothing is denormalised.
 *
 * Fields come from fields() (filter unysonplus_crm_segment_fields); every custom
 * contact field appears as `field:<key>`, typed by its definition.
 */
class FW_CRM_Segments {

	/** @var array Per-request cache for contact_matches(). */
	private static $match_cache = array();

	/* ---------------------------------------------------------------------- *
	 * The field registry
	 * ---------------------------------------------------------------------- */

	/**
	 * Operators per field type: op => label.
	 *
	 * @return array
	 */
	public static function operators() {
		return array(
			'text'     => array(
				'is'           => __( 'is', 'fw' ),
				'is_not'       => __( 'is not', 'fw' ),
				'contains'     => __( 'contains', 'fw' ),
				'not_contains' => __( 'does not contain', 'fw' ),
				'starts_with'  => __( 'starts with', 'fw' ),
				'empty'        => __( 'is empty', 'fw' ),
				'not_empty'    => __( 'is not empty', 'fw' ),
			),
			'number'   => array(
				'eq'        => __( 'equals', 'fw' ),
				'neq'       => __( 'does not equal', 'fw' ),
				'gt'        => __( 'is more than', 'fw' ),
				'gte'       => __( 'is at least', 'fw' ),
				'lt'        => __( 'is less than', 'fw' ),
				'lte'       => __( 'is at most', 'fw' ),
				'empty'     => __( 'is empty', 'fw' ),
				'not_empty' => __( 'is not empty', 'fw' ),
			),
			'date'     => array(
				'in_last_days'     => __( 'is in the last … days', 'fw' ),
				'not_in_last_days' => __( 'is not in the last … days', 'fw' ),
				'in_next_days'     => __( 'is in the next … days', 'fw' ),
				'before'           => __( 'is before', 'fw' ),
				'after'            => __( 'is after', 'fw' ),
				'on'               => __( 'is on', 'fw' ),
				'empty'            => __( 'is empty', 'fw' ),
				'not_empty'        => __( 'is not empty', 'fw' ),
			),
			'choice'   => array(
				'in'     => __( 'is any of', 'fw' ),
				'not_in' => __( 'is none of', 'fw' ),
			),
			'tags'     => array(
				'has_any'  => __( 'has any of', 'fw' ),
				'has_all'  => __( 'has all of', 'fw' ),
				'has_none' => __( 'has none of', 'fw' ),
			),
			'boolean'  => array(
				'is_true'  => __( 'yes', 'fw' ),
				'is_false' => __( 'no', 'fw' ),
			),
			'activity' => array(
				'happened'     => __( 'happened in the last … days', 'fw' ),
				'not_happened' => __( 'did not happen in the last … days', 'fw' ),
			),
		);
	}

	/**
	 * Every field a rule can use.
	 *
	 * @return array key => { label, type, group, column?, choices?, store? }
	 */
	public static function fields() {
		static $cache = null;
		if ( null !== $cache && ! self::$fields_dirty ) {
			return $cache;
		}
		self::$fields_dirty = false;

		$statuses = array(
			'subscribed'   => __( 'Subscribed', 'fw' ),
			'pending'      => __( 'Waiting to confirm', 'fw' ),
			'unsubscribed' => __( 'Unsubscribed', 'fw' ),
			'bounced'      => __( 'Bounced', 'fw' ),
			'complained'   => __( 'Marked as spam', 'fw' ),
			'none'         => __( 'Not subscribed', 'fw' ),
		);
		$owners = array( 'me' => __( 'Me (whoever is looking)', 'fw' ), '0' => __( 'Nobody', 'fw' ) );
		foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) {
			$owners[ (string) $u->ID ] = $u->display_name;
		}
		$tags = array();
		foreach ( FW_CRM_Lists::all( 'tag' ) as $t ) {
			$tags[ $t->slug ] = $t->title;
		}
		$lists = array();
		foreach ( FW_CRM_Lists::all( 'list' ) as $l ) {
			$lists[ $l->slug ] = $l->title;
		}
		$activity = array();
		foreach ( FW_CRM_Activity::types() as $type => $def ) {
			$activity[ $type ] = $def['label'];
		}

		$person  = __( 'Contact', 'fw' );
		$email   = __( 'Email and marketing', 'fw' );
		$dates   = __( 'Dates and activity', 'fw' );
		$custom  = __( 'Your fields', 'fw' );

		$fields = array(
			'first_name'       => array( 'label' => __( 'First name', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'first_name' ),
			'last_name'        => array( 'label' => __( 'Last name', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'last_name' ),
			'email'            => array( 'label' => __( 'Email', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'email' ),
			'phone'            => array( 'label' => __( 'Phone', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'phone' ),
			'job_title'        => array( 'label' => __( 'Job title', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'job_title' ),
			'company'          => array( 'label' => __( 'Company name', 'fw' ), 'type' => 'text', 'group' => $person ),
			'city'             => array( 'label' => __( 'City', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'city' ),
			'region'           => array( 'label' => __( 'Region', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'region' ),
			'country'          => array( 'label' => __( 'Country (2 letters)', 'fw' ), 'type' => 'text', 'group' => $person, 'column' => 'country' ),
			'lifecycle_stage'  => array( 'label' => __( 'Lifecycle stage', 'fw' ), 'type' => 'choice', 'group' => $person, 'column' => 'lifecycle_stage', 'choices' => FW_CRM_Contacts::stages() ),
			'owner_id'         => array( 'label' => __( 'Owner', 'fw' ), 'type' => 'choice', 'group' => $person, 'column' => 'owner_id', 'choices' => $owners ),
			'score'            => array( 'label' => __( 'Score', 'fw' ), 'type' => 'number', 'group' => $person, 'column' => 'score' ),
			'lifetime_value'   => array( 'label' => __( 'Lifetime value', 'fw' ), 'type' => 'number', 'group' => $person, 'column' => 'lifetime_value' ),
			'has_user'         => array( 'label' => __( 'Has a site account', 'fw' ), 'type' => 'boolean', 'group' => $person ),
			'status'           => array( 'label' => __( 'Email status', 'fw' ), 'type' => 'choice', 'group' => $email, 'column' => 'status', 'choices' => $statuses ),
			'tag'              => array( 'label' => __( 'Tags', 'fw' ), 'type' => 'tags', 'group' => $email, 'choices' => $tags ),
			'list'             => array( 'label' => __( 'Lists (subscribed)', 'fw' ), 'type' => 'tags', 'group' => $email, 'choices' => $lists ),
			'source'           => array( 'label' => __( 'Source', 'fw' ), 'type' => 'text', 'group' => $email, 'column' => 'source' ),
			'created_at'       => array( 'label' => __( 'Added', 'fw' ), 'type' => 'date', 'group' => $dates, 'column' => 'created_at' ),
			'last_activity_at' => array( 'label' => __( 'Last activity', 'fw' ), 'type' => 'date', 'group' => $dates, 'column' => 'last_activity_at' ),
			'activity'         => array( 'label' => __( 'Timeline activity', 'fw' ), 'type' => 'activity', 'group' => $dates, 'choices' => $activity ),
		);

		$map = array(
			'text'        => 'text',
			'textarea'    => 'text',
			'url'         => 'text',
			'email'       => 'text',
			'phone'       => 'text',
			'autonumber'  => 'text',
			'number'      => 'number',
			'money'       => 'number',
			'lookup'      => 'number',
			'date'        => 'date',
			'datetime'    => 'date',
			'choice'      => 'choice',
			'multichoice' => 'tags',
			'checkbox'    => 'boolean',
		);
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $def ) {
			if ( ! isset( $map[ $def['type'] ] ) ) {
				continue;
			}
			$field = array(
				'label' => $def['label'],
				'type'  => $map[ $def['type'] ],
				'group' => $custom,
				'store' => $def['type'],
			);
			if ( ! empty( $def['choices'] ) ) {
				$field['choices'] = array_combine( $def['choices'], $def['choices'] );
			}
			$fields[ 'field:' . $def['key'] ] = $field;
		}

		/** Filters the fields segment rules can use (key => { label, type, group, … }). */
		$cache = (array) apply_filters( 'unysonplus_crm_segment_fields', $fields );

		return $cache;
	}

	/** @var bool Rebuild fields() on the next call (after a field or tag is added). */
	private static $fields_dirty = false;

	/** Rebuild the field registry on its next use. */
	public static function flush_fields() {
		self::$fields_dirty = true;
	}

	/* ---------------------------------------------------------------------- *
	 * Sanitising
	 * ---------------------------------------------------------------------- */

	/**
	 * Clean a rule tree: unknown fields and operators are dropped, values are
	 * coerced to what the operator needs, and groups nest at most one level.
	 *
	 * @param mixed $tree
	 * @param int   $depth
	 *
	 * @return array { match, rules }
	 */
	public static function sanitize( $tree, $depth = 0 ) {
		if ( is_string( $tree ) ) {
			$tree = json_decode( $tree, true );
		}
		$tree   = is_array( $tree ) ? $tree : array();
		$fields = self::fields();
		$ops    = self::operators();
		$out    = array(
			'match' => isset( $tree['match'] ) && 'any' === $tree['match'] ? 'any' : 'all',
			'rules' => array(),
		);

		foreach ( (array) ( $tree['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			if ( isset( $rule['rules'] ) ) {
				if ( $depth < 1 ) {
					$group = self::sanitize( $rule, $depth + 1 );
					if ( $group['rules'] ) {
						$out['rules'][] = $group;
					}
				}
				continue;
			}
			$key = (string) ( $rule['field'] ?? '' );
			$op  = (string) ( $rule['op'] ?? '' );
			if ( ! isset( $fields[ $key ] ) || ! isset( $ops[ $fields[ $key ]['type'] ][ $op ] ) ) {
				continue;
			}
			$value = self::sanitize_value( $fields[ $key ]['type'], $op, $rule['value'] ?? null );
			if ( null === $value ) {
				continue;
			}
			$out['rules'][] = array( 'field' => $key, 'op' => $op, 'value' => $value );
		}

		return $out;
	}

	/**
	 * @param string $type
	 * @param string $op
	 * @param mixed  $value
	 *
	 * @return mixed Null when the rule is unusable.
	 */
	private static function sanitize_value( $type, $op, $value ) {
		if ( in_array( $op, array( 'empty', 'not_empty', 'is_true', 'is_false' ), true ) ) {
			return '';
		}
		switch ( $type ) {
			case 'number':
				return is_numeric( $value ) ? $value + 0 : null;
			case 'date':
				if ( in_array( $op, array( 'in_last_days', 'not_in_last_days', 'in_next_days' ), true ) ) {
					return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
				}
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : null;
			case 'choice':
			case 'tags':
				$values = array_values( array_filter( array_map( 'strval', (array) $value ), 'strlen' ) );
				return $values ? array_map( 'sanitize_text_field', $values ) : null;
			case 'activity':
				$value = (array) $value;
				$kind  = sanitize_key( (string) ( $value['type'] ?? '' ) );
				$days  = (int) ( $value['days'] ?? 0 );
				return '' !== $kind && $days > 0 ? array( 'type' => $kind, 'days' => $days ) : null;
			default:
				$value = sanitize_text_field( (string) $value );
				return '' !== $value ? $value : null;
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Compiling
	 * ---------------------------------------------------------------------- */

	/**
	 * One SQL condition (on contact alias `s`) for a sanitized tree.
	 *
	 * @param array $tree
	 *
	 * @return string
	 */
	public static function compile( array $tree ) {
		$parts = array();
		foreach ( (array) ( $tree['rules'] ?? array() ) as $rule ) {
			$parts[] = isset( $rule['rules'] ) ? self::compile( $rule ) : self::compile_rule( $rule );
		}
		$parts = array_filter( $parts, 'strlen' );
		if ( ! $parts ) {
			return '1=1';
		}

		return '(' . implode( 'any' === ( $tree['match'] ?? 'all' ) ? ' OR ' : ' AND ', $parts ) . ')';
	}

	/**
	 * @param array $rule
	 *
	 * @return string
	 */
	private static function compile_rule( array $rule ) {
		global $wpdb;

		$fields = self::fields();
		$key    = $rule['field'];
		$op     = $rule['op'];
		$value  = $rule['value'];
		if ( ! isset( $fields[ $key ] ) ) {
			return '1=0';
		}
		$field = $fields[ $key ];

		// A field added through the unysonplus_crm_segment_fields filter can bring its own SQL
		// (the contact row is aliased `s`): callable( $op, $value ) : string.
		if ( isset( $field['sql'] ) && is_callable( $field['sql'] ) ) {
			return (string) call_user_func( $field['sql'], $op, $value );
		}

		if ( 0 === strpos( $key, 'field:' ) ) {
			return self::compile_custom( substr( $key, 6 ), $field, $op, $value );
		}

		switch ( $key ) {
			case 'tag':
			case 'list':
				return self::compile_membership( $key, $op, (array) $value );

			case 'activity':
				$table = FW_CRM_Installer::table( 'activity' );
				$sql   = $wpdb->prepare(
					"EXISTS (SELECT 1 FROM {$table} a WHERE a.object_type = 'contact' AND a.object_id = s.id AND a.type = %s AND a.created_at >= %s)", // phpcs:ignore WordPress.DB.PreparedSQL
					$value['type'],
					self::days_ago( (int) $value['days'] )
				);
				return 'not_happened' === $op ? 'NOT ' . $sql : $sql;

			case 'company':
				$companies = FW_CRM_Installer::table( 'companies' );
				$relations = FW_CRM_Installer::table( 'relations' );
				$inner     = self::text_condition( 'c.name', $op, $value, true );
				$exists    = "EXISTS (SELECT 1 FROM {$relations} r INNER JOIN {$companies} c ON c.id = r.to_id AND c.deleted_at IS NULL"
					. " WHERE r.from_type = 'contact' AND r.from_id = s.id AND r.to_type = 'company' AND {$inner})";
				if ( in_array( $op, array( 'is_not', 'not_contains', 'empty' ), true ) ) {
					// "Company is not X" = no linked company matches X.
					$positive = array( 'is_not' => 'is', 'not_contains' => 'contains', 'empty' => 'not_empty' )[ $op ];
					$inner    = self::text_condition( 'c.name', $positive, $value, true );
					return "NOT EXISTS (SELECT 1 FROM {$relations} r INNER JOIN {$companies} c ON c.id = r.to_id AND c.deleted_at IS NULL"
						. " WHERE r.from_type = 'contact' AND r.from_id = s.id AND r.to_type = 'company' AND {$inner})";
				}
				return $exists;

			case 'has_user':
				return 'is_true' === $op ? 's.user_id > 0' : 's.user_id = 0';
		}

		$col = 's.' . $field['column'];
		switch ( $field['type'] ) {
			case 'text':
				return self::text_condition( $col, $op, $value );
			case 'number':
				return self::number_condition( $col, $op, $value );
			case 'date':
				return self::date_condition( $col, $op, $value );
			case 'choice':
				$values = array_map( function ( $v ) {
					return 'me' === $v ? (string) get_current_user_id() : $v;
				}, (array) $value );
				$in     = implode( ',', array_map( function ( $v ) use ( $wpdb ) {
					return $wpdb->prepare( '%s', $v );
				}, $values ) );
				return 'not_in' === $op ? "{$col} NOT IN ({$in})" : "{$col} IN ({$in})";
		}

		return '1=0';
	}

	/**
	 * @param string $kind  tag|list
	 * @param string $op
	 * @param array  $slugs
	 *
	 * @return string
	 */
	private static function compile_membership( $kind, $op, array $slugs ) {
		global $wpdb;

		$pivot  = FW_CRM_Installer::table( 'subscriber_pivot' );
		$lists  = FW_CRM_Installer::table( 'lists' );
		$in     = implode( ',', array_map( function ( $v ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', sanitize_key( $v ) );
		}, $slugs ) );
		$status = 'list' === $kind ? " AND p.status = 'subscribed'" : '';
		$base   = "FROM {$pivot} p INNER JOIN {$lists} l ON l.id = p.object_id AND l.type = p.object_type"
			. " WHERE p.subscriber_id = s.id AND p.object_type = '{$kind}'{$status} AND l.slug IN ({$in})";

		if ( 'has_all' === $op ) {
			return '(SELECT COUNT(DISTINCT l.slug) ' . $base . ') = ' . count( array_unique( array_map( 'sanitize_key', $slugs ) ) );
		}
		if ( 'has_none' === $op ) {
			return 'NOT EXISTS (SELECT 1 ' . $base . ')';
		}

		return 'EXISTS (SELECT 1 ' . $base . ')';
	}

	/**
	 * @param string $key   Custom field key.
	 * @param array  $field Registry entry.
	 * @param string $op
	 * @param mixed  $value
	 *
	 * @return string
	 */
	private static function compile_custom( $key, array $field, $op, $value ) {
		global $wpdb;

		$table = FW_CRM_Installer::table( 'field_values' );
		$base  = $wpdb->prepare( "FROM {$table} fv WHERE fv.object_type = 'contact' AND fv.object_id = s.id AND fv.field_key = %s", $key ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( 'empty' === $op || 'not_empty' === $op ) {
			$filled = "EXISTS (SELECT 1 {$base} AND fv.value IS NOT NULL AND fv.value <> '' AND fv.value <> '[]')";
			return 'empty' === $op ? 'NOT ' . $filled : $filled;
		}

		switch ( $field['type'] ) {
			case 'number':
				$cond = self::number_condition( 'fv.value_num', $op, $value, true );
				break;
			case 'date':
				$cond = self::date_condition( 'fv.value_date', $op, $value, true );
				if ( 'not_in_last_days' === $op ) {
					// "Not in the last N days" includes having no date at all.
					return 'NOT EXISTS (SELECT 1 ' . $base . ' AND ' . self::date_condition( 'fv.value_date', 'in_last_days', $value, true ) . ')';
				}
				break;
			case 'boolean':
				return 'is_true' === $op
					? "EXISTS (SELECT 1 {$base} AND fv.value_num = 1)"
					: "NOT EXISTS (SELECT 1 {$base} AND fv.value_num = 1)";
			case 'choice':
				$in   = implode( ',', array_map( function ( $v ) use ( $wpdb ) {
					return $wpdb->prepare( '%s', $v );
				}, (array) $value ) );
				$cond = "fv.value IN ({$in})";
				if ( 'not_in' === $op ) {
					return "NOT EXISTS (SELECT 1 {$base} AND {$cond})";
				}
				break;
			case 'tags': // A multiple-choice field, stored as a JSON array.
				$likes = array();
				foreach ( (array) $value as $v ) {
					$likes[] = $wpdb->prepare( 'fv.value LIKE %s', '%' . $wpdb->esc_like( wp_json_encode( (string) $v ) ) . '%' );
				}
				if ( 'has_none' === $op ) {
					return 'NOT EXISTS (SELECT 1 ' . $base . ' AND (' . implode( ' OR ', $likes ) . '))';
				}
				$cond = '(' . implode( 'has_all' === $op ? ' AND ' : ' OR ', $likes ) . ')';
				break;
			default:
				if ( in_array( $op, array( 'is_not', 'not_contains' ), true ) ) {
					$positive = 'is_not' === $op ? 'is' : 'contains';
					return 'NOT EXISTS (SELECT 1 ' . $base . ' AND ' . self::text_condition( 'fv.value', $positive, $value, true ) . ')';
				}
				$cond = self::text_condition( 'fv.value', $op, $value, true );
		}

		return "EXISTS (SELECT 1 {$base} AND {$cond})";
	}

	/**
	 * @param string $col
	 * @param string $op
	 * @param mixed  $value
	 * @param bool   $strict Inside an EXISTS: no NULL-handling for negations.
	 *
	 * @return string
	 */
	private static function text_condition( $col, $op, $value, $strict = false ) {
		global $wpdb;

		$value = (string) $value;
		switch ( $op ) {
			case 'is':
				return $wpdb->prepare( "{$col} = %s", $value ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'is_not':
				return $wpdb->prepare( $strict ? "{$col} <> %s" : "({$col} <> %s OR {$col} IS NULL)", $value ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'contains':
				return $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'not_contains':
				return $wpdb->prepare( $strict ? "{$col} NOT LIKE %s" : "({$col} NOT LIKE %s OR {$col} IS NULL)", '%' . $wpdb->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'starts_with':
				return $wpdb->prepare( "{$col} LIKE %s", $wpdb->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'empty':
				return "({$col} IS NULL OR {$col} = '')";
			case 'not_empty':
				return "({$col} IS NOT NULL AND {$col} <> '')";
		}
		return '1=0';
	}

	/**
	 * @param string $col
	 * @param string $op
	 * @param mixed  $value
	 * @param bool   $strict
	 *
	 * @return string
	 */
	private static function number_condition( $col, $op, $value, $strict = false ) {
		global $wpdb;

		$sql = array( 'eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=' );
		if ( isset( $sql[ $op ] ) ) {
			$cond = $wpdb->prepare( "{$col} {$sql[ $op ]} %f", (float) $value ); // phpcs:ignore WordPress.DB.PreparedSQL
			return 'neq' === $op && ! $strict ? "({$cond} OR {$col} IS NULL)" : $cond;
		}
		if ( 'empty' === $op ) {
			return "({$col} IS NULL OR {$col} = 0)";
		}
		if ( 'not_empty' === $op ) {
			return "({$col} IS NOT NULL AND {$col} <> 0)";
		}
		return '1=0';
	}

	/**
	 * @param string $col
	 * @param string $op
	 * @param mixed  $value Y-m-d, or a number of days.
	 * @param bool   $strict
	 *
	 * @return string
	 */
	private static function date_condition( $col, $op, $value, $strict = false ) {
		global $wpdb;

		switch ( $op ) {
			case 'in_last_days':
				return $wpdb->prepare( "{$col} >= %s", self::days_ago( (int) $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'not_in_last_days':
				$cond = $wpdb->prepare( "{$col} < %s", self::days_ago( (int) $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				return $strict ? $cond : "({$cond} OR {$col} IS NULL)";
			case 'in_next_days':
				return $wpdb->prepare( "{$col} BETWEEN %s AND %s", current_time( 'mysql' ), self::days_ago( -(int) $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'before':
				return $wpdb->prepare( "{$col} < %s", $value . ' 00:00:00' ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'after':
				return $wpdb->prepare( "{$col} > %s", $value . ' 23:59:59' ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'on':
				return $wpdb->prepare( "{$col} BETWEEN %s AND %s", $value . ' 00:00:00', $value . ' 23:59:59' ); // phpcs:ignore WordPress.DB.PreparedSQL
			case 'empty':
				return "({$col} IS NULL OR {$col} < '1000-01-01')";
			case 'not_empty':
				return "({$col} IS NOT NULL AND {$col} >= '1000-01-01')";
		}
		return '1=0';
	}

	/**
	 * @param int $days Negative for the future.
	 *
	 * @return string A local MySQL datetime.
	 */
	private static function days_ago( $days ) {
		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/* ---------------------------------------------------------------------- *
	 * Using segments
	 * ---------------------------------------------------------------------- */

	/**
	 * Save a rule-based segment.
	 *
	 * @param string $title
	 * @param mixed  $rules A tree (array or JSON).
	 * @param int    $id    Update this segment.
	 *
	 * @return object|WP_Error The segment row.
	 */
	public static function save( $title, $rules, $id = 0 ) {
		$tree = self::sanitize( $rules );
		if ( ! $tree['rules'] ) {
			return new WP_Error( 'fw_crm_empty_segment', __( 'Add at least one complete rule to the segment.', 'fw' ) );
		}
		$result = FW_CRM_Service::save_segment( array( 'rules' => $tree ), $title, $id );
		if ( ! is_wp_error( $result ) ) {
			delete_transient( 'fw_crm_seg_count_' . (int) $result->id );
			self::$match_cache = array();
		}
		return $result;
	}

	/**
	 * How many live contacts a segment matches, cached for five minutes.
	 *
	 * @param object|int $segment
	 * @param bool       $fresh Skip the cache.
	 *
	 * @return int
	 */
	public static function count( $segment, $fresh = false ) {
		$segment = is_numeric( $segment ) ? FW_CRM_Lists::find_segment( (int) $segment ) : $segment;
		if ( ! $segment ) {
			return 0;
		}
		$key = 'fw_crm_seg_count_' . (int) $segment->id;
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( false !== $cached && is_array( $cached ) && $cached['v'] === (string) $segment->updated_at ) {
				return (int) $cached['n'];
			}
		}
		$n = FW_CRM_Subscribers::count( array( 'segment' => (int) $segment->id ) );
		set_transient( $key, array( 'n' => $n, 'v' => (string) $segment->updated_at ), 5 * MINUTE_IN_SECONDS );
		return $n;
	}

	/**
	 * Count for an unsaved tree (the builder's live preview).
	 *
	 * @param mixed $rules
	 *
	 * @return int
	 */
	public static function preview_count( $rules ) {
		return FW_CRM_Subscribers::count( array( 'rules' => self::sanitize( $rules ) ) );
	}

	/**
	 * Does one contact match a segment? One query, cached for the request.
	 *
	 * @param int $segment_id
	 * @param int $contact_id
	 *
	 * @return bool
	 */
	public static function contact_matches( $segment_id, $contact_id ) {
		static $definitions = array();

		$segment_id = (int) $segment_id;
		$key        = $segment_id . ':' . (int) $contact_id;
		if ( ! isset( self::$match_cache[ $key ] ) ) {
			if ( ! array_key_exists( $segment_id, $definitions ) ) {
				$row                        = FW_CRM_Lists::find_segment( $segment_id );
				$definitions[ $segment_id ] = $row ? FW_CRM_Lists::segment_query_args( $row ) : null;
			}
			$args = $definitions[ $segment_id ];
			// The segment's own args, never 'segment' => id, so it is not loaded twice.
			self::$match_cache[ $key ] = (int) $contact_id && null !== $args
				&& FW_CRM_Subscribers::count( array_merge( $args, array( 'ids' => array( (int) $contact_id ) ) ) ) > 0;
		}
		return self::$match_cache[ $key ];
	}

	/** Forget cached contact_matches() answers. */
	public static function flush_cache() {
		self::$match_cache = array();
	}

	/**
	 * A plain sentence for a tree: "Lifecycle stage is any of Customer, and Tags has any of VIP".
	 *
	 * @param array $tree
	 *
	 * @return string
	 */
	public static function describe( array $tree ) {
		$fields = self::fields();
		$ops    = self::operators();
		$parts  = array();
		foreach ( (array) ( $tree['rules'] ?? array() ) as $rule ) {
			if ( isset( $rule['rules'] ) ) {
				$parts[] = '(' . self::describe( $rule ) . ')';
				continue;
			}
			if ( ! isset( $fields[ $rule['field'] ] ) ) {
				continue;
			}
			$f     = $fields[ $rule['field'] ];
			$label = $ops[ $f['type'] ][ $rule['op'] ] ?? $rule['op'];
			$value = $rule['value'];
			if ( is_array( $value ) && isset( $value['type'] ) ) {
				$what    = $f['choices'][ $value['type'] ] ?? $value['type'];
				$parts[] = 'not_happened' === $rule['op']
					? sprintf( __( 'no "%1$s" in the last %2$s days', 'fw' ), $what, number_format_i18n( $value['days'] ) )
					: sprintf( __( '"%1$s" in the last %2$s days', 'fw' ), $what, number_format_i18n( $value['days'] ) );
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( function ( $v ) use ( $f ) {
					return isset( $f['choices'][ $v ] ) ? $f['choices'][ $v ] : $v;
				}, $value ) );
			}
			if ( false !== strpos( $label, '…' ) ) {
				$parts[] = $f['label'] . ' ' . str_replace( '…', (string) $value, $label );
			} else {
				$parts[] = trim( $f['label'] . ' ' . $label . ' ' . $value );
			}
		}

		return $parts ? implode( 'any' === ( $tree['match'] ?? 'all' ) ? __( ', or ', 'fw' ) : __( ', and ', 'fw' ), $parts ) : __( 'everyone', 'fw' );
	}
}
