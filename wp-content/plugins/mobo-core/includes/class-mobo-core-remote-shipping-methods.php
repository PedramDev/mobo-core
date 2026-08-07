<?php
/**
 * Complete Mobo shipping contract and WooCommerce shipping context.
 *
 * The contract is cached for native automatic rates, administrator visibility,
 * and shipping_id mapping. A shipping-only package copy can use the Mobo API
 * price/class without changing catalog, cart, checkout, or order totals.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Remote_Shipping_Methods {

	const OPTION_SNAPSHOT       = 'mobo_core_remote_shipping_methods_snapshot';
	const OPTION_CHANGED_AT     = 'mobo_core_remote_shipping_methods_changed_at';
	const OPTION_LAST_ATTEMPT   = 'mobo_core_remote_shipping_methods_last_attempt_at';
	const OPTION_LAST_SUCCESS   = 'mobo_core_remote_shipping_methods_last_success_at';
	const OPTION_LAST_ERROR     = 'mobo_core_remote_shipping_methods_last_error';

	/**
	 * Bootstrap the optional shipping-only package context.
	 *
	 * Native Mobo rate registration is owned by Mobo_Core_Automatic_Shipping.
	 *
	 * @return void
	 */
	public function init() {
		// Mapping-only policy: the remote catalog never changes WooCommerce checkout packages or rates.
	}

	/**
	 * Sync remote methods from MoboCore if due.
	 *
	 * @param string $source Source name.
	 * @param bool   $force Force sync.
	 * @return array
	 */
	public function maybe_sync_if_due( $source = 'cron', $force = false ) {
		if ( ! $this->is_shipping_runtime_enabled() && ! $force ) {
			return array( 'success' => true, 'status' => 'disabled', 'message' => 'Mobo shipping runtime is disabled.' );
		}

		$interval_hours = Mobo_Core_Settings::get_int( 'mobo_core_remote_shipping_sync_interval_hours', 1, 1, 168 );
		$last_success   = absint( get_option( self::OPTION_LAST_SUCCESS, 0 ) );

		if ( ! $force && $last_success > 0 && ( time() - $last_success ) < ( $interval_hours * HOUR_IN_SECONDS ) ) {
			return array(
				'success'    => true,
				'status'     => 'fresh',
				'lastSyncAt' => $last_success,
				'message'    => 'Mobo shipping methods cache is fresh.',
			);
		}

		return $this->sync_now( $source, $force );
	}

	/**
	 * Force sync remote methods from MoboCore.
	 *
	 * @param string $source Source name.
	 * @param bool   $force Force the local refresh cadence only; Portal rebuild is administrator-only.
	 * @return array
	 */
	public function sync_now( $source = 'manual', $force = true ) {
		update_option( self::OPTION_LAST_ATTEMPT, time(), false );

		$api    = new Mobo_Core_API_Client();
		$result = method_exists( $api, 'get_mobo_shipping_methods' ) ? $api->get_mobo_shipping_methods() : new WP_Error( 'mobo_core_missing_shipping_api', 'MoboCore shipping-methods API is not available in this plugin build.' );

		if ( is_wp_error( $result ) ) {
			update_option( self::OPTION_LAST_ERROR, $result->get_error_message(), false );
			return array( 'success' => false, 'status' => 'failed', 'message' => $result->get_error_message() );
		}

		$stored = $this->store_snapshot( $result, $source );
		if ( empty( $stored['success'] ) ) {
			update_option( self::OPTION_LAST_ERROR, isset( $stored['message'] ) ? $stored['message'] : 'Invalid Mobo shipping-methods payload.', false );
			return $stored;
		}

		update_option( self::OPTION_LAST_SUCCESS, time(), false );
		delete_option( self::OPTION_LAST_ERROR );

		return array(
			'success' => true,
			'status'  => 'ok',
			'count'   => isset( $stored['count'] ) ? absint( $stored['count'] ) : count( $this->get_methods() ),
			'message' => 'Mobo shipping methods synced from MoboCore.',
		);
	}

	/**
	 * Store webhook or API shipping snapshot.
	 *
	 * @param array  $payload Payload.
	 * @param string $source Source name.
	 * @return array
	 */
	public function store_snapshot( $payload, $source = 'webhook' ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		$raw_methods = array();

		if ( isset( $data['shippings'] ) && is_array( $data['shippings'] ) ) {
			$raw_methods = $data['shippings'];
		} elseif ( isset( $data['methods'] ) && is_array( $data['methods'] ) ) {
			$raw_methods = $data['methods'];
		} elseif ( isset( $payload['shippings'] ) && is_array( $payload['shippings'] ) ) {
			$raw_methods = $payload['shippings'];
		}

		$methods = $this->normalize_methods( $raw_methods );
		if ( empty( $methods ) ) {
			return array( 'success' => false, 'status' => 'invalid', 'message' => 'Mobo shipping methods payload is empty or invalid.' );
		}

		$changed_at = ! empty( $data['changedAt'] ) ? strtotime( (string) $data['changedAt'] ) : time();
		if ( ! $changed_at ) {
			$changed_at = time();
		}

		$snapshot = array(
			'success'   => true,
			'source'    => sanitize_key( (string) $source ),
			'syncedAt'  => time(),
			'changedAt' => $changed_at,
			'shippings' => $methods,
		);

		update_option( self::OPTION_SNAPSHOT, $snapshot, false );
		update_option( self::OPTION_CHANGED_AT, $changed_at, false );

		return array( 'success' => true, 'status' => 'stored', 'count' => count( $methods ) );
	}

	/**
	 * Get normalized active remote shipping methods.
	 *
	 * @return array
	 */
	public function get_methods() {
		$snapshot = get_option( self::OPTION_SNAPSHOT, array() );
		$methods  = isset( $snapshot['shippings'] ) && is_array( $snapshot['shippings'] ) ? $snapshot['shippings'] : array();
		return $this->normalize_methods( $methods );
	}

	/**
	 * Get status for admin UI.
	 *
	 * @return array
	 */
	public function get_status() {
		$methods = $this->get_methods();
		return array(
			'checkoutActive'       => Mobo_Core_Settings::enabled( 'mobo_core_automatic_shipping_enabled', '0' ),
			'orderSubmission'      => $this->is_order_submission_enabled(),
			'shippingPackage'      => Mobo_Core_Settings::enabled( 'mobo_core_mobo_shipping_package_enabled', '0' ),
			'shippingClassId'      => Mobo_Core_Settings::get_int( 'mobo_core_mobo_shipping_class_id', 0, 0, PHP_INT_MAX ),
			'usesMoboApiPrice'     => Mobo_Core_Settings::enabled( 'mobo_core_mobo_shipping_use_api_price', '1' ),
			'count'                => count( $methods ),
			'lastAttemptAt'        => absint( get_option( self::OPTION_LAST_ATTEMPT, 0 ) ),
			'lastSuccessAt'        => absint( get_option( self::OPTION_LAST_SUCCESS, 0 ) ),
			'lastChangedAt'        => absint( get_option( self::OPTION_CHANGED_AT, 0 ) ),
			'lastError'            => (string) get_option( self::OPTION_LAST_ERROR, '' ),
			'rules'                => $this->get_rules(),
			'wordpressTime'        => $this->get_wordpress_time_status(),
		);
	}

	/**
	 * Prepare a shipping-only copy of WooCommerce packages for Mobo products.
	 *
	 * Product/catalog/order prices are never changed. Only the package passed to
	 * WooCommerce shipping methods is adjusted so class/subtotal rules can use
	 * the original Mobo API price stored in mobo_api_price.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public function filter_cart_shipping_packages( $packages ) {
		if ( ! is_array( $packages ) || ! Mobo_Core_Settings::enabled( 'mobo_core_mobo_shipping_package_enabled', '0' ) ) {
			return $packages;
		}

		$class_id     = Mobo_Core_Settings::get_int( 'mobo_core_mobo_shipping_class_id', 0, 0, PHP_INT_MAX );
		$use_api_price = Mobo_Core_Settings::enabled( 'mobo_core_mobo_shipping_use_api_price', '1' );

		foreach ( $packages as $package_index => $package ) {
			if ( ! is_array( $package ) || empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
				continue;
			}

			$contents_cost = 0.0;
			$mobo_count    = 0;
			foreach ( $package['contents'] as $item_key => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$product      = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
				$product_id   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : ( $product ? absint( $product->get_id() ) : 0 );
				$variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;

				if ( ! $this->is_mobo_product( $product, $product_id, $variation_id ) ) {
					$contents_cost += isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
					continue;
				}

				$mobo_count++;
				$line_total = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
				$api_price  = $use_api_price ? $this->get_mobo_api_price( $variation_id, $product_id ) : null;

				$shipping_api_price = null !== $api_price ? $this->source_amount_to_store_amount( $api_price ) : null;
				if ( null !== $shipping_api_price ) {
					$quantity   = isset( $item['quantity'] ) ? max( 0.0, (float) $item['quantity'] ) : 0.0;
					$line_total = $shipping_api_price * $quantity;
					$packages[ $package_index ]['contents'][ $item_key ]['line_subtotal'] = $line_total;
					$packages[ $package_index ]['contents'][ $item_key ]['line_total']    = $line_total;
				}

				if ( $product ) {
					$shipping_product = clone $product;
					if ( $class_id > 0 && method_exists( $shipping_product, 'set_shipping_class_id' ) ) {
						$shipping_product->set_shipping_class_id( $class_id );
					}
					if ( null !== $shipping_api_price && method_exists( $shipping_product, 'set_price' ) ) {
						$shipping_product->set_price( $shipping_api_price );
					}
					$packages[ $package_index ]['contents'][ $item_key ]['data'] = $shipping_product;
				}

				$contents_cost += $line_total;
			}

			$packages[ $package_index ]['contents_cost'] = $contents_cost;
			$packages[ $package_index ]['mobo_core_shipping_context'] = array(
				'moboItemCount' => $mobo_count,
				'classId'       => $class_id,
				'usesApiPrice'  => $use_api_price,
			);
		}

		return $packages;
	}

	/**
	 * Compatibility method. Mobo rates are no longer injected into WooCommerce.
	 *
	 * @param array $rates WooCommerce rates.
	 * @param array $package Package.
	 * @return array
	 */
	public function filter_package_rates( $rates, $package ) {
		return $rates;
	}

	/**
	 * Resolve Mobo shipping_id for a WC order.
	 *
	 * WooCommerce owns checkout shipping-rate display. For automatic Mobo order
	 * submission, the shipping method selected by the customer in WooCommerce is
	 * mapped to exactly one Mobo shipping_id.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $available_shippings Fresh shipping methods returned by Mobo for this cart.
	 * @return int|WP_Error
	 */
	public function resolve_shipping_id_for_order( $order, $available_shippings = array() ) {
		$scenario = $this->classify_order( $order );
		if ( 'non_mobo_only' === $scenario ) {
			return new WP_Error( 'mobo_core_shipping_not_needed', 'این سفارش محصول موبو ندارد و نیازی به روش ارسال موبو نیست.' );
		}

		$available = $this->normalize_methods( $available_shippings );
		if ( empty( $available ) ) {
			return new WP_Error( 'mobo_core_shipping_methods_not_available', 'موبو برای این سبد خرید هیچ روش ارسالی برنگرداند.' );
		}
		$available_by_id = array();
		foreach ( $available as $method ) {
			$available_by_id[ absint( $method['id'] ) ] = $method;
		}

		$wc_method = $this->get_order_wc_shipping_method_context( $order );
		if ( is_wp_error( $wc_method ) ) {
			return $wc_method;
		}

		// Backward compatibility for orders already created by 10.32.x: if the
		// only stored shipping line is the retired Mobo custom method, honor its
		// captured shipping_id. New orders can never enter this branch because the
		// custom method is no longer registered.
		$legacy_mobo_id = absint( isset( $wc_method['moboShippingId'] ) ? $wc_method['moboShippingId'] : 0 );
		if ( 'mobo_core_shipping' === $wc_method['methodId'] && $legacy_mobo_id > 0 && isset( $available_by_id[ $legacy_mobo_id ] ) ) {
			return $legacy_mobo_id;
		}

		$zone_id = $this->determine_order_shipping_zone_id( $order );
		$mapped  = $this->get_mapped_mobo_shipping_id_for_wc_method( $zone_id, $wc_method['methodId'], $wc_method['instanceId'] );
		$id      = absint( isset( $mapped['shippingId'] ) ? $mapped['shippingId'] : 0 );
		$zone_id = absint( isset( $mapped['zoneId'] ) ? $mapped['zoneId'] : $zone_id );

		if ( $id <= 0 ) {
			return new WP_Error(
				'mobo_core_shipping_method_mapping_missing',
				sprintf(
					'برای روش ارسال ووکامرس «%s» در منطقه ارسال #%s هیچ روش ارسال موبویی نگاشت نشده است.',
					isset( $wc_method['title'] ) ? $wc_method['title'] : $wc_method['methodId'],
					$zone_id
				)
			);
		}

		if ( isset( $available_by_id[ $id ] ) ) {
			return $id;
		}
		return new WP_Error(
			'mobo_core_shipping_config_not_available',
			'روش ارسال موبوی نگاشت‌شده برای روش ارسال انتخابی ووکامرس، در لیست روش‌های ارسال فعلی موبو موجود نیست: ' . $id
		);
	}

	/**
	 * Get configured shipping rules for admin/debug.
	 *
	 * @return array
	 */
	public function get_rules() {
		$rules = array();
		foreach ( $this->get_scenarios() as $key => $label ) {
			$rules[ $key ] = array( 'label' => $label );
		}
		return $rules;
	}

	/**
	 * Scenario definitions used for Mobo submission. Non-Mobo-only orders are ignored.
	 *
	 * @return array
	 */
	public function get_scenarios() {
		// Kept only for reading legacy saved options from pre-10.33.0 builds.
		return array(
			'mobo_only' => 'سفارش فقط محصولات موبو',
			'mixed'     => 'سفارش ترکیبی موبو و غیرموبو',
		);
	}

	/**
	 * Old UI modes kept only for backward compatibility with older saved options.
	 *
	 * @return array
	 */
	public function get_rule_modes() {
		return array(
			'woocommerce' => 'نمایش روش ارسال با WooCommerce',
		);
	}

	/**
	 * Build option key for a WooCommerce shipping-method to Mobo shipping-method rule.
	 *
	 * @param int    $zone_id WooCommerce shipping zone ID.
	 * @param string $method_id WooCommerce shipping method ID.
	 * @param int    $instance_id WooCommerce shipping method instance ID.
	 * @return string
	 */
	public function build_wc_method_rule_option_key( $zone_id, $method_id, $instance_id, $scenario = 'mobo_only' ) {
		$scenario    = $this->sanitize_scenario( $scenario );
		$zone_id     = absint( $zone_id );
		$method_id   = sanitize_key( (string) $method_id );
		$instance_id = absint( $instance_id );

		if ( '' === $method_id ) {
			$method_id = 'unknown';
		}

		return 'mobo_core_wc_shipping_method_map_' . $scenario . '_zone_' . $zone_id . '_' . $method_id . '_' . $instance_id;
	}

	/**
	 * Build the legacy scenario-less WooCommerce shipping-method mapping key.
	 *
	 * @param int    $zone_id WooCommerce shipping zone ID.
	 * @param string $method_id WooCommerce shipping method ID.
	 * @param int    $instance_id WooCommerce shipping method instance ID.
	 * @return string
	 */
	public function build_legacy_wc_method_rule_option_key( $zone_id, $method_id, $instance_id ) {
		$zone_id     = absint( $zone_id );
		$method_id   = sanitize_key( (string) $method_id );
		$instance_id = absint( $instance_id );

		if ( '' === $method_id ) {
			$method_id = 'unknown';
		}

		return 'mobo_core_wc_shipping_method_map_zone_' . $zone_id . '_' . $method_id . '_' . $instance_id;
	}

	/**
	 * Get mapped Mobo shipping ID for a WooCommerce method instance.
	 *
	 * @param int    $zone_id WooCommerce shipping zone ID.
	 * @param string $method_id WooCommerce method ID.
	 * @param int    $instance_id WooCommerce method instance ID.
	 * @return array
	 */
	private function get_mapped_mobo_shipping_id_for_wc_method( $zone_id, $method_id, $instance_id ) {
		$zone_id     = absint( $zone_id );
		$method_id   = sanitize_key( (string) $method_id );
		$instance_id = absint( $instance_id );

		$key = $this->build_legacy_wc_method_rule_option_key( $zone_id, $method_id, $instance_id );
		$id  = absint( get_option( $key, 0 ) );
		if ( $id > 0 ) {
			return array( 'shippingId' => $id, 'zoneId' => $zone_id );
		}

		// Safe migration from 10.32.x: reuse old scenario mappings only when they
		// agree (or only one side was configured). Conflicting mappings require an
		// explicit administrator choice in the new single mapping UI.
		$legacy_ids = array();
		foreach ( array( 'mobo_only', 'mixed' ) as $legacy_scenario ) {
			$legacy_id = absint( get_option( $this->build_wc_method_rule_option_key( $zone_id, $method_id, $instance_id, $legacy_scenario ), 0 ) );
			if ( $legacy_id > 0 ) {
				$legacy_ids[] = $legacy_id;
			}
		}
		$legacy_ids = array_values( array_unique( $legacy_ids ) );
		if ( 1 === count( $legacy_ids ) ) {
			$id = absint( $legacy_ids[0] );
			update_option( $key, (string) $id, false );
			return array( 'shippingId' => $id, 'zoneId' => $zone_id );
		}

		// WooCommerce order shipping items do not persist the matched Zone ID.
		// Instance IDs are globally unique, so scan other known zones as fallback.
		foreach ( $this->get_known_wc_shipping_zone_ids() as $candidate_zone_id ) {
			$candidate_zone_id = absint( $candidate_zone_id );
			if ( $candidate_zone_id === $zone_id ) {
				continue;
			}
			$candidate_key = $this->build_legacy_wc_method_rule_option_key( $candidate_zone_id, $method_id, $instance_id );
			$candidate_id  = absint( get_option( $candidate_key, 0 ) );
			if ( $candidate_id > 0 ) {
				return array( 'shippingId' => $candidate_id, 'zoneId' => $candidate_zone_id );
			}
		}
		return array( 'shippingId' => 0, 'zoneId' => $zone_id );
	}

	/**
	 * Get WooCommerce shipping zone IDs for mapping fallback.
	 *
	 * @return array
	 */
	private function get_known_wc_shipping_zone_ids() {
		$zone_ids = array();
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$raw_zones = WC_Shipping_Zones::get_zones();
			if ( is_array( $raw_zones ) ) {
				foreach ( $raw_zones as $zone_data ) {
					$zone_id = 0;
					if ( is_array( $zone_data ) ) {
						$zone_id = isset( $zone_data['id'] ) ? absint( $zone_data['id'] ) : ( isset( $zone_data['zone_id'] ) ? absint( $zone_data['zone_id'] ) : 0 );
					} elseif ( is_object( $zone_data ) && isset( $zone_data->id ) ) {
						$zone_id = absint( $zone_data->id );
					}
					if ( $zone_id > 0 ) {
						$zone_ids[] = $zone_id;
					}
				}
			}
		}

		$zone_ids[] = 0;
		return array_values( array_unique( array_map( 'absint', $zone_ids ) ) );
	}

	/**
	 * Build option key for a scenario/state/time-slot rule.
	 *
	 * @param string $scenario mobo_only|mixed.
	 * @param int    $state_id Mobo state ID.
	 * @param string $slot before12|after12.
	 * @return string
	 */
	public function build_state_rule_option_key( $scenario, $state_id, $slot ) {
		$scenario = $this->sanitize_scenario( $scenario );
		$state_id = absint( $state_id );
		$slot     = $this->sanitize_time_slot( $slot );
		return 'mobo_core_shipping_allowed_ids_' . $scenario . '_state_' . $state_id . '_' . $slot;
	}

	/**
	 * Get selected IDs for a state/time-slot rule.
	 *
	 * @param string $scenario Scenario.
	 * @param int    $state_id Mobo state ID.
	 * @param string $slot Slot.
	 * @return array
	 */
	public function get_allowed_ids_for_state_slot( $scenario, $state_id, $slot ) {
		$ids = $this->parse_allowed_ids_option( $this->build_state_rule_option_key( $scenario, $state_id, $slot ) );
		if ( empty( $ids ) ) {
			return array();
		}

		// The admin UI intentionally allows only one Mobo shipping method per state/time slot.
		// Older saved multi-select values are tolerated, but only the first one is used.
		return array( absint( $ids[0] ) );
	}

	/**
	 * Get current WordPress time slot.
	 *
	 * This must use the site's configured timezone directly. Do not combine
	 * current_time( 'timestamp' ) with wp_date(), because current_time() can
	 * already include the site offset and wp_date() applies the site timezone
	 * again.
	 *
	 * @return string before12|after12
	 */
	public function get_current_time_slot() {
		$now  = $this->get_wordpress_datetime();
		$hour = (int) $now->format( 'G' );
		return $hour < 12 ? 'before12' : 'after12';
	}

	/**
	 * Time slot labels.
	 *
	 * @return array
	 */
	public function get_time_slots() {
		return array(
			'before12' => 'قبل از ساعت ۱۲',
			'after12'  => 'بعد از ساعت ۱۲',
		);
	}

	/**
	 * Get one time-slot label.
	 *
	 * @param string $slot Slot.
	 * @return string
	 */
	public function get_time_slot_label( $slot ) {
		$slots = $this->get_time_slots();
		$slot  = $this->sanitize_time_slot( $slot );
		return isset( $slots[ $slot ] ) ? $slots[ $slot ] : $slot;
	}

	/**
	 * WordPress clock status for admin UI.
	 *
	 * @return array
	 */
	public function get_wordpress_time_status() {
		$now      = $this->get_wordpress_datetime();
		$timezone = $now->getTimezone() ? $now->getTimezone()->getName() : '—';
		$slot     = $this->get_current_time_slot();

		return array(
			'timestamp' => $now->getTimestamp(),
			'time'      => $now->format( 'Y-m-d H:i:s' ),
			'timezone'  => $timezone,
			'slot'      => $slot,
			'slotLabel' => $this->get_time_slot_label( $slot ),
		);
	}

	/**
	 * Get the current DateTime in the WordPress site timezone.
	 *
	 * @return DateTimeImmutable
	 */
	private function get_wordpress_datetime() {
		if ( function_exists( 'current_datetime' ) ) {
			$now = current_datetime();
			if ( $now instanceof DateTimeImmutable ) {
				return $now;
			}
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		return new DateTimeImmutable( 'now', $timezone instanceof DateTimeZone ? $timezone : null );
	}

	private function resolve_order_mobo_location_context( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}
		if ( ! class_exists( 'Mobo_Core_Address_Mapping' ) ) {
			return new WP_Error( 'mobo_core_address_mapping_missing', 'ماژول نگاشت آدرس موبو در دسترس نیست.' );
		}

		$group = $this->get_order_address_group_for_mobo( $order );
		$mapper = new Mobo_Core_Address_Mapping();
		if ( ! method_exists( $mapper, 'resolve_order_group' ) ) {
			return new WP_Error( 'mobo_core_address_mapping_missing_method', 'نگاشت آدرس سفارش قابل استفاده نیست.' );
		}

		$resolved = $mapper->resolve_order_group( $order, $group );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$country_id = absint( isset( $resolved['countryId'] ) ? $resolved['countryId'] : 0 );
		$state_id   = absint( isset( $resolved['stateId'] ) ? $resolved['stateId'] : 0 );
		$city_id    = absint( isset( $resolved['cityId'] ) ? $resolved['cityId'] : 0 );

		if ( $country_id <= 0 || $state_id <= 0 || $city_id <= 0 ) {
			return new WP_Error( 'mobo_core_address_mapping_incomplete', 'شناسه کشور، استان یا شهر موبو برای این سفارش کامل نیست. نگاشت کشور و استان و وضعیت فایل شهرهای موبو را بررسی کنید. ثبت سفارش در موبو متوقف شد.' );
		}

		return array(
			'group'     => $group,
			'countryId' => $country_id,
			'stateId'   => $state_id,
			'cityId'    => $city_id,
		);
	}

	private function get_order_address_group_for_mobo( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'billing';
		}

		$shipping_country = method_exists( $order, 'get_shipping_country' ) ? trim( (string) $order->get_shipping_country() ) : '';
		$shipping_state   = method_exists( $order, 'get_shipping_state' ) ? trim( (string) $order->get_shipping_state() ) : '';
		$shipping_city    = method_exists( $order, 'get_shipping_city' ) ? trim( (string) $order->get_shipping_city() ) : '';
		$shipping_address = method_exists( $order, 'get_shipping_address_1' ) ? trim( (string) $order->get_shipping_address_1() ) : '';

		if ( '' !== $shipping_country || '' !== $shipping_state || '' !== $shipping_city || '' !== $shipping_address ) {
			return 'shipping';
		}
		return 'billing';
	}

	/**
	 * Get the WooCommerce shipping method stored on the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error
	 */
	private function get_order_wc_shipping_method_context( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}

		$fallback = null;
		$legacy_mobo_fallback = null;
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$method_id   = method_exists( $shipping_item, 'get_method_id' ) ? sanitize_key( (string) $shipping_item->get_method_id() ) : '';
			$instance_id = method_exists( $shipping_item, 'get_instance_id' ) ? absint( $shipping_item->get_instance_id() ) : 0;
			$title       = method_exists( $shipping_item, 'get_name' ) ? sanitize_text_field( (string) $shipping_item->get_name() ) : '';
			$mobo_shipping_id = method_exists( $shipping_item, 'get_meta' ) ? absint( $shipping_item->get_meta( '_mobo_shipping_id', true ) ) : 0;

			if ( '' === $method_id ) {
				continue;
			}

			$context = array(
				'methodId'       => $method_id,
				'instanceId'     => $instance_id,
				'title'          => '' !== $title ? $title : $method_id,
				'moboShippingId' => $mobo_shipping_id,
			);

			/* Mapping-only policy: prefer the merchant's real WooCommerce shipping
			 * line. A retired Mobo shipping line is kept only as a legacy fallback for
			 * orders that were created before the upgrade. */
			if ( 'mobo_core_shipping' === $method_id || $mobo_shipping_id > 0 ) {
				if ( null === $legacy_mobo_fallback ) {
					$legacy_mobo_fallback = $context;
				}
				continue;
			}
			if ( null === $fallback ) {
				$fallback = $context;
			}
		}

		if ( is_array( $fallback ) ) {
			return $fallback;
		}
		if ( is_array( $legacy_mobo_fallback ) ) {
			return $legacy_mobo_fallback;
		}

		return new WP_Error( 'mobo_core_wc_shipping_method_missing', 'در سفارش ووکامرس هیچ روش ارسالی ثبت نشده است؛ بنابراین shipping_id موبو قابل انتخاب نیست.' );
	}

	/**
	 * Determine the WooCommerce shipping zone that matches the order destination.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private function determine_order_shipping_zone_id( $order ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! $order instanceof WC_Order ) {
			return 0;
		}

		$group = $this->get_order_address_group_for_mobo( $order );
		$destination = array(
			'country'   => 'shipping' === $group ? $order->get_shipping_country() : $order->get_billing_country(),
			'state'     => 'shipping' === $group ? $order->get_shipping_state() : $order->get_billing_state(),
			'postcode'  => 'shipping' === $group ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
			'city'      => 'shipping' === $group ? $order->get_shipping_city() : $order->get_billing_city(),
			'address'   => 'shipping' === $group ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			'address_2' => 'shipping' === $group ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
		);

		$package = array(
			'destination' => $destination,
			'contents'    => array(),
			'contents_cost' => 0,
			'applied_coupons' => array(),
		);

		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		if ( $zone && method_exists( $zone, 'get_id' ) ) {
			return absint( $zone->get_id() );
		}

		return 0;
	}

	private function parse_allowed_ids_option( $key ) {
		$value = get_option( $key, '' );
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = preg_split( '/[\s,]+/', (string) $value );
		}

		$ids = array();
		foreach ( $parts as $part ) {
			$id = absint( $part );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function classify_order( $order ) {
		$mobo = 0;
		$non  = 0;
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return 'non_mobo_only';
		}
		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $line_item->get_product();
			$product_id = absint( $line_item->get_product_id() );
			$variation_id = absint( $line_item->get_variation_id() );
			if ( $this->is_mobo_product( $product, $product_id, $variation_id ) ) {
				$mobo++;
			} else {
				$non++;
			}
		}
		return $this->scenario_from_counts( $mobo, $non );
	}

	private function scenario_from_counts( $mobo, $non ) {
		if ( $mobo > 0 && $non > 0 ) {
			return 'mixed';
		}
		if ( $mobo > 0 ) {
			return 'mobo_only';
		}
		return 'non_mobo_only';
	}

	private function is_mobo_product( $product, $product_id, $variation_id ) {
		$ids = array_filter( array( absint( $variation_id ), absint( $product_id ), $product instanceof WC_Product ? absint( $product->get_id() ) : 0 ) );
		foreach ( $ids as $id ) {
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

	private function normalize_methods( $methods ) {
		if ( ! is_array( $methods ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $methods as $method ) {
			if ( ! is_array( $method ) ) {
				continue;
			}

			$id = isset( $method['id'] ) ? absint( $method['id'] ) : ( isset( $method['Id'] ) ? absint( $method['Id'] ) : 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$title    = isset( $method['title'] ) ? (string) $method['title'] : ( isset( $method['Title'] ) ? (string) $method['Title'] : '' );
			$type     = isset( $method['type'] ) ? (string) $method['type'] : ( isset( $method['Type'] ) ? (string) $method['Type'] : '' );
			$desc     = isset( $method['description'] ) ? $method['description'] : ( isset( $method['Description'] ) ? $method['Description'] : null );
			$cost     = array_key_exists( 'cost', $method ) ? $method['cost'] : ( array_key_exists( 'Cost', $method ) ? $method['Cost'] : null );
			$status   = isset( $method['status'] ) && is_array( $method['status'] ) ? array_values( array_filter( array_map( 'sanitize_key', $method['status'] ) ) ) : array( 'approved' );
			$rules    = isset( $method['rules'] ) && is_array( $method['rules'] ) ? $this->normalize_shipping_rules( $method['rules'] ) : array();

			if ( in_array( 'suspended', $status, true ) || ( ! empty( $status ) && ! in_array( 'approved', $status, true ) ) ) {
				continue;
			}

			$normalized[ $id ] = array(
				'id'               => $id,
				'type'             => sanitize_key( $type ),
				'status'           => $status,
				'title'            => sanitize_text_field( '' !== $title ? $title : ( 'Mobo shipping #' . $id ) ),
				'description'      => is_null( $desc ) ? '' : sanitize_textarea_field( (string) $desc ),
				'minimum_weight'   => $this->nullable_number( isset( $method['minimum_weight'] ) ? $method['minimum_weight'] : null ),
				'maximum_weight'   => $this->nullable_number( isset( $method['maximum_weight'] ) ? $method['maximum_weight'] : null ),
				'minimum_subtotal' => $this->nullable_number( isset( $method['minimum_subtotal'] ) ? $method['minimum_subtotal'] : null ),
				'maximum_subtotal' => $this->nullable_number( isset( $method['maximum_subtotal'] ) ? $method['maximum_subtotal'] : null ),
				'minimum_cost'     => $this->nullable_number( isset( $method['minimum_cost'] ) ? $method['minimum_cost'] : null ),
				'maximum_cost'     => $this->nullable_number( isset( $method['maximum_cost'] ) ? $method['maximum_cost'] : null ),
				'round_cost'       => $this->nullable_number( isset( $method['round_cost'] ) ? $method['round_cost'] : null ),
				'cost'             => $this->nullable_number( $cost ),
				'position'         => isset( $method['position'] ) ? intval( $method['position'] ) : PHP_INT_MAX,
				'countries'        => $this->normalize_location_items( isset( $method['countries'] ) ? $method['countries'] : array() ),
				'states'           => $this->normalize_location_items( isset( $method['states'] ) ? $method['states'] : array() ),
				'cities'           => $this->normalize_location_items( isset( $method['cities'] ) ? $method['cities'] : array() ),
				'rules'            => $rules,
				'created'          => isset( $method['created'] ) && is_array( $method['created'] ) ? $method['created'] : array(),
			);
		}

		uasort( $normalized, function( $a, $b ) {
			if ( $a['position'] === $b['position'] ) {
				return $a['id'] <=> $b['id'];
			}
			return $a['position'] <=> $b['position'];
		} );

		return array_values( $normalized );
	}

	private function normalize_shipping_rules( $rules ) {
		$normalized = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$normalized[] = array(
				'minimum_weight'   => $this->nullable_number( isset( $rule['minimum_weight'] ) ? $rule['minimum_weight'] : null ),
				'maximum_weight'   => $this->nullable_number( isset( $rule['maximum_weight'] ) ? $rule['maximum_weight'] : null ),
				'minimum_subtotal' => $this->nullable_number( isset( $rule['minimum_subtotal'] ) ? $rule['minimum_subtotal'] : null ),
				'maximum_subtotal' => $this->nullable_number( isset( $rule['maximum_subtotal'] ) ? $rule['maximum_subtotal'] : null ),
				'cost'             => $this->nullable_number( isset( $rule['cost'] ) ? $rule['cost'] : null ),
			);
		}
		return $normalized;
	}

	private function normalize_location_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$normalized = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$normalized[] = array(
				'id'        => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
				'name'      => isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '',
				'latitude'  => $this->nullable_number( isset( $item['latitude'] ) ? $item['latitude'] : null ),
				'longitude' => $this->nullable_number( isset( $item['longitude'] ) ? $item['longitude'] : null ),
			);
		}
		return $normalized;
	}

	private function nullable_number( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function get_mobo_api_price( $variation_id, $product_id ) {
		$ids = array_values( array_unique( array_filter( array( absint( $variation_id ), absint( $product_id ) ) ) ) );
		foreach ( $ids as $id ) {
			$value = get_post_meta( $id, 'mobo_api_price', true );
			if ( is_numeric( $value ) && (float) $value >= 0 ) {
				return (float) $value;
			}
		}
		return null;
	}

	/**
	 * Convert a source/Mobo amount (Toman) to the WooCommerce store currency.
	 *
	 * @param float $amount Source amount.
	 * @return float
	 */
	private function source_amount_to_store_amount( $amount ) {
		$currency   = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$multiplier = 'IRR' === $currency ? 10.0 : 1.0;
		$multiplier = (float) apply_filters( 'mobo_core_shipping_source_to_store_multiplier', $multiplier, $currency );
		return max( 0.0, (float) $amount * max( 0.0, $multiplier ) );
	}

	private function is_shipping_runtime_enabled() {
		return $this->is_order_submission_enabled()
			|| Mobo_Core_Settings::enabled( 'mobo_core_mobo_shipping_package_enabled', '0' )
			|| Mobo_Core_Settings::enabled( 'mobo_core_automatic_shipping_enabled', '0' );
	}

	private function sanitize_scenario( $scenario ) {
		$scenario = sanitize_key( (string) $scenario );
		return array_key_exists( $scenario, $this->get_scenarios() ) ? $scenario : 'mobo_only';
	}

	private function sanitize_time_slot( $slot ) {
		$slot = sanitize_key( (string) $slot );
		return in_array( $slot, array( 'before12', 'after12' ), true ) ? $slot : 'before12';
	}

	private function is_order_submission_enabled() {
		return Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
	}
}
