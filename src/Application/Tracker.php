<?php
/**
 * Authorized application operations and atomic posting.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Domain\Decimal;
use GainerInteractive\IGTradingJournal\Domain\Ledger;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;

/** Tracker service for the current implementation slice. */
final class Tracker {
	public const DEFAULT_BASE_CURRENCY = 'USD';
	public const DEFAULT_TIMEZONE      = 'America/New_York';
	/**
	 * Scoped persistence.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Authorized actor identity.
	 *
	 * @var int
	 */
	private int $actor;
	/**
	 * Operation correlation identifier.
	 *
	 * @var string
	 */
	private string $correlation;

	/**
	 * Bind persistence, actor identity and correlation context.
	 *
	 * @param Database $db db input.
	 * @param int      $actor actor input.
	 * @param string   $correlation correlation input.
	 * @return void
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;
	}

	/**
	 * Require current workspace membership and contextual capability.
	 *
	 * @param int    $workspace workspace input.
	 * @param string $capability capability input.
	 * @return array
	 * @throws \DomainException When the operation contract cannot be satisfied.
	 */
	public function authorize( int $workspace, string $capability ): array {
		$membership = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'memberships' ) . ' WHERE workspace_id = %d AND wp_user_id = %d', array( $workspace, $this->actor ) );
		if ( ! Access::allows( $membership, $capability ) ) {
			throw new \DomainException( 'Workspace permission denied.' );
		}
		$record = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d AND status = %s', array( $workspace, 'active' ) );
		if ( ! $record ) {
			throw new \DomainException( 'Workspace permission denied.' );
		}
		return $record;
	}

	/**
	 * Serialize workspace writes and recheck current authorization.
	 *
	 * @param int    $workspace workspace input.
	 * @param string $capability capability input.
	 * @return array
	 */
	private function lock( int $workspace, string $capability ): array {
		$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', array( $workspace ) );
		return $this->authorize( $workspace, $capability );
	}

	/**
	 * Append actor and revision evidence inside the current transaction.
	 *
	 * @param int    $workspace workspace input.
	 * @param string $action action input.
	 * @param string $entity entity input.
	 * @param int    $id id input.
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
				'correlation_id' => $this->correlation,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Reject unknown fields and missing required inputs.
	 *
	 * @param array $data data input.
	 * @param array $allowed allowed input.
	 * @param array $required required input.
	 * @return void
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public static function fields( array $data, array $allowed, array $required ): void {
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Unknown request fields.' );
		}
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Field comes from a developer-defined allowlist; REST encodes this as JSON text.
				throw new \InvalidArgumentException( 'Missing field: ' . $field );
			}
		}
	}

	/**
	 * Validate bounded nonempty metadata.
	 *
	 * @param mixed $value value input.
	 * @param int   $max max input.
	 * @return string
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	private static function text( $value, int $max ): string {
		if ( ! is_string( $value ) || trim( $value ) === '' || strlen( $value ) > $max || preg_match( '/[\x00-\x1F]/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid text field.' );
		}
		return trim( $value );
	}

	/**
	 * Validate uppercase currency-code syntax.
	 *
	 * @param mixed $value value input.
	 * @return string
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	private static function currency( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Z]{3}$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Currency must be a three-letter uppercase ISO code.' );
		}
		return $value;
	}

	/**
	 * Require a positive JSON integer identifier.
	 *
	 * @param mixed $value value input.
	 * @return int
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	private static function id( $value ): int {
		if ( ! is_int( $value ) || $value <= 0 ) {
			throw new \InvalidArgumentException( 'Object IDs must be positive JSON integers.' );
		}
		return $value;
	}

	/**
	 * List only active workspaces belonging to this actor.
	 *
	 * @return array
	 */
	public function workspaces(): array {
		return $this->db->rows( 'SELECT w.*, m.role FROM ' . $this->db->table( 'workspaces' ) . ' w INNER JOIN ' . $this->db->table( 'memberships' ) . ' m ON m.workspace_id = w.id WHERE m.wp_user_id = %d AND m.state = %s AND w.status = %s ORDER BY w.id', array( $this->actor, 'active', 'active' ) );
	}

	/**
	 * Create a workspace with an explicit audited owner grant.
	 *
	 * @param array $data data input.
	 * @return array
	 * @throws \DomainException When the operation contract cannot be satisfied.
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public function create_workspace( array $data ): array {
		// Bootstrap permission grants no access to existing workspaces.
		if ( ! user_can( $this->actor, 'manage_options' ) ) {
			throw new \DomainException( 'Only a site operator can create a workspace.' );
		}
		$data += array(
			'base_currency' => self::DEFAULT_BASE_CURRENCY,
			'timezone'      => self::DEFAULT_TIMEZONE,
		);
		self::fields( $data, array( 'name', 'base_currency', 'timezone' ), array( 'name' ) );
		$name     = self::text( $data['name'], 190 );
		$currency = self::currency( $data['base_currency'] );
		if ( ! is_string( $data['timezone'] ) || ! in_array( $data['timezone'], \DateTimeZone::listIdentifiers(), true ) ) {
			throw new \InvalidArgumentException( 'Choose an explicit IANA timezone.' );
		}
		return $this->db->atomic(
			function () use ( $data, $name, $currency ) {
				$id = $this->db->insert(
					'workspaces',
					array(
						'uuid'          => wp_generate_uuid4(),
						'name'          => $name,
						'base_currency' => $currency,
						'timezone'      => $data['timezone'],
						'created_at'    => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'memberships',
					array(
						'workspace_id' => $id,
						'wp_user_id'   => $this->actor,
						'role'         => 'owner',
						'invited_by'   => $this->actor,
						'joined_at'    => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $id, 'workspace.created', 'workspace', $id );
				return array( 'id' => $id );
			}
		);
	}

	/**
	 * List membership records for an authorized workspace owner.
	 *
	 * @param int $workspace workspace input.
	 * @return array
	 */
	public function members( int $workspace ): array {
		$this->authorize( $workspace, 'tgit_manage_members' );
		return $this->db->rows( 'SELECT wp_user_id, role, state, joined_at, revoked_at FROM ' . $this->db->table( 'memberships' ) . ' WHERE workspace_id = %d ORDER BY id', array( $workspace ) );
	}

	/**
	 * Update membership atomically while protecting the last owner.
	 *
	 * @param int   $workspace workspace input.
	 * @param array $data data input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public function set_member( int $workspace, array $data ): array {
		self::fields( $data, array( 'wp_user_id', 'role', 'state' ), array( 'wp_user_id', 'role', 'state' ) );
		$user = self::id( $data['wp_user_id'] );
		if ( ! get_userdata( $user ) || ! in_array( $data['role'], Access::roles(), true ) || ! in_array( $data['state'], array( 'active', 'revoked' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid member or role.' );
		}
		return $this->db->atomic(
			function () use ( $workspace, $data, $user ) {
				$this->lock( $workspace, 'tgit_manage_members' );
				$table    = $this->db->table( 'memberships' );
				$existing = $this->db->row( "SELECT * FROM $table WHERE workspace_id = %d AND wp_user_id = %d", array( $workspace, $user ) );
				if ( $existing && 'owner' === $existing['role'] && 'active' === $existing['state'] && ( 'owner' !== $data['role'] || 'active' !== $data['state'] ) ) {
					$owners = $this->db->rows( "SELECT id FROM $table WHERE workspace_id = %d AND role = %s AND state = %s", array( $workspace, 'owner', 'active' ) );
					if ( count( $owners ) <= 1 ) {
						throw new \InvalidArgumentException( 'Transfer ownership before removing the last owner.' );
					}
				}
				if ( $existing ) {
					$this->db->query( "UPDATE $table SET role = %s, state = %s, revoked_at = " . ( 'revoked' === $data['state'] ? 'UTC_TIMESTAMP()' : 'NULL' ) . ' WHERE workspace_id = %d AND wp_user_id = %d', array( $data['role'], $data['state'], $workspace, $user ) );
				} else {
					$this->db->insert(
						'memberships',
						array(
							'workspace_id' => $workspace,
							'wp_user_id'   => $user,
							'role'         => $data['role'],
							'state'        => $data['state'],
							'invited_by'   => $this->actor,
							'joined_at'    => gmdate( 'Y-m-d H:i:s' ),
							'revoked_at'   => 'revoked' === $data['state'] ? gmdate( 'Y-m-d H:i:s' ) : null,
						)
					);
				}
				$this->audit( $workspace, 'membership.' . $data['state'] . '.' . $data['role'], 'user', $user );
				return array(
					'wp_user_id' => $user,
					'role'       => $data['role'],
					'state'      => $data['state'],
				);
			}
		);
	}

	/**
	 * List an authorized workspace page with a bounded ID cursor.
	 *
	 * @param int    $workspace workspace input.
	 * @param string $table table input.
	 * @param int    $after after input.
	 * @param int    $limit limit input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public function list_objects( int $workspace, string $table, int $after = 0, int $limit = 100 ): array {
		$this->authorize( $workspace, 'tgit_view' );
		if ( ! in_array( $table, array( 'accounts', 'assets', 'transactions' ), true ) || $limit < 1 || $limit > 100 || $after < 0 ) {
			throw new \InvalidArgumentException( 'Invalid list parameters.' );
		}
		return $this->db->rows( 'SELECT * FROM ' . $this->db->table( $table ) . ' WHERE workspace_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $after, $limit ) );
	}

	/**
	 * Create an authorized workspace account or supported asset.
	 *
	 * @param int    $workspace workspace input.
	 * @param string $table table input.
	 * @param array  $data data input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public function create_object( int $workspace, string $table, array $data ): array {
		if ( 'accounts' === $table ) {
			self::fields( $data, array( 'name', 'broker', 'native_currency', 'type' ), array( 'name', 'native_currency' ) );
			$row = array(
				'name'            => self::text( $data['name'], 190 ),
				'broker'          => isset( $data['broker'] ) && '' !== $data['broker'] ? self::text( $data['broker'], 190 ) : '',
				'native_currency' => self::currency( $data['native_currency'] ),
				'type'            => $data['type'] ?? 'brokerage',
			);
			if ( ! in_array( $row['type'], array( 'brokerage', 'exchange', 'bank', 'wallet' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid account type.' );
			}
		} elseif ( 'assets' === $table ) {
			self::fields( $data, array( 'symbol', 'exchange', 'asset_class', 'quote_currency' ), array( 'symbol', 'exchange', 'asset_class', 'quote_currency' ) );
			$row = array(
				'symbol'         => self::text( $data['symbol'], 32 ),
				'exchange'       => self::text( $data['exchange'], 64 ),
				'asset_class'    => $data['asset_class'],
				'quote_currency' => self::currency( $data['quote_currency'] ),
			);
			if ( ! in_array( $row['asset_class'], array( 'stock', 'etf', 'crypto' ), true ) ) {
				throw new \InvalidArgumentException( 'Only long stock, ETF and crypto assets are supported in this slice.' );
			}
		} else {
			throw new \InvalidArgumentException( 'Invalid object type.' );
		}
		return $this->db->atomic(
			function () use ( $workspace, $table, $row ) {
				$this->lock( $workspace, 'tgit_manage_settings' );
				$id = $this->db->insert(
					$table,
					$row + array(
						'workspace_id' => $workspace,
						'uuid'         => wp_generate_uuid4(),
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, $table . '.created', $table, $id );
				return $this->db->object( $table, $workspace, $id );
			}
		);
	}

	/**
	 * Validate action-specific decimal and date contracts.
	 *
	 * @param array $data data input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	private function transaction_input( array $data ): array {
		self::fields( $data, array( 'account_id', 'asset_id', 'action', 'effective_date', 'state', 'quantity', 'unit_price', 'fees', 'amount', 'currency' ), array( 'account_id', 'action', 'effective_date', 'state', 'currency' ) );
		$data['account_id'] = self::id( $data['account_id'] );
		if ( ! in_array( $data['action'], array( 'buy', 'sell', 'deposit', 'withdrawal' ), true ) || ! in_array( $data['state'], array( 'draft', 'posted' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported action or state.' );
		}
		$date = is_string( $data['effective_date'] ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $data['effective_date'] ) : false;
		if ( ! $date || $date->format( 'Y-m-d' ) !== $data['effective_date'] ) {
			throw new \InvalidArgumentException( 'Date must be a valid YYYY-MM-DD value.' );
		}
		$data['currency'] = self::currency( $data['currency'] );
		$security         = in_array( $data['action'], array( 'buy', 'sell' ), true );
		if ( $security ) {
			if ( array_key_exists( 'amount', $data ) ) {
				throw new \InvalidArgumentException( 'Buy/sell amount is calculated server-side.' );
			}
			$data['asset_id']   = self::id( $data['asset_id'] ?? null );
			$data['quantity']   = Decimal::input( $data['quantity'] ?? null, 18, true );
			$data['unit_price'] = Decimal::input( $data['unit_price'] ?? null, 18, true );
			$data['fees']       = Decimal::input( $data['fees'] ?? '0', 12 );
			$data['amount']     = '0';
		} else {
			if ( array_intersect( array( 'asset_id', 'quantity', 'unit_price', 'fees' ), array_keys( $data ) ) ) {
				throw new \InvalidArgumentException( 'Cash movements accept amount only.' );
			}
			$data['amount']     = Decimal::input( $data['amount'] ?? null, 12, true );
			$data['asset_id']   = null;
			$data['quantity']   = '0';
			$data['unit_price'] = '0';
			$data['fees']       = '0';
		}
		ksort( $data );
		return $data;
	}

	/**
	 * Commit a draft or financial event atomically and idempotently.
	 *
	 * @param int    $workspace workspace input.
	 * @param array  $input input input.
	 * @param string $key key input.
	 * @return array
	 * @throws \InvalidArgumentException On invalid decimal, field or idempotency-key input.
	 */
	public function post( int $workspace, array $input, string $key ): array {
		$data = $this->transaction_input( $input );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'An 8-128 character Idempotency-Key is required.' );
		}
		$hash       = hash( 'sha256', wp_json_encode( $data ) );
		$capability = 'posted' === $data['state'] ? 'tgit_post' : 'tgit_create_draft';
		return $this->db->atomic(
			function () use ( $workspace, $data, $key, $hash, $capability ) {
				$this->lock( $workspace, $capability );
				$existing = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'idempotency' ) . ' WHERE workspace_id = %d AND operation = %s AND request_key = %s', array( $workspace, 'transaction.create', $key ) );
				if ( $existing ) {
					if ( ! hash_equals( $existing['request_hash'], $hash ) ) {
						throw new \UnexpectedValueException( 'Idempotency key was used with different input.' );
					}
					return json_decode( $existing['result'], true, 512, JSON_THROW_ON_ERROR );
				}
				$account = $this->db->object( 'accounts', $workspace, $data['account_id'] );
				if ( null !== $account['archived_at'] || $account['native_currency'] !== $data['currency'] ) {
					throw new \InvalidArgumentException( 'Use an active account in the transaction currency.' );
				}
				if ( null !== $data['asset_id'] ) {
					$asset = $this->db->object( 'assets', $workspace, $data['asset_id'] );
					if ( $asset['quote_currency'] !== $data['currency'] ) {
						throw new \InvalidArgumentException( 'Asset and account currencies must match until FX support is implemented.' );
					}
				}
				$effects = array(
					'cash_delta'    => '0',
					'realized_gain' => '0',
				);
				if ( 'posted' === $data['state'] ) {
							// Append-only first slice: block historical inserts until revision/replay exists.
							$last = $this->db->row( 'SELECT effective_date FROM ' . $this->db->table( 'transactions' ) . ' WHERE workspace_id = %d AND account_id = %d AND state = %s ORDER BY effective_date DESC,id DESC LIMIT 1', array( $workspace, $data['account_id'], 'posted' ) );
					if ( $last && $data['effective_date'] < $last['effective_date'] ) {
						throw new \UnexpectedValueException( 'Historical posting requires the future correction/rebuild workflow.' );
					}
					if ( 'buy' === $data['action'] ) {
						$effects = Ledger::buy( $data['quantity'], $data['unit_price'], $data['fees'] ) + $effects;
					} elseif ( 'sell' === $data['action'] ) {
								$lots    = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'lots' ) . ' WHERE workspace_id = %d AND account_id = %d AND asset_id = %d AND quantity_remaining > 0 ORDER BY acquired_on,id', array( $workspace, $data['account_id'], $data['asset_id'] ) );
								$effects = Ledger::sell( $lots, $data['quantity'], $data['unit_price'], $data['fees'] );
					} else {
						$effects['cash_delta'] = 'deposit' === $data['action'] ? $data['amount'] : Decimal::sub( '0', $data['amount'] );
					}
						$cash = Decimal::money( Decimal::add( $account['cash_balance'], $effects['cash_delta'] ) );
					if ( Decimal::compare( $cash, '0' ) < 0 ) {
						throw new \InvalidArgumentException( 'Insufficient cash; overdrafts are disabled. Record deposits first.' );
					}
				}
				$id = $this->db->insert(
					'transactions',
					$data + array(
						'workspace_id'  => $workspace,
						'uuid'          => wp_generate_uuid4(),
						'realized_gain' => Decimal::money( $effects['realized_gain'] ),
						'created_by'    => $this->actor,
						'created_at'    => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'revision'       => 1,
						'payload'        => wp_json_encode( $data ),
						'actor_id'       => $this->actor,
						'reason'         => 'Initial entry',
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				if ( 'posted' === $data['state'] ) {
						$delta = 'buy' === $data['action'] ? $data['quantity'] : ( 'sell' === $data['action'] ? Decimal::sub( '0', $data['quantity'] ) : '0' );
						$leg   = $this->db->insert(
							'transaction_legs',
							array(
								'workspace_id'   => $workspace,
								'transaction_id' => $id,
								'account_id'     => $data['account_id'],
								'asset_id'       => $data['asset_id'],
								'quantity_delta' => bcadd( $delta, '0', 18 ),
								'cash_delta'     => Decimal::money( $effects['cash_delta'] ),
								'currency'       => $data['currency'],
								'role'           => $data['action'],
							)
						);
									$this->db->query( 'UPDATE ' . $this->db->table( 'accounts' ) . ' SET cash_balance = %s WHERE workspace_id = %d AND id = %d', array( $cash, $workspace, $data['account_id'] ) );
					if ( 'buy' === $data['action'] ) {
						$this->db->insert(
							'lots',
							array(
								'workspace_id'       => $workspace,
								'account_id'         => $data['account_id'],
								'asset_id'           => $data['asset_id'],
								'acquisition_leg_id' => $leg,
								'quantity_initial'   => $data['quantity'],
								'quantity_remaining' => $data['quantity'],
								'basis_initial'      => $effects['basis'],
								'basis_remaining'    => $effects['basis'],
								'acquired_on'        => $data['effective_date'],
							)
						);
					}
					foreach ( $effects['allocations'] ?? array() as $allocation ) {
						$this->db->query( 'UPDATE ' . $this->db->table( 'lots' ) . ' SET quantity_remaining = %s, basis_remaining = %s WHERE workspace_id = %d AND id = %d', array( bcadd( $allocation['quantity_remaining'], '0', 18 ), $allocation['basis_remaining'], $workspace, $allocation['lot_id'] ) );
						unset( $allocation['quantity_remaining'], $allocation['basis_remaining'] );
						$allocation['quantity'] = bcadd( $allocation['quantity'], '0', 18 );
						$allocation['proceeds'] = Decimal::money( $allocation['proceeds'] );
						$this->db->insert(
							'lot_allocations',
							$allocation + array(
								'workspace_id'    => $workspace,
								'disposal_leg_id' => $leg,
							)
						);
					}
				}
				$this->audit( $workspace, 'transaction.' . $data['state'], 'transaction', $id );
				$result = array(
					'transaction'         => $this->db->object( 'transactions', $workspace, $id ),
					'calculation_version' => Ledger::VERSION,
				);
				$this->db->insert(
					'idempotency',
					array(
						'workspace_id' => $workspace,
						'operation'    => 'transaction.create',
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

	/**
	 * Report native quantities and basis with explicit missing valuation.
	 *
	 * @param int $workspace workspace input.
	 * @param int $after after input.
	 * @param int $limit limit input.
	 * @return array
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 */
	public function holdings( int $workspace, int $after = 0, int $limit = 100 ): array {
		$this->authorize( $workspace, 'tgit_view' );
		if ( $after < 0 || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid list parameters.' );
		}
		// Asset cursor; cap assets then group their positions. No cross-currency total.
		$assets    = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'assets' ) . ' WHERE workspace_id = %d AND id > %d ORDER BY id LIMIT %d', array( $workspace, $after, $limit ) );
		$positions = array();
		foreach ( $assets as $asset ) {
			$rows = $this->db->rows( 'SELECT account_id, SUM(quantity_remaining) AS quantity, SUM(basis_remaining) AS remaining_basis FROM ' . $this->db->table( 'lots' ) . ' WHERE workspace_id = %d AND asset_id = %d GROUP BY account_id', array( $workspace, $asset['id'] ) );
			foreach ( $rows as $row ) {
				$realized    = $this->db->row( 'SELECT COALESCE(SUM(realized_gain),0) AS realized_gain FROM ' . $this->db->table( 'transactions' ) . ' WHERE workspace_id = %d AND account_id = %d AND asset_id = %d AND state = %s', array( $workspace, $row['account_id'], $asset['id'], 'posted' ) );
				$positions[] = $row + array(
					'asset_id'        => $asset['id'],
					'symbol'          => $asset['symbol'],
					'currency'        => $asset['quote_currency'],
					'realized_gain'   => $realized['realized_gain'],
					'market_value'    => null,
					'unrealized_gain' => null,
					'price_status'    => 'missing',
				);
			}
		}
		return array(
			'items'               => $positions,
			'next_cursor'         => count( $assets ) === $limit ? (string) end( $assets )['id'] : null,
			'calculation_version' => Ledger::VERSION,
			'valuation_coverage'  => 'missing',
			'base_totals'         => null,
			'as_of'               => gmdate( 'c' ),
		);
	}
}
