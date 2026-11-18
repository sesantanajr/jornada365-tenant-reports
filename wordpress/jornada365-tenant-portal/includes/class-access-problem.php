<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why someone cannot use the portal, in plain language: what was verified, what is missing,
 * who can fix it and how, plus technical details for the support team.
 *
 * build() returns a view model rendered by Router::problem_html(). All texts are Portuguese
 * (source language) and translated by I18n at render time; {placeholders} are filled there.
 */
final class Access_Problem {

	/** Built-in Entra role templates (names are Microsoft's, never translated). */
	public const ROLE_NAMES = [
		'62e90394-69f5-4237-9190-012177145e10' => 'Global Administrator',
		'e8611ab8-c189-46e8-94e1-60213ab1f814' => 'Privileged Role Administrator',
		'f2ef992c-3afb-46b9-b7cf-a126ee74c451' => 'Global Reader',
		'194ae4cb-b126-40b2-bd5b-6091b380977d' => 'Security Administrator',
		'5d6b6bb7-de71-4623-b4af-96380a352509' => 'Security Reader',
		'3a2c62db-5318-420d-8d74-23affee5d9d5' => 'Intune Administrator',
		'4a5d8f65-41da-4de4-8968-e035b65339cf' => 'Reports Reader',
		'7698a772-787b-4ac8-901f-60d6b08affd2' => 'Cloud Device Administrator',
		'b1be1c3e-b65d-4f19-8427-f6fa0d97feb9' => 'Conditional Access Administrator',
		'fe930be7-5e62-47db-91af-98c3a49a38b1' => 'User Administrator',
		'9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator',
		'158c047a-c907-4556-b7ef-446551a6b5f7' => 'Cloud Application Administrator',
		'29232cdf-9323-42fd-ade2-1d097af3e4de' => 'Exchange Administrator',
		'31392ffb-586c-42d1-9346-e59415a2cc4e' => 'Exchange Recipient Administrator',
		'f28a1f50-f6e7-4571-818b-6a12f2af6b6c' => 'SharePoint Administrator',
		'69091246-20e8-4a56-aa4d-066075b2a7a8' => 'Teams Administrator',
		'729827e3-9c14-49f7-bb1b-9608f156bbb8' => 'Helpdesk Administrator',
		'c4e39bd9-1100-46d3-8c65-fb160da0071f' => 'Authentication Administrator',
		'7be44c8a-adaf-4e2a-84d6-ab2649e08a13' => 'Privileged Authentication Administrator',
		'b0f54661-2d74-4c50-afa3-1ec803f12efe' => 'Billing Administrator',
		'4d6ac14f-3453-41d0-bef9-a3e0c569773a' => 'License Administrator',
		'17315797-102d-40b4-93e0-432062caca18' => 'Compliance Administrator',
		'fdd7a751-b60b-444a-984c-02652fe8fa1c' => 'Groups Administrator',
		'88d8e3e3-8f55-4a1e-953a-9b9898b8876b' => 'Directory Readers',
		'f023fd81-a637-4b56-95fd-791ac0226033' => 'Service Support Administrator',
	];
	/** Default role templates every account carries in "wids" (not administrative roles). */
	private const BASE_ROLES = [
		'b79fbf4d-3ef9-4689-8143-76b194e85509', // User
		'10dae51f-b6af-4016-8d66-8c2a99b929b3', // Guest User
		'2af84b1e-32c8-42b7-82bc-daa82404023b', // Restricted Guest User
		'd29b2b05-8046-44ba-8758-1e26182fcf32', // Directory Synchronization Accounts
	];
	/** Who can grant Microsoft Graph application permissions (admin consent). */
	private const CONSENT_WHO = [ 'Global Administrator', 'Privileged Role Administrator' ];
	/** Who can change enterprise application properties and assignments. */
	private const APP_ADMIN_WHO = [ 'Global Administrator', 'Cloud Application Administrator', 'Application Administrator' ];

	public const PIM_URL    = 'https://entra.microsoft.com/#view/Microsoft_Azure_PIMCommon/ActivationMenuBlade/~/aadmigratedroles';
	public const STATUS_URL = 'https://status.cloud.microsoft';

	/** Short, readable incident id for support (also written to the audit log). */
	public static function incident(): string {
		return strtoupper( substr( bin2hex( random_bytes( 5 ) ), 0, 8 ) );
	}

	/** Administrative roles from a "wids" claim (null when the claim is absent). */
	public static function roles_from_wids( $wids ): ?array {
		if ( ! is_array( $wids ) ) {
			return null;
		}
		$out = [];
		foreach ( $wids as $id ) {
			$id = strtolower( (string) $id );
			if ( '' === $id || in_array( $id, self::BASE_ROLES, true ) ) {
				continue;
			}
			$out[] = [ 'id' => $id, 'name' => self::ROLE_NAMES[ $id ] ?? Settings::DEFAULT_ROLE_TEMPLATES[ $id ] ?? $id ];
		}
		return $out;
	}

	/** Names of the roles accepted by the portal (operator setting). */
	public static function accepted_role_names(): array {
		return array_values( array_map( static fn( $t ) => self::ROLE_NAMES[ $t ] ?? Settings::DEFAULT_ROLE_TEMPLATES[ $t ] ?? $t, Settings::allowed_roles() ) );
	}

	/** The tenant's domain as the user knows it (UPN suffix), or the tenant id. */
	public static function domain_of( string $upn, string $tenant_id ): string {
		$at = strrpos( $upn, '@' );
		return false !== $at ? substr( $upn, $at + 1 ) : $tenant_id;
	}

	/**
	 * @param string $code problem code (see the switch below)
	 * @param array  $ctx  upn, name, tenant_id, wids, roles, consent (Token_Service::consent_state), ms_code, error, endpoint, incident
	 */
	public static function build( string $code, array $ctx ): array {
		$upn       = (string) ( $ctx['upn'] ?? '' );
		$tenant_id = (string) ( $ctx['tenant_id'] ?? '' );
		$domain    = (string) ( $ctx['domain'] ?? ( '' !== $upn ? self::domain_of( $upn, $tenant_id ) : $tenant_id ) );
		$consent   = (array) ( $ctx['consent'] ?? [] );
		$required  = (array) ( $consent['required'] ?? Settings::active_permissions() );
		$granted   = (array) ( $consent['granted'] ?? [] );
		$missing   = (array) ( $consent['missing'] ?? [] );
		$roles     = isset( $ctx['roles'] ) && is_array( $ctx['roles'] ) ? $ctx['roles'] : self::roles_from_wids( $ctx['wids'] ?? null );
		$accepted  = self::accepted_role_names();
		$per       = Settings::per_tenant();
		// Per-tenant model: what an administrator opens is the portal's "connect / update your tenant" link.
		$share     = $per ? Spa_Auth::connect_url( $tenant_id ) : ( '' !== $tenant_id ? add_query_arg( [ 't' => $tenant_id ], Request::route_url( 'consent/start' ) ) : '' );
		$vars      = [ 'app' => Settings::APP_NAME, 'domain' => $domain, 'upn' => $upn, 'n' => (string) count( $required ), 'a' => (string) count( array_intersect( $required, $granted ) ), 'b' => (string) count( $required ) ];

		$vm = [
			'code'    => $code,
			'tone'    => 'error',
			'icon'    => 'alert',
			'eyebrow' => 'Acesso ainda não liberado',
			'title'   => '',
			'summary' => '',
			'vars'    => $vars,
			'checks'  => [],
			'why'     => '',
			'steps'   => [],
			'who'     => [],
			'chips'   => [],
			'chipsLabel' => '',
			'perms'   => false,
			'missing' => $missing,
			'actions' => [],
			'retry'   => 0,
			'tech'    => [],
		];

		// ---- reusable checklist rows
		$account = $upn
			? [ 'state' => 'ok', 'label' => 'Conta verificada pela Microsoft', 'detail' => '{upn} · {domain}' ]
			: null;
		$role_row = static function ( bool $after_consent ) use ( $roles, $accepted ): array {
			if ( null === $roles ) {
				return [ 'state' => 'unknown', 'label' => 'Função administrativa da sua conta', 'detail' => 'Será verificada no próximo login.' ];
			}
			$names = array_column( $roles, 'name' );
			$ok    = array_intersect( $names, $accepted );
			if ( $ok ) {
				return [ 'state' => 'ok', 'label' => 'Função administrativa da sua conta', 'detail' => 'Ativa:', 'list' => array_values( $ok ) ];
			}
			return [
				'state'  => $after_consent ? 'warn' : 'fail',
				'label'  => 'Função administrativa da sua conta',
				'detail' => $after_consent
					? ( $names ? 'Depois da aprovação, você ainda vai precisar de uma função aceita pelo portal (ou ser liberado por um administrador). Funções ativas:' : 'Nenhuma função administrativa ativa. Depois da aprovação, você ainda vai precisar de uma função aceita pelo portal — ou ser liberado por um administrador.' )
					: ( $names ? 'Nenhuma função aceita pelo portal está ativa. Funções ativas:' : 'Nenhuma função administrativa ativa nesta conta.' ),
				'list'   => $names,
			];
		};
		$copy_link = $share ? [ 'kind' => 'copy', 'label' => $per ? 'Copiar link para o administrador' : 'Copiar link de aprovação', 'value' => $share, 'id' => 'j365-share' ] : null;
		$mail      = $share ? ( $per
			? [ 'kind' => 'mailto', 'label' => 'Enviar por e-mail', 'subject' => 'Conectar o tenant {domain} ao {app}', 'body' => "Olá! Para usar o {app} no tenant {domain}, precisamos que um Global Administrator, Cloud Application Administrator ou Application Administrator conecte a empresa pelo link abaixo. O portal registra o aplicativo no nosso diretório. Revise as permissões solicitadas para os relatórios e para os módulos de ação habilitados.\n\n{link}\n\nObrigado!", 'link' => $share ]
			: [ 'kind' => 'mailto', 'label' => 'Enviar por e-mail', 'subject' => 'Aprovação do {app} no tenant {domain}', 'body' => "Olá! Para usar o {app} (relatórios do Microsoft 365, Entra ID e Intune) no tenant {domain}, preciso que um Global Administrator ou Privileged Role Administrator aprove o aplicativo:\n\n{link}\n\nNa tela da Microsoft, revise as permissões e clique em Aceitar. Obrigado!", 'link' => $share ] ) : null;
		$other     = [ 'kind' => 'link', 'label' => 'Entrar com outra conta', 'href' => Request::login_url(), 'style' => 'ghost' ];
		$retry     = [ 'kind' => 'link', 'label' => 'Tentar novamente', 'href' => Request::login_url( '' !== $tenant_id ? [ 't' => $tenant_id ] : [] ), 'style' => 'solid' ];
		$home      = [ 'kind' => 'link', 'label' => 'Voltar ao início', 'href' => Request::portal_url(), 'style' => 'ghost' ];

		switch ( $code ) {
			case 'consent_pending':
			case 'consent_missing':
			case 'consent_removed':
			case 'consent_incomplete':
				$vm['tone']    = 'warn';
				$vm['icon']    = 'shield';
				$vm['eyebrow'] = 'Falta 1 etapa';
				$vm['title']   = [
					'consent_pending'    => 'Falta a aprovação de um administrador',
					'consent_missing'    => 'O {app} ainda não foi aprovado no seu tenant',
					'consent_removed'    => 'O {app} foi removido do seu tenant',
					'consent_incomplete' => 'A aprovação do {app} está incompleta',
				][ $code ];
				$vm['summary'] = [
					'consent_pending'    => 'Você entrou com sucesso, mas nenhum administrador aprovou as permissões de leitura do {app} no tenant {domain}. Sem essa aprovação o portal não consegue ler dados — nem confirmar a sua função.',
					'consent_missing'    => 'Você entrou com sucesso. Para o portal ler os relatórios do tenant {domain}, um administrador precisa aprovar o aplicativo uma única vez.',
					'consent_removed'    => 'O aplicativo empresarial foi excluído ou a aprovação foi revogada no tenant {domain}. Um administrador precisa aprová-lo novamente.',
					'consent_incomplete' => 'O {app} está no tenant {domain}, mas faltam permissões que o portal precisa para o login. Um administrador precisa aprovar novamente.',
				][ $code ];
				$vm['checks']  = array_values( array_filter( [
					$account,
					[
						'state'  => 'fail',
						'label'  => 'Aprovação do aplicativo no tenant',
						'detail' => [
							'consent_pending'    => 'O aplicativo está no tenant. Foram aprovadas 0 de {n} permissões de leitura dos relatórios.',
							'consent_missing'    => 'O aplicativo ainda não foi adicionado ao tenant.',
							'consent_removed'    => 'O aplicativo não está mais no tenant (excluído ou aprovação revogada).',
							'consent_incomplete' => '{a} de {b} permissões aprovadas. Faltam:',
						][ $code ],
						'list'   => 'consent_incomplete' === $code ? $missing : [],
					],
					$role_row( true ),
				] ) );
				$vm['why']   = [
					'consent_pending'    => 'Isso acontece quando o aplicativo entra no tenant por um login comum (consentimento de usuário): ele permite apenas entrar. As permissões de leitura do portal são permissões de aplicativo e só um administrador pode aprová-las.',
					'consent_missing'    => '',
					'consent_removed'    => '',
					'consent_incomplete' => 'Acontece quando permissões do aplicativo são removidas no Entra ID ou quando uma aprovação antiga não incluiu permissões adicionadas depois.',
				][ $code ];
				$vm['steps'] = [
					'Envie o link de aprovação para um Global Administrator ou Privileged Role Administrator do tenant.',
					'O administrador abre o link, entra na Microsoft e revisa as permissões antes de conceder a aprovação.',
					'Volte aqui e entre novamente — o acesso é liberado na hora.',
				];
				$vm['who']     = self::CONSENT_WHO;
				$vm['perms']   = true;
				$vm['actions'] = array_values( array_filter( [ $copy_link, $mail, $other, $home ] ) );
				if ( $per ) {
					$vm['why']   = 'O aplicativo do portal no seu tenant não tem todas as permissões delegadas desta versão (por exemplo, depois de uma atualização do portal ou de uma remoção no Entra ID).';
					$vm['steps'] = [
						'Envie o link para um administrador do tenant (Global Administrator, Cloud Application Administrator ou Application Administrator).',
						'O administrador abre o link, clica em Conectar e entra com a conta dele: o portal atualiza o aplicativo Tenant Jornada365 no próprio tenant.',
						'Volte aqui e entre novamente.',
					];
					$vm['who']   = array_values( Settings::CONNECT_ROLES );
				}
				break;

			case 'tenant_not_connected':
				$vm['tone']    = 'warn';
				$vm['icon']    = 'shield';
				$vm['eyebrow'] = 'Falta 1 etapa';
				$vm['title']   = 'O tenant {domain} ainda não está conectado ao portal';
				$vm['summary'] = 'Você entrou com sucesso, mas o aplicativo do portal ainda não existe no seu tenant. Um administrador conecta o tenant uma única vez, direto pelo portal — sem script.';
				$vm['checks']  = array_values( array_filter( [ $account, [ 'state' => 'fail', 'label' => 'Aplicativo do portal no seu tenant', 'detail' => 'Ainda não criado.' ], $role_row( true ) ] ) );
				$vm['steps']   = [
					'Um administrador abre o portal, clica em Entrar com Microsoft 365 e depois em Conectar o tenant.',
					'O administrador entra no portal e aprova as permissões dos módulos habilitados para registrar o aplicativo Tenant Jornada365 no diretório da empresa.',
					'Volte aqui e entre novamente.',
				];
				$vm['who']     = array_values( Settings::CONNECT_ROLES );
				$vm['perms']   = true;
				$vm['actions'] = array_values( array_filter( [ $copy_link, $mail, $home ] ) );
				break;

			case 'app_mismatch':
			case 'app_foreign':
				$vm['icon']    = 'lock';
				$vm['title']   = 'app_mismatch' === $code ? 'Este não é o aplicativo do portal no seu tenant' : 'O aplicativo informado não pertence ao seu tenant';
				$vm['summary'] = 'app_mismatch' === $code
					? 'O login usou um aplicativo diferente do que está conectado ao portal para o tenant {domain}. Entre pelo botão do portal; se o aplicativo mudou, um administrador reconecta o tenant.'
					: 'O ID de aplicativo usado no login não existe no tenant {domain}. Use a conexão automática ou crie o aplicativo no seu próprio tenant.';
				$vm['checks']  = array_values( array_filter( [ $account, [ 'state' => 'fail', 'label' => 'Aplicativo do portal no seu tenant', 'detail' => 'app_mismatch' === $code ? 'Diferente do aplicativo conectado.' : 'Não encontrado neste tenant.' ] ] ) );
				$vm['who']     = array_values( Settings::CONNECT_ROLES );
				$vm['actions'] = array_values( array_filter( [ $retry, $copy_link, $home ] ) );
				break;

			case 'app_missing':
				$vm['tone']    = 'warn';
				$vm['icon']    = 'clock';
				$vm['title']   = 'O aplicativo do portal não foi encontrado no seu tenant';
				$vm['summary'] = 'O aplicativo Tenant Jornada365 foi excluído do tenant {domain} ou ainda está sendo publicado pela Microsoft (até 2 minutos depois da conexão).';
				$vm['steps']   = [
					'Se o tenant acabou de ser conectado, aguarde 1–2 minutos e tente novamente.',
					'Se o aplicativo foi excluído no Entra ID, um administrador conecta o tenant de novo pelo portal.',
				];
				$vm['who']     = array_values( Settings::CONNECT_ROLES );
				$vm['actions'] = array_values( array_filter( [ $retry, $copy_link, $home ] ) );
				break;

			case 'consent_propagating':
				$vm['tone']    = 'info';
				$vm['icon']    = 'clock';
				$vm['eyebrow'] = 'Quase lá';
				$vm['title']   = 'Aprovação recebida — a Microsoft está propagando';
				$vm['summary'] = 'As permissões do {app} foram aprovadas no tenant {domain} há instantes. A Microsoft pode levar alguns minutos para liberá-las; vamos tentar de novo automaticamente.';
				$vm['checks']  = array_values( array_filter( [ $account, [ 'state' => 'pending', 'label' => 'Aprovação do aplicativo no tenant', 'detail' => 'Aprovação recebida; aguardando a propagação na Microsoft ({a} de {b} permissões já visíveis).' ], $role_row( true ) ] ) );
				$vm['retry']   = 30;
				$vm['actions'] = [ $retry, $home ];
				break;

			case 'app_disabled':
				$vm['icon']    = 'lock';
				$vm['title']   = 'O {app} está desabilitado no seu tenant';
				$vm['summary'] = 'O aplicativo empresarial existe no tenant {domain}, mas está configurado para não permitir login.';
				$vm['checks']  = array_values( array_filter( [ $account, [ 'state' => 'fail', 'label' => 'Aplicativo habilitado para login', 'detail' => '"Habilitado para os usuários entrarem?" está como Não.' ] ] ) );
				$vm['steps']   = [ 'Entra ID → Aplicativos empresariais → {app} → Propriedades.', 'Em "Habilitado para os usuários entrarem?", selecione Sim e salve.', 'Entre novamente no portal.' ];
				$vm['who']     = self::APP_ADMIN_WHO;
				$vm['actions'] = [ $retry, $other, $home ];
				break;

			case 'role_missing':
				$vm['icon']       = 'user';
				$vm['eyebrow']    = 'Acesso negado';
				$vm['title']      = 'Sua conta não tem uma função autorizada';
				$vm['summary']    = 'O {app} está aprovado no tenant {domain}, mas o portal só abre para contas com uma função administrativa ativa (ou liberadas por um administrador).';
				$vm['checks']     = array_values( array_filter( [ $account, [ 'state' => 'ok', 'label' => 'Aprovação do aplicativo no tenant', 'detail' => 'Aprovado.' ], $role_row( false ) ] ) );
				$vm['steps']      = [
					'Se a sua função é elegível no PIM, ative-a e entre novamente (a ativação pode levar alguns minutos).',
					'Ou peça a um administrador uma das funções aceitas abaixo (atribuição ativa).',
					'Ou peça a um Global Administrator ou Privileged Role Administrator para liberar o seu usuário no portal: Configurações → Acesso adicional.',
				];
				$vm['chips']      = $accepted;
				$vm['chipsLabel'] = 'Funções aceitas pelo portal';
				$vm['who']        = self::CONSENT_WHO;
				$vm['actions']    = [ [ 'kind' => 'link', 'label' => 'Abrir o PIM (minhas funções)', 'href' => self::PIM_URL, 'style' => 'solid', 'target' => '_blank' ], $other, $home ];
				break;

			case 'account_disabled':
			case 'account_missing':
				$vm['icon']    = 'user';
				$vm['eyebrow'] = 'Acesso negado';
				$vm['title']   = 'account_disabled' === $code ? 'Sua conta está desabilitada no Entra ID' : 'Sua conta não foi encontrada no tenant';
				$vm['summary'] = 'account_disabled' === $code ? 'Contas desabilitadas não podem usar o portal, mesmo com uma função administrativa.' : 'A conta usada no login não existe mais no tenant {domain}.';
				$vm['checks']  = array_values( array_filter( [ $account, [ 'state' => 'fail', 'label' => 'Conta ativa no Entra ID', 'detail' => 'account_disabled' === $code ? 'Conta desabilitada.' : 'Conta não encontrada.' ] ] ) );
				$vm['steps']   = [ 'Fale com o administrador de identidades do seu tenant.' ];
				$vm['who']     = [ 'Global Administrator', 'User Administrator' ];
				$vm['actions'] = [ $other, $home ];
				break;

			case 'assignment_required':
				$vm['icon']    = 'user';
				$vm['title']   = 'Seu usuário não está atribuído ao {app}';
				$vm['summary'] = 'No tenant {domain}, o aplicativo exige atribuição (Atribuição obrigatória = Sim) e a sua conta não está atribuída.';
				$vm['checks']  = [ [ 'state' => 'fail', 'label' => 'Atribuição ao aplicativo', 'detail' => 'A Microsoft bloqueou o login antes de chegar ao portal.' ] ];
				$vm['steps']   = [ 'Entra ID → Aplicativos empresariais → {app} → Usuários e grupos.', 'Adicionar atribuição → selecione o seu usuário ou o grupo de administradores → Atribuir.', 'Entre novamente no portal.' ];
				$vm['who']     = self::APP_ADMIN_WHO;
				$vm['actions'] = [ $retry, $other, $home ];
				break;

			case 'ca_blocked':
				$vm['icon']    = 'shield';
				$vm['title']   = 'Bloqueado por Acesso Condicional';
				$vm['summary'] = 'Uma política de Acesso Condicional do seu tenant bloqueou este login.';
				$vm['checks']  = [ [ 'state' => 'fail', 'label' => 'Políticas de Acesso Condicional', 'detail' => 'O login não atendeu aos requisitos (local, dispositivo, risco ou método de autenticação).' ] ];
				$vm['steps']   = [ 'Tente de um dispositivo ou rede aprovados pela sua organização.', 'Se persistir, envie ao administrador o código e o horário abaixo — ele encontra o motivo exato em Entra ID → Logs de entrada.' ];
				$vm['who']     = [ 'Conditional Access Administrator', 'Security Administrator', 'Global Administrator' ];
				$vm['actions'] = [ $retry, $home ];
				break;

			case 'mfa_required':
				$vm['icon']    = 'shield';
				$vm['tone']    = 'warn';
				$vm['title']   = 'Conclua a autenticação multifator';
				$vm['summary'] = 'O seu tenant exige MFA para este login e ela não foi concluída.';
				$vm['steps']   = [ 'Clique em Tentar novamente e conclua o MFA na tela da Microsoft.', 'Se você ainda não registrou um método, registre em https://aka.ms/mysecurityinfo.' ];
				$vm['actions'] = [ $retry, $home ];
				break;

			case 'personal_account':
				$vm['icon']    = 'user';
				$vm['title']   = 'Use uma conta corporativa';
				$vm['summary'] = 'Contas pessoais da Microsoft (Outlook.com, Hotmail, Live) não têm tenant do Microsoft 365. Entre com a conta de trabalho ou escola.';
				$vm['actions'] = [ $other, $home ];
				break;

			case 'cancelled':
				$vm['tone']    = 'info';
				$vm['icon']    = 'info';
				$vm['eyebrow'] = '';
				$vm['title']   = 'Login cancelado';
				$vm['summary'] = 'O login foi cancelado na tela da Microsoft. Nada foi alterado.';
				$vm['actions'] = [ $retry, $home ];
				break;

			case 'state_expired':
				$vm['tone']    = 'info';
				$vm['icon']    = 'clock';
				$vm['eyebrow'] = '';
				$vm['title']   = 'A tela de login expirou';
				$vm['summary'] = 'O login ficou aberto por muito tempo ou foi aberto em outra aba. Por segurança, comece de novo.';
				$vm['actions'] = [ $retry, $home ];
				break;

			case 'consent_declined':
				$vm['tone']    = 'warn';
				$vm['icon']    = 'shield';
				$vm['title']   = 'A aprovação não foi concluída';
				$vm['summary'] = 'A aprovação foi cancelada ou a conta usada não pode aprová-la. Aprovar permissões de aplicativo do Microsoft Graph exige Global Administrator ou Privileged Role Administrator.';
				$vm['who']     = self::CONSENT_WHO;
				$vm['steps']   = [ 'Peça a um Global Administrator ou Privileged Role Administrator para abrir o link de aprovação.' ];
				$vm['actions'] = array_values( array_filter( [ $copy_link, $mail, $other, $home ] ) );
				break;

			case 'microsoft_unavailable':
				$vm['tone']    = 'warn';
				$vm['icon']    = 'cloud';
				$vm['eyebrow'] = 'Instabilidade';
				$vm['title']   = 'A Microsoft não respondeu agora';
				$vm['summary'] = 'Não foi possível consultar o Microsoft Entra ID / Microsoft Graph neste momento (instabilidade, limite de requisições ou rede). Nada foi alterado.';
				$vm['steps']   = [ 'Aguarde 1 a 2 minutos e tente novamente.', 'Se persistir, consulte o status dos serviços em status.cloud.microsoft.' ];
				$vm['actions'] = [ $retry, [ 'kind' => 'link', 'label' => 'Status dos serviços Microsoft', 'href' => self::STATUS_URL, 'style' => 'ghost', 'target' => '_blank' ], $home ];
				break;

			case 'portal_config':
				$vm['icon']    = 'alert';
				$vm['eyebrow'] = 'Problema no portal';
				$vm['title']   = 'O portal está com um problema de configuração';
				$vm['summary'] = 'A credencial do portal (certificado) foi recusada pela Microsoft. Isso não é um problema do seu tenant — o operador do portal precisa atualizar o aplicativo.';
				$vm['who']     = [ 'Operador do portal' ];
				$vm['actions'] = [ $home ];
				break;

			case 'tenant_suspended':
				$vm['icon']    = 'lock';
				$vm['title']   = 'Acesso suspenso';
				$vm['summary'] = 'O acesso do tenant {domain} ao portal foi suspenso pelo operador. Entre em contato com o suporte.';
				$vm['who']     = [ 'Operador do portal' ];
				$vm['actions'] = [ $home ];
				break;

			case 'tenant_not_allowed':
				$vm['icon']    = 'lock';
				$vm['title']   = 'Tenant não autorizado';
				$vm['summary'] = 'Este portal está restrito a tenants específicos e o tenant {domain} não está na lista.';
				$vm['who']     = [ 'Operador do portal' ];
				$vm['actions'] = [ $other, $home ];
				break;

			default:
				$vm['code']    = 'generic';
				$vm['title']   = 'Não foi possível concluir o login';
				$vm['summary'] = 'A Microsoft retornou um erro inesperado. Tente novamente; se persistir, envie os detalhes técnicos abaixo ao suporte.';
				$vm['actions'] = [ $retry, $other, $home ];
		}

		// ---- technical details (support)
		$tech = [ [ 'Código', 'J365-' . strtoupper( str_replace( '_', '-', $vm['code'] ) ) ] ];
		if ( ! empty( $ctx['ms_code'] ) ) {
			$tech[] = [ 'Erro da Microsoft', (string) $ctx['ms_code'] ];
		}
		foreach ( [ 'trace' => 'Trace ID', 'correlation' => 'Correlation ID' ] as $k => $label ) {
			if ( ! empty( $ctx[ $k ] ) ) {
				$tech[] = [ $label, (string) $ctx[ $k ] ];
			}
		}
		if ( ! empty( $ctx['endpoint'] ) ) {
			$tech[] = [ 'Chamada', (string) $ctx['endpoint'] ];
		}
		if ( $consent && isset( $consent['state'] ) && 'error' !== $consent['state'] ) {
			$tech[] = [ 'Permissões aprovadas', count( array_intersect( $required, $granted ) ) . ' / ' . count( $required ) ];
		}
		if ( '' !== $tenant_id ) {
			$tech[] = [ 'Tenant', $tenant_id ];
		}
		$tech[] = [ 'Data/hora (UTC)', gmdate( 'Y-m-d H:i:s' ) ];
		$tech[] = [ 'ID do incidente', (string) ( $ctx['incident'] ?? self::incident() ) ];
		if ( ! empty( $ctx['error'] ) ) {
			$tech[] = [ 'Mensagem', mb_substr( (string) preg_replace( '/\s*(Trace|Correlation) ID:.*$/s', '', (string) $ctx['error'] ), 0, 400 ) ];
		}
		$vm['tech'] = $tech;
		return $vm;
	}

	/** Maps an OAuth/Entra error to a problem code. */
	/**
	 * Codes and IDs from Microsoft's redirect back to the portal. The query string can be crafted by
	 * anyone, so its free text (error_description) is never shown: only a validated AADSTS / OAuth error
	 * code and the Trace / Correlation IDs (GUIDs) that Microsoft support needs.
	 */
	public static function from_query( string $error, string $desc ): array {
		$code = Token_Service::aadsts( $desc );
		if ( '' === $code && preg_match( '/^[a-z_]{3,40}$/', $error ) ) {
			$code = $error;
		}
		$guid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
		return [
			'ms_code'     => $code,
			'trace'       => preg_match( '/Trace ID:\s*(' . $guid . ')/i', $desc, $m ) ? strtolower( $m[1] ) : '',
			'correlation' => preg_match( '/Correlation ID:\s*(' . $guid . ')/i', $desc, $m ) ? strtolower( $m[1] ) : '',
		];
	}

	public static function from_ms_error( string $error, string $description ): string {
		$code = Token_Service::aadsts( $description );
		return match ( true ) {
			in_array( $code, [ 'AADSTS50020', 'AADSTS500200', 'AADSTS50177' ], true ) => 'personal_account',
			in_array( $code, [ 'AADSTS65001', 'AADSTS90094', 'AADSTS90008' ], true ) || 'consent_required' === $error => 'consent_missing',
			'AADSTS50105' === $code                                                  => 'assignment_required',
			in_array( $code, [ 'AADSTS53003', 'AADSTS53000', 'AADSTS53001', 'AADSTS530032', 'AADSTS50158', 'AADSTS50097' ], true ) => 'ca_blocked',
			in_array( $code, [ 'AADSTS50076', 'AADSTS50079', 'AADSTS50072', 'AADSTS50074' ], true ) || 'interaction_required' === $error => 'mfa_required',
			in_array( $code, [ 'AADSTS7000112', 'AADSTS7000114' ], true ) => 'app_disabled',
			in_array( $code, [ 'AADSTS700027', 'AADSTS700024', 'AADSTS7000215', 'AADSTS7000222' ], true ) => 'portal_config',
			'AADSTS65004' === $code || ( 'access_denied' === $error && '' === $code ) => 'cancelled',
			'temporarily_unavailable' === $error || 'server_error' === $error        => 'microsoft_unavailable',
			default                                                                  => 'generic',
		};
	}
}
