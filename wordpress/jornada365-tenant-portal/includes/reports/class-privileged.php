<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privileged access: active (standing) vs PIM-eligible role assignments, resolved to
 * principals and roles, flagging standing admins and (optionally) admins without MFA.
 */
final class Privileged extends Report_Base {

	/**
	 * Built-in roles carrying the PRIVILEGED label in Microsoft Entra (template IDs from
	 * learn.microsoft.com/entra/identity/role-based-access-control/permissions-reference).
	 * unifiedRoleDefinition.isPrivileged exists only in /beta, so v1.0 relies on this list and the
	 * beta flag is used only as an optional enrichment for custom roles.
	 */
	private const PRIVILEGED = [
		'db506228-d27e-4b7d-95e5-295956d6615f' => 'Agent ID Administrator',
		'd2562ede-74db-457e-a7b6-544e236ebb61' => 'AI Administrator',
		'1fe13547-53f6-408d-ac04-7f8eed167b38' => 'AI Reader',
		'9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator',
		'cf1c38e5-3621-4004-a7cb-879624dced7c' => 'Application Developer',
		'ecb2c6bf-0ab6-418e-bd87-7986f8d63bbe' => 'Attribute Provisioning Administrator',
		'422218e4-db15-4ef9-bbe0-8afb41546d79' => 'Attribute Provisioning Reader',
		'c4e39bd9-1100-46d3-8c65-fb160da0071f' => 'Authentication Administrator',
		'25a516ed-2fa0-40ea-a2d0-12923a21473a' => 'Authentication Extensibility Administrator',
		'0b00bede-4072-4d22-b441-e7df02a1ef63' => 'Authentication Extensibility Password Administrator',
		'aaf43236-0c0d-4d5f-883a-6955382ac081' => 'B2C IEF Keyset Administrator',
		'158c047a-c907-4556-b7ef-446551a6b5f7' => 'Cloud Application Administrator',
		'7698a772-787b-4ac8-901f-60d6b08affd2' => 'Cloud Device Administrator',
		'b1be1c3e-b65d-4f19-8427-f6fa0d97feb9' => 'Conditional Access Administrator',
		'9360feb5-f418-4baa-8175-e2a00bac4301' => 'Directory Writers',
		'8329153b-31d0-4727-b945-745eb3bc5f31' => 'Domain Name Administrator',
		'58f930cc-fcf4-4152-852c-1d7dbf502139' => 'Entra SOC Identity Responder',
		'be2f45a1-457d-42af-a067-6ec1fa63bc45' => 'External Identity Provider Administrator',
		'62e90394-69f5-4237-9190-012177145e10' => 'Global Administrator',
		'f2ef992c-3afb-46b9-b7cf-a126ee74c451' => 'Global Reader',
		'729827e3-9c14-49f7-bb1b-9608f156bbb8' => 'Helpdesk Administrator',
		'8ac3fc64-6eca-42ea-9e69-59f4c7b60eb2' => 'Hybrid Identity Administrator',
		'45d8d3c5-c802-45c6-b32a-1d70b5e1e86e' => 'Identity Governance Administrator',
		'3a2c62db-5318-420d-8d74-23affee5d9d5' => 'Intune Administrator',
		'59d46f88-662b-457b-bceb-5c3809e5908f' => 'Lifecycle Workflows Administrator',
		'4ba39ca4-527c-499a-b93d-d9b492c50246' => 'Partner Tier1 Support',
		'e00e864a-17c5-4a4b-9c06-f5b95a8d5bd8' => 'Partner Tier2 Support',
		'966707d0-3269-4727-9be2-8c3a10f19b9d' => 'Password Administrator',
		'7be44c8a-adaf-4e2a-84d6-ab2649e08a13' => 'Privileged Authentication Administrator',
		'e8611ab8-c189-46e8-94e1-60213ab1f814' => 'Privileged Role Administrator',
		'194ae4cb-b126-40b2-bd5b-6091b380977d' => 'Security Administrator',
		'5f2222b1-57c3-48ba-8ad5-d4759f1fde6f' => 'Security Operator',
		'5d6b6bb7-de71-4623-b4af-96380a352509' => 'Security Reader',
		'1981f584-96e9-4a6f-95b0-f522373f8fae' => 'Tenant Governance Administrator',
		'fe930be7-5e62-47db-91af-98c3a49a38b1' => 'User Administrator',
	];

	public static function is_privileged_template( string $id ): bool {
		return isset( self::PRIVILEGED[ strtolower( $id ) ] );
	}

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'active' => [], 'eligible' => [], 'roles' => [], 'principals' => [], 'pim' => true ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			// Role definitions (built-in + custom). For built-in roles id == templateId.
			if ( ! $this->fetch_collection( $state, 'role_definitions', '/v1.0/roleManagement/directory/roleDefinitions?$select=id,displayName,isBuiltIn,templateId', $deadline ) ) {
				return [ 'state' => $state, 'progress' => count( $state['role_definitions'] ), 'total' => 0, 'done' => false, 'message' => 'Carregando definições de funções…' ];
			}
			foreach ( $state['role_definitions'] as $d ) {
				$id  = strtolower( (string) $d['id'] );
				$tpl = strtolower( (string) ( $d['templateId'] ?? '' ) );
				$state['roles'][ $id ] = [
					'name'       => (string) $d['displayName'],
					'builtIn'    => (bool) ( $d['isBuiltIn'] ?? true ),
					'privileged' => self::is_privileged_template( $id ) || ( '' !== $tpl && self::is_privileged_template( $tpl ) ),
				];
			}
			// Optional enrichment: /beta flags custom roles that contain privileged permissions.
			try {
				if ( ! $this->fetch_collection( $state, 'privileged_definitions', '/beta/roleManagement/directory/roleDefinitions?$filter=' . rawurlencode( 'isPrivileged eq true' ) . '&$select=id', $deadline ) ) {
					return [ 'state' => $state, 'progress' => count( $state['role_definitions'] ) + count( $state['privileged_definitions'] ), 'total' => 0, 'done' => false, 'message' => 'Verificando funções privilegiadas…' ];
				}
				foreach ( $state['privileged_definitions'] as $d ) {
					$id = strtolower( (string) ( $d['id'] ?? '' ) );
					if ( isset( $state['roles'][ $id ] ) ) {
						$state['roles'][ $id ]['privileged'] = true;
					}
				}
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				// Not required: the documented built-in list above already covers built-in roles.
			}
			unset( $state['role_definitions'], $state['role_definitions_next'], $state['role_definitions_done'], $state['privileged_definitions'], $state['privileged_definitions_next'], $state['privileged_definitions_done'] );
			$state['phase'] = 1;
		}

		if ( 1 === $state['phase'] ) {
			// Active assignments. PIM schedule instances need Entra ID P2/Governance; without them,
			// fall back to plain role assignments (every assignment is standing in that case).
			try {
				$done = $state['pim']
					? $this->fetch_collection( $state, 'active', '/v1.0/roleManagement/directory/roleAssignmentScheduleInstances?$expand=principal', $deadline, [], fn( $a ) => $this->map_assignment( $a, 'active' ) )
					: $this->fetch_collection( $state, 'active', '/v1.0/roleManagement/directory/roleAssignments?$expand=principal', $deadline, [], fn( $a ) => $this->map_assignment( $a, 'active' ) );
			} catch ( Graph_Exception $e ) {
				if ( ! $state['pim'] || ! empty( $state['active'] ) || ! ( $e->is_license_gate() || $e->is_forbidden() || 400 === $e->getCode() ) ) {
					throw $e;
				}
				$this->warn( $state, 'PIM indisponível neste tenant (requer Entra ID P2 ou Governance) — exibindo as atribuições diretas.' );
				$state['pim']    = false;
				$state['active'] = [];
				unset( $state['active_next'], $state['active_done'] );
				return [ 'state' => $state, 'progress' => 0, 'total' => 0, 'done' => false, 'message' => 'Atribuições de funções…' ];
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['active'] ), 'total' => 0, 'done' => false, 'message' => 'Atribuições ativas…' ];
			}
			$state['phase'] = $state['pim'] ? 2 : 3;
		}

		if ( 2 === $state['phase'] ) {
			// PIM-eligible assignments.
			try {
				$done = $this->fetch_collection( $state, 'eligible', '/v1.0/roleManagement/directory/roleEligibilityScheduleInstances?$expand=principal', $deadline, [], fn( $a ) => $this->map_assignment( $a, 'eligible' ) );
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				$this->warn( $state, 'Atribuições elegíveis (PIM) indisponíveis: ' . $e->getMessage() . ' (requer Entra ID P2).' );
				$state['eligible'] = [];
				$done              = true;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['eligible'] ), 'total' => 0, 'done' => false, 'message' => 'Atribuições elegíveis…' ];
			}
			$state['phase'] = 3;
		}

		if ( 3 === $state['phase'] ) {
			if ( ! $this->read_mfa_registrations( $state, $deadline ) ) {
				return [ 'state' => $state, 'progress' => $state['mfa_offset'], 'total' => count( $state['mfa_targets'] ), 'done' => false, 'message' => 'Verificando MFA dos usuários com atribuições…' ];
			}
			$state['phase'] = 4;
		}

		return [ 'state' => $state, 'progress' => count( $state['active'] ) + count( $state['eligible'] ), 'total' => count( $state['active'] ) + count( $state['eligible'] ), 'done' => true, 'message' => 'Concluído' ];
	}

	/** Only fetch registrations for the distinct users this report will include. */
	private function read_mfa_registrations( array &$state, float $deadline ): bool {
		if ( ! isset( $state['mfa_targets'] ) ) {
			$targets = [];
			foreach ( [ 'active', 'eligible' ] as $bucket ) {
				foreach ( $state[ $bucket ] as $assignment ) {
					$principal = $assignment['principalId'];
					if ( 'user' === $assignment['principalType']
						&& preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $principal ) ) {
						$targets[ $principal ] = true;
					}
				}
			}
			$state['mfa_targets'] = array_keys( $targets );
			$state['mfa_offset']  = 0;
			$state['mfa']         = [];
		}

		$total = count( $state['mfa_targets'] );
		while ( $state['mfa_offset'] < $total && ! $this->out_of_time( $deadline ) ) {
			$targets = array_slice( $state['mfa_targets'], $state['mfa_offset'], 20 );
			$requests = array_map( static fn( $id ) => [ 'id' => $id, 'method' => 'GET', 'url' => '/reports/authenticationMethods/userRegistrationDetails/' . $id ], $targets );
			try {
				$responses = $this->fetch_batch( $state, '_privileged_mfa_batch', $requests );
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				$this->warn( $state, 'Estado de MFA dos admins indisponível: ' . $e->getMessage() );
				return true;
			}
			if ( null === $responses ) {
				return false;
			}
			$forbidden = false;
			foreach ( $responses as $id => $response ) {
				$body      = $response['body'];
				$forbidden = $forbidden || 403 === $response['status'];
				if ( 200 === $response['status'] && isset( $body['isMfaRegistered'] ) && is_bool( $body['isMfaRegistered'] ) ) {
					$state['mfa'][ $id ] = $body['isMfaRegistered'];
				}
			}
			if ( $forbidden ) {
				$this->warn( $state, 'Estado de MFA dos admins indisponível. Verifique as permissões e a licença do tenant.' );
				return true;
			}
			$state['mfa_offset'] += count( $targets );
		}
		return $state['mfa_offset'] >= $total;
	}

	/** Permanente = assigned with no end date; Temporário = assigned with an end; Ativado = PIM just-in-time. */
	private static function kind_of( array $a, string $source ): string {
		if ( 'eligible' === $source ) {
			return 'Elegível (PIM)';
		}
		if ( 'Activated' === (string) ( $a['assignmentType'] ?? '' ) ) {
			return 'Ativado (PIM)';
		}
		return empty( $a['endDateTime'] ) ? 'Permanente' : 'Temporário';
	}

	private function map_assignment( array $a, string $source ): array {
		$p    = $a['principal'] ?? [];
		$type = str_replace( '#microsoft.graph.', '', (string) ( $p['@odata.type'] ?? '' ) );
		return [
			'kind'         => self::kind_of( $a, $source ),
			'source'       => $source,
			'roleId'       => strtolower( (string) ( $a['roleDefinitionId'] ?? '' ) ),
			'principalId'  => strtolower( (string) ( $a['principalId'] ?? ( $p['id'] ?? '' ) ) ),
			'principalType' => $type ?: 'unknown',
			'name'         => (string) ( $p['displayName'] ?? '' ),
			'upn'          => (string) ( $p['userPrincipalName'] ?? '' ),
			'start'        => (string) ( $a['startDateTime'] ?? '' ),
			'end'          => (string) ( $a['endDateTime'] ?? '' ),
			'memberType'   => (string) ( $a['memberType'] ?? '' ),
			'scope'        => (string) ( $a['directoryScopeId'] ?? '/' ),
		];
	}

	public function finalize( array $state, array $params ): array {
		$roles = $state['roles'] ?? [];
		$mfa   = $state['mfa'] ?? [];
		$rows  = [];
		$summary = [ 'active' => 0, 'eligible' => 0, 'standing' => 0, 'activated' => 0, 'standingPrivileged' => 0, 'adminsNoMfa' => 0, 'uniquePrincipals' => 0, 'groupsAssigned' => 0, 'spAssigned' => 0, 'globalAdmins' => 0, 'pim' => (bool) ( $state['pim'] ?? false ), 'byRole' => [] ];
		$no_mfa  = [];
		$seen  = [];

		foreach ( array_merge( $state['active'] ?? [], $state['eligible'] ?? [] ) as $a ) {
			$role            = $roles[ $a['roleId'] ] ?? [ 'name' => $a['roleId'], 'privileged' => self::is_privileged_template( $a['roleId'] ) ];
			$is_user         = 'user' === $a['principalType'];
			$mfaReg          = $is_user ? ( $mfa[ $a['principalId'] ] ?? null ) : null;
			$standing        = 'Permanente' === $a['kind'];
			$eligible        = 'eligible' === ( $a['source'] ?? '' ) || 'Elegível (PIM)' === $a['kind'];
			$rows[]          = array_merge( $a, [
				'role'       => $role['name'],
				'privileged' => $role['privileged'],
				'mfa'        => $mfaReg,
			] );

			$summary[ $eligible ? 'eligible' : 'active' ]++;
			if ( $standing ) {
				$summary['standing']++;
			}
			if ( 'Ativado (PIM)' === $a['kind'] ) {
				$summary['activated']++;
			}
			if ( $standing && $role['privileged'] ) {
				$summary['standingPrivileged']++;
			}
			if ( '62e90394-69f5-4237-9190-012177145e10' === $a['roleId'] && ! $eligible ) {
				$summary['globalAdmins']++;
			}
			if ( $is_user && false === $mfaReg && ! isset( $no_mfa[ $a['principalId'] ] ) ) {
				$no_mfa[ $a['principalId'] ] = true; // count people, not assignments
				$summary['adminsNoMfa']++;
			}
			$summary['groupsAssigned'] += 'group' === $a['principalType'] ? 1 : 0;
			$summary['spAssigned']     += 'servicePrincipal' === $a['principalType'] ? 1 : 0;
			$seen[ $a['principalId'] ] = true;
			$summary['byRole'][ $role['name'] ] = ( $summary['byRole'][ $role['name'] ] ?? 0 ) + 1;
		}
		$summary['uniquePrincipals'] = count( $seen );
		arsort( $summary['byRole'] );
		usort( $rows, fn( $a, $b ) => [ $b['privileged'], $a['kind'] ] <=> [ $a['privileged'], $b['kind'] ] ?: strcmp( $a['role'], $b['role'] ) );

		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ) ] ];
	}
}
