<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Sales reports: the pipeline by stage, a forecast weighted by stage probability,
 * won and lost over time, lost reasons and performance per owner.
 *
 * Every report takes the same filter: { pipeline (0 = all), owner ('' = everyone),
 * from, to (Y-m-d, the period closed deals are counted in) }. The open pipeline and
 * the forecast are "as of now" and ignore the period. Scoping (a sales rep sees
 * their own deals) is applied by the caller through FW_CRM_Capabilities::scope().
 */
class FW_CRM_Reports {

	/**
	 * Normalise a filter.
	 *
	 * @param array $f
	 *
	 * @return array
	 */
	public static function filter( array $f ) {
		$to   = isset( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'] ) ? (string) $f['to'] : current_time( 'Y-m-d' );
		$from = isset( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'] ) ? (string) $f['from'] : gmdate( 'Y-m-01', strtotime( $to . ' -5 months' ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array(
			'pipeline' => (int) ( $f['pipeline'] ?? 0 ),
			'owner'    => isset( $f['owner'] ) && '' !== $f['owner'] && null !== $f['owner'] ? (int) $f['owner'] : '',
			'from'     => $from,
			'to'       => $to,
		);
	}

	/**
	 * @param array  $f
	 * @param string $alias
	 *
	 * @return string SQL conditions for the pipeline / owner part of a filter (live deals only).
	 */
	private static function where( array $f, $alias = '' ) {
		global $wpdb;

		$a = $alias ? $alias . '.' : '';
		$w = array( "{$a}deleted_at IS NULL" );
		if ( $f['pipeline'] ) {
			$w[] = $wpdb->prepare( "{$a}pipeline_id = %d", $f['pipeline'] ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( '' !== $f['owner'] ) {
			$w[] = $wpdb->prepare( "{$a}owner_id = %d", $f['owner'] ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return implode( ' AND ', $w );
	}

	/**
	 * The headline numbers.
	 *
	 * @param array $f
	 *
	 * @return array { open_count, open_value, weighted, won_count, won_value, lost_count, lost_value, win_rate (0–100|null),
	 *               avg_won (value), avg_days (to close a won deal), currency }
	 */
	public static function summary( array $f ) {
		global $wpdb;

		$f     = self::filter( $f );
		$table = FW_CRM_Deals::table();
		$open  = array( 'count' => 0, 'value' => 0.0, 'weighted' => 0.0 );
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' AND " . self::where( $f ) ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$open['count']++;
			$open['value']    += (float) $d->value;
			$open['weighted'] += (float) $d->value * FW_CRM_Deals::probability( $d ) / 100;
		}
		$closed = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT status, COUNT(*) AS n, SUM(value) AS v, AVG(value) AS avg_v, AVG(TIMESTAMPDIFF(DAY, created_at, closed_at)) AS days FROM {$table} WHERE status IN ('won','lost') AND closed_at BETWEEN %s AND %s AND " . self::where( $f ) . ' GROUP BY status', // phpcs:ignore WordPress.DB.PreparedSQL
			$f['from'] . ' 00:00:00',
			$f['to'] . ' 23:59:59'
		), OBJECT_K );
		$won  = $closed['won'] ?? null;
		$lost = $closed['lost'] ?? null;
		$wn   = $won ? (int) $won->n : 0;
		$ln   = $lost ? (int) $lost->n : 0;

		return array(
			'open_count' => $open['count'],
			'open_value' => round( $open['value'], 2 ),
			'weighted'   => round( $open['weighted'], 2 ),
			'won_count'  => $wn,
			'won_value'  => $won ? round( (float) $won->v, 2 ) : 0.0,
			'lost_count' => $ln,
			'lost_value' => $lost ? round( (float) $lost->v, 2 ) : 0.0,
			'win_rate'   => ( $wn + $ln ) ? (int) round( 100 * $wn / ( $wn + $ln ) ) : null,
			'avg_won'    => $won ? round( (float) $won->avg_v, 2 ) : 0.0,
			'avg_days'   => $won && null !== $won->days ? (int) round( (float) $won->days ) : null,
			'currency'   => self::currency( $f ),
		);
	}

	/**
	 * Open deals per stage (a single pipeline: the filter's, or the default).
	 *
	 * @param array $f
	 *
	 * @return array [ { stage_id, name, probability, count, value, weighted } ]
	 */
	public static function by_stage( array $f ) {
		$f        = self::filter( $f );
		$pipeline = $f['pipeline'] ? $f['pipeline'] : (int) FW_CRM_Pipelines::default_pipeline()->id;
		$args     = array( 'status' => 'open' );
		if ( '' !== $f['owner'] ) {
			$args['owner'] = $f['owner'];
		}
		$totals = FW_CRM_Deals::totals_by_stage( $pipeline, $args );
		$out    = array();
		foreach ( FW_CRM_Pipelines::stages( $pipeline ) as $s ) {
			if ( 'open' !== $s->kind ) {
				continue;
			}
			$t     = $totals[ (int) $s->id ];
			$out[] = array(
				'stage_id'    => (int) $s->id,
				'name'        => (string) $s->name,
				'probability' => (int) $s->probability,
				'count'       => $t['count'],
				'value'       => round( $t['value'], 2 ),
				'weighted'    => round( $t['weighted'], 2 ),
			);
		}
		return $out;
	}

	/**
	 * Open deals by the month they are expected to close, from this month on;
	 * deals whose date has passed and deals without a date get their own rows.
	 *
	 * @param array $f
	 * @param int   $months
	 *
	 * @return array [ { key (Y-m|overdue|none), label, count, value, weighted } ]
	 */
	public static function forecast( array $f, $months = 6 ) {
		global $wpdb;

		$f     = self::filter( $f );
		$table = FW_CRM_Deals::table();
		$start = current_time( 'Y-m' );
		$rows  = array(
			'overdue' => array( 'key' => 'overdue', 'label' => __( 'Past their date', 'fw' ), 'count' => 0, 'value' => 0.0, 'weighted' => 0.0 ),
		);
		$ts    = strtotime( $start . '-01' );
		for ( $i = 0; $i < $months; $i++ ) {
			$m          = gmdate( 'Y-m', strtotime( "+{$i} month", $ts ) );
			$rows[ $m ] = array( 'key' => $m, 'label' => date_i18n( 'M Y', strtotime( $m . '-01' ) ), 'count' => 0, 'value' => 0.0, 'weighted' => 0.0 );
		}
		$last          = gmdate( 'Y-m', strtotime( '+' . ( $months - 1 ) . ' month', $ts ) );
		$rows['later'] = array( 'key' => 'later', 'label' => __( 'Later', 'fw' ), 'count' => 0, 'value' => 0.0, 'weighted' => 0.0 );
		$rows['none']  = array( 'key' => 'none', 'label' => __( 'No date', 'fw' ), 'count' => 0, 'value' => 0.0, 'weighted' => 0.0 );

		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' AND " . self::where( $f ) ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $d->expected_close ) {
				$key = 'none';
			} else {
				$m   = substr( $d->expected_close, 0, 7 );
				$key = $m < $start ? 'overdue' : ( $m > $last ? 'later' : $m );
			}
			$rows[ $key ]['count']++;
			$rows[ $key ]['value']    += (float) $d->value;
			$rows[ $key ]['weighted'] += (float) $d->value * FW_CRM_Deals::probability( $d ) / 100;
		}
		foreach ( $rows as &$r ) {
			$r['value']    = round( $r['value'], 2 );
			$r['weighted'] = round( $r['weighted'], 2 );
		}
		unset( $r );
		return array_values( $rows );
	}

	/**
	 * Won and lost deals per month of the period.
	 *
	 * @param array $f
	 *
	 * @return array [ { key (Y-m), label, won_count, won_value, lost_count, lost_value } ]
	 */
	public static function won_lost( array $f ) {
		global $wpdb;

		$f     = self::filter( $f );
		$table = FW_CRM_Deals::table();
		$out   = array();
		for ( $m = substr( $f['from'], 0, 7 ); $m <= substr( $f['to'], 0, 7 ); $m = gmdate( 'Y-m', strtotime( $m . '-01 +1 month' ) ) ) {
			$out[ $m ] = array( 'key' => $m, 'label' => date_i18n( 'M Y', strtotime( $m . '-01' ) ), 'won_count' => 0, 'won_value' => 0.0, 'lost_count' => 0, 'lost_value' => 0.0 );
			if ( count( $out ) > 36 ) {
				break;
			}
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT DATE_FORMAT(closed_at, '%%Y-%%m') AS m, status, COUNT(*) AS n, SUM(value) AS v FROM {$table} WHERE status IN ('won','lost') AND closed_at BETWEEN %s AND %s AND " . self::where( $f ) . ' GROUP BY m, status', // phpcs:ignore WordPress.DB.PreparedSQL
			$f['from'] . ' 00:00:00',
			$f['to'] . ' 23:59:59'
		) );
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r->m ] ) ) {
				$out[ $r->m ][ $r->status . '_count' ] = (int) $r->n;
				$out[ $r->m ][ $r->status . '_value' ] = round( (float) $r->v, 2 );
			}
		}
		return array_values( $out );
	}

	/**
	 * Why deals were lost in the period.
	 *
	 * @param array $f
	 *
	 * @return array [ { reason, count, value } ] most first.
	 */
	public static function lost_reasons( array $f ) {
		global $wpdb;

		$f     = self::filter( $f );
		$table = FW_CRM_Deals::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT lost_reason AS reason, COUNT(*) AS n, SUM(value) AS v FROM {$table} WHERE status = 'lost' AND closed_at BETWEEN %s AND %s AND " . self::where( $f ) . ' GROUP BY lost_reason ORDER BY n DESC, v DESC', // phpcs:ignore WordPress.DB.PreparedSQL
			$f['from'] . ' 00:00:00',
			$f['to'] . ' 23:59:59'
		) );
		return array_map( function ( $r ) {
			return array(
				'reason' => '' !== (string) $r->reason ? (string) $r->reason : __( 'No reason given', 'fw' ),
				'count'  => (int) $r->n,
				'value'  => round( (float) $r->v, 2 ),
			);
		}, $rows );
	}

	/**
	 * Each owner's open pipeline and results in the period.
	 *
	 * @param array $f
	 *
	 * @return array [ { owner_id, name, open_count, open_value, weighted, won_count, won_value, lost_count, win_rate, avg_days } ]
	 *               biggest won value first.
	 */
	public static function by_owner( array $f ) {
		global $wpdb;

		$f     = self::filter( $f );
		$table = FW_CRM_Deals::table();
		$out   = array();
		$row   = function ( $owner ) use ( &$out ) {
			if ( ! isset( $out[ $owner ] ) ) {
				$u             = $owner ? get_userdata( $owner ) : null;
				$out[ $owner ] = array(
					'owner_id'   => $owner,
					'name'       => $u ? $u->display_name : __( 'Nobody', 'fw' ),
					'open_count' => 0,
					'open_value' => 0.0,
					'weighted'   => 0.0,
					'won_count'  => 0,
					'won_value'  => 0.0,
					'lost_count' => 0,
					'win_rate'   => null,
					'avg_days'   => null,
				);
			}
		};
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' AND " . self::where( $f ) ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$o = (int) $d->owner_id;
			$row( $o );
			$out[ $o ]['open_count']++;
			$out[ $o ]['open_value'] += (float) $d->value;
			$out[ $o ]['weighted']   += (float) $d->value * FW_CRM_Deals::probability( $d ) / 100;
		}
		$closed = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT owner_id, status, COUNT(*) AS n, SUM(value) AS v, AVG(TIMESTAMPDIFF(DAY, created_at, closed_at)) AS days FROM {$table} WHERE status IN ('won','lost') AND closed_at BETWEEN %s AND %s AND " . self::where( $f ) . ' GROUP BY owner_id, status', // phpcs:ignore WordPress.DB.PreparedSQL
			$f['from'] . ' 00:00:00',
			$f['to'] . ' 23:59:59'
		) );
		foreach ( $closed as $c ) {
			$o = (int) $c->owner_id;
			$row( $o );
			if ( 'won' === $c->status ) {
				$out[ $o ]['won_count'] = (int) $c->n;
				$out[ $o ]['won_value'] = round( (float) $c->v, 2 );
				$out[ $o ]['avg_days']  = null !== $c->days ? (int) round( (float) $c->days ) : null;
			} else {
				$out[ $o ]['lost_count'] = (int) $c->n;
			}
		}
		foreach ( $out as &$r ) {
			$r['open_value'] = round( $r['open_value'], 2 );
			$r['weighted']   = round( $r['weighted'], 2 );
			$closed_n        = $r['won_count'] + $r['lost_count'];
			$r['win_rate']   = $closed_n ? (int) round( 100 * $r['won_count'] / $closed_n ) : null;
		}
		unset( $r );
		usort( $out, function ( $a, $b ) {
			return $b['won_value'] <=> $a['won_value'] ?: $b['open_value'] <=> $a['open_value'];
		} );
		return $out;
	}

	/**
	 * Everything at once (the Reports screen and REST).
	 *
	 * @param array $f
	 *
	 * @return array
	 */
	public static function sales( array $f ) {
		$f = self::filter( $f );
		return array(
			'filter'       => $f,
			'summary'      => self::summary( $f ),
			'by_stage'     => self::by_stage( $f ),
			'forecast'     => self::forecast( $f ),
			'won_lost'     => self::won_lost( $f ),
			'lost_reasons' => self::lost_reasons( $f ),
			'by_owner'     => self::by_owner( $f ),
		);
	}

	/**
	 * The currency figures are shown in: the pipeline's, or the default one's.
	 *
	 * @param array $f
	 *
	 * @return string
	 */
	public static function currency( array $f ) {
		$p = ! empty( $f['pipeline'] ) ? FW_CRM_Pipelines::find( (int) $f['pipeline'] ) : null;
		return FW_CRM_Pipelines::currency( $p ? $p : FW_CRM_Pipelines::default_pipeline() );
	}
}
