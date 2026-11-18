<?php
namespace J365TP\Reports;

use J365TP\Graph_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External collaboration: guest accounts, invitation state, source domain, staleness, sponsors.
 */
final class Guests extends Report_Base {

	public function initial_state( array $params ): array {
		return [ 'phase' => 0, '_warnings' => [], 'premium' => true ];
	}

	public function step( array $state, array $params, float $deadline ): array {
		$select = 'id,displayName,userPrincipalName,mail,accountEnabled,createdDateTime,externalUserState,externalUserStateChangeDateTime,creationType,companyName' . ( $state['premium'] ? ',signInActivity' : '' );
		$url    = "/v1.0/users?\$filter=userType eq 'Guest'&\$select=" . $select . '&$top=' . ( $state['premium'] ? 500 : 999 );
		$head   = [ 'ConsistencyLevel' => 'eventual' ];
		try {
			$done = $this->fetch_collection( $state, 'guests', $url, $deadline, $head, [ $this, 'map' ] );
		} catch ( Graph_Exception $e ) {
			if ( $state['premium'] && empty( $state['guests'] ) && ( $e->is_license_gate() || $e->is_forbidden() || 400 === $e->getCode() ) ) {
				$state['premium'] = false;
				unset( $state['guests'], $state['guests_next'], $state['guests_done'] );
				return [ 'state' => $state, 'progress' => 0, 'total' => 0, 'done' => false, 'message' => 'Repetindo sem signInActivity…' ];
			}
			throw $e;
		}
		$n = count( $state['guests'] ?? [] );
		return [ 'state' => $state, 'progress' => $n, 'total' => $done ? $n : 0, 'done' => $done, 'message' => sprintf( '%d convidados…', $n ) ];
	}

	public function map( array $u ): array {
		$upn    = (string) ( $u['userPrincipalName'] ?? '' );
		$domain = '';
		if ( str_contains( $upn, '#EXT#' ) ) {
			$orig = explode( '#EXT#', $upn )[0];
			$at   = strrpos( $orig, '_' );
			$domain = false !== $at ? substr( $orig, $at + 1 ) : '';
		} elseif ( str_contains( (string) ( $u['mail'] ?? '' ), '@' ) ) {
			$domain = substr( strrchr( (string) $u['mail'], '@' ), 1 );
		}
		return [
			'id'          => (string) $u['id'],
			'displayName' => (string) ( $u['displayName'] ?? '' ),
			'upn'         => $upn,
			'mail'        => (string) ( $u['mail'] ?? '' ),
			'domain'      => strtolower( $domain ),
			'enabled'     => (bool) ( $u['accountEnabled'] ?? false ),
			'created'     => (string) ( $u['createdDateTime'] ?? '' ),
			'state'       => (string) ( $u['externalUserState'] ?? '' ),
			'stateChanged' => (string) ( $u['externalUserStateChangeDateTime'] ?? '' ),
			'company'     => (string) ( $u['companyName'] ?? '' ),
			'lastSignIn'  => self::ts( $u['signInActivity']['lastSignInDateTime'] ?? '' ),
		];
	}

	public function finalize( array $state, array $params ): array {
		$inactive = (int) \J365TP\Settings::get( 'inactive_days', 90 );
		$rows     = [];
		$summary  = [ 'total' => 0, 'pending' => 0, 'accepted' => 0, 'disabled' => 0, 'stale' => 0, 'neverSignIn' => 0, 'byDomain' => [] ];

		foreach ( $state['guests'] ?? [] as $g ) {
			$days  = $g['lastSignIn'] ? self::days_since( $g['lastSignIn'] ) : null;
			$stale = $state['premium'] && ( null === $days || $days > $inactive );
			$rows[] = array_merge( $g, [ 'daysSinceSignIn' => $days, 'stale' => $stale, 'pending' => 'PendingAcceptance' === $g['state'] ] );

			$summary['total']++;
			$summary['pending']  += 'PendingAcceptance' === $g['state'] ? 1 : 0;
			$summary['accepted'] += 'Accepted' === $g['state'] ? 1 : 0;
			$summary['disabled']    += $g['enabled'] ? 0 : 1;
			$summary['stale']       += $stale ? 1 : 0;
			$summary['neverSignIn'] += ( $state['premium'] && null === $days ) ? 1 : 0;
			$dom                     = $g['domain'] ?: '(desconhecido)';
			$summary['byDomain'][ $dom ] = ( $summary['byDomain'][ $dom ] ?? 0 ) + 1;
		}
		arsort( $summary['byDomain'] );

		return [ 'rows' => $rows, 'summary' => $summary, '_meta' => [ 'rows' => count( $rows ), 'premium' => (bool) $state['premium'], 'inactive_days' => $inactive ] ];
	}
}
