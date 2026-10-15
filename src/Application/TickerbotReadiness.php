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
	 * @param int $workspace Authorized workspace.
	 * @return array Allowlisted local evidence, without secrets or capabilities.
	 */
	public function readiness( int $workspace ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace ): array {
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
				$run    = $this->db->row( 'SELECT state,cause,started_at,completed_at FROM ' . $this->db->table( 'tickerbot_runs' ) . ' WHERE workspace_id = %d AND account_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $account['id'] ) );
				$counts = $this->counts( $account );
				return array(
					'enrolled'                  => true,
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
