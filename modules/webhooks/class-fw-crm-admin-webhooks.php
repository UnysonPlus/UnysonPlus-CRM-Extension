<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Webhooks (managers).
 *
 *   ?tab=webhooks                  the endpoints
 *   ?tab=webhooks&edit=<id>|new    one endpoint: its settings, secret and delivery log
 */
class FW_CRM_Admin_Webhooks {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'webhooks' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		add_filter( 'unysonplus_crm_crumbs', function ( $crumbs, $tab ) {
			if ( 'webhooks' !== $tab || empty( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return $crumbs;
			}
			$h = 'new' === $_GET['edit'] ? null : FW_CRM_Webhooks::find( (int) $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification
			return array( array( __( 'Webhooks', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'webhooks' ) ), array( $h ? $h->name : __( 'New webhook', 'fw' ) ) );
		}, 10, 2 );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'webhook_' ) ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id = isset( $_REQUEST['webhook'] ) ? (int) $_REQUEST['webhook'] : 0;
		switch ( $action ) {
			case 'webhook_save':
				$result = FW_CRM_Webhooks::save( array(
					'name'   => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
					'url'    => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
					'events' => isset( $_POST['all_events'] ) ? array( '*' ) : ( isset( $_POST['events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['events'] ) ) : array() ),
					'active' => ! empty( $_POST['active'] ),
				), $id );
				if ( is_wp_error( $result ) ) {
					FW_CRM_Admin_Page::flash( 'error', $result->get_error_message() );
					return array( 'tab' => 'webhooks', 'edit' => $id ? $id : 'new' );
				}
				FW_CRM_Admin_Page::flash( 'success', $id ? __( 'Webhook saved.', 'fw' ) : __( 'Webhook added. Use "Send a test" to check that it arrives.', 'fw' ) );
				return array( 'tab' => 'webhooks', 'edit' => (int) $result );

			case 'webhook_test':
				$r = FW_CRM_Webhooks::test( $id );
				if ( is_wp_error( $r ) ) {
					FW_CRM_Admin_Page::flash( 'error', $r->get_error_message() );
				} elseif ( $r['ok'] ) {
					FW_CRM_Admin_Page::flash( 'success', sprintf( __( 'The test arrived (HTTP %1$d, %2$s ms).', 'fw' ), (int) $r['code'], number_format_i18n( (int) $r['ms'] ) ) );
				} else {
					FW_CRM_Admin_Page::flash( 'error', sprintf( __( 'The test did not arrive: %s', 'fw' ), '' !== $r['error'] ? $r['error'] : sprintf( __( 'the address answered HTTP %d.', 'fw' ), (int) $r['code'] ) ) );
				}
				return array( 'tab' => 'webhooks', 'edit' => $id );

			case 'webhook_secret':
				FW_CRM_Webhooks::rotate_secret( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'New signing secret made. Update it in the receiving app: requests signed with the old one stop matching now.', 'fw' ) );
				return array( 'tab' => 'webhooks', 'edit' => $id );

			case 'webhook_retry':
				$r = FW_CRM_Webhooks::retry( isset( $_REQUEST['delivery'] ) ? (int) $_REQUEST['delivery'] : 0 );
				if ( is_wp_error( $r ) ) {
					FW_CRM_Admin_Page::flash( 'error', $r->get_error_message() );
				} else {
					FW_CRM_Admin_Page::flash( $r['ok'] ? 'success' : 'error', $r['ok'] ? __( 'Delivered.', 'fw' ) : __( 'Still failing. It will be tried again automatically.', 'fw' ) );
				}
				return array( 'tab' => 'webhooks', 'edit' => $id );

			case 'webhook_delete':
				FW_CRM_Webhooks::delete( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Webhook deleted.', 'fw' ) );
				return array( 'tab' => 'webhooks' );
		}
		// phpcs:enable
		return $args;
	}

	public static function render() {
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $edit ) {
			self::render_edit( 'new' === $edit ? null : FW_CRM_Webhooks::find( (int) $edit ), 'new' !== $edit );
			return;
		}
		$base  = FW_CRM_Admin_Page::get_page_url( 'webhooks' );
		$hooks = FW_CRM_Webhooks::all();
		if ( ! $hooks ) {
			FW_CRM_App::empty_state(
				'dashicons-rest-api',
				__( 'Send CRM events to your other apps', 'fw' ),
				__( 'A webhook posts each event you choose (a new contact, a won deal, an unsubscribe) to a web address the moment it happens, signed so the receiver knows it came from this site. Automation services, your own code or a spreadsheet tool can take it from there.', 'fw' ),
				array(
					array( __( 'Add a webhook', 'fw' ), add_query_arg( 'edit', 'new', $base ), true ),
					array( __( 'Read the webhook guide', 'fw' ), 'https://docs.unysonplus.com/crm/developers/webhooks' ),
				)
			);
			return;
		}
		?>
		<div class="fw-crm-listbar">
			<p class="fw-crm-muted"><?php esc_html_e( 'Each webhook receives the events it asked for, signed with its own secret. Failed deliveries are retried for about a day.', 'fw' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'edit', 'new', $base ) ); ?>"><?php esc_html_e( 'Add a webhook', 'fw' ); ?></a>
		</div>
		<div class="fw-crm-table-scroll">
			<table class="wp-list-table widefat striped fw-crm-table fw-crm-webhooks">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Webhook', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Events', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last 7 days', 'fw' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last delivery', 'fw' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $hooks as $h ) : ?>
						<?php $st = FW_CRM_Webhooks::stats( (int) $h->id ); ?>
						<tr>
							<td>
								<a class="row-title" href="<?php echo esc_url( add_query_arg( 'edit', (int) $h->id, $base ) ); ?>"><?php echo esc_html( $h->name ); ?></a>
								<?php if ( ! (int) $h->active ) : ?>
									<span class="fw-crm-pill fw-crm-pill--off"><?php esc_html_e( 'Off', 'fw' ); ?></span>
								<?php endif; ?>
								<br /><code class="fw-crm-webhooks__url"><?php echo esc_html( $h->url ); ?></code>
							</td>
							<td><?php echo in_array( '*', $h->events, true ) ? esc_html__( 'All events', 'fw' ) : esc_html( sprintf( _n( '%s event', '%s events', count( $h->events ), 'fw' ), number_format_i18n( count( $h->events ) ) ) ); ?></td>
							<td>
								<?php
								echo esc_html( sprintf( __( '%1$s delivered · %2$s failed', 'fw' ), number_format_i18n( $st['sent'] ), number_format_i18n( $st['failed'] ) ) );
								if ( $st['pending'] + $st['sending'] ) {
									echo '<br /><span class="fw-crm-muted">' . esc_html( sprintf( __( '%s waiting', 'fw' ), number_format_i18n( $st['pending'] + $st['sending'] ) ) ) . '</span>';
								}
								?>
							</td>
							<td>
								<?php if ( $h->last_delivery_at ) : ?>
									<?php echo self::code_badge( (int) $h->last_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php echo FW_CRM_App::time_ago( get_date_from_gmt( $h->last_delivery_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php else : ?>
									<span class="fw-crm-muted"><?php esc_html_e( 'Nothing sent yet', 'fw' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param object|null $h
	 * @param bool        $wanted an existing one was asked for
	 */
	private static function render_edit( $h, $wanted ) {
		$base = FW_CRM_Admin_Page::get_page_url( 'webhooks' );
		if ( $wanted && ! $h ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Webhook not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to webhooks', 'fw' ), $base ) ) );
			return;
		}
		$events  = FW_CRM_Webhooks::events();
		$chosen  = $h ? $h->events : array();
		$all     = in_array( '*', $chosen, true );
		$grouped = array();
		foreach ( $events as $slug => $e ) {
			$grouped[ $e['group'] ?? __( 'Other', 'fw' ) ][ $slug ] = $e;
		}
		?>
		<div class="fw-crm-webhook">
			<form method="post" action="" class="fw-crm-panel fw-crm-webhook__form">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="webhook_save" />
				<?php if ( $h ) : ?>
					<input type="hidden" name="webhook" value="<?php echo (int) $h->id; ?>" />
				<?php endif; ?>
				<header class="fw-crm-panel__head"><h2><?php echo $h ? esc_html__( 'Settings', 'fw' ) : esc_html__( 'New webhook', 'fw' ); ?></h2></header>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Send to this address', 'fw' ); ?></span>
					<input type="url" name="url" required placeholder="https://" value="<?php echo esc_attr( $h ? $h->url : '' ); ?>" />
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Name (for you)', 'fw' ); ?></span>
					<input type="text" name="name" value="<?php echo esc_attr( $h ? $h->name : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Sync to the accounting app', 'fw' ); ?>" />
				</label>
				<fieldset class="fw-crm-webhook__events">
					<legend><?php esc_html_e( 'Events to send', 'fw' ); ?></legend>
					<label class="fw-crm-webhook__all"><input type="checkbox" name="all_events" value="1" <?php checked( $all ); ?> /> <?php esc_html_e( 'Every event, including ones added later', 'fw' ); ?></label>
					<div class="fw-crm-webhook__groups">
						<?php foreach ( $grouped as $group => $list ) : ?>
							<div>
								<p class="fw-crm-webhook__group"><?php echo esc_html( $group ); ?></p>
								<?php foreach ( $list as $slug => $e ) : ?>
									<label><input type="checkbox" name="events[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $all || in_array( $slug, $chosen, true ) ); ?> /> <?php echo esc_html( $e['label'] ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<label class="fw-crm-webhook__active"><input type="checkbox" class="fw-crm-toggle" role="switch" name="active" value="1" <?php checked( ! $h || (int) $h->active ); ?> /> <?php esc_html_e( 'Send events to this webhook', 'fw' ); ?></label>
				<?php if ( $h && ! (int) $h->active && (int) $h->failures >= FW_CRM_Webhooks::DISABLE_AFTER ) : ?>
					<p class="fw-crm-notice-inline"><?php echo esc_html( sprintf( __( 'Switched off after %d failed deliveries in a row. Fix the receiving end, send a test, then switch it back on.', 'fw' ), FW_CRM_Webhooks::DISABLE_AFTER ) ); ?></p>
				<?php endif; ?>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php echo $h ? esc_html__( 'Save changes', 'fw' ) : esc_html__( 'Add webhook', 'fw' ); ?></button>
					<a class="button-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
				</p>
			</form>

			<?php if ( $h ) : ?>
				<aside class="fw-crm-webhook__side">
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-wh-secret">
						<header class="fw-crm-panel__head"><h2 id="fw-crm-h-wh-secret"><?php esc_html_e( 'Signing secret', 'fw' ); ?></h2></header>
						<p class="fw-crm-muted"><?php esc_html_e( 'Every request carries X-UnysonPlus-Signature: sha256= followed by the HMAC-SHA256 of the timestamp, a dot and the body, made with this secret. Check it before trusting a request.', 'fw' ); ?></p>
						<label class="screen-reader-text" for="fw-crm-wh-secret"><?php esc_html_e( 'Signing secret', 'fw' ); ?></label>
						<input type="text" id="fw-crm-wh-secret" class="fw-crm-webhook__secret" readonly value="<?php echo esc_attr( $h->secret ); ?>" onfocus="this.select()" />
						<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'Make a new secret? The receiving app must be updated, or it will reject every request.', 'fw' ) ); ?>');">
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="webhook_secret" />
							<input type="hidden" name="webhook" value="<?php echo (int) $h->id; ?>" />
							<button type="submit" class="button-link"><?php esc_html_e( 'Make a new secret', 'fw' ); ?></button>
						</form>
					</section>
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-wh-test">
						<header class="fw-crm-panel__head"><h2 id="fw-crm-h-wh-test"><?php esc_html_e( 'Check it works', 'fw' ); ?></h2></header>
						<p class="fw-crm-muted"><?php esc_html_e( 'Sends a "ping" event now and shows what the address answered.', 'fw' ); ?></p>
						<form method="post" action="">
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="webhook_test" />
							<input type="hidden" name="webhook" value="<?php echo (int) $h->id; ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Send a test', 'fw' ); ?></button>
						</form>
					</section>
					<form method="post" action="" class="fw-crm-webhook__delete" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this webhook and its delivery log?', 'fw' ) ); ?>');">
						<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_crm_action" value="webhook_delete" />
						<input type="hidden" name="webhook" value="<?php echo (int) $h->id; ?>" />
						<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete webhook', 'fw' ); ?></button>
					</form>
				</aside>
			<?php endif; ?>
		</div>

		<?php if ( $h ) : ?>
			<?php self::render_log( $h ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param object $h
	 */
	private static function render_log( $h ) {
		list( $rows ) = FW_CRM_Webhooks::deliveries( array( 'webhook_id' => (int) $h->id, 'per_page' => 50 ) );
		$labels       = array(
			'pending' => __( 'Waiting', 'fw' ),
			'sending' => __( 'Sending', 'fw' ),
			'sent'    => __( 'Delivered', 'fw' ),
			'failed'  => __( 'Failed', 'fw' ),
		);
		?>
		<section class="fw-crm-panel fw-crm-webhook__log" aria-labelledby="fw-crm-h-wh-log">
			<header class="fw-crm-panel__head"><h2 id="fw-crm-h-wh-log"><?php esc_html_e( 'Deliveries', 'fw' ); ?></h2><span class="fw-crm-muted"><?php esc_html_e( 'The latest 50. Finished ones are kept for 30 days.', 'fw' ); ?></span></header>
			<?php if ( ! $rows ) : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'Nothing sent yet. Send a test, or wait for one of the chosen events to happen.', 'fw' ); ?></p>
			<?php else : ?>
				<ul class="fw-crm-deliveries">
					<?php foreach ( $rows as $r ) : ?>
						<li class="fw-crm-delivery is-<?php echo esc_attr( $r->status ); ?>">
							<details>
								<summary>
									<span class="fw-crm-delivery__status"><?php echo esc_html( $labels[ $r->status ] ?? $r->status ); ?></span>
									<code><?php echo esc_html( $r->event ); ?></code>
									<span class="fw-crm-delivery__meta">
										<?php
										$bits = array();
										if ( (int) $r->response_code ) {
											$bits[] = 'HTTP ' . (int) $r->response_code;
										}
										if ( (int) $r->attempts > 1 ) {
											$bits[] = sprintf( _n( '%s try', '%s tries', (int) $r->attempts, 'fw' ), number_format_i18n( (int) $r->attempts ) );
										}
										if ( 'pending' === $r->status && (int) $r->attempts && $r->next_attempt_at ) {
											$bits[] = sprintf( __( 'next try %s', 'fw' ), human_time_diff( time(), strtotime( $r->next_attempt_at . ' UTC' ) ) );
										}
										echo esc_html( implode( ' · ', $bits ) );
										?>
									</span>
									<?php echo FW_CRM_App::time_ago( get_date_from_gmt( $r->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</summary>
								<div class="fw-crm-delivery__body">
									<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'Delivery %s', 'fw' ), $r->uuid ) ); ?></p>
									<p class="fw-crm-delivery__label"><?php esc_html_e( 'Sent', 'fw' ); ?></p>
									<pre><?php echo esc_html( (string) wp_json_encode( json_decode( (string) $r->payload ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
									<?php if ( '' !== (string) $r->response_body ) : ?>
										<p class="fw-crm-delivery__label"><?php esc_html_e( 'Answer', 'fw' ); ?></p>
										<pre><?php echo esc_html( (string) $r->response_body ); ?></pre>
									<?php endif; ?>
									<?php if ( in_array( $r->status, array( 'failed', 'pending' ), true ) && 'ping' !== $r->event ) : ?>
										<form method="post" action="">
											<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
											<input type="hidden" name="fw_crm_action" value="webhook_retry" />
											<input type="hidden" name="webhook" value="<?php echo (int) $h->id; ?>" />
											<input type="hidden" name="delivery" value="<?php echo (int) $r->id; ?>" />
											<button type="submit" class="button"><?php esc_html_e( 'Send again now', 'fw' ); ?></button>
										</form>
									<?php endif; ?>
								</div>
							</details>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param int $code
	 *
	 * @return string HTML
	 */
	private static function code_badge( $code ) {
		$ok = $code >= 200 && $code < 300;
		return sprintf( '<span class="fw-crm-pill %s">%s</span> ', $ok ? 'fw-crm-pill--ok' : 'fw-crm-pill--bad', esc_html( $code ? 'HTTP ' . $code : __( 'No answer', 'fw' ) ) );
	}
}
