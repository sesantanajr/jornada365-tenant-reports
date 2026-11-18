<?php
namespace J365TP\Reports;

use J365TP\Graph_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups: source (AD-synced vs cloud), type, dynamic/assigned, member composition
 * (users / nested groups / devices / service principals), empty groups, owners.
 * Counts are gathered with JSON $batch (4 groups × 5 count requests per call).
 */
final class Groups extends Report_Base {

	private const SELECT = 'id,displayName,description,mail,mailNickname,mailEnabled,securityEnabled,groupTypes,membershipRule,membershipRuleProcessingState,onPremisesSyncEnabled,onPremisesLastSyncDateTime,onPremisesDomainName,createdDateTime,visibility,isAssignableToRole,resourceProvisioningOptions,classification,expirationDateTime,renewedDateTime';

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, 'idx' => 0, 'counts' => [], '_warnings' => [] ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		if ( 0 === $state['phase'] ) {
			$done = $this->fetch_collection( $state, 'groups', '/v1.0/groups?$select=' . self::SELECT . '&$top=999', $deadline );
			if ( ! $done ) {
				return [ 'state' => $state, 'progress' => count( $state['groups'] ), 'total' => 0, 'done' => false, 'message' => sprintf( '%d grupos carregados…', count( $state['groups'] ) ) ];
			}
			$state['phase'] = 1;
		}

		$groups = $state['groups'];
		$total  = count( $groups );
		$kinds  = [ 'all' => 'members/$count', 'users' => 'members/microsoft.graph.user/$count', 'groups' => 'members/microsoft.graph.group/$count', 'devices' => 'members/microsoft.graph.device/$count', 'owners' => 'owners/$count' ];

		while ( $state['idx'] < $total && ! $this->out_of_time( $deadline ) ) {
			// Pack up to 20 sub-requests per $batch. Microsoft 365 groups can't contain groups or
			// devices — those casts answer 400 Request_UnsupportedQuery — so they are skipped (= 0).
			$requests = [];
			$slice    = [];
			$i        = $state['idx'];
			while ( $i < $total ) {
				$g       = $groups[ $i ];
				$unified = in_array( 'Unified', (array) ( $g['groupTypes'] ?? [] ), true );
				$need    = $unified ? [ 'all', 'users', 'owners' ] : array_keys( $kinds );
				if ( count( $requests ) + count( $need ) > 20 ) {
					break;
				}
				foreach ( $need as $kind ) {
					$requests[] = [
						'id'      => $i . ':' . $kind,
						'method'  => 'GET',
						'url'     => '/groups/' . $g['id'] . '/' . $kinds[ $kind ],
						'headers' => [ 'ConsistencyLevel' => 'eventual' ],
					];
				}
				$slice[ $i ] = $unified;
				++$i;
			}
			$responses = $this->fetch_batch( $state, '_group_counts_batch', $requests );
			if ( null === $responses ) {
				break;
			}
			foreach ( $slice as $idx => $unified ) {
				$row = [];
				foreach ( array_keys( $kinds ) as $kind ) {
					if ( $unified && in_array( $kind, [ 'groups', 'devices' ], true ) ) {
						$row[ $kind ] = 0;
						continue;
					}
					$r            = $responses[ $idx . ':' . $kind ] ?? null;
					$row[ $kind ] = ( $r && 200 === $r['status'] ) ? Graph_Client::batch_int( $r['body'] ) : null;
				}
				$state['counts'][ $groups[ $idx ]['id'] ] = $row;
			}
			$state['idx'] = $i;
		}

		$done = $state['idx'] >= $total;
		return [ 'state' => $state, 'progress' => $state['idx'], 'total' => $total, 'done' => $done, 'message' => sprintf( 'Contando membros: %d de %d grupos…', $state['idx'], $total ) ];
	}

	public function finalize( array $state, array $params ): array {
		$rows    = [];
		$summary = [
			'total'       => 0,
			'synced'      => 0,
			'cloudOnly'   => 0,
			'm365'        => 0,
			'security'    => 0,
			'distribution' => 0,
			'mailSecurity' => 0,
			'dynamic'     => 0,
			'assigned'    => 0,
			'nested'      => 0,
			'empty'       => 0,
			'usersOnly'   => 0,
			'devicesOnly' => 0,
			'mixed'       => 0,
			'noOwner'     => 0,
			'roleAssignable' => 0,
			'teams'       => 0,
		];

		foreach ( $state['groups'] as $g ) {
			$types   = (array) ( $g['groupTypes'] ?? [] );
			$is_m365 = in_array( 'Unified', $types, true );
			$dynamic = in_array( 'DynamicMembership', $types, true );
			$sec     = (bool) ( $g['securityEnabled'] ?? false );
			$mail    = (bool) ( $g['mailEnabled'] ?? false );
			$type    = $is_m365 ? 'Microsoft 365' : ( $sec && $mail ? 'Segurança habilitado para email' : ( $sec ? 'Segurança' : ( $mail ? 'Distribuição' : 'Outro' ) ) );
			$synced  = ! empty( $g['onPremisesSyncEnabled'] );
			$c       = $state['counts'][ $g['id'] ] ?? [];
			$all     = $c['all'] ?? null;
			$users   = $c['users'] ?? null;
			$nested  = $c['groups'] ?? null;
			$devices = $c['devices'] ?? null;
			$owners  = $c['owners'] ?? null;
			$others  = ( null !== $all && null !== $users && null !== $nested && null !== $devices ) ? max( 0, $all - $users - $nested - $devices ) : null;

			$composition = 'Desconhecido';
			if ( null !== $all ) {
				if ( 0 === $all ) {
					$composition = 'Vazio';
				} elseif ( $users === $all ) {
					$composition = 'Somente usuários';
				} elseif ( $devices === $all ) {
					$composition = 'Somente dispositivos';
				} elseif ( $nested === $all ) {
					$composition = 'Somente grupos';
				} else {
					$composition = 'Misto';
				}
			}

			$row = [
				'id'            => (string) $g['id'],
				'name'          => (string) ( $g['displayName'] ?? '' ),
				'description'   => (string) ( $g['description'] ?? '' ),
				'mail'          => (string) ( $g['mail'] ?? '' ),
				'type'          => $type,
				'source'        => $synced ? 'AD sincronizado' : 'Cloud-only',
				'onPremDomain'  => (string) ( $g['onPremisesDomainName'] ?? '' ),
				'membership'    => $dynamic ? 'Dinâmico' : 'Atribuído',
				'rule'          => (string) ( $g['membershipRule'] ?? '' ),
				'ruleState'     => (string) ( $g['membershipRuleProcessingState'] ?? '' ),
				'members'       => $all,
				'users'         => $users,
				'nestedGroups'  => $nested,
				'devices'       => $devices,
				'others'        => $others,
				'owners'        => $owners,
				'ownersApplicable' => ! $synced && ( $is_m365 || ( $sec && ! $mail ) ),
				'composition'   => $composition,
				'isNested'      => null !== $nested && $nested > 0,
				'isEmpty'       => 0 === $all,
				'roleAssignable' => (bool) ( $g['isAssignableToRole'] ?? false ),
				'isTeam'        => in_array( 'Team', (array) ( $g['resourceProvisioningOptions'] ?? [] ), true ),
				'visibility'    => (string) ( $g['visibility'] ?? '' ),
				'created'       => (string) ( $g['createdDateTime'] ?? '' ),
				'lastSync'      => (string) ( $g['onPremisesLastSyncDateTime'] ?? '' ),
				'expiration'    => (string) ( $g['expirationDateTime'] ?? '' ),
			];
			$rows[] = $row;

			$summary['total']++;
			$summary[ $synced ? 'synced' : 'cloudOnly' ]++;
			$summary[ [ 'Microsoft 365' => 'm365', 'Segurança' => 'security', 'Distribuição' => 'distribution', 'Segurança habilitado para email' => 'mailSecurity' ][ $type ] ?? 'security' ]++;
			$summary[ $dynamic ? 'dynamic' : 'assigned' ]++;
			$summary['nested']        += $row['isNested'] ? 1 : 0;
			$summary['empty']         += $row['isEmpty'] ? 1 : 0;
			$summary['usersOnly']     += 'Somente usuários' === $composition ? 1 : 0;
			$summary['devicesOnly']   += 'Somente dispositivos' === $composition ? 1 : 0;
			$summary['mixed']         += 'Misto' === $composition ? 1 : 0;
			// Owners aren't exposed for AD-synced, distribution or Exchange-managed groups.
			$summary['noOwner']       += ( 0 === $owners && $row['ownersApplicable'] ) ? 1 : 0;
			$summary['roleAssignable'] += $row['roleAssignable'] ? 1 : 0;
			$summary['teams']         += $row['isTeam'] ? 1 : 0;
		}
		usort( $rows, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ) ] ];
	}
}
