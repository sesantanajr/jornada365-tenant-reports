<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenID Connect sign-in (authorization code + PKCE) against the "organizations" authority,
 * so users from any Entra tenant can sign in. Authorization happens in Authorization::evaluate().
 */
final class Oidc {

	private const STATE_TTL = 600;
	/** HttpOnly cookie that ties a sign-in to the browser that started it (login CSRF protection). */
	public const BIND_COOKIE = 'j365tp_oidc';

	public static function start( string $login_hint = '', string $tenant_hint = '' ): void {
		if ( ! Settings::is_configured() ) {
			Router::render_message( 'Portal em configuração', 'O aplicativo ainda não foi registrado pelo operador do portal. Tente novamente mais tarde.', 'error' );
		}
		if ( ! Rate_Limiter::allow( 'login', 20, 300 ) ) {
			Router::render_message( 'Muitas tentativas', 'Aguarde alguns minutos e tente novamente.', 'error', 429 );
		}

		$state    = Crypto::random_token( 24 );
		$nonce    = Crypto::random_token( 24 );
		$verifier = Crypto::random_token( 48 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$segment   = Request::tenant_segment( $tenant_hint );
		$bind      = Crypto::random_token( 24 );
		self::bind_cookie( $bind, time() + self::STATE_TTL );

		set_transient(
			'j365tp_oidc_' . $state,
			[
				'nonce'    => $nonce,
				'verifier' => $verifier,
				'binding'  => hash( 'sha256', $bind ),
				'segment'  => $segment,
				'lang'     => I18n::current(), // pages after Microsoft's sign-in keep the visitor's language
				'created'  => time(),
			],
			self::STATE_TTL
		);

		$authority = 'https://login.microsoftonline.com/' . $segment;

		$params = [
			'client_id'             => Settings::client_id(),
			'response_type'         => 'code',
			'redirect_uri'          => Request::login_redirect_uri(),
			'response_mode'         => 'query',
			'scope'                 => 'openid profile email',
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'prompt'                => 'select_account',
			'ui_locales'            => I18n::ms_locale(),
		];
		if ( '' !== $login_hint && is_email( $login_hint ) ) {
			$params['login_hint'] = $login_hint;
		}

		wp_safe_redirect( $authority . '/oauth2/v2.0/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
		exit;
	}

	private static function bind_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::BIND_COOKIE, $value, [ 'expires' => $expires, 'path' => '/j365/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
	}

	/** Microsoft's "consumers" tenant: personal accounts (Outlook.com, Hotmail, Live). */
	private const MSA_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

	public static function callback(): void {
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		$desc  = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? '' ) );

		$stored = '' !== $state ? get_transient( 'j365tp_oidc_' . $state ) : false;
		if ( is_array( $stored ) ) {
			delete_transient( 'j365tp_oidc_' . $state ); // one-time use, also when Microsoft answers with an error
			if ( ! empty( $stored['lang'] ) ) {
				I18n::set( (string) $stored['lang'] );
			}
			// Step-up authorization for an action (popup): answered in the popup, never as a login.
			if ( 'action' === ( $stored['kind'] ?? '' ) ) {
				Action_Auth::complete( $stored, $code, $error, $desc );
			}
		}

		// Anyone can call this URL: failures are logged at most 20 times per visitor in 5 minutes.
		$nolog = static fn(): array => Rate_Limiter::allow( 'oidc-callback-error', 20, 300 ) ? [] : [ '_nolog' => true ];

		if ( '' !== $error ) {
			// The query string can be crafted: only validated codes/IDs are logged or shown, never free text.
			$ms      = Access_Problem::from_query( $error, $desc );
			$problem = Access_Problem::from_ms_error( $error, $desc );
			$log     = $nolog();
			if ( ! $log ) {
				Audit::log( 'login_error', '', '', [ 'problem' => $problem, 'ms' => $ms['ms_code'], 'trace' => $ms['trace'] ] );
			}
			Router::render_problem( $problem, $ms + $log, 'cancelled' === $problem ? 200 : 403 );
		}

		if ( ! is_array( $stored ) || '' === $code ) {
			Router::render_problem( 'state_expired', $nolog(), 400 );
		}

		// Only the browser that started this sign-in may finish it: a code+state link sent by someone
		// else would otherwise sign the visitor into the sender's tenant (login CSRF).
		$cookie = (string) ( $_COOKIE[ self::BIND_COOKIE ] ?? '' );
		self::bind_cookie( '', time() - 3600 );
		if ( empty( $stored['binding'] ) || '' === $cookie || ! hash_equals( (string) $stored['binding'], hash( 'sha256', $cookie ) ) ) {
			$log = $nolog();
			if ( ! $log ) {
				Audit::log( 'login_error', '', '', [ 'stage' => 'binding' ] );
			}
			Router::render_problem( 'state_expired', $log, 400 );
		}

		try {
			$body = Token_Service::token_request(
				'https://login.microsoftonline.com/' . Request::tenant_segment( (string) ( $stored['segment'] ?? '' ) ) . '/oauth2/v2.0/token',
				[
					'client_id'     => Settings::client_id(),
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => Request::login_redirect_uri(),
					'code_verifier' => $stored['verifier'],
					'scope'         => 'openid profile email',
				]
			);
		} catch ( \Throwable $e ) {
			// Network details (proxy host, cURL text) stay in the log, never on the page.
			Audit::log( 'login_error', '', '', [ 'stage' => 'token_network', 'description' => mb_substr( $e->getMessage(), 0, 200 ) ] );
			Router::render_problem( 'microsoft_unavailable', [], 503 );
		}
		if ( empty( $body['id_token'] ) ) {
			$d = (string) ( $body['error_description'] ?? 'Resposta sem id_token.' );
			Audit::log( 'login_error', '', '', [ 'stage' => 'token', 'description' => mb_substr( $d, 0, 300 ) ] );
			Router::render_problem( Access_Problem::from_ms_error( (string) ( $body['error'] ?? '' ), $d ), [ 'ms_code' => Token_Service::aadsts( $d ) ?: (string) ( $body['error'] ?? '' ), 'error' => $d ] );
		}

		try {
			$verified = Jwt::verify_id_token( (string) $body['id_token'], Settings::client_id(), (string) $stored['nonce'] );
		} catch ( \Throwable $e ) {
			Audit::log( 'login_error', '', '', [ 'stage' => 'jwt', 'description' => mb_substr( $e->getMessage(), 0, 200 ) ] );
			Router::render_problem( 'generic', [ 'ms_code' => 'id_token' ], 400 );
		}

		$claims    = $verified['claims'];
		$tenant_id = strtolower( (string) $claims['tid'] );
		$oid       = (string) $claims['oid'];
		$upn       = (string) ( $claims['preferred_username'] ?? $claims['email'] ?? '' );
		$name      = (string) ( $claims['name'] ?? $upn );
		$wids      = $claims['wids'] ?? null;
		$ctx       = [ 'upn' => $upn, 'name' => $name, 'tenant_id' => $tenant_id, 'wids' => $wids ];

		if ( self::MSA_TENANT === $tenant_id ) {
			Router::render_problem( 'personal_account', $ctx );
		}

		// Tenant allowlist (optional operator restriction).
		$allowed_tenants = Settings::allowed_tenants();
		if ( $allowed_tenants && ! in_array( $tenant_id, $allowed_tenants, true ) ) {
			Audit::log( 'login_denied_tenant', $tenant_id, $upn );
			Router::render_problem( 'tenant_not_allowed', $ctx );
		}

		$tenant = Tenant_Repository::get( $tenant_id );
		if ( $tenant && 'revoked' === $tenant['status'] ) {
			Audit::log( 'login_tenant_revoked', $tenant_id, $upn );
			Router::render_problem( 'tenant_suspended', $ctx );
		}

		// What did the tenant actually approve? (roles claim of a fresh app-only token)
		$consent        = Token_Service::consent_state( $tenant_id );
		$ctx['consent'] = $consent;
		switch ( $consent['state'] ) {
			case 'error':
				$problem = 'portal_config' === Access_Problem::from_ms_error( '', $consent['error'] ) ? 'portal_config' : 'microsoft_unavailable';
				Router::render_problem( $problem, $ctx + [ 'ms_code' => $consent['code'], 'error' => $consent['error'] ], 503 );
				break;
			case 'disabled':
				Router::render_problem( 'app_disabled', $ctx + [ 'ms_code' => $consent['code'] ] );
				break;
			case 'none':
			case 'pending':
			case 'blocked':
				// An approval just happened: Microsoft may take a few minutes to put the roles in tokens.
				if ( get_transient( Consent::wait_key( $tenant_id ) ) ) {
					Router::render_problem( 'consent_propagating', $ctx, 200 );
				}
				$removed = 'none' === $consent['state'] && $tenant && 'active' === $tenant['status'];
				if ( $removed ) {
					Tenant_Repository::set_status( $tenant_id, 'disconnected' );
					Audit::log( 'login_tenant_disconnected', $tenant_id, $upn );
				}
				Audit::log( 'login_tenant_not_connected', $tenant_id, $upn, [ 'state' => $consent['state'], 'granted' => count( $consent['granted'] ) ] );
				// Administrators who can approve go straight to Microsoft's approval screen.
				if ( 'consent' === Consent::decide( $wids ) ) {
					Consent::start_for_identity( $tenant_id, $oid, $upn, $name );
				}
				$problem = [ 'none' => $removed ? 'consent_removed' : 'consent_missing', 'pending' => 'consent_pending', 'blocked' => 'consent_incomplete' ][ $consent['state'] ];
				Router::render_problem( $problem, $ctx );
				break;
		}

		if ( ! $tenant || 'active' !== $tenant['status'] ) {
			Consent::register_tenant( $tenant_id, '', 'detected' );
			$tenant = Tenant_Repository::get( $tenant_id );
		}

		$auth = Authorization::evaluate( $tenant_id, $oid, $upn, $tenant ?? [] );
		if ( ! $auth['allowed'] ) {
			Audit::log( 'login_denied_role', $tenant_id, $upn, [ 'code' => $auth['code'] ?? '', 'roles' => array_column( $auth['roles'], 'name' ) ] );
			$problem = [
				'graph_forbidden'  => 'consent_incomplete',
				'graph_error'      => 'microsoft_unavailable',
				'account_missing'  => 'account_missing',
				'account_disabled' => 'account_disabled',
			][ $auth['code'] ?? '' ] ?? 'role_missing';
			Router::render_problem(
				$problem,
				$ctx + [
					'roles'    => 'role_missing' === $problem ? $auth['roles'] : null,
					'ms_code'  => (string) ( $auth['graph_code'] ?? '' ),
					'endpoint' => ! empty( $auth['graph_error'] ) ? 'GET /v1.0/users/{id}' : '',
					'error'    => ! empty( $auth['graph_error'] ) ? $auth['reason'] : '',
				],
				'microsoft_unavailable' === $problem ? 503 : 403
			);
		}

		Session::destroy();
		Session::create( $tenant_id, $oid, $upn, $name, $auth['roles'], Org::lookup( $tenant_id ) );
		Tenant_Repository::touch_login( $tenant_id );
		delete_transient( Consent::wait_key( $tenant_id ) );
		Audit::log( 'login_success', $tenant_id, $upn, [ 'roles' => array_column( $auth['roles'], 'name' ), 'consent' => $consent['state'] ] );

		wp_safe_redirect( Request::portal_url() );
		exit;
	}

	public static function logout(): void {
		$session = Session::current();
		if ( $session ) {
			Audit::log( 'logout', $session['tenant_id'], $session['upn'] );
		}
		Session::destroy();
		$post_logout = rawurlencode( Request::portal_url() );
		wp_safe_redirect( 'https://login.microsoftonline.com/common/oauth2/v2.0/logout?post_logout_redirect_uri=' . $post_logout );
		exit;
	}
}
