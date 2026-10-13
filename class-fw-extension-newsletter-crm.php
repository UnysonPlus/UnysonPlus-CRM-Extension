<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Newsletter / Subscriber CRM.
 *
 * The storage + management layer under the existing [newsletter] element, which
 * already renders the form and validates a signup but has nowhere to put it.
 *
 * Layering (enforced, not aspirational):
 *
 *   Installer   — the only DDL. Tables + schema version + migrations.
 *   Repository  — the only SQL. FW_Newsletter_CRM_Subscribers / _Lists.
 *   Service     — the only business rules, and the only thing outsiders call.
 *   Providers   — outbound integrations; the local store is just the first one.
 *   Admin/REST  — presentation and transport, over the same service.
 *
 * Nothing above the repository writes SQL; nothing below the service fires a
 * hook. Keep it that way — it is what lets campaigns, automations and ESP
 * add-ons arrive later without a rewrite.
 */
class FW_Extension_Newsletter_CRM extends FW_Extension {

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-newsletter-crm-installer.php';
		require_once $dir . 'class-fw-newsletter-crm-subscribers.php';
		require_once $dir . 'class-fw-newsletter-crm-lists.php';
		require_once $dir . 'providers/class-fw-newsletter-crm-provider.php';
		require_once $dir . 'providers/class-fw-newsletter-crm-provider-local.php';
		require_once $dir . 'class-fw-newsletter-crm-campaigns.php';
		require_once $dir . 'class-fw-newsletter-crm-email-compiler.php';
		require_once $dir . 'class-fw-newsletter-crm-service.php';
		require_once $dir . 'class-fw-newsletter-crm-capture.php';
		require_once $dir . 'class-fw-newsletter-crm-mail.php';
		require_once $dir . 'class-fw-newsletter-crm-sender.php';
		require_once $dir . 'class-fw-newsletter-crm-endpoints.php';
		require_once $dir . 'class-fw-newsletter-crm-privacy.php';
		require_once $dir . 'class-fw-newsletter-crm-rest.php';
		require_once $dir . 'class-fw-newsletter-crm-forms-action.php'; // Forms bridge: subscribe action + activity panel

		// The Email Builder option type. Registered on the option-types init
		// action (the framework's own hook for this), matching how the shortcodes
		// and animation-engine extensions register theirs. It must load on the
		// front end too, because the compiler resolves item types when sending.
		add_action( 'fw_option_types_init', function () use ( $dir ) {
			require_once $dir . 'builder/class-fw-option-type-email-builder.php';
		} );

		// Schema check. One autoloaded get_option() when up to date, so it is
		// cheap enough to run on every load — which is what makes activation and
		// plugin-update upgrades self-healing, with no activation hook to miss.
		FW_Newsletter_CRM_Installer::maybe_install();

		// Front end + admin: the capture hook must exist wherever admin-ajax runs,
		// and the confirm/unsubscribe endpoints are public by definition.
		new FW_Newsletter_CRM_Capture();
		new FW_Newsletter_CRM_Mail();
		new FW_Newsletter_CRM_Sender(); // registers the cron schedule + worker
		new FW_Newsletter_CRM_Endpoints();
		new FW_Newsletter_CRM_Privacy();
		new FW_Newsletter_CRM_Forms_Bridge();
		new FW_Newsletter_CRM_REST();

		if ( is_admin() ) {
			require_once $dir . 'class-fw-newsletter-crm-list-table.php';
			require_once $dir . 'class-fw-newsletter-crm-admin-page.php';
			require_once $dir . 'class-fw-newsletter-crm-csv.php';

			new FW_Newsletter_CRM_Admin_Page( $this );

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
