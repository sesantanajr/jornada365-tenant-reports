<?php
namespace J365TP\Reports;

use J365TP\Graph_Client;
use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for chunked reports.
 *
 * step() is called repeatedly with the persisted $state until it returns done=true.
 * Every step must respect $deadline (microtime) and persist progress inside $state.
 */
abstract class Report_Base {

	protected Graph_Client $graph;
	protected string $tenant_id;

	public function __construct( string $tenant_id ) {
		$this->tenant_id = strtolower( $tenant_id );
		$this->graph     = new Graph_Client( $tenant_id );
	}

	/** Bounds Graph retries to the current job step (see Graph_Client::set_deadline). */
	public function set_deadline( float $deadline ): void {
		$this->graph->set_deadline( $deadline );
	}

	/** Jobs that act on behalf of the signed-in admin receive the session (delegated token lookup). */
	public function set_session( array $session ): void {
	}

	/** Notes produced by the Graph client during the last step (e.g. omitted properties). */
	public function graph_notes(): array {
		return $this->graph->take_notes();
	}

	/** Whitelists & normalizes params. */
	public function normalize_params( array $params ): array {
		return [];
	}

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [] ];
	}

	/**
	 * @return array{state: array, progress: int, total: int, done: bool, message: string}
	 */
	abstract public function step( array $state, array $params, float $deadline ): array;

	/** Builds the final payload (rows + summary) from the accumulated state. */
	abstract public function finalize( array $state, array $params ): array;

	/* ---------------- helpers ---------------- */

	protected function out_of_time( float $deadline ): bool {
		// Leave room for the next HTTP call and for the caller to persist its state.
		return microtime( true ) + 1 >= $deadline;
	}

	/**
	 * Fetch pages of $url into $state[$bucket], resuming from $state["{$bucket}_next"].
	 * Returns true when the collection is exhausted.
	 */
	protected function fetch_collection( array &$state, string $bucket, string $url, float $deadline, array $headers = [], ?callable $map = null ): bool {
		$next_key = $bucket . '_next';
		$done_key = $bucket . '_done';
		if ( ! empty( $state[ $done_key ] ) ) {
			return true;
		}
		$state[ $bucket ] = $state[ $bucket ] ?? [];
		$next             = $state[ $next_key ] ?? $url;

		while ( $next && ! $this->out_of_time( $deadline ) ) {
			try {
				$data = $this->graph->get( $next, $headers );
			} catch ( Graph_Throttled $e ) {
				$this->pause_read( $state, $e->retry_after, $e->reason );
				break;
			}
			foreach ( $data['value'] ?? [] as $item ) {
				$state[ $bucket ][] = $map ? $map( $item ) : $item;
			}
			$cursor = $data['@odata.nextLink'] ?? null;
			if ( $next === $cursor ) {
				throw new Graph_Exception( 'O Microsoft Graph repetiu a página atual do relatório.', 502, 'paging_loop' );
			}
			$next = is_string( $cursor ) && '' !== $cursor ? $cursor : null;
		}

		$state[ $next_key ] = $next;
		if ( null === $next ) {
			$state[ $done_key ] = true;
			return true;
		}
		return false;
	}

	/**
	 * Save successful GET sub-responses, then retry only temporary failures on a later step.
	 * The caller must keep its batch window fixed until the helper returns all responses.
	 */
	protected function fetch_batch( array &$state, string $bucket, array $requests ): ?array {
		$completed = $state[ $bucket ] ?? [];
		$pending   = array_values( array_filter( $requests, static fn( $request ) => ! isset( $completed[ (string) $request['id'] ] ) ) );
		try {
			$responses = $this->graph->batch( $pending, false );
		} catch ( Graph_Throttled $e ) {
			$this->pause_read( $state, $e->retry_after, $e->reason );
			return null;
		}

		$retry  = 0;
		$reason = 'network';
		foreach ( $pending as $request ) {
			$id       = (string) $request['id'];
			$response = $responses[ $id ] ?? null;
			if ( null === $response ) {
				$retry = max( 2, $retry );
				continue;
			}
			if ( isset( $response['retryAfter'] ) ) {
				$retry  = max( $retry, (int) $response['retryAfter'] );
				$reason = 'throttled';
				continue;
			}
			$completed[ $id ] = $response;
		}

		if ( $retry > 0 ) {
			$state[ $bucket ] = $completed;
			$this->pause_read( $state, $retry, $reason );
			return null;
		}

		unset( $state[ $bucket ] );
		return $completed;
	}

	private function pause_read( array &$state, int $seconds, string $reason ): void {
		$state['_retry_at']     = time() + min( 86400, $seconds );
		$state['_retry_reason'] = $reason;
	}

	protected function warn( array &$state, string $message ): void {
		$state['_warnings']   = $state['_warnings'] ?? [];
		$state['_warnings'][] = $message;
	}

	/**
	 * Normalizes Graph timestamps: never-signed-in values come back as "" or "0001-01-01T00:00:00Z",
	 * which strtotime() would accept as year 1 (≈739,000 days ago).
	 */
	protected static function ts( $value ): string {
		$v = is_string( $value ) ? trim( $value ) : '';
		return ( '' === $v || str_starts_with( $v, '0001-01-01' ) ) ? '' : $v;
	}

	protected static function days_since( ?string $iso ): ?int {
		$iso = self::ts( $iso );
		if ( '' === $iso ) {
			return null;
		}
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return null;
		}
		return (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
	}

	protected static function clamp_days( $value, int $default ): int {
		$v = (int) $value;
		return $v > 0 ? min( 730, $v ) : $default;
	}

	/** Entra ID P1-gated endpoint wrapper: returns null and records a warning when the tenant lacks the license. */
	protected function try_premium( array &$state, callable $fn, string $feature ) {
		try {
			return $fn();
		} catch ( Graph_Exception $e ) {
			if ( $e->is_license_gate() || $e->is_forbidden() ) {
				$this->warn( $state, $feature . ': indisponível neste tenant (' . $e->getMessage() . ')' );
				return null;
			}
			throw $e;
		}
	}
}
