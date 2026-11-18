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

	public static function assignment_row( array $a ): array {
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

	public static function assignments_path( string $id ): string {
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
			$ids = array_slice( $state['groups'], $state['offset'], empty( $state['groups_individual'] ) ? 1000 : 20 );
			try {
				// Directory objects require Directory.Read.All. The group endpoint also supports
				// narrower group-read grants that the tenant may already have.
				if ( ! empty( $state['groups_individual'] ) ) {
					$batch = array_map( static fn( $id ) => [
						'id' => $id, 'method' => 'GET', 'url' => '/groups/' . $id . '?$select=id,displayName',
					], $ids );
					$groups = $this->fetch_batch( $state, 'group_window', $batch );
					if ( null === $groups ) { break; }
					foreach ( $groups as $id => $group ) {
						if ( 200 === $group['status'] && ! empty( $group['body']['displayName'] ) ) {
							$state['names'][ $id ] = (string) $group['body']['displayName'];
						}
					}
					$state['offset'] += count( $ids );
					continue;
				}
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
				if ( 403 === $e->getCode() && empty( $state['groups_individual'] ) ) {
					$state['groups_individual'] = true;
					continue;
				}
				$this->optional_error( $state, 'Nomes dos grupos indisponíveis', $e );
				$state['offset'] = count( $state['groups'] );
				break;
			}
			$state['offset'] += count( $ids );
		}
		$done = $state['offset'] >= count( $state['groups'] );
		if ( $done && count( array_filter( $state['names'] ?? [], 'strlen' ) ) < count( $state['groups'] ) ) {
			$this->warn( $state, 'Alguns grupos não retornaram um nome. O relatório conserva os IDs e as atribuições.' );
		}
		return $this->progress( $state, $done, $n + $state['offset'], 'Consultando nomes de grupos…' );
	}

	public function finalize( array $state, array $params ): array {
		$rows = [];
		foreach ( $state['rows'] as $row ) {
			// Tenant-wide jobs store app metadata once per catalog entry until delivery.
			if ( isset( $row['appIndex'] ) ) {
				$row += $state['catalog'][ $row['appIndex'] ];
				unset( $row['appIndex'] );
			}
			$row['groupName'] = $state['names'][ $row['groupId'] ] ?? '';
			if ( '' === $row['id'] ) {
				$rows[] = $row;
			} else {
				$rows[ ( $row['appId'] ?? $params['id'] ?? '' ) . ':' . $row['id'] ] = $row;
			}
		}
		return [ 'rows' => array_values( $rows ) ];
	}
}
