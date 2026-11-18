<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless portal session — nothing is stored on the server.
 *
 * The whole session lives in one cookie, encrypted and authenticated with the server key
 * (XSalsa20-Poly1305): __Host- prefix (Secure, Path=/, no Domain), HttpOnly, SameSite=Lax.
 * It carries the verified identity (tenant, user, roles), absolute and idle expiry, the time of
 * the last role verification and a random 256-bit DATA KEY. Report data processed in chunks is
 * encrypted with that data key, so whatever is temporarily on the server is unreadable without
 * the user's browser — and becomes garbage the moment the user signs out or the session expires.
 */
final class Session {

	public const COOKIE        = '__Host-j365s';
	public const LEGACY_COOKIE = 'j365tp_session';
	public const ROLE_RECHECK  = 900;  // seconds — Zero Trust: directory roles are re-verified every 15 minutes
	public const ROLE_GRACE    = 1800; // if Graph can't confirm the roles for 30 minutes, the session ends (fail closed)

	private static ?array $current = null;
	private static bool $resolved  = false;

	/**
	 * @param array $org ['name' => string, 'domain' => string] looked up live at sign-in (never persisted server-side)
	 */
	public static function create( string $tenant_id, string $user_oid, string $upn, string $display_name, array $roles, array $org = [] ): void {
		$now     = time();
		$ttl     = max( 1, (int) Settings::get( 'session_ttl_hours', 8 ) ) * HOUR_IN_SECONDS;
		$payload = [
			'v'     => 2,
			'sid'   => Crypto::random_token( 18 ),
			'tid'   => strtolower( $tenant_id ),
			'oid'   => $user_oid,
			'upn'   => mb_substr( $upn, 0, 200 ),
			'name'  => mb_substr( $display_name, 0, 120 ),
			'roles' => self::compact_roles( $roles ),
			'org'   => [ 'name' => mb_substr( (string) ( $org['name'] ?? '' ), 0, 120 ), 'domain' => mb_substr( (string) ( $org['domain'] ?? '' ), 0, 120 ) ],
			'iat'   => $now,
			'exp'   => $now + $ttl,
			'seen'  => $now,
			'rc'    => $now,
			'rn'    => $now + self::ROLE_RECHECK,
			'ua'    => self::ua_hash(),
			'k'     => Crypto::b64u_encode( random_bytes( 32 ) ),
		];
		self::write( $payload );
		self::clear_legacy();
		self::$current  = self::view( $payload );
		self::$resolved = true;
	}

	private static function compact_roles( array $roles ): array {
		// Keep the cookie small (≤ 8 roles) without ever dropping a role that grants access or changes.
		$key   = array_flip( array_merge( Rest_Api::MANAGE_ACCESS_ROLES, Rest_Api::MANAGE_APPS_ROLES, Settings::allowed_roles(), ...array_values( Action_Auth::CAP_ROLES ) ) );
		$rank  = fn( $r ) => isset( $key[ strtolower( (string) ( $r['id'] ?? '' ) ) ] ) ? 0 : 1;
		usort( $roles, fn( $a, $b ) => $rank( $a ) <=> $rank( $b ) );
		$out = [];
		foreach ( array_slice( $roles, 0, 8 ) as $r ) {
			$out[] = [ 'id' => (string) ( $r['id'] ?? '' ), 'name' => mb_substr( (string) ( $r['name'] ?? '' ), 0, 60 ) ];
		}
		return $out;
	}

	private static function ua_hash(): string {
		return substr( hash( 'sha256', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 16 );
	}

	private static function write( array $payload ): void {
		$value = Crypto::seal( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ), Crypto::subkey( 'session' ) );
		self::set_cookie( $value, (int) $payload['exp'] );
	}

	private static function set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, $value, [ 'expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
		$_COOKIE[ self::COOKIE ] = $value;
	}

	private static function clear_legacy(): void {
		if ( isset( $_COOKIE[ self::LEGACY_COOKIE ] ) && ! headers_sent() ) {
			setcookie( self::LEGACY_COOKIE, '', [ 'expires' => time() - YEAR_IN_SECONDS, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
		}
	}

	/** Decodes and validates the cookie. */
	private static function read(): ?array {
		$raw = $_COOKIE[ self::COOKIE ] ?? '';
		if ( ! is_string( $raw ) || strlen( $raw ) < 40 || strlen( $raw ) > 3800 ) {
			return null;
		}
		$json = Crypto::open( $raw, Crypto::subkey( 'session' ) );
		$data = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || 2 !== (int) ( $data['v'] ?? 0 ) || empty( $data['tid'] ) || empty( $data['k'] ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Current session view, or null. Enforces absolute expiry, idle timeout, browser binding
	 * and the operator's tenant status (revocation takes effect on the next request).
	 */
	public static function current(): ?array {
		if ( self::$resolved ) {
			return self::$current;
		}
		self::$resolved = true;

		$data = self::read();
		if ( ! $data ) {
			return null;
		}
		$now  = time();
		$idle = max( 5, (int) Settings::get( 'session_idle_minutes', 60 ) ) * MINUTE_IN_SECONDS;
		if ( (int) $data['exp'] < $now || ( $now - (int) $data['seen'] ) > $idle || ! hash_equals( (string) $data['ua'], self::ua_hash() ) ) {
			self::destroy();
			return null;
		}
		if ( ! Settings::tenant_allowed( (string) $data['tid'] ) ) {
			self::destroy();
			return null;
		}
		$tenant = Tenant_Repository::get( (string) $data['tid'] );
		if ( ! $tenant || 'active' !== $tenant['status'] ) {
			self::destroy();
			return null;
		}
		if ( ( $now - (int) $data['seen'] ) > 60 ) {
			$data['seen'] = $now;
			self::write( $data );
		}
		self::$current           = self::view( $data );
		self::$current['tenant'] = $tenant;
		return self::$current;
	}

	/** Shape consumed by the REST layer. */
	private static function view( array $d ): array {
		return [
			'tenant_id'    => (string) $d['tid'],
			'user_oid'     => (string) $d['oid'],
			'upn'          => (string) $d['upn'],
			'display_name' => (string) $d['name'],
			'roles'        => (array) $d['roles'],
			'org'          => (array) ( $d['org'] ?? [] ),
			'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) $d['exp'] ),
			'roles_at'     => (int) $d['rc'],
			'recheck_at'   => (int) ( $d['rn'] ?? ( (int) $d['rc'] + self::ROLE_RECHECK ) ),
			'issued_at'    => (int) ( $d['iat'] ?? 0 ),
			'sid'          => (string) $d['sid'],
			'data_key'     => (string) Crypto::b64u_decode( (string) $d['k'] ),
			'owner'        => substr( hash_hmac( 'sha256', (string) $d['sid'], Crypto::subkey( 'job-owner' ) ), 0, 36 ),
		];
	}

	/** Records a successful role re-verification (and the possibly changed role list). */
	public static function refresh_roles( array $roles ): void {
		$data = self::read();
		if ( ! $data ) {
			return;
		}
		$data['roles'] = self::compact_roles( $roles );
		$data['rc']    = time();
		$data['rn']    = $data['rc'] + self::ROLE_RECHECK;
		self::write( $data );
		if ( self::$current ) {
			self::$current['roles']      = $data['roles'];
			self::$current['roles_at']   = $data['rc'];
			self::$current['recheck_at'] = $data['rn'];
		}
	}

	/** Graph could not confirm the roles right now: try again shortly instead of on every request. */
	public static function defer_role_check( int $seconds = 60 ): void {
		$data = self::read();
		if ( ! $data ) {
			return;
		}
		$data['rn'] = time() + max( 15, $seconds );
		self::write( $data );
		if ( self::$current ) {
			self::$current['recheck_at'] = $data['rn'];
		}
	}

	/** CSRF token bound to this session (not to the WordPress user). */
	public static function csrf_token(): string {
		$s = self::current();
		return $s ? hash_hmac( 'sha256', 'j365tp-csrf|' . $s['sid'], Crypto::subkey( 'csrf' ) ) : '';
	}

	public static function destroy(): void {
		// Delegated action tokens die with the session (they would expire within the hour anyway).
		$cur = self::$current;
		if ( null === $cur && ! self::$resolved ) {
			$raw = self::read();
			$cur = $raw ? self::view( $raw ) : null;
		}
		if ( $cur && ! empty( $cur['sid'] ) ) {
			Action_Token::forget( $cur );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', [ 'expires' => time() - YEAR_IN_SECONDS, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
		}
		unset( $_COOKIE[ self::COOKIE ] );
		self::clear_legacy();
		self::$current  = null;
		self::$resolved = true;
	}

	/** Stateless sessions: revocation is enforced by the tenant status check in current(). */
	public static function destroy_for_tenant( string $tenant_id ): void {
	}

	public static function purge_expired(): void {
	}

	/** Test helper. */
	public static function reset_cache(): void {
		self::$current  = null;
		self::$resolved = false;
	}
}
