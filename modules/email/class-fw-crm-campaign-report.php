<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Campaigns → a campaign's report (`tab=campaigns&report=<id>`): delivery,
 * opens, clicks, bounces, complaints and unsubscribes, the first 24–72 hours, the
 * links people clicked and the latest activity.
 */
class FW_CRM_Campaign_Report {

	/**
	 * @param int $id
	 */
	public static function render( $id ) {
		$c    = FW_CRM_Campaigns::find( (int) $id );
		$base = FW_CRM_Admin_Page::get_page_url();
		if ( ! $c ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Campaign not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to campaigns', 'fw' ), add_query_arg( 'tab', 'campaigns', $base ) ) ) );
			return;
		}
		$s     = FW_CRM_Tracking::stats( $c->id );
		$total = (int) $c->total;
		$pct   = function ( $v ) {
			return number_format_i18n( $v, $v < 10 && floor( $v ) !== $v ? 1 : 0 ) . '%';
		};
		$opens_on  = FW_CRM_Tracking::enabled( 'opens' );
		$clicks_on = FW_CRM_Tracking::enabled( 'clicks' );
		?>
		<div class="fw-crm-listbar">
			<p class="fw-crm-muted">
				<?php
				echo esc_html( implode( ' · ', array_filter( array(
					sprintf( __( 'Subject: %s', 'fw' ), $c->subject ),
					$c->started_at ? sprintf( __( 'Sent %s', 'fw' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $c->started_at ) ) ) : '',
				) ) ) );
				?>
			</p>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'campaigns', 'campaign' => (int) $c->id ), $base ) ); ?>"><?php esc_html_e( 'View the email', 'fw' ); ?></a>
		</div>

		<dl class="fw-crm-kpis">
			<div><dt><?php esc_html_e( 'Sent', 'fw' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $s['sent'] ) ); ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'of %1$s · %2$s failed · %3$s skipped', 'fw' ), number_format_i18n( $total ), number_format_i18n( (int) $c->failed ), number_format_i18n( (int) $c->skipped ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Opened', 'fw' ); ?></dt><dd><?php echo $opens_on || $s['opened'] ? esc_html( $pct( $s['open_rate'] ) ) : '–'; ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( _n( '%s person', '%s people', $s['opened'], 'fw' ), number_format_i18n( $s['opened'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Clicked', 'fw' ); ?></dt><dd><?php echo $clicks_on || $s['clicked'] ? esc_html( $pct( $s['click_rate'] ) ) : '–'; ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( __( '%1$s people · %2$s of openers', 'fw' ), number_format_i18n( $s['clicked'] ), $pct( $s['click_to_open'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Unsubscribed', 'fw' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $s['unsubscribed'] ) ); ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( __( '%s marked it as spam', 'fw' ), number_format_i18n( $s['complained'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Bounced', 'fw' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $s['bounced'] ) ); ?></dd><dd class="fw-crm-muted"><?php echo esc_html( sprintf( __( '%s temporary', 'fw' ), number_format_i18n( $s['soft_bounced'] ) ) ); ?></dd></div>
		</dl>

		<div class="fw-crm-reports">
			<section class="fw-crm-panel fw-crm-reports__wide" aria-labelledby="fw-crm-h-cr-time">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-cr-time"><?php esc_html_e( 'First opens and clicks by hour after sending', 'fw' ); ?></h2></header>
				<?php self::hours( FW_CRM_Tracking::by_hour( $c->id ) ); ?>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-cr-links">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-cr-links"><?php esc_html_e( 'Links clicked', 'fw' ); ?></h2></header>
				<?php $links = FW_CRM_Tracking::top_links( $c->id ); ?>
				<?php if ( $links ) : ?>
					<div class="fw-crm-table-scroll">
						<table class="fw-crm-report-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Link', 'fw' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'People', 'fw' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $links as $l ) : ?>
									<tr>
										<th scope="row"><a href="<?php echo esc_url( $l->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( FW_CRM_Tracking::short_url( $l->url ) ); ?></a></th>
										<td class="num"><?php echo esc_html( number_format_i18n( (int) $l->people ) ); ?></td>
										<td class="num"><?php echo esc_html( number_format_i18n( (int) $l->clicks ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else : ?>
					<p class="fw-crm-muted"><?php echo $clicks_on ? esc_html__( 'No clicks yet.', 'fw' ) : esc_html__( 'Click tracking is off in CRM settings.', 'fw' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-cr-recent">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-cr-recent"><?php esc_html_e( 'Latest activity', 'fw' ); ?></h2></header>
				<?php $recent = FW_CRM_Tracking::recent( $c->id, 15 ); ?>
				<?php if ( $recent ) : ?>
					<ul class="fw-crm-feed">
						<?php foreach ( $recent as $e ) : ?>
							<?php
							$who   = FW_CRM_Subscribers::find( (int) $e->subscriber_id );
							$name  = $who ? ( trim( $who->first_name . ' ' . $who->last_name ) ?: $who->email ) : __( 'Someone', 'fw' );
							$label = self::label( $e );
							?>
							<li>
								<span class="fw-crm-feed__icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( self::icon( $e->type ) ); ?>"></span></span>
								<span class="fw-crm-feed__text">
									<?php if ( $who ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $who->id ), $base ) ); ?>"><?php echo esc_html( $name ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $name ); ?>
									<?php endif; ?>
									<?php echo esc_html( $label ); ?>
								</span>
								<?php echo FW_CRM_App::time_ago( $e->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'Nothing yet.', 'fw' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<p class="fw-crm-muted fw-crm-report-note">
			<?php
			echo esc_html( sprintf(
				/* translators: %s: number of scanner requests */
				__( 'Opens are an estimate: some mail apps load images for every email and others block them. %s requests from email security scanners were left out.', 'fw' ),
				number_format_i18n( $s['scanner_hits'] )
			) );
			?>
		</p>
		<?php
	}

	/**
	 * @param object $e
	 *
	 * @return string
	 */
	private static function label( $e ) {
		switch ( $e->type ) {
			case 'open':
				return __( 'opened it', 'fw' );
			case 'click':
				return sprintf( __( 'clicked %s', 'fw' ), FW_CRM_Tracking::short_url( FW_CRM_Tracking::link_url( (int) $e->link_id ) ) );
			case 'bounce':
				return __( 'bounced', 'fw' ) . ( '' !== $e->detail ? ' (' . $e->detail . ')' : '' );
			case 'soft_bounce':
				return __( 'could not be delivered for now', 'fw' );
			case 'complaint':
				return __( 'marked it as spam', 'fw' );
			case 'unsubscribe':
				return __( 'unsubscribed', 'fw' );
		}
		return (string) $e->type;
	}

	/**
	 * @param string $type
	 *
	 * @return string
	 */
	private static function icon( $type ) {
		$map = array(
			'open'        => 'dashicons-visibility',
			'click'       => 'dashicons-admin-links',
			'bounce'      => 'dashicons-warning',
			'soft_bounce' => 'dashicons-clock',
			'complaint'   => 'dashicons-flag',
			'unsubscribe' => 'dashicons-dismiss',
		);
		return $map[ $type ] ?? 'dashicons-email';
	}

	/**
	 * Paired columns per hour, with an accessible label per hour.
	 *
	 * @param array $rows
	 */
	private static function hours( array $rows ) {
		if ( ! $rows ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'Shown once the campaign has started sending.', 'fw' ) . '</p>';
			return;
		}
		$max = 1;
		foreach ( $rows as $r ) {
			$max = max( $max, $r['opens'], $r['clicks'] );
		}
		?>
		<div class="fw-crm-cols fw-crm-cols--hours" role="list" style="--n:<?php echo (int) count( $rows ); ?>">
			<?php foreach ( $rows as $r ) : ?>
				<?php $says = sprintf( __( 'Hour %1$s: %2$s opened, %3$s clicked', 'fw' ), number_format_i18n( $r['hour'] + 1 ), number_format_i18n( $r['opens'] ), number_format_i18n( $r['clicks'] ) ); ?>
				<div class="fw-crm-cols__month" role="listitem" aria-label="<?php echo esc_attr( $says ); ?>" title="<?php echo esc_attr( $says ); ?>">
					<div class="fw-crm-cols__bars" aria-hidden="true">
						<span class="fw-crm-cols__won fw-crm-cols__opens" style="height:<?php echo esc_attr( round( 100 * $r['opens'] / $max, 1 ) ); ?>%"></span>
						<span class="fw-crm-cols__clicks" style="height:<?php echo esc_attr( round( 100 * $r['clicks'] / $max, 1 ) ); ?>%"></span>
					</div>
					<span class="fw-crm-cols__label" aria-hidden="true"><?php echo 0 === $r['hour'] % 6 ? esc_html( sprintf( __( '%sh', 'fw' ), number_format_i18n( $r['hour'] ) ) ) : '&nbsp;'; ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<ul class="fw-crm-cal-legend" aria-hidden="true">
			<li class="fw-crm-key-opens"><span></span><?php esc_html_e( 'Opened', 'fw' ); ?></li>
			<li class="fw-crm-key-clicks"><span></span><?php esc_html_e( 'Clicked', 'fw' ); ?></li>
		</ul>
		<?php
	}
}
