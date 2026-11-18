<?php
namespace J365TP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operator diagnostics: runs every enabled report against the publisher (home) tenant and returns
 * only the outcome — status, row count, duration, warnings and errors. No report content is
 * returned or stored. Available to WordPress administrators (wp-admin → Assistente) and to the
 * J365 Bridge pairing token when that plugin is active.
 */
final class Diagnostics {

	public static function routes(): void {
		register_rest_route( Rest_Api::NS, '/diag/reports', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest' ], 'permission_callback' => [ self::class, 'permission' ] ] );
	}

	public static function permission( WP_REST_Request $request ) {
		if ( Bootstrap::can() ) {
			return true;
		}
		$auth = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) && class_exists( '\\J365BR\\Bridge_Token' ) && null !== \J365BR\Bridge_Token::verify( $m[1] ) ) {
			return true;
		}
		return new WP_Error( 'j365_forbidden', 'Somente o operador do portal pode executar o diagnóstico.', [ 'status' => 403 ] );
	}

	public static function rest( WP_REST_Request $request ): WP_REST_Response {
		$tenant = strtolower( (string) Settings::get( 'home_tenant_id', '' ) );
		if ( '' === $tenant || ! Settings::is_configured() ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Registre o aplicativo pelo portal antes de testar.' ], 409 );
		}
		$only  = sanitize_key( (string) ( $request->get_param( 'type' ) ?? '' ) );
		$types = '' !== $only ? [ $only ] : array_values( array_diff( Settings::active_reports(), [ 'app_devices' ] ) );
		@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$results = [];
		foreach ( $types as $type ) {
			// Stay below reverse-proxy limits (Cloudflare closes requests after ~100 s): call one type per request for big tenants.
			$results[] = self::run_one( $tenant, $type, '' !== $only ? 85 : 120 );
		}
		Audit::log( 'diagnostics', $tenant, 'system', [ 'reports' => count( $results ), 'failed' => count( array_filter( $results, fn( $r ) => ! $r['ok'] ) ) ] );
		$r = new WP_REST_Response( [ 'ok' => true, 'tenant' => $tenant, 'version' => J365TP_VERSION, 'results' => $results ] );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/** Runs one report to completion (bounded) and summarizes the outcome. */
	public static function run_one( string $tenant, string $type, int $budget = 120 ): array {
		$t0    = microtime( true );
		$class = Jobs::report_class( $type );
		if ( ! $class ) {
			return [ 'type' => $type, 'ok' => false, 'error' => 'Relatório desconhecido ou módulo desativado.', 'ms' => 0 ];
		}
		$notes = [];
		try {
			/** @var Reports\Report_Base $report */
			$report = new $class( $tenant );
			$report->set_deadline( $t0 + $budget ); // throttling waits stay inside the diagnostic budget
			$params = $report->normalize_params( [] );
			$state  = $report->initial_state( $params );
			$steps  = 0;
			do {
				$res   = $report->step( $state, $params, microtime( true ) + Jobs::STEP_BUDGET );
				$state = $res['state'];
				$notes = array_merge( $notes, $report->graph_notes() );
				++$steps;
				if ( (int) ( $state['_retry_at'] ?? 0 ) > time() ) {
					return [ 'type' => $type, 'ok' => false, 'error' => 'O Microsoft Graph pediu uma pausa. O relatório normal pode retomar em uma nova etapa.', 'steps' => $steps, 'ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) ];
				}
			} while ( empty( $res['done'] ) && ( microtime( true ) - $t0 ) < $budget );

			if ( empty( $res['done'] ) ) {
				return [ 'type' => $type, 'ok' => false, 'error' => 'Tempo de diagnóstico excedido (relatório grande — funciona em etapas no portal).', 'steps' => $steps, 'ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ) ];
			}
			$payload = $report->finalize( $state, $params );
			$rows    = (int) ( $payload['_meta']['rows'] ?? ( is_array( $payload['rows'] ?? null ) ? count( $payload['rows'] ) : 0 ) );
			return [
				'type'     => $type,
				'ok'       => true,
				'rows'     => $rows,
				'steps'    => $steps,
				'ms'       => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'warnings' => array_values( array_unique( array_map( [ Privacy::class, 'mask_emails' ], array_merge( (array) ( $state['_warnings'] ?? [] ), $notes ) ) ) ),
			];
		} catch ( \Throwable $e ) {
			return [
				'type'  => $type,
				'ok'    => false,
				'error' => Privacy::mask_emails( $e->getMessage() ),
				'where' => basename( $e->getFile() ) . ':' . $e->getLine(),
				'ms'    => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
			];
		}
	}
}
