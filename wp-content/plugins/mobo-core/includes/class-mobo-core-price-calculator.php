<?php
/**
 * Price calculator matching legacy option behavior.
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
	 * Calculate price using legacy global options.
	 *
	 * @param mixed  $raw_price Raw API price.
	 * @param string $context Context.
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

		$options = $this->rules->get_options();

		if ( $this->rules->should_apply_dynamic_price() ) {
			$additional_price = isset( $options['global_additional_price'] ) ? (float) $options['global_additional_price'] : 0;
			$percentage       = isset( $options['global_additional_percentage'] ) ? (float) $options['global_additional_percentage'] : 0;

			if ( $additional_price > 0 ) {
				$price += $additional_price;
			}

			if ( $percentage > 0 ) {
				$price += ( $price * $percentage / 100 );
			}
		}

		$price_type = isset( $options['mobo_price_type'] ) ? sanitize_key( (string) $options['mobo_price_type'] ) : '0';

		/**
		 * Allows preserving exact older custom pricing behavior.
		 *
		 * @param float  $price Current calculated price.
		 * @param mixed  $raw_price Raw API price.
		 * @param array  $options Legacy global options.
		 * @param string $price_type Price type option.
		 * @param string $context product|variation|compare|sale.
		 */
		$price = apply_filters( 'mobo_core_calculated_price', $price, $raw_price, $options, $price_type, $context );

		if ( ! is_numeric( $price ) ) {
			return null;
		}

		return wc_format_decimal( max( 0, (float) $price ) );
	}
}