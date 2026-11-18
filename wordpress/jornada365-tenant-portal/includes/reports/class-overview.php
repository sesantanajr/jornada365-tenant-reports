<?php
namespace J365TP\Reports;

use J365TP\Graph_Client;
use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight tenant snapshot for the dashboard landing (single step).
 */
final class Overview extends Report_Base {

	public function step( array $state, array $params, float $deadline ): array {
		$h   = [ 'ConsistencyLevel' => 'eventual' ];
		$out = $state['out'] ?? [ 'org' => [], 'counts' => [], 'domains' => [], 'licenses' => [] ];

		if ( ! isset( $state['out'] ) ) {
			try {
				$org        = $this->graph->get( '/v1.0/organization?$select=id,displayName,verifiedDomains,createdDateTime,countryLetterCode,tenantType,onPremisesSyncEnabled,onPremisesLastSyncDateTime' );
				$o          = $org['value'][0] ?? [];
				$out['org'] = [
					'id'            => (string) ( $o['id'] ?? '' ),
					'name'          => (string) ( $o['displayName'] ?? '' ),
					'country'       => (string) ( $o['countryLetterCode'] ?? '' ),
					'created'       => (string) ( $o['createdDateTime'] ?? '' ),
					'tenantType'    => (string) ( $o['tenantType'] ?? '' ),
					'adSync'        => array_key_exists( 'onPremisesSyncEnabled', $o ) ? (bool) $o['onPremisesSyncEnabled'] : null,
					'adLastSync'    => (string) ( $o['onPremisesLastSyncDateTime'] ?? '' ),
				];
				foreach ( (array) ( $o['verifiedDomains'] ?? [] ) as $d ) {
					$out['domains'][] = [ 'name' => (string) $d['name'], 'default' => (bool) ( $d['isDefault'] ?? false ), 'type' => (string) ( $d['type'] ?? '' ) ];
				}
				$out['sources']['organization'] = [ 'status' => $o ? 'ok' : 'unavailable', 'checked_at' => gmdate( 'c' ) ];
				if ( ! $o ) {
					$this->warn( $state, 'A organização não foi retornada pelo diretório.' );
				}
			} catch ( Graph_Exception $e ) {
				$this->optional_error( $state, 'Organização', $e );
				$out['sources']['organization'] = [ 'status' => 'unavailable', 'checked_at' => gmdate( 'c' ) ];
			}
			$state['out'] = $out;
		}

		$counts = [
			'users'     => '/v1.0/users/$count',
			'guests'    => '/v1.0/users/$count?$filter=userType eq \'Guest\'',
			'disabled'  => '/v1.0/users/$count?$filter=accountEnabled eq false',
			'groups'    => '/v1.0/groups/$count',
			'devices'   => '/v1.0/devices/$count',
			'apps'      => '/v1.0/applications/$count',
			'sps'       => '/v1.0/servicePrincipals/$count',
		];
		$requests = [];
		foreach ( $counts as $k => $url ) {
			$requests[] = [ 'id' => $k, 'method' => 'GET', 'url' => str_replace( '/v1.0', '', $url ), 'headers' => $h ];
		}
		$requests[] = [ 'id' => 'ca', 'method' => 'GET', 'url' => '/identity/conditionalAccess/policies?$select=id,state' ];
		// $count isn't documented for Intune collections; the overview singleton has the enrolled total.
		$requests[] = [ 'id' => 'intune', 'method' => 'GET', 'url' => '/deviceManagement/managedDeviceOverview' ];
		$requests[] = [ 'id' => 'skus', 'method' => 'GET', 'url' => '/subscribedSkus?$select=skuId,skuPartNumber,consumedUnits,prepaidUnits,capabilityStatus' ];

		$res = $this->fetch_batch( $state, '_overview_batch', $requests );
		$checked = gmdate( 'c' );
		// A retry can finish much later than the already completed members of the batch.
		foreach ( array_keys( $res ?? $state['_overview_batch'] ?? [] ) as $id ) {
			$state['checked'][ $id ] ??= $checked;
		}
		if ( null === $res ) {
			return [ 'state' => $state, 'progress' => 0, 'total' => 1, 'done' => false, 'message' => 'Carregando o resumo do tenant…' ];
		}
		foreach ( array_keys( $counts ) as $k ) {
			$r                    = $res[ $k ] ?? null;
			$out['counts'][ $k ]  = ( $r && 200 === $r['status'] ) ? Graph_Client::batch_int( $r['body'] ) : null;
		}
		$known   = count( array_filter( $out['counts'], static fn( $count ) => null !== $count ) );
		$directory_checks = array_intersect_key( $state['checked'], $counts );
		$out['sources']['directory'] = [ 'status' => count( $counts ) === $known ? 'ok' : ( $known ? 'partial' : 'unavailable' ), 'checked_at' => $directory_checks ? max( $directory_checks ) : $checked ];
		if ( count( $counts ) !== $known ) {
			$this->warn( $state, 'Algumas contagens do diretório não foram retornadas.' );
		}

		// The landing stays at one organization request plus one batch. A first page cannot certify a tenant total.
		$ca = $res['ca'] ?? [];
		$ca_status = self::collection_status( $ca );
		$out['sources']['ca'] = [ 'status' => $ca_status, 'checked_at' => $state['checked']['ca'] ?? $checked ];
		$policies = 'ok' === $ca_status ? $ca['body']['value'] : null;
		$out['counts']['caTotal'] = null === $policies ? null : count( $policies );
		foreach ( [ 'caEnabled' => 'enabled', 'caReportOnly' => 'enabledForReportingButNotEnforced', 'caDisabled' => 'disabled' ] as $key => $policy_state ) {
			$out['counts'][ $key ] = null === $policies ? null : count( array_filter( $policies, static fn( $policy ) => $policy_state === ( $policy['state'] ?? '' ) ) );
		}
		if ( 'partial' === $ca_status ) {
			$this->warn( $state, 'A consulta rápida recebeu uma página de políticas. Consulte o relatório de Acesso Condicional para os totais.' );
		} elseif ( 'unavailable' === $ca_status ) {
			$this->source_error( $state, 'Acesso Condicional', $ca );
		}

		$it = $res['intune'] ?? [];
		$devices = Graph_Client::entity( $it['body'] ?? null );
		$out['counts']['intuneDevices'] = 200 === ( $it['status'] ?? 0 ) ? Graph_Client::batch_int( $devices['enrolledDeviceCount'] ?? null ) : null;
		$out['sources']['intune'] = [ 'status' => null === $out['counts']['intuneDevices'] ? 'unavailable' : 'ok', 'checked_at' => $state['checked']['intune'] ?? $checked ];
		if ( null === $out['counts']['intuneDevices'] ) {
			$this->source_error( $state, 'Intune', $it );
		}

		$sk = $res['skus'] ?? [];
		$sku_status = self::collection_status( $sk );
		$out['sources']['licenses'] = [ 'status' => $sku_status, 'checked_at' => $state['checked']['skus'] ?? $checked ];
		if ( 'partial' === $sku_status ) {
			$this->warn( $state, 'A lista de produtos está parcial. Consulte o relatório de licenças para todas as assinaturas.' );
		} elseif ( 'unavailable' === $sku_status ) {
			$this->source_error( $state, 'Licenças', $sk );
		}
		if ( 'unavailable' !== $sku_status ) {
			foreach ( $sk['body']['value'] ?? [] as $s ) {
				$part = (string) ( $s['skuPartNumber'] ?? '' );
				$name = \J365TP\Sku_Catalog::name( (string) ( $s['skuId'] ?? '' ), $part );
				$out['licenses'][] = [
					'sku'      => $name,
					'part'     => $part,
					'kind'     => \J365TP\Sku_Catalog::kind( $part, $name ),
					'consumed' => Graph_Client::batch_int( $s['consumedUnits'] ?? null ),
					'enabled'  => Graph_Client::batch_int( $s['prepaidUnits']['enabled'] ?? null ),
					'status'   => (string) ( $s['capabilityStatus'] ?? '' ),
				];
			}
			usort( $out['licenses'], static fn( $a, $b ) => [ 'paid' !== $a['kind'], -$a['consumed'] ] <=> [ 'paid' !== $b['kind'], -$b['consumed'] ] );
		}

		$state['out'] = $out;
		return [ 'state' => $state, 'progress' => 1, 'total' => 1, 'done' => true, 'message' => 'Concluído' ];
	}

	private static function collection_status( array $response ): string {
		if ( 200 !== ( $response['status'] ?? 0 ) || ! is_array( $response['body']['value'] ?? null ) ) {
			return 'unavailable';
		}
		return empty( $response['body']['@odata.nextLink'] ) ? 'ok' : 'partial';
	}

	private function source_error( array &$state, string $label, array $response ): void {
		$status = (int) ( $response['status'] ?? 0 );
		if ( $status >= 400 ) {
			$this->optional_error( $state, $label, new Graph_Exception( $label, $status, (string) ( $response['body']['error']['code'] ?? '' ) ) );
		} else {
			$this->warn( $state, $label . ': Microsoft Graph' );
		}
	}

	public function finalize( array $state, array $params ): array {
		$out          = $state['out'];
		$out['_meta'] = [ 'rows' => 1 ];
		return $out;
	}
}
