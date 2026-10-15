<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Products: the product list (CRM → Deals → Products, `tab=deals&products=1&edit=`)
 * and the Products panel on a deal page (line items).
 */
class FW_CRM_Admin_Products {

	public static function init() {
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 9, 2 );
		add_filter( 'unysonplus_crm_deal_panels', function ( $panels, $deal ) {
			$panels['products'] = array(
				'label'    => __( 'Products', 'fw' ),
				'priority' => 5,
				'column'   => 'main',
				'callback' => function () use ( $deal ) {
					self::render_items( (int) $deal->id );
				},
			);
			return $panels;
		}, 5, 2 );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || ( 0 !== strpos( (string) $action, 'product_' ) && 0 !== strpos( (string) $action, 'item_' ) ) ) {
			return $args;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		if ( 0 === strpos( $action, 'product_' ) ) {
			if ( ! FW_CRM_Capabilities::can_manage() ) {
				wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
			}
			$pid = isset( $_REQUEST['product'] ) ? (int) $_REQUEST['product'] : 0;
			if ( 'product_delete' === $action ) {
				FW_CRM_Products::delete( $pid );
				FW_CRM_Admin_Page::flash( 'success', __( 'Product deleted. Deals that used it keep their lines.', 'fw' ) );
				return array( 'tab' => 'deals', 'products' => 1 );
			}
			$res = FW_CRM_Products::save( array(
				'name'        => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by save().
				'sku'         => isset( $_POST['sku'] ) ? wp_unslash( $_POST['sku'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'price'       => isset( $_POST['price'] ) ? sanitize_text_field( wp_unslash( $_POST['price'] ) ) : 0,
				'unit'        => isset( $_POST['unit'] ) ? wp_unslash( $_POST['unit'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'description' => isset( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'active'      => ! empty( $_POST['active'] ),
			), $pid );
			FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Product saved.', 'fw' ) );
			return array( 'tab' => 'deals', 'products' => 1, 'edit' => is_wp_error( $res ) ? ( $pid ? $pid : 'new' ) : $res );
		}

		// Line items: need the right to edit the deal.
		$deal = isset( $_REQUEST['deal'] ) ? (int) $_REQUEST['deal'] : 0;
		if ( ! $deal || ! FW_CRM_Capabilities::can_edit_deal( $deal ) ) {
			wp_die( esc_html__( 'That deal belongs to someone else.', 'fw' ), 403 );
		}
		$back = array( 'tab' => 'deals', 'deal' => $deal );
		switch ( $action ) {
			case 'item_add':
				$res = FW_CRM_Products::add_item( $deal, array(
					'product'    => isset( $_POST['product'] ) && 'custom' !== $_POST['product'] ? sanitize_text_field( wp_unslash( $_POST['product'] ) ) : '',
					'name'       => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by add_item().
					'unit_price' => isset( $_POST['unit_price'] ) ? sanitize_text_field( wp_unslash( $_POST['unit_price'] ) ) : '',
					'quantity'   => isset( $_POST['quantity'] ) ? sanitize_text_field( wp_unslash( $_POST['quantity'] ) ) : 1,
					'discount'   => isset( $_POST['discount'] ) ? sanitize_text_field( wp_unslash( $_POST['discount'] ) ) : 0,
				) );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Line added.', 'fw' ) );
				return $back;

			case 'item_save':
				$rows = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value sanitized by update_item().
				foreach ( $rows as $iid => $row ) {
					$item = FW_CRM_Products::item( (int) $iid );
					if ( $item && (int) $item->deal_id === $deal && is_array( $row ) ) {
						FW_CRM_Products::update_item( (int) $iid, array_intersect_key( $row, array_flip( array( 'quantity', 'unit_price', 'discount' ) ) ) );
					}
				}
				FW_CRM_Admin_Page::flash( 'success', __( 'Lines saved.', 'fw' ) );
				return $back;

			case 'item_remove':
				$item = FW_CRM_Products::item( isset( $_REQUEST['item'] ) ? (int) $_REQUEST['item'] : 0 );
				if ( $item && (int) $item->deal_id === $deal ) {
					FW_CRM_Products::remove_item( (int) $item->id );
				}
				return $back;
		}
		// phpcs:enable
		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Deal panel
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $deal_id
	 */
	public static function render_items( $deal_id ) {
		$deal  = FW_CRM_Deals::find( $deal_id );
		$items = FW_CRM_Products::items( $deal_id );
		$t     = FW_CRM_Products::totals( $deal_id );
		$cur   = (string) $deal->currency;
		$money = function ( $v ) use ( $cur ) {
			return FW_CRM_Deals::money( $v, $cur );
		};
		$cat   = FW_CRM_Products::catalogue();
		$base  = FW_CRM_Admin_Page::get_page_url();
		?>
		<?php if ( $items ) : ?>
			<form method="post" action="" class="fw-crm-items-form">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="item_save" />
				<input type="hidden" name="deal" value="<?php echo (int) $deal_id; ?>" />
				<div class="fw-crm-table-scroll">
					<table class="fw-crm-items">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Item', 'fw' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Qty', 'fw' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Price', 'fw' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Discount %', 'fw' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Total', 'fw' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'fw' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $i ) : ?>
								<tr>
									<th scope="row">
										<span class="fw-crm-items__name"><?php echo esc_html( $i->name ); ?></span>
										<span class="fw-crm-items__sub"><?php echo esc_html( implode( ' · ', array_filter( array( (string) $i->sku, 'woo' === $i->source ? __( 'WooCommerce', 'fw' ) : ( 'custom' === $i->source ? __( 'Custom line', 'fw' ) : '' ) ) ) ) ); ?></span>
									</th>
									<td class="num"><label class="screen-reader-text" for="fw-crm-qty-<?php echo (int) $i->id; ?>"><?php echo esc_html( sprintf( __( 'Quantity of %s', 'fw' ), $i->name ) ); ?></label><input type="number" id="fw-crm-qty-<?php echo (int) $i->id; ?>" name="items[<?php echo (int) $i->id; ?>][quantity]" min="0.01" step="any" value="<?php echo esc_attr( (string) (float) $i->quantity ); ?>" /></td>
									<td class="num"><label class="screen-reader-text" for="fw-crm-price-<?php echo (int) $i->id; ?>"><?php echo esc_html( sprintf( __( 'Price of %s', 'fw' ), $i->name ) ); ?></label><input type="number" id="fw-crm-price-<?php echo (int) $i->id; ?>" name="items[<?php echo (int) $i->id; ?>][unit_price]" min="0" step="0.01" value="<?php echo esc_attr( (string) (float) $i->unit_price ); ?>" /></td>
									<td class="num"><label class="screen-reader-text" for="fw-crm-disc-<?php echo (int) $i->id; ?>"><?php echo esc_html( sprintf( __( 'Discount on %s', 'fw' ), $i->name ) ); ?></label><input type="number" id="fw-crm-disc-<?php echo (int) $i->id; ?>" name="items[<?php echo (int) $i->id; ?>][discount]" min="0" max="100" step="any" value="<?php echo esc_attr( (string) (float) $i->discount ); ?>" /></td>
									<td class="num fw-crm-items__total"><?php echo esc_html( $money( $i->total ) ); ?></td>
									<td><a class="fw-crm-unlink" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'deals', 'fw_crm_action' => 'item_remove', 'deal' => (int) $deal_id, 'item' => (int) $i->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'fw' ), $i->name ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<?php if ( $t['discount'] > 0 ) : ?>
								<tr><th scope="row" colspan="4"><?php esc_html_e( 'Subtotal', 'fw' ); ?></th><td class="num"><?php echo esc_html( $money( $t['subtotal'] ) ); ?></td><td></td></tr>
								<tr><th scope="row" colspan="4"><?php esc_html_e( 'Discounts', 'fw' ); ?></th><td class="num">−<?php echo esc_html( $money( $t['discount'] ) ); ?></td><td></td></tr>
							<?php endif; ?>
							<tr class="fw-crm-items__grand"><th scope="row" colspan="4"><?php esc_html_e( 'Deal value', 'fw' ); ?></th><td class="num"><?php echo esc_html( $money( $t['total'] ) ); ?></td><td></td></tr>
						</tfoot>
					</table>
				</div>
				<p class="fw-crm-items__foot">
					<button type="submit" class="button"><?php esc_html_e( 'Save quantities and prices', 'fw' ); ?></button>
					<span class="fw-crm-muted"><?php esc_html_e( 'The deal value follows these lines. Prices are copied when a line is added, so later price changes do not alter this deal.', 'fw' ); ?></span>
				</p>
			</form>
		<?php else : ?>
			<p class="fw-crm-muted"><?php esc_html_e( 'No products on this deal. Add what is being sold and the deal value adds itself up.', 'fw' ); ?></p>
		<?php endif; ?>

		<details class="fw-crm-task-add fw-crm-items-add"<?php echo $items ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'Add a product', 'fw' ); ?></summary>
			<form method="post" action="" class="fw-crm-form fw-crm-items-addform" data-item-form>
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="item_add" />
				<input type="hidden" name="deal" value="<?php echo (int) $deal_id; ?>" />
				<div class="fw-crm-items-addform__grid">
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Product', 'fw' ); ?></span>
						<select name="product" data-item-product>
							<?php
							$groups = array( 'crm' => array(), 'woo' => array() );
							foreach ( $cat as $p ) {
								$groups[ $p['source'] ][] = $p;
							}
							?>
							<?php foreach ( array( 'crm' => __( 'Products', 'fw' ), 'woo' => __( 'WooCommerce', 'fw' ) ) as $src => $label ) : ?>
								<?php if ( $groups[ $src ] ) : ?>
									<optgroup label="<?php echo esc_attr( $label ); ?>">
										<?php foreach ( $groups[ $src ] as $p ) : ?>
											<option value="<?php echo esc_attr( $p['key'] ); ?>" data-price="<?php echo esc_attr( (string) $p['price'] ); ?>"><?php echo esc_html( $p['name'] . ( '' !== $p['sku'] ? ' (' . $p['sku'] . ')' : '' ) . ' · ' . $money( $p['price'] ) . ( '' !== $p['unit'] ? ' / ' . $p['unit'] : '' ) ); ?></option>
										<?php endforeach; ?>
									</optgroup>
								<?php endif; ?>
							<?php endforeach; ?>
							<option value="custom" <?php selected( ! $cat ); ?>><?php esc_html_e( 'Something else (type a name)', 'fw' ); ?></option>
						</select>
					</label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Qty', 'fw' ); ?></span><input type="number" name="quantity" min="0.01" step="any" value="1" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Price', 'fw' ); ?></span><input type="number" name="unit_price" min="0" step="0.01" value="<?php echo esc_attr( $cat ? (string) $cat[0]['price'] : '' ); ?>" data-item-price /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Discount %', 'fw' ); ?></span><input type="number" name="discount" min="0" max="100" step="any" value="0" /></label>
					<label class="fw-crm-form__row" data-item-custom<?php echo $cat ? ' hidden' : ''; ?>><span><?php esc_html_e( 'Name', 'fw' ); ?></span><input type="text" name="name" <?php echo $cat ? '' : 'required'; ?> /></label>
				</div>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add to deal', 'fw' ); ?></button>
					<?php if ( FW_CRM_Capabilities::can_manage() ) : ?>
						<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'products' => 1 ), $base ) ); ?>"><?php esc_html_e( 'Edit the product list', 'fw' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</details>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Product list
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $edit Product id, 'new' or ''.
	 */
	public static function render_list( $edit ) {
		$can      = FW_CRM_Capabilities::can_manage();
		$products = FW_CRM_Products::all();
		$base     = FW_CRM_Admin_Page::get_page_url();
		$current  = 'new' === $edit ? null : ( $edit ? FW_CRM_Products::find( (int) $edit ) : null );
		$cur      = FW_CRM_Pipelines::currency();
		?>
		<div class="fw-crm-products">
			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-products">
				<header class="fw-crm-panel__head">
					<h2 id="fw-crm-h-products"><?php esc_html_e( 'Products', 'fw' ); ?></h2>
					<?php if ( $can ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'products' => 1, 'edit' => 'new' ), $base ) ); ?>"><?php esc_html_e( 'Add a product', 'fw' ); ?></a>
					<?php endif; ?>
				</header>
				<?php if ( $products ) : ?>
					<div class="fw-crm-table-scroll">
						<table class="fw-crm-report-table fw-crm-products__table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'SKU', 'fw' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Price', 'fw' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'On deals', 'fw' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $products as $p ) : ?>
									<tr<?php echo (int) $p->active ? '' : ' class="is-off"'; ?>>
										<th scope="row">
											<?php if ( $can ) : ?>
												<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'products' => 1, 'edit' => (int) $p->id ), $base ) ); ?>"><?php echo esc_html( $p->name ); ?></a>
											<?php else : ?>
												<?php echo esc_html( $p->name ); ?>
											<?php endif; ?>
											<?php if ( ! (int) $p->active ) : ?>
												<span class="fw-crm-pill"><?php esc_html_e( 'Archived', 'fw' ); ?></span>
											<?php endif; ?>
										</th>
										<td><?php echo esc_html( $p->sku ); ?></td>
										<td class="num"><?php echo esc_html( FW_CRM_Deals::money( $p->price, $cur ) . ( '' !== $p->unit ? ' / ' . $p->unit : '' ) ); ?></td>
										<td class="num"><?php echo esc_html( number_format_i18n( FW_CRM_Products::usage( (int) $p->id ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'No products yet. Add what you sell, with a price, and pick them on deals: the deal value then adds itself up.', 'fw' ); ?></p>
				<?php endif; ?>
				<?php if ( FW_CRM_Products::woo_available() ) : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'WooCommerce is active: its products can be picked on deals too, with their current price.', 'fw' ); ?></p>
				<?php endif; ?>
			</section>

			<?php if ( $can && ( $current || 'new' === $edit ) ) : ?>
				<section class="fw-crm-panel" aria-labelledby="fw-crm-h-product-edit">
					<header class="fw-crm-panel__head"><h2 id="fw-crm-h-product-edit"><?php echo $current ? esc_html( $current->name ) : esc_html__( 'New product', 'fw' ); ?></h2></header>
					<form method="post" action="" class="fw-crm-form">
						<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_crm_action" value="product_save" />
						<input type="hidden" name="product" value="<?php echo $current ? (int) $current->id : 0; ?>" />
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Name', 'fw' ); ?></span><input type="text" name="name" required value="<?php echo esc_attr( $current ? $current->name : '' ); ?>" /></label>
						<div class="fw-crm-task-form__row">
							<label class="fw-crm-form__row"><span><?php echo esc_html( sprintf( __( 'Price (%s)', 'fw' ), $cur ) ); ?></span><input type="number" name="price" min="0" step="0.01" value="<?php echo esc_attr( $current ? (string) (float) $current->price : '' ); ?>" /></label>
							<label class="fw-crm-form__row"><span><?php esc_html_e( 'Per (optional)', 'fw' ); ?></span><input type="text" name="unit" placeholder="<?php esc_attr_e( 'hour, session, month…', 'fw' ); ?>" value="<?php echo esc_attr( $current ? $current->unit : '' ); ?>" /></label>
						</div>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'SKU (optional)', 'fw' ); ?></span><input type="text" name="sku" value="<?php echo esc_attr( $current ? $current->sku : '' ); ?>" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Description (optional)', 'fw' ); ?></span><textarea name="description" rows="3"><?php echo esc_textarea( $current ? (string) $current->description : '' ); ?></textarea></label>
						<label class="fw-crm-check"><input type="checkbox" name="active" value="1" <?php checked( ! $current || (int) $current->active ); ?> /> <?php esc_html_e( 'Offer it on deals (untick to archive it)', 'fw' ); ?></label>
						<p class="fw-crm-form__foot">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save product', 'fw' ); ?></button>
							<?php if ( $current ) : ?>
								<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'deals', 'fw_crm_action' => 'product_delete', 'product' => (int) $current->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
