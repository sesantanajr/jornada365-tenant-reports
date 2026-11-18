<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MFA / authentication methods registration (reports/authenticationMethods/userRegistrationDetails).
 * Requires AuditLog.Read.All; disabled users are not returned by Graph.
 */
final class Mfa extends Report_Base {

	public function step( array $state, array $params, float $deadline ): array {
		$url = '/v1.0/reports/authenticationMethods/userRegistrationDetails?$top=999';
		try {
			$done = $this->fetch_collection( $state, 'reg', $url, $deadline, [], [ $this, 'map_row' ] );
		} catch ( Graph_Exception $e ) {
			if ( $e->is_forbidden() || $e->is_license_gate() ) {
				throw new Graph_Exception( 'Relatório de métodos de autenticação indisponível: ' . $e->getMessage() . ' (requer AuditLog.Read.All e, para alguns recursos, Entra ID P1).', $e->getCode(), $e->graph_code() );
			}
			throw $e;
		}
		$n = count( $state['reg'] ?? [] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $done ? $n : 0, 'done' => $done, 'message' => sprintf( '%d usuários processados…', $n ) ];
	}

	public function map_row( array $r ): array {
		return [
			'id'                 => (string) $r['id'],
			'upn'                => (string) ( $r['userPrincipalName'] ?? '' ),
			'displayName'        => (string) ( $r['userDisplayName'] ?? '' ),
			'userType'           => ucfirst( strtolower( (string) ( $r['userType'] ?? '' ) ) ), // v1.0 returns member/guest
			'isAdmin'            => (bool) ( $r['isAdmin'] ?? false ),
			'mfaRegistered'      => (bool) ( $r['isMfaRegistered'] ?? false ),
			'mfaCapable'         => (bool) ( $r['isMfaCapable'] ?? false ),
			'passwordless'       => (bool) ( $r['isPasswordlessCapable'] ?? false ),
			'ssprRegistered'     => (bool) ( $r['isSsprRegistered'] ?? false ),
			'ssprEnabled'        => (bool) ( $r['isSsprEnabled'] ?? false ),
			'ssprCapable'        => (bool) ( $r['isSsprCapable'] ?? false ),
			'systemPreferred'    => (bool) ( $r['isSystemPreferredAuthenticationMethodEnabled'] ?? false ),
			'methods'            => array_values( (array) ( $r['methodsRegistered'] ?? [] ) ),
			// v1.0 has no defaultMfaMethod: the effective default is the system-preferred method when
			// that feature is on, otherwise the user's own preferred second factor.
			'defaultMethod'      => self::effective_default( $r ),
			'preferredSecondary' => (string) ( $r['userPreferredMethodForSecondaryAuthentication'] ?? '' ),
			'systemMethods'      => array_values( (array) ( $r['systemPreferredAuthenticationMethods'] ?? [] ) ),
			'lastUpdated'        => (string) ( $r['lastUpdatedDateTime'] ?? '' ),
		];
	}

	private static function effective_default( array $r ): string {
		$system = array_values( array_filter( (array) ( $r['systemPreferredAuthenticationMethods'] ?? [] ), fn( $m ) => $m && 'none' !== $m ) );
		if ( ! empty( $r['isSystemPreferredAuthenticationMethodEnabled'] ) && $system ) {
			return (string) $system[0];
		}
		$user = (string) ( $r['userPreferredMethodForSecondaryAuthentication'] ?? '' );
		return 'none' === $user ? '' : $user;
	}

	public function finalize( array $state, array $params ): array {
		$rows    = $state['reg'] ?? [];
		// Phishing-resistant = methods accepted by the built-in "Phishing-resistant MFA" authentication
		// strength (FIDO2/passkeys, Windows Hello for Business, Platform SSO secure enclave key).
		// Authenticator phone sign-in and TAP are passwordless/strong but not phishing-resistant.
		$strong  = [ 'fido2SecurityKey', 'windowsHelloForBusiness', 'passKeyDeviceBound', 'passKeyDeviceBoundAuthenticator', 'passKeyDeviceBoundWindowsHello', 'passKeySynced', 'macOsSecureEnclaveKey' ];
		$weak    = [ 'mobilePhone', 'alternateMobilePhone', 'officePhone', 'email', 'securityQuestion' ];
		$summary = [
			'total'            => count( $rows ),
			'mfaRegistered'    => 0,
			'mfaNotRegistered' => 0,
			'mfaCapable'       => 0,
			'passwordless'     => 0,
			'phishingResistant' => 0,
			'weakOnly'         => 0,
			'adminsNoMfa'      => 0,
			'admins'           => 0,
			'ssprRegistered'   => 0,
			'byMethod'         => [],
			'byDefault'        => [],
		];
		foreach ( $rows as &$r ) {
			$has_strong = (bool) array_intersect( $r['methods'], $strong );
			$only_weak  = $r['methods'] && ! array_diff( $r['methods'], array_merge( $weak, [ 'none' ] ) );
			$r['phishingResistant'] = $has_strong;
			$r['weakOnly']          = $only_weak;
			$r['risk']              = $r['isAdmin'] && ! $r['mfaRegistered'] ? 'Crítico' : ( ! $r['mfaRegistered'] ? 'Alto' : ( $only_weak ? 'Médio' : ( $has_strong ? 'Baixo' : 'Baixo' ) ) );

			$summary[ $r['mfaRegistered'] ? 'mfaRegistered' : 'mfaNotRegistered' ]++;
			$summary['mfaCapable']    += $r['mfaCapable'] ? 1 : 0;
			$summary['passwordless']  += $r['passwordless'] ? 1 : 0;
			$summary['phishingResistant'] += $has_strong ? 1 : 0;
			$summary['weakOnly']      += $only_weak ? 1 : 0;
			$summary['ssprRegistered'] += $r['ssprRegistered'] ? 1 : 0;
			if ( $r['isAdmin'] ) {
				$summary['admins']++;
				if ( ! $r['mfaRegistered'] ) {
					$summary['adminsNoMfa']++;
				}
			}
			foreach ( $r['methods'] as $m ) {
				$summary['byMethod'][ $m ] = ( $summary['byMethod'][ $m ] ?? 0 ) + 1;
			}
			$d                        = $r['defaultMethod'] ?: 'none';
			$summary['byDefault'][ $d ] = ( $summary['byDefault'][ $d ] ?? 0 ) + 1;
		}
		unset( $r );
		arsort( $summary['byMethod'] );
		arsort( $summary['byDefault'] );

		return [
			'rows'    => $rows,
			'summary' => $summary,
			'_meta'   => [ 'rows' => count( $rows ) ],
		];
	}
}
