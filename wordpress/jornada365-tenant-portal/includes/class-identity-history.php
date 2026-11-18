<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** An existing row is the tenant's opt-in. A job may only update the generation it started in. */
final class Identity_History {
	private static function row( string $tenant ): ?array {
		global $wpdb;
		$table = Installer::table( 'identity_history' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s", strtolower( $tenant ) ), ARRAY_A ) ?: null;
	}

	public static function generation( string $tenant ): string {
		return self::row( $tenant )['generation'] ?? '';
	}

	private static function cutoff(): string {
		return ( new \DateTimeImmutable( gmdate( 'Y-m-01' ), new \DateTimeZone( 'UTC' ) ) )->modify( '-23 months' )->format( 'Y-m' );
	}

	/** Keep only canonical UTC rows produced by Identity_Score::finalize, projected to three fields. */
	private static function closings( array $rows ): array {
		$months = [];
		$cutoff = self::cutoff();
		$now = gmdate( 'Y-m-d\TH:i:s.999\Z' );
		foreach ( $rows as $item ) {
			$date = $item['date'] ?? '';
			$month = substr( $date, 0, 7 );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $date ) || $month < $cutoff || $date > $now ) {
				continue;
			}
			$current = $item['current'] ?? null;
			$max = $item['max'] ?? null;
			if ( ! is_numeric( $current ) || ! is_numeric( $max ) || $current < 0 || $max <= 0 || $current > $max || (float) (int) $current !== (float) $current || (float) (int) $max !== (float) $max ) {
				continue;
			}
			if ( ! isset( $months[ $month ] ) || $months[ $month ]['date'] <= $date ) {
				$months[ $month ] = [ 'date' => $date, 'current' => (int) $current, 'max' => (int) $max ];
			}
		}
		ksort( $months );
		return $months;
	}

	/** Expose scores only alongside a completed, authorized Graph report. */
	public static function status( string $tenant, string $report_generation = '' ): array {
		$row = self::row( $tenant );
		$status = [ 'enabled' => (bool) $row, 'collectedAt' => $row['collected_at'] ?? '' ];
		$status['scheduleAvailable'] = ! Settings::per_tenant();
		$status['scheduled'] = ! empty( $row['schedule_key'] );
		$status['scheduleState'] = $row['schedule_state'] ?? '';
		if ( '' !== $report_generation && $row && $report_generation === $row['generation'] ) {
			$status['months'] = array_values( self::closings( json_decode( $row['months'] ?? '[]', true ) ?: [] ) );
		}
		return $status;
	}

	public static function enable( string $tenant ): bool {
		global $wpdb;
		$tenant = strtolower( $tenant );
		$table = Installer::table( 'identity_history' );
		$tenants = Installer::table( 'tenants' );
		// The parent is checked in the same statement to avoid resurrecting a deleted/revoked tenant.
		$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (tenant_id, generation, revision, months)
			SELECT tenant_id, %s, %s, %s FROM {$tenants} WHERE tenant_id = %s AND status = 'active'", wp_generate_uuid4(), wp_generate_uuid4(), '[]', $tenant ) );
		return false !== $ok && '' !== self::generation( $tenant );
	}

	public static function disable( string $tenant ): bool {
		global $wpdb;
		return false !== $wpdb->delete( Installer::table( 'identity_history' ), [ 'tenant_id' => strtolower( $tenant ) ] );
	}

	/** Merge concurrently completed reports without losing newer observations or restoring revoked data. */
	public static function collect( string $tenant, string $generation, array $rows, bool $maintenance = false, string $schedule_key = '' ): bool {
		global $wpdb;
		if ( '' === $generation ) {
			return true;
		}
		$table = Installer::table( 'identity_history' );
		$incoming = self::closings( $rows );
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$row = self::row( $tenant );
			if ( ! $row || $row['generation'] !== $generation || ( '' !== $schedule_key && $schedule_key !== $row['schedule_key'] ) ) {
				return true;
			}
			$stored = json_decode( $row['months'], true ) ?: [];
			$merged = self::closings( array_merge( array_values( $stored ), array_values( $incoming ) ) );
			$months = wp_json_encode( array_values( $merged ) );
			$collected = $maintenance ? $row['collected_at'] : max( $row['collected_at'], gmdate( 'Y-m-d\TH:i:s\Z' ) );
			if ( $row['months'] === $months && $collected === $row['collected_at'] ) {
				return true;
			}
			$where = [ 'tenant_id' => strtolower( $tenant ), 'generation' => $generation, 'revision' => $row['revision'] ];
			if ( '' !== $schedule_key ) {
				$where['schedule_key'] = $schedule_key;
			}
			$updated = $wpdb->update( $table, [ 'months' => $months, 'oldest' => array_key_first( $merged ) ?? '', 'collected_at' => $collected, 'revision' => wp_generate_uuid4() ], $where );
			if ( 1 === $updated || false === $updated ) {
				return false !== $updated;
			}
		}
		return false;
	}

	public static function purge_expired(): void {
		global $wpdb;
		$table = Installer::table( 'identity_history' );
		$due = $wpdb->get_results( $wpdb->prepare( "SELECT tenant_id, generation FROM {$table} WHERE oldest <> '' AND oldest < %s ORDER BY oldest, tenant_id LIMIT 100", self::cutoff() ), ARRAY_A );
		foreach ( $due ?: [] as $row ) {
			self::collect( $row['tenant_id'], $row['generation'], [], true );
		}
	}
}
