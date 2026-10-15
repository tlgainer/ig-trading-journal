<?php
/**
 * Bounded selective metadata; no quote or historical-price inference.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Discard all spans, measured fields, names and unknown provider data. */
final class TickerbotCoverageSummary {
	/**
	 * Normalize a matching coverage response without retaining field-name lists.
	 *
	 * @param array  $body Lossless provider envelope.
	 * @param string $symbol Expected canonical symbol.
	 * @return array Selective counts and null flags, never a price.
	 * @throws \InvalidArgumentException On inconsistent or excessive metadata.
	 */
	public static function parse( array $body, string $symbol ): array {
		$row = $body['live_row'] ?? null;
		if ( ( $body['ticker'] ?? null ) !== $symbol || ! is_array( $row ) ) {
			throw new \InvalidArgumentException( 'Invalid coverage identity.' );
		}
		foreach ( array( 'columns', 'populated' ) as $field ) {
			if ( ! isset( $row[ $field ] ) || ( ! is_int( $row[ $field ] ) && ! is_string( $row[ $field ] ) ) || ! ctype_digit( (string) $row[ $field ] ) || strlen( (string) $row[ $field ] ) > 5 || (int) $row[ $field ] > 10000 ) {
				throw new \InvalidArgumentException( 'Invalid coverage count.' );
			}
		}
		$missing = $row['null_fields'] ?? null;
		if ( ! is_array( $missing ) || ! array_is_list( $missing ) || count( $missing ) > 10000 || (int) $row['populated'] > (int) $row['columns'] || count( $missing ) !== (int) $row['columns'] - (int) $row['populated'] ) {
			throw new \InvalidArgumentException( 'Inconsistent coverage metadata.' );
		}
		foreach ( $missing as $field ) {
			if ( ! is_string( $field ) || ! preg_match( '/^[A-Za-z0-9_]{1,128}$/D', $field ) ) {
				throw new \InvalidArgumentException( 'Invalid null-field name.' );
			}
		}
		if ( count( array_unique( $missing ) ) !== count( $missing ) ) {
			throw new \InvalidArgumentException( 'Duplicate null-field names.' );
		}
		return array(
			'columns'         => (int) $row['columns'],
			'populated'       => (int) $row['populated'],
			'price_null'      => in_array( 'price', $missing, true ),
			'price_asof_null' => in_array( 'price_asof', $missing, true ),
			'as_of'           => TickerbotQuote::timestamp( $body['as_of'] ?? null ),
		);
	}

	/**
	 * Revalidate the selective representation at persistence/read boundaries.
	 *
	 * @param array $summary Selective metadata.
	 * @return array Validated metadata.
	 * @throws \InvalidArgumentException On inconsistent selective data.
	 */
	public static function validate( array $summary ): array {
		$keys = array( 'columns', 'populated', 'price_null', 'price_asof_null', 'as_of' );
		if ( count( $summary ) !== 5 || array_diff( $keys, array_keys( $summary ) ) || ! is_int( $summary['columns'] ) || ! is_int( $summary['populated'] ) || $summary['columns'] < 0 || $summary['columns'] > 10000 || $summary['populated'] < 0 || $summary['populated'] > $summary['columns'] || ! is_bool( $summary['price_null'] ) || ! is_bool( $summary['price_asof_null'] ) ) {
			throw new \InvalidArgumentException( 'Invalid selective coverage metadata.' );
		}
		$missing = ( $summary['price_null'] ? 1 : 0 ) + ( $summary['price_asof_null'] ? 1 : 0 );
		if ( $missing > $summary['columns'] - $summary['populated'] ) {
			throw new \InvalidArgumentException( 'Contradictory null flags.' ); }
		if ( null !== $summary['as_of'] ) {
			$date = is_string( $summary['as_of'] ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $summary['as_of'], new \DateTimeZone( 'UTC' ) ) : false;
			if ( ! $date || $date->format( 'Y-m-d H:i:s.u' ) !== $summary['as_of'] ) {
				throw new \InvalidArgumentException( 'Invalid coverage response time.' ); }
		}
		return $summary;
	}
}
