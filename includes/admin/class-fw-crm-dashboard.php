<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Home: where things stand, and what happened lately.
 *
 * Opens with one status line (how many contacts, what is new, what is sending),
 * then the contacts by lifecycle stage, the activity feed across every record,
 * the newest contacts and the email side. An empty CRM shows the three ways
 * people arrive instead.
 */
class FW_CRM_Dashboard {

	public static function init() {
		add_action( 'wp_ajax_fw_crm_home_layout', array( __CLASS__, '_ajax_layout' ) );
		add_action( 'admin_enqueue_scripts', function ( $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home';
			if ( false === strpos( (string) $hook, 'fw-crm' ) || 'home' !== $tab ) {
				return;
			}
			$ext = fw_ext( 'crm' );
			wp_enqueue_script( 'fw-crm-home', $ext->get_uri( '/static/js/home.js' ), array(), $ext->manifest->get_version(), true );
		} );
	}

	public static function render() {
		$base     = FW_CRM_Admin_Page::get_page_url();
		$owner    = FW_CRM_Capabilities::owner_scope(); // A sales rep's Home is about their own records.
		$contacts = FW_CRM_Subscribers::count( FW_CRM_Capabilities::scope( array() ) );
		$month    = FW_CRM_Subscribers::count( FW_CRM_Capabilities::scope( array( 'created_after' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$status   = FW_CRM_Subscribers::counts_by_status( $owner );
		$stages   = FW_CRM_Subscribers::counts_by_stage( $owner );
		$companies = FW_CRM_Companies::count( FW_CRM_Capabilities::scope( array() ) );
		$sending  = class_exists( 'FW_CRM_Campaigns' ) ? FW_CRM_Campaigns::all( array( 'status' => 'sending' ) ) : array();
		$user     = wp_get_current_user();

		if ( FW_CRM_Modules::needs_setup() && class_exists( 'FW_CRM_Admin_Modules' ) ) {
			FW_CRM_Admin_Modules::render_setup();
			return;
		}
		if ( ! $contacts && ! $companies && FW_CRM_Capabilities::sees_all() ) {
			self::render_welcome( $user );
			return;
		}
		?>
		<p class="fw-crm-status-line">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: number of contacts, 2: new in the last 30 days */
				_n( '%1$s contact, %2$s new in the last 30 days.', '%1$s contacts, %2$s new in the last 30 days.', $contacts, 'fw' ),
				number_format_i18n( $contacts ),
				number_format_i18n( $month )
			) );
			if ( $sending ) {
				echo ' ' . esc_html( sprintf( _n( '%s campaign is sending now.', '%s campaigns are sending now.', count( $sending ), 'fw' ), number_format_i18n( count( $sending ) ) ) );
			}
			?>
		</p>

		<div class="fw-crm-stats" role="list">
			<?php
			$stats = array(
				array( __( 'Contacts', 'fw' ), $contacts, add_query_arg( 'tab', 'subscribers', $base ), sprintf( __( '+%s this month', 'fw' ), number_format_i18n( $month ) ) ),
				FW_CRM_Modules::enabled( 'companies' ) ? array( __( 'Companies', 'fw' ), $companies, add_query_arg( 'tab', 'companies', $base ), '' ) : null,
				array( __( 'Subscribed to email', 'fw' ), (int) ( $status['subscribed'] ?? 0 ), add_query_arg( array( 'tab' => 'subscribers', 'status' => 'subscribed' ), $base ), (int) ( $status['pending'] ?? 0 ) ? sprintf( __( '%s waiting to confirm', 'fw' ), number_format_i18n( (int) $status['pending'] ) ) : '' ),
				array( __( 'Customers', 'fw' ), (int) ( $stages['customer'] ?? 0 ), add_query_arg( array( 'tab' => 'subscribers', 'stage' => 'customer' ), $base ), '' ),
			);
			foreach ( array_filter( $stats ) as $s ) :
				?>
				<a class="fw-crm-stat" role="listitem" href="<?php echo esc_url( $s[2] ); ?>">
					<span class="fw-crm-stat__label"><?php echo esc_html( $s[0] ); ?></span>
					<span class="fw-crm-stat__value"><?php echo esc_html( number_format_i18n( $s[1] ) ); ?></span>
					<?php if ( '' !== $s[3] ) : ?>
						<span class="fw-crm-stat__note"><?php echo esc_html( $s[3] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<?php self::render_grid( $base, $stages, $contacts ); ?>
		<?php
	}

	const LAYOUT_META = 'fw_crm_home_layout';

	/**
	 * Every card Home can show: the built-in ones and those added through the
	 * `unysonplus_crm_dashboard_cards` filter.
	 *
	 * @param string $base
	 * @param array  $stages
	 * @param int    $contacts
	 *
	 * @return array id => { title, column, priority, render (callable that prints the whole card) }
	 */
	private static function cards( $base, array $stages, $contacts ) {
		$cards = array(
			'stages'   => array(
				'title'    => __( 'Contacts by stage', 'fw' ),
				'column'   => 'main',
				'priority' => 10,
				'render'   => function () use ( $stages, $contacts, $base ) {
					?>
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-stages">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-stages"><?php esc_html_e( 'Contacts by stage', 'fw' ); ?></h2>
						</header>
						<?php self::render_stages( $stages, $contacts, $base ); ?>
					</section>
					<?php
				},
			),
			'feed'     => array(
				'title'    => __( 'Recent activity', 'fw' ),
				'column'   => 'main',
				'priority' => 30,
				'render'   => function () {
					?>
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-feed">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-feed"><?php esc_html_e( 'Recent activity', 'fw' ); ?></h2>
						</header>
						<?php self::render_feed( FW_CRM_Activity::recent( 12, FW_CRM_Capabilities::owner_scope() ) ); ?>
					</section>
					<?php
				},
			),
			'tasks'    => array(
				'title'    => __( 'Your tasks', 'fw' ),
				'column'   => 'side',
				'priority' => 10,
				'render'   => function () use ( $base ) {
					self::render_tasks_card( $base );
				},
			),
			'newest'   => array(
				'title'    => __( 'Newest contacts', 'fw' ),
				'column'   => 'side',
				'priority' => 20,
				'render'   => function () use ( $base ) {
					self::render_newest( $base );
				},
			),
		);
		if ( class_exists( 'FW_CRM_Deals' ) ) {
			$cards['pipeline'] = array(
				'title'    => __( 'Pipeline', 'fw' ),
				'column'   => 'main',
				'priority' => 20,
				'render'   => function () use ( $base ) {
					self::render_pipeline_card( $base );
				},
			);
		}
		if ( FW_CRM_Capabilities::can_manage() && FW_CRM_Modules::enabled( 'email' ) ) {
			$cards['campaigns'] = array(
				'title'    => __( 'Email campaigns', 'fw' ),
				'column'   => 'side',
				'priority' => 30,
				'render'   => function () use ( $base ) {
					?>
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-email">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-email"><?php esc_html_e( 'Email campaigns', 'fw' ); ?></h2>
							<a href="<?php echo esc_url( add_query_arg( 'tab', 'campaigns', $base ) ); ?>"><?php esc_html_e( 'View all', 'fw' ); ?></a>
						</header>
						<?php self::render_campaigns( $base ); ?>
					</section>
					<?php
				},
			);
		}

		/**
		 * Filters the cards on the CRM home screen.
		 *
		 * @param array $cards id => { title, callback(), priority (default 50), column 'main' or 'side' (default) }
		 */
		foreach ( (array) apply_filters( 'unysonplus_crm_dashboard_cards', array() ) as $id => $card ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || isset( $cards[ $id ] ) || empty( $card['callback'] ) || ! is_callable( $card['callback'] ) ) {
				continue;
			}
			$title        = (string) ( $card['title'] ?? $id );
			$callback     = $card['callback'];
			$cards[ $id ] = array(
				'title'    => $title,
				'column'   => 'main' === ( $card['column'] ?? 'side' ) ? 'main' : 'side',
				'priority' => (int) ( $card['priority'] ?? 50 ),
				'render'   => function () use ( $id, $title, $callback ) {
					$hid = 'fw-crm-card-' . sanitize_html_class( $id );
					?>
					<section class="fw-crm-panel" aria-labelledby="<?php echo esc_attr( $hid ); ?>">
						<header class="fw-crm-panel__head">
							<h2 id="<?php echo esc_attr( $hid ); ?>"><?php echo esc_html( $title ); ?></h2>
						</header>
						<?php call_user_func( $callback ); ?>
					</section>
					<?php
				},
			);
		}
		return $cards;
	}

	/**
	 * Where each card goes for this person: their saved layout first, then any card
	 * they have not placed yet (a new one) in its own column by priority.
	 *
	 * @param array $cards
	 *
	 * @return array { main: ids, side: ids, hidden: ids }
	 */
	public static function layout( array $cards ) {
		$saved = get_user_meta( get_current_user_id(), self::LAYOUT_META, true );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array( 'main' => array(), 'side' => array(), 'hidden' => array() );
		$seen  = array();
		foreach ( array( 'main', 'side', 'hidden' ) as $col ) {
			foreach ( (array) ( $saved[ $col ] ?? array() ) as $id ) {
				$id = (string) $id;
				if ( isset( $cards[ $id ] ) && ! isset( $seen[ $id ] ) ) {
					$out[ $col ][] = $id;
					$seen[ $id ]   = true;
				}
			}
		}
		$rest = array_diff_key( $cards, $seen );
		uasort( $rest, function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		} );
		foreach ( $rest as $id => $card ) {
			$out[ $card['column'] ][] = (string) $id;
		}
		return $out;
	}

	/**
	 * Save a person's layout (admin-ajax `fw_crm_home_layout`).
	 *
	 * @internal
	 */
	public static function _ajax_layout() {
		check_ajax_referer( 'fw_crm_home_layout' );
		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( ! empty( $_POST['reset'] ) ) {
			delete_user_meta( get_current_user_id(), self::LAYOUT_META );
			wp_send_json_success();
		}
		$layout = array();
		foreach ( array( 'main', 'side', 'hidden' ) as $col ) {
			$ids            = isset( $_POST[ $col ] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST[ $col ] ) ) ) : array();
			$layout[ $col ] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $ids ) ) ) );
		}
		update_user_meta( get_current_user_id(), self::LAYOUT_META, $layout );
		wp_send_json_success( $layout );
	}

	/**
	 * The two columns of cards, and the controls to rearrange them.
	 *
	 * @param string $base
	 * @param array  $stages
	 * @param int    $contacts
	 */
	private static function render_grid( $base, array $stages, $contacts ) {
		$cards  = self::cards( $base, $stages, $contacts );
		$layout = self::layout( $cards );
		$i18n   = array(
			'up'      => __( 'Move up', 'fw' ),
			'down'    => __( 'Move down', 'fw' ),
			'toMain'  => __( 'Move to the wide column', 'fw' ),
			'toSide'  => __( 'Move to the narrow column', 'fw' ),
			'hide'    => __( 'Hide', 'fw' ),
			'saved'   => __( 'Layout saved.', 'fw' ),
			'failed'  => __( 'The layout was not saved. Try again.', 'fw' ),
			'moved'   => __( '%s moved.', 'fw' ),
			'hidden'  => __( '%s hidden.', 'fw' ),
		);
		?>
		<div class="fw-crm-home-tools">
			<button type="button" class="button" data-home-customise aria-pressed="false"><span class="dashicons dashicons-layout" aria-hidden="true"></span> <?php esc_html_e( 'Customise Home', 'fw' ); ?></button>
		</div>
		<div class="fw-crm-home-edit" data-home-edit hidden>
			<p><?php esc_html_e( 'Drag cards by their handle, or use the buttons on each card. Changes are saved as you go and only change your own Home.', 'fw' ); ?></p>
			<div class="fw-crm-home-edit__tray">
				<span class="fw-crm-home-edit__label"><?php esc_html_e( 'Hidden cards:', 'fw' ); ?></span>
				<?php if ( $layout['hidden'] ) : ?>
					<?php foreach ( $layout['hidden'] as $id ) : ?>
						<button type="button" class="button button-small" data-home-show="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( sprintf( __( 'Show %s', 'fw' ), $cards[ $id ]['title'] ) ); ?></button>
					<?php endforeach; ?>
				<?php else : ?>
					<span class="fw-crm-muted"><?php esc_html_e( 'none', 'fw' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="fw-crm-home-edit__foot">
				<button type="button" class="button button-primary" data-home-done><?php esc_html_e( 'Done', 'fw' ); ?></button>
				<button type="button" class="button-link" data-home-reset><?php esc_html_e( 'Reset to the default layout', 'fw' ); ?></button>
			</p>
		</div>
		<div class="fw-crm-home" id="fw-crm-home" data-hidden="<?php echo esc_attr( implode( ',', $layout['hidden'] ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'fw_crm_home_layout' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">
			<?php foreach ( array( 'main', 'side' ) as $col ) : ?>
				<div class="fw-crm-home__<?php echo esc_attr( $col ); ?>" data-home-col="<?php echo esc_attr( $col ); ?>">
					<?php foreach ( $layout[ $col ] as $id ) : ?>
						<div class="fw-crm-card" data-card="<?php echo esc_attr( $id ); ?>" data-title="<?php echo esc_attr( $cards[ $id ]['title'] ); ?>">
							<?php call_user_func( $cards[ $id ]['render'] ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			<p class="screen-reader-text" aria-live="polite" data-home-live></p>
		</div>
		<?php
	}

	/**
	 * @param string $base
	 */
	private static function render_newest( $base ) {
		?>
		<section class="fw-crm-panel" aria-labelledby="fw-crm-h-new">
			<header class="fw-crm-panel__head">
				<h2 id="fw-crm-h-new"><?php esc_html_e( 'Newest contacts', 'fw' ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'subscribers', $base ) ); ?>"><?php esc_html_e( 'View all', 'fw' ); ?></a>
			</header>
			<ul class="fw-crm-people">
				<?php foreach ( FW_CRM_Subscribers::query( FW_CRM_Capabilities::scope( array( 'per_page' => 6 ) ) ) as $row ) : ?>
					<?php $name = trim( $row->first_name . ' ' . $row->last_name ); ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $row->id ), $base ) ); ?>">
							<?php echo FW_CRM_App::avatar( '' !== $name ? $name : (string) $row->email, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="fw-crm-people__text">
								<span class="fw-crm-people__name"><?php echo esc_html( '' !== $name ? $name : ( '' !== (string) $row->email ? $row->email : $row->phone ) ); ?></span>
								<span class="fw-crm-people__sub"><?php echo '' !== $name ? esc_html( (string) $row->email ) : ''; ?></span>
							</span>
							<?php echo FW_CRM_App::time_ago( $row->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * First run: nothing stored yet, so show how contacts arrive.
	 *
	 * @param WP_User $user
	 */
	private static function render_welcome( $user ) {
		$base  = FW_CRM_Admin_Page::get_page_url();
		$steps = array(
			array( 'dashicons-admin-users', __( 'Add someone by hand', 'fw' ), __( 'Type in a contact you already know: name, email, phone, the company they work at.', 'fw' ), __( 'New contact', 'fw' ), add_query_arg( array( 'tab' => 'subscribers', 'new' => 1 ), $base ) ),
			array( 'dashicons-database-import', __( 'Bring in a list', 'fw' ), __( 'Import a CSV from a spreadsheet or another tool. You match its columns to CRM fields before anything is saved.', 'fw' ), __( 'Import a CSV', 'fw' ), add_query_arg( 'tab', 'tools', $base ) ),
			array( 'dashicons-feedback', __( 'Let your site collect them', 'fw' ), __( 'Put a newsletter or contact form on a page. Everyone who sends it is added here, with the form on their timeline.', 'fw' ), __( 'Open the pages', 'fw' ), admin_url( 'edit.php?post_type=page' ) ),
		);
		?>
		<div class="fw-crm-welcome">
			<h2><?php echo esc_html( sprintf( __( 'Welcome to your CRM, %s', 'fw' ), $user->display_name ) ); ?></h2>
			<p><?php esc_html_e( 'It keeps everyone your business talks to in one place: who they are, which company they work at, what they have done on your site and what they agreed to receive. There is nobody in it yet. Start in any of these three ways.', 'fw' ); ?></p>
			<ol class="fw-crm-welcome__steps">
				<?php foreach ( $steps as $s ) : ?>
					<li>
						<span class="dashicons <?php echo esc_attr( $s[0] ); ?>" aria-hidden="true"></span>
						<h3><?php echo esc_html( $s[1] ); ?></h3>
						<p><?php echo esc_html( $s[2] ); ?></p>
						<a class="button" href="<?php echo esc_url( $s[4] ); ?>"><?php echo esc_html( $s[3] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * One stacked bar of the stages plus a clickable legend.
	 */
	private static function render_stages( array $counts, $total, $base ) {
		$total = max( 1, (int) $total );
		$known = FW_CRM_Contacts::stages();
		?>
		<div class="fw-crm-stagebar" aria-hidden="true">
			<?php foreach ( $known as $slug => $label ) : ?>
				<?php $n = (int) ( $counts[ $slug ] ?? 0 ); ?>
				<?php if ( $n ) : ?>
					<span class="fw-crm-stagebar__seg fw-crm-stage-bg--<?php echo esc_attr( $slug ); ?>" style="flex-grow:<?php echo (int) $n; ?>"></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<ul class="fw-crm-stagelist">
			<?php foreach ( $known as $slug => $label ) : ?>
				<?php $n = (int) ( $counts[ $slug ] ?? 0 ); ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'stage' => $slug ), $base ) ); ?>">
						<span class="fw-crm-dot fw-crm-stage-bg--<?php echo esc_attr( $slug ); ?>" aria-hidden="true"></span>
						<span class="fw-crm-stagelist__label"><?php echo esc_html( $label ); ?></span>
						<span class="fw-crm-stagelist__n"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
						<span class="fw-crm-stagelist__pct"><?php echo esc_html( round( 100 * $n / $total ) . '%' ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The activity feed across records, each entry linked to its contact or company.
	 *
	 * @param array $rows
	 */
	public static function render_feed( array $rows ) {
		if ( ! $rows ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'Nothing has happened yet. Signups, form entries, notes and stage changes will show up here as they happen.', 'fw' ) . '</p>';
			return;
		}
		$base = FW_CRM_Admin_Page::get_page_url();
		?>
		<ol class="fw-crm-feed">
			<?php foreach ( $rows as $r ) : ?>
				<?php
				$label = '';
				$url   = '';
				if ( 'contact' === $r->object_type ) {
					$c = FW_CRM_Subscribers::find( (int) $r->object_id );
					if ( $c ) {
						$label = trim( $c->first_name . ' ' . $c->last_name );
						$label = '' !== $label ? $label : (string) $c->email;
						$url   = add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base );
					}
				} elseif ( 'company' === $r->object_type ) {
					$c = FW_CRM_Companies::find( (int) $r->object_id );
					if ( $c ) {
						$label = (string) $c->name;
						$url   = add_query_arg( array( 'tab' => 'companies', 'company' => (int) $c->id ), $base );
					}
				} elseif ( 'deal' === $r->object_type && class_exists( 'FW_CRM_Deals' ) ) {
					$c = FW_CRM_Deals::find( (int) $r->object_id );
					if ( $c ) {
						$label = (string) $c->title;
						$url   = add_query_arg( array( 'tab' => 'deals', 'deal' => (int) $c->id ), $base );
					}
				}
				?>
				<li>
					<span class="fw-crm-feed__icon fw-crm-feed__icon--<?php echo esc_attr( $r->type ); ?>" aria-hidden="true">
						<span class="dashicons <?php echo esc_attr( FW_CRM_Admin_Contacts::activity_icon( $r->type ) ); ?>"></span>
					</span>
					<span class="fw-crm-feed__text">
						<?php if ( $url ) : ?>
							<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endif; ?>
						<span><?php echo esc_html( $r->title ); ?></span>
					</span>
					<?php echo FW_CRM_App::time_ago( $r->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * The current person's overdue and today's tasks.
	 *
	 * @param string $base
	 */
	private static function render_tasks_card( $base ) {
		$me      = get_current_user_id();
		$overdue = FW_CRM_Tasks::query( array( 'owner' => $me, 'status' => 'overdue' ) );
		$today   = FW_CRM_Tasks::query( array( 'owner' => $me, 'status' => 'today' ) );
		$ids     = wp_list_pluck( $overdue, 'id' );
		$today   = array_values( array_filter( $today, function ( $t ) use ( $ids ) {
			return ! in_array( $t->id, $ids, true );
		} ) );
		$list    = array_slice( array_merge( $overdue, $today ), 0, 6 );
		$types   = FW_CRM_Tasks::types();
		?>
		<section class="fw-crm-panel" aria-labelledby="fw-crm-h-mytasks">
			<header class="fw-crm-panel__head">
				<h2 id="fw-crm-h-mytasks"><?php esc_html_e( 'Your tasks', 'fw' ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'tasks', $base ) ); ?>"><?php esc_html_e( 'All tasks', 'fw' ); ?></a>
			</header>
			<?php if ( ! $list ) : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'Nothing due today. Add calls, meetings and to-dos from any contact, company or deal.', 'fw' ); ?></p>
			<?php else : ?>
				<p class="fw-crm-muted">
					<?php
					echo esc_html( sprintf(
						/* translators: 1: overdue count, 2: due today */
						__( '%1$s overdue, %2$s due today.', 'fw' ),
						number_format_i18n( count( $overdue ) ),
						number_format_i18n( count( $today ) )
					) );
					?>
				</p>
				<ul class="fw-crm-people">
					<?php foreach ( $list as $t ) : ?>
						<?php $target = FW_CRM_Tasks::target( $t ); ?>
						<li>
							<a href="<?php echo esc_url( '' !== $target['url'] ? $target['url'] : add_query_arg( 'tab', 'tasks', $base ) ); ?>">
								<span class="fw-crm-feed__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $types[ $t->type ]['icon'] ?? 'dashicons-yes-alt' ); ?>"></span></span>
								<span class="fw-crm-people__text">
									<span class="fw-crm-people__name"><?php echo esc_html( $t->title ); ?></span>
									<span class="fw-crm-people__sub"><?php echo esc_html( trim( $target['label'] . ( $t->due_at ? ' · ' . date_i18n( get_option( 'time_format' ), strtotime( $t->due_at ) ) : '' ), ' ·' ) ); ?></span>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Open deal value per stage of the default pipeline.
	 *
	 * @param string $base
	 */
	private static function render_pipeline_card( $base ) {
		if ( ! class_exists( 'FW_CRM_Deals' ) ) {
			return;
		}
		$pipeline = FW_CRM_Pipelines::default_pipeline();
		$totals   = FW_CRM_Deals::totals_by_stage( (int) $pipeline->id, FW_CRM_Capabilities::scope( array( 'status' => 'open' ) ) );
		$currency = FW_CRM_Pipelines::currency( $pipeline );
		$max      = 0;
		$sum      = 0;
		$weighted = 0;
		$open     = array();
		foreach ( FW_CRM_Pipelines::stages( (int) $pipeline->id ) as $s ) {
			if ( 'open' === $s->kind ) {
				$open[]   = $s;
				$max      = max( $max, $totals[ (int) $s->id ]['value'] );
				$sum     += $totals[ (int) $s->id ]['value'];
				$weighted += $totals[ (int) $s->id ]['weighted'];
			}
		}
		?>
		<section class="fw-crm-panel" aria-labelledby="fw-crm-h-pipeline">
			<header class="fw-crm-panel__head">
				<h2 id="fw-crm-h-pipeline"><?php echo esc_html( sprintf( __( 'Pipeline: %s', 'fw' ), $pipeline->name ) ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'deals', $base ) ); ?>"><?php esc_html_e( 'Open the board', 'fw' ); ?></a>
			</header>
			<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( '%1$s open, %2$s weighted by stage.', 'fw' ), FW_CRM_Deals::money( $sum, $currency ), FW_CRM_Deals::money( $weighted, $currency ) ) ); ?></p>
			<ul class="fw-crm-funnel">
				<?php foreach ( $open as $s ) : ?>
					<?php $t = $totals[ (int) $s->id ]; ?>
					<li>
						<span class="fw-crm-funnel__label"><?php echo esc_html( $s->name ); ?></span>
						<span class="fw-crm-funnel__bar"><span style="width:<?php echo esc_attr( $max > 0 ? max( 2, round( 100 * $t['value'] / $max ) ) : 0 ); ?>%"></span></span>
						<span class="fw-crm-funnel__value"><?php echo esc_html( FW_CRM_Deals::money( $t['value'], $currency ) ); ?></span>
						<span class="fw-crm-muted fw-crm-funnel__count"><?php echo esc_html( number_format_i18n( $t['count'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function render_campaigns( $base ) {
		$rows = FW_CRM_Campaigns::all( array( 'per_page' => 4 ) );
		if ( ! $rows ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No campaigns yet. Write one to email a list, a tag or a segment.', 'fw' ) . '</p>';
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( add_query_arg( array( 'tab' => 'campaigns', 'new' => 1 ), $base ) ), esc_html__( 'Write a campaign', 'fw' ) );
			return;
		}
		?>
		<ul class="fw-crm-campaigns">
			<?php foreach ( $rows as $c ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'campaigns', 'campaign' => (int) $c->id ), $base ) ); ?>"><?php echo esc_html( '' !== (string) $c->title ? $c->title : $c->subject ); ?></a>
					<span class="fw-crm-status fw-crm-status--<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( ucfirst( (string) $c->status ) ); ?></span>
					<?php if ( (int) $c->total ) : ?>
						<span class="fw-crm-muted"><?php echo esc_html( sprintf( __( '%1$s of %2$s sent', 'fw' ), number_format_i18n( (int) $c->sent ), number_format_i18n( (int) $c->total ) ) ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
