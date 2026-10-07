<?php
/**
 * Verify trusted complete-input count receipts before budget admission.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** No networking, consent, storage or authorization is performed here. */
final class AiTokenCount {

	/**
	 * Bind a server count response to the exact prompt, credential and prices.
	 *
	 * @param array              $plan Deterministic AiPrompt result.
	 * @param array              $context Trusted server execution metadata, never browser claims.
	 * @param string             $body Count endpoint response bytes.
	 * @param int                $http_status Actual server-observed HTTP status.
	 * @param string             $credential Current server credential digest.
	 * @param array              $pricing Verified exact-model pricing.
	 * @param \DateTimeImmutable $now Trusted current time.
	 * @return array Canonical verified bound and integrity fingerprint.
	 * @throws \InvalidArgumentException On ambiguous, stale or mismatched evidence.
	 */
	public static function verify( array $plan, array $context, string $body, int $http_status, string $credential, array $pricing, \DateTimeImmutable $now ): array {
		$fields = array( 'credential_fingerprint', 'count_fingerprint', 'request_fingerprint', 'counted_at' );
		if ( 200 !== $http_status || ! preg_match( '/^[a-f0-9]{64}$/D', $credential ) || array_diff( array_keys( $context ), $fields ) || array_diff( $fields, array_keys( $context ) ) ) {
			throw new \InvalidArgumentException( 'Token-count execution evidence is incomplete or unverified.' );
		}
		$text   = $plan['request']['input'][0]['content'][0]['text'] ?? null;
		$model  = $plan['request']['model'] ?? null;
		$output = $plan['request']['max_output_tokens'] ?? null;
		if ( ! is_string( $text ) || ! is_string( $model ) || ! is_int( $output ) || ! is_string( $plan['evidence_fingerprint'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Token count requires a complete deterministic prompt.' );
		}
		$expected = AiPrompt::build( $text, $plan['evidence_fingerprint'], $model, $output );
		if ( $plan !== $expected || $context['credential_fingerprint'] !== $credential || $context['count_fingerprint'] !== $plan['count_fingerprint'] || $context['request_fingerprint'] !== $plan['request_fingerprint'] || ! is_string( $context['counted_at'] ) ) {
			throw new \InvalidArgumentException( 'Token count does not match the exact request or credential.' );
		}
		$at = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $context['counted_at'], new \DateTimeZone( 'UTC' ) );
		if ( false === $at || $at->format( 'Y-m-d H:i:s' ) !== $context['counted_at'] || $at > $now || $at->modify( '+5 minutes' ) <= $now ) {
			throw new \InvalidArgumentException( 'Token count is stale or has an invalid verification time.' );
		}
		$count = AiJson::decode( $body, 4096 );
		if ( array_diff( array_keys( $count ), array( 'object', 'input_tokens' ) ) || count( $count ) !== 2 || 'response.input_tokens' !== ( $count['object'] ?? null ) || ! is_int( $count['input_tokens'] ?? null ) || $count['input_tokens'] < 1 || $count['input_tokens'] > 1000000 ) {
			throw new \InvalidArgumentException( 'Token-count response is malformed or exceeds supported bounds.' );
		}
		$prices = AiBudget::pricing( $pricing, $now );
		if ( $prices['model'] !== $model ) {
			throw new \InvalidArgumentException( 'Token count and pricing refer to different models.' );
		}
		$bound = array(
			'version'                => 'ai-token-count-1',
			'credential_fingerprint' => $credential,
			'evidence_fingerprint'   => $plan['evidence_fingerprint'],
			'request_fingerprint'    => $plan['request_fingerprint'],
			'count_fingerprint'      => $plan['count_fingerprint'],
			'model'                  => $model,
			'input_tokens'           => $count['input_tokens'],
			'output_tokens'          => $output,
			'counted_at'             => $at->format( 'Y-m-d H:i:s' ),
			'expires_at'             => $at->modify( '+5 minutes' )->format( 'Y-m-d H:i:s' ),
			'pricing'                => $prices,
			'maximum_cost'           => AiBudget::estimate( $prices, $count['input_tokens'], $output, $now ),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical receipt, no raw provider body.
		$json = json_encode( $bound, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		return array(
			'bound'       => $bound,
			'fingerprint' => hash( 'sha256', $json ),
		);
	}
}
