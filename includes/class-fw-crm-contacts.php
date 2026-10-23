<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Contacts: the public face of the CRM's people.
 *
 * Every module and extension works with contacts through this class (and, from
 * phase 6, `fw_crm()`), never with the tables. A contact is a subscriber row grown
 * into a full record: an owner, a lifecycle stage, companies, custom fields, a
 * timeline and a consent log. Subscription status (may we email them?) stays a
 * separate thing from the lifecycle stage (where are they in the journey?).
 *
 * Staff-created contacts start as "Not subscribed": only a signup with consent
 * (a form, the newsletter element, an import of people who opted in) subscribes
 * anyone.
 */
class FW_CRM_Contacts {

	/** Fields a caller may set directly on the contact row. */
	const FIELDS = array(
		'email', 'first_name', 'last_name', 'phone', 'job_title', 'lifecycle_stage', 'owner_id', 'user_id',
		'timezone', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country', 'score', 'lifetime_value',
	);

	/**
	 * Lifecycle stages, in order.
	 *
	 * @return array slug => label
	 */
	public static function stages() {
		$stages = array(
			'subscriber'  => __( 'Subscriber', 'fw' ),
			'lead'        => __( 'Lead', 'fw' ),
			'opportunity' => __( 'Opportunity', 'fw' ),
			'customer'    => __( 'Customer', 'fw' ),
			'inactive'    => __( 'Inactive', 'fw' ),
		);

		/** Filters the CRM lifecycle stages (slug => label, in order). */
		return (array) apply_filters( 'unysonplus_crm_lifecycle_stages', $stages );
	}

	/**
	 * @param string $stage
	 *
	 * @return bool
	 */
	public static function is_stage( $stage ) {
		return isset( self::stages()[ (string) $stage ] );
	}

	/**
	 * One contact as an array, or null.
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = FW_CRM_Subscribers::find( $id );
		if ( ! $row ) {
			return null;
		}

		$out = array(
			'id'               => (int) $row->id,
			'email'            => (string) $row->email,
			'first_name'       => (string) $row->first_name,
			'last_name'        => (string) $row->last_name,
			'name'             => trim( $row->first_name . ' ' . $row->last_name ),
			'phone'            => (string) $row->phone,
			'job_title'        => (string) $row->job_title,
			'status'           => (string) $row->status,
			'lifecycle_stage'  => (string) $row->lifecycle_stage,
			'owner_id'         => (int) $row->owner_id,
			'company_id'       => (int) $row->company_id,
			'company_ids'      => FW_CRM_Relations::ids( 'contact', $row->id, 'company' ),
			'user_id'          => (int) $row->user_id,
			'timezone'         => (string) $row->timezone,
			'address'          => array(
				'line1'    => (string) $row->address_line1,
				'line2'    => (string) $row->address_line2,
				'city'     => (string) $row->city,
				'region'   => (string) $row->region,
				'postcode' => (string) $row->postcode,
				'country'  => (string) $row->country,
			),
			'score'            => (int) $row->score,
			'lifetime_value'   => (float) $row->lifetime_value,
			'source'           => (string) $row->source,
			'last_activity_at' => $row->last_activity_at,
			'created_at'       => $row->created_at,
			'deleted_at'       => $row->deleted_at,
			'fields'           => FW_CRM_Fields::get_values( 'contact', $row->id ),
		);

		/** Filters a contact as the CRM API returns it. */
		return (array) apply_filters( 'unysonplus_crm_contact', $out, $row );
	}

	/**
	 * Create a contact, or update the one with the same email.
	 *
	 * @param array $data Any of FIELDS, plus company_id (primary company), fields
	 *                    (custom values), status (defaults to "none").
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		$row = array_intersect_key( $data, array_flip( self::FIELDS ) );
		unset( $row['user_id'] ); // Linked after insert, through link_user()'s checks.

		if ( isset( $row['email'] ) && '' !== trim( (string) $row['email'] ) && ! is_email( $row['email'] ) ) {
			return new WP_Error( 'fw_crm_invalid_email', __( 'That email address is not valid.', 'fw' ) );
		}
		if ( isset( $data['lifecycle_stage'] ) && ! self::is_stage( $data['lifecycle_stage'] ) ) {
			return new WP_Error( 'fw_crm_stage', __( 'Unknown lifecycle stage.', 'fw' ) );
		}

		$email    = isset( $row['email'] ) ? FW_CRM_Subscribers::normalize_email( $row['email'] ) : '';
		$existing = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;

		if ( $existing ) {
			$updated = self::update( (int) $existing->id, $data );
			return is_wp_error( $updated ) ? $updated : (int) $existing->id;
		}

		$row['status'] = isset( $data['status'] ) && FW_CRM_Subscribers::is_valid_status( $data['status'] ) ? $data['status'] : 'none';
		$row['source'] = isset( $data['source'] ) ? (string) $data['source'] : 'admin';

		$id = FW_CRM_Subscribers::insert( $row );
		if ( ! $id ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the contact. It needs an email, a name or a phone number.', 'fw' ) );
		}

		FW_CRM_Activity::log( 'contact', $id, 'created', __( 'Contact added', 'fw' ) );
		if ( ! empty( $data['user_id'] ) ) {
			$linked = self::link_user( $id, (int) $data['user_id'] );
			if ( is_wp_error( $linked ) ) {
				FW_CRM_Subscribers::delete( $id );
				return $linked;
			}
		}
		if ( ! empty( $data['company_id'] ) ) {
			self::add_company( $id, (int) $data['company_id'], true );
		}
		FW_CRM_Fields::assign_autonumbers( 'contact', $id );
		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			FW_CRM_Fields::set_values( 'contact', $id, $data['fields'] );
		}

		/** Fires after a contact is created through the CRM API. */
		do_action( 'unysonplus_crm_contact_created', self::get( $id ) );

		return $id;
	}

	/**
	 * Change a contact. Stage and owner changes land on the timeline.
	 *
	 * @param int   $id
	 * @param array $data Any of FIELDS, plus company_id, fields.
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		$before = FW_CRM_Subscribers::find( $id );
		if ( ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		if ( isset( $data['lifecycle_stage'] ) && ! self::is_stage( $data['lifecycle_stage'] ) ) {
			return new WP_Error( 'fw_crm_stage', __( 'Unknown lifecycle stage.', 'fw' ) );
		}
		if ( isset( $data['email'] ) && '' !== trim( (string) $data['email'] ) ) {
			if ( ! is_email( $data['email'] ) ) {
				return new WP_Error( 'fw_crm_invalid_email', __( 'That email address is not valid.', 'fw' ) );
			}
			$other = FW_CRM_Subscribers::find_by_email( $data['email'] );
			if ( $other && (int) $other->id !== (int) $id ) {
				return new WP_Error( 'fw_crm_duplicate', __( 'Another contact already uses that email address.', 'fw' ) );
			}
		}

		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$set = FW_CRM_Fields::set_values( 'contact', $id, $data['fields'] );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		if ( array_key_exists( 'user_id', $data ) && (int) $data['user_id'] !== (int) $before->user_id ) {
			$linked = (int) $data['user_id'] ? self::link_user( $id, (int) $data['user_id'] ) : self::unlink_user( $id );
			if ( is_wp_error( $linked ) ) {
				return $linked;
			}
		}

		$row = array_intersect_key( $data, array_flip( self::FIELDS ) );
		unset( $row['user_id'] );
		if ( $row ) {
			FW_CRM_Subscribers::update( $id, $row );
		}
		if ( array_key_exists( 'company_id', $data ) ) {
			if ( (int) $data['company_id'] ) {
				self::add_company( $id, (int) $data['company_id'], true );
			} else {
				FW_CRM_Subscribers::update( $id, array( 'company_id' => 0 ) );
			}
		}

		$after = FW_CRM_Subscribers::find( $id );
		if ( $after->lifecycle_stage !== $before->lifecycle_stage ) {
			$stages = self::stages();
			FW_CRM_Activity::log( 'contact', $id, 'stage', sprintf(
				__( 'Stage changed from %1$s to %2$s', 'fw' ),
				$stages[ $before->lifecycle_stage ] ?? $before->lifecycle_stage,
				$stages[ $after->lifecycle_stage ] ?? $after->lifecycle_stage
			), array( 'meta' => array( 'from' => $before->lifecycle_stage, 'to' => $after->lifecycle_stage ) ) );
		}
		if ( (int) $after->owner_id !== (int) $before->owner_id ) {
			$user = $after->owner_id ? get_userdata( (int) $after->owner_id ) : null;
			FW_CRM_Activity::log( 'contact', $id, 'owner', $user ? sprintf( __( 'Owner set to %s', 'fw' ), $user->display_name ) : __( 'Owner removed', 'fw' ), array(
				'meta' => array( 'from' => (int) $before->owner_id, 'to' => (int) $after->owner_id ),
			) );
		}

		$changes = array();
		foreach ( array_merge( self::FIELDS, array( 'company_id', 'status', 'deleted_at' ) ) as $k ) {
			if ( (string) $before->$k !== (string) $after->$k ) {
				$changes[ $k ] = array( 'old' => $before->$k, 'new' => $after->$k );
			}
		}

		/**
		 * Fires after a contact changes: the contact now, the row before, and the
		 * changed columns as field => { old, new } (custom fields are not included).
		 */
		do_action( 'unysonplus_crm_contact_updated', self::get( $id ), $before, $changes );

		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * WordPress users
	 * ---------------------------------------------------------------------- */

	/**
	 * The contact linked to a WordPress user. A contact in the bin does not count.
	 *
	 * @param int $user_id
	 *
	 * @return array|null The contact (same shape as get()), or null.
	 */
	public static function find_by_user( $user_id ) {
		$id = self::id_for_user( $user_id );

		return $id ? self::get( $id ) : null;
	}

	/**
	 * Just the id of the contact linked to a user (0 when none): one indexed query,
	 * cached for the request, for hot paths such as access checks.
	 *
	 * @param int  $user_id
	 * @param bool $include_trashed
	 *
	 * @return int
	 */
	public static function id_for_user( $user_id, $include_trashed = false ) {
		global $wpdb;

		static $cache = array();

		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return 0;
		}
		$key = $user_id . ( $include_trashed ? 't' : '' );
		if ( ! isset( $cache[ $key ] ) || self::$users_dirty ) {
			if ( self::$users_dirty ) {
				$cache             = array();
				self::$users_dirty = false;
			}
			$table         = FW_CRM_Installer::table( 'subscribers' );
			$cache[ $key ] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d" . ( $include_trashed ? '' : ' AND deleted_at IS NULL' ) . ' ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$user_id
			) );
		}

		return $cache[ $key ];
	}

	/** @var bool Set when a link changes, so id_for_user() re-reads. */
	private static $users_dirty = false;

	/** Forget cached user → contact lookups (after a merge or a direct write). */
	public static function flush_user_cache() {
		self::$users_dirty = true;
	}

	/**
	 * Does the contact linked to this user hold these tags (or lists)? The access
	 * check other extensions use; a user with no contact holds nothing.
	 *
	 * @param int    $user_id
	 * @param array  $slugs
	 * @param string $match any | all | none
	 * @param string $type  tag | list
	 *
	 * @return bool
	 */
	public static function user_has( $user_id, array $slugs, $match = 'any', $type = 'tag' ) {
		return FW_CRM_Subscribers::has_lists( self::id_for_user( $user_id ), $slugs, $match, $type );
	}

	/**
	 * Link a contact to a WordPress user. A contact has at most one user and a user
	 * at most one contact; linking either to a different partner is an error (unlink
	 * first), relinking the same pair is a no-op.
	 *
	 * @param int $contact_id
	 * @param int $user_id
	 *
	 * @return true|WP_Error
	 */
	public static function link_user( $contact_id, $user_id ) {
		$contact = FW_CRM_Subscribers::find( $contact_id );
		if ( ! $contact ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'fw_crm_no_user', __( 'That WordPress user does not exist.', 'fw' ) );
		}
		if ( (int) $contact->user_id === (int) $user_id ) {
			return true;
		}
		if ( (int) $contact->user_id ) {
			return new WP_Error( 'fw_crm_contact_linked', __( 'This contact is already linked to a different site account. Unlink it first.', 'fw' ) );
		}
		$other = self::id_for_user( $user_id, true );
		if ( $other && $other !== (int) $contact_id ) {
			return new WP_Error( 'fw_crm_user_linked', __( 'That site account is already linked to another contact.', 'fw' ) );
		}

		FW_CRM_Subscribers::update( $contact_id, array( 'user_id' => (int) $user_id ) );
		self::$users_dirty = true;
		FW_CRM_Activity::log( 'contact', $contact_id, 'user', sprintf( __( 'Linked to the site account %s', 'fw' ), $user->user_login ), array( 'meta' => array( 'user_id' => (int) $user_id ) ) );

		/** Fires after a contact is linked to a WordPress user. */
		do_action( 'unysonplus_crm_user_linked', (int) $contact_id, (int) $user_id );

		return true;
	}

	/**
	 * Remove a contact's link to its WordPress user (the user is untouched).
	 *
	 * @param int $contact_id
	 *
	 * @return true|WP_Error
	 */
	public static function unlink_user( $contact_id ) {
		$contact = FW_CRM_Subscribers::find( $contact_id );
		if ( ! $contact ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		$old = (int) $contact->user_id;
		if ( ! $old ) {
			return true;
		}

		FW_CRM_Subscribers::update( $contact_id, array( 'user_id' => 0 ) );
		self::$users_dirty = true;
		$user = get_userdata( $old );
		FW_CRM_Activity::log( 'contact', $contact_id, 'user', $user ? sprintf( __( 'Unlinked from the site account %s', 'fw' ), $user->user_login ) : __( 'Unlinked from its site account', 'fw' ), array( 'meta' => array( 'user_id' => $old ) ) );

		/** Fires after a contact's WordPress user link is removed: contact id, the user id it had. */
		do_action( 'unysonplus_crm_user_unlinked', (int) $contact_id, $old );

		return true;
	}

	/**
	 * Link a company. The first (or $primary) one becomes the contact's company_id.
	 *
	 * @param int  $contact_id
	 * @param int  $company_id
	 * @param bool $primary
	 *
	 * @return true|WP_Error
	 */
	public static function add_company( $contact_id, $company_id, $primary = false ) {
		$company = FW_CRM_Companies::find( $company_id );
		if ( ! $company ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Company not found.', 'fw' ) );
		}
		$contact = FW_CRM_Subscribers::find( $contact_id );
		if ( ! $contact ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Contact not found.', 'fw' ) );
		}
		if ( FW_CRM_Relations::link( 'contact', $contact_id, 'company', $company_id, 'works_at' ) ) {
			FW_CRM_Activity::log( 'contact', $contact_id, 'company', sprintf( __( 'Linked to %s', 'fw' ), $company->name ), array( 'meta' => array( 'company_id' => (int) $company_id ) ) );
			FW_CRM_Activity::log( 'company', $company_id, 'contact', sprintf( __( '%s linked', 'fw' ), trim( $contact->first_name . ' ' . $contact->last_name ) ?: (string) $contact->email ), array( 'meta' => array( 'contact_id' => (int) $contact_id ) ) );
		}
		if ( $primary || ! (int) $contact->company_id ) {
			FW_CRM_Subscribers::update( $contact_id, array( 'company_id' => (int) $company_id ) );
		}
		return true;
	}

	/**
	 * Unlink a company (the primary one falls back to another linked company).
	 *
	 * @param int $contact_id
	 * @param int $company_id
	 */
	public static function remove_company( $contact_id, $company_id ) {
		FW_CRM_Relations::unlink( 'contact', $contact_id, 'company', $company_id, 'works_at' );
		$contact = FW_CRM_Subscribers::find( $contact_id );
		if ( $contact && (int) $contact->company_id === (int) $company_id ) {
			$left = FW_CRM_Relations::ids( 'contact', $contact_id, 'company' );
			FW_CRM_Subscribers::update( $contact_id, array( 'company_id' => $left ? (int) $left[0] : 0 ) );
		}
	}

	/** Move to the bin. @param int $id */
	public static function trash( $id ) {
		if ( ! FW_CRM_Subscribers::find( $id ) ) {
			return false;
		}
		FW_CRM_Subscribers::update( $id, array( 'deleted_at' => current_time( 'mysql' ) ) );
		self::$users_dirty = true;
		FW_CRM_Subscribers::flush_has_cache();
		FW_CRM_Activity::log( 'contact', $id, 'trashed', __( 'Moved to the bin', 'fw' ) );
		/** Fires after a contact is moved to the bin. */
		do_action( 'unysonplus_crm_contact_trashed', (int) $id );
		return true;
	}

	/** Bring back from the bin. @param int $id */
	public static function restore( $id ) {
		if ( ! FW_CRM_Subscribers::find( $id ) ) {
			return false;
		}
		FW_CRM_Subscribers::update( $id, array( 'deleted_at' => null ) );
		self::$users_dirty = true;
		FW_CRM_Subscribers::flush_has_cache();
		FW_CRM_Activity::log( 'contact', $id, 'restored', __( 'Restored from the bin', 'fw' ) );
		/** Fires after a contact comes back from the bin. */
		do_action( 'unysonplus_crm_contact_restored', (int) $id );
		return true;
	}

	/**
	 * Delete for good: the row, its lists and tags, links, timeline, consent log and
	 * field values.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function purge( $id ) {
		$row = FW_CRM_Subscribers::find( $id );
		if ( ! $row ) {
			return false;
		}
		FW_CRM_Relations::delete_for( 'contact', $id );
		FW_CRM_Activity::delete_for( 'contact', $id );
		FW_CRM_Consent::delete_for( $id );
		FW_CRM_Fields::delete_for( 'contact', $id );
		FW_CRM_Tasks::delete_for( 'contact', $id );
		global $wpdb;
		$wpdb->update( FW_CRM_Installer::table( 'deals' ), array( 'contact_id' => 0 ), array( 'contact_id' => (int) $id ) ); // Deals stay.
		$wpdb->update( FW_CRM_Installer::table( 'cases' ), array( 'contact_id' => 0 ), array( 'contact_id' => (int) $id ) ); // Cases stay.
		foreach ( array( 'contact_events', 'contact_scores', 'score_log', 'commerce_summaries' ) as $t ) {
			$wpdb->delete( FW_CRM_Installer::table( $t ), array( 'contact_id' => (int) $id ) );
		}
		$deleted           = FW_CRM_Subscribers::delete( $id );
		self::$users_dirty = true;
		FW_CRM_Subscribers::flush_has_cache();

		/** Fires after a contact is deleted for good. */
		do_action( 'unysonplus_crm_contact_deleted', $row );

		return $deleted;
	}
}
