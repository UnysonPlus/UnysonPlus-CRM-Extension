<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Settings → "Replies on the timeline": the inbound address and the mailbox.
 */
class FW_CRM_Admin_Inbound {

	public static function init() {
		add_action( 'unysonplus_crm_settings_sections', array( __CLASS__, 'render' ) );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'inbound_' ) ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		$back = array( 'tab' => 'settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		switch ( $action ) {
			case 'inbound_token':
				FW_CRM_Inbound_Email::new_token();
				FW_CRM_Admin_Page::flash( 'success', __( 'New inbound address made. Update it wherever the old one was used: the old address no longer works.', 'fw' ) );
				return $back;

			case 'inbound_save':
			case 'inbound_test':
				$s       = FW_CRM_Inbound_Email::settings();
				$pass    = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a password, stored encrypted.
				$changes = array(
					'imap_on'        => ! empty( $_POST['imap_on'] ),
					'host'           => isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '',
					'port'           => isset( $_POST['port'] ) ? max( 1, min( 65535, (int) $_POST['port'] ) ) : 993,
					'security'       => isset( $_POST['security'] ) && in_array( $_POST['security'], array( 'ssl', 'tls', 'none' ), true ) ? sanitize_key( $_POST['security'] ) : 'ssl',
					'user'           => isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '',
					'folder'         => isset( $_POST['folder'] ) && '' !== trim( (string) $_POST['folder'] ) ? sanitize_text_field( wp_unslash( $_POST['folder'] ) ) : 'INBOX',
					'create_unknown' => ! empty( $_POST['create_unknown'] ),
				);
				if ( '' !== $pass ) {
					$changes['pass'] = FW_CRM_Inbound_Email::encrypt( $pass );
				}
				// A different mailbox starts from its own newest message.
				if ( $changes['host'] !== $s['host'] || $changes['user'] !== $s['user'] || $changes['folder'] !== $s['folder'] ) {
					$changes['last_uid']    = 0;
					$changes['uidvalidity'] = 0;
				}
				if ( 'inbound_test' === $action ) {
					$imap = FW_CRM_Inbound_Email::open( array_merge( $s, $changes ) );
					if ( is_wp_error( $imap ) ) {
						FW_CRM_Admin_Page::flash( 'error', sprintf( __( 'Could not connect: %s', 'fw' ), $imap->get_error_message() ) );
						return $back; // Not saved: keep what worked before.
					}
					$count = (int) $imap->box['exists'];
					$imap->logout();
					FW_CRM_Inbound_Email::save_settings( $changes );
					FW_CRM_Admin_Page::flash( 'success', sprintf( _n( 'Connected: %s message in the folder. Settings saved.', 'Connected: %s messages in the folder. Settings saved.', $count, 'fw' ), number_format_i18n( $count ) ) );
					return $back;
				}
				FW_CRM_Inbound_Email::save_settings( $changes );
				FW_CRM_Admin_Page::flash( 'success', __( 'Saved.', 'fw' ) );
				return $back;

			case 'inbound_check':
				$r = FW_CRM_Inbound_Email::poll();
				FW_CRM_Admin_Page::flash( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : FW_CRM_Inbound_Email::settings()['last_result'] );
				return $back;
		}
		// phpcs:enable
		return $args;
	}

	public static function render() {
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			return;
		}
		$s    = FW_CRM_Inbound_Email::settings();
		$url  = FW_CRM_Inbound_Email::inbound_url();
		$base = FW_CRM_Admin_Page::get_page_url();
		?>
		<section class="fw-crm-panel fw-crm-inbound" id="fw-crm-inbound" aria-labelledby="fw-crm-h-inbound">
			<header class="fw-crm-panel__head"><h2 id="fw-crm-h-inbound"><?php esc_html_e( 'Replies on the timeline', 'fw' ); ?></h2></header>
			<p class="fw-crm-muted"><?php esc_html_e( 'When a contact answers an email, the reply can land on their timeline (and on the deal it was about). Use either way below, or both.', 'fw' ); ?></p>

			<h3><?php esc_html_e( '1. An inbound address', 'fw' ); ?></h3>
			<p><?php esc_html_e( 'Most email services can pass each email that arrives at an address of yours to a web address ("inbound parse", "inbound routes", "email webhooks"). Point that at the address below. Then put that email address in Bcc when you write from your own email program, or forward replies to it.', 'fw' ); ?></p>
			<div class="fw-crm-inbound__url">
				<label class="screen-reader-text" for="fw-crm-inbound-url"><?php esc_html_e( 'Inbound address', 'fw' ); ?></label>
				<input type="text" id="fw-crm-inbound-url" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select()" />
				<button type="button" class="button" data-copy="#fw-crm-inbound-url"><?php esc_html_e( 'Copy', 'fw' ); ?></button>
				<a class="button-link" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'settings', 'fw_crm_action' => 'inbound_token' ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'The current address will stop working. Continue?', 'fw' ) ); ?>');"><?php esc_html_e( 'Make a new address', 'fw' ); ?></a>
			</div>
			<p class="fw-crm-muted"><?php esc_html_e( 'Keep it private: anyone who has it can add email to the timeline. It accepts a raw email or the usual parsed fields (from, to, subject, text).', 'fw' ); ?></p>

			<h3><?php esc_html_e( '2. A mailbox the CRM checks', 'fw' ); ?></h3>
			<p><?php esc_html_e( 'Connect a mailbox (IMAP) that receives the replies, such as a shared sales address. The CRM checks it every five minutes and reads only mail that arrives after you connect it. Messages are left in the mailbox as they are.', 'fw' ); ?></p>
			<form method="post" action="" class="fw-crm-form fw-crm-inbound__form">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<label class="fw-crm-check"><input type="checkbox" name="imap_on" value="1" <?php checked( $s['imap_on'] ); ?> /> <?php esc_html_e( 'Check this mailbox', 'fw' ); ?></label>
				<div class="fw-crm-inbound__grid">
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Server', 'fw' ); ?></span><input type="text" name="host" value="<?php echo esc_attr( $s['host'] ); ?>" placeholder="imap.example.com" autocomplete="off" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Port', 'fw' ); ?></span><input type="number" name="port" value="<?php echo (int) $s['port']; ?>" min="1" max="65535" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Security', 'fw' ); ?></span>
						<select name="security">
							<option value="ssl" <?php selected( $s['security'], 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS (usually port 993)', 'fw' ); ?></option>
							<option value="tls" <?php selected( $s['security'], 'tls' ); ?>><?php esc_html_e( 'STARTTLS (usually port 143)', 'fw' ); ?></option>
							<option value="none" <?php selected( $s['security'], 'none' ); ?>><?php esc_html_e( 'None (not recommended)', 'fw' ); ?></option>
						</select>
					</label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'User name', 'fw' ); ?></span><input type="text" name="user" value="<?php echo esc_attr( $s['user'] ); ?>" autocomplete="off" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Folder', 'fw' ); ?></span><input type="text" name="folder" value="<?php echo esc_attr( $s['folder'] ); ?>" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Password', 'fw' ); ?></span><input type="password" name="pass" value="" autocomplete="new-password" placeholder="<?php echo '' !== $s['pass'] ? esc_attr__( 'Saved (leave empty to keep it)', 'fw' ) : ''; ?>" /></label>
				</div>
				<p class="fw-crm-muted"><?php esc_html_e( 'Many providers need an app password here rather than your normal password. The password is stored encrypted.', 'fw' ); ?></p>
				<label class="fw-crm-check"><input type="checkbox" name="create_unknown" value="1" <?php checked( $s['create_unknown'] ); ?> /> <?php esc_html_e( 'Add people who are not contacts yet as new contacts (otherwise their mail is skipped)', 'fw' ); ?></label>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary" name="fw_crm_action" value="inbound_save"><?php esc_html_e( 'Save', 'fw' ); ?></button>
					<button type="submit" class="button" name="fw_crm_action" value="inbound_test"><?php esc_html_e( 'Test connection and save', 'fw' ); ?></button>
					<?php if ( FW_CRM_Inbound_Email::imap_on() ) : ?>
						<button type="submit" class="button" name="fw_crm_action" value="inbound_check"><?php esc_html_e( 'Check now', 'fw' ); ?></button>
					<?php endif; ?>
				</p>
				<?php if ( '' !== $s['last_run'] ) : ?>
					<p class="fw-crm-muted fw-crm-inbound__last" role="status"><?php echo esc_html( sprintf( __( 'Last checked %1$s: %2$s', 'fw' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $s['last_run'] ) ), $s['last_result'] ) ); ?></p>
				<?php endif; ?>
			</form>
		</section>
		<?php
	}
}
