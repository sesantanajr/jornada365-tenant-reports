<?php
namespace J365BR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bridge_Ops {

	private const PORTAL_FILE = 'jornada365-tenant-portal/jornada365-tenant-portal.php';

	/** Report only the canonical Tenant Portal plugin, without enumerating the host. */
	public static function portal_status(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$path = WP_PLUGIN_DIR . '/' . self::PORTAL_FILE;
		if ( ! is_readable( $path ) ) {
			return [ 'installed' => false, 'active' => false, 'version' => null ];
		}

		$plugin    = get_plugin_data( $path, false, false );
		$installed = '' !== $plugin['Name'];
		return [
			'installed' => $installed,
			'active'    => $installed && is_plugin_active( self::PORTAL_FILE ),
			'version'   => $installed ? sanitize_text_field( $plugin['Version'] ) : null,
		];
	}
}
