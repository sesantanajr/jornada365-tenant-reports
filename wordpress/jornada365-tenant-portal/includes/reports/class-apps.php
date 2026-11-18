<?php
namespace J365TP\Reports;

use J365TP\Allowlist_Repository;
use J365TP\Graph_Exception;
use J365TP\Tenant_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovered applications (Intune detectedApps) grouped by name/platform with versions,
 * device counts and homologation status (Intune-deployed, allowlist, trusted publisher).
 */
final class Apps extends Report_Base {

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			try {
				$done = $this->fetch_collection(
					$state,
					'detected',
					'/v1.0/deviceManagement/detectedApps?$select=id,displayName,version,publisher,platform,deviceCount,sizeInByte&$top=1000',
					$deadline,
					[],
					fn( $a ) => [
						'id'        => (string) $a['id'],
						'name'      => (string) ( $a['displayName'] ?? '' ),
						'version'   => (string) ( $a['version'] ?? '' ),
						'publisher' => (string) ( $a['publisher'] ?? '' ),
						'platform'  => (string) ( $a['platform'] ?? 'unknown' ),
						'devices'   => (int) ( $a['deviceCount'] ?? 0 ),
						'size'      => (int) ( $a['sizeInByte'] ?? 0 ),
					]
				);
			} catch ( Graph_Exception $e ) {
				if ( in_array( $e->getCode(), [ 403, 404 ], true ) ) {
					throw new Graph_Exception( 'Inventário de aplicativos indisponível: ' . $e->getMessage() . ' (requer Intune licenciado e DeviceManagementManagedDevices.Read.All).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['detected'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d entradas de aplicativos…', count( $state['detected'] ) ) ];
			}
			$state['phase'] = 1;
		}

		if ( 1 === $state['phase'] ) {
			// Intune-deployed apps are considered homologated by definition.
			try {
				$done = $this->fetch_collection(
					$state,
					'mobile',
					'/v1.0/deviceManagement/mobileApps?$select=id,displayName,publisher,publishingState&$top=999', // v1.0: no isAssigned
					$deadline,
					[],
					fn( $a ) => [
						'name'      => (string) ( $a['displayName'] ?? '' ),
						'publisher' => (string) ( $a['publisher'] ?? '' ),
						'type'      => (string) ( $a['@odata.type'] ?? '' ),
						'published' => 'notPublished' !== (string) ( $a['publishingState'] ?? 'published' ),
					]
				);
			} catch ( Graph_Exception $e ) {
				$this->optional_error( $state, 'Aplicativos implantados pelo Intune (mobileApps) indisponíveis', $e );
				$state['mobile'] = [];
				$done            = true;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['detected'] ), 'total' => 0, 'done' => false, 'message' => 'Carregando catálogo do Intune…' ];
			}
			$state['phase'] = 2;
		}

		$n = count( $state['detected'] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $n, 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		$tenant         = Tenant_Repository::get( $this->tenant_id ) ?? [ 'settings' => [] ];
		$auto_intune    = (bool) Tenant_Repository::get_setting( $tenant, 'auto_approve_intune_apps', true );
		$trust_ms       = (bool) Tenant_Repository::get_setting( $tenant, 'trust_microsoft_publisher', false );
		$rules          = Allowlist_Repository::all( $this->tenant_id );
		$intune_catalog = [];
		foreach ( $state['mobile'] ?? [] as $m ) {
			if ( false === ( $m['published'] ?? true ) ) {
				continue; // uploads still processing / not published are not considered homologated
			}
			$intune_catalog[ self::norm( $m['name'] ) ] = $m;
		}

		$groups = [];
		foreach ( $state['detected'] ?? [] as $a ) {
			$key = self::norm( $a['name'] ) . '|' . strtolower( $a['platform'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'name'       => $a['name'],
					'publisher'  => $a['publisher'],
					'platform'   => $a['platform'],
					'versions'   => [],
					'deviceCount' => 0,
					'ids'        => [],
				];
			}
			$g                    = &$groups[ $key ];
			$g['deviceCount']    += $a['devices'];
			$g['versions'][]      = [ 'version' => $a['version'] ?: '(sem versão)', 'devices' => $a['devices'], 'id' => $a['id'] ];
			$g['ids'][]           = $a['id'];
			if ( '' === $g['publisher'] && '' !== $a['publisher'] ) {
				$g['publisher'] = $a['publisher'];
			}
			unset( $g );
		}

		$rows    = [];
		$summary = [
			'apps'            => 0,
			'entries'         => count( $state['detected'] ?? [] ),
			'approved'        => 0,
			'notApproved'     => 0,
			'approvedIntune'  => 0,
			'approvedAllowlist' => 0,
			'approvedPublisher' => 0,
			'multiVersion'    => 0,
			'byPlatform'      => [],
			'intuneCatalog'   => count( $intune_catalog ),
		];

		foreach ( $groups as $g ) {
			usort( $g['versions'], fn( $x, $y ) => version_compare( self::vclean( $y['version'] ), self::vclean( $x['version'] ) ) );
			$approved_by = '';
			if ( $auto_intune && isset( $intune_catalog[ self::norm( $g['name'] ) ] ) ) {
				$approved_by = 'Intune (implantado)';
				$summary['approvedIntune']++;
			} elseif ( null !== ( $rule = Allowlist_Repository::match( $rules, $g['name'], $g['publisher'], $g['platform'] ) ) ) {
				$approved_by = $rule;
				$summary['approvedAllowlist']++;
			} elseif ( $trust_ms && preg_match( '/^microsoft\b/i', $g['publisher'] ) ) {
				$approved_by = 'Publisher confiável (Microsoft)';
				$summary['approvedPublisher']++;
			}

			$rows[] = [
				'name'          => $g['name'],
				'publisher'     => $g['publisher'],
				'platform'      => $g['platform'],
				'versionCount'  => count( $g['versions'] ),
				'latestVersion' => $g['versions'][0]['version'] ?? '',
				'versions'      => $g['versions'],
				'deviceCount'   => $g['deviceCount'],
				'approved'      => '' !== $approved_by,
				'approvedBy'    => $approved_by,
				'ids'           => $g['ids'],
			];
			$summary['apps']++;
			$summary[ '' !== $approved_by ? 'approved' : 'notApproved' ]++;
			if ( count( $g['versions'] ) > 1 ) {
				$summary['multiVersion']++;
			}
			$summary['byPlatform'][ $g['platform'] ] = ( $summary['byPlatform'][ $g['platform'] ] ?? 0 ) + 1;
		}
		usort( $rows, fn( $a, $b ) => $b['deviceCount'] <=> $a['deviceCount'] );

		return [
			'rows'     => $rows,
			'summary'  => $summary,
			'settings' => [ 'auto_approve_intune_apps' => $auto_intune, 'trust_microsoft_publisher' => $trust_ms ],
			'_meta'    => [ 'rows' => count( $rows ) ],
		];
	}

	private static function norm( string $s ): string {
		$s = mb_strtolower( trim( $s ) );
		return (string) preg_replace( '/\s+/', ' ', $s );
	}

	private static function vclean( string $v ): string {
		$v = preg_replace( '/[^0-9.]/', '', $v );
		return '' === $v ? '0' : trim( $v, '.' );
	}
}
