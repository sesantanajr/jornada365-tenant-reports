<?php
namespace J365TP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Connect your tenant" — creates the portal's app INSIDE the customer's tenant (per-tenant model).
 *
 * An administrator of the customer tenant (Global Administrator, Cloud Application Administrator or
 * Application Administrator) signs in with Microsoft's own
 * public client "Microsoft Graph Command Line Tools" (the client behind Connect-MgGraph) — device
 * code, or browser + PKCE where device code is blocked. With that short-lived delegated token the
 * portal, step by step, in THAT tenant:
 *   resolve  → ids of the delegated Microsoft Graph / Exchange Online scopes
 *   app      → creates/updates the single-tenant app "Tenant Jornada365" (single-page application,
 *              redirect URI of this portal, delegated read-only permissions, no secret, no certificate)
 *   logo     → uploads the Jornada 365 icon
 *   sp       → creates the enterprise application
 *   grant    → grants the delegated permissions for the organization (admin consent)
 *   register → stores tenant ID → client ID in the portal (public identifiers only)
 *   ready    → waits until Microsoft serves the new app for sign-in
 * Nothing is created in the publisher's tenant. The onboarding token is sealed with a key that only
 * the visitor's cookie completes, and is discarded at the end (≤ 1 hour).
 */
final class Onboarding {

	public const COOKIE = 'j365tp_onb';
	/**
	 * Least privilege for the onboarding token: create the app/enterprise app and grant delegated
	 * permissions (no app roles are ever granted in the per-tenant model, so no AppRoleAssignment).
	 */
	public const SCOPES = 'openid profile https://graph.microsoft.com/Application.ReadWrite.All https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All';
	/** Tag of the per-tenant apps (distinct from the classic multi-tenant app's tag). */
	public const TAG    = 'J365TenantApp';
	private const TTL   = HOUR_IN_SECONDS;
	private const READY_MAX = 30;

	public const STEPS = [
		'resolve'  => 'Lendo as permissões do Microsoft Graph no seu tenant',
		'app'      => 'Criando o aplicativo Tenant Jornada365 no seu tenant',
		'logo'     => 'Enviando o logo da Jornada 365',
		'sp'       => 'Criando o aplicativo empresarial',
		'grant'    => 'Aprovando as permissões para a organização',
		'register' => 'Conectando o tenant ao portal',
		'ready'    => 'Aguardando a Microsoft publicar o aplicativo',
	];

	public static function routes(): void {
		$xhr = [ Spa_Auth::class, 'xhr' ];
		foreach ( [ 'start', 'poll', 'code', 'step', 'cancel' ] as $r ) {
			register_rest_route( Rest_Api::NS, '/onboard/' . $r, [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_' . $r ], 'permission_callback' => $xhr ] );
		}
	}

	/* ------------------------------------------------------------ per-visitor state */

	/** [id, secret] from the visitor's cookie (null when there is none). */
	private static function cookie(): ?array {
		$raw = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		if ( ! preg_match( '/^([A-Za-z0-9_-]{20,64})\.([A-Za-z0-9_-]{30,64})$/', $raw, $m ) ) {
			return null;
		}
		return [ $m[1], $m[2] ];
	}

	private static function new_cookie(): array {
		$pair = [ Crypto::random_token( 18 ), Crypto::random_token( 32 ) ];
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $pair[0] . '.' . $pair[1], [ 'expires' => time() + self::TTL, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
		}
		$_COOKIE[ self::COOKIE ] = $pair[0] . '.' . $pair[1];
		return $pair;
	}

	private static function key( array $pair ): string {
		return 'j365tp_onb_' . substr( hash_hmac( 'sha256', $pair[0], Crypto::subkey( 'onboarding-id' ) ), 0, 40 );
	}

	/** The state is unreadable without the visitor's cookie (secret) AND the server key. */
	private static function box_key( array $pair ): string {
		return hash_hmac( 'sha256', $pair[1], Crypto::subkey( 'onboarding' ), true );
	}

	public static function state(): array {
		$pair = self::cookie();
		if ( ! $pair ) {
			return [];
		}
		$raw   = get_transient( self::key( $pair ) );
		$plain = is_string( $raw ) ? Crypto::open( $raw, self::box_key( $pair ) ) : null;
		$data  = null === $plain ? null : json_decode( $plain, true );
		return is_array( $data ) ? $data : [];
	}

	private static function save( array $s ): void {
		$pair = self::cookie() ?? self::new_cookie();
		set_transient( self::key( $pair ), Crypto::seal( (string) wp_json_encode( $s ), self::box_key( $pair ) ), self::TTL );
	}

	public static function wipe(): void {
		$pair = self::cookie();
		if ( $pair ) {
			delete_transient( self::key( $pair ) );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', [ 'expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}

	private static function reply( array $data, int $status = 200 ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	private static function fail( string $message, int $status = 400, array $extra = [] ): WP_REST_Response {
		return self::reply( array_merge( [ 'ok' => false, 'message' => $message ], $extra ), $status );
	}

	/* ------------------------------------------------------------ Microsoft sign-in */

	public static function rest_start( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Rate_Limiter::allow( 'onb-start', 10, 900 ) ) {
			return self::fail( 'Muitas tentativas. Aguarde alguns minutos.', 429, [ 'code' => 'rate' ] );
		}
		$method    = 'browser' === $request->get_param( 'method' ) ? 'browser' : 'device';
		$directory = Settings::company_tenant_id() ?: 'organizations';
		self::wipe();
		self::new_cookie();

		if ( 'browser' === $method ) {
			$verifier = Crypto::random_token( 48 );
			$state    = Crypto::random_token( 24 );
			self::save( [ 'method' => 'browser', 'verifier' => $verifier, 'oauth_state' => $state, 'directory' => $directory, 'created' => time() ] );
			$url = Token_Service::AUTHORITY . '/' . rawurlencode( $directory ) . '/oauth2/v2.0/authorize?' . http_build_query(
				[
					'client_id'             => Bootstrap::CLI_CLIENT,
					'response_type'         => 'code',
					'redirect_uri'          => Bootstrap::BROWSER_REDIRECT,
					'scope'                 => self::SCOPES,
					'state'                 => $state,
					'code_challenge'        => self::b64url( hash( 'sha256', $verifier, true ) ),
					'code_challenge_method' => 'S256',
					'prompt'                => 'select_account',
					'ui_locales'            => I18n::ms_locale(),
				],
				'',
				'&',
				PHP_QUERY_RFC3986
			);
			Audit::log( 'onboard_start', '', '', [ 'method' => 'browser' ] );
			return self::reply( [ 'ok' => true, 'method' => 'browser', 'authorizeUrl' => $url ] );
		}

		$response = wp_remote_post(
			Token_Service::AUTHORITY . '/' . rawurlencode( $directory ) . '/oauth2/v2.0/devicecode',
			[ 'timeout' => 30, 'body' => [ 'client_id' => Bootstrap::CLI_CLIENT, 'scope' => self::SCOPES ] ]
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( 'Falha de rede ao falar com a Microsoft. Tente novamente.', 502 );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [];
		if ( empty( $body['device_code'] ) || empty( $body['user_code'] ) ) {
			return self::fail( Bootstrap::humanize( (string) ( $body['error'] ?? '' ), (string) ( $body['error_description'] ?? '' ) ), 502 );
		}
		self::save(
			[
				'method'      => 'device',
				'device_code' => (string) $body['device_code'],
				'interval'    => max( 3, (int) ( $body['interval'] ?? 5 ) ),
				'expires'     => time() + (int) ( $body['expires_in'] ?? 900 ),
				'created'     => time(),
				'directory'   => $directory,
			]
		);
		Audit::log( 'onboard_start', '', '', [ 'method' => 'device' ] );
		return self::reply(
			[
				'ok'              => true,
				'method'          => 'device',
				'userCode'        => (string) $body['user_code'],
				'verificationUri' => (string) ( $body['verification_uri'] ?? 'https://microsoft.com/devicelogin' ),
				'expiresIn'       => (int) ( $body['expires_in'] ?? 900 ),
				'interval'        => max( 3, (int) ( $body['interval'] ?? 5 ) ),
			]
		);
	}

	/** Device code: one poll of the token endpoint per call (the browser paces the calls). */
	public static function rest_poll(): WP_REST_Response {
		$s = self::state();
		if ( ! empty( $s['token'] ) ) {
			if ( ! Settings::tenant_allowed( (string) ( $s['tid'] ?? '' ) ) ) {
				return self::tenant_denied();
			}
			return self::reply( self::authorized_payload( $s ) );
		}
		if ( ( $s['method'] ?? '' ) !== 'device' || empty( $s['device_code'] ) ) {
			return self::fail( 'Nenhuma conexão em andamento. Comece novamente.', 409, [ 'code' => 'none' ] );
		}
		if ( time() > (int) $s['expires'] ) {
			self::wipe();
			return self::fail( 'O código expirou. Gere um novo código.', 410, [ 'code' => 'expired' ] );
		}
		$body  = self::token_post(
			[
				'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
				'client_id'   => Bootstrap::CLI_CLIENT,
				'device_code' => (string) $s['device_code'],
			],
			(string) ( $s['directory'] ?? 'organizations' )
		);
		$error = (string) ( $body['error'] ?? '' );
		if ( 'authorization_pending' === $error ) {
			return self::reply( [ 'ok' => true, 'status' => 'pending', 'interval' => (int) $s['interval'] ] );
		}
		if ( 'slow_down' === $error ) {
			$s['interval'] = (int) $s['interval'] + 5;
			self::save( $s );
			return self::reply( [ 'ok' => true, 'status' => 'pending', 'interval' => (int) $s['interval'] ] );
		}
		if ( empty( $body['access_token'] ) ) {
			self::wipe();
			Audit::log( 'onboard_error', '', '', [ 'stage' => 'device', 'error' => $error, 'ms' => Token_Service::aadsts( (string) ( $body['error_description'] ?? '' ) ) ] );
			return self::fail( Bootstrap::humanize( $error, (string) ( $body['error_description'] ?? '' ) ), 400, [ 'code' => $error ?: 'token' ] );
		}
		return self::accept_token( $s, $body );
	}

	/** Browser + PKCE: the administrator pastes the http://localhost/?code=… address. */
	public static function rest_code( WP_REST_Request $request ): WP_REST_Response {
		$s = self::state();
		if ( ( $s['method'] ?? '' ) !== 'browser' || empty( $s['verifier'] ) ) {
			return self::fail( 'Nenhuma conexão em andamento. Comece novamente.', 409, [ 'code' => 'none' ] );
		}
		$query = (string) wp_parse_url( trim( (string) $request->get_param( 'url' ) ), PHP_URL_QUERY );
		parse_str( $query, $q );
		if ( ! empty( $q['error'] ) ) {
			return self::fail( Bootstrap::humanize( (string) $q['error'], (string) ( $q['error_description'] ?? '' ) ) );
		}
		if ( empty( $q['code'] ) || empty( $q['state'] ) || ! hash_equals( (string) $s['oauth_state'], (string) $q['state'] ) ) {
			return self::fail( 'Endereço inválido. Copie o endereço completo da barra do navegador (começa com http://localhost/?code=).' );
		}
		$body = self::token_post(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => Bootstrap::CLI_CLIENT,
				'code'          => (string) $q['code'],
				'redirect_uri'  => Bootstrap::BROWSER_REDIRECT,
				'code_verifier' => (string) $s['verifier'],
				'scope'         => self::SCOPES,
			],
			(string) ( $s['directory'] ?? 'organizations' )
		);
		if ( empty( $body['access_token'] ) ) {
			return self::fail( Bootstrap::humanize( (string) ( $body['error'] ?? '' ), (string) ( $body['error_description'] ?? '' ) ) );
		}
		return self::accept_token( $s, $body );
	}

	private static function accept_token( array $s, array $body ): WP_REST_Response {
		$claims = Delegated::claims( (string) ( $body['id_token'] ?? '' ) ); // straight from the token endpoint over TLS
		$tid    = strtolower( (string) ( $claims['tid'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tid ) || '9188040d-6c67-4c5b-b112-36a304b66dad' === $tid ) {
			self::wipe();
			return self::fail( 'Entre com uma conta corporativa (Microsoft Entra ID) do tenant que você quer conectar.' );
		}
		if ( ! Settings::tenant_allowed( $tid ) ) {
			return self::tenant_denied();
		}
		$tenant = Tenant_Repository::get( $tid );
		if ( $tenant && 'revoked' === (string) $tenant['status'] ) {
			self::wipe();
			return self::fail( 'O acesso deste tenant ao portal foi suspenso pelo operador.', 403, [ 'code' => 'tenant_suspended' ] );
		}
		$s = [
			'method'    => $s['method'] ?? 'device',
			'token'     => (string) $body['access_token'],
			'token_exp' => time() + (int) ( $body['expires_in'] ?? 3600 ),
			'tid'       => $tid,
			'oid'       => (string) ( $claims['oid'] ?? '' ),
			'upn'       => (string) ( $claims['preferred_username'] ?? $claims['upn'] ?? '' ),
			'step'      => 0,
			'created'   => $s['created'] ?? time(),
			'update'    => '' !== Tenant_Repository::client_id( $tenant ),
		];
		self::save( $s );
		Audit::log( 'onboard_signed_in', $tid, $s['upn'] );
		return self::reply( self::authorized_payload( $s ) );
	}

	private static function authorized_payload( array $s ): array {
		return [
			'ok'     => true,
			'status' => 'authorized',
			'upn'    => (string) ( $s['upn'] ?? '' ),
			'tenant' => (string) ( $s['tid'] ?? '' ),
			'update' => ! empty( $s['update'] ),
			'steps'  => array_map( fn( $k, $v ) => [ 'id' => $k, 'label' => $v ], array_keys( self::STEPS ), self::STEPS ),
			'next'   => (int) ( $s['step'] ?? 0 ),
		];
	}

	public static function rest_cancel(): WP_REST_Response {
		self::wipe();
		return self::reply( [ 'ok' => true ] );
	}

	private static function tenant_denied(): WP_REST_Response {
		self::wipe();
		return self::fail( 'Este portal está restrito a tenants específicos e o seu não está na lista.', 403, [ 'code' => 'tenant_not_allowed' ] );
	}

	/* ------------------------------------------------------------ provisioning */

	public static function rest_step(): WP_REST_Response {
		$s = self::state();
		if ( empty( $s['token'] ) ) {
			return self::fail( 'Entre na Microsoft antes de continuar.', 409, [ 'code' => 'none' ] );
		}
		if ( ! Settings::tenant_allowed( (string) ( $s['tid'] ?? '' ) ) ) {
			return self::tenant_denied();
		}
		if ( time() > (int) $s['token_exp'] - 60 ) {
			self::wipe();
			return self::fail( 'A autorização da Microsoft expirou. Comece novamente.', 410, [ 'code' => 'expired' ] );
		}
		$ids   = array_keys( self::STEPS );
		$index = (int) ( $s['step'] ?? 0 );
		if ( $index >= count( $ids ) ) {
			return self::reply( [ 'ok' => true, 'done' => true ] );
		}
		$id = $ids[ $index ];
		try {
			$result = self::{'step_' . $id}( $s );
		} catch ( \Throwable $e ) {
			Audit::log( 'onboard_error', (string) $s['tid'], (string) $s['upn'], [ 'step' => $id, 'error' => mb_substr( $e->getMessage(), 0, 300 ) ] );
			self::save( $s );
			return self::fail( $e->getMessage(), 502, [ 'step' => $id, 'index' => $index, 'retryable' => true ] );
		}
		$retry = (int) ( $result['retry'] ?? 0 );
		if ( ! $retry ) {
			$s['step'] = $index + 1;
		}
		$done = $s['step'] >= count( $ids );
		$out  = [
			'ok'         => true,
			'step'       => $id,
			'index'      => $index,
			'total'      => count( $ids ),
			'advanced'   => ! $retry,
			'retryAfter' => $retry,
			'detail'     => (string) ( $result['detail'] ?? '' ),
			'warning'    => (string) ( $result['warning'] ?? '' ),
			'done'       => $done,
		];
		if ( $done ) {
			Audit::log( 'onboard_done', (string) $s['tid'], (string) $s['upn'], [ 'client_id' => (string) ( $s['app']['appId'] ?? '' ), 'update' => ! empty( $s['update'] ) ] );
			$out += [ 'tenantId' => (string) $s['tid'], 'clientId' => (string) $s['app']['appId'], 'loginHint' => (string) $s['upn'] ];
			self::wipe(); // the onboarding token is discarded as soon as the app is ready
		} else {
			self::save( $s );
		}
		return self::reply( $out );
	}

	private static function step_resolve( array &$s ): array {
		$r = self::graph( $s, 'GET', "/v1.0/servicePrincipals(appId='" . Bootstrap::GRAPH_APP_ID . "')?\$select=id,oauth2PermissionScopes" );
		if ( 200 !== $r['status'] ) {
			throw new \RuntimeException( self::graph_error( $r, 'Não foi possível ler o Microsoft Graph no seu tenant' ) );
		}
		$ids = [];
		foreach ( (array) ( $r['body']['oauth2PermissionScopes'] ?? [] ) as $scope ) {
			$ids[ (string) $scope['value'] ] = (string) $scope['id'];
		}
		$want = [];
		foreach ( array_merge( Settings::OIDC_SCOPES, Settings::graph_scopes() ) as $sc ) {
			if ( empty( $ids[ $sc ] ) ) {
				throw new \RuntimeException( sprintf( 'Permissão delegada não encontrada no Microsoft Graph: %s', $sc ) );
			}
			$want[ $sc ] = $ids[ $sc ];
		}
		$s['graph_sp'] = (string) $r['body']['id'];
		$s['scopes']   = $want;

		$s['ex_sp']     = '';
		$s['ex_scopes'] = [];
		$warning        = '';
		$ex_wanted      = Settings::exchange_scopes();
		if ( $ex_wanted ) {
			$rx = self::graph( $s, 'GET', "/v1.0/servicePrincipals(appId='" . Settings::RESOURCE_APPS['exchange']['appId'] . "')?\$select=id,oauth2PermissionScopes" );
			if ( 200 === $rx['status'] ) {
				$s['ex_sp'] = (string) $rx['body']['id'];
				foreach ( (array) ( $rx['body']['oauth2PermissionScopes'] ?? [] ) as $scope ) {
					if ( in_array( (string) $scope['value'], $ex_wanted, true ) ) {
						$s['ex_scopes'][ (string) $scope['value'] ] = (string) $scope['id'];
					}
				}
			}
			if ( ! $s['ex_scopes'] ) {
				$warning = 'Exchange Online não encontrado neste tenant: as ações de caixa de correio ficarão indisponíveis.';
			}
		}
		return [ 'detail' => sprintf( '%d permissões delegadas', count( $want ) + count( $s['ex_scopes'] ) ), 'warning' => $warning ];
	}

	/** App registration created in the customer's tenant (also used by tests). */
	public static function definition( array $scope_ids, array $exchange_ids = [] ): array {
		$portal = trailingslashit( Request::portal_url() );
		$info   = [ 'marketingUrl' => $portal, 'supportUrl' => $portal ];
		foreach ( [ 'privacyStatementUrl' => 'privacy_url', 'termsOfServiceUrl' => 'terms_url' ] as $field => $opt ) {
			$u = (string) Settings::get( $opt, '' );
			if ( preg_match( '#^https://#i', $u ) && strlen( $u ) <= 255 ) {
				$info[ $field ] = $u;
			}
		}
		$scope = static fn( array $ids ) => array_values( array_map( static fn( $id ) => [ 'id' => $id, 'type' => 'Scope' ], array_values( $ids ) ) );
		return [
			'displayName'            => Settings::APP_NAME,
			'signInAudience'         => 'AzureADMyOrg',
			'groupMembershipClaims'  => 'DirectoryRole',
			'tags'                   => [ self::TAG, 'Jornada365' ],
			'notes'                  => 'Aplicativo do portal ' . untrailingslashit( $portal ) . ' neste tenant: login dos administradores com permissões delegadas (somente leitura + ações executadas com a conta de quem clica). Sem segredo e sem certificado. Excluir este aplicativo desconecta o tenant do portal.',
			'spa'                    => [ 'redirectUris' => [ Spa_Auth::redirect_uri() ] ],
			'web'                    => [
				'redirectUris'          => [],
				'homePageUrl'           => $portal,
				'logoutUrl'             => $portal,
				'implicitGrantSettings' => [ 'enableIdTokenIssuance' => false, 'enableAccessTokenIssuance' => false ],
			],
			'info'                   => $info,
			'keyCredentials'         => [],
			'requiredResourceAccess' => array_values(
				array_filter(
					[
						[ 'resourceAppId' => Bootstrap::GRAPH_APP_ID, 'resourceAccess' => $scope( $scope_ids ) ],
						$exchange_ids ? [ 'resourceAppId' => Settings::RESOURCE_APPS['exchange']['appId'], 'resourceAccess' => $scope( $exchange_ids ) ] : null,
					]
				)
			),
		];
	}

	private static function step_app( array &$s ): array {
		$def = self::definition( (array) $s['scopes'], (array) ( $s['ex_scopes'] ?? [] ) );
		// Reconnecting updates the app the portal already uses for this tenant; only when it no longer
		// exists (deleted in Entra ID) is another app looked up or created. So whoever reconnects must be
		// able to change THAT app (Microsoft Graph enforces it) to move the tenant to a different one.
		$app        = null;
		$registered = Tenant_Repository::client_id( Tenant_Repository::get( (string) $s['tid'] ) );
		if ( '' !== $registered ) {
			$r0  = self::graph( $s, 'GET', "/v1.0/applications(appId='" . rawurlencode( $registered ) . "')?\$select=id,appId,displayName" );
			$app = 200 === $r0['status'] && ! empty( $r0['body']['id'] ) ? $r0['body'] : null;
		}
		if ( ! $app ) {
			$r   = self::graph( $s, 'GET', '/v1.0/applications?$filter=' . rawurlencode( "tags/any(t:t eq '" . self::TAG . "')" ) . '&$select=id,appId,displayName' );
			$app = 200 === $r['status'] && ! empty( $r['body']['value'][0]['id'] ) ? $r['body']['value'][0] : null;
		}
		if ( $app ) {
			$rr = self::graph( $s, 'PATCH', '/v1.0/applications/' . rawurlencode( (string) $app['id'] ), $def );
			if ( ! in_array( $rr['status'], [ 200, 204 ], true ) ) {
				throw new \RuntimeException( self::graph_error( $rr, 'Falha ao atualizar o aplicativo' ) );
			}
			$detail = 'Aplicativo atualizado';
		} else {
			$rr = self::graph( $s, 'POST', '/v1.0/applications', $def );
			if ( 201 !== $rr['status'] ) {
				throw new \RuntimeException( self::graph_error( $rr, 'Falha ao criar o aplicativo' ) );
			}
			$app    = [ 'id' => (string) $rr['body']['id'], 'appId' => (string) $rr['body']['appId'] ];
			$detail = 'Aplicativo criado';
		}
		$s['app'] = [ 'id' => (string) $app['id'], 'appId' => strtolower( (string) $app['appId'] ) ];
		return [ 'detail' => $detail . ': ' . $s['app']['appId'] ];
	}

	private static function step_logo( array &$s ): array {
		$file = Brand::icon_file();
		if ( '' === $file && Brand::gd_available() ) {
			Brand::refresh_from_url();
			$file = Brand::icon_file();
		}
		if ( '' === $file || ! is_readable( $file ) || filesize( $file ) > Brand::ICON_MAX ) {
			return [ 'warning' => 'Logo não enviado (ícone indisponível no portal).' ];
		}
		$r = self::graph( $s, 'PUT', '/v1.0/applications/' . rawurlencode( $s['app']['id'] ) . '/logo', (string) file_get_contents( $file ), 'image/png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! in_array( $r['status'], [ 200, 204 ], true ) ) {
			// A brand-new application may still be replicating.
			$s['logo_attempts'] = (int) ( $s['logo_attempts'] ?? 0 ) + 1;
			if ( $s['logo_attempts'] < 5 && in_array( $r['status'], [ 400, 404 ], true ) ) {
				return [ 'retry' => 3000, 'detail' => 'Aguardando a replicação do diretório…' ];
			}
			return [ 'warning' => self::graph_error( $r, 'Logo não enviado' ) ];
		}
		return [ 'detail' => 'Logo aplicado' ];
	}

	private static function step_sp( array &$s ): array {
		$app_id = (string) $s['app']['appId'];
		$r      = self::graph( $s, 'GET', '/v1.0/servicePrincipals?$filter=' . rawurlencode( "appId eq '{$app_id}'" ) . '&$select=id' );
		if ( 200 === $r['status'] && ! empty( $r['body']['value'][0]['id'] ) ) {
			$s['sp'] = (string) $r['body']['value'][0]['id'];
			return [ 'detail' => 'Aplicativo empresarial já existente' ];
		}
		$r = self::graph( $s, 'POST', '/v1.0/servicePrincipals', [ 'appId' => $app_id, 'tags' => [ 'WindowsAzureActiveDirectoryIntegratedApp' ] ] );
		if ( 201 === $r['status'] ) {
			$s['sp'] = (string) $r['body']['id'];
			return [ 'detail' => 'Aplicativo empresarial criado' ];
		}
		$s['sp_attempts'] = (int) ( $s['sp_attempts'] ?? 0 ) + 1;
		if ( $s['sp_attempts'] < 10 && in_array( $r['status'], [ 400, 404 ], true ) ) {
			return [ 'retry' => 3000, 'detail' => 'Aguardando a replicação do diretório…' ];
		}
		throw new \RuntimeException( self::graph_error( $r, 'Falha ao criar o aplicativo empresarial' ) );
	}

	/** Tenant-wide consent (AllPrincipals) for the delegated scopes; least privilege: exactly these. */
	private static function step_grant( array &$s ): array {
		$sp = (string) $s['sp'];
		$r  = self::graph( $s, 'GET', '/v1.0/oauth2PermissionGrants?$filter=' . rawurlencode( "clientId eq '{$sp}' and consentType eq 'AllPrincipals'" ) );
		if ( 200 !== $r['status'] ) {
			throw new \RuntimeException( self::graph_error( $r, 'Falha ao ler as aprovações atuais' ) );
		}
		$grants = [];
		foreach ( (array) ( $r['body']['value'] ?? [] ) as $g ) {
			$grants[ (string) ( $g['resourceId'] ?? '' ) ] = (string) $g['id'];
		}
		$todo = [ (string) $s['graph_sp'] => implode( ' ', array_keys( (array) $s['scopes'] ) ) ];
		if ( ! empty( $s['ex_sp'] ) ) {
			$todo[ (string) $s['ex_sp'] ] = implode( ' ', array_keys( (array) ( $s['ex_scopes'] ?? [] ) ) );
		}
		$n = 0;
		foreach ( $todo as $resource => $scope ) {
			if ( isset( $grants[ $resource ] ) ) {
				$rr = '' === $scope
					? self::graph( $s, 'DELETE', '/v1.0/oauth2PermissionGrants/' . rawurlencode( $grants[ $resource ] ) )
					: self::graph( $s, 'PATCH', '/v1.0/oauth2PermissionGrants/' . rawurlencode( $grants[ $resource ] ), [ 'scope' => $scope ] );
			} elseif ( '' !== $scope ) {
				$rr = self::graph( $s, 'POST', '/v1.0/oauth2PermissionGrants', [ 'clientId' => $sp, 'consentType' => 'AllPrincipals', 'resourceId' => $resource, 'scope' => $scope ] );
			} else {
				continue;
			}
			if ( ! in_array( $rr['status'], [ 200, 201, 204 ], true ) ) {
				$s['grant_attempts'] = (int) ( $s['grant_attempts'] ?? 0 ) + 1;
				if ( $s['grant_attempts'] < 6 && in_array( $rr['status'], [ 400, 404 ], true ) ) {
					return [ 'retry' => 3000, 'detail' => 'Aguardando a replicação do diretório…' ];
				}
				throw new \RuntimeException( self::graph_error( $rr, 'Falha ao aprovar as permissões' ) );
			}
			++$n;
		}
		return [ 'detail' => sprintf( '%d permissões aprovadas para a organização', count( (array) $s['scopes'] ) + count( (array) ( $s['ex_scopes'] ?? [] ) ) ) ];
	}

	private static function step_register( array &$s ): array {
		Tenant_Repository::register_app( (string) $s['tid'], (string) $s['app']['appId'], (string) $s['app']['id'], (string) $s['upn'], 'auto' );
		return [ 'detail' => 'Tenant conectado' ];
	}

	/**
	 * A new app can take a minute to be served by the sign-in service. prompt=none without a cookie
	 * makes Microsoft answer with a redirect to the portal (error=login_required) once the app and its
	 * redirect URI are known — before that it shows an error page (AADSTS700016).
	 */
	private static function step_ready( array &$s ): array {
		if ( self::app_served( (string) $s['tid'], (string) $s['app']['appId'] ) ) {
			return [ 'detail' => 'Aplicativo pronto para o login' ];
		}
		$s['ready_attempts'] = (int) ( $s['ready_attempts'] ?? 0 ) + 1;
		if ( $s['ready_attempts'] < self::READY_MAX ) {
			return [ 'retry' => 4000, 'detail' => 'A Microsoft está publicando o aplicativo (até 2 minutos)…' ];
		}
		return [ 'warning' => 'A Microsoft ainda está publicando o aplicativo. Se o login mostrar um erro, aguarde 1–2 minutos e entre novamente.' ];
	}

	public static function app_served( string $tenant_id, string $client_id ): bool {
		$verifier = Crypto::random_token( 32 );
		$url      = Token_Service::AUTHORITY . '/' . rawurlencode( $tenant_id ) . '/oauth2/v2.0/authorize?' . http_build_query(
			[
				'client_id'             => $client_id,
				'response_type'         => 'code',
				'redirect_uri'          => Spa_Auth::redirect_uri(),
				'response_mode'         => 'fragment',
				'scope'                 => 'openid',
				'prompt'                => 'none',
				'state'                 => 'probe',
				'code_challenge'        => self::b64url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$res = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 0 ] );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code     = (int) wp_remote_retrieve_response_code( $res );
		$location = (string) wp_remote_retrieve_header( $res, 'location' );
		return $code >= 300 && $code < 400 && str_starts_with( $location, Spa_Auth::redirect_uri() );
	}

	/* ------------------------------------------------------------ http */

	private static function token_post( array $fields, string $directory ): array {
		$response = wp_remote_post( Token_Service::AUTHORITY . '/' . rawurlencode( $directory ) . '/oauth2/v2.0/token', [ 'timeout' => 30, 'body' => $fields ] );
		if ( is_wp_error( $response ) ) {
			return [ 'error' => 'network', 'error_description' => $response->get_error_message() ];
		}
		return json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [ 'error' => 'invalid_response' ];
	}

	/** @return array{status:int,body:array} */
	private static function graph( array $s, string $method, string $path, $body = null, string $type = 'application/json' ): array {
		$args = [
			'method'  => $method,
			'timeout' => 45,
			'headers' => [ 'Authorization' => 'Bearer ' . (string) $s['token'], 'Accept' => 'application/json' ],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = $type;
			$args['body']                    = is_array( $body ) ? wp_json_encode( $body ) : $body;
		}
		$response = wp_remote_request( Token_Service::GRAPH . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Falha de rede ao falar com o Microsoft Graph. Tente este passo novamente.' );
		}
		return [
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [],
		];
	}

	/** "Prefix: detail" — the browser translates the prefix and the detail separately. */
	private static function graph_error( array $r, string $prefix ): string {
		$code = (string) ( $r['body']['error']['code'] ?? '' );
		$msg  = (string) ( $r['body']['error']['message'] ?? '' );
		if ( 403 === $r['status'] || 'Authorization_RequestDenied' === $code ) {
			return $prefix . ': permissão insuficiente. Entre com um Global Administrator, Cloud Application Administrator ou Application Administrator do seu tenant.';
		}
		return $prefix . ': HTTP ' . (int) $r['status'] . ( '' !== $msg ? ' — ' . mb_substr( $msg, 0, 300 ) : '' );
	}

	private static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
