<?php
namespace J365TP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-portal registration of the multi-tenant "Tenant Jornada365" app.
 *
 * The portal operator (a WordPress administrator) clicks "Entrar com Microsoft 365" on the
 * portal while it is not configured yet. They sign in to Microsoft with a Global Administrator of
 * the publisher tenant (Jornada 365) through the Microsoft Graph Command Line Tools public client
 * — the same client Connect-MgGraph uses — via device code (or browser + PKCE as a fallback).
 * With that short-lived delegated token the server, step by step:
 *   resolve  → Microsoft Graph app roles/scopes for the configured permissions
 *   app      → creates/updates the App Registration (multi-tenant, wids claim, redirect URIs,
 *              read-only permissions, certificate public key) and removes legacy client secrets
 *   logo     → uploads the Jornada 365 square icon
 *   sp       → creates the Enterprise Application in the publisher tenant
 *   grant    → grants the application permissions + openid/profile/email (admin consent)
 *   activate → stores the client ID, activates the certificate, registers the home tenant
 *   verify   → app-only token with the certificate (waits for directory propagation)
 * The delegated token is encrypted at rest for the duration of the run only and wiped at the end.
 * No secret is created, shown or stored.
 */
final class Bootstrap {

	/** Microsoft Graph Command Line Tools (public client used by Microsoft Graph PowerShell). */
	public const TAG          = 'Jornada365TenantPortal';
	public const CLI_CLIENT   = '14d82eec-204b-4c2f-b7e8-296a70dab67e';
	public const GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';
	public const SCOPES       = 'openid profile https://graph.microsoft.com/Application.ReadWrite.All https://graph.microsoft.com/AppRoleAssignment.ReadWrite.All https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All';
	public const DELEGATED    = [ 'openid', 'profile', 'email' ];
	public const LEGACY_TAGS  = [ 'TenantPortal' ];
	public const LEGACY_NAMES = [ 'Jornada 365 Tenant Portal', 'Jornada 365 · Tenant Portal' ];
	public const BROWSER_REDIRECT = 'http://localhost';
	private const TTL         = HOUR_IN_SECONDS;
	private const VERIFY_MAX  = 14;

	public const STEPS = [
		'resolve'  => 'Lendo as permissões do Microsoft Graph',
		'app'      => 'Registrando o aplicativo Tenant Jornada365',
		'logo'     => 'Enviando o logo da Jornada 365',
		'sp'       => 'Criando o aplicativo empresarial no seu tenant',
		'grant'    => 'Concedendo as permissões somente leitura',
		'activate' => 'Ativando o certificado do portal',
		'verify'   => 'Validando a conexão com o Microsoft Graph',
	];

	/* ------------------------------------------------------------ access */

	public static function can(): bool {
		return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public static function permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'j365_boot_auth', 'Entre no WordPress como administrador do portal.', [ 'status' => 401 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'j365_boot_forbidden', 'Somente administradores do portal podem registrar o aplicativo.', [ 'status' => 403 ] );
		}
		return true;
	}

	public static function routes(): void {
		$p = [ self::class, 'permission' ];
		register_rest_route( Rest_Api::NS, '/bootstrap/start', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_start' ], 'permission_callback' => $p ] );
		register_rest_route( Rest_Api::NS, '/bootstrap/poll', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_poll' ], 'permission_callback' => $p ] );
		register_rest_route( Rest_Api::NS, '/bootstrap/code', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_code' ], 'permission_callback' => $p ] );
		register_rest_route( Rest_Api::NS, '/bootstrap/step', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_step' ], 'permission_callback' => $p ] );
		register_rest_route( Rest_Api::NS, '/bootstrap/cancel', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_cancel' ], 'permission_callback' => $p ] );
	}

	/* ------------------------------------------------------------ state */

	private static function key(): string {
		return 'j365tp_boot_' . (int) get_current_user_id();
	}

	public static function state(): array {
		$s = get_transient( self::key() );
		return is_array( $s ) ? $s : [];
	}

	private static function save( array $s ): void {
		set_transient( self::key(), $s, self::TTL );
	}

	public static function wipe(): void {
		delete_transient( self::key() );
	}

	private static function reply( array $data, int $status = 200 ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	private static function fail( string $message, int $status = 400, array $extra = [] ): WP_REST_Response {
		return self::reply( array_merge( [ 'ok' => false, 'message' => $message ], $extra ), $status );
	}

	/* ------------------------------------------------------------ sign-in */

	public static function rest_start( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Client_Credential::available() ) {
			return self::fail( 'O servidor não tem openssl/sodium para gerar o certificado do aplicativo.', 500 );
		}
		$method = 'browser' === $request->get_param( 'method' ) ? 'browser' : 'device';
		self::wipe();

		if ( 'browser' === $method ) {
			$verifier = Crypto::random_token( 48 );
			$state    = Crypto::random_token( 24 );
			self::save( [ 'method' => 'browser', 'verifier' => Crypto::encrypt( $verifier ), 'oauth_state' => $state, 'step' => 0, 'created' => time() ] );
			$url = Token_Service::AUTHORITY . '/organizations/oauth2/v2.0/authorize?' . http_build_query(
				[
					'client_id'             => self::CLI_CLIENT,
					'response_type'         => 'code',
					'redirect_uri'          => self::BROWSER_REDIRECT,
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
			Audit::log( 'bootstrap_start', '', self::wp_user(), [ 'method' => 'browser' ] );
			return self::reply( [ 'ok' => true, 'method' => 'browser', 'authorizeUrl' => $url ] );
		}

		$response = wp_remote_post(
			Token_Service::AUTHORITY . '/organizations/oauth2/v2.0/devicecode',
			[ 'timeout' => 30, 'body' => [ 'client_id' => self::CLI_CLIENT, 'scope' => self::SCOPES ] ]
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( 'Falha de rede ao falar com a Microsoft: ' . $response->get_error_message(), 502 );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [];
		if ( empty( $body['device_code'] ) || empty( $body['user_code'] ) ) {
			return self::fail( self::humanize( (string) ( $body['error'] ?? '' ), (string) ( $body['error_description'] ?? '' ) ), 502 );
		}
		$expires = time() + (int) ( $body['expires_in'] ?? 900 );
		self::save(
			[
				'method'      => 'device',
				'device_code' => Crypto::encrypt( (string) $body['device_code'] ),
				'interval'    => max( 3, (int) ( $body['interval'] ?? 5 ) ),
				'expires'     => $expires,
				'step'        => 0,
				'created'     => time(),
			]
		);
		Audit::log( 'bootstrap_start', '', self::wp_user(), [ 'method' => 'device' ] );
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
		if ( ( $s['method'] ?? '' ) !== 'device' || empty( $s['device_code'] ) ) {
			return ! empty( $s['token'] ) ? self::reply( self::authorized_payload( $s ) ) : self::fail( 'Nenhuma entrada em andamento. Comece novamente.', 409 );
		}
		if ( time() > (int) $s['expires'] ) {
			self::wipe();
			return self::fail( 'O código expirou. Gere um novo código.', 410, [ 'code' => 'expired' ] );
		}
		$body = self::token_post(
			[
				'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
				'client_id'   => self::CLI_CLIENT,
				'device_code' => Crypto::decrypt( (string) $s['device_code'] ),
			]
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
			Audit::log( 'bootstrap_error', '', self::wp_user(), [ 'stage' => 'device', 'error' => $error ] );
			return self::fail( self::humanize( $error, (string) ( $body['error_description'] ?? '' ) ), 400, [ 'code' => $error ?: 'token' ] );
		}
		return self::accept_token( $s, $body );
	}

	/** Browser + PKCE: the operator pastes the http://localhost/?code=… address. */
	public static function rest_code( WP_REST_Request $request ): WP_REST_Response {
		$s = self::state();
		if ( ( $s['method'] ?? '' ) !== 'browser' || empty( $s['verifier'] ) ) {
			return self::fail( 'Nenhuma entrada em andamento. Comece novamente.', 409 );
		}
		$query = (string) wp_parse_url( trim( (string) $request->get_param( 'url' ) ), PHP_URL_QUERY );
		parse_str( $query, $q );
		if ( ! empty( $q['error'] ) ) {
			return self::fail( self::humanize( (string) $q['error'], (string) ( $q['error_description'] ?? '' ) ) );
		}
		if ( empty( $q['code'] ) || empty( $q['state'] ) || ! hash_equals( (string) $s['oauth_state'], (string) $q['state'] ) ) {
			return self::fail( 'Endereço inválido. Copie o endereço completo da barra do navegador (começa com http://localhost/?code=).' );
		}
		$body = self::token_post(
			[
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLI_CLIENT,
				'code'          => (string) $q['code'],
				'redirect_uri'  => self::BROWSER_REDIRECT,
				'code_verifier' => Crypto::decrypt( (string) $s['verifier'] ),
				'scope'         => self::SCOPES,
			]
		);
		if ( empty( $body['access_token'] ) ) {
			return self::fail( self::humanize( (string) ( $body['error'] ?? '' ), (string) ( $body['error_description'] ?? '' ) ) );
		}
		return self::accept_token( $s, $body );
	}

	private static function accept_token( array $s, array $body ): WP_REST_Response {
		$claims = self::jwt_payload( (string) ( $body['id_token'] ?? '' ) );
		$tid    = strtolower( (string) ( $claims['tid'] ?? '' ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tid ) ) {
			self::wipe();
			return self::fail( 'A Microsoft não retornou o tenant da conta. Tente novamente.' );
		}
		$home = strtolower( (string) Settings::get( 'home_tenant_id', '' ) );
		if ( Settings::is_configured() && '' !== $home && $home !== $tid ) {
			self::wipe();
			return self::fail( sprintf( 'O aplicativo já está registrado no tenant %s. Entre com um administrador desse tenant.', $home ), 409 );
		}
		$s = [
			'method'    => $s['method'],
			'token'     => Crypto::encrypt( (string) $body['access_token'] ),
			'token_exp' => time() + (int) ( $body['expires_in'] ?? 3600 ),
			'tid'       => $tid,
			'upn'       => (string) ( $claims['preferred_username'] ?? $claims['upn'] ?? '' ),
			'name'      => (string) ( $claims['name'] ?? '' ),
			'step'      => 0,
			'created'   => $s['created'] ?? time(),
		];
		self::save( $s );
		Audit::log( 'bootstrap_signed_in', $tid, $s['upn'] );
		return self::reply( self::authorized_payload( $s ) );
	}

	private static function authorized_payload( array $s ): array {
		return [
			'ok'     => true,
			'status' => 'authorized',
			'upn'    => $s['upn'] ?? '',
			'tenant' => $s['tid'] ?? '',
			'steps'  => array_map( fn( $k, $v ) => [ 'id' => $k, 'label' => $v ], array_keys( self::STEPS ), self::STEPS ),
			'next'   => (int) ( $s['step'] ?? 0 ),
		];
	}

	public static function rest_cancel(): WP_REST_Response {
		self::wipe();
		return self::reply( [ 'ok' => true ] );
	}

	/* ------------------------------------------------------------ provisioning */

	public static function rest_step(): WP_REST_Response {
		$s = self::state();
		if ( empty( $s['token'] ) ) {
			return self::fail( 'Entre na Microsoft antes de continuar.', 409 );
		}
		if ( time() > (int) $s['token_exp'] - 60 ) {
			self::wipe();
			return self::fail( 'A autorização da Microsoft expirou. Comece novamente.', 410 );
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
			Audit::log( 'bootstrap_error', (string) $s['tid'], (string) $s['upn'], [ 'step' => $id, 'error' => $e->getMessage() ] );
			self::save( $s );
			return self::fail( $e->getMessage(), 502, [ 'step' => $id, 'index' => $index, 'retryable' => true ] );
		}

		$retry = (int) ( $result['retry'] ?? 0 );
		if ( ! $retry ) {
			$s['step'] = $index + 1;
		}
		$done = $s['step'] >= count( $ids );
		if ( $done ) {
			Audit::log( 'bootstrap_done', (string) $s['tid'], (string) $s['upn'], [ 'client_id' => Settings::client_id(), 'verified' => ! empty( $result['verified'] ) ] );
			self::wipe(); // the delegated token is discarded as soon as the app is ready
		} else {
			self::save( $s );
		}
		return self::reply(
			[
				'ok'         => true,
				'step'       => $id,
				'index'      => $index,
				'total'      => count( $ids ),
				'advanced'   => ! $retry,
				'retryAfter' => $retry,
				'detail'     => (string) ( $result['detail'] ?? '' ),
				'warning'    => (string) ( $result['warning'] ?? '' ),
				'done'       => $done,
				'loginUrl'   => $done ? Request::login_url() : '',
			]
		);
	}

	private static function step_resolve( array &$s ): array {
		$r = self::graph( $s, 'GET', "/v1.0/servicePrincipals(appId='" . self::GRAPH_APP_ID . "')?\$select=id,appRoles,oauth2PermissionScopes" );
		if ( 200 !== $r['status'] ) {
			throw new \RuntimeException( self::graph_error( $r, 'Não foi possível ler o Microsoft Graph no seu tenant' ) );
		}
		$roles  = [];
		$scopes = [];
		foreach ( (array) ( $r['body']['appRoles'] ?? [] ) as $role ) {
			if ( in_array( 'Application', (array) ( $role['allowedMemberTypes'] ?? [] ), true ) ) {
				$roles[ (string) $role['value'] ] = (string) $role['id'];
			}
		}
		foreach ( (array) ( $r['body']['oauth2PermissionScopes'] ?? [] ) as $scope ) {
			$scopes[ (string) $scope['value'] ] = (string) $scope['id'];
		}
		$want_roles = [];
		foreach ( Settings::active_permissions() as $perm ) {
			if ( empty( $roles[ $perm ] ) ) {
				throw new \RuntimeException( sprintf( 'Permissão não encontrada no Microsoft Graph: %s', $perm ) );
			}
			$want_roles[ $perm ] = $roles[ $perm ];
		}
		$want_scopes = [];
		$actions     = Settings::action_scopes();
		foreach ( array_merge( self::DELEGATED, (array) ( $actions['graph'] ?? [] ) ) as $sc ) {
			if ( ! empty( $scopes[ $sc ] ) ) {
				$want_scopes[ $sc ] = $scopes[ $sc ];
			}
		}
		$s['graph_sp'] = (string) $r['body']['id'];
		$s['roles']    = $want_roles;
		$s['scopes']   = $want_scopes;

		// Delegated Exchange Online scope for the actions module (runs as the signed-in admin).
		$s['ex_sp']     = '';
		$s['ex_scopes'] = [];
		$warning        = '';
		if ( ! empty( $actions['exchange'] ) ) {
			$rx = self::graph( $s, 'GET', "/v1.0/servicePrincipals(appId='" . Settings::RESOURCE_APPS['exchange']['appId'] . "')?\$select=id,oauth2PermissionScopes" );
			if ( 200 === $rx['status'] ) {
				$s['ex_sp'] = (string) $rx['body']['id'];
				foreach ( (array) ( $rx['body']['oauth2PermissionScopes'] ?? [] ) as $scope ) {
					if ( in_array( (string) $scope['value'], (array) $actions['exchange'], true ) ) {
						$s['ex_scopes'][ (string) $scope['value'] ] = (string) $scope['id'];
					}
				}
			}
			if ( ! $s['ex_scopes'] ) {
				$warning = 'Exchange Online não encontrado neste tenant: as ações de caixa de correio ficarão indisponíveis.';
			}
		}
		return [ 'detail' => sprintf( '%d permissões de aplicativo + %d escopos delegados', count( $want_roles ), count( $want_scopes ) + count( $s['ex_scopes'] ) ), 'warning' => $warning ];
	}

	/** Definition of the App Registration (also used by tests). */
	public static function definition( array $roles, array $scopes, array $cert, array $exchange_scopes = [] ): array {
		$portal = trailingslashit( Request::portal_url() );
		$access = [];
		foreach ( $roles as $id ) {
			$access[] = [ 'id' => $id, 'type' => 'Role' ];
		}
		foreach ( $scopes as $id ) {
			$access[] = [ 'id' => $id, 'type' => 'Scope' ];
		}
		$info = [ 'marketingUrl' => $portal, 'supportUrl' => $portal ];
		foreach ( [ 'privacyStatementUrl' => 'privacy_url', 'termsOfServiceUrl' => 'terms_url' ] as $field => $opt ) {
			$u = (string) Settings::get( $opt, '' );
			if ( preg_match( '#^https://#i', $u ) && strlen( $u ) <= 255 ) {
				$info[ $field ] = $u;
			}
		}
		return [
			'displayName'            => Settings::APP_NAME,
			'signInAudience'         => 'AzureADMultipleOrgs',
			'groupMembershipClaims'  => 'DirectoryRole',
			'tags'                   => [ self::TAG, 'Jornada365' ],
			'notes'                  => 'Portal multi-tenant de relatórios Microsoft 365 (' . untrailingslashit( $portal ) . '). Gerenciado pelo próprio portal.',
			'web'                    => [
				'redirectUris'          => [ Request::login_redirect_uri(), Request::consent_redirect_uri() ],
				'logoutUrl'             => $portal,
				'homePageUrl'           => $portal,
				'implicitGrantSettings' => [ 'enableIdTokenIssuance' => false, 'enableAccessTokenIssuance' => false ],
			],
			'info'                   => $info,
			'requiredResourceAccess' => array_values(
				array_filter(
					[
						[ 'resourceAppId' => self::GRAPH_APP_ID, 'resourceAccess' => $access ],
						$exchange_scopes ? [ 'resourceAppId' => Settings::RESOURCE_APPS['exchange']['appId'], 'resourceAccess' => array_values( array_map( static fn( $id ) => [ 'id' => $id, 'type' => 'Scope' ], $exchange_scopes ) ) ] : null,
					]
				)
			),
			'keyCredentials'         => [
				[
					'type'        => 'AsymmetricX509Cert',
					'usage'       => 'Verify',
					'key'         => Client_Credential::der_base64( $cert ),
					'displayName' => 'CN=' . Settings::APP_NAME . ' (portal)',
				],
			],
		];
	}

	private static function step_app( array &$s ): array {
		$cert = Client_Credential::ensure();
		if ( ! $cert ) {
			throw new \RuntimeException( 'Não foi possível gerar o certificado do portal.' );
		}
		$app = self::find_app( $s );
		$def = self::definition( (array) $s['roles'], (array) $s['scopes'], $cert, (array) ( $s['ex_scopes'] ?? [] ) );

		if ( $app ) {
			$r = self::graph( $s, 'PATCH', '/v1.0/applications/' . rawurlencode( $app['id'] ), $def );
			if ( ! in_array( $r['status'], [ 200, 204 ], true ) ) {
				throw new \RuntimeException( self::graph_error( $r, 'Falha ao atualizar o aplicativo' ) );
			}
			$detail = 'Aplicativo atualizado';
		} else {
			$r = self::graph( $s, 'POST', '/v1.0/applications', $def );
			if ( 201 !== $r['status'] ) {
				throw new \RuntimeException( self::graph_error( $r, 'Falha ao criar o aplicativo' ) );
			}
			$app    = [ 'id' => (string) $r['body']['id'], 'appId' => (string) $r['body']['appId'], 'passwordCredentials' => [] ];
			$detail = 'Aplicativo criado';
		}
		$removed = 0;
		foreach ( (array) ( $app['passwordCredentials'] ?? [] ) as $pc ) {
			if ( str_starts_with( (string) ( $pc['displayName'] ?? '' ), 'wordpress-' ) ) {
				$rr = self::graph( $s, 'POST', '/v1.0/applications/' . rawurlencode( $app['id'] ) . '/removePassword', [ 'keyId' => $pc['keyId'] ] );
				$removed += in_array( $rr['status'], [ 200, 204 ], true ) ? 1 : 0;
			}
		}
		$s['app']   = [ 'id' => $app['id'], 'appId' => $app['appId'] ];
		$s['thumb'] = (string) $cert['thumb_hex'];
		return [ 'detail' => $detail . ' · ' . $app['appId'] . ( $removed ? sprintf( ' · %d client secret(s) antigo(s) removido(s)', $removed ) : '' ) ];
	}

	private static function find_app( array $s ): ?array {
		$select = '$select=id,appId,displayName,passwordCredentials,tags';
		$cid    = Settings::client_id();
		if ( '' !== $cid ) {
			$r = self::graph( $s, 'GET', "/v1.0/applications(appId='" . rawurlencode( $cid ) . "')?" . $select );
			if ( 200 === $r['status'] && ! empty( $r['body']['id'] ) ) {
				return $r['body'];
			}
		}
		foreach ( array_merge( [ self::TAG ], self::LEGACY_TAGS ) as $tag ) {
			$r = self::graph( $s, 'GET', '/v1.0/applications?$filter=' . rawurlencode( "tags/any(t:t eq '{$tag}')" ) . '&' . $select );
			if ( 200 === $r['status'] && ! empty( $r['body']['value'][0]['id'] ) ) {
				return $r['body']['value'][0];
			}
		}
		foreach ( array_merge( [ Settings::APP_NAME ], self::LEGACY_NAMES ) as $name ) {
			$r = self::graph( $s, 'GET', '/v1.0/applications?$filter=' . rawurlencode( "displayName eq '" . str_replace( "'", "''", $name ) . "'" ) . '&' . $select );
			if ( 200 === $r['status'] && ! empty( $r['body']['value'][0]['id'] ) ) {
				return $r['body']['value'][0];
			}
		}
		return null;
	}

	private static function step_logo( array &$s ): array {
		$file = Brand::icon_file();
		if ( '' === $file && Brand::gd_available() ) {
			Brand::refresh_from_url();
			$file = Brand::icon_file();
		}
		if ( '' === $file || ! is_readable( $file ) || filesize( $file ) > Brand::ICON_MAX ) {
			return [ 'warning' => 'Logo não enviado (ícone indisponível). Envie em wp-admin → Tenant Jornada365 → Assistente e registre novamente.' ];
		}
		$r = self::graph( $s, 'PUT', '/v1.0/applications/' . rawurlencode( $s['app']['id'] ) . '/logo', (string) file_get_contents( $file ), 'image/png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! in_array( $r['status'], [ 200, 204 ], true ) ) {
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
		// A brand-new application can take a few seconds to replicate.
		$s['sp_attempts'] = (int) ( $s['sp_attempts'] ?? 0 ) + 1;
		if ( $s['sp_attempts'] < 10 && in_array( $r['status'], [ 400, 404 ], true ) ) {
			return [ 'retry' => 3000, 'detail' => 'Aguardando a replicação do diretório…' ];
		}
		throw new \RuntimeException( self::graph_error( $r, 'Falha ao criar o aplicativo empresarial' ) );
	}

	private static function step_grant( array &$s ): array {
		$sp       = (string) $s['sp'];
		$graph_sp = (string) $s['graph_sp'];
		$want     = array_values( (array) $s['roles'] );

		$r = self::graph( $s, 'GET', '/v1.0/servicePrincipals/' . rawurlencode( $sp ) . '/appRoleAssignments?$select=id,appRoleId,resourceId' );
		if ( 200 !== $r['status'] ) {
			throw new \RuntimeException( self::graph_error( $r, 'Falha ao ler as permissões atuais' ) );
		}
		$have = [];
		foreach ( (array) ( $r['body']['value'] ?? [] ) as $a ) {
			if ( ( $a['resourceId'] ?? '' ) === $graph_sp ) {
				$have[ (string) $a['appRoleId'] ] = (string) $a['id'];
			}
		}
		$added = 0;
		foreach ( $want as $role_id ) {
			if ( isset( $have[ $role_id ] ) ) {
				continue;
			}
			$rr = self::graph( $s, 'POST', '/v1.0/servicePrincipals/' . rawurlencode( $graph_sp ) . '/appRoleAssignedTo', [ 'principalId' => $sp, 'resourceId' => $graph_sp, 'appRoleId' => $role_id ] );
			if ( 201 !== $rr['status'] && ! str_contains( (string) ( $rr['body']['error']['message'] ?? '' ), 'already exists' ) ) {
				if ( in_array( $rr['status'], [ 400, 404 ], true ) && (int) ( $s['grant_attempts'] ?? 0 ) < 6 ) {
					$s['grant_attempts'] = (int) ( $s['grant_attempts'] ?? 0 ) + 1;
					return [ 'retry' => 3000, 'detail' => 'Aguardando a replicação do diretório…' ];
				}
				throw new \RuntimeException( self::graph_error( $rr, 'Falha ao conceder permissão' ) );
			}
			++$added;
		}
		// Least privilege: drop Graph permissions that are no longer configured (disabled modules).
		$removed = 0;
		foreach ( $have as $role_id => $assignment_id ) {
			if ( ! in_array( $role_id, $want, true ) ) {
				$rr       = self::graph( $s, 'DELETE', '/v1.0/servicePrincipals/' . rawurlencode( $sp ) . '/appRoleAssignments/' . rawurlencode( $assignment_id ) );
				$removed += 204 === $rr['status'] ? 1 : 0;
			}
		}

		$scope = implode( ' ', array_keys( (array) $s['scopes'] ) );
		$r     = self::graph( $s, 'GET', '/v1.0/oauth2PermissionGrants?$filter=' . rawurlencode( "clientId eq '{$sp}' and consentType eq 'AllPrincipals'" ) );
		$grant = null;
		foreach ( (array) ( $r['body']['value'] ?? [] ) as $g ) {
			if ( ( $g['resourceId'] ?? '' ) === $graph_sp ) {
				$grant = $g;
			}
		}
		if ( $grant ) {
			self::graph( $s, 'PATCH', '/v1.0/oauth2PermissionGrants/' . rawurlencode( (string) $grant['id'] ), [ 'scope' => $scope ] );
		} elseif ( '' !== $scope ) {
			$rr = self::graph( $s, 'POST', '/v1.0/oauth2PermissionGrants', [ 'clientId' => $sp, 'consentType' => 'AllPrincipals', 'resourceId' => $graph_sp, 'scope' => $scope ] );
			if ( 201 !== $rr['status'] ) {
				throw new \RuntimeException( self::graph_error( $rr, 'Falha ao conceder os escopos de login' ) );
			}
		}
		// Exchange Online delegated scope (actions module) — granted for the operator's own tenant too.
		$ex_sp = (string) ( $s['ex_sp'] ?? '' );
		if ( '' !== $ex_sp ) {
			$ex_scope = implode( ' ', array_keys( (array) ( $s['ex_scopes'] ?? [] ) ) );
			$ex_grant = null;
			foreach ( (array) ( $r['body']['value'] ?? [] ) as $g ) {
				if ( ( $g['resourceId'] ?? '' ) === $ex_sp ) {
					$ex_grant = $g;
				}
			}
			if ( $ex_grant && '' === $ex_scope ) {
				self::graph( $s, 'DELETE', '/v1.0/oauth2PermissionGrants/' . rawurlencode( (string) $ex_grant['id'] ) );
			} elseif ( $ex_grant ) {
				self::graph( $s, 'PATCH', '/v1.0/oauth2PermissionGrants/' . rawurlencode( (string) $ex_grant['id'] ), [ 'scope' => $ex_scope ] );
			} elseif ( '' !== $ex_scope ) {
				self::graph( $s, 'POST', '/v1.0/oauth2PermissionGrants', [ 'clientId' => $sp, 'consentType' => 'AllPrincipals', 'resourceId' => $ex_sp, 'scope' => $ex_scope ] );
			}
		}
		return [ 'detail' => sprintf( '%d permissões concedidas, %d já existentes, %d removidas', $added, count( $want ) - $added, $removed ) ];
	}

	private static function step_activate( array &$s ): array {
		$previous = Settings::client_id();
		Settings::update(
			[
				'client_id'         => (string) $s['app']['appId'],
				'home_tenant_id'    => (string) $s['tid'],
				'client_secret_enc' => '',
				'app_signature'     => Settings::app_signature(),
			]
		);
		if ( ! Client_Credential::promote( (string) $s['thumb'] ) ) {
			throw new \RuntimeException( 'O certificado registrado não corresponde ao certificado do portal. Comece novamente.' );
		}
		if ( $previous !== (string) $s['app']['appId'] ) {
			Token_Service::forget( (string) $s['tid'] );
		}
		Consent::register_tenant( (string) $s['tid'], (string) $s['upn'], 'bootstrap' );
		return [ 'detail' => 'Client ID ' . $s['app']['appId'] ];
	}

	private static function step_verify( array &$s ): array {
		try {
			Token_Service::acquire( (string) $s['tid'], true );
			Client_Credential::record_test( true, 'Token de aplicativo obtido com o certificado.' );
			return [ 'detail' => 'Conexão validada', 'verified' => true ];
		} catch ( \Throwable $e ) {
			$s['verify_attempts'] = (int) ( $s['verify_attempts'] ?? 0 ) + 1;
			if ( $s['verify_attempts'] < self::VERIFY_MAX ) {
				return [ 'retry' => 5000, 'detail' => 'A Microsoft está propagando o certificado (até 1–2 minutos)…' ];
			}
			Client_Credential::record_test( false, $e->getMessage() );
			return [ 'warning' => 'O aplicativo foi registrado, mas a Microsoft ainda está propagando o certificado. Aguarde 1–2 minutos e entre novamente.' ];
		}
	}

	/* ------------------------------------------------------------ http */

	private static function token_post( array $fields ): array {
		$response = wp_remote_post( Token_Service::AUTHORITY . '/organizations/oauth2/v2.0/token', [ 'timeout' => 30, 'body' => $fields ] );
		if ( is_wp_error( $response ) ) {
			return [ 'error' => 'network', 'error_description' => $response->get_error_message() ];
		}
		return json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [ 'error' => 'invalid_response' ];
	}

	/**
	 * Delegated Microsoft Graph call with the operator's token.
	 *
	 * @param array|string|null $body JSON-able array, raw bytes (with $type) or null.
	 * @return array{status:int,body:array}
	 */
	private static function graph( array $s, string $method, string $path, $body = null, string $type = 'application/json' ): array {
		$args = [
			'method'  => $method,
			'timeout' => 45,
			'headers' => [ 'Authorization' => 'Bearer ' . Crypto::decrypt( (string) $s['token'] ), 'Accept' => 'application/json' ],
		];
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = $type;
			$args['body']                    = is_array( $body ) ? wp_json_encode( $body ) : $body;
		}
		$response = wp_remote_request( 'https://graph.microsoft.com' . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Falha de rede ao falar com o Microsoft Graph: ' . $response->get_error_message() );
		}
		return [
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => json_decode( (string) wp_remote_retrieve_body( $response ), true ) ?: [],
		];
	}

	private static function graph_error( array $r, string $prefix ): string {
		$code = (string) ( $r['body']['error']['code'] ?? '' );
		$msg  = (string) ( $r['body']['error']['message'] ?? '' );
		if ( 403 === $r['status'] || 'Authorization_RequestDenied' === $code ) {
			return $prefix . ': permissão insuficiente. Entre com um Global Administrator (ou Privileged Role Administrator + Cloud Application Administrator) do tenant da Jornada 365.';
		}
		return $prefix . sprintf( ' (HTTP %d%s)', $r['status'], '' !== $msg ? ': ' . $msg : '' );
	}

	public static function humanize( string $error, string $desc ): string {
		$map = [
			'authorization_declined' => 'A entrada foi recusada na tela da Microsoft.',
			'expired_token'          => 'O código expirou. Gere um novo código.',
			'bad_verification_code'  => 'Código inválido. Gere um novo código.',
			'access_denied'          => 'A entrada foi cancelada ou não autorizada.',
			'network'                => 'Falha de rede ao falar com a Microsoft: ' . $desc,
		];
		if ( isset( $map[ $error ] ) ) {
			return $map[ $error ];
		}
		if ( str_contains( $desc, 'AADSTS53003' ) || str_contains( $desc, 'AADSTS530033' ) || str_contains( $desc, 'AADSTS50199' ) ) {
			return 'Uma política de Acesso Condicional bloqueou esta forma de entrada (fluxo de código do dispositivo). Use a opção "Entrar pelo navegador" ou crie uma exceção temporária para a sua conta.';
		}
		if ( str_contains( $desc, 'AADSTS65001' ) || str_contains( $desc, 'AADSTS90094' ) ) {
			return Settings::per_tenant()
				? 'É necessária a aprovação de um administrador para o Microsoft Graph Command Line Tools. Entre com um Global Administrator, Cloud Application Administrator ou Application Administrator.'
				: 'É necessária a aprovação de um administrador para o Microsoft Graph Command Line Tools. Entre com um Global Administrator.';
		}
		if ( Settings::per_tenant() && ( str_contains( $desc, 'AADSTS50105' ) || str_contains( $desc, 'AADSTS7000112' ) || str_contains( $desc, 'AADSTS50177' ) ) ) {
			// The tenant blocks Microsoft Graph Command Line Tools (assignment required / disabled).
			return 'O Microsoft Graph Command Line Tools está bloqueado no seu tenant (atribuição obrigatória ou aplicativo desabilitado). Use "Prefiro criar o aplicativo manualmente no Entra ID" ou libere-o temporariamente para a sua conta.';
		}
		if ( str_contains( $desc, 'AADSTS50011' ) ) {
			return 'A Microsoft recusou o endereço de retorno do método pelo navegador. Use o método por código.';
		}
		$clean = trim( preg_replace( '/\s*Trace ID:.*$/s', '', $desc ) );
		return '' !== $clean ? 'A Microsoft retornou um erro: ' . $clean : 'Falha ao entrar na Microsoft (' . ( $error ?: 'erro desconhecido' ) . ').';
	}

	/* ------------------------------------------------------------ helpers */

	private static function jwt_payload( string $jwt ): array {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) {
			return [];
		}
		$json = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ) ); // Received directly from the token endpoint over TLS.
		return json_decode( (string) $json, true ) ?: [];
	}

	private static function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function wp_user(): string {
		$u = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		return $u && ! empty( $u->user_login ) ? 'wp:' . $u->user_login : 'wp';
	}

	/** Manual re-test from wp-admin. */
	public static function test_connection(): array {
		$tenant = (string) Settings::get( 'home_tenant_id', '' );
		if ( '' === $tenant || ! Settings::is_configured() ) {
			return [ 'ok' => false, 'message' => 'Registre o aplicativo pelo portal antes de testar.' ];
		}
		try {
			Token_Service::acquire( $tenant, true );
			Client_Credential::record_test( true, 'Token de aplicativo obtido.' );
			return [ 'ok' => true, 'message' => 'Token de aplicativo obtido com sucesso.' ];
		} catch ( \Throwable $e ) {
			Client_Credential::record_test( false, $e->getMessage() );
			return [ 'ok' => false, 'message' => $e->getMessage() ];
		}
	}
}
