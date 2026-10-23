<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Outbound email: the double-opt-in confirmation and the welcome message.
 *
 * Deliberately built ON the lifecycle hooks rather than called from inside the
 * service — `_subscriber_added` / `_resubscribed` / `_confirmed` are exactly the
 * seams an integrator would use, so using them ourselves proves they carry
 * enough context to be useful. If this class were deleted the store would keep
 * working; nothing else depends on it.
 *
 * Everything goes through `wp_mail()`, so the Mailer/SMTP extension governs
 * transport, From address and authentication. Never call mail() directly here —
 * bypassing wp_mail breaks SPF/DKIM alignment on every site that configured it.
 */
class FW_CRM_Mail {

	public function __construct() {
		add_action( 'unysonplus_crm_subscriber_added', array( $this, '_maybe_send_confirmation' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_resubscribed', array( $this, '_maybe_send_confirmation' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_updated', array( $this, '_maybe_send_confirmation_on_update' ), 10, 3 );
		add_action( 'unysonplus_crm_subscriber_confirmed', array( $this, '_send_welcome' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Triggers
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 *
	 * @param object $subscriber
	 * @param array  $context
	 */
	public function _maybe_send_confirmation( $subscriber, $context = array() ) {
		if ( ! $subscriber || 'pending' !== $subscriber->status || '' === $subscriber->confirm_token ) {
			return;
		}

		self::send_confirmation( $subscriber );
	}

	/**
	 * @internal
	 * A repeat signup by someone already pending lands on `_updated`, not
	 * `_added` — they should still get the (regenerated) link. Admin edits and
	 * GDPR erasures also fire `_updated`, so those contexts are excluded.
	 *
	 * @param object $subscriber
	 * @param object $before
	 * @param array  $context
	 */
	public function _maybe_send_confirmation_on_update( $subscriber, $before = null, $context = array() ) {
		$origin = isset( $context['context'] ) ? $context['context'] : '';

		if ( in_array( $origin, array( 'update', 'gdpr_erase' ), true ) ) {
			return;
		}

		$this->_maybe_send_confirmation( $subscriber, $context );
	}

	/**
	 * @internal
	 *
	 * @param object $subscriber
	 */
	public function _send_welcome( $subscriber ) {
		if ( ! FW_CRM_Service::setting_is_on( 'welcome_email' ) ) {
			return;
		}

		self::send( $subscriber, 'welcome' );
	}

	/* ---------------------------------------------------------------------- *
	 * Sending
	 * ---------------------------------------------------------------------- */

	/**
	 * @param object $subscriber
	 *
	 * @return bool
	 */
	public static function send_confirmation( $subscriber ) {
		return self::send( $subscriber, 'confirm' );
	}

	/**
	 * @param object $subscriber
	 * @param string $kind confirm|welcome
	 *
	 * @return bool
	 */
	public static function send( $subscriber, $kind ) {
		if ( ! $subscriber || ! is_email( $subscriber->email ) ) {
			return false;
		}

		$defaults = self::defaults( $kind );
		$subject  = (string) FW_CRM_Service::setting( $kind . '_subject', $defaults['subject'] );
		$body     = (string) FW_CRM_Service::setting( $kind . '_body', $defaults['body'] );

		$subject = self::replace( $subject, $subscriber );
		$html    = self::render_body( self::replace( $body, $subscriber ), $subscriber );

		/**
		 * Filters an outgoing transactional subscriber email array (to, subject, body, headers) before it is sent, given the subscriber and email kind.
		 *
		 * Last chance to rewrite an outgoing subscriber email.
		 *
		 * @param array  $mail  [ to, subject, body, headers ]
		 * @param object $subscriber
		 * @param string $kind
		 */
		$mail = apply_filters( 'unysonplus_crm_mail', array(
			'to'      => $subscriber->email,
			'subject' => $subject,
			'body'    => $html,
			'headers' => array_merge(
				array( 'Content-Type: text/html; charset=UTF-8' ),
				self::unsubscribe_headers( $subscriber )
			),
		), $subscriber, $kind );

		if ( empty( $mail['to'] ) ) {
			return false;
		}

		// Confirmations and welcomes are transactional: they use that sender profile.
		if ( class_exists( 'FW_CRM_Senders' ) ) {
			FW_CRM_Senders::begin( 'transactional' );
		}
		try {
			return (bool) wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'] );
		} finally {
			if ( class_exists( 'FW_CRM_Senders' ) ) {
				FW_CRM_Senders::end();
			}
		}
	}

	/**
	 * The one-click unsubscribe headers Gmail and Yahoo require of bulk senders
	 * (RFC 8058). `List-Unsubscribe-Post` is what makes the URL a POST target, so
	 * the endpoint must accept POST without any confirmation step — a mail client
	 * cannot carry a nonce, which is exactly why the token is the credential.
	 *
	 * Public because the future campaign sender needs the same headers.
	 *
	 * @param object $subscriber
	 *
	 * @return array
	 */
	public static function unsubscribe_headers( $subscriber ) {
		$url = FW_CRM_Endpoints::unsubscribe_url( $subscriber );

		if ( '' === $url ) {
			return array();
		}

		return array(
			'List-Unsubscribe: <' . $url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Templating
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $kind
	 *
	 * @return array [ subject, body ]
	 */
	public static function defaults( $kind ) {
		if ( 'welcome' === $kind ) {
			return array(
				'subject' => __( 'You are subscribed to {{site_name}}', 'fw' ),
				'body'    => __( "Hi {{name}},\n\nThanks for confirming — you are now subscribed to {{site_name}}.\n\nYou can unsubscribe at any time using the link at the bottom of any email:\n{{unsubscribe_url}}", 'fw' ),
			);
		}

		return array(
			'subject' => __( 'Please confirm your subscription to {{site_name}}', 'fw' ),
			'body'    => __( "Hi {{name}},\n\nPlease confirm you would like to receive emails from {{site_name}} by clicking the link below:\n\n{{confirm_url}}\n\nIf you did not request this, you can safely ignore this email — nothing will be sent to you.", 'fw' ),
		);
	}

	/**
	 * The placeholders available in both templates.
	 *
	 * @param string $text
	 * @param object $subscriber
	 *
	 * @return string
	 */
	public static function replace( $text, $subscriber ) {
		$name = trim( $subscriber->first_name . ' ' . $subscriber->last_name );

		$map = array(
			'{{name}}'            => '' !== $name ? $name : __( 'there', 'fw' ),
			'{{first_name}}'      => $subscriber->first_name,
			'{{last_name}}'       => $subscriber->last_name,
			'{{email}}'           => $subscriber->email,
			'{{site_name}}'       => self::site_name(),
			'{{site_url}}'        => home_url( '/' ),
			'{{confirm_url}}'     => FW_CRM_Endpoints::confirm_url( $subscriber ),
			'{{unsubscribe_url}}' => FW_CRM_Endpoints::unsubscribe_url( $subscriber ),
			'{{preferences_url}}' => FW_CRM_Endpoints::preferences_url( $subscriber ),
		);

		/**
		 * Filters the placeholder-to-value map used to personalize email text for a subscriber.
		 *
		 * Add or rewrite email placeholders.
		 *
		 * @param array  $map
		 * @param object $subscriber
		 */
		$map = apply_filters( 'unysonplus_crm_mail_placeholders', $map, $subscriber );

		return str_replace( array_keys( $map ), array_values( $map ), (string) $text );
	}

	/**
	 * Turn a body into sendable HTML.
	 *
	 * `wpautop()` is right for a plain-text template (the settings defaults are
	 * plain text with blank lines) but WRONG for content that already carries
	 * block markup — a campaign written in TinyMCE arrives as `<p>` tags, and
	 * running wpautop over it inserts a second layer of paragraphs and mangles
	 * the spacing. So autop only when there is no block-level markup to respect.
	 *
	 * Public because the campaign sender needs exactly the same decision.
	 *
	 * @param string $body
	 *
	 * @return string
	 */
	public static function maybe_autop( $body ) {
		$body = (string) $body;

		if ( preg_match( '#<(p|div|table|ul|ol|h[1-6]|blockquote|figure)\b#i', $body ) ) {
			return $body;
		}

		return wpautop( $body );
	}

	/**
	 * The full body pipeline: sanitise → linkify bare URLs → autop if needed →
	 * wrap in the email shell. Public so the campaign sender produces bodies
	 * identical in treatment to confirmation/welcome mail, rather than a second
	 * near-copy that drifts.
	 *
	 * @param string $body
	 * @param object $subscriber
	 *
	 * @return string
	 */
	public static function render_body( $body, $subscriber ) {
		$body = (string) $body;

		/**
		 * A body that is ALREADY a complete document passes through untouched.
		 *
		 * The email builder compiles one, and running the fragment pipeline over
		 * it does real damage: `wp_kses_post()` strips `<style>` — dumping the
		 * mobile-stacking CSS into the message as VISIBLE TEXT — and drops
		 * `<head>`/`<html>`, after which the whole thing is nested inside a
		 * second `<!doctype>` shell with its own padding and background.
		 *
		 * Sanitising is not lost, only moved: a compiled document is assembled
		 * by our own compiler from per-block values that were sanitised when
		 * saved, so it never contains unreviewed author markup. The fragment
		 * path below still guards bodies typed into the visual editor.
		 */
		if ( self::is_document( $body ) ) {
			/** Filters the sendable HTML for a pre-compiled email document body before delivery. */
			return apply_filters( 'unysonplus_crm_mail_html', $body, $body, $subscriber );
		}

		$html = self::maybe_autop( make_clickable( wp_kses_post( $body ) ) );

		$out = '<!doctype html><html><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:24px;background:#f6f7f7;'
			. 'font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2327">'
			. '<div style="max-width:600px;margin:0 auto;background:#fff;padding:28px 32px;border-radius:6px">'
			. $html
			. '</div></body></html>';

		/**
		 * Replace the whole email shell (to use a branded template, for example).
		 *
		 * @param string $out
		 * @param string $body
		 * @param object $subscriber
		 */
		return apply_filters( 'unysonplus_crm_mail_html', $out, $body, $subscriber );
	}

	/**
	 * The site title as PLAIN TEXT.
	 *
	 * Tags are stripped, not escaped, and the distinction matters. A site title
	 * may legitimately contain markup — the Site Converter emits a two-tone
	 * wordmark like `My<span class="accent">Company</span>` and the theme prints
	 * it raw — and `esc_html()` on that mails the reader a literal
	 * `&lt;span class="accent"&gt;`. Keeping the tag is not an option either:
	 * there is no stylesheet in an inbox, so the class would do nothing even if
	 * it survived, and letting raw markup into `{{site_name}}` would mean a
	 * theme setting could inject arbitrary HTML into every email.
	 *
	 * One helper, used by the `{{site_name}}` placeholder, the Logo block, the
	 * plain-text renderer and the public endpoint pages — because the answer
	 * must be the same in all of them.
	 *
	 * @return string
	 */
	public static function site_name() {
		return trim( wp_strip_all_tags(
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		) );
	}

	/**
	 * Is this body a complete HTML document rather than a fragment?
	 *
	 * The one place that question is answered, because two things need it and
	 * they must never disagree: `render_body()` (which must not re-wrap a
	 * document) and `Sender::with_unsubscribe()` (which must not append its
	 * opt-out line after `</html>`).
	 *
	 * @param string $body
	 *
	 * @return bool
	 */
	public static function is_document( $body ) {
		return (bool) preg_match( '/^\s*<(?:!doctype\s+html|html)\b/i', (string) $body );
	}
}
