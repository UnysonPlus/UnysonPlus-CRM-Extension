<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Files panel on contact, company and deal pages: upload files or pick them
 * from the media library, open them, remove them from the record.
 */
class FW_CRM_Admin_Files {

	const MAX_AT_ONCE = 10;

	public static function init() {
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		foreach ( array( 'contact', 'company', 'deal' ) as $object ) {
			add_filter( 'unysonplus_crm_' . $object . '_panels', function ( $panels, $record ) use ( $object ) {
				$id              = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
				$panels['files'] = array(
					'label'    => __( 'Files', 'fw' ),
					'priority' => 30,
					'column'   => 'side',
					'callback' => function () use ( $object, $id ) {
						self::render_panel( $object, $id );
					},
				);
				return $panels;
			}, 10, 2 );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, '_enqueue' ) );
	}

	/** @internal */
	public static function _enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( (string) $hook, 'fw-crm' ) || ! ( isset( $_GET['subscriber'] ) || isset( $_GET['company'] ) || isset( $_GET['deal'] ) ) ) {
			return;
		}
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
		$ext = fw_ext( 'crm' );
		wp_enqueue_script( 'fw-crm-files', $ext->get_uri( '/static/js/files.js' ), array(), $ext->manifest->get_version(), true );
	}

	/**
	 * @param string $object
	 * @param int    $id
	 *
	 * @return bool
	 */
	private static function can_edit( $object, $id ) {
		switch ( $object ) {
			case 'contact':
				return FW_CRM_Capabilities::can_edit_contact( (int) $id );
			case 'company':
				return FW_CRM_Capabilities::can_edit_company( (int) $id );
			case 'deal':
				return class_exists( 'FW_CRM_Deals' ) && FW_CRM_Capabilities::can_edit_deal( (int) $id );
		}
		return false;
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || ! in_array( $action, array( 'file_attach', 'file_detach' ), true ) ) {
			return $args;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$object = isset( $_REQUEST['object'] ) ? sanitize_key( wp_unslash( $_REQUEST['object'] ) ) : '';
		$id     = isset( $_REQUEST['object_id'] ) ? (int) $_REQUEST['object_id'] : 0;
		$back   = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : FW_CRM_Admin_Page::get_page_url();
		if ( ! FW_CRM_Files::supports( $object ) || ! self::can_edit( $object, $id ) ) {
			wp_die( esc_html__( 'That record belongs to someone else.', 'fw' ), 403 );
		}

		if ( 'file_detach' === $action ) {
			FW_CRM_Files::detach( $object, $id, isset( $_REQUEST['attachment'] ) ? (int) $_REQUEST['attachment'] : 0 );
			FW_CRM_Admin_Page::flash( 'success', __( 'File removed from the record. It is still in the media library.', 'fw' ) );
			return array( '_back' => $back );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You are not allowed to add files.', 'fw' ), 403 );
		}
		$added  = 0;
		$errors = array();
		// From the media library.
		$picked = isset( $_POST['attachments'] ) ? array_filter( array_map( 'intval', explode( ',', (string) wp_unslash( $_POST['attachments'] ) ) ) ) : array();
		foreach ( array_slice( $picked, 0, 50 ) as $aid ) {
			$r = FW_CRM_Files::attach( $object, $id, $aid );
			is_wp_error( $r ) ? $errors[] = $r->get_error_message() : $added++;
		}
		// Uploaded.
		if ( ! empty( $_FILES['files']['name'] ) && is_array( $_FILES['files']['name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$files = $_FILES['files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handed to media_handle_sideload, which checks type and moves it.
			$count = min( count( $files['name'] ), self::MAX_AT_ONCE );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( '' === (string) $files['name'][ $i ] ) {
					continue;
				}
				$r = FW_CRM_Files::upload( $object, $id, array(
					'name'     => $files['name'][ $i ],
					'type'     => $files['type'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				) );
				is_wp_error( $r ) ? $errors[] = $files['name'][ $i ] . ': ' . $r->get_error_message() : $added++;
			}
		}
		// phpcs:enable
		if ( $errors ) {
			FW_CRM_Admin_Page::flash( 'error', implode( ' ', array_map( 'wp_strip_all_tags', $errors ) ) );
		} elseif ( $added ) {
			FW_CRM_Admin_Page::flash( 'success', sprintf( _n( '%s file added.', '%s files added.', $added, 'fw' ), number_format_i18n( $added ) ) );
		} else {
			FW_CRM_Admin_Page::flash( 'error', __( 'Choose a file to add.', 'fw' ) );
		}
		return array( '_back' => $back );
	}

	/**
	 * @param string $object
	 * @param int    $id
	 */
	public static function render_panel( $object, $id ) {
		$query = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt into an admin URL and escaped on output.
		$back  = remove_query_arg( array( 'fw_crm_action', '_wpnonce', 'attachment', 'object', 'object_id', 'back' ), admin_url( 'admin.php?' . $query ) );
		$files = FW_CRM_Files::for_record( $object, $id );
		$can   = current_user_can( 'upload_files' );
		$uid   = 'fw-crm-files-' . $object . '-' . (int) $id;
		?>
		<?php if ( $files ) : ?>
			<ul class="fw-crm-files">
				<?php foreach ( $files as $f ) : ?>
					<li class="fw-crm-file">
						<span class="fw-crm-file__icon" aria-hidden="true">
							<?php if ( 0 === strpos( $f['mime'], 'image/' ) && ( $thumb = wp_get_attachment_image_url( $f['id'], 'thumbnail' ) ) ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="" width="32" height="32" loading="lazy" />
							<?php else : ?>
								<span class="dashicons <?php echo esc_attr( FW_CRM_Files::icon( $f['mime'] ) ); ?>"></span>
							<?php endif; ?>
						</span>
						<span class="fw-crm-file__text">
							<a href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $f['title'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'fw' ); ?></span></a>
							<span class="fw-crm-file__meta">
								<?php echo esc_html( $f['size'] ? size_format( $f['size'] ) : '' ); ?>
								<?php if ( $f['added_at'] ) : ?>
									<?php echo $f['size'] ? ' · ' : ''; ?><?php echo FW_CRM_App::time_ago( $f['added_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by time_ago(). ?>
								<?php endif; ?>
							</span>
						</span>
						<a class="fw-crm-unlink" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'file_detach', 'object' => $object, 'object_id' => (int) $id, 'attachment' => $f['id'], 'back' => rawurlencode( $back ) ), FW_CRM_Admin_Page::get_page_url() ), FW_CRM_Admin_Page::NONCE ) ); ?>"
						   aria-label="<?php echo esc_attr( sprintf( __( 'Remove "%s" from this record', 'fw' ), $f['title'] ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="fw-crm-muted"><?php esc_html_e( 'No files yet. Add a quote, a contract or a photo so it is with the record.', 'fw' ); ?></p>
		<?php endif; ?>

		<?php if ( $can ) : ?>
			<form method="post" action="" enctype="multipart/form-data" class="fw-crm-files-add" data-files-form>
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="file_attach" />
				<input type="hidden" name="object" value="<?php echo esc_attr( $object ); ?>" />
				<input type="hidden" name="object_id" value="<?php echo (int) $id; ?>" />
				<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
				<input type="hidden" name="attachments" value="" data-files-picked />
				<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Files to upload', 'fw' ); ?></label>
				<input type="file" name="files[]" id="<?php echo esc_attr( $uid ); ?>" multiple data-files-input />
				<div class="fw-crm-files-add__buttons">
					<button type="submit" class="button" data-files-upload><?php esc_html_e( 'Upload', 'fw' ); ?></button>
					<button type="button" class="button-link" data-files-library data-button="<?php esc_attr_e( 'Add to the record', 'fw' ); ?>" hidden><?php esc_html_e( 'Choose from the media library', 'fw' ); ?></button>
				</div>
			</form>
			<p class="fw-crm-muted fw-crm-files-note">
				<?php
				echo esc_html( sprintf(
					/* translators: %s: maximum upload size */
					__( 'Up to %s each. Files are kept in the media library: anyone with a file\'s link can open it.', 'fw' ),
					size_format( wp_max_upload_size() )
				) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}
}
