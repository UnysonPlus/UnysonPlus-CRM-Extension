<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Abstract provider — the seam an ESP integration plugs into.
 *
 * The contract was designed against the current Mailchimp, MailerLite and Brevo
 * APIs, and the finding that shaped it is that all three are EMAIL-KEYED UPSERTS
 * taking (a) an address, (b) a flat field map, (c) an array of list/group/audience
 * IDs and (d) a status. So one `subscribe()` is enough — there is no create/update
 * split, and idempotency is the adapter's job, not the caller's:
 *
 *   Mailchimp   PUT /lists/{list_id}/members/{md5(lowercased email)}
 *   MailerLite  POST /api/subscribers          (201 created / 200 updated)
 *   Brevo       POST /v3/contacts              with updateEnabled: true
 *
 * Consequences baked into the signatures below:
 *
 *  - Methods take the WHOLE subscriber, not granular operations, because tags are
 *    a second API call on Mailchimp but the same call on MailerLite/Brevo. That
 *    asymmetry must stay inside the adapter.
 *  - Every method returns `true` or a WP_Error, never a bare bool, so a caller can
 *    tell "rejected" from "429, retry later".
 *  - Remote list IDs differ per provider, so `map_list_id()` exists rather than
 *    assuming our ID means anything remotely.
 *  - `get_settings_options()` lets a provider contribute its own option fields to
 *    the settings screen, so adding one needs no core change.
 *
 * Register with:
 *
 *   add_filter( 'unysonplus_crm_providers', function ( $providers ) {
 *       $providers['acme'] = new My_Acme_Provider();
 *       return $providers;
 *   } );
 */
abstract class FW_CRM_Provider {

	/**
	 * Machine name, e.g. 'local', 'acme'.
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Human label for the settings screen.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Create or update the contact remotely (an upsert — see the class docblock).
	 *
	 * @param object $subscriber Subscriber row.
	 * @param array  $args       lists, tags, double_optin, context…
	 *
	 * @return true|WP_Error
	 */
	abstract public function subscribe( $subscriber, array $args = array() );

	/**
	 * Mark the contact as opted out remotely. Must not delete them.
	 *
	 * @param object $subscriber
	 * @param array  $args
	 *
	 * @return true|WP_Error
	 */
	abstract public function unsubscribe( $subscriber, array $args = array() );

	/**
	 * Push the current local state (fields, lists, tags) without changing status.
	 * Default implementation delegates to subscribe(), which is correct for every
	 * upsert-style API; override only if a provider separates the two.
	 *
	 * @param object $subscriber
	 * @param array  $args
	 *
	 * @return true|WP_Error
	 */
	public function sync( $subscriber, array $args = array() ) {
		return $this->subscribe( $subscriber, $args );
	}

	/**
	 * Is the provider usable (credentials present, etc.)? The service skips
	 * providers that say no, so a half-configured integration cannot break a
	 * signup.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Should this provider run at all? Defaults to is_configured(); the settings
	 * screen's per-provider enable switch is the usual override.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->is_configured();
	}

	/**
	 * Option fields contributed to the settings screen, keyed by option name.
	 * Values land under the extension's settings store, namespaced by slug.
	 *
	 * @return array
	 */
	public function get_settings_options() {
		return array();
	}

	/**
	 * Translate one of OUR list/tag IDs into the provider's own audience/group/
	 * list ID. Stored mapping, never guessed. Returns null when unmapped.
	 *
	 * @param int    $object_id
	 * @param string $object_type
	 *
	 * @return string|null
	 */
	public function map_list_id( $object_id, $object_type = 'list' ) {
		$map = (array) $this->get_setting( 'list_map', array() );
		$key = $object_type . ':' . (int) $object_id;

		return isset( $map[ $key ] ) && '' !== $map[ $key ] ? (string) $map[ $key ] : null;
	}

	/**
	 * The flat field map every one of the three ESP APIs wants (Mailchimp's
	 * merge_fields, MailerLite's fields, Brevo's attributes). Filterable so a
	 * site can remap FNAME/FIRSTNAME without editing an adapter.
	 *
	 * @param object $subscriber
	 *
	 * @return array
	 */
	public function get_field_map( $subscriber ) {
		$fields = array(
			'first_name' => isset( $subscriber->first_name ) ? $subscriber->first_name : '',
			'last_name'  => isset( $subscriber->last_name ) ? $subscriber->last_name : '',
		);

		/**
		 * Filters the outbound subscriber field map a provider sends to its ESP, given the subscriber and provider slug.
		 *
		 * Filter the outbound field map.
		 *
		 * @param array  $fields
		 * @param object $subscriber
		 * @param string $provider_slug
		 */
		return apply_filters( 'unysonplus_crm_field_map', $fields, $subscriber, $this->get_slug() );
	}

	/**
	 * Read one of this provider's settings out of the extension settings store.
	 *
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	protected function get_setting( $key, $default = null ) {
		$settings = (array) fw_get_db_ext_settings_option( 'crm' );
		$mine     = isset( $settings[ 'provider_' . $this->get_slug() ] )
			? (array) $settings[ 'provider_' . $this->get_slug() ]
			: array();

		return isset( $mine[ $key ] ) ? $mine[ $key ] : $default;
	}
}
