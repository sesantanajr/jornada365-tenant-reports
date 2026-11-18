<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Intune v1.0 policy and setting states for one managed device, requested from its inventory row. */
final class Device_Compliance extends Report_Base {

	public function normalize_params( array $params ): array {
		$id = is_string( $params['id'] ?? null ) ? strtolower( $params['id'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id ) ) {
			throw new \InvalidArgumentException( 'ID de dispositivo do Intune inválido.' );
		}
		$name = is_string( $params['name'] ?? null ) ? $params['name'] : '';
		return [ 'id' => $id, 'name' => mb_substr( sanitize_text_field( $name ), 0, 200 ) ];
	}

	public function initial_state( array $params ): array {
		return [ 'policies' => [], 'index' => 0, 'rows' => [], '_warnings' => [] ];
	}

	private static function failed( string $state ): bool {
		return in_array( strtolower( $state ), [ 'noncompliant', 'error', 'conflict' ], true );
	}

	/** Drop user identity, current setting values and successful settings before persisting progress. */
	private static function policy( array $policy ): array {
		$name = (string) ( $policy['displayName'] ?? '' );
		$status = (string) ( $policy['state'] ?? '' );
		$settings = (array) ( $policy['settingStates'] ?? [] );
		$rows = [];
		foreach ( $settings as $setting ) {
			if ( ! is_array( $setting ) || ! self::failed( (string) ( $setting['state'] ?? '' ) ) ) {
				continue;
			}
			$row = [
				'policy' => $name, 'policyState' => $status, 'state' => $setting['state'],
				'setting' => (string) ( ( $setting['settingName'] ?? '' ) ?: ( $setting['setting'] ?? '' ) ),
				'instance' => (string) ( $setting['instanceDisplayName'] ?? '' ),
				'code' => (string) ( $setting['errorCode'] ?? '' ), 'description' => (string) ( $setting['errorDescription'] ?? '' ),
			];
			$rows[ serialize( $row ) ] = $row;
		}
		return [
			'id' => (string) ( $policy['id'] ?? '' ), 'name' => $name, 'state' => $status, 'rows' => array_values( $rows ),
			'needsDetail' => self::failed( $status ) && ! $settings && (int) ( $policy['settingCount'] ?? 1 ) > 0,
		];
	}

	public function step( array $state, array $params, float $deadline ): array {
		$base = '/v1.0/deviceManagement/managedDevices/' . rawurlencode( $params['id'] ) . '/deviceCompliancePolicyStates';
		try {
			$listed = $this->fetch_collection( $state, 'policies', $base . '?$select=id,displayName,state,settingCount,settingStates', $deadline, [], static fn( array $item ): array => self::policy( $item ) );
		} catch ( Graph_Exception $e ) {
			if ( 403 === $e->getCode() ) {
				throw new Graph_Exception( 'O Intune negou a leitura das políticas. Verifique o consentimento para DeviceManagementConfiguration.Read.All e o acesso da conta ao dispositivo.', 403, $e->graph_code() );
			}
			throw $e;
		}
		$total = count( $state['policies'] );
		while ( $listed && $state['index'] < $total && ! $this->out_of_time( $deadline ) ) {
			$policy = $state['policies'][ $state['index'] ];
			// Some tenants return settingStates only when requesting the individual policy state.
			if ( $policy['needsDetail'] && '' !== $policy['id'] ) {
				try {
					$detail = $this->graph->get( $base . '/' . rawurlencode( $policy['id'] ) . '?$select=id,displayName,state,settingStates' );
					$policy = self::policy( $detail + [ 'displayName' => $policy['name'], 'state' => $policy['state'] ] );
				} catch ( Graph_Throttled $e ) {
					$this->pause_read( $state, $e->retry_after, $e->reason );
					break;
				} catch ( Graph_Exception $e ) {
					if ( ! in_array( $e->getCode(), [ 403, 404 ], true ) ) {
						throw $e;
					}
					$this->warn( $state, 'O Intune não disponibilizou as configurações de uma ou mais políticas.' );
				}
			}
			if ( ! $policy['rows'] && self::failed( $policy['state'] ) ) {
				$policy['rows'][] = [ 'policy' => $policy['name'], 'policyState' => $policy['state'], 'state' => $policy['state'], 'setting' => '', 'instance' => '', 'code' => '', 'description' => '' ];
			}
			foreach ( $policy['rows'] as $row ) {
				$state['rows'][] = $row;
			}
			$state['policies'][ $state['index'] ] = null;
			++$state['index'];
		}
		return [ 'state' => $state, 'progress' => $state['index'], 'total' => $total, 'done' => $listed && $state['index'] === $total, 'message' => 'Consultando estados de conformidade do Intune…' ];
	}

	public function finalize( array $state, array $params ): array {
		return [ 'rows' => $state['rows'], '_meta' => [ 'policies' => count( $state['policies'] ), 'rows' => count( $state['rows'] ) ] ];
	}
}
