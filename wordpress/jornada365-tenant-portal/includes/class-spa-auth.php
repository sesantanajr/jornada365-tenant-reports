<?php
namespace J365TP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-tenant sign-in: each customer tenant has its OWN "Tenant Jornada365" app (single-tenant,
 * single-page application, delegated permissions, no secret, no certificate).
 *
 *   1. resolve  — e-mail/domain → tenant ID (public OpenID metadata) → the tenant's client ID.
 *   2. begin    — binding cookie + server nonce (login CSRF / ID-token replay protection).
 *   3. The browser signs in at Microsoft with the tenant's app (authorization code + PKCE) and
 *      redeems the code itself (CORS); tokens stay in the browser.
 *   4. session  — the ID token is verified (JWKS, issuer, audience = the tenant's app, nonce), the
 *      account and roles are checked live with the admin's delegated Graph token, and the portal
 *      session cookie is issued.
 *
 * The registry keeps only public identifiers (tenant ID → client ID). Registering or replacing an
 * app for a tenant requires a role that can manage applications in that tenant.
 */
final class Spa_Auth {

	public const BIND_COOKIE = 'j365tp_spa';
	private const BIND_TTL   = 900;
	private const PROBLEM_TTL = 600;
	private const GUID       = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
	/** Microsoft's "consumers" tenant (personal accounts). */
	private const MSA_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';
	/** Personal mail domains: no Microsoft 365 tenant behind them. */
	public const CONSUMER_DOMAINS = [ 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'gmail.com', 'googlemail.com', 'yahoo.com', 'icloud.com', 'me.com', 'outlook.com.br', 'hotmail.com.br', 'live.com.br', 'yahoo.com.br', 'bol.com.br', 'uol.com.br', 'terra.com.br', 'ig.com.br' ];

	public static function routes(): void {
		$xhr = [ self::class, 'xhr' ];
		register_rest_route( Rest_Api::NS, '/auth/resolve', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_resolve' ], 'permission_callback' => $xhr ] );
		register_rest_route( Rest_Api::NS, '/auth/begin', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_begin' ], 'permission_callback' => $xhr ] );
		register_rest_route( Rest_Api::NS, '/auth/session', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_session' ], 'permission_callback' => $xhr ] );
		register_rest_route( Rest_Api::NS, '/auth/fail', [ 'methods' => 'POST', 'callback' => [ self::class, 'rest_fail' ], 'permission_callback' => $xhr ] );
	}

	/** Public endpoints answer only the portal's own XHR (the custom header forces a CORS preflight elsewhere). */
	public static function xhr( WP_REST_Request $request ) {
		if ( 'XMLHttpRequest' !== (string) $request->get_header( 'x-requested-with' ) ) {
			return new WP_Error( 'j365_xhr', 'Requisição inválida.', [ 'status' => 400 ] );
		}
		return true;
	}

	/* ------------------------------------------------------------ urls */

	/** SPA redirect URI registered in every tenant's app. */
	public static function redirect_uri(): string {
		return Request::route_url( 'auth/spa' );
	}

	/** Portal URL that opens the "connect your tenant" assistant (optionally for a tenant). */
	public static function connect_url( string $tenant_id = '' ): string {
		return add_query_arg( array_filter( [ 'connect' => '1', 't' => Settings::company_tenant_id() ?: $tenant_id ] ), Request::portal_url() );
	}

	/** Portal URL that opens the sign-in dialog. */
	public static function signin_url( array $extra = [] ): string {
		return add_query_arg( array_merge( [ 'signin' => '1', 'lang' => I18n::current() ], array_filter( $extra ) ), Request::portal_url() );
	}

	/** Browser configuration (scopes are fully qualified for the v2.0 endpoint). */
	public static function client_config(): array {
		$company = Settings::company_tenant_id();
		return [
			'authority'   => Token_Service::AUTHORITY,
			'redirectUri' => self::redirect_uri(),
			'scopes'      => array_merge( Settings::OIDC_SCOPES, array_map( static fn( $s ) => Token_Service::GRAPH . '/' . $s, Settings::graph_scopes() ) ),
			'exoScopes'   => array_map( static fn( $s ) => Settings::RESOURCE_APPS['exchange']['audience'] . '/' . $s, Settings::exchange_scopes() ),
			'connectRoles' => array_values( Settings::CONNECT_ROLES ),
			'companyLogin' => '' !== $company ? [
				'tenantId' => $company,
				'label'    => (string) Settings::get( 'company_login_label', '' ),
			] : null,
		];
	}

	/* ------------------------------------------------------------ discovery */

	/**
	 * E-mail, domain or tenant ID → tenant ID, via the public OpenID Connect metadata of Entra ID.
	 *
	 * @return array{tenant_id:string, domain:string, error:string}
	 */
	public static function tenant_of( string $input ): array {
		$in = strtolower( trim( $input ) );
		if ( str_contains( $in, '@' ) ) {
			$in = substr( $in, strrpos( $in, '@' ) + 1 );
		}
		$in = trim( $in, " \t\n\r\0\x0B." );
		if ( preg_match( self::GUID, $in ) ) {
			$domain = '';
		} elseif ( preg_match( '/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $in ) ) {
			if ( in_array( $in, self::CONSUMER_DOMAINS, true ) ) {
				return [ 'tenant_id' => '', 'domain' => $in, 'error' => 'personal' ];
			}
			$domain = $in;
		} else {
			return [ 'tenant_id' => '', 'domain' => '', 'error' => 'invalid' ];
		}
		$cache = 'j365tp_hrd_' . md5( $in );
		$hit   = get_transient( $cache );
		if ( is_string( $hit ) ) {
			return [ 'tenant_id' => 'none' === $hit ? '' : $hit, 'domain' => $domain, 'error' => 'none' === $hit ? 'not_found' : '' ];
		}
		$res = wp_remote_get( Token_Service::AUTHORITY . '/' . rawurlencode( $in ) . '/v2.0/.well-known/openid-configuration', [ 'timeout' => 15 ] );
		if ( is_wp_error( $res ) ) {
			return [ 'tenant_id' => '', 'domain' => $domain, 'error' => 'network' ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$tid  = '';
		if ( 200 === $code && is_array( $body ) && preg_match( '#^https://login\.microsoftonline\.com/([0-9a-f-]{36})/v2\.0/?$#', (string) ( $body['issuer'] ?? '' ), $m ) ) {
			$tid = strtolower( $m[1] );
		}
		if ( '' === $tid && ( 400 === $code || 404 === $code ) ) {
			set_transient( $cache, 'none', HOUR_IN_SECONDS );
			return [ 'tenant_id' => '', 'domain' => $domain, 'error' => 'not_found' ];
		}
		if ( '' === $tid ) {
			return [ 'tenant_id' => '', 'domain' => $domain, 'error' => 'network' ];
		}
		set_transient( $cache, $tid, 6 * HOUR_IN_SECONDS );
		return [ 'tenant_id' => $tid, 'domain' => $domain, 'error' => self::MSA_TENANT === $tid ? 'personal' : '' ];
	}

	public static function rest_resolve( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Rate_Limiter::allow( 'spa-resolve', 40, 300 ) ) {
			return self::reply( [ 'ok' => false, 'code' => 'rate', 'message' => 'Muitas tentativas. Aguarde alguns minutos.' ], 429 );
		}
		$q       = mb_substr( trim( (string) $request->get_param( 'q' ) ), 0, 320 );
		$company = Settings::company_tenant_id();
		// A corporate shortcut starts at the operator's directory without a discovery request.
		$res = '' !== $company && ( '' === $q || preg_match( self::GUID, strtolower( $q ) ) )
			? [ 'tenant_id' => '' === $q ? $company : strtolower( $q ), 'domain' => '', 'error' => '' ]
			: self::tenant_of( $q );
		$msg = [
			'invalid'   => 'Informe o seu e-mail corporativo (ou o domínio do Microsoft 365).',
			'personal'  => 'Contas pessoais (Outlook.com, Gmail…) não têm tenant do Microsoft 365. Use o e-mail corporativo.',
			'not_found' => 'Não encontramos um tenant do Microsoft 365 para este domínio.',
			'network'   => 'Não foi possível consultar a Microsoft agora. Tente novamente.',
		];
		if ( '' !== $res['error'] ) {
			return self::reply( [ 'ok' => false, 'code' => $res['error'], 'message' => $msg[ $res['error'] ] ?? $msg['network'] ], 'network' === $res['error'] ? 502 : 200 );
		}
		$tid = $res['tenant_id'];
		if ( ! Settings::tenant_allowed( $tid ) ) {
			return self::reply( [ 'ok' => false, 'code' => 'tenant_not_allowed', 'message' => 'Este portal está restrito a tenants específicos e o seu não está na lista.' ] );
		}
		$tenant = Tenant_Repository::get( $tid );
		if ( $tenant && 'revoked' === (string) $tenant['status'] ) {
			return self::reply( [ 'ok' => false, 'code' => 'tenant_suspended', 'message' => 'O acesso deste tenant ao portal foi suspenso pelo operador.' ] );
		}
		$client = Tenant_Repository::client_id( $tenant );
		$hint   = is_email( $q ) ? strtolower( $q ) : '';
		return self::reply(
			[
				'ok'        => true,
				'tenantId'  => $tid,
				'domain'    => $res['domain'],
				'connected' => '' !== $client,
				'clientId'  => $client,
				'loginHint' => $hint,
			]
		);
	}

	/* ------------------------------------------------------------ sign-in */

	public static function nonce_for( string $binding ): string {
		return substr( hash_hmac( 'sha256', 'spa-nonce|' . $binding, Crypto::subkey( 'spa-auth' ) ), 0, 43 );
	}

	private static function bind_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::BIND_COOKIE, $value, [ 'expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
	}

	/** Starts a sign-in: only this browser (cookie) can finish it, and only once (nonce in the ID token). */
	public static function rest_begin(): WP_REST_Response {
		if ( ! Rate_Limiter::allow( 'spa-begin', 30, 300 ) ) {
			return self::reply( [ 'ok' => false, 'code' => 'rate', 'message' => 'Muitas tentativas. Aguarde alguns minutos.' ], 429 );
		}
		$bind = Crypto::random_token( 24 );
		self::bind_cookie( $bind, time() + self::BIND_TTL );
		return self::reply( [ 'ok' => true, 'nonce' => self::nonce_for( $bind ), 'ttl' => self::BIND_TTL ] );
	}

	/** Microsoft answered the redirect with an error: explain it on the portal's problem page. */
	public static function rest_fail( WP_REST_Request $request ): WP_REST_Response {
		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );
		$desc  = sanitize_text_field( (string) $request->get_param( 'error_description' ) );
		$tid   = strtolower( sanitize_text_field( (string) $request->get_param( 'tenant' ) ) );
		$ms    = Access_Problem::from_query( $error, $desc );
		$code  = Access_Problem::from_ms_error( $error, $desc );
		if ( 'AADSTS700016' === $ms['ms_code'] ) {
			$code = 'app_missing'; // the tenant's app was deleted in Entra ID (or is still replicating)
		}
		if ( Rate_Limiter::allow( 'spa-fail-log', 20, 300 ) ) {
			Audit::log( 'login_error', preg_match( self::GUID, $tid ) ? $tid : '', '', [ 'problem' => $code, 'ms' => $ms['ms_code'], 'trace' => $ms['trace'] ] );
		}
		return self::problem( $code, $ms + [ 'tenant_id' => preg_match( self::GUID, $tid ) ? $tid : '' ], 'cancelled' === $code ? 200 : 403 );
	}

	/**
	 * Finishes the sign-in. Body: { id_token, client_id }; header X-J365-Graph: delegated Graph token.
	 */
	public static function rest_session( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Rate_Limiter::allow( 'spa-session', 30, 300 ) ) {
			return self::reply( [ 'ok' => false, 'code' => 'rate', 'message' => 'Muitas tentativas. Aguarde alguns minutos.' ], 429 );
		}
		$body     = (array) ( $request->get_json_params() ?: [] );
		$id_token = (string) ( $body['id_token'] ?? '' );
		$client   = strtolower( (string) ( $body['client_id'] ?? '' ) );
		$bind     = (string) ( $_COOKIE[ self::BIND_COOKIE ] ?? '' );
		self::bind_cookie( '', time() - 3600 ); // one-time use

		if ( '' === $bind || ! preg_match( self::GUID, $client ) || strlen( $id_token ) > 16000 ) {
			return self::problem( 'state_expired', [], 400 );
		}
		// Single use on the server too: the same cookie + ID token can never open a second session.
		$used = 'j365tp_spa_used_' . substr( hash_hmac( 'sha256', $bind, Crypto::subkey( 'spa-auth' ) ), 0, 32 );
		if ( false !== get_transient( $used ) ) {
			return self::problem( 'state_expired', [], 400 );
		}
		set_transient( $used, 1, self::BIND_TTL );
		try {
			$verified = Jwt::verify_id_token( $id_token, $client, self::nonce_for( $bind ) );
		} catch ( \Throwable $e ) {
			Audit::log( 'login_error', '', '', [ 'stage' => 'jwt', 'description' => mb_substr( $e->getMessage(), 0, 200 ) ] );
			return self::problem( 'generic', [ 'ms_code' => 'id_token' ], 400 );
		}

		$claims = $verified['claims'];
		$tid    = strtolower( (string) $claims['tid'] );
		$oid    = (string) $claims['oid'];
		$upn    = (string) ( $claims['preferred_username'] ?? $claims['email'] ?? '' );
		$name   = (string) ( $claims['name'] ?? $upn );
		$wids   = $claims['wids'] ?? null;
		$ctx    = [ 'upn' => $upn, 'name' => $name, 'tenant_id' => $tid, 'wids' => $wids ];

		if ( self::MSA_TENANT === $tid ) {
			return self::problem( 'personal_account', $ctx );
		}
		if ( ! Settings::tenant_allowed( $tid ) ) {
			Audit::log( 'login_denied_tenant', $tid, $upn );
			return self::problem( 'tenant_not_allowed', $ctx );
		}
		$tenant = Tenant_Repository::get( $tid );
		if ( $tenant && 'revoked' === (string) $tenant['status'] ) {
			Audit::log( 'login_tenant_revoked', $tid, $upn );
			return self::problem( 'tenant_suspended', $ctx );
		}

		// The delegated Graph token sent with this request must belong to this user, tenant and app.
		if ( ! Delegated::capture( $tid, $oid, $client ) ) {
			Audit::log( 'login_error', $tid, $upn, [ 'stage' => 'graph_token', 'reason' => Delegated::error() ] );
			return self::problem( 'generic', $ctx + [ 'ms_code' => 'graph_token' ], 400 );
		}

		// Account state and directory roles, live in the user's tenant (with the user's own token).
		$auth = Authorization::evaluate( $tid, $oid, $upn, $tenant ?? [] );

		// The app this sign-in used must be the tenant's registered app. Registering it (first time,
		// after a manual creation or after the registry was lost) or replacing it requires a role
		// that manages applications in the tenant: from the signed "wids" claim or, for apps without
		// the directory-roles claim (manual registration), from Microsoft Graph.
		$registered = Tenant_Repository::client_id( $tenant );
		$register   = $registered !== $client;
		if ( $register ) {
			$can = self::can_connect( $wids ) || Rest_Api::has_role( (array) ( $auth['roles'] ?? [] ), array_keys( Settings::CONNECT_ROLES ) );
			if ( ! $can ) {
				Audit::log( 'login_app_unknown', $tid, $upn, [ 'registered' => '' !== $registered ] );
				return self::problem( '' === $registered ? 'tenant_not_connected' : 'app_mismatch', $ctx + [ 'roles' => $auth['roles'] ?? null ] );
			}
			// An app of another directory can never be bound to this tenant: it must exist here.
			try {
				$app = ( new Graph_Client( $tid ) )->get( "/v1.0/applications(appId='" . rawurlencode( $client ) . "')?\$select=id,appId,signInAudience" );
			} catch ( Graph_Exception $e ) {
				Audit::log( 'login_app_check_failed', $tid, $upn, [ 'status' => $e->getCode() ] );
				return self::problem( 404 === $e->getCode() ? 'app_foreign' : 'consent_incomplete', $ctx + [ 'ms_code' => (string) $e->graph_code() ] );
			}
			Tenant_Repository::register_app( $tid, $client, (string) ( $app['id'] ?? '' ), $upn, '' === $registered ? 'manual' : (string) ( $tenant['settings']['app_mode'] ?? 'manual' ) );
			Audit::log( 'tenant_app_registered', $tid, $upn, [ 'client_id' => $client, 'replaced' => '' !== $registered ] );
			$tenant = Tenant_Repository::get( $tid );
			if ( ! $auth['allowed'] ) {
				$auth = Authorization::evaluate( $tid, $oid, $upn, $tenant ?? [] ); // the administrator who just connected the tenant
			}
		}

		$consent        = Token_Service::delegated_state( Settings::active_permissions() );
		$ctx['consent'] = $consent;
		if ( 'blocked' === $consent['state'] ) {
			return self::problem( 'consent_incomplete', $ctx );
		}

		if ( ! $auth['allowed'] ) {
			Audit::log( 'login_denied_role', $tid, $upn, [ 'code' => $auth['code'] ?? '', 'roles' => array_column( $auth['roles'], 'name' ) ] );
			$problem = [
				'graph_forbidden'  => 'consent_incomplete',
				'graph_error'      => 'microsoft_unavailable',
				'account_missing'  => 'account_missing',
				'account_disabled' => 'account_disabled',
			][ $auth['code'] ?? '' ] ?? 'role_missing';
			return self::problem(
				$problem,
				$ctx + [
					'roles'   => 'role_missing' === $problem ? $auth['roles'] : null,
					'ms_code' => (string) ( $auth['graph_code'] ?? '' ),
					'error'   => ! empty( $auth['graph_error'] ) ? $auth['reason'] : '',
				],
				'microsoft_unavailable' === $problem ? 503 : 403
			);
		}

		Session::destroy();
		Session::create( $tid, $oid, $upn, $name, $auth['roles'], Org::lookup( $tid ) );
		Tenant_Repository::touch_login( $tid );
		Audit::log( 'login_success', $tid, $upn, [ 'roles' => array_column( $auth['roles'], 'name' ), 'consent' => $consent['state'], 'model' => 'per_tenant' ] );

		return self::reply( [ 'ok' => true, 'redirect' => add_query_arg( $register ? [ 'welcome' => '1' ] : [], Request::portal_url() ) ] );
	}

	/** Roles (template IDs in the ID token's "wids") allowed to register the tenant's app. */
	public static function can_connect( $wids ): bool {
		return is_array( $wids ) && (bool) array_intersect( array_map( 'strtolower', array_map( 'strval', $wids ) ), array_keys( Settings::CONNECT_ROLES ) );
	}

	/* ------------------------------------------------------------ problems */

	/**
	 * The problem page is rendered by the server (same pages as the classic sign-in): its view model
	 * waits at most 10 minutes under a random id and is deleted when shown.
	 */
	public static function problem( string $code, array $ctx = [], int $status = 403 ): WP_REST_Response {
		$id = Crypto::random_token( 18 );
		unset( $ctx['consent']['granted'] ); // the page needs only the counts and the missing list
		set_transient( 'j365tp_prob_' . $id, [ 'code' => $code, 'ctx' => $ctx, 'status' => $status ], self::PROBLEM_TTL );
		return self::reply( [ 'ok' => false, 'problem' => $code, 'url' => add_query_arg( 'id', $id, Request::route_url( 'auth/problem' ) ) ] );
	}

	/** GET /j365/auth/problem/?id=… */
	public static function render_problem(): void {
		$id   = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$data = '' !== $id ? get_transient( 'j365tp_prob_' . $id ) : false;
		if ( ! is_array( $data ) ) {
			Router::render_problem( 'state_expired', [], 400 );
		}
		delete_transient( 'j365tp_prob_' . $id );
		Router::render_problem( (string) $data['code'], (array) $data['ctx'], (int) $data['status'] );
	}

	/* ------------------------------------------------------------ redirect page */

	/** GET /j365/auth/spa/ — the browser finishes the sign-in here (code + PKCE, then /auth/session). */
	public static function render_page(): void {
		Page_Cache::bypass();
		$nonce = Shortcode::csp_nonce();
		$ver   = J365TP_ASSET_VERSION;
		$css   = add_query_arg( 'ver', $ver, J365TP_URL . 'assets/css/portal.css' );
		$theme = isset( $_COOKIE['j365_theme'] ) && 'dark' === $_COOKIE['j365_theme'] ? 'dark' : 'light';
		$js    = static fn( string $f ) => '<script src="' . esc_url( add_query_arg( 'ver', $ver, J365TP_URL . 'assets/js/' . $f . '.js' ) ) . '" defer></script>';
		if ( ! headers_sent() ) {
			status_header( 200 );
			Shortcode::security_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, max-age=0' );
			header( 'Referrer-Policy: no-referrer' ); // the URL carries the authorization code (fragment)
		}
		echo '<!doctype html><html lang="' . esc_attr( I18n::html_lang() ) . '" data-theme="' . esc_attr( $theme ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( I18n::t( 'Entrando…' ) . ' · ' . Settings::brand_name() ) . '</title>'
			. '<link rel="stylesheet" href="' . esc_url( $css ) . '">'
			. '<script nonce="' . esc_attr( $nonce ) . '">window.J365TP = ' . Shortcode::config_json() . ';</script>' // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX flags
			. $js( 'core' ) . $js( 'auth' )
			. '</head><body class="j365-body" data-j365-page="spa"><div class="j365tp">'
			. '<div class="lx-bg" aria-hidden="true"><i class="blob b1"></i><i class="blob b2"></i><i class="blob b3"></i><div class="gridlines"></div><div class="grain"></div></div>'
			. '<main class="sp"><article class="sp-card tone-info" id="j365-spa"><div class="sp-top">' . Router::brand_html() . '</div>'
			. '<div class="spa-wait" role="status" aria-live="polite"><span class="spinner" aria-hidden="true"></span><span id="j365-spa-msg">' . esc_html( I18n::t( 'Concluindo a entrada na Microsoft…' ) ) . '</span></div>'
			. '<noscript><p>' . esc_html( I18n::t( 'Este portal requer JavaScript habilitado no navegador.' ) ) . '</p></noscript>'
			. '</article></main></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
		exit;
	}

	private static function reply( array $data, int $status = 200 ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}
}
