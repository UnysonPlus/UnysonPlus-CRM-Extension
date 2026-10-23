<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * WooCommerce: customers become contacts; orders, refunds and abandoned carts reach the
 * timeline, the purchase summary (orders, total spent, products), segments and automations.
 *
 * - **Contacts** are found or created by the billing email when an order is placed (status
 *   "none": a customer is not a newsletter subscriber). Empty name, phone and address fields
 *   are filled from the order; nothing the team typed is overwritten. A paid order makes a lead
 *   or subscriber a customer (lifecycle stage).
 * - **Summary**: recalculated from the customer's paid orders whenever one changes status, so
 *   refunds and cancellations are always reflected (provider `woocommerce`).
 * - **Abandoned carts**: for visitors the CRM knows (logged in, or identified by the Lead scoring
 *   module's site activity), a cart left for the chosen number of hours without an order fires
 *   the "Abandoned a cart" trigger once, and goes on the timeline.
 * - **Past orders**: CRM → Settings → WooCommerce imports existing orders in batches.
 */
class FW_CRM_WooCommerce {

	const PROVIDER = 'woocommerce';
	const CARTS    = 'fw_crm_woo_carts';
	const OPTION   = 'fw_crm_woocommerce';
	const CRON     = 'fw_crm_woo_carts_tick';
	const PAID     = array( 'processing', 'completed' );

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, '_on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
			self::_on_new_order( $order->get_id() );
		}, 20 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, '_on_status' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, '_on_refund' ), 20, 2 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, '_remember_cart' ) );
		add_action( self::CRON, array( __CLASS__, 'check_carts' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
		add_filter( 'unysonplus_crm_commerce_provider_labels', function ( $l ) {
			$l[ self::PROVIDER ] = 'WooCommerce';
			return $l;
		} );
		add_filter( 'unysonplus_crm_activity_types', function ( $t ) {
			$t['order'] = array( 'label' => __( 'Order', 'fw' ), 'icon' => 'dashicons-cart' );
			return $t;
		} );
		FW_CRM_Integrations::register( 'woocommerce', array(
			'label'       => 'WooCommerce',
			'description' => __( 'Customers, orders, refunds and abandoned carts.', 'fw' ),
			'icon'        => 'dashicons-cart',
			'active'      => true,
			'triggers'    => self::_triggers( array() ),
			'events'      => array(
				'woocommerce.order_placed'   => array( 'label' => __( 'Order placed', 'fw' ), 'data' => array( 'order_id' => 'int', 'total' => 'float', 'status' => 'string' ) ),
				'woocommerce.order_refunded' => array( 'label' => __( 'Order refunded', 'fw' ), 'data' => array( 'order_id' => 'int', 'amount' => 'float' ) ),
				'woocommerce.cart_abandoned' => array( 'label' => __( 'Cart abandoned', 'fw' ), 'data' => array( 'total' => 'float', 'items' => 'int' ) ),
			),
			'widgets'     => array(
				'woo_orders' => array( 'title' => __( 'Latest orders from contacts', 'fw' ), 'callback' => array( __CLASS__, 'render_widget' ), 'priority' => 40 ),
			),
		) );
		if ( is_admin() ) {
			add_action( 'unysonplus_crm_settings_sections', array( __CLASS__, 'render_settings' ), 8 );
			add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, '_handle' ), 10, 2 );
		}
	}

	/** @return array { cart_hours, stage_customer } */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( array( 'cart_hours' => 2, 'stage_customer' => true, 'synced' => 0 ), is_array( $s ) ? $s : array() );
	}

	/* ---------------------------------------------------------------------- *
	 * Orders
	 * ---------------------------------------------------------------------- */

	/**
	 * The contact for an order's billing email, created when missing, with empty details filled.
	 *
	 * @param WC_Order $order
	 *
	 * @return int The contact id, or 0.
	 */
	public static function contact_for_order( $order ) {
		$email = strtolower( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$details = array_filter( array(
			'first_name'    => (string) $order->get_billing_first_name(),
			'last_name'     => (string) $order->get_billing_last_name(),
			'phone'         => (string) $order->get_billing_phone(),
			'address_line1' => (string) $order->get_billing_address_1(),
			'address_line2' => (string) $order->get_billing_address_2(),
			'city'          => (string) $order->get_billing_city(),
			'region'        => (string) $order->get_billing_state(),
			'postcode'      => (string) $order->get_billing_postcode(),
			'country'       => (string) $order->get_billing_country(),
		), 'strlen' );
		$c = FW_CRM_Subscribers::find_by_email( $email );
		if ( ! $c || ! empty( $c->deleted_at ) ) {
			$id = FW_CRM_Contacts::create( array_merge( $details, array( 'email' => $email, 'source' => 'woocommerce' ) ) );
			if ( is_wp_error( $id ) ) {
				return 0;
			}
			if ( $order->get_customer_id() ) {
				FW_CRM_Contacts::link_user( (int) $id, (int) $order->get_customer_id() );
			}
			return (int) $id;
		}
		$fill = array();
		foreach ( $details as $k => $v ) {
			if ( '' === (string) ( $c->$k ?? '' ) ) {
				$fill[ $k ] = $v;
			}
		}
		if ( $fill ) {
			FW_CRM_Contacts::update( (int) $c->id, $fill );
		}
		return (int) $c->id;
	}

	/** @internal A new order (checkout). */
	public static function _on_new_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order || $order->get_meta( '_fw_crm_placed' ) ) {
			return;
		}
		$cid = self::contact_for_order( $order );
		if ( ! $cid ) {
			return;
		}
		$order->update_meta_data( '_fw_crm_placed', 1 );
		$order->save_meta_data();
		FW_CRM_Activity::log( 'contact', $cid, 'order', sprintf( __( 'Order #%1$s placed: %2$s', 'fw' ), $order->get_order_number(), wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ), array(
			'body'     => esc_html( implode( ', ', array_map( function ( $i ) {
				return $i->get_quantity() . ' × ' . $i->get_name();
			}, $order->get_items() ) ) ),
			'meta'     => array( 'order_id' => (int) $order_id ),
			'actor_id' => 0,
		) );
		self::forget_cart( $cid );
		FW_CRM_Events::emit( 'woocommerce.order_placed', $cid, 'contact', 0, array( 'order_id' => (int) $order_id, 'total' => (float) $order->get_total(), 'status' => $order->get_status() ) );
	}

	/** @internal */
	public static function _on_status( $order_id, $from, $to, $order ) {
		if ( ! $order ) {
			return;
		}
		$cid = self::contact_for_order( $order );
		if ( ! $cid ) {
			return;
		}
		if ( ! $order->get_meta( '_fw_crm_placed' ) ) {
			self::_on_new_order( $order_id );
		}
		$labels = array( 'completed' => __( 'completed', 'fw' ), 'refunded' => __( 'refunded', 'fw' ), 'cancelled' => __( 'cancelled', 'fw' ), 'failed' => __( 'failed', 'fw' ) );
		if ( isset( $labels[ $to ] ) ) {
			FW_CRM_Activity::log( 'contact', $cid, 'order', sprintf( __( 'Order #%1$s %2$s', 'fw' ), $order->get_order_number(), $labels[ $to ] ), array( 'meta' => array( 'order_id' => (int) $order_id ), 'actor_id' => 0 ) );
		}
		if ( in_array( $to, self::PAID, true ) && self::settings()['stage_customer'] ) {
			$c = FW_CRM_Subscribers::find( $cid );
			if ( $c && in_array( (string) $c->lifecycle_stage, array( '', 'subscriber', 'lead', 'opportunity' ), true ) ) {
				FW_CRM_Contacts::update( $cid, array( 'lifecycle_stage' => 'customer' ) );
			}
		}
		self::recalculate( $cid, (string) $order->get_billing_email() );
	}

	/** @internal */
	public static function _on_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		$cid    = $order ? self::contact_for_order( $order ) : 0;
		if ( ! $cid ) {
			return;
		}
		$amount = $refund ? abs( (float) $refund->get_amount() ) : 0;
		FW_CRM_Activity::log( 'contact', $cid, 'order', sprintf( __( 'Refund on order #%1$s: %2$s', 'fw' ), $order->get_order_number(), wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) ), array( 'meta' => array( 'order_id' => (int) $order_id ), 'actor_id' => 0 ) );
		FW_CRM_Events_Store::record( $cid, 'refund', array( 'value' => (string) $amount, 'props' => array( 'order_id' => (int) $order_id ) ) );
		FW_CRM_Events::emit( 'woocommerce.order_refunded', $cid, 'contact', 0, array( 'order_id' => (int) $order_id, 'amount' => $amount ) );
		do_action( 'unysonplus_crm_automation_event', 'crm.order_refunded', $cid, array( 'order_id' => (int) $order_id, 'amount' => $amount, 'dedupe' => 'refund:' . (int) $refund_id ) );
		self::recalculate( $cid, (string) $order->get_billing_email() );
	}

	/**
	 * Rebuild the contact's summary from their paid orders (net of refunds).
	 *
	 * @param int    $contact_id
	 * @param string $email
	 */
	public static function recalculate( $contact_id, $email ) {
		$orders = wc_get_orders( array( 'billing_email' => strtolower( $email ), 'status' => array_merge( self::PAID, array( 'refunded' ) ), 'limit' => 500, 'orderby' => 'date', 'order' => 'ASC' ) );
		$count = 0;
		$total = 0.0;
		$products = array();
		$first = null;
		$last  = null;
		$currency = '';
		foreach ( $orders as $o ) {
			$net = (float) $o->get_total() - (float) $o->get_total_refunded();
			if ( 'refunded' === $o->get_status() || $net <= 0 ) {
				continue;
			}
			$count++;
			$total   += $net;
			$currency = $o->get_currency();
			foreach ( $o->get_items() as $i ) {
				$products[] = (int) $i->get_product_id();
			}
			$d     = $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i:s' ) : null;
			$first = $first ? $first : $d;
			$last  = $d;
		}
		FW_CRM_Commerce::set( (int) $contact_id, self::PROVIDER, array(
			'orders'         => $count,
			'total'          => $total,
			'currency'       => $currency,
			'products'       => $products,
			'first_order_at' => $first,
			'last_order_at'  => $last,
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Abandoned carts
	 * ---------------------------------------------------------------------- */

	/**
	 * The CRM contact behind the current visitor, if known.
	 *
	 * @return int
	 */
	private static function current_contact() {
		if ( is_user_logged_in() ) {
			$c = FW_CRM_Events_Store::contact_for_user( wp_get_current_user() );
			if ( $c ) {
				return (int) $c->id;
			}
		}
		if ( class_exists( 'FW_CRM_Site_Activity' ) && ! empty( $_COOKIE[ FW_CRM_Site_Activity::COOKIE ] ) ) {
			return FW_CRM_Site_Activity::verify( sanitize_text_field( wp_unslash( $_COOKIE[ FW_CRM_Site_Activity::COOKIE ] ) ) );
		}
		return 0;
	}

	/** @internal The cart changed: remember it for a known visitor. */
	public static function _remember_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cid = self::current_contact();
		if ( ! $cid ) {
			return;
		}
		$carts = get_option( self::CARTS, array() );
		$carts = is_array( $carts ) ? $carts : array();
		if ( WC()->cart->is_empty() ) {
			unset( $carts[ $cid ] );
		} else {
			$carts[ $cid ] = array(
				'total'   => (float) WC()->cart->get_total( 'edit' ),
				'items'   => (int) WC()->cart->get_cart_contents_count(),
				'names'   => array_slice( array_values( array_map( function ( $i ) {
					return isset( $i['data'] ) && is_object( $i['data'] ) ? $i['data']->get_name() : '';
				}, WC()->cart->get_cart() ) ), 0, 5 ),
				'updated' => time(),
				'fired'   => 0,
			);
		}
		update_option( self::CARTS, $carts, false );
	}

	/** @param int $contact_id */
	private static function forget_cart( $contact_id ) {
		$carts = get_option( self::CARTS, array() );
		if ( is_array( $carts ) && isset( $carts[ $contact_id ] ) ) {
			unset( $carts[ $contact_id ] );
			update_option( self::CARTS, $carts, false );
		}
	}

	/**
	 * Hourly: carts left alone long enough fire once.
	 *
	 * @param int|null $now
	 *
	 * @return int How many fired.
	 */
	public static function check_carts( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$hours = max( 1, (int) self::settings()['cart_hours'] );
		$carts = get_option( self::CARTS, array() );
		$carts = is_array( $carts ) ? $carts : array();
		$n     = 0;
		foreach ( $carts as $cid => $cart ) {
			if ( ! empty( $cart['fired'] ) ) {
				if ( $cart['updated'] < $now - 30 * DAY_IN_SECONDS ) {
					unset( $carts[ $cid ] ); // Old news.
				}
				continue;
			}
			if ( $cart['updated'] > $now - $hours * HOUR_IN_SECONDS ) {
				continue;
			}
			$carts[ $cid ]['fired'] = 1;
			$items = implode( ', ', array_filter( (array) $cart['names'] ) );
			FW_CRM_Activity::log( 'contact', (int) $cid, 'order', sprintf( __( 'Left a cart: %s', 'fw' ), $items ), array( 'meta' => array( 'total' => $cart['total'] ), 'actor_id' => 0 ) );
			FW_CRM_Events_Store::record( (int) $cid, 'cart_abandoned', array( 'value' => (string) $cart['total'] ) );
			FW_CRM_Events::emit( 'woocommerce.cart_abandoned', (int) $cid, 'contact', 0, array( 'total' => (float) $cart['total'], 'items' => (int) $cart['items'] ) );
			do_action( 'unysonplus_crm_automation_event', 'crm.cart_abandoned', (int) $cid, array( 'total' => (float) $cart['total'], 'items' => (int) $cart['items'], 'products' => $items, 'dedupe' => 'cart:' . (int) $cid . ':' . (int) $cart['updated'] ) );
			$n++;
		}
		update_option( self::CARTS, $carts, false );
		return $n;
	}

	/* ---------------------------------------------------------------------- *
	 * Automations
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _triggers( $t ) {
		$shop = __( 'Shop', 'fw' );
		$t['crm.order_refunded'] = array( 'label' => __( 'Order refunded', 'fw' ), 'group' => $shop, 'fields' => array() );
		$t['crm.cart_abandoned'] = array(
			'label'  => __( 'Abandoned a cart', 'fw' ),
			'group'  => $shop,
			'fields' => array( 'min_total' => array( 'label' => __( 'Cart total at least', 'fw' ), 'type' => 'number' ) ),
			'match'  => function ( $s, $ctx ) {
				return '' === (string) ( $s['min_total'] ?? '' ) || (float) ( $ctx['total'] ?? 0 ) >= (float) $s['min_total'];
			},
		);
		return $t;
	}

	/* ---------------------------------------------------------------------- *
	 * Admin
	 * ---------------------------------------------------------------------- */

	/** Home card: the latest orders by people in the CRM. */
	public static function render_widget() {
		$orders = wc_get_orders( array( 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'status' => array( 'processing', 'completed', 'on-hold' ) ) );
		if ( ! $orders ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No orders yet.', 'fw' ) . '</p>';
			return;
		}
		$base = FW_CRM_Admin_Page::get_page_url();
		echo '<ul class="fw-crm-case-links">';
		foreach ( $orders as $o ) {
			$c    = FW_CRM_Subscribers::find_by_email( strtolower( (string) $o->get_billing_email() ) );
			$name = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
			$who  = $c ? '<a href="' . esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base ) ) . '">' . esc_html( '' !== $name ? $name : $o->get_billing_email() ) . '</a>' : esc_html( '' !== $name ? $name : $o->get_billing_email() );
			printf( '<li>%1$s <span class="fw-crm-muted">#%2$s · %3$s</span></li>', $who, esc_html( $o->get_order_number() ), wp_kses_post( wc_price( $o->get_total(), array( 'currency' => $o->get_currency() ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul>';
	}

	/** CRM → Settings: WooCommerce. */
	public static function render_settings() {
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			return;
		}
		$s = self::settings();
		?>
		<section class="fw-crm-panel" id="fw-crm-woo" aria-labelledby="fw-crm-h-woo" style="margin-bottom:1.5em">
			<header class="fw-crm-panel__head"><h2 id="fw-crm-h-woo">WooCommerce</h2></header>
			<form method="post" action="" class="fw-crm-form">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="woo_save" />
				<label class="fw-crm-check"><input type="checkbox" name="stage_customer" value="1" <?php checked( $s['stage_customer'] ); ?> /> <?php esc_html_e( 'A paid order makes a subscriber, lead or opportunity a customer', 'fw' ); ?></label>
				<p class="fw-crm-inline"><?php esc_html_e( 'A cart counts as abandoned after', 'fw' ); ?> <input type="number" name="cart_hours" min="1" max="168" class="small-text" value="<?php echo (int) $s['cart_hours']; ?>" /> <?php esc_html_e( 'hours without an order (only for visitors the CRM knows: logged in, or recognised by site activity).', 'fw' ); ?></p>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'fw' ); ?></button>
					<button type="submit" name="sync" value="1" class="button"><?php esc_html_e( 'Import past orders', 'fw' ); ?></button>
					<span class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'Each click imports the next 200 orders (%d so far). Customers become contacts with their order history.', 'fw' ), (int) $s['synced'] ) ); ?></span>
				</p>
			</form>
		</section>
		<?php
	}

	/** @internal */
	public static function _handle( $args, $action ) {
		if ( null !== $args || 'woo_save' !== $action ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$s                   = self::settings();
		$s['stage_customer'] = ! empty( $_POST['stage_customer'] );
		$s['cart_hours']     = isset( $_POST['cart_hours'] ) ? max( 1, min( 168, (int) $_POST['cart_hours'] ) ) : 2;
		$msg                 = __( 'WooCommerce settings saved.', 'fw' );
		if ( ! empty( $_POST['sync'] ) ) {
			$done        = self::sync( (int) $s['synced'], 200 );
			$s['synced'] = (int) $s['synced'] + $done;
			$msg         = $done ? sprintf( __( '%d orders imported. Click again for the next ones.', 'fw' ), $done ) : __( 'Every past order is in the CRM.', 'fw' );
		}
		// phpcs:enable
		update_option( self::OPTION, $s, false );
		FW_CRM_Admin_Page::flash( 'success', $msg );
		return array( 'tab' => 'settings' );
	}

	/**
	 * Import past orders, oldest first.
	 *
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return int How many orders were looked at.
	 */
	public static function sync( $offset, $limit ) {
		$orders = wc_get_orders( array( 'limit' => $limit, 'offset' => $offset, 'orderby' => 'date', 'order' => 'ASC', 'type' => 'shop_order', 'status' => array_merge( self::PAID, array( 'refunded', 'on-hold', 'cancelled' ) ) ) );
		$seen   = array();
		foreach ( $orders as $o ) {
			$cid = self::contact_for_order( $o );
			if ( $cid ) {
				$seen[ $cid ] = (string) $o->get_billing_email();
			}
		}
		foreach ( $seen as $cid => $email ) {
			self::recalculate( (int) $cid, $email );
		}
		return count( $orders );
	}
}
