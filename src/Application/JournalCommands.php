<?php
/**
 * Atomic journal commands, separate from financial posting.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;

/** Workspace serialization and retry evidence for journal/media commands. */
final class JournalCommands {
	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Explicit actor.
	 *
	 * @var int
	 */
	private int $actor;
	/**
	 * Request correlation.
	 *
	 * @var string
	 */
	private string $correlation;
	/** Bind context.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Actor.
	 * @param string   $correlation Request context.
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
	}
	/** Require current membership.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $capability Capability.
	 * @return void
	 */
	public function authorize( int $workspace, string $capability = 'tgit_view' ): void {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, $capability );
	}
	/** Run a serialized command with idempotency.
	 *
	 * @param int      $workspace Workspace.
	 * @param string   $capability Capability.
	 * @param string   $operation Operation identity.
	 * @param array    $input Canonical command.
	 * @param string   $key Retry identity.
	 * @param callable $callback Authorized operation.
	 * @return array
	 * @throws \InvalidArgumentException When key is invalid.
	 */
	public function run( int $workspace, string $capability, string $operation, array $input, string $key, callable $callback ): array {
		$this->authorize( $workspace, $capability );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'Idempotency-Key must contain 8-128 safe ASCII characters.' );
		}
		$hash = hash( 'sha256', wp_json_encode( $input ) );
		return $this->db->atomic(
			function () use ( $workspace, $capability, $operation, $hash, $key, $callback ) {
				$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $workspace ) );
				$this->authorize( $workspace, $capability );
				$prior = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'idempotency' ) . ' WHERE workspace_id = %d AND operation = %s AND request_key = %s', array( $workspace, $operation, $key ) );
				if ( $prior ) {
					if ( ! hash_equals( $prior['request_hash'], $hash ) ) {
						throw new \UnexpectedValueException( 'Idempotency key already used with different facts.' );
					}
					return json_decode( $prior['result'], true, 512, JSON_THROW_ON_ERROR );
				}
				$result = $callback();
				$this->db->insert(
					'idempotency',
					array(
						'workspace_id' => $workspace,
						'operation'    => $operation,
						'request_key'  => $key,
						'request_hash' => $hash,
						'result'       => wp_json_encode( $result ),
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				return $result;
			}
		);
	}
	/** Append evidence inside the transaction.
	 *
	 * @param int    $workspace Workspace.
	 * @param string $action Event action.
	 * @param string $entity Object type.
	 * @param int    $id Object identity.
	 * @param int    $revision Object revision.
	 * @return void
	 */
	public function audit( int $workspace, string $action, string $entity, int $id, int $revision ): void {
		$this->db->insert(
			'audit_events',
			array(
				'workspace_id'   => $workspace,
				'actor_id'       => $this->actor,
				'action'         => $action,
				'entity'         => $entity,
				'entity_id'      => $id,
				'revision'       => $revision,
				'correlation_id' => $this->correlation,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
