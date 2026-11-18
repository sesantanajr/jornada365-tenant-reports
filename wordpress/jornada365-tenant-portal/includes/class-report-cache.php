<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact JSON codec used for encrypted job blobs.
 *
 * Since 2.1 there is NO report cache on the server: results are kept only in the user's browser
 * tab. The former database methods remain as no-ops for backward compatibility.
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
		return (string) gzcompress( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ), 6 );
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
