<?php
/**
 * Explicit append-only workspace pricing source and reversible quote-polling fence.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Domain\TickerbotAge;

/** No HTTP, implicit fallback, schedule rewrites or changes to posted facts. */
final class PricingPolicy {
	/**
	 * Persistence.
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
	 * Audit identity.
	 *
	 * @var string
	 */
	private string $correlation;
	/**
	 * Bind explicit actor and scoped persistence.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Explicit actor.
	 * @param string   $correlation Audit identity.
	 * @return void
	 */
	public function __construct( Database $db, int $actor, string $correlation ) {
		$this->db          = $db;
		$this->actor       = $actor;
		$this->correlation = $correlation;}
	/** Trusted callers must authorize the workspace before using this local gate. */
	/**
	 * Read a local policy after the caller authorizes the workspace.
	 *
	 * @param Database $db Persistence.
	 * @param int      $workspace Authorized workspace.
	 * @return array
	 */
	public static function saved( Database $db, int $workspace ): array {
		$row = $db->row( 'SELECT revision,source,previous_source,account_id FROM ' . $db->table( 'pricing_policies' ) . ' WHERE workspace_id=%d ORDER BY revision DESC LIMIT 1', array( $workspace ) );
		return $row ? array(
			'revision'        => (int) $row['revision'],
			'source'          => $row['source'],
			'previous_source' => $row['previous_source'],
			'account_id'      => null === $row['account_id'] ? null : (int) $row['account_id'],
		) : array(
			'revision'        => 0,
			'source'          => 'manual',
			'previous_source' => 'manual',
			'account_id'      => null,
		);
	}
	/**
	 * Read the shared source without internal account identifiers.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array
	 */
	public function get( int $workspace ): array {
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_view' );
		$row = self::saved( $this->db, $workspace );
		unset( $row['account_id'] );
		return $row;}
	/**
	 * Lock and reauthorize the owner workspace.
	 *
	 * @param int $workspace Workspace ID.
	 * @return void
	 */
	private function lock( int $workspace ): void {
		$this->db->row( 'SELECT id FROM ' . $this->db->table( 'workspaces' ) . ' WHERE id=%d FOR UPDATE', array( $workspace ) );
		( new Tracker( $this->db, $this->actor, $this->correlation ) )->authorize( $workspace, 'tgit_manage_members' );}
	/** All readiness evidence is read locally under the owner workspace lock. */
	/**
	 * Calculate readiness under the owner workspace lock without HTTP.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array
	 */
	private function review_locked( int $workspace ): array {
		$policy = self::saved( $this->db, $workspace );
		unset( $policy['account_id'] );
		$blockers  = array();
		$account   = null;
		$settings  = null;
		$selection = array( 'revision' => 0 );
		$inventory = array( 'items' => array() );
		$covered   = 0;
		$scope     = defined( 'TGIT_TICKERBOT_ACCOUNT_SCOPE' ) && is_string( TGIT_TICKERBOT_ACCOUNT_SCOPE ) ? TGIT_TICKERBOT_ACCOUNT_SCOPE : '';
		$key       = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || '' === $key || str_starts_with( $key, 'YOUR_' ) || strlen( $key ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $key ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $scope ) || str_starts_with( $scope, 'YOUR_' ) ) {
			$blockers[] = 'server_setup';} else {
			$account  = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tickerbot_accounts' ) . ' WHERE scope_digest=%s FOR UPDATE', array( hash( 'sha256', 'tickerbot/account/' . $scope ) ) );
			$settings = $account ? $this->db->row( 'SELECT revision,interval_seconds FROM ' . $this->db->table( 'tickerbot_settings' ) . ' WHERE workspace_id=%d AND account_id=%d', array( $workspace, $account['id'] ) ) : null;
			if ( ! $settings ) {
				$blockers[] = 'workspace_setup';} else {
				$store      = new TickerbotStore( $this->db, $this->actor, $this->correlation, $scope );
				$selection  = $store->scope_selection( $workspace );
				$inventory  = $store->inventory( $workspace );
				$now        = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
				$connection = $this->db->row( 'SELECT c.state,c.credential_digest FROM ' . $this->db->table( 'tickerbot_connections' ) . ' c JOIN ' . $this->db->table( 'tickerbot_runs' ) . ' r ON r.workspace_id=c.workspace_id AND r.id=c.run_id WHERE c.workspace_id=%d AND r.account_id=%d ORDER BY c.id DESC LIMIT 1', array( $workspace, $account['id'] ) );
				if ( ! $connection || 'connected' !== $connection['state'] || ! hash_equals( $connection['credential_digest'], hash( 'sha256', $key ) ) ) {
					$blockers[] = 'connection_check';}
				foreach ( $inventory['items']as$item ) {
					$q = 'mapped' === $item['state'] ? $store->latest_quote( $workspace, $item['asset_id'] ) : null;
					if ( $q && 'recent' === TickerbotAge::inspect( $q['price_asof'], $now, (int) $settings['interval_seconds'] )['state'] ) {
						++$covered;}
				}
				if ( ! $inventory['items'] ) {
					$blockers[] = 'empty_scope';
				} elseif ( count( $inventory['items'] ) !== $covered ) {
					$blockers[] = 'price_coverage';}
				if ( $account['paused_until'] && $account['paused_until'] > $now->format( 'Y-m-d H:i:s' ) ) {
					$blockers[] = 'provider_pause';}
				if ( $account['active_run_id'] && (int) $account['active_workspace_id'] === $workspace && $account['lease_until'] > $now->format( 'Y-m-d H:i:s' ) ) {
					$blockers[] = 'active_refresh';}
				}
			}
			if ( $this->db->row( 'SELECT id FROM ' . $this->db->table( 'provider_requests' ) . ' WHERE workspace_id=%d AND dataset=%s AND state=%s LIMIT 1', array( $workspace, 'quote', 'dispatched' ) ) ) {
				$blockers[] = 'legacy_inflight';}
			return array(
				'policy'            => $policy,
				'ready'             => ! $blockers,
				'blockers'          => $blockers,
				'settings_revision' => (int) ( $settings['revision'] ?? 0 ),
				'scope_revision'    => $selection['revision'],
				'assets'            => count( $inventory['items'] ),
				'covered_assets'    => $covered,
				'account_id'        => $account ? (int) $account['id'] : null,
			);
	}
	/**
	 * Read local switch readiness without mutation.
	 *
	 * @param int $workspace Workspace ID.
	 * @return array
	 */
	public function review( int $workspace ): array {
		return $this->db->atomic(
			function () use ( $workspace ) {
				$this->lock( $workspace );
				$r = $this->review_locked( $workspace );
				unset( $r['account_id'] );
				return $r;
			}
		);}
	/** Rollback remains available even when a server key/reference is missing. */
	/**
	 * Append an acknowledged source switch or rollback atomically.
	 *
	 * @param int    $workspace Workspace ID.
	 * @param string $source Explicit target or rollback.
	 * @param int    $expected Reviewed policy revision.
	 * @param bool   $acknowledged Scheduling acknowledgment.
	 * @param int    $settings_revision Reviewed Tickerbot settings.
	 * @param int    $scope_revision Reviewed inventory selection.
	 * @return array
	 * @throws \InvalidArgumentException On invalid explicit input.
	 */
	public function save( int $workspace, string $source, int $expected, bool $acknowledged, int $settings_revision = 0, int $scope_revision = 0 ): array {
		if ( ! $acknowledged || ! in_array( $source, array( 'manual', 'fmp', 'alpha_vantage', 'tickerbot', 'rollback' ), true ) || $expected < 0 ) {
			throw new \InvalidArgumentException( 'Choose a pricing source and acknowledge its scheduling effect.' );}
		return $this->db->atomic(
			function () use ( $workspace, $source, $expected, $settings_revision, $scope_revision ) {
				$this->lock( $workspace );
				$prior = self::saved( $this->db, $workspace );
				if ( $prior['revision'] !== $expected ) {
					throw new \UnexpectedValueException( 'Pricing policy changed; reload before switching.' );}
				$next    = 'rollback' === $source ? $prior['previous_source'] : $source;
				$account = null;
				if ( 'tickerbot' === $next ) {
					$review = $this->review_locked( $workspace );
					if ( ! $review['ready'] || $review['settings_revision'] !== $settings_revision || $review['scope_revision'] !== $scope_revision ) {
						throw new \DomainException( 'Tickerbot switch requires current setup, connection and complete recent saved-scope prices; reload readiness.' );
					}$account = $review['account_id'];}
				$previous = 'tickerbot' === $prior['source'] ? $prior['previous_source'] : $prior['source'];
				$id       = $this->db->insert(
					'pricing_policies',
					array(
						'workspace_id'      => $workspace,
						'revision'          => $expected + 1,
						'source'            => $next,
						'previous_source'   => $previous,
						'account_id'        => $account,
						'settings_revision' => $settings_revision,
						'scope_revision'    => $scope_revision,
						'actor_id'          => $this->actor,
						'created_at'        => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->db->insert(
					'audit_events',
					array(
						'workspace_id'   => $workspace,
						'actor_id'       => $this->actor,
						'action'         => 'pricing.switch',
						'entity'         => 'pricing_policies',
						'entity_id'      => $id,
						'revision'       => $expected + 1,
						'correlation_id' => $this->correlation,
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				return $this->get( $workspace );
			}
		);
	}
}
