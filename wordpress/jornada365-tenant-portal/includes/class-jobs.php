<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunked report execution without keeping tenant data on the server.
 *
 * Large tenants cannot be read inside a single PHP request, so a job keeps its progress between
 * HTTP calls. That progress (parameters, partial rows and the final result) is encrypted with the
 * DATA KEY of the user's session — a key that exists only inside the user's encrypted session
 * cookie — so the server alone cannot read it. The row is bound to that session, deleted as soon
 * as the browser collects the result, and purged after 15 minutes in any case. There is no report
 * cache on the server: results live only in the user's browser tab.
 */
final class Jobs {

	/** Seconds of work per run() call. Keep below PHP max_execution_time and proxy timeouts. */
	public const STEP_BUDGET = 20;
	/** A step marked running is only re-claimed after this long (longer than the longest step: budget + bounded HTTP waits). */
	private const LOCK_SECONDS = 90;
	public const TTL         = 15 * MINUTE_IN_SECONDS;
	/** Reports a single session may have in progress at once (keeps abandoned work bounded). */
	public const MAX_ACTIVE  = 8;
	private const MAX_REPORT_RETRIES = 30;

	public static function registry(): array {
		return [
			'overview'     => Reports\Overview::class,
			'identity'     => Reports\Identity::class,
			'mfa'          => Reports\Mfa::class,
			'devices'      => Reports\Devices::class,
			'apps'         => Reports\Apps::class,
			'app_devices'  => Reports\App_Devices::class,
			'ca'           => Reports\Conditional_Access::class,
			'groups'       => Reports\Groups::class,
			'licensing'    => Reports\Licensing::class,
			'guests'       => Reports\Guests::class,
			'domains'      => Reports\Domains::class,
			'authpolicies' => Reports\Auth_Policies::class,
			'privileged'   => Reports\Privileged::class,
			'risk'         => Reports\Risk::class,
			'appsec'       => Reports\App_Security::class,
			'securescore'  => Reports\Secure_Score::class,
			'usage'        => Reports\Usage::class,
			'mailboxes'    => Reports\Mailboxes::class,
			'storage'      => Reports\Storage::class,
		];
	}

	/** Jobs that change something in the tenant: started only by the role-gated /actions endpoints. */
	public static function action_registry(): array {
		return [
			'act_sync'    => Actions\Device_Sync::class,
			'act_archive' => Actions\Mailbox_Archive::class,
		];
	}

	public static function action_class( string $type ): ?string {
		if ( ! in_array( $type, Settings::active_reports(), true ) ) {
			return null;
		}
		$class = self::action_registry()[ $type ] ?? null;
		return $class && class_exists( $class ) ? $class : null;
	}

	public static function report_class( string $type ): ?string {
		// Only allow reports whose module is enabled (defense in depth beyond the UI).
		if ( ! in_array( $type, Settings::active_reports(), true ) ) {
			return null;
		}
		$class = self::registry()[ $type ] ?? null;
		return $class && class_exists( $class ) ? $class : null;
	}

	private static function seal( array $data, string $key ): string {
		return Crypto::seal( Report_Cache::encode( $data ), $key );
	}

	private static function open( ?string $blob, string $key ): ?array {
		$raw = Crypto::open( (string) $blob, $key );
		return null === $raw ? null : Report_Cache::decode( $raw );
	}

	/**
	 * @param array $session Session::current() view (tenant_id, owner, data_key, …)
	 */
	public static function start( array $session, string $type, array $params, bool $action = false, string $request_id = '' ): array {
		$class = $action ? self::action_class( $type ) : self::report_class( $type );
		if ( ! $class ) {
			throw new \InvalidArgumentException( $action ? 'Ação indisponível.' : 'Relatório desconhecido.' );
		}
		self::purge_old();
		global $wpdb;
		$table  = Installer::table( 'jobs' );
		/** @var Reports\Report_Base $report */
		$report = new $class( $session['tenant_id'] );
		$params = $report->normalize_params( $params );

		$deduplicate = ! $action && '' !== $request_id;
		if ( $deduplicate ) {
			if ( ! wp_is_uuid( $request_id, 4 ) ) {
				throw new \InvalidArgumentException( 'Identificador de solicitação inválido.' );
			}
			// A lost start response can be retried with the same UUID. Bind its job ID to
			// the session and normalized query so another user or report cannot claim it.
			$hash   = hash_hmac( 'sha256', wp_json_encode( [ strtolower( $request_id ), $type, $params ] ), $session['data_key'] );
			$job_id = sprintf( '%s-%s-8%s-8%s-%s', substr( $hash, 0, 8 ), substr( $hash, 8, 4 ), substr( $hash, 12, 3 ), substr( $hash, 15, 3 ), substr( $hash, 18, 12 ) );
			$row    = self::get( $job_id, $session );
			if ( $row ) {
				return [ 'job' => self::public_view( $row ) ];
			}
		} else {
			$job_id = wp_generate_uuid4();
		}
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_oid = %s AND status IN ('pending','running')", $session['owner'] ) );
		if ( $active >= self::MAX_ACTIVE ) {
			// The oldest job may be processing a request or waiting for Graph's Retry-After.
			// Keep its progress and let the new report retry when there is capacity.
			throw new Graph_Exception( 'Há várias consultas em andamento. Aguarde a conclusão e tente novamente.', 429, 'job_capacity' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = [
			'job_id'     => $job_id,
			'tenant_id'  => strtolower( $session['tenant_id'] ),
			'user_oid'   => $session['owner'],
			'job_type'   => $type,
			'params'     => null,
			'state'      => self::seal( [ 'params' => $params, 'state' => $report->initial_state( $params ) ], $session['data_key'] ),
			'status'     => 'pending',
			'progress'   => 0,
			'total'      => 0,
			'message'    => 'Iniciando…',
			'cache_key'  => null,
			'created_at' => $now,
			'updated_at' => $now,
		];
		$previous = $deduplicate ? $wpdb->suppress_errors( true ) : null;
		try {
			$created = $wpdb->insert( $table, $row );
		} finally {
			if ( $deduplicate ) {
				$wpdb->suppress_errors( $previous );
			}
		}
		if ( false === $created ) {
			// A simultaneous retry can win the primary-key insert between SELECT and INSERT.
			$row = $deduplicate ? self::get( $job_id, $session ) : null;
			if ( $row ) {
				return [ 'job' => self::public_view( $row ) ];
			}
			throw new \RuntimeException( 'Não foi possível iniciar o relatório. Verifique o banco de dados do WordPress.' );
		}
		Audit::log( 'report_start', $session['tenant_id'], $session['upn'], [ 'report' => $type ] );
		return [ 'job' => self::public_view( $row ) ];
	}

	/** A job is only visible to the session that created it. */
	public static function get( string $job_id, array $session ): ?array {
		global $wpdb;
		$table = Installer::table( 'jobs' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %s AND tenant_id = %s AND user_oid = %s", $job_id, strtolower( $session['tenant_id'] ), $session['owner'] ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function public_view( array $row ): array {
		return [
			'job_id'   => $row['job_id'],
			'type'     => $row['job_type'],
			'status'   => $row['status'],
			'progress' => (int) $row['progress'],
			'total'    => (int) $row['total'],
			'message'  => $row['message'],
		];
	}

	/** List metadata only. The encrypted report can be much larger than the list itself. */
	public static function reports_for_session( array $session ): array {
		global $wpdb;
		$table = Installer::table( 'jobs' );
		$types = array_values( array_intersect( array_keys( self::registry() ), Settings::active_reports() ) );
		if ( ! $types ) {
			return [];
		}
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TTL );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT job_id, job_type, status, progress, total, message, updated_at FROM {$table}
			 WHERE tenant_id = %s AND user_oid = %s AND updated_at >= %s AND job_type IN ({$placeholders})
			 ORDER BY CASE WHEN status IN ('pending','running') THEN 0 ELSE 1 END, updated_at DESC, job_id DESC LIMIT 50",
			array_merge( [ strtolower( $session['tenant_id'] ), $session['owner'], $cutoff ], $types )
		), ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'Não foi possível consultar os relatórios da sessão.' );
		}
		return array_map( static function ( array $row ): array {
			return self::public_view( $row ) + [ 'updatedAt' => str_replace( ' ', 'T', $row['updated_at'] ) . 'Z' ];
		}, $rows );
	}

	/** Remove an owned read job. A running worker can only UPDATE this row; it cannot recreate it. */
	public static function discard_report( string $job_id, array $session ): bool {
		global $wpdb;
		$table = Installer::table( 'jobs' );
		$types = array_keys( self::registry() );
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$count = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE job_id = %s AND tenant_id = %s AND user_oid = %s AND job_type IN ({$placeholders})",
			array_merge( [ $job_id, strtolower( $session['tenant_id'] ), $session['owner'] ], $types )
		) );
		if ( false === $count ) {
			throw new \RuntimeException( 'Não foi possível descartar o relatório.' );
		}
		return 1 === $count;
	}

	/**
	 * Executes one time-boxed step.
	 */
	public static function run( string $job_id, array $session, bool $reports_only = false ): array {
		global $wpdb;
		$table = Installer::table( 'jobs' );
		// The activity UI can resume reads only. Check this inside the lease claim,
		// before an action could run, even if the client submits a different id.
		$read_guard = '';
		if ( $reports_only ) {
			$types = array_values( array_intersect( array_keys( self::registry() ), Settings::active_reports() ) );
			if ( ! $types ) {
				throw new \InvalidArgumentException( 'Relatório indisponível nesta sessão.' );
			}
			$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			$read_guard = " AND job_type IN ({$placeholders}) AND updated_at >= %s";
		}
		// Load the state after acquiring the lease. Loading it before the UPDATE could replay
		// old progress if another request completed a step between the SELECT and the claim.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', updated_at = %s WHERE job_id = %s AND tenant_id = %s AND user_oid = %s AND (status = 'pending' OR (status = 'running' AND job_type NOT IN ('act_sync','act_archive') AND updated_at < %s)){$read_guard}",
				array_merge( [
					gmdate( 'Y-m-d H:i:s' ), $job_id, strtolower( $session['tenant_id'] ), $session['owner'],
					gmdate( 'Y-m-d H:i:s', time() - self::LOCK_SECONDS ),
				], $reports_only ? array_merge( $types, [ gmdate( 'Y-m-d H:i:s', time() - self::TTL ) ] ) : [] )
			)
		);
		if ( false === $claimed ) {
			throw new \RuntimeException( 'Não foi possível processar o relatório. Verifique o banco de dados do WordPress.' );
		}
		$row = self::get( $job_id, $session );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Relatório não encontrado (expirado ou de outra sessão). Gere novamente.' );
		}
		if ( $reports_only && ( ! in_array( $row['job_type'], $types, true ) || strtotime( $row['updated_at'] . ' UTC' ) < time() - self::TTL ) ) {
			throw new \InvalidArgumentException( 'Relatório indisponível nesta sessão.' );
		}
		if ( ! $claimed ) {
			// An abandoned write might already have reached Microsoft Graph or Exchange. Its lease
			// is never re-acquired. Stop polling and let the user check the operation remotely.
			if ( 'running' === $row['status'] && strtotime( $row['updated_at'] . ' UTC' ) < time() - self::LOCK_SECONDS
				&& array_key_exists( $row['job_type'], self::action_registry() ) ) {
				$view            = self::public_view( $row );
				$view['status']  = 'error';
				$view['message'] = 'A ação perdeu a conexão com o portal. Confira o estado no Microsoft 365 antes de executá-la novamente.';
				return $view;
			}
			return self::public_view( $row ) + ( 'running' === $row['status'] ? [ 'retryAfter' => 2 ] : [] );
		}

		$box = self::open( $row['state'], $session['data_key'] );
		if ( null === $box ) {
			$wpdb->delete( $table, [ 'job_id' => $job_id ] );
			throw new \InvalidArgumentException( 'Não foi possível abrir os dados temporários deste relatório. Execute novamente.' );
		}
		$class = self::report_class( $row['job_type'] ) ?? self::action_class( $row['job_type'] );
		if ( ! $class ) {
			$wpdb->delete( $table, [ 'job_id' => $job_id ] );
			throw new \InvalidArgumentException( 'Relatório ou ação indisponível.' );
		}
		/** @var Reports\Report_Base $report */
		$report = new $class( $session['tenant_id'] );
		$report->set_session( $session );
		$params = (array) ( $box['params'] ?? [] );
		$state  = (array) ( $box['state'] ?? $report->initial_state( $params ) );

		// Graph asked to wait (Retry-After): don't call it again before that, whatever the poll rate.
		$wait = (int) ( $state['_retry_at'] ?? 0 ) - time();
		if ( $wait > 0 ) {
			$wpdb->update( $table, [ 'status' => 'pending', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'job_id' => $job_id ] );
			$row['status'] = 'pending';
			return self::public_view( $row ) + [ 'retryAfter' => $wait ];
		}
		unset( $state['_retry_at'], $state['_retry_reason'] );
		$retry = 0;
		$is_action = array_key_exists( $row['job_type'], self::action_registry() );
		$previous_phase = $state['phase'] ?? null;

		$deadline = microtime( true ) + self::STEP_BUDGET;
		$report->set_deadline( $deadline );

		try {
			try {
				$result = $report->step( $state, $params, $deadline );
			} catch ( Graph_Throttled $e ) {
				if ( $is_action ) {
					throw $e; // A mutating action can only reschedule a request it knows did not run.
				}
				$state['_retry_at'] = time() + $e->retry_after;
				$state['_retry_reason'] = $e->reason;
				$result = [ 'state' => $state, 'done' => false, 'progress' => $row['progress'], 'total' => $row['total'], 'message' => $e->getMessage() ];
			}
			$state  = $result['state'];
			$retry  = max( 0, (int) ( $state['_retry_at'] ?? 0 ) - time() );
			$reason = $state['_retry_reason'] ?? 'throttled';
			if ( $retry > 0 && ! $is_action ) {
				$progressed = $previous_phase !== ( $state['phase'] ?? null ) || (int) ( $result['progress'] ?? 0 ) > (int) $row['progress'];
				$attempts   = $progressed ? 1 : 1 + (int) ( $state['_read_retries'] ?? 0 );
				$limit      = 'throttled' === $reason ? self::MAX_REPORT_RETRIES : 3;
				if ( $retry >= self::TTL || $attempts > $limit ) {
					throw new \RuntimeException(
						'budget' === $reason
							? 'A consulta não avançou dentro do tempo de processamento disponível nesta hospedagem.'
							: 'O Microsoft Graph não respondeu após várias tentativas. Gere o relatório novamente mais tarde.'
					);
				}
				$retry = max( $retry, 'budget' === $reason ? 1 : min( 30, 2 ** min( 5, $attempts ) ) );
				$state['_read_retries'] = $attempts;
				$state['_retry_at']     = time() + $retry;
			} else {
				unset( $state['_read_retries'] );
			}
			foreach ( $report->graph_notes() as $note ) {
				$state['_warnings'][] = $note;
			}

			if ( ! empty( $result['done'] ) ) {
				$payload          = $report->finalize( $state, $params );
				$payload['_meta'] = array_merge(
					$payload['_meta'] ?? [],
					[
						'report'       => $row['job_type'],
						'tenant_id'    => strtolower( $session['tenant_id'] ),
						'generated_at' => gmdate( 'c' ),
						'params'       => $params,
						'warnings'     => array_values( array_unique( $state['_warnings'] ?? [] ) ),
						'stored'       => false,
					]
				);
				$wpdb->update(
					$table,
					[
						'status'     => 'done',
						'progress'   => (int) ( $result['total'] ?? $result['progress'] ?? 0 ),
						'total'      => (int) ( $result['total'] ?? 0 ),
						'message'    => 'Concluído',
						'state'      => self::seal( [ 'result' => $payload ], $session['data_key'] ),
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					],
					[ 'job_id' => $job_id ]
				);
				Audit::log( 'report_done', $session['tenant_id'], $session['upn'], [ 'report' => $row['job_type'], 'rows' => $payload['_meta']['rows'] ?? null ] );
			} else {
				$wpdb->update(
					$table,
					[
						'status'     => 'pending',
						'progress'   => (int) ( $result['progress'] ?? 0 ),
						'total'      => (int) ( $result['total'] ?? 0 ),
						// Pages kept, Graph asked to wait: say so instead of a progress line that looks stuck.
						'message'    => $retry > 0 && 'budget' !== $reason
							? ( 'network' === $reason ? 'A conexão falhou. Tentando novamente…' : 'O Microsoft Graph pediu uma pausa. Continuando…' )
							: mb_substr( Privacy::mask_emails( (string) ( $result['message'] ?? '' ) ), 0, 500 ),
						'state'      => self::seal( [ 'params' => $params, 'state' => $state ], $session['data_key'] ),
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					],
					[ 'job_id' => $job_id ]
				);
			}
		} catch ( Graph_Exception $e ) {
			if ( 'j365_token' !== $e->graph_code() ) {
				self::fail( $job_id, $row, $session, $e );
			} else {
				// Per-tenant model: the admin's delegated token expired mid-step. Nothing is lost — the
				// pre-step state stays; the browser renews the token and repeats this step.
				$wpdb->update( $table, [ 'status' => 'pending', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'job_id' => $job_id ] );
				throw $e;
			}
		} catch ( \Throwable $e ) {
			self::fail( $job_id, $row, $session, $e );
		}

		$row = self::get( $job_id, $session );
		if ( ! $row ) {
			return [ 'job_id' => $job_id, 'status' => 'error', 'progress' => 0, 'total' => 0, 'message' => 'Job expirado.' ];
		}
		$view = self::public_view( $row );
		if ( $retry > 0 && 'pending' === $view['status'] ) {
			$view['retryAfter'] = $retry; // the browser waits before polling again
		}
		return $view;
	}

	private static function fail( string $job_id, array $row, array $session, \Throwable $e ): void {
		global $wpdb;
		$wpdb->update(
			Installer::table( 'jobs' ),
			[
				'status'     => 'error',
				'message'    => mb_substr( Privacy::mask_emails( $e->getMessage() ), 0, 500 ),
				'state'      => null,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'job_id' => $job_id ]
		);
		Audit::log( 'report_error', $session['tenant_id'], $session['upn'], [ 'report' => $row['job_type'], 'error' => $e->getMessage() ] );
	}

	/**
	 * Returns the result exactly once and deletes the row immediately (nothing stays on the server).
	 */
	public static function result( string $job_id, array $session, bool $reports_only = false ): ?array {
		$row = self::get( $job_id, $session );
		if ( ! $row || 'done' !== $row['status'] ) {
			return null;
		}
		if ( $reports_only && ( ! isset( self::registry()[ $row['job_type'] ] )
			|| ! in_array( $row['job_type'], Settings::active_reports(), true )
			|| strtotime( $row['updated_at'] . ' UTC' ) < time() - self::TTL ) ) {
			return null;
		}
		$box = self::open( $row['state'], $session['data_key'] );
		global $wpdb;
		$deleted = $wpdb->delete( Installer::table( 'jobs' ), [
			'job_id' => $job_id, 'tenant_id' => strtolower( $session['tenant_id'] ), 'user_oid' => $session['owner'], 'status' => 'done',
		] );
		// Only one tab wins collection, including a race with an explicit discard.
		return 1 === $deleted && is_array( $box['result'] ?? null ) ? $box['result'] : null;
	}

	/** Deletes every job older than the TTL (abandoned tabs, expired sessions). */
	public static function purge_old(): void {
		global $wpdb;
		$table = Installer::table( 'jobs' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::TTL ) ) );
	}
}
