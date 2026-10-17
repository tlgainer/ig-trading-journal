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
	/**
	 * Refresh one bounded selection immediately; cron and worker flags are irrelevant.
	 * Only the creator receives the private lease, so rechecks never send again.
	 *
	 * @param TickerbotStore $store Authorized owner context.
	 * @param int            $workspace Workspace ID.
	 * @param string         $key Retained operation UUID.
	 * @param int            $settings Reviewed settings revision.
	 * @param int            $scope Reviewed scope revision.
	 * @param array          $assets Positive local asset IDs, maximum fifty.
	 * @param callable|null  $transport Deterministic fixture transport only.
	 * @param bool           $check_only Read the saved outcome without starting or sending.
	 * @return array Safe saved outcome, without lease capabilities.
	 * @throws \InvalidArgumentException On invalid operation identity.
	 */
	public static function selected( TickerbotStore $store, int $workspace, string $key, int $settings, int $scope, array $assets, ?callable $transport = null, bool $check_only = false ): array {
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
			throw new \InvalidArgumentException( 'A retained refresh operation UUID is required.' ); }
		if ( $settings < 1 || $scope < 0 || ! array_is_list( $assets ) || count( $assets ) < 1 || count( $assets ) > 50 ) {
			throw new \InvalidArgumentException( 'Reviewed revisions and one to fifty asset IDs are required.' ); }
		foreach ( $assets as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new \InvalidArgumentException( 'Asset IDs must be positive integers.' ); }
		}
		if ( count( array_unique( $assets ) ) !== count( $assets ) ) {
			throw new \InvalidArgumentException( 'Select each asset once.' ); }
		if ( $check_only ) {
			return $store->selected_outcome( $workspace, $key, $settings, $scope, $assets ); }
		$credential = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || '' === $credential || str_starts_with( $credential, 'YOUR_' ) || strlen( $credential ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $credential ) ) {
			return array( 'state' => 'disabled' ); }
		$run = $store->start_run( $workspace, $key, 'selected', 0, $settings, $scope, $assets );
		if ( ! isset( $run['lease_token'] ) ) {
			return $run; }
		$result = TickerbotRefresh::step( $store, $workspace, $run['run_id'], $run['lease_token'], $transport );
		if ( in_array( $result['state'], array( 'pending', 'waiting' ), true ) ) {
			// No automatic retries or queued recovery for an immediate operation.
			$store->finish_run( $workspace, $run['run_id'], $run['lease_token'], 'failed' );
			$result['state'] = 'failed';
		}
		return $result + array( 'run_id' => $run['run_id'] );
	}
}
