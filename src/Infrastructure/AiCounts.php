<?php
/**
 * Disabled-by-default authorized complete-input counting.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\AiSpending;
use GainerInteractive\IGTradingJournal\Domain\AiCountPolicy;
use GainerInteractive\IGTradingJournal\Domain\AiTokenCount;

/** Internal only; no endpoint, enrollment, generation or automatic retries. */
final class AiCounts {
	/**
	 * Count only exact approved input with verified zero-charge counting evidence.
	 *
	 * @param AiSpending $service Authorized actor's service.
	 * @param int        $workspace Workspace.
	 * @param int        $approval Exact approval.
	 * @param int        $output Full generated-output ceiling.
	 * @param array      $catalog Trusted model access/pricing.
	 * @param array      $policy Trusted account/model count-cost evidence.
	 * @return array Private verified execution data; never return as browser JSON.
	 * @throws \UnexpectedValueException On unsupported request size or preflight context.
	 */
	public static function run( AiSpending $service, int $workspace, int $approval, int $output, array $catalog, array $policy ): array {
		if ( ! Installer::ready() || ! AiTransport::enabled() ) {
			return array( 'state' => 'disabled' );
		}
		$key        = TGIT_OPENAI_API_KEY;
		$credential = hash( 'sha256', $key );
		$prepared   = $service->prepare_count( $workspace, $approval, $credential, $output, $catalog );
		$now        = new \DateTimeImmutable( $prepared['prepared_at'], new \DateTimeZone( 'UTC' ) );
		AiCountPolicy::verify( $policy, $credential, $prepared['plan']['request']['model'], $now );
		$body = wp_json_encode( $prepared['plan']['count_request'] );
		if ( ! is_string( $body ) || strlen( $body ) > 524288 ) {
			throw new \UnexpectedValueException( 'Complete input exceeds the counting transport bound.' );
		}
		try {
			$response = wp_safe_remote_post(
				'https://api.openai.com/v1/responses/input_tokens',
				array(
					'headers'             => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					),
					'body'                => $body,
					'timeout'             => 30,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 4096,
					'blocking'            => true,
					'cookies'             => array(),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array( 'state' => 'unavailable' );
			}
			$after = $service->prepare_count( $workspace, $approval, $credential, $output, $catalog );
			foreach ( array( 'plan', 'pricing', 'config_id', 'enrollment_id' ) as $field ) {
				if ( $prepared[ $field ] !== $after[ $field ] ) {
					throw new \UnexpectedValueException( 'Counting context changed during delivery.' );
				}
			}
			$now = new \DateTimeImmutable( $after['prepared_at'], new \DateTimeZone( 'UTC' ) );
			AiCountPolicy::verify( $policy, $credential, $prepared['plan']['request']['model'], $now );
			$execution = array(
				'context'     => array(
					'credential_fingerprint' => $credential,
					'count_fingerprint'      => $prepared['plan']['count_fingerprint'],
					'request_fingerprint'    => $prepared['plan']['request_fingerprint'],
					'counted_at'             => $prepared['prepared_at'],
				),
				'body'        => wp_remote_retrieve_body( $response ),
				'http_status' => wp_remote_retrieve_response_code( $response ),
			);
			$verified  = AiTokenCount::verify( $prepared['plan'], $execution['context'], $execution['body'], $execution['http_status'], $credential, $prepared['pricing'], $now );
			return array(
				'state'     => 'counted',
				'execution' => $execution,
				'verified'  => $verified,
			);
		} catch ( \Throwable $error ) {
			return array( 'state' => 'unavailable' );
		}
	}
}
