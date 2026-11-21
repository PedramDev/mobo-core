<?php
/**
 * Pre-payment destination checks for Mobo-only carts with automatic Source order
 * submission (review B6).
 *
 * Before the shopper pays, two things that would otherwise only fail AFTER payment
 * are checked inside the owning Connection:
 * 1. the selected WooCommerce shipping method has a Source shipping mapping (local);
 * 2. the destination resolves through the central resolver (one network call with a
 *    short timeout).
 *
 * FAIL OPEN: only an explicit, deterministic answer blocks checkout
 * (mapping_missing, or the Backend answering status "pending" for this address).
 * Transport errors, HTTP errors, an open circuit, unexpected payloads, exceptions
 * and a numeric city of unprovable origin (a merchant configuration issue the
 * shopper cannot fix) all ALLOW checkout; such orders then use the normal deferral
 * and admin escalation path. Our outage must never stop sales.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Checkout_Preflight {

	const RESOLVE_TIMEOUT_SECONDS = 6;

	const ADDRESS_MESSAGE  = 'شهر یا استان واردشده برای ارسال این سفارش قابل شناسایی نیست. لطفاً نام استان و شهر را بدون غلط املایی و به فارسی (مثلاً «تهران») وارد کنید و دوباره تلاش کنید.';
	const SHIPPING_MESSAGE = 'روش ارسال انتخاب‌شده برای این سفارش در دسترس نیست. لطفاً روش ارسال دیگری انتخاب کنید یا با فروشگاه تماس بگیرید.';

	/**
	 * Pure decision for the resolver outcome.
	 *
	 * @param array|WP_Error $result resolve_values_via_backend() result.
	 * @return string Customer message, '' to allow checkout.
	 */
	public static function address_error_message( $result ) {
		if ( ! is_wp_error( $result ) || 'mobo_core_location_resolution_pending' !== $result->get_error_code() ) {
			return '';
		}
		$data = $result->get_error_data();
		return is_array( $data ) && isset( $data['backendStatus'] ) && 'pending' === $data['backendStatus'] ? self::ADDRESS_MESSAGE : '';
	}

	/**
	 * Pure decision for the shipping mapping outcome.
	 *
	 * @param true|WP_Error $mapping Mapping status.
	 * @return string Customer message, '' to allow checkout.
	 */
	public static function shipping_error_message( $mapping ) {
		return is_wp_error( $mapping ) && 'mobo_core_shipping_method_mapping_missing' === $mapping->get_error_code() ? self::SHIPPING_MESSAGE : '';
	}

	/**
	 * Destination the order will use, from classic checkout posted data.
	 *
	 * @param array $data WooCommerce posted checkout data.
	 * @return array country/state/city.
	 */
	public static function checkout_destination_values( $data ) {
		$data  = is_array( $data ) ? $data : array();
		$group = ! empty( $data['ship_to_different_address'] ) ? 'shipping' : 'billing';
		$out   = array();
		foreach ( array( 'country', 'state', 'city' ) as $part ) {
			$key          = $group . '_' . $part;
			$out[ $part ] = isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		}
		return $out;
	}

	/**
	 * Combine both checks. Never throws.
	 *
	 * @param true|WP_Error $shipping_mapping Shipping mapping status.
	 * @param callable      $resolver Returns the resolver result.
	 * @return true|WP_Error
	 */
	public static function evaluate( $shipping_mapping, $resolver ) {
		$message = self::shipping_error_message( $shipping_mapping );
		if ( '' !== $message ) {
			return new WP_Error( 'mobo_core_checkout_shipping_unmapped', $message );
		}
		$result  = self::call_resolver_fail_open( $resolver );
		$message = self::address_error_message( $result );
		if ( '' !== $message ) {
			return new WP_Error( 'mobo_core_checkout_address_unresolvable', $message );
		}
		return true;
	}

	/**
	 * Classic checkout (woocommerce_after_checkout_validation). Run inside the owning
	 * Connection context.
	 *
	 * @param array $data Posted data.
	 * @return true|WP_Error
	 */
	public static function validate_checkout_data( $data ) {
		$shipping = self::shipping_status( null );
		$values   = self::checkout_destination_values( $data );
		return self::evaluate( $shipping, static function () use ( $values ) {
			$mapping = new Mobo_Core_Address_Mapping();
			return $mapping->resolve_values_via_backend( $values );
		} );
	}

	/**
	 * Checkout Block / Store API (draft order already exists). Run inside the owning
	 * Connection context.
	 *
	 * @param WC_Order $order Draft order.
	 * @param string   $group billing|shipping address group used for submission.
	 * @return true|WP_Error
	 */
	public static function validate_order( $order, $group ) {
		$shipping = self::shipping_status( $order );
		return self::evaluate( $shipping, static function () use ( $order, $group ) {
			$mapping = new Mobo_Core_Address_Mapping();
			return $mapping->resolve_order_group_via_backend( $order, $group );
		} );
	}

	/**
	 * @param WC_Order|null $order Order for Store API, null for the classic cart.
	 * @return true|WP_Error
	 */
	private static function shipping_status( $order ) {
		try {
			if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
				return true;
			}
			$methods = new Mobo_Core_Remote_Shipping_Methods();
			return null === $order ? $methods->checkout_shipping_mapping_status() : $methods->order_shipping_mapping_status( $order );
		} catch ( Throwable $e ) {
			return true;
		}
	}

	/**
	 * Call the resolver with a short HTTP timeout; any exception counts as "unknown".
	 *
	 * @param callable $resolver Resolver.
	 * @return mixed
	 */
	private static function call_resolver_fail_open( $resolver ) {
		$cap = static function ( $args, $url ) {
			if ( is_array( $args ) && false !== strpos( (string) $url, 'resolve-location' ) ) {
				$current         = isset( $args['timeout'] ) ? (float) $args['timeout'] : (float) self::RESOLVE_TIMEOUT_SECONDS;
				$args['timeout'] = min( $current, (float) self::RESOLVE_TIMEOUT_SECONDS );
			}
			return $args;
		};
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'http_request_args', $cap, 999, 2 );
		}
		try {
			return is_callable( $resolver ) ? call_user_func( $resolver ) : null;
		} catch ( Throwable $e ) {
			return new WP_Error( 'mobo_core_checkout_preflight_exception', 'Pre-payment destination check failed open.' );
		} finally {
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'http_request_args', $cap, 999 );
			}
		}
	}
}
