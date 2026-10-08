<?php
/**
 * Read-only preparation for an exact approved AI evidence bundle.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\AiConfiguration;
use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** No counting, reservation, enablement or delivery. */
final class AiPreflight {
	/**
	 * Describe blockers without exposing approved bytes or server credentials.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Explicit owner.
	 * @param string   $correlation Audit context.
	 * @param int      $workspace Workspace.
	 * @param int      $approval Exact approval.
	 * @return array Advisory preparation only; never authorization to send.
	 */
	public static function read( Database $db, int $actor, string $correlation, int $workspace, int $approval ): array {
		$approved  = ( new AiEvidencePreview( $db, $actor, $correlation ) )->approved( $workspace, $approval );
		$source    = $db->object( 'ai_evidence_bundles', $workspace, $approval );
		$status    = ( new AiSpending( $db, $actor, $correlation ) )->status( $workspace );
		$readiness = AiConfiguration::readiness( $status['model'] );
		$blockers  = array();
		$checks    = array(
			'original_owner'         => (int) $source['actor_id'] === $actor,
			'credential_configured'  => $readiness['credential_configured'],
			'model_evidence_current' => $readiness['model_evidence_current'],
			'count_evidence_current' => $readiness['count_evidence_current'],
			'server_enabled'         => $readiness['server_enabled'],
			'policy_enabled'         => $status['enabled'],
			'workspace_consent'      => $status['enrolled'],
			'budget_available'       => ! $status['overrun'] && Decimal::compare( $status['remaining'], '0' ) > 0,
			'workflow_available'     => false,
		);
		foreach ( $checks as $check => $passed ) {
			if ( ! $passed ) {
				$blockers[] = $check;
			}
		}
		return array(
			'approval_id'  => $approved['id'],
			'asset_id'     => $approved['asset_id'],
			'model'        => $status['model'],
			'remaining'    => $status['remaining'],
			'checks'       => $checks,
			'blockers'     => $blockers,
			'can_generate' => false,
		);
	}
}
