<?php
/**
 * Bounded journal input contracts (JR 01-03).
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Explicit validation for non-financial input. */
final class JournalInput {
	/** Validate object keys.
	 *
	 * @param array $input Input object.
	 * @param array $allowed Supported keys.
	 * @param array $required Required keys.
	 * @return void
	 * @throws \InvalidArgumentException When fields are missing or unknown.
	 */
	public static function fields( array $input, array $allowed, array $required = array() ): void {
		if ( array_diff( array_keys( $input ), $allowed ) || array_diff( $required, array_keys( $input ) ) ) {
			throw new \InvalidArgumentException( 'Unknown or missing fields.' );
		}
	}
	/** Validate positive integer identity.
	 *
	 * @param mixed $value Input value.
	 * @return int
	 * @throws \InvalidArgumentException When identity is invalid.
	 */
	public static function id( $value ): int {
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new \InvalidArgumentException( 'IDs and revisions must be positive JSON integers.' );
		}
		return $value;
	}
	/** Validate bounded text before sanitization.
	 *
	 * @param mixed $value Input value.
	 * @param int   $max Byte limit.
	 * @return string
	 * @throws \InvalidArgumentException When text is not bounded.
	 */
	public static function text( $value, int $max = 20000 ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $max || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value ) ) {
			throw new \InvalidArgumentException( 'Text is invalid or exceeds its limit.' );
		}
		return $value;
	}
	/** Validate nullable date-only precision.
	 *
	 * @param mixed $value Input value.
	 * @return string|null
	 * @throws \InvalidArgumentException When date is invalid.
	 */
	public static function date( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ) : false;
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			throw new \InvalidArgumentException( 'Use a valid YYYY-MM-DD date.' );
		}
		return $value;
	}
	/** Validate a bounded checklist or tags.
	 *
	 * @param mixed $value Input list.
	 * @return array
	 * @throws \InvalidArgumentException When list is invalid.
	 */
	public static function labels( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 50 ) {
			throw new \InvalidArgumentException( 'Use a list of at most 50 labels.' );
		}
		return array_values(
			array_unique(
				array_map(
					static function ( $label ) {
						return trim( self::text( $label, 190 ) );
					},
					$value
				)
			)
		);
	}
}
