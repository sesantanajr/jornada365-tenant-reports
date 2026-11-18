<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin → Tenant Jornada365 → Assistente.
 *
 * Per-tenant model (default): there is nothing to register here — each customer tenant gets its own
 * app when one of its administrators connects it in the portal (see Onboarding). The page shows
 * how customers connect, what is created in their tenant, the connected tenants, the brand used on
 * their apps and, if present, the app of the previous model (which can be forgotten/deleted).
 *
 * Classic model (filter j365tp_per_tenant = false): status of the multi-tenant app, brand assets
 * and certificate; the registration itself happens in the portal (see Bootstrap).
 */
final class Admin_Setup_Page {

	private const CAP = 'manage_options';

	public function __construct() {
		// Handlers are registered once from Plugin::boot via register().
	}

	public static function register(): void {
		$self = new self();
		foreach ( [ 'renew', 'test', 'brand_refresh', 'brand_upload', 'icon_upload', 'icon_style', 'download_cert', 'forget_legacy' ] as $a ) {
			add_action( 'admin_post_j365tp_setup_' . $a, [ $self, 'handle_' . $a ] );
		}
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( (string) ( $_REQUEST['_wpnonce'] ?? '' ), 'j365tp_setup_' . $action ) ) {
			wp_die( 'Não autorizado.' );
		}
	}

	private function back( string $type, string $msg ): void {
		set_transient( 'j365tp_setup_notice_' . get_current_user_id(), [ 'type' => $type, 'msg' => $msg ], 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=j365tp' ) );
		exit;
	}

	private function url( string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=j365tp_setup_' . $action ), 'j365tp_setup_' . $action );
	}

	/* ------------------------------------------------------------ handlers */

	public function handle_renew(): void {
		$this->guard( 'renew' );
		try {
			Client_Credential::renew();
		} catch ( \Throwable $e ) {
			$this->back( 'error', $e->getMessage() );
		}
		Audit::log( 'certificate_renew', '', 'wp:' . wp_get_current_user()->user_login );
		$this->back( 'success', 'Novo certificado gerado. Clique em "Atualizar o aplicativo" para enviá-lo ao Entra ID; o atual continua valendo até lá.' );
	}

	public function handle_test(): void {
		$this->guard( 'test' );
		$r = Settings::per_tenant() ? self::test_microsoft() : Bootstrap::test_connection();
		$this->back( $r['ok'] ? 'success' : 'error', $r['message'] );
	}

	/**
	 * Per-tenant model: the server only needs to reach Microsoft's public endpoints (tenant discovery,
	 * signing keys of ID tokens, device code/token for the "connect" assistant and Microsoft Graph).
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function test_microsoft(): array {
		$checks = [
			'OpenID (descoberta de tenant)' => Token_Service::AUTHORITY . '/organizations/v2.0/.well-known/openid-configuration',
			'Chaves de assinatura (JWKS)'   => Token_Service::AUTHORITY . '/organizations/discovery/v2.0/keys',
			'Microsoft Graph'               => Token_Service::GRAPH . '/v1.0/$metadata#organization',
		];
		$bad = [];
		foreach ( $checks as $label => $url ) {
			$r    = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 0 ] );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			// Graph answers 401 without a token: reachable is what matters here.
			$ok = 'Microsoft Graph' === $label ? in_array( $code, [ 200, 401 ], true ) : 200 === $code;
			if ( ! $ok ) {
				$bad[] = $label . ' (' . ( is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . $code ) . ')';
			}
		}
		$ok  = ! $bad;
		$msg = $ok ? 'Microsoft acessível: descoberta de tenant, chaves de assinatura e Microsoft Graph respondem.' : 'Falha ao falar com a Microsoft: ' . implode( '; ', $bad );
		Client_Credential::record_test( $ok, $msg );
		return [ 'ok' => $ok, 'message' => $msg ];
	}

	/** Per-tenant model: removes the identifiers and certificate of the previous (multi-tenant) app. */
	public function handle_forget_legacy(): void {
		$this->guard( 'forget_legacy' );
		if ( ! Settings::per_tenant() ) {
			$this->back( 'error', 'Disponível apenas no modelo por tenant.' );
		}
		$old = Settings::client_id();
		$all = Settings::all();
		foreach ( [ 'client_id', 'client_secret_enc', 'home_tenant_id', 'app_signature' ] as $k ) {
			$all[ $k ] = '';
		}
		remove_all_filters( 'sanitize_option_' . Settings::OPTION ); // a direct write, not a form submission
		update_option( Settings::OPTION, $all, 'no' );
		Client_Credential::forget();
		Audit::log( 'legacy_app_forgotten', '', 'wp:' . wp_get_current_user()->user_login, [ 'client_id' => $old ] );
		$this->back( 'success', 'Aplicativo do modelo anterior esquecido: Client ID, certificado e secret foram removidos deste servidor. Exclua também o registro no Entra ID do tenant da Jornada 365.' );
	}

	public function handle_brand_refresh(): void {
		$this->guard( 'brand_refresh' );
		$r = Brand::refresh_from_url();
		$this->back( $r['ok'] ? 'success' : 'error', $r['message'] );
	}

	public function handle_brand_upload(): void {
		$this->guard( 'brand_upload' );
		$r = $this->uploaded( 'logo_file', fn( $bytes ) => Brand::store_source( $bytes, 'upload' ) );
		$this->back( $r['ok'] ? 'success' : 'error', $r['message'] );
	}

	public function handle_icon_upload(): void {
		$this->guard( 'icon_upload' );
		$r = $this->uploaded( 'icon_file', fn( $bytes ) => Brand::store_icon( $bytes ) );
		$this->back( $r['ok'] ? 'success' : 'error', $r['message'] );
	}

	public function handle_icon_style(): void {
		$this->guard( 'icon_style' );
		$style = in_array( $_POST['icon_style'] ?? '', [ 'badge', 'full' ], true ) ? (string) $_POST['icon_style'] : 'badge';
		Settings::update( [ 'icon_style' => $style ] );
		$src = Brand::source_url();
		if ( '' !== $src ) {
			$path = trailingslashit( wp_get_upload_dir()['basedir'] ) . Brand::DIR . '/logo-source.png';
			$r    = Brand::store_source( (string) file_get_contents( $path ), (string) ( Brand::meta()['origin'] ?? 'url' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->back( $r['ok'] ? 'success' : 'error', 'Estilo do ícone: ' . ( 'badge' === $style ? 'selo "365"' : 'logo completo' ) . '. ' . $r['message'] );
		}
		$this->back( 'success', 'Estilo salvo.' );
	}

	private function uploaded( string $field, callable $store ): array {
		$f = $_FILES[ $field ] ?? null;
		if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return [ 'ok' => false, 'message' => 'Nenhum arquivo recebido.' ];
		}
		return $store( (string) file_get_contents( $f['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function handle_download_cert(): void {
		$this->guard( 'download_cert' );
		$cert = Client_Credential::to_register();
		if ( ! $cert ) {
			wp_die( 'Nenhum certificado gerado.' );
		}
		nocache_headers();
		header( 'Content-Type: application/x-x509-ca-cert' );
		header( 'Content-Disposition: attachment; filename="tenant-jornada365.cer"' );
		echo $cert['cert_pem']; // phpcs:ignore WordPress.Security.EscapeOutput -- public certificate (PEM)
		exit;
	}


	private function styles(): void {
		?>
			<style>
				.j365-setup .step{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px 20px;margin:14px 0;max-width:1080px}
				.j365-setup .step h2{margin:0 0 6px;display:flex;align-items:center;gap:10px;font-size:17px}
				.j365-setup .num{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:#0068b8;color:#fff;font-size:13px;font-weight:700}
				.j365-setup .num.ok{background:#00a32a}
				.j365-setup .row{display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start}
				.j365-setup .preview{border:1px dashed #c3c4c7;border-radius:8px;padding:10px;background:repeating-conic-gradient(#f6f7f7 0 25%,#fff 0 50%) 50%/16px 16px}
				.j365-setup .preview.dark{background:#071a33}
				.j365-setup .kv td{padding:4px 12px 4px 0;vertical-align:top}
				.j365-setup .muted{color:#646970}
				.j365-setup .hero{display:flex;gap:18px;align-items:center;max-width:1080px}
				.j365-setup .hero img{height:58px;width:auto}
				.j365-setup .cta{display:inline-flex;align-items:center;gap:10px;background:linear-gradient(120deg,#00b0ff,#0068b8);color:#fff;border-radius:10px;padding:10px 18px;font-weight:600;text-decoration:none;font-size:14px}
				.j365-setup .cta:hover{color:#fff;filter:brightness(1.08)}
			</style>
		<?php
	}

	private function brand_block( string $num, string $intro ): void {
		$brand  = Brand::meta();
		$source = (string) Settings::get( 'logo_source_url' );
		?>
			<div class="step">
				<h2><span class="num <?php echo Brand::icon_url() ? 'ok' : ''; ?>"><?php echo esc_html( $num ); ?></span> Marca e logo</h2>
				<p class="muted"><?php echo esc_html( $intro ); ?></p>
				<div class="row">
					<div><div class="muted">Logo (fundo claro)</div><div class="preview"><?php echo Brand::wordmark_url() ? '<img src="' . esc_url( Brand::wordmark_url() ) . '" style="height:64px;width:auto;display:block" alt="">' : '<em>não disponível</em>'; ?></div></div>
					<div><div class="muted">Logo (fundo escuro)</div><div class="preview dark"><?php echo Brand::wordmark_dark_url() ? '<img src="' . esc_url( Brand::wordmark_dark_url() ) . '" style="height:64px;width:auto;display:block" alt="">' : '<em style="color:#fff">não disponível</em>'; ?></div></div>
					<div><div class="muted">Ícone do aplicativo (215×215)</div><div class="preview"><?php echo Brand::icon_url() ? '<img src="' . esc_url( Brand::icon_url() ) . '" width="96" height="96" style="display:block" alt="">' : '<em>' . ( Brand::gd_available() ? 'não disponível' : 'GD ausente no servidor' ) . '</em>'; ?></div></div>
					<div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px">
							<?php wp_nonce_field( 'j365tp_setup_icon_style' ); ?><input type="hidden" name="action" value="j365tp_setup_icon_style">
							<label><input type="radio" name="icon_style" value="badge" <?php checked( Settings::get( 'icon_style', 'badge' ), 'badge' ); ?>> Selo "365"</label><br>
							<label><input type="radio" name="icon_style" value="full" <?php checked( Settings::get( 'icon_style', 'badge' ), 'full' ); ?>> Logo completo</label><br>
							<button class="button button-small" style="margin-top:6px">Aplicar estilo</button>
						</form>
						<?php if ( '' !== $source ) : ?>
							<a class="button" href="<?php echo esc_url( $this->url( 'brand_refresh' ) ); ?>">Atualizar pela URL</a>
							<p class="muted" style="margin:6px 0 0">Fonte: <code><?php echo esc_html( $source ); ?></code><?php echo ! empty( $brand['updated'] ) ? ' · ' . esc_html( $brand['updated'] ) . ' UTC' : ''; ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $brand['error'] ) ) : ?><p style="color:#b32d2e;margin:6px 0 0"><?php echo esc_html( $brand['error'] ); ?></p><?php endif; ?>
					</div>
				</div>
				<details style="margin-top:10px"><summary>Enviar arquivos manualmente</summary>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0">
						<?php wp_nonce_field( 'j365tp_setup_brand_upload' ); ?><input type="hidden" name="action" value="j365tp_setup_brand_upload">
						Logo (PNG transparente): <input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp"> <button class="button">Enviar logo</button>
					</form>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'j365tp_setup_icon_upload' ); ?><input type="hidden" name="action" value="j365tp_setup_icon_upload">
						Ícone quadrado próprio (PNG/JPG): <input type="file" name="icon_file" accept="image/png,image/jpeg"> <button class="button">Enviar ícone</button>
					</form>
				</details>
			</div>

		<?php
	}

	/* ------------------------------------------------------------ page */

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( Settings::per_tenant() ) {
			$this->render_per_tenant();
			return;
		}
		try {
			Client_Credential::ensure();
		} catch ( \Throwable $e ) {
			$cert_error = $e->getMessage();
		}
		$notice  = get_transient( 'j365tp_setup_notice_' . get_current_user_id() );
		delete_transient( 'j365tp_setup_notice_' . get_current_user_id() );
		$cred    = Client_Credential::state();
		$active  = Client_Credential::describe( Client_Credential::active() );
		$pending = Client_Credential::describe( Client_Credential::pending() );
		$done    = Settings::is_configured() && Client_Credential::has_active();
		$brand   = Brand::meta();
		$client  = Settings::client_id();
		$setup   = add_query_arg( 'j365_setup', '1', Request::portal_url() );
		?>
		<div class="wrap j365-setup">
			<?php $this->styles(); ?>
			<div class="hero">
				<?php if ( Brand::wordmark_url() ) : ?><img src="<?php echo esc_url( Brand::wordmark_url() ); ?>" alt="Jornada 365"><?php endif; ?>
				<div><h1 style="margin:0"><?php echo esc_html( Settings::APP_NAME ); ?> — Assistente</h1>
				<p class="muted" style="margin:4px 0 0">O registro do aplicativo é feito <strong>no próprio portal</strong>: abra o portal, clique em <em>Entrar com Microsoft 365</em> e entre com um Global Administrator do tenant da Jornada 365. O portal cria o <strong><?php echo esc_html( Settings::APP_NAME ); ?></strong> (multi-tenant, logo, certificado, permissões somente leitura), concede as permissões e já entra. Nenhum script, nenhum client secret.</p></div>
			</div>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $cert_error ) ) : ?>
				<div class="notice notice-error"><p>Certificado: <?php echo esc_html( $cert_error ); ?></p></div>
			<?php endif; ?>

			<div class="step">
				<h2><span class="num <?php echo $done ? 'ok' : ''; ?>">1</span> Registrar o aplicativo no Entra ID</h2>
				<?php if ( $done ) : ?>
					<p>Aplicativo registrado: <code><?php echo esc_html( $client ); ?></code> · <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Overview/appId/<?php echo esc_attr( $client ); ?>" target="_blank" rel="noopener">abrir no Entra</a></p>
					<p class="muted">Use <em>Atualizar o aplicativo</em> depois de mudar módulos/permissões, renovar o certificado ou trocar o logo.</p>
					<a class="cta" href="<?php echo esc_url( $setup ); ?>" target="_blank" rel="noopener">Atualizar o aplicativo pelo portal</a>
				<?php else : ?>
					<p>Abra o portal neste navegador (você já está logado no WordPress) e clique em <strong>Entrar com Microsoft 365</strong>.</p>
					<a class="cta" href="<?php echo esc_url( $setup ); ?>" target="_blank" rel="noopener">Abrir o portal e registrar</a>
					<?php if ( Settings::client_secret() ) : ?><p style="color:#b32d2e">Há um client secret legado salvo; ao registrar pelo portal ele é substituído pelo certificado e os secrets <code>wordpress-*</code> são removidos do app.</p><?php endif; ?>
				<?php endif; ?>
				<details style="margin-top:12px"><summary>Como o portal registra o aplicativo</summary>
					<ul style="list-style:disc;margin-left:22px">
						<li>Você entra na Microsoft pelo cliente público <em>Microsoft Graph Command Line Tools</em> (o mesmo do <code>Connect-MgGraph</code>), por código de dispositivo ou pelo navegador.</li>
						<li>Com essa autorização temporária o servidor cria/atualiza o App Registration multi-tenant, envia o certificado público e o logo, cria o Enterprise Application e concede as permissões (admin consent) no seu tenant.</li>
						<li>A autorização é cifrada durante a execução e descartada ao final. A chave privada do certificado nunca sai do servidor.</li>
						<li>Se o fluxo de código do dispositivo estiver bloqueado por Acesso Condicional, use a opção <em>Entrar pelo navegador</em> na mesma tela.</li>
					</ul>
				</details>
			</div>

			<?php $this->brand_block( '2', 'O ícone quadrado aparece na tela de aprovação da Microsoft e no Entra ID dos clientes. O portal usa o logo original em fundos claros e uma versão clara em fundos escuros (sem fundo branco).' ); ?>

			<div class="step">
				<h2><span class="num <?php echo $active ? 'ok' : ''; ?>">3</span> Certificado (substitui o client secret)</h2>
				<table class="kv">
					<?php if ( $active ) : ?>
						<tr><td>Ativo</td><td><code><?php echo esc_html( $active['thumbprint'] ); ?></code> · expira em <?php echo esc_html( $active['notAfter'] ); ?> (<?php echo (int) $active['daysLeft']; ?> dias) · <?php echo esc_html( $cred['alg'] ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $pending ) : ?>
						<tr><td><?php echo $active ? 'Renovação pendente' : 'Gerado'; ?></td><td><code><?php echo esc_html( $pending['thumbprint'] ); ?></code> · expira em <?php echo esc_html( $pending['notAfter'] ); ?> — <em>enviado ao Entra no próximo registro/atualização</em></td></tr>
					<?php endif; ?>
				</table>
				<p class="muted">RSA 2048, 2 anos. A chave privada fica cifrada neste servidor; só a parte pública vai para o App Registration.</p>
				<a class="button" href="<?php echo esc_url( $this->url( 'download_cert' ) ); ?>">Baixar certificado público (.cer)</a>
				<?php if ( $active ) : ?> <a class="button" href="<?php echo esc_url( $this->url( 'renew' ) ); ?>" onclick="return confirm('Gerar um novo certificado? Depois clique em Atualizar o aplicativo.')">Renovar certificado</a><?php endif; ?>
			</div>

			<div class="step">
				<h2><span class="num <?php echo $done && ! empty( $cred['last_test']['ok'] ) ? 'ok' : ''; ?>">4</span> Status</h2>
				<table class="kv">
					<tr><td>Tenant de origem</td><td><?php echo Settings::get( 'home_tenant_id' ) ? '<code>' . esc_html( (string) Settings::get( 'home_tenant_id' ) ) . '</code>' : '—'; ?></td></tr>
					<tr><td>Credencial</td><td><?php echo Client_Credential::has_active() ? '✅ certificado' : ( Settings::client_secret() ? '⚠️ client secret legado' : '—' ); ?></td></tr>
					<tr><td>Último teste</td><td><?php echo ! empty( $cred['last_test'] ) ? ( $cred['last_test']['ok'] ? '✅ ' : '❌ ' ) . esc_html( $cred['last_test']['message'] . ' (' . $cred['last_test']['at'] . ' UTC)' ) : '—'; ?></td></tr>
					<tr><td>Portal</td><td><a href="<?php echo esc_url( Request::portal_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Request::portal_url() ); ?></a></td></tr>
				</table>
				<?php if ( Settings::is_configured() ) : ?><a class="button" href="<?php echo esc_url( $this->url( 'test' ) ); ?>">Testar conexão</a><?php endif; ?>
				<?php if ( $done ) : ?><p class="muted">Na tela da Microsoft o app aparece como "não verificado" até a <a href="https://learn.microsoft.com/entra/identity-platform/publisher-verification-overview" target="_blank" rel="noopener">verificação de publisher</a>.</p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Per-tenant model: nothing to register — customers connect their own tenant in the portal. */
	private function render_per_tenant(): void {
		$notice = get_transient( 'j365tp_setup_notice_' . get_current_user_id() );
		delete_transient( 'j365tp_setup_notice_' . get_current_user_id() );
		$tenants   = Tenant_Repository::all();
		$sig       = Settings::app_signature();
		$connected = array_values( array_filter( $tenants, static fn( $t ) => '' !== Tenant_Repository::client_id( $t ) ) );
		$outdated  = array_values( array_filter( $connected, static fn( $t ) => (string) ( $t['settings']['app_signature'] ?? '' ) !== $sig ) );
		$redirect  = Spa_Auth::redirect_uri();
		$https     = str_starts_with( $redirect, 'https://' );
		$connect   = Spa_Auth::connect_url();
		$legacy    = '' !== Settings::client_id() || Client_Credential::active() || Client_Credential::pending() || '' !== (string) Settings::get( 'client_secret_enc', '' );
		$last      = Client_Credential::state()['last_test'];
		$graph     = Settings::graph_scopes();
		$exchange  = Settings::exchange_scopes();
		?>
		<div class="wrap j365-setup">
			<?php $this->styles(); ?>
			<style>
				.j365-setup .chips{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}
				.j365-setup .chips code{background:#f0f6fc;border:1px solid #c5d9ed;border-radius:999px;padding:2px 9px;font-size:12px}
				.j365-setup .chips code.ex{background:#fff4e5;border-color:#f0d3a8}
				.j365-setup .copy{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:6px 0 2px}
				.j365-setup .copy code{font-size:13px;padding:6px 10px;border-radius:8px;background:#f6f7f7;border:1px solid #dcdcde;word-break:break-all}
				.j365-setup table.widefat{max-width:1080px}
				.j365-setup .tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;background:#edfaef;color:#00631d;border:1px solid #b8e6c1}
				.j365-setup .tag.warn{background:#fcf9e8;color:#7a5b00;border-color:#f0e0a8}
				.j365-setup .tag.off{background:#fcf0f1;color:#8a1f1f;border-color:#f1c3c6}
			</style>
			<div class="hero">
				<?php if ( Brand::wordmark_url() ) : ?><img src="<?php echo esc_url( Brand::wordmark_url() ); ?>" alt="Jornada 365"><?php endif; ?>
				<div><h1 style="margin:0"><?php echo esc_html( Settings::APP_NAME ); ?> — Assistente</h1>
				<p class="muted" style="margin:4px 0 0"><strong>Modelo por tenant.</strong> Não existe aplicativo no tenant da Jornada 365 e não há nada para registrar aqui: cada cliente conecta o próprio tenant pelo portal e o aplicativo <strong><?php echo esc_html( Settings::APP_NAME ); ?></strong> é criado <strong>dentro do tenant do cliente</strong> — aplicativo de página única, permissões delegadas, sem segredo e sem certificado.</p></div>
			</div>
			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $https ) : ?>
				<div class="notice notice-error"><p>O endereço de retorno do login precisa ser HTTPS (<code><?php echo esc_html( $redirect ); ?></code>). Atrás do Cloudflare/proxy, configure <code>HTTP_X_FORWARDED_PROTO</code> no <code>wp-config.php</code>.</p></div>
			<?php endif; ?>

			<div class="step">
				<h2><span class="num ok">✓</span> Como os clientes conectam</h2>
				<p>Um administrador do cliente abre o portal, informa o e-mail em <em>Entrar com Microsoft 365</em> e clica em <em>Conectar o tenant</em> — ou abre direto o link de conexão:</p>
				<div class="copy"><code id="j365-connect"><?php echo esc_html( $connect ); ?></code> <button type="button" class="button" data-copy="j365-connect">Copiar</button></div>
				<p class="muted" style="margin-top:8px">Quem pode conectar: <?php echo esc_html( implode( ', ', array_values( Settings::CONNECT_ROLES ) ) ); ?>. Depois de conectado, qualquer conta com uma das funções autorizadas em <a href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp-settings' ) ); ?>">Configurações</a> entra com o aplicativo do próprio tenant.</p>
				<details style="margin-top:10px"><summary>Como funciona a conexão</summary>
					<ul style="list-style:disc;margin-left:22px">
						<li>O administrador entra pelo cliente público oficial <em>Microsoft Graph Command Line Tools</em> (o mesmo do <code>Connect-MgGraph</code>), por código de dispositivo ou pelo navegador, com apenas <code>Application.ReadWrite.All</code> e <code>DelegatedPermissionGrant.ReadWrite.All</code>.</li>
						<li>Com essa autorização temporária (cifrada com uma chave que só o navegador dele completa, descartada no final) o portal cria no tenant do cliente: o registro de aplicativo <em>single-tenant</em> com o endereço de retorno abaixo, o logo, o aplicativo empresarial e a aprovação das permissões delegadas para a organização.</li>
						<li>O servidor guarda apenas <strong>ID do tenant → ID do aplicativo</strong> (identificadores públicos). Os tokens de login ficam no navegador de quem entra; o servidor só os recebe a cada chamada, em memória.</li>
						<li>Se o código de dispositivo estiver bloqueado por Acesso Condicional, o assistente oferece <em>Entrar pelo navegador</em> e também a <em>criação manual</em> no Entra ID.</li>
						<li>Para desconectar, o cliente exclui o aplicativo <?php echo esc_html( Settings::APP_NAME ); ?> no Entra ID dele. Para bloquear um tenant pelo lado do portal, use <a href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp-tenants' ) ); ?>">Tenants → Revogar</a>.</li>
					</ul>
				</details>
			</div>

			<div class="step">
				<h2><span class="num <?php echo $https ? 'ok' : ''; ?>"><?php echo $https ? '✓' : '!'; ?></span> O que é criado no tenant do cliente</h2>
				<table class="kv">
					<tr><td>Aplicativo</td><td><strong><?php echo esc_html( Settings::APP_NAME ); ?></strong> · somente este diretório (<code>AzureADMyOrg</code>) · tag <code><?php echo esc_html( Onboarding::TAG ); ?></code> · sem segredo e sem certificado</td></tr>
					<tr><td>Retorno do login (SPA)</td><td><div class="copy" style="margin:0"><code id="j365-redirect"><?php echo esc_html( $redirect ); ?></code> <button type="button" class="button button-small" data-copy="j365-redirect">Copiar</button></div></td></tr>
					<tr><td>Microsoft Graph (delegadas)</td><td><div class="chips"><?php foreach ( $graph as $sc ) : ?><code><?php echo esc_html( $sc ); ?></code><?php endforeach; ?></div></td></tr>
					<?php if ( $exchange ) : ?><tr><td>Exchange Online (delegada)</td><td><div class="chips"><?php foreach ( $exchange as $sc ) : ?><code class="ex"><?php echo esc_html( $sc ); ?></code><?php endforeach; ?></div></td></tr><?php endif; ?>
					<tr><td>Login</td><td><code><?php echo esc_html( implode( ' ', Settings::OIDC_SCOPES ) ); ?></code></td></tr>
				</table>
				<p class="muted">Permissões delegadas: o portal enxerga só o que a conta de quem entra pode ver, sempre com a MFA e o Acesso Condicional do tenant do cliente. As permissões vêm dos <a href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp-settings' ) ); ?>">módulos habilitados</a>; ao mudar os módulos, o portal mostra aos clientes o aviso <em>Atualizar o aplicativo</em> (1 clique de um administrador).</p>
			</div>

			<?php $this->brand_block( '✓', 'O logo é enviado ao aplicativo de cada tenant na conexão (aparece no Entra ID e na tela de login da Microsoft). O portal usa o logo original em fundos claros e uma versão clara em fundos escuros.' ); ?>

			<div class="step">
				<h2><span class="num <?php echo $connected ? 'ok' : ''; ?>"><?php echo (int) count( $connected ); ?></span> Tenants conectados</h2>
				<?php if ( ! $connected ) : ?>
					<p class="muted">Nenhum tenant conectado ainda. Envie o link de conexão para o administrador do cliente.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr><th>Tenant ID</th><th>Aplicativo (client ID)</th><th>Conexão</th><th>Conectado em</th><th>Último acesso</th><th>Situação</th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $connected, 0, 25 ) as $t ) : ?>
							<?php $old = (string) ( $t['settings']['app_signature'] ?? '' ) !== $sig; ?>
							<tr>
								<td><code><?php echo esc_html( $t['tenant_id'] ); ?></code></td>
								<td><code><?php echo esc_html( Tenant_Repository::client_id( $t ) ); ?></code></td>
								<td><?php echo 'manual' === ( $t['settings']['app_mode'] ?? '' ) ? 'manual (Entra ID)' : 'automática'; ?></td>
								<td><?php echo esc_html( (string) ( $t['settings']['app_at'] ?? $t['consented_at'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( ! empty( $t['last_login_at'] ) ? substr( (string) $t['last_login_at'], 0, 10 ) : '—' ); ?></td>
								<td><?php if ( 'revoked' === $t['status'] ) : ?><span class="tag off">revogado</span><?php elseif ( $old ) : ?><span class="tag warn">permissões desta versão pendentes</span><?php else : ?><span class="tag">ativo</span><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $outdated ) : ?><p class="muted"><?php echo (int) count( $outdated ); ?> tenant(s) conectados antes da mudança de permissões: os administradores veem o aviso <em>Atualizar o aplicativo</em> no portal. Os relatórios que dependem das novas permissões ficam com cadeado até lá.</p><?php endif; ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp-tenants' ) ); ?>">Gerenciar tenants (revogar, reativar, remover)</a></p>
				<?php endif; ?>
			</div>

			<div class="step">
				<h2><span class="num <?php echo ! empty( $last['ok'] ) ? 'ok' : ''; ?>"><?php echo ! empty( $last['ok'] ) ? '✓' : '?'; ?></span> Status</h2>
				<table class="kv">
					<tr><td>Modelo</td><td>Por tenant (aplicativo no tenant de cada cliente)</td></tr>
					<tr><td>Último teste</td><td><?php echo ! empty( $last ) ? ( $last['ok'] ? '✅ ' : '❌ ' ) . esc_html( $last['message'] . ' (' . $last['at'] . ' UTC)' ) : '—'; ?></td></tr>
					<tr><td>Portal</td><td><a href="<?php echo esc_url( Request::portal_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Request::portal_url() ); ?></a></td></tr>
				</table>
				<a class="button" href="<?php echo esc_url( $this->url( 'test' ) ); ?>">Testar conexão com a Microsoft</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=j365tp-diag' ) ); ?>">Diagnóstico completo</a>
			</div>

			<?php if ( $legacy ) : ?>
			<div class="step">
				<h2><span class="num">!</span> Aplicativo do modelo anterior</h2>
				<p>Este servidor ainda guarda o aplicativo multi-tenant do modelo anterior<?php echo '' !== Settings::client_id() ? ': <code>' . esc_html( Settings::client_id() ) . '</code>' : ''; ?><?php echo '' !== (string) Settings::get( 'home_tenant_id', '' ) ? ' (tenant <code>' . esc_html( (string) Settings::get( 'home_tenant_id', '' ) ) . '</code>)' : ''; ?>. Ele <strong>não é mais usado</strong>.</p>
				<ol style="margin-left:22px">
					<li>Clique em <em>Esquecer o aplicativo anterior</em>: o Client ID, o certificado (chave privada cifrada) e um eventual client secret são apagados deste servidor.</li>
					<li>No Entra ID do tenant da Jornada 365: <em>Registros de aplicativo → <?php echo esc_html( Settings::APP_NAME ); ?> → Excluir</em><?php echo '' !== Settings::client_id() ? ' (<a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/Overview/appId/' . esc_attr( Settings::client_id() ) . '" target="_blank" rel="noopener">abrir no Entra</a>)' : ''; ?>.</li>
					<li>Clientes que aprovaram o aplicativo antigo podem excluir o aplicativo empresarial antigo no tenant deles; ao conectar de novo, o portal cria o aplicativo novo dentro do tenant.</li>
				</ol>
				<a class="button" href="<?php echo esc_url( $this->url( 'forget_legacy' ) ); ?>" onclick="return confirm('Apagar deste servidor o Client ID, o certificado e o secret do aplicativo anterior? Esta ação não pode ser desfeita.')">Esquecer o aplicativo anterior</a>
			</div>
			<?php endif; ?>
			<script>
				document.querySelectorAll('.j365-setup [data-copy]').forEach(function (b) {
					b.addEventListener('click', function () {
						var el = document.getElementById(b.getAttribute('data-copy'));
						if (!el || !navigator.clipboard) return;
						navigator.clipboard.writeText(el.textContent.trim()).then(function () { var t = b.textContent; b.textContent = 'Copiado'; setTimeout(function () { b.textContent = t; }, 1500); });
					});
				});
			</script>
		</div>
		<?php
	}
}
