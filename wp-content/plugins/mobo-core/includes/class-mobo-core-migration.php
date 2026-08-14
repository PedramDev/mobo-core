<?php
/**
 * Migration helper.
 *
 * Responsibilities:
 * - create missing defaults
 * - create/update local data directories
 * - discard legacy plugin-directory webhook queue files that are no longer needed
 * - create/update local sync database tables
 * - seed product/variation map from legacy meta in bounded batches
 * - clear old WP-Cron hooks from previous versions
 *
 * PHP 7.4 compatible.
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
class Mobo_Core_Migration {

	/**
	 * Activation hook.
	 *
	 * Activation must be safe for existing customer installs. It must never delete
	 * the active uploads-based webhook queue. Legacy plugin-directory JSON files
	 * from 7.4 are intentionally discarded because they are no longer required.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_defaults();
		self::apply_10331613_runtime_option_autoload_cleanup( '' );
		self::apply_10307_default_adjustments( '' );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		self::create_database_tables();
		self::apply_10331614_table_housekeeping_fast_path( '' );
		self::apply_103171_runtime_lock_recovery( '' );
		self::apply_103164_image_family_migration( '' );
		self::apply_103165_image_refresh_safety( '' );
		self::apply_103166_admin_health_defaults( '' );
		self::apply_103167_image_workflow_safety( '' );
		self::apply_103168_image_automation_safety( '' );
		self::apply_103177_desired_state_repair( '' );
		self::apply_103194_health_pull_only( '' );
		self::apply_103198_manual_initial_sync_safety( '' );
		self::apply_103199_image_queue_recovery( '' );
		self::apply_10333_archive_purge_interval_migration( '' );
		self::apply_10338_legacy_image_catalog_rescan( '' );
		self::apply_10339_image_reference_migration( '' );
		self::apply_103310_structured_image_reference_migration( '' );
		self::apply_103311_replaced_attachment_scan_runtime_safety( '' );
		self::apply_103313_persistent_delete_old_preference( '' );
		self::apply_103315_nonblocking_stage7( '' );
		self::apply_103316_automatic_stage7_convergence( '' );
		self::apply_1033161_install_bootstrap_safety( '' );
		self::apply_1033171_stage7_autodrain( '' );
		self::maybe_mark_legacy_repair_required( '' );
		self::seed_product_map_from_legacy_meta();
		self::seed_category_map_from_legacy_meta();
		self::discard_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();

		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	/**
	 * Run lightweight migrations if version changed.
	 *
	 * Important:
	 * This method never deletes the active uploads-based webhook queue. It only
	 * discards legacy plugin-directory JSON files from 7.4.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		$current = get_option( 'mobo_core_db_version', '' );

		if ( MOBO_CORE_VERSION === $current ) {
			return;
		}

		self::ensure_defaults();
		self::apply_10331613_runtime_option_autoload_cleanup( $current );
		self::apply_10307_default_adjustments( $current );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		self::create_database_tables();
		self::apply_10331614_table_housekeeping_fast_path( $current );
		self::apply_103171_runtime_lock_recovery( $current );
		self::apply_103164_image_family_migration( $current );
		self::apply_103165_image_refresh_safety( $current );
		self::apply_103166_admin_health_defaults( $current );
		self::apply_103167_image_workflow_safety( $current );
		self::apply_103168_image_automation_safety( $current );
		self::apply_103177_desired_state_repair( $current );
		self::apply_103194_health_pull_only( $current );
		self::apply_103198_manual_initial_sync_safety( $current );
		self::apply_103199_image_queue_recovery( $current );
		self::apply_10333_archive_purge_interval_migration( $current );
		self::apply_10338_legacy_image_catalog_rescan( $current );
		self::apply_10339_image_reference_migration( $current );
		self::apply_103310_structured_image_reference_migration( $current );
		self::apply_103311_replaced_attachment_scan_runtime_safety( $current );
		self::apply_103313_persistent_delete_old_preference( $current );
		self::apply_103315_nonblocking_stage7( $current );
		self::apply_103316_automatic_stage7_convergence( $current );
		self::apply_1033161_install_bootstrap_safety( $current );
		self::apply_1033171_stage7_autodrain( $current );
		self::maybe_mark_legacy_repair_required( $current );
		self::seed_product_map_from_legacy_meta();
		self::seed_category_map_from_legacy_meta();
		self::discard_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();

		/*
		 * Cleanup old beta option if it exists.
		 * WP-Cron is not used in final architecture.
		 */
		delete_option( 'mobo_core_enable_wp_cron' );

		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	/**
	 * Add missing default options only.
	 *
	 * Existing customer settings are never overwritten.
	 *
	 * @return void
	 */
	private static function ensure_defaults() {
		$defaults = Mobo_Core_Settings::defaults();
		if ( method_exists( 'Mobo_Core_Settings', 'prime_options' ) ) {
			Mobo_Core_Settings::prime_options( array_keys( $defaults ) );
		}

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value, '', false );
			}
		}
	}


	/**
	 * Schedule an early bounded housekeeping pass after the 10.33.16.14 indexes
	 * have been created by dbDelta. No active queue rows are modified here; the
	 * real-cron maintenance worker applies the normal conservative retention rules.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10331614_table_housekeeping_fast_path( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.16.14', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_maintenance_next_due_at', time() + 60, false );
		update_option( 'mobo_core_10331614_housekeeping_scheduled_at', time(), false );
	}

	/**
	 * Normalize Mobo runtime/settings options to non-autoloaded storage.
	 *
	 * Current installs already create every Mobo option with autoload=false. Very
	 * old installations can still carry queue/state/history rows in alloptions,
	 * making every storefront request deserialize worker data it never uses. Keep
	 * the intended architecture consistent by moving legacy mobo_core_* rows out
	 * of the global autoload payload without changing their values.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10331613_runtime_option_autoload_cleanup( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.16.13', '>=' ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb->options ) ) {
			return;
		}

		$autoload_values = array( 'yes', 'on', 'auto-on', 'auto' );
		$placeholders     = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name LIKE %s AND autoload IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $wpdb->esc_like( 'mobo_core_' ) . '%' ), $autoload_values )
		);
		$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the immediate result of wpdb::prepare() above.
		$changed = false === $changed ? 0 : absint( $changed );

		/* alloptions may already be resident in this upgrade request. Force the next
		 * read to rebuild it without the rows moved above. */
		wp_cache_delete( 'alloptions', 'options' );
		update_option( 'mobo_core_10331613_autoload_cleanup_count', $changed, false );
		update_option( 'mobo_core_10331613_autoload_cleanup_at', time(), false );
	}

	/**
	 * Adjust defaults introduced in 10.30.7 without disturbing custom values.
	 *
	 * Existing values are changed only when they still match the old defaults.
	 * This keeps customer overrides intact while moving untouched installs to the
	 * safer requested defaults.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10307_default_adjustments( $previous_version ) {
		if ( '' !== (string) $previous_version && version_compare( (string) $previous_version, '10.30.7', '>=' ) ) {
			return;
		}

		self::update_option_if_current_value( 'mobo_core_webhook_files_per_run', 1, 4 );
		self::update_option_if_current_value( 'mobo_core_missing_variants_behavior', 'ignore', 'outofstock' );
		self::update_option_if_current_value( 'mobo_core_checkout_mobo_cart_validation_enabled', '1', '0' );
		self::update_option_if_current_value( 'mobo_core_checkout_mobo_debug_enabled', '1', '0' );
		self::update_option_if_current_value( 'mobo_core_checkout_validation_enabled', '0', '0' );
	}

	/**
	 * Update an option only if it is absent or still equals a known old default.
	 *
	 * @param string $key Option key.
	 * @param mixed  $old_value Old default value.
	 * @param mixed  $new_value New default value.
	 * @return void
	 */
	private static function update_option_if_current_value( $key, $old_value, $new_value ) {
		$current = get_option( $key, false );

		if ( false === $current || (string) $current === (string) $old_value ) {
			update_option( $key, $new_value, false );
		}
	}

	/**
	 * Ensure each install has a private real-cron/self-runner token.
	 *
	 * @return void
	 */
	private static function ensure_cron_token() {
		$token = (string) get_option( 'mobo_core_cron_token', '' );

		if ( '' !== trim( $token ) ) {
			return;
		}

		update_option( 'mobo_core_cron_token', wp_generate_password( 48, false, false ), false );
	}

	/**
	 * Ensure webhook directories exist and are protected.
	 *
	 * @return void
	 */
	private static function ensure_webhook_dirs() {
		self::protect_dir( MOBO_CORE_DATA_DIR );
		self::protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		self::protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
	}



	/**
	 * Remove generated city JS from the private data tree used before 10.31.56.
	 *
	 * The parent directory intentionally remains denied from the web because it
	 * also stores webhook fallback data. Public city assets are regenerated in
	 * uploads/mobo-core-public/assets by Mobo_Core_City_Assets.
	 *
	 * @return void
	 */
	private static function cleanup_legacy_private_city_assets() {
		$legacy_dir = trailingslashit( MOBO_CORE_DATA_DIR ) . 'assets/';
		foreach ( array( 'iran_cities.js', 'iran_cities.min.js' ) as $filename ) {
			$path = $legacy_dir . $filename;
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}


	/**
	 * Remove runtime state from the retired Persian WooCommerce option-enforcement feature.
	 *
	 * Mobo Core now shows guidance only and does not read, write, block, or
	 * periodically inspect Persian WooCommerce option values.
	 *
	 * @return void
	 */
	private static function cleanup_deprecated_pw_option_enforcement_state() {
		delete_option( 'mobo_core_pw_options_last_check_at' );
		delete_option( 'mobo_core_pw_options_last_enforced' );
		delete_transient( 'mobo_core_pw_options_enforced_notice' );
	}

	/**
	 * Create/update custom sync tables.
	 *
	 * @return void
	 */
	private static function create_database_tables() {
		if ( class_exists( 'Mobo_Core_Product_Map' ) ) {
			Mobo_Core_Product_Map::create_table();
		}

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) ) {
			Mobo_Core_Sync_Event_Store::create_table();
		}

		if ( class_exists( 'Mobo_Core_Category_Map' ) ) {
			Mobo_Core_Category_Map::create_table();
		}

		if ( class_exists( 'Mobo_Core_Image_Queue' ) ) {
			Mobo_Core_Image_Queue::create_table();
		}

		if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ) {
			Mobo_Core_Image_Refresh_Queue::create_table();
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			Mobo_Core_Orphan_Image_Cleanup::create_table();
		}

		if ( class_exists( 'Mobo_Core_Reconciliation' ) ) {
			Mobo_Core_Reconciliation::create_table();
		}

		update_option( 'mobo_core_schema_version', MOBO_CORE_VERSION, false );
	}


	/**
	 * Recover stale runtime locks when installing/upgrading to 10.31.71.
	 *
	 * Legacy transient locks could become permanent when their value option
	 * existed without the matching timeout option. The new lock helper stores
	 * token and expiry atomically, and this migration removes every old/current
	 * Mobo runtime lock before normal workers resume.
	 *
	 * Category placeholder repair is deferred until `init`, where WooCommerce has
	 * registered the product_cat taxonomy.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103171_runtime_lock_recovery( $previous_version ) {
		$installed_version = trim( (string) $previous_version );

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.71', '>=' ) ) {
			return;
		}

		$lock_result = array(
			'deleted' => 0,
			'found'   => 0,
		);

		if ( class_exists( 'Mobo_Core_Lock' ) && method_exists( 'Mobo_Core_Lock', 'force_release_all' ) ) {
			$lock_result = Mobo_Core_Lock::force_release_all();
		}

		update_option( 'mobo_core_103171_lock_cleanup_result', $lock_result, false );
		update_option( 'mobo_core_103171_lock_cleanup_at', time(), false );
		update_option( 'mobo_core_category_placeholder_repair_pending', '1', false );
	}



	/**
	 * Replace the legacy immediate archive-purge boolean with a deferred interval.
	 *
	 * Legacy OFF remains disabled. Legacy ON migrates to the shortest supported
	 * window (15 minutes), preserving freshness without reintroducing per-save
	 * archive purges. New installs keep the normal default from Settings.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10333_archive_purge_interval_migration( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.3', '>=' ) ) {
			return;
		}

		$legacy = get_option( 'mobo_core_cache_purge_archives_on_product_update', false );
		if ( false !== $legacy ) {
			$interval = in_array( strtolower( trim( (string) $legacy ) ), array( '1', 'yes', 'true', 'on' ), true ) ? 15 : 0;
			update_option( 'mobo_core_cache_archive_purge_interval_minutes', (string) $interval, false );
			delete_option( 'mobo_core_cache_purge_archives_on_product_update' );
		}
	}

	/**
	 * Force one safe legacy-image discovery pass after 10.33.8 introduced
	 * catalog-based recovery for old JPEG/PNG attachments without Mobo markers.
	 *
	 * Existing refresh/image queue rows are preserved. Only cached scan and
	 * verification state is invalidated so an in-progress or previously paused
	 * automation cannot skip the new discovery logic. Destructive switches are
	 * turned off and still require the normal explicit approval gate.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10338_legacy_image_catalog_rescan( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.8', '>=' ) ) {
			return;
		}

		$options_to_delete = array(
			'mobo_core_image_refresh_scan_cursor',
			'mobo_core_image_refresh_missing_scan_cursor',
			'mobo_core_image_refresh_enqueue_cursor',
			'mobo_core_image_refresh_missing_enqueue_cursor',
			'mobo_core_image_subsize_scan_cursor',
			'mobo_core_image_subsize_repair_cursor',
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_scan',
			'mobo_core_image_refresh_last_enqueue',
			'mobo_core_image_refresh_last_result',
			'mobo_core_image_refresh_last_subsize_scan',
			'mobo_core_image_refresh_last_subsize_repair',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
			'mobo_core_image_refresh_auto_delete_orphan_approved',
			'mobo_core_orphan_image_scan_cursor',
			'mobo_core_orphan_image_cleanup_last_scan',
			'mobo_core_orphan_image_cleanup_last_delete',
		);

		foreach ( $options_to_delete as $option_name ) {
			delete_option( $option_name );
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( true );
		}

		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_catalog_rescan_migrated_at', time(), false );
	}


	/**
	 * Re-open only the replaced-attachment cleanup stages after 10.33.9 added
	 * global old-attachment reference migration before safe deletion.
	 *
	 * Existing image refresh queue rows, downloaded WebP files and completed
	 * product replacements are preserved. Destructive approval is revoked so the
	 * administrator must explicitly review the new migration-aware cleanup step.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_10339_image_reference_migration( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.9', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
		) as $option_name ) {
			delete_option( $option_name );
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_image_reference_migration_enabled_at', time(), false );
	}


	/**
	 * Re-open replaced-attachment cleanup after 10.33.10 introduced structured
	 * JSON and PHP-serialized reference migration.
	 *
	 * Existing queue rows, downloaded WebP files and completed replacements are
	 * preserved. Only Stages 6/7 and destructive approval are reset so retained
	 * legacy images are retried with the safer structured migrator.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103310_structured_image_reference_migration( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.10', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
		) as $option_name ) {
			delete_option( $option_name );
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_structured_image_reference_migration_enabled_at', time(), false );
	}


	/**
	 * Re-open Stages 6/7 after 10.33.11 made replaced-attachment scanning
	 * timeout-safe and moved the expensive global reference audit to Stage 7.
	 *
	 * 10.33.10 advanced the Stage 6/7 cursor when a batch was fetched, before all
	 * rows were processed. If PHP was interrupted, part of that batch could be
	 * skipped on the next request. Reset only these verification cursors/results;
	 * existing WebP files, queue rows and completed product replacements remain.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103311_replaced_attachment_scan_runtime_safety( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.11', '>=' ) ) {
			return;
		}

		foreach ( array(
			'mobo_core_image_replaced_scan_cursor',
			'mobo_core_image_replaced_delete_cursor',
			'mobo_core_image_refresh_last_replaced_scan',
			'mobo_core_image_refresh_last_replaced_delete',
			'mobo_core_image_refresh_automation_last_result',
			'mobo_core_image_refresh_automation_completed_at',
			'mobo_core_image_refresh_auto_delete_old_approved',
		) as $option_name ) {
			delete_option( $option_name );
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_replaced_attachment_scan_runtime_safety_at', time(), false );
	}


	/**
	 * Make replaced-old-attachment deletion a persistent administrator preference.
	 *
	 * 10.33.12 and earlier used a separate one-time approval option and could
	 * automatically switch mobo_core_image_refresh_delete_old back to 0 while
	 * starting, pausing, invalidating verification state or completing a cycle.
	 * From 10.33.13 the actual setting is authoritative and is never changed by
	 * workflow state transitions. This migration only removes stale approval state;
	 * it deliberately preserves the administrator's current delete-old setting.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103313_persistent_delete_old_preference( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.13', '>=' ) ) {
			return;
		}

		delete_option( 'mobo_core_image_refresh_auto_delete_old_approved' );

		$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
		if ( is_array( $last ) && isset( $last['waitingApproval'] ) && 'delete-old' === sanitize_key( (string) $last['waitingApproval'] ) ) {
			unset( $last['waitingApproval'] );
			$delete_enabled = Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' );
			$last['status'] = $delete_enabled ? 'delete-old-setting-enabled' : 'waiting-delete-old-setting';
			$last['needsContinuation'] = $delete_enabled;
			$last['message'] = $delete_enabled
				? 'تنظیم دائمی حذف پیوست قدیمی فعال است و مرحله ۷ بدون تایید مجدد ادامه پیدا می کند.'
				: 'برای مرحله ۷، گزینه حذف پیوست قدیمی بعد از جایگزینی امن را یک بار فعال کنید؛ این انتخاب در ادامه حفظ می شود.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
		}

		update_option( 'mobo_core_persistent_delete_old_preference_migrated_at', time(), false );
	}


	/**
	 * 10.33.15: Stage 7 is non-blocking per attachment.
	 *
	 * 10.33.14 could disable the whole automation when one old attachment was
	 * retained by the safety audit. If an installation was paused specifically by
	 * that status, resume the existing Stage 7 cursor after upgrade. User-initiated
	 * pauses and unrelated errors are intentionally left untouched.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103315_nonblocking_stage7( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.15', '>=' ) ) {
			return;
		}

		$delete_state = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		if ( is_array( $delete_state ) && 'delete' === ( isset( $delete_state['mode'] ) ? (string) $delete_state['mode'] : '' ) ) {
			$legacy_failed = absint( isset( $delete_state['failed'] ) ? $delete_state['failed'] : 0 );
			if ( $legacy_failed > 0 && ! isset( $delete_state['blocked'] ) ) {
				/* 10.33.14 did not distinguish safety blocks from operational errors.
				 * Preserve them conservatively as blocked legacy items for reporting. */
				$delete_state['blocked'] = $legacy_failed;
				$delete_state['errors']  = 0;
				update_option( 'mobo_core_image_refresh_last_replaced_delete', $delete_state, false );
			}
		}

		$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
		if ( is_array( $last ) && 'delete-old-failed' === sanitize_key( isset( $last['status'] ) ? (string) $last['status'] : '' ) ) {
			update_option( 'mobo_core_image_refresh_automation_enabled', '1', false );
			$last['success']           = true;
			$last['status']            = 'stage7-resumed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = false;
			$last['message']           = 'مرحله ۷ پس از ارتقا با منطق غیرمسدودکننده ادامه پیدا می کند؛ پیوست های دارای Safety Block ثبت و رد می شوند و کل چرخه را متوقف نمی کنند.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
		}

		update_option( 'mobo_core_stage7_nonblocking_migrated_at', time(), false );
	}


	/**
	 * 10.33.16: make Stage 7 a fully automatic convergence loop.
	 *
	 * 10.33.15 treated one complete delete pass as authoritative. A site could
	 * therefore report the full image-refresh cycle as completed even though a
	 * later manual Stage 7 pass was still able to migrate references and delete
	 * more legacy attachments. The new workflow repeats full Stage 7 passes while
	 * each pass makes progress and stops only after a zero-progress safety pass.
	 *
	 * Existing Stage 1-6 state, WebP replacements and the Stage 7 cursor are
	 * preserved. If an installation was incorrectly marked completed while Stage 7
	 * still has work, automation is re-enabled directly at Stage 7.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103316_automatic_stage7_convergence( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.16', '>=' ) ) {
			return;
		}

		$scan = get_option( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$scan = is_array( $scan ) ? $scan : array();
		$delete = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_progress = absint( isset( $delete['passProgress'] )
			? $delete['passProgress']
			: absint( isset( $delete['deleted'] ) ? $delete['deleted'] : 0 )
				+ absint( isset( $delete['referenceRowsUpdated'] ) ? $delete['referenceRowsUpdated'] : 0 ) );

		if ( ! empty( $delete['cycleComplete'] ) ) {
			$delete['passProgress']     = $delete_progress;
			$delete['needsAnotherPass'] = $delete_progress > 0;
			$delete['stableComplete']   = 0 === $delete_progress;
			update_option( 'mobo_core_image_refresh_last_replaced_delete', $delete, false );
		}

		$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
		$last = is_array( $last ) ? $last : array();
		$last_status = sanitize_key( isset( $last['status'] ) ? (string) $last['status'] : '' );
		$delete_in_progress = ! empty( $delete ) && empty( $delete['cycleComplete'] );
		$needs_followup = ! empty( $delete['cycleComplete'] ) && $delete_progress > 0;
		$was_false_complete = 'completed' === $last_status && $actionable > 0;

		if ( $actionable > 0
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& ( $delete_in_progress || $needs_followup || $was_false_complete ) ) {
			update_option( 'mobo_core_image_refresh_automation_enabled', '1', false );
			update_option( 'mobo_core_image_refresh_automation_completed_at', 0, false );
			$last['success']           = true;
			$last['status']            = 'stage7-auto-resumed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = true;
			$last['message']           = 'Stage 7 با منطق چندگذری خودکار دوباره فعال شد. Cursor فعلی حفظ شده و Cron/Self Runner تا رسیدن به یک گذر کامل بدون پیشرفت جدید، انتقال مراجع و حذف امن را ادامه می دهد.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
			/*
			 * maybe_run() executes on plugins_loaded, before rest_url() is safe on
			 * all WordPress installations. Queue a one-shot kick and dispatch it
			 * from run_deferred_repairs() after init instead of performing HTTP
			 * during the migration transaction/bootstrap.
			 */
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );
		}

		update_option( 'mobo_core_stage7_automatic_convergence_migrated_at', time(), false );
	}


	/**
	 * 10.33.16.1: installation/bootstrap safety hotfix.
	 *
	 * - Re-arms the deferred Stage 7 self-runner kick for installations that
	 *   upgraded from 10.33.16 or were interrupted during the previous migration.
	 * - The sync-event table schema itself is recreated/updated by dbDelta before
	 *   this method, using an index prefix compatible with older 1000-byte limits.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033161_install_bootstrap_safety( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' === $previous_version || version_compare( $previous_version, '10.33.16.1', '>=' ) ) {
			return;
		}

		$scan = get_option( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$scan = is_array( $scan ) ? $scan : array();
		$delete = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_in_progress = ! empty( $delete ) && empty( $delete['cycleComplete'] );
		$needs_followup = ! empty( $delete['needsAnotherPass'] );

		if ( $actionable > 0
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			&& ( $delete_in_progress || $needs_followup || '1' === (string) get_option( 'mobo_core_image_refresh_automation_enabled', '0' ) ) ) {
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );
		}

		update_option( 'mobo_core_1033161_install_bootstrap_safety_at', time(), false );
	}


	/**
	 * 10.33.17.1: automatically arm a safe manual Stage 7 after upgrade.
	 *
	 * Older versions only re-armed Stage 7 when a delete pass had already started.
	 * A store that completed Stage 6 manually, enabled the persistent delete-old
	 * preference and had not yet completed the first delete pass could therefore
	 * still require one administrator click per batch. Preserve all cursors and
	 * simply hand that already-verified Stage 7 to the existing automation runner.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_1033171_stage7_autodrain( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.33.17.1', '>=' ) ) {
			return;
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ) ) {
			update_option( 'mobo_core_1033171_stage7_autodrain_migrated_at', time(), false );
			return;
		}

		$scan   = get_option( 'mobo_core_image_refresh_last_replaced_scan', array() );
		$delete = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		$scan   = is_array( $scan ) ? $scan : array();
		$delete = is_array( $delete ) ? $delete : array();

		$actionable = ! empty( $scan['cycleComplete'] )
			? absint( isset( $scan['ready'] ) ? $scan['ready'] : 0 ) + absint( isset( $scan['migrationCandidates'] ) ? $scan['migrationCandidates'] : 0 )
			: 0;
		$delete_newer = ! empty( $delete['cycleComplete'] )
			&& absint( isset( $delete['checkedAt'] ) ? $delete['checkedAt'] : 0 ) >= absint( isset( $scan['checkedAt'] ) ? $scan['checkedAt'] : 0 );
		$delete_progress = absint( isset( $delete['passProgress'] )
			? $delete['passProgress']
			: absint( isset( $delete['deleted'] ) ? $delete['deleted'] : 0 )
				+ absint( isset( $delete['referenceRowsUpdated'] ) ? $delete['referenceRowsUpdated'] : 0 ) );
		$stable = $delete_newer && 0 === $delete_progress;

		if ( $actionable > 0 && ! $stable ) {
			update_option( 'mobo_core_image_refresh_automation_enabled', '1', false );
			update_option( 'mobo_core_image_refresh_automation_completed_at', 0, false );
			update_option( 'mobo_core_stage7_resume_kick_pending', '1', false );

			$last = get_option( 'mobo_core_image_refresh_automation_last_result', array() );
			$last = is_array( $last ) ? $last : array();
			$last['success']           = true;
			$last['status']            = 'stage7-autodrain-armed-after-upgrade';
			$last['step']              = 7;
			$last['needsContinuation'] = true;
			$last['progressed']        = false;
			$last['message']           = 'Stage 7 پس از ارتقا برای ادامه خودکار فعال شد. Cursor و Safety Audit فعلی بدون ریست حفظ شده اند.';
			update_option( 'mobo_core_image_refresh_automation_last_result', $last, false );
		}

		update_option( 'mobo_core_1033171_stage7_autodrain_migrated_at', time(), false );
	}

	/**
	 * Run repairs that require taxonomies registered on WordPress init.
	 *
	 * @return void
	 */
	public static function run_deferred_repairs() {
		/*
		 * Stage 7 resume kicks are intentionally dispatched only after init.
		 * This avoids rest_url()/rewrite access during plugins_loaded migrations.
		 */
		if ( '1' === (string) get_option( 'mobo_core_stage7_resume_kick_pending', '0' )
			&& class_exists( 'Mobo_Core_Self_Runner' )
			&& method_exists( 'Mobo_Core_Self_Runner', 'kick' ) ) {
			delete_option( 'mobo_core_stage7_resume_kick_pending' );
			$kick = Mobo_Core_Self_Runner::kick( 'stage7-auto-resume-upgrade', true );
			update_option( 'mobo_core_stage7_resume_deferred_kick_result', $kick, false );
			update_option( 'mobo_core_stage7_resume_deferred_kick_at', time(), false );
		}

		if ( '1' === (string) get_option( 'mobo_core_category_placeholder_repair_pending', '0' )
			&& taxonomy_exists( 'product_cat' ) ) {
			$result = self::repair_placeholder_category_titles_from_map();

			update_option( 'mobo_core_category_placeholder_repair_result', $result, false );
			update_option( 'mobo_core_category_placeholder_repair_at', time(), false );
			delete_option( 'mobo_core_category_placeholder_repair_pending' );
		}

		if ( '1' !== (string) get_option( 'mobo_core_image_queue_recovery_pending', '0' ) ) {
			return;
		}

		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return;
		}

		$failed_recovery = method_exists( 'Mobo_Core_Image_Queue', 'recover_legacy_failed' )
			? Mobo_Core_Image_Queue::recover_legacy_failed( 500 )
			: array( 'status' => 'unavailable', 'recovered' => 0, 'remaining' => 0 );
		$linkage_recovery = method_exists( 'Mobo_Core_Image_Queue', 'schedule_linkage_repairs' )
			? Mobo_Core_Image_Queue::schedule_linkage_repairs( 200 )
			: array( 'status' => 'unavailable', 'scheduled' => 0 );

		$result = array(
			'failedRows' => $failed_recovery,
			'linkage'    => $linkage_recovery,
			'executedAt' => time(),
		);

		update_option( 'mobo_core_103199_image_queue_recovery_result', $result, false );
		update_option( 'mobo_core_103199_image_queue_recovery_at', time(), false );

		$scheduled_work = ( isset( $failed_recovery['recovered'] ) ? absint( $failed_recovery['recovered'] ) : 0 )
			+ ( isset( $linkage_recovery['scheduled'] ) ? absint( $linkage_recovery['scheduled'] ) : 0 );

		if ( $scheduled_work > 0 && class_exists( 'Mobo_Core_Self_Runner' ) && method_exists( 'Mobo_Core_Self_Runner', 'kick' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-queue-recovery', true );
		}

		if ( empty( $failed_recovery['remaining'] ) ) {
			delete_option( 'mobo_core_image_queue_recovery_pending' );
		}
	}



	/**
	 * Repair old `Mobo Category <GUID>` names when the mapping table already has
	 * a real remote category name.
	 *
	 * Normal customer-edited category names are never changed.
	 *
	 * @return array Repair summary.
	 */
	private static function repair_placeholder_category_titles_from_map() {
		global $wpdb;

		$result = array(
			'checked'  => 0,
			'repaired' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		if ( ! class_exists( 'Mobo_Core_Category_Map' ) || ! Mobo_Core_Category_Map::table_exists() ) {
			return $result;
		}

		$table = Mobo_Core_Category_Map::table_name();
		$rows  = $wpdb->get_results(
			"SELECT remote_guid, synced_term_id, remote_name
			FROM {$table}
			WHERE synced_term_id > 0 AND remote_name <> ''
			ORDER BY id ASC
			LIMIT 1000",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$result['checked']++;

			$guid        = trim( sanitize_text_field( isset( $row['remote_guid'] ) ? (string) $row['remote_guid'] : '' ) );
			$term_id     = absint( isset( $row['synced_term_id'] ) ? $row['synced_term_id'] : 0 );
			$remote_name = trim( sanitize_text_field( isset( $row['remote_name'] ) ? (string) $row['remote_name'] : '' ) );

			if (
				'' === $guid
				|| $term_id <= 0
				|| '' === $remote_name
				|| 0 === strcasecmp( $remote_name, $guid )
				|| 0 === strcasecmp( $remote_name, 'Mobo Category ' . $guid )
				|| 1 === preg_match( '/^Mobo Category\s+[0-9a-f-]{16,}$/i', $remote_name )
			) {
				$result['skipped']++;
				continue;
			}

			$term = get_term( $term_id, 'product_cat' );

			if ( ! $term instanceof WP_Term || 0 !== strcasecmp( trim( (string) $term->name ), 'Mobo Category ' . $guid ) ) {
				$result['skipped']++;
				continue;
			}

			$updated = wp_update_term(
				$term_id,
				'product_cat',
				array( 'name' => $remote_name )
			);

			if ( is_wp_error( $updated ) ) {
				$result['failed']++;
				continue;
			}

			$result['repaired']++;
		}

		return $result;
	}


	/**
	 * Convert orphan-image cleanup from noisy per-file rows to one row per image
	 * family and reset bounded scan cursors introduced in 10.31.64.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103164_image_family_migration( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' === $installed_version ) {
			$installed_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		}

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.64', '>=' ) ) {
			return;
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) && method_exists( 'Mobo_Core_Orphan_Image_Cleanup', 'migrate_to_family_rows' ) ) {
			Mobo_Core_Orphan_Image_Cleanup::migrate_to_family_rows();
		}

		delete_option( 'mobo_core_image_refresh_scan_cursor' );
		delete_option( 'mobo_core_image_refresh_enqueue_cursor' );
		delete_option( 'mobo_core_image_refresh_last_scan' );
		delete_option( 'mobo_core_image_refresh_last_enqueue' );
	}


	/**
	 * Apply the conservative image-maintenance defaults introduced in 10.31.65.
	 *
	 * Old releases defaulted destructive image cleanup options to enabled. On the
	 * first 10.31.65 run they are switched off so the administrator must complete
	 * the new health scan and explicitly opt in again. Non-destructive refresh
	 * processing may continue, but old attachments and orphan families are kept.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103165_image_refresh_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' === $installed_version ) {
			$installed_version = trim( (string) get_option( 'mobo_core_db_version', '' ) );
		}

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.65', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		delete_option( 'mobo_core_image_subsize_scan_cursor' );
		delete_option( 'mobo_core_image_subsize_repair_cursor' );
		delete_option( 'mobo_core_image_replaced_scan_cursor' );
		delete_option( 'mobo_core_image_replaced_delete_cursor' );
		delete_option( 'mobo_core_image_refresh_last_subsize_scan' );
		delete_option( 'mobo_core_image_refresh_last_subsize_repair' );
		delete_option( 'mobo_core_image_refresh_last_replaced_scan' );
		delete_option( 'mobo_core_image_refresh_last_replaced_delete' );
	}


	/**
	 * Enforce non-editable Mobo endpoints and initialize legacy health options.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103166_admin_health_defaults( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.66', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_health_report_enabled', '1', false );
		delete_option( 'mobo_core_health_report_url' );
		update_option( 'mobo_core_checkout_mobo_site_url', defined( 'MOBO_CORE_CHECKOUT_SITE_URL' ) ? MOBO_CORE_CHECKOUT_SITE_URL : 'https://mobomobo.ir', false );
	}



	/**
	 * Re-open recoverable image failures and repair the old import/linkage race.
	 *
	 * The work is deferred and bounded so plugin activation/upgrade does not run a
	 * product Sync or Repair. Existing queue rows are reused.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103199_image_queue_recovery( $previous_version ) {
		$installed_version = trim( (string) $previous_version );

		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.99', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_image_queue_recovery_pending', '1', false );
	}

	/**
	 * Prevent legacy migration flags from starting a heavy Repair automatically.
	 * Existing active operations are not cancelled.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103198_manual_initial_sync_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.98', '>=' ) ) {
			return;
		}

		delete_option( 'mobo_core_desired_state_repair_queue_pending' );
	}

	/**
	 * Disable the retired outbound health-report path.
	 *
	 * Portal 51+ pulls the authenticated /health endpoint. Existing installs may
	 * still have the legacy option enabled from older releases, so normalize it
	 * once when upgrading to 10.31.94.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103194_health_pull_only( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.94', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_health_report_enabled', '0', false );
		delete_option( 'mobo_core_health_report_url' );
		delete_option( 'mobo_core_health_last_report_attempt_at' );
		delete_option( 'mobo_core_health_last_report_success_at' );
		delete_option( 'mobo_core_health_last_report_result' );
	}

	/**
	 * Start the strict image workflow with destructive switches disabled.
	 *
	 * Existing scan and queue progress is kept. Refresh execution and both
	 * destructive opt-ins are turned off so the new state machine can unlock each
	 * one at the correct stage.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103167_image_workflow_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.67', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
	}


	/**
	 * Introduce image-refresh automation with every destructive approval off.
	 *
	 * Existing scan/queue progress is preserved. The coordinator starts only after
	 * an administrator explicitly presses the safe automation button.
	 *
	 * @param string $previous_version Previously stored plugin DB version.
	 * @return void
	 */
	private static function apply_103168_image_automation_safety( $previous_version ) {
		$installed_version = trim( (string) $previous_version );
		if ( '' !== $installed_version && version_compare( $installed_version, '10.31.68', '>=' ) ) {
			return;
		}

		update_option( 'mobo_core_image_refresh_automation_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_auto_delete_old_approved', '0', false );
		update_option( 'mobo_core_image_refresh_auto_delete_orphan_approved', '0', false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
	}

	/**
	 * Mark one authoritative manual Repair as required after installing the
	 * desired-state variation engine. The migration never starts that heavy job.
	 *
	 * @param string $previous_version Previously stored DB version.
	 * @return void
	 */
	private static function apply_103177_desired_state_repair( $previous_version ) {
		$previous_version = trim( (string) $previous_version );
		if ( '' !== $previous_version && version_compare( $previous_version, '10.31.77', '>=' ) ) {
			return;
		}

		if ( ! self::has_legacy_mobo_content() ) {
			return;
		}

		delete_option( 'mobo_core_repair_completed_at' );
		delete_option( 'mobo_core_repair_last_sync_id' );
		update_option( 'mobo_core_legacy_repair_required', '1', false );
		update_option( 'mobo_core_desired_state_repair_required', '1', false );
		delete_option( 'mobo_core_desired_state_repair_queue_pending' );
		update_option( 'mobo_core_desired_state_repair_marked_at', time(), false );
	}

	/**
	 * Mark legacy installs as requiring one manual Repair pass.
	 *
	 * Version 7 installs can have products/images created before the new map,
	 * image queue and hash-bypass repair flow existed. We do not run Repair during
	 * upgrade because it can be heavy; we only persist a clear admin requirement.
	 *
	 * @param string $previous_version Previously stored DB version.
	 * @return void
	 */
	private static function maybe_mark_legacy_repair_required( $previous_version ) {
		$previous_version = trim( (string) $previous_version );

		if ( class_exists( 'Mobo_Core_Product_Sync' ) && Mobo_Core_Product_Sync::is_repair_completed() ) {
			update_option( 'mobo_core_legacy_repair_required', '0', false );
			return;
		}

		$looks_legacy = false;
		if ( '' !== $previous_version ) {
			$looks_legacy = version_compare( $previous_version, '10.0.0', '<' );
		} else {
			$looks_legacy = self::has_legacy_mobo_content();
		}

		if ( $looks_legacy ) {
			update_option( 'mobo_core_legacy_repair_required', '1', false );
		}
	}

	/**
	 * Detect legacy Mobo products/attachments on installs that do not have a stored DB version.
	 *
	 * @return bool
	 */
	private static function has_legacy_mobo_content() {
		global $wpdb;

		$product_meta_count = absint(
			$wpdb->get_var(
				"SELECT COUNT(1)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type IN ('product','product_variation')
				AND p.post_status NOT IN ('trash','auto-draft')
				AND pm.meta_key IN ('product_guid','variant_guid','portal_product_id','mobo_portal_product_id','_mobo_portal_product_id','PortalProductId','mobo_url')
				AND pm.meta_value <> ''
				LIMIT 1"
			)
		);

		if ( $product_meta_count > 0 ) {
			return true;
		}

		$attachment_meta_count = absint(
			$wpdb->get_var(
				"SELECT COUNT(1)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'attachment'
				AND pm.meta_key IN ('image_guid','img_guid','mobo_source_url')
				AND pm.meta_value <> ''
				LIMIT 1"
			)
		);

		return $attachment_meta_count > 0;
	}


	/**
	 * Seed product/variation map from old post meta without blocking upgrades.
	 *
	 * This is bounded and repeatable. If a large site is not fully seeded during
	 * upgrade, normal product sync lookup still falls back to legacy meta_query and
	 * repairs missing map rows lazily.
	 *
	 * @return void
	 */
	private static function seed_product_map_from_legacy_meta() {
		if ( ! class_exists( 'Mobo_Core_Product_Map' ) ) {
			return;
		}

		$map    = new Mobo_Core_Product_Map();
		$result = $map->seed_from_legacy_meta( 500 );

		update_option( 'mobo_core_product_map_last_seed_result', $result, false );
		update_option( 'mobo_core_product_map_last_seed_at', time(), false );
	}


	/**
	 * Seed category map from legacy product_cat term meta without blocking upgrades.
	 *
	 * @return void
	 */
	private static function seed_category_map_from_legacy_meta() {
		if ( ! class_exists( 'Mobo_Core_Category_Map' ) ) {
			return;
		}

		$map    = new Mobo_Core_Category_Map();
		$result = $map->seed_from_legacy_term_meta( 500 );

		update_option( 'mobo_core_category_map_last_seed_result', $result, false );
		update_option( 'mobo_core_category_map_last_seed_at', time(), false );
	}

	/**
	 * Discard old file-based webhook queue from the plugin directory.
	 *
	 * Previous versions used:
	 * wp-content/plugins/mobo-core/webhook-files/
	 *
	 * Current versions use uploads for the active queue. The legacy queued JSON
	 * files are intentionally not migrated because stale webhook payloads from
	 * 7.4 are not required and can replay outdated product/category changes.
	 *
	 * Only JSON payload files inside the legacy plugin-directory queue are removed.
	 * Protection files such as index.php, .htaccess and .gitignore are left intact.
	 * The current uploads-based queue is never touched here.
	 *
	 * @return void
	 */
	private static function discard_legacy_webhook_queue() {
		if ( ! defined( 'MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR' ) ) {
			return;
		}

		$legacy_dir = trailingslashit( MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR );
		$new_dir    = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR );

		if ( $legacy_dir === $new_dir || ! is_dir( $legacy_dir ) ) {
			return;
		}

		$deleted = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$legacy_dir,
				RecursiveDirectoryIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );

			if ( 'json' !== $extension ) {
				continue;
			}

			$file_path = $file->getPathname();
			wp_delete_file( $file_path );

			if ( ! file_exists( $file_path ) ) {
				$deleted++;
			}
		}

		update_option( 'mobo_core_legacy_webhook_queue_discarded_at', time(), false );
		update_option( 'mobo_core_legacy_webhook_queue_discarded_count', $deleted, false );
	}

	/**
	 * Protect directory with index.php and .htaccess.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Clear old WP-Cron hooks from previous plugin versions.
	 *
	 * Final architecture does not rely on WP-Cron.
	 *
	 * @return void
	 */
	private static function clear_legacy_cron_hooks() {
		if ( class_exists( 'Mobo_Core_Maintenance' ) ) {
			$hooks = Mobo_Core_Maintenance::mobo_cron_hooks();
		} else {
			$hooks = array(
				'mobo_core_cron',
				'mobo_core_sync_cron',
				'mobo_core_product_sync_cron',
				'mobo_core_products_sync_cron',
				'mobo_core_webhook_cron',
				'mobo_core_webhook_queue_cron',
				'mobo_core_process_webhook_queue',
				'mobo_core_run_webhooks',
				'mobo_core_update_products',
				'mobo_core_update_variants',
				'mobo_core_process_queued_mobo_orders',
				'mobo_core_queue_mobo_order_submission',
				'mobo_core_read_webhook_interval',
				'mobo_core_sync_products_24_event',
				'mobo_core_sync_products_event',
				'mobo_core_sync_categories_event',
				'mobo_cron_hook',
				'mobo_sync_cron_hook',
				'mobo_webhook_cron_hook',
			);
		}

		/**
		 * Allow old/custom installs to register extra legacy cron hooks for cleanup.
		 *
		 * @param array $hooks Cron hook names.
		 */
		$hooks = apply_filters( 'mobo_core_legacy_cron_hooks', $hooks );

		foreach ( $hooks as $hook ) {
			$hook = sanitize_key( (string) $hook );

			if ( '' === $hook ) {
				continue;
			}

			wp_clear_scheduled_hook( $hook );
		}
	}
}
