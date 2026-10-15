<?php
/**
 * One counted catalogue verification; no implicit quote or coverage requests.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;

/** Server proof, mapping and selective receipt remain separate from browser inputs. */
final class TickerbotResolution {
	/**
	 * Verify or read one immutable mapping operation.
	 *
	 * @param TickerbotStore $store Trusted owner/account context.
	 * @param int            $workspace Workspace ID.
	 * @param int            $asset Local asset ID.
	 * @param string         $symbol Explicit canonical candidate.
	 * @param int            $expected Reviewed mapping ID.
	 * @param string         $key Stable UUID.
	 * @param callable|null  $transport Disposable fixture transport only.
	 * @return array Safe receipt or uncertainty; no identity row/provider body.
	 * @throws \InvalidArgumentException On malformed command.
	 * @throws \DomainException On stale mapping revision.
	 */
	public static function run( TickerbotStore $store, int $workspace, int $asset, string $symbol, int $expected, string $key, ?callable $transport = null ): array {
		TickerbotQuote::symbol( $symbol );
		if ( $asset < 1 || $expected < 0 || strlen( $symbol ) > 64 || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'A valid mapping operation and stable UUID are required.' ); }
		$target = $store->mapping_current( $workspace, $asset );
		if ( ! $store->readiness( $workspace )['enrolled'] ) {
			return array( 'state' => 'not_enrolled' ); }
		$prior = $store->resolution_status( $workspace, $key, $asset, $symbol, $expected );
		if ( null !== $prior ) {
			return $prior; }
		$credential = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || '' === $credential || str_starts_with( $credential, 'YOUR_' ) || strlen( $credential ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $credential ) ) {
			return array( 'state' => 'disabled' ); }
		$row = $target['asset'];
		if ( ! in_array( $row['asset_class'], array( 'stock', 'etf', 'crypto' ), true ) || ( 'crypto' === $row['asset_class'] ? 'USD' !== $row['quote_currency'] || 'X:' . $row['symbol'] . 'USD' !== $symbol : $row['symbol'] !== $symbol ) ) {
			return array( 'state' => 'unsupported_identity' ); }
		if ( (int) ( $target['mapping']['id'] ?? 0 ) !== $expected ) {
			throw new \DomainException( 'Mapping changed; reload before verifying.' ); }
		$run = $store->start_run( $workspace, 'resolution:' . $key, 'resolution' );
		if ( ! isset( $run['lease_token'] ) ) {
			return 'joined' === $run['state'] ? array( 'state' => 'busy' ) : ( $store->resolution_status( $workspace, $key, $asset, $symbol, $expected ) ?? array( 'state' => $run['state'] ) );
		}
		$row       = $store->bind_resolution( $workspace, $run['run_id'], $run['lease_token'], $asset, $symbol, $expected );
		$admission = null;
		$provider  = new TickerbotProvider(
			function ( string $family ) use ( $store, $workspace, $run, &$admission ): bool {
				$admission = $store->reserve_attempt( $workspace, $run['run_id'], $run['lease_token'], $family );
				return 'admitted' === $admission['state'];
			},
			$transport
		);
		$result    = $provider->resolve( $row, $symbol );
		$attempt   = (int) ( $admission['attempt_id'] ?? 0 );
		$state     = $attempt ? $result['state'] : ( $admission['state'] ?? $result['state'] );
		if ( $attempt ) {
			$store->finish_attempt( $workspace, $run['run_id'], $run['lease_token'], $attempt, in_array( $state, array( 'mapped', 'ambiguous', 'unsupported' ), true ) && ! isset( $result['http_status'] ) ? 'completed' : $state, $result['retry_after_seconds'] ?? null ); }
		$store->finish_resolution( $workspace, $run['run_id'], $run['lease_token'], $attempt, $state, $result['identity'] ?? null );
		$store->finish_run( $workspace, $run['run_id'], $run['lease_token'], 'mapped' === $state ? 'completed' : 'failed' );
		return $store->resolution_status( $workspace, $key, $asset, $symbol, $expected );
	}
}
