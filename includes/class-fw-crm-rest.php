<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST controller — `fw-crm/v1`, following the Site Converter's precedent
 * (`fw-sc/v1` + a capability-gated permission_callback).
 *
 * Every route goes through the same API the admin screens use (FW_CRM_Contacts,
 * FW_CRM_Companies, FW_CRM_Activity, FW_CRM_Consent, FW_CRM_Fields, and the
 * service for email signups), so there is no second copy of the rules.
 *
 *   /contacts                      GET list · POST create
 *   /contacts/{id}                 GET · PATCH/PUT update · DELETE (bin; ?force=true deletes for good)
 *   /contacts/{id}/restore         POST (out of the bin)
 *   /contacts/{id}/merge           POST { with, prefer } (merge `with` into {id})
 *   /contacts/duplicates           GET groups of likely duplicates
 *   /contacts/{id}/timeline        GET · POST a note
 *   /contacts/{id}/consent         GET
 *   /contacts/{id}/companies       POST link { company_id, primary }
 *   /contacts/{id}/companies/{cid} DELETE unlink
 *   /companies                     GET list · POST create
 *   /companies/{id}                GET · PATCH/PUT · DELETE (bin; ?force=true)
 *   /companies/{id}/restore        POST
 *   /companies/{id}/contacts       GET
 *   /companies/{id}/timeline       GET · POST a note
 *   /fields                        GET (?object=contact|company) · POST save a definition
 *   /fields/{object}/{key}         DELETE
 *   /stages                        GET
 *   /audit                         GET (managers; actor, object_type, object_id, action)
 *   /segments                      GET (with counts) · POST { title, rules }
 *   /segments/{id}                 GET · PATCH/PUT { title?, rules? } · DELETE
 *   /segments/{id}/contacts        GET (paged)
 *   /segments/preview              POST { rules } → { count, describe }
 *   /segments/fields               GET the fields and operators rules can use
 *   /subscribers                   GET · POST (the original email-signup routes, kept)
 *
 * Tasks and notes: FW_CRM_REST_Tasks (includes/rest/). Deals and pipelines:
 * FW_CRM_REST_Deals (the pipeline module). Both extend this class for its helpers.
 */
class FW_CRM_REST {

	const NAMESPACE_ = 'fw-crm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, '_register_routes' ) );
	}

	/**
	 * @internal
	 */
	public function _register_routes() {
		$auth = array( $this, '_authorize' );
		$id   = '(?P<id>\d+)';
		$page = array(
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
		);

		register_rest_route( self::NAMESPACE_, '/subscribers', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_get_subscribers' ),
				'permission_callback' => $auth,
				'args'                => array_merge( $page, array(
					'status' => array( 'type' => 'string' ),
					'list'   => array( 'type' => 'string' ),
					'search' => array( 'type' => 'string' ),
				) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create_subscriber' ),
				'permission_callback' => $auth,
				'args'                => array(
					'email' => array( 'type' => 'string', 'required' => true ),
					'name'  => array( 'type' => 'string' ),
					'list'  => array( 'type' => 'string' ),
					'tags'  => array( 'type' => 'array' ),
				),
			),
		) );

		/* --- Contacts ------------------------------------------------------ */

		register_rest_route( self::NAMESPACE_, '/contacts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_list_contacts' ),
				'permission_callback' => $auth,
				'args'                => array_merge( $page, array(
					'search'  => array( 'type' => 'string' ),
					'stage'   => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
					'owner'   => array( 'type' => 'integer' ),
					'company' => array( 'type' => 'integer' ),
					'list'    => array( 'type' => 'string' ),
					'tag'     => array( 'type' => 'string' ),
					'segment' => array( 'type' => 'integer' ),
					'trashed' => array( 'type' => 'string', 'enum' => array( 'exclude', 'only', 'include' ), 'default' => 'exclude' ),
					'orderby' => array( 'type' => 'string', 'default' => 'created_at' ),
					'order'   => array( 'type' => 'string', 'enum' => array( 'asc', 'desc', 'ASC', 'DESC' ), 'default' => 'desc' ),
				) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create_contact' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_contact' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_update_contact' ), 'permission_callback' => $auth ),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, '_delete_contact' ),
				'permission_callback' => $auth,
				'args'                => array( 'force' => array( 'type' => 'boolean', 'default' => false ) ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id . '/merge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_merge_contact' ),
			'permission_callback' => array( $this, '_authorize_manager' ),
			'args'                => array(
				'with'   => array( 'type' => 'integer', 'required' => true ),
				'prefer' => array( 'type' => 'object' ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/duplicates', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return new WP_REST_Response( FW_CRM_Merge::find_groups( 100 ) );
			},
			'permission_callback' => array( $this, '_authorize_manager' ),
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id . '/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_restore_contact' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id . '/timeline', $this->timeline_route( 'contact', $page ) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id . '/consent', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_get_consent' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id . '/companies', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_link_company' ),
			'permission_callback' => $auth,
			'args'                => array(
				'company_id' => array( 'type' => 'integer', 'required' => true ),
				'primary'    => array( 'type' => 'boolean', 'default' => false ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/contacts/' . $id . '/companies/(?P<company>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, '_unlink_company' ),
			'permission_callback' => $auth,
		) );

		/* --- Companies ----------------------------------------------------- */

		register_rest_route( self::NAMESPACE_, '/companies', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_list_companies' ),
				'permission_callback' => $auth,
				'args'                => array_merge( $page, array(
					'search'  => array( 'type' => 'string' ),
					'owner'   => array( 'type' => 'integer' ),
					'trashed' => array( 'type' => 'string', 'enum' => array( 'exclude', 'only', 'include' ), 'default' => 'exclude' ),
					'orderby' => array( 'type' => 'string', 'default' => 'name' ),
					'order'   => array( 'type' => 'string', 'enum' => array( 'asc', 'desc', 'ASC', 'DESC' ), 'default' => 'asc' ),
				) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create_company' ),
				'permission_callback' => $auth,
			),
		) );

		register_rest_route( self::NAMESPACE_, '/companies/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_company' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_update_company' ), 'permission_callback' => $auth ),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, '_delete_company' ),
				'permission_callback' => $auth,
				'args'                => array( 'force' => array( 'type' => 'boolean', 'default' => false ) ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/companies/' . $id . '/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_restore_company' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/companies/' . $id . '/contacts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_company_contacts' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/companies/' . $id . '/timeline', $this->timeline_route( 'company', $page ) );

		/* --- Fields, stages ------------------------------------------------ */

		register_rest_route( self::NAMESPACE_, '/fields', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_list_fields' ),
				'permission_callback' => $auth,
				'args'                => array( 'object' => array( 'type' => 'string' ) ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_save_field' ),
				'permission_callback' => array( $this, '_authorize_manager' ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/fields/(?P<object>[a-z_]+)/(?P<key>[a-z][a-z0-9_]*)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, '_delete_field' ),
			'permission_callback' => array( $this, '_authorize_manager' ),
		) );

		/* --- Segments ------------------------------------------------------ */

		$manager = array( $this, '_authorize_manager' );

		register_rest_route( self::NAMESPACE_, '/segments', array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_list_segments' ), 'permission_callback' => $manager ),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_save_segment' ),
				'permission_callback' => $manager,
				'args'                => array( 'title' => array( 'type' => 'string', 'required' => true ) ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/segments/preview', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_preview_segment' ),
			'permission_callback' => $manager,
		) );

		register_rest_route( self::NAMESPACE_, '/segments/fields', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return new WP_REST_Response( array( 'fields' => FW_CRM_Segments::fields(), 'operators' => FW_CRM_Segments::operators() ) );
			},
			'permission_callback' => $manager,
		) );

		register_rest_route( self::NAMESPACE_, '/segments/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_segment' ), 'permission_callback' => $manager ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_save_segment' ), 'permission_callback' => $manager ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, '_delete_segment' ), 'permission_callback' => $manager ),
		) );

		register_rest_route( self::NAMESPACE_, '/segments/' . $id . '/contacts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_segment_contacts' ),
			'permission_callback' => $manager,
			'args'                => $page,
		) );

		if ( FW_CRM_Modules::enabled( 'audit' ) ) {
			register_rest_route( self::NAMESPACE_, '/audit', array(
				'methods'             => 'GET',
				'callback'            => function ( $request ) {
					list( $rows, $total ) = FW_CRM_Audit::query( array(
						'actor'       => $request->get_param( 'actor' ),
						'object_type' => (string) $request->get_param( 'object_type' ),
						'object_id'   => (int) $request->get_param( 'object_id' ),
						'action'      => (string) $request->get_param( 'action' ),
						'per_page'    => (int) $request->get_param( 'per_page' ),
						'paged'       => (int) $request->get_param( 'page' ),
					) );
					return $this->collection( array_map( function ( $r ) {
						return array(
							'id'          => (int) $r->id,
							'actor_id'    => (int) $r->actor_id,
							'object_type' => $r->object_type,
							'object_id'   => (int) $r->object_id,
							'action'      => $r->action,
							'details'     => $r->details,
							'created_at'  => $r->created_at,
						);
					}, $rows ), $total, max( 1, (int) $request->get_param( 'per_page' ) ) );
				},
				'permission_callback' => array( $this, '_authorize_manager' ),
				'args'                => array_merge( $page, array(
					'actor'       => array( 'type' => 'integer' ),
					'object_type' => array( 'type' => 'string' ),
					'object_id'   => array( 'type' => 'integer' ),
					'action'      => array( 'type' => 'string' ),
				) ),
			) );
		}

		register_rest_route( self::NAMESPACE_, '/stages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_list_stages' ),
			'permission_callback' => $auth,
		) );
	}

	/**
	 * @param string $object
	 * @param array  $page
	 *
	 * @return array
	 */
	protected function timeline_route( $object, array $page ) {
		return array(
			array(
				'methods'             => 'GET',
				'callback'            => function ( $request ) use ( $object ) {
					return $this->get_timeline( $object, $request );
				},
				'permission_callback' => array( $this, '_authorize' ),
				'args'                => $page,
			),
			array(
				'methods'             => 'POST',
				'callback'            => function ( $request ) use ( $object ) {
					return $this->add_note( $object, $request );
				},
				'permission_callback' => array( $this, '_authorize' ),
				'args'                => array( 'note' => array( 'type' => 'string', 'required' => true ) ),
			),
		);
	}

	/**
	 * @internal
	 *
	 * @return bool
	 */
	public function _authorize() {
		return current_user_can( FW_CRM_Service::capability() );
	}

	/**
	 * Manager-only routes (fields, segments writes, merges, deleting for good).
	 *
	 * @internal
	 *
	 * @return bool
	 */
	public function _authorize_manager() {
		return current_user_can( FW_CRM_Service::capability() ) && FW_CRM_Capabilities::can_manage();
	}

	/**
	 * A sales rep may only reach records they own.
	 *
	 * @param string $object contact|company|deal
	 * @param int    $id
	 *
	 * @return WP_Error|null
	 */
	protected function forbid( $object, $id ) {
		switch ( $object ) {
			case 'company':
				$ok = FW_CRM_Capabilities::can_edit_company( (int) $id );
				break;
			case 'deal':
				$ok = FW_CRM_Capabilities::can_edit_deal( (int) $id );
				break;
			default:
				$ok = FW_CRM_Capabilities::can_edit_contact( (int) $id );
		}
		if ( $ok || ! $this->exists( $object, (int) $id ) ) {
			return null; // Allowed, or missing (the handler answers 404).
		}
		return new WP_Error( 'fw_crm_forbidden', __( 'That record belongs to someone else.', 'fw' ), array( 'status' => 403 ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * @param WP_Error $error
	 * @param int      $status
	 *
	 * @return WP_Error
	 */
	protected function fail( $error, $status = 400 ) {
		$error->add_data( array( 'status' => $status ) );
		return $error;
	}

	/** @return WP_Error */
	protected function not_found( $what ) {
		return new WP_Error( 'fw_crm_not_found', sprintf( __( '%s not found.', 'fw' ), $what ), array( 'status' => 404 ) );
	}

	/**
	 * A paged collection response with the WordPress total headers.
	 *
	 * @param array $items
	 * @param int   $total
	 * @param int   $per_page
	 *
	 * @return WP_REST_Response
	 */
	protected function collection( array $items, $total, $per_page ) {
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) (int) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * The writable body of a request, without the route's own params.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	protected function body( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || ! $data ) {
			$data = $request->get_body_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * A contact as the API returns it: the contact API's shape plus subscription
	 * details. Tokens are credentials, never data, so they are never included.
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	protected function contact( $id ) {
		$c = FW_CRM_Contacts::get( $id );
		if ( ! $c ) {
			return null;
		}
		$c['lists'] = wp_list_pluck( FW_CRM_Subscribers::get_lists( $id, 'list' ), 'slug' );
		$c['list_status'] = array();
		foreach ( FW_CRM_List_Membership::for_contact( $id ) as $m ) {
			$c['list_status'][ $m->slug ] = array(
				'status'          => $m->status,
				'subscribed_at'   => $m->subscribed_at,
				'unsubscribed_at' => $m->unsubscribed_at,
				'source'          => $m->source,
			);
		}
		$c['tags']  = wp_list_pluck( FW_CRM_Subscribers::get_lists( $id, 'tag' ), 'slug' );
		return $c;
	}

	/**
	 * @param object $row
	 *
	 * @return array
	 */
	private function company( $row ) {
		$keys = array( 'name', 'domain', 'website', 'phone', 'email', 'industry', 'size', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country', 'last_activity_at', 'created_at', 'updated_at', 'deleted_at' );
		$out  = array( 'id' => (int) $row->id );
		foreach ( $keys as $k ) {
			$out[ $k ] = $row->$k;
		}
		$out['owner_id']       = (int) $row->owner_id;
		$out['lifetime_value'] = (float) $row->lifetime_value;
		$out['contact_ids']    = array_map( 'intval', FW_CRM_Companies::contact_ids( (int) $row->id ) );
		$out['fields']         = FW_CRM_Fields::get_values( 'company', (int) $row->id );
		return $out;
	}

	/**
	 * Lists and tags given by slug (or id) in a write: `lists` / `tags` replace the
	 * contact's current ones.
	 *
	 * @param int   $id
	 * @param array $data
	 */
	private function apply_membership( $id, array $data ) {
		foreach ( array( 'lists' => 'list', 'tags' => 'tag' ) as $key => $type ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				continue;
			}
			$ids = array();
			foreach ( $data[ $key ] as $ref ) {
				$row = is_numeric( $ref ) ? FW_CRM_Lists::find( (int) $ref ) : FW_CRM_Lists::get_or_create( sanitize_title( (string) $ref ), $type );
				if ( $row ) {
					$ids[] = (int) $row->id;
				}
			}
			FW_CRM_Subscribers::set_lists( $id, $ids, $type );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Contacts
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public function _list_contacts( $request ) {
		$args = array(
			'search'   => (string) $request->get_param( 'search' ),
			'stage'    => sanitize_key( (string) $request->get_param( 'stage' ) ),
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'list'     => sanitize_key( (string) $request->get_param( 'list' ) ),
			'tag'      => sanitize_key( (string) $request->get_param( 'tag' ) ),
			'trashed'  => (string) $request->get_param( 'trashed' ),
			'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'order'    => (string) $request->get_param( 'order' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'paged'    => (int) $request->get_param( 'page' ),
		);
		foreach ( array( 'owner', 'company', 'segment' ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$args[ $k ] = (int) $request->get_param( $k );
			}
		}

		$args  = FW_CRM_Capabilities::scope( $args );
		$items = array();
		foreach ( FW_CRM_Subscribers::query( $args ) as $row ) {
			$items[] = $this->contact( (int) $row->id );
		}

		return $this->collection( $items, FW_CRM_Subscribers::count( $args ), $args['per_page'] );
	}

	/** @internal */
	public function _get_contact( $request ) {
		if ( $no = $this->forbid( 'contact', (int) $request['id'] ) ) {
			return $no;
		}
		$c = $this->contact( (int) $request['id'] );
		return $c ? new WP_REST_Response( $c ) : $this->not_found( __( 'Contact', 'fw' ) );
	}

	/** @internal */
	public function _create_contact( $request ) {
		$data           = $this->body( $request );
		$data['source'] = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'rest';
		unset( $data['status'] ); // Subscribing someone needs their consent: use POST /subscribers.
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			$data['owner_id'] = get_current_user_id();
		}
		if ( ! empty( $data['user_id'] ) && ! FW_CRM_Capabilities::can_link_user( (int) $data['user_id'] ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'You may not link contacts to that site account.', 'fw' ), array( 'status' => 403 ) );
		}

		$id = FW_CRM_Contacts::create( $data );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id );
		}
		$this->apply_membership( $id, $data );

		return new WP_REST_Response( $this->contact( $id ), 201 );
	}

	/** @internal */
	public function _update_contact( $request ) {
		$id = (int) $request['id'];
		if ( ! FW_CRM_Subscribers::find( $id ) ) {
			return $this->not_found( __( 'Contact', 'fw' ) );
		}
		if ( $no = $this->forbid( 'contact', $id ) ) {
			return $no;
		}
		$data = $this->body( $request );
		unset( $data['status'], $data['id'] );
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			unset( $data['owner_id'] );
		}
		if ( array_key_exists( 'user_id', $data ) && ! FW_CRM_Capabilities::can_link_user( (int) $data['user_id'] ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'You may not link contacts to that site account.', 'fw' ), array( 'status' => 403 ) );
		}

		$result = FW_CRM_Contacts::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}
		$this->apply_membership( $id, $data );

		return new WP_REST_Response( $this->contact( $id ) );
	}

	/** @internal */
	public function _delete_contact( $request ) {
		$id      = (int) $request['id'];
		if ( $no = $this->forbid( 'contact', $id ) ) {
			return $no;
		}
		$contact = $this->contact( $id );
		if ( ! $contact ) {
			return $this->not_found( __( 'Contact', 'fw' ) );
		}
		if ( $request->get_param( 'force' ) && ! FW_CRM_Capabilities::can_manage() ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'Only CRM managers can delete for good.', 'fw' ), array( 'status' => 403 ) );
		}
		if ( $request->get_param( 'force' ) ) {
			FW_CRM_Contacts::purge( $id );
			return new WP_REST_Response( array( 'deleted' => true, 'previous' => $contact ) );
		}
		FW_CRM_Contacts::trash( $id );
		return new WP_REST_Response( $this->contact( $id ) );
	}

	/** @internal */
	public function _restore_contact( $request ) {
		$id = (int) $request['id'];
		if ( $no = $this->forbid( 'contact', $id ) ) {
			return $no;
		}
		return FW_CRM_Contacts::restore( $id ) ? new WP_REST_Response( $this->contact( $id ) ) : $this->not_found( __( 'Contact', 'fw' ) );
	}

	/** @internal */
	public function _merge_contact( $request ) {
		$result = FW_CRM_Merge::merge( (int) $request['id'], (int) $request->get_param( 'with' ), (array) $request->get_param( 'prefer' ) );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 'fw_crm_not_found' === $result->get_error_code() ? 404 : 400 );
		}
		return new WP_REST_Response( $this->contact( (int) $request['id'] ) );
	}

	/** @internal */
	public function _get_consent( $request ) {
		$id = (int) $request['id'];
		if ( $no = $this->forbid( 'contact', $id ) ) {
			return $no;
		}
		if ( ! FW_CRM_Subscribers::find( $id ) ) {
			return $this->not_found( __( 'Contact', 'fw' ) );
		}
		$out = array();
		foreach ( FW_CRM_Consent::for_contact( $id ) as $r ) {
			$out[] = array(
				'id'         => (int) $r->id,
				'purpose'    => $r->purpose,
				'granted'    => (bool) (int) $r->granted,
				'text'       => $r->text,
				'source'     => $r->source,
				'source_url' => $r->source_url,
				'created_at' => $r->created_at,
			);
		}
		return new WP_REST_Response( $out );
	}

	/** @internal */
	public function _link_company( $request ) {
		if ( ( $no = $this->forbid( 'contact', (int) $request['id'] ) ) || ( $no = $this->forbid( 'company', (int) $request->get_param( 'company_id' ) ) ) ) {
			return $no;
		}
		$result = FW_CRM_Contacts::add_company( (int) $request['id'], (int) $request->get_param( 'company_id' ), (bool) $request->get_param( 'primary' ) );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 404 );
		}
		return new WP_REST_Response( $this->contact( (int) $request['id'] ) );
	}

	/** @internal */
	public function _unlink_company( $request ) {
		if ( $no = $this->forbid( 'contact', (int) $request['id'] ) ) {
			return $no;
		}
		if ( ! FW_CRM_Subscribers::find( (int) $request['id'] ) ) {
			return $this->not_found( __( 'Contact', 'fw' ) );
		}
		FW_CRM_Contacts::remove_company( (int) $request['id'], (int) $request['company'] );
		return new WP_REST_Response( $this->contact( (int) $request['id'] ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Timeline
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string          $object
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	protected function record_exists( $object, $request ) {
		return $this->exists( $object, (int) $request['id'] );
	}

	/**
	 * @param string $object contact|company|deal
	 * @param int    $id
	 *
	 * @return bool
	 */
	protected function exists( $object, $id ) {
		switch ( $object ) {
			case 'company':
				return (bool) FW_CRM_Companies::find( $id );
			case 'deal':
				return class_exists( 'FW_CRM_Deals' ) && FW_CRM_Deals::find( $id );
		}
		return (bool) FW_CRM_Subscribers::find( $id );
	}

	/**
	 * @param string $object
	 *
	 * @return string
	 */
	protected function label( $object ) {
		switch ( $object ) {
			case 'company':
				return __( 'Company', 'fw' );
			case 'deal':
				return __( 'Deal', 'fw' );
		}
		return __( 'Contact', 'fw' );
	}

	/**
	 * @param string          $object
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function get_timeline( $object, $request ) {
		if ( $no = $this->forbid( $object, (int) $request['id'] ) ) {
			return $no;
		}
		if ( ! $this->record_exists( $object, $request ) ) {
			return $this->not_found( $this->label( $object ) );
		}
		$per  = (int) $request->get_param( 'per_page' );
		$rows = FW_CRM_Activity::for_record( $object, (int) $request['id'], $per, ( (int) $request->get_param( 'page' ) - 1 ) * $per );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'         => (int) $r->id,
				'type'       => $r->type,
				'title'      => $r->title,
				'body'       => $r->body,
				'meta'       => $r->meta,
				'actor_id'   => (int) $r->actor_id,
				'created_at' => $r->created_at,
			);
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * @param string          $object
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	protected function add_note( $object, $request ) {
		if ( $no = $this->forbid( $object, (int) $request['id'] ) ) {
			return $no;
		}
		if ( ! $this->record_exists( $object, $request ) ) {
			return $this->not_found( $this->label( $object ) );
		}
		$note = trim( sanitize_textarea_field( (string) $request->get_param( 'note' ) ) );
		if ( '' === $note ) {
			return new WP_Error( 'fw_crm_empty_note', __( 'The note is empty.', 'fw' ), array( 'status' => 400 ) );
		}
		$entry = FW_CRM_Activity::log( $object, (int) $request['id'], 'note', __( 'Note', 'fw' ), array( 'body' => $note ) );
		return new WP_REST_Response( array( 'id' => $entry ), 201 );
	}

	/* ---------------------------------------------------------------------- *
	 * Companies
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public function _list_companies( $request ) {
		$args = array(
			'search'   => (string) $request->get_param( 'search' ),
			'trashed'  => (string) $request->get_param( 'trashed' ),
			'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'order'    => (string) $request->get_param( 'order' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'paged'    => (int) $request->get_param( 'page' ),
		);
		if ( null !== $request->get_param( 'owner' ) ) {
			$args['owner'] = (int) $request->get_param( 'owner' );
		}
		$args  = FW_CRM_Capabilities::scope( $args );
		$items = array_map( array( $this, 'company' ), FW_CRM_Companies::query( $args ) );

		return $this->collection( $items, FW_CRM_Companies::count( $args ), $args['per_page'] );
	}

	/** @internal */
	public function _get_company( $request ) {
		if ( $no = $this->forbid( 'company', (int) $request['id'] ) ) {
			return $no;
		}
		$row = FW_CRM_Companies::find( (int) $request['id'] );
		return $row ? new WP_REST_Response( $this->company( $row ) ) : $this->not_found( __( 'Company', 'fw' ) );
	}

	/** @internal */
	public function _create_company( $request ) {
		$data = $this->body( $request );
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			$data['owner_id'] = get_current_user_id();
		}
		$id = FW_CRM_Companies::create( $data );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id );
		}
		return new WP_REST_Response( $this->company( FW_CRM_Companies::find( $id ) ), 201 );
	}

	/** @internal */
	public function _update_company( $request ) {
		if ( $no = $this->forbid( 'company', (int) $request['id'] ) ) {
			return $no;
		}
		$data = $this->body( $request );
		unset( $data['id'] );
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			unset( $data['owner_id'] );
		}
		$result = FW_CRM_Companies::update( (int) $request['id'], $data );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 'fw_crm_not_found' === $result->get_error_code() ? 404 : 400 );
		}
		return new WP_REST_Response( $this->company( FW_CRM_Companies::find( (int) $request['id'] ) ) );
	}

	/** @internal */
	public function _delete_company( $request ) {
		if ( $no = $this->forbid( 'company', (int) $request['id'] ) ) {
			return $no;
		}
		$row = FW_CRM_Companies::find( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found( __( 'Company', 'fw' ) );
		}
		if ( $request->get_param( 'force' ) && ! FW_CRM_Capabilities::can_manage() ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'Only CRM managers can delete for good.', 'fw' ), array( 'status' => 403 ) );
		}
		if ( $request->get_param( 'force' ) ) {
			$previous = $this->company( $row );
			FW_CRM_Companies::purge( (int) $row->id );
			return new WP_REST_Response( array( 'deleted' => true, 'previous' => $previous ) );
		}
		FW_CRM_Companies::trash( (int) $row->id );
		return new WP_REST_Response( $this->company( FW_CRM_Companies::find( (int) $row->id ) ) );
	}

	/** @internal */
	public function _restore_company( $request ) {
		if ( $no = $this->forbid( 'company', (int) $request['id'] ) ) {
			return $no;
		}
		$result = FW_CRM_Companies::restore( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 404 );
		}
		return new WP_REST_Response( $this->company( FW_CRM_Companies::find( (int) $request['id'] ) ) );
	}

	/** @internal */
	public function _company_contacts( $request ) {
		if ( $no = $this->forbid( 'company', (int) $request['id'] ) ) {
			return $no;
		}
		if ( ! FW_CRM_Companies::find( (int) $request['id'] ) ) {
			return $this->not_found( __( 'Company', 'fw' ) );
		}
		$out = array();
		foreach ( FW_CRM_Companies::contact_ids( (int) $request['id'] ) as $cid ) {
			$c = $this->contact( (int) $cid );
			if ( $c ) {
				$out[] = $c;
			}
		}
		return new WP_REST_Response( $out );
	}

	/* ---------------------------------------------------------------------- *
	 * Fields, stages
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public function _list_fields( $request ) {
		$object = $request->get_param( 'object' );
		return new WP_REST_Response( array(
			'types'  => FW_CRM_Fields::types(),
			'fields' => FW_CRM_Fields::definitions( $object ? sanitize_key( (string) $object ) : null ),
		) );
	}

	/** @internal */
	public function _save_field( $request ) {
		$def = FW_CRM_Fields::save_definition( $this->body( $request ) );
		return is_wp_error( $def ) ? $this->fail( $def ) : new WP_REST_Response( $def, 201 );
	}

	/** @internal */
	public function _delete_field( $request ) {
		if ( ! FW_CRM_Fields::definition( (string) $request['object'], (string) $request['key'] ) ) {
			return $this->not_found( __( 'Field', 'fw' ) );
		}
		FW_CRM_Fields::delete_definition( (string) $request['object'], (string) $request['key'] );
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * @param object $row
	 *
	 * @return array
	 */
	private function segment( $row ) {
		$filters = FW_CRM_Lists::segment_query_args( $row );
		return array(
			'id'         => (int) $row->id,
			'slug'       => $row->slug,
			'title'      => $row->title,
			'rules'      => $filters['rules'] ?? null,
			'filters'    => array_diff_key( $filters, array( 'rules' => 1 ) ),
			'describe'   => FW_CRM_Service::describe_filters( $filters ),
			'count'      => FW_CRM_Segments::count( $row ),
			'updated_at' => $row->updated_at,
		);
	}

	/** @internal */
	public function _list_segments() {
		return new WP_REST_Response( array_map( array( $this, 'segment' ), FW_CRM_Lists::segments() ) );
	}

	/** @internal */
	public function _get_segment( $request ) {
		$row = FW_CRM_Lists::find_segment( (int) $request['id'] );
		return $row ? new WP_REST_Response( $this->segment( $row ) ) : $this->not_found( __( 'Segment', 'fw' ) );
	}

	/** @internal */
	public function _save_segment( $request ) {
		$id   = isset( $request['id'] ) ? (int) $request['id'] : 0;
		$body = $this->body( $request );
		$row  = $id ? FW_CRM_Lists::find_segment( $id ) : null;
		if ( $id && ! $row ) {
			return $this->not_found( __( 'Segment', 'fw' ) );
		}
		$title = isset( $body['title'] ) ? (string) $body['title'] : ( $row ? $row->title : '' );
		$rules = isset( $body['rules'] ) ? $body['rules'] : ( $row ? ( FW_CRM_Lists::segment_query_args( $row )['rules'] ?? array() ) : array() );

		$result = FW_CRM_Segments::save( $title, $rules, $id );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}
		return new WP_REST_Response( $this->segment( $result ), $id ? 200 : 201 );
	}

	/** @internal */
	public function _delete_segment( $request ) {
		$row = FW_CRM_Lists::find_segment( (int) $request['id'] );
		if ( ! $row ) {
			return $this->not_found( __( 'Segment', 'fw' ) );
		}
		FW_CRM_Service::delete_segment( (int) $row->id );
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/** @internal */
	public function _preview_segment( $request ) {
		$body = $this->body( $request );
		$tree = FW_CRM_Segments::sanitize( $body['rules'] ?? array() );
		return new WP_REST_Response( array(
			'rules'    => $tree,
			'count'    => $tree['rules'] ? FW_CRM_Segments::preview_count( $tree ) : 0,
			'describe' => FW_CRM_Segments::describe( $tree ),
		) );
	}

	/** @internal */
	public function _segment_contacts( $request ) {
		if ( ! FW_CRM_Lists::find_segment( (int) $request['id'] ) ) {
			return $this->not_found( __( 'Segment', 'fw' ) );
		}
		$args  = array(
			'segment'  => (int) $request['id'],
			'per_page' => (int) $request->get_param( 'per_page' ),
			'paged'    => (int) $request->get_param( 'page' ),
		);
		$items = array();
		foreach ( FW_CRM_Subscribers::query( $args ) as $row ) {
			$items[] = $this->contact( (int) $row->id );
		}
		return $this->collection( $items, FW_CRM_Subscribers::count( $args ), $args['per_page'] );
	}

	/** @internal */
	public function _list_stages() {
		$out = array();
		foreach ( FW_CRM_Contacts::stages() as $slug => $label ) {
			$out[] = array( 'slug' => $slug, 'label' => $label );
		}
		return new WP_REST_Response( $out );
	}

	/* ---------------------------------------------------------------------- *
	 * Subscribers (the original email-signup routes)
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function _get_subscribers( $request ) {
		$args = FW_CRM_Capabilities::scope( array(
			'status'   => (string) $request->get_param( 'status' ),
			'list'     => (string) $request->get_param( 'list' ),
			'search'   => (string) $request->get_param( 'search' ),
			'per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'paged'    => max( 1, (int) $request->get_param( 'page' ) ),
		) );

		$items = array();
		foreach ( FW_CRM_Subscribers::query( $args ) as $row ) {
			$items[] = $this->prepare( $row );
		}

		return $this->collection( $items, FW_CRM_Subscribers::count( $args ), $args['per_page'] );
	}

	/**
	 * @internal
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function _create_subscriber( $request ) {
		$result = FW_CRM_Service::subscribe( (string) $request->get_param( 'email' ), array(
			'name'   => (string) $request->get_param( 'name' ),
			'list'   => (string) $request->get_param( 'list' ),
			'tags'   => (array) $request->get_param( 'tags' ),
			'source' => 'rest',
		) );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}

		return new WP_REST_Response( $this->prepare( $result ), 201 );
	}

	/**
	 * Never expose the tokens — they are credentials, not data.
	 *
	 * @param object $row
	 *
	 * @return array
	 */
	private function prepare( $row ) {
		return array(
			'id'         => (int) $row->id,
			'email'      => $row->email,
			'first_name' => $row->first_name,
			'last_name'  => $row->last_name,
			'status'     => $row->status,
			'source'     => $row->source,
			'lists'      => wp_list_pluck( FW_CRM_Subscribers::get_lists( $row->id, 'list' ), 'slug' ),
			'tags'       => wp_list_pluck( FW_CRM_Subscribers::get_lists( $row->id, 'tag' ), 'slug' ),
			'created_at' => $row->created_at,
		);
	}
}
