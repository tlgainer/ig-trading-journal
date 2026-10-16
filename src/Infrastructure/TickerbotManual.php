<?php
/**
 * Explicit reviewed manual cache refresh; durable admissions own every send.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;
/** Same-key checks cannot acquire a second run or send capability. */
final class TickerbotManual {
	/**
	 * Queue a reviewed fixed-key run without exposing lease capability.
	 *
	 * @param TickerbotStore $store Trusted owner context.
	 * @param int            $workspace Workspace ID.
	 * @param string         $key Retained operation UUID.
	 * @param int            $settings Reviewed settings revision.
	 * @param int            $scope Reviewed scope revision.
	 * @return array
	 * @throws \InvalidArgumentException On invalid explicit input.
	 */
	public static function run( TickerbotStore $store, int $workspace, string $key, int $settings, int $scope ): array {
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'A retained refresh operation UUID is required.' );}
		$credential = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( '' === $credential || str_starts_with( $credential, 'YOUR_' ) || strlen( $credential ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $credential ) ) {
			return array( 'state' => 'disabled' ); }
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || ! defined( 'TGIT_TICKERBOT_WORKER_ENABLED' ) || true !== TGIT_TICKERBOT_WORKER_ENABLED ) {
			return array( 'state' => 'disabled' );}
		$run = $store->start_run( $workspace, $key, 'manual', 0, $settings, $scope );
		unset( $run['lease_token'] );
		if ( 'running' === $run['state'] ) {
			$run['queued'] = TickerbotRefresh::queue( $workspace, $run['run_id'], time() + 1 );}
		return $run;
	}
}
