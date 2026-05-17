<?php
/**
 * Price calculator.
 *
 * Mirrors legacy set_variant_prices() behavior:
 * - comparePrice controls regular/sale price when auto compare is enabled.
 * - mobo_additional_price on a specific product/variation overrides global pricing rules.
 * - static-price adds global_additional_price.
 * - static-percentage multiplies by floatval('1.' . percentage).
 * - dynamic-price applies first matching active condition.
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
	 * Calculate regular and sale prices for a product or variation.
	 *
	 * @param int    $object_id Product/variation ID. Can be 0 for new objects.
	 * @param mixed  $price API price.
	 * @param mixed  $compare_price API comparePrice.
	 * @param string $context Context.
	 * @return array
	 */
	public function calculate_price_pair( $object_id, $price, $compare_price, $context ) {
		if ( null === $price || '' === $price ) {
			return array(
				'regular_price' => null,
				'sale_price'    => '',
			);
		}

		$options = $this->rules->get_options();

		$auto_compare = isset( $options['global_product_auto_compare_price'] )
			? (string) $options['global_product_auto_compare_price']
			: '0';

		$base_price   = intval( $price );
		$regular_base = $base_price;
		$sale_base    = '';

		if ( null !== $compare_price && '' !== $compare_price && '1' === $auto_compare ) {
			$regular_base = intval( $compare_price );
			$sale_base    = $base_price;
		}

		$additional_price = $this->get_object_additional_price( $object_id );

		if ( null !== $additional_price ) {
			$regular_price = $regular_base + $additional_price;
			$sale_price    = '';

			if ( '' !== $sale_base ) {
				$sale_price = intval( $sale_base ) + $additional_price;
			}

			return $this->format_pair(
				$regular_price,
				$sale_price,
				$price,
				$compare_price,
				$options,
				'object-additional-price',
				$context
			);
		}

		$price_type = isset( $options['mobo_price_type'] )
			? sanitize_key( (string) $options['mobo_price_type'] )
			: 'static-price';

		switch ( $price_type ) {
			default:
			case 'static-price':
				return $this->calculate_static_price_pair(
					$regular_base,
					$sale_base,
					$price,
					$compare_price,
					$options,
					$context
				);

			case 'static-percentage':
				return $this->calculate_static_percentage_pair(
					$regular_base,
					$sale_base,
					$price,
					$compare_price,
					$options,
					$context
				);

			case 'dynamic-price':
				return $this->calculate_dynamic_price_pair(
					$regular_base,
					$sale_base,
					$price,
					$compare_price,
					$options,
					$context
				);
		}
	}

	/**
	 * Legacy static-price:
	 * regular/sale + global_additional_price.
	 *
	 * @param int    $regular_base Regular base.
	 * @param int|string $sale_base Sale base or empty.
	 * @param mixed  $raw_price Raw API price.
	 * @param mixed  $raw_compare_price Raw compare.
	 * @param array  $options Options.
	 * @param string $context Context.
	 * @return array
	 */
	private function calculate_static_price_pair( $regular_base, $sale_base, $raw_price, $raw_compare_price, $options, $context ) {
		$static_price = isset( $options['global_additional_price'] )
			? intval( $options['global_additional_price'] )
			: 0;

		$regular_price = intval( $regular_base ) + $static_price;
		$sale_price    = '';

		if ( '' !== $sale_base ) {
			$sale_price = intval( $sale_base ) + $static_price;
		}

		return $this->format_pair(
			$regular_price,
			$sale_price,
			$raw_price,
			$raw_compare_price,
			$options,
			'static-price',
			$context
		);
	}

	/**
	 * Legacy static-percentage:
	 * regular/sale * floatval('1.' . global_additional_percentage).
	 *
	 * @param int    $regular_base Regular base.
	 * @param int|string $sale_base Sale base or empty.
	 * @param mixed  $raw_price Raw API price.
	 * @param mixed  $raw_compare_price Raw compare.
	 * @param array  $options Options.
	 * @param string $context Context.
	 * @return array
	 */
	private function calculate_static_percentage_pair( $regular_base, $sale_base, $raw_price, $raw_compare_price, $options, $context ) {
		$percentage = isset( $options['global_additional_percentage'] )
			? (string) $options['global_additional_percentage']
			: '0';

		$factor = floatval( '1.' . $percentage );

		$regular_price = intval( $regular_base ) * $factor;
		$sale_price    = '';

		if ( '' !== $sale_base ) {
			$sale_price = intval( $sale_base ) * $factor;
		}

		return $this->format_pair(
			$regular_price,
			$sale_price,
			$raw_price,
			$raw_compare_price,
			$options,
			'static-percentage',
			$context
		);
	}

	/**
	 * Legacy dynamic-price:
	 * Check conditions against raw API price, not comparePrice.
	 * First matching active condition wins.
	 *
	 * mobo_dynamic_price JSON example:
	 * [
	 *   {
	 *     "is_active": "true",
	 *     "low": "100000",
	 *     "high": "500000",
	 *     "benefit_type": "static",
	 *     "benefit": "50000"
	 *   }
	 * ]
	 *
	 * @param int    $regular_base Regular base.
	 * @param int|string $sale_base Sale base or empty.
	 * @param mixed  $raw_price Raw API price.
	 * @param mixed  $raw_compare_price Raw compare.
	 * @param array  $options Options.
	 * @param string $context Context.
	 * @return array
	 */
	private function calculate_dynamic_price_pair( $regular_base, $sale_base, $raw_price, $raw_compare_price, $options, $context ) {
		$conditions_json = isset( $options['mobo_dynamic_price'] )
			? (string) $options['mobo_dynamic_price']
			: '';

		$conditions = json_decode( $conditions_json, true );

		if ( ! is_array( $conditions ) ) {
			return $this->format_pair(
				$regular_base,
				$sale_base,
				$raw_price,
				$raw_compare_price,
				$options,
				'dynamic-price-no-match',
				$context
			);
		}

		$api_price     = intval( $raw_price );
		$regular_price = intval( $regular_base );
		$sale_price    = $sale_base;

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$is_active = isset( $condition['is_active'] ) ? (string) $condition['is_active'] : 'false';
			$low       = isset( $condition['low'] ) ? intval( $condition['low'] ) : 0;
			$high      = isset( $condition['high'] ) ? intval( $condition['high'] ) : 0;

			if ( 'true' !== $is_active ) {
				continue;
			}

			if ( $api_price < $low || $api_price > $high ) {
				continue;
			}

			$benefit_type = isset( $condition['benefit_type'] )
				? sanitize_key( (string) $condition['benefit_type'] )
				: 'static';

			$benefit = isset( $condition['benefit'] ) ? (string) $condition['benefit'] : '0';

			if ( 'static' === $benefit_type ) {
				$regular_price = intval( $regular_base ) + intval( $benefit );

				if ( '' !== $sale_base ) {
					$sale_price = intval( $sale_base ) + intval( $benefit );
				}
			} else {
				$factor        = floatval( '1.' . $benefit );
				$regular_price = intval( $regular_base ) * $factor;

				if ( '' !== $sale_base ) {
					$sale_price = intval( $sale_base ) * $factor;
				}
			}

			return $this->format_pair(
				$regular_price,
				$sale_price,
				$raw_price,
				$raw_compare_price,
				$options,
				'dynamic-price',
				$context
			);
		}

		return $this->format_pair(
			$regular_price,
			$sale_price,
			$raw_price,
			$raw_compare_price,
			$options,
			'dynamic-price-no-match',
			$context
		);
	}

	/**
	 * Get per-product/per-variation additional price.
	 *
	 * In legacy code this is stored on variant:
	 * mobo_additional_price
	 *
	 * @param int $object_id Product/variation ID.
	 * @return int|null
	 */
	private function get_object_additional_price( $object_id ) {
		$object_id = absint( $object_id );

		if ( $object_id <= 0 ) {
			return null;
		}

		$value = get_post_meta( $object_id, 'mobo_additional_price', true );

		if ( null === $value || '' === $value ) {
			return null;
		}

		return intval( $value );
	}

	/**
	 * Format and filter pair.
	 *
	 * @param mixed  $regular_price Regular price.
	 * @param mixed  $sale_price Sale price or empty.
	 * @param mixed  $raw_price Raw API price.
	 * @param mixed  $raw_compare_price Raw compare price.
	 * @param array  $options Options.
	 * @param string $price_type Price type applied.
	 * @param string $context Context.
	 * @return array
	 */
	private function format_pair( $regular_price, $sale_price, $raw_price, $raw_compare_price, $options, $price_type, $context ) {
		$regular_price = is_numeric( $regular_price ) ? max( 0, (float) $regular_price ) : null;
		$sale_price    = is_numeric( $sale_price ) ? max( 0, (float) $sale_price ) : '';

		/**
		 * Final filter for custom compatibility.
		 *
		 * @param array  $pair Pair with regular_price and sale_price.
		 * @param mixed  $raw_price Raw API price.
		 * @param mixed  $raw_compare_price Raw compare price.
		 * @param array  $options Legacy options.
		 * @param string $price_type Applied price type.
		 * @param string $context Context.
		 */
		$pair = apply_filters(
			'mobo_core_calculated_price_pair',
			array(
				'regular_price' => null === $regular_price ? null : wc_format_decimal( $regular_price ),
				'sale_price'    => '' === $sale_price ? '' : wc_format_decimal( $sale_price ),
			),
			$raw_price,
			$raw_compare_price,
			$options,
			$price_type,
			$context
		);

		if ( ! is_array( $pair ) ) {
			$pair = array(
				'regular_price' => null,
				'sale_price'    => '',
			);
		}

		return array(
			'regular_price' => isset( $pair['regular_price'] ) ? $pair['regular_price'] : null,
			'sale_price'    => isset( $pair['sale_price'] ) ? $pair['sale_price'] : '',
		);
	}
}