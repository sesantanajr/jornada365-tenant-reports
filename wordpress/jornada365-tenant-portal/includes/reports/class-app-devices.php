<?php
namespace J365TP\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drill-down: devices where a given detected app (one or more detectedApp IDs = versions) is installed.
 */
final class App_Devices extends Report_Base {

	public function normalize_params( array $params ): array {
		$ids = array_values( array_filter( array_map( 'strval', (array) ( $params['ids'] ?? [] ) ), fn( $id ) => (bool) preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $id ) ) );
		sort( $ids );
		return [ 'ids' => array_slice( $ids, 0, 50 ), 'name' => mb_substr( sanitize_text_field( (string) ( $params['name'] ?? '' ) ), 0, 200 ) ];
	}

	public function initial_state( array $params ): array {
		return [ 'idx' => 0, 'rows' => [], '_warnings' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		$ids = $params['ids'];
		while ( $state['idx'] < count( $ids ) && ! $this->out_of_time( $deadline ) ) {
			$id     = $ids[ $state['idx'] ];
			$bucket = 'dev_' . $state['idx'];
			$done   = $this->fetch_collection(
				$state,
				$bucket,
				'/v1.0/deviceManagement/detectedApps/' . rawurlencode( $id ) . '/managedDevices?$select=id,azureADDeviceId,deviceName,userPrincipalName,userDisplayName,operatingSystem,osVersion,complianceState,lastSyncDateTime,model,manufacturer,serialNumber,managedDeviceOwnerType,deviceEnrollmentType&$top=1000',
				$deadline,
				[],
				fn( $d ) => [
					'detectedAppId' => $id,
					'intuneId'      => (string) $d['id'],
					'name'          => (string) ( $d['deviceName'] ?? '' ),
					'user'          => (string) ( $d['userPrincipalName'] ?? '' ),
					'userName'      => (string) ( $d['userDisplayName'] ?? '' ),
					'os'            => (string) ( $d['operatingSystem'] ?? '' ),
					'osVersion'     => (string) ( $d['osVersion'] ?? '' ),
					'compliance'    => (string) ( $d['complianceState'] ?? '' ),
					'lastSync'      => (string) ( $d['lastSyncDateTime'] ?? '' ),
					'model'         => (string) ( $d['model'] ?? '' ),
					'manufacturer'  => (string) ( $d['manufacturer'] ?? '' ),
					'serial'        => (string) ( $d['serialNumber'] ?? '' ),
					'ownership'     => (string) ( $d['managedDeviceOwnerType'] ?? '' ),
					'enrollmentType' => (string) ( $d['deviceEnrollmentType'] ?? '' ), // v1.0 has no managedDevice.joinType
				]
			);
			if ( ! $done ) {
				break;
			}
			foreach ( $state[ $bucket ] as $row ) {
				$state['rows'][] = $row;
			}
			unset( $state[ $bucket ], $state[ $bucket . '_next' ], $state[ $bucket . '_done' ] );
			$state['idx']++;
		}
		$finished = $state['idx'] >= count( $ids );
		return [ 'state' => $state, 'progress' => $state['idx'], 'total' => count( $ids ), 'done' => $finished, 'message' => sprintf( 'Versão %d de %d…', min( $state['idx'] + 1, count( $ids ) ), count( $ids ) ) ];
	}

	public function finalize( array $state, array $params ): array {
		return [
			'rows'  => $state['rows'],
			'app'   => $params['name'],
			'_meta' => [ 'rows' => count( $state['rows'] ) ],
		];
	}
}
