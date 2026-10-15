<?php
/**
 * Read-only owner setup evidence; never creates enrollment or provider requests.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Safe projections for the owner readiness panel. */
trait TickerbotReadiness {

	/**
	 * Read saved state under the same workspace/account locks as commands.
	 *
	 * @param int    $workspace Authorized workspace.
	 * @param string $credential_digest Current trusted credential fingerprint.
	 * @return array Allowlisted local evidence, without secrets or capabilities.
	 */
	public function readiness( int $workspace, string $credential_digest = '' ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $credential_digest ): array {
				$account  = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_accounts' ) . ' WHERE scope_digest = %s FOR UPDATE', array( $this->scope ) );
				$settings = $account ? $this->db->row( 'SELECT revision,interval_seconds,next_due_at FROM ' . $this->db->table( 'tickerbot_settings' ) . ' WHERE workspace_id = %d AND account_id = %d', array( $workspace, $account['id'] ) ) : null;
				if ( ! $settings ) {
					return array( 'enrolled' => false );
				}
				$cadence = $this->cadence( $workspace );
				$review  = 'off';
				if ( $cadence && 1 === (int) $cadence['enabled'] ) {
					try {
						$this->check_cadence( $workspace, (int) $cadence['id'], $account );
						$review = 'reviewed';
					} catch ( \DomainException $error ) {
						$review = 'needs_review';
					}
				}
				$run        = $this->db->row( 'SELECT state,cause,started_at,completed_at FROM ' . $this->db->table( 'tickerbot_runs' ) . ' WHERE workspace_id = %d AND account_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $account['id'] ) );
				$counts     = $this->counts( $account );
				$connection = $this->db->row( 'SELECT c.state,c.checked_at,c.credential_digest FROM ' . $this->db->table( 'tickerbot_connections' ) . ' c INNER JOIN ' . $this->db->table( 'tickerbot_runs' ) . ' r ON r.workspace_id = c.workspace_id AND r.id = c.run_id WHERE c.workspace_id = %d AND r.account_id = %d ORDER BY c.id DESC LIMIT 1', array( $workspace, $account['id'] ) );
				if ( $connection ) {
					$connection['credential_matches'] = '' !== $credential_digest && hash_equals( $connection['credential_digest'], $credential_digest );
					unset( $connection['credential_digest'] );
				}
				return array(
					'enrolled'                  => true,
					'settings_revision'         => (int) $settings['revision'],
					'can_manage_budget'         => (int) $account['controller_workspace_id'] === $workspace,
					'connection'                => $connection,
					'interval_seconds'          => (int) $settings['interval_seconds'],
					'next_due_at'               => $settings['next_due_at'],
					'recurring_review'          => $review,
					'period'                    => $this->now->format( 'Y-m' ),
					'attempts'                  => $counts['monthly'],
					'soft_cap'                  => (int) $account['monthly_cap'],
					'projected_recurring_calls' => $this->capacity_total( $account ),
					'paused_until'              => $account['paused_until'],
					'latest_run'                => $run,
				);
			}
		);
	}
}
