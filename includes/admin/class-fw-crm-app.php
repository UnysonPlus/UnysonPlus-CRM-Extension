<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM app shell: the frame every CRM screen renders inside.
 *
 * The CRM is one admin page (`fw-crm`) whose `tab` picks the view. Around that
 * view this class draws the CRM's own navigation (a left sidebar with every area
 * of the CRM) and a top bar (title, search across contacts and companies, and a
 * "New" menu), so the CRM reads as an application rather than one settings page.
 *
 * Two things change the WordPress chrome around it, and only on CRM screens:
 *  - the WordPress admin menu folds to icons (the core `folded` body class), so the
 *    CRM sidebar gets the room; leaving the CRM gives it back;
 *  - focus mode (per user, toggled from the sidebar) hides the WordPress menu
 *    altogether, with "Back to WordPress" in the CRM sidebar as the way out.
 */
class FW_CRM_App {

	/** User meta: 1 while the person prefers the CRM without the WordPress menu. */
	const FOCUS_META = 'fw_crm_focus';

	/**
	 * Hook the body classes and the focus toggle. Called once from the admin page.
	 *
	 * @param string $hook_suffix The CRM page's hook suffix.
	 */
	public static function init( $hook_suffix ) {
		add_filter( 'admin_body_class', function ( $classes ) use ( $hook_suffix ) {
			$screen = get_current_screen();
			if ( ! $screen || $screen->id !== $hook_suffix ) {
				return $classes;
			}
			$classes .= ' fw-crm-screen folded';
			if ( self::is_focus() ) {
				$classes .= ' fw-crm-focus';
			}
			return $classes;
		} );
	}

	/** @internal admin-ajax: remember the focus-mode choice. */
	public static function _ajax_focus() {
		check_ajax_referer( 'fw_crm_focus' );
		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$on = ! empty( $_POST['on'] ) && '0' !== $_POST['on']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_user_meta( get_current_user_id(), self::FOCUS_META, $on ? 1 : 0 );
		wp_send_json_success( array( 'on' => $on ) );
	}

	/** @return bool */
	public static function is_focus() {
		return (bool) get_user_meta( get_current_user_id(), self::FOCUS_META, true );
	}

	/* ---------------------------------------------------------------------- *
	 * Navigation
	 * ---------------------------------------------------------------------- */

	/**
	 * The sidebar, in groups. `soon` marks an area that is planned but not built
	 * yet: it stays in the menu so the shape of the CRM is visible, and its screen
	 * says plainly what it will do.
	 *
	 * @return array group => { label, items: view => { label, icon, soon? } }
	 */
	public static function nav() {
		$nav = array(
			'main'      => array(
				'label' => '',
				'items' => array(
					'home'        => array( 'label' => __( 'Home', 'fw' ), 'icon' => 'dashicons-admin-home' ),
					'subscribers' => array( 'label' => __( 'Contacts', 'fw' ), 'icon' => 'dashicons-groups' ),
					'companies'   => array( 'label' => __( 'Companies', 'fw' ), 'icon' => 'dashicons-building', 'module' => 'companies' ),
					'deals'       => array( 'label' => __( 'Deals', 'fw' ), 'icon' => 'dashicons-money-alt', 'module' => 'pipeline' ),
					'tasks'       => array( 'label' => __( 'Tasks', 'fw' ), 'icon' => 'dashicons-yes-alt' ),
					'calendar'    => array( 'label' => __( 'Calendar', 'fw' ), 'icon' => 'dashicons-calendar-alt', 'module' => 'calendar' ),
				),
			),
			'marketing' => array(
				'label' => __( 'Marketing', 'fw' ),
				'items' => array(
					'campaigns' => array( 'label' => __( 'Campaigns', 'fw' ), 'icon' => 'dashicons-email-alt', 'cap' => FW_CRM_Capabilities::MANAGE, 'module' => 'email' ),
					'lists'     => array( 'label' => __( 'Lists & segments', 'fw' ), 'icon' => 'dashicons-tag', 'cap' => FW_CRM_Capabilities::MANAGE ),
				),
			),
			'insights'  => array(
				'label' => __( 'Insights', 'fw' ),
				'items' => array(
					'reports' => array( 'label' => __( 'Reports', 'fw' ), 'icon' => 'dashicons-chart-bar', 'module' => 'pipeline' ),
				),
			),
			'admin'     => array(
				'label' => __( 'Data', 'fw' ),
				'items' => array(
					'tools'    => array( 'label' => __( 'Import / Export', 'fw' ), 'icon' => 'dashicons-database-import', 'cap' => FW_CRM_Capabilities::MANAGE ),
					'fields'   => array( 'label' => __( 'Custom fields', 'fw' ), 'icon' => 'dashicons-feedback', 'cap' => FW_CRM_Capabilities::MANAGE ),
					'bin'      => array( 'label' => __( 'Bin', 'fw' ), 'icon' => 'dashicons-trash', 'cap' => FW_CRM_Capabilities::MANAGE ),
					'audit'    => array( 'label' => __( 'Audit log', 'fw' ), 'icon' => 'dashicons-shield', 'cap' => FW_CRM_Capabilities::MANAGE, 'module' => 'audit' ),
					'webhooks' => array( 'label' => __( 'Webhooks', 'fw' ), 'icon' => 'dashicons-rest-api', 'cap' => FW_CRM_Capabilities::MANAGE, 'module' => 'webhooks' ),
					'modules'  => array( 'label' => __( 'Modules', 'fw' ), 'icon' => 'dashicons-admin-plugins', 'cap' => FW_CRM_Capabilities::MANAGE ),
					'settings' => array( 'label' => __( 'Settings', 'fw' ), 'icon' => 'dashicons-admin-generic', 'cap' => FW_CRM_Capabilities::MANAGE ),
				),
			),
		);

		/**
		 * Filters the CRM sidebar. Modules and companion extensions (Membership, for
		 * one) add their areas here: group => { label, items: view => { label, icon,
		 * url?, soon?, cap? } }. An item with `cap` shows only to people holding it.
		 */
		return (array) apply_filters( 'unysonplus_crm_nav', $nav );
	}

	/**
	 * May the current person open this view?
	 *
	 * @param string $view
	 *
	 * @return bool
	 */
	public static function can_view( $view ) {
		$item = self::item( $view );

		return ! $item || empty( $item['cap'] ) || current_user_can( $item['cap'] );
	}

	/**
	 * Every view slug the sidebar knows, so the admin page can accept them.
	 *
	 * @return string[]
	 */
	public static function views() {
		$views = array( 'search' );
		foreach ( self::nav() as $group ) {
			$views = array_merge( $views, array_keys( (array) $group['items'] ) );
		}
		return $views;
	}

	/**
	 * @param string $view
	 *
	 * @return array|null The nav item.
	 */
	public static function item( $view ) {
		foreach ( self::nav() as $group ) {
			if ( isset( $group['items'][ $view ] ) ) {
				return $group['items'][ $view ];
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------- *
	 * Frame
	 * ---------------------------------------------------------------------- */

	/**
	 * Open the frame: sidebar, top bar, then the content column (left open).
	 *
	 * @param string $view   The active view (sidebar highlight).
	 * @param array  $crumbs [ [ label, url? ], … ] — the last one is the page title.
	 * @param string $actions Extra top-bar HTML (already escaped), right of search.
	 */
	public static function open( $view, array $crumbs, $actions = '' ) {
		$base  = FW_CRM_Admin_Page::get_page_url();
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$focus = self::is_focus();
		?>
		<div class="fw-crm-app" id="fw-crm-app"
		     data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		     data-focus-nonce="<?php echo esc_attr( wp_create_nonce( 'fw_crm_focus' ) ); ?>">

			<nav class="fw-crm-side" id="fw-crm-side" aria-label="<?php esc_attr_e( 'CRM', 'fw' ); ?>">
				<a class="fw-crm-side__brand" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'home' ) ); ?>">
					<span class="fw-crm-side__mark" aria-hidden="true">
						<span class="dashicons dashicons-id-alt"></span>
					</span>
					<span class="fw-crm-side__name"><?php esc_html_e( 'CRM', 'fw' ); ?></span>
				</a>

				<div class="fw-crm-side__groups">
					<?php
					foreach ( self::nav() as $group_key => $group ) :
						$group['items'] = array_filter( (array) $group['items'], function ( $item ) {
							return ( empty( $item['cap'] ) || current_user_can( $item['cap'] ) )
								&& ( empty( $item['module'] ) || FW_CRM_Modules::enabled( $item['module'] ) );
						} );
						if ( ! $group['items'] ) {
							continue;
						}
						?>
						<div class="fw-crm-side__group">
							<?php if ( '' !== (string) $group['label'] ) : ?>
								<p class="fw-crm-side__label" id="fw-crm-nav-<?php echo esc_attr( $group_key ); ?>"><?php echo esc_html( $group['label'] ); ?></p>
							<?php endif; ?>
							<ul<?php echo '' !== (string) $group['label'] ? ' aria-labelledby="fw-crm-nav-' . esc_attr( $group_key ) . '"' : ''; ?>>
								<?php foreach ( $group['items'] as $slug => $item ) : ?>
									<?php $here = $slug === $view; ?>
									<li>
										<a class="fw-crm-side__link<?php echo $here ? ' is-active' : ''; ?><?php echo ! empty( $item['soon'] ) ? ' is-soon' : ''; ?>"
										   href="<?php echo esc_url( ! empty( $item['url'] ) ? $item['url'] : add_query_arg( 'tab', $slug, $base ) ); ?>"
											<?php echo $here ? 'aria-current="page"' : ''; ?>>
											<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
											<span class="fw-crm-side__text"><?php echo esc_html( $item['label'] ); ?></span>
											<?php if ( ! empty( $item['soon'] ) ) : ?>
												<span class="fw-crm-side__soon"><?php esc_html_e( 'Soon', 'fw' ); ?></span>
											<?php endif; ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="fw-crm-side__foot">
					<button type="button" class="fw-crm-side__link fw-crm-focus-toggle" aria-pressed="<?php echo $focus ? 'true' : 'false'; ?>"
					        data-on="<?php esc_attr_e( 'Show the WordPress menu', 'fw' ); ?>"
					        data-off="<?php esc_attr_e( 'Hide the WordPress menu', 'fw' ); ?>">
						<span class="dashicons dashicons-editor-contract" aria-hidden="true"></span>
						<span class="fw-crm-side__text"><?php echo $focus ? esc_html__( 'Show the WordPress menu', 'fw' ) : esc_html__( 'Hide the WordPress menu', 'fw' ); ?></span>
					</button>
					<a class="fw-crm-side__link fw-crm-side__back" href="<?php echo esc_url( admin_url() ); ?>">
						<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
						<span class="fw-crm-side__text"><?php esc_html_e( 'Back to WordPress', 'fw' ); ?></span>
					</a>
				</div>
			</nav>

			<div class="fw-crm-main">
				<header class="fw-crm-top">
					<button type="button" class="fw-crm-top__menu" aria-controls="fw-crm-side" aria-expanded="false">
						<span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'CRM menu', 'fw' ); ?></span>
					</button>

					<div class="fw-crm-top__title">
						<?php
						$last = count( $crumbs ) - 1;
						if ( $last > 0 ) :
							?>
							<ol class="fw-crm-crumbs">
								<?php foreach ( array_slice( $crumbs, 0, $last ) as $crumb ) : ?>
									<li><a href="<?php echo esc_url( $crumb[1] ); ?>"><?php echo esc_html( $crumb[0] ); ?></a></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
						<h1><?php echo esc_html( $crumbs[ $last ][0] ); ?></h1>
					</div>

					<form class="fw-crm-search" role="search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
						<input type="hidden" name="tab" value="search" />
						<label class="screen-reader-text" for="fw-crm-q"><?php echo FW_CRM_Modules::enabled( 'companies' ) ? esc_html__( 'Search contacts and companies', 'fw' ) : esc_html__( 'Search contacts', 'fw' ); ?></label>
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input type="search" id="fw-crm-q" name="q" value="<?php echo esc_attr( $query ); ?>"
						       placeholder="<?php echo FW_CRM_Modules::enabled( 'companies' ) ? esc_attr__( 'Search contacts and companies', 'fw' ) : esc_attr__( 'Search contacts', 'fw' ); ?>" autocomplete="off" />
						<kbd aria-hidden="true">/</kbd>
					</form>

					<div class="fw-crm-top__actions">
						<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller. ?>
						<details class="fw-crm-new">
							<summary class="button button-primary">
								<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
								<?php esc_html_e( 'New', 'fw' ); ?>
							</summary>
							<ul class="fw-crm-new__menu">
								<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php esc_html_e( 'Contact', 'fw' ); ?></a></li>
								<?php if ( FW_CRM_Modules::enabled( 'companies' ) ) : ?>
									<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'companies', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-building" aria-hidden="true"></span><?php esc_html_e( 'Company', 'fw' ); ?></a></li>
								<?php endif; ?>
								<?php if ( class_exists( 'FW_CRM_Deals' ) ) : ?>
									<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span><?php esc_html_e( 'Deal', 'fw' ); ?></a></li>
								<?php endif; ?>
								<li><a href="<?php echo esc_url( add_query_arg( 'tab', 'tasks', $base ) ); ?>"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Task', 'fw' ); ?></a></li>
								<?php if ( FW_CRM_Capabilities::can_manage() ) : ?>
									<?php if ( FW_CRM_Modules::enabled( 'email' ) ) : ?>
										<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'campaigns', 'new' => 1 ), $base ) ); ?>"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><?php esc_html_e( 'Email campaign', 'fw' ); ?></a></li>
									<?php endif; ?>
									<li><a href="<?php echo esc_url( add_query_arg( 'tab', 'tools', $base ) ); ?>"><span class="dashicons dashicons-database-import" aria-hidden="true"></span><?php esc_html_e( 'Import from CSV', 'fw' ); ?></a></li>
								<?php endif; ?>
							</ul>
						</details>
					</div>
				</header>

				<div class="wrap fw-ext-crm fw-crm-body">
					<hr class="wp-header-end" />
		<?php
	}

	/** Close the frame opened by open(). */
	public static function close() {
		?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Small shared pieces
	 * ---------------------------------------------------------------------- */

	/**
	 * Initials in a tinted circle; the hue is derived from the name so a person
	 * keeps their colour everywhere.
	 *
	 * @param string $name
	 * @param int    $size px
	 * @param string $shape round|square (companies are square)
	 *
	 * @return string
	 */
	public static function avatar( $name, $size = 32, $shape = 'round' ) {
		$name  = trim( (string) $name );
		$parts = preg_split( '/[\s@._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		$ini   = '';
		foreach ( array_slice( (array) $parts, 0, 2 ) as $p ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 );
		}
		$ini = '' !== $ini ? strtoupper( $ini ) : '?';
		$hue = hexdec( substr( md5( strtolower( $name ) ), 0, 4 ) ) % 360;

		return sprintf(
			'<span class="fw-crm-avatar fw-crm-avatar--%s" style="--s:%dpx;--h:%d" aria-hidden="true">%s</span>',
			'square' === $shape ? 'square' : 'round',
			(int) $size,
			(int) $hue,
			esc_html( $ini )
		);
	}

	/**
	 * "Managed by Membership" for a managed_by slug. Extensions can name themselves
	 * with the `unysonplus_crm_managed_by_label` filter.
	 *
	 * @param string $slug
	 * @param bool   $phrase Whole phrase ("Managed by X") or just the name.
	 *
	 * @return string
	 */
	public static function managed_label( $slug, $phrase = true ) {
		$name = ucwords( str_replace( array( '-', '_' ), ' ', (string) $slug ) );
		if ( function_exists( 'fw_ext' ) && fw_ext( (string) $slug ) ) {
			$name = fw_ext( (string) $slug )->manifest->get_name();
		}
		/** Filters the display name of an extension that manages CRM tags, lists or fields. */
		$name = (string) apply_filters( 'unysonplus_crm_managed_by_label', $name, (string) $slug );

		return $phrase ? sprintf( __( 'Managed by %s', 'fw' ), $name ) : $name;
	}

	/**
	 * Extension panels for a record page, from the `unysonplus_crm_{object}_panels`
	 * filter: id => { label, callback( $record ), priority (default 50), column
	 * 'main' (under the timeline) or 'side' (default) }.
	 *
	 * @param string $object contact|company
	 * @param mixed  $record What the callback receives: the contact array or the company row.
	 * @param string $column main|side
	 */
	public static function render_panels( $object, $record, $column ) {
		/**
		 * Filters the extra panels on a contact page (unysonplus_crm_contact_panels) or a
		 * company page (unysonplus_crm_company_panels).
		 */
		$panels = (array) apply_filters( 'unysonplus_crm_' . $object . '_panels', array(), $record );
		uasort( $panels, function ( $a, $b ) {
			return (int) ( $a['priority'] ?? 50 ) <=> (int) ( $b['priority'] ?? 50 );
		} );
		foreach ( $panels as $id => $panel ) {
			if ( ( $panel['column'] ?? 'side' ) !== $column || empty( $panel['callback'] ) || ! is_callable( $panel['callback'] ) ) {
				continue;
			}
			$hid = 'fw-crm-panel-' . sanitize_html_class( (string) $id );
			?>
			<section class="fw-crm-panel fw-crm-ext-panel" id="<?php echo esc_attr( $hid ); ?>" aria-labelledby="<?php echo esc_attr( $hid ); ?>-h">
				<header class="fw-crm-panel__head">
					<h2 id="<?php echo esc_attr( $hid ); ?>-h"><?php echo esc_html( (string) ( $panel['label'] ?? $id ) ); ?></h2>
				</header>
				<?php call_user_func( $panel['callback'], $record ); ?>
			</section>
			<?php
		}
	}

	/**
	 * @param string $stage
	 *
	 * @return string
	 */
	public static function stage_pill( $stage ) {
		$stages = FW_CRM_Contacts::stages();
		if ( '' === $stage || ! isset( $stages[ $stage ] ) ) {
			return '<span aria-hidden="true">—</span>';
		}

		return sprintf( '<span class="fw-crm-stage fw-crm-stage--%1$s">%2$s</span>', esc_attr( $stage ), esc_html( $stages[ $stage ] ) );
	}

	/**
	 * "3 hours ago", with the exact time on hover.
	 *
	 * @param string|null $mysql A local MySQL datetime.
	 *
	 * @return string
	 */
	public static function time_ago( $mysql ) {
		$time = $mysql ? strtotime( (string) $mysql ) : 0;
		if ( ! $time || $time < 86400 ) {
			return '<span aria-hidden="true">—</span>';
		}
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- compared with a local datetime.
		$text = $now - $time < 45
			? __( 'just now', 'fw' )
			: sprintf( __( '%s ago', 'fw' ), human_time_diff( $time, $now ) );

		return sprintf( '<time datetime="%s" title="%s">%s</time>', esc_attr( date( 'c', $time ) ), esc_attr( date_i18n( 'Y-m-d H:i', $time ) ), esc_html( $text ) );
	}

	/**
	 * A teaching empty state: what this place is for and the first useful step.
	 *
	 * @param string $icon    A dashicons class.
	 * @param string $title
	 * @param string $text
	 * @param array  $actions [ [ label, url, primary? ], … ]
	 */
	public static function empty_state( $icon, $title, $text, array $actions = array() ) {
		?>
		<div class="fw-crm-empty">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $text ); ?></p>
			<?php if ( $actions ) : ?>
				<p class="fw-crm-empty__actions">
					<?php foreach ( $actions as $a ) : ?>
						<a class="button<?php echo ! empty( $a[2] ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $a[1] ); ?>"><?php echo esc_html( $a[0] ); ?></a>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The screen for an area that is planned but not built yet.
	 *
	 * @param string $view
	 */
	public static function render_soon( $view ) {
		$about = array(
			'deals'    => __( 'Track sales as deals moving through the stages of a pipeline: a board you drag cards across, the value and expected close date of each, and the contacts and companies involved.', 'fw' ),
			'tasks'    => __( 'Calls, follow-ups and to-dos with due dates, assigned to a person and attached to a contact, company or deal, with reminders when they are due.', 'fw' ),
			'calendar' => __( 'Your tasks, meetings and scheduled campaigns on one calendar, by day, week or month.', 'fw' ),
			'reports'  => __( 'Charts of how contacts, deals and campaigns are doing over time: new contacts by source, the pipeline by stage, and email results.', 'fw' ),
		);
		$item = self::item( $view );

		self::empty_state(
			$item ? $item['icon'] : 'dashicons-clock',
			sprintf( __( '%s is on the way', 'fw' ), $item ? $item['label'] : '' ),
			$about[ $view ] ?? __( 'This part of the CRM is planned for a later update.', 'fw' ),
			array( array( __( 'See the CRM roadmap', 'fw' ), 'https://docs.unysonplus.com/crm/roadmap' ) )
		);
	}
}
