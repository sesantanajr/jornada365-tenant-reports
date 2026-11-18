<?php
declare( strict_types=1 );

namespace J365TP {
	final class Settings { public static function active_reports(): array { return [ 'overview' ]; } }
	final class Installer { public static function table( string $name ): string { return $name; } }
	final class Report_Cache { public static function encode( array $data ): string { return json_encode( $data, JSON_THROW_ON_ERROR ); } }
	final class Crypto { public static function seal( string $data, string $key ): string { return 'fixture'; } }
	final class Audit {
		public static int $started = 0;
		public static function log(): void { ++self::$started; }
	}
}
namespace J365TP\Reports {
	final class Overview {
		public function normalize_params( array $params ): array { return $params; }
		public function initial_state( array $params ): array { return []; }
	}
}
namespace {
	use J365TP\Audit;
	use J365TP\Jobs;
	define( 'ABSPATH', __DIR__ );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'MINUTE_IN_SECONDS', 60 );
	require __DIR__ . '/../wordpress/jornada365-tenant-portal/includes/class-graph-exception.php';
	require __DIR__ . '/../wordpress/jornada365-tenant-portal/includes/class-jobs.php';

	function wp_is_uuid( string $id, int $version ): bool {
		return 4 === $version && 1 === preg_match( '/^[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}$/iD', $id );
	}
	function wp_json_encode( array $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }
	function wp_generate_uuid4(): string { return 'aaaaaaaa-aaaa-4aaa-aaaa-' . bin2hex( random_bytes( 6 ) ); }
	function expect( bool $value, string $name ): void {
		if ( ! $value ) { throw new \RuntimeException( $name ); }
	}

	// Minimal database harness for lost ACKs, duplicate-key insert races and the session job cap.
	$wpdb = new class() {
		public array $rows = [];
		public bool $race = false;
		public bool $suppressed = false;
		public function prepare( string $sql, ...$args ): array { return $args; }
		public function query( array $prepared ): int { return 0; }
		public function get_var( array $args ): int {
			return count( array_filter( $this->rows, static fn( $r ) => $args[0] === $r['user_oid'] && in_array( $r['status'], [ 'running', 'pending' ], true ) ) );
		}
		public function get_row( array $args, string $mode ): ?array {
			$row = $this->rows[ $args[0] ] ?? null;
			return $row && $row['tenant_id'] === $args[1] && $row['user_oid'] === $args[2] ? $row : null;
		}
		public function suppress_errors( bool $flag ): bool {
			$previous = $this->suppressed;
			$this->suppressed = $flag;
			return $previous;
		}
		public function insert( string $table, array $row ) {
			$this->rows[ $row['job_id'] ] = $row;
			if ( $this->race ) { $this->race = false; return false; }
			return 1;
		}
	};
	$session = [ 'tenant_id' => 'a', 'owner' => 'test', 'upn' => 'fixture', 'data_key' => random_bytes( 32 ) ];
	$id = wp_generate_uuid4();
	$first = Jobs::start( $session, 'overview', [], false, $id )['job'];
	$wpdb->rows[ $first['job_id'] ]['status'] = 'running';
	$wpdb->rows[ $first['job_id'] ]['progress'] = 7;
	$resumed = Jobs::start( $session, 'overview', [], false, $id )['job'];
	expect( $first['job_id'] === $resumed['job_id'] && 7 === $resumed['progress'] && 1 === Audit::$started, 'Retry should resume the existing job without creating or auditing a second one.' );
	$other = array_merge( $session, [ 'owner' => 'other', 'data_key' => random_bytes( 32 ) ] );
	expect( Jobs::start( $other, 'overview', [], false, $id )['job']['job_id'] !== $first['job_id'], 'Sessions must stay isolated.' );
	expect( Jobs::start( $session, 'overview', [ 'period' => 'D30' ], false, $id )['job']['job_id'] !== $first['job_id'], 'Normalized query parameters must participate in the job ID.' );
	$wpdb->race = true;
	$raced = Jobs::start( $session, 'overview', [], false, wp_generate_uuid4() )['job'];
	expect( 'pending' === $raced['status'] && ! $wpdb->suppressed && 3 === Audit::$started, 'Concurrent insert should reuse the winner and restore database diagnostics.' );
	try {
		Jobs::start( $session, 'overview', [], false, 'invalid' );
		throw new \RuntimeException( 'Invalid UUID accepted.' );
	} catch ( \InvalidArgumentException $expected ) {}
	while ( $wpdb->get_var( [ 'test' ] ) < Jobs::MAX_ACTIVE ) { Jobs::start( $session, 'overview', [] ); }
	try {
		Jobs::start( $session, 'overview', [], false, wp_generate_uuid4() );
		throw new \RuntimeException( 'Expected a capacity error.' );
	} catch ( \J365TP\Graph_Exception $e ) { expect( 429 === $e->getCode(), 'Capacity must return a retryable response.' ); }
	expect( Jobs::start( $session, 'overview', [], false, $id )['job'] === $resumed, 'Full capacity must keep existing work available.' );
	echo "Report start checks passed (lost ACK, concurrent insert, input validation, isolation, job capacity).\n";
}
