<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read the signed-in organization's default Entra branding without storing its image on the host. */
final class Tenant_Logo {
	private const MAX_BYTES = 65536;

	public static function read( string $tenant_id ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $tenant_id ) ) {
			return null;
		}
		try {
			$token = Token_Service::acquire( $tenant_id );
		} catch ( Graph_Exception $e ) {
			return null;
		}
		$base = Token_Service::GRAPH . '/v1.0/organization/' . $tenant_id . '/branding/localizations/default/';
		$deadline = microtime( true ) + 4;
		foreach ( [ 'squareLogo', 'bannerLogo', 'headerLogo', 'squareLogoDark' ] as $property ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining < 0.5 ) {
				break;
			}
			$response = wp_remote_get( $base . $property, [
				'headers'             => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'image/*' ],
				'timeout'             => min( 2, $remaining ),
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
			] );
			if ( is_wp_error( $response ) ) {
				break;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $status || 204 === $status ) {
				continue;
			}
			if ( 200 !== $status ) {
				break;
			}
			$bytes = (string) wp_remote_retrieve_body( $response );
			if ( '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
				continue;
			}
			// Inspect the actual stream. Never return URLs, SVG, HTML or an error document to the header.
			$info = @getimagesizefromstring( $bytes );
			if ( ! $info || ! in_array( $info[2], [ IMAGETYPE_PNG, IMAGETYPE_JPEG ], true )
				|| $info[0] < 1 || $info[1] < 1 || $info[0] > 512 || $info[1] > 512 ) {
				continue;
			}
			return [
				'mime' => $info['mime'], 'data' => base64_encode( $bytes ),
				'wide' => $info[0] > 2 * $info[1], 'dark' => 'squareLogoDark' === $property,
			];
		}
		return null;
	}
}
