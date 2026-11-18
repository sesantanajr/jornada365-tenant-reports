<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema, rewrite rules and lifecycle hooks.
 */
final class Installer {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'j365_' . $name;
	}

	public static function activate(): void {
		self::create_tables();
		( new Router() )->add_rules();
		flush_rewrite_rules();
		update_option( 'j365tp_db_version', J365TP_DB_VERSION );

		if ( ! wp_next_scheduled( 'j365tp_hourly_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'j365tp_hourly_maintenance' );
		}

		// Sensible defaults (never overwrite an existing configuration).
		$defaults = Settings::defaults();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, $defaults, '', 'no' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'j365tp_hourly_maintenance' );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'j365tp_db_version' ) !== J365TP_DB_VERSION ) {
			self::create_tables();
			update_option( 'j365tp_db_version', J365TP_DB_VERSION );
		}
		$rev = (int) get_option( 'j365tp_settings_rev', 0 );
		if ( $rev < Settings::REVISION ) {
			self::migrate_settings();
			if ( $rev < 5 ) {
				self::migrate_zero_storage();
			}
			if ( $rev < 6 ) {
				// Page caches (e.g. WP-Optimize) may hold copies of /j365/ routes from earlier versions.
				Page_Cache::schedule_purge();
			}
			if ( $rev < 7 ) {
				// 2.3: new "actions" module (delegated, runs as the signed-in admin) on by default; the
				// operator can turn it off in Configurações. Page caches hold the 2.2 landing.
				$saved = get_option( Settings::OPTION, [] );
				if ( is_array( $saved ) && isset( $saved['enabled_modules'] ) && is_array( $saved['enabled_modules'] ) && ! in_array( 'actions', $saved['enabled_modules'], true ) ) {
					$saved['enabled_modules'][] = 'actions';
					update_option( Settings::OPTION, $saved, 'no' );
				}
				Page_Cache::schedule_purge();
			}
			if ( $rev < 8 ) {
				// 1.0.0 (production): new delegated scope, Excel engine and landing — drop cached copies.
				Page_Cache::schedule_purge();
			}
			if ( $rev < 9 ) {
				// 1.1.0: executive interface and workbook — cached pages would reference the 1.0 assets.
				Page_Cache::schedule_purge();
			}
			if ( $rev < 10 ) {
				// 1.2.0: per-tenant model (each customer's own app, sign-in in the browser) — new landing/assets.
				Page_Cache::schedule_purge();
			}
			update_option( 'j365tp_settings_rev', Settings::REVISION, false );
		}
	}

	/**
	 * 2.1 — "nothing is stored on the server": removes every piece of tenant data and personal
	 * data kept by earlier versions (report cache, server sessions, job states, cached tokens,
	 * organization names, UPNs in clear and the old audit trail with e-mails/IPs).
	 */
	public static function migrate_zero_storage(): void {
		global $wpdb;
		foreach ( [ 'sessions', 'cache' ] as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$wpdb->query( 'DELETE FROM ' . self::table( 'jobs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_j365tp\_tok\_%' OR option_name LIKE '\_transient\_timeout\_j365tp\_tok\_%'" );

		$tenants = self::table( 'tenants' );
		foreach ( $wpdb->get_results( "SELECT tenant_id, consented_by, settings FROM {$tenants}", ARRAY_A ) ?: [] as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$settings = json_decode( (string) $row['settings'], true ) ?: [];
			if ( isset( $settings['extra_users'] ) ) {
				$settings['extra_users'] = Privacy::uids( (array) $settings['extra_users'] );
			}
			$wpdb->update(
				$tenants,
				[
					'display_name'   => '',
					'default_domain' => '',
					'consented_by'   => Privacy::uid( (string) $row['consented_by'] ),
					'settings'       => wp_json_encode( $settings ),
				],
				[ 'tenant_id' => $row['tenant_id'] ]
			);
		}
		$allow = self::table( 'app_allowlist' );
		foreach ( $wpdb->get_results( "SELECT id, created_by FROM {$allow}", ARRAY_A ) ?: [] as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->update( $allow, [ 'created_by' => Privacy::uid( (string) $row['created_by'] ) ], [ 'id' => $row['id'] ] );
		}
		$wpdb->query( 'DELETE FROM ' . self::table( 'audit' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		Audit::log( 'privacy_migration', '', 'system', [ 'version' => J365TP_VERSION ] );
	}

	/**
	 * v1.3: brand renamed to "Tenant Jornada365", logo source configured, certificate credential
	 * prepared and logo assets built in the background.
	 */
	public static function migrate_settings(): void {
		$s       = get_option( Settings::OPTION, [] );
		$s       = is_array( $s ) ? $s : [];
		$legacy  = [ '', 'Jornada 365 · Tenant Portal', 'Jornada 365 Tenant Portal', 'Jornada 365 - Tenant Portal' ];
		if ( in_array( trim( (string) ( $s['brand_name'] ?? '' ) ), $legacy, true ) ) {
			$s['brand_name'] = Settings::APP_NAME;
		}
		$s['logo_source_url'] = $s['logo_source_url'] ?? Settings::LOGO_SOURCE;
		$s['icon_style']      = $s['icon_style'] ?? 'badge';
		$s['terms_url']       = $s['terms_url'] ?? '';
		update_option( Settings::OPTION, $s, 'no' );

		if ( ! Settings::per_tenant() ) { // per-tenant model: no app credential exists anywhere
			try {
				Client_Credential::ensure();
			} catch ( \Throwable $e ) {
				// Surfaced on the setup screen.
			}
		}
		if ( ! Brand::rebuild_local() && '' !== $s['logo_source_url'] && ! wp_next_scheduled( 'j365tp_brand_refresh' ) ) {
			wp_schedule_single_event( time() + 5, 'j365tp_brand_refresh' );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$tenants   = self::table( 'tenants' );
		$jobs      = self::table( 'jobs' );
		$allowlist = self::table( 'app_allowlist' );
		$audit     = self::table( 'audit' );

		$sql = [];

		$history = self::table( 'identity_history' );
		$sql[] = "CREATE TABLE {$history} (
			tenant_id CHAR(36) NOT NULL,
			generation CHAR(36) NOT NULL,
			revision CHAR(36) NOT NULL,
			months TEXT NOT NULL,
			oldest CHAR(7) NOT NULL DEFAULT '',
			collected_at CHAR(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (tenant_id),
			KEY oldest (oldest)
		) {$charset};";

		$sql[] = "CREATE TABLE {$tenants} (
			tenant_id CHAR(36) NOT NULL,
			display_name VARCHAR(255) NOT NULL DEFAULT '',
			default_domain VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			consented_at DATETIME NULL,
			consented_by VARCHAR(255) NOT NULL DEFAULT '',
			last_login_at DATETIME NULL,
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (tenant_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$jobs} (
			job_id CHAR(36) NOT NULL,
			tenant_id CHAR(36) NOT NULL,
			user_oid CHAR(36) NOT NULL,
			job_type VARCHAR(50) NOT NULL,
			params LONGTEXT NULL,
			state LONGBLOB NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			progress INT UNSIGNED NOT NULL DEFAULT 0,
			total INT UNSIGNED NOT NULL DEFAULT 0,
			message VARCHAR(500) NOT NULL DEFAULT '',
			cache_key CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (job_id),
			KEY tenant_status (tenant_id, status),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$allowlist} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id CHAR(36) NOT NULL,
			app_name VARCHAR(255) NOT NULL,
			publisher VARCHAR(255) NOT NULL DEFAULT '',
			platform VARCHAR(30) NOT NULL DEFAULT 'any',
			match_type VARCHAR(10) NOT NULL DEFAULT 'exact',
			notes VARCHAR(500) NOT NULL DEFAULT '',
			created_by VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_id (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			tenant_id CHAR(36) NOT NULL DEFAULT '',
			actor VARCHAR(255) NOT NULL DEFAULT '',
			action VARCHAR(60) NOT NULL,
			details LONGTEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY tenant_ts (tenant_id, ts),
			KEY action (action)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
