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
use GainerInteractive\IGTradingJournal\Domain\Replay;
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
	 * @param int    $revision Evidence revision.
	 * @return void
	 */
	private function audit( int $workspace, string $action, string $entity, int $id, int $revision = 1 ): void {
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
		if ( 'transactions' === $table ) {
			return $this->db->rows( 'SELECT t.*,superseded.replacement_transaction_id AS corrected_by_id,parent.source_transaction_id AS supersedes_id FROM ' . $this->db->table( 'transactions' ) . ' t LEFT JOIN ' . $this->db->table( 'transaction_corrections' ) . ' superseded ON superseded.workspace_id = t.workspace_id AND superseded.source_transaction_id = t.id LEFT JOIN ' . $this->db->table( 'transaction_corrections' ) . ' parent ON parent.workspace_id = t.workspace_id AND parent.replacement_transaction_id = t.id WHERE t.workspace_id = %d AND t.id > %d ORDER BY t.id LIMIT %d', array( $workspace, $after, $limit ) );
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

	/** Record documented starting cash or a pre-existing asset lot before ordinary posting.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $input Opening facts and source evidence.
	 * @param string $key Idempotency identity.
	 * @return array
	 * @throws \InvalidArgumentException When opening facts are invalid.
	 */
	public function opening_balance( int $workspace, array $input, string $key ): array {
		self::fields( $input, array( 'account_id', 'asset_id', 'kind', 'effective_date', 'acquired_on', 'amount', 'quantity', 'basis_status', 'source_note' ), array( 'account_id', 'kind', 'effective_date', 'source_note' ) );
		$account_id = self::id( $input['account_id'] );
		$kind       = $input['kind'];
		if ( ! in_array( $kind, array( 'cash', 'lot' ), true ) ) {
			throw new \InvalidArgumentException( 'Opening balance kind must be cash or lot.' );
		}
		$date = is_string( $input['effective_date'] ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $input['effective_date'] ) : false;
		if ( ! $date || $date->format( 'Y-m-d' ) !== $input['effective_date'] ) {
			throw new \InvalidArgumentException( 'Opening date must be a valid YYYY-MM-DD value.' );
		}
		$source = self::text( $input['source_note'], 190 );
		$facts  = array(
			'account_id'     => $account_id,
			'kind'           => $kind,
			'effective_date' => $input['effective_date'],
			'source_note'    => $source,
		);
		if ( 'cash' === $kind ) {
			if ( array_intersect( array( 'asset_id', 'acquired_on', 'quantity', 'basis_status' ), array_keys( $input ) ) ) {
				throw new \InvalidArgumentException( 'Opening cash accepts only an amount and source note.' );
			}
			$facts['amount']       = Decimal::input( $input['amount'] ?? null, 12, true );
			$facts['basis_status'] = 'complete';
		} else {
			$facts['asset_id'] = self::id( $input['asset_id'] ?? null );
			$acquired          = is_string( $input['acquired_on'] ?? null ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $input['acquired_on'] ) : false;
			if ( ! $acquired || $acquired->format( 'Y-m-d' ) !== $input['acquired_on'] || $input['acquired_on'] > $input['effective_date'] ) {
				throw new \InvalidArgumentException( 'Acquisition date must be valid and no later than the opening date.' );
			}
			$facts['acquired_on']  = $input['acquired_on'];
			$facts['quantity']     = Decimal::input( $input['quantity'] ?? null, 18, true );
			$facts['basis_status'] = $input['basis_status'] ?? 'complete';
			if ( ! in_array( $facts['basis_status'], array( 'complete', 'unresolved' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid opening basis status.' );
			}
			if ( 'unresolved' === $facts['basis_status'] ) {
				if ( array_key_exists( 'amount', $input ) ) {
					throw new \InvalidArgumentException( 'Unresolved basis must not include an invented amount.' );
				}
				$facts['amount'] = '0';
			} else {
				$facts['amount'] = Decimal::input( $input['amount'] ?? null, 12 );
			}
		}
		return $this->mutation(
			$workspace,
			'tgit_post',
			'opening.create',
			$key,
			$facts,
			function () use ( $workspace, $facts ) {
				$account = $this->db->object( 'accounts', $workspace, $facts['account_id'] );
				if ( null !== $account['archived_at'] ) {
					throw new \InvalidArgumentException( 'Opening balance requires an active account.' );
				}
				$existing = $this->db->rows( 'SELECT action,effective_date FROM ' . $this->db->table( 'transactions' ) . ' WHERE workspace_id = %d AND account_id = %d AND state = %s ORDER BY id', array( $workspace, $facts['account_id'], 'posted' ) );
				foreach ( $existing as $event ) {
					if ( ! in_array( $event['action'], array( 'opening_cash', 'opening_lot' ), true ) || $event['effective_date'] !== $facts['effective_date'] ) {
						throw new \UnexpectedValueException( 'Opening balances must precede ordinary posting and share one opening date for the account.' );
					}
					if ( 'cash' === $facts['kind'] && 'opening_cash' === $event['action'] ) {
						throw new \UnexpectedValueException( 'This account already has opening cash.' );
					}
				}
				if ( 'cash' === $facts['kind'] && ! $existing && Decimal::compare( $account['cash_balance'], '0' ) !== 0 ) {
					throw new \UnexpectedValueException( 'Account balance does not match an empty opening history.' );
				}
				$asset_id = $facts['asset_id'] ?? null;
				if ( null !== $asset_id ) {
					$asset = $this->db->object( 'assets', $workspace, $asset_id );
					if ( $asset['quote_currency'] !== $account['native_currency'] ) {
						throw new \InvalidArgumentException( 'Opening lot and account currencies must match.' );
					}
				}
				$action   = 'cash' === $facts['kind'] ? 'opening_cash' : 'opening_lot';
				$quantity = $facts['quantity'] ?? '0';
				$id       = $this->db->insert(
					'transactions',
					array(
						'workspace_id'   => $workspace,
						'uuid'           => wp_generate_uuid4(),
						'account_id'     => $facts['account_id'],
						'asset_id'       => $asset_id,
						'action'         => $action,
						'effective_date' => $facts['effective_date'],
						'state'          => 'posted',
						'quantity'       => $quantity,
						'unit_price'     => '0',
						'fees'           => '0',
						'amount'         => $facts['amount'],
						'currency'       => $account['native_currency'],
						'realized_gain'  => '0',
						'created_by'     => $this->actor,
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'revision'       => 1,
						'payload'        => wp_json_encode( $facts ),
						'actor_id'       => $this->actor,
						'reason'         => 'Documented opening balance',
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$leg     = $this->db->insert(
					'transaction_legs',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'account_id'     => $facts['account_id'],
						'asset_id'       => $asset_id,
						'quantity_delta' => $quantity,
						'cash_delta'     => 'cash' === $facts['kind'] ? $facts['amount'] : '0',
						'currency'       => $account['native_currency'],
						'role'           => $action,
					)
				);
				$opening = $this->db->insert(
					'opening_balances',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'account_id'     => $facts['account_id'],
						'asset_id'       => $asset_id,
						'acquired_on'    => $facts['acquired_on'] ?? null,
						'basis_status'   => $facts['basis_status'],
						'source_note'    => $facts['source_note'],
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				if ( 'cash' === $facts['kind'] ) {
					$this->db->query( 'UPDATE ' . $this->db->table( 'accounts' ) . ' SET cash_balance = %s WHERE workspace_id = %d AND id = %d', array( Decimal::money( Decimal::add( $account['cash_balance'], $facts['amount'] ) ), $workspace, $facts['account_id'] ) );
				} else {
					$this->db->insert(
						'lots',
						array(
							'workspace_id'       => $workspace,
							'account_id'         => $facts['account_id'],
							'asset_id'           => $asset_id,
							'acquisition_leg_id' => $leg,
							'quantity_initial'   => $quantity,
							'quantity_remaining' => $quantity,
							'basis_initial'      => $facts['amount'],
							'basis_remaining'    => $facts['amount'],
							'acquired_on'        => $facts['acquired_on'],
						)
					);
				}
				$this->audit( $workspace, 'opening.created', 'transaction', $id );
				return array(
					'transaction' => $this->db->object( 'transactions', $workspace, $id ),
					'opening'     => $this->db->object( 'opening_balances', $workspace, $opening ),
				);
			}
		);
	}

	/**
	 * Execute a workspace mutation atomically with a saved retry result.
	 *
	 * @param int      $workspace Workspace identifier.
	 * @param string   $capability Required contextual grant.
	 * @param string   $operation Idempotency operation scope.
	 * @param string   $key Client request identity.
	 * @param array    $command Normalized request for conflict detection.
	 * @param callable $callback Authorized mutation body.
	 * @return array
	 * @throws \InvalidArgumentException When the request identity is invalid.
	 */
	private function mutation( int $workspace, string $capability, string $operation, string $key, array $command, callable $callback ): array {
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'An 8-128 character Idempotency-Key is required.' );
		}
		$hash = hash( 'sha256', wp_json_encode( $command ) );
		return $this->db->atomic(
			function () use ( $workspace, $capability, $operation, $key, $hash, $callback ) {
				$this->lock( $workspace, $capability );
				$existing = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'idempotency' ) . ' WHERE workspace_id = %d AND operation = %s AND request_key = %s', array( $workspace, $operation, $key ) );
				if ( $existing ) {
					if ( ! hash_equals( $existing['request_hash'], $hash ) ) {
						throw new \UnexpectedValueException( 'Idempotency key was used with different input.' );
					}
					return json_decode( $existing['result'], true, 512, JSON_THROW_ON_ERROR );
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

	/**
	 * Commit a draft or financial event without nested database transactions.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $input Transaction facts.
	 * @param string $key Client request identity.
	 * @return array
	 */
	public function post( int $workspace, array $input, string $key ): array {
		$data       = $this->transaction_input( $input );
		$capability = 'posted' === $data['state'] ? 'tgit_post' : 'tgit_create_draft';
		return $this->mutation(
			$workspace,
			$capability,
			'transaction.create',
			$key,
			$data,
			function () use ( $workspace, $data ) {
				return $this->persist_transaction( $workspace, $data );
			}
		);
	}

	/**
	 * Require an editable draft at the client's expected revision.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $id Draft identifier.
	 * @param int $revision Expected revision.
	 * @return array
	 * @throws \UnexpectedValueException When the draft is stale or already posted.
	 */
	private function editable_draft( int $workspace, int $id, int $revision ): array {
		$draft = $this->db->object( 'transactions', $workspace, $id );
		if ( 'draft' !== $draft['state'] || $revision !== (int) $draft['revision'] ) {
			throw new \UnexpectedValueException( 'The draft changed or has already been promoted. Reload its latest revision.' );
		}
		if ( $this->actor !== (int) $draft['created_by'] ) {
			$this->authorize( $workspace, 'tgit_post' );
		}
		return $draft;
	}

	/**
	 * Replace draft facts and preserve its prior revision without ledger effects.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $id Draft identifier.
	 * @param array  $input Expected revision and replacement facts.
	 * @param string $key Client request identity.
	 * @return array
	 * @throws \InvalidArgumentException When replacement facts are not a draft.
	 */
	public function edit_draft( int $workspace, int $id, array $input, string $key ): array {
		self::fields( $input, array( 'expected_revision', 'transaction' ), array( 'expected_revision', 'transaction' ) );
		$revision = self::id( $input['expected_revision'] );
		if ( ! is_array( $input['transaction'] ) ) {
			throw new \InvalidArgumentException( 'Replacement transaction must be a JSON object.' );
		}
		$data = $this->transaction_input( $input['transaction'] );
		if ( 'draft' !== $data['state'] ) {
			throw new \InvalidArgumentException( 'Use the dedicated promotion operation to post a draft.' );
		}
		$command = array(
			'expected_revision' => $revision,
			'transaction'       => $data,
		);
		return $this->mutation(
			$workspace,
			'tgit_create_draft',
			'transaction.draft.' . $id,
			$key,
			$command,
			function () use ( $workspace, $id, $revision, $data ) {
				$this->editable_draft( $workspace, $id, $revision );
				$link = $this->db->row( 'SELECT trade_id FROM ' . $this->db->table( 'trade_fills' ) . ' WHERE workspace_id = %d AND transaction_id = %d', array( $workspace, $id ) );
				if ( $link ) {
					$trade = $this->db->object( 'trades', $workspace, (int) $link['trade_id'] );
					if ( $data['asset_id'] !== (int) $trade['asset_id'] || ! in_array( $data['action'], array( 'buy', 'sell' ), true ) ) {
						throw new \InvalidArgumentException( 'Unlink the draft from its trade before changing its asset or financial action.' );
					}
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
				$this->db->update_object( 'transactions', $workspace, $id, $data + array( 'revision' => $revision + 1 ) );
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'revision'       => $revision + 1,
						'payload'        => wp_json_encode( $data ),
						'actor_id'       => $this->actor,
						'reason'         => 'Draft edited',
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'draft.edited', 'transaction', $id, $revision + 1 );
				return array( 'transaction' => $this->db->object( 'transactions', $workspace, $id ) );
			}
		);
	}

	/**
	 * Promote a draft once and preserve its revision trail and financial link.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $id Draft identifier.
	 * @param array  $input Expected revision.
	 * @param string $key Client request identity.
	 * @return array
	 */
	public function promote_draft( int $workspace, int $id, array $input, string $key ): array {
		self::fields( $input, array( 'expected_revision' ), array( 'expected_revision' ) );
		$revision = self::id( $input['expected_revision'] );
		return $this->mutation(
			$workspace,
			'tgit_post',
			'transaction.post.' . $id,
			$key,
			array( 'expected_revision' => $revision ),
			function () use ( $workspace, $id, $revision ) {
				$draft               = $this->editable_draft( $workspace, $id, $revision );
				$input               = array_intersect_key( $draft, array_flip( array( 'account_id', 'action', 'effective_date', 'currency' ) ) );
				$input['account_id'] = (int) $input['account_id'];
				$input['state']      = 'posted';
				if ( in_array( $draft['action'], array( 'buy', 'sell' ), true ) ) {
					$input            += array_intersect_key( $draft, array_flip( array( 'quantity', 'unit_price', 'fees' ) ) );
					$input['asset_id'] = (int) $draft['asset_id'];
				} else {
					$input['amount'] = $draft['amount'];
				}
				$result = $this->persist_transaction( $workspace, $this->transaction_input( $input ) );
				$this->db->update_object(
					'transactions',
					$workspace,
					$id,
					array(
						'state'    => 'promoted',
						'revision' => $revision + 1,
					)
				);
				// A linked draft becomes a posted fill without detaching its trade-level gallery.
				$link = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'trade_fills' ) . ' WHERE workspace_id = %d AND transaction_id = %d', array( $workspace, $id ) );
				if ( $link ) {
					$trade_id                   = (int) $link['trade_id'];
					$trade_record               = $this->db->object( 'trades', $workspace, $trade_id );
					$journal_record             = $this->db->row( 'SELECT payload FROM ' . $this->db->table( 'trade_journals' ) . ' WHERE workspace_id = %d AND trade_id = %d AND revision = %d', array( $workspace, $trade_id, (int) $trade_record['revision'] ) );
					$payload                    = json_decode( $journal_record['payload'], true, 512, JSON_THROW_ON_ERROR );
					$payload['transaction_ids'] = array_map(
						static function ( $fill ) use ( $id, $result ) {
							return (int) $fill === $id ? (int) $result['transaction']['id'] : (int) $fill;
						},
						$payload['transaction_ids']
					);
					$trade_revision             = (int) $trade_record['revision'] + 1;
					$this->db->update_object( 'trade_fills', $workspace, (int) $link['id'], array( 'transaction_id' => (int) $result['transaction']['id'] ) );
					$this->db->update_object( 'trades', $workspace, $trade_id, array( 'revision' => $trade_revision ) );
					$this->db->insert(
						'trade_journals',
						array(
							'workspace_id' => $workspace,
							'trade_id'     => $trade_id,
							'revision'     => $trade_revision,
							'payload'      => wp_json_encode( $payload ),
							'actor_id'     => $this->actor,
							'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						)
					);
					$this->audit( $workspace, 'trade.fill_promoted', 'trade', $trade_id, $trade_revision );
				}

				$record = $this->db->object( 'transactions', $workspace, $id );
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'revision'       => $revision + 1,
						'payload'        => wp_json_encode(
							array(
								'draft'                 => $record,
								'posted_transaction_id' => $result['transaction']['id'],
							)
						),
						'actor_id'       => $this->actor,
						'reason'         => 'Draft promoted',
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit( $workspace, 'draft.promoted', 'transaction', $id, $revision + 1 );
				return $result + array( 'draft' => $record );
			}
		);
	}

	/**
	 * Retrieve authorized facts and all immutable revision evidence.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $id Transaction identifier.
	 * @return array
	 */
	public function transaction( int $workspace, int $id ): array {
		$this->authorize( $workspace, 'tgit_view' );
		return array(
			'transaction'      => $this->db->object( 'transactions', $workspace, $id ),
			'revisions'        => $this->db->rows( 'SELECT revision,payload,actor_id,reason,created_at FROM ' . $this->db->table( 'transaction_revisions' ) . ' WHERE workspace_id = %d AND transaction_id = %d ORDER BY revision', array( $workspace, $id ) ),
			'correction'       => $this->db->row( 'SELECT * FROM ' . $this->db->table( 'transaction_corrections' ) . ' WHERE workspace_id = %d AND (source_transaction_id = %d OR replacement_transaction_id = %d) ORDER BY id DESC LIMIT 1', array( $workspace, $id, $id ) ),
			'correction_links' => $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'transaction_corrections' ) . ' WHERE workspace_id = %d AND (source_transaction_id = %d OR replacement_transaction_id = %d) ORDER BY id', array( $workspace, $id, $id ) ),
		);
	}

	/**
	 * Read only currently active posted events for an account.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $account Account identifier.
	 * @return array
	 */
	private function active_account_events( int $workspace, int $account ): array {
		return $this->db->rows( 'SELECT t.*,o.acquired_on,o.basis_status,COALESCE(parent.chronology_id,t.id) AS order_id FROM ' . $this->db->table( 'transactions' ) . ' t LEFT JOIN ' . $this->db->table( 'opening_balances' ) . ' o ON o.workspace_id = t.workspace_id AND o.transaction_id = t.id LEFT JOIN ' . $this->db->table( 'transaction_corrections' ) . ' superseded ON superseded.workspace_id = t.workspace_id AND superseded.source_transaction_id = t.id LEFT JOIN ' . $this->db->table( 'transaction_corrections' ) . ' parent ON parent.workspace_id = t.workspace_id AND parent.replacement_transaction_id = t.id WHERE t.workspace_id = %d AND t.account_id = %d AND t.state = %s AND superseded.id IS NULL ORDER BY t.effective_date,order_id', array( $workspace, $account, 'posted' ) );
	}

	/**
	 * Preview a chronological insertion without changing posted history.
	 *
	 * @param int   $workspace Workspace identifier.
	 * @param array $input Complete proposed posted transaction.
	 * @return array Projected cash and affected realized gains.
	 * @throws \InvalidArgumentException When proposed facts or chronology are invalid.
	 * @throws \RuntimeException When stored cash disagrees with posted history.
	 */
	public function replay_preview( int $workspace, array $input ): array {
		$this->authorize( $workspace, 'tgit_post' );
		$data = $this->transaction_input( $input );
		if ( 'posted' !== $data['state'] ) {
			throw new \InvalidArgumentException( 'Replay preview requires posted transaction facts.' );
		}
		$account = $this->db->object( 'accounts', $workspace, $data['account_id'] );
		if ( null !== $account['archived_at'] || $account['native_currency'] !== $data['currency'] ) {
			throw new \InvalidArgumentException( 'Use an active account in the transaction currency.' );
		}
		if ( null !== $data['asset_id'] ) {
			$asset = $this->db->object( 'assets', $workspace, $data['asset_id'] );
			if ( $asset['quote_currency'] !== $data['currency'] ) {
				throw new \InvalidArgumentException( 'Asset and account currencies must match.' );
			}
		}
		$existing = $this->active_account_events( $workspace, $data['account_id'] );
		foreach ( $existing as $event ) {
			if ( str_starts_with( $event['action'], 'opening_' ) && $data['effective_date'] < $event['effective_date'] ) {
				throw new \InvalidArgumentException( 'Historical transaction cannot precede the account opening date.' );
			}
		}
		$before = Replay::calculate( $existing );
		if ( Decimal::compare( $before['cash_balance'], $account['cash_balance'] ) !== 0 ) {
			throw new \RuntimeException( 'Account projection differs from posted history; repair is required before replay.' );
		}
		$proposal_id = $existing ? 1 + max( array_map( static fn( array $event ): int => (int) $event['id'], $existing ) ) : 1;
		$proposal    = array( 'id' => $proposal_id ) + $data;
		$after       = Replay::calculate( array_merge( $existing, array( $proposal ) ) );
		$changed     = array();
		foreach ( $existing as $event ) {
			$id = (int) $event['id'];
			if ( Decimal::compare( $before['effects'][ $id ]['realized_gain'], $after['effects'][ $id ]['realized_gain'] ) !== 0 ) {
				$changed[] = array(
					'transaction_id'       => $id,
					'realized_gain_before' => $before['effects'][ $id ]['realized_gain'],
					'realized_gain_after'  => $after['effects'][ $id ]['realized_gain'],
				);
			}
		}
		return array(
			'applied'                => false,
			'account_id'             => $data['account_id'],
			'currency'               => $data['currency'],
			'cash_before'            => $before['cash_balance'],
			'cash_after'             => $after['cash_balance'],
			'proposed_cash_delta'    => $after['effects'][ $proposal_id ]['cash_delta'],
			'proposed_realized_gain' => $after['effects'][ $proposal_id ]['realized_gain'],
			'changed_realized_gains' => $changed,
			'source_fingerprint'     => hash( 'sha256', wp_json_encode( $existing ) ),
		);
	}

	/**
	 * Supersede one posted cash event without rewriting its financial facts.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $id Posted cash transaction identifier.
	 * @param array  $input Expected revision, reason and replacement facts.
	 * @param string $key Idempotency identity.
	 * @return array Source, replacement and correction evidence.
	 * @throws \InvalidArgumentException When cash correction facts are invalid.
	 */
	public function correct_cash( int $workspace, int $id, array $input, string $key ): array {
		self::fields( $input, array( 'expected_revision', 'reason', 'replacement' ), array( 'expected_revision', 'reason', 'replacement' ) );
		$revision = self::id( $input['expected_revision'] );
		$reason   = self::text( $input['reason'], 190 );
		if ( ! is_array( $input['replacement'] ) ) {
			throw new \InvalidArgumentException( 'Replacement must be a JSON object.' );
		}
		$replacement = $this->transaction_input( $input['replacement'] );
		if ( 'posted' !== $replacement['state'] || ! in_array( $replacement['action'], array( 'deposit', 'withdrawal' ), true ) ) {
			throw new \InvalidArgumentException( 'This correction path accepts posted cash movements only.' );
		}
		$command = array(
			'expected_revision' => $revision,
			'reason'            => $reason,
			'replacement'       => $replacement,
		);
		return $this->mutation(
			$workspace,
			'tgit_post',
			'transaction.correct.' . $id,
			$key,
			$command,
			function () use ( $workspace, $id, $revision, $reason, $replacement ) {
				$source = $this->db->object( 'transactions', $workspace, $id );
				if ( 'posted' !== $source['state'] || ! in_array( $source['action'], array( 'deposit', 'withdrawal' ), true ) || (int) $source['revision'] !== $revision ) {
					throw new \UnexpectedValueException( 'Posted cash transaction changed or is not correctable.' );
				}
				if ( (int) $source['account_id'] !== $replacement['account_id'] || $source['currency'] !== $replacement['currency'] ) {
					throw new \InvalidArgumentException( 'Cash correction must retain its account and currency.' );
				}
				$prior = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'transaction_corrections' ) . ' WHERE workspace_id = %d AND source_transaction_id = %d', array( $workspace, $id ) );
				if ( $prior ) {
					throw new \UnexpectedValueException( 'This transaction was already superseded.' );
				}
				$account = $this->db->object( 'accounts', $workspace, $replacement['account_id'] );
				if ( null !== $account['archived_at'] ) {
					throw new \InvalidArgumentException( 'Correct an active account only.' );
				}
				$existing = $this->active_account_events( $workspace, $replacement['account_id'] );
				$before   = Replay::calculate( $existing );
				if ( Decimal::compare( $before['cash_balance'], $account['cash_balance'] ) !== 0 ) {
					throw new \RuntimeException( 'Account projection differs from posted history; repair is required.' );
				}
				$active_source = null;
				$revised       = array();
				foreach ( $existing as $event ) {
					if ( (int) $event['id'] === $id ) {
						$active_source = $event;
						continue;
					}
					if ( str_starts_with( $event['action'], 'opening_' ) && $replacement['effective_date'] < $event['effective_date'] ) {
						throw new \InvalidArgumentException( 'Correction cannot precede the account opening date.' );
					}
					$revised[] = $event;
				}
				if ( null === $active_source ) {
					throw new \UnexpectedValueException( 'Source is not an active posted transaction.' );
				}
				$preview_id = $existing ? 1 + max( array_map( static fn( array $event ): int => (int) $event['id'], $existing ) ) : 1;
				$proposal   = array(
					'id'       => $preview_id,
					'order_id' => (int) $active_source['order_id'],
				) + $replacement;
				$after      = Replay::calculate( array_merge( $revised, array( $proposal ) ) );
				$new_id     = $this->db->insert(
					'transactions',
					$replacement + array(
						'workspace_id'  => $workspace,
						'uuid'          => wp_generate_uuid4(),
						'realized_gain' => '0',
						'created_by'    => $this->actor,
						'created_at'    => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $new_id,
						'revision'       => 1,
						'payload'        => wp_json_encode( $replacement ),
						'actor_id'       => $this->actor,
						'reason'         => $reason,
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$delta = $after['effects'][ $preview_id ]['cash_delta'];
				$this->db->insert(
					'transaction_legs',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $new_id,
						'account_id'     => $replacement['account_id'],
						'asset_id'       => null,
						'quantity_delta' => '0',
						'cash_delta'     => $delta,
						'currency'       => $replacement['currency'],
						'role'           => $replacement['action'],
					)
				);
				$correction_id = $this->db->insert(
					'transaction_corrections',
					array(
						'workspace_id'               => $workspace,
						'source_transaction_id'      => $id,
						'replacement_transaction_id' => $new_id,
						'chronology_id'              => (int) $active_source['order_id'],
						'source_revision'            => $revision + 1,
						'actor_id'                   => $this->actor,
						'reason'                     => $reason,
						'created_at'                 => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->update_object( 'transactions', $workspace, $id, array( 'revision' => $revision + 1 ) );
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $id,
						'revision'       => $revision + 1,
						'payload'        => wp_json_encode(
							array(
								'superseded_by' => $new_id,
								'reason'        => $reason,
							)
						),
						'actor_id'       => $this->actor,
						'reason'         => $reason,
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->query( 'UPDATE ' . $this->db->table( 'accounts' ) . ' SET cash_balance = %s WHERE workspace_id = %d AND id = %d', array( $after['cash_balance'], $workspace, $replacement['account_id'] ) );
				$this->audit( $workspace, 'transaction.corrected', 'transaction', $id, $revision + 1 );
				$this->audit( $workspace, 'transaction.replaced', 'transaction', $new_id );
				return array(
					'source'      => $this->db->object( 'transactions', $workspace, $id ),
					'replacement' => $this->db->object( 'transactions', $workspace, $new_id ),
					'correction'  => $this->db->object( 'transaction_corrections', $workspace, $correction_id ),
					'cash_after'  => $after['cash_balance'],
				);
			}
		);
	}

	/**
	 * Insert a historical cash movement after validating every later balance.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param array  $input Posted deposit or withdrawal facts.
	 * @param string $key Idempotency identity.
	 * @return array Posted event and rebuilt current cash.
	 * @throws \InvalidArgumentException When history would be invalid.
	 */
	public function post_historical_cash( int $workspace, array $input, string $key ): array {
		$data = $this->transaction_input( $input );
		if ( 'posted' !== $data['state'] || ! in_array( $data['action'], array( 'deposit', 'withdrawal' ), true ) ) {
			throw new \InvalidArgumentException( 'Historical cash entry requires a posted deposit or withdrawal.' );
		}
		return $this->mutation(
			$workspace,
			'tgit_post',
			'transaction.historical_cash',
			$key,
			$data,
			function () use ( $workspace, $data ) {
				$account = $this->db->object( 'accounts', $workspace, $data['account_id'] );
				if ( null !== $account['archived_at'] || $account['native_currency'] !== $data['currency'] ) {
					throw new \InvalidArgumentException( 'Use an active account in the transaction currency.' );
				}
				$existing = $this->active_account_events( $workspace, $data['account_id'] );
				foreach ( $existing as $event ) {
					if ( str_starts_with( $event['action'], 'opening_' ) && $data['effective_date'] < $event['effective_date'] ) {
						throw new \InvalidArgumentException( 'Historical cash cannot precede the account opening date.' );
					}
				}
				$before = Replay::calculate( $existing );
				if ( Decimal::compare( $before['cash_balance'], $account['cash_balance'] ) !== 0 ) {
					throw new \RuntimeException( 'Account projection differs from posted history; repair is required.' );
				}
				$preview_id = $existing ? 1 + max( array_map( static fn( array $event ): int => (int) $event['id'], $existing ) ) : 1;
				$after      = Replay::calculate( array_merge( $existing, array( array( 'id' => $preview_id ) + $data ) ) );
				$new_id     = $this->db->insert(
					'transactions',
					$data + array(
						'workspace_id'  => $workspace,
						'uuid'          => wp_generate_uuid4(),
						'realized_gain' => '0',
						'created_by'    => $this->actor,
						'created_at'    => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'transaction_revisions',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $new_id,
						'revision'       => 1,
						'payload'        => wp_json_encode( $data ),
						'actor_id'       => $this->actor,
						'reason'         => 'Historical cash entry',
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'transaction_legs',
					array(
						'workspace_id'   => $workspace,
						'transaction_id' => $new_id,
						'account_id'     => $data['account_id'],
						'asset_id'       => null,
						'quantity_delta' => '0',
						'cash_delta'     => $after['effects'][ $preview_id ]['cash_delta'],
						'currency'       => $data['currency'],
						'role'           => $data['action'],
					)
				);
				$this->db->query( 'UPDATE ' . $this->db->table( 'accounts' ) . ' SET cash_balance = %s WHERE workspace_id = %d AND id = %d', array( $after['cash_balance'], $workspace, $data['account_id'] ) );
				$this->audit( $workspace, 'transaction.historical_cash', 'transaction', $new_id );
				return array(
					'transaction' => $this->db->object( 'transactions', $workspace, $new_id ),
					'cash_after'  => $after['cash_balance'],
				);
			}
		);
	}

	/**
	 * Persist validated transaction facts and effects inside the caller's transaction.
	 *
	 * @throws \InvalidArgumentException When account or financial facts are invalid.
	 * @throws \UnexpectedValueException When chronological posting is unavailable.
	 *
	 * @param int   $workspace Workspace identifier.
	 * @param array $data Normalized transaction facts.
	 * @return array
	 */
	private function persist_transaction( int $workspace, array $data ): array {
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
				$unresolved = $this->db->row( 'SELECT o.id FROM ' . $this->db->table( 'lots' ) . ' l INNER JOIN ' . $this->db->table( 'transaction_legs' ) . ' leg ON leg.workspace_id = l.workspace_id AND leg.id = l.acquisition_leg_id INNER JOIN ' . $this->db->table( 'opening_balances' ) . ' o ON o.workspace_id = leg.workspace_id AND o.transaction_id = leg.transaction_id WHERE l.workspace_id = %d AND l.account_id = %d AND l.asset_id = %d AND l.quantity_remaining > 0 AND o.basis_status = %s LIMIT 1', array( $workspace, $data['account_id'], $data['asset_id'], 'unresolved' ) );
				if ( $unresolved ) {
					throw new \UnexpectedValueException( 'Resolve the opening lot basis before posting a sale of this asset.' );
				}
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
		return $result;
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
			$rows = $this->db->rows( 'SELECT l.account_id, SUM(l.quantity_remaining) AS quantity, SUM(l.basis_remaining) AS remaining_basis, SUM(CASE WHEN o.basis_status = %s AND l.quantity_remaining > 0 THEN 1 ELSE 0 END) AS unresolved_count FROM ' . $this->db->table( 'lots' ) . ' l LEFT JOIN ' . $this->db->table( 'transaction_legs' ) . ' leg ON leg.workspace_id = l.workspace_id AND leg.id = l.acquisition_leg_id LEFT JOIN ' . $this->db->table( 'opening_balances' ) . ' o ON o.workspace_id = leg.workspace_id AND o.transaction_id = leg.transaction_id WHERE l.workspace_id = %d AND l.asset_id = %d GROUP BY l.account_id', array( 'unresolved', $workspace, $asset['id'] ) );
			foreach ( $rows as $row ) {
				$unresolved = (int) $row['unresolved_count'] > 0;
				unset( $row['unresolved_count'] );
				if ( $unresolved ) {
					$row['remaining_basis'] = null;
				}
				$realized    = $this->db->row( 'SELECT COALESCE(SUM(realized_gain),0) AS realized_gain FROM ' . $this->db->table( 'transactions' ) . ' WHERE workspace_id = %d AND account_id = %d AND asset_id = %d AND state = %s', array( $workspace, $row['account_id'], $asset['id'], 'posted' ) );
				$positions[] = $row + array(
					'basis_status'    => $unresolved ? 'unresolved' : 'complete',
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
