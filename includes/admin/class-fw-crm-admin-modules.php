<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Modules (managers): switch the optional parts of the CRM on and off, and
 * the first-run question on Home ("What will you use the CRM for?").
 */
class FW_CRM_Admin_Modules {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'modules' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || ! in_array( $action, array( 'save_modules', 'setup_modules' ), true ) ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		if ( 'setup_modules' === $action ) {
			$uses   = isset( $_POST['uses'] ) && ! isset( $_POST['skip'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['uses'] ) ) : array();
			$errors = FW_CRM_Modules::setup( $uses );
			FW_CRM_Admin_Page::flash( $errors ? 'error' : 'success', $errors ? implode( ' ', $errors ) : __( 'Your CRM is set up. You can switch modules on or off at any time under Modules.', 'fw' ) );
			return array( 'tab' => 'home' );
		}
		$on     = isset( $_POST['modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) : array();
		// phpcs:enable
		$errors = FW_CRM_Modules::save( $on );
		FW_CRM_Admin_Page::flash( $errors ? 'error' : 'success', $errors ? implode( ' ', $errors ) : __( 'Modules saved.', 'fw' ) );
		return array( 'tab' => 'modules' );
	}

	public static function render() {
		$all    = FW_CRM_Modules::all();
		$groups = FW_CRM_Modules::groups();
		$by     = array();
		foreach ( $all as $id => $m ) {
			$g          = isset( $groups[ $m['group'] ] ) ? $m['group'] : 'connect';
			$by[ $g ][ $id ] = $m;
		}
		?>
		<p class="fw-crm-status-line"><?php esc_html_e( 'The core (contacts, notes, tags, lists and segments, tasks, files, import and export, custom fields and the bin) is always on. Switch on the modules your site needs; a module that is off loads no code and adds no menus, and its data stays until you switch it back on.', 'fw' ); ?></p>
		<form method="post" action="" class="fw-crm-modules-form">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="save_modules" />
			<?php foreach ( $groups as $gid => $glabel ) : ?>
				<?php
				if ( empty( $by[ $gid ] ) ) {
					continue;
				}
				?>
				<h2 class="fw-crm-modules__group" id="fw-crm-mg-<?php echo esc_attr( $gid ); ?>"><?php echo esc_html( $glabel ); ?></h2>
				<ul class="fw-crm-modules" role="list" aria-labelledby="fw-crm-mg-<?php echo esc_attr( $gid ); ?>">
					<?php foreach ( $by[ $gid ] as $mid => $m ) : ?>
						<?php
						$available = FW_CRM_Modules::available( $mid );
						$on        = FW_CRM_Modules::enabled( $mid );
						$needs     = FW_CRM_Modules::requires_text( $m );
						$id        = 'fw-crm-mod-' . $mid;
						?>
						<li class="fw-crm-module<?php echo $on ? ' is-on' : ''; ?><?php echo ! $available ? ' is-soon' : ''; ?>">
							<span class="dashicons <?php echo esc_attr( $m['icon'] ); ?>" aria-hidden="true"></span>
							<div class="fw-crm-module__text">
								<label for="<?php echo esc_attr( $id ); ?>" class="fw-crm-module__name"><?php echo esc_html( $m['label'] ); ?></label>
								<p><?php echo esc_html( $m['description'] ); ?></p>
								<?php if ( '' !== $needs ) : ?>
									<p class="fw-crm-muted"><?php echo esc_html( $needs ); ?></p>
								<?php endif; ?>
							</div>
							<div class="fw-crm-module__switch">
								<?php if ( ! empty( $m['soon'] ) ) : ?>
									<span class="fw-crm-module__soon"><?php esc_html_e( 'Coming soon', 'fw' ); ?></span>
								<?php elseif ( ! $available ) : ?>
									<span class="fw-crm-module__soon"><?php esc_html_e( 'Not available', 'fw' ); ?></span>
								<?php else : ?>
									<input type="checkbox" class="fw-crm-toggle" role="switch" id="<?php echo esc_attr( $id ); ?>" name="modules[]" value="<?php echo esc_attr( $mid ); ?>" <?php checked( $on ); ?> />
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save modules', 'fw' ); ?></button></p>
		</form>
		<?php
		/** Fires below the module switches (the integrations registry lists connected extensions here). */
		do_action( 'unysonplus_crm_modules_after' );
		?>
		<p class="fw-crm-muted">
			<?php
			printf(
				/* translators: %s: link to the developer docs */
				esc_html__( 'Developers can add their own module to this screen. %s', 'fw' ),
				'<a href="https://docs.unysonplus.com/crm/developers/custom-modules" target="_blank" rel="noopener">' . esc_html__( 'How to build a CRM module', 'fw' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * The first-run question, shown on Home until a manager answers or skips it.
	 */
	public static function render_setup() {
		$user = wp_get_current_user();
		?>
		<section class="fw-crm-panel fw-crm-setup" aria-labelledby="fw-crm-h-setup">
			<header class="fw-crm-setup__head">
				<h2 id="fw-crm-h-setup"><?php echo esc_html( sprintf( __( 'Welcome, %s. What will you use the CRM for?', 'fw' ), $user->display_name ) ); ?></h2>
				<p class="fw-crm-muted"><?php esc_html_e( 'Pick everything that fits. The CRM switches on only what you need, so it stays simple. You can change this later under Modules.', 'fw' ); ?></p>
			</header>
			<form method="post" action="">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="setup_modules" />
				<fieldset class="fw-crm-setup__uses">
					<legend class="screen-reader-text"><?php esc_html_e( 'What you will use the CRM for', 'fw' ); ?></legend>
					<?php foreach ( FW_CRM_Modules::uses() as $uid => $use ) : ?>
						<label class="fw-crm-setup__use">
							<input type="checkbox" name="uses[]" value="<?php echo esc_attr( $uid ); ?>" <?php checked( 'people' === $uid ); ?> />
							<span class="dashicons <?php echo esc_attr( $use['icon'] ); ?>" aria-hidden="true"></span>
							<span class="fw-crm-setup__text">
								<strong><?php echo esc_html( $use['label'] ); ?></strong>
								<span><?php echo esc_html( $use['description'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="fw-crm-setup__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Set up my CRM', 'fw' ); ?></button>
					<button type="submit" name="skip" value="1" class="button-link"><?php esc_html_e( 'Skip, keep it simple', 'fw' ); ?></button>
				</p>
			</form>
		</section>
		<?php
	}
}
