<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal, pseudonymized audit trail (security events only).
 *
 * People are recorded as keyed pseudonyms (never UPN/e-mail/name), IP addresses are truncated,
 * details are scrubbed of personal fields and rows are deleted after the retention period
 * (default 30 days). No report content is ever written here.
 */
final class Audit {

	public static function log( string $action, string $tenant_id = '', string $actor = '', array $details = [] ): void {
		global $wpdb;
		$details = Privacy::scrub( $details );
		$wpdb->insert(
			Installer::table( 'audit' ),
			[
				'ts'        => gmdate( 'Y-m-d H:i:s' ),
				'tenant_id' => substr( strtolower( $tenant_id ), 0, 36 ),
				'actor'     => Privacy::actor( $actor ),
				'action'    => substr( $action, 0, 60 ),
				'details'   => $details ? wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) : null,
				'ip'        => Privacy::ip( Request::client_ip() ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public static function recent( int $limit = 200, string $tenant_id = '' ): array {
		global $wpdb;
		$table = Installer::table( 'audit' );
		if ( '' !== $tenant_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY id DESC LIMIT %d", $tenant_id, $limit ), ARRAY_A ) ?: [];
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: [];
	}

	public static function retention_days(): int {
		return max( 1, min( 365, (int) Settings::get( 'audit_days', 30 ) ) );
	}

	public static function purge(): void {
		global $wpdb;
		$table = Installer::table( 'audit' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS ) ) );
	}
}
