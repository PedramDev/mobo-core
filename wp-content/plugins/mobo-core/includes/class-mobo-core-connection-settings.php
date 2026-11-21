<?php
/**
 * Durable per-Connection source/business settings.
 *
 * Global worker/infrastructure tuning and the current License architecture are
 * deliberately excluded. Source/business configuration reads through this
 * repository. wp_options is migration input only: once the Default Connection is
 * seeded, scoped settings are retired from wp_options and this table is the sole
 * runtime source of truth for every Connection, including Default.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Connection_Settings {
	/** @var array<string,mixed> */
	private static $cache = array();

	/**
	 * Request-local negative cache. Missing rows must be cached independently
	 * from the caller-provided fallback; otherwise a sentinel/fallback object
	 * can leak into a later read that supplied a different fallback.
	 *
	 * @var array<string,bool>
	 */
	private static $missing_cache = array();

	/** @return string */
	public static function table_name() {
		global $wpdb;
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'connection_settings' );
			if ( '' !== $table ) { return $table; }
		}
		return $wpdb->prefix . 'mobo_connection_settings';
	}

	/** @return bool */
	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (string) $found === (string) $table;
	}

	/**
	 * Exact Source-dependent settings contract.
	 *
	 * Do not add installation infrastructure (Cron budgets, logging, DB versions)
	 * or the current License token here. Credentials used by the backend transport
	 * remain columns on mobo_connections.
	 *
	 * @return array<string,bool>
	 */
	public static function scoped_keys() {
		$keys = array(
			// Product/filter/update policy.
			'mobo_core_only_in_stock',
			'global_product_auto_stock',
			'global_product_auto_price',
			'global_product_auto_title',
			'global_product_auto_compare_price',
			'global_product_auto_slug',
			'global_update_categories',
			'global_update_images',
			'global_product_auto_description',
			'mobo_core_category_mapping_enabled',
			'mobo_core_category_mapping_required',
			'mobo_default_category_id',
			'mobo_price_type',
			'global_additional_price',
			'global_additional_percentage',
			'mobo_dynamic_price',
			'mobo_core_excluded_product_urls',
			'mobo_core_excluded_product_guid_urls',
			'mobo_core_missing_variants_behavior',
			'mobo_core_categories_refresh_interval_hours',
			'mobo_core_categories_last_sync_at',
			'mobo_core_categories_mapping_loaded_at',
			'mobo_core_category_mapping_active_guids',
			'mobo_core_category_mapping_active_snapshot_at',
			'mobo_core_repair_completed_at',
			'mobo_core_repair_last_sync_id',
			'mobo_core_sync_changes_endpoint_preference',
			'mobo_core_sync_changes_endpoint_retry_after',
			'mobo_core_sync_changes_endpoint_base_fingerprint',
			'mobo_core_webhook_queue_last_attempt_at',
			'mobo_core_webhook_queue_last_success_at',
			'mobo_core_webhook_queue_last_activity_at',
			'mobo_core_webhook_queue_last_result',
			'mobo_core_portal_webhook_delivery_status',
			'mobo_core_portal_webhook_delivery_status_at',
			'mobo_core_webhook_auth_status',
			'mobo_core_last_payload_pull_error',
			'mobo_core_last_payload_pull_error_at',

			// Source-scoped Health / heartbeat refresh state.
			'mobo_core_health_snapshot_refresh_requested_at',
			'mobo_core_health_snapshot_last_background_success_at',
			'mobo_core_health_snapshot_last_success_at',
			'mobo_core_health_snapshot_last_error',
			'mobo_core_sync_health_snapshot_refresh_requested_at',
			'mobo_core_sync_health_snapshot_refresh_requested_limit',
			'mobo_core_sync_health_snapshot_last_background_success_at',
			'mobo_core_sync_health_snapshot_last_success_at',
			'mobo_core_sync_health_snapshot_last_error',
			'mobo_core_portal_heartbeat_last_attempt_at',
			'mobo_core_portal_heartbeat_last_success_at',
			'mobo_core_portal_heartbeat_last_result',
			'mobo_core_health_last_report_attempt_at',
			'mobo_core_health_last_report_success_at',
			'mobo_core_health_last_report_result',
			'mobo_core_last_sync_success_at',
			'mobo_core_runtime_diagnostics',

			// Source account / checkout / remote cart.
			'mobo_core_checkout_mobo_username',
			'mobo_core_checkout_mobo_password',
			'mobo_core_checkout_mobo_cookie_jar',
			'mobo_core_checkout_mobo_cookie_reset_pending',
			'mobo_core_checkout_mobo_login_success_at',
			'mobo_core_checkout_mobo_cart_success_at',
			'mobo_core_checkout_mobo_cart_snapshot_at',
			'mobo_core_checkout_mobo_cart_item_map_count',
			'mobo_core_checkout_mobo_cart_delete_success_at',
			'mobo_core_checkout_mobo_cart_delete_last_result',
			'mobo_core_checkout_mobo_login_test_at',
			'mobo_core_checkout_mobo_login_test_result',
			'mobo_core_checkout_mobo_login_test_error',
			'mobo_core_checkout_last_validation_attempt_at',
			'mobo_core_checkout_last_validation_success_at',
			'mobo_core_checkout_last_validation_result',
			'mobo_core_checkout_mobo_cart_validation_enabled',
			'mobo_core_checkout_validation_enabled',
			'mobo_core_checkout_validate_only_mobo_products',
			'mobo_core_checkout_require_remote_guid',
			'mobo_core_checkout_block_incomplete_sync',
			'mobo_core_checkout_local_stock_check_enabled',
			'mobo_core_checkout_mobo_debug_enabled',
			'mobo_core_checkout_external_validation_enabled',
			'mobo_core_checkout_external_validation_url',
			'mobo_core_checkout_external_error_behavior',
			'mobo_core_checkout_mobo_timeout_seconds',
			'mobo_core_checkout_mobo_cart_lock_wait_seconds',
			'mobo_core_checkout_mobo_cart_lock_ttl_seconds',
			'mobo_core_checkout_external_timeout_seconds',

			// Purchase/order submission and Source shipping account policy.
			'mobo_core_mobo_order_submission_enabled',
			'mobo_core_mobo_order_auto_complete_enabled',
			'mobo_core_mobo_order_sender_name',
			'mobo_core_mobo_order_sender_mobile',
			'mobo_core_mobo_order_shipping_id',
			'mobo_core_mobo_shipping_package_enabled',
			'mobo_core_mobo_shipping_class_id',
			'mobo_core_mobo_shipping_use_api_price',
			'mobo_core_automatic_shipping_enabled',
			'mobo_core_automatic_shipping_last_run_at',
			'mobo_core_automatic_shipping_last_result',
			'mobo_core_automatic_shipping_managed_zones',
			'mobo_core_automatic_shipping_managed_instances',
			'mobo_core_shipping_mapping_only_policy_version',
			'mobo_core_shipping_mapping_only_last_cleanup',
			'mobo_core_shipping_wizard_completed',
			'mobo_core_shipping_fulfillment_profile',
			'mobo_core_shipping_store_location_mode',
			'mobo_core_shipping_mixed_fulfillment_shipping_id',
			'mobo_core_shipping_store_rate_mode',
			'mobo_core_shipping_store_rate_title',
			'mobo_core_shipping_store_rate_cost',
			'mobo_core_shipping_store_fallback_instances',
			'mobo_core_shipping_store_existing_mirror_instances',
			'mobo_core_remote_shipping_methods_snapshot',
			'mobo_core_remote_shipping_methods_changed_at',
			'mobo_core_remote_shipping_methods_last_attempt_at',
			'mobo_core_remote_shipping_methods_last_success_at',
			'mobo_core_remote_shipping_methods_last_error',

			// Source-specific address mapping/catalog.
			'mobo_core_address_mapping_enabled',
			'mobo_core_address_manual_mapping',
			'mobo_core_address_mapping_data',
			'mobo_core_address_mapping_last_attempt_at',
			'mobo_core_address_mapping_last_success_at',
			'mobo_core_address_mapping_last_error',
			'mobo_core_city_assets_status',

			// Source wallet/account monitoring state.
			'mobo_core_wallet_alert_enabled',
			'mobo_core_wallet_alert_threshold',
			'mobo_core_wallet_alert_recipients',
			'mobo_core_wallet_alert_template',
			'mobo_core_wallet_alert_armed',
			'mobo_core_wallet_alert_last_balance',
			'mobo_core_wallet_alert_last_check_at',
			'mobo_core_wallet_alert_last_error',
			'mobo_core_wallet_alert_last_sent_at',
			// Actual durable keys used by Mobo_Core_Wallet_Alert.
			'mobo_core_wallet_last_balance',
			'mobo_core_wallet_last_checked_at',
			'mobo_core_wallet_last_error',
			'mobo_core_wallet_last_order_id',
			'mobo_core_wallet_alert_notified',
			'mobo_core_wallet_alert_notified_at',
			'mobo_core_wallet_alert_notified_balance',
			'mobo_core_wallet_alert_delivery_fence_at',
			'mobo_core_wallet_sms_last_attempt_at',
			'mobo_core_wallet_sms_last_success_at',
			'mobo_core_wallet_sms_last_error',
			'mobo_core_wallet_sms_last_result',
			'mobo_core_wallet_test_sms_last_attempt_at',
			'mobo_core_wallet_test_sms_last_success_at',
			'mobo_core_wallet_test_sms_last_error',
			'mobo_core_wallet_test_sms_last_result',
		);
		return array_fill_keys( $keys, true );
	}

	/** Dynamic Source-owned option families. @return array<int,string> */
	public static function scoped_prefixes() {
		return array(
			'mobo_core_wc_shipping_method_map_',
			'mobo_core_shipping_allowed_ids_',
			'mobo_core_mobo_order_recovery_',
			'mobo_core_image_refresh_',
			'mobo_core_image_subsize_',
			'mobo_core_image_replaced_',
		);
	}

	/**
	 * Whether a boolean option is enabled for at least one enabled Connection.
	 *
	 * This is intentionally an aggregate bootstrap helper only. Source-owned
	 * runtime work must still execute inside an explicit Connection context.
	 *
	 * @param string $key Option key.
	 * @param mixed  $fallback Fallback value.
	 * @return bool
	 */
	public static function any_enabled_boolean( $key, $fallback = '0' ) {
		$key = (string) $key;
		if ( ! self::is_scoped_key( $key ) ) {
			return self::value_is_enabled( get_option( $key, $fallback ) );
		}
		if ( ! self::table_exists() || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return self::value_is_enabled( $fallback );
		}
		$rows = Mobo_Core_Connection_Repository::list_enabled();
		if ( empty( $rows ) ) {
			return self::value_is_enabled( $fallback );
		}
		foreach ( $rows as $row ) {
			$id = is_array( $row ) ? absint( $row['id'] ?? 0 ) : 0;
			if ( $id > 0 && self::value_is_enabled( self::get_option( $key, $fallback, $id ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return bool */
	private static function value_is_enabled( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/** @return bool */
	public static function is_scoped_key( $key ) {
		$key  = (string) $key;
		$keys = self::scoped_keys();
		if ( isset( $keys[ $key ] ) ) { return true; }
		foreach ( self::scoped_prefixes() as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) { return true; }
		}
		return false;
	}

	/** @return int */
	public static function effective_connection_id( $connection_id = 0 ) {
		$id = absint( $connection_id );
		if ( $id > 0 ) { return $id; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$id = Mobo_Core_Connection_Context::get_effective_connection_id();
			if ( $id > 0 ) { return $id; }
		}
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$row = Mobo_Core_Connection_Repository::get_default();
			return is_array( $row ) ? absint( $row['id'] ?? 0 ) : 0;
		}
		return 0;
	}

	/** @return bool */
	public static function is_default_connection( $connection_id ) {
		$id = absint( $connection_id );
		if ( $id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return false; }
		$row = Mobo_Core_Connection_Repository::get_by_id( $id );
		return is_array( $row ) && 1 === absint( $row['is_default'] ?? 0 );
	}

	/** @return mixed */
	public static function get( $connection_id, $key, $fallback = null ) {
		global $wpdb;
		$id  = self::effective_connection_id( $connection_id );
		$key = (string) $key;
		if ( $id <= 0 || ! self::is_scoped_key( $key ) || ! self::table_exists() ) { return $fallback; }
		$cache_key = $id . '|' . $key;
		if ( array_key_exists( $cache_key, self::$cache ) ) { return self::$cache[ $cache_key ]; }
		if ( isset( self::$missing_cache[ $cache_key ] ) ) { return $fallback; }
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT setting_value FROM ' . self::table_name() . ' WHERE connection_id = %d AND setting_key = %s LIMIT 1', $id, $key ) );
		if ( null === $value ) {
			self::$missing_cache[ $cache_key ] = true;
			return $fallback;
		}
		unset( self::$missing_cache[ $cache_key ] );
		self::$cache[ $cache_key ] = maybe_unserialize( $value );
		return self::$cache[ $cache_key ];
	}

	/** Whether a durable scoped row exists for one Connection. @return bool */
	public static function has( $connection_id, $key ) {
		global $wpdb;
		$id  = self::effective_connection_id( $connection_id );
		$key = (string) $key;
		if ( $id <= 0 || ! self::is_scoped_key( $key ) || ! self::table_exists() ) { return false; }
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE connection_id = %d AND setting_key = %s LIMIT 1', $id, $key ) );
		return null !== $exists;
	}

	/**
	 * Runtime option-compatible read.
	 *
	 * Scoped settings never read wp_options. Legacy rows are migration input only;
	 * absence of durable storage/ownership fails closed to the caller fallback.
	 *
	 * @return mixed
	 */
	public static function get_option( $key, $fallback = null, $connection_id = 0 ) {
		$key = (string) $key;
		if ( ! self::is_scoped_key( $key ) ) { return get_option( $key, $fallback ); }
		$id = self::effective_connection_id( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) { return $fallback; }
		return self::get( $id, $key, $fallback );
	}

	/** @return bool */
	public static function set( $connection_id, $key, $value ) {
		global $wpdb;
		$id  = self::effective_connection_id( $connection_id );
		$key = (string) $key;
		if ( $id <= 0 || ! self::is_scoped_key( $key ) || ! self::table_exists() ) { return false; }
		$encoded = maybe_serialize( $value );
		$now = current_time( 'mysql', true );
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::table_name() . ' (connection_id,setting_key,setting_value,created_at,updated_at) VALUES (%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
			$id, $key, $encoded, $now, $now
		);
		$result = $wpdb->query( $sql );
		if ( false === $result ) { return false; }
		$cache_key = $id . '|' . $key;
		unset( self::$missing_cache[ $cache_key ] );
		self::$cache[ $cache_key ] = $value;
		return true;
	}

	/**
	 * Option-compatible write. Scoped settings write only durable Connection
	 * storage; wp_options is never maintained as a second copy.
	 *
	 * @return bool
	 */
	public static function update_option( $key, $value, $autoload = false, $connection_id = 0 ) {
		$key = (string) $key;
		if ( ! self::is_scoped_key( $key ) ) { return update_option( $key, $value, $autoload ); }
		$id = self::effective_connection_id( $connection_id );
		return $id > 0 && self::set( $id, $key, $value );
	}

	/** @return bool */
	public static function delete( $connection_id, $key ) {
		global $wpdb;
		$id = self::effective_connection_id( $connection_id );
		$key = (string) $key;
		if ( $id <= 0 || ! self::is_scoped_key( $key ) || ! self::table_exists() ) { return false; }
		$result = $wpdb->delete( self::table_name(), array( 'connection_id' => $id, 'setting_key' => $key ), array( '%d', '%s' ) );
		$cache_key = $id . '|' . $key;
		unset( self::$cache[ $cache_key ] );
		if ( false !== $result ) { self::$missing_cache[ $cache_key ] = true; }
		return false !== $result;
	}

	/** @return bool */
	public static function delete_option( $key, $connection_id = 0 ) {
		$id = self::effective_connection_id( $connection_id );
		if ( ! self::is_scoped_key( $key ) ) { return delete_option( $key ); }
		return $id > 0 && self::delete( $id, $key );
	}

	/** Remove every durable Source-owned setting for one Connection. @return bool */
	public static function delete_all_for_connection( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) { return false; }
		$result = $wpdb->delete( self::table_name(), array( 'connection_id' => $id ), array( '%d' ) );
		$prefix = $id . '|';
		foreach ( array_keys( self::$cache ) as $cache_key ) {
			if ( 0 === strpos( (string) $cache_key, $prefix ) ) { unset( self::$cache[ $cache_key ] ); }
		}
		foreach ( array_keys( self::$missing_cache ) as $cache_key ) {
			if ( 0 === strpos( (string) $cache_key, $prefix ) ) { unset( self::$missing_cache[ $cache_key ] ); }
		}
		return false !== $result;
	}

	/** Request-local cache reset. @return void */
	public static function clear_cache() {
		self::$cache = array();
		self::$missing_cache = array();
	}

	/**
	 * Seed only missing rows from current effective legacy options.
	 * Safe to retry without overwriting later Connection-specific changes.
	 *
	 * @return bool
	 */
	public static function migrate_legacy_options( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) { return false; }
		$defaults = Mobo_Core_Settings::defaults();
		foreach ( array_keys( self::scoped_keys() ) as $key ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE connection_id = %d AND setting_key = %s LIMIT 1', $id, $key ) );
			if ( null !== $exists ) { continue; }
			$fallback = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
			if ( ! self::set( $id, $key, get_option( $key, $fallback ) ) ) { return false; }
		}
		return self::migrate_dynamic_legacy_options( $id );
	}

	/** Migrate currently existing dynamic Source-owned options to Default. @return bool */
	public static function migrate_dynamic_legacy_options( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() ) { return false; }
		foreach ( self::scoped_prefixes() as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC", $like ) );
			foreach ( is_array( $names ) ? $names : array() as $key ) {
				$key = (string) $key;
				$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE connection_id=%d AND setting_key=%s LIMIT 1', $id, $key ) );
				if ( null !== $exists ) { continue; }
				if ( ! self::set( $id, $key, get_option( $key, null ) ) ) { return false; }
			}
		}
		return true;
	}

	/**
	 * Existing legacy scoped option rows, including dynamic families.
	 * Migration/diagnostics only; runtime must never read these as authority.
	 *
	 * @return array<int,string>
	 */
	public static function legacy_option_names() {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) { return array(); }
		$names = array();
		$fixed = array_keys( self::scoped_keys() );
		if ( ! empty( $fixed ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $fixed ), '%s' ) );
			$sql = $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $fixed );
			$rows = $wpdb->get_col( $sql );
			foreach ( is_array( $rows ) ? $rows : array() as $name ) { $names[] = (string) $name; }
		}
		foreach ( self::scoped_prefixes() as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
			foreach ( is_array( $rows ) ? $rows : array() as $name ) { $names[] = (string) $name; }
		}
		$names = array_values( array_unique( array_filter( $names, 'strlen' ) ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Terminally retire legacy scoped wp_options after durable adoption.
	 *
	 * Every legacy row must already have a durable Default Connection row. Cleanup
	 * is retry-safe: if deletion/proof fails, the contract marker is not advanced.
	 *
	 * @return bool
	 */
	public static function retire_legacy_options( $connection_id ) {
		global $wpdb;
		$id = absint( $connection_id );
		if ( $id <= 0 || ! self::table_exists() || ! self::is_default_connection( $id ) ) { return false; }
		$legacy = self::legacy_option_names();
		foreach ( $legacy as $key ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table_name() . ' WHERE connection_id=%d AND setting_key=%s LIMIT 1', $id, $key ) );
			if ( null === $exists ) { return false; }
		}
		foreach ( $legacy as $key ) {
			delete_option( $key );
		}
		self::clear_cache();
		return array() === self::legacy_option_names();
	}

}
