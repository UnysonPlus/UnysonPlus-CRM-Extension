<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Module switches. The CRM is a small core (contacts, notes and the timeline,
 * tags, lists and segments, tasks, files, import and export, custom fields, the
 * bin, roles and settings) plus modules that a site switches on when it needs
 * them. A module that is off loads no code, runs no background job and adds no
 * menu; its data stays where it is until the module is switched back on.
 *
 * Modules register with the `unysonplus_crm_modules` filter:
 *
 *     id => array(
 *         'label'         => string,
 *         'description'   => string,
 *         'icon'          => dashicon class,
 *         'group'         => sales|marketing|workspace|connect (or your own),
 *         'default'       => bool, on before anyone chooses,
 *         'requires'      => string[] other module ids that must be on,
 *         'requires_text' => string shown under the description ("Needs …"),
 *         'available'     => callable|bool, false greys the switch out (a plugin it needs is missing),
 *         'soon'          => bool, planned, shown but cannot be switched on,
 *     )
 */
class FW_CRM_Modules {

	const OPTION = 'fw_crm_modules';

	/** Set once the first-run question was answered (or skipped). */
	const SETUP_OPTION = 'fw_crm_setup';

	/** Set once the switches of a site that existed before modules were introduced were worked out. */
	const MIGRATED_OPTION = 'fw_crm_modules_v2';

	/**
	 * Module groups, in the order the Modules screen shows them.
	 *
	 * @return array id => label
	 */
	public static function groups() {
		$groups = array(
			'sales'     => __( 'Sales', 'fw' ),
			'marketing' => __( 'Marketing', 'fw' ),
			'workspace' => __( 'Team and records', 'fw' ),
			'connect'   => __( 'Connections', 'fw' ),
		);

		/** Filters the module groups on the Modules screen: id => label. */
		return (array) apply_filters( 'unysonplus_crm_module_groups', $groups );
	}

	/**
	 * @return array id => module (see the class comment)
	 */
	public static function all() {
		$modules = array(
			'companies'     => array(
				'label'       => __( 'Companies', 'fw' ),
				'description' => __( 'Organisations your contacts work for, with their own record, timeline and people. For selling to businesses.', 'fw' ),
				'icon'        => 'dashicons-building',
				'group'       => 'sales',
				'default'     => false,
			),
			'pipeline'      => array(
				'label'       => __( 'Sales pipeline', 'fw' ),
				'description' => __( 'Deals moving through stages on a board, with their value, products, close date and sales reports.', 'fw' ),
				'icon'        => 'dashicons-money-alt',
				'group'       => 'sales',
				'default'     => false,
			),
			'calendar'      => array(
				'label'       => __( 'Calendar', 'fw' ),
				'description' => __( 'Calls, meetings and tasks with a date on a month, week and day calendar.', 'fw' ),
				'icon'        => 'dashicons-calendar-alt',
				'group'       => 'sales',
				'default'     => false,
			),
			'conversations' => array(
				'label'       => __( 'Email conversations', 'fw' ),
				'description' => __( 'Write to a contact from their record and log their replies on the timeline, from a forwarding address or a mailbox.', 'fw' ),
				'icon'        => 'dashicons-format-chat',
				'group'       => 'sales',
				'default'     => false,
			),
			'email'         => array(
				'label'       => __( 'Email marketing', 'fw' ),
				'description' => __( 'Campaigns written in the email builder, sent to a list, tag or segment at a safe pace, with opens, clicks and bounces.', 'fw' ),
				'icon'        => 'dashicons-email-alt',
				'group'       => 'marketing',
				'default'     => false,
			),
			'automations'   => array(
				'label'       => __( 'Automations', 'fw' ),
				'description' => __( 'Flows that start from a form, a tag, a date or a deal stage and send emails, wait, branch and update records for you.', 'fw' ),
				'icon'        => 'dashicons-randomize',
				'group'       => 'marketing',
				'default'     => false,
			),
			'scoring'       => array(
				'label'       => __( 'Lead scoring and site activity', 'fw' ),
				'description' => __( 'Points for what contacts do, and the pages known contacts read, so the warmest leads come first.', 'fw' ),
				'icon'        => 'dashicons-star-filled',
				'group'       => 'marketing',
				'default'     => false,
			),
			'audit'         => array(
				'label'       => __( 'Audit log', 'fw' ),
				'description' => __( 'A record of who changed, exported, merged or deleted what, and when. Useful once more than one person works in the CRM.', 'fw' ),
				'icon'        => 'dashicons-shield',
				'group'       => 'workspace',
				'default'     => false,
			),
			'cases'         => array(
				'label'       => __( 'Support cases', 'fw' ),
				'description' => __( 'Questions and problems from customers, each with a status, a priority, an owner and the full conversation. Opened by hand, from a form or from an email.', 'fw' ),
				'icon'        => 'dashicons-sos',
				'group'       => 'workspace',
				'default'     => false,
			),
			'webhooks'      => array(
				'label'       => __( 'Webhooks', 'fw' ),
				'description' => __( 'Send CRM events (a new contact, a won deal, an unsubscribe) to another app the moment they happen, signed and retried.', 'fw' ),
				'icon'        => 'dashicons-rest-api',
				'group'       => 'connect',
				'default'     => false,
			),
		);

		/** Filters the CRM modules: id => { label, description, icon, group, default, requires, requires_text, available, soon }. */
		$modules = (array) apply_filters( 'unysonplus_crm_modules', $modules );
		foreach ( $modules as $id => $m ) {
			$modules[ $id ] = array_merge(
				array( 'label' => $id, 'description' => '', 'icon' => 'dashicons-admin-plugins', 'group' => 'connect', 'default' => false, 'requires' => array(), 'requires_text' => '', 'available' => true, 'soon' => false ),
				(array) $m
			);
		}
		return $modules;
	}

	/**
	 * Can a module be switched on right now (not planned, its plugin present)?
	 *
	 * @param string $id
	 *
	 * @return bool
	 */
	public static function available( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) || ! empty( $all[ $id ]['soon'] ) ) {
			return false;
		}
		$a = $all[ $id ]['available'];
		return is_callable( $a ) ? (bool) call_user_func( $a ) : (bool) $a;
	}

	/**
	 * Is a module on? Planned or unavailable modules are never on, nor is one whose
	 * required modules are off.
	 *
	 * @param string $id
	 *
	 * @return bool
	 */
	public static function enabled( $id ) {
		if ( ! self::available( $id ) ) {
			return false;
		}
		$all   = self::all();
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$on    = array_key_exists( $id, $saved ) ? (bool) $saved[ $id ] : ! empty( $all[ $id ]['default'] );
		if ( $on ) {
			foreach ( (array) $all[ $id ]['requires'] as $need ) {
				if ( $need !== $id && ! self::enabled( $need ) ) {
					return false;
				}
			}
		}
		return $on;
	}

	/**
	 * Turn a module on or off.
	 *
	 * @param string $id
	 * @param bool   $on
	 *
	 * @return true|WP_Error
	 */
	public static function set( $id, $on ) {
		if ( ! self::available( $id ) ) {
			return new WP_Error( 'fw_crm_module', __( 'That module is not available yet.', 'fw' ) );
		}
		$on = (bool) $on;
		if ( self::enabled( $id ) === $on ) {
			return true;
		}

		/**
		 * Filters whether a module may be turned off right now (return a WP_Error to refuse,
		 * e.g. while it is busy).
		 */
		if ( ! $on ) {
			$check = apply_filters( 'unysonplus_crm_module_can_disable', true, $id );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$saved        = get_option( self::OPTION, array() );
		$saved        = is_array( $saved ) ? $saved : array();
		$saved[ $id ] = $on;
		update_option( self::OPTION, $saved, true );

		/** Fires after a module is turned on or off. */
		do_action( 'unysonplus_crm_module_toggled', $id, $on );

		return true;
	}

	/**
	 * Save every available module from a list of the ones that should be on.
	 *
	 * @param string[] $on
	 *
	 * @return string[] error messages (empty when all saved)
	 */
	public static function save( array $on ) {
		$errors = array();
		foreach ( self::all() as $mid => $module ) {
			if ( self::available( $mid ) ) {
				$result = self::set( $mid, in_array( $mid, $on, true ) );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}
		}
		return $errors;
	}

	/**
	 * The "Needs …" line under a module: its own text, else the modules it needs.
	 *
	 * @param array $m
	 *
	 * @return string
	 */
	public static function requires_text( array $m ) {
		if ( '' !== (string) $m['requires_text'] ) {
			return (string) $m['requires_text'];
		}
		$all   = self::all();
		$names = array();
		foreach ( (array) $m['requires'] as $need ) {
			$names[] = isset( $all[ $need ] ) ? $all[ $need ]['label'] : $need;
		}
		return $names ? sprintf( __( 'Needs %s.', 'fw' ), implode( ', ', $names ) ) : '';
	}

	/**
	 * The starting points offered the first time a manager opens the CRM:
	 * id => { label, description, icon, modules[] }.
	 *
	 * @return array
	 */
	public static function uses() {
		$uses = array(
			'people'   => array(
				'label'       => __( 'Keep track of people', 'fw' ),
				'description' => __( 'Contacts, notes, tags, lists and tasks. Everything else stays out of the way.', 'fw' ),
				'icon'        => 'dashicons-groups',
				'modules'     => array(),
			),
			'sell'     => array(
				'label'       => __( 'Sell to customers', 'fw' ),
				'description' => __( 'Companies, a deals pipeline with sales reports, and a calendar for calls and meetings.', 'fw' ),
				'icon'        => 'dashicons-money-alt',
				'modules'     => array( 'companies', 'pipeline', 'calendar' ),
			),
			'email'    => array(
				'label'       => __( 'Send newsletters', 'fw' ),
				'description' => __( 'Email campaigns to your lists, with opens, clicks and unsubscribes.', 'fw' ),
				'icon'        => 'dashicons-email-alt',
				'modules'     => array( 'email' ),
			),
			'converse' => array(
				'label'       => __( 'Email people one to one', 'fw' ),
				'description' => __( 'Write from a contact\'s record and keep their replies on the timeline.', 'fw' ),
				'icon'        => 'dashicons-format-chat',
				'modules'     => array( 'conversations' ),
			),
		);

		/** Filters the first-run starting points: id => { label, description, icon, modules[] }. */
		return (array) apply_filters( 'unysonplus_crm_setup_uses', $uses );
	}

	/**
	 * Should Home ask the first-run question?
	 *
	 * @return bool
	 */
	public static function needs_setup() {
		return ! get_option( self::SETUP_OPTION ) && FW_CRM_Capabilities::can_manage();
	}

	/**
	 * Answer the first-run question: switch on the modules behind the chosen uses.
	 *
	 * @param string[] $uses ids from uses(); empty = keep it simple
	 *
	 * @return string[] error messages
	 */
	public static function setup( array $uses ) {
		$on = array();
		foreach ( self::uses() as $uid => $use ) {
			if ( in_array( $uid, $uses, true ) ) {
				$on = array_merge( $on, (array) $use['modules'] );
			}
		}
		update_option( self::SETUP_OPTION, array( 'uses' => array_values( $uses ), 'at' => current_time( 'mysql', true ) ), false );
		return self::save( array_values( array_unique( $on ) ) );
	}

	/**
	 * A site that already used the CRM before it was split into modules keeps what
	 * it uses: each module it has data for, or that was on before, stays on. A fresh
	 * site starts with the core only and is asked what it needs.
	 */
	public static function migrate() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}
		update_option( self::MIGRATED_OPTION, 1, true );

		global $wpdb;
		$installed = (string) get_option( 'fw_ext_crm_db_version', '' );
		if ( '' === $installed ) {
			return; // Fresh: the core only, and the first-run question on Home.
		}

		$count = function ( $table, $where = '1=1' ) use ( $wpdb ) {
			$name = FW_CRM_Installer::table( $table );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
				return 0;
			}
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$name} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		};

		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$infer = array(
			// These two were on unless someone switched them off.
			'pipeline'      => true,
			'email'         => true,
			'companies'     => $count( 'companies' ) > 0,
			'calendar'      => $count( 'tasks', 'due_at IS NOT NULL' ) > 0,
			'conversations' => $count( 'activity', "type = 'email_in' OR ( type = 'email' AND meta LIKE '%\"direct\":1%' )" ) > 0 || (bool) get_option( 'fw_crm_inbound' ),
			'audit'         => $count( 'audit' ) > 0,
		);
		foreach ( $infer as $id => $on ) {
			if ( ! array_key_exists( $id, $saved ) ) {
				$saved[ $id ] = (bool) $on;
			}
		}
		update_option( self::OPTION, $saved, true );
		if ( $count( 'subscribers' ) > 0 ) {
			update_option( self::SETUP_OPTION, array( 'uses' => array(), 'at' => current_time( 'mysql', true ), 'migrated' => 1 ), false );
		}
	}

	/**
	 * Email marketing refuses to switch off while a campaign is sending or scheduled,
	 * and clears its sending job when it does.
	 */
	public static function init() {
		self::migrate();
		add_filter( 'unysonplus_crm_module_can_disable', function ( $ok, $id ) {
			if ( 'email' !== $id || ! class_exists( 'FW_CRM_Campaigns' ) ) {
				return $ok;
			}
			$busy = array_merge( FW_CRM_Campaigns::all( array( 'status' => 'sending' ) ), FW_CRM_Campaigns::all( array( 'status' => 'scheduled' ) ) );
			if ( $busy ) {
				return new WP_Error( 'fw_crm_module_busy', __( 'A campaign is sending or scheduled. Pause or finish it before turning email marketing off.', 'fw' ) );
			}
			return $ok;
		}, 10, 2 );
		add_action( 'unysonplus_crm_module_toggled', function ( $id, $on ) {
			if ( 'email' === $id && ! $on ) {
				wp_clear_scheduled_hook( 'fw_crm_send_tick' );
			}
			if ( 'conversations' === $id && ! $on && ! self::enabled( 'email' ) ) {
				wp_clear_scheduled_hook( 'fw_crm_inbound_poll' );
			}
			if ( 'webhooks' === $id && ! $on ) {
				wp_clear_scheduled_hook( 'fw_crm_webhooks_tick' );
			}
		}, 10, 2 );
	}
}
