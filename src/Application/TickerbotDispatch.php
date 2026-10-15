<?php
/**
 * Durable bounded Tickerbot dispatch operations, used by TickerbotStore.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** Private application orchestration; never expose lease tokens through REST. */
trait TickerbotDispatch {
	/**
	 * Freeze the complete local inventory exactly once for an active run.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Active run.
	 * @param string $token Private lease.
	 * @return int Immutable plan ID.
	 * @throws \DomainException When the configured quote projection exceeds budget.
	 */
	public function prepare_dispatch( int $workspace, int $run, string $token ): int {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token ): int {
				$account = $this->account();
				$current = $this->lease( $workspace, $run, $token, $account );
				if ( 'connection' === $current['cause'] ) {
					throw new \DomainException( 'Connection commands cannot prepare quote plans.' );
				}
				$this->guard_run_cadence( $workspace, $run, $account );
				$prior = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_plans' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run ) );
				if ( $prior ) {
					return (int) $prior['id'];
				}
				$settings  = $this->settings( $workspace, $account );
				$inventory = $this->inventory( $workspace );
				$this->plan_capacity( $workspace, $account, $inventory, $run );
				if ( 'scheduled' === $current['cause'] && $inventory['projected_monthly_attempts'] > (int) $account['monthly_cap'] ) {
					throw new \DomainException( 'Eligible quote projection exceeds the account soft cap.' );
				}
				$id = $this->db->insert(
					'tickerbot_plans',
					array(
						'workspace_id'      => $workspace,
						'run_id'            => $run,
						'settings_revision' => $settings['revision'],
						'scope_revision'    => $inventory['scope_revision'],
						'inventory_json'    => wp_json_encode( $inventory['items'] ),
						'created_at'        => $this->time(),
					)
				);
				foreach ( $inventory['batches'] as $ordinal => $symbols ) {
					$mappings = array();
					foreach ( $inventory['items'] as $item ) {
						if ( 'mapped' === $item['state'] && in_array( $item['provider_symbol'], $symbols, true ) ) {
							$mappings[] = $item['mapping_id'];
						}
					}
					$this->db->insert(
						'tickerbot_batches',
						array(
							'workspace_id'  => $workspace,
							'run_id'        => $run,
							'plan_id'       => $id,
							'ordinal'       => $ordinal + 1,
							'symbols_json'  => wp_json_encode( $symbols ),
							'mappings_json' => wp_json_encode( $mappings ),
							'state'         => 'pending',
							'next_due_at'   => $this->time(),
						)
					);
				}
				$this->audit( $workspace, 'tickerbot.plan.prepare', 'tickerbot_plans', $id );
				return $id;
			}
		);
	}

	/**
	 * Validate the saved intent and current mappings immediately before dispatch.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param array $batch Saved batch.
	 * @param array $account Locked account.
	 * @return array Local identity rows only; no quantities or journal text.
	 * @throws \DomainException On superseded intent or identities.
	 */
	private function dispatch_inputs( int $workspace, array $batch, array $account ): array {
		$this->guard_run_cadence( $workspace, (int) $batch['run_id'], $account );
		$plan     = $this->db->object( 'tickerbot_plans', $workspace, (int) $batch['plan_id'] );
		$settings = $this->settings( $workspace, $account );
		$scope    = $this->scope_selection( $workspace );
		if ( (int) $plan['run_id'] !== (int) $batch['run_id'] || (int) $settings['revision'] !== (int) $plan['settings_revision'] || $scope['revision'] !== (int) $plan['scope_revision'] ) {
			throw new \DomainException( 'Refresh intent changed; a new reviewed run is required.' );
		}
		$rows = array();
		foreach ( json_decode( $batch['mappings_json'], true, 512, JSON_THROW_ON_ERROR ) as $id ) {
			$map    = $this->db->object( 'tickerbot_mappings', $workspace, (int) $id );
			$asset  = $this->db->object( 'assets', $workspace, (int) $map['asset_id'] );
			$latest = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $map['asset_id'] ) );
			$entry  = TickerbotInventory::entry( $asset, $map, array() );
			if ( (int) $latest['id'] !== (int) $id || 'mapped' !== $entry['state'] ) {
				throw new \DomainException( 'Batch mapping changed; no request was admitted.' );
			}
			$asset['id']              = (int) $asset['id'];
			$asset['mapping_id']      = (int) $id;
			$asset['provider_symbol'] = $map['provider_symbol'];
			// Only stable identity leaves the application boundary.
			$rows[] = array_intersect_key( $asset, array_flip( array( 'id', 'mapping_id', 'symbol', 'asset_class', 'exchange', 'quote_currency', 'provider_symbol' ) ) );
		}
		return $rows;
	}

	/**
	 * Inspect one next batch, without reserving requests early or resending crashes.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Active run.
	 * @param string $token Private lease.
	 * @return array Internal dispatcher inputs or a safe wait/terminal state.
	 */
	public function next_dispatch( int $workspace, int $run, string $token ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token ): array {
				$account = $this->account();
				$this->lease( $workspace, $run, $token, $account );
				$busy = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_batches' ) . ' WHERE workspace_id = %d AND run_id = %d AND state = %s LIMIT 1 FOR UPDATE', array( $workspace, $run, 'dispatching' ) );
				if ( $busy ) {
					return array( 'state' => 'in_flight' );
				}
				$batch = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_batches' ) . ' WHERE workspace_id = %d AND run_id = %d AND state IN (%s,%s) ORDER BY ordinal LIMIT 1 FOR UPDATE', array( $workspace, $run, 'pending', 'waiting' ) );
				if ( ! $batch ) {
					$failed = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_batches' ) . ' WHERE workspace_id = %d AND run_id = %d AND state = %s LIMIT 1', array( $workspace, $run, 'failed' ) );
					return array( 'state' => $failed ? 'failed' : 'completed' );
				}
				if ( $batch['next_due_at'] > $this->time() ) {
					return array(
						'state'       => 'waiting',
						'next_due_at' => $batch['next_due_at'],
					);
				}
				return array(
					'state'    => 'ready',
					'batch_id' => (int) $batch['id'],
					'symbols'  => json_decode( $batch['symbols_json'], true, 512, JSON_THROW_ON_ERROR ),
					'mappings' => $this->dispatch_inputs( $workspace, $batch, $account ),
				);
			}
		);
	}
	/**
	 * Settle a bound batch in the same transaction as its attempt outcome.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Run ID.
	 * @param int    $attempt Attempt ID.
	 * @param string $state Safe provider outcome.
	 * @param array  $issues Selective per-asset safe issue codes.
	 * @return void
	 * @throws \DomainException On a mismatched active dispatch.
	 */
	private function settle_dispatch( int $workspace, int $run, int $attempt, string $state, array $issues ): void {
		$link = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_batch_attempts' ) . ' WHERE workspace_id = %d AND attempt_id = %d', array( $workspace, $attempt ) );
		if ( ! $link ) {
			return;
		}
		$batch = $this->db->object( 'tickerbot_batches', $workspace, (int) $link['batch_id'] );
		if ( (int) $link['run_id'] !== $run || (int) $batch['run_id'] !== $run || (int) $batch['active_attempt_id'] !== $attempt || 'dispatching' !== $batch['state'] || (int) $batch['attempts'] !== (int) $link['ordinal'] ) {
			throw new \DomainException( 'Batch attempt is no longer active.' );
		}

		if ( 1 > (int) $batch['attempts'] || 3 < (int) $batch['attempts'] ) {
			throw new \DomainException( 'Batch attempt count is invalid.' );
		}
		if ( 'completed' === $state ) {
			$plan        = $this->db->object( 'tickerbot_plans', $workspace, (int) $batch['plan_id'] );
			$entries     = json_decode( $plan['inventory_json'], true, 512, JSON_THROW_ON_ERROR );
			$mapping_ids = json_decode( $batch['mappings_json'], true, 512, JSON_THROW_ON_ERROR );
			$received    = array_map( 'intval', array_column( $this->db->rows( 'SELECT mapping_id FROM ' . $this->db->table( 'tickerbot_quote_receipts' ) . ' WHERE workspace_id = %d AND attempt_id = %d', array( $workspace, $attempt ) ), 'mapping_id' ) );
			$assets      = array();
			foreach ( $entries as $entry ) {
				if ( in_array( $entry['mapping_id'], $mapping_ids, true ) ) {
					$assets[] = $entry['asset_id'];
					$receipt  = in_array( $entry['mapping_id'], $received, true );
					if ( isset( $issues[ $entry['asset_id'] ] ) === $receipt ) {
						throw new \DomainException( 'Each completed batch asset needs exactly one receipt or issue.' );
					}
				}
			}
			if ( array_diff( array_keys( $issues ), $assets ) ) {
				throw new \DomainException( 'Batch issue does not belong to a requested asset.' );
			}
		}
		$retry = in_array( $state, array( 'transport_error', 'provider_error' ), true ) && (int) $batch['attempts'] < 3;
		$delay = 15 * ( 1 << ( (int) $batch['attempts'] - 1 ) ) + ( hexdec( substr( hash( 'sha256', 'tickerbot-backoff:' . $attempt ), 0, 4 ) ) % 6 );
		$this->db->update_object(
			'tickerbot_batches',
			$workspace,
			(int) $batch['id'],
			array(
				'state'        => $retry ? 'waiting' : ( 'completed' === $state && ! $issues ? 'completed' : 'failed' ),
				'next_due_at'  => $retry ? $this->now->modify( '+' . $delay . ' seconds' )->format( 'Y-m-d H:i:s' ) : $this->time(),
				'outcome_json' => wp_json_encode(
					array(
						'state'  => $state,
						'issues' => $issues,
					)
				),
			)
		);
		$this->audit( $workspace, 'tickerbot.batch.finish', 'tickerbot_batches', (int) $batch['id'] );
	}
}
