<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Calendar: a month or a week of tasks, deals expected to close and
 * campaigns (FW_CRM_Calendar), with a quick "add a task on this day".
 *
 *   ?tab=calendar&view=month|week&date=Y-m-d&owner=<id|all>
 */
class FW_CRM_Admin_Calendar {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'calendar' === $view ) {
				self::render();
			}
		} );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, '_enqueue' ) );
	}

	/** @internal */
	public static function _enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( (string) $hook, 'fw-crm' ) || ! isset( $_GET['tab'] ) || 'calendar' !== $_GET['tab'] ) {
			return;
		}
		$ext = fw_ext( 'crm' );
		wp_enqueue_script( 'fw-crm-calendar', $ext->get_uri( '/static/js/calendar.js' ), array(), $ext->manifest->get_version(), true );
	}

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$view  = isset( $_GET['view'] ) && 'week' === $_GET['view'] ? 'week' : 'month';
		$date  = isset( $_GET['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date'] ) ? (string) $_GET['date'] : current_time( 'Y-m-d' );
		$owner = get_current_user_id();
		if ( FW_CRM_Capabilities::sees_all() && isset( $_GET['owner'] ) ) {
			$owner = 'all' === $_GET['owner'] ? '' : (int) $_GET['owner'];
		}
		// phpcs:enable

		$tz      = wp_timezone();
		$day     = new DateTimeImmutable( $date, $tz );
		$start_w = (int) get_option( 'start_of_week', 1 );
		if ( 'month' === $view ) {
			$first = $day->modify( 'first day of this month' );
			$from  = $first->modify( '-' . ( ( (int) $first->format( 'w' ) - $start_w + 7 ) % 7 ) . ' days' );
			$last  = $day->modify( 'last day of this month' );
			$to    = $last->modify( '+' . ( ( $start_w + 6 - (int) $last->format( 'w' ) + 7 ) % 7 ) . ' days' );
			$prev  = $first->modify( '-1 month' );
			$next  = $first->modify( '+1 month' );
			$title = date_i18n( 'F Y', $first->getTimestamp() + $first->getOffset() );
		} else {
			$from  = $day->modify( '-' . ( ( (int) $day->format( 'w' ) - $start_w + 7 ) % 7 ) . ' days' );
			$to    = $from->modify( '+6 days' );
			$prev  = $from->modify( '-7 days' );
			$next  = $from->modify( '+7 days' );
			$title = self::range_label( $from, $to );
		}

		$events = FW_CRM_Calendar::events( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ), $owner );
		$today  = current_time( 'Y-m-d' );
		$base   = FW_CRM_Admin_Page::get_page_url( 'calendar' );
		$keep   = array( 'view' => $view );
		if ( (int) $owner !== get_current_user_id() || '' === $owner ) {
			$keep['owner'] = '' === $owner ? 'all' : (int) $owner;
		}
		$url = function ( array $args ) use ( $base, $keep ) {
			return add_query_arg( array_merge( $keep, $args ), $base );
		};
		$back = $url( array( 'date' => $date ) );
		?>
		<div class="fw-crm-cal-bar">
			<div class="fw-crm-cal-bar__nav">
				<a class="button" href="<?php echo esc_url( $url( array( 'date' => $prev->format( 'Y-m-d' ) ) ) ); ?>" aria-label="<?php echo esc_attr( 'month' === $view ? __( 'Previous month', 'fw' ) : __( 'Previous week', 'fw' ) ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
				<a class="button" href="<?php echo esc_url( $url( array( 'date' => $today ) ) ); ?>"><?php esc_html_e( 'Today', 'fw' ); ?></a>
				<a class="button" href="<?php echo esc_url( $url( array( 'date' => $next->format( 'Y-m-d' ) ) ) ); ?>" aria-label="<?php echo esc_attr( 'month' === $view ? __( 'Next month', 'fw' ) : __( 'Next week', 'fw' ) ); ?>"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
				<h2 class="fw-crm-cal-bar__title" aria-live="polite"><?php echo esc_html( $title ); ?></h2>
			</div>
			<div class="fw-crm-cal-bar__tools">
				<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
					<form method="get" action="" class="fw-crm-inline">
						<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
						<input type="hidden" name="tab" value="calendar" />
						<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
						<input type="hidden" name="date" value="<?php echo esc_attr( $date ); ?>" />
						<label class="screen-reader-text" for="fw-crm-cal-owner"><?php esc_html_e( 'Whose calendar', 'fw' ); ?></label>
						<select id="fw-crm-cal-owner" name="owner" onchange="this.form.submit()">
							<option value="<?php echo (int) get_current_user_id(); ?>" <?php selected( $owner, get_current_user_id() ); ?>><?php esc_html_e( 'My calendar', 'fw' ); ?></option>
							<option value="all" <?php selected( '', $owner ); ?>><?php esc_html_e( 'Everyone', 'fw' ); ?></option>
							<?php foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) : ?>
								<?php if ( (int) $u->ID !== get_current_user_id() ) : ?>
									<option value="<?php echo (int) $u->ID; ?>" <?php selected( $owner, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<noscript><button class="button"><?php esc_html_e( 'Show', 'fw' ); ?></button></noscript>
					</form>
				<?php endif; ?>
				<div class="fw-crm-seg" role="group" aria-label="<?php esc_attr_e( 'Calendar view', 'fw' ); ?>">
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'month', 'date' => $date ), $url( array() ) ) ); ?>" <?php echo 'month' === $view ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Month', 'fw' ); ?></a>
					<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'week', 'date' => $date ), $url( array() ) ) ); ?>" <?php echo 'week' === $view ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'Week', 'fw' ); ?></a>
				</div>
			</div>
		</div>

		<div class="fw-crm-cal fw-crm-cal--<?php echo esc_attr( $view ); ?>" id="fw-crm-cal">
			<div class="fw-crm-cal__head" aria-hidden="true">
				<?php for ( $i = 0; $i < 7; $i++ ) : ?>
					<?php $d = $from->modify( '+' . $i . ' days' ); ?>
					<span><?php echo esc_html( date_i18n( 'D', $d->getTimestamp() + $d->getOffset() ) ); ?></span>
				<?php endfor; ?>
			</div>
			<ol class="fw-crm-cal__grid">
				<?php
				$limit = 'month' === $view ? 3 : 999;
				for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) :
					$key   = $d->format( 'Y-m-d' );
					$list  = $events[ $key ] ?? array();
					$out   = 'month' === $view && $d->format( 'm' ) !== $day->format( 'm' );
					$label = date_i18n( get_option( 'date_format' ), $d->getTimestamp() + $d->getOffset() );
					$class = 'fw-crm-cal__day' . ( $key === $today ? ' is-today' : '' ) . ( $out ? ' is-out' : '' ) . ( $key < $today ? ' is-past' : '' );
					?>
					<li class="<?php echo esc_attr( $class ); ?>" data-date="<?php echo esc_attr( $key ); ?>">
						<div class="fw-crm-cal__dayhead">
							<?php if ( 'month' === $view ) : ?>
								<a class="fw-crm-cal__num" href="<?php echo esc_url( add_query_arg( array( 'view' => 'week', 'date' => $key ), $url( array() ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Week of %s', 'fw' ), $label ) ); ?>"><?php echo esc_html( $d->format( 'j' ) ); ?></a>
							<?php else : ?>
								<span class="fw-crm-cal__num"><span class="fw-crm-cal__dow"><?php echo esc_html( date_i18n( 'D', $d->getTimestamp() + $d->getOffset() ) ); ?></span> <?php echo esc_html( $d->format( 'j' ) ); ?></span>
							<?php endif; ?>
							<a class="fw-crm-cal__add" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'tasks' ) ); ?>" data-date="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Add a task on %s', 'fw' ), $label ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></a>
						</div>
						<?php if ( $list ) : ?>
							<ul class="fw-crm-cal__events" aria-label="<?php echo esc_attr( $label ); ?>">
								<?php foreach ( array_slice( $list, 0, $limit ) as $e ) : ?>
									<li class="fw-crm-cal__ev fw-crm-cal__ev--<?php echo esc_attr( $e['kind'] ); ?><?php echo $e['done'] ? ' is-done' : ''; ?><?php echo $e['late'] ? ' is-late' : ''; ?>">
										<a href="<?php echo esc_url( $e['url'] ); ?>" title="<?php echo esc_attr( trim( $e['title'] . ( '' !== $e['sub'] ? ' · ' . $e['sub'] : '' ) ) ); ?>">
											<span class="dashicons <?php echo esc_attr( $e['icon'] ); ?>" aria-hidden="true"></span>
											<?php if ( '' !== $e['time'] ) : ?>
												<time><?php echo esc_html( date_i18n( get_option( 'time_format' ), strtotime( $key . ' ' . $e['time'] ) ) ); ?></time>
											<?php endif; ?>
											<span class="fw-crm-cal__evtitle"><?php echo esc_html( $e['title'] ); ?></span>
											<?php if ( 'week' === $view && '' !== $e['sub'] ) : ?>
												<span class="fw-crm-cal__evsub"><?php echo esc_html( $e['sub'] ); ?></span>
											<?php endif; ?>
											<?php if ( $e['done'] ) : ?>
												<span class="screen-reader-text"><?php esc_html_e( '(done)', 'fw' ); ?></span>
											<?php elseif ( $e['late'] ) : ?>
												<span class="screen-reader-text"><?php esc_html_e( '(overdue)', 'fw' ); ?></span>
											<?php endif; ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php if ( count( $list ) > $limit ) : ?>
								<a class="fw-crm-cal__more" href="<?php echo esc_url( add_query_arg( array( 'view' => 'week', 'date' => $key ), $url( array() ) ) ); ?>"><?php echo esc_html( sprintf( __( '%s more', 'fw' ), number_format_i18n( count( $list ) - $limit ) ) ); ?></a>
							<?php endif; ?>
						<?php elseif ( 'week' === $view ) : ?>
							<p class="fw-crm-muted fw-crm-cal__none"><?php esc_html_e( 'Nothing planned', 'fw' ); ?></p>
						<?php endif; ?>
					</li>
				<?php endfor; ?>
			</ol>
		</div>

		<ul class="fw-crm-cal-legend" aria-label="<?php esc_attr_e( 'Key', 'fw' ); ?>">
			<li class="fw-crm-cal__ev--task"><span aria-hidden="true"></span><?php esc_html_e( 'Tasks', 'fw' ); ?></li>
			<?php if ( class_exists( 'FW_CRM_Deals' ) ) : ?>
				<li class="fw-crm-cal__ev--deal"><span aria-hidden="true"></span><?php esc_html_e( 'Deals expected to close', 'fw' ); ?></li>
			<?php endif; ?>
			<?php if ( class_exists( 'FW_CRM_Campaigns' ) && FW_CRM_Capabilities::can_manage() ) : ?>
				<li class="fw-crm-cal__ev--campaign"><span aria-hidden="true"></span><?php esc_html_e( 'Campaigns', 'fw' ); ?></li>
			<?php endif; ?>
		</ul>

		<dialog class="fw-crm-dialog fw-crm-cal-dialog" id="fw-crm-cal-add" aria-labelledby="fw-crm-cal-add-h">
			<form method="post" action="" class="fw-crm-form fw-crm-task-form">
				<h2 id="fw-crm-cal-add-h"><?php esc_html_e( 'New task', 'fw' ); ?></h2>
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="task_create" />
				<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'What', 'fw' ); ?></span>
					<input type="text" name="title" required placeholder="<?php esc_attr_e( 'e.g. Call about the quote', 'fw' ); ?>" />
				</label>
				<div class="fw-crm-task-form__row">
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Type', 'fw' ); ?></span>
						<select name="type">
							<?php foreach ( FW_CRM_Tasks::types() as $k => $t ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'When', 'fw' ); ?></span>
						<input type="datetime-local" name="due_at" required />
					</label>
				</div>
				<p class="fw-crm-muted"><?php esc_html_e( 'To attach the task to a contact, company or deal, add it from that record\'s page.', 'fw' ); ?></p>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add task', 'fw' ); ?></button>
					<button type="button" class="button" data-close><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * "3 – 9 Nov 2026", "29 Dec 2026 – 4 Jan 2027".
	 *
	 * @param DateTimeImmutable $from
	 * @param DateTimeImmutable $to
	 *
	 * @return string
	 */
	private static function range_label( $from, $to ) {
		$f = $from->getTimestamp() + $from->getOffset();
		$t = $to->getTimestamp() + $to->getOffset();
		if ( $from->format( 'Y' ) !== $to->format( 'Y' ) ) {
			return date_i18n( 'j M Y', $f ) . ' – ' . date_i18n( 'j M Y', $t );
		}
		if ( $from->format( 'm' ) !== $to->format( 'm' ) ) {
			return date_i18n( 'j M', $f ) . ' – ' . date_i18n( 'j M Y', $t );
		}
		return date_i18n( 'j', $f ) . ' – ' . date_i18n( 'j M Y', $t );
	}
}
