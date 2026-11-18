<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Intune_Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Explicit tenant-wide read, isolated from discovered apps and the managed catalog. */
final class Intune_Assignments extends App_Assignments {
	public function normalize_params( array $params ): array {
		return [];
	}

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, 'index' => 0, 'rows' => [], 'extra' => [], '_warnings' => [] ];
	}

	private static function add_row( array &$state, int $index, array $assignment, string $coverage = 'assignment' ): void {
		$state['rows'][] = [ 'appIndex' => $index, 'coverage' => $coverage ] + self::assignment_row( $assignment );
	}

	private static function collect_assignment( array &$state, int $index, $assignment ): void {
		if ( is_array( $assignment ) && is_array( $assignment['target'] ?? null ) ) {
			self::add_row( $state, $index, $assignment );
		} else {
			self::unknown( $state, $index );
		}
	}

	private static function unknown( array &$state, int $index ): void {
		self::add_row( $state, $index, [], 'unavailable' );
		if ( empty( $state['incomplete'] ) ) {
			$state['_warnings'][] = 'Alguns aplicativos têm atribuições indisponíveis. Consulte a coluna de cobertura.';
		}
		$state['incomplete'] = true;
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( ! $this->fetch_collection( $state, 'catalog', '/v1.0/deviceAppManagement/mobileApps?$select=id,displayName&$top=999', $deadline, [],
			static fn( $a ) => [
				'appId' => strtolower( (string) $a['id'] ), 'appName' => (string) ( $a['displayName'] ?? '' ),
				'platform' => Intune_Platform::catalog( (string) ( $a['@odata.type'] ?? '' ) ),
				'appType' => preg_replace( '/^#?microsoft\.graph\./i', '', (string) ( $a['@odata.type'] ?? '' ) ),
			]
		) ) {
			return $this->progress( $state, false, count( $state['catalog'] ), 'Carregando catálogo do Intune…' );
		}
		// A moved app can occur on adjacent catalog pages. Keep older in-flight window indices intact.
		if ( empty( $state['catalog_compact'] ) && ! $state['index'] && ! $state['extra'] && empty( $state['window'] ) ) {
			$state['catalog'] = array_values( array_column( $state['catalog'], null, 'appId' ) );
			$state['catalog_compact'] = true;
		}

		// Drain nextLinks separately through Graph_Client's trusted-origin validation. We hold
		// at most one window (20 apps) of continuation links; completed batches are never replayed.
		while ( $state['extra'] && ! $this->out_of_time( $deadline ) ) {
			$index = array_key_first( $state['extra'] );
			$extra = $state['extra'][ $index ];
			$collect = static function ( array &$state, $assignment ) use ( $index ) {
				self::collect_assignment( $state, $index, $assignment );
				$state['extra'][ $index ]['found'] = true;
			};
			// Jobs started before compact pagination may still have raw continuation rows.
			foreach ( $state['more'] ?? [] as $assignment ) {
				$collect( $state, $assignment );
			}
			$state['more'] = [];
			try {
				$done = $this->fetch_collection( $state, 'more', $extra['next'], $deadline, [], null, $collect );
				if ( ! $done ) {
					return $this->progress( $state, false, $state['index'] + count( $state['rows'] ), 'Lendo as atribuições do catálogo…' );
				}
				if ( ! $state['extra'][ $index ]['found'] ) {
					self::add_row( $state, $index, [], 'none' );
				}
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				self::unknown( $state, $index );
			}
			unset( $state['extra'][ $index ], $state['more'], $state['more_next'], $state['more_done'] );
		}

		$start = $state['index'];
		$size  = count( $state['catalog'] );
		if ( $start >= $size && ! $state['extra'] ) {
			return $this->resolve_names( $state, $deadline, $start + count( $state['rows'] ) );
		}
		// Read up to four independent windows in a worker slice, stopping at throttling or a nextLink.
		for ( $window = 0; $window < 4 && $start < $size && ! $state['extra'] && ! $this->out_of_time( $deadline ); ++$window ) {
			$state['phase'] = 1;
			$requests = [];
			foreach ( array_slice( $state['catalog'], $start, 20, true ) as $index => $app ) {
				$requests[] = [ 'id' => (string) $index, 'method' => 'GET', 'url' => self::assignments_path( $app['appId'] ) ];
			}
			$replies = $this->fetch_batch( $state, 'window', $requests );
			if ( null === $replies ) { break; }
			foreach ( $replies as $index => $reply ) {
				$body = $reply['body'];
				$rows = $body['value'] ?? null;
				if ( 200 !== $reply['status'] || ! is_array( $rows ) || ! array_is_list( $rows ) ) {
					self::unknown( $state, $index );
					continue;
				}
				foreach ( $rows as $assignment ) {
					self::collect_assignment( $state, $index, $assignment );
				}
				if ( isset( $body['@odata.nextLink'] ) && ! is_string( $body['@odata.nextLink'] ) ) {
					self::unknown( $state, $index );
				} elseif ( ! empty( $body['@odata.nextLink'] ) ) {
					$state['extra'][ $index ] = [ 'next' => $body['@odata.nextLink'], 'found' => (bool) $rows ];
				} elseif ( ! $rows ) {
					self::add_row( $state, $index, [], 'none' );
				}
			}
			$state['index'] += count( $requests );
			$start = $state['index'];
		}
		return $this->progress( $state, false, $state['index'] + count( $state['rows'] ), 'Lendo as atribuições do catálogo…' );
	}
}
