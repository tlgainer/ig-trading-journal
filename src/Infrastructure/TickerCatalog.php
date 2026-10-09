<?php
/**
 * Public ticker suggestions; never an asset identity or market-data mapping.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** Bounded fixed-source catalogue cached independently of workspace data. */
final class TickerCatalog {
	public const URL = 'https://terrellgainer.com/tickers.json';

	/**
	 * Read public suggestions without sending workspace information upstream.
	 *
	 * @return array
	 * @throws \RuntimeException When the catalogue is unavailable.
	 */
	public static function read(): array {
		$cached = get_transient( 'tgit_public_tickers_v1' );
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
			throw new \RuntimeException( 'Ticker suggestions are unavailable. Manual entry remains available.' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 2097152 ) {
			throw new \RuntimeException( 'Ticker catalogue exceeds its size limit.' );
		}
		$rows = self::normalize( $body );
		set_transient( 'tgit_public_tickers_v1', $rows, DAY_IN_SECONDS );
		return $rows;
	}

	/**
	 * Validate untrusted catalogue rows; preserve share-class punctuation.
	 *
	 * @param string $body JSON source.
	 * @return array
	 * @throws \RuntimeException When the source is malformed.
	 */
	public static function normalize( string $body ): array {
		$source = json_decode( $body, true );
		if ( ! is_array( $source ) || count( $source ) > 20000 ) {
			throw new \RuntimeException( 'Invalid ticker catalogue.' );
		}
		$rows = array();
		$seen = array();
		foreach ( $source as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['ticker'] ?? null ) || ! is_string( $row['title'] ?? null ) ) {
				continue;
			}
			$symbol = strtoupper( trim( $row['ticker'] ) );
			$title  = trim( $row['title'] );
			if ( ! preg_match( '/^[A-Z0-9][A-Z0-9.\-^]{0,31}$/D', $symbol ) || '' === $title || strlen( $title ) > 300 || preg_match( '/[\x00-\x1f\x7f]/', $title ) || isset( $seen[ $symbol ] ) ) {
				continue;
			}
			$seen[ $symbol ] = true;
			$rows[]          = array(
				'symbol' => $symbol,
				'title'  => $title,
			);
		}
		if ( ! $rows ) {
			throw new \RuntimeException( 'Ticker catalogue has no usable suggestions.' );
		}
		return $rows;
	}
}
