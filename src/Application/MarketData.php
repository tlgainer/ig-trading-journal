<?php
/**
 * Authorized provider identities, quote evidence and credential-wide quotas.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\AlphaVantageQuote;
use GainerInteractive\IGTradingJournal\Infrastructure\FmpEodQuote;
use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** Internal application boundary; no external requests or key persistence. */
final class MarketData {
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
	 * Audit correlation.
	 *
	 * @var string
	 */
	private string $correlation;
	private const LIMITS = array(
		'alpha_vantage' => 25,
		'fmp'           => 250,
	);

	/**
	 * Bind actor and persistence for synchronous or scheduled work.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Actor identifier.
	 * @param string   $correlation Audit correlation.
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
	}

	/**
	 * Read current mapping revisions, including disabled mappings, for an owner.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $after Cursor identifier.
	 * @param int $limit Maximum records.
	 * @return array
	 * @throws \InvalidArgumentException On invalid pagination.
	 */
	public function mappings( int $workspace, int $after = 0, int $limit = 100 ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid mapping pagination.' );
		}
		$table     = $this->db->table( 'provider_mappings' );
		$quotes    = $this->db->table( 'provider_quotes' );
		$schedules = $this->db->table( 'provider_schedules' );
		return $this->db->rows( 'SELECT m.*, q.price, q.session_date, q.retrieved_at, s.id AS schedule_id, s.frequency FROM ' . $table . ' m LEFT JOIN ' . $quotes . ' q ON q.workspace_id = m.workspace_id AND q.mapping_id = m.id AND q.id = (SELECT latest.id FROM ' . $quotes . ' latest WHERE latest.workspace_id = m.workspace_id AND latest.mapping_id = m.id ORDER BY latest.session_date DESC, latest.id DESC LIMIT 1) LEFT JOIN ' . $schedules . ' s ON s.workspace_id = m.workspace_id AND s.mapping_id = m.id AND s.id = (SELECT MAX(revision.id) FROM ' . $schedules . ' revision WHERE revision.workspace_id = m.workspace_id AND revision.mapping_id = m.id) WHERE m.workspace_id = %d AND m.id > %d AND NOT EXISTS (SELECT newer.id FROM ' . $table . ' newer WHERE newer.workspace_id = m.workspace_id AND newer.asset_id = m.asset_id AND newer.provider = m.provider AND newer.id > m.id) ORDER BY m.id LIMIT %d', array( $workspace, $after, $limit ) );
	}

	/**
	 * Serialize a workspace operation and recheck explicit owner membership.
	 *
	 * @param int $workspace Workspace identifier.
	 * @return void
	 */
	private function lock_owner( int $workspace ): void {
		$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $workspace ) );
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
	}

	/**
	 * Read latest enrollment for a scoped mapping, including disabled revisions.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $mapping Mapping identifier.
	 * @return array|null
	 */
	public function schedule_config( int $workspace, int $mapping ): ?array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$this->db->object( 'provider_mappings', $workspace, $mapping );
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'provider_schedules' ) . ' WHERE workspace_id = %d AND mapping_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $mapping ) );
	}

	/**
	 * Append explicitly owner-authorized recurring enrollment or disable it.
	 *
	 * @param int   $workspace Workspace identifier.
	 * @param int   $mapping Mapping identifier.
	 * @param array $input Frequency and expected schedule identifier.
	 * @return array
	 * @throws \InvalidArgumentException On invalid enrollment.
	 */
	public function save_schedule( int $workspace, int $mapping, array $input ): array {
		Tracker::fields( $input, array( 'frequency', 'expected_schedule_id' ), array( 'frequency', 'expected_schedule_id' ) );
		if ( ! in_array( $input['frequency'], array( 'off', 'once', 'twice' ), true ) || ! is_int( $input['expected_schedule_id'] ) || $input['expected_schedule_id'] < 0 ) {
			throw new \InvalidArgumentException( 'Invalid recurring enrollment.' );
		}
		return $this->db->atomic(
			function () use ( $workspace, $mapping, $input ): array {
				$this->lock_owner( $workspace );
				$prior = $this->schedule_config( $workspace, $mapping );
				if ( (int) ( $prior['id'] ?? 0 ) !== $input['expected_schedule_id'] ) {
						throw new \UnexpectedValueException( 'Refresh schedule changed; reload before saving.' );
				}
				if ( 'off' !== $input['frequency'] ) {
					$this->mapping( $workspace, $mapping );
				}
				$id = $this->db->insert(
					'provider_schedules',
					array(
						'workspace_id' => $workspace,
						'mapping_id'   => $mapping,
						'actor_id'     => $this->actor,
						'frequency'    => $input['frequency'],
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'quote_schedule', 'provider_schedules', $id );
				return $this->db->object( 'provider_schedules', $workspace, $id );
			}
		);
	}

	/**
	 * Validate bounded provider metadata without exposing it in exceptions.
	 *
	 * @param mixed $value Metadata.
	 * @param int   $maximum Byte limit.
	 * @return string
	 * @throws \InvalidArgumentException On invalid metadata.
	 */
	private static function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid provider metadata.' );
		}
		return trim( $value );
	}

	/**
	 * Append an owner-confirmed mapping or disabled mapping revision.
	 *
	 * @param int   $workspace Workspace identifier.
	 * @param int   $asset Asset identifier.
	 * @param array $input Complete mapping and expected prior identifier.
	 * @return array
	 * @throws \InvalidArgumentException On invalid identity evidence; stale revisions raise a conflict inside the transaction.
	 */
	public function save_mapping( int $workspace, int $asset, array $input ): array {
		Tracker::fields( $input, array( 'provider', 'provider_symbol', 'exchange', 'currency', 'enabled', 'evidence', 'expected_mapping_id' ), array( 'provider', 'provider_symbol', 'exchange', 'currency', 'enabled', 'evidence', 'expected_mapping_id' ) );
		$provider = self::text( $input['provider'], 20 );
		if ( ! isset( self::LIMITS[ $provider ] ) || ! is_bool( $input['enabled'] ) || ! is_int( $input['expected_mapping_id'] ) || $input['expected_mapping_id'] < 0 ) {
			throw new \InvalidArgumentException( 'Invalid provider mapping configuration.' );
		}
		$data = array(
			'provider'        => $provider,
			'provider_symbol' => self::text( $input['provider_symbol'], 80 ),
			'exchange'        => self::text( $input['exchange'], 80 ),
			'currency'        => self::text( $input['currency'], 3 ),
			'enabled'         => $input['enabled'] ? 1 : 0,
			'evidence'        => self::text( $input['evidence'], 500 ),
		);
		return $this->db->atomic(
			function () use ( $workspace, $asset, $input, $data ): array {
				$this->lock_owner( $workspace );
				$identity = $this->db->object( 'assets', $workspace, $asset );
				if ( 'stock' !== $identity['asset_class'] || $identity['quote_currency'] !== $data['currency'] || $identity['exchange'] !== $data['exchange'] ) {
					throw new \InvalidArgumentException( 'Mapping must confirm the stock exchange and quote currency.' );
				}
				$prior = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'provider_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d AND provider = %s ORDER BY id DESC LIMIT 1', array( $workspace, $asset, $data['provider'] ) );
				if ( (int) ( $prior['id'] ?? 0 ) !== $input['expected_mapping_id'] ) {
					throw new \UnexpectedValueException( 'Provider mapping changed; reload before saving.' );
				}
				$id = $this->db->insert(
					'provider_mappings',
					array_merge(
						$data,
						array(
							'workspace_id' => $workspace,
							'asset_id'     => $asset,
							'actor_id'     => $this->actor,
							'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						)
					)
				);
				$this->audit( $workspace, 'provider_mapping', 'provider_mappings', $id );
				return $this->db->object( 'provider_mappings', $workspace, $id );
			}
		);
	}

	/**
	 * Resolve an enabled current mapping and revalidate its stock identity.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $id Mapping identifier.
	 * @return array
	 * @throws \UnexpectedValueException On disabled/superseded identity.
	 */
	private function mapping( int $workspace, int $id ): array {
		$row   = $this->db->object( 'provider_mappings', $workspace, $id );
		$newer = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'provider_mappings' ) . ' WHERE workspace_id = %d AND asset_id = %d AND provider = %s AND id > %d LIMIT 1', array( $workspace, $row['asset_id'], $row['provider'], $id ) );
		$asset = $this->db->object( 'assets', $workspace, (int) $row['asset_id'] );
		if ( '1' !== (string) $row['enabled'] || $newer || 'stock' !== $asset['asset_class'] || $asset['quote_currency'] !== $row['currency'] || $asset['exchange'] !== $row['exchange'] ) {
			throw new \UnexpectedValueException( 'Provider mapping is disabled, changed or incompatible.' );
		}
		return $row;
	}

	/**
	 * Recheck the authorizing owner and selected mapping before network work.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $id Mapping identifier.
	 * @return array
	 */
	public function current_mapping( int $workspace, int $id ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		return $this->mapping( $workspace, $id );
	}

	/**
	 * Retrieve saved evidence for a completed request without resending it.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $request Request identifier.
	 * @return array|null
	 */
	public function completed_quote( int $workspace, int $request ): ?array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$this->request( $workspace, $request );
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'provider_quotes' ) . ' WHERE workspace_id = %d AND request_id = %d', array( $workspace, $request ) );
	}

	/**
	 * Commit one credential-wide reservation before any network request.
	 * The fingerprint is a trusted server-side SHA-256 digest, never a client field.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $mapping Mapping identifier.
	 * @param string $fingerprint Server-derived credential fingerprint.
	 * @param string $key Unique request/retry identity.
	 * @param bool   $scheduled Reserve five requests for on-demand work.
	 * @return array
	 * @throws \InvalidArgumentException On invalid metadata; quota/retry conflicts are raised inside the transaction.
	 * @throws \RuntimeException On shared reservation lock contention.
	 */
	public function reserve( int $workspace, int $mapping, string $fingerprint, string $key, bool $scheduled = true ): array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			throw new \InvalidArgumentException( 'Invalid credential fingerprint.' );
		}
		// Hash exact retry bytes so case-insensitive database collation cannot alias keys.
		$key      = hash( 'sha256', self::text( $key, 80 ) );
		$identity = $this->current_mapping( $workspace, $mapping );
		$lock     = 'tgit_quote_' . substr( hash( 'sha256', $this->db->table( 'provider_pools' ) . $identity['provider'] . $fingerprint ), 0, 40 );
		$acquired = $this->db->row( 'SELECT GET_LOCK(%s, 10) AS acquired', array( $lock ) );
		if ( '1' !== (string) $acquired['acquired'] ) {
			throw new \RuntimeException( 'Provider reservations are busy; retry later.' );
		}
		try {
			return $this->db->atomic(
				function () use ( $workspace, $mapping, $fingerprint, $key, $scheduled ): array {
					$this->lock_owner( $workspace );
					$identity = $this->mapping( $workspace, $mapping );
					$this->db->query( 'INSERT IGNORE INTO ' . $this->db->table( 'provider_pools' ) . ' (provider, credential_fingerprint) VALUES (%s, %s)', array( $identity['provider'], $fingerprint ) );
					$pool  = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'provider_pools' ) . ' WHERE provider = %s AND credential_fingerprint = %s FOR UPDATE', array( $identity['provider'], $fingerprint ) );
					$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'provider_requests' ) . ' WHERE pool_id = %d AND workspace_id = %d AND request_key = %s', array( $pool['id'], $workspace, $key ) );
					if ( $prior ) {
						if ( (int) $prior['mapping_id'] !== $mapping || (int) $prior['actor_id'] !== $this->actor ) {
							throw new \UnexpectedValueException( 'Provider request key conflicts with its original context.' );
						}
						return self::request_result( $prior );
					}
					$cutoff = gmdate( 'Y-m-d H:i:s', time() - 86400 );
					// Locking read avoids an older REPEATABLE READ snapshot under concurrent workspaces.
					$used  = $this->db->rows( 'SELECT id FROM ' . $this->db->table( 'provider_requests' ) . ' WHERE pool_id = %d AND (created_at >= %s OR dispatched_at >= %s OR state = %s) LIMIT 251 FOR UPDATE', array( $pool['id'], $cutoff, $cutoff, 'dispatched' ) );
					$limit = self::LIMITS[ $identity['provider'] ] - ( $scheduled ? 5 : 0 );
					if ( count( $used ) >= $limit ) {
						throw new \UnexpectedValueException( 'Provider request allowance is exhausted; retry after the rolling window clears.' );
					}
					$id = $this->db->insert(
						'provider_requests',
						array(
							'pool_id'      => $pool['id'],
							'workspace_id' => $workspace,
							'mapping_id'   => $mapping,
							'actor_id'     => $this->actor,
							'request_key'  => $key,
							'state'        => 'reserved',
							'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						)
					);
					$this->audit( $workspace, 'provider_reserve', 'provider_requests', $id );
					return self::request_result( $this->db->object( 'provider_requests', $workspace, $id ) );
				}
			);
		} finally {
			$this->db->row( 'SELECT RELEASE_LOCK(%s) AS released', array( $lock ) );
		}
	}

	/**
	 * Claim a reservation exactly once; never automatically resend uncertain calls.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $request Request identifier.
	 * @return array
	 * @throws \UnexpectedValueException On expired or previously claimed requests.
	 */
	public function dispatch( int $workspace, int $request ): array {
		return $this->db->atomic(
			function () use ( $workspace, $request ): array {
				$this->lock_owner( $workspace );
				$row = $this->request( $workspace, $request );
				$this->mapping( $workspace, (int) $row['mapping_id'] );
				if ( 'reserved' !== $row['state'] || $row['created_at'] <= gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) {
					throw new \UnexpectedValueException( 'Provider request is expired or already dispatched.' );
				}
				// Lock the shared pool so dispatch and quota reservations see one consistent window.
				$this->db->row( 'SELECT id FROM ' . $this->db->table( 'provider_pools' ) . ' WHERE id = %d FOR UPDATE', array( $row['pool_id'] ) );
				$this->db->update_object(
					'provider_requests',
					$workspace,
					$request,
					array(
						'state'         => 'dispatched',
						'dispatched_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'provider_dispatch', 'provider_requests', $request );
				return self::request_result( $this->db->object( 'provider_requests', $workspace, $request ) );
			}
		);
	}

	/**
	 * Persist validated Alpha Vantage evidence without touching financial history.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $request Request identifier.
	 * @param string $body Provider response body.
	 * @return array
	 * @throws \UnexpectedValueException On invalid request lifecycle or conflicting retry.
	 */
	public function complete_alpha_quote( int $workspace, int $request, string $body ): array {
		return $this->complete_quote( $workspace, $request, $body, 'alpha_vantage' );
	}

	/**
	 * Persist FMP daily evidence using the same immutable request contract.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $request Request identifier.
	 * @param string $body Provider response body.
	 * @return array
	 */
	public function complete_fmp_quote( int $workspace, int $request, string $body ): array {
		return $this->complete_quote( $workspace, $request, $body, 'fmp' );
	}

	/**
	 * Complete only the provider bound to the authorized request.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $request Request identifier.
	 * @param string $body Response body.
	 * @param string $provider Expected provider.
	 * @return array
	 * @throws \UnexpectedValueException On incompatible or conflicting evidence.
	 */
	private function complete_quote( int $workspace, int $request, string $body, string $provider ): array {
		return $this->db->atomic(
			function () use ( $workspace, $request, $body, $provider ): array {
				$this->lock_owner( $workspace );
				$row     = $this->request( $workspace, $request );
				$mapping = $this->mapping( $workspace, (int) $row['mapping_id'] );
				if ( $provider !== $mapping['provider'] || ! in_array( $row['state'], array( 'dispatched', 'completed' ), true ) ) {
					throw new \UnexpectedValueException( 'Provider response has no compatible dispatched request.' );
				}
				$quote = 'fmp' === $provider ? FmpEodQuote::parse( $body, $mapping['provider_symbol'] ) : AlphaVantageQuote::parse( $body, $mapping['provider_symbol'] );
				if ( $quote['session_date'] > gmdate( 'Y-m-d' ) ) {
					throw new \UnexpectedValueException( 'Provider quote has a future session date.' );
				}
				$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'provider_quotes' ) . ' WHERE workspace_id = %d AND request_id = %d', array( $workspace, $request ) );
				if ( $prior ) {
					if ( $prior['session_date'] !== $quote['session_date'] || Decimal::compare( $prior['price'], $quote['price'] ) || Decimal::compare( $prior['previous_close'], $quote['previous_close'] ) ) {
						throw new \UnexpectedValueException( 'Provider completion conflicts with saved quote evidence.' );
					}
					return $prior;
				}
				$quote['workspace_id'] = $workspace;
				$quote['asset_id']     = $mapping['asset_id'];
				$quote['mapping_id']   = $mapping['id'];
				$quote['request_id']   = $request;
				$quote['currency']     = $mapping['currency'];
				$quote['exchange']     = $mapping['exchange'];
				$quote['retrieved_at'] = gmdate( 'Y-m-d H:i:s' );
				$id                    = $this->db->insert( 'provider_quotes', $quote );
				$this->db->update_object(
					'provider_requests',
					$workspace,
					$request,
					array(
						'state'        => 'completed',
						'completed_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'provider_quote', 'provider_quotes', $id );
				return $this->db->object( 'provider_quotes', $workspace, $id );
			}
		);
	}

	/**
	 * Read bounded quote evidence within a workspace and asset.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $asset Asset identifier.
	 * @param int $after Cursor identifier.
	 * @param int $limit Maximum records.
	 * @return array
	 * @throws \InvalidArgumentException On invalid pagination.
	 */
	public function quotes( int $workspace, int $asset, int $after = 0, int $limit = 100 ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_view' );
		$this->db->object( 'assets', $workspace, $asset );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid quote pagination.' );
		}
		return $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'provider_quotes' ) . ' WHERE workspace_id = %d AND asset_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $asset, $after, $limit ) );
	}

	/**
	 * Record a known failed attempt without refunding its request allowance.
	 * Uncertain network outcomes remain dispatched and require explicit reconciliation.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $request Request identifier.
	 * @return void
	 * @throws \UnexpectedValueException On an incompatible request state.
	 */
	public function fail( int $workspace, int $request ): void {
		$this->db->atomic(
			function () use ( $workspace, $request ): void {
				$this->lock_owner( $workspace );
				$row = $this->request( $workspace, $request );
				if ( 'failed' === $row['state'] ) {
					return;
				}
				if ( 'dispatched' !== $row['state'] ) {
					throw new \UnexpectedValueException( 'Only dispatched attempts may be recorded as failed.' );
				}
				$this->db->update_object(
					'provider_requests',
					$workspace,
					$request,
					array(
						'state'        => 'failed',
						'completed_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'provider_failed', 'provider_requests', $request );
			}
		);
	}

	/**
	 * Resolve a request bound to this authorizing owner.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $id Request identifier.
	 * @return array
	 * @throws \DomainException On another owner's request.
	 */
	private function request( int $workspace, int $id ): array {
		$row = $this->db->object( 'provider_requests', $workspace, $id );
		if ( (int) $row['actor_id'] !== $this->actor ) {
			throw new \DomainException( 'Provider request belongs to another authorizing owner.' );
		}
		return $row;
	}

	/**
	 * Expose only workspace request state, never the shared credential pool.
	 *
	 * @param array $row Internal request row.
	 * @return array
	 */
	private static function request_result( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'mapping_id' => (int) $row['mapping_id'],
			'state'      => $row['state'],
		);
	}

	/**
	 * Append audit evidence within the same transaction.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param string $action Event action.
	 * @param string $entity Entity table.
	 * @param int    $id Entity identifier.
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
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
