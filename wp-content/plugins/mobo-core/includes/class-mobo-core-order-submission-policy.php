<?php
/**
 * Shared automatic Mobo order-submission activation policy.
 *
 * Runtime services, diagnostics and UI must interpret the master submission
 * switch identically. Checkout-validation feature flags remain separate: they
 * control optional pre-payment checks and must not be coupled to submission.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Order_Submission_Policy {

	const OPTION_ENABLED = 'mobo_core_mobo_order_submission_enabled';

	/**
	 * Whether automatic Mobo order submission is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::option_enabled( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Bootstrap helper: whether any enabled Connection has automatic submission on.
	 * Runtime submission must still be evaluated inside the order owner's context.
	 *
	 * @return bool
	 */
	public static function is_enabled_for_any_connection() {
		if ( class_exists( 'Mobo_Core_Connection_Settings' ) ) {
			return Mobo_Core_Connection_Settings::any_enabled_boolean( self::OPTION_ENABLED, '0' );
		}
		return self::is_enabled();
	}

	/**
	 * Whether remote-shipping runtime is needed by any feature that consumes it.
	 *
	 * Keep this composition here so shipping discovery and diagnostics cannot use
	 * different activation rules.
	 *
	 * @return bool
	 */
	public static function is_shipping_runtime_enabled() {
		return self::is_enabled()
			|| self::option_enabled( 'mobo_core_mobo_shipping_package_enabled', '0' )
			|| self::option_enabled( 'mobo_core_automatic_shipping_enabled', '0' );
	}

	/**
	 * Read one boolean option without loading the full settings registry.
	 * This keeps the bootstrap decision cheap while ensuring every runtime uses
	 * the exact same truth table for enabled values.
	 *
	 * @param string $option Option key.
	 * @param string $default Default value.
	 * @return bool
	 */
	private static function option_enabled( $option, $default = '0' ) {
		$option = sanitize_key( (string) $option );
		$value = class_exists( 'Mobo_Core_Connection_Settings' ) && Mobo_Core_Connection_Settings::is_scoped_key( $option )
			? Mobo_Core_Connection_Settings::get_option( $option, $default )
			: get_option( $option, $default );
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
