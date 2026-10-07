<?php
/**
 * Conservative text-only Responses receipt validation.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Domain;

/** Provider-reported usage is separate from publishable review content. */
final class AiResponse {
	/**
	 * Inspect a server-decoded receipt, never browser-supplied output.
	 *
	 * @param array  $response Server-decoded provider response.
	 * @param string $model Exact captured model.
	 * @param array  $bundle Exact approved evidence.
	 * @param int    $http_status Actual transport status.
	 * @return array Bounded usage and independently publishable review.
	 */
	public static function inspect( array $response, string $model, array $bundle, int $http_status = 200 ): array {
		$result         = array(
			'version'     => 'ai-response-1',
			'response_id' => null,
			'model'       => null,
			'reason'      => 'unverified_identity',
			'usage'       => null,
			'review'      => null,
		);
		$returned_model = $response['model'] ?? null;
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $model ) || 200 !== $http_status || 'response' !== ( $response['object'] ?? null ) || $model !== $returned_model || ! is_string( $response['id'] ?? null ) || ! preg_match( '/^resp_[A-Za-z0-9_-]{1,180}$/D', $response['id'] ) ) {
			return $result;
		}
		$result['response_id'] = $response['id'];
		$result['model']       = $model;
		$result['reason']      = 'unsupported_billing';
		if ( 'default' !== ( $response['service_tier'] ?? null ) || array() !== ( $response['tools'] ?? null ) || ! in_array( $response['status'] ?? null, array( 'completed', 'incomplete', 'failed', 'cancelled' ), true ) || ! is_array( $response['output'] ?? null ) || ! array_is_list( $response['output'] ) || count( $response['output'] ) > 16 ) {
			return $result;
		}
		foreach ( $response['output'] as $item ) {
			if ( ! is_array( $item ) || ! in_array( $item['type'] ?? null, array( 'message', 'reasoning' ), true ) ) {
				return $result;
			}
		}
		$result['reason'] = 'unknown_usage';
		$usage            = self::usage( $response['usage'] ?? null );
		if ( null === $usage || ( 'completed' === $response['status'] && ( 0 === $usage['input_tokens'] || 0 === $usage['output_tokens'] ) ) ) {
			return $result;
		}
		$result['usage']  = $usage;
		$result['reason'] = 'incomplete';
		if ( 'completed' !== $response['status'] ) {
			return $result;
		}
		$result['reason'] = 'invalid_output';
		if ( ! array_key_exists( 'error', $response ) || null !== $response['error'] || ! array_key_exists( 'incomplete_details', $response ) || null !== $response['incomplete_details'] ) {
			return $result;
		}
		$messages = array_values( array_filter( $response['output'], static fn( array $item ): bool => 'message' === $item['type'] ) );
		if ( 1 !== count( $messages ) ) {
			return $result;
		}
		$message = $messages[0];
		if ( 'assistant' !== ( $message['role'] ?? null ) || 'completed' !== ( $message['status'] ?? null ) || ( isset( $message['phase'] ) && 'final_answer' !== $message['phase'] ) || ! is_array( $message['content'] ?? null ) || ! array_is_list( $message['content'] ) || 1 !== count( $message['content'] ) || ! is_array( $message['content'][0] ) ) {
			return $result;
		}
		$content = $message['content'][0];
		if ( 'refusal' === ( $content['type'] ?? null ) ) {
			$result['reason'] = 'refused';
			return $result;
		}
		if ( 'output_text' !== ( $content['type'] ?? null ) || array() !== ( $content['annotations'] ?? null ) || ! is_string( $content['text'] ?? null ) || strlen( $content['text'] ) > 32768 ) {
			return $result;
		}
		try {
			$output = AiJson::decode( $content['text'], 32768 );
			if ( ! is_array( $output ) || array_diff( array_keys( $output ), array( 'summary', 'findings' ) ) || array_diff( array( 'summary', 'findings' ), array_keys( $output ) ) ) {
				return $result;
			}
			$normalized             = array(
				'response_id' => $response['id'],
				'model'       => $model,
				'summary'     => $output['summary'],
				'findings'    => $output['findings'],
			);
			$validated              = AiReview::build( $normalized, $bundle, $model );
			$normalized['findings'] = $validated['output']['findings'];
			$result['review']       = $normalized;
			$result['reason']       = 'completed';
		} catch ( \InvalidArgumentException | \JsonException $error ) {
			// Bad content is not a refund; independently verified usage survives.
			return $result;
		}
		return $result;
	}

	/**
	 * Verify totals without estimating missing usage or double-counting reasoning.
	 *
	 * @param mixed $usage Provider usage.
	 * @return array|null
	 */
	private static function usage( $usage ): ?array {
		$fields = array( 'input_tokens', 'input_tokens_details', 'output_tokens', 'output_tokens_details', 'total_tokens' );
		if ( ! is_array( $usage ) || array_diff( array_keys( $usage ), $fields ) || array_diff( $fields, array_keys( $usage ) ) ) {
			return null;
		}
		$input  = $usage['input_tokens_details'];
		$output = $usage['output_tokens_details'];
		if ( ! is_array( $input ) || ! is_array( $output ) || array_diff( array_keys( $input ), array( 'cached_tokens', 'cache_write_tokens' ) ) || ! array_key_exists( 'cached_tokens', $input ) || ( array_key_exists( 'cache_write_tokens', $input ) && 0 !== $input['cache_write_tokens'] ) || array( 'reasoning_tokens' ) !== array_keys( $output ) ) {
			return null;
		}
		foreach ( array( $usage['input_tokens'], $usage['output_tokens'], $input['cached_tokens'], $output['reasoning_tokens'] ) as $tokens ) {
			if ( ! is_int( $tokens ) || $tokens < 0 || $tokens > 1000000 ) {
				return null;
			}
		}
		if ( ! is_int( $usage['total_tokens'] ) || $usage['total_tokens'] !== $usage['input_tokens'] + $usage['output_tokens'] || $input['cached_tokens'] > $usage['input_tokens'] || $output['reasoning_tokens'] > $usage['output_tokens'] ) {
			return null;
		}
		return array(
			'input_tokens'        => $usage['input_tokens'],
			'cached_input_tokens' => $input['cached_tokens'],
			'output_tokens'       => $usage['output_tokens'],
		);
	}
}
