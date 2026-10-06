<?php
/**
 * Explicitly enabled, bounded Alpha Vantage fundamental snapshot worker.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

use GainerInteractive\IGTradingJournal\Application\MarketData;

/** No recurring enrollment, automatic provider fallback or paid processing. */
final class FundamentalRefresh {

	/** Check separate fundamental consent and existing server-only market configuration. */
	public static function enabled(): bool {
		return defined( 'TGIT_FUNDAMENTALS_ENABLED' ) && true === TGIT_FUNDAMENTALS_ENABLED && QuoteRefresh::enabled( 'alpha_vantage' );
	}

	/**
	 * Process one typed snapshot without resending completed or uncertain deliveries.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $actor Explicit authorizing owner.
	 * @param int    $mapping Current stock mapping.
	 * @param string $dataset Supported statement or overview endpoint.
	 * @param string $key Stable request identity.
	 * @param bool   $scheduled Preserve on-demand request headroom.
	 * @return array
	 */
	public static function run( int $workspace, int $actor, int $mapping, string $dataset, string $key, bool $scheduled = true ): array {
		if ( ! Installer::ready() ) {
			return array( 'state' => 'unavailable' );
		}
		if ( ! self::enabled() ) {
			return array( 'state' => 'disabled' );
		}
		if ( ! self::dataset( $dataset ) ) {
			return array( 'state' => 'blocked' );
		}
		global $wpdb;
		$service = new MarketData( new Database( $wpdb ), $actor, wp_generate_uuid4() );
		try {
			$identity = $service->current_mapping( $workspace, $mapping );
			if ( 'alpha_vantage' !== $identity['provider'] ) {
				return array( 'state' => 'blocked' );
			}
			$request = $service->reserve( $workspace, $mapping, hash( 'sha256', TGIT_ALPHA_VANTAGE_API_KEY ), $key, $scheduled, $dataset );
			if ( 'completed' === $request['state'] ) {
				$snapshot = $service->completed_fundamentals( $workspace, $request['id'] );
				return null === $snapshot ? array( 'state' => 'unavailable' ) : array(
					'state'    => 'completed',
					'snapshot' => $snapshot,
				);
			}
			if ( 'reserved' !== $request['state'] ) {
				return array( 'state' => 'dispatched' === $request['state'] ? 'uncertain' : $request['state'] );
			}
			$service->dispatch( $workspace, $request['id'] );
			$identity = $service->current_mapping( $workspace, $mapping );
			if ( ! self::enabled() ) {
				return array( 'state' => 'disabled' );
			}
			$url      = add_query_arg(
				array(
					'function' => $dataset,
					'symbol'   => $identity['provider_symbol'],
					'apikey'   => TGIT_ALPHA_VANTAGE_API_KEY,
				),
				'https://www.alphavantage.co/query'
			);
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
				// Delivery can precede a timeout; retain its quota and never resend automatically.
				return array( 'state' => 'uncertain' );
			}
			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$service->fail( $workspace, $request['id'] );
				return array( 'state' => 'failed' );
			}
			try {
				$snapshot = $service->complete_fundamentals( $workspace, $request['id'], wp_remote_retrieve_body( $response ) );
			} catch ( \InvalidArgumentException $error ) {
				$service->fail( $workspace, $request['id'] );
				return array( 'state' => 'invalid_response' );
			}
			return array(
				'state'    => 'completed',
				'snapshot' => $snapshot,
			);
		} catch ( \DomainException | \OutOfBoundsException | \UnexpectedValueException $error ) {
			return array( 'state' => 'blocked' );
		} catch ( \Throwable $error ) {
			// Provider errors and URLs may contain credentials; expose only a fixed state.
			return array( 'state' => 'unavailable' );
		}
	}

	/**
	 * Queue one explicitly authorized, dataset-bound job in the coming week.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $actor Authorizing owner.
	 * @param int    $mapping Current mapping identifier.
	 * @param string $dataset Supported dataset.
	 * @param int    $timestamp Requested UTC execution time.
	 * @return void
	 * @throws \InvalidArgumentException On disabled or unsupported processing.
	 * @throws \RuntimeException On scheduling failure.
	 */
	public static function schedule( int $workspace, int $actor, int $mapping, string $dataset, int $timestamp ): void {
		if ( ! Installer::ready() || ! self::enabled() || ! self::dataset( $dataset ) || $timestamp < time() || $timestamp > time() + 604800 ) {
			throw new \InvalidArgumentException( 'Fundamental processing is disabled or the job is invalid.' );
		}
		global $wpdb;
		$service  = new MarketData( new Database( $wpdb ), $actor, wp_generate_uuid4() );
		$identity = $service->current_mapping( $workspace, $mapping );
		if ( 'alpha_vantage' !== $identity['provider'] ) {
			throw new \InvalidArgumentException( 'Fundamental processing requires Alpha Vantage.' );
		}
		$args = array( $workspace, $actor, $mapping, $dataset, $timestamp );
		if ( ! wp_next_scheduled( 'tgit_fundamental_refresh', $args ) && true !== wp_schedule_single_event( $timestamp, 'tgit_fundamental_refresh', $args, true ) ) {
			throw new \RuntimeException( 'Fundamental refresh could not be scheduled.' );
		}
	}

	/**
	 * Recheck ownership at execution and retain original job retry identity.
	 *
	 * @param int    $workspace Workspace identifier.
	 * @param int    $actor Authorizing owner.
	 * @param int    $mapping Mapping identifier.
	 * @param string $dataset Dataset.
	 * @param int    $timestamp Original UTC execution time.
	 * @return void
	 */
	public static function job( int $workspace, int $actor, int $mapping, string $dataset, int $timestamp ): void {
		$result = self::run( $workspace, $actor, $mapping, $dataset, 'fundamental-job:' . hash( 'sha256', $mapping . ':' . $dataset . ':' . $timestamp ) );
		do_action( 'tgit_fundamental_refresh_status', $workspace, $mapping, $dataset, $result['state'] );
	}

	/**
	 * Restrict provider function selection to supported fundamental datasets.
	 *
	 * @param string $dataset Requested dataset.
	 * @return bool
	 */
	private static function dataset( string $dataset ): bool {
		return in_array( $dataset, array( 'OVERVIEW', 'INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW' ), true );
	}
}
