<?php
/**
 * Bounded JSON decoding with duplicate object-key rejection.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Ambiguous provider identities, usage and structured findings fail closed. */
final class AiJson {
	/**
	 * Decode bounded JSON and reject duplicate keys at every nesting level.
	 *
	 * @param string $body Server response or structured text.
	 * @param int    $limit Maximum bytes.
	 * @return array
	 * @throws \InvalidArgumentException On malformed, ambiguous or oversized JSON.
	 */
	public static function decode( string $body, int $limit = 524288 ): array {
		if ( $limit < 1 || $limit > 524288 || strlen( $body ) > $limit ) {
			throw new \InvalidArgumentException( 'AI JSON exceeds its size limit.' );
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Pure bounded parser; integer usage remains integer.
			$result = json_decode( $body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
			if ( ! is_array( $result ) ) {
				throw new \InvalidArgumentException( 'AI JSON must be an object or array.' );
			}
			$matched = preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^{}\[\]:,\s]+/s', $body, $matches );
			if ( false === $matched ) {
				throw new \InvalidArgumentException( 'AI JSON token validation failed.' );
			}
			$stack = array();
			foreach ( $matches[0] as $token ) {
				if ( '{' === $token || '[' === $token ) {
					$stack[] = array(
						'object' => '{' === $token,
						'key'    => '{' === $token,
						'keys'   => array(),
					);
					continue;
				}
				if ( '}' === $token || ']' === $token ) {
					array_pop( $stack );
					continue;
				}
				$index = count( $stack ) - 1;
				if ( $index < 0 || ! $stack[ $index ]['object'] ) {
					continue;
				}
				if ( ',' === $token || ':' === $token ) {
					$stack[ $index ]['key'] = ',' === $token;
				} elseif ( $stack[ $index ]['key'] ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Compare decoded keys, including escaped equivalents.
					$key = json_decode( $token, true, 2, JSON_THROW_ON_ERROR );
					if ( array_key_exists( $key, $stack[ $index ]['keys'] ) ) {
						throw new \InvalidArgumentException( 'AI JSON contains duplicate object keys.' );
					}
					$stack[ $index ]['keys'][ $key ] = true;
				}
			}
			return $result;
		} catch ( \JsonException $error ) {
			throw new \InvalidArgumentException( 'AI JSON is malformed.' );
		}
	}
}
