<?php
/**
 * Plugin Name: Mobo Settings Exporter
 * Description: One-time, read-only exporter for legacy Mobo Core settings and the exact registered WordPress image size profile.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Settings_Exporter {

	const ACTION = 'mobo_settings_exporter_download';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'mobo-settings-export', array( __CLASS__, 'cli_export' ) );
		}
	}

	public static function register_page() {
		add_management_page(
			'Mobo Settings Export',
			'Mobo Settings Export',
			'manage_options',
			'mobo-settings-exporter',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
		?>
		<div class="wrap" dir="rtl">
			<h1>خروجی تنظیمات Mobo</h1>
			<p>این ابزار تنظیمات فعلی افزونه Mobo Core و سایزهای واقعی ثبت‌شده تصاویر WordPress/WooCommerce را در یک فایل JSON دانلود می‌کند.</p>
			<div class="notice notice-warning inline"><p><strong>هشدار:</strong> فایل ممکن است شامل Password، Token یا اطلاعات حساس تنظیمات باشد. فقط در Portal رسمی بارگذاری کنید و فایل را در فضای عمومی یا پیام‌رسان ناامن قرار ندهید.</p></div>
			<p>هیچ فایلی روی سرور ذخیره نمی‌شود و هیچ تنظیمی تغییر نمی‌کند.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( 'دانلود فایل JSON', 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
		check_admin_referer( self::ACTION );
		$payload = self::build_payload();
		$host = sanitize_file_name( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$file = 'mobo-settings-export-' . ( $host ? $host : 'wordpress' ) . '-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	public static function cli_export( $args, $assoc_args ) {
		$path = isset( $assoc_args['path'] ) ? (string) $assoc_args['path'] : '';
		$json = wp_json_encode( self::build_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( '' === $path ) { WP_CLI::line( $json ); return; }
		if ( false === file_put_contents( $path, $json . PHP_EOL ) ) { WP_CLI::error( 'Unable to write export file.' ); }
		WP_CLI::success( 'Export written to ' . $path );
	}

	private static function build_payload() {
		$settings = array();
		foreach ( self::fixed_keys() as $key ) {
			$settings[ $key ] = get_option( $key, self::default_for_missing_key( $key ) );
		}
		foreach ( self::load_dynamic_settings() as $key => $value ) { $settings[ $key ] = $value; }
		ksort( $settings, SORT_STRING );

		$sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		ksort( $sizes, SORT_STRING );
		return array(
			'schemaVersion' => 1,
			'exportType' => 'mobo-settings-export',
			'exportedAt' => gmdate( 'c' ),
			'siteUrl' => home_url( '/' ),
			'wordpressVersion' => get_bloginfo( 'version' ),
			'phpVersion' => PHP_VERSION,
			'moboPluginVersion' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : null,
			'settings' => $settings,
			'registeredImageSizes' => $sizes,
		);
	}

	private static function fixed_keys() {
		return array(
			'global_product_auto_stock',
			'global_product_auto_price',
			'global_product_auto_title',
			'global_product_auto_compare_price',
			'global_product_auto_slug',
			'global_update_categories',
			'global_update_images',
			'mobo_core_only_in_stock',
			'mobo_core_category_mapping_enabled',
			'mobo_core_category_mapping_required',
			'mobo_default_category_id',
			'mobo_price_type',
			'global_additional_price',
			'global_additional_percentage',
			'mobo_dynamic_price',
			'mobo_core_sync_time_budget_seconds',
			'mobo_core_webhook_files_per_run',
			'mobo_core_webhook_max_try',
			'mobo_core_webhook_expire_days',
			'mobo_core_variant_parent_wait_timeout_seconds',
			'mobo_core_pull_payload_enabled',
			'mobo_core_payload_pull_timeout_seconds',
			'mobo_core_api_request_timeout_seconds',
			'mobo_core_transient_retry_max_try',
			'mobo_core_waiting_for_portal_retry_delay_seconds',
			'mobo_core_reprice_batch_size',
			'mobo_core_products_per_page',
			'mobo_core_product_cursor_sync_enabled',
			'mobo_core_variants_per_page',
			'mobo_core_variant_cursor_sync_enabled',
			'mobo_core_images_per_run',
			'mobo_core_image_queue_enabled',
			'mobo_core_image_queue_blocking',
			'mobo_core_image_max_try',
			'mobo_core_image_retry_base_seconds',
			'mobo_core_image_refresh_enabled',
			'mobo_core_image_refresh_delete_old',
			'mobo_core_image_refresh_generate_subsizes',
			'mobo_core_image_refresh_cleanup_leftover_subsizes',
			'mobo_core_image_refresh_per_run',
			'mobo_core_image_refresh_scan_limit',
			'mobo_core_image_refresh_max_try',
			'mobo_core_image_refresh_retry_base_seconds',
			'mobo_core_image_refresh_automation_enabled',
			'mobo_core_image_refresh_auto_delete_old_approved',
			'mobo_core_image_refresh_auto_delete_orphan_approved',
			'mobo_core_orphan_image_cleanup_enabled',
			'mobo_core_orphan_image_scan_limit',
			'mobo_core_orphan_image_delete_per_run',
			'mobo_core_missing_variants_behavior',
			'mobo_core_excluded_product_urls',
			'mobo_core_categories_refresh_interval_hours',
			'mobo_core_real_cron_time_budget_seconds',
			'mobo_core_real_cron_max_sync_steps',
			'mobo_core_real_cron_max_rounds',
			'mobo_core_real_cron_safety_margin_seconds',
			'mobo_core_real_cron_lock_ttl_seconds',
			'mobo_core_real_cron_expected_interval_seconds',
			'mobo_core_real_cron_process_webhooks',
			'mobo_core_process_webhook_on_receive',
			'mobo_core_self_runner_enabled',
			'mobo_core_self_runner_continue_enabled',
			'mobo_core_self_runner_min_interval_seconds',
			'mobo_core_self_runner_http_timeout_seconds',
			'mobo_core_health_report_enabled',
			'mobo_core_health_report_min_interval_seconds',
			'mobo_core_health_report_timeout_seconds',
			'mobo_core_checkout_validation_enabled',
			'mobo_core_checkout_validate_only_mobo_products',
			'mobo_core_checkout_require_remote_guid',
			'mobo_core_checkout_block_incomplete_sync',
			'mobo_core_checkout_local_stock_check_enabled',
			'mobo_core_checkout_mobo_cart_validation_enabled',
			'mobo_core_checkout_mobo_debug_enabled',
			'mobo_core_shipping_diagnostics_enabled',
			'mobo_core_checkout_mobo_site_url',
			'mobo_core_checkout_mobo_username',
			'mobo_core_checkout_mobo_password',
			'mobo_core_checkout_mobo_timeout_seconds',
			'mobo_core_checkout_mobo_cart_lock_wait_seconds',
			'mobo_core_checkout_mobo_cart_lock_ttl_seconds',
			'mobo_core_checkout_external_validation_enabled',
			'mobo_core_checkout_external_validation_url',
			'mobo_core_checkout_external_timeout_seconds',
			'mobo_core_checkout_external_error_behavior',
			'mobo_core_mobo_order_submission_enabled',
			'mobo_core_mobo_order_auto_complete_enabled',
			'mobo_core_mobo_order_sender_name',
			'mobo_core_mobo_order_sender_mobile',
			'mobo_core_mobo_order_shipping_id',
			'mobo_core_remote_shipping_sync_interval_hours',
			'mobo_core_address_mapping_enabled',
			'mobo_core_address_mapping_sync_interval_days',
			'mobo_core_address_mapping_show_all_countries',
			'mobo_core_address_manual_mapping',
			'mobo_core_sms_notifications_enabled',
			'mobo_core_sms_non_mobo_enabled',
			'mobo_core_sms_non_mobo_recipients',
			'mobo_core_sms_non_mobo_template',
			'mobo_core_sms_mobo_only_enabled',
			'mobo_core_sms_mobo_only_recipients',
			'mobo_core_sms_mobo_only_template',
			'mobo_core_sms_mixed_enabled',
			'mobo_core_sms_mixed_recipients',
			'mobo_core_sms_mixed_template',
			'mobo_category_map',
		);
	}

	private static function default_for_missing_key( $key ) {
		if ( 'mobo_category_map' === $key ) { return array(); }
		if ( class_exists( 'Mobo_Core_Settings' ) && method_exists( 'Mobo_Core_Settings', 'defaults' ) ) {
			$defaults = Mobo_Core_Settings::defaults();
			if ( is_array( $defaults ) && array_key_exists( $key, $defaults ) ) { return $defaults[ $key ]; }
		}
		return '';
	}

	private static function load_dynamic_settings() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'mobo_core_wc_shipping_method_map_%' OR option_name LIKE 'mobo_core_shipping_allowed_ids_%'",
			ARRAY_A
		);
		$result = array();
		foreach ( (array) $rows as $row ) {
			$key = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
			if ( ! self::is_allowed_dynamic_key( $key ) ) { continue; }
			$result[ $key ] = maybe_unserialize( isset( $row['option_value'] ) ? $row['option_value'] : '' );
		}
		return $result;
	}

	private static function is_allowed_dynamic_key( $key ) {
		return 1 === preg_match( '/^mobo_core_wc_shipping_method_map_(?:mobo_only|mixed)_zone_[0-9]+_[a-z0-9_-]+_[0-9]+$/', $key )
			|| 1 === preg_match( '/^mobo_core_wc_shipping_method_map_zone_[0-9]+_[a-z0-9_-]+_[0-9]+$/', $key )
			|| 1 === preg_match( '/^mobo_core_shipping_allowed_ids_(?:mobo_only|mixed)_state_[0-9]+_(?:before12|after12)$/', $key );
	}
}

Mobo_Settings_Exporter::init();
