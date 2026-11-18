<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Company branding belongs to the authenticated directory, separately from the portal operator's logo. */
final class Tenant_Logo {
	public const MAX_BYTES = 65536;
	private const PROPERTIES = [ 'squareLogo', 'bannerLogo', 'headerLogo', 'squareLogoDark' ];
	// Microsoft commercial cloud CDN hosts documented in the organizationalBranding response.
	private const CDN_HOSTS = [ 'secure.aadcdn.microsoftonline-p.com', 'aadcdn.msftauthimages.net', 'aadcdn.msauthimages.net' ];

	private static function option( string $id ): string { return 'j365tp_brand_' . strtolower( $id ); }

	public static function forget( string $id ): void { delete_option( self::option( $id ) ); }

	public static function configure( string $id, string $encoded ): bool {
		if ( strlen( $encoded ) > 87384 ) { return false; }
		$bytes = base64_decode( $encoded, true );
		$image = false === $bytes ? null : self::image( $bytes );
		if ( ! $image ) { return false; }
		$key = self::option( $id );
		return get_option( $key, null ) === $image || update_option( $key, $image, false );
	}

	private static function image( string $bytes, bool $dark = false ): ?array {
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) { return null; }
		$info = @getimagesizefromstring( $bytes );
		if ( ! $info || ! in_array( $info[2], [ IMAGETYPE_PNG, IMAGETYPE_JPEG ], true )
			|| min( $info[0], $info[1] ) < 1 || max( $info[0], $info[1] ) > 512 ) { return null; }
		$end = IMAGETYPE_PNG === $info[2] ? "\0\0\0\0IEND\xae\x42\x60\x82" : "\xff\xd9";
		if ( ! str_ends_with( $bytes, $end ) ) { return null; }
		return [ 'mime' => $info['mime'], 'data' => base64_encode( $bytes ), 'wide' => $info[0] > 2 * $info[1], 'dark' => $dark ];
	}

	private static function fetch( string $url, array $headers, float $deadline, bool $cdn = false ): array {
		$time = $deadline - microtime( true );
		if ( $time < 0.5 ) { return [ 0, '' ]; }
		$args = [ 'headers' => $headers, 'timeout' => min( 4, $time ), 'redirection' => 0, 'limit_response_size' => self::MAX_BYTES + 1 ];
		$r = $cdn ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );
		return is_wp_error( $r ) ? [ 0, '' ] : [ (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) ];
	}

	private static function reason( int $status ): string {
		return match ( $status ) {
			401 => 'authorization', 403 => 'denied', 404, 204 => 'missing',
			429 => 'throttled', default => 'unavailable',
		};
	}

	private static function cdn_image( array $metadata, string $property, float $deadline ): ?array {
		$relative = $metadata[ $property . 'RelativeUrl' ] ?? '';
		if ( ! is_string( $relative ) || '' === $relative || strlen( $relative ) > 2048 ) { return null; }
		$decoded = rawurldecode( $relative );
		if ( preg_match( '~[\x00-\x20\\\\#]|^(?:[a-z]+:|//)|(?:^|/)\.\.(?:/|$)~i', $decoded ) ) { return null; }
		foreach ( (array) ( $metadata['cdnList'] ?? [] ) as $entry ) {
			if ( ! is_string( $entry ) ) { continue; }
			$host = strtolower( rtrim( preg_replace( '~^https://~i', '', $entry ), '/' ) );
			if ( ! in_array( $host, self::CDN_HOSTS, true ) ) { continue; }
			[ $status, $body ] = self::fetch( 'https://' . $host . '/' . ltrim( $relative, '/' ), [ 'Accept' => 'image/*' ], $deadline, true );
			if ( 200 === $status && ( $image = self::image( $body, 'squareLogoDark' === $property ) ) ) { return $image; }
		}
		return null;
	}

	private static function entra( string $id, string $language ): array {
		try { $token = Token_Service::acquire( $id ); }
		catch ( Graph_Exception $e ) { return [ null, 'authorization' ]; }
		$deadline = microtime( true ) + 10;
		$base = Token_Service::GRAPH . '/v1.0/organization/' . $id . '/branding';
		$fields = array_map( static fn( $p ) => $p . 'RelativeUrl', self::PROPERTIES );
		$metadata_url = $base . '?$select=id,cdnList,' . implode( ',', $fields );
		$auth = [ 'Authorization' => 'Bearer ' . $token ];
		$locale = [ 'pt-br' => 'pt-BR', 'es' => 'es-ES', 'en' => 'en-US' ][ strtolower( $language ) ] ?? '';
		$reason = 'missing';
		foreach ( array_filter( [ '0', $locale ], 'strlen' ) as $requested ) {
			[ $status, $body ] = self::fetch( $metadata_url, $auth + [ 'Accept' => 'application/json', 'Accept-Language' => $requested ], $deadline );
			if ( 404 === $status || 204 === $status ) { continue; }
			if ( 200 !== $status ) { return [ null, self::reason( $status ) ]; }
			$metadata = json_decode( $body, true );
			if ( ! is_array( $metadata ) ) { return [ null, 'unavailable' ]; }
			$localization = (string) ( $metadata['id'] ?? $requested );
			if ( ! in_array( $localization, [ '0', 'default', $requested ], true ) ) { continue; }
			$advertised = array_filter( self::PROPERTIES, static fn( $p ) => ! empty( $metadata[ $p . 'RelativeUrl' ] ) );
			// Some tenants omit relative URLs. Their raw streams may still be populated.
			$unlisted = $metadata ? array_filter( self::PROPERTIES, static fn( $p ) => ! array_key_exists( $p . 'RelativeUrl', $metadata ) ) : [];
			foreach ( array_merge( $advertised, $unlisted ) as $property ) {
				[ $code, $stream ] = self::fetch( $base . '/localizations/' . rawurlencode( $localization ) . '/' . $property, $auth + [ 'Accept' => 'image/*' ], $deadline );
				$dark = 'squareLogoDark' === $property;
				$image = 200 === $code ? self::image( $stream, $dark ) : null;
				// Only an authenticated metadata response can supply the CDN path. Never forward credentials.
				$image ??= self::cdn_image( $metadata, $property, $deadline );
				if ( $image ) { return [ $image, 'ok' ]; }
				if ( 200 !== $code && 204 !== $code && 404 !== $code ) { return [ null, self::reason( $code ) ]; }
				if ( 200 === $code && ! in_array( trim( $stream ), [ '', '{}', 'null' ], true ) ) { $reason = 'invalid'; }
			}
		}
		return [ null, $reason ];
	}

	public static function resolve( string $id, string $language ): array {
		if ( ! wp_is_uuid( $id ) ) { return [ 'logo' => null, 'source' => 'initials', 'reason' => 'authorization', 'configured' => false ]; }
		[ $image, $reason ] = self::entra( $id, $language );
		$fallback = get_option( self::option( $id ), null );
		$has = is_array( $fallback ) && ! empty( $fallback['data'] );
		return [ 'logo' => $image ?? ( $has ? $fallback : null ), 'source' => $image ? 'entra' : ( $has ? 'tenant' : 'initials' ), 'reason' => $reason, 'configured' => $has ];
	}
}
