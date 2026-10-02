<?php
/**
 * Pure FIFO calculator, CAL 01 and TX 02.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Ledger service for the current implementation slice. */
final class Ledger {
	public const VERSION = 'fifo-native-1';

	/**
	 * Calculate fee-inclusive acquisition basis and cash effect.
	 *
	 * @param string $quantity quantity input.
	 * @param string $price price input.
	 * @param string $fees fees input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public static function buy( string $quantity, string $price, string $fees ): array {
		Decimal::input( $quantity, 18, true );
		Decimal::input( $price, 18, true );
		Decimal::input( $fees, 12 );
		$basis = Decimal::money( Decimal::add( Decimal::mul( $quantity, $price ), $fees ) );
		if ( Decimal::compare( $basis, '0' ) === 0 ) {
			throw new \InvalidArgumentException( 'Purchase basis is below supported monetary precision.' );
		}
		return array(
			'basis'      => $basis,
			'cash_delta' => Decimal::sub( '0', $basis ),
		);
	}

	/**
	 * Consume chronologically ordered lots and conserve basis and proceeds.
	 *
	 * @param array  $lots lots input.
	 * @param string $quantity quantity input.
	 * @param string $price price input.
	 * @param string $fees fees input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public static function sell( array $lots, string $quantity, string $price, string $fees ): array {
		Decimal::input( $quantity, 18, true );
		Decimal::input( $price, 18, true );
		Decimal::input( $fees, 12 );
		$proceeds = Decimal::money( Decimal::sub( Decimal::mul( $quantity, $price ), $fees ) );
		if ( Decimal::compare( $proceeds, '0' ) < 0 ) {
			throw new \InvalidArgumentException( 'Fees exceed sale proceeds.' );
		}
		$remaining          = $quantity;
		$basis              = '0';
		$allocated_proceeds = '0';
		$allocations        = array();
		foreach ( $lots as $lot ) {
			if ( Decimal::compare( $remaining, '0' ) === 0 ) {
				break;
			}
			$available = $lot['quantity_remaining'];
			if ( Decimal::compare( $available, '0' ) <= 0 ) {
				continue;
			}
			$take = Decimal::compare( $remaining, $available ) < 0 ? $remaining : $available;
			// Consume all residual basis when exhausting a lot; avoid rounding drift.
			$cost               = Decimal::compare( $take, $available ) === 0 ? $lot['basis_remaining'] : Decimal::money( Decimal::div( Decimal::mul( $lot['basis_remaining'], $take ), $available ) );
			$remaining          = Decimal::sub( $remaining, $take );
			$part_proceeds      = Decimal::compare( $remaining, '0' ) === 0 ? Decimal::sub( $proceeds, $allocated_proceeds ) : Decimal::money( Decimal::div( Decimal::mul( $proceeds, $take ), $quantity ) );
			$allocated_proceeds = Decimal::add( $allocated_proceeds, $part_proceeds );
			$basis              = Decimal::add( $basis, $cost );
			$allocations[]      = array(
				'lot_id'             => $lot['id'],
				'quantity'           => $take,
				'basis_native'       => $cost,
				'proceeds'           => $part_proceeds,
				'realized_gain'      => Decimal::money( Decimal::sub( $part_proceeds, $cost ) ),
				'quantity_remaining' => Decimal::sub( $available, $take ),
				'basis_remaining'    => Decimal::money( Decimal::sub( $lot['basis_remaining'], $cost ) ),
			);
		}
		if ( Decimal::compare( $remaining, '0' ) > 0 ) {
			throw new \InvalidArgumentException( 'Sale exceeds available units.' );
		}
		return array(
			'cash_delta'    => $proceeds,
			'realized_gain' => Decimal::money( Decimal::sub( $proceeds, $basis ) ),
			'allocations'   => $allocations,
		);
	}
}
