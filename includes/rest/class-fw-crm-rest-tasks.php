<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST routes for tasks and notes, on `fw-crm/v1`. Same rules as the admin
 * screens: a sales rep sees and changes their own tasks and tasks on records
 * they own; a note can be changed by its author or a manager.
 *
 *   /tasks               GET (owner, object_type, object_id, status open|done|overdue|today|upcoming) · POST
 *                        (duration, location, attendee_ids; `done: true` + `outcome` logs a call that happened)
 *   /tasks/{id}          GET · PATCH/PUT · DELETE
 *   /tasks/{id}/complete POST { outcome } (logs to the record it belongs to)
 *   /tasks/{id}/reopen   POST
 *   /task-types          GET
 *   /notes/{id}          PATCH/PUT { note } · DELETE
 *   /calendar            GET { from, to (Y-m-d, up to 62 days), owner (managers: id or 'all') } → date => events
 */
class FW_CRM_REST_Tasks extends FW_CRM_REST {

	/**
	 * @internal
	 */
	public function _register_routes() {
		$auth = array( $this, '_authorize' );
		$id   = '(?P<id>\d+)';

		register_rest_route( self::NAMESPACE_, '/tasks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_list_tasks' ),
				'permission_callback' => $auth,
				'args'                => array(
					'per_page'    => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
					'page'        => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'owner'       => array( 'type' => 'integer' ),
					'object_type' => array( 'type' => 'string', 'enum' => array( 'contact', 'company', 'deal' ) ),
					'object_id'   => array( 'type' => 'integer' ),
					'status'      => array( 'type' => 'string', 'enum' => array( '', 'open', 'done', 'overdue', 'today', 'upcoming' ), 'default' => 'open' ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create_task' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( self::NAMESPACE_, '/tasks/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_task' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_update_task' ), 'permission_callback' => $auth ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, '_delete_task' ), 'permission_callback' => $auth ),
		) );

		register_rest_route( self::NAMESPACE_, '/tasks/' . $id . '/complete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_complete_task' ),
			'permission_callback' => $auth,
			'args'                => array(
				'outcome'  => array( 'type' => 'string', 'default' => '' ),
				'duration' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1440 ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/tasks/' . $id . '/reopen', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_reopen_task' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/task-types', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return new WP_REST_Response( FW_CRM_Tasks::types() );
			},
			'permission_callback' => $auth,
		) );

		if ( class_exists( 'FW_CRM_Calendar' ) ) {
			register_rest_route( self::NAMESPACE_, '/calendar', array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_calendar' ),
				'permission_callback' => $auth,
				'args'                => array(
					'from'  => array( 'type' => 'string', 'required' => true, 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
					'to'    => array( 'type' => 'string', 'required' => true, 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
					'owner' => array( 'type' => 'string' ),
				),
			) );
		}

		register_rest_route( self::NAMESPACE_, '/notes/' . $id, array(
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, '_update_note' ),
				'permission_callback' => $auth,
				'args'                => array( 'note' => array( 'type' => 'string', 'required' => true ) ),
			),
			array( 'methods' => 'DELETE', 'callback' => array( $this, '_delete_note' ), 'permission_callback' => $auth ),
		) );
	}

	/**
	 * A task as the API returns it.
	 *
	 * @param object $t
	 *
	 * @return array
	 */
	protected function task( $t ) {
		$target = FW_CRM_Tasks::target( $t );
		return array(
			'id'          => (int) $t->id,
			'title'       => (string) $t->title,
			'type'        => (string) $t->type,
			'object_type' => (string) $t->object_type,
			'object_id'   => (int) $t->object_id,
			'object_name' => (string) $target['label'],
			'owner_id'    => (int) $t->owner_id,
			'due_at'      => $t->due_at,
			'overdue'     => ! $t->done_at && $t->due_at && $t->due_at < current_time( 'mysql' ),
			'done'        => (bool) $t->done_at,
			'done_at'     => $t->done_at,
			'notes'       => (string) $t->notes,
			'duration'    => (int) $t->duration,
			'location'    => (string) $t->location,
			'attendee_ids' => FW_CRM_Tasks::attendees( (int) $t->id ),
			'created_by'  => (int) $t->created_by,
			'created_at'  => $t->created_at,
		);
	}

	/**
	 * The task, or the error to answer with.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return object|WP_Error
	 */
	protected function load( $request ) {
		$t = FW_CRM_Tasks::find( (int) $request['id'] );
		if ( ! $t ) {
			return $this->not_found( __( 'Task', 'fw' ) );
		}
		if ( ! FW_CRM_Capabilities::can_edit_task( $t ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'That task belongs to someone else.', 'fw' ), array( 'status' => 403 ) );
		}
		return $t;
	}

	/**
	 * A task may only point at a record the person can reach, and a sales rep can
	 * only give tasks to themselves.
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	protected function check_write( array $data ) {
		unset( $data['id'], $data['created_by'], $data['done_at'], $data['reminder_sent_at'] );
		if ( ! empty( $data['object_type'] ) ) {
			$object = sanitize_key( (string) $data['object_type'] );
			if ( ! in_array( $object, array( 'contact', 'company', 'deal' ), true ) ) {
				return new WP_Error( 'fw_crm_bad_object', __( 'Tasks belong to a contact, a company or a deal.', 'fw' ), array( 'status' => 400 ) );
			}
			if ( ! $this->exists( $object, (int) ( $data['object_id'] ?? 0 ) ) ) {
				return $this->not_found( $this->label( $object ) );
			}
			if ( $no = $this->forbid( $object, (int) $data['object_id'] ) ) {
				return $no;
			}
		}
		foreach ( (array) ( $data['attendee_ids'] ?? array() ) as $cid ) {
			if ( ! $this->exists( 'contact', (int) $cid ) ) {
				return $this->not_found( $this->label( 'contact' ) );
			}
			if ( $no = $this->forbid( 'contact', (int) $cid ) ) {
				return $no;
			}
		}
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			unset( $data['owner_id'] );
		}
		return $data;
	}

	/** @internal */
	public function _list_tasks( $request ) {
		$args = array(
			'status'   => (string) $request->get_param( 'status' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'paged'    => (int) $request->get_param( 'page' ),
		);
		foreach ( array( 'owner', 'object_type', 'object_id' ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$args[ $k ] = $request->get_param( $k );
			}
		}
		$args = FW_CRM_Capabilities::scope( $args );

		return $this->collection( array_map( array( $this, 'task' ), FW_CRM_Tasks::query( $args ) ), FW_CRM_Tasks::count( $args ), $args['per_page'] );
	}

	/** @internal */
	public function _get_task( $request ) {
		$t = $this->load( $request );
		return is_wp_error( $t ) ? $t : new WP_REST_Response( $this->task( $t ) );
	}

	/** @internal */
	public function _create_task( $request ) {
		$data = $this->check_write( $this->body( $request ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id = FW_CRM_Tasks::create( $data );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id );
		}
		return new WP_REST_Response( $this->task( FW_CRM_Tasks::find( $id ) ), 201 );
	}

	/** @internal */
	public function _update_task( $request ) {
		$t = $this->load( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$data = $this->check_write( $this->body( $request ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$result = FW_CRM_Tasks::update( (int) $t->id, $data );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}
		return new WP_REST_Response( $this->task( FW_CRM_Tasks::find( (int) $t->id ) ) );
	}

	/** @internal */
	public function _delete_task( $request ) {
		$t = $this->load( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$previous = $this->task( $t );
		FW_CRM_Tasks::delete( (int) $t->id );
		return new WP_REST_Response( array( 'deleted' => true, 'previous' => $previous ) );
	}

	/** @internal */
	public function _complete_task( $request ) {
		$t = $this->load( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		FW_CRM_Tasks::complete( (int) $t->id, (string) $request->get_param( 'outcome' ), $request->get_param( 'duration' ) );
		return new WP_REST_Response( $this->task( FW_CRM_Tasks::find( (int) $t->id ) ) );
	}

	/** @internal */
	public function _reopen_task( $request ) {
		$t = $this->load( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		FW_CRM_Tasks::reopen( (int) $t->id );
		return new WP_REST_Response( $this->task( FW_CRM_Tasks::find( (int) $t->id ) ) );
	}

	/** @internal */
	public function _calendar( $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		$days = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
		if ( $days < 0 || $days > 62 ) {
			return new WP_Error( 'fw_crm_bad_range', __( 'Ask for up to 62 days, with from before to.', 'fw' ), array( 'status' => 400 ) );
		}
		$owner = (string) $request->get_param( 'owner' );
		$owner = 'all' === $owner ? '' : ( '' !== $owner ? (int) $owner : get_current_user_id() );
		return new WP_REST_Response( (object) FW_CRM_Calendar::events( $from, $to, $owner ) );
	}

	/** @internal */
	public function _update_note( $request ) {
		$result = FW_CRM_Notes::update( (int) $request['id'], (string) $request->get_param( 'note' ) );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, $this->status_of( $result ) );
		}
		$n = FW_CRM_Notes::find( (int) $request['id'] );
		return new WP_REST_Response( array( 'id' => (int) $n->id, 'body' => $n->body, 'object_type' => $n->object_type, 'object_id' => (int) $n->object_id ) );
	}

	/** @internal */
	public function _delete_note( $request ) {
		$result = FW_CRM_Notes::delete( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, $this->status_of( $result ) );
		}
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * @param WP_Error $error
	 *
	 * @return int
	 */
	protected function status_of( $error ) {
		switch ( $error->get_error_code() ) {
			case 'fw_crm_not_found':
				return 404;
			case 'fw_crm_forbidden':
				return 403;
		}
		return 400;
	}
}
