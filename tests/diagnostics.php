<?php
/** Verify the capability used by the classic operator diagnostics REST route. */
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

final class WP_Error {
	public function __construct( public string $code, public string $message, public array $data ) {}
}

function is_user_logged_in(): bool {
	return $GLOBALS['logged_in'];
}

function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability && $GLOBALS['can_manage'];
}

require __DIR__ . '/../wordpress/jornada365-tenant-portal/includes/class-bootstrap.php';
require __DIR__ . '/../wordpress/jornada365-tenant-portal/includes/class-diagnostics.php';

// A REST request can carry arbitrary headers. WordPress supplies the authenticated user.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fixture';
foreach ( [ [ false, false ], [ false, true ], [ true, false ], [ true, true ] ] as [ $logged_in, $can_manage ] ) {
	$result = \J365TP\Diagnostics::permission();
	if ( $logged_in && $can_manage ) {
		if ( true !== $result ) {
			throw new RuntimeException( 'A signed-in administrator must be allowed.' );
		}
	} elseif ( ! $result instanceof WP_Error || 403 !== $result->data['status'] ) {
		throw new RuntimeException( 'The diagnostics route requires an authenticated administrator.' );
	}
}
echo "Diagnostics capability checks passed.\n";
