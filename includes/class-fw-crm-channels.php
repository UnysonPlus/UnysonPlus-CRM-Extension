<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Message channels: the ways the CRM can reach a contact. Email is the first channel;
 * SMS or a messaging app is added by registering another one, without changing the
 * code that decides WHAT to send (campaigns, automations, case replies).
 *
 * A channel is registered with the `unysonplus_crm_channels` filter:
 *
 *     id => array(
 *         'label'     => string,
 *         'icon'      => dashicon class,
 *         'address'   => callable( $contact ) : string, the contact's address on it ('' = not reachable),
 *         'send'      => callable( $contact, array $message ) : true|WP_Error,
 *                        $message = { subject, body (HTML for email, text otherwise), purpose: marketing|transactional }
 *         'available' => callable|bool, false while it is not set up,
 *     )
 *
 * FW_CRM_Channels::send() is the single entry point; it also writes the timeline entry, so
 * every channel's messages show up on the contact the same way.
 */
class FW_CRM_Channels {

	public static function init() {
		// Nothing to hook yet: channels are read on demand.
	}

	/** @return array id => channel */
	public static function all() {
		$channels = array(
			'email' => array(
				'label'     => __( 'Email', 'fw' ),
				'icon'      => 'dashicons-email',
				'address'   => function ( $contact ) {
					return is_email( (string) ( $contact->email ?? '' ) ) ? (string) $contact->email : '';
				},
				'send'      => array( __CLASS__, 'send_email' ),
				'available' => true,
			),
		);

		/** Filters the message channels: id => { label, icon, address, send, available }. */
		$channels = (array) apply_filters( 'unysonplus_crm_channels', $channels );
		foreach ( $channels as $id => $c ) {
			$channels[ $id ] = array_merge( array( 'label' => $id, 'icon' => 'dashicons-format-chat', 'address' => '__return_empty_string', 'send' => null, 'available' => true ), (array) $c );
		}
		return $channels;
	}

	/**
	 * Channels that are set up, optionally only those that can reach a contact.
	 *
	 * @param object|null $contact
	 *
	 * @return array id => channel
	 */
	public static function available( $contact = null ) {
		return array_filter( self::all(), function ( $c ) use ( $contact ) {
			$ok = is_callable( $c['available'] ) ? (bool) call_user_func( $c['available'] ) : (bool) $c['available'];
			return $ok && is_callable( $c['send'] ) && ( null === $contact || '' !== (string) call_user_func( $c['address'], $contact ) );
		} );
	}

	/**
	 * Send a message to a contact on a channel.
	 *
	 * @param string $channel
	 * @param int    $contact_id
	 * @param array  $message { subject, body, purpose }
	 *
	 * @return true|WP_Error
	 */
	public static function send( $channel, $contact_id, array $message ) {
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		if ( ! $contact || $contact->deleted_at ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		$all = self::available( $contact );
		if ( ! isset( $all[ $channel ] ) ) {
			return new WP_Error( 'fw_crm_channel', __( 'This contact cannot be reached on that channel.', 'fw' ) );
		}
		$message = array_merge( array( 'subject' => '', 'body' => '', 'purpose' => 'transactional' ), $message );
		$res     = call_user_func( $all[ $channel ]['send'], $contact, $message );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		FW_CRM_Activity::log( 'contact', (int) $contact->id, 'email' === $channel ? 'email' : 'message', sprintf(
			/* translators: 1: channel, 2: subject or first words */
			__( '%1$s sent: %2$s', 'fw' ),
			$all[ $channel ]['label'],
			'' !== $message['subject'] ? $message['subject'] : wp_trim_words( wp_strip_all_tags( $message['body'] ), 8, '…' )
		), array( 'body' => wp_strip_all_tags( (string) $message['body'] ), 'meta' => array( 'channel' => $channel ) ) );
		/** Fires after a message went out on a channel. */
		do_action( 'unysonplus_crm_channel_sent', $channel, $contact, $message );
		return true;
	}

	/**
	 * The email channel: the CRM's own body pipeline and the sender profile for the purpose.
	 *
	 * @param object $contact
	 * @param array  $message
	 *
	 * @return true|WP_Error
	 */
	public static function send_email( $contact, array $message ) {
		$subject = FW_CRM_Mail::replace( (string) $message['subject'], $contact );
		$html    = FW_CRM_Mail::render_body( FW_CRM_Mail::replace( (string) $message['body'], $contact ), $contact );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( 'marketing' === $message['purpose'] ) {
			$headers = array_merge( $headers, FW_CRM_Mail::unsubscribe_headers( $contact ) );
		}
		$error = null;
		$catch = function ( $e ) use ( &$error ) {
			$error = $e;
		};
		add_action( 'wp_mail_failed', $catch );
		if ( class_exists( 'FW_CRM_Senders' ) ) {
			FW_CRM_Senders::begin( 'marketing' === $message['purpose'] ? 'marketing' : 'transactional' );
		}
		$ok = wp_mail( $contact->email, $subject, $html, $headers );
		if ( class_exists( 'FW_CRM_Senders' ) ) {
			FW_CRM_Senders::end();
		}
		remove_action( 'wp_mail_failed', $catch );
		return $ok ? true : new WP_Error( 'fw_crm_send_failed', $error instanceof WP_Error ? $error->get_error_message() : __( 'The email could not be sent.', 'fw' ) );
	}
}
