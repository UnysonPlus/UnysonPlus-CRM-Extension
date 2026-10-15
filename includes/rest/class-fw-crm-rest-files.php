<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST routes for files on records, on `fw-crm/v1`.
 *
 *   /{contacts|companies|deals}/{id}/files        GET · POST { attachment_id } or a multipart `file` upload
 *   /{contacts|companies|deals}/{id}/files/{aid}  DELETE (unlinks; the file stays in the media library)
 */
class FW_CRM_REST_Files extends FW_CRM_REST {

	/**
	 * @internal
	 */
	public function _register_routes() {
		$auth = array( $this, '_authorize' );
		foreach ( array( 'contacts' => 'contact', 'companies' => 'company', 'deals' => 'deal' ) as $path => $object ) {
			register_rest_route( self::NAMESPACE_, '/' . $path . '/(?P<id>\d+)/files', array(
				array(
					'methods'             => 'GET',
					'callback'            => function ( $request ) use ( $object ) {
						return $this->list_files( $object, $request );
					},
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => function ( $request ) use ( $object ) {
						return $this->add_file( $object, $request );
					},
					'permission_callback' => $auth,
				),
			) );
			register_rest_route( self::NAMESPACE_, '/' . $path . '/(?P<id>\d+)/files/(?P<attachment>\d+)', array(
				'methods'             => 'DELETE',
				'callback'            => function ( $request ) use ( $object ) {
					return $this->remove_file( $object, $request );
				},
				'permission_callback' => $auth,
			) );
		}
	}

	/**
	 * The record, or the error to answer with.
	 *
	 * @param string          $object
	 * @param WP_REST_Request $request
	 *
	 * @return WP_Error|null
	 */
	protected function check( $object, $request ) {
		if ( ! $this->exists( $object, (int) $request['id'] ) ) {
			return $this->not_found( $this->label( $object ) );
		}
		return $this->forbid( $object, (int) $request['id'] );
	}

	/** @internal */
	protected function list_files( $object, $request ) {
		if ( $no = $this->check( $object, $request ) ) {
			return $no;
		}
		return new WP_REST_Response( FW_CRM_Files::for_record( $object, (int) $request['id'] ) );
	}

	/** @internal */
	protected function add_file( $object, $request ) {
		if ( $no = $this->check( $object, $request ) ) {
			return $no;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'You are not allowed to add files.', 'fw' ), array( 'status' => 403 ) );
		}
		$files = $request->get_file_params();
		if ( ! empty( $files['file'] ) ) {
			$result = FW_CRM_Files::upload( $object, (int) $request['id'], $files['file'] );
		} else {
			$aid    = (int) ( $this->body( $request )['attachment_id'] ?? 0 );
			$result = FW_CRM_Files::attach( $object, (int) $request['id'], $aid );
			$result = is_wp_error( $result ) ? $result : $aid;
		}
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result, 'fw_crm_not_found' === $result->get_error_code() ? 404 : 400 );
		}
		foreach ( FW_CRM_Files::for_record( $object, (int) $request['id'] ) as $f ) {
			if ( (int) $result === $f['id'] ) {
				return new WP_REST_Response( $f, 201 );
			}
		}
		return new WP_REST_Response( array( 'id' => (int) $result ), 201 );
	}

	/** @internal */
	protected function remove_file( $object, $request ) {
		if ( $no = $this->check( $object, $request ) ) {
			return $no;
		}
		if ( ! FW_CRM_Files::detach( $object, (int) $request['id'], (int) $request['attachment'] ) ) {
			return $this->not_found( __( 'File', 'fw' ) );
		}
		return new WP_REST_Response( array( 'deleted' => true ) );
	}
}
