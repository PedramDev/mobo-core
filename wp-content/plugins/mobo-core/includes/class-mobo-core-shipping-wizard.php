<?php
/**
 * Guided fulfillment setup for Mobo/non-Mobo mixed WooCommerce carts.
 *
 * The wizard stores one explicit fulfillment profile and then the runtime keeps
 * WooCommerce packages/rates consistent with that decision:
 *
 * - tehran_consolidated: mixed carts stay one customer shipment; Mobo items are
 *   fulfilled internally with the configured Mobo pickup method.
 * - split_shipments: Mobo and store items become two shipping packages.
 * - block_mixed: mixed carts are rejected with a manager-defined message.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Shipping_Wizard {

	const OPTION_COMPLETED              = 'mobo_core_shipping_wizard_completed';
	const OPTION_PROFILE                = 'mobo_core_shipping_fulfillment_profile';
	const OPTION_STORE_LOCATION         = 'mobo_core_shipping_store_location_mode';
	const OPTION_MIXED_FULFILLMENT_ID   = 'mobo_core_shipping_mixed_fulfillment_shipping_id';
	const OPTION_BLOCK_MESSAGE          = 'mobo_core_shipping_mixed_block_message';
	const OPTION_STORE_RATE_MODE        = 'mobo_core_shipping_store_rate_mode';
	const OPTION_STORE_RATE_TITLE       = 'mobo_core_shipping_store_rate_title';
	const OPTION_STORE_RATE_COST        = 'mobo_core_shipping_store_rate_cost';
	const OPTION_LAST_APPLIED_AT        = 'mobo_core_shipping_wizard_last_applied_at';
	const OPTION_LAST_RESULT            = 'mobo_core_shipping_wizard_last_result';

	const PROFILE_TEHRAN_CONSOLIDATED = 'tehran_consolidated';
	const PROFILE_SPLIT_SHIPMENTS     = 'split_shipments';
	const PROFILE_BLOCK_MIXED         = 'block_mixed';

	/**
	 * Register cart/checkout/order hooks after an administrator completed the wizard.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_completed() ) {
			return;
		}

		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'configure_shipping_packages' ), 5, 1 );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 100, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_mixed_cart' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 20, 2 );
		add_filter( 'woocommerce_store_api_cart_errors', array( $this, 'validate_store_api_cart' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'annotate_created_order' ), 20, 1 );
	}

	/**
	 * Apply wizard answers, run the idempotent shipping installer, and persist the profile.
	 *
	 * @param array  $input Raw wizard input.
	 * @param string $source Run source.
	 * @return array
	 */
	public function apply( $input, $source = 'admin-wizard' ) {
		$result = array(
			'success'       => false,
			'profile'       => '',
			'storeLocation' => '',
			'fulfillmentId' => 0,
			'storeRateMode' => '',
			'storeRateTitle'=> '',
			'storeRateCost' => '0',
			'message'       => '',
			'installer'     => array(),
			'warnings'      => array(),
		);

		$config = $this->sanitize_config( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $config ) ) {
			$result['message'] = $config->get_error_message();
			return $this->store_result( $result );
		}

		if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) || ! class_exists( 'Mobo_Core_Automatic_Shipping' ) ) {
			$result['message'] = 'ماژول‌های حمل‌ونقل موبو در دسترس نیستند.';
			return $this->store_result( $result );
		}

		$remote = new Mobo_Core_Remote_Shipping_Methods();
		$sync   = $remote->sync_now( 'shipping-wizard', true );
		if ( empty( $sync['success'] ) ) {
			$result['message'] = isset( $sync['message'] ) ? sanitize_text_field( (string) $sync['message'] ) : 'دریافت روش‌های ارسال موبو ناموفق بود.';
			return $this->store_result( $result );
		}

		$methods = $remote->get_methods();
		if ( empty( $methods ) ) {
			$result['message'] = 'هیچ روش ارسال فعال موبو دریافت نشد.';
			return $this->store_result( $result );
		}

		if ( self::PROFILE_TEHRAN_CONSOLIDATED === $config['profile'] && 'tehran' !== $config['storeLocation'] ) {
			$result['message'] = 'پروفایل تجمیع فقط برای فروشگاه یا انبار مستقر در تهران قابل استفاده است.';
			return $this->store_result( $result );
		}

		if ( self::PROFILE_TEHRAN_CONSOLIDATED === $config['profile'] ) {
			$fulfillment_id = absint( $config['fulfillmentId'] );
			if ( $fulfillment_id <= 0 ) {
				$fulfillment_id = $this->find_default_pickup_method_id( $methods );
			}
			if ( $fulfillment_id <= 0 || ! $this->method_is_pickup( $methods, $fulfillment_id ) ) {
				$result['message'] = 'برای تجمیع سفارش، روش «تحویل حضوری» معتبر در روش‌های ارسال موبو پیدا نشد.';
				return $this->store_result( $result );
			}
			$config['fulfillmentId'] = $fulfillment_id;
		}

		$automatic = new Mobo_Core_Automatic_Shipping();
		$installer = $automatic->install_or_repair(
			sanitize_key( (string) $source ),
			array(
				'mode'  => $config['storeRateMode'],
				'title' => $config['storeRateTitle'],
				'cost'  => $config['storeRateCost'],
			)
		);
		$result['installer'] = $installer;
		if ( empty( $installer['success'] ) ) {
			$result['message'] = isset( $installer['message'] ) ? sanitize_text_field( (string) $installer['message'] ) : 'ساخت روش‌های حمل‌ونقل ووکامرس ناموفق بود.';
			return $this->store_result( $result );
		}

		update_option( self::OPTION_PROFILE, $config['profile'], false );
		update_option( self::OPTION_STORE_LOCATION, $config['storeLocation'], false );
		update_option( self::OPTION_MIXED_FULFILLMENT_ID, absint( $config['fulfillmentId'] ), false );
		update_option( self::OPTION_BLOCK_MESSAGE, $config['blockMessage'], false );
		update_option( self::OPTION_STORE_RATE_MODE, $config['storeRateMode'], false );
		update_option( self::OPTION_STORE_RATE_TITLE, $config['storeRateTitle'], false );
		update_option( self::OPTION_STORE_RATE_COST, $config['storeRateCost'], false );
		update_option( self::OPTION_COMPLETED, '1', false );
		update_option( self::OPTION_LAST_APPLIED_AT, time(), false );

		$result['success']       = true;
		$result['profile']       = $config['profile'];
		$result['storeLocation'] = $config['storeLocation'];
		$result['fulfillmentId'] = absint( $config['fulfillmentId'] );
		$result['storeRateMode'] = $config['storeRateMode'];
		$result['storeRateTitle']= $config['storeRateTitle'];
		$result['storeRateCost'] = $config['storeRateCost'];
		$result['warnings']      = isset( $installer['warnings'] ) && is_array( $installer['warnings'] ) ? $installer['warnings'] : array();
		$result['message']       = $this->get_success_message( $config, $installer );

		return $this->store_result( $result );
	}

	/**
	 * Split or mark WooCommerce packages according to the selected profile.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function configure_shipping_packages( $packages ) {
		if ( ! is_array( $packages ) || empty( $packages ) ) {
			return $packages;
		}

		$profile = $this->get_profile();
		$output  = array();

		foreach ( $packages as $package ) {
			if ( ! is_array( $package ) ) {
				$output[] = $package;
				continue;
			}

			$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
			$groups   = $this->partition_contents( $contents );

			if ( self::PROFILE_SPLIT_SHIPMENTS === $profile && ! empty( $groups['mobo'] ) && ! empty( $groups['store'] ) ) {
				$mobo_package = $this->build_partitioned_package( $package, $groups['mobo'], 'mobo' );
				$store_package = $this->build_partitioned_package( $package, $groups['store'], 'store' );

				/* Mobo package first so order shipping resolution can prefer its explicit shipping_id. */
				$output[] = $mobo_package;
				$output[] = $store_package;
				continue;
			}

			$package['mobo_core_package_type'] = $this->package_type_from_groups( $groups );
			$package['mobo_core_fulfillment_profile'] = $profile;
			$output[] = $package;
		}

		return array_values( $output );
	}

	/**
	 * Keep Mobo rates and store rates on the correct package only.
	 *
	 * @param array $rates Calculated rates.
	 * @param array $package Package.
	 * @return array
	 */
	public function filter_package_rates( $rates, $package ) {
		if ( ! is_array( $rates ) ) {
			return $rates;
		}

		$type = isset( $package['mobo_core_package_type'] ) ? sanitize_key( (string) $package['mobo_core_package_type'] ) : '';
		if ( '' === $type ) {
			$groups = $this->partition_contents( isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array() );
			$type   = $this->package_type_from_groups( $groups );
		}

		$profile = $this->get_profile();
		$filtered = array();

		foreach ( $rates as $rate_key => $rate ) {
			$is_mobo_rate = $this->is_mobo_rate( $rate_key, $rate );
			$keep         = true;

			if ( 'mobo' === $type ) {
				$keep = $is_mobo_rate;
			} elseif ( 'store' === $type ) {
				$keep = ! $is_mobo_rate;
			} elseif ( 'mixed' === $type && self::PROFILE_TEHRAN_CONSOLIDATED === $profile ) {
				/* Customer chooses the store's final-mile shipping; Mobo pickup is internal. */
				$keep = ! $is_mobo_rate;
			}

			if ( $keep ) {
				$filtered[ $rate_key ] = $rate;
			}
		}

		return $filtered;
	}

	/**
	 * Reject mixed carts when the protective profile is selected.
	 *
	 * @return void
	 */
	public function validate_mixed_cart() {
		if ( self::PROFILE_BLOCK_MIXED !== $this->get_profile() || ! $this->cart_is_mixed() || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		wc_add_notice( $this->get_block_message(), 'error' );
	}

	/**
	 * Checkout validation fallback for classic checkout and integrations.
	 *
	 * @param array    $data Posted data.
	 * @param WP_Error $errors Errors.
	 * @return void
	 */
	public function validate_checkout( $data, $errors ) {
		unset( $data );
		if ( self::PROFILE_BLOCK_MIXED !== $this->get_profile() || ! $this->cart_is_mixed() || ! is_wp_error( $errors ) ) {
			return;
		}

		$errors->add( 'mobo_core_mixed_cart_blocked', $this->get_block_message() );
	}

	/**
	 * Store API / Checkout Block validation fallback.
	 *
	 * @param WP_Error $errors Cart errors.
	 * @return WP_Error
	 */
	public function validate_store_api_cart( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			$errors = new WP_Error();
		}
		if ( self::PROFILE_BLOCK_MIXED === $this->get_profile() && $this->cart_is_mixed() ) {
			$errors->add( 'mobo_core_mixed_cart_blocked', $this->get_block_message() );
		}
		return $errors;
	}

	/**
	 * Save fulfillment context and an operational note on created mixed orders.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function annotate_created_order( $order ) {
		if ( ! $order instanceof WC_Order || ! $this->order_is_mixed( $order ) ) {
			return;
		}

		$profile = $this->get_profile();
		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_mobo_shipping_fulfillment_profile', $profile );
			if ( self::PROFILE_TEHRAN_CONSOLIDATED === $profile ) {
				$order->update_meta_data( '_mobo_mixed_fulfillment_shipping_id', $this->get_mixed_fulfillment_shipping_id() );
			}
		}

		if ( method_exists( $order, 'add_order_note' ) ) {
			if ( self::PROFILE_TEHRAN_CONSOLIDATED === $profile ) {
				$order->add_order_note( 'سفارش ترکیبی: اقلام موبو با تحویل حضوری دریافت و همراه اقلام فروشگاه در یک بسته برای مشتری ارسال شوند.' );
			} elseif ( self::PROFILE_SPLIT_SHIPMENTS === $profile ) {
				$order->add_order_note( 'سفارش ترکیبی: اقلام موبو و اقلام فروشگاه در دو بسته مستقل ارسال می‌شوند.' );
			}
		}

		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Wizard status for administrator UI and diagnostics.
	 *
	 * @return array
	 */
	public function get_status() {
		$defaults = $this->get_defaults();
		$profile  = $this->get_profile();
		$labels   = $this->get_profile_labels();

		return array(
			'completed'       => $this->is_completed(),
			'profile'         => $profile,
			'profileLabel'    => isset( $labels[ $profile ] ) ? $labels[ $profile ] : $profile,
			'storeLocation'   => sanitize_key( (string) get_option( self::OPTION_STORE_LOCATION, $defaults['storeLocation'] ) ),
			'fulfillmentId'   => $this->get_mixed_fulfillment_shipping_id(),
			'blockMessage'    => $this->get_block_message(),
			'storeRateMode'   => sanitize_key( (string) get_option( self::OPTION_STORE_RATE_MODE, $defaults['storeRateMode'] ) ),
			'storeRateTitle'  => sanitize_text_field( (string) get_option( self::OPTION_STORE_RATE_TITLE, $defaults['storeRateTitle'] ) ),
			'storeRateCost'   => (string) get_option( self::OPTION_STORE_RATE_COST, $defaults['storeRateCost'] ),
			'storeShipping'   => isset( $defaults['storeShipping'] ) ? $defaults['storeShipping'] : array(),
			'lastAppliedAt'   => absint( get_option( self::OPTION_LAST_APPLIED_AT, 0 ) ),
			'lastResult'      => get_option( self::OPTION_LAST_RESULT, array() ),
			'inferredTehran'  => ! empty( $defaults['inferredTehran'] ),
			'defaultProfile'  => $defaults['profile'],
		);
	}

	/**
	 * Safe wizard defaults inferred from the WooCommerce store base address.
	 *
	 * @return array
	 */
	public function get_defaults() {
		$is_tehran = $this->store_is_tehran();
		$store_shipping = array( 'ready' => false, 'existingReady' => false, 'zoneCount' => 0, 'activeMethodCount' => 0, 'existingMethodCount' => 0, 'missingZones' => array(), 'existingMissingZones' => array() );
		if ( class_exists( 'Mobo_Core_Automatic_Shipping' ) ) {
			$automatic = new Mobo_Core_Automatic_Shipping();
			if ( method_exists( $automatic, 'inspect_store_shipping' ) ) {
				$store_shipping = $automatic->inspect_store_shipping();
			}
		}

		$saved_mode = sanitize_key( (string) get_option( self::OPTION_STORE_RATE_MODE, '' ) );
		$allowed_modes = array( Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_EXISTING, Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_ENSURE_FLAT_RATE );
		if ( ! in_array( $saved_mode, $allowed_modes, true ) ) {
			$saved_mode = ! empty( $store_shipping['existingReady'] ) ? Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_EXISTING : Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_ENSURE_FLAT_RATE;
		}

		return array(
			'storeLocation'  => $is_tehran ? 'tehran' : 'outside_tehran',
			'profile'        => $is_tehran ? self::PROFILE_TEHRAN_CONSOLIDATED : self::PROFILE_SPLIT_SHIPMENTS,
			'fulfillmentId'  => absint( get_option( self::OPTION_MIXED_FULFILLMENT_ID, 0 ) ),
			'blockMessage'   => $this->default_block_message(),
			'storeRateMode'  => $saved_mode,
			'storeRateTitle' => sanitize_text_field( (string) get_option( self::OPTION_STORE_RATE_TITLE, 'ارسال محصولات فروشگاه' ) ),
			'storeRateCost'  => (string) get_option( self::OPTION_STORE_RATE_COST, '0' ),
			'storeShipping'  => $store_shipping,
			'inferredTehran' => $is_tehran,
		);
	}

	/**
	 * Profile labels.
	 *
	 * @return array
	 */
	public function get_profile_labels() {
		return array(
			self::PROFILE_TEHRAN_CONSOLIDATED => 'تجمیع در فروشگاه تهران',
			self::PROFILE_SPLIT_SHIPMENTS     => 'ارسال در دو بسته مستقل',
			self::PROFILE_BLOCK_MIXED         => 'جلوگیری از سفارش ترکیبی',
		);
	}

	/**
	 * Current profile.
	 *
	 * @return string
	 */
	public function get_profile() {
		$defaults = $this->get_defaults();
		$profile  = sanitize_key( (string) get_option( self::OPTION_PROFILE, $defaults['profile'] ) );
		return in_array( $profile, array_keys( $this->get_profile_labels() ), true ) ? $profile : $defaults['profile'];
	}

	/**
	 * Whether wizard has been applied at least once.
	 *
	 * @return bool
	 */
	public function is_completed() {
		return '1' === (string) get_option( self::OPTION_COMPLETED, '0' );
	}

	/**
	 * Internal Mobo shipping ID used for mixed consolidated orders.
	 *
	 * @return int
	 */
	public function get_mixed_fulfillment_shipping_id() {
		return absint( get_option( self::OPTION_MIXED_FULFILLMENT_ID, 0 ) );
	}

	/**
	 * Is mixed order shipping resolved internally instead of from customer rate mapping?
	 *
	 * @return bool
	 */
	public function uses_internal_mixed_fulfillment() {
		return $this->is_completed() && self::PROFILE_TEHRAN_CONSOLIDATED === $this->get_profile() && $this->get_mixed_fulfillment_shipping_id() > 0;
	}

	/**
	 * Sanitize wizard configuration.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private function sanitize_config( $input ) {
		$defaults = $this->get_defaults();
		$profile  = isset( $input['profile'] ) ? sanitize_key( (string) $input['profile'] ) : $defaults['profile'];
		$location = isset( $input['storeLocation'] ) ? sanitize_key( (string) $input['storeLocation'] ) : $defaults['storeLocation'];

		if ( ! in_array( $profile, array_keys( $this->get_profile_labels() ), true ) ) {
			return new WP_Error( 'mobo_shipping_wizard_profile_invalid', 'سناریوی ارسال ترکیبی معتبر نیست.' );
		}
		if ( ! in_array( $location, array( 'tehran', 'outside_tehran', 'multi_location' ), true ) ) {
			$location = $defaults['storeLocation'];
		}

		$block_message = isset( $input['blockMessage'] ) ? sanitize_textarea_field( (string) $input['blockMessage'] ) : $defaults['blockMessage'];
		if ( '' === trim( $block_message ) ) {
			$block_message = $defaults['blockMessage'];
		}

		$fulfillment_id = isset( $input['fulfillmentId'] ) ? absint( $input['fulfillmentId'] ) : absint( $defaults['fulfillmentId'] );
		if ( self::PROFILE_TEHRAN_CONSOLIDATED !== $profile ) {
			$fulfillment_id = 0;
		}

		$store_rate_mode = isset( $input['storeRateMode'] ) ? sanitize_key( (string) $input['storeRateMode'] ) : $defaults['storeRateMode'];
		$allowed_store_modes = array( Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_EXISTING, Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_ENSURE_FLAT_RATE );
		if ( ! in_array( $store_rate_mode, $allowed_store_modes, true ) ) {
			return new WP_Error( 'mobo_shipping_wizard_store_rate_mode_invalid', 'روش ارسال محصولات غیرموبویی معتبر نیست.' );
		}
		if ( Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_EXISTING === $store_rate_mode && empty( $defaults['storeShipping']['existingReady'] ) ) {
			return new WP_Error( 'mobo_shipping_wizard_store_rate_missing', 'برای محصولات غیرموبویی در Zoneهای ایران روش ارسال عادی فعالی وجود ندارد. گزینه ساخت Flat Rate پشتیبان را انتخاب کنید.' );
		}

		$store_rate_title = isset( $input['storeRateTitle'] ) ? sanitize_text_field( (string) $input['storeRateTitle'] ) : $defaults['storeRateTitle'];
		if ( '' === trim( $store_rate_title ) ) {
			$store_rate_title = 'ارسال محصولات فروشگاه';
		}
		$store_rate_cost_raw = isset( $input['storeRateCost'] ) ? (string) $input['storeRateCost'] : $defaults['storeRateCost'];
		$store_rate_cost_raw = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $store_rate_cost_raw ) : preg_replace( '/[^0-9.\-]/', '', $store_rate_cost_raw );
		$store_rate_cost = is_numeric( $store_rate_cost_raw ) ? max( 0, (float) $store_rate_cost_raw ) : 0.0;
		$store_rate_cost = function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $store_rate_cost, false, true ) : rtrim( rtrim( number_format( $store_rate_cost, 4, '.', '' ), '0' ), '.' );

		return array(
			'profile'        => $profile,
			'storeLocation'  => $location,
			'fulfillmentId'  => $fulfillment_id,
			'blockMessage'   => $block_message,
			'storeRateMode'  => $store_rate_mode,
			'storeRateTitle' => $store_rate_title,
			'storeRateCost'  => $store_rate_cost,
		);
	}

	/**
	 * Determine whether the configured WooCommerce base address is Tehran.
	 *
	 * @return bool
	 */
	private function store_is_tehran() {
		$country_state = (string) get_option( 'woocommerce_default_country', '' );
		$city          = (string) get_option( 'woocommerce_store_city', '' );
		$state         = $country_state;
		if ( false !== strpos( $country_state, ':' ) ) {
			$parts = explode( ':', $country_state, 2 );
			$state = isset( $parts[1] ) ? $parts[1] : '';
		}

		$haystack = $this->normalize_text( $state . ' ' . $city );
		if ( false !== strpos( $haystack, 'تهران' ) || false !== strpos( $haystack, 'tehran' ) ) {
			return true;
		}

		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && method_exists( WC()->countries, 'get_states' ) ) {
			$states = WC()->countries->get_states( 'IR' );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) {
				$name = $this->normalize_text( $states[ $state ] );
				return false !== strpos( $name, 'تهران' ) || false !== strpos( $name, 'tehran' );
			}
		}

		return false;
	}

	/**
	 * Find the approved Mobo pickup method from current payload.
	 *
	 * @param array $methods Methods.
	 * @return int
	 */
	public function find_default_pickup_method_id( $methods ) {
		foreach ( is_array( $methods ) ? $methods : array() as $method ) {
			$title = $this->normalize_text( isset( $method['title'] ) ? $method['title'] : '' );
			if ( false !== strpos( $title, 'تحویل حضوری' ) ) {
				return absint( isset( $method['id'] ) ? $method['id'] : 0 );
			}
		}
		return 0;
	}

	private function method_is_pickup( $methods, $id ) {
		foreach ( is_array( $methods ) ? $methods : array() as $method ) {
			if ( absint( isset( $method['id'] ) ? $method['id'] : 0 ) !== absint( $id ) ) {
				continue;
			}
			$title = $this->normalize_text( isset( $method['title'] ) ? $method['title'] : '' );
			return false !== strpos( $title, 'تحویل حضوری' );
		}
		return false;
	}

	private function partition_contents( $contents ) {
		$groups = array( 'mobo' => array(), 'store' => array() );
		foreach ( is_array( $contents ) ? $contents : array() as $item_key => $item ) {
			if ( self::is_mobo_cart_item( $item ) ) {
				$groups['mobo'][ $item_key ] = $item;
			} else {
				$groups['store'][ $item_key ] = $item;
			}
		}
		return $groups;
	}

	private function build_partitioned_package( $package, $contents, $type ) {
		$package['contents'] = $contents;
		$package['contents_cost'] = 0.0;
		foreach ( $contents as $item ) {
			$package['contents_cost'] += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
		}
		$package['mobo_core_package_type'] = sanitize_key( (string) $type );
		$package['mobo_core_fulfillment_profile'] = self::PROFILE_SPLIT_SHIPMENTS;
		return $package;
	}

	private function package_type_from_groups( $groups ) {
		$has_mobo  = ! empty( $groups['mobo'] );
		$has_store = ! empty( $groups['store'] );
		if ( $has_mobo && $has_store ) {
			return 'mixed';
		}
		return $has_mobo ? 'mobo' : 'store';
	}

	private function is_mobo_rate( $rate_key, $rate ) {
		$method_id = '';
		if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
			$method_id = sanitize_key( (string) $rate->get_method_id() );
		} elseif ( is_object( $rate ) && isset( $rate->method_id ) ) {
			$method_id = sanitize_key( (string) $rate->method_id );
		}
		if ( '' === $method_id ) {
			$method_id = sanitize_key( strtok( (string) $rate_key, ':' ) );
		}
		return Mobo_Core_Automatic_Shipping::METHOD_ID === $method_id;
	}

	private function cart_is_mixed() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->cart ) || ! method_exists( WC()->cart, 'get_cart' ) ) {
			return false;
		}
		$groups = $this->partition_contents( WC()->cart->get_cart() );
		return ! empty( $groups['mobo'] ) && ! empty( $groups['store'] );
	}

	private function order_is_mixed( $order ) {
		$mobo = 0;
		$store = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$cart_item = array(
				'data'         => $product,
				'product_id'   => absint( $item->get_product_id() ),
				'variation_id' => absint( $item->get_variation_id() ),
			);
			if ( self::is_mobo_cart_item( $cart_item ) ) {
				$mobo++;
			} else {
				$store++;
			}
		}
		return $mobo > 0 && $store > 0;
	}

	private static function is_mobo_cart_item( $item ) {
		if ( ! is_array( $item ) ) {
			return false;
		}
		$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
		$ids = array_filter(
			array(
				isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0,
				isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				$product ? absint( $product->get_id() ) : 0,
			)
		);
		foreach ( array_values( array_unique( $ids ) ) as $id ) {
			if ( get_post_meta( $id, 'variant_guid', true ) || get_post_meta( $id, 'product_guid', true ) ) {
				return true;
			}
			if ( absint( get_post_meta( $id, 'portal_variant_id', true ) ) > 0 || absint( get_post_meta( $id, 'mobo_portal_variant_id', true ) ) > 0 || absint( get_post_meta( $id, '_mobo_portal_variant_id', true ) ) > 0 ) {
				return true;
			}
			if ( absint( get_post_meta( $id, 'portal_product_id', true ) ) > 0 || absint( get_post_meta( $id, 'mobo_portal_product_id', true ) ) > 0 || absint( get_post_meta( $id, '_mobo_portal_product_id', true ) ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	private function get_block_message() {
		$message = sanitize_text_field( (string) get_option( self::OPTION_BLOCK_MESSAGE, $this->default_block_message() ) );
		return '' !== $message ? $message : $this->default_block_message();
	}

	private function default_block_message() {
		return 'محصولات موبو و سایر محصولات فروشگاه باید در دو سفارش جداگانه ثبت شوند.';
	}

	private function get_success_message( $config, $installer ) {
		$labels = $this->get_profile_labels();
		$label  = isset( $labels[ $config['profile'] ] ) ? $labels[ $config['profile'] ] : $config['profile'];
		$store_label = Mobo_Core_Automatic_Shipping::STORE_RATE_MODE_ENSURE_FLAT_RATE === $config['storeRateMode'] ? 'Flat Rate پشتیبان' : 'روش‌های فعلی فروشگاه';
		$message = 'ویزارد حمل‌ونقل با سناریوی «' . $label . '» اعمال شد. ارسال محصولات غیرموبویی نیز با حالت «' . $store_label . '» کنترل شد.';
		if ( isset( $installer['message'] ) && '' !== trim( (string) $installer['message'] ) ) {
			$message .= ' ' . sanitize_text_field( (string) $installer['message'] );
		}
		return $message;
	}

	private function store_result( $result ) {
		update_option( self::OPTION_LAST_RESULT, $result, false );
		return $result;
	}

	private function normalize_text( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = str_replace( array( 'ي', 'ى', 'ك', '‌', '-' ), array( 'ی', 'ی', 'ک', ' ', ' ' ), $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
