<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM.
 *
 * The storage + management layer under the existing [newsletter] element, which
 * already renders the form and validates a signup but has nowhere to put it.
 *
 * Layering (enforced, not aspirational):
 *
 *   Installer   — the only DDL. Tables + schema version + migrations.
 *   Repository  — the only SQL. FW_CRM_Subscribers / _Lists.
 *   Service     — the only business rules, and the only thing outsiders call.
 *   Providers   — outbound integrations; the local store is just the first one.
 *   Admin/REST  — presentation and transport, over the same service.
 *
 * Nothing above the repository writes SQL; nothing below the service fires a
 * hook. Keep it that way — it is what lets campaigns, automations and ESP
 * add-ons arrive later without a rewrite.
 */
class FW_Extension_CRM extends FW_Extension {

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-crm-installer.php';
		require_once $dir . 'class-fw-crm-subscribers.php';
		require_once $dir . 'class-fw-crm-lists.php';
		require_once $dir . 'class-fw-crm-list-membership.php';
		require_once $dir . 'class-fw-crm-tags.php';
		// The contact model (2.0): links, timeline, consent log, custom fields, companies,
		// and the public contact API the rest of the CRM and other extensions use.
		require_once $dir . 'class-fw-crm-relations.php';
		require_once $dir . 'class-fw-crm-activity.php';
		require_once $dir . 'class-fw-crm-consent.php';
		require_once $dir . 'class-fw-crm-fields.php';
		require_once $dir . 'class-fw-crm-companies.php';
		require_once $dir . 'class-fw-crm-contacts.php';
		require_once $dir . 'class-fw-crm-trash.php';
		require_once $dir . 'class-fw-crm-segments.php';
		require_once $dir . 'class-fw-crm-merge.php';
		require_once $dir . 'class-fw-crm-capabilities.php';
		require_once $dir . 'class-fw-crm-audit.php';
		require_once $dir . 'class-fw-crm-modules.php';
		require_once $dir . 'class-fw-crm-import.php';
		require_once $dir . 'class-fw-crm-tasks.php';
		require_once $dir . 'class-fw-crm-files.php';
		require_once $dir . 'class-fw-crm-notes.php';
		FW_CRM_Modules::init();

		// Optional parts of the core that belong to a module. Each loads only while its
		// module is on. Incoming mail serves both conversations (replies) and email
		// marketing (bounces and complaints), so it loads for either.
		$calendar      = FW_CRM_Modules::enabled( 'calendar' );
		$conversations = FW_CRM_Modules::enabled( 'conversations' );
		$inbound       = $conversations || FW_CRM_Modules::enabled( 'email' );
		$webhooks      = FW_CRM_Modules::enabled( 'webhooks' );
		// These live in modules/<name>/ (not includes/, whose top-level files the framework
		// loads on its own), so a module that is off really loads none of them.
		$mod_dir = dirname( __FILE__ ) . '/modules/';
		if ( $calendar && ! class_exists( 'FW_CRM_Calendar' ) ) {
			require_once $mod_dir . 'calendar/class-fw-crm-calendar.php';
		}
		if ( $conversations && ! class_exists( 'FW_CRM_Direct_Email' ) ) {
			require_once $mod_dir . 'conversations/class-fw-crm-direct-email.php';
		}
		if ( $inbound && ! class_exists( 'FW_CRM_Inbound_Email' ) ) {
			require_once $mod_dir . 'conversations/class-fw-crm-mime.php';
			require_once $mod_dir . 'conversations/class-fw-crm-imap.php';
			require_once $mod_dir . 'conversations/class-fw-crm-inbound-email.php';
		}
		FW_CRM_Capabilities::init();
		if ( FW_CRM_Modules::enabled( 'audit' ) ) {
			FW_CRM_Audit::init();
		}
		FW_CRM_Activity::init();
		FW_CRM_Consent::init();
		FW_CRM_Trash::init();
		FW_CRM_Tags::init();
		FW_CRM_Import::init();
		FW_CRM_Tasks::init();
		FW_CRM_Files::init();
		if ( $inbound ) {
			FW_CRM_Inbound_Email::init();
		}

		// Module: sales pipeline (pipelines, stages, deals and the board).
		if ( FW_CRM_Modules::enabled( 'pipeline' ) ) {
			$pipeline_dir = dirname( __FILE__ ) . '/modules/pipeline/';
			require_once $pipeline_dir . 'class-fw-crm-pipelines.php';
			require_once $pipeline_dir . 'class-fw-crm-deals.php';
			require_once $pipeline_dir . 'class-fw-crm-reports.php';
			require_once $pipeline_dir . 'class-fw-crm-products.php';
			FW_CRM_Products::init();
		}
		// Module: support cases (status, priority, owner; the conversation on the timeline).
		if ( FW_CRM_Modules::enabled( 'cases' ) ) {
			$cases_dir = dirname( __FILE__ ) . '/modules/cases/';
			require_once $cases_dir . 'class-fw-crm-cases.php';
			require_once $cases_dir . 'class-fw-crm-cases-intake.php';
			FW_CRM_Cases_Intake::init();
		}
		require_once $dir . 'providers/class-fw-crm-provider.php';
		require_once $dir . 'providers/class-fw-crm-provider-local.php';
		// Module: email marketing (campaigns, the email builder, the batched sender).
		// Off = none of it loads and its background job is not scheduled.
		// Modules live in modules/<name>/, outside includes/ (whose top-level files the
		// framework loads automatically), so a module that is off really loads nothing.
		$email     = FW_CRM_Modules::enabled( 'email' );
		$email_dir = dirname( __FILE__ ) . '/modules/email/';
		if ( $email ) {
			require_once $email_dir . 'class-fw-crm-campaigns.php';
			require_once $email_dir . 'class-fw-crm-email-compiler.php';
		}
		require_once $dir . 'class-fw-crm-service.php';
		require_once $dir . 'class-fw-crm-capture.php';
		require_once $dir . 'class-fw-crm-mail.php';
		// Sender profiles and email services: campaigns are marketing mail, conversations and
		// case replies transactional, so they load with either module.
		if ( $email || $conversations ) {
			require_once $email_dir . 'services/class-fw-crm-mail-service.php';
			require_once $email_dir . 'class-fw-crm-senders.php';
			FW_CRM_Senders::init();
		}
		require_once $dir . 'class-fw-crm-channels.php';
		FW_CRM_Channels::init();
		require_once $dir . 'class-fw-crm-events-store.php';
		FW_CRM_Events_Store::init();
		// Module: lead scoring and site activity.
		if ( FW_CRM_Modules::enabled( 'scoring' ) ) {
			$score_dir = dirname( __FILE__ ) . '/modules/scoring/';
			require_once $score_dir . 'class-fw-crm-scoring.php';
			require_once $score_dir . 'class-fw-crm-site-activity.php';
			FW_CRM_Scoring::init();
			FW_CRM_Site_Activity::init();
			if ( is_admin() ) {
				require_once $score_dir . 'class-fw-crm-admin-scoring.php';
				FW_CRM_Admin_Scoring::init();
			}
		}
		// Module: automations (the engine, its triggers and actions, the runner and its health check).
		if ( FW_CRM_Modules::enabled( 'automations' ) ) {
			$auto_dir = dirname( __FILE__ ) . '/modules/automations/';
			require_once $auto_dir . 'class-fw-crm-automations.php';
			require_once $auto_dir . 'class-fw-crm-conditions.php';
			require_once $auto_dir . 'class-fw-crm-goals.php';
			require_once $auto_dir . 'class-fw-crm-automation-guard.php';
			require_once $auto_dir . 'class-fw-crm-run-log.php';
			require_once $auto_dir . 'class-fw-crm-runner.php';
			require_once $auto_dir . 'class-fw-crm-cron-check.php';
			require_once $auto_dir . 'triggers/class-fw-crm-trigger.php';
			require_once $auto_dir . 'actions/class-fw-crm-action.php';
			require_once $auto_dir . 'actions/class-fw-crm-action-assign-owner.php';
			require_once $auto_dir . 'actions/class-fw-crm-action-split.php';
			FW_CRM_Automations::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-fw-crm-cli.php';
		}
		if ( $email ) {
			require_once $email_dir . 'class-fw-crm-smart-links.php';
			require_once $email_dir . 'class-fw-crm-digest.php';
			FW_CRM_Smart_Links::init();
			FW_CRM_Digest::init();
		}
		if ( $email ) {
			require_once $email_dir . 'class-fw-crm-sender.php';
			require_once $email_dir . 'class-fw-crm-tracking.php';
			require_once $email_dir . 'class-fw-crm-campaign-report.php';
		}
		require_once $dir . 'class-fw-crm-endpoints.php';
		require_once $dir . 'class-fw-crm-privacy.php';
		require_once $dir . 'class-fw-crm-rest.php';
		require_once $dir . 'class-fw-crm-forms-action.php'; // Forms bridge: subscribe action + activity panel
		require_once $dir . 'ai-abilities.php'; // AI Assistant abilities (only registered while that extension is active)

		// The Email Builder option type. Registered on the option-types init
		// action (the framework's own hook for this), matching how the shortcodes
		// and animation-engine extensions register theirs. It must load on the
		// front end too, because the compiler resolves item types when sending.
		if ( $email ) {
			add_action( 'fw_option_types_init', function () use ( $email_dir ) {
				require_once $email_dir . 'builder/class-fw-option-type-email-builder.php';
			} );
		}

		// Schema check. One autoloaded get_option() when up to date, so it is
		// cheap enough to run on every load — which is what makes activation and
		// plugin-update upgrades self-healing, with no activation hook to miss.
		FW_CRM_Installer::maybe_install();

		// Front end + admin: the capture hook must exist wherever admin-ajax runs,
		// and the confirm/unsubscribe endpoints are public by definition.
		new FW_CRM_Capture();
		new FW_CRM_Mail();
		if ( $email ) {
			new FW_CRM_Sender(); // registers the cron schedule + worker
			FW_CRM_Tracking::init();
		}
		new FW_CRM_Endpoints();
		new FW_CRM_Privacy();
		new FW_CRM_Forms_Bridge();
		new FW_CRM_REST();
		require_once $dir . 'rest/class-fw-crm-rest-tasks.php';
		new FW_CRM_REST_Tasks();
		require_once $dir . 'rest/class-fw-crm-rest-files.php';
		new FW_CRM_REST_Files();
		if ( $inbound ) {
			require_once $dir . 'rest/class-fw-crm-rest-email.php';
			new FW_CRM_REST_Email();
		}
		// Module: webhooks (signed event deliveries to other apps).
		if ( $webhooks ) {
			$hooks_dir = dirname( __FILE__ ) . '/modules/webhooks/';
			require_once $hooks_dir . 'class-fw-crm-webhooks.php';
			require_once $hooks_dir . 'class-fw-crm-rest-webhooks.php';
			FW_CRM_Webhooks::init();
			new FW_CRM_REST_Webhooks();
		}
		if ( FW_CRM_Modules::enabled( 'pipeline' ) ) {
			require_once dirname( __FILE__ ) . '/modules/pipeline/class-fw-crm-rest-deals.php';
			new FW_CRM_REST_Deals();
		}

		if ( is_admin() ) {
			require_once $dir . 'class-fw-crm-list-table.php';
			require_once $dir . 'class-fw-crm-admin-page.php';
			require_once $dir . 'class-fw-crm-csv.php';
			// The CRM app: its own sidebar + top bar, the home dashboard, and the
			// contact / company record screens.
			require_once $dir . 'admin/class-fw-crm-app.php';
			require_once $dir . 'admin/class-fw-crm-dashboard.php';
			FW_CRM_Dashboard::init();
			require_once $dir . 'admin/class-fw-crm-admin-contacts.php';
			require_once $dir . 'admin/class-fw-crm-admin-segments.php';
			require_once $dir . 'admin/class-fw-crm-admin-import.php';
			require_once $dir . 'admin/class-fw-crm-admin-tasks.php';
			FW_CRM_Admin_Tasks::init();
			require_once $dir . 'admin/class-fw-crm-admin-files.php';
			FW_CRM_Admin_Files::init();
			if ( $calendar ) {
				require_once $dir . 'admin/class-fw-crm-admin-calendar.php';
				FW_CRM_Admin_Calendar::init();
			}
			if ( $conversations ) {
				require_once $dir . 'admin/class-fw-crm-admin-email.php';
				FW_CRM_Admin_Email::init();
			}
			if ( $email || $conversations ) {
				require_once $email_dir . 'class-fw-crm-admin-sending.php';
				FW_CRM_Admin_Sending::init();
			}
			if ( $inbound ) {
				require_once $dir . 'admin/class-fw-crm-admin-inbound.php';
				FW_CRM_Admin_Inbound::init();
			}
			require_once $dir . 'admin/class-fw-crm-admin-fields.php';
			FW_CRM_Admin_Fields::init();
			require_once $dir . 'admin/class-fw-crm-admin-modules.php';
			FW_CRM_Admin_Modules::init();
			if ( $webhooks ) {
				require_once dirname( __FILE__ ) . '/modules/webhooks/class-fw-crm-admin-webhooks.php';
				FW_CRM_Admin_Webhooks::init();
			}
			if ( FW_CRM_Modules::enabled( 'pipeline' ) ) {
				require_once dirname( __FILE__ ) . '/modules/pipeline/class-fw-crm-admin-deal.php';
				FW_CRM_Admin_Deals::init();
				require_once dirname( __FILE__ ) . '/modules/pipeline/class-fw-crm-admin-reports.php';
				FW_CRM_Admin_Reports::init();
				require_once dirname( __FILE__ ) . '/modules/pipeline/class-fw-crm-admin-products.php';
				FW_CRM_Admin_Products::init();
			}
			if ( FW_CRM_Modules::enabled( 'automations' ) ) {
				require_once dirname( __FILE__ ) . '/modules/automations/class-fw-crm-admin-automations.php';
				FW_CRM_Admin_Automations::init();
			}
			if ( FW_CRM_Modules::enabled( 'cases' ) ) {
				require_once dirname( __FILE__ ) . '/modules/cases/class-fw-crm-admin-cases.php';
				FW_CRM_Admin_Cases::init();
			}
			FW_CRM_Admin_Segments::init();
			FW_CRM_Admin_Import::init();

			new FW_CRM_Admin_Page( $this );

			// WP_List_Table's per-page screen option needs the value saved back.
			add_filter( 'set-screen-option', array( $this, '_filter_set_screen_option' ), 10, 3 );
			add_filter( 'set_screen_option_fw_crm_subscribers_per_page', array( $this, '_filter_set_screen_option' ), 10, 3 );
		}
	}

	/**
	 * @internal
	 *
	 * @param mixed  $status
	 * @param string $option
	 * @param mixed  $value
	 *
	 * @return mixed
	 */
	public function _filter_set_screen_option( $status, $option, $value ) {
		if ( 'fw_crm_subscribers_per_page' === $option ) {
			return max( 1, min( 500, (int) $value ) );
		}

		return $status;
	}
}
