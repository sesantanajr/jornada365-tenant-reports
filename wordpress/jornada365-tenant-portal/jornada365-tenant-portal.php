<?php
/**
 * Plugin Name:       Tenant Jornada365
 * Plugin URI:        https://tenant.jornada365.cloud
 * Description:       Portal de relatórios Microsoft 365 / Entra ID / Intune da Jornada 365. Cada empresa conecta o próprio tenant: o app "Tenant Jornada365" é criado dentro do tenant dela (aplicativo de página única, permissões delegadas, sem segredo e sem certificado), sem depender de nenhum outro tenant. Relatórios somente leitura, ações delegadas (Intune e Exchange) e Excel executivo gerado no navegador.
 * Version:           1.5.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Sérgio Sant'Ana Júnior – Jornada 365
 * Author URI:        https://jornada365.cloud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Update URI:        https://github.com/sesantanajr/jornada365-tenant-reports
 * Text Domain:       j365-tenant-portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'J365TP_VERSION', '1.5.0' );
define( 'J365TP_ASSET_VERSION', J365TP_VERSION );
define( 'J365TP_FILE', __FILE__ );
define( 'J365TP_DIR', plugin_dir_path( __FILE__ ) );
define( 'J365TP_URL', plugin_dir_url( __FILE__ ) );
define( 'J365TP_DB_VERSION', '1' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( strpos( $class, 'J365TP\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, 7 ); // strip J365TP\
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$subdir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$path     = J365TP_DIR . 'includes/' . $subdir . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, [ 'J365TP\\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'J365TP\\Installer', 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>Tenant Jornada365:</strong> requer PHP 8.1 ou superior.</p></div>';
				}
			);
			return;
		}
		J365TP\Plugin::instance()->boot();
	}
);
