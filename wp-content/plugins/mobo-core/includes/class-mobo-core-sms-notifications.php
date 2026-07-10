<?php
/**
 * Mobo order-type SMS notifications through Persian WooCommerce SMS.
 *
 * This class does not implement SMS gateways itself. It delegates sending to
 * Persian WooCommerce SMS via PWSMS()->send_sms(), so the active gateway and
 * pattern support remain owned by that plugin.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_SMS_Notifications {

	const META_SENT_PREFIX = '_mobo_core_sms_notification_sent_';

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_checkout_order_processed' ), 99, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'handle_store_api_checkout_order_processed' ), 99, 1 );
	}

	/**
	 * Classic checkout hook.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function handle_checkout_order_processed( $order_id, $posted_data = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		$this->maybe_send_for_order( $order );
	}

	/**
	 * Block checkout hook.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function handle_store_api_checkout_order_processed( $order ) {
		$this->maybe_send_for_order( $order );
	}

	/**
	 * Send configured notification once for the order type.
	 *
	 * @param WC_Order|false $order Order.
	 * @return bool|WP_Error
	 */
	public function maybe_send_for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_sms_invalid_order', 'Invalid WooCommerce order.' );
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_sms_notifications_enabled', '0' ) ) {
			return false;
		}

		if ( ! function_exists( 'PWSMS' ) || ! is_object( PWSMS() ) || ! method_exists( PWSMS(), 'send_sms' ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: افزونه «پیامک حرفه ای ووکامرس» فعال نیست یا API ارسال آن در دسترس نیست.' );
			return new WP_Error( 'mobo_core_sms_pwsms_missing', 'Persian WooCommerce SMS is not available.' );
		}

		$scenario = $this->classify_order( $order );
		$config   = $this->get_scenario_config( $scenario );

		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		$sent_meta_key = self::META_SENT_PREFIX . $scenario;
		if ( $order->get_meta( $sent_meta_key ) ) {
			return false;
		}

		$recipients = $this->normalize_recipients( $config['recipients'], $order );
		$template   = trim( (string) $config['template'] );

		if ( empty( $recipients ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: برای نوع سفارش «' . $this->get_scenario_label( $scenario ) . '» شماره گیرنده معتبر تنظیم نشده است.' );
			return new WP_Error( 'mobo_core_sms_empty_recipients', 'SMS recipients are empty.' );
		}

		if ( '' === $template ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: برای نوع سفارش «' . $this->get_scenario_label( $scenario ) . '» متن/الگوی پیامک تنظیم نشده است.' );
			return new WP_Error( 'mobo_core_sms_empty_template', 'SMS template is empty.' );
		}

		$message = $this->render_template( $template, $order, $scenario );
		if ( '' === trim( $message ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: خروجی متن/الگوی پیامک خالی شد.' );
			return new WP_Error( 'mobo_core_sms_empty_message', 'Rendered SMS message is empty.' );
		}

		$result = PWSMS()->send_sms(
			array(
				'type'    => 4,
				'post_id' => $order->get_id(),
				'mobile'  => $recipients,
				'message' => $message,
			)
		);

		if ( true === $result ) {
			$order->update_meta_data( $sent_meta_key, current_time( 'mysql' ) );
			$order->save();
			$this->add_order_note( $order, 'پیامک موبو برای نوع سفارش «' . $this->get_scenario_label( $scenario ) . '» به این شماره ها ارسال شد: ' . implode( ', ', $recipients ) );
			return true;
		}

		$error = is_string( $result ) ? $result : wp_json_encode( $result );
		$this->add_order_note( $order, 'ارسال پیامک موبو ناموفق بود: ' . $error );
		return new WP_Error( 'mobo_core_sms_send_failed', $error );
	}

	/**
	 * Scenario labels.
	 *
	 * @return array
	 */
	public function get_scenarios() {
		return array(
			'non_mobo'  => 'سفارش غیر موبو',
			'mobo_only' => 'سفارش فقط محصولات موبو',
			'mixed'     => 'سفارش ترکیبی موبو و غیرموبو',
		);
	}

	/**
	 * Get scenario label.
	 *
	 * @param string $scenario Scenario.
	 * @return string
	 */
	public function get_scenario_label( $scenario ) {
		$scenarios = $this->get_scenarios();
		return isset( $scenarios[ $scenario ] ) ? $scenarios[ $scenario ] : (string) $scenario;
	}

	/**
	 * Read one scenario config.
	 *
	 * @param string $scenario Scenario.
	 * @return array
	 */
	public function get_scenario_config( $scenario ) {
		$scenario = $this->sanitize_scenario( $scenario );

		return array(
			'enabled'    => Mobo_Core_Settings::enabled( 'mobo_core_sms_' . $scenario . '_enabled', '0' ),
			'recipients' => (string) Mobo_Core_Settings::get( 'mobo_core_sms_' . $scenario . '_recipients', '' ),
			'template'   => (string) Mobo_Core_Settings::get( 'mobo_core_sms_' . $scenario . '_template', '' ),
		);
	}

	/**
	 * Classify order by Mobo product presence.
	 *
	 * @param WC_Order $order Order.
	 * @return string non_mobo|mobo_only|mixed
	 */
	public function classify_order( $order ) {
		$mobo = 0;
		$non  = 0;

		if ( ! $order instanceof WC_Order || ! method_exists( $order, 'get_items' ) ) {
			return 'non_mobo';
		}

		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product      = $line_item->get_product();
			$product_id   = absint( $line_item->get_product_id() );
			$variation_id = absint( $line_item->get_variation_id() );

			if ( $this->is_mobo_product( $product, $product_id, $variation_id ) ) {
				$mobo++;
			} else {
				$non++;
			}
		}

		if ( $mobo > 0 && $non > 0 ) {
			return 'mixed';
		}

		if ( $mobo > 0 ) {
			return 'mobo_only';
		}

		return 'non_mobo';
	}

	/**
	 * Detect product imported/synced from Mobo.
	 *
	 * @param WC_Product|false $product Product.
	 * @param int              $product_id Parent product ID.
	 * @param int              $variation_id Variation ID.
	 * @return bool
	 */
	private function is_mobo_product( $product, $product_id, $variation_id ) {
		$ids = array_filter( array( absint( $variation_id ), absint( $product_id ), $product instanceof WC_Product ? absint( $product->get_id() ) : 0 ) );

		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, 'variant_guid', true ) || get_post_meta( $id, 'product_guid', true ) ) {
				return true;
			}
			if ( absint( get_post_meta( $id, 'portal_variant_id', true ) ) > 0 || absint( get_post_meta( $id, 'mobo_portal_variant_id', true ) ) > 0 || absint( get_post_meta( $id, '_mobo_portal_variant_id', true ) ) > 0 ) {
				return true;
			}
			if ( absint( get_post_meta( $id, 'portal_product_id', true ) ) > 0 || absint( get_post_meta( $id, 'mobo_portal_product_id', true ) ) > 0 || absint( get_post_meta( $id, '_mobo_portal_product_id', true ) ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render template through PWSMS shortcodes plus Mobo-specific placeholders.
	 *
	 * @param string   $template Template.
	 * @param WC_Order $order Order.
	 * @param string   $scenario Scenario.
	 * @return string
	 */
	private function render_template( $template, $order, $scenario ) {
		$message = $template;
		$status  = method_exists( $order, 'get_status' ) ? $order->get_status() : 'created';

		if ( function_exists( 'PWSMS' ) && is_object( PWSMS() ) && method_exists( PWSMS(), 'replace_short_codes' ) ) {
			$message = PWSMS()->replace_short_codes( $message, $status, $order );
		}

		$counts = $this->count_mobo_and_non_mobo_items( $order );
		$custom_tags = array(
			'{mobo_order_type}'       => $scenario,
			'{mobo_order_type_label}' => $this->get_scenario_label( $scenario ),
			'{mobo_items_count}'      => (string) $counts['mobo'],
			'{non_mobo_items_count}'  => (string) $counts['non'],
		);

		return str_ireplace( array_keys( $custom_tags ), array_values( $custom_tags ), $message );
	}

	/**
	 * Count Mobo/non-Mobo line items.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function count_mobo_and_non_mobo_items( $order ) {
		$counts = array( 'mobo' => 0, 'non' => 0 );
		if ( ! $order instanceof WC_Order ) {
			return $counts;
		}

		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product      = $line_item->get_product();
			$product_id   = absint( $line_item->get_product_id() );
			$variation_id = absint( $line_item->get_variation_id() );
			if ( $this->is_mobo_product( $product, $product_id, $variation_id ) ) {
				$counts['mobo']++;
			} else {
				$counts['non']++;
			}
		}

		return $counts;
	}

	/**
	 * Normalize recipients; supports static numbers and {customer_mobile}.
	 *
	 * @param string   $raw Raw recipients.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function normalize_recipients( $raw, $order ) {
		$raw = str_ireplace(
			array( '{customer_mobile}', '{billing_phone}', '{phone}', '{mobile}' ),
			array( $this->get_order_billing_phone( $order ), $this->get_order_billing_phone( $order ), $this->get_order_billing_phone( $order ), $this->get_order_billing_phone( $order ) ),
			(string) $raw
		);

		$parts = preg_split( '/[\s,،;]+/u', $raw );
		$valid = array();

		foreach ( $parts as $part ) {
			$mobile = trim( (string) $part );
			if ( '' === $mobile ) {
				continue;
			}

			$is_valid = false;
			if ( function_exists( 'PWSMS' ) && is_object( PWSMS() ) && method_exists( PWSMS(), 'validate_mobile' ) ) {
				$is_valid = PWSMS()->validate_mobile( $mobile );
			} else {
				$is_valid = (bool) preg_match( '/^[+0-9][0-9\s\-()]{7,20}$/', $mobile );
			}

			if ( $is_valid ) {
				$valid[] = $mobile;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Get billing phone.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_order_billing_phone( $order ) {
		if ( $order instanceof WC_Order && method_exists( $order, 'get_billing_phone' ) ) {
			return (string) $order->get_billing_phone();
		}

		return '';
	}

	/**
	 * Sanitize scenario key.
	 *
	 * @param string $scenario Scenario.
	 * @return string
	 */
	private function sanitize_scenario( $scenario ) {
		$scenario = sanitize_key( (string) $scenario );
		return array_key_exists( $scenario, $this->get_scenarios() ) ? $scenario : 'non_mobo';
	}

	/**
	 * Add order note safely.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $message Message.
	 * @return void
	 */
	private function add_order_note( $order, $message ) {
		if ( $order instanceof WC_Order && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}
}
