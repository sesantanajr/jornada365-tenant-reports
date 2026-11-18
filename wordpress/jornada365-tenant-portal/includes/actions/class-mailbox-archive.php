<?php
namespace J365TP\Actions;

use J365TP\Action_Auth;
use J365TP\Action_Token;
use J365TP\Exchange_Client;
use J365TP\Graph_Exception;
use J365TP\Graph_Throttled;
use J365TP\Reports\Report_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archive management per mailbox, with the signed-in admin's delegated Exchange token and Exchange RBAC.
 *
 * op "enable"  1. Enable-Mailbox -Archive              (always sent: the usage report can be 48 h old;
 *                                                       "already has an archive" counts as enabled. An archive
 *                                                       disabled more than 30 days ago blocks it — then
 *                                                       Set-Mailbox -RemoveDisabledArchive clears the purged
 *                                                       reference and the archive is created again)
 *              2. Enable-Mailbox -AutoExpandingArchive (only when licensed and asked — irreversible)
 *              3. Start-ManagedFolderAssistant         (retention/auto-archiving runs now, not within 7 days)
 * op "disable" Disable-Mailbox -Archive               (typed confirmation in the UI; the archive can be
 *                                                       reconnected for 30 days, then Microsoft purges it)
 * op "process" Start-ManagedFolderAssistant only
 *
 * Progress is saved after every cmdlet. Throttling pauses the job without re-running what already
 * ran; an expired authorization or actions turned off mid-way end the job with the results so far.
 */
final class Mailbox_Archive extends Report_Base {

	public const CAP = 'exchange';
	public const MAX = 200;
	/** Phases run for each operation (0 archive, 1 auto-expanding, 2 Managed Folder Assistant). */
	public const OPS = [ 'enable' => [ 0, 1, 2 ], 'disable' => [ 0 ], 'process' => [ 2 ] ];
	/** Exchange error that means "the archive was disabled more than 30 days ago". */
	private const PURGED_ARCHIVE = '/disconnect of shard|MissingDisconnectReceipts/i';
	/** Exchange answers for Disable-Mailbox -Archive on a mailbox that has no archive. */
	private const NO_ARCHIVE = "/(does ?n[o'’]t|doesn't|does not) have an? archive|archive (is not|isn't|isn’t) enabled|not archive[- ]enabled|no archive/iu";

	private ?Exchange_Client $ex = null;
	private ?array $session = null;

	public function set_session( array $session ): void {
		$this->session = $session;
		$token         = Action_Token::get( $session, self::CAP );
		if ( $token ) {
			$this->ex = new Exchange_Client( $this->tenant_id, $token );
		}
	}

	public function set_deadline( float $deadline ): void {
		parent::set_deadline( $deadline );
		if ( $this->ex ) {
			$this->ex->set_deadline( $deadline );
		}
	}

	/** Validated operation ('' when unknown). */
	public static function op_key( string $op ): string {
		return isset( self::OPS[ $op ] ) ? $op : '';
	}

	public function normalize_params( array $params ): array {
		$op = self::op_key( (string) ( $params['op'] ?? 'enable' ) );
		if ( '' === $op ) {
			throw new \InvalidArgumentException( 'Operação desconhecida.' );
		}
		$submitted = count( (array) ( $params['mailboxes'] ?? [] ) );
		if ( $submitted > self::MAX ) {
			throw new \InvalidArgumentException( sprintf( 'Selecione no máximo %d caixas de correio por execução.', self::MAX ) );
		}
		$list = [];
		foreach ( (array) ( $params['mailboxes'] ?? [] ) as $m ) {
			$upn = strtolower( trim( (string) ( is_array( $m ) ? ( $m['upn'] ?? '' ) : $m ) ) );
			if ( ! preg_match( '/^[^@\s"<>]{1,64}@[a-z0-9.-]{3,253}\.[a-z]{2,63}$/i', $upn ) || isset( $list[ $upn ] ) ) {
				continue;
			}
			$list[ $upn ] = [
				'upn'      => $upn,
				'name'     => mb_substr( trim( wp_strip_all_tags( (string) ( $m['name'] ?? '' ) ) ), 0, 80 ),
				'eligible' => ! empty( $m['eligible'] ),
			];
			if ( count( $list ) >= self::MAX ) {
				break;
			}
		}
		if ( ! $list ) {
			throw new \InvalidArgumentException( 'Nenhuma caixa de correio válida selecionada (nomes ocultos pela Microsoft não podem receber ações).' );
		}
		$auto = 'enable' === $op && ! empty( $params['autoExpand'] );
		// Irreversible or destructive steps carry the confirmation the dialog collected, bound to the
		// selection that was confirmed ("disable-archive:<count>" / "auto-expand:<count>", count as
		// submitted) — a stray or replayed call with another selection is refused. Entries dropped by the
		// validation above only ever make the run smaller than what was confirmed, never larger.
		$need = 'disable' === $op ? 'disable-archive:' . $submitted : ( $auto ? 'auto-expand:' . $submitted : '' );
		if ( '' !== $need && $need !== (string) ( $params['confirm'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'disable' === $op ? 'Confirmação ausente para desativar o arquivo morto.' : 'Confirmação ausente para a expansão automática (irreversível).' );
		}
		return [
			'op'         => $op,
			'mailboxes'  => array_values( $list ),
			'autoExpand' => $auto,
			'mfa'        => 'process' === $op || ( 'enable' === $op && ( ! array_key_exists( 'mfa', $params ) || ! empty( $params['mfa'] ) ) ),
		];
	}

	/**
	 * Licence check done by the server (app-only, Directory.Read.All) right before the irreversible
	 * Enable-Mailbox -AutoExpandingArchive — the browser's "eligible" hint is never trusted for it.
	 * null = could not be confirmed.
	 */
	private function expand_licensed( string $upn ): ?bool {
		try {
			$u = $this->graph->get( '/v1.0/users/' . rawurlencode( $upn ) . '?$select=assignedPlans' );
		} catch ( Graph_Throttled $e ) {
			throw $e;
		} catch ( Graph_Exception $e ) {
			return null;
		}
		$codes = [];
		foreach ( (array) ( $u['assignedPlans'] ?? [] ) as $p ) {
			if ( 'Enabled' === ( $p['capabilityStatus'] ?? '' ) && 'exchange' === strtolower( (string) ( $p['service'] ?? '' ) ) ) {
				$codes[] = \J365TP\Sku_Catalog::plan_code( (string) ( $p['servicePlanId'] ?? '' ) );
			}
		}
		return (bool) preg_grep( \J365TP\Reports\Mailboxes::EXPAND_PLANS, array_filter( $codes ) );
	}

	/** Rough duration (seconds per cmdlet ≈ 3), so the authorization outlasts the job. */
	public static function estimate( int $count, bool $auto_expand, bool $mfa, string $op = 'enable' ): int {
		$per = match ( $op ) {
			'disable', 'process' => 1,
			default              => 1 + (int) $auto_expand + (int) $mfa,
		};
		return max( 1, $count ) * 3 * $per;
	}

	public function initial_state( array $params ): array {
		return [ 'i' => 0, 'ph' => 0, 'cur' => null, 'res' => [], 'halt' => '', '_warnings' => [] ];
	}

	/** Runs a cmdlet with -Confirm:$false; retried without it if this endpoint does not accept the parameter. */
	private function quiet( string $name, array $params, string $upn ): array {
		$x = $this->ex->cmdlet( $name, $params + [ 'Confirm' => false ], $upn );
		if ( ! $x['ok'] && preg_match( "/parameter name '?Confirm'?|parameter cannot be found.*Confirm/i", (string) $x['error'] ) ) {
			$x = $this->ex->cmdlet( $name, $params, $upn );
		}
		return $x;
	}

	public function step( array $state, array $params, float $deadline ): array {
		$list   = (array) $params['mailboxes'];
		$total  = count( $list );
		$op     = (string) ( $params['op'] ?? 'enable' );
		$phases = self::OPS[ $op ] ?? self::OPS['enable'];
		if ( '' === ( $state['halt'] ?? '' ) && ! $this->ex ) {
			$state['halt'] = 'auth';
		}
		if ( '' === ( $state['halt'] ?? '' ) && $this->session && ! Action_Auth::allowed( $this->session, self::CAP ) ) {
			$state['halt'] = 'off';
		}
		// Each cmdlet is bounded by the step deadline (Exchange_Client), so a step never outlives the job lock.
		while ( '' === $state['halt'] && $state['i'] < $total && microtime( true ) < $deadline - 8 ) {
			$m   = $list[ $state['i'] ];
			$upn = $m['upn'];
			$r   = is_array( $state['cur'] ) ? $state['cur'] : [ 'archive' => 'process' === $op ? 'off' : 'pending', 'expand' => 'off', 'mfa' => 'off', 'm' => [] ];
			$pi  = (int) $state['ph'];
			$ph  = $phases[ $pi ] ?? 2;
			try {
				if ( 0 === $ph && 'disable' === $op ) {
					$x = $this->quiet( 'Disable-Mailbox', [ 'Identity' => $upn, 'Archive' => true ], $upn );
					if ( $x['ok'] ) {
						$r['archive'] = 'ok';
					} elseif ( preg_match( self::NO_ARCHIVE, (string) $x['error'] ) ) {
						$r['archive'] = 'already';
					} else {
						$r['archive'] = 'error';
						$r['m'][]     = 'Arquivo: ' . $x['error'];
					}
				} elseif ( 0 === $ph ) {
					$x = $this->ex->cmdlet( 'Enable-Mailbox', [ 'Identity' => $upn, 'Archive' => true ], $upn );
					if ( ! $x['ok'] && ! $x['already'] && preg_match( self::PURGED_ARCHIVE, (string) $x['error'] ) ) {
						// Archive disabled > 30 days ago (already purged by Microsoft): clear the stale reference, create again.
						$fix = $this->quiet( 'Set-Mailbox', [ 'Identity' => $upn, 'RemoveDisabledArchive' => true ], $upn );
						$x   = $this->ex->cmdlet( 'Enable-Mailbox', [ 'Identity' => $upn, 'Archive' => true ], $upn );
						if ( $x['ok'] ) {
							$r['m'][] = 'Arquivo antigo (desativado há mais de 30 dias e já excluído pela Microsoft) removido; um novo arquivo foi criado.';
						} elseif ( ! $fix['ok'] ) {
							$x['error'] = $fix['error'];
						}
					}
					$r['archive'] = $x['ok'] ? 'ok' : ( $x['already'] ? 'already' : 'error' );
					if ( 'error' === $r['archive'] ) {
						$r['m'][] = 'Arquivo: ' . $x['error'];
					}
				} elseif ( 1 === $ph ) {
					if ( ! $params['autoExpand'] ) {
						$r['expand'] = 'off';
					} elseif ( 'error' === $r['archive'] ) {
						$r['expand'] = 'skipped';
					} elseif ( true !== ( $lic = $this->expand_licensed( $upn ) ) ) {
						$r['expand'] = 'unlicensed';
						if ( null === $lic ) {
							$r['m'][] = 'Expansão automática: não foi possível confirmar a licença no Microsoft Graph — nada foi alterado.';
						}
					} else {
						$x           = $this->ex->cmdlet( 'Enable-Mailbox', [ 'Identity' => $upn, 'AutoExpandingArchive' => true ], $upn );
						$r['expand'] = $x['ok'] ? 'ok' : ( $x['already'] ? 'already' : 'error' );
						if ( 'error' === $r['expand'] ) {
							$r['m'][] = 'Expansão automática: ' . $x['error'];
						}
					}
				} else {
					if ( ! $params['mfa'] ) {
						$r['mfa'] = 'off';
					} elseif ( 'error' === $r['archive'] ) {
						$r['mfa'] = 'skipped';
					} else {
						$x        = $this->ex->cmdlet( 'Start-ManagedFolderAssistant', [ 'Identity' => $upn ], $upn );
						$r['mfa'] = $x['ok'] ? 'ok' : 'error';
						if ( ! $x['ok'] ) {
							$r['m'][] = 'Processamento: ' . $x['error'];
						}
					}
				}
			} catch ( Graph_Throttled $e ) {
				$state['cur']       = $r; // the cmdlet was refused: it is sent again after the pause
				$state['_retry_at'] = time() + max( 1, $e->retry_after );
				return $this->progress( $state, $total );
			} catch ( Graph_Exception $e ) {
				if ( 'action_token_expired' !== $e->graph_code() ) {
					throw $e;
				}
				$state['cur']  = $r;
				$state['halt'] = 'auth';
				break;
			}
			if ( $pi >= count( $phases ) - 1 ) {
				$state['res'][ $upn ] = $r;
				$state['i']++;
				$state['ph']  = 0;
				$state['cur'] = null;
			} else {
				$state['ph']  = $pi + 1;
				$state['cur'] = $r;
			}
		}
		return $this->progress( $state, $total );
	}

	private function progress( array $state, int $total ): array {
		$done = '' !== $state['halt'] || $state['i'] >= $total;
		return [ 'state' => $state, 'progress' => $done ? $total : (int) $state['i'], 'total' => $total, 'done' => $done, 'message' => $done ? 'Concluído' : sprintf( '%d de %d caixas…', $state['i'], $total ) ];
	}

	public function finalize( array $state, array $params ): array {
		$halt    = (string) ( $state['halt'] ?? '' );
		$why     = 'auth' === $halt ? 'Não processado: a autorização expirou durante a execução. Autorize de novo e repita estes itens.' : ( 'off' === $halt ? 'Não processado: as ações foram desativadas neste tenant durante a execução.' : 'Não processado.' );
		$rows    = [];
		$op      = (string) ( $params['op'] ?? 'enable' );
		$summary = [ 'total' => count( (array) $params['mailboxes'] ), 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'halted' => $halt, 'archived' => 0, 'expanded' => 0, 'processed' => 0, 'disabled' => 0, 'op' => $op ];
		$current = (int) ( $state['i'] ?? 0 );
		foreach ( (array) $params['mailboxes'] as $idx => $m ) {
			$r = $state['res'][ $m['upn'] ] ?? null;
			if ( null === $r && $idx === $current && is_array( $state['cur'] ?? null ) ) {
				// Interrupted in the middle of this mailbox: keep what already ran.
				$r = $state['cur'];
				foreach ( [ 'archive', 'expand', 'mfa' ] as $k ) {
					if ( 'pending' === $r[ $k ] || ( 'off' === $r[ $k ] && 'archive' !== $k && ( 'expand' === $k ? $params['autoExpand'] : $params['mfa'] ) ) ) {
						$r[ $k ] = 'skipped';
					}
				}
				if ( 'process' === $op && 'off' === $r['mfa'] ) {
					$r['mfa'] = 'skipped';
				}
				$r['m'][] = $why;
			}
			if ( null === $r ) {
				$rows[] = [ 'upn' => $m['upn'], 'name' => $m['name'], 'archive' => 'process' === $op ? 'off' : 'skipped', 'expand' => $params['autoExpand'] ? 'skipped' : 'off', 'mfa' => $params['mfa'] ? 'skipped' : 'off', 'ok' => false, 'skipped' => true, 'message' => $why ];
				$summary['skipped']++;
				continue;
			}
			$failed = in_array( 'error', [ $r['archive'], $r['expand'], $r['mfa'] ], true );
			$part   = ! $failed && in_array( 'skipped', [ $r['archive'], $r['expand'], $r['mfa'] ], true ) && '' !== $halt && $idx === $current;
			$rows[] = [ 'upn' => $m['upn'], 'name' => $m['name'], 'archive' => $r['archive'], 'expand' => $r['expand'], 'mfa' => $r['mfa'], 'ok' => ! $failed && ! $part, 'skipped' => $part, 'message' => implode( ' · ', (array) $r['m'] ) ];
			$summary[ $failed ? 'failed' : ( $part ? 'skipped' : 'ok' ) ]++;
			$summary['archived']  += 'disable' !== $op && in_array( $r['archive'], [ 'ok', 'already' ], true ) ? 1 : 0;
			$summary['disabled']  += 'disable' === $op && 'ok' === $r['archive'] ? 1 : 0;
			$summary['expanded']  += in_array( $r['expand'], [ 'ok', 'already' ], true ) ? 1 : 0;
			$summary['processed'] += 'ok' === $r['mfa'] ? 1 : 0;
		}
		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ), 'action' => 'archive', 'op' => $op, 'autoExpand' => (bool) $params['autoExpand'] ] ];
	}
}
