<?php
declare(strict_types=1);

namespace J365TP;

// Standalone test of ID-token verification. The JWKS transport and cache never access the network.
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

function get_transient( string $key ): array {
	return $GLOBALS['jwks_cache'];
}

function set_transient( string $key, array $keys, int $ttl ): void {
	$GLOBALS['jwks_cache'] = $keys;
}

function wp_remote_get( string $url, array $options ): array {
	if ( 'https://login.microsoftonline.com/organizations/discovery/v2.0/keys' !== $url ) {
		throw new \LogicException( 'Unexpected JWKS discovery URL.' );
	}
	++$GLOBALS['jwks_requests'];
	return [ 'body' => json_encode( [ 'keys' => $GLOBALS['jwks_remote'] ], JSON_THROW_ON_ERROR ) ];
}

function is_wp_error( $response ): bool {
	return false;
}

function wp_remote_retrieve_body( array $response ): string {
	return $response['body'];
}

require dirname( __DIR__ ) . '/wordpress/jornada365-tenant-portal/includes/class-jwt.php';

$base64url = static fn( string $bytes ): string => rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
$private = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
if ( ! $private ) {
	throw new \LogicException( 'OpenSSL could not create a test key.' );
}
$rsa = openssl_pkey_get_details( $private )['rsa'];
$key = [ 'kid' => 'test-key', 'n' => $base64url( $rsa['n'] ), 'e' => $base64url( $rsa['e'] ) ];
$tenant = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
$claims = [
	'tid'   => $tenant,
	'iss'   => $issuer,
	'oid'   => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
	'aud'   => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
	'nonce' => 'unit-test-nonce',
	'exp'   => time() + 600,
];
$header = $base64url( json_encode( [ 'alg' => 'RS256', 'kid' => $key['kid'] ], JSON_THROW_ON_ERROR ) );
$sign = static function ( array $token_claims ) use ( $base64url, $header, $private ): string {
	$payload = $header . '.' . $base64url( json_encode( $token_claims, JSON_THROW_ON_ERROR ) );
	if ( ! openssl_sign( $payload, $signature, $private, OPENSSL_ALGO_SHA256 ) ) {
		throw new \LogicException( 'OpenSSL could not sign the test token.' );
	}
	return $payload . '.' . $base64url( $signature );
};

$verify = static fn( string $jwt ): array => Jwt::verify_id_token( $jwt, $claims['aud'], $claims['nonce'] )['claims'];
$scenarios = [
	'template issuer' => [ 'https://login.microsoftonline.com/{tenantid}/v2.0', true ],
	'matching tenant issuer' => [ $issuer, true ],
	'other tenant issuer' => [ 'https://login.microsoftonline.com/dddddddd-dddd-4ddd-8ddd-dddddddddddd/v2.0', false ],
	'missing issuer' => [ null, false ],
];
foreach ( $scenarios as $label => [ $key_issuer, $expected ] ) {
	$record = null === $key_issuer ? $key : $key + [ 'issuer' => $key_issuer ];
	$GLOBALS['jwks_cache'] = [ $record ];
	$GLOBALS['jwks_remote'] = [ $record ];
	$GLOBALS['jwks_requests'] = 0;
	try {
		$accepted = $claims === $verify( $sign( $claims ) );
	} catch ( \RuntimeException $e ) {
		$accepted = false;
	}
	if ( $accepted !== $expected ) {
		throw new \LogicException( 'Unexpected verification result for ' . $label );
	}
}

// A new key still triggers one refresh when the cache has a different key.
$GLOBALS['jwks_cache'] = [ [ 'kid' => 'old-key', 'issuer' => $issuer ] ];
$GLOBALS['jwks_remote'] = [ $key + [ 'issuer' => $issuer ] ];
$GLOBALS['jwks_requests'] = 0;
if ( $claims !== $verify( $sign( $claims ) ) || 1 !== $GLOBALS['jwks_requests'] ) {
	throw new \LogicException( 'Verification after signing key rollover failed.' );
}

echo "JWT signing-key issuer checks passed.\n";
