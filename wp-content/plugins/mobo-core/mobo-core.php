<?php
/**
 * Plugin Name: Mobo Core
 * Plugin URI: https://github.com/PedramDev/moboplugin.com
 * Description: همگام‌سازی مرحله‌ای محصولات، تنوع‌ها، دسته‌بندی‌ها، تصاویر و وب‌هوک‌ها برای ووکامرس.
 * Version: 10.25.0
 * Author: Pedram Karimi
 * Author URI: http://mobo.codeya.ir/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 10.9
 * Text Domain: mobo-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOBO_CORE_VERSION', '10.25.0' );
define( 'MOBO_CORE_PLUGIN_FILE', __FILE__ );
define( 'MOBO_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOBO_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR', MOBO_CORE_PLUGIN_DIR . 'webhook-files/' );

$mobo_core_upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
$mobo_core_basedir = isset( $mobo_core_upload['basedir'] ) && is_string( $mobo_core_upload['basedir'] ) && '' !== trim( $mobo_core_upload['basedir'] )
	? $mobo_core_upload['basedir']
	: MOBO_CORE_PLUGIN_DIR;

define( 'MOBO_CORE_DATA_DIR', trailingslashit( $mobo_core_basedir ) . 'mobo-core/' );
define( 'MOBO_CORE_WEBHOOK_FILE_DIR', MOBO_CORE_DATA_DIR . 'webhook-files/' );

/*
 * Optional API base URL constant.
 *
 * You can define this in wp-config.php or in your custom environment loader:
 *
 * define( 'MOBO_API_BASE_URL', 'http://dev.mobo.codeya.ir/' );
 *
 * If this is empty, API client may still fallback to mobo_core_api_base_url option.
 */
if ( ! defined( 'MOBO_API_BASE_URL' ) ) {
	define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
}

/*
 * Core classes.
 */
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-settings.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-legacy-rules.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-price-calculator.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-reprice-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-lock.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-api-client.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-product-map.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-sync-event-store.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-category-map.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-category-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-product-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-webhook-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-cron-runner.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-self-runner.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-health-reporter.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-checkout-validator.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-rest-controller.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-admin.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-variation-fields.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-migration.php';


/**
 * WooCommerce HPOS compatibility declaration.
 *
 * Mobo Core syncs products, variations, categories and media. It does not read
 * or write WooCommerce orders directly, so it is compatible with custom order
 * tables. Future checkout/order code must use WooCommerce CRUD APIs.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MOBO_CORE_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Resolve API base URL.
 *
 * Priority:
 * 1. Any custom filter added elsewhere.
 * 2. MOBO_API_BASE_URL constant.
 * 3. mobo_core_api_base_url option fallback inside API client.
 */
add_filter(
	'mobo_core_api_base_url',
	function ( $base_url ) {
		if ( is_string( $base_url ) && '' !== trim( $base_url ) ) {
			return $base_url;
		}

		if ( defined( 'MOBO_API_BASE_URL' ) && '' !== trim( (string) MOBO_API_BASE_URL ) ) {
			return (string) MOBO_API_BASE_URL;
		}

		return '';
	}
);

/**
 * Activation.
 *
 * Creates defaults, protects webhook directories,
 * creates/updates local tables, and migrates legacy webhook JSON files safely.
 */
register_activation_hook( __FILE__, array( 'Mobo_Core_Migration', 'activate' ) );

/**
 * Bootstrap plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		Mobo_Core_Migration::maybe_run();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}

					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'برای استفاده از Mobo Core باید افزونه WooCommerce فعال باشد.', 'mobo-core' );
					echo '</p></div>';
				}
			);

			return;
		}

		/*
		 * Variation custom field:
		 * mobo_additional_price
		 */
		$variation_fields = new Mobo_Core_Variation_Fields();
		$variation_fields->init();

		/*
		 * Checkout/pre-purchase validation.
		 * HPOS-safe: this validates cart items only and does not touch order storage.
		 */
		$checkout_validator = new Mobo_Core_Checkout_Validator();
		$checkout_validator->init();

		/*
		 * REST endpoints for C# runner and webhooks.
		 */
		$rest = new Mobo_Core_Rest_Controller();
		$rest->init();

		/*
		 * Admin UI.
		 */
		if ( is_admin() ) {
			$admin = new Mobo_Core_Admin();
			$admin->init();
		}
	}
);