<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for Email marketing, beyond crm-save-campaign (in includes/ai-abilities.php):
 *
 *   crm-email-templates          the starter templates of the email builder
 *   crm-campaign-from-template   a DRAFT campaign laid out by a starter template, with the owner's texts
 *   crm-sending                  read or change sender profiles (who email comes from) and the post digest
 *
 * Nothing here sends, schedules or switches a digest on: sending is the owner's decision. Email
 * service API keys are never read or written here (they are secrets the owner pastes in).
 */

if ( ! function_exists( 'fw_ext_crm_ai_email_register' ) ) :

	function fw_ext_crm_ai_email_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_CRM_Campaigns' ) ) {
			return;
		}
		// The starters normally load with the email builder (admin screens); the abilities need them anywhere.
		if ( ! class_exists( 'FW_CRM_Email_Templates' ) ) {
			require_once dirname( __FILE__ ) . '/class-fw-crm-email-templates.php';
		}

		fw_ai_register_ability( 'crm-email-templates', array(
			'label'       => __( 'List the email starter templates', 'fw' ),
			'description' => 'The email builder\'s starter templates (id, title, and the blocks each is made of, with their texts), to pick one for crm-campaign-from-template.',
			'permission'  => 'manage_options',
			'readonly'    => true,
			'execute'     => 'fw_ext_crm_ai_email_templates',
		) );

		fw_ai_register_ability( 'crm-campaign-from-template', array(
			'label'       => __( 'Draft a campaign from a template', 'fw' ),
			'description' => 'Saves a DRAFT campaign built in the email builder from a starter template, with your texts in place of the template\'s: { template (id from crm-email-templates), title (internal name), subject, texts: [ "first text block", "second…" ] (replace the template\'s text blocks in order; HTML paragraphs are fine), headings?: [ … ] (replace its headings in order), button?: { label, url }, audience? { list?, tag?, segment? } }. Write in the owner\'s voice; placeholders like {{first_name}} work. Never scheduled or sent: the owner reviews it on CRM > Campaigns. Not undoable with undo_change.',
			'input'       => array(
				'template' => array( 'type' => 'string', 'minLength' => 1 ),
				'title'    => array( 'type' => 'string', 'minLength' => 1 ),
				'subject'  => array( 'type' => 'string', 'minLength' => 1 ),
				'texts'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'headings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'button'   => array( 'type' => 'object' ),
				'audience' => array( 'type' => 'object' ),
			),
			'required'    => array( 'template', 'title', 'subject' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_campaign_from_template',
		) );

		if ( class_exists( 'FW_CRM_Senders' ) ) {
			fw_ai_register_ability( 'crm-sending', array(
				'label'       => __( 'Set up CRM senders and the post digest', 'fw' ),
				'description' => 'Reads (no input) or changes who CRM email comes from and the post digest. senders: [ { name (for the owner, e.g. "Newsletter"), from_name, from_email, reply_to? } ] matched by name; defaults: { marketing: sender name, transactional: sender name } (marketing = campaigns and digests; transactional = confirmations, one-to-one email, case replies); digest: { frequency weekly | monthly, weekday 1-7, hour 0-23, list?, tag?, subject, intro, count, post_type } (its on/off switch is the owner\'s, it is never switched on here). The From address must be one the site may send for. Email service API keys are never set here. Undo with undo_change.',
				'input'       => array(
					'senders'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'defaults' => array( 'type' => 'object' ),
					'digest'   => array( 'type' => 'object' ),
				),
				'permission'  => 'manage_options',
				'idempotent'  => true,
				'execute'     => 'fw_ext_crm_ai_sending',
			) );
		}
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_crm_ai_email_register' );

	/** @return array */
	function fw_ext_crm_ai_email_templates() {
		$out = array();
		foreach ( FW_CRM_Email_Templates::all() as $id => $t ) {
			$out[] = array(
				'id'     => (string) $id,
				'title'  => (string) $t['title'],
				'blocks' => array_map( function ( $b ) {
					$o    = (array) ( $b['options'] ?? array() );
					$text = $o['text'] ?? ( $o['content'] ?? ( $o['label'] ?? '' ) );
					return array( 'type' => (string) ( $b['type'] ?? '' ), 'text' => is_string( $text ) ? wp_strip_all_tags( $text ) : '' );
				}, (array) $t['blocks'] ),
			);
		}
		return array( 'templates' => $out );
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_campaign_from_template( $in ) {
		if ( ! class_exists( 'FW_CRM_Email_Compiler' ) ) {
			return new WP_Error( 'fw_crm_module_off', 'Email marketing is switched off.' );
		}
		$all = FW_CRM_Email_Templates::all();
		$id  = (string) ( $in['template'] ?? '' );
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'fw_crm_ai_template', 'Unknown template. Use an id from crm-email-templates.' );
		}
		$texts    = array_values( (array) ( $in['texts'] ?? array() ) );
		$headings = array_values( (array) ( $in['headings'] ?? array() ) );
		$button   = (array) ( $in['button'] ?? array() );
		$blocks   = array();
		foreach ( (array) $all[ $id ]['blocks'] as $b ) {
			$type = (string) ( $b['type'] ?? '' );
			$opts = (array) ( $b['options'] ?? array() );
			if ( 'text' === $type && $texts ) {
				$t                 = (string) array_shift( $texts );
				$opts['content']   = false !== strpos( $t, '<' ) ? wp_kses_post( $t ) : wpautop( esc_html( $t ) );
				unset( $opts['text'] );
			} elseif ( 'heading' === $type && $headings ) {
				$opts['text'] = sanitize_text_field( (string) array_shift( $headings ) );
			} elseif ( 'button' === $type && $button ) {
				if ( isset( $button['label'] ) ) {
					$opts['label'] = sanitize_text_field( (string) $button['label'] );
				}
				if ( isset( $button['url'] ) ) {
					$opts['url'] = esc_url_raw( (string) $button['url'] );
				}
			}
			$b['options'] = $opts;
			$blocks[]     = $b;
		}
		// Extra texts the template had no room for go before the footer.
		foreach ( $texts as $t ) {
			$footer = array_pop( $blocks );
			$blocks[] = array( 'type' => 'text', 'options' => array( 'content' => false !== strpos( (string) $t, '<' ) ? wp_kses_post( (string) $t ) : wpautop( esc_html( (string) $t ) ) ) );
			$blocks[] = $footer;
		}
		$audience = array();
		foreach ( (array) ( $in['audience'] ?? array() ) as $k => $v ) {
			if ( in_array( $k, array( 'list', 'tag' ), true ) && function_exists( 'fw_ext_crm_ai_slug' ) ) {
				$audience[ $k ] = (string) fw_ext_crm_ai_slug( $v, $k );
			} elseif ( 'segment' === $k ) {
				$audience['segment'] = (int) $v;
			}
		}
		$title    = sanitize_text_field( (string) $in['title'] );
		$existing = 0;
		foreach ( FW_CRM_Campaigns::all() as $c ) {
			if ( 0 === strcasecmp( $c->title, $title ) && in_array( $c->status, array( 'draft', 'scheduled' ), true ) ) {
				$existing = (int) $c->id;
				break;
			}
		}
		$campaign = FW_CRM_Service::save_campaign( array(
			'id'        => $existing,
			'title'     => $title,
			'subject'   => sanitize_text_field( (string) $in['subject'] ),
			'body_json' => FW_CRM_Email_Templates::to_value( $blocks ),
			'audience'  => $audience,
		) );
		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}
		return array(
			'ok'          => true,
			'message'     => ( $existing ? 'Updated' : 'Saved' ) . ' the draft "' . $title . '" from the ' . $all[ $id ]['title'] . ' template. The owner reviews and sends it on CRM > Campaigns.',
			'campaign_id' => (int) $campaign->id,
			'would_reach' => FW_CRM_Service::audience_count( $campaign ),
		);
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_sending( $in ) {
		$in      = is_array( $in ) ? $in : array();
		$changes = array_intersect_key( $in, array_flip( array( 'senders', 'defaults', 'digest' ) ) );
		$rev     = 0;
		$errors  = array();
		if ( $changes ) {
			$opts = array( FW_CRM_Senders::OPTION );
			if ( class_exists( 'FW_CRM_Digest' ) ) {
				$opts[] = FW_CRM_Digest::OPTION;
			}
			$rev = ! function_exists( 'fw_ai_snapshot' ) ? 0 : fw_ai_snapshot( array( 'options' => $opts ), 'unysonplus/crm-sending', 'Changed CRM sending: ' . implode( ', ', array_keys( $changes ) ) );
			$by_name = array();
			foreach ( FW_CRM_Senders::profiles() as $p ) {
				$by_name[ strtolower( $p['name'] ) ] = $p['id'];
			}
			foreach ( (array) ( $changes['senders'] ?? array() ) as $p ) {
				$p   = (array) $p;
				$res = FW_CRM_Senders::save( array(
					'id'         => $by_name[ strtolower( (string) ( $p['name'] ?? '' ) ) ] ?? '',
					'name'       => (string) ( $p['name'] ?? '' ),
					'from_name'  => (string) ( $p['from_name'] ?? '' ),
					'from_email' => (string) ( $p['from_email'] ?? '' ),
					'reply_to'   => (string) ( $p['reply_to'] ?? '' ),
					'service'    => '', // Services hold API keys: the owner chooses them on the Settings screen.
				) );
				if ( is_wp_error( $res ) ) {
					$errors[] = ( $p['name'] ?? '?' ) . ': ' . $res->get_error_message();
				} else {
					$by_name[ strtolower( (string) $p['name'] ) ] = $res;
				}
			}
			if ( ! empty( $changes['defaults'] ) ) {
				$s = FW_CRM_Senders::settings();
				FW_CRM_Senders::set_defaults(
					$by_name[ strtolower( (string) ( $changes['defaults']['marketing'] ?? '' ) ) ] ?? $s['marketing'],
					$by_name[ strtolower( (string) ( $changes['defaults']['transactional'] ?? '' ) ) ] ?? $s['transactional']
				);
			}
			if ( ! empty( $changes['digest'] ) && class_exists( 'FW_CRM_Digest' ) ) {
				$d       = (array) $changes['digest'];
				$current = FW_CRM_Digest::settings();
				unset( $d['on'], $d['last_sent'] );
				FW_CRM_Digest::save_settings( array_merge( $current, $d, array( 'on' => $current['on'] ) ) );
			}
		}
		$s   = FW_CRM_Senders::settings();
		$out = array(
			'senders'  => array_values( array_map( function ( $p ) {
				return array( 'name' => $p['name'], 'from_name' => $p['from_name'], 'from_email' => $p['from_email'], 'reply_to' => $p['reply_to'], 'uses_email_service' => '' !== $p['service'] );
			}, $s['profiles'] ) ),
			'defaults' => array(
				'marketing'     => $s['profiles'][ $s['marketing'] ]['name'] ?? '',
				'transactional' => $s['profiles'][ $s['transactional'] ]['name'] ?? '',
			),
			'digest'   => class_exists( 'FW_CRM_Digest' ) ? array_diff_key( FW_CRM_Digest::settings(), array( 'last_sent' => 1 ) ) : null,
		);
		if ( $changes ) {
			$out = array_merge( array( 'ok' => ! $errors, 'message' => 'CRM sending updated.' . ( $errors ? ' Problems: ' . implode( ' | ', $errors ) : '' ) . ' The digest\'s on/off switch is the owner\'s.', 'revision' => $rev ), $out );
		}
		return $out;
	}

endif;
