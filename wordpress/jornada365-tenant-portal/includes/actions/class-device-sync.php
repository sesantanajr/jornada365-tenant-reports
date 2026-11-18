<?php
namespace J365TP\Actions;

use J365TP\Action_Auth;
use J365TP\Action_Token;
use J365TP\Graph_Client;
use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;
use J365TP\Reports\Report_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intune remote actions for selected managed devices, with the signed-in admin's delegated token
 * (DeviceManagementManagedDevices.PrivilegedOperations.All + the admin's Intune RBAC):
 *   sync           → POST …/syncDevice                       (check-in now)
 *   restart        → POST …/rebootNow                        (restart within ~5 minutes)
 *   quickScan      → POST …/windowsDefenderScan {quickScan: true}   (Windows)
 *   fullScan       → POST …/windowsDefenderScan {quickScan: false}  (Windows)
 *   defenderUpdate → POST …/windowsDefenderUpdateSignatures         (Windows)
 * The portal offers each action only to platforms Microsoft documents as supported; Graph still
 * validates every device (400 = not applicable, 403 = outside the admin's RBAC/scope tags).
 *
 * Graph $batch (20 per call), at most 160 commands per step followed by a 20 s pause — below the
 * Intune devices service write limit (200 requests / 20 s per app and tenant). Progress is saved
 * per batch: throttled sub-requests are queued and retried after Retry-After (never re-sent with
 * the rest), and if the authorization expires or the tenant turns actions off mid-way the job ends
 * with the results so far ("não processado" for the rest) instead of losing them.
 */
final class Device_Sync extends Report_Base {

	public const CAP       = 'intune';
	public const MAX       = 1000;
	/** Actions that interrupt the user or the device: the request must carry "action:count". */
	public const CONFIRM   = [ 'restart', 'fullScan' ];
	/** action key → [Graph path segment, JSON body or null, Intune RBAC permission named in 403 messages] */
	public const ACTIONS   = [
		'sync'           => [ 'syncDevice', null, 'Remote tasks → Sync devices' ],
		'restart'        => [ 'rebootNow', null, 'Remote tasks → Reboot now' ],
		'quickScan'      => [ 'windowsDefenderScan', [ 'quickScan' => true ], 'Remote tasks → Windows defender' ],
		'fullScan'       => [ 'windowsDefenderScan', [ 'quickScan' => false ], 'Remote tasks → Windows defender' ],
		'defenderUpdate' => [ 'windowsDefenderUpdateSignatures', null, 'Remote tasks → Windows defender' ],
	];
	private const PER_STEP = 160;
	private const PAUSE    = 20;
	private const GUID     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	private ?string $token = null;
	private ?array $session = null;

	public function set_session( array $session ): void {
		$this->session = $session;
		$this->token   = Action_Token::get( $session, self::CAP );
		if ( $this->token ) {
			$this->graph = new Graph_Client( $this->tenant_id, $this->token );
		}
	}

	public function normalize_params( array $params ): array {
		$ids   = [];
		$names = [];
		$os    = [];
		$submitted = count( (array) ( $params['devices'] ?? [] ) );
		if ( $submitted > self::MAX ) {
			throw new \InvalidArgumentException( sprintf( 'Selecione no máximo %d dispositivos por execução.', self::MAX ) );
		}
		foreach ( (array) ( $params['devices'] ?? [] ) as $d ) {
			$id = strtolower( (string) ( is_array( $d ) ? ( $d['id'] ?? '' ) : $d ) );
			if ( ! preg_match( self::GUID, $id ) || isset( $ids[ $id ] ) ) {
				continue;
			}
			$ids[ $id ]   = true;
			$names[ $id ] = mb_substr( trim( wp_strip_all_tags( (string) ( $d['name'] ?? '' ) ) ), 0, 80 );
			$os[ $id ]    = mb_substr( trim( wp_strip_all_tags( (string) ( $d['os'] ?? '' ) ) ), 0, 30 );
			if ( count( $ids ) >= self::MAX ) {
				break;
			}
		}
		if ( ! $ids ) {
			throw new \InvalidArgumentException( 'Nenhum dispositivo válido selecionado.' );
		}
		$action = self::action_key( (string) ( $params['action'] ?? 'sync' ) );
		if ( '' === $action ) {
			throw new \InvalidArgumentException( 'Ação desconhecida.' );
		}
		// Disruptive actions carry the confirmation the dialog collected, bound to the confirmed selection
		// ("action:count", count as submitted; invalid entries dropped above only make the run smaller).
		if ( in_array( $action, self::CONFIRM, true ) && $action . ':' . $submitted !== (string) ( $params['confirm'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'A confirmação não corresponde à seleção. Abra a ação de novo e confirme.' );
		}
		return [ 'ids' => array_keys( $ids ), 'names' => $names, 'os' => $os, 'action' => $action ];
	}

	/** Validated action key ('' when unknown). */
	public static function action_key( string $action ): string {
		return isset( self::ACTIONS[ $action ] ) ? $action : '';
	}

	/** Rough duration, so the authorization is not about to expire when the job starts (seconds). */
	public static function estimate( int $count ): int {
		return (int) ceil( max( 1, $count ) / self::PER_STEP ) * ( self::PAUSE + 10 );
	}

	public function initial_state( array $params ): array {
		return [ 'i' => 0, 'res' => [], 'retry' => [], 'halt' => '', '_warnings' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		$ids   = (array) $params['ids'];
		$total = count( $ids );
		if ( '' === ( $state['halt'] ?? '' ) && ! $this->token ) {
			$state['halt'] = 'auth';
		}
		if ( '' === ( $state['halt'] ?? '' ) && $this->session && ! Action_Auth::allowed( $this->session, self::CAP ) ) {
			$state['halt'] = 'off'; // the tenant turned actions off (or the role changed) while the job ran
		}
		$sent = 0;
		while ( '' === $state['halt'] && $sent < self::PER_STEP && microtime( true ) < $deadline - 4 ) {
			// Throttled devices first (after their pause), then the next ones in order.
			$chunk = array_splice( $state['retry'], 0, 20 );
			if ( count( $chunk ) < 20 && $state['i'] < $total ) {
				$take          = array_slice( $ids, (int) $state['i'], 20 - count( $chunk ) );
				$state['i']   += count( $take );
				$chunk         = array_merge( $chunk, $take );
			}
			if ( ! $chunk ) {
				break;
			}
			[ $segment, $payload ] = self::ACTIONS[ $params['action'] ?? 'sync' ];
			$reqs                  = [];
			foreach ( $chunk as $k => $id ) {
				$req = [ 'id' => (string) $k, 'method' => 'POST', 'url' => '/deviceManagement/managedDevices/' . $id . '/' . $segment ];
				if ( null !== $payload ) {
					$req['body']    = $payload;
					$req['headers'] = [ 'Content-Type' => 'application/json' ];
				}
				$reqs[] = $req;
			}
			try {
				$res = $this->graph->batch( $reqs, false );
			} catch ( Graph_Throttled $e ) {
				// The batch itself was refused: nothing in it ran. Queue it again and pause the job.
				$state['retry']     = array_merge( $chunk, $state['retry'] );
				$state['_retry_at'] = time() + max( 1, $e->retry_after );
				return $this->progress( $state, $total );
			} catch ( Graph_Exception $e ) {
				if ( 401 === $e->getCode() ) {
					$state['retry'] = array_merge( $chunk, $state['retry'] );
					$state['halt']  = 'auth';
					break;
				}
				foreach ( $chunk as $id ) {
					$state['res'][ $id ] = [ 's' => 'error', 'm' => mb_substr( $e->getMessage(), 0, 200 ) ];
				}
				$sent += count( $chunk );
				continue;
			}
			$retry_after = 0;
			foreach ( $chunk as $k => $id ) {
				$r = $res[ (string) $k ] ?? [ 'status' => 0, 'body' => null ];
				if ( 429 === (int) $r['status'] ) {
					$state['retry'][] = $id; // Explicitly rejected by Graph.
					$retry_after = max( $retry_after, (int) ( $r['retryAfter'] ?? self::PAUSE ) );
					continue;
				}
				if ( 0 === (int) $r['status'] || (int) $r['status'] >= 500 ) {
					$state['res'][ $id ] = [ 's' => 'error', 'm' => 'A Microsoft não confirmou a ação. Confira o dispositivo antes de repeti-la.' ];
					continue;
				}
				$state['res'][ $id ] = self::outcome( (int) $r['status'], $r['body'], (string) ( $params['action'] ?? 'sync' ) );
			}
			$sent += count( $chunk );
			if ( $retry_after ) {
				$state['_retry_at'] = time() + min( 86400, $retry_after );
				return $this->progress( $state, $total );
			}
		}
		if ( '' === $state['halt'] && $sent >= self::PER_STEP && ( $state['i'] < $total || $state['retry'] ) ) {
			$state['_retry_at'] = time() + self::PAUSE; // stay below 200 requests / 20 s
		}
		return $this->progress( $state, $total );
	}

	private function progress( array $state, int $total ): array {
		$done = '' !== $state['halt'] || ( $state['i'] >= $total && ! $state['retry'] );
		$n    = count( $state['res'] );
		return [ 'state' => $state, 'progress' => $done ? $total : $n, 'total' => $total, 'done' => $done, 'message' => $done ? 'Concluído' : sprintf( '%d de %d dispositivos…', $n, $total ) ];
	}

	/** @return array{s: string, m?: string} */
	public static function outcome( int $status, $body, string $action = 'sync' ): array {
		$msg  = is_array( $body ) ? (string) ( $body['error']['message'] ?? '' ) : '';
		$rbac = self::ACTIONS[ $action ][2] ?? self::ACTIONS['sync'][2];
		return match ( true ) {
			$status >= 200 && $status < 300 => [ 's' => 'ok' ],
			404 === $status                  => [ 's' => 'not_found', 'm' => 'Dispositivo não encontrado no Intune (removido ou desmatriculado).' ],
			403 === $status                  => [ 's' => 'forbidden', 'm' => sprintf( 'Sem permissão no Intune para este dispositivo (RBAC: %s, ou fora das suas scope tags).', $rbac ) ],
			400 === $status                  => [ 's' => 'unsupported', 'm' => '' !== $msg ? mb_substr( $msg, 0, 200 ) : 'A plataforma ou o estado do dispositivo não aceita esta ação.' ],
			default                          => [ 's' => 'error', 'm' => '' !== $msg ? mb_substr( $msg, 0, 200 ) : 'Falha HTTP ' . $status . '.' ],
		};
	}

	public function finalize( array $state, array $params ): array {
		$halt    = (string) ( $state['halt'] ?? '' );
		$skipped = 'auth' === $halt ? 'Não processado: a autorização expirou durante a execução. Autorize de novo e repita estes itens.' : ( 'off' === $halt ? 'Não processado: as ações foram desativadas neste tenant durante a execução.' : 'Não processado.' );
		$rows    = [];
		$summary = [ 'total' => count( (array) $params['ids'] ), 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'halted' => $halt, 'byStatus' => [] ];
		foreach ( (array) $params['ids'] as $id ) {
			$r      = $state['res'][ $id ] ?? [ 's' => 'skipped', 'm' => $skipped ];
			$rows[] = [ 'id' => $id, 'name' => (string) ( $params['names'][ $id ] ?? '' ), 'os' => (string) ( $params['os'][ $id ] ?? '' ), 'status' => $r['s'], 'message' => (string) ( $r['m'] ?? '' ) ];
			$summary[ 'ok' === $r['s'] ? 'ok' : ( 'skipped' === $r['s'] ? 'skipped' : 'failed' ) ]++;
			$summary['byStatus'][ $r['s'] ] = ( $summary['byStatus'][ $r['s'] ] ?? 0 ) + 1;
		}
		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ), 'action' => (string) ( $params['action'] ?? 'sync' ) ] ];
	}
}
