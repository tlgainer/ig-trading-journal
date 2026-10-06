<?php
/**
 * Exact open-stock totals with explicit coverage and currency separation.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Never turns a missing value into a zero-valued position. */
final class StockTotals {
	/**
	 * Group complete values and covered subtotals for open stock positions only.
	 *
	 * @param array $positions Authorized valued positions.
	 * @return array
	 */
	public static function calculate( array $positions ): array {
		$groups = array();
		foreach ( $positions as $position ) {
			if ( 'stock' !== $position['asset_class'] || Decimal::compare( $position['quantity'], '0' ) <= 0 ) {
				continue;
			}
			$currency = $position['currency'];
			if ( ! isset( $groups[ $currency ] ) ) {
				$groups[ $currency ] = array(
					'currency'                => $currency,
					'positions'               => 0,
					'priced_positions'        => 0,
					'gain_positions'          => 0,
					'covered_market_value'    => '0',
					'covered_unrealized_gain' => '0',
					'session_dates'           => array(),
				);
			}
			++$groups[ $currency ]['positions'];
			if ( 'complete' !== $position['price_status'] || null === $position['market_value'] ) {
				continue;
			}
			++$groups[ $currency ]['priced_positions'];
			$date = $position['price_observation']['effective_date'] ?? null;
			if ( is_string( $date ) && ! in_array( $date, $groups[ $currency ]['session_dates'], true ) ) {
				$groups[ $currency ]['session_dates'][] = $date;
			}
			$groups[ $currency ]['covered_market_value'] = Decimal::add( $groups[ $currency ]['covered_market_value'], $position['market_value'] );
			if ( null !== $position['unrealized_gain'] ) {
				++$groups[ $currency ]['gain_positions'];
				$groups[ $currency ]['covered_unrealized_gain'] = Decimal::add( $groups[ $currency ]['covered_unrealized_gain'], $position['unrealized_gain'] );
			}
		}
		ksort( $groups, SORT_STRING );
		foreach ( $groups as &$group ) {
			sort( $group['session_dates'], SORT_STRING );
			$coherent                 = count( $group['session_dates'] ) <= 1;
			$group['market_value']    = $coherent && $group['priced_positions'] === $group['positions'] ? $group['covered_market_value'] : null;
			$group['unrealized_gain'] = $coherent && $group['gain_positions'] === $group['positions'] ? $group['covered_unrealized_gain'] : null;
			$group['status']          = $coherent && $group['gain_positions'] === $group['positions'] ? 'complete' : 'partial';
			if ( 0 === $group['priced_positions'] ) {
				$group['covered_market_value'] = null;
			}
			if ( 0 === $group['gain_positions'] ) {
				$group['covered_unrealized_gain'] = null;
			}
		}
		unset( $group );
		return array_values( $groups );
	}
}
