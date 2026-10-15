<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for the CRM: enough to SET UP a CRM (lists, pipeline tags, saved
 * segments, the signup emails, draft campaigns) and nothing that reads the people in it.
 *
 *   crm-describe       settings, lists, tags, segments and campaigns, with subscriber COUNTS only
 *   crm-save-lists     create or rename lists and tags (matched by slug, so calling twice is safe)
 *   crm-save-segment   save a segment: a live filter by status, list, tag or search
 *   crm-settings       double opt-in, admin notification, default list and the confirmation /
 *                      welcome emails (the settings option is snapshotted for undo-change)
 *   crm-save-campaign  save a DRAFT campaign; there is deliberately no ability that schedules or sends
 *
 * Personal data stays out of reach: no ability returns a subscriber's name, email or history. Lists,
 * tags, segments and campaigns live in the CRM's own tables, which undo-change cannot restore, so
 * those abilities say what they changed and the person can rename or delete it on the CRM screens.
 */

if ( ! function_exists( 'fw_ext_crm_ai_register' ) ) :

	/** @return string The option backing this extension's settings. */
	function fw_ext_crm_ai_option() {
		return 'fw_ext_settings_options:crm';
	}

	/**
	 * A list or tag as the abilities show it.
	 *
	 * @param object $row
	 * @param array  $counts object_id => subscribed count
	 * @return array
	 */
	function fw_ext_crm_ai_list_row( $row, array $counts ) {
		return array(
			'id'          => (int) $row->id,
			'slug'        => (string) $row->slug,
			'title'       => (string) $row->title,
			'description' => (string) $row->description,
			'subscribed'  => (int) ( $counts[ (int) $row->id ] ?? 0 ),
		);
	}

	/**
	 * Turn a list / tag reference (slug, id or title) into its slug, or null.
	 *
	 * @param mixed  $ref
	 * @param string $type list | tag
	 * @return string|null
	 */
	function fw_ext_crm_ai_slug( $ref, $type ) {
		if ( is_numeric( $ref ) ) {
			$row = FW_CRM_Lists::find( (int) $ref );
			return $row && $row->type === $type ? (string) $row->slug : null;
		}
		$ref = (string) $ref;
		foreach ( array( sanitize_key( $ref ), sanitize_key( sanitize_title( $ref ) ) ) as $slug ) {
			if ( '' !== $slug && FW_CRM_Lists::find_by_slug( $slug, $type ) ) {
				return $slug;
			}
		}
		return null;
	}

	function fw_ext_crm_ai_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! fw_ext( 'crm' ) || ! class_exists( 'FW_CRM_Service' ) ) {
			return;
		}

		fw_ai_register_ability( 'crm-describe', array(
			'label'       => __( 'Describe the CRM', 'fw' ),
			'description' => 'The CRM set-up: settings (double opt-in, admin notification, default list, confirmation and welcome emails), lists and tags (slug, title, how many subscribed people each has), saved segments (with a plain description of what they match and how many people match), campaigns (title, subject, status, audience) and the email sender (From name / address, whether a send method is set). Counts only: no subscriber names or emails. Pipeline stages are tags, conventionally titled "Stage: <name>".',
			'permission'  => 'manage_options',
			'readonly'    => true,
			'execute'     => 'fw_ext_crm_ai_describe',
		) );

		fw_ai_register_ability( 'crm-save-lists', array(
			'label'       => __( 'Create CRM lists and tags', 'fw' ),
			'description' => 'Creates lists (audiences people sign up to, e.g. "Buyers", "Newsletter") and tags (labels such as pipeline stages, e.g. "Stage: New lead") in one call. Each item: { title, slug? (derived from the title when left out), description? }. An item whose slug already exists is updated (title / description), so calling it again is safe. Returns each item\'s id and slug: forms subscribe people by LIST slug, and tags by name or slug. Not undoable with undo_change; rename or delete on CRM > Lists, Tags & Segments.',
			'input'       => array(
				'lists' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'tags'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_save_lists',
		) );

		fw_ai_register_ability( 'crm-save-segment', array(
			'label'       => __( 'Save a CRM segment', 'fw' ),
			'description' => 'Saves a segment: a live filter the CRM screens and campaigns can use. filters: { status? (subscribed | pending (not yet confirmed) | unsubscribed | bounced | complained), list? (slug, id or title), tag? (slug, id or title), search? }. One segment per pipeline stage (tag "Stage: X", status subscribed) lets the owner open each stage in one click. A segment with the same title is updated. Not undoable with undo_change.',
			'input'       => array(
				'title'   => array( 'type' => 'string', 'minLength' => 1 ),
				'filters' => array( 'type' => 'object' ),
			),
			'required'    => array( 'title', 'filters' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_save_segment',
		) );

		fw_ai_register_ability( 'crm-settings', array(
			'label'       => __( 'Change CRM settings', 'fw' ),
			'description' => 'Changes CRM settings; only the keys given change. double_optin (bool, recommended true: people confirm by email before they count as subscribed), admin_notify (bool), default_list (list slug, or "" for none), confirm_subject / confirm_body (the confirmation email; the body MUST contain {{confirm_url}}), welcome_email (bool: send a welcome email after confirming), welcome_subject / welcome_body. Placeholders: {{name}}, {{first_name}}, {{site_name}}, {{site_url}}, {{confirm_url}}, {{unsubscribe_url}}, {{preferences_url}}. Write emails in the owner\'s voice and leave welcome_email off until the owner approves the text. Undo with undo_change.',
			'input'       => array(
				'double_optin'    => array( 'type' => 'boolean' ),
				'admin_notify'    => array( 'type' => 'boolean' ),
				'default_list'    => array( 'type' => 'string' ),
				'confirm_subject' => array( 'type' => 'string' ),
				'confirm_body'    => array( 'type' => 'string' ),
				'welcome_email'   => array( 'type' => 'boolean' ),
				'welcome_subject' => array( 'type' => 'string' ),
				'welcome_body'    => array( 'type' => 'string' ),
			),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_settings',
		) );

		if ( FW_CRM_Modules::enabled( 'email' ) ) :
		fw_ai_register_ability( 'crm-save-campaign', array(
			'label'       => __( 'Save a draft campaign', 'fw' ),
			'description' => 'Saves a DRAFT email campaign for the owner to review and send themselves (nothing is ever scheduled or sent by this ability). { title (internal name), subject, body (plain text or simple HTML; the same placeholders as crm_settings; an unsubscribe link is added when the body has none), audience? { list?, tag?, segment? (title or id), status? } , id? (to update a draft) }. A draft with the same title is updated. Returns how many people it would reach right now. Not undoable with undo_change; delete it on CRM > Campaigns.',
			'input'       => array(
				'title'    => array( 'type' => 'string', 'minLength' => 1 ),
				'subject'  => array( 'type' => 'string', 'minLength' => 1 ),
				'body'     => array( 'type' => 'string' ),
				'audience' => array( 'type' => 'object' ),
				'id'       => array( 'type' => 'integer' ),
			),
			'required'    => array( 'title', 'subject' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_save_campaign',
		) );
		endif;
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_crm_ai_register' );

	/**
	 * @return array
	 */
	function fw_ext_crm_ai_describe() {
		$s = 'FW_CRM_Service';

		$out = array(
			'settings' => array(
				'double_optin'    => $s::setting_is_on( 'double_optin', true ),
				'admin_notify'    => $s::setting_is_on( 'admin_notify', true ),
				'default_list'    => (string) $s::setting( 'default_list', '' ),
				'confirm_subject' => (string) $s::setting( 'confirm_subject', FW_CRM_Mail::defaults( 'confirm' )['subject'] ),
				'welcome_email'   => $s::setting_is_on( 'welcome_email', false ),
				'welcome_subject' => (string) $s::setting( 'welcome_subject', FW_CRM_Mail::defaults( 'welcome' )['subject'] ),
			),
			'lists'    => array(),
			'tags'     => array(),
			'segments' => array(),
			'campaigns' => array(),
		);

		foreach ( array( 'list' => 'lists', 'tag' => 'tags' ) as $type => $key ) {
			$counts = FW_CRM_Lists::counts( $type );
			foreach ( FW_CRM_Lists::all( $type ) as $row ) {
				$out[ $key ][] = fw_ext_crm_ai_list_row( $row, $counts );
			}
		}

		foreach ( FW_CRM_Lists::segments() as $seg ) {
			$filters           = FW_CRM_Lists::segment_query_args( $seg );
			$out['segments'][] = array(
				'id'      => (int) $seg->id,
				'title'   => (string) $seg->title,
				'filters' => $filters,
				'matches' => $s::describe_filters( $filters ),
				'count'   => (int) FW_CRM_Subscribers::count( $filters ),
			);
		}

		foreach ( class_exists( 'FW_CRM_Campaigns' ) ? FW_CRM_Campaigns::all() : array() as $c ) {
			$audience           = json_decode( (string) ( $c->audience ?? '' ), true );
			$out['campaigns'][] = array(
				'id'       => (int) $c->id,
				'title'    => (string) $c->title,
				'subject'  => (string) $c->subject,
				'status'   => (string) $c->status,
				'audience' => is_array( $audience ) ? $s::describe_filters( $audience ) : '',
			);
		}

		$mailer = fw_ext( 'mailer' );
		if ( $mailer ) {
			$out['sender'] = array(
				'from_name'    => (string) $mailer->get_db_settings_option( 'general/from_name' ),
				'from_address' => (string) $mailer->get_db_settings_option( 'general/from_address' ),
				'configured'   => method_exists( $mailer, 'is_configured' ) ? (bool) $mailer->is_configured() : null,
			);
		}

		return $out;
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_save_lists( $in ) {
		$done   = array( 'lists' => array(), 'tags' => array() );
		$errors = array();

		foreach ( array( 'lists' => 'list', 'tags' => 'tag' ) as $key => $type ) {
			foreach ( array_values( (array) ( $in[ $key ] ?? array() ) ) as $i => $item ) {
				$item  = is_array( $item ) ? $item : array( 'title' => (string) $item );
				$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
				$slug  = isset( $item['slug'] ) && '' !== $item['slug'] ? sanitize_key( (string) $item['slug'] ) : sanitize_key( sanitize_title( $title ) );
				$found = '' !== $slug ? FW_CRM_Lists::find_by_slug( $slug, $type ) : null;

				$data = array(
					'type'  => $type,
					'title' => $title,
					'slug'  => $slug,
				);
				if ( isset( $item['description'] ) ) {
					$data['description'] = sanitize_text_field( (string) $item['description'] );
				} elseif ( $found ) {
					$data['description'] = (string) $found->description;
				}
				if ( $found ) {
					$data['id'] = (int) $found->id;
				}

				$row = FW_CRM_Service::save_list( $data );
				if ( is_wp_error( $row ) ) {
					$errors[] = sprintf( '%s[%d] "%s": %s', $key, $i, $title, $row->get_error_message() );
					continue;
				}
				$done[ $key ][] = array( 'id' => (int) $row->id, 'slug' => (string) $row->slug, 'title' => (string) $row->title, 'created' => ! $found );
			}
		}

		if ( ! $done['lists'] && ! $done['tags'] ) {
			return new WP_Error( 'fw_crm_ai_nothing', $errors ? implode( ' | ', $errors ) : 'Give lists and / or tags.' );
		}

		return array(
			'ok'      => ! $errors,
			'message' => sprintf( 'Saved %d list(s) and %d tag(s).', count( $done['lists'] ), count( $done['tags'] ) ),
			'lists'   => $done['lists'],
			'tags'    => $done['tags'],
			'errors'  => $errors,
		);
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_save_segment( $in ) {
		$filters = (array) ( $in['filters'] ?? array() );
		$args    = array();

		foreach ( array( 'list', 'tag' ) as $type ) {
			if ( isset( $filters[ $type ] ) && '' !== $filters[ $type ] ) {
				$slug = fw_ext_crm_ai_slug( $filters[ $type ], $type );
				if ( ! $slug ) {
					return new WP_Error( 'fw_crm_ai_unknown', sprintf( 'filters.%1$s "%2$s" is not an existing %1$s (create it with crm_save_lists).', $type, (string) $filters[ $type ] ) );
				}
				$args[ $type ] = $slug;
			}
		}
		foreach ( array( 'status', 'search' ) as $k ) {
			if ( isset( $filters[ $k ] ) && '' !== $filters[ $k ] ) {
				$args[ $k ] = (string) $filters[ $k ];
			}
		}
		if ( isset( $args['status'] ) && ! FW_CRM_Subscribers::is_valid_status( $args['status'] ) ) {
			return new WP_Error( 'fw_crm_ai_status', 'filters.status must be subscribed, pending, unsubscribed, bounced or complained.' );
		}

		$title = sanitize_text_field( (string) $in['title'] );
		$slug  = sanitize_key( sanitize_title( $title ) );
		$found = '' !== $slug ? FW_CRM_Lists::find_segment_by_slug( $slug ) : null;

		$seg = FW_CRM_Service::save_segment( $args, $title, $found ? (int) $found->id : 0 );
		if ( is_wp_error( $seg ) ) {
			return $seg;
		}
		$saved = FW_CRM_Lists::segment_query_args( $seg );

		return array(
			'ok'      => true,
			'message' => sprintf( '%s segment "%s": %s.', $found ? 'Updated' : 'Created', $seg->title, FW_CRM_Service::describe_filters( $saved ) ),
			'id'      => (int) $seg->id,
			'count'   => (int) FW_CRM_Subscribers::count( $saved ),
		);
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_settings( $in ) {
		$changes = array();

		foreach ( array( 'double_optin', 'admin_notify', 'welcome_email' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$changes[ $k ] = $in[ $k ] ? 'yes' : 'no';
			}
		}
		if ( isset( $in['default_list'] ) ) {
			$ref = (string) $in['default_list'];
			$slug = '' === $ref ? '' : fw_ext_crm_ai_slug( $ref, 'list' );
			if ( null === $slug ) {
				return new WP_Error( 'fw_crm_ai_unknown', sprintf( 'default_list "%s" is not an existing list.', $ref ) );
			}
			$changes['default_list'] = $slug;
		}
		foreach ( array( 'confirm_subject', 'welcome_subject' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$changes[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		foreach ( array( 'confirm_body', 'welcome_body' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$changes[ $k ] = sanitize_textarea_field( (string) $in[ $k ] );
			}
		}
		if ( isset( $changes['confirm_body'] ) && false === strpos( $changes['confirm_body'], '{{confirm_url}}' ) ) {
			return new WP_Error( 'fw_crm_ai_confirm', 'confirm_body must contain {{confirm_url}}, or nobody could confirm. Nothing was changed.' );
		}
		if ( ! $changes ) {
			return new WP_Error( 'fw_crm_ai_nothing', 'Give at least one setting to change.' );
		}

		$rev = ! function_exists( 'fw_ai_snapshot' ) ? 0 : fw_ai_snapshot( array( 'options' => array( fw_ext_crm_ai_option() ) ), 'unysonplus/crm-settings', 'Changed CRM settings: ' . implode( ', ', array_keys( $changes ) ) );
		foreach ( $changes as $k => $v ) {
			fw_set_db_ext_settings_option( 'crm', $k, $v );
		}

		return array(
			'ok'               => true,
			'message'          => 'Changed: ' . implode( ', ', array_keys( $changes ) ) . '.',
			'undo_revision_id' => $rev,
		);
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_save_campaign( $in ) {
		$audience = (array) ( $in['audience'] ?? array() );
		$aud      = array();

		foreach ( array( 'list', 'tag' ) as $type ) {
			if ( isset( $audience[ $type ] ) && '' !== $audience[ $type ] ) {
				$slug = fw_ext_crm_ai_slug( $audience[ $type ], $type );
				if ( ! $slug ) {
					return new WP_Error( 'fw_crm_ai_unknown', sprintf( 'audience.%1$s "%2$s" is not an existing %1$s.', $type, (string) $audience[ $type ] ) );
				}
				$aud[ $type ] = $slug;
			}
		}
		if ( isset( $audience['segment'] ) && '' !== $audience['segment'] ) {
			$ref = $audience['segment'];
			$seg = is_numeric( $ref ) ? FW_CRM_Lists::find_segment( (int) $ref ) : FW_CRM_Lists::find_segment_by_slug( sanitize_key( sanitize_title( (string) $ref ) ) );
			if ( ! $seg ) {
				return new WP_Error( 'fw_crm_ai_unknown', sprintf( 'audience.segment "%s" is not an existing segment.', (string) $ref ) );
			}
			$aud['segment'] = (int) $seg->id;
		}
		if ( ! empty( $audience['status'] ) ) {
			$aud['status'] = (string) $audience['status'];
		}

		$title = sanitize_text_field( (string) $in['title'] );
		$id    = isset( $in['id'] ) ? (int) $in['id'] : 0;
		if ( ! $id ) {
			foreach ( FW_CRM_Campaigns::all() as $c ) {
				if ( 'draft' === $c->status && 0 === strcasecmp( (string) $c->title, $title ) ) {
					$id = (int) $c->id;
					break;
				}
			}
		}

		$campaign = FW_CRM_Service::save_campaign( array(
			'id'       => $id,
			'title'    => $title,
			'subject'  => sanitize_text_field( (string) $in['subject'] ),
			'body'     => wp_kses_post( (string) ( $in['body'] ?? '' ) ),
			'audience' => $aud,
		) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		return array(
			'ok'      => true,
			'message' => sprintf( '%s the draft campaign "%s" (not sent; the owner sends it from CRM > Campaigns).', $id ? 'Updated' : 'Saved', $campaign->title ),
			'id'      => (int) $campaign->id,
			'reach'   => (int) FW_CRM_Service::audience_count( $campaign ),
		);
	}
endif;
