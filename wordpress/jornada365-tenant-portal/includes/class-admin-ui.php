<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin: Configurações, Tenants conectados, Auditoria, Diagnóstico.
 */
final class Admin_Ui {

	private const CAP = 'manage_options';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_j365tp_tenant_action', [ $this, 'tenant_action' ] );
		add_action( 'admin_post_j365tp_create_page', [ $this, 'create_page' ] );
		add_action( 'admin_post_j365tp_purge_page_cache', [ $this, 'purge_page_cache' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	public function menu(): void {
		$setup = new Admin_Setup_Page();
		add_menu_page( Settings::brand_name(), Settings::brand_name(), self::CAP, 'j365tp', [ $setup, 'render' ], 'dashicons-cloud', 58 );
		add_submenu_page( 'j365tp', 'Assistente de configuração', 'Assistente', self::CAP, 'j365tp', [ $setup, 'render' ] );
		add_submenu_page( 'j365tp', 'Configurações', 'Configurações', self::CAP, 'j365tp-settings', [ $this, 'page_settings' ] );
		add_submenu_page( 'j365tp', 'Tenants conectados', 'Tenants', self::CAP, 'j365tp-tenants', [ $this, 'page_tenants' ] );
		add_submenu_page( 'j365tp', 'Auditoria', 'Auditoria', self::CAP, 'j365tp-audit', [ $this, 'page_audit' ] );
		add_submenu_page( 'j365tp', 'Diagnóstico', 'Diagnóstico', self::CAP, 'j365tp-diag', [ $this, 'page_diag' ] );
	}

	public function notices(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$brand = esc_html( Settings::brand_name() );
		if ( ! Settings::is_configured() && ( $_GET['page'] ?? '' ) !== 'j365tp' ) {
			echo '<div class="notice notice-warning"><p><strong>' . $brand . ':</strong> o aplicativo ainda não foi registrado. Abra o <a href="' . esc_url( admin_url( 'admin.php?page=j365tp' ) ) . '">Assistente de configuração</a> (um comando, sem client secret).</p></div>';
		}
		if ( Settings::app_outdated() && ( $_GET['page'] ?? '' ) !== 'j365tp' ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only
			echo '<div class="notice notice-warning"><p><strong>' . $brand . ':</strong> as permissões do aplicativo mudaram (nova versão ou módulos alterados). Abra o <a href="' . esc_url( admin_url( 'admin.php?page=j365tp' ) ) . '">Assistente</a> → <em>Atualizar o aplicativo pelo portal</em> para registrá-las no Entra ID. Sem isso, as ações (permissões delegadas) e os relatórios novos não funcionam; depois, cada tenant aprova a mudança uma vez pelo aviso no portal.</p></div>';
		}
		if ( ! Crypto::available() ) {
			echo '<div class="notice notice-error"><p><strong>' . $brand . ':</strong> a extensão PHP <code>sodium</code> não está disponível; ' . ( Settings::per_tenant() ? 'as sessões e o estado da conexão dos tenants não podem ser cifrados.' : 'a chave do certificado não pode ser armazenada com segurança.' ) . '</p></div>';
		}
		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			echo '<div class="notice notice-error"><p><strong>' . $brand . ':</strong> o site não está sendo servido via HTTPS (<code>is_ssl()</code> = false). Atrás do Cloudflare/proxy, configure <code>HTTP_X_FORWARDED_PROTO</code> no <code>wp-config.php</code>.</p></div>';
		}
	}

	/* ---------------------------------------------------------------- */

	public function page_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s     = Settings::all();
		$pages = get_pages( [ 'post_status' => 'publish,private' ] );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( Settings::brand_name() ); ?> — Configurações</h1>
			<?php if ( Settings::per_tenant() ) : ?>
			<p>Modelo por tenant: cada cliente conecta o próprio tenant pelo portal e o aplicativo é criado no tenant dele (veja o <a href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp' ) ); ?>">Assistente</a>). Aqui ficam as opções do portal.</p>
			<h2 class="title">Referência do aplicativo criado em cada tenant</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th>Retorno do login (SPA)</th><td><code><?php echo esc_html( Spa_Auth::redirect_uri() ); ?></code></td></tr>
					<tr><th>Link de conexão</th><td><code><?php echo esc_html( Spa_Auth::connect_url() ); ?></code></td></tr>
					<?php if ( '' !== Settings::company_tenant_id() ) : ?>
					<tr><th>Link para o login corporativo</th><td><a href="<?php echo esc_url( Spa_Auth::signin_url() ); ?>"><code><?php echo esc_html( Spa_Auth::signin_url() ); ?></code></a></td></tr>
					<?php endif; ?>
					<tr><th>Microsoft Graph (delegadas)</th><td><code><?php echo esc_html( implode( ', ', Settings::graph_scopes() ) ); ?></code><p class="description">Determinadas pelos módulos habilitados abaixo. Ao mudar, os administradores de cada tenant veem no portal o aviso <em>Atualizar o aplicativo</em>.</p></td></tr>
					<tr><th>Exchange Online (delegada)</th><td><code><?php echo esc_html( implode( ', ', Settings::exchange_scopes() ) ?: '—' ); ?></code></td></tr>
					<tr><th>Login</th><td><code><?php echo esc_html( implode( ', ', Settings::OIDC_SCOPES ) ); ?></code></td></tr>
				</tbody>
			</table>
			<?php else : ?>
			<p>O registro do aplicativo é feito pelo <a href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp' ) ); ?>">Assistente</a>. Aqui ficam as opções do portal.</p>

			<h2 class="title">Referência do App Registration</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th>Redirect URI (Web) — login</th><td><code><?php echo esc_html( Request::login_redirect_uri() ); ?></code></td></tr>
					<tr><th>Redirect URI (Web) — admin consent</th><td><code><?php echo esc_html( Request::consent_redirect_uri() ); ?></code></td></tr>
					<tr><th>Front-channel logout URL</th><td><code><?php echo esc_html( Request::portal_url() ); ?></code></td></tr>
					<tr><th>Permissões de aplicativo (Graph) ativas</th><td><code><?php echo esc_html( implode( ', ', Settings::active_permissions() ) ); ?></code><p class="description">Determinadas pelos módulos habilitados abaixo. Ao mudar, use <em>Assistente → Atualizar o aplicativo pelo portal</em> e peça aos tenants para aprovar novamente.</p></td></tr>
					<tr><th>Permissões delegadas</th><td><code>openid, profile, email</code></td></tr>
				</tbody>
			</table>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'j365tp' ); ?>
				<table class="form-table" role="presentation">
					<?php if ( ! Settings::per_tenant() ) : ?>
					<tr><th scope="row"><label for="client_id">Client ID (Application ID)</label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[client_id]" id="client_id" type="text" class="regular-text code" value="<?php echo esc_attr( $s['client_id'] ); ?>" placeholder="preenchido automaticamente pelo Assistente">
						<p class="description">Preenchido pelo Assistente. Credencial: <?php echo Client_Credential::has_active() ? '<strong>certificado</strong> (sem client secret)' : ( '' !== $s['client_secret_enc'] ? 'client secret (legado)' : 'nenhuma' ); ?>.</p></td></tr>
					<tr><th scope="row"><label for="client_secret">Client Secret (legado)</label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[client_secret]" id="client_secret" type="password" class="regular-text code" autocomplete="new-password" placeholder="<?php echo '' !== $s['client_secret_enc'] ? '•••••••• (armazenado criptografado — deixe em branco para manter)' : 'Não recomendado — use o Assistente (certificado)'; ?>">
						<p class="description">Usado apenas se não houver certificado ativo. O Assistente substitui o secret por um certificado gerado neste servidor.</p></td></tr>
					<tr><th scope="row"><label for="home_tenant_id">Tenant ID (seu tenant, opcional)</label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[home_tenant_id]" id="home_tenant_id" type="text" class="regular-text code" value="<?php echo esc_attr( $s['home_tenant_id'] ); ?>"><p class="description">Apenas informativo/diagnóstico.</p></td></tr>
					<?php endif; ?>
					<tr><th scope="row"><label for="portal_page_id">Página do portal</label></th>
						<td><select name="<?php echo esc_attr( Settings::OPTION ); ?>[portal_page_id]" id="portal_page_id">
							<option value="0">— selecione a página com o shortcode [j365_tenant_portal] —</option>
							<?php foreach ( $pages as $p ) : ?>
								<option value="<?php echo (int) $p->ID; ?>" <?php selected( (int) $s['portal_page_id'], $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! $s['portal_page_id'] ) : ?>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=j365tp_create_page' ), 'j365tp_create_page' ) ); ?>">Criar página “Portal” automaticamente</a>
						<?php endif; ?></td></tr>
					<tr><th scope="row">Funções do Entra autorizadas</th>
						<td><fieldset>
							<?php foreach ( Settings::DEFAULT_ROLE_TEMPLATES as $id => $name ) : ?>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[allowed_roles][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $s['allowed_roles'], true ) ); ?>> <?php echo esc_html( $name ); ?> <code style="font-size:11px"><?php echo esc_html( $id ); ?></code></label>
							<?php endforeach; ?>
							<?php $extra = array_diff( $s['allowed_roles'], array_keys( Settings::DEFAULT_ROLE_TEMPLATES ) ); ?>
							<label for="allowed_roles_extra" style="display:block;margin-top:8px">Outros roleTemplateIds (um por linha):</label>
							<textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[allowed_roles_extra]" id="allowed_roles_extra" rows="2" class="large-text code"><?php echo esc_textarea( implode( "\n", $extra ) ); ?></textarea>
							<p class="description">Verificado server-side via Graph (<code>memberOf/directoryRole</code>) <?php echo Settings::per_tenant() ? 'com o token delegado de quem entra' : 'com o token de aplicativo do tenant'; ?>. Atribuições PIM precisam estar <strong>ativas</strong>.</p>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[allow_consenter]" value="1" <?php checked( $s['allow_consenter'] ); ?>> Permitir o administrador que <?php echo Settings::per_tenant() ? 'conectou o tenant' : 'aprovou o aplicativo'; ?> nas primeiras 24 h, mesmo sem função ativa (evita lockout logo após o onboarding com PIM). Depois disso valem só as funções de diretório.</label>
						</fieldset></td></tr>
					<tr><th scope="row">Módulos de relatório</th>
						<td><fieldset>
							<p class="description">Relatórios base (sempre ativos): Visão geral, Identidade, MFA, Dispositivos, Aplicativos, Acesso Condicional, Grupos, Licenciamento, Convidados, Domínios, Políticas de autenticação.</p>
							<?php foreach ( Settings::MODULES as $key => $mod ) : ?>
								<label style="display:block;margin:4px 0"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[enabled_modules][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['enabled_modules'], true ) ); ?>> <strong><?php echo esc_html( $mod['label'] ); ?></strong> — <?php echo esc_html( $mod['note'] ); ?> <code style="font-size:11px"><?php echo esc_html( implode( ', ', $mod['permissions'] ) ); ?></code><?php foreach ( (array) ( $mod['delegated'] ?? [] ) as $res => $scopes ) : ?> <code style="font-size:11px;background:#fff4e5">delegada (<?php echo esc_html( 'exchange' === $res ? 'Exchange Online' : 'Graph' ); ?>): <?php echo esc_html( implode( ', ', (array) $scopes ) ); ?></code><?php endforeach; ?></label>
							<?php endforeach; ?>
						</fieldset></td></tr>
					<tr><th scope="row"><label for="allowed_tenants">Restringir a tenants (opcional)</label></th>
						<td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[allowed_tenants]" id="allowed_tenants" rows="3" class="large-text code" placeholder="GUIDs de tenant, um por linha. Vazio = qualquer tenant pode conectar."><?php echo esc_textarea( $s['allowed_tenants'] ); ?></textarea></td></tr>
					<?php if ( Settings::per_tenant() ) : ?>
					<tr><th scope="row"><label for="company_tenant_id">Login corporativo</label></th>
						<td><input name="<?php echo esc_attr( Settings::OPTION ); ?>[company_tenant_id]" id="company_tenant_id" type="text" class="regular-text code" maxlength="36" value="<?php echo esc_attr( $s['company_tenant_id'] ); ?>" placeholder="ID do diretório do Microsoft Entra">
						<p class="description">Para uma instalação interna, informe o GUID do próprio tenant. O portal abre o login da empresa sem pedir o e-mail e restringe todo o acesso a esse diretório. Deixe em branco para voltar à descoberta por e-mail e à lista de tenants acima.</p>
						<p><label for="company_login_label">Texto do botão (opcional)</label><br><input name="<?php echo esc_attr( Settings::OPTION ); ?>[company_login_label]" id="company_login_label" type="text" class="regular-text" maxlength="60" value="<?php echo esc_attr( $s['company_login_label'] ); ?>" placeholder="Entrar com Microsoft 365"></p></td></tr>
					<?php endif; ?>
					<tr><th scope="row">Sessão</th>
						<td><label>Duração máxima <input type="number" min="1" max="24" name="<?php echo esc_attr( Settings::OPTION ); ?>[session_ttl_hours]" value="<?php echo (int) $s['session_ttl_hours']; ?>" class="small-text"> h</label> &nbsp;
							<label>Inatividade <input type="number" min="10" max="480" name="<?php echo esc_attr( Settings::OPTION ); ?>[session_idle_minutes]" value="<?php echo (int) $s['session_idle_minutes']; ?>" class="small-text"> min</label></td></tr>
					<tr><th scope="row">Relatórios</th>
						<td><label>Contas inativas (padrão) <input type="number" min="1" max="730" name="<?php echo esc_attr( Settings::OPTION ); ?>[inactive_days]" value="<?php echo (int) $s['inactive_days']; ?>" class="small-text"> dias</label> &nbsp;
							<label>Dispositivos obsoletos (padrão) <input type="number" min="1" max="730" name="<?php echo esc_attr( Settings::OPTION ); ?>[stale_device_days]" value="<?php echo (int) $s['stale_device_days']; ?>" class="small-text"> dias</label></td></tr>
					<tr><th scope="row">Privacidade</th>
						<td><p><strong>Nada dos relatórios é salvo no servidor</strong>: sessão em cookie cifrado, resultados apenas no navegador do usuário, dados em processamento cifrados com chave que só o navegador possui e apagados ao final (no máximo <?php echo (int) ( Jobs::TTL / MINUTE_IN_SECONDS ); ?> min).</p>
							<label>Retenção da auditoria pseudonimizada <input type="number" min="1" max="365" name="<?php echo esc_attr( Settings::OPTION ); ?>[audit_days]" value="<?php echo (int) ( $s['audit_days'] ?? 30 ); ?>" class="small-text"> dias</label></td></tr>
					<tr><th scope="row">Rede</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[trust_cloudflare]" value="1" <?php checked( $s['trust_cloudflare'] ); ?>> Site atrás do Cloudflare (usar <code>CF-Connecting-IP</code> apenas quando o IP de origem pertencer aos ranges do Cloudflare).</label></td></tr>
					<tr><th scope="row">Identidade visual</th>
						<td><input type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[brand_name]" value="<?php echo esc_attr( $s['brand_name'] ); ?>" class="regular-text" placeholder="Nome exibido"> &nbsp;
							<input type="email" name="<?php echo esc_attr( Settings::OPTION ); ?>[support_email]" value="<?php echo esc_attr( $s['support_email'] ); ?>" class="regular-text" placeholder="E-mail de suporte"> &nbsp;
							<p><label>Política de privacidade <input type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[privacy_url]" value="<?php echo esc_attr( $s['privacy_url'] ); ?>" class="regular-text"></label></p>
							<p><label>Termos de uso <input type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[terms_url]" value="<?php echo esc_attr( $s['terms_url'] ); ?>" class="regular-text"></label></p>
							<p><label>URL HTTPS direta do logo <input type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[logo_source_url]" value="<?php echo esc_attr( $s['logo_source_url'] ); ?>" class="regular-text"></label></p>
							<p class="description">Depois de salvar a URL, atualize o logo no Assistente. Você também pode enviar um arquivo por lá.</p></td></tr>
				</table>
				<?php submit_button( 'Salvar configurações' ); ?>
			</form>
		</div>
		<?php
	}

	public function create_page(): void {
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'j365tp_create_page' ) ) {
			wp_die( 'Não autorizado.' );
		}
		$id = wp_insert_post(
			[
				'post_title'   => 'Portal',
				'post_name'    => 'portal',
				'post_content' => '[j365_tenant_portal]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'comment_status' => 'closed',
			]
		);
		if ( $id && ! is_wp_error( $id ) ) {
			Settings::update( [ 'portal_page_id' => (int) $id ] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=j365tp-settings&created=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- */

	public function page_tenants(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tenants = Tenant_Repository::all();
		?>
		<div class="wrap">
			<h1>Tenants conectados</h1>
			<?php $per = Settings::per_tenant(); ?>
			<p>Link público de conexão (envie ao administrador do cliente): <code><?php echo esc_html( $per ? Spa_Auth::connect_url() : Request::route_url( 'consent/start' ) ); ?></code></p>
			<table class="widefat striped">
				<thead><tr><th>Tenant ID</th><?php if ( $per ) : ?><th>Aplicativo no tenant (client ID)</th><?php endif; ?><th>Status</th><th><?php echo $per ? 'Conectado em' : 'Aprovado em'; ?></th><th>Último acesso (dia)</th><th>Ações</th></tr></thead>
				<tbody>
				<?php if ( ! $tenants ) : ?>
					<tr><td colspan="<?php echo $per ? 6 : 5; ?>">Nenhum tenant conectado ainda.</td></tr>
				<?php endif; ?>
				<?php foreach ( $tenants as $t ) : ?>
					<tr>
						<td><code><?php echo esc_html( $t['tenant_id'] ); ?></code><?php echo ! $per && strtolower( (string) Settings::get( 'home_tenant_id', '' ) ) === $t['tenant_id'] ? ' <em>(publisher)</em>' : ''; ?></td>
						<?php if ( $per ) : ?><td><?php echo '' !== Tenant_Repository::client_id( $t ) ? '<code>' . esc_html( Tenant_Repository::client_id( $t ) ) . '</code> · ' . esc_html( 'manual' === ( $t['settings']['app_mode'] ?? '' ) ? 'manual' : 'automática' ) : '<em>não conectado</em>'; ?></td><?php endif; ?>
						<td><?php echo esc_html( $t['status'] ); ?></td>
						<td><?php echo esc_html( $t['consented_at'] ?? '' ); ?></td>
						<td><?php echo esc_html( $t['last_login_at'] ? substr( $t['last_login_at'], 0, 10 ) : '—' ); ?></td>
						<td>
							<?php foreach ( [ 'active' === $t['status'] ? 'revoke' : 'activate', 'delete' ] as $action ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=j365tp_tenant_action&do=' . $action . '&tenant=' . $t['tenant_id'] ), 'j365tp_tenant_' . $t['tenant_id'] ) ); ?>" <?php echo 'delete' === $action ? 'onclick="return confirm(\'Remover o tenant e as regras de homologação dele?\')"' : ''; ?>><?php echo esc_html( [ 'revoke' => 'Revogar', 'activate' => 'Reativar', 'delete' => 'Remover' ][ $action ] ); ?></a>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Por privacidade, o portal guarda só o ID do tenant<?php echo $per ? ', o ID do aplicativo do tenant' : ''; ?>, o status e datas — nomes e e-mails nunca são armazenados. Revogar bloqueia o acesso na próxima requisição (as sessões são verificadas a cada chamada), mas o aplicativo continua no tenant do cliente até que o administrador dele o exclua.<?php echo $per ? ' Remover apaga o vínculo tenant → aplicativo: o próximo login de um administrador do cliente com o aplicativo existente o registra de novo.' : ''; ?></p>
		</div>
		<?php
	}

	public function tenant_action(): void {
		$tenant = strtolower( sanitize_text_field( wp_unslash( $_GET['tenant'] ?? '' ) ) );
		$do     = sanitize_key( $_GET['do'] ?? '' );
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'j365tp_tenant_' . $tenant ) ) {
			wp_die( 'Não autorizado.' );
		}
		$actor = wp_get_current_user()->user_login;
		switch ( $do ) {
			case 'revoke':
				Tenant_Repository::set_status( $tenant, 'revoked' );
				break;
			case 'activate':
				Tenant_Repository::set_status( $tenant, 'active' );
				break;
			case 'clear_cache':
				Report_Cache::invalidate( $tenant );
				break;
			case 'delete':
				Tenant_Repository::delete( $tenant );
				break;
		}
		Audit::log( 'admin_tenant_' . $do, $tenant, 'wp:' . $actor );
		wp_safe_redirect( admin_url( 'admin.php?page=j365tp-tenants' ) );
		exit;
	}

	/** Diagnóstico → "Limpar cache de página": removes stored copies of the portal routes. */
	public function purge_page_cache(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Não autorizado.' );
		}
		check_admin_referer( 'j365tp_purge_page_cache' );
		$done = Page_Cache::purge_all();
		Audit::log( 'page_cache_purged', '', 'wp:' . wp_get_current_user()->user_login, [ 'caches' => $done, 'manual' => true ] );
		wp_safe_redirect( add_query_arg( [ 'page' => 'j365tp-diag', 'j365_purged' => count( $done ) ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function page_audit(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rows = Audit::recent( 300 );
		?>
		<div class="wrap">
			<h1>Auditoria</h1>
			<table class="widefat striped">
				<thead><tr><th>Quando (UTC)</th><th>Ação</th><th>Tenant</th><th>Ator</th><th>IP</th><th>Detalhes</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr><td><?php echo esc_html( $r['ts'] ); ?></td><td><code><?php echo esc_html( $r['action'] ); ?></code></td><td><code><?php echo esc_html( $r['tenant_id'] ); ?></code></td><td><?php echo esc_html( $r['actor'] ); ?></td><td><?php echo esc_html( $r['ip'] ); ?></td><td style="max-width:420px;word-break:break-all"><small><?php echo esc_html( (string) $r['details'] ); ?></small></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function page_diag(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$checks = [
			[ 'PHP ≥ 8.1', version_compare( PHP_VERSION, '8.1', '>=' ), PHP_VERSION ],
			[ 'Extensão sodium', Crypto::available(), Crypto::available() ? 'ok' : 'ausente' ],
			[ 'Extensão openssl', function_exists( 'openssl_verify' ), '' ],
			[ 'Extensão zlib', function_exists( 'gzcompress' ), '' ],
			[ 'HTTPS detectado (is_ssl)', is_ssl(), '' ],
			Settings::per_tenant()
				? [ 'Retorno do login (SPA) em HTTPS', str_starts_with( Spa_Auth::redirect_uri(), 'https://' ), Spa_Auth::redirect_uri() ]
				: [ 'Aplicativo registrado (Client ID + certificado)', Settings::is_configured(), Settings::client_id() ?: 'use o Assistente' ],
			[ 'Permalinks ativos', '' !== get_option( 'permalink_structure' ), get_option( 'permalink_structure' ) ?: 'plain (rotas /j365/… exigem permalinks)' ],
			[ 'max_execution_time ≥ 60', (int) ini_get( 'max_execution_time' ) === 0 || (int) ini_get( 'max_execution_time' ) >= 60, ini_get( 'max_execution_time' ) ],
			[ 'memory_limit ≥ 256M', wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) >= 256 * MB_IN_BYTES || -1 === (int) ini_get( 'memory_limit' ), ini_get( 'memory_limit' ) ],
			[ 'WP-Cron agendado', (bool) wp_next_scheduled( 'j365tp_hourly_maintenance' ), '' ],
		];
		$caches    = Page_Cache::detected();
		$purged_at = (int) get_option( Page_Cache::PURGED_AT, 0 );
		$checks[]  = [
			'Cache de página: rotas /j365/ fora do cache',
			true,
			( $caches ? implode( ', ', $caches ) : 'nenhum plugin de cache detectado' ) . ' · DONOTCACHEPAGE + Cache-Control: no-store' . ( $purged_at ? ' · última limpeza: ' . wp_date( 'd/m/Y H:i', $purged_at ) : '' ),
		];
		$cat      = get_option( Sku_Catalog::OPTION );
		$checks[] = [ 'Catálogo de licenças (nomes amigáveis)', true, 'Microsoft · ' . Sku_Catalog::version() . ( is_array( $cat ) && ! empty( $cat['fetched'] ) ? ' · atualizado do CSV oficial em ' . wp_date( 'd/m/Y H:i', (int) $cat['fetched'] ) : ' · cópia embutida (atualização semanal via WP-Cron)' ) . ' · ' . count( Sku_Catalog::data()['skus'] ) . ' produtos' ];
		$live     = Page_Cache::self_test();
		$checks[] = [ Settings::per_tenant() ? 'Retorno do login (/j365/auth/spa/) responde ao vivo' : 'Login público (/j365/auth/login/) responde ao vivo', $live['ok'], $live['detail'] ];
		$graph_ok = null;
		$graph_msg = '';
		if ( Settings::per_tenant() ) {
			$ms        = Admin_Setup_Page::test_microsoft();
			$graph_ok  = $ms['ok'];
			$graph_msg = $ms['message'];
		} elseif ( Settings::is_configured() ) {
			$test_tenant = Settings::get( 'home_tenant_id' ) ?: ( Tenant_Repository::all()[0]['tenant_id'] ?? '' );
			if ( $test_tenant ) {
				try {
					Token_Service::acquire( $test_tenant, true );
					$graph_ok  = true;
					$graph_msg = 'Token obtido para ' . $test_tenant;
				} catch ( \Throwable $e ) {
					$graph_ok  = false;
					$graph_msg = $e->getMessage();
				}
			} else {
				$graph_msg = 'Informe o Tenant ID em Configurações ou conecte um tenant para testar.';
			}
		}
		?>
		<div class="wrap">
			<h1>Diagnóstico</h1>
			<table class="widefat striped" style="max-width:900px">
				<?php foreach ( $checks as [ $label, $ok, $detail ] ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo null === $ok ? '—' : ( $ok ? '✅' : '❌' ); ?></td><td><code><?php echo esc_html( (string) $detail ); ?></code></td></tr>
				<?php endforeach; ?>
				<tr><td><?php echo Settings::per_tenant() ? 'Microsoft (descoberta de tenant, JWKS e Graph)' : 'Token de aplicativo (client credentials)'; ?></td><td><?php echo null === $graph_ok ? '—' : ( $graph_ok ? '✅' : '❌' ); ?></td><td><code><?php echo esc_html( $graph_msg ); ?></code></td></tr>
			</table>
			<?php if ( isset( $_GET['j365_purged'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification -- display only ?>
				<div class="notice notice-success inline" style="max-width:900px"><p>Cache de página limpo. As rotas do portal (/j365/…) agora são sempre geradas na hora.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
				<input type="hidden" name="action" value="j365tp_purge_page_cache">
				<?php wp_nonce_field( 'j365tp_purge_page_cache' ); ?>
				<button type="submit" class="button">Limpar cache de página</button>
				<span class="description">Remove cópias antigas das rotas /j365/ guardadas por WP-Optimize, LiteSpeed, WP Super Cache, W3 Total Cache, WP Rocket ou SiteGround.</span>
			</form>
			<?php if ( Settings::per_tenant() ) : ?>
			<h2>Relatórios</h2>
			<p class="description" style="max-width:900px">No modelo por tenant não existe token de aplicativo: os relatórios rodam no navegador de quem entra, com a conta e o aplicativo do tenant dele. Para testá-los, entre no portal com um administrador de um tenant conectado.</p>
			<?php else : ?>
			<?php $j365_types = array_values( array_diff( Settings::active_reports(), [ 'app_devices' ] ) ); ?>
			<h2>Testar todos os relatórios</h2>
			<p class="description" style="max-width:900px">Executa cada relatório no tenant da Jornada 365 (o tenant onde o aplicativo foi registrado) e mostra apenas o resultado técnico — status, linhas, tempo e avisos. Nenhum dado de relatório é exibido, transmitido ou salvo.</p>
			<p><button type="button" class="button button-primary" id="j365-diag-run" <?php disabled( ! Settings::is_configured() ); ?>>Testar todos os relatórios</button> <span id="j365-diag-status" class="description"></span></p>
			<table class="widefat striped" id="j365-diag-table" style="max-width:1100px;display:none"><thead><tr><th>Relatório</th><th>Status</th><th>Linhas</th><th>Tempo</th><th>Avisos / erro</th></tr></thead><tbody></tbody></table>
			<script>
			(function () {
				var types = <?php echo wp_json_encode( $j365_types ); ?>, url = <?php echo wp_json_encode( rest_url( Rest_Api::NS . '/diag/reports' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
				var btn = document.getElementById('j365-diag-run'), st = document.getElementById('j365-diag-status'), table = document.getElementById('j365-diag-table'), tb = table.querySelector('tbody');
				var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
				btn.addEventListener('click', async function () {
					btn.disabled = true; tb.innerHTML = ''; table.style.display = ''; var ok = 0, fail = 0;
					for (var i = 0; i < types.length; i++) {
						var type = types[i], tr = document.createElement('tr');
						st.textContent = (i + 1) + '/' + types.length + ': ' + type + '…';
						tr.innerHTML = '<td><code>' + esc(type) + '</code></td><td>⏳</td><td></td><td></td><td></td>'; tb.appendChild(tr);
						try {
							var r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ type: type }) });
							var j = await r.json(), x = (j.results || [])[0] || { ok: false, error: j.message || ('HTTP ' + r.status) };
							if (x.ok) { ok++; } else { fail++; }
							tr.innerHTML = '<td><code>' + esc(type) + '</code></td><td>' + (x.ok ? '✅' : '❌') + '</td><td>' + (x.ok ? esc(x.rows) : '') + '</td><td>' + (x.ms != null ? (x.ms / 1000).toFixed(1) + ' s' : '') + '</td><td>' + esc(x.ok ? (x.warnings || []).join(' · ') : (x.error + (x.where ? ' (' + x.where + ')' : ''))) + '</td>';
						} catch (e) { fail++; tr.children[1].textContent = '❌'; tr.children[4].textContent = e.message; }
					}
					st.textContent = 'Concluído: ' + ok + ' ok, ' + fail + ' com falha.'; btn.disabled = false;
				});
			})();
			</script>
			<?php endif; ?>
			<h2>Rotas</h2>
			<ul>
				<li>Login: <code><?php echo esc_html( Request::route_url( 'auth/login' ) ); ?></code></li>
				<?php if ( Settings::per_tenant() ) : ?>
				<li>Retorno do login (SPA): <code><?php echo esc_html( Spa_Auth::redirect_uri() ); ?></code></li>
				<li>Conectar um tenant: <code><?php echo esc_html( Spa_Auth::connect_url() ); ?></code></li>
				<?php else : ?>
				<li>Admin consent: <code><?php echo esc_html( Request::route_url( 'consent/start' ) ); ?></code></li>
				<?php endif; ?>
				<li>REST: <code><?php echo esc_html( rest_url( Rest_Api::NS ) ); ?></code></li>
			</ul>
		</div>
		<?php
	}
}
