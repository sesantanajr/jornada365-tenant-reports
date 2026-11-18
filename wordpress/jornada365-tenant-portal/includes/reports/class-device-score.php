<?php
namespace J365TP\Reports;

use J365TP\Graph_Client;
use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Intune Endpoint analytics. The tenant score is supplied by the service; device rows load separately. */
final class Device_Score extends Report_Base {
	private const ROOT = '/v1.0/deviceManagement/userExperienceAnalytics';
	private const BETA_OVERVIEW = '/beta/deviceManagement/userExperienceAnalyticsOverview';
	private const BASELINE_CATEGORIES = [
		'boot' => 'deviceBootPerformanceMetrics', 'apps' => 'appHealthMetrics', 'work' => 'workFromAnywhereMetrics',
		'resources' => 'resourcePerformanceMetrics', 'battery' => 'batteryHealthMetrics',
	];
	private const CATEGORIES = [
		'boot'      => [ 'deviceBootPerformanceOverallScore', 'deviceBootPerformanceHealthState' ],
		'apps'      => [ 'appHealthOverallScore', 'appHealthState' ],
		'work'      => [ 'workFromAnywhereOverallScore', 'workFromAnywhereHealthState' ],
		'resources' => [ 'resourcePerformanceOverallScore', 'resourcePerformanceHealthState' ],
		'battery'   => [ 'batteryHealthOverallScore', 'batteryHealthState' ],
		'practices' => [ 'bestPracticesOverallScore', 'bestPracticesHealthState' ],
	];

	public function normalize_params( array $params ): array {
		if ( 'baseline' === ( $params['section'] ?? '' ) ) {
			$id = $params['id'] ?? '';
			return self::valid_id( $id ) ? [ 'section' => 'baseline', 'id' => $id ] : [];
		}
		return 'devices' === ( $params['section'] ?? '' ) ? [ 'section' => 'devices' ] : [];
	}

	private static function valid_id( $id ): bool {
		return is_string( $id ) && (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,159}$/D', $id );
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
		$item = Graph_Client::entity( $item );
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

	private static function baseline( array $item ): array {
		$item = Graph_Client::entity( $item );
		$metrics = [];
		foreach ( self::BASELINE_CATEGORIES as $key => $relation ) {
			if ( is_array( $item[ $relation ] ?? null ) ) {
				$category = Graph_Client::entity( $item[ $relation ] );
				if ( $category ) {
					$metrics[ $key ] = self::score( $category['overallScore'] ?? null, (string) ( $category['state'] ?? '' ) );
				}
			}
		}
		return [
			'id' => (string) ( $item['id'] ?? '' ), 'name' => (string) ( $item['displayName'] ?? '' ),
			'score' => self::score( $item['overallScore'] ?? null ), 'builtIn' => $item['isBuiltIn'] ?? null,
			'created' => (string) ( $item['createdDateTime'] ?? '' ), 'metrics' => $metrics,
		];
	}

	/** Documented singleton and category navigation, in one read-only batch for the selected baseline. */
	private function read_baseline_detail( array &$state, array &$row ): bool {
		$root = '/deviceManagement/userExperienceAnalyticsBaselines/' . rawurlencode( $row['id'] );
		$requests = [];
		if ( null === $row['score'] ) {
			$requests[] = [ 'id' => 'summary', 'method' => 'GET', 'url' => $root ];
		}
		foreach ( self::BASELINE_CATEGORIES as $key => $relation ) {
			if ( ! array_key_exists( $key, $row['metrics'] ) ) {
				$requests[] = [ 'id' => $key, 'method' => 'GET', 'url' => $root . '/' . $relation ];
			}
		}
		if ( ! $requests ) {
			return true;
		}
		try {
			$responses = $this->fetch_batch( $state, 'baseline_detail', $requests );
		} catch ( Graph_Exception $error ) {
			$this->optional_error( $state, 'Detalhes da referência indisponíveis', $error );
			return true;
		}
		if ( null === $responses ) {
			return false;
		}
		foreach ( $responses as $key => $response ) {
			$body = (array) ( $response['body'] ?? [] );
			if ( 200 !== $response['status'] ) {
				$this->optional_error( $state, 'Detalhes da referência indisponíveis', new Graph_Exception( '', $response['status'], (string) ( $body['error']['code'] ?? '' ) ) );
				continue;
			}
			$body = Graph_Client::entity( $body );
			if ( 'summary' === $key ) {
				$row['score'] = self::score( $body['overallScore'] ?? null );
				$row['name'] = (string) ( $body['displayName'] ?? $row['name'] );
			} else {
				$row['metrics'][ $key ] = self::score( $body['overallScore'] ?? null, (string) ( $body['state'] ?? '' ) );
			}
		}
		return true;
	}

	/** Only a missing or fieldless stable singleton warrants a second read of the current summary. */
	private function read_overview( array &$state, float $deadline ): bool {
		$beta = ! empty( $state['try_beta'] );
		while ( ! $this->out_of_time( $deadline ) ) {
			try {
				$org = self::overview( $this->graph->get( $beta ? self::BETA_OVERVIEW : self::ROOT . 'Overview' ) );
				if ( null !== $org['score'] || '' !== $org['state'] || $org['metrics'] ) {
					$state['overview'] = $org + [ 'source' => $beta ? 'beta' : 'v1.0' ];
					return true;
				}
				$error = new Graph_Exception( 'O resumo não contém campos de experiência.', 200, 'NoScoreFields' );
			} catch ( Graph_Throttled $pause ) {
				$this->pause_read( $state, $pause->retry_after, $pause->reason );
				return false;
			} catch ( Graph_Exception $error ) {
				if ( ! $beta && ! $error->resource_missing() ) {
					$this->optional_error( $state, 'Pontuação do tenant indisponível', $error );
					$state['overview_error'] = $error->report_issue();
					return true;
				}
			}
			if ( ! $beta ) {
				$state['overview_error'] = $error->report_issue();
				$beta = $state['try_beta'] = true;
				continue;
			}
			$first = $state['overview_error'];
			$this->optional_error( $state, 'Pontuação do tenant indisponível', new Graph_Exception( '', $first['status'], $first['code'] ) );
			$this->optional_error( $state, 'Resumo alternativo do Intune indisponível', $error );
			$state['use_history'] = true;
			$state['try_app_category'] = $error->resource_missing() || 'NoScoreFields' === $error->graph_code();
			return true;
		}
		return false;
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
		if ( 'baseline' === ( $params['section'] ?? '' ) && self::valid_id( $params['id'] ?? null ) ) {
			$state['rows'] ??= [ self::baseline( [ 'id' => $params['id'] ] ) ];
			$done = $this->read_baseline_detail( $state, $state['rows'][0] );
			return [ 'state' => $state, 'done' => $done, 'progress' => 0, 'total' => 0, 'message' => 'Consultando referências do Endpoint Analytics…' ];
		}
		$devices = 'devices' === ( $params['section'] ?? '' );
		if ( ! $devices && 0 === $state['phase'] ) {
			if ( ! $this->read_overview( $state, $deadline ) ) {
				return [ 'state' => $state, 'done' => false, 'progress' => 0, 'total' => 0, 'message' => 'Consultando o resumo do Endpoint Analytics…' ];
			}
			$state['phase'] = 1;
		}

		$expand = ! $devices && empty( $state['plain_baselines'] );
		$path = self::ROOT . ( $devices ? 'DeviceScores' : 'Baselines' );
		if ( $expand ) {
			$path .= '?$expand=' . implode( ',', self::BASELINE_CATEGORIES );
		}
		try {
			$done = $this->fetch_collection( $state, 'rows', $path, $deadline, [], $devices ? self::device(...) : self::baseline(...) );
		} catch ( Graph_Exception $error ) {
			if ( $devices && empty( $state['rows'] ) ) {
				throw $error;
			}
			if ( $expand && empty( $state['rows'] ) && empty( $state['rows_next'] ) && in_array( $error->getCode(), [ 400, 501 ], true ) ) {
				$this->optional_error( $state, 'Categorias das referências indisponíveis', $error );
				$state['plain_baselines'] = true;
				return [ 'state' => $state, 'done' => false, 'progress' => 0, 'total' => 0, 'message' => 'Consultando referências do Endpoint Analytics…' ];
			}
			$this->optional_error( $state, $devices ? 'Lista de dispositivos incompleta' : 'Referências de comparação indisponíveis', $error );
			if ( ! $devices && empty( $state['use_history'] ) && empty( $state['overview'] ) && ! empty( $state['overview_error'] ) && empty( $state['rows'] ) ) {
				$first = $state['overview_error'];
				throw new Graph_Exception( 'O Intune não disponibilizou o resumo do Endpoint Analytics.', $first['status'], $first['code'] );
			}
			$state['partial'] = true;
			$state['rows_done'] = true;
			$done = true;
		}
		if ( $done && ! $devices && empty( $state['baseline_detail_done'] ) && 1 === count( $state['rows'] ?? [] ) ) {
			$row = $state['rows'][0];
			if ( false === $row['builtIn'] && null === $row['score'] && self::valid_id( $row['id'] ) ) {
				$done = $this->read_baseline_detail( $state, $state['rows'][0] );
			}
			$state['baseline_detail_done'] = $done;
		}
		if ( $done && ! empty( $state['use_history'] ) && empty( $state['history_done'] ) ) {
			$state['phase'] = 2;
			$state['history_count'] ??= 0;
			try {
				$done = $this->fetch_collection( $state, 'history', self::ROOT . 'ScoreHistory', $deadline, [], null, self::observe_history(...) );
				$state['history_status'] = $done ? ( isset( $state['historical'] ) ? 'ok' : 'empty' ) : 'loading';
			} catch ( Graph_Exception $error ) {
				$this->optional_error( $state, 'Histórico de experiência indisponível', $error );
				$state['history_status'] = isset( $state['historical'] ) ? 'partial' : 'unavailable';
				$state['history_done'] = true;
				$state['try_app_category'] = ! empty( $state['try_app_category'] ) && $error->resource_missing();
				$done = true;
			}
		}
		if ( $done && ! empty( $state['try_app_category'] ) && empty( $state['historical'] ) ) {
			try {
				$category = Graph_Client::entity( $this->graph->get( self::ROOT . 'AppHealthOverview' ) );
				$health = (string) ( $category['state'] ?? '' );
				$value = self::score( $category['overallScore'] ?? null, $health );
				if ( null !== $value ) {
					$state['overview'] = [ 'score' => null, 'state' => '', 'source' => 'appCategory',
						'metrics' => [ [ 'metric' => 'apps', 'score' => $value, 'state' => $health ] ] ];
				}
			} catch ( Graph_Throttled $pause ) {
				$this->pause_read( $state, $pause->retry_after, $pause->reason );
				$done = false;
			} catch ( Graph_Exception $error ) {
				$this->optional_error( $state, 'Categoria de confiabilidade indisponível', $error );
			}
		}
		return [ 'state' => $state, 'done' => $done, 'progress' => count( $state['rows'] ?? [] ) + ( $state['history_count'] ?? 0 ), 'total' => 0,
			'message' => $devices ? 'Consultando pontuações por dispositivo…' : 'Consultando referências do Endpoint Analytics…' ];
	}

	public function finalize( array $state, array $params ): array {
		$rows = $state['rows'] ?? [];
		return [ 'overview' => $state['overview'] ?? $state['historical'] ?? null, 'rows' => $rows, 'partial' => ! empty( $state['partial'] ) || ! empty( $state['_issues'] ),
			'historyStatus' => $state['history_status'] ?? null, '_meta' => [ 'rows' => count( $rows ) ] ];
	}
}
