<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exchange Online cmdlets over REST, with the signed-in admin's delegated token (Exchange.Manage).
 *
 * Archive operations (Enable-Mailbox -Archive / -AutoExpandingArchive, Start-ManagedFolderAssistant)
 * are not part of Microsoft Graph nor of the documented Exchange Online Admin API (v2.0 preview,
 * which covers only a few EWS scenarios). They are sent to the same REST backend used by the
 * official ExchangeOnlineManagement v3 PowerShell module (adminapi/beta/…/InvokeCommand). Exchange
 * RBAC of the admin decides what is allowed. The portal also offers the equivalent PowerShell script.
 */
final class Exchange_Client {

	private string $tenant_id;
	private string $token;
	private float $deadline = 0.0;

	public function __construct( string $tenant_id, string $token ) {
		$this->tenant_id = strtolower( $tenant_id );
		$this->token     = $token;
	}

	public function set_deadline( float $deadline ): void {
		$this->deadline = $deadline;
	}

	private function can_wait( int $seconds ): bool {
		return $this->deadline <= 0 || microtime( true ) + $seconds <= $this->deadline + 15;
	}

	/** HTTP timeout for the next call: never lets a job step outlive its lock (deadline + 25 s). */
	private function timeout(): int {
		return $this->deadline > 0 ? (int) max( 5, min( 30, floor( $this->deadline + 25 - microtime( true ) ) ) ) : 60;
	}

	public function endpoint(): string {
		return 'https://outlook.office365.com/adminapi/beta/' . rawurlencode( $this->tenant_id ) . '/InvokeCommand';
	}

	/**
	 * Runs one cmdlet. Never throws for cmdlet errors (they are returned), only for an expired
	 * authorization (Graph_Exception 401) and throttling beyond the step (Graph_Throttled).
	 *
	 * @return array{ok: bool, status: int, value: mixed, error: string, already: bool}
	 */
	public function cmdlet( string $name, array $params, string $anchor_upn ): array {
		$body = [ 'CmdletInput' => [ 'CmdletName' => $name, 'Parameters' => (object) $params ] ];
		$read = str_starts_with( $name, 'Get-' );
		for ( $attempt = 1; ; $attempt++ ) {
			$res = wp_remote_post(
				$this->endpoint(),
				[
					'timeout'     => $this->timeout(),
					'redirection' => 0,
					'headers' => [
						'Authorization'   => 'Bearer ' . $this->token,
						'Content-Type'    => 'application/json',
						'Accept'          => 'application/json',
						'X-AnchorMailbox' => 'UPN:' . $anchor_upn,
						'User-Agent'      => 'Jornada365-TenantPortal/' . J365TP_VERSION,
					],
					'body'    => wp_json_encode( $body ),
				]
			);
			if ( is_wp_error( $res ) ) {
				if ( $read && $attempt <= 2 && $this->can_wait( 12 ) ) {
					sleep( 2 );
					continue;
				}
				return [ 'ok' => false, 'status' => 0, 'value' => null, 'error' => $read ? 'Falha de rede ao chamar o Exchange Online: ' . $res->get_error_message() : 'O Exchange não confirmou a ação. Confira o estado da caixa antes de repeti-la.', 'already' => false ];
			}
			$status = (int) wp_remote_retrieve_response_code( $res );
			$raw    = (string) wp_remote_retrieve_body( $res );
			$json   = json_decode( $raw, true );
			if ( 401 === $status ) {
				throw new Graph_Exception( 'Sua autorização para executar ações expirou. Autorize novamente.', 401, 'action_token_expired' );
			}
			if ( ! $read && $status >= 500 ) {
				return [ 'ok' => false, 'status' => $status, 'value' => null, 'error' => 'O Exchange não confirmou a ação. Confira o estado da caixa antes de repeti-la.', 'already' => false ];
			}
			if ( 429 === $status || ( $read && 503 === $status ) ) {
				$wait = max( 1, min( 60, (int) wp_remote_retrieve_header( $res, 'retry-after' ) ?: 2 ** $attempt ) );
				if ( $attempt <= 4 && $this->can_wait( $wait ) ) {
					sleep( $wait );
					continue;
				}
				throw new Graph_Throttled( 'O Exchange Online pediu uma pausa (limite de requisições). Continuando…', $wait, $status );
			}
			if ( $status >= 200 && $status < 300 ) {
				return [ 'ok' => true, 'status' => $status, 'value' => is_array( $json ) ? ( $json['value'] ?? $json ) : null, 'error' => '', 'already' => false ];
			}
			$msg = self::error_message( is_array( $json ) ? $json : [], $raw, $status );
			return [ 'ok' => false, 'status' => $status, 'value' => null, 'error' => $msg, 'already' => self::already( $msg ) ];
		}
	}

	/**
	 * True only for "the requested state is already in place" (archive already enabled, auto-expanding
	 * already on) — never for "already has a pending/move request" (UserAlreadyBeingMigratedException),
	 * which means nothing was changed.
	 */
	public static function already( string $msg ): bool {
		if ( preg_match( '/pending|move ?request|being (moved|migrated)|BeingMigrated|in progress/i', $msg ) ) {
			return false;
		}
		return (bool) preg_match( '/already (has|have) (an |the )?archive|archive (is |was )?already (enabled|exists|active)|auto-?expanding archiv\w*\s+(is |was )?already (been )?enabled|is already enabled|has already been enabled|já (possui|tem) (um |o )?arquivo|já está (ativ|habilit)/iu', $msg );
	}

	/** Most useful message from an Exchange REST error (cmdlet errors come in details). */
	public static function error_message( array $json, string $raw, int $status ): string {
		$e   = (array) ( $json['error'] ?? [] );
		$msg = (string) ( $e['details'][0]['message'] ?? $e['innererror']['internalexception']['message'] ?? $e['message'] ?? '' );
		$msg = trim( (string) preg_replace( '/^\|?[A-Za-z0-9_.]+(Exception|Error)\|/', '', $msg ) );
		if ( '' === $msg ) {
			$msg = 403 === $status ? 'Acesso negado pelo Exchange Online (RBAC da sua conta).' : 'Exchange Online respondeu HTTP ' . $status . '.';
		}
		if ( 403 === $status && ! str_contains( $msg, 'RBAC' ) ) {
			$msg .= ' (verifique o RBAC do Exchange da sua conta: Recipient Management ou Organization Management)';
		}
		return mb_substr( $msg, 0, 400 );
	}
}
