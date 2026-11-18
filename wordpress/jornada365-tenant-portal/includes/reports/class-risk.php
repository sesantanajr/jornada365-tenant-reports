<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identity Protection: risky users and recent risk detections (Entra ID P2).
 */
final class Risk extends Report_Base {

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'users' => [], 'detections' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			try {
				$done = $this->fetch_collection( $state, 'users', '/v1.0/identityProtection/riskyUsers?$filter=' . rawurlencode( "riskState eq 'atRisk' or riskState eq 'confirmedCompromised'" ) . '&$top=500', $deadline, [], fn( $u ) => [
					'id'          => (string) $u['id'],
					'upn'         => (string) ( $u['userPrincipalName'] ?? '' ),
					'displayName' => (string) ( $u['userDisplayName'] ?? '' ),
					'level'       => (string) ( $u['riskLevel'] ?? '' ),
					'state'       => (string) ( $u['riskState'] ?? '' ),
					'detail'      => (string) ( $u['riskDetail'] ?? '' ),
					'updated'     => (string) ( $u['riskLastUpdatedDateTime'] ?? '' ),
				] );
			} catch ( Graph_Exception $e ) {
				if ( $e->is_forbidden() || $e->is_license_gate() || 400 === $e->getCode() ) {
					throw new Graph_Exception( 'Identity Protection indisponível: ' . $e->getMessage() . ' (requer Entra ID P2 e IdentityRiskyUser.Read.All).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['users'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d usuários de risco…', count( $state['users'] ) ) ];
			}
			$state['phase'] = 1;
		}

		if ( 1 === $state['phase'] ) {
			try {
				$done = $this->fetch_collection( $state, 'detections', '/v1.0/identityProtection/riskDetections?$filter=' . rawurlencode( 'detectedDateTime ge ' . gmdate( 'Y-m-d\\TH:i:s\\Z', time() - 30 * DAY_IN_SECONDS ) ) . '&$top=500', $deadline, [], fn( $d ) => [
					'upn'        => (string) ( $d['userPrincipalName'] ?? '' ),
					'type'       => (string) ( $d['riskEventType'] ?? '' ),
					'level'      => (string) ( $d['riskLevel'] ?? '' ),
					'state'      => (string) ( $d['riskState'] ?? '' ),
					'detected'   => (string) ( $d['detectedDateTime'] ?? '' ),
					'ip'         => (string) ( $d['ipAddress'] ?? '' ),
					'location'   => trim( ( ( $d['location']['city'] ?? '' ) . ' ' . ( $d['location']['countryOrRegion'] ?? '' ) ) ),
					'detectionTiming' => (string) ( $d['detectionTimingType'] ?? '' ),
				] );
			} catch ( Graph_Exception $e ) {
				$this->warn( $state, 'Detecções de risco indisponíveis: ' . $e->getMessage() . ' (IdentityRiskEvent.Read.All).' );
				$state['detections'] = [];
				$done                = true;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['detections'] ), 'total' => 0, 'done' => false, 'message' => 'Detecções de risco…' ];
			}
			$state['phase'] = 2;
		}

		$n = count( $state['users'] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $n, 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		$users   = $state['users'] ?? [];
		$summary = [ 'total' => count( $users ), 'high' => 0, 'medium' => 0, 'low' => 0, 'atRisk' => 0, 'confirmedCompromised' => 0, 'detections' => count( $state['detections'] ?? [] ), 'byType' => [] ];
		foreach ( $users as $u ) {
			$summary[ $u['level'] ] = ( $summary[ $u['level'] ] ?? 0 ) + 1;
			$summary['atRisk']               += 'atRisk' === $u['state'] ? 1 : 0;
			$summary['confirmedCompromised'] += 'confirmedCompromised' === $u['state'] ? 1 : 0;
		}
		$detections = $state['detections'] ?? [];
		usort( $detections, fn( $a, $b ) => strcmp( $b['detected'], $a['detected'] ) ); // newest first ($orderby isn't documented on v1.0)
		foreach ( $detections as $d ) {
			$summary['byType'][ $d['type'] ] = ( $summary['byType'][ $d['type'] ] ?? 0 ) + 1;
		}
		arsort( $summary['byType'] );
		$summary['detectionsWindowDays'] = 30;
		return [ 'rows' => $users, 'detections' => array_slice( $detections, 0, 2000 ), 'summary' => $summary, '_meta' => [ 'rows' => count( $users ) ] ];
	}
}
