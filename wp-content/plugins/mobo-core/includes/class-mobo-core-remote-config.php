<?php
/**
 * Signed remote configuration client.
 *
 * The .NET Portal is the source of truth. WordPress stores only a signed,
 * last-known-good cache and never trusts local writes for managed keys.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Remote_Config {

	const CACHE_PREFIX = "<?php exit; ?>\n";
	const STATUS_LAST_ATTEMPT = 'mobo_core_remote_config_last_attempt_at';
	const STATUS_LAST_SUCCESS = 'mobo_core_remote_config_last_success_at';
	const STATUS_LAST_ERROR   = 'mobo_core_remote_config_last_error';
	const STATUS_LAST_RESULT  = 'mobo_core_remote_config_last_result';

	private static $instance = null;
	private $loaded = false;
	private $active = false;
	private $payload = array();
	private $envelope = array();
	private $load_error = '';

	/**
	 * @return Mobo_Core_Remote_Config
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register runtime and admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		$this->load_cache();

		foreach ( self::fixed_managed_keys() as $key ) {
			add_filter( 'pre_option_' . $key, array( $this, 'filter_pre_option' ), 10, 3 );
			add_filter( 'pre_update_option_' . $key, array( $this, 'filter_pre_update_option' ), 10, 3 );
		}

		foreach ( array_keys( self::bootstrap_option_map() ) as $key ) {
			add_filter( 'pre_option_' . $key, array( $this, 'filter_pre_bootstrap_option' ), 10, 3 );
			add_filter( 'pre_update_option_' . $key, array( $this, 'filter_pre_update_option' ), 10, 3 );
		}

		// Also protect dynamically named shipping mappings from direct writes.
		add_filter( 'pre_update_option', array( $this, 'filter_pre_update_option_generic' ), 10, 3 );

		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 99 );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'admin_footer', array( $this, 'render_admin_readonly_script' ) );
		add_action( 'admin_post_mobo_core_refresh_remote_config', array( $this, 'handle_manual_refresh' ) );
		add_action( 'admin_init', array( $this, 'maybe_refresh_in_admin' ) );
	}

	/**
	 * Whether a verified remote configuration is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$this->load_cache();
		return $this->active;
	}

	/**
	 * Whether this installation has ever been bound to the remote control plane.
	 * Once bound, local database options are never trusted again, even when both
	 * cache copies are damaged or temporarily unavailable.
	 *
	 * @return bool
	 */
	public function is_enforced() {
		$this->load_cache();
		return $this->active || '' !== $this->read_installation_id();
	}

	/**
	 * Get a managed value.
	 *
	 * @param string $key Key.
	 * @param bool   $found Found flag.
	 * @return mixed
	 */
	public function get_value( $key, &$found = null ) {
		$found = false;
		$this->load_cache();

		if ( ! self::is_managed_key( $key ) ) {
			return null;
		}

		$enforced = $this->active || '' !== $this->read_installation_id();
		if ( ! $enforced ) {
			return null;
		}

		$settings = $this->active && isset( $this->payload['settings'] ) && is_array( $this->payload['settings'] )
			? $this->payload['settings']
			: array();

		$found = true;
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		// Once an installation is bound, missing or damaged remote configuration
		// must never fall back to mutable wp_options. Fixed keys use conservative
		// code defaults; dynamic mappings are treated as intentionally unset.
		if ( 'mobo_category_map' === $key ) {
			return array();
		}
		$defaults = class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::defaults() : array();
		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
	}

	/**
	 * Get one remote category mapping.
	 *
	 * @param string $remote_guid Remote GUID.
	 * @return int
	 */
	public function get_category_term_id( $remote_guid ) {
		$found = false;
		$map   = $this->get_value( 'mobo_category_map', $found );
		if ( ! $found || ! is_array( $map ) ) {
			return 0;
		}

		$remote_guid = sanitize_text_field( (string) $remote_guid );
		return isset( $map[ $remote_guid ] ) ? absint( $map[ $remote_guid ] ) : 0;
	}

	/**
	 * Dynamic pre-option filter.
	 *
	 * @param mixed  $pre Current short-circuit value.
	 * @param string $option Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function filter_pre_option( $pre, $option = '', $default = false ) {
		$found = false;
		$value = $this->get_value( $option, $found );
		return $found ? $value : $pre;
	}

	/**
	 * Return file/constant-backed bootstrap credentials after installation bind.
	 * Database values are accepted only before the first signed configuration is
	 * activated, then copied to a private 0600 file and ignored afterwards.
	 *
	 * @param mixed  $pre Current short-circuit value.
	 * @param string $option Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function filter_pre_bootstrap_option( $pre, $option = '', $default = false ) {
		if ( ! $this->is_enforced() || ! array_key_exists( $option, self::bootstrap_option_map() ) ) {
			return $pre;
		}

		return $this->get_bootstrap_credential( $option, $default, false );
	}

	/**
	 * Prevent local mutation of managed settings after remote activation.
	 *
	 * @param mixed  $value New value.
	 * @param mixed  $old_value Old value.
	 * @param string $option Option key.
	 * @return mixed
	 */
	public function filter_pre_update_option( $value, $old_value, $option = '' ) {
		if ( $this->is_enforced() && ( self::is_managed_key( $option ) || array_key_exists( $option, self::bootstrap_option_map() ) ) ) {
			return $old_value;
		}
		return $value;
	}

	/**
	 * Generic pre-update filter. WordPress uses a different argument order for
	 * this hook than for pre_update_option_{$option}.
	 *
	 * @param mixed  $value New value.
	 * @param string $option Option key.
	 * @param mixed  $old_value Old value.
	 * @return mixed
	 */
	public function filter_pre_update_option_generic( $value, $option, $old_value ) {
		return $this->filter_pre_update_option( $value, $old_value, $option );
	}

	/**
	 * Refresh configuration when admin is active and cache is stale.
	 *
	 * @return void
	 */
	public function maybe_refresh_in_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->refresh_if_due( 'admin' );
	}

	/**
	 * Refresh when interval elapsed.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	public function refresh_if_due( $source = 'scheduled' ) {
		$interval = defined( 'MOBO_REMOTE_CONFIG_REFRESH_INTERVAL' )
			? max( 60, absint( MOBO_REMOTE_CONFIG_REFRESH_INTERVAL ) )
			: 300;
		$last = absint( get_option( self::STATUS_LAST_SUCCESS, 0 ) );
		if ( $last > 0 && ( time() - $last ) < $interval ) {
			return array( 'success' => true, 'status' => 'not-due', 'revision' => $this->get_revision() );
		}
		return $this->refresh( false, $source );
	}

	/**
	 * Fetch and activate signed configuration.
	 *
	 * @param bool   $force Force refresh.
	 * @param string $source Source.
	 * @return array
	 */
	public function refresh( $force = false, $source = 'manual' ) {
		update_option( self::STATUS_LAST_ATTEMPT, time(), false );

		if ( ! function_exists( 'openssl_verify' ) ) {
			return $this->save_failure( 'openssl_missing', 'OpenSSL signature verification is not available.' );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'remote_config_refresh', 60 ) : true;
		if ( false === $lock ) {
			return array( 'success' => false, 'status' => 'locked' );
		}

		try {
			$base_url = $this->get_api_base_url();
			if ( '' === trim( $base_url ) ) {
				return $this->save_failure( 'missing_api_url', 'Mobo API base URL is missing.' );
			}

			$validated_base_url = $this->validate_api_base_url( $base_url );
			if ( is_wp_error( $validated_base_url ) ) {
				return $this->save_failure( $validated_base_url->get_error_code(), $validated_base_url->get_error_message() );
			}

			$url = trailingslashit( $validated_base_url ) . 'api/mobo/configuration';

			$headers = $this->build_headers();
			if ( is_wp_error( $headers ) ) {
				return $this->save_failure( $headers->get_error_code(), $headers->get_error_message() );
			}

			$bootstrapped = false;
			for ( $attempt = 0; $attempt < 2; $attempt++ ) {
				$request_headers = $headers;
				if ( ! $force && $this->get_revision() > 0 ) {
					$request_headers['If-None-Match'] = '"mobo-config-' . $this->get_revision() . '"';
				}

				$response = wp_remote_get(
					$url,
					array(
						'timeout'     => 20,
						'redirection' => 2,
						'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'remote_config' ),
						'headers'     => $request_headers,
					)
				);

				if ( is_wp_error( $response ) ) {
					return $this->save_failure( 'request_failed', $response->get_error_message() );
				}

				$status = absint( wp_remote_retrieve_response_code( $response ) );
				if ( 304 === $status ) {
					// ACK again so a previously lost non-blocking acknowledgement is
					// eventually repaired on the next poll.
					if ( $this->active && ! empty( $this->payload ) ) {
						$this->acknowledge( $url, $headers, $this->payload, 'applied', '' );
					}
					return $this->save_success( array( 'success' => true, 'status' => 'not-modified', 'revision' => $this->get_revision() ) );
				}

				if ( 404 === $status && ! $this->is_enforced() && ! $bootstrapped && $this->auto_bootstrap_enabled() ) {
					$bootstrap = $this->bootstrap_remote_configuration( $url, $headers );
					if ( empty( $bootstrap['success'] ) ) {
						return $bootstrap;
					}
					$bootstrapped = true;
					$force = true;
					continue;
				}

				if ( $status < 200 || $status >= 300 ) {
					$body = wp_remote_retrieve_body( $response );
					return $this->save_failure( 'http_' . $status, 'Configuration endpoint returned HTTP ' . $status . '. ' . substr( (string) $body, 0, 500 ) );
				}

				$body = wp_remote_retrieve_body( $response );
				$verified = $this->verify_envelope( $body );
				if ( is_wp_error( $verified ) ) {
					return $this->save_failure( $verified->get_error_code(), $verified->get_error_message() );
				}

				$write = $this->activate_verified_envelope( $verified['envelope'], $verified['payload'] );
				if ( is_wp_error( $write ) ) {
					return $this->save_failure( $write->get_error_code(), $write->get_error_message() );
				}

				$this->acknowledge( $url, $headers, $verified['payload'], 'applied', '' );
				return $this->save_success(
					array(
						'success'  => true,
						'status'   => $bootstrapped ? 'bootstrapped-and-applied' : 'applied',
						'revision' => absint( $verified['payload']['revision'] ),
						'hash'     => sanitize_text_field( (string) $verified['payload']['settingsHash'] ),
						'source'   => sanitize_key( (string) $source ),
					)
				);
			}

			return $this->save_failure( 'bootstrap_fetch_failed', 'Configuration was bootstrapped but could not be fetched.' );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && false !== $lock ) {
				Mobo_Core_Lock::release( 'remote_config_refresh', $lock );
			}
		}
	}

	/**
	 * Admin page registration.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_submenu_page(
			'mobo-core',
			'تنظیمات مرکزی',
			'تنظیمات مرکزی',
			'manage_options',
			'mobo-core-remote-config',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render read-only diagnostics and active cache.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		$this->load_cache();
		$status = $this->get_status();
		$display_payload = $this->mask_sensitive_data( $this->payload );
		?>
		<div class="wrap" dir="rtl">
			<h1>تنظیمات مرکزی Mobo Core</h1>
			<p>این صفحه فقط وضعیت و Cache امضاشده را نمایش می‌دهد. تغییر تنظیمات فقط در Portal .NET انجام می‌شود.</p>
			<table class="widefat striped" style="max-width:1000px">
				<tbody>
				<tr><th>وضعیت امضا</th><td><?php echo $status['active'] ? '<strong style="color:#087f23">معتبر</strong>' : '<strong style="color:#b32d2e">نامعتبر/موجود نیست</strong>'; ?></td></tr>
				<tr><th>Installation ID</th><td dir="ltr"><?php echo esc_html( $status['installationId'] ); ?></td></tr>
				<tr><th>Revision</th><td><?php echo esc_html( (string) $status['revision'] ); ?></td></tr>
				<tr><th>Hash</th><td dir="ltr" style="word-break:break-all"><?php echo esc_html( $status['hash'] ); ?></td></tr>
				<tr><th>آخرین دریافت موفق</th><td><?php echo esc_html( $this->format_timestamp( $status['lastSuccessAt'] ) ); ?></td></tr>
				<tr><th>آخرین خطا</th><td dir="ltr"><?php echo esc_html( $status['lastError'] ); ?></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
				<input type="hidden" name="action" value="mobo_core_refresh_remote_config">
				<?php wp_nonce_field( 'mobo_core_refresh_remote_config', 'mobo_core_remote_config_nonce' ); ?>
				<button type="submit" class="button button-primary">دریافت مجدد تنظیمات</button>
			</form>
			<h2>Cache فعال (Read-only)</h2>
			<textarea readonly dir="ltr" style="width:100%;max-width:1200px;height:520px;font-family:monospace"><?php echo esc_textarea( wp_json_encode( $display_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Remote-managed notice.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! $this->is_enforced() || ! isset( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'mobo-core' ) ) {
			return;
		}
		?>
		<div class="notice notice-info"><p><strong>Mobo Core:</strong> تنظیمات این افزونه از Portal .NET دریافت و با امضای دیجیتال کنترل می‌شوند. فرم‌های تنظیمات محلی فقط خواندنی هستند.</p></div>
		<?php
	}

	/**
	 * Disable only settings-save forms; operational tools remain available.
	 *
	 * @return void
	 */
	public function render_admin_readonly_script() {
		if ( ! $this->is_enforced() || ! isset( $_GET['page'] ) || 'mobo-core' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('form').forEach(function (form) {
				var action = form.querySelector('input[name="action"][value="mobo_core_save_settings"]');
				if (!action) return;
				form.querySelectorAll('input, select, textarea, button').forEach(function (field) {
					if (field.type === 'hidden') return;
					field.disabled = true;
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle manual refresh.
	 *
	 * @return void
	 */
	public function handle_manual_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}
		check_admin_referer( 'mobo_core_refresh_remote_config', 'mobo_core_remote_config_nonce' );
		$this->refresh( true, 'admin-manual' );
		wp_safe_redirect( admin_url( 'admin.php?page=mobo-core-remote-config' ) );
		exit;
	}

	/**
	 * Expose compact status.
	 *
	 * @return array
	 */
	public function get_status() {
		$this->load_cache();
		return array(
			'active'         => $this->active,
			'installationId' => isset( $this->payload['installationId'] ) ? sanitize_text_field( (string) $this->payload['installationId'] ) : '',
			'revision'       => $this->get_revision(),
			'hash'           => isset( $this->payload['settingsHash'] ) ? sanitize_text_field( (string) $this->payload['settingsHash'] ) : '',
			'lastAttemptAt'  => absint( get_option( self::STATUS_LAST_ATTEMPT, 0 ) ),
			'lastSuccessAt'  => absint( get_option( self::STATUS_LAST_SUCCESS, 0 ) ),
			'lastError'      => (string) get_option( self::STATUS_LAST_ERROR, $this->load_error ),
			'lastResult'     => get_option( self::STATUS_LAST_RESULT, array() ),
		);
	}

	/**
	 * Is fixed/dynamic key managed remotely.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_managed_key( $key ) {
		$key = (string) $key;
		if ( in_array( $key, self::fixed_managed_keys(), true ) ) {
			return true;
		}
		return (bool) preg_match( '/^mobo_core_wc_shipping_method_map_(?:mobo_only|mixed)_zone_\d+_[a-z0-9_-]+_\d+$/', $key )
			|| (bool) preg_match( '/^mobo_core_wc_shipping_method_map_zone_\d+_[a-z0-9_-]+_\d+$/', $key )
			|| (bool) preg_match( '/^mobo_core_shipping_allowed_ids_(?:mobo_only|mixed)_state_\d+_(?:before12|after12)$/', $key );
	}

	/**
	 * Fixed remotely managed keys. Bootstrap credentials and runtime state are excluded.
	 *
	 * @return array
	 */
	/**
	 * Local bootstrap secrets required to authenticate configuration pulls and
	 * cron/webhook requests. These are not part of the signed business settings.
	 *
	 * @return array<string,string>
	 */
	public static function bootstrap_option_map() {
		return array(
			'mobo_core_api_base_url'  => 'MOBO_API_BASE_URL',
			'mobo_core_token'         => 'MOBO_TOKEN',
			'mobo_core_security_code' => 'MOBO_SECURITY_CODE',
			'mobo_core_cron_token'    => 'MOBO_CRON_TOKEN',
		);
	}

	public static function fixed_managed_keys() {
		return array(
			'global_product_auto_stock', 'global_product_auto_price', 'global_product_auto_title',
			'global_product_auto_compare_price', 'global_product_auto_slug', 'global_update_categories',
			'global_update_images', 'mobo_core_only_in_stock', 'mobo_core_category_mapping_enabled',
			'mobo_core_category_mapping_required', 'mobo_default_category_id', 'mobo_price_type',
			'global_additional_price', 'global_additional_percentage', 'mobo_dynamic_price',
			'mobo_core_sync_time_budget_seconds', 'mobo_core_webhook_files_per_run',
			'mobo_core_webhook_max_try', 'mobo_core_webhook_expire_days',
			'mobo_core_variant_parent_wait_timeout_seconds', 'mobo_core_pull_payload_enabled',
			'mobo_core_payload_pull_timeout_seconds', 'mobo_core_api_request_timeout_seconds',
			'mobo_core_transient_retry_max_try', 'mobo_core_waiting_for_portal_retry_delay_seconds',
			'mobo_core_reprice_batch_size', 'mobo_core_products_per_page',
			'mobo_core_product_cursor_sync_enabled', 'mobo_core_variants_per_page',
			'mobo_core_variant_cursor_sync_enabled', 'mobo_core_images_per_run',
			'mobo_core_image_queue_enabled', 'mobo_core_image_queue_blocking',
			'mobo_core_image_max_try', 'mobo_core_image_retry_base_seconds',
			'mobo_core_image_refresh_enabled', 'mobo_core_image_refresh_delete_old',
			'mobo_core_image_refresh_generate_subsizes', 'mobo_core_image_refresh_cleanup_leftover_subsizes',
			'mobo_core_image_refresh_per_run', 'mobo_core_image_refresh_scan_limit',
			'mobo_core_image_refresh_max_try', 'mobo_core_image_refresh_retry_base_seconds',
			'mobo_core_image_refresh_automation_enabled', 'mobo_core_image_refresh_auto_delete_old_approved',
			'mobo_core_image_refresh_auto_delete_orphan_approved', 'mobo_core_orphan_image_cleanup_enabled',
			'mobo_core_orphan_image_scan_limit', 'mobo_core_orphan_image_delete_per_run',
			'mobo_core_missing_variants_behavior', 'mobo_core_excluded_product_urls',
			'mobo_core_categories_refresh_interval_hours', 'mobo_core_real_cron_time_budget_seconds',
			'mobo_core_real_cron_max_sync_steps', 'mobo_core_real_cron_lock_ttl_seconds',
			'mobo_core_real_cron_expected_interval_seconds', 'mobo_core_real_cron_process_webhooks',
			'mobo_core_process_webhook_on_receive', 'mobo_core_self_runner_enabled',
			'mobo_core_self_runner_continue_enabled', 'mobo_core_self_runner_min_interval_seconds',
			'mobo_core_self_runner_http_timeout_seconds', 'mobo_core_health_report_enabled',
			'mobo_core_health_report_min_interval_seconds', 'mobo_core_health_report_timeout_seconds',
			'mobo_core_checkout_validation_enabled', 'mobo_core_checkout_validate_only_mobo_products',
			'mobo_core_checkout_require_remote_guid', 'mobo_core_checkout_block_incomplete_sync',
			'mobo_core_checkout_local_stock_check_enabled', 'mobo_core_checkout_mobo_cart_validation_enabled',
			'mobo_core_checkout_mobo_debug_enabled', 'mobo_core_shipping_diagnostics_enabled',
			'mobo_core_checkout_mobo_site_url', 'mobo_core_checkout_mobo_username',
			'mobo_core_checkout_mobo_password', 'mobo_core_checkout_mobo_timeout_seconds',
			'mobo_core_checkout_mobo_cart_lock_wait_seconds', 'mobo_core_checkout_mobo_cart_lock_ttl_seconds',
			'mobo_core_checkout_external_validation_enabled', 'mobo_core_checkout_external_validation_url',
			'mobo_core_checkout_external_timeout_seconds', 'mobo_core_checkout_external_error_behavior',
			'mobo_core_mobo_order_submission_enabled', 'mobo_core_mobo_order_auto_complete_enabled',
			'mobo_core_mobo_order_sender_name', 'mobo_core_mobo_order_sender_mobile',
			'mobo_core_mobo_order_shipping_id', 'mobo_core_remote_shipping_sync_interval_hours',
			'mobo_core_address_mapping_enabled', 'mobo_core_address_mapping_sync_interval_days',
			'mobo_core_address_mapping_show_all_countries', 'mobo_core_address_manual_mapping',
			'mobo_core_sms_notifications_enabled', 'mobo_core_sms_non_mobo_enabled',
			'mobo_core_sms_non_mobo_recipients', 'mobo_core_sms_non_mobo_template',
			'mobo_core_sms_mobo_only_enabled', 'mobo_core_sms_mobo_only_recipients',
			'mobo_core_sms_mobo_only_template', 'mobo_core_sms_mixed_enabled',
			'mobo_core_sms_mixed_recipients', 'mobo_core_sms_mixed_template',
			'mobo_category_map'
		);
	}

	private function load_cache() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		$errors = array();
		foreach ( array( 'current.php', 'previous.php' ) as $candidate ) {
			$file = $this->cache_file( $candidate );
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$raw = file_get_contents( $file );
			if ( false === $raw ) {
				$errors[] = $candidate . ': unreadable';
				continue;
			}

			$verified = $this->verify_envelope( $this->strip_cache_prefix( $raw ) );
			if ( is_wp_error( $verified ) ) {
				$errors[] = $candidate . ': ' . $verified->get_error_message();
				continue;
			}

			$this->envelope = $verified['envelope'];
			$this->payload  = $verified['payload'];
			$this->active   = true;
			$this->ensure_bootstrap_credentials_migrated();

			// Restore a verified previous cache over a corrupted/missing current cache.
			if ( 'previous.php' === $candidate ) {
				$this->atomic_write(
					$this->cache_file( 'current.php' ),
					self::CACHE_PREFIX . wp_json_encode( $verified['envelope'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				);
			}
			return;
		}

		$this->load_error = implode( ' | ', $errors );
	}

	private function verify_envelope( $json ) {
		$json = (string) $json;
		if ( '' === $json || strlen( $json ) > 1572864 ) {
			return new WP_Error( 'envelope_size_invalid', 'Configuration envelope is empty or exceeds the 1.5 MB safety limit.' );
		}

		$envelope = json_decode( $json, true );
		if ( ! is_array( $envelope ) || empty( $envelope['payload'] ) || empty( $envelope['signature'] ) ) {
			return new WP_Error( 'invalid_envelope', 'Configuration envelope is invalid.' );
		}
		if ( 'RS256' !== ( isset( $envelope['algorithm'] ) ? (string) $envelope['algorithm'] : '' ) ) {
			return new WP_Error( 'unsupported_algorithm', 'Configuration signature algorithm is not supported.' );
		}

		$key_id = isset( $envelope['keyId'] ) ? sanitize_text_field( (string) $envelope['keyId'] ) : '';
		if ( '' === $key_id || ! hash_equals( $this->expected_key_id(), $key_id ) ) {
			return new WP_Error( 'key_id_mismatch', 'Configuration signing key ID is missing or not trusted.' );
		}

		$payload_bytes = $this->base64url_decode( $envelope['payload'] );
		$signature     = $this->base64url_decode( $envelope['signature'] );
		if ( false === $payload_bytes || false === $signature ) {
			return new WP_Error( 'invalid_base64', 'Configuration envelope encoding is invalid.' );
		}
		if ( strlen( $payload_bytes ) > 786432 || strlen( $signature ) > 1024 ) {
			return new WP_Error( 'signed_payload_size_invalid', 'Signed configuration payload or signature exceeds the safety limit.' );
		}
		$public_key_pem = $this->get_public_key_pem();
		if ( '' === trim( $public_key_pem ) ) {
			return new WP_Error( 'public_key_missing', 'Mobo configuration public key is missing.' );
		}
		$public_key = openssl_pkey_get_public( $public_key_pem );
		if ( false === $public_key ) {
			return new WP_Error( 'public_key_invalid', 'Mobo configuration public key is invalid.' );
		}

		$key_details = openssl_pkey_get_details( $public_key );
		if ( ! is_array( $key_details ) || OPENSSL_KEYTYPE_RSA !== $key_details['type'] || empty( $key_details['bits'] ) || absint( $key_details['bits'] ) < 3072 ) {
			return new WP_Error( 'public_key_strength_invalid', 'Mobo configuration public key must be an RSA key of at least 3072 bits.' );
		}

		$valid = openssl_verify( $payload_bytes, $signature, $public_key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $valid ) {
			return new WP_Error( 'signature_invalid', 'Configuration signature verification failed.' );
		}

		$payload = json_decode( $payload_bytes, true );
		if ( ! is_array( $payload ) ||
			! isset( $payload['schemaVersion'] ) || 1 !== absint( $payload['schemaVersion'] ) ||
			empty( $payload['installationId'] ) || ! preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', (string) $payload['installationId'] ) ||
			empty( $payload['revision'] ) || absint( $payload['revision'] ) <= 0 ||
			empty( $payload['domain'] ) ||
			empty( $payload['settingsHash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $payload['settingsHash'] ) ||
			! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) || count( $payload['settings'] ) > 5000 ) {
			return new WP_Error( 'payload_invalid', 'Signed configuration payload is invalid or uses an unsupported schema.' );
		}

		$expected_installation = $this->read_installation_id();
		if ( '' !== $expected_installation && ! hash_equals( $expected_installation, (string) $payload['installationId'] ) ) {
			return new WP_Error( 'installation_mismatch', 'Configuration belongs to another installation.' );
		}

		$expected_domain = $this->normalize_domain( home_url() );
		$payload_domain  = $this->normalize_domain( isset( $payload['domain'] ) ? $payload['domain'] : '' );
		if ( '' !== $expected_domain && '' !== $payload_domain && ! hash_equals( $expected_domain, $payload_domain ) ) {
			return new WP_Error( 'domain_mismatch', 'Configuration belongs to another domain.' );
		}

		foreach ( $payload['settings'] as $key => $value ) {
			if ( ! self::is_managed_key( $key ) ) {
				return new WP_Error( 'unknown_setting', 'Signed configuration contains an unsupported setting: ' . $key );
			}
		}

		return array( 'envelope' => $envelope, 'payload' => $payload );
	}

	private function activate_verified_envelope( $envelope, $payload ) {
		$current_revision = $this->get_revision();
		$incoming_revision = absint( $payload['revision'] );
		if ( $current_revision > 0 && $incoming_revision < $current_revision ) {
			return new WP_Error( 'revision_downgrade', 'Older configuration revision was rejected.' );
		}

		$dir = $this->cache_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'cache_directory_failed', 'Unable to create Mobo private cache directory.' );
		}
		$this->protect_cache_dir( $dir );

		$credentials = $this->persist_bootstrap_credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Persist the immutable installation binding before activating the first
		// cache. A failure here must stop activation; otherwise a valid cache from
		// another installation on the same domain could later be substituted.
		if ( '' === $this->read_installation_id() && ! $this->atomic_write(
			$this->cache_file( 'installation.php' ),
			self::CACHE_PREFIX . sanitize_text_field( (string) $payload['installationId'] )
		) ) {
			return new WP_Error( 'installation_binding_write_failed', 'Unable to persist the Mobo installation binding.' );
		}

		$current  = $this->cache_file( 'current.php' );
		$previous = $this->cache_file( 'previous.php' );
		if ( is_readable( $current ) ) {
			$current_contents = file_get_contents( $current );
			if ( false !== $current_contents && ! $this->atomic_write( $previous, $current_contents ) ) {
				return new WP_Error( 'previous_cache_write_failed', 'Unable to preserve the previous signed configuration cache.' );
			}
		}

		$json = wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || ! $this->atomic_write( $current, self::CACHE_PREFIX . $json ) ) {
			return new WP_Error( 'cache_write_failed', 'Unable to persist signed configuration cache.' );
		}

		$this->envelope = $envelope;
		$this->payload  = $payload;
		$this->active   = true;
		$this->loaded   = true;
		return true;
	}

	private function bootstrap_remote_configuration( $url, $headers ) {
		$settings = $this->export_local_settings();
		$response = wp_remote_post(
			trailingslashit( $url ) . 'bootstrap',
			array(
				'timeout'   => 25,
				'sslverify' => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'remote_config' ),
				'headers'   => array_merge( $headers, array( 'Content-Type' => 'application/json' ) ),
				'body'      => wp_json_encode(
					array(
						'schemaVersion' => 1,
						'pluginVersion' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
						'settings'      => $settings,
					),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->save_failure( 'bootstrap_request_failed', $response->get_error_message() );
		}
		$status = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $status < 200 || $status >= 300 ) {
			return $this->save_failure( 'bootstrap_http_' . $status, 'Configuration bootstrap returned HTTP ' . $status . '.' );
		}
		return array( 'success' => true, 'status' => 'bootstrapped' );
	}

	private function export_local_settings() {
		$defaults = class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::defaults() : array();
		$out = array();
		foreach ( self::fixed_managed_keys() as $key ) {
			if ( 'mobo_category_map' === $key ) {
				continue;
			}
			$fallback = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '';
			$out[ $key ] = get_option( $key, $fallback );
		}

		global $wpdb;
		$patterns = array(
			'mobo_core_wc_shipping_method_map_%',
			'mobo_core_shipping_allowed_ids_%',
		);
		foreach ( $patterns as $pattern ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ), ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$key = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
					if ( self::is_managed_key( $key ) ) {
						$out[ $key ] = maybe_unserialize( $row['option_value'] );
					}
				}
			}
		}

		$out['mobo_category_map'] = $this->export_category_map();
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function export_category_map() {
		if ( ! class_exists( 'Mobo_Core_Category_Map' ) || ! Mobo_Core_Category_Map::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = Mobo_Core_Category_Map::table_name();
		$rows = $wpdb->get_results( "SELECT remote_guid, manual_term_id FROM {$table} WHERE manual_term_id > 0 ORDER BY remote_guid ASC", ARRAY_A );
		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$guid = isset( $row['remote_guid'] ) ? sanitize_text_field( (string) $row['remote_guid'] ) : '';
				$id = isset( $row['manual_term_id'] ) ? absint( $row['manual_term_id'] ) : 0;
				if ( '' !== $guid && $id > 0 ) {
					$map[ $guid ] = $id;
				}
			}
		}
		return $map;
	}

	private function acknowledge( $url, $headers, $payload, $status, $error ) {
		wp_remote_post(
			trailingslashit( $url ) . 'ack',
			array(
				'timeout'   => 10,
				'blocking'  => false,
				'sslverify' => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'remote_config' ),
				'headers'   => array_merge( $headers, array( 'Content-Type' => 'application/json' ) ),
				'body'      => wp_json_encode(
					array(
						'revision'          => absint( $payload['revision'] ),
						'configurationHash' => sanitize_text_field( (string) $payload['settingsHash'] ),
						'status'             => sanitize_key( (string) $status ),
						'pluginVersion'      => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
						'error'              => sanitize_text_field( (string) $error ),
						'appliedAt'          => gmdate( 'c' ),
					),
				),
			)
		);
	}

	private function build_headers() {
		$token = $this->get_bootstrap_credential( 'mobo_core_token', '', true );
		$security = $this->get_bootstrap_credential( 'mobo_core_security_code', '', true );
		if ( '' === trim( $token ) || '' === trim( $security ) ) {
			return new WP_Error( 'credentials_missing', 'Token or Security Code is missing.' );
		}
		return array(
			'Accept'          => 'application/json',
			'Token'           => trim( $token ),
			'X-SEC'           => trim( $security ),
			'X-Mobo-Site-Url' => home_url( '/' ),
			'X-Mobo-Plugin-Version' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
		);
	}

	private function get_api_base_url() {
		$base = apply_filters( 'mobo_core_api_base_url', '' );
		if ( is_string( $base ) && '' !== trim( $base ) ) {
			return trailingslashit( esc_url_raw( $base ) );
		}

		return trailingslashit(
			esc_url_raw( (string) $this->get_bootstrap_credential( 'mobo_core_api_base_url', '', true ) )
		);
	}

	/**
	 * Remote configuration credentials and signed payloads must use TLS in
	 * production. Development HTTP requires an explicit opt-in constant.
	 *
	 * @param string $url API base URL.
	 * @return string|WP_Error
	 */
	private function validate_api_base_url( $url ) {
		$url = trailingslashit( esc_url_raw( (string) $url ) );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'invalid_api_url', 'Mobo API base URL is invalid.' );
		}

		if ( 'https' === strtolower( (string) $parts['scheme'] ) ) {
			return $url;
		}

		if ( defined( 'MOBO_ALLOW_INSECURE_REMOTE_CONFIG' ) && true === (bool) MOBO_ALLOW_INSECURE_REMOTE_CONFIG ) {
			return $url;
		}

		return new WP_Error(
			'insecure_api_url',
			'Mobo remote configuration requires an HTTPS API URL. Define MOBO_ALLOW_INSECURE_REMOTE_CONFIG=true only for an isolated development environment.'
		);
	}

	/**
	 * Resolve a bootstrap credential using constant/environment first, then the
	 * private credential file. Mutable database options are allowed only before
	 * the installation has been bound to a verified signed configuration.
	 *
	 * @param string $option_name Option key.
	 * @param mixed  $default Default value.
	 * @param bool   $allow_database_before_bind Allow one-time legacy import.
	 * @return mixed
	 */
	public function get_bootstrap_credential( $option_name, $default = '', $allow_database_before_bind = true ) {
		$map = self::bootstrap_option_map();
		if ( ! array_key_exists( $option_name, $map ) ) {
			return $default;
		}

		$constant_name = $map[ $option_name ];
		if ( defined( $constant_name ) && '' !== trim( (string) constant( $constant_name ) ) ) {
			return (string) constant( $constant_name );
		}

		$environment_value = getenv( $constant_name );
		if ( false !== $environment_value && '' !== trim( (string) $environment_value ) ) {
			return (string) $environment_value;
		}

		$stored = $this->read_bootstrap_credentials();
		if ( array_key_exists( $option_name, $stored ) && '' !== trim( (string) $stored[ $option_name ] ) ) {
			return $stored[ $option_name ];
		}

		if ( $allow_database_before_bind && ! $this->is_enforced() ) {
			return $this->read_raw_option( $option_name, $default );
		}

		return $default;
	}

	private function ensure_bootstrap_credentials_migrated() {
		if ( is_readable( $this->cache_file( 'credentials.php' ) ) ) {
			return;
		}

		$this->persist_bootstrap_credentials();
	}

	private function persist_bootstrap_credentials() {
		$values = array();
		foreach ( self::bootstrap_option_map() as $option_name => $constant_name ) {
			$value = '';
			if ( defined( $constant_name ) && '' !== trim( (string) constant( $constant_name ) ) ) {
				$value = (string) constant( $constant_name );
			} else {
				$environment_value = getenv( $constant_name );
				$value = false !== $environment_value && '' !== trim( (string) $environment_value )
					? (string) $environment_value
					: (string) $this->read_raw_option( $option_name, '' );
			}
			$values[ $option_name ] = $value;
		}

		foreach ( array( 'mobo_core_api_base_url', 'mobo_core_token', 'mobo_core_security_code' ) as $required ) {
			if ( '' === trim( (string) $values[ $required ] ) ) {
				return new WP_Error( 'bootstrap_credentials_missing', 'Required Mobo bootstrap credentials are missing: ' . $required );
			}
		}

		$dir = $this->cache_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'credential_directory_failed', 'Unable to create Mobo private credential directory.' );
		}
		$this->protect_cache_dir( $dir );

		$json = wp_json_encode( $values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || ! $this->atomic_write( $this->cache_file( 'credentials.php' ), self::CACHE_PREFIX . $json ) ) {
			return new WP_Error( 'credential_write_failed', 'Unable to persist Mobo bootstrap credentials outside the WordPress database.' );
		}

		return true;
	}

	private function read_bootstrap_credentials() {
		$file = $this->cache_file( 'credentials.php' );
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			return array();
		}

		$decoded = json_decode( $this->strip_cache_prefix( $raw ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function read_raw_option( $option_name, $default = '' ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return function_exists( 'get_option' ) ? get_option( $option_name, $default ) : $default;
		}

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		return null === $raw ? $default : maybe_unserialize( $raw );
	}

	private function expected_key_id() {
		if ( defined( 'MOBO_CONFIG_KEY_ID' ) && '' !== trim( (string) MOBO_CONFIG_KEY_ID ) ) {
			return sanitize_text_field( (string) MOBO_CONFIG_KEY_ID );
		}
		$env = getenv( 'MOBO_CONFIG_KEY_ID' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return sanitize_text_field( $env );
		}
		return 'mobo-config-v1';
	}

	private function get_public_key_pem() {
		if ( defined( 'MOBO_CONFIG_PUBLIC_KEY_PEM' ) && '' !== trim( (string) MOBO_CONFIG_PUBLIC_KEY_PEM ) ) {
			return (string) MOBO_CONFIG_PUBLIC_KEY_PEM;
		}
		$env = getenv( 'MOBO_CONFIG_PUBLIC_KEY_PEM' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return $env;
		}
		$file = MOBO_CORE_PLUGIN_DIR . 'config/mobo-config-public.pem';
		return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}

	private function auto_bootstrap_enabled() {
		return ! defined( 'MOBO_REMOTE_CONFIG_AUTO_BOOTSTRAP' ) || (bool) MOBO_REMOTE_CONFIG_AUTO_BOOTSTRAP;
	}

	private function get_revision() {
		return isset( $this->payload['revision'] ) ? absint( $this->payload['revision'] ) : 0;
	}

	private function cache_dir() {
		if ( defined( 'MOBO_CONFIG_CACHE_DIR' ) && '' !== trim( (string) MOBO_CONFIG_CACHE_DIR ) ) {
			return untrailingslashit( (string) MOBO_CONFIG_CACHE_DIR );
		}
		$environment_dir = getenv( 'MOBO_CONFIG_CACHE_DIR' );
		if ( false !== $environment_dir && '' !== trim( (string) $environment_dir ) ) {
			return untrailingslashit( (string) $environment_dir );
		}
		return trailingslashit( WP_CONTENT_DIR ) . 'mobo-private';
	}

	private function cache_file( $name ) {
		return trailingslashit( $this->cache_dir() ) . ltrim( $name, '/' );
	}

	private function read_installation_id() {
		$file = $this->cache_file( 'installation.php' );
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$raw = file_get_contents( $file );
		return false === $raw ? '' : sanitize_text_field( trim( $this->strip_cache_prefix( $raw ) ) );
	}

	private function strip_cache_prefix( $raw ) {
		return 0 === strpos( $raw, self::CACHE_PREFIX ) ? substr( $raw, strlen( self::CACHE_PREFIX ) ) : $raw;
	}

	private function protect_cache_dir( $dir ) {
		@file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php exit;\n", LOCK_EX );
		@file_put_contents( trailingslashit( $dir ) . '.htaccess', "Require all denied\nDeny from all\n", LOCK_EX );
		@file_put_contents( trailingslashit( $dir ) . 'web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>', LOCK_EX );
	}

	private function atomic_write( $file, $contents ) {
		$tmp = $file . '.tmp-' . wp_generate_uuid4();
		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			return false;
		}
		@chmod( $tmp, 0600 );
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}
		@chmod( $file, 0600 );
		return true;
	}

	private function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( $value, true );
	}

	private function normalize_domain( $url_or_domain ) {
		$value = trim( strtolower( (string) $url_or_domain ) );
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = preg_replace( '#^https?://#', '', $value );
			$host = explode( '/', $host )[0];
			$host = explode( ':', $host )[0];
		}
		$host = preg_replace( '/^www\./', '', strtolower( trim( (string) $host ) ) );
		return $host;
	}

	private function save_failure( $code, $message ) {
		$result = array( 'success' => false, 'status' => sanitize_key( (string) $code ), 'message' => sanitize_text_field( (string) $message ) );
		update_option( self::STATUS_LAST_ERROR, $result['message'], false );
		update_option( self::STATUS_LAST_RESULT, $result, false );
		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::error( 'Remote configuration refresh failed.', $result );
		}
		return $result;
	}

	private function save_success( $result ) {
		update_option( self::STATUS_LAST_SUCCESS, time(), false );
		delete_option( self::STATUS_LAST_ERROR );
		update_option( self::STATUS_LAST_RESULT, $result, false );
		return $result;
	}

	private function mask_sensitive_data( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $child_key => $child_value ) {
				$out[ $child_key ] = $this->mask_sensitive_data( $child_value, (string) $child_key );
			}
			return $out;
		}
		if ( preg_match( '/password|token|secret|username|mobile|recipients/i', $key ) && '' !== (string) $value ) {
			return '***';
		}
		return $value;
	}

	private function format_timestamp( $timestamp ) {
		$timestamp = absint( $timestamp );
		return $timestamp > 0 ? gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' : '—';
	}
}
