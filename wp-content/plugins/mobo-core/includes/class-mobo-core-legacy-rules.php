<?php
/**
 * Legacy option rules.
 *
 * Centralized access to old/global behavior switches.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Legacy_Rules {

	/**
	 * Cached options.
	 *
	 * @var array|null
	 */
	private $options = null;

	/**
	 * Get all legacy/global product options.
	 *
	 * Preserves old option names:
	 *
	 * - global_product_auto_stock
	 * - global_product_auto_price
	 * - global_product_auto_title
	 * - global_product_auto_caption
	 * - global_product_auto_compare_price
	 * - global_product_auto_slug
	 * - global_update_categories
	 * - global_update_images
	 * - mobo_core_only_in_stock
	 * - mobo_price_type
	 * - global_additional_price
	 * - global_additional_percentage
	 * - mobo_dynamic_price
	 * - mobo_default_category_id
	 *
	 * @return array
	 */
	public function get_options() {
		if ( null !== $this->options ) {
			return $this->options;
		}

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
			'mobo_default_category_id',
		);

		$options = array();

		foreach ( $keys as $key ) {
			$options[ $key ] = Mobo_Core_Settings::get( $key, $this->default_for_key( $key ) );
		}

		$this->options = $options;

		return $this->options;
	}

	/**
	 * Clear cached options.
	 *
	 * @return void
	 */
	public function refresh() {
		$this->options = null;
	}

	/**
	 * Should update stock?
	 *
	 * @return bool
	 */
	public function should_update_stock() {
		return $this->enabled( 'global_product_auto_stock', '1' );
	}

	/**
	 * Should update price?
	 *
	 * @return bool
	 */
	public function should_update_price() {
		return $this->enabled( 'global_product_auto_price', '1' );
	}

	/**
	 * Should update title?
	 *
	 * @return bool
	 */
	public function should_update_title() {
		return $this->enabled( 'global_product_auto_title', '1' );
	}

	/**
	 * Should update caption/short description?
	 *
	 * @return bool
	 */
	public function should_update_caption() {
		return $this->enabled( 'global_product_auto_caption', '1' );
	}

	/**
	 * Should update compare price?
	 *
	 * @return bool
	 */
	public function should_update_compare_price() {
		return $this->enabled( 'global_product_auto_compare_price', '1' );
	}

	/**
	 * Should update slug?
	 *
	 * @return bool
	 */
	public function should_update_slug() {
		return $this->enabled( 'global_product_auto_slug', '1' );
	}

	/**
	 * Should update categories automatically?
	 *
	 * @return bool
	 */
	public function should_update_categories() {
		return $this->enabled( 'global_update_categories', '1' );
	}

	/**
	 * Should update images automatically?
	 *
	 * @return bool
	 */
	public function should_update_images() {
		return $this->enabled( 'global_update_images', '1' );
	}

	/**
	 * Should only fetch in-stock products?
	 *
	 * @return bool
	 */
	public function only_in_stock() {
		return $this->enabled( 'mobo_core_only_in_stock', '0' );
	}

	/**
	 * Get price type.
	 *
	 * @return string
	 */
	public function price_type() {
		$options = $this->get_options();

		$price_type = isset( $options['mobo_price_type'] )
			? sanitize_key( (string) $options['mobo_price_type'] )
			: 'static-price';

		if ( ! in_array( $price_type, array( 'static-price', 'static-percentage', 'dynamic-price' ), true ) ) {
			return 'static-price';
		}

		return $price_type;
	}

	/**
	 * Get default category ID.
	 *
	 * @return int
	 */
	public function default_category_id() {
		$options = $this->get_options();

		return isset( $options['mobo_default_category_id'] )
			? absint( $options['mobo_default_category_id'] )
			: 0;
	}

	/**
	 * Missing variants behavior.
	 *
	 * @return string
	 */
	public function missing_variants_behavior() {
		$behavior = sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_missing_variants_behavior', 'outofstock' ) );

		if ( ! in_array( $behavior, array( 'outofstock', 'ignore' ), true ) ) {
			return 'outofstock';
		}

		return $behavior;
	}

	/**
	 * Check bool option from cached options.
	 *
	 * @param string $key Option key.
	 * @param string $default Default.
	 * @return bool
	 */
	private function enabled( $key, $default = '0' ) {
		$options = $this->get_options();

		$value = array_key_exists( $key, $options )
			? $options[ $key ]
			: $default;

		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Default values for legacy keys.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	private function default_for_key( $key ) {
		$defaults = array(
			'global_product_auto_stock'         => '1',
			'global_product_auto_price'         => '1',
			'global_product_auto_title'         => '1',
			'global_product_auto_caption'       => '1',
			'global_product_auto_compare_price' => '1',
			'global_product_auto_slug'          => '1',
			'global_update_categories'          => '1',
			'global_update_images'              => '1',

			'mobo_core_only_in_stock'           => '0',
			'mobo_price_type'                   => 'static-price',
			'global_additional_price'           => '0',
			'global_additional_percentage'      => '0',
			'mobo_dynamic_price'                => '[]',
			'mobo_default_category_id'          => '0',
		);

		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '';
	}
}