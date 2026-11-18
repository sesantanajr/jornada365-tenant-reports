<?php
namespace J365TP\Reports;

use J365TP\Allowlist_Repository;
use J365TP\Graph_Exception;
use J365TP\Intune_Platform;
use J365TP\Tenant_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Discovered software and tenant policy evidence. Assignments are read only for matched identities. */
final class Apps extends Report_Base {
	public function initial_state( array $params ): array {
		return [ 'inventorySchema' => 2, 'phase' => 0, 'entries' => 0, 'groups' => [], '_warnings' => [] ];
	}

	private static function progress( array $state, bool $done, string $message ): array {
		$n = $state['entries'] + count( $state['catalog'] ?? [] ) + ( $state['offset'] ?? 0 );
		return [ 'state' => $state, 'progress' => $n, 'total' => $done ? $n : 0, 'done' => $done, 'message' => $message ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( empty( $state['inventorySchema'] ) ) {
			// Older in-flight jobs grouped distinct publishers together; rebuild identity evidence once.
			$state = $this->initial_state( $params );
		}
		if ( 0 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'inventory', '/v1.0/deviceManagement/detectedApps?$select=id,displayName,version,publisher,platform,deviceCount&$top=1000',
				$deadline, [], null, [ self::class, 'collect_detected' ], 4 );
			if ( ! $done ) {
				return self::progress( $state, false, 'Consultando inventário descoberto…' );
			}
			unset( $state['seen'], $state['inventory'] );
			$tenant = Tenant_Repository::get( $this->tenant_id ) ?? [];
			$state['autoIntune'] = (bool) Tenant_Repository::get_setting( $tenant, 'auto_approve_intune_apps', true );
			$state['phase'] = $state['autoIntune'] ? 1 : 3;
			return self::progress( $state, 3 === $state['phase'], 'Inventário descoberto consultado.' );
		}
		if ( 1 === $state['phase'] ) {
			$fields = 'id,displayName,publisher,publishingState';
			if ( empty( $state['packageUnavailable'] ) ) {
				$projections = [
					'iOS/iPadOS' => ',microsoft.graph.iosVppApp/bundleId,microsoft.graph.iosStoreApp/bundleId,microsoft.graph.managedIOSStoreApp/bundleId,microsoft.graph.iosLobApp/bundleId',
					'Android' => ',microsoft.graph.androidLobApp/packageId,microsoft.graph.androidManagedStoreApp/packageId',
					'Windows' => ',microsoft.graph.windowsAppX/identityName,microsoft.graph.windowsUniversalAppX/identityName',
				];
				foreach ( $state['packageFamilies'] ?? [] as $family => $_ ) {
					$fields .= $projections[ $family ];
				}
			}
			try {
				$done = $this->fetch_collection( $state, 'mobile', '/v1.0/deviceAppManagement/mobileApps?$select=' . $fields . '&$top=250', $deadline, [], [ App_Identity::class, 'catalog' ],
					static function ( array &$st, array $item ) {
						if ( '' !== $item['id'] ) {
							$st['catalog'][ $item['id'] ] = $item;
						}
					}, 4 );
			} catch ( Graph_Exception $error ) {
				if ( in_array( $error->getCode(), [ 400, 501 ], true ) && ! empty( $state['packageFamilies'] ) && empty( $state['packageUnavailable'] ) ) {
					// Some Intune backends only project base-type fields. Restart this collection once.
					$state['packageUnavailable'] = true;
					unset( $state['mobile_next'], $state['mobile_done'] );
					return self::progress( $state, false, 'Consultando catálogo com campos compatíveis…' );
				}
				$this->optional_error( $state, 'Catálogo do Intune indisponível', $error );
				$state['catalogUnavailable'] = true;
				$done = true;
			}
			if ( ! $done ) {
				return self::progress( $state, false, 'Carregando catálogo do Intune…' );
			}
			App_Identity::match_catalog( $state );
			$state['offset'] = 0;
			$state['phase'] = 2;
			return self::progress( $state, ! $state['queue'], 'Catálogo do Intune consultado.' );
		}
		if ( 2 === $state['phase'] && ! $this->assignments( $state, $deadline ) ) {
			return self::progress( $state, false, 'Conferindo atribuições dos aplicativos identificados…' );
		}
		return self::progress( $state, true, 'Concluído' );
	}

	private function assignments( array &$state, float $deadline ): bool {
		if ( ! empty( $state['more'] ) && ! $this->out_of_time( $deadline ) ) {
			$id = array_key_first( $state['more'] );
			try {
				$done = $this->fetch_collection( $state, 'targets', $state['more'][ $id ], $deadline, [], null,
					static function ( array &$st, array $target ) use ( $id ) {
						App_Identity::add_target( $st['proof'][ $id ], $target );
					}, 4 );
				if ( ! $done ) {
					return false;
				}
			} catch ( Graph_Exception $error ) {
				$this->optional_error( $state, 'Atribuições do Intune indisponíveis', $error );
				$state['proof'][ $id ]['incomplete'] = true;
			}
			unset( $state['more'][ $id ], $state['targets'], $state['targets_done'], $state['targets_next'] );
		}
		if ( ! empty( $state['more'] ) ) {
			return false;
		}
		$ids = array_slice( $state['queue'], $state['offset'], 20 );
		if ( ! $ids ) {
			return true;
		}
		if ( $this->out_of_time( $deadline ) ) {
			return false;
		}
		$requests = [];
		foreach ( $ids as $index => $id ) {
			$requests[] = [ 'id' => (string) $index, 'method' => 'GET', 'url' => App_Assignments::assignments_path( $id ) ];
		}
		try {
			$replies = $this->fetch_batch( $state, 'targetWindow', $requests );
		} catch ( Graph_Exception $error ) {
			$this->optional_error( $state, 'Atribuições do Intune indisponíveis', $error );
			$replies = [];
		}
		if ( null === $replies ) {
			return false;
		}
		foreach ( $ids as $index => $id ) {
			$reply = $replies[ $index ] ?? [];
			$body = $reply['body'] ?? [];
			$state['proof'][ $id ] = [];
			if ( 200 !== ( $reply['status'] ?? 0 ) || ! is_array( $body['value'] ?? null ) || ! array_is_list( $body['value'] ) ) {
				$state['proof'][ $id ]['incomplete'] = true;
				continue;
			}
			foreach ( $body['value'] as $assignment ) {
				App_Identity::add_target( $state['proof'][ $id ], $assignment );
			}
			if ( ! empty( $body['@odata.nextLink'] ) ) {
				$state['more'][ $id ] = $body['@odata.nextLink'];
			}
		}
		$state['offset'] += count( $ids );
		return $state['offset'] >= count( $state['queue'] ) && empty( $state['more'] );
	}

	public function finalize( array $state, array $params ): array {
		$tenant = Tenant_Repository::get( $this->tenant_id ) ?? [];
		$auto = (bool) Tenant_Repository::get_setting( $tenant, 'auto_approve_intune_apps', true );
		$components = (bool) Tenant_Repository::get_setting( $tenant, 'recognize_windows_components', true );
		$trust = (bool) Tenant_Repository::get_setting( $tenant, 'trust_microsoft_publisher', false );
		$rules = Allowlist_Repository::all( $this->tenant_id );
		$rows = [];
		$s = [ 'apps' => 0, 'entries' => $state['entries'] ?? 0, 'approved' => 0, 'notApproved' => 0, 'unknown' => 0, 'approvedIntune' => 0,
			'approvedAllowlist' => 0, 'approvedPublisher' => 0, 'approvedComponent' => 0, 'multiVersion' => 0, 'byPlatform' => [], 'catalogByPlatform' => [],
			'catalogState' => empty( $state['autoIntune'] ) ? 'disabled' : ( ! empty( $state['catalogUnavailable'] ) ? 'partial' : 'complete' ),
			'intuneCatalog' => null, 'assignmentUnknown' => 0, 'packageUnavailable' => ! empty( $state['packageUnavailable'] ) ];
		foreach ( $state['catalog'] ?? [] as $app ) {
			$s['catalogByPlatform'][ $app['family'] ] = 1 + ( $s['catalogByPlatform'][ $app['family'] ] ?? 0 );
		}
		if ( 'complete' === $s['catalogState'] ) {
			$s['intuneCatalog'] = count( $state['catalog'] ?? [] );
		}
		foreach ( $state['groups'] ?? [] as $g ) {
			$id = $g['catalogId'] ?? '';
			$app = $state['catalog'][ $id ] ?? [];
			$proof = $state['proof'][ $id ] ?? [];
			$family = Intune_Platform::detected( $g['platform'] );
			$component = App_Identity::windows_component( $g['name'], $g['publisher'], $family );
			$intents = App_Identity::intents( $proof );
			$reason = $g['uncertain'] ?? '';
			$approved = ''; $via = '';
			if ( ! empty( $proof['incomplete'] ) ) {
				$reason = 'Atribuições incompletas';
				++$s['assignmentUnknown'];
			} elseif ( $id && $intents ) {
				$reason = 'Inclusão conforme o público configurado';
			} elseif ( $id ) {
				$reason = 'Sem atribuição positiva incluída';
			}
			if ( null !== ( $rule = Allowlist_Repository::match( $rules, $g['name'], $g['publisher'], $g['platform'] ) ) ) {
				$approved = $rule; $via = 'approvedAllowlist';
			} elseif ( $auto && 'complete' === $s['catalogState'] && $intents && empty( $proof['incomplete'] ) ) {
				$approved = 'Atribuição incluída no Intune'; $via = 'approvedIntune';
			} elseif ( $components && $component ) {
				$approved = 'Componente Windows e editor Microsoft'; $via = 'approvedComponent';
			} elseif ( $trust && 'microsoft' === App_Identity::publisher( $g['publisher'] ) ) {
				$approved = 'Publisher confiável (Microsoft)'; $via = 'approvedPublisher';
			}
			$known = '' !== $approved || ! $auto || ( 'complete' === $s['catalogState'] && empty( $g['uncertain'] ) && empty( $proof['incomplete'] ) );
			if ( ! $known && ( ! $reason || 'complete' !== $s['catalogState'] ) ) {
				$reason = 'Catálogo incompleto';
			}
			if ( $via ) {
				++$s[ $via ];
			}
			++$s[ ! $known ? 'unknown' : ( $approved ? 'approved' : 'notApproved' ) ];
			$versions = $g['versions'];
			usort( $versions, static fn( $a, $b ) => strnatcasecmp( $b['version'], $a['version'] ) );
			$version_count = count( array_unique( array_column( $versions, 'version' ) ) );
			$s['multiVersion'] += $version_count > 1 ? 1 : 0;
			++$s['apps'];
			$s['byPlatform'][ $family ] = 1 + ( $s['byPlatform'][ $family ] ?? 0 );
			$rows[] = [ 'name' => $g['name'], 'publisher' => $g['publisher'], 'platform' => $g['platform'], 'family' => $family,
				'approved' => '' !== $approved, 'approvedBy' => $approved, 'approvalKnown' => $known, 'component' => $component,
				'identity' => $g['identity'] ?? '', 'catalogApp' => $app['name'] ?? '', 'intents' => $intents, 'scopeNote' => $reason,
				'versionCount' => $version_count, 'latestVersion' => $versions[0]['version'] ?? '', 'versions' => $versions,
				'deviceCount' => $g['deviceCount'], 'ids' => array_column( $versions, 'id' ) ];
		}
		usort( $rows, static fn( $a, $b ) => $b['deviceCount'] <=> $a['deviceCount'] ?: strnatcasecmp( $a['name'], $b['name'] ) );
		return [ 'rows' => $rows, 'summary' => $s, 'settings' => [ 'auto_approve_intune_apps' => $auto, 'trust_microsoft_publisher' => $trust,
			'recognize_windows_components' => $components ], '_meta' => [ 'rows' => count( $rows ) ] ];
	}

	protected static function collect_detected( array &$state, array $app ): void {
		$id = (string) ( $app['id'] ?? '' );
		if ( '' === $id || isset( $state['seen'][ $id ] ) ) {
			return;
		}
		$state['seen'][ $id ] = true;
		$name = (string) ( $app['displayName'] ?? '' );
		$publisher = (string) ( $app['publisher'] ?? '' );
		$platform = (string) ( $app['platform'] ?? 'unknown' );
		$key = wp_json_encode( [ App_Identity::norm( $name ) ?: $id, strtolower( $platform ), App_Identity::publisher( $publisher ) ] );
		if ( ! isset( $state['groups'][ $key ] ) ) {
			$state['groups'][ $key ] = [ 'name' => $name, 'publisher' => $publisher, 'platform' => $platform, 'versions' => [], 'deviceCount' => 0 ];
		}
		$g = &$state['groups'][ $key ];
		$count = is_numeric( $app['deviceCount'] ?? null ) ? (int) $app['deviceCount'] : null;
		$g['deviceCount'] = null === $count || null === $g['deviceCount'] ? null : $g['deviceCount'] + $count;
		$g['versions'][] = [ 'version' => (string) ( $app['version'] ?? '' ), 'devices' => $count, 'id' => $id ];
		if ( App_Identity::package_name( $name ) ) {
			$family = Intune_Platform::detected( $platform );
			if ( in_array( $family, [ 'iOS/iPadOS', 'Windows', 'Android' ], true ) ) {
				$state['packageFamilies'][ $family ] = true;
			}
		}
		++$state['entries'];
	}
}
