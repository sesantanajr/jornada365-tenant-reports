<?php
/** Exercise managed installations with database-backed salts and existing configured keys. */
define( 'ABSPATH', __DIR__ );
define( 'AUTH_KEY', 'put your unique phrase here' );
define( 'LOGGED_IN_KEY', 'frase de exemplo' );
require __DIR__ . '/../wordpress/jornada365-tenant-portal/includes/class-crypto.php';

function __( string $text ): string {
	return 'put your unique phrase here' === $text ? 'frase de exemplo' : $text;
}

function wp_salt( string $scheme ): string {
	if ( 'auth' !== $scheme ) {
		throw new RuntimeException( 'Expected the WordPress authentication salt.' );
	}
	return $GLOBALS['site_salt'];
}

$site_salt = random_bytes( 64 );
$first     = \J365TP\Crypto::subkey( 'session' );
if ( ! hash_equals( $first, \J365TP\Crypto::subkey( 'session' ) ) ) {
	throw new RuntimeException( 'The same installation must keep its session key.' );
}
$site_salt = random_bytes( 64 );
if ( hash_equals( $first, \J365TP\Crypto::subkey( 'session' ) ) ) {
	throw new RuntimeException( 'Sites with independent WordPress salts must use different keys.' );
}

define( 'SECURE_AUTH_KEY', bin2hex( random_bytes( 32 ) ) );
$existing = hash( 'sha256', 'j365tp|' . AUTH_KEY . '|' . SECURE_AUTH_KEY . '|' . LOGGED_IN_KEY, true );
if ( ! hash_equals( hash_hmac( 'sha256', 'j365tp-subkey|session', $existing, true ), \J365TP\Crypto::subkey( 'session' ) ) ) {
	throw new RuntimeException( 'Existing explicitly configured keys must remain compatible.' );
}
echo "Installation key checks passed.\n";
