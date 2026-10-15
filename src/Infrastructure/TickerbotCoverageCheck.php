<?php
/**
 * One explicit counted coverage request; never a quote/valuation operation.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;

/** Only the new command creator receives a send capability. */
final class TickerbotCoverageCheck {
	/**
	 * Execute or read a fixed mapping-bound coverage command.
	 *
	 * @param TickerbotStore $store Trusted owner/account service.
	 * @param int            $workspace Workspace ID.
	 * @param int            $asset Asset ID.
	 * @param int            $mapping Reviewed mapping ID.
	 * @param string         $key Stable UUID.
	 * @param callable|null  $transport Disposable fixture transport only.
	 * @return array Selective metadata receipt or uncertainty.
	 * @throws \InvalidArgumentException On malformed command identity.
	 */
	public static function run( TickerbotStore $store, int $workspace, int $asset, int $mapping, string $key, ?callable $transport = null ): array {
		if ( $asset < 1 || $mapping < 1 || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'A verified mapping and stable coverage UUID are required.' ); }
		$store->mapping_current( $workspace, $asset );
		if ( ! $store->readiness( $workspace )['enrolled'] ) {
			return array( 'state' => 'not_enrolled' ); }
		$prior = $store->coverage_status( $workspace, $key, $asset, $mapping );
		if ( null !== $prior ) {
			return $prior; }
		$credential = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || '' === $credential || str_starts_with( $credential, 'YOUR_' ) || strlen( $credential ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $credential ) ) {
			return array( 'state' => 'disabled' ); }
		$store->coverage_target( $workspace, $asset, $mapping );
		$run = $store->start_run( $workspace, 'coverage:' . $key, 'coverage' );
		if ( ! isset( $run['lease_token'] ) ) {
			return 'joined' === $run['state'] ? array( 'state' => 'busy' ) : ( $store->coverage_status( $workspace, $key, $asset, $mapping ) ?? array( 'state' => $run['state'] ) ); }
		$symbol    = $store->bind_coverage( $workspace, $run['run_id'], $run['lease_token'], $asset, $mapping );
		$admission = null;
		$provider  = new TickerbotProvider(
			function ( string $family ) use ( $store, $workspace, $run, &$admission ): bool {
				$admission = $store->reserve_attempt( $workspace, $run['run_id'], $run['lease_token'], $family );
				return 'admitted' === $admission['state'];
			},
			$transport
		);
		$result    = $provider->coverage( $symbol );
		$attempt   = (int) ( $admission['attempt_id'] ?? 0 );
		$state     = $attempt ? $result['state'] : ( $admission['state'] ?? $result['state'] );
		if ( $attempt ) {
			$store->finish_attempt( $workspace, $run['run_id'], $run['lease_token'], $attempt, 'covered' === $state ? 'completed' : $state, $result['retry_after_seconds'] ?? null ); }
		$store->finish_coverage( $workspace, $run['run_id'], $run['lease_token'], $attempt, $state, $result['summary'] ?? null );
		$store->finish_run( $workspace, $run['run_id'], $run['lease_token'], 'covered' === $state ? 'completed' : 'failed' );
		return $store->coverage_status( $workspace, $key, $asset, $mapping );
	}
}
