<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Random split: sends each contact down path A or path B, by percentage, to compare two
 * follow-ups (two subject lines, a discount against none).
 */
class FW_CRM_Action_Split {

	/**
	 * @param array    $s    { percent: share of contacts taking path A, 1-99, default 50 }
	 * @param int|null $roll 1-100, for tests.
	 *
	 * @return string a|b
	 */
	public static function choose( array $s, $roll = null ) {
		$pct  = max( 1, min( 99, (int) ( $s['percent'] ?? 50 ) ) );
		$roll = null === $roll ? wp_rand( 1, 100 ) : (int) $roll;
		return $roll <= $pct ? 'a' : 'b';
	}
}
