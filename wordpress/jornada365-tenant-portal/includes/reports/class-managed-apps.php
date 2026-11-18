<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Intune_Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Catalog plus one paginated aggregate for the tenant. Assignments and devices are separate jobs. */
final class Managed_Apps extends Intune_App_Report {
	public function step( array $state, array $params, float $deadline ): array {
		$done = $this->fetch_collection(
			$state, 'apps', '/v1.0/deviceAppManagement/mobileApps?$select=id,displayName,publisher,publishingState,lastModifiedDateTime&$top=999', $deadline, [],
			static fn( $a ) => [
				'id' => strtolower( (string) $a['id'] ), 'name' => (string) ( $a['displayName'] ?? '' ),
				'publisher' => (string) ( $a['publisher'] ?? '' ), 'type' => preg_replace( '/^#?microsoft\.graph\./', '', (string) ( $a['@odata.type'] ?? '' ) ),
				'state' => (string) ( $a['publishingState'] ?? '' ), 'modified' => self::ts( $a['lastModifiedDateTime'] ?? '' ),
			]
		);
		$n = count( $state['apps'] );
		if ( ! $done || 0 === $n ) {
			return $this->progress( $state, $done, $n, 'Carregando catálogo do Intune…' );
		}
		$state['phase'] = 1;
		try {
			$done = $this->install_pages( $state, [ 'select' => [ 'ApplicationId', 'FailedDeviceCount' ], 'orderBy' => [ 'ApplicationId asc' ] ], false, $deadline,
				[ 'ApplicationId', 'FailedDeviceCount' ], static function ( array &$state, array $row ) {
					$count = $row['FailedDeviceCount'];
					if ( is_scalar( $count ) && ctype_digit( (string) $count ) ) {
						$state['failures'][ strtolower( (string) $row['ApplicationId'] ) ] = (int) $count;
					}
				}
			);
		} catch ( Graph_Exception $e ) {
			$this->optional_error( $state, 'Contagem de falhas do Intune indisponível', $e );
			// Discard an incomplete aggregate. A missing record is always different from zero.
			unset( $state['failures'] );
			$done = true;
		}
		return $this->progress( $state, $done, $n + ( $state['skip'] ?? 0 ), 'Consultando contagens de falhas…' );
	}

	public function finalize( array $state, array $params ): array {
		$rows = array_values( array_column( $state['apps'], null, 'id' ) );
		$with_failures = 0;
		$unknown = 0;
		foreach ( $rows as &$row ) {
			$row['platform'] = Intune_Platform::catalog( $row['type'] ?? '' );
			$row['failedDevices'] = $state['failures'][ $row['id'] ] ?? null;
			$with_failures += $row['failedDevices'] > 0 ? 1 : 0;
			$unknown += null === $row['failedDevices'] ? 1 : 0;
		}
		unset( $row );
		usort( $rows, static fn( $a, $b ) => ( $b['failedDevices'] ?? -1 ) <=> ( $a['failedDevices'] ?? -1 ) ?: strnatcasecmp( $a['name'], $b['name'] ) );
		return [ 'rows' => $rows, 'summary' => [ 'apps' => count( $rows ), 'withFailures' => $with_failures, 'unknown' => $unknown ] ];
	}
}
