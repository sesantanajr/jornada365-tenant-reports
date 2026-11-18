<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global configuration (WP admin → Jornada 365 Portal → Configurações).
 */
final class Settings {

	public const OPTION = 'j365tp_settings';

	/** Display name of the multi-tenant App Registration (shown on Microsoft's consent screen). */
	public const APP_NAME = 'Tenant Jornada365';

	/** New installations set their own brand source. */
	public const LOGO_SOURCE = '';

	/** Settings revision for one-off migrations. */
	public const REVISION = 10;
	private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * 1.2: every customer tenant gets its OWN app registration ("Tenant Jornada365", single-tenant,
	 * public client/SPA, delegated permissions only), created when its administrator connects the
	 * tenant. Nothing is registered in the publisher's tenant and the portal stores no credential.
	 */
	public static function per_tenant(): bool {
		return (bool) apply_filters( 'j365tp_per_tenant', true );
	}

	/** OpenID scopes always requested at sign-in (refresh token included: tokens live in the browser). */
	public const OIDC_SCOPES = [ 'openid', 'profile', 'email', 'offline_access' ];

	/**
	 * Delegated Microsoft Graph scopes of the per-tenant app: the read-only report permissions (same
	 * names as the application permissions of the classic model) + the Graph action scopes.
	 */
	public static function graph_scopes(): array {
		$actions = self::action_scopes();
		return array_values( array_unique( array_merge( [ 'User.Read' ], self::active_permissions(), (array) ( $actions['graph'] ?? [] ) ) ) );
	}

	/** Delegated Exchange Online scopes (mailbox actions; empty when the actions module is off). */
	public static function exchange_scopes(): array {
		$actions = self::action_scopes();
		return array_values( (array) ( $actions['exchange'] ?? [] ) );
	}

	/**
	 * Entra roles that may connect a tenant: create/update the app registration and the enterprise app
	 * AND grant its delegated permissions tenant-wide. (Privileged Role Administrator can grant consent
	 * but has no applications/create or applications/update, so it is not enough on its own.)
	 */
	public const CONNECT_ROLES = [
		'62e90394-69f5-4237-9190-012177145e10' => 'Global Administrator',
		'158c047a-c907-4556-b7ef-446551a6b5f7' => 'Cloud Application Administrator',
		'9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator',
	];

	/** Default Entra role templates allowed to use the portal (read-only reporting roles). */
	public const DEFAULT_ROLE_TEMPLATES = [
		'62e90394-69f5-4237-9190-012177145e10' => 'Global Administrator',
		'f2ef992c-3afb-46b9-b7cf-a126ee74c451' => 'Global Reader',
		'194ae4cb-b126-40b2-bd5b-6091b380977d' => 'Security Administrator',
		'5d6b6bb7-de71-4623-b4af-96380a352509' => 'Security Reader',
		'3a2c62db-5318-420d-8d74-23affee5d9d5' => 'Intune Administrator',
		'4a5d8f65-41da-4de4-8968-e035b65339cf' => 'Reports Reader',
		'7698a772-787b-4ac8-901f-60d6b08affd2' => 'Cloud Device Administrator',
		'b1be1c3e-b65d-4f19-8427-f6fa0d97feb9' => 'Conditional Access Administrator',
		'fe930be7-5e62-47db-91af-98c3a49a38b1' => 'User Administrator',
		'9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator',
		'29232cdf-9323-42fd-ade2-1d097af3e4de' => 'Exchange Administrator',
		'31392ffb-586c-42d1-9346-e59415a2cc4e' => 'Exchange Recipient Administrator',
	];

	/** Microsoft Graph application permissions always requested (core reports). */
	public const BASE_PERMISSIONS = [
		'Directory.Read.All',
		'AuditLog.Read.All',
		'Policy.Read.All',
		'DeviceManagementManagedDevices.Read.All',
		'DeviceManagementApps.Read.All',
	];

	/**
	 * Optional report modules. Each adds Graph permission(s) to the admin consent and
	 * enables extra report(s) in the portal. The operator toggles them in WP admin; the
	 * consent screen and the App Registration script request only what is enabled.
	 */
	public const MODULES = [
		'device_compliance' => [
			'label'       => 'Detalhes de conformidade do Intune',
			'permissions' => [ 'DeviceManagementConfiguration.Read.All' ],
			'reports'     => [ 'device_compliance' ],
			'note'        => 'Consulta sob demanda das políticas e configurações com falha em um dispositivo.',
		],
		'privileged' => [
			'label'       => 'Identidade privilegiada & risco',
			'permissions' => [ 'RoleManagement.Read.All', 'IdentityRiskyUser.Read.All', 'IdentityRiskEvent.Read.All' ],
			'reports'     => [ 'privileged', 'risk' ],
			'note'        => 'Funções permanentes vs elegíveis (PIM), admins sem MFA, usuários/sign-ins de risco (Identity Protection exige Entra ID P2).',
		],
		'appsec'     => [
			'label'       => 'Aplicativos, consentimentos & credenciais',
			'permissions' => [ 'Application.Read.All' ],
			'reports'     => [ 'appsec' ],
			'note'        => 'Enterprise apps, consentimentos OAuth de risco e segredos/certificados expirando.',
		],
		'securescore' => [
			'label'       => 'Secure Score & recomendações',
			'permissions' => [ 'SecurityEvents.Read.All' ],
			'reports'     => [ 'securescore' ],
			'note'        => 'Microsoft Secure Score, tendência e controles pendentes.',
		],
		'identityscore' => [
			'label'       => 'Identity Score (Microsoft Entra)',
			'permissions' => [ 'DirectoryRecommendations.Read.All' ],
			'reports'     => [ 'identityscore' ],
			'note'        => 'Histórico de pontuação e recomendações de identidade do Microsoft Entra (Microsoft Graph beta).',
		],
		'usage'      => [
			'label'       => 'Uso, caixas de correio e armazenamento (M365)',
			'permissions' => [ 'Reports.Read.All' ],
			'reports'     => [ 'usage', 'mailboxes', 'storage' ],
			'note'        => 'Adoção por workload, caixas de correio lotadas e arquivamento, OneDrive e SharePoint no limite.',
		],
		'actions'    => [
			'label'       => 'Ações administrativas (com a sua conta)',
			'permissions' => [],
			'delegated'   => [
				'graph'    => [ 'DeviceManagementManagedDevices.PrivilegedOperations.All', 'ReportSettings.ReadWrite.All' ],
				'exchange' => [ 'Exchange.Manage' ],
			],
			'reports'     => [ 'act_sync', 'act_archive' ],
			'note'        => 'Ações no Intune (sincronizar, reiniciar, verificação e atualização do Microsoft Defender), arquivamento de caixas (ativar, desativar, expansão automática, processar agora) e exibição dos nomes nos relatórios de uso. Executadas com a conta e o RBAC de quem clica — nunca pelo aplicativo sozinho.',
		],
	];

	/** Reports (or features) that stop working when an application permission is missing. */
	public const PERMISSION_REPORTS = [
		'DeviceManagementConfiguration.Read.All' => [ 'device_compliance' ],
		'Directory.Read.All'                      => [ 'overview', 'identity', 'groups', 'licensing', 'guests', 'domains' ],
		'AuditLog.Read.All'                       => [ 'inactive', 'mfa' ],
		'Policy.Read.All'                         => [ 'ca', 'authpolicies' ],
		'DeviceManagementManagedDevices.Read.All' => [ 'apps', 'app_devices' ],
		'DeviceManagementApps.Read.All'           => [ 'managed_apps', 'intune_assignments', 'app_assignments', 'app_failures' ],
		'RoleManagement.Read.All'                 => [ 'privileged' ],
		'IdentityRiskyUser.Read.All'              => [ 'risk' ],
		'IdentityRiskEvent.Read.All'              => [],
		'Application.Read.All'                    => [ 'appsec' ],
		'SecurityEvents.Read.All'                 => [ 'securescore' ],
		'DirectoryRecommendations.Read.All'       => [ 'identityscore' ],
		'Reports.Read.All'                        => [ 'usage', 'mailboxes', 'storage' ],
	];

	/** Microsoft resource apps used by delegated actions. */
	public const RESOURCE_APPS = [
		'graph'    => [ 'appId' => '00000003-0000-0000-c000-000000000000', 'audience' => 'https://graph.microsoft.com' ],
		'exchange' => [ 'appId' => '00000002-0000-0ff1-ce00-000000000000', 'audience' => 'https://outlook.office365.com' ],
	];

	/** @return string[] report keys affected by the given missing permissions */
	public static function reports_for_permissions( array $missing ): array {
		$out = [];
		foreach ( $missing as $p ) {
			$out = array_merge( $out, self::PERMISSION_REPORTS[ $p ] ?? [] );
		}
		return array_values( array_unique( $out ) );
	}

	/** Delegated scopes requested for actions (empty when the actions module is off), by resource. */
	public static function action_scopes(): array {
		return in_array( 'actions', self::enabled_modules(), true ) ? self::MODULES['actions']['delegated'] : [];
	}

	/** Reports available with the base permissions (always on). */
	public const BASE_REPORTS = [ 'overview', 'identity', 'mfa', 'devices', 'apps', 'app_devices', 'managed_apps', 'intune_assignments', 'app_assignments', 'app_failures', 'ca', 'groups', 'licensing', 'guests', 'domains', 'authpolicies' ];

	public static function enabled_modules(): array {
		$m = self::get( 'enabled_modules', array_keys( self::MODULES ) );
		if ( ! is_array( $m ) ) {
			$m = array_keys( self::MODULES );
		}
		return array_values( array_intersect( $m, array_keys( self::MODULES ) ) );
	}

	/** Permissions actually requested = base ∪ enabled modules. */
	public static function active_permissions(): array {
		$perms = self::BASE_PERMISSIONS;
		foreach ( self::enabled_modules() as $mod ) {
			$perms = array_merge( $perms, self::MODULES[ $mod ]['permissions'] );
		}
		return array_values( array_unique( $perms ) );
	}

	/** Report keys enabled = base ∪ enabled modules. */
	public static function active_reports(): array {
		$reports = self::BASE_REPORTS;
		foreach ( self::enabled_modules() as $mod ) {
			$reports = array_merge( $reports, self::MODULES[ $mod ]['reports'] );
		}
		return array_values( array_unique( $reports ) );
	}

	/** Backwards-compatible alias used by older code paths. */
	public const GRAPH_APP_PERMISSIONS = self::BASE_PERMISSIONS;

	/** Why each permission is requested (shown on the public sign-in screen and consent pages). */
	public const PERMISSION_WHY = [
		'DeviceManagementConfiguration.Read.All' => 'Estados de políticas e configurações de conformidade do Intune',
		'Directory.Read.All'                      => 'Usuários, grupos, dispositivos, licenças, domínios, funções e consentimentos do diretório',
		'AuditLog.Read.All'                       => 'Último login das contas (inatividade) e registro de métodos de MFA',
		'Policy.Read.All'                         => 'Acesso Condicional, Security Defaults e políticas de autenticação',
		'DeviceManagementManagedDevices.Read.All' => 'Dispositivos do Intune, conformidade e inventário de aplicativos instalados',
		'DeviceManagementApps.Read.All'           => 'Catálogo, atribuições e falhas de instalação de aplicativos do Intune',
		'RoleManagement.Read.All'                 => 'Atribuições de funções administrativas e PIM',
		'IdentityRiskyUser.Read.All'              => 'Usuários de risco (Identity Protection)',
		'IdentityRiskEvent.Read.All'              => 'Detecções de risco (Identity Protection)',
		'Application.Read.All'                    => 'Aplicativos, service principals e validade de segredos/certificados',
		'SecurityEvents.Read.All'                 => 'Microsoft Secure Score e controles de segurança',
		'DirectoryRecommendations.Read.All'       => 'Ler a pontuação e as recomendações de identidade do tenant no Microsoft Entra',
		'Reports.Read.All'                        => 'Relatórios de uso e adoção, caixas de correio, OneDrive e SharePoint (tamanho e cotas)',
		'DeviceManagementManagedDevices.PrivilegedOperations.All' => 'Delegada: ações remotas do Intune (sincronizar, reiniciar, verificação e atualização do Microsoft Defender), executadas com a sua conta e o seu RBAC do Intune',
		'Exchange.Manage'                         => 'Delegada: ativar ou desativar o arquivo morto, expansão automática e processamento de caixas (Exchange Online), com a sua conta e o seu RBAC do Exchange',
		'ReportSettings.ReadWrite.All'            => 'Delegada: exibir ou voltar a ocultar os nomes de usuários nos relatórios de uso do Microsoft 365 (configuração do tenant), com a sua conta',
	];

	/**
	 * Active permissions grouped by module, each with its justification.
	 *
	 * @return array<int, array{label:string, note:string, permissions: array<int, array{name:string, why:string}>}>
	 */
	public static function permission_groups(): array {
		$map    = fn( array $perms ) => array_map( fn( $p ) => [ 'name' => $p, 'why' => self::PERMISSION_WHY[ $p ] ?? '' ], $perms );
		$groups = [
			[
				'label'       => 'Relatórios principais',
				'note'        => 'Identidade, MFA, dispositivos, aplicativos, Acesso Condicional, grupos, licenças, convidados, domínios e políticas.',
				'permissions' => $map( self::BASE_PERMISSIONS ),
			],
		];
		foreach ( self::enabled_modules() as $mod ) {
			$perms = self::MODULES[ $mod ]['permissions'];
			foreach ( (array) ( self::MODULES[ $mod ]['delegated'] ?? [] ) as $scopes ) {
				$perms = array_merge( $perms, $scopes );
			}
			$groups[] = [
				'label'       => self::MODULES[ $mod ]['label'],
				'note'        => self::MODULES[ $mod ]['note'],
				'delegated'   => ! empty( self::MODULES[ $mod ]['delegated'] ),
				'permissions' => $map( $perms ),
			];
		}
		return $groups;
	}

	public static function defaults(): array {
		return [
			'client_id'            => '',
			'client_secret_enc'    => '',
			'home_tenant_id'       => '',
			'portal_page_id'       => 0,
			'allowed_roles'        => array_keys( self::DEFAULT_ROLE_TEMPLATES ),
			'enabled_modules'      => array_keys( self::MODULES ),
			'allow_consenter'      => 1,
			'session_ttl_hours'    => 8,
			'session_idle_minutes' => 60,
			'audit_days'           => 30,
			'inactive_days'        => 90,
			'stale_device_days'    => 90,
			'trust_cloudflare'     => 1,
			'allowed_tenants'      => '',
			'company_tenant_id'    => '',
			'company_login_label'  => '',
			'brand_name'           => self::APP_NAME,
			'support_email'        => '',
			'privacy_url'          => '',
			'terms_url'            => '',
			'logo_source_url'      => self::LOGO_SOURCE,
			'icon_style'           => 'badge',
		];
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public const CREATOR_NAME     = "Sérgio Sant'Ana Júnior";
	public const CREATOR_LINKEDIN = 'https://www.linkedin.com/in/sergiosantanacloud/';

	public static function client_id(): string {
		return (string) self::get( 'client_id', '' );
	}

	public static function client_secret(): string {
		return Crypto::decrypt( (string) self::get( 'client_secret_enc', '' ) );
	}

	public static function is_configured(): bool {
		// Per-tenant model: there is nothing to configure in the publisher tenant — the portal is ready.
		if ( self::per_tenant() ) {
			return true;
		}
		return self::client_id() !== '' && ( Client_Credential::has_active() || self::client_secret() !== '' );
	}

	public static function brand_name(): string {
		$b = trim( (string) self::get( 'brand_name', '' ) );
		return '' !== $b ? $b : self::APP_NAME;
	}

	/** @return string[] role template IDs */
	public static function allowed_roles(): array {
		$roles = self::get( 'allowed_roles', [] );
		return is_array( $roles ) ? array_values( array_filter( array_map( 'strtolower', $roles ) ) ) : [];
	}

	/** @return string[] tenant IDs; empty = any tenant may connect */
	public static function allowed_tenants(): array {
		$company = self::company_tenant_id();
		if ( '' !== $company ) {
			return [ $company ];
		}
		$raw = (string) self::get( 'allowed_tenants', '' );
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', strtolower( $raw ) ) ) ) );
	}

	public static function company_tenant_id(): string {
		return self::per_tenant() ? (string) self::get( 'company_tenant_id', '' ) : '';
	}

	public static function tenant_allowed( string $tenant_id ): bool {
		$allowed = self::allowed_tenants();
		return ! $allowed || in_array( strtolower( $tenant_id ), $allowed, true );
	}

	/**
	 * Fingerprint of what the app registration must contain (application permissions of the enabled
	 * modules + delegated action scopes). Stored by the bootstrap when it updates the app; a mismatch
	 * means the operator must run "Atualizar o aplicativo" (new version or modules changed).
	 */
	public static function app_signature(): string {
		return substr( hash( 'sha256', (string) wp_json_encode( [ self::active_permissions(), self::action_scopes() ] ) ), 0, 16 );
	}

	public static function app_outdated(): bool {
		if ( self::per_tenant() ) {
			return false; // each tenant's app is checked against the scopes in its own tokens
		}
		return self::is_configured() && (string) self::get( 'app_signature', '' ) !== self::app_signature();
	}

	public static function update( array $values ): void {
		$current = self::all();
		update_option( self::OPTION, array_merge( $current, $values ), 'no' );
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings(): void {
		register_setting(
			'j365tp',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'show_in_rest'      => false,
			]
		);
	}

	public function sanitize( $input ): array {
		$current = self::all();
		$input   = is_array( $input ) ? $input : [];

		$out                   = $current;
		// Per-tenant model: these fields are not on the form; keep what is stored (the Assistente can forget it).
		$out['client_id']      = array_key_exists( 'client_id', $input ) ? $this->sanitize_guid( $input['client_id'] ) : (string) ( $current['client_id'] ?? '' );
		$out['home_tenant_id'] = array_key_exists( 'home_tenant_id', $input ) ? $this->sanitize_guid( $input['home_tenant_id'] ) : (string) ( $current['home_tenant_id'] ?? '' );

		$secret = trim( (string) ( $input['client_secret'] ?? '' ) );
		if ( '' !== $secret ) {
			try {
				$out['client_secret_enc'] = Crypto::encrypt( $secret );
			} catch ( \Throwable $e ) {
				add_settings_error( 'j365tp', 'crypto', $e->getMessage() );
			}
		}

		$out['portal_page_id']       = absint( $input['portal_page_id'] ?? 0 );
		$out['allow_consenter']      = empty( $input['allow_consenter'] ) ? 0 : 1;
		$out['trust_cloudflare']     = empty( $input['trust_cloudflare'] ) ? 0 : 1;
		$out['session_ttl_hours']    = max( 1, min( 24, absint( $input['session_ttl_hours'] ?? 8 ) ) );
		$out['session_idle_minutes'] = max( 10, min( 480, absint( $input['session_idle_minutes'] ?? 60 ) ) );
		$out['audit_days']           = max( 1, min( 365, absint( $input['audit_days'] ?? ( $current['audit_days'] ?? 30 ) ) ) );
		$out['inactive_days']        = max( 1, min( 730, absint( $input['inactive_days'] ?? 90 ) ) );
		$out['stale_device_days']    = max( 1, min( 730, absint( $input['stale_device_days'] ?? 90 ) ) );
		$out['brand_name']           = sanitize_text_field( $input['brand_name'] ?? $current['brand_name'] );
		$out['support_email']        = sanitize_email( $input['support_email'] ?? '' );
		$out['privacy_url']          = esc_url_raw( $input['privacy_url'] ?? '' );
		$out['terms_url']            = esc_url_raw( $input['terms_url'] ?? ( $current['terms_url'] ?? '' ) );
		$out['logo_source_url']      = esc_url_raw( $input['logo_source_url'] ?? ( $current['logo_source_url'] ?? self::LOGO_SOURCE ), [ 'https' ] );
		$out['icon_style']           = in_array( $input['icon_style'] ?? '', [ 'badge', 'full' ], true ) ? $input['icon_style'] : ( $current['icon_style'] ?? 'badge' );

		$tenants                = (string) ( $input['allowed_tenants'] ?? '' );
		$valid                  = array_filter( preg_split( '/[\s,;]+/', $tenants ), fn( $t ) => '' !== $this->sanitize_guid( $t ) );
		$out['allowed_tenants'] = implode( "\n", array_map( 'strtolower', array_map( 'trim', $valid ) ) );

		if ( array_key_exists( 'company_tenant_id', $input ) ) {
			$company_raw = $input['company_tenant_id'];
			$company     = is_string( $company_raw ) ? $this->sanitize_guid( $company_raw ) : '';
			if ( is_string( $company_raw ) && '' === trim( $company_raw ) ) {
				$out['company_tenant_id'] = '';
			} elseif ( '' !== $company && ! in_array( $company, [ '9188040d-6c67-4c5b-b112-36a304b66dad', '00000000-0000-0000-0000-000000000000' ], true ) ) {
				$out['company_tenant_id'] = $company;
			} else {
				add_settings_error( 'j365tp', 'company_tenant_id', 'O ID de diretório precisa ser um GUID organizacional do Microsoft Entra. O valor anterior foi mantido.' );
			}
		}
		if ( array_key_exists( 'company_login_label', $input ) ) {
			$out['company_login_label'] = mb_substr( sanitize_text_field( $input['company_login_label'] ), 0, 60 );
		}

		$roles = $input['allowed_roles'] ?? [];
		$roles = is_array( $roles ) ? array_filter( array_map( [ $this, 'sanitize_guid' ], $roles ) ) : [];
		// Custom (free text) roles appended one per line.
		if ( ! empty( $input['allowed_roles_extra'] ) ) {
			foreach ( preg_split( '/[\s,;]+/', (string) $input['allowed_roles_extra'] ) as $extra ) {
				$g = $this->sanitize_guid( $extra );
				if ( '' !== $g ) {
					$roles[] = $g;
				}
			}
		}
		$out['allowed_roles'] = array_values( array_unique( array_map( 'strtolower', $roles ) ) );

		$modules                 = $input['enabled_modules'] ?? [];
		$modules                 = is_array( $modules ) ? $modules : [];
		$out['enabled_modules']  = array_values( array_intersect( $modules, array_keys( self::MODULES ) ) );

		return $out;
	}

	private function sanitize_guid( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( self::GUID, $value ) ? $value : '';
	}
}
