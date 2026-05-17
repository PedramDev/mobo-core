<?php
/**
 * Settings helper.
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
			'mobo_core_api_token'                 => '',
			'mobo_core_only_in_stock'             => '0',

			'global_product_auto_stock'           => '1',
			'global_product_auto_price'           => '1',
			'global_product_auto_title'           => '1',
			'global_product_auto_caption'         => '1',
			'global_product_auto_compare_price'   => '1',
			'global_product_auto_slug'            => '1',
			'global_update_categories'            => '1',
			'global_update_images'                => '1',

			'mobo_price_type'                     => '0',
			'global_additional_price'             => '0',
			'global_additional_percentage'        => '0',
			'mobo_dynamic_price'                  => '0',

			'mobo_core_enable_wp_cron'            => 'no',
			'mobo_core_sync_time_budget_seconds'  => 8,
			'mobo_core_webhook_files_per_run'     => 1,
			'mobo_core_webhook_max_try'           => 5,
			'mobo_core_webhook_expire_days'       => 2,
			'mobo_core_products_per_page'         => 1,
			'mobo_core_variants_per_page'         => 5,
			'mobo_core_images_per_run'            => 1,
			'mobo_core_missing_variants_behavior' => 'outofstock',
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
	 * Get integer option.
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
		self::save_text( $post, 'mobo_core_api_token' );

		self::save_bool( $post, 'mobo_core_only_in_stock' );
		self::save_bool( $post, 'global_product_auto_stock' );
		self::save_bool( $post, 'global_product_auto_price' );
		self::save_bool( $post, 'global_product_auto_title' );
		self::save_bool( $post, 'global_product_auto_caption' );
		self::save_bool( $post, 'global_product_auto_compare_price' );
		self::save_bool( $post, 'global_product_auto_slug' );
		self::save_bool( $post, 'global_update_categories' );
		self::save_bool( $post, 'global_update_images' );
		self::save_bool( $post, 'mobo_dynamic_price' );

		self::save_text( $post, 'mobo_price_type' );
		self::save_decimal( $post, 'global_additional_price' );
		self::save_decimal( $post, 'global_additional_percentage' );

		update_option(
			'mobo_core_enable_wp_cron',
			isset( $post['mobo_core_enable_wp_cron'] ) && 'yes' === sanitize_text_field( wp_unslash( $post['mobo_core_enable_wp_cron'] ) ) ? 'yes' : 'no',
			false
		);

		self::save_int( $post, 'mobo_core_sync_time_budget_seconds', 8, 2, 25 );
		self::save_int( $post, 'mobo_core_webhook_files_per_run', 1, 1, 10 );
		self::save_int( $post, 'mobo_core_webhook_max_try', 5, 1, 20 );
		self::save_int( $post, 'mobo_core_webhook_expire_days', 2, 1, 30 );
		self::save_int( $post, 'mobo_core_products_per_page', 1, 1, 20 );
		self::save_int( $post, 'mobo_core_variants_per_page', 5, 1, 100 );
		self::save_int( $post, 'mobo_core_images_per_run', 1, 0, 10 );

		$behavior = isset( $post['mobo_core_missing_variants_behavior'] )
			? sanitize_key( wp_unslash( $post['mobo_core_missing_variants_behavior'] ) )
			: 'outofstock';

		if ( ! in_array( $behavior, array( 'outofstock', 'ignore' ), true ) ) {
			$behavior = 'outofstock';
		}

		update_option( 'mobo_core_missing_variants_behavior', $behavior, false );
	}

	private static function save_text( $post, $key ) {
		update_option(
			$key,
			isset( $post[ $key ] ) ? sanitize_text_field( wp_unslash( $post[ $key ] ) ) : '',
			false
		);
	}

	private static function save_url( $post, $key ) {
		update_option(
			$key,
			isset( $post[ $key ] ) ? esc_url_raw( wp_unslash( $post[ $key ] ) ) : '',
			false
		);
	}

	private static function save_bool( $post, $key ) {
		update_option(
			$key,
			isset( $post[ $key ] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $post[ $key ] ) ) ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0',
			false
		);
	}

	private static function save_decimal( $post, $key ) {
		$value = isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : '0';
		$value = wc_format_decimal( $value );

		update_option( $key, $value, false );
	}

	private static function save_int( $post, $key, $default, $min, $max ) {
		$value = isset( $post[ $key ] ) ? absint( wp_unslash( $post[ $key ] ) ) : $default;
		$value = min( $max, max( $min, $value ) );

		update_option( $key, $value, false );
	}
}