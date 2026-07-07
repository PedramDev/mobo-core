<?php
/**
 * Migration helper.
 *
 * Responsibilities:
 * - create missing defaults
 * - create/update local data directories
 * - move legacy webhook queue files from the plugin directory to uploads
 * - create/update local sync database tables
 * - seed product/variation map from legacy meta in bounded batches
 * - clear old WP-Cron hooks from previous versions
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Migration {

	/**
	 * Activation hook.
	 *
	 * Activation must be safe for existing customer installs. It must never delete
	 * pending webhook JSON files, because plugin updates/re-activations can happen
	 * while a queue is still being processed.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_defaults();
		self::apply_10307_default_adjustments( '' );
		self::ensure_cron_token();
		self::ensure_webhook_dirs();
		self::create_database_tables();
		self::seed_product_map_from_legacy_meta();
		self::seed_category_map_from_legacy_meta();
		self::migrate_legacy_webhook_queue();
		self::clear_legacy_cron_hooks();

		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	/**
	 * Run lightweight migrations if version changed.
	 *
	 * Important:
	 * This method never deletes queued webhook JSON files.
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
		self::create_database_tables();
		self::seed_product_map_from_legacy_meta();
		self::seed_category_map_from_legacy_meta();
		self::migrate_legacy_webhook_queue();
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

		update_option( 'mobo_core_schema_version', MOBO_CORE_VERSION, false );
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
	 * Migrate old file-based webhook queue from plugin directory to uploads.
	 *
	 * Previous versions used:
	 * wp-content/plugins/mobo-core/webhook-files/
	 *
	 * New versions use:
	 * wp-content/uploads/mobo-core/webhook-files/
	 *
	 * This migration is intentionally repeatable and lossless:
	 * - JSON files are moved when possible.
	 * - If rename() fails, copy + unlink is attempted.
	 * - Existing destination files are not overwritten.
	 * - Non-JSON protection files are left in place.
	 *
	 * @return void
	 */
	private static function migrate_legacy_webhook_queue() {
		if ( ! defined( 'MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR' ) ) {
			return;
		}

		$legacy_dir = trailingslashit( MOBO_CORE_LEGACY_WEBHOOK_FILE_DIR );
		$new_dir    = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR );

		if ( $legacy_dir === $new_dir || ! is_dir( $legacy_dir ) ) {
			return;
		}

		self::protect_dir( $new_dir );
		self::protect_dir( $new_dir . 'failed/' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$legacy_dir,
				RecursiveDirectoryIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$moved = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );

			if ( 'json' !== $extension ) {
				continue;
			}

			$source = $file->getPathname();
			$relative = ltrim( str_replace( $legacy_dir, '', $source ), '/\\' );
			$target = $new_dir . $relative;
			$target_dir = dirname( $target );

			self::protect_dir( $target_dir );

			if ( file_exists( $target ) ) {
				$target = self::unique_file_path( $target );
			}

			if ( @rename( $source, $target ) ) {
				$moved++;
				continue;
			}

			if ( @copy( $source, $target ) ) {
				@unlink( $source );
				$moved++;
			}
		}

		update_option( 'mobo_core_legacy_webhook_queue_migrated_at', time(), false );
		update_option( 'mobo_core_legacy_webhook_queue_migrated_count', $moved, false );
	}

	/**
	 * Build a unique file path without overwriting the original destination.
	 *
	 * @param string $path Desired path.
	 * @return string
	 */
	private static function unique_file_path( $path ) {
		$dir = dirname( $path );
		$name = pathinfo( $path, PATHINFO_FILENAME );
		$ext = pathinfo( $path, PATHINFO_EXTENSION );

		for ( $i = 1; $i < 1000; $i++ ) {
			$candidate = trailingslashit( $dir ) . $name . '-' . $i . ( '' !== $ext ? '.' . $ext : '' );

			if ( ! file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return trailingslashit( $dir ) . $name . '-' . wp_generate_password( 8, false, false ) . ( '' !== $ext ? '.' . $ext : '' );
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
