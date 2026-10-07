<?php
/**
 * Bounded structured AI review validation, independent of transport.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Structural citations are validated; model assertions are not verified facts. */
final class AiReview {
	/**
	 * Validate a normalized server response against immutable approved evidence.
	 *
	 * @param array  $response Normalized response, never a browser submission.
	 * @param array  $bundle Approved evidence.
	 * @param string $model Exact requested model.
	 * @return array Canonical output, fingerprint and byte count.
	 * @throws \InvalidArgumentException On invalid output, identity or citations.
	 */
	public static function build( array $response, array $bundle, string $model ): array {
		self::fields( $response, array( 'response_id', 'model', 'summary', 'findings' ) );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $model ) || 'ai-evidence-1' !== ( $bundle['version'] ?? null ) || ! is_array( $bundle['sources'] ?? null ) || ! $bundle['sources'] || $model !== $response['model'] || ! is_string( $response['response_id'] ) || ! preg_match( '/^[A-Za-z0-9_-]{1,190}$/D', $response['response_id'] ) ) {
			throw new \InvalidArgumentException( 'AI response identity or approved evidence is invalid.' );
		}
		if ( ! is_array( $response['findings'] ) || ! array_is_list( $response['findings'] ) || ! $response['findings'] || count( $response['findings'] ) > 12 ) {
			throw new \InvalidArgumentException( 'AI findings must be a bounded nonempty list.' );
		}
		$findings = array();
		foreach ( $response['findings'] as $finding ) {
			if ( ! is_array( $finding ) ) {
				throw new \InvalidArgumentException( 'AI finding is invalid.' );
			}
			self::fields( $finding, array( 'text', 'source_ids' ) );
			$ids = $finding['source_ids'];
			if ( ! is_array( $ids ) || ! array_is_list( $ids ) || ! $ids || count( $ids ) > count( $bundle['sources'] ) ) {
				throw new \InvalidArgumentException( 'Each finding requires approved source citations.' );
			}
			$allowed = array_column( $bundle['sources'], 'snapshot_id' );
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id <= 0 || ! in_array( $id, $allowed, true ) ) {
					throw new \InvalidArgumentException( 'AI citation is outside the approved evidence.' );
				}
			}
			if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
				throw new \InvalidArgumentException( 'AI citations are duplicated.' );
			}
			sort( $ids, SORT_NUMERIC );
			$findings[] = array(
				'text'       => self::text( $finding['text'], 2000 ),
				'source_ids' => $ids,
			);
		}
		$output = array(
			'version'     => 'ai-review-1',
			'response_id' => $response['response_id'],
			'model'       => $model,
			'summary'     => self::text( $response['summary'], 4000 ),
			'findings'    => $findings,
			'limitations' => array( 'ai_generated_not_verified_facts', 'citations_validate_source_membership_not_claim_accuracy', 'saved_evidence_only', 'no_news_prices_or_investment_recommendation' ),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure canonical serialization.
		$json = json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > 32768 ) {
			throw new \InvalidArgumentException( 'AI review exceeds the output size limit.' );
		}
		return array(
			'output'      => $output,
			'fingerprint' => hash( 'sha256', $json ),
			'bytes'       => strlen( $json ),
		);
	}

	/**
	 * Require exact fields, rejecting hidden data and unsupported output.
	 *
	 * @param array $input Candidate object.
	 * @param array $fields Exact keys.
	 * @return void
	 * @throws \InvalidArgumentException On unknown or missing fields.
	 */
	private static function fields( array $input, array $fields ): void {
		if ( array_diff( array_keys( $input ), $fields ) || array_diff( $fields, array_keys( $input ) ) ) {
			throw new \InvalidArgumentException( 'AI output fields are incomplete or unsupported.' );
		}
	}

	/**
	 * Keep plain UTF-8 text as untrusted data for later escaped rendering.
	 *
	 * @param mixed $value Text.
	 * @param int   $limit Maximum bytes.
	 * @return string
	 * @throws \InvalidArgumentException On invalid text.
	 */
	private static function text( $value, int $limit ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $limit || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value ) ) {
			throw new \InvalidArgumentException( 'AI review text is invalid or exceeds limits.' );
		}
		return $value;
	}
}
