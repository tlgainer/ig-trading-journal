<?php
/**
 * Owner readiness reads; no external I/O or implicit enrollment.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\TickerbotStore;
use GainerInteractive\IGTradingJournal\Application\Tracker;

/** Keep server-only configuration out of browser inputs and responses. */
final class TickerbotSetup {
	/**
	 * Report configuration presence separately from authenticated account access.
	 *
	 * @param Database $db Database adapter.
	 * @param int      $actor Current owner.
	 * @param int      $workspace Workspace ID.
	 * @param string   $correlation Request correlation.
	 * @return array Safe configuration flags and saved workspace evidence.
	 */
	public static function status( Database $db, int $actor, int $workspace, string $correlation ): array {
		( new Tracker( $db, $actor, $correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$key         = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		$scope       = defined( 'TGIT_TICKERBOT_ACCOUNT_SCOPE' ) && is_string( TGIT_TICKERBOT_ACCOUNT_SCOPE ) ? TGIT_TICKERBOT_ACCOUNT_SCOPE : '';
		$valid_scope = ! str_starts_with( $scope, 'YOUR_' ) && (bool) preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $scope );
		$saved       = $valid_scope ? ( new TickerbotStore( $db, $actor, $correlation, $scope ) )->readiness( $workspace, hash( 'sha256', $key ) ) : null;
		return array(
			'credential_configured'    => '' !== $key && ! str_starts_with( $key, 'YOUR_' ) && strlen( $key ) <= 200 && ! preg_match( '/[\x00-\x20\x7f]/', $key ),
			'account_scope_configured' => $valid_scope,
			'transport_enabled'        => defined( 'TGIT_TICKERBOT_ENABLED' ) && true === TGIT_TICKERBOT_ENABLED,
			'worker_enabled'           => defined( 'TGIT_TICKERBOT_WORKER_ENABLED' ) && true === TGIT_TICKERBOT_WORKER_ENABLED,
			'scheduler_enabled'        => defined( 'TGIT_TICKERBOT_SCHEDULER_ENABLED' ) && true === TGIT_TICKERBOT_SCHEDULER_ENABLED,
			'access_verified'          => (bool) ( ( $saved['connection']['credential_matches'] ?? false ) && 'connected' === ( $saved['connection']['state'] ?? '' ) ),
			'saved'                    => $saved,
		);
	}

	/**
	 * Construct only from the trusted server account reference after owner authorization.
	 *
	 * @param Database $db Database adapter.
	 * @param int      $actor Current owner.
	 * @param int      $workspace Workspace ID.
	 * @param string   $correlation Request correlation.
	 * @return TickerbotStore Trusted command service.
	 * @throws \DomainException On missing server account reference.
	 */
	public static function service( Database $db, int $actor, int $workspace, string $correlation ): TickerbotStore {
		( new Tracker( $db, $actor, $correlation ) )->authorize( $workspace, 'tgit_manage_members' );
		$scope = defined( 'TGIT_TICKERBOT_ACCOUNT_SCOPE' ) && is_string( TGIT_TICKERBOT_ACCOUNT_SCOPE ) ? TGIT_TICKERBOT_ACCOUNT_SCOPE : '';
		if ( str_starts_with( $scope, 'YOUR_' ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/D', $scope ) ) {
			throw new \DomainException( 'Configure a stable Tickerbot account reference on the server first.' );
		}
		return new TickerbotStore( $db, $actor, $correlation, $scope );
	}
}
