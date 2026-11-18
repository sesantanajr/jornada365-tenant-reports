<?php
namespace J365BR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bridge_Admin {

	private const CAP = 'manage_options';
	private const NONCE = 'j365br_manage_diagnostic_token';
	private const URL = 'admin.php?page=j365br';

	private ?string $issued = null;
	private ?string $error = null;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
	}

	public function menu(): void {
		$hook = add_menu_page( 'Jornada 365 Bridge', 'J365 Bridge', self::CAP, 'j365br', [ $this, 'page' ], 'dashicons-rest-api', 59 );
		add_action( 'load-' . $hook, [ $this, 'handle_token' ] );
	}

	public function handle_token(): void {
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! is_ssl() ) {
			wp_die( 'A administração do token exige permissão e HTTPS.', '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );

		$action = $_POST['j365br_action'] ?? '';
		if ( 'revoke' === $action ) {
			if ( ! Bridge_Token::revoke() ) {
				$this->error = 'O WordPress não conseguiu revogar o token. Tente novamente.';
				return;
			}
			wp_safe_redirect( admin_url( self::URL ), 303 );
			exit;
		}
		if ( 'issue' !== $action ) {
			wp_die( 'Ação inválida.', '', [ 'response' => 400 ] );
		}

		$ttl = $_POST['j365br_ttl'] ?? '';
		$ttl = is_string( $ttl ) && 1 === preg_match( '/\A[0-9]{1,2}\z/', $ttl ) ? (int) $ttl : 0;
		if ( $ttl < 1 || $ttl > 24 ) {
			$this->error = 'Informe uma validade de 1 a 24 horas.';
			return;
		}

		$result = Bridge_Token::issue( $ttl );
		if ( is_wp_error( $result ) ) {
			$this->error = $result->get_error_message();
		} else {
			$this->issued = $result;
		}
	}

	public function page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Acesso negado.', '', [ 'response' => 403 ] );
		}

		$portal = Bridge_Ops::portal_status();
		$status = $portal['installed'] ? ( $portal['active'] ? 'Ativo' : 'Instalado, inativo' ) : 'Não instalado';
		$expires = Bridge_Token::expires();
		?>
		<div class="wrap">
			<h1>Jornada 365 · Bridge</h1>
			<p>Consulte a disponibilidade e a versão do Tenant Portal por HTTPS. A instalação e a ativação usam os controles de plugins do WordPress.</p>
			<?php if ( $this->error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $this->error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->issued ) : ?>
				<div class="notice notice-success">
					<p><strong>Copie o token agora.</strong> Ele só aparece nesta resposta.</p>
					<p><code><?php echo esc_html( $this->issued ); ?></code></p>
				</div>
			<?php endif; ?>

			<h2>Tenant Portal</h2>
			<p><?php echo esc_html( $status ); ?><?php if ( $portal['version'] ) : ?> · <?php echo esc_html( $portal['version'] ); ?><?php endif; ?></p>
			<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Gerenciar plugins instalados</a></p>
			<?php endif; ?>
			<?php if ( current_user_can( 'upload_plugins' ) ) : ?>
				<p><a href="<?php echo esc_url( self_admin_url( 'plugin-install.php?tab=upload' ) ); ?>">Enviar o ZIP pelo WordPress</a></p>
			<?php endif; ?>

			<h2>Token de diagnóstico</h2>
			<p><?php echo $expires > time() ? esc_html( 'Válido até ' . wp_date( 'd/m/Y H:i T', $expires ) ) : 'Sem token ativo.'; ?></p>
			<p>Use o cabeçalho <code>Authorization: Bearer &lt;token&gt;</code> em <code><?php echo esc_html( rest_url( Bridge_Rest::NS . '/ping' ) ); ?></code> ou <code><?php echo esc_html( rest_url( Bridge_Rest::NS . '/env' ) ); ?></code>.</p>
			<?php if ( is_ssl() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( self::URL ) ); ?>">
					<?php wp_nonce_field( self::NONCE ); ?>
					<p><label for="j365br_ttl">Validade em horas</label> <input type="number" id="j365br_ttl" name="j365br_ttl" min="1" max="24" value="1" required></p>
					<p>Gerar um token invalida o anterior.</p>
					<button class="button button-primary" type="submit" name="j365br_action" value="issue">Gerar token</button>
					<?php if ( $expires > time() ) : ?>
						<button class="button" type="submit" name="j365br_action" value="revoke" formnovalidate>Revogar token</button>
					<?php endif; ?>
				</form>
			<?php else : ?>
				<p>Abra o painel em HTTPS para gerenciar o token. Se o navegador já usa HTTPS, peça à hospedagem que confirme se o WordPress detecta a conexão segura.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
