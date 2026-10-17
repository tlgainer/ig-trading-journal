<?php
/**
 * One bounded Tickerbot worker step; durable store owns all admissions.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;

/** Internal worker boundary; no automatic enrollment or provider cutover. */
final class TickerbotRefresh {
	/**
	 * Execute at most one batch, returning a future due time instead of sleeping.
	 *
	 * Only trusted server orchestration supplies account scope and private lease.
	 * Exceptions after admission retain counted, in-flight uncertainty; never resend.
	 *
	 * @param TickerbotStore $store Authorized server context.
	 * @param int            $workspace Workspace ID.
	 * @param int            $run Active run.
	 * @param string         $token Private lease capability.
	 * @param callable|null  $transport Synthetic fixture transport only.
	 * @return array Safe progress without tokens, keys, provider bodies or portfolio text.
	 */
	public static function step( TickerbotStore $store, int $workspace, int $run, string $token, ?callable $transport = null ): array {
		$store->prepare_dispatch( $workspace, $run, $token );
		$next = $store->next_dispatch( $workspace, $run, $token );
		if ( in_array( $next['state'], array( 'completed', 'failed' ), true ) ) {
			$store->finish_run( $workspace, $run, $token, $next['state'] );
			return array(
				'state'  => $next['state'],
				'run_id' => $run,
			);
		}
		if ( 'ready' !== $next['state'] ) {
			return array_intersect_key( $next, array_flip( array( 'state', 'next_due_at' ) ) );
		}
		$store->renew( $workspace, $run, $token );
		$admission = null;
		$provider  = new TickerbotProvider(
			function ( string $family ) use ( $store, $workspace, $run, $token, $next, &$admission ): bool {
				$admission = $store->reserve_attempt( $workspace, $run, $token, $family, $next['symbols'], $next['batch_id'] );
				return 'admitted' === $admission['state'];
			},
			$transport
		);
		$result    = $provider->quotes( $next['mappings'] );
		$outcome   = $result['batches'][0] ?? array( 'state' => 'invalid_response' );
		if ( null === $admission || 'admitted' !== $admission['state'] ) {
			$state = $admission['state'] ?? $outcome['state'];
			if ( in_array( $state, array( 'budget_blocked', 'paused', 'rate_limit' ), true ) ) {
				$store->finish_run( $workspace, $run, $token, 'failed' );
			}
			return array(
				'state'  => $state,
				'run_id' => $run,
			);
		}
		foreach ( $next['mappings'] as $mapping ) {
			if ( isset( $result['accepted'][ $mapping['id'] ] ) ) {
				$store->record_quote( $workspace, $run, $token, $admission['attempt_id'], $mapping['mapping_id'], $result['accepted'][ $mapping['id'] ] );
			}
		}
		$store->finish_attempt( $workspace, $run, $token, $admission['attempt_id'], $outcome['state'], $outcome['retry_after_seconds'] ?? null, 'completed' === $outcome['state'] ? $result['issues'] : array() );
		if ( in_array( $outcome['state'], array( 'authentication', 'capability', 'monthly_quota', 'rate_limit' ), true ) ) {
			$store->finish_run( $workspace, $run, $token, 'failed' );
			return array(
				'state'  => $outcome['state'],
				'run_id' => $run,
			);
		}
		$next = $store->next_dispatch( $workspace, $run, $token );
		if ( in_array( $next['state'], array( 'completed', 'failed' ), true ) ) {
			$store->finish_run( $workspace, $run, $token, $next['state'] );
		}
		return array(
			'state'  => 'ready' === $next['state'] ? 'pending' : $next['state'],
			'run_id' => $run,
		) + array_intersect_key( $next, array( 'next_due_at' => true ) );
	}
	/**
	 * Queue only an explicitly started run; keys alone never create jobs.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $run Previously authorized run ID.
	 * @param int $at Future UTC timestamp.
	 * @return bool Queueing success, false while worker/cutover preparation is off.
	 */
	public static function queue( int $workspace, int $run, int $at ): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		$args = array( $workspace, $run );
		return (bool) wp_next_scheduled( 'tgit_tickerbot_batch', $args ) || true === wp_schedule_single_event( max( time() + 1, $at ), 'tgit_tickerbot_batch', $args, true );
	}

	/**
	 * Additional worker gate remains off pending owner setup/cutover controls.
	 *
	 * @return bool Whether trusted server dispatch is explicitly enabled.
	 */
	private static function enabled(): bool {
		return Installer::ready() && defined( 'TGIT_TICKERBOT_ENABLED' ) && true === TGIT_TICKERBOT_ENABLED && defined( 'TGIT_TICKERBOT_WORKER_ENABLED' ) && true === TGIT_TICKERBOT_WORKER_ENABLED && defined( 'TGIT_TICKERBOT_ACCOUNT_SCOPE' ) && is_string( TGIT_TICKERBOT_ACCOUNT_SCOPE ) && (bool) preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', TGIT_TICKERBOT_ACCOUNT_SCOPE );
	}

	/**
	 * Resume a run from trusted database context; cron arguments contain no tokens.
	 *
	 * @param int $workspace Workspace ID.
	 * @param int $run Previously authorized run ID.
	 * @return void
	 */
	public static function job( int $workspace, int $run ): void {
		if ( ! self::enabled() ) {
			return;
		}
		try {
			global $wpdb;
			$db  = new Database( $wpdb );
			$row = $db->object( 'tickerbot_runs', $workspace, $run );
			if ( 'selected' === $row['cause'] ) {
				return; }
			if ( 'scheduled' === $row['cause'] && 'tickerbot' !== \GainerInteractive\IGTradingJournal\Application\PricingPolicy::saved( $db, $workspace )['source'] ) {
				return; }
			$store  = new TickerbotStore( $db, (int) $row['actor_id'], wp_generate_uuid4(), TGIT_TICKERBOT_ACCOUNT_SCOPE );
			$result = self::step( $store, $workspace, $run, $row['lease_token'] );
			if ( 'pending' === $result['state'] || 'waiting' === $result['state'] ) {
				$at = isset( $result['next_due_at'] ) ? ( new \DateTimeImmutable( $result['next_due_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp() : time() + 1;
				self::queue( $workspace, $run, $at );
			}
		} catch ( \Throwable $error ) {
			// Leave admitted attempts counted and uncertainty intact; never log secrets.
			return;
		}
	}
}
