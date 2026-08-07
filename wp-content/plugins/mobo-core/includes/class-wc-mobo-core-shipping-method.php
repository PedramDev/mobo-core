<?php
/**
 * One fixed WooCommerce shipping rate backed by one Mobo shipping method.
 *
 * This file is loaded only after WC_Shipping_Method exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Shipping_Method' ) && ! class_exists( 'WC_Mobo_Core_Shipping_Method' ) ) {
	class WC_Mobo_Core_Shipping_Method extends WC_Shipping_Method {

		public function __construct( $instance_id = 0 ) {
			$this->id                 = Mobo_Core_Automatic_Shipping::METHOD_ID;
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = 'روش ارسال موبو';
			$this->method_description = 'این Instance به‌صورت خودکار از یک روش ارسال موبو ساخته و بروزرسانی می‌شود.';
			$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

			$this->init_instance_form_fields();
			$this->init_settings();

			$this->enabled = $this->get_option( 'enabled', 'yes' );
			$this->title   = $this->get_option( 'title', 'روش ارسال موبو' );

			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_instance_form_fields() {
			$this->instance_form_fields = array(
				'title' => array(
					'title'       => 'عنوان',
					'type'        => 'text',
					'description' => 'عنوان روش ارسال برای مشتری؛ دکمه ترمیم خودکار آن را با عنوان موبو هماهنگ می‌کند.',
					'desc_tip'    => true,
				),
				'mobo_shipping_id' => array(
					'title'             => 'Mobo shipping_id',
					'type'              => 'number',
					'custom_attributes' => array( 'readonly' => 'readonly', 'min' => '1' ),
				),
				'mobo_description' => array(
					'title'             => 'توضیح موبو',
					'type'              => 'textarea',
					'custom_attributes' => array( 'readonly' => 'readonly' ),
				),
			);
		}

		public function calculate_shipping( $package = array() ) {
			if ( 'yes' !== $this->enabled ) {
				return;
			}

			$shipping_id = absint( $this->get_option( 'mobo_shipping_id', 0 ) );
			if ( $shipping_id <= 0 ) {
				return;
			}

			$result = Mobo_Core_Automatic_Shipping::calculate_rate_for_package( $shipping_id, $package );
			if ( empty( $result['available'] ) ) {
				return;
			}

			$method = isset( $result['method'] ) && is_array( $result['method'] ) ? $result['method'] : array();
			$this->add_rate(
				array(
					'id'       => $this->get_rate_id(),
					'label'    => $this->title,
					'cost'     => isset( $result['cost'] ) ? (float) $result['cost'] : 0.0,
					'package'  => $package,
					'taxes'    => false,
					'meta_data'=> array(
						'_mobo_shipping_id'   => $shipping_id,
						'_mobo_shipping_type' => isset( $method['type'] ) ? sanitize_key( $method['type'] ) : '',
						'_mobo_shipping_description' => isset( $method['description'] ) ? sanitize_textarea_field( $method['description'] ) : '',
					),
				)
			);
		}
	}
}
