<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The audit log: who changed what in the CRM, and when.
 *
 * Unlike the timeline (what happened WITH a person, shown to the team), the audit
 * log records what people DID to the data: edits with the old and new values,
 * deletions, merges, exports, imports, settings. It is built only from the change
 * events the CRM already fires, so every module and extension that goes through
 * the API is covered without extra code. Entries older than a year are removed by
 * the daily bin job (filter unysonplus_crm_audit_days; 0 keeps everything).
 */
class FW_CRM_Audit {

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'audit' );
	}

	public static function init() {
		add_action( 'unysonplus_crm_contact_created', function ( $c ) {
			self::log( 'contact', (int) ( $c['id'] ?? 0 ), 'created', array( 'name' => $c['name'] ?? '', 'email' => $c['email'] ?? '' ) );
		} );
		add_action( 'unysonplus_crm_contact_updated', function ( $c, $before = null, $changes = array() ) {
			if ( $changes ) {
				self::log( 'contact', (int) $c['id'], 'updated', (array) $changes );
			}
		}, 10, 3 );
		add_action( 'unysonplus_crm_contact_trashed', function ( $id ) {
			self::log( 'contact', (int) $id, 'trashed' );
		} );
		add_action( 'unysonplus_crm_contact_restored', function ( $id ) {
			self::log( 'contact', (int) $id, 'restored' );
		} );
		add_action( 'unysonplus_crm_contact_deleted', function ( $row ) {
			self::log( 'contact', (int) $row->id, 'deleted', array( 'email' => (string) $row->email, 'name' => trim( $row->first_name . ' ' . $row->last_name ) ) );
		} );
		add_action( 'unysonplus_crm_subscriber_deleted', function ( $row ) {
			self::log( 'contact', (int) $row->id, 'deleted', array( 'email' => (string) $row->email, 'name' => trim( $row->first_name . ' ' . $row->last_name ) ) );
		} );
		add_action( 'unysonplus_crm_contacts_merged', function ( $kept, $removed ) {
			self::log( 'contact', (int) $kept, 'merged', array( 'merged_id' => (int) $removed->id, 'email' => (string) $removed->email ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_user_linked', function ( $contact, $user ) {
			self::log( 'contact', (int) $contact, 'user_linked', array( 'user_id' => (int) $user ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_user_unlinked', function ( $contact, $user ) {
			self::log( 'contact', (int) $contact, 'user_unlinked', array( 'user_id' => (int) $user ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_company_created', function ( $row ) {
			if ( $row ) {
				self::log( 'company', (int) $row->id, 'created', array( 'name' => (string) $row->name ) );
			}
		} );
		add_action( 'unysonplus_crm_company_updated', function ( $row, $before ) {
			if ( ! $row || ! $before ) {
				return;
			}
			$changes = array();
			foreach ( get_object_vars( $row ) as $k => $v ) {
				if ( 'updated_at' !== $k && (string) $v !== (string) ( $before->$k ?? '' ) ) {
					$changes[ $k ] = array( 'old' => $before->$k ?? null, 'new' => $v );
				}
			}
			if ( $changes ) {
				$action = isset( $changes['deleted_at'] ) ? ( $row->deleted_at ? 'trashed' : 'restored' ) : 'updated';
				self::log( 'company', (int) $row->id, $action, 'updated' === $action ? $changes : array() );
			}
		}, 10, 2 );
		add_action( 'unysonplus_crm_company_deleted', function ( $row ) {
			self::log( 'company', (int) $row->id, 'deleted', array( 'name' => (string) $row->name ) );
		} );
		add_action( 'unysonplus_crm_segment_saved', function ( $s ) {
			if ( $s ) {
				self::log( 'segment', (int) $s->id, 'saved', array( 'title' => (string) $s->title ) );
			}
		} );
		add_action( 'unysonplus_crm_segment_deleted', function ( $s ) {
			self::log( 'segment', (int) $s->id, 'deleted', array( 'title' => (string) $s->title ) );
		} );
		add_action( 'unysonplus_crm_list_deleted', function ( $l ) {
			self::log( 'list', (int) $l->id, 'deleted', array( 'title' => (string) $l->title, 'type' => (string) $l->type ) );
		} );
		add_action( 'unysonplus_crm_campaign_started', function ( $c, $queued = 0 ) {
			self::log( 'campaign', (int) $c->id, 'sending', array( 'title' => (string) $c->title, 'recipients' => (int) $queued ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_exported', function ( $args, $rows ) {
			self::log( 'contacts', 0, 'exported', array( 'filters' => array_filter( (array) $args ), 'rows' => (int) $rows ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_import_started', function ( $info ) {
			self::log( 'contacts', 0, 'import_started', (array) $info );
		} );
		add_action( 'unysonplus_crm_settings_saved', function ( $changed ) {
			self::log( 'settings', 0, 'saved', array( 'changed' => array_values( (array) $changed ) ) );
		} );
		add_action( 'unysonplus_crm_deal_created', function ( $d ) {
			if ( $d ) {
				self::log( 'deal', (int) $d['id'], 'created', array( '_label' => $d['title'], 'value' => $d['value'] ) );
			}
		} );
		add_action( 'unysonplus_crm_deal_updated', function ( $d, $before = null, $changes = array() ) {
			if ( $d && $changes ) {
				self::log( 'deal', (int) $d['id'], 'updated', array_merge( (array) $changes, array( '_label' => $d['title'] ) ) );
			}
		}, 10, 3 );
		add_action( 'unysonplus_crm_deal_stage_changed', function ( $d, $from, $to ) {
			if ( $d ) {
				self::log( 'deal', (int) $d['id'], 'stage', array( '_label' => $d['title'], 'from' => $from ? $from->name : '', 'to' => $to ? $to->name : '' ) );
			}
		}, 10, 3 );
		add_action( 'unysonplus_crm_deal_trashed', function ( $id ) {
			self::log( 'deal', (int) $id, 'trashed' );
		} );
		add_action( 'unysonplus_crm_deal_deleted', function ( $d ) {
			self::log( 'deal', (int) $d->id, 'deleted', array( '_label' => (string) $d->title ) );
		} );
		add_action( 'fw_crm_empty_bin', array( __CLASS__, 'purge_expired' ), 20 );
	}

	/**
	 * Append an entry. The person is the current user (0 = the system: cron, a
	 * visitor's form, another extension's background job).
	 *
	 * @param string $object_type contact|company|segment|list|campaign|contacts|settings|…
	 * @param int    $object_id
	 * @param string $action
	 * @param array  $details
	 *
	 * @return int
	 */
	public static function log( $object_type, $object_id, $action, array $details = array() ) {
		global $wpdb;

		/** Filters whether to record an audit entry (return false to skip it). */
		if ( ! apply_filters( 'unysonplus_crm_audit_record', true, $object_type, $object_id, $action, $details ) ) {
			return 0;
		}

		// Keep a readable name with the entry, so it still makes sense after the
		// record itself is gone.
		if ( ! isset( $details['_label'] ) && $object_id ) {
			if ( 'contact' === $object_type && ( $c = FW_CRM_Subscribers::find( (int) $object_id ) ) ) {
				$details['_label'] = trim( $c->first_name . ' ' . $c->last_name ) ?: (string) ( $c->email ?: $c->phone );
			} elseif ( 'company' === $object_type && ( $co = FW_CRM_Companies::find( (int) $object_id ) ) ) {
				$details['_label'] = (string) $co->name;
			} elseif ( 'deal' === $object_type && class_exists( 'FW_CRM_Deals' ) && ( $dl = FW_CRM_Deals::find( (int) $object_id ) ) ) {
				$details['_label'] = (string) $dl->title;
			}
		}

		$ok = $wpdb->insert( self::table(), array(
			'actor_id'    => (int) get_current_user_id(),
			'object_type' => substr( sanitize_key( (string) $object_type ), 0, 30 ),
			'object_id'   => (int) $object_id,
			'action'      => substr( sanitize_key( (string) $action ), 0, 40 ),
			'details'     => $details ? wp_json_encode( $details ) : '',
			'ip'          => class_exists( 'FW_CRM_Service' ) && get_current_user_id() ? substr( (string) FW_CRM_Service::client_ip(), 0, 45 ) : '',
			'created_at'  => current_time( 'mysql' ),
		) );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array $args actor, object_type, object_id, action, per_page, paged
	 *
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		$where = array( '1=1' );
		if ( isset( $args['actor'] ) && '' !== $args['actor'] ) {
			$where[] = $wpdb->prepare( 'actor_id = %d', (int) $args['actor'] );
		}
		if ( ! empty( $args['object_type'] ) ) {
			$where[] = $wpdb->prepare( 'object_type = %s', sanitize_key( (string) $args['object_type'] ) );
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[] = $wpdb->prepare( 'object_id = %d', (int) $args['object_id'] );
		}
		if ( ! empty( $args['action'] ) ) {
			$where[] = $wpdb->prepare( 'action = %s', sanitize_key( (string) $args['action'] ) );
		}
		$where = implode( ' AND ', $where );
		$per   = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page  = max( 1, (int) ( $args['paged'] ?? 1 ) );

		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			$d          = json_decode( (string) $r->details, true );
			$r->details = is_array( $d ) ? $d : array();
		}

		return array( $rows, $total );
	}

	/**
	 * Remove entries older than the retention period (365 days; filter
	 * unysonplus_crm_audit_days, 0 = keep everything).
	 *
	 * @return int
	 */
	public static function purge_expired() {
		global $wpdb;

		/** Filters how many days audit log entries are kept (0 = forever). */
		$days = max( 0, (int) apply_filters( 'unysonplus_crm_audit_days', 365 ) );
		if ( ! $days ) {
			return 0;
		}
		$table = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * A readable line for an entry: "changed Stage, Job title".
	 *
	 * @param object $r
	 *
	 * @return string
	 */
	public static function describe( $r ) {
		$labels = array(
			'created'        => __( 'created', 'fw' ),
			'updated'        => __( 'changed', 'fw' ),
			'trashed'        => __( 'moved to the bin', 'fw' ),
			'restored'       => __( 'restored', 'fw' ),
			'deleted'        => __( 'deleted for good', 'fw' ),
			'merged'         => __( 'merged a duplicate into', 'fw' ),
			'user_linked'    => __( 'linked a site account to', 'fw' ),
			'user_unlinked'  => __( 'unlinked the site account of', 'fw' ),
			'saved'          => __( 'saved', 'fw' ),
			'sending'        => __( 'started sending', 'fw' ),
			'exported'       => __( 'exported contacts', 'fw' ),
			'import_started' => __( 'started an import', 'fw' ),
			'stage'          => __( 'moved the deal', 'fw' ),
		);

		if ( 'deleted' === $r->action && ! in_array( $r->object_type, array( 'contact', 'company' ), true ) ) {
			return __( 'deleted', 'fw' ); // Only contacts and companies have a bin.
		}

		return $labels[ $r->action ] ?? str_replace( '_', ' ', (string) $r->action );
	}
}
