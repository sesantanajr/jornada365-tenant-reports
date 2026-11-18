<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Microsoft Secure Score: latest score, trend, and control profiles with current state.
 * SecurityEvents.Read.All.
 */
final class Secure_Score extends Report_Base {

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			try {
				$scores = $this->graph->get( '/v1.0/security/secureScores?$top=8' );
			} catch ( Graph_Exception $e ) {
				if ( $e->is_forbidden() ) {
					throw new Graph_Exception( 'Secure Score indisponível: ' . $e->getMessage() . ' (requer SecurityEvents.Read.All).', $e->getCode(), $e->graph_code() );
				}
				throw $e;
			}
			$list = $scores['value'] ?? [];
			$state['latest'] = $list[0] ?? [];
			$state['trend']  = array_map( static fn( $s ) => [ 'date' => (string) ( $s['createdDateTime'] ?? '' ), 'current' => (float) ( $s['currentScore'] ?? 0 ), 'max' => (float) ( $s['maxScore'] ?? 0 ) ], $list );
			$state['phase']  = 1;
		}
		try {
			$done = $this->fetch_collection(
				$state, 'profile_rows', '/v1.0/security/secureScoreControlProfiles', $deadline, [],
				static fn( $p ) => [
					'id'          => strtolower( (string) $p['id'] ),
					'title'       => (string) ( $p['title'] ?? $p['id'] ),
					'category'    => (string) ( $p['controlCategory'] ?? '' ),
					'maxScore'    => (float) ( $p['maxScore'] ?? 0 ),
					'remediation' => (string) ( $p['remediation'] ?? '' ),
					'userImpact'  => (string) ( $p['userImpact'] ?? '' ),
					'tier'        => (string) ( $p['tier'] ?? '' ),
					'deprecated'  => ! empty( $p['deprecated'] ),
					'override'    => self::override_state( (array) ( $p['controlStateUpdates'] ?? [] ) ),
				]
			);
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['profile_rows'] ), 'total' => 0, 'done' => false, 'message' => 'Carregando perfis de controle do Secure Score…' ];
			}
		} catch ( Graph_Exception $e ) {
			$this->optional_error( $state, 'Perfis de controle indisponíveis', $e );
		}
		$state['profiles'] = array_column( $state['profile_rows'], null, 'id' );
		unset( $state['profile_rows'], $state['profile_rows_next'], $state['profile_rows_done'] );
		return [ 'state' => $state, 'progress' => 1, 'total' => 1, 'done' => true, 'message' => 'Concluído' ];
	}

	/** Latest admin decision on the control (Ignored = risk accepted, ThirdParty, Reviewed); '' = default. */
	private static function override_state( array $updates ): string {
		$last = '';
		$when = '';
		foreach ( $updates as $u ) {
			$d = (string) ( $u['updatedDateTime'] ?? '' );
			if ( '' === $when || strcmp( $d, $when ) >= 0 ) {
				$when = $d;
				$last = (string) ( $u['state'] ?? '' );
			}
		}
		return match ( strtolower( $last ) ) {
			'ignored'    => 'Risco aceito',
			'thirdparty' => 'Resolvido por terceiro',
			'reviewed'   => 'Revisado',
			default      => '',
		};
	}

	/** controlScore in v1.0 has no implementationStatus: derive it from score vs. the profile maxScore. */
	private static function status( float $score, float $max ): string {
		if ( $max > 0 && $score >= $max ) {
			return 'Concluído';
		}
		return $score > 0 ? 'Parcial' : 'Pendente';
	}

	public function finalize( array $state, array $params ): array {
		$latest   = $state['latest'] ?? [];
		$profiles = $state['profiles'] ?? [];
		$current  = (float) ( $latest['currentScore'] ?? 0 );
		$max      = (float) ( $latest['maxScore'] ?? 0 );

		$controls = [];
		$byCat    = [];
		foreach ( (array) ( $latest['controlScores'] ?? [] ) as $c ) {
			$name     = strtolower( (string) ( $c['controlName'] ?? '' ) );
			$prof     = $profiles[ $name ] ?? [];
			if ( ! empty( $prof['deprecated'] ) ) {
				continue;
			}
			$score    = (float) ( $c['score'] ?? 0 );
			$maxc     = (float) ( $prof['maxScore'] ?? 0 );
			$cat      = (string) ( $c['controlCategory'] ?? ( $prof['category'] ?? '' ) );
			$controls[] = [
				'name'        => $prof['title'] ?? ( $c['controlName'] ?? '' ),
				'category'    => $cat,
				'score'       => $score,
				'max'         => $maxc,
				'gap'         => max( 0, $maxc - $score ),
				'implemented' => $maxc > 0 ? (int) round( 100 * $score / $maxc ) : null,
				'state'       => ( $prof['override'] ?? '' ) ?: self::status( $score, $maxc ),
				'description' => wp_strip_all_tags( (string) ( $c['description'] ?? '' ) ),
				'remediation' => $prof['remediation'] ?? '',
				'userImpact'  => $prof['userImpact'] ?? '',
			];
			$byCat[ $cat ] = ( $byCat[ $cat ] ?? 0 ) + $score;
		}
		usort( $controls, fn( $a, $b ) => $b['gap'] <=> $a['gap'] );
		arsort( $byCat );

		return [
			'summary' => [
				'current'    => $current,
				'max'        => $max,
				'percent'    => $max > 0 ? round( 100 * $current / $max, 1 ) : null,
				'date'       => (string) ( $latest['createdDateTime'] ?? '' ),
				'activeUsers' => (int) ( $latest['activeUserCount'] ?? 0 ),
				'licensedUsers' => (int) ( $latest['licensedUserCount'] ?? 0 ),
				'controls'   => count( $controls ),
				'byCategory' => $byCat,
			],
			'trend'   => array_reverse( $state['trend'] ?? [] ),
			'rows'    => $controls,
			'_meta'   => [ 'rows' => count( $controls ) ],
		];
	}
}
