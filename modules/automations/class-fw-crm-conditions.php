<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Conditions about a contact, shared by if / else steps, exit conditions and goals.
 *
 * A group: { match: all|any, rules: [ { field, op, value } ] }. Fields:
 *   tag, list                 op is | is_not (value = slug)
 *   status, lifecycle_stage   op is | is_not
 *   email, first_name, last_name, phone, job_title, city, country, score, lifetime_value
 *   cf:<key>                  a contact custom field
 *   deal_stage                the run's deal (context deal_id) is in a stage (value = stage id)
 *   opened, clicked           the contact opened / clicked a campaign email during this run
 *   segment                   op is | is_not (value = segment id)
 * Text and number ops: eq, neq, contains, not_contains, empty, not_empty, gt, lt.
 */
class FW_CRM_Conditions {

	/** @return array op => label */
	public static function ops() {
		return array(
			'is'           => __( 'is', 'fw' ),
			'is_not'       => __( 'is not', 'fw' ),
			'eq'           => __( 'equals', 'fw' ),
			'neq'          => __( 'does not equal', 'fw' ),
			'contains'     => __( 'contains', 'fw' ),
			'not_contains' => __( 'does not contain', 'fw' ),
			'empty'        => __( 'is empty', 'fw' ),
			'not_empty'    => __( 'is not empty', 'fw' ),
			'gt'           => __( 'is more than', 'fw' ),
			'lt'           => __( 'is less than', 'fw' ),
		);
	}

	/**
	 * The fields a rule can test, for the editor.
	 *
	 * @return array key => { label, kind: set|text|number|bool, options? }
	 */
	public static function fields() {
		$tags  = array();
		foreach ( FW_CRM_Lists::all( 'tag' ) as $t ) {
			$tags[ $t->slug ] = $t->title;
		}
		$lists = array();
		foreach ( FW_CRM_Lists::all( 'list' ) as $l ) {
			$lists[ $l->slug ] = $l->title;
		}
		$fields = array(
			'tag'             => array( 'label' => __( 'Tag', 'fw' ), 'kind' => 'set', 'options' => $tags ),
			'list'            => array( 'label' => __( 'List', 'fw' ), 'kind' => 'set', 'options' => $lists ),
			'status'          => array( 'label' => __( 'Email status', 'fw' ), 'kind' => 'set', 'options' => array( 'subscribed' => __( 'Subscribed', 'fw' ), 'pending' => __( 'Pending', 'fw' ), 'unsubscribed' => __( 'Unsubscribed', 'fw' ), 'bounced' => __( 'Bounced', 'fw' ), 'complained' => __( 'Complained', 'fw' ), 'none' => __( 'Not subscribed', 'fw' ) ) ),
			'lifecycle_stage' => array( 'label' => __( 'Lifecycle stage', 'fw' ), 'kind' => 'set', 'options' => FW_CRM_Contacts::stages() ),
			'opened'          => array( 'label' => __( 'Opened an email during this automation', 'fw' ), 'kind' => 'bool' ),
			'clicked'         => array( 'label' => __( 'Clicked an email during this automation', 'fw' ), 'kind' => 'bool' ),
		);
		$segments = array();
		foreach ( FW_CRM_Lists::segments() as $s ) {
			$segments[ (string) $s->id ] = $s->title;
		}
		if ( $segments ) {
			$fields['segment'] = array( 'label' => __( 'Segment', 'fw' ), 'kind' => 'set', 'options' => $segments );
		}
		foreach ( array( 'email' => __( 'Email', 'fw' ), 'first_name' => __( 'First name', 'fw' ), 'last_name' => __( 'Last name', 'fw' ), 'phone' => __( 'Phone', 'fw' ), 'job_title' => __( 'Job title', 'fw' ), 'city' => __( 'City', 'fw' ), 'country' => __( 'Country', 'fw' ) ) as $k => $label ) {
			$fields[ $k ] = array( 'label' => $label, 'kind' => 'text' );
		}
		$fields['score']          = array( 'label' => __( 'Score', 'fw' ), 'kind' => 'number' );
		$fields['lifetime_value'] = array( 'label' => __( 'Lifetime value', 'fw' ), 'kind' => 'number' );
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $d ) {
			$num = in_array( $d['type'] ?? '', array( 'number', 'money' ), true );
			$fields[ 'cf:' . $d['key'] ] = array( 'label' => (string) $d['label'], 'kind' => $num ? 'number' : 'text' );
		}
		if ( class_exists( 'FW_CRM_Pipelines' ) ) {
			$stages = array();
			foreach ( FW_CRM_Pipelines::all() as $p ) {
				foreach ( FW_CRM_Pipelines::stages( (int) $p->id ) as $s ) {
					$stages[ (string) $s->id ] = $p->name . ': ' . $s->name;
				}
			}
			$fields['deal_stage'] = array( 'label' => __( 'The deal\'s stage', 'fw' ), 'kind' => 'set', 'options' => $stages );
		}
		/** Filters the fields automation conditions can test. Add `test` (callable( $contact, $rule, $run )) for your own. */
		return (array) apply_filters( 'unysonplus_crm_automation_condition_fields', $fields );
	}

	/**
	 * @param array $group
	 *
	 * @return array
	 */
	public static function clean( array $group ) {
		$rules = array();
		foreach ( (array) ( $group['rules'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) || '' === (string) ( $r['field'] ?? '' ) ) {
				continue;
			}
			$rules[] = array(
				'field' => preg_replace( '/[^a-zA-Z0-9_:\-]/', '', (string) $r['field'] ),
				'op'    => array_key_exists( $r['op'] ?? '', self::ops() ) ? $r['op'] : 'is',
				'value' => is_array( $r['value'] ?? '' ) ? array_map( 'sanitize_text_field', $r['value'] ) : sanitize_text_field( (string) ( $r['value'] ?? '' ) ),
			);
		}
		return array( 'match' => 'all' === ( $group['match'] ?? 'all' ) ? 'all' : 'any', 'rules' => $rules );
	}

	/**
	 * Does a contact meet a group? An empty group is true.
	 *
	 * @param array       $group
	 * @param int         $contact_id
	 * @param object|null $run
	 *
	 * @return bool
	 */
	public static function check( array $group, $contact_id, $run = null ) {
		$rules = (array) ( $group['rules'] ?? array() );
		if ( ! $rules ) {
			return true;
		}
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		if ( ! $contact ) {
			return false;
		}
		$any = 'any' === ( $group['match'] ?? 'all' );
		foreach ( $rules as $rule ) {
			$ok = self::rule( $contact, (array) $rule, $run );
			if ( $any && $ok ) {
				return true;
			}
			if ( ! $any && ! $ok ) {
				return false;
			}
		}
		return ! $any;
	}

	/**
	 * @param object      $contact
	 * @param array       $rule
	 * @param object|null $run
	 *
	 * @return bool
	 */
	public static function rule( $contact, array $rule, $run = null ) {
		$field = (string) ( $rule['field'] ?? '' );
		$op    = (string) ( $rule['op'] ?? 'is' );
		$value = $rule['value'] ?? '';
		$neg   = in_array( $op, array( 'is_not', 'neq', 'not_contains' ), true );

		$custom = self::fields()[ $field ]['test'] ?? null;
		if ( is_callable( $custom ) ) {
			return (bool) call_user_func( $custom, $contact, $rule, $run );
		}
		switch ( $field ) {
			case 'tag':
			case 'list':
				$has = FW_CRM_Subscribers::has_lists( (int) $contact->id, array( (string) $value ), 'any', $field );
				return $neg ? ! $has : $has;
			case 'segment':
				$in = class_exists( 'FW_CRM_Segments' ) && FW_CRM_Segments::contact_matches( (int) $value, (int) $contact->id );
				return $neg ? ! $in : $in;
			case 'opened':
			case 'clicked':
				$did = $run && self::engaged( $run, 'opened' === $field ? 'open' : 'click' );
				return ( 'is_not' === $op || 'neq' === $op || '0' === (string) $value ) ? ! $did : $did;
			case 'deal_stage':
				$deal = $run && ! empty( $run->context['deal_id'] ) && class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::find( (int) $run->context['deal_id'] ) : null;
				$in   = $deal && (string) $deal->stage_id === (string) $value;
				return $neg ? ! $in : $in;
		}
		if ( 0 === strpos( $field, 'cf:' ) ) {
			$vals = FW_CRM_Fields::get_values( 'contact', (int) $contact->id );
			$have = $vals[ substr( $field, 3 ) ] ?? '';
		} else {
			$have = isset( $contact->$field ) ? $contact->$field : '';
		}
		return self::compare( $have, $op, $value );
	}

	/**
	 * @param mixed  $have
	 * @param string $op
	 * @param mixed  $want
	 *
	 * @return bool
	 */
	public static function compare( $have, $op, $want ) {
		$have = is_array( $have ) ? implode( ', ', $have ) : (string) $have;
		$want = is_array( $want ) ? implode( ', ', $want ) : (string) $want;
		$h    = strtolower( trim( $have ) );
		$w    = strtolower( trim( $want ) );
		switch ( $op ) {
			case 'is':
			case 'eq':
				return $h === $w;
			case 'is_not':
			case 'neq':
				return $h !== $w;
			case 'contains':
				return '' !== $w && false !== strpos( $h, $w );
			case 'not_contains':
				return '' === $w || false === strpos( $h, $w );
			case 'empty':
				return '' === $h;
			case 'not_empty':
				return '' !== $h;
			case 'gt':
				return is_numeric( $have ) && (float) $have > (float) $want;
			case 'lt':
				return is_numeric( $have ) && (float) $have < (float) $want;
		}
		return false;
	}

	/**
	 * Did the contact open / click a campaign email since the run started?
	 *
	 * @param object $run
	 * @param string $type open|click
	 *
	 * @return bool
	 */
	private static function engaged( $run, $type ) {
		global $wpdb;

		if ( ! class_exists( 'FW_CRM_Tracking' ) ) {
			return false;
		}
		$t = FW_CRM_Tracking::events_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE subscriber_id = %d AND type = %s AND bot = 0 AND created_at >= %s LIMIT 1", (int) $run->contact_id, $type, (string) $run->started_at ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
