<?php
/**
 * Plugin Name: Mobo Core
 * Plugin URI: https://github.com/PedramDev/mobo-core
 * Description: همگام‌سازی محصولات و ثبت سفارش ووکامرس برای فروشگاه‌های ایران متصل به MoboCore و منبع mobomobo.ir.
 * Version: 10.33.44.6
 * Author: Pedram Karimi
 * Author URI: http://mobo.codeya.ir/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 10.9
 * Requires Plugins: woocommerce, persian-woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mobo-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOBO_CORE_VERSION', '10.33.44.6' );
define( 'MOBO_CORE_PLUGIN_FILE', __FILE__ );
define( 'MOBO_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOBO_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOBO_CORE_PURCHASE_URL', 'http://mobo.codeya.ir/' );
define( 'MOBO_CORE_CHECKOUT_SITE_URL', 'https://mobomobo.ir' );
define( 'MOBO_CORE_GITHUB_URL', 'https://github.com/PedramDev/mobo-core' );
define( 'MOBO_CORE_SALES_PHONE', '+989124508218' );
define( 'MOBO_CORE_SALES_TEL_URL', 'tel:+989124508218' );
define( 'MOBO_CORE_SALES_TELEGRAM_URL', 'https://t.me/yazdan_ghadiri' );
define( 'MOBO_CORE_SALES_WHATSAPP_URL', 'https://wa.me/989124508218' );
define( 'MOBO_CORE_TECH_PHONE', '+989367362228' );
define( 'MOBO_CORE_TECH_TELEGRAM_URL', 'https://t.me/Codeya' );
define( 'MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR', MOBO_CORE_PLUGIN_DIR . 'webhook-files/' );


/**
 * Keep settings and dependency links available on the Plugins screen even when
 * a required plugin was deactivated after Mobo Core had already been enabled.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings    = '<a href="' . esc_url( admin_url( 'admin.php?page=mobo-core' ) ) . '">تنظیمات موبو</a>';
		$health      = '<a href="' . esc_url( admin_url( 'admin.php?page=mobo-core&tab=health' ) ) . '">سلامت</a>';
		$woocommerce = '<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">پیش نیاز WooCommerce</a>';
		$persian_wc  = '<a href="' . esc_url( admin_url( 'plugin-install.php?s=persian-woocommerce&tab=search&type=term' ) ) . '">پیش نیاز ووکامرس فارسی</a>';

		array_unshift( $links, $settings, $health, $woocommerce, $persian_wc );
		return $links;
	}
);

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
 * Lazy class loading.
 *
 * Older builds eagerly required every runtime/admin/migration/image class on
 * every WordPress request. Keep one tiny autoloader eager and load the actual
 * component only when that request reaches its code path.
 */
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-autoloader.php';
Mobo_Core_Autoloader::register();

/**
 * Detect a REST request early enough for plugins_loaded gating.
 * REST_REQUEST itself is defined later during parse_request, so the URI fallback
 * is needed for lazy bootstrap decisions.
 *
 * @return bool
 */
function mobo_core_is_rest_request_early() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	/* Plain-permalink REST requests use ?rest_route=/... before REST_REQUEST exists. */
	$rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request-context detection.
	if ( '' !== trim( $rest_route ) ) {
		return true;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( '' === $request_uri ) {
		return false;
	}

	$path = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		return false;
	}

	$prefix = function_exists( 'rest_get_url_prefix' ) ? trim( (string) rest_get_url_prefix(), '/' ) : 'wp-json';
	return false !== strpos( '/' . ltrim( $path, '/' ), '/' . $prefix . '/' );
}

/**
 * Whether this request may mutate products/options and therefore needs the full
 * cache mutation listeners instead of the read-only frontend fast path.
 *
 * @return bool
 */
function mobo_core_request_may_mutate() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

	if ( is_admin() || mobo_core_is_rest_request_early() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return true;
	}

	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true;
	}

	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI ) {
		return true;
	}

	return '' !== $method && 'GET' !== $method && 'HEAD' !== $method;
}

/**
 * Read a boolean option without loading the full settings helper during the
 * ordinary frontend bootstrap.
 *
 * @param string $name Option name.
 * @param string $default Default value.
 * @return bool
 */
function mobo_core_bootstrap_enabled( $name, $default = '0' ) {
	$value = get_option( sanitize_key( (string) $name ), $default );
	return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Whether private shared media was explicitly enabled by server configuration.
 * This inexpensive pre-check avoids loading the adapter on normal public sites.
 *
 * @return bool
 */
function mobo_core_shared_media_configured() {
	$name = 'MOBO_CORE_SHARED_MEDIA_ENABLED';
	$value = defined( $name ) ? constant( $name ) : getenv( $name );
	return in_array( strtolower( trim( false === $value || null === $value ? '' : (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Whether a migration repair that must run on init is currently pending.
 *
 * @return bool
 */
function mobo_core_has_deferred_repair() {
	foreach ( array(
		'mobo_core_stage7_resume_kick_pending',
		'mobo_core_category_placeholder_repair_pending',
		'mobo_core_image_queue_recovery_pending',
		'mobo_core_product_recovery_kick_pending',
	) as $option_name ) {
		if ( '1' === (string) get_option( $option_name, '0' ) ) {
			return true;
		}
	}

	/* A failed/non-arrived loopback remains durable, but only load the migration
	 * repair hook when self-runner dispatch is actually configured. */
	if ( '1' === (string) get_option( 'mobo_core_worker_dispatch_pending', '0' )
		&& mobo_core_bootstrap_enabled( 'mobo_core_self_runner_enabled', '1' )
		&& '' !== trim( (string) get_option( 'mobo_core_cron_token', '' ) ) ) {
		return true;
	}

	return false;
}

/**
 * Preserve the WP Rocket hierarchical product-category URL validation exception
 * on read-only frontend requests without loading the full cache purger class.
 *
 * @param bool $disabled Existing decision.
 * @return bool
 */
function mobo_core_lightweight_rocket_category_validation( $disabled ) {
	if ( $disabled ) {
		return true;
	}

	$product_cat = sanitize_title( (string) get_query_var( 'product_cat', '' ) );
	if ( '' !== $product_cat ) {
		return true;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path = '' !== $request_uri ? wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
	if ( ! is_string( $path ) || '' === $path ) {
		return false;
	}

	$permalinks = get_option( 'woocommerce_permalinks', array() );
	$permalinks = is_array( $permalinks ) ? $permalinks : array();
	$base = isset( $permalinks['category_base'] ) ? trim( (string) $permalinks['category_base'], '/ ' ) : '';
	$base = '' !== $base ? $base : 'product-category';

	return 0 === strpos( '/' . ltrim( $path, '/' ), '/' . trim( $base, '/' ) . '/' );
}


/**
 * WooCommerce HPOS compatibility declaration.
 *
 * Mobo Core uses WooCommerce CRUD APIs for order validation, Mobo submission,
 * immutable revenue metadata and diagnostics, so it remains compatible with
 * custom order tables (HPOS).
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
function mobo_core_activate() {
	Mobo_Core_Dependencies::enforce_activation_requirements();
	Mobo_Core_Migration::activate();
}

register_activation_hook( __FILE__, 'mobo_core_activate' );

/**
 * Bootstrap plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		$missing_dependencies = Mobo_Core_Dependencies::get_missing_dependencies();
		if ( ! empty( $missing_dependencies ) ) {
			Mobo_Core_Dependencies::register_admin_notices( $missing_dependencies );
			return;
		}

		/*
		 * Do not parse the large migration component on every frontend request.
		 * It is loaded only after an actual plugin-version change or while one of
		 * the init-dependent recovery flags is still pending.
		 */
		$current_db_version = (string) get_option( 'mobo_core_db_version', '' );
		if ( MOBO_CORE_VERSION !== $current_db_version ) {
			Mobo_Core_Migration::maybe_run();
		}
		if ( mobo_core_has_deferred_repair() ) {
			add_action( 'init', array( 'Mobo_Core_Migration', 'run_deferred_repairs' ), 20 );
		}

		/* Private shared media is loaded only on servers that explicitly enable it. */
		if ( mobo_core_shared_media_configured() ) {
			Mobo_Core_Shared_Media::init();
		}

		/*
		 * Full cache mutation listeners are unnecessary on ordinary read-only GETs.
		 * Keep only WP Rocket's product-category validation exception there; REST,
		 * admin, cron, CLI, AJAX and write requests retain the complete purger.
		 */
		if ( mobo_core_request_may_mutate() ) {
			Mobo_Core_Cache_Purger::init();
		} else {
			add_filter( 'rocket_disable_url_validation', 'mobo_core_lightweight_rocket_category_validation', PHP_INT_MAX, 1 );
		}

		/* Settings writes happen through admin/REST/AJAX/CLI paths, not storefront reads. */
		if ( is_admin() || mobo_core_is_rest_request_early() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$sync_settings_guard = new Mobo_Core_Sync_Settings_Guard();
			$sync_settings_guard->init();
		}

		/* Variation editor fields are wp-admin only. */
		if ( is_admin() ) {
			$variation_fields = new Mobo_Core_Variation_Fields();
			$variation_fields->init();
		}

		/*
		 * Checkout/pre-purchase validation stays completely absent from storefront
		 * requests when every Mobo checkout feature is disabled.
		 */
		$checkout_validation_master_enabled = mobo_core_bootstrap_enabled( 'mobo_core_checkout_validation_enabled', '0' );
		$order_submission_enabled = mobo_core_bootstrap_enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
		$checkout_runtime_enabled = $order_submission_enabled
			|| ( $checkout_validation_master_enabled && mobo_core_bootstrap_enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ) )
			|| ( $checkout_validation_master_enabled && mobo_core_bootstrap_enabled( 'mobo_core_checkout_local_stock_check_enabled', '0' ) )
			|| ( $checkout_validation_master_enabled && mobo_core_bootstrap_enabled( 'mobo_core_checkout_external_validation_enabled', '0' ) );

		if ( is_admin() || $checkout_runtime_enabled ) {
			$checkout_validator = new Mobo_Core_Checkout_Validator();
			$checkout_validator->init();
		}

		/* Address/city runtime is relevant only when Mobo order submission is enabled. */
		if ( $order_submission_enabled ) {
			$address_mapping = new Mobo_Core_Address_Mapping();
			$address_mapping->init();

			$city_assets = new Mobo_Core_City_Assets();
			$city_assets->init();
		}

		/* Legacy shipping cleanup is a one-time policy migration, not a per-request job. */
		if ( '1' !== (string) get_option( 'mobo_core_shipping_mapping_only_policy_version', '' ) ) {
			add_action(
				'wp_loaded',
				function () {
					$automatic_shipping = new Mobo_Core_Automatic_Shipping();
					$automatic_shipping->retire_legacy_runtime( 'policy-10.33.0' );
				},
				1
			);
		}

		if ( mobo_core_bootstrap_enabled( 'mobo_core_shipping_diagnostics_enabled', '0' ) ) {
			$shipping_diagnostics = new Mobo_Core_Shipping_Diagnostics();
			$shipping_diagnostics->init();
		}

		/*
		 * Revenue source snapshots are private bookkeeping metadata. Keep them stored
		 * on line items for immutable calculations while removing them from every
		 * WooCommerce display path that relies on hidden/formatted item metadata.
		 */
		add_filter( 'woocommerce_hidden_order_itemmeta', array( 'Mobo_Core_Revenue_Ledger', 'hide_internal_order_item_meta' ), PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( 'Mobo_Core_Revenue_Ledger', 'filter_internal_formatted_item_meta' ), PHP_INT_MAX, 2 );

		/*
		 * Lazy order hooks: keep SMS and wallet classes off catalogue/product page
		 * requests and instantiate them only when the corresponding order event fires.
		 */
		add_action(
			'woocommerce_checkout_order_processed',
			function ( $order_id, $posted_data = array(), $order = null ) {
				$notifications = new Mobo_Core_SMS_Notifications();
				$notifications->handle_checkout_order_processed( $order_id, $posted_data, $order );
			},
			99,
			3
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			function ( $order ) {
				$notifications = new Mobo_Core_SMS_Notifications();
				$notifications->handle_store_api_checkout_order_processed( $order );
			},
			99,
			1
		);
		add_action(
			'mobo_core_mobo_order_submission_success',
			function ( $order_id, $mobo_order_id = 0, $payment_json = array() ) {
				$wallet = new Mobo_Core_Wallet_Alert();
				$wallet->handle_mobo_order_submission_success( $order_id, $mobo_order_id, $payment_json );
			},
			10,
			3
		);

		add_action(
			'woocommerce_checkout_create_order_line_item',
			function ( $item, $cart_item_key, $values, $order ) {
				$ledger = new Mobo_Core_Revenue_Ledger();
				$ledger->capture_checkout_line_item_source_cost( $item, $cart_item_key, $values, $order );
			},
			20,
			4
		);
		add_action(
			'woocommerce_checkout_order_processed',
			function ( $order_id, $posted_data = array(), $order = null ) {
				if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
					$order = wc_get_order( absint( $order_id ) );
				}
				if ( $order instanceof WC_Order ) {
					$ledger = new Mobo_Core_Revenue_Ledger();
					$ledger->snapshot_missing_source_costs( $order );
				}
			},
			10,
			3
		);
		add_action(
			'woocommerce_store_api_checkout_update_order_meta',
			function ( $order ) {
				if ( $order instanceof WC_Order ) {
					$ledger = new Mobo_Core_Revenue_Ledger();
					$ledger->snapshot_missing_source_costs( $order );
				}
			},
			30,
			1
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			function ( $order ) {
				if ( $order instanceof WC_Order ) {
					$ledger = new Mobo_Core_Revenue_Ledger();
					$ledger->snapshot_missing_source_costs( $order );
				}
			},
			10,
			1
		);

		add_action(
			'mobo_core_mobo_order_submission_success',
			function ( $order_id, $mobo_order_id = 0, $payment_json = array() ) {
				$ledger = new Mobo_Core_Revenue_Ledger();
				$ledger->handle_mobo_order_submission_success( $order_id, $mobo_order_id, $payment_json );
			},
			20,
			3
		);
		add_action(
			'woocommerce_order_status_completed',
			function ( $order_id, $order = null ) {
				$ledger = new Mobo_Core_Revenue_Ledger();
				$ledger->handle_order_completed( $order_id, $order );
			},
			20,
			2
		);

		/* REST controller is parsed only on actual REST requests. */
		add_action(
			'rest_api_init',
			function () {
				$rest = new Mobo_Core_Rest_Controller();
				$rest->register_routes();
			}
		);

		if ( is_admin() ) {
			$admin = new Mobo_Core_Admin();
			$admin->init();
		}
	}
);
