<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST routes for deals and pipelines, on `fw-crm/v1` (registered only while the
 * pipeline module is on). A sales rep sees and changes their own deals; pipelines
 * are read by everyone and changed by managers.
 *
 *   /pipelines                   GET · POST (managers)
 *   /pipelines/{id}              GET (stages + totals) · PATCH/PUT · DELETE (managers)
 *   /deals                       GET (pipeline, stage, status, owner, contact, company, search, trashed,
 *                                closing_before, orderby, order) · POST
 *   /deals/{id}                  GET · PATCH/PUT (stage_id moves it) · DELETE (bin; ?force=true for managers)
 *   /deals/{id}/move             POST { stage_id } or { status: won|lost, lost_reason }
 *   /deals/{id}/restore          POST
 *   /deals/{id}/contacts         POST { contact_id }
 *   /deals/{id}/contacts/{cid}   DELETE
 *   /deals/{id}/timeline         GET · POST a note
 *   /lost-reasons                GET
 *   /products                    GET (active, search; ?catalogue=1 adds WooCommerce) · POST (managers)
 *   /products/{id}               GET · PATCH/PUT · DELETE (managers)
 *   /deals/{id}/items            GET (lines + totals) · POST { product (crm:5|woo:9) | name, quantity, unit_price, discount }
 *   /deals/{id}/items/{iid}      PATCH { quantity, unit_price, discount, name } · DELETE
 *   /reports/sales               GET (pipeline, owner (managers), from, to) → summary, by_stage, forecast,
 *                                won_lost, lost_reasons, by_owner (managers)
 */
class FW_CRM_REST_Deals extends FW_CRM_REST {

	/**
	 * @internal
	 */
	public function _register_routes() {
		$auth    = array( $this, '_authorize' );
		$manager = array( $this, '_authorize_manager' );
		$id      = '(?P<id>\d+)';
		$page    = array(
			'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
		);

		register_rest_route( self::NAMESPACE_, '/pipelines', array(
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return new WP_REST_Response( array_map( array( $this, 'pipeline' ), FW_CRM_Pipelines::all() ) );
				},
				'permission_callback' => $auth,
			),
			array( 'methods' => 'POST', 'callback' => array( $this, '_save_pipeline' ), 'permission_callback' => $manager ),
		) );

		register_rest_route( self::NAMESPACE_, '/pipelines/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_pipeline' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_save_pipeline' ), 'permission_callback' => $manager ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, '_delete_pipeline' ), 'permission_callback' => $manager ),
		) );

		register_rest_route( self::NAMESPACE_, '/deals', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_list_deals' ),
				'permission_callback' => $auth,
				'args'                => array_merge( $page, array(
					'pipeline'       => array( 'type' => 'integer' ),
					'stage'          => array( 'type' => 'integer' ),
					'status'         => array( 'type' => 'string', 'enum' => array( '', 'open', 'won', 'lost' ), 'default' => '' ),
					'owner'          => array( 'type' => 'integer' ),
					'contact'        => array( 'type' => 'integer' ),
					'company'        => array( 'type' => 'integer' ),
					'search'         => array( 'type' => 'string' ),
					'trashed'        => array( 'type' => 'string', 'enum' => array( 'exclude', 'only', 'include' ), 'default' => 'exclude' ),
					'closing_before' => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
					'orderby'        => array( 'type' => 'string', 'default' => 'position' ),
					'order'          => array( 'type' => 'string', 'enum' => array( 'asc', 'desc', 'ASC', 'DESC' ), 'default' => 'asc' ),
				) ),
			),
			array( 'methods' => 'POST', 'callback' => array( $this, '_create_deal' ), 'permission_callback' => $auth ),
		) );

		register_rest_route( self::NAMESPACE_, '/deals/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_deal' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_update_deal' ), 'permission_callback' => $auth ),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, '_delete_deal' ),
				'permission_callback' => $auth,
				'args'                => array( 'force' => array( 'type' => 'boolean', 'default' => false ) ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/move', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_move_deal' ),
			'permission_callback' => $auth,
			'args'                => array(
				'stage_id'    => array( 'type' => 'integer' ),
				'status'      => array( 'type' => 'string', 'enum' => array( 'won', 'lost' ) ),
				'lost_reason' => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_restore_deal' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/contacts', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_add_contact' ),
			'permission_callback' => $auth,
			'args'                => array( 'contact_id' => array( 'type' => 'integer', 'required' => true ) ),
		) );

		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/contacts/(?P<contact>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, '_remove_contact' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/timeline', $this->timeline_route( 'deal', $page ) );

		register_rest_route( self::NAMESPACE_, '/reports/sales', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_sales_report' ),
			'permission_callback' => $auth,
			'args'                => array(
				'pipeline' => array( 'type' => 'integer', 'default' => 0 ),
				'owner'    => array( 'type' => 'string' ),
				'from'     => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				'to'       => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/products', array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_list_products' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( $this, '_save_product' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( self::NAMESPACE_, '/products/' . $id, array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_get_product' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_save_product' ), 'permission_callback' => $manager ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, '_delete_product' ), 'permission_callback' => $manager ),
		) );
		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/items', array(
			array( 'methods' => 'GET', 'callback' => array( $this, '_list_items' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( $this, '_add_item' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( self::NAMESPACE_, '/deals/' . $id . '/items/(?P<item>\d+)', array(
			array( 'methods' => 'PUT, PATCH', 'callback' => array( $this, '_update_item' ), 'permission_callback' => $auth ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, '_remove_item' ), 'permission_callback' => $auth ),
		) );

		register_rest_route( self::NAMESPACE_, '/lost-reasons', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return new WP_REST_Response( array_values( FW_CRM_Pipelines::lost_reasons() ) );
			},
			'permission_callback' => $auth,
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Pipelines
	 * ---------------------------------------------------------------------- */

	/**
	 * @param object $p
	 *
	 * @return array
	 */
	protected function pipeline( $p ) {
		$stages = array();
		foreach ( FW_CRM_Pipelines::stages( (int) $p->id ) as $s ) {
			$stages[] = array(
				'id'          => (int) $s->id,
				'name'        => (string) $s->name,
				'kind'        => (string) $s->kind,
				'probability' => (int) $s->probability,
				'rot_days'    => (int) $s->rot_days,
				'position'    => (int) $s->position,
			);
		}
		return array(
			'id'         => (int) $p->id,
			'name'       => (string) $p->name,
			'currency'   => FW_CRM_Pipelines::currency( $p ),
			'is_default' => (bool) $p->is_default,
			'position'   => (int) $p->position,
			'stages'     => $stages,
		);
	}

	/** @internal */
	public function _get_pipeline( $request ) {
		$p = FW_CRM_Pipelines::find( (int) $request['id'] );
		if ( ! $p ) {
			return $this->not_found( __( 'Pipeline', 'fw' ) );
		}
		$out           = $this->pipeline( $p );
		$out['totals'] = array();
		foreach ( FW_CRM_Deals::totals_by_stage( (int) $p->id, FW_CRM_Capabilities::scope( array() ) ) as $sid => $t ) {
			$out['totals'][] = array_merge( array( 'stage_id' => (int) $sid ), $t );
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Create (POST /pipelines) or update (PATCH /pipelines/{id}). A PATCH may leave
	 * out the stages to keep them as they are.
	 *
	 * @internal
	 */
	public function _save_pipeline( $request ) {
		$id   = (int) $request['id'];
		$data = $this->body( $request );
		if ( $id ) {
			$p = FW_CRM_Pipelines::find( $id );
			if ( ! $p ) {
				return $this->not_found( __( 'Pipeline', 'fw' ) );
			}
			$data = array_merge( array( 'name' => $p->name, 'currency' => $p->currency ), $data );
			if ( ! isset( $data['stages'] ) ) {
				$data['stages'] = array();
				foreach ( FW_CRM_Pipelines::stages( $id ) as $s ) {
					if ( 'open' === $s->kind ) {
						$data['stages'][] = array( 'id' => (int) $s->id, 'name' => $s->name, 'probability' => (int) $s->probability, 'rot_days' => (int) $s->rot_days );
					}
				}
			}
		}
		if ( isset( $data['stages'] ) && is_array( $data['stages'] ) ) {
			// Won and lost stages are kept automatically; renaming them goes through won_name / lost_name.
			$data['stages'] = array_values( array_filter( $data['stages'], function ( $s ) {
				return is_array( $s ) && ( empty( $s['kind'] ) || 'open' === $s['kind'] );
			} ) );
		}
		$result = FW_CRM_Pipelines::save( $data, $id );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}
		return new WP_REST_Response( $this->pipeline( FW_CRM_Pipelines::find( $result ) ), $id ? 200 : 201 );
	}

	/** @internal */
	public function _delete_pipeline( $request ) {
		$p = FW_CRM_Pipelines::find( (int) $request['id'] );
		if ( ! $p ) {
			return $this->not_found( __( 'Pipeline', 'fw' ) );
		}
		$previous = $this->pipeline( $p );
		$result   = FW_CRM_Pipelines::delete( (int) $p->id );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 409 );
		}
		return new WP_REST_Response( array( 'deleted' => true, 'previous' => $previous ) );
	}

	/**
	 * Sales report. A sales rep gets their own numbers and no per-owner table.
	 *
	 * @internal
	 */
	public function _sales_report( $request ) {
		$owner = (string) $request->get_param( 'owner' );
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			$owner = get_current_user_id();
		} elseif ( 'all' === $owner ) {
			$owner = '';
		}
		$out = FW_CRM_Reports::sales( array(
			'pipeline' => (int) $request->get_param( 'pipeline' ),
			'owner'    => $owner,
			'from'     => (string) $request->get_param( 'from' ),
			'to'       => (string) $request->get_param( 'to' ),
		) );
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			unset( $out['by_owner'] );
		}
		return new WP_REST_Response( $out );
	}

	/* ---------------------------------------------------------------------- *
	 * Products and line items
	 * ---------------------------------------------------------------------- */

	/**
	 * @param object $p
	 *
	 * @return array
	 */
	protected function product( $p ) {
		return array(
			'id'          => (int) $p->id,
			'name'        => (string) $p->name,
			'sku'         => (string) $p->sku,
			'description' => (string) $p->description,
			'price'       => (float) $p->price,
			'unit'        => (string) $p->unit,
			'active'      => (bool) $p->active,
			'deals'       => FW_CRM_Products::usage( (int) $p->id ),
		);
	}

	/**
	 * @param object $i
	 *
	 * @return array
	 */
	protected function item( $i ) {
		return array(
			'id'         => (int) $i->id,
			'deal_id'    => (int) $i->deal_id,
			'source'     => (string) $i->source,
			'product_id' => (int) $i->product_id,
			'name'       => (string) $i->name,
			'sku'        => (string) $i->sku,
			'unit_price' => (float) $i->unit_price,
			'quantity'   => (float) $i->quantity,
			'discount'   => (float) $i->discount,
			'total'      => (float) $i->total,
		);
	}

	/** @internal */
	public function _list_products( $request ) {
		if ( $request->get_param( 'catalogue' ) ) {
			return new WP_REST_Response( FW_CRM_Products::catalogue( (string) $request->get_param( 'search' ) ) );
		}
		$active = $request->get_param( 'active' );
		return new WP_REST_Response( array_map( array( $this, 'product' ), FW_CRM_Products::all( array(
			'active' => null === $active ? null : rest_sanitize_boolean( $active ),
			'search' => (string) $request->get_param( 'search' ),
		) ) ) );
	}

	/** @internal */
	public function _get_product( $request ) {
		$p = FW_CRM_Products::find( (int) $request['id'] );
		return $p ? new WP_REST_Response( $this->product( $p ) ) : $this->not_found( __( 'Product', 'fw' ) );
	}

	/** @internal */
	public function _save_product( $request ) {
		$id  = (int) $request['id'];
		$res = FW_CRM_Products::save( $this->body( $request ), $id );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res, 'fw_crm_not_found' === $res->get_error_code() ? 404 : 400 );
		}
		return new WP_REST_Response( $this->product( FW_CRM_Products::find( $res ) ), $id ? 200 : 201 );
	}

	/** @internal */
	public function _delete_product( $request ) {
		$p = FW_CRM_Products::find( (int) $request['id'] );
		if ( ! $p ) {
			return $this->not_found( __( 'Product', 'fw' ) );
		}
		$previous = $this->product( $p );
		FW_CRM_Products::delete( (int) $p->id );
		return new WP_REST_Response( array( 'deleted' => true, 'previous' => $previous ) );
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	protected function items_response( $deal_id, $status = 200 ) {
		return new WP_REST_Response( array(
			'items'  => array_map( array( $this, 'item' ), FW_CRM_Products::items( $deal_id ) ),
			'totals' => FW_CRM_Products::totals( $deal_id ),
			'value'  => (float) FW_CRM_Deals::find( $deal_id )->value,
		), $status );
	}

	/** @internal */
	public function _list_items( $request ) {
		$d = $this->load( $request['id'] );
		return is_wp_error( $d ) ? $d : $this->items_response( (int) $d->id );
	}

	/** @internal */
	public function _add_item( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$res = FW_CRM_Products::add_item( (int) $d->id, $this->body( $request ) );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res, 'fw_crm_not_found' === $res->get_error_code() ? 404 : 400 );
		}
		return $this->items_response( (int) $d->id, 201 );
	}

	/** @internal */
	public function _update_item( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$item = FW_CRM_Products::item( (int) $request['item'] );
		if ( ! $item || (int) $item->deal_id !== (int) $d->id ) {
			return $this->not_found( __( 'Line', 'fw' ) );
		}
		$res = FW_CRM_Products::update_item( (int) $item->id, $this->body( $request ) );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res );
		}
		return $this->items_response( (int) $d->id );
	}

	/** @internal */
	public function _remove_item( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$item = FW_CRM_Products::item( (int) $request['item'] );
		if ( ! $item || (int) $item->deal_id !== (int) $d->id ) {
			return $this->not_found( __( 'Line', 'fw' ) );
		}
		FW_CRM_Products::remove_item( (int) $item->id );
		return $this->items_response( (int) $d->id );
	}

	/* ---------------------------------------------------------------------- *
	 * Deals
	 * ---------------------------------------------------------------------- */

	/**
	 * The deal, or the error to answer with.
	 *
	 * @param int $id
	 *
	 * @return object|WP_Error
	 */
	protected function load( $id ) {
		$d = FW_CRM_Deals::find( (int) $id );
		if ( ! $d ) {
			return $this->not_found( __( 'Deal', 'fw' ) );
		}
		if ( ! FW_CRM_Capabilities::can_edit_deal( $d ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'That deal belongs to someone else.', 'fw' ), array( 'status' => 403 ) );
		}
		return $d;
	}

	/**
	 * A deal may only link records the person can reach, and a sales rep cannot
	 * hand a deal to someone else.
	 *
	 * @param array $data
	 *
	 * @return array|WP_Error
	 */
	protected function check_write( array $data ) {
		unset( $data['id'], $data['status'], $data['closed_at'], $data['stage_changed_at'], $data['deleted_at'] );
		foreach ( array( 'contact_id' => 'contact', 'company_id' => 'company' ) as $key => $object ) {
			if ( ! empty( $data[ $key ] ) ) {
				if ( ! $this->exists( $object, (int) $data[ $key ] ) ) {
					return $this->not_found( $this->label( $object ) );
				}
				if ( $no = $this->forbid( $object, (int) $data[ $key ] ) ) {
					return $no;
				}
			}
		}
		foreach ( (array) ( $data['contact_ids'] ?? array() ) as $cid ) {
			if ( $no = $this->forbid( 'contact', (int) $cid ) ) {
				return $no;
			}
		}
		if ( ! empty( $data['pipeline_id'] ) && ! FW_CRM_Pipelines::find( (int) $data['pipeline_id'] ) ) {
			return $this->not_found( __( 'Pipeline', 'fw' ) );
		}
		if ( ! empty( $data['stage_id'] ) && ! FW_CRM_Pipelines::stage( (int) $data['stage_id'] ) ) {
			return $this->not_found( __( 'Stage', 'fw' ) );
		}
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			unset( $data['owner_id'] );
		}
		return $data;
	}

	/** @internal */
	public function _list_deals( $request ) {
		$args = array(
			'status'   => (string) $request->get_param( 'status' ),
			'search'   => (string) $request->get_param( 'search' ),
			'trashed'  => (string) $request->get_param( 'trashed' ),
			'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'order'    => (string) $request->get_param( 'order' ),
			'per_page' => (int) $request->get_param( 'per_page' ),
			'paged'    => (int) $request->get_param( 'page' ),
		);
		foreach ( array( 'pipeline', 'stage', 'owner', 'contact', 'company' ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$args[ $k ] = (int) $request->get_param( $k );
			}
		}
		if ( $request->get_param( 'closing_before' ) ) {
			$args['closing_before'] = (string) $request->get_param( 'closing_before' );
		}
		$args  = FW_CRM_Capabilities::scope( $args );
		$items = array();
		foreach ( FW_CRM_Deals::query( $args ) as $row ) {
			$items[] = FW_CRM_Deals::get( (int) $row->id );
		}
		return $this->collection( $items, FW_CRM_Deals::count( $args ), $args['per_page'] );
	}

	/** @internal */
	public function _get_deal( $request ) {
		$d = $this->load( $request['id'] );
		return is_wp_error( $d ) ? $d : new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}

	/** @internal */
	public function _create_deal( $request ) {
		$data = $this->check_write( $this->body( $request ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$id = FW_CRM_Deals::create( $data );
		if ( is_wp_error( $id ) ) {
			return $this->fail( $id );
		}
		return new WP_REST_Response( FW_CRM_Deals::get( $id ), 201 );
	}

	/** @internal */
	public function _update_deal( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$data = $this->check_write( $this->body( $request ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$result = FW_CRM_Deals::update( (int) $d->id, $data );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result );
		}
		return new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}

	/** @internal */
	public function _move_deal( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$reason = sanitize_text_field( (string) $request->get_param( 'lost_reason' ) );
		$status = (string) $request->get_param( 'status' );
		if ( $status ) {
			$result = 'won' === $status ? FW_CRM_Deals::win( (int) $d->id ) : FW_CRM_Deals::lose( (int) $d->id, $reason );
		} elseif ( $request->get_param( 'stage_id' ) ) {
			$result = FW_CRM_Deals::move( (int) $d->id, (int) $request->get_param( 'stage_id' ), $reason );
		} else {
			return new WP_Error( 'fw_crm_no_stage', __( 'Give a stage_id, or a status of won or lost.', 'fw' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 404 );
		}
		return new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}

	/** @internal */
	public function _delete_deal( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( $request->get_param( 'force' ) ) {
			if ( ! FW_CRM_Capabilities::can_manage() ) {
				return new WP_Error( 'fw_crm_forbidden', __( 'Only CRM managers can delete for good.', 'fw' ), array( 'status' => 403 ) );
			}
			$previous = FW_CRM_Deals::get( (int) $d->id );
			FW_CRM_Deals::purge( (int) $d->id );
			return new WP_REST_Response( array( 'deleted' => true, 'previous' => $previous ) );
		}
		FW_CRM_Deals::trash( (int) $d->id );
		return new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}

	/** @internal */
	public function _restore_deal( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		FW_CRM_Deals::restore( (int) $d->id );
		return new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}

	/** @internal */
	public function _add_contact( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$cid = (int) $request->get_param( 'contact_id' );
		if ( $no = $this->forbid( 'contact', $cid ) ) {
			return $no;
		}
		$result = FW_CRM_Deals::add_contact( (int) $d->id, $cid );
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 404 );
		}
		return new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}

	/** @internal */
	public function _remove_contact( $request ) {
		$d = $this->load( $request['id'] );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		FW_CRM_Deals::remove_contact( (int) $d->id, (int) $request['contact'] );
		return new WP_REST_Response( FW_CRM_Deals::get( (int) $d->id ) );
	}
}
