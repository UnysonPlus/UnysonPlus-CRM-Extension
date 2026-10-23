<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * WordPress users and the CRM, for the Membership extension and anything like it.
 *
 * - A contact is linked to a WordPress user only when someone has to log in: ensure_for_user()
 *   finds the contact by the account's email (or creates one) and links it. Nothing creates
 *   contacts for every registered user.
 * - Access checks for a user: tags, lists and (new) segments, through user_can_access().
 * - Membership events (joined, renewed, expired, cancelled, changed plan) reach the timeline,
 *   the events catalogue, the purchase summary (plan and status) and Automations through one
 *   call: membership_event().
 * - A deleted WordPress user is unlinked; the contact and its history stay.
 */
class FW_CRM_Users {

	public static function init() {
		add_action( 'deleted_user', function ( $user_id ) {
			$cid = FW_CRM_Contacts::id_for_user( (int) $user_id, true );
			if ( $cid ) {
				FW_CRM_Contacts::unlink_user( $cid );
			}
		} );
		add_filter( 'unysonplus_crm_activity_types', function ( $types ) {
			$types['membership'] = array( 'label' => __( 'Membership', 'fw' ), 'icon' => 'dashicons-groups' );
			return $types;
		} );
		add_filter( 'unysonplus_crm_automation_triggers', function ( $t ) {
			foreach ( self::membership_events() as $slug => $label ) {
				if ( ! isset( $t[ 'membership.' . $slug ] ) ) {
					$t[ 'membership.' . $slug ] = array(
						'label'  => $label,
						'group'  => __( 'Membership', 'fw' ),
						'fields' => array( 'plan' => array( 'label' => __( 'Plan (empty = any)', 'fw' ), 'type' => 'text' ) ),
					);
				}
			}
			return $t;
		}, 5 );
		// Whoever fires a membership automation event (the Membership extension, or membership_event()
		// below), the CRM records it once: timeline, purchase summary, event history and catalogue.
		add_action( 'unysonplus_crm_automation_event', array( __CLASS__, '_on_membership' ), 5, 3 );
		add_filter( 'unysonplus_crm_events_catalogue', function ( $c ) {
			foreach ( self::membership_events() as $slug => $label ) {
				$c[ 'membership.' . $slug ] = array( 'label' => $label, 'group' => __( 'Membership', 'fw' ), 'data' => array( 'plan' => 'string', 'status' => 'string', 'expires_at' => 'datetime' ) );
			}
			return $c;
		} );
	}

	/** @return array slug => label */
	public static function membership_events() {
		return array(
			'joined'         => __( 'Member joined', 'fw' ),
			'trial_started'  => __( 'Free trial started', 'fw' ),
			'renewed'        => __( 'Membership renewed', 'fw' ),
			'payment_failed' => __( 'Renewal payment failed', 'fw' ),
			'plan_changed'   => __( 'Membership plan changed', 'fw' ),
			'expired'        => __( 'Membership expired', 'fw' ),
			'cancelled'      => __( 'Membership cancelled', 'fw' ),
		);
	}

	/**
	 * The contact for a WordPress user: the linked one, else the one with the account's email
	 * (then linked), else a new contact (status "none": in the CRM, not subscribed).
	 *
	 * @param int $user_id
	 *
	 * @return int|WP_Error The contact id.
	 */
	public static function ensure_for_user( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'fw_crm_no_user', __( 'User not found.', 'fw' ) );
		}
		$cid = FW_CRM_Contacts::id_for_user( (int) $user_id );
		if ( $cid ) {
			return $cid;
		}
		$c = FW_CRM_Subscribers::find_by_email( strtolower( (string) $user->user_email ) );
		if ( $c && empty( $c->deleted_at ) ) {
			$cid = (int) $c->id;
		} else {
			$cid = FW_CRM_Contacts::create( array(
				'email'      => (string) $user->user_email,
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'source'     => 'user',
			) );
			if ( is_wp_error( $cid ) ) {
				return $cid;
			}
		}
		$linked = FW_CRM_Contacts::link_user( (int) $cid, (int) $user_id );
		return is_wp_error( $linked ) && 'fw_crm_contact_linked' !== $linked->get_error_code() ? $linked : (int) $cid;
	}

	/**
	 * May this user in? Any of the given tags or lists, or membership of a segment.
	 *
	 * @param int   $user_id
	 * @param array $rule { tags: slugs, lists: slugs, segment: id|slug, match: any|all }
	 *
	 * @return bool A user without a contact holds nothing.
	 */
	public static function user_can_access( $user_id, array $rule ) {
		$cid = FW_CRM_Contacts::id_for_user( (int) $user_id );
		if ( ! $cid ) {
			return false;
		}
		$match  = 'all' === ( $rule['match'] ?? 'any' ) ? 'all' : 'any';
		$checks = array();
		if ( ! empty( $rule['tags'] ) ) {
			$checks[] = FW_CRM_Subscribers::has_lists( $cid, (array) $rule['tags'], $match, 'tag' );
		}
		if ( ! empty( $rule['lists'] ) ) {
			$checks[] = FW_CRM_Subscribers::has_lists( $cid, (array) $rule['lists'], $match, 'list' );
		}
		if ( ! empty( $rule['segment'] ) ) {
			$checks[] = self::in_segment( $cid, $rule['segment'] );
		}
		if ( ! $checks ) {
			return false;
		}
		return 'all' === $match ? ! in_array( false, $checks, true ) : in_array( true, $checks, true );
	}

	/**
	 * Is a contact in a segment right now?
	 *
	 * @param int        $contact_id
	 * @param int|string $segment An id or a slug.
	 *
	 * @return bool
	 */
	public static function in_segment( $contact_id, $segment ) {
		if ( ! is_numeric( $segment ) ) {
			$s       = FW_CRM_Lists::find_segment_by_slug( (string) $segment );
			$segment = $s ? (int) $s->id : 0;
		}
		return $segment && class_exists( 'FW_CRM_Segments' ) && FW_CRM_Segments::contact_matches( (int) $segment, (int) $contact_id );
	}

	/**
	 * Report a membership event for a user or contact (for a membership plugin that is not the
	 * Membership extension). It becomes the `membership.<event>` automation event, which the CRM
	 * records once (see _on_membership()).
	 *
	 * @param int|WP_User $who   A contact id, or a WP_User (its contact is found or created).
	 * @param string      $event joined|trial_started|renewed|payment_failed|plan_changed|expired|cancelled
	 * @param array       $data  plan_name, status, expires_at, total (amount paid), dedupe
	 *
	 * @return true|WP_Error
	 */
	public static function membership_event( $who, $event, array $data = array() ) {
		$cid = $who instanceof WP_User ? self::ensure_for_user( (int) $who->ID ) : (int) $who;
		if ( is_wp_error( $cid ) || ! $cid ) {
			return is_wp_error( $cid ) ? $cid : new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		if ( ! isset( self::membership_events()[ $event ] ) ) {
			return new WP_Error( 'fw_crm_event', __( 'Unknown membership event.', 'fw' ) );
		}
		do_action( 'unysonplus_crm_automation_event', 'membership.' . $event, $cid, $data );
		return true;
	}

	/**
	 * @internal A membership event reached the CRM: timeline, summary, history, catalogue.
	 *
	 * @param string $slug
	 * @param int    $contact_id
	 * @param array  $ctx plan_name (or plan), status, expires_at, total
	 */
	public static function _on_membership( $slug, $contact_id, $ctx = array() ) {
		if ( 0 !== strpos( (string) $slug, 'membership.' ) || ! $contact_id ) {
			return;
		}
		$event  = substr( (string) $slug, 11 );
		$events = self::membership_events();
		if ( ! isset( $events[ $event ] ) ) {
			return;
		}
		$ctx    = is_array( $ctx ) ? $ctx : array();
		$plan   = sanitize_text_field( (string) ( $ctx['plan_name'] ?? ( $ctx['plan'] ?? '' ) ) );
		$status = sanitize_key( (string) ( $ctx['status'] ?? array( 'joined' => 'active', 'trial_started' => 'trial', 'renewed' => 'active', 'payment_failed' => 'past_due', 'plan_changed' => 'active', 'expired' => 'expired', 'cancelled' => 'cancelled' )[ $event ] ) );
		// The Membership extension writes its own, richer timeline entries; anyone else gets this one.
		if ( ! class_exists( 'FW_Membership_CRM_Timeline' ) ) {
			FW_CRM_Activity::log( 'contact', (int) $contact_id, 'membership', $events[ $event ] . ( '' !== $plan ? ': ' . $plan : '' ), array( 'meta' => array( 'event' => $event, 'plan' => $plan, 'status' => $status ), 'actor_id' => 0 ) );
		}
		$summary = array( 'plan' => $plan, 'plan_status' => $status );
		if ( ! empty( $ctx['total'] ) ) {
			$cur                      = FW_CRM_Commerce::get( (int) $contact_id, 'membership' );
			$summary['orders']        = $cur['orders'] + 1;
			$summary['total']         = $cur['total'] + (float) $ctx['total'];
			$summary['last_order_at'] = current_time( 'mysql' );
		}
		FW_CRM_Commerce::set( (int) $contact_id, 'membership', $summary );
		FW_CRM_Events_Store::record( (int) $contact_id, 'membership_' . $event, array( 'value' => $plan ) );
		FW_CRM_Events::emit( 'membership.' . $event, (int) $contact_id, 'contact', 0, array( 'plan' => $plan, 'status' => $status, 'expires_at' => (string) ( $ctx['expires_at'] ?? '' ) ) );
	}
}
