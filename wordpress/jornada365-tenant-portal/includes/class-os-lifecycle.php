<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operating-system platform and support status for the device inventory.
 *
 * Sources (checked 2026-11-01): Windows release health (Windows 11/10 release information),
 * Microsoft Lifecycle (Windows Server, LTSC/LTSB), Extended Security Updates FAQ and
 * "Supported operating systems and browsers in Intune" (iOS/iPadOS 18+, macOS 15+, Android 10+,
 * Ubuntu 24.04/26.04, RHEL 9/10). Dates are the last supported day.
 */
final class Os_Lifecycle {

	public const AS_OF   = '2026-11-01';
	public const SOURCES = [
		'https://learn.microsoft.com/windows/release-health/windows11-release-information',
		'https://learn.microsoft.com/windows/release-health/release-information',
		'https://learn.microsoft.com/lifecycle/faq/extended-security-updates',
		'https://learn.microsoft.com/windows/release-health/windows-server-release-info',
		'https://learn.microsoft.com/intune/fundamentals/ref-supported-platforms',
	];
	private const ENDING_DAYS = 90;

	/** Windows 11 by build: [version, Home/Pro end, Enterprise/Education end]. */
	private const WIN11 = [
		28000 => [ '26H1', '2028-03-14', '2029-03-13' ],
		26300 => [ '26H2', '2028-10-10', '2029-10-09' ],
		26200 => [ '25H2', '2027-10-12', '2028-10-10' ],
		26100 => [ '24H2', '2026-10-13', '2027-10-12' ],
		22631 => [ '23H2', '2025-11-11', '2026-11-10' ],
		22621 => [ '22H2', '2024-10-08', '2025-10-14' ],
		22000 => [ '21H2', '2023-10-10', '2024-10-08' ],
	];
	/** Windows 10 builds → version (all non-LTSC releases are out of support). */
	private const WIN10 = [ 19045 => '22H2', 19044 => '21H2', 19043 => '21H1', 19042 => '20H2', 19041 => '2004', 18363 => '1909', 18362 => '1903', 17763 => '1809', 17134 => '1803', 16299 => '1709', 15063 => '1703', 14393 => '1607', 10586 => '1511', 10240 => '1507' ];
	/** Windows 10/11 LTSC/LTSB by build: [name, end of support]. */
	private const LTSC = [
		26100 => [ 'Windows 11 Enterprise LTSC 2024', '2029-10-09' ],
		19044 => [ 'Windows 10 Enterprise LTSC 2021', '2027-01-12' ],
		17763 => [ 'Windows 10 Enterprise LTSC 2019', '2029-01-09' ],
		14393 => [ 'Windows 10 Enterprise LTSB 2016', '2026-10-13' ],
	];
	/** IoT Enterprise LTSC gets 10 years where the Enterprise LTSC of the same build gets 5. */
	private const LTSC_IOT = [
		26100 => [ 'Windows 11 IoT Enterprise LTSC 2024', '2034-10-10' ],
		19044 => [ 'Windows 10 IoT Enterprise LTSC 2021', '2032-01-13' ],
	];
	/** Windows Server by build: [name, mainstream end, extended end, last ESU year end ('' = none)]. */
	private const SERVER = [
		26100 => [ 'Windows Server 2025', '2029-11-13', '2034-11-14', '' ],
		20348 => [ 'Windows Server 2022', '2026-10-13', '2031-10-14', '' ],
		17763 => [ 'Windows Server 2019', '2024-01-09', '2029-01-09', '' ],
		14393 => [ 'Windows Server 2016', '2022-01-11', '2027-01-12', '' ],
		9600  => [ 'Windows Server 2012 R2', '2018-10-09', '2023-10-10', '2026-10-13' ],
		9200  => [ 'Windows Server 2012', '2018-10-09', '2023-10-10', '2026-10-13' ],
		7601  => [ 'Windows Server 2008 R2', '2015-01-13', '2020-01-14', '2024-01-09' ],
		7600  => [ 'Windows Server 2008 R2', '2015-01-13', '2020-01-14', '2024-01-09' ],
		6003  => [ 'Windows Server 2008', '2015-01-13', '2020-01-14', '2024-01-09' ],
		6002  => [ 'Windows Server 2008', '2015-01-13', '2020-01-14', '2024-01-09' ],
		6001  => [ 'Windows Server 2008', '2015-01-13', '2020-01-14', '2024-01-09' ],
	];
	private const WIN10_END = '2025-10-14';
	private const ESU_END   = '2028-10-10'; // commercial ESU, year 3
	private const MIN       = [ 'macos' => 15, 'ios' => 18, 'ipados' => 18, 'android' => 10 ];

	/** Platform key from the OS name: windows, windowsServer, macos, ios, ipados, android, linux, chromeos, other. */
	public static function platform( string $os ): string {
		$o = strtolower( trim( $os ) );
		return match ( true ) {
			'' === $o                                                    => 'other',
			str_contains( $o, 'server' ) && str_contains( $o, 'windows' ) => 'windowsServer',
			str_starts_with( $o, 'win' )                                  => 'windows',
			(bool) preg_match( '/mac|os ?x/', $o )                        => 'macos',
			str_contains( $o, 'ipad' )                                   => 'ipados',
			(bool) preg_match( '/^ios|iphone/', $o )                      => 'ios',
			str_contains( $o, 'android' )                                => 'android',
			(bool) preg_match( '/linux|ubuntu|red ?hat|rhel|debian|fedora|suse/', $o ) => 'linux',
			(bool) preg_match( '/chrome/', $o )                           => 'chromeos',
			default                                                      => 'other',
		};
	}

	/**
	 * @param string $os      operatingSystem (Entra or Intune)
	 * @param string $version operatingSystemVersion / osVersion ("10.0.22631.4317", "10.0 (19045)", "18.6.2", "24.04")
	 * @param string $now     today (Y-m-d), for tests
	 * @return array{platform: string, release: string, support: string, supportEnd: string, note: array}
	 *         note = ['tpl' => Portuguese template with {a} {b} {os} {min}, …] — dates are formatted by the browser.
	 */
	public static function evaluate( string $os, string $version, string $now = '' ): array {
		$now      = '' !== $now ? $now : gmdate( 'Y-m-d' );
		$platform = self::platform( $os );
		$out      = [ 'platform' => $platform, 'release' => '', 'support' => 'unknown', 'supportEnd' => '', 'note' => [] ];

		if ( 'windows' === $platform || 'windowsServer' === $platform ) {
			return self::windows( $os, $version, $platform, $now, $out );
		}
		if ( isset( self::MIN[ $platform ] ) ) {
			$major = (int) ( preg_match( '/(\d+)(?:\.\d+)*/', $version, $m ) ? $m[1] : 0 );
			if ( $major <= 0 ) {
				return $out;
			}
			$label          = [ 'macos' => 'macOS', 'ios' => 'iOS', 'ipados' => 'iPadOS', 'android' => 'Android' ][ $platform ];
			$out['release'] = $label . ' ' . $major;
			$min            = self::MIN[ $platform ];
			$out['support'] = $major >= $min ? 'supported' : 'unsupported';
			$out['note']    = [ 'tpl' => $major >= $min ? 'Suportado pelo Intune ({os} {min} ou posterior).' : 'Abaixo do mínimo suportado pelo Intune ({os} {min}).', 'os' => $label, 'min' => $min ];
			return $out;
		}
		if ( 'linux' === $platform ) {
			$s = strtolower( $os . ' ' . $version );
			if ( preg_match( '/(\d{2})\.(04|10)/', $s, $m ) && ! preg_match( '/red ?hat|rhel/', $s ) ) {
				$out['release'] = 'Ubuntu ' . $m[1] . '.' . $m[2];
				$out['support'] = in_array( $m[1] . '.' . $m[2], [ '24.04', '26.04' ], true ) ? 'supported' : 'unsupported';
			} elseif ( preg_match( '/(?:red ?hat|rhel)[^0-9]*(\d+)/', $s, $m ) || preg_match( '/^\s*(9|10)(?:\.|$)/', $version, $m ) ) {
				$major          = (int) $m[1];
				$out['release'] = 'RHEL ' . $major;
				$out['support'] = in_array( $major, [ 9, 10 ], true ) ? 'supported' : 'unsupported';
			}
			if ( 'unknown' !== $out['support'] ) {
				$out['note'] = [ 'tpl' => 'supported' === $out['support'] ? 'Suportado pelo Intune.' : 'Distribuição/versão fora da lista suportada pelo Intune (Ubuntu 24.04/26.04 LTS, RHEL 9/10).' ];
			}
			return $out;
		}
		return $out;
	}

	private static function windows( string $os, string $version, string $platform, string $now, array $out ): array {
		// "10.0.22631.4317" (Entra/Intune) or "10.0 (19045)" (hybrid objects synced from AD).
		$build = 0;
		if ( preg_match( '/^\s*\d+\.\d+\.(\d{4,5})/', $version, $m ) || preg_match( '/\((\d{4,5})\)/', $version, $m ) ) {
			$build = (int) $m[1];
		} elseif ( preg_match( '/^\s*6\.3/', $version ) ) {
			$build = 9600;
		} elseif ( preg_match( '/^\s*6\.2/', $version ) ) {
			$build = 9200;
		} elseif ( preg_match( '/^\s*6\.1/', $version ) ) {
			$build = 7601;
		} elseif ( preg_match( '/^\s*6\.0/', $version ) ) {
			$build = 6002;
		}
		$o = strtolower( $os );

		if ( 'windowsServer' === $platform ) {
			$srv = self::SERVER[ $build ] ?? null;
			if ( ! $srv && preg_match( '/server\s*(20\d\d(\s*r2)?)/', $o, $mm ) ) {
				foreach ( self::SERVER as $row ) {
					if ( str_ends_with( strtolower( $row[0] ), strtolower( trim( $mm[1] ) ) ) ) {
						$srv = $row;
						break;
					}
				}
			}
			if ( ! $srv ) {
				return $out;
			}
			$out['release']    = $srv[0];
			$out['supportEnd'] = $srv[2];
			$out['support']    = self::status( $srv[2], $now );
			$esu_over          = '' !== $srv[3] && $now > $srv[3];
			$out['note']       = [
				'tpl' => $now > $srv[2]
					? ( $esu_over ? 'Fim do suporte estendido em {a}; o ESU também já terminou ({c}). Migre para uma versão suportada.' : 'Fim do suporte estendido em {a} (ESU pago, se contratado).' )
					: ( $now > $srv[1] ? 'Suporte estendido (somente segurança) até {a}; suporte principal terminou em {b}.' : 'Suporte principal até {b}; estendido até {a}.' ),
				'a'   => $srv[2],
				'b'   => $srv[1],
				'c'   => $srv[3],
			];
			return $out;
		}

		if ( $build <= 0 ) {
			return $out;
		}
		$ltsc = str_contains( $o, 'ltsc' ) || str_contains( $o, 'ltsb' );
		if ( $ltsc && isset( self::LTSC[ $build ] ) ) {
			$iot               = str_contains( $o, 'iot' );
			[ $name, $end ]    = $iot && isset( self::LTSC_IOT[ $build ] ) ? self::LTSC_IOT[ $build ] : self::LTSC[ $build ];
			$name              = $iot ? str_replace( ' Enterprise LTS', ' IoT Enterprise LTS', str_replace( ' IoT Enterprise', ' Enterprise', $name ) ) : $name;
			$out['release']    = $name;
			$out['supportEnd'] = $end;
			$out['support']    = self::status( $end, $now );
			$out['note']       = [ 'tpl' => 'Canal LTSC: suporte até {a}.', 'a' => $end ];
			return $out;
		}

		if ( $build >= 22000 ) {
			$row = self::WIN11[ $build ] ?? null;
			if ( ! $row ) {
				// A build newer than the table (future release) is current; an unknown older one isn't classified.
				if ( $build > max( array_keys( self::WIN11 ) ) ) {
					$out['release'] = 'Windows 11';
					$out['support'] = 'supported';
					$out['note']    = [ 'tpl' => 'Versão mais recente que a tabela de ciclo de vida do portal.' ];
				}
				return $out;
			}
			[ $ver, $pro, $ent ] = $row;
			$out['release']      = 'Windows 11 ' . $ver;
			$edition             = preg_match( '/\b(home|pro)\b/', $o ) ? 'pro' : ( preg_match( '/enterprise|education|iot/', $o ) ? 'ent' : '' );
			if ( 'pro' === $edition ) {
				$out['supportEnd'] = $pro;
				$out['support']    = self::status( $pro, $now );
				$out['note']       = [ 'tpl' => 'Home/Pro: atualizações até {a}.', 'a' => $pro ];
				return $out;
			}
			$out['supportEnd'] = $ent;
			$out['support']    = self::status( $ent, $now );
			if ( 'ent' === $edition || $pro === $ent ) {
				$out['note'] = [ 'tpl' => 'Enterprise/Education: atualizações até {a}.', 'a' => $ent ];
			} else {
				// Edition unknown (cloud devices report just "Windows").
				$out['note'] = [ 'tpl' => $now > $pro ? 'Enterprise/Education: atualizações até {a}. Home/Pro: encerrado em {b}.' : 'Enterprise/Education: até {a} · Home/Pro: até {b}.', 'a' => $ent, 'b' => $pro ];
				if ( 'supported' === $out['support'] && $now > $pro ) {
					$out['support'] = 'entOnly';
				}
			}
			return $out;
		}

		if ( $build > 0 && $build < 10240 ) {
			$out['release'] = match ( true ) { $build >= 9600 => 'Windows 8.1', $build >= 9200 => 'Windows 8', $build >= 7600 => 'Windows 7', default => 'Windows Vista' };
			$out['support'] = 'unsupported';
			$out['note']    = [ 'tpl' => 'Versão do Windows sem suporte há anos — substitua ou atualize para o Windows 11.' ];
			return $out;
		}
		if ( isset( self::WIN10[ $build ] ) ) {
			$out['release']    = 'Windows 10 ' . self::WIN10[ $build ];
			$out['support']    = 'unsupported';
			$out['supportEnd'] = 19045 === $build ? self::WIN10_END : '';
			$out['note']       = 19045 === $build
				? [ 'tpl' => 'Fim do suporte em {a}. Só recebe correções de segurança com ESU pago (organizações: até {b}).', 'a' => self::WIN10_END, 'b' => self::ESU_END ]
				: [ 'tpl' => 'Versão do Windows 10 fora de suporte — atualize para o Windows 11.' ];
			// Same build as a still-supported LTSC release, but the name doesn't tell the edition (cloud devices report "Windows").
			if ( ! $ltsc && isset( self::LTSC[ $build ] ) && $now <= self::LTSC[ $build ][1] ) {
				$iot_end     = self::LTSC_IOT[ $build ][1] ?? self::LTSC[ $build ][1];
				$out['note'] = $iot_end !== self::LTSC[ $build ][1]
					? [ 'tpl' => 'Fora de suporte, exceto se for LTSC: este build é o {os}, com suporte até {a} (IoT LTSC: {b}). Confira a edição no Intune.', 'os' => self::LTSC[ $build ][0], 'a' => self::LTSC[ $build ][1], 'b' => $iot_end ]
					: [ 'tpl' => 'Fora de suporte, exceto se for LTSC: este build é o {os}, com suporte até {a}. Confira a edição no Intune.', 'os' => self::LTSC[ $build ][0], 'a' => self::LTSC[ $build ][1] ];
			}
		}
		return $out;
	}

	private static function status( string $end, string $now ): string {
		if ( $now > $end ) {
			return 'unsupported';
		}
		$days = (int) floor( ( strtotime( $end . ' 00:00:00 UTC' ) - strtotime( $now . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
		return $days <= self::ENDING_DAYS ? 'ending' : 'supported';
	}
}
