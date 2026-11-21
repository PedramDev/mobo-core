<?php
/**
 * Optional storefront price rounding applied AFTER all markup rules.
 *
 * The rounding step is expressed in WooCommerce store currency (the same unit
 * the calculator output uses after any Source->Store conversion). The default
 * is "none", which preserves the historical calculator output byte-for-byte.
 *
 * Examples (store currency, step 1000):
 *   up      1,419,752.05 -> 1,420,000
 *   down    1,419,752.05 -> 1,419,000
 *   nearest 1,419,499    -> 1,419,000 ; 1,419,500 -> 1,420,000 (half up)
 *
 * The same helper is used by Product Sync, Reprice, product/variation overrides
 * and delta payloads because they all leave the calculator through format_pair().
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Price_Rounding {
	const OPTION_STEP = 'mobo_core_price_rounding_step';
	const OPTION_MODE = 'mobo_core_price_rounding_mode';

	const MODE_UP      = 'up';
	const MODE_DOWN    = 'down';
	const MODE_NEAREST = 'nearest';

	/** Allowed steps in store currency. 0 means no rounding. */
	public static function allowed_steps() {
		return array( 0, 100, 1000, 10000 );
	}

	public static function allowed_modes() {
		return array( self::MODE_UP, self::MODE_DOWN, self::MODE_NEAREST );
	}

	public static function normalize_step( $value ) {
		if ( is_bool( $value ) || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}
		$step = (int) $value;
		return in_array( $step, self::allowed_steps(), true ) ? $step : 0;
	}

	public static function normalize_mode( $value ) {
		$mode = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return in_array( $mode, self::allowed_modes(), true ) ? $mode : self::MODE_UP;
	}

	/** Current Connection-scoped policy. @return array{step:int,mode:string} */
	public static function current_policy() {
		$step = 0;
		$mode = self::MODE_UP;
		if ( class_exists( 'Mobo_Core_Settings' ) ) {
			$step = self::normalize_step( Mobo_Core_Settings::get( self::OPTION_STEP, 0 ) );
			$mode = self::normalize_mode( Mobo_Core_Settings::get( self::OPTION_MODE, self::MODE_UP ) );
		}
		return array( 'step' => $step, 'mode' => $mode );
	}

	/** Whether rounding is active (non-default). */
	public static function is_active( $policy = null ) {
		$policy = is_array( $policy ) ? $policy : self::current_policy();
		return self::normalize_step( isset( $policy['step'] ) ? $policy['step'] : 0 ) > 0;
	}

	/**
	 * Round one non-negative store-currency amount.
	 *
	 * @param float|int|string $amount Amount.
	 * @param int              $step   0/100/1000/10000.
	 * @param string           $mode   up|down|nearest.
	 * @return float|null Rounded amount, or the original amount when step is 0.
	 */
	public static function round_amount( $amount, $step, $mode ) {
		if ( is_bool( $amount ) || ! is_numeric( $amount ) ) {
			return null;
		}
		$value = (float) $amount;
		if ( ! is_finite( $value ) || $value < 0 ) {
			return null;
		}
		$step = self::normalize_step( $step );
		if ( $step <= 0 ) {
			return $value;
		}
		$mode = self::normalize_mode( $mode );
		/* Work on integer multiples. A tiny epsilon prevents 1000.0000000001 from
		 * being pushed one whole step up by float noise in "up" mode. */
		$ratio = $value / $step;
		$epsilon = 1e-9;
		if ( self::MODE_DOWN === $mode ) {
			$units = floor( $ratio + $epsilon );
		} elseif ( self::MODE_NEAREST === $mode ) {
			$units = floor( $ratio + 0.5 + $epsilon );
		} else {
			$units = ceil( $ratio - $epsilon );
		}
		return (float) ( $units * $step );
	}

	/**
	 * Round a regular/sale pair and keep WooCommerce's sale < regular invariant.
	 *
	 * If both values collapse into the same rounded amount, the sale is dropped
	 * (the customer pays the rounded regular price and no fake discount is shown).
	 *
	 * @param mixed  $regular Regular price (numeric) or null.
	 * @param mixed  $sale    Sale price (numeric) or ''.
	 * @param array  $policy  Policy array.
	 * @return array{0:mixed,1:mixed}
	 */
	public static function round_pair( $regular, $sale, $policy = null ) {
		$policy = is_array( $policy ) ? $policy : self::current_policy();
		if ( ! self::is_active( $policy ) ) {
			return array( $regular, $sale );
		}
		$step = (int) $policy['step'];
		$mode = (string) $policy['mode'];
		if ( null !== $regular && is_numeric( $regular ) ) {
			$rounded = self::round_amount( $regular, $step, $mode );
			$regular = null === $rounded ? $regular : $rounded;
		}
		if ( '' !== $sale && null !== $sale && is_numeric( $sale ) ) {
			$rounded = self::round_amount( $sale, $step, $mode );
			$sale = null === $rounded ? $sale : $rounded;
			if ( null !== $regular && is_numeric( $regular ) && (float) $sale >= (float) $regular ) {
				$sale = '';
			}
		}
		return array( $regular, $sale );
	}

	/** Fingerprint fragment; empty when inactive so historical hashes are kept. */
	public static function fingerprint_fragment( $policy = null ) {
		$policy = is_array( $policy ) ? $policy : self::current_policy();
		if ( ! self::is_active( $policy ) ) {
			return array();
		}
		return array(
			'roundingStep' => (int) $policy['step'],
			'roundingMode' => (string) $policy['mode'],
		);
	}
}
