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
				$state['policies'] = $this->graph->get_all( '/v1.0/identity/conditionalAccess/policies' );
			} catch ( Graph_Exception $e ) {
				if ( $e->is_forbidden() || $e->is_license_gate() ) {
					throw new Graph_Exception( 'Acesso Condicional indisponível: ' . $e->getMessage() . ' (requer Policy.Read.All e Entra ID P1).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}
			try {
				$state['locations'] = [];
				foreach ( $this->graph->get_all( '/v1.0/identity/conditionalAccess/namedLocations?$select=id,displayName' ) as $loc ) {
					$state['locations'][ strtolower( (string) $loc['id'] ) ] = (string) $loc['displayName'];
				}
			} catch ( Graph_Exception $e ) {
				$this->warn( $state, 'Named locations: ' . $e->getMessage() );
			}
			try {
				$state['strengths'] = [];
				// v1.0 path is /policies/authenticationStrengthPolicies (only $filter is documented).
				foreach ( $this->graph->get_all( '/v1.0/policies/authenticationStrengthPolicies' ) as $s ) {
					$state['strengths'][ strtolower( (string) $s['id'] ) ] = (string) $s['displayName'];
				}
			} catch ( Graph_Exception $e ) {
				$this->warn( $state, 'Authentication strengths: ' . $e->getMessage() );
			}
			try {
				$state['roles'] = [];
				foreach ( $this->graph->get_all( '/v1.0/directoryRoleTemplates' ) as $r ) { // no OData query options supported
					$state['roles'][ strtolower( (string) $r['id'] ) ] = (string) $r['displayName'];
				}
			} catch ( Graph_Exception $e ) {
				$this->warn( $state, 'Templates de função: ' . $e->getMessage() );
			}
			$state['phase'] = 1;
		}

		if ( 1 === $state['phase'] ) {
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

			$state['names'] = [];
			foreach ( array_chunk( array_keys( $principals ), 1000 ) as $chunk ) {
				try {
					$res = $this->graph->get_directory_objects( $chunk, [ 'user', 'group', 'directoryRole', 'servicePrincipal' ] );
					foreach ( $res['value'] ?? [] as $o ) {
						$type                                       = str_replace( '#microsoft.graph.', '', (string) ( $o['@odata.type'] ?? '' ) );
						$state['names'][ strtolower( (string) $o['id'] ) ] = (string) ( $o['displayName'] ?? $o['userPrincipalName'] ?? $o['id'] ) . ( 'user' === $type && ! empty( $o['userPrincipalName'] ) ? ' <' . $o['userPrincipalName'] . '>' : '' );
					}
				} catch ( Graph_Exception $e ) {
					$this->warn( $state, 'Resolução de nomes (getByIds): ' . $e->getMessage() );
				}
			}

			$state['appnames'] = [];
			foreach ( array_chunk( array_keys( $apps ), 15 ) as $chunk ) {
				$filter = implode( ',', array_map( fn( $a ) => "'" . $a . "'", $chunk ) );
				try {
					$res = $this->graph->get( '/v1.0/servicePrincipals?$select=appId,displayName&$filter=appId in (' . rawurlencode( $filter ) . ')' );
					foreach ( $res['value'] ?? [] as $sp ) {
						$state['appnames'][ strtolower( (string) $sp['appId'] ) ] = (string) $sp['displayName'];
					}
				} catch ( Graph_Exception $e ) {
					$this->warn( $state, 'Resolução de aplicativos: ' . $e->getMessage() );
				}
			}
			$state['phase'] = 2;
		}

		$n = count( $state['policies'] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $n, 'done' => true, 'message' => 'Concluído' ];
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
