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

	private const SERVICES = [
		'exchange' => 'Exchange',
		'teams' => 'Teams',
		'sharePoint' => 'SharePoint',
		'oneDrive' => 'OneDrive',
	];

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
			: [ 'userPrincipalName', 'displayName', 'isDeleted', 'assignedProducts', 'exchangeLastActivityDate', 'oneDriveLastActivityDate', 'sharePointLastActivityDate', 'teamsLastActivityDate', 'hasTeamsLicense', 'hasExchangeLicense', 'hasOneDriveLicense', 'hasSharePointLicense', 'reportRefreshDate' ];
		try {
			return $this->graph->get_report_csv( '/v1.0/reports/' . $fn, $columns );
		} catch ( Graph_Exception $e ) {
			if ( ! $teams && $e->is_forbidden() ) {
				throw new Graph_Exception( 'Relatórios de uso indisponíveis: ' . $e->getMessage() . ' (requer Reports.Read.All).', $e->getCode(), $e->graph_code() );
			}
			if ( ! $teams || 'j365_token' === $e->graph_code() ) {
				throw $e; // the main report is the report: an outage must fail visibly, never finish as an empty list
			}
			// Teams detail only adds message/meeting/call counts; the rest of the report stays valid (warning shown).
			$this->optional_error( $state, 'Detalhes de atividade do Teams indisponíveis', $e );
			return [];
		}
	}

	public function finalize( array $state, array $params ): array {
		$active = $state['active'] ?? [];
		$period = $this->normalize_params( $params )['period'];
		$refresh_dates = [];
		foreach ( $active as $row ) {
			$date = self::valid_day( $row['reportRefreshDate'] ?? '' );
			if ( $date ) {
				$refresh_dates[ $date ] = true;
			}
		}
		// A CSV represents one snapshot. Conflicting refresh dates cannot define one reporting window.
		$end = 1 === count( $refresh_dates ) ? (string) array_key_first( $refresh_dates ) : '';
		$start = '';
		if ( $end ) {
			$day = new \DateTimeImmutable( $end, new \DateTimeZone( 'UTC' ) );
			$start = $day->modify( '-' . ( (int) substr( $period, 1 ) - 1 ) . ' days' )->format( 'Y-m-d' );
		}
		$services = [];
		foreach ( self::SERVICES as $key => $name ) {
			$services[ $key ] = [ 'name' => $name, 'key' => $key, 'licensed' => 0, 'active' => 0, 'inactive' => 0, 'unknown' => 0, 'unknownLicense' => 0 ];
		}
		$summary = [ 'users' => 0, 'eligible' => 0, 'activeUsers' => 0, 'licensedNoActivity' => 0, 'insufficient' => 0, 'deleted' => 0, 'unknownAccount' => 0, 'period' => $period, 'startDate' => $start, 'refreshDate' => $end ];
		$teams_by_upn = [];
		foreach ( $state['teams'] ?? [] as $t ) {
			$key = strtolower( trim( (string) ( $t['userPrincipalName'] ?? '' ) ) );
			if ( '' !== $key ) {
				$teams_by_upn[ $key ] = $t;
			}
		}
		$rows = [];
		$concealed = false;
		foreach ( $active as $u ) {
			$upn = trim( (string) ( $u['userPrincipalName'] ?? '' ) );
			$concealed = $concealed || self::concealed_id( $upn );
			$product_field = $u['assignedProducts'] ?? '';
			// Products are joined with "+"; " + " can be part of a product name.
			$list = is_array( $product_field ) ? $product_field : preg_split( '/(?<!\s)\+(?!\s)/', trim( (string) $product_field ), -1, PREG_SPLIT_NO_EMPTY );
			$deleted = self::flag( $u['isDeleted'] ?? null );
			$t = $teams_by_upn[ strtolower( $upn ) ] ?? [];
			$channel = self::metric( $t['teamChatMessageCount'] ?? null );
			$private = self::metric( $t['privateChatMessageCount'] ?? null );
			$row = [
				'upn' => $upn, 'displayName' => (string) ( $u['displayName'] ?? '' ), 'deleted' => $deleted,
				'products' => array_values( array_filter( array_map( static fn( $p ) => \J365TP\Sku_Catalog::product( (string) $p ), $list ) ) ),
				'teamsMessages' => null !== $channel && null !== $private ? $channel + $private : null,
				'teamsMeetings' => self::metric( $t['meetingCount'] ?? null ), 'teamsCalls' => self::metric( $t['callCount'] ?? null ),
				'teamsRefreshDate' => self::valid_day( $t['reportRefreshDate'] ?? '' ),
				'serviceStatus' => [],
			];
			foreach ( self::SERVICES as $key => $name ) {
				$licensed = self::flag( $u[ 'has' . $name . 'License' ] ?? null );
				$activity = $key . 'LastActivityDate';
				$last = trim( (string) ( $u[ $activity ] ?? '' ) );
				$status = 'unlicensed';
				if ( null === $licensed ) {
					$status = 'unknownLicense';
				} elseif ( $licensed ) {
					if ( ! $end || ! array_key_exists( $activity, $u ) || ( '' !== $last && ( ! self::valid_day( $last ) || $last > $end ) ) ) {
						$status = 'unknown';
					} else {
						$status = $last >= $start ? 'active' : 'inactive';
					}
				}
				$row[ $key ] = self::valid_day( $last );
				$row[ 'has' . $name ] = $licensed;
				$row['serviceStatus'][ $key ] = $status;
				if ( false === $deleted && 'unlicensed' !== $status ) {
					++$services[ $key ][ $status ];
					if ( true === $licensed ) {
						++$services[ $key ]['licensed'];
					}
				}
			}
			$statuses = $row['serviceStatus'];
			$eligible = (bool) array_intersect( [ 'active', 'inactive', 'unknown' ], $statuses );
			$incomplete = (bool) array_intersect( [ 'unknown', 'unknownLicense' ], $statuses );
			$row['usageStatus'] = 'unlicensed';
			if ( true === $deleted ) {
				$row['usageStatus'] = 'deleted';
				++$summary['deleted'];
			} elseif ( null === $deleted ) {
				$row['usageStatus'] = 'unknownAccount';
				++$summary['unknownAccount'];
			} else {
				++$summary['users'];
				$summary['eligible'] += (int) $eligible;
				if ( in_array( 'active', $statuses, true ) ) {
					$row['usageStatus'] = 'active';
					++$summary['activeUsers'];
				} elseif ( $incomplete ) {
					$row['usageStatus'] = 'unknown';
					$summary['insufficient'] += (int) $eligible;
				} elseif ( $eligible ) {
					$row['usageStatus'] = 'inactive';
					++$summary['licensedNoActivity'];
				}
			}
			$row['noActivity'] = 'inactive' === $row['usageStatus'];
			$rows[] = $row;
		}
		foreach ( $services as &$service ) {
			$service['pct'] = $service['licensed'] && ! $service['unknown'] ? 100 * $service['active'] / $service['licensed'] : null;
			$summary[ 'active' . $service['name'] ] = $service['active'];
			$summary[ 'licensed' . $service['name'] ] = $service['licensed'];
		}
		unset( $service );
		$summary['services'] = array_values( $services );
		$summary['teamsLicensedInactive'] = $services['teams']['inactive'];
		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ), 'concealed' => $concealed, 'warnings' => $state['_warnings'] ?? [] ] ];
	}

	private static function valid_day( $value ): string {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $value;
	}

	private static function metric( $value ): ?int {
		return is_scalar( $value ) && ctype_digit( (string) $value ) ? (int) $value : null;
	}

	private static function flag( $value ): ?bool {
		return match ( strtolower( trim( (string) $value ) ) ) {
			'true', '1', 'yes' => true,
			'false', '0', 'no' => false,
			default => false === $value ? false : null,
		};
	}


	/**
	 * Microsoft conceals names in usage reports by default (Microsoft 365 admin center → Settings → Org
	 * settings → Reports). Concealed values are 32-character hexadecimal identifiers instead of UPNs.
	 */
	public static function concealed_id( string $upn ): bool {
		return '' !== $upn && ! str_contains( $upn, '@' ) && (bool) preg_match( '/^[0-9A-F]{32,64}$/i', $upn );
	}
}
