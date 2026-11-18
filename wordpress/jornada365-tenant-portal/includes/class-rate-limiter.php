<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed-window rate limiter backed by transients (object cache when available).
 */
final class Rate_Limiter {

	/**
	 * @return bool true when the request is allowed.
	 */
	public static function allow( string $bucket, int $limit, int $window_seconds ): bool {
		$key   = 'j365tp_rl_' . md5( $bucket . '|' . self::client_key( Request::client_ip() ) );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ( $state['reset'] ?? 0 ) < time() ) {
			$state = [ 'count' => 0, 'reset' => time() + $window_seconds ];
		}
		$state['count']++;
		set_transient( $key, $state, max( 1, $state['reset'] - time() ) );
		return $state['count'] <= $limit;
	}

	/** IPv4 per address; IPv6 per /64 (one subscriber/LAN usually owns a whole /64). */
	public static function client_key( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			if ( false !== $bin ) {
				return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
			}
		}
		return $ip;
	}
}
