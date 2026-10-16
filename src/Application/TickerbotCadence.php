<?php
/**
 * Explicit recurring intent and fenced recovery for TickerbotStore.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Domain\TickerbotSchedule;

/** No HTTP, cron mutation, implicit enrollment or customer queries across workspaces. */
trait TickerbotCadence {
	/**
	 * Save explicit recurring intent without queueing or enabling server processing.
	 *
	 * @param int      $workspace Workspace ID.
	 * @param bool     $enabled Owner opt-in.
	 * @param int      $expected_revision Last reviewed revision, zero initially.
	 * @param int|null $expected_settings Reviewed settings revision for owner commands.
	 * @param int|null $expected_scope Reviewed scope revision for owner commands.
	 * @return array Saved revision; no lease capabilities.
	 * @throws \UnexpectedValueException On stale review.
	 */
	public function save_cadence( int $workspace, bool $enabled, int $expected_revision, ?int $expected_settings = null, ?int $expected_scope = null ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $enabled, $expected_revision, $expected_settings, $expected_scope ): array {
				$account  = $this->account();
				$settings = $this->settings( $workspace, $account );
				$prior    = $this->cadence( $workspace );
				if ( $expected_revision < 0 || (int) ( $prior['revision'] ?? 0 ) !== $expected_revision ) {
					throw new \UnexpectedValueException( 'Recurring enrollment changed; reload before saving.' );
				}
				$scope = $this->scope_selection( $workspace );
				if ( ( null !== $expected_settings && $expected_settings !== (int) $settings['revision'] ) || ( null !== $expected_scope && $expected_scope !== $scope['revision'] ) ) {
					throw new \UnexpectedValueException( 'Settings or asset selection changed; reload before saving recurring intent.' );
				}
				$id        = $this->db->insert(
					'tickerbot_cadences',
					array(
						'workspace_id'      => $workspace,
						'revision'          => $expected_revision + 1,
						'enabled'           => $enabled ? 1 : 0,
						'actor_id'          => $this->actor,
						'settings_revision' => $settings['revision'],
						'scope_revision'    => $scope['revision'],
						'created_at'        => $this->time(),
					)
				);
				$inventory = $enabled ? $this->inventory( $workspace ) : null;
				$this->write_capacity( $workspace, $account, $id, $inventory ? $inventory['projected_monthly_attempts'] : 0 );
				$this->audit( $workspace, 'tickerbot.cadence.save', 'tickerbot_cadences', $id );
				return array(
					'id'       => $id,
					'revision' => $expected_revision + 1,
					'enabled'  => $enabled,
				);
			}
		);
	}

	/**
	 * Read the latest explicit enrollment; missing rows never imply consent.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array|null Scoped enrollment.
	 */
	public function cadence( int $workspace ): ?array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_cadences' ) . ' WHERE workspace_id = %d ORDER BY revision DESC LIMIT 1', array( $workspace ) );
	}

	/**
	 * Validate the original owner and the reviewed configuration before scheduling.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param int   $cadence Explicit enrollment ID.
	 * @param array $account Locked account.
	 * @return array Current settings.
	 * @throws \DomainException On revoked or superseded enrollment.
	 */
	private function check_cadence( int $workspace, int $cadence, array $account ): array {
		$current  = $this->cadence( $workspace );
		$settings = $this->settings( $workspace, $account );
		$scope    = $this->scope_selection( $workspace );
		if ( ! $current || (int) $current['id'] !== $cadence || 1 !== (int) $current['enabled'] || (int) $current['actor_id'] !== $this->actor || (int) $current['settings_revision'] !== (int) $settings['revision'] || (int) $current['scope_revision'] !== $scope['revision'] ) {
			throw new \DomainException( 'Recurring enrollment is disabled, revoked or needs renewed review.' );
		}
		$capacity = $this->db->row( 'SELECT cadence_id,settings_revision,scope_revision FROM ' . $this->db->table( 'tickerbot_capacity' ) . ' WHERE workspace_id = %d AND account_id = %d', array( $workspace, $account['id'] ) );
		if ( ! $capacity || (int) $capacity['cadence_id'] !== $cadence || (int) $capacity['settings_revision'] !== (int) $settings['revision'] || (int) $capacity['scope_revision'] !== $scope['revision'] ) {
			throw new \DomainException( 'Recurring capacity needs renewed review.' );
		}
		return $settings;
	}

	/**
	 * Recheck a scheduled run at preparation and immediately before each admission.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param int   $run Run ID.
	 * @param array $account Locked account.
	 * @return void
	 */
	private function guard_run_cadence( int $workspace, int $run, array $account ): void {
		$link = $this->db->row( 'SELECT cadence_id FROM ' . $this->db->table( 'tickerbot_run_cadences' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run ) );
		if ( $link ) {
			$this->check_cadence( $workspace, (int) $link['cadence_id'], $account );
		}
	}

	/**
	 * Recover only saved plans; expired attempts stay counted and are never resent.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $cadence Current explicit enrollment ID.
	 * @return array Safe due/resume/wait status without private tokens.
	 */
	public function cadence_tick( int $workspace, int $cadence ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $cadence ): array {
				$account  = $this->account();
				$settings = $this->check_cadence( $workspace, $cadence, $account );
				if ( $account['active_run_id'] && (int) $account['active_workspace_id'] !== $workspace && $account['lease_until'] > $this->time() ) {
					return array( 'state' => 'busy' );
				}
				if ( $account['active_run_id'] && (int) $account['active_workspace_id'] === $workspace ) {
					$run = $this->db->object( 'tickerbot_runs', $workspace, (int) $account['active_run_id'] );
					if ( (int) $run['account_id'] !== (int) $account['id'] || 'running' !== $run['state'] || $run['lease_until'] !== $account['lease_until'] ) {
						throw new \DomainException( 'Run/account relationship is inconsistent.' );
					}
					if ( $run['lease_until'] <= $this->time() ) {
						$this->db->update_object(
							'tickerbot_runs',
							$workspace,
							(int) $run['id'],
							array(
								'state'        => 'failed',
								'completed_at' => $this->time(),
							)
						);
						$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET active_run_id = NULL, active_workspace_id = NULL, lease_until = NULL WHERE id = %d', array( $account['id'] ) );
						$next = TickerbotSchedule::next( $this->now, (int) $settings['interval_seconds'] );
						$this->db->update_object( 'tickerbot_settings', $workspace, (int) $settings['id'], array( 'next_due_at' => $next ) );
						$this->audit( $workspace, 'tickerbot.run.expire', 'tickerbot_runs', (int) $run['id'] );
						return array(
							'state'       => 'expired',
							'next_due_at' => $next,
						);
					}
					$plan    = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_plans' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run['id'] ) );
					$pending = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_attempts' ) . ' WHERE workspace_id = %d AND run_id = %d AND state = %s LIMIT 1', array( $workspace, $run['id'], 'admitted' ) );
					if ( ! $plan || $pending || (int) $run['actor_id'] !== $this->actor ) {
						return array( 'state' => 'in_flight' );
					}
					$link = $this->db->row( 'SELECT cadence_id FROM ' . $this->db->table( 'tickerbot_run_cadences' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run['id'] ) );
					if ( $link && (int) $link['cadence_id'] !== $cadence ) {
						return array( 'state' => 'in_flight' );
					}
					$this->guard_run_cadence( $workspace, (int) $run['id'], $account );
					return array(
						'state'  => 'resume',
						'run_id' => (int) $run['id'],
					);
				}
				if ( $account['paused_until'] && $account['paused_until'] > $this->time() ) {
					return array(
						'state' => 'paused',
						'until' => $account['paused_until'],
					);
				}
				$usage = $this->counts( $account );
				if ( $usage['monthly'] >= (int) $account['monthly_cap'] ) {
					return array( 'state' => 'budget_blocked' );
				}
				if ( $settings['next_due_at'] > $this->time() ) {
					return array(
						'state'       => 'not_due',
						'next_due_at' => $settings['next_due_at'],
					);
				}
				return array(
					'state' => 'due',
					'key'   => 'cadence:' . $cadence . ':' . str_replace( array( ' ', ':', '-' ), '', $settings['next_due_at'] ),
				);
			}
		);
	}
}
