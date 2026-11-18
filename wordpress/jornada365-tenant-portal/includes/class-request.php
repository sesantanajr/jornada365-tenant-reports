<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request helpers: client IP (Cloudflare-aware), base URLs, redirect URIs.
 */
final class Request {

	/** Cloudflare IPv4/IPv6 ranges (https://www.cloudflare.com/ips/). Reviewed 2026. */
	private const CF_RANGES = [
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	];

	public static function client_ip(): string {
		$remote = $_SERVER['REMOTE_ADDR'] ?? '';
		if ( Settings::get( 'trust_cloudflare' ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::is_cloudflare( $remote ) ) {
			$cf = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}

	public static function user_agent(): string {
		return substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 );
	}

	private static function is_cloudflare( string $ip ): bool {
		foreach ( self::CF_RANGES as $cidr ) {
			if ( self::cidr_match( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	private static function cidr_match( string $ip, string $cidr ): bool {
		[ $subnet, $bits ] = explode( '/', $cidr );
		$ip_bin            = inet_pton( $ip );
		$subnet_bin        = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}

	/**
	 * Normalizes a tenant hint for the Microsoft identity platform authority segment:
	 * a tenant GUID, a verified domain (contoso.com / contoso.onmicrosoft.com), or $fallback.
	 */
	public static function tenant_segment( string $hint, string $fallback = 'organizations' ): string {
		$hint = strtolower( trim( $hint ) );
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $hint ) ) {
			return $hint;
		}
		if ( strlen( $hint ) <= 253 && preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $hint ) ) {
			return $hint;
		}
		return $fallback;
	}

	public static function route_url( string $route ): string {
		return home_url( '/j365/' . trim( $route, '/' ) . '/' );
	}

	/**
	 * Sign-in URL carrying the visitor's language: the Microsoft screens and every portal page
	 * follow it, and the query string keeps page caches from ever serving a stored copy.
	 */
	public static function login_url( array $extra = [] ): string {
		return add_query_arg( array_merge( [ 'lang' => I18n::current() ], $extra ), self::route_url( 'auth/login' ) );
	}

	public static function login_redirect_uri(): string {
		return self::route_url( 'auth/callback' );
	}

	public static function consent_redirect_uri(): string {
		return self::route_url( 'consent/callback' );
	}

	public static function portal_url(): string {
		$page_id = (int) Settings::get( 'portal_page_id', 0 );
		if ( $page_id > 0 ) {
			$link = get_permalink( $page_id );
			if ( $link ) {
				return $link;
			}
		}
		return home_url( '/' );
	}
}
