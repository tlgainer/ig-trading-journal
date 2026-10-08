<?php
/**
 * Dated cost evidence required before external token counting.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Paid or unverified counting requires a separate reservation lifecycle. */
final class AiCountPolicy {
	/**
	 * Admit only trusted, credential/model-bound evidence of zero counting charge.
	 *
	 * @param array              $policy Trusted server evidence, never browser assertions.
	 * @param string             $credential Current credential digest.
	 * @param string             $model Exact model.
	 * @param \DateTimeImmutable $now Current time.
	 * @return void
	 * @throws \InvalidArgumentException On absent, expired or unsupported cost evidence.
	 */
	public static function verify( array $policy, string $credential, string $model, \DateTimeImmutable $now ): void {
		$fields = array( 'credential_fingerprint', 'model', 'maximum_charge', 'verified_at', 'valid_until', 'source', 'access_confirmed' );
		if ( array_diff( array_keys( $policy ), $fields ) || array_diff( $fields, array_keys( $policy ) ) || ! preg_match( '/^[a-f0-9]{64}$/D', $credential ) || $policy['credential_fingerprint'] !== $credential || $policy['model'] !== $model || '0' !== $policy['maximum_charge'] || true !== $policy['access_confirmed'] || ! is_string( $policy['source'] ) || ! preg_match( '#^https://(?:developers|platform)\.openai\.com/[^\s]*$#D', $policy['source'] ) ) {
			throw new \InvalidArgumentException( 'Counting requires verified access and zero-charge evidence for this credential and model.' );
		}
		$dates = array();
		foreach ( array( 'verified_at', 'valid_until' ) as $field ) {
			if ( ! is_string( $policy[ $field ] ) ) {
				throw new \InvalidArgumentException( 'Count-cost evidence requires dated verification.' );
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $policy[ $field ], new \DateTimeZone( 'UTC' ) );
			if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $policy[ $field ] ) {
				throw new \InvalidArgumentException( 'Count-cost verification timestamp is invalid.' );
			}
			$dates[ $field ] = $date;
		}
		if ( $dates['verified_at'] > $now || $dates['valid_until'] <= $now || $dates['valid_until'] <= $dates['verified_at'] || $dates['valid_until'] > $dates['verified_at']->modify( '+30 days' ) ) {
			throw new \InvalidArgumentException( 'Count-cost evidence is expired or exceeds its verification lifetime.' );
		}
	}
}
