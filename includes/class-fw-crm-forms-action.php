<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Forms → CRM bridge: the "Add to the CRM" action for the Forms extension
 * (add the sender as a contact, save their answers, subscribe them only with
 * consent), plus a "Form entries" activity panel on the contact screen.
 *
 * Registered through Forms' own `fw_ext_forms_actions` filter, so Forms never
 * references this extension; deactivate the CRM and the action is simply not
 * offered. Everything here is guarded on the Forms classes existing, so the CRM
 * keeps working on a site without Forms.
 *
 * Adding someone as a contact is not subscribing them: a contact added by a form has
 * the status "none" and receives no campaign.
 *
 * The rule that cannot be bent: NOTHING here subscribes anyone without a ticked
 * Consent field whose purpose is "newsletter". Not an email field, not a label
 * that says "newsletter", not the action being switched on. The consent field
 * is a dedicated form-builder type precisely so this check is by TYPE.
 * `FW_Option_Type_Form_Builder_Item_Consent::newsletter_consent_given()` is the
 * one test, and it is Forms' — the CRM does not reinterpret it.
 */
class FW_CRM_Forms_Bridge {

	public function __construct() {
		require_once dirname( __FILE__ ) . '/forms/class-fw-crm-form-map.php';
		add_filter( 'fw_ext_forms_actions', array( $this, '_register_action' ) );
		add_action( 'unysonplus_crm_subscriber_view_after', array( $this, '_activity_panel' ) );
		// "New custom field" answers get their field as soon as the page is saved.
		add_action( 'added_post_meta', array( 'FW_CRM_Form_Map', '_on_builder_saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( 'FW_CRM_Form_Map', '_on_builder_saved' ), 10, 4 );
		// The mapping table in the contact form's Settings tab, wherever the form builder loads.
		add_action( 'admin_footer', array( $this, '_enqueue_map_script' ), 5 );
		add_action( 'wp_footer', array( $this, '_enqueue_map_script' ), 5 );
	}

	/**
	 * @internal
	 */
	public function _enqueue_map_script() {
		if ( ! wp_script_is( 'fw-builder-form-builder', 'enqueued' ) || ! FW_CRM_Capabilities::can_manage() && ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ext = fw_ext( 'crm' );
		wp_enqueue_script( 'fw-crm-form-map', $ext->get_uri( '/static/js/form-map.js' ), array( 'jquery' ), $ext->manifest->get_version(), true );
		wp_enqueue_style( 'fw-crm-form-map', $ext->get_uri( '/static/css/form-map.css' ), array(), $ext->manifest->get_version() );
	}

	/**
	 * @internal
	 */
	public function _register_action( $actions ) {
		if ( class_exists( 'FW_Forms_Action' ) ) {
			// Kept in a subfolder on purpose: the framework auto-includes every file directly in
			// includes/, and this class extends a Forms class, so loading it with Forms inactive
			// would be a fatal error for the whole site.
			require_once dirname( __FILE__ ) . '/forms/class-fw-crm-forms-action-subscribe.php';
			$actions[] = new FW_CRM_Forms_Action_Subscribe();
		}

		return $actions;
	}

	/**
	 * @internal
	 * The subscriber's form entries — Activity, from the person's side. A read
	 * across the join by email; the CRM never writes entries.
	 *
	 * @param object $subscriber
	 */
	public function _activity_panel( $subscriber ) {
		if ( ! class_exists( 'FW_Forms_Entries' ) || empty( $subscriber->email ) ) {
			return;
		}

		$entries = FW_Forms_Entries::by_email( $subscriber->email, 25 );

		if ( ! $entries ) {
			return;
		}

		$view = class_exists( 'FW_Forms_Entries_Admin' ) ? 'FW_Forms_Entries_Admin::entry_url' : null;
		?>
		<h2 style="margin-top:1.5em"><?php esc_html_e( 'Form entries', 'fw' ); ?> <span class="count">(<?php echo count( $entries ); ?>)</span></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Form', 'fw' ); ?></th><th><?php esc_html_e( 'Summary', 'fw' ); ?></th><th><?php esc_html_e( 'Submitted', 'fw' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $entries as $e ) : ?>
				<tr>
					<td><?php if ( $view ) : ?><a href="<?php echo esc_url( call_user_func( $view, $e->id ) ); ?>"><?php endif; ?><?php echo esc_html( '' !== $e->form_title ? $e->form_title : $e->form_id ); ?><?php if ( $view ) : ?></a><?php endif; ?></td>
					<td>
						<?php
						$bits = array();
						foreach ( $e->fields as $f ) {
							if ( 'email' === $f['type'] || '' === $f['value'] || array() === $f['value'] || 'hidden' === $f['type'] ) { continue; }
							$v      = is_array( $f['value'] ) ? implode( ', ', $f['value'] ) : $f['value'];
							$bits[] = esc_html( $f['label'] ) . ': ' . esc_html( wp_html_excerpt( $v, 40, '…' ) );
							if ( count( $bits ) >= 3 ) { break; }
						}
						echo implode( ' · ', $bits ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
						?>
					</td>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $e->created_at ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
