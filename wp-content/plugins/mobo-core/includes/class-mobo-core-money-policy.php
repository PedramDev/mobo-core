<?php
/**
 * Shared money/currency policy for Mobo shipping and order calculations.
 *
 * Source/Mobo monetary amounts are expressed in Toman. WooCommerce stores may
 * use IRR, in which case the same source amount must be multiplied by 10. All
 * shipping integrations must use the same conversion/filter boundary so remote
 * method pricing and automatic-order calculations cannot drift.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Money_Policy {

	/**
	 * Read the canonical Mobo API price for a cart/order line.
	 *
	 * Variation metadata wins over parent metadata, matching the historical
	 * shipping behavior. Invalid/negative values are ignored instead of silently
	 * becoming zero.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $product_id Parent/simple product ID.
	 * @return float|null
	 */
	public static function get_mobo_api_price( $variation_id, $product_id ) {
		$ids = array_values(
			array_unique(
				array_filter(
					array(
						absint( $variation_id ),
						absint( $product_id ),
					)
				)
			)
		);

		foreach ( $ids as $id ) {
			$value = get_post_meta( $id, 'mobo_api_price', true );
			if ( is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 ) {
				return (float) $value;
			}
		}

		return null;
	}

	/**
	 * Validate a source monetary value without collapsing malformed input to zero.
	 * Null/empty string are valid explicit nullable money states; booleans,
	 * arrays, NaN/INF, negative and overflow values are invalid.
	 *
	 * @param mixed $value Source value.
	 * @return bool
	 */
	public static function is_valid_source_amount( $value ) {
		if ( null === $value || '' === $value ) {
			return true;
		}
		if ( is_bool( $value ) || ! is_numeric( $value ) ) {
			return false;
		}
		$number = (float) $value;
		return is_finite( $number ) && $number >= 0 && $number <= PHP_INT_MAX;
	}

	/**
	 * Convert a source/Mobo Toman amount to the WooCommerce store unit.
	 *
	 * @param mixed $amount Source amount.
	 * @return float
	 */
	public static function source_amount_to_store_amount( $amount ) {
		$currency   = self::store_currency();
		$multiplier = 'IRR' === $currency ? 10.0 : 1.0;
		$multiplier = (float) apply_filters( 'mobo_core_shipping_source_to_store_multiplier', $multiplier, $currency );
		$multiplier = is_finite( $multiplier ) ? max( 0.0, $multiplier ) : 0.0;
		$value      = is_numeric( $amount ) && is_finite( (float) $amount ) ? (float) $amount : 0.0;

		return max( 0.0, $value * $multiplier );
	}

	/**
	 * Convert a WooCommerce store amount back to the source/Mobo Toman unit.
	 *
	 * @param mixed $amount Store amount.
	 * @return float
	 */
	public static function store_amount_to_source_amount( $amount ) {
		$currency = self::store_currency();
		$divisor  = 'IRR' === $currency ? 10.0 : 1.0;
		$divisor  = (float) apply_filters( 'mobo_core_shipping_store_to_source_divisor', $divisor, $currency );
		$divisor  = is_finite( $divisor ) && $divisor > 0 ? $divisor : 1.0;
		$value    = is_numeric( $amount ) && is_finite( (float) $amount ) ? (float) $amount : 0.0;

		return max( 0.0, $value / $divisor );
	}

	/**
	 * Current WooCommerce currency in normalized uppercase form.
	 *
	 * @return string
	 */
	private static function store_currency() {
		return function_exists( 'get_woocommerce_currency' )
			? strtoupper( trim( (string) get_woocommerce_currency() ) )
			: '';
	}
}
