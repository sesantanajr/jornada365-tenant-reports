<?php
/**
 * Standalone full-screen app shell for the public portal (no theme chrome).
 *
 * @package J365TP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use J365TP\Shortcode;
use J365TP\Settings;
use J365TP\Session;
use J365TP\I18n;
use J365TP\Brand;

$j365_brand   = Settings::brand_name();
$j365_nonce   = Shortcode::csp_nonce();
$j365_session = Session::current();
$j365_ver     = J365TP_ASSET_VERSION;
$j365_css     = add_query_arg( 'ver', $j365_ver, J365TP_URL . 'assets/css/portal.css' );
$j365_desc    = I18n::t( 'Conecte o tenant Microsoft 365 da sua organização e gere relatórios de identidade, MFA, dispositivos, Acesso Condicional, licenças, segurança de aplicativos e uso — somente leitura, via Microsoft Graph.' );
$j365_icon    = Brand::favicon_url() ?: ( 'data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#50d8f8"/><stop offset="1" stop-color="#0068b8"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#g)"/><path d="M20 44h24a9 9 0 0 0 1-17.9A12 12 0 0 0 22 25a9.5 9.5 0 0 0-2 19z" fill="#fff"/></svg>' ) );
$j365_theme   = isset( $_COOKIE['j365_theme'] ) && in_array( $_COOKIE['j365_theme'], [ 'light', 'dark' ], true ) ? (string) $_COOKIE['j365_theme'] : '';
?><!doctype html>
<html lang="<?php echo esc_attr( I18n::html_lang() ); ?>" data-theme="<?php echo 'dark' === $j365_theme ? 'dark' : 'light'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo esc_html( $j365_brand . ' · ' . I18n::t( 'Raio-X do seu tenant Microsoft 365' ) ); ?></title>
<meta name="description" content="<?php echo esc_attr( $j365_desc ); ?>">
<meta name="robots" content="<?php echo get_option( 'blog_public' ) ? 'index,follow' : 'noindex,nofollow'; ?>">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?php echo 'dark' === $j365_theme ? '#030d1b' : '#f4f8fc'; ?>">
<meta property="og:title" content="<?php echo esc_attr( $j365_brand ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $j365_desc ); ?>">
<meta property="og:type" content="website">
<?php foreach ( I18n::LANGS as $j365_code => $j365_l ) : ?>
<link rel="alternate" hreflang="<?php echo esc_attr( $j365_l['html'] ); ?>" href="<?php echo esc_url( add_query_arg( 'lang', $j365_code, \J365TP\Request::portal_url() ) ); ?>">
<?php endforeach; ?>
<link rel="icon" href="<?php echo esc_attr( $j365_icon ); ?>">
<link rel="preload" href="<?php echo esc_url( J365TP_URL . 'assets/fonts/raleway-var.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo esc_url( J365TP_URL . 'assets/fonts/inter-var.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url( $j365_css ); ?>">
<script nonce="<?php echo esc_attr( $j365_nonce ); ?>">(function(d){try{var m=d.cookie.match(/(?:^|; )j365_theme=(light|dark)/);d.documentElement.dataset.theme=m&&m[1]==='dark'?'dark':'light';}catch(e){}})(document);window.J365TP = <?php echo Shortcode::config_json(); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX flags ?>;</script>
<?php foreach ( [ 'core', 'auth', 'landing', 'portal' ] as $j365_js ) : ?>
<script src="<?php echo esc_url( add_query_arg( 'ver', $j365_ver, J365TP_URL . 'assets/js/' . $j365_js . '.js' ) ); ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="j365-body">
<div id="j365tp-app" class="j365tp" data-authenticated="<?php echo $j365_session ? '1' : '0'; ?>">
	<noscript><main class="sp"><div class="sp-card"><h1><?php echo esc_html( I18n::t( 'JavaScript necessário' ) ); ?></h1><p class="lead"><?php echo esc_html( I18n::t( 'Este portal requer JavaScript habilitado no navegador.' ) ); ?></p></div></main></noscript>
	<div class="j365tp-boot" aria-live="polite"><span class="spinner" aria-hidden="true"></span><?php echo esc_html( I18n::t( 'Carregando…' ) ); ?></div>
</div>
</body>
</html>
