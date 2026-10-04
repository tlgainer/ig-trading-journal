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
}
