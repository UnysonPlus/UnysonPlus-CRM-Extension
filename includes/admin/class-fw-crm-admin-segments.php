<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The segment builder: CRM → Lists & segments → New segment / Edit.
 *
 * The page holds the rule tree as JSON in a hidden field; static/js/segments.js
 * draws the rules from it and from the field registry, and asks
 * `wp_ajax_fw_crm_segment_preview` for the live count as rules change. Saving goes
 * through FW_CRM_Segments::save(), the same path as the REST endpoint.
 */
class FW_CRM_Admin_Segments {

	public static function init() {
		add_action( 'wp_ajax_fw_crm_segment_preview', array( __CLASS__, '_ajax_preview' ) );
	}

	/**
	 * @param string $action
	 *
	 * @return array|null Redirect args when the action was ours.
	 */
	public static function handle( $action ) {
		if ( 'save_rule_segment' !== $action ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$title = isset( $_POST['segment_title'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_title'] ) ) : '';
		$rules = isset( $_POST['rules_json'] ) ? json_decode( wp_unslash( $_POST['rules_json'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by FW_CRM_Segments::sanitize().
		// phpcs:enable

		$result = FW_CRM_Segments::save( $title, is_array( $rules ) ? $rules : array(), $id );
		if ( is_wp_error( $result ) ) {
			FW_CRM_Admin_Page::flash( 'error', $result->get_error_message() );
			set_transient( 'fw_crm_segment_draft_' . get_current_user_id(), array( 'title' => $title, 'rules' => $rules ), MINUTE_IN_SECONDS );
			return array( 'tab' => 'lists', 'edit_segment' => $id ? $id : 'new' );
		}

		FW_CRM_Admin_Page::flash( 'success', sprintf(
			/* translators: 1: segment name, 2: number of contacts */
			__( 'Segment "%1$s" saved. It matches %2$s contacts right now.', 'fw' ),
			$result->title,
			number_format_i18n( FW_CRM_Segments::count( $result, true ) )
		) );

		return array( 'tab' => 'lists' );
	}

	/** @internal admin-ajax: count + sentence for an unsaved tree. */
	public static function _ajax_preview() {
		check_ajax_referer( 'fw_crm_segment_preview' );
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_send_json_error( null, 403 );
		}
		$rules = isset( $_POST['rules'] ) ? json_decode( wp_unslash( $_POST['rules'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		$tree  = FW_CRM_Segments::sanitize( is_array( $rules ) ? $rules : array() );

		wp_send_json_success( array(
			'count'    => $tree['rules'] ? FW_CRM_Segments::preview_count( $tree ) : null,
			'describe' => $tree['rules'] ? FW_CRM_Segments::describe( $tree ) : '',
		) );
	}

	/**
	 * The builder page.
	 *
	 * @param int|string $id A segment id or "new".
	 */
	public static function render_editor( $id ) {
		$segment = 'new' !== $id ? FW_CRM_Lists::find_segment( (int) $id ) : null;
		$title   = $segment ? $segment->title : '';
		$rules   = array( 'match' => 'all', 'rules' => array() );
		$legacy  = false;

		if ( $segment ) {
			$filters = FW_CRM_Lists::segment_query_args( $segment );
			if ( ! empty( $filters['rules'] ) ) {
				$rules = $filters['rules'];
			} else {
				// A segment saved from the contacts list's filters: offer it as rules.
				$rules  = self::rules_from_filters( $filters );
				$legacy = true;
			}
		}

		$draft = get_transient( 'fw_crm_segment_draft_' . get_current_user_id() );
		if ( $draft ) {
			delete_transient( 'fw_crm_segment_draft_' . get_current_user_id() );
			$title = (string) $draft['title'];
			$rules = is_array( $draft['rules'] ) ? $draft['rules'] : $rules;
		}

		$config = array(
			'fields'    => FW_CRM_Segments::fields(),
			'operators' => FW_CRM_Segments::operators(),
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'fw_crm_segment_preview' ),
			'i18n'      => array(
				'match_all'   => __( 'all', 'fw' ),
				'match_any'   => __( 'any', 'fw' ),
				'match'       => __( 'Contacts who match %s of these rules', 'fw' ),
				'group_match' => __( '%s of:', 'fw' ),
				'add_rule'    => __( 'Add rule', 'fw' ),
				'add_group'   => __( 'Add a group (and / or)', 'fw' ),
				'remove'      => __( 'Remove this rule', 'fw' ),
				'remove_grp'  => __( 'Remove this group', 'fw' ),
				'field'       => __( 'Field', 'fw' ),
				'operator'    => __( 'Condition', 'fw' ),
				'value'       => __( 'Value', 'fw' ),
				'days'        => __( 'days', 'fw' ),
				'pick'        => __( 'Choose a field…', 'fw' ),
				'count'       => __( '%s contacts match', 'fw' ),
				'count_one'   => __( '1 contact matches', 'fw' ),
				'counting'    => __( 'Counting…', 'fw' ),
				'empty'       => __( 'Add a rule to see who matches.', 'fw' ),
				'none_yet'    => __( 'None yet — create one under Lists & segments.', 'fw' ),
			),
		);
		?>
		<form method="post" action="" class="fw-crm-panel fw-crm-segment-editor" id="fw-crm-segment-editor">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="save_rule_segment" />
			<input type="hidden" name="id" value="<?php echo $segment ? (int) $segment->id : 0; ?>" />
			<input type="hidden" name="rules_json" id="fw-crm-rules-json" value="<?php echo esc_attr( wp_json_encode( $rules ) ); ?>" />

			<label class="fw-crm-form__row fw-crm-segment-editor__title">
				<span><?php esc_html_e( 'Segment name', 'fw' ); ?></span>
				<input type="text" name="segment_title" required value="<?php echo esc_attr( $title ); ?>"
				       placeholder="<?php esc_attr_e( 'e.g. Customers who have not visited in 90 days', 'fw' ); ?>" />
			</label>

			<?php if ( $legacy ) : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'This segment was saved from the contacts list. Its filters are shown as rules; saving stores them in the new format.', 'fw' ); ?></p>
			<?php endif; ?>

			<div id="fw-crm-rules" class="fw-crm-rules" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
				<noscript><p><?php esc_html_e( 'The segment builder needs JavaScript.', 'fw' ); ?></p></noscript>
			</div>

			<div class="fw-crm-segment-editor__foot">
				<p class="fw-crm-segment-count" id="fw-crm-segment-count" aria-live="polite"></p>
				<p class="fw-crm-muted fw-crm-segment-describe" id="fw-crm-segment-describe"></p>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save segment', 'fw' ); ?></button>
					<a class="button" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'lists' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
					<?php if ( $segment ) : ?>
						<a class="button-link" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'segment' => (int) $segment->id ), FW_CRM_Admin_Page::get_page_url() ) ); ?>"><?php esc_html_e( 'See the contacts it matches', 'fw' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</form>
		<p class="fw-crm-muted fw-crm-segment-help">
			<?php esc_html_e( 'A segment is a saved search, not a fixed group: it is worked out again every time it is used, so people move in and out of it as their details change. Campaigns can be sent to it, and other parts of the CRM can check whether someone is in it.', 'fw' ); ?>
		</p>
		<?php
	}

	/**
	 * Turn the contacts list's simple filters into an equivalent rule tree.
	 *
	 * @param array $filters
	 *
	 * @return array
	 */
	private static function rules_from_filters( array $filters ) {
		$rules = array();
		if ( ! empty( $filters['status'] ) ) {
			$rules[] = array( 'field' => 'status', 'op' => 'in', 'value' => array( $filters['status'] ) );
		}
		if ( ! empty( $filters['stage'] ) ) {
			$rules[] = array( 'field' => 'lifecycle_stage', 'op' => 'in', 'value' => array( $filters['stage'] ) );
		}
		foreach ( array( 'tag', 'list' ) as $kind ) {
			if ( empty( $filters[ $kind ] ) ) {
				continue;
			}
			$row = is_numeric( $filters[ $kind ] ) ? FW_CRM_Lists::find( (int) $filters[ $kind ] ) : FW_CRM_Lists::find_by_slug( $filters[ $kind ], $kind );
			if ( $row ) {
				$rules[] = array( 'field' => $kind, 'op' => 'has_any', 'value' => array( $row->slug ) );
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$rules[] = array(
				'match' => 'any',
				'rules' => array(
					array( 'field' => 'email', 'op' => 'contains', 'value' => $filters['search'] ),
					array( 'field' => 'first_name', 'op' => 'contains', 'value' => $filters['search'] ),
					array( 'field' => 'last_name', 'op' => 'contains', 'value' => $filters['search'] ),
				),
			);
		}

		return array( 'match' => 'all', 'rules' => $rules );
	}
}
