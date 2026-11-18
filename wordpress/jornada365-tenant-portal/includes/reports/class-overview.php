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
				'adSync'        => (bool) ( $o['onPremisesSyncEnabled'] ?? false ),
				'adLastSync'    => (string) ( $o['onPremisesLastSyncDateTime'] ?? '' ),
			];
			foreach ( (array) ( $o['verifiedDomains'] ?? [] ) as $d ) {
				$out['domains'][] = [ 'name' => (string) $d['name'], 'default' => (bool) ( $d['isDefault'] ?? false ), 'type' => (string) ( $d['type'] ?? '' ) ];
			}
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				$this->warn( $state, 'Organização: ' . $e->getMessage() );
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
		if ( null === $res ) {
			return [ 'state' => $state, 'progress' => 0, 'total' => 1, 'done' => false, 'message' => 'Carregando o resumo do tenant…' ];
		}
		foreach ( array_keys( $counts ) as $k ) {
			$r                    = $res[ $k ] ?? null;
			$out['counts'][ $k ]  = ( $r && 200 === $r['status'] ) ? Graph_Client::batch_int( $r['body'] ) : null;
		}
		$ca = $res['ca'] ?? null;
		if ( $ca && 200 === $ca['status'] ) {
			$policies                     = $ca['body']['value'] ?? [];
			$out['counts']['caTotal']     = count( $policies );
			$out['counts']['caEnabled']   = count( array_filter( $policies, fn( $p ) => 'enabled' === ( $p['state'] ?? '' ) ) );
			$out['counts']['caReportOnly'] = count( array_filter( $policies, fn( $p ) => 'enabledForReportingButNotEnforced' === ( $p['state'] ?? '' ) ) );
		} else {
			$this->warn( $state, 'Acesso Condicional: ' . ( $ca['body']['error']['message'] ?? 'indisponível' ) );
		}
		$it = $res['intune'] ?? null;
		$out['counts']['intuneDevices'] = ( $it && 200 === $it['status'] && is_array( $it['body'] ) ) ? (int) ( $it['body']['enrolledDeviceCount'] ?? 0 ) : null;
		if ( ! $it || 200 !== $it['status'] ) {
			$this->warn( $state, 'Intune: ' . ( $it['body']['error']['message'] ?? 'indisponível ou não licenciado' ) );
		}
		$sk = $res['skus'] ?? null;
		if ( $sk && 200 === $sk['status'] ) {
			foreach ( $sk['body']['value'] ?? [] as $s ) {
				$part = (string) ( $s['skuPartNumber'] ?? '' );
				$name = \J365TP\Sku_Catalog::name( (string) ( $s['skuId'] ?? '' ), $part );
				$out['licenses'][] = [
					'sku'      => $name,
					'part'     => $part,
					'kind'     => \J365TP\Sku_Catalog::kind( $part, $name ),
					'consumed' => (int) ( $s['consumedUnits'] ?? 0 ),
					'enabled'  => (int) ( $s['prepaidUnits']['enabled'] ?? 0 ),
					'status'   => (string) ( $s['capabilityStatus'] ?? '' ),
				];
			}
			usort( $out['licenses'], static fn( $a, $b ) => [ 'paid' !== $a['kind'], -$a['consumed'] ] <=> [ 'paid' !== $b['kind'], -$b['consumed'] ] );
		}

		$state['out'] = $out;
		return [ 'state' => $state, 'progress' => 1, 'total' => 1, 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		$out          = $state['out'];
		$out['_meta'] = [ 'rows' => 1 ];
		return $out;
	}
}
