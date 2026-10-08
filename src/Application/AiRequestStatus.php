<?php
/**
 * Read-only recovery of a saved AI operation identity.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;

/** No count, reservation, dispatch, enablement or external HTTP. */
final class AiRequestStatus {
	/**
	 * Find an original owner's request using its retained operation UUID.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Explicit owner.
	 * @param string   $correlation Context.
	 * @param int      $workspace Workspace.
	 * @param int      $approval Saved approval.
	 * @param string   $key Original operation UUID.
	 * @return array Lifecycle state and optional request ID only.
	 * @throws \UnexpectedValueException On a substituted authorizer or identity.
	 * @throws \RuntimeException On a persistence or unexpected coordination failure.
	 */
	public static function read( Database $db, int $actor, string $correlation, int $workspace, int $approval, string $key ): array {
		( new AiEvidencePreview( $db, $actor, $correlation ) )->approved( $workspace, $approval );
		$source = $db->object( 'ai_evidence_bundles', $workspace, $approval );
		if ( (int) $source['actor_id'] !== $actor ) {
			throw new \UnexpectedValueException( 'Saved operation recovery requires the original approving owner.' );
		}
		$spending = new AiSpending( $db, $actor, $correlation );
		try {
			return $spending->generation_operation(
				$workspace,
				$approval,
				$key,
				2000,
				static fn( ?array $prior ): array => $prior ? array(
					'state'      => $prior['state'],
					'request_id' => (int) $prior['id'],
				) : array( 'state' => 'not_reserved' )
			);
		} catch ( \RuntimeException $error ) {
			if ( 'AI generation is already in progress.' === $error->getMessage() ) {
				return array( 'state' => 'in_progress' );
			}
			throw $error;
		}
	}
}
