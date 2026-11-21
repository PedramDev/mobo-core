<?php
if ( defined( 'ABSPATH' ) ) { require_once __DIR__ . '/includes/class-mobo-core-health-read-cache.php'; } // MOBO-HEALTH-SYNC-LICENSE-HARDENING-r1

/**
 * Plugin Name: Mobo Core - Production Candidate (OFFLINE / NOT APPROVED)
 * Plugin URI: https://github.com/PedramDev/mobo-core
 * Description: Offline owner-approved operational derivative. Transport capable. NOT APPROVED FOR INSTALLATION OR DEPLOYMENT. NOT FOR PRODUCTION.
 * Version: 10.34.77
 * Author: Pedram Karimi
 * Author URI: https://codeya.ir/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 10.9
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mobo-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOBO_CORE_VERSION', '10.34.77' );
/* Immutable build-time derivative identity; no runtime unseal switch for Preview. */
define( 'MOBO_CORE_PUBLISH_STAGED_NO_SEND', false );
define( 'MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION', '5' );
define( 'MOBO_CORE_PLUGIN_FILE', __FILE__ );
define( 'MOBO_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOBO_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOBO_CORE_PURCHASE_URL', 'https://codeya.ir/' );
define( 'MOBO_CORE_GITHUB_URL', 'https://github.com/PedramDev/mobo-core' );
define( 'MOBO_CORE_SALES_PHONE', '+989124508218' );
define( 'MOBO_CORE_SALES_TEL_URL', 'tel:+989124508218' );
define( 'MOBO_CORE_SALES_TELEGRAM_URL', 'https://t.me/yazdan_ghadiri' );
define( 'MOBO_CORE_SALES_WHATSAPP_URL', 'https://wa.me/989124508218' );
define( 'MOBO_CORE_TECH_PHONE', '+989367362228' );
define( 'MOBO_CORE_TECH_TELEGRAM_URL', 'https://t.me/Codeya' );
define( 'MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR', MOBO_CORE_PLUGIN_DIR . 'webhook-files/' );


/**
 * Context-aware links for the Mobo Core row on WordPress' Plugins screens.
 *
 * Settings/Health exist only after platform + plugin requirements are satisfied
 * and only in the site admin where Mobo's menu is registered. Dependency links
 * are shown only for dependencies that actually need operator action.
 *
 * @param array<int|string,string> $links Existing WordPress plugin-row links.
 * @return array<int|string,string>
 */
function mobo_core_plugin_action_links( $links ) {
	/* Persian labels sit inside an LTR (e.g. English) wp-admin row. Without dir="rtl"
	 * (an isolated bidi run) adjacent RTL links and WordPress' " | " separators are
	 * reordered into one RTL run and Chromium hit-tests the second link as its parent
	 * span, so a click on "سلامت سایت" did nothing. */
	if ( ! is_array( $links ) ) {
		$links = array();
	}

	$is_network_admin = function_exists( 'is_network_admin' ) && is_network_admin();
	$can_manage_site  = ! $is_network_admin && current_user_can( 'manage_options' );
	$can_manage_plugins = current_user_can( 'activate_plugins' ) || ( is_multisite() && current_user_can( 'manage_network_plugins' ) );

	$platform_ready = class_exists( 'Mobo_Core_Dependencies' )
		? Mobo_Core_Dependencies::platform_requirements_met()
		: PHP_INT_SIZE >= 8;
	$missing = class_exists( 'Mobo_Core_Dependencies' )
		? Mobo_Core_Dependencies::get_missing_dependencies()
		: array();

	$prepend = array();
	if ( $platform_ready && empty( $missing ) && $can_manage_site ) {
		$prepend['mobo_settings'] = '<a href="' . esc_url( admin_url( 'admin.php?page=mobo-core' ) ) . '" dir="rtl">تنظیمات موبو</a>';
		$prepend['mobo_health']   = '<a href="' . esc_url( admin_url( 'admin.php?page=mobo-core&tab=health' ) ) . '" dir="rtl">سلامت سایت</a>';
	}

	if ( ! $platform_ready && $can_manage_plugins ) {
		$prepend[] = '<span dir="rtl" aria-label="' . esc_attr__( 'Mobo Core requires 64-bit PHP.', 'mobo-core' ) . '">' . esc_html__( 'نیازمند PHP 64-bit', 'mobo-core' ) . '</span>';
	}

	if ( $can_manage_plugins && ! empty( $missing ) ) {
		foreach ( $missing as $dependency ) {
			$name      = isset( $dependency['name'] ) ? sanitize_text_field( (string) $dependency['name'] ) : '';
			$slug      = isset( $dependency['slug'] ) ? sanitize_key( (string) $dependency['slug'] ) : '';
			$installed = ! empty( $dependency['installed'] );
			if ( '' === $name ) {
				continue;
			}

			if ( $installed || ! current_user_can( 'install_plugins' ) || '' === $slug ) {
				$prepend[] = '<a href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '" dir="rtl">' . esc_html( 'مدیریت پیش‌نیاز: ' . $name ) . '</a>';
				continue;
			}

			$url = add_query_arg(
				array(
					's'    => $slug,
					'tab'  => 'search',
					'type' => 'term',
				),
				self_admin_url( 'plugin-install.php' )
			);
			$prepend[] = '<a href="' . esc_url( $url ) . '" dir="rtl">' . esc_html( 'نصب پیش‌نیاز: ' . $name ) . '</a>';
		}
	}

	return array_merge( $prepend, $links );
}

$mobo_core_plugin_action_hook = plugin_basename( __FILE__ );
add_filter( 'plugin_action_links_' . $mobo_core_plugin_action_hook, 'mobo_core_plugin_action_links' );
add_filter( 'network_admin_plugin_action_links_' . $mobo_core_plugin_action_hook, 'mobo_core_plugin_action_links' );

$mobo_core_upload = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
$mobo_core_basedir = isset( $mobo_core_upload['basedir'] ) && is_string( $mobo_core_upload['basedir'] ) && '' !== trim( $mobo_core_upload['basedir'] )
	? $mobo_core_upload['basedir']
	: MOBO_CORE_PLUGIN_DIR;

define( 'MOBO_CORE_DATA_DIR', trailingslashit( $mobo_core_basedir ) . 'mobo-core/' );
define( 'MOBO_CORE_WEBHOOK_FILE_DIR', MOBO_CORE_DATA_DIR . 'webhook-files/' );

/*
 * Source identity, provider binding, backend URL and storefront URL are provisioned
 * by the licensed .NET deployment after the customer stores a Token. No target
 * Source is compiled into the plugin package.
 */

/*
 * Privacy policy helper for sites that use MoboCore.
 */
add_action( 'admin_init', function() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	wp_add_privacy_policy_content(
		'Mobo Core',
		'<p>این سایت از افزونه Mobo Core برای همگام سازی محصولات ووکامرس، دریافت وب هوک، بررسی لایسنس، گزارش سلامت فنی و ثبت سفارش استفاده می کند. Source و آدرس سرویس مربوط به هر Connection پس از ثبت Token توسط Backend مجاز provision می شود و در بسته افزونه از قبل تعیین نشده است. بسته به تنظیمات مدیر سایت، داده هایی مانند دامنه سایت، Token اتصال، اطلاعات محصول و تنوع، وضعیت صف ها، اطلاعات لازم برای ثبت سفارش، آدرس ارسال، روش ارسال انتخاب شده و گزارش سلامت فنی ممکن است ارسال یا دریافت شود. این افزونه برای فروشگاه های فعال در ایران طراحی شده است.</p>'
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

/*
 * Disabled-function-safe wrappers (+ ctype polyfill) must exist before any other
 * Mobo code runs. Plugin-scoped error capture is wired with two tiny hooks; the
 * Mobo_Core_Error_Log class itself is only loaded when there is something to
 * record (a capture in this request, a fatal in a plugin file, a failing Mobo
 * REST route), so ordinary storefront requests stay lean.
 */
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-environment.php';
Mobo_Core_Environment::on_shutdown( 'mobo_core_diagnostics_shutdown' );
add_filter( 'rest_request_after_callbacks', 'mobo_core_diagnostics_rest_observer', 999, 3 );

/**
 * Shutdown: persist captured problems and record a fatal raised in plugin code.
 *
 * @return void
 */
function mobo_core_diagnostics_shutdown() {
	if ( class_exists( 'Mobo_Core_Error_Log', false ) ) {
		Mobo_Core_Error_Log::on_shutdown();
		return;
	}
	$error = function_exists( 'error_get_last' ) ? error_get_last() : null;
	$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
	if ( is_array( $error ) && isset( $error['type'], $error['file'] ) && ( (int) $error['type'] & $fatal )
		&& 0 === strpos( str_replace( '\\', '/', (string) $error['file'] ), rtrim( str_replace( '\\', '/', MOBO_CORE_PLUGIN_DIR ), '/' ) . '/' ) ) {
		Mobo_Core_Error_Log::on_shutdown();
	}
}

/**
 * rest_request_after_callbacks: failing Mobo routes feed the support error log.
 *
 * @param mixed $response Response.
 * @param mixed $handler  Handler.
 * @param mixed $request  Request.
 * @return mixed
 */
function mobo_core_diagnostics_rest_observer( $response, $handler = null, $request = null ) {
	if ( is_object( $request ) && method_exists( $request, 'get_route' ) && 0 === strpos( (string) $request->get_route(), '/mobo-core/' )
		&& ( is_wp_error( $response ) || ( is_object( $response ) && method_exists( $response, 'get_status' ) && (int) $response->get_status() >= 400 ) ) ) {
		return Mobo_Core_Error_Log::observe_rest_response( $response, $handler, $request );
	}
	return $response;
}

/*
 * 10.33.95 terminal identity authority. Product/Variation identity reads are
 * virtualized from Custom Storage after the durable identity fence; mutations
 * are intercepted at priority 1 so retired wp_postmeta rows cannot reappear.
 */
function mobo_core_terminal_identity_meta_read( $check, $object_id, $meta_key, $single = false, $meta_type = 'post' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $meta_type );
	return Mobo_Core_Storage_Identity_Meta_Retirement::filter_read( $check, $object_id, $meta_key, $single );
}
function mobo_core_terminal_identity_meta_add( $check, $object_id, $meta_key, $meta_value = null, $unique = false ) {
	return Mobo_Core_Storage_Identity_Meta_Retirement::filter_add( $check, $object_id, $meta_key, $meta_value, $unique );
}
function mobo_core_terminal_identity_meta_update( $check, $object_id, $meta_key, $meta_value = null, $prev_value = '' ) {
	return Mobo_Core_Storage_Identity_Meta_Retirement::filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value );
}
function mobo_core_terminal_identity_meta_delete( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	return Mobo_Core_Storage_Identity_Meta_Retirement::filter_delete( $check, $object_id, $meta_key, $meta_value, $delete_all );
}
add_filter( 'get_post_metadata', 'mobo_core_terminal_identity_meta_read', 1, 5 );
add_filter( 'add_post_metadata', 'mobo_core_terminal_identity_meta_add', 1, 5 );
add_filter( 'update_post_metadata', 'mobo_core_terminal_identity_meta_update', 1, 5 );
add_filter( 'delete_post_metadata', 'mobo_core_terminal_identity_meta_delete', 1, 5 );

/*
 * 10.33.76 buffered operational-meta mirror. Keep the hot global metadata hook
 * tiny: unrelated WordPress/Woo meta writes do not autoload the bridge class.
 */
function mobo_core_custom_storage_meta_commit_bridge( $meta_id, $object_id, $meta_key, $meta_value = null ) {
	static $keys = array(
		'product_guid'=>true, 'variant_guid'=>true,
		'portal_product_id'=>true, 'mobo_portal_product_id'=>true, '_mobo_portal_product_id'=>true, 'PortalProductId'=>true,
		'portal_variant_id'=>true, 'mobo_portal_variant_id'=>true, '_mobo_portal_variant_id'=>true, 'PortalVariantId'=>true,
		'_mobo_product_seen_revision'=>true, '_mobo_product_applied_revision'=>true,
		'_mobo_variant_seen_revision'=>true, '_mobo_variant_applied_revision'=>true,
		'_mobo_product_source_hash'=>true, '_mobo_variant_source_hash'=>true, 'mobo_sync_incomplete'=>true,
	);
	if ( ! isset( $keys[ (string) $meta_key ] ) ) { return; }
	Mobo_Core_Storage_Meta_Commit_Bridge::handle_post_meta_change( $meta_id, $object_id, $meta_key, $meta_value );
}
add_action( 'added_post_meta', 'mobo_core_custom_storage_meta_commit_bridge', 20, 4 );
add_action( 'updated_post_meta', 'mobo_core_custom_storage_meta_commit_bridge', 20, 4 );
add_action( 'deleted_post_meta', 'mobo_core_custom_storage_meta_commit_bridge', 20, 4 );

/*
 * 10.33.94 terminal operational-meta authority. Once the durable retirement
 * fence is published, add/update/delete for the seven managed Product/Variation
 * keys are Custom-only and fail closed. Priority 1 ensures WordPress can never
 * recreate the physically retired postmeta rows. Attachments are intentionally
 * not intercepted.
 */
function mobo_core_terminal_operational_meta_add( $check, $object_id, $meta_key, $meta_value = null, $unique = false ) {
	return Mobo_Core_Storage_Operational_Meta_Retirement::filter_add( $check, $object_id, $meta_key, $meta_value, $unique );
}
function mobo_core_terminal_operational_meta_update( $check, $object_id, $meta_key, $meta_value = null, $prev_value = '' ) {
	return Mobo_Core_Storage_Operational_Meta_Retirement::filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value );
}
function mobo_core_terminal_operational_meta_delete( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	return Mobo_Core_Storage_Operational_Meta_Retirement::filter_delete( $check, $object_id, $meta_key, $meta_value, $delete_all );
}
add_filter( 'add_post_metadata', 'mobo_core_terminal_operational_meta_add', 1, 5 );
add_filter( 'update_post_metadata', 'mobo_core_terminal_operational_meta_update', 1, 5 );
add_filter( 'delete_post_metadata', 'mobo_core_terminal_operational_meta_delete', 1, 5 );

/*
 * 10.33.96 terminal generic Mobo Product/Variation metadata authority.
 * Operational and identity families remain owned by their typed stores; the
 * remaining Mobo-owned keys are routed to mobo_entity_meta. Priority 2 runs
 * after the specialized priority-1 filters and before any Legacy rollout code.
 */
function mobo_core_terminal_entity_meta_read( $check, $object_id, $meta_key, $single = false, $meta_type = 'post' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $meta_type );
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_read( $check, $object_id, $meta_key, $single );
}
function mobo_core_terminal_entity_meta_add( $check, $object_id, $meta_key, $meta_value = null, $unique = false ) {
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_add( $check, $object_id, $meta_key, $meta_value, $unique );
}
function mobo_core_terminal_entity_meta_update( $check, $object_id, $meta_key, $meta_value = null, $prev_value = '' ) {
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value );
}
function mobo_core_terminal_entity_meta_delete( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_delete( $check, $object_id, $meta_key, $meta_value, $delete_all );
}
add_filter( 'get_post_metadata', 'mobo_core_terminal_entity_meta_read', 2, 5 );
add_filter( 'add_post_metadata', 'mobo_core_terminal_entity_meta_add', 2, 5 );
add_filter( 'update_post_metadata', 'mobo_core_terminal_entity_meta_update', 2, 5 );
add_filter( 'delete_post_metadata', 'mobo_core_terminal_entity_meta_delete', 2, 5 );

/* 10.33.97 compatibility view for all-meta WordPress reads. Specific-key
 * reads remain owned by the three terminal storage families above. */
function mobo_core_terminal_all_meta_read( $check, $object_id, $meta_key, $single = false, $meta_type = 'post' ) {
	return Mobo_Core_Storage_Terminal_Meta_Compat::filter_all_meta_read( $check, $object_id, $meta_key, $single, $meta_type );
}
add_filter( 'get_post_metadata', 'mobo_core_terminal_all_meta_read', 3, 5 );

/* 10.34.78: Mobo sideloads/attachments generate only the WooCommerce image sizes
 * (option mobo_core_image_subsizes, filter mobo_core_image_subsizes; 'all' restores
 * WordPress' default). The Backend already serves resized WebP. */
function mobo_core_image_subsize_filter( $sizes, $image_meta = array(), $attachment_id = 0 ) {
	return Mobo_Core_Image_Size_Policy::filter_sizes( $sizes, $image_meta, $attachment_id );
}
add_filter( 'intermediate_image_sizes_advanced', 'mobo_core_image_subsize_filter', 20, 3 );

/* The same policy must govern WordPress' subsize health check, otherwise every
 * intentionally skipped size is reported "missing" and regenerated by
 * wp_update_image_subsizes()/readiness checks (all sizes rebuilt anyway). */
function mobo_core_missing_image_subsize_filter( $missing, $image_meta = array(), $attachment_id = 0 ) {
	return Mobo_Core_Image_Size_Policy::filter_missing_subsizes( $missing, $image_meta, $attachment_id );
}
add_filter( 'wp_get_missing_image_subsizes', 'mobo_core_missing_image_subsize_filter', 20, 3 );

/* WC_Data_Store_WP reads custom Product metadata with SQL and updates existing
 * rows by meta ID, bypassing the ordinary metadata filters above. Bridge those
 * surfaces explicitly so WC_Product::get_meta()/save_meta_data() remain exact. */
function mobo_core_terminal_specialized_woocommerce_read_bridge( $raw_meta_data, $object, $data_store = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $data_store );
	return Mobo_Core_Storage_Terminal_Meta_Compat::filter_woocommerce_read_meta( $raw_meta_data, $object );
}
function mobo_core_entity_meta_woocommerce_read_bridge( $raw_meta_data, $object, $data_store = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $data_store );
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_woocommerce_read_meta( $raw_meta_data, $object );
}
function mobo_core_terminal_specialized_get_by_mid_bridge( $check, $meta_id ) {
	return Mobo_Core_Storage_Terminal_Meta_Compat::filter_get_by_mid( $check, $meta_id );
}
function mobo_core_terminal_specialized_update_by_mid_bridge( $check, $meta_id, $meta_value, $meta_key = false ) {
	return Mobo_Core_Storage_Terminal_Meta_Compat::filter_update_by_mid( $check, $meta_id, $meta_value, $meta_key );
}
function mobo_core_terminal_specialized_delete_by_mid_bridge( $check, $meta_id ) {
	return Mobo_Core_Storage_Terminal_Meta_Compat::filter_delete_by_mid( $check, $meta_id );
}
function mobo_core_entity_meta_get_by_mid_bridge( $check, $meta_id ) {
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_get_by_mid( $check, $meta_id );
}
function mobo_core_entity_meta_update_by_mid_bridge( $check, $meta_id, $meta_value, $meta_key = false ) {
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_update_by_mid( $check, $meta_id, $meta_value, $meta_key );
}
function mobo_core_entity_meta_delete_by_mid_bridge( $check, $meta_id ) {
	return Mobo_Core_Storage_Entity_Meta_Retirement::filter_delete_by_mid( $check, $meta_id );
}
add_filter( 'woocommerce_data_store_wp_post_read_meta', 'mobo_core_terminal_specialized_woocommerce_read_bridge', 19, 3 );
add_filter( 'woocommerce_data_store_wp_post_read_meta', 'mobo_core_entity_meta_woocommerce_read_bridge', 20, 3 );
add_filter( 'get_post_metadata_by_mid', 'mobo_core_terminal_specialized_get_by_mid_bridge', 1, 2 );
add_filter( 'update_post_metadata_by_mid', 'mobo_core_terminal_specialized_update_by_mid_bridge', 1, 4 );
add_filter( 'delete_post_metadata_by_mid', 'mobo_core_terminal_specialized_delete_by_mid_bridge', 1, 2 );
add_filter( 'get_post_metadata_by_mid', 'mobo_core_entity_meta_get_by_mid_bridge', 2, 2 );
add_filter( 'update_post_metadata_by_mid', 'mobo_core_entity_meta_update_by_mid_bridge', 2, 4 );
add_filter( 'delete_post_metadata_by_mid', 'mobo_core_entity_meta_delete_by_mid_bridge', 2, 2 );

/* During pre-transition, mirror every committed generic Mobo row by source
 * meta_id. After transition, this same bridge absorbs any request that began
 * before authority changed and immediately removes the late Legacy row. */
function mobo_core_entity_meta_commit_bridge( $meta_id, $object_id, $meta_key, $meta_value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $meta_value );
	if ( ! Mobo_Core_Storage_Entity_Meta_Retirement::is_managed_key( $meta_key ) ) { return; }
	Mobo_Core_Storage_Entity_Meta_Retirement::handle_legacy_commit( $meta_id, $object_id, $meta_key );
}
function mobo_core_entity_meta_delete_bridge( $meta_id, $object_id, $meta_key, $meta_value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $meta_value );
	if ( ! Mobo_Core_Storage_Entity_Meta_Retirement::is_managed_key( $meta_key ) ) { return; }
	Mobo_Core_Storage_Entity_Meta_Retirement::handle_legacy_delete( $meta_id, $object_id, $meta_key );
}
function mobo_core_entity_meta_purge_deleted_object( $post_id, $post = null ) {
	Mobo_Core_Storage_Entity_Meta_Retirement::purge_deleted_object( $post_id, $post );
}
add_action( 'added_post_meta', 'mobo_core_entity_meta_commit_bridge', 30, 4 );
add_action( 'updated_post_meta', 'mobo_core_entity_meta_commit_bridge', 30, 4 );
add_action( 'deleted_post_meta', 'mobo_core_entity_meta_delete_bridge', 30, 4 );
add_action( 'before_delete_post', 'mobo_core_entity_meta_purge_deleted_object', 30, 2 );

/* Retry only when a previous post-terminal late-write/delete cleanup recorded a
 * durable pending marker. Healthy requests do not touch the runtime-state row. */
function mobo_core_entity_meta_late_repair_tick() {
	if ( 1 !== absint( get_option( 'mobo_core_entity_meta_late_repair_pending_v1', 0 ) ) ) { return; }
	Mobo_Core_Storage_Entity_Meta_Retirement::drain_late_failures();
}
add_action( 'init', 'mobo_core_entity_meta_late_repair_tick', 1 );

/*
 * 10.33.89 controlled operational-state suppression. This runs before the
 * Legacy write-decision guard. Revision watermarks, source hashes and the final
 * mobo_sync_incomplete completion marker are Custom-first only after their
 * per-key readiness lease and exact commit-group guards pass. Direct REST, add,
 * and delete metadata paths remain Legacy-authoritative.
 */
function mobo_core_custom_storage_postmeta_write_suppression( $check, $object_id, $meta_key, $meta_value = null, $prev_value = '' ) {
	static $keys = array(
		'_mobo_product_seen_revision'=>true, '_mobo_product_applied_revision'=>true,
		'_mobo_variant_seen_revision'=>true, '_mobo_variant_applied_revision'=>true,
		'_mobo_product_source_hash'=>true, '_mobo_variant_source_hash'=>true,
		'mobo_sync_incomplete'=>true,
	);
	if ( ! isset( $keys[ (string) $meta_key ] ) || null !== $check ) { return $check; }
	return Mobo_Core_Storage_Postmeta_Write_Suppression::filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value );
}
add_filter( 'update_post_metadata', 'mobo_core_custom_storage_postmeta_write_suppression', 4, 5 );

/*
 * 10.33.78 write-decision guard. WordPress update_metadata() reads the current
 * value through get_metadata_raw() before deciding whether a write is a no-op.
 * For operational keys, pin the object to Legacy before that read so Custom
 * state can never influence whether authoritative postmeta is written. The pin
 * intentionally lasts for the remainder of the request.
 */
function mobo_core_custom_storage_state_write_guard( $check, $object_id, $meta_key, $meta_value = null, $extra = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $meta_value, $extra );
	if ( null !== $check ) { return $check; }
	static $keys = array(
		'_mobo_product_seen_revision'=>true, '_mobo_product_applied_revision'=>true,
		'_mobo_variant_seen_revision'=>true, '_mobo_variant_applied_revision'=>true,
		'_mobo_product_source_hash'=>true, '_mobo_variant_source_hash'=>true,
		'mobo_sync_incomplete'=>true,
	);
	if ( ! isset( $keys[ (string) $meta_key ] ) ) { return $check; }
	if ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' ) && Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced() ) { return $check; }
	$requested = sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) );
	if ( in_array( $requested, array( 'custom-primary', 'custom-only' ), true ) ) {
		Mobo_Core_Storage_Primary_State_Read::mark_legacy_mutation( $object_id );
	}
	return $check;
}
add_filter( 'add_post_metadata', 'mobo_core_custom_storage_state_write_guard', 5, 5 );
add_filter( 'update_post_metadata', 'mobo_core_custom_storage_state_write_guard', 5, 5 );
add_filter( 'delete_post_metadata', 'mobo_core_custom_storage_state_write_guard', 5, 5 );

/*
 * 10.33.78 operational-state rollout boundary. Shadow mode still observes the
 * authoritative Legacy value. Under a fresh promotion lease, exact schema-v3
 * Custom state with metadata-presence fidelity may short-circuit direct Mobo state reads. Any real Custom drift
 * records a primary fallback, blocks further Custom-Primary reads in the same
 * request, and returns null so WordPress reads authoritative Legacy postmeta.
 * Expected read-after-write/mirror scopes bypass Custom without poisoning the gate.
 */
function mobo_core_custom_storage_state_read_rollout( $check, $object_id, $meta_key, $single = false, $meta_type = 'post' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	unset( $meta_type );
	static $keys = array(
		'_mobo_product_seen_revision'=>true, '_mobo_product_applied_revision'=>true,
		'_mobo_variant_seen_revision'=>true, '_mobo_variant_applied_revision'=>true,
		'_mobo_product_source_hash'=>true, '_mobo_variant_source_hash'=>true,
		'mobo_sync_incomplete'=>true,
	);
	if ( ! isset( $keys[ (string) $meta_key ] ) || null !== $check ) { return $check; }
	if ( class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' )
		&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
		&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $object_id, $meta_key ) ) {
		$current = Mobo_Core_Storage_Postmeta_Write_Suppression::terminal_current( $object_id, $meta_key );
		if ( 'hit' === ( $current['status'] ?? '' ) ) {
			if ( empty( $current['present'] ) ) { return $single ? '' : array(); }
			/* Present single values stay wrapped so metadata_exists() is true even
			 * for valid false-y values such as the explicit string '0'. */
			return array( isset( $current['value'] ) ? $current['value'] : '' );
		}
		/* Missing Custom rows after terminal cutover are corruption only for a
		 * Mobo-owned object. Unrelated Woo Products must not become sync-incomplete
		 * merely because they have no Mobo state row. */
		if ( 'mobo_sync_incomplete' === (string) $meta_key
			&& Mobo_Core_Storage_Operational_Meta_Retirement::object_is_mobo( $object_id ) ) { return array( '1' ); }
		return $single ? '' : array();
	}
	$requested = sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) );
	if ( ! in_array( $requested, array( 'shadow-read', 'custom-primary', 'custom-only' ), true ) ) { return $check; }

	if ( in_array( $requested, array( 'custom-primary', 'custom-only' ), true ) ) {
		$attempt = Mobo_Core_Storage_Primary_State_Read::read_meta( $object_id, $meta_key );
		$status  = sanitize_key( (string) ( isset( $attempt['status'] ) ? $attempt['status'] : '' ) );
		if ( 'hit' === $status ) {
			$present = ! array_key_exists( 'present', $attempt ) || ! empty( $attempt['present'] );
			if ( ! $present ) {
				if ( class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) && Mobo_Core_Storage_Mode::reads_custom_only() ) {
					Mobo_Core_Storage_Legacy_Dependency::record_read_dependency( 'state_presence_legacy_fallback', $object_id, $meta_key );
				}
				/*
				 * Absence intentionally falls through to Legacy. metadata_exists() uses
				 * this same filter with $single=true and casts the raw filter result to
				 * bool, while get_metadata_raw() unwraps a non-empty array for single
				 * reads. Returning an empty Custom value here would therefore make it
				 * impossible to preserve all three contracts (absent, explicit 0, and
				 * multi-value shape) without consulting Legacy. This is a safe bypass,
				 * not Custom drift, so it must not trip the primary circuit breaker.
				 */
				Mobo_Core_Storage_State_Parity::observe_meta_read( $object_id, $meta_key );
				return $check;
			}
			$value = isset( $attempt['value'] ) ? $attempt['value'] : '';
			if ( class_exists( 'Mobo_Core_Storage_Primary_Usage' ) ) { Mobo_Core_Storage_Primary_Usage::record_state_hit( $object_id, $meta_key ); }
			if ( Mobo_Core_Storage_Mode::reads_custom_only() && class_exists( 'Mobo_Core_Storage_Postmeta_Suppression_Readiness' ) ) {
				Mobo_Core_Storage_Postmeta_Suppression_Readiness::record_custom_read( $object_id, $meta_key );
			}
			/*
			 * Always return a one-item array for a present meta value. WordPress
			 * unwraps it for get_post_meta(..., true), but metadata_exists() sees a
			 * truthy array even when the stored value is the explicit string '0'.
			 */
			return array( $value );
		}
		if ( 'fallback' === $status ) {
			$post_type = get_post_type( absint( $object_id ) );
			if ( Mobo_Core_Storage_Mode::reads_custom_only() && class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ) {
				Mobo_Core_Storage_Legacy_Dependency::record_read_dependency(
					(string) ( isset( $attempt['reason'] ) ? $attempt['reason'] : 'state_custom_primary_fallback' ),
					$object_id,
					$meta_key
				);
			}
			Mobo_Core_Storage_Primary_Read::record_fallback(
				'product_variation' === $post_type ? 'variant_state' : 'product_state',
				(string) ( isset( $attempt['guid'] ) ? $attempt['guid'] : '' ),
				(string) ( isset( $attempt['reason'] ) ? $attempt['reason'] : 'state_custom_primary_fallback' ),
				absint( isset( $attempt['customId'] ) ? $attempt['customId'] : 0 ),
				absint( $object_id )
			);
			Mobo_Core_Storage_State_Parity::observe_meta_read( $object_id, $meta_key );
			return $check;
		}
		if ( 'bypass' === $status ) {
			if ( class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) && Mobo_Core_Storage_Mode::reads_custom_only() ) {
				Mobo_Core_Storage_Legacy_Dependency::record_read_dependency(
					(string) ( isset( $attempt['reason'] ) ? $attempt['reason'] : 'state_legacy_bypass' ),
					$object_id,
					$meta_key
				);
			}
			return $check;
		}
	}

	Mobo_Core_Storage_State_Parity::observe_meta_read( $object_id, $meta_key );
	return $check;
}
add_filter( 'get_post_metadata', 'mobo_core_custom_storage_state_read_rollout', 20, 5 );

/* Custom-storage lifecycle cleanup remains side-channel cleanup for guarded Custom Storage reads in 10.33.76. */
add_action( 'trashed_post', array( 'Mobo_Core_Storage_Reconciler', 'handle_trashed_post' ), 10, 1 );
add_action( 'deleted_post', array( 'Mobo_Core_Storage_Reconciler', 'handle_deleted_post' ), 10, 2 );
add_action( 'untrashed_post', array( 'Mobo_Core_Storage_Reconciler', 'handle_untrashed_post' ), 10, 1 );

/* Durable retry hook for per-product pricing overrides. The class stays lazy-loaded. */
add_action( 'mobo_core_apply_product_pricing_override', array( 'Mobo_Core_Product_Pricing_Policy', 'handle_retry_action' ), 10, 2 );

/* C1: notify-only guard for a WooCommerce currency change after prices were synced. Lazy-loaded. */
add_action( 'update_option_woocommerce_currency', array( 'Mobo_Core_Store_Currency_Guard', 'on_currency_option_updated' ), 10, 2 );
add_action( 'admin_notices', array( 'Mobo_Core_Store_Currency_Guard', 'render_notice' ) );
add_action( 'admin_post_mobo_core_dismiss_currency_change', array( 'Mobo_Core_Store_Currency_Guard', 'handle_dismiss' ) );

/* M8: quarantined non-canonical duplicate copies must not sell with a frozen price.
 * The hot hooks stay tiny; the guard class loads only on a real quarantine change
 * or a product trash/draft transition. */
function mobo_core_quarantine_sale_guard_option_changed( $option ) {
	if ( 0 === strpos( (string) $option, 'mobo_core_migration_quarantine_v1' ) ) {
		Mobo_Core_Quarantine_Sale_Guard::schedule_reconcile();
	}
}
add_action( 'added_option', 'mobo_core_quarantine_sale_guard_option_changed', 10, 1 );
add_action( 'updated_option', 'mobo_core_quarantine_sale_guard_option_changed', 10, 1 );
add_action( 'deleted_option', 'mobo_core_quarantine_sale_guard_option_changed', 10, 1 );
function mobo_core_quarantine_sale_guard_transition( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || ( 'trash' !== $new_status && 'draft' !== $new_status ) || ! is_object( $post ) || 'product' !== ( $post->post_type ?? '' ) ) {
		return;
	}
	Mobo_Core_Quarantine_Sale_Guard::handle_transition( $new_status, $old_status, $post );
}
add_action( 'transition_post_status', 'mobo_core_quarantine_sale_guard_transition', 10, 3 );

/* S1: clone/staging copy guard notice (Source purchases and wallet payments blocked). Lazy-loaded. */
add_action( 'admin_notices', array( 'Mobo_Core_Clone_Guard', 'render_admin_notice' ) );

/* B23: excluded products keep selling with frozen price/stock; warn on their edit screen. */
add_action( 'admin_notices', array( 'Mobo_Core_Product_Exclusions', 'render_product_edit_notice' ) );

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
 * Whether this request is one of the budgeted worker routes (/cron/run, /worker/run)
 * that drive migration themselves inside the request deadline.
 *
 * @param string|null $request_uri Optional REQUEST_URI (tests).
 * @param string|null $rest_route  Optional ?rest_route= value (tests).
 * @return bool
 */
function mobo_core_is_budgeted_worker_route( $request_uri = null, $rest_route = null ) {
	if ( null === $rest_route ) {
		$rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request-context detection.
	}
	if ( null === $request_uri ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}
	return Mobo_Core_Request_Deadline::is_budgeted_worker_route( (string) $request_uri, (string) $rest_route );
}

/**
 * Unstamped database on an ordinary request: do not migrate here, only make sure
 * the background runner gets woken up. At most once per minute site-wide, never
 * when a recurring real cron is already driving the site, and only at shutdown
 * through the throttled, lease-protected, non-blocking self-runner kick.
 *
 * @return void
 */
function mobo_core_nudge_background_migration() {
	$now  = time();
	$last = absint( get_option( 'mobo_core_migration_last_nudge_at', 0 ) );
	if ( $last > 0 && $last <= $now && ( $now - $last ) < 60 ) {
		return;
	}
	update_option( 'mobo_core_migration_last_nudge_at', $now, false );
	$last_cron_hit = absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) );
	if ( $last_cron_hit > 0 && $last_cron_hit <= $now && ( $now - $last_cron_hit ) < 300 ) {
		return;
	}
	add_action(
		'shutdown',
		static function () {
			if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
				try {
					Mobo_Core_Self_Runner::kick( 'migration-nudge', false );
				} catch ( Throwable $e ) {
					unset( $e ); // Best effort; the next nudge or real cron retries.
				}
			}
		},
		1
	);
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
	$name = sanitize_key( (string) $name );
	if ( class_exists( 'Mobo_Core_Connection_Settings' ) && Mobo_Core_Connection_Settings::is_scoped_key( $name ) ) {
		return Mobo_Core_Connection_Settings::any_enabled_boolean( $name, $default );
	}
	$value = get_option( $name, $default );
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
	$value = defined( $name ) ? constant( $name ) : Mobo_Core_Environment::env( $name );
	return in_array( strtolower( trim( false === $value || null === $value ? '' : (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Whether a migration repair that must run on init is currently pending.
 *
 * @return bool
 */
function mobo_core_has_deferred_repair() {
	/*
	 * REL29 release-hygiene bootstrap:
	 *
	 * The 10.34.77 one-time hygiene pass is intentionally able to arm itself on
	 * the first normal request after the final candidate is installed, including a
	 * same-version candidate replacement. Without this bootstrap, the only code
	 * that creates the pending options would live inside run_deferred_repairs(),
	 * while that method itself would never be hooked until a pending option already
	 * existed.
	 *
	 * scheduled_at is durable once the pass has been armed. If a repair remains
	 * incomplete, its dedicated pending option below keeps the hook alive.
	 */
	if ( '4' !== (string) get_option( 'mobo_core_103477_release_hygiene_revision', '' )
		|| absint( get_option( 'mobo_core_103477_release_hygiene_scheduled_at', 0 ) ) <= 0 ) {
		return true;
	}

	foreach ( array(
		'mobo_core_stage7_resume_kick_pending',
		'mobo_core_category_placeholder_repair_pending',
		'mobo_core_image_queue_recovery_pending',
		'mobo_core_103353_convergence_residue_selfheal_pending',
		'mobo_core_103454_singleton_meta_dedupe_pending',
		'mobo_core_103473_post_upgrade_convergence_pending',
		'mobo_core_103477_revenue_ledger_backfill_pending',
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
			/* M9: Checkout Block / Store API pre-payment validation runs on the place
			 * order POST only (woocommerce_store_api_checkout_update_order_from_request,
			 * with an order_processed fallback), validates the submitted address and
			 * stops checkout with a Store API RouteException (HTTP 400, Persian message). */
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				MOBO_CORE_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Backward-compatible API base URL filter.
 *
 * Durable Connection runtime resolves the provisioned api_base_url unless an explicit
 * MOBO_API_BASE_URL environment/developer override is defined. This filter remains
 * for legacy call-sites during the staged ownership migration.
 */
add_filter(
	'mobo_core_api_base_url',
	function ( $base_url ) {
		/* Once the durable Connection table exists, provisioned Connection routing is
		 * authoritative. Do not reuse an incoming legacy/default URL when the
		 * explicit owner is disabled, deleted or otherwise unavailable. */
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			return Mobo_Core_Connection_Repository::get_runtime_base_url( '' );
		}

		if ( is_string( $base_url ) && '' !== trim( $base_url ) ) {
			return $base_url;
		}

		return defined( 'MOBO_API_BASE_URL' ) ? (string) MOBO_API_BASE_URL : '';
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
 * Deactivation: an active support access is revoked (role removed, password
 * rotated, sessions destroyed) so no support administrator outlives the plugin.
 *
 * @return void
 */
function mobo_core_deactivate_support_access() {
	$state = get_option( 'mobo_core_support_access_state', array() );
	if ( is_array( $state ) && 'granted' === (string) ( $state['status'] ?? '' ) && class_exists( 'Mobo_Core_Support_Access' ) ) {
		Mobo_Core_Support_Access::revoke( 'revoked', 'deactivation' );
	}
}
register_deactivation_hook( __FILE__, 'mobo_core_deactivate_support_access' );

/**
 * Bootstrap plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! Mobo_Core_Dependencies::platform_requirements_met() ) {
			Mobo_Core_Dependencies::register_platform_notice();
			return;
		}
		$missing_dependencies = Mobo_Core_Dependencies::get_missing_dependencies();
		if ( ! empty( $missing_dependencies ) ) {
			Mobo_Core_Dependencies::register_admin_notices( $missing_dependencies );
			return;
		}

		/*
		 * Do not parse the large migration component on every frontend request.
		 * It is loaded only after an actual plugin-version change, a stale durable
		 * Connection-settings contract marker, or an init-dependent repair flag.
		 */
		$current_db_version = (string) get_option( 'mobo_core_db_version', '' );
		$connection_settings_contract_pending = (string) get_option( 'mobo_core_connection_settings_contract_version', '' ) !== MOBO_CORE_CONNECTION_SETTINGS_CONTRACT_VERSION;
		$is_budgeted_cron_bootstrap = defined( 'MOBO_CORE_LOCAL_PHP_CRON' ) && MOBO_CORE_LOCAL_PHP_CRON;
		if ( MOBO_CORE_VERSION !== $current_db_version && ! $is_budgeted_cron_bootstrap ) {
			/* mobo-cron.php defines its context before wp-load.php. Let the dedicated
			 * runner own the first migration pass so one Cron request cannot replay the
			 * full historical pipeline once during bootstrap and again inside its slice.
			 *
			 * Runtime hardening: the full pipeline never runs synchronously inside a
			 * storefront/admin/REST page load any more (first page view took 13-26 s
			 * and a product page hit the 30 s fatal). The cron/worker routes leave it
			 * to the budgeted runner slice; every other request only nudges the
			 * self-runner (throttled, non-blocking, at shutdown). WP-CLI has no time
			 * limit and keeps the full pass. */
			$bootstrap_action = Mobo_Core_Request_Deadline::migration_bootstrap_action(
				defined( 'WP_CLI' ) && WP_CLI,
				mobo_core_is_budgeted_worker_route()
			);
			if ( 'full-pass' === $bootstrap_action ) {
				Mobo_Core_Migration::maybe_run();
			} elseif ( 'nudge' === $bootstrap_action ) {
				mobo_core_nudge_background_migration();
			}
		} elseif ( $connection_settings_contract_pending && ! $is_budgeted_cron_bootstrap ) {
			/* WIP/replacement builds can expand the Source-owned settings contract
			 * without changing MOBO_CORE_VERSION. Run the retry-safe terminal cutover once per contract revision: seed only
			 * missing Default rows, retire scoped legacy options, and preserve existing
			 * durable Connection values. */
			Mobo_Core_Migration::ensure_connection_settings_contract();
		}
		if ( mobo_core_has_deferred_repair() ) {
			add_action( 'init', array( 'Mobo_Core_Migration', 'run_deferred_repairs' ), 20 );
		}

		/* Source status guard: products hidden/deactivated on the Source stay published (SEO)
		 * but are out of stock and not purchasable on every request path (storefront, cart,
		 * checkout, REST). Filters only read already-loaded product meta. */
		Mobo_Core_Source_Visibility::init();

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
		$order_submission_enabled = Mobo_Core_Order_Submission_Policy::is_enabled_for_any_connection();
		$checkout_runtime_enabled = $order_submission_enabled
			|| ( $checkout_validation_master_enabled && Mobo_Core_Connection_Settings::any_enabled_boolean( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' ) )
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

		}

		/* Mapping-only shipping policy is enforced at registration time and legacy
		 * WooCommerce residues are re-proved on wp_loaded. The policy marker is only a
		 * checkpoint; a manually/stale re-enabled Mobo method must be retired again. */
		$automatic_shipping = new Mobo_Core_Automatic_Shipping();
		$automatic_shipping->init();
		add_action(
			'wp_loaded',
			function () use ( $automatic_shipping ) {
				$run_cleanup = static function () use ( $automatic_shipping ) {
					$automatic_shipping->retire_legacy_runtime( 'policy-10.33.0' );
				};
				if ( class_exists( 'Mobo_Core_Connection_Repository' ) && class_exists( 'Mobo_Core_Connection_Context' ) ) {
					$connections = Mobo_Core_Connection_Repository::list_enabled();
					if ( ! empty( $connections ) ) {
						foreach ( $connections as $connection ) {
							$connection_id = is_array( $connection ) ? absint( $connection['id'] ?? 0 ) : 0;
							if ( $connection_id <= 0 ) { continue; }
							Mobo_Core_Connection_Context::run( $connection_id, $run_cleanup );
						}
						return;
					}
				}
				$run_cleanup();
			},
			1
		);

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

		/* Support access expiry guard: only logged-in requests read the small
		 * autoloaded state option; the support class is parsed only when an
		 * access has actually expired. Cron and wp-admin enforce expiry too. */
		add_action(
			'init',
			function () {
				if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
					return;
				}
				$state   = get_option( 'mobo_core_support_access_state', array() );
				$state   = is_array( $state ) ? $state : array();
				$expired = 'granted' === (string) ( $state['status'] ?? '' ) && absint( $state['expiresAt'] ?? 0 ) <= time();
				$user    = wp_get_current_user();
				$login   = is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '';
				$is_candidate = get_current_user_id() === absint( $state['userId'] ?? 0 ) || in_array( $login, array( 'MoboCore', 'mobocore-support' ), true );
				if ( ( ! $expired && ! $is_candidate ) || ! class_exists( 'Mobo_Core_Support_Access' ) ) {
					return;
				}
				/* Per-request session validation: expire a passed grant and log
				 * the support user out whenever its access is not live (revoked,
				 * expired, never granted, or state pointing at another user). */
				Mobo_Core_Support_Access::enforce_current_session();
			},
			1
		);

		/* Support user is powerless without a live grant on every path
		 * (capabilities) and never gets Application Passwords. Ordinary users
		 * exit before the support class is parsed. */
		$mobo_support_candidate = static function ( $user ) {
			if ( ! is_object( $user ) || empty( $user->ID ) ) {
				return false;
			}
			$state = get_option( 'mobo_core_support_access_state', array() );
			$sid   = is_array( $state ) ? absint( $state['userId'] ?? 0 ) : 0;
			$login = isset( $user->user_login ) ? (string) $user->user_login : '';
			return ( absint( $user->ID ) === $sid || in_array( $login, array( 'MoboCore', 'mobocore-support' ), true ) ) && class_exists( 'Mobo_Core_Support_Access' );
		};
		add_filter(
			'user_has_cap',
			static function ( $allcaps, $caps, $args, $user ) use ( $mobo_support_candidate ) {
				return $mobo_support_candidate( $user ) ? Mobo_Core_Support_Access::filter_user_has_cap( $allcaps, $caps, $args, $user ) : $allcaps;
			},
			PHP_INT_MAX,
			4
		);
		add_filter(
			'wp_is_application_passwords_available_for_user',
			static function ( $available, $user ) use ( $mobo_support_candidate ) {
				return $mobo_support_candidate( $user ) ? Mobo_Core_Support_Access::filter_application_passwords_available( $available, $user ) : $available;
			},
			10,
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
