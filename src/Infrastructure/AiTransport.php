<?php
/**
 * Bounded sender for explicitly approved and budget-reserved requests.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\AiSpending;

/** No REST route, scheduled enrollment, counting or automatic retries. */
final class AiTransport {
	/**
	 * Require explicit server enablement and a header-safe prepared key.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return defined( 'TGIT_OPENAI_ENABLED' ) && true === TGIT_OPENAI_ENABLED && AiConnection::status()['credential_configured'];
	}

	/**
	 * Claim once and send only the stored, verified execution request.
	 *
	 * @param AiSpending $service Explicit authorizing actor's service.
	 * @param int        $workspace Workspace.
	 * @param int        $id Exact reserved request.
	 * @param array      $catalog Fresh trusted credential-bound model evidence.
	 * @return array Safe status without provider text, keys or prompt data.
	 * @throws \UnexpectedValueException On unbound or stale execution evidence.
	 */
	public static function run( AiSpending $service, int $workspace, int $id, array $catalog ): array {
		if ( ! Installer::ready() ) {
			return array( 'state' => 'unavailable' );
		}
		$row = $service->request( $workspace, $id );
		if ( 'reserved' !== $row['state'] ) {
			return array(
				'state'      => $row['state'],
				'request_id' => $id,
			);
		}
		if ( ! self::enabled() ) {
			return array(
				'state'      => 'disabled',
				'request_id' => $id,
			);
		}
		$manifest = $service->manifest( $workspace, $id );
		if ( null === $manifest ) {
			throw new \UnexpectedValueException( 'AI sending requires a complete verified execution manifest.' );
		}
		$body = wp_json_encode( $manifest['plan']['request'] );
		if ( ! is_string( $body ) || strlen( $body ) > 524288 ) {
			throw new \UnexpectedValueException( 'AI execution request exceeds the transport bound.' );
		}
		$key = TGIT_OPENAI_API_KEY;
		$service->dispatch( $workspace, $id, hash( 'sha256', $key ), $catalog, true );
		try {
			$response = wp_safe_remote_post(
				'https://api.openai.com/v1/responses',
				array(
					'headers'             => array(
						'Authorization'       => 'Bearer ' . $key,
						'Content-Type'        => 'application/json',
						'X-Client-Request-Id' => 'tgit-' . hash( 'sha256', $workspace . ':' . $id . ':' . $row['request_key'] ),
					),
					'body'                => $body,
					'timeout'             => 30,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 524288,
					'blocking'            => true,
					'cookies'             => array(),
				)
			);
			if ( ! is_wp_error( $response ) ) {
				return $service->receive( $workspace, $id, wp_remote_retrieve_body( $response ), wp_remote_retrieve_response_code( $response ) );
			}
		} catch ( \Throwable $error ) {
			// Delivery or receipt capture may have completed. Never resend.
			return self::uncertain( $service, $workspace, $id );
		}
		return self::uncertain( $service, $workspace, $id );
	}

	/**
	 * Preserve unknown charges even if recording uncertainty fails.
	 *
	 * @param AiSpending $service Authorized service.
	 * @param int        $workspace Workspace.
	 * @param int        $id Claimed request.
	 * @return array
	 */
	private static function uncertain( AiSpending $service, int $workspace, int $id ): array {
		try {
			$service->reconcile( $workspace, $id );
		} catch ( \Throwable $error ) {
			// A failed uncertainty audit still preserves the dispatched hold.
			return array(
				'state'      => 'uncertain',
				'request_id' => $id,
			);
		}
		return array(
			'state'      => 'uncertain',
			'request_id' => $id,
		);
	}
}
