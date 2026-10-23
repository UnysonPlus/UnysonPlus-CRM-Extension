<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Settings, "Sending": sender profiles, the email services they can use (with the
 * address each service reports bounces to), a test send, and the post digest.
 */
class FW_CRM_Admin_Sending {

	public static function init() {
		add_action( 'unysonplus_crm_settings_sections', array( __CLASS__, 'render' ), 5 );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'sending_' ) ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		$back = array( 'tab' => 'settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		switch ( $action ) {
			case 'sending_profile_save':
				$res = FW_CRM_Senders::save( array(
					'id'         => isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '',
					'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
					'from_name'  => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
					'from_email' => isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '',
					'reply_to'   => isset( $_POST['reply_to'] ) ? sanitize_email( wp_unslash( $_POST['reply_to'] ) ) : '',
					'service'    => isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '',
				) );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Sender saved.', 'fw' ) );
				return $back + array( 'edit_sender' => is_wp_error( $res ) ? 'new' : '' );

			case 'sending_profile_delete':
				FW_CRM_Senders::delete( isset( $_REQUEST['profile_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['profile_id'] ) ) : '' );
				FW_CRM_Admin_Page::flash( 'success', __( 'Sender deleted.', 'fw' ) );
				return $back;

			case 'sending_defaults':
				FW_CRM_Senders::set_defaults(
					isset( $_POST['marketing'] ) ? sanitize_key( wp_unslash( $_POST['marketing'] ) ) : '',
					isset( $_POST['transactional'] ) ? sanitize_key( wp_unslash( $_POST['transactional'] ) ) : ''
				);
				FW_CRM_Admin_Page::flash( 'success', __( 'Default senders saved.', 'fw' ) );
				return $back;

			case 'sending_service_save':
				$service = FW_CRM_Mail_Service::get( isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '' );
				if ( $service ) {
					$values = isset( $_POST['svc'] ) && is_array( $_POST['svc'] ) ? wp_unslash( $_POST['svc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field by save_settings(); secrets are stored encrypted.
					$service->save_settings( $values );
					FW_CRM_Admin_Page::flash( 'success', sprintf( __( '%s settings saved.', 'fw' ), $service->label() ) );
				}
				return $back;

			case 'sending_test':
				$to      = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
				$profile = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
				if ( ! is_email( $to ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'Enter an address to send the test to.', 'fw' ) );
					return $back;
				}
				$p     = FW_CRM_Senders::get( $profile );
				$error = null;
				$catch = function ( $e ) use ( &$error ) {
					$error = $e;
				};
				add_action( 'wp_mail_failed', $catch );
				FW_CRM_Senders::begin( 'transactional', $profile );
				$ok = wp_mail( $to, __( 'Test email from the CRM', 'fw' ), '<p>' . esc_html( sprintf( __( 'This test was sent with the sender "%s".', 'fw' ), $p ? $p['name'] : __( 'default', 'fw' ) ) ) . '</p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
				FW_CRM_Senders::end();
				remove_action( 'wp_mail_failed', $catch );
				FW_CRM_Admin_Page::flash( $ok ? 'success' : 'error', $ok ? sprintf( __( 'Test sent to %s.', 'fw' ), $to ) : ( $error instanceof WP_Error ? $error->get_error_message() : __( 'The test could not be sent.', 'fw' ) ) );
				return $back;

			case 'sending_digest':
				if ( class_exists( 'FW_CRM_Digest' ) ) {
					FW_CRM_Digest::save_settings( array(
						'on'        => ! empty( $_POST['on'] ),
						'frequency' => isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'weekly',
						'weekday'   => isset( $_POST['weekday'] ) ? (int) $_POST['weekday'] : 1,
						'hour'      => isset( $_POST['hour'] ) ? (int) $_POST['hour'] : 9,
						'list'      => isset( $_POST['a_list'] ) ? sanitize_key( wp_unslash( $_POST['a_list'] ) ) : '',
						'tag'       => isset( $_POST['a_tag'] ) ? sanitize_key( wp_unslash( $_POST['a_tag'] ) ) : '',
						'segment'   => isset( $_POST['a_segment'] ) ? (int) $_POST['a_segment'] : 0,
						'subject'   => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
						'intro'     => isset( $_POST['intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['intro'] ) ) : '',
						'count'     => isset( $_POST['count'] ) ? (int) $_POST['count'] : 5,
						'post_type' => isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post',
						'sender_id' => isset( $_POST['sender_id'] ) ? sanitize_key( wp_unslash( $_POST['sender_id'] ) ) : '',
					) );
					if ( ! empty( $_POST['send_now'] ) ) {
						$res = FW_CRM_Digest::send_now();
						if ( is_wp_error( $res ) ) {
							FW_CRM_Admin_Page::flash( 'error', $res->get_error_message() );
						} elseif ( null === $res ) {
							FW_CRM_Admin_Page::flash( 'success', __( 'Nothing new was published since the last digest, so none was sent.', 'fw' ) );
						} else {
							FW_CRM_Admin_Page::flash( 'success', __( 'The digest was created and queued as a campaign.', 'fw' ) );
							return array( 'tab' => 'campaigns', 'campaign' => (int) $res );
						}
					} else {
						FW_CRM_Admin_Page::flash( 'success', __( 'Post digest saved.', 'fw' ) );
					}
				}
				return $back;
		}
		// phpcs:enable
		return $args;
	}

	public static function render() {
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			return;
		}
		$s        = FW_CRM_Senders::settings();
		$services = FW_CRM_Mail_Service::all();
		$edit     = isset( $_GET['edit_sender'] ) ? sanitize_key( wp_unslash( $_GET['edit_sender'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$editing  = 'new' === $edit ? array( 'id' => '', 'name' => '', 'from_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 'from_email' => get_option( 'admin_email' ), 'reply_to' => '', 'service' => '' ) : FW_CRM_Senders::get( $edit );
		$base     = FW_CRM_Admin_Page::get_page_url( 'settings' );
		?>
		<section class="fw-crm-panel fw-crm-sending" id="fw-crm-sending" aria-labelledby="fw-crm-h-sending">
			<header class="fw-crm-panel__head"><h2 id="fw-crm-h-sending"><?php esc_html_e( 'Sending', 'fw' ); ?></h2></header>
			<p class="description"><?php esc_html_e( 'Who CRM emails come from, and how they are sent. Marketing email (campaigns, digests) and transactional email (confirmations, one-to-one email, case replies) each have a default sender, so they can use different addresses or services.', 'fw' ); ?></p>

			<h3><?php esc_html_e( 'Senders', 'fw' ); ?></h3>
			<?php if ( $s['profiles'] ) : ?>
				<table class="widefat striped fw-crm-senders">
					<thead><tr><th><?php esc_html_e( 'Sender', 'fw' ); ?></th><th><?php esc_html_e( 'From', 'fw' ); ?></th><th><?php esc_html_e( 'Reply-To', 'fw' ); ?></th><th><?php esc_html_e( 'Sent with', 'fw' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $s['profiles'] as $p ) : ?>
						<?php $svc = '' !== $p['service'] ? FW_CRM_Mail_Service::get( $p['service'] ) : null; ?>
						<tr>
							<td><strong><?php echo esc_html( $p['name'] ); ?></strong>
								<?php if ( $s['marketing'] === $p['id'] ) : ?><span class="fw-crm-status fw-crm-status--sending"><?php esc_html_e( 'Marketing default', 'fw' ); ?></span><?php endif; ?>
								<?php if ( $s['transactional'] === $p['id'] ) : ?><span class="fw-crm-status fw-crm-status--sent"><?php esc_html_e( 'Transactional default', 'fw' ); ?></span><?php endif; ?>
							</td>
							<td><?php echo esc_html( '' !== $p['from_name'] ? $p['from_name'] . ' <' . $p['from_email'] . '>' : $p['from_email'] ); ?></td>
							<td><?php echo esc_html( '' !== $p['reply_to'] ? $p['reply_to'] : '—' ); ?></td>
							<td>
								<?php
								if ( ! $svc ) {
									esc_html_e( 'The site\'s own mail', 'fw' );
								} else {
									echo esc_html( $svc->label() );
									if ( ! $svc->is_configured() ) {
										echo ' <span class="fw-crm-status fw-crm-status--bounced">' . esc_html__( 'not set up: the site\'s own mail is used', 'fw' ) . '</span>';
									}
								}
								?>
							</td>
							<td class="fw-crm-senders__actions">
								<a href="<?php echo esc_url( add_query_arg( 'edit_sender', $p['id'], $base ) . '#fw-crm-sending' ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a>
								<a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'sending_profile_delete', 'profile_id' => $p['id'] ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this sender? Campaigns that used it switch to the default.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'No senders yet: CRM email uses the Mailer settings (or WordPress\'s own From address). Add one to choose the From name and address per kind of email.', 'fw' ); ?></p>
			<?php endif; ?>

			<?php if ( $editing ) : ?>
				<form method="post" action="" class="fw-crm-form fw-crm-sender-form">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="sending_profile_save" />
					<input type="hidden" name="profile_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
					<div class="fw-crm-form__grid">
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Name (for you)', 'fw' ); ?></span><input type="text" name="name" required value="<?php echo esc_attr( $editing['name'] ); ?>" placeholder="<?php esc_attr_e( 'Newsletter', 'fw' ); ?>" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'From name', 'fw' ); ?></span><input type="text" name="from_name" value="<?php echo esc_attr( $editing['from_name'] ); ?>" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'From address', 'fw' ); ?></span><input type="email" name="from_email" required value="<?php echo esc_attr( $editing['from_email'] ); ?>" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Reply-To (optional)', 'fw' ); ?></span><input type="email" name="reply_to" value="<?php echo esc_attr( $editing['reply_to'] ); ?>" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Send with', 'fw' ); ?></span>
							<select name="service">
								<option value=""><?php esc_html_e( 'The site\'s own mail (Mailer / SMTP)', 'fw' ); ?></option>
								<?php foreach ( $services as $svc ) : ?>
									<option value="<?php echo esc_attr( $svc->id() ); ?>" <?php selected( $editing['service'], $svc->id() ); ?>><?php echo esc_html( $svc->label() . ( $svc->is_configured() ? '' : ' (' . __( 'set it up below', 'fw' ) . ')' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<p class="description"><?php esc_html_e( 'The From address must be one your mail host or email service is allowed to send for (a verified domain), or the email will be rejected or land in spam.', 'fw' ); ?></p>
					<p class="fw-crm-form__foot">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save sender', 'fw' ); ?></button>
						<a class="button" href="<?php echo esc_url( $base . '#fw-crm-sending' ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
					</p>
				</form>
			<?php else : ?>
				<p><a class="button" href="<?php echo esc_url( add_query_arg( 'edit_sender', 'new', $base ) . '#fw-crm-sending' ); ?>"><?php esc_html_e( 'Add a sender', 'fw' ); ?></a></p>
			<?php endif; ?>

			<?php if ( $s['profiles'] ) : ?>
				<form method="post" action="" class="fw-crm-inline fw-crm-sender-defaults">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="sending_defaults" />
					<?php foreach ( array( 'marketing' => __( 'Marketing email from', 'fw' ), 'transactional' => __( 'Transactional email from', 'fw' ) ) as $kind => $label ) : ?>
						<label><?php echo esc_html( $label ); ?>
							<select name="<?php echo esc_attr( $kind ); ?>">
								<?php foreach ( $s['profiles'] as $p ) : ?>
									<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $s[ $kind ], $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endforeach; ?>
					<button type="submit" class="button"><?php esc_html_e( 'Save defaults', 'fw' ); ?></button>
				</form>

				<form method="post" action="" class="fw-crm-inline fw-crm-sender-test">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="sending_test" />
					<label><?php esc_html_e( 'Send a test with', 'fw' ); ?>
						<select name="profile_id">
							<?php foreach ( $s['profiles'] as $p ) : ?>
								<option value="<?php echo esc_attr( $p['id'] ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="screen-reader-text" for="fw-crm-sender-test-to"><?php esc_html_e( 'To', 'fw' ); ?></label>
					<input type="email" id="fw-crm-sender-test-to" name="to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Send test', 'fw' ); ?></button>
				</form>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Email services', 'fw' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Send through an email service\'s API instead of the site\'s own mail: faster for big lists, better delivered, and bounces and spam complaints come back to the CRM. Set one up here, then choose it on a sender.', 'fw' ); ?></p>
			<?php foreach ( $services as $svc ) : ?>
				<?php $vals = $svc->settings(); ?>
				<details class="fw-crm-service" <?php echo $svc->is_configured() ? '' : ''; ?>>
					<summary>
						<strong><?php echo esc_html( $svc->label() ); ?></strong>
						<?php if ( $svc->is_configured() ) : ?>
							<span class="fw-crm-status fw-crm-status--sent"><?php esc_html_e( 'Set up', 'fw' ); ?></span>
						<?php endif; ?>
					</summary>
					<form method="post" action="" class="fw-crm-form">
						<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_crm_action" value="sending_service_save" />
						<input type="hidden" name="service" value="<?php echo esc_attr( $svc->id() ); ?>" />
						<div class="fw-crm-form__grid">
							<?php foreach ( $svc->fields() as $k => $f ) : ?>
								<label class="fw-crm-form__row"><span><?php echo esc_html( $f['label'] ); ?></span>
									<?php if ( 'select' === $f['type'] ) : ?>
										<select name="svc[<?php echo esc_attr( $k ); ?>]">
											<?php foreach ( $f['choices'] as $cv => $cl ) : ?>
												<option value="<?php echo esc_attr( $cv ); ?>" <?php selected( (string) ( $vals[ $k ] ?? '' ), (string) $cv ); ?>><?php echo esc_html( $cl ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php elseif ( 'password' === $f['type'] ) : ?>
										<input type="password" name="svc[<?php echo esc_attr( $k ); ?>]" value="" autocomplete="new-password" placeholder="<?php echo '' !== (string) ( $vals[ $k ] ?? '' ) ? esc_attr__( 'Saved: leave empty to keep it', 'fw' ) : ''; ?>" />
									<?php else : ?>
										<input type="text" name="svc[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( (string) ( $vals[ $k ] ?? '' ) ); ?>" />
									<?php endif; ?>
									<?php if ( ! empty( $f['desc'] ) ) : ?><small class="fw-crm-muted"><?php echo esc_html( $f['desc'] ); ?></small><?php endif; ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="fw-crm-service__hook">
							<span><?php esc_html_e( 'Bounce and complaint webhook (paste it into the service):', 'fw' ); ?></span>
							<input type="text" readonly value="<?php echo esc_attr( $svc->webhook_url() ); ?>" onfocus="this.select()" />
						</p>
						<p class="fw-crm-form__foot"><button type="submit" class="button button-primary"><?php echo esc_html( sprintf( __( 'Save %s', 'fw' ), $svc->label() ) ); ?></button></p>
					</form>
				</details>
			<?php endforeach; ?>

			<?php if ( class_exists( 'FW_CRM_Digest' ) ) : ?>
				<?php self::render_digest(); ?>
			<?php endif; ?>
		</section>
		<style>
			.fw-crm-sending { margin-bottom: 1.5em; }
			.fw-crm-sending h3 { margin: 1.6em 0 .5em; }
			.fw-crm-senders .fw-crm-status { margin-left: .4em; }
			.fw-crm-senders__actions { text-align: right; white-space: nowrap; }
			.fw-crm-senders__actions a + a { margin-left: .8em; }
			.fw-crm-sender-form, .fw-crm-sender-defaults, .fw-crm-sender-test { margin-top: 1em; }
			.fw-crm-sender-defaults label, .fw-crm-sender-test label { margin-right: .6em; }
			.fw-crm-service { border: 1px solid var(--crm-border, #dcdcde); border-radius: 8px; margin: .5em 0; padding: .6em 1em; }
			.fw-crm-service summary { cursor: pointer; }
			.fw-crm-service summary .fw-crm-status { margin-left: .5em; }
			.fw-crm-service form { margin-top: .8em; }
			.fw-crm-service__hook { display: flex; flex-wrap: wrap; gap: .5em; align-items: center; }
			.fw-crm-service__hook input { flex: 1 1 320px; font-family: monospace; font-size: 12px; }
			.fw-crm-form__row small { display: block; margin-top: .25em; }
		</style>
		<?php
	}

	/** The post digest settings. */
	private static function render_digest() {
		$d     = FW_CRM_Digest::settings();
		$days  = array( 1 => __( 'Monday', 'fw' ), 2 => __( 'Tuesday', 'fw' ), 3 => __( 'Wednesday', 'fw' ), 4 => __( 'Thursday', 'fw' ), 5 => __( 'Friday', 'fw' ), 6 => __( 'Saturday', 'fw' ), 7 => __( 'Sunday', 'fw' ) );
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		?>
		<h3 id="fw-crm-digest"><?php esc_html_e( 'Post digest', 'fw' ); ?></h3>
		<p class="description"><?php esc_html_e( 'A recurring email of your latest posts. Each time it is due, the posts published since the last one become a campaign and are sent to the audience below. When nothing new was published, nothing is sent.', 'fw' ); ?></p>
		<form method="post" action="" class="fw-crm-form">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="sending_digest" />
			<label class="fw-crm-check"><input type="checkbox" name="on" value="1" <?php checked( $d['on'] ); ?> /> <?php esc_html_e( 'Send a post digest', 'fw' ); ?></label>
			<div class="fw-crm-form__grid">
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'How often', 'fw' ); ?></span>
					<select name="frequency">
						<option value="weekly" <?php selected( $d['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Every week', 'fw' ); ?></option>
						<option value="monthly" <?php selected( $d['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Every month, on the 1st', 'fw' ); ?></option>
					</select>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'On (weekly)', 'fw' ); ?></span>
					<select name="weekday"><?php foreach ( $days as $n => $label ) : ?><option value="<?php echo (int) $n; ?>" <?php selected( (int) $d['weekday'], $n ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'From', 'fw' ); ?></span>
					<select name="hour"><?php for ( $h = 0; $h < 24; $h++ ) : ?><option value="<?php echo (int) $h; ?>" <?php selected( (int) $d['hour'], $h ); ?>><?php echo esc_html( date_i18n( get_option( 'time_format' ), mktime( $h, 0, 0 ) ) ); ?></option><?php endfor; ?></select>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Posts', 'fw' ); ?></span>
					<select name="post_type"><?php foreach ( $types as $pt ) : ?><option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $d['post_type'], $pt->name ); ?>><?php echo esc_html( $pt->labels->name ); ?></option><?php endforeach; ?></select>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'At most', 'fw' ); ?></span><input type="number" name="count" min="1" max="20" value="<?php echo (int) $d['count']; ?>" /></label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'List', 'fw' ); ?></span>
					<select name="a_list"><option value=""><?php esc_html_e( 'Everyone subscribed', 'fw' ); ?></option><?php foreach ( FW_CRM_Lists::all( 'list' ) as $l ) : ?><option value="<?php echo esc_attr( $l->slug ); ?>" <?php selected( $d['list'], $l->slug ); ?>><?php echo esc_html( $l->title ); ?></option><?php endforeach; ?></select>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Tag', 'fw' ); ?></span>
					<select name="a_tag"><option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option><?php foreach ( FW_CRM_Lists::all( 'tag' ) as $t ) : ?><option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $d['tag'], $t->slug ); ?>><?php echo esc_html( $t->title ); ?></option><?php endforeach; ?></select>
				</label>
				<?php if ( FW_CRM_Lists::segments() ) : ?>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Segment', 'fw' ); ?></span>
						<select name="a_segment"><option value="0"><?php esc_html_e( 'None', 'fw' ); ?></option><?php foreach ( FW_CRM_Lists::segments() as $sg ) : ?><option value="<?php echo (int) $sg->id; ?>" <?php selected( (int) $d['segment'], (int) $sg->id ); ?>><?php echo esc_html( $sg->title ); ?></option><?php endforeach; ?></select>
					</label>
				<?php endif; ?>
				<?php if ( FW_CRM_Senders::profiles() ) : ?>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Sender', 'fw' ); ?></span>
						<select name="sender_id"><option value=""><?php esc_html_e( 'The default for campaigns', 'fw' ); ?></option><?php foreach ( FW_CRM_Senders::profiles() as $p ) : ?><option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $d['sender_id'], $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option><?php endforeach; ?></select>
					</label>
				<?php endif; ?>
				<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'Subject', 'fw' ); ?></span><input type="text" name="subject" value="<?php echo esc_attr( $d['subject'] ); ?>" /></label>
				<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'Opening line', 'fw' ); ?></span><textarea name="intro" rows="2"><?php echo esc_textarea( $d['intro'] ); ?></textarea></label>
			</div>
			<p class="fw-crm-muted">
				<?php
				echo esc_html( '' !== $d['last_sent']
					? sprintf( __( 'Last checked %s.', 'fw' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $d['last_sent'] ) ) )
					: __( 'Not sent yet. The first digest covers the last week (or month).', 'fw' ) );
				?>
			</p>
			<p class="fw-crm-form__foot">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save digest', 'fw' ); ?></button>
				<button type="submit" name="send_now" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Save and send a digest of the new posts now?', 'fw' ) ); ?>');"><?php esc_html_e( 'Save and send now', 'fw' ); ?></button>
			</p>
		</form>
		<?php
	}
}
