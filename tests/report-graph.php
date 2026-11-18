<?php
declare( strict_types=1 );

namespace J365TP {
	final class Token_Service {
		public const GRAPH = 'https://graph.microsoft.com';
		public static function acquire( string $tenant ): string {
			return 'fixture';
		}
	}
	final class Settings {
		public static function per_tenant(): bool {
			return true;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'J365TP_VERSION', 'test' );
	$includes = __DIR__ . '/../wordpress/jornada365-tenant-portal/includes/';
	foreach ( [ 'class-graph-exception.php', 'class-graph-throttled.php', 'class-graph-client.php', 'reports/class-report-base.php', 'reports/class-groups.php', 'reports/class-privileged.php', 'reports/class-usage.php' ] as $file ) {
		require $includes . $file;
	}

	final class Network_Failure {}
	$requests = [];
	$responses = [];

	function assert_report( bool $condition, string $description ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $description );
		}
	}
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
	function wp_json_encode( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR );
	}
	function wp_remote_request( string $url, array $arguments ) {
		global $requests, $responses;
		$requests[] = [ $url, $arguments ];
		if ( ! $responses ) {
			throw new RuntimeException( 'Unexpected Graph call.' );
		}
		return array_shift( $responses );
	}
	function wp_remote_retrieve_response_code( array $response ): int {
		return $response['status'];
	}
	function wp_remote_retrieve_body( array $response ): string {
		return wp_json_encode( $response['body'] ?? [] );
	}
	function wp_remote_retrieve_header( array $response, string $name ): string {
		return $response['headers'][ $name ] ?? '';
	}
	function is_wp_error( $value ): bool {
		return $value instanceof Network_Failure;
	}
	function batch_fixture( array $items ): array {
		return [ 'status' => 200, 'body' => [ 'responses' => $items ] ];
	}
	function requested_ids( array $request ): array {
		return array_column( json_decode( $request[1]['body'], true, 512, JSON_THROW_ON_ERROR )['requests'], 'id' );
	}

	$client = new \J365TP\Graph_Client( 'tenant' );
	$client->set_deadline( microtime( true ) - 1 );
	try {
		$client->get( '/v1.0/users' );
		throw new RuntimeException( 'Expired deadline should defer the read.' );
	} catch ( \J365TP\Graph_Throttled $error ) {
		assert_report( 'budget' === $error->reason && [] === $requests, 'Budget deferral must avoid an HTTP call.' );
	}

	$client->set_deadline( microtime( true ) + 10 );
	$responses[] = [ 'status' => 429, 'headers' => [ 'retry-after' => '120' ] ];
	try {
		$client->get( '/v1.0/users' );
		throw new RuntimeException( 'Graph 429 should defer the read.' );
	} catch ( \J365TP\Graph_Throttled $error ) {
		assert_report( 120 === $error->retry_after && 1 === count( $requests ), 'Read must return the wait after one call.' );
		assert_report( $requests[0][1]['timeout'] <= 10 && 0 === $requests[0][1]['redirection'], 'Graph HTTP must fit its deadline and avoid forwarding credentials.' );
	}

	try {
		$client->get( 'https://elsewhere.example/v1.0/users' );
		throw new RuntimeException( 'Unexpected nextLink origin should be rejected.' );
	} catch ( \J365TP\Graph_Exception $error ) {
		assert_report( 'bad_nextlink' === $error->graph_code() && 1 === count( $requests ), 'Foreign nextLink must never receive the Graph token.' );
	}

	$responses[] = new Network_Failure();
	try {
		$client->post( '/v1.0/deviceManagement/managedDevices/id/rebootNow', [] );
		throw new RuntimeException( 'An ambiguous write must fail explicitly.' );
	} catch ( \J365TP\Graph_Exception $error ) {
		assert_report( 'action_unconfirmed' === $error->graph_code() && 2 === count( $requests ), 'A write after ambiguous transport failure must not be retried.' );
	}

	$groups = new \J365TP\Reports\Groups( 'tenant' );
	$deadline = microtime( true ) + 20;
	$groups->set_deadline( $deadline );
	$group_state = [
		'phase' => 1, 'idx' => 0, '_warnings' => [], 'counts' => [],
		'groups' => [ [ 'id' => 'group-id', 'groupTypes' => [ 'Unified' ] ] ],
	];
	$responses[] = batch_fixture( [
		[ 'id' => '0:all', 'status' => 200, 'body' => 12 ],
		[ 'id' => '0:users', 'status' => 429, 'headers' => [ 'Retry-After' => '25' ] ],
		[ 'id' => '0:owners', 'status' => 200, 'body' => 2 ],
	] );
	$paused = $groups->step( $group_state, [], $deadline );
	assert_report( ! $paused['done'] && $paused['state']['_retry_at'] >= time() + 24, 'Group counts must persist successes and pause after a partial throttle.' );
	$next_state = $paused['state'];
	unset( $next_state['_retry_at'], $next_state['_retry_reason'] );
	$responses[] = batch_fixture( [ [ 'id' => '0:users', 'status' => 200, 'body' => 12 ] ] );
	$done = $groups->step( $next_state, [], $deadline );
	assert_report( [ '0:users' ] === requested_ids( end( $requests ) ), 'Only the throttled subrequest should be sent again.' );
	assert_report( $done['done'] && [ 'all' => 12, 'users' => 12, 'groups' => 0, 'devices' => 0, 'owners' => 2 ] === $done['state']['counts']['group-id'], 'Original and recovered counts must be merged.' );

	$privileged = new \J365TP\Reports\Privileged( 'tenant' );
	$privileged->set_deadline( $deadline );
	$user = '11111111-2222-3333-4444-555555555555';
	$service = '22222222-2222-3333-4444-555555555555';
	$assignment = [ 'principalId' => $user, 'principalType' => 'user' ];
	$responses[] = batch_fixture( [ [ 'id' => $user, 'status' => 200, 'body' => [ 'isMfaRegistered' => false ] ] ] );
	$done = $privileged->step( [
		'phase' => 3, '_warnings' => [],
		'active' => [ $assignment, [ 'principalId' => $service, 'principalType' => 'servicePrincipal' ] ],
		'eligible' => [ $assignment ],
	], [], $deadline );
	assert_report( [ $user ] === requested_ids( end( $requests ) ) && $done['done'], 'MFA lookup should query each on-report user once and skip nonusers.' );
	assert_report( false === $done['state']['mfa'][ $user ], 'A negative MFA status must stay distinct from missing data.' );

	$csv = "\xEF\xBB\xBFUser Principal Name,Dropped,Has Archive\r\n\"a,b@example.test\",unused,Yes\r\n";
	assert_report(
		[ [ 'userPrincipalName' => 'a,b@example.test', 'hasArchive' => 'Yes' ] ] === \J365TP\Graph_Client::parse_csv( $csv, [ 'userPrincipalName', 'hasArchive' ] ),
		'CSV projection must preserve source column indexes, quoted fields, and retained values.'
	);
	$usage = new \J365TP\Reports\Usage( 'tenant' );
	assert_report( [ 'period' => 'D30' ] === $usage->normalize_params( [] ), 'Usage default must remain valid when period is missing.' );
	assert_report( [] === $responses, 'All configured HTTP fixtures must have been used.' );
	echo "Graph budgets, batch retries, scoped MFA, write safety, and CSV projection passed.\n";
}
