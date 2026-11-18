<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An HTTP read should resume in a later job request. This stays separate from Graph_Exception so
 * that optional report lookups cannot accidentally turn a deferred read into missing data.
 */
final class Graph_Throttled extends \RuntimeException {

	public int $retry_after;
	public string $reason;

	public function __construct( string $message, int $retry_after = 30, int $status = 429, string $reason = 'throttled' ) {
		parent::__construct( $message, $status );
		$this->retry_after = max( 1, min( 86400, $retry_after ) );
		$this->reason      = $reason;
	}
}
