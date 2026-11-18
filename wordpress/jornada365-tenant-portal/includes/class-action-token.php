<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-lived delegated access tokens for actions (sync a device, enable an archive).
 *
 * The token belongs to the signed-in admin and is kept server-side only while it is valid
 * (≤ 60 min), sealed with the DATA KEY of the user's session cookie: without that browser's cookie
 * the server cannot read it. It never reaches the browser, and signing out deletes it.
 */
final class Action_Token {

	private const MAX_TTL = 3600;

	private static function key( array $session, string $cap ): string {
		return 'j365tp_atk_' . substr( hash_hmac( 'sha256', (string) $session['sid'] . '|' . $cap, Crypto::subkey( 'action-token' ) ), 0, 40 );
	}

	/**
	 * Per-tenant model: the token comes with the request (Delegated) and must carry the capability's
	 * scopes; nothing is kept on the server.
	 */
	private static function delegated( string $cap ): ?string {
		$res = 'exchange' === ( Action_Auth::CAPS[ $cap ]['resource'] ?? '' ) ? 'exo' : 'graph';
		foreach ( (array) ( Action_Auth::CAPS[ $cap ]['scopes'] ?? [] ) as $scope ) {
			if ( ! Delegated::has_scope( $res, $scope ) ) {
				return null;
			}
		}
		return Delegated::get( $res );
	}

	public static function put( array $session, string $cap, string $token, int $expires_in ): void {
		if ( Settings::per_tenant() ) {
			return;
		}
		$ttl = max( 60, min( self::MAX_TTL, $expires_in - 120 ) );
		$box = Crypto::seal( (string) wp_json_encode( [ 't' => $token, 'exp' => time() + $ttl, 'oid' => $session['user_oid'] ] ), $session['data_key'] );
		set_transient( self::key( $session, $cap ), $box, $ttl );
	}

	public static function get( array $session, string $cap ): ?string {
		if ( Settings::per_tenant() ) {
			return self::delegated( $cap );
		}
		$raw = get_transient( self::key( $session, $cap ) );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$plain = Crypto::open( $raw, $session['data_key'] );
		$data  = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) || (int) ( $data['exp'] ?? 0 ) <= time() || ( $data['oid'] ?? '' ) !== $session['user_oid'] ) {
			return null;
		}
		return (string) $data['t'];
	}

	/** Seconds left (0 = no usable token). */
	public static function remaining( array $session, string $cap ): int {
		if ( Settings::per_tenant() ) {
			return null === self::delegated( $cap ) ? 0 : Delegated::remaining( 'exchange' === ( Action_Auth::CAPS[ $cap ]['resource'] ?? '' ) ? 'exo' : 'graph' );
		}
		$raw = get_transient( self::key( $session, $cap ) );
		if ( ! is_string( $raw ) ) {
			return 0;
		}
		$plain = Crypto::open( $raw, $session['data_key'] );
		$data  = null === $plain ? null : json_decode( $plain, true );
		return is_array( $data ) && ( $data['oid'] ?? '' ) === $session['user_oid'] ? max( 0, (int) ( $data['exp'] ?? 0 ) - time() ) : 0;
	}

	public static function forget( array $session, ?string $cap = null ): void {
		if ( Settings::per_tenant() ) {
			return;
		}
		foreach ( null === $cap ? array_keys( Action_Auth::CAPS ) : [ $cap ] as $c ) {
			delete_transient( self::key( $session, $c ) );
		}
	}
}
