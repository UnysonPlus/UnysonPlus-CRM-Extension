<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Dashboard widgets: other extensions add their own cards to the CRM's Home.
 *
 *     fw_crm()->add_widget( 'bookings_today', array(
 *         'title'    => 'Bookings today',
 *         'callback' => function () { echo '…'; },  // prints the card's body
 *         'column'   => 'side',                     // or 'main'
 *         'priority' => 30,                         // lower comes first
 *         'cap'      => 'manage_options',           // optional: only people with this capability see it
 *     ) );
 *
 * Cards land in the `unysonplus_crm_dashboard_cards` filter that Home reads, so people can
 * show, hide and reorder them with Home's own "Customise" panel like the built-in ones.
 * Built in: the warmest contacts (Lead scoring module) and recent shop orders (WooCommerce module).
 */
class FW_CRM_Widgets {

	/** @var array id => args */
	private static $widgets = array();

	public static function init() {
		add_filter( 'unysonplus_crm_dashboard_cards', array( __CLASS__, '_cards' ), 20 );
		if ( class_exists( 'FW_CRM_Scoring' ) ) {
			self::add( 'warmest', array(
				'title'    => __( 'Warmest contacts', 'fw' ),
				'priority' => 25,
				'callback' => array( __CLASS__, 'render_warmest' ),
			) );
		}
	}

	/**
	 * @param string $id
	 * @param array  $args title, callback, column, priority, cap
	 *
	 * @return true|WP_Error
	 */
	public static function add( $id, array $args ) {
		$id = sanitize_key( (string) $id );
		if ( '' === $id || empty( $args['callback'] ) || ! is_callable( $args['callback'] ) ) {
			return new WP_Error( 'fw_crm_widget', __( 'A widget needs an id and a callback.', 'fw' ) );
		}
		self::$widgets[ $id ] = $args;
		return true;
	}

	/** @param string $id */
	public static function remove( $id ) {
		unset( self::$widgets[ sanitize_key( (string) $id ) ] );
	}

	/** @internal */
	public static function _cards( $cards ) {
		foreach ( self::$widgets as $id => $w ) {
			if ( ! empty( $w['cap'] ) && ! current_user_can( (string) $w['cap'] ) ) {
				continue;
			}
			$cards[ $id ] = array(
				'title'    => (string) ( $w['title'] ?? $id ),
				'callback' => $w['callback'],
				'column'   => 'main' === ( $w['column'] ?? 'side' ) ? 'main' : 'side',
				'priority' => (int) ( $w['priority'] ?? 50 ),
			);
		}
		return $cards;
	}

	/** The warmest contacts (yours, unless you see everyone's). */
	public static function render_warmest() {
		$base = FW_CRM_Admin_Page::get_page_url();
		$rows = FW_CRM_Scoring::top( 'default', 6, FW_CRM_Capabilities::sees_all() ? 0 : get_current_user_id() );
		if ( ! $rows ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No scores yet. Add scoring rules under Lead scoring.', 'fw' ) . '</p>';
			return;
		}
		echo '<ol class="fw-crm-score-top">';
		foreach ( $rows as $r ) {
			$c = FW_CRM_Subscribers::find( (int) $r->contact_id );
			if ( $c ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <strong>%3$s</strong></li>',
					esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base ) ),
					esc_html( trim( $c->first_name . ' ' . $c->last_name ) ?: (string) $c->email ),
					esc_html( number_format_i18n( (int) $r->points ) )
				);
			}
		}
		echo '</ol><style>.fw-crm-score-top{margin:0;padding-left:1.4em}.fw-crm-score-top li{display:flex;justify-content:space-between;padding:.25em 0}</style>';
	}
}
