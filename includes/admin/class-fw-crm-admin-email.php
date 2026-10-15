<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The "Email" button on contact, company and deal pages: a compose dialog that
 * sends a one-to-one email (FW_CRM_Direct_Email) to the contact, or to one of the
 * company's people or the deal's contacts.
 */
class FW_CRM_Admin_Email {

	public static function init() {
		add_action( 'unysonplus_crm_record_actions', array( __CLASS__, 'render_button' ), 10, 2 );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/**
	 * Who an email from this record can go to.
	 *
	 * @param string $object
	 * @param int    $id
	 *
	 * @return array contact id => "Name <email>"
	 */
	public static function recipients( $object, $id ) {
		switch ( $object ) {
			case 'contact':
				$c = FW_CRM_Subscribers::find( (int) $id );
				return $c && is_email( (string) $c->email ) ? array( (int) $c->id => trim( $c->first_name . ' ' . $c->last_name . ' <' . $c->email . '>' ) ) : array();
			case 'company':
				$out = array();
				foreach ( FW_CRM_Companies::contact_ids( (int) $id ) as $cid ) {
					$c = FW_CRM_Subscribers::find( (int) $cid );
					if ( $c && ! $c->deleted_at && is_email( (string) $c->email ) ) {
						$out[ (int) $c->id ] = trim( $c->first_name . ' ' . $c->last_name . ' <' . $c->email . '>' );
					}
				}
				return $out;
			case 'deal':
				return FW_CRM_Direct_Email::deal_recipients( (int) $id );
		}
		return array();
	}

	/**
	 * @param string $object
	 * @param int    $id
	 *
	 * @return bool
	 */
	private static function can_write( $object, $id ) {
		switch ( $object ) {
			case 'contact':
				return FW_CRM_Capabilities::can_edit_contact( (int) $id );
			case 'company':
				return FW_CRM_Capabilities::can_edit_company( (int) $id );
			case 'deal':
				return class_exists( 'FW_CRM_Deals' ) && FW_CRM_Capabilities::can_edit_deal( (int) $id );
		}
		return false;
	}

	/**
	 * @param string       $object
	 * @param array|object $record
	 */
	public static function render_button( $object, $record ) {
		$id = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
		if ( ! self::can_write( $object, $id ) ) {
			return;
		}
		$to = self::recipients( $object, $id );
		if ( ! $to ) {
			return;
		}
		$query = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt into an admin URL and escaped on output.
		$back  = remove_query_arg( array( 'fw_crm_action', '_wpnonce' ), admin_url( 'admin.php?' . $query ) );
		$user  = wp_get_current_user();
		// A draft kept from a send that failed reopens, filled in.
		$draft = get_transient( 'fw_crm_email_draft_' . get_current_user_id() );
		$draft = is_array( $draft ) && $draft['object'] === $object && (int) $draft['object_id'] === $id ? $draft : null;
		if ( $draft ) {
			delete_transient( 'fw_crm_email_draft_' . get_current_user_id() );
		}
		$body = $draft ? $draft['body'] : sprintf( __( "Hi {{first_name}},\n\n\n\n%s", 'fw' ), $user->display_name );
		?>
		<button type="button" class="button" data-open-dialog="fw-crm-compose"<?php echo $draft ? ' data-open-now' : ''; ?>><span class="dashicons dashicons-email" aria-hidden="true"></span> <?php esc_html_e( 'Email', 'fw' ); ?></button>
		<dialog class="fw-crm-dialog fw-crm-compose" id="fw-crm-compose" aria-labelledby="fw-crm-compose-h">
			<form method="post" action="" class="fw-crm-form">
				<h2 id="fw-crm-compose-h"><?php esc_html_e( 'New email', 'fw' ); ?></h2>
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="direct_email" />
				<input type="hidden" name="object" value="<?php echo esc_attr( $object ); ?>" />
				<input type="hidden" name="object_id" value="<?php echo (int) $id; ?>" />
				<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'To', 'fw' ); ?></span>
					<?php if ( 1 === count( $to ) ) : ?>
						<input type="hidden" name="to" value="<?php echo (int) key( $to ); ?>" />
						<input type="text" value="<?php echo esc_attr( current( $to ) ); ?>" readonly />
					<?php else : ?>
						<select name="to" required>
							<?php foreach ( $to as $cid => $label ) : ?>
								<option value="<?php echo (int) $cid; ?>" <?php selected( $draft ? (int) $draft['to'] : 0, (int) $cid ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Cc (optional)', 'fw' ); ?></span>
					<input type="text" name="cc" value="<?php echo esc_attr( $draft ? $draft['cc'] : '' ); ?>" inputmode="email" autocomplete="off" placeholder="<?php esc_attr_e( 'name@example.com, another@example.com', 'fw' ); ?>" />
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Subject', 'fw' ); ?></span>
					<input type="text" name="subject" value="<?php echo esc_attr( $draft ? $draft['subject'] : '' ); ?>" required />
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Message', 'fw' ); ?></span>
					<textarea name="body" rows="9" required><?php echo esc_textarea( $body ); ?></textarea>
				</label>
				<p class="fw-crm-muted fw-crm-compose__note">
					<?php
					echo esc_html( sprintf(
						/* translators: %s: the writer's email */
						__( 'Replies go to %s. {{first_name}}, {{name}} and {{site_name}} are filled in. It is saved on the timeline.', 'fw' ),
						$user->user_email
					) );
					?>
				</p>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'fw' ); ?></button>
					<button type="button" class="button" data-close><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 'direct_email' !== $action ) {
			return $args;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$object = isset( $_POST['object'] ) ? sanitize_key( wp_unslash( $_POST['object'] ) ) : '';
		$id     = isset( $_POST['object_id'] ) ? (int) $_POST['object_id'] : 0;
		$to     = isset( $_POST['to'] ) ? (int) $_POST['to'] : 0;
		$back   = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : FW_CRM_Admin_Page::get_page_url();
		if ( ! self::can_write( $object, $id ) || ! isset( self::recipients( $object, $id )[ $to ] ) ) {
			wp_die( esc_html__( 'You cannot write to that contact from here.', 'fw' ), 403 );
		}
		$mail   = array(
			'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'body'    => isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '',
			'cc'      => isset( $_POST['cc'] ) ? sanitize_text_field( wp_unslash( $_POST['cc'] ) ) : '',
			'deal_id' => 'deal' === $object ? $id : 0,
		);
		// phpcs:enable
		$result = FW_CRM_Direct_Email::send( $to, $mail );
		if ( is_wp_error( $result ) ) {
			set_transient( 'fw_crm_email_draft_' . get_current_user_id(), array_merge( $mail, array( 'object' => $object, 'object_id' => $id, 'to' => $to ) ), 10 * MINUTE_IN_SECONDS );
		}
		FW_CRM_Admin_Page::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Email sent.', 'fw' ) );
		return array( '_back' => $back );
	}
}
