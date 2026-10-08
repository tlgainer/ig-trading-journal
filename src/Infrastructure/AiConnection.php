<?php
/**
 * Read-only server credential preparation; no network or generation.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Key values and digests never leave this configuration projection. */
final class AiConnection {
	/**
	 * Show configuration readiness without claiming account access is verified.
	 *
	 * @return array
	 */
	public static function status(): array {
		$key = defined( 'TGIT_OPENAI_API_KEY' ) ? TGIT_OPENAI_API_KEY : null;
		return array(
			'credential_configured' => is_string( $key ) && 'YOUR_OPENAI_KEY' !== $key && 1 === preg_match( '/^[A-Za-z0-9._-]{10,512}$/D', $key ),
			'access_verified'       => false,
			'processing_available'  => false,
		);
	}
}
