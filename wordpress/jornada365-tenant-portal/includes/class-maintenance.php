<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hourly housekeeping (WP-Cron). On high-traffic sites, trigger wp-cron from the system cron.
 */
final class Maintenance {

	public function register(): void {
		add_action( 'j365tp_hourly_maintenance', [ $this, 'run' ] );
		add_action( 'j365tp_brand_refresh', [ Brand::class, 'refresh_from_url' ] );
	}

	public function run(): void {
		Jobs::purge_old();
		Audit::purge();

		// Brand assets missing (e.g. the logo host was unreachable at upgrade time): retry.
		if ( Settings::get( 'logo_source_url' ) && '' === Brand::wordmark_url() && empty( Brand::meta()['origin'] ) ) {
			Brand::refresh_from_url();
		}

		// Microsoft's public license catalog (friendly SKU names): refreshed weekly, built-in copy as fallback.
		Sku_Catalog::maybe_refresh();

	}
}
