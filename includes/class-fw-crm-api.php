<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM's public PHP API: one entry point for other extensions, `fw_crm()`.
 *
 *     if ( function_exists( 'fw_crm' ) && fw_crm() ) {
 *         $id = fw_crm()->upsert_contact( 'ana@example.com', array( 'first_name' => 'Ana' ) );
 *         fw_crm()->tag( $id, 'webinar-2026' );
 *         fw_crm()->log( $id, 'booking', 'Booked a consultation' );
 *     }
 *
 * The tables behind it are private and may change; this API keeps its shape within a major
 * version (`fw_crm()->version()`). Methods return plain arrays / ints / bools, or a WP_Error.
 * Contact ids are CRM contact ids, not WordPress user ids (see user_contact()).
 * Nothing here checks the current user's permissions: callers decide who may do what.
 */
class FW_CRM_API {

	const VERSION = '1.0';

	/** @var FW_CRM_API|null */
	private static $instance = null;

	/** @return FW_CRM_API */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return string The API version (major.minor); a major change may break callers. */
	public function version() {
		return self::VERSION;
	}

	/**
	 * Is a module switched on?
	 *
	 * @param string $module companies|pipeline|calendar|conversations|email|automations|scoring|cases|audit|webhooks|woocommerce
	 *
	 * @return bool
	 */
	public function has_module( $module ) {
		return FW_CRM_Modules::enabled( (string) $module );
	}

	/* ---------------------------------------------------------------------- *
	 * Contacts
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int|string $id_or_email
	 *
	 * @return array|null The contact (id, email, first_name, last_name, phone, status, lifecycle_stage, owner_id, score, …).
	 */
	public function contact( $id_or_email ) {
		$c = is_numeric( $id_or_email ) ? FW_CRM_Subscribers::find( (int) $id_or_email ) : FW_CRM_Subscribers::find_by_email( FW_CRM_Subscribers::normalize_email( (string) $id_or_email ) );
		return $c && empty( $c->deleted_at ) ? FW_CRM_Contacts::get( (int) $c->id ) : null;
	}

	/**
	 * @param array $data email (or phone), first_name, last_name, phone, job_title, lifecycle_stage, owner_id, source, fields (custom field values)
	 *
	 * @return int|WP_Error
	 */
	public function create_contact( array $data ) {
		return FW_CRM_Contacts::create( $data );
	}

	/**
	 * @param int   $id
	 * @param array $data As create_contact().
	 *
	 * @return true|WP_Error
	 */
	public function update_contact( $id, array $data ) {
		return FW_CRM_Contacts::update( (int) $id, $data );
	}

	/**
	 * Find a contact by email and update it, or create it.
	 *
	 * @param string $email
	 * @param array  $data
	 * @param bool   $only_empty Fill only fields that are empty now (keep what the team typed).
	 *
	 * @return int|WP_Error The contact id.
	 */
	public function upsert_contact( $email, array $data = array(), $only_empty = true ) {
		$c = FW_CRM_Subscribers::find_by_email( FW_CRM_Subscribers::normalize_email( (string) $email ) );
		if ( ! $c || ! empty( $c->deleted_at ) ) {
			return FW_CRM_Contacts::create( array_merge( $data, array( 'email' => $email ) ) );
		}
		if ( $only_empty ) {
			$data = array_filter( $data, function ( $v, $k ) use ( $c ) {
				return ! isset( $c->$k ) || '' === (string) $c->$k || '0' === (string) $c->$k;
			}, ARRAY_FILTER_USE_BOTH );
		}
		unset( $data['email'] );
		if ( $data ) {
			$res = FW_CRM_Contacts::update( (int) $c->id, $data );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return (int) $c->id;
	}

	/**
	 * Move a contact to the bin (it can be restored for a while).
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public function trash_contact( $id ) {
		return (bool) FW_CRM_Contacts::trash( (int) $id );
	}

	/**
	 * @param int $id
	 *
	 * @return array key => value of the contact's custom fields
	 */
	public function fields( $id ) {
		return FW_CRM_Fields::get_values( 'contact', (int) $id );
	}

	/**
	 * Define a custom field (contact by default) that your extension manages: shown read-only in the CRM's form.
	 *
	 * @param array  $def key, label, type, object, choices, group
	 * @param string $managed_by Your extension's id ('' = an ordinary field people can edit).
	 *
	 * @return array|WP_Error
	 */
	public function add_field( array $def, $managed_by = '' ) {
		if ( '' !== $managed_by ) {
			$def['managed_by'] = sanitize_key( $managed_by );
		}
		return FW_CRM_Fields::save_definition( $def );
	}

	/**
	 * @param int   $id
	 * @param array $values key => value
	 *
	 * @return true|WP_Error
	 */
	public function set_fields( $id, array $values ) {
		return FW_CRM_Fields::set_values( 'contact', (int) $id, $values );
	}

	/* ---------------------------------------------------------------------- *
	 * Tags, lists, segments
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int          $id
	 * @param string|array $tags Slugs or titles; created when missing.
	 * @param string       $source Who added them (your extension's id).
	 *
	 * @return int How many were newly added.
	 */
	public function tag( $id, $tags, $source = '' ) {
		return FW_CRM_Tags::add( (int) $id, $tags, (string) $source );
	}

	/**
	 * @param int          $id
	 * @param string|array $tags
	 * @param string       $source
	 *
	 * @return int How many were removed.
	 */
	public function untag( $id, $tags, $source = '' ) {
		return FW_CRM_Tags::remove( (int) $id, $tags, (string) $source );
	}

	/**
	 * @param int          $id
	 * @param string|array $tags
	 * @param string       $match any|all|none
	 *
	 * @return bool
	 */
	public function has_tag( $id, $tags, $match = 'any' ) {
		return FW_CRM_Subscribers::has_lists( (int) $id, (array) $tags, $match, 'tag' );
	}

	/** @return string[] The contact's tag slugs. */
	public function tags( $id ) {
		return FW_CRM_Tags::for_contact( (int) $id );
	}

	/**
	 * Add to a list (not a newsletter signup: no consent or double opt-in is implied).
	 *
	 * @param int    $id
	 * @param string $list Slug; created when missing.
	 * @param string $source
	 *
	 * @return bool
	 */
	public function add_to_list( $id, $list, $source = '' ) {
		$l = FW_CRM_Lists::get_or_create( sanitize_key( (string) $list ), 'list' );
		return $l ? (bool) FW_CRM_Subscribers::add_to_list( (int) $id, (int) $l->id, 'list', (string) $source ) : false;
	}

	/**
	 * @param int    $id
	 * @param string $list
	 * @param string $source
	 *
	 * @return bool
	 */
	public function remove_from_list( $id, $list, $source = '' ) {
		$l = FW_CRM_Lists::find_by_slug( sanitize_key( (string) $list ), 'list' );
		return $l ? (bool) FW_CRM_Subscribers::remove_from_list( (int) $id, (int) $l->id, 'list', (string) $source ) : false;
	}

	/**
	 * @param int          $id
	 * @param string|array $lists
	 * @param string       $match any|all|none
	 *
	 * @return bool Subscribed to them.
	 */
	public function in_list( $id, $lists, $match = 'any' ) {
		return FW_CRM_Subscribers::has_lists( (int) $id, (array) $lists, $match, 'list' );
	}

	/**
	 * @param int        $id
	 * @param int|string $segment An id or a slug.
	 *
	 * @return bool
	 */
	public function in_segment( $id, $segment ) {
		return FW_CRM_Users::in_segment( (int) $id, $segment );
	}

	/* ---------------------------------------------------------------------- *
	 * Users
	 * ---------------------------------------------------------------------- */

	/**
	 * The contact linked to a WordPress user.
	 *
	 * @param int  $user_id
	 * @param bool $create Find by email or create, and link, when there is none.
	 *
	 * @return int The contact id, or 0.
	 */
	public function user_contact( $user_id, $create = false ) {
		$id = FW_CRM_Contacts::id_for_user( (int) $user_id );
		if ( ! $id && $create ) {
			$id = FW_CRM_Users::ensure_for_user( (int) $user_id );
		}
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Access check for a user: tags, lists or a segment.
	 *
	 * @param int   $user_id
	 * @param array $rule { tags, lists, segment, match: any|all }
	 *
	 * @return bool
	 */
	public function user_can_access( $user_id, array $rule ) {
		return FW_CRM_Users::user_can_access( (int) $user_id, $rule );
	}

	/**
	 * Report a membership event (joined, renewed, expired…) from a membership plugin.
	 *
	 * @param int|WP_User $who
	 * @param string      $event
	 * @param array       $data
	 *
	 * @return true|WP_Error
	 */
	public function membership_event( $who, $event, array $data = array() ) {
		return FW_CRM_Users::membership_event( $who, (string) $event, $data );
	}

	/* ---------------------------------------------------------------------- *
	 * Companies, deals
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public function company( $id ) {
		$c = FW_CRM_Companies::find( (int) $id );
		return $c && empty( $c->deleted_at ) ? (array) $c : null;
	}

	/**
	 * @param array $data name, domain, website, phone, email, owner_id …
	 *
	 * @return int|WP_Error
	 */
	public function create_company( array $data ) {
		return FW_CRM_Companies::create( $data );
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public function update_company( $id, array $data ) {
		return FW_CRM_Companies::update( (int) $id, $data );
	}

	/**
	 * @param int  $contact_id
	 * @param int  $company_id
	 * @param bool $primary
	 *
	 * @return true|WP_Error
	 */
	public function link_company( $contact_id, $company_id, $primary = false ) {
		return FW_CRM_Contacts::add_company( (int) $contact_id, (int) $company_id, (bool) $primary );
	}

	/**
	 * @param int $id
	 *
	 * @return array|null Null also when the Sales pipeline module is off.
	 */
	public function deal( $id ) {
		return class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::get( (int) $id ) : null;
	}

	/**
	 * @param array $data title, value, currency, pipeline_id, stage_id, contact_id, company_id, owner_id, expected_close
	 *
	 * @return int|WP_Error
	 */
	public function create_deal( array $data ) {
		return class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::create( $data ) : new WP_Error( 'fw_crm_module_off', __( 'The Sales pipeline module is switched off.', 'fw' ) );
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return true|WP_Error
	 */
	public function update_deal( $id, array $data ) {
		return class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::update( (int) $id, $data ) : new WP_Error( 'fw_crm_module_off', __( 'The Sales pipeline module is switched off.', 'fw' ) );
	}

	/**
	 * @param int    $id
	 * @param int    $stage_id
	 * @param string $lost_reason
	 *
	 * @return true|WP_Error
	 */
	public function move_deal( $id, $stage_id, $lost_reason = '' ) {
		return class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::move( (int) $id, (int) $stage_id, (string) $lost_reason ) : new WP_Error( 'fw_crm_module_off', __( 'The Sales pipeline module is switched off.', 'fw' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Timeline, events, tasks, scores, purchases
	 * ---------------------------------------------------------------------- */

	/**
	 * Write to a record's timeline.
	 *
	 * @param int    $id
	 * @param string $type   A timeline type (register yours with the integration's activity_types).
	 * @param string $title  One line.
	 * @param string $body   Optional text (basic HTML).
	 * @param string $object contact|company|deal|case|<custom object type>
	 *
	 * @return int The entry id.
	 */
	public function log( $id, $type, $title, $body = '', $object = 'contact' ) {
		return FW_CRM_Activity::log( (string) $object, (int) $id, (string) $type, (string) $title, array( 'body' => (string) $body, 'actor_id' => get_current_user_id() ) );
	}

	/**
	 * Record something the contact did (scores, segments and automations can use it).
	 *
	 * @param int    $id
	 * @param string $event
	 * @param array  $args value, url, props
	 *
	 * @return int
	 */
	public function track( $id, $event, array $args = array() ) {
		return FW_CRM_Events_Store::record( (int) $id, (string) $event, $args );
	}

	/**
	 * @param array $data title, type (todo|call|meeting|email), object_type, object_id, owner_id, due_at, notes
	 *
	 * @return int|WP_Error
	 */
	public function create_task( array $data ) {
		return FW_CRM_Tasks::create( $data );
	}

	/**
	 * @param int    $id
	 * @param string $score
	 *
	 * @return int 0 when the Lead scoring module is off.
	 */
	public function score( $id, $score = 'default' ) {
		return class_exists( 'FW_CRM_Scoring' ) ? FW_CRM_Scoring::get( (int) $id, (string) $score ) : (int) ( FW_CRM_Subscribers::find( (int) $id )->score ?? 0 );
	}

	/**
	 * @param int    $id
	 * @param int    $points
	 * @param string $reason
	 * @param string $score
	 *
	 * @return int|WP_Error The new score.
	 */
	public function add_points( $id, $points, $reason = '', $score = 'default' ) {
		return class_exists( 'FW_CRM_Scoring' ) ? FW_CRM_Scoring::add( (int) $id, (int) $points, (string) $score, (string) $reason ) : new WP_Error( 'fw_crm_module_off', __( 'The Lead scoring module is switched off.', 'fw' ) );
	}

	/**
	 * Keep your store's or membership's summary for a contact.
	 *
	 * @param int    $id
	 * @param string $provider
	 * @param array  $data orders, total, currency, products, plan, plan_status, first_order_at, last_order_at
	 *
	 * @return true|WP_Error
	 */
	public function set_summary( $id, $provider, array $data ) {
		return FW_CRM_Commerce::set( (int) $id, (string) $provider, $data );
	}

	/**
	 * @param int         $id
	 * @param string|null $provider null = every provider
	 *
	 * @return array
	 */
	public function summary( $id, $provider = null ) {
		return null === $provider ? FW_CRM_Commerce::for_contact( (int) $id ) : FW_CRM_Commerce::get( (int) $id, (string) $provider );
	}

	/* ---------------------------------------------------------------------- *
	 * Extending the CRM
	 * ---------------------------------------------------------------------- */

	/** @see FW_CRM_Integrations::register() */
	public function register_integration( $id, array $args ) {
		return FW_CRM_Integrations::register( (string) $id, $args );
	}

	/** @see FW_CRM_Objects::register() */
	public function register_object( $type, array $args ) {
		return FW_CRM_Objects::register( (string) $type, $args );
	}

	/**
	 * The record API for a custom object type.
	 *
	 * @return string The class with create(), update(), get(), query(), delete(), link().
	 */
	public function objects() {
		return 'FW_CRM_Objects';
	}

	/** @see FW_CRM_Widgets::add() (admin screens only) */
	public function add_widget( $id, array $args ) {
		return class_exists( 'FW_CRM_Widgets' ) ? FW_CRM_Widgets::add( (string) $id, $args ) : false;
	}

	/**
	 * Fire one of your own catalogue events (declare it in your integration's `events`).
	 *
	 * @param string $event
	 * @param int    $contact_id
	 * @param array  $data
	 * @param string $object_type
	 * @param int    $object_id
	 */
	public function emit( $event, $contact_id, array $data = array(), $object_type = 'contact', $object_id = 0 ) {
		FW_CRM_Events::emit( (string) $event, (int) $contact_id, (string) $object_type, (int) $object_id, $data );
	}
}

if ( ! function_exists( 'fw_crm' ) ) {
	/**
	 * The CRM's public API, or null while the CRM extension is not active.
	 *
	 * @return FW_CRM_API|null
	 */
	function fw_crm() {
		return class_exists( 'FW_CRM_Installer' ) ? FW_CRM_API::instance() : null;
	}
}
