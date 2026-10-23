<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant ability for Lead scoring: set up the rules, named scores and fading.
 *
 *   crm-scoring   read (no input) or change the scoring set-up; changes are undoable with undo_change
 *
 * Never reads anyone's score. Site activity (recording pages known contacts visit) is a privacy
 * decision, so it is reported but deliberately NOT switchable here.
 */

if ( ! function_exists( 'fw_ext_crm_ai_scoring_register' ) ) :

	function fw_ext_crm_ai_scoring_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_CRM_Scoring' ) ) {
			return;
		}
		fw_ai_register_ability( 'crm-scoring', array(
			'label'       => __( 'Set up lead scoring', 'fw' ),
			'description' => 'Reads or changes the lead scoring set-up. With no input it only describes it: named scores, rules, fading, the rule events available and whether site activity is on. To change it, give any of: scores ({ key: label } besides the main Score, e.g. { "product_x": "Product X" }), rules (the WHOLE new list: [ { event, match?, points (negative to take away), score? (key, default "default"), limit? (times per contact, 0 = no limit), label? } ]; event is one of page_view (match = address contains), email_open / email_click (match = campaign id), form_submitted (match = form id), purchase (match = minimum total), login, tag_added (match = tag slug), field (match = "field = value"), or event:<custom name>), decay ({ on, percent, days }). A sensible start: +5 email_open, +10 email_click, +15 page_view "/pricing" (limit 2), +30 form_submitted, -10 tag_added "not-interested". Points count from now on. Site activity is a privacy choice for the owner: it is never switched here. Undo with undo_change.',
			'input'       => array(
				'scores' => array( 'type' => 'object' ),
				'rules'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'decay'  => array( 'type' => 'object' ),
			),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_crm_ai_scoring',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_crm_ai_scoring_register' );

	/**
	 * @param array $in
	 *
	 * @return array|WP_Error
	 */
	function fw_ext_crm_ai_scoring( $in ) {
		$in      = is_array( $in ) ? $in : array();
		$changes = array_intersect_key( $in, array_flip( array( 'scores', 'rules', 'decay' ) ) );
		$rev     = 0;
		if ( $changes ) {
			$s = FW_CRM_Scoring::settings();
			if ( isset( $changes['scores'] ) ) {
				$s['scores'] = array_merge( array( 'default' => $s['scores']['default'] ), (array) $changes['scores'] );
			}
			if ( isset( $changes['rules'] ) ) {
				$known   = array_keys( FW_CRM_Scoring::rule_events() );
				$invalid = array();
				foreach ( (array) $changes['rules'] as $r ) {
					$ev = (string) ( $r['event'] ?? '' );
					if ( ! in_array( $ev, $known, true ) && 0 !== strpos( $ev, 'event:' ) || 'event:' === $ev || ! (int) ( $r['points'] ?? 0 ) ) {
						$invalid[] = $ev;
					}
				}
				if ( $invalid ) {
					return new WP_Error( 'fw_crm_ai_rule', 'These rules are not valid (unknown event, or no points): ' . implode( ', ', $invalid ) . '. Nothing was changed.' );
				}
				$s['rules'] = (array) $changes['rules'];
			}
			if ( isset( $changes['decay'] ) ) {
				$s['decay'] = array_merge( $s['decay'], (array) $changes['decay'] );
			}
			$rev = ! function_exists( 'fw_ai_snapshot' ) ? 0 : fw_ai_snapshot( array( 'options' => array( FW_CRM_Scoring::OPTION ) ), 'unysonplus/crm-scoring', 'Changed lead scoring: ' . implode( ', ', array_keys( $changes ) ) );
			FW_CRM_Scoring::save_settings( $s );
		}
		$s     = FW_CRM_Scoring::settings();
		$rules = array_map( function ( $r ) {
			return array_merge( $r, array( 'reads' => FW_CRM_Scoring::describe( $r ) ) );
		}, $s['rules'] );
		$out   = array(
			'scores'        => $s['scores'],
			'rules'         => $rules,
			'decay'         => $s['decay'],
			'rule_events'   => array_map( function ( $e ) {
				return $e['label'] . ( '' !== $e['match'] ? ' — match: ' . $e['match'] : '' );
			}, FW_CRM_Scoring::rule_events() ),
			'site_activity' => array( 'on' => (bool) FW_CRM_Site_Activity::settings()['on'], 'note' => 'A privacy choice the owner makes on CRM > Lead scoring.' ),
		);
		if ( $changes ) {
			$out = array_merge( array( 'ok' => true, 'message' => 'Lead scoring updated: ' . implode( ', ', array_keys( $changes ) ) . '. Points count from now on.', 'revision' => $rev ), $out );
		}
		return $out;
	}

endif;
