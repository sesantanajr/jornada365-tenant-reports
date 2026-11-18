<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App governance: expiring secrets/certificates (App Registrations + Service Principals),
 * OAuth2 delegated consents with risky-scope flags, and enterprise-app inventory.
 * Application.Read.All (+ Directory.Read.All for grants).
 */
final class App_Security extends Report_Base {

	private const RISKY_SCOPES = [
		'mail.readwrite', 'mail.send', 'mail.read', 'mailboxsettings.readwrite', 'full_access_as_user',
		'files.readwrite.all', 'sites.readwrite.all', 'sites.fullcontrol.all',
		'directory.readwrite.all', 'user.readwrite.all', 'group.readwrite.all', 'groupmember.readwrite.all',
		'application.readwrite.all', 'rolemanagement.readwrite.directory', 'privilegedaccess.readwrite.azuread',
		'accessreview.readwrite.all', 'policy.readwrite.conditionalaccess',
	];

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'creds' => [], 'grants' => [], 'sps' => [], 'spmap' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		// Phase 0: App Registrations -> credential expiry.
		if ( 0 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'apps_raw', '/v1.0/applications?$select=id,appId,displayName,passwordCredentials,keyCredentials,signInAudience&$top=200', $deadline, [], function ( $a ) {
				$this->collect_creds( $a['displayName'] ?? '', 'App Registration', $a['passwordCredentials'] ?? [], $a['keyCredentials'] ?? [], (string) ( $a['appId'] ?? '' ) );
				return [ 'appId' => strtolower( (string) ( $a['appId'] ?? '' ) ), 'name' => (string) ( $a['displayName'] ?? '' ), 'audience' => (string) ( $a['signInAudience'] ?? '' ) ];
			} );
			$state['creds'] = array_merge( $state['creds'], $this->flush_creds() );
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['apps_raw'] ), 'total' => 0, 'done' => false, 'message' => 'App Registrations…' ];
			}
			$state['phase'] = 1;
		}

		// Phase 1: Service Principals (enterprise apps) inventory + SP credentials.
		if ( 1 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'sps', '/v1.0/servicePrincipals?$select=id,appId,displayName,servicePrincipalType,accountEnabled,appOwnerOrganizationId,passwordCredentials,keyCredentials,tags&$top=500', $deadline, [], function ( $sp ) {
				$this->collect_creds( $sp['displayName'] ?? '', 'Service Principal', $sp['passwordCredentials'] ?? [], $sp['keyCredentials'] ?? [], (string) ( $sp['appId'] ?? '' ) );
				$this->state_spmap[ strtolower( (string) $sp['id'] ) ] = (string) ( $sp['displayName'] ?? '' );
				return [
					'id'        => (string) $sp['id'],
					'appId'     => strtolower( (string) ( $sp['appId'] ?? '' ) ),
					'name'      => (string) ( $sp['displayName'] ?? '' ),
					'type'      => (string) ( $sp['servicePrincipalType'] ?? '' ),
					'enabled'   => (bool) ( $sp['accountEnabled'] ?? true ),
					'owner'     => strtolower( (string) ( $sp['appOwnerOrganizationId'] ?? '' ) ),
					'tags'      => (array) ( $sp['tags'] ?? [] ),
				];
			} );
			// move collected creds/spmap from instance buffer into state
			$state['creds'] = array_merge( $state['creds'], $this->flush_creds() );
			$state['spmap'] = array_merge( $state['spmap'], $this->state_spmap );
			$this->state_spmap = [];
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['sps'] ), 'total' => 0, 'done' => false, 'message' => 'Enterprise apps…' ];
			}
			$state['phase'] = 2;
		}

		// Phase 2: OAuth2 delegated permission grants.
		if ( 2 === $state['phase'] ) {
			try {
				// Only $filter is documented for this list on v1.0 — page with nextLink, no $top.
				$done = $this->fetch_collection( $state, 'grants', '/v1.0/oauth2PermissionGrants', $deadline, [], fn( $g ) => [
					'clientId'    => strtolower( (string) ( $g['clientId'] ?? '' ) ),
					'consentType' => (string) ( $g['consentType'] ?? '' ),
					'scope'       => trim( (string) ( $g['scope'] ?? '' ) ),
				] );
			} catch ( Graph_Exception $e ) {
				$this->warn( $state, 'Consentimentos OAuth (oauth2PermissionGrants) indisponíveis: ' . $e->getMessage() );
				$state['grants'] = [];
				$done            = true;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['grants'] ), 'total' => 0, 'done' => false, 'message' => 'Consentimentos OAuth…' ];
			}
			$state['phase'] = 3;
		}

		return [ 'state' => $state, 'progress' => count( $state['creds'] ), 'total' => count( $state['creds'] ), 'done' => true, 'message' => 'Concluído' ];
	}

	/** Instance buffers used during a step (never persisted directly). */
	private array $cred_buffer = [];
	private array $state_spmap = [];

	private function collect_creds( string $owner, string $kind, array $pw, array $keys, string $appId ): void {
		foreach ( $pw as $c ) {
			$this->cred_buffer[] = $this->cred_row( $owner, $kind, 'Secret', $c, $appId );
		}
		foreach ( $keys as $c ) {
			$this->cred_buffer[] = $this->cred_row( $owner, $kind, 'Certificado', $c, $appId );
		}
	}

	private function flush_creds(): array {
		$b                 = $this->cred_buffer;
		$this->cred_buffer = [];
		return $b;
	}

	private function cred_row( string $owner, string $kind, string $type, array $c, string $appId ): array {
		$end  = (string) ( $c['endDateTime'] ?? '' );
		$days = $end ? (int) floor( ( strtotime( $end ) - time() ) / DAY_IN_SECONDS ) : null;
		return [
			'owner'       => $owner,
			'ownerType'   => $kind,
			'appId'       => strtolower( $appId ),
			'credType'    => $type,
			'displayName' => (string) ( $c['displayName'] ?? '' ),
			'start'       => (string) ( $c['startDateTime'] ?? '' ),
			'end'         => $end,
			'daysToExpiry' => $days,
			'status'      => null === $days ? 'sem validade' : ( $days < 0 ? 'expirado' : ( $days <= 30 ? 'expira <30d' : ( $days <= 90 ? 'expira <90d' : 'ok' ) ) ),
		];
	}

	public function finalize( array $state, array $params ): array {
		// Merge any creds collected during phase 0 (apps) that weren't flushed.
		$creds = $state['creds'];
		if ( $this->cred_buffer ) {
			$creds = array_merge( $creds, $this->flush_creds() );
		}
		usort( $creds, fn( $a, $b ) => ( $a['daysToExpiry'] ?? PHP_INT_MAX ) <=> ( $b['daysToExpiry'] ?? PHP_INT_MAX ) );

		$spmap = $state['spmap'] ?? [];
		$home  = $this->tenant_id;

		// Aggregate delegated consents per client.
		$by_client = [];
		foreach ( $state['grants'] ?? [] as $g ) {
			$cid = $g['clientId'];
			if ( ! isset( $by_client[ $cid ] ) ) {
				$by_client[ $cid ] = [ 'scopes' => [], 'allPrincipals' => false, 'principals' => 0 ];
			}
			foreach ( preg_split( '/\s+/', strtolower( $g['scope'] ) ) as $sc ) {
				if ( '' !== $sc ) {
					$by_client[ $cid ]['scopes'][ $sc ] = true;
				}
			}
			if ( 'AllPrincipals' === $g['consentType'] ) {
				$by_client[ $cid ]['allPrincipals'] = true;
			} else {
				$by_client[ $cid ]['principals']++;
			}
		}
		$consents = [];
		foreach ( $by_client as $cid => $info ) {
			$scopes = array_keys( $info['scopes'] );
			$risky  = array_values( array_intersect( $scopes, self::RISKY_SCOPES ) );
			$consents[] = [
				'client'        => $spmap[ $cid ] ?? '(desconhecido)',
				'clientId'      => $cid,
				'scopeCount'    => count( $scopes ),
				'scopes'        => $scopes,
				'risky'         => $risky,
				'tenantWide'    => $info['allPrincipals'],
				'userGrants'    => $info['principals'],
			];
		}
		usort( $consents, fn( $a, $b ) => count( $b['risky'] ) <=> count( $a['risky'] ) ?: $b['scopeCount'] <=> $a['scopeCount'] );

		$sps        = $state['sps'] ?? [];
		$ms_owner   = 'f8cdef31-a31e-4b4a-93e4-5f571e91255a';
		$summary    = [
			'enterpriseApps'  => count( $sps ),
			'thirdParty'      => count( array_filter( $sps, fn( $s ) => $s['owner'] && $s['owner'] !== $home && $s['owner'] !== $ms_owner ) ),
			'disabledApps'    => count( array_filter( $sps, fn( $s ) => ! $s['enabled'] ) ),
			'credsTotal'      => count( $creds ),
			'credsExpired'    => count( array_filter( $creds, fn( $c ) => null !== $c['daysToExpiry'] && $c['daysToExpiry'] < 0 ) ),
			'creds30'         => count( array_filter( $creds, fn( $c ) => null !== $c['daysToExpiry'] && $c['daysToExpiry'] >= 0 && $c['daysToExpiry'] <= 30 ) ),
			'consents'        => count( $consents ),
			'riskyConsents'   => count( array_filter( $consents, fn( $c ) => $c['risky'] ) ),
			'tenantWideConsents' => count( array_filter( $consents, fn( $c ) => $c['tenantWide'] ) ),
		];

		return [
			'rows'     => $creds,
			'consents' => $consents,
			'summary'  => $summary,
			'_meta'    => [ 'rows' => count( $creds ) ],
		];
	}
}
