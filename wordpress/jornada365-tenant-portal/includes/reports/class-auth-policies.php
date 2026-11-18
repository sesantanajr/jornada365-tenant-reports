<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant authentication posture: Security Defaults, per-method policy (Authentication Methods Policy),
 * SSPR/consent settings (Authorization Policy). Read-only, Policy.Read.All.
 */
final class Auth_Policies extends Report_Base {

	public function step( array $state, array $params, float $deadline ): array {
		$state['out'] ??= [ 'securityDefaults' => null, 'methods' => [], 'authorization' => [], 'legacyAuthBlocked' => null ];
		$out = &$state['out'];

		if ( 0 === $state['phase'] ) {
			$out['securityDefaults'] = $this->try_premium( $state, function () {
				$p = $this->graph->get( '/v1.0/policies/identitySecurityDefaultsEnforcementPolicy' );
				return [ 'enabled' => (bool) ( $p['isEnabled'] ?? false ) ];
			}, 'Security Defaults' );
			return $this->advance( $state, 1, 'Carregando métodos de autenticação…' );
		}

		if ( 1 === $state['phase'] ) {
			$amp = $this->try_premium( $state, fn() => $this->graph->get( '/v1.0/policies/authenticationMethodsPolicy' ), 'Authentication Methods Policy' );
			$configs = (array) ( $amp['authenticationMethodConfigurations'] ?? [] );
			if ( is_array( $amp ) ) {
				$out['policyVersion'] = (string) ( $amp['policyVersion'] ?? '' );
			}
			// Group targets are GUIDs: resolve their names in chunks of up to 1,000.
			$ids = [];
			foreach ( $configs as $m ) {
				foreach ( (array) ( $m['includeTargets'] ?? [] ) as $t ) {
					$tid = strtolower( (string) ( $t['id'] ?? '' ) );
					if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tid ) ) {
						$ids[ $tid ] = true;
					}
				}
			}
			$state['configs'] = $configs;
			$state['target_ids'] = array_keys( $ids );
			$state['target_idx'] = 0;
			$state['names'] = [];
			return $this->advance( $state, 2, 'Identificando grupos de autenticação…' );
		}

		if ( 2 === $state['phase'] ) {
			$chunk = array_slice( $state['target_ids'], $state['target_idx'], 1000 );
			if ( $chunk ) {
				try {
					$res = $this->graph->get_directory_objects( $chunk, [ 'group', 'user' ] );
					foreach ( (array) ( $res['value'] ?? [] ) as $o ) {
						$state['names'][ strtolower( (string) $o['id'] ) ] = (string) ( $o['displayName'] ?? $o['id'] );
					}
				} catch ( Graph_Exception $e ) {
					$this->optional_error( $state, 'Resolução de nomes (getByIds)', $e );
				}
				$state['target_idx'] += count( $chunk );
				if ( $state['target_idx'] < count( $state['target_ids'] ) ) {
					return $this->advance( $state, 2, 'Identificando grupos de autenticação…' );
				}
			}
			$configs = $state['configs'];
			foreach ( $configs as $m ) {
				$targets = [];
				foreach ( (array) ( $m['includeTargets'] ?? [] ) as $t ) {
					$tid       = (string) ( $t['id'] ?? '' );
					$targets[] = 'all_users' === $tid ? 'Todos os usuários' : ( ( 'group' === ( $t['targetType'] ?? '' ) ? 'Grupo: ' : 'Usuário: ' ) . ( $state['names'][ strtolower( $tid ) ] ?? $tid ) );
				}
				$out['methods'][] = [
					'id'      => (string) ( $m['id'] ?? '' ),
					'state'   => (string) ( $m['state'] ?? '' ),
					'targets' => $targets,
				];
			}
			unset( $state['configs'], $state['target_ids'], $state['target_idx'], $state['names'] );
			return $this->advance( $state, 3, 'Carregando a política de autorização…' );
		}

		if ( 3 === $state['phase'] ) {
			$auth = $this->try_premium( $state, fn() => $this->graph->get( '/v1.0/policies/authorizationPolicy' ), 'Authorization Policy' );
			// authorizationPolicy may return a single object or a collection depending on API shape.
			$auth = isset( $auth['value'][0] ) ? $auth['value'][0] : $auth;
			if ( is_array( $auth ) ) {
				$out['authorization'] = [
					'allowedToSignUpEmailBasedSubscriptions' => (bool) ( $auth['allowedToSignUpEmailBasedSubscriptions'] ?? false ),
					'allowedToUseSSPR'          => (bool) ( $auth['allowedToUseSSPR'] ?? false ),
					'allowEmailVerifiedToJoin'  => (bool) ( $auth['allowEmailVerifiedUsersToJoinOrganization'] ?? false ),
					// User consent is on only when a "managePermissionGrantsForSelf.*" policy is assigned
					// (other entries, e.g. ManagePermissionGrantsForOwnedResource.*, don't allow user consent).
					'allowUserConsentForApps'   => (bool) array_filter( (array) ( $auth['defaultUserRolePermissions']['permissionGrantPoliciesAssigned'] ?? [] ), fn( $p ) => 0 === stripos( (string) $p, 'managePermissionGrantsForSelf.' ) ),
					'userConsentPolicies'       => array_values( array_map( 'strval', (array) ( $auth['defaultUserRolePermissions']['permissionGrantPoliciesAssigned'] ?? [] ) ) ),
					'allowedToCreateApps'       => (bool) ( $auth['defaultUserRolePermissions']['allowedToCreateApps'] ?? false ),
					'allowedToCreateSecurityGroups' => (bool) ( $auth['defaultUserRolePermissions']['allowedToCreateSecurityGroups'] ?? false ),
					'allowedToReadOtherUsers'   => (bool) ( $auth['defaultUserRolePermissions']['allowedToReadOtherUsers'] ?? false ),
					'guestUserRoleId'           => (string) ( $auth['guestUserRoleId'] ?? '' ),
					'blockMsolPowerShell'       => (bool) ( $auth['blockMsolPowerShell'] ?? false ),
				];
			}
			return $this->advance( $state, 4, 'Avaliando autenticação legada…' );
		}

		try {
			$done = $this->fetch_collection(
				$state, 'legacy_blocks', '/v1.0/identity/conditionalAccess/policies?$select=state,conditions,grantControls', $deadline, [],
				static function ( array $policy ): bool {
					$clients = (array) ( $policy['conditions']['clientAppTypes'] ?? [] );
					return 'enabled' === ( $policy['state'] ?? '' )
						&& in_array( 'block', (array) ( $policy['grantControls']['builtInControls'] ?? [] ), true )
						&& ( in_array( 'exchangeActiveSync', $clients, true ) || in_array( 'other', $clients, true ) );
				}
			);
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['legacy_blocks'] ), 'total' => 0, 'done' => false, 'message' => 'Avaliando autenticação legada…' ];
			}
			$out['legacyAuthBlocked'] = in_array( true, $state['legacy_blocks'], true );
		} catch ( Graph_Exception $e ) {
			if ( ! $e->is_license_gate() && ! $e->is_forbidden() ) {
				throw $e;
			}
			$this->optional_error( $state, 'Avaliação de autenticação legada indisponível', $e );
		}
		unset( $state['legacy_blocks'], $state['legacy_blocks_next'], $state['legacy_blocks_done'] );
		return [ 'state' => $state, 'progress' => 5, 'total' => 5, 'done' => true, 'message' => 'Concluído' ];
	}

	private function advance( array $state, int $phase, string $message ): array {
		$state['phase'] = $phase;
		return [ 'state' => $state, 'progress' => $phase, 'total' => 5, 'done' => false, 'message' => $message ];
	}

	public function finalize( array $state, array $params ): array {
		$out          = $state['out'];
		$guest_roles  = [
			'a0b1b346-4d3e-4e8b-98f8-753987be4970' => 'Acesso total (igual a membros)',
			'10dae51f-b6af-4016-8d66-8c2a99b929b3' => 'Limitado (padrão)',
			'2af84b1e-32c8-42b7-82bc-daa82404023b' => 'Restrito (mais restritivo)',
		];
		if ( ! empty( $out['authorization']['guestUserRoleId'] ) ) {
			$out['authorization']['guestAccessLabel'] = $guest_roles[ strtolower( $out['authorization']['guestUserRoleId'] ) ] ?? $out['authorization']['guestUserRoleId'];
		}
		$out['_meta'] = [ 'rows' => 1 ];
		return $out;
	}
}
