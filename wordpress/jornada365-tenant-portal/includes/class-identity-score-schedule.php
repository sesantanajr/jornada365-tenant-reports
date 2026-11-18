<?php
namespace J365TP;

use J365TP\Reports\Identity_Score;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Optional app-only collection. WP-Cron processes one official page per tenant and yields. */
final class Identity_Score_Schedule {
	public static function change( string $tenant, bool $enabled ): bool {
		global $wpdb;
		$table = Installer::table( 'identity_history' );
		$tenant = strtolower( $tenant );
		if ( ! $enabled ) {
			return false !== $wpdb->update( $table, [ 'schedule_key' => '', 'schedule_state' => '', 'schedule_due' => 0, 'schedule_cursor' => null ], [ 'tenant_id' => $tenant ] );
		}
		if ( Settings::per_tenant() || ! in_array( 'identityscore', Settings::active_reports(), true ) ) {
			return false;
		}
		$generation = Identity_History::generation( $tenant );
		$grant = Token_Service::consent_state( $tenant );
		if ( '' === $generation || ! array_intersect( [ 'DirectoryRecommendations.Read.All', 'DirectoryRecommendations.ReadWrite.All' ], $grant['granted'] ?? [] ) ) {
			return false;
		}
		$result = $wpdb->update( $table, [ 'schedule_key' => wp_generate_uuid4(), 'schedule_due' => time(), 'schedule_state' => 'waiting' ],
			[ 'tenant_id' => $tenant, 'generation' => $generation, 'schedule_key' => '' ] );
		if ( false !== $result ) {
			self::wake();
		}
		return false !== $result && Identity_History::status( $tenant )['scheduled'];
	}

	private static function wake(): void {
		if ( ! wp_next_scheduled( 'j365tp_identity_score_work' ) ) {
			wp_schedule_single_event( time() + 60, 'j365tp_identity_score_work' );
		}
	}

	public static function run(): void {
		global $wpdb;
		if ( Settings::per_tenant() || ! in_array( 'identityscore', Settings::active_reports(), true ) ) {
			return;
		}
		$table = Installer::table( 'identity_history' );
		$tenants = Installer::table( 'tenants' );
		$due = $wpdb->get_results( $wpdb->prepare( "SELECT h.* FROM {$table} h INNER JOIN {$tenants} t ON h.tenant_id = t.tenant_id WHERE t.status = 'active' AND h.schedule_due > 0 AND h.schedule_due <= %d AND h.schedule_key <> '' ORDER BY h.schedule_due, h.tenant_id LIMIT 2", time() ), ARRAY_A );
		if ( ! $due ) {
			return;
		}
		$row = $due[0];
		$where = [ 'tenant_id' => $row['tenant_id'], 'generation' => $row['generation'], 'schedule_key' => $row['schedule_key'] ];
		$lease = time() + 300;
		if ( 1 !== $wpdb->update( $table, [ 'schedule_due' => $lease ], $where + [ 'schedule_due' => $row['schedule_due'] ] ) ) {
			return;
		}
		$checkpoint = json_decode( $row['schedule_cursor'] ?? '', true ) ?: [];
		$path = $checkpoint['url'] ?? Identity_Score::HISTORY_PATH;
		$seen = $checkpoint['seen'] ?? [];
		$update = [ 'schedule_state' => 'complete', 'schedule_due' => time() + DAY_IN_SECONDS, 'schedule_cursor' => null ];
		try {
			if ( isset( $checkpoint['url'] ) && ! self::valid_cursor( $path ) ) {
				throw new \UnexpectedValueException();
			}
			$graph = new Graph_Client( $row['tenant_id'] );
			$graph->set_deadline( microtime( true ) + 10 );
			$page = $graph->get( $path );
			if ( ! is_array( $page['value'] ?? null ) || ! array_is_list( $page['value'] ) ) {
				throw new \UnexpectedValueException();
			}
			$days = Identity_Score::normalized_history( array_map( [ Identity_Score::class, 'score_day' ], $page['value'] ) );
			$measured = ! empty( $checkpoint['measured'] ) || (bool) array_filter( $days, static fn( $day ) => null !== $day['percent'] );
			$next = $page['@odata.nextLink'] ?? null;
			if ( null !== $next ) {
				$seen[] = hash( 'sha256', $path );
				if ( ! self::valid_cursor( $next ) || in_array( hash( 'sha256', $next ), $seen, true ) ) {
					throw new \UnexpectedValueException();
				}
				$update = [ 'schedule_state' => 'paging', 'schedule_due' => time() + 30,
					'schedule_cursor' => wp_json_encode( [ 'url' => $next, 'seen' => array_slice( $seen, -16 ), 'measured' => $measured ] ) ];
			} elseif ( ! $measured ) {
				$update['schedule_state'] = 'empty';
			}
			if ( ! Identity_History::collect( $row['tenant_id'], $row['generation'], $days, false, $row['schedule_key'] ) ) {
				throw new \RuntimeException();
			}
		} catch ( Graph_Throttled $e ) {
			$update = [ 'schedule_state' => 'retry', 'schedule_due' => time() + max( 300, $e->retry_after ) ];
		} catch ( \Throwable $e ) {
			$code = (int) $e->getCode();
			$access = in_array( $code, [ 401, 403 ], true );
			$update = [ 'schedule_state' => $access ? 'access' : 'retry', 'schedule_due' => time() + ( $access ? DAY_IN_SECONDS : HOUR_IN_SECONDS ) ];
			if ( $access || in_array( $code, [ 400, 404, 410 ], true ) || $e instanceof \UnexpectedValueException ) {
				$update['schedule_cursor'] = null;
			}
		}
		$wpdb->update( $table, $update, $where + [ 'schedule_due' => $lease ] );
		if ( count( $due ) > 1 || 'paging' === $update['schedule_state'] ) {
			self::wake();
		}
	}

	private static function valid_cursor( $url ): bool {
		if ( ! is_string( $url ) || strlen( $url ) > 8192 ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && 'graph.microsoft.com' === strtolower( $parts['host'] ?? '' )
			&& 443 === ( $parts['port'] ?? 443 ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] )
			&& 1 === preg_match( '~^/beta/directory/recommendations/(?:microsoft\.graph\.)?tenantSecureScores(?:\(\))?/?$~iD', $parts['path'] ?? '' );
	}
}
