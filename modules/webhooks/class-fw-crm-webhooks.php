<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Outbound webhooks: when something happens in the CRM, POST it as JSON to the
 * URLs that asked for it.
 *
 * - Each endpoint picks its events (or `*` for all) and has its own secret.
 * - Every request is signed: `X-UnysonPlus-Signature: sha256=<hex>` is the
 *   HMAC-SHA256 of `<timestamp>.<raw body>` with the secret, and the timestamp is
 *   sent in `X-UnysonPlus-Timestamp`, so a receiver can refuse old or forged calls.
 * - Deliveries are queued and sent in the background (never during the request
 *   that caused them). A failure is retried with growing gaps (1 min, 5 min,
 *   30 min, 2 h, 6 h, 12 h) and then marked failed; every try is kept in the log.
 *   A retry keeps the same delivery id (`X-UnysonPlus-Delivery`), so a receiver
 *   can ignore a delivery it already handled.
 * - An endpoint that fails 20 deliveries in a row is switched off.
 *
 * Other code adds events with the `unysonplus_crm_webhook_events` filter and
 * fires them with `do_action( 'unysonplus_crm_webhook', $event, $data )`.
 */
class FW_CRM_Webhooks {

	const TICK = 'fw_crm_webhooks_tick';

	/** Seconds to wait before each retry. */
	const BACKOFF = array( 60, 300, 1800, 7200, 21600, 43200 );

	/** Final failures in a row before an endpoint is switched off. */
	const DISABLE_AFTER = 20;

	/** @var array|null active endpoints, per request */
	private static $active = null;

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'webhooks' );
	}

	/** @return string */
	public static function deliveries_table() {
		return FW_CRM_Installer::table( 'webhook_deliveries' );
	}

	/* ---------------------------------------------------------------------- *
	 * Events
	 * ---------------------------------------------------------------------- */

	/**
	 * Every event an endpoint can subscribe to: slug => { label, group }.
	 *
	 * @return array
	 */
	public static function events() {
		$events = array(
			'contact.created'           => array( 'label' => __( 'Contact created', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'contact.updated'           => array( 'label' => __( 'Contact updated', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'contact.deleted'           => array( 'label' => __( 'Contact deleted for good', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'contact.tagged'            => array( 'label' => __( 'Tag added to a contact', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'contact.untagged'          => array( 'label' => __( 'Tag removed from a contact', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'contact.subscribed'        => array( 'label' => __( 'Contact subscribed to email', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'contact.unsubscribed'      => array( 'label' => __( 'Contact unsubscribed', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'form.submitted'            => array( 'label' => __( 'Form sent (with every answer)', 'fw' ), 'group' => __( 'Contacts', 'fw' ) ),
			'task.created'              => array( 'label' => __( 'Task created', 'fw' ), 'group' => __( 'Tasks', 'fw' ) ),
			'task.completed'            => array( 'label' => __( 'Task completed', 'fw' ), 'group' => __( 'Tasks', 'fw' ) ),
		);
		if ( FW_CRM_Modules::enabled( 'companies' ) ) {
			$events += array(
				'company.created' => array( 'label' => __( 'Company created', 'fw' ), 'group' => __( 'Companies', 'fw' ) ),
				'company.updated' => array( 'label' => __( 'Company updated', 'fw' ), 'group' => __( 'Companies', 'fw' ) ),
				'company.deleted' => array( 'label' => __( 'Company deleted for good', 'fw' ), 'group' => __( 'Companies', 'fw' ) ),
			);
		}
		if ( FW_CRM_Modules::enabled( 'pipeline' ) ) {
			$events += array(
				'deal.created'       => array( 'label' => __( 'Deal created', 'fw' ), 'group' => __( 'Deals', 'fw' ) ),
				'deal.updated'       => array( 'label' => __( 'Deal updated', 'fw' ), 'group' => __( 'Deals', 'fw' ) ),
				'deal.stage_changed' => array( 'label' => __( 'Deal moved to another stage', 'fw' ), 'group' => __( 'Deals', 'fw' ) ),
				'deal.won'           => array( 'label' => __( 'Deal won', 'fw' ), 'group' => __( 'Deals', 'fw' ) ),
				'deal.lost'          => array( 'label' => __( 'Deal lost', 'fw' ), 'group' => __( 'Deals', 'fw' ) ),
				'deal.deleted'       => array( 'label' => __( 'Deal deleted for good', 'fw' ), 'group' => __( 'Deals', 'fw' ) ),
			);
		}
		if ( FW_CRM_Modules::enabled( 'email' ) ) {
			$events += array(
				'email.opened'       => array( 'label' => __( 'Campaign email opened', 'fw' ), 'group' => __( 'Email', 'fw' ) ),
				'email.clicked'      => array( 'label' => __( 'Link clicked in a campaign', 'fw' ), 'group' => __( 'Email', 'fw' ) ),
				'email.bounced'      => array( 'label' => __( 'Campaign email bounced', 'fw' ), 'group' => __( 'Email', 'fw' ) ),
				'email.complained'   => array( 'label' => __( 'Campaign email marked as spam', 'fw' ), 'group' => __( 'Email', 'fw' ) ),
				'campaign.sent'      => array( 'label' => __( 'Campaign finished sending', 'fw' ), 'group' => __( 'Email', 'fw' ) ),
			);
		}
		if ( FW_CRM_Modules::enabled( 'conversations' ) ) {
			$events['email.received'] = array( 'label' => __( 'Email reply received', 'fw' ), 'group' => __( 'Email', 'fw' ) );
		}

		/**
		 * Filters the webhook events: slug => { label, group }. Fire your own with
		 * do_action( 'unysonplus_crm_webhook', $slug, $data ).
		 */
		return (array) apply_filters( 'unysonplus_crm_webhook_events', $events );
	}

	/**
	 * Listen to the CRM and turn what happens into webhook events.
	 */
	public static function init() {
		add_action( 'unysonplus_crm_webhook', array( __CLASS__, 'emit' ), 10, 2 );
		add_action( self::TICK, array( __CLASS__, 'run' ) );
		add_action( 'fw_crm_empty_bin', array( __CLASS__, 'prune' ) );

		$contact = function ( $id ) {
			return FW_CRM_Contacts::get( (int) $id );
		};

		add_action( 'unysonplus_crm_contact_created', function ( $c ) {
			self::emit( 'contact.created', (array) $c );
		} );
		add_action( 'unysonplus_crm_contact_updated', function ( $c, $before = null, $changes = array() ) {
			self::emit( 'contact.updated', array( 'contact' => (array) $c, 'changed' => array_values( array_keys( (array) $changes ) ) ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_contact_deleted', function ( $row ) {
			$row = (object) $row;
			self::emit( 'contact.deleted', array( 'id' => (int) ( $row->id ?? 0 ), 'email' => (string) ( $row->email ?? '' ) ) );
		} );
		add_action( 'unysonplus_crm_pivot_changed', function ( $contact_id, $object_id, $type, $op, $source = '' ) use ( $contact ) {
			if ( 'tag' !== $type ) {
				return;
			}
			$tag = FW_CRM_Lists::find( (int) $object_id );
			self::emit( 'add' === $op ? 'contact.tagged' : 'contact.untagged', array(
				'contact' => $contact( $contact_id ),
				'tag'     => $tag ? array( 'id' => (int) $tag->id, 'slug' => (string) $tag->slug, 'name' => (string) $tag->title ) : array( 'id' => (int) $object_id ),
				'source'  => (string) $source,
			) );
		}, 10, 5 );
		foreach ( array( 'unysonplus_crm_subscriber_opted_in', 'unysonplus_crm_subscriber_resubscribed', 'unysonplus_crm_subscriber_confirmed' ) as $hook ) {
			add_action( $hook, function ( $s ) use ( $contact ) {
				self::emit( 'contact.subscribed', $contact( is_object( $s ) ? $s->id : $s ) );
			} );
		}
		add_action( 'unysonplus_crm_subscriber_unsubscribed', function ( $s ) use ( $contact ) {
			self::emit( 'contact.unsubscribed', $contact( is_object( $s ) ? $s->id : $s ) );
		} );

		add_action( 'unysonplus_crm_form_submitted', function ( $contact_id, $ctx, $created ) use ( $contact ) {
			$answers = array();
			foreach ( (array) ( $ctx['fields'] ?? array() ) as $f ) {
				if ( 'notice' !== ( $f['type'] ?? '' ) ) {
					$answers[] = array( 'id' => (string) $f['id'], 'label' => (string) $f['label'], 'type' => (string) $f['type'], 'value' => $f['value'] );
				}
			}
			self::emit( 'form.submitted', array(
				'contact'     => $contact( $contact_id ),
				'new_contact' => (bool) $created,
				'form_id'     => (string) ( $ctx['form_id'] ?? '' ),
				'form_title'  => (string) apply_filters( 'fw_ext_forms_entry_form_title', (string) ( $ctx['form_id'] ?? '' ), (array) ( $ctx['payload'] ?? array() ) ),
				'entry_id'    => (int) ( $ctx['entry_id'] ?? 0 ),
				'answers'     => $answers,
			) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_company_created', function ( $co ) {
			self::emit( 'company.created', self::company( $co ) );
		} );
		add_action( 'unysonplus_crm_company_updated', function ( $co ) {
			self::emit( 'company.updated', self::company( $co ) );
		} );
		add_action( 'unysonplus_crm_company_deleted', function ( $co ) {
			$co = (object) $co;
			self::emit( 'company.deleted', array( 'id' => (int) ( $co->id ?? 0 ), 'name' => (string) ( $co->name ?? '' ) ) );
		} );

		add_action( 'unysonplus_crm_deal_created', function ( $d ) {
			self::emit( 'deal.created', (array) $d );
		} );
		add_action( 'unysonplus_crm_deal_updated', function ( $d, $before = null, $changes = array() ) {
			self::emit( 'deal.updated', array( 'deal' => (array) $d, 'changed' => array_values( array_keys( (array) $changes ) ) ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_deal_stage_changed', function ( $d, $from = 0, $to = 0 ) {
			self::emit( 'deal.stage_changed', array( 'deal' => (array) $d, 'from_stage_id' => (int) ( is_object( $from ) ? $from->id : $from ), 'to_stage_id' => (int) ( is_object( $to ) ? $to->id : $to ) ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_deal_won', function ( $d ) {
			self::emit( 'deal.won', (array) $d );
		} );
		add_action( 'unysonplus_crm_deal_lost', function ( $d, $reason = '' ) {
			self::emit( 'deal.lost', array( 'deal' => (array) $d, 'reason' => (string) $reason ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_deal_deleted', function ( $d ) {
			$d = (object) $d;
			self::emit( 'deal.deleted', array( 'id' => (int) ( $d->id ?? 0 ), 'title' => (string) ( $d->title ?? '' ) ) );
		} );

		add_action( 'unysonplus_crm_task_created', function ( $t ) {
			self::emit( 'task.created', self::task( $t ) );
		} );
		add_action( 'unysonplus_crm_task_completed', function ( $t, $outcome = '' ) {
			self::emit( 'task.completed', array( 'task' => self::task( $t ), 'outcome' => (string) $outcome ) );
		}, 10, 2 );

		add_action( 'unysonplus_crm_email_event', function ( $type, $row, $link_id = 0 ) use ( $contact ) {
			$map = array( 'open' => 'email.opened', 'click' => 'email.clicked', 'bounce' => 'email.bounced', 'complaint' => 'email.complained' );
			if ( ! isset( $map[ $type ] ) ) {
				return;
			}
			$data = array(
				'campaign_id' => (int) $row->campaign_id,
				'contact'     => $contact( (int) $row->subscriber_id ),
			);
			if ( 'click' === $type && class_exists( 'FW_CRM_Tracking' ) ) {
				$data['url'] = FW_CRM_Tracking::link_url( (int) $link_id );
			}
			self::emit( $map[ $type ], $data );
		}, 10, 3 );
		add_action( 'unysonplus_crm_campaign_sent', function ( $campaign ) {
			$c = is_object( $campaign ) ? $campaign : ( class_exists( 'FW_CRM_Campaigns' ) ? FW_CRM_Campaigns::find( (int) $campaign ) : null );
			if ( $c ) {
				self::emit( 'campaign.sent', array( 'id' => (int) $c->id, 'title' => (string) $c->title, 'subject' => (string) $c->subject, 'total' => (int) $c->total, 'sent' => (int) $c->sent, 'failed' => (int) $c->failed ) );
			}
		} );
		add_action( 'unysonplus_crm_email_received', function ( $contact_id, $m, $deal = 0 ) use ( $contact ) {
			self::emit( 'email.received', array(
				'contact' => $contact( $contact_id ),
				'subject' => (string) ( $m['subject'] ?? '' ),
				'from'    => (string) ( $m['from_email'] ?? '' ),
				'text'    => (string) wp_trim_words( (string) ( $m['text'] ?? '' ), 80 ),
				'deal_id' => (int) $deal,
			) );
		}, 10, 3 );
	}

	/**
	 * @param object|array $co
	 *
	 * @return array
	 */
	private static function company( $co ) {
		$co  = (object) $co;
		$out = array( 'id' => (int) ( $co->id ?? 0 ) );
		foreach ( array( 'name', 'domain', 'website', 'phone', 'email', 'industry', 'size', 'city', 'region', 'country', 'created_at' ) as $k ) {
			$out[ $k ] = isset( $co->$k ) ? (string) $co->$k : '';
		}
		$out['owner_id'] = (int) ( $co->owner_id ?? 0 );
		if ( $out['id'] ) {
			$out['fields'] = FW_CRM_Fields::get_values( 'company', $out['id'] );
		}
		return $out;
	}

	/**
	 * @param object|array $t
	 *
	 * @return array
	 */
	private static function task( $t ) {
		$t = (object) $t;
		return array(
			'id'          => (int) ( $t->id ?? 0 ),
			'title'       => (string) ( $t->title ?? '' ),
			'type'        => (string) ( $t->type ?? '' ),
			'due_at'      => $t->due_at ?? null,
			'done_at'     => $t->done_at ?? null,
			'owner_id'    => (int) ( $t->owner_id ?? 0 ),
			'object_type' => (string) ( $t->object_type ?? '' ),
			'object_id'   => (int) ( $t->object_id ?? 0 ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Endpoints
	 * ---------------------------------------------------------------------- */

	/**
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param object $row
	 *
	 * @return object
	 */
	private static function hydrate( $row ) {
		$events      = json_decode( (string) $row->events, true );
		$row->events = is_array( $events ) ? $events : array();
		return $row;
	}

	/**
	 * Create or update an endpoint.
	 *
	 * @param array $data { name, url, events[], active }
	 * @param int   $id   0 = new
	 *
	 * @return int|WP_Error the id
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$existing = $id ? self::find( $id ) : null;
		if ( $id && ! $existing ) {
			return new WP_Error( 'fw_crm_webhook', __( 'Webhook not found.', 'fw' ) );
		}
		$row = array();
		if ( isset( $data['url'] ) || ! $existing ) {
			$url = esc_url_raw( trim( (string) ( $data['url'] ?? '' ) ), array( 'http', 'https' ) );
			if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! wp_parse_url( $url, PHP_URL_HOST ) || ( ! self::allow_local() && ! wp_http_validate_url( $url ) ) ) {
				return new WP_Error( 'fw_crm_webhook_url', __( 'Enter the full web address that should receive the events, starting with https://.', 'fw' ) );
			}
			$row['url'] = $url;
		}
		if ( isset( $data['name'] ) || ! $existing ) {
			$row['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
			if ( '' === $row['name'] ) {
				$row['name'] = (string) wp_parse_url( $row['url'] ?? $existing->url, PHP_URL_HOST );
			}
		}
		if ( isset( $data['events'] ) || ! $existing ) {
			$known  = array_keys( self::events() );
			$events = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $data['events'] ?? array() ) ), function ( $e ) use ( $known ) {
				return '*' === $e || in_array( $e, $known, true ) || preg_match( '/^[a-z0-9_]+\.[a-z0-9_.]+$/', $e );
			} ) ) );
			if ( ! $events ) {
				return new WP_Error( 'fw_crm_webhook_events', __( 'Choose at least one event to send.', 'fw' ) );
			}
			$row['events'] = wp_json_encode( $events );
		}
		if ( isset( $data['active'] ) ) {
			$row['active'] = $data['active'] ? 1 : 0;
			if ( $row['active'] ) {
				$row['failures'] = 0;
			}
		}
		$row['updated_at'] = current_time( 'mysql', true );

		if ( $existing ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		} else {
			$row += array(
				'secret'     => self::new_secret(),
				'active'     => 1,
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			);
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		}
		self::$active = null;
		return (int) $id;
	}

	/**
	 * @param int $id
	 *
	 * @return string the new secret
	 */
	public static function rotate_secret( $id ) {
		global $wpdb;
		$secret = self::new_secret();
		$wpdb->update( self::table(), array( 'secret' => $secret, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
		self::$active = null;
		return $secret;
	}

	/**
	 * Delete an endpoint and its delivery log.
	 *
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		$wpdb->delete( self::deliveries_table(), array( 'webhook_id' => (int) $id ) );
		self::$active = null;
	}

	/** @return string */
	private static function new_secret() {
		return 'whsec_' . wp_generate_password( 32, false, false );
	}

	/**
	 * May webhooks reach addresses on this machine or a private network? Only on a
	 * local or development site, or when the filter says so; otherwise a webhook
	 * cannot be used to probe the server's own network.
	 *
	 * @return bool
	 */
	public static function allow_local() {
		$local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		/** Filters whether webhooks may be sent to local and private network addresses. */
		return (bool) apply_filters( 'unysonplus_crm_webhook_allow_local', $local );
	}

	/* ---------------------------------------------------------------------- *
	 * Queue and delivery
	 * ---------------------------------------------------------------------- */

	/**
	 * Queue an event for every active endpoint that asked for it.
	 *
	 * @param string $event
	 * @param mixed  $data
	 *
	 * @return int deliveries queued
	 */
	public static function emit( $event, $data ) {
		$event = (string) $event;
		if ( null === self::$active ) {
			self::$active = array_values( array_filter( self::all(), function ( $h ) {
				return (int) $h->active;
			} ) );
		}
		if ( ! self::$active || '' === $event ) {
			return 0;
		}
		$targets = array_filter( self::$active, function ( $h ) use ( $event ) {
			return in_array( '*', $h->events, true ) || in_array( $event, $h->events, true );
		} );
		if ( ! $targets ) {
			return 0;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );
		$n   = 0;
		foreach ( $targets as $h ) {
			$uuid    = wp_generate_uuid4();
			$payload = array(
				'id'         => $uuid,
				'event'      => $event,
				'created_at' => gmdate( 'c' ),
				'site'       => home_url( '/' ),
				'data'       => $data,
			);

			/** Filters a webhook payload before it is queued. Return null to skip this endpoint. */
			$payload = apply_filters( 'unysonplus_crm_webhook_payload', $payload, $event, $h );
			if ( null === $payload ) {
				continue;
			}
			$wpdb->insert( self::deliveries_table(), array(
				'webhook_id'      => (int) $h->id,
				'uuid'            => $uuid,
				'event'           => substr( $event, 0, 100 ),
				'payload'         => wp_json_encode( $payload ),
				'status'          => 'pending',
				'next_attempt_at' => $now,
				'created_at'      => $now,
			) );
			$n++;
		}
		if ( $n ) {
			self::schedule( 0 );
		}
		return $n;
	}

	/**
	 * Make sure a background run is due within `$in` seconds.
	 *
	 * @param int $in
	 */
	public static function schedule( $in = 0 ) {
		$next = wp_next_scheduled( self::TICK );
		$when = time() + max( 0, (int) $in );
		if ( $next && $next <= $when ) {
			return;
		}
		if ( $next ) {
			wp_unschedule_event( $next, self::TICK );
		}
		wp_schedule_single_event( $when, self::TICK );
	}

	/**
	 * The background run: send what is due, then book the next run.
	 *
	 * @param int $limit
	 *
	 * @return int deliveries attempted
	 */
	public static function run( $limit = 25 ) {
		global $wpdb;
		$d   = self::deliveries_table();
		$now = current_time( 'mysql', true );

		// A run that died mid-send leaves rows in "sending"; give them back after 10 minutes.
		$wpdb->query( $wpdb->prepare( "UPDATE {$d} SET status = 'pending' WHERE status = 'sending' AND next_attempt_at < %s", gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$d} WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d", $now, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$start = microtime( true );
		$done  = 0;
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $start > 20 ) {
				break;
			}
			// Claim it, so two overlapping runs never send the same delivery.
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$d} SET status = 'sending', next_attempt_at = %s WHERE id = %d AND status = 'pending'", $now, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $claimed ) {
				self::deliver( (int) $id );
				$done++;
			}
		}

		$next = $wpdb->get_var( "SELECT MIN(next_attempt_at) FROM {$d} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $next ) {
			self::schedule( max( 0, strtotime( $next . ' UTC' ) - time() ) );
		}
		return $done;
	}

	/**
	 * Send one delivery now and record the result.
	 *
	 * @param int $id
	 *
	 * @return array { ok, code, body, error }
	 */
	public static function deliver( $id ) {
		global $wpdb;
		$d   = self::deliveries_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$d} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$h   = $row ? self::find( (int) $row->webhook_id ) : null;
		if ( ! $row || ! $h ) {
			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => __( 'Webhook not found.', 'fw' ) );
		}

		$result   = self::post( $h, (string) $row->payload, (string) $row->uuid, (string) $row->event );
		$attempts = (int) $row->attempts + 1;
		$now      = current_time( 'mysql', true );
		$update   = array(
			'attempts'      => $attempts,
			'response_code' => (int) $result['code'],
			'response_body' => '' !== $result['error'] ? $result['error'] : substr( (string) $result['body'], 0, 1000 ),
			'duration_ms'   => (int) $result['ms'],
		);
		if ( $result['ok'] ) {
			$update += array( 'status' => 'sent', 'delivered_at' => $now, 'next_attempt_at' => null );
			$wpdb->update( self::table(), array( 'failures' => 0, 'last_status' => (int) $result['code'], 'last_delivery_at' => $now ), array( 'id' => (int) $h->id ) );
		} elseif ( $attempts <= count( self::BACKOFF ) ) {
			$update += array( 'status' => 'pending', 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::BACKOFF[ $attempts - 1 ] ) );
			$wpdb->update( self::table(), array( 'last_status' => (int) $result['code'], 'last_delivery_at' => $now ), array( 'id' => (int) $h->id ) );
		} else {
			$update  += array( 'status' => 'failed', 'next_attempt_at' => null );
			$failures = (int) $h->failures + 1;
			$hook     = array( 'failures' => $failures, 'last_status' => (int) $result['code'], 'last_delivery_at' => $now );
			if ( $failures >= self::DISABLE_AFTER ) {
				$hook['active'] = 0;
				self::$active   = null;
			}
			$wpdb->update( self::table(), $hook, array( 'id' => (int) $h->id ) );
		}
		$wpdb->update( $d, $update, array( 'id' => (int) $row->id ) );

		/** Fires after a webhook delivery attempt. */
		do_action( 'unysonplus_crm_webhook_delivered', (int) $row->id, $result, $h );

		return $result;
	}

	/**
	 * The signed HTTP request.
	 *
	 * @param object $h
	 * @param string $body
	 * @param string $uuid
	 * @param string $event
	 *
	 * @return array { ok, code, body, error, ms }
	 */
	public static function post( $h, $body, $uuid, $event ) {
		$ts   = (string) time();
		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => array(
				'Content-Type'          => 'application/json; charset=utf-8',
				'User-Agent'            => 'Unyson+ CRM webhooks (' . home_url( '/' ) . ')',
				'X-UnysonPlus-Event'    => $event,
				'X-UnysonPlus-Delivery' => $uuid,
				'X-UnysonPlus-Timestamp' => $ts,
				'X-UnysonPlus-Signature' => 'sha256=' . self::sign( $ts, $body, (string) $h->secret ),
			),
			'body'        => $body,
		);

		/** Filters the request a webhook delivery sends (headers, timeout). */
		$args  = (array) apply_filters( 'unysonplus_crm_webhook_request_args', $args, $h, $event );
		$start = microtime( true );
		$res   = self::allow_local() ? wp_remote_post( $h->url, $args ) : wp_safe_remote_post( $h->url, $args );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => $res->get_error_message(), 'ms' => $ms );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array( 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => (string) wp_remote_retrieve_body( $res ), 'error' => '', 'ms' => $ms );
	}

	/**
	 * The signature a receiver recomputes: HMAC-SHA256 of "<timestamp>.<body>".
	 *
	 * @param string $timestamp
	 * @param string $body
	 * @param string $secret
	 *
	 * @return string hex
	 */
	public static function sign( $timestamp, $body, $secret ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Send a `ping` to one endpoint right now (not queued) and log it.
	 *
	 * @param int $id
	 *
	 * @return array|WP_Error the result
	 */
	public static function test( $id ) {
		global $wpdb;
		$h = self::find( $id );
		if ( ! $h ) {
			return new WP_Error( 'fw_crm_webhook', __( 'Webhook not found.', 'fw' ) );
		}
		$uuid = wp_generate_uuid4();
		$now  = current_time( 'mysql', true );
		$wpdb->insert( self::deliveries_table(), array(
			'webhook_id'      => (int) $h->id,
			'uuid'            => $uuid,
			'event'           => 'ping',
			'payload'         => wp_json_encode( array(
				'id'         => $uuid,
				'event'      => 'ping',
				'created_at' => gmdate( 'c' ),
				'site'       => home_url( '/' ),
				'data'       => array( 'webhook_id' => (int) $h->id, 'message' => __( 'A test from the Unyson+ CRM. If you can read this, the webhook works.', 'fw' ) ),
			) ),
			'status'          => 'sending',
			'next_attempt_at' => $now,
			'created_at'      => $now,
		) );
		$did    = (int) $wpdb->insert_id;
		$result = self::deliver( $did );
		if ( ! $result['ok'] ) {
			// A test is not retried: it was a question, and it has been answered.
			$wpdb->update( self::deliveries_table(), array( 'status' => 'failed', 'next_attempt_at' => null ), array( 'id' => $did ) );
		}
		$result['delivery_id'] = $did;
		return $result;
	}

	/**
	 * Send a failed (or waiting) delivery again now.
	 *
	 * @param int $delivery_id
	 *
	 * @return array|WP_Error
	 */
	public static function retry( $delivery_id ) {
		global $wpdb;
		$d   = self::deliveries_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$d} WHERE id = %d", (int) $delivery_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return new WP_Error( 'fw_crm_webhook', __( 'Delivery not found.', 'fw' ) );
		}
		if ( 'sent' === $row->status ) {
			return new WP_Error( 'fw_crm_webhook_sent', __( 'That delivery already arrived.', 'fw' ) );
		}
		// A manual retry starts a fresh round of automatic retries.
		$wpdb->update( $d, array( 'status' => 'sending', 'attempts' => 0, 'next_attempt_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) );
		return self::deliver( (int) $row->id );
	}

	/**
	 * The delivery log, newest first.
	 *
	 * @param array $args { webhook_id, status, per_page, paged }
	 *
	 * @return array [ rows, total ]
	 */
	public static function deliveries( array $args = array() ) {
		global $wpdb;
		$args  = array_merge( array( 'webhook_id' => 0, 'status' => '', 'per_page' => 50, 'paged' => 1 ), $args );
		$d     = self::deliveries_table();
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['webhook_id'] ) {
			$where[] = 'webhook_id = %d';
			$vals[]  = (int) $args['webhook_id'];
		}
		if ( '' !== (string) $args['status'] ) {
			$where[] = 'status = %s';
			$vals[]  = (string) $args['status'];
		}
		$sql   = implode( ' AND ', $where );
		$per   = max( 1, min( 200, (int) $args['per_page'] ) );
		$off   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$d} WHERE {$sql}", $vals ) : "SELECT COUNT(*) FROM {$d} WHERE {$sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$d} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per, $off ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( (array) $rows, $total );
	}

	/**
	 * Counts per status for one endpoint over the last 7 days.
	 *
	 * @param int $id
	 *
	 * @return array status => count
	 */
	public static function stats( $id ) {
		global $wpdb;
		$d    = self::deliveries_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM {$d} WHERE webhook_id = %d AND created_at >= %s GROUP BY status", (int) $id, gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array( 'sent' => 0, 'pending' => 0, 'sending' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Drop delivered and failed log rows older than 30 days (daily).
	 */
	public static function prune() {
		global $wpdb;
		$d = self::deliveries_table();

		/** Filters how many days the webhook delivery log keeps finished deliveries. */
		$days = max( 1, (int) apply_filters( 'unysonplus_crm_webhook_log_days', 30 ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$d} WHERE status IN ('sent','failed') AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
