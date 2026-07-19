<?php
/**
 * WooCommerce variation custom fields.
 *
 * Adds mobo_additional_price to each variation.
 * This is a per-variation profit override.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Variation_Fields {

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_additional_price_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_additional_price_field' ), 10, 2 );
	}

	/**
	 * Render additional price field in WooCommerce variation pricing section.
	 *
	 * @param int     $loop Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation Variation post.
	 * @return void
	 */
	public function render_additional_price_field( $loop, $variation_data, $variation ) {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$variation_id = isset( $variation->ID ) ? absint( $variation->ID ) : 0;

		if ( $variation_id <= 0 ) {
			return;
		}

		$value = get_post_meta( $variation_id, 'mobo_additional_price', true );

		woocommerce_wp_text_input(
			array(
				'id'                => 'mobo_additional_price_' . $loop,
				'name'              => 'mobo_additional_price[' . $loop . ']',
				'value'             => '' === $value ? '' : esc_attr( $value ),
				'label'             => 'سود اختصاصی موبو',
				'desc_tip'          => true,
				'description'       => 'اگر این مقدار پر شود، سود عمومی نادیده گرفته شده و همین مبلغ به قیمت این تنوع اضافه می‌شود. اگر خالی باشد، تنظیمات عمومی قیمت‌گذاری استفاده می‌شود.',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'wrapper_class'     => 'form-row form-row-full',
			)
		);
	}

	/**
	 * Save additional price field.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop Variation loop index.
	 * @return void
	 */
	public function save_additional_price_field( $variation_id, $loop ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $variation_id ) && ! current_user_can( 'edit_products' ) ) {
			return;
		}

		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		if ( ! isset( $_POST['variable_post_id'] ) || ! is_array( $_POST['variable_post_id'] ) ) {
			return;
		}

		$posted_variation_ids = array_map( 'absint', wp_unslash( $_POST['variable_post_id'] ) );

		if ( ! in_array( $variation_id, $posted_variation_ids, true ) ) {
			return;
		}

		if ( ! isset( $_POST['mobo_additional_price'] ) || ! is_array( $_POST['mobo_additional_price'] ) || ! isset( $_POST['mobo_additional_price'][ $loop ] ) ) {
			delete_post_meta( $variation_id, 'mobo_additional_price' );
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST['mobo_additional_price'][ $loop ] ) );

		if ( '' === $value ) {
			delete_post_meta( $variation_id, 'mobo_additional_price' );
			if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
				Mobo_Core_Cache_Purger::queue_product( $variation_id, 'variation-additional-price-removed' );
			}
			return;
		}

		$value = absint( $value );

		if ( $value <= 0 ) {
			delete_post_meta( $variation_id, 'mobo_additional_price' );
			if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
				Mobo_Core_Cache_Purger::queue_product( $variation_id, 'variation-additional-price-removed' );
			}
			return;
		}

		update_post_meta( $variation_id, 'mobo_additional_price', $value );
		if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
			Mobo_Core_Cache_Purger::queue_product( $variation_id, 'variation-additional-price-updated' );
		}
	}
}