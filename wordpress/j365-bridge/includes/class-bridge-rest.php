<?php
namespace J365BR;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only health endpoints for a paired operator. */
final class Bridge_Rest {

	public const NS = 'j365-bridge/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'prevent_caching' ], 10, 3 );
	}

	public function routes(): void {
		foreach ( [ '/ping' => 'ping', '/env' => 'env' ] as $path => $handler ) {
			register_rest_route(
				self::NS,
				$path,
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, $handler ],
					'permission_callback' => [ $this, 'auth' ],
				]
			);
		}
	}

	public function auth( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_ssl() ) {
			return new WP_Error( 'j365br_insecure', 'A ponte exige HTTPS.', [ 'status' => 403 ] );
		}
		$authorization = (string) $request->get_header( 'authorization' );
		if ( 1 === preg_match( '/\A(?i:bearer) (j365diag_[0-9a-f]{64})\z/', $authorization, $matches ) && Bridge_Token::verify( $matches[1] ) ) {
			return true;
		}
		return new WP_Error( 'j365br_unauthorized', 'Credencial de diagnóstico inválida ou expirada.', [ 'status' => 401 ] );
	}

	public function ping(): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true, 'bridge' => J365BR_VERSION ] );
	}

	public function env(): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true, 'data' => [ 'bridge' => J365BR_VERSION, 'portal' => Bridge_Ops::portal_status() ] ] );
	}

	public function prevent_caching( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		if ( str_starts_with( $request->get_route(), '/' . self::NS . '/' ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
			if ( 401 === $response->get_status() ) {
				$response->header( 'WWW-Authenticate', 'Bearer realm="J365 Bridge"' );
			}
		}
		return $response;
	}
}
