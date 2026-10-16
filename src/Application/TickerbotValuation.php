<?php
/**
 * Cached Tickerbot holding prices; never external I/O or historical report evidence.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Application;

use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Domain\TickerbotAge;

/** Cached prices remain separate from historical manual evidence. */
final class TickerbotValuation {
	/**
	 * Read current-account cached marks without external I/O.
	 *
	 * @param Database $db Persistence.
	 * @param int      $actor Authorized member.
	 * @param int      $workspace Workspace ID.
	 * @param array    $positions Complete current page of positions.
	 * @return array
	 */
	public static function prices( Database $db, int $actor, int $workspace, array $positions ): array {
		( new Tracker( $db, $actor, 'cached-valuation' ) )->authorize( $workspace, 'tgit_view' );
		$result = array();
		$scope  = defined( 'TGIT_TICKERBOT_ACCOUNT_SCOPE' ) && is_string( TGIT_TICKERBOT_ACCOUNT_SCOPE ) ? TGIT_TICKERBOT_ACCOUNT_SCOPE : '';
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $scope ) || str_starts_with( $scope, 'YOUR_' ) ) {
			return $result;}
		$settings = $db->row( 'SELECT s.interval_seconds,a.id AS account_id FROM ' . $db->table( 'tickerbot_settings' ) . ' s JOIN ' . $db->table( 'tickerbot_accounts' ) . ' a ON a.id=s.account_id WHERE s.workspace_id=%d AND a.scope_digest=%s', array( $workspace, hash( 'sha256', 'tickerbot/account/' . $scope ) ) );
		$policy   = PricingPolicy::saved( $db, $workspace );
		if ( ! $settings || ( 'tickerbot' === $policy['source'] && $policy['account_id'] !== (int) $settings['account_id'] ) ) {
			return $result;}
		$store = new TickerbotStore( $db, $actor, 'cached-valuation', $scope );
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		foreach ( $positions as$p ) {
			$id = $p['asset_id'];
			if ( isset( $result[ $id ] ) ) {
				continue;
			}$asset = $db->object( 'assets', $workspace, $id );
			$map    = $db->row( 'SELECT * FROM ' . $db->table( 'tickerbot_mappings' ) . ' WHERE workspace_id=%d AND asset_id=%d ORDER BY id DESC LIMIT 1', array( $workspace, $id ) );
			if ( 'mapped' !== TickerbotInventory::entry( $asset, $map, array() )['state'] ) {
				continue;
			}$q = $store->latest_quote( $workspace, $id );
			if ( ! $q ) {
				continue;
			}$age          = TickerbotAge::inspect( $q['price_asof'], $now, (int) $settings['interval_seconds'] );
			$state         = 'recent' === $age['state'] ? 'complete' : ( 'stale' === $age['state'] ? 'stale' : 'unknown' );
			$result[ $id ] = array(
				'status'      => $state,
				'observation' => array(
					'id'                    => $q['id'],
					'kind'                  => 'tickerbot_mark',
					'source'                => 'Tickerbot ' . $q['price_source'],
					'value'                 => $q['price'],
					'effective_date'        => $q['price_asof'] ? substr( $q['price_asof'], 0, 10 ) : null,
					'price_asof'            => $q['price_asof'],
					'response_as_of'        => $q['response_as_of'],
					'retrieved_at'          => $q['last_received_at'],
					'source_time_semantics' => $q['source_time_semantics'],
					'mapping_id'            => $q['mapping_id'],
					'cohort'                => 'tickerbot-run:' . $q['received_run_id'],
					'age'                   => $age,
				),
			);
		}return $result;
	}
}
