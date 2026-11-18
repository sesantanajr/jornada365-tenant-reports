<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Platform families for inventory and Intune's polymorphic mobileApp catalog. */
final class Intune_Platform {
	public static function detected( string $raw ): string {
		return match ( strtolower( trim( $raw ) ) ) {
			'windows', 'windowsmobile', 'windowsholographic' => 'Windows',
			'ios', 'ipados', 'ios/ipados' => 'iOS/iPadOS',
			'macos', 'macosx' => 'macOS',
			'android', 'androidosp', 'androiddeviceadministrator', 'androidworkprofile', 'androiddedicatedandfullymanaged' => 'Android',
			'chromeos', 'chrome os' => 'Chrome OS',
			'linux' => 'Linux',
			default => 'unknown',
		};
	}

	public static function catalog( string $odata_type ): string {
		$type = strtolower( preg_replace( '/^#?microsoft\.graph\./i', '', trim( $odata_type ) ) );
		// Derived type names carry their OS even when a localized app title or publisher does not.
		if ( preg_match( '/^(?:managed)?(android|ios|macos|windows|win32|winget|linux|chromeos)/', $type, $parts ) ) {
			return in_array( $parts[1], [ 'win32', 'winget' ], true ) ? 'Windows' : self::detected( $parts[1] );
		}
		return match ( $type ) {
			'officesuiteapp', 'microsoftstoreforbusinessapp' => 'Windows',
			'webapp' => 'Web',
			default => 'unknown',
		};
	}
}
