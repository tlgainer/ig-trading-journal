<?php
/**
 * Disabled-by-default, bounded provider quote refresh worker.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\MarketData;

/** No automatic enrollment; explicit configuration and owner-authorized jobs only. */
final class QuoteRefresh {
	/**
	 * Check explicit server enablement and the selected provider credential.
	 *
	 * @param string $provider Selected provider.
	 * @return bool
	 */
	public static function enabled( string $provider = 'alpha_vantage' ): bool {
		$key = self::credential( $provider );
		return defined( 'TGIT_MARKET_DATA_ENABLED' ) && true === TGIT_MARKET_DATA_ENABLED
			&& '' !== trim( $key ) && strlen( $key ) <= 200;
	}

	/**
	 * Resolve a server-only credential from a fixed provider allowlist.
	 *
	 * @param string $provider Selected provider.
	 * @return string
	 */
	private static function credential( string $provider ): string {
		$constants = array(
			'alpha_vantage' => 'TGIT_ALPHA_VANTAGE_API_KEY',
			'fmp'           => 'TGIT_FMP_API_KEY',
		);
		$name      = $constants[ $provider ] ?? '';
		return '' !== $name && defined( $name ) && is_string( constant( $name ) ) ? constant( $name ) : '';
	}

	/**
	 * Process one quote; retries never resend completed or uncertain attempts.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $actor Explicit authorizing owner.
	 * @param int    $mapping Mapping identifier.
	 * @param string $key Request identity.
	 * @param bool   $scheduled Preserve on-demand quota headroom.
	 * @return array
	 */
	public static function run( int $workspace, int $actor, int $mapping, string $key, bool $scheduled = true ): array {
		if ( ! Installer::ready() ) {
			return array( 'state' => 'unavailable' );
		}
		if ( ! defined( 'TGIT_MARKET_DATA_ENABLED' ) || true !== TGIT_MARKET_DATA_ENABLED ) {
			return array( 'state' => 'disabled' );
		}
		global $wpdb;
		$service = new MarketData( new Database( $wpdb ), $actor, wp_generate_uuid4() );
		try {
			$identity = $service->current_mapping( $workspace, $mapping );
			if ( ! self::enabled( $identity['provider'] ) ) {
				return array( 'state' => 'disabled' );
			}
			// Stable across WordPress salt changes; a salt rotation must not reset quotas.
			$fingerprint = hash( 'sha256', self::credential( $identity['provider'] ) );
			$request     = $service->reserve( $workspace, $mapping, $fingerprint, $key, $scheduled );
			if ( 'completed' === $request['state'] ) {
				$quote = $service->completed_quote( $workspace, $request['id'] );
				if ( null === $quote ) {
					return array( 'state' => 'unavailable' );
				}
				return array(
					'state' => 'completed',
					'quote' => $quote,
				);
			}
			if ( 'reserved' !== $request['state'] ) {
				return array( 'state' => 'dispatched' === $request['state'] ? 'uncertain' : $request['state'] );
			}
			$service->dispatch( $workspace, $request['id'] );
			$identity = $service->current_mapping( $workspace, $mapping );
			if ( ! self::enabled( $identity['provider'] ) ) {
				return array( 'state' => 'disabled' );
			}
			$url = add_query_arg(
				array(
					'function' => 'GLOBAL_QUOTE',
					'symbol'   => $identity['provider_symbol'],
					'apikey'   => self::credential( $identity['provider'] ),
				),
				'https://www.alphavantage.co/query'
			);
			if ( 'fmp' === $identity['provider'] ) {
				$url = add_query_arg(
					array(
						'symbol' => $identity['provider_symbol'],
						'apikey' => self::credential( 'fmp' ),
						'from'   => gmdate( 'Y-m-d', time() - 1209600 ),
						'to'     => gmdate( 'Y-m-d' ),
					),
					'https://financialmodelingprep.com/stable/historical-price-eod/light'
				);
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 20,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 2097152,
					'headers'             => array( 'Accept' => 'application/json' ),
					'user-agent'          => 'IGTradingJournal/' . IG_TRADING_JOURNAL_VERSION,
				)
			);
			if ( is_wp_error( $response ) ) {
				// A timeout may follow successful delivery; never refund or resend blindly.
				return array( 'state' => 'uncertain' );
			}
			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$service->fail( $workspace, $request['id'] );
				return array( 'state' => 'failed' );
			}
			try {
				$quote = 'fmp' === $identity['provider']
					? $service->complete_fmp_quote( $workspace, $request['id'], wp_remote_retrieve_body( $response ) )
					: $service->complete_alpha_quote( $workspace, $request['id'], wp_remote_retrieve_body( $response ) );
			} catch ( \InvalidArgumentException $error ) {
				$service->fail( $workspace, $request['id'] );
				return array( 'state' => 'invalid_response' );
			}
			return array(
				'state' => 'completed',
				'quote' => $quote,
			);
		} catch ( \DomainException | \OutOfBoundsException | \UnexpectedValueException $error ) {
			return array( 'state' => 'blocked' );
		} catch ( \Throwable $error ) {
			// HTTP errors/URLs can contain API keys. Do not emit raw exceptions or bodies.
			return array( 'state' => 'unavailable' );
		}
	}

	/**
	 * Queue one bounded job after explicit owner authorization.
	 * Recurring enrollment uses separate revision-bound jobs.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $actor Authorizing owner.
	 * @param int $mapping Mapping identifier.
	 * @param int $timestamp Requested UTC execution time.
	 * @return void
	 * @throws \InvalidArgumentException On disabled processing or invalid execution time.
	 * @throws \RuntimeException On scheduling failure.
	 */
	public static function schedule( int $workspace, int $actor, int $mapping, int $timestamp ): void {
		if ( ! Installer::ready() || $timestamp < time() || $timestamp > time() + 604800 ) {
			throw new \InvalidArgumentException( 'Quote processing is disabled or the scheduled time is invalid.' );
		}
		global $wpdb;
		$service  = new MarketData( new Database( $wpdb ), $actor, wp_generate_uuid4() );
		$identity = $service->current_mapping( $workspace, $mapping );
		if ( ! self::enabled( $identity['provider'] ) ) {
			throw new \InvalidArgumentException( 'Quote processing is disabled for this provider.' );
		}
		$args = array( $workspace, $actor, $mapping, $timestamp );
		if ( ! wp_next_scheduled( 'tgit_quote_refresh', $args ) && true !== wp_schedule_single_event( $timestamp, 'tgit_quote_refresh', $args, true ) ) {
			throw new \RuntimeException( 'Quote refresh could not be scheduled.' );
		}
	}

	/**
	 * Handle a scheduled quote with a stable request identity.
	 *
	 * @param int $workspace Workspace identifier.
	 * @param int $actor Authorizing owner.
	 * @param int $mapping Mapping identifier.
	 * @param int $timestamp Original scheduled execution time.
	 * @return void
	 */
	public static function job( int $workspace, int $actor, int $mapping, int $timestamp ): void {
		$result = self::run( $workspace, $actor, $mapping, 'quote-job:' . $mapping . ':' . $timestamp );
		do_action( 'tgit_quote_refresh_status', $workspace, $mapping, $result['state'] );
	}

	/** Stop provider jobs on deactivation without deleting evidence or quotas. */
	public static function deactivate(): void {
		wp_unschedule_hook( 'tgit_fundamental_refresh' );
		wp_unschedule_hook( 'tgit_quote_refresh' );
		wp_unschedule_hook( 'tgit_quote_schedule_scan' );
		wp_unschedule_hook( 'tgit_scheduled_quote_refresh' );
	}
}
