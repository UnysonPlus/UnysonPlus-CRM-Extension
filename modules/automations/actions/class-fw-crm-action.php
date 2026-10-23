<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM's own automation actions, and the "wait" timing.
 *
 * An action is { label, group, settings: key => { label, type, options?, required? }, callback }.
 * The callback gets ( $contact_id, $settings, $context ) and returns true, a WP_Error (a code
 * starting with "skip" logs it as skipped rather than failed), or { status?, message?, context? }
 * where `context` is merged into the run (a created deal's id, for the steps after it).
 * Callbacks run from the scheduler with no logged-in user. `$context['test']` is true in a test run.
 */
class FW_CRM_Action {

	/** @return array slug => action */
	public static function builtin() {
		$tags  = array();
		foreach ( FW_CRM_Lists::all( 'tag' ) as $t ) {
			$tags[ $t->slug ] = $t->title;
		}
		$lists = array();
		foreach ( FW_CRM_Lists::all( 'list' ) as $l ) {
			$lists[ $l->slug ] = $l->title;
		}
		$team = array( 'owner' => __( 'The contact\'s owner', 'fw' ) );
		foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) {
			$team[ (string) $u->ID ] = $u->display_name;
		}
		$email = __( 'Email', 'fw' );
		$cont  = __( 'Contact', 'fw' );
		$flow  = __( 'Flow', 'fw' );
		$team_g = __( 'Team', 'fw' );

		$a = array(
			'crm.send_email'   => array(
				'label'    => __( 'Send an email', 'fw' ),
				'group'    => $email,
				'settings' => array(
					'subject' => array( 'label' => __( 'Subject', 'fw' ), 'type' => 'text', 'required' => true ),
					'body'    => array( 'label' => __( 'Message', 'fw' ), 'type' => 'textarea', 'required' => true, 'desc' => __( 'Tags such as {{first_name}} and {{site_name}} work. Basic HTML is fine.', 'fw' ) ),
					'purpose' => array( 'label' => __( 'Kind of email', 'fw' ), 'type' => 'select', 'options' => array( 'marketing' => __( 'Marketing: only to subscribed contacts, with an unsubscribe link', 'fw' ), 'transactional' => __( 'Transactional: a reply, a reminder, a confirmation', 'fw' ) ) ),
				),
				'callback' => array( __CLASS__, 'send_email' ),
			),
			'crm.add_tag'      => array( 'label' => __( 'Add tags', 'fw' ), 'group' => $cont, 'settings' => array( 'tags' => array( 'label' => __( 'Tags (comma-separated)', 'fw' ), 'type' => 'text', 'required' => true, 'suggest' => array_keys( $tags ) ) ), 'callback' => array( __CLASS__, 'add_tag' ) ),
			'crm.remove_tag'   => array( 'label' => __( 'Remove tags', 'fw' ), 'group' => $cont, 'settings' => array( 'tags' => array( 'label' => __( 'Tags (comma-separated)', 'fw' ), 'type' => 'text', 'required' => true, 'suggest' => array_keys( $tags ) ) ), 'callback' => array( __CLASS__, 'remove_tag' ) ),
			'crm.add_list'     => array( 'label' => __( 'Add to a list', 'fw' ), 'group' => $cont, 'settings' => array( 'list' => array( 'label' => __( 'List', 'fw' ), 'type' => 'select', 'options' => $lists, 'required' => true ) ), 'callback' => array( __CLASS__, 'add_list' ) ),
			'crm.remove_list'  => array( 'label' => __( 'Remove from a list', 'fw' ), 'group' => $cont, 'settings' => array( 'list' => array( 'label' => __( 'List', 'fw' ), 'type' => 'select', 'options' => $lists, 'required' => true ) ), 'callback' => array( __CLASS__, 'remove_list' ) ),
			'crm.update_field' => array(
				'label'    => __( 'Update a field', 'fw' ),
				'group'    => $cont,
				'settings' => array(
					'field' => array( 'label' => __( 'Field', 'fw' ), 'type' => 'select', 'options' => self::writable_fields(), 'required' => true ),
					'value' => array( 'label' => __( 'New value', 'fw' ), 'type' => 'text' ),
				),
				'callback' => array( __CLASS__, 'update_field' ),
			),
			'crm.create_task'  => array(
				'label'    => __( 'Create a task', 'fw' ),
				'group'    => $team_g,
				'settings' => array(
					'title'    => array( 'label' => __( 'Task', 'fw' ), 'type' => 'text', 'required' => true ),
					'type'     => array( 'label' => __( 'Type', 'fw' ), 'type' => 'select', 'options' => array_map( function ( $t ) { return is_array( $t ) ? $t['label'] : $t; }, FW_CRM_Tasks::types() ) ),
					'due_days' => array( 'label' => __( 'Due in (days)', 'fw' ), 'type' => 'number' ),
					'owner'    => array( 'label' => __( 'For', 'fw' ), 'type' => 'select', 'options' => $team ),
				),
				'callback' => array( __CLASS__, 'create_task' ),
			),
			'crm.notify'       => array(
				'label'    => __( 'Notify the team', 'fw' ),
				'group'    => $team_g,
				'settings' => array(
					'to'      => array( 'label' => __( 'Who', 'fw' ), 'type' => 'select', 'options' => $team ),
					'subject' => array( 'label' => __( 'Subject', 'fw' ), 'type' => 'text', 'required' => true ),
					'message' => array( 'label' => __( 'Message', 'fw' ), 'type' => 'textarea', 'desc' => __( 'A link to the contact is added at the end.', 'fw' ) ),
				),
				'callback' => array( __CLASS__, 'notify' ),
			),
			'crm.webhook'      => array(
				'label'    => __( 'Call a webhook', 'fw' ),
				'group'    => $flow,
				'settings' => array( 'url' => array( 'label' => __( 'Address (https://…)', 'fw' ), 'type' => 'text', 'required' => true, 'desc' => __( 'The contact and the automation are POSTed as JSON.', 'fw' ) ) ),
				'callback' => array( __CLASS__, 'webhook' ),
			),
			'crm.start_automation' => array(
				'label'    => __( 'Start another automation', 'fw' ),
				'group'    => $flow,
				'settings' => array( 'automation' => array( 'label' => __( 'Automation', 'fw' ), 'type' => 'select', 'options' => self::automation_options(), 'required' => true ) ),
				'callback' => array( __CLASS__, 'start_automation' ),
			),
			'crm.end_automation'   => array(
				'label'    => __( 'End other automations', 'fw' ),
				'group'    => $flow,
				'settings' => array( 'automation' => array( 'label' => __( 'Which', 'fw' ), 'type' => 'select', 'options' => array( '' => __( 'Every other automation the contact is in', 'fw' ) ) + self::automation_options() ) ),
				'callback' => array( __CLASS__, 'end_automation' ),
			),
			'crm.assign_owner'     => array(
				'label'    => __( 'Assign an owner (round robin)', 'fw' ),
				'group'    => $team_g,
				'settings' => array(
					'users' => array( 'label' => __( 'Take turns between', 'fw' ), 'type' => 'multiselect', 'options' => array_diff_key( $team, array( 'owner' => '' ) ), 'required' => true ),
					'only_unowned' => array( 'label' => __( 'Only when the contact has no owner yet', 'fw' ), 'type' => 'checkbox' ),
				),
				'callback' => array( 'FW_CRM_Action_Assign_Owner', 'run' ),
			),
		);
		if ( class_exists( 'FW_CRM_Deals' ) ) {
			$stages = array();
			$pipes  = array( '' => __( 'The default pipeline', 'fw' ) );
			foreach ( FW_CRM_Pipelines::all() as $p ) {
				$pipes[ (string) $p->id ] = $p->name;
				foreach ( FW_CRM_Pipelines::stages( (int) $p->id ) as $s ) {
					$stages[ (string) $s->id ] = $p->name . ': ' . $s->name;
				}
			}
			$deals = __( 'Deals', 'fw' );
			$a['crm.create_deal'] = array(
				'label'    => __( 'Create a deal', 'fw' ),
				'group'    => $deals,
				'settings' => array(
					'title'    => array( 'label' => __( 'Deal name', 'fw' ), 'type' => 'text', 'desc' => __( 'Empty: the contact\'s name.', 'fw' ) ),
					'value'    => array( 'label' => __( 'Value', 'fw' ), 'type' => 'number' ),
					'pipeline' => array( 'label' => __( 'Pipeline', 'fw' ), 'type' => 'select', 'options' => $pipes ),
				),
				'callback' => array( __CLASS__, 'create_deal' ),
			);
			$a['crm.move_deal'] = array(
				'label'    => __( 'Move the deal', 'fw' ),
				'group'    => $deals,
				'settings' => array( 'stage' => array( 'label' => __( 'To stage', 'fw' ), 'type' => 'select', 'options' => $stages, 'required' => true ) ),
				'callback' => array( __CLASS__, 'move_deal' ),
			);
		}
		return $a;
	}

	/* ---------------------------------------------------------------------- *
	 * Callbacks
	 * ---------------------------------------------------------------------- */

	public static function send_email( $contact_id, array $s, array $ctx ) {
		$contact = FW_CRM_Subscribers::find( (int) $contact_id );
		$purpose = 'transactional' === ( $s['purpose'] ?? 'marketing' ) ? 'transactional' : 'marketing';
		if ( ! $contact || ! is_email( (string) $contact->email ) ) {
			return new WP_Error( 'skip_no_email', __( 'The contact has no email address.', 'fw' ) );
		}
		if ( 'marketing' === $purpose && 'subscribed' !== $contact->status && empty( $ctx['test'] ) ) {
			return new WP_Error( 'skip_not_subscribed', __( 'Not sent: the contact is not subscribed to marketing email.', 'fw' ) );
		}
		$message = array( 'subject' => (string) ( $s['subject'] ?? '' ), 'body' => (string) ( $s['body'] ?? '' ), 'purpose' => $purpose );
		if ( '' === trim( $message['subject'] ) || '' === trim( wp_strip_all_tags( $message['body'] ) ) ) {
			return new WP_Error( 'fw_crm_empty_email', __( 'The email has no subject or message.', 'fw' ) );
		}
		if ( 'marketing' === $purpose && false === strpos( $message['body'], '{{unsubscribe_url}}' ) && class_exists( 'FW_CRM_Sender' ) ) {
			$message['body'] = FW_CRM_Sender::with_unsubscribe( $message['body'] );
		}
		if ( ! empty( $ctx['test'] ) ) {
			// A test run never mails the contact: it goes to the person testing, filled in with the contact's details.
			$me = wp_get_current_user();
			if ( ! $me || ! is_email( $me->user_email ) ) {
				return new WP_Error( 'skip_test', __( 'Test run: no email sent (nobody to send it to).', 'fw' ) );
			}
			$copy             = clone $contact;
			$copy->email      = $me->user_email;
			$message['subject'] = '[' . __( 'TEST', 'fw' ) . '] ' . $message['subject'];
			$res = FW_CRM_Channels::send_email( $copy, $message );
			return is_wp_error( $res ) ? $res : array( 'message' => sprintf( __( 'test copy sent to %s', 'fw' ), $me->user_email ) );
		}
		$res = FW_CRM_Channels::send( 'email', (int) $contact_id, $message );
		return is_wp_error( $res ) ? $res : array( 'message' => FW_CRM_Mail::replace( $message['subject'], $contact ) );
	}

	public static function add_tag( $contact_id, array $s ) {
		$n = FW_CRM_Tags::add( (int) $contact_id, (string) ( $s['tags'] ?? '' ), 'automation' );
		return array( 'message' => (string) ( $s['tags'] ?? '' ) . ( $n ? '' : ' ' . __( '(already had them)', 'fw' ) ) );
	}

	public static function remove_tag( $contact_id, array $s ) {
		FW_CRM_Tags::remove( (int) $contact_id, (string) ( $s['tags'] ?? '' ), 'automation' );
		return array( 'message' => (string) ( $s['tags'] ?? '' ) );
	}

	public static function add_list( $contact_id, array $s ) {
		$list = FW_CRM_Lists::find_by_slug( (string) ( $s['list'] ?? '' ), 'list' );
		if ( ! $list ) {
			return new WP_Error( 'fw_crm_no_list', __( 'That list no longer exists.', 'fw' ) );
		}
		FW_CRM_Subscribers::add_to_list( (int) $contact_id, (int) $list->id, 'list', 'automation' );
		return array( 'message' => $list->title );
	}

	public static function remove_list( $contact_id, array $s ) {
		$list = FW_CRM_Lists::find_by_slug( (string) ( $s['list'] ?? '' ), 'list' );
		if ( ! $list ) {
			return new WP_Error( 'fw_crm_no_list', __( 'That list no longer exists.', 'fw' ) );
		}
		FW_CRM_Subscribers::remove_from_list( (int) $contact_id, (int) $list->id, 'list', 'automation' );
		return array( 'message' => $list->title );
	}

	public static function update_field( $contact_id, array $s ) {
		$field = (string) ( $s['field'] ?? '' );
		$value = (string) ( $s['value'] ?? '' );
		$c     = FW_CRM_Subscribers::find( (int) $contact_id );
		$value = $c ? FW_CRM_Mail::replace( $value, $c ) : $value;
		if ( 0 === strpos( $field, 'cf:' ) ) {
			$res = FW_CRM_Fields::set_values( 'contact', (int) $contact_id, array( substr( $field, 3 ) => $value ) );
		} elseif ( isset( self::writable_fields()[ $field ] ) ) {
			$res = FW_CRM_Contacts::update( (int) $contact_id, array( $field => $value ) );
		} else {
			return new WP_Error( 'fw_crm_field', __( 'That field cannot be changed by an automation.', 'fw' ) );
		}
		return is_wp_error( $res ) ? $res : array( 'message' => $field . ' = ' . $value );
	}

	public static function create_task( $contact_id, array $s, array $ctx ) {
		$c     = FW_CRM_Subscribers::find( (int) $contact_id );
		$owner = 'owner' === ( $s['owner'] ?? 'owner' ) ? ( $c ? (int) $c->owner_id : 0 ) : (int) $s['owner'];
		$days  = max( 0, (int) ( $s['due_days'] ?? 0 ) );
		$id    = FW_CRM_Tasks::create( array(
			'title'       => $c ? FW_CRM_Mail::replace( (string) ( $s['title'] ?? '' ), $c ) : (string) ( $s['title'] ?? '' ),
			'type'        => isset( FW_CRM_Tasks::types()[ $s['type'] ?? '' ] ) ? $s['type'] : 'todo',
			'object_type' => ! empty( $ctx['deal_id'] ) && class_exists( 'FW_CRM_Deals' ) ? 'deal' : 'contact',
			'object_id'   => ! empty( $ctx['deal_id'] ) && class_exists( 'FW_CRM_Deals' ) ? (int) $ctx['deal_id'] : (int) $contact_id,
			'owner_id'    => $owner,
			'due_at'      => gmdate( 'Y-m-d 09:00', current_time( 'timestamp' ) + $days * DAY_IN_SECONDS ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		) );
		return is_wp_error( $id ) ? $id : array( 'message' => (string) ( $s['title'] ?? '' ) );
	}

	public static function notify( $contact_id, array $s, array $ctx ) {
		$c  = FW_CRM_Subscribers::find( (int) $contact_id );
		$to = (string) ( $s['to'] ?? 'owner' );
		$u  = 'owner' === $to ? ( $c && (int) $c->owner_id ? get_userdata( (int) $c->owner_id ) : null ) : get_userdata( (int) $to );
		$address = $u ? $u->user_email : get_option( 'admin_email' );
		if ( ! empty( $ctx['test'] ) ) {
			$me      = wp_get_current_user();
			$address = $me && is_email( $me->user_email ) ? $me->user_email : $address;
		}
		$link    = class_exists( 'FW_CRM_Admin_Page' ) ? add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $contact_id ), FW_CRM_Admin_Page::get_page_url() ) : admin_url( 'admin.php?page=fw-crm&tab=subscribers&subscriber=' . (int) $contact_id );
		$subject = $c ? FW_CRM_Mail::replace( (string) ( $s['subject'] ?? '' ), $c ) : (string) ( $s['subject'] ?? '' );
		$body    = ( $c ? FW_CRM_Mail::replace( (string) ( $s['message'] ?? '' ), $c ) : (string) ( $s['message'] ?? '' ) ) . "\n\n" . $link;
		$ok      = wp_mail( $address, $subject, $body );
		return $ok ? array( 'message' => $address ) : new WP_Error( 'fw_crm_send_failed', __( 'The notification could not be sent.', 'fw' ) );
	}

	public static function webhook( $contact_id, array $s, array $ctx ) {
		$url = esc_url_raw( (string) ( $s['url'] ?? '' ) );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'fw_crm_url', __( 'The webhook address is not a valid public URL.', 'fw' ) );
		}
		$a   = FW_CRM_Automations::find( (int) ( $ctx['automation_id'] ?? 0 ) );
		$res = wp_safe_remote_post( $url, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json', 'User-Agent' => 'Unyson+ CRM' ),
			'body'    => wp_json_encode( array(
				'event'      => 'automation.step',
				'automation' => $a ? array( 'id' => (int) $a->id, 'title' => $a->title ) : null,
				'contact'    => FW_CRM_Contacts::get( (int) $contact_id ),
				'context'    => FW_CRM_Automations::plain( $ctx ),
				'test'       => ! empty( $ctx['test'] ),
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code >= 200 && $code < 300 ? array( 'message' => 'HTTP ' . $code ) : new WP_Error( 'fw_crm_http', sprintf( __( 'The address answered HTTP %d.', 'fw' ), $code ) );
	}

	public static function start_automation( $contact_id, array $s, array $ctx ) {
		$id = (int) ( $s['automation'] ?? 0 );
		if ( $id === (int) ( $ctx['automation_id'] ?? 0 ) ) {
			return new WP_Error( 'fw_crm_self', __( 'An automation cannot start itself.', 'fw' ) );
		}
		$a = FW_CRM_Automations::find( $id );
		if ( ! $a || 'active' !== $a->status ) {
			return new WP_Error( 'skip_inactive', __( 'That automation is not switched on.', 'fw' ) );
		}
		$res = FW_CRM_Automations::start( $a, (int) $contact_id, array( 'started_by' => (int) ( $ctx['automation_id'] ?? 0 ) ) );
		return is_wp_error( $res ) ? new WP_Error( 'skip_' . $res->get_error_code(), $res->get_error_message() ) : array( 'message' => $a->title );
	}

	public static function end_automation( $contact_id, array $s, array $ctx ) {
		$n = FW_CRM_Automations::exit_runs( (int) $contact_id, (int) ( $s['automation'] ?? 0 ), (int) ( $ctx['run_id'] ?? 0 ), __( 'Ended by another automation', 'fw' ) );
		return array( 'message' => sprintf( _n( '%d run ended', '%d runs ended', $n, 'fw' ), $n ) );
	}

	public static function create_deal( $contact_id, array $s ) {
		$c   = FW_CRM_Subscribers::find( (int) $contact_id );
		$who = $c ? trim( $c->first_name . ' ' . $c->last_name ) : '';
		$title = '' !== trim( (string) ( $s['title'] ?? '' ) ) && $c ? FW_CRM_Mail::replace( (string) $s['title'], $c ) : ( '' !== $who ? $who : ( $c ? (string) $c->email : '' ) );
		$id  = FW_CRM_Deals::create( array(
			'title'       => $title,
			'value'       => (float) ( $s['value'] ?? 0 ),
			'pipeline_id' => (int) ( $s['pipeline'] ?? 0 ),
			'contact_id'  => (int) $contact_id,
			'company_id'  => $c ? (int) $c->company_id : 0,
			'owner_id'    => $c ? (int) $c->owner_id : 0,
		) );
		return is_wp_error( $id ) ? $id : array( 'message' => $title, 'context' => array( 'deal_id' => (int) $id ) );
	}

	public static function move_deal( $contact_id, array $s, array $ctx ) {
		global $wpdb;

		$deal_id = (int) ( $ctx['deal_id'] ?? 0 );
		if ( ! $deal_id ) {
			$t       = FW_CRM_Installer::table( 'deals' );
			$deal_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE contact_id = %d AND status = 'open' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", (int) $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! $deal_id ) {
			return new WP_Error( 'skip_no_deal', __( 'The contact has no open deal.', 'fw' ) );
		}
		$res = FW_CRM_Deals::move( $deal_id, (int) ( $s['stage'] ?? 0 ) );
		return is_wp_error( $res ) ? $res : array( 'message' => '#' . $deal_id, 'context' => array( 'deal_id' => $deal_id ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Waiting
	 * ---------------------------------------------------------------------- */

	/**
	 * When a wait step ends.
	 *
	 * Settings: mode = delay ( amount, unit: minutes|hours|days|weeks )
	 *                | time  ( weekday: ''|1-7, time: HH:MM ): the next such moment
	 *                | date  ( field: cf:<key>, days, when: before|on|after, time ): relative to a date field
	 *
	 * @param array       $s
	 * @param object|null $run
	 * @param int|null    $now Site-local timestamp.
	 *
	 * @return string MySQL datetime (site time).
	 */
	public static function wait_until( array $s, $run = null, $now = null ) {
		$now  = null === $now ? current_time( 'timestamp' ) : (int) $now; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$mode = (string) ( $s['mode'] ?? 'delay' );
		if ( 'time' === $mode ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $s['time'] ?? '' ) ) ? $s['time'] : '09:00' ) );
			$wd = (int) ( $s['weekday'] ?? 0 );
			for ( $d = 0; $d <= 7; $d++ ) {
				$day = $now + $d * DAY_IN_SECONDS;
				$at  = gmmktime( $h, $m, 0, (int) gmdate( 'n', $day ), (int) gmdate( 'j', $day ), (int) gmdate( 'Y', $day ) );
				if ( $at > $now && ( ! $wd || (int) gmdate( 'N', $at ) === $wd ) ) {
					return gmdate( 'Y-m-d H:i:s', $at );
				}
			}
		}
		if ( 'date' === $mode && $run ) {
			$vals = FW_CRM_Fields::get_values( 'contact', (int) $run->contact_id );
			$date = (string) ( $vals[ preg_replace( '/^cf:/', '', (string) ( $s['field'] ?? '' ) ) ] ?? '' );
			$t    = '' !== $date ? strtotime( substr( $date, 0, 10 ) . ' ' . ( preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $s['time'] ?? '' ) ) ? $s['time'] : '09:00' ) ) : false;
			if ( $t ) {
				$days = max( 0, (int) ( $s['days'] ?? 0 ) );
				$t   += ( 'before' === ( $s['when'] ?? 'on' ) ? -$days : ( 'after' === ( $s['when'] ?? 'on' ) ? $days : 0 ) ) * DAY_IN_SECONDS;
				return gmdate( 'Y-m-d H:i:s', max( $t, $now ) );
			}
			return gmdate( 'Y-m-d H:i:s', $now ); // No date: carry straight on.
		}
		$units  = array( 'minutes' => MINUTE_IN_SECONDS, 'hours' => HOUR_IN_SECONDS, 'days' => DAY_IN_SECONDS, 'weeks' => WEEK_IN_SECONDS );
		$amount = max( 0, (int) ( $s['amount'] ?? 1 ) );
		return gmdate( 'Y-m-d H:i:s', $now + $amount * ( $units[ $s['unit'] ?? 'days' ] ?? DAY_IN_SECONDS ) );
	}

	/* ---------------------------------------------------------------------- */

	/** @return array Contact fields an automation may set. */
	public static function writable_fields() {
		$o = array(
			'lifecycle_stage' => __( 'Lifecycle stage', 'fw' ),
			'first_name'      => __( 'First name', 'fw' ),
			'last_name'       => __( 'Last name', 'fw' ),
			'phone'           => __( 'Phone', 'fw' ),
			'job_title'       => __( 'Job title', 'fw' ),
			'city'            => __( 'City', 'fw' ),
			'country'         => __( 'Country', 'fw' ),
			'score'           => __( 'Score', 'fw' ),
		);
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $d ) {
			if ( 'autonumber' !== ( $d['type'] ?? '' ) ) {
				$o[ 'cf:' . $d['key'] ] = (string) $d['label'];
			}
		}
		return $o;
	}

	/** @return array id => title */
	private static function automation_options() {
		$o = array();
		foreach ( FW_CRM_Automations::all() as $a ) {
			$o[ (string) $a->id ] = $a->title;
		}
		return $o;
	}
}
