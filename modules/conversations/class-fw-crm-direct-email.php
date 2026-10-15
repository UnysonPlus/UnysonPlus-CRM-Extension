<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * One-to-one email: a person on the team writes to a contact from the CRM.
 *
 * Sent through wp_mail(), so the Mailer extension (or whatever SMTP set-up the
 * site has) decides the transport and the From address. The writer's own address
 * goes in Reply-To and their name in the From name, so the answer comes back to
 * them. It is a personal message, not marketing: no unsubscribe footer and no
 * tracking, and it goes to people who unsubscribed from lists too (they opted out
 * of campaigns, not of being written to).
 *
 * Each email lands on the contact's timeline (type `email`) and, when written from
 * a deal, on the deal's.
 */
class FW_CRM_Direct_Email {

	/**
	 * @param int   $contact_id
	 * @param array $data subject, body, cc (comma separated), deal_id
	 *
	 * @return true|WP_Error
	 */
	public static function send( $contact_id, array $data ) {
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		if ( ! $contact || $contact->deleted_at ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		if ( ! is_email( (string) $contact->email ) ) {
			return new WP_Error( 'fw_crm_no_email', __( 'This contact has no email address.', 'fw' ) );
		}
		$subject = trim( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ) );
		$body    = trim( (string) ( $data['body'] ?? '' ) );
		if ( '' === $subject || '' === $body ) {
			return new WP_Error( 'fw_crm_empty_email', __( 'Write a subject and a message.', 'fw' ) );
		}
		$cc = array();
		foreach ( preg_split( '/[,;\s]+/', (string) ( $data['cc'] ?? '' ) ) as $addr ) {
			if ( '' === $addr ) {
				continue;
			}
			if ( ! is_email( $addr ) ) {
				return new WP_Error( 'fw_crm_bad_cc', sprintf( __( '"%s" is not an email address.', 'fw' ), $addr ) );
			}
			$cc[] = sanitize_email( $addr );
		}
		$deal_id = (int) ( $data['deal_id'] ?? 0 );

		$user    = wp_get_current_user();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $user && $user->exists() && is_email( $user->user_email ) ) {
			$headers[] = 'Reply-To: ' . self::header_name( $user->display_name ) . ' <' . $user->user_email . '>';
		}
		foreach ( $cc as $addr ) {
			$headers[] = 'Cc: ' . $addr;
		}
		$message_id = FW_CRM_Inbound_Email::message_id( (int) $contact->id, $deal_id );
		$headers[]  = 'Message-ID: <' . $message_id . '>';

		$text = FW_CRM_Mail::replace( $body, $contact );
		$html = FW_CRM_Mail::render_body( wp_kses_post( $text ), $contact );
		$subj = FW_CRM_Mail::replace( $subject, $contact );

		/**
		 * Filters a one-to-one email before it is sent.
		 *
		 * @param array  $mail    { to, subject, html, headers }
		 * @param object $contact
		 * @param array  $data
		 */
		$mail = apply_filters( 'unysonplus_crm_direct_email', array(
			'to'      => $contact->email,
			'subject' => $subj,
			'html'    => $html,
			'headers' => $headers,
		), $contact, $data );

		$from_name = $user && $user->exists() ? sprintf(
			/* translators: 1: the writer, 2: the site */
			__( '%1$s at %2$s', 'fw' ),
			$user->display_name,
			FW_CRM_Mail::site_name()
		) : '';
		$set_name  = function () use ( $from_name ) {
			return $from_name;
		};
		$error     = null;
		$catch     = function ( $e ) use ( &$error ) {
			$error = $e;
		};
		if ( '' !== $from_name ) {
			add_filter( 'wp_mail_from_name', $set_name, 999 );
		}
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $mail['to'], $mail['subject'], $mail['html'], $mail['headers'] );
		remove_action( 'wp_mail_failed', $catch );
		remove_filter( 'wp_mail_from_name', $set_name, 999 );

		if ( ! $sent ) {
			return new WP_Error( 'fw_crm_send_failed', $error instanceof WP_Error ? $error->get_error_message() : __( 'The email could not be sent. Check the Mailer settings.', 'fw' ) );
		}

		$entry = array(
			'body' => wp_strip_all_tags( $text ),
			'meta' => array(
				'subject' => $subj,
				'to'      => $contact->email,
				'cc'      => $cc,
				'deal_id'    => $deal_id,
				'direct'     => 1,
				'message_id' => $message_id,
			),
		);
		$title = sprintf( __( 'Email sent: %s', 'fw' ), $subj );
		FW_CRM_Activity::log( 'contact', (int) $contact->id, 'email', $title, $entry );
		if ( $deal_id && class_exists( 'FW_CRM_Deals' ) && FW_CRM_Deals::find( $deal_id ) ) {
			FW_CRM_Activity::log( 'deal', $deal_id, 'email', sprintf( __( 'Email to %1$s: %2$s', 'fw' ), self::name( $contact ), $subj ), $entry );
		}

		/**
		 * Fires after a one-to-one email is sent.
		 *
		 * @param object $contact
		 * @param array  $mail
		 * @param int    $deal_id
		 */
		do_action( 'unysonplus_crm_direct_email_sent', $contact, $mail, $deal_id );

		return true;
	}

	/**
	 * Contacts a deal's email can go to (with an address).
	 *
	 * @param int $deal_id
	 *
	 * @return array id => "Name <email>"
	 */
	public static function deal_recipients( $deal_id ) {
		$out = array();
		$d   = class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::get( (int) $deal_id ) : null;
		foreach ( $d ? $d['contact_ids'] : array() as $cid ) {
			$c = FW_CRM_Subscribers::find( $cid );
			if ( $c && ! $c->deleted_at && is_email( (string) $c->email ) ) {
				$out[ (int) $c->id ] = self::name( $c ) . ' <' . $c->email . '>';
			}
		}
		return $out;
	}

	/**
	 * @param object $c
	 *
	 * @return string
	 */
	private static function name( $c ) {
		$n = trim( $c->first_name . ' ' . $c->last_name );
		return '' !== $n ? $n : (string) $c->email;
	}

	/**
	 * A display name safe inside an email header.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private static function header_name( $name ) {
		return '"' . str_replace( array( '"', "\r", "\n" ), '', (string) $name ) . '"';
	}
}
