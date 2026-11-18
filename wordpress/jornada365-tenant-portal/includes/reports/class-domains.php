<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domains: verification, federation, default/initial, supported services.
 */
final class Domains extends Report_Base {

	public function step( array $state, array $params, float $deadline ): array {
		$rows = [];
		foreach ( $this->graph->get_all( '/v1.0/domains' ) as $d ) {
			$rows[] = [
				'name'            => (string) $d['id'],
				'isDefault'       => (bool) ( $d['isDefault'] ?? false ),
				'isInitial'       => (bool) ( $d['isInitial'] ?? false ),
				'isVerified'      => (bool) ( $d['isVerified'] ?? false ),
				'isRoot'          => (bool) ( $d['isRoot'] ?? false ),
				'authType'        => (string) ( $d['authenticationType'] ?? '' ),
				'supportedServices' => array_values( (array) ( $d['supportedServices'] ?? [] ) ),
				'adminManaged'    => (bool) ( $d['isAdminManaged'] ?? false ),
				'passwordValidity' => (int) ( $d['passwordValidityPeriodInDays'] ?? 0 ),
			];
		}
		usort( $rows, fn( $a, $b ) => ( $b['isDefault'] <=> $a['isDefault'] ) ?: strcmp( $a['name'], $b['name'] ) );

		$state['rows']    = $rows;
		$state['summary'] = [
			'total'     => count( $rows ),
			'verified'  => count( array_filter( $rows, fn( $r ) => $r['isVerified'] ) ),
			'federated' => count( array_filter( $rows, fn( $r ) => 'Federated' === $r['authType'] ) ),
			'managed'   => count( array_filter( $rows, fn( $r ) => 'Managed' === $r['authType'] ) ),
			'unverified' => count( array_filter( $rows, fn( $r ) => ! $r['isVerified'] ) ),
		];
		return [ 'state' => $state, 'progress' => count( $rows ), 'total' => count( $rows ), 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		return [ 'rows' => $state['rows'], 'summary' => $state['summary'], '_meta' => [ 'rows' => count( $state['rows'] ) ] ];
	}
}
