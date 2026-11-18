<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microsoft 365 usage & adoption (Reports.Read.All), Microsoft Graph v1.0.
 * v1.0 report functions answer with a 302 to a pre-authenticated CSV download (JSON output is
 * beta-only), so the CSV is downloaded without the Graph token and parsed in memory. Only the
 * columns used by the portal are kept. Tenants may conceal user names — surfaced as a note.
 */
final class Usage extends Report_Base {

	public function normalize_params( array $params ): array {
		$period = $params['period'] ?? 'D30';
		$period = in_array( $period, [ 'D7', 'D30', 'D90', 'D180' ], true ) ? $period : 'D30';
		return [ 'period' => $period ];
	}

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'active' => [], 'teams' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		$period = $params['period'] ?? 'D30';

		if ( 0 === $state['phase'] ) {
			$state['active'] = $this->fetch_report( $state, "getOffice365ActiveUserDetail(period='{$period}')" );
			$state['phase']  = 1;
			// One download per step: if Graph throttles the Teams detail, only that step is retried.
			return [ 'state' => $state, 'progress' => 1, 'total' => 2, 'done' => false, 'message' => 'Uso geral carregado…' ];
		}

		if ( 1 === $state['phase'] ) {
			$state['teams'] = $this->fetch_report( $state, "getTeamsUserActivityUserDetail(period='{$period}')" );
			$state['phase'] = 2;
		}

		return [ 'state' => $state, 'progress' => 2, 'total' => 2, 'done' => true, 'message' => 'Concluído' ];
	}

	private function fetch_report( array &$state, string $fn ): array {
		$teams = str_starts_with( $fn, 'getTeams' );
		$columns = $teams
			? [ 'userPrincipalName', 'teamChatMessageCount', 'privateChatMessageCount', 'meetingCount', 'callCount', 'lastActivityDate', 'reportRefreshDate' ]
			: [ 'userPrincipalName', 'displayName', 'isDeleted', 'assignedProducts', 'exchangeLastActivityDate', 'oneDriveLastActivityDate', 'sharePointLastActivityDate', 'teamsLastActivityDate', 'hasTeamsLicense', 'hasExchangeLicense', 'reportRefreshDate' ];
		try {
			return $this->graph->get_report_csv( '/v1.0/reports/' . $fn, $columns );
		} catch ( Graph_Exception $e ) {
			if ( $e->is_forbidden() ) {
				throw new Graph_Exception( 'Relatórios de uso indisponíveis: ' . $e->getMessage() . ' (requer Reports.Read.All).', $e->getCode(), $e->graph_code() );
			}
			if ( ! $teams || 'j365_token' === $e->graph_code() ) {
				throw $e; // the main report is the report: an outage must fail visibly, never finish as an empty list
			}
			// Teams detail only adds message/meeting/call counts; the rest of the report stays valid (warning shown).
			$this->warn( $state, preg_replace( '/\(.*$/', '', $fn ) . ': ' . $e->getMessage() );
			return [];
		}
	}

	public function finalize( array $state, array $params ): array {
		$active    = $state['active'] ?? [];
		$teams     = $state['teams'] ?? [];
		$concealed = false;
		$refresh   = '';

		$teamsByUpn = [];
		foreach ( $teams as $t ) {
			$teamsByUpn[ strtolower( (string) ( $t['userPrincipalName'] ?? '' ) ) ] = [
				'messages' => (int) ( $t['teamChatMessageCount'] ?? 0 ) + (int) ( $t['privateChatMessageCount'] ?? 0 ),
				'meetings' => (int) ( $t['meetingCount'] ?? 0 ),
				'calls'    => (int) ( $t['callCount'] ?? 0 ),
			];
		}

		$rows = [];
		foreach ( $active as $u ) {
			$upn = (string) ( $u['userPrincipalName'] ?? '' );
			if ( self::concealed_id( $upn ) ) {
				$concealed = true;
			}
			$ap = $u['assignedProducts'] ?? '';
			// The CSV separates products with "+" (JSON output: an array); names come in upper case — use Microsoft's casing.
			// Products are joined with "+" ("A+B"), but names may contain " + " ("ENTERPRISE MOBILITY + SECURITY E3").
			$list     = is_array( $ap ) ? $ap : ( '' === trim( (string) $ap ) ? [] : (array) preg_split( '/(?<!\s)\+(?!\s)/', (string) $ap ) );
			$products = array_values( array_filter( array_map( static fn( $p ) => \J365TP\Sku_Catalog::product( (string) $p ), $list ) ) );
			$refresh  = $refresh ?: (string) ( $u['reportRefreshDate'] ?? '' );
			$t        = $teamsByUpn[ strtolower( $upn ) ] ?? null;
			$row      = [
				'upn'           => $upn,
				'displayName'   => (string) ( $u['displayName'] ?? '' ),
				'deleted'       => self::truthy( $u['isDeleted'] ?? null ),
				'products'      => $products,
				'exchange'      => (string) ( $u['exchangeLastActivityDate'] ?? '' ),
				'oneDrive'      => (string) ( $u['oneDriveLastActivityDate'] ?? '' ),
				'sharePoint'    => (string) ( $u['sharePointLastActivityDate'] ?? '' ),
				'teams'         => (string) ( $u['teamsLastActivityDate'] ?? '' ),
				'hasTeams'      => self::truthy( $u['hasTeamsLicense'] ?? null ),
				'hasExchange'   => self::truthy( $u['hasExchangeLicense'] ?? null ),
				'teamsMessages' => $t['messages'] ?? null,
				'teamsMeetings' => $t['meetings'] ?? null,
				'teamsCalls'    => $t['calls'] ?? null,
			];
			$row['noActivity'] = ! $row['exchange'] && ! $row['oneDrive'] && ! $row['sharePoint'] && ! $row['teams'];
			$rows[]            = $row;
		}

		$live    = array_values( array_filter( $rows, static fn( $r ) => ! $r['deleted'] ) );
		$count   = static fn( callable $f ) => count( array_filter( $live, $f ) );
		$summary = [
			'users'                 => count( $live ),
			'deleted'               => count( $rows ) - count( $live ),
			'activeExchange'        => $count( static fn( $r ) => $r['exchange'] ),
			'activeOneDrive'        => $count( static fn( $r ) => $r['oneDrive'] ),
			'activeSharePoint'      => $count( static fn( $r ) => $r['sharePoint'] ),
			'activeTeams'           => $count( static fn( $r ) => $r['teams'] ),
			'licensedExchange'      => $count( static fn( $r ) => $r['hasExchange'] ),
			'licensedTeams'         => $count( static fn( $r ) => $r['hasTeams'] ),
			'teamsLicensedInactive' => $count( static fn( $r ) => $r['hasTeams'] && ! $r['teams'] ),
			'licensedNoActivity'    => $count( static fn( $r ) => $r['products'] && $r['noActivity'] ),
			'period'                => $params['period'] ?? 'D30',
			'refreshDate'           => $refresh,
		];

		return [
			'rows'    => $rows,
			'summary' => $summary,
			'_meta'   => [ 'rows' => count( $rows ), 'concealed' => $concealed, 'warnings' => $state['_warnings'] ?? [] ],
		];
	}

	/**
	 * Microsoft conceals names in usage reports by default (Microsoft 365 admin center → Settings → Org
	 * settings → Reports). Concealed values are 32-character hexadecimal identifiers instead of UPNs.
	 */
	public static function concealed_id( string $upn ): bool {
		return '' !== $upn && ! str_contains( $upn, '@' ) && (bool) preg_match( '/^[0-9A-F]{32,64}$/i', $upn );
	}

	private static function truthy( $v ): bool {
		return true === $v || 1 === $v || ( is_string( $v ) && in_array( strtolower( trim( $v ) ), [ 'true', '1', 'yes' ], true ) );
	}
}
