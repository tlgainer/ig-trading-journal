<?php
/**
 * Versioned REST integration (API 01-04).
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Http;

use GainerInteractive\IGTradingJournal\Application\Tracker;
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
				'args'                => 'GET' === $method && ( str_starts_with( $operation, 'list_' ) || 'holdings' === $operation ) ? array(
					'after' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 100,
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
			if ( str_starts_with( $operation, 'journal_' ) ) {
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
				$result = $service->holdings( $workspace, (int) $request['after'], (int) $request['limit'] );
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
