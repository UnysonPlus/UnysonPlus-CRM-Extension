<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Capture: turns a [newsletter] signup into a stored subscriber.
 *
 * We build on the shortcode's existing hooks rather than replacing its endpoint —
 * it already does the security work (nonce check, honeypot, sanitisation, an
 * admin-only notification recipient so it can't be an open relay).
 *
 * ORDERING, which is the whole subtlety here:
 *
 *   do_action ( 'fw_newsletter_subscribe',        $email, $name, $list )   <- we store
 *   apply_filters( 'fw_newsletter_subscribe_result', true, … )             <- we report
 *   apply_filters( 'fw_newsletter_handled',          false, … )            <- we may suppress
 *
 * `fw_newsletter_subscribe` is an ACTION fired BEFORE the result filter, so it
 * cannot itself reject a signup. The handler therefore never throws or dies: it
 * stores, stashes any WP_Error on the instance, and the result filter (hooked
 * later) surfaces it to the visitor. The stash is keyed by email so two form
 * posts in one request can't cross wires.
 *
 * The admin notification email is deliberately LEFT ALONE by default — storing a
 * subscriber does not mean the site owner stops wanting to hear about it. The
 * "admin_notify" setting turns it off by returning true from `fw_newsletter_handled`.
 */
class FW_CRM_Capture {

	/** @var array [ normalised email => WP_Error|object ] */
	private $results = array();

	public function __construct() {
		add_action( 'fw_newsletter_subscribe', array( $this, '_action_subscribe' ), 10, 3 );
		add_filter( 'fw_newsletter_subscribe_result', array( $this, '_filter_result' ), 20, 4 );
		add_filter( 'fw_newsletter_handled', array( $this, '_filter_handled' ), 10, 4 );
	}

	/**
	 * @internal
	 *
	 * @param string $email
	 * @param string $name
	 * @param string $list
	 */
	public function _action_subscribe( $email, $name = '', $list = '' ) {
		$key = FW_CRM_Subscribers::normalize_email( $email );

		$args = array(
			'name'       => $name,
			'list'       => $list,
			'source'     => 'shortcode',
			'source_url' => isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		);

		// Consent snapshot. The stock [newsletter] element RENDERS its "Consent /
		// Fine Print" text but does not post it (and a posted copy would be
		// spoofable anyway), so this only picks it up from a custom form. Sites
		// that need watertight consent evidence should record the text via the
		// `unysonplus_crm_subscriber_data` filter instead.
		if ( isset( $_POST['consent_text'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['consent_text'] = sanitize_textarea_field( wp_unslash( $_POST['consent_text'] ) );
		}

		$this->results[ $key ] = FW_CRM_Service::subscribe( $email, $args );
	}

	/**
	 * @internal
	 * Surface a storage failure to the visitor. Anything that is not our own
	 * error is passed through untouched, so other integrations still work.
	 *
	 * @param mixed  $result
	 * @param string $email
	 * @param string $name
	 * @param string $list
	 *
	 * @return mixed
	 */
	public function _filter_result( $result, $email = '', $name = '', $list = '' ) {
		if ( is_wp_error( $result ) ) {
			return $result; // Someone else already rejected it.
		}

		$key = FW_CRM_Subscribers::normalize_email( $email );

		if ( isset( $this->results[ $key ] ) && is_wp_error( $this->results[ $key ] ) ) {
			return $this->results[ $key ];
		}

		return $result;
	}

	/**
	 * @internal
	 * Suppress the shortcode's admin notification when the site owner has turned
	 * it off — but only if we actually stored the subscriber, so a failure never
	 * silently swallows the only record of a signup.
	 *
	 * @param bool   $handled
	 * @param string $email
	 * @param string $name
	 * @param string $list
	 *
	 * @return bool
	 */
	public function _filter_handled( $handled, $email = '', $name = '', $list = '' ) {
		if ( $handled ) {
			return $handled;
		}

		if ( FW_CRM_Service::setting_is_on( 'admin_notify', true ) ) {
			return $handled; // Owner still wants the email.
		}

		$key = FW_CRM_Subscribers::normalize_email( $email );
		$ok  = isset( $this->results[ $key ] ) && ! is_wp_error( $this->results[ $key ] );

		return $ok ? true : $handled;
	}
}
