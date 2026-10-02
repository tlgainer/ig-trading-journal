<?php
/**
 * Exact decimal operations; no WordPress dependency.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Decimal service for the current implementation slice. */
final class Decimal {
	public const SCALE = 36;

	/**
	 * Validate a plain unsigned decimal string and storage bounds.
	 *
	 * @param mixed $value value input.
	 * @param int   $scale scale input.
	 * @param bool  $positive positive input.
	 * @return string
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public static function input( $value, int $scale = 18, bool $positive = false ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Decimal values must be unsigned plain decimal strings.' );
		}
		$parts = explode( '.', $value );
		if ( strlen( $parts[0] ) > 38 - $scale || strlen( $parts[1] ?? '' ) > $scale ) {
			throw new \InvalidArgumentException( 'Decimal value exceeds storage precision.' );
		}
		if ( $positive && self::compare( $value, '0' ) <= 0 ) {
			throw new \InvalidArgumentException( 'Value must be positive.' );
		}
		return $value;
	}

	/**
	 * Add without binary floating point.
	 *
	 * @param string $a a input.
	 * @param string $b b input.
	 * @return string
	 */
	public static function add( string $a, string $b ): string {
		return bcadd( $a, $b, self::SCALE );
	}
	/**
	 * Subtract without binary floating point.
	 *
	 * @param string $a a input.
	 * @param string $b b input.
	 * @return string
	 */
	public static function sub( string $a, string $b ): string {
		return bcsub( $a, $b, self::SCALE );
	}
	/**
	 * Multiply with full decimal intermediates.
	 *
	 * @param string $a a input.
	 * @param string $b b input.
	 * @return string
	 */
	public static function mul( string $a, string $b ): string {
		return bcmul( $a, $b, self::SCALE );
	}
	/**
	 * Divide with an explicit nonzero denominator.
	 *
	 * @param string $a a input.
	 * @param string $b b input.
	 * @return string
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public static function div( string $a, string $b ): string {
		if ( self::compare( $b, '0' ) === 0 ) {
			throw new \InvalidArgumentException( 'Zero denominator.' );
		}
		return bcdiv( $a, $b, self::SCALE );
	}
	/**
	 * Compare exact decimal values.
	 *
	 * @param string $a a input.
	 * @param string $b b input.
	 * @return int
	 */
	public static function compare( string $a, string $b ): int {
		return bccomp( $a, $b, self::SCALE );
	}

	/**
	 * Apply symmetric half-up rounding at a declared boundary.
	 *
	 * @param string $value value input.
	 * @param int    $scale scale input.
	 * @return string
	 */
	public static function round( string $value, int $scale = 12 ): string {
		$negative  = str_starts_with( $value, '-' );
		$increment = '0.' . str_repeat( '0', $scale ) . '5';
		return bcadd( $value, $negative ? '-' . $increment : $increment, $scale );
	}

	/**
	 * Round and validate a monetary storage value.
	 *
	 * @param string $value value input.
	 * @return string
	 */
	public static function money( string $value ): string {
		$value    = self::round( $value );
		$unsigned = ltrim( $value, '-' );
		self::input( $unsigned, 12 );
		return $value;
	}
}
