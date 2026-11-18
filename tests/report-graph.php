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
	foreach ( [ 'class-graph-exception.php', 'class-graph-throttled.php', 'class-graph-client.php', 'class-sku-catalog.php', 'reports/class-report-base.php', 'reports/class-groups.php', 'reports/class-privileged.php', 'reports/class-usage.php', 'reports/class-conditional-access.php', 'reports/class-secure-score.php', 'reports/class-auth-policies.php', 'reports/class-licensing.php', 'reports/class-apps.php' ] as $file ) {
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
		return $response['raw'] ?? wp_json_encode( $response['body'] ?? [] );
	}
	function wp_remote_get( string $url, array $arguments ) {
		return wp_remote_request( $url, $arguments );
	}
	function wp_safe_remote_get( string $url, array $arguments ) {
		return wp_remote_request( $url, $arguments );
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
	$responses[] = batch_fixture( [ [ 'id' => '0:users', 'status' => 502 ] ] );
	$paused = $groups->step( $next_state, [], $deadline );
	assert_report( ! $paused['done'] && 'network' === $paused['state']['_retry_reason'] && [ '0:users' ] === requested_ids( end( $requests ) ), 'A single 502 inside a batch must preserve successes and retry just that request.' );
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

	// A transient error in the first request must leave the SAME job resumable.
	foreach ( [ 408, 500, 502 ] as $status ) {
		$responses[] = [ 'status' => $status, 'headers' => [ 'retry-after' => '9' ] ];
		try {
			$client->get( '/v1.0/users' );
			throw new RuntimeException( "Expected HTTP $status to defer the read." );
		} catch ( \J365TP\Graph_Throttled $error ) {
			assert_report( 'network' === $error->reason && 9 === $error->retry_after, 'Temporary HTTP failures must preserve the requested delay.' );
		}
	}
	foreach ( [ '', '{"value":[', '<html>gateway unavailable</html>' ] as $raw ) {
		$responses[] = [ 'status' => 200, 'raw' => $raw ];
		try {
			$client->get( '/v1.0/users' );
			throw new RuntimeException( 'Invalid successful HTTP response must not be presented as a tenant with zero records.' );
		} catch ( \J365TP\Graph_Throttled $error ) {
			assert_report( 'network' === $error->reason, 'Incomplete Graph responses should be retried as a read.' );
		}
	}
	$responses[] = [ 'status' => 200, 'raw' => '0' ];
	assert_report( [ 'value' => 0 ] === $client->get( '/v1.0/users/$count' ), 'Zero remains a valid Graph count.' );
	$responses[] = [ 'status' => 302, 'headers' => [ 'location' => 'https://downloads.example.test/report' ] ];
	$responses[] = [ 'status' => 502, 'headers' => [ 'retry-after' => '7' ] ];
	try {
		$client->get_report_csv( '/v1.0/reports/fixture' );
		throw new RuntimeException( 'Temporary CSV download failure must defer.' );
	} catch ( \J365TP\Graph_Throttled $error ) {
		assert_report( 7 === $error->retry_after && ! isset( end( $requests )[1]['headers']['Authorization'] ), 'Retry the CSV without disclosing the Graph credential to the download host.' );
	}
	$responses[] = [ 'status' => 200, 'raw' => "<html>gateway unavailable</html>\n" ];
	try {
		$client->get_report_csv( '/v1.0/reports/fixture', [ 'userPrincipalName' ] );
		throw new RuntimeException( 'HTML must not be accepted as an empty usage report.' );
	} catch ( \J365TP\Graph_Throttled $error ) {
		assert_report( 'network' === $error->reason, 'Missing CSV headers should defer the download.' );
	}
	$responses[] = [ 'status' => 200, 'raw' => "User Principal Name,Display Name\r\n" ];
	assert_report( [] === $client->get_report_csv( '/v1.0/reports/fixture', [ 'userPrincipalName' ] ), 'A valid header-only report represents zero records.' );

	$next = 'https://graph.microsoft.com/v1.0/fixture/cursor';
	$page = static fn( array $rows, ?string $cursor = null ) => [ 'status' => 200, 'body' => [ 'value' => $rows ] + ( $cursor ? [ '@odata.nextLink' => $cursor ] : [] ) ];
	$deadline = microtime( true ) + 20;
	require $includes . 'reports/class-domains.php';
	$domains = new \J365TP\Reports\Domains( 'tenant' );
	$domains->set_deadline( $deadline );
	$responses = [ $page( [ [ 'id' => 'a.example.invalid', 'isDefault' => true ] ], $next ), [ 'status' => 502 ] ];
	$paused = $domains->step( $domains->initial_state( [] ), [], $deadline );
	assert_report( ! $paused['done'] && 1 === $paused['progress'], 'Domains must retain the first page after a temporary failure.' );
	$responses = [ $page( [ [ 'id' => 'b.example.invalid' ] ] ) ];
	$done = $domains->step( $paused['state'], [], $deadline );
	$data = $domains->finalize( $done['state'], [] );
	assert_report( $done['done'] && $next === end( $requests )[0] && 2 === $data['summary']['total'] && [ 'a.example.invalid', 'b.example.invalid' ] === array_column( $data['rows'], 'name' ), 'Domain pagination must resume without losing or duplicating a row.' );

	$ca = new \J365TP\Reports\Conditional_Access( 'tenant' );
	$ca->set_deadline( $deadline );
	$responses = [ [ 'status' => 200, 'body' => [ 'unexpected' => true ] ] ];
	$invalid = $ca->step( $ca->initial_state( [] ), [], $deadline );
	assert_report( ! $invalid['done'] && 'network' === $invalid['state']['_retry_reason'] && ! isset( $invalid['state']['policies_done'] ), 'A malformed collection must not mark tenant policies as empty.' );
	$app_ids = array_map( static fn( $i ) => sprintf( '99999999-0000-0000-0000-%012d', $i ), range( 1, 16 ) );
	$pol = [ 'id' => 'pol', 'conditions' => [ 'applications' => [ 'includeApplications' => $app_ids ] ] ];
	$responses = [ $page( [ $pol ], $next ), [ 'status' => 502 ] ];
	$paused = $ca->step( $ca->initial_state( [] ), [], $deadline );
	assert_report( ! $paused['done'] && $next === $paused['state']['policies_next'], 'Interrupted policy lists retain their cursor.' );
	$before = count( $requests );
	$responses = [ $page( [] ), $page( [] ), $page( [] ), $page( [] ), $page( [ [ 'appId' => $app_ids[0], 'displayName' => 'First app' ] ] ), [ 'status' => 500 ] ];
	$paused = $ca->step( $paused['state'], [], $deadline );
	assert_report( $next === $requests[ $before ][0] && ! $paused['done'] && 15 === $paused['state']['app_idx'], 'A retry must continue the policy page and retain the first 15 app lookups.' );
	$responses = [ $page( [ [ 'appId' => $app_ids[15], 'displayName' => 'Last app' ] ] ) ];
	$done = $ca->step( $paused['state'], [], $deadline );
	$apps = $ca->finalize( $done['state'], [] )['rows'][0]['includeApps'];
	assert_report( $done['done'] && 'First app' === $apps[0] && 'Last app' === $apps[15], 'Resumed CA mappings include both sets of app names.' );

	$score = new \J365TP\Reports\Secure_Score( 'tenant' );
	$score->set_deadline( $deadline );
	$responses = [ $page( [ [ 'currentScore' => 20 ] ] ), $page( [ [ 'id' => 'first' ] ], $next ), [ 'status' => 500 ] ];
	$paused = $score->step( $score->initial_state( [] ), [], $deadline );
	assert_report( ! $paused['done'] && 1 === count( $paused['state']['profile_rows'] ), 'Score and the first profile page survive a cold gateway failure.' );
	$responses = [ $page( [ [ 'id' => 'second' ] ] ) ];
	$done = $score->step( $paused['state'], [], $deadline );
	assert_report( $next === end( $requests )[0] && [ 'first', 'second' ] === array_keys( $done['state']['profiles'] ), 'Secure Score must resume the outstanding profile page only.' );

	$auth = new \J365TP\Reports\Auth_Policies( 'tenant' );
	$auth->set_deadline( $deadline );
	$responses = [ [ 'status' => 200, 'body' => [ 'isEnabled' => true ] ], [ 'status' => 200, 'body' => [ 'authenticationMethodConfigurations' => [ [ 'id' => 'Fido2', 'includeTargets' => [ [ 'id' => $user, 'targetType' => 'group' ] ] ] ] ] ], $page( [ [ 'id' => $user, 'displayName' => 'Team' ] ] ), [ 'status' => 200, 'body' => [ 'allowedToUseSSPR' => true ] ], $page( [], $next ), [ 'status' => 502 ] ];
	$state = $auth->initial_state( [] );
	for ( $i = 0; $i < 5; $i++ ) {
		$paused = $auth->step( $state, [], $deadline );
		$state = $paused['state'];
	}
	assert_report( ! $paused['done'] && $next === $state['legacy_blocks_next'], 'Authentication policy stages must checkpoint before a legacy-policy page fails.' );
	$responses = [ $page( [ [ 'state' => 'enabled', 'conditions' => [ 'clientAppTypes' => [ 'other' ] ], 'grantControls' => [ 'builtInControls' => [ 'block' ] ] ] ] ) ];
	$done = $auth->step( $state, [], $deadline );
	$posture = $auth->finalize( $done['state'], [] );
	assert_report( $next === end( $requests )[0] && $posture['legacyAuthBlocked'] && $posture['securityDefaults']['enabled'] && [ 'Grupo: Team' ] === $posture['methods'][0]['targets'] && $posture['authorization']['allowedToUseSSPR'], 'Authentication posture must retain all previous phases.' );

	$licenses = new \J365TP\Reports\Licensing( 'tenant' );
	$licenses->set_deadline( $deadline );
	$responses = [ $page( [ [ 'skuId' => 'first', 'skuPartNumber' => 'E1' ] ], $next ), [ 'status' => 500 ] ];
	$paused = $licenses->step( $licenses->initial_state( [] ), [], $deadline );
	assert_report( ! $paused['done'] && $next === $paused['state']['sku_pages_next'], 'License pagination must checkpoint early successes.' );
	$responses = [ $page( [ [ 'skuId' => 'second', 'skuPartNumber' => 'E2' ] ] ), $page( [] ) ];
	$done = $licenses->step( $paused['state'], [], $deadline );
	assert_report( $done['done'] && [ 'first', 'second' ] === array_keys( $done['state']['skus'] ), 'Resumed licensing report must retain both SKUs.' );

	$inventory = new \J365TP\Reports\Apps( 'tenant' );
	$inventory->set_deadline( $deadline );
	$responses = [ $page( [] ), [ 'status' => 401 ] ];
	try {
		$inventory->step( $inventory->initial_state( [] ), [], $deadline );
		throw new RuntimeException( 'Optional Intune data incorrectly consumed a token renewal.' );
	} catch ( \J365TP\Graph_Exception $error ) {
		assert_report( 'j365_token' === $error->graph_code(), 'Token renewal must propagate even when fetching optional data.' );
	}
	assert_report( [] === $responses, 'All configured HTTP fixtures must have been used.' );
	echo "Graph retries, page checkpoints, auth renewal, scoped MFA, write safety, and CSV projection passed.\n";
}
