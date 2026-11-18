<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Graph_Exception extends \RuntimeException {

	private string $graph_code;

	public function __construct( string $message, int $http_status = 0, string $graph_code = '' ) {
		parent::__construct( $message, $http_status );
		$this->graph_code = $graph_code;
	}

	public function graph_code(): string {
		return $this->graph_code;
	}

	public function is_forbidden(): bool {
		return 403 === $this->getCode();
	}

	/** Entra ID P1/P2 feature gate (signInActivity, etc.). */
	public function is_license_gate(): bool {
		return in_array( $this->graph_code, [ 'Authentication_RequestFromNonPremiumTenantOrB2CTenant', 'Request_UnsupportedQuery' ], true )
			|| str_contains( $this->getMessage(), 'premium' );
	}
}
