<?php
/**
 * Retired WooCommerce shipping-method compatibility class.
 *
 * Mobo Core is mapping-only. This class is retained only so legacy references do
 * not fatally fail; it must never publish a checkout rate or become editable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Shipping_Method' ) && ! class_exists( 'WC_Mobo_Core_Shipping_Method' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WooCommerce shipping-method class; WC_ prefix is intentional and retained for backward compatibility.
	class WC_Mobo_Core_Shipping_Method extends WC_Shipping_Method {

		public function __construct( $instance_id = 0 ) {
			$this->id                 = Mobo_Core_Automatic_Shipping::METHOD_ID;
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = 'روش ارسال قدیمی موبو (غیرفعال)';
			$this->method_description = 'این روش قدیمی بازنشسته شده است. MoboCore فقط نگاشت روش‌های ارسال WooCommerce به shipping_id منبع را نگه می‌دارد.';
			$this->supports           = array();

			$this->init_instance_form_fields();
			$this->init_settings();

			$this->enabled = 'no';
			$this->title   = $this->get_option( 'title', 'روش ارسال قدیمی موبو' );
		}

		public function init_instance_form_fields() {
			$this->instance_form_fields = array(
				'mobo_shipping_id' => array(
					'title'             => 'Mobo shipping_id (قدیمی)',
					'type'              => 'number',
					'description'       => 'فقط برای تشخیص residue نسخه‌های قدیمی نگه داشته شده و در Checkout استفاده نمی‌شود.',
					'desc_tip'          => true,
					'custom_attributes' => array( 'readonly' => 'readonly', 'min' => '1' ),
				),
			);
		}

		public function calculate_shipping( $package = array() ) {
			unset( $package );
			/* Mapping-only invariant: a legacy instance can never publish a rate, even
			 * if another extension or stale code instantiates this compatibility class. */
			return;
		}

	}
}
