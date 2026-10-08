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
use GainerInteractive\IGTradingJournal\Domain\AiPrompt;
use GainerInteractive\IGTradingJournal\Domain\AiTokenCount;
use GainerInteractive\IGTradingJournal\Domain\AiResponse;
use GainerInteractive\IGTradingJournal\Domain\AiJson;
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
	 * Resolve an authorized complete count request without making a reservation.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $approval Exact saved approval.
	 * @param string $credential Current credential digest.
	 * @param int    $output_tokens Full output ceiling.
	 * @param array  $catalog Trusted credential-bound model evidence.
	 * @return array Private server plan; never expose to a browser.
	 * @throws \UnexpectedValueException On missing consent, evidence or budget room.
	 */
	public function prepare_count( int $workspace, int $approval, string $credential, int $output_tokens, array $catalog ): array {
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $approval, $credential, $output_tokens, $catalog ): array {
				list( $config, $enrollment, $pricing ) = $this->active( $workspace, $pool );
				$source                                = $this->db->object( 'ai_evidence_bundles', $workspace, $approval );
				$this->execution_evidence( $workspace, $approval, $source['fingerprint'], $config, $credential, $catalog );
				$plan    = AiPrompt::build( $source['bundle_json'], $source['fingerprint'], $config['model'], $output_tokens );
				$totals  = $this->totals();
				$minimum = AiBudget::estimate( $pricing, 1, $output_tokens, $this->now );
				if ( $totals['overrun'] || ! AiBudget::admission( $config['monthly_cap'], $totals['spent'], $totals['reserved'], $minimum )['allowed'] ) {
					throw new \UnexpectedValueException( 'AI counting requires room for the minimum summary reservation.' );
				}
				return array(
					'plan'          => $plan,
					'pricing'       => $pricing,
					'config_id'     => (int) $config['id'],
					'enrollment_id' => (int) $enrollment['id'],
					'prepared_at'   => $this->stamp(),
				);
			}
		);
	}

	/**
	 * Coordinate an explicit generation attempt without holding a transaction over HTTP.
	 *
	 * @param int      $workspace Authorized workspace.
	 * @param int      $approval Exact saved approval.
	 * @param string   $key Stable operation identity.
	 * @param int      $output_tokens Full output ceiling.
	 * @param callable $callback Internal operation accepting an existing request or null.
	 * @return array Safe operation result.
	 * @throws \InvalidArgumentException On invalid operation identity.
	 * @throws \UnexpectedValueException On conflicting retry identity.
	 * @throws \RuntimeException On overlapping attempts.
	 */
	public function generation_operation( int $workspace, int $approval, string $key, int $output_tokens, callable $callback ): array {
		$this->owner( $workspace );
		if ( $approval < 1 || '' === trim( $key ) || strlen( $key ) > 80 || $output_tokens < 1 || $output_tokens > 1000000 ) {
			throw new \InvalidArgumentException( 'Invalid AI generation identity.' );
		}
		$digest = hash( 'sha256', $key );
		$lock   = 'tgit_gen_' . substr( hash( 'sha256', $this->db->table( 'ai_requests' ) . ':' . $workspace . ':' . $digest ), 0, 40 );
		$held   = $this->db->row( 'SELECT IS_USED_LOCK(%s) AS holder', array( $lock ) );
		if ( null !== $held['holder'] ) {
			throw new \RuntimeException( 'AI generation is already in progress.' );
		}
		$acquired = $this->db->row( 'SELECT GET_LOCK(%s, 0) AS acquired', array( $lock ) );
		if ( '1' !== (string) $acquired['acquired'] ) {
			throw new \RuntimeException( 'AI generation is already in progress.' );
		}
		try {
			$this->owner( $workspace );
			$prior = $this->db->row( 'SELECT id, actor_id, approval_id, output_tokens FROM ' . $this->db->table( 'ai_requests' ) . ' WHERE workspace_id = %d AND request_key = %s', array( $workspace, $digest ) );
			if ( $prior && ( (int) $prior['actor_id'] !== $this->actor || (int) $prior['approval_id'] !== $approval || (int) $prior['output_tokens'] !== $output_tokens ) ) {
				throw new \UnexpectedValueException( 'AI generation retry conflicts with its original context.' );
			}
			return $callback( $prior ? $this->request( $workspace, (int) $prior['id'] ) : null );
		} finally {
			$this->db->row( 'SELECT RELEASE_LOCK(%s) AS released', array( $lock ) );
		}
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
	 * @param array  $execution Trusted count context, response body and actual HTTP status.
	 * @return array
	 * @throws \InvalidArgumentException On invalid identity.
	 */
	public function reserve( int $workspace, string $credential, string $key, string $fingerprint, int $input_tokens, int $output_tokens, int $approval = 0, array $catalog = array(), array $execution = array() ): array {
		if ( $approval < 0 || ! preg_match( '/^[a-f0-9]{64}$/D', $credential ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) || '' === trim( $key ) || strlen( $key ) > 80 ) {
			throw new \InvalidArgumentException( 'Invalid AI reservation identity.' );
		}
		$key = hash( 'sha256', $key );
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $credential, $key, $fingerprint, $input_tokens, $output_tokens, $approval, $catalog, $execution ): array {
				$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ai_requests' ) . ' WHERE workspace_id = %d AND request_key = %s FOR UPDATE', array( $workspace, $key ) );
				if ( $prior ) {
					if ( (int) ( $prior['approval_id'] ?? 0 ) !== $approval || (int) $prior['actor_id'] !== $this->actor || $prior['credential_fingerprint'] !== $credential || $prior['input_fingerprint'] !== $fingerprint || (int) $prior['input_tokens'] !== $input_tokens || (int) $prior['output_tokens'] !== $output_tokens ) {
						throw new \UnexpectedValueException( 'AI retry identity conflicts with its original context.' );
					}
					$manifest = $this->manifest( $workspace, (int) $prior['id'] );
					if ( (bool) $manifest !== (bool) $execution || ( $manifest && $manifest['receipt'] !== $this->count_receipt( $execution ) ) ) {
						throw new \UnexpectedValueException( 'AI retry cannot replace its verified execution receipt.' );
					}
					return $this->request( $workspace, (int) $prior['id'] );
				}
				list( $config, $enrollment, $pricing ) = $this->active( $workspace, $pool );
				if ( $approval ) {
					$this->execution_evidence( $workspace, $approval, $fingerprint, $config, $credential, $catalog );
				}
				$manifest = null;
				if ( $execution ) {
					if ( ! $approval ) {
						throw new \UnexpectedValueException( 'Verified execution requires an exact approval.' );
					}
					$source   = $this->db->object( 'ai_evidence_bundles', $workspace, $approval );
					$plan     = AiPrompt::build( $source['bundle_json'], $fingerprint, $config['model'], $output_tokens );
					$receipt  = $this->count_receipt( $execution );
					$verified = AiTokenCount::verify( $plan, $receipt['context'], wp_json_encode( $receipt['response'] ), $receipt['http_status'], $credential, $pricing, $this->now );
					if ( $verified['bound']['input_tokens'] !== $input_tokens ) {
						throw new \UnexpectedValueException( 'Reservation tokens differ from the verified complete input.' );
					}
					$manifest = array(
						'version'  => 'ai-execution-1',
						'plan'     => $plan,
						'receipt'  => $receipt,
						'verified' => $verified,
					);
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
				if ( $manifest ) {
					$json = wp_json_encode( $manifest );
					$this->db->insert(
						'ai_execution_manifests',
						array(
							'workspace_id' => $workspace,
							'request_id'   => $id,
							'actor_id'     => $this->actor,
							'approval_id'  => $approval,
							'fingerprint'  => hash( 'sha256', $json ),
							'payload_json' => $json,
							'created_at'   => $this->stamp(),
						)
					);
				}
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
	 * @param bool   $require_execution Future senders must require the complete verified plan.
	 * @return array
	 * @throws \UnexpectedValueException On stale or previously claimed reservations.
	 */
	public function dispatch( int $workspace, int $id, string $credential, array $catalog = array(), bool $require_execution = false ): array {
		return $this->locked(
			$workspace,
			function ( ?array $pool ) use ( $workspace, $id, $credential, $catalog, $require_execution ): array {
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
				$manifest = $this->manifest( $workspace, $id );
				if ( $require_execution && ! $manifest ) {
					throw new \UnexpectedValueException( 'Sending requires an immutable verified execution plan.' );
				}
				if ( $manifest ) {
					$receipt  = $manifest['receipt'];
					$verified = AiTokenCount::verify( $manifest['plan'], $receipt['context'], wp_json_encode( $receipt['response'] ), $receipt['http_status'], $credential, $this->pricing_evidence( $row ), $this->now );
					if ( $verified !== $manifest['verified'] || $verified['bound']['input_tokens'] !== (int) $row['input_tokens'] || $verified['bound']['output_tokens'] !== (int) $row['output_tokens'] || $verified['bound']['evidence_fingerprint'] !== $row['input_fingerprint'] || $verified['bound']['maximum_cost'] !== $row['maximum_cost'] ) {
						throw new \UnexpectedValueException( 'Execution plan no longer matches its reservation.' );
					}
				}
				$this->event( $workspace, $id, 'dispatched' );
				return $this->request( $workspace, $id );
			}
		);
	}

	/**
	 * Read private immutable execution evidence; never expose as browser JSON.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Request.
	 * @return array|null
	 * @throws \UnexpectedValueException On damaged provenance or stored bytes.
	 */
	public function manifest( int $workspace, int $id ): ?array {
		$request = $this->request( $workspace, $id );
		$row     = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ai_execution_manifests' ) . ' WHERE workspace_id = %d AND request_id = %d', array( $workspace, $id ) );
		if ( ! $row ) {
			return null;
		}
		$approval = (int) ( $request['approval_id'] ?? 0 );
		if ( (int) $row['actor_id'] !== (int) $request['actor_id'] || (int) $row['approval_id'] !== $approval || ! hash_equals( $row['fingerprint'], hash( 'sha256', $row['payload_json'] ) ) ) {
			throw new \UnexpectedValueException( 'AI execution manifest is damaged or has incompatible provenance.' );
		}
		$manifest = AiJson::decode( $row['payload_json'] );
		if ( 'ai-execution-1' !== ( $manifest['version'] ?? null ) || ! is_array( $manifest['receipt'] ?? null ) || ! is_array( $manifest['verified'] ?? null ) || ! is_array( $manifest['plan'] ?? null ) ) {
			throw new \UnexpectedValueException( 'AI execution manifest format is unsupported.' );
		}
		return $manifest;
	}

	/**
	 * Normalize trusted execution metadata without storing arbitrary HTTP bodies.
	 *
	 * @param array $execution Actual server count execution, never a client submission.
	 * @return array
	 * @throws \InvalidArgumentException On incomplete or unsupported count evidence.
	 */
	private function count_receipt( array $execution ): array {
		$fields = array( 'context', 'body', 'http_status' );
		if ( array_diff( array_keys( $execution ), $fields ) || array_diff( $fields, array_keys( $execution ) ) || ! is_array( $execution['context'] ) || ! is_string( $execution['body'] ) || ! is_int( $execution['http_status'] ) ) {
			throw new \InvalidArgumentException( 'Trusted count execution is incomplete.' );
		}
		$context = $execution['context'];
		ksort( $context, SORT_STRING );
		$response = AiJson::decode( $execution['body'], 4096 );
		ksort( $response, SORT_STRING );
		return array(
			'context'     => $context,
			'response'    => $response,
			'http_status' => $execution['http_status'],
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
				if ( null !== $this->received( $workspace, $id ) ) {
					throw new \UnexpectedValueException( 'Receipt-backed requests require receipt-bound reconciliation.' );
				}
				return $this->reconcile_locked( $workspace, $id, $usage, $cancel );
			}
		);
	}


	/**
	 * Reconcile while the caller holds the shared spending lock and transaction.
	 *
	 * @param int        $workspace Workspace.
	 * @param int        $id Request.
	 * @param array|null $usage Verified usage.
	 * @param bool       $cancel Unsent cancellation.
	 * @return array
	 * @throws \UnexpectedValueException On incompatible lifecycle.
	 */
	private function reconcile_locked( int $workspace, int $id, ?array $usage, bool $cancel ): array {
		if ( null !== $usage ) {
			ksort( $usage, SORT_STRING );
		}
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


	/**
	 * Capture a server response and its budget result in one locked transaction.
	 *
	 * @param int    $workspace Workspace.
	 * @param int    $id Exact dispatched approval-bound request.
	 * @param string $body Bounded server transport body, never client input.
	 * @param int    $http_status Actual server HTTP status.
	 * @return array Safe status without provider text, usage or credential data.
	 * @throws \UnexpectedValueException On changed receipts or invalid lifecycle.
	 */
	public function receive( int $workspace, int $id, string $body, int $http_status = 200 ): array {
		try {
			$response = AiJson::decode( $body );
		} catch ( \InvalidArgumentException $error ) {
			$response = array();
		}
		return $this->locked(
			$workspace,
			function () use ( $workspace, $id, $response, $http_status ): array {
				$row = $this->request( $workspace, $id );
				if ( (int) $row['actor_id'] !== $this->actor || ! (int) ( $row['approval_id'] ?? 0 ) || ! in_array( $row['state'], array( 'dispatched', 'uncertain', 'settled', 'overrun' ), true ) ) {
					throw new \UnexpectedValueException( 'AI receipts require an originally authorized, dispatched and approval-bound request.' );
				}
				$approved = ( new AiEvidencePreview( $this->db, $this->actor, $this->correlation ) )->approved( $workspace, (int) $row['approval_id'] );
				if ( ! hash_equals( $row['input_fingerprint'], $approved['fingerprint'] ) ) {
					throw new \UnexpectedValueException( 'AI request approved evidence is damaged.' );
				}
				$result = AiResponse::inspect( $response, $row['model'], $approved['bundle'], $http_status );
				if ( null !== $result['usage'] && ( $result['usage']['input_tokens'] > (int) $row['input_tokens'] || $result['usage']['output_tokens'] > (int) $row['output_tokens'] ) ) {
					$result['reason'] = 'token_bound_exceeded';
					$result['review'] = null;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stable result identity, with no raw provider payload.
				$json        = json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
				$fingerprint = hash( 'sha256', $json );
				$prior       = $this->received( $workspace, $id );
				if ( null !== $prior ) {
					if ( hash_equals( $prior['fingerprint'], $fingerprint ) ) {
						return $this->receipt_status( $row, $prior );
					}
					if ( in_array( $row['state'], array( 'settled', 'overrun' ), true ) || ( null !== $prior['result']['response_id'] && $prior['result']['response_id'] !== $result['response_id'] ) ) {
						throw new \UnexpectedValueException( 'AI receipt conflicts with its immutable result or response identity.' );
					}
				} elseif ( in_array( $row['state'], array( 'settled', 'overrun' ), true ) ) {
					throw new \UnexpectedValueException( 'A settled request cannot acquire fabricated response provenance.' );
				}
				$receipt = $this->db->insert(
					'ai_response_receipts',
					array(
						'workspace_id'       => $workspace,
						'request_id'         => $id,
						'approval_id'        => $row['approval_id'],
						'actor_id'           => $this->actor,
						'version'            => $result['version'],
						'result_fingerprint' => $fingerprint,
						'result_json'        => $json,
						'created_at'         => $this->stamp(),
					)
				);
				$this->audit( $workspace, 'ai_response_received', 'ai_response_receipts', $receipt );
				$settled = $this->reconcile_locked( $workspace, $id, $result['usage'], false );
				return $this->receipt_status( $settled, $this->received( $workspace, $id ) );
			}
		);
	}

	/**
	 * Read the latest immutable normalized receipt for server-side publication only.
	 *
	 * @param int $workspace Workspace.
	 * @param int $id Request.
	 * @return array|null Internal evidence; never return directly to a browser.
	 * @throws \UnexpectedValueException On damaged receipt history.
	 */
	public function received( int $workspace, int $id ): ?array {
		$request = $this->request( $workspace, $id );
		$row     = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ai_response_receipts' ) . ' WHERE workspace_id = %d AND request_id = %d ORDER BY id DESC LIMIT 1', array( $workspace, $id ) );
		if ( null === $row ) {
			return null;
		}
		if ( 'ai-response-1' !== $row['version'] || (int) $row['actor_id'] !== (int) $request['actor_id'] || (int) $row['approval_id'] !== (int) $request['approval_id'] || ! hash_equals( $row['result_fingerprint'], hash( 'sha256', $row['result_json'] ) ) ) {
			throw new \UnexpectedValueException( 'AI response receipt is damaged or incompatible.' );
		}
		$result = AiJson::decode( $row['result_json'], 65536 );
		return array(
			'id'          => (int) $row['id'],
			'request_id'  => $id,
			'approval_id' => (int) $row['approval_id'],
			'fingerprint' => $row['result_fingerprint'],
			'result'      => $result,
		);
	}

	/**
	 * Expose fixed receipt status separately from private response evidence.
	 *
	 * @param array $request Current spending state.
	 * @param array $receipt Captured receipt.
	 * @return array
	 */
	private function receipt_status( array $request, array $receipt ): array {
		return array(
			'request_id'       => (int) $request['id'],
			'receipt_id'       => $receipt['id'],
			'state'            => $request['state'],
			'reason'           => $receipt['result']['reason'],
			'review_available' => 'settled' === $request['state'] && 'completed' === $receipt['result']['reason'],
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
