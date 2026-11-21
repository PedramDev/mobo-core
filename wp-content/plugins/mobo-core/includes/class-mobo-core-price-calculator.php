<?php
/**
 * Price calculator.
 *
 * Mirrors legacy set_variant_prices() behavior:
 *
 * 1. Base:
 *    price        = API price
 *    comparePrice = API comparePrice
 *
 * 2. If comparePrice exists and global_product_auto_compare_price = 1:
 *    regular_price = comparePrice
 *    sale_price    = price
 *
 * 3. Otherwise:
 *    regular_price = price
 *    sale_price    = empty
 *
 * 4. If mobo_additional_price exists on the current variation/object:
 *    regular/sale + mobo_additional_price
 *    every parent/global pricing rule is skipped. This is the highest-priority
 *    Mobo pricing override.
 *
 * 5. Otherwise, if a parent-level product percentage override is enabled:
 *    regular/sale * (1 + percentage / 100).
 *
 * 6. Otherwise mobo_price_type is used:
 *    - static-price      => + global_additional_price
 *    - static-percentage => * (1 + global_additional_percentage / 100)
 *    - dynamic-price     => first active matching condition from mobo_dynamic_price
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Price_Calculator {

	/**
	 * Legacy rules.
	 *
	 * @var Mobo_Core_Legacy_Rules
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param Mobo_Core_Legacy_Rules $rules Legacy rules.
	 */
	public function __construct( Mobo_Core_Legacy_Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Calculate regular and sale prices for a product or variation.
	 *
	 * @param int    $object_id Product/variation ID. Can be 0 for new objects.
	 * @param mixed  $price API price.
	 * @param mixed  $compare_price API comparePrice.
	 * @param string $context Context: product|variation.
	 * @return array
	 */
	public function calculate_price_pair( $object_id, $price, $compare_price, $context, $parent_id = 0 ) {
		if ( null === $price || '' === $price ) {
			return array(
				'regular_price' => null,
				'sale_price'    => '',
				'error'         => '',
			);
		}

		if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
			return $this->invalid_price_pair( 'Mobo monetary policy is unavailable.' );
		}
		$money_compatibility = Mobo_Core_Money_Policy::assert_compatible();
		if ( is_wp_error( $money_compatibility ) ) {
			// Only an explicit store-currency choice can enable a mismatch bridge.
			// Other monetary errors retain the V1 fail-closed behavior.
			if ( 'mobo_core_money_unit_mismatch' !== $money_compatibility->get_error_code() ) {
				return $this->invalid_price_pair( $money_compatibility->get_error_message() );
			}
			$pricing_context = Mobo_Core_Money_Policy::pricing_conversion_context();
			if ( is_wp_error( $pricing_context ) ) {
				return $this->invalid_price_pair( $pricing_context->get_error_message() );
			}
			if ( ! class_exists( 'Mobo_Core_Money_Price_Input_Plan' ) ) {
				return $this->invalid_price_pair( 'Price input currency conversion is unavailable.' );
			}
			$plan = Mobo_Core_Money_Price_Input_Plan::prepare(
				$price, $compare_price, $pricing_context['id'], $pricing_context['row'],
				$pricing_context['store'], $pricing_context['preference'], $pricing_context['digits']
			);
			if ( empty( $plan['ok'] ) ) {
				return $this->invalid_price_pair( 'Price input conversion rejected: ' . sanitize_key( (string) ( $plan['code'] ?? 'unknown' ) ) );
			}
			// Source amounts and their postmeta are not changed. All downstream fixed
			// margins/thresholds are evaluated in WooCommerce store currency.
			$store_price = (string) $plan['price'];
			$store_compare = $plan['comparePrice'];
			$pair = $this->calculate_prevalidated_price_pair(
				$object_id, $store_price, $store_compare, $context, $parent_id,
				(float) $store_price, null === $store_compare ? null : (float) $store_compare
			);
			if ( empty( $pair['error'] ) ) {
				$pair['money_source_unit'] = (string) $plan['sourceUnit'];
				$pair['money_store_unit'] = (string) $plan['targetUnit'];
				$pair['money_converted_once'] = true;
			}
			return $pair;
		}
		$price_valid = Mobo_Core_Money_Policy::validate_source_amount( $price );
		if ( is_wp_error( $price_valid ) ) {
			return $this->invalid_price_pair( $price_valid->get_error_message() );
		}
		$normalized_price = Mobo_Core_Money_Policy::normalize_source_number( $price );
		if ( null === $normalized_price ) {
			return $this->invalid_price_pair( 'API price is present but is not a valid numeric value.' );
		}

		$normalized_compare_price = null;
		if ( null !== $compare_price && '' !== $compare_price ) {
			$compare_valid = Mobo_Core_Money_Policy::validate_source_amount( $compare_price );
			if ( is_wp_error( $compare_valid ) ) {
				return $this->invalid_price_pair( $compare_valid->get_error_message() );
			}
			$normalized_compare_price = Mobo_Core_Money_Policy::normalize_source_number( $compare_price );
			if ( null === $normalized_compare_price ) {
				return $this->invalid_price_pair( 'API comparePrice is present but is not a valid numeric value.' );
			}
		}

        return $this->calculate_prevalidated_price_pair(
            $object_id, $price, $compare_price, $context, $parent_id,
            $normalized_price, $normalized_compare_price
        );
    }

    /**
     * STAGED ONLY: preview the legacy markup and comparePrice calculation using
     * exact, owner-verified store-currency INPUTS. This is NOT invoked from Sync,
     * Reprice, Shipping, Checkout, or any hook. V1 calculate_price_pair() keeps
     * its original strict mismatch gate until all financial boundaries are proven.
     */
    public function preview_converted_price_pair( $object_id, $price, $compare_price,
        $context, $parent_id, $owner_connection_id, $connection_row,
        $woo_currency, $target_preference, $woo_digits ) {
        if ( ! class_exists( 'Mobo_Core_Money_Price_Input_Plan' ) ) {
            return $this->invalid_price_pair( 'Conversion input planner is unavailable.' );
        }
        $plan = Mobo_Core_Money_Price_Input_Plan::prepare(
            $price, $compare_price, $owner_connection_id, $connection_row,
            $woo_currency, $target_preference, $woo_digits
        );
        if ( empty( $plan['ok'] ) || ! empty( $plan['runtimeEnabled'] ) ) {
            return $this->invalid_price_pair( 'Staged conversion input rejected: ' . (string) ( $plan['code'] ?? 'unknown' ) );
        }
        // Values are canonical integer strings from the exact converter.
        // The existing profit/threshold algorithm expects WooCommerce units.
        $store_price = (string) $plan['price'];
        $store_compare = $plan['comparePrice'];
        $preview = $this->calculate_prevalidated_price_pair(
            $object_id, $store_price, $store_compare, $context, $parent_id,
            (float) $store_price, null === $store_compare ? null : (float) $store_compare
        );
        $preview['preview_only'] = true;
        $preview['source_unit'] = (string) $plan['sourceUnit'];
        $preview['store_unit'] = (string) $plan['targetUnit'];
        $preview['runtime_enabled'] = false;
        return $preview;
    }

    /** Reuse the canonical pricing algorithm; no duplicated markup rules. */
    private function calculate_prevalidated_price_pair( $object_id, $price, $compare_price,
        $context, $parent_id, $normalized_price, $normalized_compare_price ) {
		$options = $this->rules->get_options();

		$auto_compare = isset( $options['global_product_auto_compare_price'] )
			? (string) $options['global_product_auto_compare_price']
			: '0';

		$base_price   = intval( $normalized_price );
		$regular_base = $base_price;
		$sale_base    = '';

		if ( null !== $compare_price && '' !== $compare_price && '1' === $auto_compare ) {
			/*
			 * WooCommerce requires a sale price to be strictly lower than the
			 * regular price. A source comparePrice that is equal to or below the
			 * source price is not a valid sale reference; treating it as regular
			 * would produce sale >= regular and WooCommerce will normalize the sale
			 * away during save, making our durable postcondition impossible.
			 *
			 * Fail-safe interpretation: only a strictly higher comparePrice creates
			 * a sale pair. Otherwise the source price remains the ordinary regular/
			 * active price and sale is empty.
			 */
			if ( $normalized_compare_price > $normalized_price ) {
				$regular_base = intval( $normalized_compare_price );
				$sale_base    = $base_price;
			}
		}

		$product_override = class_exists( 'Mobo_Core_Product_Pricing_Policy' )
			? Mobo_Core_Product_Pricing_Policy::resolve_for_object( $object_id, $parent_id, $context )
			: array( 'enabled' => false );
		if ( ! empty( $product_override['error'] ) ) {
			return $this->invalid_price_pair( 'Mobo product pricing override identity is ambiguous: ' . sanitize_text_field( (string) $product_override['error'] ) );
		}

		/* A variation/object-specific fixed profit is the strongest valid pricing
		 * intent. Identity/ownership ambiguity still fails closed before any price
		 * mutation, but this value wins over parent and global pricing rules. */
		$additional_price = $this->get_object_additional_price( $object_id );

		if ( null !== $additional_price ) {
			$regular_price = intval( $regular_base ) + $additional_price;
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

		/* Parent-level custom pricing is below a variation-specific fixed profit, but
		 * above the global pricing policy. */
		if ( ! empty( $product_override['enabled'] ) ) {
			$percentage = isset( $product_override['value'] ) && is_numeric( $product_override['value'] ) ? (float) $product_override['value'] : 0.0;
			$factor = 1 + ( $percentage / 100 );
			$regular_price = (float) $regular_base * $factor;
			$sale_price = '';
			if ( '' !== $sale_base ) {
				$sale_price = (float) $sale_base * $factor;
			}
			return $this->format_pair(
				$regular_price,
				$sale_price,
				$price,
				$compare_price,
				$options,
				'product-percentage-override',
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
	 * @param int        $regular_base Regular base.
	 * @param int|string $sale_base Sale base or empty.
	 * @param mixed      $raw_price Raw API price.
	 * @param mixed      $raw_compare_price Raw API compare price.
	 * @param array      $options Options.
	 * @param string     $context Context.
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
	 * Static percentage:
	 * regular/sale * (1 + percentage / 100)
	 *
	 * Examples:
	 * 20  => 1.20
	 * 50  => 1.50
	 * 100 => 2.00
	 *
	 * @param int        $regular_base Regular base.
	 * @param int|string $sale_base Sale base or empty.
	 * @param mixed      $raw_price Raw API price.
	 * @param mixed      $raw_compare_price Raw API compare price.
	 * @param array      $options Options.
	 * @param string     $context Context.
	 * @return array
	 */
	private function calculate_static_percentage_pair( $regular_base, $sale_base, $raw_price, $raw_compare_price, $options, $context ) {
		$percentage = isset( $options['global_additional_percentage'] )
			? wc_format_decimal( $options['global_additional_percentage'] )
			: '0';

		$percentage = is_numeric( $percentage ) ? (float) $percentage : 0.0;

		if ( $percentage < 0 ) {
			$percentage = 0.0;
		}

		$factor = 1 + ( $percentage / 100 );

		$regular_price = (float) $regular_base * $factor;
		$sale_price    = '';

		if ( '' !== $sale_base ) {
			$sale_price = (float) $sale_base * $factor;
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
	 * First active matching condition wins.
	 *
	 * mobo_dynamic_price JSON shape:
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
	 * benefit_type:
	 * - static     => + benefit
	 * - percentage => * floatval('1.' . benefit)
	 *
	 * @param int        $regular_base Regular base.
	 * @param int|string $sale_base Sale base or empty.
	 * @param mixed      $raw_price Raw API price.
	 * @param mixed      $raw_compare_price Raw API compare price.
	 * @param array      $options Options.
	 * @param string     $context Context.
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

			$is_active = isset( $condition['is_active'] )
				? sanitize_text_field( (string) $condition['is_active'] )
				: 'false';

			$low  = isset( $condition['low'] ) ? intval( $condition['low'] ) : 0;
			$high = isset( $condition['high'] ) ? intval( $condition['high'] ) : 0;

			if ( 'true' !== $is_active ) {
				continue;
			}

			/*
			 * A high value of 0 means "no upper limit".
			 * Dynamic rows are inclusive, so the next row should start from previous high + 1.
			 */
			if ( $api_price < $low || ( $high > 0 && $api_price > $high ) ) {
				continue;
			}

			$benefit_type = isset( $condition['benefit_type'] )
				? sanitize_key( (string) $condition['benefit_type'] )
				: 'static';

			$benefit_raw = isset( $condition['benefit'] ) ? (string) $condition['benefit'] : '0';
			$benefit = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $benefit_raw, false ) : trim( $benefit_raw );
			$benefit_value = is_numeric( $benefit ) ? max( 0, (float) $benefit ) : 0.0;

			if ( 'static' === $benefit_type ) {
				$regular_price = (float) $regular_base + $benefit_value;

				if ( '' !== $sale_base ) {
					$sale_price = (float) $sale_base + $benefit_value;
				}
			} else {
				$factor = 1 + ( $benefit_value / 100 );

				$regular_price = (float) $regular_base * $factor;

				if ( '' !== $sale_base ) {
					$sale_price = (float) $sale_base * $factor;
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
	 * Legacy meta:
	 * mobo_additional_price
	 *
	 * If this value exists and is greater than zero,
	 * global pricing rules must not be applied.
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

		$value = absint( $value );

		if ( $value <= 0 ) {
			return null;
		}

		return $value;
	}

	/**
	 * Format and filter price pair.
	 *
	 * @param mixed  $regular_price Regular price.
	 * @param mixed  $sale_price Sale price or empty.
	 * @param mixed  $raw_price Raw API price.
	 * @param mixed  $raw_compare_price Raw compare price.
	 * @param array  $options Legacy options.
	 * @param string $price_type Applied price type.
	 * @param string $context Context.
	 * @return array
	 */
	private function format_pair( $regular_price, $sale_price, $raw_price, $raw_compare_price, $options, $price_type, $context ) {
		$regular_price = is_numeric( $regular_price ) ? max( 0, (float) $regular_price ) : null;
		$sale_price    = is_numeric( $sale_price ) ? max( 0, (float) $sale_price ) : '';

		$pair = array(
			'regular_price' => null === $regular_price ? null : wc_format_decimal( $regular_price ),
			'sale_price'    => '' === $sale_price ? '' : wc_format_decimal( $sale_price ),
			'error'         => '',
			'policy_type'   => sanitize_key( (string) $price_type ),
		);

		/**
		 * Final compatibility filter.
		 *
		 * @param array  $pair Pair with regular_price and sale_price.
		 * @param mixed  $raw_price Raw API price.
		 * @param mixed  $raw_compare_price Raw API compare price.
		 * @param array  $options Legacy options.
		 * @param string $price_type Applied price type.
		 * @param string $context Context.
		 */
		$filtered_pair = apply_filters(
			'mobo_core_calculated_price_pair',
			$pair,
			$raw_price,
			$raw_compare_price,
			$options,
			$price_type,
			$context
		);

		$final_pair = ! is_array( $filtered_pair )
			? $pair
			: array(
				'regular_price' => array_key_exists( 'regular_price', $filtered_pair ) ? $filtered_pair['regular_price'] : $pair['regular_price'],
				'sale_price'    => array_key_exists( 'sale_price', $filtered_pair ) ? $filtered_pair['sale_price'] : $pair['sale_price'],
				'error'         => isset( $filtered_pair['error'] ) ? sanitize_text_field( (string) $filtered_pair['error'] ) : '',
				'policy_type'   => $pair['policy_type'],
			);

		/*
		 * Compatibility filters are allowed to modify the pair, but the final pair
		 * must still satisfy WooCommerce's durable sale invariant.
		 */
		if ( empty( $final_pair['error'] )
			&& '' !== (string) $final_pair['sale_price']
			&& null !== $final_pair['regular_price']
			&& is_numeric( $final_pair['sale_price'] )
			&& is_numeric( $final_pair['regular_price'] )
			&& (float) $final_pair['sale_price'] >= (float) $final_pair['regular_price']
		) {
			$final_pair['error'] = 'Calculated WooCommerce sale price must be strictly lower than regular price.';
		}

		return $final_pair;
	}

	/**
	 * Normalize a source numeric field without allowing arbitrary text to become 0.
	 *
	 * @param mixed $value Source numeric value.
	 * @return float|null
	 */
	private function normalize_source_number( $value ) {
		return class_exists( 'Mobo_Core_Money_Policy' )
			? Mobo_Core_Money_Policy::normalize_source_number( $value )
			: null;
	}

	/** @return array */
	private function invalid_price_pair( $message ) {
		return array(
			'regular_price' => null,
			'sale_price'    => '',
			'error'         => sanitize_text_field( (string) $message ),
		);
	}
}