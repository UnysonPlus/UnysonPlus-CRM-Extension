<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Import / Export: upload, map, check, import, undo; and export.
 *
 * Steps live in the URL (tab=tools&import=<id>&step=map|run|done), so a page
 * reload never loses the place. All writes go through FW_CRM_Import.
 */
class FW_CRM_Admin_Import {

	public static function init() {
		add_action( 'wp_ajax_fw_crm_import_tick', array( __CLASS__, '_ajax_tick' ) );
	}

	/**
	 * @param string $action
	 *
	 * @return array|null Redirect args when the action was ours.
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id = isset( $_REQUEST['import'] ) ? (int) $_REQUEST['import'] : 0;

		switch ( $action ) {
			case 'import_upload':
				$file = $_FILES['fw_crm_csv'] ?? array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'The upload failed. Choose a CSV file; it may be larger than this server allows.', 'fw' ) );
					return array( 'tab' => 'tools' );
				}
				$name = sanitize_file_name( (string) ( $file['name'] ?? 'import.csv' ) );
				if ( 'csv' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'That is not a .csv file.', 'fw' ) );
					return array( 'tab' => 'tools' );
				}
				$dir = function_exists( 'fw_upw_uploads_dir' ) ? fw_upw_uploads_dir( 'crm' ) : array( 'path' => get_temp_dir() );
				if ( ! file_exists( $dir['path'] ) ) {
					wp_mkdir_p( $dir['path'] );
				}
				// Not web-guessable, and an index.php keeps the folder from listing.
				if ( ! file_exists( trailingslashit( $dir['path'] ) . 'index.php' ) ) {
					file_put_contents( trailingslashit( $dir['path'] ) . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				$target = trailingslashit( $dir['path'] ) . 'import-' . get_current_user_id() . '-' . wp_generate_password( 16, false, false ) . '.csv';
				if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					FW_CRM_Admin_Page::flash( 'error', __( 'Could not store the uploaded file.', 'fw' ) );
					return array( 'tab' => 'tools' );
				}
				$new = FW_CRM_Import::create( $target, $name );
				if ( is_wp_error( $new ) ) {
					@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					FW_CRM_Admin_Page::flash( 'error', $new->get_error_message() );
					return array( 'tab' => 'tools' );
				}
				return array( 'tab' => 'tools', 'import' => $new, 'step' => 'map' );

			case 'import_map':
				$mapping = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['map'] ) ) : array();
				$options = array(
					'match'     => isset( $_POST['match'] ) ? sanitize_key( wp_unslash( $_POST['match'] ) ) : 'fill',
					'consent'   => ! empty( $_POST['consent'] ),
					'overwrite' => ! empty( $_POST['overwrite'] ),
					'lists'     => isset( $_POST['lists'] ) ? array_map( 'intval', (array) $_POST['lists'] ) : array(),
					'tags'      => isset( $_POST['tags'] ) ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['tags'] ) ) ) ) ) : array(),
				);
				$saved = FW_CRM_Import::configure( $id, $mapping, $options );
				if ( is_wp_error( $saved ) ) {
					FW_CRM_Admin_Page::flash( 'error', $saved->get_error_message() );
					return array( 'tab' => 'tools', 'import' => $id, 'step' => 'map' );
				}
				if ( isset( $_POST['go'] ) && 'import' === $_POST['go'] ) {
					$started = FW_CRM_Import::start( $id );
					if ( is_wp_error( $started ) ) {
						FW_CRM_Admin_Page::flash( 'error', $started->get_error_message() );
						return array( 'tab' => 'tools' );
					}
					return array( 'tab' => 'tools', 'import' => $id, 'step' => 'run' );
				}
				FW_CRM_Import::dry_run( $id );
				return array( 'tab' => 'tools', 'import' => $id, 'step' => 'map', 'checked' => 1 );

			case 'import_cancel':
				FW_CRM_Import::cancel( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Import stopped. What it already imported is kept, and can still be undone.', 'fw' ) );
				return array( 'tab' => 'tools' );

			case 'import_undo':
				$result = FW_CRM_Import::undo( $id );
				FW_CRM_Admin_Page::flash(
					is_wp_error( $result ) ? 'error' : 'success',
					is_wp_error( $result ) ? $result->get_error_message() : sprintf(
						/* translators: 1: contacts deleted, 2: contacts put back */
						__( 'Import undone: %1$s new contacts deleted, %2$s contacts put back as they were.', 'fw' ),
						number_format_i18n( $result['deleted'] ),
						number_format_i18n( $result['restored'] )
					)
				);
				return array( 'tab' => 'tools' );
		}
		// phpcs:enable

		return null;
	}

	/** @internal admin-ajax: run one chunk of a running import and report progress. */
	public static function _ajax_tick() {
		check_ajax_referer( 'fw_crm_import_tick' );
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_send_json_error( null, 403 );
		}
		$id     = isset( $_POST['import'] ) ? (int) $_POST['import'] : 0;
		$import = FW_CRM_Import::run_chunk( $id, (int) apply_filters( 'unysonplus_crm_import_seconds', 10 ) );
		if ( ! $import ) {
			wp_send_json_error( array( 'message' => __( 'Import not found.', 'fw' ) ) );
		}
		$s = $import->stats;
		wp_send_json_success( array(
			'status'  => $import->status,
			'line'    => max( 0, (int) $import->line - 1 ),
			'total'   => (int) $import->total_rows,
			'created' => (int) ( $s['created'] ?? 0 ),
			'updated' => (int) ( $s['updated'] ?? 0 ),
			'skipped' => (int) ( $s['skipped'] ?? 0 ) + (int) ( $s['unchanged'] ?? 0 ),
			'failed'  => (int) ( $s['failed'] ?? 0 ),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Screens
	 * ---------------------------------------------------------------------- */

	/** The Import / Export tab. */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$id   = isset( $_GET['import'] ) ? (int) $_GET['import'] : 0;
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable
		$import = $id ? FW_CRM_Import::find( $id ) : null;

		if ( $import && 'map' === $step && in_array( $import->status, array( 'mapping', 'checked' ), true ) ) {
			self::render_map( $import );
			return;
		}
		if ( $import && ( 'run' === $step || 'done' === $step ) ) {
			self::render_run( $import );
			return;
		}
		self::render_home();
	}

	/** Upload, export and history. */
	private static function render_home() {
		$base = FW_CRM_Admin_Page::get_page_url();
		?>
		<div class="fw-crm-import-home">
			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-import">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-import"><?php esc_html_e( 'Import contacts', 'fw' ); ?></h2></header>
				<p class="fw-crm-muted"><?php esc_html_e( 'Upload a CSV with a header row. Next you match its columns to CRM fields, choose what happens to people already in the CRM, and can check the result before anything is saved. Every import can be undone afterwards.', 'fw' ); ?></p>
				<form method="post" action="" enctype="multipart/form-data" class="fw-crm-inline">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="import_upload" />
					<label class="screen-reader-text" for="fw-crm-csv"><?php esc_html_e( 'CSV file', 'fw' ); ?></label>
					<input type="file" id="fw-crm-csv" name="fw_crm_csv" accept=".csv,text/csv" required />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and continue', 'fw' ); ?></button>
				</form>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-export">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-export"><?php esc_html_e( 'Export contacts', 'fw' ); ?></h2></header>
				<p class="fw-crm-muted"><?php esc_html_e( 'A CSV of everyone, or of one segment, with their details, stage, company, lists, tags and your own fields.', 'fw' ); ?></p>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fw-crm-inline">
					<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
					<input type="hidden" name="tab" value="tools" />
					<input type="hidden" name="fw_crm_action" value="export" />
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE, '_wpnonce', false ); ?>
					<label class="screen-reader-text" for="fw-crm-export-seg"><?php esc_html_e( 'Who to export', 'fw' ); ?></label>
					<select id="fw-crm-export-seg" name="segment">
						<option value=""><?php esc_html_e( 'Everyone', 'fw' ); ?></option>
						<?php foreach ( FW_CRM_Lists::segments() as $seg ) : ?>
							<option value="<?php echo (int) $seg->id; ?>"><?php echo esc_html( $seg->title ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Download CSV', 'fw' ); ?></button>
				</form>
			</section>
		</div>

		<?php $history = FW_CRM_Import::history( 25 ); ?>
		<?php if ( $history ) : ?>
			<section class="fw-crm-panel fw-crm-import-history" aria-labelledby="fw-crm-h-history">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-history"><?php esc_html_e( 'Past imports', 'fw' ); ?></h2></header>
				<table class="widefat striped fw-crm-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'File', 'fw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When', 'fw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'By', 'fw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'fw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'fw' ); ?></th>
							<th scope="col"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $imp ) : ?>
							<?php
							$who = get_userdata( (int) $imp->user_id );
							$s   = $imp->stats;
							?>
							<tr>
								<td><strong><?php echo esc_html( $imp->filename ); ?></strong></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $imp->created_at ) ) ); ?></td>
								<td><?php echo $who ? esc_html( $who->display_name ) : '<span aria-hidden="true">—</span>'; ?></td>
								<td><?php echo esc_html( self::summary( $s ) ); ?></td>
								<td><span class="fw-crm-status fw-crm-status--<?php echo esc_attr( self::status_class( $imp->status ) ); ?>"><?php echo esc_html( self::status_label( $imp->status ) ); ?></span></td>
								<td>
									<?php if ( 'running' === $imp->status ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'tools', 'import' => (int) $imp->id, 'step' => 'run' ), $base ) ); ?>"><?php esc_html_e( 'Watch', 'fw' ); ?></a>
									<?php elseif ( in_array( $imp->status, array( 'done', 'cancelled' ), true ) ) : ?>
										<a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'tools', 'fw_crm_action' => 'import_undo', 'import' => (int) $imp->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"
										   onclick="return confirm('<?php echo esc_js( __( 'Undo this import? Contacts it added are deleted, contacts it changed are put back, and the lists and tags it added are removed.', 'fw' ) ); ?>');"><?php esc_html_e( 'Undo', 'fw' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Map columns, choose options, check, import.
	 *
	 * @param object $import
	 */
	private static function render_map( $import ) {
		$fields  = FW_CRM_Import::fields();
		$groups  = array();
		foreach ( $fields as $key => $f ) {
			$groups[ $f['group'] ][ $key ] = $f['label'];
		}
		$opts    = $import->options + array( 'match' => 'fill', 'consent' => false, 'overwrite' => false, 'lists' => array(), 'tags' => array() );
		$checked = isset( $_GET['checked'] ) && 'checked' === $import->status; // phpcs:ignore WordPress.Security.NonceVerification
		$dry     = $checked ? json_decode( (string) ( $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT dry_stats FROM ' . FW_CRM_Installer::table( 'imports' ) . ' WHERE id = %d', (int) $import->id ) ) ), true ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
		?>
		<form method="post" action="" class="fw-crm-import-map">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="import_map" />
			<input type="hidden" name="import" value="<?php echo (int) $import->id; ?>" />

			<?php if ( is_array( $dry ) ) : ?>
				<section class="fw-crm-panel fw-crm-dry" aria-live="polite">
					<header class="fw-crm-panel__head"><h2><?php esc_html_e( 'Check result: nothing has been saved yet', 'fw' ); ?></h2></header>
					<ul class="fw-crm-dry__counts">
						<?php
						$left = (int) $dry['unchanged'] + (int) $dry['skipped'];
						$rows = array(
							array( (int) $dry['created'], _n( 'new contact', 'new contacts', (int) $dry['created'], 'fw' ), '' ),
							array( (int) $dry['updated'], _n( 'existing contact updated', 'existing contacts updated', (int) $dry['updated'], 'fw' ), '' ),
							array( $left, __( 'left as they are', 'fw' ), '' ),
							array( (int) $dry['failed'], _n( 'row with a problem', 'rows with a problem', (int) $dry['failed'], 'fw' ), (int) $dry['failed'] ? 'is-bad' : '' ),
						);
						foreach ( $rows as $r ) :
							?>
							<li class="<?php echo esc_attr( $r[2] ); ?>"><strong><?php echo esc_html( number_format_i18n( $r[0] ) ); ?></strong> <?php echo esc_html( $r[1] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! empty( $dry['skip_reasons'] ) ) : ?>
						<p class="fw-crm-muted">
							<?php
							$parts = array();
							foreach ( $dry['skip_reasons'] as $why => $n ) {
								$parts[] = sprintf( '%s: %s', FW_CRM_Import::skip_label( $why ), number_format_i18n( $n ) );
							}
							echo esc_html( sprintf( __( 'Skipped because %s.', 'fw' ), implode( '; ', $parts ) ) );
							?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $dry['errors'] ) ) : ?>
						<ul class="fw-crm-dry__errors">
							<?php foreach ( $dry['errors'] as $e ) : ?>
								<li><?php echo esc_html( $e ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( empty( $dry['complete'] ) ) : ?>
						<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'Checked the first %s rows; the file is too large to check in one go.', 'fw' ), number_format_i18n( (int) $dry['rows_checked'] ) ) ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-map">
				<header class="fw-crm-panel__head">
					<h2 id="fw-crm-h-map"><?php echo esc_html( sprintf( __( 'Match the columns of %s', 'fw' ), $import->filename ) ); ?></h2>
					<span class="fw-crm-muted"><?php echo esc_html( sprintf( _n( '%s row', '%s rows', (int) $import->total_rows, 'fw' ), number_format_i18n( (int) $import->total_rows ) ) ); ?></span>
				</header>
				<table class="widefat fw-crm-map-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Column in the file', 'fw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Examples', 'fw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Goes into', 'fw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $import->header as $i => $label ) : ?>
							<?php
							$examples = array();
							foreach ( (array) $import->samples as $sample ) {
								if ( isset( $sample[ $i ] ) && '' !== trim( (string) $sample[ $i ] ) ) {
									$examples[] = (string) $sample[ $i ];
								}
							}
							$current = $import->mapping[ $i ] ?? '';
							?>
							<tr>
								<th scope="row"><label for="fw-crm-map-<?php echo (int) $i; ?>"><?php echo esc_html( (string) $label ); ?></label></th>
								<td class="fw-crm-muted"><?php echo esc_html( implode( ', ', array_slice( $examples, 0, 3 ) ) ); ?></td>
								<td>
									<select id="fw-crm-map-<?php echo (int) $i; ?>" name="map[<?php echo (int) $i; ?>]">
										<option value=""><?php esc_html_e( 'Do not import', 'fw' ); ?></option>
										<?php foreach ( $groups as $group => $options ) : ?>
											<optgroup label="<?php echo esc_attr( $group ); ?>">
												<?php foreach ( $options as $key => $text ) : ?>
													<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $text ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-opts">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-opts"><?php esc_html_e( 'People already in the CRM', 'fw' ); ?></h2></header>
				<p class="fw-crm-muted"><?php esc_html_e( 'A row matches a contact by email, or by phone number when it has no email.', 'fw' ); ?></p>
				<fieldset class="fw-crm-choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'People already in the CRM', 'fw' ); ?></legend>
					<?php
					foreach ( array(
						'fill'   => __( 'Fill in what is missing (keep what the CRM already has)', 'fw' ),
						'update' => __( 'Update them with the values in the file', 'fw' ),
						'skip'   => __( 'Leave them alone; only add new people', 'fw' ),
					) as $value => $text ) :
						?>
						<label class="fw-crm-check"><input type="radio" name="match" value="<?php echo esc_attr( $value ); ?>" <?php checked( $opts['match'], $value ); ?> /> <?php echo esc_html( $text ); ?></label>
					<?php endforeach; ?>
				</fieldset>

				<h3><?php esc_html_e( 'Lists, tags and email', 'fw' ); ?></h3>
				<?php $lists = FW_CRM_Lists::all( 'list' ); ?>
				<?php if ( $lists ) : ?>
					<fieldset class="fw-crm-choices">
						<legend><?php esc_html_e( 'Add everyone to these lists', 'fw' ); ?></legend>
						<?php foreach ( $lists as $l ) : ?>
							<label class="fw-crm-check"><input type="checkbox" name="lists[]" value="<?php echo (int) $l->id; ?>" <?php checked( in_array( (int) $l->id, (array) $opts['lists'], true ) ); ?> /> <?php echo esc_html( $l->title ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<label class="fw-crm-form__row">
					<span><?php esc_html_e( 'Tag everyone with', 'fw' ); ?></span>
					<input type="text" name="tags" value="<?php echo esc_attr( implode( ', ', (array) $opts['tags'] ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. trade-show-2026 (comma separated)', 'fw' ); ?>" />
				</label>
				<label class="fw-crm-check fw-crm-consent-check">
					<input type="checkbox" name="consent" value="1" <?php checked( ! empty( $opts['consent'] ) ); ?> />
					<span><strong><?php esc_html_e( 'Everyone in this file agreed to receive email from you', 'fw' ); ?></strong><br />
					<span class="fw-crm-muted"><?php esc_html_e( 'Ticking this subscribes them, and records your confirmation in each person\'s consent log. Leave it unticked for a list of leads or customers who never signed up: they are added as contacts you can call or write to, but no campaign is sent to them.', 'fw' ); ?></span></span>
				</label>
				<label class="fw-crm-check">
					<input type="checkbox" name="overwrite" value="1" <?php checked( ! empty( $opts['overwrite'] ) ); ?> />
					<span><?php esc_html_e( 'Also put back people who unsubscribed or left a list (only if they asked to be added again)', 'fw' ); ?></span>
				</label>
			</section>

			<p class="fw-crm-form__foot">
				<button type="submit" name="go" value="check" class="button"><?php esc_html_e( 'Check first (saves nothing)', 'fw' ); ?></button>
				<button type="submit" name="go" value="import" class="button button-primary"><?php esc_html_e( 'Import now', 'fw' ); ?></button>
				<a class="button-link" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'tools', 'fw_crm_action' => 'import_cancel', 'import' => (int) $import->id ), FW_CRM_Admin_Page::get_page_url() ), FW_CRM_Admin_Page::NONCE ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Progress (while running) and the result (when finished).
	 *
	 * @param object $import
	 */
	private static function render_run( $import ) {
		$base    = FW_CRM_Admin_Page::get_page_url();
		$running = 'running' === $import->status;
		$s       = $import->stats;
		$pct     = (int) $import->total_rows ? min( 100, (int) round( 100 * max( 0, (int) $import->line - 1 ) / (int) $import->total_rows ) ) : 0;
		?>
		<section class="fw-crm-panel fw-crm-import-run" id="fw-crm-import-run"
		         data-import="<?php echo (int) $import->id; ?>"
		         data-running="<?php echo $running ? '1' : '0'; ?>"
		         data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		         data-nonce="<?php echo esc_attr( wp_create_nonce( 'fw_crm_import_tick' ) ); ?>"
		         data-done-url="<?php echo esc_url( add_query_arg( array( 'tab' => 'tools', 'import' => (int) $import->id, 'step' => 'done' ), $base ) ); ?>">
			<header class="fw-crm-panel__head">
				<h2><?php echo esc_html( sprintf( $running ? __( 'Importing %s', 'fw' ) : __( 'Import of %s', 'fw' ), $import->filename ) ); ?></h2>
				<span class="fw-crm-status fw-crm-status--<?php echo esc_attr( self::status_class( $import->status ) ); ?>"><?php echo esc_html( self::status_label( $import->status ) ); ?></span>
			</header>
			<?php if ( $running ) : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'You can leave this page: the import carries on in the background and its result appears under Past imports.', 'fw' ); ?></p>
			<?php endif; ?>
			<div class="fw-crm-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Import progress', 'fw' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) ( $running ? $pct : 100 ); ?>">
				<div class="fw-crm-bar__fill" style="width:<?php echo (int) ( $running ? $pct : 100 ); ?>%"></div>
			</div>
			<p class="fw-crm-import-counts" aria-live="polite"><?php echo esc_html( self::summary( $s ) ); ?></p>
			<?php if ( ! $running && ! empty( $s['errors'] ) ) : ?>
				<ul class="fw-crm-dry__errors">
					<?php foreach ( $s['errors'] as $e ) : ?>
						<li><?php echo esc_html( $e ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="fw-crm-form__foot">
				<?php if ( $running ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'tools', 'fw_crm_action' => 'import_cancel', 'import' => (int) $import->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"><?php esc_html_e( 'Stop', 'fw' ); ?></a>
				<?php else : ?>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'tab', 'subscribers', $base ) ); ?>"><?php esc_html_e( 'See the contacts', 'fw' ); ?></a>
					<?php if ( in_array( $import->status, array( 'done', 'cancelled' ), true ) ) : ?>
						<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'tools', 'fw_crm_action' => 'import_undo', 'import' => (int) $import->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"
						   onclick="return confirm('<?php echo esc_js( __( 'Undo this import? Contacts it added are deleted, contacts it changed are put back, and the lists and tags it added are removed.', 'fw' ) ); ?>');"><?php esc_html_e( 'Undo this import', 'fw' ); ?></a>
					<?php endif; ?>
					<a class="button-link" href="<?php echo esc_url( add_query_arg( 'tab', 'tools', $base ) ); ?>"><?php esc_html_e( 'Back to Import / Export', 'fw' ); ?></a>
				<?php endif; ?>
			</p>
		</section>
		<?php if ( $running ) : ?>
			<script>
			( function () {
				var box = document.getElementById( 'fw-crm-import-run' );
				var bar = box.querySelector( '.fw-crm-bar' );
				var fill = box.querySelector( '.fw-crm-bar__fill' );
				var out = box.querySelector( '.fw-crm-import-counts' );
				var t = <?php echo wp_json_encode( array(
					'counts' => __( '%1$s of %2$s rows: %3$s new, %4$s updated, %5$s left as they are, %6$s with a problem', 'fw' ),
				) ); ?>;
				function fmt( s, a ) {
					return s.replace( /%(\d)\$s/g, function ( m, n ) { return String( a[ n - 1 ] ); } );
				}
				function tick() {
					var body = new FormData();
					body.append( 'action', 'fw_crm_import_tick' );
					body.append( '_ajax_nonce', box.dataset.nonce );
					body.append( 'import', box.dataset.import );
					fetch( box.dataset.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) { setTimeout( tick, 3000 ); return; }
							var d = res.data;
							var pct = d.total ? Math.min( 100, Math.round( 100 * d.line / d.total ) ) : 0;
							fill.style.width = pct + '%';
							bar.setAttribute( 'aria-valuenow', pct );
							out.textContent = fmt( t.counts, [ d.line, d.total, d.created, d.updated, d.skipped, d.failed ] );
							if ( 'running' === d.status ) {
								setTimeout( tick, 300 );
							} else {
								window.location.href = box.dataset.doneUrl;
							}
						} )
						.catch( function () { setTimeout( tick, 3000 ); } );
				}
				tick();
			}() );
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array $s
	 *
	 * @return string
	 */
	private static function summary( array $s ) {
		return sprintf(
			/* translators: 1: new, 2: updated, 3: unchanged or skipped, 4: failed */
			__( '%1$s new, %2$s updated, %3$s left as they are, %4$s with a problem', 'fw' ),
			number_format_i18n( (int) ( $s['created'] ?? 0 ) ),
			number_format_i18n( (int) ( $s['updated'] ?? 0 ) ),
			number_format_i18n( (int) ( $s['unchanged'] ?? 0 ) + (int) ( $s['skipped'] ?? 0 ) ),
			number_format_i18n( (int) ( $s['failed'] ?? 0 ) )
		);
	}

	/**
	 * @param string $status
	 *
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'mapping'   => __( 'Not started', 'fw' ),
			'checked'   => __( 'Checked', 'fw' ),
			'running'   => __( 'Running', 'fw' ),
			'done'      => __( 'Finished', 'fw' ),
			'cancelled' => __( 'Stopped', 'fw' ),
			'failed'    => __( 'Failed', 'fw' ),
			'undone'    => __( 'Undone', 'fw' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Reuse the existing status pill colours.
	 *
	 * @param string $status
	 *
	 * @return string
	 */
	private static function status_class( $status ) {
		$map = array( 'done' => 'sent', 'running' => 'sending', 'cancelled' => 'paused', 'failed' => 'bounced', 'undone' => 'draft', 'checked' => 'draft', 'mapping' => 'draft' );
		return $map[ $status ] ?? 'draft';
	}
}
