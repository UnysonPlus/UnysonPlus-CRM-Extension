<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Deals: the pipeline board, the deal page, the new-deal form and the
 * pipeline editor. Plugs into the CRM app through its extension points
 * (unysonplus_crm_render_view, unysonplus_crm_handle_action, the record panels).
 *
 * URLs: tab=deals (board, &pipeline=<id>), &deal=<id>, &new=1 (&contact= / &company=
 * prefill), &pipelines=1 (editor, managers).
 */
class FW_CRM_Admin_Deals {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'deals' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		add_action( 'wp_ajax_fw_crm_deal_move', array( __CLASS__, '_ajax_move' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, '_enqueue' ) );
		add_filter( 'unysonplus_crm_crumbs', array( __CLASS__, '_crumbs' ), 10, 2 );

		// Deals on contact and company pages.
		foreach ( array( 'contact', 'company' ) as $object ) {
			add_filter( 'unysonplus_crm_' . $object . '_panels', function ( $panels, $record ) use ( $object ) {
				$id               = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
				$panels['deals']  = array(
					'label'    => __( 'Deals', 'fw' ),
					'priority' => 4,
					'column'   => 'side',
					'callback' => function () use ( $object, $id ) {
						self::render_record_deals( $object, $id );
					},
				);
				return $panels;
			}, 4, 2 );
		}
	}

	/** @internal */
	public static function _enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( (string) $hook, 'fw-crm' ) || ! isset( $_GET['tab'] ) || 'deals' !== $_GET['tab'] ) {
			return;
		}
		$uri = fw_ext( 'crm' )->get_uri( '/modules/pipeline/static' );
		$ver = fw_ext( 'crm' )->manifest->get_version();
		wp_enqueue_style( 'fw-crm-board', $uri . '/css/board.css', array( 'fw-ext-crm-app' ), $ver );
		wp_enqueue_script( 'fw-crm-board', $uri . '/js/board.js', array(), $ver, true );
	}

	/**
	 * Breadcrumbs for deal pages.
	 *
	 * @internal
	 */
	public static function _crumbs( $crumbs, $tab ) {
		if ( 'deals' !== $tab ) {
			return $crumbs;
		}
		$base = add_query_arg( 'tab', 'deals', FW_CRM_Admin_Page::get_page_url() );
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['deal'] ) && ( $d = FW_CRM_Deals::find( (int) $_GET['deal'] ) ) ) {
			return array( array( __( 'Deals', 'fw' ), $base ), array( $d->title ) );
		}
		if ( ! empty( $_GET['new'] ) ) {
			return array( array( __( 'Deals', 'fw' ), $base ), array( __( 'New deal', 'fw' ) ) );
		}
		if ( ! empty( $_GET['pipelines'] ) ) {
			return array( array( __( 'Deals', 'fw' ), $base ), array( __( 'Pipelines', 'fw' ) ) );
		}
		if ( ! empty( $_GET['products'] ) ) {
			return array( array( __( 'Deals', 'fw' ), $base ), array( __( 'Products', 'fw' ) ) );
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
		if ( null !== $args || ( 0 !== strpos( (string) $action, 'deal_' ) && 0 !== strpos( (string) $action, 'pipeline_' ) ) ) {
			return $args;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id = isset( $_REQUEST['deal'] ) ? (int) $_REQUEST['deal'] : 0;
		if ( $id && ! FW_CRM_Capabilities::can_edit_deal( $id ) ) {
			wp_die( esc_html__( 'That deal belongs to someone else.', 'fw' ), 403 );
		}
		if ( 0 === strpos( $action, 'pipeline_' ) && ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		$page = array( 'tab' => 'deals', 'deal' => $id );

		switch ( $action ) {
			case 'deal_create':
				$data = self::posted();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					$data['owner_id'] = get_current_user_id();
				}
				$new = FW_CRM_Deals::create( $data );
				if ( is_wp_error( $new ) ) {
					FW_CRM_Admin_Page::flash( 'error', $new->get_error_message() );
					return array( 'tab' => 'deals', 'new' => 1 );
				}
				FW_CRM_Admin_Page::flash( 'success', __( 'Deal created.', 'fw' ) );
				return array( 'tab' => 'deals', 'deal' => $new );

			case 'deal_save':
				$data = self::posted();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					unset( $data['owner_id'] );
				}
				$res = FW_CRM_Deals::update( $id, $data );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Deal saved.', 'fw' ) );
				return $page;

			case 'deal_move':
				$stage = isset( $_REQUEST['stage'] ) ? (int) $_REQUEST['stage'] : 0;
				$res   = FW_CRM_Deals::move( $id, $stage, isset( $_POST['lost_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['lost_reason'] ) ) : '' );
				if ( is_wp_error( $res ) ) {
					FW_CRM_Admin_Page::flash( 'error', $res->get_error_message() );
				}
				return $page;

			case 'deal_win':
				FW_CRM_Deals::win( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Deal won. Nice work.', 'fw' ) );
				return $page;

			case 'deal_lose':
				FW_CRM_Deals::lose( $id, isset( $_POST['lost_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['lost_reason'] ) ) : '' );
				FW_CRM_Admin_Page::flash( 'success', __( 'Deal marked lost.', 'fw' ) );
				return $page;

			case 'deal_add_contact':
				$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
				$contact = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;
				if ( ! $contact || ! FW_CRM_Capabilities::can_edit_contact( $contact ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'No contact of yours has that email address. Add them as a contact first.', 'fw' ) );
				} else {
					FW_CRM_Deals::add_contact( $id, (int) $contact->id );
				}
				return $page;

			case 'deal_remove_contact':
				FW_CRM_Deals::remove_contact( $id, isset( $_REQUEST['contact'] ) ? (int) $_REQUEST['contact'] : 0 );
				return $page;

			case 'deal_trash':
				FW_CRM_Deals::trash( $id );
				FW_CRM_Admin_Page::flash( 'success', sprintf(
					'%s <a href="%s">%s</a>',
					esc_html__( 'Deal moved to the bin.', 'fw' ),
					esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'deals', 'fw_crm_action' => 'deal_restore', 'deal' => $id ), FW_CRM_Admin_Page::get_page_url() ), FW_CRM_Admin_Page::NONCE ) ),
					esc_html__( 'Undo', 'fw' )
				) );
				return array( 'tab' => 'deals' );

			case 'deal_restore':
				FW_CRM_Deals::restore( $id );
				return $page;

			case 'deal_purge':
				if ( ! FW_CRM_Capabilities::can_manage() ) {
					wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
				}
				FW_CRM_Deals::purge( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Deal deleted for good.', 'fw' ) );
				return array( 'tab' => 'bin' );

			case 'pipeline_save':
				$pid    = isset( $_POST['pipeline_id'] ) ? (int) $_POST['pipeline_id'] : 0;
				$stages = array();
				$raw    = isset( $_POST['stages'] ) && is_array( $_POST['stages'] ) ? wp_unslash( $_POST['stages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by FW_CRM_Pipelines::save().
				foreach ( $raw as $s ) {
					$stages[] = array(
						'id'          => (int) ( $s['id'] ?? 0 ),
						'name'        => (string) ( $s['name'] ?? '' ),
						'probability' => (int) ( $s['probability'] ?? 0 ),
						'rot_days'    => (int) ( $s['rot_days'] ?? 0 ),
					);
				}
				$res = FW_CRM_Pipelines::save( array(
					'name'       => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'currency'   => isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : '',
					'is_default' => ! empty( $_POST['is_default'] ),
					'won_name'   => isset( $_POST['won_name'] ) ? wp_unslash( $_POST['won_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'lost_name'  => isset( $_POST['lost_name'] ) ? wp_unslash( $_POST['lost_name'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'stages'     => $stages,
				), $pid );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Pipeline saved.', 'fw' ) );
				return array( 'tab' => 'deals', 'pipelines' => 1, 'edit' => is_wp_error( $res ) ? $pid : $res );

			case 'pipeline_delete':
				$res = FW_CRM_Pipelines::delete( isset( $_REQUEST['pipeline_id'] ) ? (int) $_REQUEST['pipeline_id'] : 0 );
				FW_CRM_Admin_Page::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : __( 'Pipeline deleted.', 'fw' ) );
				return array( 'tab' => 'deals', 'pipelines' => 1 );
		}
		// phpcs:enable

		return $args;
	}

	/** @return array Deal fields from the posted form. */
	private static function posted() {
		// phpcs:disable WordPress.Security.NonceVerification
		$data = array();
		foreach ( array( 'title', 'value', 'currency', 'expected_close', 'probability', 'lost_reason' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		foreach ( array( 'owner_id', 'company_id', 'contact_id', 'pipeline_id', 'stage_id' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = (int) $_POST[ $k ];
			}
		}
		if ( isset( $data['probability'] ) && '' === $data['probability'] ) {
			$data['probability'] = null;
		}
		// phpcs:enable
		return $data;
	}

	/** @internal admin-ajax: a card dropped on the board. */
	public static function _ajax_move() {
		check_ajax_referer( 'fw_crm_deal_move' );
		$id    = isset( $_POST['deal'] ) ? (int) $_POST['deal'] : 0;
		$stage = isset( $_POST['stage'] ) ? (int) $_POST['stage'] : 0;
		if ( ! current_user_can( FW_CRM_Service::capability() ) || ! FW_CRM_Capabilities::can_edit_deal( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That deal belongs to someone else.', 'fw' ) ), 403 );
		}
		$deal = FW_CRM_Deals::find( $id );
		if ( ! $deal ) {
			wp_send_json_error( array( 'message' => __( 'Deal not found.', 'fw' ) ), 404 );
		}
		$res = FW_CRM_Deals::move( $id, $stage, isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		if ( isset( $_POST['order'] ) ) {
			$order = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['order'] ) ) ) ) );
			$order = array_filter( $order, array( 'FW_CRM_Capabilities', 'can_edit_deal' ) );
			FW_CRM_Deals::reorder( $order );
		}
		$totals = array();
		foreach ( FW_CRM_Deals::totals_by_stage( (int) $deal->pipeline_id, self::board_args() ) as $sid => $t ) {
			$totals[ $sid ] = array( 'count' => $t['count'], 'value' => FW_CRM_Deals::money( $t['value'], $deal->currency ) );
		}
		wp_send_json_success( array( 'totals' => $totals ) );
	}

	/** @return array Board filters for the current person. */
	private static function board_args() {
		// phpcs:disable WordPress.Security.NonceVerification
		$args = array();
		if ( ! FW_CRM_Capabilities::sees_all() || ( isset( $_REQUEST['mine'] ) && '1' === $_REQUEST['mine'] ) ) {
			$args['owner'] = get_current_user_id();
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
		if ( ! empty( $_GET['deal'] ) ) {
			self::render_deal( (int) $_GET['deal'] );
		} elseif ( ! empty( $_GET['new'] ) ) {
			self::render_new();
		} elseif ( ! empty( $_GET['products'] ) ) {
			FW_CRM_Admin_Products::render_list( isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '' );
		} elseif ( ! empty( $_GET['pipelines'] ) ) {
			if ( FW_CRM_Capabilities::can_manage() ) {
				self::render_pipelines( isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '' );
			} else {
				FW_CRM_App::empty_state( 'dashicons-lock', __( 'For CRM managers', 'fw' ), __( 'Pipelines are set up by CRM managers and administrators.', 'fw' ) );
			}
		} else {
			self::render_board();
		}
		// phpcs:enable
	}

	/** The board. */
	private static function render_board() {
		// phpcs:disable WordPress.Security.NonceVerification
		$pipeline = ! empty( $_GET['pipeline'] ) ? FW_CRM_Pipelines::find( (int) $_GET['pipeline'] ) : null;
		$mine     = isset( $_GET['mine'] ) && '1' === $_GET['mine'];
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$pipeline = $pipeline ? $pipeline : FW_CRM_Pipelines::default_pipeline();
		$stages   = FW_CRM_Pipelines::stages( (int) $pipeline->id );
		$args     = self::board_args();
		$base     = FW_CRM_Admin_Page::get_page_url();
		$totals   = FW_CRM_Deals::totals_by_stage( (int) $pipeline->id, $args );
		$deals    = FW_CRM_Deals::query( array_merge( $args, array( 'pipeline' => (int) $pipeline->id, 'status' => 'open' ) ) );
		$by_stage = array();
		foreach ( $deals as $d ) {
			$by_stage[ (int) $d->stage_id ][] = $d;
		}
		$currency = FW_CRM_Pipelines::currency( $pipeline );
		$open_sum = 0;
		$weighted = 0;
		foreach ( $stages as $s ) {
			if ( 'open' === $s->kind ) {
				$open_sum += $totals[ (int) $s->id ]['value'];
				$weighted += $totals[ (int) $s->id ]['weighted'];
			}
		}
		$recent = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$won    = FW_CRM_Deals::query( array_merge( $args, array( 'pipeline' => (int) $pipeline->id, 'status' => 'won', 'closed_after' => $recent ) ) );
		$lost   = FW_CRM_Deals::count( array_merge( $args, array( 'pipeline' => (int) $pipeline->id, 'status' => 'lost', 'closed_after' => $recent ) ) );
		$won_v  = array_sum( array_map( function ( $d ) {
			return (float) $d->value;
		}, $won ) );
		?>
		<div class="fw-crm-listbar fw-crm-board-bar">
			<form method="get" action="" class="fw-crm-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="deals" />
				<?php $pipelines = FW_CRM_Pipelines::all(); ?>
				<?php if ( count( $pipelines ) > 1 ) : ?>
					<label class="screen-reader-text" for="fw-crm-pipeline"><?php esc_html_e( 'Pipeline', 'fw' ); ?></label>
					<select id="fw-crm-pipeline" name="pipeline" onchange="this.form.submit()">
						<?php foreach ( $pipelines as $p ) : ?>
							<option value="<?php echo (int) $p->id; ?>" <?php selected( (int) $p->id, (int) $pipeline->id ); ?>><?php echo esc_html( $p->name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="hidden" name="pipeline" value="<?php echo (int) $pipeline->id; ?>" />
				<?php endif; ?>
				<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
					<label class="fw-crm-check"><input type="checkbox" name="mine" value="1" <?php checked( $mine ); ?> onchange="this.form.submit()" /> <?php esc_html_e( 'Only my deals', 'fw' ); ?></label>
				<?php endif; ?>
				<label class="screen-reader-text" for="fw-crm-deal-s"><?php esc_html_e( 'Search deals', 'fw' ); ?></label>
				<input type="search" id="fw-crm-deal-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search deals', 'fw' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fw' ); ?></button>
			</form>
			<div class="fw-crm-inline">
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'products' => 1 ), $base ) ); ?>"><?php esc_html_e( 'Products', 'fw' ); ?></a>
				<?php if ( FW_CRM_Capabilities::can_manage() ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'pipelines' => 1 ), $base ) ); ?>"><?php esc_html_e( 'Edit pipelines', 'fw' ); ?></a>
				<?php endif; ?>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'new' => 1, 'pipeline' => (int) $pipeline->id ), $base ) ); ?>"><?php esc_html_e( 'New deal', 'fw' ); ?></a>
			</div>
		</div>

		<p class="fw-crm-status-line">
			<?php
			echo esc_html( sprintf(
				/* translators: 1: number of deals, 2: total value, 3: weighted forecast, 4: won value, 5: lost count */
				__( '%1$s open deals worth %2$s (%3$s weighted by stage). Last 30 days: %4$s won, %5$s lost.', 'fw' ),
				number_format_i18n( count( $deals ) ),
				FW_CRM_Deals::money( $open_sum, $currency ),
				FW_CRM_Deals::money( $weighted, $currency ),
				FW_CRM_Deals::money( $won_v, $currency ),
				number_format_i18n( $lost )
			) );
			?>
		</p>

		<div class="fw-crm-board" id="fw-crm-board"
		     data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		     data-nonce="<?php echo esc_attr( wp_create_nonce( 'fw_crm_deal_move' ) ); ?>"
		     data-mine="<?php echo $mine ? '1' : '0'; ?>"
		     data-i18n="<?php echo esc_attr( wp_json_encode( array( 'failed' => __( 'That move could not be saved. The board was put back.', 'fw' ), 'moved' => __( '%1$s moved to %2$s.', 'fw' ) ) ) ); ?>">
			<?php foreach ( $stages as $s ) : ?>
				<?php
				if ( 'open' !== $s->kind ) {
					continue;
				}
				$t = $totals[ (int) $s->id ];
				?>
				<section class="fw-crm-board__col" data-stage="<?php echo (int) $s->id; ?>" aria-labelledby="fw-crm-stage-<?php echo (int) $s->id; ?>">
					<header class="fw-crm-board__head">
						<h2 id="fw-crm-stage-<?php echo (int) $s->id; ?>"><?php echo esc_html( $s->name ); ?></h2>
						<p class="fw-crm-board__sum">
							<span class="fw-crm-board__value"><?php echo esc_html( FW_CRM_Deals::money( $t['value'], $currency ) ); ?></span>
							<span class="fw-crm-muted"><span class="fw-crm-board__count"><?php echo esc_html( number_format_i18n( $t['count'] ) ); ?></span> · <?php echo esc_html( (int) $s->probability . '%' ); ?></span>
						</p>
					</header>
					<ol class="fw-crm-board__list">
						<?php foreach ( $by_stage[ (int) $s->id ] ?? array() as $d ) : ?>
							<?php self::render_card( $d, $s ); ?>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endforeach; ?>
			<div class="fw-crm-board__close">
				<?php foreach ( array( 'won', 'lost' ) as $kind ) : ?>
					<?php $st = FW_CRM_Pipelines::stage_of_kind( (int) $pipeline->id, $kind ); ?>
					<?php if ( $st ) : ?>
						<div class="fw-crm-board__drop fw-crm-board__drop--<?php echo esc_attr( $kind ); ?>" data-stage="<?php echo (int) $st->id; ?>" data-kind="<?php echo esc_attr( $kind ); ?>">
							<span class="dashicons <?php echo 'won' === $kind ? 'dashicons-awards' : 'dashicons-dismiss'; ?>" aria-hidden="true"></span>
							<span><?php echo 'won' === $kind ? esc_html__( 'Drop here: won', 'fw' ) : esc_html__( 'Drop here: lost', 'fw' ); ?></span>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>

		<dialog class="fw-crm-lost-dialog" id="fw-crm-lost-dialog">
			<form method="dialog">
				<h2><?php esc_html_e( 'Why was it lost?', 'fw' ); ?></h2>
				<label class="screen-reader-text" for="fw-crm-lost-reason"><?php esc_html_e( 'Lost reason', 'fw' ); ?></label>
				<select id="fw-crm-lost-reason">
					<?php foreach ( FW_CRM_Pipelines::lost_reasons() as $r ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $r ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="fw-crm-form__foot">
					<button value="ok" class="button button-primary"><?php esc_html_e( 'Mark lost', 'fw' ); ?></button>
					<button value="cancel" class="button"><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
				</p>
			</form>
		</dialog>

		<?php if ( ! $deals && ! $won && ! $lost && '' === $search ) : ?>
			<p class="fw-crm-muted fw-crm-board-empty"><?php esc_html_e( 'No deals yet. A deal is a sale you are working on: give it a value and a close date, and move it through the stages as it progresses. Start one with New deal, or from a contact\'s page.', 'fw' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param object $d
	 * @param object $stage
	 */
	private static function render_card( $d, $stage ) {
		$base    = FW_CRM_Admin_Page::get_page_url();
		$contact = (int) $d->contact_id ? FW_CRM_Subscribers::find( (int) $d->contact_id ) : null;
		$company = (int) $d->company_id ? FW_CRM_Companies::find( (int) $d->company_id ) : null;
		$owner   = (int) $d->owner_id ? get_userdata( (int) $d->owner_id ) : null;
		$who     = $company ? $company->name : ( $contact ? ( trim( $contact->first_name . ' ' . $contact->last_name ) ?: (string) $contact->email ) : '' );
		$stalled = FW_CRM_Deals::is_stalled( $d, $stage );
		$late    = $d->expected_close && strtotime( $d->expected_close ) < strtotime( current_time( 'Y-m-d' ) );
		?>
		<li class="fw-crm-deal-card<?php echo $stalled ? ' is-stalled' : ''; ?>" draggable="true" data-deal="<?php echo (int) $d->id; ?>" data-title="<?php echo esc_attr( $d->title ); ?>">
			<a class="fw-crm-deal-card__title" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'deal' => (int) $d->id ), $base ) ); ?>"><?php echo esc_html( $d->title ); ?></a>
			<?php if ( '' !== $who ) : ?>
				<span class="fw-crm-deal-card__who"><?php echo esc_html( $who ); ?></span>
			<?php endif; ?>
			<span class="fw-crm-deal-card__meta">
				<strong><?php echo esc_html( FW_CRM_Deals::money( $d->value, $d->currency ) ); ?></strong>
				<?php if ( $d->expected_close ) : ?>
					<span class="<?php echo $late ? 'is-late' : ''; ?>"><?php echo esc_html( date_i18n( 'j M', strtotime( $d->expected_close ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $owner ) : ?>
					<?php echo FW_CRM_App::avatar( $owner->display_name, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>
			</span>
			<?php if ( $stalled ) : ?>
				<span class="fw-crm-deal-card__flag"><?php echo esc_html( sprintf( __( 'Stalled: no move in %s days', 'fw' ), number_format_i18n( (int) $stage->rot_days ) ) ); ?></span>
			<?php endif; ?>
		</li>
		<?php
	}

	/** New deal form. */
	private static function render_new() {
		// phpcs:disable WordPress.Security.NonceVerification
		$pipeline = ! empty( $_GET['pipeline'] ) ? FW_CRM_Pipelines::find( (int) $_GET['pipeline'] ) : null;
		$contact  = ! empty( $_GET['contact'] ) ? FW_CRM_Subscribers::find( (int) $_GET['contact'] ) : null;
		$company  = ! empty( $_GET['company'] ) ? FW_CRM_Companies::find( (int) $_GET['company'] ) : null;
		// phpcs:enable
		$pipeline = $pipeline ? $pipeline : FW_CRM_Pipelines::default_pipeline();
		if ( $contact && ! $company && (int) $contact->company_id ) {
			$company = FW_CRM_Companies::find( (int) $contact->company_id );
		}
		$title = $company ? $company->name : ( $contact ? trim( $contact->first_name . ' ' . $contact->last_name ) : '' );
		?>
		<form method="post" action="" class="fw-crm-panel fw-crm-form fw-crm-form--page">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="deal_create" />
			<?php if ( $contact ) : ?>
				<input type="hidden" name="contact_id" value="<?php echo (int) $contact->id; ?>" />
			<?php endif; ?>
			<div class="fw-crm-form__grid">
				<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'Deal name', 'fw' ); ?></span>
					<input type="text" name="title" required autofocus value="<?php echo esc_attr( '' !== $title ? sprintf( __( '%s deal', 'fw' ), $title ) : '' ); ?>" />
				</label>
				<label class="fw-crm-form__row"><span><?php echo esc_html( sprintf( __( 'Value (%s)', 'fw' ), FW_CRM_Pipelines::currency( $pipeline ) ) ); ?></span>
					<input type="number" name="value" min="0" step="0.01" inputmode="decimal" />
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Expected to close', 'fw' ); ?></span>
					<input type="date" name="expected_close" />
				</label>
				<?php self::pipeline_stage_selects( (int) $pipeline->id, 0 ); ?>
				<?php self::company_select( $company ? (int) $company->id : 0 ); ?>
				<?php self::owner_select( get_current_user_id() ); ?>
			</div>
			<?php if ( $contact ) : ?>
				<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'The deal is linked to %s.', 'fw' ), trim( $contact->first_name . ' ' . $contact->last_name ) ?: $contact->email ) ); ?></p>
			<?php endif; ?>
			<p class="fw-crm-form__foot">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create deal', 'fw' ); ?></button>
				<a class="button" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'deals' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * @param int $pipeline_id
	 * @param int $stage_id
	 */
	private static function pipeline_stage_selects( $pipeline_id, $stage_id ) {
		?>
		<label class="fw-crm-form__row"><span><?php esc_html_e( 'Stage', 'fw' ); ?></span>
			<select name="stage_id">
				<?php foreach ( FW_CRM_Pipelines::all() as $p ) : ?>
					<optgroup label="<?php echo esc_attr( $p->name ); ?>">
						<?php foreach ( FW_CRM_Pipelines::stages( (int) $p->id ) as $s ) : ?>
							<?php if ( 'open' === $s->kind || (int) $s->id === (int) $stage_id ) : ?>
								<option value="<?php echo (int) $s->id; ?>" <?php selected( $stage_id ? (int) $stage_id : ( (int) $p->id === (int) $pipeline_id ? (int) FW_CRM_Pipelines::stage_of_kind( $pipeline_id, 'open' )->id : 0 ), (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</label>
		<input type="hidden" name="pipeline_id" value="<?php echo (int) $pipeline_id; ?>" />
		<?php
	}

	/** @param int $current */
	private static function company_select( $current ) {
		if ( ! FW_CRM_Modules::enabled( 'companies' ) ) {
			return;
		}
		?>
		<label class="fw-crm-form__row"><span><?php esc_html_e( 'Company', 'fw' ); ?></span>
			<select name="company_id">
				<option value="0"><?php esc_html_e( 'No company', 'fw' ); ?></option>
				<?php foreach ( FW_CRM_Companies::query( FW_CRM_Capabilities::scope( array( 'per_page' => 500 ) ) ) as $c ) : ?>
					<option value="<?php echo (int) $c->id; ?>" <?php selected( (int) $current, (int) $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	/** @param int $current */
	private static function owner_select( $current ) {
		?>
		<label class="fw-crm-form__row"><span><?php esc_html_e( 'Owner', 'fw' ); ?></span>
			<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
				<select name="owner_id">
					<?php foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $current, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<?php $u = get_userdata( (int) $current ); ?>
				<span class="fw-crm-readonly"><?php echo esc_html( $u ? $u->display_name : wp_get_current_user()->display_name ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * The deal page.
	 *
	 * @param int $id
	 */
	private static function render_deal( $id ) {
		$d = FW_CRM_Deals::find( $id );
		if ( ! $d ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Deal not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to deals', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'deals' ) ) ) );
			return;
		}
		if ( ! FW_CRM_Capabilities::can_edit_deal( $d ) ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'This deal belongs to someone else', 'fw' ), __( 'Sales reps see the deals they own. Ask a CRM manager if it should be yours.', 'fw' ), array( array( __( 'Back to deals', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'deals' ) ) ) );
			return;
		}
		$deal     = FW_CRM_Deals::get( $id );
		$base     = FW_CRM_Admin_Page::get_page_url();
		$stages   = FW_CRM_Pipelines::stages( (int) $d->pipeline_id );
		$pipeline = FW_CRM_Pipelines::find( (int) $d->pipeline_id );
		$company  = (int) $d->company_id ? FW_CRM_Companies::find( (int) $d->company_id ) : null;
		$owner    = (int) $d->owner_id ? get_userdata( (int) $d->owner_id ) : null;
		$action   = function ( $name, $extra = array() ) use ( $id, $base ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'tab' => 'deals', 'fw_crm_action' => $name, 'deal' => $id ), $extra ), $base ), FW_CRM_Admin_Page::NONCE );
		};
		if ( $d->deleted_at ) {
			?>
			<div class="fw-crm-binned" role="status">
				<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				<p><strong><?php esc_html_e( 'This deal is in the bin.', 'fw' ); ?></strong></p>
				<a class="button button-primary" href="<?php echo esc_url( $action( 'deal_restore' ) ); ?>"><?php esc_html_e( 'Restore', 'fw' ); ?></a>
			</div>
			<?php
		}
		?>
		<div class="fw-crm-record fw-crm-deal">
			<section class="fw-crm-panel fw-crm-record__head fw-crm-deal__head">
				<span class="fw-crm-deal__icon" aria-hidden="true"><span class="dashicons dashicons-money-alt"></span></span>
				<div class="fw-crm-record__id">
					<h2><?php echo esc_html( $d->title ); ?></h2>
					<p class="fw-crm-record__sub">
						<strong class="fw-crm-deal__value"><?php echo esc_html( FW_CRM_Deals::money( $d->value, $d->currency ) ); ?></strong>
						<span aria-hidden="true">·</span>
						<?php echo esc_html( sprintf( __( '%s%% likely', 'fw' ), number_format_i18n( $deal['probability'] ) ) ); ?>
						<?php if ( $d->expected_close ) : ?>
							<span aria-hidden="true">·</span>
							<?php echo esc_html( sprintf( __( 'Expected to close %s', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( $d->expected_close ) ) ) ); ?>
						<?php endif; ?>
					</p>
					<ul class="fw-crm-record__chips">
						<?php if ( $company && FW_CRM_Modules::enabled( 'companies' ) ) : ?>
							<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'companies', 'company' => (int) $company->id ), $base ) ); ?>"><span class="dashicons dashicons-building" aria-hidden="true"></span><?php echo esc_html( $company->name ); ?></a></li>
						<?php endif; ?>
						<li><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo $owner ? esc_html( sprintf( __( 'Owner: %s', 'fw' ), $owner->display_name ) ) : esc_html__( 'No owner', 'fw' ); ?></li>
						<li><span class="dashicons dashicons-randomize" aria-hidden="true"></span><?php echo esc_html( $pipeline ? $pipeline->name : '' ); ?></li>
					</ul>
				</div>
				<div class="fw-crm-record__actions">
					<span class="fw-crm-status fw-crm-status--<?php echo esc_attr( 'won' === $d->status ? 'sent' : ( 'lost' === $d->status ? 'bounced' : 'sending' ) ); ?>"><?php echo esc_html( 'won' === $d->status ? __( 'Won', 'fw' ) : ( 'lost' === $d->status ? __( 'Lost', 'fw' ) : __( 'Open', 'fw' ) ) ); ?></span>
					<?php do_action( 'unysonplus_crm_record_actions', 'deal', $d ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in the contact page. ?>
					<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
				</div>
			</section>

			<nav class="fw-crm-panel fw-crm-stagepath" aria-label="<?php esc_attr_e( 'Deal stage', 'fw' ); ?>">
				<ol>
					<?php
					$reached = true;
					foreach ( $stages as $s ) :
						if ( 'open' !== $s->kind ) {
							continue;
						}
						$current = (int) $s->id === (int) $d->stage_id;
						?>
						<li class="<?php echo $current ? 'is-current' : ( $reached && 'open' === $d->status ? 'is-done' : '' ); ?>">
							<?php if ( $current ) : ?>
								<span aria-current="step"><?php echo esc_html( $s->name ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $action( 'deal_move', array( 'stage' => (int) $s->id ) ) ); ?>"><?php echo esc_html( $s->name ); ?></a>
							<?php endif; ?>
						</li>
						<?php
						if ( $current ) {
							$reached = false;
						}
					endforeach;
					?>
				</ol>
				<div class="fw-crm-stagepath__close">
					<?php if ( 'open' === $d->status ) : ?>
						<a class="button button-primary fw-crm-btn-won" href="<?php echo esc_url( $action( 'deal_win' ) ); ?>"><span class="dashicons dashicons-awards" aria-hidden="true"></span><?php esc_html_e( 'Won', 'fw' ); ?></a>
						<details class="fw-crm-lose">
							<summary class="button"><?php esc_html_e( 'Lost', 'fw' ); ?></summary>
							<form method="post" action="" class="fw-crm-lose__form">
								<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
								<input type="hidden" name="fw_crm_action" value="deal_lose" />
								<input type="hidden" name="deal" value="<?php echo (int) $id; ?>" />
								<label for="fw-crm-lose-reason"><?php esc_html_e( 'Why was it lost?', 'fw' ); ?></label>
								<select id="fw-crm-lose-reason" name="lost_reason">
									<?php foreach ( FW_CRM_Pipelines::lost_reasons() as $r ) : ?>
										<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $r ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button"><?php esc_html_e( 'Mark lost', 'fw' ); ?></button>
							</form>
						</details>
					<?php else : ?>
						<p class="fw-crm-muted">
							<?php
							echo esc_html( 'won' === $d->status
								? sprintf( __( 'Won on %s.', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( (string) $d->closed_at ) ) )
								: sprintf( __( 'Lost on %1$s%2$s.', 'fw' ), date_i18n( get_option( 'date_format' ), strtotime( (string) $d->closed_at ) ), '' !== $d->lost_reason ? ': ' . $d->lost_reason : '' ) );
							?>
						</p>
						<a class="button" href="<?php echo esc_url( $action( 'deal_move', array( 'stage' => (int) FW_CRM_Pipelines::stage_of_kind( (int) $d->pipeline_id, 'open' )->id ) ) ); ?>"><?php esc_html_e( 'Reopen', 'fw' ); ?></a>
					<?php endif; ?>
				</div>
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
								<dt><?php esc_html_e( 'Value', 'fw' ); ?></dt><dd><?php echo esc_html( FW_CRM_Deals::money( $d->value, $d->currency ) ); ?></dd>
								<dt><?php esc_html_e( 'Weighted', 'fw' ); ?></dt><dd><?php echo esc_html( FW_CRM_Deals::money( $deal['weighted_value'], $d->currency ) ); ?></dd>
								<dt><?php esc_html_e( 'Probability', 'fw' ); ?></dt><dd><?php echo esc_html( $deal['probability'] . '%' . ( $deal['probability_set'] ? '' : ' ' . __( '(from the stage)', 'fw' ) ) ); ?></dd>
								<?php if ( $d->expected_close ) : ?>
									<dt><?php esc_html_e( 'Close date', 'fw' ); ?></dt><dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $d->expected_close ) ) ); ?></dd>
								<?php endif; ?>
								<dt><?php esc_html_e( 'Created', 'fw' ); ?></dt><dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $d->created_at ) ) ); ?></dd>
								<dt><?php esc_html_e( 'In this stage', 'fw' ); ?></dt><dd><?php echo esc_html( human_time_diff( strtotime( (string) ( $d->stage_changed_at ?: $d->created_at ) ), current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp ?></dd>
							</dl>
						</div>
						<form method="post" action="" class="fw-crm-form" data-edit hidden>
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="deal_save" />
							<input type="hidden" name="deal" value="<?php echo (int) $id; ?>" />
							<div class="fw-crm-form__grid">
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Deal name', 'fw' ); ?></span><input type="text" name="title" required value="<?php echo esc_attr( $d->title ); ?>" /></label>
								<?php $has_items = class_exists( 'FW_CRM_Products' ) && FW_CRM_Products::totals( $id )['count']; ?>
								<label class="fw-crm-form__row"><span><?php echo $has_items ? esc_html__( 'Value (from the products)', 'fw' ) : esc_html__( 'Value', 'fw' ); ?></span><input type="number" name="value" min="0" step="0.01" value="<?php echo esc_attr( (string) (float) $d->value ); ?>" <?php echo $has_items ? 'readonly' : ''; ?> /></label>
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Currency', 'fw' ); ?></span><input type="text" name="currency" maxlength="3" value="<?php echo esc_attr( $d->currency ); ?>" /></label>
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Expected to close', 'fw' ); ?></span><input type="date" name="expected_close" value="<?php echo esc_attr( (string) $d->expected_close ); ?>" /></label>
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Probability % (empty = from the stage)', 'fw' ); ?></span><input type="number" name="probability" min="0" max="100" value="<?php echo null === $d->probability ? '' : (int) $d->probability; ?>" /></label>
								<?php self::company_select( (int) $d->company_id ); ?>
								<?php self::owner_select( (int) $d->owner_id ); ?>
							</div>
							<p class="fw-crm-form__foot">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'fw' ); ?></button>
								<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
							</p>
						</form>
					</section>
				</div>

				<div class="fw-crm-record__col fw-crm-record__col--wide">
					<?php FW_CRM_App::render_panels( 'deal', $d, 'main' ); ?>
					<?php FW_CRM_Admin_Contacts::render_timeline( 'deal', $id ); ?>
				</div>

				<div class="fw-crm-record__col">
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-dealpeople">
						<header class="fw-crm-panel__head"><h2 id="fw-crm-h-dealpeople"><?php esc_html_e( 'People', 'fw' ); ?></h2></header>
						<?php $people = array_filter( array_map( array( 'FW_CRM_Subscribers', 'find' ), $deal['contact_ids'] ) ); ?>
						<?php if ( $people ) : ?>
							<ul class="fw-crm-people">
								<?php foreach ( $people as $p ) : ?>
									<?php $pn = trim( $p->first_name . ' ' . $p->last_name ); ?>
									<li>
										<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $p->id ), $base ) ); ?>">
											<?php echo FW_CRM_App::avatar( '' !== $pn ? $pn : (string) $p->email, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<span class="fw-crm-people__text">
												<span class="fw-crm-people__name"><?php echo esc_html( '' !== $pn ? $pn : (string) $p->email ); ?></span>
												<span class="fw-crm-people__sub"><?php echo (int) $p->id === (int) $d->contact_id ? esc_html__( 'Main contact', 'fw' ) : esc_html( (string) $p->job_title ); ?></span>
											</span>
										</a>
										<a class="fw-crm-unlink" href="<?php echo esc_url( $action( 'deal_remove_contact', array( 'contact' => (int) $p->id ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s from the deal', 'fw' ), '' !== $pn ? $pn : $p->email ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="fw-crm-muted"><?php esc_html_e( 'Nobody is on this deal yet.', 'fw' ); ?></p>
						<?php endif; ?>
						<form method="post" action="" class="fw-crm-inline">
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="deal_add_contact" />
							<input type="hidden" name="deal" value="<?php echo (int) $id; ?>" />
							<label class="screen-reader-text" for="fw-crm-deal-person"><?php esc_html_e( 'Email of a contact', 'fw' ); ?></label>
							<input type="email" id="fw-crm-deal-person" name="email" required placeholder="<?php esc_attr_e( 'Email of a contact', 'fw' ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Add', 'fw' ); ?></button>
						</form>
					</section>

					<?php FW_CRM_App::render_panels( 'deal', $d, 'side' ); ?>

					<?php if ( ! $d->deleted_at ) : ?>
						<p class="fw-crm-record__delete"><a class="button-link button-link-delete" href="<?php echo esc_url( $action( 'deal_trash' ) ); ?>"><?php esc_html_e( 'Move to bin', 'fw' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Deals panel on a contact or company page.
	 *
	 * @param string $object
	 * @param int    $id
	 */
	public static function render_record_deals( $object, $id ) {
		$deals = FW_CRM_Deals::query( FW_CRM_Capabilities::scope( array( $object => $id, 'orderby' => 'created_at', 'order' => 'DESC' ) ) );
		$base  = FW_CRM_Admin_Page::get_page_url();
		if ( $deals ) {
			echo '<ul class="fw-crm-people fw-crm-record-deals">';
			foreach ( $deals as $d ) {
				$stage = FW_CRM_Pipelines::stage( (int) $d->stage_id );
				printf(
					'<li><a href="%s"><span class="fw-crm-people__text"><span class="fw-crm-people__name">%s</span><span class="fw-crm-people__sub">%s · %s</span></span></a></li>',
					esc_url( add_query_arg( array( 'tab' => 'deals', 'deal' => (int) $d->id ), $base ) ),
					esc_html( $d->title ),
					esc_html( FW_CRM_Deals::money( $d->value, $d->currency ) ),
					esc_html( $stage ? $stage->name : '' )
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No deals yet.', 'fw' ) . '</p>';
		}
		printf(
			'<p><a class="button button-small" href="%s">%s</a></p>',
			esc_url( add_query_arg( array( 'tab' => 'deals', 'new' => 1, $object => $id ), $base ) ),
			esc_html__( 'New deal', 'fw' )
		);
	}

	/**
	 * The pipeline editor.
	 *
	 * @param string $edit A pipeline id, "new", or ''.
	 */
	private static function render_pipelines( $edit ) {
		$base      = FW_CRM_Admin_Page::get_page_url();
		$pipelines = FW_CRM_Pipelines::all();
		$current   = 'new' === $edit ? null : ( $edit ? FW_CRM_Pipelines::find( (int) $edit ) : $pipelines[0] );
		$stages    = $current ? FW_CRM_Pipelines::stages( (int) $current->id ) : array();
		$open      = array_values( array_filter( $stages, function ( $s ) {
			return 'open' === $s->kind;
		} ) );
		if ( ! $open ) {
			$open = array( (object) array( 'id' => 0, 'name' => '', 'probability' => 10, 'rot_days' => 0 ) );
		}
		$won  = $current ? FW_CRM_Pipelines::stage_of_kind( (int) $current->id, 'won' ) : null;
		$lost = $current ? FW_CRM_Pipelines::stage_of_kind( (int) $current->id, 'lost' ) : null;
		?>
		<div class="fw-crm-pipelines">
			<nav class="fw-crm-panel fw-crm-pipelines__list" aria-label="<?php esc_attr_e( 'Pipelines', 'fw' ); ?>">
				<ul class="fw-crm-people">
					<?php foreach ( $pipelines as $p ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'pipelines' => 1, 'edit' => (int) $p->id ), $base ) ); ?>" <?php echo $current && (int) $current->id === (int) $p->id ? 'aria-current="page"' : ''; ?>><span class="fw-crm-people__text"><span class="fw-crm-people__name"><?php echo esc_html( $p->name ); ?></span><span class="fw-crm-people__sub"><?php echo (int) $p->is_default ? esc_html__( 'Default', 'fw' ) : ''; ?></span></span></a></li>
					<?php endforeach; ?>
				</ul>
				<p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'deals', 'pipelines' => 1, 'edit' => 'new' ), $base ) ); ?>"><?php esc_html_e( 'Add a pipeline', 'fw' ); ?></a></p>
			</nav>
			<form method="post" action="" class="fw-crm-panel fw-crm-form fw-crm-pipeline-form">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="pipeline_save" />
				<input type="hidden" name="pipeline_id" value="<?php echo $current ? (int) $current->id : 0; ?>" />
				<div class="fw-crm-form__grid">
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Pipeline name', 'fw' ); ?></span><input type="text" name="name" required value="<?php echo esc_attr( $current ? $current->name : '' ); ?>" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Currency (empty = the CRM default)', 'fw' ); ?></span><input type="text" name="currency" maxlength="3" value="<?php echo esc_attr( $current ? $current->currency : '' ); ?>" placeholder="<?php echo esc_attr( FW_CRM_Pipelines::currency() ); ?>" /></label>
				</div>
				<label class="fw-crm-check"><input type="checkbox" name="is_default" value="1" <?php checked( $current && (int) $current->is_default ); ?> /> <?php esc_html_e( 'Open this pipeline first on the board', 'fw' ); ?></label>

				<h3><?php esc_html_e( 'Stages', 'fw' ); ?></h3>
				<p class="fw-crm-muted"><?php esc_html_e( 'In order. Probability is how likely a deal in that stage is to be won (it weights the forecast). A deal that stays in a stage longer than its "stalled after" days is flagged on the board.', 'fw' ); ?></p>
				<table class="widefat fw-crm-stage-table" id="fw-crm-stage-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Stage', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Probability %', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Stalled after (days, 0 = never)', 'fw' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'fw' ); ?></span></th></tr></thead>
					<tbody>
						<?php foreach ( $open as $i => $s ) : ?>
							<tr>
								<td><input type="hidden" name="stages[<?php echo (int) $i; ?>][id]" value="<?php echo (int) $s->id; ?>" /><label class="screen-reader-text" for="fw-crm-stage-n<?php echo (int) $i; ?>"><?php esc_html_e( 'Stage name', 'fw' ); ?></label><input type="text" id="fw-crm-stage-n<?php echo (int) $i; ?>" name="stages[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $s->name ); ?>" /></td>
								<td><input type="number" min="0" max="100" name="stages[<?php echo (int) $i; ?>][probability]" value="<?php echo (int) $s->probability; ?>" aria-label="<?php esc_attr_e( 'Probability', 'fw' ); ?>" /></td>
								<td><input type="number" min="0" name="stages[<?php echo (int) $i; ?>][rot_days]" value="<?php echo (int) $s->rot_days; ?>" aria-label="<?php esc_attr_e( 'Stalled after days', 'fw' ); ?>" /></td>
								<td><button type="button" class="fw-crm-unlink" data-remove-row aria-label="<?php esc_attr_e( 'Remove this stage', 'fw' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button button-small" id="fw-crm-add-stage"><?php esc_html_e( 'Add a stage', 'fw' ); ?></button></p>
				<div class="fw-crm-form__grid">
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Name of the won stage', 'fw' ); ?></span><input type="text" name="won_name" value="<?php echo esc_attr( $won ? $won->name : __( 'Won', 'fw' ) ); ?>" /></label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Name of the lost stage', 'fw' ); ?></span><input type="text" name="lost_name" value="<?php echo esc_attr( $lost ? $lost->name : __( 'Lost', 'fw' ) ); ?>" /></label>
				</div>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save pipeline', 'fw' ); ?></button>
					<a class="button" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'deals' ) ); ?>"><?php esc_html_e( 'Back to the board', 'fw' ); ?></a>
					<?php if ( $current && count( $pipelines ) > 1 ) : ?>
						<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'deals', 'fw_crm_action' => 'pipeline_delete', 'pipeline_id' => (int) $current->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this pipeline? Only possible when it has no deals.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete pipeline', 'fw' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}
}
