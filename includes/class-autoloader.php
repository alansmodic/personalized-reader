<?php
/**
 * Minimal PSR-ish autoloader: maps PersonalizedReader\Foo\Bar_Baz to
 * includes/foo/class-bar-baz.php.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$leaf     = array_pop( $parts );
		$leaf     = 'class-' . strtolower( str_replace( '_', '-', $leaf ) ) . '.php';
		$dir      = '';
		foreach ( $parts as $segment ) {
			$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$path = PLUGIN_DIR . '/includes/' . $dir . $leaf;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
