<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connected tenants (one row per Entra tenant that granted admin consent).
 *
 * Data minimization: only the tenant ID, its status (for operator revocation), dates and the
 * tenant's own portal preferences are kept. Organization name/domain are never stored (they are
 * read live from Microsoft Graph at sign-in); people are stored only as keyed pseudonyms.
 */
final class Tenant_Repository {

	public static function get( string $tenant_id ): ?array {
		global $wpdb;
		$table = Installer::table( 'tenants' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s", strtolower( $tenant_id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['settings'] = json_decode( (string) $row['settings'], true ) ?: [];
		return $row;
	}

	public static function all(): array {
		global $wpdb;
		$table = Installer::table( 'tenants' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY consented_at DESC", ARRAY_A ) ?: [];
		foreach ( $rows as &$row ) {
			$row['settings'] = json_decode( (string) $row['settings'], true ) ?: [];
		}
		return $rows;
	}

	public static function upsert( string $tenant_id, array $fields ): void {
		unset( $fields['display_name'], $fields['default_domain'] ); // never stored
		if ( isset( $fields['consented_by'] ) ) {
			$fields['consented_by'] = Privacy::uid( (string) $fields['consented_by'] );
		}
		global $wpdb;
		$table     = Installer::table( 'tenants' );
		$tenant_id = strtolower( $tenant_id );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$existing  = self::get( $tenant_id );

		if ( isset( $fields['settings'] ) && is_array( $fields['settings'] ) ) {
			$fields['settings'] = wp_json_encode( $fields['settings'] );
		}
		$fields['updated_at'] = $now;

		if ( $existing ) {
			$wpdb->update( $table, $fields, [ 'tenant_id' => $tenant_id ] );
		} else {
			$fields['tenant_id']  = $tenant_id;
			$fields['created_at'] = $now;
			$fields['status']     = $fields['status'] ?? 'active';
			$wpdb->insert( $table, $fields );
		}
	}

	public static function set_status( string $tenant_id, string $status ): void {
		self::upsert( $tenant_id, [ 'status' => $status ] );
		if ( 'active' !== $status ) {
			Session::destroy_for_tenant( $tenant_id );
			Token_Service::forget( $tenant_id );
		}
	}

	public static function delete( string $tenant_id ): void {
		global $wpdb;
		$tenant_id = strtolower( $tenant_id );
		Token_Service::forget( $tenant_id );
		foreach ( [ 'tenants', 'jobs', 'app_allowlist' ] as $t ) {
			$wpdb->delete( Installer::table( $t ), [ 'tenant_id' => $tenant_id ] );
		}
	}

	public static function touch_login( string $tenant_id ): void {
		self::upsert( $tenant_id, [ 'last_login_at' => gmdate( 'Y-m-d 00:00:00' ) ] ); // day precision only
	}

	public static function get_setting( array $tenant, string $key, $fallback ) {
		return $tenant['settings'][ $key ] ?? $fallback;
	}

	/** Per-tenant model: client ID of the tenant's own "Tenant Jornada365" app ('' = not connected). */
	public static function client_id( ?array $tenant ): string {
		$id = strtolower( (string) ( $tenant['settings']['client_id'] ?? '' ) );
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id ) ? $id : '';
	}

	/**
	 * Per-tenant model: records the tenant's own app (public identifiers only — no secret exists).
	 *
	 * @param string $mode auto (created by the portal) | manual (created by the admin in Entra)
	 */
	public static function register_app( string $tenant_id, string $client_id, string $object_id, string $by_upn, string $mode ): void {
		$tenant   = self::get( $tenant_id );
		$settings = array_merge(
			$tenant['settings'] ?? [],
			[
				'client_id'     => strtolower( $client_id ),
				'app_object_id' => strtolower( $object_id ),
				'app_mode'      => in_array( $mode, [ 'auto', 'manual' ], true ) ? $mode : 'auto',
				'app_signature' => Settings::app_signature(),
				'app_at'        => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		$fields = [ 'settings' => $settings ];
		if ( ! $tenant || 'revoked' !== (string) $tenant['status'] ) {
			$fields['status'] = 'active'; // an operator revocation is never undone by a reconnection
		}
		if ( ! $tenant || '' === (string) ( $tenant['consented_by'] ?? '' ) ) {
			$fields['consented_at'] = gmdate( 'Y-m-d H:i:s' );
			$fields['consented_by'] = $by_upn; // stored as a pseudonym by upsert()
		}
		self::upsert( $tenant_id, $fields );
	}

	public static function update_settings( string $tenant_id, array $patch ): array {
		$tenant   = self::get( $tenant_id );
		$settings = array_merge( $tenant['settings'] ?? [], $patch );
		if ( isset( $settings['extra_users'] ) ) {
			$settings['extra_users'] = Privacy::uids( (array) $settings['extra_users'] ); // pseudonyms only
		}
		self::upsert( $tenant_id, [ 'settings' => $settings ] );
		return $settings;
	}

	/**
	 * Who may run actions in this tenant (decided by a Global Administrator / Privileged Role Administrator):
	 *  roles — only Entra roles that already include the Intune/Exchange RBAC (default, least privilege)
	 *  rbac  — anyone authorized in the portal; Microsoft enforces each person's Intune/Exchange RBAC
	 *          (e.g. Intune Help Desk Operator limited by scope tags, Exchange custom role groups)
	 *  off   — actions disabled for the tenant
	 */
	public const ACTION_SCOPES = [ 'roles', 'rbac', 'off' ];

	public static function action_scope( ?array $tenant ): string {
		$v = (string) ( $tenant['settings']['actions_scope'] ?? 'roles' );
		return in_array( $v, self::ACTION_SCOPES, true ) ? $v : 'roles';
	}

	/** Settings as exposed to the portal (pseudonyms are not returned, only how many there are). */
	public static function public_settings( array $tenant ): array {
		$s = array_merge( [ 'auto_approve_intune_apps' => true, 'trust_microsoft_publisher' => false, 'extra_users' => [] ], $tenant['settings'] ?? [] );
		return [
			'auto_approve_intune_apps'  => (bool) $s['auto_approve_intune_apps'],
			'trust_microsoft_publisher' => (bool) $s['trust_microsoft_publisher'],
			'extra_users_count'         => count( (array) $s['extra_users'] ),
			'actions_scope'             => self::action_scope( $tenant ),
		];
	}
}
