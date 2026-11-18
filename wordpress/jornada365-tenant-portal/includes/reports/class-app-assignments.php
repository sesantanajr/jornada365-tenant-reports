<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolve unique group IDs in batches while preserving exclusions and tenant-wide targets. */
class App_Assignments extends Intune_App_Report {
	public function normalize_params( array $params ): array {
		return $this->app_params( $params );
	}

	protected static function assignment_row( array $a ): array {
		$target = $a['target'] ?? [];
		return [
			'id' => (string) ( $a['id'] ?? '' ), 'intent' => (string) ( $a['intent'] ?? '' ),
			'target' => preg_replace( '/^#?microsoft\.graph\./', '', (string) ( $target['@odata.type'] ?? '' ) ),
			'groupId' => strtolower( (string) ( $target['groupId'] ?? '' ) ),
			'collectionId' => (string) ( $target['collectionId'] ?? '' ),
			'filterId' => (string) ( $target['deviceAndAppManagementAssignmentFilterId'] ?? '' ),
			'filterType' => (string) ( $target['deviceAndAppManagementAssignmentFilterType'] ?? '' ),
		];
	}

	protected static function assignments_path( string $id ): string {
		return '/deviceAppManagement/mobileApps/' . rawurlencode( $id ) . '/assignments?$select=id,intent,target';
	}

	public function step( array $state, array $params, float $deadline ): array {
		$done = $this->fetch_collection( $state, 'rows', '/v1.0' . self::assignments_path( $params['id'] ), $deadline, [], [ self::class, 'assignment_row' ] );
		$n = count( $state['rows'] );
		if ( ! $done ) {
			return $this->progress( $state, false, $n, 'Carregando atribuições do aplicativo…' );
		}
		return $this->resolve_names( $state, $deadline, $n );
	}

	protected function resolve_names( array $state, float $deadline, int $n ): array {
		if ( ! isset( $state['groups'] ) ) {
			$state['groups'] = array_values( array_unique( array_filter( array_column( $state['rows'], 'groupId' ), 'wp_is_uuid' ) ) );
			$state['offset'] = 0;
			$state['phase']  = 2;
		}
		while ( $state['offset'] < count( $state['groups'] ) && ! $this->out_of_time( $deadline ) ) {
			$ids = array_slice( $state['groups'], $state['offset'], 500 );
			try {
				$reply = $this->graph->get_directory_objects( $ids, [ 'group' ] );
				if ( ! isset( $reply['value'] ) || ! is_array( $reply['value'] ) ) {
					throw new Graph_Exception( 'O diretório devolveu uma resposta incompleta.', 502 );
				}
				foreach ( $reply['value'] as $group ) {
					$state['names'][ strtolower( (string) $group['id'] ) ] = (string) ( $group['displayName'] ?? '' );
				}
			} catch ( Graph_Throttled $e ) {
				$this->pause_read( $state, $e->retry_after, $e->reason );
				break;
			} catch ( Graph_Exception $e ) {
				$this->optional_error( $state, 'Nomes dos grupos indisponíveis', $e );
				$state['offset'] = count( $state['groups'] );
				break;
			}
			$state['offset'] += count( $ids );
		}
		return $this->progress( $state, $state['offset'] >= count( $state['groups'] ), $n + $state['offset'], 'Consultando nomes de grupos…' );
	}

	public function finalize( array $state, array $params ): array {
		$rows = $state['rows'];
		foreach ( $rows as &$row ) {
			$row['groupName'] = $state['names'][ $row['groupId'] ] ?? '';
		}
		unset( $row );
		return [ 'rows' => $rows ];
	}
}
