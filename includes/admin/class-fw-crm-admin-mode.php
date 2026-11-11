<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Settings → "CRM mode": run the whole site as a CRM, in one switch.
 *
 * The mechanism is core's Workspaces (Unyson+ → Admin Menu → Roles), which the
 * CRM already registers with; this is the CRM's own way in to it, because a
 * person setting up a CRM-only site looks for the option in the CRM, not in a
 * menu editor. Turning it on:
 *
 *  - opens the CRM's roles (CRM Manager, Sales Rep) to the CRM, and Administrators
 *    too when ticked: they land on it after login, and see a CRM-only menu;
 *  - gives administrators "Switch to WordPress" in the top bar (with the number of
 *    updates waiting), per person, so stepping out does not change what staff see;
 *  - optionally leaves the site with no public website (visitors go to the login
 *    screen, search engines are kept out, email links and forms keep working).
 *
 * Hidden menus are not permissions: the CRM's own roles carry only what they need.
 */
class FW_CRM_Admin_Mode {

	const WORKSPACE = 'crm';

	public static function init() {
		add_action( 'unysonplus_crm_settings_sections', array( __CLASS__, 'render' ), 1 );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/** @return bool Core has Workspaces (3.0.55+) and the CRM is registered with it. */
	public static function available() {
		return class_exists( 'FW_Workspaces' ) && FW_Workspaces::get( self::WORKSPACE );
	}

	/** @return bool Whether some role opens to the CRM. */
	public static function is_on() {
		return self::available() && FW_Workspaces::in_use( self::WORKSPACE );
	}

	/** @return bool Whether Administrators open to the CRM. */
	public static function for_admins() {
		$roles = FW_Workspaces::settings()['roles'];

		return self::WORKSPACE === ( $roles['administrator']['workspace'] ?? '' );
	}

	/**
	 * Turn CRM mode on (or update it).
	 *
	 * @param bool $admins     Administrators open to the CRM too.
	 * @param bool $no_website The site has no public website.
	 */
	public static function turn_on( $admins, $no_website ) {
		FW_Workspaces::setup( self::WORKSPACE, $admins ? array( 'administrator' ) : array() );

		if ( ! $admins && self::for_admins() ) {
			$s = FW_Workspaces::settings();
			unset( $s['roles']['administrator'] );
			FW_Workspaces::save( $s );
		}

		FW_Workspaces::set_private( $no_website );

		// Whoever turns it on sees the result at once, rather than their old switch-back choice.
		delete_user_meta( get_current_user_id(), FW_Workspaces::OFF_META );
	}

	/** Turn CRM mode off: every role back to WordPress, and the website public again. */
	public static function turn_off() {
		FW_Workspaces::teardown( self::WORKSPACE );
		FW_Workspaces::set_private( false );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'crm_mode_' ) ) {
			return $args;
		}
		// Changing what every role sees, and whether the site is public, is the site owner's call.
		if ( ! current_user_can( 'manage_options' ) || ! self::available() ) {
			wp_die( esc_html__( 'Only administrators can change CRM mode.', 'fw' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		if ( 'crm_mode_off' === $action ) {
			self::turn_off();
			FW_CRM_Admin_Page::flash( 'success', __( 'CRM mode is off. Everyone opens to WordPress again, and the website is public.', 'fw' ) );
		} else {
			$admins = ! empty( $_POST['admins'] );
			self::turn_on( $admins, ! empty( $_POST['no_website'] ) );
			FW_CRM_Admin_Page::flash( 'success', $admins
				? __( 'CRM mode is on. You open to the CRM from now on; "Switch to WordPress" in the top bar takes you to the full admin.', 'fw' )
				: __( 'CRM mode is on for the CRM\'s own roles.', 'fw' ) );
		}
		// phpcs:enable

		// Back to the CRM with the menu it now has.
		return array( 'tab' => 'settings' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$on      = self::is_on();
		$admins  = $on ? self::for_admins() : true;
		$private = $on ? FW_Workspaces::is_private() : true;
		?>
		<section class="fw-crm-panel fw-crm-mode" id="fw-crm-mode" aria-labelledby="fw-crm-h-mode">
			<header class="fw-crm-panel__head">
				<h2 id="fw-crm-h-mode"><?php esc_html_e( 'CRM mode', 'fw' ); ?></h2>
				<?php if ( self::available() ) : ?>
					<span class="fw-crm-status fw-crm-status--<?php echo $on ? 'subscribed' : 'draft'; ?>"><?php echo $on ? esc_html__( 'On', 'fw' ) : esc_html__( 'Off', 'fw' ); ?></span>
				<?php endif; ?>
			</header>
			<p class="fw-crm-muted"><?php esc_html_e( 'Use this site as a CRM only: people land on the CRM after signing in and see a menu with just the CRM and their profile, without posts, pages, themes or the rest of WordPress.', 'fw' ); ?></p>

			<?php if ( ! self::available() ) : ?>
				<p><?php esc_html_e( 'CRM mode needs Unyson+ 3.0.55 or later. Update the Unyson+ plugin to use it.', 'fw' ); ?></p>
			<?php else : ?>
				<form method="post" action="" class="fw-crm-form">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<label class="fw-crm-check"><input type="checkbox" name="admins" value="1" <?php checked( $admins ); ?> /> <?php esc_html_e( 'Administrators open to the CRM too', 'fw' ); ?></label>
					<p class="fw-crm-muted" style="margin-left:24px"><?php esc_html_e( 'Each administrator can still switch to the full WordPress admin from the top bar, and back. The switch is per person, and it shows how many updates are waiting.', 'fw' ); ?></p>
					<label class="fw-crm-check"><input type="checkbox" name="no_website" value="1" <?php checked( $private ); ?> /> <?php esc_html_e( 'No public website', 'fw' ); ?></label>
					<p class="fw-crm-muted" style="margin-left:24px"><?php esc_html_e( 'Visitors who open the site go to the login screen, and search engines are asked to keep out. Email links (confirm, unsubscribe), forms and the REST API keep working.', 'fw' ); ?></p>
					<p class="fw-crm-muted"><?php esc_html_e( 'The CRM Manager and Sales Rep roles always open to the CRM in CRM mode. Hiding the menu does not take any permission away; fine-tune each role\'s menu under Unyson+ → Admin Menu.', 'fw' ); ?></p>
					<p class="fw-crm-form__foot">
						<button type="submit" class="button button-primary" name="fw_crm_action" value="crm_mode_on"><?php echo $on ? esc_html__( 'Save', 'fw' ) : esc_html__( 'Turn on CRM mode', 'fw' ); ?></button>
						<?php if ( $on ) : ?>
							<button type="submit" class="button" name="fw_crm_action" value="crm_mode_off" onclick="return confirm('<?php echo esc_js( __( 'Everyone will open to the WordPress dashboard again, and the website will be public. Continue?', 'fw' ) ); ?>');"><?php esc_html_e( 'Turn off CRM mode', 'fw' ); ?></button>
						<?php endif; ?>
					</p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}
}
