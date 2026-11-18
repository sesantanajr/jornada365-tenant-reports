<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional Access policies with every GUID resolved to a display name.
 */
final class Conditional_Access extends Report_Base {

	private const WELL_KNOWN_APPS = [
		'all'                                  => 'Todos os aplicativos na nuvem',
		'office365'                            => 'Office 365',
		'microsoftadminportals'                => 'Microsoft Admin Portals',
		'none'                                 => 'Nenhum',
		'00000002-0000-0ff1-ce00-000000000000' => 'Office 365 Exchange Online',
		'00000003-0000-0ff1-ce00-000000000000' => 'Office 365 SharePoint Online',
		'00000003-0000-0000-c000-000000000000' => 'Microsoft Graph',
		'797f4846-ba00-4fd7-ba43-dac1f8f63013' => 'Windows Azure Service Management API',
		'0000000a-0000-0000-c000-000000000000' => 'Microsoft Intune',
		'd4ebce55-015a-49b5-a083-c84d1797ae8c' => 'Microsoft Intune Enrollment',
		'cc15fd57-2c6c-4117-a88c-83b1d56b4bbe' => 'Microsoft Teams Services',
		'1fec8e78-bce4-4aaf-ab1b-5451cc387264' => 'Microsoft Teams',
		'c44b4083-3bb0-49c1-b47d-974e53cbdf3c' => 'Azure Portal',
		'0000000c-0000-0000-c000-000000000000' => 'Microsoft App Access Panel',
	];

	private const WELL_KNOWN_PRINCIPALS = [
		'all'                 => 'Todos os usuários',
		'none'                => 'Nenhum',
		'guestsorexternalusers' => 'Convidados ou usuários externos',
		'alltrusted'          => 'Todos os locais confiáveis',
		'allcompliantnetwork' => 'Rede compatível (Global Secure Access)',
	];

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			try {
				if ( ! $this->fetch_collection( $state, 'policies', '/v1.0/identity/conditionalAccess/policies', $deadline ) ) {
					return $this->pending( $state, 'Carregando políticas de Acesso Condicional…' );
				}
			} catch ( Graph_Exception $e ) {
				if ( $e->is_forbidden() || $e->is_license_gate() ) {
					throw new Graph_Exception( 'Acesso Condicional indisponível: ' . $e->getMessage() . ' (requer Policy.Read.All e Entra ID P1).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}

			$lookups = [
				'locations' => [ '/v1.0/identity/conditionalAccess/namedLocations?$select=id,displayName', 'Locais nomeados' ],
				'strengths' => [ '/v1.0/policies/authenticationStrengthPolicies', 'Intensidades de autenticação' ],
				'roles'     => [ '/v1.0/directoryRoleTemplates', 'Modelos de função' ],
			];
			foreach ( $lookups as $key => [ $url, $label ] ) {
				if ( isset( $state[ $key ] ) ) {
					continue;
				}
				try {
					if ( ! $this->fetch_collection( $state, 'lookup_rows', $url, $deadline, [], static fn( $row ) => [ 'id' => strtolower( (string) $row['id'] ), 'name' => (string) ( $row['displayName'] ?? $row['id'] ) ] ) ) {
						return $this->pending( $state, 'Carregando ' . $label . '…' );
					}
				} catch ( Graph_Exception $e ) {
					$this->optional_error( $state, $label, $e );
				}
				$state[ $key ] = array_column( $state['lookup_rows'], 'name', 'id' );
				unset( $state['lookup_rows'], $state['lookup_rows_next'], $state['lookup_rows_done'] );
			}
			$state['phase'] = 1;
		}

		if ( ! isset( $state['principal_ids'] ) ) {
			// Collect GUIDs for users/groups and app IDs.
			$principals = [];
			$apps       = [];
			foreach ( $state['policies'] as $p ) {
				$c = $p['conditions'] ?? [];
				foreach ( [ 'includeUsers', 'excludeUsers', 'includeGroups', 'excludeGroups' ] as $k ) {
					foreach ( (array) ( $c['users'][ $k ] ?? [] ) as $id ) {
						if ( self::is_guid( $id ) ) {
							$principals[ strtolower( $id ) ] = true;
						}
					}
				}
				foreach ( [ 'includeApplications', 'excludeApplications' ] as $k ) {
					foreach ( (array) ( $c['applications'][ $k ] ?? [] ) as $id ) {
						if ( self::is_guid( $id ) && ! isset( self::WELL_KNOWN_APPS[ strtolower( $id ) ] ) ) {
							$apps[ strtolower( $id ) ] = true;
						}
					}
				}
			}

			$state['principal_ids'] = array_keys( $principals );
			$state['app_ids']       = array_keys( $apps );
			$state['principal_idx'] = 0;
			$state['app_idx']       = 0;
			$state['names']         = [];
			$state['appnames']      = [];
		}

		// Save each completed name lookup. Tenants with many targeted apps can span several steps.
		try {
			while ( $state['principal_idx'] < count( $state['principal_ids'] ) && ! $this->out_of_time( $deadline ) ) {
				$chunk = array_slice( $state['principal_ids'], $state['principal_idx'], 1000 );
				try {
					$res = $this->graph->get_directory_objects( $chunk, [ 'user', 'group', 'directoryRole', 'servicePrincipal' ] );
					foreach ( $res['value'] ?? [] as $o ) {
						$name = (string) ( $o['displayName'] ?? $o['userPrincipalName'] ?? $o['id'] );
						if ( '#microsoft.graph.user' === ( $o['@odata.type'] ?? '' ) && ! empty( $o['userPrincipalName'] ) ) {
							$name .= ' <' . $o['userPrincipalName'] . '>';
						}
						$state['names'][ strtolower( (string) $o['id'] ) ] = $name;
					}
				} catch ( Graph_Exception $e ) {
					$this->optional_error( $state, 'Resolução de nomes (getByIds)', $e );
				}
				$state['principal_idx'] += count( $chunk );
			}
			while ( $state['app_idx'] < count( $state['app_ids'] ) && ! $this->out_of_time( $deadline ) ) {
				$chunk  = array_slice( $state['app_ids'], $state['app_idx'], 15 );
				$filter = implode( ',', array_map( static fn( $id ) => "'" . $id . "'", $chunk ) );
				try {
					$res = $this->graph->get( '/v1.0/servicePrincipals?$select=appId,displayName&$filter=appId in (' . rawurlencode( $filter ) . ')' );
					foreach ( $res['value'] ?? [] as $sp ) {
						$state['appnames'][ strtolower( (string) $sp['appId'] ) ] = (string) $sp['displayName'];
					}
				} catch ( Graph_Exception $e ) {
					$this->optional_error( $state, 'Resolução de aplicativos', $e );
				}
				$state['app_idx'] += count( $chunk );
			}
		} catch ( \J365TP\Graph_Throttled $error ) {
			$this->pause_read( $state, $error->retry_after, $error->reason );
		}
		if ( $state['principal_idx'] < count( $state['principal_ids'] ) || $state['app_idx'] < count( $state['app_ids'] ) ) {
			return $this->pending( $state, 'Resolvendo nomes nas políticas…' );
		}

		$n = count( $state['policies'] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $n, 'done' => true, 'message' => 'Concluído' ];
	}


	private function pending( array $state, string $message ): array {
		$progress = count( $state['policies'] ?? [] ) + count( $state['lookup_rows'] ?? [] )
			+ count( $state['locations'] ?? [] ) + count( $state['strengths'] ?? [] ) + count( $state['roles'] ?? [] )
			+ ( $state['principal_idx'] ?? 0 ) + ( $state['app_idx'] ?? 0 );
		return [ 'state' => $state, 'progress' => $progress, 'total' => 0, 'done' => false, 'message' => $message ];
	}

	public function finalize( array $state, array $params ): array {
		$rows    = [];
		$summary = [ 'total' => 0, 'enabled' => 0, 'reportOnly' => 0, 'disabled' => 0, 'requireMfa' => 0, 'authStrength' => 0, 'blockLegacy' => 0, 'requireCompliant' => 0, 'noExclusions' => 0 ];

		foreach ( $state['policies'] as $p ) {
			$c  = $p['conditions'] ?? [];
			$g  = $p['grantControls'] ?? [];
			$s  = $p['sessionControls'] ?? [];
			$st = (string) ( $p['state'] ?? '' );

			$built_in = (array) ( $g['builtInControls'] ?? [] );
			$strength = $g['authenticationStrength']['id'] ?? null;
			$controls = array_map( fn( $b ) => self::label_control( $b ), $built_in );
			if ( $strength ) {
				$controls[] = 'Authentication Strength: ' . ( $state['strengths'][ strtolower( $strength ) ] ?? ( $g['authenticationStrength']['displayName'] ?? $strength ) );
			}
			foreach ( (array) ( $g['termsOfUse'] ?? [] ) as $t ) {
				$controls[] = 'Termos de uso';
			}

			$session = [];
			if ( ! empty( $s['signInFrequency']['isEnabled'] ) ) {
				$sf        = $s['signInFrequency'];
				$session[] = 'Sign-in frequency: ' . ( 'everyTime' === ( $sf['frequencyInterval'] ?? '' ) ? 'sempre' : ( $sf['value'] ?? '' ) . ' ' . ( $sf['type'] ?? '' ) );
			}
			if ( ! empty( $s['persistentBrowser']['isEnabled'] ) ) {
				$session[] = 'Persistent browser: ' . ( $s['persistentBrowser']['mode'] ?? '' );
			}
			if ( ! empty( $s['cloudAppSecurity']['isEnabled'] ) ) {
				$session[] = 'Defender for Cloud Apps: ' . ( $s['cloudAppSecurity']['cloudAppSecurityType'] ?? '' );
			}
			if ( ! empty( $s['applicationEnforcedRestrictions']['isEnabled'] ) ) {
				$session[] = 'App enforced restrictions';
			}
			if ( ! empty( $s['disableResilienceDefaults'] ) ) {
				$session[] = 'Resilience defaults desativado';
			}

			$users = $c['users'] ?? [];
			$row   = [
				'id'             => (string) $p['id'],
				'name'           => (string) ( $p['displayName'] ?? '' ),
				'state'          => $st,
				'stateLabel'     => [ 'enabled' => 'Ativada', 'disabled' => 'Desativada', 'enabledForReportingButNotEnforced' => 'Somente relatório' ][ $st ] ?? $st,
				'created'        => (string) ( $p['createdDateTime'] ?? '' ),
				'modified'       => (string) ( $p['modifiedDateTime'] ?? '' ),
				'includeUsers'   => $this->names( $users['includeUsers'] ?? [], $state ),
				'excludeUsers'   => $this->names( $users['excludeUsers'] ?? [], $state ),
				'includeGroups'  => $this->names( $users['includeGroups'] ?? [], $state ),
				'excludeGroups'  => $this->names( $users['excludeGroups'] ?? [], $state ),
				'includeRoles'   => $this->roles( $users['includeRoles'] ?? [], $state ),
				'excludeRoles'   => $this->roles( $users['excludeRoles'] ?? [], $state ),
				'includeGuests'  => $this->guests( $users['includeGuestsOrExternalUsers'] ?? null ),
				'excludeGuests'  => $this->guests( $users['excludeGuestsOrExternalUsers'] ?? null ),
				'includeApps'    => $this->apps( $c['applications']['includeApplications'] ?? [], $state ),
				'excludeApps'    => $this->apps( $c['applications']['excludeApplications'] ?? [], $state ),
				'userActions'    => (array) ( $c['applications']['includeUserActions'] ?? [] ),
				'authContexts'   => (array) ( $c['applications']['includeAuthenticationContextClassReferences'] ?? [] ),
				'clientAppTypes' => (array) ( $c['clientAppTypes'] ?? [] ),
				'platformsInc'   => (array) ( $c['platforms']['includePlatforms'] ?? [] ),
				'platformsExc'   => (array) ( $c['platforms']['excludePlatforms'] ?? [] ),
				'locationsInc'   => $this->locations( $c['locations']['includeLocations'] ?? [], $state ),
				'locationsExc'   => $this->locations( $c['locations']['excludeLocations'] ?? [], $state ),
				'signInRisk'     => (array) ( $c['signInRiskLevels'] ?? [] ),
				'userRisk'       => (array) ( $c['userRiskLevels'] ?? [] ),
				'insiderRisk'    => (string) ( $c['insiderRiskLevels'] ?? '' ),
				'deviceFilter'   => isset( $c['devices']['deviceFilter'] ) ? ( $c['devices']['deviceFilter']['mode'] . ': ' . $c['devices']['deviceFilter']['rule'] ) : '',
				'grantOperator'  => (string) ( $g['operator'] ?? '' ),
				'grantControls'  => $controls,
				'sessionControls' => $session,
			];
			$rows[] = $row;

			$summary['total']++;
			$summary[ [ 'enabled' => 'enabled', 'disabled' => 'disabled', 'enabledForReportingButNotEnforced' => 'reportOnly' ][ $st ] ?? 'disabled' ]++;
			if ( 'enabled' === $st ) {
				if ( in_array( 'mfa', $built_in, true ) ) {
					$summary['requireMfa']++;
				}
				if ( $strength ) {
					$summary['authStrength']++;
				}
				if ( in_array( 'compliantDevice', $built_in, true ) ) {
					$summary['requireCompliant']++;
				}
				$cat = (array) ( $c['clientAppTypes'] ?? [] );
				if ( in_array( 'block', $built_in, true ) && ( in_array( 'exchangeActiveSync', $cat, true ) || in_array( 'other', $cat, true ) ) ) {
					$summary['blockLegacy']++;
				}
				if ( empty( $row['excludeUsers'] ) && empty( $row['excludeGroups'] ) && empty( $row['excludeRoles'] ) ) {
					$summary['noExclusions']++;
				}
			}
		}
		usort( $rows, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ) ] ];
	}

	private function names( array $ids, array $state ): array {
		return array_map( fn( $id ) => self::WELL_KNOWN_PRINCIPALS[ strtolower( $id ) ] ?? ( $state['names'][ strtolower( $id ) ] ?? $id ), $ids );
	}

	private function roles( array $ids, array $state ): array {
		return array_map( fn( $id ) => $state['roles'][ strtolower( $id ) ] ?? $id, $ids );
	}

	private function apps( array $ids, array $state ): array {
		return array_map( fn( $id ) => self::WELL_KNOWN_APPS[ strtolower( $id ) ] ?? ( $state['appnames'][ strtolower( $id ) ] ?? $id ), $ids );
	}

	private function locations( array $ids, array $state ): array {
		return array_map( fn( $id ) => self::WELL_KNOWN_PRINCIPALS[ strtolower( $id ) ] ?? ( $state['locations'][ strtolower( $id ) ] ?? ( 'all' === strtolower( $id ) ? 'Todos os locais' : $id ) ), $ids );
	}

	private function guests( ?array $g ): string {
		if ( ! $g ) {
			return '';
		}
		return (string) ( $g['guestOrExternalUserTypes'] ?? '' ) . ( ! empty( $g['externalTenants']['membershipKind'] ) ? ' (' . $g['externalTenants']['membershipKind'] . ')' : '' );
	}

	private static function label_control( string $c ): string {
		return [
			'block'                        => 'Bloquear',
			'mfa'                          => 'Exigir MFA',
			'compliantDevice'              => 'Dispositivo em conformidade',
			'domainJoinedDevice'           => 'Hybrid Entra joined',
			'approvedApplication'          => 'Aplicativo cliente aprovado',
			'compliantApplication'         => 'App protection policy',
			'passwordChange'               => 'Troca de senha',
			'unknownFutureValue'           => 'Desconhecido',
		][ $c ] ?? $c;
	}

	private static function is_guid( $v ): bool {
		return is_string( $v ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v );
	}
}
