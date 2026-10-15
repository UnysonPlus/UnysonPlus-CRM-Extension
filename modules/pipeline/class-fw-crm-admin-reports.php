<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Reports (sales): FW_CRM_Reports drawn as bars and tables.
 *
 *   ?tab=reports&pipeline=<id|0>&period=this_month|3m|6m|12m|this_year|custom&from=&to=&owner=<id|all>
 */
class FW_CRM_Admin_Reports {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'reports' === $view ) {
				self::render();
			}
		} );
	}

	/**
	 * @return array period key => label
	 */
	private static function periods() {
		return array(
			'this_month' => __( 'This month', 'fw' ),
			'3m'         => __( 'Last 3 months', 'fw' ),
			'6m'         => __( 'Last 6 months', 'fw' ),
			'12m'        => __( 'Last 12 months', 'fw' ),
			'this_year'  => __( 'This year', 'fw' ),
			'custom'     => __( 'Custom dates', 'fw' ),
		);
	}

	/**
	 * @param string $period
	 * @param string $from
	 * @param string $to
	 *
	 * @return array { from, to }
	 */
	private static function range( $period, $from, $to ) {
		$today = current_time( 'Y-m-d' );
		switch ( $period ) {
			case 'this_month':
				return array( current_time( 'Y-m-01' ), $today );
			case '3m':
			case '6m':
			case '12m':
				return array( gmdate( 'Y-m-01', strtotime( current_time( 'Y-m-01' ) . ' -' . ( (int) $period - 1 ) . ' months' ) ), $today );
			case 'this_year':
				return array( current_time( 'Y' ) . '-01-01', $today );
		}
		$ok = function ( $d ) {
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d );
		};
		return array( $ok( $from ) ? $from : current_time( 'Y-m-01' ), $ok( $to ) ? $to : $today );
	}

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$period = isset( $_GET['period'] ) && isset( self::periods()[ $_GET['period'] ] ) ? (string) $_GET['period'] : '6m';
		list( $from, $to ) = self::range( $period, isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '', isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '' );
		$pipeline = isset( $_GET['pipeline'] ) ? (int) $_GET['pipeline'] : 0;
		$owner    = '';
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			$owner = get_current_user_id();
		} elseif ( isset( $_GET['owner'] ) && 'all' !== $_GET['owner'] && '' !== $_GET['owner'] ) {
			$owner = (int) $_GET['owner'];
		}
		// phpcs:enable

		$r        = FW_CRM_Reports::sales( array( 'pipeline' => $pipeline, 'owner' => $owner, 'from' => $from, 'to' => $to ) );
		$s        = $r['summary'];
		$cur      = $s['currency'];
		$money    = function ( $v ) use ( $cur ) {
			return FW_CRM_Deals::money( $v, $cur );
		};
		$all      = FW_CRM_Pipelines::all();
		$p_name   = $pipeline && ( $p = FW_CRM_Pipelines::find( $pipeline ) ) ? $p->name : FW_CRM_Pipelines::default_pipeline()->name;
		$period_l = date_i18n( get_option( 'date_format' ), strtotime( $from ) ) . ' – ' . date_i18n( get_option( 'date_format' ), strtotime( $to ) );
		?>
		<form method="get" action="" class="fw-crm-listbar fw-crm-report-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="reports" />
			<?php if ( count( $all ) > 1 ) : ?>
				<label class="fw-crm-inline"><span class="screen-reader-text"><?php esc_html_e( 'Pipeline', 'fw' ); ?></span>
					<select name="pipeline">
						<option value="0"><?php esc_html_e( 'All pipelines', 'fw' ); ?></option>
						<?php foreach ( $all as $p ) : ?>
							<option value="<?php echo (int) $p->id; ?>" <?php selected( $pipeline, (int) $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<label class="fw-crm-inline"><span class="screen-reader-text"><?php esc_html_e( 'Period', 'fw' ); ?></span>
				<select name="period" data-report-period>
					<?php foreach ( self::periods() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $period, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<span class="fw-crm-inline fw-crm-report-dates" data-report-dates <?php echo 'custom' === $period ? '' : 'hidden'; ?>>
				<label><span class="screen-reader-text"><?php esc_html_e( 'From', 'fw' ); ?></span><input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<span aria-hidden="true">–</span>
				<label><span class="screen-reader-text"><?php esc_html_e( 'To', 'fw' ); ?></span><input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
			</span>
			<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
				<label class="fw-crm-inline"><span class="screen-reader-text"><?php esc_html_e( 'Whose deals', 'fw' ); ?></span>
					<select name="owner">
						<option value="all"><?php esc_html_e( 'Everyone', 'fw' ); ?></option>
						<?php foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) : ?>
							<option value="<?php echo (int) $u->ID; ?>" <?php selected( $owner, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<button class="button"><?php esc_html_e( 'Show', 'fw' ); ?></button>
			<p class="fw-crm-muted fw-crm-report-period"><?php echo esc_html( sprintf( __( 'Closed deals: %s', 'fw' ), $period_l ) ); ?></p>
		</form>

		<?php if ( ! FW_CRM_Deals::count( FW_CRM_Capabilities::scope( array() ) ) ) : ?>
			<?php
			FW_CRM_App::empty_state(
				'dashicons-chart-bar',
				__( 'No deals to report on yet', 'fw' ),
				__( 'Reports fill in as deals move through your pipeline: the value at each stage, what is likely to close and when, and what was won and lost.', 'fw' ),
				array( array( __( 'Add a deal', 'fw' ), add_query_arg( array( 'tab' => 'deals', 'new' => 1 ), FW_CRM_Admin_Page::get_page_url() ) ) )
			);
			return;
			?>
		<?php endif; ?>

		<dl class="fw-crm-kpis">
			<div><dt><?php esc_html_e( 'Open pipeline', 'fw' ); ?></dt><dd><?php echo esc_html( $money( $s['open_value'] ) ); ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( _n( '%s deal', '%s deals', $s['open_count'], 'fw' ), number_format_i18n( $s['open_count'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Weighted forecast', 'fw' ); ?></dt><dd><?php echo esc_html( $money( $s['weighted'] ) ); ?></dd><dd class="fw-crm-muted"><?php esc_html_e( 'value × stage probability', 'fw' ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Won', 'fw' ); ?></dt><dd><?php echo esc_html( $money( $s['won_value'] ) ); ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( _n( '%s deal', '%s deals', $s['won_count'], 'fw' ), number_format_i18n( $s['won_count'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Win rate', 'fw' ); ?></dt><dd><?php echo null === $s['win_rate'] ? '–' : esc_html( $s['win_rate'] . '%' ); ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( __( '%1$s won, %2$s lost', 'fw' ), number_format_i18n( $s['won_count'] ), number_format_i18n( $s['lost_count'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Average deal won', 'fw' ); ?></dt><dd><?php echo $s['won_count'] ? esc_html( $money( $s['avg_won'] ) ) : '–'; ?></dd><dd class="fw-crm-muted"><?php echo null === $s['avg_days'] ? '&nbsp;' : esc_html( sprintf( _n( '%s day to close', '%s days to close', $s['avg_days'], 'fw' ), number_format_i18n( $s['avg_days'] ) ) ); ?></dd></div>
		</dl>

		<div class="fw-crm-reports">
			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-r-stage">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-r-stage"><?php echo esc_html( sprintf( __( 'Open deals by stage: %s', 'fw' ), $p_name ) ); ?></h2></header>
				<?php self::bars( $r['by_stage'], 'name', $money, true ); ?>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-r-fc">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-r-fc"><?php esc_html_e( 'Forecast by expected close', 'fw' ); ?></h2></header>
				<?php
				$fc = array_values( array_filter( $r['forecast'], function ( $row ) {
					return $row['count'] || preg_match( '/^\d{4}-\d{2}$/', $row['key'] );
				} ) );
				self::bars( $fc, 'label', $money, true );
				?>
				<p class="fw-crm-muted fw-crm-report-note"><?php esc_html_e( 'The solid part of each bar is the weighted value: what the stage probabilities say is likely to close.', 'fw' ); ?></p>
			</section>

			<section class="fw-crm-panel fw-crm-reports__wide" aria-labelledby="fw-crm-h-r-wl">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-r-wl"><?php esc_html_e( 'Won and lost', 'fw' ); ?></h2></header>
				<?php self::columns( $r['won_lost'], $money ); ?>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-r-lr">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-r-lr"><?php esc_html_e( 'Why deals were lost', 'fw' ); ?></h2></header>
				<?php if ( $r['lost_reasons'] ) : ?>
					<?php self::bars( $r['lost_reasons'], 'reason', $money, false ); ?>
				<?php else : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'No deals were lost in this period.', 'fw' ); ?></p>
				<?php endif; ?>
			</section>

			<?php if ( FW_CRM_Capabilities::sees_all() && '' === $owner ) : ?>
				<section class="fw-crm-panel" aria-labelledby="fw-crm-h-r-owner">
					<header class="fw-crm-panel__head"><h2 id="fw-crm-h-r-owner"><?php esc_html_e( 'By owner', 'fw' ); ?></h2></header>
					<?php if ( $r['by_owner'] ) : ?>
						<div class="fw-crm-table-scroll">
							<table class="fw-crm-report-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Owner', 'fw' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Open', 'fw' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Weighted', 'fw' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Won', 'fw' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Win rate', 'fw' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Days to close', 'fw' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $r['by_owner'] as $o ) : ?>
										<tr>
											<th scope="row"><?php echo esc_html( $o['name'] ); ?></th>
											<td class="num"><?php echo esc_html( $money( $o['open_value'] ) ); ?> <span class="fw-crm-muted">(<?php echo esc_html( number_format_i18n( $o['open_count'] ) ); ?>)</span></td>
											<td class="num"><?php echo esc_html( $money( $o['weighted'] ) ); ?></td>
											<td class="num"><?php echo esc_html( $money( $o['won_value'] ) ); ?> <span class="fw-crm-muted">(<?php echo esc_html( number_format_i18n( $o['won_count'] ) ); ?>)</span></td>
											<td class="num"><?php echo null === $o['win_rate'] ? '–' : esc_html( $o['win_rate'] . '%' ); ?></td>
											<td class="num"><?php echo null === $o['avg_days'] ? '–' : esc_html( number_format_i18n( $o['avg_days'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else : ?>
						<p class="fw-crm-muted"><?php esc_html_e( 'No deals yet.', 'fw' ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>
		</div>
		<p class="fw-crm-muted fw-crm-report-note">
			<?php
			echo esc_html( sprintf(
				/* translators: %s: currency code */
				__( 'Amounts are added up as they are stored on each deal and shown in %s; deals in the bin are left out.', 'fw' ),
				$cur
			) );
			?>
		</p>
		<?php
	}

	/**
	 * Horizontal bars: a label, a bar (value; the weighted part solid), the value and a count.
	 *
	 * @param array    $rows
	 * @param string   $label_key
	 * @param callable $money
	 * @param bool     $weighted
	 */
	private static function bars( array $rows, $label_key, $money, $weighted ) {
		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, (float) $row['value'] );
		}
		?>
		<table class="fw-crm-bars<?php echo $weighted ? '' : ' fw-crm-bars--solid'; ?>">
			<thead class="screen-reader-text">
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Share', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Value', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Deals', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$v = (float) $row['value'];
					$w = $weighted ? (float) $row['weighted'] : $v;
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $row[ $label_key ] ); ?></th>
						<td class="fw-crm-bars__track" aria-hidden="true">
							<span class="fw-crm-bars__bar" style="width:<?php echo esc_attr( $max > 0 ? max( $v > 0 ? 1.5 : 0, round( 100 * $v / $max, 1 ) ) : 0 ); ?>%">
								<?php if ( $weighted && $v > 0 ) : ?>
									<span class="fw-crm-bars__fill" style="width:<?php echo esc_attr( round( 100 * $w / $v, 1 ) ); ?>%"></span>
								<?php endif; ?>
							</span>
						</td>
						<td class="num">
							<?php if ( ! $row['count'] ) : ?>
								<span class="fw-crm-muted">–</span>
							<?php else : ?>
								<?php echo esc_html( $money( $v ) ); ?>
								<?php if ( $weighted ) : ?>
									<span class="fw-crm-bars__w"><?php echo esc_html( sprintf( __( '%s weighted', 'fw' ), $money( $w ) ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="num fw-crm-muted"><?php echo $row['count'] ? esc_html( number_format_i18n( $row['count'] ) ) : ''; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Won and lost per month as paired columns, with the same numbers as a table for
	 * screen readers (and anyone who opens it).
	 *
	 * @param array    $rows
	 * @param callable $money
	 */
	private static function columns( array $rows, $money ) {
		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['won_value'], $row['lost_value'] );
		}
		?>
		<div class="fw-crm-cols" role="list" style="--n:<?php echo (int) count( $rows ); ?>">
			<?php foreach ( $rows as $row ) : ?>
				<?php $says = sprintf( __( '%1$s: won %2$s (%3$s), lost %4$s (%5$s)', 'fw' ), $row['label'], $money( $row['won_value'] ), number_format_i18n( $row['won_count'] ), $money( $row['lost_value'] ), number_format_i18n( $row['lost_count'] ) ); ?>
				<div class="fw-crm-cols__month" role="listitem" aria-label="<?php echo esc_attr( $says ); ?>" title="<?php echo esc_attr( $says ); ?>">
					<div class="fw-crm-cols__bars" aria-hidden="true">
						<span class="fw-crm-cols__won" style="height:<?php echo esc_attr( $max > 0 ? round( 100 * $row['won_value'] / $max, 1 ) : 0 ); ?>%"></span>
						<span class="fw-crm-cols__lost" style="height:<?php echo esc_attr( $max > 0 ? round( 100 * $row['lost_value'] / $max, 1 ) : 0 ); ?>%"></span>
					</div>
					<span class="fw-crm-cols__label" aria-hidden="true"><?php echo esc_html( $row['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<ul class="fw-crm-cal-legend" aria-hidden="true">
			<li class="fw-crm-cols-key--won"><span></span><?php esc_html_e( 'Won', 'fw' ); ?></li>
			<li class="fw-crm-cols-key--lost"><span></span><?php esc_html_e( 'Lost', 'fw' ); ?></li>
		</ul>
		<details class="fw-crm-report-data">
			<summary><?php esc_html_e( 'Show the numbers', 'fw' ); ?></summary>
			<div class="fw-crm-table-scroll">
				<table class="fw-crm-report-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Month', 'fw' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Won', 'fw' ); ?></th>
							<th scope="col" class="num"><?php esc_html_e( 'Lost', 'fw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td class="num"><?php echo esc_html( $money( $row['won_value'] ) ); ?> <span class="fw-crm-muted">(<?php echo esc_html( number_format_i18n( $row['won_count'] ) ); ?>)</span></td>
								<td class="num"><?php echo esc_html( $money( $row['lost_value'] ) ); ?> <span class="fw-crm-muted">(<?php echo esc_html( number_format_i18n( $row['lost_count'] ) ); ?>)</span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php
	}
}
