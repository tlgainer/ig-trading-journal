<?php
/**
 * Immutable mapping-bound coverage checks, separate from quote observations.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotCoverageSummary;

/** Counts and null flags are metadata, never a usable price proof. */
trait TickerbotCoverage {
	/**
	 * Require the latest mapping to match the current local identity.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $asset Asset ID.
	 * @param int $mapping Reviewed mapping ID.
	 * @return array Mapping and asset rows for trusted orchestration.
	 * @throws \DomainException On missing/stale identity.
	 */
	public function coverage_target( int $workspace, int $asset, int $mapping ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$row    = $this->db->object( 'assets', $workspace, $asset );
		$map    = $this->db->object( 'tickerbot_mappings', $workspace, $mapping );
		$latest = $this->mapping_current( $workspace, $asset );
		if ( (int) $map['asset_id'] !== $asset || (int) ( $latest['mapping']['id'] ?? 0 ) !== $mapping || 'mapped' !== TickerbotInventory::entry( $row, $map, array() )['state'] ) {
			throw new \DomainException( 'Verify the current asset mapping before checking coverage.' ); }
		return array(
			'asset'   => $row,
			'mapping' => $map,
		);
	}

	/**
	 * Bind a coverage command before request admission.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Coverage run.
	 * @param string $token Private lease.
	 * @param int    $asset Asset ID.
	 * @param int    $mapping Reviewed mapping ID.
	 * @return string Canonical provider symbol.
	 * @throws \DomainException On wrong command purpose.
	 */
	public function bind_coverage( int $workspace, int $run, string $token, int $asset, int $mapping ): string {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $asset, $mapping ): string {
				$current = $this->lease( $workspace, $run, $token, $this->account() );
				if ( 'coverage' !== $current['cause'] ) {
					throw new \DomainException( 'Coverage requires its own command.' ); }
				$target = $this->coverage_target( $workspace, $asset, $mapping );
				$id     = $this->db->insert(
					'tickerbot_coverage_commands',
					array(
						'workspace_id'      => $workspace,
						'run_id'            => $run,
						'asset_id'          => $asset,
						'mapping_id'        => $mapping,
						'asset_fingerprint' => $this->asset_fingerprint( $target['asset'] ),
						'created_at'        => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.coverage.bind', 'tickerbot_coverage_commands', $id );
				return $target['mapping']['provider_symbol'];
			}
		);
	}

	/**
	 * Recheck identity and mapping revision at admission and settlement.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $run Coverage run.
	 * @return array Bound command.
	 * @throws \DomainException On changed identity.
	 */
	private function guard_coverage( int $workspace, int $run ): array {
		$command = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_coverage_commands' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run ) );
		if ( ! $command ) {
			throw new \DomainException( 'Coverage command binding is missing.' ); }
		$target = $this->coverage_target( $workspace, (int) $command['asset_id'], (int) $command['mapping_id'] );
		if ( ! hash_equals( $command['asset_fingerprint'], $this->asset_fingerprint( $target['asset'] ) ) ) {
			throw new \DomainException( 'Coverage asset identity changed.' ); }
		return $command;
	}

	/**
	 * Decode only the bounded selective metadata stored by this module.
	 *
	 * @param array $row Selective receipt row.
	 * @return array Safe browser receipt.
	 * @throws \RuntimeException On excessive stored metadata.
	 */
	private function coverage_view( array $row ): array {
		if ( ( 'covered' === $row['state'] ) !== ( null !== $row['summary_json'] ) ) {
			throw new \RuntimeException( 'Incomplete stored coverage evidence.' ); }
		if ( isset( $row['summary_json'] ) && strlen( $row['summary_json'] ) > 1024 ) {
			throw new \RuntimeException( 'Invalid stored coverage metadata.' ); }
		$row['summary'] = null === $row['summary_json'] ? null : TickerbotCoverageSummary::validate( json_decode( $row['summary_json'], true, 16, JSON_THROW_ON_ERROR ) );
		unset( $row['summary_json'] );
		return $row;
	}

	/**
	 * Read fixed-input operation evidence without acquiring a send capability.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param string $key Stable UUID.
	 * @param int    $asset Asset ID.
	 * @param int    $mapping Reviewed mapping ID.
	 * @return array|null Safe outcome or uncertainty.
	 * @throws \DomainException On command rebinding.
	 */
	public function coverage_status( int $workspace, string $key, int $asset, int $mapping ): ?array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $key, $asset, $mapping ): ?array {
				$account = $this->account();
				$run     = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_runs' ) . ' WHERE workspace_id = %d AND account_id = %d AND request_key = %s AND cause = %s', array( $workspace, $account['id'], 'coverage:' . $key, 'coverage' ) );
				if ( ! $run ) {
					return null; }
				$command = $this->db->row( 'SELECT asset_id,mapping_id FROM ' . $this->db->table( 'tickerbot_coverage_commands' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run['id'] ) );
				if ( $command && ( (int) $command['asset_id'] !== $asset || (int) $command['mapping_id'] !== $mapping ) ) {
					throw new \DomainException( 'Coverage operation conflicts with its saved inputs.' ); }
				$row = $this->db->row( 'SELECT state,checked_at,summary_json FROM ' . $this->db->table( 'tickerbot_coverage_results' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run['id'] ) );
				return $row ? $this->coverage_view( $row ) : array( 'state' => 'uncertain' );
			}
		);
	}

	/**
	 * Read the latest saved coverage check for this workspace/asset/account.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $asset Asset ID.
	 * @return array Local mapping and saved selective outcome.
	 */
	public function coverage_current( int $workspace, int $asset ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $asset ): array {
				$account            = $this->account();
				$target             = $this->mapping_current( $workspace, $asset );
				$target['eligible'] = false;
				if ( $target['mapping'] ) {
					try {
						$this->coverage_target( $workspace, $asset, (int) $target['mapping']['id'] );
						$target['eligible'] = true; } catch ( \DomainException $error ) {
						$target['eligible'] = false; }
				}
				$row                = $this->db->row( 'SELECT c.mapping_id,r.state,r.checked_at,r.summary_json FROM ' . $this->db->table( 'tickerbot_coverage_results' ) . ' r INNER JOIN ' . $this->db->table( 'tickerbot_coverage_commands' ) . ' c ON c.workspace_id = r.workspace_id AND c.run_id = r.run_id INNER JOIN ' . $this->db->table( 'tickerbot_runs' ) . ' u ON u.workspace_id = r.workspace_id AND u.id = r.run_id WHERE r.workspace_id = %d AND c.asset_id = %d AND u.account_id = %d ORDER BY r.id DESC LIMIT 1', array( $workspace, $asset, $account['id'] ) );
				$target['coverage'] = $row ? $this->coverage_view( $row ) : null;
				if ( $row ) {
					try {
						$this->coverage_target( $workspace, $asset, (int) $row['mapping_id'] );
						$target['coverage']['matches_current_mapping'] = true; } catch ( \DomainException $error ) {
										$target['coverage']['matches_current_mapping'] = false; }
				}
				return $target;
			}
		);
	}

	/**
	 * Append a selective receipt only after the counted attempt settles.
	 *
	 * @param int        $workspace Workspace ID.
	 * @param int        $run Coverage run.
	 * @param string     $token Private lease.
	 * @param int        $attempt Counted attempt, zero if admission was denied.
	 * @param string     $state Safe adapter result.
	 * @param array|null $summary Selective normalized metadata.
	 * @return void
	 * @throws \DomainException On invalid or unsettled evidence.
	 */
	public function finish_coverage( int $workspace, int $run, string $token, int $attempt, string $state, ?array $summary ): void {
		$states = array( 'covered', 'unsupported', 'authentication', 'capability', 'monthly_quota', 'rate_limit', 'provider_error', 'http_error', 'transport_error', 'invalid_response' );
		if ( $attempt < 0 || ( $attempt ? ! in_array( $state, $states, true ) : ! in_array( $state, array( 'paused', 'budget_blocked', 'rate_limit', 'disabled' ), true ) ) || ( 'covered' === $state ) !== ( null !== $summary ) ) {
			throw new \DomainException( 'Invalid coverage evidence.' ); }
		$this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $attempt, $state, $summary ): void {
				$account = $this->account();
				$current = $this->lease( $workspace, $run, $token, $account );
				if ( 'coverage' !== $current['cause'] ) {
					throw new \DomainException( 'Coverage requires its own command.' ); }
				$this->guard_coverage( $workspace, $run );
				if ( $attempt ) {
					$row = $this->db->object( 'tickerbot_attempts', $workspace, $attempt );
					if ( (int) $row['run_id'] !== $run || (int) $row['account_id'] !== (int) $account['id'] || 'coverage' !== $row['family'] || ( 'covered' === $state ? 'completed' : $state ) !== $row['state'] ) {
						throw new \DomainException( 'Coverage attempt is not settled for this command.' ); }
				}
				if ( null !== $summary ) {
					$summary = TickerbotCoverageSummary::validate( $summary );
					$this->mark_time( $summary['as_of'] ); }
				$id = $this->db->insert(
					'tickerbot_coverage_results',
					array(
						'workspace_id' => $workspace,
						'run_id'       => $run,
						'attempt_id'   => $attempt ? $attempt : null,
						'state'        => $state,
						'summary_json' => null === $summary ? null : wp_json_encode( $summary ),
						'checked_at'   => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.coverage.finish', 'tickerbot_coverage_results', $id );
			}
		);
	}
}
