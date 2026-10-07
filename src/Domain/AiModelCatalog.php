<?php
/**
 * Credential-bound model evidence for text summaries.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Pure catalog validation; never discovers models or makes network requests. */
final class AiModelCatalog {
	/**
	 * Admit only dated model evidence tied to the current server credential.
	 *
	 * @param array              $entries Trusted server evidence, keyed by exact model.
	 * @param string             $credential SHA-256 credential fingerprint.
	 * @param \DateTimeImmutable $now Evaluation time.
	 * @return array Validated pricing entries for AiSpending configuration.
	 * @throws \InvalidArgumentException On invalid or expired model evidence.
	 */
	public static function verified( array $entries, string $credential, \DateTimeImmutable $now ): array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $credential ) ) {
			throw new \InvalidArgumentException( 'Model verification requires a credential fingerprint.' );
		}
		$result = array();
		foreach ( $entries as $model => $entry ) {
			if ( ! is_string( $model ) || ! is_array( $entry ) || array_diff( array_keys( $entry ), array( 'pricing', 'credential_fingerprint', 'access_verified_at' ) ) || array_diff( array( 'pricing', 'credential_fingerprint', 'access_verified_at' ), array_keys( $entry ) ) || ! is_array( $entry['pricing'] ) || ! is_string( $entry['credential_fingerprint'] ) || ! hash_equals( $credential, $entry['credential_fingerprint'] ) || ! is_string( $entry['access_verified_at'] ) ) {
				throw new \InvalidArgumentException( 'Model access evidence does not match the current credential.' );
			}
			$pricing = AiBudget::pricing( $entry['pricing'], $now );
			$access  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $entry['access_verified_at'], new \DateTimeZone( 'UTC' ) );
			if ( $pricing['model'] !== $model || false === $access || $access->format( 'Y-m-d H:i:s' ) !== $entry['access_verified_at'] || $access > $now || $access->modify( '+30 days' ) <= $now ) {
				throw new \InvalidArgumentException( 'Model identity or dated access evidence is invalid or expired.' );
			}
			$result[ $model ] = $pricing;
		}
		return $result;
	}
}
