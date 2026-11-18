<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Application credential based on an X.509 certificate generated on this server.
 *
 * - The RSA private key is created here, encrypted at rest (libsodium) and never leaves
 *   the server; only the public certificate is published to the App Registration.
 * - Token requests use a signed client assertion (private_key_jwt), PS256 + x5t#S256 as
 *   documented by the Microsoft identity platform, with automatic RS256 + x5t fallback.
 * - A legacy client secret is still honored when no certificate is active.
 */
final class Client_Credential {

	public const OPTION        = 'j365tp_credential';
	public const VALIDITY_DAYS = 730;
	public const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

	/* ------------------------------------------------------------ storage */

	public static function state(): array {
		$s = get_option( self::OPTION, [] );
		$s = is_array( $s ) ? $s : [];
		return array_merge( [ 'active' => null, 'pending' => null, 'alg' => 'PS256', 'last_test' => null ], $s );
	}

	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/** Removes every certificate (per-tenant model: the portal has no app credential at all). */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	public static function active(): ?array {
		$a = self::state()['active'];
		return is_array( $a ) && ! empty( $a['cert_pem'] ) ? $a : null;
	}

	public static function pending(): ?array {
		$p = self::state()['pending'];
		return is_array( $p ) && ! empty( $p['cert_pem'] ) ? $p : null;
	}

	/** The certificate the setup script must register: pending (renewal) or active. */
	public static function to_register(): ?array {
		return self::pending() ?? self::active();
	}

	public static function has_active(): bool {
		$a = self::active();
		return $a && ! empty( $a['key_enc'] ) && '' !== Crypto::decrypt( (string) $a['key_enc'] );
	}

	public static function available(): bool {
		return function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_csr_sign' ) && Crypto::available();
	}

	/* ------------------------------------------------------------ lifecycle */

	/**
	 * Creates the first certificate (as pending until the setup script registers it).
	 */
	public static function ensure(): ?array {
		if ( self::to_register() ) {
			return self::to_register();
		}
		if ( ! self::available() ) {
			return null;
		}
		$state            = self::state();
		$state['pending'] = self::generate();
		self::save( $state );
		return $state['pending'];
	}

	/** Starts a renewal: a new pending certificate; the active one keeps working until promoted. */
	public static function renew(): array {
		$state            = self::state();
		$state['pending'] = self::generate();
		self::save( $state );
		return $state['pending'];
	}

	/** Called when the setup script confirms the certificate was uploaded to the App Registration. */
	public static function promote( string $thumbprint_sha1_hex ): bool {
		$state = self::state();
		$t     = strtoupper( preg_replace( '/[^0-9a-fA-F]/', '', $thumbprint_sha1_hex ) );
		if ( $state['pending'] && hash_equals( (string) $state['pending']['thumb_hex'], $t ) ) {
			$state['active']  = $state['pending'];
			$state['pending'] = null;
			$state['alg']     = 'PS256';
			self::save( $state );
			return true;
		}
		return $state['active'] && hash_equals( (string) $state['active']['thumb_hex'], $t );
	}

	public static function record_test( bool $ok, string $message ): void {
		$state              = self::state();
		$state['last_test'] = [ 'ok' => $ok, 'message' => mb_substr( $message, 0, 400 ), 'at' => gmdate( 'Y-m-d H:i:s' ) ];
		self::save( $state );
	}

	public static function set_alg( string $alg ): void {
		$state        = self::state();
		$state['alg'] = 'RS256' === $alg ? 'RS256' : 'PS256';
		self::save( $state );
	}

	/**
	 * @return array{cert_pem:string,key_enc:string,thumb_hex:string,x5t:string,x5t_s256:string,not_after:int,created:string}
	 */
	public static function generate( string $common_name = '' ): array {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'Extensões openssl/sodium indisponíveis para gerar o certificado.' );
		}
		$cn  = '' !== $common_name ? $common_name : ( Settings::APP_NAME . ' (portal)' );
		$cfg = [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256' ];
		$key = openssl_pkey_new( $cfg );
		if ( false === $key ) {
			throw new \RuntimeException( 'Falha ao gerar a chave RSA: ' . (string) openssl_error_string() );
		}
		$csr = openssl_csr_new( [ 'commonName' => mb_substr( $cn, 0, 64 ), 'organizationName' => 'Jornada 365' ], $key, $cfg );
		if ( false === $csr ) {
			throw new \RuntimeException( 'Falha ao gerar o CSR: ' . (string) openssl_error_string() );
		}
		$x509 = openssl_csr_sign( $csr, null, $key, self::VALIDITY_DAYS, $cfg, random_int( 1, PHP_INT_MAX ) );
		if ( false === $x509 ) {
			throw new \RuntimeException( 'Falha ao assinar o certificado: ' . (string) openssl_error_string() );
		}
		openssl_x509_export( $x509, $cert_pem );
		openssl_pkey_export( $key, $key_pem, null, $cfg );
		$der  = self::pem_to_der( $cert_pem );
		$info = openssl_x509_parse( $cert_pem );

		return [
			'cert_pem'  => $cert_pem,
			'key_enc'   => Crypto::encrypt( $key_pem ),
			'thumb_hex' => strtoupper( sha1( $der ) ),
			'x5t'       => self::b64url( sha1( $der, true ) ),
			'x5t_s256'  => self::b64url( hash( 'sha256', $der, true ) ),
			'not_after' => (int) ( $info['validTo_time_t'] ?? ( time() + self::VALIDITY_DAYS * DAY_IN_SECONDS ) ),
			'created'   => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/* ------------------------------------------------------------ token requests */

	/**
	 * Client authentication parameters for a token request against $token_url.
	 */
	public static function auth_params( string $token_url, ?string $force_alg = null ): array {
		$active = self::active();
		if ( $active && ! empty( $active['key_enc'] ) ) {
			$key_pem = Crypto::decrypt( (string) $active['key_enc'] );
			if ( '' !== $key_pem ) {
				return [
					'client_assertion_type' => self::ASSERTION_TYPE,
					'client_assertion'      => self::assertion( Settings::client_id(), $token_url, $active, $key_pem, $force_alg ?? self::state()['alg'] ),
				];
			}
		}
		$secret = Settings::client_secret();
		if ( '' !== $secret ) {
			return [ 'client_secret' => $secret ];
		}
		throw new Graph_Exception( 'Portal sem credencial de aplicativo. Conclua o Assistente de configuração no wp-admin.', 0, 'no_credential' );
	}

	public static function uses_certificate(): bool {
		return self::has_active();
	}

	/**
	 * Builds the signed JWT client assertion.
	 */
	public static function assertion( string $client_id, string $token_url, array $cert, string $key_pem, string $alg = 'PS256' ): string {
		$alg    = 'RS256' === $alg ? 'RS256' : 'PS256';
		$now    = time();
		$header = [ 'alg' => $alg, 'typ' => 'JWT', 'x5t' => $cert['x5t'] ];
		if ( 'PS256' === $alg ) {
			$header['x5t#S256'] = $cert['x5t_s256'];
		}
		$claims = [
			'aud' => $token_url,
			'iss' => $client_id,
			'sub' => $client_id,
			'jti' => wp_generate_uuid4(),
			'nbf' => $now - 30,
			'iat' => $now - 30,
			'exp' => $now + 540,
		];
		$input = self::b64url( (string) wp_json_encode( $header, JSON_UNESCAPED_SLASHES ) ) . '.' . self::b64url( (string) wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
		$sig   = 'PS256' === $alg ? self::sign_pss( $input, $key_pem ) : self::sign_pkcs1( $input, $key_pem );
		return $input . '.' . self::b64url( $sig );
	}

	private static function sign_pkcs1( string $data, string $key_pem ): string {
		$sig = '';
		if ( ! openssl_sign( $data, $sig, $key_pem, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'Falha ao assinar a client assertion (RS256).' );
		}
		return $sig;
	}

	/**
	 * RSASSA-PSS (SHA-256, MGF1-SHA-256, 32-byte salt) — EMSA-PSS encoding per RFC 8017 §9.1.1
	 * followed by the raw RSA private-key operation.
	 */
	public static function sign_pss( string $data, string $key_pem ): string {
		$key = openssl_pkey_get_private( $key_pem );
		if ( false === $key ) {
			throw new \RuntimeException( 'Chave privada inválida.' );
		}
		$bits   = (int) openssl_pkey_get_details( $key )['bits'];
		$em     = self::emsa_pss_encode( hash( 'sha256', $data, true ), $bits - 1 );
		$em     = str_pad( $em, intdiv( $bits + 7, 8 ), "\0", STR_PAD_LEFT );
		$sig    = '';
		if ( ! openssl_private_encrypt( $em, $sig, $key, OPENSSL_NO_PADDING ) ) {
			throw new \RuntimeException( 'Falha ao assinar a client assertion (PS256): ' . (string) openssl_error_string() );
		}
		return $sig;
	}

	public static function emsa_pss_encode( string $m_hash, int $em_bits, int $s_len = 32 ): string {
		$h_len  = 32;
		$em_len = intdiv( $em_bits + 7, 8 );
		if ( $em_len < $h_len + $s_len + 2 ) {
			throw new \RuntimeException( 'Chave RSA muito curta para PSS.' );
		}
		$salt     = random_bytes( $s_len );
		$h        = hash( 'sha256', str_repeat( "\0", 8 ) . $m_hash . $salt, true );
		$db       = str_repeat( "\0", $em_len - $s_len - $h_len - 2 ) . "\x01" . $salt;
		$mask     = self::mgf1( $h, $em_len - $h_len - 1 );
		$masked   = $db ^ $mask;
		$zero     = 8 * $em_len - $em_bits;
		$masked[0] = chr( ord( $masked[0] ) & ( 0xFF >> $zero ) );
		return $masked . $h . "\xbc";
	}

	private static function mgf1( string $seed, int $len ): string {
		$t = '';
		for ( $c = 0; strlen( $t ) < $len; $c++ ) {
			$t .= hash( 'sha256', $seed . pack( 'N', $c ), true );
		}
		return substr( $t, 0, $len );
	}

	/* ------------------------------------------------------------ helpers */

	public static function pem_to_der( string $pem ): string {
		$b64 = preg_replace( '/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem );
		return (string) base64_decode( (string) $b64, true );
	}

	public static function der_base64( array $cert ): string {
		return base64_encode( self::pem_to_der( (string) $cert['cert_pem'] ) );
	}

	public static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/** Public summary (never includes key material). */
	public static function describe( ?array $cert ): ?array {
		if ( ! $cert ) {
			return null;
		}
		return [
			'thumbprint' => $cert['thumb_hex'],
			'notAfter'   => gmdate( 'Y-m-d', (int) $cert['not_after'] ),
			'daysLeft'   => (int) floor( ( (int) $cert['not_after'] - time() ) / DAY_IN_SECONDS ),
			'created'    => $cert['created'],
		];
	}
}
