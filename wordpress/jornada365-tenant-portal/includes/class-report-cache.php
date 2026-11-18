<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact JSON codec used for encrypted job blobs.
 *
 * Jobs retain encrypted checkpoints until the result is collected. Earlier cache methods
 * remain available to integrations using the old interface.
 */
final class Report_Cache {

	public static function get( string $key ): ?array {
		return null;
	}

	public static function put( ...$args ): void {
	}

	public static function invalidate( string $tenant_id, string $report = '' ): void {
	}

	public static function purge_expired(): void {
	}

	public static function encode( array $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		$compressed = is_string( $json ) ? gzcompress( $json, 3 ) : false;
		if ( false === $compressed ) {
			throw new \RuntimeException( 'Não foi possível preparar os dados temporários deste relatório.' );
		}
		return $compressed;
	}

	public static function decode( string $blob ): ?array {
		if ( '' === $blob ) {
			return null;
		}
		$json = @gzuncompress( $blob ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}
}
