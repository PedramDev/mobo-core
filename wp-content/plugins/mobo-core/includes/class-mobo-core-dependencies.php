<?php
/**
 * Required plugin dependency checks for Mobo Core.
 *
 * WordPress 6.5+ reads the `Requires Plugins` header from the main plugin file.
 * This runtime guard keeps the same behavior on older WordPress versions and
 * when a required plugin is deactivated after Mobo Core was already enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Dependencies {

	const WOOCOMMERCE_PLUGIN_FILE         = 'woocommerce/woocommerce.php';
	const WOOCOMMERCE_SLUG                = 'woocommerce';
	const PERSIAN_WOOCOMMERCE_PLUGIN_FILE = 'persian-woocommerce/woocommerce-persian.php';
	const PERSIAN_WOOCOMMERCE_SLUG        = 'persian-woocommerce';

	/**
	 * Return missing required plugins.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_missing_dependencies() {
		$dependencies = array(
			array(
				'name'        => 'WooCommerce',
				'plugin_file' => self::WOOCOMMERCE_PLUGIN_FILE,
				'slug'        => self::WOOCOMMERCE_SLUG,
				'active'      => self::is_woocommerce_active(),
			),
			array(
				'name'        => 'ووکامرس فارسی',
				'plugin_file' => self::PERSIAN_WOOCOMMERCE_PLUGIN_FILE,
				'slug'        => self::PERSIAN_WOOCOMMERCE_SLUG,
				'active'      => self::is_persian_woocommerce_active(),
			),
		);

		$missing = array();
		foreach ( $dependencies as $dependency ) {
			if ( ! empty( $dependency['active'] ) ) {
				continue;
			}

			$dependency['installed'] = self::is_plugin_installed( $dependency['plugin_file'], $dependency['slug'] );
			$missing[]               = $dependency;
		}

		return $missing;
	}

	/**
	 * Whether all required dependencies are active.
	 *
	 * @return bool
	 */
	public static function requirements_met() {
		return empty( self::get_missing_dependencies() );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' )
			|| self::is_plugin_active_safe( self::WOOCOMMERCE_PLUGIN_FILE )
			|| self::is_plugin_slug_active( self::WOOCOMMERCE_SLUG );
	}

	/**
	 * Whether the official WordPress.org Persian WooCommerce plugin is active.
	 *
	 * The plugin file check is the canonical signal. The class/global fallbacks
	 * support installations where the plugin directory was renamed manually.
	 *
	 * @return bool
	 */
	public static function is_persian_woocommerce_active() {
		if ( self::is_plugin_active_safe( self::PERSIAN_WOOCOMMERCE_PLUGIN_FILE )
			|| self::is_plugin_slug_active( self::PERSIAN_WOOCOMMERCE_SLUG ) ) {
			return true;
		}

		if ( class_exists( 'PersianWooommercePlugin' ) ) {
			return true;
		}

		global $woocommerce_persian;
		return is_object( $woocommerce_persian );
	}

	/**
	 * Stop activation on WordPress versions that do not enforce Requires Plugins.
	 *
	 * @return void
	 */
	public static function enforce_activation_requirements() {
		$missing = self::get_missing_dependencies();
		if ( empty( $missing ) ) {
			return;
		}

		self::load_plugin_api();
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( MOBO_CORE_PLUGIN_FILE ) );
		}

		$message  = '<p><strong>' . esc_html__( 'Mobo Core فعال نشد.', 'mobo-core' ) . '</strong></p>';
		$message .= '<p>' . esc_html__( 'برای استفاده از این افزونه، پیش نیازهای زیر باید نصب و فعال باشند:', 'mobo-core' ) . '</p>';
		$message .= '<ul style="list-style:disc;padding-right:22px;">';
		foreach ( $missing as $dependency ) {
			$status   = ! empty( $dependency['installed'] ) ? 'نصب شده ولی غیرفعال است' : 'نصب نشده است';
			$message .= '<li><strong>' . esc_html( $dependency['name'] ) . '</strong>: ' . esc_html( $status ) . '</li>';
		}
		$message .= '</ul>';
		$message .= '<p><a href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'بازگشت به افزونه ها', 'mobo-core' ) . '</a></p>';

		wp_die(
			wp_kses_post( $message ),
			esc_html__( 'پیش نیازهای Mobo Core کامل نیست', 'mobo-core' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Register persistent admin notices when a dependency is removed later.
	 *
	 * @param array<int,array<string,mixed>>|null $missing Missing dependencies.
	 * @return void
	 */
	public static function register_admin_notices( $missing = null ) {
		if ( null === $missing ) {
			$missing = self::get_missing_dependencies();
		}

		if ( empty( $missing ) ) {
			return;
		}

		$callback = function () use ( $missing ) {
			self::render_admin_notice( $missing );
		};

		add_action( 'admin_notices', $callback );
		add_action( 'network_admin_notices', $callback );
	}

	/**
	 * Render the missing dependency notice.
	 *
	 * @param array<int,array<string,mixed>> $missing Missing dependencies.
	 * @return void
	 */
	private static function render_admin_notice( $missing ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$names = array();
		foreach ( $missing as $dependency ) {
			$names[] = isset( $dependency['name'] ) ? (string) $dependency['name'] : '';
		}
		$names = array_values( array_filter( $names ) );

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Mobo Core غیرفعال است:', 'mobo-core' );
		echo '</strong> ';
		echo esc_html(
			sprintf(
				'برای اجرای افزونه باید %s نصب و فعال باشد.',
				implode( ' و ', $names )
			)
		);
		echo ' <a href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '">';
		echo esc_html__( 'مدیریت افزونه ها', 'mobo-core' );
		echo '</a></p></div>';
	}

	/**
	 * Check plugin active state, including network activation.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	private static function is_plugin_active_safe( $plugin_file ) {
		self::load_plugin_api();

		$active = function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
		if ( ! $active && is_multisite() && function_exists( 'is_plugin_active_for_network' ) ) {
			$active = is_plugin_active_for_network( $plugin_file );
		}

		return (bool) $active;
	}

	/**
	 * Check active plugins by their WordPress.org slug.
	 *
	 * This keeps dependency detection valid if a future release changes its main
	 * PHP filename while preserving the official plugin directory/slug.
	 *
	 * @param string $slug WordPress.org plugin slug.
	 * @return bool
	 */
	private static function is_plugin_slug_active( $slug ) {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		if ( is_multisite() && function_exists( 'get_site_option' ) ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_active ) ) {
				$active = array_merge( $active, array_keys( $network_active ) );
			}
		}

		foreach ( $active as $plugin_file ) {
			if ( $slug === dirname( (string) $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a required plugin exists in wp-content/plugins.
	 *
	 * @param string $plugin_file Preferred plugin basename.
	 * @param string $slug        WordPress.org plugin slug.
	 * @return bool
	 */
	private static function is_plugin_installed( $plugin_file, $slug ) {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}

		$plugins_dir = trailingslashit( WP_PLUGIN_DIR );
		return file_exists( $plugins_dir . ltrim( $plugin_file, '/' ) )
			|| is_dir( $plugins_dir . trim( $slug, '/' ) );
	}

	/**
	 * Load WordPress plugin helper functions when needed.
	 *
	 * @return void
	 */
	private static function load_plugin_api() {
		if ( function_exists( 'is_plugin_active' ) && function_exists( 'deactivate_plugins' ) ) {
			return;
		}

		$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( file_exists( $plugin_api ) ) {
			require_once $plugin_api;
		}
	}
}
