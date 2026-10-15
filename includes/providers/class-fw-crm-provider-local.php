<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The default provider: our own tables.
 *
 * It is registered first and always enabled, so the local store is never a
 * special case in the service — "save it here" and "push it to Mailchimp" go
 * through exactly the same call. That symmetry is the whole point of the
 * provider layer, and it is what lets an ESP add-on ship with zero core changes.
 *
 * The row itself is written by the service (which owns the lifecycle rules);
 * this provider owns list/tag membership, which is the part an ESP adapter would
 * also own remotely.
 */
class FW_CRM_Provider_Local extends FW_CRM_Provider {

	/** @return string */
	public function get_slug() {
		return 'local';
	}

	/** @return string */
	public function get_label() {
		return __( 'Local database', 'fw' );
	}

	/** @return bool */
	public function is_configured() {
		return true;
	}

	/**
	 * @param object $subscriber
	 * @param array  $args lists (ids), tags (ids)
	 *
	 * @return true|WP_Error
	 */
	public function subscribe( $subscriber, array $args = array() ) {
		if ( empty( $subscriber->id ) ) {
			return new WP_Error( 'fw_crm_no_subscriber', __( 'No stored subscriber to attach lists to.', 'fw' ) );
		}

		foreach ( (array) ( isset( $args['lists'] ) ? $args['lists'] : array() ) as $list_id ) {
			FW_CRM_Subscribers::add_to_list( $subscriber->id, $list_id, 'list', (string) ( $args['source'] ?? '' ) );
		}

		foreach ( (array) ( isset( $args['tags'] ) ? $args['tags'] : array() ) as $tag_id ) {
			FW_CRM_Subscribers::add_to_list( $subscriber->id, $tag_id, 'tag' );
		}

		return true;
	}

	/**
	 * Membership is deliberately KEPT on unsubscribe — the row's `status` is the
	 * opt-out record, and remembering which lists they were on is what makes a
	 * later re-subscribe (and any audit) meaningful.
	 *
	 * @param object $subscriber
	 * @param array  $args
	 *
	 * @return true|WP_Error
	 */
	public function unsubscribe( $subscriber, array $args = array() ) {
		return true;
	}
}
