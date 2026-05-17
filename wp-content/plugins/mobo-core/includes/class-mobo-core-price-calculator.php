<?php
/**
 * Price calculator.
 *
 * Legacy price type support:
 * - static-price
 * - static-percentage
 * - dynamic-price
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Price_Calculator {

	private $rules;

	public function __construct( Mobo_Core_Legacy_Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Calculate final price based on legacy pricing options.
	 *
	 * @param mixed  $raw_price Raw API price.
	 * @param string $context Context: product|variation|product_compare|variation_compare|product_sale|variation_sale.
	 * @return string|null
	 */
	public function calculate( $raw_price, $context ) {
		if ( null === $raw_price || '' === $raw_price ) {
			return null;
		}

		$price = (float) $raw_price;

		if ( $price < 0 ) {
			$price = 0;
		}

		$options    = $this->rules->get_options();
		$price_type = isset( $options['mobo_price_type'] )
			? sanitize_key( (string) $options['mobo_price_type'] )
			: '';

		switch ( $price_type ) {
			case 'static-price':
				$price = $this->apply_static_price( $price, $options );
				break;

			case 'static-percentage':
				$price = $this->apply_static_percentage( $price, $options );
				break;

			case 'dynamic-price':
				$price = $this->apply_dynamic_price( $price, $options, $context );
				break;

			default:
				/*
				 * Backward compatibility:
				 * If older installs only had mobo_dynamic_price enabled,
				 * still apply dynamic behavior.
				 */
				if ( $this->rules->should_apply_dynamic_price() ) {
					$price = $this->apply_dynamic_price( $price, $options, $context );
				}
				break;
		}

		/**
		 * Final escape hatch for exact custom legacy pricing behavior.
		 *
		 * @param float  $price Current calculated price.
		 * @param mixed  $raw_price Raw API price.
		 * @param array  $options Global options.
		 * @param string $price_type Price type option.
		 * @param string $context Context.
		 */
		$price = apply_filters( 'mobo_core_calculated_price', $price, $raw_price, $options, $price_type, $context );

		if ( ! is_numeric( $price ) ) {
			return null;
		}

		return wc_format_decimal( max( 0, (float) $price ) );
	}

	/**
	 * Add fixed amount to price.
	 *
	 * @param float $price Price.
	 * @param array $options Options.
	 * @return float
	 */
	private function apply_static_price( $price, $options ) {
		$additional_price = isset( $options['global_additional_price'] )
			? (float) $options['global_additional_price']
			: 0;

		if ( $additional_price > 0 ) {
			$price += $additional_price;
		}

		return $price;
	}

	/**
	 * Add percentage to price.
	 *
	 * @param float $price Price.
	 * @param array $options Options.
	 * @return float
	 */
	private function apply_static_percentage( $price, $options ) {
		$percentage = isset( $options['global_additional_percentage'] )
			? (float) $options['global_additional_percentage']
			: 0;

		if ( $percentage > 0 ) {
			$price += ( $price * $percentage / 100 );
		}

		return $price;
	}

	/**
	 * Apply dynamic price.
	 *
	 * Current clean interpretation:
	 * dynamic-price applies both fixed amount and percentage if configured.
	 *
	 * @param float  $price Price.
	 * @param array  $options Options.
	 * @param string $context Context.
	 * @return float
	 */
	private function apply_dynamic_price( $price, $options, $context ) {
		$price = $this->apply_static_price( $price, $options );
		$price = $this->apply_static_percentage( $price, $options );

		/**
		 * Dynamic pricing extension point.
		 *
		 * Use this if old dynamic-price had more complex behavior.
		 *
		 * @param float  $price Current price.
		 * @param array  $options Options.
		 * @param string $context Context.
		 */
		return apply_filters( 'mobo_core_dynamic_price', $price, $options, $context );
	}
}