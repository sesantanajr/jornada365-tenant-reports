<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant-wide admin consent ("register the app in my tenant").
 *
 * The administrator is sent to the Microsoft identity platform admin consent endpoint; on
 * acceptance Entra ID creates the Enterprise Application (service principal) of the
 * multi-tenant "Tenant Jornada365" app in *their* tenant with the configured read-only
 * permissions. Nothing is registered manually and no customer credential is stored.
 *
 * Two entry points:
 *  - start_for_identity(): automatic, right after sign-in, for an admin of a tenant that is
 *    not connected yet. The verified identity is kept server-side and bound to this browser
 *    by an HttpOnly cookie; after consent the session is created and the admin lands in the
 *    dashboard without signing in again.
 *  - start(): from a shared link (/j365/consent/start/?t=<tenant or domain>) for admins who
 *    were sent the link by a colleague; after consent they sign in normally.
 */
final class Consent {

	private const STATE_TTL = 900;
	public const FLOW_COOKIE = 'j365tp_flow';

	/** Directory roles allowed to grant tenant-wide consent to Graph application permissions. */
	public const CONSENT_ROLES = [
		'62e90394-69f5-4237-9190-012177145e10', // Global Administrator
		'e8611ab8-c189-46e8-94e1-60213ab1f814', // Privileged Role Administrator
	];

	/**
	 * Decision for a signed-in user whose tenant is not connected.
	 *
	 * @param mixed $wids `wids` claim from the ID token (array of role template IDs) or null when absent.
	 * @return string 'consent' (send to admin consent) | 'ask_admin' (show the "ask your admin" page)
	 */
	public static function decide( $wids ): string {
		if ( ! is_array( $wids ) ) {
			return 'consent'; // claim not emitted → let Microsoft decide on the consent screen
		}
		$wids = array_map( 'strtolower', array_map( 'strval', $wids ) );
		return array_intersect( $wids, self::CONSENT_ROLES ) ? 'consent' : 'ask_admin';
	}

	/** Marks "an administrator approved minutes ago" (only changes the waiting page, grants nothing). */
	public static function wait_key( string $tenant_id ): string {
		return 'j365tp_cwait_' . md5( strtolower( $tenant_id ) );
	}

	/* ------------------------------------------------------------ entry points */

	public static function start( string $tenant_hint = '' ): void {
		self::guard();
		self::redirect( Request::tenant_segment( $tenant_hint ), [ 'kind' => 'link', 'tid' => strtolower( $tenant_hint ) ] );
	}

	public static function start_for_identity( string $tenant_id, string $oid, string $upn, string $name ): void {
		self::guard();
		$binding = Crypto::random_token( 32 );
		self::set_flow_cookie( $binding, time() + self::STATE_TTL );
		Audit::log( 'consent_auto_start', $tenant_id, $upn );
		// The verified identity waits for Microsoft's answer sealed with a key that only this
		// browser's HttpOnly cookie can rebuild: the server keeps no readable UPN or name meanwhile.
		self::redirect(
			strtolower( $tenant_id ),
			[
				'kind'     => 'identity',
				'tid'      => strtolower( $tenant_id ),
				'identity' => Crypto::seal( (string) wp_json_encode( [ 'oid' => $oid, 'upn' => $upn, 'name' => $name ] ), self::flow_key( $binding ) ),
				'binding'  => hash( 'sha256', $binding ),
			]
		);
	}

	private static function guard(): void {
		if ( ! Settings::is_configured() ) {
			Router::render_message( 'Portal em configuração', 'O aplicativo ainda não foi registrado pelo operador do portal. Tente novamente mais tarde.', 'error' );
		}
		if ( ! Rate_Limiter::allow( 'consent', 10, 600 ) ) {
			Router::render_message( 'Muitas tentativas', 'Aguarde alguns minutos e tente novamente.', 'error', 429 );
		}
	}

	private static function redirect( string $segment, array $context ): void {
		$state = Crypto::random_token( 24 );
		$context['created'] = time(); // short-lived state only (15 min); no client IP kept
		$context['lang']    = I18n::current(); // the pages after Microsoft's screen keep the visitor's language
		set_transient( 'j365tp_consent_' . $state, $context, self::STATE_TTL );

		// v2 admin consent with /.default grants every permission configured on the app registration
		// (application + delegated) on behalf of the whole organization.
		$params = [
			'client_id'    => Settings::client_id(),
			'scope'        => Token_Service::GRAPH . '/.default',
			'redirect_uri' => Request::consent_redirect_uri(),
			'state'        => $state,
			'ui_locales'   => I18n::ms_locale(),
		];
		wp_safe_redirect( 'https://login.microsoftonline.com/' . $segment . '/v2.0/adminconsent?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
		exit;
	}

	/* ------------------------------------------------------------ callback */

	public static function callback(): void {
		$state  = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$stored = '' !== $state ? get_transient( 'j365tp_consent_' . $state ) : false;
		if ( ! is_array( $stored ) ) {
			Router::render_message( 'Solicitação expirada', 'A solicitação de aprovação expirou ou é inválida. Entre novamente para recomeçar.', 'error' );
		}
		delete_transient( 'j365tp_consent_' . $state );
		if ( ! empty( $stored['lang'] ) ) {
			I18n::set( (string) $stored['lang'] );
		}

		// Only a browser that signed in (OIDC-verified tenant + HttpOnly binding cookie) is tied
		// to a tenant here. The adminconsent redirect itself is not signed by Microsoft: its
		// "tenant" and "admin_consent" parameters are never trusted on their own.
		$identity = self::identity_from( $stored );
		self::clear_flow_cookie();

		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		if ( '' !== $error ) {
			// The query string can be crafted: only validated codes/IDs are logged or shown, never free text.
			Audit::log( 'consent_error', $identity ? $identity['tid'] : '', $identity ? $identity['upn'] : '', [ 'error' => preg_match( '/^[a-z_]{3,40}$/', $error ) ? $error : 'invalid', 'flow' => (string) ( $stored['kind'] ?? '' ) ] );
			// The signed-in user could not (or chose not to) approve: show how to get an administrator to do it.
			$desc = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? '' ) );
			Router::render_problem(
				'consent_declined',
				[
					'tenant_id' => $identity ? $identity['tid'] : '',
					'upn'       => $identity ? $identity['upn'] : '',
				] + Access_Problem::from_query( $error, $desc ),
				200
			);
		}

		$tenant_id     = strtolower( sanitize_text_field( wp_unslash( $_GET['tenant'] ?? '' ) ) );
		$admin_consent = strtolower( sanitize_text_field( wp_unslash( $_GET['admin_consent'] ?? '' ) ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $tenant_id ) || 'true' !== $admin_consent ) {
			Router::render_message( 'Aprovação não confirmada', 'A resposta da Microsoft não confirmou a aprovação para a organização.', 'error' );
		}

		// Shared-link flow (no signed-in identity) or a tenant other than the signed-in one:
		// nothing is written, checked or revealed. The first sign-in confirms the consent with the
		// app-only token and connects the tenant (Oidc::callback).
		if ( ! $identity || $identity['tid'] !== $tenant_id ) {
			Router::render_consent_link_done();
		}

		$allowed_tenants = Settings::allowed_tenants();
		if ( $allowed_tenants && ! in_array( $tenant_id, $allowed_tenants, true ) ) {
			Audit::log( 'consent_denied_tenant', $tenant_id, $identity['upn'] );
			Router::render_message( 'Tenant não autorizado', 'Este portal está restrito a tenants específicos. A aprovação foi registrada no seu tenant, mas o portal não a aceitará; você pode remover o aplicativo Tenant Jornada365 em Entra ID → Aplicativos empresariais.', 'error', 403 );
		}

		$existing = Tenant_Repository::get( $tenant_id );
		if ( $existing && 'revoked' === $existing['status'] ) {
			Audit::log( 'consent_tenant_revoked', $tenant_id, $identity['upn'] );
			Router::render_message( 'Acesso suspenso', 'O acesso deste tenant ao portal foi suspenso pelo operador. Entre em contato com o suporte.', 'error', 403 );
		}

		// The consent is real only if the app can now get an app-only token for that tenant.
		// Propagation may take a few seconds: short server-side check, then the page retries.
		$ok = false;
		for ( $i = 0; $i < 3 && ! $ok; $i++ ) {
			$ok = Token_Service::tenant_has_consent( $tenant_id );
			if ( ! $ok && $i < 2 ) {
				sleep( 2 );
			}
		}
		if ( ! $ok ) {
			// Approved, but the roles are not in tokens yet: the next sign-in shows a waiting page
			// instead of sending the administrator back to the approval screen.
			set_transient( self::wait_key( $tenant_id ), time(), 900 );
			Router::render_consent_done( $tenant_id, false, '' );
		}

		self::register_tenant( $tenant_id, $identity['upn'], 'adminconsent' );
		Audit::log( 'consent_granted', $tenant_id, $identity['upn'], [ 'flow' => 'identity' ] );

		// One-click path: the verified admin goes straight to the dashboard.
		$tenant = Tenant_Repository::get( $tenant_id );
		$auth   = Authorization::evaluate( $tenant_id, $identity['oid'], $identity['upn'], $tenant ?? [] );
		if ( $auth['allowed'] ) {
			Session::destroy();
			Session::create( $tenant_id, $identity['oid'], $identity['upn'], $identity['name'], $auth['roles'], Org::lookup( $tenant_id ) );
			Tenant_Repository::touch_login( $tenant_id );
			Audit::log( 'login_success', $tenant_id, $identity['upn'], [ 'via' => 'consent', 'roles' => array_column( $auth['roles'], 'name' ) ] );
			wp_safe_redirect( add_query_arg( 'welcome', '1', Request::portal_url() ) );
			exit;
		}

		Router::render_consent_done( $tenant_id, true, (string) ( Org::lookup( $tenant_id )['name'] ?? '' ) );
	}

	/**
	 * Returns the verified identity carried in the consent state, only if this browser holds
	 * the matching binding cookie (prevents replaying a leaked state into another browser).
	 */
	private static function identity_from( array $stored ): ?array {
		if ( 'identity' !== ( $stored['kind'] ?? '' ) ) {
			return null;
		}
		$cookie = isset( $_COOKIE[ self::FLOW_COOKIE ] ) ? (string) $_COOKIE[ self::FLOW_COOKIE ] : '';
		if ( '' === $cookie || ! hash_equals( (string) ( $stored['binding'] ?? '' ), hash( 'sha256', $cookie ) ) ) {
			return null;
		}
		$plain = Crypto::open( (string) ( $stored['identity'] ?? '' ), self::flow_key( $cookie ) );
		$who   = null === $plain ? null : json_decode( $plain, true );
		if ( ! is_array( $who ) || empty( $who['oid'] ) ) {
			return null;
		}
		return [
			'tid'  => (string) $stored['tid'],
			'oid'  => (string) $who['oid'],
			'upn'  => (string) ( $who['upn'] ?? '' ),
			'name' => (string) ( $who['name'] ?? '' ),
		];
	}

	/** 32-byte key derived from the browser-held binding secret (never stored server-side). */
	private static function flow_key( string $binding ): string {
		return hash_hmac( 'sha256', $binding, Crypto::subkey( 'consent-flow' ), true );
	}

	private static function set_flow_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::FLOW_COOKIE, $value, [ 'expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ] );
	}

	private static function clear_flow_cookie(): void {
		self::set_flow_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Persists the tenant and enriches with organization data (best effort).
	 */
	public static function register_tenant( string $tenant_id, string $consented_by, string $source ): void {
		$fields = [
			'status'       => 'active',
			'consented_at' => gmdate( 'Y-m-d H:i:s' ),
		];
		if ( '' !== $consented_by ) {
			$fields['consented_by'] = Privacy::uid( $consented_by ); // pseudonym only
		}
		$existing = Tenant_Repository::get( $tenant_id );
		if ( $existing && 'revoked' === $existing['status'] ) {
			unset( $fields['status'] ); // operator suspension wins over a new consent
		}
		if ( $existing && empty( $fields['consented_by'] ) && ! empty( $existing['consented_by'] ) ) {
			unset( $fields['consented_by'] );
		}
		Tenant_Repository::upsert( $tenant_id, $fields );
		Token_Service::forget( $tenant_id );
	}
}
