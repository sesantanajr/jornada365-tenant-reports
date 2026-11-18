<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether an authenticated Entra user may use the portal for its tenant.
 *
 * Rule: the user must hold (active assignment) one of the allowed Entra directory roles
 * in its own tenant, verified server-side with the app-only token — never from client claims.
 * The administrator who granted consent is also allowed during the first 24 h (configurable),
 * to avoid a lockout right after onboarding when the approving role was activated just-in-time.
 */
final class Authorization {

	/** How long after the approval the consenting admin may enter without an active role. */
	public const CONSENTER_WINDOW = DAY_IN_SECONDS;

	/**
	 * @return array{allowed: bool, roles: array<int, array{id:string,name:string}>, reason: string, valid_from: int, graph_error?: bool}
	 */
	public static function evaluate( string $tenant_id, string $user_oid, string $upn, array $tenant ): array {
		$allowed_templates = Settings::allowed_roles();
		$graph             = new Graph_Client( $tenant_id );

		// Zero Trust: the account must still exist and be enabled, and "Revoke sessions" in Entra ID
		// (signInSessionsValidFromDateTime) also ends portal sessions issued before it.
		try {
			$user = $graph->get( '/v1.0/users/' . rawurlencode( $user_oid ) . '?$select=id,accountEnabled,signInSessionsValidFromDateTime' );
		} catch ( Graph_Exception $e ) {
			if ( 404 === $e->getCode() ) {
				return [ 'allowed' => false, 'roles' => [], 'code' => 'account_missing', 'reason' => 'Sua conta não existe mais no tenant.', 'valid_from' => 0 ];
			}
			return [ 'allowed' => false, 'roles' => [], 'code' => 403 === $e->getCode() ? 'graph_forbidden' : 'graph_error', 'graph_code' => (string) $e->graph_code(), 'reason' => 'Não foi possível verificar a conta do usuário: ' . $e->getMessage(), 'graph_error' => true, 'valid_from' => 0 ];
		}
		if ( false === ( $user['accountEnabled'] ?? true ) ) {
			return [ 'allowed' => false, 'roles' => [], 'code' => 'account_disabled', 'reason' => 'Sua conta está desabilitada no Entra ID.', 'valid_from' => 0 ];
		}
		$valid_from = (int) ( strtotime( (string) ( $user['signInSessionsValidFromDateTime'] ?? '' ) ) ?: 0 );

		$roles = [];
		try {
			// transitiveMemberOf also covers roles assigned through role-assignable groups (incl. PIM for Groups).
			try {
				$data = $graph->get_all( '/v1.0/users/' . rawurlencode( $user_oid ) . '/transitiveMemberOf/microsoft.graph.directoryRole?$select=id,displayName,roleTemplateId' );
			} catch ( Graph_Exception $e ) {
				if ( 400 !== $e->getCode() ) {
					throw $e;
				}
				$data = $graph->get_all( '/v1.0/users/' . rawurlencode( $user_oid ) . '/memberOf/microsoft.graph.directoryRole?$select=id,displayName,roleTemplateId' );
			}
			foreach ( $data as $role ) {
				$roles[] = [
					'id'   => strtolower( (string) ( $role['roleTemplateId'] ?? '' ) ),
					'name' => (string) ( $role['displayName'] ?? '' ),
				];
			}
		} catch ( Graph_Exception $e ) {
			return [ 'allowed' => false, 'roles' => [], 'code' => 403 === $e->getCode() ? 'graph_forbidden' : 'graph_error', 'graph_code' => (string) $e->graph_code(), 'reason' => 'Não foi possível verificar as funções do usuário: ' . $e->getMessage(), 'graph_error' => true, 'valid_from' => $valid_from ];
		}

		foreach ( $roles as $role ) {
			if ( in_array( $role['id'], $allowed_templates, true ) ) {
				return [ 'allowed' => true, 'roles' => $roles, 'code' => '', 'reason' => '', 'valid_from' => $valid_from ];
			}
		}

		// Bootstrap convenience only: the administrator who approved the app may enter during the first
		// 24 h even if the role used for the approval (e.g. PIM) is no longer active. After that, normal
		// role rules apply — losing the role means losing access.
		$consented_at = strtotime( (string) ( $tenant['consented_at'] ?? '' ) . ' UTC' ) ?: 0;
		if ( Settings::get( 'allow_consenter' ) && '' !== $upn && $consented_at > time() - self::CONSENTER_WINDOW && '' !== (string) ( $tenant['consented_by'] ?? '' ) && hash_equals( Privacy::uid( (string) $tenant['consented_by'] ), Privacy::uid( $upn ) ) ) {
			return [ 'allowed' => true, 'roles' => $roles, 'code' => '', 'reason' => '', 'valid_from' => $valid_from ];
		}

		// Tenant-level allowlist of UPNs (managed by an already-authorized admin in the portal).
		$extra = Privacy::uids( (array) Tenant_Repository::get_setting( $tenant, 'extra_users', [] ) );
		if ( '' !== $upn && in_array( Privacy::uid( $upn ), $extra, true ) ) {
			return [ 'allowed' => true, 'roles' => $roles, 'code' => '', 'reason' => '', 'valid_from' => $valid_from ];
		}

		$names = array_map( fn( $t ) => Settings::DEFAULT_ROLE_TEMPLATES[ $t ] ?? $t, $allowed_templates );
		return [
			'allowed'    => false,
			'code'       => 'role_missing',
			'valid_from' => $valid_from,
			'roles'   => $roles,
			'reason'  => 'Sua conta não possui uma função de diretório autorizada (atribuição ativa). Funções aceitas: ' . implode( ', ', $names ) . '. Se usa PIM, ative a função e entre novamente.',
		];
	}
}
