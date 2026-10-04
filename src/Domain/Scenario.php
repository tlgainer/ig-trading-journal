<?php
/**
 * Exact, non-posting investment scenarios.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Pure calculator outputs; no ledger or WordPress dependency. */
final class Scenario {
	public const VERSION = 'scenario-native-1';

	/**
	 * Calculate units, gross value and fee-aware profit for a crypto scenario.
	 *
	 * @param string $buy_price Buy price per unit.
	 * @param string $sell_price Proposed sell or current price per unit.
	 * @param string $investment Purchase principal excluding fees.
	 * @param string $buy_fee Optional purchase fee.
	 * @param string $sell_fee Optional exit fee.
	 * @return array Decimal-string scenario.
	 * @throws \InvalidArgumentException When inputs are invalid.
	 */
	public static function crypto( string $buy_price, string $sell_price, string $investment, string $buy_fee = '0', string $sell_fee = '0' ): array {
		Decimal::input( $buy_price, 18, true );
		Decimal::input( $sell_price, 18, true );
		Decimal::input( $investment, 12, true );
		Decimal::input( $buy_fee, 12 );
		Decimal::input( $sell_fee, 12 );
		$units       = bcdiv( $investment, $buy_price, 18 );
		$gross_value = Decimal::money( Decimal::mul( $units, $sell_price ) );
		$cost        = Decimal::money( Decimal::add( $investment, $buy_fee ) );
		$net_value   = Decimal::money( Decimal::sub( $gross_value, $sell_fee ) );
		$profit      = Decimal::money( Decimal::sub( $net_value, $cost ) );
		return array(
			'calculation_version' => self::VERSION,
			'units'               => $units,
			'position_value'      => $gross_value,
			'net_exit_value'      => $net_value,
			'profit_amount'       => $profit,
			'profit_percentage'   => Decimal::round( Decimal::mul( Decimal::div( $profit, $cost ), '100' ), 12 ),
			'fees_included'       => Decimal::compare( $buy_fee, '0' ) > 0 || Decimal::compare( $sell_fee, '0' ) > 0,
		);
	}

	/**
	 * Size a long position from a positive risk budget and stop distance.
	 *
	 * @param string $entry_price Entry price per unit.
	 * @param string $risk_budget Maximum native-currency loss at stop.
	 * @param string $stop_distance Absolute price distance below entry.
	 * @return array Decimal-string scenario.
	 * @throws \InvalidArgumentException When inputs are invalid.
	 */
	public static function risk( string $entry_price, string $risk_budget, string $stop_distance ): array {
		Decimal::input( $entry_price, 18, true );
		Decimal::input( $risk_budget, 12, true );
		Decimal::input( $stop_distance, 18, true );
		if ( Decimal::compare( $stop_distance, $entry_price ) >= 0 ) {
			throw new \InvalidArgumentException( 'Stop distance must be below entry price.' );
		}
		$units = bcdiv( $risk_budget, $stop_distance, 18 );
		if ( Decimal::compare( $units, '0' ) <= 0 ) {
			throw new \InvalidArgumentException( 'Risk budget is too small for supported unit precision.' );
		}
		return array(
			'calculation_version' => self::VERSION,
			'stop_price'          => bcsub( $entry_price, $stop_distance, 18 ),
			'position_size'       => $units,
			'capital_required'    => Decimal::money( Decimal::mul( $units, $entry_price ) ),
			'risk_used'           => Decimal::money( Decimal::mul( $units, $stop_distance ) ),
		);
	}
}
