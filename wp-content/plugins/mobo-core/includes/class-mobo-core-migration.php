<?php
/**
 * Migration helper.
 *
 * Responsibilities:
 * - create missing defaults
 * - ensure webhook directories exist and are protected
 * - delete old webhook JSON files only on activation/install
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
	 * Runs on plugin activation/install.
	 * This is the only place where webhook JSON files are deleted.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_defaults();
		self::ensure_webhook_dirs();
		self::delete_webhook_json_files();
		self::clear_legacy_cron_hooks();

		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	/**
	 * Run lightweight migrations if version changed.
	 *
	 * Important:
	 * This method does not delete queued webhook JSON files.
	 *
	 * @return void
	 */
	public static function maybe_run() {
		$current = get_option( 'mobo_core_db_version', '' );

		if ( MOBO_CORE_VERSION === $current ) {
			return;
		}

		self::ensure_defaults();
		self::ensure_webhook_dirs();

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
	 * Ensure webhook directories exist and are protected.
	 *
	 * @return void
	 */
	private static function ensure_webhook_dirs() {
		self::protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		self::protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
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
	 * Delete all JSON files inside webhook-files recursively.
	 *
	 * Runs only on activation/install.
	 *
	 * @return void
	 */
	private static function delete_webhook_json_files() {
		if ( ! is_dir( MOBO_CORE_WEBHOOK_FILE_DIR ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				MOBO_CORE_WEBHOOK_FILE_DIR,
				RecursiveDirectoryIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo ) {
				continue;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );

			if ( 'json' === $extension ) {
				@unlink( $file->getPathname() );
			}
		}
	}


	/**
	 * Clear old WP-Cron hooks from previous plugin versions.
	 *
	 * Final v2 architecture does not use WP-Cron.
	 *
	 * @return void
	 */
	private static function clear_legacy_cron_hooks() {
		$hooks = array(
			/*
			* Add all old cron hook names here.
			* These are likely names based on old files, but confirm with old cron.php / cron-functions.php.
			*/
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
			'mobo_cron_hook',
			'mobo_sync_cron_hook',
			'mobo_webhook_cron_hook',
		);

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