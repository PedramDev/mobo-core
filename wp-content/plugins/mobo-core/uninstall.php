<?php
/**
 * Uninstall cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'mobo_core_sync_state' );
delete_option( 'mobo_core_db_version' );

/*
 * Runtime/config options removed.
 * Business data and GUID meta are intentionally preserved.
 */
delete_option( 'mobo_core_enable_wp_cron' );
delete_option( 'mobo_core_sync_time_budget_seconds' );
delete_option( 'mobo_core_webhook_files_per_run' );
delete_option( 'mobo_core_webhook_max_try' );
delete_option( 'mobo_core_webhook_expire_days' );
delete_option( 'mobo_core_products_per_page' );
delete_option( 'mobo_core_variants_per_page' );
delete_option( 'mobo_core_images_per_run' );
delete_option( 'mobo_core_missing_variants_behavior' );