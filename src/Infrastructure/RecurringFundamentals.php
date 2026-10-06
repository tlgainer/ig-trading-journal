<?php
/**
 * Recoverable weekly jobs for explicit owner dataset enrollment.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Domain\FundamentalSchedule;

/** Durable enrollment commits before recoverable cron queueing. */
final class RecurringFundamentals {

	/** Recover only explicitly enrolled datasets when server processing is enabled. */
	public static function boot(): void {
		if ( Installer::ready() && FundamentalRefresh::enabled() && ! wp_next_scheduled( 'tgit_fundamental_schedule_scan' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'tgit_fundamental_schedule_scan' );
		}
	}

	/**
	 * Recheck original owner, mapping and latest dataset revision.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $schedule Enrollment revision.
	 * @return array
	 * @throws \UnexpectedValueException On disabled or obsolete enrollment.
	 */
	private static function authorized( int $workspace, int $schedule ): array {
		global $wpdb;
		$db      = new Database( $wpdb );
		$row     = $db->object( 'fundamental_schedules', $workspace, $schedule );
		$service = new MarketData( $db, (int) $row['actor_id'], wp_generate_uuid4() );
		$current = $service->fundamental_schedule_config( $workspace, (int) $row['mapping_id'], $row['dataset'] );
		if ( (int) ( $current['id'] ?? 0 ) !== $schedule || 'weekly' !== $row['frequency'] ) {
			throw new \UnexpectedValueException( 'Fundamental enrollment is superseded or disabled.' );
		}
		$mapping = $service->current_mapping( $workspace, (int) $row['mapping_id'] );
		if ( 'alpha_vantage' !== $mapping['provider'] ) {
			throw new \UnexpectedValueException( 'Fundamental mapping is incompatible.' );
		}
		return array( $row, $mapping );
	}

	/**
	 * Queue one future weekly slot, never a catch-up request.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $schedule Enrollment revision.
	 * @return bool Whether the next event exists.
	 */
	public static function queue( int $workspace, int $schedule ): bool {
		if ( ! Installer::ready() || ! FundamentalRefresh::enabled() ) {
			return false;
		}
		try {
			list( $row ) = self::authorized( $workspace, $schedule );
			$at          = FundamentalSchedule::next( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $row['frequency'], (int) $row['weekday'] );
			$args        = array( $workspace, $schedule, $at );
			return (bool) wp_next_scheduled( 'tgit_scheduled_fundamental_refresh', $args ) || true === wp_schedule_single_event( $at, 'tgit_scheduled_fundamental_refresh', $args, true );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Process one original dataset slot within two hours; queue the next future slot.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $schedule Enrollment revision.
	 * @param int $timestamp Original UTC slot.
	 * @return void
	 */
	public static function job( int $workspace, int $schedule, int $timestamp ): void {
		if ( ! Installer::ready() || ! FundamentalRefresh::enabled() || $timestamp > time() ) {
			return;
		}
		try {
			list( $row, $mapping ) = self::authorized( $workspace, $schedule );
			if ( time() - $timestamp <= 7200 ) {
				$result = FundamentalRefresh::run( $workspace, (int) $row['actor_id'], (int) $mapping['id'], $row['dataset'], 'fundamental-recurring:' . $schedule . ':' . $timestamp );
				do_action( 'tgit_fundamental_refresh_status', $workspace, (int) $mapping['id'], $row['dataset'], $result['state'] );
			}
			self::queue( $workspace, $schedule );
		} catch ( \Throwable $error ) {
			// Obsolete mappings and revoked owners never authorize new provider requests.
			return;
		}
	}

	/** Enumerate durable latest enrollment; queue reauthorizes every workspace. */
	public static function scan(): void {
		if ( ! Installer::ready() || ! FundamentalRefresh::enabled() ) {
			return;
		}
		global $wpdb;
		$db    = new Database( $wpdb );
		$table = $db->table( 'fundamental_schedules' );
		$after = 0;
		do {
			$rows = $db->rows( 'SELECT s.id, s.workspace_id FROM ' . $table . " s WHERE s.id > %d AND s.frequency = 'weekly' AND NOT EXISTS (SELECT newer.id FROM " . $table . ' newer WHERE newer.workspace_id = s.workspace_id AND newer.mapping_id = s.mapping_id AND newer.dataset = s.dataset AND newer.id > s.id) ORDER BY s.id LIMIT 100', array( $after ) );
			foreach ( $rows as $row ) {
				self::queue( (int) $row['workspace_id'], (int) $row['id'] );
				$after = (int) $row['id'];
			}
			$more = count( $rows ) === 100;
		} while ( $more );
	}
}
