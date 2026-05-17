<?php
/**
 * Migration helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Migration {

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_defaults();
		self::ensure_webhook_dirs();
		self::delete_webhook_json_files();
		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	/**
	 * Maybe run migration.
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
		 * Do not delete webhook files on normal version migration.
		 * JSON cleanup is only on activation/install.
		 */

		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	/**
	 * Add missing defaults only.
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
	 * Ensure webhook dirs.
	 *
	 * @return void
	 */
	private static function ensure_webhook_dirs() {
		self::protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		self::protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
	}

	/**
	 * Protect directory.
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
	 * Runs on plugin activation/install only.
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
}