<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Tasks: calls, meetings, emails and to-dos with a due time and an owner, on a
 * contact, a company or a deal (or on nothing, as a personal to-do).
 *
 * Calls and meetings carry a duration (minutes) and a meeting a location and its
 * attendees (contacts, linked as relations task -> contact `attendee`). A call that
 * already happened is logged with log_call() (or create() with `done`).
 *
 * Completing a task lands on its record's timeline (and on each attendee's). An hourly job emails each
 * owner one digest of their tasks that are due within the hour or overdue and not
 * yet reminded about (filter unysonplus_crm_task_reminders, false = off).
 */
class FW_CRM_Tasks {

	const CRON_HOOK = 'fw_crm_task_reminders';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_reminders' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'tasks' );
	}

	/**
	 * @return array type => { label, icon }
	 */
	public static function types() {
		/** Filters the task types (type => { label, icon }). */
		return (array) apply_filters( 'unysonplus_crm_task_types', array(
			'todo'    => array( 'label' => __( 'To-do', 'fw' ), 'icon' => 'dashicons-yes-alt' ),
			'call'    => array( 'label' => __( 'Call', 'fw' ), 'icon' => 'dashicons-phone' ),
			'meeting' => array( 'label' => __( 'Meeting', 'fw' ), 'icon' => 'dashicons-groups' ),
			'email'   => array( 'label' => __( 'Email', 'fw' ), 'icon' => 'dashicons-email' ),
		) );
	}

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args owner, object_type, object_id, status (open|done|overdue|today|upcoming|''),
	 *                    per_page, paged, order
	 *
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		$order = 'done' === ( $args['status'] ?? '' ) ? 'done_at DESC' : 'due_at IS NULL, due_at ASC, id ASC';
		$sql   = "SELECT * FROM {$table} WHERE " . self::where( $args ) . " ORDER BY {$order}";
		if ( ! empty( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * (int) $args['per_page'] );
		}
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . self::where( $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args
	 *
	 * @return string
	 */
	private static function where( array $args ) {
		global $wpdb;

		$w = array( '1=1' );
		if ( isset( $args['owner'] ) && '' !== $args['owner'] && null !== $args['owner'] ) {
			$w[] = $wpdb->prepare( 'owner_id = %d', (int) $args['owner'] );
		}
		if ( ! empty( $args['object_type'] ) ) {
			$w[] = $wpdb->prepare( 'object_type = %s', sanitize_key( (string) $args['object_type'] ) );
		}
		if ( ! empty( $args['object_id'] ) ) {
			$w[] = $wpdb->prepare( 'object_id = %d', (int) $args['object_id'] );
		}
		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );
		switch ( (string) ( $args['status'] ?? '' ) ) {
			case 'open':
				$w[] = 'done_at IS NULL';
				break;
			case 'done':
				$w[] = 'done_at IS NOT NULL';
				break;
			case 'overdue':
				$w[] = $wpdb->prepare( 'done_at IS NULL AND due_at IS NOT NULL AND due_at < %s', $now );
				break;
			case 'today':
				$w[] = $wpdb->prepare( 'done_at IS NULL AND due_at BETWEEN %s AND %s', $today . ' 00:00:00', $today . ' 23:59:59' );
				break;
			case 'upcoming':
				$w[] = $wpdb->prepare( 'done_at IS NULL AND ( due_at IS NULL OR due_at > %s )', $today . ' 23:59:59' );
				break;
		}
		return implode( ' AND ', $w );
	}

	/**
	 * Create a task.
	 *
	 * @param array $data title (required), type, object_type, object_id, owner_id, due_at (Y-m-d H:i or Y-m-d), notes,
	 *                    duration (minutes), location, attendee_ids (contact ids), done + outcome (log it as already done)
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::sanitize( $data );
		if ( empty( $row['title'] ) ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Say what the task is.', 'fw' ) );
		}
		$row = array_merge( array( 'type' => 'todo', 'owner_id' => get_current_user_id() ), $row, array(
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
		) );
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the task.', 'fw' ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( isset( $data['attendee_ids'] ) ) {
			self::set_attendees( $id, (array) $data['attendee_ids'] );
		}

		/** Fires after a task is created. */
		do_action( 'unysonplus_crm_task_created', self::find( $id ) );

		if ( ! empty( $data['done'] ) ) {
			self::complete( $id, (string) ( $data['outcome'] ?? '' ) );
		}

		return $id;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Task not found.', 'fw' ) );
		}
		$row = self::sanitize( $data );
		if ( isset( $row['title'] ) && '' === $row['title'] ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Say what the task is.', 'fw' ) );
		}
		if ( isset( $row['due_at'] ) && $row['due_at'] !== $before->due_at ) {
			$row['reminder_sent_at'] = null; // A new due time earns a new reminder.
		}
		if ( $row ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		}
		if ( isset( $data['attendee_ids'] ) ) {
			self::set_attendees( $id, (array) $data['attendee_ids'] );
		}
		/** Fires after a task changes. */
		do_action( 'unysonplus_crm_task_updated', self::find( $id ), $before );
		return true;
	}

	/**
	 * Mark done; the record it belongs to gets a timeline entry.
	 *
	 * @param int      $id
	 * @param string   $outcome  Optional note on how it went.
	 * @param int|null $duration Minutes it took (calls and meetings); null keeps the planned one.
	 *
	 * @return true|WP_Error
	 */
	public static function complete( $id, $outcome = '', $duration = null ) {
		global $wpdb;

		$task = self::find( $id );
		if ( ! $task ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Task not found.', 'fw' ) );
		}
		if ( $task->done_at ) {
			return true;
		}
		$set = array( 'done_at' => current_time( 'mysql' ) );
		if ( null !== $duration && '' !== $duration ) {
			$set['duration'] = max( 0, min( 1440, (int) $duration ) );
		}
		$wpdb->update( self::table(), $set, array( 'id' => (int) $id ) );
		$task  = self::find( $id );
		$types = self::types();
		$title = sprintf(
			/* translators: 1: task type, 2: task title */
			__( '%1$s done: %2$s', 'fw' ),
			$types[ $task->type ]['label'] ?? __( 'Task', 'fw' ),
			$task->title
		) . ( (int) $task->duration ? ' (' . self::duration_label( (int) $task->duration ) . ')' : '' );
		$entry = array(
			'body' => sanitize_textarea_field( (string) $outcome ),
			'meta' => array( 'task_id' => (int) $id, 'type' => $task->type, 'duration' => (int) $task->duration ),
		);
		if ( $task->object_type && $task->object_id ) {
			FW_CRM_Activity::log( $task->object_type, (int) $task->object_id, 'task', $title, $entry );
		}
		// Each attendee's timeline shows the meeting or call too.
		foreach ( self::attendees( (int) $id ) as $cid ) {
			if ( 'contact' !== $task->object_type || (int) $task->object_id !== $cid ) {
				FW_CRM_Activity::log( 'contact', $cid, 'task', $title, $entry );
			}
		}
		/** Fires after a task is completed. */
		do_action( 'unysonplus_crm_task_completed', self::find( $id ), (string) $outcome );
		return true;
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function reopen( $id ) {
		global $wpdb;

		return false !== $wpdb->update( self::table(), array( 'done_at' => null ), array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		FW_CRM_Relations::delete_for( 'task', (int) $id );
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 *
	 * @return int
	 */
	public static function delete_for( $object_type, $object_id ) {
		global $wpdb;

		foreach ( self::query( array( 'object_type' => $object_type, 'object_id' => $object_id ) ) as $t ) {
			FW_CRM_Relations::delete_for( 'task', (int) $t->id );
		}
		return (int) $wpdb->delete( self::table(), array( 'object_type' => sanitize_key( (string) $object_type ), 'object_id' => (int) $object_id ) );
	}

	/**
	 * Log a call (or meeting) that already happened: a done task with its outcome.
	 *
	 * @param string $object_type contact|company|deal
	 * @param int    $object_id
	 * @param array  $data title, type (default call), outcome, duration, at (Y-m-d H:i, default now), attendee_ids
	 *
	 * @return int|WP_Error
	 */
	public static function log_call( $object_type, $object_id, array $data ) {
		$type = isset( $data['type'] ) && 'meeting' === $data['type'] ? 'meeting' : 'call';
		if ( '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
			$data['title'] = 'meeting' === $type ? __( 'Meeting', 'fw' ) : __( 'Call', 'fw' );
		}
		return self::create( array_merge( $data, array(
			'type'        => $type,
			'object_type' => $object_type,
			'object_id'   => (int) $object_id,
			'due_at'      => ! empty( $data['at'] ) ? $data['at'] : current_time( 'Y-m-d H:i' ),
			'done'        => true,
		) ) );
	}

	/**
	 * @param int $id
	 *
	 * @return int[] Contact ids attending a meeting or on a call.
	 */
	public static function attendees( $id ) {
		return FW_CRM_Relations::ids( 'task', (int) $id, 'contact', 'attendee' );
	}

	/**
	 * Replace a task's attendees.
	 *
	 * @param int   $id
	 * @param int[] $contact_ids
	 */
	public static function set_attendees( $id, array $contact_ids ) {
		$want = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ), function ( $cid ) {
			return $cid > 0 && FW_CRM_Subscribers::find( $cid );
		} ) ) );
		$have = self::attendees( $id );
		foreach ( array_diff( $have, $want ) as $cid ) {
			FW_CRM_Relations::unlink( 'task', (int) $id, 'contact', $cid, 'attendee' );
		}
		foreach ( array_diff( $want, $have ) as $cid ) {
			FW_CRM_Relations::link( 'task', (int) $id, 'contact', $cid, 'attendee' );
		}
	}

	/**
	 * Contacts who could attend a task on this record: the contact itself, a
	 * company's people, a deal's contacts.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 *
	 * @return int[]
	 */
	public static function candidate_attendees( $object_type, $object_id ) {
		switch ( $object_type ) {
			case 'contact':
				return array( (int) $object_id );
			case 'company':
				return array_map( 'intval', FW_CRM_Companies::contact_ids( (int) $object_id ) );
			case 'deal':
				$d = class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::get( (int) $object_id ) : null;
				return $d ? $d['contact_ids'] : array();
		}
		return array();
	}

	/**
	 * "45 min", "1 h", "1 h 30 min".
	 *
	 * @param int $minutes
	 *
	 * @return string
	 */
	public static function duration_label( $minutes ) {
		$h = intdiv( (int) $minutes, 60 );
		$m = (int) $minutes % 60;
		if ( ! $h ) {
			return sprintf( __( '%d min', 'fw' ), $m );
		}
		return $m ? sprintf( __( '%1$d h %2$d min', 'fw' ), $h, $m ) : sprintf( __( '%d h', 'fw' ), $h );
	}

	/**
	 * The record a task belongs to, as a label and an admin URL.
	 *
	 * @param object $task
	 *
	 * @return array { label, url }
	 */
	public static function target( $task ) {
		$base = class_exists( 'FW_CRM_Admin_Page' ) ? FW_CRM_Admin_Page::get_page_url() : admin_url( 'admin.php?page=fw-crm' );
		switch ( $task->object_type ) {
			case 'contact':
				$c = FW_CRM_Subscribers::find( (int) $task->object_id );
				return $c ? array( 'label' => trim( $c->first_name . ' ' . $c->last_name ) ?: (string) ( $c->email ?: $c->phone ), 'url' => add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base ) ) : array( 'label' => '', 'url' => '' );
			case 'company':
				$co = FW_CRM_Companies::find( (int) $task->object_id );
				return $co ? array( 'label' => (string) $co->name, 'url' => add_query_arg( array( 'tab' => 'companies', 'company' => (int) $co->id ), $base ) ) : array( 'label' => '', 'url' => '' );
			case 'deal':
				$d = class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::find( (int) $task->object_id ) : null;
				return $d ? array( 'label' => (string) $d->title, 'url' => add_query_arg( array( 'tab' => 'deals', 'deal' => (int) $d->id ), $base ) ) : array( 'label' => '', 'url' => '' );
		}
		return array( 'label' => '', 'url' => '' );
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	private static function sanitize( array $data ) {
		$row = array();
		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['type'] ) ) {
			$row['type'] = isset( self::types()[ $data['type'] ] ) ? (string) $data['type'] : 'todo';
		}
		if ( isset( $data['object_type'] ) ) {
			$row['object_type'] = in_array( $data['object_type'], array( 'contact', 'company', 'deal', '' ), true ) ? (string) $data['object_type'] : '';
		}
		if ( isset( $data['object_id'] ) ) {
			$row['object_id'] = (int) $data['object_id'];
		}
		if ( isset( $data['owner_id'] ) ) {
			$row['owner_id'] = (int) $data['owner_id'];
		}
		if ( array_key_exists( 'due_at', $data ) ) {
			$v = trim( str_replace( 'T', ' ', (string) $data['due_at'] ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$v .= ' 09:00:00';
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v ) ) {
				$v .= ':00';
			}
			$row['due_at'] = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ? $v : null;
		}
		if ( isset( $data['notes'] ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}
		if ( isset( $data['duration'] ) ) {
			$row['duration'] = max( 0, min( 1440, (int) $data['duration'] ) );
		}
		if ( isset( $data['location'] ) ) {
			$row['location'] = substr( sanitize_text_field( (string) $data['location'] ), 0, 190 );
		}
		return $row;
	}

	/**
	 * The hourly reminder: one email per owner listing what is due within the hour or
	 * overdue and not reminded yet.
	 *
	 * @return int Emails sent.
	 */
	public static function send_reminders() {
		global $wpdb;

		/** Filters whether the CRM emails task reminders (default true). */
		if ( ! apply_filters( 'unysonplus_crm_task_reminders', true ) ) {
			return 0;
		}
		$table = self::table();
		$soon  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE done_at IS NULL AND reminder_sent_at IS NULL AND owner_id > 0 AND due_at IS NOT NULL AND due_at <= %s ORDER BY owner_id, due_at", $soon ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$by    = array();
		foreach ( $rows as $t ) {
			$by[ (int) $t->owner_id ][] = $t;
		}
		$sent  = 0;
		$types = self::types();
		$url   = class_exists( 'FW_CRM_Admin_Page' ) ? FW_CRM_Admin_Page::get_page_url( 'tasks' ) : admin_url( 'admin.php?page=fw-crm&tab=tasks' );
		foreach ( $by as $owner => $tasks ) {
			$user = get_userdata( $owner );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				continue;
			}
			$lines = array();
			foreach ( $tasks as $t ) {
				$target  = self::target( $t );
				$lines[] = sprintf( '- %s: %s%s (%s)', $types[ $t->type ]['label'] ?? '', $t->title, '' !== $target['label'] ? ' — ' . $target['label'] : '', date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $t->due_at ) ) );
			}
			$subject = sprintf( _n( '[%1$s] %2$s task is due', '[%1$s] %2$s tasks are due', count( $tasks ), 'fw' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), number_format_i18n( count( $tasks ) ) );
			$body    = sprintf( __( "Hi %s,\n\nThese CRM tasks are due:\n\n%s\n\nOpen your tasks: %s\n", 'fw' ), $user->display_name, implode( "\n", $lines ), $url );
			if ( wp_mail( $user->user_email, $subject, $body ) ) {
				$sent++;
			}
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $tasks, 'id' ) ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET reminder_sent_at = %s WHERE id IN ({$ids})", current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $sent;
	}
}
