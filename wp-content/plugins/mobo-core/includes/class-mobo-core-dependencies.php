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
		return self::platform_requirements_met() && empty( self::get_missing_dependencies() );
	}

	/**
	 * Stable terminal Woo metadata IDs require a 64-bit PHP integer runtime.
	 *
	 * @return bool
	 */
	public static function platform_requirements_met() {
		return PHP_INT_SIZE >= 8;
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
	 * Stop activation on WordPress versions that do not enforce Requires Plugins.
	 *
	 * @return void
	 */
	public static function enforce_activation_requirements() {
		if ( ! self::platform_requirements_met() ) {
			self::load_plugin_api();
			if ( function_exists( 'deactivate_plugins' ) ) { deactivate_plugins( plugin_basename( MOBO_CORE_PLUGIN_FILE ) ); }
			wp_die(
				wp_kses_post( '<p><strong>' . esc_html__( 'Mobo Core فعال نشد.', 'mobo-core' ) . '</strong></p><p>' . esc_html__( 'نسخه 10.33.97 و جدیدتر برای نگهداری امن شناسه های متادیتای WooCommerce به PHP 64-bit نیاز دارد.', 'mobo-core' ) . '</p>' ),
				esc_html__( 'معماری PHP پشتیبانی نمی شود', 'mobo-core' ),
				array( 'back_link' => true )
			);
		}
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

	/** Whether the current administrator can repair plugin dependencies. @return bool */
	private static function current_user_can_manage_plugins() {
		return current_user_can( 'activate_plugins' ) || ( is_multisite() && current_user_can( 'manage_network_plugins' ) );
	}

	/**
	 * Register persistent admin notices when a dependency is removed later.
	 *
	 * @param array<int,array<string,mixed>>|null $missing Missing dependencies.
	 * @return void
	 */
	public static function register_platform_notice() {
		$callback = function () {
			if ( ! self::current_user_can_manage_plugins() ) { return; }
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Mobo Core غیرفعال است:', 'mobo-core' ) . '</strong> ' . esc_html__( 'این نسخه برای متادیتای terminal WooCommerce به PHP 64-bit نیاز دارد.', 'mobo-core' ) . '</p></div>';
		};
		add_action( 'admin_notices', $callback );
		add_action( 'network_admin_notices', $callback );
	}

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
		if ( ! self::current_user_can_manage_plugins() ) {
			return;
		}

		$states = array();
		foreach ( $missing as $dependency ) {
			$name = isset( $dependency['name'] ) ? sanitize_text_field( (string) $dependency['name'] ) : '';
			if ( '' === $name ) { continue; }
			$states[] = $name . ( ! empty( $dependency['installed'] ) ? ' (نصب شده ولی غیرفعال)' : ' (نصب نشده)' );
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Mobo Core غیرفعال است:', 'mobo-core' );
		echo '</strong> ';
		echo esc_html( 'پیش نیازها کامل نیستند: ' . implode( '، ', $states ) . '.' );
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

	/**
	 * Backend (machine) REST routes that must still answer while a requirement is
	 * missing. envA T13: with WooCommerce inactive (FTP rename, failed update,
	 * dependency auto-deactivation) the bootstrap returned before any route was
	 * registered, so cron/run, heartbeat, health and webhook answered 404
	 * rest_no_route. A cPanel cron / the Backend then saw "endpoint missing"
	 * (webhook deliveries may be treated as permanent failures) instead of the
	 * retryable "dependency missing" that mobo-cron.php already reports.
	 *
	 * SECURITY: /health and /health/report-now are deliberately NOT registered
	 * here. Mobo_Core_Health_Read_Cache::serve_health_snapshot() answers any
	 * handler of GET /mobo-core/v1/health from the cached report on
	 * rest_dispatch_request (it relies on the route's permission_callback), so an
	 * open placeholder route would hand out the full Health report (license token
	 * included) without X-SEC. These routes need no credential because they only
	 * ever return the constant 503 below.
	 *
	 * @return string[] Routes relative to the mobo-core/v1 namespace.
	 */
	public static function dependency_missing_routes() {
		return array(
			'/cron/run', '/cron/status', '/worker/run', '/worker/status', '/heartbeat',
			'/webhook', '/webhook/run', '/sync/start', '/sync/run', '/sync/status', '/sync/cancel',
			'/upgrade/status', '/upgrade/apply', '/connection/provision',
		);
	}

	/**
	 * 503 body for a Backend route while requirements are missing. No secrets,
	 * no side effects; Retry-After tells callers to back off.
	 *
	 * @param string[] $missing Missing requirement labels.
	 * @return array{status:int,body:array,headers:array}
	 */
	public static function dependency_missing_rest_payload( $missing = array() ) {
		$missing = is_array( $missing ) ? array_values( array_filter( array_map( 'strval', $missing ) ) ) : array();
		return array(
			'status'  => 503,
			'body'    => array(
				'success' => false,
				'status'  => 'dependency-missing',
				'code'    => 'mobo_core_dependency_missing',
				'message' => 'WooCommerce (or another Mobo Core requirement) is not active; the request was not processed.',
				'missing' => $missing,
			),
			'headers' => array( 'Retry-After' => '300', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ),
		);
	}

	/**
	 * Register the 503 dependency-missing answers (called only from the bootstrap
	 * branch that skips the normal plugin runtime).
	 *
	 * @param string[] $missing Missing requirement labels.
	 * @return void
	 */
	public static function register_dependency_missing_routes( $missing = array() ) {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action(
			'rest_api_init',
			static function () use ( $missing ) {
				if ( ! function_exists( 'register_rest_route' ) ) {
					return;
				}
				$payload = self::dependency_missing_rest_payload( $missing );
				foreach ( self::dependency_missing_routes() as $route ) {
					register_rest_route(
						'mobo-core/v1',
						$route,
						array(
							'methods'             => array( 'GET', 'POST' ),
							'callback'            => static function () use ( $payload ) {
								$response = new WP_REST_Response( $payload['body'], $payload['status'] );
								foreach ( $payload['headers'] as $name => $value ) {
									$response->header( $name, $value );
								}
								return $response;
							},
							'permission_callback' => '__return_true',
						)
					);
				}
			}
		);
	}
}
