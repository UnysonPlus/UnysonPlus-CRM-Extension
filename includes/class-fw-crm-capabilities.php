<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Team roles: who may use the CRM, and which records they see.
 *
 * Capabilities:
 *   fw_crm_access    use the CRM (the admin page and REST)
 *   fw_crm_view_all  see and edit every contact and company, not only their own
 *   fw_crm_manage    campaigns, lists and segments, import/export, the bin, merging,
 *                    custom fields and settings
 *
 * Roles: "CRM Manager" (all three) and "Sales Rep" (access only: they see and edit
 * the records they own). Administrators always hold all three.
 *
 * Scoping is applied where a PERSON looks at data (the CRM screens and REST), never
 * in the repository: background work (sending, automations) and other extensions'
 * server-side checks (Membership's access rules) always see everything.
 */
class FW_CRM_Capabilities {

	const ACCESS   = 'fw_crm_access';
	const VIEW_ALL = 'fw_crm_view_all';
	const MANAGE   = 'fw_crm_manage';

	/** Bump when the role definitions change. */
	const ROLES_VERSION = '2';

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, '_grant_admins' ), 10, 1 );
		// One autoloaded option read when the roles are current; extensions boot
		// during `init`, so this runs directly rather than on a hook that may have passed.
		self::maybe_add_roles();
	}

	/**
	 * Administrators (anyone who can manage_options) hold every CRM capability, even
	 * where the role was edited or on a multisite network.
	 *
	 * @internal
	 *
	 * @param array $allcaps
	 *
	 * @return array
	 */
	public static function _grant_admins( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::ACCESS ]   = true;
			$allcaps[ self::VIEW_ALL ] = true;
			$allcaps[ self::MANAGE ]   = true;
		}
		return $allcaps;
	}

	/**
	 * Create the two roles (and give the administrator role the capabilities, so
	 * user queries by capability find administrators too). Runs once per version.
	 */
	public static function maybe_add_roles() {
		if ( get_option( 'fw_crm_roles_version' ) === self::ROLES_VERSION ) {
			return;
		}
		$all = array( 'read' => true, self::ACCESS => true, self::VIEW_ALL => true, self::MANAGE => true );

		remove_role( 'fw_crm_manager' );
		remove_role( 'fw_crm_sales_rep' );
		// upload_files (2): files on records go through the media library.
		add_role( 'fw_crm_manager', __( 'CRM Manager', 'fw' ), array_merge( $all, array( 'upload_files' => true ) ) );
		add_role( 'fw_crm_sales_rep', __( 'Sales Rep', 'fw' ), array( 'read' => true, 'upload_files' => true, self::ACCESS => true ) );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( self::ACCESS, self::VIEW_ALL, self::MANAGE ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		update_option( 'fw_crm_roles_version', self::ROLES_VERSION, true );
	}

	/** Remove the roles and capabilities (on uninstall). */
	public static function remove_roles() {
		remove_role( 'fw_crm_manager' );
		remove_role( 'fw_crm_sales_rep' );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( self::ACCESS, self::VIEW_ALL, self::MANAGE ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
		delete_option( 'fw_crm_roles_version' );
	}

	/* ---------------------------------------------------------------------- *
	 * Checks
	 * ---------------------------------------------------------------------- */

	/** @return bool */
	public static function can_manage() {
		return current_user_can( self::MANAGE );
	}

	/** @return bool Does the current person see every record? */
	public static function sees_all() {
		return current_user_can( self::VIEW_ALL );
	}

	/**
	 * Narrow query args to what the current person may see: a sales rep sees the
	 * records they own.
	 *
	 * @param array $args FW_CRM_Subscribers / FW_CRM_Companies query args.
	 *
	 * @return array
	 */
	public static function scope( array $args ) {
		if ( ! self::sees_all() ) {
			$args['owner'] = get_current_user_id();
		}
		return $args;
	}

	/**
	 * The owner the current person may filter on: 0 = everyone, else their own id.
	 *
	 * @return int
	 */
	public static function owner_scope() {
		return self::sees_all() ? 0 : get_current_user_id();
	}

	/**
	 * May the current person open and edit this contact?
	 *
	 * @param object|int $contact A contact row or id.
	 *
	 * @return bool
	 */
	public static function can_edit_contact( $contact ) {
		if ( self::sees_all() ) {
			return true;
		}
		$row = is_object( $contact ) ? $contact : FW_CRM_Subscribers::find( (int) $contact );
		return $row && (int) $row->owner_id === get_current_user_id();
	}

	/**
	 * @param object|int $company A company row or id.
	 *
	 * @return bool
	 */
	public static function can_edit_company( $company ) {
		if ( self::sees_all() ) {
			return true;
		}
		$row = is_object( $company ) ? $company : FW_CRM_Companies::find( (int) $company );
		return $row && (int) $row->owner_id === get_current_user_id();
	}

	/**
	 * @param object|int $deal A deal row or id.
	 *
	 * @return bool
	 */
	public static function can_edit_deal( $deal ) {
		if ( self::sees_all() ) {
			return true;
		}
		$row = is_object( $deal ) ? $deal : ( class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::find( (int) $deal ) : null );
		return $row && (int) $row->owner_id === get_current_user_id();
	}

	/**
	 * May the current person work on this case? Its owner, or anyone who sees everything.
	 * An unassigned case is open to every CRM user, so a new request is never stuck.
	 *
	 * @param object|int $case
	 *
	 * @return bool
	 */
	public static function can_edit_case( $case ) {
		if ( self::sees_all() ) {
			return true;
		}
		$row = is_object( $case ) ? $case : ( class_exists( 'FW_CRM_Cases' ) ? FW_CRM_Cases::find( (int) $case ) : null );
		return $row && ( ! (int) $row->owner_id || (int) $row->owner_id === get_current_user_id() );
	}

	/**
	 * May the current person change this task? Their own tasks, tasks on records
	 * they own, or anything for someone who sees everything.
	 *
	 * @param object|int $task
	 *
	 * @return bool
	 */
	public static function can_edit_task( $task ) {
		if ( self::sees_all() ) {
			return true;
		}
		$row = is_object( $task ) ? $task : FW_CRM_Tasks::find( (int) $task );
		if ( ! $row ) {
			return false;
		}
		if ( (int) $row->owner_id === get_current_user_id() || (int) $row->created_by === get_current_user_id() ) {
			return true;
		}
		switch ( $row->object_type ) {
			case 'contact':
				return self::can_edit_contact( (int) $row->object_id );
			case 'company':
				return self::can_edit_company( (int) $row->object_id );
			case 'deal':
				return self::can_edit_deal( (int) $row->object_id );
		}
		return false;
	}

	/**
	 * May the current person link a contact to this WordPress user? Linking gives
	 * the contact's CRM data a login, so it needs the right to edit that user.
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public static function can_link_user( $user_id ) {
		return self::can_manage() && current_user_can( 'edit_user', (int) $user_id );
	}
}
