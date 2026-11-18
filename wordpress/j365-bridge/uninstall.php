<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove current and legacy Bridge data from this site.
delete_option( 'j365br_diagnostic_token' );
delete_option( 'j365br_tokens' );
delete_option( 'j365br_db' );
delete_transient( 'j365br_new_token' );
delete_transient( 'j365br_notice' );

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'j365br_audit' ) );
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_j365br_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_j365br_rl_' ) . '%'
	)
);
