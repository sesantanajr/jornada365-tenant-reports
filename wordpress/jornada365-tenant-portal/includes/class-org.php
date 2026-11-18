<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Organization display data read live from Microsoft Graph (never persisted on the server).
 */
final class Org {

	/** @return array{name:string,domain:string} best effort; empty strings on failure */
	public static function lookup( string $tenant_id ): array {
		try {
			$org   = ( new Graph_Client( $tenant_id ) )->get( '/v1.0/organization?$select=displayName,verifiedDomains' );
			$first = $org['value'][0] ?? [];
			$dom   = '';
			foreach ( (array) ( $first['verifiedDomains'] ?? [] ) as $d ) {
				if ( ! empty( $d['isDefault'] ) ) {
					$dom = (string) $d['name'];
				}
			}
			return [ 'name' => (string) ( $first['displayName'] ?? '' ), 'domain' => $dom ];
		} catch ( \Throwable $e ) {
			return [ 'name' => '', 'domain' => '' ];
		}
	}
}
