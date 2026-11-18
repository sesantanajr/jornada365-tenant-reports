<?php
/**
 * Uninstall: remove tables, options and transients. Runs only when the plugin is deleted via WP admin.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
foreach ( [ 'tenants', 'sessions', 'jobs', 'cache', 'app_allowlist', 'audit' ] as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'j365_' . $t ); // phpcs:ignore WordPress.DB.PreparedSQL
}
delete_option( 'j365tp_settings' );
delete_option( 'j365tp_db_version' );
foreach ( [ 'j365tp_credential', 'j365tp_brand', 'j365tp_settings_rev', 'j365tp_setup_code', 'j365tp_purge_page_cache', 'j365tp_page_cache_purged_at', 'j365tp_sku_catalog' ] as $o ) { // includes the encrypted certificate private key
	delete_option( $o );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_j365tp_%' OR option_name LIKE '_transient_timeout_j365tp_%'" );
wp_clear_scheduled_hook( 'j365tp_hourly_maintenance' );
