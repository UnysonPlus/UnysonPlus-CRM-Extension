<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Pipelines and their stages.
 *
 * A pipeline (sales, onboarding, hiring…) is an ordered list of stages. Each stage
 * is `open`, `won` or `lost`, has a win probability (used for the weighted
 * forecast) and optionally a number of days after which a deal sitting in it counts
 * as stalled. Every pipeline has exactly one won and one lost stage, at the end.
 */
class FW_CRM_Pipelines {

	/** @return string */
	private static function table( $which = 'pipelines' ) {
		return FW_CRM_Installer::table( $which );
	}

	/**
	 * @return array Pipeline rows by position.
	 */
	public static function all() {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY position ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			self::seed();
			$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY position ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $rows;
	}

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return object The default pipeline (created on first use). */
	public static function default_pipeline() {
		$all = self::all();
		foreach ( $all as $p ) {
			if ( (int) $p->is_default ) {
				return $p;
			}
		}
		return $all[0];
	}

	/**
	 * A pipeline's stages in order.
	 *
	 * @param int $pipeline_id
	 *
	 * @return array
	 */
	public static function stages( $pipeline_id ) {
		global $wpdb;

		static $cache = array();
		$pipeline_id  = (int) $pipeline_id;
		if ( ! isset( $cache[ $pipeline_id ] ) || self::$dirty ) {
			if ( self::$dirty ) {
				$cache       = array();
				self::$dirty = false;
			}
			$table                 = self::table( 'pipeline_stages' );
			$cache[ $pipeline_id ] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE pipeline_id = %d ORDER BY position ASC, id ASC", $pipeline_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $cache[ $pipeline_id ];
	}

	/** @var bool */
	private static $dirty = false;

	/**
	 * @param int $stage_id
	 *
	 * @return object|null
	 */
	public static function stage( $stage_id ) {
		global $wpdb;

		$table = self::table( 'pipeline_stages' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $stage_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * The first stage of a kind in a pipeline (the won stage, the first open stage…).
	 *
	 * @param int    $pipeline_id
	 * @param string $kind open|won|lost
	 *
	 * @return object|null
	 */
	public static function stage_of_kind( $pipeline_id, $kind ) {
		foreach ( self::stages( $pipeline_id ) as $s ) {
			if ( $kind === $s->kind ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * Create or update a pipeline and its stages.
	 *
	 * @param array $data name, currency, is_default, stages: [ { id?, name, probability, rot_days } ] (open stages, in
	 *                    order; the won and lost stages are kept at the end automatically), won_name, lost_name.
	 * @param int   $id
	 *
	 * @return int|WP_Error The pipeline id.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Give the pipeline a name.', 'fw' ) );
		}
		$open = array_values( array_filter( (array) ( $data['stages'] ?? array() ), function ( $s ) {
			return '' !== trim( (string) ( $s['name'] ?? '' ) );
		} ) );
		if ( ! $open ) {
			return new WP_Error( 'fw_crm_no_stages', __( 'A pipeline needs at least one stage.', 'fw' ) );
		}
		$row = array(
			'name'     => $name,
			'currency' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['currency'] ?? '' ) ), 0, 3 ) ),
		);
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		} else {
			$row['position']   = count( self::all() );
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		}
		if ( ! empty( $data['is_default'] ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET is_default = IF(id = %d, 1, 0)', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Stages: update the ones that exist, add new ones, delete the ones left out
		// (their deals move to the first open stage).
		$table    = self::table( 'pipeline_stages' );
		$existing = wp_list_pluck( self::stages( $id ), 'id' );
		$keep     = array();
		$pos      = 0;
		foreach ( $open as $s ) {
			$stage = array(
				'pipeline_id' => (int) $id,
				'name'        => sanitize_text_field( (string) $s['name'] ),
				'kind'        => 'open',
				'probability' => max( 0, min( 100, (int) ( $s['probability'] ?? 0 ) ) ),
				'rot_days'    => max( 0, min( 3650, (int) ( $s['rot_days'] ?? 0 ) ) ),
				'position'    => $pos++,
			);
			$sid = (int) ( $s['id'] ?? 0 );
			if ( $sid && in_array( (string) $sid, array_map( 'strval', $existing ), true ) ) {
				$wpdb->update( $table, $stage, array( 'id' => $sid ) );
			} else {
				$wpdb->insert( $table, $stage );
				$sid = (int) $wpdb->insert_id;
			}
			$keep[] = $sid;
		}
		foreach ( array( 'won' => 100, 'lost' => 0 ) as $kind => $prob ) {
			$current = null;
			foreach ( self::stages( $id ) as $s ) {
				if ( $kind === $s->kind ) {
					$current = $s;
				}
			}
			$label = sanitize_text_field( (string) ( $data[ $kind . '_name' ] ?? '' ) );
			$label = '' !== $label ? $label : ( $current ? $current->name : ( 'won' === $kind ? __( 'Won', 'fw' ) : __( 'Lost', 'fw' ) ) );
			$stage = array( 'pipeline_id' => (int) $id, 'name' => $label, 'kind' => $kind, 'probability' => $prob, 'rot_days' => 0, 'position' => $pos++ );
			if ( $current ) {
				$wpdb->update( $table, $stage, array( 'id' => (int) $current->id ) );
				$keep[] = (int) $current->id;
			} else {
				$wpdb->insert( $table, $stage );
				$keep[] = (int) $wpdb->insert_id;
			}
		}
		$removed = array_diff( array_map( 'intval', $existing ), $keep );
		if ( $removed ) {
			$first = $keep[0];
			$deals = self::table( 'deals' );
			$in    = implode( ',', array_map( 'intval', $removed ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$deals} SET stage_id = %d WHERE stage_id IN ({$in}) AND status = 'open'", $first ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		self::$dirty = true;

		/** Fires after a pipeline or its stages change. */
		do_action( 'unysonplus_crm_pipeline_saved', (int) $id );

		return (int) $id;
	}

	/**
	 * Delete a pipeline that has no deals.
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		if ( count( self::all() ) < 2 ) {
			return new WP_Error( 'fw_crm_last_pipeline', __( 'The CRM needs at least one pipeline.', 'fw' ) );
		}
		$deals = self::table( 'deals' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$deals} WHERE pipeline_id = %d", (int) $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return new WP_Error( 'fw_crm_pipeline_used', __( 'This pipeline still has deals. Move or delete them first.', 'fw' ) );
		}
		$was_default = ( $p = self::find( $id ) ) && (int) $p->is_default;
		$wpdb->delete( self::table( 'pipeline_stages' ), array( 'pipeline_id' => (int) $id ) );
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		if ( $was_default ) {
			// Another pipeline becomes the one the board opens first.
			$next = $wpdb->get_var( 'SELECT id FROM ' . self::table() . ' ORDER BY position ASC, id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $next ) {
				$wpdb->update( self::table(), array( 'is_default' => 1 ), array( 'id' => (int) $next ) );
			}
		}
		self::$dirty = true;
		return true;
	}

	/** The first pipeline: a simple sales process. */
	private static function seed() {
		global $wpdb;

		$wpdb->insert( self::table(), array( 'name' => __( 'Sales', 'fw' ), 'position' => 0, 'is_default' => 1, 'created_at' => current_time( 'mysql' ) ) );
		$id    = (int) $wpdb->insert_id;
		$table = self::table( 'pipeline_stages' );
		$pos   = 0;
		foreach ( array(
			array( __( 'New lead', 'fw' ), 'open', 10, 14 ),
			array( __( 'Contacted', 'fw' ), 'open', 25, 14 ),
			array( __( 'Proposal sent', 'fw' ), 'open', 50, 21 ),
			array( __( 'Negotiating', 'fw' ), 'open', 75, 21 ),
			array( __( 'Won', 'fw' ), 'won', 100, 0 ),
			array( __( 'Lost', 'fw' ), 'lost', 0, 0 ),
		) as $s ) {
			$wpdb->insert( $table, array( 'pipeline_id' => $id, 'name' => $s[0], 'kind' => $s[1], 'probability' => $s[2], 'rot_days' => $s[3], 'position' => $pos++ ) );
		}
		self::$dirty = true;
	}

	/**
	 * The currency for new deals: the pipeline's, the CRM setting, WooCommerce's, or USD.
	 *
	 * @param object|null $pipeline
	 *
	 * @return string
	 */
	public static function currency( $pipeline = null ) {
		if ( $pipeline && '' !== (string) $pipeline->currency ) {
			return (string) $pipeline->currency;
		}
		$setting = class_exists( 'FW_CRM_Service' ) ? (string) FW_CRM_Service::setting( 'currency', '' ) : '';
		if ( '' !== $setting ) {
			return strtoupper( $setting );
		}
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_woocommerce_currency();
		}
		/** Filters the CRM's default deal currency. */
		return (string) apply_filters( 'unysonplus_crm_default_currency', 'USD' );
	}

	/**
	 * Reasons a deal can be lost (option fw_crm_lost_reasons).
	 *
	 * @return string[]
	 */
	public static function lost_reasons() {
		$saved = get_option( 'fw_crm_lost_reasons' );
		$list  = is_array( $saved ) && $saved ? $saved : array(
			__( 'Price', 'fw' ),
			__( 'Timing', 'fw' ),
			__( 'Chose a competitor', 'fw' ),
			__( 'No budget', 'fw' ),
			__( 'No response', 'fw' ),
			__( 'Other', 'fw' ),
		);
		/** Filters the lost reasons offered when a deal is marked lost. */
		return (array) apply_filters( 'unysonplus_crm_lost_reasons', $list );
	}
}
