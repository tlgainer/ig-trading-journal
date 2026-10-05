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
	 * Model a bought call or put premium sale or expiration cash payoff.
	 *
	 * @param array $input Decimal strings and explicitly selected scenario.
	 * @return array Non-posting scenario; exercise is not modeled.
	 * @throws \InvalidArgumentException When the supported contract is invalid.
	 */
	public static function option( array $input ): array {
		$type = $input['option_type'] ?? '';
		$mode = $input['mode'] ?? '';
		if ( ! in_array( $type, array( 'call', 'put' ), true ) || ! in_array( $mode, array( 'close', 'expiry' ), true ) ) {
			throw new \InvalidArgumentException( 'Select a bought call or put and a close-out or expiration scenario.' );
		}
		$premium    = Decimal::input( $input['entry_premium'] ?? null, 18, true );
		$contracts  = Decimal::input( $input['contracts'] ?? null, 18, true );
		$multiplier = Decimal::input( $input['multiplier'] ?? null, 18, true );
		$entry_fee  = Decimal::input( $input['entry_fee'] ?? '0', 12 );
		$exit_fee   = Decimal::input( $input['exit_fee'] ?? '0', 12 );
		if ( ! ctype_digit( $contracts ) ) {
			throw new \InvalidArgumentException( 'Contract count must be a positive whole number.' );
		}
		$units = Decimal::mul( $contracts, $multiplier );
		if ( 'close' === $mode ) {
			$exit_premium = Decimal::input( $input['exit_premium'] ?? null, 18 );
		} else {
			$strike       = Decimal::input( $input['strike'] ?? null, 18, true );
			$underlying   = Decimal::input( $input['underlying_price'] ?? null, 18 );
			$intrinsic    = 'call' === $type ? Decimal::sub( $underlying, $strike ) : Decimal::sub( $strike, $underlying );
			$exit_premium = Decimal::compare( $intrinsic, '0' ) > 0 ? $intrinsic : '0';
		}
		$entry_value = Decimal::money( Decimal::mul( $premium, $units ) );
		$payoff      = Decimal::money( Decimal::mul( $exit_premium, $units ) );
		$costs       = Decimal::money( Decimal::add( $entry_fee, $exit_fee ) );
		$capital     = Decimal::money( Decimal::add( $entry_value, $entry_fee ) );
		if ( Decimal::compare( $capital, '0' ) <= 0 ) {
			throw new \InvalidArgumentException( 'Starting capital is below supported monetary precision.' );
		}
		$profit = Decimal::money( Decimal::sub( Decimal::sub( $payoff, $entry_value ), $costs ) );
		return array(
			'calculation_version' => 'scenario-bought-option-1',
			'option_type'         => $type,
			'mode'                => $mode,
			'entry_premium_value' => $entry_value,
			'starting_capital'    => $capital,
			'gross_exit_value'    => $payoff,
			'total_fees'          => $costs,
			'net_profit'          => $profit,
			'return_on_capital'   => Decimal::round( Decimal::mul( Decimal::div( $profit, $capital ), '100' ), 12 ),
		);
	}

	/**
	 * Estimate owned-share sale or borrowed-share cover profit.
	 *
	 * @param string $direction Long or short.
	 * @param string $entry Entry price per share.
	 * @param string $exit_price Sale or cover price per share.
	 * @param string $quantity Share quantity.
	 * @param string $entry_fee Entry cost amount.
	 * @param string $exit_fee Exit cost amount.
	 * @param string $borrow_cost Borrowing cost amount, short only.
	 * @param string $dividend_cost Dividend payment amount, short only.
	 * @return array Non-posting decimal scenario.
	 * @throws \InvalidArgumentException When inputs are invalid.
	 */
	public static function stock( string $direction, string $entry, string $exit_price, string $quantity, string $entry_fee = '0', string $exit_fee = '0', string $borrow_cost = '0', string $dividend_cost = '0' ): array {
		if ( ! in_array( $direction, array( 'long', 'short' ), true ) ) {
			throw new \InvalidArgumentException( 'Direction must be long or short.' );
		}
		Decimal::input( $entry, 18, true );
		Decimal::input( $exit_price, 18 );
		Decimal::input( $quantity, 18, true );
		foreach ( array( $entry_fee, $exit_fee, $borrow_cost, $dividend_cost ) as $cost ) {
			Decimal::input( $cost, 12 );
		}
		if ( 'long' === $direction && ( Decimal::compare( $borrow_cost, '0' ) > 0 || Decimal::compare( $dividend_cost, '0' ) > 0 ) ) {
			throw new \InvalidArgumentException( 'Borrowing and dividend payment costs apply to short scenarios only.' );
		}
		$entry_value = Decimal::mul( $quantity, $entry );
		$change      = 'long' === $direction ? Decimal::sub( $exit_price, $entry ) : Decimal::sub( $entry, $exit_price );
		$gross       = Decimal::money( Decimal::mul( $quantity, $change ) );
		$costs       = Decimal::money( Decimal::add( Decimal::add( $entry_fee, $exit_fee ), Decimal::add( $borrow_cost, $dividend_cost ) ) );
		$net         = Decimal::money( Decimal::sub( $gross, $costs ) );
		return array(
			'calculation_version'   => 'scenario-stock-1',
			'direction'             => $direction,
			'quantity'              => $quantity,
			'entry_value'           => Decimal::money( $entry_value ),
			'exit_value'            => Decimal::money( Decimal::mul( $quantity, $exit_price ) ),
			'gross_profit'          => $gross,
			'total_costs'           => $costs,
			'net_profit'            => $net,
			'return_on_entry_value' => Decimal::round( Decimal::mul( Decimal::div( $net, $entry_value ), '100' ), 12 ),
			'margin_required'       => null,
		);
	}

	/**
	 * Size whole short shares at an adverse stop, reserving entered costs.
	 *
	 * @param string $entry Entry price per share.
	 * @param string $stop_price Stop above entry.
	 * @param string $risk_budget Total loss budget, not investment capital.
	 * @param string $estimated_costs Estimated total costs at the stop.
	 * @return array Non-posting decimal scenario.
	 * @throws \InvalidArgumentException When the budget cannot buy one risk unit.
	 */
	public static function short_risk( string $entry, string $stop_price, string $risk_budget, string $estimated_costs = '0' ): array {
		Decimal::input( $entry, 18, true );
		Decimal::input( $stop_price, 18, true );
		Decimal::input( $risk_budget, 12, true );
		Decimal::input( $estimated_costs, 12 );
		if ( Decimal::compare( $stop_price, $entry ) <= 0 ) {
			throw new \InvalidArgumentException( 'Short stop price must be above entry price.' );
		}
		$distance = Decimal::sub( $stop_price, $entry );
		$budget   = Decimal::sub( $risk_budget, $estimated_costs );
		if ( Decimal::compare( $budget, '0' ) <= 0 ) {
			throw new \InvalidArgumentException( 'Estimated costs must be below the risk budget.' );
		}
		$shares = bcdiv( $budget, $distance, 0 );
		if ( Decimal::compare( $shares, '1' ) < 0 ) {
			throw new \InvalidArgumentException( 'Risk budget after costs is too small for one whole share.' );
		}
		$price_risk = Decimal::money( Decimal::mul( $shares, $distance ) );
		$risk_used  = Decimal::money( Decimal::add( $price_risk, $estimated_costs ) );
		return array(
			'calculation_version' => 'scenario-short-risk-1',
			'stop_price'          => $stop_price,
			'position_size'       => $shares,
			'notional_exposure'   => Decimal::money( Decimal::mul( $shares, $entry ) ),
			'price_loss_at_stop'  => $price_risk,
			'estimated_costs'     => $estimated_costs,
			'risk_used'           => $risk_used,
			'unused_budget'       => Decimal::money( Decimal::sub( $risk_budget, $risk_used ) ),
			'margin_required'     => null,
		);
	}

	/**
	 * Model linear exposure with costs paid in the same unit as collateral.
	 *
	 * @param string $direction Long or short.
	 * @param string $entry Entry price.
	 * @param string $exit_price Proposed exit price, including zero.
	 * @param string $collateral Entered collateral, excluding costs.
	 * @param string $leverage Exposure multiplier, at least one.
	 * @param string $entry_fee Entry cost amount.
	 * @param string $exit_fee Exit cost amount.
	 * @param string $other_costs Borrowing and funding cost amount.
	 * @return array Non-posting decimal scenario.
	 * @throws \InvalidArgumentException When inputs exceed the supported model.
	 */
	public static function leveraged( string $direction, string $entry, string $exit_price, string $collateral, string $leverage, string $entry_fee = '0', string $exit_fee = '0', string $other_costs = '0' ): array {
		if ( ! in_array( $direction, array( 'long', 'short' ), true ) ) {
			throw new \InvalidArgumentException( 'Direction must be long or short.' );
		}
		Decimal::input( $entry, 18, true );
		Decimal::input( $exit_price, 18 );
		Decimal::input( $collateral, 12, true );
		Decimal::input( $leverage, 12, true );
		if ( Decimal::compare( $leverage, '1' ) < 0 ) {
			throw new \InvalidArgumentException( 'Leverage must be at least 1.' );
		}
		foreach ( array( $entry_fee, $exit_fee, $other_costs ) as $cost ) {
			Decimal::input( $cost, 12 );
		}
		$exposure = Decimal::mul( $collateral, $leverage );
		$notional = Decimal::money( $exposure );
		$change   = 'long' === $direction ? Decimal::sub( $exit_price, $entry ) : Decimal::sub( $entry, $exit_price );
		// Keep full precision for P&L; displayed equivalent units are not an intermediate.
		$gross = Decimal::money( Decimal::mul( $exposure, Decimal::div( $change, $entry ) ) );
		$costs = Decimal::money( Decimal::add( Decimal::add( $entry_fee, $exit_fee ), $other_costs ) );
		$net   = Decimal::money( Decimal::sub( $gross, $costs ) );
		return array(
			'calculation_version'  => 'scenario-linear-1',
			'direction'            => $direction,
			'notional_exposure'    => $notional,
			'equivalent_units'     => bcdiv( $exposure, $entry, 18 ),
			'gross_profit'         => $gross,
			'total_costs'          => $costs,
			'net_profit'           => $net,
			'return_on_collateral' => Decimal::round( Decimal::mul( Decimal::div( $net, $collateral ), '100' ), 12 ),
			'liquidation_price'    => null,
		);
	}

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
