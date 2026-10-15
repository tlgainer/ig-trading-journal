<?php
/**
 * Workspace-specific recoverable elapsed-UTC Tickerbot scheduling.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;

/** Explicit enrollments only; no portfolio-wide discovery or implicit key activation. */
final class RecurringTickerbot {
	/**
	 * Register the recovery heartbeat without creating any enrollment or event.
	 *
	 * @param array $schedules WordPress intervals.
	 * @return array Intervals including the scoped recovery interval.
	 */
	public static function intervals( array $schedules ): array {
		$schedules['tgit_minute'] = array(
			'interval' => 60,
			'display'  => 'TGIT recovery every minute',
		);
		return $schedules;
	}

	/**
	 * Separate rollout gate; this is not connection/coverage or host-cron proof.
	 *
	 * @return bool Whether trusted server scheduler execution is explicitly on.
	 */
	private static function enabled(): bool {
		return Installer::ready() && defined( 'TGIT_TICKERBOT_ENABLED' ) && true === TGIT_TICKERBOT_ENABLED && defined( 'TGIT_TICKERBOT_WORKER_ENABLED' ) && true === TGIT_TICKERBOT_WORKER_ENABLED && defined( 'TGIT_TICKERBOT_SCHEDULER_ENABLED' ) && true === TGIT_TICKERBOT_SCHEDULER_ENABLED && defined( 'TGIT_TICKERBOT_ACCOUNT_SCOPE' ) && is_string( TGIT_TICKERBOT_ACCOUNT_SCOPE ) && (bool) preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', TGIT_TICKERBOT_ACCOUNT_SCOPE );
	}

	/**
	 * Read only one workspace's latest original author and validate membership.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array Store, current enrollment.
	 * @throws \DomainException On absent or disabled enrollment.
	 */
	private static function context( int $workspace ): array {
		global $wpdb;
		$db  = new Database( $wpdb );
		$row = $db->row( 'SELECT actor_id FROM ' . $db->table( 'tickerbot_cadences' ) . ' WHERE workspace_id = %d ORDER BY revision DESC LIMIT 1', array( $workspace ) );
		if ( ! $row ) {
			throw new \DomainException( 'No explicit recurring enrollment.' );
		}
		$store   = new TickerbotStore( $db, (int) $row['actor_id'], wp_generate_uuid4(), TGIT_TICKERBOT_ACCOUNT_SCOPE );
		$cadence = $store->cadence( $workspace );
		if ( 1 !== (int) $cadence['enabled'] ) {
			throw new \DomainException( 'Recurring enrollment is disabled.' );
		}
		return array( $store, $cadence );
	}

	/**
	 * Queue recovery only after the owner explicitly saves an enrollment.
	 *
	 * Database commit and queueing are separate; false must remain visible to callers.
	 *
	 * @param int $workspace Workspace ID.
	 * @return bool Whether the workspace recovery event exists.
	 */
	public static function queue( int $workspace ): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		try {
			self::context( $workspace );
			$args = array( $workspace );
			return (bool) wp_next_scheduled( 'tgit_tickerbot_schedule_tick', $args ) || true === wp_schedule_event( time() + 60, 'tgit_minute', 'tgit_tickerbot_schedule_tick', $args, true );
		} catch ( \DomainException $error ) {
			wp_clear_scheduled_hook( 'tgit_tickerbot_schedule_tick', array( $workspace ) );
			return false;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Advance a current enrollment once; missed slots never create catch-up bursts.
	 *
	 * @param int $workspace Workspace ID.
	 * @return void
	 */
	public static function job( int $workspace ): void {
		if ( ! self::enabled() ) {
			return;
		}
		try {
			list( $store, $cadence ) = self::context( $workspace );
			$result                  = $store->cadence_tick( $workspace, (int) $cadence['id'] );
			if ( 'resume' === $result['state'] ) {
				TickerbotRefresh::queue( $workspace, $result['run_id'], time() + 1 );
			} elseif ( 'due' === $result['state'] ) {
				$run = $store->start_run( $workspace, $result['key'], 'scheduled', (int) $cadence['id'] );
				if ( isset( $run['lease_token'] ) ) {
					$store->prepare_dispatch( $workspace, $run['run_id'], $run['lease_token'] );
					TickerbotRefresh::queue( $workspace, $run['run_id'], time() + 1 );
				}
			}
		} catch ( \DomainException $error ) {
			// Explicit opt-out, revoked authors and superseded review stop wakeups.
			wp_clear_scheduled_hook( 'tgit_tickerbot_schedule_tick', array( $workspace ) );
		} catch ( \Throwable $error ) {
			// Do not turn database/queue uncertainty into an uncounted HTTP resend.
			return;
		}
	}
}
