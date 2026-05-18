<?php
/**
 * Uninstall cleanup.
 *
 * Important:
 * Business data and legacy customer settings are intentionally preserved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Remove runtime sync state.
 */
delete_option( 'mobo_core_sync_state' );
delete_option( 'mobo_core_db_version' );

/*
 * Remove runtime/chunking options introduced by v2.
 * Legacy business settings are preserved.
 */
delete_option( 'mobo_core_sync_time_budget_seconds' );
delete_option( 'mobo_core_webhook_files_per_run' );
delete_option( 'mobo_core_webhook_max_try' );
delete_option( 'mobo_core_webhook_expire_days' );
delete_option( 'mobo_core_products_per_page' );
delete_option( 'mobo_core_variants_per_page' );
delete_option( 'mobo_core_images_per_run' );
delete_option( 'mobo_core_missing_variants_behavior' );

/*
 * Remove old v2 beta option if it exists.
 */
delete_option( 'mobo_core_enable_wp_cron' );

/*
 * Remove temporary seen-variant options.
 */
global $wpdb;

$prefix = $wpdb->esc_like( 'mobo_seen_variants_' ) . '%';

$option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$prefix
	)
);

if ( is_array( $option_names ) ) {
	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}
}

/*
 * Remove transient locks.
 */
$lock_prefix    = $wpdb->esc_like( '_transient_mobo_core_lock_' ) . '%';
$timeout_prefix = $wpdb->esc_like( '_transient_timeout_mobo_core_lock_' ) . '%';

$transient_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$lock_prefix,
		$timeout_prefix
	)
);

if ( is_array( $transient_names ) ) {
	foreach ( $transient_names as $transient_name ) {
		delete_option( $transient_name );
	}
}

/*
 * Do NOT delete:
 *
 * - WooCommerce products
 * - WooCommerce variations
 * - WooCommerce categories
 * - WooCommerce images
 * - product_guid
 * - variant_guid
 * - category_guid
 * - image_guid
 * - img_guid
 * - attribute_guid
 * - mobo_additional_price
 * - mobo_default_category_id
 * - global_product_auto_stock
 * - global_product_auto_price
 * - global_product_auto_title
 * - global_product_auto_caption
 * - global_product_auto_compare_price
 * - global_product_auto_slug
 * - global_update_categories
 * - global_update_images
 * - mobo_price_type
 * - global_additional_price
 * - global_additional_percentage
 * - mobo_dynamic_price
 * - mobo_core_security_code
 * - mobo_core_api_base_url
 * - mobo_core_api_token
 * - mobo_core_only_in_stock
 */