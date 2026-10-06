<?php
/**
 * Lossless decoding of external financial JSON.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Provider numbers are strings before PHP's JSON decoder sees them. */
final class ProviderJson {

	/**
	 * Decode bounded JSON without passing numeric tokens through floats.
	 *
	 * @param string $body Provider response body.
	 * @return array
	 * @throws \InvalidArgumentException On malformed or oversized responses.
	 */
	public static function decode( string $body ): array {
		if ( strlen( $body ) > 2097152 ) {
			throw new \InvalidArgumentException( 'Provider response exceeds the size limit.' );
		}
		$quoted = preg_replace_callback(
			'/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?(\s*:)?/s',
			static function ( array $token_match ): string {
				if ( ! empty( $token_match[1] ) ) {
					throw new \InvalidArgumentException( 'Provider object keys must be quoted.' );
				}
				return '"' === $token_match[0][0] ? $token_match[0] : '"' . self::number( $token_match[0] ) . '"';
			},
			$body
		);
		try {
			$result = json_decode( $quoted ?? '', true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new \InvalidArgumentException( 'Provider response is not valid JSON.' );
		}
		if ( ! is_array( $result ) ) {
			throw new \InvalidArgumentException( 'Provider response must be an object or array.' );
		}
		return $result;
	}

	/**
	 * Expand a bounded decimal/scientific token with string operations.
	 *
	 * @param string $token Numeric token or provider numeric string.
	 * @return string
	 * @throws \InvalidArgumentException On invalid or excessive precision.
	 */
	public static function number( string $token ): string {
		if ( strlen( $token ) > 512 || ! preg_match( '/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $token, $parts ) ) {
			throw new \InvalidArgumentException( 'Provider number is invalid or exceeds precision limits.' );
		}
		$exponent_text = $parts[4] ?? '0';
		if ( strlen( ltrim( $exponent_text, '+-' ) ) > 3 || abs( (int) $exponent_text ) > 256 ) {
			throw new \InvalidArgumentException( 'Provider exponent exceeds precision limits.' );
		}
		$digits = $parts[2] . ( $parts[3] ?? '' );
		$point  = strlen( $parts[2] ) + (int) $exponent_text;
		if ( $point <= 0 ) {
			$value = '0.' . str_repeat( '0', -$point ) . $digits;
		} elseif ( $point >= strlen( $digits ) ) {
			$value = $digits . str_repeat( '0', $point - strlen( $digits ) );
		} else {
			$value = substr( $digits, 0, $point ) . '.' . substr( $digits, $point );
		}
		$pieces    = explode( '.', $value );
		$pieces[0] = ltrim( $pieces[0], '0' );
		$value     = ( '' === $pieces[0] ? '0' : $pieces[0] ) . ( isset( $pieces[1] ) ? '.' . $pieces[1] : '' );
		return $parts[1] . $value;
	}
}
