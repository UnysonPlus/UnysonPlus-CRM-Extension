<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CRM → Lead scoring: the warmest contacts, the rules, named scores, fading and site activity
 * settings. Adds a "Score & activity" panel to contact pages and the score to deal pages.
 */
class FW_CRM_Admin_Scoring {

	public static function init() {
		add_action( 'unysonplus_crm_render_view', function ( $view ) {
			if ( 'scoring' === $view ) {
				self::render();
			}
		} );
		add_filter( 'unysonplus_crm_handle_action', array( __CLASS__, 'handle' ), 10, 2 );
		add_filter( 'unysonplus_crm_contact_panels', function ( $panels, $record ) {
			$id                 = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
			$panels['scoring'] = array(
				'label'    => __( 'Score & activity', 'fw' ),
				'priority' => 2,
				'column'   => 'side',
				'callback' => function () use ( $id ) {
					self::render_contact_panel( $id );
				},
			);
			return $panels;
		}, 2, 2 );
		add_filter( 'unysonplus_crm_deal_panels', function ( $panels, $record ) {
			$id                 = is_array( $record ) ? (int) ( $record['id'] ?? 0 ) : (int) ( $record->id ?? 0 );
			$panels['scoring'] = array(
				'label'    => __( 'Lead score', 'fw' ),
				'priority' => 2,
				'column'   => 'side',
				'callback' => function () use ( $id ) {
					$score = FW_CRM_Scoring::deal_score( $id );
					echo '<p class="fw-crm-score-big"><strong>' . esc_html( number_format_i18n( $score ) ) . '</strong></p>';
					echo '<p class="fw-crm-muted">' . esc_html__( 'The highest score among the deal\'s contacts.', 'fw' ) . '</p>';
				},
			);
			return $panels;
		}, 2, 2 );
	}

	/**
	 * @param array|null $args
	 * @param string     $action
	 *
	 * @return array|null
	 */
	public static function handle( $args, $action ) {
		if ( null !== $args || 0 !== strpos( (string) $action, 'scoring_' ) ) {
			return $args;
		}
		$back = array( 'tab' => 'scoring' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the admin page.
		if ( 'scoring_adjust' === $action ) {
			$cid = isset( $_POST['contact'] ) ? (int) $_POST['contact'] : 0;
			if ( ! FW_CRM_Capabilities::can_edit_contact( $cid ) ) {
				wp_die( esc_html__( 'That contact belongs to someone else.', 'fw' ), 403 );
			}
			$pts = isset( $_POST['points'] ) ? (int) $_POST['points'] : 0;
			if ( $pts ) {
				FW_CRM_Scoring::add( $cid, $pts, isset( $_POST['score'] ) ? sanitize_key( wp_unslash( $_POST['score'] ) ) : 'default', sprintf( __( 'Changed by %s', 'fw' ), wp_get_current_user()->display_name ) );
			}
			return array( 'tab' => 'subscribers', 'subscriber' => $cid );
		}
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Only CRM managers can do that.', 'fw' ), 403 );
		}
		$s = FW_CRM_Scoring::settings();
		switch ( $action ) {
			case 'scoring_rule_add':
				$rule = FW_CRM_Scoring::clean_rule( array(
					'label'  => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by clean_rule().
					'score'  => isset( $_POST['score'] ) ? sanitize_key( wp_unslash( $_POST['score'] ) ) : 'default',
					'event'  => isset( $_POST['event'] ) ? ( 'event:' === $_POST['event'] ? 'event:' . sanitize_key( wp_unslash( $_POST['custom'] ?? '' ) ) : sanitize_text_field( wp_unslash( $_POST['event'] ) ) ) : '',
					'match'  => isset( $_POST['match'] ) ? wp_unslash( $_POST['match'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					'points' => isset( $_POST['points'] ) ? (int) $_POST['points'] : 0,
					'limit'  => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 0,
				) );
				if ( ! $rule || 'event:' === $rule['event'] ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'Choose what the rule reacts to and give it some points (they can be negative).', 'fw' ) );
					return $back;
				}
				$s['rules'][] = $rule;
				FW_CRM_Scoring::save_settings( $s );
				FW_CRM_Admin_Page::flash( 'success', __( 'Rule added. It counts from now on.', 'fw' ) );
				return $back;

			case 'scoring_rule_delete':
				$id         = isset( $_REQUEST['rule'] ) ? sanitize_key( wp_unslash( $_REQUEST['rule'] ) ) : '';
				$s['rules'] = array_values( array_filter( $s['rules'], function ( $r ) use ( $id ) {
					return $r['id'] !== $id;
				} ) );
				FW_CRM_Scoring::save_settings( $s );
				FW_CRM_Admin_Page::flash( 'success', __( 'Rule removed. Points already earned stay.', 'fw' ) );
				return $back;

			case 'scoring_settings':
				$names = isset( $_POST['score_names'] ) ? sanitize_textarea_field( wp_unslash( $_POST['score_names'] ) ) : '';
				$s['scores'] = array( 'default' => __( 'Score', 'fw' ) );
				foreach ( preg_split( '/\R/', $names ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$s['scores'][ sanitize_key( str_replace( ' ', '_', $line ) ) ] = $line;
					}
				}
				$s['decay'] = array(
					'on'      => ! empty( $_POST['decay_on'] ),
					'percent' => isset( $_POST['decay_percent'] ) ? (int) $_POST['decay_percent'] : 25,
					'days'    => isset( $_POST['decay_days'] ) ? (int) $_POST['decay_days'] : 30,
				);
				FW_CRM_Scoring::save_settings( $s );
				FW_CRM_Site_Activity::save_settings( array(
					'on'             => ! empty( $_POST['activity_on'] ),
					'consent'        => isset( $_POST['consent'] ) ? sanitize_key( wp_unslash( $_POST['consent'] ) ) : 'cookie',
					'consent_cookie' => isset( $_POST['consent_cookie'] ) ? sanitize_text_field( wp_unslash( $_POST['consent_cookie'] ) ) : '',
					'exclude'        => isset( $_POST['exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude'] ) ) : '',
				) );
				FW_CRM_Admin_Page::flash( 'success', __( 'Scoring settings saved.', 'fw' ) );
				return $back;
		}
		// phpcs:enable
		return $args;
	}

	public static function render() {
		if ( ! FW_CRM_Capabilities::can_manage() ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'For CRM managers', 'fw' ), __( 'Scoring is set up by CRM managers and administrators.', 'fw' ) );
			return;
		}
		$s      = FW_CRM_Scoring::settings();
		$a      = FW_CRM_Site_Activity::settings();
		$base   = FW_CRM_Admin_Page::get_page_url();
		$events = FW_CRM_Scoring::rule_events();
		$top    = FW_CRM_Scoring::top( 'default', 15 );
		?>
		<div class="fw-crm-scoring">
			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-top">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-top"><?php esc_html_e( 'Warmest contacts', 'fw' ); ?></h2></header>
				<?php if ( $top ) : ?>
					<ol class="fw-crm-score-top">
						<?php foreach ( $top as $row ) : ?>
							<?php $c = FW_CRM_Subscribers::find( (int) $row->contact_id ); ?>
							<?php if ( $c ) : ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base ) ); ?>"><?php echo esc_html( trim( $c->first_name . ' ' . $c->last_name ) ?: (string) $c->email ); ?></a>
									<strong><?php echo esc_html( number_format_i18n( (int) $row->points ) ); ?></strong>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ol>
				<?php else : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'Nobody has points yet. Add a rule below; points count from then on.', 'fw' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="fw-crm-panel" aria-labelledby="fw-crm-h-rules">
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-rules"><?php esc_html_e( 'Rules', 'fw' ); ?></h2></header>
				<?php if ( $s['rules'] ) : ?>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'When a contact', 'fw' ); ?></th><th><?php esc_html_e( 'Points', 'fw' ); ?></th><th><?php esc_html_e( 'Score', 'fw' ); ?></th><th><?php esc_html_e( 'At most', 'fw' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $s['rules'] as $r ) : ?>
							<tr>
								<td><?php echo esc_html( '' !== $r['label'] ? $r['label'] . ' · ' . FW_CRM_Scoring::describe( $r ) : FW_CRM_Scoring::describe( $r ) ); ?></td>
								<td><strong class="<?php echo $r['points'] < 0 ? 'is-late' : ''; ?>"><?php echo esc_html( ( $r['points'] > 0 ? '+' : '' ) . $r['points'] ); ?></strong></td>
								<td><?php echo esc_html( $s['scores'][ $r['score'] ] ?? $r['score'] ); ?></td>
								<td><?php echo $r['limit'] ? esc_html( sprintf( _n( '%d time', '%d times', $r['limit'], 'fw' ), $r['limit'] ) ) : esc_html__( 'No limit', 'fw' ); ?></td>
								<td><a class="button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'scoring', 'fw_crm_action' => 'scoring_rule_delete', 'rule' => $r['id'] ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"><?php esc_html_e( 'Remove', 'fw' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="fw-crm-muted"><?php esc_html_e( 'A common start: +5 for opening an email, +10 for a click, +15 for visiting the pricing page, +30 for a form, and -10 for the "not interested" tag.', 'fw' ); ?></p>
				<?php endif; ?>
				<form method="post" action="" class="fw-crm-form fw-crm-score-add">
					<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="scoring_rule_add" />
					<div class="fw-crm-form__grid">
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'When a contact', 'fw' ); ?></span>
							<select name="event" id="fw-crm-score-event">
								<?php foreach ( $events as $k => $e ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" data-match="<?php echo esc_attr( $e['match'] ); ?>"><?php echo esc_html( $e['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Custom event name', 'fw' ); ?></span>
							<input type="text" name="custom" list="fw-crm-event-names" placeholder="<?php esc_attr_e( 'For "Does a custom event"', 'fw' ); ?>" />
							<datalist id="fw-crm-event-names"><?php foreach ( FW_CRM_Events_Store::names() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php endforeach; ?></datalist>
						</label>
						<label class="fw-crm-form__row"><span id="fw-crm-score-match-label"><?php esc_html_e( 'Only when', 'fw' ); ?></span><input type="text" name="match" id="fw-crm-score-match" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Points', 'fw' ); ?></span><input type="number" name="points" required value="10" /></label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'Score', 'fw' ); ?></span>
							<select name="score"><?php foreach ( $s['scores'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
						</label>
						<label class="fw-crm-form__row"><span><?php esc_html_e( 'At most (times per contact, 0 = no limit)', 'fw' ); ?></span><input type="number" name="limit" min="0" value="0" /></label>
						<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'Name (optional)', 'fw' ); ?></span><input type="text" name="label" placeholder="<?php esc_attr_e( 'Visited pricing', 'fw' ); ?>" /></label>
					</div>
					<p class="fw-crm-form__foot"><button type="submit" class="button button-primary"><?php esc_html_e( 'Add rule', 'fw' ); ?></button></p>
				</form>
				<script>
				( function () {
					var sel = document.getElementById( 'fw-crm-score-event' ), lab = document.getElementById( 'fw-crm-score-match-label' ), inp = document.getElementById( 'fw-crm-score-match' );
					function sync() {
						var m = sel.options[ sel.selectedIndex ].getAttribute( 'data-match' );
						lab.textContent = m || '<?php echo esc_js( __( 'Only when', 'fw' ) ); ?>';
						inp.disabled = ! m;
					}
					sel.addEventListener( 'change', sync );
					sync();
				}() );
				</script>
			</section>

			<form method="post" action="" class="fw-crm-panel fw-crm-form" aria-labelledby="fw-crm-h-scoreset">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="scoring_settings" />
				<header class="fw-crm-panel__head"><h2 id="fw-crm-h-scoreset"><?php esc_html_e( 'Scores, fading and site activity', 'fw' ); ?></h2></header>

				<h3><?php esc_html_e( 'Named scores', 'fw' ); ?></h3>
				<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'One per line, besides the main Score (e.g. one per product or interest)', 'fw' ); ?></span>
					<textarea name="score_names" rows="3"><?php echo esc_textarea( implode( "\n", array_values( array_diff_key( $s['scores'], array( 'default' => '' ) ) ) ) ); ?></textarea>
				</label>

				<h3><?php esc_html_e( 'Fading', 'fw' ); ?></h3>
				<label class="fw-crm-check"><input type="checkbox" name="decay_on" value="1" <?php checked( $s['decay']['on'] ); ?> /> <?php esc_html_e( 'Scores fade when a contact goes quiet', 'fw' ); ?></label>
				<p class="fw-crm-inline">
					<?php esc_html_e( 'Lose', 'fw' ); ?> <input type="number" name="decay_percent" min="1" max="100" value="<?php echo (int) $s['decay']['percent']; ?>" class="small-text" />%
					<?php esc_html_e( 'after', 'fw' ); ?> <input type="number" name="decay_days" min="1" max="365" value="<?php echo (int) $s['decay']['days']; ?>" class="small-text" /> <?php esc_html_e( 'days without new points, and again every period after that.', 'fw' ); ?>
				</p>

				<h3><?php esc_html_e( 'Site activity', 'fw' ); ?></h3>
				<label class="fw-crm-check"><input type="checkbox" name="activity_on" value="1" <?php checked( $a['on'] ); ?> /> <?php esc_html_e( 'Record the pages known contacts visit', 'fw' ); ?></label>
				<p class="fw-crm-muted"><?php esc_html_e( 'Only for visitors who identified themselves (sent a form that adds them to the CRM, clicked a campaign link or logged in), using a signed first-party cookie. Anonymous visitors are never recorded, and browsers that send Do Not Track or Global Privacy Control are left out. Mention it in your privacy policy.', 'fw' ); ?></p>
				<div class="fw-crm-form__grid">
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Consent', 'fw' ); ?></span>
						<select name="consent">
							<option value="cookie" <?php selected( $a['consent'], 'cookie' ); ?>><?php esc_html_e( 'Only when my consent banner\'s cookie is set', 'fw' ); ?></option>
							<option value="always" <?php selected( $a['consent'], 'always' ); ?>><?php esc_html_e( 'My privacy notice covers it (no banner check)', 'fw' ); ?></option>
						</select>
					</label>
					<label class="fw-crm-form__row"><span><?php esc_html_e( 'Consent cookie name', 'fw' ); ?></span><input type="text" name="consent_cookie" value="<?php echo esc_attr( $a['consent_cookie'] ); ?>" placeholder="analytics_consent" /></label>
					<label class="fw-crm-form__row fw-crm-form__row--wide"><span><?php esc_html_e( 'Never record addresses starting with (one per line)', 'fw' ); ?></span><textarea name="exclude" rows="3"><?php echo esc_textarea( $a['exclude'] ); ?></textarea></label>
				</div>
				<p class="fw-crm-muted"><?php echo wp_kses( __( 'To record clicks on a button or link, add <code>data-crm-event="name"</code> to it (and optionally <code>data-crm-value="…"</code>). From code: <code>do_action( \'unysonplus_crm_track\', $contact_id_or_email, \'name\', array( \'value\' =&gt; … ) )</code>.', 'fw' ), array( 'code' => array() ) ); ?></p>
				<p class="fw-crm-form__foot"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fw' ); ?></button></p>
			</form>
		</div>
		<style>
			.fw-crm-scoring { display: grid; gap: 1.2em; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); align-items: start; }
			.fw-crm-scoring > form.fw-crm-panel { grid-column: 1 / -1; }
			@media (max-width: 1000px) { .fw-crm-scoring { grid-template-columns: 1fr; } }
			.fw-crm-score-top { margin: 0; padding-left: 1.4em; }
			.fw-crm-score-top li { display: flex; justify-content: space-between; padding: .3em 0; border-bottom: 1px solid var(--crm-border, #f0f0f1); }
			.fw-crm-score-add { margin-top: 1em; }
			.fw-crm-scoring .is-late { color: #b32d2e; }
			.fw-crm-scoring h3 { margin: 1.4em 0 .4em; }
		</style>
		<?php
	}

	/**
	 * Contact page: scores, recent changes and recent site activity.
	 *
	 * @param int $contact_id
	 */
	public static function render_contact_panel( $contact_id ) {
		$s      = FW_CRM_Scoring::settings();
		$scores = FW_CRM_Scoring::all_for( $contact_id );
		$events = FW_CRM_Events_Store::for_contact( $contact_id, 8 );
		$names  = FW_CRM_Events_Store::names();
		echo '<dl class="fw-crm-details">';
		foreach ( $scores as $k => $p ) {
			echo '<dt>' . esc_html( $s['scores'][ $k ] ?? $k ) . '</dt><dd><strong>' . esc_html( number_format_i18n( $p ) ) . '</strong></dd>';
		}
		echo '</dl>';
		$hist = FW_CRM_Scoring::history( $contact_id, 5 );
		if ( $hist ) {
			echo '<ul class="fw-crm-score-hist">';
			foreach ( $hist as $h ) {
				printf( '<li><strong>%1$s</strong> %2$s <span class="fw-crm-muted">%3$s</span></li>', esc_html( ( $h->points > 0 ? '+' : '' ) . $h->points ), esc_html( $h->reason ), FW_CRM_App::time_ago( $h->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
		if ( FW_CRM_Capabilities::can_edit_contact( $contact_id ) ) {
			?>
			<form method="post" action="" class="fw-crm-inline">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="scoring_adjust" />
				<input type="hidden" name="contact" value="<?php echo (int) $contact_id; ?>" />
				<label class="screen-reader-text" for="fw-crm-adjust"><?php esc_html_e( 'Points to add or remove', 'fw' ); ?></label>
				<input type="number" id="fw-crm-adjust" name="points" class="small-text" placeholder="+10" />
				<?php if ( count( $s['scores'] ) > 1 ) : ?>
					<select name="score"><?php foreach ( $s['scores'] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<?php endif; ?>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Adjust', 'fw' ); ?></button>
			</form>
			<?php
		}
		if ( $events ) {
			echo '<h3 class="fw-crm-subhead">' . esc_html__( 'Recent activity', 'fw' ) . '</h3><ul class="fw-crm-score-hist">';
			foreach ( $events as $e ) {
				$what = 'page_view' === $e->event
					? ( '' !== $e->value ? $e->value : (string) wp_parse_url( $e->url, PHP_URL_PATH ) )
					: ( $names[ $e->event ] ?? $e->event ) . ( '' !== $e->value && ! in_array( $e->event, array( 'email_open', 'email_click', 'form_submitted' ), true ) ? ': ' . $e->value : '' );
				$link = 'page_view' === $e->event && $e->url ? '<a href="' . esc_url( $e->url ) . '" target="_blank" rel="noopener">' . esc_html( $what ) . '</a>' : esc_html( $what );
				printf( '<li>%1$s <span class="fw-crm-muted">%2$s</span></li>', $link, FW_CRM_App::time_ago( $e->created_at ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
	}
}
