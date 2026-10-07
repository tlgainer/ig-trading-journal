<?php
/**
 * Persistent site-wide AI spending coordination; no external transport.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Domain\AiBudget;
use GainerInteractive\IGTradingJournal\Domain\AiModelCatalog;
use GainerInteractive\IGTradingJournal\Domain\Decimal;

/** Explicit owner operations; request/configuration/event history is append-only. */
final class AiSpending {
	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Authorizing actor.
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
	/**
	 * Explicit evaluation clock.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $now;
	/**
	 * Whether tests supplied an explicit deterministic clock.
	 *
	 * @var bool
	 */
	private bool $fixed_clock;

	/**
	 * Bind trusted persistence, actor and clock; no keys or global user inference.
	 *
	 * @param Database                $db Persistence.
	 * @param int                     $actor Explicit actor.
	 * @param string                  $correlation Audit correlation.
	 * @param \DateTimeImmutable|null $now Trusted internal clock; never a client field.
	 */
	public function __construct( Database $db, int $actor, string $correlation, ?\DateTimeImmutable $now = null ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
		$this->now         = ( $now ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->fixed_clock = null !== $now;
	}

	/**
	 * Append global policy; only owners in the original controller workspace edit it.
	 *
	 * @param int   $workspace Controller workspace.
	 * @param array $input Enabled, cap, model and expected revision.
	 * @param array $catalog Trusted server model/pricing catalog, never client pricing.
	 * @return array
	 * @throws \InvalidArgumentException On invalid settings.
	 */
	public function configure( int $workspace, array $input, array $catalog ): array {
		$fields = array( 'enabled', 'monthly_cap', 'model', 'expected_config_id' );
		Tracker::fields( $input, $fields, $fields );
		Decimal::input( $input['monthly_cap'], 12 );
		if ( ! is_bool( $input['enabled'] ) || ! is_int( $input['expected_config_id'] ) || $input['expected_config_id'] < 0 || ! is_string( $input['model'] ) || ( '' !== $input['model'] && ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $input['model'] ) ) ) {
			throw new \InvalidArgumentException( 'Invalid AI settings.' );
		}
		$pricing = null;
		if ( $input['enabled'] ) {
			$pricing = AiBudget::pricing( $catalog[ $input['model'] ] ?? array(), $this->now );
			if ( $pricing['model'] !== $input['model'] ) {
				throw new \InvalidArgumentException( 'Selected model does not match catalog evidence.' );
			}
		}
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $input, $pricing ): array {
				if ( null !== $pricing ) {
					AiBudget::pricing( $pricing, $this->now );
				}
				if ( $pool && (int) $pool['controller_workspace_id'] !== $workspace ) {
					throw new \DomainException( 'Only an owner of the controller workspace can change the shared AI budget.' );
				}
				$prior = $this->latest( 'ai_configs', $workspace );
				if ( (int) ( $prior['id'] ?? 0 ) !== $input['expected_config_id'] ) {
					throw new \UnexpectedValueException( 'AI settings changed; reload before saving.' );
				}
				if ( ! $pool ) {
					$this->db->insert(
						'ai_pools',
						array(
							'id'                      => 1,
							'controller_workspace_id' => $workspace,
							'created_at'              => $this->stamp(),
						)
					);
				}
				$id = $this->db->insert(
					'ai_configs',
					array(
						'workspace_id'        => $workspace,
						'actor_id'            => $this->actor,
						'enabled'             => (int) $input['enabled'],
						'monthly_cap'         => $input['monthly_cap'],
						'model'               => $input['model'],
						'pricing_json'        => null === $pricing ? null : wp_json_encode( $pricing ),
						'pricing_fingerprint' => null === $pricing ? null : hash( 'sha256', wp_json_encode( $pricing ) ),
						'created_at'          => $this->stamp(),
					)
				);
				$this->audit( $workspace, 'ai_configure', 'ai_configs', $id );
				return $this->db->object( 'ai_configs', $workspace, $id );
			}
		);
	}

	/**
	 * Append workspace opt-in; enabling the shared policy alone enrolls nobody.
	 *
	 * @param int  $workspace Workspace.
	 * @param bool $enabled Explicit opt-in.
	 * @param int  $expected Expected revision.
	 * @return array
	 * @throws \InvalidArgumentException On invalid revision.
	 */
	public function enroll( int $workspace, bool $enabled, int $expected ): array {
		if ( $expected < 0 ) {
			throw new \InvalidArgumentException( 'Invalid AI enrollment revision.' );
		}
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $enabled, $expected ): array {
				$prior = $this->latest( 'ai_enrollments', $workspace );
				if ( (int) ( $prior['id'] ?? 0 ) !== $expected || ( $enabled && ! $pool ) ) {
					throw new \UnexpectedValueException( 'AI enrollment changed or shared configuration is missing.' );
				}
				$id = $this->db->insert(
					'ai_enrollments',
					array(
						'workspace_id' => $workspace,
						'actor_id'     => $this->actor,
						'enabled'      => (int) $enabled,
						'created_at'   => $this->stamp(),
					)
				);
				$this->audit( $workspace, 'ai_enroll', 'ai_enrollments', $id );
				return $this->db->object( 'ai_enrollments', $workspace, $id );
			}
		);
	}

	/**
	 * Reserve exactly once across all credentials/workspaces on this site.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $credential Server-derived credential digest, never the key.
	 * @param string $key Exact retry identity.
	 * @param string $fingerprint Approved input bundle digest.
	 * @param int    $input_tokens Verified input upper bound.
	 * @param int    $output_tokens Generated output bound.
	 * @param int    $approval Exact approval, or zero for legacy internal reservations.
	 * @param array  $catalog Trusted credential-bound model evidence.
	 * @return array
	 * @throws \InvalidArgumentException On invalid identity.
	 */
	public function reserve( int $workspace, string $credential, string $key, string $fingerprint, int $input_tokens, int $output_tokens, int $approval = 0, array $catalog = array() ): array {
		if ( $approval < 0 || ! preg_match( '/^[a-f0-9]{64}$/D', $credential ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) || '' === trim( $key ) || strlen( $key ) > 80 ) {
			throw new \InvalidArgumentException( 'Invalid AI reservation identity.' );
		}
		$key = hash( 'sha256', $key );
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $credential, $key, $fingerprint, $input_tokens, $output_tokens, $approval, $catalog ): array {
				$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ai_requests' ) . ' WHERE workspace_id = %d AND request_key = %s FOR UPDATE', array( $workspace, $key ) );
				if ( $prior ) {
					if ( (int) ( $prior['approval_id'] ?? 0 ) !== $approval || (int) $prior['actor_id'] !== $this->actor || $prior['credential_fingerprint'] !== $credential || $prior['input_fingerprint'] !== $fingerprint || (int) $prior['input_tokens'] !== $input_tokens || (int) $prior['output_tokens'] !== $output_tokens ) {
						throw new \UnexpectedValueException( 'AI retry identity conflicts with its original context.' );
					}
					return $this->request( $workspace, (int) $prior['id'] );
				}
				list( $config, $enrollment, $pricing ) = $this->active( $workspace, $pool );
				if ( $approval ) {
					$this->execution_evidence( $workspace, $approval, $fingerprint, $config, $credential, $catalog );
				}
				$cost      = AiBudget::estimate( $pricing, $input_tokens, $output_tokens, $this->now );
				$totals    = $this->totals();
				$admission = AiBudget::admission( $config['monthly_cap'], $totals['spent'], $totals['reserved'], $cost );
				if ( ! $admission['allowed'] || $totals['overrun'] ) {
					throw new \UnexpectedValueException( 'Shared AI spending allowance is paused, exhausted or requires overrun review.' );
				}
				$id = $this->db->insert(
					'ai_requests',
					array(
						'workspace_id'           => $workspace,
						'actor_id'               => $this->actor,
						'config_id'              => $config['id'],
						'enrollment_id'          => $enrollment['id'],
						'credential_fingerprint' => $credential,
						'request_key'            => $key,
						'input_fingerprint'      => $fingerprint,
						'approval_id'            => 0 === $approval ? null : $approval,
						'model'                  => $pricing['model'],
						'pricing_json'           => $config['pricing_json'],
						'pricing_fingerprint'    => $config['pricing_fingerprint'],
						'input_tokens'           => $input_tokens,
						'output_tokens'          => $output_tokens,
						'maximum_cost'           => $cost,
						'budget_period'          => AiBudget::period( $this->now )['period'],
						'created_at'             => $this->stamp(),
						'expires_at'             => $this->now->modify( '+10 minutes' )->format( 'Y-m-d H:i:s' ),
					)
				);
				$this->event( $workspace, $id, 'reserved' );
				return $this->request( $workspace, $id );
			}
		);
	}

	/**
	 * Claim once, rechecking consent, revisions, expiry, model prices and credential.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Request.
	 * @param string $credential Current server credential digest.
	 * @param array  $catalog Fresh trusted model evidence for bound requests.
	 * @return array
	 * @throws \UnexpectedValueException On stale or previously claimed reservations.
	 */
	public function dispatch( int $workspace, int $id, string $credential, array $catalog = array() ): array {
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $id, $credential, $catalog ): array {
				$row = $this->request( $workspace, $id );
				if ( 'reserved' !== $row['state'] || (int) $row['actor_id'] !== $this->actor ) {
					throw new \UnexpectedValueException( 'AI request is already claimed or belongs to a different authorizer.' );
				}
				list( $config, $enrollment ) = $this->active( $workspace, $pool );
				$totals                      = $this->totals();
				AiBudget::pricing( $this->pricing_evidence( $row ), $this->now );
				$period = AiBudget::period( $this->now )['period'];
				if ( $row['credential_fingerprint'] !== $credential || (int) $row['config_id'] !== (int) $config['id'] || (int) $row['enrollment_id'] !== (int) $enrollment['id'] || $row['expires_at'] <= $this->stamp() || $row['budget_period'] !== $period || 0 >= Decimal::compare( $config['monthly_cap'], '0' ) || 0 < Decimal::compare( Decimal::add( $totals['spent'], $totals['reserved'] ), $config['monthly_cap'] ) || $totals['overrun'] ) {
					throw new \UnexpectedValueException( 'AI reservation cannot dispatch in its current context.' );
				}
				if ( (int) ( $row['approval_id'] ?? 0 ) ) {
					$this->execution_evidence( $workspace, (int) $row['approval_id'], $row['input_fingerprint'], $row, $credential, $catalog );
				}
				$this->event( $workspace, $id, 'dispatched' );
				return $this->request( $workspace, $id );
			}
		);
	}

	/**
	 * Settle trusted normalized usage, retain unknown charges or cancel unsent work.
	 *
	 * @param int        $workspace Workspace.
	 * @param int        $id Request.
	 * @param array|null $usage Verified normalized usage, or uncertain delivery.
	 * @param bool       $cancel Explicit unsent cancellation.
	 * @return array
	 * @throws \UnexpectedValueException On incompatible lifecycle or changed usage.
	 */
	public function reconcile( int $workspace, int $id, ?array $usage = null, bool $cancel = false ): array {
		if ( null !== $usage ) {
			ksort( $usage, SORT_STRING );
		}
		return $this->locked(
			$workspace,
			function () use ( $workspace, $id, $usage, $cancel ): array {
				$row = $this->request( $workspace, $id );
				if ( (int) $row['actor_id'] !== $this->actor || ( $cancel && null !== $usage ) ) {
					throw new \UnexpectedValueException( 'Invalid AI reconciliation context.' );
				}
				$json = null === $usage ? null : wp_json_encode( $usage );
				if ( in_array( $row['state'], array( 'settled', 'overrun', 'cancelled' ), true ) ) {
					if ( $row['usage_json'] !== $json || ( 'cancelled' === $row['state'] ) !== $cancel ) {
						throw new \UnexpectedValueException( 'AI reconciliation conflicts with its original result.' );
					}
					return $row;
				}
				if ( $cancel ) {
					if ( 'reserved' !== $row['state'] ) {
						throw new \UnexpectedValueException( 'Dispatched AI work cannot be cancelled or refunded without usage evidence.' );
					}
					$this->event( $workspace, $id, 'cancelled', '0' );
				} else {
					if ( ! in_array( $row['state'], array( 'dispatched', 'uncertain' ), true ) ) {
						throw new \UnexpectedValueException( 'Only dispatched AI work can be reconciled.' );
					}
					$dispatch   = $this->db->row( 'SELECT created_at FROM ' . $this->db->table( 'ai_request_events' ) . ' WHERE workspace_id = %d AND request_id = %d AND state = %s ORDER BY id LIMIT 1', array( $workspace, $id, 'dispatched' ) );
					$charge     = null === $usage ? null : AiBudget::charge( $this->pricing_evidence( $row ), $usage, new \DateTimeImmutable( $dispatch['created_at'], new \DateTimeZone( 'UTC' ) ) );
					$settlement = AiBudget::settlement( $row['maximum_cost'], $charge );
					if ( 'uncertain' !== $row['state'] || null !== $usage ) {
						$this->event( $workspace, $id, $settlement['state'], $charge, $json );
					}
				}
				return $this->request( $workspace, $id );
			}
		);
	}

	/**
	 * Read only an explicitly authorized workspace request.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Request.
	 * @return array Internal result; credential digests must not enter browser responses.
	 */
	public function request( int $workspace, int $id ): array {
		$this->owner( $workspace );
		$row   = $this->db->object( 'ai_requests', $workspace, $id );
		$event = $this->db->row( 'SELECT state, charge, usage_json FROM ' . $this->db->table( 'ai_request_events' ) . ' WHERE workspace_id = %d AND request_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $id ) );
		return array_merge( $row, $event ?? array() );
	}

	/**
	 * Owner-only shared budget summary, never foreign request or credential data.
	 *
	 * @param int $workspace Authorized workspace.
	 * @return array
	 */
	public function status( int $workspace ): array {
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace ): array {
				$config     = $pool ? $this->latest( 'ai_configs', (int) $pool['controller_workspace_id'] ) : null;
				$enrollment = $this->latest( 'ai_enrollments', $workspace );
				$totals     = $this->totals();
				$cap        = $config['monthly_cap'] ?? '15.000000000000';
				return array_merge(
					AiBudget::period( $this->now ),
					AiBudget::admission( $cap, $totals['spent'], $totals['reserved'], '0' ),
					array(
						'configured'    => null !== $config,
						'can_configure' => ! $pool || (int) $pool['controller_workspace_id'] === $workspace,
						'config_id'     => (int) ( $config['id'] ?? 0 ),
						'enabled'       => '1' === (string) ( $config['enabled'] ?? '0' ),
						'monthly_cap'   => $cap,
						'model'         => $config['model'] ?? '',
						'enrollment_id' => (int) ( $enrollment['id'] ?? 0 ),
						'enrolled'      => '1' === (string) ( $enrollment['enabled'] ?? '0' ),
						'spent'         => $totals['spent'],
						'reserved'      => $totals['reserved'],
						'overrun'       => '1' === (string) $totals['overrun'],
					)
				);
			}
		);
	}

	/**
	 * Recheck approval and model evidence inside the spending transaction.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $approval Exact approval.
	 * @param string $fingerprint Approved digest.
	 * @param array  $row Captured configuration or request.
	 * @param string $credential Current credential digest.
	 * @param array  $catalog Trusted server catalog.
	 * @return void
	 * @throws \UnexpectedValueException On changed approval or model evidence.
	 */
	private function execution_evidence( int $workspace, int $approval, string $fingerprint, array $row, string $credential, array $catalog ): void {
		$approved = ( new AiEvidencePreview( $this->db, $this->actor, $this->correlation ) )->approved( $workspace, $approval );
		$source   = $this->db->object( 'ai_evidence_bundles', $workspace, $approval );
		if ( (int) $source['actor_id'] !== $this->actor || ! hash_equals( $approved['fingerprint'], $fingerprint ) ) {
			throw new \UnexpectedValueException( 'AI execution requires the original authorizer and exact approved evidence.' );
		}
		$verified = AiModelCatalog::verified( $catalog, $credential, $this->now );
		$pricing  = $verified[ $row['model'] ] ?? null;
		if ( null === $pricing || ! hash_equals( $row['pricing_fingerprint'], hash( 'sha256', wp_json_encode( $pricing ) ) ) ) {
			throw new \UnexpectedValueException( 'AI execution requires current verified model access and unchanged pricing.' );
		}
	}

	/**
	 * Serialize shared admission before workspace locks, with audited rollback.
	 *
	 * @param int      $workspace Workspace.
	 * @param callable $callback Operation with locked pool metadata.
	 * @return mixed
	 * @throws \RuntimeException On lock contention.
	 */
	private function locked( int $workspace, callable $callback ) {
		$this->owner( $workspace );
		$lock     = 'tgit_ai_' . substr( hash( 'sha256', $this->db->table( 'ai_pools' ) ), 0, 40 );
		$acquired = $this->db->row( 'SELECT GET_LOCK(%s, 10) AS acquired', array( $lock ) );
		if ( '1' !== (string) $acquired['acquired'] ) {
			throw new \RuntimeException( 'AI spending operations are busy; retry later.' );
		}
		try {
			if ( ! $this->fixed_clock ) {
				$this->now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			}
			return $this->db->atomic(
				function () use ( $workspace, $callback ) {
					$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $workspace ) );
					$this->owner( $workspace );
					// Singleton coordination metadata contains no credential or customer evidence.
					$pool = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ai_pools' ) . ' WHERE id = 1 FOR UPDATE' );
					return $callback( $pool );
				}
			);
		} finally {
			$this->db->row( 'SELECT RELEASE_LOCK(%s) AS released', array( $lock ) );
		}
	}

	/**
	 * Resolve active global and local consent with current owner membership.
	 *
	 * @param int        $workspace Workspace.
	 * @param array|null $pool Shared coordination metadata.
	 * @return array
	 * @throws \UnexpectedValueException On missing/disabled policy or enrollment.
	 */
	private function active( int $workspace, ?array $pool ): array {
		if ( $pool && (int) $pool['controller_workspace_id'] !== $workspace ) {
			$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $pool['controller_workspace_id'] ) );
		}
		$config     = $pool ? $this->latest( 'ai_configs', (int) $pool['controller_workspace_id'] ) : null;
		$enrollment = $this->latest( 'ai_enrollments', $workspace );
		if ( ! $config || ! $enrollment || '1' !== (string) $config['enabled'] || '1' !== (string) $enrollment['enabled'] ) {
			throw new \UnexpectedValueException( 'AI processing requires enabled shared policy and explicit workspace enrollment.' );
		}
		( new Tracker( $this->db, (int) $config['actor_id'], $this->correlation ) )->authorize( (int) $config['workspace_id'], 'tgit_manage_members' );
		( new Tracker( $this->db, (int) $enrollment['actor_id'], $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$pricing = AiBudget::pricing( $this->pricing_evidence( $config ), $this->now );
		return array( $config, $enrollment, $pricing );
	}

	/**
	 * Verify saved model/pricing integrity without rewriting captured evidence.
	 *
	 * @param array $row Saved configuration or reservation.
	 * @return array
	 * @throws \UnexpectedValueException On missing or damaged pricing evidence.
	 */
	private function pricing_evidence( array $row ): array {
		if ( ! is_string( $row['pricing_json'] ?? null ) || ! is_string( $row['pricing_fingerprint'] ?? null ) || ! hash_equals( $row['pricing_fingerprint'], hash( 'sha256', $row['pricing_json'] ) ) ) {
			throw new \UnexpectedValueException( 'Saved AI pricing evidence is damaged.' );
		}
		try {
			$pricing = json_decode( $row['pricing_json'], true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new \UnexpectedValueException( 'Saved AI pricing evidence is malformed.' );
		}
		if ( ! is_array( $pricing ) || ( $pricing['model'] ?? null ) !== $row['model'] ) {
			throw new \UnexpectedValueException( 'Saved AI pricing identity is incompatible.' );
		}
		return $pricing;
	}

	/**
	 * Narrow global aggregate under the budget lock; never returns foreign rows.
	 *
	 * @return array
	 */
	private function totals(): array {
		$requests = $this->db->table( 'ai_requests' );
		$events   = $this->db->table( 'ai_request_events' );
		// Global spending coordination intentionally aggregates all opt-in workspaces.
		return $this->db->row( 'SELECT COALESCE(SUM(CASE WHEN e.state IN (%s,%s) AND r.budget_period = %s THEN e.charge ELSE 0 END),0) AS spent, COALESCE(SUM(CASE WHEN e.state IN (%s,%s,%s) THEN r.maximum_cost ELSE 0 END),0) AS reserved, COALESCE(MAX(e.state = %s),0) AS overrun FROM ' . $requests . ' r JOIN ' . $events . ' e ON e.workspace_id = r.workspace_id AND e.request_id = r.id AND e.id = (SELECT MAX(latest.id) FROM ' . $events . ' latest WHERE latest.workspace_id = r.workspace_id AND latest.request_id = r.id)', array( 'settled', 'overrun', AiBudget::period( $this->now )['period'], 'reserved', 'dispatched', 'uncertain', 'overrun' ) );
	}

	/**
	 * Read a scoped current revision while the global write lock is held.
	 *
	 * @param string $table Trusted table.
	 * @param int    $workspace Workspace.
	 * @return array|null
	 */
	private function latest( string $table, int $workspace ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( $table ) . ' WHERE workspace_id = %d ORDER BY id DESC LIMIT 1 FOR UPDATE', array( $workspace ) );
	}

	/**
	 * Explicit owner authorization, also required for site administrators.
	 *
	 * @param int $workspace Workspace.
	 * @return void
	 */
	private function owner( int $workspace ): void {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );
	}

	/**
	 * Append a lifecycle fact and its audit together.
	 *
	 * @param int         $workspace Workspace.
	 * @param int         $request Request.
	 * @param string      $state Lifecycle state.
	 * @param string|null $charge Verified estimated charge.
	 * @param string|null $usage Normalized usage JSON.
	 * @return void
	 */
	private function event( int $workspace, int $request, string $state, ?string $charge = null, ?string $usage = null ): void {
		$id = $this->db->insert(
			'ai_request_events',
			array(
				'workspace_id' => $workspace,
				'request_id'   => $request,
				'actor_id'     => $this->actor,
				'state'        => $state,
				'charge'       => $charge,
				'usage_json'   => $usage,
				'created_at'   => $this->stamp(),
			)
		);
		$this->audit( $workspace, 'ai_' . $state, 'ai_request_events', $id );
	}

	/**
	 * Append workspace audit evidence without input text or credentials.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $action Action.
	 * @param string $entity Entity.
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
				'created_at'     => $this->stamp(),
			)
		);
	}

	/**
	 * Trusted UTC clock value.
	 *
	 * @return string
	 */
	private function stamp(): string {
		return $this->now->format( 'Y-m-d H:i:s' );
	}
}
