<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Just-in-time delegated authorization for actions ("step-up"), in a popup window.
 *
 * Reports use the read-only application permissions. Actions never do: they run with a delegated
 * token of the admin who clicks, so Microsoft enforces that person's own Intune/Exchange RBAC and
 * records the change under their name in the Microsoft audit logs. The popup keeps the portal tab
 * (and the reports in its memory) intact; the token stays on the server, sealed with the session key.
 *
 *  /j365/auth/action/?cap=intune   → Microsoft sign-in (SSO, usually silent) → /j365/auth/callback/
 */
final class Action_Auth {

	/** Each capability gets its own token, limited to its own scope (least privilege per action). */
	public const CAPS = [
		'intune'   => [ 'resource' => 'graph', 'scopes' => [ 'DeviceManagementManagedDevices.PrivilegedOperations.All' ], 'label' => 'Ações remotas do Intune' ],
		'exchange' => [ 'resource' => 'exchange', 'scopes' => [ 'Exchange.Manage' ], 'label' => 'Ações de caixa de correio do Exchange Online' ],
		'reports'  => [ 'resource' => 'graph', 'scopes' => [ 'ReportSettings.ReadWrite.All' ], 'label' => 'Nomes nos relatórios de uso do Microsoft 365' ],
	];

	/**
	 * Entra roles that already include the workload RBAC needed (default tenant mode "roles").
	 * In mode "rbac" anyone authorized in the portal may try: Microsoft enforces the Intune/Exchange RBAC.
	 */
	public const CAP_ROLES = [
		'intune'   => [
			'62e90394-69f5-4237-9190-012177145e10', // Global Administrator
			'3a2c62db-5318-420d-8d74-23affee5d9d5', // Intune Administrator
		],
		'exchange' => [
			'62e90394-69f5-4237-9190-012177145e10', // Global Administrator
			'29232cdf-9323-42fd-ade2-1d097af3e4de', // Exchange Administrator
			'31392ffb-586c-42d1-9346-e59415a2cc4e', // Exchange Recipient Administrator
		],
		// The Microsoft 365 admin center lets only Global Administrators change the report privacy setting.
		'reports'  => [
			'62e90394-69f5-4237-9190-012177145e10', // Global Administrator
		],
	];

	/** Workload RBAC that grants each action (Microsoft documentation), shown as guidance. */
	public const RBAC = [
		'intune'   => [ 'Intune: Help Desk Operator', 'Intune: School Administrator (sincronizar e reiniciar)', 'Intune: Endpoint Security Manager', 'Intune: função personalizada com Remote tasks › Sync devices, Reboot now ou Windows defender', 'Entra: Intune Administrator', 'Entra: Global Administrator' ],
		'exchange' => [ 'Exchange: Recipient Management (função Mail Recipients)', 'Exchange: Organization Management', 'Entra: Exchange Recipient Administrator', 'Entra: Exchange Administrator', 'Entra: Global Administrator' ],
		'reports'  => [ 'Entra: Global Administrator' ],
	];

	private const STATE_TTL = 600;

	/** Full scope URIs for a capability ('' when the actions module is off). */
	public static function scope( string $cap ): string {
		$res    = self::CAPS[ $cap ]['resource'] ?? '';
		$scopes = array_values( array_intersect( (array) ( self::CAPS[ $cap ]['scopes'] ?? [] ), (array) ( Settings::action_scopes()[ $res ] ?? [] ) ) );
		$aud    = Settings::RESOURCE_APPS[ $res ]['audience'] ?? '';
		return implode( ' ', array_map( static fn( $sc ) => $aud . '/' . $sc, $scopes ) );
	}

	public static function enabled( string $cap ): bool {
		return isset( self::CAPS[ $cap ] ) && '' !== self::scope( $cap );
	}

	/** Tenant decision (Configurações → Ações): roles | rbac | off. Read live, not from the session. */
	public static function mode( array $session ): string {
		return Tenant_Repository::action_scope( Tenant_Repository::get( (string) $session['tenant_id'] ) );
	}

	/** Entra role that certainly includes the RBAC (GA, Intune/Exchange administrators). */
	public static function by_role( array $session, string $cap ): bool {
		return Rest_Api::has_role( (array) $session['roles'], self::CAP_ROLES[ $cap ] ?? [] );
	}

	public static function allowed( array $session, string $cap, ?string $mode = null ): bool {
		$mode ??= self::mode( $session );
		return isset( self::CAPS[ $cap ] ) && match ( $mode ) {
			'off'   => false,
			'rbac'  => true,
			default => self::by_role( $session, $cap ),
		};
	}

	/** Who may use each action, for messages. */
	public static function role_names( string $cap ): array {
		return array_values( array_map( static fn( $t ) => Access_Problem::ROLE_NAMES[ $t ] ?? $t, self::CAP_ROLES[ $cap ] ?? [] ) );
	}

	/** Status for the front-end: enabled, allowed for this user, seconds of authorization left. */
	public static function status( array $session ): array {
		$out  = [];
		$mode = self::mode( $session );
		foreach ( array_keys( self::CAPS ) as $cap ) {
			$out[ $cap ] = [
				'enabled'    => self::enabled( $cap ),
				'mode'       => $mode,
				'allowed'    => self::allowed( $session, $cap, $mode ),
				'byRole'     => self::by_role( $session, $cap ),
				'authorized' => self::enabled( $cap ) ? Action_Token::remaining( $session, $cap ) : 0,
				'roles'      => self::role_names( $cap ),
				'rbac'       => self::RBAC[ $cap ] ?? [],
			];
		}
		return $out;
	}

	/* ------------------------------------------------------------ popup start */

	/**
	 * Per-session key that the portal puts in the popup URL: another site cannot start an
	 * authorization in the visitor's session (top-level navigations carry SameSite=Lax cookies).
	 */
	public static function start_key( array $session ): string {
		return substr( hash_hmac( 'sha256', (string) $session['sid'] . '|action-start', Crypto::subkey( 'action-start' ) ), 0, 32 );
	}

	public static function start( string $cap ): void {
		$session = Session::current();
		if ( ! $session ) {
			self::popup( $cap, false, 'session_expired' );
		}
		$site = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) );
		$key  = (string) ( $_GET['k'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- HMAC checked below
		if ( ( '' !== $site && 'same-origin' !== $site ) || ! hash_equals( self::start_key( $session ), $key ) ) {
			Audit::log( 'action_auth_rejected', $session['tenant_id'], $session['upn'], [ 'cap' => $cap, 'site' => mb_substr( $site, 0, 20 ) ] );
			self::popup( $cap, false, 'invalid_request' );
		}
		if ( ! self::enabled( $cap ) ) {
			self::popup( $cap, false, 'disabled' );
		}
		$mode = self::mode( $session );
		if ( 'off' === $mode ) {
			self::popup( $cap, false, 'tenant_off' );
		}
		if ( ! self::allowed( $session, $cap, $mode ) ) {
			self::popup( $cap, false, 'forbidden' );
		}
		if ( ! Rate_Limiter::allow( 'action-auth:' . $session['tenant_id'], 30, 300 ) ) {
			self::popup( $cap, false, 'rate' );
		}
		$state     = Crypto::random_token( 24 );
		$nonce     = Crypto::random_token( 24 );
		$verifier  = Crypto::random_token( 48 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		set_transient(
			'j365tp_oidc_' . $state,
			[
				'kind'     => 'action',
				'cap'      => $cap,
				'nonce'    => $nonce,
				'verifier' => $verifier,
				'tid'      => $session['tenant_id'],
				'oid'      => $session['user_oid'],
				'lang'     => I18n::current(),
				'created'  => time(),
			],
			self::STATE_TTL
		);
		$params = [
			'client_id'             => Settings::client_id(),
			'response_type'         => 'code',
			'redirect_uri'          => Request::login_redirect_uri(),
			'response_mode'         => 'query',
			'scope'                 => 'openid ' . self::scope( $cap ),
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'login_hint'            => $session['upn'],
			'ui_locales'            => I18n::ms_locale(),
		];
		wp_safe_redirect( 'https://login.microsoftonline.com/' . rawurlencode( $session['tenant_id'] ) . '/oauth2/v2.0/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
		exit;
	}

	/* ------------------------------------------------------------ callback (from Oidc::callback) */

	public static function complete( array $stored, string $code, string $error, string $desc ): void {
		$cap     = (string) ( $stored['cap'] ?? '' );
		$session = Session::current();
		if ( ! isset( self::CAPS[ $cap ] ) ) {
			self::popup( '', false, 'generic' );
		}
		if ( '' !== $error ) {
			$ms = Token_Service::aadsts( $desc );
			Audit::log( 'action_auth_error', $session ? $session['tenant_id'] : '', $session ? $session['upn'] : '', [ 'cap' => $cap, 'error' => preg_match( '/^[a-z_]{3,40}$/', $error ) ? $error : 'invalid', 'ms' => $ms ] );
			self::popup( $cap, false, self::classify( $error, $ms ), $ms );
		}
		if ( ! $session || $session['tenant_id'] !== ( $stored['tid'] ?? '' ) || $session['user_oid'] !== ( $stored['oid'] ?? '' ) ) {
			self::popup( $cap, false, 'session_expired' );
		}
		try {
			$body = Token_Service::token_request(
				'https://login.microsoftonline.com/' . rawurlencode( $session['tenant_id'] ) . '/oauth2/v2.0/token',
				[
					'client_id'     => Settings::client_id(),
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => Request::login_redirect_uri(),
					'code_verifier' => (string) $stored['verifier'],
					'scope'         => 'openid ' . self::scope( $cap ),
				]
			);
		} catch ( \Throwable $e ) {
			self::popup( $cap, false, 'unavailable' );
		}
		if ( empty( $body['access_token'] ) || empty( $body['id_token'] ) ) {
			$ms = Token_Service::aadsts( (string) ( $body['error_description'] ?? '' ) );
			self::popup( $cap, false, self::classify( (string) ( $body['error'] ?? '' ), $ms ), $ms );
		}
		try {
			$claims = Jwt::verify_id_token( (string) $body['id_token'], Settings::client_id(), (string) $stored['nonce'] )['claims'];
		} catch ( \Throwable $e ) {
			self::popup( $cap, false, 'generic' );
		}
		// The token must be for the same person and tenant as the portal session.
		if ( strtolower( (string) ( $claims['tid'] ?? '' ) ) !== $session['tenant_id'] || (string) ( $claims['oid'] ?? '' ) !== $session['user_oid'] ) {
			Audit::log( 'action_auth_mismatch', $session['tenant_id'], $session['upn'], [ 'cap' => $cap ] );
			self::popup( $cap, false, 'account_mismatch' );
		}
		// The token must carry this capability's scope (Entra adds every scope already consented for the
		// resource; a missing one means the tenant has not approved it yet). Opaque tokens are not inspected.
		$scp = self::token_scopes( (string) $body['access_token'] );
		if ( null !== $scp ) {
			foreach ( (array) ( self::CAPS[ $cap ]['scopes'] ?? [] ) as $need ) {
				if ( ! in_array( strtolower( $need ), $scp, true ) ) {
					Audit::log( 'action_auth_scope_missing', $session['tenant_id'], $session['upn'], [ 'cap' => $cap ] );
					self::popup( $cap, false, 'consent_needed' );
				}
			}
		}
		Action_Token::put( $session, $cap, (string) $body['access_token'], (int) ( $body['expires_in'] ?? 3600 ) );
		Audit::log( 'action_authorized', $session['tenant_id'], $session['upn'], [ 'cap' => $cap ] );
		self::popup( $cap, true, 'ok' );
	}

	/** Lower-cased "scp" claim of a JWT access token, or null when the token is not a readable JWT. */
	public static function token_scopes( string $jwt ): ?array {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ), true ), true );
		if ( ! is_array( $claims ) || ! isset( $claims['scp'] ) || ! is_string( $claims['scp'] ) ) {
			return null;
		}
		return array_values( array_filter( array_map( 'strtolower', preg_split( '/\s+/', trim( $claims['scp'] ) ) ) ) );
	}

	private static function classify( string $error, string $ms ): string {
		return match ( true ) {
			in_array( $ms, [ 'AADSTS65001', 'AADSTS90094', 'AADSTS90095', 'AADSTS90008' ], true ) || 'consent_required' === $error => 'consent_needed',
			'AADSTS65004' === $ms || ( 'access_denied' === $error && '' === $ms ) => 'cancelled',
			'AADSTS50105' === $ms                                               => 'assignment_required',
			in_array( $ms, [ 'AADSTS53003', 'AADSTS53000', 'AADSTS53001', 'AADSTS530032' ], true ) => 'ca_blocked',
			in_array( $error, [ 'interaction_required', 'login_required' ], true ) => 'interaction',
			default                                                             => 'generic',
		};
	}

	/** Messages shown in the portal for each outcome (PT source; translated by the front-end). */
	public const MESSAGES = [
		'ok'                  => 'Autorizado. Executando a ação…',
		'session_expired'     => 'Sua sessão do portal expirou. Entre novamente e repita a ação.',
		'invalid_request'     => 'Solicitação inválida. Abra a ação pelo próprio portal.',
		'disabled'            => 'As ações estão desativadas neste portal.',
		'tenant_off'          => 'As ações foram desativadas neste tenant por um administrador (Configurações → Ações).',
		'forbidden'           => 'Sua função não permite esta ação no portal.',
		'rate'                => 'Muitas autorizações em sequência. Aguarde alguns minutos.',
		'consent_needed'      => 'Esta ação precisa de uma aprovação única de um administrador para a permissão delegada.',
		'cancelled'           => 'Autorização cancelada. Nada foi executado.',
		'assignment_required' => 'Seu usuário não está atribuído ao aplicativo no seu tenant.',
		'ca_blocked'          => 'Uma política de Acesso Condicional bloqueou a autorização.',
		'interaction'         => 'A Microsoft pediu uma nova verificação. Tente de novo.',
		'account_mismatch'    => 'A autorização foi feita com outra conta. Use a mesma conta do portal.',
		'unavailable'         => 'A Microsoft não respondeu. Tente novamente em instantes.',
		'generic'             => 'Não foi possível autorizar a ação.',
	];

	/** Tiny page in the popup: tells the portal tab the outcome and closes itself. Never carries tokens. */
	private static function popup( string $cap, bool $ok, string $code, string $ms = '' ): void {
		$origin  = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) . '://' . (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) . ( wp_parse_url( home_url( '/' ), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url( '/' ), PHP_URL_PORT ) : '' );
		$session = Session::current();
		$payload = [
			'j365'    => 'action-auth',
			'cap'     => $cap,
			'ok'      => $ok,
			'code'    => $code,
			'ms'      => $ms,
			'message' => I18n::t( self::MESSAGES[ $code ] ?? self::MESSAGES['generic'] ),
			'approve' => 'consent_needed' === $code && $session ? add_query_arg( [ 't' => $session['tenant_id'] ], Request::route_url( 'consent/start' ) ) : '',
			'roles'   => $cap ? self::role_names( $cap ) : [],
		];
		$nonce  = Shortcode::csp_nonce();
		// Two channels: window.opener (classic popup) and BroadcastChannel (same-origin, survives a
		// severed opener). The portal also checks /actions/status when the popup closes.
		$script = '(function(){var p=' . wp_json_encode( $payload ) . ';var sent=false;try{if(window.BroadcastChannel){var bc=new BroadcastChannel("j365tp-action");bc.postMessage(p);bc.close();sent=true;}}catch(e){}'
			. 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage(p,' . wp_json_encode( $origin ) . ');sent=true;}}catch(e){}'
			. 'if(sent){setTimeout(function(){window.close();},' . ( $ok ? 200 : 600 ) . ');}})();';
		$html   = '<!doctype html><html lang="' . esc_attr( I18n::html_lang() ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( Settings::brand_name() ) . '</title><link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', J365TP_ASSET_VERSION, J365TP_URL . 'assets/css/portal.css' ) ) . '">'
			. '</head><body class="j365-body"><div class="j365tp"><main class="sp"><article class="sp-card tone-' . ( $ok ? 'ok' : 'warn' ) . '"><header class="sp-head"><div class="sp-icon ' . ( $ok ? 'ok' : 'warn' ) . '">' . Router::icon( $ok ? 'check' : 'shield' ) . '</div><div class="sp-headtxt"><h1>' . esc_html( I18n::t( $ok ? 'Autorizado' : 'Autorização não concluída' ) ) . '</h1></div></header>'
			. '<p class="lead">' . esc_html( $payload['message'] ) . '</p><p class="fine">' . esc_html( I18n::t( 'Você já pode fechar esta janela e voltar ao portal.' ) ) . '</p></article></main></div>'
			. '<script nonce="' . esc_attr( $nonce ) . '">' . $script . '</script></body></html>';
		Page_Cache::bypass();
		if ( ! headers_sent() ) {
			status_header( 200 );
			Shortcode::security_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, max-age=0' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* above
		exit;
	}
}
