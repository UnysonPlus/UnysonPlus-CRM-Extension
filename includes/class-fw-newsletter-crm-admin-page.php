<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson+ → Subscribers screen.
 *
 * House conventions, matched deliberately (see Post Types / Asset Optimizer):
 *  - add_submenu_page() under the shared `fw-extensions` parent, position declared
 *    via the `fw_unysonplus_admin_submenu_order` filter rather than hard-coded.
 *  - EVERY POST/GET action runs on `load-{$hook_suffix}`, before any output, so
 *    each one can PRG-redirect and the CSV export can stream a download and exit.
 *  - Native `nav-tab-wrapper` tabs — no hand-rolled pill UI.
 *  - Notices are handed across the redirect in a transient.
 *  - Settings are stored with fw_set_db_ext_settings_option() and rendered by
 *    fw()->backend->render_options(), because a settings form IS an options form —
 *    while the subscriber grid is not, so that part is a real WP_List_Table.
 */
class FW_Newsletter_CRM_Admin_Page {

	const PARENT_SLUG      = 'fw-extensions';
	const PAGE_SLUG        = 'fw-newsletter-crm';
	const NONCE            = 'fw_newsletter_crm_action';
	const TRANSIENT_NOTICE = 'fw_ext_newsletter_crm_notice_';
	const TRANSIENT_IMPORT = 'fw_ext_newsletter_crm_import_';

	/** @var FW_Extension_Newsletter_CRM */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	/** @var FW_Newsletter_CRM_List_Table|null */
	private $table = null;

	/**
	 * @param FW_Extension_Newsletter_CRM $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
		add_action( 'wp_ajax_fw_crm_import_step', array( $this, '_ajax_import_step' ) );
		add_action( 'wp_ajax_fw_crm_preview', array( $this, '_ajax_preview' ) );
		add_action( 'admin_post_fw_crm_preview_tab', array( $this, '_action_preview_tab' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Menu + assets
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Newsletter / Subscriber CRM', 'fw' ), // page title
			// The screen outgrew "Subscribers" — it is the whole newsletter surface
			// now (campaigns, lists, tags, segments, import/export, settings), and
			// the subscriber list is one tab within it. The TAB is still called
			// Subscribers, which is where that word belongs.
			__( 'Newsletter', 'fw' ),                  // menu label (short — the submenu is narrow)
			FW_Newsletter_CRM_Service::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_action_load' ) );
			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );
		}
	}

	/**
	 * @internal
	 * We just declare where we want to sit; the Post Types extension owns the sort.
	 *
	 * @param array $order
	 *
	 * @return array
	 */
	public function _filter_submenu_order( $order ) {
		if ( in_array( self::PAGE_SLUG, (array) $order, true ) ) {
			return $order;
		}

		$order[] = self::PAGE_SLUG;

		return $order;
	}

	/**
	 * @internal
	 *
	 * @param string $hook
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		fw()->backend->enqueue_options_static( $this->ext->get_settings_options() );

		wp_enqueue_style(
			'fw-ext-newsletter-crm',
			$this->ext->get_uri() . '/static/css/admin.css',
			array(),
			$this->ext->manifest->get_version()
		);
	}

	/* ---------------------------------------------------------------------- *
	 * URLs
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $tab
	 *
	 * @return string
	 */
	public static function get_page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' !== $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * A nonced single-row action link.
	 *
	 * @param string $action
	 * @param int    $id
	 *
	 * @return string
	 */
	public static function action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg( array( 'fw_crm_action' => $action, 'id' => (int) $id ), self::get_page_url() ),
			self::NONCE
		);
	}

	/**
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'subscribers'; // phpcs:ignore WordPress.Security.NonceVerification

		return in_array( $tab, array( 'subscribers', 'campaigns', 'lists', 'tools', 'settings' ), true ) ? $tab : 'subscribers';
	}

	/* ---------------------------------------------------------------------- *
	 * Request handling — all of it before any output
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_load() {
		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			return;
		}

		// The list table must exist before the screen renders so its per-page
		// option and column headers are registered.
		$this->table = new FW_Newsletter_CRM_List_Table();

		add_screen_option( 'per_page', array(
			'label'   => __( 'Subscribers per page', 'fw' ),
			'default' => 20,
			'option'  => 'fw_crm_subscribers_per_page',
		) );

		// Our own actions carry `fw_crm_action` (POST wins over GET); a bulk action
		// instead arrives through the list table's own fields, and is nonced by
		// WP_List_Table under a different name — so resolve which it is FIRST and
		// then verify the matching nonce. Never one check standing in for both.
		$action = '';
		$bulk   = $this->table->current_action();

		if ( isset( $_POST['fw_crm_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['fw_crm_action'] ) );
		} elseif ( isset( $_GET['fw_crm_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_GET['fw_crm_action'] ) );
		}

		if ( '' !== $action ) {
			check_admin_referer( self::NONCE );
		} elseif ( $bulk ) {
			check_admin_referer( 'bulk-subscribers' );
			$action = 'bulk_' . $bulk;
		} else {
			return;
		}

		switch ( $action ) {
			case 'export':
				// Streams and exits — hence the `load-` hook.
				FW_Newsletter_CRM_CSV::export( FW_Newsletter_CRM_List_Table::request_query_args() );
				return;

			case 'add':
				$this->handle_add();
				break;

			case 'unsubscribe':
				$this->handle_single( 'unsubscribe' );
				break;

			case 'delete':
				$this->handle_single( 'delete' );
				break;

			case 'resend':
				$this->handle_single( 'resend' );
				break;

			case 'save_campaign':
			case 'send_campaign':
			case 'schedule_campaign':
			case 'test_campaign':
				$this->handle_campaign_post( $action );
				break;

			case 'pause_campaign':
			case 'resume_campaign':
			case 'delete_campaign':
			case 'run_sender':
				$this->handle_campaign_get( $action );
				break;

			case 'save_membership':
				$this->handle_save_membership();
				break;

			case 'save_list':
				$this->handle_save_list();
				break;

			case 'delete_list':
				$this->handle_delete_list();
				break;

			case 'save_segment':
				$this->handle_save_segment();
				break;

			case 'delete_segment':
				$this->handle_delete_segment();
				break;

			case 'import_upload':
				$this->handle_import_upload();
				break;

			case 'import_run':
				$this->handle_import_run();
				break;

			case 'import_cancel':
				$this->handle_import_cancel();
				break;

			case 'save_settings':
				$this->handle_save_settings();
				break;

			case 'remove_data':
				$this->handle_remove_data();
				break;

			default:
				if ( 0 === strpos( $action, 'bulk_' ) ) {
					$this->handle_bulk( substr( $action, 5 ) );
				}
				break;
		}

		$this->redirect();
	}

	/**
	 * PRG back to the current tab, preserving the active filters.
	 *
	 * @param array $args
	 */
	private function redirect( array $args = array() ) {
		$url = self::get_page_url( $this->current_tab() );

		foreach ( array( 'status', 'list', 's' ) as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$url = add_query_arg( $key, sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ), $url );
			}
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * @param string $type success|error|warning
	 * @param string $message
	 */
	private function notice( $type, $message ) {
		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array(
			'type'    => $type,
			'message' => $message,
		), MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------- *
	 * Actions
	 * ---------------------------------------------------------------------- */

	private function handle_add() {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$list  = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		$tags  = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';

		$result = FW_Newsletter_CRM_Service::subscribe( $email, array(
			'name'         => $name,
			'list'         => $list,
			'tags'         => array_filter( array_map( 'trim', explode( ',', $tags ) ) ),
			'source'       => 'manual',
			'double_optin' => false, // An admin adding someone by hand means it.
		) );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return;
		}

		$this->notice( 'success', sprintf(
			/* translators: %s: email address */
			__( '%s added.', 'fw' ),
			$result->email
		) );
	}

	/**
	 * @param string $what unsubscribe|delete
	 */
	private function handle_single( $what ) {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $id ) {
			return;
		}

		if ( 'delete' === $what ) {
			FW_Newsletter_CRM_Service::delete( $id );
			$this->notice( 'success', __( 'Subscriber deleted.', 'fw' ) );

			return;
		}

		if ( 'resend' === $what ) {
			$result = FW_Newsletter_CRM_Service::resend_confirmation( $id );

			$this->notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'A fresh confirmation link was emailed.', 'fw' )
			);

			return;
		}

		$result = FW_Newsletter_CRM_Service::unsubscribe( $id );

		$this->notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Subscriber unsubscribed.', 'fw' )
		);
	}

	/**
	 * @param string $what unsubscribe|subscribe|delete
	 */
	private function handle_bulk( $what ) {
		$ids = isset( $_REQUEST['subscribers'] ) ? array_map( 'intval', (array) $_REQUEST['subscribers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			$this->notice( 'warning', __( 'No subscribers selected.', 'fw' ) );

			return;
		}

		// Tagging in bulk is one repository round-trip per subscriber, not a loop
		// of full subscribe() calls — the service handles the whole set.
		if ( 'add_tag' === $what || 'remove_tag' === $what ) {
			$tag_id = isset( $_REQUEST['bulk_tag'] ) ? (int) $_REQUEST['bulk_tag'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

			if ( ! $tag_id ) {
				$this->notice( 'error', __( 'Choose which tag to add or remove from the dropdown next to the bulk actions.', 'fw' ) );

				return;
			}

			$op   = 'remove_tag' === $what ? 'remove' : 'add';
			$done = FW_Newsletter_CRM_Service::set_membership( $ids, $tag_id, $op, 'tag' );
			$tag  = FW_Newsletter_CRM_Lists::find( $tag_id );

			$this->notice( 'success', sprintf(
				'remove' === $op
					/* translators: 1: number of subscribers, 2: tag name */
					? _n( 'Removed "%2$s" from %1$s subscriber.', 'Removed "%2$s" from %1$s subscribers.', $done, 'fw' )
					/* translators: 1: number of subscribers, 2: tag name */
					: _n( 'Tagged %1$s subscriber "%2$s".', 'Tagged %1$s subscribers "%2$s".', $done, 'fw' ),
				number_format_i18n( $done ),
				$tag ? $tag->title : ''
			) );

			return;
		}

		$done = 0;

		foreach ( $ids as $id ) {
			switch ( $what ) {
				case 'delete':
					$done += FW_Newsletter_CRM_Service::delete( $id ) ? 1 : 0;
					break;

				case 'subscribe':
					$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

					if ( $subscriber ) {
						$result = FW_Newsletter_CRM_Service::subscribe( $subscriber->email, array(
							'status'       => 'subscribed',
							'source'       => $subscriber->source,
							'double_optin' => false,
						) );
						$done  += is_wp_error( $result ) ? 0 : 1;
					}
					break;

				case 'unsubscribe':
				default:
					$done += is_wp_error( FW_Newsletter_CRM_Service::unsubscribe( $id ) ) ? 0 : 1;
					break;
			}
		}

		$this->notice( 'success', sprintf(
			/* translators: %s: number of subscribers */
			_n( '%s subscriber updated.', '%s subscribers updated.', $done, 'fw' ),
			number_format_i18n( $done )
		) );
	}

	/**
	 * Replace one subscriber's whole list and tag membership from the checkboxes
	 * on the single view.
	 */
	private function handle_save_membership() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id || ! FW_Newsletter_CRM_Subscribers::find( $id ) ) {
			$this->notice( 'error', __( 'Subscriber not found.', 'fw' ) );

			return;
		}

		foreach ( array( 'list', 'tag' ) as $type ) {
			$ids = isset( $_POST[ $type . 's' ] ) ? array_map( 'intval', (array) $_POST[ $type . 's' ] ) : array();
			FW_Newsletter_CRM_Subscribers::set_lists( $id, $ids, $type );
		}

		$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

		do_action( 'unysonplus_newsletter_crm_subscriber_updated', $subscriber, $subscriber, array( 'context' => 'membership' ) );

		$this->notice( 'success', __( 'Lists and tags updated.', 'fw' ) );
		$this->redirect( array( 'subscriber' => $id ) );
	}

	/**
	 * Campaign actions that arrive from the editor form.
	 *
	 * @param string $action
	 */
	private function handle_campaign_post( $action ) {
		$audience = array(
			'list'    => isset( $_POST['a_list'] ) ? sanitize_key( wp_unslash( $_POST['a_list'] ) ) : '',
			'tag'     => isset( $_POST['a_tag'] ) ? sanitize_key( wp_unslash( $_POST['a_tag'] ) ) : '',
			'segment' => isset( $_POST['a_segment'] ) ? (int) $_POST['a_segment'] : 0,
		);

		$payload = array(
			'id'       => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'title'    => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'subject'  => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'body'     => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
			'audience' => $audience,
		);

		$mode = isset( $_POST['editor_mode'] ) ? sanitize_key( wp_unslash( $_POST['editor_mode'] ) ) : 'visual';

		if ( 'builder' === $mode ) {
			// Let the option type parse and sanitise its own value — never trust
			// the raw POST tree, and never hand-decode a builder's storage shape.
			$values = fw_get_options_values_from_input(
				array( 'body_json' => array( 'type' => 'email-builder' ) )
			);

			$payload['body_json'] = isset( $values['body_json'] ) ? $values['body_json'] : array();
		} else {
			// Switching back to the visual editor clears the block tree, so the
			// stored HTML and the builder can never disagree about what is sent.
			$payload['body_json'] = array();
		}

		$campaign = FW_Newsletter_CRM_Service::save_campaign( $payload );

		if ( is_wp_error( $campaign ) ) {
			$this->notice( 'error', $campaign->get_error_message() );

			return;
		}

		if ( 'test_campaign' === $action ) {
			$to     = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
			$result = FW_Newsletter_CRM_Service::send_test( $campaign->id, $to );

			$this->notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result )
					? $result->get_error_message()
					: sprintf(
						/* translators: %s: email address */
						__( 'Test sent to %s.', 'fw' ),
						$to
					)
			);

			$this->redirect( array( 'tab' => 'campaigns', 'campaign' => $campaign->id ) );
		}

		if ( 'send_campaign' === $action || 'schedule_campaign' === $action ) {
			$when = null;

			if ( 'schedule_campaign' === $action ) {
				$raw = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
				$ts  = $raw ? strtotime( $raw ) : false;

				if ( ! $ts ) {
					$this->notice( 'error', __( 'That send date could not be understood.', 'fw' ) );
					$this->redirect( array( 'tab' => 'campaigns', 'campaign' => $campaign->id ) );
				}

				$when = gmdate( 'Y-m-d H:i:s', $ts );
			}

			$result = FW_Newsletter_CRM_Service::schedule_campaign( $campaign->id, $when );

			if ( is_wp_error( $result ) ) {
				$this->notice( 'error', $result->get_error_message() );
				$this->redirect( array( 'tab' => 'campaigns', 'campaign' => $campaign->id ) );
			}

			$this->notice( 'success', $when
				? sprintf(
					/* translators: 1: campaign name, 2: date */
					__( '"%1$s" is scheduled for %2$s. Sending happens in batches on WP-Cron — which only runs when the site gets traffic, so on a quiet site use "Run sending now".', 'fw' ),
					$result->title,
					date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $when ) )
				)
				: sprintf(
					/* translators: %s: campaign name */
					__( '"%s" is queued. Sending happens in batches on WP-Cron — which only runs when the site gets traffic, so on a quiet site use "Run sending now".', 'fw' ),
					$result->title
				)
			);

			$this->redirect( array( 'tab' => 'campaigns' ) );
		}

		$this->notice( 'success', __( 'Campaign saved.', 'fw' ) );
		$this->redirect( array( 'tab' => 'campaigns', 'campaign' => $campaign->id ) );
	}

	/**
	 * Campaign actions that arrive as nonced links.
	 *
	 * @param string $action
	 */
	private function handle_campaign_get( $action ) {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'run_sender' === $action ) {
			// The manual kick. WP-Cron only fires on page loads, so on a quiet
			// site (or localhost) a queued campaign would otherwise just sit
			// there looking broken.
			$sender = new FW_Newsletter_CRM_Sender();
			$report = $sender->run();

			$this->notice( 'success', sprintf(
				/* translators: 1: started, 2: sent, 3: failed, 4: skipped */
				__( 'Sender run — %1$s started, %2$s sent, %3$s failed, %4$s skipped. Run again to continue, or leave it to WP-Cron.', 'fw' ),
				number_format_i18n( $report['started'] ),
				number_format_i18n( $report['sent'] ),
				number_format_i18n( $report['failed'] ),
				number_format_i18n( $report['skipped'] )
			) );

			$this->redirect( array( 'tab' => 'campaigns' ) );
		}

		if ( 'delete_campaign' === $action ) {
			$this->notice(
				FW_Newsletter_CRM_Service::delete_campaign( $id ) ? 'success' : 'error',
				__( 'Campaign deleted.', 'fw' )
			);

			$this->redirect( array( 'tab' => 'campaigns' ) );
		}

		$result = 'pause_campaign' === $action
			? FW_Newsletter_CRM_Service::pause_campaign( $id )
			: FW_Newsletter_CRM_Service::resume_campaign( $id );

		$this->notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result )
				? $result->get_error_message()
				: ( 'pause_campaign' === $action
					? __( 'Paused. The queue is kept, so resuming continues from where it stopped.', 'fw' )
					: __( 'Resumed.', 'fw' ) )
		);

		$this->redirect( array( 'tab' => 'campaigns' ) );
	}

	private function handle_save_list() {
		$result = FW_Newsletter_CRM_Service::save_list( array(
			'id'          => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'type'        => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'list',
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'slug'        => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
		) );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return;
		}

		$this->notice( 'success', sprintf(
			/* translators: %s: list or tag name */
			__( '"%s" saved.', 'fw' ),
			$result->title
		) );
	}

	private function handle_delete_list() {
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$result = FW_Newsletter_CRM_Service::delete_list( $id );

		$this->notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result )
				? $result->get_error_message()
				: __( 'Deleted. The subscribers who were in it are untouched.', 'fw' )
		);
	}

	/**
	 * Save whatever the Subscribers tab is currently filtered to, as a segment.
	 */
	private function handle_save_segment() {
		$title = isset( $_POST['segment_title'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_title'] ) ) : '';

		$filters = array(
			'status' => isset( $_POST['f_status'] ) ? sanitize_key( wp_unslash( $_POST['f_status'] ) ) : '',
			'list'   => isset( $_POST['f_list'] ) ? sanitize_key( wp_unslash( $_POST['f_list'] ) ) : '',
			'tag'    => isset( $_POST['f_tag'] ) ? sanitize_key( wp_unslash( $_POST['f_tag'] ) ) : '',
			'search' => isset( $_POST['f_search'] ) ? sanitize_text_field( wp_unslash( $_POST['f_search'] ) ) : '',
		);

		$result = FW_Newsletter_CRM_Service::save_segment( $filters, $title );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return;
		}

		$this->notice( 'success', sprintf(
			/* translators: 1: segment name, 2: what it matches */
			__( 'Segment "%1$s" saved — it matches %2$s.', 'fw' ),
			$result->title,
			FW_Newsletter_CRM_Service::describe_filters( FW_Newsletter_CRM_Lists::segment_query_args( $result ) )
		) );
	}

	private function handle_delete_segment() {
		$id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$deleted = FW_Newsletter_CRM_Service::delete_segment( $id );

		$this->notice(
			$deleted ? 'success' : 'error',
			$deleted ? __( 'Segment deleted.', 'fw' ) : __( 'That segment no longer exists.', 'fw' )
		);
	}

	/**
	 * Step 1 of import: take the upload, park it, and move to column mapping.
	 */
	private function handle_import_upload() {
		if ( empty( $_FILES['fw_crm_csv']['tmp_name'] ) ) {
			$this->notice( 'error', __( 'Choose a CSV file to import.', 'fw' ) );

			return;
		}

		$file = $_FILES['fw_crm_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! empty( $file['error'] ) ) {
			$this->notice( 'error', __( 'The upload failed. The file may be larger than this server allows.', 'fw' ) );

			return;
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'import.csv';

		if ( 'csv' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$this->notice( 'error', __( 'That is not a .csv file.', 'fw' ) );

			return;
		}

		// Park it under the shared uploads parent (uploads/unysonplus/<subdir>),
		// never a loose sibling folder — see the workspace uploads rule.
		$dir = function_exists( 'fw_upw_uploads_dir' )
			? fw_upw_uploads_dir( 'newsletter-crm' )
			: array( 'path' => get_temp_dir() );

		if ( ! file_exists( $dir['path'] ) ) {
			wp_mkdir_p( $dir['path'] );
		}

		$target = trailingslashit( $dir['path'] ) . 'import-' . get_current_user_id() . '-' . wp_generate_password( 8, false, false ) . '.csv';

		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->notice( 'error', __( 'Could not store the uploaded file.', 'fw' ) );

			return;
		}

		$peek = FW_Newsletter_CRM_CSV::peek( $target );

		if ( is_wp_error( $peek ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->notice( 'error', $peek->get_error_message() );

			return;
		}

		set_transient( self::TRANSIENT_IMPORT . get_current_user_id(), array(
			'file'   => $target,
			'header' => $peek['header'],
			'rows'   => $peek['rows'],
		), HOUR_IN_SECONDS );

		$this->redirect( array( 'tab' => 'tools', 'step' => 'map' ) );
	}

	/**
	 * Step 2 of import: accept the mapping and START the job.
	 *
	 * Nothing is imported here. A large file cannot finish inside one request —
	 * it hits max_execution_time, and because every row commits as it goes that
	 * would leave a partial import with no way to resume. So this builds a job
	 * and hands off to _ajax_import_step(), which chews through it in resumable
	 * chunks from a byte offset.
	 */
	private function handle_import_run() {
		$parked = get_transient( self::TRANSIENT_IMPORT . get_current_user_id() );

		if ( ! $parked || empty( $parked['file'] ) ) {
			$this->notice( 'error', __( 'That import expired. Please upload the file again.', 'fw' ) );

			return;
		}

		$mapping = array();

		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			foreach ( wp_unslash( $_POST['map'] ) as $index => $field ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$field = sanitize_key( $field );

				if ( '' !== $field ) {
					$mapping[ (int) $index ] = $field;
				}
			}
		}

		if ( ! in_array( 'email', $mapping, true ) ) {
			$this->notice( 'error', __( 'One column must be mapped to Email.', 'fw' ) );

			return;
		}

		$list = isset( $_POST['import_list'] ) ? sanitize_key( wp_unslash( $_POST['import_list'] ) ) : '';

		set_transient( self::TRANSIENT_IMPORT . get_current_user_id(), array(
			'file'    => $parked['file'],
			'header'  => $parked['header'],
			'rows'    => $parked['rows'],
			'mapping' => $mapping,
			'opts'    => array(
				'lists'                  => '' !== $list ? array( $list ) : array(),
				'overwrite_unsubscribed' => ! empty( $_POST['overwrite_unsubscribed'] ),
				'source'                 => 'import',
			),
			'offset'  => 0,
			'line'    => 1,
			'stats'   => array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array() ),
			'running' => true,
		), DAY_IN_SECONDS );

		$this->redirect( array( 'tab' => 'tools', 'step' => 'run' ) );
	}

	/**
	 * @internal
	 * Import one chunk and report progress. Called repeatedly by the progress
	 * screen until it reports done.
	 */
	public function _ajax_import_step() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'fw' ) ) );
		}

		$key = self::TRANSIENT_IMPORT . get_current_user_id();
		$job = get_transient( $key );

		if ( ! $job || empty( $job['running'] ) ) {
			wp_send_json_error( array( 'message' => __( 'That import is no longer running. Please upload the file again.', 'fw' ) ) );
		}

		/**
		 * Filters the number of CSV rows processed per import request (default 200).
		 *
		 * Rows per request. Small enough to finish comfortably inside one PHP
		 * request on a modest host; the seconds budget is the real backstop.
		 *
		 * @param int $rows
		 */
		$max_rows = (int) apply_filters( 'unysonplus_newsletter_crm_import_batch_size', 200 );

		$chunk = FW_Newsletter_CRM_CSV::import( $job['file'], $job['mapping'], array_merge( $job['opts'], array(
			'offset'      => (int) $job['offset'],
			'line'        => (int) $job['line'],
			'max_rows'    => $max_rows,
			/** Filters the per-request time budget in seconds for a chunked CSV subscriber import (default 10). */
			'max_seconds' => (int) apply_filters( 'unysonplus_newsletter_crm_import_batch_seconds', 10 ),
		) ) );

		if ( is_wp_error( $chunk ) ) {
			delete_transient( $key );
			wp_send_json_error( array( 'message' => $chunk->get_error_message() ) );
		}

		$job['stats']  = FW_Newsletter_CRM_CSV::merge_stats( $job['stats'], $chunk );
		$job['offset'] = (int) $chunk['offset'];
		$job['line']   = (int) $chunk['line'];

		$total   = max( 1, (int) $chunk['size'] );
		$percent = min( 100, (int) round( ( $chunk['offset'] / $total ) * 100 ) );

		if ( empty( $chunk['done'] ) ) {
			set_transient( $key, $job, DAY_IN_SECONDS );

			wp_send_json_success( array(
				'done'    => false,
				'percent' => $percent,
				'stats'   => $job['stats'],
			) );
		}

		// Finished — clean up the upload and hand the summary to the notice.
		@unlink( $job['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		delete_transient( $key );

		$stats   = $job['stats'];
		$message = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
			__( 'Import finished — %1$s added, %2$s updated, %3$s skipped, %4$s failed.', 'fw' ),
			number_format_i18n( $stats['created'] ),
			number_format_i18n( $stats['updated'] ),
			number_format_i18n( $stats['skipped'] ),
			number_format_i18n( $stats['failed'] )
		);

		if ( ! empty( $stats['errors'] ) ) {
			$message .= '<br>' . esc_html( implode( ' · ', $stats['errors'] ) );
		}

		$this->notice( $stats['failed'] ? 'warning' : 'success', $message );

		wp_send_json_success( array(
			'done'     => true,
			'percent'  => 100,
			'stats'    => $stats,
			'redirect' => self::get_page_url( 'subscribers' ),
		) );
	}

	/**
	 * The placeholder list, plus the layout advice that actually applies to the
	 * editor being used.
	 *
	 * The placeholder half is identical either way — they resolve in the block
	 * options exactly as they do in the visual editor, and someone building with
	 * blocks needs to know that just as much. The layout half is NOT identical,
	 * which is why this takes a mode rather than printing one paragraph for
	 * both: telling a builder user to avoid multi-column layouts would be wrong,
	 * since compiling columns that survive Outlook is precisely what the builder
	 * does for them.
	 *
	 * @param string $mode 'visual' | 'builder'
	 */
	private function placeholder_help( $mode = 'visual' ) {
		?>
		<p class="description">
			<?php esc_html_e( 'Placeholders: {{name}}, {{first_name}}, {{last_name}}, {{email}}, {{site_name}}, {{site_url}}, {{unsubscribe_url}}, {{preferences_url}} (a page where the reader picks which lists to receive). If you leave out {{unsubscribe_url}} an unsubscribe line is appended automatically — bulk email must always carry one.', 'fw' ); ?>
		</p>
		<p class="description">
			<?php
			if ( 'builder' === $mode ) {
				esc_html_e( 'Blocks compile to table-based HTML, so columns and buttons are safe here — the builder emits the Outlook fallbacks for you. Two things it cannot do for you: Outlook ignores media queries, so columns do not stack on mobile there, and most clients block images by default — write alt text that still reads sensibly when the picture never loads.', 'fw' );
			} else {
				esc_html_e( 'Keep the layout simple. Email clients are not browsers — Outlook renders with the Word engine, so multi-column layouts, floats and background images are unreliable. Text, headings, lists, links, buttons and single-column images are safe everywhere.', 'fw' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * The campaign fields out of a preview request.
	 *
	 * Mirrors the save path: the builder's value is parsed by the option type
	 * rather than hand-decoded, so a preview sees exactly the tree a save would
	 * store — including its sanitisation.
	 *
	 * @return array
	 */
	private function preview_payload() {
		$mode = isset( $_POST['editor_mode'] ) && 'builder' === $_POST['editor_mode'] ? 'builder' : 'visual';

		$data = array(
			'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'body'    => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
		);

		if ( 'builder' === $mode ) {
			$values = fw_get_options_values_from_input(
				array( 'body_json' => array( 'type' => 'email-builder' ) )
			);

			$data['body_json'] = isset( $values['body_json'] ) ? $values['body_json'] : array();
		}

		return $data;
	}

	/**
	 * @internal
	 * Render the current (possibly unsaved) campaign as it would be sent.
	 */
	public function _ajax_preview() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'fw' ) ) );
		}

		$preview = FW_Newsletter_CRM_Service::preview( $this->preview_payload() );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * @internal
	 * The same preview as its own full browser tab — for looking at it at real
	 * window width, printing it, or handing the URL to somebody. Posts rather
	 * than links because the payload is the unsaved editor state.
	 */
	public function _action_preview_tab() {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'fw' ), 403 );
		}

		$preview = FW_Newsletter_CRM_Service::preview( $this->preview_payload() );

		if ( is_wp_error( $preview ) ) {
			wp_die( esc_html( $preview->get_error_message() ) );
		}

		// The compiled email IS a complete document — serve it as one rather than
		// nesting it in admin chrome, so what the tab renders is byte-for-byte
		// what an inbox receives.
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		echo $preview['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- compiled email document.
		exit;
	}

	/**
	 * Abandon a running import.
	 */
	private function handle_import_cancel() {
		$key = self::TRANSIENT_IMPORT . get_current_user_id();
		$job = get_transient( $key );

		if ( $job && ! empty( $job['file'] ) ) {
			@unlink( $job['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		delete_transient( $key );

		$this->notice( 'warning', __( 'Import cancelled. Rows already imported were kept.', 'fw' ) );
	}

	private function handle_save_settings() {
		$before = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		$values = array_merge( $before, fw_get_options_values_from_input( $this->ext->get_settings_options() ) );

		fw_set_db_ext_settings_option( $this->ext->get_name(), null, $values );

		$this->notice( 'success', __( 'Settings saved.', 'fw' ) );
	}

	/**
	 * The explicit, opt-in data removal. Never happens on deactivation.
	 */
	private function handle_remove_data() {
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

		if ( 'DELETE' !== strtoupper( $confirm ) ) {
			$this->notice( 'error', __( 'Type DELETE to confirm removing all subscriber data.', 'fw' ) );

			return;
		}

		// Stop the sender first — a scheduled tick against dropped tables would
		// fatal on the next page load.
		FW_Newsletter_CRM_Sender::unschedule();
		FW_Newsletter_CRM_Installer::uninstall();

		$this->notice( 'success', __( 'All subscriber data was removed. The tables will be recreated empty on the next page load.', 'fw' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			return;
		}

		$tab = $this->current_tab();

		$tabs = array(
			'subscribers' => __( 'Subscribers', 'fw' ),
			'campaigns'   => __( 'Campaigns', 'fw' ),
			'lists'       => __( 'Lists, Tags & Segments', 'fw' ),
			'tools'       => __( 'Import / Export', 'fw' ),
			'settings'    => __( 'Settings', 'fw' ),
		);
		?>
		<div class="wrap fw-ext-newsletter-crm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Newsletter', 'fw' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2 class="nav-tab-wrapper" style="margin:.6em 0 1.4em">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::get_page_url( $slug ) ); ?>"
					   class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings_tab();
			} elseif ( 'campaigns' === $tab ) {
				$this->render_campaigns_tab();
			} elseif ( 'lists' === $tab ) {
				$this->render_lists_tab();
			} elseif ( 'tools' === $tab ) {
				$this->render_tools_tab();
			} else {
				$this->render_subscribers_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_notice() {
		$key    = self::TRANSIENT_NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			wp_kses_post( $notice['message'] )
		);
	}

	private function render_subscribers_tab() {
		$single = isset( $_GET['subscriber'] ) ? (int) $_GET['subscriber'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $single ) {
			$this->render_single( $single );

			return;
		}

		if ( ! $this->table ) {
			$this->table = new FW_Newsletter_CRM_List_Table();
		}

		$this->table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array_filter( FW_Newsletter_CRM_List_Table::request_query_args() ),
					array( 'fw_crm_action' => 'export' )
				),
				self::get_page_url()
			),
			self::NONCE
		);
		?>
		<p class="fw-crm-toolbar">
			<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export these results (CSV)', 'fw' ); ?></a>
			<a href="<?php echo esc_url( self::get_page_url( 'tools' ) ); ?>" class="button"><?php esc_html_e( 'Import CSV', 'fw' ); ?></a>
			<button type="button" class="button button-primary" id="fw-crm-add-toggle"><?php esc_html_e( 'Add subscriber', 'fw' ); ?></button>
		</p>

		<div class="fw-crm-add" id="fw-crm-add" hidden>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="add" />
				<p>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'Email', 'fw' ); ?></span>
						<input type="email" name="email" required class="regular-text" />
					</label>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'Name', 'fw' ); ?></span>
						<input type="text" name="name" class="regular-text" />
					</label>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'List', 'fw' ); ?></span>
						<?php $this->render_list_select( 'list' ); ?>
					</label>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'Tags', 'fw' ); ?></span>
						<input type="text" name="tags" class="regular-text" placeholder="<?php esc_attr_e( 'comma, separated', 'fw' ); ?>" />
					</label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add subscriber', 'fw' ); ?></button></p>
			</form>
		</div>

		<?php
		// "Save as segment" only makes sense once something is actually filtered —
		// a segment matching everyone is just the unfiltered screen.
		$filters = FW_Newsletter_CRM_Service::sanitize_segment_filters(
			FW_Newsletter_CRM_List_Table::request_query_args()
		);
		?>
		<?php if ( $filters ) : ?>
			<div class="fw-crm-segment-save">
				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="save_segment" />
					<?php foreach ( $filters as $key => $value ) : ?>
						<input type="hidden" name="f_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
					<?php endforeach; ?>
					<span class="fw-crm-segment-save__what">
						<?php
						printf(
							/* translators: %s: a description of the current filters */
							esc_html__( 'Showing %s.', 'fw' ),
							'<strong>' . esc_html( FW_Newsletter_CRM_Service::describe_filters( $filters ) ) . '</strong>'
						);
						?>
					</span>
					<input type="text" name="segment_title" required
					       placeholder="<?php esc_attr_e( 'Name this segment', 'fw' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Save as segment', 'fw' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<?php
		// ONE form, method="get" — the core pattern (see edit.php). The filter
		// dropdowns live inside WP_List_Table's own tablenav, so they must be in
		// the same form as the table, and it has to be GET or filtering would not
		// survive into the URL and pagination links would silently drop it. Bulk
		// actions ride the same form and are nonced under `bulk-subscribers`;
		// every handler PRG-redirects, so nothing re-fires on refresh.
		$this->table->views();
		?>
		<form method="get" action="">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<?php if ( ! empty( $_GET['status'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( 'bulk-subscribers' ); ?>
			<?php $this->table->search_box( __( 'Search subscribers', 'fw' ), 'fw-crm-search' ); ?>
			<?php $this->table->display(); ?>
		</form>

		<script>
		( function () {
			var btn = document.getElementById( 'fw-crm-add-toggle' );
			var box = document.getElementById( 'fw-crm-add' );
			if ( ! btn || ! box ) { return; }
			btn.addEventListener( 'click', function () {
				box.hidden = ! box.hidden;
				if ( ! box.hidden ) { var f = box.querySelector( 'input' ); if ( f ) { f.focus(); } }
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * The single-subscriber view — the seed of the future contact profile.
	 *
	 * @param int $id
	 */
	private function render_single( $id ) {
		$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

		if ( ! $subscriber ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Subscriber not found.', 'fw' ) . '</p></div>';

			return;
		}

		$lists = wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $id, 'list' ), 'title' );
		$tags  = wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $id, 'tag' ), 'title' );
		$meta  = $subscriber->meta ? json_decode( $subscriber->meta, true ) : array();

		$rows = array(
			__( 'Email', 'fw' )        => $subscriber->email,
			__( 'Name', 'fw' )         => trim( $subscriber->first_name . ' ' . $subscriber->last_name ),
			__( 'Status', 'fw' )       => $subscriber->status,
			__( 'Lists', 'fw' )        => implode( ', ', $lists ),
			__( 'Tags', 'fw' )         => implode( ', ', $tags ),
			__( 'Source', 'fw' )       => $subscriber->source,
			__( 'Signed up on', 'fw' ) => $subscriber->source_url,
			__( 'IP', 'fw' )           => $subscriber->ip,
			__( 'Created', 'fw' )      => $subscriber->created_at,
			__( 'Confirmed', 'fw' )    => $subscriber->confirmed_at,
			__( 'Unsubscribed', 'fw' ) => $subscriber->unsubscribed_at,
		);

		if ( is_array( $meta ) && ! empty( $meta['consent_text'] ) ) {
			$rows[ __( 'Consent text', 'fw' ) ] = $meta['consent_text'];
		}
		?>
		<p><a href="<?php echo esc_url( self::get_page_url() ); ?>">&larr; <?php esc_html_e( 'Back to all subscribers', 'fw' ); ?></a></p>

		<table class="widefat striped fw-crm-single">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row" style="width:180px"><?php echo esc_html( $label ); ?></th>
					<td><?php echo '' !== (string) $value ? esc_html( $value ) : '<span aria-hidden="true">—</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$all_lists = FW_Newsletter_CRM_Lists::all( 'list' );
		$all_tags  = FW_Newsletter_CRM_Lists::all( 'tag' );
		$mine      = array(
			'list' => wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $id, 'list' ), 'id' ),
			'tag'  => wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $id, 'tag' ), 'id' ),
		);
		?>
		<?php if ( $all_lists || $all_tags ) : ?>
			<form method="post" action="" class="fw-crm-membership">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="save_membership" />
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />

				<?php
				foreach ( array(
					'list' => __( 'Lists', 'fw' ),
					'tag'  => __( 'Tags', 'fw' ),
				) as $type => $label ) :
					$rows = 'list' === $type ? $all_lists : $all_tags;

					if ( ! $rows ) {
						continue;
					}
					?>
					<fieldset class="fw-crm-membership__set">
						<legend><?php echo esc_html( $label ); ?></legend>
						<?php foreach ( $rows as $row ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $type ); ?>s[]"
								       value="<?php echo (int) $row->id; ?>"
									<?php checked( in_array( $row->id, $mine[ $type ], false ) ); // phpcs:ignore WordPress.PHP.StrictInArray ?> />
								<?php echo esc_html( $row->title ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>

				<p><button type="submit" class="button"><?php esc_html_e( 'Save lists & tags', 'fw' ); ?></button></p>
			</form>
		<?php endif; ?>

		<p style="margin-top:1em">
			<?php if ( 'pending' === $subscriber->status ) : ?>
				<a class="button" href="<?php echo esc_url( self::action_url( 'resend', $subscriber->id ) ); ?>"><?php esc_html_e( 'Resend confirmation', 'fw' ); ?></a>
			<?php endif; ?>
			<?php if ( 'unsubscribed' !== $subscriber->status ) : ?>
				<a class="button" href="<?php echo esc_url( self::action_url( 'unsubscribe', $subscriber->id ) ); ?>"><?php esc_html_e( 'Unsubscribe', 'fw' ); ?></a>
			<?php endif; ?>
			<a class="button button-link-delete" href="<?php echo esc_url( self::action_url( 'delete', $subscriber->id ) ); ?>"
			   onclick="return confirm('<?php echo esc_js( __( 'Delete this subscriber permanently?', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
		</p>
		<?php
		/**
		 * Fires at the end of the single-subscriber screen. The Forms bridge adds
		 * the person's form entries here (Activity) — a read across the join by
		 * email, so the CRM never has to know how entries are stored.
		 *
		 * @param object $subscriber
		 */
		do_action( 'unysonplus_newsletter_crm_subscriber_view_after', $subscriber );
	}

	/* ---------------------------------------------------------------------- *
	 * Campaigns
	 * ---------------------------------------------------------------------- */

	private function render_campaigns_tab() {
		$editing = isset( $_GET['campaign'] ) ? (int) $_GET['campaign'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$is_new  = isset( $_GET['new'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $editing || $is_new ) {
			$this->render_campaign_editor( $editing ? FW_Newsletter_CRM_Campaigns::find( $editing ) : null );

			return;
		}

		$campaigns = FW_Newsletter_CRM_Campaigns::all();
		$base      = self::get_page_url();

		$labels = array(
			'draft'     => __( 'Draft', 'fw' ),
			'scheduled' => __( 'Scheduled', 'fw' ),
			'sending'   => __( 'Sending', 'fw' ),
			'paused'    => __( 'Paused', 'fw' ),
			'sent'      => __( 'Sent', 'fw' ),
		);
		?>
		<p class="fw-crm-toolbar">
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'campaigns', 'new' => 1 ), $base ) ); ?>">
				<?php esc_html_e( 'New campaign', 'fw' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'campaigns', 'fw_crm_action' => 'run_sender' ), $base ), self::NONCE ) ); ?>">
				<?php esc_html_e( 'Run sending now', 'fw' ); ?>
			</a>
		</p>

		<p class="description" style="max-width:56em">
			<?php esc_html_e( 'Sending happens in small batches on WP-Cron so a big list cannot time out mid-send — every recipient is tracked individually, so a send always resumes exactly where it stopped. WP-Cron only fires when the site gets traffic, so on a quiet site use "Run sending now" to push the next batch by hand.', 'fw' ); ?>
		</p>

		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Campaign', 'fw' ); ?></th>
				<th style="width:110px"><?php esc_html_e( 'Status', 'fw' ); ?></th>
				<th style="width:220px"><?php esc_html_e( 'Progress', 'fw' ); ?></th>
				<th style="width:170px"><?php esc_html_e( 'When', 'fw' ); ?></th>
				<th style="width:200px"></th>
			</tr>
			</thead>
			<tbody>
			<?php if ( ! $campaigns ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No campaigns yet.', 'fw' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $campaigns as $c ) :
				$counts   = FW_Newsletter_CRM_Campaigns::queue_counts( $c->id );
				$total    = max( 1, (int) $counts['total'] );
				$done     = (int) $counts['sent'] + (int) $counts['failed'] + (int) $counts['skipped'];
				$percent  = $counts['total'] ? (int) round( ( $done / $total ) * 100 ) : 0;
				$editable = in_array( $c->status, array( 'draft', 'scheduled' ), true );
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'campaigns', 'campaign' => (int) $c->id ), $base ) ); ?>">
							<strong><?php echo esc_html( $c->title ); ?></strong>
						</a>
						<div class="row-actions"><span><?php echo esc_html( $c->subject ); ?></span></div>
					</td>
					<td>
						<span class="fw-crm-status fw-crm-status--<?php echo esc_attr( $c->status ); ?>">
							<?php echo esc_html( isset( $labels[ $c->status ] ) ? $labels[ $c->status ] : $c->status ); ?>
						</span>
					</td>
					<td>
						<?php if ( $counts['total'] ) : ?>
							<div class="fw-crm-bar"><div class="fw-crm-bar__fill" style="width:<?php echo (int) $percent; ?>%"></div></div>
							<small>
								<?php
								printf(
									/* translators: 1: sent, 2: total, 3: failed, 4: skipped */
									esc_html__( '%1$s of %2$s sent · %3$s failed · %4$s skipped', 'fw' ),
									esc_html( number_format_i18n( $counts['sent'] ) ),
									esc_html( number_format_i18n( $counts['total'] ) ),
									esc_html( number_format_i18n( $counts['failed'] ) ),
									esc_html( number_format_i18n( $counts['skipped'] ) )
								);
								?>
							</small>
						<?php else : ?>
							<small>
								<?php
								printf(
									/* translators: %s: number of recipients */
									esc_html__( '%s recipients right now', 'fw' ),
									esc_html( number_format_i18n( FW_Newsletter_CRM_Service::audience_count( $c ) ) )
								);
								?>
							</small>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$when = $c->finished_at ? $c->finished_at : ( $c->scheduled_at ? $c->scheduled_at : $c->created_at );
						echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $when ) ) );
						?>
					</td>
					<td>
						<?php if ( 'sending' === $c->status || 'scheduled' === $c->status ) : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'campaigns', 'fw_crm_action' => 'pause_campaign', 'id' => (int) $c->id ), $base ), self::NONCE ) ); ?>"><?php esc_html_e( 'Pause', 'fw' ); ?></a>
						<?php elseif ( 'paused' === $c->status ) : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'campaigns', 'fw_crm_action' => 'resume_campaign', 'id' => (int) $c->id ), $base ), self::NONCE ) ); ?>"><?php esc_html_e( 'Resume', 'fw' ); ?></a>
						<?php endif; ?>
						<a class="fw-crm-remove" style="margin-left:.6em"
						   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'campaigns', 'fw_crm_action' => 'delete_campaign', 'id' => (int) $c->id ), $base ), self::NONCE ) ); ?>"
						   onclick="return confirm('<?php echo esc_js( __( 'Delete this campaign and its send log?', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param object|null $campaign
	 */
	private function render_campaign_editor( $campaign ) {
		$audience = $campaign ? FW_Newsletter_CRM_Campaigns::audience_args( $campaign ) : array();
		$editable = ! $campaign || in_array( $campaign->status, array( 'draft', 'scheduled' ), true );
		$lists    = FW_Newsletter_CRM_Lists::all( 'list' );
		$tags     = FW_Newsletter_CRM_Lists::all( 'tag' );
		$segments = FW_Newsletter_CRM_Lists::segments();
		$count    = FW_Newsletter_CRM_Service::audience_count( $campaign ? $campaign : array() );
		?>
		<p><a href="<?php echo esc_url( self::get_page_url( 'campaigns' ) ); ?>">&larr; <?php esc_html_e( 'Back to campaigns', 'fw' ); ?></a></p>

		<?php if ( ! $editable ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'This campaign has started sending, so it is read-only — editing it now would mean half the list received a different email from the other half.', 'fw' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="" class="fw-crm-campaign-editor">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="id" value="<?php echo $campaign ? (int) $campaign->id : 0; ?>" />

			<?php
			// Postboxes, exactly as the Settings tab gets them:
			// fw()->backend->render_box() is what FW_Container_Type_Box calls for a
			// `box` option container, and the wrapper below is its markup too. Going
			// through the same call means the two screens render identically rather
			// than merely looking similar — and stay that way if the framework's box
			// chrome ever changes.
			$boxes = '';
			ob_start();
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fw-crm-title"><?php esc_html_e( 'Name', 'fw' ); ?></label></th>
					<td>
						<input type="text" id="fw-crm-title" name="title" class="regular-text" required
						       <?php disabled( ! $editable ); ?>
						       value="<?php echo esc_attr( $campaign ? $campaign->title : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Internal only — subscribers never see this.', 'fw' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fw-crm-subject"><?php esc_html_e( 'Subject', 'fw' ); ?></label></th>
					<td>
						<input type="text" id="fw-crm-subject" name="subject" class="large-text" required
						       <?php disabled( ! $editable ); ?>
						       value="<?php echo esc_attr( $campaign ? $campaign->subject : '' ); ?>" />
					</td>
				</tr>
			</table>
			<?php
			// `prevent-auto-close` is the framework's own opt-out: backend-options.js
			// collapses every box after the first, which is right for a long settings
			// screen but wrong for a form — it would hide the message editor and the
			// audience picker behind a click on a page you came to fill in.
			$boxes .= fw()->backend->render_box(
				'fw-crm-box-details',
				__( 'Campaign details', 'fw' ),
				ob_get_clean(),
				array( 'attr' => array( 'class' => 'prevent-auto-close' ) )
			);
			ob_start();
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Editor', 'fw' ); ?></th>
					<td>
						<?php
						$has_blocks = $campaign && ! empty( $campaign->body_json )
							&& FW_Newsletter_CRM_Email_Compiler::normalize( $campaign->body_json );
						$mode = $has_blocks ? 'builder' : 'visual';
						?>
						<label style="margin-right:1.2em">
							<input type="radio" name="editor_mode" value="visual" <?php checked( 'visual', $mode ); ?> <?php disabled( ! $editable ); ?> />
							<?php esc_html_e( 'Visual editor', 'fw' ); ?>
						</label>
						<label>
							<input type="radio" name="editor_mode" value="builder" <?php checked( 'builder', $mode ); ?> <?php disabled( ! $editable ); ?> />
							<?php esc_html_e( 'Email builder', 'fw' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'The visual editor is quicker for a short note. The email builder assembles blocks and compiles them to table-based HTML that survives Outlook and Gmail — use it for anything designed.', 'fw' ); ?>
						</p>
					</td>
				</tr>

				<tr class="fw-crm-mode fw-crm-mode--builder">
					<th scope="row"><?php esc_html_e( 'Blocks', 'fw' ); ?></th>
					<td>
						<?php
						if ( $editable ) {
							$blocks = $campaign && ! empty( $campaign->body_json )
								? FW_Newsletter_CRM_Email_Compiler::normalize( $campaign->body_json )
								: array();

							echo fw()->backend->render_options( // phpcs:ignore WordPress.Security.EscapeOutput
								array(
									'body_json' => array(
										'type'  => 'email-builder',
										'label' => false,
										'desc'  => false,
									),
								),
								// The builder's value shape is array( 'json' => '<string>' ) —
								// hand it a bare array and it renders an empty canvas.
								array( 'body_json' => FW_Newsletter_CRM_Email_Compiler::to_option_value( $blocks ) )
							);
						} else {
							esc_html_e( 'This campaign has started sending, so its blocks are locked.', 'fw' );
						}

						$this->placeholder_help( 'builder' );
						?>
					</td>
				</tr>

				<tr class="fw-crm-mode fw-crm-mode--visual">
					<th scope="row"><label for="fw_crm_body"><?php esc_html_e( 'Message', 'fw' ); ?></label></th>
					<td>
						<?php
						$body = $campaign ? $campaign->body : '';

						if ( $editable ) {
							// The same TinyMCE used for posts. Note the editor ID is
							// `fw_crm_body` — wp_editor() only accepts lowercase
							// letters and underscores, so a hyphen here silently
							// breaks the editor.
							wp_editor( $body, 'fw_crm_body', array(
								'textarea_name' => 'body',
								'textarea_rows' => 16,
								'media_buttons' => true,
								'teeny'         => false,
								'tinymce'       => array(
									// Keep the toolbar to things email clients can
									// actually render — no columns, no floats.
									'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_adv',
									'toolbar2' => 'strikethrough,hr,forecolor,pastetext,removeformat,undo,redo',
								),
								'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
							) );
						} else {
							// A sent campaign is read-only, and TinyMCE has no honest
							// disabled state — so show the message instead of an
							// editor the user cannot use.
							echo '<div class="fw-crm-body-preview">' . wp_kses_post( $body ) . '</div>';
						}
						?>
						<?php $this->placeholder_help( 'visual' ); ?>
					</td>
				</tr>
			</table>
			<?php
			$boxes .= fw()->backend->render_box(
				'fw-crm-box-content',
				__( 'Content', 'fw' ),
				ob_get_clean(),
				array( 'attr' => array( 'class' => 'prevent-auto-close' ) )
			);
			ob_start();
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send to', 'fw' ); ?></th>
					<td>
						<label>
							<?php esc_html_e( 'List', 'fw' ); ?>
							<select name="a_list" <?php disabled( ! $editable ); ?>>
								<option value=""><?php esc_html_e( 'Everyone', 'fw' ); ?></option>
								<?php foreach ( $lists as $l ) : ?>
									<option value="<?php echo esc_attr( $l->slug ); ?>" <?php selected( isset( $audience['list'] ) ? $audience['list'] : '', $l->slug ); ?>><?php echo esc_html( $l->title ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label style="margin-left:1em">
							<?php esc_html_e( 'Tag', 'fw' ); ?>
							<select name="a_tag" <?php disabled( ! $editable ); ?>>
								<option value=""><?php esc_html_e( 'Any', 'fw' ); ?></option>
								<?php foreach ( $tags as $t ) : ?>
									<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( isset( $audience['tag'] ) ? $audience['tag'] : '', $t->slug ); ?>><?php echo esc_html( $t->title ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label style="margin-left:1em">
							<?php esc_html_e( 'Segment', 'fw' ); ?>
							<select name="a_segment" <?php disabled( ! $editable ); ?>>
								<option value="0"><?php esc_html_e( 'None', 'fw' ); ?></option>
								<?php foreach ( $segments as $s ) : ?>
									<option value="<?php echo (int) $s->id; ?>" <?php selected( isset( $audience['segment'] ) ? (int) $audience['segment'] : 0, (int) $s->id ); ?>><?php echo esc_html( $s->title ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: number of subscribers */
								esc_html__( 'Only confirmed subscribers are ever mailed — pending, unsubscribed, bounced and complained addresses are excluded automatically. This campaign currently matches %s.', 'fw' ),
								'<strong>' . esc_html( sprintf( _n( '%s person', '%s people', $count, 'fw' ), number_format_i18n( $count ) ) ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php
			$boxes .= fw()->backend->render_box(
				'fw-crm-box-audience',
				__( 'Audience', 'fw' ),
				ob_get_clean(),
				array( 'attr' => array( 'class' => 'prevent-auto-close' ) )
			);

			ob_start();
			?>
			<div class="fw-crm-preview">
				<div class="fw-crm-preview__bar">
					<span class="fw-crm-preview__group">
						<button type="button" class="button button-primary fw-crm-preview__refresh"><?php esc_html_e( 'Refresh preview', 'fw' ); ?></button>
					</span>
					<span class="fw-crm-preview__group" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'fw' ); ?>">
						<button type="button" class="button fw-crm-preview__width is-active" data-width="600"><?php esc_html_e( 'Desktop', 'fw' ); ?></button>
						<button type="button" class="button fw-crm-preview__width" data-width="375"><?php esc_html_e( 'Mobile', 'fw' ); ?></button>
					</span>
					<span class="fw-crm-preview__group" role="group" aria-label="<?php esc_attr_e( 'Preview format', 'fw' ); ?>">
						<button type="button" class="button fw-crm-preview__format is-active" data-format="html"><?php esc_html_e( 'HTML', 'fw' ); ?></button>
						<button type="button" class="button fw-crm-preview__format" data-format="text"><?php esc_html_e( 'Plain text', 'fw' ); ?></button>
					</span>
					<button type="submit" class="button fw-crm-preview__tab" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					        formtarget="_blank" name="action" value="fw_crm_preview_tab"><?php esc_html_e( 'Open in new tab', 'fw' ); ?></button>
					<span class="fw-crm-preview__size" aria-live="polite"></span>
				</div>

				<p class="fw-crm-preview__subject description"></p>

				<div class="fw-crm-preview__stage">
					<?php
					/**
					 * `sandbox` with NO allow-* tokens: the preview renders whatever
					 * HTML the author put in a Raw HTML block, so it must not be able
					 * to run script, submit forms, navigate the admin page, or reach
					 * back into it via same-origin. srcdoc still works fully sandboxed.
					 */
					?>
					<iframe class="fw-crm-preview__frame" sandbox="" title="<?php esc_attr_e( 'Email preview', 'fw' ); ?>"></iframe>
					<pre class="fw-crm-preview__text" hidden></pre>
				</div>
			</div>
			<?php
			$boxes .= fw()->backend->render_box(
				'fw-crm-box-preview',
				__( 'Preview', 'fw' ),
				ob_get_clean(),
				array( 'attr' => array( 'class' => 'prevent-auto-close' ) )
			);

			echo '<div class="fw-backend-postboxes metabox-holder">' . $boxes . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			?>

			<?php if ( $editable ) : ?>
				<p class="submit" style="display:flex;gap:.5em;flex-wrap:wrap;align-items:center">
					<button type="submit" name="fw_crm_action" value="save_campaign" class="button"><?php esc_html_e( 'Save draft', 'fw' ); ?></button>
					<button type="submit" name="fw_crm_action" value="send_campaign" class="button button-primary"
					        onclick="return confirm('<?php echo esc_js( __( 'Queue this campaign for sending to everyone who matches?', 'fw' ) ); ?>');"><?php esc_html_e( 'Send now', 'fw' ); ?></button>
					<span style="margin-left:1em">
						<input type="datetime-local" name="scheduled_at" />
						<button type="submit" name="fw_crm_action" value="schedule_campaign" class="button"><?php esc_html_e( 'Schedule', 'fw' ); ?></button>
					</span>
				</p>
				<p style="display:flex;gap:.5em;align-items:center">
					<input type="email" name="test_email" placeholder="<?php esc_attr_e( 'you@example.com', 'fw' ); ?>"
					       value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<button type="submit" name="fw_crm_action" value="test_campaign" class="button"><?php esc_html_e( 'Send a test', 'fw' ); ?></button>
				</p>
			<?php endif; ?>
		</form>

		<script>
		( function () {
			var form = document.querySelector( '.fw-crm-campaign-editor' );
			if ( ! form ) { return; }
			function sync() {
				var mode = ( form.querySelector( 'input[name="editor_mode"]:checked' ) || {} ).value || 'visual';
				form.querySelectorAll( '.fw-crm-mode' ).forEach( function ( row ) {
					row.style.display = row.classList.contains( 'fw-crm-mode--' + mode ) ? '' : 'none';
				} );
			}
			form.querySelectorAll( 'input[name="editor_mode"]' ).forEach( function ( r ) {
				r.addEventListener( 'change', sync );
			} );
			sync();

			/* --- Preview ------------------------------------------------- */

			var root = form.querySelector( '.fw-crm-preview' );
			if ( ! root ) { return; }

			var frame   = root.querySelector( '.fw-crm-preview__frame' );
			var textEl  = root.querySelector( '.fw-crm-preview__text' );
			var sizeEl  = root.querySelector( '.fw-crm-preview__size' );
			var subjEl  = root.querySelector( '.fw-crm-preview__subject' );
			var l10n    = <?php echo wp_json_encode( array(
				'loading' => __( 'Rendering…', 'fw' ),
				'failed'  => __( 'Could not render the preview.', 'fw' ),
				'subject' => __( 'Subject: %s', 'fw' ),
				'empty'   => __( '(no subject yet)', 'fw' ),
				'size'    => __( '%1$s KB — %2$s%% of the %3$s KB Gmail clips at', 'fw' ),
				'clipped' => __( 'Over the %s KB limit — Gmail will clip this email and hide the unsubscribe link.', 'fw' ),
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
			) ); ?>;

			function sprintf( s ) {
				var args = Array.prototype.slice.call( arguments, 1 );
				return s.replace( /%(\d+)\$s|%s|%%/g, function ( m, n ) {
					if ( m === '%%' ) { return '%'; }
					return args[ n ? n - 1 : 0 ];
				} );
			}

			// TinyMCE keeps the authored content in its iframe until told to
			// flush it, so without this a preview of the visual editor shows the
			// last SAVED body rather than what is on screen.
			function flushEditors() {
				if ( window.tinyMCE && window.tinyMCE.triggerSave ) { window.tinyMCE.triggerSave(); }
			}

			function render( data ) {
				subjEl.textContent = sprintf( l10n.subject, data.subject || l10n.empty );
				frame.srcdoc       = data.html;
				textEl.textContent = data.text;

				var kb = Math.round( data.size.bytes / 1024 * 10 ) / 10;
				sizeEl.textContent = sprintf(
					l10n.size, kb, data.size.percent, Math.round( data.size.limit / 1024 )
				);
				sizeEl.classList.toggle( 'is-over', !! data.size.clipped );
				sizeEl.title = data.size.clipped
					? sprintf( l10n.clipped, Math.round( data.size.limit / 1024 ) )
					: '';
			}

			var busy = false;

			function refresh() {
				if ( busy ) { return; }
				busy = true;
				flushEditors();
				sizeEl.textContent = l10n.loading;

				var body = new FormData( form );
				body.append( 'action', 'fw_crm_preview' );
				body.append( 'nonce', l10n.nonce );

				fetch( l10n.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {
						if ( r && r.success ) { render( r.data ); }
						else { sizeEl.textContent = ( r && r.data && r.data.message ) || l10n.failed; }
					} )
					.catch( function () { sizeEl.textContent = l10n.failed; } )
					.then( function () { busy = false; } );
			}

			root.querySelector( '.fw-crm-preview__refresh' ).addEventListener( 'click', refresh );

			root.querySelectorAll( '.fw-crm-preview__width' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					root.querySelectorAll( '.fw-crm-preview__width' ).forEach( function ( o ) {
						o.classList.toggle( 'is-active', o === b );
					} );
					frame.style.width = b.dataset.width + 'px';
				} );
			} );

			root.querySelectorAll( '.fw-crm-preview__format' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					root.querySelectorAll( '.fw-crm-preview__format' ).forEach( function ( o ) {
						o.classList.toggle( 'is-active', o === b );
					} );
					var html = b.dataset.format === 'html';
					frame.hidden  = ! html;
					textEl.hidden = html;
				} );
			} );

			// The new-tab button submits the form, so flush TinyMCE for it too.
			root.querySelector( '.fw-crm-preview__tab' ).addEventListener( 'click', flushEditors );

			refresh();
		}() );
		</script>
		<?php
	}

	/**
	 * Lists, tags and segments. Lists and tags render from the same code because
	 * they ARE the same table — only `type` differs.
	 */
	private function render_lists_tab() {
		?>
		<div class="fw-crm-panels">
			<?php
			$this->render_object_panel( 'list', __( 'Lists', 'fw' ), __( 'A list is something a subscriber joins — the [newsletter] element\'s List ID resolves to one of these. Deleting a list never deletes its subscribers.', 'fw' ) );
			$this->render_object_panel( 'tag', __( 'Tags', 'fw' ), __( 'A tag is something you attach — interests, behaviour, source. Apply them in bulk from the Subscribers tab.', 'fw' ) );
			?>
		</div>

		<?php $this->render_segments_panel(); ?>
		<?php
	}

	/**
	 * @param string $type  list|tag
	 * @param string $title
	 * @param string $intro
	 */
	private function render_object_panel( $type, $title, $intro ) {
		$rows   = FW_Newsletter_CRM_Lists::all( $type );
		$counts = FW_Newsletter_CRM_Lists::counts( $type );
		$base   = self::get_page_url();
		?>
		<div class="fw-crm-panel">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="description"><?php echo esc_html( $intro ); ?></p>

			<table class="widefat striped fw-crm-objects">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'fw' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Slug', 'fw' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Subscribed', 'fw' ); ?></th>
					<th style="width:70px"></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'None yet.', 'fw' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) :
					$count = isset( $counts[ (int) $row->id ] ) ? (int) $counts[ (int) $row->id ] : 0;
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', $type => $row->slug ), $base ) ); ?>">
								<strong><?php echo esc_html( $row->title ); ?></strong>
							</a>
						</td>
						<td><code><?php echo esc_html( $row->slug ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						<td>
							<a class="fw-crm-remove"
							   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'lists', 'fw_crm_action' => 'delete_list', 'id' => (int) $row->id ), $base ), self::NONCE ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this? Subscribers stay, they just stop being members of it.', 'fw' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'fw' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="" class="fw-crm-inline-add">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="save_list" />
				<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
				<input type="text" name="title" required
				       placeholder="<?php echo 'tag' === $type ? esc_attr__( 'New tag name', 'fw' ) : esc_attr__( 'New list name', 'fw' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Add', 'fw' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_segments_panel() {
		$segments = FW_Newsletter_CRM_Lists::segments();
		$base     = self::get_page_url();
		?>
		<div class="fw-crm-panel fw-crm-panel--wide">
			<h2><?php esc_html_e( 'Segments', 'fw' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'A segment is a saved search, not a fixed group — it is re-evaluated every time you open it, so someone who newly matches is simply in it. Build one by filtering the Subscribers tab and clicking "Save as segment".', 'fw' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
				<tr>
					<th style="width:220px"><?php esc_html_e( 'Segment', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Matches', 'fw' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Subscribers', 'fw' ); ?></th>
					<th style="width:70px"></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( ! $segments ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No segments saved yet.', 'fw' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $segments as $segment ) :
					$filters = FW_Newsletter_CRM_Lists::segment_query_args( $segment );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'segment' => (int) $segment->id ), $base ) ); ?>">
								<strong><?php echo esc_html( $segment->title ); ?></strong>
							</a>
						</td>
						<td><?php echo esc_html( FW_Newsletter_CRM_Service::describe_filters( $filters ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( FW_Newsletter_CRM_Subscribers::count( $filters ) ) ); ?></td>
						<td>
							<a class="fw-crm-remove"
							   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'lists', 'fw_crm_action' => 'delete_segment', 'id' => (int) $segment->id ), $base ), self::NONCE ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this segment? The subscribers it matched are not affected.', 'fw' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'fw' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_tools_tab() {
		$step   = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$parked = get_transient( self::TRANSIENT_IMPORT . get_current_user_id() );

		if ( 'run' === $step && $parked && ! empty( $parked['running'] ) ) {
			$this->render_import_progress( $parked );

			return;
		}

		if ( 'map' === $step && $parked ) {
			$this->render_import_mapping( $parked );

			return;
		}
		?>
		<div class="fw-crm-panels">
			<div class="fw-crm-panel">
				<h2><?php esc_html_e( 'Import subscribers', 'fw' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Upload a CSV with a header row. The next step lets you match its columns to subscriber fields. Existing addresses are updated, not duplicated, and people who previously unsubscribed are skipped unless you say otherwise.', 'fw' ); ?>
				</p>
				<form method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="import_upload" />
					<p><input type="file" name="fw_crm_csv" accept=".csv,text/csv" required /></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and continue', 'fw' ); ?></button></p>
				</form>
			</div>

			<div class="fw-crm-panel">
				<h2><?php esc_html_e( 'Export subscribers', 'fw' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Downloads every subscriber as CSV. To export a subset instead, filter the Subscribers tab first and use the export button there.', 'fw' ); ?>
				</p>
				<p>
					<a class="button button-primary"
					   href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'fw_crm_action', 'export', self::get_page_url() ), self::NONCE ) ); ?>">
						<?php esc_html_e( 'Export all (CSV)', 'fw' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The chunked-import progress screen. Each tick imports a batch and returns
	 * the byte offset it reached, so a file far too big for one request still
	 * completes — and a closed tab just stops it, leaving the rows already
	 * imported intact and resumable-safe rather than half-written.
	 *
	 * @param array $job
	 */
	private function render_import_progress( array $job ) {
		$size = ! empty( $job['file'] ) && file_exists( $job['file'] ) ? (int) filesize( $job['file'] ) : 0;
		?>
		<div class="fw-crm-panel fw-crm-import-progress">
			<h2><?php esc_html_e( 'Importing…', 'fw' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: file size */
					esc_html__( 'Working through the file (%s) in batches. Keep this tab open — closing it stops the import, and everything already imported is kept.', 'fw' ),
					esc_html( size_format( $size ) )
				);
				?>
			</p>

			<div class="fw-crm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="fw-crm-bar__fill" style="width:0"></div>
			</div>

			<p class="fw-crm-import-stats" aria-live="polite">
				<span class="fw-crm-import-pct">0%</span> —
				<span class="fw-crm-import-counts"><?php esc_html_e( 'starting…', 'fw' ); ?></span>
			</p>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'tools', 'fw_crm_action' => 'import_cancel' ), self::get_page_url() ), self::NONCE ) ); ?>">
					<?php esc_html_e( 'Cancel', 'fw' ); ?>
				</a>
			</p>
		</div>

		<script>
		( function () {
			var wrap  = document.querySelector( '.fw-crm-import-progress' );
			if ( ! wrap ) { return; }
			var bar   = wrap.querySelector( '.fw-crm-bar__fill' );
			var meter = wrap.querySelector( '.fw-crm-bar' );
			var pct   = wrap.querySelector( '.fw-crm-import-pct' );
			var count = wrap.querySelector( '.fw-crm-import-counts' );
			var url   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
			var i18n  = <?php echo wp_json_encode( array(
				'counts' => __( '%1$s added · %2$s updated · %3$s skipped · %4$s failed', 'fw' ),
				'failed' => __( 'The import stopped:', 'fw' ),
			) ); ?>;

			function fmt( s ) {
				return i18n.counts
					.replace( '%1$s', s.created ).replace( '%2$s', s.updated )
					.replace( '%3$s', s.skipped ).replace( '%4$s', s.failed );
			}

			function step() {
				var body = new FormData();
				body.append( 'action', 'fw_crm_import_step' );
				body.append( 'nonce', nonce );

				fetch( url, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							count.textContent = i18n.failed + ' ' + ( res && res.data ? res.data.message : '' );
							return;
						}
						var d = res.data;
						bar.style.width = d.percent + '%';
						meter.setAttribute( 'aria-valuenow', d.percent );
						pct.textContent = d.percent + '%';
						count.textContent = fmt( d.stats );

						if ( d.done ) { window.location.href = d.redirect; return; }
						step();
					} )
					.catch( function ( e ) { count.textContent = i18n.failed + ' ' + e; } );
			}

			step();
		}() );
		</script>
		<?php
	}

	/**
	 * @param array $parked
	 */
	private function render_import_mapping( array $parked ) {
		$fields  = FW_Newsletter_CRM_CSV::fields();
		$fields['name'] = __( 'Name (whole)', 'fw' );
		$guess   = FW_Newsletter_CRM_CSV::guess_mapping( $parked['header'] );
		?>
		<h2><?php esc_html_e( 'Match the columns', 'fw' ); ?></h2>
		<p class="description"><?php esc_html_e( 'One column must be mapped to Email. Anything left as “Ignore” is skipped.', 'fw' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="import_run" />

			<table class="widefat striped" style="max-width:900px">
				<thead>
				<tr>
					<th><?php esc_html_e( 'CSV column', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Sample', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Import as', 'fw' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( (array) $parked['header'] as $index => $label ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td>
							<?php
							$samples = array();

							foreach ( (array) $parked['rows'] as $row ) {
								if ( isset( $row[ $index ] ) && '' !== $row[ $index ] ) {
									$samples[] = $row[ $index ];
								}
							}

							echo $samples ? esc_html( implode( ', ', array_slice( $samples, 0, 2 ) ) ) : '<span aria-hidden="true">—</span>';
							?>
						</td>
						<td>
							<select name="map[<?php echo (int) $index; ?>]">
								<option value=""><?php esc_html_e( '— Ignore —', 'fw' ); ?></option>
								<?php foreach ( $fields as $key => $field_label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"
										<?php selected( isset( $guess[ $index ] ) ? $guess[ $index ] : '', $key ); ?>>
										<?php echo esc_html( $field_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1em">
				<label>
					<?php esc_html_e( 'Add everyone to this list:', 'fw' ); ?>
					<?php $this->render_list_select( 'import_list' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="overwrite_unsubscribed" value="1" />
					<?php esc_html_e( 'Also re-subscribe people who previously unsubscribed (only tick this if you have their fresh consent)', 'fw' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run import', 'fw' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::get_page_url( 'tools' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	private function render_settings_tab() {
		$schema = $this->ext->get_settings_options();
		$values = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="save_settings" />
			<?php echo fw()->backend->render_options( $schema, $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
			</p>
		</form>

		<div class="fw-crm-danger">
			<h2><?php esc_html_e( 'Remove all data', 'fw' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: subscriber count */
					esc_html__( 'Permanently deletes the %s stored subscribers along with the lists, tags, segments and their tables. Deactivating the extension does NOT do this — data is only ever removed here, on purpose.', 'fw' ),
					esc_html( number_format_i18n( FW_Newsletter_CRM_Subscribers::count() ) )
				);
				?>
			</p>
			<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'This permanently deletes every subscriber. Continue?', 'fw' ) ); ?>');">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="remove_data" />
				<p>
					<input type="text" name="confirm" placeholder="<?php esc_attr_e( 'Type DELETE', 'fw' ); ?>" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove all subscriber data', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string $name
	 */
	private function render_list_select( $name ) {
		$lists = FW_Newsletter_CRM_Lists::all( 'list' );
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value=""><?php esc_html_e( '— Default list —', 'fw' ); ?></option>
			<?php foreach ( $lists as $list ) : ?>
				<option value="<?php echo esc_attr( $list->slug ); ?>"><?php echo esc_html( $list->title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
