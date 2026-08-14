<?php
/**
 * Lightweight class autoloader for Mobo Core.
 *
 * Keeps heavy sync, image, migration, health and admin classes off ordinary
 * frontend requests until one of those code paths is actually used.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Autoloader {

	/** @var bool */
	private static $registered = false;

	/**
	 * Register the autoloader once.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		spl_autoload_register( array( __CLASS__, 'autoload' ), true, true );
	}

	/**
	 * Load one Mobo Core class by the existing filename convention.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		$class = ltrim( (string) $class, '\\' );
		$file  = '';

		if ( 0 === strpos( $class, 'Mobo_Core_' ) ) {
			$slug = strtolower( str_replace( '_', '-', substr( $class, strlen( 'Mobo_Core_' ) ) ) );
			$file = MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-' . $slug . '.php';
		} elseif ( 'WC_Mobo_Core_Shipping_Method' === $class ) {
			$file = MOBO_CORE_PLUGIN_DIR . 'includes/class-wc-mobo-core-shipping-method.php';
		}

		if ( '' !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}
}
