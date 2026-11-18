<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-tenant list of approved (homologated) applications.
 */
final class Allowlist_Repository {

	public const PLATFORMS   = [ 'any', 'windows', 'windowsMobile', 'windowsHolographic', 'macOS', 'iOS', 'iPadOS', 'androidOSP',
		'androidDeviceAdministrator', 'androidWorkProfile', 'androidDedicatedAndFullyManaged', 'chromeOS', 'linux', 'other', 'unknown', 'unknownFutureValue' ];
	public const MATCH_TYPES = [ 'exact', 'contains', 'prefix', 'regex' ];

	public static function all( string $tenant_id ): array {
		global $wpdb;
		$table = Installer::table( 'app_allowlist' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY app_name ASC", strtolower( $tenant_id ) ), ARRAY_A ) ?: [];
	}

	public static function add( string $tenant_id, array $data, string $actor ): array {
		global $wpdb;
		$platforms = array_combine( array_map( 'strtolower', self::PLATFORMS ), self::PLATFORMS );
		$platform = strtolower( trim( (string) ( $data['platform'] ?? 'any' ) ) );
		if ( ! isset( $platforms[ $platform ] ) ) {
			throw new \InvalidArgumentException( 'Plataforma não reconhecida para esta regra.' );
		}
		$match_type = $data['match_type'] ?? 'exact';
		$row = [
			'tenant_id'  => strtolower( $tenant_id ),
			'app_name'   => mb_substr( sanitize_text_field( $data['app_name'] ?? '' ), 0, 255 ),
			'publisher'  => mb_substr( sanitize_text_field( $data['publisher'] ?? '' ), 0, 255 ),
			'platform'   => $platforms[ $platform ],
			'match_type' => in_array( $match_type, self::MATCH_TYPES, true ) ? $match_type : 'exact',
			'notes'      => mb_substr( sanitize_text_field( $data['notes'] ?? '' ), 0, 500 ),
			'created_by' => Privacy::uid( $actor ), // pseudonym only
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		];
		if ( '' === $row['app_name'] ) {
			throw new \InvalidArgumentException( 'Nome do aplicativo é obrigatório.' );
		}
		if ( 'regex' === $row['match_type'] && ( mb_strlen( $row['app_name'] ) > 200 || false === @preg_match( '/' . str_replace( '/', '\/', $row['app_name'] ) . '/iu', '' ) ) ) {
			throw new \InvalidArgumentException( 'Expressão regular inválida.' );
		}
		if ( false === $wpdb->insert( Installer::table( 'app_allowlist' ), $row ) ) {
			throw new \RuntimeException( 'Não foi possível salvar a regra de homologação.' );
		}
		$row['id'] = (int) $wpdb->insert_id;
		Audit::log( 'allowlist_add', $tenant_id, $actor, $row );
		Report_Cache::invalidate( $tenant_id, 'apps' );
		return $row;
	}

	public static function remove( string $tenant_id, int $id, string $actor ): bool {
		global $wpdb;
		$deleted = $wpdb->delete( Installer::table( 'app_allowlist' ), [ 'id' => $id, 'tenant_id' => strtolower( $tenant_id ) ] );
		if ( $deleted ) {
			Audit::log( 'allowlist_remove', $tenant_id, $actor, [ 'id' => $id ] );
			Report_Cache::invalidate( $tenant_id, 'apps' );
		}
		return (bool) $deleted;
	}

	/**
	 * @return string|null matching rule description, or null when not approved.
	 */
	public static function match( array $rules, string $name, string $publisher, string $platform ): ?string {
		$n = mb_strtolower( trim( $name ) );
		$p = mb_strtolower( trim( $publisher ) );
		foreach ( $rules as $rule ) {
			if ( 'any' !== $rule['platform'] && strcasecmp( $rule['platform'], $platform ) !== 0 ) {
				continue;
			}
			$rn = mb_strtolower( trim( (string) $rule['app_name'] ) );
			$ok = match ( $rule['match_type'] ) {
				'contains' => '' !== $rn && str_contains( $n, $rn ),
				'prefix'   => '' !== $rn && str_starts_with( $n, $rn ),
				'regex'    => (bool) @preg_match( '/' . str_replace( '/', '\/', (string) $rule['app_name'] ) . '/iu', $name ),
				default    => $n === $rn,
			};
			if ( ! $ok ) {
				continue;
			}
			$rp = mb_strtolower( trim( (string) $rule['publisher'] ) );
			if ( '' !== $rp && ! str_contains( $p, $rp ) ) {
				continue;
			}
			return 'Allowlist: ' . $rule['app_name'] . ( '' !== $rp ? ' (' . $rule['publisher'] . ')' : '' );
		}
		return null;
	}
}
