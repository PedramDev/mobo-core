<?php
/**
 * Settings helper.
 *
 * Preserves legacy option names while removing WP-Cron dependency.
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Settings {

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mobo_core_security_code'             => '',
			'mobo_core_api_base_url'              => '',
			'mobo_core_only_in_stock'             => '0',

			'global_product_auto_stock'           => '1',
			'global_product_auto_price'           => '1',
			'global_product_auto_title'           => '1',
			'global_product_auto_compare_price'   => '1',
			'global_product_auto_slug'            => '1',
			'global_update_categories'            => '1',
			'global_update_images'                => '1',
			'mobo_core_category_mapping_enabled'  => '1',
			'mobo_core_category_mapping_required' => '0',

			'mobo_default_category_id'            => '0',

			'mobo_price_type'                     => 'static-price',
			'global_additional_price'             => '0',
			'global_additional_percentage'        => '0',
			'mobo_dynamic_price'                  => '[]',

			'mobo_core_sync_time_budget_seconds'  => 8,
			'mobo_core_webhook_files_per_run'     => 4,
			'mobo_core_webhook_max_try'           => 5,
			'mobo_core_webhook_expire_days'       => 2,
			'mobo_core_variant_parent_wait_timeout_seconds' => 600,
			'mobo_core_pull_payload_enabled'        => '1',
			'mobo_core_payload_pull_timeout_seconds'=> 60,
			'mobo_core_api_request_timeout_seconds'    => 60,
			'mobo_core_transient_retry_max_try'        => 10,
			'mobo_core_waiting_for_portal_retry_delay_seconds' => 60,
			'mobo_core_reprice_batch_size'       => 20,
			'mobo_core_products_per_page'         => 1,
			'mobo_core_product_cursor_sync_enabled' => '1',
			'mobo_core_variants_per_page'         => 5,
			'mobo_core_variant_cursor_sync_enabled' => '1',
			'mobo_core_images_per_run'            => 3,
			'mobo_core_image_queue_enabled'       => '1',
			'mobo_core_image_queue_blocking'      => '1',
			'mobo_core_image_max_try'             => 5,
			'mobo_core_image_retry_base_seconds'  => 120,
			'mobo_core_image_refresh_enabled'     => '0',
			'mobo_core_image_refresh_delete_old'  => '1',
			'mobo_core_image_refresh_per_run'     => 2,
			'mobo_core_image_refresh_scan_limit'  => 500,
			'mobo_core_image_refresh_max_try'     => 5,
			'mobo_core_image_refresh_retry_base_seconds' => 120,
			'mobo_core_orphan_image_cleanup_enabled' => '1',
			'mobo_core_orphan_image_scan_limit' => 500,
			'mobo_core_orphan_image_delete_per_run' => 20,
			'mobo_core_missing_variants_behavior' => 'outofstock',

			'mobo_core_excluded_product_urls' => '',
			'mobo_core_categories_last_sync_at'              => 0,
			'mobo_core_categories_refresh_interval_hours'    => 12,
			
			'mobo_core_token'         => '',

			// Real cron is the primary execution path on customer hosts.
			'mobo_core_cron_token'                    => '',
			'mobo_core_real_cron_last_hit_at'          => 0,
			'mobo_core_real_cron_last_success_at'      => 0,
			'mobo_core_real_cron_last_result'          => array(),
			'mobo_core_real_cron_time_budget_seconds'  => 25,
			'mobo_core_real_cron_max_sync_steps'       => 3,
			'mobo_core_real_cron_lock_ttl_seconds'     => 120,
			'mobo_core_real_cron_expected_interval_seconds' => 60,
			'mobo_core_real_cron_process_webhooks'     => '1',
			'mobo_core_process_webhook_on_receive'     => '0',

			// Customer-side self runner. This replaces central runner/WP-Cron dependency.
			'mobo_core_self_runner_enabled'           => '1',
			'mobo_core_self_runner_continue_enabled'  => '1',
			'mobo_core_self_runner_min_interval_seconds' => 3,
			'mobo_core_self_runner_http_timeout_seconds' => 1,
			'mobo_core_self_runner_last_kick_attempt_at' => 0,
			'mobo_core_self_runner_last_kick_success_at' => 0,
			'mobo_core_self_runner_last_kick_result'   => array(),
			'mobo_core_self_runner_last_run_at'        => 0,
			'mobo_core_self_runner_last_run_success_at'=> 0,
			'mobo_core_self_runner_last_run_result'    => array(),

			// Customer-side health reporting to MoboCore.
			'mobo_core_health_report_enabled'          => '0',
			'mobo_core_health_report_url'              => '',
			'mobo_core_health_report_min_interval_seconds' => 300,
			'mobo_core_health_report_timeout_seconds'  => 15,
			'mobo_core_health_last_report_attempt_at'  => 0,
			'mobo_core_health_last_report_success_at'  => 0,
			'mobo_core_health_last_report_result'      => array(),

			// Checkout / pre-purchase validation. Disabled by default for safe upgrades.
			'mobo_core_checkout_validation_enabled'          => '0',
			'mobo_core_checkout_validate_only_mobo_products' => '1',
			'mobo_core_checkout_require_remote_guid'         => '1',
			'mobo_core_checkout_block_incomplete_sync'       => '1',
			'mobo_core_checkout_local_stock_check_enabled'   => '0',
			'mobo_core_checkout_mobo_cart_validation_enabled' => '0',
			'mobo_core_checkout_mobo_debug_enabled'           => '0',
			'mobo_core_shipping_diagnostics_enabled'              => '0',
			'mobo_core_checkout_mobo_site_url'                => 'https://mobomobo.ir',
			'mobo_core_checkout_mobo_username'                => '',
			'mobo_core_checkout_mobo_password'                => '',
			'mobo_core_checkout_mobo_timeout_seconds'         => 8,
			'mobo_core_checkout_mobo_cart_lock_wait_seconds' => 15,
			'mobo_core_checkout_mobo_cart_lock_ttl_seconds'  => 60,
			'mobo_core_checkout_mobo_cookie_jar'              => array(),
			'mobo_core_checkout_mobo_login_success_at'        => 0,
			'mobo_core_checkout_mobo_cart_success_at'         => 0,
			'mobo_core_checkout_external_validation_enabled' => '0',
			'mobo_core_checkout_external_validation_url'     => '',
			'mobo_core_checkout_external_timeout_seconds'    => 3,
			'mobo_core_checkout_external_error_behavior'     => 'allow',
			'mobo_core_checkout_last_validation_attempt_at'  => 0,
			'mobo_core_checkout_last_validation_success_at'  => 0,
			'mobo_core_checkout_last_validation_result'      => array(),

			// Automatic Mobo order submission defaults.
			'mobo_core_mobo_order_submission_enabled'       => '0',
			'mobo_core_mobo_order_auto_complete_enabled'    => '1',
			'mobo_core_mobo_order_sender_name'              => '',
			'mobo_core_mobo_order_sender_mobile'            => '',
			'mobo_core_mobo_order_shipping_id'              => 148395514,
			'mobo_core_remote_shipping_sync_interval_hours'   => 1,

			// Mobo checkout address mapping defaults.
			'mobo_core_address_mapping_enabled'             => '0',
			'mobo_core_address_mapping_sync_interval_days'  => 7,

			// SMS notifications through Persian WooCommerce SMS.
			'mobo_core_sms_notifications_enabled'           => '0',
			'mobo_core_sms_non_mobo_enabled'                => '0',
			'mobo_core_sms_non_mobo_recipients'             => '',
			'mobo_core_sms_non_mobo_template'               => '',
			'mobo_core_sms_mobo_only_enabled'               => '0',
			'mobo_core_sms_mobo_only_recipients'            => '',
			'mobo_core_sms_mobo_only_template'              => '',
			'mobo_core_sms_mixed_enabled'                   => '0',
			'mobo_core_sms_mixed_recipients'                => '',
			'mobo_core_sms_mixed_template'                  => '',
		);
	}

	/**
	 * Get option.
	 *
	 * @param string $key Option key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$defaults = self::defaults();

		if ( null === $fallback && array_key_exists( $key, $defaults ) ) {
			$fallback = $defaults[ $key ];
		}

		return get_option( $key, $fallback );
	}

	/**
	 * Get integer option with min/max clamp.
	 *
	 * @param string $key Option key.
	 * @param int    $default Default.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @return int
	 */
	public static function get_int( $key, $default, $min, $max ) {
		$value = absint( get_option( $key, $default ) );

		if ( $value < $min ) {
			return $min;
		}

		if ( $value > $max ) {
			return $max;
		}

		return $value;
	}

	/**
	 * Get boolean-like option.
	 *
	 * @param string $key Option key.
	 * @param string $default Default.
	 * @return bool
	 */
	public static function enabled( $key, $default = '0' ) {
		$value = get_option( $key, $default );

		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Save settings from admin post.
	 *
	 * @param array $post Raw post.
	 * @return void
	 */
	public static function save_from_post( $post ) {
		self::save_text( $post, 'mobo_core_security_code' );
		self::save_url( $post, 'mobo_core_api_base_url' );
		self::save_text( $post, 'mobo_core_token' );
		self::save_text( $post, 'mobo_core_cron_token' );
		self::save_url( $post, 'mobo_core_health_report_url' );
		self::save_url( $post, 'mobo_core_checkout_external_validation_url' );
		self::save_url( $post, 'mobo_core_checkout_mobo_site_url' );
		self::save_text( $post, 'mobo_core_checkout_mobo_username' );
		if ( isset( $post['mobo_core_checkout_mobo_password'] ) ) {
			$password = sanitize_text_field( wp_unslash( $post['mobo_core_checkout_mobo_password'] ) );

			if ( '' !== $password ) {
				update_option( 'mobo_core_checkout_mobo_password', $password, false );
				delete_option( 'mobo_core_checkout_mobo_cookie_jar' );
			}
		}

		self::save_bool( $post, 'mobo_core_only_in_stock' );
		self::save_bool( $post, 'global_product_auto_stock' );
		self::save_bool( $post, 'global_product_auto_price' );
		self::save_bool( $post, 'global_product_auto_title' );
		self::save_bool( $post, 'global_product_auto_compare_price' );
		self::save_bool( $post, 'global_product_auto_slug' );
		self::save_bool( $post, 'global_update_categories' );
		self::save_bool( $post, 'global_update_images' );
		self::save_bool( $post, 'mobo_core_category_mapping_enabled' );
		self::save_bool( $post, 'mobo_core_category_mapping_required' );

		if ( isset( $post['mobo_core_excluded_product_urls'] ) ) {
			update_option(
				'mobo_core_excluded_product_urls',
				sanitize_textarea_field( wp_unslash( $post['mobo_core_excluded_product_urls'] ) ),
				false
			);
		}

		update_option(
			'mobo_default_category_id',
			absint( isset( $post['mobo_default_category_id'] ) ? wp_unslash( $post['mobo_default_category_id'] ) : 0 ),
			false
		);

		$price_type = isset( $post['mobo_price_type'] )
			? sanitize_key( wp_unslash( $post['mobo_price_type'] ) )
			: null;

		if ( null === $price_type ) {
			$price_type = (string) self::get( 'mobo_price_type', 'static-price' );
		}

		if ( ! in_array( $price_type, array( 'static-price', 'static-percentage', 'dynamic-price' ), true ) ) {
			$price_type = 'static-price';
		}

		update_option( 'mobo_price_type', $price_type, false );

		/*
		 * Preserve old option names, but only save the relevant value based on selected price type.
		 */
		if ( 'static-price' === $price_type ) {
			self::save_decimal( $post, 'global_additional_price' );
			update_option( 'global_additional_percentage', '0', false );
			update_option( 'mobo_dynamic_price', '[]', false );
		} elseif ( 'static-percentage' === $price_type ) {
			update_option( 'global_additional_price', '0', false );
			self::save_decimal( $post, 'global_additional_percentage' );
			update_option( 'mobo_dynamic_price', '[]', false );
		} else {
			update_option( 'global_additional_price', '0', false );
			update_option( 'global_additional_percentage', '0', false );
			update_option( 'mobo_dynamic_price', self::sanitize_dynamic_price_rows( $post ), false );
		}

		self::save_int( $post, 'mobo_core_sync_time_budget_seconds', 8, 2, 25 );
		self::save_int( $post, 'mobo_core_webhook_files_per_run', 4, 1, 10 );
		self::save_int( $post, 'mobo_core_webhook_max_try', 5, 1, 20 );
		self::save_int( $post, 'mobo_core_webhook_expire_days', 2, 1, 30 );
		self::save_int_if_present( $post, 'mobo_core_variant_parent_wait_timeout_seconds', 600, 60, 86400 );
		self::save_bool_if_present( $post, 'mobo_core_pull_payload_enabled' );
		self::save_int_if_present( $post, 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 );
		self::save_int_if_present( $post, 'mobo_core_api_request_timeout_seconds', 60, 5, 180 );
		self::save_int_if_present( $post, 'mobo_core_transient_retry_max_try', 10, 1, 50 );
		self::save_int_if_present( $post, 'mobo_core_waiting_for_portal_retry_delay_seconds', 60, 10, 3600 );
		self::save_int_if_present( $post, 'mobo_core_reprice_batch_size', 20, 1, 200 );
		self::save_int( $post, 'mobo_core_products_per_page', 1, 1, 20 );
		self::save_bool_if_present( $post, 'mobo_core_product_cursor_sync_enabled' );
		self::save_int( $post, 'mobo_core_variants_per_page', 5, 1, 100 );
		self::save_bool_if_present( $post, 'mobo_core_variant_cursor_sync_enabled' );
		self::save_int( $post, 'mobo_core_images_per_run', 1, 0, 10 );
		self::save_bool_if_present( $post, 'mobo_core_image_queue_enabled' );
		self::save_bool_if_present( $post, 'mobo_core_image_queue_blocking' );
		self::save_int_if_present( $post, 'mobo_core_image_max_try', 5, 1, 20 );
		self::save_int_if_present( $post, 'mobo_core_image_retry_base_seconds', 120, 30, 900 );
		self::save_int( $post, 'mobo_core_real_cron_time_budget_seconds', 25, 5, 55 );
		self::save_int( $post, 'mobo_core_real_cron_max_sync_steps', 3, 1, 20 );
		self::save_int( $post, 'mobo_core_real_cron_lock_ttl_seconds', 120, 30, 600 );
		self::save_int( $post, 'mobo_core_real_cron_expected_interval_seconds', 60, 60, 3600 );
		self::save_bool( $post, 'mobo_core_real_cron_process_webhooks' );
		self::save_bool( $post, 'mobo_core_process_webhook_on_receive' );
		self::save_bool_if_present( $post, 'mobo_core_self_runner_enabled' );
		self::save_bool_if_present( $post, 'mobo_core_self_runner_continue_enabled' );
		self::save_int_if_present( $post, 'mobo_core_self_runner_min_interval_seconds', 3, 0, 60 );
		self::save_int_if_present( $post, 'mobo_core_self_runner_http_timeout_seconds', 1, 1, 10 );
		self::save_bool( $post, 'mobo_core_health_report_enabled' );
		self::save_int( $post, 'mobo_core_health_report_min_interval_seconds', 300, 60, 3600 );
		self::save_int( $post, 'mobo_core_health_report_timeout_seconds', 15, 5, 60 );

		self::save_bool_if_present( $post, 'mobo_core_checkout_validation_enabled' );
		update_option( 'mobo_core_checkout_validate_only_mobo_products', '1', false );
		update_option( 'mobo_core_checkout_require_remote_guid', '1', false );
		update_option( 'mobo_core_checkout_block_incomplete_sync', '1', false );
		if ( ! self::enabled( 'mobo_core_checkout_validation_enabled', '0' ) ) {
			delete_option( 'mobo_core_shared_mobo_cart_lock' );
		}
		self::save_bool_if_present( $post, 'mobo_core_checkout_local_stock_check_enabled' );
		self::save_bool_if_present( $post, 'mobo_core_checkout_mobo_cart_validation_enabled' );
		self::save_bool_if_present( $post, 'mobo_core_checkout_mobo_debug_enabled' );
		self::save_bool_if_present( $post, 'mobo_core_shipping_diagnostics_enabled' );
		self::save_int_if_present( $post, 'mobo_core_checkout_mobo_timeout_seconds', 8, 2, 20 );
		self::save_int_if_present( $post, 'mobo_core_checkout_mobo_cart_lock_wait_seconds', 15, 0, 45 );
		self::save_int_if_present( $post, 'mobo_core_checkout_mobo_cart_lock_ttl_seconds', 60, 15, 300 );
		self::save_int_if_present( $post, 'mobo_core_remote_shipping_sync_interval_hours', 1, 1, 168 );
		self::save_bool_if_present( $post, 'mobo_core_checkout_external_validation_enabled' );
		self::save_int_if_present( $post, 'mobo_core_checkout_external_timeout_seconds', 3, 1, 10 );

		$checkout_error_behavior = isset( $post['mobo_core_checkout_external_error_behavior'] )
			? sanitize_key( wp_unslash( $post['mobo_core_checkout_external_error_behavior'] ) )
			: 'allow';

		if ( ! in_array( $checkout_error_behavior, array( 'allow', 'block' ), true ) ) {
			$checkout_error_behavior = 'allow';
		}

		update_option( 'mobo_core_checkout_external_error_behavior', $checkout_error_behavior, false );

		$behavior = isset( $post['mobo_core_missing_variants_behavior'] )
			? sanitize_key( wp_unslash( $post['mobo_core_missing_variants_behavior'] ) )
			: 'outofstock';

		if ( ! in_array( $behavior, array( 'outofstock', 'ignore' ), true ) ) {
			$behavior = 'outofstock';
		}

		update_option( 'mobo_core_missing_variants_behavior', $behavior, false );
	}

	/**
	 * Convert dynamic pricing UI rows to legacy JSON.
	 *
	 * Legacy expected shape:
	 * [
	 *   {
	 *     "is_active": "true",
	 *     "low": "1000",
	 *     "high": "5000",
	 *     "benefit_type": "static",
	 *     "benefit": "100"
	 *   }
	 * ]
	 *
	 * @param array $post Raw post.
	 * @return string
	 */
	private static function sanitize_dynamic_price_rows( $post ) {
		$rows = isset( $post['mobo_dynamic_price_rows'] ) ? wp_unslash( $post['mobo_dynamic_price_rows'] ) : array();

		if ( ! is_array( $rows ) ) {
			return '[]';
		}

		$clean = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$low          = isset( $row['low'] ) ? absint( $row['low'] ) : 0;
			$high         = isset( $row['high'] ) ? absint( $row['high'] ) : 0;
			$benefit      = isset( $row['benefit'] ) ? absint( $row['benefit'] ) : 0;
			$benefit_type = isset( $row['benefit_type'] ) ? sanitize_key( $row['benefit_type'] ) : 'static';
			$is_active    = isset( $row['is_active'] ) && 'true' === sanitize_text_field( $row['is_active'] ) ? 'true' : 'false';

			if ( $low <= 0 && $high <= 0 && $benefit <= 0 ) {
				continue;
			}

			if ( ! in_array( $benefit_type, array( 'static', 'percentage' ), true ) ) {
				$benefit_type = 'static';
			}

			/*
			 * If high is empty/zero but low is set, keep high = 0.
			 * Old pricing code requires price <= high, so a zero high will not match.
			 * Admin should normally set both low and high.
			 */
			$clean[] = array(
				'is_active'    => $is_active,
				'low'          => (string) $low,
				'high'         => (string) $high,
				'benefit_type' => $benefit_type,
				'benefit'      => (string) $benefit,
			);
		}

		$json = wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '[]' : $json;
	}

	/**
	 * Save sanitized text option.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @return void
	 */
	private static function save_text( $post, $key ) {
		update_option(
			$key,
			isset( $post[ $key ] ) ? sanitize_text_field( wp_unslash( $post[ $key ] ) ) : '',
			false
		);
	}

	/**
	 * Save sanitized URL option.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @return void
	 */
	private static function save_url( $post, $key ) {
		update_option(
			$key,
			isset( $post[ $key ] ) ? esc_url_raw( wp_unslash( $post[ $key ] ) ) : '',
			false
		);
	}


	/**
	 * Save integer option only when the field belongs to the submitted tab/form.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @param int    $default Default.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @return void
	 */
	private static function save_int_if_present( $post, $key, $default, $min, $max ) {
		if ( ! isset( $post[ $key ] ) ) {
			return;
		}

		self::save_int( $post, $key, $default, $min, $max );
	}

	/**
	 * Save boolean-like option only when the field belongs to the submitted tab/form.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @return void
	 */
	private static function save_bool_if_present( $post, $key ) {
		if ( ! isset( $post[ $key ] ) ) {
			return;
		}

		self::save_bool( $post, $key );
	}

	/**
	 * Save boolean-like option as 1/0.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @return void
	 */
	private static function save_bool( $post, $key ) {
		update_option(
			$key,
			isset( $post[ $key ] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $post[ $key ] ) ) ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0',
			false
		);
	}

	/**
	 * Save decimal option.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @return void
	 */
	private static function save_decimal( $post, $key ) {
		$value = isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : '0';
		$value = wc_format_decimal( $value );

		update_option( $key, $value, false );
	}

	/**
	 * Save integer option with range clamp.
	 *
	 * @param array  $post Post.
	 * @param string $key Option key.
	 * @param int    $default Default.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 * @return void
	 */
	private static function save_int( $post, $key, $default, $min, $max ) {
		$value = isset( $post[ $key ] ) ? absint( wp_unslash( $post[ $key ] ) ) : $default;
		$value = min( $max, max( $min, $value ) );

		update_option( $key, $value, false );
	}
}