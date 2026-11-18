<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap: wires every component.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		load_plugin_textdomain( 'j365-tenant-portal', false, dirname( plugin_basename( J365TP_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();
		Page_Cache::register();

		( new Router() )->register();
		( new Rest_Api() )->register();
		( new Shortcode() )->register();
		( new Admin_Ui() )->register();
		Admin_Setup_Page::register();
		( new Settings() )->register();
		( new Maintenance() )->register();

		add_filter( 'allowed_redirect_hosts', [ $this, 'allowed_redirect_hosts' ] );
	}

	/**
	 * Allow redirects to Microsoft identity platform for wp_safe_redirect().
	 */
	public function allowed_redirect_hosts( array $hosts ): array {
		$hosts[] = 'login.microsoftonline.com';
		$hosts[] = 'login.microsoftonline.us';
		return $hosts;
	}
}
