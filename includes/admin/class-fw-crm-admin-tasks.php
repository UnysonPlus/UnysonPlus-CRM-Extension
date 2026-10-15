<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Tasks (My tasks), and the Tasks panel on contact, company and deal pages.
 *
 * Plugs into the app through its own extension points: the view renders on
 * `unysonplus_crm_render_view`, actions arrive through `unysonplus_crm_handle_action`,
 * and the panels through `unysonplus_crm_{contact|company|deal}_panels`.
 */
class FW_CRM_Admin_Tasks {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'tasks' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		foreach ( array( 'contact', 'company', 'deal' ) as $object ) {
			add_filter( 'unysonplus_crm_' . $object . '_panels', function ( $panels, $record ) use ( $object ) {
				$id = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
				$panels['tasks'] = array(
					'label'    => __( 'Tasks', 'fw' ),
					'priority' => 5,
					'column'   => 'side',
					'callback' => function () use ( $object, $id ) {
						self::render_panel( $object, $id );
					},
				);
				return $panels;
			}, 5, 2 );
		}
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null Redirect args.
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'task_' ) ) {
			return $args;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id   = isset( $_REQUEST['task'] ) ? (int) $_REQUEST['task'] : 0;
		$back = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : '';
		$to   = $back ? array( '_back' => $back ) : array( 'tab' => 'tasks' );

		if ( $id && ! FW_CRM_Capabilities::can_edit_task( $id ) ) {
			wp_die( esc_html__( 'That task belongs to someone else.', 'fw' ), 403 );
		}

		switch ( $action ) {
			case 'task_create':
				$object = isset( $_POST['object_type'] ) ? sanitize_key( wp_unslash( $_POST['object_type'] ) ) : '';
				$oid    = isset( $_POST['object_id'] ) ? (int) $_POST['object_id'] : 0;
				$data   = array(
					'title'        => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by FW_CRM_Tasks.
					'type'         => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'todo',
					'due_at'       => isset( $_POST['due_at'] ) ? sanitize_text_field( wp_unslash( $_POST['due_at'] ) ) : '',
					'duration'     => isset( $_POST['duration'] ) ? (int) $_POST['duration'] : 0,
					'location'     => isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '',
					'attendee_ids' => isset( $_POST['attendees'] ) ? array_map( 'intval', (array) $_POST['attendees'] ) : array(),
					'object_type'  => $object,
					'object_id'    => $oid,
					'owner_id'     => FW_CRM_Capabilities::sees_all() && isset( $_POST['owner_id'] ) ? (int) $_POST['owner_id'] : get_current_user_id(),
				);
				if ( $object && ! self::can_reach( $object, $oid ) ) {
					wp_die( esc_html__( 'That record belongs to someone else.', 'fw' ), 403 );
				}
				// Only people the record involves can be attendees.
				$data['attendee_ids'] = array_values( array_intersect( $data['attendee_ids'], FW_CRM_Tasks::candidate_attendees( $object, $oid ) ) );
				$logging = ! empty( $_POST['log'] );
				if ( $logging ) {
					$data['done']    = true;
					$data['outcome'] = isset( $_POST['outcome'] ) ? wp_unslash( $_POST['outcome'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by complete().
					if ( '' === trim( (string) $data['title'] ) ) {
						$data['title'] = 'meeting' === $data['type'] ? __( 'Meeting', 'fw' ) : __( 'Call', 'fw' );
					}
					if ( '' === $data['due_at'] ) {
						$data['due_at'] = current_time( 'Y-m-d H:i' );
					}
				}
				$result = FW_CRM_Tasks::create( $data );
				FW_CRM_Admin_Page::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : ( $logging ? __( 'Logged.', 'fw' ) : __( 'Task added.', 'fw' ) ) );
				return $to;

			case 'task_complete':
				FW_CRM_Tasks::complete(
					$id,
					isset( $_POST['outcome'] ) ? wp_unslash( $_POST['outcome'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by complete().
					isset( $_POST['duration'] ) && '' !== $_POST['duration'] ? (int) $_POST['duration'] : null
				);
				FW_CRM_Admin_Page::flash( 'success', __( 'Task done.', 'fw' ) );
				return $to;

			case 'task_reopen':
				FW_CRM_Tasks::reopen( $id );
				return $to;

			case 'task_delete':
				FW_CRM_Tasks::delete( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Task deleted.', 'fw' ) );
				return $to;
		}
		// phpcs:enable

		return $args;
	}

	/**
	 * May the current person add a task to this record?
	 *
	 * @param string $object
	 * @param int    $id
	 *
	 * @return bool
	 */
	private static function can_reach( $object, $id ) {
		switch ( $object ) {
			case 'contact':
				return FW_CRM_Capabilities::can_edit_contact( (int) $id );
			case 'company':
				return FW_CRM_Capabilities::can_edit_company( (int) $id );
			case 'deal':
				return FW_CRM_Capabilities::can_edit_deal( (int) $id );
		}
		return false;
	}

	/* ---------------------------------------------------------------------- *
	 * My tasks
	 * ---------------------------------------------------------------------- */

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$owner = get_current_user_id();
		if ( FW_CRM_Capabilities::sees_all() && isset( $_GET['owner'] ) ) {
			$owner = 'all' === $_GET['owner'] ? '' : (int) $_GET['owner'];
		}
		// phpcs:enable
		$base   = FW_CRM_Admin_Page::get_page_url( 'tasks' );
		$groups = array(
			'overdue'  => array( __( 'Overdue', 'fw' ), FW_CRM_Tasks::query( array( 'owner' => $owner, 'status' => 'overdue' ) ) ),
			'today'    => array( __( 'Today', 'fw' ), FW_CRM_Tasks::query( array( 'owner' => $owner, 'status' => 'today' ) ) ),
			'upcoming' => array( __( 'Upcoming', 'fw' ), FW_CRM_Tasks::query( array( 'owner' => $owner, 'status' => 'upcoming' ) ) ),
			'done'     => array( __( 'Done recently', 'fw' ), FW_CRM_Tasks::query( array( 'owner' => $owner, 'status' => 'done', 'per_page' => 10 ) ) ),
		);
		// "Today" in the overdue sense excludes earlier hours of today; drop overlaps.
		$overdue_ids          = wp_list_pluck( $groups['overdue'][1], 'id' );
		$groups['today'][1]   = array_values( array_filter( $groups['today'][1], function ( $t ) use ( $overdue_ids ) {
			return ! in_array( $t->id, $overdue_ids, true );
		} ) );
		?>
		<div class="fw-crm-listbar">
			<?php if ( FW_CRM_Capabilities::sees_all() ) : ?>
				<form method="get" action="" class="fw-crm-inline">
					<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
					<input type="hidden" name="tab" value="tasks" />
					<label class="screen-reader-text" for="fw-crm-task-owner"><?php esc_html_e( 'Whose tasks', 'fw' ); ?></label>
					<select id="fw-crm-task-owner" name="owner" onchange="this.form.submit()">
						<option value="<?php echo (int) get_current_user_id(); ?>" <?php selected( $owner, get_current_user_id() ); ?>><?php esc_html_e( 'My tasks', 'fw' ); ?></option>
						<option value="all" <?php selected( '', $owner ); ?>><?php esc_html_e( 'Everyone\'s tasks', 'fw' ); ?></option>
						<?php foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) : ?>
							<?php if ( (int) $u->ID !== get_current_user_id() ) : ?>
								<option value="<?php echo (int) $u->ID; ?>" <?php selected( $owner, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<noscript><button class="button"><?php esc_html_e( 'Show', 'fw' ); ?></button></noscript>
				</form>
			<?php else : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'Your tasks, and tasks on the records you own.', 'fw' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="fw-crm-tasks-page">
			<div class="fw-crm-tasks-page__main">
				<?php foreach ( $groups as $key => $g ) : ?>
					<?php
					if ( ! $g[1] && 'done' === $key ) {
						continue;
					}
					?>
					<section class="fw-crm-panel fw-crm-taskgroup fw-crm-taskgroup--<?php echo esc_attr( $key ); ?>" aria-labelledby="fw-crm-h-tg-<?php echo esc_attr( $key ); ?>">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-tg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $g[0] ); ?></h2>
							<span class="fw-crm-muted"><?php echo esc_html( number_format_i18n( count( $g[1] ) ) ); ?></span>
						</header>
						<?php if ( $g[1] ) : ?>
							<?php self::render_list( $g[1], $base, true ); ?>
						<?php else : ?>
							<p class="fw-crm-muted"><?php echo 'overdue' === $key ? esc_html__( 'Nothing overdue.', 'fw' ) : ( 'today' === $key ? esc_html__( 'Nothing due today.', 'fw' ) : esc_html__( 'Nothing planned.', 'fw' ) ); ?></p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>
			<section class="fw-crm-panel fw-crm-tasks-page__add" aria-labelledby="fw-crm-h-newtask">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-newtask"><?php esc_html_e( 'New task', 'fw' ); ?></h2></header>
				<?php self::render_form( '', 0, $base ); ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'To attach a task to a contact, company or deal, add it from that record\'s page. Reminders are emailed to the owner when a task is due.', 'fw' ); ?></p>
			</section>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Panel on a record
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $object
	 * @param int    $id
	 */
	public static function render_panel( $object, $id ) {
		$query = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt into an admin URL and escaped on output.
		$back  = remove_query_arg( array( 'fw_crm_action', '_wpnonce', 'task' ), admin_url( 'admin.php?' . $query ) );
		$open  = FW_CRM_Tasks::query( array( 'object_type' => $object, 'object_id' => $id, 'status' => 'open' ) );
		$done  = FW_CRM_Tasks::count( array( 'object_type' => $object, 'object_id' => $id, 'status' => 'done' ) );
		if ( $open ) {
			self::render_list( $open, $back, false );
		} else {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No open tasks. Add a call, a meeting or a to-do so the follow-up does not slip.', 'fw' ) . '</p>';
		}
		if ( $done ) {
			echo '<p class="fw-crm-muted fw-crm-tasks-done">' . esc_html( sprintf( _n( '%s done task is on the timeline.', '%s done tasks are on the timeline.', $done, 'fw' ), number_format_i18n( $done ) ) ) . '</p>';
		}
		echo '<div class="fw-crm-task-adds">';
		echo '<details class="fw-crm-task-add"><summary>' . esc_html__( 'Add a task', 'fw' ) . '</summary>';
		self::render_form( $object, $id, $back );
		echo '</details>';
		echo '<details class="fw-crm-task-add"><summary>' . esc_html__( 'Log a call or meeting', 'fw' ) . '</summary>';
		self::render_form( $object, $id, $back, true );
		echo '</details>';
		echo '</div>';
	}

	/**
	 * @param array  $tasks
	 * @param string $back      Where actions return to.
	 * @param bool   $with_target Show which record each task is on.
	 */
	private static function render_list( array $tasks, $back, $with_target ) {
		$types = FW_CRM_Tasks::types();
		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		?>
		<ul class="fw-crm-tasklist">
			<?php foreach ( $tasks as $t ) : ?>
				<?php
				$due     = $t->due_at ? strtotime( $t->due_at ) : 0;
				$late    = ! $t->done_at && $due && $due < $now;
				$target  = $with_target ? FW_CRM_Tasks::target( $t ) : array( 'label' => '' );
				$owner   = (int) $t->owner_id !== get_current_user_id() ? get_userdata( (int) $t->owner_id ) : null;
				$people  = array();
				foreach ( FW_CRM_Tasks::attendees( (int) $t->id ) as $cid ) {
					$c = FW_CRM_Subscribers::find( $cid );
					if ( $c ) {
						$people[] = trim( $c->first_name . ' ' . $c->last_name ) ?: (string) $c->email;
					}
				}
				$ask     = ! $t->done_at && in_array( $t->type, array( 'call', 'meeting' ), true );
				$actions = array(
					'fw_crm_action' => $t->done_at ? 'task_reopen' : 'task_complete',
					'task'          => (int) $t->id,
					'back'          => $back,
				);
				?>
				<li class="fw-crm-task<?php echo $t->done_at ? ' is-done' : ''; ?><?php echo $late ? ' is-late' : ''; ?>">
					<form method="post" action="" class="fw-crm-task__check"<?php echo $ask ? ' data-ask="1" data-title="' . esc_attr( $t->title ) . '" data-duration="' . (int) $t->duration . '"' : ''; ?>>
						<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
						<?php foreach ( $actions as $k => $v ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
						<?php endforeach; ?>
						<button type="submit" class="fw-crm-task__box" aria-label="<?php echo esc_attr( $t->done_at ? sprintf( __( 'Mark "%s" as not done', 'fw' ), $t->title ) : sprintf( __( 'Mark "%s" as done', 'fw' ), $t->title ) ); ?>">
							<span class="dashicons <?php echo $t->done_at ? 'dashicons-yes' : esc_attr( $types[ $t->type ]['icon'] ?? 'dashicons-yes-alt' ); ?>" aria-hidden="true"></span>
						</button>
					</form>
					<div class="fw-crm-task__text">
						<span class="fw-crm-task__title"><?php echo esc_html( $t->title ); ?></span>
						<span class="fw-crm-task__meta">
							<?php echo esc_html( $types[ $t->type ]['label'] ?? '' ); ?>
							<?php if ( $due ) : ?>
								· <time datetime="<?php echo esc_attr( gmdate( 'c', $due ) ); ?>"><?php echo esc_html( self::when( $due, $now ) ); ?></time>
							<?php endif; ?>
							<?php if ( '' !== $target['label'] ) : ?>
								· <a href="<?php echo esc_url( $target['url'] ); ?>"><?php echo esc_html( $target['label'] ); ?></a>
							<?php endif; ?>
							<?php if ( (int) $t->duration ) : ?>
								· <span class="fw-crm-nowrap"><?php echo esc_html( FW_CRM_Tasks::duration_label( (int) $t->duration ) ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== (string) $t->location ) : ?>
								· <?php echo esc_html( $t->location ); ?>
							<?php endif; ?>
							<?php if ( $owner ) : ?>
								· <?php echo esc_html( $owner->display_name ); ?>
							<?php endif; ?>
							<?php if ( $people ) : ?>
								<span class="fw-crm-task__people"><span class="dashicons dashicons-groups" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'With', 'fw' ); ?></span> <?php echo esc_html( implode( ', ', $people ) ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<a class="fw-crm-unlink" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'task_delete', 'task' => (int) $t->id, 'back' => rawurlencode( $back ) ), FW_CRM_Admin_Page::get_page_url( 'tasks' ) ), FW_CRM_Admin_Page::NONCE ) ); ?>"
					   aria-label="<?php echo esc_attr( sprintf( __( 'Delete "%s"', 'fw' ), $t->title ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		self::render_done_dialog();
	}

	/**
	 * Ticking off a call or meeting asks how it went (once per page).
	 */
	private static function render_done_dialog() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<dialog class="fw-crm-dialog" id="fw-crm-task-done" aria-labelledby="fw-crm-task-done-h">
			<form method="post" action="" class="fw-crm-form">
				<h2 id="fw-crm-task-done-h"><?php esc_html_e( 'How did it go?', 'fw' ); ?></h2>
				<p class="fw-crm-muted" data-title></p>
				<div data-fields></div>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Outcome', 'fw' ); ?></span>
					<textarea name="outcome" rows="3" placeholder="<?php esc_attr_e( 'e.g. Agreed to a trial; send the proposal on Friday', 'fw' ); ?>"></textarea>
				</label>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'How long', 'fw' ); ?></span>
					<?php self::duration_select( 'fw-crm-done-duration', 0 ); ?>
				</label>
				<p class="fw-crm-form__foot">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Mark done', 'fw' ); ?></button>
					<button type="button" class="button" data-close><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	/**
	 * @param string $id
	 * @param int    $value Minutes.
	 */
	private static function duration_select( $id, $value ) {
		$choices = array( 0 => __( 'Not set', 'fw' ) );
		foreach ( array( 5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 240 ) as $m ) {
			$choices[ $m ] = FW_CRM_Tasks::duration_label( $m );
		}
		if ( $value && ! isset( $choices[ $value ] ) ) {
			$choices[ $value ] = FW_CRM_Tasks::duration_label( $value );
		}
		?>
		<select name="duration" id="<?php echo esc_attr( $id ); ?>">
			<?php foreach ( $choices as $m => $label ) : ?>
				<option value="<?php echo (int) $m; ?>" <?php selected( (int) $value, (int) $m ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * "Today 15:00", "Tomorrow 09:00", "Mon 3 Nov".
	 *
	 * @param int $ts
	 * @param int $now
	 *
	 * @return string
	 */
	private static function when( $ts, $now ) {
		$day   = gmdate( 'Y-m-d', $ts );
		$today = gmdate( 'Y-m-d', $now );
		$time  = date_i18n( get_option( 'time_format' ), $ts );
		if ( $day === $today ) {
			return sprintf( __( 'Today %s', 'fw' ), $time );
		}
		if ( gmdate( 'Y-m-d', $now + DAY_IN_SECONDS ) === $day ) {
			return sprintf( __( 'Tomorrow %s', 'fw' ), $time );
		}
		if ( gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ) === $day ) {
			return sprintf( __( 'Yesterday %s', 'fw' ), $time );
		}
		return date_i18n( get_option( 'date_format' ), $ts ) . ' ' . $time;
	}

	/**
	 * @param string $object
	 * @param int    $id
	 * @param string $back
	 * @param bool   $log  Log a call or meeting that already happened.
	 */
	private static function render_form( $object, $id, $back, $log = false ) {
		$uid    = 'fw-crm-task-' . ( $object ? $object : 'own' ) . ( $log ? '-log' : '' );
		$people = array();
		foreach ( FW_CRM_Tasks::candidate_attendees( $object, $id ) as $cid ) {
			$c = FW_CRM_Subscribers::find( $cid );
			if ( $c && ! $c->deleted_at ) {
				$people[ $cid ] = trim( $c->first_name . ' ' . $c->last_name ) ?: (string) $c->email;
			}
		}
		$types = FW_CRM_Tasks::types();
		if ( $log ) {
			$types = array_intersect_key( $types, array( 'call' => 1, 'meeting' => 1 ) );
		}
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		?>
		<form method="post" action="" class="fw-crm-form fw-crm-task-form" data-task-form>
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="task_create" />
			<input type="hidden" name="object_type" value="<?php echo esc_attr( $object ); ?>" />
			<input type="hidden" name="object_id" value="<?php echo (int) $id; ?>" />
			<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
			<?php if ( $log ) : ?>
				<input type="hidden" name="log" value="1" />
			<?php endif; ?>
			<div class="fw-crm-task-form__row">
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Type', 'fw' ); ?></span>
					<select name="type" data-task-type>
						<?php foreach ( $types as $k => $t ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="fw-crm-form__row"><span><?php echo $log ? esc_html__( 'When', 'fw' ) : esc_html__( 'Due', 'fw' ); ?></span>
					<input type="datetime-local" name="due_at" value="<?php echo esc_attr( $log ? gmdate( 'Y-m-d\TH:i', $now ) : gmdate( 'Y-m-d\TH:00', $now + DAY_IN_SECONDS ) ); ?>" />
				</label>
			</div>
			<label class="fw-crm-form__row"><span><?php echo $log ? esc_html__( 'Subject (optional)', 'fw' ) : esc_html__( 'What', 'fw' ); ?></span>
				<input type="text" name="title" <?php echo $log ? '' : 'required'; ?> placeholder="<?php echo $log ? esc_attr__( 'e.g. Pricing questions', 'fw' ) : esc_attr__( 'e.g. Call about the quote', 'fw' ); ?>" />
			</label>
			<?php if ( $log ) : ?>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'Outcome', 'fw' ); ?></span>
					<textarea name="outcome" rows="3" placeholder="<?php esc_attr_e( 'What was said, what happens next', 'fw' ); ?>"></textarea>
				</label>
			<?php endif; ?>
			<div class="fw-crm-task-form__row" data-for-types="call meeting"<?php echo $log ? '' : ' hidden'; ?>>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'How long', 'fw' ); ?></span>
					<?php self::duration_select( $uid . '-duration', $log ? 15 : 30 ); ?>
				</label>
				<label class="fw-crm-form__row" data-for-types="meeting" hidden><span><?php esc_html_e( 'Where', 'fw' ); ?></span>
					<input type="text" name="location" placeholder="<?php esc_attr_e( 'Address or video link', 'fw' ); ?>" />
				</label>
			</div>
			<?php if ( $people && 'contact' !== $object ) : ?>
				<fieldset class="fw-crm-form__row fw-crm-attendees" data-for-types="call meeting"<?php echo $log ? '' : ' hidden'; ?>>
					<legend><?php esc_html_e( 'With', 'fw' ); ?></legend>
					<?php foreach ( $people as $cid => $name ) : ?>
						<label><input type="checkbox" name="attendees[]" value="<?php echo (int) $cid; ?>" /> <?php echo esc_html( $name ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<?php if ( FW_CRM_Capabilities::sees_all() && ! $log ) : ?>
				<label class="fw-crm-form__row"><span><?php esc_html_e( 'For', 'fw' ); ?></span>
					<select name="owner_id">
						<?php foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) : ?>
							<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $u->ID, get_current_user_id() ); ?>><?php echo esc_html( $u->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<p class="fw-crm-form__foot"><button type="submit" class="button button-primary"><?php echo $log ? esc_html__( 'Log it', 'fw' ) : esc_html__( 'Add task', 'fw' ); ?></button></p>
		</form>
		<?php
	}
}
