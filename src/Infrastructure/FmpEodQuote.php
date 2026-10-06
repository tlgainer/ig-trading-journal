<?php
/**
 * Normalize FMP's daily price history without inventing missing sessions.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** Parsing only; entitlement, authorization and adjustment checks remain caller gates. */
final class FmpEodQuote {

	/**
	 * Select the latest two distinct daily observations regardless of response order.
	 *
	 * @param string $body Response body.
	 * @param string $symbol Expected mapped symbol.
	 * @return array
	 * @throws \InvalidArgumentException On incomplete or conflicting evidence.
	 */
	public static function parse( string $body, string $symbol ): array {
		$payload = ProviderJson::decode( $body );
		if ( '' === $symbol || ! array_is_list( $payload ) || count( $payload ) < 2 || count( $payload ) > 500 ) {
			throw new \InvalidArgumentException( 'Provider daily history is missing or unavailable.' );
		}
		$sessions = array();
		foreach ( $payload as $row ) {
			if ( ! is_array( $row ) || ( $row['symbol'] ?? null ) !== $symbol ) {
				throw new \InvalidArgumentException( 'Provider quote identity is missing or mismatched.' );
			}
			$date = $row['date'] ?? null;
			if ( ! is_string( $date ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date ) ) {
				throw new \InvalidArgumentException( 'Provider session date is invalid.' );
			}
			$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date || ! is_string( $row['price'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Provider session date or price is invalid.' );
			}
			$price = Decimal::input( ProviderJson::number( $row['price'] ), 18, true );
			if ( isset( $sessions[ $date ] ) && Decimal::compare( $sessions[ $date ], $price ) ) {
				throw new \InvalidArgumentException( 'Provider returned conflicting prices for one session.' );
			}
			$sessions[ $date ] = $price;
		}
		if ( count( $sessions ) < 2 ) {
			throw new \InvalidArgumentException( 'Provider previous session is unavailable.' );
		}
		krsort( $sessions, SORT_STRING );
		$dates = array_keys( $sessions );
		return array(
			'provider'        => 'fmp',
			'provider_symbol' => $symbol,
			'price'           => $sessions[ $dates[0] ],
			'previous_close'  => $sessions[ $dates[1] ],
			'session_date'    => $dates[0],
			'freshness'       => 'end_of_day',
			'currency'        => null,
			'exchange'        => null,
		);
	}
}
