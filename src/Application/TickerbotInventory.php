<?php
/**
 * Explicit workspace inventory for future Tickerbot refresh dispatch.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotQuote;
use GainerInteractive\IGTradingJournal\Domain\Decimal;
use GainerInteractive\IGTradingJournal\Domain\TickerbotSchedule;

/** Read-only inventory; never discovers symbols or sends portfolio data. */
final class TickerbotInventory {
	/**
	 * Load every eligible asset, independent of loaded-page UI filters.
	 *
	 * Watchlists are an explicit selection, not inferred from buy/sell/hold labels.
	 * Only individually opted-in trades still in planned state are included.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Authorizing owner.
	 * @param int      $workspace Workspace.
	 * @param array    $watchlists Explicit enabled watchlist IDs.
	 * @param int      $interval Elapsed refresh seconds.
	 * @param array    $trades Explicit planned-trade IDs.
	 * @return array Inventory and conservative normal-cycle projection.
	 * @throws \InvalidArgumentException On invalid scope selection.
	 * @throws \RuntimeException When inventory exceeds the supported bound.
	 */
	public static function load( Database $db, int $actor, int $workspace, array $watchlists = array(), int $interval = 7200, array $trades = array() ): array {
		$tracker = new Tracker( $db, $actor, 'tickerbot-inventory' );
		$tracker->authorize( $workspace, 'tgit_manage_members' );
		TickerbotSchedule::projected( 0, $interval );
		if ( count( $watchlists ) > 10000 || count( $trades ) > 10000 ) {
			throw new \InvalidArgumentException( 'Too many enabled watchlists.' );
		}
		foreach ( array( $watchlists, $trades ) as $ids ) {
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 ) {
					throw new \InvalidArgumentException( 'Inventory selections require positive integer IDs.' );
				}
			}
		}
		$eligible = array();
		$cursor   = 0;
		do {
			$page = $tracker->holdings( $workspace, $cursor, 100 );
			foreach ( $page['items'] as $position ) {
				if ( Decimal::compare( $position['quantity'], '0' ) > 0 ) {
					$eligible[ (int) $position['asset_id'] ]['holding'] = true;
				}
			}
			$cursor = null === $page['next_cursor'] ? 0 : (int) $page['next_cursor'];
		} while ( $cursor );
		foreach ( array_unique( $watchlists ) as $watchlist ) {
			if ( ! is_int( $watchlist ) || $watchlist < 1 ) {
				throw new \InvalidArgumentException( 'Enabled watchlists require positive integer IDs.' );
			}
			$db->object( 'watchlists', $workspace, $watchlist );
			$cursor = 0;
			do {
				$items = $tracker->watchlist_items( $workspace, $watchlist, $cursor, 100 );
				foreach ( $items as $item ) {
					$eligible[ (int) $item['asset_id'] ]['watchlist'] = true;
				}
				$cursor = count( $items ) === 100 ? (int) end( $items )['id'] : 0;
			} while ( $cursor );
		}
		foreach ( array_unique( $trades ) as $id ) {
			$trade = $db->object( 'trades', $workspace, $id );
			if ( 'planned' === $trade['state'] ) {
				$eligible[ (int) $trade['asset_id'] ]['planned_trade'] = true;
			}
		}
		if ( count( $eligible ) > 10000 ) {
			throw new \RuntimeException( 'Tickerbot inventory exceeds the supported scope; no partial refresh is permitted.' );
		}
		ksort( $eligible, SORT_NUMERIC );
		$rows = array();
		foreach ( $eligible as $id => $origins ) {
			$asset   = $db->object( 'assets', $workspace, $id );
			$mapping = $db->row( 'SELECT * FROM ' . $db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $id ) );
			$rows[]  = self::entry( $asset, $mapping, array_keys( $origins ) );
		}
		return self::plan( $rows, $interval );
	}

	/**
	 * Batch canonical symbols once while retaining each local instrument.
	 *
	 * @param array $rows Inventory entries.
	 * @param int   $interval Elapsed seconds.
	 * @return array Complete local plan; retries/discovery are additional usage.
	 */
	public static function plan( array $rows, int $interval = 7200 ): array {
		$symbols = array();
		$planned = false;
		foreach ( $rows as $row ) {
			$planned = $planned || in_array( 'planned_trade', $row['origins'] ?? array(), true );
			if ( 'mapped' === $row['state'] ) {
				$symbols[] = $row['provider_symbol'];
			}
		}
		$symbols = array_values( array_unique( $symbols ) );
		sort( $symbols, SORT_STRING );
		return array(
			'items'                      => $rows,
			'symbols'                    => $symbols,
			'batches'                    => array_chunk( $symbols, 50 ),
			'projected_monthly_attempts' => TickerbotSchedule::projected( count( $symbols ), $interval ),
			'complete'                   => true,
			'planned_trades_included'    => $planned,
		);
	}

	/**
	 * Describe a local mapping without guessing identity or exposing quantities.
	 *
	 * @param array      $asset Local identity.
	 * @param array|null $mapping Latest verified mapping.
	 * @param array      $origins Eligibility reasons.
	 * @return array Safe inventory entry.
	 */
	public static function entry( array $asset, ?array $mapping, array $origins ): array {
		$supported = in_array( $asset['asset_class'], array( 'stock', 'etf', 'crypto' ), true ) && ( 'crypto' !== $asset['asset_class'] || 'USD' === $asset['quote_currency'] );
		$current   = $mapping && (int) $mapping['workspace_id'] === (int) $asset['workspace_id'] && (int) $mapping['asset_id'] === (int) $asset['id'] && $mapping['asset_class'] === $asset['asset_class'] && $mapping['exchange'] === $asset['exchange'] && $mapping['currency'] === $asset['quote_currency'];
		if ( $current && 'crypto' === $asset['asset_class'] && 'X:' . $asset['symbol'] . 'USD' !== $mapping['provider_symbol'] ) {
			$current = false;
		}
		if ( $current ) {
			try {
				TickerbotQuote::symbol( $mapping['provider_symbol'] );
			} catch ( \InvalidArgumentException $error ) {
				$current = false;
			}
		}
		return array(
			'asset_id'        => (int) $asset['id'],
			'symbol'          => $asset['symbol'],
			'asset_class'     => $asset['asset_class'],
			'currency'        => $asset['quote_currency'],
			'origins'         => $origins,
			'state'           => ! $supported ? 'unsupported' : ( $current ? 'mapped' : 'needs_mapping' ),
			'mapping_id'      => $supported && $current ? (int) $mapping['id'] : null,
			'provider_symbol' => $supported && $current ? $mapping['provider_symbol'] : null,
		);
	}
}
