<?php
namespace J365TP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand assets generated on the server from a logo configured by the operator:
 *  - logo-wordmark.png : full logo for the UI (max 720px wide, transparent)
 *  - app-icon.png      : 215×215 square for the App Registration (≤ 100 KB, white background)
 *  - favicon.png       : 64×64
 * Stored in uploads/j365tp-brand/. Uses GD when available; otherwise the original logo is
 * used in the UI and the setup script builds the square icon locally.
 */
final class Brand {

	public const DIR       = 'j365tp-brand';
	public const ICON_SIZE = 215;
	public const ICON_MAX  = 102400;
	private const SOURCE_MAX_BYTES = 8 * 1024 * 1024;

	/** Crop of the "365" badge inside the logo (fractions of width/height), measured on the official artwork. */
	public const BADGE_CROP = [ 'x0' => 0.675, 'y0' => 0.39, 'x1' => 0.975, 'y1' => 0.785 ];

	private static function paths(): array {
		$up   = wp_get_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . self::DIR . '/';
		$url  = trailingslashit( $up['baseurl'] ) . self::DIR . '/';
		return [ 'dir' => $base, 'url' => $url ];
	}

	public static function meta(): array {
		$m = get_option( 'j365tp_brand', [] );
		return is_array( $m ) ? $m : [];
	}

	private static function url_for( string $file ): string {
		$m = self::meta();
		$p = self::paths();
		if ( empty( $m['files'][ $file ] ) || ! file_exists( $p['dir'] . $file ) ) {
			return '';
		}
		return $p['url'] . $file . '?v=' . rawurlencode( (string) ( $m['version'] ?? '1' ) );
	}

	public static function wordmark_url(): string {
		return self::url_for( 'logo-wordmark.png' ) ?: self::url_for( 'logo-source.png' );
	}

	public static function icon_url(): string {
		return self::url_for( 'app-icon.png' );
	}

	/** Light version of the wordmark for dark backgrounds (no white plate). */
	public static function wordmark_dark_url(): string {
		return self::url_for( 'logo-wordmark-dark.png' );
	}

	/** Filesystem path of the square app icon ('' when missing). */
	public static function icon_file(): string {
		$m = self::meta();
		$f = self::paths()['dir'] . 'app-icon.png';
		return ! empty( $m['files']['app-icon.png'] ) && file_exists( $f ) ? $f : '';
	}

	public static function favicon_url(): string {
		return self::url_for( 'favicon.png' );
	}

	public static function source_url(): string {
		return self::url_for( 'logo-source.png' );
	}

	public static function gd_available(): bool {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecopyresampled' ) && function_exists( 'imagepng' );
	}

	/**
	 * Downloads the logo from the configured URL and (re)builds the derived assets.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function refresh_from_url( string $url = '' ): array {
		$url = '' !== $url ? $url : (string) Settings::get( 'logo_source_url', Settings::LOGO_SOURCE );
		if ( '' === $url ) {
			return [ 'ok' => false, 'message' => 'Configure uma URL HTTPS nas configurações do portal ou envie o logo manualmente.' ];
		}
		if ( ! preg_match( '#^https://#i', $url ) ) {
			return self::fail( 'URL do logo inválida (use https).' );
		}
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 30,
				'redirection'         => 0,
				'limit_response_size' => self::SOURCE_MAX_BYTES + 1,
				'headers'             => [
					// The logo host applies hotlink protection: present a same-site referer.
					'Referer'    => 'https://' . $host . '/',
					'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Jornada365-TenantPortal/' . J365TP_VERSION,
					'Accept'     => 'image/png,image/webp,image/jpeg,image/*;q=0.8',
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( 'Não foi possível baixar o logo: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return self::fail( "O servidor do logo respondeu HTTP {$code}. Envie o arquivo manualmente no Assistente." );
		}
		return self::store_source( (string) wp_remote_retrieve_body( $response ), 'url' );
	}

	/** Accepts an uploaded logo file (bytes). */
	public static function store_source( string $bytes, string $origin ): array {
		if ( '' === $bytes || strlen( $bytes ) > self::SOURCE_MAX_BYTES ) {
			return self::fail( 'Arquivo de logo vazio ou maior que 8 MB.' );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info['mime'] ?? '', [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ], true ) ) {
			return self::fail( 'O arquivo não é uma imagem PNG/JPG/WebP/GIF válida.' );
		}
		$p = self::paths();
		if ( ! wp_mkdir_p( $p['dir'] ) ) {
			return self::fail( 'Não foi possível criar a pasta de marca em uploads.' );
		}
		$files = [];
		file_put_contents( $p['dir'] . 'logo-source.png', $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$files['logo-source.png'] = true;

		$note = '';
		if ( self::gd_available() ) {
			$built = self::build( $bytes, $p['dir'], (string) Settings::get( 'icon_style', 'badge' ) );
			$files = array_merge( $files, $built );
		} else {
			$note = ' (GD indisponível: o ícone quadrado será gerado pelo script de configuração)';
		}
		update_option(
			'j365tp_brand',
			[
				'files'   => $files,
				'version' => substr( md5( $bytes . (string) Settings::get( 'icon_style', 'badge' ) ), 0, 10 ),
				'origin'  => $origin,
				'size'    => [ (int) $info[0], (int) $info[1] ],
				'updated' => gmdate( 'Y-m-d H:i:s' ),
				'error'   => '',
			],
			false
		);
		return [ 'ok' => true, 'message' => 'Logo atualizado' . $note . '.' ];
	}

	/** Rebuilds the derived assets from the logo already stored on this server (no download). */
	public static function rebuild_local(): bool {
		$f = self::paths()['dir'] . 'logo-source.png';
		if ( ! is_readable( $f ) || ! self::gd_available() ) {
			return false;
		}
		$m      = self::meta();
		$custom = ! empty( $m['custom_icon'] );
		$icon   = $custom && is_readable( self::paths()['dir'] . 'app-icon.png' ) ? (string) file_get_contents( self::paths()['dir'] . 'app-icon.png' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$r      = self::store_source( (string) file_get_contents( $f ), (string) ( $m['origin'] ?? 'url' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $custom && '' !== $icon ) {
			self::store_icon( $icon ); // keep the operator's own icon
		}
		return ! empty( $r['ok'] );
	}

	/** Stores a custom square icon (overrides the generated one). */
	public static function store_icon( string $bytes ): array {
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info['mime'] ?? '', [ 'image/png', 'image/jpeg' ], true ) ) {
			return self::fail( 'O ícone deve ser PNG ou JPG.' );
		}
		$p = self::paths();
		wp_mkdir_p( $p['dir'] );
		if ( self::gd_available() ) {
			$src = imagecreatefromstring( $bytes );
			$out = self::fit_square( $src, 0, 0, imagesx( $src ), imagesy( $src ), self::ICON_SIZE, 0.06 );
			self::save_png_under( $out, $p['dir'] . 'app-icon.png', self::ICON_MAX );
			$fav = self::fit_square( $src, 0, 0, imagesx( $src ), imagesy( $src ), 64, 0.04 );
			imagepng( $fav, $p['dir'] . 'favicon.png', 9 );
		} elseif ( strlen( $bytes ) <= self::ICON_MAX ) {
			file_put_contents( $p['dir'] . 'app-icon.png', $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			return self::fail( 'Ícone maior que 100 KB e GD indisponível para reduzir.' );
		}
		$m                                = self::meta();
		$m['files']['app-icon.png']       = true;
		$m['files']['favicon.png']        = self::gd_available();
		$m['custom_icon']                 = true;
		$m['version']                     = substr( md5( $bytes ), 0, 10 );
		update_option( 'j365tp_brand', $m, false );
		return [ 'ok' => true, 'message' => 'Ícone do aplicativo atualizado.' ];
	}

	private static function fail( string $msg ): array {
		$m          = self::meta();
		$m['error'] = $msg;
		update_option( 'j365tp_brand', $m, false );
		return [ 'ok' => false, 'message' => $msg ];
	}

	/**
	 * Builds wordmark, square app icon and favicon from the source bytes. Pure GD; testable.
	 *
	 * @return array<string,bool> files written
	 */
	public static function build( string $bytes, string $dir, string $style = 'badge' ): array {
		$src = imagecreatefromstring( $bytes );
		if ( ! $src ) {
			throw new \RuntimeException( 'Imagem de logo ilegível.' );
		}
		imagesavealpha( $src, true );
		$w = imagesx( $src );
		$h = imagesy( $src );

		// Trim fully transparent margins so crops are relative to the artwork.
		[ $tx, $ty, $tw, $th ] = self::trim_box( $src );

		// 1) Wordmark (transparent, max 720px wide).
		$ww = min( 720, $tw );
		$wh = (int) round( $th * $ww / $tw );
		$wm = imagecreatetruecolor( $ww, $wh );
		imagealphablending( $wm, false );
		imagesavealpha( $wm, true );
		imagefill( $wm, 0, 0, imagecolorallocatealpha( $wm, 0, 0, 0, 127 ) );
		imagecopyresampled( $wm, $src, 0, 0, $tx, $ty, $ww, $wh, $tw, $th );
		imagepng( $wm, $dir . 'logo-wordmark.png', 9 );
		imagepng( self::on_dark( $wm ), $dir . 'logo-wordmark-dark.png', 9 );

		// 2) Square app icon.
		if ( 'full' === $style ) {
			$icon = self::fit_square( $src, $tx, $ty, $tw, $th, self::ICON_SIZE, 0.06 );
			$fav  = self::fit_square( $src, $tx, $ty, $tw, $th, 64, 0.04 );
		} else {
			// Crop fractions are measured on the full artwork (same as the setup script fallback);
			// then tighten to the opaque pixels inside that region.
			$c    = self::BADGE_CROP;
			$cx   = (int) round( $w * $c['x0'] );
			$cy   = (int) round( $h * $c['y0'] );
			$cw   = max( 1, min( (int) round( $w * ( $c['x1'] - $c['x0'] ) ), $w - $cx ) );
			$ch   = max( 1, min( (int) round( $h * ( $c['y1'] - $c['y0'] ) ), $h - $cy ) );
			[ $cx, $cy, $cw, $ch ] = self::trim_box( $src, [ $cx, $cy, $cw, $ch ] );
			$icon = self::fit_square( $src, $cx, $cy, $cw, $ch, self::ICON_SIZE, 0.08 );
			$fav  = self::fit_square( $src, $cx, $cy, $cw, $ch, 64, 0.05 );
		}
		self::save_png_under( $icon, $dir . 'app-icon.png', self::ICON_MAX );
		imagepng( $fav, $dir . 'favicon.png', 9 );

		return [ 'logo-wordmark.png' => true, 'logo-wordmark-dark.png' => true, 'app-icon.png' => true, 'favicon.png' => true ];
	}

	/**
	 * Dark-background variant: dark blue artwork (wordmark letters, tagline, outlines) becomes white,
	 * the light cyan of the cloud/badge is kept; alpha is preserved, so there is no plate behind it.
	 */
	public static function on_dark( $img ) {
		$w   = imagesx( $img );
		$h   = imagesy( $img );
		$out = imagecreatetruecolor( $w, $h );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c = imagecolorat( $img, $x, $y );
				$a = ( $c >> 24 ) & 0x7F;
				if ( 127 === $a ) {
					imagesetpixel( $out, $x, $y, $c );
					continue;
				}
				$r = ( $c >> 16 ) & 0xFF;
				$g = ( $c >> 8 ) & 0xFF;
				$b = $c & 0xFF;
				$l = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;
				// 0 → keep (bright cyan), 1 → white (dark blue); smooth ramp avoids halos.
				$t  = max( 0.0, min( 1.0, ( 0.62 - $l ) / 0.22 ) );
				$nr = (int) round( $r + ( 255 - $r ) * $t );
				$ng = (int) round( $g + ( 255 - $g ) * $t );
				$nb = (int) round( $b + ( 255 - $b ) * $t );
				imagesetpixel( $out, $x, $y, imagecolorallocatealpha( $out, $nr, $ng, $nb, $a ) );
			}
		}
		return $out;
	}

	/** Places a region of $src centered on a white square canvas with padding. */
	private static function fit_square( $src, int $sx, int $sy, int $sw, int $sh, int $size, float $pad ) {
		$out = imagecreatetruecolor( $size, $size );
		imagealphablending( $out, true );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );
		$inner = (int) round( $size * ( 1 - 2 * $pad ) );
		$scale = min( $inner / $sw, $inner / $sh );
		$dw    = max( 1, (int) round( $sw * $scale ) );
		$dh    = max( 1, (int) round( $sh * $scale ) );
		imagecopyresampled( $out, $src, (int) ( ( $size - $dw ) / 2 ), (int) ( ( $size - $dh ) / 2 ), $sx, $sy, $dw, $dh, $sw, $sh );
		return $out;
	}

	private static function save_png_under( $img, string $path, int $max ): void {
		imagepng( $img, $path, 9 );
		clearstatcache( true, $path );
		if ( filesize( $path ) > $max && function_exists( 'imagetruecolortopalette' ) ) {
			imagetruecolortopalette( $img, true, 128 );
			imagepng( $img, $path, 9 );
		}
	}

	/**
	 * Bounding box of non-transparent pixels (sampled), as [x, y, w, h], optionally inside a region.
	 *
	 * @param array{0:int,1:int,2:int,3:int}|null $region [x, y, w, h]
	 */
	private static function trim_box( $img, ?array $region = null ): array {
		[ $rx, $ry, $rw, $rh ] = $region ?? [ 0, 0, imagesx( $img ), imagesy( $img ) ];
		$w    = $rx + $rw;
		$h    = $ry + $rh;
		$step = max( 1, (int) floor( min( $rw, $rh ) / 300 ) );
		$x0   = $w;
		$y0   = $h;
		$x1   = $rx;
		$y1   = $ry;
		for ( $y = $ry; $y < $h; $y += $step ) {
			for ( $x = $rx; $x < $w; $x += $step ) {
				$a = ( imagecolorat( $img, $x, $y ) >> 24 ) & 0x7F;
				if ( $a < 110 ) {
					$x0 = min( $x0, $x );
					$y0 = min( $y0, $y );
					$x1 = max( $x1, $x );
					$y1 = max( $y1, $y );
				}
			}
		}
		if ( $x1 <= $x0 || $y1 <= $y0 ) {
			return [ $rx, $ry, $rw, $rh ];
		}
		$x0 = max( $rx, $x0 - $step );
		$y0 = max( $ry, $y0 - $step );
		$x1 = min( $w - 1, $x1 + $step );
		$y1 = min( $h - 1, $y1 + $step );
		return [ $x0, $y0, $x1 - $x0 + 1, $y1 - $y0 + 1 ];
	}
}
