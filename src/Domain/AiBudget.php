<?php
/**
 * Exact text-summary cost bounds and monthly budget arithmetic.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Pure policy only; does not authorize, reserve funds or send requests. */
final class AiBudget {

	/**
	 * Validate a selected text model and dated pricing evidence.
	 *
	 * @param array              $pricing Trusted server catalog entry.
	 * @param \DateTimeImmutable $now Evaluation time.
	 * @return array
	 * @throws \InvalidArgumentException On unknown, expired or unsupported pricing.
	 */
	public static function pricing( array $pricing, \DateTimeImmutable $now ): array {
		$fields = array( 'model', 'currency', 'input_per_million', 'cached_input_per_million', 'output_per_million', 'verified_at', 'valid_until', 'source', 'responses', 'structured_outputs', 'access_confirmed' );
		if ( array_diff( array_keys( $pricing ), $fields ) || array_diff( $fields, array_keys( $pricing ) ) || ! is_string( $pricing['model'] ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $pricing['model'] ) || 'USD' !== $pricing['currency'] || true !== $pricing['responses'] || true !== $pricing['structured_outputs'] || true !== $pricing['access_confirmed'] || ! is_string( $pricing['source'] ) || ! preg_match( '#^https://(?:developers|platform)\.openai\.com/[^\s]*$#D', $pricing['source'] ) ) {
			throw new \InvalidArgumentException( 'Model pricing or required text capabilities are unverified.' );
		}
		foreach ( array( 'input_per_million', 'cached_input_per_million', 'output_per_million' ) as $field ) {
			Decimal::input( $pricing[ $field ], 12, 'cached_input_per_million' !== $field );
		}
		$verified = self::timestamp( $pricing['verified_at'] );
		$expires  = self::timestamp( $pricing['valid_until'] );
		if ( $verified > $now || $expires <= $now || $expires <= $verified || $expires > $verified->modify( '+30 days' ) ) {
			throw new \InvalidArgumentException( 'Model pricing must be current and expire within thirty days of verification.' );
		}
		return $pricing;
	}

	/**
	 * Bound input at the higher rate and all generated output, including reasoning.
	 *
	 * @param array              $pricing Verified text-only prices.
	 * @param int                $input_tokens Confirmed upper bound on input tokens.
	 * @param int                $output_tokens Maximum generated tokens.
	 * @param \DateTimeImmutable $now Evaluation time.
	 * @return string USD reservation rounded upward to twelve places.
	 * @throws \InvalidArgumentException On unsupported pricing or token bounds.
	 */
	public static function estimate( array $pricing, int $input_tokens, int $output_tokens, \DateTimeImmutable $now ): string {
		self::pricing( $pricing, $now );
		self::tokens( $input_tokens );
		self::tokens( $output_tokens );
		if ( 0 === $input_tokens || 0 === $output_tokens ) {
			throw new \InvalidArgumentException( 'Summary reservations require positive input and output bounds.' );
		}
		$input_rate = Decimal::compare( $pricing['input_per_million'], $pricing['cached_input_per_million'] ) >= 0 ? $pricing['input_per_million'] : $pricing['cached_input_per_million'];
		return self::cost( $input_tokens, $input_rate, 0, '0', $output_tokens, $pricing['output_per_million'] );
	}

	/**
	 * Reconcile usage against captured prices, even after their dispatch expiry.
	 *
	 * @param array              $pricing Immutable verified dispatch prices.
	 * @param array              $usage Provider-reported token totals, including reasoning.
	 * @param \DateTimeImmutable $dispatched_at Original dispatch time.
	 * @return string Estimated USD charge; malformed usage must retain reservations.
	 * @throws \InvalidArgumentException On unverified prices or malformed usage.
	 */
	public static function charge( array $pricing, array $usage, \DateTimeImmutable $dispatched_at ): string {
		self::pricing( $pricing, $dispatched_at );
		$fields = array( 'input_tokens', 'cached_input_tokens', 'output_tokens' );
		if ( array_diff( array_keys( $usage ), $fields ) || array_diff( $fields, array_keys( $usage ) ) ) {
			throw new \InvalidArgumentException( 'Summary usage is incomplete or unsupported.' );
		}
		foreach ( $fields as $field ) {
			if ( ! is_int( $usage[ $field ] ) ) {
				throw new \InvalidArgumentException( 'Summary usage must contain integer token counts.' );
			}
			self::tokens( $usage[ $field ] );
		}
		if ( $usage['cached_input_tokens'] > $usage['input_tokens'] ) {
			throw new \InvalidArgumentException( 'Cached input exceeds total input usage.' );
		}
		return self::cost( $usage['input_tokens'] - $usage['cached_input_tokens'], $pricing['input_per_million'], $usage['cached_input_tokens'], $pricing['cached_input_per_million'], $usage['output_tokens'], $pricing['output_per_million'] );
	}

	/**
	 * Evaluate admission from a locked credential-wide ledger, including old holds.
	 *
	 * @param string $cap Configured monthly USD cap; zero pauses processing.
	 * @param string $spent Current-period settled estimates.
	 * @param string $reserved All unreconciled reservations across periods.
	 * @param string $requested New bounded reservation.
	 * @return array
	 */
	public static function admission( string $cap, string $spent, string $reserved, string $requested ): array {
		foreach ( array( $cap, $spent, $reserved, $requested ) as $value ) {
			Decimal::input( $value, 12 );
		}
		$used      = Decimal::add( $spent, $reserved );
		$available = Decimal::sub( $cap, $used );
		$remaining = Decimal::compare( $available, '0' ) > 0 ? $available : '0';
		$paused    = 0 === Decimal::compare( $cap, '0' );
		$allowed   = ! $paused && Decimal::compare( $requested, '0' ) > 0 && Decimal::compare( $requested, $available ) <= 0;
		$warning   = $paused ? 'paused' : ( Decimal::compare( $used, $cap ) >= 0 ? 'limit_reached' : ( Decimal::compare( $used, Decimal::mul( $cap, '0.9' ) ) >= 0 ? '90_percent' : ( Decimal::compare( $used, Decimal::mul( $cap, '0.8' ) ) >= 0 ? '80_percent' : 'none' ) ) );
		return array(
			'allowed'   => $allowed,
			'remaining' => bcadd( $remaining, '0', 12 ),
			'warning'   => $warning,
		);
	}

	/**
	 * Retain uncertain holds and flag reported charges beyond the original bound.
	 *
	 * @param string      $reservation Immutable original maximum cost.
	 * @param string|null $charge Verified usage cost, or unknown delivery cost.
	 * @return array
	 */
	public static function settlement( string $reservation, ?string $charge ): array {
		Decimal::input( $reservation, 12, true );
		if ( null === $charge ) {
			return array(
				'state'    => 'uncertain',
				'charge'   => null,
				'retained' => bcadd( $reservation, '0', 12 ),
				'released' => '0.000000000000',
			);
		}
		Decimal::input( $charge, 12 );
		$overrun = Decimal::compare( $charge, $reservation ) > 0;
		return array(
			'state'    => $overrun ? 'overrun' : 'settled',
			'charge'   => bcadd( $charge, '0', 12 ),
			'retained' => '0.000000000000',
			'released' => $overrun ? '0.000000000000' : bcsub( $reservation, $charge, 12 ),
		);
	}

	/**
	 * Fixed New York calendar boundaries cannot be changed to reset usage.
	 *
	 * @param \DateTimeImmutable $now Evaluation time.
	 * @return array
	 */
	public static function period( \DateTimeImmutable $now ): array {
		$local = $now->setTimezone( new \DateTimeZone( 'America/New_York' ) );
		$start = $local->modify( 'first day of this month' )->setTime( 0, 0 );
		$utc   = new \DateTimeZone( 'UTC' );
		return array(
			'period'    => $local->format( 'Y-m' ),
			'timezone'  => 'America/New_York',
			'starts_at' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'resets_at' => $start->modify( '+1 month' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Calculate text-token costs, conservatively rounding upward at storage.
	 *
	 * @param int    $input Input count.
	 * @param string $input_rate Input USD per million.
	 * @param int    $cached Cached count.
	 * @param string $cached_rate Cached USD per million.
	 * @param int    $output Output count, including reasoning.
	 * @param string $output_rate Output USD per million.
	 * @return string
	 */
	private static function cost( int $input, string $input_rate, int $cached, string $cached_rate, int $output, string $output_rate ): string {
		$value  = Decimal::div( Decimal::add( Decimal::add( Decimal::mul( (string) $input, $input_rate ), Decimal::mul( (string) $cached, $cached_rate ) ), Decimal::mul( (string) $output, $output_rate ) ), '1000000' );
		$stored = bcadd( $value, '0', 12 );
		$stored = Decimal::compare( $value, $stored ) > 0 ? bcadd( $stored, '0.000000000001', 12 ) : $stored;
		Decimal::input( $stored, 12 );
		return $stored;
	}

	/**
	 * Validate bounded token counts without coercion.
	 *
	 * @param int $tokens Token count.
	 * @return void
	 * @throws \InvalidArgumentException On invalid token bounds.
	 */
	private static function tokens( int $tokens ): void {
		if ( $tokens < 0 || $tokens > 1000000 ) {
			throw new \InvalidArgumentException( 'Summary token count exceeds supported bounds.' );
		}
	}

	/**
	 * Validate explicit UTC pricing timestamps.
	 *
	 * @param mixed $value Timestamp.
	 * @return \DateTimeImmutable
	 * @throws \InvalidArgumentException On malformed timestamps.
	 */
	private static function timestamp( $value ): \DateTimeImmutable {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'Pricing timestamps must be UTC strings.' );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new \InvalidArgumentException( 'Pricing timestamp is invalid.' );
		}
		return $date;
	}
}
