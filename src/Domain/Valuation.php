<?php
/**
 * Exact valuation arithmetic and dated observation selection.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Pure valuation rules; never fetches prices or invents missing rates. */
final class Valuation {

	/**
	 * Select the latest eligible observation, including an explicit stale label.
	 *
	 * @param array  $rows Active observations for one identity.
	 * @param string $date Valuation date.
	 * @return array
	 */
	public static function select( array $rows, string $date ): array {
		$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['effective_date'] <= $date ) );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$order = strcmp( $b['effective_date'], $a['effective_date'] );
				return 0 !== $order ? $order : (int) $b['id'] <=> (int) $a['id'];
			}
		);
		$row = $rows[0] ?? null;
		return array(
			'observation' => $row,
			'status'      => null === $row ? 'missing' : ( $row['expires_on'] < $date ? 'stale' : 'complete' ),
		);
	}

	/**
	 * Convert using one native unit in base currency; null propagates.
	 *
	 * @param string|null $amount Native amount.
	 * @param string|null $rate Explicit rate.
	 * @return string|null
	 */
	public static function convert( ?string $amount, ?string $rate ): ?string {
		return null === $amount || null === $rate ? null : Decimal::mul( $amount, $rate );
	}

	/**
	 * Calculate a value and gain without prematurely rounding.
	 *
	 * @param string      $quantity Units.
	 * @param string|null $price Quote.
	 * @param string|null $basis Remaining basis.
	 * @return array
	 */
	public static function position( string $quantity, ?string $price, ?string $basis ): array {
		$value = null === $price ? null : Decimal::mul( $quantity, $price );
		return array(
			'market_value'    => $value,
			'unrealized_gain' => null === $value || null === $basis ? null : Decimal::sub( $value, $basis ),
		);
	}

	/**
	 * Calculate fixed-quantity price movement, not transaction-aware daily P&L.
	 * Caller must establish session and corporate-action compatibility first.
	 *
	 * @param string      $quantity Current owned units.
	 * @param string|null $price Current compatible quote.
	 * @param string|null $previous_close Previous regular-session close.
	 * @return array
	 */
	public static function price_movement( string $quantity, ?string $price, ?string $previous_close ): array {
		Decimal::input( $quantity );
		if ( null !== $price ) {
			Decimal::input( $price );
		}
		if ( null !== $previous_close ) {
			Decimal::input( $previous_close );
		}
		$result = array(
			'per_unit' => null,
			'amount'   => null,
			'percent'  => null,
		);
		if ( null === $price || null === $previous_close ) {
			return $result;
		}
		$result['per_unit'] = Decimal::sub( $price, $previous_close );
		$result['amount']   = Decimal::mul( $quantity, $result['per_unit'] );
		$result['percent']  = Decimal::compare( $previous_close, '0' ) > 0 ? Decimal::mul( Decimal::div( $result['per_unit'], $previous_close ), '100' ) : null;
		return $result;
	}
}
