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
			try {
				$done = $this->fetch_collection( $state, 'history', '/beta/directory/recommendations/tenantSecureScores', $deadline, [], self::score_day( ... ) );
			} catch ( Graph_Exception $error ) {
				if ( ! $error->is_forbidden() ) {
					throw $error;
				}
				throw new Graph_Exception( 'O Microsoft Graph recusou a leitura do Identity Score. Confirme o consentimento de DirectoryRecommendations.Read.All. No acesso delegado, use uma função do Entra com leitura das recomendações, como Reports Reader.', 403, $error->graph_code() );
			}
			if ( $done ) {
				$state['phase'] = 1;
			}
			return [ 'state' => $state, 'progress' => $done ? 1 : 0, 'total' => 2, 'done' => false, 'message' => 'Consultando o histórico do Identity Score…' ];
		}
		// Beta deployments can reject an explicit $select on inherited optional fields.
		// Accept the service's default projection and retain only the fields used in this report.
		$url = '/beta/directory/recommendations?$filter=category%20eq%20%27identitySecureScore%27';
		try {
			$done = $this->fetch_collection( $state, 'recommendations', $url, $deadline, [ 'Prefer' => 'include-unknown-enum-members' ], static function ( $item ) {
				$current = is_numeric( $item['currentScore'] ?? null ) ? (float) $item['currentScore'] : null;
				$max     = is_numeric( $item['maxScore'] ?? null ) ? (float) $item['maxScore'] : null;
				return [
					'name' => (string) ( $item['displayName'] ?? '' ), 'status' => (string) ( $item['status'] ?? '' ),
					'priority' => (string) ( $item['priority'] ?? '' ), 'current' => $current, 'max' => $max,
					'gap' => null !== $current && null !== $max ? max( 0, $max - $current ) : null,
					'checked' => (string) ( $item['lastCheckedDateTime'] ?? '' ),
					'modified' => (string) ( $item['lastModifiedDateTime'] ?? '' ),
				];
			} );
		} catch ( Graph_Exception $error ) {
			$this->optional_error( $state, 'Recomendações de identidade indisponíveis', $error );
			$state['recommendations_partial'] = true;
			$done = true;
		}
		return [ 'state' => $state, 'progress' => $done ? 2 : 1, 'total' => 2, 'done' => $done, 'message' => 'Consultando recomendações de identidade…' ];
	}

	private static function score_day( array $day ): array {
		$current = is_numeric( $day['tenantScore'] ?? null ) ? (float) $day['tenantScore'] : null;
		$max     = is_numeric( $day['tenantMaxScore'] ?? null ) ? (float) $day['tenantMaxScore'] : null;
		return [
			'date'    => (string) ( $day['createDateTime'] ?? '' ),
			'current' => $current,
			'max'     => $max,
			'percent' => null !== $current && $current >= 0 && $max > 0 && $current <= $max ? 100 * $current / $max : null,
		];
	}

	public function finalize( array $state, array $params ): array {
		// Normalize offsets before ordering. API rows occasionally arrive across UTC day/month boundaries.
		$history = [];
		foreach ( $state['history'] ?? [] as $day ) {
			$date = (string) ( $day['date'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $date ) ) {
				continue;
			}
			try {
				$utc = new \DateTimeZone( 'UTC' );
				$instant = new \DateTimeImmutable( $date, $utc );
				$issues = \DateTimeImmutable::getLastErrors();
				if ( $issues && ( $issues['warning_count'] || $issues['error_count'] ) ) {
					continue;
				}
				$day['date'] = $instant->setTimezone( $utc )->format( 'Y-m-d\TH:i:s.v\Z' );
			} catch ( \Exception $error ) {
				continue;
			}
			$history[] = self::score_day( [ 'createDateTime' => $day['date'], 'tenantScore' => $day['current'], 'tenantMaxScore' => $day['max'] ] );
		}
		usort( $history, static fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		$latest = [ 'date' => '', 'current' => null, 'max' => null, 'percent' => null ];
		foreach ( $history as $day ) {
			if ( null !== $day['percent'] ) {
				$latest = $day;
				break;
			}
		}
		$rows = $state['recommendations'] ?? [];
		usort( $rows, static fn( $a, $b ) => ( $b['gap'] ?? -1 ) <=> ( $a['gap'] ?? -1 ) );
		return [ 'summary' => $latest, 'history' => $history, 'rows' => $rows,
			'_meta' => [ 'rows' => count( $rows ), 'recommendationsPartial' => ! empty( $state['recommendations_partial'] ) ] ];
	}
}
