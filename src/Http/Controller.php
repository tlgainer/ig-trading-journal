<?php
/**
 * Versioned REST integration (API 01-04).
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Http;

use GainerInteractive\IGTradingJournal\Application\Tracker;
use GainerInteractive\IGTradingJournal\Application\AiSpending;
use GainerInteractive\IGTradingJournal\Application\AiSettings;
use GainerInteractive\IGTradingJournal\Application\AiEvidencePreview;
use GainerInteractive\IGTradingJournal\Application\AiReviews;
use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh;
use GainerInteractive\IGTradingJournal\Infrastructure\FundamentalRefresh;
use GainerInteractive\IGTradingJournal\Infrastructure\RecurringQuotes;
use GainerInteractive\IGTradingJournal\Infrastructure\RecurringFundamentals;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;

/** Controller service for the current implementation slice. */
final class Controller {
	/**
	 * Check authentication, transport and workspace membership.
	 *
	 * @param \WP_REST_Request $request request input.
	 * @return bool|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'tgit_unauthenticated', __( 'Authentication required.', 'ig-trading-journal' ), array( 'status' => 401 ) );
		}
		if ( ! is_ssl() && ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return new \WP_Error( 'tgit_https_required', __( 'HTTPS is required.', 'ig-trading-journal' ), array( 'status' => 403 ) );
		}
		if ( ! Installer::ready() ) {
			return new \WP_Error( 'tgit_unavailable', __( 'Plugin dependencies or schema are not ready.', 'ig-trading-journal' ), array( 'status' => 503 ) );
		}
		if ( $request->get_param( 'workspace' ) !== null ) {
			try {
				self::service( wp_generate_uuid4() )->authorize( (int) $request['workspace'], 'tgit_view' );
			} catch ( \DomainException $error ) {
							return new \WP_Error( 'tgit_forbidden', __( 'Workspace permission denied.', 'ig-trading-journal' ), array( 'status' => 403 ) );
			} catch ( \Throwable $error ) {
							return new \WP_Error( 'tgit_unavailable', __( 'Tracker is temporarily unavailable.', 'ig-trading-journal' ), array( 'status' => 503 ) );
			}
		}
		return true;
	}

	/**
	 * Construct a service for the authenticated actor.
	 *
	 * @param string $correlation correlation input.
	 * @return Tracker
	 */
	private static function service( string $correlation ): Tracker {
		global $wpdb;
		return new Tracker( new Database( $wpdb ), get_current_user_id(), $correlation );
	}

	/**
	 * Register versioned REST routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		JournalRoutes::register();
		add_filter( 'rest_post_dispatch', array( self::class, 'private_response' ), 10, 3 );
		self::route( '/workspaces', 'GET', 'workspaces' );
		self::route( '/workspaces', 'POST', 'create_workspace' );
		$base = '/workspaces/(?P<workspace>[1-9][0-9]*)';
		self::route( $base . '/ai-settings', 'GET', 'ai_settings' );
		self::route( $base . '/ai-settings', 'POST', 'save_ai_settings' );
		self::route( $base . '/ai-enrollment', 'POST', 'ai_enrollment' );
		self::route( $base . '/ai-requests', 'GET', 'ai_request_history' );
		self::route( $base . '/ai-evidence/(?P<approval>[1-9][0-9]*)/preflight', 'GET', 'ai_generation_preflight' );
		self::route( $base . '/ai-evidence/(?P<approval>[1-9][0-9]*)/generate', 'POST', 'ai_generate' );
		self::route( $base . '/ai-evidence/(?P<approval>[1-9][0-9]*)/generation', 'GET', 'ai_generation_status' );
		self::route( $base . '/ai-requests/(?P<request_id>[1-9][0-9]*)/publish', 'POST', 'publish_ai_review' );
		self::route( $base . '/ai-requests/(?P<request_id>[1-9][0-9]*)/cancel', 'POST', 'cancel_ai_request' );
		self::route( $base . '/assets/(?P<asset_id>[1-9][0-9]*)/ai-evidence/preview', 'POST', 'ai_evidence_preview' );
		self::route( $base . '/assets/(?P<asset_id>[1-9][0-9]*)/ai-evidence/approve', 'POST', 'ai_evidence_approve' );
		self::route( $base . '/ai-evidence/(?P<approval>[1-9][0-9]*)', 'GET', 'ai_evidence_read' );
		self::route( $base . '/assets/(?P<asset_id>[1-9][0-9]*)/ai-reviews', 'GET', 'ai_review_history' );
		self::route( $base . '/ai-reviews/(?P<review>[1-9][0-9]*)', 'GET', 'ai_review_read' );
		self::route( $base . '/market-data', 'GET', 'market_status' );
		self::route( $base . '/assets/(?P<asset_id>[1-9][0-9]*)/fundamentals', 'GET', 'fundamentals' );
		self::route( $base . '/assets/(?P<asset_id>[1-9][0-9]*)/fundamental-metrics', 'POST', 'fundamental_metrics' );
		self::route( $base . '/provider-mappings/(?P<mapping>[1-9][0-9]*)/fundamentals/refresh', 'POST', 'refresh_fundamentals' );
		self::route( $base . '/provider-mappings/(?P<mapping>[1-9][0-9]*)/fundamentals/schedule', 'GET', 'fundamental_schedule' );
		self::route( $base . '/provider-mappings/(?P<mapping>[1-9][0-9]*)/fundamentals/schedule', 'POST', 'save_fundamental_schedule' );
		self::route( $base . '/provider-mappings', 'GET', 'provider_mappings' );
		self::route( $base . '/assets/(?P<asset_id>[1-9][0-9]*)/provider-mappings', 'POST', 'save_provider_mapping' );
		self::route( $base . '/provider-mappings/(?P<mapping>[1-9][0-9]*)/refresh', 'POST', 'refresh_provider_quote' );
		self::route( $base . '/provider-mappings/(?P<mapping>[1-9][0-9]*)/schedule', 'POST', 'save_quote_schedule' );
		self::route( $base . '/observations', 'GET', 'observations' );
		self::route( $base . '/observations', 'POST', 'record_observation' );
		self::route( $base . '/reports', 'POST', 'generate_report' );
		self::route( $base . '/reports', 'GET', 'reports' );
		self::route( $base . '/reports/(?P<report>[1-9][0-9]*)', 'GET', 'report_run' );
		self::route( $base . '/saved-views', 'GET', 'saved_views' );
		self::route( $base . '/saved-views', 'POST', 'save_view' );
		self::route( $base . '/members', 'GET', 'members' );
		self::route( $base . '/members', 'POST', 'set_member' );
		foreach ( array( 'accounts', 'assets', 'transactions' ) as $type ) {
			self::route( $base . '/' . $type, 'GET', 'list_' . $type );
			self::route( $base . '/' . $type, 'POST', 'create_' . $type );
		}
		self::route( $base . '/transactions/(?P<transaction>[1-9][0-9]*)', 'GET', 'transaction' );
		self::route( $base . '/transactions/(?P<transaction>[1-9][0-9]*)/draft', 'POST', 'edit_draft' );
		self::route( $base . '/transactions/(?P<transaction>[1-9][0-9]*)/post', 'POST', 'promote_draft' );
		self::route( $base . '/transactions/(?P<transaction>[1-9][0-9]*)/corrections', 'POST', 'correct_cash' );
		self::route( $base . '/opening-balances', 'POST', 'opening_balance' );
		self::route( $base . '/opening-balances/retroactive', 'POST', 'retroactive_opening' );
		self::route( $base . '/opening-balances/(?P<transaction>[1-9][0-9]*)/basis-resolutions', 'POST', 'resolve_opening_basis' );
		self::route( $base . '/replay-preview', 'POST', 'replay_preview' );
		self::route( $base . '/historical-cash', 'POST', 'post_historical_cash' );
		self::route( $base . '/historical-transactions', 'POST', 'post_historical_security' );
		self::route( $base . '/holdings', 'GET', 'holdings' );
		self::route( $base . '/stock-summary', 'GET', 'stock_summary' );
		self::route( $base . '/calculators/(?P<calculator>crypto|risk|leveraged|stock|short-risk|option)', 'POST', 'scenario' );
		self::route( $base . '/watchlists', 'GET', 'watchlists' );
		self::route( $base . '/watchlists', 'POST', 'create_watchlist' );
		self::route( $base . '/watchlists/(?P<list>[1-9][0-9]*)/items', 'GET', 'watchlist_items' );
		self::route( $base . '/watchlists/(?P<list>[1-9][0-9]*)/items', 'POST', 'add_watchlist_item' );
		self::route( $base . '/watchlists/(?P<list>[1-9][0-9]*)/items/(?P<item>[1-9][0-9]*)/revisions', 'GET', 'watchlist_item_revisions' );
		self::route( $base . '/watchlists/(?P<list>[1-9][0-9]*)/items/(?P<item>[1-9][0-9]*)/revisions', 'POST', 'revise_watchlist_item' );
		self::route( $base . '/research-notes', 'GET', 'research_notes' );
		self::route( $base . '/research-notes', 'POST', 'create_research_note' );
		self::route( $base . '/research-notes/(?P<note>[1-9][0-9]*)/revisions', 'GET', 'research_note_revisions' );
		self::route( $base . '/research-notes/(?P<note>[1-9][0-9]*)/revisions', 'POST', 'revise_research_note' );
	}

	/**
	 * Prevent shared caching of tracker responses.
	 *
	 * @param \WP_REST_Response $response response input.
	 * @param \WP_REST_Server   $server server input.
	 * @param \WP_REST_Request  $request request input.
	 * @return \WP_REST_Response
	 */
	public static function private_response( $response, $server, $request ) {
		if ( str_starts_with( $request->get_route(), '/tgit/v1/' ) ) {
			$response = rest_ensure_response( $response );
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Cookie, Authorization' );
			$headers     = $response->get_headers();
			$payload     = $response->get_data();
			$correlation = $headers['X-Correlation-ID'] ?? ( $payload['correlation_id'] ?? ( $payload['data']['correlation_id'] ?? wp_generate_uuid4() ) );
			$response->header( 'X-Correlation-ID', $correlation );
		}
		return $response;
	}

	/**
	 * Register one operation with its permission and pagination contract.
	 *
	 * @param string $path path input.
	 * @param string $method method input.
	 * @param string $operation operation input.
	 * @return void
	 */
	private static function route( string $path, string $method, string $operation ): void {
		register_rest_route(
			'tgit/v1',
			$path,
			array(
				'methods'             => $method,
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => static function ( $request ) use ( $operation ) {
					return self::dispatch( $request, $operation );
				},
				'args'                => 'GET' === $method && ( str_starts_with( $operation, 'list_' ) || in_array( $operation, array( 'ai_request_history', 'ai_review_history', 'fundamentals', 'stock_summary', 'provider_mappings', 'observations', 'reports', 'saved_views', 'holdings', 'watchlists', 'watchlist_items', 'watchlist_item_revisions', 'research_notes', 'research_note_revisions' ), true ) ) ? array(
					'price_source' => array(
						'type'    => 'string',
						'enum'    => array( 'manual', 'fmp', 'alpha_vantage' ),
						'default' => 'manual',
					),
					'after'        => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit'        => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 100,
					),
					'asset'        => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				) : array(),
			)
		);
	}

	/**
	 * Validate and execute an operation with safe error responses.
	 *
	 * @param \WP_REST_Request $request request input.
	 * @param string           $operation operation input.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \InvalidArgumentException When the operation contract cannot be satisfied.
	 * @throws \LogicException When the operation contract cannot be satisfied.
	 */
	public static function dispatch( $request, string $operation ) {
		$correlation = wp_generate_uuid4();
		$previous    = null;
		try {
			global $wpdb;
			// Database diagnostics must never enter financial API responses.
			$previous  = $wpdb->suppress_errors( true );
			$service   = self::service( $correlation );
			$workspace = (int) $request['workspace'];
			$data      = array();
			if ( $request->get_method() === 'POST' && 'journal_upload' !== $operation ) {
				$data = $request->get_json_params();
				if ( ! is_array( $data ) || ( $data && array_is_list( $data ) ) ) {
					throw new \InvalidArgumentException( 'A JSON object is required.' );
				}
			}
			if ( 'ai_generate' === $operation ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				Tracker::fields( $data, array( 'expected_config_id' ), array( 'expected_config_id' ) );
				$key = $request->get_header( 'idempotency-key' );
				if ( ! is_int( $data['expected_config_id'] ) || $data['expected_config_id'] < 1 || ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
					throw new \InvalidArgumentException( 'A stable operation UUID and reviewed saved policy are required.' );
				}
				$result = \GainerInteractive\IGTradingJournal\Infrastructure\AiGeneration::run( new AiSpending( new Database( $wpdb ), get_current_user_id(), $correlation ), $workspace, (int) $request->get_url_params()['approval'], $key, 2000, $data['expected_config_id'] );
			} elseif ( 'ai_generation_status' === $operation ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				$key = $request->get_param( 'operation_key' );
				if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key ) ) {
					throw new \InvalidArgumentException( 'The original operation UUID is required.' );
				}
				$result = \GainerInteractive\IGTradingJournal\Application\AiRequestStatus::read( new Database( $wpdb ), get_current_user_id(), $correlation, $workspace, (int) $request->get_url_params()['approval'], $key );
			} elseif ( 'ai_generation_preflight' === $operation ) {
				$result = \GainerInteractive\IGTradingJournal\Application\AiPreflight::read( new Database( $wpdb ), get_current_user_id(), $correlation, $workspace, (int) $request->get_url_params()['approval'] );
			} elseif ( 'cancel_ai_request' === $operation ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				Tracker::fields( $data, array(), array() );
				$spending = new AiSpending( new Database( $wpdb ), get_current_user_id(), $correlation );
				$result   = $spending->cancel_unsent( $workspace, (int) $request->get_url_params()['request_id'] );
			} elseif ( 'publish_ai_review' === $operation ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				Tracker::fields( $data, array(), array() );
				$reviews = new AiReviews( new Database( $wpdb ), get_current_user_id(), $correlation );
				$result  = $reviews->publish_received( $workspace, (int) $request->get_url_params()['request_id'] );
			} elseif ( in_array( $operation, array( 'ai_review_history', 'ai_review_read' ), true ) ) {
				$reviews = new AiReviews( new Database( $wpdb ), get_current_user_id(), $correlation );
				$result  = 'ai_review_read' === $operation ? $reviews->read( $workspace, (int) $request->get_url_params()['review'] ) : $reviews->history( $workspace, (int) $request->get_url_params()['asset_id'], (int) $request['after'], (int) $request['limit'] );
			} elseif ( in_array( $operation, array( 'ai_evidence_preview', 'ai_evidence_approve', 'ai_evidence_read' ), true ) ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				$evidence = new AiEvidencePreview( new Database( $wpdb ), get_current_user_id(), $correlation );
				if ( 'ai_evidence_read' === $operation ) {
					$result = $evidence->approved( $workspace, (int) $request->get_url_params()['approval'] );
				} else {
					$approval = 'ai_evidence_approve' === $operation;
					$required = $approval ? array( 'snapshots', 'fingerprint' ) : array( 'snapshots' );
					Tracker::fields( $data, array_merge( $required, array( 'trade_id', 'expected_revision' ) ), $required );
					if ( ! is_array( $data['snapshots'] ) || array_is_list( $data['snapshots'] ) || ( $approval && ! is_string( $data['fingerprint'] ) ) ) {
						throw new \InvalidArgumentException( 'Select saved dataset snapshots and a valid reviewed fingerprint.' );
					}
					foreach ( array( 'trade_id', 'expected_revision' ) as $field ) {
						if ( array_key_exists( $field, $data ) && ( ! is_int( $data[ $field ] ) || $data[ $field ] <= 0 ) ) {
							throw new \InvalidArgumentException( 'Select a positive trade ID and expected journal revision together.' );
						}
					}
					$asset    = (int) $request->get_url_params()['asset_id'];
					$trade    = $data['trade_id'] ?? null;
					$revision = $data['expected_revision'] ?? null;
					$result   = $approval ? $evidence->approve( $workspace, $asset, $data['snapshots'], $data['fingerprint'], (string) $request->get_header( 'idempotency-key' ), $trade, $revision ) : $evidence->preview( $workspace, $asset, $data['snapshots'], $trade, $revision );
				}
			} elseif ( 'ai_request_history' === $operation ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				$spending = new AiSpending( new Database( $wpdb ), get_current_user_id(), $correlation );
				$result   = $spending->request_history( $workspace, (int) $request['after'], (int) $request['limit'] );
				$reviews  = new AiReviews( new Database( $wpdb ), get_current_user_id(), $correlation );
				foreach ( $result['items'] as &$item ) {
					$item               = array_merge( $item, $reviews->publication_status( $workspace, $item['id'] ) );
					$item['can_cancel'] = $spending->can_cancel_unsent( $workspace, $item['id'] );
				}
				unset( $item );
			} elseif ( in_array( $operation, array( 'ai_settings', 'save_ai_settings', 'ai_enrollment' ), true ) ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				$spending = new AiSpending( new Database( $wpdb ), get_current_user_id(), $correlation );
				if ( 'ai_enrollment' === $operation ) {
					Tracker::fields( $data, array( 'enabled', 'expected_enrollment_id' ), array( 'enabled', 'expected_enrollment_id' ) );
					if ( ! is_bool( $data['enabled'] ) || ! is_int( $data['expected_enrollment_id'] ) || $data['expected_enrollment_id'] < 0 ) {
						throw new \InvalidArgumentException( 'Invalid workspace AI consent.' );
					}
					$spending->enroll( $workspace, $data['enabled'], $data['expected_enrollment_id'] );
				}
				$result = AiSettings::policy( $spending, $workspace, 'save_ai_settings' === $operation ? $data : null );
			} elseif ( in_array( $operation, array( 'fundamentals', 'fundamental_metrics' ), true ) ) {
				$market = new MarketData( new Database( $wpdb ), get_current_user_id(), $correlation );
				$asset  = (int) $request->get_url_params()['asset_id'];
				if ( 'fundamental_metrics' === $operation ) {
					Tracker::fields( $data, array( 'snapshots' ), array( 'snapshots' ) );
					if ( ! is_array( $data['snapshots'] ) || array_is_list( $data['snapshots'] ) ) {
						throw new \InvalidArgumentException( 'A dataset to snapshot object is required.' );
					}
					$result = $market->fundamental_metrics( $workspace, $asset, $data['snapshots'] );
				} else {
					$limit  = null === $request['limit'] ? 100 : (int) $request['limit'];
					$items  = $market->fundamentals( $workspace, $asset, (int) $request['after'], $limit );
					$result = array(
						'items'       => $items,
						'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
					);
				}
			} elseif ( in_array( $operation, array( 'market_status', 'provider_mappings', 'save_provider_mapping', 'refresh_provider_quote', 'save_quote_schedule', 'refresh_fundamentals', 'fundamental_schedule', 'save_fundamental_schedule' ), true ) ) {
				$service->authorize( $workspace, 'tgit_manage_members' );
				$market = new MarketData( new Database( $wpdb ), get_current_user_id(), $correlation );
				if ( 'market_status' === $operation ) {
					$result = array(
						'fundamentals_enabled' => FundamentalRefresh::enabled(),
						'providers'            => array(
							array(
								'provider'        => 'fmp',
								'enabled'         => QuoteRefresh::enabled( 'fmp' ),
								'daily_limit'     => 250,
								'scheduled_limit' => 245,
							),
							array(
								'provider'        => 'alpha_vantage',
								'enabled'         => QuoteRefresh::enabled( 'alpha_vantage' ),
								'daily_limit'     => 25,
								'scheduled_limit' => 20,
							),
						),
					);
				} elseif ( 'provider_mappings' === $operation ) {
					$limit  = null === $request['limit'] ? 100 : (int) $request['limit'];
					$items  = $market->mappings( $workspace, (int) $request['after'], $limit );
					$result = array(
						'items'       => $items,
						'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
					);
				} elseif ( 'save_provider_mapping' === $operation ) {
					$result = $market->save_mapping( $workspace, (int) $request->get_url_params()['asset_id'], $data );
				} elseif ( 'fundamental_schedule' === $operation ) {
					$items = array();
					foreach ( array( 'OVERVIEW', 'INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW' ) as $dataset ) {
						$row = $market->fundamental_schedule_config( $workspace, (int) $request->get_url_params()['mapping'], $dataset );
						if ( $row ) {
							$items[] = $row;
						}
					}
					$result = array( 'items' => $items );
				} elseif ( 'save_fundamental_schedule' === $operation ) {
					$result           = $market->save_fundamental_schedule( $workspace, (int) $request->get_url_params()['mapping'], $data );
					$result['queued'] = RecurringFundamentals::queue( $workspace, (int) $result['id'] );
					RecurringFundamentals::boot();
				} elseif ( 'save_quote_schedule' === $operation ) {
					$result           = $market->save_schedule( $workspace, (int) $request->get_url_params()['mapping'], $data );
					$result['queued'] = RecurringQuotes::queue( $workspace, (int) $result['id'] );
					RecurringQuotes::boot();
				} else {
					$fundamental = 'refresh_fundamentals' === $operation;
					Tracker::fields( $data, $fundamental ? array( 'dataset' ) : array(), $fundamental ? array( 'dataset' ) : array() );
					if ( $fundamental && ( ! is_string( $data['dataset'] ) || ! in_array( $data['dataset'], array( 'OVERVIEW', 'INCOME_STATEMENT', 'BALANCE_SHEET', 'CASH_FLOW' ), true ) ) ) {
						throw new \InvalidArgumentException( 'Choose a supported fundamental dataset.' );
					}
					$key = (string) $request->get_header( 'idempotency-key' );
					if ( '' === $key || strlen( $key ) > 80 ) {
						throw new \InvalidArgumentException( 'A bounded idempotency key is required for a refresh.' );
					}
					$mapping = (int) $request->get_url_params()['mapping'];
					$market->current_mapping( $workspace, $mapping );
					$result = $fundamental ? FundamentalRefresh::run( $workspace, get_current_user_id(), $mapping, $data['dataset'], $key, false ) : QuoteRefresh::run( $workspace, get_current_user_id(), $mapping, $key, false );
				}
			} elseif ( in_array( $operation, array( 'record_observation', 'generate_report', 'save_view' ), true ) ) {
				$result = $service->$operation( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'observations' === $operation ) {
				$limit  = null === $request['limit'] ? 100 : (int) $request['limit'];
				$items  = $service->observations( $workspace, (int) $request['after'], $limit );
				$result = array(
					'items'       => $items,
					'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
				);
			} elseif ( 'saved_views' === $operation ) {
				$limit  = null === $request['limit'] ? 100 : (int) $request['limit'];
				$items  = $service->saved_views( $workspace, (int) $request['after'], $limit );
				$result = array(
					'items'       => $items,
					'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
				);
			} elseif ( 'reports' === $operation ) {
				$limit  = null === $request['limit'] ? 100 : (int) $request['limit'];
				$items  = $service->reports( $workspace, (int) $request['after'], $limit );
				$result = array(
					'items'       => $items,
					'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
				);
			} elseif ( 'report_run' === $operation ) {
				$result = $service->report_run( $workspace, (int) $request->get_url_params()['report'] );
			} elseif ( str_starts_with( $operation, 'journal_' ) ) {
				$result = JournalRoutes::execute( $request, substr( $operation, 8 ), $data, $correlation );
			} elseif ( str_starts_with( $operation, 'list_' ) ) {
				$limit  = (int) $request['limit'];
				$items  = $service->list_objects( $workspace, substr( $operation, 5 ), (int) $request['after'], $limit );
				$result = array(
					'items'       => $items,
					'next_cursor' => count( $items ) === $limit ? (string) end( $items )['id'] : null,
				);
			} elseif ( 'create_accounts' === $operation || 'create_assets' === $operation ) {
				$result = $service->create_object( $workspace, substr( $operation, 7 ), $data );
			} elseif ( 'create_transactions' === $operation ) {
				$result = $service->post( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'opening_balance' === $operation ) {
				$result = $service->opening_balance( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'retroactive_opening' === $operation ) {
				$result = $service->retroactive_opening( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'replay_preview' === $operation ) {
				$result = $service->replay_preview( $workspace, $data );
			} elseif ( 'post_historical_cash' === $operation ) {
				$result = $service->post_historical_cash( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'post_historical_security' === $operation ) {
				$result = $service->post_historical_security( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'transaction' === $operation ) {
				$result = $service->transaction( $workspace, (int) $request->get_url_params()['transaction'] );
			} elseif ( in_array( $operation, array( 'edit_draft', 'promote_draft', 'correct_cash', 'resolve_opening_basis' ), true ) ) {
				$result = $service->$operation( $workspace, (int) $request->get_url_params()['transaction'], $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'holdings' === $operation ) {
				$result = $service->holdings( $workspace, (int) $request['after'], (int) $request['limit'], (string) $request['price_source'] );
			} elseif ( 'stock_summary' === $operation ) {
				$result = $service->stock_summary( $workspace, (string) $request['price_source'] );
			} elseif ( 'scenario' === $operation ) {
				$result = $service->scenario( $workspace, (string) $request->get_url_params()['calculator'], $data );
			} elseif ( in_array( $operation, array( 'watchlists', 'watchlist_items', 'watchlist_item_revisions', 'research_notes', 'research_note_revisions' ), true ) ) {
				$after  = (int) $request['after'];
				$limit  = (int) $request['limit'];
				$params = $request->get_url_params();
				if ( 'watchlists' === $operation ) {
					$items = $service->watchlists( $workspace, $after, $limit );
				} elseif ( 'watchlist_items' === $operation ) {
					$items = $service->watchlist_items( $workspace, (int) $params['list'], $after, $limit );
				} elseif ( 'watchlist_item_revisions' === $operation ) {
					$items = $service->watchlist_item_revisions( $workspace, (int) $params['list'], (int) $params['item'], $after, $limit );
				} elseif ( 'research_notes' === $operation ) {
					$items = $service->research_notes( $workspace, null !== $request['asset'] ? (int) $request['asset'] : null, $after, $limit );
				} else {
					$items = $service->research_note_revisions( $workspace, (int) $params['note'], $after, $limit );
				}
				$cursor = str_ends_with( $operation, '_revisions' ) ? 'revision' : 'id';
				$result = array(
					'items'       => $items,
					'next_cursor' => count( $items ) === $limit ? (string) end( $items )[ $cursor ] : null,
				);
			} elseif ( 'create_watchlist' === $operation ) {
				$result = $service->create_watchlist( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'add_watchlist_item' === $operation ) {
				$result = $service->add_watchlist_item( $workspace, (int) $request->get_url_params()['list'], $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'revise_watchlist_item' === $operation ) {
				$params = $request->get_url_params();
				$result = $service->revise_watchlist_item( $workspace, (int) $params['list'], (int) $params['item'], $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'create_research_note' === $operation ) {
				$result = $service->create_research_note( $workspace, $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'revise_research_note' === $operation ) {
				$result = $service->revise_research_note( $workspace, (int) $request->get_url_params()['note'], $data, (string) $request->get_header( 'idempotency-key' ) );
			} elseif ( 'workspaces' === $operation ) {
				$result = array( 'items' => $service->workspaces() );
			} elseif ( 'create_workspace' === $operation ) {
						$result = $service->create_workspace( $data );
			} elseif ( 'members' === $operation ) {
					$result = array( 'items' => $service->members( $workspace ) );
			} elseif ( 'set_member' === $operation ) {
							$result = $service->set_member( $workspace, $data );
			} else {
						throw new \LogicException( 'Unknown operation.' );
			}
							$response = new \WP_REST_Response(
								array(
									'data'           => $result,
									'correlation_id' => $correlation,
								),
								200
							);
			if ( $result instanceof \WP_REST_Response ) {
				$response = $result;
			}
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Cookie, Authorization' );
			$response->header( 'X-Correlation-ID', $correlation );
			return $response;
		} catch ( \Throwable $error ) {
			$code    = 'tgit_internal_error';
			$status  = 500;
			$message = __( 'Tracker operation failed. Retry with the same idempotency key.', 'ig-trading-journal' );
			if ( $error instanceof \InvalidArgumentException ) {
				$code    = 'tgit_validation';
				$status  = 400;
				$message = $error->getMessage();
			} elseif ( $error instanceof \DomainException ) {
							$code    = 'tgit_forbidden';
							$status  = 403;
							$message = $error->getMessage();
			} elseif ( $error instanceof \OutOfBoundsException ) {
							$code    = 'tgit_not_found';
							$status  = 404;
							$message = $error->getMessage();
			} elseif ( $error instanceof \UnexpectedValueException ) {
								$code    = 'tgit_conflict';
								$status  = 409;
								$message = $error->getMessage();
			}
							return new \WP_Error(
								$code,
								$message,
								array(
									'status'         => $status,
									'correlation_id' => $correlation,
								)
							);
		} finally {
			if ( null !== $previous ) {
				$wpdb->suppress_errors( $previous );
			}
		}
	}
}
