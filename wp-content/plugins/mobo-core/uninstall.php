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


/*
 * This component operates on Mobo Core's internal queue/map tables. Direct
 * database access is required for atomic batching and cursor updates; table
 * identifiers are generated internally and all external values are prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}


/**
 * Return the WordPress filesystem implementation used by uninstall cleanup.
 *
 * @return WP_Filesystem_Base|null
 */
function mobo_core_uninstall_filesystem() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;

	if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() && is_object( $wp_filesystem ) ) {
		return $wp_filesystem;
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	return new WP_Filesystem_Direct( false );
}

/**
 * Remove Mobo Core runtime state while preserving business data.
 *
 * @return void
 */
function mobo_core_uninstall_runtime_state() {
	/*
	 * Remove runtime sync state.
	 */
	delete_option( 'mobo_core_sync_state' );
	delete_option( 'mobo_core_db_version' );
	delete_option( 'mobo_core_schema_version' );

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
	delete_option( 'mobo_core_cron_token' );
	delete_option( 'mobo_core_real_cron_last_hit_at' );
	delete_option( 'mobo_core_real_cron_last_success_at' );
	delete_option( 'mobo_core_real_cron_last_result' );
	delete_option( 'mobo_core_real_cron_time_budget_seconds' );
	delete_option( 'mobo_core_real_cron_max_sync_steps' );
	delete_option( 'mobo_core_real_cron_lock_ttl_seconds' );
	delete_option( 'mobo_core_real_cron_expected_interval_seconds' );
	delete_option( 'mobo_core_real_cron_process_webhooks' );
	delete_option( 'mobo_core_process_webhook_on_receive' );
	delete_option( 'mobo_core_webhook_queue_last_attempt_at' );
	delete_option( 'mobo_core_webhook_queue_last_success_at' );
	delete_option( 'mobo_core_webhook_queue_last_activity_at' );
	delete_option( 'mobo_core_webhook_queue_last_result' );
	delete_option( 'mobo_core_city_assets_status' );
	delete_option( 'mobo_core_pw_options_last_check_at' );
	delete_option( 'mobo_core_pw_options_last_enforced' );
	delete_option( 'mobo_core_image_refresh_automation_enabled' );
	delete_option( 'mobo_core_image_refresh_auto_delete_old_approved' );
	delete_option( 'mobo_core_image_refresh_auto_delete_orphan_approved' );
	delete_option( 'mobo_core_image_refresh_automation_started_at' );
	delete_option( 'mobo_core_image_refresh_automation_completed_at' );
	delete_option( 'mobo_core_image_refresh_automation_last_result' );
	delete_option( 'mobo_core_image_refresh_automation_last_run_at' );
	delete_option( 'mobo_core_image_refresh_automation_last_tick_started_at' );
	delete_option( 'mobo_core_image_refresh_automation_last_tick_finished_at' );
	delete_option( 'mobo_core_image_refresh_automation_last_tick_source' );
	delete_transient( 'mobo_core_pw_options_enforced_notice' );

	/*
	 * Generated checkout city files are runtime assets, not business data.
	 */
	$upload = wp_upload_dir( null, false );
	$filesystem = mobo_core_uninstall_filesystem();
	if ( $filesystem && empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$base_dir = trailingslashit( (string) $upload['basedir'] );
		$asset_dirs = array(
			$base_dir . 'mobo-core-public/assets/',
			$base_dir . 'mobo-core/assets/', // Legacy private location used before 10.31.56.
		);
		foreach ( $asset_dirs as $asset_dir ) {
			foreach ( array( 'iran_cities.js', 'iran_cities.min.js', 'index.html', '.htaccess' ) as $asset_name ) {
				$asset_path = $asset_dir . $asset_name;
				if ( $filesystem->exists( $asset_path ) ) {
					$filesystem->delete( $asset_path, false, 'f' );
				}
			}
			if ( $filesystem->is_dir( $asset_dir ) ) {
				$filesystem->rmdir( untrailingslashit( $asset_dir ), false );
			}
		}
		$public_root = $base_dir . 'mobo-core-public/';
		if ( $filesystem->is_dir( $public_root ) ) {
			$filesystem->rmdir( untrailingslashit( $public_root ), false );
		}
	}

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
	 * - mobo_core_token
	 * - mobo_core_only_in_stock
	 * - mobo_core_security_code
	 * - mobo_core_api_base_url
	 * - custom Mobo Core tables; resume/map data is preserved intentionally
	 */
}

mobo_core_uninstall_runtime_state();
