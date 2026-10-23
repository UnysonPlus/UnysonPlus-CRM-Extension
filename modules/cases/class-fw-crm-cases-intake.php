<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * How cases arrive besides "New case": from a contact form ("Add to the CRM" → Also create →
 * A support case) and from email.
 *
 * Email (needs the Email conversations module's incoming mail):
 * - A message whose subject carries a case number ("Re: [Case #12] …") from that case's
 *   contact goes on the case's timeline, and a case that was waiting or solved opens again.
 * - Any other message from a customer opens a new case, when "Open a case for each new
 *   email" is on. A reply to an email the team sent from the CRM never does: that is a
 *   conversation, not a new request.
 */
class FW_CRM_Cases_Intake {

	public static function init() {
		add_action( 'unysonplus_crm_email_received', array( __CLASS__, '_on_email' ), 10, 3 );
	}

	/**
	 * A form submission becomes a case: the subject comes from the first text answer that looks like
	 * one (a field named subject/topic, else the first dropdown), the message from the longest answer.
	 *
	 * @param int    $contact_id
	 * @param string $form_title
	 * @param array  $ctx    Forms action context (fields, entry_id, …).
	 * @param array  $values field id => answer as text
	 * @param array  $types  field id => field type
	 *
	 * @return int|WP_Error
	 */
	public static function from_form( $contact_id, $form_title, array $ctx, array $values, array $types ) {
		$title = '';
		foreach ( (array) $ctx['fields'] as $f ) {
			$label = strtolower( (string) ( $f['label'] ?? '' ) );
			$v     = $values[ (string) $f['id'] ] ?? '';
			if ( '' !== $v && preg_match( '/subject|topic|issue|problem|question|about/', $label ) && 'textarea' !== ( $types[ (string) $f['id'] ] ?? '' ) ) {
				$title = $v;
				break;
			}
		}
		if ( '' === $title ) {
			foreach ( $types as $id => $t ) {
				if ( in_array( $t, array( 'select', 'radio' ), true ) && '' !== ( $values[ $id ] ?? '' ) ) {
					$title = $values[ $id ];
					break;
				}
			}
		}
		$message = '';
		foreach ( $types as $id => $t ) {
			if ( 'textarea' === $t && strlen( $values[ $id ] ?? '' ) > strlen( $message ) ) {
				$message = $values[ $id ];
			}
		}
		if ( '' === $title ) {
			$title = '' !== $message ? wp_trim_words( $message, 8, '…' ) : (string) $form_title;
		}
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		return FW_CRM_Cases::create( array(
			'title'      => $title,
			'contact_id' => (int) $contact_id,
			'owner_id'   => $contact && (int) $contact->owner_id ? (int) $contact->owner_id : (int) FW_CRM_Cases::settings()['default_owner'],
			'source'     => 'form',
			'body'       => FW_CRM_Form_Map::answers_html( $ctx['fields'], array(), (int) ( $ctx['entry_id'] ?? 0 ) ),
			'actor_id'   => 0,
		) );
	}

	/**
	 * @internal unysonplus_crm_email_received
	 *
	 * @param int   $contact_id
	 * @param array $m
	 * @param int   $deal_id
	 */
	public static function _on_email( $contact_id, $m, $deal_id ) {
		$subject = trim( (string) ( $m['subject'] ?? '' ) );
		$text    = '' !== trim( (string) ( $m['text'] ?? '' ) ) ? (string) $m['text'] : ( class_exists( 'FW_CRM_Mime' ) ? FW_CRM_Mime::html_to_text( (string) ( $m['html'] ?? '' ) ) : '' );
		$reply   = class_exists( 'FW_CRM_Mime' ) ? FW_CRM_Mime::reply_part( $text ) : $text;
		$reply   = '' !== trim( $reply ) ? trim( $reply ) : trim( $text );

		// A reply on an existing case.
		$num  = FW_CRM_Cases::number_in( $subject );
		$case = $num ? FW_CRM_Cases::find( $num ) : null;
		if ( $case && (int) $case->contact_id === (int) $contact_id ) {
			FW_CRM_Activity::log( 'case', (int) $case->id, 'email_in', __( 'Customer replied', 'fw' ), array(
				'body'     => esc_html( $reply ),
				'meta'     => array( 'direction' => 'in', 'subject' => $subject, 'message_id' => (string) ( $m['message_id'] ?? '' ) ),
				'actor_id' => 0,
			) );
			if ( in_array( $case->status, array( 'waiting', 'solved' ), true ) ) {
				FW_CRM_Cases::update( (int) $case->id, array( 'status' => 'open' ) );
			}
			return;
		}

		// A new request.
		$in_reply_to = (string) ( $m['in_reply_to'] ?? '' ) . ' ' . implode( ' ', (array) ( $m['references'] ?? array() ) );
		if ( $deal_id || ! FW_CRM_Cases::settings()['from_email'] || false !== strpos( $in_reply_to, 'fwcrm-' ) ) {
			return;
		}
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		FW_CRM_Cases::create( array(
			'title'      => '' !== $subject ? preg_replace( '/^(re|fwd?|aw|sv)\s*:\s*/i', '', $subject ) : __( '(no subject)', 'fw' ),
			'contact_id' => (int) $contact_id,
			'owner_id'   => $contact && (int) $contact->owner_id ? (int) $contact->owner_id : (int) FW_CRM_Cases::settings()['default_owner'],
			'source'     => 'email',
			'body'       => esc_html( $reply ),
			'actor_id'   => 0,
		) );
	}
}
