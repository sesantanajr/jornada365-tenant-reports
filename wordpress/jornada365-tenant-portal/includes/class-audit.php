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

	/** Read a cursor page within one tenant and its retention window, even before cleanup runs. */
	public static function for_tenant( string $tenant_id, string $me, array $filters ): array {
		if ( '' === $tenant_id ) {
			return [ 'rows' => [], 'actions' => [], 'nextCursor' => null ];
		}
		global $wpdb;
		$table = Installer::table( 'audit' );
		$since = time() - self::retention_days() * DAY_IN_SECONDS;
		$where = [ 'tenant_id = %s', 'ts >= %s' ];
		$args  = [ $tenant_id, gmdate( 'Y-m-d H:i:s', max( $since, $filters['after'] ) ) ];
		if ( $filters['until'] ) {
			$where[] = 'ts < %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', $filters['until'] );
		}
		if ( $filters['cursor'] ) {
			$where[] = 'id < %d';
			$args[]  = $filters['cursor'];
		}
		if ( '' !== $filters['action'] ) {
			$where[] = 'action = %s';
			$args[]  = $filters['action'];
		}
		switch ( $filters['actor'] ) {
			case 'you':
				$where[] = 'actor = %s';
				$args[] = $me;
				break;
			case 'other':
				$where[] = 'actor LIKE %s AND actor <> %s';
				$args[] = 'h:%';
				$args[] = $me;
				break;
			case 'system':
				$where[] = 'actor NOT LIKE %s AND actor <> %s';
				$args[] = 'h:%';
				$args[] = $me;
		}
		$condition = implode( ' AND ', $where );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, ts, action, actor, details FROM {$table} WHERE {$condition} ORDER BY id DESC LIMIT 201", $args ), ARRAY_A ) ?: [];
		$more = count( $rows ) > 200;
		if ( $more ) {
			array_pop( $rows );
		}
		$cursor = $more ? (int) end( $rows )['id'] : null;
		foreach ( $rows as &$row ) {
			$row['actor'] = hash_equals( (string) $row['actor'], $me ) ? 'you' : ( str_starts_with( (string) $row['actor'], 'h:' ) ? 'other' : 'system' );
			$row['details'] = json_decode( (string) $row['details'], true );
			unset( $row['id'] );
		}
		unset( $row );
		$actions = $filters['cursor'] ? [] : $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT action FROM {$table} WHERE tenant_id = %s AND ts >= %s ORDER BY action", $tenant_id, gmdate( 'Y-m-d H:i:s', $since ) ) );
		return [ 'rows' => $rows, 'actions' => $actions, 'nextCursor' => $cursor ];
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
