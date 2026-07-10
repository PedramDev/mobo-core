<?php
/**
 * Mobo Core logging abstraction.
 *
 * Uses WooCommerce's structured logger and intentionally avoids PHP's global
 * error log so messages remain scoped, searchable, and suitable for production.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Logger {

	/**
	 * Write an error message to the WooCommerce log when available.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public static function error( $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context = is_array( $context ) ? $context : array();
		$context['source'] = 'mobo-core';

		wc_get_logger()->error( sanitize_text_field( (string) $message ), $context );
	}

	/**
	 * Write a warning message to the WooCommerce log when available.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public static function warning( $message, $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$context = is_array( $context ) ? $context : array();
		$context['source'] = 'mobo-core';

		wc_get_logger()->warning( sanitize_text_field( (string) $message ), $context );
	}
}
