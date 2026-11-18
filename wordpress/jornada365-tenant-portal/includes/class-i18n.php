<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portal languages: Português (Brasil) — source —, Español and English.
 *
 * Dictionaries are keyed by the Portuguese text (languages/{es,en}.json), shared by the PHP
 * pages and the front-end (the active dictionary is handed to the browser). Keys may contain
 * typed placeholders: {n} matches numbers/dates/counters, {s} matches any text. Keys that end
 * with ':' also translate as a prefix ("Intune indisponível: <detalhe da Microsoft>").
 */
final class I18n {

	public const COOKIE = 'j365_lang';
	public const LANGS  = [
		'pt-BR' => [ 'label' => 'Português', 'short' => 'PT', 'ms' => 'pt-BR', 'html' => 'pt-BR', 'intl' => 'pt-BR' ],
		'es'    => [ 'label' => 'Español', 'short' => 'ES', 'ms' => 'es-ES', 'html' => 'es', 'intl' => 'es' ],
		'en'    => [ 'label' => 'English', 'short' => 'EN', 'ms' => 'en-US', 'html' => 'en', 'intl' => 'en-US' ],
	];

	private static ?string $current = null;
	private static array $dicts     = [];
	private static array $patterns  = [];

	/** Explicit choice (?lang= or cookie) → browser Accept-Language → pt-BR. */
	public static function current(): string {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$q = isset( $_GET['lang'] ) ? self::normalize( (string) wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display preference
		if ( '' !== $q ) {
			self::remember( $q );
			return self::$current = $q;
		}
		$c = isset( $_COOKIE[ self::COOKIE ] ) ? self::normalize( (string) $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' !== $c ) {
			return self::$current = $c;
		}
		return self::$current = self::from_accept( (string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) );
	}

	public static function set( string $lang ): void {
		self::$current = self::normalize( $lang ) ?: 'pt-BR';
	}

	public static function normalize( string $lang ): string {
		$l = strtolower( substr( trim( $lang ), 0, 5 ) );
		if ( str_starts_with( $l, 'pt' ) ) {
			return 'pt-BR';
		}
		if ( str_starts_with( $l, 'es' ) ) {
			return 'es';
		}
		if ( str_starts_with( $l, 'en' ) ) {
			return 'en';
		}
		return '';
	}

	public static function from_accept( string $header ): string {
		$best = [ 'pt-BR', 0.0 ];
		foreach ( explode( ',', $header ) as $i => $part ) {
			$bits = explode( ';q=', trim( $part ) );
			$lang = self::normalize( $bits[0] );
			$q    = isset( $bits[1] ) ? (float) $bits[1] : 1.0 - $i * 0.001;
			if ( '' !== $lang && $q > $best[1] ) {
				$best = [ $lang, $q ];
			}
		}
		return $best[0];
	}

	private static function remember( string $lang ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE, $lang, [ 'expires' => time() + YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ] );
		$_COOKIE[ self::COOKIE ] = $lang;
	}

	public static function html_lang(): string {
		return self::LANGS[ self::current() ]['html'];
	}

	/** Value for Microsoft's ui_locales parameter. */
	public static function ms_locale(): string {
		return self::LANGS[ self::current() ]['ms'];
	}

	public static function intl(): string {
		return self::LANGS[ self::current() ]['intl'];
	}

	/** Dictionary for a language (empty for pt-BR, the source language). */
	public static function dict( ?string $lang = null ): array {
		$lang = $lang ?? self::current();
		if ( 'pt-BR' === $lang ) {
			return [];
		}
		if ( ! isset( self::$dicts[ $lang ] ) ) {
			$file                 = J365TP_DIR . 'languages/' . $lang . '.json';
			$data                 = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$dicts[ $lang ] = is_array( $data ) ? $data : [];
		}
		return self::$dicts[ $lang ];
	}

	/**
	 * Translates a Portuguese UI string; {name} placeholders in $s are filled from $vars.
	 */
	public static function t( string $s, array $vars = [] ): string {
		$out = self::lookup( $s, self::current() );
		foreach ( $vars as $k => $v ) {
			$out = str_replace( '{' . $k . '}', (string) $v, $out );
		}
		return $out;
	}

	public static function lookup( string $s, string $lang ): string {
		$dict = self::dict( $lang );
		if ( ! $dict || '' === trim( $s ) ) {
			return $s;
		}
		if ( isset( $dict[ $s ] ) ) {
			return (string) $dict[ $s ];
		}
		$trim = trim( $s );
		if ( $trim !== $s && isset( $dict[ $trim ] ) ) {
			return str_replace( $trim, (string) $dict[ $trim ], $s );
		}
		foreach ( self::patterns( $lang ) as [ $re, $tpl, $types ] ) {
			if ( preg_match( $re, $s, $m ) ) {
				$i = 0;
				return (string) preg_replace_callback(
					'/\{([ns])\}/',
					static function () use ( &$i, $m, $lang ) {
						++$i;
						return self::lookup( $m[ $i ] ?? '', $lang );
					},
					$tpl
				);
			}
		}
		// Prefix keys ("Rótulo: detalhe"): label and detail are translated independently.
		$pos = strpos( $s, ': ' );
		if ( false !== $pos && $pos > 0 && $pos < 160 ) {
			$prefix = substr( $s, 0, $pos + 1 );
			$rest   = substr( $s, $pos + 2 );
			$p2     = isset( $dict[ $prefix ] ) ? (string) $dict[ $prefix ] : $prefix;
			$r2     = '' === $rest ? $rest : self::lookup( $rest, $lang );
			if ( $p2 !== $prefix || $r2 !== $rest ) {
				return $p2 . ' ' . $r2;
			}
		}
		return $s;
	}

	/** Compiled placeholder keys: [regex, translated template, types]. */
	private static function patterns( string $lang ): array {
		if ( isset( self::$patterns[ $lang ] ) ) {
			return self::$patterns[ $lang ];
		}
		$list = [];
		foreach ( self::dict( $lang ) as $key => $tpl ) {
			if ( ! str_contains( (string) $key, '{' ) ) {
				continue;
			}
			$fixed = preg_replace( '/\{[ns]\}/', '', (string) $key );
			if ( preg_match_all( '/[\p{L}]/u', (string) $fixed ) < 3 ) {
				continue; // too generic ("{s} ({n})") — would mistranslate data
			}
			preg_match_all( '/\{([ns])\}/', (string) $key, $types );
			$parts = preg_split( '/(\{[ns]\})/', (string) $key, -1, PREG_SPLIT_DELIM_CAPTURE );
			$re    = '';
			foreach ( $parts as $p ) {
				if ( '{n}' === $p ) {
					$re .= '([\d.,\s%+\-−—]+|—)';
				} elseif ( '{s}' === $p ) {
					$re .= '(.+?)';
				} else {
					$re .= preg_quote( $p, '/' );
				}
			}
			$list[] = [ '/^' . $re . '$/su', (string) $tpl, $types[1] ];
		}
		usort( $list, fn( $a, $b ) => strlen( $b[0] ) <=> strlen( $a[0] ) );
		return self::$patterns[ $lang ] = $list;
	}

	/** Language switcher data for the front-end. */
	public static function languages(): array {
		$out = [];
		foreach ( self::LANGS as $code => $l ) {
			$out[] = [ 'code' => $code, 'label' => $l['label'], 'short' => $l['short'] ];
		}
		return $out;
	}
}
