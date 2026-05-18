<?php
/**
 * Plugin Name: Mobo Core
 * Description: Production-ready chunked WooCommerce product, category, image, variation and webhook sync for Mobo.
 * Version: 2.0.0
 * Author: Mobo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: mobo-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOBO_CORE_VERSION', '2.0.0' );
define( 'MOBO_CORE_PLUGIN_FILE', __FILE__ );
define( 'MOBO_CORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOBO_CORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOBO_CORE_WEBHOOK_FILE_DIR', MOBO_CORE_PLUGIN_DIR . 'webhook-files/' );

require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-settings.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-legacy-rules.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-price-calculator.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-lock.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-api-client.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-image-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-category-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-product-sync.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-webhook-queue.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-rest-controller.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-admin.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-variation-fields.php';
require_once MOBO_CORE_PLUGIN_DIR . 'includes/class-mobo-core-migration.php';

register_activation_hook( __FILE__, array( 'Mobo_Core_Migration', 'activate' ) );

if ( ! defined( 'MOBO_API_BASE_URL' ) ) {
	define( 'MOBO_API_BASE_URL', '' );
}

add_filter(
	'mobo_core_api_base_url',
	function () {
		return MOBO_API_BASE_URL;
	}
);

add_action(
	'plugins_loaded',
	function () {
		Mobo_Core_Migration::maybe_run();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}

					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Mobo Core requires WooCommerce to be installed and active.', 'mobo-core' );
					echo '</p></div>';
				}
			);

			return;
		}

		$variation_fields = new Mobo_Core_Variation_Fields();
		$variation_fields->init();

		$rest = new Mobo_Core_Rest_Controller();
		$rest->init();

		if ( is_admin() ) {
			$admin = new Mobo_Core_Admin();
			$admin->init();
		}
	}
);