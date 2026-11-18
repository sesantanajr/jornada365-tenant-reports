<?php
namespace J365BR;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A single short-lived, site-specific diagnostics credential. */
final class Bridge_Token {

	public const OPTION = 'j365br_diagnostic_token';
	private const PREFIX = 'j365diag_';

	public static function issue( int $hours ): string|WP_Error {
		try {
			$token = self::PREFIX . bin2hex( random_bytes( 32 ) );
		} catch ( \Exception ) {
			return new WP_Error( 'j365br_entropy', 'O WordPress não conseguiu gerar um token seguro.' );
		}

		$expires = time() + max( 1, min( 24, $hours ) ) * HOUR_IN_SECONDS;
		if ( ! update_option( self::OPTION, [ 'hash' => hash( 'sha256', $token ), 'expires' => $expires ], false ) ) {
			return new WP_Error( 'j365br_storage', 'O WordPress não conseguiu gravar o token de diagnóstico.' );
		}
		return $token;
	}

	public static function expires(): int {
		$record = get_option( self::OPTION, [] );
		return is_array( $record ) && is_int( $record['expires'] ?? null ) ? $record['expires'] : 0;
	}

	public static function revoke(): bool {
		return delete_option( self::OPTION ) || false === get_option( self::OPTION );
	}

	public static function verify( string $token ): bool {
		if ( 1 !== preg_match( '/\Aj365diag_[0-9a-f]{64}\z/', $token ) ) {
			return false;
		}
		$record = get_option( self::OPTION, [] );
		return is_array( $record )
			&& is_string( $record['hash'] ?? null )
			&& is_int( $record['expires'] ?? null )
			&& $record['expires'] > time()
			&& hash_equals( $record['hash'], hash( 'sha256', $token ) );
	}

	public static function discard_legacy_tokens(): void {
		if ( false !== get_option( 'j365br_tokens' ) ) {
			delete_transient( 'j365br_new_token' );
			delete_option( 'j365br_tokens' );
		}
	}
}
