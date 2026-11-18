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
		$out = [ 'securityDefaults' => null, 'methods' => [], 'authorization' => [], 'legacyAuthBlocked' => null ];

		$out['securityDefaults'] = $this->try_premium( $state, function () {
			$p = $this->graph->get( '/v1.0/policies/identitySecurityDefaultsEnforcementPolicy' );
			return [ 'enabled' => (bool) ( $p['isEnabled'] ?? false ) ];
		}, 'Security Defaults' );

		$amp = $this->try_premium( $state, fn() => $this->graph->get( '/v1.0/policies/authenticationMethodsPolicy' ), 'Authentication Methods Policy' );
		if ( is_array( $amp ) ) {
			$configs = (array) ( $amp['authenticationMethodConfigurations'] ?? [] );
			// Group targets are GUIDs: resolve their names in one call (best effort).
			$ids = [];
			foreach ( $configs as $m ) {
				foreach ( (array) ( $m['includeTargets'] ?? [] ) as $t ) {
					$tid = strtolower( (string) ( $t['id'] ?? '' ) );
					if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tid ) ) {
						$ids[ $tid ] = true;
					}
				}
			}
			$names = [];
			if ( $ids ) {
				try {
					$res = $this->graph->get_directory_objects( array_slice( array_keys( $ids ), 0, 1000 ), [ 'group', 'user' ] );
					foreach ( (array) ( $res['value'] ?? [] ) as $o ) {
						$names[ strtolower( (string) ( $o['id'] ?? '' ) ) ] = (string) ( $o['displayName'] ?? '' );
					}
				} catch ( Graph_Exception $e ) {
					$this->warn( $state, 'Resolução de nomes (getByIds): ' . $e->getMessage() );
				}
			}
			foreach ( $configs as $m ) {
				$targets = [];
				foreach ( (array) ( $m['includeTargets'] ?? [] ) as $t ) {
					$tid       = (string) ( $t['id'] ?? '' );
					$targets[] = 'all_users' === $tid ? 'Todos os usuários' : ( ( 'group' === ( $t['targetType'] ?? '' ) ? 'Grupo: ' : 'Usuário: ' ) . ( $names[ strtolower( $tid ) ] ?? $tid ) );
				}
				$out['methods'][] = [
					'id'      => (string) ( $m['id'] ?? '' ),
					'state'   => (string) ( $m['state'] ?? '' ),
					'targets' => $targets,
				];
			}
			$out['policyVersion'] = (string) ( $amp['policyVersion'] ?? '' );
		}

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

		// Legacy auth: infer from an enabled CA policy blocking legacy clients.
		$out['legacyAuthBlocked'] = $this->try_premium( $state, function () {
			$pol = $this->graph->get_all( '/v1.0/identity/conditionalAccess/policies?$select=state,conditions,grantControls' );
			foreach ( $pol as $p ) {
				if ( 'enabled' !== ( $p['state'] ?? '' ) ) {
					continue;
				}
				$cat = (array) ( $p['conditions']['clientAppTypes'] ?? [] );
				$ctl = (array) ( $p['grantControls']['builtInControls'] ?? [] );
				if ( in_array( 'block', $ctl, true ) && ( in_array( 'exchangeActiveSync', $cat, true ) || in_array( 'other', $cat, true ) ) ) {
					return true;
				}
			}
			return false;
		}, 'Avaliação de legacy auth' );

		$state['out'] = $out;
		return [ 'state' => $state, 'progress' => 1, 'total' => 1, 'done' => true, 'message' => 'Concluído' ];
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
