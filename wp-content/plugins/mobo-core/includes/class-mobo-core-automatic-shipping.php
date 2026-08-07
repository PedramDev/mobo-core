<?php
/**
 * One-click WooCommerce shipping configuration backed by Mobo shipping data.
 *
 * The installer creates/repairs a shipping class, one managed WooCommerce
 * shipping-method instance per active Mobo shipping method, fixed instance to
 * shipping_id mappings, and the shipping-only Mobo price context. Re-running
 * the installer is idempotent and never removes unrelated WooCommerce zones or
 * methods.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Automatic_Shipping {

	const METHOD_ID             = 'mobo_core_shipping';
	const CLASS_SLUG            = 'mobo-products';
	const CLASS_NAME            = 'محصولات موبو';
	const DEFAULT_ZONE_NAME     = 'ایران - ارسال موبو';
	const OPTION_ENABLED        = 'mobo_core_automatic_shipping_enabled';
	const OPTION_LAST_RUN       = 'mobo_core_automatic_shipping_last_run_at';
	const OPTION_LAST_RESULT    = 'mobo_core_automatic_shipping_last_result';
	const OPTION_MANAGED_ZONES  = 'mobo_core_automatic_shipping_managed_zones';
	const OPTION_MANAGED_RATES  = 'mobo_core_automatic_shipping_managed_instances';
	const OPTION_STORE_RATE_MODE  = 'mobo_core_shipping_store_rate_mode';
	const OPTION_STORE_RATE_TITLE = 'mobo_core_shipping_store_rate_title';
	const OPTION_STORE_RATE_COST  = 'mobo_core_shipping_store_rate_cost';
	const OPTION_STORE_FALLBACKS  = 'mobo_core_shipping_store_fallback_instances';
	const OPTION_STORE_EXISTING_MIRRORS = 'mobo_core_shipping_store_existing_mirror_instances';

	const STORE_RATE_MODE_EXISTING          = 'existing_methods';
	const STORE_RATE_MODE_ENSURE_FLAT_RATE  = 'ensure_flat_rate';
	const STORE_FALLBACK_MARKER              = 'managed_by_mobo_core_store_fallback';
	const STORE_EXISTING_MIRROR_MARKER        = 'managed_by_mobo_core_store_existing_mirror';
	const STORE_EXISTING_SOURCE_ZONE          = 'mobo_core_store_existing_source_zone';
	const STORE_EXISTING_SOURCE_INSTANCE      = 'mobo_core_store_existing_source_instance';
	const STORE_EXISTING_SOURCE_METHOD        = 'mobo_core_store_existing_source_method';

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Mapping-only policy: Mobo never registers or injects a checkout shipping method.
	}

	/**
	 * Disable shipping configuration created by legacy automatic-shipping builds.
	 *
	 * This does not touch merchant-owned WooCommerce methods. Only instances that
	 * are recorded in Mobo Core's managed option maps are disabled. The cleanup is
	 * idempotent and is also used by the admin repair action.
	 *
	 * @param string $source Cleanup source.
	 * @return array
	 */
	public function retire_legacy_runtime( $source = 'mapping-only' ) {
		$policy_version = '1';
		$already_applied = (string) get_option( 'mobo_core_shipping_mapping_only_policy_version', '' ) === $policy_version;
		$result = array(
			'success' => true,
			'source' => sanitize_key( (string) $source ),
			'alreadyApplied' => $already_applied,
			'disabledMoboMethods' => 0,
			'disabledFallbackMethods' => 0,
			'disabledMirrorMethods' => 0,
			'deletedLegacyZones' => 0,
		);
		$force = 'admin-mapping-repair' === sanitize_key( (string) $source );
		if ( $already_applied && ! $force ) {
			return $result;
		}

		$managed = get_option( self::OPTION_MANAGED_RATES, array() );
		foreach ( is_array( $managed ) ? $managed : array() as $entry ) {
			$instance_id = is_array( $entry ) ? absint( isset( $entry['instanceId'] ) ? $entry['instanceId'] : 0 ) : 0;
			if ( $instance_id > 0 && $this->disable_legacy_instance( self::METHOD_ID, $instance_id ) ) {
				$result['disabledMoboMethods']++;
			}
		}

		$fallbacks = get_option( self::OPTION_STORE_FALLBACKS, array() );
		foreach ( is_array( $fallbacks ) ? $fallbacks : array() as $instance_id ) {
			$instance_id = absint( $instance_id );
			if ( $instance_id > 0 && $this->disable_legacy_instance( 'flat_rate', $instance_id ) ) {
				$result['disabledFallbackMethods']++;
			}
		}

		$mirrors = get_option( self::OPTION_STORE_EXISTING_MIRRORS, array() );
		foreach ( is_array( $mirrors ) ? $mirrors : array() as $zone_map ) {
			foreach ( is_array( $zone_map ) ? $zone_map : array() as $source_key => $instance_id ) {
				$parts = explode( ':', (string) $source_key );
				$method_id = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
				$instance_id = absint( $instance_id );
				if ( '' !== $method_id && $instance_id > 0 && $this->disable_legacy_instance( $method_id, $instance_id ) ) {
					$result['disabledMirrorMethods']++;
				}
			}
		}

		$result['deletedLegacyZones'] = $this->delete_empty_legacy_mobo_zones( $managed, $fallbacks, $mirrors );

		update_option( self::OPTION_ENABLED, '0', false );
		update_option( 'mobo_core_mobo_shipping_package_enabled', '0', false );
		update_option( 'mobo_core_mobo_shipping_use_api_price', '0', false );
		update_option( 'mobo_core_shipping_wizard_completed', '0', false );
		update_option( 'mobo_core_shipping_mapping_only_policy_version', $policy_version, false );
		update_option( 'mobo_core_shipping_mapping_only_last_cleanup', array_merge( $result, array( 'at' => time() ) ), false );
		return $result;
	}

	/**
	 * Delete only the legacy default Mobo zone when every method inside it was
	 * created/managed by Mobo Core. If a merchant-owned method exists, the zone is
	 * preserved exactly as-is.
	 *
	 * @param array $managed Mobo custom method map.
	 * @param array $fallbacks Managed fallback map.
	 * @param array $mirrors Managed mirror map.
	 * @return int
	 */
	private function delete_empty_legacy_mobo_zones( $managed, $fallbacks, $mirrors ) {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			return 0;
		}
		$owned = array();
		foreach ( is_array( $managed ) ? $managed : array() as $entry ) {
			$id = is_array( $entry ) ? absint( isset( $entry['instanceId'] ) ? $entry['instanceId'] : 0 ) : 0;
			if ( $id > 0 ) { $owned[ $id ] = true; }
		}
		foreach ( is_array( $fallbacks ) ? $fallbacks : array() as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) { $owned[ $id ] = true; }
		}
		foreach ( is_array( $mirrors ) ? $mirrors : array() as $zone_map ) {
			foreach ( is_array( $zone_map ) ? $zone_map : array() as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) { $owned[ $id ] = true; }
			}
		}

		$deleted = 0;
		foreach ( (array) get_option( self::OPTION_MANAGED_ZONES, array() ) as $zone_id ) {
			$zone_id = absint( $zone_id );
			if ( $zone_id <= 0 ) { continue; }
			try {
				$zone = new WC_Shipping_Zone( $zone_id );
				$name = method_exists( $zone, 'get_zone_name' ) ? trim( (string) $zone->get_zone_name() ) : '';
				if ( self::DEFAULT_ZONE_NAME !== $name ) { continue; }
				$has_merchant_method = false;
				foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
					$instance_id = is_object( $method ) && isset( $method->instance_id ) ? absint( $method->instance_id ) : 0;
					if ( $instance_id > 0 && empty( $owned[ $instance_id ] ) ) {
						$has_merchant_method = true;
						break;
					}
				}
				if ( ! $has_merchant_method && method_exists( $zone, 'delete' ) ) {
					$zone->delete();
					$deleted++;
				}
			} catch ( Throwable $error ) {
				continue;
			}
		}
		return $deleted;
	}

	/**
	 * Disable one legacy Mobo-managed WooCommerce shipping instance.
	 *
	 * @param string $method_id Method ID.
	 * @param int    $instance_id Instance ID.
	 * @return bool True when the instance had been enabled before cleanup.
	 */
	private function disable_legacy_instance( $method_id, $instance_id ) {
		$method_id = sanitize_key( (string) $method_id );
		$instance_id = absint( $instance_id );
		if ( '' === $method_id || $instance_id <= 0 ) {
			return false;
		}
		$option_key = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
		$settings = get_option( $option_key, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$was_enabled = 'yes' === ( isset( $settings['enabled'] ) ? (string) $settings['enabled'] : 'yes' );
		$settings['enabled'] = 'no';
		update_option( $option_key, $settings, false );
		$this->set_zone_method_state( $instance_id, false, null );
		return $was_enabled;
	}

	/**
	 * Register the custom shipping method after WooCommerce has loaded its base class.
	 *
	 * @param array $methods Shipping method classes.
	 * @return array
	 */
	public function register_shipping_method( $methods ) {
		if ( ! is_array( $methods ) ) {
			$methods = array();
		}

		if ( class_exists( 'WC_Shipping_Method' ) ) {
			require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-wc-mobo-core-shipping-method.php';
			if ( class_exists( 'WC_Mobo_Core_Shipping_Method' ) ) {
				$methods[ self::METHOD_ID ] = 'WC_Mobo_Core_Shipping_Method';
			}
		}

		return $methods;
	}

	/**
	 * Persist the selected Mobo shipping ID on the order shipping line.
	 *
	 * WooCommerce already copies rate metadata to the order item. This hook is a
	 * compatibility fallback for themes/extensions that strip rate metadata.
	 *
	 * @param WC_Order_Item_Shipping $item Shipping item.
	 * @param string                 $package_key Package key.
	 * @param array                  $package Package.
	 * @param WC_Order               $order Order.
	 * @return void
	 */
	public function capture_order_shipping_id( $item, $package_key, $package, $order ) {
		unset( $package_key, $package, $order );
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) || self::METHOD_ID !== (string) $item->get_method_id() ) {
			return;
		}

		$id = method_exists( $item, 'get_meta' ) ? absint( $item->get_meta( '_mobo_shipping_id', true ) ) : 0;
		if ( $id <= 0 && method_exists( $item, 'get_instance_id' ) ) {
			$id = $this->get_shipping_id_for_instance( absint( $item->get_instance_id() ) );
		}

		if ( $id > 0 && method_exists( $item, 'update_meta_data' ) ) {
			$item->update_meta_data( '_mobo_shipping_id', $id );
		}
	}

	/**
	 * Create or repair all Mobo-owned WooCommerce shipping configuration.
	 *
	 * @param string $source Run source.
	 * @param array  $store_rate_config Store-product shipping setup.
	 * @return array
	 */
	public function install_or_repair( $source = 'manual', $store_rate_config = array() ) {
		unset( $store_rate_config );
		$cleanup = $this->retire_legacy_runtime( 'admin-mapping-repair' === sanitize_key( (string) $source ) ? 'admin-mapping-repair' : 'legacy-install-blocked' );
		return array(
			'success' => true,
			'status' => 'mapping-only',
			'message' => 'سیاست حمل‌ونقل Mobo Core روی حالت فقط نگاشت است؛ هیچ روش یا Zone موبویی در WooCommerce ساخته نمی‌شود.',
			'cleanup' => $cleanup,
		);

		/* Legacy installer code below is intentionally unreachable and retained only for downgrade/reference compatibility. */
		$result = array(
			'success'           => false,
			'source'            => sanitize_key( (string) $source ),
			'classId'           => 0,
			'zoneIds'           => array(),
			'createdZones'      => array(),
			'createdInstances'  => 0,
			'updatedInstances'  => 0,
			'disabledInstances' => 0,
			'activeMethods'     => 0,
			'manualReviewMethods' => array(),
			'tehranOnlyMethods' => 0,
			'iranWideMethods'   => 0,
			'storeRateMode'      => '',
			'storeRateCreated'   => 0,
			'storeRateUpdated'   => 0,
			'storeRateMirrored'  => 0,
			'storeRateDisabled'  => 0,
			'storeRateZones'     => array(),
			'warnings'          => array(),
			'message'           => '',
		);

		update_option( self::OPTION_LAST_RUN, time(), false );

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Shipping_Zone' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $this->store_result( $this->fail_result( $result, 'WooCommerce shipping classes are not available.' ) );
		}

		if ( ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
			return $this->store_result( $this->fail_result( $result, 'Mobo shipping data manager is not available.' ) );
		}

		$remote = new Mobo_Core_Remote_Shipping_Methods();
		$sync   = $remote->sync_now( 'automatic-shipping-setup', true );
		if ( empty( $sync['success'] ) ) {
			$message = isset( $sync['message'] ) ? (string) $sync['message'] : 'Shipping methods could not be synchronized from MoboCore.';
			return $this->store_result( $this->fail_result( $result, $message ) );
		}

		$methods = $remote->get_methods();
		if ( empty( $methods ) ) {
			return $this->store_result( $this->fail_result( $result, 'No active Mobo shipping method is available.' ) );
		}

		$class_id = $this->ensure_shipping_class();
		if ( is_wp_error( $class_id ) ) {
			return $this->store_result( $this->fail_result( $result, $class_id->get_error_message() ) );
		}

		$result['classId']       = absint( $class_id );
		$result['activeMethods'] = count( $methods );

		update_option( 'mobo_core_mobo_shipping_class_id', absint( $class_id ), false );
		update_option( 'mobo_core_mobo_shipping_package_enabled', '1', false );
		update_option( 'mobo_core_mobo_shipping_use_api_price', '1', false );
		update_option( self::OPTION_ENABLED, '1', false );

		$zones = $this->get_or_create_iran_zones( $result );
		if ( empty( $zones ) ) {
			return $this->store_result( $this->fail_result( $result, 'An Iran WooCommerce shipping zone could not be created or found.' ) );
		}

		$store_config = $this->sanitize_store_rate_config( is_array( $store_rate_config ) ? $store_rate_config : array() );
		$store_setup  = $this->ensure_store_shipping_ready( $zones, $store_config );
		if ( is_wp_error( $store_setup ) ) {
			return $this->store_result( $this->fail_result( $result, $store_setup->get_error_message() ) );
		}
		$result['storeRateMode']    = $store_config['mode'];
		$result['storeRateCreated'] = absint( isset( $store_setup['created'] ) ? $store_setup['created'] : 0 );
		$result['storeRateUpdated'] = absint( isset( $store_setup['updated'] ) ? $store_setup['updated'] : 0 );
		$result['storeRateMirrored'] = absint( isset( $store_setup['mirrored'] ) ? $store_setup['mirrored'] : 0 );
		$result['storeRateDisabled'] = absint( isset( $store_setup['disabled'] ) ? $store_setup['disabled'] : 0 );
		$result['storeRateZones']   = isset( $store_setup['zoneIds'] ) && is_array( $store_setup['zoneIds'] ) ? array_values( array_map( 'absint', $store_setup['zoneIds'] ) ) : array();
		if ( ! empty( $store_setup['warnings'] ) && is_array( $store_setup['warnings'] ) ) {
			$result['warnings'] = array_merge( $result['warnings'], $store_setup['warnings'] );
		}

		update_option( self::OPTION_STORE_RATE_MODE, $store_config['mode'], false );
		update_option( self::OPTION_STORE_RATE_TITLE, $store_config['title'], false );
		update_option( self::OPTION_STORE_RATE_COST, $store_config['cost'], false );

		$active_ids       = array();
		$managed_instances = array();
		$support_cod_rate_ids = array();

		foreach ( $methods as $method ) {
			$shipping_id = isset( $method['id'] ) ? absint( $method['id'] ) : 0;
			if ( $shipping_id <= 0 ) {
				continue;
			}
			$active_ids[] = $shipping_id;
			$scope = self::get_method_destination_scope( $method );
			if ( 'tehran_only' === $scope ) {
				$result['tehranOnlyMethods']++;
			} else {
				$result['iranWideMethods']++;
			}
			if ( $this->is_sensitive_operational_method( $method ) ) {
				$result['manualReviewMethods'][ $shipping_id ] = sanitize_text_field( isset( $method['title'] ) ? $method['title'] : (string) $shipping_id );
			}

			foreach ( $zones as $zone ) {
				$zone_id = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0;
				$instance = $this->ensure_method_instance( $zone, $method );
				if ( is_wp_error( $instance ) ) {
					$result['warnings'][] = $instance->get_error_message();
					continue;
				}

				$instance_id = absint( $instance['instanceId'] );
				if ( ! empty( $instance['created'] ) ) {
					$result['createdInstances']++;
				} else {
					$result['updatedInstances']++;
				}

				$managed_instances[] = array(
					'zoneId'      => $zone_id,
					'instanceId'  => $instance_id,
					'shippingId'  => $shipping_id,
					'scope'       => $scope,
					'enabled'     => ! empty( $instance['enabled'] ),
				);

				$this->save_automatic_mapping( $remote, $zone_id, $instance_id, $shipping_id );

				if ( ! empty( $instance['enabled'] ) && in_array( 'support_cod', isset( $method['status'] ) && is_array( $method['status'] ) ? $method['status'] : array(), true ) ) {
					$support_cod_rate_ids[] = self::METHOD_ID . ':' . $instance_id;
				}
			}
		}

		$result['disabledInstances'] += $this->disable_stale_managed_instances( $zones, $active_ids );
		$this->merge_cod_method_restrictions( $support_cod_rate_ids );

		$result['zoneIds'] = array_values( array_unique( array_map( 'absint', array_keys( $zones ) ) ) );
		update_option( self::OPTION_MANAGED_ZONES, $result['zoneIds'], false );
		update_option( self::OPTION_MANAGED_RATES, $managed_instances, false );

		$result['manualReviewMethods'] = array_values( $result['manualReviewMethods'] );
		$result['success'] = true;
		$store_summary = self::STORE_RATE_MODE_ENSURE_FLAT_RATE === $store_config['mode']
			? sprintf( '%1$d Flat Rate پشتیبان ساخته و %2$d مورد بروزرسانی شد.', absint( $result['storeRateCreated'] ), absint( $result['storeRateUpdated'] ) )
			: sprintf( 'روش‌های فعلی فروشگاه فعال شدند؛ %1$d روش به Zone موبو منتقل و %2$d Flat Rate پشتیبان غیرفعال شد.', absint( $result['storeRateMirrored'] ), absint( $result['storeRateDisabled'] ) );
		$result['message'] = sprintf(
			'پیکربندی حمل‌ونقل آماده شد: %1$d روش موبو در %2$d منطقه، %3$d Instance موبو جدید و %4$d Instance موبو بروزرسانی‌شده. محدوده‌ها: %6$d روش فقط تهران و %7$d روش سراسری ایران. ارسال محصولات فروشگاه: %8$s%5$s',
			count( $methods ),
			count( $zones ),
			absint( $result['createdInstances'] ),
			absint( $result['updatedInstances'] ),
			! empty( $result['manualReviewMethods'] ) ? ' ' . count( $result['manualReviewMethods'] ) . ' روش عملیاتی حساس برای بررسی مدیر غیرفعال ماند.' : '',
			absint( $result['tehranOnlyMethods'] ),
			absint( $result['iranWideMethods'] ),
			$store_summary
		);

		return $this->store_result( $result );
	}

	/**
	 * Current one-click shipping status.
	 *
	 * @return array
	 */
	public function get_status() {
		$last = get_option( self::OPTION_LAST_RESULT, array() );
		if ( ! is_array( $last ) ) {
			$last = array();
		}

		$class_id = Mobo_Core_Settings::get_int( 'mobo_core_mobo_shipping_class_id', 0, 0, PHP_INT_MAX );
		$zones    = get_option( self::OPTION_MANAGED_ZONES, array() );
		$rates    = get_option( self::OPTION_MANAGED_RATES, array() );

		$store_status = $this->inspect_store_shipping();

		return array(
			'enabled'          => Mobo_Core_Settings::enabled( self::OPTION_ENABLED, '0' ),
			'classId'          => $class_id,
			'classReady'       => $class_id > 0 && term_exists( $class_id, 'product_shipping_class' ),
			'zoneCount'        => is_array( $zones ) ? count( $zones ) : 0,
			'instanceCount'    => is_array( $rates ) ? count( $rates ) : 0,
			'storeRateMode'    => sanitize_key( (string) get_option( self::OPTION_STORE_RATE_MODE, self::STORE_RATE_MODE_EXISTING ) ),
			'storeRateTitle'   => sanitize_text_field( (string) get_option( self::OPTION_STORE_RATE_TITLE, 'ارسال محصولات فروشگاه' ) ),
			'storeRateCost'    => (string) get_option( self::OPTION_STORE_RATE_COST, '0' ),
			'storeShipping'    => $store_status,
			'lastRunAt'        => absint( get_option( self::OPTION_LAST_RUN, 0 ) ),
			'lastResult'       => $last,
		);
	}


	/**
	 * Inspect whether every Iran-capable zone has at least one enabled non-Mobo
	 * shipping method for ordinary store products.
	 *
	 * @return array
	 */
	public function inspect_store_shipping() {
		$status = array(
			'ready'                    => false,
			'existingReady'            => false,
			'existingRequiresMirror'   => false,
			'zoneCount'                => 0,
			'readyZoneCount'           => 0,
			'existingReadyZoneCount'   => 0,
			'activeMethodCount'        => 0,
			'existingMethodCount'      => 0,
			'existingSourceMethodCount'=> 0,
			'missingZones'             => array(),
			'existingMissingZones'     => array(),
			'existingSourceMethods'    => array(),
			'zones'                    => array(),
		);

		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
			return $status;
		}

		$zones = $this->get_iran_zones();
		$status['zoneCount'] = count( $zones );
		foreach ( $zones as $zone_id => $zone ) {
			$all_store_methods = $this->get_enabled_store_methods( $zone, true, true );
			/* Existing mode must never treat the plugin fallback as a user method. */
			$existing_methods  = $this->get_enabled_store_methods( $zone, false, true );
			$zone_name = method_exists( $zone, 'get_zone_name' ) ? sanitize_text_field( (string) $zone->get_zone_name() ) : ( 'Zone #' . absint( $zone_id ) );
			$row = array(
				'zoneId'              => absint( $zone_id ),
				'zoneName'            => $zone_name,
				'methodCount'         => count( $all_store_methods ),
				'existingMethodCount' => count( $existing_methods ),
				'methods'             => $existing_methods,
			);
			$status['zones'][] = $row;
			$status['activeMethodCount'] += count( $all_store_methods );
			$status['existingMethodCount'] += count( $existing_methods );
			if ( ! empty( $all_store_methods ) ) {
				$status['readyZoneCount']++;
			} else {
				$status['missingZones'][] = array( 'zoneId' => absint( $zone_id ), 'zoneName' => $zone_name );
			}
			if ( ! empty( $existing_methods ) ) {
				$status['existingReadyZoneCount']++;
			} else {
				$status['existingMissingZones'][] = array( 'zoneId' => absint( $zone_id ), 'zoneName' => $zone_name );
			}
		}

		$source_methods = $status['zoneCount'] > 0 && $status['zoneCount'] !== $status['existingReadyZoneCount']
			? $this->find_existing_store_source_methods( $zones )
			: array();
		$status['existingSourceMethods']     = $source_methods;
		$status['existingSourceMethodCount'] = count( $source_methods );
		$status['ready'] = $status['zoneCount'] > 0 && $status['zoneCount'] === $status['readyZoneCount'];
		$status['existingRequiresMirror'] = $status['zoneCount'] > 0 && $status['zoneCount'] !== $status['existingReadyZoneCount'] && ! empty( $source_methods );
		$status['existingReady'] = $status['zoneCount'] > 0 && (
			$status['zoneCount'] === $status['existingReadyZoneCount'] || ! empty( $source_methods )
		);
		return $status;
	}

	/**
	 * Normalize the store-product shipping configuration supplied by the wizard
	 * or persisted by a previous successful run.
	 *
	 * @param array $config Configuration.
	 * @return array
	 */
	private function sanitize_store_rate_config( $config ) {
		$saved_mode  = sanitize_key( (string) get_option( self::OPTION_STORE_RATE_MODE, self::STORE_RATE_MODE_EXISTING ) );
		$saved_title = sanitize_text_field( (string) get_option( self::OPTION_STORE_RATE_TITLE, 'ارسال محصولات فروشگاه' ) );
		$saved_cost  = (string) get_option( self::OPTION_STORE_RATE_COST, '0' );

		$mode = isset( $config['mode'] ) ? sanitize_key( (string) $config['mode'] ) : $saved_mode;
		if ( ! in_array( $mode, array( self::STORE_RATE_MODE_EXISTING, self::STORE_RATE_MODE_ENSURE_FLAT_RATE ), true ) ) {
			$mode = self::STORE_RATE_MODE_EXISTING;
		}

		$title = isset( $config['title'] ) ? sanitize_text_field( (string) $config['title'] ) : $saved_title;
		if ( '' === trim( $title ) ) {
			$title = 'ارسال محصولات فروشگاه';
		}

		$cost_raw = isset( $config['cost'] ) ? (string) $config['cost'] : $saved_cost;
		$cost_raw = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $cost_raw ) : preg_replace( '/[^0-9.\-]/', '', $cost_raw );
		$cost = is_numeric( $cost_raw ) ? max( 0, (float) $cost_raw ) : 0.0;

		return array(
			'mode'  => $mode,
			'title' => $title,
			'cost'  => $this->format_decimal_for_option( $cost ),
		);
	}

	/**
	 * Ensure ordinary store products have at least one valid rate in every zone
	 * that the Mobo installer can use for Iranian destinations.
	 *
	 * @param array $zones Iran-capable zones.
	 * @param array $config Store-rate configuration.
	 * @return array|WP_Error
	 */
	private function ensure_store_shipping_ready( $zones, $config ) {
		$result = array( 'created' => 0, 'updated' => 0, 'mirrored' => 0, 'disabled' => 0, 'zoneIds' => array(), 'warnings' => array() );
		if ( self::STORE_RATE_MODE_EXISTING === $config['mode'] ) {
			$source_methods = $this->find_existing_store_source_methods( $zones );
			$missing = array();

			foreach ( $zones as $zone_id => $zone ) {
				/* Prefer genuine methods already present in the destination Zone. */
				$genuine = $this->get_enabled_store_methods( $zone, false, false );
				if ( ! empty( $genuine ) ) {
					$result['disabled'] += $this->disable_existing_store_mirrors_for_zone( $zone );
					continue;
				}

				/* Keep mirrors synchronized with the real source method settings. */
				if ( ! empty( $source_methods ) ) {
					$mirrors = $this->ensure_existing_store_method_mirrors( $zone, $source_methods );
					if ( is_wp_error( $mirrors ) ) {
						return $mirrors;
					}
					$result['mirrored'] += absint( isset( $mirrors['created'] ) ? $mirrors['created'] : 0 );
					$result['updated']  += absint( isset( $mirrors['updated'] ) ? $mirrors['updated'] : 0 );
					$result['disabled'] += absint( isset( $mirrors['disabled'] ) ? $mirrors['disabled'] : 0 );
					$result['zoneIds'][] = absint( $zone_id );
				}

				$current = $this->get_enabled_store_methods( $zone, false, true );
				if ( empty( $current ) ) {
					$missing[] = method_exists( $zone, 'get_zone_name' ) ? sanitize_text_field( (string) $zone->get_zone_name() ) : ( 'Zone #' . absint( $zone_id ) );
				}
			}

			if ( ! empty( $missing ) ) {
				return new WP_Error(
					'mobo_store_shipping_missing',
					'برای محصولات غیرموبویی در این منطقه‌ها هیچ روش ارسال واقعی قابل استفاده پیدا نشد: ' . implode( '، ', $missing ) . '. در WooCommerce یک روش عادی فعال بسازید یا گزینه «ساخت Flat Rate پشتیبان» را انتخاب کنید.'
				);
			}

			/* Existing means existing: the old plugin fallback must disappear. */
			$result['disabled'] += $this->disable_managed_store_fallbacks( $zones );
			return $result;
		}

		/* Fallback mode must not leave mirrors from the other mode visible. */
		$result['disabled'] += $this->disable_existing_store_mirrors( $zones );
		foreach ( $zones as $zone_id => $zone ) {
			$instance = $this->ensure_managed_store_flat_rate( $zone, $config );
			if ( is_wp_error( $instance ) ) {
				return $instance;
			}
			$result['zoneIds'][] = absint( $zone_id );
			if ( ! empty( $instance['created'] ) ) {
				$result['created']++;
			} else {
				$result['updated']++;
			}
		}
		return $result;
	}

	/**
	 * Create or update one plugin-managed standard WooCommerce Flat Rate.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @param array            $config Configuration.
	 * @return array|WP_Error
	 */
	private function ensure_managed_store_flat_rate( $zone, $config ) {
		if ( ! $zone instanceof WC_Shipping_Zone ) {
			return new WP_Error( 'mobo_store_flat_rate_zone_invalid', 'منطقه حمل‌ونقل فروشگاه معتبر نیست.' );
		}

		$instance_id = 0;
		$created = false;
		$zone_id = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0;
		$fallback_map = get_option( self::OPTION_STORE_FALLBACKS, array() );
		$fallback_map = is_array( $fallback_map ) ? $fallback_map : array();
		$mapped_instance_id = $zone_id > 0 && isset( $fallback_map[ $zone_id ] ) ? absint( $fallback_map[ $zone_id ] ) : 0;

		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $existing ) {
			if ( ! is_object( $existing ) || ! isset( $existing->id ) || 'flat_rate' !== (string) $existing->id ) {
				continue;
			}
			$candidate_id = isset( $existing->instance_id ) ? absint( $existing->instance_id ) : 0;
			$settings = get_option( 'woocommerce_flat_rate_' . $candidate_id . '_settings', array() );
			$is_mapped = $mapped_instance_id > 0 && $candidate_id === $mapped_instance_id;
			$is_marked = $candidate_id > 0 && is_array( $settings ) && 'yes' === ( isset( $settings[ self::STORE_FALLBACK_MARKER ] ) ? $settings[ self::STORE_FALLBACK_MARKER ] : '' );
			if ( $is_mapped || $is_marked ) {
				$instance_id = $candidate_id;
				break;
			}
		}

		if ( $instance_id <= 0 ) {
			try {
				$instance_id = absint( $zone->add_shipping_method( 'flat_rate' ) );
				$created = $instance_id > 0;
			} catch ( Throwable $error ) {
				return new WP_Error( 'mobo_store_flat_rate_create_failed', 'ساخت Flat Rate محصولات فروشگاه ناموفق بود: ' . $error->getMessage() );
			}
		}
		if ( $instance_id <= 0 ) {
			return new WP_Error( 'mobo_store_flat_rate_instance_missing', 'WooCommerce شناسه Instance روش فروشگاه را برنگرداند.' );
		}

		$settings = array(
			'enabled' => 'yes',
			'title' => sanitize_text_field( (string) $config['title'] ),
			'tax_status' => 'none',
			'cost' => (string) $config['cost'],
			self::STORE_FALLBACK_MARKER => 'yes',
		);
		update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', $settings, false );
		$this->set_zone_method_state( $instance_id, true, 900 );
		if ( $zone_id > 0 ) {
			$fallback_map[ $zone_id ] = $instance_id;
			update_option( self::OPTION_STORE_FALLBACKS, $fallback_map, false );
		}
		return array( 'instanceId' => $instance_id, 'created' => $created );
	}

	/**
	 * Find one real WooCommerce store-method set outside the Mobo target Zones.
	 *
	 * A dedicated Mobo Iran Zone shadows broader store Zones during normal
	 * WooCommerce matching. Existing mode therefore mirrors the best current
	 * store method set into the target Zone instead of keeping the artificial
	 * fallback visible.
	 *
	 * @param array $target_zones Mobo target Zones.
	 * @return array
	 */
	private function find_existing_store_source_methods( $target_zones ) {
		$target_ids = array();
		foreach ( is_array( $target_zones ) ? $target_zones : array() as $zone_id => $zone ) {
			$target_ids[] = $zone instanceof WC_Shipping_Zone && method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : absint( $zone_id );
		}

		$candidates = array();
		$all = WC_Shipping_Zones::get_shipping_zones();
		foreach ( is_array( $all ) ? $all : array() as $zone_id => $zone ) {
			if ( $zone instanceof WC_Shipping_Zone ) {
				$candidates[ absint( $zone_id ) ] = $zone;
			}
		}
		try {
			$fallback_zone = new WC_Shipping_Zone( 0 );
			if ( $fallback_zone instanceof WC_Shipping_Zone ) {
				$candidates[0] = $fallback_zone;
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}

		$ranked = array();
		foreach ( $candidates as $zone_id => $zone ) {
			$actual_zone_id = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : absint( $zone_id );
			if ( in_array( $actual_zone_id, $target_ids, true ) ) {
				continue;
			}
			$methods = $this->get_enabled_store_methods( $zone, false, false );
			if ( empty( $methods ) ) {
				continue;
			}

			$score = 0 === $actual_zone_id ? 10 : 40;
			$locations = method_exists( $zone, 'get_zone_locations' ) ? $zone->get_zone_locations() : array();
			if ( empty( $locations ) && $actual_zone_id > 0 ) {
				$score += 30;
			}
			foreach ( is_array( $locations ) ? $locations : array() as $location ) {
				$type = isset( $location->type ) ? sanitize_key( (string) $location->type ) : '';
				$code = isset( $location->code ) ? strtoupper( (string) $location->code ) : '';
				if ( 'country' === $type && 'IR' === $code ) {
					$score += 100;
				} elseif ( 'state' === $type && 0 === strpos( $code, 'IR:' ) ) {
					$score += 110;
				} elseif ( 'continent' === $type && 'AS' === $code ) {
					$score += 80;
				}
			}
			$zone_name = method_exists( $zone, 'get_zone_name' ) ? sanitize_text_field( (string) $zone->get_zone_name() ) : ( 0 === $actual_zone_id ? 'بقیه دنیا' : 'Zone #' . $actual_zone_id );
			$normalized_name = self::normalize_text( $zone_name );
			if ( false !== strpos( $normalized_name, 'ایران' ) || false !== strpos( $normalized_name, 'iran' ) ) {
				$score += 90;
			}
			$ranked[] = array(
				'score'    => $score,
				'zoneId'   => $actual_zone_id,
				'zoneName' => $zone_name,
				'methods'  => $methods,
			);
		}

		if ( empty( $ranked ) ) {
			return array();
		}
		usort(
			$ranked,
			static function ( $left, $right ) {
				if ( absint( $left['score'] ) === absint( $right['score'] ) ) {
					return absint( $left['zoneId'] ) <=> absint( $right['zoneId'] );
				}
				return absint( $right['score'] ) <=> absint( $left['score'] );
			}
		);

		$selected = $ranked[0];
		$output = array();
		foreach ( $selected['methods'] as $method ) {
			$method_id   = sanitize_key( isset( $method['methodId'] ) ? (string) $method['methodId'] : '' );
			$instance_id = absint( isset( $method['instanceId'] ) ? $method['instanceId'] : 0 );
			if ( '' === $method_id || $instance_id <= 0 ) {
				continue;
			}
			$settings = get_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', array() );
			$output[] = array(
				'methodId'       => $method_id,
				'instanceId'     => $instance_id,
				'title'          => sanitize_text_field( isset( $method['title'] ) ? (string) $method['title'] : $method_id ),
				'sourceZoneId'   => absint( $selected['zoneId'] ),
				'sourceZoneName' => sanitize_text_field( (string) $selected['zoneName'] ),
				'settings'       => is_array( $settings ) ? $settings : array(),
			);
		}
		return $output;
	}

	/**
	 * Mirror real existing methods into one Mobo target Zone.
	 *
	 * @param WC_Shipping_Zone $zone Target Zone.
	 * @param array            $source_methods Source method definitions.
	 * @return array|WP_Error
	 */
	private function ensure_existing_store_method_mirrors( $zone, $source_methods ) {
		if ( ! $zone instanceof WC_Shipping_Zone ) {
			return new WP_Error( 'mobo_store_existing_mirror_zone_invalid', 'منطقه مقصد برای اتصال روش‌های فعلی فروشگاه معتبر نیست.' );
		}
		$result = array( 'created' => 0, 'updated' => 0, 'disabled' => 0 );
		$zone_id = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0;
		$map = get_option( self::OPTION_STORE_EXISTING_MIRRORS, array() );
		$map = is_array( $map ) ? $map : array();
		if ( ! isset( $map[ $zone_id ] ) || ! is_array( $map[ $zone_id ] ) ) {
			$map[ $zone_id ] = array();
		}

		$active_source_keys = array();
		foreach ( is_array( $source_methods ) ? $source_methods : array() as $source ) {
			$method_id          = sanitize_key( isset( $source['methodId'] ) ? (string) $source['methodId'] : '' );
			$source_zone_id     = absint( isset( $source['sourceZoneId'] ) ? $source['sourceZoneId'] : 0 );
			$source_instance_id = absint( isset( $source['instanceId'] ) ? $source['instanceId'] : 0 );
			if ( '' === $method_id || $source_instance_id <= 0 ) {
				continue;
			}
			$source_key = $source_zone_id . ':' . $method_id . ':' . $source_instance_id;
			$active_source_keys[] = $source_key;
			$instance_id = isset( $map[ $zone_id ][ $source_key ] ) ? absint( $map[ $zone_id ][ $source_key ] ) : 0;
			$created = false;

			foreach ( $zone->get_shipping_methods( false, 'admin' ) as $existing ) {
				if ( ! is_object( $existing ) || ! isset( $existing->id ) || $method_id !== sanitize_key( (string) $existing->id ) ) {
					continue;
				}
				$candidate_id = isset( $existing->instance_id ) ? absint( $existing->instance_id ) : 0;
				$settings = get_option( 'woocommerce_' . $method_id . '_' . $candidate_id . '_settings', array() );
				$is_marked = is_array( $settings )
					&& 'yes' === ( isset( $settings[ self::STORE_EXISTING_MIRROR_MARKER ] ) ? $settings[ self::STORE_EXISTING_MIRROR_MARKER ] : '' )
					&& $source_zone_id === absint( isset( $settings[ self::STORE_EXISTING_SOURCE_ZONE ] ) ? $settings[ self::STORE_EXISTING_SOURCE_ZONE ] : 0 )
					&& $source_instance_id === absint( isset( $settings[ self::STORE_EXISTING_SOURCE_INSTANCE ] ) ? $settings[ self::STORE_EXISTING_SOURCE_INSTANCE ] : 0 );
				if ( $candidate_id > 0 && ( $candidate_id === $instance_id || $is_marked ) ) {
					$instance_id = $candidate_id;
					break;
				}
			}

			if ( $instance_id <= 0 ) {
				try {
					$instance_id = absint( $zone->add_shipping_method( $method_id ) );
					$created = $instance_id > 0;
				} catch ( Throwable $error ) {
					return new WP_Error( 'mobo_store_existing_mirror_create_failed', 'اتصال روش فعلی فروشگاه «' . sanitize_text_field( isset( $source['title'] ) ? (string) $source['title'] : $method_id ) . '» ناموفق بود: ' . $error->getMessage() );
				}
			}
			if ( $instance_id <= 0 ) {
				return new WP_Error( 'mobo_store_existing_mirror_instance_missing', 'WooCommerce شناسه روش متصل‌شده فروشگاه را برنگرداند.' );
			}

			$settings = isset( $source['settings'] ) && is_array( $source['settings'] ) ? $source['settings'] : array();
			$settings['enabled'] = 'yes';
			if ( empty( $settings['title'] ) && ! empty( $source['title'] ) ) {
				$settings['title'] = sanitize_text_field( (string) $source['title'] );
			}
			$settings[ self::STORE_EXISTING_MIRROR_MARKER ]   = 'yes';
			$settings[ self::STORE_EXISTING_SOURCE_ZONE ]     = $source_zone_id;
			$settings[ self::STORE_EXISTING_SOURCE_INSTANCE ] = $source_instance_id;
			$settings[ self::STORE_EXISTING_SOURCE_METHOD ]   = $method_id;
			update_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', $settings, false );
			$this->set_zone_method_state( $instance_id, true, 850 + $result['created'] + $result['updated'] );
			$map[ $zone_id ][ $source_key ] = $instance_id;
			if ( $created ) {
				$result['created']++;
			} else {
				$result['updated']++;
			}
		}
		/* Disable mirrors whose source method is no longer part of the selected real Zone. */
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $existing ) {
			if ( ! is_object( $existing ) || ! isset( $existing->id ) ) {
				continue;
			}
			$existing_method_id = sanitize_key( (string) $existing->id );
			$existing_instance_id = isset( $existing->instance_id ) ? absint( $existing->instance_id ) : 0;
			$settings = get_option( 'woocommerce_' . $existing_method_id . '_' . $existing_instance_id . '_settings', array() );
			if ( ! is_array( $settings ) || 'yes' !== ( isset( $settings[ self::STORE_EXISTING_MIRROR_MARKER ] ) ? $settings[ self::STORE_EXISTING_MIRROR_MARKER ] : '' ) ) {
				continue;
			}
			$source_key = absint( isset( $settings[ self::STORE_EXISTING_SOURCE_ZONE ] ) ? $settings[ self::STORE_EXISTING_SOURCE_ZONE ] : 0 ) . ':'
				. sanitize_key( isset( $settings[ self::STORE_EXISTING_SOURCE_METHOD ] ) ? (string) $settings[ self::STORE_EXISTING_SOURCE_METHOD ] : $existing_method_id ) . ':'
				. absint( isset( $settings[ self::STORE_EXISTING_SOURCE_INSTANCE ] ) ? $settings[ self::STORE_EXISTING_SOURCE_INSTANCE ] : 0 );
			if ( in_array( $source_key, $active_source_keys, true ) ) {
				continue;
			}
			$was_enabled = $this->get_zone_method_enabled( $existing_instance_id, 'yes' === ( isset( $settings['enabled'] ) ? $settings['enabled'] : 'no' ) );
			$settings['enabled'] = 'no';
			update_option( 'woocommerce_' . $existing_method_id . '_' . $existing_instance_id . '_settings', $settings, false );
			$this->set_zone_method_state( $existing_instance_id, false, null );
			if ( $was_enabled ) {
				$result['disabled']++;
			}
		}

		update_option( self::OPTION_STORE_EXISTING_MIRRORS, $map, false );
		return $result;
	}

	/**
	 * Disable existing-method mirrors in one Zone.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return int
	 */
	private function disable_existing_store_mirrors_for_zone( $zone ) {
		$count = 0;
		if ( ! $zone instanceof WC_Shipping_Zone ) {
			return $count;
		}
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
			if ( ! is_object( $method ) || ! isset( $method->id ) ) {
				continue;
			}
			$method_id = sanitize_key( (string) $method->id );
			$instance_id = isset( $method->instance_id ) ? absint( $method->instance_id ) : 0;
			if ( '' === $method_id || $instance_id <= 0 ) {
				continue;
			}
			$settings = get_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', array() );
			if ( ! is_array( $settings ) || 'yes' !== ( isset( $settings[ self::STORE_EXISTING_MIRROR_MARKER ] ) ? $settings[ self::STORE_EXISTING_MIRROR_MARKER ] : '' ) ) {
				continue;
			}
			$was_enabled = $this->get_zone_method_enabled( $instance_id, 'yes' === ( isset( $settings['enabled'] ) ? $settings['enabled'] : 'no' ) );
			$settings['enabled'] = 'no';
			update_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', $settings, false );
			$this->set_zone_method_state( $instance_id, false, null );
			if ( $was_enabled ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Disable all mirrors in target Zones.
	 *
	 * @param array $zones Zones.
	 * @return int
	 */
	private function disable_existing_store_mirrors( $zones ) {
		$count = 0;
		foreach ( is_array( $zones ) ? $zones : array() as $zone ) {
			$count += $this->disable_existing_store_mirrors_for_zone( $zone );
		}
		return $count;
	}

	/**
	 * Return enabled non-Mobo methods available to ordinary store products.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @param bool             $include_managed_fallback Include plugin fallback.
	 * @param bool             $include_existing_mirror Include connected existing-method mirrors.
	 * @return array
	 */
	private function get_enabled_store_methods( $zone, $include_managed_fallback = true, $include_existing_mirror = true ) {
		$output = array();
		if ( ! $zone instanceof WC_Shipping_Zone ) {
			return $output;
		}
		$zone_id = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0;
		$fallback_map = get_option( self::OPTION_STORE_FALLBACKS, array() );
		$fallback_map = is_array( $fallback_map ) ? $fallback_map : array();
		$mapped_instance_id = $zone_id > 0 && isset( $fallback_map[ $zone_id ] ) ? absint( $fallback_map[ $zone_id ] ) : 0;
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
			if ( ! is_object( $method ) || ! isset( $method->id ) ) {
				continue;
			}
			$method_id = sanitize_key( (string) $method->id );
			if ( self::METHOD_ID === $method_id ) {
				continue;
			}
			$instance_id = isset( $method->instance_id ) ? absint( $method->instance_id ) : 0;
			if ( $instance_id <= 0 || ! $this->get_zone_method_enabled( $instance_id, isset( $method->enabled ) ? 'yes' === $method->enabled : true ) ) {
				continue;
			}
			$settings = get_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings', array() );
			$managed_fallback = 'flat_rate' === $method_id && (
				( $mapped_instance_id > 0 && $instance_id === $mapped_instance_id )
				|| ( is_array( $settings ) && 'yes' === ( isset( $settings[ self::STORE_FALLBACK_MARKER ] ) ? $settings[ self::STORE_FALLBACK_MARKER ] : '' ) )
			);
			$managed_mirror = is_array( $settings ) && 'yes' === ( isset( $settings[ self::STORE_EXISTING_MIRROR_MARKER ] ) ? $settings[ self::STORE_EXISTING_MIRROR_MARKER ] : '' );
			if ( $managed_fallback && ! $include_managed_fallback ) {
				continue;
			}
			if ( $managed_mirror && ! $include_existing_mirror ) {
				continue;
			}
			$title = method_exists( $method, 'get_title' ) ? $method->get_title() : ( isset( $method->title ) ? $method->title : $method_id );
			if ( is_array( $settings ) && ! empty( $settings['title'] ) ) {
				$title = $settings['title'];
			}
			$output[] = array(
				'methodId'       => $method_id,
				'instanceId'     => $instance_id,
				'title'          => sanitize_text_field( (string) $title ),
				'managedFallback'=> $managed_fallback,
				'managedMirror'  => $managed_mirror,
			);
		}
		return $output;
	}

	/**
	 * Disable only Flat Rates explicitly created by this plugin.
	 *
	 * @param array $zones Zones.
	 * @return int
	 */
	private function disable_managed_store_fallbacks( $zones ) {
		$count = 0;
		$fallback_map = get_option( self::OPTION_STORE_FALLBACKS, array() );
		$fallback_map = is_array( $fallback_map ) ? $fallback_map : array();
		foreach ( $zones as $zone ) {
			if ( ! $zone instanceof WC_Shipping_Zone ) {
				continue;
			}
			$zone_id = method_exists( $zone, 'get_id' ) ? absint( $zone->get_id() ) : 0;
			$mapped_instance_id = $zone_id > 0 && isset( $fallback_map[ $zone_id ] ) ? absint( $fallback_map[ $zone_id ] ) : 0;
			foreach ( $zone->get_shipping_methods( false, 'admin' ) as $method ) {
				if ( ! is_object( $method ) || ! isset( $method->id ) || 'flat_rate' !== (string) $method->id ) {
					continue;
				}
				$instance_id = isset( $method->instance_id ) ? absint( $method->instance_id ) : 0;
				$settings = get_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array() );
				$is_mapped = $mapped_instance_id > 0 && $instance_id === $mapped_instance_id;
				$is_marked = is_array( $settings ) && 'yes' === ( isset( $settings[ self::STORE_FALLBACK_MARKER ] ) ? $settings[ self::STORE_FALLBACK_MARKER ] : '' );
				if ( $instance_id <= 0 || ( ! $is_mapped && ! $is_marked ) ) {
					continue;
				}
				$was_enabled = $this->get_zone_method_enabled( $instance_id, 'yes' === ( isset( $settings['enabled'] ) ? $settings['enabled'] : 'no' ) );
				$settings['enabled'] = 'no';
				update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', $settings, false );
				$this->set_zone_method_state( $instance_id, false, null );
				if ( $was_enabled ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Locate Iran-capable zones without creating any configuration.
	 *
	 * @return array<int,WC_Shipping_Zone>
	 */
	private function get_iran_zones() {
		$zones = array();
		$all = WC_Shipping_Zones::get_shipping_zones();
		foreach ( is_array( $all ) ? $all : array() as $zone_id => $zone ) {
			if ( $zone instanceof WC_Shipping_Zone && $this->zone_can_match_iran( $zone ) ) {
				$zones[ absint( $zone_id ) ] = $zone;
			}
		}
		return $zones;
	}

	/**
	 * Format a non-negative decimal for WooCommerce instance options.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function format_decimal_for_option( $value ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (string) wc_format_decimal( max( 0, (float) $value ), false, true );
		}
		$value = max( 0, (float) $value );
		return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Calculate one managed Mobo shipping rate.
	 *
	 * @param int   $shipping_id Mobo shipping ID.
	 * @param array $package WooCommerce package.
	 * @return array
	 */
	public static function calculate_rate_for_package( $shipping_id, $package ) {
		$unavailable = array( 'available' => false, 'cost' => null, 'reason' => 'unavailable', 'method' => array() );
		if ( ! Mobo_Core_Settings::enabled( self::OPTION_ENABLED, '0' ) || ! class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
			return $unavailable;
		}

		$manager = new Mobo_Core_Remote_Shipping_Methods();
		$method  = null;
		foreach ( $manager->get_methods() as $candidate ) {
			if ( absint( isset( $candidate['id'] ) ? $candidate['id'] : 0 ) === absint( $shipping_id ) ) {
				$method = $candidate;
				break;
			}
		}

		if ( ! is_array( $method ) ) {
			return $unavailable;
		}

		$context = self::build_package_context( is_array( $package ) ? $package : array() );
		if ( empty( $context['moboItemCount'] ) ) {
			$unavailable['reason'] = 'no-mobo-items';
			return $unavailable;
		}

		if ( ! self::matches_destination( $method, $context['destination'] ) ) {
			$unavailable['reason'] = 'destination';
			return $unavailable;
		}

		if ( ! self::matches_bounds( $method, $context['moboSubtotal'], $context['moboWeightGrams'] ) ) {
			$unavailable['reason'] = 'bounds';
			return $unavailable;
		}

		$source_cost = self::resolve_source_cost( $method, $context['moboSubtotal'], $context['moboWeightGrams'] );
		if ( null === $source_cost ) {
			$unavailable['reason'] = 'no-matching-cost';
			return $unavailable;
		}

		return array(
			'available' => true,
			'cost'      => self::source_amount_to_store_amount( $source_cost ),
			'sourceCost'=> $source_cost,
			'reason'    => 'ok',
			'method'    => $method,
			'context'   => $context,
		);
	}

	/**
	 * Resolve shipping ID stored for a method instance.
	 *
	 * @param int $instance_id Instance ID.
	 * @return int
	 */
	public function get_shipping_id_for_instance( $instance_id ) {
		$instance_id = absint( $instance_id );
		if ( $instance_id <= 0 ) {
			return 0;
		}
		$settings = get_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', array() );
		return is_array( $settings ) && isset( $settings['mobo_shipping_id'] ) ? absint( $settings['mobo_shipping_id'] ) : 0;
	}

	/**
	 * Ensure Mobo product shipping class exists.
	 *
	 * @return int|WP_Error
	 */
	private function ensure_shipping_class() {
		if ( ! taxonomy_exists( 'product_shipping_class' ) ) {
			return new WP_Error( 'mobo_shipping_class_taxonomy_missing', 'WooCommerce product_shipping_class taxonomy is not available.' );
		}

		$term = get_term_by( 'slug', self::CLASS_SLUG, 'product_shipping_class' );
		if ( $term && ! is_wp_error( $term ) ) {
			return absint( $term->term_id );
		}

		$inserted = wp_insert_term(
			self::CLASS_NAME,
			'product_shipping_class',
			array(
				'slug'        => self::CLASS_SLUG,
				'description' => 'کلاس مجازی محصولات موبو؛ فقط برای محاسبه حمل‌ونقل استفاده می‌شود.',
			)
		);

		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		return absint( isset( $inserted['term_id'] ) ? $inserted['term_id'] : 0 );
	}

	/**
	 * Locate Iran-capable zones or create one safe fallback zone.
	 *
	 * @param array $result Mutable installer result.
	 * @return array<int,WC_Shipping_Zone>
	 */
	private function get_or_create_iran_zones( &$result ) {
		$zones = array();
		$all   = WC_Shipping_Zones::get_shipping_zones();
		foreach ( is_array( $all ) ? $all : array() as $zone_id => $zone ) {
			if ( $zone instanceof WC_Shipping_Zone && $this->zone_can_match_iran( $zone ) ) {
				$zones[ absint( $zone_id ) ] = $zone;
			}
		}

		if ( ! empty( $zones ) ) {
			return $zones;
		}

		try {
			$zone = new WC_Shipping_Zone();
			$zone->set_zone_name( self::DEFAULT_ZONE_NAME );
			$zone->set_zone_order( 0 );
			$zone->add_location( 'IR', 'country' );
			$zone->save();
			$zone_id = absint( $zone->get_id() );
			if ( $zone_id > 0 ) {
				$zones[ $zone_id ] = $zone;
				$result['createdZones'][] = $zone_id;
			}
		} catch ( Throwable $error ) {
			$result['warnings'][] = 'ساخت منطقه ایران ناموفق بود: ' . $error->getMessage();
		}

		return $zones;
	}

	/**
	 * Does a WooCommerce zone explicitly or broadly cover Iran?
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @return bool
	 */
	private function zone_can_match_iran( $zone ) {
		if ( ! $zone instanceof WC_Shipping_Zone || ! method_exists( $zone, 'get_zone_locations' ) ) {
			return false;
		}
		$zone_name = method_exists( $zone, 'get_zone_name' ) ? self::normalize_text( $zone->get_zone_name() ) : '';
		if ( false !== strpos( $zone_name, 'ایران' ) || false !== strpos( $zone_name, 'تهران' ) || false !== strpos( $zone_name, 'iran' ) || false !== strpos( $zone_name, 'tehran' ) ) {
			return true;
		}
		foreach ( $zone->get_zone_locations() as $location ) {
			$type = isset( $location->type ) ? sanitize_key( (string) $location->type ) : '';
			$code = isset( $location->code ) ? strtoupper( (string) $location->code ) : '';
			if ( 'country' === $type && 'IR' === $code ) {
				return true;
			}
			if ( 'state' === $type && 0 === strpos( $code, 'IR:' ) ) {
				return true;
			}
			if ( 'continent' === $type && 'AS' === $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ensure one Mobo-owned method instance exists in a zone.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @param array            $method Mobo method.
	 * @return array|WP_Error
	 */
	private function ensure_method_instance( $zone, $method ) {
		$shipping_id = absint( isset( $method['id'] ) ? $method['id'] : 0 );
		if ( ! $zone instanceof WC_Shipping_Zone || $shipping_id <= 0 ) {
			return new WP_Error( 'mobo_shipping_instance_invalid', 'Invalid zone or Mobo shipping method.' );
		}

		$instance_id = 0;
		$created     = false;
		foreach ( $zone->get_shipping_methods( false, 'admin' ) as $existing ) {
			if ( ! is_object( $existing ) || ! isset( $existing->id ) || self::METHOD_ID !== (string) $existing->id ) {
				continue;
			}
			$candidate_id = isset( $existing->instance_id ) ? absint( $existing->instance_id ) : 0;
			if ( $candidate_id > 0 && $this->get_shipping_id_for_instance( $candidate_id ) === $shipping_id ) {
				$instance_id = $candidate_id;
				break;
			}
		}

		if ( $instance_id <= 0 ) {
			try {
				$instance_id = absint( $zone->add_shipping_method( self::METHOD_ID ) );
				$created     = $instance_id > 0;
			} catch ( Throwable $error ) {
				return new WP_Error( 'mobo_shipping_instance_create_failed', 'ساخت روش ارسال «' . ( isset( $method['title'] ) ? $method['title'] : $shipping_id ) . '» ناموفق بود: ' . $error->getMessage() );
			}
		}

		if ( $instance_id <= 0 ) {
			return new WP_Error( 'mobo_shipping_instance_missing', 'WooCommerce did not return a shipping method instance ID.' );
		}

		$existing_settings = get_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', array() );
		$default_enabled   = ! $this->is_sensitive_operational_method( $method );
		$enabled           = $created
			? $default_enabled
			: $this->get_zone_method_enabled(
				$instance_id,
				is_array( $existing_settings ) && isset( $existing_settings['enabled'] ) ? 'yes' === $existing_settings['enabled'] : $default_enabled
			);
		$settings = array(
			'enabled'              => $enabled ? 'yes' : 'no',
			'title'                => sanitize_text_field( isset( $method['title'] ) ? $method['title'] : ( 'روش ارسال موبو #' . $shipping_id ) ),
			'mobo_shipping_id'     => $shipping_id,
			'mobo_shipping_type'   => sanitize_key( isset( $method['type'] ) ? $method['type'] : '' ),
			'mobo_description'      => sanitize_textarea_field( isset( $method['description'] ) ? $method['description'] : '' ),
			'mobo_destination_scope' => self::get_method_destination_scope( $method ),
			'managed_by_mobo_core'  => 'yes',
		);
		update_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', $settings, false );
		$this->set_zone_method_state(
			$instance_id,
			$enabled,
			1000 + max( 0, absint( isset( $method['position'] ) ? $method['position'] : 0 ) )
		);

		return array( 'instanceId' => $instance_id, 'created' => $created, 'enabled' => $enabled );
	}

	/**
	 * Disable plugin-owned instances whose shipping ID is no longer active.
	 *
	 * @param array $zones Zone objects.
	 * @param array $active_ids Active Mobo IDs.
	 * @return int
	 */
	private function disable_stale_managed_instances( $zones, $active_ids ) {
		$count      = 0;
		$active_ids = array_map( 'absint', is_array( $active_ids ) ? $active_ids : array() );
		foreach ( $zones as $zone ) {
			if ( ! $zone instanceof WC_Shipping_Zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( false, 'admin' ) as $existing ) {
				if ( ! is_object( $existing ) || ! isset( $existing->id ) || self::METHOD_ID !== (string) $existing->id ) {
					continue;
				}
				$instance_id = isset( $existing->instance_id ) ? absint( $existing->instance_id ) : 0;
				$settings    = get_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', array() );
				if ( ! is_array( $settings ) || 'yes' !== ( isset( $settings['managed_by_mobo_core'] ) ? $settings['managed_by_mobo_core'] : '' ) ) {
					continue;
				}
				$shipping_id = isset( $settings['mobo_shipping_id'] ) ? absint( $settings['mobo_shipping_id'] ) : 0;
				if ( $shipping_id > 0 && in_array( $shipping_id, $active_ids, true ) ) {
					continue;
				}
				$was_enabled = $this->get_zone_method_enabled( $instance_id, 'yes' === ( isset( $settings['enabled'] ) ? $settings['enabled'] : 'no' ) );
				$settings['enabled'] = 'no';
				update_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', $settings, false );
				$this->set_zone_method_state( $instance_id, false, null );
				if ( $was_enabled ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Read the authoritative Zone-table enabled state for one method instance.
	 *
	 * @param int  $instance_id Instance ID.
	 * @param bool $fallback Fallback state.
	 * @return bool
	 */
	private function get_zone_method_enabled( $instance_id, $fallback ) {
		if ( class_exists( 'WC_Data_Store' ) ) {
			try {
				$store = WC_Data_Store::load( 'shipping-zone' );
				$row   = is_object( $store ) && method_exists( $store, 'get_method' ) ? $store->get_method( absint( $instance_id ) ) : null;
				if ( is_object( $row ) && isset( $row->is_enabled ) ) {
					return ! empty( $row->is_enabled );
				}
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
		return (bool) $fallback;
	}

	/**
	 * Persist enabled/order state in WooCommerce's authoritative Zone table.
	 *
	 * WooCommerce keeps this state separately from the instance option. Both are
	 * updated so Checkout, Blocks, the Zone list, and direct instantiation agree.
	 *
	 * @param int      $instance_id Instance ID.
	 * @param bool     $enabled Enabled state.
	 * @param int|null $method_order Optional order.
	 * @return void
	 */
	private function set_zone_method_state( $instance_id, $enabled, $method_order = null ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) || empty( $wpdb->prefix ) ) {
			return;
		}

		$data   = array( 'is_enabled' => $enabled ? 1 : 0 );
		$format = array( '%d' );
		if ( null !== $method_order ) {
			$data['method_order'] = absint( $method_order );
			$format[]             = '%d';
		}

		$wpdb->update(
			$wpdb->prefix . 'woocommerce_shipping_zone_methods',
			$data,
			array( 'instance_id' => absint( $instance_id ) ),
			$format,
			array( '%d' )
		);

		if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'get_transient_version' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/**
	 * Save explicit method-instance mappings for both Mobo order scenarios.
	 *
	 * @param Mobo_Core_Remote_Shipping_Methods $remote Remote manager.
	 * @param int                               $zone_id Zone ID.
	 * @param int                               $instance_id Instance ID.
	 * @param int                               $shipping_id Mobo ID.
	 * @return void
	 */
	private function save_automatic_mapping( $remote, $zone_id, $instance_id, $shipping_id ) {
		foreach ( array( 'mobo_only', 'mixed' ) as $scenario ) {
			$key = $remote->build_wc_method_rule_option_key( $zone_id, self::METHOD_ID, $instance_id, $scenario );
			update_option( $key, absint( $shipping_id ), false );
		}
		$legacy = $remote->build_legacy_wc_method_rule_option_key( $zone_id, self::METHOD_ID, $instance_id );
		update_option( $legacy, absint( $shipping_id ), false );
	}

	/**
	 * Preserve existing COD restrictions and add Mobo methods that support COD.
	 *
	 * An empty WooCommerce COD restriction list means COD is already available for
	 * all shipping methods, so it is intentionally left untouched.
	 *
	 * @param array $rate_ids Canonical method:instance IDs.
	 * @return void
	 */
	private function merge_cod_method_restrictions( $rate_ids ) {
		$settings = get_option( 'woocommerce_cod_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['enable_for_methods'] ) || ! is_array( $settings['enable_for_methods'] ) ) {
			return;
		}
		$settings['enable_for_methods'] = array_values( array_unique( array_merge( $settings['enable_for_methods'], array_filter( array_map( 'sanitize_text_field', $rate_ids ) ) ) ) );
		update_option( 'woocommerce_cod_settings', $settings, false );
	}

	/**
	 * Sensitive operational choices must be reviewed before public checkout use.
	 *
	 * @param array $method Method.
	 * @return bool
	 */
	private function is_sensitive_operational_method( $method ) {
		$title = self::normalize_text( isset( $method['title'] ) ? $method['title'] : '' );
		$needles = array( 'فاکتور قبلی', 'اضافه شود به فاکتور', 'نگه داری در انبار', 'نگهداری در انبار', 'تحویل حضوری' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $title, self::normalize_text( $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build Mobo-only subtotal and weight from a WooCommerce shipping package.
	 *
	 * @param array $package Package.
	 * @return array
	 */
	private static function build_package_context( $package ) {
		$subtotal = 0.0;
		$weight   = 0.0;
		$count    = 0;
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();

		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product      = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
			$product_id   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : ( $product ? absint( $product->get_id() ) : 0 );
			$variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
			if ( ! self::is_mobo_product( $product, $product_id, $variation_id ) ) {
				continue;
			}

			$quantity = isset( $item['quantity'] ) ? max( 0.0, (float) $item['quantity'] ) : 0.0;
			$count   += $quantity > 0 ? 1 : 0;
			$api_price = self::get_mobo_api_price( $variation_id, $product_id );
			if ( null !== $api_price && Mobo_Core_Settings::enabled( 'mobo_core_mobo_shipping_use_api_price', '1' ) ) {
				$subtotal += $api_price * $quantity;
			} else {
				$line_total = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0;
				$subtotal  += self::store_amount_to_source_amount( $line_total );
			}

			if ( $product && method_exists( $product, 'get_weight' ) ) {
				$product_weight = $product->get_weight();
				if ( is_numeric( $product_weight ) ) {
					$grams = function_exists( 'wc_get_weight' ) ? wc_get_weight( (float) $product_weight, 'g' ) : (float) $product_weight;
					$weight += max( 0.0, (float) $grams ) * $quantity;
				}
			}
		}

		return array(
			'moboSubtotal'   => $subtotal,
			'moboWeightGrams' => $weight,
			'moboItemCount'  => $count,
			'destination'    => isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array(),
		);
	}

	/**
	 * Evaluate explicit Mobo location restrictions and safe Tehran title hints.
	 *
	 * @param array $method Method.
	 * @param array $destination Destination.
	 * @return bool
	 */
	private static function matches_destination( $method, $destination ) {
		$country = strtoupper( sanitize_text_field( isset( $destination['country'] ) ? $destination['country'] : '' ) );
		$state   = sanitize_text_field( isset( $destination['state'] ) ? $destination['state'] : '' );
		$city    = sanitize_text_field( isset( $destination['city'] ) ? $destination['city'] : '' );

		if ( '' !== $country && 'IR' !== $country && ! is_numeric( $country ) ) {
			return false;
		}

		$resolved = array( 'countryId' => 0, 'stateId' => 0, 'cityId' => 0 );
		if ( class_exists( 'Mobo_Core_Address_Mapping' ) ) {
			$mapper = new Mobo_Core_Address_Mapping();
			if ( method_exists( $mapper, 'resolve_values_to_mobo_location' ) ) {
				$value = $mapper->resolve_values_to_mobo_location( $country, $state, $city );
				if ( ! is_wp_error( $value ) && is_array( $value ) ) {
					$resolved = array_merge( $resolved, $value );
				}
			}
		}

		if ( ! self::location_list_matches( isset( $method['countries'] ) ? $method['countries'] : array(), absint( $resolved['countryId'] ), $country, 'country' ) ) {
			return false;
		}

		$scope = self::get_method_destination_scope( $method );
		if ( 'tehran_only' === $scope ) {
			return self::destination_is_tehran( $country, $state, absint( $resolved['stateId'] ) );
		}
		if ( 'iran_wide' === $scope ) {
			return true;
		}

		if ( ! self::location_list_matches( isset( $method['states'] ) ? $method['states'] : array(), absint( $resolved['stateId'] ), $state, 'state' ) ) {
			return false;
		}
		if ( ! self::location_list_matches( isset( $method['cities'] ) ? $method['cities'] : array(), absint( $resolved['cityId'] ), $city, 'city' ) ) {
			return false;
		}

		return true;
	}


	/**
	 * Resolve the authoritative destination scope used by Repair and Checkout.
	 *
	 * Courier/pickup-delivery methods are Tehran-only. The Mobo drop-shipping
	 * postal method is nationwide, even though its title mentions Tehran as part
	 * of the delivery-time description. Explicit source state/city restrictions
	 * remain authoritative for every other method.
	 *
	 * @param array $method Remote method.
	 * @return string tehran_only|iran_wide|source_restricted
	 */
	private static function get_method_destination_scope( $method ) {
		$id    = absint( isset( $method['id'] ) ? $method['id'] : 0 );
		$title = self::normalize_text( isset( $method['title'] ) ? $method['title'] : '' );

		if ( 148395514 === $id || false !== strpos( $title, 'دراپ شیپینگ' ) ) {
			return 'iran_wide';
		}

		if ( false !== strpos( $title, 'پیک' ) ) {
			return 'tehran_only';
		}

		if ( ! empty( $method['states'] ) || ! empty( $method['cities'] ) ) {
			return 'source_restricted';
		}

		if ( false !== strpos( $title, 'تهران' ) && false === strpos( $title, 'شهرستان' ) ) {
			return 'tehran_only';
		}

		return 'iran_wide';
	}

	/**
	 * Match one normalized location list.
	 *
	 * @param array  $items Remote locations.
	 * @param int    $resolved_id Resolved Mobo ID.
	 * @param string $local_value Local code/name.
	 * @param string $type Location type.
	 * @return bool
	 */
	private static function location_list_matches( $items, $resolved_id, $local_value, $type ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return true;
		}
		$local_normalized = self::normalize_text( $local_value );
		foreach ( $items as $item ) {
			$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$name = isset( $item['name'] ) ? self::normalize_text( $item['name'] ) : '';
			if ( $resolved_id > 0 && $id === $resolved_id ) {
				return true;
			}
			if ( '' !== $local_normalized && '' !== $name && ( $local_normalized === $name || false !== strpos( $name, $local_normalized ) || false !== strpos( $local_normalized, $name ) ) ) {
				return true;
			}
			if ( 'country' === $type && 'IR' === strtoupper( (string) $local_value ) && ( 'ایران' === $name || 'iran' === $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determine Tehran using Mobo mapping first, WooCommerce state labels second.
	 *
	 * @param string $country Country.
	 * @param string $state State.
	 * @param int    $mobo_state_id Mobo state ID.
	 * @return bool
	 */
	private static function destination_is_tehran( $country, $state, $mobo_state_id ) {
		if ( $mobo_state_id > 0 && class_exists( 'Mobo_Core_Address_Mapping' ) ) {
			$mapper = new Mobo_Core_Address_Mapping();
			if ( method_exists( $mapper, 'is_mobo_state_tehran' ) && $mapper->is_mobo_state_tehran( $mobo_state_id ) ) {
				return true;
			}
		}

		$normalized_state = self::normalize_text( $state );
		if ( false !== strpos( $normalized_state, 'تهران' ) || 'tehran' === $normalized_state ) {
			return true;
		}

		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && method_exists( WC()->countries, 'get_states' ) ) {
			$states = WC()->countries->get_states( '' !== $country ? $country : 'IR' );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) {
				$name = self::normalize_text( $states[ $state ] );
				return false !== strpos( $name, 'تهران' ) || 'tehran' === $name;
			}
		}

		return false;
	}

	/**
	 * Match method-level amount/weight bounds.
	 *
	 * @param array $method Method.
	 * @param float $subtotal Source subtotal.
	 * @param float $weight_grams Weight in grams.
	 * @return bool
	 */
	private static function matches_bounds( $method, $subtotal, $weight_grams ) {
		return self::number_in_range( $subtotal, isset( $method['minimum_subtotal'] ) ? $method['minimum_subtotal'] : null, isset( $method['maximum_subtotal'] ) ? $method['maximum_subtotal'] : null )
			&& self::number_in_range( $weight_grams, isset( $method['minimum_weight'] ) ? $method['minimum_weight'] : null, isset( $method['maximum_weight'] ) ? $method['maximum_weight'] : null );
	}

	/**
	 * Resolve source-currency cost for free/static/rules methods.
	 *
	 * @param array $method Method.
	 * @param float $subtotal Source subtotal.
	 * @param float $weight_grams Weight.
	 * @return float|null
	 */
	private static function resolve_source_cost( $method, $subtotal, $weight_grams ) {
		$type = sanitize_key( isset( $method['type'] ) ? $method['type'] : '' );
		$cost = null;

		if ( 'rules' === $type ) {
			$rules = isset( $method['rules'] ) && is_array( $method['rules'] ) ? $method['rules'] : array();
			foreach ( $rules as $rule ) {
				if ( ! self::number_in_range( $subtotal, isset( $rule['minimum_subtotal'] ) ? $rule['minimum_subtotal'] : null, isset( $rule['maximum_subtotal'] ) ? $rule['maximum_subtotal'] : null ) ) {
					continue;
				}
				if ( ! self::number_in_range( $weight_grams, isset( $rule['minimum_weight'] ) ? $rule['minimum_weight'] : null, isset( $rule['maximum_weight'] ) ? $rule['maximum_weight'] : null ) ) {
					continue;
				}
				if ( isset( $rule['cost'] ) && is_numeric( $rule['cost'] ) ) {
					$cost = max( 0.0, (float) $rule['cost'] );
					break;
				}
			}
			/* A populated rule table is authoritative; an unmatched range is unavailable. */
			if ( empty( $rules ) && null === $cost && isset( $method['cost'] ) && is_numeric( $method['cost'] ) ) {
				$cost = max( 0.0, (float) $method['cost'] );
			}
		} elseif ( 'static' === $type ) {
			$cost = isset( $method['cost'] ) && is_numeric( $method['cost'] ) ? max( 0.0, (float) $method['cost'] ) : null;
		} elseif ( 'free' === $type ) {
			$title = self::normalize_text( isset( $method['title'] ) ? $method['title'] : '' );
			if ( ( false !== strpos( $title, 'بالای 10 میلیون' ) || false !== strpos( $title, 'بالای ۱۰ میلیون' ) ) && $subtotal < 10000000 ) {
				return null;
			}
			$cost = 0.0;
		} elseif ( isset( $method['cost'] ) && is_numeric( $method['cost'] ) ) {
			$cost = max( 0.0, (float) $method['cost'] );
		}

		if ( null === $cost ) {
			return null;
		}
		if ( isset( $method['minimum_cost'] ) && is_numeric( $method['minimum_cost'] ) ) {
			$cost = max( $cost, (float) $method['minimum_cost'] );
		}
		if ( isset( $method['maximum_cost'] ) && is_numeric( $method['maximum_cost'] ) ) {
			$cost = min( $cost, (float) $method['maximum_cost'] );
		}
		if ( isset( $method['round_cost'] ) && is_numeric( $method['round_cost'] ) && (float) $method['round_cost'] > 0 ) {
			$round = (float) $method['round_cost'];
			$cost  = round( $cost / $round ) * $round;
		}
		return max( 0.0, $cost );
	}

	private static function number_in_range( $value, $minimum, $maximum ) {
		$value = (float) $value;
		if ( is_numeric( $minimum ) && $value < (float) $minimum ) {
			return false;
		}
		if ( is_numeric( $maximum ) && $value > (float) $maximum ) {
			return false;
		}
		return true;
	}

	private static function is_mobo_product( $product, $product_id, $variation_id ) {
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

	private static function get_mobo_api_price( $variation_id, $product_id ) {
		$ids = array_values( array_unique( array_filter( array( absint( $variation_id ), absint( $product_id ) ) ) ) );
		foreach ( $ids as $id ) {
			$value = get_post_meta( $id, 'mobo_api_price', true );
			if ( is_numeric( $value ) && (float) $value >= 0 ) {
				return (float) $value;
			}
		}
		return null;
	}

	private static function source_amount_to_store_amount( $amount ) {
		$currency   = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$multiplier = 'IRR' === $currency ? 10.0 : 1.0;
		$multiplier = (float) apply_filters( 'mobo_core_shipping_source_to_store_multiplier', $multiplier, $currency );
		return max( 0.0, (float) $amount * max( 0.0, $multiplier ) );
	}

	private static function store_amount_to_source_amount( $amount ) {
		$currency   = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$divisor    = 'IRR' === $currency ? 10.0 : 1.0;
		$divisor    = (float) apply_filters( 'mobo_core_shipping_store_to_source_divisor', $divisor, $currency );
		return max( 0.0, (float) $amount / ( $divisor > 0 ? $divisor : 1.0 ) );
	}

	private static function normalize_text( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = str_replace( array( 'ي', 'ى', 'ك', '‌', '-' ), array( 'ی', 'ی', 'ک', ' ', ' ' ), $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private function fail_result( $result, $message ) {
		$result['success'] = false;
		$result['message'] = sanitize_text_field( (string) $message );
		return $result;
	}

	private function store_result( $result ) {
		update_option( self::OPTION_LAST_RESULT, $result, false );
		return $result;
	}
}
