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
			$done = $this->fetch_collection( $state, 'apps_raw', '/v1.0/applications?$select=id,appId,displayName,passwordCredentials,keyCredentials&$top=200', $deadline, [], function ( $a ) {
				$this->collect_creds( $a, 'App Registration' );
				return null;
			} );
			// Only credentials and the cursor are needed for subsequent pages.
			$state['apps_seen'] = ( $state['apps_seen'] ?? 0 ) + count( $state['apps_raw'] ?? [] );
			unset( $state['apps_raw'] );
			$state['creds'] = array_merge( $state['creds'], $this->flush_creds() );
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => $state['apps_seen'], 'total' => 0, 'done' => false, 'message' => 'App Registrations…' ];
			}
			$state['phase'] = 1;
		}

		// Phase 1: Service Principals (enterprise apps) inventory + SP credentials.
		if ( 1 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'sps', '/v1.0/servicePrincipals?$select=id,appId,displayName,accountEnabled,appOwnerOrganizationId,passwordCredentials,keyCredentials&$top=500', $deadline, [], function ( $sp ) {
				$this->collect_creds( $sp, 'Service Principal' );
				$this->state_spmap[ strtolower( (string) $sp['id'] ) ] = (string) ( $sp['displayName'] ?? '' );
				return [
					'enabled'   => (bool) ( $sp['accountEnabled'] ?? true ),
					'owner'     => strtolower( (string) ( $sp['appOwnerOrganizationId'] ?? '' ) ),
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
				$this->optional_error( $state, 'Consentimentos OAuth (oauth2PermissionGrants) indisponíveis', $e );
				$state['grantsIncomplete'] = true;
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

	private function collect_creds( array $object, string $kind ): void {
		foreach ( [ 'passwordCredentials' => 'Secret', 'keyCredentials' => 'Certificado' ] as $property => $type ) {
			foreach ( $object[ $property ] ?? [] as $credential ) {
				$this->cred_buffer[] = [
					'owner'       => (string) ( $object['displayName'] ?? '' ),
					'ownerType'   => $kind,
					'objectId'    => strtolower( (string) ( $object['id'] ?? '' ) ),
					'appId'       => strtolower( (string) ( $object['appId'] ?? '' ) ),
					'credType'    => $type,
					'credentialId' => (string) ( $credential['keyId'] ?? '' ),
					'displayName' => (string) ( $credential['displayName'] ?? '' ),
					'start'       => (string) ( $credential['startDateTime'] ?? '' ),
					'end'         => (string) ( $credential['endDateTime'] ?? '' ),
				];
			}
		}
	}

	private function flush_creds(): array {
		$b                 = $this->cred_buffer;
		$this->cred_buffer = [];
		return $b;
	}

	/** Aggregate deadlines per directory object and credential type, using one reference time for all pages. */
	private static function rotation_plan( array &$credentials, int $now ): array {
		$groups = [];
		foreach ( $credentials as $i => &$row ) {
			$end  = strtotime( $row['end'] );
			$days = false === $end ? null : (int) ceil( ( $end - $now ) / DAY_IN_SECONDS );
			if ( false !== $end && $end <= $now ) {
				$days = min( -1, (int) floor( ( $end - $now ) / DAY_IN_SECONDS ) );
			}
			$row['daysToExpiry'] = $days;
			$row['status']       = null === $days ? 'sem validade' : ( $days < 0 ? 'expirado' : ( $days <= 30 ? 'expira <30d' : ( $days <= 90 ? 'expira <90d' : 'ok' ) ) );
			// Older in-flight jobs contain appId but do not yet have the directory object ID.
			$object = ( $row['objectId'] ?? '' ) ?: ( $row['appId'] ?: 'row-' . $i );
			$key    = $row['ownerType'] . '|' . $object . '|' . $row['credType'];
			$groups[ $key ] ??= [
				'owner' => $row['owner'], 'ownerType' => $row['ownerType'], 'objectId' => $row['objectId'] ?? '',
				'appId' => $row['appId'], 'credType' => $row['credType'], 'expired' => 0, 'within7' => 0,
				'within30' => 0, 'within90' => 0, 'longValid' => 0, 'nextExpiry' => '',
			];
			$group = &$groups[ $key ];
			if ( null === $days ) {
				continue;
			}
			if ( $days < 0 ) {
				++$group['expired'];
			} elseif ( $days <= 90 ) {
				$bucket = $days <= 7 ? 'within7' : ( $days <= 30 ? 'within30' : 'within90' );
				++$group[ $bucket ];
				$utc = gmdate( 'Y-m-d\TH:i:s\Z', $end );
				if ( '' === $group['nextExpiry'] || $utc < $group['nextExpiry'] ) {
					$group['nextExpiry'] = $utc;
				}
			} else {
				$start = strtotime( $row['start'] );
				if ( false !== $start && $start <= $now ) {
					++$group['longValid'];
				}
			}
		}
		unset( $row, $group );
		$queue = array_values( array_filter( $groups, static fn( $g ) => $g['expired'] + $g['within7'] + $g['within30'] + $g['within90'] > 0 ) );
		usort( $queue, static fn( $a, $b ) => [ $a['longValid'] > 0, $a['nextExpiry'] ?: '9999', -$a['expired'], $a['owner'] ] <=> [ $b['longValid'] > 0, $b['nextExpiry'] ?: '9999', -$b['expired'], $b['owner'] ] );
		return $queue;
	}

	public function finalize( array $state, array $params ): array {
		// Merge any creds collected during phase 0 (apps) that weren't flushed.
		$creds = $state['creds'];
		if ( $this->cred_buffer ) {
			$creds = array_merge( $creds, $this->flush_creds() );
		}
		$rotation = self::rotation_plan( $creds, time() );
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
			'credsUndated'    => count( array_filter( $creds, fn( $c ) => null === $c['daysToExpiry'] ) ),
			'credsExpired'    => count( array_filter( $creds, fn( $c ) => null !== $c['daysToExpiry'] && $c['daysToExpiry'] < 0 ) ),
			'creds30'         => count( array_filter( $creds, fn( $c ) => null !== $c['daysToExpiry'] && $c['daysToExpiry'] >= 0 && $c['daysToExpiry'] <= 30 ) ),
			'consents'        => count( $consents ),
			'riskyConsents'   => count( array_filter( $consents, fn( $c ) => $c['risky'] ) ),
			'tenantWideConsents' => count( array_filter( $consents, fn( $c ) => $c['tenantWide'] ) ),
		];
		if ( ! empty( $state['grantsIncomplete'] ) ) {
			foreach ( [ 'consents', 'riskyConsents', 'tenantWideConsents' ] as $metric ) {
				$summary[ $metric ] = null;
			}
		}

		return [
			'rows'     => $creds,
			'rotation' => $rotation,
			'consents' => $consents,
			'summary'  => $summary,
			'_meta'    => [ 'rows' => count( $creds ) ],
		];
	}
}
