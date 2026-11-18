<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portal front-end.
 *
 * The page that holds [j365_tenant_portal] (or the page selected in Settings) is rendered
 * as a standalone full-screen app (templates/app.php) — no theme header/footer — so the
 * public sign-in experience looks like a product, not a blog page. The shortcode still
 * works anywhere else as an embedded fallback.
 */
final class Shortcode {

	private static ?string $csp_nonce = null;

	public function register(): void {
		add_shortcode( 'j365_tenant_portal', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'template_redirect', [ $this, 'no_cache_for_portal' ] );
		add_filter( 'template_include', [ $this, 'template' ], 99 );
	}

	public function register_assets(): void {
		wp_register_style( 'j365tp-portal', J365TP_URL . 'assets/css/portal.css', [], J365TP_ASSET_VERSION );
		wp_register_script( 'j365tp-core', J365TP_URL . 'assets/js/core.js', [], J365TP_ASSET_VERSION, true );
		wp_register_script( 'j365tp-auth', J365TP_URL . 'assets/js/auth.js', [ 'j365tp-core' ], J365TP_ASSET_VERSION, true );
		wp_register_script( 'j365tp-landing', J365TP_URL . 'assets/js/landing.js', [ 'j365tp-core', 'j365tp-auth' ], J365TP_ASSET_VERSION, true );
		wp_register_script( 'j365tp-portal', J365TP_URL . 'assets/js/portal.js', [ 'j365tp-core', 'j365tp-landing' ], J365TP_ASSET_VERSION, true );
	}

	public static function is_portal_request(): bool {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}
		$id  = (int) get_queried_object_id();
		$pid = (int) Settings::get( 'portal_page_id', 0 );
		if ( $pid > 0 && $id === $pid ) {
			return true;
		}
		return has_shortcode( (string) get_post_field( 'post_content', $id ), 'j365_tenant_portal' );
	}

	/** The portal page must never be served from page caches (WP-Optimize, LiteSpeed, Cloudflare APO…). */
	public function no_cache_for_portal(): void {
		if ( ! self::is_portal_request() ) {
			return;
		}
		Page_Cache::bypass();
	}

	public function template( string $template ): string {
		if ( ! self::is_portal_request() ) {
			return $template;
		}
		self::security_headers();
		I18n::current(); // resolve (and persist ?lang=) before any output
		return J365TP_DIR . 'templates/app.php';
	}

	public static function csp_nonce(): string {
		if ( null === self::$csp_nonce ) {
			self::$csp_nonce = Crypto::random_token( 16 );
		}
		return self::$csp_nonce;
	}

	/**
	 * Strict headers for the standalone app. Only same-origin XHR; scripts only from this site and the
	 * inline config (nonce) — the Excel engine is the plugin's own file (assets/js/xlsx.js), no CDN.
	 */
	public static function security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		$n = self::csp_nonce();
		header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$n}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' https://login.microsoftonline.com; font-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://login.microsoftonline.com; object-src 'none'" );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
	}

	/** Number of report screens shown to the user (drill-down excluded, "contas inativas" included). */
	public static function report_count(): int {
		// Actions (act_*) are not reports; the identity report is shown as two views (users + inactive).
		$active = array_values( array_filter( Settings::active_reports(), static fn( $r ) => ! str_starts_with( (string) $r, 'act_' ) ) );
		return count( array_diff( $active, [ 'app_devices', 'app_assignments', 'app_failures', 'device_compliance' ] ) ) + ( in_array( 'identity', $active, true ) ? 1 : 0 );
	}

	/** Runtime configuration handed to the front-end. */
	public static function config(): array {
		$session    = Session::current();
		$per_tenant = Settings::per_tenant();
		$can_setup  = ! $per_tenant && Bootstrap::can(); // per-tenant model: nothing to register here
		$configured = Settings::is_configured();
		$forced     = $can_setup && isset( $_GET['j365_setup'] ); // phpcs:ignore WordPress.Security.NonceVerification -- opens a dialog only
		return [
			'restUrl'          => esc_url_raw( rest_url( Rest_Api::NS . '/' ) ),
			'nonce'            => $session ? Session::csrf_token() : '',
			'loginUrl'         => $per_tenant ? Spa_Auth::signin_url() : Request::login_url(),
			'tenancy'          => $per_tenant ? 'per_tenant' : 'multi',
			'spa'              => $per_tenant ? Spa_Auth::client_config() : null,
			// Signed-in tab without tokens (new tab, 24-hour limit): silent renewal with the tenant's own app.
			'account'          => $per_tenant && $session ? [ 'tenantId' => $session['tenant_id'], 'clientId' => Tenant_Repository::client_id( $session['tenant'] ?? null ), 'upn' => $session['upn'] ] : null,
			'logoutUrl'        => Request::route_url( 'auth/logout' ),
			'consentUrl'       => Request::route_url( 'consent/start' ),
			'brand'            => Settings::brand_name(),
			'appName'          => Settings::APP_NAME,
			'privacyUrl'       => (string) Settings::get( 'privacy_url' ),
			'termsUrl'         => (string) Settings::get( 'terms_url', '' ),
			'support'          => (string) Settings::get( 'support_email' ),
			'configured'       => $configured,
			'authenticated'    => (bool) $session,
			'permissions'      => Settings::active_permissions(),
			'permissionGroups' => Settings::permission_groups(),
			'reportCount'      => self::report_count(),
			'logo'             => [ 'light' => Brand::wordmark_url(), 'dark' => Brand::wordmark_dark_url() ],
			'iconUrl'          => Brand::icon_url(),
			'welcome'          => isset( $_GET['welcome'] ) && $session, // phpcs:ignore WordPress.Security.NonceVerification -- display only
			'lang'             => I18n::current(),
			'intl'             => I18n::intl(),
			'langs'            => I18n::languages(),
			'i18n'             => (object) I18n::dict(),
			'setup'            => $can_setup ? [
				'can'      => true,
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'forced'   => $forced,
				'autoOpen' => $forced,
			] : [ 'can' => false ], // the (possibly hidden) wp-login URL is never exposed to visitors
			'assetsUrl'        => J365TP_URL . 'assets/',
			'langBase'         => J365TP_URL . 'languages/', // dictionaries fetched on an in-place language switch
			'actionUrl'        => Request::route_url( 'auth/action' ), // delegated authorization popup for actions
			'actionKey'        => $session ? Action_Auth::start_key( $session ) : '', // binds the popup to this session (CSRF)
			'creator'          => [
				'name'  => Settings::CREATOR_NAME,
				'url'   => Settings::CREATOR_LINKEDIN,
				'photo' => J365TP_URL . 'assets/img/creator.webp',
				'thumb' => J365TP_URL . 'assets/img/creator-96.webp',
			],
			'privacy'          => [
				'serverStorage'  => false,
				'jobTtlMinutes'  => (int) ( Jobs::TTL / MINUTE_IN_SECONDS ),
				'auditDays'      => Audit::retention_days(),
				'roleCheckMinutes' => (int) ( Session::ROLE_RECHECK / MINUTE_IN_SECONDS ),
			],
			'version'          => J365TP_VERSION,
			'assetVersion'     => J365TP_ASSET_VERSION,
		];
	}

	public static function config_json(): string {
		return (string) wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
	}

	/** Embedded fallback (shortcode used inside a themed page). */
	public function render( $atts = [] ): string {
		$session = Session::current();
		wp_enqueue_style( 'j365tp-portal' );
		wp_enqueue_script( 'j365tp-portal' );
		wp_add_inline_script( 'j365tp-core', 'window.J365TP = ' . self::config_json() . ';', 'before' );

		return '<div id="j365tp-app" class="j365tp embedded" data-authenticated="' . ( $session ? '1' : '0' ) . '"><noscript>Este portal requer JavaScript.</noscript><div class="j365tp-boot">' . esc_html( I18n::t( 'Carregando…' ) ) . '</div></div>';
	}
}
