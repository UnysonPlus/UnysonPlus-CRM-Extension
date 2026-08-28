<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The service layer — the business rules, and the ONLY thing the outside world
 * calls. The AJAX capture hook, the admin screen, the CSV importer and the REST
 * controller all go through here, so a rule ("a re-signup reactivates rather
 * than duplicating", "double opt-in means status = pending") exists once.
 *
 * Rules live here; SQL lives in the repositories; markup lives in the admin
 * classes. Nothing above the repository writes SQL and nothing below the service
 * fires a hook.
 */
class FW_Newsletter_CRM_Service {

	/**
	 * Read an extension setting.
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public static function setting( $key, $default = null ) {
		$settings = (array) fw_get_db_ext_settings_option( 'newsletter-crm' );

		return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $default;
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public static function setting_is_on( $key, $default = false ) {
		$value = self::setting( $key, $default ? 'yes' : 'no' );

		return 'yes' === $value || true === $value || '1' === $value;
	}

	/**
	 * The registered providers, local first.
	 *
	 * @return FW_Newsletter_CRM_Provider[] keyed by slug.
	 */
	public static function providers() {
		$providers = array( 'local' => new FW_Newsletter_CRM_Provider_Local() );

		/**
		 * Filters the registered ESP provider map (keyed by slug) so add-ons can register integrations.
		 *
		 * Register an ESP integration.
		 *
		 * @param FW_Newsletter_CRM_Provider[] $providers Keyed by slug.
		 */
		$providers = apply_filters( 'unysonplus_newsletter_crm_providers', $providers );

		// Defensive: a badly-written add-on must not be able to break a signup.
		foreach ( $providers as $slug => $provider ) {
			if ( ! $provider instanceof FW_Newsletter_CRM_Provider ) {
				unset( $providers[ $slug ] );
			}
		}

		return $providers;
	}

	/**
	 * The capability gating the admin screen and REST.
	 *
	 * @return string
	 */
	public static function capability() {
		/** Filters the capability (default manage_options) required to reach the newsletter CRM admin screen and REST endpoints. */
		return apply_filters( 'unysonplus_newsletter_crm_capability', 'manage_options' );
	}

	/* ---------------------------------------------------------------------- *
	 * The main entry point
	 * ---------------------------------------------------------------------- */

	/**
	 * Subscribe (or re-subscribe) an address.
	 *
	 * @param string $email
	 * @param array  $args name, first_name, last_name, list, lists, tags, source,
	 *                     source_url, ip, status, meta, consent_text, double_optin
	 *
	 * @return object|WP_Error The stored subscriber, or an error.
	 */
	public static function subscribe( $email, array $args = array() ) {
		$email = FW_Newsletter_CRM_Subscribers::normalize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'fw_crm_invalid_email', __( 'Please enter a valid email address.', 'fw' ) );
		}

		/**
		 * Filters signup acceptance before storing; return a WP_Error to veto an address via blocklist or spam checks.
		 *
		 * Veto or accept a signup — blocklists, disposable-domain checks, spam
		 * scoring. Return a WP_Error to reject.
		 *
		 * @param true|WP_Error $valid
		 * @param string        $email
		 * @param array         $args
		 */
		$valid = apply_filters( 'unysonplus_newsletter_crm_validate', true, $email, $args );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$existing = FW_Newsletter_CRM_Subscribers::find_by_email( $email );
		$names    = self::split_name( $args );
		$now      = current_time( 'mysql' );

		$double_optin = array_key_exists( 'double_optin', $args )
			? (bool) $args['double_optin']
			: self::setting_is_on( 'double_optin' );

		$data = array(
			'email'      => $email,
			'first_name' => $names['first'],
			'last_name'  => $names['last'],
			'source'     => isset( $args['source'] ) ? $args['source'] : 'shortcode',
			'source_url' => isset( $args['source_url'] ) ? $args['source_url'] : '',
			'ip'         => isset( $args['ip'] ) ? $args['ip'] : self::client_ip(),
			'consent_at' => $now,
		);

		// Blank names must not wipe a name we already know (an ESP-style
		// "omission does not clear" rule — it is what people expect).
		if ( '' === $data['first_name'] && '' === $data['last_name'] && $existing ) {
			unset( $data['first_name'], $data['last_name'] );
		}

		// Consent evidence: what they agreed to, not just that they did.
		$meta = self::merge_meta( $existing, $args );

		if ( $meta ) {
			$data['meta'] = $meta;
		}

		$was_unsubscribed = $existing && 'unsubscribed' === $existing->status;

		if ( ! empty( $args['status'] ) && FW_Newsletter_CRM_Subscribers::is_valid_status( $args['status'] ) ) {
			$data['status'] = $args['status'];
		} elseif ( $double_optin && ( ! $existing || 'subscribed' !== $existing->status ) ) {
			$data['status']           = 'pending';
			$data['confirm_token']    = FW_Newsletter_CRM_Subscribers::generate_token();
			$data['confirm_token_at'] = $now;
		} else {
			$data['status'] = 'subscribed';
		}

		if ( 'subscribed' === $data['status'] ) {
			$data['unsubscribed_at'] = null;
		}

		/**
		 * Filters a subscriber row immediately before it is written, the shared seam for CSV import and ESP adapters.
		 *
		 * Rewrite the record immediately before it is written — the field-mapping
		 * seam shared with CSV import and ESP adapters.
		 *
		 * @param array       $data
		 * @param object|null $existing
		 * @param array       $args
		 */
		$data = apply_filters( 'unysonplus_newsletter_crm_subscriber_data', $data, $existing, $args );

		$result = FW_Newsletter_CRM_Subscribers::upsert( $data );

		if ( empty( $result['id'] ) ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the subscriber.', 'fw' ) );
		}

		$subscriber = FW_Newsletter_CRM_Subscribers::find( $result['id'] );
		$membership = self::resolve_membership( $args );

		self::dispatch( 'subscribe', $subscriber, $membership );

		// Re-read: providers (local included) change membership, not the row, but
		// a third-party provider may have written meta we want reflected.
		$subscriber = FW_Newsletter_CRM_Subscribers::find( $result['id'] );

		$context = array_merge( $args, $membership, array( 'double_optin' => $double_optin ) );

		if ( $result['created'] ) {
			/** Fired once, when a brand-new subscriber row is created. */
			do_action( 'unysonplus_newsletter_crm_subscriber_added', $subscriber, $context );
		} elseif ( $was_unsubscribed && 'unsubscribed' !== $subscriber->status ) {
			/** Fired when a previously opted-out address comes back — distinct from _added. */
			do_action( 'unysonplus_newsletter_crm_subscriber_resubscribed', $subscriber, $context );
		} else {
			/** Fired when an existing subscriber's data changed. */
			do_action( 'unysonplus_newsletter_crm_subscriber_updated', $subscriber, $existing, $context );
		}

		return $subscriber;
	}

	/**
	 * Confirm a pending double-opt-in signup.
	 *
	 * Phase 1 stores and validates the token; the emails and the public confirm
	 * endpoint land in Phase 2. The storage is here now precisely so that is not
	 * a migration later.
	 *
	 * @param string $token
	 *
	 * @return object|WP_Error
	 */
	public static function confirm( $token ) {
		$subscriber = FW_Newsletter_CRM_Subscribers::find_by_token( $token, 'confirm' );

		// hash_equals-style comparison happens inside the lookup's prepared
		// statement; the extra guard here is the empty-token case, which would
		// otherwise match every row whose token column is ''.
		if ( ! $subscriber || '' === (string) $token ) {
			return new WP_Error( 'fw_crm_bad_token', __( 'That confirmation link is not valid.', 'fw' ) );
		}

		/** Filters the lifetime in seconds of a double-opt-in confirmation token (default 48 hours). */
		$ttl = (int) apply_filters( 'unysonplus_newsletter_crm_confirm_token_ttl', 48 * HOUR_IN_SECONDS );

		if ( $ttl > 0 && ! empty( $subscriber->confirm_token_at ) ) {
			$age = time() - (int) strtotime( $subscriber->confirm_token_at );

			if ( $age > $ttl ) {
				return new WP_Error( 'fw_crm_expired_token', __( 'That confirmation link has expired.', 'fw' ) );
			}
		}

		FW_Newsletter_CRM_Subscribers::update( $subscriber->id, array(
			'status'           => 'subscribed',
			'confirmed_at'     => current_time( 'mysql' ),
			'confirm_token'    => '',       // single-use
			'confirm_token_at' => null,
			'unsubscribed_at'  => null,
		) );

		$subscriber = FW_Newsletter_CRM_Subscribers::find( $subscriber->id );

		/** Fired when double opt-in completes. */
		do_action( 'unysonplus_newsletter_crm_subscriber_confirmed', $subscriber );

		return $subscriber;
	}

	/**
	 * Issue a fresh confirmation link and email it.
	 *
	 * The old token is replaced rather than reused, so a link that leaked (a
	 * forwarded email, a shared inbox) stops working the moment a new one is
	 * requested. Also used to put an accidentally-expired signup back in play.
	 *
	 * @param int|string|object $subscriber
	 *
	 * @return object|WP_Error
	 */
	public static function resend_confirmation( $subscriber ) {
		$subscriber = self::resolve_subscriber( $subscriber );

		if ( ! $subscriber ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Subscriber not found.', 'fw' ) );
		}

		if ( 'unsubscribed' === $subscriber->status ) {
			return new WP_Error(
				'fw_crm_unsubscribed',
				__( 'That person unsubscribed — sending them a new confirmation link would be re-adding them without consent.', 'fw' )
			);
		}

		FW_Newsletter_CRM_Subscribers::update( $subscriber->id, array(
			'status'           => 'pending',
			'confirm_token'    => FW_Newsletter_CRM_Subscribers::generate_token(),
			'confirm_token_at' => current_time( 'mysql' ),
		) );

		$subscriber = FW_Newsletter_CRM_Subscribers::find( $subscriber->id );

		if ( ! FW_Newsletter_CRM_Mail::send_confirmation( $subscriber ) ) {
			return new WP_Error(
				'fw_crm_mail_failed',
				__( 'The confirmation email could not be sent. Check the site\'s email/SMTP settings.', 'fw' )
			);
		}

		return $subscriber;
	}

	/**
	 * Opt an address out. Never deletes the row — a forgotten address is silently
	 * re-subscribed by the next import.
	 *
	 * @param int|string|object $subscriber ID, email, or row.
	 *
	 * @return object|WP_Error
	 */
	public static function unsubscribe( $subscriber ) {
		$subscriber = self::resolve_subscriber( $subscriber );

		if ( ! $subscriber ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Subscriber not found.', 'fw' ) );
		}

		FW_Newsletter_CRM_Subscribers::update( $subscriber->id, array(
			'status'          => 'unsubscribed',
			'unsubscribed_at' => current_time( 'mysql' ),
		) );

		$subscriber = FW_Newsletter_CRM_Subscribers::find( $subscriber->id );

		self::dispatch( 'unsubscribe', $subscriber, array() );

		/** Fired on opt-out, from any path. */
		do_action( 'unysonplus_newsletter_crm_subscriber_unsubscribed', $subscriber );

		return $subscriber;
	}

	/**
	 * Update an existing subscriber's fields (admin edit).
	 *
	 * @param int   $id
	 * @param array $data
	 *
	 * @return object|WP_Error
	 */
	public static function update( $id, array $data ) {
		$before = FW_Newsletter_CRM_Subscribers::find( $id );

		if ( ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Subscriber not found.', 'fw' ) );
		}

		if ( isset( $data['email'] ) ) {
			$email = FW_Newsletter_CRM_Subscribers::normalize_email( $data['email'] );

			if ( ! is_email( $email ) ) {
				return new WP_Error( 'fw_crm_invalid_email', __( 'Please enter a valid email address.', 'fw' ) );
			}

			$clash = FW_Newsletter_CRM_Subscribers::find_by_email( $email );

			if ( $clash && (int) $clash->id !== (int) $id ) {
				return new WP_Error( 'fw_crm_duplicate', __( 'Another subscriber already uses that address.', 'fw' ) );
			}
		}

		$data = apply_filters( 'unysonplus_newsletter_crm_subscriber_data', $data, $before, array( 'context' => 'update' ) );

		FW_Newsletter_CRM_Subscribers::update( $id, $data );

		$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

		do_action( 'unysonplus_newsletter_crm_subscriber_updated', $subscriber, $before, array( 'context' => 'update' ) );

		return $subscriber;
	}

	/**
	 * Hard delete. Admin action / GDPR erase only.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

		if ( ! $subscriber ) {
			return false;
		}

		$deleted = FW_Newsletter_CRM_Subscribers::delete( $id );

		if ( $deleted ) {
			/** Fired after a hard delete. The row is already gone — this is a copy. */
			do_action( 'unysonplus_newsletter_crm_subscriber_deleted', $subscriber );
		}

		return $deleted;
	}

	/**
	 * Import one CSV row. Thin wrapper over subscribe() that adds the import
	 * rules: never silently resurrect an opt-out, and stay idempotent.
	 *
	 * @param array $row  Already mapped to our field names.
	 * @param array $opts overwrite_unsubscribed (bool), lists, tags, source
	 *
	 * @return string|WP_Error 'created' | 'updated' | 'skipped'
	 */
	public static function import_row( array $row, array $opts = array() ) {
		$email = FW_Newsletter_CRM_Subscribers::normalize_email( isset( $row['email'] ) ? $row['email'] : '' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'fw_crm_invalid_email', sprintf(
				/* translators: %s: the offending value */
				__( 'Not a valid email address: %s', 'fw' ),
				$email
			) );
		}

		$existing = FW_Newsletter_CRM_Subscribers::find_by_email( $email );

		// The default that keeps you out of trouble: an import must not
		// re-subscribe somebody who opted out.
		if ( $existing && 'unsubscribed' === $existing->status && empty( $opts['overwrite_unsubscribed'] ) ) {
			return 'skipped';
		}

		$args = array_merge( $row, array(
			'source'       => isset( $opts['source'] ) ? $opts['source'] : 'import',
			'lists'        => isset( $opts['lists'] ) ? $opts['lists'] : array(),
			'tags'         => isset( $opts['tags'] ) ? $opts['tags'] : array(),
			'double_optin' => false, // An imported list is already consented-to.
		) );

		// An explicit status column in the CSV wins, if it is one we know.
		if ( ! empty( $row['status'] ) && ! FW_Newsletter_CRM_Subscribers::is_valid_status( $row['status'] ) ) {
			unset( $args['status'] );
		}

		$result = self::subscribe( $email, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/** Fired per row during a CSV import. */
		do_action( 'unysonplus_newsletter_crm_subscriber_imported', $result, $row, $opts );

		return $existing ? 'updated' : 'created';
	}

	/* ---------------------------------------------------------------------- *
	 * Lists, tags and segments
	 *
	 * Same layering as subscribers: the repository does the SQL, this fires the
	 * hooks and holds the rules (a list and a tag are the same table, so one set
	 * of methods handles both via $type).
	 * ---------------------------------------------------------------------- */

	/**
	 * Create or update a list/tag.
	 *
	 * @param array $data id (optional), slug, type, title, description
	 *
	 * @return object|WP_Error
	 */
	public static function save_list( array $data ) {
		$type  = isset( $data['type'] ) && 'tag' === $data['type'] ? 'tag' : 'list';
		$title = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		$id    = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( '' === $title ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Give it a name.', 'fw' ) );
		}

		// A blank slug is derived from the title — the usual case, since the UI
		// only asks for a name.
		$slug = isset( $data['slug'] ) && '' !== $data['slug']
			? sanitize_key( $data['slug'] )
			: sanitize_key( sanitize_title( $title ) );

		if ( '' === $slug ) {
			return new WP_Error( 'fw_crm_no_slug', __( 'That name cannot be turned into a slug — try adding some letters.', 'fw' ) );
		}

		$clash = FW_Newsletter_CRM_Lists::find_by_slug( $slug, $type );

		if ( $clash && (int) $clash->id !== $id ) {
			return new WP_Error( 'fw_crm_duplicate', sprintf(
				/* translators: %s: the slug */
				__( 'Something with the slug "%s" already exists.', 'fw' ),
				$slug
			) );
		}

		if ( $id ) {
			FW_Newsletter_CRM_Lists::update( $id, array(
				'title'       => $title,
				'slug'        => $slug,
				'description' => isset( $data['description'] ) ? $data['description'] : '',
			) );
		} else {
			$id = FW_Newsletter_CRM_Lists::insert( array(
				'slug'        => $slug,
				'type'        => $type,
				'title'       => $title,
				'description' => isset( $data['description'] ) ? $data['description'] : '',
			) );

			if ( ! $id ) {
				return new WP_Error( 'fw_crm_store_failed', __( 'Could not save it.', 'fw' ) );
			}
		}

		$list = FW_Newsletter_CRM_Lists::find( $id );

		/** Fired after a list or tag is created or edited. */
		do_action( 'unysonplus_newsletter_crm_list_saved', $list );

		return $list;
	}

	/**
	 * Delete a list/tag. Membership rows go with it; subscribers do not.
	 *
	 * @param int $id
	 *
	 * @return bool|WP_Error
	 */
	public static function delete_list( $id ) {
		$list = FW_Newsletter_CRM_Lists::find( $id );

		if ( ! $list ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Not found.', 'fw' ) );
		}

		$deleted = FW_Newsletter_CRM_Lists::delete( $id );

		if ( $deleted ) {
			/** Fired after a list or tag is deleted. The row is already gone — this is a copy. */
			do_action( 'unysonplus_newsletter_crm_list_deleted', $list );
		}

		return $deleted;
	}

	/**
	 * Attach or detach a tag (or list) across many subscribers at once.
	 *
	 * @param array  $subscriber_ids
	 * @param int    $object_id
	 * @param string $op          add|remove
	 * @param string $object_type list|tag
	 *
	 * @return int Number of subscribers changed.
	 */
	public static function set_membership( array $subscriber_ids, $object_id, $op = 'add', $object_type = 'tag' ) {
		$object = FW_Newsletter_CRM_Lists::find( $object_id );

		if ( ! $object ) {
			return 0;
		}

		$done = 0;

		foreach ( array_filter( array_map( 'intval', $subscriber_ids ) ) as $subscriber_id ) {
			$changed = 'remove' === $op
				? FW_Newsletter_CRM_Subscribers::remove_from_list( $subscriber_id, $object->id, $object->type )
				: FW_Newsletter_CRM_Subscribers::add_to_list( $subscriber_id, $object->id, $object->type );

			if ( ! $changed ) {
				continue;
			}

			$done++;

			$subscriber = FW_Newsletter_CRM_Subscribers::find( $subscriber_id );

			if ( $subscriber ) {
				do_action( 'unysonplus_newsletter_crm_subscriber_updated', $subscriber, $subscriber, array(
					'context'     => 'membership',
					'op'          => $op,
					'object'      => $object,
				) );
			}
		}

		return $done;
	}

	/**
	 * Save a segment — a named query, never denormalised membership.
	 *
	 * @param array $args  The same query args the list table uses.
	 * @param string $title
	 * @param int   $id    Existing segment to overwrite.
	 *
	 * @return object|WP_Error
	 */
	public static function save_segment( array $args, $title, $id = 0 ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Give the segment a name.', 'fw' ) );
		}

		$filters = self::sanitize_segment_filters( $args );

		if ( ! $filters ) {
			return new WP_Error(
				'fw_crm_empty_segment',
				__( 'That segment has no filters — set a status, list, tag or search first, then save it.', 'fw' )
			);
		}

		$id = (int) $id;

		if ( $id ) {
			FW_Newsletter_CRM_Lists::update_segment( $id, array( 'title' => $title, 'filters' => $filters ) );
		} else {
			$slug  = sanitize_key( sanitize_title( $title ) );
			$clash = '' !== $slug ? FW_Newsletter_CRM_Lists::find_segment_by_slug( $slug ) : null;

			if ( $clash ) {
				return new WP_Error( 'fw_crm_duplicate', __( 'A segment with that name already exists.', 'fw' ) );
			}

			$id = FW_Newsletter_CRM_Lists::insert_segment( array(
				'slug'    => $slug,
				'title'   => $title,
				'filters' => $filters,
			) );

			if ( ! $id ) {
				return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the segment.', 'fw' ) );
			}
		}

		$segment = FW_Newsletter_CRM_Lists::find_segment( $id );

		/** Fired after a segment is created or edited. */
		do_action( 'unysonplus_newsletter_crm_segment_saved', $segment );

		return $segment;
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete_segment( $id ) {
		$segment = FW_Newsletter_CRM_Lists::find_segment( $id );

		if ( ! $segment ) {
			return false;
		}

		$deleted = FW_Newsletter_CRM_Lists::delete_segment( $id );

		if ( $deleted ) {
			/** Fired after a segment is deleted. */
			do_action( 'unysonplus_newsletter_crm_segment_deleted', $segment );
		}

		return $deleted;
	}

	/**
	 * Keep only the keys that are genuinely filters, so a stored segment can
	 * never smuggle in paging, ordering or an `ids` list — which would make it
	 * a frozen snapshot instead of a live query.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public static function sanitize_segment_filters( array $args ) {
		$out = array();

		foreach ( array( 'status', 'list', 'tag', 'search' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$out[ $key ] = is_string( $args[ $key ] ) ? sanitize_text_field( $args[ $key ] ) : $args[ $key ];
			}
		}

		if ( isset( $out['status'] ) && ! FW_Newsletter_CRM_Subscribers::is_valid_status( $out['status'] ) ) {
			unset( $out['status'] );
		}

		return $out;
	}

	/**
	 * A human sentence describing what a segment matches, for the admin UI.
	 *
	 * @param array $filters
	 *
	 * @return string
	 */
	public static function describe_filters( array $filters ) {
		$parts = array();

		if ( ! empty( $filters['status'] ) ) {
			$parts[] = sprintf( __( 'status is %s', 'fw' ), $filters['status'] );
		}

		foreach ( array( 'list' => __( 'in list %s', 'fw' ), 'tag' => __( 'tagged %s', 'fw' ) ) as $key => $format ) {
			if ( empty( $filters[ $key ] ) ) {
				continue;
			}

			$row = is_numeric( $filters[ $key ] )
				? FW_Newsletter_CRM_Lists::find( $filters[ $key ] )
				: FW_Newsletter_CRM_Lists::find_by_slug( $filters[ $key ], $key );

			$parts[] = sprintf( $format, $row ? $row->title : $filters[ $key ] );
		}

		if ( ! empty( $filters['search'] ) ) {
			$parts[] = sprintf( __( 'matching "%s"', 'fw' ), $filters['search'] );
		}

		return $parts ? implode( __( ', and ', 'fw' ), $parts ) : __( 'everyone', 'fw' );
	}

	/* ---------------------------------------------------------------------- *
	 * Campaigns
	 * ---------------------------------------------------------------------- */

	/**
	 * Create or update a campaign. Only a draft or a scheduled campaign may be
	 * edited — once a queue exists, editing the body would mean half the
	 * recipients got a different email from the other half.
	 *
	 * @param array $data id, title, subject, body, audience
	 *
	 * @return object|WP_Error
	 */
	public static function save_campaign( array $data ) {
		$id      = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$title   = isset( $data['title'] ) ? trim( (string) $data['title'] ) : '';
		$subject = isset( $data['subject'] ) ? trim( (string) $data['subject'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Give the campaign a name.', 'fw' ) );
		}

		if ( '' === $subject ) {
			return new WP_Error( 'fw_crm_no_subject', __( 'A campaign needs a subject line.', 'fw' ) );
		}

		if ( $id ) {
			$existing = FW_Newsletter_CRM_Campaigns::find( $id );

			if ( ! $existing ) {
				return new WP_Error( 'fw_crm_not_found', __( 'Campaign not found.', 'fw' ) );
			}

			if ( ! in_array( $existing->status, array( 'draft', 'scheduled' ), true ) ) {
				return new WP_Error(
					'fw_crm_locked',
					__( 'This campaign has already started sending, so it can no longer be edited — half the list would receive a different email from the other half. Duplicate it instead.', 'fw' )
				);
			}
		}

		$payload = array(
			'title'    => $title,
			'subject'  => $subject,
			'body'     => isset( $data['body'] ) ? $data['body'] : '',
			'audience' => self::sanitize_audience( isset( $data['audience'] ) ? (array) $data['audience'] : array() ),
		);

		// Email-builder campaigns keep the BLOCK TREE as the source of truth and
		// compile it to HTML into `body` right here, on save. That is what keeps
		// the whole sending stack — queue, batching, test sends, render_body() —
		// completely unaware that a builder exists, and it is why campaigns
		// written in the plain editor keep working: they simply have no body_json.
		if ( array_key_exists( 'body_json', $data ) ) {
			$blocks = FW_Newsletter_CRM_Email_Compiler::normalize( $data['body_json'] );

			$payload['body_json'] = $blocks ? wp_json_encode( $blocks ) : '';
			$payload['body']      = $blocks
				? FW_Newsletter_CRM_Email_Compiler::compile( $blocks )
				: $payload['body'];
		}

		if ( $id ) {
			FW_Newsletter_CRM_Campaigns::update( $id, $payload );
		} else {
			$payload['status'] = 'draft';
			$id                = FW_Newsletter_CRM_Campaigns::insert( $payload );

			if ( ! $id ) {
				return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the campaign.', 'fw' ) );
			}
		}

		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		/** Fired after a campaign is created or edited. */
		do_action( 'unysonplus_newsletter_crm_campaign_saved', $campaign );

		return $campaign;
	}

	/**
	 * Audience filters may include a segment id as well as the usual filters.
	 *
	 * @param array $audience
	 *
	 * @return array
	 */
	public static function sanitize_audience( array $audience ) {
		$out = self::sanitize_segment_filters( $audience );

		if ( ! empty( $audience['segment'] ) ) {
			$out['segment'] = (int) $audience['segment'];
		}

		return $out;
	}

	/**
	 * How many people a campaign would go to right now.
	 *
	 * @param object|array $campaign_or_audience
	 *
	 * @return int
	 */
	public static function audience_count( $campaign_or_audience ) {
		$args = is_object( $campaign_or_audience )
			? FW_Newsletter_CRM_Campaigns::audience_args( $campaign_or_audience )
			: array_merge( (array) $campaign_or_audience, array( 'status' => 'subscribed' ) );

		return FW_Newsletter_CRM_Subscribers::count( $args );
	}

	/**
	 * Queue a campaign for sending.
	 *
	 * Both "send now" and "schedule" land here — the only difference is the
	 * timestamp. Going through one path means the worker has exactly one way to
	 * pick work up, which is what keeps the lock reasoning simple.
	 *
	 * @param int         $id
	 * @param string|null $when MySQL datetime, or null for immediately.
	 *
	 * @return object|WP_Error
	 */
	public static function schedule_campaign( $id, $when = null ) {
		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		if ( ! $campaign ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Campaign not found.', 'fw' ) );
		}

		if ( ! in_array( $campaign->status, array( 'draft', 'scheduled' ), true ) ) {
			return new WP_Error( 'fw_crm_already_sending', __( 'That campaign is already sending or sent.', 'fw' ) );
		}

		if ( '' === trim( (string) $campaign->subject ) ) {
			return new WP_Error( 'fw_crm_no_subject', __( 'Add a subject line before sending.', 'fw' ) );
		}

		$count = self::audience_count( $campaign );

		if ( ! $count ) {
			return new WP_Error(
				'fw_crm_empty_audience',
				__( 'Nobody matches this campaign\'s audience, so there is nothing to send. Check the list, tag or segment.', 'fw' )
			);
		}

		FW_Newsletter_CRM_Campaigns::update( $id, array(
			'status'       => 'scheduled',
			'scheduled_at' => $when ? $when : current_time( 'mysql' ),
		) );

		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		/** Fired when a campaign is queued for sending (now or later). */
		do_action( 'unysonplus_newsletter_crm_campaign_scheduled', $campaign );

		return $campaign;
	}

	/**
	 * Stop a send mid-flight. The queue is left intact, so resuming continues
	 * from exactly where it stopped rather than starting over.
	 *
	 * @param int $id
	 *
	 * @return object|WP_Error
	 */
	public static function pause_campaign( $id ) {
		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		if ( ! $campaign || ! in_array( $campaign->status, array( 'sending', 'scheduled' ), true ) ) {
			return new WP_Error( 'fw_crm_not_sending', __( 'That campaign is not sending.', 'fw' ) );
		}

		FW_Newsletter_CRM_Campaigns::update( $id, array( 'status' => 'paused' ) );

		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		/** Fired when a send is paused. The queue is retained. */
		do_action( 'unysonplus_newsletter_crm_campaign_paused', $campaign );

		return $campaign;
	}

	/**
	 * @param int $id
	 *
	 * @return object|WP_Error
	 */
	public static function resume_campaign( $id ) {
		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		if ( ! $campaign || 'paused' !== $campaign->status ) {
			return new WP_Error( 'fw_crm_not_paused', __( 'That campaign is not paused.', 'fw' ) );
		}

		$counts = FW_Newsletter_CRM_Campaigns::queue_counts( $id );

		// Paused before the queue was ever built → go back through scheduling.
		FW_Newsletter_CRM_Campaigns::update( $id, $counts['total']
			? array( 'status' => 'sending' )
			: array( 'status' => 'scheduled', 'scheduled_at' => current_time( 'mysql' ) )
		);

		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		/** Fires after a paused campaign is set back to sending or scheduled, passing the refreshed campaign object. */
		do_action( 'unysonplus_newsletter_crm_campaign_resumed', $campaign );

		return $campaign;
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete_campaign( $id ) {
		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		if ( ! $campaign ) {
			return false;
		}

		$deleted = FW_Newsletter_CRM_Campaigns::delete( $id );

		if ( $deleted ) {
			/** Fired after a campaign and its queue are deleted. */
			do_action( 'unysonplus_newsletter_crm_campaign_deleted', $campaign );
		}

		return $deleted;
	}

	/**
	 * Send one copy to an arbitrary address, without touching the queue.
	 *
	 * @param int    $id
	 * @param string $email
	 *
	 * @return true|WP_Error
	 */
	public static function send_test( $id, $email ) {
		$campaign = FW_Newsletter_CRM_Campaigns::find( $id );

		if ( ! $campaign ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Campaign not found.', 'fw' ) );
		}

		$email = FW_Newsletter_CRM_Subscribers::normalize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'fw_crm_invalid_email', __( 'Enter a valid address to send the test to.', 'fw' ) );
		}

		// Render against the real subscriber if there is one, so the test shows
		// what THEY would get; otherwise a stand-in so placeholders still resolve.
		$subscriber = FW_Newsletter_CRM_Subscribers::find_by_email( $email );

		if ( ! $subscriber ) {
			$subscriber = (object) array(
				'id'                => 0,
				'email'             => $email,
				'first_name'        => __( 'Test', 'fw' ),
				'last_name'         => '',
				'unsubscribe_token' => '',
				'confirm_token'     => '',
			);
		}

		$sender = new FW_Newsletter_CRM_Sender();
		$test   = clone $campaign;
		$test->subject = __( '[TEST]', 'fw' ) . ' ' . $campaign->subject;

		return $sender->send_one( $test, $subscriber );
	}

	/**
	 * Render a campaign exactly as it would be sent, without sending or storing
	 * anything.
	 *
	 * Takes the same `$data` shape as `save_campaign()` so the editor can preview
	 * UNSAVED work — which is the whole point, since a preview you must save
	 * first is one you will not use while iterating.
	 *
	 * The rule that makes this trustworthy: it calls the SAME functions
	 * `Sender::send_one()` calls, in the same order — `with_unsubscribe()`,
	 * `Mail::replace()`, `Mail::render_body()`. Reimplementing any part of that
	 * here would produce a preview that quietly diverges from the real email,
	 * which is worse than no preview at all.
	 *
	 * @param array $data Campaign fields; `body_json` compiles, else `body`.
	 *
	 * @return array|WP_Error subject, html, text, size
	 */
	public static function preview( array $data ) {
		if ( ! current_user_can( self::capability() ) ) {
			return new WP_Error( 'fw_crm_forbidden', __( 'You are not allowed to do that.', 'fw' ) );
		}

		$body   = isset( $data['body'] ) ? (string) $data['body'] : '';
		$blocks = array();

		if ( ! empty( $data['body_json'] ) ) {
			$blocks = FW_Newsletter_CRM_Email_Compiler::normalize( $data['body_json'] );

			if ( $blocks ) {
				$body = FW_Newsletter_CRM_Email_Compiler::compile( $blocks );
			}
		}

		$subscriber = self::preview_subscriber();

		$subject = FW_Newsletter_CRM_Mail::replace(
			isset( $data['subject'] ) ? (string) $data['subject'] : '',
			$subscriber
		);

		$html = FW_Newsletter_CRM_Mail::render_body(
			FW_Newsletter_CRM_Mail::replace(
				FW_Newsletter_CRM_Sender::with_unsubscribe( $body ),
				$subscriber
			),
			$subscriber
		);

		// The plain-text part is built from the BLOCKS when there are any — the
		// same choice the sender makes — because stripping tags off compiled
		// HTML yields table scaffolding, not prose. It goes through `replace()`
		// as well: a text part that still reads "Hi {{first_name}}" while the
		// HTML beside it reads "Hi Alex" is exactly the kind of divergence a
		// preview exists to catch.
		$text = $blocks
			? FW_Newsletter_CRM_Email_Compiler::to_plain_text( $blocks )
			: trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $body ) ) );

		$text = FW_Newsletter_CRM_Mail::replace( $text, $subscriber );

		return array(
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
			'size'    => FW_Newsletter_CRM_Email_Compiler::estimate_size( $html ),
		);
	}

	/**
	 * The stand-in recipient a preview renders against.
	 *
	 * Deliberately synthetic rather than a real subscriber pulled from the
	 * audience. A real one would make every link in the preview LIVE — including
	 * the unsubscribe link, which the admin (or their browser's link prefetcher,
	 * or a corporate scanner) could fire and opt a real person out just by
	 * looking at a draft. The tokens are left empty, so the confirm/unsubscribe
	 * URLs resolve to visibly inert links.
	 *
	 * @return object
	 */
	public static function preview_subscriber() {
		$subscriber = (object) array(
			'id'                => 0,
			'email'             => 'preview@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'first_name'        => __( 'Alex', 'fw' ),
			'last_name'         => __( 'Morgan', 'fw' ),
			'status'            => 'confirmed',
			'unsubscribe_token' => '',
			'confirm_token'     => '',
		);

		/**
		 * Filters the stand-in subscriber object a campaign preview is rendered against.
		 *
		 * Change the stand-in recipient a campaign preview renders against.
		 *
		 * @param object $subscriber
		 */
		return apply_filters( 'unysonplus_newsletter_crm_preview_subscriber', $subscriber );
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Run an operation across every enabled provider. The local store is just
	 * another provider here — that is the point.
	 *
	 * @param string $op subscribe|unsubscribe|sync
	 * @param object $subscriber
	 * @param array  $args
	 *
	 * @return array [ slug => true|WP_Error ]
	 */
	public static function dispatch( $op, $subscriber, array $args = array() ) {
		$results = array();

		foreach ( self::providers() as $slug => $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}

			$result = $provider->$op( $subscriber, $args );

			$results[ $slug ] = $result;

			// A remote provider failing must never lose the local signup, so the
			// failure is recorded on the subscriber rather than thrown.
			if ( is_wp_error( $result ) && 'local' !== $slug && ! empty( $subscriber->id ) ) {
				FW_Newsletter_CRM_Subscribers::set_meta(
					$subscriber->id,
					'provider_error_' . $slug,
					$result->get_error_message()
				);
			}
		}

		return $results;
	}

	/**
	 * Turn the `list`/`lists`/`tags` args into real list & tag IDs, creating
	 * lists on demand when the setting allows it (the [newsletter] element's
	 * List ID is free text, so this is the normal path).
	 *
	 * @param array $args
	 *
	 * @return array [ 'lists' => int[], 'tags' => int[] ]
	 */
	public static function resolve_membership( array $args ) {
		$auto_create = self::setting_is_on( 'auto_create_lists', true );
		$lists       = array();
		$tags        = array();

		$raw_lists = array();

		if ( ! empty( $args['list'] ) ) {
			$raw_lists[] = $args['list'];
		}
		if ( ! empty( $args['lists'] ) ) {
			$raw_lists = array_merge( $raw_lists, (array) $args['lists'] );
		}

		foreach ( $raw_lists as $value ) {
			$id = self::resolve_object( $value, 'list', $auto_create );

			if ( $id ) {
				$lists[] = $id;
			}
		}

		foreach ( (array) ( isset( $args['tags'] ) ? $args['tags'] : array() ) as $value ) {
			$id = self::resolve_object( $value, 'tag', true );

			if ( $id ) {
				$tags[] = $id;
			}
		}

		// Nothing named? Fall back to the configured default list so every
		// subscriber is reachable by a list query.
		if ( ! $lists ) {
			$default = self::setting( 'default_list', 'default' );
			$id      = self::resolve_object( $default, 'list', true );

			if ( $id ) {
				$lists[] = $id;
			}
		}

		return array(
			'lists' => array_values( array_unique( $lists ) ),
			'tags'  => array_values( array_unique( $tags ) ),
		);
	}

	/**
	 * @param mixed  $value ID or slug.
	 * @param string $type
	 * @param bool   $create
	 *
	 * @return int
	 */
	private static function resolve_object( $value, $type, $create ) {
		if ( is_numeric( $value ) ) {
			$row = FW_Newsletter_CRM_Lists::find( (int) $value );

			return $row ? (int) $row->id : 0;
		}

		$slug = sanitize_key( (string) $value );

		if ( '' === $slug ) {
			return 0;
		}

		$row = $create
			? FW_Newsletter_CRM_Lists::get_or_create( $slug, $type )
			: FW_Newsletter_CRM_Lists::find_by_slug( $slug, $type );

		return $row ? (int) $row->id : 0;
	}

	/**
	 * The [newsletter] element collects ONE name field, so split it: first token
	 * is the first name, the remainder is the surname. The raw value is kept in
	 * meta so nothing is lost to a bad guess.
	 *
	 * @param array $args
	 *
	 * @return array [ 'first' => string, 'last' => string ]
	 */
	private static function split_name( array $args ) {
		if ( isset( $args['first_name'] ) || isset( $args['last_name'] ) ) {
			return array(
				'first' => isset( $args['first_name'] ) ? sanitize_text_field( $args['first_name'] ) : '',
				'last'  => isset( $args['last_name'] ) ? sanitize_text_field( $args['last_name'] ) : '',
			);
		}

		$name = isset( $args['name'] ) ? trim( sanitize_text_field( $args['name'] ) ) : '';

		if ( '' === $name ) {
			return array( 'first' => '', 'last' => '' );
		}

		$parts = preg_split( '/\s+/', $name, 2 );

		return array(
			'first' => $parts[0],
			'last'  => isset( $parts[1] ) ? $parts[1] : '',
		);
	}

	/**
	 * Consent evidence + anything not worth a column, merged over what is stored.
	 *
	 * @param object|null $existing
	 * @param array       $args
	 *
	 * @return array
	 */
	private static function merge_meta( $existing, array $args ) {
		$meta = array();

		if ( $existing && ! empty( $existing->meta ) ) {
			$decoded = json_decode( $existing->meta, true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! empty( $args['name'] ) ) {
			$meta['raw_name'] = sanitize_text_field( $args['name'] );
		}

		if ( ! empty( $args['consent_text'] ) ) {
			// A snapshot of what they actually agreed to — consent is evidence,
			// not a boolean.
			$meta['consent_text'] = wp_strip_all_tags( (string) $args['consent_text'] );
		}

		if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$meta = array_merge( $meta, $args['meta'] );
		}

		return $meta;
	}

	/**
	 * @param int|string|object $subscriber
	 *
	 * @return object|null
	 */
	public static function resolve_subscriber( $subscriber ) {
		if ( is_object( $subscriber ) ) {
			return $subscriber;
		}

		if ( is_numeric( $subscriber ) ) {
			return FW_Newsletter_CRM_Subscribers::find( (int) $subscriber );
		}

		return FW_Newsletter_CRM_Subscribers::find_by_email( (string) $subscriber );
	}

	/**
	 * Best-effort client IP. Consent evidence, so it is stored — but it is
	 * personal data, so the settings screen can turn it off.
	 *
	 * @return string
	 */
	public static function client_ip() {
		if ( ! self::setting_is_on( 'store_ip', true ) ) {
			return '';
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return '';
		}

		if ( self::setting_is_on( 'anonymize_ip' ) && function_exists( 'wp_privacy_anonymize_ip' ) ) {
			$ip = wp_privacy_anonymize_ip( $ip );
		}

		return $ip;
	}
}
