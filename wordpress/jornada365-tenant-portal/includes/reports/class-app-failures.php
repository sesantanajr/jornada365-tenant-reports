<?php
namespace J365TP\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Check only the selected app. Keep failure records, never the full successful-device inventory. */
final class App_Failures extends Intune_App_Report {
	public function normalize_params( array $params ): array {
		return $this->app_params( $params );
	}

	private static function failure_state( array $row ): ?string {
		// Prefer explicit English labels before the numeric Graph installState/resultantAppState enum.
		$states = [ $row['AppInstallState_loc'] ?? '', $row['InstallState_loc'] ?? '', $row['InstallState'] ?? '', $row['AppInstallState'] ?? '' ];
		foreach ( $states as $value ) {
			$key = strtolower( preg_replace( '/[^a-zA-Z]/', '', (string) $value ) );
			if ( in_array( $key, [ 'failed', 'installfailed', 'failedinstall', 'installationfailed', 'failedtoinstall' ], true ) ) {
				return 'failed';
			}
			if ( in_array( $key, [ 'uninstallfailed', 'faileduninstall', 'failedtouninstall' ], true ) ) {
				return 'uninstallFailed';
			}
			if ( in_array( $key, [ 'installed', 'notinstalled', 'notapplicable', 'pending', 'pendinginstall', 'installpending', 'available', 'unknown', 'waitingforinstallstatus' ], true ) ) {
				return '';
			}
		}
		foreach ( [ $row['InstallState'] ?? '', $row['AppInstallState'] ?? '' ] as $value ) {
			switch ( (string) $value ) {
				case '2': return 'failed';
				case '4': return 'uninstallFailed';
				case '-1': case '0': case '1': case '3': case '5': case '99': return '';
			}
		}
		return null;
	}

	public function step( array $state, array $params, float $deadline ): array {
		$columns = [ 'DeviceId', 'DeviceName', 'UserPrincipalName', 'AppVersion', 'AppInstallState', 'InstallState', 'AppInstallStateDetails', 'ErrorCode', 'HexErrorCode', 'LastModifiedDateTime' ];
		$query = [ 'filter' => "(ApplicationId eq '" . $params['id'] . "')", 'select' => $columns, 'orderBy' => [ 'DeviceId asc' ] ];
		$done = $this->install_pages( $state, $query, true, $deadline, [ 'DeviceId', 'AppInstallState' ], static function ( array &$state, array $row ) {
			$status = self::failure_state( $row );
			if ( null === $status ) {
				$state['unrecognized'] = ( $state['unrecognized'] ?? 0 ) + 1;
			} elseif ( '' !== $status ) {
				$state['rows'][] = [
					'deviceId' => (string) $row['DeviceId'], 'name' => (string) ( $row['DeviceName'] ?? '' ),
					'user' => (string) ( $row['UserPrincipalName'] ?? '' ), 'version' => (string) ( $row['AppVersion'] ?? '' ),
					'status' => $status, 'detail' => (string) ( $row['AppInstallStateDetails_loc'] ?? $row['AppInstallStateDetails'] ?? '' ),
					'code' => (string) ( $row['HexErrorCode'] ?? $row['ErrorCode'] ?? '' ), 'modified' => self::ts( $row['LastModifiedDateTime'] ?? '' ),
				];
			}
		} );
		if ( $done && ! empty( $state['unrecognized'] ) ) {
			$this->warn( $state, 'O Intune retornou estados desconhecidos. A lista contém apenas falhas identificadas.' );
		}
		return $this->progress( $state, $done, $state['skip'] ?? 0, 'Consultando falhas por dispositivo…' );
	}

	public function finalize( array $state, array $params ): array {
		return [ 'rows' => $state['rows'] ?? [], 'summary' => [ 'examined' => $state['skip'] ?? 0, 'unrecognized' => $state['unrecognized'] ?? 0 ] ];
	}
}
