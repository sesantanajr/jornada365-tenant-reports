<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;
use J365TP\Sku_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exchange Online mailboxes: size against the quotas (warning / prohibit send / prohibit send and
 * receive), archive status and whether the license allows auto-expanding archiving.
 *
 * Sources: getMailboxUsageDetail (Reports.Read.All, CSV via 302) + /users assignedPlans
 * (Directory.Read.All) mapped to service plan ids with the official SKU catalog.
 */
final class Mailboxes extends Report_Base {

	/**
	 * Service plans that include auto-expanding archiving: Exchange Online Plan 2 (also in Microsoft 365
	 * E3/E5, A3/A5, G3/G5) or the Exchange Online Archiving add-on (e.g. Business Premium, or Plan 1 + EOA).
	 * EXCHANGE_S_ARCHIVE (archiving for Exchange Server, on-premises mailboxes) is deliberately excluded.
	 */
	public const EXPAND_PLANS = '/^EXCHANGE_S_(ENTERPRISE|ARCHIVE_ADDON)(_GOV)?$/';
	/** Plans with an Exchange Online mailbox that includes an archive mailbox. */
	private const ARCHIVE_PLANS = '/^EXCHANGE_S_(ENTERPRISE|STANDARD|ARCHIVE_ADDON|ESSENTIALS)(_GOV|_MIDMARKET)?$/';

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'mbx' => [], 'plans' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			try {
				$state['mbx'] = $this->graph->get_report_csv(
					"/v1.0/reports/getMailboxUsageDetail(period='D7')",
					[ 'userPrincipalName', 'displayName', 'isDeleted', 'createdDate', 'lastActivityDate', 'itemCount', 'storageUsedByte', 'issueWarningQuotaByte', 'prohibitSendQuotaByte', 'prohibitSendReceiveQuotaByte', 'deletedItemSizeByte', 'hasArchive', 'recipientType', 'reportRefreshDate' ]
				);
			} catch ( Graph_Exception $e ) {
				if ( $e->is_forbidden() ) {
					throw new Graph_Exception( 'Relatório de caixas de correio indisponível: ' . $e->getMessage() . ' (requer Reports.Read.All).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}
			$state['phase'] = 1;
			return [ 'state' => $state, 'progress' => 1, 'total' => 2, 'done' => false, 'message' => sprintf( '%d caixas de correio…', count( $state['mbx'] ) ) ];
		}

		if ( 1 === $state['phase'] ) {
			// Licenses → archive / auto-expanding eligibility (only Exchange plans are kept).
			try {
				$done = $this->fetch_collection(
					$state,
					'plans',
					'/v1.0/users?$select=userPrincipalName,assignedPlans&$top=999',
					$deadline,
					[],
					static function ( $u ) {
						$codes = [];
						foreach ( (array) ( $u['assignedPlans'] ?? [] ) as $p ) {
							if ( 'Enabled' === ( $p['capabilityStatus'] ?? '' ) && 'exchange' === strtolower( (string) ( $p['service'] ?? '' ) ) ) {
								$codes[] = Sku_Catalog::plan_code( (string) ( $p['servicePlanId'] ?? '' ) );
							}
						}
						return [ 'u' => strtolower( (string) ( $u['userPrincipalName'] ?? '' ) ), 'p' => array_values( array_unique( array_filter( $codes ) ) ) ];
					}
				);
			} catch ( Graph_Exception $e ) {
				if ( 'j365_token' === $e->graph_code() ) {
					throw $e;
				}
				$this->warn( $state, 'Licenças (assignedPlans): ' . $e->getMessage() );
				$done = true;
			}
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => 1, 'total' => 2, 'done' => false, 'message' => sprintf( '%d usuários…', count( $state['plans'] ) ) ];
			}
			$state['phase'] = 2;
		}
		return [ 'state' => $state, 'progress' => 2, 'total' => 2, 'done' => true, 'message' => 'Concluído' ];
	}

	public function finalize( array $state, array $params ): array {
		$plans = [];
		foreach ( (array) ( $state['plans'] ?? [] ) as $p ) {
			if ( ! empty( $p['u'] ) ) {
				$plans[ $p['u'] ] = (array) $p['p'];
			}
		}
		$rows      = [];
		$concealed = false;
		$refresh   = '';
		foreach ( (array) ( $state['mbx'] ?? [] ) as $m ) {
			if ( self::truthy( $m['isDeleted'] ?? null ) ) {
				continue;
			}
			$upn  = (string) ( $m['userPrincipalName'] ?? '' );
			$hide = Usage::concealed_id( $upn );
			$concealed = $concealed || $hide;
			$refresh   = $refresh ?: (string) ( $m['reportRefreshDate'] ?? '' );
			$used = (float) ( $m['storageUsedByte'] ?? 0 );
			$warn = (float) ( $m['issueWarningQuotaByte'] ?? 0 );
			$send = (float) ( $m['prohibitSendQuotaByte'] ?? 0 );
			$full = (float) ( $m['prohibitSendReceiveQuotaByte'] ?? 0 );
			$cap  = $full > 0 ? $full : $send;
			$pct  = $cap > 0 ? round( 100 * $used / $cap, 1 ) : null;
			$st   = match ( true ) {
				$full > 0 && $used >= $full => 'blocked',
				$send > 0 && $used >= $send => 'send_blocked',
				$warn > 0 && $used >= $warn => 'warning',
				default                     => 'ok',
			};
			$codes    = $hide ? null : ( $plans[ strtolower( $upn ) ] ?? null );
			$eligible = null === $codes ? null : (bool) preg_grep( self::EXPAND_PLANS, $codes );
			$rows[]   = [
				'upn'          => $upn,
				'displayName'  => (string) ( $m['displayName'] ?? '' ),
				'type'         => (string) ( $m['recipientType'] ?? '' ),
				'lastActivity' => (string) ( $m['lastActivityDate'] ?? '' ),
				'items'        => (int) ( $m['itemCount'] ?? 0 ),
				'used'         => $used,
				'warnQuota'    => $warn,
				'sendQuota'    => $send,
				'fullQuota'    => $full,
				'pct'          => $pct,
				'quota'        => $st,
				'deletedSize'  => (float) ( $m['deletedItemSizeByte'] ?? 0 ),
				'hasArchive'   => self::truthy( $m['hasArchive'] ?? null ),
				'expandOk'     => $eligible,
				'archiveOk'    => null === $codes ? null : (bool) preg_grep( self::ARCHIVE_PLANS, $codes ),
				'concealed'    => $hide,
			];
		}
		usort( $rows, static fn( $a, $b ) => $b['used'] <=> $a['used'] );
		$count   = static fn( callable $f ) => count( array_filter( $rows, $f ) );
		$summary = [
			'total'          => count( $rows ),
			'used'           => array_sum( array_column( $rows, 'used' ) ),
			'warning'        => $count( static fn( $r ) => 'warning' === $r['quota'] ),
			'sendBlocked'    => $count( static fn( $r ) => 'send_blocked' === $r['quota'] ),
			'blocked'        => $count( static fn( $r ) => 'blocked' === $r['quota'] ),
			'near'           => $count( static fn( $r ) => null !== $r['pct'] && $r['pct'] >= 80 ),
			'withArchive'    => $count( static fn( $r ) => $r['hasArchive'] ),
			'noArchive'      => $count( static fn( $r ) => ! $r['hasArchive'] ),
			'nearNoArchive'  => $count( static fn( $r ) => ! $r['hasArchive'] && null !== $r['pct'] && $r['pct'] >= 80 ),
			'expandEligible' => $count( static fn( $r ) => true === $r['expandOk'] ),
			'refreshDate'    => $refresh,
		];
		return [
			'rows'    => $rows,
			'summary' => $summary,
			'_meta'   => [ 'rows' => count( $rows ), 'concealed' => $concealed, 'warnings' => $state['_warnings'] ?? [] ],
		];
	}

	private static function truthy( $v ): bool {
		return true === $v || 1 === $v || ( is_string( $v ) && in_array( strtolower( trim( $v ) ), [ 'true', '1', 'yes' ], true ) );
	}
}
