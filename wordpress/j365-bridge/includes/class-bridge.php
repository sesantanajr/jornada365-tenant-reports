<?php
namespace J365BR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bridge {

	private static ?Bridge $instance = null;

	public static function instance(): Bridge {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		Bridge_Token::discard_legacy_tokens();
		( new Bridge_Rest() )->register();
		if ( is_admin() ) {
			( new Bridge_Admin() )->register();
		}
	}
}
