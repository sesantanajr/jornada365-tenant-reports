<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-cache compatibility.
 *
 * The portal and its /j365/… routes answer per visitor (session, language, OIDC state), so no
 * full-page cache may store them. WP-Optimize, WP Super Cache, W3 Total Cache, WP Rocket and
 * SiteGround honor DONOTCACHEPAGE; LiteSpeed uses its own action/header; CDNs read
 * CDN-Cache-Control. Copies stored by earlier versions are purged once after an upgrade.
 */
final class Page_Cache {

	public const PURGE_FLAG = 'j365tp_purge_page_cache';
	public const PURGED_AT  = 'j365tp_page_cache_purged_at';

	/** Response headers that page caches / CDNs use to report a hit. */
	private const HIT_HEADERS = [ 'wpo-cache-status', 'x-litespeed-cache', 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-proxy-cache', 'x-sg-cache', 'x-hcdn-cache-status', 'x-wp-super-cache' ];

	/** Marks the current response as never cacheable (plugins, proxies and CDNs). */
	public static function bypass(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_control_set_nocache', 'Tenant Jornada365: per-visitor response' );
		}
		if ( headers_sent() ) {
			return;
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'CDN-Cache-Control: no-store' );
	}

	/**
	 * Called by the upgrade routine: the purge itself runs once WordPress is fully loaded.
	 * The flag stays autoloaded (1/0) so checking it costs no query on regular requests.
	 */
	public static function schedule_purge(): void {
		update_option( self::PURGE_FLAG, 1, true );
	}

	public static function register(): void {
		add_action( 'wp_loaded', [ self::class, 'maybe_purge' ] );
	}

	public static function maybe_purge(): void {
		if ( ! (int) get_option( self::PURGE_FLAG, 0 ) ) {
			return;
		}
		update_option( self::PURGE_FLAG, 0, true );
		$done = self::purge_all();
		Audit::log( 'page_cache_purged', '', 'system', [ 'caches' => $done ] );
	}

	/**
	 * Purges full-page caches of the known plugins (whichever are installed).
	 *
	 * @return string[] caches that were purged
	 */
	public static function purge_all(): array {
		$done = [];
		$try  = static function ( string $name, callable $fn ) use ( &$done ): void {
			try {
				$fn();
				$done[] = $name;
			} catch ( \Throwable $e ) {
				$done[] = $name . ' (falhou)';
			}
		};
		// Stored copies of the portal routes are removed first, independently of any plugin API.
		$files = self::purge_wpo_route_files();
		if ( $files > 0 ) {
			$done[] = 'wp-optimize: ' . $files . ' arquivo(s) de /j365/';
		}
		if ( function_exists( 'wpo_cache_flush' ) ) {
			$try( 'wp-optimize', static fn() => \wpo_cache_flush() );
		} elseif ( function_exists( 'WP_Optimize' ) && is_object( \WP_Optimize() ) && method_exists( \WP_Optimize(), 'get_page_cache' ) ) {
			$try( 'wp-optimize', static fn() => \WP_Optimize()->get_page_cache()->purge() );
		}
		if ( function_exists( 'has_action' ) && has_action( 'litespeed_purge_all' ) ) {
			$try( 'litespeed', static fn() => do_action( 'litespeed_purge_all' ) );
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$try( 'wp-super-cache', static fn() => \wp_cache_clear_cache() );
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$try( 'w3-total-cache', static fn() => \w3tc_flush_all() );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$try( 'wp-rocket', static fn() => \rocket_clean_domain() );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$try( 'sg-optimizer', static fn() => \sg_cachepress_purge_cache() );
		}
		update_option( self::PURGED_AT, time(), false );
		return $done;
	}

	/** Full-page cache plugins active on this site (by their public constants/functions). */
	public static function detected(): array {
		$found = [];
		if ( defined( 'WPO_VERSION' ) || function_exists( 'WP_Optimize' ) ) {
			$found[] = 'WP-Optimize';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$found[] = 'LiteSpeed Cache';
		}
		if ( defined( 'WPCACHEHOME' ) ) {
			$found[] = 'WP Super Cache';
		}
		if ( defined( 'W3TC' ) ) {
			$found[] = 'W3 Total Cache';
		}
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$found[] = 'WP Rocket';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$found[] = 'SiteGround Optimizer';
		}
		if ( ! $found && defined( 'WP_CACHE' ) && WP_CACHE ) {
			$found[] = 'WP_CACHE (advanced-cache.php)';
		}
		return $found;
	}

	/**
	 * Requests /j365/auth/login/ the way an anonymous visitor does (no cookies, no query string):
	 * the answer must come from the portal (redirect to Microsoft), never from a page cache.
	 *
	 * @return array{ok: ?bool, detail: string} ok = null when the loopback request is not possible
	 */
	public static function self_test(): array {
		$per = Settings::per_tenant();
		$r   = wp_remote_get(
			$per ? Spa_Auth::redirect_uri() : Request::route_url( 'auth/login' ),
			[
				'redirection' => 0,
				'timeout'     => 8,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [ 'Accept' => 'text/html' ],
			]
		);
		if ( is_wp_error( $r ) ) {
			return [ 'ok' => null, 'detail' => 'Teste local indisponível neste servidor: ' . $r->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$hit  = self::cache_hit( wp_remote_retrieve_headers( $r ) );
		if ( '' !== $hit ) {
			return [ 'ok' => false, 'detail' => 'HTTP ' . $code . ' servido do cache (' . $hit . '). Clique em "Limpar cache de página".' ];
		}
		if ( $per ) {
			// Per-tenant model: the login return page must be generated live (it finishes the sign-in).
			if ( 200 === $code && str_contains( (string) wp_remote_retrieve_body( $r ), 'data-j365-page="spa"' ) ) {
				return [ 'ok' => true, 'detail' => 'HTTP 200 · página de retorno do login (resposta dinâmica, sem cache)' ];
			}
			return [ 'ok' => false, 'detail' => 'HTTP ' . $code . ' (esperado: página de retorno do login /j365/auth/spa/). Clique em "Limpar cache de página".' ];
		}
		$loc  = wp_remote_retrieve_header( $r, 'location' );
		$host = (string) wp_parse_url( (string) ( is_array( $loc ) ? reset( $loc ) : $loc ), PHP_URL_HOST );
		if ( $code >= 300 && $code < 400 && 'login.microsoftonline.com' === strtolower( $host ) ) {
			return [ 'ok' => true, 'detail' => 'HTTP ' . $code . ' → login.microsoftonline.com (resposta dinâmica, sem cache)' ];
		}
		if ( str_contains( (string) wp_remote_retrieve_body( $r ), 'Client Secret' ) ) {
			return [ 'ok' => false, 'detail' => 'HTTP ' . $code . ': cópia antiga da v1.x. Clique em "Limpar cache de página".' ];
		}
		if ( ! Settings::is_configured() ) {
			return [ 'ok' => 200 === $code, 'detail' => 'HTTP ' . $code . ' (aplicativo ainda não registrado: o login mostra "Portal em configuração")' ];
		}
		return [ 'ok' => false, 'detail' => 'HTTP ' . $code . ' (esperado: redirecionamento para login.microsoftonline.com)' ];
	}

	/** "header: value" of the first cache-hit header, or ''. */
	public static function cache_hit( $headers ): string {
		foreach ( self::HIT_HEADERS as $name ) {
			$raw = $headers[ $name ] ?? '';
			foreach ( is_array( $raw ) ? $raw : explode( ',', (string) $raw ) as $v ) { // one value per cache layer
				$v = strtolower( trim( (string) $v ) );
				if ( '' !== $v && preg_match( '/\b(cached|hit)\b/', $v ) && ! preg_match( '/\b(not|no|miss|bypass|dynamic|expired|saving)\b/', $v ) ) {
					return $name . ': ' . $v;
				}
			}
		}
		return '';
	}

	/**
	 * Deletes WP-Optimize's stored copies of the /j365/ routes (wp-content/cache/wpo-cache/<host>/j365/).
	 * Only that subtree is touched; every path is re-checked to stay inside the cache directory.
	 */
	public static function purge_wpo_route_files( ?string $content_dir = null ): int {
		$content_dir = $content_dir ?? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );
		$base        = realpath( rtrim( $content_dir, '/\\' ) . '/cache/wpo-cache' );
		if ( false === $base || ! is_dir( $base ) ) {
			return 0;
		}
		// <host>/j365, or <host>/<subdirectory>/j365 when WordPress lives in a subdirectory.
		$route = function_exists( 'home_url' ) ? trim( (string) wp_parse_url( home_url( '/j365/' ), PHP_URL_PATH ), '/' ) : 'j365';
		$route = preg_match( '#^((?!\.+/)[A-Za-z0-9._~-]+/)*j365$#', $route ) ? $route : 'j365';
		$count = 0;
		foreach ( glob( $base . '/*/' . $route, GLOB_ONLYDIR ) ?: [] as $dir ) {
			$real = realpath( $dir );
			if ( false === $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) || 'j365' !== basename( $real ) || is_link( $dir ) ) {
				continue;
			}
			$count += self::remove_tree( $real, $base );
		}
		return $count;
	}

	private static function remove_tree( string $dir, string $base ): int {
		$count = 0;
		$items = scandir( $dir );
		foreach ( false === $items ? [] : $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_link( $path ) ) {
				continue; // never follow links out of the cache directory
			}
			if ( is_dir( $path ) ) {
				$count += self::remove_tree( $path, $base );
				continue;
			}
			$real = realpath( $path );
			if ( false !== $real && str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) && @unlink( $real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				++$count;
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		return $count;
	}
}
