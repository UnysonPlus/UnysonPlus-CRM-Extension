<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The public, logged-out endpoints: confirm a double-opt-in signup, and
 * unsubscribe.
 *
 * Design notes, each of which is a decision rather than an accident:
 *
 *  - **Query args on the home URL, not rewrite rules.** Pretty permalinks would
 *    need rules flushed on activation, and Unyson extensions have no activation
 *    hook we can rely on — a stale rewrite cache would silently 404 every
 *    confirmation link, which is the worst possible failure for this feature.
 *    Query args work on every permalink structure with nothing to flush.
 *  - **The token IS the credential.** These are links clicked from an email by a
 *    logged-out visitor, so a nonce is impossible (and meaningless — it is not
 *    the visitor we are authenticating, it is the mailbox).
 *  - **Confirmation defaults to requiring a click on a landing page (POST),**
 *    because corporate link scanners and mail-security products auto-visit every
 *    URL in an inbound email. A bare GET confirm would let a scanner opt someone
 *    in, which defeats the entire point of double opt-in. The `confirm_on_visit`
 *    setting turns that guard off for sites that would rather have no friction.
 *  - **Unsubscribe must accept a bare POST with no confirmation step** — that is
 *    RFC 8058 one-click, which Gmail and Yahoo require of bulk senders. A GET
 *    instead shows a landing page with a button, so a human clicking the link in
 *    a mail client that does NOT support one-click gets a sane page.
 *  - Runs on `init` and always exits, so nothing else in the request can render
 *    over the response, and every response is sent no-cache.
 */
class FW_Newsletter_CRM_Endpoints {

	const CONFIRM_ARG     = 'fw-crm-confirm';
	const UNSUBSCRIBE_ARG = 'fw-crm-unsubscribe';

	public function __construct() {
		add_action( 'init', array( $this, '_maybe_handle' ), 1 );
	}

	/* ---------------------------------------------------------------------- *
	 * URLs
	 * ---------------------------------------------------------------------- */

	/**
	 * @param object $subscriber
	 *
	 * @return string '' when there is no token to build a link from.
	 */
	public static function confirm_url( $subscriber ) {
		if ( empty( $subscriber->confirm_token ) ) {
			return '';
		}

		/** Filters the double-opt-in confirmation link built for a subscriber from their confirm token. */
		return apply_filters(
			'unysonplus_newsletter_crm_confirm_url',
			add_query_arg( self::CONFIRM_ARG, rawurlencode( $subscriber->confirm_token ), home_url( '/' ) ),
			$subscriber
		);
	}

	/**
	 * @param object $subscriber
	 *
	 * @return string
	 */
	public static function unsubscribe_url( $subscriber ) {
		if ( empty( $subscriber->unsubscribe_token ) ) {
			return '';
		}

		/** Filters the unsubscribe link built for a subscriber from their unsubscribe token. */
		return apply_filters(
			'unysonplus_newsletter_crm_unsubscribe_url',
			add_query_arg( self::UNSUBSCRIBE_ARG, rawurlencode( $subscriber->unsubscribe_token ), home_url( '/' ) ),
			$subscriber
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Handling
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _maybe_handle() {
		// phpcs:disable WordPress.Security.NonceVerification -- the token is the credential; see the class docblock.
		if ( isset( $_REQUEST[ self::CONFIRM_ARG ] ) ) {
			$this->handle_confirm( sanitize_text_field( wp_unslash( $_REQUEST[ self::CONFIRM_ARG ] ) ) );
		} elseif ( isset( $_REQUEST[ self::UNSUBSCRIBE_ARG ] ) ) {
			$this->handle_unsubscribe( sanitize_text_field( wp_unslash( $_REQUEST[ self::UNSUBSCRIBE_ARG ] ) ) );
		}
		// phpcs:enable
	}

	/**
	 * @param string $token
	 */
	private function handle_confirm( $token ) {
		$is_post = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' );

		// Show the click-to-confirm page unless this is the confirming POST (or
		// the site opted into confirming straight from the link).
		if ( ! $is_post && ! FW_Newsletter_CRM_Service::setting_is_on( 'confirm_on_visit' ) ) {
			$subscriber = FW_Newsletter_CRM_Subscribers::find_by_token( $token, 'confirm' );

			if ( ! $subscriber || '' === $token ) {
				$this->render(
					__( 'Link not valid', 'fw' ),
					__( 'That confirmation link is not valid — it may already have been used.', 'fw' ),
					''
				);
			}

			$this->render(
				__( 'Confirm your subscription', 'fw' ),
				sprintf(
					/* translators: %s: the email address */
					__( 'Click below to confirm that %s should receive our emails.', 'fw' ),
					'<strong>' . esc_html( $subscriber->email ) . '</strong>'
				),
				$this->confirm_form( $token )
			);
		}

		$result = FW_Newsletter_CRM_Service::confirm( $token );

		if ( is_wp_error( $result ) ) {
			$this->render( __( 'Link not valid', 'fw' ), $result->get_error_message(), '' );
		}

		$this->render(
			__( 'Subscription confirmed', 'fw' ),
			sprintf(
				/* translators: %s: site name */
				__( 'Thank you — you are now subscribed to %s.', 'fw' ),
				'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
			),
			$this->home_link()
		);
	}

	/**
	 * @param string $token
	 */
	private function handle_unsubscribe( $token ) {
		$subscriber = FW_Newsletter_CRM_Subscribers::find_by_token( $token, 'unsubscribe' );

		if ( ! $subscriber || '' === $token ) {
			$this->render(
				__( 'Link not valid', 'fw' ),
				__( 'That unsubscribe link is not valid.', 'fw' ),
				''
			);
		}

		$is_post = 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' );

		// RFC 8058 one-click: a POST unsubscribes immediately, no page, no
		// confirmation. Mail clients send this automatically.
		if ( $is_post ) {
			if ( 'unsubscribed' !== $subscriber->status ) {
				FW_Newsletter_CRM_Service::unsubscribe( $subscriber->id );
			}

			$this->render(
				__( 'Unsubscribed', 'fw' ),
				sprintf(
					/* translators: %s: the email address */
					__( '%s has been removed from our mailing list.', 'fw' ),
					'<strong>' . esc_html( $subscriber->email ) . '</strong>'
				),
				$this->home_link()
			);
		}

		if ( 'unsubscribed' === $subscriber->status ) {
			$this->render(
				__( 'Already unsubscribed', 'fw' ),
				sprintf(
					/* translators: %s: the email address */
					__( '%s is not subscribed to our mailing list.', 'fw' ),
					'<strong>' . esc_html( $subscriber->email ) . '</strong>'
				),
				$this->home_link()
			);
		}

		// A human followed the link in a client without one-click support.
		$this->render(
			__( 'Unsubscribe', 'fw' ),
			sprintf(
				/* translators: %s: the email address */
				__( 'Click below to stop sending emails to %s.', 'fw' ),
				'<strong>' . esc_html( $subscriber->email ) . '</strong>'
			),
			$this->unsubscribe_form( $token )
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Output
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $token
	 *
	 * @return string
	 */
	private function confirm_form( $token ) {
		return '<form method="post" action="' . esc_url( add_query_arg( self::CONFIRM_ARG, rawurlencode( $token ), home_url( '/' ) ) ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::CONFIRM_ARG ) . '" value="' . esc_attr( $token ) . '">'
			. '<button type="submit" class="fw-crm-btn">' . esc_html__( 'Confirm subscription', 'fw' ) . '</button>'
			. '</form>';
	}

	/**
	 * @param string $token
	 *
	 * @return string
	 */
	private function unsubscribe_form( $token ) {
		return '<form method="post" action="' . esc_url( add_query_arg( self::UNSUBSCRIBE_ARG, rawurlencode( $token ), home_url( '/' ) ) ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::UNSUBSCRIBE_ARG ) . '" value="' . esc_attr( $token ) . '">'
			. '<button type="submit" class="fw-crm-btn">' . esc_html__( 'Unsubscribe', 'fw' ) . '</button>'
			. '</form>';
	}

	/**
	 * @return string
	 */
	private function home_link() {
		return '<p class="fw-crm-back"><a href="' . esc_url( home_url( '/' ) ) . '">'
			. sprintf(
				/* translators: %s: site name */
				esc_html__( 'Back to %s', 'fw' ),
				esc_html( get_bloginfo( 'name' ) )
			)
			. '</a></p>';
	}

	/**
	 * Render a minimal, self-contained response page and exit.
	 *
	 * Self-contained rather than themed on purpose: this must work identically on
	 * every theme, including one whose header itself queries something broken, and
	 * it must never be cached. A site that wants its own design can return its own
	 * markup from `unysonplus_newsletter_crm_endpoint_page` — or short-circuit the
	 * whole thing and redirect to a real page.
	 *
	 * @param string $title
	 * @param string $message Allows inline HTML (we build it).
	 * @param string $action  Optional form / link markup.
	 */
	private function render( $title, $message, $action ) {
		nocache_headers();

		/**
		 * Filters the public confirm/unsubscribe page body; return a string to replace the default response entirely.
		 *
		 * Replace the endpoint response entirely. Return a non-null string to use
		 * it as the whole page body.
		 *
		 * @param string|null $html
		 * @param string      $title
		 * @param string      $message
		 * @param string      $action
		 */
		$custom = apply_filters( 'unysonplus_newsletter_crm_endpoint_page', null, $title, $message, $action );

		if ( is_string( $custom ) ) {
			echo $custom; // phpcs:ignore WordPress.Security.EscapeOutput -- the filter owns its markup.
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( $title . ' — ' . get_bloginfo( 'name' ) ); ?></title>
	<style>
		:root { color-scheme: light dark; }
		body {
			margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
			padding: 24px; background: #f6f7f7; color: #1d2327;
			font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
		}
		.fw-crm-card {
			max-width: 480px; width: 100%; background: #fff; border-radius: 8px;
			padding: 40px 36px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08);
		}
		h1 { margin: 0 0 .5em; font-size: 22px; line-height: 1.3; }
		p { margin: 0 0 1em; }
		.fw-crm-btn {
			display: inline-block; margin-top: .6em; padding: 12px 26px; border: 0; border-radius: 4px;
			background: #2271b1; color: #fff; font: inherit; font-weight: 600; cursor: pointer;
		}
		.fw-crm-btn:hover, .fw-crm-btn:focus { background: #135e96; }
		.fw-crm-btn:focus-visible { outline: 2px solid #135e96; outline-offset: 2px; }
		.fw-crm-back { margin: 1.4em 0 0; font-size: 14px; }
		.fw-crm-back a { color: #2271b1; }
		@media (prefers-color-scheme: dark) {
			body { background: #1d2327; color: #f0f0f1; }
			.fw-crm-card { background: #2c3338; box-shadow: none; }
			.fw-crm-back a { color: #72aee6; }
		}
	</style>
</head>
<body>
	<main class="fw-crm-card">
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo wp_kses_post( $message ); ?></p>
		<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput -- built above from escaped parts. ?>
	</main>
</body>
</html>
		<?php
		exit;
	}
}
