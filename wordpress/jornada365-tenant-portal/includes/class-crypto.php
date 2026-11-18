<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated encryption (XSalsa20-Poly1305 via libsodium) for secrets at rest.
 *
 * Key material is derived from the WordPress salts. Rotating AUTH_KEY/SECURE_AUTH_KEY
 * in wp-config.php invalidates stored ciphertext (the client secret must be re-entered).
 * An optional J365TP_ENCRYPTION_KEY constant (32+ random bytes, base64) takes precedence
 * and is the recommended production setup because it decouples secret storage from salt rotation.
 */
final class Crypto {

	private const PREFIX = 'j365v1:';

	private static function key(): string {
		if ( defined( 'J365TP_ENCRYPTION_KEY' ) && is_string( J365TP_ENCRYPTION_KEY ) && J365TP_ENCRYPTION_KEY !== '' ) {
			$raw = base64_decode( J365TP_ENCRYPTION_KEY, true );
			if ( false !== $raw && strlen( $raw ) >= 32 ) {
				return hash( 'sha256', 'j365tp|' . $raw, true );
			}
		}
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . '|' . ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
		return hash( 'sha256', 'j365tp|' . $material, true );
	}

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! self::available() ) {
			throw new \RuntimeException( 'libsodium indisponível no PHP. Habilite a extensão sodium.' );
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * @return string Plaintext, or empty string if the payload cannot be decrypted.
	 */
	public static function decrypt( string $payload ): string {
		if ( '' === $payload || strpos( $payload, self::PREFIX ) !== 0 || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/** Context-bound 32-byte subkey derived from the server key (domain separation). */
	public static function subkey( string $context ): string {
		return hash_hmac( 'sha256', 'j365tp-subkey|' . $context, self::key(), true );
	}

	/**
	 * Encrypts with a caller-provided 32-byte key (e.g. the per-session data key that only the
	 * user's browser holds). Returns base64url(nonce || ciphertext).
	 */
	public static function seal( string $plaintext, string $key ): string {
		if ( strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new \InvalidArgumentException( 'Chave de sessão inválida.' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::b64u_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) );
	}

	public static function open( string $blob, string $key ): ?string {
		if ( '' === $blob || strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			return null;
		}
		$raw = self::b64u_decode( $blob );
		if ( null === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Keyed, non-reversible pseudonym (HMAC-SHA256, server secret) for identifiers that must be
	 * compared but never stored in clear (UPNs in audit/authorization lists).
	 */
	public static function pseudonym( string $value ): string {
		$v = strtolower( trim( $value ) );
		return '' === $v ? '' : 'h:' . substr( hash_hmac( 'sha256', $v, self::subkey( 'pseudonym' ) ), 0, 24 );
	}

	public static function b64u_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function b64u_decode( string $s ): ?string {
		$raw = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $raw ? null : $raw;
	}

	public static function random_token( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}
}
