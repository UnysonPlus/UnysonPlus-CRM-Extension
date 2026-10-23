<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The integrations registry: an extension registers itself once and its triggers and actions
 * appear in Automations, its fields on the contact page, its activity on the timeline, its
 * events in the catalogue, its cards on Home and its record types as custom objects. The CRM
 * never needs to know about it in advance, and the Modules screen lists what is connected.
 *
 *     fw_crm()->register_integration( 'bookings', array(
 *         'label'          => 'Bookings',
 *         'description'    => 'Appointments become contact activity.',
 *         'icon'           => 'dashicons-calendar',
 *         'active'         => function () { return class_exists( 'My_Bookings' ); },
 *         'triggers'       => array( 'bookings.booked' => array( 'label' => 'Booking made', 'fields' => array() ) ),
 *         'actions'        => array( 'bookings.cancel' => array( 'label' => 'Cancel the booking', 'settings' => array(), 'callback' => '…' ) ),
 *         'fields'         => array( array( 'key' => 'last_booking', 'label' => 'Last booking', 'type' => 'date' ) ),
 *         'activity_types' => array( 'booking' => array( 'label' => 'Booking', 'icon' => 'dashicons-calendar' ) ),
 *         'events'         => array( 'bookings.booked' => array( 'label' => 'Booking made', 'data' => array( 'service' => 'string' ) ) ),
 *         'widgets'        => array( 'bookings_today' => array( 'title' => 'Bookings today', 'callback' => '…' ) ),
 *         'objects'        => array( 'booking' => array( 'label' => 'Booking', 'plural' => 'Bookings' ) ),
 *     ) );
 *
 * Fields are created as **managed** contact fields (read-only in the CRM's own edit form) the
 * first time the integration is active. Trigger and action groups default to the label.
 */
class FW_CRM_Integrations {

	/** @var array id => args */
	private static $all = array();

	public static function init() {
		add_filter( 'unysonplus_crm_automation_triggers', function ( $t ) {
			return self::merge( $t, 'triggers' );
		}, 8 );
		add_filter( 'unysonplus_crm_automation_actions', function ( $a ) {
			return self::merge( $a, 'actions' );
		}, 8 );
		add_filter( 'unysonplus_crm_activity_types', function ( $t ) {
			foreach ( self::active() as $i ) {
				$t += (array) $i['activity_types'];
			}
			return $t;
		} );
		add_filter( 'unysonplus_crm_events_catalogue', function ( $c ) {
			foreach ( self::active() as $i ) {
				foreach ( (array) $i['events'] as $slug => $e ) {
					$c[ $slug ] = array_merge( array( 'group' => $i['label'], 'data' => array() ), (array) $e );
				}
			}
			return $c;
		} );
		add_filter( 'unysonplus_crm_dashboard_cards', function ( $cards ) {
			foreach ( self::active() as $i ) {
				$cards += (array) $i['widgets'];
			}
			return $cards;
		} );
		add_action( 'unysonplus_crm_register_objects', function () {
			foreach ( self::active() as $i ) {
				foreach ( (array) $i['objects'] as $type => $args ) {
					FW_CRM_Objects::register( $type, (array) $args );
				}
			}
		} );
		add_action( 'admin_init', array( __CLASS__, '_ensure_fields' ) );
		add_action( 'unysonplus_crm_modules_after', array( __CLASS__, 'render' ) );
	}

	/**
	 * @param string $id
	 * @param array  $args label, description, icon, active, triggers, actions, fields, activity_types, events, widgets, objects
	 *
	 * @return true|WP_Error
	 */
	public static function register( $id, array $args ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id ) {
			return new WP_Error( 'fw_crm_integration', __( 'An integration needs an id.', 'fw' ) );
		}
		self::$all[ $id ] = array_merge( array(
			'id'             => $id,
			'label'          => $id,
			'description'    => '',
			'icon'           => 'dashicons-admin-plugins',
			'active'         => true,
			'triggers'       => array(),
			'actions'        => array(),
			'fields'         => array(),
			'activity_types' => array(),
			'events'         => array(),
			'widgets'        => array(),
			'objects'        => array(),
		), $args, array( 'id' => $id ) );
		return true;
	}

	/** @return array id => integration */
	public static function all() {
		/** Filters the registered integrations (id => args, as register()). */
		return (array) apply_filters( 'unysonplus_crm_integrations', self::$all );
	}

	/** @return array id => integration, only those that are active */
	public static function active() {
		return array_filter( self::all(), function ( $i ) {
			$a = $i['active'];
			return is_bool( $a ) ? $a : ( is_callable( $a ) ? (bool) call_user_func( $a ) : (bool) $a );
		} );
	}

	/**
	 * @param array  $registry
	 * @param string $key triggers|actions
	 *
	 * @return array
	 */
	private static function merge( array $registry, $key ) {
		foreach ( self::active() as $i ) {
			foreach ( (array) $i[ $key ] as $slug => $def ) {
				if ( ! isset( $registry[ $slug ] ) ) {
					$registry[ $slug ] = array_merge( array( 'group' => $i['label'] ), (array) $def );
				}
			}
		}
		return $registry;
	}

	/** @internal Create each active integration's managed contact fields once. */
	public static function _ensure_fields() {
		foreach ( self::active() as $i ) {
			foreach ( (array) $i['fields'] as $f ) {
				$key = sanitize_key( (string) ( $f['key'] ?? '' ) );
				if ( '' === $key || FW_CRM_Fields::definition( (string) ( $f['object'] ?? 'contact' ), $key ) ) {
					continue;
				}
				FW_CRM_Fields::save_definition( array_merge( array( 'object' => 'contact', 'type' => 'text', 'label' => $key ), (array) $f, array( 'key' => $key, 'managed_by' => $i['id'] ) ) );
			}
		}
	}

	/** On the Modules screen: what is connected. */
	public static function render() {
		$all = self::all();
		if ( ! $all ) {
			return;
		}
		$active = self::active();
		?>
		<section class="fw-crm-panel fw-crm-integrations" aria-labelledby="fw-crm-h-integrations">
			<header class="fw-crm-panel__head"><h2 id="fw-crm-h-integrations"><?php esc_html_e( 'Connected extensions', 'fw' ); ?></h2></header>
			<p class="fw-crm-muted"><?php esc_html_e( 'Extensions that plug into the CRM: their triggers and actions are in Automations, their fields on contact pages and their activity on the timeline.', 'fw' ); ?></p>
			<ul class="fw-crm-integrations__list">
				<?php foreach ( $all as $id => $i ) : ?>
					<li>
						<span class="dashicons <?php echo esc_attr( $i['icon'] ); ?>" aria-hidden="true"></span>
						<strong><?php echo esc_html( $i['label'] ); ?></strong>
						<span class="fw-crm-status fw-crm-status--<?php echo isset( $active[ $id ] ) ? 'sent' : 'draft'; ?>"><?php echo isset( $active[ $id ] ) ? esc_html__( 'Connected', 'fw' ) : esc_html__( 'Not active', 'fw' ); ?></span>
						<?php if ( '' !== $i['description'] ) : ?><br><span class="fw-crm-muted"><?php echo esc_html( $i['description'] ); ?></span><?php endif; ?>
						<br><small class="fw-crm-muted">
							<?php
							echo esc_html( sprintf(
								/* translators: 1-4: counts */
								__( '%1$d triggers, %2$d actions, %3$d fields, %4$d events', 'fw' ),
								count( (array) $i['triggers'] ),
								count( (array) $i['actions'] ),
								count( (array) $i['fields'] ),
								count( (array) $i['events'] )
							) );
							?>
						</small>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<style>
			.fw-crm-integrations { margin-top: 1.5em; }
			.fw-crm-integrations__list { list-style: none; margin: 0; padding: 0; }
			.fw-crm-integrations__list li { border-top: 1px solid var(--crm-border, #f0f0f1); padding: .6em 0; }
			.fw-crm-integrations__list .fw-crm-status { margin-left: .5em; }
		</style>
		<?php
	}
}
