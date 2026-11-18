<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OneDrive accounts and SharePoint sites by storage: used vs allocated, near/at the limit and
 * the largest consumers (Top N chosen in the browser). Reports.Read.All, CSV via 302.
 */
final class Storage extends Report_Base {

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'od' => [], 'sp' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			try {
				$state['od'] = $this->graph->get_report_csv(
					"/v1.0/reports/getOneDriveUsageAccountDetail(period='D7')",
					[ 'ownerPrincipalName', 'ownerDisplayName', 'siteUrl', 'siteURL', 'isDeleted', 'lastActivityDate', 'fileCount', 'activeFileCount', 'storageUsedByte', 'storageAllocatedByte', 'reportRefreshDate' ]
				);
			} catch ( Graph_Exception $e ) {
				if ( $e->is_forbidden() ) {
					throw new Graph_Exception( 'Relatório de armazenamento indisponível: ' . $e->getMessage() . ' (requer Reports.Read.All).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}
			$state['phase'] = 1;
			return [ 'state' => $state, 'progress' => 1, 'total' => 2, 'done' => false, 'message' => sprintf( '%d contas do OneDrive…', count( $state['od'] ) ) ];
		}
		if ( 1 === $state['phase'] ) {
			try {
				$state['sp'] = $this->graph->get_report_csv(
					"/v1.0/reports/getSharePointSiteUsageDetail(period='D7')",
					[ 'siteUrl', 'siteURL', 'ownerDisplayName', 'ownerPrincipalName', 'isDeleted', 'lastActivityDate', 'fileCount', 'activeFileCount', 'pageViewCount', 'storageUsedByte', 'storageAllocatedByte', 'rootWebTemplate' ]
				);
			} catch ( Graph_Exception $e ) {
				$this->optional_error( $state, 'Armazenamento do SharePoint indisponível', $e );
				$state['spIncomplete'] = true;
			}
			$state['phase'] = 2;
		}
		return [ 'state' => $state, 'progress' => 2, 'total' => 2, 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		$concealed = false;
		$refresh   = '';
		$od        = [];
		foreach ( (array) ( $state['od'] ?? [] ) as $r ) {
			if ( self::truthy( $r['isDeleted'] ?? null ) ) {
				continue;
			}
			$upn       = (string) ( $r['ownerPrincipalName'] ?? '' );
			$hide      = Usage::concealed_id( $upn );
			$concealed = $concealed || $hide;
			$refresh   = $refresh ?: (string) ( $r['reportRefreshDate'] ?? '' );
			$used      = (float) ( $r['storageUsedByte'] ?? 0 );
			$alloc     = (float) ( $r['storageAllocatedByte'] ?? 0 );
			$od[]      = [
				'upn'          => $upn,
				'displayName'  => (string) ( $r['ownerDisplayName'] ?? '' ),
				'url'          => self::site_url( $r ),
				'lastActivity' => (string) ( $r['lastActivityDate'] ?? '' ),
				'files'        => (int) ( $r['fileCount'] ?? 0 ),
				'activeFiles'  => (int) ( $r['activeFileCount'] ?? 0 ),
				'used'         => $used,
				'allocated'    => $alloc,
				'pct'          => $alloc > 0 ? round( 100 * $used / $alloc, 1 ) : null,
				'concealed'    => $hide,
			];
		}
		$sp = [];
		foreach ( (array) ( $state['sp'] ?? [] ) as $r ) {
			if ( self::truthy( $r['isDeleted'] ?? null ) ) {
				continue;
			}
			$used  = (float) ( $r['storageUsedByte'] ?? 0 );
			$alloc = (float) ( $r['storageAllocatedByte'] ?? 0 );
			$url   = self::site_url( $r );
			$sp[]  = [
				'url'          => $url,
				'owner'        => (string) ( $r['ownerDisplayName'] ?? '' ),
				'ownerUpn'     => (string) ( $r['ownerPrincipalName'] ?? '' ),
				'template'     => (string) ( $r['rootWebTemplate'] ?? '' ),
				'lastActivity' => (string) ( $r['lastActivityDate'] ?? '' ),
				'files'        => (int) ( $r['fileCount'] ?? 0 ),
				'views'        => (int) ( $r['pageViewCount'] ?? 0 ),
				'used'         => $used,
				'allocated'    => $alloc,
				'pct'          => $alloc > 0 ? round( 100 * $used / $alloc, 1 ) : null,
			];
		}
		usort( $od, static fn( $a, $b ) => $b['used'] <=> $a['used'] );
		usort( $sp, static fn( $a, $b ) => $b['used'] <=> $a['used'] );
		$near    = static fn( array $list, float $min ) => count( array_filter( $list, static fn( $r ) => null !== $r['pct'] && $r['pct'] >= $min ) );
		$summary = [
			'odAccounts' => count( $od ),
			'odUsed'     => array_sum( array_column( $od, 'used' ) ),
			'odNear'     => $near( $od, 90 ),
			'odFull'     => $near( $od, 100 ),
			'odEmpty'    => count( array_filter( $od, static fn( $r ) => 0 === $r['files'] ) ),
			'spSites'    => count( $sp ),
			'spUsed'     => array_sum( array_column( $sp, 'used' ) ),
			'spNear'     => $near( $sp, 90 ),
			'spInactive' => count( array_filter( $sp, static fn( $r ) => '' === $r['lastActivity'] || ( strtotime( $r['lastActivity'] ) ?: 0 ) < time() - 180 * DAY_IN_SECONDS ) ),
			'refreshDate' => $refresh,
		];
		if ( ! empty( $state['spIncomplete'] ) ) {
			foreach ( [ 'spSites', 'spUsed', 'spNear', 'spInactive' ] as $metric ) {
				$summary[ $metric ] = null;
			}
		}
		return [
			'onedrive'   => $od,
			'sharepoint' => $sp,
			'summary'    => $summary,
			'_meta'      => [ 'rows' => count( $od ) + count( $sp ), 'concealed' => $concealed, 'warnings' => $state['_warnings'] ?? [] ],
		];
	}

	/** CSV column "Site URL" arrives as siteURL (camelCase keeps the acronym); JSON uses siteUrl. */
	private static function site_url( array $r ): string {
		$url = (string) ( $r['siteUrl'] ?? $r['siteURL'] ?? '' );
		return preg_match( '#^https://#i', $url ) ? $url : '';
	}

	private static function truthy( $v ): bool {
		return true === $v || 1 === $v || ( is_string( $v ) && in_array( strtolower( trim( $v ) ), [ 'true', '1', 'yes' ], true ) );
	}
}
