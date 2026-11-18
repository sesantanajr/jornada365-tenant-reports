<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RS256 ID token verification against the Microsoft identity platform JWKS.
 * No external dependencies: builds the RSA public key (SPKI DER) from JWK n/e.
 */
final class Jwt {

	private const JWKS_URL = 'https://login.microsoftonline.com/organizations/discovery/v2.0/keys';
	private const ISSUER_TEMPLATE = 'https://login.microsoftonline.com/{tenantid}/v2.0';

	/**
	 * @return array{header: array, claims: array}
	 * @throws \RuntimeException on any validation failure.
	 */
	public static function verify_id_token( string $jwt, string $expected_audience, string $expected_nonce ): array {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			throw new \RuntimeException( 'ID token malformado.' );
		}
		[ $h64, $p64, $s64 ] = $parts;

		$header = json_decode( self::b64url_decode( $h64 ), true );
		$claims = json_decode( self::b64url_decode( $p64 ), true );
		$sig    = self::b64url_decode( $s64 );

		if ( ! is_array( $header ) || ! is_array( $claims ) || '' === $sig ) {
			throw new \RuntimeException( 'ID token ilegível.' );
		}
		$kid = $header['kid'] ?? '';
		if ( ( $header['alg'] ?? '' ) !== 'RS256' || ! is_string( $kid ) || '' === $kid ) {
			throw new \RuntimeException( 'Algoritmo de assinatura não suportado.' );
		}

		// Bind the tenant-independent discovery keys to a candidate issuer before verifying the signature.
		$tid = is_string( $claims['tid'] ?? null ) ? strtolower( $claims['tid'] ) : '';
		if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $tid ) ) {
			throw new \RuntimeException( 'Tenant (tid) ausente no ID token.' );
		}
		$issuer = str_replace( '{tenantid}', $tid, self::ISSUER_TEMPLATE );
		if ( ( $claims['iss'] ?? '' ) !== $issuer ) {
			throw new \RuntimeException( 'Issuer do ID token inválido.' );
		}

		$jwk = self::find_key( $kid, $issuer );
		if ( ! $jwk ) {
			throw new \RuntimeException( 'Chave de assinatura (kid) não encontrada no JWKS para este emissor.' );
		}

		$pem = self::jwk_to_pem( $jwk );
		$ok  = openssl_verify( $h64 . '.' . $p64, $sig, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			throw new \RuntimeException( 'Assinatura do ID token inválida.' );
		}

		$now = time();
		if ( ( $claims['exp'] ?? 0 ) < $now - 60 ) {
			throw new \RuntimeException( 'ID token expirado.' );
		}
		if ( ( $claims['nbf'] ?? 0 ) > $now + 60 ) {
			throw new \RuntimeException( 'ID token ainda não é válido (nbf).' );
		}
		if ( ( $claims['aud'] ?? '' ) !== $expected_audience ) {
			throw new \RuntimeException( 'Audience do ID token não corresponde ao Client ID.' );
		}
		if ( ! hash_equals( $expected_nonce, (string) ( $claims['nonce'] ?? '' ) ) ) {
			throw new \RuntimeException( 'Nonce inválido (possível replay).' );
		}
		if ( empty( $claims['oid'] ) ) {
			throw new \RuntimeException( 'Object ID do usuário ausente no ID token.' );
		}

		return [ 'header' => $header, 'claims' => $claims ];
	}

	private static function find_key( string $kid, string $issuer ): ?array {
		// Refresh once on a miss to account for Microsoft's signing key rollover.
		foreach ( [ false, true ] as $refresh ) {
			foreach ( self::jwks( $refresh ) as $key ) {
				if ( ( $key['kid'] ?? '' ) === $kid && in_array( $key['issuer'] ?? '', [ self::ISSUER_TEMPLATE, $issuer ], true ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	private static function jwks( bool $force = false ): array {
		$cache_key = 'j365tp_jwks';
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = wp_remote_get( self::JWKS_URL, [ 'timeout' => 20 ] );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Não foi possível baixar o JWKS da Microsoft: ' . $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$keys = $data['keys'] ?? [];
		if ( ! $keys ) {
			throw new \RuntimeException( 'JWKS vazio.' );
		}
		set_transient( $cache_key, $keys, DAY_IN_SECONDS );
		return $keys;
	}

	public static function b64url_decode( string $s ): string {
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		return (string) base64_decode( strtr( $s, '-_', '+/' ), true );
	}

	/**
	 * Build a PEM public key from JWK modulus/exponent (RSA, SPKI).
	 */
	public static function jwk_to_pem( array $jwk ): string {
		$n = self::b64url_decode( (string) ( $jwk['n'] ?? '' ) );
		$e = self::b64url_decode( (string) ( $jwk['e'] ?? '' ) );
		if ( '' === $n || '' === $e ) {
			throw new \RuntimeException( 'JWK inválido.' );
		}
		$mod = self::der_int( $n );
		$exp = self::der_int( $e );
		$rsa = self::der( 0x30, $mod . $exp ); // RSAPublicKey SEQUENCE

		$alg_id = self::der( 0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00" ); // rsaEncryption + NULL
		$bitstr = self::der( 0x03, "\x00" . $rsa );
		$spki   = self::der( 0x30, $alg_id . $bitstr );

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private static function der_int( string $bytes ): string {
		if ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes; // keep positive
		}
		return self::der( 0x02, $bytes );
	}

	private static function der( int $tag, string $content ): string {
		$len = strlen( $content );
		if ( $len < 0x80 ) {
			$len_bytes = chr( $len );
		} else {
			$tmp = ltrim( pack( 'N', $len ), "\x00" );
			$len_bytes = chr( 0x80 | strlen( $tmp ) ) . $tmp;
		}
		return chr( $tag ) . $len_bytes . $content;
	}
}
