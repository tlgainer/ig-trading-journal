<?php
/**
 * Read-only saved dispatch and cached-mark diagnostics.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Domain\TickerbotAge;

/** Reads never dispatch, renew leases, refund usage or switch valuation sources. */
trait TickerbotDiagnostics {
	/**
	 * Read the current workspace/account quote run and complete saved-scope cache.
	 *
	 * @param int $workspace Explicit owner workspace.
	 * @return array Safe saved metadata; no credentials or lease capabilities.
	 */
	public function diagnostics( int $workspace ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace ): array {
				$account   = $this->account();
				$settings  = $this->settings( $workspace, $account );
				$inventory = $this->inventory( $workspace );
				$run       = $this->db->row( 'SELECT id,state,cause,started_at,completed_at,lease_until FROM ' . $this->db->table( 'tickerbot_runs' ) . ' WHERE workspace_id=%d AND account_id=%d AND cause IN (%s,%s,%s) ORDER BY id DESC LIMIT 1', array( $workspace, $account['id'], 'manual', 'scheduled', 'selected' ) );
				$batches   = array();
				$attempts  = 0;
				$planned   = false;
				if ( $run ) {
					$frozen  = $this->db->row( 'SELECT id,inventory_json FROM ' . $this->db->table( 'tickerbot_plans' ) . ' WHERE workspace_id=%d AND run_id=%d', array( $workspace, $run['id'] ) );
					$planned = (bool) $frozen;
					if ( $frozen && 'selected' === $run['cause'] ) {
						$existing = array_column( $inventory['items'], null, 'asset_id' );
						foreach ( json_decode( $frozen['inventory_json'], true, 512, JSON_THROW_ON_ERROR ) as $item ) {
							if ( ! isset( $existing[ $item['asset_id'] ] ) ) {
								$asset                = $this->db->object( 'assets', $workspace, (int) $item['asset_id'] );
								$mapping              = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id=%d AND asset_id=%d ORDER BY id DESC LIMIT 1', array( $workspace, $item['asset_id'] ) );
								$inventory['items'][] = TickerbotInventory::entry( $asset, $mapping, array( 'manual_selection' ) ); }
						}
					}
					$rows = $this->db->rows( 'SELECT id,ordinal,state,attempts,next_due_at,outcome_json FROM ' . $this->db->table( 'tickerbot_batches' ) . ' WHERE workspace_id=%d AND run_id=%d ORDER BY ordinal LIMIT 201', array( $workspace, $run['id'] ) );
					if ( count( $rows ) > 200 ) {
						throw new \DomainException( 'Saved batch plan exceeds supported bounds; no partial progress is shown.' );}
					foreach ( $rows as $row ) {
						$outcome = $row['outcome_json'] ? json_decode( $row['outcome_json'], true ) : null;
						$issues  = array();
						foreach ( ( is_array( $outcome['issues'] ?? null ) ? $outcome['issues'] : array() ) as $code ) {
							$safe            = in_array( $code, array( 'unsupported', 'identity_mismatch', 'invalid_quote' ), true ) ? $code : 'other';
							$issues[ $safe ] = ( $issues[ $safe ] ?? 0 ) + 1;}
						$received  = $this->db->row( 'SELECT COUNT(DISTINCT r.mapping_id) AS total FROM ' . $this->db->table( 'tickerbot_batch_attempts' ) . ' l JOIN ' . $this->db->table( 'tickerbot_quote_receipts' ) . ' r ON r.workspace_id=l.workspace_id AND r.attempt_id=l.attempt_id JOIN ' . $this->db->table( 'tickerbot_attempts' ) . ' a ON a.workspace_id=l.workspace_id AND a.id=l.attempt_id AND a.run_id=l.run_id WHERE l.workspace_id=%d AND l.run_id=%d AND l.batch_id=%d AND a.account_id=%d', array( $workspace, $run['id'], $row['id'], $account['id'] ) );
						$batches[] = array(
							'ordinal'         => (int) $row['ordinal'],
							'state'           => $row['state'],
							'attempts'        => (int) $row['attempts'],
							'next_due_at'     => $row['next_due_at'],
							'received_assets' => (int) $received['total'],
							'issues'          => $issues,
							'outcome'         => in_array( $outcome['state'] ?? null, array( 'completed', 'transport_error', 'provider_error', 'authentication', 'capability', 'monthly_quota', 'rate_limit', 'invalid_response', 'unsupported' ), true ) ? $outcome['state'] : null,
						);
					}
					$count               = $this->db->row( 'SELECT COUNT(*) AS total FROM ' . $this->db->table( 'tickerbot_attempts' ) . ' WHERE workspace_id=%d AND account_id=%d AND run_id=%d', array( $workspace, $account['id'], $run['id'] ) );
					$attempts            = (int) $count['total'];
					$run['lease_status'] = 'running' !== $run['state'] ? 'terminal' : ( $run['lease_until'] > $this->time() ? 'active' : 'expired' );
					unset( $run['id'], $run['lease_until'] );
				}
				$marks = array();
				foreach ( $inventory['items'] as $item ) {
					$quote = 'mapped' === $item['state'] ? $this->latest_quote( $workspace, $item['asset_id'] ) : null;
					$mark  = null;
					if ( $quote ) {
						$mark        = array_intersect_key( $quote, array_flip( array( 'price', 'currency', 'price_source', 'source_time_semantics', 'price_asof', 'response_as_of', 'last_received_at' ) ) );
						$mark['age'] = TickerbotAge::inspect( $quote['price_asof'], $this->now, (int) $settings['interval_seconds'] );
					}
					$marks[] = array(
						'asset_id'      => $item['asset_id'],
						'symbol'        => $item['symbol'],
						'mapping_state' => $item['state'],
						'mark'          => $mark,
					);
				}
				return array(
					'read_at'          => $this->time(),
					'interval_seconds' => (int) $settings['interval_seconds'],
					'run'              => $run,
					'plan_saved'       => $planned,
					'counted_attempts' => $attempts,
					'batches'          => $batches,
					'marks'            => $marks,
					'complete'         => true,
				);
			}
		);
	}
}
