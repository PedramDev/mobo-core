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
		self::apply_10307_default_adjustments( '' );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		self::create_database_tables();
		self::apply_103164_image_family_migration( '' );
		self::apply_103165_image_refresh_safety( '' );
		self::apply_103166_admin_health_defaults( '' );
		self::apply_103167_image_workflow_safety( '' );
		self::apply_103168_image_automation_safety( '' );
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
		self::apply_10307_default_adjustments( $current );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::cleanup_legacy_private_city_assets();
		self::cleanup_deprecated_pw_option_enforcement_state();
		self::create_database_tables();
		self::apply_103164_image_family_migration( $current );
		self::apply_103165_image_refresh_safety( $current );
		self::apply_103166_admin_health_defaults( $current );
		self::apply_103167_image_workflow_safety( $current );
		self::apply_103168_image_automation_safety( $current );
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
		foreach ( Mobo_Core_Settings::defaults() as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value, '', false );
			}
		}
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

		update_option( 'mobo_core_schema_version', MOBO_CORE_VERSION, false );
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
	 * Enforce non-editable Mobo endpoints and always-on health reporting.
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
