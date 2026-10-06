<?php
/**
 * Recoverable recurring jobs for explicit owner enrollment.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Domain\QuoteSchedule;

/** Configuration commits before cron writes; the hourly scan repairs missed queueing. */
final class RecurringQuotes {
	/** Ensure the recovery scan exists only when server processing is explicitly enabled. */
	public static function boot(): void {
		if ( Installer::ready() && defined( 'TGIT_MARKET_DATA_ENABLED' ) && true === TGIT_MARKET_DATA_ENABLED && ! wp_next_scheduled( 'tgit_quote_schedule_scan' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'tgit_quote_schedule_scan' );
		}
	}

	/**
	 * Queue the next slot only for the latest authorized enrollment.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $schedule Schedule revision identifier.
	 * @return bool Whether a compatible next job exists.
	 */
	public static function queue( int $workspace, int $schedule ): bool {
		if ( ! Installer::ready() ) {
			return false;
		}
		try {
			list( $row, $mapping ) = self::authorized( $workspace, $schedule );
			if ( ! QuoteRefresh::enabled( $mapping['provider'] ) ) {
				return false;
			}
			$at   = QuoteSchedule::next( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $row['frequency'] );
			$args = array( $workspace, $schedule, $at );
			return (bool) wp_next_scheduled( 'tgit_scheduled_quote_refresh', $args ) || true === wp_schedule_single_event( $at, 'tgit_scheduled_quote_refresh', $args, true );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Recheck original owner, current mapping and latest enrollment before processing.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $schedule Schedule revision identifier.
	 * @return array
	 * @throws \UnexpectedValueException On superseded or disabled enrollment.
	 */
	private static function authorized( int $workspace, int $schedule ): array {
		global $wpdb;
		$db      = new Database( $wpdb );
		$row     = $db->object( 'provider_schedules', $workspace, $schedule );
		$service = new MarketData( $db, (int) $row['actor_id'], wp_generate_uuid4() );
		$current = $service->schedule_config( $workspace, (int) $row['mapping_id'] );
		if ( (int) ( $current['id'] ?? 0 ) !== $schedule || 'off' === $row['frequency'] ) {
			throw new \UnexpectedValueException( 'Recurring enrollment is superseded or disabled.' );
		}
		return array( $row, $service->current_mapping( $workspace, (int) $row['mapping_id'] ) );
	}

	/**
	 * Execute an original slot once and queue the next future slot without catch-up bursts.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $schedule Schedule revision identifier.
	 * @param int $timestamp Original UTC slot.
	 * @return void
	 */
	public static function job( int $workspace, int $schedule, int $timestamp ): void {
		if ( ! Installer::ready() || $timestamp > time() ) {
			return;
		}
		try {
			list( $row, $mapping ) = self::authorized( $workspace, $schedule );
			// A delayed site cron skips missed slots rather than issuing catch-up requests.
			if ( time() - $timestamp <= 7200 ) {
				$result = QuoteRefresh::run( $workspace, (int) $row['actor_id'], (int) $mapping['id'], 'recurring:' . $schedule . ':' . $timestamp );
				do_action( 'tgit_quote_refresh_status', $workspace, (int) $mapping['id'], $result['state'] );
			}
			self::queue( $workspace, $schedule );
		} catch ( \Throwable $error ) {
			// Revoked owners and obsolete configurations never authorize new requests.
			return;
		}
	}

	/** Recover jobs from durable explicit enrollment, never from holdings or API keys. */
	public static function scan(): void {
		if ( ! Installer::ready() || ! defined( 'TGIT_MARKET_DATA_ENABLED' ) || true !== TGIT_MARKET_DATA_ENABLED ) {
			return;
		}
		global $wpdb;
		$db    = new Database( $wpdb );
		$table = $db->table( 'provider_schedules' );
		$after = 0;
		do {
			// System scheduler enumerates enrollment identifiers; queue reauthorizes each workspace.
			$rows = $db->rows( 'SELECT s.id, s.workspace_id FROM ' . $table . " s WHERE s.id > %d AND s.frequency <> 'off' AND NOT EXISTS (SELECT newer.id FROM " . $table . ' newer WHERE newer.workspace_id = s.workspace_id AND newer.mapping_id = s.mapping_id AND newer.id > s.id) ORDER BY s.id LIMIT 100', array( $after ) );
			foreach ( $rows as $row ) {
				self::queue( (int) $row['workspace_id'], (int) $row['id'] );
				$after = (int) $row['id'];
			}
			$more = count( $rows ) === 100;
		} while ( $more );
	}
}
