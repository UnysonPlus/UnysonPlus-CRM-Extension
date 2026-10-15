<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Custom fields (managers): the fields on contacts, companies and deals.
 *
 *   ?tab=fields&object=contact|company|deal&edit=<key>|new
 */
class FW_CRM_Admin_Fields {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'fields' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
	}

	/** @return array object => label */
	private static function objects() {
		$out = array( 'contact' => __( 'Contacts', 'fw' ) );
		if ( FW_CRM_Modules::enabled( 'companies' ) ) {
			$out['company'] = __( 'Companies', 'fw' );
		}
		if ( class_exists( 'FW_CRM_Deals' ) ) {
			$out['deal'] = __( 'Deals', 'fw' );
		}
		return $out;
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || ! in_array( $action, array( 'field_save', 'field_delete' ), true ) ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$object = isset( $_REQUEST['object'] ) ? sanitize_key( wp_unslash( $_REQUEST['object'] ) ) : 'contact';
		$object = isset( self::objects()[ $object ] ) ? $object : 'contact';
		$key    = isset( $_REQUEST['key'] ) ? sanitize_key( wp_unslash( $_REQUEST['key'] ) ) : '';
		$back   = array( 'tab' => 'fields', 'object' => $object );
		$def    = '' !== $key ? FW_CRM_Fields::definition( $object, $key ) : null;
		if ( $def && ! empty( $def['managed_by'] ) ) {
			wp_die( esc_html__( 'This field is managed by another extension.', 'fw' ), 403 );
		}

		if ( 'field_delete' === $action ) {
			FW_CRM_Fields::delete_definition( $object, $key );
			FW_CRM_Admin_Page::flash( 'success', __( 'Field deleted, with the values stored in it.', 'fw' ) );
			return $back;
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text';
		$res  = FW_CRM_Fields::save_definition( array(
			'object'   => $object,
			'key'      => $def ? $key : ( isset( $_POST['new_key'] ) ? sanitize_key( wp_unslash( $_POST['new_key'] ) ) : '' ),
			'label'    => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by save_definition().
			'type'     => $def ? $def['type'] : $type, // The type of a field with values cannot change.
			'group'    => isset( $_POST['group'] ) ? wp_unslash( $_POST['group'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'required' => ! empty( $_POST['required'] ),
			'choices'  => isset( $_POST['choices'] ) ? preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['choices'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'settings' => array(
				'target' => isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'contact',
				'prefix' => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '',
				'pad'    => isset( $_POST['pad'] ) ? (int) $_POST['pad'] : 4,
			),
		) );
		// phpcs:enable
		if ( is_wp_error( $res ) ) {
			FW_CRM_Admin_Page::flash( 'error', $res->get_error_message() );
			return array_merge( $back, array( 'edit' => $def ? $key : 'new' ) );
		}
		FW_CRM_Admin_Page::flash( 'success', __( 'Field saved.', 'fw' ) );
		return $back;
	}

	public static function render() {
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'For CRM managers', 'fw' ), __( 'Custom fields are set up by CRM managers and administrators.', 'fw' ) );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$objects = self::objects();
		$object  = isset( $_GET['object'] ) && isset( $objects[ $_GET['object'] ] ) ? sanitize_key( $_GET['object'] ) : 'contact';
		$edit    = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:enable
		$base    = FW_CRM_Admin_Page::get_page_url( 'fields' );
		$fields  = FW_CRM_Fields::definitions( $object );
		$types   = FW_CRM_Fields::types();
		$current = '' !== $edit && 'new' !== $edit ? FW_CRM_Fields::definition( $object, $edit ) : null;
		?>
		<nav class="fw-crm-seg fw-crm-fields__objects" aria-label="<?php esc_attr_e( 'Record type', 'fw' ); ?>">
			<?php foreach ( $objects as $o => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'object', $o, $base ) ); ?>" <?php echo $o === $object ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="fw-crm-fields">
			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-fields">
				<header class="fw-crm-panel__head">
					<h2 id="fw-crm-h-fields"><?php echo esc_html( sprintf( __( 'Fields on %s', 'fw' ), strtolower( $objects[ $object ] ) ) ); ?></h2>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'object' => $object, 'edit' => 'new' ), $base ) ); ?>"><?php esc_html_e( 'Add a field', 'fw' ); ?></a>
				</header>
				<?php if ( $fields ) : ?>
					<div class="fw-crm-table-scroll">
						<table class="fw-crm-report-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Label', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Key', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'fw' ); ?></th><th scope="col"><?php esc_html_e( 'Group', 'fw' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $fields as $f ) : ?>
									<tr>
										<th scope="row">
											<?php if ( empty( $f['managed_by'] ) ) : ?>
												<a href="<?php echo esc_url( add_query_arg( array( 'object' => $object, 'edit' => $f['key'] ), $base ) ); ?>"><?php echo esc_html( $f['label'] ); ?></a>
											<?php else : ?>
												<?php echo esc_html( $f['label'] ); ?>
												<span class="fw-crm-pill"><?php echo esc_html( FW_CRM_App::managed_label( $f['managed_by'] ) ); ?></span>
											<?php endif; ?>
											<?php if ( ! empty( $f['required'] ) ) : ?>
												<span class="fw-crm-pill"><?php esc_html_e( 'Required', 'fw' ); ?></span>
											<?php endif; ?>
										</th>
										<td><code><?php echo esc_html( $f['key'] ); ?></code></td>
										<td><?php echo esc_html( $types[ $f['type'] ]['label'] ?? $f['type'] ); ?></td>
										<td><?php echo esc_html( (string) ( $f['group'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'No custom fields yet. Add what your business tracks that the CRM does not: a membership number, a preferred service, a renewal date.', 'fw' ); ?></p>
				<?php endif; ?>
			</section>

			<?php if ( $current || 'new' === $edit ) : ?>
				<?php
				$t = $current ? $current['type'] : 'text';
				?>
				<section class="fw-crm-panel" aria-labelledby="fw-crm-h-field-edit">
					<header class="fw-crm-panel__head"><h2 id="fw-crm-h-field-edit"><?php echo $current ? esc_html( $current['label'] ) : esc_html__( 'New field', 'fw' ); ?></h2></header>
					<form method="post" action="" class="fw-crm-form" data-field-form>
						<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_crm_action" value="field_save" />
						<input type="hidden" name="object" value="<?php echo esc_attr( $object ); ?>" />
						<input type="hidden" name="key" value="<?php echo esc_attr( $current ? $current['key'] : '' ); ?>" />
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Label', 'fw' ); ?></span><input type="text" name="label" required value="<?php echo esc_attr( $current ? $current['label'] : '' ); ?>" data-field-label /></label>
						<?php if ( $current ) : ?>
							<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'Key: %1$s · Type: %2$s (the type cannot change once a field exists).', 'fw' ), $current['key'], $types[ $t ]['label'] ?? $t ) ); ?></p>
						<?php else : ?>
							<div class="fw-crm-task-form__row">
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Key (for imports and the API)', 'fw' ); ?></span><input type="text" name="new_key" required pattern="[a-z][a-z0-9_]{0,63}" data-field-key /></label>
								<label class="fw-crm-form__row"><span><?php esc_html_e( 'Type', 'fw' ); ?></span>
									<select name="type" data-field-type>
										<?php foreach ( $types as $k => $info ) : ?>
											<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $info['label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>
						<?php endif; ?>
						<label class="fw-crm-form__row" data-for-field="choice multichoice"<?php echo in_array( $t, array( 'choice', 'multichoice' ), true ) ? '' : ' hidden'; ?>><span><?php esc_html_e( 'Choices (one per line)', 'fw' ); ?></span><textarea name="choices" rows="5"><?php echo esc_textarea( $current ? implode( "\n", (array) $current['choices'] ) : '' ); ?></textarea></label>
						<label class="fw-crm-form__row" data-for-field="lookup"<?php echo 'lookup' === $t ? '' : ' hidden'; ?>><span><?php esc_html_e( 'Links to', 'fw' ); ?></span>
							<select name="target">
								<?php foreach ( array( 'contact' => __( 'A contact', 'fw' ), 'company' => __( 'A company', 'fw' ), 'deal' => __( 'A deal', 'fw' ), 'post' => __( 'A page or post', 'fw' ), 'user' => __( 'A WordPress user', 'fw' ) ) as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $current['settings']['target'] ?? '', $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<div class="fw-crm-task-form__row" data-for-field="autonumber"<?php echo 'autonumber' === $t ? '' : ' hidden'; ?>>
							<label class="fw-crm-form__row"><span><?php esc_html_e( 'Prefix', 'fw' ); ?></span><input type="text" name="prefix" value="<?php echo esc_attr( $current['settings']['prefix'] ?? '' ); ?>" placeholder="WL-" /></label>
							<label class="fw-crm-form__row"><span><?php esc_html_e( 'Digits', 'fw' ); ?></span><input type="number" name="pad" min="0" max="12" value="<?php echo (int) ( $current['settings']['pad'] ?? 4 ); ?>" /></label>
						</div>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Group (optional)', 'fw' ); ?></span><input type="text" name="group" value="<?php echo esc_attr( $current['group'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Preferences', 'fw' ); ?>" /></label>
						<label class="fw-crm-check"><input type="checkbox" name="required" value="1" <?php checked( ! empty( $current['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'fw' ); ?></label>
						<p class="fw-crm-form__foot">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save field', 'fw' ); ?></button>
							<?php if ( $current ) : ?>
								<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'field_delete', 'object' => $object, 'key' => $current['key'] ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this field and every value stored in it?', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
