<?php
/**
 * Workspace-bound mapping verification commands and selective receipts.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** No browser-supplied identity proof is accepted. */
trait TickerbotMapping {
	/**
	 * Read a saved asset and current mapping without provider I/O.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $asset Asset ID.
	 * @return array Local identity and latest immutable mapping.
	 */
	public function mapping_current( int $workspace, int $asset ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$row     = $this->db->object( 'assets', $workspace, $asset );
		$mapping = $this->db->row( 'SELECT id,provider_symbol,asset_class,exchange,currency,checked_at FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $asset ) );
		return array(
			'asset'   => array_intersect_key( $row, array_flip( array( 'id', 'symbol', 'asset_class', 'exchange', 'quote_currency' ) ) ),
			'mapping' => $mapping,
		);
	}

	/**
	 * Fingerprint local identity, excluding holdings and user notes.
	 *
	 * @param array $asset Local asset.
	 * @return string Stable identity fingerprint.
	 */
	private function asset_fingerprint( array $asset ): string {
		return hash( 'sha256', wp_json_encode( array( (string) $asset['id'], $asset['symbol'], $asset['asset_class'], $asset['exchange'], $asset['quote_currency'] ) ) );
	}

	/**
	 * Bind immutable command inputs before any request can be admitted.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Resolution run.
	 * @param string $token Private lease.
	 * @param int    $asset Asset ID.
	 * @param string $symbol Canonical candidate.
	 * @param int    $expected Prior mapping ID.
	 * @return array Local identity for the server adapter.
	 * @throws \DomainException On unsupported or changed inputs.
	 */
	public function bind_resolution( int $workspace, int $run, string $token, int $asset, string $symbol, int $expected ): array {
		\GainerInteractive\IGTradingJournal\Infrastructure\TickerbotQuote::symbol( $symbol );
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $asset, $symbol, $expected ): array {
				$current = $this->lease( $workspace, $run, $token, $this->account() );
				$target  = $this->mapping_current( $workspace, $asset );
				$row     = $target['asset'];
				if ( 'resolution' !== $current['cause'] || $expected < 0 || (int) ( $target['mapping']['id'] ?? 0 ) !== $expected || ! in_array( $row['asset_class'], array( 'stock', 'etf', 'crypto' ), true ) || ( 'crypto' === $row['asset_class'] && 'USD' !== $row['quote_currency'] ) ) {
					throw new \DomainException( 'Asset is unsupported or its mapping changed; reload before verifying.' );
				}
				$id = $this->db->insert(
					'tickerbot_mapping_commands',
					array(
						'workspace_id'        => $workspace,
						'run_id'              => $run,
						'asset_id'            => $asset,
						'provider_symbol'     => $symbol,
						'expected_mapping_id' => $expected,
						'asset_fingerprint'   => $this->asset_fingerprint( $row ),
						'created_at'          => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.resolution.bind', 'tickerbot_mapping_commands', $id );
				return $row;
			}
		);
	}

	/**
	 * Recheck bound local identity before admission and after provider delivery.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $run Resolution run.
	 * @return array Bound command.
	 * @throws \DomainException On missing, stale or changed binding.
	 */
	private function guard_resolution( int $workspace, int $run ): array {
		$command = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_mapping_commands' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run ) );
		if ( ! $command ) {
			throw new \DomainException( 'Mapping command binding is missing.' );
		}
		$target = $this->mapping_current( $workspace, (int) $command['asset_id'] );
		if ( ! hash_equals( $command['asset_fingerprint'], $this->asset_fingerprint( $target['asset'] ) ) || (int) ( $target['mapping']['id'] ?? 0 ) !== (int) $command['expected_mapping_id'] ) {
			throw new \DomainException( 'Mapping identity or revision changed; reload before verifying.' );
		}
		return $command;
	}

	/**
	 * Read same-key outcome and reject rebinding, without a send capability.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param string $key Stable UUID.
	 * @param int    $asset Asset ID.
	 * @param string $symbol Canonical candidate.
	 * @param int    $expected Prior mapping ID.
	 * @return array|null Safe result or uncertainty.
	 * @throws \DomainException On conflicting operation identity.
	 */
	public function resolution_status( int $workspace, string $key, int $asset, string $symbol, int $expected ): ?array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $key, $asset, $symbol, $expected ): ?array {
				$account = $this->account();
				$run     = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_runs' ) . ' WHERE workspace_id = %d AND account_id = %d AND request_key = %s AND cause = %s', array( $workspace, $account['id'], 'resolution:' . $key, 'resolution' ) );
				if ( ! $run ) {
					return null;
				}
				$command = $this->db->row( 'SELECT asset_id,provider_symbol,expected_mapping_id FROM ' . $this->db->table( 'tickerbot_mapping_commands' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run['id'] ) );
				if ( $command && ( (int) $command['asset_id'] !== $asset || $command['provider_symbol'] !== $symbol || (int) $command['expected_mapping_id'] !== $expected ) ) {
					throw new \DomainException( 'Mapping operation identity conflicts with its saved inputs.' );
				}
				$result = $this->db->row( 'SELECT state,mapping_id,checked_at FROM ' . $this->db->table( 'tickerbot_mapping_results' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run['id'] ) );
				return $result ? $result : array( 'state' => 'uncertain' );
			}
		);
	}

	/**
	 * Commit mapping and receipt together after the counted request settles.
	 *
	 * @param int        $workspace Workspace ID.
	 * @param int        $run Resolution run.
	 * @param string     $token Private lease.
	 * @param int        $attempt Counted attempt, zero when admission was denied.
	 * @param string     $state Safe adapter result.
	 * @param array|null $identity Selective server-fetched identity.
	 * @return void
	 * @throws \DomainException On incompatible or unsettled evidence.
	 */
	public function finish_resolution( int $workspace, int $run, string $token, int $attempt, string $state, ?array $identity ): void {
		$states = array( 'mapped', 'ambiguous', 'unsupported', 'authentication', 'capability', 'monthly_quota', 'rate_limit', 'provider_error', 'http_error', 'transport_error', 'invalid_response' );
		if ( $attempt < 0 || ( $attempt ? ! in_array( $state, $states, true ) : ! in_array( $state, array( 'paused', 'budget_blocked', 'rate_limit', 'disabled' ), true ) ) ) {
			throw new \DomainException( 'Invalid resolution evidence.' );
		}
		$this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $attempt, $state, $identity ): void {
				$account = $this->account();
				$current = $this->lease( $workspace, $run, $token, $account );
				if ( 'resolution' !== $current['cause'] ) {
					throw new \DomainException( 'Resolution requires its own command.' );
				}
				$command = $this->guard_resolution( $workspace, $run );
				if ( $attempt ) {
					$row     = $this->db->object( 'tickerbot_attempts', $workspace, $attempt );
					$settled = in_array( $state, array( 'mapped', 'ambiguous' ), true ) ? 'completed' : $state;
					if ( (int) $row['run_id'] !== $run || (int) $row['account_id'] !== (int) $account['id'] || 'catalogue' !== $row['family'] || ! in_array( $row['state'], array( $settled, 'unsupported' === $state ? 'completed' : $settled ), true ) ) {
						throw new \DomainException( 'Resolution attempt is not settled for this command.' );
					}
				}
				$mapping = 'mapped' === $state && $identity ? $this->append_mapping( $workspace, (int) $command['asset_id'], $command['provider_symbol'], $identity, (int) $command['expected_mapping_id'] ) : null;
				if ( 'mapped' === $state && ! $mapping ) {
					throw new \DomainException( 'Verified identity proof is missing.' );
				}
				$id = $this->db->insert(
					'tickerbot_mapping_results',
					array(
						'workspace_id' => $workspace,
						'run_id'       => $run,
						'attempt_id'   => $attempt ? $attempt : null,
						'mapping_id'   => $mapping,
						'state'        => $state,
						'checked_at'   => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.resolution.finish', 'tickerbot_mapping_results', $id );
			}
		);
	}
}
