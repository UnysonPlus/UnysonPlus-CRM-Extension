<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The timeline: one row per thing that happened to a record (a contact, a
 * company, later a deal or a case).
 *
 * Every module writes here through log(), and the profile screens read it back
 * with for_record(). Logging on a contact also stamps its last_activity_at, so
 * "contacts quiet for 90 days" is an indexed column, not a join.
 *
 * init() wires the CRM's own events and the Forms extension's stored entries into
 * the timeline; other extensions call log() directly.
 */
class FW_CRM_Activity {

	public static function init() {
		add_action( 'unysonplus_crm_subscriber_added', array( __CLASS__, '_on_added' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_resubscribed', array( __CLASS__, '_on_resubscribed' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_opted_in', array( __CLASS__, '_on_opted_in' ), 10, 2 );
		add_action( 'unysonplus_crm_subscriber_confirmed', array( __CLASS__, '_on_confirmed' ) );
		add_action( 'unysonplus_crm_subscriber_unsubscribed', array( __CLASS__, '_on_unsubscribed' ) );
		add_action( 'fw_ext_forms_entry_stored', array( __CLASS__, '_on_form_entry' ), 20, 2 );
		// Forms stores the entry BEFORE its actions run (the CRM action is one of them), so a
		// first-time sender has no contact yet when the entry is stored. Log after the actions.
		add_action( 'fw_ext_forms_frontend_submit', array( __CLASS__, '_after_form' ), 30 );
	}

	/** @var array|null The entry stored during this submission, waiting for its contact. */
	private static $pending_entry = null;

	/**
	 * The id of the form entry stored during this request (Forms stores it in the same
	 * action loop that runs the CRM's action, so the action's context does not have it yet).
	 *
	 * @return int
	 */
	public static function pending_entry_id() {
		return self::$pending_entry ? (int) self::$pending_entry[0] : 0;
	}

	/** @var array Emails whose submission the CRM form action already logged (with the answers) this request. */
	public static $form_logged = array();

	/**
	 * Timeline entry types with their label and icon. Extensions add theirs with
	 * the `unysonplus_crm_activity_types` filter (type => { label, icon }).
	 *
	 * @return array
	 */
	public static function types() {
		$types = array(
			'created'      => array( 'label' => __( 'Added', 'fw' ), 'icon' => 'dashicons-plus-alt2' ),
			'note'         => array( 'label' => __( 'Note', 'fw' ), 'icon' => 'dashicons-edit' ),
			'form'         => array( 'label' => __( 'Form', 'fw' ), 'icon' => 'dashicons-feedback' ),
			'email'        => array( 'label' => __( 'Email', 'fw' ), 'icon' => 'dashicons-email' ),
			'subscribed'   => array( 'label' => __( 'Subscribed', 'fw' ), 'icon' => 'dashicons-yes' ),
			'confirmed'    => array( 'label' => __( 'Confirmed', 'fw' ), 'icon' => 'dashicons-yes-alt' ),
			'unsubscribed' => array( 'label' => __( 'Unsubscribed', 'fw' ), 'icon' => 'dashicons-dismiss' ),
			'stage'        => array( 'label' => __( 'Stage', 'fw' ), 'icon' => 'dashicons-flag' ),
			'owner'        => array( 'label' => __( 'Owner', 'fw' ), 'icon' => 'dashicons-admin-users' ),
			'company'      => array( 'label' => __( 'Company', 'fw' ), 'icon' => 'dashicons-building' ),
			'contact'      => array( 'label' => __( 'Contact', 'fw' ), 'icon' => 'dashicons-groups' ),
			'user'         => array( 'label' => __( 'Site account', 'fw' ), 'icon' => 'dashicons-admin-network' ),
			'restored'     => array( 'label' => __( 'Restored', 'fw' ), 'icon' => 'dashicons-backup' ),
			'trashed'      => array( 'label' => __( 'Moved to the bin', 'fw' ), 'icon' => 'dashicons-trash' ),
			'merged'       => array( 'label' => __( 'Merged', 'fw' ), 'icon' => 'dashicons-randomize' ),
			'list'         => array( 'label' => __( 'List', 'fw' ), 'icon' => 'dashicons-list-view' ),
			'tag'          => array( 'label' => __( 'Tag', 'fw' ), 'icon' => 'dashicons-tag' ),
			'deal'         => array( 'label' => __( 'Deal', 'fw' ), 'icon' => 'dashicons-money-alt' ),
			'won'          => array( 'label' => __( 'Won', 'fw' ), 'icon' => 'dashicons-awards' ),
			'lost'         => array( 'label' => __( 'Lost', 'fw' ), 'icon' => 'dashicons-dismiss' ),
			'task'         => array( 'label' => __( 'Task', 'fw' ), 'icon' => 'dashicons-yes-alt' ),
			'import'       => array( 'label' => __( 'Import', 'fw' ), 'icon' => 'dashicons-database-import' ),
			'file'         => array( 'label' => __( 'File', 'fw' ), 'icon' => 'dashicons-paperclip' ),
			'email_in'     => array( 'label' => __( 'Email received', 'fw' ), 'icon' => 'dashicons-email-alt2' ),
			'email_open'   => array( 'label' => __( 'Opened an email', 'fw' ), 'icon' => 'dashicons-visibility' ),
			'email_click'  => array( 'label' => __( 'Clicked a link', 'fw' ), 'icon' => 'dashicons-admin-links' ),
			'call'         => array( 'label' => __( 'Call', 'fw' ), 'icon' => 'dashicons-phone' ),
			'case'         => array( 'label' => __( 'Case', 'fw' ), 'icon' => 'dashicons-sos' ),
			'message'      => array( 'label' => __( 'Message', 'fw' ), 'icon' => 'dashicons-format-chat' ),
			'automation'   => array( 'label' => __( 'Automation', 'fw' ), 'icon' => 'dashicons-randomize' ),
			'visit'        => array( 'label' => __( 'Visited the site', 'fw' ), 'icon' => 'dashicons-admin-site-alt3' ),
			'score'        => array( 'label' => __( 'Score', 'fw' ), 'icon' => 'dashicons-star-filled' ),
			'priority'     => array( 'label' => __( 'Priority', 'fw' ), 'icon' => 'dashicons-warning' ),
		);

		/** Filters the CRM timeline entry types: type => { label, icon (a dashicons class) }. */
		return (array) apply_filters( 'unysonplus_crm_activity_types', $types );
	}

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'activity' );
	}

	/**
	 * Add a timeline entry.
	 *
	 * @param string $object_type contact|company|deal|case…
	 * @param int    $object_id
	 * @param string $type        A short slug: created, form, email, note, call, stage, owner, …
	 * @param string $title       One readable line.
	 * @param array  $args        body (string), meta (array), actor_id (int, default the current user)
	 *
	 * @return int The entry id, or 0.
	 */
	public static function log( $object_type, $object_id, $type, $title, array $args = array() ) {
		global $wpdb;

		if ( ! $object_id ) {
			return 0;
		}

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( self::table(), array(
			'object_type' => substr( sanitize_key( (string) $object_type ), 0, 30 ),
			'object_id'   => (int) $object_id,
			'type'        => substr( sanitize_key( (string) $type ), 0, 50 ),
			'title'       => substr( wp_strip_all_tags( (string) $title ), 0, 255 ),
			'body'        => isset( $args['body'] ) ? wp_kses_post( (string) $args['body'] ) : '',
			'meta'        => isset( $args['meta'] ) ? wp_json_encode( (array) $args['meta'] ) : '',
			'actor_id'    => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : (int) get_current_user_id(),
			'created_at'  => $now,
		) );

		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		if ( 'contact' === $object_type ) {
			$wpdb->update( FW_CRM_Installer::table( 'subscribers' ), array( 'last_activity_at' => $now ), array( 'id' => (int) $object_id ) );
		} elseif ( 'company' === $object_type ) {
			$wpdb->update( FW_CRM_Installer::table( 'companies' ), array( 'last_activity_at' => $now ), array( 'id' => (int) $object_id ) );
		} elseif ( 'deal' === $object_type ) {
			$wpdb->update( FW_CRM_Installer::table( 'deals' ), array( 'last_activity_at' => $now ), array( 'id' => (int) $object_id ) );
		} elseif ( 'case' === $object_type && class_exists( 'FW_CRM_Cases' ) ) {
			$wpdb->update( FW_CRM_Installer::table( 'cases' ), array( 'last_activity_at' => $now ), array( 'id' => (int) $object_id ) );
		}

		/** Fires after a timeline entry is written. */
		do_action( 'unysonplus_crm_activity_logged', $id, (string) $object_type, (int) $object_id, (string) $type );

		return $id;
	}

	/**
	 * A record's timeline, newest first.
	 *
	 * @param string $object_type
	 * @param int    $object_id
	 * @param int    $limit
	 * @param int    $offset
	 *
	 * @return array [ { id, type, title, body, meta (array), actor_id, created_at } ]
	 */
	public static function for_record( $object_type, $object_id, $limit = 50, $offset = 0 ) {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
			sanitize_key( (string) $object_type ),
			(int) $object_id,
			max( 1, (int) $limit ),
			max( 0, (int) $offset )
		) );

		foreach ( $rows as $r ) {
			$meta    = json_decode( (string) $r->meta, true );
			$r->meta = is_array( $meta ) ? $meta : array();
		}

		return $rows;
	}

	/**
	 * The newest entries across every record, for the CRM home feed.
	 *
	 * @param int $limit
	 *
	 * @return array Same shape as for_record(), plus object_type and object_id.
	 */
	public static function recent( $limit = 15, $owner = 0 ) {
		global $wpdb;

		$table = self::table();
		// A deal's events are mirrored onto its contact and company; the feed shows them once.
		$where = "NOT ( type IN ('deal', 'won', 'lost') AND object_type IN ('contact', 'company') )";
		if ( $owner ) {
			// Only the records this person owns.
			$contacts = FW_CRM_Installer::table( 'subscribers' );
			$cos      = FW_CRM_Installer::table( 'companies' );
			$deals    = FW_CRM_Installer::table( 'deals' );
			$where   .= $wpdb->prepare(
				" AND ( ( object_type = 'contact' AND object_id IN ( SELECT id FROM {$contacts} WHERE owner_id = %d ) ) OR ( object_type = 'company' AND object_id IN ( SELECT id FROM {$cos} WHERE owner_id = %d ) ) OR ( object_type = 'deal' AND object_id IN ( SELECT id FROM {$deals} WHERE owner_id = %d ) ) )", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $owner,
				(int) $owner,
				(int) $owner
			);
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			max( 1, (int) $limit )
		) );

		foreach ( $rows as $r ) {
			$meta    = json_decode( (string) $r->meta, true );
			$r->meta = is_array( $meta ) ? $meta : array();
		}

		return $rows;
	}

	/**
	 * @param string $object_type
	 * @param int    $object_id
	 *
	 * @return int Entries removed.
	 */
	public static function delete_for( $object_type, $object_id ) {
		global $wpdb;

		return (int) $wpdb->delete( self::table(), array( 'object_type' => sanitize_key( (string) $object_type ), 'object_id' => (int) $object_id ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Built-in listeners
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $context
	 * @return string
	 */
	private static function where_from( array $context ) {
		$source = (string) ( $context['source'] ?? '' );
		$labels = array(
			'shortcode' => __( 'the newsletter form', 'fw' ),
			'form'      => __( 'a contact form', 'fw' ),
			'import'    => __( 'an import', 'fw' ),
			'admin'     => __( 'the CRM screen', 'fw' ),
			'rest'      => __( 'the API', 'fw' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : ( '' !== $source ? $source : __( 'the site', 'fw' ) );
	}

	/** @internal */
	public static function _on_added( $subscriber, $context = array() ) {
		if ( ! is_object( $subscriber ) ) {
			return;
		}
		self::log( 'contact', $subscriber->id, 'created', sprintf( __( 'Added through %s', 'fw' ), self::where_from( (array) $context ) ), array(
			'meta' => array( 'source' => (string) ( $context['source'] ?? '' ), 'source_url' => (string) ( $context['source_url'] ?? '' ) ),
		) );
	}

	/** @internal */
	public static function _on_resubscribed( $subscriber, $context = array() ) {
		if ( is_object( $subscriber ) ) {
			self::log( 'contact', $subscriber->id, 'subscribed', sprintf( __( 'Subscribed again through %s', 'fw' ), self::where_from( (array) $context ) ) );
		}
	}

	/** @internal */
	public static function _on_opted_in( $subscriber, $context = array() ) {
		if ( is_object( $subscriber ) ) {
			self::log( 'contact', $subscriber->id, 'subscribed', sprintf( __( 'Subscribed through %s', 'fw' ), self::where_from( (array) $context ) ) );
		}
	}

	/** @internal */
	public static function _on_confirmed( $subscriber ) {
		if ( is_object( $subscriber ) ) {
			self::log( 'contact', $subscriber->id, 'confirmed', __( 'Confirmed their email address', 'fw' ), array( 'actor_id' => 0 ) );
		}
	}

	/** @internal */
	public static function _on_unsubscribed( $subscriber ) {
		if ( is_object( $subscriber ) ) {
			self::log( 'contact', $subscriber->id, 'unsubscribed', __( 'Unsubscribed', 'fw' ), array( 'actor_id' => 0 ) );
		}
	}

	/**
	 * Remember a stored form entry; _after_form() puts it on the timeline of the contact with
	 * that email once the form's actions have run.
	 *
	 * @internal
	 *
	 * @param int   $entry_id
	 * @param array $entry
	 */
	public static function _on_form_entry( $entry_id, $entry ) {
		self::$pending_entry = is_array( $entry ) ? array( (int) $entry_id, $entry ) : null;
	}

	/**
	 * @internal
	 */
	public static function _after_form() {
		if ( ! self::$pending_entry ) {
			return;
		}
		list( $entry_id, $entry ) = self::$pending_entry;
		self::$pending_entry      = null;

		$email   = (string) ( $entry['email'] ?? '' );
		$contact = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;

		if ( ! $contact || ! empty( self::$form_logged[ strtolower( $email ) ] ) ) {
			return;
		}
		self::log( 'contact', $contact->id, 'form', sprintf( __( 'Sent the form "%s"', 'fw' ), (string) ( $entry['form_title'] ?? '' ) ), array(
			'body'     => class_exists( 'FW_CRM_Form_Map' ) ? FW_CRM_Form_Map::answers_html( (array) ( $entry['fields'] ?? array() ), array(), (int) $entry_id ) : '',
			'meta'     => array( 'entry_id' => (int) $entry_id, 'post_id' => (int) ( $entry['post_id'] ?? 0 ) ),
			'actor_id' => 0,
		) );
	}
}
