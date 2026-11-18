<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegated access tokens of the signed-in administrator, per HTTP request (per-tenant model).
 *
 * The browser obtains them from Microsoft with the tenant's own app (authorization code + PKCE,
 * single-page application) and sends them with each call to the portal API:
 *   X-J365-Graph: <Microsoft Graph access token>
 *   X-J365-Exo:   <Exchange Online access token>   (only for mailbox actions)
 * They are used in memory for this request only — never stored, never logged. Microsoft Graph and
 * Exchange validate the signature when they are used; here the claims are checked so a token can
 * only act for the session's own user, tenant and app.
 */
final class Delegated {

	public const HEADERS = [
		'graph' => 'HTTP_X_J365_GRAPH',
		'exo'   => 'HTTP_X_J365_EXO',
	];

	/** Accepted "aud" values per resource (v1 tokens carry the URI, v2 the app id). */
	public const AUDIENCES = [
		'graph' => [ 'https://graph.microsoft.com', 'https://graph.microsoft.com/', '00000003-0000-0000-c000-000000000000' ],
		'exo'   => [ 'https://outlook.office365.com', 'https://outlook.office365.com/', 'https://outlook.office.com', '00000002-0000-0ff1-ce00-000000000000' ],
	];

	/** Upper bound of a header value (a Graph token is 2–4 KB). */
	private const MAX_LEN = 12000;

	/** @var array<string, array{t:string, exp:int, scp:string[]}> */
	private static array $tokens = [];
	private static string $error = '';

	/**
	 * Reads the tokens of this request and keeps those issued to $client_id for this user.
	 *
	 * @return bool true when a usable Microsoft Graph token is present
	 */
	public static function capture( string $tenant_id, string $user_oid, string $client_id ): bool {
		self::$tokens = [];
		self::$error  = '';
		foreach ( self::HEADERS as $res => $header ) {
			$raw = isset( $_SERVER[ $header ] ) ? trim( (string) wp_unslash( $_SERVER[ $header ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- opaque bearer token, validated below
			if ( '' === $raw ) {
				continue;
			}
			if ( 0 === stripos( $raw, 'Bearer ' ) ) {
				$raw = trim( substr( $raw, 7 ) );
			}
			$why = self::accept( $res, $raw, $tenant_id, $user_oid, $client_id );
			if ( '' !== $why ) {
				self::$error = $why;
			}
		}
		return isset( self::$tokens['graph'] );
	}

	/** Validates one token and keeps it; returns '' or the reason it was refused. */
	public static function accept( string $res, string $jwt, string $tenant_id, string $user_oid, string $client_id ): string {
		if ( ! isset( self::AUDIENCES[ $res ] ) ) {
			return 'resource';
		}
		if ( strlen( $jwt ) > self::MAX_LEN || ! preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*$/', $jwt ) ) {
			return 'format';
		}
		$c   = self::claims( $jwt );
		$why = self::check( $c, $res, $tenant_id, $user_oid, $client_id );
		if ( '' !== $why ) {
			return $why;
		}
		self::$tokens[ $res ] = [
			't'   => $jwt,
			'exp' => (int) $c['exp'],
			'scp' => array_values( array_filter( explode( ' ', (string) ( $c['scp'] ?? '' ) ) ) ),
		];
		return '';
	}

	/**
	 * Claim checks (no signature check: Graph/Exchange verify it on every call; these checks stop a
	 * token minted for another app, tenant or user from being used through this session).
	 */
	public static function check( array $c, string $res, string $tenant_id, string $user_oid, string $client_id ): string {
		if ( ! $c ) {
			return 'unreadable';
		}
		if ( ! in_array( (string) ( $c['aud'] ?? '' ), self::AUDIENCES[ $res ] ?? [], true ) ) {
			return 'audience';
		}
		if ( strtolower( (string) ( $c['tid'] ?? '' ) ) !== strtolower( $tenant_id ) ) {
			return 'tenant';
		}
		if ( '' === $user_oid || (string) ( $c['oid'] ?? '' ) !== $user_oid ) {
			return 'user';
		}
		$app = strtolower( (string) ( $c['appid'] ?? $c['azp'] ?? '' ) );
		if ( '' === $client_id || $app !== strtolower( $client_id ) ) {
			return 'app';
		}
		if ( empty( $c['scp'] ) ) {
			return 'scope'; // app-only tokens (roles) are never accepted from a browser
		}
		if ( (int) ( $c['exp'] ?? 0 ) <= time() + 15 ) {
			return 'expired';
		}
		return '';
	}

	/** Payload of a JWT (no verification). */
	public static function claims( string $jwt ): array {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) {
			return [];
		}
		$data = json_decode( Jwt::b64url_decode( $parts[1] ), true );
		return is_array( $data ) ? $data : [];
	}

	public static function graph(): ?string {
		return self::$tokens['graph']['t'] ?? null;
	}

	public static function exchange(): ?string {
		return self::$tokens['exo']['t'] ?? null;
	}

	public static function get( string $res ): ?string {
		return self::$tokens[ $res ]['t'] ?? null;
	}

	/** @return string[] scopes ("scp") of the resource's token */
	public static function scopes( string $res = 'graph' ): array {
		return self::$tokens[ $res ]['scp'] ?? [];
	}

	public static function has_scope( string $res, string $scope ): bool {
		$have = self::scopes( $res );
		// A ReadWrite scope also covers the Read one (e.g. ReportSettings.ReadWrite.All ⊇ ReportSettings.Read.All).
		return in_array( $scope, $have, true ) || in_array( str_replace( '.Read.', '.ReadWrite.', $scope ), $have, true );
	}

	/** Seconds of validity left (0 = no token). */
	public static function remaining( string $res = 'graph' ): int {
		return isset( self::$tokens[ $res ] ) ? max( 0, (int) self::$tokens[ $res ]['exp'] - time() ) : 0;
	}

	public static function error(): string {
		return self::$error;
	}

	/** Test helper / end of a request. */
	public static function reset(): void {
		self::$tokens = [];
		self::$error  = '';
	}
}
