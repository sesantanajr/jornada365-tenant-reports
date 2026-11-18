<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Intune Endpoint analytics. The tenant score is supplied by the service; device rows load separately. */
final class Device_Score extends Report_Base {
	private const ROOT = '/v1.0/deviceManagement/userExperienceAnalytics';
	private const CATEGORIES = [
		'boot'      => [ 'deviceBootPerformanceOverallScore', 'deviceBootPerformanceHealthState' ],
		'apps'      => [ 'appHealthOverallScore', 'appHealthState' ],
		'work'      => [ 'workFromAnywhereOverallScore', 'workFromAnywhereHealthState' ],
		'resources' => [ 'resourcePerformanceOverallScore', 'resourcePerformanceHealthState' ],
		'battery'   => [ 'batteryHealthOverallScore', 'batteryHealthState' ],
		'practices' => [ 'bestPracticesOverallScore', 'bestPracticesHealthState' ],
	];

	public function normalize_params( array $params ): array {
		return 'devices' === ( $params['section'] ?? '' ) ? [ 'section' => 'devices' ] : [];
	}

	private static function score( $value, string $state = '' ): ?float {
		if ( ! is_numeric( $value ) || in_array( $state, [ 'insufficientData', 'unknown', 'unknownFutureValue' ], true ) ) {
			return null;
		}
		$score = (float) $value;
		return is_finite( $score ) && $score >= 0 && $score <= 100 ? $score : null;
	}

	private static function device( array $item ): array {
		$state = (string) ( $item['healthStatus'] ?? '' );
		$row = [
			'id' => (string) ( $item['id'] ?? '' ), 'name' => (string) ( $item['deviceName'] ?? '' ),
			'manufacturer' => (string) ( $item['manufacturer'] ?? '' ), 'model' => (string) ( $item['model'] ?? '' ),
			'state' => $state, 'score' => self::score( $item['endpointAnalyticsScore'] ?? null, $state ),
		];
		// Device rows have a single health status. With insufficient or unknown coverage, withhold score values.
		foreach ( [ 'boot' => 'startupPerformanceScore', 'apps' => 'appReliabilityScore', 'work' => 'workFromAnywhereScore', 'battery' => 'batteryHealthScore' ] as $key => $field ) {
			$row[ $key ] = self::score( $item[ $field ] ?? null, $state );
		}
		return $row;
	}

	private static function overview( array $item ): array {
		$state = (string) ( $item['state'] ?? '' );
		$metrics = [];
		foreach ( self::CATEGORIES as $key => [ $field, $status ] ) {
			if ( ! array_key_exists( $field, $item ) && ! array_key_exists( $status, $item ) ) {
				continue;
			}
			$health = (string) ( $item[ $status ] ?? '' );
			$metrics[] = [ 'metric' => $key, 'score' => self::score( $item[ $field ] ?? null, $health ), 'state' => $health ];
		}
		return [ 'score' => self::score( $item['overallScore'] ?? null, $state ), 'state' => $state, 'metrics' => $metrics ];
	}

	/** ScoreHistory is a collection. Its records have no health state and may arrive in any order. */
	private static function observe_history( array &$state, array $row ): void {
		++$state['history_count'];
		$score = self::score( $row['overallScore'] ?? null );
		$date  = (string) ( $row['startupDateTime'] ?? '' );
		if ( null === $score || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date ) ) {
			return;
		}
		try {
			$instant = new \DateTimeImmutable( $date );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
				return;
			}
		} catch ( \Exception $ignored ) {
			return;
		}
		$date = $instant->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.v\Z' );
		if ( $date <= ( $state['historical']['measuredAt'] ?? '' ) ) {
			return;
		}
		$metrics = [];
		foreach ( [ 'boot' => 'startupScore', 'apps' => 'appHealthOverallScore', 'work' => 'workFromAnywhereScore', 'battery' => 'batteryHealthScore' ] as $key => $field ) {
			$value = self::score( $row[ $field ] ?? null );
			if ( null !== $value ) {
				$metrics[] = [ 'metric' => $key, 'score' => $value, 'state' => '' ];
			}
		}
		$state['historical'] = [ 'score' => $score, 'state' => '', 'metrics' => $metrics, 'measuredAt' => $date, 'source' => 'history' ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		$devices = 'devices' === ( $params['section'] ?? '' );
		if ( ! $devices && 0 === $state['phase'] ) {
			try {
				$state['overview'] = self::overview( $this->graph->get( self::ROOT . 'Overview' ) );
			} catch ( Graph_Exception $error ) {
				$this->optional_error( $state, 'Pontuação do tenant indisponível', $error );
				$state['overview_error'] = $error->report_issue();
				$state['use_history'] = $error->resource_missing();
			}
			$state['phase'] = 1;
		}

		$map = $devices ? self::device(...) : static function ( array $baseline ): array {
			return [ 'id' => (string) ( $baseline['id'] ?? '' ), 'name' => (string) ( $baseline['displayName'] ?? '' ),
				'score' => self::score( $baseline['overallScore'] ?? null ), 'builtIn' => $baseline['isBuiltIn'] ?? null,
				'created' => (string) ( $baseline['createdDateTime'] ?? '' ) ];
		};
		try {
			$done = $this->fetch_collection( $state, 'rows', self::ROOT . ( $devices ? 'DeviceScores' : 'Baselines' ), $deadline, [], $map );
		} catch ( Graph_Exception $error ) {
			if ( $devices && empty( $state['rows'] ) ) {
				throw $error;
			}
			$this->optional_error( $state, $devices ? 'Lista de dispositivos incompleta' : 'Referências de comparação indisponíveis', $error );
			if ( ! $devices && empty( $state['use_history'] ) && ! empty( $state['overview_error'] ) && empty( $state['rows'] ) ) {
				$first = $state['overview_error'];
				throw new Graph_Exception( 'O Intune não disponibilizou o resumo do Endpoint Analytics.', $first['status'], $first['code'] );
			}
			$state['partial'] = true;
			$state['rows_done'] = true;
			$done = true;
		}
		if ( $done && ! empty( $state['use_history'] ) ) {
			$state['phase'] = 2;
			$state['history_count'] ??= 0;
			try {
				$done = $this->fetch_collection( $state, 'history', self::ROOT . 'ScoreHistory', $deadline, [], null, self::observe_history(...) );
				$state['history_status'] = $done ? ( isset( $state['historical'] ) ? 'ok' : 'empty' ) : 'loading';
			} catch ( Graph_Exception $error ) {
				$this->optional_error( $state, 'Histórico de experiência indisponível', $error );
				$state['history_status'] = isset( $state['historical'] ) ? 'partial' : 'unavailable';
				$done = true;
			}
		}
		return [ 'state' => $state, 'done' => $done, 'progress' => count( $state['rows'] ?? [] ) + ( $state['history_count'] ?? 0 ), 'total' => 0,
			'message' => $devices ? 'Consultando pontuações por dispositivo…' : 'Consultando referências do Endpoint Analytics…' ];
	}

	public function finalize( array $state, array $params ): array {
		$rows = $state['rows'] ?? [];
		return [ 'overview' => $state['overview'] ?? $state['historical'] ?? null, 'rows' => $rows, 'partial' => ! empty( $state['partial'] ),
			'historyStatus' => $state['history_status'] ?? null, '_meta' => [ 'rows' => count( $rows ) ] ];
	}
}
