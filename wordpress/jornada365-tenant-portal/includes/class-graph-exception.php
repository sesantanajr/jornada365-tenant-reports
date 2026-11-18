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

	public function resource_missing(): bool {
		return 404 === $this->getCode() || ( 400 === $this->getCode()
			&& in_array( strtolower( $this->graph_code ), [ 'resourcenotfound', 'request_resourcenotfound', 'itemnotfound' ], true ) );
	}

	/** Safe, stable context for report failures. A generic 403 does not identify the missing prerequisite. */
	public function report_issue(): array {
		$status = $this->getCode();
		$kind = match ( true ) {
			'host_budget' === $this->graph_code => 'host_limit',
			'Authentication_RequestFromNonPremiumTenantOrB2CTenant' === $this->graph_code => 'license',
			403 === $status => 'access',
			401 === $status => 'authorization',
			$this->resource_missing() => 'unavailable',
			in_array( $status, [ 408, 429, 500, 502, 503, 504 ], true ) || 'network' === $this->graph_code => 'temporary',
			default => 'request',
		};
		$code = preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/D', $this->graph_code ) ? $this->graph_code : '';
		return [ 'kind' => $kind, 'status' => $status, 'code' => $code ];
	}

	/** Entra ID P1/P2 feature gate (signInActivity, etc.). */
	public function is_license_gate(): bool {
		return in_array( $this->graph_code, [ 'Authentication_RequestFromNonPremiumTenantOrB2CTenant', 'Request_UnsupportedQuery' ], true )
			|| str_contains( $this->getMessage(), 'premium' );
	}
}
