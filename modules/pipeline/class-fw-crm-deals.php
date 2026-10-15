<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Deals: an opportunity moving through a pipeline.
 *
 * A deal has a title, a value and currency, an owner, a primary contact (plus any
 * others, linked as `involved`), a company, a stage, an expected close date, an
 * optional probability that overrides its stage's, and custom fields. Moving it to
 * the pipeline's won or lost stage closes it. Every change lands on the deal's own
 * timeline and on its contact's and company's.
 */
class FW_CRM_Deals {

	/** @return string */
	public static function table() {
		return FW_CRM_Installer::table( 'deals' );
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

	/**
	 * A deal as the API returns it.
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		$d = self::find( $id );
		if ( ! $d ) {
			return null;
		}
		$stage    = FW_CRM_Pipelines::stage( (int) $d->stage_id );
		$prob     = self::probability( $d, $stage );
		$contacts = array_values( array_unique( array_filter( array_merge( array( (int) $d->contact_id ), FW_CRM_Relations::ids( 'deal', (int) $d->id, 'contact' ) ) ) ) );

		return array(
			'id'               => (int) $d->id,
			'title'            => (string) $d->title,
			'pipeline_id'      => (int) $d->pipeline_id,
			'stage_id'         => (int) $d->stage_id,
			'stage'            => $stage ? (string) $stage->name : '',
			'status'           => (string) $d->status,
			'value'            => (float) $d->value,
			'currency'         => (string) $d->currency,
			'probability'      => $prob,
			'weighted_value'   => round( (float) $d->value * $prob / 100, 2 ),
			'probability_set'  => null !== $d->probability,
			'owner_id'         => (int) $d->owner_id,
			'contact_id'       => (int) $d->contact_id,
			'contact_ids'      => array_map( 'intval', $contacts ),
			'company_id'       => (int) $d->company_id,
			'expected_close'   => $d->expected_close,
			'lost_reason'      => (string) $d->lost_reason,
			'stalled'          => self::is_stalled( $d, $stage ),
			'stage_changed_at' => $d->stage_changed_at,
			'closed_at'        => $d->closed_at,
			'last_activity_at' => $d->last_activity_at,
			'created_at'       => $d->created_at,
			'deleted_at'       => $d->deleted_at,
			'fields'           => FW_CRM_Fields::get_values( 'deal', (int) $d->id ),
		);
	}

	/**
	 * The deal's own probability, or its stage's.
	 *
	 * @param object      $deal
	 * @param object|null $stage
	 *
	 * @return int
	 */
	public static function probability( $deal, $stage = null ) {
		if ( 'won' === $deal->status ) {
			return 100;
		}
		if ( 'lost' === $deal->status ) {
			return 0;
		}
		if ( null !== $deal->probability ) {
			return (int) $deal->probability;
		}
		$stage = $stage ? $stage : FW_CRM_Pipelines::stage( (int) $deal->stage_id );
		return $stage ? (int) $stage->probability : 0;
	}

	/**
	 * Has an open deal sat in its stage longer than the stage allows?
	 *
	 * @param object      $deal
	 * @param object|null $stage
	 *
	 * @return bool
	 */
	public static function is_stalled( $deal, $stage = null ) {
		$stage = $stage ? $stage : FW_CRM_Pipelines::stage( (int) $deal->stage_id );
		if ( 'open' !== $deal->status || ! $stage || ! (int) $stage->rot_days ) {
			return false;
		}
		$since = strtotime( (string) ( $deal->stage_changed_at ?: $deal->created_at ) );
		return $since && ( current_time( 'timestamp' ) - $since ) > (int) $stage->rot_days * DAY_IN_SECONDS; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	}

	/**
	 * @param array $args pipeline, stage, status (open|won|lost|''), owner, contact, company, search,
	 *                    trashed (exclude|only|include), closing_before (Y-m-d), orderby, order, per_page, paged
	 *
	 * @return array Deal rows.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args    = array_merge( array( 'orderby' => 'position', 'order' => 'ASC', 'per_page' => 0, 'paged' => 1 ), $args );
		$table   = self::table();
		$orderby = in_array( $args['orderby'], array( 'position', 'value', 'expected_close', 'created_at', 'updated_at', 'title', 'closed_at', 'last_activity_at' ), true ) ? $args['orderby'] : 'position';
		$order   = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$sql     = "SELECT * FROM {$table} WHERE " . self::where( $args ) . " ORDER BY {$orderby} {$order}, id DESC";
		if ( (int) $args['per_page'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], ( max( 1, (int) $args['paged'] ) - 1 ) * (int) $args['per_page'] );
		}
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . self::where( $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args
	 *
	 * @return string
	 */
	private static function where( array $args ) {
		global $wpdb;

		$w       = array( '1=1' );
		$trashed = (string) ( $args['trashed'] ?? 'exclude' );
		if ( 'only' === $trashed ) {
			$w[] = 'deleted_at IS NOT NULL';
		} elseif ( 'include' !== $trashed ) {
			$w[] = 'deleted_at IS NULL';
		}
		foreach ( array( 'pipeline' => 'pipeline_id', 'stage' => 'stage_id', 'owner' => 'owner_id', 'company' => 'company_id' ) as $arg => $col ) {
			if ( isset( $args[ $arg ] ) && '' !== $args[ $arg ] && null !== $args[ $arg ] ) {
				$w[] = $wpdb->prepare( "{$col} = %d", (int) $args[ $arg ] ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
		if ( ! empty( $args['contact'] ) ) {
			$ids = FW_CRM_Relations::ids( 'contact', (int) $args['contact'], 'deal' );
			$or  = $wpdb->prepare( 'contact_id = %d', (int) $args['contact'] );
			if ( $ids ) {
				$or .= ' OR id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
			}
			$w[] = "( {$or} )";
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'open', 'won', 'lost' ), true ) ) {
			$w[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$w[] = $wpdb->prepare( 'title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' );
		}
		if ( ! empty( $args['closing_before'] ) ) {
			$w[] = $wpdb->prepare( 'expected_close <= %s', (string) $args['closing_before'] );
		}
		if ( ! empty( $args['closed_after'] ) ) {
			$w[] = $wpdb->prepare( 'closed_at >= %s', (string) $args['closed_after'] );
		}
		return implode( ' AND ', $w );
	}

	/**
	 * Count, value and weighted value per stage of a pipeline (open deals; the won
	 * and lost columns show closed ones).
	 *
	 * @param int   $pipeline_id
	 * @param array $args Extra filters (owner…).
	 *
	 * @return array stage_id => { count, value, weighted }
	 */
	public static function totals_by_stage( $pipeline_id, array $args = array() ) {
		$out = array();
		foreach ( FW_CRM_Pipelines::stages( $pipeline_id ) as $s ) {
			$out[ (int) $s->id ] = array( 'count' => 0, 'value' => 0.0, 'weighted' => 0.0 );
		}
		foreach ( self::query( array_merge( $args, array( 'pipeline' => (int) $pipeline_id ) ) ) as $d ) {
			if ( ! isset( $out[ (int) $d->stage_id ] ) ) {
				continue;
			}
			$out[ (int) $d->stage_id ]['count']++;
			$out[ (int) $d->stage_id ]['value']    += (float) $d->value;
			$out[ (int) $d->stage_id ]['weighted'] += (float) $d->value * self::probability( $d ) / 100;
		}
		return $out;
	}

	/**
	 * @param array $data
	 * @param bool  $creating
	 *
	 * @return array|WP_Error Clean row.
	 */
	private static function sanitize( array $data, $creating ) {
		$row = array();
		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( (string) $data['title'] );
			if ( '' === $row['title'] ) {
				return new WP_Error( 'fw_crm_no_title', __( 'A deal needs a name.', 'fw' ) );
			}
		} elseif ( $creating ) {
			return new WP_Error( 'fw_crm_no_title', __( 'A deal needs a name.', 'fw' ) );
		}
		if ( isset( $data['value'] ) ) {
			$row['value'] = round( (float) str_replace( array( ',', ' ' ), '', (string) $data['value'] ), 2 );
		}
		if ( isset( $data['currency'] ) && '' !== $data['currency'] ) {
			$row['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $data['currency'] ), 0, 3 ) );
		}
		if ( array_key_exists( 'probability', $data ) ) {
			$row['probability'] = ( null === $data['probability'] || '' === $data['probability'] ) ? null : max( 0, min( 100, (int) $data['probability'] ) );
		}
		foreach ( array( 'owner_id', 'contact_id', 'company_id' ) as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$row[ $k ] = (int) $data[ $k ];
			}
		}
		if ( array_key_exists( 'expected_close', $data ) ) {
			$v                     = (string) $data['expected_close'];
			$row['expected_close'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
		}
		if ( isset( $data['lost_reason'] ) ) {
			$row['lost_reason'] = sanitize_text_field( (string) $data['lost_reason'] );
		}
		return $row;
	}

	/**
	 * Create a deal.
	 *
	 * @param array $data title (required), value, currency, pipeline_id, stage_id, owner_id, contact_id,
	 *                    contact_ids, company_id, expected_close, probability, fields
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$row = self::sanitize( $data, true );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$pipeline = ! empty( $data['pipeline_id'] ) ? FW_CRM_Pipelines::find( (int) $data['pipeline_id'] ) : null;
		$pipeline = $pipeline ? $pipeline : FW_CRM_Pipelines::default_pipeline();
		$stage    = ! empty( $data['stage_id'] ) ? FW_CRM_Pipelines::stage( (int) $data['stage_id'] ) : null;
		if ( ! $stage || (int) $stage->pipeline_id !== (int) $pipeline->id ) {
			$stage = FW_CRM_Pipelines::stage_of_kind( (int) $pipeline->id, 'open' );
		}
		$now = current_time( 'mysql' );
		$row = array_merge( array(
			'currency' => FW_CRM_Pipelines::currency( $pipeline ),
			'owner_id' => get_current_user_id(),
		), $row, array(
			'pipeline_id'      => (int) $pipeline->id,
			'stage_id'         => (int) $stage->id,
			'status'           => 'open' === $stage->kind ? 'open' : $stage->kind,
			'stage_changed_at' => $now,
			'closed_at'        => 'open' === $stage->kind ? null : $now,
			'created_at'       => $now,
			'updated_at'       => $now,
		) );
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'fw_crm_store_failed', __( 'Could not save the deal.', 'fw' ) );
		}
		$id = (int) $wpdb->insert_id;

		foreach ( array_filter( array_map( 'intval', (array) ( $data['contact_ids'] ?? array() ) ) ) as $cid ) {
			self::add_contact( $id, $cid, false );
		}
		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			FW_CRM_Fields::set_values( 'deal', $id, $data['fields'] );
		}
		self::log_everywhere( $id, 'created', sprintf( __( 'Deal created: %s', 'fw' ), $row['title'] ) );

		/** Fires after a deal is created. */
		do_action( 'unysonplus_crm_deal_created', self::get( $id ) );

		return $id;
	}

	/**
	 * Change a deal. Moving it to another stage goes through move().
	 *
	 * @param int   $id
	 * @param array $data Any create() field; stage_id moves the deal.
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$before = self::find( $id );
		if ( ! $before ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Deal not found.', 'fw' ) );
		}
		$row = self::sanitize( $data, false );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// A deal with products is worth what its lines add up to.
		if ( isset( $row['value'] ) && class_exists( 'FW_CRM_Products' ) && ! FW_CRM_Products::$syncing && FW_CRM_Products::totals( $id )['count'] ) {
			unset( $row['value'] );
		}
		if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
			$set = FW_CRM_Fields::set_values( 'deal', $id, $data['fields'] );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}
		if ( $row ) {
			$row['updated_at'] = current_time( 'mysql' );
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		}
		if ( ! empty( $data['stage_id'] ) && (int) $data['stage_id'] !== (int) $before->stage_id ) {
			$moved = self::move( $id, (int) $data['stage_id'], (string) ( $data['lost_reason'] ?? '' ) );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
		}
		$after   = self::find( $id );
		$changes = array();
		foreach ( array( 'title', 'value', 'currency', 'owner_id', 'contact_id', 'company_id', 'expected_close', 'probability' ) as $k ) {
			if ( (string) $before->$k !== (string) $after->$k ) {
				$changes[ $k ] = array( 'old' => $before->$k, 'new' => $after->$k );
			}
		}
		if ( isset( $changes['value'] ) ) {
			self::log( $id, 'deal', sprintf( __( 'Value changed from %1$s to %2$s', 'fw' ), self::money( $before->value, $before->currency ), self::money( $after->value, $after->currency ) ) );
		}
		if ( isset( $changes['owner_id'] ) ) {
			$u = (int) $after->owner_id ? get_userdata( (int) $after->owner_id ) : null;
			self::log( $id, 'owner', $u ? sprintf( __( 'Owner set to %s', 'fw' ), $u->display_name ) : __( 'Owner removed', 'fw' ) );
		}

		/** Fires after a deal changes: the deal now, the row before, the changed columns. */
		do_action( 'unysonplus_crm_deal_updated', self::get( $id ), $before, $changes );

		return true;
	}

	/**
	 * Move a deal to a stage (of its own pipeline, or another one). The won and lost
	 * stages close the deal; moving back to an open stage reopens it.
	 *
	 * @param int    $id
	 * @param int    $stage_id
	 * @param string $lost_reason
	 *
	 * @return true|WP_Error
	 */
	public static function move( $id, $stage_id, $lost_reason = '' ) {
		global $wpdb;

		$deal  = self::find( $id );
		$stage = FW_CRM_Pipelines::stage( (int) $stage_id );
		if ( ! $deal || ! $stage ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Deal or stage not found.', 'fw' ) );
		}
		if ( (int) $stage->id === (int) $deal->stage_id ) {
			return true;
		}
		$from = FW_CRM_Pipelines::stage( (int) $deal->stage_id );
		$now  = current_time( 'mysql' );
		$row  = array(
			'pipeline_id'      => (int) $stage->pipeline_id,
			'stage_id'         => (int) $stage->id,
			'status'           => 'open' === $stage->kind ? 'open' : $stage->kind,
			'stage_changed_at' => $now,
			'closed_at'        => 'open' === $stage->kind ? null : $now,
			'lost_reason'      => 'lost' === $stage->kind ? sanitize_text_field( (string) $lost_reason ) : '',
			'updated_at'       => $now,
		);
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );

		if ( 'won' === $stage->kind ) {
			self::log_everywhere( $id, 'won', sprintf( __( 'Deal won: %1$s (%2$s)', 'fw' ), $deal->title, self::money( $deal->value, $deal->currency ) ) );
			/** Fires when a deal is won. */
			do_action( 'unysonplus_crm_deal_won', self::get( $id ) );
		} elseif ( 'lost' === $stage->kind ) {
			self::log_everywhere( $id, 'lost', sprintf( __( 'Deal lost: %s', 'fw' ), $deal->title ) . ( '' !== $row['lost_reason'] ? ' (' . $row['lost_reason'] . ')' : '' ) );
			/** Fires when a deal is lost. */
			do_action( 'unysonplus_crm_deal_lost', self::get( $id ), $row['lost_reason'] );
		} else {
			self::log( $id, 'stage', sprintf( __( 'Moved from %1$s to %2$s', 'fw' ), $from ? $from->name : '?', $stage->name ), array( 'from' => $from ? (int) $from->id : 0, 'to' => (int) $stage->id ) );
		}

		/** Fires after a deal changes stage: the deal, the old stage, the new stage. */
		do_action( 'unysonplus_crm_deal_stage_changed', self::get( $id ), $from, $stage );

		return true;
	}

	/**
	 * Mark won (moves to the pipeline's won stage).
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function win( $id ) {
		$deal = self::find( $id );
		$won  = $deal ? FW_CRM_Pipelines::stage_of_kind( (int) $deal->pipeline_id, 'won' ) : null;
		return $won ? self::move( $id, (int) $won->id ) : new WP_Error( 'fw_crm_not_found', __( 'Deal not found.', 'fw' ) );
	}

	/**
	 * Mark lost, with a reason.
	 *
	 * @param int    $id
	 * @param string $reason
	 *
	 * @return true|WP_Error
	 */
	public static function lose( $id, $reason = '' ) {
		$deal = self::find( $id );
		$lost = $deal ? FW_CRM_Pipelines::stage_of_kind( (int) $deal->pipeline_id, 'lost' ) : null;
		return $lost ? self::move( $id, (int) $lost->id, $reason ) : new WP_Error( 'fw_crm_not_found', __( 'Deal not found.', 'fw' ) );
	}

	/**
	 * Put deals of one stage in a given order (the board's drag and drop).
	 *
	 * @param int[] $ids In display order.
	 */
	public static function reorder( array $ids ) {
		global $wpdb;

		foreach ( array_values( array_map( 'intval', $ids ) ) as $pos => $id ) {
			$wpdb->update( self::table(), array( 'position' => $pos ), array( 'id' => $id ) );
		}
	}

	/**
	 * Link another contact to the deal (or make one the primary).
	 *
	 * @param int  $deal_id
	 * @param int  $contact_id
	 * @param bool $primary
	 *
	 * @return true|WP_Error
	 */
	public static function add_contact( $deal_id, $contact_id, $primary = false ) {
		global $wpdb;

		$deal    = self::find( $deal_id );
		$contact = FW_CRM_Subscribers::find( $contact_id );
		if ( ! $deal || ! $contact ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Deal or contact not found.', 'fw' ) );
		}
		if ( $primary || ! (int) $deal->contact_id ) {
			$wpdb->update( self::table(), array( 'contact_id' => (int) $contact_id ), array( 'id' => (int) $deal_id ) );
		}
		FW_CRM_Relations::link( 'deal', (int) $deal_id, 'contact', (int) $contact_id, 'involved' );
		return true;
	}

	/**
	 * @param int $deal_id
	 * @param int $contact_id
	 */
	public static function remove_contact( $deal_id, $contact_id ) {
		global $wpdb;

		FW_CRM_Relations::unlink( 'deal', (int) $deal_id, 'contact', (int) $contact_id, 'involved' );
		$deal = self::find( $deal_id );
		if ( $deal && (int) $deal->contact_id === (int) $contact_id ) {
			$left = FW_CRM_Relations::ids( 'deal', (int) $deal_id, 'contact' );
			$wpdb->update( self::table(), array( 'contact_id' => $left ? (int) $left[0] : 0 ), array( 'id' => (int) $deal_id ) );
		}
	}

	/** Move to the bin. */
	public static function trash( $id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		self::log( $id, 'trashed', __( 'Moved to the bin', 'fw' ) );
		/** Fires after a deal is moved to the bin. */
		do_action( 'unysonplus_crm_deal_trashed', (int) $id );
		return true;
	}

	/** Bring back from the bin. */
	public static function restore( $id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'deleted_at' => null ), array( 'id' => (int) $id ) );
		self::log( $id, 'restored', __( 'Restored from the bin', 'fw' ) );
		return true;
	}

	/**
	 * Delete for good, with its links, timeline, tasks and field values.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function purge( $id ) {
		global $wpdb;

		$deal = self::find( $id );
		if ( ! $deal ) {
			return false;
		}
		FW_CRM_Relations::delete_for( 'deal', (int) $id );
		FW_CRM_Activity::delete_for( 'deal', (int) $id );
		FW_CRM_Fields::delete_for( 'deal', (int) $id );
		if ( class_exists( 'FW_CRM_Tasks' ) ) {
			FW_CRM_Tasks::delete_for( 'deal', (int) $id );
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );

		/** Fires after a deal is deleted for good. */
		do_action( 'unysonplus_crm_deal_deleted', $deal );
		return true;
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * "$1,250.00" style money, via WooCommerce's formatter when it is active.
	 *
	 * @param float  $amount
	 * @param string $currency
	 *
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		$amount   = (float) $amount;
		$currency = strtoupper( (string) $currency );
		if ( class_exists( 'NumberFormatter' ) && '' !== $currency ) {
			$f   = new NumberFormatter( get_locale(), NumberFormatter::CURRENCY );
			$out = $f->formatCurrency( $amount, $currency );
			if ( false !== $out ) {
				return $out;
			}
		}
		return trim( $currency . ' ' . number_format_i18n( $amount, 2 ) );
	}

	/**
	 * Timeline entry on the deal.
	 *
	 * @param int    $id
	 * @param string $type
	 * @param string $title
	 * @param array  $meta
	 */
	private static function log( $id, $type, $title, array $meta = array() ) {
		FW_CRM_Activity::log( 'deal', (int) $id, $type, $title, $meta ? array( 'meta' => $meta ) : array() );
	}

	/**
	 * Timeline entry on the deal, its contact and its company.
	 *
	 * @param int    $id
	 * @param string $type
	 * @param string $title
	 */
	private static function log_everywhere( $id, $type, $title ) {
		$deal = self::find( $id );
		self::log( $id, $type, $title );
		$meta = array( 'meta' => array( 'deal_id' => (int) $id ) );
		if ( $deal && (int) $deal->contact_id ) {
			FW_CRM_Activity::log( 'contact', (int) $deal->contact_id, 'deal', $title, $meta );
		}
		if ( $deal && (int) $deal->company_id ) {
			FW_CRM_Activity::log( 'company', (int) $deal->company_id, 'deal', $title, $meta );
		}
	}
}
