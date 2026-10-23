<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Automations: the list (with ready-made recipes and the scheduler's health), the visual
 * editor, an automation's activity (run log), and an Automations panel on contact pages.
 *
 * URLs: tab=automations, &automation=<id> (editor), &new=1, &log=<id> (activity), &run=<id>.
 */
class FW_CRM_Admin_Automations {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'automations' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, '_enqueue' ) );
		add_filter( 'unysonplus_crm_crumbs', array( __CLASS__, '_crumbs' ), 10, 2 );
		add_filter( 'unysonplus_crm_contact_panels', function ( $panels, $record ) {
			$id                    = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
			$panels['automations'] = array(
				'label'    => __( 'Automations', 'fw' ),
				'priority' => 6,
				'column'   => 'side',
				'callback' => function () use ( $id ) {
					self::render_contact_panel( $id );
				},
			);
			return $panels;
		}, 6, 2 );
	}

	/** @internal */
	public static function _enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( false === strpos( (string) $hook, 'fw-crm' ) || ! isset( $_GET['tab'] ) || 'automations' !== $_GET['tab'] ) {
			return;
		}
		$ext = fw_ext( 'crm' );
		$ver = $ext->manifest->get_version();
		wp_enqueue_style( 'fw-crm-automations', $ext->get_uri( '/modules/automations/static/css/editor.css' ), array( 'fw-ext-crm-app' ), $ver );
		wp_enqueue_script( 'fw-crm-automations', $ext->get_uri( '/modules/automations/static/js/editor.js' ), array(), $ver, true );
	}

	/** @internal */
	public static function _crumbs( $crumbs, $tab ) {
		if ( 'automations' !== $tab ) {
			return $crumbs;
		}
		$base = FW_CRM_Admin_Page::get_page_url( 'automations' );
		// phpcs:disable WordPress.Security.NonceVerification
		foreach ( array( 'automation', 'log' ) as $k ) {
			if ( ! empty( $_GET[ $k ] ) && ( $a = FW_CRM_Automations::find( (int) $_GET[ $k ] ) ) ) {
				return array( array( __( 'Automations', 'fw' ), $base ), array( 'log' === $k ? sprintf( __( 'Activity: %s', 'fw' ), $a->title ) : $a->title ) );
			}
		}
		if ( ! empty( $_GET['new'] ) ) {
			return array( array( __( 'Automations', 'fw' ), $base ), array( __( 'New automation', 'fw' ) ) );
		}
		// phpcs:enable
		return $crumbs;
	}

	/* ---------------------------------------------------------------------- *
	 * Actions
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'auto_' ) ) {
			return $args;
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		$id = isset( $_REQUEST['automation'] ) ? (int) $_REQUEST['automation'] : 0;
		switch ( $action ) {
			case 'auto_save':
				$data = json_decode( isset( $_POST['automation_json'] ) ? wp_unslash( $_POST['automation_json'] ) : '', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded and cleaned by FW_CRM_Automations::save().
				if ( ! is_array( $data ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'The automation could not be read. Nothing was saved.', 'fw' ) );
					return array( 'tab' => 'automations', 'automation' => $id );
				}
				$data['id'] = $id;
				$res        = FW_CRM_Automations::save( $data );
				if ( is_wp_error( $res ) ) {
					FW_CRM_Admin_Page::flash( 'error', $res->get_error_message() );
					return $id ? array( 'tab' => 'automations', 'automation' => $id ) : array( 'tab' => 'automations', 'new' => 1 );
				}
				$msg = __( 'Automation saved.', 'fw' );
				if ( ! empty( $_POST['then'] ) && in_array( $_POST['then'], array( 'active', 'paused' ), true ) ) {
					$st  = FW_CRM_Automations::set_status( $res, sanitize_key( $_POST['then'] ) );
					$msg = is_wp_error( $st ) ? $st->get_error_message() : ( 'active' === $_POST['then'] ? __( 'Automation saved and switched on.', 'fw' ) : __( 'Automation saved and paused.', 'fw' ) );
					FW_CRM_Admin_Page::flash( is_wp_error( $st ) ? 'error' : 'success', $msg );
				} else {
					FW_CRM_Admin_Page::flash( 'success', $msg );
				}
				return array( 'tab' => 'automations', 'automation' => $res );

			case 'auto_status':
				$st = FW_CRM_Automations::set_status( $id, isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : 'paused' );
				FW_CRM_Admin_Page::flash( is_wp_error( $st ) ? 'error' : 'success', is_wp_error( $st ) ? $st->get_error_message() : __( 'Status changed.', 'fw' ) );
				return array( 'tab' => 'automations' );

			case 'auto_duplicate':
				$new = FW_CRM_Automations::duplicate( $id );
				return is_wp_error( $new ) ? array( 'tab' => 'automations' ) : array( 'tab' => 'automations', 'automation' => $new );

			case 'auto_delete':
				FW_CRM_Automations::delete( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Automation deleted.', 'fw' ) );
				return array( 'tab' => 'automations' );

			case 'auto_recipe':
				$recipe = self::recipe( isset( $_REQUEST['recipe'] ) ? sanitize_key( wp_unslash( $_REQUEST['recipe'] ) ) : '' );
				if ( ! $recipe ) {
					return array( 'tab' => 'automations' );
				}
				$new = FW_CRM_Automations::save( $recipe );
				if ( is_wp_error( $new ) ) {
					FW_CRM_Admin_Page::flash( 'error', $new->get_error_message() );
					return array( 'tab' => 'automations' );
				}
				FW_CRM_Admin_Page::flash( 'success', __( 'Recipe added as a draft. Check the emails and settings, then switch it on.', 'fw' ) );
				return array( 'tab' => 'automations', 'automation' => $new );

			case 'auto_test':
				$email   = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
				$contact = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;
				if ( ! $contact ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'No contact has that email address.', 'fw' ) );
					return array( 'tab' => 'automations', 'automation' => $id );
				}
				$log = FW_CRM_Automation_Guard::test_run( $id, (int) $contact->id );
				if ( is_wp_error( $log ) ) {
					FW_CRM_Admin_Page::flash( 'error', $log->get_error_message() );
				} else {
					$lines = array_map( function ( $l ) {
						return ( 'failed' === $l->status ? '✗ ' : ( 'skipped' === $l->status ? '– ' : '✓ ' ) ) . esc_html( $l->message );
					}, $log );
					FW_CRM_Admin_Page::flash( 'success', '<strong>' . esc_html__( 'Test run finished.', 'fw' ) . '</strong><br>' . implode( '<br>', $lines ) );
				}
				return array( 'tab' => 'automations', 'automation' => $id );

			case 'auto_exit':
				FW_CRM_Automations::exit_runs( isset( $_REQUEST['contact'] ) ? (int) $_REQUEST['contact'] : 0, $id, 0, __( 'Removed by hand', 'fw' ) );
				FW_CRM_Admin_Page::flash( 'success', __( 'Removed from the automation.', 'fw' ) );
				return array( 'tab' => 'subscribers', 'subscriber' => isset( $_REQUEST['contact'] ) ? (int) $_REQUEST['contact'] : 0 );
		}
		// phpcs:enable
		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Recipes
	 * ---------------------------------------------------------------------- */

	/** @return array[] Recipes, each with `available`. */
	public static function recipes() {
		return FW_CRM_Automations::recipes();
	}

	/**
	 * @param string $id
	 *
	 * @return array|null Ready to pass to FW_CRM_Automations::save().
	 */
	private static function recipe( $id ) {
		return FW_CRM_Automations::recipe( $id );
	}

	/* ---------------------------------------------------------------------- *
	 * Screens
	 * ---------------------------------------------------------------------- */

	public static function render() {
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'For CRM managers', 'fw' ), __( 'Automations are set up by CRM managers and administrators.', 'fw' ) );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['log'] ) ) {
			self::render_log( (int) $_GET['log'] );
		} elseif ( ! empty( $_GET['automation'] ) || ! empty( $_GET['new'] ) ) {
			self::render_editor( (int) ( $_GET['automation'] ?? 0 ) );
		} else {
			self::render_list();
		}
		// phpcs:enable
	}

	private static function render_list() {
		$base  = FW_CRM_Admin_Page::get_page_url();
		$all   = FW_CRM_Automations::all();
		$trig  = FW_CRM_Automations::triggers();
		$state = array( 'active' => __( 'On', 'fw' ), 'paused' => __( 'Paused', 'fw' ), 'draft' => __( 'Draft', 'fw' ) );
		$act   = function ( $name, $id, $extra = array() ) use ( $base ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'tab' => 'automations', 'fw_crm_action' => $name, 'automation' => $id ), $extra ), $base ), FW_CRM_Admin_Page::NONCE );
		};
		?>
		<div class="fw-crm-listbar">
			<p class="fw-crm-muted fw-crm-auto-intro"><?php esc_html_e( 'An automation starts when something happens to a contact and then runs its steps for them: emails, waits, if / else, tags, fields, deals, tasks and more.', 'fw' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'automations', 'new' => 1 ), $base ) ); ?>"><?php esc_html_e( 'New automation', 'fw' ); ?></a>
		</div>

		<?php self::render_health(); ?>

		<?php if ( $all ) : ?>
			<table class="widefat striped fw-crm-auto-table">
				<thead><tr><th><?php esc_html_e( 'Automation', 'fw' ); ?></th><th><?php esc_html_e( 'Starts when', 'fw' ); ?></th><th><?php esc_html_e( 'Status', 'fw' ); ?></th><th><?php esc_html_e( 'Inside now', 'fw' ); ?></th><th><?php esc_html_e( 'Finished', 'fw' ); ?></th><th><?php esc_html_e( 'Problems', 'fw' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $all as $a ) : ?>
					<?php $sum = FW_CRM_Run_Log::summary( (int) $a->id ); ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'automations', 'automation' => (int) $a->id ), $base ) ); ?>"><strong><?php echo esc_html( $a->title ); ?></strong></a></td>
						<td><?php echo esc_html( $trig[ $a->trigger_type ]['label'] ?? '—' ); ?></td>
						<td><span class="fw-crm-auto-state fw-crm-auto-state--<?php echo esc_attr( $a->status ); ?>"><?php echo esc_html( $state[ $a->status ] ?? $a->status ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $sum['inside'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $sum['succeeded'] + $sum['partly'] ) ); ?></td>
						<td><?php echo $sum['failed'] + $sum['partly'] ? '<a href="' . esc_url( add_query_arg( array( 'tab' => 'automations', 'log' => (int) $a->id, 'status' => 'failed' ), $base ) ) . '" class="is-late">' . esc_html( number_format_i18n( $sum['failed'] + $sum['partly'] ) ) . '</a>' : '—'; ?></td>
						<td class="fw-crm-auto-table__actions">
							<?php if ( 'active' === $a->status ) : ?>
								<a href="<?php echo esc_url( $act( 'auto_status', (int) $a->id, array( 'status' => 'paused' ) ) ); ?>"><?php esc_html_e( 'Pause', 'fw' ); ?></a>
							<?php else : ?>
								<a href="<?php echo esc_url( $act( 'auto_status', (int) $a->id, array( 'status' => 'active' ) ) ); ?>"><?php esc_html_e( 'Switch on', 'fw' ); ?></a>
							<?php endif; ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'automations', 'log' => (int) $a->id ), $base ) ); ?>"><?php esc_html_e( 'Activity', 'fw' ); ?></a>
							<a href="<?php echo esc_url( $act( 'auto_duplicate', (int) $a->id ) ); ?>"><?php esc_html_e( 'Duplicate', 'fw' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 class="fw-crm-auto-h"><?php esc_html_e( 'Start from a recipe', 'fw' ); ?></h2>
		<ul class="fw-crm-recipes">
			<?php foreach ( self::recipes() as $r ) : ?>
				<li class="fw-crm-recipe<?php echo $r['available'] ? '' : ' is-unavailable'; ?>">
					<h3><?php echo esc_html( $r['title'] ); ?></h3>
					<p><?php echo esc_html( $r['description'] ); ?></p>
					<?php if ( $r['available'] ) : ?>
						<a class="button" href="<?php echo esc_url( $act( 'auto_recipe', 0, array( 'recipe' => $r['id'] ) ) ); ?>"><?php esc_html_e( 'Use this recipe', 'fw' ); ?></a>
					<?php else : ?>
						<p class="fw-crm-muted"><?php esc_html_e( 'Needs a module that is switched off (CRM → Modules).', 'fw' ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/** The scheduler's health, and how to fix it. */
	private static function render_health() {
		$h     = FW_CRM_Cron_Check::status();
		$lines = FW_CRM_Cron_Check::lines();
		?>
		<details class="fw-crm-panel fw-crm-auto-health fw-crm-auto-health--<?php echo esc_attr( $h['status'] ); ?>" <?php echo 'ok' === $h['status'] ? '' : 'open'; ?>>
			<summary>
				<span class="dashicons <?php echo 'ok' === $h['status'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Scheduler', 'fw' ); ?>:</strong> <?php echo esc_html( $h['message'] ); ?>
			</summary>
			<p><?php esc_html_e( 'WordPress runs scheduled work only when someone visits the site, so on a quiet site waits end late. For exact timing, add a cron job on your server (in cPanel: Cron Jobs) with one of these lines:', 'fw' ); ?></p>
			<p><label><?php esc_html_e( 'Any host (every 5 minutes):', 'fw' ); ?><br><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $lines['url'] ); ?>" onfocus="this.select()" /></label></p>
			<p><label><?php esc_html_e( 'With WP-CLI (every minute):', 'fw' ); ?><br><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $lines['cli'] ); ?>" onfocus="this.select()" /></label></p>
			<p class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'Then add %s to wp-config.php so visits no longer run the scheduler.', 'fw' ), $lines['config'] ) ); ?></p>
		</details>
		<?php
	}

	/**
	 * The editor.
	 *
	 * @param int $id
	 */
	private static function render_editor( $id ) {
		$a = $id ? FW_CRM_Automations::find( $id ) : null;
		if ( $id && ! $a ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Automation not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to automations', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'automations' ) ) ) );
			return;
		}
		$base   = FW_CRM_Admin_Page::get_page_url();
		$config = array(
			'automation' => array(
				'title'            => $a ? $a->title : '',
				'status'           => $a ? $a->status : 'draft',
				'trigger_type'     => $a ? $a->trigger_type : '',
				'trigger_settings' => $a ? (object) $a->trigger_settings : new stdClass(),
				'settings'         => $a ? $a->settings : FW_CRM_Automations::default_settings(),
				'steps'            => $a ? FW_CRM_Automations::steps( (int) $a->id ) : array(),
			),
			'triggers'   => self::for_js( FW_CRM_Automations::triggers(), 'fields' ),
			'actions'    => self::for_js( FW_CRM_Automations::actions(), 'settings' ),
			'condFields' => FW_CRM_Conditions::fields(),
			'ops'        => FW_CRM_Conditions::ops(),
			'dateFields' => FW_CRM_Trigger::date_field_options(),
			'counts'     => $a ? (object) FW_CRM_Automations::counts_by_step( (int) $a->id ) : new stdClass(),
			'inside'     => $a ? FW_CRM_Automations::count_runs( (int) $a->id, array( 'active', 'waiting' ) ) : 0,
			'i18n'       => self::i18n(),
		);
		foreach ( $config['condFields'] as $k => $f ) {
			unset( $config['condFields'][ $k ]['test'] );
		}
		$act = function ( $name, $extra = array() ) use ( $base, $id ) {
			return wp_nonce_url( add_query_arg( array_merge( array( 'tab' => 'automations', 'fw_crm_action' => $name, 'automation' => $id ), $extra ), $base ), FW_CRM_Admin_Page::NONCE );
		};
		?>
		<form method="post" action="" class="fw-crm-auto-editor" id="fw-crm-auto-form">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="auto_save" />
			<input type="hidden" name="automation" value="<?php echo (int) $id; ?>" />
			<input type="hidden" name="automation_json" id="fw-crm-auto-json" value="" />
			<div class="fw-crm-panel fw-crm-auto-bar">
				<label class="screen-reader-text" for="fw-crm-auto-title"><?php esc_html_e( 'Automation name', 'fw' ); ?></label>
				<input type="text" id="fw-crm-auto-title" class="fw-crm-auto-title" required placeholder="<?php esc_attr_e( 'Name this automation', 'fw' ); ?>" value="<?php echo esc_attr( $a ? $a->title : '' ); ?>" />
				<span class="fw-crm-auto-state fw-crm-auto-state--<?php echo esc_attr( $a ? $a->status : 'draft' ); ?>"><?php echo esc_html( array( 'active' => __( 'On', 'fw' ), 'paused' => __( 'Paused', 'fw' ), 'draft' => __( 'Draft', 'fw' ) )[ $a ? $a->status : 'draft' ] ); ?></span>
				<span class="fw-crm-auto-bar__spacer"></span>
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'fw' ); ?></button>
				<?php if ( ! $a || 'active' !== $a->status ) : ?>
					<button type="submit" name="then" value="active" class="button button-primary"><?php esc_html_e( 'Save and switch on', 'fw' ); ?></button>
				<?php else : ?>
					<button type="submit" name="then" value="paused" class="button"><?php esc_html_e( 'Save and pause', 'fw' ); ?></button>
				<?php endif; ?>
			</div>
			<?php if ( $a && $config['inside'] ) : ?>
				<p class="fw-crm-auto-note"><?php echo esc_html( sprintf( _n( '%s contact is inside this automation. Your changes apply to people who enter from now on; they finish the version they started.', '%s contacts are inside this automation. Your changes apply to people who enter from now on; they finish the version they started.', $config['inside'], 'fw' ), number_format_i18n( $config['inside'] ) ) ); ?></p>
			<?php endif; ?>
			<div class="fw-crm-auto-layout">
				<div class="fw-crm-auto-canvas" id="fw-crm-auto-canvas" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
					<noscript><p><?php esc_html_e( 'The automation editor needs JavaScript.', 'fw' ); ?></p></noscript>
				</div>
				<aside class="fw-crm-panel fw-crm-auto-inspector" id="fw-crm-auto-inspector" aria-live="polite"></aside>
			</div>
		</form>

		<?php if ( $a ) : ?>
			<div class="fw-crm-auto-after">
				<form method="post" action="" class="fw-crm-inline fw-crm-auto-test">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="auto_test" />
					<input type="hidden" name="automation" value="<?php echo (int) $id; ?>" />
					<label for="fw-crm-auto-test"><?php esc_html_e( 'Test run for one contact', 'fw' ); ?></label>
					<input type="email" id="fw-crm-auto-test" name="test_email" required placeholder="<?php esc_attr_e( 'Their email address', 'fw' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Run test', 'fw' ); ?></button>
					<span class="fw-crm-muted"><?php esc_html_e( 'Runs the saved steps now: waits are skipped and emails come to you, but tags, fields and deals change for real on that contact.', 'fw' ); ?></span>
				</form>
				<p>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'automations', 'log' => (int) $id ), $base ) ); ?>"><?php esc_html_e( 'Activity', 'fw' ); ?></a>
					<a class="button" href="<?php echo esc_url( $act( 'auto_duplicate' ) ); ?>"><?php esc_html_e( 'Duplicate', 'fw' ); ?></a>
					<a class="button-link button-link-delete" href="<?php echo esc_url( $act( 'auto_delete' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this automation, everyone\'s progress in it and its activity?', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * A registry without its callables, for the editor.
	 *
	 * @param array  $registry
	 * @param string $fields_key
	 *
	 * @return array
	 */
	private static function for_js( array $registry, $fields_key ) {
		$out = array();
		foreach ( $registry as $slug => $def ) {
			$fields = array();
			foreach ( (array) ( $def[ $fields_key ] ?? array() ) as $k => $f ) {
				$opts = $f['options'] ?? array();
				if ( is_callable( $opts ) && ! is_array( $opts ) ) {
					$opts = (array) call_user_func( $opts );
				}
				$fields[ $k ] = array(
					'label'    => (string) ( $f['label'] ?? $k ),
					'type'     => (string) ( $f['type'] ?? 'text' ),
					'options'  => (object) $opts,
					'required' => ! empty( $f['required'] ),
					'desc'     => (string) ( $f['desc'] ?? '' ),
				);
			}
			$out[ $slug ] = array( 'label' => (string) ( $def['label'] ?? $slug ), 'group' => (string) ( $def['group'] ?? '' ), 'fields' => (object) $fields );
		}
		return $out;
	}

	/** @return array */
	private static function i18n() {
		return array(
			'trigger'       => __( 'Starts when', 'fw' ),
			'chooseTrigger' => __( 'Choose what starts it', 'fw' ),
			'settings'      => __( 'Who and when', 'fw' ),
			'reentry'       => __( 'A contact can go through it', 'fw' ),
			'once'          => __( 'Once, ever', 'fw' ),
			'afterFinish'   => __( 'Again, once they have finished', 'fw' ),
			'always'        => __( 'Every time it is triggered', 'fw' ),
			'exitRules'     => __( 'They leave as soon as', 'fw' ),
			'exitHelp'      => __( 'Checked before every step. Leave empty to keep everyone until the end.', 'fw' ),
			'add'           => __( 'Add a step', 'fw' ),
			'action'        => __( 'Action', 'fw' ),
			'wait'          => __( 'Wait', 'fw' ),
			'condition'     => __( 'If / else', 'fw' ),
			'split'         => __( 'Random split', 'fw' ),
			'goal'          => __( 'Goal', 'fw' ),
			'yes'           => __( 'Yes', 'fw' ),
			'no'            => __( 'No', 'fw' ),
			'pathA'         => __( 'Path A', 'fw' ),
			'pathB'         => __( 'Path B', 'fw' ),
			'end'           => __( 'End', 'fw' ),
			'remove'        => __( 'Remove step', 'fw' ),
			'up'            => __( 'Move up', 'fw' ),
			'down'          => __( 'Move down', 'fw' ),
			'inside'        => __( '%d here now', 'fw' ),
			'pick'          => __( 'Select a step to edit it.', 'fw' ),
			'chooseAction'  => __( 'Choose an action', 'fw' ),
			'delay'         => __( 'For a while', 'fw' ),
			'untilTime'     => __( 'Until a day and time', 'fw' ),
			'untilDate'     => __( 'Until a date on the contact', 'fw' ),
			'amount'        => __( 'How long', 'fw' ),
			'minutes'       => __( 'minutes', 'fw' ),
			'hours'         => __( 'hours', 'fw' ),
			'days'          => __( 'days', 'fw' ),
			'weeks'         => __( 'weeks', 'fw' ),
			'weekday'       => __( 'Day', 'fw' ),
			'anyDay'        => __( 'The next day', 'fw' ),
			'weekdays'      => array( 1 => __( 'Monday', 'fw' ), 2 => __( 'Tuesday', 'fw' ), 3 => __( 'Wednesday', 'fw' ), 4 => __( 'Thursday', 'fw' ), 5 => __( 'Friday', 'fw' ), 6 => __( 'Saturday', 'fw' ), 7 => __( 'Sunday', 'fw' ) ),
			'time'          => __( 'At', 'fw' ),
			'dateField'     => __( 'Date field', 'fw' ),
			'when'          => __( 'When', 'fw' ),
			'on'            => __( 'On the day', 'fw' ),
			'before'        => __( 'Days before', 'fw' ),
			'after'         => __( 'Days after', 'fw' ),
			'match'         => __( 'Match', 'fw' ),
			'all'           => __( 'all of these', 'fw' ),
			'any'           => __( 'any of these', 'fw' ),
			'addRule'       => __( 'Add a condition', 'fw' ),
			'percent'       => __( 'Share taking path A (%)', 'fw' ),
			'goalHelp'      => __( 'When this happens to someone on an earlier step, they jump straight here. Reached in the normal course, it is passed.', 'fw' ),
			'goalTrigger'   => __( 'The goal is reached when', 'fw' ),
			'condHelp'      => __( 'Contacts who meet the conditions take the Yes path; everyone else takes No.', 'fw' ),
			'yes1'          => __( 'yes', 'fw' ),
			'none'          => __( '— Choose —', 'fw' ),
			'required'      => __( 'Fill in the highlighted fields first.', 'fw' ),
			'emptyFlow'     => __( 'No steps yet. Add the first one.', 'fw' ),
		);
	}

	/**
	 * An automation's activity: how runs ended and the latest log rows.
	 *
	 * @param int $id
	 */
	private static function render_log( $id ) {
		$a = FW_CRM_Automations::find( $id );
		if ( ! $a ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Automation not found', 'fw' ), '' );
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sum    = FW_CRM_Run_Log::summary( $id );
		$rows   = FW_CRM_Run_Log::for_automation( $id, 200, $status );
		$base   = FW_CRM_Admin_Page::get_page_url();
		$here   = add_query_arg( array( 'tab' => 'automations', 'log' => $id ), $base );
		?>
		<p class="fw-crm-listbar">
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'automations', 'automation' => $id ), $base ) ); ?>"><?php esc_html_e( 'Edit the automation', 'fw' ); ?></a>
		</p>
		<ul class="fw-crm-auto-sum">
			<li><strong><?php echo esc_html( number_format_i18n( $sum['inside'] ) ); ?></strong> <?php esc_html_e( 'inside now', 'fw' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $sum['succeeded'] ) ); ?></strong> <?php esc_html_e( 'finished', 'fw' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $sum['partly'] ) ); ?></strong> <?php esc_html_e( 'finished, partly done', 'fw' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $sum['failed'] ) ); ?></strong> <?php esc_html_e( 'failed', 'fw' ); ?></li>
			<li><strong><?php echo esc_html( number_format_i18n( $sum['exited'] ) ); ?></strong> <?php esc_html_e( 'left early', 'fw' ); ?></li>
		</ul>
		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( $here ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Everything', 'fw' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'failed', $here ) ); ?>" class="<?php echo 'failed' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Failed', 'fw' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'skipped', $here ) ); ?>" class="<?php echo 'skipped' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Skipped', 'fw' ); ?></a></li>
		</ul>
		<table class="widefat striped fw-crm-auto-log">
			<thead><tr><th><?php esc_html_e( 'When', 'fw' ); ?></th><th><?php esc_html_e( 'Contact', 'fw' ); ?></th><th><?php esc_html_e( 'What happened', 'fw' ); ?></th><th><?php esc_html_e( 'Result', 'fw' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4" class="fw-crm-muted"><?php esc_html_e( 'Nothing yet.', 'fw' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<?php $c = FW_CRM_Subscribers::find( (int) $r->contact_id ); ?>
				<tr>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r->created_at ) ) ); ?></td>
					<td><?php if ( $c ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base ) ); ?>"><?php echo esc_html( trim( $c->first_name . ' ' . $c->last_name ) ?: (string) $c->email ); ?></a><?php else : ?>—<?php endif; ?></td>
					<td><?php echo esc_html( $r->message ); ?></td>
					<td><span class="fw-crm-auto-result fw-crm-auto-result--<?php echo esc_attr( $r->status ); ?>"><?php echo esc_html( array( 'ok' => __( 'Done', 'fw' ), 'failed' => __( 'Failed', 'fw' ), 'skipped' => __( 'Skipped', 'fw' ) )[ $r->status ] ?? $r->status ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * On a contact's page: which automations they are in, and at which step.
	 *
	 * @param int $contact_id
	 */
	public static function render_contact_panel( $contact_id ) {
		$runs = FW_CRM_Automations::runs_for_contact( $contact_id, 10 );
		if ( ! $runs ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'Not in any automation.', 'fw' ) . '</p>';
			return;
		}
		$base   = FW_CRM_Admin_Page::get_page_url();
		$labels = array( 'active' => __( 'In progress', 'fw' ), 'waiting' => __( 'Waiting', 'fw' ), 'completed' => __( 'Finished', 'fw' ), 'exited' => __( 'Left', 'fw' ), 'failed' => __( 'Failed', 'fw' ) );
		echo '<ul class="fw-crm-auto-runs">';
		foreach ( $runs as $r ) {
			$a = FW_CRM_Automations::find( (int) $r->automation_id );
			if ( ! $a ) {
				continue;
			}
			$where = '';
			if ( in_array( $r->status, array( 'active', 'waiting' ), true ) ) {
				$index = FW_CRM_Automations::index( FW_CRM_Automations::steps( (int) $a->id, (int) $r->version ) );
				$where = isset( $index[ $r->step_id ] ) ? sprintf( __( 'Next: %s', 'fw' ), FW_CRM_Automations::step_label( $index[ $r->step_id ]['step'] ) ) : '';
				if ( 'waiting' === $r->status && $r->next_run_at ) {
					$where .= ' · ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r->next_run_at ) );
				}
			}
			printf(
				'<li><a href="%1$s">%2$s</a>%3$s <span class="fw-crm-auto-state fw-crm-auto-state--%4$s">%5$s</span>%6$s</li>',
				esc_url( add_query_arg( array( 'tab' => 'automations', 'automation' => (int) $a->id ), $base ) ),
				esc_html( $a->title ),
				$r->is_test ? ' <span class="fw-crm-muted">(' . esc_html__( 'test', 'fw' ) . ')</span>' : '',
				esc_attr( $r->status ),
				esc_html( $labels[ $r->status ] ?? $r->status ),
				'' !== $where ? '<br><small class="fw-crm-muted">' . esc_html( $where ) . '</small>' . ( FW_CRM_Capabilities::can_manage() ? ' <a class="button-link-delete" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'automations', 'fw_crm_action' => 'auto_exit', 'automation' => (int) $a->id, 'contact' => (int) $contact_id ), $base ), FW_CRM_Admin_Page::NONCE ) ) . '"><small>' . esc_html__( 'Remove', 'fw' ) . '</small></a>' : '' ) : ''
			);
		}
		echo '</ul>';
	}
}
