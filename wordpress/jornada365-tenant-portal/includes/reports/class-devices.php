<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Devices: Entra ID device objects joined with Intune managed devices.
 * Classification: Híbrido (Hybrid Entra joined) / Entra joined (cloud-only) / Registrado (BYOD) / Obsoleto.
 */
final class Devices extends Report_Base {

	// Microsoft Graph v1.0 schemas only (managedDevice has no joinType/autopilotEnrolled in v1.0).
	private const ENTRA_SELECT  = 'id,deviceId,displayName,operatingSystem,operatingSystemVersion,trustType,approximateLastSignInDateTime,accountEnabled,isCompliant,isManaged,registrationDateTime,profileType,deviceOwnership,enrollmentType,managementType,onPremisesSyncEnabled,onPremisesLastSyncDateTime,mdmAppId,manufacturer,model,enrollmentProfileName,physicalIds';
	private const INTUNE_SELECT = 'id,azureADDeviceId,deviceName,operatingSystem,osVersion,complianceState,lastSyncDateTime,managementAgent,enrolledDateTime,userPrincipalName,userDisplayName,model,manufacturer,serialNumber,isEncrypted,azureADRegistered,deviceEnrollmentType,managedDeviceOwnerType,deviceCategoryDisplayName,totalStorageSpaceInBytes,freeStorageSpaceInBytes,isSupervised';

	/** Join type as Intune names it, derived from the Entra trustType (managedDevice.joinType is beta-only). */
	private static function join_type( string $trust, ?array $m = null ): string {
		return match ( $trust ) {
			'ServerAd'  => 'hybridAzureADJoined',
			'AzureAd'   => 'azureADJoined',
			'Workplace' => 'azureADRegistered',
			default     => ( $m && ! empty( $m['azureADRegistered'] ) ) ? 'azureADRegistered' : '',
		};
	}

	/**
	 * Keeps only what the report uses. physicalIds is reduced to an Autopilot flag: devices registered
	 * with Windows Autopilot carry a "[ZTDID]:" physical id (same rule used by Autopilot dynamic groups),
	 * so no extra permission (DeviceManagementServiceConfig) is needed.
	 */
	private static function compact_entra( array $d ): array {
		$autopilot = false;
		foreach ( (array) ( $d['physicalIds'] ?? [] ) as $pid ) {
			if ( 0 === stripos( (string) $pid, '[ZTDID]' ) ) {
				$autopilot = true;
				break;
			}
		}
		unset( $d['physicalIds'], $d['@odata.type'] );
		$d['_autopilot'] = $autopilot;
		return $d;
	}

	public function normalize_params( array $params ): array {
		return [ 'stale_days' => self::clamp_days( $params['stale_days'] ?? 0, (int) \J365TP\Settings::get( 'stale_device_days', 90 ) ) ];
	}

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'intune' => true ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'entra', '/v1.0/devices?$select=' . self::ENTRA_SELECT . '&$top=999', $deadline, [], static fn( array $d ): array => self::compact_entra( $d ) );
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['entra'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d dispositivos do Entra ID…', count( $state['entra'] ) ) ];
			}
			$state['phase'] = 1;
		}

		if ( 1 === $state['phase'] ) {
			if ( $state['intune'] ) {
				try {
					$done = $this->fetch_collection( $state, 'intune_devices', '/v1.0/deviceManagement/managedDevices?$select=' . self::INTUNE_SELECT . '&$top=1000', $deadline );
				} catch ( Graph_Exception $e ) {
					// 403/404: permission or service missing; 400 "Request not applicable to target tenant": no Intune licence.
					$no_intune = 400 === $e->getCode() && (bool) preg_match( '/not applicable|not licensed|no license|licen[cs]/i', $e->getMessage() );
					if ( ( in_array( $e->getCode(), [ 403, 404 ], true ) || $no_intune ) && empty( $state['intune_devices'] ) ) {
						$state['intune_error'] = $no_intune ? 'license' : ( 403 === $e->getCode() ? 'forbidden' : 'unavailable' );
						$this->optional_error( $state, 'Intune indisponível', $e );
						$state['intune']         = false;
						$state['intune_devices'] = [];
						$done                    = true;
					} else {
						throw $e;
					}
				}
				if ( ! $done ) {
					return [ 'state' => $state, 'progress' => count( $state['entra'] ) + count( $state['intune_devices'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d dispositivos do Intune…', count( $state['intune_devices'] ) ) ];
				}
			}
			$state['phase'] = 2;
		}

		$n = count( $state['entra'] ) + count( $state['intune_devices'] ?? [] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $n, 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		$stale_days = (int) $params['stale_days'];
		$intune     = [];
		$intune_available = ! empty( $state['intune'] );
		$enrollments = [];
		$unjoined   = []; // enrolled in Intune without an Entra device id (userless Android/AOSP, failed registration…)
		foreach ( $state['intune_devices'] ?? [] as $d ) {
			$id = strtolower( (string) ( $d['id'] ?? '' ) );
			if ( '' === $id || isset( $enrollments[ $id ] ) ) {
				continue;
			}
			$enrollments[ $id ] = true;
			$key = strtolower( (string) ( $d['azureADDeviceId'] ?? '' ) );
			if ( '' !== $key && '00000000-0000-0000-0000-000000000000' !== $key ) {
				$old = $intune[ $key ] ?? null;
				// Re-enrolling a device can leave older Intune records for the same Entra ID.
				// Use the latest sync, then enrollment and ID, independently of Graph page order.
				if ( null === $old || self::enrollment_order( $d ) > self::enrollment_order( $old ) ) {
					$intune[ $key ] = $d;
				}
			} else {
				$unjoined[ 'intune:' . $id ] = $d;
			}
		}

		$rows    = [];
		$summary = [
			'total'          => 0,
			'hybrid'         => 0,
			'entraJoined'    => 0,
			'registered'     => 0,
			'stale'          => 0,
			'disabled'       => 0,
			'intuneManaged'  => 0,
			'notInIntune'    => 0,
			'nonCompliant'   => 0,
			'intuneStale'    => 0,
			'intuneOrphans'  => 0,
			'byOs'           => [],
			'byTrust'        => [],
			'byPlatform'     => [],
			'byRelease'      => [],
			'bySupport'      => [],
			'unencrypted'    => 0,
			'personal'       => 0,
		];
		$seen_intune = [];

		$seen_entra = [];
		foreach ( $state['entra'] ?? [] as $d ) {
			$object_id = strtolower( (string) ( $d['id'] ?? '' ) );
			if ( '' === $object_id || isset( $seen_entra[ $object_id ] ) ) {
				continue;
			}
			$seen_entra[ $object_id ] = true;
			$trust = (string) ( $d['trustType'] ?? '' );
			$class = match ( $trust ) {
				'ServerAd'  => 'Híbrido (Hybrid Entra joined)',
				'AzureAd'   => 'Cloud-only (Entra joined)',
				'Workplace' => 'Registrado (Entra registered)',
				default     => 'Desconhecido',
			};
			$last_entra = (string) ( $d['approximateLastSignInDateTime'] ?? '' );
			$key        = strtolower( (string) ( $d['deviceId'] ?? '' ) );
			$m          = isset( $seen_intune[ $key ] ) ? null : ( $intune[ $key ] ?? null );
			if ( $m ) {
				$seen_intune[ $key ] = true;
			}
			$last_sync   = $m ? (string) ( $m['lastSyncDateTime'] ?? '' ) : '';
			$days_entra  = self::days_since( $last_entra );
			$days_sync   = self::days_since( $last_sync );
			$stale       = ( null === $days_entra || $days_entra > $stale_days ) && ( null === $days_sync || $days_sync > $stale_days );
			if ( $stale && ! $intune_available ) {
				$stale = null; // A recent Intune sync cannot be ruled out without access to Intune.
			}

			$row = [
				'id'               => (string) $d['id'],
				'deviceId'         => (string) ( $d['deviceId'] ?? '' ),
				'name'             => (string) ( ( $d['displayName'] ?? '' ) ?: ( $m['deviceName'] ?? '' ) ),
				'trustType'        => $trust,
				'classification'   => $class,
				'enabled'          => $d['accountEnabled'] ?? null,
				'lastSignInEntra'  => $last_entra,
				'daysSinceEntra'   => $days_entra,
				'registered'       => (string) ( $d['registrationDateTime'] ?? '' ),
				'compliantEntra'   => $d['isCompliant'] ?? null,
				'managedEntra'     => $d['isManaged'] ?? null,
				'ownership'        => (string) ( $d['deviceOwnership'] ?? ( $m['managedDeviceOwnerType'] ?? '' ) ),
				'managementType'   => (string) ( $d['managementType'] ?? '' ),
				'profileType'      => (string) ( $d['profileType'] ?? '' ),
				'syncedFromAd'     => ! empty( $d['onPremisesSyncEnabled'] ),
				'manufacturer'     => (string) ( $d['manufacturer'] ?? ( $m['manufacturer'] ?? '' ) ),
				'model'            => (string) ( $d['model'] ?? ( $m['model'] ?? '' ) ),
				'autopilotProfile' => (string) ( $d['enrollmentProfileName'] ?? '' ),
				'inIntune'         => $intune_available ? (bool) $m : null,
				'intuneId'         => $m ? (string) $m['id'] : '',
				'compliance'       => $m ? (string) ( $m['complianceState'] ?? '' ) : '',
				'lastSyncIntune'   => $last_sync,
				'daysSinceSync'    => $days_sync,
				'joinType'         => self::join_type( $trust, $m ),
				'managementAgent'  => $m ? (string) ( $m['managementAgent'] ?? '' ) : '',
				'enrolled'         => $m ? (string) ( $m['enrolledDateTime'] ?? '' ) : '',
				'enrollmentType'   => $m ? (string) ( $m['deviceEnrollmentType'] ?? '' ) : '',
				'primaryUser'      => $m ? (string) ( $m['userPrincipalName'] ?? '' ) : '',
				'serial'           => $m ? (string) ( $m['serialNumber'] ?? '' ) : '',
				'encrypted'        => $m['isEncrypted'] ?? null,
				'autopilot'        => ! empty( $d['_autopilot'] ) || '' !== (string) ( $d['enrollmentProfileName'] ?? '' ),
				'category'         => $m ? (string) ( $m['deviceCategoryDisplayName'] ?? '' ) : '',
				'supervised'       => $m['isSupervised'] ?? null,
				'stale'            => $stale,
			];
			$row    = self::with_lifecycle( $row, (string) ( $d['operatingSystem'] ?? '' ), $m ? (string) ( $m['operatingSystem'] ?? '' ) : '', $m ? (string) ( $m['osVersion'] ?? '' ) : '', (string) ( $d['operatingSystemVersion'] ?? '' ) );
			$rows[] = $row;
			self::tally( $summary, $row, $stale_days );

			$summary['total']++;
			if ( 'ServerAd' === $trust ) {
				$summary['hybrid']++;
			} elseif ( 'AzureAd' === $trust ) {
				$summary['entraJoined']++;
			} elseif ( 'Workplace' === $trust ) {
				$summary['registered']++;
			}
			if ( false === $row['enabled'] ) {
				$summary['disabled']++;
			}
			$summary['byTrust'][ $class ]              = ( $summary['byTrust'][ $class ] ?? 0 ) + 1;
		}

		// Intune devices with no matching Entra object (orphans), including those enrolled without an Entra device id.
		foreach ( $intune + $unjoined as $key => $m ) {
			if ( isset( $seen_intune[ $key ] ) || empty( $m['id'] ) ) {
				continue;
			}
			$summary['intuneOrphans']++;
			$last_sync = (string) ( $m['lastSyncDateTime'] ?? '' );
			$days_sync = self::days_since( $last_sync );
			$row       = [
				'id'               => 'intune:' . $m['id'],
				'deviceId'         => (string) ( $m['azureADDeviceId'] ?? '' ),
				'name'             => (string) ( $m['deviceName'] ?? '' ),
				'os'               => (string) ( $m['operatingSystem'] ?? '' ),
				'osVersion'        => (string) ( $m['osVersion'] ?? '' ),
				'trustType'        => '',
				'classification'   => 'Somente Intune (sem objeto no Entra)',
				'enabled'          => null,
				'lastSignInEntra'  => '',
				'daysSinceEntra'   => null,
				'registered'       => '',
				'compliantEntra'   => null,
				'managedEntra'     => null,
				'ownership'        => (string) ( $m['managedDeviceOwnerType'] ?? '' ),
				'managementType'   => '',
				'profileType'      => '',
				'syncedFromAd'     => null,
				'manufacturer'     => (string) ( $m['manufacturer'] ?? '' ),
				'model'            => (string) ( $m['model'] ?? '' ),
				'autopilotProfile' => '',
				'inIntune'         => true,
				'intuneId'         => (string) $m['id'],
				'compliance'       => (string) ( $m['complianceState'] ?? '' ),
				'lastSyncIntune'   => $last_sync,
				'daysSinceSync'    => $days_sync,
				'joinType'         => self::join_type( '', $m ),
				'managementAgent'  => (string) ( $m['managementAgent'] ?? '' ),
				'enrolled'         => (string) ( $m['enrolledDateTime'] ?? '' ),
				'enrollmentType'   => (string) ( $m['deviceEnrollmentType'] ?? '' ),
				'primaryUser'      => (string) ( $m['userPrincipalName'] ?? '' ),
				'serial'           => (string) ( $m['serialNumber'] ?? '' ),
				'encrypted'        => $m['isEncrypted'] ?? null,
				'autopilot'        => null,
				'category'         => (string) ( $m['deviceCategoryDisplayName'] ?? '' ),
				'supervised'       => $m['isSupervised'] ?? null,
				'stale'            => null === $days_sync || $days_sync > $stale_days,
			];
			$row    = self::with_lifecycle( $row, '', (string) ( $m['operatingSystem'] ?? '' ), (string) ( $m['osVersion'] ?? '' ), '' );
			$rows[] = $row;
			self::tally( $summary, $row, $stale_days );
		}
		arsort( $summary['byOs'] );
		arsort( $summary['byPlatform'] );
		arsort( $summary['byRelease'] );
		$managed_count = count( $intune ) + count( $unjoined );
		if ( ! $intune_available ) {
			foreach ( [ 'intuneManaged', 'notInIntune', 'nonCompliant', 'intuneStale', 'intuneOrphans', 'unencrypted' ] as $key ) {
				$summary[ $key ] = null;
			}
			if ( in_array( null, array_column( $rows, 'stale' ), true ) ) {
				$summary['stale'] = null;
			}
		}

		return [
			'rows'    => $rows,
			'summary' => $summary,
			'_meta'   => [ 'rows' => count( $rows ), 'intune' => $intune_available, 'intuneError' => (string) ( $state['intune_error'] ?? '' ), 'intuneCount' => $intune_available ? $managed_count : null, 'previousEnrollments' => $intune_available ? count( $enrollments ) - $managed_count : null, 'stale_days' => $stale_days, 'lifecycleAsOf' => \J365TP\Os_Lifecycle::AS_OF ],
		];
	}

	/**
	 * Platform and OS support status. Intune's osVersion is the most precise ("10.0.22631.4317");
	 * the Entra name may carry the edition for devices synced from AD ("Windows 11 Enterprise").
	 */
	private static function with_lifecycle( array $row, string $entra_os, string $intune_os, string $intune_ver, string $entra_ver ): array {
		$name = '' !== $entra_os ? $entra_os : $intune_os;
		$row['os'] = $name;
		$row['osVersion'] = '' !== $intune_ver ? $intune_ver : $entra_ver;
		$lc                 = \J365TP\Os_Lifecycle::evaluate( $name, $row['osVersion'] );
		$row['platform']    = $lc['platform'];
		$row['osBuild']     = $lc['reportedBuild'] ?? '';
		$row['osRelease']   = $lc['release'];
		$row['support']     = $lc['support'];
		$row['supportEnd']  = $lc['supportEnd'];
		$row['supportNote'] = $lc['note'];
		return $row;
	}

	private static function enrollment_order( array $device ): array {
		return [ strtotime( $device['lastSyncDateTime'] ?? '' ) ?: 0, strtotime( $device['enrolledDateTime'] ?? '' ) ?: 0, (string) $device['id'] ];
	}

	private static function tally( array &$summary, array $row, int $stale_days ): void {
		$os = $row['os'] ?: 'Desconhecido';
		$summary['byOs'][ $os ] = ( $summary['byOs'][ $os ] ?? 0 ) + 1;
		if ( $row['stale'] ) {
			$summary['stale']++;
		}
		if ( $row['inIntune'] ) {
			$summary['intuneManaged']++;
			if ( 'noncompliant' === strtolower( $row['compliance'] ) ) {
				$summary['nonCompliant']++;
			}
			if ( null === $row['daysSinceSync'] || $row['daysSinceSync'] > $stale_days ) {
				$summary['intuneStale']++;
			}
		} elseif ( false === $row['inIntune'] ) {
			$summary['notInIntune']++;
		}
		$summary['byPlatform'][ $row['platform'] ] = ( $summary['byPlatform'][ $row['platform'] ] ?? 0 ) + 1;
		$summary['bySupport'][ $row['support'] ]   = ( $summary['bySupport'][ $row['support'] ] ?? 0 ) + 1;
		if ( '' !== $row['osRelease'] ) {
			$summary['byRelease'][ $row['osRelease'] ] = ( $summary['byRelease'][ $row['osRelease'] ] ?? 0 ) + 1;
		}
		if ( false === $row['encrypted'] ) {
			$summary['unencrypted']++;
		}
		if ( 'personal' === strtolower( (string) $row['ownership'] ) ) {
			$summary['personal']++;
		}
	}
}
