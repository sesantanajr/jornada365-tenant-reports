<?php
namespace J365TP\Reports;

use J365TP\Intune_Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Classify inventory metadata and deployment targets without claiming device-level applicability. */
final class App_Identity {
	public static function norm( string $text ): string {
		return (string) preg_replace( '/\s+/u', ' ', mb_strtolower( trim( $text ) ) );
	}

	public static function publisher( string $value ): string {
		$value = self::norm( $value );
		if ( preg_match( '/^(?:cn|o)=/i', $value ) ) {
			$dn = [];
			foreach ( preg_split( '/\s*,\s*(?=[a-z]+\s*=)/', $value ) as $part ) {
				$pair = explode( '=', $part, 2 );
				if ( count( $pair ) !== 2 || isset( $dn[ trim( $pair[0] ) ] ) ) {
					return $value;
				}
				$dn[ trim( $pair[0] ) ] = trim( $pair[1], " \"" );
			}
			return 'microsoft corporation' === ( $dn['cn'] ?? '' ) && 'microsoft corporation' === ( $dn['o'] ?? '' ) ? 'microsoft' : $value;
		}
		return match ( $value ) {
			'microsoft', 'microsoft corporation', 'microsoft corp.' => 'microsoft',
			'teamviewer gmbh', 'teamviewer germany gmbh', 'teamviewer germany gmbh & co. kg' => 'teamviewer',
			'crowdstrike, inc.', 'crowdstrike, inc', 'crowdstrike inc.', 'crowdstrike inc', 'crowdstrike' => 'crowdstrike',
			'zscaler, inc.', 'zscaler, inc', 'zscaler inc.', 'zscaler inc', 'zscaler' => 'zscaler',
			'unknown', 'n/a', 'unknown publisher' => '',
			default => $value,
		};
	}

	public static function windows_component( string $name, string $publisher, string $family ): bool {
		if ( 'Windows' !== $family || 'microsoft' !== self::publisher( $publisher ) ) {
			return false;
		}
		$name = self::norm( $name );
		$components = [ 'microsoft edge', 'microsoft edge webview2 runtime', 'microsoft intune management extension', 'intune management extension',
			'microsoft paint', 'paint', 'microsoft.paint', 'microsoft.mspaint', 'windows alarms & clock', 'windows alarms',
			'microsoft.windowsalarms', 'microsoft.powerautomatedesktop', 'microsoft.rawimageextension',
			'microsoft.windows.devhome', 'microsoft.yourphone', 'microsoft.microsoftedge.stable' ];
		return in_array( $name, $components, true ) || (bool) preg_match( '/^microsoft\.windowsappruntime\.\d+\.\d+$/D', $name );
	}

	private static function product( string $name, string $publisher ): string {
		$name = self::norm( $name );
		$aliases = match ( $publisher ) {
			'teamviewer' => [ 'teamviewer host', 'teamviewer host (64-bit)', 'teamviewer host (32-bit)', 'teamviewer host (x64)', 'teamviewer host (x86)', 'teamviewer host x64' ],
			'crowdstrike' => [ 'crowdstrike windows sensor', 'crowdstrike falcon sensor', 'crowdstrike falcon sensor for windows', 'crowdstrike falcon sensor for mac', 'falcon sensor' ],
			'zscaler' => [ 'zscaler client connector', 'zscaler client connector (x64)', 'zscaler client connector for windows', 'zscaler client connector for macos', 'zscaler app' ],
			default => [],
		};
		return in_array( $name, $aliases, true ) ? 'alias:' . $publisher : $name;
	}

	public static function package_name( string $value ): bool {
		return (bool) preg_match( '/^(?:[a-z][a-z\d_-]*\.)+[a-z\d_-]+$/iD', trim( $value ) );
	}

	public static function catalog( array $app ): array {
		$family = Intune_Platform::catalog( (string) ( $app['@odata.type'] ?? '' ) );
		$package = $app['bundleId'] ?? $app['packageId'] ?? $app['identityName'] ?? '';
		return [ 'id' => (string) ( $app['id'] ?? '' ), 'name' => (string) ( $app['displayName'] ?? '' ),
			'publisher' => (string) ( $app['publisher'] ?? '' ), 'family' => $family, 'package' => self::norm( (string) $package ),
			'published' => 'published' === ( $app['publishingState'] ?? '' ) ];
	}

	/** Index once. Unknown publishers, ambiguous catalog objects or platforms are never proof of identity. */
	public static function match_catalog( array &$state ): void {
		$index = [];
		foreach ( $state['catalog'] ?? [] as $id => $app ) {
			if ( ! $app['published'] ) {
				continue;
			}
			$publisher = self::publisher( $app['publisher'] );
			$product = self::product( $app['name'], $publisher );
			$index[ $app['family'] ][ $product ][] = $id;
			if ( '' !== $app['package'] ) {
				$index[ $app['family'] ][ 'package:' . $app['package'] ][] = $id;
			}
		}
		$ids = [];
		foreach ( $state['groups'] ?? [] as $key => $g ) {
			if ( ! $index && empty( $state['catalogUnavailable'] ) ) {
				break;
			}
			$family = Intune_Platform::detected( $g['platform'] );
			$name = self::norm( $g['name'] );
			$pub = self::publisher( $g['publisher'] );
			if ( 'unknown' === $family || '' === $name ) {
				$state['groups'][ $key ]['uncertain'] = 'Plataforma ou identidade não informada';
				continue;
			}
			$packages = $index[ $family ][ 'package:' . $name ] ?? [];
			$product = self::product( $name, $pub );
			$candidates = $packages ?: ( $index[ $family ][ $product ] ?? [] );
			$matches = [];
			$uncertain = false;
			foreach ( $candidates as $id ) {
				$other = self::publisher( $state['catalog'][ $id ]['publisher'] );
				if ( $pub && $other && $pub !== $other ) {
					continue;
				}
				if ( ! $packages && ( ! $pub || ! $other ) ) {
					$uncertain = true;
					continue;
				}
				$matches[] = $id;
			}
			if ( count( $matches ) === 1 && ! $uncertain ) {
				$id = $matches[0];
				$state['groups'][ $key ]['catalogId'] = $id;
				$state['groups'][ $key ]['identity'] = $packages ? 'Pacote e plataforma' : ( str_starts_with( $product, 'alias:' ) ? 'Produto, editor e plataforma' : 'Nome, editor e plataforma' );
				$ids[ $id ] = true;
			} elseif ( $matches || $uncertain || isset( $index['unknown'][ $product ] ) || ( ! empty( $state['packageUnavailable'] ) && self::package_name( $name ) ) ) {
				$state['groups'][ $key ]['uncertain'] = 'Correspondência inconclusiva no catálogo';
			}
		}
		$state['queue'] = array_keys( $ids );
	}

	public static function add_target( array &$proof, array $raw ): void {
		$a = App_Assignments::assignment_row( $raw );
		$intent = $a['intent'];
		if ( ! in_array( $intent, [ 'required', 'available', 'availableWithoutEnrollment' ], true ) ) {
			if ( 'uninstall' !== $intent ) {
				$proof['incomplete'] = true;
			}
			return;
		}
		$type = $a['target'];
		$filter = $a['filterId'] ? $a['filterType'] . ':' . $a['filterId'] : '';
		if ( ( $a['filterId'] && ! in_array( $a['filterType'], [ 'include', 'exclude' ], true ) ) || ( ! $a['filterId'] && ! in_array( $a['filterType'], [ '', 'none' ], true ) ) ) {
			$proof['incomplete'] = true;
			return;
		}
		if ( 'exclusionGroupAssignmentTarget' === $type ) {
			if ( '' === $a['groupId'] ) {
				$proof['incomplete'] = true;
			} else {
				$proof['exclude'][ $intent ][ $a['groupId'] ][ $filter ] = true;
			}
			return;
		}
		$scope = match ( $type ) {
			'allDevicesAssignmentTarget', 'allLicensedUsersAssignmentTarget' => $type,
			'groupAssignmentTarget' => $a['groupId'],
			'configurationManagerCollectionAssignmentTarget' => '' !== $a['collectionId'] ? 'collection:' . $a['collectionId'] : '',
			default => '',
		};
		if ( '' === $scope ) {
			$proof['incomplete'] = true;
		} else {
			$proof['include'][ $intent ][ $scope ][ $filter ] = true;
		}
	}

	public static function intents( array $proof ): array {
		$result = [];
		foreach ( $proof['include'] ?? [] as $intent => $scopes ) {
			foreach ( $scopes as $scope => $filters ) {
				$exclude = $proof['exclude'][ $intent ][ $scope ] ?? [];
				foreach ( $filters as $filter => $_ ) {
					if ( ! isset( $exclude[''] ) && ! isset( $exclude[ $filter ] ) ) {
						$result[] = $intent;
						continue 3;
					}
				}
			}
		}
		sort( $result );
		return $result;
	}
}
