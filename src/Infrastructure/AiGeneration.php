<?php
/**
 * Explicit count, reserve and delivery coordination.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\AiSpending;

/** Disabled by default; no schedule or automatic publication. */
final class AiGeneration {
	/**
	 * Count approved input, reserve its verified bound and send a new request once.
	 *
	 * @param AiSpending $service Explicit owner's spending service.
	 * @param int        $workspace Workspace.
	 * @param int        $approval Exact saved approval.
	 * @param string     $key Stable operation identity preserved across retries.
	 * @param int        $output Full output-token ceiling.
	 * @param int|null   $expected_config Reviewed policy revision for owner controls.
	 * @return array State and optional request ID only.
	 * @throws \UnexpectedValueException On changed policy or conflicting retry context.
	 */
	public static function run( AiSpending $service, int $workspace, int $approval, string $key, int $output, ?int $expected_config = null ): array {
		return $service->generation_operation(
			$workspace,
			$approval,
			$key,
			$output,
			static function ( ?array $prior ) use ( $service, $workspace, $approval, $key, $output, $expected_config ): array {
				if ( $prior ) {
					if ( null !== $expected_config && $expected_config !== (int) $prior['config_id'] ) {
						throw new \UnexpectedValueException( 'Generation policy conflicts with the original request.' );
					}
					return array(
						'state'      => $prior['state'],
						'request_id' => (int) $prior['id'],
					);
				}
				if ( ! Installer::ready() || ! AiTransport::enabled() ) {
					return array( 'state' => 'disabled' );
				}
				$status = $service->status( $workspace );
				if ( null !== $expected_config && $expected_config !== $status['config_id'] ) {
					throw new \UnexpectedValueException( 'AI policy changed; check summary setup again.' );
				}
				$evidence = AiConfiguration::current( $status['model'] );
				$count    = AiCounts::run( $service, $workspace, $approval, $output, $evidence['catalog'], $evidence['count_policy'] );
				if ( 'counted' !== $count['state'] ) {
					return array( 'state' => $count['state'] );
				}
				$bound    = $count['verified']['bound'];
				$evidence = AiConfiguration::current( $status['model'] );
				$request  = $service->reserve( $workspace, hash( 'sha256', TGIT_OPENAI_API_KEY ), $key, $bound['evidence_fingerprint'], $bound['input_tokens'], $output, $approval, $evidence['catalog'], $count['execution'], $expected_config );
				$id       = (int) $request['id'];
				$evidence = AiConfiguration::current( $request['model'] );
				$result   = AiTransport::run( $service, $workspace, $id, $evidence['catalog'] );
				return array(
					'state'      => $result['state'],
					'request_id' => $id,
				);
			}
		);
	}
}
