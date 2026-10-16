<?php
/**
 * Durable Tickerbot control plane; no HTTP, cron registration or ledger writes.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotQuote;
use GainerInteractive\IGTradingJournal\Domain\Decimal;
use GainerInteractive\IGTradingJournal\Domain\TickerbotSchedule;

/** Shared account admission and fenced workspace operations. */
final class TickerbotStore {
	use TickerbotDispatch;
	use TickerbotCadence;
	use TickerbotCapacity;
	use TickerbotReadiness;
	use TickerbotConnection;
	use TickerbotMapping;
	use TickerbotCoverage;
	use TickerbotRecovery;
	use TickerbotControls;
	use TickerbotDiagnostics;

	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Explicit authorizing owner.
	 *
	 * @var int
	 */
	private int $actor;
	/**
	 * Audit correlation, never a credential.
	 *
	 * @var string
	 */
	private string $correlation;
	/**
	 * Stable server-supplied account grouping, independent of key rotation.
	 *
	 * @var string
	 */
	private string $scope;
	/**
	 * UTC operation clock.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $now;
	/**
	 * Deterministic test clock; production boundaries always obtain fresh UTC time.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $fixed_now;

	/**
	 * Bind trusted server context; never accept account scope from browser input.
	 *
	 * @param Database                $db Persistence.
	 * @param int                     $actor Owner.
	 * @param string                  $correlation Correlation ID.
	 * @param string                  $account_scope Server-configured stable account identifier, not the API key.
	 * @param \DateTimeImmutable|null $now Testable operation time.
	 * @throws \InvalidArgumentException On invalid scope/correlation.
	 */
	public function __construct( Database $db, int $actor, string $correlation, string $account_scope, ?\DateTimeImmutable $now = null ) {
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $account_scope ) || '' === $correlation || strlen( $correlation ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid trusted Tickerbot account context.' );
		}
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
		$this->scope       = hash( 'sha256', 'tickerbot/account/' . $account_scope );
		$this->now         = ( $now ?? new \DateTimeImmutable( 'now' ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->fixed_now   = $now ? $now->setTimezone( new \DateTimeZone( 'UTC' ) ) : null;
	}

	/**
	 * Serialize workspace changes before shared-account admission.
	 *
	 * @param int      $workspace Workspace ID.
	 * @param callable $work Atomic mutation.
	 * @return mixed Committed result.
	 * @throws \Throwable On authorization, validation or persistence failure.
	 */
	private function atomic( int $workspace, callable $work ) {
		$this->now = $this->fixed_now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$this->db->query( 'START TRANSACTION' );
		try {
			$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $workspace ) );
			( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
			$result = $work();
			$this->db->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Lock the trusted shared account; it contains no customer portfolio records.
	 *
	 * @return array Account state.
	 * @throws \DomainException On unconfigured account.
	 */
	private function account(): array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_accounts' ) . ' WHERE scope_digest = %s FOR UPDATE', array( $this->scope ) );
		if ( ! $row ) {
			throw new \DomainException( 'Configure this Tickerbot account first.' );
		}
		return $row;
	}

	/**
	 * Format the trusted clock.
	 *
	 * @return string UTC database time.
	 */
	private function time(): string {
		return $this->now->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Append transactional audit evidence without secrets or provider bodies.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param string $action Safe action.
	 * @param string $entity Trusted table.
	 * @param int    $id Record ID.
	 * @return void
	 */
	private function audit( int $workspace, string $action, string $entity, int $id ): void {
		$this->db->insert(
			'audit_events',
			array(
				'workspace_id'   => $workspace,
				'actor_id'       => $this->actor,
				'action'         => $action,
				'entity'         => $entity,
				'entity_id'      => $id,
				'revision'       => 1,
				'correlation_id' => $this->correlation,
				'created_at'     => $this->time(),
			)
		);
	}

	/**
	 * Persist explicit tracking selections without enabling requests.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param array $watchlists Enabled watchlist IDs.
	 * @param array $trades Individually opted-in planned trade IDs.
	 * @param int   $expected_revision Last reviewed scope revision, zero initially.
	 * @return array Committed revision and selections.
	 * @throws \InvalidArgumentException On malformed IDs or non-planned trades.
	 */
	public function select_scope( int $workspace, array $watchlists, array $trades, int $expected_revision ): array {
		foreach ( array( $watchlists, $trades ) as $ids ) {
			if ( count( $ids ) > 10000 || $expected_revision < 0 ) {
				throw new \InvalidArgumentException( 'Invalid Tickerbot inventory selection.' );
			}
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 ) {
					throw new \InvalidArgumentException( 'Inventory selections require positive integer IDs.' );
				}
			}
		}
		$watchlists = array_values( array_unique( $watchlists ) );
		$trades     = array_values( array_unique( $trades ) );
		sort( $watchlists, SORT_NUMERIC );
		sort( $trades, SORT_NUMERIC );
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $watchlists, $trades, $expected_revision ): array {
				$account  = $this->account();
				$settings = $this->db->row( 'SELECT account_id FROM ' . $this->db->table( 'tickerbot_settings' ) . ' WHERE workspace_id = %d FOR UPDATE', array( $workspace ) );
				if ( ! $settings || (int) $settings['account_id'] !== (int) $account['id'] ) {
					throw new \DomainException( 'Configure this workspace account before selecting inventory.' );
				}
				$current = $this->scope_selection( $workspace );
				if ( $current['revision'] !== $expected_revision ) {
					throw new \UnexpectedValueException( 'Inventory selection changed; reload before saving.' );
				}
				foreach ( $watchlists as $id ) {
					$this->db->object( 'watchlists', $workspace, $id );
				}
				foreach ( $trades as $id ) {
					$trade = $this->db->object( 'trades', $workspace, $id );
					if ( 'planned' !== $trade['state'] ) {
						throw new \InvalidArgumentException( 'Only explicitly selected planned trades can enroll.' );
					}
					$this->db->object( 'assets', $workspace, (int) $trade['asset_id'] );
				}
				$id = $this->db->insert(
					'tickerbot_scope_revisions',
					array(
						'workspace_id'    => $workspace,
						'revision'        => $expected_revision + 1,
						// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact numeric ID arrays; fail closed on encoding error.
						'watchlists_json' => json_encode( $watchlists, JSON_THROW_ON_ERROR ),
						// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact numeric ID arrays; fail closed on encoding error.
						'trades_json'     => json_encode( $trades, JSON_THROW_ON_ERROR ),
						'actor_id'        => $this->actor,
						'created_at'      => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.scope.select', 'tickerbot_scope_revisions', $id );
				return array(
					'revision'       => $expected_revision + 1,
					'watchlists'     => $watchlists,
					'trades'         => $trades,
					'worker_enabled' => false,
				);
			}
		);
	}

	/**
	 * Read the latest explicit selection; missing selection means opt-out.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array Revision and local IDs only.
	 */
	public function scope_selection( int $workspace ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_scope_revisions' ) . ' WHERE workspace_id = %d ORDER BY revision DESC LIMIT 1', array( $workspace ) );
		return array(
			'revision'   => $row ? (int) $row['revision'] : 0,
			'watchlists' => $row ? json_decode( $row['watchlists_json'], true, 512, JSON_THROW_ON_ERROR ) : array(),
			'trades'     => $row ? json_decode( $row['trades_json'], true, 512, JSON_THROW_ON_ERROR ) : array(),
		);
	}

	/**
	 * Prepare inventory from saved opt-ins and the configured elapsed cadence.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array Complete local inventory, without external requests.
	 * @throws \DomainException When the workspace has no matching enrollment.
	 */
	public function inventory( int $workspace ): array {
		$selection = $this->scope_selection( $workspace );
		$settings  = $this->db->row( 'SELECT s.interval_seconds FROM ' . $this->db->table( 'tickerbot_settings' ) . ' s INNER JOIN ' . $this->db->table( 'tickerbot_accounts' ) . ' a ON a.id = s.account_id WHERE s.workspace_id = %d AND a.scope_digest = %s', array( $workspace, $this->scope ) );
		if ( ! $settings ) {
			throw new \DomainException( 'Workspace is not enrolled in this account.' );
		}
		$inventory                   = TickerbotInventory::load( $this->db, $this->actor, $workspace, $selection['watchlists'], (int) $settings['interval_seconds'], $selection['trades'] );
		$inventory['scope_revision'] = $selection['revision'];
		return $inventory;
	}

	/**
	 * Save owner enrollment without enabling any worker or external request.
	 *
	 * @param int      $workspace Workspace ID.
	 * @param int      $cap Account monthly soft cap, zero pauses admission.
	 * @param int      $interval Elapsed seconds.
	 * @param int      $symbols Projected unique eligible symbols.
	 * @param bool     $clear_pause Explicit operator intervention, controller only.
	 * @param int|null $expected_revision Reviewed revision for owner setup commands.
	 * @return array Safe enrollment/projection.
	 * @throws \InvalidArgumentException On invalid limits or projection.
	 */
	public function configure( int $workspace, int $cap = 8000, int $interval = 7200, int $symbols = 0, bool $clear_pause = false, ?int $expected_revision = null ): array {
		$projection = TickerbotSchedule::projected( $symbols, $interval );
		if ( $cap < 0 || $cap > 10000 || ( $cap > 0 && $projection > $cap ) ) {
			throw new \InvalidArgumentException( 'Projected quote calls exceed the selected soft cap or free-tier maximum.' );
		}
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $cap, $interval, $projection, $clear_pause, $expected_revision ): array {
				$this->db->query( 'INSERT IGNORE INTO ' . $this->db->table( 'tickerbot_accounts' ) . ' (scope_digest,controller_workspace_id,monthly_cap) VALUES (%s,%d,%d)', array( $this->scope, $workspace, $cap ) );
				$account = $this->account();
				if ( (int) $account['controller_workspace_id'] !== $workspace && ( $cap !== (int) $account['monthly_cap'] || $clear_pause ) ) {
					throw new \DomainException( 'Only the controlling workspace can change the shared account budget or pause.' );
				}
				$settings = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_settings' ) . ' WHERE workspace_id = %d FOR UPDATE', array( $workspace ) );
				if ( null !== $expected_revision && ( $expected_revision < 0 || (int) ( $settings['revision'] ?? 0 ) !== $expected_revision ) ) {
					throw new \UnexpectedValueException( 'Tickerbot settings changed; reload before saving.' );
				}
				if ( $settings && (int) $settings['account_id'] !== (int) $account['id'] ) {
					throw new \DomainException( 'Account regrouping requires reviewed migration; it cannot reset usage.' );
				}
				if ( $cap > 0 && $this->capacity_total( $account ) > $cap ) {
					throw new \DomainException( 'Soft cap cannot underfund reserved recurring capacity; opt out or reduce enrolled scopes first.' );
				}
				$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET monthly_cap = %d WHERE id = %d', array( $cap, $account['id'] ) );
				if ( $clear_pause ) {
					$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET paused_until = NULL, pause_kind = NULL, recovery_not_before = NULL, pause_revision = pause_revision + 1 WHERE id = %d', array( $account['id'] ) );
				}
				$next = TickerbotSchedule::next( $this->now, $interval );
				if ( $settings ) {
					$this->db->update_object(
						'tickerbot_settings',
						$workspace,
						(int) $settings['id'],
						array(
							'interval_seconds' => $interval,
							'revision'         => (int) $settings['revision'] + 1,
							'next_due_at'      => $next,
						)
					);
					$id = (int) $settings['id'];
				} else {
					$id = $this->db->insert(
						'tickerbot_settings',
						array(
							'workspace_id'     => $workspace,
							'account_id'       => $account['id'],
							'interval_seconds' => $interval,
							'next_due_at'      => $next,
							'revision'         => 1,
						)
					);
				}
				$this->db->insert(
					'tickerbot_policy_revisions',
					array(
						'workspace_id'     => $workspace,
						'account_id'       => $account['id'],
						'actor_id'         => $this->actor,
						'interval_seconds' => $interval,
						'monthly_cap'      => $cap,
						'clear_pause'      => $clear_pause ? 1 : 0,
						'created_at'       => $this->time(),
					)
				);
				$this->audit( $workspace, 'tickerbot.configure', 'tickerbot_settings', $id );
				return array(
					'id'                    => $id,
					'projected_quote_calls' => $projection,
					'next_due_at'           => $next,
					'worker_enabled'        => false,
				);
			}
		);
	}

	/**
	 * Obtain a scoped enrollment under the already locked account.
	 *
	 * @param int   $workspace Workspace ID.
	 * @param array $account Locked account.
	 * @return array Enrollment.
	 * @throws \DomainException On missing or mismatched enrollment.
	 */
	private function settings( int $workspace, array $account ): array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_settings' ) . ' WHERE workspace_id = %d AND account_id = %d FOR UPDATE', array( $workspace, $account['id'] ) );
		if ( ! $row ) {
			throw new \DomainException( 'Workspace is not enrolled in this account.' );
		}
		return $row;
	}

	/**
	 * Claim one account-wide lease, joining only same-workspace active runs.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param string $key Retry identity.
	 * @param string $cause manual or scheduled.
	 * @param int    $cadence Explicit recurring enrollment, zero for existing direct operations.
	 * @return array Private run token for a new claim; joins/status never expose it.
	 * @throws \InvalidArgumentException On invalid command.
	 */
	public function start_run( int $workspace, string $key, string $cause = 'manual', int $cadence = 0 ): array {
		if ( ! preg_match( '/^[A-Za-z0-9:-]{1,80}$/D', $key ) || ! in_array( $cause, array( 'manual', 'scheduled', 'connection', 'resolution', 'coverage' ), true ) || $cadence < 0 || ( $cadence && 'scheduled' !== $cause ) ) {
			throw new \InvalidArgumentException( 'Invalid Tickerbot refresh command.' );
		}
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $key, $cause, $cadence ): array {
				$account  = $this->account();
				$settings = $this->settings( $workspace, $account );
				if ( $cadence ) {
					$this->check_cadence( $workspace, $cadence, $account );
				}
				$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_runs' ) . ' WHERE workspace_id = %d AND request_key = %s', array( $workspace, $key ) );
				if ( $prior ) {
					if ( $prior['cause'] !== $cause || (int) $prior['account_id'] !== (int) $account['id'] ) {
						throw new \DomainException( 'Refresh identity conflicts with its saved command.' );
					}
					return array(
						'state'  => 'running' === $prior['state'] && $prior['lease_until'] <= $this->time() ? 'expired' : $prior['state'],
						'run_id' => (int) $prior['id'],
					);
				}
				if ( $account['active_run_id'] && $account['lease_until'] > $this->time() ) {
					return (int) $account['active_workspace_id'] === $workspace ? array(
						'state'  => 'joined',
						'run_id' => (int) $account['active_run_id'],
					) : array( 'state' => 'busy' );
				}
				if ( 'scheduled' !== $cause && $settings['last_manual_at'] && $settings['last_manual_at'] > $this->now->modify( '-5 minutes' )->format( 'Y-m-d H:i:s' ) ) {
					return array( 'state' => 'cooldown' );
				}
				if ( 'scheduled' === $cause && $settings['next_due_at'] > $this->time() ) {
					return array( 'state' => 'not_due' );
				}
				$token = bin2hex( random_bytes( 32 ) );
				$until = $this->now->modify( '+5 minutes' )->format( 'Y-m-d H:i:s' );
				$id    = $this->db->insert(
					'tickerbot_runs',
					array(
						'workspace_id'   => $workspace,
						'account_id'     => $account['id'],
						'actor_id'       => $this->actor,
						'request_key'    => $key,
						'cause'          => $cause,
						'state'          => 'running',
						'lease_token'    => $token,
						'lease_until'    => $until,
						'started_at'     => $this->time(),
						'correlation_id' => $this->correlation,
					)
				);
				$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET active_run_id = %d, active_workspace_id = %d, lease_until = %s WHERE id = %d', array( $id, $workspace, $until, $account['id'] ) );
				if ( 'scheduled' !== $cause ) {
					$this->db->update_object( 'tickerbot_settings', $workspace, (int) $settings['id'], array( 'last_manual_at' => $this->time() ) );
				}
				if ( $cadence ) {
					$this->db->insert(
						'tickerbot_run_cadences',
						array(
							'workspace_id' => $workspace,
							'run_id'       => $id,
							'cadence_id'   => $cadence,
						)
					);
				}
				$this->audit( $workspace, 'tickerbot.run.start', 'tickerbot_runs', $id );
				return array(
					'state'       => 'running',
					'run_id'      => $id,
					'lease_token' => $token,
				);
			}
		);
	}

	/**
	 * Fence every writer against expiry, replacement, membership and original actor.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Run ID.
	 * @param string $token Private lease capability.
	 * @param array  $account Locked account.
	 * @return array Valid active run.
	 * @throws \DomainException On a lost lease.
	 */
	private function lease( int $workspace, int $run, string $token, array $account ): array {
		$row = $this->db->object( 'tickerbot_runs', $workspace, $run );
		if ( (int) $row['actor_id'] !== $this->actor || (int) $row['account_id'] !== (int) $account['id'] || (int) $account['active_run_id'] !== $run || 'running' !== $row['state'] || $row['lease_until'] <= $this->time() || ! hash_equals( $row['lease_token'], $token ) ) {
			throw new \DomainException( 'Tickerbot run lease is no longer valid.' );
		}
		return $row;
	}

	/**
	 * Count account-wide monthly and rolling-minute attempts under its lock.
	 *
	 * @param array $account Locked account.
	 * @return array Usage estimate; other applications remain unknown.
	 * @throws \RuntimeException On corrupted or excessive local usage history.
	 */
	private function counts( array $account ): array {
		$month  = $this->now->format( 'Y-m-01 00:00:00' );
		$minute = $this->now->modify( '-60 seconds' )->format( 'Y-m-d H:i:s' );
		// Locking reads see the latest committed attempts after an account-lock wait.
		$rows = $this->db->rows( 'SELECT created_at FROM ' . $this->db->table( 'tickerbot_attempts' ) . ' WHERE account_id = %d AND created_at >= %s ORDER BY id LIMIT 10046 FOR UPDATE', array( $account['id'], min( $month, $minute ) ) );
		if ( count( $rows ) >= 10046 ) {
			throw new \RuntimeException( 'Tickerbot usage exceeds the supported local account bound.' );
		}
		$monthly = 0;
		$recent  = 0;
		foreach ( $rows as $row ) {
			$monthly += $row['created_at'] >= $month ? 1 : 0;
			$recent  += $row['created_at'] > $minute ? 1 : 0;
		}
		return array(
			'monthly' => $monthly,
			'recent'  => $recent,
		);
	}

	/**
	 * Reserve/count each actual attempt before HTTP; retries also require this boundary.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Run ID.
	 * @param string $token Private lease.
	 * @param string $family Endpoint family.
	 * @param array  $symbols Exact canonical quote symbols.
	 * @param int    $batch Optional durable batch, zero for counted setup operations.
	 * @return array Admission or safe blocking reason.
	 * @throws \InvalidArgumentException On unsupported family.
	 */
	public function reserve_attempt( int $workspace, int $run, string $token, string $family, array $symbols = array(), int $batch = 0 ): array {
		if ( ! in_array( $family, array( 'quotes', 'connection', 'catalogue', 'coverage', 'history', 'enrichment' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported Tickerbot endpoint family.' );
		}
		if ( count( $symbols ) > 50 || count( $symbols ) !== count( array_unique( $symbols ) ) || ( 'quotes' === $family && ! $symbols ) ) {
			throw new \InvalidArgumentException( 'Quote attempts need one to fifty distinct canonical symbols.' );
		}
		foreach ( $symbols as $symbol ) {
			TickerbotQuote::symbol( $symbol );
		}
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $family, $symbols, $batch ): array {
				$account = $this->account();
				$current = $this->lease( $workspace, $run, $token, $account );
				if ( in_array( $current['cause'], array( 'connection', 'resolution', 'coverage' ), true ) ) {
					if ( array(
						'connection' => 'connection',
						'resolution' => 'catalogue',
						'coverage'   => 'coverage',
					)[ $current['cause'] ] !== $family || $batch ) {
						throw new \DomainException( 'Connection commands cannot dispatch other endpoint families.' );
					}
					$prior = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_attempts' ) . ' WHERE workspace_id = %d AND run_id = %d LIMIT 1 FOR UPDATE', array( $workspace, $run ) );
					if ( $prior ) {
						return array( 'state' => 'in_flight' );
					}
					if ( 'resolution' === $current['cause'] ) {
						$this->guard_resolution( $workspace, $run );
					}
					if ( 'coverage' === $current['cause'] ) {
						$this->guard_coverage( $workspace, $run );
					}
				}
				if ( $batch ) {
					$row  = $this->db->object( 'tickerbot_batches', $workspace, $batch );
					$busy = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_batches' ) . ' WHERE workspace_id = %d AND run_id = %d AND state = %s LIMIT 1 FOR UPDATE', array( $workspace, $run, 'dispatching' ) );
					if ( $busy ) {
						return array( 'state' => 'in_flight' );
					}
					if ( 'quotes' !== $family || (int) $row['run_id'] !== $run || ! in_array( $row['state'], array( 'pending', 'waiting' ), true ) || 3 <= (int) $row['attempts'] || json_decode( $row['symbols_json'], true, 512, JSON_THROW_ON_ERROR ) !== $symbols ) {
						throw new \DomainException( 'Batch is not eligible for this attempt.' );
					}
					if ( $row['next_due_at'] > $this->time() ) {
						return array(
							'state'       => 'waiting',
							'next_due_at' => $row['next_due_at'],
						);
					}
					$this->dispatch_inputs( $workspace, $row, $account );
				}
				$counts = $this->counts( $account );
				if ( $account['paused_until'] && $account['paused_until'] > $this->time() ) {
					return array(
						'state' => 'paused',
						'until' => $account['paused_until'],
					);
				}
				if ( $counts['monthly'] >= (int) $account['monthly_cap'] ) {
					return array( 'state' => 'budget_blocked' );
				}
				if ( $counts['recent'] >= 45 ) {
					return array( 'state' => 'rate_limit' );
				}
				if ( in_array( $family, array( 'history', 'enrichment' ), true ) && $counts['monthly'] * 5 >= (int) $account['monthly_cap'] * 4 ) {
					return array( 'state' => 'optional_paused' );
				}
				$id = $this->db->insert(
					'tickerbot_attempts',
					array(
						'workspace_id' => $workspace,
						'account_id'   => $account['id'],
						'run_id'       => $run,
						'family'       => $family,
						'symbols_json' => wp_json_encode( $symbols ),
						'state'        => 'admitted',
						'created_at'   => $this->time(),
					)
				);
				if ( $batch ) {
					$ordinal = (int) $row['attempts'] + 1;
					$this->db->update_object(
						'tickerbot_batches',
						$workspace,
						$batch,
						array(
							'state'             => 'dispatching',
							'attempts'          => $ordinal,
							'active_attempt_id' => $id,
						)
					);
					$this->db->insert(
						'tickerbot_batch_attempts',
						array(
							'workspace_id' => $workspace,
							'run_id'       => $run,
							'batch_id'     => $batch,
							'attempt_id'   => $id,
							'ordinal'      => $ordinal,
						)
					);
				}
				$this->audit( $workspace, 'tickerbot.attempt.admit', 'tickerbot_attempts', $id );
				return array(
					'state'      => 'admitted',
					'attempt_id' => $id,
					'warning'    => ( $counts['monthly'] + 1 ) * 5 >= (int) $account['monthly_cap'] * 4,
				);
			}
		);
	}

	/**
	 * Record a safe outcome and persist provider pauses without returning error bodies.
	 *
	 * @param int      $workspace Workspace ID.
	 * @param int      $run Run ID.
	 * @param string   $token Private lease.
	 * @param int      $attempt Attempt ID.
	 * @param string   $state Safe adapter result.
	 * @param int|null $retry_after Delay on rate limit.
	 * @param array    $issues Selective normalized per-asset issue codes.
	 * @return void
	 * @throws \InvalidArgumentException On unsupported outcome/delay.
	 */
	public function finish_attempt( int $workspace, int $run, string $token, int $attempt, string $state, ?int $retry_after = null, array $issues = array() ): void {
		if ( ! in_array( $state, array( 'completed', 'authentication', 'monthly_quota', 'capability', 'unsupported', 'rate_limit', 'provider_error', 'http_error', 'transport_error', 'invalid_response' ), true ) || ( null !== $retry_after && ( $retry_after < 1 || $retry_after > 2678400 ) ) ) {
			throw new \InvalidArgumentException( 'Invalid Tickerbot attempt outcome.' );
		}
		if ( count( $issues ) > 10000 || ( $issues && 'completed' !== $state ) ) {
			throw new \InvalidArgumentException( 'Too many batch issues.' );
		}
		foreach ( $issues as $asset => $issue ) {
			if ( ! is_int( $asset ) || $asset < 1 || ! in_array( $issue, array( 'unsupported', 'invalid_quote', 'identity_mismatch' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid selective batch issue.' );
			}
		}
		$this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $attempt, $state, $retry_after, $issues ): void {
				$account = $this->account();
				$this->lease( $workspace, $run, $token, $account );
				$row = $this->db->object( 'tickerbot_attempts', $workspace, $attempt );
				if ( (int) $row['run_id'] !== $run || (int) $row['account_id'] !== (int) $account['id'] ) {
					throw new \DomainException( 'Attempt does not belong to this run.' );
				}
				if ( 'admitted' !== $row['state'] ) {
					if ( $row['state'] !== $state ) {
						throw new \DomainException( 'Attempt outcome is immutable.' );
					}
					return;
				}
				$this->db->update_object(
					'tickerbot_attempts',
					$workspace,
					$attempt,
					array(
						'state'        => $state,
						'completed_at' => $this->time(),
					)
				);
				$until = null;
				if ( 'rate_limit' === $state ) {
					$until = $this->now->modify( '+' . ( $retry_after ?? 60 ) . ' seconds' )->format( 'Y-m-d H:i:s' );
				} elseif ( 'monthly_quota' === $state ) {
					$until = $this->now->modify( 'first day of next month' )->format( 'Y-m-01 00:00:00' );
				} elseif ( in_array( $state, array( 'authentication', 'capability' ), true ) ) {
					$until = '9999-12-31 23:59:59';
				}
				if ( null !== $until ) {
					if ( in_array( $state, array( 'rate_limit', 'monthly_quota' ), true ) && ( ! $account['recovery_not_before'] || $until > $account['recovery_not_before'] ) ) {
						$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET recovery_not_before = %s WHERE id = %d', array( $until, $account['id'] ) );
					}
					$kind = $state;
					if ( $account['paused_until'] && $account['paused_until'] > $until ) {
						$until = $account['paused_until'];
						$kind  = $account['pause_kind'];
					}
					if ( null === $kind ) {
						$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET paused_until = %s, pause_kind = NULL, pause_revision = pause_revision + 1 WHERE id = %d', array( $until, $account['id'] ) );
					} else {
						$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET paused_until = %s, pause_kind = %s, pause_revision = pause_revision + 1 WHERE id = %d', array( $until, $kind, $account['id'] ) );
					}
				}
				$this->settle_dispatch( $workspace, $run, $attempt, $state, $issues );
				$this->audit( $workspace, 'tickerbot.attempt.finish', 'tickerbot_attempts', $attempt );
			}
		);
	}

	/**
	 * Finish a run and move due time forward once, never replaying missed slots.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Run ID.
	 * @param string $token Private lease.
	 * @param string $state completed or failed.
	 * @return void
	 * @throws \InvalidArgumentException On unsupported terminal state.
	 */
	public function finish_run( int $workspace, int $run, string $token, string $state ): void {
		if ( ! in_array( $state, array( 'completed', 'failed' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid run outcome.' );
		}
		$this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $state ): void {
				$account    = $this->account();
				$current    = $this->lease( $workspace, $run, $token, $account );
				$settings   = $this->settings( $workspace, $account );
				$pending    = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_attempts' ) . ' WHERE workspace_id = %d AND run_id = %d AND state = %s LIMIT 1 FOR UPDATE', array( $workspace, $run, 'admitted' ) );
				$unfinished = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_batches' ) . ' WHERE workspace_id = %d AND run_id = %d AND state <> %s LIMIT 1 FOR UPDATE', array( $workspace, $run, 'completed' ) );
				if ( 'completed' === $state && ( $pending || $unfinished ) ) {
					throw new \DomainException( 'An unfinished provider attempt cannot be reported as a completed run.' );
				}
				$this->db->update_object(
					'tickerbot_runs',
					$workspace,
					$run,
					array(
						'state'        => $state,
						'completed_at' => $this->time(),
					)
				);
				if ( ! in_array( $current['cause'], array( 'connection', 'resolution', 'coverage' ), true ) ) {
					$this->db->update_object( 'tickerbot_settings', $workspace, (int) $settings['id'], array( 'next_due_at' => TickerbotSchedule::next( $this->now, (int) $settings['interval_seconds'] ) ) );
				}
				$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET active_run_id = NULL, active_workspace_id = NULL, lease_until = NULL WHERE id = %d', array( $account['id'] ) );
				$this->audit( $workspace, 'tickerbot.run.finish', 'tickerbot_runs', $run );
			}
		);
	}

	/**
	 * Append an identity mapping only after exact provider identity validation.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $asset Asset ID.
	 * @param string $symbol Canonical candidate.
	 * @param array  $identity Server-fetched provider identity, never a browser proof flag.
	 * @param int    $expected Prior mapping ID, zero for first mapping.
	 * @return int New immutable mapping ID.
	 */
	public function save_mapping( int $workspace, int $asset, string $symbol, array $identity, int $expected = 0 ): int {
		TickerbotQuote::symbol( $symbol );
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $asset, $symbol, $identity, $expected ): int {
				return $this->append_mapping( $workspace, $asset, $symbol, $identity, $expected );
			}
		);
	}

	/**
	 * Append under the existing workspace/account transaction.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $asset Local asset ID.
	 * @param string $symbol Canonical candidate.
	 * @param array  $identity Server-verified identity.
	 * @param int    $expected Prior mapping ID.
	 * @return int New immutable mapping ID.
	 * @throws \InvalidArgumentException On incompatible identity.
	 * @throws \DomainException On stale revision.
	 */
	private function append_mapping( int $workspace, int $asset, string $symbol, array $identity, int $expected ): int {
		$row = $this->db->object( 'assets', $workspace, $asset );
		if ( ! TickerbotQuote::identity( $row, $identity, $symbol ) ) {
			throw new \InvalidArgumentException( 'Tickerbot asset identity is unresolved or incompatible.' );
		}
		$prior = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $asset ) );
		if ( (int) ( $prior['id'] ?? 0 ) !== $expected ) {
			throw new \DomainException( 'Tickerbot mapping revision changed; reload it.' );
		}
		$id = $this->db->insert(
			'tickerbot_mappings',
			array(
				'workspace_id'    => $workspace,
				'asset_id'        => $asset,
				'provider_symbol' => $symbol,
				'asset_class'     => $row['asset_class'],
				'exchange'        => $row['exchange'],
				'currency'        => $row['quote_currency'],
				'actor_id'        => $this->actor,
				'checked_at'      => $this->time(),
			)
		);
		$this->audit( $workspace, 'tickerbot.mapping.save', 'tickerbot_mappings', $id );
		return $id;
	}

	/**
	 * Validate normalized UTC timestamp fields before persistence.
	 *
	 * @param mixed $value Normalized UTC value or null.
	 * @return string|null Valid value.
	 * @throws \InvalidArgumentException On malformed or future values.
	 */
	private function mark_time( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) ) : false;
		if ( ! $date || $date->format( 'Y-m-d H:i:s.u' ) !== $value || $date > $this->now->modify( '+5 minutes' ) ) {
			throw new \InvalidArgumentException( 'Invalid normalized mark timestamp.' );
		}
		return $value;
	}

	/**
	 * Keep valid marks and a receipt per attempt, deduplicating identical observations.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Run ID.
	 * @param string $token Private active lease.
	 * @param int    $attempt Admitted quote attempt.
	 * @param int    $mapping Current mapping ID.
	 * @param array  $quote Selective normalized adapter mark.
	 * @return int Quote observation ID.
	 */
	public function record_quote( int $workspace, int $run, string $token, int $attempt, int $mapping, array $quote ): int {
		return $this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token, $attempt, $mapping, $quote ): int {
				$account = $this->account();
				$this->lease( $workspace, $run, $token, $account );
				$request = $this->db->object( 'tickerbot_attempts', $workspace, $attempt );
				$map     = $this->db->object( 'tickerbot_mappings', $workspace, $mapping );
				$asset   = $this->db->object( 'assets', $workspace, (int) $map['asset_id'] );
				$latest  = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $map['asset_id'] ) );
				if ( ! in_array( $quote['provider_symbol'] ?? null, json_decode( $request['symbols_json'], true ) ?? array(), true ) || (int) $request['run_id'] !== $run || (int) $request['account_id'] !== (int) $account['id'] || 'quotes' !== $request['family'] || 'admitted' !== $request['state']
				|| (int) $latest['id'] !== $mapping || ( $quote['asset_id'] ?? null ) !== (int) $map['asset_id'] || ( $quote['provider'] ?? null ) !== 'tickerbot'
				|| ( $quote['provider_symbol'] ?? null ) !== $map['provider_symbol'] || ( $quote['currency'] ?? null ) !== $map['currency'] || $asset['quote_currency'] !== $map['currency'] || $asset['exchange'] !== $map['exchange'] || $asset['asset_class'] !== $map['asset_class'] ) {
					throw new \DomainException( 'Quote does not match the current admitted identity.' );
				}
				$price  = bcadd( Decimal::input( $quote['price'] ?? null, 18, true ), '0', 18 );
				$source = $quote['price_source'] ?? null;
				if ( ! in_array( $source, array( 'fair_value', 'trade', 'quote', 'minute_bar', 'day_bar', 'previous_close', 'exchange_rate' ), true ) ) {
					throw new \InvalidArgumentException( 'Unsupported Tickerbot mark type.' );
				}
				$price_time    = $this->mark_time( $quote['price_asof'] ?? null );
				$response_time = $this->mark_time( $quote['response_as_of'] ?? null );
				$fetched       = $this->mark_time( $quote['fetched_at'] ?? null );
				$semantics     = null === $price_time ? 'unknown' : ( 'fair_value' === $source ? 'provider_fetch_time' : 'source_observation_time' );
				if ( null === $fetched || ( $quote['source_time_semantics'] ?? null ) !== $semantics ) {
					throw new \InvalidArgumentException( 'Mark time semantics do not match its source.' );
				}
				$fingerprint = hash( 'sha256', wp_json_encode( array( $map['provider_symbol'], $price, $map['currency'], $source, $price_time, $semantics ) ) );
				$prior       = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_quote_receipts' ) . ' WHERE workspace_id = %d AND attempt_id = %d AND mapping_id = %d', array( $workspace, $attempt, $mapping ) );
				if ( $prior ) {
					$stored = $this->db->object( 'tickerbot_quotes', $workspace, (int) $prior['quote_id'] );
					$times  = $this->db->row( 'SELECT response_as_of FROM ' . $this->db->table( 'tickerbot_receipt_times' ) . ' WHERE workspace_id = %d AND receipt_id = %d', array( $workspace, $prior['id'] ) );
					if ( $stored['fingerprint'] !== $fingerprint || $prior['fetched_at'] !== $fetched || ( $times && $times['response_as_of'] !== $response_time ) ) {
						throw new \DomainException( 'Attempt quote receipt is immutable.' );
					}
					return (int) $prior['quote_id'];
				}
				$existing = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'tickerbot_quotes' ) . ' WHERE workspace_id = %d AND mapping_id = %d AND fingerprint = %s', array( $workspace, $mapping, $fingerprint ) );
				$id       = $existing ? (int) $existing['id'] : $this->db->insert(
					'tickerbot_quotes',
					array(
						'workspace_id'          => $workspace,
						'asset_id'              => $map['asset_id'],
						'mapping_id'            => $mapping,
						'fingerprint'           => $fingerprint,
						'price'                 => $price,
						'currency'              => $map['currency'],
						'price_source'          => $source,
						'source_time_semantics' => $semantics,
						'price_asof'            => $price_time,
						'response_as_of'        => $response_time,
						'fetched_at'            => $fetched,
					)
				);
				$receipt  = $this->db->insert(
					'tickerbot_quote_receipts',
					array(
						'workspace_id' => $workspace,
						'attempt_id'   => $attempt,
						'mapping_id'   => $mapping,
						'quote_id'     => $id,
						'fetched_at'   => $fetched,
					)
				);
				$this->db->insert(
					'tickerbot_receipt_times',
					array(
						'workspace_id'   => $workspace,
						'receipt_id'     => $receipt,
						'response_as_of' => $response_time,
					)
				);
				$this->audit( $workspace, 'tickerbot.quote.accept', 'tickerbot_quotes', $id );
				return $id;
			}
		);
	}

	/**
	 * Read safe account usage without exposing other workspace records.
	 *
	 * @param int $workspace Authorized enrolled workspace.
	 * @return array Local usage estimate only.
	 */
	public function usage( int $workspace ): array {
		return $this->atomic(
			$workspace,
			function () use ( $workspace ): array {
				$account = $this->account();
				$this->settings( $workspace, $account );
				$counts   = $this->counts( $account );
				$capacity = $this->capacity_total( $account );
				return array(
					'period'                    => $this->now->format( 'Y-m' ),
					'attempts'                  => $counts['monthly'],
					'recent_attempts'           => $counts['recent'],
					'soft_cap'                  => (int) $account['monthly_cap'],
					'remaining'                 => max( 0, (int) $account['monthly_cap'] - $counts['monthly'] ),
					'warning'                   => $counts['monthly'] * 5 >= (int) $account['monthly_cap'] * 4,
					'projected_recurring_calls' => $capacity,
					'projection_headroom'       => max( 0, (int) $account['monthly_cap'] - $capacity ),
					'projection_over_cap'       => $capacity > (int) $account['monthly_cap'],
					'paused_until'              => $account['paused_until'],
				);
			}
		);
	}

	/**
	 * Extend only a still-owned active lease between bounded HTTP batches.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param int    $run Run ID.
	 * @param string $token Private lease.
	 * @return void
	 */
	public function renew( int $workspace, int $run, string $token ): void {
		$this->atomic(
			$workspace,
			function () use ( $workspace, $run, $token ): void {
				$account = $this->account();
				$this->lease( $workspace, $run, $token, $account );
				$until = $this->now->modify( '+5 minutes' )->format( 'Y-m-d H:i:s' );
				$this->db->update_object( 'tickerbot_runs', $workspace, $run, array( 'lease_until' => $until ) );
				$this->db->query( 'UPDATE ' . $this->db->table( 'tickerbot_accounts' ) . ' SET lease_until = %s WHERE id = %d', array( $until, $account['id'] ) );
			}
		);
	}

	/**
	 * Read the latest received mark, not the highest deduplicated observation ID.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $asset Asset ID.
	 * @return array|null Current-mapping cached observation; no request or freshness claim.
	 */
	public function latest_quote( int $workspace, int $asset ): ?array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_view' );
		$this->db->object( 'assets', $workspace, $asset );
		$m        = $this->db->table( 'tickerbot_mappings' );
		$r        = $this->db->table( 'tickerbot_quote_receipts' );
		$q        = $this->db->table( 'tickerbot_quotes' );
		$a        = $this->db->table( 'assets' );
		$t        = $this->db->table( 'tickerbot_receipt_times' );
		$attempts = $this->db->table( 'tickerbot_attempts' );
		$accounts = $this->db->table( 'tickerbot_accounts' );
		$row      = $this->db->row( 'SELECT q.*, q.response_as_of AS first_response_as_of, t.response_as_of AS response_as_of, m.provider_symbol, m.asset_class, r.fetched_at AS last_received_at FROM ' . $m . ' m JOIN ' . $r . ' r ON r.workspace_id = m.workspace_id AND r.mapping_id = m.id JOIN ' . $attempts . ' admitted ON admitted.workspace_id = r.workspace_id AND admitted.id = r.attempt_id JOIN ' . $accounts . ' account ON account.id = admitted.account_id AND account.scope_digest = %s JOIN ' . $q . ' q ON q.workspace_id = r.workspace_id AND q.mapping_id = r.mapping_id AND q.id = r.quote_id JOIN ' . $a . ' a ON a.workspace_id = m.workspace_id AND a.id = m.asset_id LEFT JOIN ' . $t . ' t ON t.workspace_id = r.workspace_id AND t.receipt_id = r.id WHERE m.workspace_id = %d AND m.asset_id = %d AND a.quote_currency = m.currency AND a.exchange = m.exchange AND a.asset_class = m.asset_class AND m.id = (SELECT MAX(latest.id) FROM ' . $m . ' latest WHERE latest.workspace_id = m.workspace_id AND latest.asset_id = m.asset_id) ORDER BY r.fetched_at DESC, r.id DESC LIMIT 1', array( $this->scope, $workspace, $asset ) );
		return $row ? array_merge( $row, array( 'provider' => 'tickerbot' ) ) : null;
	}
}
