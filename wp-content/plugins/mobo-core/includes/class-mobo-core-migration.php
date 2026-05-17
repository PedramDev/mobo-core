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
		 * Important:
		 * We do not rename or remove old meta keys.
		 * Existing production data remains compatible:
		 * product_guid, variant_guid, attribute_guid, category_guid, image_guid, img_guid.
		 */

		update_option( 'mobo_core_db_version', MOBO_CORE_VERSION, false );
	}

	private static function ensure_defaults() {
		foreach ( Mobo_Core_Settings::defaults() as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value, '', false );
			}
		}
	}

	private static function ensure_webhook_dirs() {
		self::protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		self::protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
	}

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
}