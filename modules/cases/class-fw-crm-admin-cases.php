<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Cases: the board (a column per status) and the list, the case page and the
 * new-case form, plus a Cases panel on contact and company pages. Plugs into the CRM app
 * through its extension points, like Deals.
 *
 * URLs: tab=cases (&view=list, &owner=me|none|<id>, &priority=, &age=<days>, &s=),
 * &case=<id>, &new=1 (&contact= / &company= prefill).
 */
class FW_CRM_Admin_Cases {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'cases' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		add_action( 'wp_ajax_fw_crm_case_move', array( __CLASS__, '_ajax_move' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, '_enqueue' ) );
		add_filter( 'unysonplus_crm_crumbs', array( __CLASS__, '_crumbs' ), 10, 2 );

		foreach ( array( 'contact', 'company' ) as $object ) {
			add_filter( 'unysonplus_crm_' . $object . '_panels', function ( $panels, $record ) use ( $object ) {
				$id              = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
				$panels['cases'] = array(
					'label'    => __( 'Cases', 'fw' ),
					'priority' => 5,
					'column'   => 'side',
					'callback' => function () use ( $object, $id ) {
						self::render_record_cases( $object, $id );
					},
				);
				return $panels;
			}, 5, 2 );
		}
	}

	/** @internal */
	public static function _enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( (string) $hook, 'fw-crm' ) || ! isset( $_GET['tab'] ) || 'cases' !== $_GET['tab'] ) {
			return;
		}
		$ext = fw_ext( 'crm' );
		$ver = $ext->manifest->get_version();
		// The board shares the deals board's look (the file ships with the extension whether or not the pipeline is on).
		wp_enqueue_style( 'fw-crm-board', $ext->get_uri( '/modules/pipeline/static/css/board.css' ), array( 'fw-ext-crm-app' ), $ver );
		wp_enqueue_style( 'fw-crm-cases', $ext->get_uri( '/modules/cases/static/css/cases.css' ), array( 'fw-crm-board' ), $ver );
		wp_enqueue_script( 'fw-crm-cases', $ext->get_uri( '/modules/cases/static/js/cases.js' ), array(), $ver, true );
	}

	/** @internal */
	public static function _crumbs( $crumbs, $tab ) {
		if ( 'cases' !== $tab ) {
			return $crumbs;
		}
		$base = FW_CRM_Admin_Page::get_page_url( 'cases' );
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['case'] ) && ( $c = FW_CRM_Cases::find( (int) $_GET['case'] ) ) ) {
			return array( array( __( 'Cases', 'fw' ), $base ), array( '#' . (int) $c->id . ' ' . $c->title ) );
		}
		if ( ! empty( $_GET['new'] ) ) {
			return array( array( __( 'Cases', 'fw' ), $base ), array( __( 'New case', 'fw' ) ) );
		}
		// phpcs:enable
		return $crumbs;
	}

	/* ---------------------------------------------------------------------- *
	 * Actions
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'case_' ) ) {
			return $args;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id = isset( $_REQUEST['case'] ) ? (int) $_REQUEST['case'] : 0;
		if ( $id && ! FW_CRM_Capabilities::can_edit_case( $id ) ) {
			wp_die( esc_html__( 'That case belongs to someone else.', 'fw' ), 403 );
		}
		$page = array( 'tab' => 'cases', 'case' => $id );

		switch ( $action ) {
			case 'case_create':
				$data = self::posted();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					$data['owner_id'] = get_current_user_id();
				}
				$data['body'] = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
				$new          = FW_CRM_Cases::create( $data );
				if ( is_wp_error( $new ) ) {
					FW_CRM_Admin_Page::flash( 'error', $new->get_error_message() );
					return array( 'tab' => 'cases', 'new' => 1 );
				}
				FW_CRM_Admin_Page::flash( 'success', sprintf( __( 'Case #%d opened.', 'fw' ), (int) $new ) );
				return array( 'tab' => 'cases', 'case' => $new );

			case 'case_save':
				$data = self::posted();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					unset( $data['owner_id'] );
				}
				$res = FW_CRM_Cases::update( $id, $data );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Case saved.', 'fw' ) );
				return $page;

			case 'case_status':
				$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
				FW_CRM_Cases::update( $id, array( 'status' => $status ) );
				return $page;

			case 'case_take':
				FW_CRM_Cases::update( $id, array( 'owner_id' => get_current_user_id() ) );
				return $page;

			case 'case_note':
				$body = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';
				if ( '' === $body ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'Write something before saving the note.', 'fw' ) );
				} else {
					FW_CRM_Activity::log( 'case', $id, 'note', __( 'Internal note', 'fw' ), array( 'body' => $body ) );
				}
				return $page;

			case 'case_reply':
				$body = isset( $_POST['reply'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reply'] ) ) ) : '';
				if ( '' === $body ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'Write the reply first.', 'fw' ) );
					return $page;
				}
				$res = FW_CRM_Cases::reply( $id, $body, ! empty( $_POST['wait'] ) );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Reply sent.', 'fw' ) );
				return $page;

			case 'case_link_contact':
				$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
				$contact = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;
				if ( ! $contact || ! FW_CRM_Capabilities::can_edit_contact( $contact ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'No contact of yours has that email address. Add them as a contact first.', 'fw' ) );
				} else {
					FW_CRM_Cases::update( $id, array( 'contact_id' => (int) $contact->id, 'company_id' => (int) $contact->company_id ) );
				}
				return $page;

			case 'case_delete':
				if ( ! FW_CRM_Capabilities::can_manage() ) {
					wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
				}
				FW_CRM_Cases::delete( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Case deleted.', 'fw' ) );
				return array( 'tab' => 'cases' );

			case 'case_settings':
				if ( ! FW_CRM_Capabilities::can_manage() ) {
					wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
				}
				FW_CRM_Cases::save_settings( array(
					'from_email'    => ! empty( $_POST['from_email'] ),
					'default_owner' => isset( $_POST['default_owner'] ) ? (int) $_POST['default_owner'] : 0,
				) );
				FW_CRM_Admin_Page::flash( 'success', __( 'Case settings saved.', 'fw' ) );
				return array( 'tab' => 'cases', 'view' => 'list' );
		}
		// phpcs:enable

		return $args;
	}

	/** @return array Case fields from the posted form. */
	private static function posted() {
		// phpcs:disable WordPress.Security.NonceVerification
		$data = array();
		foreach ( array( 'title', 'status', 'priority', 'due_at' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		foreach ( array( 'owner_id', 'company_id', 'contact_id' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = (int) $_POST[ $k ];
			}
		}
		// phpcs:enable
		return $data;
	}

	/** @internal admin-ajax: a card dropped on another status. */
	public static function _ajax_move() {
		check_ajax_referer( 'fw_crm_case_move' );
		$id     = isset( $_POST['case'] ) ? (int) $_POST['case'] : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! current_user_can( FW_CRM_Service::capability() ) || ! FW_CRM_Capabilities::can_edit_case( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That case belongs to someone else.', 'fw' ) ), 403 );
		}
		if ( ! FW_CRM_Cases::find( $id ) || ! array_key_exists( $status, FW_CRM_Cases::statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'Case not found.', 'fw' ) ), 404 );
		}
		FW_CRM_Cases::update( $id, array( 'status' => $status ) );
		if ( isset( $_POST['order'] ) ) {
			$order = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['order'] ) ) ) ) );
			FW_CRM_Cases::reorder( array_filter( $order, array( 'FW_CRM_Capabilities', 'can_edit_case' ) ) );
		}
		wp_send_json_success( array( 'counts' => FW_CRM_Cases::counts_by_status( self::filters() ) ) );
	}

	/**
	 * The filters on the screen, as query() args. Someone who does not see everything sees
	 * their own cases and the unassigned ones.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$args  = array();
		$owner = isset( $_REQUEST['owner'] ) ? sanitize_key( wp_unslash( $_REQUEST['owner'] ) ) : '';
		if ( 'me' === $owner ) {
			$args['owner'] = get_current_user_id();
		} elseif ( 'none' === $owner ) {
			$args['owner'] = 0;
		} elseif ( ctype_digit( $owner ) && FW_CRM_Capabilities::sees_all() ) {
			$args['owner'] = (int) $owner;
		}
		if ( ! FW_CRM_Capabilities::sees_all() && ! isset( $args['owner'] ) ) {
			$args['mine_or_unassigned'] = get_current_user_id();
		}
		if ( ! empty( $_REQUEST['priority'] ) ) {
			$args['priority'] = sanitize_key( wp_unslash( $_REQUEST['priority'] ) );
		}
		if ( ! empty( $_REQUEST['age'] ) ) {
			$args['older_than'] = (int) $_REQUEST['age'];
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$args['search'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}
		// phpcs:enable
		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Screens
	 * ---------------------------------------------------------------------- */

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['case'] ) ) {
			self::render_case( (int) $_GET['case'] );
		} elseif ( ! empty( $_GET['new'] ) ) {
			self::render_new();
		} else {
			self::render_overview( isset( $_GET['view'] ) && 'list' === $_GET['view'] ? 'list' : 'board' );
		}
		// phpcs:enable
	}

	/**
	 * The filter bar and the board or the list.
	 *
	 * @param string $view board|list
	 */
	private static function render_overview( $view ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$owner    = isset( $_GET['owner'] ) ? sanitize_key( wp_unslash( $_GET['owner'] ) ) : '';
		$priority = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : '';
		$age      = isset( $_GET['age'] ) ? (int) $_GET['age'] : 0;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$args   = self::filters();
		$base   = FW_CRM_Admin_Page::get_page_url();
		$counts = FW_CRM_Cases::counts_by_status( $args );
		$keep   = array_filter( array( 'owner' => $owner, 'priority' => $priority, 'age' => $age ?: '', 's' => $search ) );
		?>
		<div class="fw-crm-listbar fw-crm-board-bar">
			<form method="get" action="" class="fw-crm-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="cases" />
				<?php if ( 'list' === $view ) : ?>
					<input type="hidden" name="view" value="list" />
				<?php endif; ?>
				<label class="screen-reader-text" for="fw-crm-case-owner"><?php esc_html_e( 'Owner', 'fw' ); ?></label>
				<select id="fw-crm-case-owner" name="owner" onchange="this.form.submit()">
					<option value=""><?php echo FW_CRM_Capabilities::sees_all() ? esc_html__( 'Everyone\'s cases', 'fw' ) : esc_html__( 'Mine and unassigned', 'fw' ); ?></option>
					<option value="me" <?php selected( $owner, 'me' ); ?>><?php esc_html_e( 'My cases', 'fw' ); ?></option>
					<option value="none" <?php selected( $owner, 'none' ); ?>><?php esc_html_e( 'Unassigned', 'fw' ); ?></option>
					<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
						<?php foreach ( self::team() as $u ) : ?>
							<?php if ( (int) $u->ID !== get_current_user_id() ) : ?>
								<option value="<?php echo (int) $u->ID; ?>" <?php selected( $owner, (string) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<label class="screen-reader-text" for="fw-crm-case-priority"><?php esc_html_e( 'Priority', 'fw' ); ?></label>
				<select id="fw-crm-case-priority" name="priority" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any priority', 'fw' ); ?></option>
					<?php foreach ( array_reverse( FW_CRM_Cases::priorities(), true ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $priority, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="fw-crm-case-age"><?php esc_html_e( 'Age', 'fw' ); ?></label>
				<select id="fw-crm-case-age" name="age" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any age', 'fw' ); ?></option>
					<?php foreach ( array( 1, 3, 7, 14, 30 ) as $d ) : ?>
						<option value="<?php echo (int) $d; ?>" <?php selected( $age, $d ); ?>><?php echo esc_html( sprintf( _n( 'Older than %s day', 'Older than %s days', $d, 'fw' ), number_format_i18n( $d ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="fw-crm-case-s"><?php esc_html_e( 'Search cases', 'fw' ); ?></label>
				<input type="search" id="fw-crm-case-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search, or #number', 'fw' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fw' ); ?></button>
			</form>
			<div class="fw-crm-inline">
				<span class="fw-crm-viewswitch" role="group" aria-label="<?php esc_attr_e( 'View', 'fw' ); ?>">
					<a class="button<?php echo 'board' === $view ? ' is-active' : ''; ?>" <?php echo 'board' === $view ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( array_merge( array( 'tab' => 'cases' ), $keep ), $base ) ); ?>"><?php esc_html_e( 'Board', 'fw' ); ?></a>
					<a class="button<?php echo 'list' === $view ? ' is-active' : ''; ?>" <?php echo 'list' === $view ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( array_merge( array( 'tab' => 'cases', 'view' => 'list' ), $keep ), $base ) ); ?>"><?php esc_html_e( 'List', 'fw' ); ?></a>
				</span>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'cases', 'new' => 1 ), $base ) ); ?>"><?php esc_html_e( 'New case', 'fw' ); ?></a>
			</div>
		</div>

		<p class="fw-crm-status-line">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: new, 2: open, 3: waiting, 4: solved */
				__( '%1$s new, %2$s open, %3$s waiting on the customer, %4$s solved.', 'fw' ),
				number_format_i18n( $counts['new'] ?? 0 ),
				number_format_i18n( $counts['open'] ?? 0 ),
				number_format_i18n( $counts['waiting'] ?? 0 ),
				number_format_i18n( $counts['solved'] ?? 0 )
			) );
			?>
		</p>

		<?php
		if ( 'list' === $view ) {
			self::render_list( $args );
		} else {
			self::render_board( $args, $counts );
		}

		if ( ! array_sum( $counts ) && ! $keep ) {
			echo '<p class="fw-crm-muted fw-crm-board-empty">' . esc_html__( 'No cases yet. A case is a question or problem from a customer: it gets a status, a priority and an owner, and every reply and note stays on its timeline. Open one with New case, from a contact\'s page, from a contact form (Add to the CRM → Also create → A support case) or from email.', 'fw' ) . '</p>';
		}

		if ( FW_CRM_Capabilities::can_manage() && 'list' === $view ) {
			self::render_settings();
		}
	}

	/**
	 * @param array $args
	 * @param array $counts
	 */
	private static function render_board( array $args, array $counts ) {
		$statuses = FW_CRM_Cases::statuses();
		$recent   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		?>
		<div class="fw-crm-board fw-crm-case-board" id="fw-crm-case-board"
		     data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		     data-nonce="<?php echo esc_attr( wp_create_nonce( 'fw_crm_case_move' ) ); ?>"
		     data-i18n="<?php echo esc_attr( wp_json_encode( array( 'failed' => __( 'That move could not be saved. The board was put back.', 'fw' ), 'moved' => __( 'Case %1$s moved to %2$s.', 'fw' ) ) ) ); ?>">
			<?php foreach ( $statuses as $status => $s ) : ?>
				<?php
				$cases = FW_CRM_Cases::query( array_merge( $args, array( 'status' => $status, 'orderby' => 'board', 'per_page' => 100 ) ) );
				if ( ! empty( $s['done'] ) ) {
					// Solved: the last two weeks only, so the column does not grow for ever.
					$cases = array_values( array_filter( $cases, function ( $c ) use ( $recent ) {
						return $c->solved_at && $c->solved_at >= $recent;
					} ) );
				}
				?>
				<section class="fw-crm-board__col fw-crm-case-col--<?php echo esc_attr( $status ); ?>" data-stage="<?php echo esc_attr( $status ); ?>" aria-labelledby="fw-crm-case-st-<?php echo esc_attr( $status ); ?>">
					<header class="fw-crm-board__head">
						<h2 id="fw-crm-case-st-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $s['label'] ); ?></h2>
						<p class="fw-crm-board__sum">
							<span class="fw-crm-muted"><span class="fw-crm-board__count"><?php echo esc_html( number_format_i18n( $counts[ $status ] ?? 0 ) ); ?></span><?php echo ! empty( $s['done'] ) ? ' · ' . esc_html__( 'last 14 days shown', 'fw' ) : ''; ?></span>
						</p>
					</header>
					<ol class="fw-crm-board__list">
						<?php foreach ( $cases as $c ) : ?>
							<?php self::render_card( $c ); ?>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** @param object $c */
	private static function render_card( $c ) {
		$base    = FW_CRM_Admin_Page::get_page_url();
		$contact = (int) $c->contact_id ? FW_CRM_Subscribers::find( (int) $c->contact_id ) : null;
		$owner   = (int) $c->owner_id ? get_userdata( (int) $c->owner_id ) : null;
		$late    = FW_CRM_Cases::is_overdue( $c );
		$prio    = FW_CRM_Cases::priorities();
		?>
		<li class="fw-crm-deal-card fw-crm-case-card fw-crm-case-card--<?php echo esc_attr( $c->priority ); ?>" draggable="true" data-deal="<?php echo (int) $c->id; ?>" data-title="<?php echo esc_attr( '#' . (int) $c->id ); ?>">
			<a class="fw-crm-deal-card__title" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'cases', 'case' => (int) $c->id ), $base ) ); ?>"><span class="fw-crm-case-num">#<?php echo (int) $c->id; ?></span> <?php echo esc_html( $c->title ); ?></a>
			<?php if ( $contact ) : ?>
				<span class="fw-crm-deal-card__who"><?php echo esc_html( self::contact_name( $contact ) ); ?></span>
			<?php endif; ?>
			<span class="fw-crm-deal-card__meta">
				<?php if ( 'normal' !== $c->priority ) : ?>
					<span class="fw-crm-case-prio fw-crm-case-prio--<?php echo esc_attr( $c->priority ); ?>"><?php echo esc_html( $prio[ $c->priority ] ?? $c->priority ); ?></span>
				<?php endif; ?>
				<span class="fw-crm-muted"><?php echo esc_html( self::age_label( $c ) ); ?></span>
				<?php if ( $c->due_at ) : ?>
					<span class="<?php echo $late ? 'is-late' : ''; ?>"><?php echo esc_html( sprintf( __( 'Due %s', 'fw' ), date_i18n( 'j M', strtotime( $c->due_at ) ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $owner ) : ?>
					<?php echo FW_CRM_App::avatar( $owner->display_name, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php else : ?>
					<span class="fw-crm-case-unassigned"><?php esc_html_e( 'Unassigned', 'fw' ); ?></span>
				<?php endif; ?>
			</span>
		</li>
		<?php
	}

	/** @param array $args */
	private static function render_list( array $args ) {
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification
		$cases    = FW_CRM_Cases::query( array_merge( $args, array( 'status' => $status, 'per_page' => 200 ) ) );
		$statuses = FW_CRM_Cases::statuses();
		$prio     = FW_CRM_Cases::priorities();
		$base     = FW_CRM_Admin_Page::get_page_url();
		$here     = remove_query_arg( array( 'status', 'fw_crm_action' ) );
		?>
		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'active', $here ) ); ?>" class="<?php echo 'active' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Not solved', 'fw' ); ?></a> |</li>
			<?php foreach ( $statuses as $k => $s ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'status', $k, $here ) ); ?>" class="<?php echo $k === $status ? 'current' : ''; ?>"><?php echo esc_html( $s['label'] ); ?></a> |</li>
			<?php endforeach; ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', '', $here ) ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'fw' ); ?></a></li>
		</ul>
		<table class="widefat striped fw-crm-case-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Case', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Contact', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Priority', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Owner', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Age', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last activity', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $cases ) : ?>
					<tr><td colspan="7" class="fw-crm-muted"><?php esc_html_e( 'No cases match.', 'fw' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $cases as $c ) : ?>
					<?php
					$contact = (int) $c->contact_id ? FW_CRM_Subscribers::find( (int) $c->contact_id ) : null;
					$owner   = (int) $c->owner_id ? get_userdata( (int) $c->owner_id ) : null;
					?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'cases', 'case' => (int) $c->id ), $base ) ); ?>"><strong><span class="fw-crm-case-num">#<?php echo (int) $c->id; ?></span> <?php echo esc_html( $c->title ); ?></strong></a></td>
						<td><?php echo $contact ? esc_html( self::contact_name( $contact ) ) : '<span class="fw-crm-muted">—</span>'; ?></td>
						<td><span class="fw-crm-case-status fw-crm-case-status--<?php echo esc_attr( $c->status ); ?>"><?php echo esc_html( $statuses[ $c->status ]['label'] ?? $c->status ); ?></span></td>
						<td><span class="fw-crm-case-prio fw-crm-case-prio--<?php echo esc_attr( $c->priority ); ?>"><?php echo esc_html( $prio[ $c->priority ] ?? $c->priority ); ?></span></td>
						<td><?php echo $owner ? esc_html( $owner->display_name ) : '<span class="fw-crm-muted">' . esc_html__( 'Unassigned', 'fw' ) . '</span>'; ?></td>
						<td><?php echo esc_html( self::age_label( $c ) ); ?><?php echo FW_CRM_Cases::is_overdue( $c ) ? ' <span class="is-late">' . esc_html__( 'overdue', 'fw' ) . '</span>' : ''; ?></td>
						<td><?php echo FW_CRM_App::time_ago( (string) ( $c->last_activity_at ?: $c->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Managers: where cases come from and who gets them. */
	private static function render_settings() {
		$s       = FW_CRM_Cases::settings();
		$inbound = class_exists( 'FW_CRM_Inbound_Email' );
		?>
		<form method="post" action="" class="fw-crm-panel fw-crm-form fw-crm-case-settings">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="case_settings" />
			<header class="fw-crm-panel__head"><h2><?php esc_html_e( 'Case settings', 'fw' ); ?></h2></header>
			<label class="fw-crm-check">
				<input type="checkbox" name="from_email" value="1" <?php checked( $s['from_email'] ); ?> <?php disabled( ! $inbound ); ?> />
				<?php esc_html_e( 'Open a case for each new email a customer sends to the CRM\'s address', 'fw' ); ?>
			</label>
			<p class="fw-crm-muted">
				<?php
				echo $inbound
					? esc_html__( 'Replies to a case (their subject has the case number) always go back to that case. Replies to emails your team sent from the CRM stay on the contact\'s timeline and never open a case.', 'fw' )
					: esc_html__( 'Needs incoming email: switch on the Email conversations module and set up its forwarding address or mailbox.', 'fw' );
				?>
			</p>
			<label class="fw-crm-form__row"><span><?php esc_html_e( 'New cases go to', 'fw' ); ?></span>
				<select name="default_owner">
					<option value="0"><?php esc_html_e( 'Nobody (unassigned)', 'fw' ); ?></option>
					<?php foreach ( self::team() as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $s['default_owner'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<p class="fw-crm-muted"><?php esc_html_e( 'A case from a form or email goes to the contact\'s owner when they have one, otherwise to this person.', 'fw' ); ?></p>
			<p class="fw-crm-form__foot"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fw' ); ?></button></p>
		</form>
		<?php
	}

	/** New case form. */
	private static function render_new() {
		// phpcs:disable WordPress.Security.NonceVerification
		$contact = ! empty( $_GET['contact'] ) ? FW_CRM_Subscribers::find( (int) $_GET['contact'] ) : null;
		$company = ! empty( $_GET['company'] ) ? FW_CRM_Companies::find( (int) $_GET['company'] ) : null;
		// phpcs:enable
		?>
		<form method="post" action="" class="fw-crm-panel fw-crm-form fw-crm-form--page">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="case_create" />
			<?php if ( $contact ) : ?>
				<input type="hidden" name="contact_id" value="<?php echo (int) $contact->id; ?>" />
			<?php endif; ?>
			<?php if ( $company ) : ?>
				<input type="hidden" name="company_id" value="<?php echo (int) $company->id; ?>" />
			<?php endif; ?>
			<div class="fw-crm-form__grid">
				<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'What is it about?', 'fw' ); ?></span>
					<input type="text" name="title" required autofocus />
				</label>
				<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'Details (optional)', 'fw' ); ?></span>
					<textarea name="body" rows="4" placeholder="<?php esc_attr_e( 'What the customer asked or reported', 'fw' ); ?>"></textarea>
				</label>
				<?php self::priority_select( 'normal' ); ?>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Due', 'fw' ); ?></span><input type="date" name="due_at" /></label>
				<?php self::owner_select( (int) FW_CRM_Cases::settings()['default_owner'] ?: get_current_user_id() ); ?>
			</div>
			<?php if ( $contact ) : ?>
				<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'For %s.', 'fw' ), self::contact_name( $contact ) ) ); ?></p>
			<?php elseif ( $company ) : ?>
				<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'For %s.', 'fw' ), $company->name ) ); ?></p>
			<?php else : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'You can link it to a contact on the case page.', 'fw' ); ?></p>
			<?php endif; ?>
			<p class="fw-crm-form__foot">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Open case', 'fw' ); ?></button>
				<a class="button" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'cases' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * The case page.
	 *
	 * @param int $id
	 */
	private static function render_case( $id ) {
		$c = FW_CRM_Cases::find( $id );
		if ( ! $c ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Case not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to cases', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'cases' ) ) ) );
			return;
		}
		if ( ! FW_CRM_Capabilities::can_edit_case( $c ) ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'This case belongs to someone else', 'fw' ), __( 'You see your own cases and the unassigned ones. Ask a CRM manager if it should be yours.', 'fw' ), array( array( __( 'Back to cases', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'cases' ) ) ) );
			return;
		}
		$base     = FW_CRM_Admin_Page::get_page_url();
		$statuses = FW_CRM_Cases::statuses();
		$prio     = FW_CRM_Cases::priorities();
		$contact  = (int) $c->contact_id ? FW_CRM_Subscribers::find( (int) $c->contact_id ) : null;
		$company  = (int) $c->company_id && FW_CRM_Modules::enabled( 'companies' ) ? FW_CRM_Companies::find( (int) $c->company_id ) : null;
		$owner    = (int) $c->owner_id ? get_userdata( (int) $c->owner_id ) : null;
		$can_mail = class_exists( 'FW_CRM_Direct_Email' ) && $contact && is_email( (string) $contact->email );
		$action   = function ( $name, $extra = array() ) use ( $id, $base ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'tab' => 'cases', 'fw_crm_action' => $name, 'case' => $id ), $extra ), $base ), FW_CRM_Admin_Page::NONCE );
		};
		?>
		<div class="fw-crm-record fw-crm-deal fw-crm-case">
			<section class="fw-crm-panel fw-crm-record__head fw-crm-deal__head">
				<span class="fw-crm-deal__icon" aria-hidden="true"><span class="dashicons dashicons-sos"></span></span>
				<div class="fw-crm-record__id">
					<h2><span class="fw-crm-case-num">#<?php echo (int) $c->id; ?></span> <?php echo esc_html( $c->title ); ?></h2>
					<p class="fw-crm-record__sub">
						<span class="fw-crm-case-prio fw-crm-case-prio--<?php echo esc_attr( $c->priority ); ?>"><?php echo esc_html( sprintf( __( '%s priority', 'fw' ), $prio[ $c->priority ] ?? $c->priority ) ); ?></span>
						<span aria-hidden="true">·</span>
						<?php echo esc_html( sprintf( __( 'Opened %s ago', 'fw' ), human_time_diff( strtotime( $c->created_at ), current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp ?>
						<?php if ( $c->due_at ) : ?>
							<span aria-hidden="true">·</span>
							<span class="<?php echo FW_CRM_Cases::is_overdue( $c ) ? 'is-late' : ''; ?>"><?php echo esc_html( sprintf( __( 'Due %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $c->due_at ) ) ) ); ?></span>
						<?php endif; ?>
					</p>
					<ul class="fw-crm-record__chips">
						<?php if ( $contact ) : ?>
							<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $contact->id ), $base ) ); ?>"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo esc_html( self::contact_name( $contact ) ); ?></a></li>
						<?php endif; ?>
						<?php if ( $company ) : ?>
							<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'companies', 'company' => (int) $company->id ), $base ) ); ?>"><span class="dashicons dashicons-building" aria-hidden="true"></span><?php echo esc_html( $company->name ); ?></a></li>
						<?php endif; ?>
						<li><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><?php echo $owner ? esc_html( sprintf( __( 'Owner: %s', 'fw' ), $owner->display_name ) ) : esc_html__( 'Unassigned', 'fw' ); ?></li>
					</ul>
				</div>
				<div class="fw-crm-record__actions">
					<?php if ( (int) $c->owner_id !== get_current_user_id() ) : ?>
						<a class="button" href="<?php echo esc_url( $action( 'case_take' ) ); ?>"><?php esc_html_e( 'Take it', 'fw' ); ?></a>
					<?php endif; ?>
					<?php do_action( 'unysonplus_crm_record_actions', 'case', $c ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in the contact page. ?>
					<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
				</div>
			</section>

			<nav class="fw-crm-panel fw-crm-stagepath" aria-label="<?php esc_attr_e( 'Case status', 'fw' ); ?>">
				<ol>
					<?php
					$reached = true;
					foreach ( $statuses as $k => $s ) :
						$current = $k === $c->status;
						?>
						<li class="<?php echo $current ? 'is-current' : ( $reached ? 'is-done' : '' ); ?>">
							<?php if ( $current ) : ?>
								<span aria-current="step"><?php echo esc_html( $s['label'] ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $action( 'case_status', array( 'status' => $k ) ) ); ?>"><?php echo esc_html( $s['label'] ); ?></a>
							<?php endif; ?>
						</li>
						<?php
						if ( $current ) {
							$reached = false;
						}
					endforeach;
					?>
				</ol>
			</nav>

			<div class="fw-crm-record__cols">
				<div class="fw-crm-record__col">
					<section class="fw-crm-panel" id="fw-crm-about" aria-labelledby="fw-crm-h-about">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-about"><?php esc_html_e( 'About', 'fw' ); ?></h2>
							<button type="button" class="button-link" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
						</header>
						<div data-view>
							<dl class="fw-crm-details">
								<dt><?php esc_html_e( 'Status', 'fw' ); ?></dt><dd><?php echo esc_html( $statuses[ $c->status ]['label'] ?? $c->status ); ?></dd>
								<dt><?php esc_html_e( 'Priority', 'fw' ); ?></dt><dd><?php echo esc_html( $prio[ $c->priority ] ?? $c->priority ); ?></dd>
								<dt><?php esc_html_e( 'Opened', 'fw' ); ?></dt><dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $c->created_at ) ) ); ?></dd>
								<dt><?php esc_html_e( 'Came from', 'fw' ); ?></dt><dd><?php echo esc_html( array( 'form' => __( 'A form', 'fw' ), 'email' => __( 'An email', 'fw' ) )[ $c->source ] ?? __( 'Opened by hand', 'fw' ) ); ?></dd>
								<?php if ( $c->solved_at ) : ?>
									<dt><?php esc_html_e( 'Solved', 'fw' ); ?></dt><dd><?php echo esc_html( sprintf( __( '%1$s (took %2$s)', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $c->solved_at ) ), human_time_diff( strtotime( $c->created_at ), strtotime( $c->solved_at ) ) ) ); ?></dd>
								<?php endif; ?>
							</dl>
						</div>
						<form method="post" action="" class="fw-crm-form" data-edit hidden>
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="case_save" />
							<input type="hidden" name="case" value="<?php echo (int) $id; ?>" />
							<div class="fw-crm-form__grid">
								<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'What is it about?', 'fw' ); ?></span><input type="text" name="title" required value="<?php echo esc_attr( $c->title ); ?>" /></label>
								<?php self::priority_select( $c->priority ); ?>
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Due', 'fw' ); ?></span><input type="date" name="due_at" value="<?php echo esc_attr( $c->due_at ? substr( $c->due_at, 0, 10 ) : '' ); ?>" /></label>
								<?php self::owner_select( (int) $c->owner_id, true ); ?>
							</div>
							<p class="fw-crm-form__foot">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'fw' ); ?></button>
								<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
							</p>
						</form>
					</section>
				</div>

				<div class="fw-crm-record__col fw-crm-record__col--wide">
					<section class="fw-crm-panel fw-crm-case-reply" aria-labelledby="fw-crm-h-reply">
						<header class="fw-crm-panel__head"><h2 id="fw-crm-h-reply"><?php esc_html_e( 'Answer', 'fw' ); ?></h2></header>
						<?php if ( $can_mail ) : ?>
							<form method="post" action="" class="fw-crm-form">
								<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
								<input type="hidden" name="fw_crm_action" value="case_reply" />
								<input type="hidden" name="case" value="<?php echo (int) $id; ?>" />
								<label class="screen-reader-text" for="fw-crm-case-reply"><?php esc_html_e( 'Reply to the customer', 'fw' ); ?></label>
								<textarea id="fw-crm-case-reply" name="reply" rows="4" placeholder="<?php echo esc_attr( sprintf( __( 'Reply to %s by email', 'fw' ), self::contact_name( $contact ) ) ); ?>"></textarea>
								<p class="fw-crm-form__foot">
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Send reply', 'fw' ); ?></button>
									<label class="fw-crm-check"><input type="checkbox" name="wait" value="1" checked /> <?php esc_html_e( 'Then wait for the customer', 'fw' ); ?></label>
								</p>
								<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'Subject: %s. Their answer comes back to this case.', 'fw' ), FW_CRM_Cases::subject( $c ) ) ); ?></p>
							</form>
						<?php elseif ( ! $contact ) : ?>
							<p class="fw-crm-muted"><?php esc_html_e( 'Link the case to a contact to answer them by email.', 'fw' ); ?></p>
							<form method="post" action="" class="fw-crm-inline">
								<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
								<input type="hidden" name="fw_crm_action" value="case_link_contact" />
								<input type="hidden" name="case" value="<?php echo (int) $id; ?>" />
								<label class="screen-reader-text" for="fw-crm-case-person"><?php esc_html_e( 'Email of a contact', 'fw' ); ?></label>
								<input type="email" id="fw-crm-case-person" name="email" required placeholder="<?php esc_attr_e( 'Email of a contact', 'fw' ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Link', 'fw' ); ?></button>
							</form>
						<?php else : ?>
							<p class="fw-crm-muted"><?php echo class_exists( 'FW_CRM_Direct_Email' ) ? esc_html__( 'This contact has no email address. Add one to answer from here.', 'fw' ) : esc_html__( 'Switch on the Email conversations module (CRM → Modules) to answer customers from the case.', 'fw' ); ?></p>
						<?php endif; ?>
						<form method="post" action="" class="fw-crm-note">
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="case_note" />
							<input type="hidden" name="case" value="<?php echo (int) $id; ?>" />
							<label class="screen-reader-text" for="fw-crm-case-note"><?php esc_html_e( 'Internal note', 'fw' ); ?></label>
							<textarea id="fw-crm-case-note" name="note" rows="2" placeholder="<?php esc_attr_e( 'Internal note: only your team sees it', 'fw' ); ?>"></textarea>
							<button type="submit" class="button"><?php esc_html_e( 'Save note', 'fw' ); ?></button>
						</form>
					</section>
					<?php FW_CRM_App::render_panels( 'case', $c, 'main' ); ?>
					<?php self::render_timeline( $id ); ?>
				</div>

				<div class="fw-crm-record__col">
					<?php if ( $contact ) : ?>
						<section class="fw-crm-panel" aria-labelledby="fw-crm-h-casecontact">
							<header class="fw-crm-panel__head"><h2 id="fw-crm-h-casecontact"><?php esc_html_e( 'Customer', 'fw' ); ?></h2></header>
							<p>
								<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $contact->id ), $base ) ); ?>"><strong><?php echo esc_html( self::contact_name( $contact ) ); ?></strong></a><br />
								<?php if ( $contact->email ) : ?><span class="fw-crm-muted"><?php echo esc_html( $contact->email ); ?></span><br /><?php endif; ?>
								<?php if ( $contact->phone ) : ?><span class="fw-crm-muted"><?php echo esc_html( $contact->phone ); ?></span><?php endif; ?>
							</p>
							<?php $others = array_filter( FW_CRM_Cases::query( array( 'contact' => (int) $contact->id, 'per_page' => 6 ) ), function ( $o ) use ( $id ) { return (int) $o->id !== (int) $id; } ); ?>
							<?php if ( $others ) : ?>
								<h3 class="fw-crm-subhead"><?php esc_html_e( 'Their other cases', 'fw' ); ?></h3>
								<?php self::case_links( $others ); ?>
							<?php endif; ?>
						</section>
					<?php endif; ?>
					<?php FW_CRM_App::render_panels( 'case', $c, 'side' ); ?>
					<?php if ( FW_CRM_Capabilities::can_manage() ) : ?>
						<p class="fw-crm-record__delete"><a class="button-link button-link-delete" href="<?php echo esc_url( $action( 'case_delete' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this case and its timeline for good?', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete case', 'fw' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The case timeline: replies both ways, notes and every change, newest first.
	 *
	 * @param int $id
	 */
	private static function render_timeline( $id ) {
		$rows = FW_CRM_Activity::for_record( 'case', $id, 200 );
		?>
		<section class="fw-crm-panel" aria-labelledby="fw-crm-h-timeline">
			<header class="fw-crm-panel__head"><h2 id="fw-crm-h-timeline"><?php esc_html_e( 'Conversation and history', 'fw' ); ?></h2></header>
			<?php if ( ! $rows ) : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'Nothing here yet.', 'fw' ); ?></p>
			<?php else : ?>
				<ol class="fw-crm-timeline">
					<?php foreach ( $rows as $r ) : ?>
						<?php $actor = (int) $r->actor_id ? get_userdata( (int) $r->actor_id ) : null; ?>
						<li class="fw-crm-timeline__item fw-crm-timeline__item--<?php echo esc_attr( $r->type ); ?>">
							<span class="fw-crm-feed__icon fw-crm-feed__icon--<?php echo esc_attr( $r->type ); ?>" aria-hidden="true">
								<span class="dashicons <?php echo esc_attr( FW_CRM_Admin_Contacts::activity_icon( $r->type ) ); ?>"></span>
							</span>
							<div class="fw-crm-timeline__body">
								<p class="fw-crm-timeline__title">
									<strong><?php echo esc_html( $r->title ); ?></strong>
									<?php if ( $actor ) : ?>
										<span class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'by %s', 'fw' ), $actor->display_name ) ); ?></span>
									<?php endif; ?>
									<?php echo FW_CRM_App::time_ago( $r->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</p>
								<?php if ( '' !== (string) $r->body ) : ?>
									<div class="fw-crm-timeline__text"><?php echo wp_kses_post( wpautop( $r->body ) ); ?></div>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Cases panel on a contact or company page.
	 *
	 * @param string $object contact|company
	 * @param int    $id
	 */
	public static function render_record_cases( $object, $id ) {
		$cases = FW_CRM_Cases::query( array( 'contact' === $object ? 'contact' : 'company' => (int) $id, 'per_page' => 10 ) );
		$new   = add_query_arg( array( 'tab' => 'cases', 'new' => 1, $object => (int) $id ), FW_CRM_Admin_Page::get_page_url() );
		if ( $cases ) {
			self::case_links( $cases );
		} else {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No cases.', 'fw' ) . '</p>';
		}
		echo '<p><a class="button button-small" href="' . esc_url( $new ) . '">' . esc_html__( 'New case', 'fw' ) . '</a></p>';
	}

	/** @param object[] $cases */
	private static function case_links( array $cases ) {
		$statuses = FW_CRM_Cases::statuses();
		$base     = FW_CRM_Admin_Page::get_page_url();
		echo '<ul class="fw-crm-case-links">';
		foreach ( $cases as $c ) {
			printf(
				'<li><a href="%1$s"><span class="fw-crm-case-num">#%2$d</span> %3$s</a> <span class="fw-crm-case-status fw-crm-case-status--%4$s">%5$s</span></li>',
				esc_url( add_query_arg( array( 'tab' => 'cases', 'case' => (int) $c->id ), $base ) ),
				(int) $c->id,
				esc_html( $c->title ),
				esc_attr( $c->status ),
				esc_html( $statuses[ $c->status ]['label'] ?? $c->status )
			);
		}
		echo '</ul>';
	}

	/** @param string $current */
	private static function priority_select( $current ) {
		?>
		<label class="fw-crm-form__row"><span><?php esc_html_e( 'Priority', 'fw' ); ?></span>
			<select name="priority">
				<?php foreach ( FW_CRM_Cases::priorities() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $current, $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/**
	 * @param int  $current
	 * @param bool $allow_none Offer "Unassigned".
	 */
	private static function owner_select( $current, $allow_none = true ) {
		?>
		<label class="fw-crm-form__row"><span><?php esc_html_e( 'Owner', 'fw' ); ?></span>
			<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
				<select name="owner_id">
					<?php if ( $allow_none ) : ?>
						<option value="0"><?php esc_html_e( 'Unassigned', 'fw' ); ?></option>
					<?php endif; ?>
					<?php foreach ( self::team() as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $current, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<?php $u = get_userdata( (int) $current ); ?>
				<span class="fw-crm-readonly"><?php echo esc_html( $u ? $u->display_name : __( 'Unassigned', 'fw' ) ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/** @return WP_User[] People who can work in the CRM. */
	private static function team() {
		return get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) );
	}

	/**
	 * @param object $contact
	 *
	 * @return string
	 */
	private static function contact_name( $contact ) {
		$n = trim( $contact->first_name . ' ' . $contact->last_name );
		return '' !== $n ? $n : (string) ( $contact->email ?: $contact->phone );
	}

	/**
	 * "3 days", "today".
	 *
	 * @param object $c
	 *
	 * @return string
	 */
	private static function age_label( $c ) {
		$d = FW_CRM_Cases::age_days( $c );
		return $d ? sprintf( _n( '%s day', '%s days', $d, 'fw' ), number_format_i18n( $d ) ) : __( 'Today', 'fw' );
	}
}
