<?php
/**
 * Disabled Tickerbot adapter; durable admission must be supplied by the orchestrator.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Infrastructure;

/** One HTTP attempt per admitted request; no hidden retries or database mutation. */
final class TickerbotProvider implements QuoteProvider {
	/**
	 * Durable admission callback; family only, no portfolio facts.
	 *
	 * @var callable
	 */
	private $admit;
	/**
	 * Synthetic transport for isolated fixtures.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Bind request admission and optional fixture transport.
	 *
	 * @param callable      $admit Must reserve/count an account-scoped attempt before allowing HTTP.
	 * @param callable|null $transport Optional deterministic fixture transport.
	 */
	public function __construct( callable $admit, ?callable $transport = null ) {
		$this->admit     = $admit;
		$this->transport = $transport;
	}

	/**
	 * Descriptive capabilities, not an authenticated account-access claim.
	 *
	 * @return array
	 */
	public function capabilities(): array {
		return array(
			'batch_size' => 50,
			'catalogue'  => true,
			'coverage'   => true,
			'history'    => false,
		);
	}

	/**
	 * Execute one counted, bounded request against the fixed API host.
	 *
	 * @param string $path Fixed internal endpoint suffix.
	 * @param array  $query Allowlisted non-sensitive API parameters.
	 * @param string $family Usage endpoint family.
	 * @return array Private bounded response or safe error state.
	 */
	private function request( string $path, array $query, string $family ): array {
		$key = defined( 'TGIT_TICKERBOT_API_KEY' ) && is_string( TGIT_TICKERBOT_API_KEY ) ? TGIT_TICKERBOT_API_KEY : '';
		if ( ! defined( 'TGIT_TICKERBOT_ENABLED' ) || true !== TGIT_TICKERBOT_ENABLED || '' === $key || str_starts_with( $key, 'YOUR_' ) || strlen( $key ) > 200 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			return array( 'state' => 'disabled' );
		}
		try {
			if ( true !== ( $this->admit )( $family ) ) {
				return array( 'state' => 'budget_blocked' );
			}
			$url = 'https://api.tickerbot.io/v2/' . $path;
			if ( $query ) {
				$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
			}
			$options  = array(
				'timeout'             => 20,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 2097153,
				'cookies'             => array(),
				'headers'             => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
			);
			$response = $this->transport ? ( $this->transport )( $url, $options ) : wp_safe_remote_get( $url, $options );
			if ( is_wp_error( $response ) ) {
				return array( 'state' => 'transport_error' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status ) {
				$states = array(
					401 => 'authentication',
					402 => 'monthly_quota',
					403 => 'capability',
					404 => 'unsupported',
					429 => 'rate_limit',
				);
				$retry  = wp_remote_retrieve_header( $response, 'retry-after' );
				$delay  = null;
				if ( 'rate_limit' === ( $states[ $status ] ?? '' ) && is_string( $retry ) ) {
					$delay = ctype_digit( $retry ) ? max( 1, min( 2678400, (int) $retry ) ) : null;
					if ( null === $delay ) {
						$at    = strtotime( $retry );
						$delay = false !== $at ? min( 2678400, max( 1, $at - time() ) ) : null;
					}
				}
				return array(
					'state'               => $states[ $status ] ?? ( $status >= 500 ? 'provider_error' : 'http_error' ),
					'http_status'         => $status,
					'retry_after_seconds' => $delay,
				);
			}
			return array(
				'state' => 'completed',
				'body'  => ProviderJson::decode( wp_remote_retrieve_body( $response ) ),
			);
		} catch ( \Throwable $error ) {
			// Provider errors and exceptions can contain secrets. Never forward their text.
			return array( 'state' => 'invalid_response' );
		}
	}

	/**
	 * Does not change provider settings, mappings, schedules or ledger data.
	 *
	 * @return array
	 */
	public function test_connection(): array {
		$result = $this->request( 'tickers/AAPL', array(), 'connection' );
		if ( 'completed' !== $result['state'] ) {
			return $result;
		}
		return array( 'state' => ( $result['body']['ticker'] ?? null ) === 'AAPL' && is_array( $result['body']['data'] ?? null ) ? 'connected' : 'invalid_response' );
	}

	/**
	 * Fetch admitted batches and retain independent per-asset outcomes.
	 *
	 * @param array  $asset Stable local identity only.
	 * @param string $symbol Explicit candidate, never a fuzzy match.
	 * @return array Mapping state; no provider row is stored.
	 * @throws \InvalidArgumentException On an excessive search candidate.
	 */
	public function resolve( array $asset, string $symbol ): array {
		TickerbotQuote::symbol( $symbol );
		if ( strlen( $symbol ) > 64 ) {
			throw new \InvalidArgumentException( 'Catalogue candidates must be at most 64 characters.' );
		}
		$class  = 'crypto' === ( $asset['asset_class'] ?? '' ) ? 'crypto' : 'stocks';
		$result = $this->request(
			'tickers',
			array(
				'search'      => $symbol,
				'asset_class' => $class,
				'limit'       => 50,
			),
			'catalogue'
		);
		if ( 'completed' !== $result['state'] ) {
			return $result;
		}
		$rows = $result['body']['results'] ?? null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > 50 ) {
			return array( 'state' => 'invalid_response' );
		}
		$matches = array_filter( $rows, static fn( $row ): bool => is_array( $row ) && TickerbotQuote::identity( $asset, $row, $symbol ) );
		return array(
			'state'           => count( $matches ) === 1 ? 'mapped' : ( $rows ? 'ambiguous' : 'unsupported' ),
			'provider_symbol' => $symbol,
			'identity'        => count( $matches ) === 1 ? array_intersect_key( reset( $matches ), array_flip( array( 'ticker', 'asset_class', 'asset_type', 'currency_name', 'exchange', 'exchange_mic' ) ) ) : null,
		);
	}

	/**
	 * Read only the coverage fields needed for setup.
	 *
	 * @param string $symbol Explicit canonical symbol.
	 * @return array Selective coverage metadata, never signals or user content.
	 */
	public function coverage( string $symbol ): array {
		$result = $this->request( 'tickers/' . rawurlencode( TickerbotQuote::symbol( $symbol ) ) . '/coverage', array(), 'coverage' );
		if ( 'completed' !== $result['state'] ) {
			return $result;
		}
		try {
			return array(
				'state'   => 'covered',
				'summary' => TickerbotCoverageSummary::parse( $result['body'], $symbol ),
			);
		} catch ( \Throwable $error ) {
			return array( 'state' => 'invalid_response' );
		}
	}

	/**
	 * Fetch admitted batches and retain independent per-asset outcomes.
	 *
	 * @param array $mappings Verified local identity mappings; duplicates share one symbol request.
	 * @return array Per-asset observations/issues; failed batches never discard earlier successes.
	 * @throws \InvalidArgumentException On excessive scope or invalid mapped identities.
	 */
	public function quotes( array $mappings ): array {
		if ( count( $mappings ) > 10000 ) {
			throw new \InvalidArgumentException( 'Too many mapped assets for one run.' );
		}
		$ids    = array();
		$groups = array();
		foreach ( $mappings as $asset ) {
			if ( ! is_int( $asset['id'] ?? null ) || $asset['id'] < 1 || isset( $ids[ $asset['id'] ] ) ) {
				throw new \InvalidArgumentException( 'Mapped assets need unique positive local IDs.' );
			}
			$ids[ $asset['id'] ] = true;
			$symbol              = TickerbotQuote::symbol( $asset['provider_symbol'] ?? '' );
			$groups[ $symbol ][] = $asset;
		}
		$accepted = array();
		$issues   = array();
		$batches  = array();
		foreach ( array_chunk( array_keys( $groups ), 50 ) as $symbols ) {
			$rows   = array_merge( ...array_map( static fn( $symbol ): array => $groups[ $symbol ], $symbols ) );
			$result = $this->request( 'tickers/' . implode( ',', array_map( 'rawurlencode', $symbols ) ), array(), 'quotes' );
			if ( 'completed' === $result['state'] ) {
				try {
					// Re-encoding already lossless string tokens cannot introduce binary float prices.
					$parsed    = TickerbotQuote::parse( wp_json_encode( $result['body'] ), $rows, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
					$accepted += $parsed['accepted'];
					$issues   += $parsed['issues'];
				} catch ( \Throwable $error ) {
					$result = array( 'state' => 'invalid_response' );
				}
			}
			if ( 'completed' !== $result['state'] ) {
				foreach ( $rows as $asset ) {
					$issues[ $asset['id'] ] = $result['state'];
				}
			}
			unset( $result['body'] );
			$batches[] = $result;
			if ( in_array( $result['state'], array( 'authentication', 'monthly_quota', 'rate_limit', 'capability', 'budget_blocked', 'disabled' ), true ) ) {
				foreach ( $mappings as $asset ) {
					if ( ! array_key_exists( $asset['id'], $accepted ) && ! array_key_exists( $asset['id'], $issues ) ) {
						$issues[ $asset['id'] ] = 'not_attempted';
					}
				}
				break;
			}
		}
		return array(
			'accepted' => $accepted,
			'issues'   => $issues,
			'batches'  => $batches,
		);
	}
}
