<?php
/**
 * Public coin suggestions; never an asset identity or market-data mapping.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Bounded fixed-source catalogue cached independently of workspace data. */
final class CoinCatalog {
	public const URL = 'https://terrellgainer.com/coins.json';

	/**
	 * Read public suggestions without sending workspace information upstream.
	 *
	 * @return array
	 * @throws \RuntimeException When the catalogue is unavailable.
	 */
	public static function read(): array {
		$cached = get_transient( 'tgit_public_coins_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_safe_remote_get(
			self::URL,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 2097153,
				'cookies'             => array(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( 'Coin suggestions are unavailable. Manual entry remains available.' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 2097152 ) {
			throw new \RuntimeException( 'Coin catalogue exceeds its size limit.' );
		}
		$rows = self::normalize( $body );
		set_transient( 'tgit_public_coins_v1', $rows, DAY_IN_SECONDS );
		return $rows;
	}

	/**
	 * Validate untrusted catalogue rows; preserve distinct IDs even when symbols repeat.
	 *
	 * @param string $body JSON source.
	 * @return array
	 * @throws \RuntimeException When the source is malformed.
	 */
	public static function normalize( string $body ): array {
		$source = json_decode( $body, true );
		if ( ! is_array( $source ) || ! array_is_list( $source ) || count( $source ) > 20000 ) {
			throw new \RuntimeException( 'Invalid coin catalogue.' );
		}
		$rows = array();
		$seen = array();
		foreach ( $source as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['id'] ?? null ) || ! is_string( $row['symbol'] ?? null ) || ! is_string( $row['name'] ?? null ) ) {
				continue;
			}
			$id     = trim( $row['id'] );
			$symbol = strtoupper( trim( $row['symbol'] ) );
			$title  = trim( $row['name'] );
			if ( '' === $id || strlen( $id ) > 100 || preg_match( '/[\x00-\x20\x7f<>]/', $id ) || '' === $symbol || strlen( $symbol ) > 32 || preg_match( '/[\x00-\x20\x7f<>]/', $symbol ) || '' === $title || strlen( $title ) > 300 || preg_match( '/[\x00-\x1f\x7f]/', $title ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$rows[]      = array(
				'id'     => $id,
				'symbol' => $symbol,
				'title'  => $title,
			);
		}
		if ( ! $rows ) {
			throw new \RuntimeException( 'Coin catalogue has no usable suggestions.' );
		}
		return $rows;
	}
}
