<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Products and deal line items.
 *
 * The CRM keeps a simple product list (name, SKU, price, unit). When WooCommerce is
 * active its products can be picked too. A line item on a deal COPIES the name, SKU
 * and price at the moment it is added, so later price changes or deleted products
 * never rewrite an old deal.
 *
 * Once a deal has line items, its value is their total (kept in step on every
 * change); without items the value is typed by hand as before.
 */
class FW_CRM_Products {

	/** @var bool Set while sync_value() writes the value, so Deals::update() lets it through. */
	public static $syncing = false;

	public static function init() {
		add_action( 'unysonplus_crm_deal_deleted', function ( $deal ) {
			global $wpdb;
			$wpdb->delete( self::items_table(), array( 'deal_id' => (int) $deal->id ) );
		} );
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'products' );
	}

	/** @return string */
	public static function items_table() {
		return FW_CRM_Installer::table( 'deal_items' );
	}

	/* ---------------------------------------------------------------------- *
	 * The product list
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $args active (true|false|null = all), search
	 *
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		$w     = array( '1=1' );
		if ( isset( $args['active'] ) && null !== $args['active'] ) {
			$w[] = $wpdb->prepare( 'active = %d', $args['active'] ? 1 : 0 );
		}
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$w[]  = $wpdb->prepare( '( name LIKE %s OR sku LIKE %s )', $like, $like );
		}
		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $w ) . ' ORDER BY active DESC, position ASC, name ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

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
	 * Create or update a product.
	 *
	 * @param array $data name (required), sku, description, price, unit, active
	 * @param int   $id
	 *
	 * @return int|WP_Error
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$before = $id ? self::find( $id ) : null;
		if ( $id && ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Product not found.', 'fw' ) );
		}
		$row = array();
		if ( isset( $data['name'] ) || ! $id ) {
			$row['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
			if ( '' === $row['name'] ) {
				return new WP_Error( 'fw_crm_no_title', __( 'Give the product a name.', 'fw' ) );
			}
		}
		if ( isset( $data['sku'] ) ) {
			$row['sku'] = substr( sanitize_text_field( (string) $data['sku'] ), 0, 100 );
		}
		if ( isset( $data['description'] ) ) {
			$row['description'] = sanitize_textarea_field( (string) $data['description'] );
		}
		if ( isset( $data['price'] ) ) {
			$row['price'] = self::number( $data['price'] );
		}
		if ( isset( $data['unit'] ) ) {
			$row['unit'] = substr( sanitize_text_field( (string) $data['unit'] ), 0, 30 );
		}
		if ( isset( $data['active'] ) ) {
			$row['active'] = $data['active'] ? 1 : 0;
		}
		$row['updated_at'] = current_time( 'mysql' );
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			$row['position']   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $wpdb->insert( self::table(), $row ) ) {
				return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the product.', 'fw' ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		/** Fires after a CRM product is created or changed. */
		do_action( 'unysonplus_crm_product_saved', self::find( $id ), $before );
		return (int) $id;
	}

	/**
	 * Delete a product. Deals that used it keep their copied line items.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * How many deals use a product.
	 *
	 * @param int    $id
	 * @param string $source
	 *
	 * @return int
	 */
	public static function usage( $id, $source = 'crm' ) {
		global $wpdb;

		$items = self::items_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT deal_id) FROM {$items} WHERE source = %s AND product_id = %d", $source, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return bool Can WooCommerce products be picked? */
	public static function woo_available() {
		/** Filters whether WooCommerce products are offered on deals (default: when WooCommerce is active). */
		return (bool) apply_filters( 'unysonplus_crm_woo_products', function_exists( 'wc_get_products' ) );
	}

	/**
	 * Everything that can be put on a deal: active CRM products, then WooCommerce
	 * products when available.
	 *
	 * @param string $search
	 * @param int    $limit Per source.
	 *
	 * @return array [ { key (crm:5 | woo:123), source, id, name, sku, price, unit } ]
	 */
	public static function catalogue( $search = '', $limit = 200 ) {
		$out = array();
		foreach ( self::all( array( 'active' => true, 'search' => $search ) ) as $p ) {
			$out[] = array(
				'key'    => 'crm:' . $p->id,
				'source' => 'crm',
				'id'     => (int) $p->id,
				'name'   => (string) $p->name,
				'sku'    => (string) $p->sku,
				'price'  => (float) $p->price,
				'unit'   => (string) $p->unit,
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		if ( self::woo_available() ) {
			$args = array( 'status' => 'publish', 'limit' => $limit, 'orderby' => 'title', 'order' => 'ASC' );
			if ( '' !== (string) $search ) {
				$args['s'] = (string) $search;
			}
			foreach ( wc_get_products( $args ) as $wp ) {
				if ( $wp->is_type( 'variable' ) ) {
					continue; // Its variations are what is sold.
				}
				$out[] = array(
					'key'    => 'woo:' . $wp->get_id(),
					'source' => 'woo',
					'id'     => (int) $wp->get_id(),
					'name'   => wp_strip_all_tags( $wp->get_name() ),
					'sku'    => (string) $wp->get_sku(),
					'price'  => (float) $wp->get_price(),
					'unit'   => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Look up one catalogue entry by its key.
	 *
	 * @param string $key crm:5 | woo:123
	 *
	 * @return array|null
	 */
	public static function lookup( $key ) {
		if ( ! preg_match( '/^(crm|woo):(\d+)$/', (string) $key, $m ) ) {
			return null;
		}
		if ( 'crm' === $m[1] ) {
			$p = self::find( (int) $m[2] );
			return $p ? array( 'key' => $key, 'source' => 'crm', 'id' => (int) $p->id, 'name' => (string) $p->name, 'sku' => (string) $p->sku, 'price' => (float) $p->price, 'unit' => (string) $p->unit ) : null;
		}
		if ( ! self::woo_available() ) {
			return null;
		}
		$wp = wc_get_product( (int) $m[2] );
		return $wp ? array( 'key' => $key, 'source' => 'woo', 'id' => (int) $wp->get_id(), 'name' => wp_strip_all_tags( $wp->get_name() ), 'sku' => (string) $wp->get_sku(), 'price' => (float) $wp->get_price(), 'unit' => '' ) : null;
	}

	/* ---------------------------------------------------------------------- *
	 * Line items
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $deal_id
	 *
	 * @return array Item rows in order.
	 */
	public static function items( $deal_id ) {
		global $wpdb;

		$table = self::items_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE deal_id = %d ORDER BY position ASC, id ASC", (int) $deal_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param int $item_id
	 *
	 * @return object|null
	 */
	public static function item( $item_id ) {
		global $wpdb;

		$table = self::items_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Add a line to a deal: from the catalogue (`product` = crm:5 | woo:123) or a
	 * custom line (`name`). The price is copied; `unit_price` overrides it.
	 *
	 * @param int   $deal_id
	 * @param array $data product | name, sku, unit_price, quantity, discount (percent)
	 *
	 * @return int|WP_Error The item id.
	 */
	public static function add_item( $deal_id, array $data ) {
		global $wpdb;

		$deal = FW_CRM_Deals::find( (int) $deal_id );
		if ( ! $deal ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Deal not found.', 'fw' ) );
		}
		$row = array( 'deal_id' => (int) $deal_id, 'source' => 'custom', 'product_id' => 0 );
		if ( ! empty( $data['product'] ) ) {
			$p = self::lookup( (string) $data['product'] );
			if ( ! $p ) {
				return new WP_Error( 'fw_crm_not_found', __( 'That product was not found.', 'fw' ) );
			}
			$row['source']     = $p['source'];
			$row['product_id'] = $p['id'];
			$row['name']       = $p['name'];
			$row['sku']        = $p['sku'];
			$row['unit_price'] = $p['price'];
		}
		$row = array_merge( $row, self::sanitize_item( $data, ! empty( $data['product'] ) ) );
		if ( '' === (string) ( $row['name'] ?? '' ) ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Pick a product or name the line.', 'fw' ) );
		}
		$row = array_merge( array( 'quantity' => 1, 'discount' => 0, 'unit_price' => 0 ), $row );
		$row['total']      = self::line_total( $row['unit_price'], $row['quantity'], $row['discount'] );
		$row['position']   = count( self::items( $deal_id ) );
		$row['created_at'] = current_time( 'mysql' );
		if ( ! $wpdb->insert( self::items_table(), $row ) ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not add the line.', 'fw' ) );
		}
		$id = (int) $wpdb->insert_id;
		FW_CRM_Activity::log( 'deal', (int) $deal_id, 'deal', sprintf(
			/* translators: 1: quantity, 2: product */
			__( 'Added %1$s × %2$s', 'fw' ),
			self::qty( $row['quantity'] ),
			$row['name']
		) );
		self::sync_value( (int) $deal_id );
		return $id;
	}

	/**
	 * @param int   $item_id
	 * @param array $data name, sku, unit_price, quantity, discount
	 *
	 * @return true|WP_Error
	 */
	public static function update_item( $item_id, array $data ) {
		global $wpdb;

		$item = self::item( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Line not found.', 'fw' ) );
		}
		$row = self::sanitize_item( $data, false );
		if ( isset( $row['name'] ) && '' === $row['name'] ) {
			return new WP_Error( 'fw_crm_no_title', __( 'A line needs a name.', 'fw' ) );
		}
		if ( ! $row ) {
			return true;
		}
		$merged       = array_merge( (array) $item, $row );
		$row['total'] = self::line_total( $merged['unit_price'], $merged['quantity'], $merged['discount'] );
		$wpdb->update( self::items_table(), $row, array( 'id' => (int) $item_id ) );
		self::sync_value( (int) $item->deal_id );
		return true;
	}

	/**
	 * @param int $item_id
	 *
	 * @return bool
	 */
	public static function remove_item( $item_id ) {
		global $wpdb;

		$item = self::item( $item_id );
		if ( ! $item ) {
			return false;
		}
		$wpdb->delete( self::items_table(), array( 'id' => (int) $item_id ) );
		FW_CRM_Activity::log( 'deal', (int) $item->deal_id, 'deal', sprintf( __( 'Removed %s', 'fw' ), $item->name ) );
		self::sync_value( (int) $item->deal_id );
		return true;
	}

	/**
	 * Subtotal (before discounts), discount and total of a deal's lines.
	 *
	 * @param int $deal_id
	 *
	 * @return array { count, subtotal, discount, total }
	 */
	public static function totals( $deal_id ) {
		$sub   = 0.0;
		$total = 0.0;
		$items = self::items( $deal_id );
		foreach ( $items as $i ) {
			$sub   += round( (float) $i->unit_price * (float) $i->quantity, 2 );
			$total += (float) $i->total;
		}
		return array(
			'count'    => count( $items ),
			'subtotal' => round( $sub, 2 ),
			'discount' => round( $sub - $total, 2 ),
			'total'    => round( $total, 2 ),
		);
	}

	/**
	 * Keep the deal's value equal to its lines (when it has any).
	 *
	 * @param int $deal_id
	 */
	public static function sync_value( $deal_id ) {
		$t = self::totals( $deal_id );
		if ( ! $t['count'] ) {
			return;
		}
		$deal = FW_CRM_Deals::find( $deal_id );
		if ( $deal && abs( (float) $deal->value - $t['total'] ) > 0.004 ) {
			self::$syncing = true;
			FW_CRM_Deals::update( $deal_id, array( 'value' => $t['total'] ) );
			self::$syncing = false;
		}
	}

	/**
	 * @param float $price
	 * @param float $qty
	 * @param float $discount Percent.
	 *
	 * @return float
	 */
	public static function line_total( $price, $qty, $discount ) {
		return round( (float) $price * (float) $qty * ( 1 - max( 0, min( 100, (float) $discount ) ) / 100 ), 2 );
	}

	/**
	 * "2", "1.5".
	 *
	 * @param float $q
	 *
	 * @return string
	 */
	public static function qty( $q ) {
		$q = (float) $q;
		return floor( $q ) === $q ? number_format_i18n( $q ) : number_format_i18n( $q, 2 );
	}

	/**
	 * @param array $data
	 * @param bool  $from_catalogue Keep the copied name/SKU unless given.
	 *
	 * @return array
	 */
	private static function sanitize_item( array $data, $from_catalogue ) {
		$row = array();
		if ( isset( $data['name'] ) && ( ! $from_catalogue || '' !== trim( (string) $data['name'] ) ) ) {
			$row['name'] = substr( sanitize_text_field( (string) $data['name'] ), 0, 190 );
		}
		if ( isset( $data['sku'] ) && ( ! $from_catalogue || '' !== trim( (string) $data['sku'] ) ) ) {
			$row['sku'] = substr( sanitize_text_field( (string) $data['sku'] ), 0, 100 );
		}
		if ( isset( $data['unit_price'] ) && '' !== $data['unit_price'] ) {
			$row['unit_price'] = self::number( $data['unit_price'] );
		}
		if ( isset( $data['quantity'] ) && '' !== $data['quantity'] ) {
			$row['quantity'] = max( 0.01, min( 9999999, round( (float) str_replace( ',', '.', (string) $data['quantity'] ), 2 ) ) );
		}
		if ( isset( $data['discount'] ) && '' !== $data['discount'] ) {
			$row['discount'] = max( 0, min( 100, round( (float) str_replace( ',', '.', (string) $data['discount'] ), 2 ) ) );
		}
		return $row;
	}

	/**
	 * "1,250.50" or "1250,50" → 1250.5 (a comma followed by exactly two digits at the
	 * end is read as a decimal comma).
	 *
	 * @param mixed $v
	 *
	 * @return float
	 */
	private static function number( $v ) {
		$v = trim( str_replace( ' ', '', (string) $v ) );
		if ( preg_match( '/^-?\d+,\d{1,2}$/', $v ) ) {
			$v = str_replace( ',', '.', $v );
		}
		return round( max( 0, (float) str_replace( ',', '', $v ) ), 2 );
	}
}
