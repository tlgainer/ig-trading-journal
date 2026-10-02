<?php
/**
 * Workspace-authorized bounded retention jobs.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\Media;

/** WordPress cron schedules carry workspace and explicit authorizing owner. */
final class MediaJobs {
	/** Schedule cleanup only after an owner saves media policy.
	 *
	 * @param int $workspace Workspace.
	 * @param int $actor Authorizing owner.
	 * @return void
	 */
	public static function schedule( int $workspace, int $actor ): void {
		$args = array( $workspace, $actor );
		if ( ! wp_next_scheduled( 'tgit_media_cleanup', $args ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tgit_media_cleanup', $args );
		}
	}
	/** Run a bounded cleanup; current membership is checked by the service.
	 *
	 * @param int $workspace Workspace.
	 * @param int $actor Authorizing owner.
	 * @return void
	 */
	public static function cleanup( int $workspace, int $actor ): void {
		if ( ! Installer::ready() ) {
			return;
		}
		global $wpdb;
		try {
			$service = new Media( new Database( $wpdb ), $actor, wp_generate_uuid4() );
			$result  = $service->cleanup( $workspace, wp_generate_uuid4() );
			if ( 100 === $result['purged'] ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'tgit_media_cleanup', array( $workspace, $actor ) );
			}
		} catch ( \Throwable $error ) {
			// Do not bypass a revoked owner's permission or expose private paths in cron diagnostics.
			do_action( 'tgit_media_cleanup_failed', $workspace, $actor );
		}
	}
}
