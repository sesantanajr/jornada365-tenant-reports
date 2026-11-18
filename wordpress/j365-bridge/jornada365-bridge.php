<?php
/**
 * Plugin Name:       Jornada 365 · Bridge
 * Plugin URI:        https://jornada365.cloud
 * Description:       Diagnóstico HTTPS do Tenant Jornada365 com token temporário, revogável e somente de leitura.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Sérgio Sant'Ana Júnior – Jornada 365
 * Author URI:        https://jornada365.cloud
 * License:           GPL-2.0-or-later
 * Text Domain:       j365-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'J365BR_VERSION', '1.1.0' );
define( 'J365BR_FILE', __FILE__ );
define( 'J365BR_DIR', plugin_dir_path( __FILE__ ) );
define( 'J365BR_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( strpos( $class, 'J365BR\\' ) !== 0 ) {
			return;
		}
		$name = substr( $class, 7 );
		$file = J365BR_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			return;
		}
		J365BR\Bridge::instance()->boot();
	}
);
