<?php
/**
 * Legacy behavior rules implemented cleanly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Legacy_Rules {

	/**
	 * Get legacy/global product options.
	 *
	 * @return array
	 */
	public function get_options() {
		$keys = array(
			'global_product_auto_stock',
			'global_product_auto_price',
			'global_product_auto_title',
			'global_product_auto_caption',
			'global_product_auto_compare_price',
			'global_product_auto_slug',
			'global_update_categories',
			'global_update_images',
			'mobo_core_only_in_stock',
			'mobo_price_type',
			'global_additional_price',
			'global_additional_percentage',
			'mobo_dynamic_price',
		);

		$options = array();

		foreach ( $keys as $key ) {
			$options[ $key ] = get_option( $key, '0' );
		}

		return $options;
	}

	/**
	 * Is option enabled?
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public function enabled( $key ) {
		$options = $this->get_options();

		return isset( $options[ $key ] ) && in_array( strtolower( (string) $options[ $key ] ), array( '1', 'yes', 'true', 'on' ), true );
	}

	public function should_update_stock() {
		return $this->enabled( 'global_product_auto_stock' );
	}

	public function should_update_price() {
		return $this->enabled( 'global_product_auto_price' );
	}

	public function should_update_title() {
		return $this->enabled( 'global_product_auto_title' );
	}

	public function should_update_caption() {
		return $this->enabled( 'global_product_auto_caption' );
	}

	public function should_update_compare_price() {
		return $this->enabled( 'global_product_auto_compare_price' );
	}

	public function should_update_slug() {
		return $this->enabled( 'global_product_auto_slug' );
	}

	public function should_update_categories() {
		return $this->enabled( 'global_update_categories' );
	}

	public function should_update_images() {
		return $this->enabled( 'global_update_images' );
	}

	public function should_apply_dynamic_price() {
		return $this->enabled( 'mobo_dynamic_price' );
	}

	/**
	 * Missing variants behavior.
	 *
	 * @return string
	 */
	public function missing_variants_behavior() {
		$value = sanitize_key( (string) get_option( 'mobo_core_missing_variants_behavior', 'outofstock' ) );

		if ( ! in_array( $value, array( 'outofstock', 'ignore' ), true ) ) {
			return 'outofstock';
		}

		return $value;
	}
}