<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Friendly names for Microsoft licenses (SKUs) and service plans.
 *
 * Source: Microsoft's official "Product names and service plan identifiers for licensing" table.
 * A built-in copy ships in data/sku-catalog.json; WP-Cron refreshes it weekly from Microsoft's
 * public CSV. This is public catalog data — no tenant data is stored.
 */
final class Sku_Catalog {

	public const OPTION     = 'j365tp_sku_catalog';
	public const SOURCE_CSV = 'https://download.microsoft.com/download/e/3/e/e3e9faf2-f28b-490a-9ada-c6089a1fc5b0/Product%20names%20and%20service%20plan%20identifiers%20for%20licensing.csv';
	private const REFRESH_DAYS = 7;

	/** Not sold per user (credits / resource accounts / free room licenses, incl. EDU/GOV variants) — kept out of seat totals. */
	private const NON_SEAT_RX = '/^(MCOPSTNC|PHONESYSTEM_VIRTUALUSER|MCOEV_VIRTUALUSER|MICROSOFT_TEAMS_ROOMS_BASIC)($|_)/';
	/**
	 * Free SKUs whose names don't say so. EDU: Office 365 A1 (no cost), A1 Plus (no-cost benefit) and the
	 * student use benefits granted with faculty licenses.
	 */
	private const FREE = [
		'WINDOWS_STORE', 'WSFB_EDU_FACULTY', 'MICROSOFT_BUSINESS_CENTER', 'RIGHTSMANAGEMENT_ADHOC', 'PROJECT_MADEIRA_PREVIEW_IW_SKU',
		'POWERAPPS_DEV', 'FLOW_FREE', 'POWER_BI_STANDARD', 'POWERAPPS_PER_APP_IW', 'SPZA_IW',
		'STANDARDWOFFPACK_STUDENT', 'STANDARDWOFFPACK_FACULTY', 'STANDARDWOFFPACK_IW_STUDENT', 'STANDARDWOFFPACK_IW_FACULTY',
	];

	private const EXO     = [ 'EXCHANGESTANDARD', 'EXCHANGEENTERPRISE', 'EXCHANGEDESKLESS' ];
	private const SPO     = [ 'SHAREPOINTSTANDARD', 'SHAREPOINTENTERPRISE' ];
	private const EMS_E3  = [ 'EMS', 'AAD_PREMIUM', 'INTUNE_A', 'RIGHTSMANAGEMENT' ];
	private const SEC_E5  = [ 'WIN_DEF_ATP', 'DEFENDER_ENDPOINT_P1', 'ATP_ENTERPRISE', 'THREAT_INTELLIGENCE', 'ADALLOM_STANDALONE', 'ATA', 'AAD_PREMIUM_P2', 'AAD_PREMIUM' ];
	private const O365_NT = [ 'Office_365_E3_(no_Teams)', 'Office_365_E1_(no_Teams)', 'OFFICESUBSCRIPTION', 'O365_BUSINESS' ];
	private const O365_T  = [ 'ENTERPRISEPACK', 'STANDARDPACK', 'Microsoft_Teams_Enterprise_New', 'O365_BUSINESS_ESSENTIALS' ];

	/**
	 * Products that already contain other products (per Microsoft's licensing composition). Used only to
	 * flag users holding both — a strict service-plan subset test is unreliable because E3/E5 variants of
	 * the same service carry different plan IDs. Conservative: "no Teams" suites never cover suites with Teams.
	 */
	private const INCLUDES = [
		'SPE_E5'                                  => [ 'SPE_E3', 'Microsoft_365_E3_(no_Teams)', 'ENTERPRISEPREMIUM', 'EMSPREMIUM', 'POWER_BI_PRO', 'WIN10_VDA_E3', 'WIN10_VDA_E5', 'IDENTITY_THREAT_PROTECTION', 'INFORMATION_PROTECTION_COMPLIANCE', 'MCOEV', 'MCOMEETADV', '@EXO', '@SPO', '@EMS_E3', '@SEC_E5', '@O365_NT', '@O365_T' ],
		'SPE_E5_CALLINGMINUTES'                   => [ '=SPE_E5' ],
		'SPE_E5_NOPSTNCONF'                       => [ 'SPE_E3', 'Microsoft_365_E3_(no_Teams)', 'EMSPREMIUM', 'POWER_BI_PRO', 'WIN10_VDA_E3', 'WIN10_VDA_E5', 'IDENTITY_THREAT_PROTECTION', 'INFORMATION_PROTECTION_COMPLIANCE', 'MCOEV', '@EXO', '@SPO', '@EMS_E3', '@SEC_E5', '@O365_NT', '@O365_T' ],
		'Microsoft_365_E5_(no_Teams)'             => [ 'Microsoft_365_E3_(no_Teams)', 'EMSPREMIUM', 'POWER_BI_PRO', 'WIN10_VDA_E3', 'WIN10_VDA_E5', 'IDENTITY_THREAT_PROTECTION', 'INFORMATION_PROTECTION_COMPLIANCE', '@EXO', '@SPO', '@EMS_E3', '@SEC_E5', '@O365_NT' ],
		'SPE_E3'                                  => [ 'Microsoft_365_E3_(no_Teams)', 'WIN10_VDA_E3', 'DEFENDER_ENDPOINT_P1', '@EXO', '@SPO', '@EMS_E3', '@O365_NT', '@O365_T' ],
		'Microsoft_365_E3_(no_Teams)'             => [ 'WIN10_VDA_E3', 'DEFENDER_ENDPOINT_P1', '@EXO', '@SPO', '@EMS_E3', '@O365_NT' ],
		'ENTERPRISEPREMIUM'                       => [ 'POWER_BI_PRO', 'ATP_ENTERPRISE', 'THREAT_INTELLIGENCE', 'MCOEV', 'MCOMEETADV', '@EXO', '@SPO', '@O365_NT', '@O365_T' ],
		'ENTERPRISEPREMIUM_NOPSTNCONF'            => [ 'POWER_BI_PRO', 'ATP_ENTERPRISE', 'THREAT_INTELLIGENCE', 'MCOEV', '@EXO', '@SPO', '@O365_NT', '@O365_T' ],
		'ENTERPRISEPACK'                          => [ 'STANDARDPACK', 'Microsoft_Teams_Enterprise_New', 'O365_BUSINESS_ESSENTIALS', '@EXO', '@SPO', '@O365_NT' ],
		'Office_365_E3_(no_Teams)'                => [ 'Office_365_E1_(no_Teams)', 'OFFICESUBSCRIPTION', 'O365_BUSINESS', '@EXO', '@SPO' ],
		'STANDARDPACK'                            => [ 'Office_365_E1_(no_Teams)', 'O365_BUSINESS_ESSENTIALS', 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS', 'SHAREPOINTSTANDARD' ],
		'OFFICESUBSCRIPTION'                      => [ 'O365_BUSINESS' ],
		'EMSPREMIUM'                              => [ 'AAD_PREMIUM_P2', 'ADALLOM_STANDALONE', 'ATA', '@EMS_E3' ],
		'EMS'                                     => [ 'AAD_PREMIUM', 'INTUNE_A', 'RIGHTSMANAGEMENT' ],
		'AAD_PREMIUM_P2'                          => [ 'AAD_PREMIUM' ],
		'IDENTITY_THREAT_PROTECTION'              => [ '@SEC_E5' ],
		'WIN10_VDA_E5'                            => [ 'WIN10_VDA_E3', 'WIN_DEF_ATP', 'DEFENDER_ENDPOINT_P1' ],
		'WIN_DEF_ATP'                             => [ 'DEFENDER_ENDPOINT_P1' ],
		'THREAT_INTELLIGENCE'                     => [ 'ATP_ENTERPRISE' ],
		'PBI_PREMIUM_PER_USER'                    => [ 'POWER_BI_PRO' ],
		'SPB'                                     => [ 'O365_BUSINESS_PREMIUM', 'O365_BUSINESS_ESSENTIALS', 'O365_BUSINESS', 'MICROSOFT_365_BUSINESS_STANDARD_NO_TEAMS', 'Microsoft_365_Business_Basic_(noTeams)', 'Microsoft_Teams_Enterprise_New', 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS', 'SHAREPOINTSTANDARD', 'AAD_PREMIUM', 'INTUNE_A', 'ATP_ENTERPRISE', 'RIGHTSMANAGEMENT' ],
		'Microsoft_365_Business_Premium_(noTeams)' => [ 'MICROSOFT_365_BUSINESS_STANDARD_NO_TEAMS', 'Microsoft_365_Business_Basic_(noTeams)', 'O365_BUSINESS', 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS', 'SHAREPOINTSTANDARD', 'AAD_PREMIUM', 'INTUNE_A', 'ATP_ENTERPRISE', 'RIGHTSMANAGEMENT' ],
		'O365_BUSINESS_PREMIUM'                   => [ 'O365_BUSINESS_ESSENTIALS', 'O365_BUSINESS', 'Microsoft_365_Business_Basic_(noTeams)', 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS', 'SHAREPOINTSTANDARD' ],
		'MICROSOFT_365_BUSINESS_STANDARD_NO_TEAMS' => [ 'Microsoft_365_Business_Basic_(noTeams)', 'O365_BUSINESS', 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS', 'SHAREPOINTSTANDARD' ],
		'O365_BUSINESS_ESSENTIALS'                => [ 'Microsoft_365_Business_Basic_(noTeams)', 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS', 'SHAREPOINTSTANDARD' ],
		'SPE_F1'                                  => [ 'DESKLESSPACK', 'EXCHANGEDESKLESS' ],
		'DESKLESSPACK'                            => [ 'EXCHANGEDESKLESS' ],
		'EXCHANGEENTERPRISE'                      => [ 'EXCHANGESTANDARD', 'EXCHANGEDESKLESS' ],
		'EXCHANGESTANDARD'                        => [ 'EXCHANGEDESKLESS' ],
		'SHAREPOINTENTERPRISE'                    => [ 'SHAREPOINTSTANDARD' ],
	];
	private static ?array $includes = null;

	/** Does product $outer already include product $inner? (skuPartNumbers, case-insensitive) */
	public static function includes( string $outer, string $inner ): bool {
		if ( null === self::$includes ) {
			$groups = [ 'EXO' => self::EXO, 'SPO' => self::SPO, 'EMS_E3' => self::EMS_E3, 'SEC_E5' => self::SEC_E5, 'O365_NT' => self::O365_NT, 'O365_T' => self::O365_T ];
			$expand = static function ( array $list ) use ( $groups ): array {
				$out = [];
				foreach ( $list as $x ) {
					if ( str_starts_with( $x, '@' ) ) {
						$out = array_merge( $out, $groups[ substr( $x, 1 ) ] ?? [] );
					} elseif ( str_starts_with( $x, '=' ) ) {
						$out = array_merge( $out, self::INCLUDES[ substr( $x, 1 ) ] ?? [] );
					} else {
						$out[] = $x;
					}
				}
				return $out;
			};
			self::$includes = [];
			foreach ( self::INCLUDES as $k => $list ) {
				$flat = $expand( $list );
				$flat = $expand( $flat ); // "=SPE_E5" may itself contain groups
				self::$includes[ strtoupper( $k ) ] = array_fill_keys( array_map( 'strtoupper', $flat ), true );
				unset( self::$includes[ strtoupper( $k ) ][ strtoupper( $k ) ] );
			}
		}
		return isset( self::$includes[ strtoupper( $outer ) ][ strtoupper( $inner ) ] );
	}

	private static ?array $data   = null;
	private static ?array $by_part = null;
	private static ?array $upper  = null;

	/** @return array{skus: array<string, array{0:string,1:string}>, plans: array<string, array{0:string,1:string}>, v: string} */
	public static function data(): array {
		if ( null !== self::$data ) {
			return self::$data;
		}
		$file = ( defined( 'J365TP_DIR' ) ? J365TP_DIR : dirname( __DIR__ ) . '/' ) . 'data/sku-catalog.json';
		$base = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$base = is_array( $base ) ? $base : [];
		$skus  = is_array( $base['skus'] ?? null ) ? $base['skus'] : [];
		$plans = is_array( $base['plans'] ?? null ) ? $base['plans'] : [];
		$v     = (string) ( $base['v'] ?? '' );

		$fresh = function_exists( 'get_option' ) ? get_option( self::OPTION ) : false;
		if ( is_array( $fresh ) && ! empty( $fresh['skus'] ) && is_array( $fresh['skus'] ) ) {
			$skus  = array_merge( $skus, $fresh['skus'] ); // refreshed entries win; built-in ones stay as fallback
			$plans = array_merge( $plans, is_array( $fresh['plans'] ?? null ) ? $fresh['plans'] : [] );
			$v     = (string) ( $fresh['v'] ?? $v );
		}
		return self::$data = [ 'skus' => $skus, 'plans' => $plans, 'v' => $v ];
	}

	/** Friendly product name for a skuId (GUID) or skuPartNumber; falls back to a readable part number. */
	public static function name( string $sku_id, string $part = '' ): string {
		$d   = self::data();
		$hit = $d['skus'][ strtolower( trim( $sku_id ) ) ] ?? null;
		if ( is_array( $hit ) && '' !== (string) ( $hit[1] ?? '' ) ) {
			return (string) $hit[1];
		}
		if ( '' !== $part ) {
			$idx = self::by_part();
			$key = strtoupper( $part );
			if ( isset( $idx[ $key ] ) ) {
				return $idx[ $key ];
			}
		}
		return self::readable( '' !== $part ? $part : $sku_id );
	}

	/** Friendly service plan name (by servicePlanId), or the technical name. */
	/** Service plan string id ("EXCHANGE_S_ENTERPRISE") from its GUID ('' when unknown). */
	public static function plan_code( string $plan_id ): string {
		$hit = self::data()['plans'][ strtolower( trim( $plan_id ) ) ] ?? null;
		return is_array( $hit ) ? (string) ( $hit[0] ?? '' ) : '';
	}

	public static function plan( string $plan_id, string $plan_name = '' ): string {
		$hit = self::data()['plans'][ strtolower( trim( $plan_id ) ) ] ?? null;
		if ( is_array( $hit ) && '' !== (string) ( $hit[1] ?? '' ) ) {
			return (string) $hit[1];
		}
		return '' !== $plan_name ? $plan_name : $plan_id;
	}

	/**
	 * Usage reports list assigned products in upper case ("MICROSOFT 365 E5 (NO TEAMS)").
	 * Returns the official casing when the product is in the catalog.
	 */
	public static function product( string $upper ): string {
		$upper = trim( $upper );
		if ( '' === $upper ) {
			return '';
		}
		if ( null === self::$upper ) {
			self::$upper = [];
			foreach ( self::data()['skus'] as $sku ) {
				$n = (string) ( $sku[1] ?? '' );
				if ( '' !== $n ) {
					self::$upper[ self::fold( $n ) ] = $n;
				}
			}
		}
		$hit = self::$upper[ self::fold( $upper ) ] ?? null;
		if ( null !== $hit ) {
			return $hit;
		}
		// Unknown product: title case, keeping short codes (E3, P1, A5…) in capitals.
		return (string) preg_replace_callback(
			'/\b([A-Z][A-Z0-9]*)\b/u',
			static fn( $m ) => preg_match( '/^([A-Z]\d+|[A-Z]{1,2}|OATH|SKU|AI)$/', $m[1] ) ? $m[1] : ucfirst( strtolower( $m[1] ) ),
			$upper
		);
	}

	/**
	 * License kind: paid | free | trial | nonseat. Only names and a short curated list decide —
	 * never the seat count (large tenants legitimately have 10 000+ seats of a paid SKU).
	 */
	public static function kind( string $part, string $name = '' ): string {
		$p = strtoupper( $part );
		if ( preg_match( self::NON_SEAT_RX, $p ) ) {
			return 'nonseat';
		}
		if ( in_array( $p, self::FREE, true ) || preg_match( '/(^|_)FREE($|_)|DEVELOPERPACK|_DEV$|STUUSEBNFT|STUDENTS?_USE_BENEFIT/', $p )
			|| preg_match( '/\b(free|developer|use benefits?)\b/i', $name ) ) {
			return 'free';
		}
		// "(Preview)" is kept out on purpose: some paid SKUs (Windows 365 Enterprise) still carry it.
		if ( preg_match( '/(TRIAL|VIRAL|EXPLORATORY)/', $p ) || preg_match( '/\b(trial|vtrial|viral|exploratory)\b/i', $name ) ) {
			return 'trial';
		}
		return 'paid';
	}

	/** Built-in catalog date (for the UI footnote). */
	public static function version(): string {
		return self::data()['v'];
	}

	/* ------------------------------------------------------------ weekly refresh (WP-Cron) */

	public static function maybe_refresh(): void {
		$fresh = get_option( self::OPTION );
		$age   = is_array( $fresh ) ? time() - (int) ( $fresh['fetched'] ?? 0 ) : PHP_INT_MAX;
		if ( $age < self::REFRESH_DAYS * DAY_IN_SECONDS ) {
			return;
		}
		$r = self::refresh();
		if ( ! $r['ok'] ) {
			// Keep the previous copy (or the built-in one) and try again in a day, not every hour.
			$keep            = is_array( $fresh ) ? $fresh : [ 'skus' => [], 'plans' => [], 'v' => '' ];
			$keep['fetched'] = time() - ( self::REFRESH_DAYS - 1 ) * DAY_IN_SECONDS;
			$keep['error']   = mb_substr( (string) ( $r['message'] ?? '' ), 0, 200 );
			update_option( self::OPTION, $keep, false );
		}
	}

	/** Downloads Microsoft's CSV and stores the compact map. @return array{ok: bool, skus?: int, message?: string} */
	public static function refresh(): array {
		$res = wp_remote_get( self::SOURCE_CSV, [ 'timeout' => 30, 'limit_response_size' => 12 * MB_IN_BYTES, 'redirection' => 3 ] );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return [ 'ok' => false, 'message' => is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ];
		}
		$parsed = self::parse_csv( (string) wp_remote_retrieve_body( $res ) );
		if ( count( $parsed['skus'] ) < 100 ) {
			return [ 'ok' => false, 'message' => 'Catálogo incompleto (' . count( $parsed['skus'] ) . ' SKUs).' ];
		}
		$parsed['v']       = gmdate( 'Y-m-d' );
		$parsed['fetched'] = time();
		update_option( self::OPTION, $parsed, false );
		self::$data = self::$by_part = self::$upper = null;
		return [ 'ok' => true, 'skus' => count( $parsed['skus'] ) ];
	}

	/**
	 * Microsoft's CSV: Product_Display_Name, String_Id, GUID, Service_Plan_Name, Service_Plan_Id,
	 * Service_Plans_Included_Friendly_Names — one row per (product, service plan).
	 */
	public static function parse_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$lines = preg_split( '/\r\n|\n|\r/', (string) $csv );
		$head  = str_getcsv( (string) array_shift( $lines ), ',', '"', '' );
		$col   = array_flip( array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $head ) );
		$need  = [ 'product_display_name', 'string_id', 'guid', 'service_plan_name', 'service_plan_id', 'service_plans_included_friendly_names' ];
		foreach ( $need as $n ) {
			if ( ! isset( $col[ $n ] ) ) {
				return [ 'skus' => [], 'plans' => [] ];
			}
		}
		$guid  = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
		$skus  = [];
		$plans = [];
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$c   = str_getcsv( $line, ',', '"', '' );
			$id  = strtolower( trim( (string) ( $c[ $col['guid'] ] ?? '' ) ) );
			$sid = trim( (string) ( $c[ $col['string_id'] ] ?? '' ) );
			$nm  = trim( (string) ( $c[ $col['product_display_name'] ] ?? '' ) );
			if ( preg_match( $guid, $id ) && '' !== $sid && '' !== $nm && mb_strlen( $nm ) <= 200 ) {
				$skus[ $id ] = [ mb_substr( $sid, 0, 120 ), $nm ];
			}
			$pid = strtolower( trim( (string) ( $c[ $col['service_plan_id'] ] ?? '' ) ) );
			$pn  = trim( (string) ( $c[ $col['service_plan_name'] ] ?? '' ) );
			$pf  = trim( (string) ( $c[ $col['service_plans_included_friendly_names'] ] ?? '' ) );
			if ( preg_match( $guid, $pid ) && '' !== $pn ) {
				$plans[ $pid ] = [ mb_substr( $pn, 0, 120 ), mb_substr( $pf, 0, 200 ) ];
			}
		}
		return [ 'skus' => $skus, 'plans' => $plans ];
	}

	/* ------------------------------------------------------------ helpers */

	private static function by_part(): array {
		if ( null === self::$by_part ) {
			self::$by_part = [];
			foreach ( self::data()['skus'] as $sku ) {
				if ( isset( $sku[0], $sku[1] ) ) {
					self::$by_part[ strtoupper( (string) $sku[0] ) ] = (string) $sku[1];
				}
			}
		}
		return self::$by_part;
	}

	private static function fold( string $s ): string {
		return strtoupper( (string) preg_replace( '/\s+/', ' ', trim( $s ) ) );
	}

	/** "Microsoft_365_E5_(no_Teams)" → "Microsoft 365 E5 (no Teams)" when the SKU is unknown. */
	private static function readable( string $part ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( '_', ' ', $part ) ) );
	}

	/** For tests. */
	public static function reset(): void {
		self::$data = self::$by_part = self::$upper = null;
	}
}
