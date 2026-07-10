<?php
/**
 * Read-only WooCommerce shipping diagnostics for checkout troubleshooting.
 *
 * This class intentionally does not change packages, destinations or rates.
 * It only stores the last checkout/shipping calculation context so admins can
 * see why WooCommerce returned no shipping methods.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Shipping_Diagnostics {

	const OPTION_LAST = 'mobo_core_shipping_diagnostics_last';

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout_update_order_review' ), 999, 1 );
		add_filter( 'woocommerce_package_rates', array( $this, 'capture_package_rates' ), 9999, 2 );
		add_filter( 'woocommerce_cart_no_shipping_available_html', array( $this, 'capture_cart_no_shipping_html' ), 9999, 1 );
		add_filter( 'woocommerce_no_shipping_available_html', array( $this, 'capture_no_shipping_html' ), 9999, 1 );
	}

	/**
	 * Return last report.
	 *
	 * @return array
	 */
	public function get_last_report() {
		$report = get_option( self::OPTION_LAST, array() );
		return is_array( $report ) ? $report : array();
	}

	/**
	 * Clear last report.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_LAST );
	}

	/**
	 * Capture serialized checkout update request.
	 *
	 * @param string $post_data Serialized checkout data.
	 * @return void
	 */
	public function capture_checkout_update_order_review( $post_data ) {
		$data = array();
		if ( is_string( $post_data ) && '' !== $post_data ) {
			parse_str( $post_data, $data );
		}

		$this->store_report(
			array(
				'event'          => 'checkout_update_order_review',
				'postedAddress'  => $this->extract_posted_address( $data ),
				'customer'       => $this->get_customer_destination(),
				'cart'           => $this->get_cart_summary(),
				'notices'        => $this->get_wc_notices_summary(),
				'settings'       => $this->get_mobo_checkout_settings(),
				'packageRateFilters' => $this->get_filter_callbacks_summary( 'woocommerce_package_rates' ),
				'packages'       => $this->get_current_packages_summary(),
			)
		);
	}

	/**
	 * Capture calculated rates. Read-only filter.
	 *
	 * @param array $rates Rates.
	 * @param array $package Package.
	 * @return array
	 */
	public function capture_package_rates( $rates, $package ) {
		$this->store_report(
			array(
				'event'          => 'package_rates',
				'customer'       => $this->get_customer_destination(),
				'cart'           => $this->get_cart_summary(),
				'notices'        => $this->get_wc_notices_summary(),
				'settings'       => $this->get_mobo_checkout_settings(),
				'packageRateFilters' => $this->get_filter_callbacks_summary( 'woocommerce_package_rates' ),
				'package'        => $this->summarize_package( is_array( $package ) ? $package : array(), $rates ),
			)
		);

		return $rates;
	}

	/**
	 * Capture WooCommerce no-shipping message context.
	 *
	 * @param string $html Notice HTML.
	 * @return string
	 */
	public function capture_cart_no_shipping_html( $html ) {
		$this->capture_no_shipping_context( 'cart_no_shipping_available_html' );
		return $html;
	}

	/**
	 * Capture WooCommerce no-shipping message context.
	 *
	 * @param string $html Notice HTML.
	 * @return string
	 */
	public function capture_no_shipping_html( $html ) {
		$this->capture_no_shipping_context( 'no_shipping_available_html' );
		return $html;
	}

	/**
	 * Capture no-shipping context.
	 *
	 * @param string $event Event name.
	 * @return void
	 */
	private function capture_no_shipping_context( $event ) {
		$this->store_report(
			array(
				'event'          => $event,
				'customer'       => $this->get_customer_destination(),
				'cart'           => $this->get_cart_summary(),
				'notices'        => $this->get_wc_notices_summary(),
				'settings'       => $this->get_mobo_checkout_settings(),
				'packageRateFilters' => $this->get_filter_callbacks_summary( 'woocommerce_package_rates' ),
				'packages'       => $this->get_current_packages_summary(),
			)
		);
	}

	/**
	 * Store compact report.
	 *
	 * @param array $report Report data.
	 * @return void
	 */
	private function store_report( $report ) {
		$report = is_array( $report ) ? $report : array();
		$report['capturedAt'] = time();
		$report['request']    = array(
			'isAjax'     => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
			'isCheckout' => function_exists( 'is_checkout' ) && is_checkout(),
			'isCart'     => function_exists( 'is_cart' ) && is_cart(),
		);

		update_option( self::OPTION_LAST, $this->sanitize_deep( $report ), false );
	}

	/**
	 * Extract relevant address values from checkout request.
	 *
	 * @param array $data Checkout data.
	 * @return array
	 */
	private function extract_posted_address( $data ) {
		$data = is_array( $data ) ? $data : array();
		$keys = array(
			'billing_country',
			'billing_state',
			'billing_city',
			'billing_postcode',
			'shipping_country',
			'shipping_state',
			'shipping_city',
			'shipping_postcode',
			'ship_to_different_address',
		);

		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		}

		return $out;
	}

	/**
	 * Get current WooCommerce customer shipping destination.
	 *
	 * @return array
	 */
	private function get_customer_destination() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return array();
		}

		$customer = WC()->customer;

		return array(
			'shipping_country'  => method_exists( $customer, 'get_shipping_country' ) ? (string) $customer->get_shipping_country() : '',
			'shipping_state'    => method_exists( $customer, 'get_shipping_state' ) ? (string) $customer->get_shipping_state() : '',
			'shipping_city'     => method_exists( $customer, 'get_shipping_city' ) ? (string) $customer->get_shipping_city() : '',
			'shipping_postcode' => method_exists( $customer, 'get_shipping_postcode' ) ? (string) $customer->get_shipping_postcode() : '',
			'billing_country'   => method_exists( $customer, 'get_billing_country' ) ? (string) $customer->get_billing_country() : '',
			'billing_state'     => method_exists( $customer, 'get_billing_state' ) ? (string) $customer->get_billing_state() : '',
			'billing_city'      => method_exists( $customer, 'get_billing_city' ) ? (string) $customer->get_billing_city() : '',
			'billing_postcode'  => method_exists( $customer, 'get_billing_postcode' ) ? (string) $customer->get_billing_postcode() : '',
		);
	}

	/**
	 * Get cart summary and shippable state.
	 *
	 * @return array
	 */

	/**
	 * Get current WooCommerce notices without changing them.
	 *
	 * @return array
	 */
	private function get_wc_notices_summary() {
		$out = array(
			'error'   => array(),
			'notice'  => array(),
			'success' => array(),
		);

		if ( ! function_exists( 'wc_get_notices' ) ) {
			return $out;
		}

		$notices = wc_get_notices();
		if ( ! is_array( $notices ) ) {
			return $out;
		}

		foreach ( $out as $type => $items ) {
			if ( empty( $notices[ $type ] ) || ! is_array( $notices[ $type ] ) ) {
				continue;
			}

			foreach ( $notices[ $type ] as $notice ) {
				if ( is_array( $notice ) && isset( $notice['notice'] ) ) {
					$message = $notice['notice'];
				} else {
					$message = $notice;
				}

				$message = wp_strip_all_tags( (string) $message );
				$message = trim( preg_replace( '/\s+/', ' ', $message ) );

				if ( '' !== $message ) {
					$out[ $type ][] = sanitize_text_field( $message );
				}
			}
		}

		return $out;
	}

	private function get_cart_summary() {
		$out = array(
			'needsShipping' => null,
			'itemCount'      => 0,
			'items'          => array(),
		);

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}

		$out['needsShipping'] = method_exists( WC()->cart, 'needs_shipping' ) ? (bool) WC()->cart->needs_shipping() : null;

		$cart = WC()->cart->get_cart();
		if ( ! is_array( $cart ) ) {
			return $out;
		}

		foreach ( $cart as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$out['items'][] = array(
				'productId'       => isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : absint( $product->get_id() ),
				'variationId'     => isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0,
				'name'            => wp_strip_all_tags( $product->get_name() ),
				'type'            => method_exists( $product, 'get_type' ) ? (string) $product->get_type() : '',
				'quantity'        => isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0,
				'virtual'         => method_exists( $product, 'is_virtual' ) ? (bool) $product->is_virtual() : null,
				'downloadable'    => method_exists( $product, 'is_downloadable' ) ? (bool) $product->is_downloadable() : null,
				'needsShipping'   => method_exists( $product, 'needs_shipping' ) ? (bool) $product->needs_shipping() : null,
				'shippingClassId' => method_exists( $product, 'get_shipping_class_id' ) ? absint( $product->get_shipping_class_id() ) : 0,
				'weight'          => method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '',
				'stockStatus'     => method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '',
			);
		}

		$out['itemCount'] = count( $out['items'] );

		return $out;
	}

	/**
	 * Get current packages from WC shipping object.
	 *
	 * @return array
	 */
	private function get_current_packages_summary() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping || ! method_exists( WC()->shipping, 'get_packages' ) ) {
			return array();
		}

		$packages = WC()->shipping()->get_packages();
		$out      = array();

		if ( ! is_array( $packages ) ) {
			return $out;
		}

		foreach ( $packages as $package ) {
			$out[] = $this->summarize_package( is_array( $package ) ? $package : array(), isset( $package['rates'] ) ? $package['rates'] : array() );
		}

		return $out;
	}

	/**
	 * Summarize shipping package.
	 *
	 * @param array $package Package.
	 * @param array $rates Rates.
	 * @return array
	 */
	private function summarize_package( $package, $rates = array() ) {
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		$contents    = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		$rate_ids    = array();

		if ( is_array( $rates ) ) {
			foreach ( $rates as $rate_id => $rate ) {
				$rate_ids[] = is_string( $rate_id ) ? $rate_id : ( is_object( $rate ) && method_exists( $rate, 'get_id' ) ? (string) $rate->get_id() : (string) $rate_id );
			}
		}

		$out = array(
			'destination'    => array(
				'country'  => isset( $destination['country'] ) ? (string) $destination['country'] : '',
				'state'    => isset( $destination['state'] ) ? (string) $destination['state'] : '',
				'city'     => isset( $destination['city'] ) ? (string) $destination['city'] : '',
				'postcode' => isset( $destination['postcode'] ) ? (string) $destination['postcode'] : '',
			),
			'contentsCount'  => count( $contents ),
			'ratesCount'     => count( $rate_ids ),
			'rateIds'        => $rate_ids,
			'matchingZone'   => $this->get_matching_zone_summary( $package ),
		);

		return $out;
	}

	/**
	 * Get matching shipping zone and enabled methods for a package.
	 *
	 * @param array $package Package.
	 * @return array
	 */
	private function get_matching_zone_summary( $package ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! is_array( $package ) ) {
			return array();
		}

		try {
			$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		}

		if ( ! is_object( $zone ) ) {
			return array();
		}

		$methods = array();
		if ( method_exists( $zone, 'get_shipping_methods' ) ) {
			$shipping_methods = $zone->get_shipping_methods( true );
			if ( is_array( $shipping_methods ) ) {
				foreach ( $shipping_methods as $method ) {
					if ( ! is_object( $method ) ) {
						continue;
					}
					$methods[] = array(
						'id'       => isset( $method->id ) ? (string) $method->id : '',
						'instance' => isset( $method->instance_id ) ? absint( $method->instance_id ) : 0,
						'title'    => isset( $method->title ) ? (string) $method->title : '',
						'enabled'  => isset( $method->enabled ) ? (string) $method->enabled : '',
						'options'  => $this->get_shipping_method_options_summary( $method ),
					);
				}
			}
		}

		return array(
			'id'      => method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0,
			'name'    => method_exists( $zone, 'get_zone_name' ) ? (string) $zone->get_zone_name() : '',
			'methods' => $methods,
		);
	}


	/**
	 * Summarize safe shipping method options that affect rate calculation.
	 *
	 * @param object $method Shipping method instance.
	 * @return array
	 */
	private function get_shipping_method_options_summary( $method ) {
		$out = array();
		if ( ! is_object( $method ) || ! method_exists( $method, 'get_option' ) ) {
			return $out;
		}

		$keys = array( 'cost', 'class_cost', 'no_class_cost', 'type', 'calculation_type', 'tax_status', 'min_amount', 'requires' );
		foreach ( $keys as $key ) {
			$value = $method->get_option( $key, null );
			if ( null !== $value && '' !== $value ) {
				$out[ $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}
		}

		if ( isset( $method->instance_settings ) && is_array( $method->instance_settings ) ) {
			foreach ( $method->instance_settings as $key => $value ) {
				$key = (string) $key;
				if ( 0 === strpos( $key, 'class_cost_' ) && '' !== (string) $value ) {
					$out[ $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
				}
			}
		}

		return $out;
	}

	/**
	 * Summarize callbacks attached to a filter hook.
	 *
	 * @param string $hook Hook name.
	 * @return array
	 */
	private function get_filter_callbacks_summary( $hook ) {
		global $wp_filter;

		$out = array();
		if ( empty( $hook ) || empty( $wp_filter[ $hook ] ) ) {
			return $out;
		}

		$filter = $wp_filter[ $hook ];
		$callbacks = isset( $filter->callbacks ) && is_array( $filter->callbacks ) ? $filter->callbacks : array();

		foreach ( $callbacks as $priority => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$function = isset( $item['function'] ) ? $item['function'] : null;
				$out[] = array(
					'priority' => absint( $priority ),
					'callback' => $this->callback_to_string( $function ),
				);
			}
		}

		return $out;
	}

	/**
	 * Convert callback to safe string.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private function callback_to_string( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $target . '::' . (string) $callback[1];
		}

		if ( $callback instanceof Closure ) {
			return 'Closure';
		}

		return is_object( $callback ) ? get_class( $callback ) : gettype( $callback );
	}

	/**
	 * Current Mobo checkout settings relevant to shipping ownership.
	 *
	 * @return array
	 */
	private function get_mobo_checkout_settings() {
		$master = class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' ) : false;
		$auto_order = class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '1' ) : false;
		$address_mapping_raw = class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::enabled( 'mobo_core_address_mapping_enabled', '1' ) : false;

		return array(
			'checkoutValidationMasterEnabled' => $master,
			'localStockCheckEnabled'          => $master && class_exists( 'Mobo_Core_Settings' ) && Mobo_Core_Settings::enabled( 'mobo_core_checkout_local_stock_check_enabled', '0' ),
			'moboCartValidationEnabled'       => $master && class_exists( 'Mobo_Core_Settings' ) && Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ),
			'autoOrderEnabled'                => $auto_order,
			'addressMappingEnabled'           => $address_mapping_raw,
			'addressMappingCheckoutActive'    => $auto_order && $address_mapping_raw,
		);
	}

	/**
	 * Sanitize data recursively for option storage.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ sanitize_key( (string) $key ) ] = $this->sanitize_deep( $item );
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}
}
