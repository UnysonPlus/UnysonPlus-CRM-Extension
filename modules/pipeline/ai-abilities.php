<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for the Sales pipeline module: set up pipelines and their stages.
 * Never reads deals or the people on them (stage counts only).
 *
 *   crm-pipelines       every pipeline with its stages and how many open deals sit in each
 *   crm-save-pipeline   create or update a pipeline, matched by name; stages matched by name
 *
 * Saving never deletes a stage that still holds deals: such a stage is kept and reported.
 * Pipelines live in the CRM's tables, so undo-change cannot restore them; the result says what
 * changed, and CRM > Deals > Edit pipelines can change it back.
 */

if ( ! function_exists( 'fw_ext_crm_ai_pipeline_register' ) ) :

	function fw_ext_crm_ai_pipeline_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_CRM_Pipelines' ) ) {
			return;
		}

		fw_ai_register_ability( 'crm-pipelines', array(
			'label'       => __( 'Describe the sales pipelines', 'fw' ),
			'description' => 'Every sales pipeline: name, currency, whether it is the default, its stages in order (name, win probability %, "stalled after" days, kind open / won / lost) and how many OPEN deals are in each stage. Counts only, never deal names or people.',
			'permission'  => 'manage_options',
			'readonly'    => true,
			'execute'     => 'fw_ext_crm_ai_pipelines',
		) );

		fw_ai_register_ability( 'crm-save-pipeline', array(
			'label'       => __( 'Create or update a sales pipeline', 'fw' ),
			'description' => 'Creates or updates a sales pipeline, matched by name (calling it again is safe). { name, stages: [ { name, probability? (0-100, the chance a deal at this stage is won), rot_days? (flag a deal as stalled after this many days without moving; 0 = never) } ] in order, currency? (3 letters), is_default?, won_name?, lost_name? }. Stages are matched by name, so renaming creates a new stage. A stage left out is removed only when it holds no deals; one that holds deals is kept and reported. Use real stages instead of "Stage: X" tags whenever the Sales pipeline module is on. Not undoable with undo_change; change it on CRM > Deals > Edit pipelines.',
			'input'       => array(
				'name'       => array( 'type' => 'string', 'minLength' => 1 ),
				'stages'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'minItems' => 1 ),
				'currency'   => array( 'type' => 'string' ),
				'is_default' => array( 'type' => 'boolean' ),
				'won_name'   => array( 'type' => 'string' ),
				'lost_name'  => array( 'type' => 'string' ),
			),
			'required'    => array( 'name', 'stages' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_save_pipeline',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_crm_ai_pipeline_register' );

	/** @return array */
	function fw_ext_crm_ai_pipelines() {
		$out = array();
		foreach ( FW_CRM_Pipelines::all() as $p ) {
			$totals = FW_CRM_Deals::totals_by_stage( (int) $p->id, array() );
			$stages = array();
			foreach ( FW_CRM_Pipelines::stages( (int) $p->id ) as $s ) {
				$stages[] = array(
					'id'          => (int) $s->id,
					'name'        => (string) $s->name,
					'kind'        => (string) $s->kind,
					'probability' => (int) $s->probability,
					'rot_days'    => (int) $s->rot_days,
					'open_deals'  => 'open' === $s->kind ? (int) ( $totals[ (int) $s->id ]['count'] ?? 0 ) : null,
				);
			}
			$out[] = array(
				'id'         => (int) $p->id,
				'name'       => (string) $p->name,
				'currency'   => FW_CRM_Pipelines::currency( $p ),
				'is_default' => (bool) $p->is_default,
				'stages'     => $stages,
			);
		}
		return array( 'pipelines' => $out );
	}

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_save_pipeline( $in ) {
		$name = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		$pipe = null;
		foreach ( FW_CRM_Pipelines::all() as $p ) {
			if ( 0 === strcasecmp( $p->name, $name ) ) {
				$pipe = $p;
				break;
			}
		}
		$existing = array();
		$counts   = array();
		$won      = '';
		$lost     = '';
		if ( $pipe ) {
			$counts = FW_CRM_Deals::totals_by_stage( (int) $pipe->id, array() );
			foreach ( FW_CRM_Pipelines::stages( (int) $pipe->id ) as $s ) {
				if ( 'open' === $s->kind ) {
					$existing[ strtolower( $s->name ) ] = $s;
				} elseif ( 'won' === $s->kind ) {
					$won = $s->name;
				} else {
					$lost = $s->name;
				}
			}
		}
		$stages  = array();
		$listed  = array();
		$created = array();
		foreach ( (array) ( $in['stages'] ?? array() ) as $s ) {
			$s     = is_array( $s ) ? $s : array( 'name' => (string) $s );
			$sname = trim( sanitize_text_field( (string) ( $s['name'] ?? '' ) ) );
			if ( '' === $sname || isset( $listed[ strtolower( $sname ) ] ) ) {
				continue;
			}
			$listed[ strtolower( $sname ) ] = true;
			$old      = $existing[ strtolower( $sname ) ] ?? null;
			$stages[] = array(
				'id'          => $old ? (int) $old->id : 0,
				'name'        => $sname,
				'probability' => isset( $s['probability'] ) ? (int) $s['probability'] : ( $old ? (int) $old->probability : 0 ),
				'rot_days'    => isset( $s['rot_days'] ) ? (int) $s['rot_days'] : ( $old ? (int) $old->rot_days : 0 ),
			);
			if ( ! $old ) {
				$created[] = $sname;
			}
		}
		// A stage left out that still holds deals stays (at the end), so no deal is moved by an AI edit.
		$kept    = array();
		$removed = array();
		foreach ( $existing as $key => $s ) {
			if ( isset( $listed[ $key ] ) ) {
				continue;
			}
			if ( (int) ( $counts[ (int) $s->id ]['count'] ?? 0 ) > 0 ) {
				$stages[] = array( 'id' => (int) $s->id, 'name' => $s->name, 'probability' => (int) $s->probability, 'rot_days' => (int) $s->rot_days );
				$kept[]   = $s->name;
			} else {
				$removed[] = $s->name;
			}
		}
		$res = FW_CRM_Pipelines::save( array(
			'name'       => $name,
			'currency'   => (string) ( $in['currency'] ?? ( $pipe ? $pipe->currency : '' ) ),
			'is_default' => ! empty( $in['is_default'] ) || ( $pipe && $pipe->is_default ),
			'won_name'   => (string) ( $in['won_name'] ?? $won ),
			'lost_name'  => (string) ( $in['lost_name'] ?? $lost ),
			'stages'     => $stages,
		), $pipe ? (int) $pipe->id : 0 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'ok'             => true,
			'message'        => ( $pipe ? 'Updated' : 'Created' ) . ' the pipeline "' . $name . '".',
			'pipeline_id'    => (int) $res,
			'stages_created' => $created,
			'stages_removed' => $removed,
			'stages_kept'    => $kept ? array( 'stages' => $kept, 'why' => 'They still hold deals, so they were kept. Move the deals on CRM > Deals, then remove the stage.' ) : array(),
		);
	}

endif;
