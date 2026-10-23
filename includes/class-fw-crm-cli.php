<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

// The framework loads every file in includes/ by itself; the command is registered only under WP-CLI,
// and only once (the extension also requires this file). A class declared inside a condition is not
// hoisted, which is what makes the class_exists() check below meaningful.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || class_exists( 'FW_CRM_CLI', false ) ) {
	return;
}

/**
 * WP-CLI commands for the CRM's background work. On a VPS, run these from the server's cron
 * instead of relying on WordPress's visit-triggered scheduler:
 *
 *     * * * * * cd /path/to/site && wp crm queue run --quiet && wp crm automations run --quiet
 */
if ( ! class_exists( 'FW_CRM_CLI', false ) ) :
class FW_CRM_CLI {

	/**
	 * Send the next batches of queued campaigns.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crm queue run
	 *
	 * @subcommand queue
	 */
	public function queue( $args, $assoc ) {
		if ( ( $args[0] ?? 'run' ) !== 'run' ) {
			WP_CLI::error( 'Usage: wp crm queue run' );
		}
		if ( ! class_exists( 'FW_CRM_Sender' ) ) {
			WP_CLI::error( 'Email marketing is switched off (CRM → Modules).' );
		}
		$sender = new FW_CRM_Sender();
		$r      = $sender->run();
		WP_CLI::success( sprintf( 'Campaigns: %d started, %d sent, %d failed, %d skipped, %d finished.', $r['started'], $r['sent'], $r['failed'], $r['skipped'], $r['finished'] ) );
	}

	/**
	 * Automations: run what is due, show the scheduler's health, list them, or test one.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : run | status | list | test | dates
	 *
	 * [<automation>]
	 * : For test: the automation id.
	 *
	 * [<contact>]
	 * : For test: the contact id.
	 *
	 * [--budget=<seconds>]
	 * : For run: how long to keep going. Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crm automations run
	 *     wp crm automations status
	 *     wp crm automations test 3 42
	 */
	public function automations( $args, $assoc ) {
		if ( ! class_exists( 'FW_CRM_Automations' ) ) {
			WP_CLI::error( 'Automations are switched off (CRM → Modules).' );
		}
		switch ( $args[0] ?? 'run' ) {
			case 'run':
				$n = FW_CRM_Runner::tick( max( 1, (int) ( $assoc['budget'] ?? 50 ) ) );
				WP_CLI::success( sprintf( '%d automation runs processed.', $n ) );
				break;
			case 'dates':
				FW_CRM_Runner::daily();
				WP_CLI::success( 'Date triggers checked.' );
				break;
			case 'status':
				$s = FW_CRM_Cron_Check::status();
				WP_CLI::log( $s['message'] );
				foreach ( FW_CRM_Automations::all() as $a ) {
					$sum = FW_CRM_Run_Log::summary( (int) $a->id );
					WP_CLI::log( sprintf( '#%d %s [%s] inside %d, done %d, partly %d, failed %d, left %d', $a->id, $a->title, $a->status, $sum['inside'], $sum['succeeded'], $sum['partly'], $sum['failed'], $sum['exited'] ) );
				}
				break;
			case 'list':
				$rows = array();
				foreach ( FW_CRM_Automations::all() as $a ) {
					$rows[] = array( 'id' => $a->id, 'title' => $a->title, 'status' => $a->status, 'trigger' => $a->trigger_type, 'version' => $a->version );
				}
				WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'title', 'status', 'trigger', 'version' ) );
				break;
			case 'test':
				$log = FW_CRM_Automation_Guard::test_run( (int) ( $args[1] ?? 0 ), (int) ( $args[2] ?? 0 ) );
				if ( is_wp_error( $log ) ) {
					WP_CLI::error( $log->get_error_message() );
				}
				foreach ( $log as $l ) {
					WP_CLI::log( sprintf( '[%s] %s', $l->status, $l->message ) );
				}
				break;
			default:
				WP_CLI::error( 'Unknown command. Use run, status, list, test or dates.' );
		}
	}
}

endif;

WP_CLI::add_command( 'crm', 'FW_CRM_CLI' );
