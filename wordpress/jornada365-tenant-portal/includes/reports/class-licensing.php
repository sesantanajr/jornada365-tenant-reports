<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Sku_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licensing & cost optimization: SKUs with Microsoft's product names, assignment, waste (disabled,
 * inactive or guest accounts holding paid licenses) and overlapping licenses (a user holding a product
 * that another of their products already includes). Free, trial and non-seat SKUs stay out of the
 * paid totals.
 */
final class Licensing extends Report_Base {

	private const STATUS = [
		'Enabled'   => 'Ativa',
		'Warning'   => 'Em aviso (expirando)',
		'Suspended' => 'Suspensa',
		'Deleted'   => 'Excluída',
		'LockedOut' => 'Bloqueada',
	];

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'premium' => true ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			$skus = [];
			foreach ( $this->graph->get_all( '/v1.0/subscribedSkus' ) as $sku ) {
				$id   = strtolower( (string) $sku['skuId'] );
				$part = (string) ( $sku['skuPartNumber'] ?? '' );
				$name = Sku_Catalog::name( $id, $part );
				$skus[ $id ] = [
					'skuId'     => $id,
					'part'      => $part,
					'name'      => $name,
					'kind'      => Sku_Catalog::kind( $part, $name ),
					'consumed'  => (int) ( $sku['consumedUnits'] ?? 0 ),
					'enabled'   => (int) ( $sku['prepaidUnits']['enabled'] ?? 0 ),
					'warning'   => (int) ( $sku['prepaidUnits']['warning'] ?? 0 ),
					'suspended' => (int) ( $sku['prepaidUnits']['suspended'] ?? 0 ),
					'status'    => (string) ( $sku['capabilityStatus'] ?? '' ),
					'appliesTo' => (string) ( $sku['appliesTo'] ?? '' ),
					'plans'     => array_values(
						array_filter(
							array_map(
								static fn( $p ) => Sku_Catalog::plan( (string) ( $p['servicePlanId'] ?? '' ), (string) ( $p['servicePlanName'] ?? '' ) ),
								(array) ( $sku['servicePlans'] ?? [] )
							)
						)
					),
				];
			}
			$state['skus']  = $skus;
			$state['phase'] = 1;
		}

		if ( 1 === $state['phase'] ) {
			$select = 'id,displayName,userPrincipalName,accountEnabled,assignedLicenses,userType' . ( $state['premium'] ? ',signInActivity' : '' );
			$top    = $state['premium'] ? 500 : 999;
			try {
				$done = $this->fetch_collection( $state, 'users', '/v1.0/users?$select=' . $select . '&$top=' . $top, $deadline, [], static fn( $u ) => self::map_user( $u ) );
			} catch ( Graph_Exception $e ) {
				if ( $state['premium'] && empty( $state['users'] ) && ( $e->is_license_gate() || $e->is_forbidden() || 400 === $e->getCode() ) ) {
					$state['premium'] = false;
					unset( $state['users'], $state['users_next'], $state['users_done'] );
					return [ 'state' => $state, 'progress' => 0, 'total' => 0, 'done' => false, 'message' => 'Repetindo sem signInActivity…' ];
				}
				throw $e;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['users'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d usuários…', count( $state['users'] ) ) ];
			}
			$state['phase'] = 2;
		}

		return [ 'state' => $state, 'progress' => count( $state['users'] ), 'total' => count( $state['users'] ), 'done' => true, 'message' => 'Concluído' ];
	}

	/** Only users holding licenses matter here; disabled service plans are kept as a count per SKU. */
	public static function map_user( array $u ): ?array {
		$lic = (array) ( $u['assignedLicenses'] ?? [] );
		if ( ! $lic ) {
			return null;
		}
		$ids = [];
		$dis = [];
		foreach ( $lic as $l ) {
			$id    = strtolower( (string) ( $l['skuId'] ?? '' ) );
			$ids[] = $id;
			if ( ! empty( $l['disabledPlans'] ) ) {
				$dis[ $id ] = count( (array) $l['disabledPlans'] );
			}
		}
		return [
			'displayName' => (string) ( $u['displayName'] ?? '' ),
			'upn'         => (string) ( $u['userPrincipalName'] ?? '' ),
			'enabled'     => (bool) ( $u['accountEnabled'] ?? false ),
			'userType'    => (string) ( $u['userType'] ?? 'Member' ),
			'skuIds'      => $ids,
			'disabled'    => $dis,
			'lastSignIn'  => self::ts( $u['signInActivity']['lastSignInDateTime'] ?? '' ),
		];
	}

	public function finalize( array $state, array $params ): array {
		// Normalize SKUs (also accepts the v2.1 state shape of jobs that were running during an upgrade).
		$skus = [];
		foreach ( (array) ( $state['skus'] ?? [] ) as $id => $sku ) {
			$part        = (string) ( $sku['part'] ?? $sku['name'] ?? $id );
			$name        = isset( $sku['part'] ) ? (string) $sku['name'] : Sku_Catalog::name( (string) $id, $part );
			$skus[ $id ] = array_merge(
				[ 'consumed' => 0, 'enabled' => 0, 'warning' => 0, 'suspended' => 0, 'status' => '', 'appliesTo' => '' ],
				(array) $sku,
				[ 'part' => $part, 'name' => $name, 'kind' => (string) ( $sku['kind'] ?? Sku_Catalog::kind( $part, $name ) ), 'plans' => (array) ( $sku['plans'] ?? $sku['servicePlans'] ?? [] ) ]
			);
		}
		$inactive = (int) \J365TP\Settings::get( 'inactive_days', 90 );
		$users    = array_values( array_filter( (array) ( $state['users'] ?? [] ) ) );
		$paid     = static fn( string $id ) => 'paid' === ( $skus[ $id ]['kind'] ?? 'paid' );
		$label    = static fn( string $id ) => $skus[ $id ]['name'] ?? Sku_Catalog::name( $id );

		$rows = [];
		foreach ( $skus as $id => $sku ) {
			$rows[] = [
				'name'        => $sku['name'],
				'part'        => $sku['part'],
				'skuId'       => $id,
				'kind'        => $sku['kind'],
				'consumed'    => $sku['consumed'],
				'enabled'     => $sku['enabled'],
				'available'   => $sku['enabled'] - $sku['consumed'],
				'warning'     => $sku['warning'],
				'suspended'   => $sku['suspended'],
				'status'      => $sku['status'],
				'statusLabel' => self::STATUS[ $sku['status'] ] ?? $sku['status'],
				'appliesTo'   => $sku['appliesTo'],
				'plans'       => count( $sku['plans'] ),
				'planNames'   => $sku['plans'],
				'pctUsed'     => $sku['enabled'] > 0 ? (int) round( 100 * $sku['consumed'] / $sku['enabled'] ) : 0,
			];
		}
		usort( $rows, static fn( $a, $b ) => [ 'paid' !== $a['kind'], -$a['consumed'] ] <=> [ 'paid' !== $b['kind'], -$b['consumed'] ] );

		$paid_rows = array_filter( $rows, static fn( $r ) => 'paid' === $r['kind'] );
		$summary   = [
			'skus'             => count( $paid_rows ),
			'freeSkus'         => count( $rows ) - count( $paid_rows ),
			'totalEnabled'     => array_sum( array_column( $paid_rows, 'enabled' ) ),
			'totalConsumed'    => array_sum( array_column( $paid_rows, 'consumed' ) ),
			'totalAvailable'   => 0,
			'nearCapacity'     => count( array_filter( $paid_rows, static fn( $r ) => $r['enabled'] > 0 && $r['available'] >= 0 && $r['pctUsed'] >= 95 ) ),
			'overAssigned'     => count( array_filter( $paid_rows, static fn( $r ) => $r['available'] < 0 ) ),
			'unused'           => count( array_filter( $paid_rows, static fn( $r ) => $r['enabled'] > 0 && 0 === $r['consumed'] ) ),
			'expiring'         => count( array_filter( $rows, static fn( $r ) => in_array( $r['status'], [ 'Warning', 'Suspended', 'LockedOut' ], true ) ) ),
			'disabledLicensed' => 0,
			'inactiveLicensed' => 0,
			'guestsLicensed'   => 0,
			'wasteSeats'       => 0,
			'overlapUsers'     => 0,
			'overlapSeats'     => 0,
		];
		$summary['totalAvailable'] = $summary['totalEnabled'] - $summary['totalConsumed'];

		$waste    = [];
		$overlaps = [];
		foreach ( $users as $u ) {
			$u['disabled'] = (array) ( $u['disabled'] ?? [] );
			$mine          = array_values( array_filter( (array) ( $u['skuIds'] ?? [] ), $paid ) );
			if ( ! $mine ) {
				continue;
			}
			$names = array_map( $label, $mine );
			$days  = $u['lastSignIn'] ? self::days_since( $u['lastSignIn'] ) : null;
			$flags = [];
			if ( ! $u['enabled'] ) {
				$flags[] = 'Desabilitado';
				$summary['disabledLicensed']++;
			}
			if ( 'Guest' === $u['userType'] ) {
				$flags[] = 'Convidado';
				$summary['guestsLicensed']++;
			}
			if ( $state['premium'] && ( null === $days || $days > $inactive ) ) {
				$flags[] = null === $days ? 'Nunca logou' : sprintf( 'Inativo %dd', $days );
				$summary['inactiveLicensed']++;
			}
			if ( $flags ) {
				$summary['wasteSeats'] += count( $names );
				$waste[] = [
					'displayName' => $u['displayName'],
					'upn'         => $u['upn'],
					'licenses'    => $names,
					'seats'       => count( $names ),
					'reason'      => implode( ' · ', $flags ),
					'enabled'     => $u['enabled'],
					'lastSignIn'  => $u['lastSignIn'],
				];
			}

			// Overlaps: a product the user also gets inside another of their products.
			$extra = [];
			foreach ( $mine as $inner ) {
				foreach ( $mine as $outer ) {
					if ( $inner !== $outer && Sku_Catalog::includes( $skus[ $outer ]['part'] ?? '', $skus[ $inner ]['part'] ?? '' ) ) {
						$extra[] = [
							'redundant' => $label( $inner ),
							'coveredBy' => $label( $outer ),
							'check'     => isset( $u['disabled'][ $outer ] ), // service plans disabled in the larger product
						];
						break;
					}
				}
			}
			if ( $extra ) {
				$summary['overlapUsers']++;
				$summary['overlapSeats'] += count( $extra );
				$overlaps[] = [
					'displayName' => $u['displayName'],
					'upn'         => $u['upn'],
					'redundant'   => array_column( $extra, 'redundant' ),
					'coveredBy'   => array_values( array_unique( array_column( $extra, 'coveredBy' ) ) ),
					'seats'       => count( $extra ),
					'check'       => in_array( true, array_column( $extra, 'check' ), true ),
					'enabled'     => $u['enabled'],
				];
			}
		}
		usort( $waste, static fn( $a, $b ) => $b['seats'] <=> $a['seats'] );
		usort( $overlaps, static fn( $a, $b ) => $b['seats'] <=> $a['seats'] );

		return [
			'rows'     => $rows,
			'waste'    => $waste,
			'overlaps' => $overlaps,
			'summary'  => $summary,
			'_meta'    => [ 'rows' => count( $rows ), 'premium' => (bool) $state['premium'], 'inactive_days' => $inactive, 'catalog' => Sku_Catalog::version() ],
		];
	}
}
