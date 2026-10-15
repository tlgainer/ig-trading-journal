<?php
/**
 * Selective, lossless Tickerbot identity and current-mark normalization.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** Parsing only; no provider calls, ledger writes or inferred currency conversion. */
final class TickerbotQuote {
	/**
	 * Validate a canonical instrument path token.
	 *
	 * @param string $symbol Explicit canonical symbol; preserve case-sensitive instruments.
	 * @return string Validated symbol.
	 * @throws \InvalidArgumentException On invalid path tokens.
	 */
	public static function symbol( string $symbol ): string {
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9.:-]{0,79}$/D', $symbol ) ) {
			throw new \InvalidArgumentException( 'Invalid canonical Tickerbot symbol.' );
		}
		return $symbol;
	}

	/**
	 * Compare asset class, currency and exchange against local identity.
	 *
	 * @param array  $asset Local stable identity.
	 * @param array  $row Provider identity row.
	 * @param string $symbol Explicit canonical candidate.
	 * @return bool Exact compatible identity, never name-based matching.
	 */
	public static function identity( array $asset, array $row, string $symbol ): bool {
		$class = $asset['asset_class'] ?? '';
		if ( ( $row['ticker'] ?? null ) !== $symbol || ! is_string( $row['currency_name'] ?? null )
			|| strtoupper( $row['currency_name'] ) !== ( $asset['quote_currency'] ?? null ) ) {
			return false;
		}
		if ( 'crypto' === $class ) {
			return 'crypto' === ( $row['asset_class'] ?? null ) && 'USD' === $asset['quote_currency'] && 'X:' . ( $asset['symbol'] ?? '' ) . 'USD' === $symbol;
		}
		if ( ( $asset['symbol'] ?? null ) !== $symbol || ! in_array( $class, array( 'stock', 'etf' ), true ) || 'stocks' !== ( $row['asset_class'] ?? null )
			|| ( 'etf' === $class ) !== ( 'ETF' === ( $row['asset_type'] ?? null ) ) ) {
			return false;
		}
		$exchange = $asset['exchange'] ?? '';
		return '' !== $exchange && in_array( $exchange, array( $row['exchange'] ?? null, $row['exchange_mic'] ?? null ), true );
	}

	/**
	 * Normalize an honest provider timestamp without inventing missing time.
	 *
	 * @param mixed $value Provider ISO timestamp or null.
	 * @return string|null UTC timestamp with microseconds; unknown stays unknown.
	 * @throws \InvalidArgumentException On malformed timestamps.
	 */
	public static function timestamp( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid provider timestamp.' );
		}
		try {
			$date = new \DateTimeImmutable( $value );
		} catch ( \Exception $error ) {
			throw new \InvalidArgumentException( 'Invalid provider timestamp.' );
		}
		if ( $date->format( 'Y-m-d\TH:i:s' ) !== substr( $value, 0, 19 ) ) {
			throw new \InvalidArgumentException( 'Invalid provider calendar date.' );
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' );
	}

	/**
	 * Normalize single and batch marks while isolating per-symbol failures.
	 *
	 * @param string             $body Bounded current-state response.
	 * @param array              $mappings Asset identity plus provider_symbol, at most fifty symbols.
	 * @param \DateTimeImmutable $now Local fetch completion.
	 * @return array Accepted marks and isolated per-asset issues.
	 * @throws \InvalidArgumentException On incompatible envelopes.
	 */
	public static function parse( string $body, array $mappings, \DateTimeImmutable $now ): array {
		$payload = ProviderJson::decode( $body );
		$symbols = array_values( array_unique( array_column( $mappings, 'provider_symbol' ) ) );
		if ( ! $symbols || count( $symbols ) > 50 || ! is_array( $payload['data'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Invalid Tickerbot quote envelope.' );
		}
		foreach ( $symbols as $symbol ) {
			self::symbol( $symbol );
		}
		$as_of = self::timestamp( $payload['as_of'] ?? null );
		if ( isset( $payload['ticker'] ) ) {
			if ( count( $symbols ) !== 1 || $payload['ticker'] !== $symbols[0] ) {
				throw new \InvalidArgumentException( 'Tickerbot response identity mismatch.' );
			}
			$rows = array( $payload['ticker'] => $payload['data'] );
		} else {
			$requested = $payload['requested'] ?? null;
			$missing   = $payload['not_found'] ?? null;
			$rows      = $payload['data'];
			if ( ! is_array( $requested ) || ! is_array( $missing ) || $requested !== $symbols
				|| count( $requested ) !== count( array_unique( $requested ) )
				|| array_diff( array_keys( $rows ), $symbols ) || array_diff( $missing, $symbols )
				|| count( $missing ) !== count( array_unique( $missing ) ) || array_intersect( array_keys( $rows ), $missing ) || count( $rows ) + count( $missing ) !== count( $symbols )
				|| (string) count( $rows ) !== ( $payload['count'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Inconsistent Tickerbot batch response.' );
			}
		}
		$accepted = array();
		$issues   = array();
		foreach ( $mappings as $asset ) {
			$id     = $asset['id'];
			$symbol = $asset['provider_symbol'];
			$row    = $rows[ $symbol ] ?? null;
			if ( null === $row ) {
				$issues[ $id ] = 'unsupported';
				continue;
			}
			try {
				if ( ! is_array( $row ) || ! self::identity( $asset, $row, $symbol ) ) {
					$issues[ $id ] = 'identity_mismatch';
					continue;
				}
				$source = $row['price_source'] ?? null;
				if ( ! in_array( $source, array( 'fair_value', 'trade', 'quote', 'minute_bar', 'day_bar', 'previous_close', 'exchange_rate' ), true ) ) {
					throw new \InvalidArgumentException( 'Unsupported investment mark source.' );
				}
				$price = Decimal::input( ProviderJson::number( $row['price'] ?? '' ), 18, true );
				$time  = self::timestamp( $row['price_asof'] ?? null );
				if ( null !== $time && new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) ) > $now->modify( '+5 minutes' ) ) {
					throw new \InvalidArgumentException( 'Provider price timestamp is in the future.' );
				}
				$accepted[ $id ] = array(
					'asset_id'              => $id,
					'provider'              => 'tickerbot',
					'provider_symbol'       => $symbol,
					'price'                 => $price,
					'currency'              => $asset['quote_currency'],
					'price_source'          => $source,
					'price_asof'            => $time,
					'response_as_of'        => $as_of,
					'fetched_at'            => $now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ),
					'source_time_semantics' => null === $time ? 'unknown' : ( 'fair_value' === $source ? 'provider_fetch_time' : 'source_observation_time' ),
				);
			} catch ( \InvalidArgumentException | \TypeError $error ) {
				$issues[ $id ] = 'invalid_quote';
			}
		}
		return array(
			'accepted' => $accepted,
			'issues'   => $issues,
		);
	}
}
