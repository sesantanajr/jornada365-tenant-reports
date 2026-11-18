<?php
declare(strict_types=1);

namespace J365TP;

// Offline directory-policy regression. WordPress I/O and upstream token validation are isolated.
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'YEAR_IN_SECONDS', 31536000 );

final class Test_Request {
	public function __construct( private array $data = [] ) {}
	public function get_param( string $key ) { return $this->data[ $key ] ?? null; }
	public function get_json_params(): array { return $this->data; }
}

final class Test_Response {
	public function __construct( public array $data, public int $status ) {}
	public function header( string $key, string $value ): void {}
}

class_alias( Test_Request::class, 'WP_REST_Request' );
class_alias( Test_Response::class, 'WP_REST_Response' );

function get_option( string $key, $default ) { return $GLOBALS['test_settings'] ?? $default; }
function apply_filters( string $hook, $value ) { return $GLOBALS['test_per_tenant'] ?? $value; }
function wp_remote_get( string $url ): never { throw new \LogicException( 'Unexpected discovery request: ' . $url ); }
function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
function sanitize_email( string $email ): string { return $email; }
function esc_url_raw( string $url ): string { return $url; }
function absint( int $value ): int { return abs( $value ); }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function mb_substr( string $value, int $start, int $length ): string { return substr( $value, $start, $length ); }
function add_settings_error( string $setting, string $code, string $message ): void { $GLOBALS['test_error'] = $code; }
function get_transient( string $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
function set_transient( string $key, $value, int $ttl ): void { $GLOBALS['test_transients'][ $key ] = $value; }
function delete_transient( string $key ): void { unset( $GLOBALS['test_transients'][ $key ] ); }
function add_query_arg( string $key, string $value, string $uri ): string { return $uri . '?' . http_build_query( [ $key => $value ] ); }
function wp_json_encode( array $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR ); }

final class Request {
	public static function route_url( string $route ): string { return 'https://test.invalid/' . $route; }
}

final class Tenant_Repository {
	public static function get( string $id ): array { return [ 'status' => 'active', 'client_id' => 'configured-client' ]; }
	public static function client_id( array $tenant ): string { return $tenant['client_id']; }
}

final class Rate_Limiter {
	public static function allow( string $action, int $limit, int $ttl ): bool { return true; }
}

final class Crypto {
	public static function open( string $raw, string $key ): string { return $GLOBALS['test_payload'] ?? $raw; }
	public static function seal( string $value, string $key ): string { return $value; }
	public static function subkey( string $key ): string { return $key; }
	public static function random_token( int $bytes ): string { return bin2hex( random_bytes( $bytes ) ); }
}

final class Jwt {
	public static function verify_id_token( string $jwt, string $client, string $nonce ): array {
		return [ 'claims' => [ 'tid' => $GLOBALS['test_other'], 'oid' => 'test-oid' ] ];
	}
}

final class Audit {
	public static function log( string $event, string $tenant, string $actor, array $detail = [] ): void {}
}

final class Token_Service {
	public const AUTHORITY = 'https://login.microsoftonline.com';
}

final class Bootstrap {
	public const CLI_CLIENT = 'test-cli';
	public const BROWSER_REDIRECT = 'http://localhost';
}

final class I18n {
	public static function ms_locale(): string { return 'pt-BR'; }
}

foreach ( [ 'settings', 'spa-auth', 'session', 'onboarding' ] as $file ) {
	require dirname( __DIR__ ) . '/wordpress/jornada365-tenant-portal/includes/class-' . $file . '.php';
}

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

$company = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$other   = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$GLOBALS['test_other'] = $other;

expect( Settings::tenant_allowed( $other ), 'Unconfigured instances must keep the public multi-tenant mode.' );
expect( 'invalid' === Spa_Auth::rest_resolve( new Test_Request() )->data['code'], 'Public discovery should still request the viewer’s email or domain.' );
$GLOBALS['test_settings'] = [ 'allowed_tenants' => $other ];
$GLOBALS['test_settings'] = ( new Settings() )->sanitize( [ 'company_tenant_id' => strtoupper( $company ), 'allowed_tenants' => $other ] );
expect( [ $company ] === Settings::allowed_tenants(), 'Company mode must admit exactly the configured directory.' );
expect( $company === ( new Settings() )->sanitize( [ 'company_tenant_id' => 'invalid' ] )['company_tenant_id'], 'Invalid GUID must preserve the previous restriction.' );
expect( 'company_tenant_id' === $GLOBALS['test_error'], 'Administrator should be told the GUID was invalid.' );

$resolved = Spa_Auth::rest_resolve( new Test_Request() );
expect( $resolved->data['ok'] && $resolved->data['connected'] && $company === $resolved->data['tenantId'], 'Company sign-in should resolve its own directory without discovery.' );
$rejected = Spa_Auth::rest_resolve( new Test_Request( [ 'q' => $other ] ) );
expect( 'tenant_not_allowed' === $rejected->data['code'], 'An explicit different directory must be rejected.' );

$_COOKIE[ Spa_Auth::BIND_COOKIE ] = 'test-bind';
$rejected = Spa_Auth::rest_session( new Test_Request( [ 'id_token' => 'test-verified-token', 'client_id' => $company ] ) );
expect( 'tenant_not_allowed' === $rejected->data['problem'], 'Sign-in must check the verified tenant claim, not the client-supplied application ID.' );

$now = time();
$GLOBALS['test_payload'] = wp_json_encode( [ 'v' => 2, 'tid' => $other, 'k' => 'fixture', 'seen' => $now, 'exp' => $now + 600, 'ua' => substr( hash( 'sha256', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 16 ) ] );
$_COOKIE[ Session::COOKIE ] = str_repeat( 's', 40 );
expect( null === Session::current() && ! isset( $_COOKIE[ Session::COOKIE ] ), 'A previously issued external session must be refused and cleared.' );
unset( $GLOBALS['test_payload'] );

$onboarding = Onboarding::rest_start( new Test_Request( [ 'method' => 'browser' ] ) );
expect( str_starts_with( $onboarding->data['authorizeUrl'], Token_Service::AUTHORITY . '/' . $company . '/oauth2/' ), 'Onboarding should direct the operator to the company directory.' );
expect( $company === Onboarding::state()['directory'], 'The code exchange should retain the same authority for the in-progress onboarding.' );
$GLOBALS['test_payload'] = wp_json_encode( [ 'tid' => $other, 'token' => 'previously-authorized', 'token_exp' => $now + 600 ] );
$rejected = Onboarding::rest_step();
expect( 403 === $rejected->status && 'tenant_not_allowed' === $rejected->data['code'], 'External onboarding must stop if a previous authorization is no longer permitted.' );

$GLOBALS['test_settings'] = ( new Settings() )->sanitize( [ 'company_tenant_id' => '', 'allowed_tenants' => $other ] );
expect( [ $other ] === Settings::allowed_tenants(), 'Clearing the company directory must restore the ordinary allowlist.' );

fwrite( STDOUT, "Corporate login policy tests passed.\n" );
