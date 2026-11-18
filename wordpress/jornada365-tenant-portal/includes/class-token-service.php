<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.0 client credentials (app-only) tokens per tenant, kept only in memory for the current request.
 */
final class Token_Service {

	public const AUTHORITY = 'https://login.microsoftonline.com';
	public const GRAPH     = 'https://graph.microsoft.com';

	/** App-only tokens live only in this PHP process (never written to the database). */
	private static array $mem = [];

	public static function acquire( string $tenant_id, bool $force = false ): string {
		$tenant_id = strtolower( $tenant_id );
		// Per-tenant model: Graph is called with the signed-in admin's delegated token sent by the
		// browser with this request (Delegated::capture) — there is no app-only credential at all.
		if ( Settings::per_tenant() ) {
			$token = Delegated::graph();
			if ( null === $token || Delegated::remaining( 'graph' ) < 20 ) {
				throw new Graph_Exception( 'A autorização da Microsoft expirou. A página vai renová-la automaticamente.', 401, 'j365_token' );
			}
			return $token;
		}
		$key       = $tenant_id . '|' . Settings::client_id();
		if ( ! $force && isset( self::$mem[ $key ] ) && self::$mem[ $key ]['exp'] > time() + 60 ) {
			return self::$mem[ $key ]['token'];
		}

		$token_url = self::AUTHORITY . '/' . rawurlencode( $tenant_id ) . '/oauth2/v2.0/token';
		$body      = self::token_request(
			$token_url,
			[
				'client_id'  => Settings::client_id(),
				'scope'      => self::GRAPH . '/.default',
				'grant_type' => 'client_credentials',
			]
		);
		if ( empty( $body['access_token'] ) ) {
			$error = (string) ( $body['error'] ?? 'unknown_error' );
			$desc  = (string) ( $body['error_description'] ?? '' );
			throw new Graph_Exception( self::humanize_token_error( $error, $desc ), (int) ( $body['_status'] ?? 0 ), $error );
		}
		self::$mem[ $key ] = [ 'token' => (string) $body['access_token'], 'exp' => time() + (int) ( $body['expires_in'] ?? 3600 ) ];
		return (string) $body['access_token'];
	}

	/**
	 * POSTs to a token endpoint with the app credential (certificate assertion or legacy secret).
	 * If a PS256 assertion is rejected for its signature, retries once with RS256 and remembers it.
	 *
	 * @return array decoded body plus '_status'
	 */
	public static function token_request( string $token_url, array $fields ): array {
		$attempts = Client_Credential::uses_certificate() ? [ null, 'RS256' ] : [ null ];
		$body     = [];
		foreach ( $attempts as $i => $alg ) {
			if ( null !== $alg && 'RS256' === Client_Credential::state()['alg'] ) {
				break; // already using RS256; nothing else to try
			}
			$response = wp_remote_post(
				$token_url,
				[
					'timeout' => 30,
					'headers' => [ 'Accept' => 'application/json' ],
					'body'    => array_merge( $fields, Client_Credential::auth_params( $token_url, $alg ) ),
				]
			);
			if ( is_wp_error( $response ) ) {
				throw new Graph_Exception( 'Falha de rede ao obter token: ' . $response->get_error_message(), 0, 'network' );
			}
			$body            = json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [];
			$body['_status'] = (int) wp_remote_retrieve_response_code( $response );
			$desc            = (string) ( $body['error_description'] ?? '' );
			$sig_error       = str_contains( $desc, 'AADSTS700027' ) && stripos( $desc, 'signature' ) !== false;
			if ( 'RS256' === $alg && 200 === $body['_status'] ) {
				Client_Credential::set_alg( 'RS256' );
			}
			if ( ! $sig_error ) {
				break;
			}
		}
		return $body;
	}

	public static function forget( string $tenant_id ): void {
		$tenant_id = strtolower( $tenant_id );
		foreach ( array_keys( self::$mem ) as $k ) {
			if ( str_starts_with( $k, $tenant_id . '|' ) ) {
				unset( self::$mem[ $k ] );
			}
		}
	}

	/** Permissions without which nobody can sign in (account + directory roles are read with them). */
	public const SIGNIN_PERMISSIONS = [ 'Directory.Read.All' ];

	/**
	 * Whether the app is usable in the tenant: a service principal exists AND the application
	 * permissions needed for sign-in were granted by an administrator. A token alone is not enough —
	 * user consent to "sign in and read your profile" also creates the service principal, but
	 * without any application permission (every Graph call then fails with 403).
	 */
	public static function tenant_has_consent( string $tenant_id ): bool {
		return in_array( self::consent_state( $tenant_id )['state'], [ 'complete', 'partial' ], true );
	}

	/**
	 * Application permissions (roles claim) granted in the tenant, read from a fresh app-only token.
	 *
	 * @return array{state:string, granted:string[], missing:string[], required:string[], code:string, error:string}
	 *  state: complete | partial (sign-in possible, some reports blocked) | blocked (sign-in permissions
	 *  missing) | pending (app present, no application permission approved) | none (app not in tenant) |
	 *  disabled (enterprise app disabled) | error (Microsoft/network/portal problem)
	 */
	public static function consent_state( string $tenant_id, bool $force = true ): array {
		$required = Settings::active_permissions();
		$out      = [ 'state' => 'error', 'granted' => [], 'missing' => $required, 'required' => $required, 'code' => '', 'error' => '' ];
		if ( Settings::per_tenant() ) {
			return self::delegated_state( $required );
		}
		try {
			$token = self::acquire( $tenant_id, $force );
		} catch ( Graph_Exception $e ) {
			$code          = (string) $e->graph_code();
			$out['code']   = self::aadsts( $e->getMessage() ) ?: $code;
			$out['error']  = $e->getMessage();
			$out['state']  = match ( true ) {
				in_array( $out['code'], [ 'AADSTS700016', 'AADSTS650052', 'AADSTS7000229', 'AADSTS500011' ], true ) => 'none',
				in_array( $out['code'], [ 'AADSTS7000112', 'AADSTS7000114' ], true ) => 'disabled',
				default => 'error',
			};
			return $out;
		}
		$granted        = self::roles_claim( $token );
		$have           = array_flip( $granted );
		$out['granted'] = $granted;
		$out['missing'] = array_values( array_filter( $required, static fn( $p ) => ! isset( $have[ $p ] ) && ! isset( $have[ str_replace( '.Read.', '.ReadWrite.', $p ) ] ) ) );
		$signin_missing = array_intersect( self::SIGNIN_PERMISSIONS, $out['missing'] );
		$out['state']   = match ( true ) {
			! $granted        => 'pending',
			(bool) $signin_missing => 'blocked',
			(bool) $out['missing'] => 'partial',
			default           => 'complete',
		};
		return $out;
	}

	/**
	 * Per-tenant model: what the tenant's own app was granted, read from the "scp" claim of the
	 * delegated Graph token of this request (same states as consent_state()).
	 */
	public static function delegated_state( array $required ): array {
		$out = [ 'state' => 'error', 'granted' => [], 'missing' => $required, 'required' => $required, 'code' => '', 'error' => '' ];
		if ( null === Delegated::graph() ) {
			$out['code']  = 'j365_token';
			$out['error'] = 'Sem token da Microsoft nesta requisição.';
			return $out;
		}
		$granted        = Delegated::scopes( 'graph' );
		$out['granted'] = $granted;
		$out['missing'] = array_values( array_filter( $required, static fn( $p ) => ! Delegated::has_scope( 'graph', $p ) ) );
		$out['state']   = match ( true ) {
			(bool) array_intersect( self::SIGNIN_PERMISSIONS, $out['missing'] ) => 'blocked',
			(bool) $out['missing'] => 'partial',
			default                => 'complete',
		};
		return $out;
	}

	/** "roles" claim of an access token we just received from Entra ID over TLS (no signature check needed). */
	public static function roles_claim( string $jwt ): array {
		$parts   = explode( '.', $jwt );
		$payload = isset( $parts[1] ) ? json_decode( Jwt::b64url_decode( $parts[1] ), true ) : null;
		return is_array( $payload ) ? array_values( array_unique( array_map( 'strval', (array) ( $payload['roles'] ?? [] ) ) ) ) : [];
	}

	/** First AADSTS code in a message ("AADSTS700016: …" → "AADSTS700016"). */
	public static function aadsts( string $text ): string {
		return preg_match( '/AADSTS\d{5,7}/', $text, $m ) ? $m[0] : '';
	}

	private static function humanize_token_error( string $error, string $description ): string {
		if ( str_contains( $description, 'AADSTS700016' ) || str_contains( $description, 'AADSTS650052' ) || str_contains( $description, 'AADSTS7000229' ) ) {
			return 'O aplicativo ainda não foi aprovado neste tenant (' . self::aadsts( $description ) . ').';
		}
		if ( str_contains( $description, 'AADSTS7000112' ) || str_contains( $description, 'AADSTS7000114' ) ) {
			return 'O aplicativo está desabilitado neste tenant (' . self::aadsts( $description ) . ').';
		}
		if ( str_contains( $description, 'AADSTS700027' ) || str_contains( $description, 'AADSTS700024' ) ) {
			return 'O certificado do portal não está registrado no aplicativo (ou expirou). Atualize o aplicativo pelo portal (wp-admin → Tenant Jornada365 → Assistente).';
		}
		if ( str_contains( $description, 'AADSTS7000215' ) ) {
			return 'Client secret inválido. Verifique a configuração do plugin (secret expirado ou incorreto).';
		}
		if ( str_contains( $description, 'AADSTS7000222' ) ) {
			return 'Client secret expirado. Gere um novo secret no App Registration e atualize o plugin.';
		}
		if ( str_contains( $description, 'AADSTS90002' ) ) {
			return 'Tenant não encontrado. Verifique o ID do tenant.';
		}
		return sprintf( 'Erro ao obter token (%s): %s', $error, preg_replace( '/\s*Trace ID:.*$/s', '', $description ) );
	}
}
