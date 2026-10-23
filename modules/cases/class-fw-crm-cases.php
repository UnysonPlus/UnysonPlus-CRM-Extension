<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Support cases: a question or problem from a contact, with a status (new, open,
 * waiting, solved), a priority, an owner and a due date.
 *
 * The conversation is NOT stored here: replies, incoming emails, notes and every status or
 * owner change go on the case's timeline (FW_CRM_Activity, object_type "case"), the same
 * timeline deals and contacts use. A case is referred to as "#<id>"; that number in an email
 * subject ("[Case #12]") is what threads a reply back to its case.
 */
class FW_CRM_Cases {

	/** Where the "open a case from email" choice and other case settings live. */
	const OPTION = 'fw_crm_cases';

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'cases' );
	}

	/**
	 * @return array status => { label, done (bool: the case is finished) }
	 */
	public static function statuses() {
		/** Filters the case statuses, in board order: status => { label, done }. */
		return (array) apply_filters( 'unysonplus_crm_case_statuses', array(
			'new'     => array( 'label' => __( 'New', 'fw' ), 'done' => false ),
			'open'    => array( 'label' => __( 'Open', 'fw' ), 'done' => false ),
			'waiting' => array( 'label' => __( 'Waiting on customer', 'fw' ), 'done' => false ),
			'solved'  => array( 'label' => __( 'Solved', 'fw' ), 'done' => true ),
		) );
	}

	/** @return array priority => label, lowest first */
	public static function priorities() {
		return array(
			'low'    => __( 'Low', 'fw' ),
			'normal' => __( 'Normal', 'fw' ),
			'high'   => __( 'High', 'fw' ),
			'urgent' => __( 'Urgent', 'fw' ),
		);
	}

	/** @return array { from_email: bool, default_owner: int } */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge( array( 'from_email' => false, 'default_owner' => 0 ), is_array( $s ) ? $s : array() );
	}

	/** @param array $changes */
	public static function save_settings( array $changes ) {
		$s = self::settings();
		if ( isset( $changes['from_email'] ) ) {
			$s['from_email'] = (bool) $changes['from_email'];
		}
		if ( isset( $changes['default_owner'] ) ) {
			$s['default_owner'] = max( 0, (int) $changes['default_owner'] );
		}
		update_option( self::OPTION, $s, false );
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
	 * @param array $args status (a status, 'active' = not solved, '' = all), priority, owner (user id; 0 = unassigned),
	 *                    contact, company, older_than (days since opened), search, per_page, paged, orderby (board|recent)
	 *
	 * @return object[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		$order = 'board' === ( $args['orderby'] ?? '' )
			? "position ASC, FIELD(priority,'urgent','high','normal','low'), id ASC"
			: "FIELD(priority,'urgent','high','normal','low'), COALESCE(last_activity_at, created_at) DESC";
		$sql   = "SELECT * FROM {$table} WHERE " . self::where( $args ) . " ORDER BY {$order}";
		if ( ! empty( $args['per_page'] ) ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * (int) $args['per_page'] );
		}
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args As query().
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
	 * @return array status => count
	 */
	public static function counts_by_status( array $args = array() ) {
		$out = array();
		foreach ( array_keys( self::statuses() ) as $status ) {
			$out[ $status ] = self::count( array_merge( $args, array( 'status' => $status ) ) );
		}
		return $out;
	}

	/**
	 * @param array $args
	 *
	 * @return string
	 */
	private static function where( array $args ) {
		global $wpdb;

		$w      = array( '1=1' );
		$status = (string) ( $args['status'] ?? '' );
		if ( 'active' === $status ) {
			$done = array_keys( array_filter( self::statuses(), function ( $s ) {
				return ! empty( $s['done'] );
			} ) );
			if ( $done ) {
				$w[] = "status NOT IN ('" . implode( "','", array_map( 'esc_sql', $done ) ) . "')";
			}
		} elseif ( '' !== $status ) {
			$w[] = $wpdb->prepare( 'status = %s', sanitize_key( $status ) );
		}
		if ( ! empty( $args['priority'] ) ) {
			$w[] = $wpdb->prepare( 'priority = %s', sanitize_key( (string) $args['priority'] ) );
		}
		if ( isset( $args['owner'] ) && '' !== $args['owner'] && null !== $args['owner'] ) {
			$w[] = $wpdb->prepare( 'owner_id = %d', (int) $args['owner'] );
		}
		if ( ! empty( $args['mine_or_unassigned'] ) ) {
			$w[] = $wpdb->prepare( '( owner_id = %d OR owner_id = 0 )', (int) $args['mine_or_unassigned'] );
		}
		if ( ! empty( $args['contact'] ) ) {
			$w[] = $wpdb->prepare( 'contact_id = %d', (int) $args['contact'] );
		}
		if ( ! empty( $args['company'] ) ) {
			$w[] = $wpdb->prepare( 'company_id = %d', (int) $args['company'] );
		}
		if ( ! empty( $args['older_than'] ) ) {
			$w[] = $wpdb->prepare( 'created_at < %s', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - (int) $args['older_than'] * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$s   = trim( (string) $args['search'] );
			$num = (int) ltrim( $s, '#' );
			$w[] = $num && ltrim( $s, '#' ) === (string) $num
				? $wpdb->prepare( 'id = %d', $num )
				: $wpdb->prepare( 'title LIKE %s', '%' . $wpdb->esc_like( $s ) . '%' );
		}
		return implode( ' AND ', $w );
	}

	/**
	 * Open a case.
	 *
	 * @param array $data title (required), contact_id, company_id, owner_id, priority, status, due_at, source
	 *                    (manual|form|email), body (the first message, put on the timeline)
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::sanitize( $data );
		if ( empty( $row['title'] ) ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Say what the case is about.', 'fw' ) );
		}
		$now   = current_time( 'mysql' );
		$owner = isset( $row['owner_id'] ) ? (int) $row['owner_id'] : (int) self::settings()['default_owner'];
		if ( ! isset( $row['company_id'] ) && ! empty( $row['contact_id'] ) ) {
			$c                 = FW_CRM_Subscribers::find( (int) $row['contact_id'] );
			$row['company_id'] = $c ? (int) $c->company_id : 0;
		}
		$row = array_merge( array( 'status' => 'new', 'priority' => 'normal', 'source' => 'manual' ), $row, array(
			'owner_id'          => $owner,
			'created_by'        => get_current_user_id(),
			'created_at'        => $now,
			'updated_at'        => $now,
			'status_changed_at' => $now,
			'last_activity_at'  => $now,
		) );
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the case.', 'fw' ) );
		}
		$id = (int) $wpdb->insert_id;

		$sources = array(
			'form'   => __( 'Case opened from a form', 'fw' ),
			'email'  => __( 'Case opened from an email', 'fw' ),
			'manual' => __( 'Case opened', 'fw' ),
		);
		$entry   = array( 'body' => (string) ( $data['body'] ?? '' ), 'meta' => array( 'case_id' => $id, 'source' => $row['source'] ) );
		if ( isset( $data['actor_id'] ) ) {
			$entry['actor_id'] = (int) $data['actor_id'];
		}
		FW_CRM_Activity::log( 'case', $id, 'created', $sources[ $row['source'] ] ?? $sources['manual'], $entry );
		if ( (int) $row['contact_id'] ) {
			FW_CRM_Activity::log( 'contact', (int) $row['contact_id'], 'case', sprintf( __( 'Case #%1$d opened: %2$s', 'fw' ), $id, $row['title'] ), array_merge( $entry, array( 'body' => '' ) ) );
		}

		/** Fires after a case is opened. */
		do_action( 'unysonplus_crm_case_created', self::find( $id ) );
		/** Automations: a case was opened. */
		do_action( 'unysonplus_crm_automation_event', 'crm.case_created', (int) $row['contact_id'], array( 'case_id' => $id, 'dedupe' => 'case:' . $id ) );

		return $id;
	}

	/**
	 * Change a case. A new status, owner or priority is noted on its timeline.
	 *
	 * @param int   $id
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Case not found.', 'fw' ) );
		}
		$row = self::sanitize( $data );
		if ( isset( $row['title'] ) && '' === $row['title'] ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Say what the case is about.', 'fw' ) );
		}
		if ( ! $row ) {
			return true;
		}
		$now               = current_time( 'mysql' );
		$row['updated_at'] = $now;
		$statuses          = self::statuses();
		if ( isset( $row['status'] ) && $row['status'] !== $before->status ) {
			$row['status_changed_at'] = $now;
			$row['solved_at']         = ! empty( $statuses[ $row['status'] ]['done'] ) ? $now : null;
		}
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );

		if ( isset( $row['status'] ) && $row['status'] !== $before->status ) {
			FW_CRM_Activity::log( 'case', (int) $id, 'stage', sprintf( __( 'Status: %1$s → %2$s', 'fw' ), $statuses[ $before->status ]['label'] ?? $before->status, $statuses[ $row['status'] ]['label'] ?? $row['status'] ), array( 'meta' => array( 'from' => $before->status, 'to' => $row['status'] ) ) );
			if ( ! empty( $statuses[ $row['status'] ]['done'] ) && (int) $before->contact_id ) {
				FW_CRM_Activity::log( 'contact', (int) $before->contact_id, 'case', sprintf( __( 'Case #%1$d solved: %2$s', 'fw' ), (int) $id, $before->title ), array( 'meta' => array( 'case_id' => (int) $id ) ) );
			}
			/** Fires after a case's status changes. */
			do_action( 'unysonplus_crm_case_status_changed', self::find( $id ), $before->status );
		}
		if ( isset( $row['owner_id'] ) && (int) $row['owner_id'] !== (int) $before->owner_id ) {
			$u = (int) $row['owner_id'] ? get_userdata( (int) $row['owner_id'] ) : null;
			FW_CRM_Activity::log( 'case', (int) $id, 'owner', $u ? sprintf( __( 'Assigned to %s', 'fw' ), $u->display_name ) : __( 'Unassigned', 'fw' ) );
		}
		if ( isset( $row['priority'] ) && $row['priority'] !== $before->priority ) {
			$p = self::priorities();
			FW_CRM_Activity::log( 'case', (int) $id, 'priority', sprintf( __( 'Priority: %s', 'fw' ), $p[ $row['priority'] ] ?? $row['priority'] ) );
		}

		/** Fires after a case changes. */
		do_action( 'unysonplus_crm_case_updated', self::find( $id ), $before );
		return true;
	}

	/**
	 * Board order within a status.
	 *
	 * @param int[] $ids In their new order.
	 */
	public static function reorder( array $ids ) {
		global $wpdb;

		foreach ( array_values( $ids ) as $i => $id ) {
			$wpdb->update( self::table(), array( 'position' => $i ), array( 'id' => (int) $id ) );
		}
	}

	/**
	 * Delete a case and its timeline.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$case = self::find( $id );
		if ( ! $case ) {
			return false;
		}
		FW_CRM_Activity::delete_for( 'case', (int) $id );
		if ( class_exists( 'FW_CRM_Tasks' ) ) {
			FW_CRM_Tasks::delete_for( 'case', (int) $id );
		}
		$ok = (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		/** Fires after a case is deleted. */
		do_action( 'unysonplus_crm_case_deleted', $case );
		return $ok;
	}

	/**
	 * A contact or company was deleted: their cases stay, unlinked.
	 *
	 * @param string $field contact_id|company_id
	 * @param int    $id
	 */
	public static function unlink( $field, $id ) {
		global $wpdb;

		if ( in_array( $field, array( 'contact_id', 'company_id' ), true ) ) {
			$wpdb->update( self::table(), array( $field => 0 ), array( $field => (int) $id ) );
		}
	}

	/**
	 * Email the case's contact. The subject carries "[Case #<id>]" so their answer finds its
	 * way back here, and the reply lands on the case's timeline as well as the contact's.
	 * Answering a new case opens it; asking the customer something sets it to waiting.
	 *
	 * @param int    $id
	 * @param string $body
	 * @param bool   $wait Set the case to "waiting on customer".
	 *
	 * @return true|WP_Error
	 */
	public static function reply( $id, $body, $wait = true ) {
		$case = self::find( $id );
		if ( ! $case ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Case not found.', 'fw' ) );
		}
		if ( ! class_exists( 'FW_CRM_Direct_Email' ) ) {
			return new WP_Error( 'fw_crm_no_conversations', __( 'Switch on the Email conversations module to answer by email.', 'fw' ) );
		}
		if ( ! (int) $case->contact_id ) {
			return new WP_Error( 'fw_crm_no_contact', __( 'Link the case to a contact first.', 'fw' ) );
		}
		$res = FW_CRM_Direct_Email::send( (int) $case->contact_id, array(
			'subject' => self::subject( $case ),
			'body'    => wpautop( esc_html( (string) $body ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		FW_CRM_Activity::log( 'case', (int) $id, 'email', __( 'Reply sent', 'fw' ), array( 'body' => sanitize_textarea_field( (string) $body ), 'meta' => array( 'direction' => 'out' ) ) );
		$status = $wait ? 'waiting' : ( 'new' === $case->status ? 'open' : '' );
		if ( '' !== $status && $status !== $case->status ) {
			self::update( $id, array( 'status' => $status ) );
		}
		return true;
	}

	/**
	 * @param object $case
	 *
	 * @return string "[Case #12] Title"
	 */
	public static function subject( $case ) {
		return sprintf( '[%1$s #%2$d] %3$s', __( 'Case', 'fw' ), (int) $case->id, $case->title );
	}

	/**
	 * The case number in an email subject ("Re: [Case #12] …"), or 0.
	 *
	 * @param string $subject
	 *
	 * @return int
	 */
	public static function number_in( $subject ) {
		return preg_match( '/\[[^\]#]*#(\d+)\]/', (string) $subject, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Days a case has been open (or took, once solved).
	 *
	 * @param object $case
	 *
	 * @return int
	 */
	public static function age_days( $case ) {
		$end = $case->solved_at ? strtotime( $case->solved_at ) : current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		return max( 0, (int) floor( ( $end - strtotime( $case->created_at ) ) / DAY_IN_SECONDS ) );
	}

	/**
	 * @param object $case
	 *
	 * @return bool Due date passed and not solved.
	 */
	public static function is_overdue( $case ) {
		$statuses = self::statuses();
		return $case->due_at && empty( $statuses[ $case->status ]['done'] ) && strtotime( $case->due_at ) < current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * @param array $data
	 *
	 * @return array Only the columns present in $data, cleaned.
	 */
	private static function sanitize( array $data ) {
		$row = array();
		if ( isset( $data['title'] ) ) {
			$row['title'] = substr( sanitize_text_field( (string) $data['title'] ), 0, 190 );
		}
		if ( isset( $data['status'] ) && array_key_exists( $data['status'], self::statuses() ) ) {
			$row['status'] = (string) $data['status'];
		}
		if ( isset( $data['priority'] ) && array_key_exists( $data['priority'], self::priorities() ) ) {
			$row['priority'] = (string) $data['priority'];
		}
		foreach ( array( 'owner_id', 'contact_id', 'company_id' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$row[ $k ] = max( 0, (int) $data[ $k ] );
			}
		}
		if ( isset( $data['source'] ) ) {
			$row['source'] = in_array( $data['source'], array( 'manual', 'form', 'email' ), true ) ? $data['source'] : 'manual';
		}
		if ( array_key_exists( 'due_at', $data ) ) {
			$t             = '' !== trim( (string) $data['due_at'] ) ? strtotime( (string) $data['due_at'] ) : false;
			$row['due_at'] = $t ? gmdate( 'Y-m-d H:i:s', $t ) : null;
		}
		return $row;
	}
}
