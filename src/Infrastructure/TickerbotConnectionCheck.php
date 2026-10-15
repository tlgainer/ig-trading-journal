<?php
/**
 * One counted explicit connection test; no retries or worker enrollment.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;

/** Only a newly created command receives a private send capability. */
final class TickerbotConnectionCheck {
	/**
	 * Execute or read the outcome of the same operation.
	 *
	 * @param TickerbotStore $store Trusted owner/account context.
	 * @param int            $workspace Workspace ID.
	 * @param string         $key Stable operation UUID.
	 * @param callable|null  $transport Disposable fixture transport only.
	 * @return array Safe outcome, no private lease or provider body.
	 * @throws \InvalidArgumentException On invalid operation identity.
	 */
	public static function run( TickerbotStore $store, int $workspace, string $key, ?callable $transport = null ): array {
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'A stable connection operation UUID is required.' );
		}
		$ready = $store->readiness( $workspace );
		if ( ! $ready['enrolled'] ) {
			return array( 'state' => 'not_enrolled' );
		}
		$prior = $store->connection_status( $workspace, $key );
		if ( null !== $prior ) {
			return $prior;
		}
		$credential = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || '' === $credential || str_starts_with( $credential, 'YOUR_' ) || strlen( $credential ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $credential ) ) {
			return array( 'state' => 'disabled' );
		}
		$run = $store->start_run( $workspace, 'connection:' . $key, 'connection' );
		if ( ! isset( $run['lease_token'] ) ) {
			if ( 'joined' === $run['state'] ) {
				return array( 'state' => 'busy' );
			}
			return isset( $run['run_id'] ) ? $store->connection_outcome( $workspace, $run['run_id'] ) : array( 'state' => $run['state'] );
		}
		$admission = null;
		$provider  = new TickerbotProvider(
			function ( string $family ) use ( $store, $workspace, $run, &$admission ): bool {
				$admission = $store->reserve_attempt( $workspace, $run['run_id'], $run['lease_token'], $family );
				return 'admitted' === $admission['state'];
			},
			$transport
		);
		$result    = $provider->test_connection();
		$attempt   = (int) ( $admission['attempt_id'] ?? 0 );
		$state     = $attempt ? $result['state'] : ( $admission['state'] ?? $result['state'] );
		if ( $attempt ) {
			$store->finish_attempt( $workspace, $run['run_id'], $run['lease_token'], $attempt, 'connected' === $state ? 'completed' : $state, $result['retry_after_seconds'] ?? null );
		}
		$store->record_connection( $workspace, $run['run_id'], $run['lease_token'], $attempt, $state, hash( 'sha256', $credential ) );
		$store->finish_run( $workspace, $run['run_id'], $run['lease_token'], 'connected' === $state ? 'completed' : 'failed' );
		return $store->connection_outcome( $workspace, $run['run_id'] );
	}
}
