<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end routes under /j365/… (rewrite rules) with a ?j365_route= fallback.
 *
 *  /j365/auth/login/        → start OIDC sign-in
 *  /j365/auth/callback/     → OIDC redirect URI
 *  /j365/auth/logout/
 *  /j365/consent/start/     → admin consent
 *  /j365/consent/callback/  → admin consent redirect URI
 */
final class Router {

	public const QUERY_VAR = 'j365_route';

	public function register(): void {
		add_action( 'init', [ $this, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'dispatch' ], 0 );
		add_action( 'send_headers', [ $this, 'security_headers' ] );
	}

	public function add_rules(): void {
		add_rewrite_rule( '^j365/(auth|consent)/([a-z]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]/$matches[2]', 'top' );
	}

	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		$route = get_query_var( self::QUERY_VAR, '' );
		if ( '' !== $route ) {
			header( 'Cache-Control: no-store, max-age=0' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: DENY' );
		}
	}

	public function dispatch(): void {
		$route = (string) get_query_var( self::QUERY_VAR, '' );
		if ( '' === $route && isset( $_GET[ self::QUERY_VAR ] ) ) {
			$route = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		}
		if ( '' === $route ) {
			return;
		}
		// Per-visitor responses (OIDC state, session, language): never stored by page caches.
		Page_Cache::bypass();
		I18n::current(); // resolve (and persist ?lang=) before any output

		// Per-tenant model: sign-in happens in the browser with the tenant's own app; the classic routes
		// only lead there (old links and bookmarks keep working).
		if ( Settings::per_tenant() ) {
			$hint = sanitize_text_field( wp_unslash( $_GET['login_hint'] ?? '' ) );
			$t    = strtolower( sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ) );
			$t    = preg_match( '/^[0-9a-f-]{36}$/', $t ) ? $t : '';
			switch ( $route ) {
				case 'auth/spa':
					Spa_Auth::render_page();
					break;
				case 'auth/problem':
					Spa_Auth::render_problem();
					break;
				case 'auth/login':
					wp_safe_redirect( Spa_Auth::signin_url( [ 't' => $t, 'login_hint' => is_email( $hint ) ? $hint : '' ] ) );
					exit;
				case 'consent/start':
					// Old "approve the app" links carried ?t=<tenant ID or domain>.
					$raw = strtolower( sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ) );
					$dom = '' === $t && preg_match( '/^(?=.{3,253}$)[a-z0-9-]+(\.[a-z0-9-]+)+$/', $raw ) ? $raw : '';
					wp_safe_redirect( '' !== $dom ? add_query_arg( 'd', $dom, Spa_Auth::connect_url() ) : Spa_Auth::connect_url( $t ) );
					exit;
				case 'auth/callback':
				case 'auth/action':
				case 'consent/callback':
					wp_safe_redirect( Request::portal_url() );
					exit;
			}
		}

		switch ( $route ) {
			case 'auth/login':
				Oidc::start(
					sanitize_text_field( wp_unslash( $_GET['login_hint'] ?? '' ) ),
					sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) )
				);
				break;
			case 'auth/callback':
				Oidc::callback();
				break;
			case 'auth/logout':
				Oidc::logout();
				break;
			case 'auth/action':
				Action_Auth::start( sanitize_key( wp_unslash( $_GET['cap'] ?? '' ) ) );
				break;
			case 'consent/start':
				Consent::start( sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) ) );
				break;
			case 'consent/callback':
				Consent::callback();
				break;
			default:
				status_header( 404 );
				self::render_message( 'Rota inexistente', 'A rota solicitada não existe.', 'error', 404 );
		}
		exit;
	}


	/* ------------------------------------------------------------------ */
	/* Standalone pages for the auth/consent flows (same design as the app) */
	/* ------------------------------------------------------------------ */

	public const ICONS = [
		'shield' => '<path d="M12 3 4.5 6v6c0 4.4 3.2 8.2 7.5 9 4.3-.8 7.5-4.6 7.5-9V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
		'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-3.8 4.6-5.8 8-5.8s6.4 2 8 5.8"/>',
		'check'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16.5 9.5"/>',
		'alert'  => '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4.5M12 17.5v.01"/>',
		'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
		'link'   => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
		'lock'   => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'cloud'  => '<path d="M7 18h10a4 4 0 0 0 .7-7.9A6 6 0 0 0 6.2 9.2 4.5 4.5 0 0 0 7 18z"/><path d="M12 11v3M12 16.5v.01"/>',
		'ok'     => '<path d="m6.5 12.5 3.6 3.6L17.5 8.7"/>',
		'x'      => '<path d="m7.5 7.5 9 9M16.5 7.5l-9 9"/>',
		'warn'   => '<path d="M12 7v6.5M12 16.8v.01"/>',
		'q'      => '<path d="M9.6 9.2a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.4M12 16.8v.01"/>',
		'copy'   => '<rect x="8.5" y="8.5" width="11" height="11" rx="2.5"/><path d="M15.5 8.5V6.5a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h2"/>',
		'mail'   => '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7 7.5 6 7.5-6"/>',
		'badge'  => '<path d="M12 3.5 5.5 6v5.3c0 4 2.8 7.6 6.5 8.7 3.7-1.1 6.5-4.7 6.5-8.7V6z"/>',
		'ext'    => '<path d="M14 5h5v5M19 5l-8 8"/><path d="M18 14v4a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 5 18V8a1.5 1.5 0 0 1 1.5-1.5H10"/>',
	];

	public static function icon( string $name, string $class = 'ico' ): string {
		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( self::ICONS[ $name ] ?? '' ) . '</svg>';
	}

	/** Jornada 365 logo with light/dark variants (no white plate). */
	public static function brand_html(): string {
		$light = Brand::wordmark_url();
		$dark  = Brand::wordmark_dark_url() ?: $light;
		$logo  = '' !== $light
			? '<span class="logo sp-logo"><img class="logo-light" src="' . esc_url( $light ) . '" alt="Jornada 365"><img class="logo-dark" src="' . esc_url( $dark ) . '" alt="" aria-hidden="true"></span>'
			: '<span class="logo logo-fallback sp-logo"><b>JORNADA</b><em>365</em></span>';
		return '<a class="brand" href="' . esc_url( Request::portal_url() ) . '">' . $logo . '<span class="brand-chip">Tenant</span></a>';
	}

	public static function permission_groups_html( array $missing = [] ): string {
		$html = '<div class="perm">';
		foreach ( Settings::permission_groups() as $g ) {
			$html .= '<div class="perm-group"><div class="perm-title">' . esc_html( I18n::t( $g['label'] ) ) . '</div><ul>';
			foreach ( $g['permissions'] as $p ) {
				$miss  = in_array( $p['name'], $missing, true );
				$html .= '<li' . ( $miss ? ' class="miss"' : '' ) . '><code>' . esc_html( $p['name'] ) . ( $miss ? ' <em>' . esc_html( I18n::t( 'pendente' ) ) . '</em>' : '' ) . '</code><span>' . esc_html( I18n::t( $p['why'] ) ) . '</span></li>';
			}
			$html .= '</ul></div>';
		}
		return $html . '</div>';
	}

	/* ---------------- public renderers (send + exit) ---------------- */

	public static function render_message( string $title, string $message, string $type = 'info', int $status = 200 ): void {
		self::send( self::message_html( $title, $message, $type ), $status );
	}

	/**
	 * Structured "why can't I get in" page (see Access_Problem). Logged with an incident id that is
	 * also shown to the visitor, so support can find the event without any personal data in the URL.
	 */
	public static function render_problem( string $code, array $ctx = [], int $status = 403 ): void {
		$ctx['incident'] = (string) ( $ctx['incident'] ?? Access_Problem::incident() );
		if ( empty( $ctx['_nolog'] ) ) {
			Audit::log( 'access_problem', strtolower( (string) ( $ctx['tenant_id'] ?? '' ) ), (string) ( $ctx['upn'] ?? '' ), [ 'code' => $code, 'incident' => $ctx['incident'], 'ms' => mb_substr( (string) ( $ctx['ms_code'] ?? '' ), 0, 60 ) ] );
		}
		self::send( self::problem_html( Access_Problem::build( $code, $ctx ) ), $status );
	}

	public static function render_consent_required( string $tenant_id, string $upn, string $tenant_status, bool $signed_in = true ): void {
		self::send( self::consent_required_html( $tenant_id, $upn, $tenant_status ), 200 );
	}

	public static function render_consent_done( string $tenant_id, bool $token_ok, string $name = '' ): void {
		self::send( self::consent_done_html( $tenant_id, $name, $token_ok ), 200 );
	}

	/** Neutral result for the shared-link consent flow: reveals nothing about any tenant. */
	public static function render_consent_link_done(): void {
		self::send( self::consent_link_done_html(), 200 );
	}

	/* ---------------- HTML builders (pure, testable) ---------------- */

	private static function t( string $s, array $vars = [] ): string {
		return I18n::t( $s, $vars );
	}

	/** Translates, escapes and fills {placeholders} (values in bold unless $plain). */
	private static function fmt( string $text, array $vars, bool $plain = false ): string {
		$out = esc_html( self::t( $text ) );
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{' . $k . '}', $plain ? esc_html( (string) $v ) : '<strong>' . esc_html( (string) $v ) . '</strong>', $out );
		}
		return $out;
	}

	/** Plain translated text with {placeholders} filled (for mailto, attributes, titles). */
	private static function plain( string $text, array $vars ): string {
		$out = self::t( $text );
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{' . $k . '}', (string) $v, $out );
		}
		return $out;
	}

	public static function problem_html( array $vm ): string {
		$vars  = (array) $vm['vars'];
		$state = [ 'ok' => [ 'ok', 'Verificado' ], 'fail' => [ 'x', 'Pendente' ], 'warn' => [ 'warn', 'Atenção' ], 'pending' => [ 'clock', 'Aguardando' ], 'unknown' => [ 'q', 'A verificar' ] ];
		$html  = '<p class="lead">' . self::fmt( (string) $vm['summary'], $vars ) . '</p>';

		if ( $vm['checks'] ) {
			$html .= '<section class="sp-sec"><h2 class="sp-h">' . esc_html( self::t( 'Verificação do acesso' ) ) . '</h2><ol class="ck-list">';
			foreach ( $vm['checks'] as $c ) {
				[ $ic, $sr ] = $state[ $c['state'] ] ?? $state['unknown'];
				$list = '';
				if ( ! empty( $c['list'] ) ) {
					$list = '<span class="ck-chips">' . implode( '', array_map( static fn( $x ) => '<code>' . esc_html( (string) $x ) . '</code>', (array) $c['list'] ) ) . '</span>';
				}
				$html .= '<li class="ck ' . esc_attr( $c['state'] ) . '"><span class="ck-i" title="' . esc_attr( self::t( $sr ) ) . '">' . self::icon( $ic ) . '<span class="sr">' . esc_html( self::t( $sr ) ) . '</span></span>'
					. '<div class="ck-t"><b>' . esc_html( self::t( (string) $c['label'] ) ) . '</b><span>' . self::fmt( (string) $c['detail'], $vars, true ) . '</span>' . $list . '</div></li>';
			}
			$html .= '</ol></section>';
		}

		if ( $vm['steps'] || $vm['why'] || $vm['who'] || $vm['chips'] ) {
			$html .= '<section class="sp-sec sp-fix"><h2 class="sp-h">' . esc_html( self::t( 'Como resolver' ) ) . '</h2>';
			if ( '' !== (string) $vm['why'] ) {
				$html .= '<p class="sp-why">' . self::fmt( (string) $vm['why'], $vars ) . '</p>';
			}
			if ( $vm['steps'] ) {
				$html .= '<ol class="fix-steps">' . implode( '', array_map( fn( $st ) => '<li>' . self::fmt( (string) $st, $vars ) . '</li>', (array) $vm['steps'] ) ) . '</ol>';
			}
			if ( $vm['who'] ) {
				$html .= '<div class="who"><span class="who-l">' . esc_html( self::t( 'Quem pode resolver' ) ) . '</span>'
					. implode( '', array_map( fn( $w ) => '<span class="role-chip">' . self::icon( 'badge' ) . esc_html( self::t( (string) $w ) ) . '</span>', (array) $vm['who'] ) ) . '</div>';
			}
			if ( $vm['chips'] ) {
				$html .= '<div class="who accepted"><span class="who-l">' . esc_html( self::t( (string) $vm['chipsLabel'] ) ) . '</span>'
					. implode( '', array_map( static fn( $w ) => '<span class="role-chip soft">' . esc_html( (string) $w ) . '</span>', (array) $vm['chips'] ) ) . '</div>';
			}
			$html .= '</section>';
		}

		// Share box (approval link) + buttons.
		$buttons = '';
		$share   = '';
		foreach ( (array) $vm['actions'] as $a ) {
			$label = esc_html( self::t( (string) $a['label'] ) );
			if ( 'copy' === $a['kind'] ) {
				$share .= '<div class="share"><label for="' . esc_attr( $a['id'] ) . '">' . esc_html( self::t( 'Link de aprovação para o administrador' ) ) . '</label><div class="copy-row"><input id="' . esc_attr( $a['id'] ) . '" type="text" readonly value="' . esc_attr( (string) $a['value'] ) . '"><button class="btn-solid" type="button" data-copy="' . esc_attr( $a['id'] ) . '" data-done="' . esc_attr( self::t( 'Copiado ✓' ) ) . '">' . self::icon( 'copy' ) . $label . '</button></div></div>';
			} elseif ( 'mailto' === $a['kind'] ) {
				$mv       = $vars + [ 'link' => (string) $a['link'] ];
				$subject  = self::plain( (string) $a['subject'], $mv );
				$body     = self::plain( (string) $a['body'], $mv );
				$buttons .= '<a class="btn-ghost" href="mailto:?subject=' . rawurlencode( $subject ) . '&amp;body=' . rawurlencode( $body ) . '">' . self::icon( 'mail' ) . $label . '</a>';
			} else {
				$ext      = ! empty( $a['target'] );
				$buttons .= '<a class="' . ( 'solid' === ( $a['style'] ?? '' ) ? 'btn-solid' : 'btn-ghost' ) . '" href="' . esc_url( (string) $a['href'] ) . '"' . ( $ext ? ' target="_blank" rel="noopener"' : '' ) . '>' . $label . ( $ext ? self::icon( 'ext' ) : '' ) . '</a>';
			}
		}
		$html .= $share;

		if ( $vm['retry'] ) {
			$html .= '<div class="note">' . str_replace( '{s}', '<strong><span id="j365-countdown" data-seconds="' . (int) $vm['retry'] . '" data-url="' . esc_attr( Request::login_url() ) . '">' . (int) $vm['retry'] . '</span>s</strong>', esc_html( self::t( 'Nova tentativa automática em {s}.' ) ) ) . '</div>';
		}
		if ( $vm['perms'] ) {
			$html .= '<details class="disclosure"><summary>' . esc_html( self::t( 'O que o administrador vai aprovar' ) ) . '</summary><div class="disclosure-body">' . self::permission_groups_html( (array) $vm['missing'] )
				. '<p class="fine">' . str_replace( '{app}', '<strong>' . esc_html( Settings::APP_NAME ) . '</strong>', esc_html( self::t( 'Os relatórios consultam dados. Os módulos de ação podem solicitar permissões adicionais. O administrador pode revogar o acesso de {app} no Microsoft Entra ID.' ) ) ) . '</p></div></details>';
		}
		$rows = '';
		$text = [];
		foreach ( (array) $vm['tech'] as [ $k, $v ] ) {
			$rows  .= '<dt>' . esc_html( self::t( (string) $k ) ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
			$text[] = self::t( (string) $k ) . ': ' . $v;
		}
		$html .= '<details class="disclosure tech"><summary>' . esc_html( self::t( 'Detalhes técnicos (para o suporte)' ) ) . '</summary><div class="disclosure-body"><dl class="tech-dl">' . $rows . '</dl>'
			. '<textarea id="j365-tech" class="sr" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( implode( "\n", $text ) ) . '</textarea><button class="btn-ghost sm" type="button" data-copy="j365-tech" data-done="' . esc_attr( self::t( 'Copiado ✓' ) ) . '">' . self::icon( 'copy' ) . esc_html( self::t( 'Copiar detalhes' ) ) . '</button></div></details>';

		return self::layout( self::plain( (string) $vm['title'], $vars ), (string) $vm['icon'], (string) $vm['tone'], $html, $buttons, [ 'eyebrow' => (string) $vm['eyebrow'], 'wide' => true, 'code' => (string) $vm['code'] ] );
	}

	public static function message_html( string $title, string $message, string $type = 'info' ): string {
		$icon    = [ 'error' => 'alert', 'ok' => 'check', 'info' => 'info' ][ $type ] ?? 'info';
		$actions = 'error' === $type
			? '<a class="btn-solid" href="' . esc_url( Request::login_url() ) . '">' . esc_html( self::t( 'Tentar novamente' ) ) . '</a><a class="btn-ghost" href="' . esc_url( Request::portal_url() ) . '">' . esc_html( self::t( 'Voltar ao início' ) ) . '</a>'
			: '<a class="btn-solid" href="' . esc_url( Request::portal_url() ) . '">' . esc_html( self::t( 'Voltar ao início' ) ) . '</a>';
		return self::layout( self::t( $title ), $icon, $type, '<p class="lead">' . esc_html( self::t( $message ) ) . '</p>', $actions );
	}

	public static function consent_required_html( string $tenant_id, string $upn, string $tenant_status ): string {
		$share_url = add_query_arg( [ 't' => $tenant_id ], Request::route_url( 'consent/start' ) );
		$app       = '<strong>' . esc_html( Settings::APP_NAME ) . '</strong>';
		$lead      = 'disconnected' === $tenant_status
			? self::t( 'O {app} foi removido do tenant da sua organização (o aplicativo empresarial foi excluído ou a aprovação revogada). Um administrador precisa aprová-lo novamente para voltar a usar o portal.' )
			: self::t( 'O {app} ainda não foi instalado no tenant da sua organização. Um administrador precisa aprová-lo uma única vez — depois disso, todos os administradores e leitores entram direto.' );
		$hello = self::t( 'Olá, {upn}.' );

		$body = '<p class="lead">' . str_replace( '{upn}', '<strong>' . esc_html( $upn ) . '</strong>', esc_html( $hello ) ) . ' ' . str_replace( '{app}', $app, esc_html( $lead ) ) . '</p>'
			. '<div class="choice-grid">'
			. '<section class="choice"><div class="choice-head">' . self::icon( 'link' ) . '<h2>' . esc_html( self::t( 'Envie para um administrador' ) ) . '</h2></div>'
			. '<p>' . esc_html( self::t( 'Um Global Administrator ou Privileged Role Administrator abre este link, revisa as permissões na tela da Microsoft e aprova.' ) ) . '</p>'
			. '<div class="copy-row"><input id="j365-share" type="text" readonly value="' . esc_attr( $share_url ) . '" aria-label="' . esc_attr( self::t( 'Link de aprovação para o administrador' ) ) . '"><button class="btn-ghost" type="button" data-copy="j365-share" data-done="' . esc_attr( self::t( 'Copiado ✓' ) ) . '">' . esc_html( self::t( 'Copiar link' ) ) . '</button></div></section>'
			. '<section class="choice"><div class="choice-head">' . self::icon( 'shield' ) . '<h2>' . esc_html( self::t( 'Você é administrador?' ) ) . '</h2></div>'
			. '<p>' . esc_html( self::t( 'Se a sua função foi ativada agora pelo PIM ou você tem outra conta de administrador, aprove diretamente.' ) ) . '</p>'
			. '<a class="btn-solid" href="' . esc_url( $share_url ) . '">' . esc_html( self::t( 'Aprovar com conta de administrador' ) ) . '</a></section>'
			. '</div>'
			. '<details class="disclosure"><summary>' . esc_html( self::t( 'O que o administrador vai aprovar' ) ) . '</summary><div class="disclosure-body">' . self::permission_groups_html()
			. '<p class="fine">' . str_replace( '{app}', $app, esc_html( self::t( 'Os relatórios consultam dados. Os módulos de ação podem solicitar permissões adicionais. O administrador pode revogar o acesso de {app} no Microsoft Entra ID.' ) ) ) . '</p></div></details>'
			. '<p class="tenant-line">' . esc_html( self::t( 'Tenant' ) ) . ': <code>' . esc_html( $tenant_id ) . '</code></p>';

		$actions = '<a class="btn-ghost" href="' . esc_url( Request::login_url() ) . '">' . esc_html( self::t( 'Entrar com outra conta' ) ) . '</a><a class="btn-ghost" href="' . esc_url( Request::portal_url() ) . '">' . esc_html( self::t( 'Voltar ao início' ) ) . '</a>';
		return self::layout( self::t( 'Aprovação do administrador necessária' ), 'shield', 'info', $body, $actions );
	}

	public static function consent_link_done_html(): string {
		$app  = '<strong>' . esc_html( Settings::APP_NAME ) . '</strong>';
		$body = '<p class="lead">' . str_replace( '{app}', $app, esc_html( self::t( 'Se o administrador aprovou o {app} na tela da Microsoft, o aplicativo já está no tenant da organização. Entre com Microsoft 365 para abrir o portal — a conexão é confirmada no login.' ) ) ) . '</p>'
			. '<p class="fine">' . esc_html( self::t( 'Por segurança, esta página não mostra dados do tenant: a confirmação acontece somente depois do login com a sua conta corporativa.' ) ) . '</p>';
		$actions = '<a class="btn-solid" href="' . esc_url( Request::login_url() ) . '">' . esc_html( self::t( 'Entrar com Microsoft 365' ) ) . '</a><a class="btn-ghost" href="' . esc_url( Request::portal_url() ) . '">' . esc_html( self::t( 'Voltar ao início' ) ) . '</a>';
		return self::layout( self::t( 'Aprovação concluída na Microsoft' ), 'check', 'ok', $body, $actions );
	}

	public static function consent_done_html( string $tenant_id, string $name, bool $token_ok ): string {
		$login   = Request::login_url( [ 't' => $tenant_id ] );
		$seconds = $token_ok ? 4 : 20;
		$label   = '' !== $name ? $name : $tenant_id;
		$count   = '<strong><span id="j365-countdown" data-seconds="' . $seconds . '" data-url="' . esc_attr( $login ) . '">' . $seconds . '</span>s</strong>';

		$body = '<p class="lead">' . str_replace( [ '{app}', '{tenant}' ], [ '<strong>' . esc_html( Settings::APP_NAME ) . '</strong>', '<strong>' . esc_html( $label ) . '</strong>' ], esc_html( self::t( $token_ok ? 'O {app} foi instalado em {tenant}.' : 'Aprovação do {app} recebida para {tenant}.' ) ) ) . '</p>'
			. ( $token_ok
				? '<div class="note">' . str_replace( '{s}', $count, esc_html( self::t( 'Conexão validada. Entrando em {s}…' ) ) ) . '</div>'
				: '<div class="note warn">' . str_replace( '{s}', $count, esc_html( self::t( 'A Microsoft ainda está propagando a autorização (pode levar até 2 minutos). Vamos entrar automaticamente em {s}.' ) ) ) . '</div>' )
			. '<details class="disclosure"><summary>' . esc_html( self::t( 'Recomendações de segurança (Zero Trust)' ) ) . '</summary><div class="disclosure-body"><ol class="steps">'
			. '<li>' . str_replace( '{app}', '<strong>' . esc_html( Settings::APP_NAME ) . '</strong>', esc_html( self::t( 'Entra ID → Aplicativos empresariais → {app} → Propriedades → Atribuição obrigatória = Sim, e atribua apenas o grupo de administradores que usará o portal.' ) ) ) . '</li>'
			. '<li>' . esc_html( self::t( 'Crie uma política de Acesso Condicional para o aplicativo exigindo Authentication Strength: MFA resistente a phishing.' ) ) . '</li>'
			. '<li>' . esc_html( self::t( 'Para revogar, exclua o aplicativo empresarial — sessões e tokens param de funcionar imediatamente.' ) ) . '</li>'
			. '</ol></div></details>'
			. '<p class="tenant-line">' . esc_html( self::t( 'Tenant' ) ) . ': <code>' . esc_html( $tenant_id ) . '</code></p>';

		return self::layout( self::t( $token_ok ? 'Tenant conectado' : 'Confirmando a aprovação' ), $token_ok ? 'check' : 'info', $token_ok ? 'ok' : 'info', $body, '<a class="btn-solid" href="' . esc_url( $login ) . '">' . esc_html( self::t( 'Entrar agora' ) ) . '</a>' );
	}

	private static function layout( string $title, string $icon, string $type, string $body_html, string $actions_html, array $opt = [] ): string {
		$brand   = Settings::brand_name();
		$favicon = Brand::favicon_url();
		$css     = add_query_arg( 'ver', J365TP_ASSET_VERSION, J365TP_URL . 'assets/css/portal.css' );
		$nonce   = Shortcode::csp_nonce();
		$theme   = isset( $_COOKIE['j365_theme'] ) && in_array( $_COOKIE['j365_theme'], [ 'light', 'dark' ], true ) ? (string) $_COOKIE['j365_theme'] : '';

		$foot = [];
		foreach ( [ 'privacy_url' => 'Privacidade', 'terms_url' => 'Termos' ] as $setting => $label ) {
			$u = (string) Settings::get( $setting );
			if ( '' !== $u ) {
				$foot[] = '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . esc_html( self::t( $label ) ) . '</a>';
			}
		}
		$support = (string) Settings::get( 'support_email' );
		if ( '' !== $support ) {
			$foot[] = '<a href="mailto:' . esc_attr( $support ) . '">' . esc_html( self::t( 'Suporte' ) ) . '</a>';
		}
		$foot[] = esc_html( self::t( 'Protegido pelo Microsoft Entra ID' ) );
		$credit = '<a class="sp-credit" href="' . esc_url( Settings::CREATOR_LINKEDIN ) . '" target="_blank" rel="noopener">'
			. '<img src="' . esc_url( J365TP_URL . 'assets/img/creator-96.webp' ) . '" alt="" width="28" height="28" decoding="async">'
			. '<span>' . esc_html( self::t( 'Projeto criado por' ) ) . ' <b>' . esc_html( Settings::CREATOR_NAME ) . '</b></span></a>';

		$script = "(function(d){try{var m=d.cookie.match(/(?:^|; )j365_theme=(light|dark)/);d.documentElement.dataset.theme=m&&m[1]==='dark'?'dark':'light';}catch(e){}"
			. "d.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var i=d.getElementById(b.getAttribute('data-copy'));if(!i)return;i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){d.execCommand('copy');});var o=b.textContent;b.textContent=b.getAttribute('data-done')||'✓';setTimeout(function(){b.textContent=o;},1800);});"
			. "var c=d.getElementById('j365-countdown');if(c){var s=+c.getAttribute('data-seconds'),u=c.getAttribute('data-url');var t=setInterval(function(){s--;c.textContent=s;if(s<=0){clearInterval(t);location.href=u;}},1000);}})(document);";

		return '<!doctype html><html lang="' . esc_attr( I18n::html_lang() ) . '" data-theme="' . ( 'dark' === $theme ? 'dark' : 'light' ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="color-scheme" content="light dark">'
			. '<title>' . esc_html( $title . ' · ' . $brand ) . '</title>'
			. ( $favicon ? '<link rel="icon" href="' . esc_url( $favicon ) . '">' : '' )
			. '<link rel="stylesheet" href="' . esc_url( $css ) . '">'
			. '<script nonce="' . esc_attr( $nonce ) . '">' . $script . '</script>'
			. '</head><body class="j365-body"><div class="j365tp">'
			. '<div class="lx-bg" aria-hidden="true"><i class="blob b1"></i><i class="blob b2"></i><i class="blob b3"></i><div class="gridlines"></div><div class="grain"></div></div>'
			. '<main class="sp"><article class="sp-card tone-' . esc_attr( $type ) . ( ! empty( $opt['wide'] ) ? ' wide' : '' ) . '"' . ( in_array( $type, [ 'error', 'warn' ], true ) ? ' role="alert"' : '' ) . ( ! empty( $opt['code'] ) ? ' data-code="' . esc_attr( (string) $opt['code'] ) . '"' : '' ) . '>'
			. '<div class="sp-top">' . self::brand_html() . '</div>'
			. '<header class="sp-head"><div class="sp-icon ' . esc_attr( $type ) . '">' . self::icon( $icon ) . '</div><div class="sp-headtxt">'
			. ( ! empty( $opt['eyebrow'] ) ? '<p class="sp-eyebrow">' . esc_html( self::t( (string) $opt['eyebrow'] ) ) . '</p>' : '' )
			. '<h1>' . esc_html( $title ) . '</h1></div></header>' . $body_html
			. '<div class="sp-actions">' . $actions_html . '</div>'
			. '</article><p class="sp-foot">' . implode( ' · ', $foot ) . '</p>' . $credit . '</main></div></body></html>';
	}

	private static function send( string $html, int $status ): void {
		Page_Cache::bypass();
		if ( ! headers_sent() ) {
			status_header( $status );
			Shortcode::security_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* above
		exit;
	}
}
