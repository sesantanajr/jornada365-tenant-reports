<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identity report: every user with sign-in activity, license, sync source and admin roles.
 * The "inactive accounts (X days)" view is derived from this dataset in the UI.
 */
final class Identity extends Report_Base {

	private const USER_SELECT = 'id,displayName,userPrincipalName,mail,userType,accountEnabled,createdDateTime,onPremisesSyncEnabled,onPremisesLastSyncDateTime,assignedLicenses,jobTitle,department,companyName,externalUserState,lastPasswordChangeDateTime,usageLocation';

	public function normalize_params( array $params ): array {
		return [];
	}

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'premium' => true ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		// Phase 0: SKU catalog.
		if ( 0 === $state['phase'] ) {
			$skus = [];
			try {
				if ( ! $this->fetch_collection( $state, 'sku_pages', '/v1.0/subscribedSkus?$select=skuId,skuPartNumber,consumedUnits,prepaidUnits,capabilityStatus', $deadline ) ) {
					return [ 'state' => $state, 'progress' => 0, 'total' => 0, 'done' => false, 'message' => 'Carregando licenças…' ];
				}
				foreach ( $state['sku_pages'] as $sku ) {
					$id   = strtolower( (string) $sku['skuId'] );
					$part = (string) ( $sku['skuPartNumber'] ?? '' );
					$name = \J365TP\Sku_Catalog::name( $id, $part );
					$skus[ $id ] = [
						'part'     => $part,
						'name'     => $name,
						'kind'     => \J365TP\Sku_Catalog::kind( $part, $name ),
						'consumed' => (int) ( $sku['consumedUnits'] ?? 0 ),
						'enabled'  => (int) ( $sku['prepaidUnits']['enabled'] ?? 0 ),
						'status'   => (string) ( $sku['capabilityStatus'] ?? '' ),
					];
				}
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				$this->warn( $state, 'Licenças (subscribedSkus): ' . $e->getMessage() );
			}
			unset( $state['sku_pages'], $state['sku_pages_next'], $state['sku_pages_done'] );
			$state['skus']  = $skus;
			$state['phase'] = 1;
		}

		// Phase 1: users (with signInActivity when licensed).
		if ( 1 === $state['phase'] ) {
			$select = self::USER_SELECT . ( $state['premium'] ? ',signInActivity' : '' );
			$top    = $state['premium'] ? 500 : 999;
			$url    = '/v1.0/users?$select=' . $select . '&$top=' . $top;
			try {
				// Keep the field names only once in an encrypted checkpoint. Save after at
				// most two pages so a disconnected PHP worker has little work to replay.
				if ( ! isset( $state['userFields'] ) ) {
					$state['userFields'] = array_keys( $this->map_user( [ 'id' => '' ] ) );
				}
				$done = $this->fetch_collection( $state, 'users', $url, $deadline, [],
					fn( $user ) => array_values( $this->map_user( $user ) ), null, 2 );
			} catch ( Graph_Exception $e ) {
				if ( $state['premium'] && ( $e->is_license_gate() || $e->is_forbidden() || 400 === $e->getCode() ) && empty( $state['users'] ) ) {
					$this->warn( $state, 'Atividade de login (signInActivity) indisponível: requer Microsoft Entra ID P1/P2 e AuditLog.Read.All. Relatório de inatividade ficará vazio.' );
					$state['premium'] = false;
					unset( $state['users_next'], $state['users_done'] );
					$state['users'] = [];
					return [ 'state' => $state, 'progress' => 0, 'total' => 0, 'done' => false, 'message' => 'Repetindo sem signInActivity…' ];
				}
				throw $e;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['users'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d usuários carregados…', count( $state['users'] ) ) ];
			}
			$state['phase'] = 2;
		}

		// Phase 2: active directory role assignments (paged). directoryRoles?$expand=members is capped
		// at 20 members per role without nextLink, so it is only a fallback.
		if ( 2 === $state['phase'] ) {
			$admins = [];
			if ( empty( $state['legacy_roles'] ) ) {
				try {
					if ( ! $this->fetch_collection( $state, 'role_definitions', '/v1.0/roleManagement/directory/roleDefinitions?$select=id,displayName', $deadline )
						|| ! $this->fetch_collection( $state, 'role_assignments', '/v1.0/roleManagement/directory/roleAssignments?$select=principalId,roleDefinitionId', $deadline ) ) {
						$read = count( $state['role_definitions'] ) + count( $state['role_assignments'] ?? [] );
						return [ 'state' => $state, 'progress' => count( $state['users'] ) + $read, 'total' => 0, 'done' => false, 'message' => 'Carregando atribuições de funções…' ];
					}
					$names = [];
					foreach ( $state['role_definitions'] as $d ) {
						$names[ strtolower( (string) $d['id'] ) ] = (string) $d['displayName'];
					}
					foreach ( $state['role_assignments'] as $a ) {
						$pid  = (string) ( $a['principalId'] ?? '' );
						$name = $names[ strtolower( (string) ( $a['roleDefinitionId'] ?? '' ) ) ] ?? (string) ( $a['roleDefinitionId'] ?? '' );
						if ( '' !== $pid && ! in_array( $name, $admins[ $pid ] ?? [], true ) ) {
							$admins[ $pid ][] = $name;
						}
					}
				} catch ( Graph_Exception $e ) {
					if ( 'j365_token' === $e->graph_code() ) {
						throw $e;
					}
					$state['legacy_roles'] = true;
				}
				unset( $state['role_definitions'], $state['role_definitions_next'], $state['role_definitions_done'], $state['role_assignments'], $state['role_assignments_next'], $state['role_assignments_done'] );
			}
			if ( ! empty( $state['legacy_roles'] ) ) {
				try {
					if ( ! $this->fetch_collection( $state, 'directory_roles', '/v1.0/directoryRoles?$select=id,displayName,roleTemplateId&$expand=members($select=id)', $deadline ) ) {
						return [ 'state' => $state, 'progress' => count( $state['users'] ) + count( $state['directory_roles'] ), 'total' => 0, 'done' => false, 'message' => 'Carregando funções de diretório…' ];
					}
					foreach ( $state['directory_roles'] as $role ) {
						$members = (array) ( $role['members'] ?? [] );
						if ( count( $members ) >= 20 ) {
							$this->warn( $state, sprintf( 'A função "%s" tem 20 ou mais membros; a lista de admins pode estar incompleta.', (string) $role['displayName'] ) );
						}
						foreach ( $members as $m ) {
							$admins[ (string) $m['id'] ][] = (string) $role['displayName'];
						}
					}
				} catch ( Graph_Exception $e2 ) {
					if ( 'j365_token' === $e2->graph_code() ) {
						throw $e2;
					}
					$this->warn( $state, 'Funções de diretório: ' . $e2->getMessage() );
				}
				unset( $state['directory_roles'], $state['directory_roles_next'], $state['directory_roles_done'] );
			}
			$state['admins'] = $admins;
			$state['phase']  = 3;
		}

		return [ 'state' => $state, 'progress' => count( $state['users'] ), 'total' => count( $state['users'] ), 'done' => true, 'message' => 'Concluído' ];
	}

	public function map_user( array $u ): array {
		$sia = $u['signInActivity'] ?? [];
		return [
			'id'             => (string) $u['id'],
			'displayName'    => (string) ( $u['displayName'] ?? '' ),
			'upn'            => (string) ( $u['userPrincipalName'] ?? '' ),
			'mail'           => (string) ( $u['mail'] ?? '' ),
			'userType'       => (string) ( $u['userType'] ?? 'Member' ),
			'enabled'        => (bool) ( $u['accountEnabled'] ?? false ),
			'created'        => (string) ( $u['createdDateTime'] ?? '' ),
			'synced'         => ! empty( $u['onPremisesSyncEnabled'] ),
			'lastSync'       => (string) ( $u['onPremisesLastSyncDateTime'] ?? '' ),
			'skuIds'         => array_map( fn( $l ) => strtolower( (string) $l['skuId'] ), (array) ( $u['assignedLicenses'] ?? [] ) ),
			'jobTitle'       => (string) ( $u['jobTitle'] ?? '' ),
			'department'     => (string) ( $u['department'] ?? '' ),
			'company'        => (string) ( $u['companyName'] ?? '' ),
			'guestState'     => (string) ( $u['externalUserState'] ?? '' ),
			'pwdChanged'     => (string) ( $u['lastPasswordChangeDateTime'] ?? '' ),
			'usageLocation'  => (string) ( $u['usageLocation'] ?? '' ),
			'lastSignIn'     => self::ts( $sia['lastSignInDateTime'] ?? '' ),
			'lastNonInt'     => self::ts( $sia['lastNonInteractiveSignInDateTime'] ?? '' ),
			'lastSuccessful' => self::ts( $sia['lastSuccessfulSignInDateTime'] ?? '' ),
		];
	}

	public function finalize( array $state, array $params ): array {
		$skus   = $state['skus'] ?? [];
		$admins = $state['admins'] ?? [];
		$rows   = [];

		$summary = [
			'total'       => 0,
			'enabled'     => 0,
			'disabled'    => 0,
			'members'     => 0,
			'guests'      => 0,
			'synced'      => 0,
			'cloudOnly'   => 0,
			'licensed'    => 0,
			'unlicensed'  => 0,
			'paidLicensed' => 0,
			'admins'      => 0,
			'neverSignIn' => 0,
			'byLicense'   => [],
		];

		foreach ( $state['users'] ?? [] as $u ) {
			if ( array_is_list( $u ) ) {
				$u = array_combine( $state['userFields'], $u );
			}
			$lic_names = array_map( static fn( $id ) => $skus[ $id ]['name'] ?? \J365TP\Sku_Catalog::name( $id ), $u['skuIds'] );
			$lic_codes = array_map( static fn( $id ) => $skus[ $id ]['part'] ?? $id, $u['skuIds'] );
			$paid      = (bool) array_filter( $u['skuIds'], static fn( $id ) => 'paid' === ( $skus[ $id ]['kind'] ?? 'paid' ) );
			sort( $lic_names );
			sort( $lic_codes );

			$last = $u['lastSignIn'];
			foreach ( [ $u['lastNonInt'], $u['lastSuccessful'] ] as $candidate ) {
				if ( $candidate && ( ! $last || strtotime( $candidate ) > strtotime( $last ) ) ) {
					$last = $candidate;
				}
			}
			$days = self::days_since( $last );

			$row = [
				'id'                => $u['id'],
				'displayName'       => $u['displayName'],
				'upn'               => $u['upn'],
				'mail'              => $u['mail'],
				'userType'          => $u['userType'],
				'enabled'           => $u['enabled'],
				'source'            => $u['synced'] ? 'AD sincronizado' : 'Cloud-only',
				'created'           => $u['created'],
				'lastSignIn'        => $last,
				'lastInteractive'   => $u['lastSignIn'],
				'lastNonInteractive' => $u['lastNonInt'],
				'daysSinceSignIn'   => $days,
				'licenses'          => $lic_names,
				'licenseCodes'      => $lic_codes,
				'licensed'          => count( $lic_names ) > 0,
				'paidLicense'       => $paid,
				'adminRoles'        => $admins[ $u['id'] ] ?? [],
				'jobTitle'          => $u['jobTitle'],
				'department'        => $u['department'],
				'company'           => $u['company'],
				'guestState'        => $u['guestState'],
				'pwdChanged'        => $u['pwdChanged'],
				'usageLocation'     => $u['usageLocation'],
				'lastSync'          => $u['lastSync'],
			];
			$rows[] = $row;

			$summary['total']++;
			$summary[ $row['enabled'] ? 'enabled' : 'disabled' ]++;
			$summary[ 'Guest' === $row['userType'] ? 'guests' : 'members' ]++;
			$summary[ $u['synced'] ? 'synced' : 'cloudOnly' ]++;
			$summary[ $row['licensed'] ? 'licensed' : 'unlicensed' ]++;
			if ( $paid ) {
				$summary['paidLicensed']++;
			}
			if ( $row['adminRoles'] ) {
				$summary['admins']++;
			}
			if ( ! $last ) {
				$summary['neverSignIn']++;
			}
			foreach ( $lic_names as $ln ) {
				$summary['byLicense'][ $ln ] = ( $summary['byLicense'][ $ln ] ?? 0 ) + 1;
			}
		}
		arsort( $summary['byLicense'] );
		// Without signInActivity (no Entra ID P1/P2) "no sign-in date" means unknown, not "never signed in".
		$premium = (bool) ( $state['premium'] ?? false );
		if ( ! $premium ) {
			$summary['neverSignIn'] = null;
		}

		return [
			'rows'    => $rows,
			'summary' => $summary,
			'skus'    => array_values( $skus ),
			'_meta'   => [
				'rows'    => count( $rows ),
				'premium' => $premium,
			],
		];
	}
}
