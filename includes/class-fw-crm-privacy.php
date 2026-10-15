<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * GDPR: plugs the subscriber store into WordPress's own Tools → Export/Erase
 * Personal Data flow. A handful of lines, and it means the site owner's existing
 * data-request workflow just covers us.
 *
 * The erase rule is the important one: we ANONYMISE and keep the row as
 * `unsubscribed` rather than deleting it. Deleting the address means the next
 * CSV import silently re-subscribes that person — which is the exact thing they
 * asked not to happen.
 */
class FW_CRM_Privacy {

	const GROUP = 'unysonplus-crm';

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, '_register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, '_register_eraser' ) );
	}

	/**
	 * @internal
	 *
	 * @param array $exporters
	 *
	 * @return array
	 */
	public function _register_exporter( $exporters ) {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'CRM contact', 'fw' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @internal
	 *
	 * @param array $erasers
	 *
	 * @return array
	 */
	public function _register_eraser( $erasers ) {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'CRM contact', 'fw' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 *
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$subscriber = FW_CRM_Subscribers::find_by_email( $email );

		if ( ! $subscriber ) {
			return array( 'data' => array(), 'done' => true );
		}

		$lists = wp_list_pluck( FW_CRM_Subscribers::get_lists( $subscriber->id, 'list' ), 'title' );
		$tags  = wp_list_pluck( FW_CRM_Subscribers::get_lists( $subscriber->id, 'tag' ), 'title' );
		$meta  = $subscriber->meta ? json_decode( $subscriber->meta, true ) : array();

		$data = array(
			array( 'name' => __( 'Email', 'fw' ), 'value' => $subscriber->email ),
			array( 'name' => __( 'Name', 'fw' ), 'value' => trim( $subscriber->first_name . ' ' . $subscriber->last_name ) ),
			array( 'name' => __( 'Status', 'fw' ), 'value' => $subscriber->status ),
			array( 'name' => __( 'Lists', 'fw' ), 'value' => implode( ', ', $lists ) ),
			array( 'name' => __( 'Tags', 'fw' ), 'value' => implode( ', ', $tags ) ),
			array( 'name' => __( 'Signed up', 'fw' ), 'value' => $subscriber->created_at ),
			array( 'name' => __( 'Signed up on page', 'fw' ), 'value' => $subscriber->source_url ),
			array( 'name' => __( 'IP address', 'fw' ), 'value' => $subscriber->ip ),
		);

		if ( is_array( $meta ) && ! empty( $meta['consent_text'] ) ) {
			$data[] = array( 'name' => __( 'Consent text shown', 'fw' ), 'value' => $meta['consent_text'] );
		}

		return array(
			'data' => array(
				array(
					'group_id'    => self::GROUP,
					'group_label' => __( 'CRM contact', 'fw' ),
					'item_id'     => 'subscriber-' . $subscriber->id,
					'data'        => $data,
				),
			),
			'done' => true,
		);
	}

	/**
	 * @param string $email
	 * @param int    $page
	 *
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		$subscriber = FW_CRM_Subscribers::find_by_email( $email );

		if ( ! $subscriber ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		// Anonymise in place and keep the opt-out — see the class docblock.
		$anonymous = 'deleted-' . wp_generate_password( 12, false, false ) . '@site.invalid';

		FW_CRM_Subscribers::update( $subscriber->id, array(
			'email'             => $anonymous,
			'first_name'        => '',
			'last_name'         => '',
			'status'            => 'unsubscribed',
			'unsubscribed_at'   => current_time( 'mysql' ),
			'ip'                => '',
			'source_url'        => '',
			'meta'              => array(),
			'confirm_token'     => '',
			'unsubscribe_token' => FW_CRM_Subscribers::generate_token(),
		) );

		do_action( 'unysonplus_crm_subscriber_updated', FW_CRM_Subscribers::find( $subscriber->id ), $subscriber, array( 'context' => 'gdpr_erase' ) );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array(
				__( 'The newsletter record was anonymised and kept as unsubscribed, so the address cannot be re-added by a future import.', 'fw' ),
			),
			'done'           => true,
		);
	}
}
