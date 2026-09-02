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
		$value = get_option( sanitize_key( (string) $option ), $default );
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
