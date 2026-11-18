<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The Entra recommendations API supplies the tenant-specific denominator for each date. */
final class Identity_Score extends Report_Base {

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'history', '/beta/directory/recommendations/tenantSecureScores', $deadline, [], static function ( $day ) {
				$current = is_numeric( $day['tenantScore'] ?? null ) ? (float) $day['tenantScore'] : null;
				$max     = is_numeric( $day['tenantMaxScore'] ?? null ) ? (float) $day['tenantMaxScore'] : null;
				return [
					'date'    => (string) ( $day['createDateTime'] ?? '' ),
					'current' => $current,
					'max'     => $max,
					'percent' => null !== $current && $max > 0 ? round( 100 * $current / $max, 1 ) : null,
				];
			} );
			if ( $done ) {
				$state['phase'] = 1;
			}
			return [ 'state' => $state, 'progress' => $done ? 1 : 0, 'total' => 2, 'done' => false, 'message' => 'Consultando o histórico do Identity Score…' ];
		}
		$url = '/beta/directory/recommendations?$filter=category%20eq%20%27identitySecureScore%27&$select=displayName,status,priority,currentScore,maxScore,lastCheckedDateTime';
		try {
			$done = $this->fetch_collection( $state, 'recommendations', $url, $deadline, [ 'Prefer' => 'include-unknown-enum-members' ], static function ( $item ) {
				$current = is_numeric( $item['currentScore'] ?? null ) ? (float) $item['currentScore'] : null;
				$max     = is_numeric( $item['maxScore'] ?? null ) ? (float) $item['maxScore'] : null;
				return [
					'name' => (string) ( $item['displayName'] ?? '' ), 'status' => (string) ( $item['status'] ?? '' ),
					'priority' => (string) ( $item['priority'] ?? '' ), 'current' => $current, 'max' => $max,
					'gap' => null !== $current && null !== $max ? max( 0, $max - $current ) : null,
					'checked' => (string) ( $item['lastCheckedDateTime'] ?? '' ),
				];
			} );
		} catch ( Graph_Exception $error ) {
			$this->optional_error( $state, 'Recomendações de identidade indisponíveis', $error );
			$done = true;
		}
		return [ 'state' => $state, 'progress' => $done ? 2 : 1, 'total' => 2, 'done' => $done, 'message' => 'Consultando recomendações de identidade…' ];
	}

	public function finalize( array $state, array $params ): array {
		$history = array_values( array_filter( $state['history'] ?? [], static fn( $day ) => '' !== $day['date'] ) );
		usort( $history, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		$rows = $state['recommendations'] ?? [];
		usort( $rows, static fn( $a, $b ) => ( $b['gap'] ?? -1 ) <=> ( $a['gap'] ?? -1 ) );
		return [ 'summary' => $history[0] ?? [ 'date' => '', 'current' => null, 'max' => null, 'percent' => null ],
			'history' => $history, 'rows' => $rows, '_meta' => [ 'rows' => count( $rows ) ] ];
	}
}
