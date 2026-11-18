<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

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

	private static function add_row( array &$state, array $app, array $assignment, string $coverage = 'assignment' ): void {
		$state['rows'][] = $app + [ 'coverage' => $coverage ] + self::assignment_row( $assignment );
	}

	private static function unknown( array &$state, array $app ): void {
		self::add_row( $state, $app, [], 'unavailable' );
		if ( empty( $state['incomplete'] ) ) {
			$state['_warnings'][] = 'Alguns aplicativos têm atribuições indisponíveis. Consulte a coluna de cobertura.';
		}
		$state['incomplete'] = true;
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( ! $this->fetch_collection( $state, 'catalog', '/v1.0/deviceAppManagement/mobileApps?$select=id,displayName&$top=999', $deadline, [],
			static fn( $a ) => [ 'appId' => (string) $a['id'], 'appName' => (string) ( $a['displayName'] ?? '' ) ]
		) ) {
			return $this->progress( $state, false, count( $state['catalog'] ), 'Carregando catálogo do Intune…' );
		}

		// Drain nextLinks separately through Graph_Client's trusted-origin validation. We hold
		// at most one window (20 apps) of continuation links; completed batches are never replayed.
		while ( $state['extra'] && ! $this->out_of_time( $deadline ) ) {
			$index = array_key_first( $state['extra'] );
			$app   = $state['catalog'][ $index ];
			$extra = $state['extra'][ $index ];
			try {
				$done = $this->fetch_collection( $state, 'more', $extra['next'], $deadline );
				if ( ! $done ) {
					return $this->progress( $state, false, $state['index'] + count( $state['rows'] ) + count( $state['more'] ), 'Lendo as atribuições do catálogo…' );
				}
				foreach ( $state['more'] as $assignment ) {
					self::add_row( $state, $app, $assignment );
				}
				if ( ! $extra['found'] && ! $state['more'] ) {
					self::add_row( $state, $app, [], 'none' );
				}
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				foreach ( $state['more'] ?? [] as $assignment ) {
					self::add_row( $state, $app, $assignment );
				}
				self::unknown( $state, $app );
			}
			unset( $state['extra'][ $index ], $state['more'], $state['more_next'], $state['more_done'] );
		}

		$start = $state['index'];
		$size  = count( $state['catalog'] );
		if ( $start >= $size && ! $state['extra'] ) {
			return $this->resolve_names( $state, $deadline, $start + count( $state['rows'] ) );
		}
		if ( ! $state['extra'] && ! $this->out_of_time( $deadline ) ) {
			$state['phase'] = 1;
			$requests = [];
			foreach ( array_slice( $state['catalog'], $start, 20, true ) as $index => $app ) {
				$requests[] = [ 'id' => (string) $index, 'method' => 'GET', 'url' => self::assignments_path( $app['appId'] ) ];
			}
			$replies = $this->fetch_batch( $state, 'window', $requests );
			if ( null !== $replies ) {
				foreach ( $replies as $index => $reply ) {
					$app  = $state['catalog'][ $index ];
					$body = $reply['body'];
					$rows = $body['value'] ?? null;
					if ( 200 !== $reply['status'] || ! is_array( $rows ) || ! array_is_list( $rows ) ) {
						self::unknown( $state, $app );
						continue;
					}
					foreach ( $rows as $assignment ) {
						self::add_row( $state, $app, $assignment );
					}
					if ( ! empty( $body['@odata.nextLink'] ) && is_string( $body['@odata.nextLink'] ) ) {
						$state['extra'][ $index ] = [ 'next' => $body['@odata.nextLink'], 'found' => (bool) $rows ];
					} elseif ( ! $rows ) {
						self::add_row( $state, $app, [], 'none' );
					}
				}
				$state['index'] += count( $requests );
			}
		}
		return $this->progress( $state, false, $state['index'] + count( $state['rows'] ), 'Lendo as atribuições do catálogo…' );
	}
}
