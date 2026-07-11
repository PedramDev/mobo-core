<?php
/**
 * Plugin Name: Mobo Core
 * Plugin URI: https://github.com/PedramDev/mobo-core
 * Description: همگام‌سازی محصولات و ثبت سفارش ووکامرس برای فروشگاه‌های ایران متصل به MoboCore و منبع mobomobo.ir.
 * Version: 10.31.48
 * Author: Pedram Karimi
 * Author URI: http://mobo.codeya.ir/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 10.9
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mobo-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOBO_CORE_VERSION', '10.31.47' );
define( 'MOBO_CORE_PLUGIN_FILE', __FILE__ );
define( 'MOBO_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOBO_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOBO_CORE_PURCHASE_URL', 'http://mobo.codeya.ir/' );
define( 'MOBO_CORE_GITHUB_URL', 'https://github.com/PedramDev/mobo-core' );
define( 'MOBO_CORE_SALES_PHONE', '+989124508218' );
define( 'MOBO_CORE_SALES_TEL_URL', 'tel:+989124508218' );
define( 'MOBO_CORE_SALES_TELEGRAM_URL', 'https://t.me/yazdan_ghadiri' );
define( 'MOBO_CORE_SALES_WHATSAPP_URL', 'https://wa.me/989124508218' );
define( 'MOBO_CORE_TECH_PHONE', '+989367362228' );
define( 'MOBO_CORE_TECH_TELEGRAM_URL', 'https://t.me/Codeya' );
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
 * Privacy policy helper for sites that use MoboCore.
 */
add_action( 'admin_init', function() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	wp_add_privacy_policy_content(
		'Mobo Core',
		'<p>این سایت از افزونه Mobo Core برای همگام سازی محصولات ووکامرس، دریافت وب هوک، بررسی لایسنس، گزارش سلامت فنی و ثبت سفارش های مرتبط با منبع mobomobo.ir استفاده می کند. افزونه برای مدیریت لایسنس، همگام سازی و صف ها به سرویس MoboCore در دامنه mobo.codeya.ir متصل می شود و برای بررسی سبد یا ثبت سفارش موبویی، در صورت فعال بودن تنظیمات مربوطه، با mobomobo.ir ارتباط برقرار می کند. بسته به تنظیمات مدیر سایت، داده هایی مانند دامنه سایت، Token اتصال، اطلاعات محصول و تنوع، وضعیت صف ها، اطلاعات لازم برای ثبت سفارش، آدرس ارسال، روش ارسال انتخاب شده و گزارش سلامت فنی ممکن است ارسال یا دریافت شود. این افزونه برای فروشگاه های فعال در ایران طراحی شده است. برای پیامک، در صورت فعال سازی، ارسال واقعی از طریق افزونه پیامک حرفه ای ووکامرس و درگاه انتخاب شده در همان افزونه انجام می شود.</p>'
	);
} );

/*
 * Core classes.
 */
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-logger.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-settings.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-legacy-rules.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-price-calculator.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-reprice-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-lock.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-product-concurrency.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-api-client.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-product-map.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-sync-event-store.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-category-map.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-refresh-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-refresh-service.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-orphan-image-cleanup.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-category-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-recategorize-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-product-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-webhook-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-maintenance.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-cron-runner.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-self-runner.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-health-reporter.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-checkout-validator.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-address-mapping.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-shipping-diagnostics.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-remote-shipping-methods.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-sms-notifications.php';
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
		 *
		 * Important: when automatic Mobo order submission, Mobo-cart validation,
		 * local stock validation and external validation are all disabled, Mobo must
		 * stay completely out of the customer checkout runtime. In that mode
		 * WooCommerce/Persian-WooCommerce/shipping plugins own address fields,
		 * packages and shipping-rate calculation.
		 */
		$checkout_validation_master_enabled = Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' );
		$checkout_runtime_enabled = Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' )
			|| ( $checkout_validation_master_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ) )
			|| ( $checkout_validation_master_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_local_stock_check_enabled', '0' ) )
			|| ( $checkout_validation_master_enabled && Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' ) );

		if ( is_admin() || $checkout_runtime_enabled ) {
			$checkout_validator = new Mobo_Core_Checkout_Validator();
			$checkout_validator->init();
		} else {
			delete_option( 'mobo_core_shared_mobo_cart_lock' );
		}

		/*
		 * Mobo address mapping for checkout country/state/city selects.
		 * The class itself gates checkout hooks behind automatic order submission.
		 */
		$address_mapping = new Mobo_Core_Address_Mapping();
		$address_mapping->init();


		/*
		 * Remote Mobo shipping methods.
		 * WooCommerce remains the only owner of checkout shipping-rate display.
		 * Cached Mobo shipping methods are used only to choose shipping_id when
		 * creating an automatic order in Mobo.
		 */
		$remote_shipping_methods = new Mobo_Core_Remote_Shipping_Methods();
		$remote_shipping_methods->init();

		/*
		 * Shipping diagnostics are opt-in only. They are useful for troubleshooting,
		 * but normal checkout must not carry any extra shipping hooks from Mobo when
		 * Mobo is not responsible for automatic order submission.
		 */
		if ( Mobo_Core_Settings::enabled( 'mobo_core_shipping_diagnostics_enabled', '0' ) ) {
			$shipping_diagnostics = new Mobo_Core_Shipping_Diagnostics();
			$shipping_diagnostics->init();
		}

		/*
		 * Optional SMS notifications for Mobo/non-Mobo/mixed orders.
		 * Actual gateway delivery is delegated to Persian WooCommerce SMS.
		 */
		$sms_notifications = new Mobo_Core_SMS_Notifications();
		$sms_notifications->init();

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