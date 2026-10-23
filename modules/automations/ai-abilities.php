<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for Automations: set automations up, always as DRAFTS.
 *
 *   crm-automations         every automation (status, trigger, steps in plain words, how many people
 *                           are inside / finished), the ready-made recipes, and the triggers and
 *                           actions available here (with their settings)
 *   crm-save-automation     create or update a DRAFT automation, from a recipe or from a trigger and steps
 *
 * Nothing here switches an automation on, starts anyone in one, or runs a test: an active
 * automation emails and changes real people, so the owner switches it on after reading it.
 */

if ( ! function_exists( 'fw_ext_crm_ai_automations_register' ) ) :

	function fw_ext_crm_ai_automations_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_CRM_Automations' ) ) {
			return;
		}

		fw_ai_register_ability( 'crm-automations', array(
			'label'       => __( 'Describe the CRM automations', 'fw' ),
			'description' => 'The automations (id, title, status draft / active / paused, trigger, re-entry rule, steps described in plain words, how many contacts are inside now and how many finished), the ready-made recipes (id, title, description, whether they can be used here), and the catalogue of triggers and actions available on this site with their settings keys. Counts only, never people.',
			'permission'  => 'manage_options',
			'readonly'    => true,
			'execute'     => 'fw_ext_crm_ai_automations',
		) );

		fw_ai_register_ability( 'crm-save-automation', array(
			'label'       => __( 'Create or update a draft automation', 'fw' ),
			'description' => 'Creates (or, with id or a matching title, updates) an automation as a DRAFT; it never switches it on, so the owner reads it and switches it on in CRM > Automations. Either { recipe: "<recipe id>", title? } to start from a ready-made recipe, or { title, trigger_type, trigger_settings?, settings? { reentry: once | after_finish | always, exit: { match: any | all, rules: [ { field, op, value } ] } }, steps }. steps: [ { type: action, action: "<action slug>", settings: {…} } | { type: wait, settings: { mode: delay, amount, unit: minutes | hours | days | weeks } } | { type: condition, settings: { conditions: { match, rules } }, branches: { yes: [steps], no: [steps] } } | { type: split, settings: { percent }, branches: { a: [], b: [] } } | { type: goal, settings: { trigger, match: {} } } ]. Step ids are made when left out. Emails go in crm.send_email settings { subject, body, purpose: marketing | transactional }, written in the owner\'s voice. Get the slugs from crm-automations first. Not undoable with undo_change; delete it on CRM > Automations.',
			'input'       => array(
				'id'               => array( 'type' => 'integer' ),
				'recipe'           => array( 'type' => 'string' ),
				'title'            => array( 'type' => 'string' ),
				'trigger_type'     => array( 'type' => 'string' ),
				'trigger_settings' => array( 'type' => 'object' ),
				'settings'         => array( 'type' => 'object' ),
				'steps'            => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_save_automation',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_crm_ai_automations_register' );

	/**
	 * A step tree in plain words.
	 *
	 * @param array $steps
	 *
	 * @return array
	 */
	function fw_ext_crm_ai_describe_steps( array $steps ) {
		$out = array();
		foreach ( $steps as $s ) {
			$line = array( 'id' => (string) $s['id'], 'step' => FW_CRM_Automations::step_label( $s ) );
			if ( 'action' === $s['type'] && ! empty( $s['settings'] ) ) {
				$line['settings'] = $s['settings'];
			}
			if ( 'wait' === $s['type'] ) {
				$line['settings'] = $s['settings'];
			}
			foreach ( (array) ( $s['branches'] ?? array() ) as $k => $b ) {
				$line['branches'][ $k ] = fw_ext_crm_ai_describe_steps( (array) $b );
			}
			$out[] = $line;
		}
		return $out;
	}

	/** @return array */
	function fw_ext_crm_ai_automations() {
		$out = array( 'automations' => array(), 'recipes' => array(), 'triggers' => array(), 'actions' => array() );
		foreach ( FW_CRM_Automations::all() as $a ) {
			$sum                    = FW_CRM_Run_Log::summary( (int) $a->id );
			$out['automations'][]   = array(
				'id'               => (int) $a->id,
				'title'            => (string) $a->title,
				'status'           => (string) $a->status,
				'trigger'          => (string) $a->trigger_type,
				'trigger_settings' => $a->trigger_settings,
				'reentry'          => (string) $a->settings['reentry'],
				'steps'            => fw_ext_crm_ai_describe_steps( FW_CRM_Automations::steps( (int) $a->id ) ),
				'inside_now'       => $sum['inside'],
				'finished'         => $sum['succeeded'] + $sum['partly'],
			);
		}
		foreach ( FW_CRM_Automations::recipes() as $r ) {
			$out['recipes'][] = array( 'id' => $r['id'], 'title' => $r['title'], 'description' => $r['description'], 'available' => (bool) $r['available'] );
		}
		foreach ( FW_CRM_Automations::triggers() as $slug => $t ) {
			$out['triggers'][ $slug ] = array( 'label' => (string) $t['label'], 'settings' => array_map( function ( $f ) {
				return array( 'label' => (string) ( $f['label'] ?? '' ), 'type' => (string) ( $f['type'] ?? 'text' ), 'options' => is_array( $f['options'] ?? null ) ? $f['options'] : null );
			}, (array) ( $t['fields'] ?? array() ) ) );
		}
		foreach ( FW_CRM_Automations::actions() as $slug => $a ) {
			$out['actions'][ $slug ] = array( 'label' => (string) $a['label'], 'settings' => array_map( function ( $f ) {
				return array( 'label' => (string) ( $f['label'] ?? '' ), 'type' => (string) ( $f['type'] ?? 'text' ), 'required' => ! empty( $f['required'] ), 'options' => is_array( $f['options'] ?? null ) ? $f['options'] : null );
			}, (array) ( $a['settings'] ?? array() ) ) );
		}
		$out['conditions'] = array( 'fields' => array_map( function ( $f ) {
			return array( 'label' => $f['label'], 'kind' => $f['kind'] );
		}, FW_CRM_Conditions::fields() ), 'ops' => array_keys( FW_CRM_Conditions::ops() ) );
		return $out;
	}

	/**
	 * Give every step (and branch step) an id.
	 *
	 * @param array $steps
	 *
	 * @return array
	 */
	function fw_ext_crm_ai_step_ids( array $steps ) {
		foreach ( $steps as $i => $s ) {
			$steps[ $i ]['id'] = ! empty( $s['id'] ) ? $s['id'] : 's' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$steps[ $i ]['type'] = $s['type'] ?? 'action';
			foreach ( (array) ( $s['branches'] ?? array() ) as $k => $b ) {
				$steps[ $i ]['branches'][ $k ] = fw_ext_crm_ai_step_ids( (array) $b );
			}
		}
		return $steps;
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_save_automation( $in ) {
		if ( ! empty( $in['recipe'] ) ) {
			$data = FW_CRM_Automations::recipe( sanitize_key( (string) $in['recipe'] ) );
			if ( ! $data ) {
				return new WP_Error( 'fw_crm_ai_recipe', 'Unknown recipe, or it needs a module that is switched off. See crm-automations.' );
			}
			if ( ! empty( $in['title'] ) ) {
				$data['title'] = (string) $in['title'];
			}
		} else {
			$data = array_intersect_key( $in, array_flip( array( 'title', 'trigger_type', 'trigger_settings', 'settings', 'steps' ) ) );
			if ( isset( $data['steps'] ) ) {
				$data['steps'] = fw_ext_crm_ai_step_ids( (array) $data['steps'] );
				// Unknown actions are dropped by the cleaner; say so instead of silently losing them.
				$unknown = array();
				array_walk_recursive( $data['steps'], function ( $v, $k ) use ( &$unknown ) {
					if ( 'action' === $k && ! isset( FW_CRM_Automations::actions()[ $v ] ) ) {
						$unknown[] = $v;
					}
				} );
				if ( $unknown ) {
					return new WP_Error( 'fw_crm_ai_action', 'Unknown action(s): ' . implode( ', ', array_unique( $unknown ) ) . '. See crm-automations for the actions available here.' );
				}
			}
		}
		// Match an existing automation by id, else by title.
		$id = (int) ( $in['id'] ?? 0 );
		if ( ! $id && ! empty( $data['title'] ) ) {
			foreach ( FW_CRM_Automations::all() as $a ) {
				if ( 0 === strcasecmp( $a->title, (string) $data['title'] ) ) {
					$id = (int) $a->id;
					break;
				}
			}
		}
		if ( $id ) {
			$existing = FW_CRM_Automations::find( $id );
			if ( ! $existing ) {
				return new WP_Error( 'fw_crm_not_found', 'Automation not found.' );
			}
			$data = array_merge( array(
				'title'            => $existing->title,
				'trigger_type'     => $existing->trigger_type,
				'trigger_settings' => $existing->trigger_settings,
				'settings'         => $existing->settings,
			), $data );
		}
		$data['id'] = $id;
		$res        = FW_CRM_Automations::save( $data );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$a = FW_CRM_Automations::find( $res );
		return array(
			'ok'            => true,
			'message'       => ( $id ? 'Updated' : 'Created' ) . ' the automation "' . $a->title . '" (' . $a->status . '). ' . ( 'active' === $a->status ? 'It is on: your changes apply to people who enter from now on.' : 'It is a draft: the owner switches it on in CRM > Automations after reading it.' ),
			'automation_id' => (int) $res,
			'status'        => (string) $a->status,
			'steps'         => fw_ext_crm_ai_describe_steps( FW_CRM_Automations::steps( (int) $res ) ),
		);
	}

endif;
