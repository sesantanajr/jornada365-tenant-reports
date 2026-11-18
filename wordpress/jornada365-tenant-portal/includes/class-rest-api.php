<?php
namespace J365TP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API consumed by the portal front-end: /wp-json/j365/v1/…
 * Every route requires a valid portal session (cookie) + CSRF nonce header.
 */
final class Rest_Api {

	public const NS = 'j365/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'no_store' ], 10, 3 );
	}

	/** Report data must never be cached by browsers or intermediaries. */
	public function no_store( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && str_starts_with( (string) $request->get_route(), '/' . self::NS ) ) {
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
		}
		return $response;
	}

	public function routes(): void {
		$auth = [ $this, 'authorize' ];
		$change = [ $this, 'authorize_change' ];

		register_rest_route( self::NS, '/me', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'me' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/reports/(?P<type>[a-z_]+)', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'start_report' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/jobs/(?P<id>[0-9a-f-]{36})/run', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'run_job' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/jobs', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'list_report_jobs' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/jobs/(?P<id>[0-9a-f-]{36})/discard', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'discard_report_job' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/jobs/(?P<id>[0-9a-f-]{36})/result', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'job_result' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/allowlist', [
			[ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'allowlist_get' ], 'permission_callback' => $auth ],
			[ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'allowlist_add' ], 'permission_callback' => $change ],
		] );
		register_rest_route( self::NS, '/allowlist/(?P<id>\d+)', [ 'methods' => WP_REST_Server::DELETABLE, 'callback' => [ $this, 'allowlist_delete' ], 'permission_callback' => $change ] );
		register_rest_route( self::NS, '/tenant/settings', [ 'methods' => 'PATCH', 'callback' => [ $this, 'tenant_settings' ], 'permission_callback' => $change ] );
		register_rest_route( self::NS, '/cache/clear', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'cache_clear' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/audit', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'audit' ], 'permission_callback' => $auth ] );
		// Actions (delegated, run as the signed-in admin): roles re-verified live before every start.
		register_rest_route( self::NS, '/actions', [ 'methods' => WP_REST_Server::READABLE, 'callback' => [ $this, 'actions_status' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/actions/(?P<cap>intune|exchange)', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'action_start' ], 'permission_callback' => $change ] );
		register_rest_route( self::NS, '/actions/reports', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => [ $this, 'report_names' ], 'permission_callback' => $change ] );

		// Per-tenant model: tenant discovery + sign-in with the tenant's own app, and the public
		// "connect your tenant" flow that creates that app in the customer's tenant.
		Spa_Auth::routes();
		Onboarding::routes();
		if ( ! Settings::per_tenant() ) {
			// Classic multi-tenant model only: in-portal registration of the publisher app (WordPress
			// administrators) and operator diagnostics with its app-only token. The per-tenant model has
			// no app in the publisher tenant, so these endpoints do not exist there.
			Bootstrap::routes();
			Diagnostics::routes();
		}
	}

	/**
	 * Permission callback: portal session + CSRF nonce.
	 */
	public function authorize( WP_REST_Request $request ) {
		$session = Session::current();
		if ( ! $session ) {
			return new WP_Error( 'j365_unauthenticated', 'Sessão expirada. Entre novamente.', [ 'status' => 401 ] );
		}
		$nonce    = (string) $request->get_header( 'x-j365-nonce' );
		$expected = Session::csrf_token();
		if ( '' === $nonce || '' === $expected || ! hash_equals( $expected, $nonce ) ) {
			return new WP_Error( 'j365_csrf', 'Token CSRF inválido. Recarregue a página.', [ 'status' => 403 ] );
		}
		if ( ! Rate_Limiter::allow( 'api:' . $session['tenant_id'], 600, 60 ) ) {
			return new WP_Error( 'j365_rate', 'Limite de requisições excedido.', [ 'status' => 429 ] );
		}
		// Per-tenant model: every call carries the admin's delegated token(s) for the tenant's own app.
		if ( Settings::per_tenant() && ! Delegated::capture( $session['tenant_id'], $session['user_oid'], Tenant_Repository::client_id( $session['tenant'] ) ) ) {
			return new WP_Error( 'j365_token', 'Renovando a autorização da Microsoft…', [ 'status' => 401, 'reason' => Delegated::error() ] );
		}
		// Zero Trust: account state and directory roles are re-verified in the user's tenant every
		// 15 minutes. Graph outages get a bounded grace period (then the session ends: fail closed).
		if ( time() >= (int) $session['recheck_at'] ) {
			$auth = Authorization::evaluate( $session['tenant_id'], $session['user_oid'], $session['upn'], $session['tenant'] );
			if ( ! empty( $auth['graph_error'] ) ) {
				if ( time() - (int) $session['roles_at'] > Session::ROLE_GRACE ) {
					Audit::log( 'session_recheck_failed', $session['tenant_id'], $session['upn'] );
					Session::destroy();
					return new WP_Error( 'j365_unauthenticated', 'Não foi possível revalidar sua função no Entra ID. Entre novamente.', [ 'status' => 401 ] );
				}
				Session::defer_role_check( 60 );
			} elseif ( ! $auth['allowed'] || self::revoked_in_entra( $auth, $session ) ) {
				$revoked = $auth['allowed'];
				Audit::log( $revoked ? 'session_revoked_entra' : 'session_revoked_role', $session['tenant_id'], $session['upn'] );
				Session::destroy();
				return new WP_Error( 'j365_unauthenticated', $revoked ? 'Suas sessões foram revogadas no Entra ID. Entre novamente.' : 'Sua conta ou função de diretório não permite mais o acesso. Entre novamente.', [ 'status' => 401 ] );
			} else {
				Session::refresh_roles( $auth['roles'] );
			}
		}
		return true;
	}

	/** Directory roles (template IDs) allowed to change who can use the portal for the tenant. */
	public const MANAGE_ACCESS_ROLES = [
		'62e90394-69f5-4237-9190-012177145e10', // Global Administrator
		'e8611ab8-c189-46e8-94e1-60213ab1f814', // Privileged Role Administrator
	];
	/** Directory roles allowed to change app homologation (allowlist, Intune/publisher rules). */
	public const MANAGE_APPS_ROLES = [
		'62e90394-69f5-4237-9190-012177145e10', // Global Administrator
		'3a2c62db-5318-420d-8d74-23affee5d9d5', // Intune Administrator
		'194ae4cb-b126-40b2-bd5b-6091b380977d', // Security Administrator
	];

	/** "Revoke sessions" in Entra ID after this portal session was issued (60 s clock tolerance). */
	private static function revoked_in_entra( array $auth, array $session ): bool {
		$from = (int) ( $auth['valid_from'] ?? 0 );
		return $from > 0 && (int) ( $session['issued_at'] ?? 0 ) > 0 && $from > (int) $session['issued_at'] + 60;
	}

	/** @param array $roles [['id' => templateId, 'name' => …]] */
	public static function has_role( array $roles, array $allowed ): bool {
		foreach ( $roles as $r ) {
			if ( in_array( strtolower( (string) ( $r['id'] ?? '' ) ), $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function capabilities( array $roles ): array {
		return [
			'manageAccess'    => self::has_role( $roles, self::MANAGE_ACCESS_ROLES ),
			'manageApps'      => self::has_role( $roles, self::MANAGE_APPS_ROLES ),
			'updateTenantApp' => self::has_role( $roles, array_keys( Settings::CONNECT_ROLES ) ),
		];
	}

	/**
	 * Changes always re-verify the directory roles live in the user's tenant (no 15-minute grace):
	 * readers, extra users and a consenter whose role was removed cannot change anything.
	 */
	public function authorize_change( WP_REST_Request $request ) {
		$ok = $this->authorize( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$s    = Session::current();
		$auth = Authorization::evaluate( $s['tenant_id'], $s['user_oid'], $s['upn'], $s['tenant'] );
		if ( ! empty( $auth['graph_error'] ) ) {
			return new WP_Error( 'j365_role_check', 'Não foi possível confirmar sua função no Entra ID agora. Tente novamente.', [ 'status' => 503 ] );
		}
		if ( ! $auth['allowed'] || self::revoked_in_entra( $auth, $s ) ) {
			Audit::log( 'session_revoked_role', $s['tenant_id'], $s['upn'], [ 'on' => 'change' ] );
			Session::destroy();
			return new WP_Error( 'j365_unauthenticated', 'Sua conta ou função de diretório não permite mais o acesso. Entre novamente.', [ 'status' => 401 ] );
		}
		Session::refresh_roles( $auth['roles'] );
		return true;
	}

	private function forbid_change( string $what ): WP_Error {
		return new WP_Error( 'j365_forbidden', $what, [ 'status' => 403 ] );
	}

	private function session(): array {
		return Session::current();
	}

	private function error( \Throwable $e ): WP_Error {
		if ( $e instanceof Graph_Exception && 'j365_token' === $e->graph_code() ) {
			// The browser renews the admin's delegated token and repeats the call (per-tenant model).
			return new WP_Error( 'j365_token', $e->getMessage(), [ 'status' => 401 ] );
		}
		$status = $e instanceof \InvalidArgumentException ? 400 : ( $e instanceof Graph_Exception && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500 );
		return new WP_Error( 'j365_error', $e->getMessage(), [ 'status' => 403 === $status ? 424 : $status ] );
	}

	public function me(): WP_REST_Response {
		$s      = $this->session();
		$tenant = $s['tenant'];
		$you    = '' !== (string) ( $tenant['consented_by'] ?? '' ) && hash_equals( (string) $tenant['consented_by'], Privacy::uid( $s['upn'] ) );
		return new WP_REST_Response(
			[
				'user'     => [ 'oid' => $s['user_oid'], 'upn' => $s['upn'], 'name' => $s['display_name'], 'roles' => $s['roles'], 'can' => self::capabilities( $s['roles'] ) ],
				'tenant'   => [
					'id'             => $s['tenant_id'],
					'name'           => (string) ( $s['org']['name'] ?? '' ),
					'domain'         => (string) ( $s['org']['domain'] ?? '' ),
					'consentedAt'    => $tenant['consented_at'],
					'consentedByYou' => $you,
					'settings'       => Tenant_Repository::public_settings( $tenant ),
					'app'            => Settings::per_tenant() ? [ 'clientId' => Tenant_Repository::client_id( $tenant ), 'mode' => (string) Tenant_Repository::get_setting( $tenant, 'app_mode', 'auto' ) ] : null,
				],
				'defaults' => [
					'inactive_days'     => (int) Settings::get( 'inactive_days', 90 ),
					'stale_device_days' => (int) Settings::get( 'stale_device_days', 90 ),
				],
				'privacy'  => [
					'serverStorage'   => false,
					'auditDays'       => Audit::retention_days(),
					'jobTtlMinutes'   => (int) ( Jobs::TTL / MINUTE_IN_SECONDS ),
					'roleCheckMinutes' => (int) ( Session::ROLE_RECHECK / MINUTE_IN_SECONDS ),
				],
				'session'  => [ 'expiresAt' => $s['expires_at'] ],
				'brand'    => Settings::brand_name(),
				'reports'  => Settings::active_reports(),
				'modules'  => Settings::enabled_modules(),
				'actions'  => Action_Auth::status( $s ),
				'permissions' => self::permission_status( $s['tenant_id'] ),
			]
		);
	}

	public function start_report( WP_REST_Request $request ) {
		$s      = $this->session();
		$type   = sanitize_key( $request['type'] );
		$body   = $request->get_json_params() ?: [];
		$params = is_array( $body['params'] ?? null ) ? $body['params'] : [];
		try {
			$request_id = $body['request_id'] ?? '';
			if ( ! is_string( $request_id ) ) {
				throw new \InvalidArgumentException( 'Identificador de solicitação inválido.' );
			}
			return new WP_REST_Response( Jobs::start( $s, $type, $params, false, $request_id ) );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	public function run_job( WP_REST_Request $request ) {
		$s = $this->session();
		try {
			return new WP_REST_Response( Jobs::run( (string) $request['id'], $s, '1' === (string) $request->get_header( 'x-j365-report-only' ) ) );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	public function list_report_jobs() {
		try {
			return new WP_REST_Response( [ 'jobs' => Jobs::reports_for_session( $this->session() ) ] );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	public function discard_report_job( WP_REST_Request $request ) {
		try {
			if ( Jobs::discard_report( (string) $request['id'], $this->session() ) ) {
				return new WP_REST_Response( [ 'discarded' => true ] );
			}
			return new WP_Error( 'j365_not_found', 'Relatório indisponível nesta sessão.', [ 'status' => 404 ] );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	public function job_result( WP_REST_Request $request ) {
		$s      = $this->session();
		$result = Jobs::result( (string) $request['id'], $s, '1' === (string) $request->get_header( 'x-j365-report-only' ) );
		if ( null === $result ) {
			return new WP_Error( 'j365_not_ready', 'Resultado indisponível (job não concluído ou expirado).', [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $result );
	}

	public function allowlist_get(): WP_REST_Response {
		$s     = $this->session();
		$me    = Privacy::uid( $s['upn'] );
		$rules = array_map(
			function ( $r ) use ( $me ) {
				$r['created_by'] = ( '' !== (string) $r['created_by'] && hash_equals( Privacy::uid( (string) $r['created_by'] ), $me ) ) ? 'you' : '';
				return $r;
			},
			Allowlist_Repository::all( $s['tenant_id'] )
		);
		return new WP_REST_Response( [ 'rules' => $rules ] );
	}

	public function allowlist_add( WP_REST_Request $request ) {
		$s = $this->session();
		if ( ! self::has_role( $s['roles'], self::MANAGE_APPS_ROLES ) ) {
			return $this->forbid_change( 'Somente Global Administrator, Intune Administrator ou Security Administrator (atribuição ativa) podem homologar aplicativos.' );
		}
		try {
			$rule = Allowlist_Repository::add( $s['tenant_id'], $request->get_json_params() ?: [], $s['upn'] );
			$rule['created_by'] = 'you';
			return new WP_REST_Response( [ 'rule' => $rule ], 201 );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
	}

	public function allowlist_delete( WP_REST_Request $request ) {
		$s  = $this->session();
		if ( ! self::has_role( $s['roles'], self::MANAGE_APPS_ROLES ) ) {
			return $this->forbid_change( 'Somente Global Administrator, Intune Administrator ou Security Administrator (atribuição ativa) podem homologar aplicativos.' );
		}
		$ok = Allowlist_Repository::remove( $s['tenant_id'], (int) $request['id'], $s['upn'] );
		return $ok ? new WP_REST_Response( [ 'deleted' => true ] ) : new WP_Error( 'j365_not_found', 'Regra não encontrada.', [ 'status' => 404 ] );
	}

	/**
	 * Tenant preferences. Extra authorized users are stored only as keyed pseudonyms:
	 * extra_users_add / extra_users_remove (UPN lists) and extra_users_clear (bool).
	 */
	public function tenant_settings( WP_REST_Request $request ) {
		$s       = $this->session();
		$body    = $request->get_json_params() ?: [];
		$tenant  = Tenant_Repository::get( $s['tenant_id'] );
		$current = Privacy::uids( (array) Tenant_Repository::get_setting( $tenant ?? [], 'extra_users', [] ) );
		$patch   = [];
		$changed = [];
		foreach ( [ 'auto_approve_intune_apps', 'trust_microsoft_publisher' ] as $k ) {
			if ( array_key_exists( $k, $body ) ) {
				$patch[ $k ] = (bool) $body[ $k ];
				$changed[]   = $k;
			}
		}
		if ( $changed && ! self::has_role( $s['roles'], self::MANAGE_APPS_ROLES ) ) {
			return $this->forbid_change( 'Somente Global Administrator, Intune Administrator ou Security Administrator (atribuição ativa) podem alterar as regras de homologação.' );
		}
		$touches_access = ! empty( $body['extra_users_clear'] ) || isset( $body['extra_users_add'] ) || isset( $body['extra_users_remove'] ) || isset( $body['actions_scope'] );
		if ( isset( $body['actions_scope'] ) ) {
			if ( ! in_array( (string) $body['actions_scope'], Tenant_Repository::ACTION_SCOPES, true ) ) {
				return new WP_Error( 'j365_bad_request', 'Valor inválido para quem pode executar ações.', [ 'status' => 400 ] );
			}
			$patch['actions_scope'] = (string) $body['actions_scope'];
			$changed[]              = 'actions_scope:' . $patch['actions_scope'];
		}
		if ( $touches_access && ! self::has_role( $s['roles'], self::MANAGE_ACCESS_ROLES ) ) {
			return $this->forbid_change( 'Somente Global Administrator ou Privileged Role Administrator (atribuição ativa) podem liberar acesso a outros usuários ou mudar quem executa ações.' );
		}
		$clean = fn( $list ) => array_values( array_filter( array_map( fn( $u ) => strtolower( sanitize_email( (string) $u ) ), is_array( $list ) ? $list : [] ) ) );
		if ( ! empty( $body['extra_users_clear'] ) ) {
			$current   = [];
			$changed[] = 'extra_users_clear';
		}
		if ( isset( $body['extra_users_add'] ) ) {
			$current   = array_values( array_unique( array_merge( $current, Privacy::uids( $clean( $body['extra_users_add'] ) ) ) ) );
			$changed[] = 'extra_users_add';
		}
		if ( isset( $body['extra_users_remove'] ) ) {
			$current   = array_values( array_diff( $current, Privacy::uids( $clean( $body['extra_users_remove'] ) ) ) );
			$changed[] = 'extra_users_remove';
		}
		if ( array_intersect( [ 'extra_users_clear', 'extra_users_add', 'extra_users_remove' ], $changed ) ) {
			$patch['extra_users'] = array_slice( $current, 0, 200 );
		}
		if ( ! $patch ) {
			return new WP_Error( 'j365_bad_request', 'Nenhuma configuração válida informada.', [ 'status' => 400 ] );
		}
		Tenant_Repository::update_settings( $s['tenant_id'], $patch );
		Audit::log( 'tenant_settings', $s['tenant_id'], $s['upn'], [ 'changed' => $changed ] );
		return new WP_REST_Response( [ 'settings' => Tenant_Repository::public_settings( Tenant_Repository::get( $s['tenant_id'] ) ?? [] ) ] );
	}

	/** What the tenant approved vs what the enabled modules need (banner + locked reports in the UI). */
	private static function permission_status( string $tenant_id ): array {
		try {
			$c = Token_Service::consent_state( $tenant_id, false );
		} catch ( \Throwable $e ) {
			return [ 'state' => 'error', 'missing' => [], 'blocked' => [] ];
		}
		return [
			'state'      => $c['state'],
			'missing'    => 'error' === $c['state'] ? [] : $c['missing'],
			'blocked'    => 'error' === $c['state'] ? [] : Settings::reports_for_permissions( $c['missing'] ),
			// Per-tenant model: missing permissions are fixed by "Atualizar o aplicativo" (connect flow).
			'approveUrl' => Settings::per_tenant() ? Spa_Auth::connect_url( $tenant_id ) : add_query_arg( [ 't' => $tenant_id ], Request::route_url( 'consent/start' ) ),
			'mode'       => Settings::per_tenant() ? 'per_tenant' : 'multi',
		];
	}

	public function actions_status(): WP_REST_Response {
		return new WP_REST_Response( [ 'actions' => Action_Auth::status( $this->session() ) ] );
	}

	/**
	 * Starts an action job. Requires: actions module on, an allowed Entra role (checked live by the
	 * permission callback) and a delegated authorization from the popup (428 asks the browser for it).
	 */
	public function action_start( WP_REST_Request $request ) {
		$s   = $this->session();
		$cap = (string) $request['cap'];
		if ( ! Action_Auth::enabled( $cap ) ) {
			return new WP_Error( 'j365_action_disabled', 'As ações estão desativadas neste portal.', [ 'status' => 404 ] );
		}
		if ( 'off' === Action_Auth::mode( $s ) ) {
			return $this->forbid_change( 'As ações foram desativadas neste tenant por um administrador (Configurações → Ações).' );
		}
		if ( ! Action_Auth::allowed( $s, $cap ) ) {
			return $this->forbid_change( 'Esta ação requer uma destas funções (atribuição ativa): ' . implode( ', ', Action_Auth::role_names( $cap ) ) . '. Um Global Administrator pode liberar o uso com o RBAC do Intune/Exchange em Configurações → Ações.' );
		}
		// The delegated token must outlast the whole job (it is never refreshed in the background).
		$body = (array) ( $request->get_json_params() ?: [] );
		if ( 'intune' === $cap ) {
			$what  = Actions\Device_Sync::action_key( (string) ( $body['action'] ?? 'sync' ) );
			$count = min( Actions\Device_Sync::MAX, count( (array) ( $body['devices'] ?? [] ) ) );
			$need  = 90 + Actions\Device_Sync::estimate( $count );
		} else {
			$what  = Actions\Mailbox_Archive::op_key( (string) ( $body['op'] ?? 'enable' ) );
			$count = min( Actions\Mailbox_Archive::MAX, count( (array) ( $body['mailboxes'] ?? [] ) ) );
			$need  = 90 + Actions\Mailbox_Archive::estimate( $count, ! empty( $body['autoExpand'] ), ! array_key_exists( 'mfa', $body ) || ! empty( $body['mfa'] ), $what );
		}
		if ( '' === $what ) {
			return new WP_Error( 'j365_error', 'Ação desconhecida.', [ 'status' => 400 ] );
		}
		// Classic model: the stored token must outlast the job. Per-tenant model: the browser renews the
		// delegated token before every step, so only a valid token with the right scope is required.
		$need = Settings::per_tenant() ? 60 : min( 3000, $need );
		if ( Action_Token::remaining( $s, $cap ) < $need ) {
			return new WP_Error( 'j365_action_auth', 'Autorize a ação com a sua conta Microsoft.', [ 'status' => 428, 'need' => $need ] );
		}
		$type = 'intune' === $cap ? 'act_sync' : 'act_archive';
		try {
			$job = Jobs::start( $s, $type, $body, true );
		} catch ( \Throwable $e ) {
			return $this->error( $e );
		}
		Audit::log( 'action_started', $s['tenant_id'], $s['upn'], [ 'cap' => $cap, 'action' => $what, 'count' => $count, 'autoExpand' => ! empty( $body['autoExpand'] ) ] );
		return new WP_REST_Response( $job );
	}

	/**
	 * Shows (or conceals again) user, group and site names in this tenant's Microsoft 365 usage reports:
	 * PATCH /admin/reportSettings with the signed-in admin's delegated token (ReportSettings.ReadWrite.All).
	 * Tenant-wide privacy setting of the Microsoft 365 admin center — Microsoft audits the change too
	 * (UpdatedCFRPrivacySettings). Synchronous: one call, no job.
	 */
	public function report_names( WP_REST_Request $request ) {
		$body = (array) ( $request->get_json_params() ?: [] );
		// Explicit intent only: a missing or malformed value never reveals names by default.
		if ( ! isset( $body['conceal'] ) || ! is_bool( $body['conceal'] ) ) {
			return new WP_Error( 'j365_error', 'Parâmetro inválido.', [ 'status' => 400 ] );
		}
		$out = self::apply_report_names( $this->session(), $body['conceal'] );
		return $out instanceof WP_Error ? $out : new WP_REST_Response( $out );
	}

	/** @return array|WP_Error ['ok' => true, 'concealed' => bool] */
	public static function apply_report_names( array $s, bool $conceal ) {
		$cap = 'reports';
		if ( ! Action_Auth::enabled( $cap ) ) {
			return new WP_Error( 'j365_action_disabled', 'As ações estão desativadas neste portal.', [ 'status' => 404 ] );
		}
		if ( 'off' === Action_Auth::mode( $s ) ) {
			return new WP_Error( 'j365_forbidden', 'As ações foram desativadas neste tenant por um administrador (Configurações → Ações).', [ 'status' => 403 ] );
		}
		if ( ! Action_Auth::allowed( $s, $cap ) ) {
			return new WP_Error( 'j365_forbidden', 'Esta configuração do tenant só pode ser alterada por um Global Administrator (atribuição ativa).', [ 'status' => 403 ] );
		}
		$token = Action_Token::get( $s, $cap );
		if ( ! $token || Action_Token::remaining( $s, $cap ) < 30 ) {
			return new WP_Error( 'j365_action_auth', 'Autorize a ação com a sua conta Microsoft.', [ 'status' => 428, 'need' => 60 ] );
		}
		$graph = new Graph_Client( $s['tenant_id'], $token );
		try {
			$graph->patch( '/v1.0/admin/reportSettings', [ 'displayConcealedNames' => $conceal ] );
		} catch ( Graph_Exception $e ) {
			Audit::log( 'report_names_failed', $s['tenant_id'], $s['upn'], [ 'conceal' => $conceal, 'status' => $e->getCode() ] );
			if ( 'action_token_expired' === $e->graph_code() ) {
				return new WP_Error( 'j365_action_auth', 'Autorize a ação com a sua conta Microsoft.', [ 'status' => 428, 'need' => 60 ] );
			}
			$msg = 403 === $e->getCode() ? 'A Microsoft recusou a alteração: sua conta precisa da função Global Administrator ativa.' : $e->getMessage();
			return new WP_Error( 'j365_error', $msg, [ 'status' => 403 === $e->getCode() ? 424 : 502 ] );
		}
		// The change is done; reading it back only confirms it (a failed read is not a failed change).
		try {
			$now = $graph->get( '/v1.0/admin/reportSettings' );
		} catch ( \Throwable $e ) {
			$now = [];
		}
		$concealed = array_key_exists( 'displayConcealedNames', $now ) ? (bool) $now['displayConcealedNames'] : $conceal;
		Audit::log( $concealed ? 'report_names_concealed' : 'report_names_shown', $s['tenant_id'], $s['upn'] );
		return [ 'ok' => true, 'concealed' => $concealed ];
	}

	/** Nothing to clear on the server any more; kept for older front-ends. */
	public function cache_clear(): WP_REST_Response {
		return new WP_REST_Response( [ 'cleared' => true, 'serverStorage' => false ] );
	}

	public function audit( WP_REST_Request $request ) {
		$s = $this->session();
		$filters = [];
		foreach ( [ 'after', 'until', 'cursor' ] as $key ) {
			$value = $request->get_param( $key ) ?? '0';
			if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || strlen( (string) $value ) > ( 'cursor' === $key ? 18 : 10 ) ) {
				return new WP_Error( 'invalid_filter', 'Filtro de auditoria inválido.', [ 'status' => 400 ] );
			}
			$filters[ $key ] = (int) $value;
		}
		$filters['actor'] = $request->get_param( 'actor' ) ?? '';
		$filters['action'] = $request->get_param( 'action' ) ?? '';
		if ( ! in_array( $filters['actor'], [ '', 'you', 'other', 'system' ], true ) ||
			! is_string( $filters['action'] ) || ! preg_match( '/^[a-z0-9_]{0,60}$/D', $filters['action'] ) ||
			( $filters['until'] && $filters['after'] >= $filters['until'] ) ) {
			return new WP_Error( 'invalid_filter', 'Filtro de auditoria inválido.', [ 'status' => 400 ] );
		}
		$page = Audit::for_tenant( $s['tenant_id'], Privacy::uid( $s['upn'] ), $filters );
		return new WP_REST_Response( $page + [ 'retentionDays' => Audit::retention_days() ] );
	}
}
