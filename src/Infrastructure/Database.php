<?php
/**
 * Workspace-scoped persistence utilities.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Database service for the current implementation slice. */
final class Database {
	/**
	 * Bound database connection.
	 *
	 * @var \wpdb
	 */
	private $wpdb;
	private const TABLES = array( 'workspaces', 'memberships', 'accounts', 'assets', 'transactions', 'transaction_legs', 'transaction_revisions', 'transaction_corrections', 'replay_runs', 'lots', 'lot_allocations', 'opening_balances', 'opening_basis_resolutions', 'idempotency', 'audit_events', 'strategies', 'strategy_versions', 'trades', 'trade_journals', 'trade_fills', 'media_settings', 'media' );

	/**
	 * Bind the WordPress database connection.
	 *
	 * @param \wpdb $wpdb wpdb input.
	 * @return void
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}
	/**
	 * Resolve a trusted table identifier.
	 *
	 * @param string $name name input.
	 * @return string
	 * @throws \LogicException When the operation contract cannot be satisfied.
	 */
	public function table( string $name ): string {
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \LogicException( 'Unknown table.' );
		}
		return $this->wpdb->prefix . 'tgit_' . $name;
	}
	/**
	 * Execute trusted SQL with prepared values.
	 *
	 * @param string $sql sql input.
	 * @param array  $args args input.
	 * @return int
	 * @throws \RuntimeException When the operation contract cannot be satisfied.
	 */
	public function query( string $sql, array $args = array() ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared here; argument-free callers provide trusted transaction control or test DDL.
		$result = $this->wpdb->query( $args ? $this->wpdb->prepare( $sql, ...$args ) : $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Database operation failed.' );
		}
		return (int) $result;
	}
	/**
	 * Fetch records using prepared values.
	 *
	 * @param string $sql sql input.
	 * @param array  $args args input.
	 * @return array
	 * @throws \RuntimeException When the operation contract cannot be satisfied.
	 */
	public function rows( string $sql, array $args = array() ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Repository templates provide trusted identifiers and prepare every external value here.
		$rows = $this->wpdb->get_results( $args ? $this->wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A );
		if ( $this->wpdb->last_error ) {
			throw new \RuntimeException( 'Database operation failed.' );
		}
		return $rows ?? array();
	}
	/**
	 * Fetch the first matching record.
	 *
	 * @param string $sql sql input.
	 * @param array  $args args input.
	 * @return array|null
	 */
	public function row( string $sql, array $args = array() ): ?array {
		return $this->rows( $sql, $args )[0] ?? null;
	}
	/**
	 * Insert a row using WordPress value escaping.
	 *
	 * @param string $table table input.
	 * @param array  $data data input.
	 * @return int
	 * @throws \RuntimeException When the operation contract cannot be satisfied.
	 */
	public function insert( string $table, array $data ): int {
		if ( $this->wpdb->insert( $this->table( $table ), $data ) === false ) {
			throw new \RuntimeException( 'Database operation failed.' );
		}
		return (int) $this->wpdb->insert_id;
	}
	/**
	 * Resolve an object only within its workspace.
	 *
	 * @param string $table table input.
	 * @param int    $workspace workspace input.
	 * @param int    $id id input.
	 * @return array
	 * @throws \OutOfBoundsException When the operation contract cannot be satisfied.
	 */
	public function object( string $table, int $workspace, int $id ): array {
		$row = $this->row( 'SELECT * FROM ' . $this->table( $table ) . ' WHERE workspace_id = %d AND id = %d', array( $workspace, $id ) );
		if ( ! $row ) {
			throw new \OutOfBoundsException( 'Object not found in workspace.' );
		}
		return $row;
	}
	/**
	 * Update a non-posting object within its explicit workspace.
	 *
	 * @param string $table Trusted table name.
	 * @param int    $workspace Workspace identifier.
	 * @param int    $id Object identifier.
	 * @param array  $data Validated replacement values.
	 * @return void
	 * @throws \RuntimeException When persistence fails.
	 */
	public function update_object( string $table, int $workspace, int $id, array $data ): void {
		if ( false === $this->wpdb->update(
			$this->table( $table ),
			$data,
			array(
				'workspace_id' => $workspace,
				'id'           => $id,
			)
		) ) {
			throw new \RuntimeException( 'Database operation failed.' );
		}
	}
	/**
	 * Commit an application operation or roll back every change.
	 *
	 * @param callable $callback callback input.
	 * @return mixed
	 * @throws \Throwable Rethrows the application failure after rollback.
	 */
	public function atomic( callable $callback ) {
		$this->query( 'START TRANSACTION' );
		try {
			$result = $callback();
			$this->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
					$this->query( 'ROLLBACK' );
					throw $error;
		}
	}
}
