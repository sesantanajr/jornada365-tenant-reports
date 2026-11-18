<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data-minimization helpers for the "nothing is stored" model.
 *
 * Identifiers that must be compared later (who consented, extra authorized users, audit actors)
 * are stored only as keyed pseudonyms; IP addresses are truncated; free-form details are
 * scrubbed of e-mail addresses and personal fields before they reach the database.
 */
final class Privacy {

	private const PII_KEYS = [ 'upn', 'email', 'mail', 'name', 'display_name', 'displayName', 'consented_by', 'created_by', 'actor', 'user', 'users', 'extra_users', 'ip', 'login_hint' ];

	public static function uid( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || str_starts_with( $value, 'h:' ) ) {
			return $value;
		}
		return Crypto::pseudonym( $value );
	}

	/** Same pseudonym for every list element; empty values dropped, duplicates removed. */
	public static function uids( array $values ): array {
		return array_values( array_unique( array_filter( array_map( fn( $v ) => self::uid( (string) $v ), $values ) ) ) );
	}

	/** Actor for audit rows: operator accounts ("wp:…") and system actors stay readable; people are pseudonymized. */
	public static function actor( string $actor ): string {
		$actor = trim( $actor );
		if ( '' === $actor || str_starts_with( $actor, 'wp:' ) || str_starts_with( $actor, 'token:' ) || str_starts_with( $actor, 'h:' ) || in_array( $actor, [ 'setup', 'bootstrap', 'system', 'setup-script' ], true ) ) {
			return mb_substr( $actor, 0, 255 );
		}
		return self::uid( $actor );
	}

	/** 203.0.113.77 → 203.0.113.0 ; 2001:db8:1:2:… → 2001:db8:1:: */
	public static function ip( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			return $bin ? inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) ) : '';
		}
		return '';
	}

	/** Removes personal fields and masks e-mail addresses in audit details. */
	public static function scrub( array $details ): array {
		$out = [];
		foreach ( $details as $k => $v ) {
			if ( in_array( (string) $k, self::PII_KEYS, true ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $k ] = self::scrub( $v );
			} elseif ( is_string( $v ) ) {
				$out[ $k ] = mb_substr( self::mask_emails( $v ), 0, 300 );
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	public static function mask_emails( string $text ): string {
		return (string) preg_replace_callback( '/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', fn( $m ) => self::uid( $m[0] ), $text );
	}
}
