<?php
/**
 * Conservative shared-account capacity; never reads another portfolio's symbols.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Scalar operational forecasts are separate from immutable counted attempts. */
trait TickerbotCapacity {
	/**
	 * Read committed scalar capacity after acquiring the account lock.
	 *
	 * @param array $account Locked shared account metadata.
	 * @param int   $exclude Optional current workspace excluded from the sum.
	 * @return int Reserved normal-cycle calls across current workspace batches.
	 * @throws \RuntimeException On inconsistent/excessive capacity metadata.
	 */
	private function capacity_total( array $account, int $exclude = 0 ): int {
		// Administrative budget metadata only: never select foreign IDs, symbols or facts.
		$rows = $this->db->rows( 'SELECT projected_calls FROM ' . $this->db->table( 'tickerbot_capacity' ) . ' WHERE account_id = %d AND workspace_id <> %d LIMIT 10001 FOR UPDATE', array( $account['id'], $exclude ) );
		if ( count( $rows ) > 10000 ) {
			throw new \RuntimeException( 'Capacity enrollment exceeds the supported account bound.' );
		}
		$total = 0;
		foreach ( $rows as $row ) {
			if ( ! ctype_digit( (string) $row['projected_calls'] ) || (int) $row['projected_calls'] > 10000 ) {
				throw new \RuntimeException( 'Invalid shared capacity metadata.' );
			}
			$total += (int) $row['projected_calls'];
		}
		return $total;
	}

	/**
	 * Reserve or release this workspace's forecast inside the account transaction.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param array $account Locked account.
	 * @param int   $cadence Current explicit review ID.
	 * @param int   $calls Normal-cycle projection, zero releases future capacity.
	 * @return void
	 * @throws \DomainException On shared overcommit or changed account grouping.
	 */
	private function write_capacity( int $workspace, array $account, int $cadence, int $calls ): void {
		if ( $calls < 0 || $calls > 10000 || ( $calls > 0 && $calls + $this->capacity_total( $account, $workspace ) > (int) $account['monthly_cap'] ) ) {
			throw new \DomainException( 'Combined recurring quote projections exceed the shared account soft cap.' );
		}
		$settings = $this->settings( $workspace, $account );
		$scope    = $this->scope_selection( $workspace );
		$prior    = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_capacity' ) . ' WHERE workspace_id = %d FOR UPDATE', array( $workspace ) );
		if ( $prior && (int) $prior['account_id'] !== (int) $account['id'] ) {
			throw new \DomainException( 'Capacity cannot be moved to a different account.' );
		}
		$data = array(
			'account_id'        => (int) $account['id'],
			'cadence_id'        => $cadence,
			'settings_revision' => (int) $settings['revision'],
			'scope_revision'    => $scope['revision'],
			'projected_calls'   => $calls,
			'actor_id'          => $this->actor,
		);
		if ( $prior ) {
			$changed = false;
			foreach ( $data as $field => $value ) {
				$changed = $changed || (int) $prior[ $field ] !== $value;
			}
			if ( ! $changed ) {
				return;
			}
			$this->db->update_object( 'tickerbot_capacity', $workspace, (int) $prior['id'], $data + array( 'updated_at' => $this->time() ) );
		} else {
			$this->db->insert(
				'tickerbot_capacity',
				$data + array(
					'workspace_id' => $workspace,
					'updated_at'   => $this->time(),
				)
			);
		}
		$id = $this->db->insert(
			'tickerbot_capacity_revisions',
			$data + array(
				'workspace_id' => $workspace,
				'created_at'   => $this->time(),
			)
		);
		$this->audit( $workspace, 'tickerbot.capacity.save', 'tickerbot_capacity_revisions', $id );
	}

	/**
	 * Refresh capacity for a new recurring plan using only this workspace inventory.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param array $account Locked account.
	 * @param array $inventory Newly computed local inventory.
	 * @param int   $run Current run.
	 * @return void
	 */
	private function plan_capacity( int $workspace, array $account, array $inventory, int $run ): void {
		$link = $this->db->row( 'SELECT cadence_id FROM ' . $this->db->table( 'tickerbot_run_cadences' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run ) );
		if ( ! $link ) {
			return;
		}
		$cadence = $this->cadence( $workspace );
		if ( $cadence && 1 === (int) $cadence['enabled'] ) {
			$this->check_cadence( $workspace, (int) $cadence['id'], $account );
			$this->write_capacity( $workspace, $account, (int) $cadence['id'], $inventory['projected_monthly_attempts'] );
		}
	}
}
