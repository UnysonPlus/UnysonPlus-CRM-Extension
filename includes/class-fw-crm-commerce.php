<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Purchase summaries: each store or membership provider keeps one row per contact (orders, total
 * spent, products bought, plan and its status), so segments, automations and the contact page can
 * use them without reaching into another extension's tables.
 *
 *     fw_crm()->set_summary( $contact_id, 'myshop', array( 'orders' => 3, 'total' => 240.5, 'products' => array( 12, 40 ) ) );
 *
 * The contact's lifetime value is kept as the sum of every provider's total.
 */
class FW_CRM_Commerce {

	public static function init() {
		add_filter( 'unysonplus_crm_segment_fields', array( __CLASS__, '_segment_fields' ) );
		add_filter( 'unysonplus_crm_automation_condition_fields', array( __CLASS__, '_condition_fields' ) );
		add_filter( 'unysonplus_crm_contact_panels', function ( $panels, $record ) {
			$id = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
			if ( self::for_contact( $id ) ) {
				$panels['commerce'] = array(
					'label'    => __( 'Purchases', 'fw' ),
					'priority' => 3,
					'column'   => 'side',
					'callback' => function () use ( $id ) {
						self::render_panel( $id );
					},
				);
			}
			return $panels;
		}, 3, 2 );
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'commerce_summaries' );
	}

	/**
	 * Store a provider's summary for a contact (fields left out keep their value).
	 *
	 * @param int    $contact_id
	 * @param string $provider A slug: woocommerce, membership, myshop…
	 * @param array  $data orders, total, currency, products (ids), plan, plan_status, first_order_at, last_order_at
	 *
	 * @return true|WP_Error
	 */
	public static function set( $contact_id, $provider, array $data ) {
		global $wpdb;

		$provider = substr( sanitize_key( (string) $provider ), 0, 40 );
		if ( ! $contact_id || '' === $provider || ! FW_CRM_Subscribers::find( (int) $contact_id ) ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		$row = array();
		if ( isset( $data['orders'] ) ) {
			$row['orders'] = max( 0, (int) $data['orders'] );
		}
		if ( isset( $data['total'] ) ) {
			$row['total'] = round( (float) $data['total'], 2 );
		}
		if ( isset( $data['currency'] ) ) {
			$row['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $data['currency'] ), 0, 3 ) );
		}
		if ( isset( $data['products'] ) ) {
			$row['products'] = implode( ',', array_values( array_unique( array_filter( array_map( 'intval', (array) $data['products'] ) ) ) ) );
		}
		foreach ( array( 'plan' => 190, 'plan_status' => 30 ) as $k => $len ) {
			if ( isset( $data[ $k ] ) ) {
				$row[ $k ] = substr( sanitize_text_field( (string) $data[ $k ] ), 0, $len );
			}
		}
		foreach ( array( 'first_order_at', 'last_order_at' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$t         = $data[ $k ] ? strtotime( (string) $data[ $k ] ) : false;
				$row[ $k ] = $t ? gmdate( 'Y-m-d H:i:s', $t ) : null;
			}
		}
		$row['updated_at'] = current_time( 'mysql' );
		$t                 = self::table();
		$exists            = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE contact_id = %d AND provider = %s", (int) $contact_id, $provider ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $exists ) {
			$wpdb->update( $t, $row, array( 'id' => (int) $exists ) );
		} else {
			$wpdb->insert( $t, array_merge( array( 'contact_id' => (int) $contact_id, 'provider' => $provider ), $row ) );
		}
		$total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(total) FROM {$t} WHERE contact_id = %d", (int) $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->update( FW_CRM_Installer::table( 'subscribers' ), array( 'lifetime_value' => $total ), array( 'id' => (int) $contact_id ) );
		$s = self::get( $contact_id, $provider );
		FW_CRM_Events::emit( 'commerce.updated', (int) $contact_id, 'contact', 0, array( 'provider' => $provider, 'orders' => (int) $s['orders'], 'total' => (float) $s['total'] ) );
		return true;
	}

	/**
	 * Add one order to a summary (orders + 1, total + amount, products merged, dates moved).
	 *
	 * @param int    $contact_id
	 * @param string $provider
	 * @param float  $amount
	 * @param array  $products
	 * @param string $date
	 * @param string $currency
	 *
	 * @return true|WP_Error
	 */
	public static function add_order( $contact_id, $provider, $amount, array $products = array(), $date = '', $currency = '' ) {
		$s    = self::get( $contact_id, $provider );
		$date = '' !== $date ? $date : current_time( 'mysql' );
		return self::set( $contact_id, $provider, array(
			'orders'         => (int) $s['orders'] + 1,
			'total'          => (float) $s['total'] + (float) $amount,
			'products'       => array_merge( $s['products'], $products ),
			'currency'       => '' !== $currency ? $currency : $s['currency'],
			'first_order_at' => $s['first_order_at'] ? $s['first_order_at'] : $date,
			'last_order_at'  => $date,
		) );
	}

	/**
	 * @param int    $contact_id
	 * @param string $provider
	 *
	 * @return array orders, total, currency, products[], plan, plan_status, first_order_at, last_order_at
	 */
	public static function get( $contact_id, $provider ) {
		global $wpdb;

		$t = self::table();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE contact_id = %d AND provider = %s", (int) $contact_id, sanitize_key( (string) $provider ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array(
			'orders'         => $r ? (int) $r->orders : 0,
			'total'          => $r ? (float) $r->total : 0.0,
			'currency'       => $r ? (string) $r->currency : '',
			'products'       => $r && '' !== (string) $r->products ? array_map( 'intval', explode( ',', $r->products ) ) : array(),
			'plan'           => $r ? (string) $r->plan : '',
			'plan_status'    => $r ? (string) $r->plan_status : '',
			'first_order_at' => $r ? $r->first_order_at : null,
			'last_order_at'  => $r ? $r->last_order_at : null,
		);
	}

	/**
	 * Every provider's summary for a contact.
	 *
	 * @param int $contact_id
	 *
	 * @return array provider => summary (as get())
	 */
	public static function for_contact( $contact_id ) {
		global $wpdb;

		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT provider FROM {$t} WHERE contact_id = %d", (int) $contact_id ) ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $p ] = self::get( $contact_id, $p );
		}
		return $out;
	}

	/** @param int $contact_id */
	private static function render_panel( $contact_id ) {
		$labels = (array) apply_filters( 'unysonplus_crm_commerce_provider_labels', array( 'woocommerce' => __( 'Shop', 'fw' ), 'membership' => __( 'Membership', 'fw' ) ) );
		foreach ( self::for_contact( $contact_id ) as $p => $s ) {
			echo '<h3 class="fw-crm-subhead">' . esc_html( $labels[ $p ] ?? ucfirst( $p ) ) . '</h3><dl class="fw-crm-details">';
			if ( $s['orders'] ) {
				echo '<dt>' . esc_html__( 'Orders', 'fw' ) . '</dt><dd>' . esc_html( number_format_i18n( $s['orders'] ) ) . '</dd>';
				echo '<dt>' . esc_html__( 'Spent', 'fw' ) . '</dt><dd>' . esc_html( trim( number_format_i18n( $s['total'], 2 ) . ' ' . $s['currency'] ) ) . '</dd>';
			}
			if ( $s['last_order_at'] ) {
				echo '<dt>' . esc_html__( 'Last order', 'fw' ) . '</dt><dd>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $s['last_order_at'] ) ) ) . '</dd>';
			}
			if ( '' !== $s['plan'] ) {
				echo '<dt>' . esc_html__( 'Plan', 'fw' ) . '</dt><dd>' . esc_html( $s['plan'] . ( '' !== $s['plan_status'] ? ' (' . $s['plan_status'] . ')' : '' ) ) . '</dd>';
			}
			echo '</dl>';
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Segments, conditions
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _segment_fields( $fields ) {
		$t     = self::table();
		$group = __( 'Purchases', 'fw' );
		$num   = function ( $expr ) {
			return function ( $op, $value ) use ( $expr ) {
				$map = array( 'eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=' );
				if ( 'empty' === $op ) {
					return $expr . ' = 0';
				}
				if ( 'not_empty' === $op ) {
					return $expr . ' > 0';
				}
				return isset( $map[ $op ] ) ? $expr . ' ' . $map[ $op ] . ' ' . (float) $value : '1=0';
			};
		};
		$fields['commerce_orders'] = array( 'label' => __( 'Number of orders (all stores)', 'fw' ), 'type' => 'number', 'group' => $group, 'sql' => $num( "COALESCE((SELECT SUM(cm.orders) FROM {$t} cm WHERE cm.contact_id = s.id), 0)" ) );
		$fields['commerce_total']  = array( 'label' => __( 'Total spent (all stores)', 'fw' ), 'type' => 'number', 'group' => $group, 'sql' => $num( "COALESCE((SELECT SUM(cm.total) FROM {$t} cm WHERE cm.contact_id = s.id), 0)" ) );
		$fields['commerce_last']   = array(
			'label' => __( 'Last order', 'fw' ),
			'type'  => 'date',
			'group' => $group,
			'sql'   => function ( $op, $value ) use ( $t ) {
				$col  = "(SELECT MAX(cm.last_order_at) FROM {$t} cm WHERE cm.contact_id = s.id)";
				$days = (int) $value;
				$at   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
				switch ( $op ) {
					case 'in_last_days':
						return "{$col} >= '{$at}'";
					case 'not_in_last_days':
						return "({$col} IS NULL OR {$col} < '{$at}')";
					case 'empty':
						return "{$col} IS NULL";
					case 'not_empty':
						return "{$col} IS NOT NULL";
					case 'before':
						return $col . ' < ' . "'" . esc_sql( (string) $value ) . "'";
					case 'after':
						return $col . ' > ' . "'" . esc_sql( (string) $value ) . " 23:59:59'";
				}
				return '1=0';
			},
		);
		$fields['commerce_product'] = array(
			'label' => __( 'Bought product (ID)', 'fw' ),
			'type'  => 'number',
			'group' => $group,
			'sql'   => function ( $op, $value ) use ( $t ) {
				$in = "EXISTS (SELECT 1 FROM {$t} cm WHERE cm.contact_id = s.id AND FIND_IN_SET(" . (int) $value . ', cm.products))';
				return 'neq' === $op ? 'NOT ' . $in : $in;
			},
		);
		return $fields;
	}

	/** @internal */
	public static function _condition_fields( $fields ) {
		$fields['commerce_orders'] = array(
			'label' => __( 'Number of orders (all stores)', 'fw' ),
			'kind'  => 'number',
			'test'  => function ( $contact, $rule ) {
				return FW_CRM_Conditions::compare( array_sum( wp_list_pluck( self::for_contact( (int) $contact->id ), 'orders' ) ), (string) $rule['op'], $rule['value'] ?? '' );
			},
		);
		$fields['commerce_total'] = array(
			'label' => __( 'Total spent (all stores)', 'fw' ),
			'kind'  => 'number',
			'test'  => function ( $contact, $rule ) {
				return FW_CRM_Conditions::compare( array_sum( wp_list_pluck( self::for_contact( (int) $contact->id ), 'total' ) ), (string) $rule['op'], $rule['value'] ?? '' );
			},
		);
		return $fields;
	}
}
