<?php
/**
 * Journal and private-media REST integration.
 *
 * @package IGTradingJournal
 */

declare(strict_types=1);
namespace GainerInteractive\IGTradingJournal\Http;

use GainerInteractive\IGTradingJournal\Application\Journal;
use GainerInteractive\IGTradingJournal\Application\Media;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;

/** Route adapter shares authentication and safe errors with the ledger API. */
final class JournalRoutes {
	/** Register explicit REST commands and protected binary delivery.
	 *
	 * @return void
	 */
	public static function register(): void {
		$base   = '/workspaces/(?P<workspace>[1-9][0-9]*)';
		$routes = array(
			array( '/trades', 'GET', 'trades' ),
			array( '/trades', 'POST', 'save_trade' ),
			array( '/trades/(?P<object>[1-9][0-9]*)', 'GET', 'trade' ),
			array( '/trades/(?P<object>[1-9][0-9]*)', 'POST', 'save_trade' ),
			array( '/trades/(?P<object>[1-9][0-9]*)/revisions', 'GET', 'trade_history' ),
			array( '/trades/(?P<object>[1-9][0-9]*)/fill-candidates', 'GET', 'fill_candidates' ),
			array( '/strategies/(?P<object>[1-9][0-9]*)/versions', 'GET', 'strategy_history' ),
			array( '/strategies', 'GET', 'strategies' ),
			array( '/strategies', 'POST', 'save_strategy' ),
			array( '/strategies/(?P<object>[1-9][0-9]*)', 'GET', 'strategy' ),
			array( '/strategies/(?P<object>[1-9][0-9]*)', 'POST', 'save_strategy' ),
			array( '/media-settings', 'GET', 'settings' ),
			array( '/media-settings', 'POST', 'save_settings' ),
			array( '/media-cleanup', 'POST', 'cleanup' ),
			array( '/trades/(?P<object>[1-9][0-9]*)/images', 'GET', 'gallery' ),
			array( '/trades/(?P<object>[1-9][0-9]*)/images', 'POST', 'reserve' ),
			array( '/images/(?P<object>[1-9][0-9]*)/upload', 'POST', 'upload' ),
			array( '/images/(?P<object>[1-9][0-9]*)/retry', 'POST', 'retry' ),
			array( '/images/(?P<object>[1-9][0-9]*)/metadata', 'POST', 'update' ),
			array( '/images/(?P<object>[1-9][0-9]*)/delete', 'POST', 'delete' ),
			array( '/images/(?P<object>[1-9][0-9]*)/restore', 'POST', 'restore' ),
			array( '/images/(?P<object>[1-9][0-9]*)/content', 'GET', 'content' ),
		);
		foreach ( $routes as $route ) {
			list($path, $method, $operation) = $route;
			register_rest_route(
				'tgit/v1',
				$base . $path,
				array(
					'methods'             => $method,
					'permission_callback' => array( Controller::class, 'permission' ),
					'callback'            => static function ( $request ) use ( $operation ) {
						return Controller::dispatch( $request, 'journal_' . $operation );
					},
					'args'                => in_array( $operation, array( 'trades', 'strategies', 'fill_candidates' ), true ) ? array(
						'asset_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'after'    => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'limit'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 100,
						),
					) : ( in_array( $operation, array( 'trade_history', 'strategy_history' ), true ) ? array(
						'before' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => PHP_INT_MAX,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 20,
							'default' => 20,
						),
					) : ( 'content' === $operation ? array(
						'variant' => array(
							'type'    => 'string',
							'enum'    => array( 'original', 'thumbnail' ),
							'default' => 'original',
						),
					) : array() ) ),
				)
			);
		}
		add_filter( 'rest_pre_serve_request', array( self::class, 'stream' ), 10, 4 );
	}
	/** Execute an authenticated command.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $operation Operation name.
	 * @param array            $input JSON input.
	 * @param string           $correlation Request context.
	 * @return array|\WP_REST_Response
	 * @throws \LogicException When operation is unknown.
	 * @throws \InvalidArgumentException When multipart input is invalid.
	 */
	public static function execute( $request, string $operation, array $input, string $correlation ) {
		global $wpdb;
		$params    = $request->get_url_params();
		$workspace = (int) $params['workspace'];
		$id        = (int) ( $params['object'] ?? 0 );
		$db        = new Database( $wpdb );
		$journal   = new Journal( $db, get_current_user_id(), $correlation );
		$media     = new Media( $db, get_current_user_id(), $correlation );
		$key       = (string) $request->get_header( 'idempotency-key' );
		if ( 'fill_candidates' === $operation ) {
			return $journal->fill_candidates( $workspace, $id, (int) $request['asset_id'], (int) $request['after'], (int) $request['limit'] );
		}
		if ( in_array( $operation, array( 'trades', 'strategies' ), true ) ) {
			return $journal->listing( $workspace, $operation, (int) $request['after'], (int) $request['limit'] );
		}
		if ( in_array( $operation, array( 'trade_history', 'strategy_history' ), true ) ) {
			return $journal->history( $workspace, 'trade_history' === $operation ? 'trades' : 'strategies', $id, (int) ( $request['before'] ?? PHP_INT_MAX ), (int) ( $request['limit'] ?? 20 ) );
		}
		if ( in_array( $operation, array( 'trade', 'strategy' ), true ) ) {
			return $journal->$operation( $workspace, $id );
		}
		if ( in_array( $operation, array( 'save_trade', 'save_strategy' ), true ) ) {
			return $journal->$operation( $workspace, $id, $input, $key );
		}
		if ( 'settings' === $operation ) {
			return $media->settings( $workspace );
		}
		if ( 'save_settings' === $operation ) {
			return $media->save_settings( $workspace, $input, $key );
		}
		if ( 'cleanup' === $operation ) {
			if ( $input ) {
				throw new \InvalidArgumentException( 'Cleanup accepts an empty object.' );
			} return $media->cleanup( $workspace, $key );
		}
		if ( 'gallery' === $operation ) {
			return $media->gallery( $workspace, $id );
		}
		if ( 'reserve' === $operation ) {
			return $media->reserve( $workspace, $id, $input, $key );
		}
		if ( in_array( $operation, array( 'update', 'delete', 'restore' ), true ) ) {
			return $media->change( $workspace, $id, $operation, $input, $key );
		}
		if ( 'retry' === $operation ) {
			return $media->retry( $workspace, $id, $input, $key );
		}
		if ( 'upload' === $operation ) {
			$files = $request->get_file_params();
			if ( array_keys( $files ) !== array( 'file' ) || $request->get_body_params() ) {
				throw new \InvalidArgumentException( 'Upload exactly one file per reserved image.' );
			}
			return $media->upload( $workspace, $id, $files['file'], $key );
		}
		if ( 'content' === $operation ) {
			$content  = $media->content( $workspace, $id, (string) ( $request['variant'] ?? 'original' ) );
			$response = new \WP_REST_Response( null, 200 );
			$response->header( 'Content-Type', $content['mime'] );
			$response->header( 'Content-Length', (string) $content['bytes'] );
			$response->header( 'Content-Disposition', 'inline; filename="trade-image-' . $id . '"' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow, noarchive' );
			return $response;
		}
		throw new \LogicException( 'Unknown journal operation.' );
	}
	/** Stream only an authorized successful binary route, rechecking revocation.
	 *
	 * @param bool              $served Prior serve result.
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Request  $request Request.
	 * @param \WP_REST_Server   $server Server.
	 * @return bool
	 */
	public static function stream( $served, $response, $request, $server ): bool {
		if ( $served || 200 !== $response->get_status() || 'GET' !== $request->get_method() || ! preg_match( '#^/tgit/v1/workspaces/[1-9][0-9]*/images/[1-9][0-9]*/content$#D', $request->get_route() ) ) {
			return $served;
		}
		try {
			global $wpdb;
			$params  = $request->get_url_params();
			$content = ( new Media( new Database( $wpdb ), get_current_user_id(), wp_generate_uuid4() ) )->content( (int) $params['workspace'], (int) $params['object'], (string) ( $request['variant'] ?? 'original' ) );
		 // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a generated, workspace-scoped path after authorization; no user path or URL is accepted.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Open a private generated file after authorization without leaking native filesystem diagnostics.
			$stream = @fopen( $content['path'], 'rb' );
			if ( false === $stream ) {
				status_header( 404 );
				$server->send_header( 'Content-Length', '0' );
				return true;
			}
			$server->send_header( 'Content-Type', $content['mime'] );
			$server->send_header( 'Content-Length', (string) $content['bytes'] );
			try {
				fpassthru( $stream );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the authenticated native binary stream opened above.
				fclose( $stream );
			}
		} catch ( \Throwable $error ) {
			status_header( 403 );
			$server->send_header( 'Content-Length', '0' );
		}
		return true;
	}
}
