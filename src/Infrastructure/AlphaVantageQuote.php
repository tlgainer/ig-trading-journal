<?php
/**
 * Normalize Alpha Vantage's free end-of-day quote response.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** Parsing only: no credentials, HTTP, storage or workspace authorization. */
final class AlphaVantageQuote {

	/**
	 * Parse a successful response for an explicitly selected provider symbol.
	 * Currency/exchange mapping and corporate-action compatibility remain caller gates.
	 *
	 * @param string $body Response body.
	 * @param string $symbol Expected provider symbol.
	 * @return array
	 * @throws \InvalidArgumentException On unusable data.
	 */
	public static function parse( string $body, string $symbol ): array {
		$payload = ProviderJson::decode( $body );
		foreach ( array( 'Note', 'Information', 'Error Message' ) as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				// Never echo provider diagnostics: they can include request credentials.
				throw new \InvalidArgumentException( 'Provider returned a limit, entitlement or request error.' );
			}
		}
		$row = $payload['Global Quote'] ?? null;
		if ( ! is_array( $row ) || '' === $symbol || ( $row['01. symbol'] ?? null ) !== $symbol ) {
			throw new \InvalidArgumentException( 'Provider quote identity is missing or mismatched.' );
		}
		$date = $row['07. latest trading day'] ?? null;
		if ( ! is_string( $date ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date ) ) {
			throw new \InvalidArgumentException( 'Provider session date is invalid.' );
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Provider session date is invalid.' );
		}
		$price = self::price( $row['05. price'] ?? null );
		$prior = self::price( $row['08. previous close'] ?? null );
		return array(
			'provider'        => 'alpha_vantage',
			'provider_symbol' => $symbol,
			'price'           => $price,
			'previous_close'  => $prior,
			'session_date'    => $date,
			'freshness'       => 'end_of_day',
			'currency'        => null,
			'exchange'        => null,
		);
	}

	/**
	 * Validate an exact positive price within existing storage precision.
	 *
	 * @param mixed $value Provider value.
	 * @return string
	 * @throws \InvalidArgumentException On missing/invalid price.
	 */
	private static function price( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'Provider price is missing or invalid.' );
		}
		return Decimal::input( ProviderJson::number( $value ), 18, true );
	}
}
