<?php
/**
 * Trusted server model, pricing and counting evidence.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Domain\AiCountPolicy;
use GainerInteractive\IGTradingJournal\Domain\AiModelCatalog;

/** No browser assertions, defaults, model discovery or network requests. */
final class AiConfiguration {

	/**
	 * Resolve current server evidence for an exact selected model.
	 *
	 * @param string $model Exact configured model ID.
	 * @return array Private execution evidence; never serialize to a browser.
	 * @throws \UnexpectedValueException On missing credentials or evidence.
	 */
	public static function current( string $model ): array {
		if ( ! AiConnection::status()['credential_configured'] ) {
			throw new \UnexpectedValueException( 'OpenAI server credential is unavailable.' );
		}
		$catalog  = defined( 'TGIT_OPENAI_MODEL_EVIDENCE' ) ? TGIT_OPENAI_MODEL_EVIDENCE : null;
		$policies = defined( 'TGIT_OPENAI_COUNT_EVIDENCE' ) ? TGIT_OPENAI_COUNT_EVIDENCE : null;
		if ( ! is_array( $catalog ) || ! is_array( $policies ) ) {
			throw new \UnexpectedValueException( 'Trusted OpenAI model and counting evidence is unavailable.' );
		}
		return self::verified( $catalog, $policies, hash( 'sha256', TGIT_OPENAI_API_KEY ), $model, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Validate only selected model evidence against the current credential/time.
	 *
	 * @param array              $catalog Trusted server catalog keyed by exact model.
	 * @param array              $policies Trusted counting evidence keyed by exact model.
	 * @param string             $credential Current credential fingerprint.
	 * @param string             $model Exact selected model.
	 * @param \DateTimeImmutable $now Current evaluation time.
	 * @return array Private catalog, count policy and exact pricing.
	 * @throws \UnexpectedValueException On absent selected model evidence.
	 */
	public static function verified( array $catalog, array $policies, string $credential, string $model, \DateTimeImmutable $now ): array {
		if ( '' === $model || ! isset( $catalog[ $model ], $policies[ $model ] ) || ! is_array( $catalog[ $model ] ) || ! is_array( $policies[ $model ] ) ) {
			throw new \UnexpectedValueException( 'Selected OpenAI model has no trusted execution evidence.' );
		}
		$selected = array( $model => $catalog[ $model ] );
		$pricing  = AiModelCatalog::verified( $selected, $credential, $now );
		AiCountPolicy::verify( $policies[ $model ], $credential, $model, $now );
		return array(
			'catalog'      => $selected,
			'count_policy' => $policies[ $model ],
			'pricing'      => $pricing[ $model ],
		);
	}
}
