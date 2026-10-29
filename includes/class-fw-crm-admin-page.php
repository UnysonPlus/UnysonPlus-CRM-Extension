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
class FW_CRM_Admin_Page {

	const PARENT_SLUG      = 'fw-extensions';
	const PAGE_SLUG        = 'fw-crm';
	const NONCE            = 'fw_crm_action';
	const TRANSIENT_NOTICE = 'fw_ext_crm_notice_';

	/** @var FW_Extension_CRM */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	/** @var FW_CRM_List_Table|null */
	private $table = null;

	/**
	 * @param FW_Extension_CRM $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
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
		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			return;
		}

		// The CRM is a business app with its own operator roles (CRM managers, sales
		// reps), so it sits at the TOP LEVEL for everyone rather than inside the Unyson+
		// (developer / site-config) menu. It is a destination you work in daily, not a
		// tool you configure, so it leads beside the other apps (Membership, Forms,
		// Visitor Insights, SEO). Previously admins saw it as a Unyson+ submenu.
		$this->hook_suffix = add_menu_page(
			__( 'CRM', 'fw' ),
			__( 'CRM', 'fw' ),
			FW_CRM_Service::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-id-alt',
			26
		);

		if ( $this->hook_suffix ) {
			FW_CRM_App::init( $this->hook_suffix );
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
			'fw-ext-crm',
			$this->ext->get_uri() . '/static/css/admin.css',
			array(),
			$this->ext->manifest->get_version()
		);
		wp_enqueue_style(
			'fw-ext-crm-app',
			$this->ext->get_uri() . '/static/css/app.css',
			array( 'fw-ext-crm', 'dashicons' ),
			$this->ext->manifest->get_version()
		);
		wp_enqueue_script(
			'fw-ext-crm-app',
			$this->ext->get_uri() . '/static/js/app.js',
			array(),
			$this->ext->manifest->get_version(),
			true
		);
		if ( isset( $_GET['edit_segment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_script(
				'fw-ext-crm-segments',
				$this->ext->get_uri() . '/static/js/segments.js',
				array(),
				$this->ext->manifest->get_version(),
				true
			);
		}
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
			add_query_arg( array( 'fw_crm_action' => $action, 'id' => (int) $id ), self::get_page_url( 'subscribers' ) ),
			self::NONCE
		);
	}

	/**
	 * @return string
	 */
	private function current_tab() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['subscriber'] ) ) {
			return 'subscribers';
		}
		if ( ! empty( $_GET['company'] ) && empty( $_GET['tab'] ) ) {
			return 'companies';
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home';
		// phpcs:enable

		return in_array( $tab, FW_CRM_App::views(), true ) ? $tab : 'home';
	}

	/* ---------------------------------------------------------------------- *
	 * Request handling — all of it before any output
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_load() {
		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			return;
		}

		// The list table must exist before the screen renders so its per-page
		// option and column headers are registered.
		$this->table = new FW_CRM_List_Table();

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

		// Team roles: what a sales rep may not do at all, and records they do not own.
		$managers_only = array(
			'export', 'add', 'delete', 'save_campaign', 'send_campaign', 'schedule_campaign', 'test_campaign',
			'pause_campaign', 'resume_campaign', 'delete_campaign', 'run_sender', 'save_list', 'delete_list',
			'save_segment', 'delete_segment', 'import_upload', 'import_run', 'import_cancel', 'save_settings',
			'remove_data', 'import_map', 'import_undo', 'purge_contact', 'purge_company', 'empty_bin', 'merge_contacts', 'save_rule_segment',
			'bulk_delete', 'bulk_subscribe', 'rename_tag', 'merge_tags',
		);
		if ( in_array( $action, $managers_only, true ) && ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		$campaign_actions = array( 'save_campaign', 'send_campaign', 'schedule_campaign', 'test_campaign', 'pause_campaign', 'resume_campaign', 'delete_campaign', 'run_sender' );
		if ( in_array( $action, $campaign_actions, true ) && ! FW_CRM_Modules::enabled( 'email' ) ) {
			wp_die( esc_html__( 'Email marketing is turned off. Turn it on in CRM settings first.', 'fw' ), 403 );
		}
		if ( 'rename_tag' === $action || 'merge_tags' === $action ) {
			// phpcs:disable WordPress.Security.NonceVerification -- checked above.
			$tag = isset( $_POST['tag_id'] ) ? (int) $_POST['tag_id'] : 0;
			if ( 'rename_tag' === $action ) {
				$result = FW_CRM_Tags::rename( $tag, isset( $_POST['tag_title'] ) ? wp_unslash( $_POST['tag_title'] ) : '', isset( $_POST['tag_slug'] ) ? wp_unslash( $_POST['tag_slug'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by rename().
				$this->notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Tag renamed.', 'fw' ) );
			} else {
				$result = FW_CRM_Tags::merge( $tag, isset( $_POST['into_id'] ) ? (int) $_POST['into_id'] : 0 );
				$this->notice(
					is_wp_error( $result ) ? 'error' : 'success',
					is_wp_error( $result ) ? $result->get_error_message() : sprintf( _n( 'Tags merged: %s contact moved.', 'Tags merged: %s contacts moved.', (int) $result, 'fw' ), number_format_i18n( (int) $result ) )
				);
			}
			// phpcs:enable
			$this->redirect( array( 'tab' => 'lists' ) );
		}

		$record = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$on_company = in_array( $action, array( 'save_company', 'trash_company', 'restore_company', 'link_contact', 'purge_company' ), true )
			|| ( 'add_note' === $action && isset( $_POST['object'] ) && 'company' === $_POST['object'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$on_deal    = 'add_note' === $action && isset( $_POST['object'] ) && 'deal' === $_POST['object']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $record && ! FW_CRM_Capabilities::sees_all() && 0 !== strpos( $action, 'task_' ) && 0 !== strpos( $action, 'deal_' ) && 0 !== strpos( $action, 'pipeline_' ) ) {
			$allowed = $on_deal ? FW_CRM_Capabilities::can_edit_deal( $record ) : ( $on_company ? FW_CRM_Capabilities::can_edit_company( $record ) : FW_CRM_Capabilities::can_edit_contact( $record ) );
			if ( ! $allowed ) {
				wp_die( esc_html__( 'That record belongs to someone else.', 'fw' ), 403 );
			}
		}
		if ( 0 === strpos( $action, 'bulk_' ) && ! FW_CRM_Capabilities::sees_all() && isset( $_REQUEST['subscribers'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Bulk actions only ever touch the rep's own contacts.
			$_REQUEST['subscribers'] = array_values( array_filter( array_map( 'intval', (array) $_REQUEST['subscribers'] ), array( 'FW_CRM_Capabilities', 'can_edit_contact' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		switch ( $action ) {
			case 'export':
				$export = FW_CRM_List_Table::request_query_args();
				/** Fires before contacts are exported: the filters, and how many rows. */
				do_action( 'unysonplus_crm_exported', $export, FW_CRM_Subscribers::count( $export ) );
				// Streams and exits — hence the `load-` hook.
				FW_CRM_CSV::export( $export );
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

			case 'trash':
				$this->handle_single( 'trash' );
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

			case 'save_settings':
				$this->handle_save_settings();
				break;

			case 'remove_data':
				$this->handle_remove_data();
				break;

			default:
				if ( 0 === strpos( $action, 'bulk_' ) ) {
					$this->handle_bulk( substr( $action, 5 ) );
					break;
				}
				// The record screens (contact profile, companies) and the segment
				// builder own their actions.
				/**
				 * Lets modules and extensions handle their own CRM admin actions. Return
				 * redirect args (or array( '_back' => $admin_url )) when the action was yours.
				 */
				$args = apply_filters( 'unysonplus_crm_handle_action', null, $action );
				if ( null === $args ) {
					$args = FW_CRM_Admin_Import::handle( $action );
				}
				if ( null === $args ) {
					$args = FW_CRM_Admin_Segments::handle( $action );
				}
				if ( null === $args ) {
					$args = FW_CRM_Admin_Contacts::handle( $action );
				}
				if ( null !== $args ) {
					// Back to the page the action came from (a record page), when it is a CRM page.
					if ( ! empty( $args['_back'] ) && 0 === strpos( (string) $args['_back'], self::get_page_url() ) ) {
						wp_safe_redirect( (string) $args['_back'] );
						exit;
					}
					unset( $args['_back'] );
					wp_safe_redirect( add_query_arg( $args, self::get_page_url() ) );
					exit;
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
		self::flash( $type, $message );
	}

	/**
	 * Hand a notice across the next redirect (for the record screens).
	 *
	 * @param string $type success|error|warning
	 * @param string $message
	 */
	public static function flash( $type, $message ) {
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

		$result = FW_CRM_Service::subscribe( $email, array(
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
			FW_CRM_Service::delete( $id );
			$this->notice( 'success', __( 'Contact deleted.', 'fw' ) );

			return;
		}

		if ( 'trash' === $what ) {
			if ( FW_CRM_Contacts::trash( $id ) ) {
				$this->notice( 'success', sprintf(
					'%s <a href="%s">%s</a>',
					esc_html__( 'Contact moved to the bin.', 'fw' ),
					esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'restore_contact', 'id' => $id ), self::get_page_url( 'subscribers' ) ), self::NONCE ) ),
					esc_html__( 'Undo', 'fw' )
				) );
			}

			return;
		}

		if ( 'resend' === $what ) {
			$result = FW_CRM_Service::resend_confirmation( $id );

			$this->notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'A fresh confirmation link was emailed.', 'fw' )
			);

			$this->back_to_profile( $id );

			return;
		}

		$result = FW_CRM_Service::unsubscribe( $id );

		$this->notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Contact unsubscribed from email.', 'fw' )
		);

		$this->back_to_profile( $id );
	}

	/**
	 * Single actions started on the contact profile return to it.
	 *
	 * @param int $id
	 */
	private function back_to_profile( $id ) {
		if ( isset( $_GET['from'] ) && 'profile' === $_GET['from'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->redirect( array( 'subscriber' => (int) $id ) );
		}
	}

	/**
	 * @param string $what unsubscribe|subscribe|delete
	 */
	private function handle_bulk( $what ) {
		$ids = isset( $_REQUEST['subscribers'] ) ? array_map( 'intval', (array) $_REQUEST['subscribers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			$this->notice( 'warning', __( 'No contacts selected.', 'fw' ) );

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
			$done = FW_CRM_Service::set_membership( $ids, $tag_id, $op, 'tag' );
			$tag  = FW_CRM_Lists::find( $tag_id );

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
					$done += FW_CRM_Service::delete( $id ) ? 1 : 0;
					break;

				case 'trash':
					$done += FW_CRM_Contacts::trash( $id ) ? 1 : 0;
					break;

				case 'subscribe':
					$subscriber = FW_CRM_Subscribers::find( $id );

					if ( $subscriber ) {
						$result = FW_CRM_Service::subscribe( $subscriber->email, array(
							'status'       => 'subscribed',
							'source'       => $subscriber->source,
							'double_optin' => false,
						) );
						$done  += is_wp_error( $result ) ? 0 : 1;
					}
					break;

				case 'unsubscribe':
				default:
					$done += is_wp_error( FW_CRM_Service::unsubscribe( $id ) ) ? 0 : 1;
					break;
			}
		}

		$this->notice( 'success', sprintf(
			/* translators: %s: number of subscribers */
			'trash' === $what ? _n( '%s contact moved to the bin.', '%s contacts moved to the bin.', $done, 'fw' ) : _n( '%s contact updated.', '%s contacts updated.', $done, 'fw' ),
			number_format_i18n( $done )
		) );
	}

	/**
	 * Replace one subscriber's whole list and tag membership from the checkboxes
	 * on the single view.
	 */
	private function handle_save_membership() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( ! $id || ! FW_CRM_Subscribers::find( $id ) ) {
			$this->notice( 'error', __( 'Subscriber not found.', 'fw' ) );

			return;
		}

		foreach ( array( 'list', 'tag' ) as $type ) {
			$ids = isset( $_POST[ $type . 's' ] ) ? array_map( 'intval', (array) $_POST[ $type . 's' ] ) : array();
			// Managed lists and tags are shown read-only, so the form never carries
			// them: keep the ones the contact has, and never add one by hand.
			$managed = array();
			foreach ( FW_CRM_Lists::all( $type ) as $row ) {
				if ( ! empty( $row->managed_by ) ) {
					$managed[] = (int) $row->id;
				}
			}
			$held = array_map( 'intval', wp_list_pluck( FW_CRM_Subscribers::get_lists( $id, $type ), 'id' ) );
			$ids  = array_merge( array_diff( $ids, $managed ), array_intersect( $held, $managed ) );
			FW_CRM_Subscribers::set_lists( $id, $ids, $type );
		}

		$subscriber = FW_CRM_Subscribers::find( $id );

		do_action( 'unysonplus_crm_subscriber_updated', $subscriber, $subscriber, array( 'context' => 'membership' ) );

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
			'max_per_hour' => isset( $_POST['max_per_hour'] ) ? max( 0, (int) $_POST['max_per_hour'] ) : 0,
			'sender_id'    => isset( $_POST['sender_id'] ) ? sanitize_key( wp_unslash( $_POST['sender_id'] ) ) : '',
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

		$campaign = FW_CRM_Service::save_campaign( $payload );

		if ( is_wp_error( $campaign ) ) {
			$this->notice( 'error', $campaign->get_error_message() );

			return;
		}

		if ( 'test_campaign' === $action ) {
			$to     = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
			$result = FW_CRM_Service::send_test( $campaign->id, $to );

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

			$result = FW_CRM_Service::schedule_campaign( $campaign->id, $when );

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
			$sender = new FW_CRM_Sender();
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
				FW_CRM_Service::delete_campaign( $id ) ? 'success' : 'error',
				__( 'Campaign deleted.', 'fw' )
			);

			$this->redirect( array( 'tab' => 'campaigns' ) );
		}

		$result = 'pause_campaign' === $action
			? FW_CRM_Service::pause_campaign( $id )
			: FW_CRM_Service::resume_campaign( $id );

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
		$result = FW_CRM_Service::save_list( array(
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
		$result = FW_CRM_Service::delete_list( $id );

		$this->notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result )
				? $result->get_error_message()
				: __( 'Deleted. The contacts who were in it are untouched.', 'fw' )
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

		$result = FW_CRM_Service::save_segment( $filters, $title );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return;
		}

		$this->notice( 'success', sprintf(
			/* translators: 1: segment name, 2: what it matches */
			__( 'Segment "%1$s" saved — it matches %2$s.', 'fw' ),
			$result->title,
			FW_CRM_Service::describe_filters( FW_CRM_Lists::segment_query_args( $result ) )
		) );
	}

	private function handle_delete_segment() {
		$id      = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$deleted = FW_CRM_Service::delete_segment( $id );

		$this->notice(
			$deleted ? 'success' : 'error',
			$deleted ? __( 'Segment deleted.', 'fw' ) : __( 'That segment no longer exists.', 'fw' )
		);
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

		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'fw' ) ) );
		}

		$preview = FW_CRM_Service::preview( $this->preview_payload() );

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

		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'fw' ), 403 );
		}

		$preview = FW_CRM_Service::preview( $this->preview_payload() );

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

	private function handle_save_settings() {
		$before = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		$values = array_merge( $before, fw_get_options_values_from_input( $this->ext->get_settings_options() ) );

		fw_set_db_ext_settings_option( $this->ext->get_name(), null, $values );

		$changed = array();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
				$changed[] = $key;
			}
		}
		/** Fires after the CRM settings are saved: the keys that changed. */
		do_action( 'unysonplus_crm_settings_saved', $changed );

		$this->notice( 'success', __( 'Settings saved.', 'fw' ) );
	}

	/**
	 * The explicit, opt-in data removal. Never happens on deactivation.
	 */
	private function handle_remove_data() {
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

		if ( 'DELETE' !== strtoupper( $confirm ) ) {
			$this->notice( 'error', __( 'Type DELETE to confirm removing all CRM data.', 'fw' ) );

			return;
		}

		// Stop the sender first — a scheduled tick against dropped tables would
		// fatal on the next page load.
		FW_CRM_Sender::unschedule();
		FW_CRM_Installer::uninstall();

		$this->notice( 'success', __( 'All CRM data was removed. The tables will be recreated empty on the next page load.', 'fw' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( FW_CRM_Service::capability() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- read-only routing.
		$tab     = $this->current_tab();
		$base    = self::get_page_url();
		$item    = FW_CRM_App::item( $tab );
		$title   = $item ? $item['label'] : __( 'Search', 'fw' );
		$crumbs  = array( array( $title ) );
		$contact = isset( $_GET['subscriber'] ) ? (int) $_GET['subscriber'] : 0;
		$company = 'companies' === $tab && isset( $_GET['company'] ) ? (int) $_GET['company'] : 0;
		$is_new  = ! empty( $_GET['new'] );
		$query   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable

		$merge = 'subscribers' === $tab && isset( $_GET['merge'], $_GET['with'] ) ? (int) $_GET['merge'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$dupes = 'subscribers' === $tab && ! empty( $_GET['duplicates'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $merge ) {
			$crumbs = array( array( $title, add_query_arg( 'tab', 'subscribers', $base ) ), array( __( 'Merge contacts', 'fw' ) ) );
		} elseif ( $dupes ) {
			$crumbs = array( array( $title, add_query_arg( 'tab', 'subscribers', $base ) ), array( __( 'Possible duplicates', 'fw' ) ) );
		} elseif ( 'subscribers' === $tab && ( $contact || $is_new ) ) {
			$name = __( 'New contact', 'fw' );
			if ( $contact && ( $c = FW_CRM_Contacts::get( $contact ) ) ) {
				$name = '' !== $c['name'] ? $c['name'] : ( '' !== $c['email'] ? $c['email'] : $c['phone'] );
			}
			$crumbs = array( array( $title, add_query_arg( 'tab', 'subscribers', $base ) ), array( $name ) );
		} elseif ( 'companies' === $tab && ( $company || $is_new ) ) {
			$name = __( 'New company', 'fw' );
			if ( $company && ( $co = FW_CRM_Companies::find( $company ) ) ) {
				$name = $co->name;
			}
			$crumbs = array( array( $title, add_query_arg( 'tab', 'companies', $base ) ), array( $name ) );
		} elseif ( 'lists' === $tab && ! empty( $_GET['edit_segment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$seg    = 'new' === $_GET['edit_segment'] ? null : FW_CRM_Lists::find_segment( (int) $_GET['edit_segment'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$crumbs = array( array( $title, add_query_arg( 'tab', 'lists', $base ) ), array( $seg ? $seg->title : __( 'New segment', 'fw' ) ) );
		} elseif ( 'search' === $tab && '' !== $query ) {
			$crumbs = array( array( sprintf( __( 'Results for "%s"', 'fw' ), $query ) ) );
		} elseif ( 'campaigns' === $tab && ! empty( $_GET['report'] ) && class_exists( 'FW_CRM_Campaigns' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$camp   = FW_CRM_Campaigns::find( (int) $_GET['report'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$crumbs = array( array( $title, add_query_arg( 'tab', 'campaigns', $base ) ), array( $camp ? sprintf( __( 'Report: %s', 'fw' ), $camp->title ) : __( 'Report', 'fw' ) ) );
		}

		/** Filters the breadcrumbs of a CRM view: [ [ label, url? ], …, [ title ] ]. */
		$crumbs = (array) apply_filters( 'unysonplus_crm_crumbs', $crumbs, $tab );

		FW_CRM_App::open( $tab, $crumbs );
		$this->render_notice();

		$denied = ! FW_CRM_App::can_view( $tab ) || ( ( $merge || $dupes ) && ! FW_CRM_Capabilities::can_manage() );
		if ( ! $denied && $item && ! empty( $item['module'] ) && ! FW_CRM_Modules::enabled( $item['module'] ) ) {
			$mods = FW_CRM_Modules::all();
			FW_CRM_App::empty_state(
				'dashicons-admin-plugins',
				sprintf( __( '%s is turned off', 'fw' ), $mods[ $item['module'] ]['label'] ?? $item['label'] ),
				FW_CRM_Capabilities::can_manage() ? __( 'Switch it on under Modules to use this part of the CRM. Nothing was deleted while it was off.', 'fw' ) : __( 'A CRM manager can switch it on under Modules. Nothing was deleted while it was off.', 'fw' ),
				FW_CRM_Capabilities::can_manage() ? array( array( __( 'Open Modules', 'fw' ), add_query_arg( 'tab', 'modules', $base ), true ) ) : array()
			);
			FW_CRM_App::close();
			return;
		}
		if ( $denied ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'For CRM managers', 'fw' ), __( 'This part of the CRM is open to CRM managers and administrators. Ask one of them if you need something here.', 'fw' ), array( array( __( 'Back to Home', 'fw' ), add_query_arg( 'tab', 'home', $base ) ) ) );
			FW_CRM_App::close();
			return;
		}

		switch ( $tab ) {
			case 'home':
				FW_CRM_Dashboard::render();
				break;
			case 'subscribers':
				if ( $merge ) {
					FW_CRM_Admin_Contacts::render_merge( $merge, (int) $_GET['with'] ); // phpcs:ignore WordPress.Security.NonceVerification
				} elseif ( $dupes ) {
					FW_CRM_Admin_Contacts::render_duplicates();
				} elseif ( $contact ) {
					FW_CRM_Admin_Contacts::render_contact( $contact );
				} elseif ( $is_new ) {
					FW_CRM_Admin_Contacts::render_contact_new();
				} else {
					$this->render_subscribers_tab();
				}
				break;
			case 'companies':
				if ( $company ) {
					FW_CRM_Admin_Contacts::render_company( $company );
				} elseif ( $is_new ) {
					FW_CRM_Admin_Contacts::render_company_new();
				} else {
					FW_CRM_Admin_Contacts::render_companies();
				}
				break;
			case 'search':
				FW_CRM_Admin_Contacts::render_search( $query );
				break;
			case 'campaigns':
				$this->render_campaigns_tab();
				break;
			case 'lists':
				if ( ! empty( $_GET['edit_segment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					FW_CRM_Admin_Segments::render_editor( 'new' === $_GET['edit_segment'] ? 'new' : (int) $_GET['edit_segment'] ); // phpcs:ignore WordPress.Security.NonceVerification
				} else {
					$this->render_lists_tab();
				}
				break;
			case 'tools':
				FW_CRM_Admin_Import::render();
				break;
			case 'settings':
				$this->render_settings_tab();
				break;
			case 'bin':
				FW_CRM_Admin_Contacts::render_bin();
				break;
			case 'audit':
				FW_CRM_Admin_Contacts::render_audit();
				break;
			default:
				if ( $item && ! empty( $item['soon'] ) ) {
					FW_CRM_App::render_soon( $tab );
				} else {
					/**
					 * Renders a CRM view added through the `unysonplus_crm_nav` filter.
					 *
					 * @param string $tab The view slug.
					 */
					do_action( 'unysonplus_crm_render_view', $tab );
				}
		}

		FW_CRM_App::close();
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
		if ( ! $this->table ) {
			$this->table = new FW_CRM_List_Table();
		}

		$this->table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array_filter( FW_CRM_List_Table::request_query_args() ),
					array( 'fw_crm_action' => 'export' )
				),
				self::get_page_url()
			),
			self::NONCE
		);
		?>
		<div class="fw-crm-listbar">
			<p class="fw-crm-muted">
				<?php
				$all = FW_CRM_Subscribers::count( FW_CRM_Capabilities::scope( array() ) );
				echo FW_CRM_Capabilities::sees_all()
					? esc_html( sprintf( _n( '%s contact in the CRM.', '%s contacts in the CRM.', $all, 'fw' ), number_format_i18n( $all ) ) )
					: esc_html( sprintf( _n( 'You own %s contact.', 'You own %s contacts.', $all, 'fw' ), number_format_i18n( $all ) ) );
				?>
			</p>
			<div class="fw-crm-inline">
				<?php if ( FW_CRM_Capabilities::can_manage() ) : ?>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export these (CSV)', 'fw' ); ?></a>
				<?php endif; ?>
				<?php if ( FW_CRM_Capabilities::can_manage() ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'duplicates', 1, self::get_page_url( 'subscribers' ) ) ); ?>" class="button"><?php esc_html_e( 'Find duplicates', 'fw' ); ?></a>
					<a href="<?php echo esc_url( self::get_page_url( 'tools' ) ); ?>" class="button"><?php esc_html_e( 'Import CSV', 'fw' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( add_query_arg( 'new', 1, self::get_page_url( 'subscribers' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'New contact', 'fw' ); ?></a>
			</div>
		</div>

		<?php
		// "Save as segment" only makes sense once something is actually filtered —
		// a segment matching everyone is just the unfiltered screen.
		$filters = FW_CRM_Service::sanitize_segment_filters(
			FW_CRM_List_Table::request_query_args()
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
							'<strong>' . esc_html( FW_CRM_Service::describe_filters( $filters ) ) . '</strong>'
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
			<input type="hidden" name="tab" value="subscribers" />
			<?php if ( ! empty( $_GET['status'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( 'bulk-subscribers' ); ?>
			<?php $this->table->search_box( __( 'Search contacts', 'fw' ), 'fw-crm-search' ); ?>
			<?php $this->table->display(); ?>
		</form>

		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Campaigns
	 * ---------------------------------------------------------------------- */

	private function render_campaigns_tab() {
		$editing = isset( $_GET['campaign'] ) ? (int) $_GET['campaign'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$is_new  = isset( $_GET['new'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! empty( $_GET['report'] ) && class_exists( 'FW_CRM_Campaign_Report' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			FW_CRM_Campaign_Report::render( (int) $_GET['report'] ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( $editing || $is_new ) {
			$this->render_campaign_editor( $editing ? FW_CRM_Campaigns::find( $editing ) : null );

			return;
		}

		$campaigns = FW_CRM_Campaigns::all();
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
				<th style="width:180px"><?php esc_html_e( 'Results', 'fw' ); ?></th>
				<th style="width:170px"><?php esc_html_e( 'When', 'fw' ); ?></th>
				<th style="width:200px"></th>
			</tr>
			</thead>
			<tbody>
			<?php if ( ! $campaigns ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No campaigns yet.', 'fw' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $campaigns as $c ) :
				$counts   = FW_CRM_Campaigns::queue_counts( $c->id );
				if ( ! $counts['total'] && $c->queue_purged_at ) {
					// The per-person log was cleared: the campaign kept its totals.
					$counts = array( 'pending' => 0, 'sending' => 0, 'sent' => (int) $c->sent, 'failed' => (int) $c->failed, 'skipped' => (int) $c->skipped, 'total' => (int) $c->total );
				}
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
									esc_html( number_format_i18n( FW_CRM_Service::audience_count( $c ) ) )
								);
								?>
							</small>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( in_array( $c->status, array( 'sending', 'sent', 'paused' ), true ) && class_exists( 'FW_CRM_Tracking' ) ) : ?>
							<?php $st = FW_CRM_Tracking::stats( (int) $c->id ); ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'campaigns', 'report' => (int) $c->id ), $base ) ); ?>">
								<?php echo esc_html( sprintf( __( 'Opened %1$s%% · Clicked %2$s%%', 'fw' ), number_format_i18n( $st['open_rate'], 1 ), number_format_i18n( $st['click_rate'], 1 ) ) ); ?>
							</a>
						<?php else : ?>
							<span class="fw-crm-muted">–</span>
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
		$audience = $campaign ? FW_CRM_Campaigns::audience_args( $campaign ) : array();
		$editable = ! $campaign || in_array( $campaign->status, array( 'draft', 'scheduled' ), true );
		$lists    = FW_CRM_Lists::all( 'list' );
		$tags     = FW_CRM_Lists::all( 'tag' );
		$segments = FW_CRM_Lists::segments();
		$count    = FW_CRM_Service::audience_count( $campaign ? $campaign : array() );
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
						<p class="description"><?php esc_html_e( 'Internal only. The people you email never see this.', 'fw' ); ?></p>
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
							&& FW_CRM_Email_Compiler::normalize( $campaign->body_json );
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
								? FW_CRM_Email_Compiler::normalize( $campaign->body_json )
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
								array( 'body_json' => FW_CRM_Email_Compiler::to_option_value( $blocks ) )
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
				<?php if ( class_exists( 'FW_CRM_Senders' ) && FW_CRM_Senders::profiles() ) : ?>
					<p class="fw-crm-campaign-sender">
						<label for="fw-crm-sender"><?php esc_html_e( 'From', 'fw' ); ?></label>
						<select id="fw-crm-sender" name="sender_id">
							<option value=""><?php esc_html_e( 'The default for campaigns', 'fw' ); ?></option>
							<?php foreach ( FW_CRM_Senders::profiles() as $sp ) : ?>
								<option value="<?php echo esc_attr( $sp['id'] ); ?>" <?php selected( $campaign ? (string) ( $campaign->sender_id ?? '' ) : '', $sp['id'] ); ?>><?php echo esc_html( FW_CRM_Senders::describe( $sp ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<p class="fw-crm-campaign-speed">
					<label for="fw-crm-max-per-hour"><?php esc_html_e( 'Speed limit for this campaign', 'fw' ); ?></label>
					<input type="number" id="fw-crm-max-per-hour" name="max_per_hour" min="0" step="10" value="<?php echo $campaign && (int) $campaign->max_per_hour ? (int) $campaign->max_per_hour : ''; ?>" placeholder="<?php echo esc_attr( number_format_i18n( FW_CRM_Sender::rate()['per_hour'] ) ); ?>" />
					<span class="description"><?php esc_html_e( 'emails an hour. Leave empty to send as fast as the site allows (CRM settings). A lower limit spreads the opens and clicks of a big list over time.', 'fw' ); ?></span>
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
			$this->render_object_panel( 'list', __( 'Lists', 'fw' ), __( 'A list is something a contact joins; the [newsletter] element\'s List ID resolves to one of these. Deleting a list never deletes its contacts.', 'fw' ) );
			$this->render_object_panel( 'tag', __( 'Tags', 'fw' ), __( 'A tag is a label you attach: an interest, a behaviour, where someone came from. Add or remove tags in bulk from the Contacts list.', 'fw' ) );
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
		$rows   = FW_CRM_Lists::all( $type );
		$counts = FW_CRM_Lists::counts( $type, 'tag' === $type ? '' : 'subscribed' ); // A tag counts everyone wearing it.
		$base   = self::get_page_url();
		?>
		<div class="fw-crm-panel">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="description"><?php echo esc_html( $intro ); ?></p>

			<table class="widefat striped fw-crm-objects">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'fw' ); ?></th>
					<th style="width:150px"><?php esc_html_e( 'Slug', 'fw' ); ?></th>
					<th style="width:110px"><?php echo 'tag' === $type ? esc_html__( 'Contacts', 'fw' ) : esc_html__( 'Subscribed', 'fw' ); ?></th>
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
							<?php if ( ! empty( $row->managed_by ) ) : ?>
								<span class="fw-crm-managed"><?php echo esc_html( FW_CRM_App::managed_label( $row->managed_by ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $row->slug ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						<td>
							<a class="fw-crm-remove"
							   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'lists', 'fw_crm_action' => 'delete_list', 'id' => (int) $row->id ), $base ), self::NONCE ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( ! empty( $row->managed_by ) ? sprintf( __( '%s keeps this up to date automatically. Deleting it can break what it does with it (for example, who can see members-only content). Delete anyway?', 'fw' ), FW_CRM_App::managed_label( $row->managed_by, false ) ) : __( 'Delete this? Contacts stay, they just stop being members of it.', 'fw' ) ); ?>');">
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
			<?php
			if ( 'tag' === $type ) {
				$editable = array_values( array_filter( $rows, function ( $t ) {
					return empty( $t->managed_by );
				} ) );
				if ( $editable ) {
					$this->render_tag_tools( $editable );
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Rename a tag, or merge one into another (managed tags are not offered).
	 *
	 * @param array $tags
	 */
	private function render_tag_tools( array $tags ) {
		$options = '';
		foreach ( $tags as $t ) {
			$options .= sprintf( '<option value="%d">%s</option>', (int) $t->id, esc_html( $t->title ) );
		}
		?>
		<details class="fw-crm-tag-tools">
			<summary><?php esc_html_e( 'Rename or merge tags', 'fw' ); ?></summary>
			<form method="post" action="" class="fw-crm-inline">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="rename_tag" />
				<label class="screen-reader-text" for="fw-crm-rename-tag"><?php esc_html_e( 'Tag to rename', 'fw' ); ?></label>
				<select id="fw-crm-rename-tag" name="tag_id"><?php echo $options; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				<label class="screen-reader-text" for="fw-crm-rename-title"><?php esc_html_e( 'New name', 'fw' ); ?></label>
				<input type="text" id="fw-crm-rename-title" name="tag_title" required placeholder="<?php esc_attr_e( 'New name', 'fw' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Rename', 'fw' ); ?></button>
			</form>
			<form method="post" action="" class="fw-crm-inline">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="merge_tags" />
				<label class="screen-reader-text" for="fw-crm-merge-from"><?php esc_html_e( 'Merge this tag', 'fw' ); ?></label>
				<select id="fw-crm-merge-from" name="tag_id"><?php echo $options; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				<span><?php esc_html_e( 'into', 'fw' ); ?></span>
				<label class="screen-reader-text" for="fw-crm-merge-into"><?php esc_html_e( 'Into this tag', 'fw' ); ?></label>
				<select id="fw-crm-merge-into" name="into_id"><?php echo $options; // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Everyone with the first tag gets the second, then the first is deleted. Segments using it are updated. Continue?', 'fw' ) ); ?>');"><?php esc_html_e( 'Merge', 'fw' ); ?></button>
			</form>
		</details>
		<?php
	}

	private function render_segments_panel() {
		$segments = FW_CRM_Lists::segments();
		$base     = self::get_page_url();
		?>
		<div class="fw-crm-panel fw-crm-panel--wide">
			<div class="fw-crm-panel__head">
				<h2><?php esc_html_e( 'Segments', 'fw' ); ?></h2>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'lists', 'edit_segment' => 'new' ), $base ) ); ?>"><?php esc_html_e( 'New segment', 'fw' ); ?></a>
			</div>
			<p class="description">
				<?php esc_html_e( 'A segment is a saved search built from rules (stage, tags, lists, your own fields, dates, activity), not a fixed group: it is worked out again every time it is used, so someone who newly matches is simply in it. Send campaigns to it, or filter the contacts list by it.', 'fw' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
				<tr>
					<th style="width:220px"><?php esc_html_e( 'Segment', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Matches', 'fw' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Contacts', 'fw' ); ?></th>
					<th style="width:120px"></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( ! $segments ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No segments saved yet.', 'fw' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $segments as $segment ) :
					$filters = FW_CRM_Lists::segment_query_args( $segment );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'segment' => (int) $segment->id ), $base ) ); ?>">
								<strong><?php echo esc_html( $segment->title ); ?></strong>
							</a>
						</td>
						<td><?php echo esc_html( FW_CRM_Service::describe_filters( $filters ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( FW_CRM_Segments::count( $segment ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'lists', 'edit_segment' => (int) $segment->id ), $base ) ); ?>"><?php esc_html_e( 'Edit', 'fw' ); ?></a>
							<span aria-hidden="true">·</span>
							<a class="fw-crm-remove"
							   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'lists', 'fw_crm_action' => 'delete_segment', 'id' => (int) $segment->id ), $base ), self::NONCE ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this segment? The contacts it matched are not affected.', 'fw' ) ); ?>');">
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

	private function render_settings_tab() {
		$schema = $this->ext->get_settings_options();
		$values = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		?>
		<?php
		/** Extra sections on the CRM settings screen (managers), above the settings form. */
		do_action( 'unysonplus_crm_settings_sections' );
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
					esc_html__( 'Permanently deletes the %s stored contacts along with their companies, timelines, consent records, lists, tags, segments and campaigns, and their tables. Deactivating the extension does NOT do this — data is only ever removed here, on purpose.', 'fw' ),
					esc_html( number_format_i18n( FW_CRM_Subscribers::count() ) )
				);
				?>
			</p>
			<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'This permanently deletes every contact and everything the CRM stores. Continue?', 'fw' ) ); ?>');">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="remove_data" />
				<p>
					<input type="text" name="confirm" placeholder="<?php esc_attr_e( 'Type DELETE', 'fw' ); ?>" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove all CRM data', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

}
