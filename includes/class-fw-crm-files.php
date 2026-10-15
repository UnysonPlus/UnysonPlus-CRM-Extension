<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Files on records: media library attachments linked to a contact, company or
 * deal (relations record -> attachment, labelled `file`). The file itself stays a
 * normal attachment, so it is served from the uploads folder like any other media:
 * anyone with its link can open it.
 *
 * Adding or removing a file lands on the record's timeline. Deleting the
 * attachment from the media library unlinks it everywhere; deleting a record for
 * good unlinks its files but keeps them in the library.
 */
class FW_CRM_Files {

	const LABEL = 'file';

	public static function init() {
		add_action( 'delete_attachment', function ( $attachment_id ) {
			FW_CRM_Relations::delete_for( 'attachment', (int) $attachment_id );
		} );
	}

	/**
	 * @param string $object contact|company|deal
	 *
	 * @return bool
	 */
	public static function supports( $object ) {
		return in_array( $object, array( 'contact', 'company', 'deal' ), true );
	}

	/**
	 * Link a media library file to a record.
	 *
	 * @param string $object
	 * @param int    $id
	 * @param int    $attachment_id
	 *
	 * @return true|WP_Error
	 */
	public static function attach( $object, $id, $attachment_id ) {
		$post = get_post( (int) $attachment_id );
		if ( ! self::supports( $object ) || ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'fw_crm_not_found', __( 'That file is not in the media library.', 'fw' ) );
		}
		if ( ! FW_CRM_Relations::link( $object, (int) $id, 'attachment', (int) $attachment_id, self::LABEL ) ) {
			return true; // Already attached.
		}
		FW_CRM_Activity::log( $object, (int) $id, 'file', sprintf( __( 'File added: %s', 'fw' ), self::name( $post ) ), array( 'meta' => array( 'attachment_id' => (int) $attachment_id ) ) );

		/** Fires after a file is attached to a CRM record. */
		do_action( 'unysonplus_crm_file_attached', $object, (int) $id, (int) $attachment_id );
		return true;
	}

	/**
	 * Unlink a file from a record (it stays in the media library).
	 *
	 * @param string $object
	 * @param int    $id
	 * @param int    $attachment_id
	 *
	 * @return bool Whether it was attached.
	 */
	public static function detach( $object, $id, $attachment_id ) {
		if ( ! FW_CRM_Relations::unlink( $object, (int) $id, 'attachment', (int) $attachment_id, self::LABEL ) ) {
			return false;
		}
		$post = get_post( (int) $attachment_id );
		FW_CRM_Activity::log( $object, (int) $id, 'file', sprintf( __( 'File removed: %s', 'fw' ), $post ? self::name( $post ) : '#' . (int) $attachment_id ), array( 'meta' => array( 'attachment_id' => (int) $attachment_id ) ) );

		/** Fires after a file is unlinked from a CRM record. */
		do_action( 'unysonplus_crm_file_detached', $object, (int) $id, (int) $attachment_id );
		return true;
	}

	/**
	 * Upload a file into the media library and attach it.
	 *
	 * @param string $object
	 * @param int    $id
	 * @param array  $file One entry shaped like $_FILES['x'] (name, type, tmp_name, error, size).
	 *
	 * @return int|WP_Error The attachment id.
	 */
	public static function upload( $object, $id, array $file ) {
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'fw_crm_upload', UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] ? __( 'That file is larger than this site allows.', 'fw' ) : __( 'The file did not upload.', 'fw' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$aid = media_handle_sideload( $file, 0 );
		if ( is_wp_error( $aid ) ) {
			return $aid;
		}
		update_post_meta( $aid, '_fw_crm_file', $object . ':' . (int) $id );
		$attached = self::attach( $object, $id, $aid );
		return is_wp_error( $attached ) ? $attached : (int) $aid;
	}

	/**
	 * A record's files, newest first.
	 *
	 * @param string $object
	 * @param int    $id
	 *
	 * @return array [ { id, title, filename, url, mime, size, added_at, author } ]
	 */
	public static function for_record( $object, $id ) {
		$out = array();
		foreach ( array_reverse( FW_CRM_Relations::related( $object, (int) $id, 'attachment', self::LABEL ) ) as $r ) {
			$post = get_post( (int) $r['id'] );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			$path  = get_attached_file( $post->ID );
			$out[] = array(
				'id'       => (int) $post->ID,
				'title'    => self::name( $post ),
				'filename' => $path ? wp_basename( $path ) : '',
				'url'      => (string) wp_get_attachment_url( $post->ID ),
				'mime'     => (string) $post->post_mime_type,
				'size'     => $path && file_exists( $path ) ? (int) filesize( $path ) : 0,
				'added_at' => $r['created_at'],
				'author'   => (int) $post->post_author,
			);
		}
		return $out;
	}

	/**
	 * @param string $object
	 * @param int    $id
	 *
	 * @return int
	 */
	public static function count( $object, $id ) {
		return count( FW_CRM_Relations::ids( $object, (int) $id, 'attachment', self::LABEL ) );
	}

	/**
	 * A dashicon for a mime type.
	 *
	 * @param string $mime
	 *
	 * @return string
	 */
	public static function icon( $mime ) {
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'dashicons-format-image';
		}
		if ( 'application/pdf' === $mime ) {
			return 'dashicons-pdf';
		}
		if ( preg_match( '/spreadsheet|excel|csv/', $mime ) ) {
			return 'dashicons-media-spreadsheet';
		}
		if ( preg_match( '/word|document|text/', $mime ) ) {
			return 'dashicons-media-document';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'dashicons-media-video';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'dashicons-media-audio';
		}
		if ( preg_match( '/zip|compressed|archive/', $mime ) ) {
			return 'dashicons-media-archive';
		}
		return 'dashicons-media-default';
	}

	/**
	 * @param WP_Post $post
	 *
	 * @return string
	 */
	private static function name( $post ) {
		$title = trim( (string) $post->post_title );
		if ( '' !== $title ) {
			return $title;
		}
		$path = get_attached_file( $post->ID );
		return $path ? wp_basename( $path ) : '#' . $post->ID;
	}
}
