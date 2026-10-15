<?php
/**
 * Immutable connection evidence, separate from quote observations.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

/** The command creator alone can record a settled connection outcome. */
trait TickerbotConnection {
	/**
	 * Look up an operation without acquiring a lease, including with transport off.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param string $key Stable operation identity.
	 * @return array|null Safe recorded result or uncertainty.
	 */
	public function connection_status( int $workspace, string $key ): ?array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $key ): ?array {
				$account = $this->account();
				$row     = $this->db->row( 'SELECT c.state,c.checked_at FROM ' . $this->db->table( 'tickerbot_runs' ) . ' r LEFT JOIN ' . $this->db->table( 'tickerbot_connections' ) . ' c ON c.workspace_id = r.workspace_id AND c.run_id = r.id WHERE r.workspace_id = %d AND r.account_id = %d AND r.request_key = %s AND r.cause = %s', array( $workspace, $account['id'], 'connection:' . $key, 'connection' ) );
				return $row ? ( $row['state'] ? $row : array( 'state' => 'uncertain' ) ) : null;
			}
		);
	}
	/**
	 * Append a selective receipt after the counted attempt has settled.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Connection run ID.
	 * @param string $token Private lease.
	 * @param int    $attempt Counted attempt, zero if admission was denied.
	 * @param string $state Safe provider/admission result.
	 * @param string $digest Server credential fingerprint, never browser input.
	 * @return void
	 * @throws \DomainException On invalid or conflicting evidence.
	 */
	public function record_connection( int $workspace, int $run, string $token, int $attempt, string $state, string $digest ): void {
		$settled = array(
			'connected'        => 'completed',
			'authentication'   => 'authentication',
			'capability'       => 'capability',
			'monthly_quota'    => 'monthly_quota',
			'rate_limit'       => 'rate_limit',
			'unsupported'      => 'unsupported',
			'provider_error'   => 'provider_error',
			'http_error'       => 'http_error',
			'transport_error'  => 'transport_error',
			'invalid_response' => 'invalid_response',
		);
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $digest ) || $attempt < 0 || ( $attempt ? ! isset( $settled[ $state ] ) : ! in_array( $state, array( 'paused', 'budget_blocked', 'rate_limit', 'disabled' ), true ) ) ) {
			throw new \DomainException( 'Invalid connection evidence.' );
		}
		$this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $attempt, $state, $digest, $settled ): void {
				$account = $this->account();
				$current = $this->lease( $workspace, $run, $token, $account );
				if ( 'connection' !== $current['cause'] ) {
					throw new \DomainException( 'Connection evidence requires its own command.' );
				}
				if ( $attempt ) {
					$row = $this->db->object( 'tickerbot_attempts', $workspace, $attempt );
					if ( (int) $row['run_id'] !== $run || (int) $row['account_id'] !== (int) $account['id'] || 'connection' !== $row['family'] || $row['state'] !== $settled[ $state ] ) {
						throw new \DomainException( 'Connection attempt is not settled for this command.' );
					}
				}
				$id = $this->db->insert(
					'tickerbot_connections',
					array(
						'workspace_id'      => $workspace,
						'run_id'            => $run,
						'attempt_id'        => $attempt ? $attempt : null,
						'credential_digest' => $digest,
						'state'             => $state,
						'checked_at'        => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.connection.record', 'tickerbot_connections', $id );
			}
		);
	}

	/**
	 * Read same-operation evidence without acquiring a send capability.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $run Connection run.
	 * @return array Safe outcome; absent evidence is never a success.
	 */
	public function connection_outcome( int $workspace, int $run ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run ): array {
				$account = $this->account();
				$row     = $this->db->object( 'tickerbot_runs', $workspace, $run );
				if ( 'connection' !== $row['cause'] || (int) $row['account_id'] !== (int) $account['id'] ) {
					return array( 'state' => 'busy' );
				}
				$receipt = $this->db->row( 'SELECT state,checked_at FROM ' . $this->db->table( 'tickerbot_connections' ) . ' WHERE workspace_id = %d AND run_id = %d', array( $workspace, $run ) );
				return $receipt ? $receipt : array( 'state' => 'uncertain' );
			}
		);
	}
}
