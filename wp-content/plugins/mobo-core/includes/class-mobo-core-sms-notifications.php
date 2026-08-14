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

		$scenario = $this->classify_order( $order );
		$config   = $this->get_scenario_config( $scenario );

		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		$client = $this->get_pwsms_client();
		if ( is_wp_error( $client ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: ' . $client->get_error_message() );
			return $client;
		}

		$sent_meta_key = self::META_SENT_PREFIX . $scenario;
		if ( $order->get_meta( $sent_meta_key ) ) {
			return false;
		}

		$recipients = $this->normalize_recipients( $config['recipients'], $order, $client );
		$template   = trim( (string) $config['template'] );

		if ( empty( $recipients ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: برای نوع سفارش «' . $this->get_scenario_label( $scenario ) . '» شماره گیرنده معتبر تنظیم نشده است.' );
			return new WP_Error( 'mobo_core_sms_empty_recipients', 'SMS recipients are empty or invalid after normalization.' );
		}

		if ( '' === $template ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: برای نوع سفارش «' . $this->get_scenario_label( $scenario ) . '» متن/الگوی پیامک تنظیم نشده است.' );
			return new WP_Error( 'mobo_core_sms_empty_template', 'SMS template is empty.' );
		}

		$message = $this->render_template( $template, $order, $scenario, $client );
		if ( is_wp_error( $message ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: ' . $message->get_error_message() );
			return $message;
		}
		if ( '' === trim( $message ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو انجام نشد: خروجی متن/الگوی پیامک خالی شد.' );
			return new WP_Error( 'mobo_core_sms_empty_message', 'Rendered SMS message is empty.' );
		}

		$send = $this->dispatch_sms( $client, $recipients, $message, $order->get_id(), 'mobo_core_sms_send_failed' );
		if ( is_wp_error( $send ) ) {
			$this->add_order_note( $order, 'ارسال پیامک موبو ناموفق بود: ' . $send->get_error_message() );
			return $send;
		}

		$order->update_meta_data( $sent_meta_key, current_time( 'mysql' ) );
		$order->save();
		$this->add_order_note( $order, 'پیامک موبو برای نوع سفارش «' . $this->get_scenario_label( $scenario ) . '» به این شماره ها ارسال شد: ' . implode( ', ', $recipients ) );
		return true;
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
	 * Send the dedicated one-shot Mobo wallet low-balance alert.
	 *
	 * This alert is configured independently from order-type notifications so a
	 * store can disable customer/order SMS while still monitoring its Mobo wallet.
	 *
	 * @param int|float $balance Current Mobo wallet balance.
	 * @param int|float $threshold Configured low-balance threshold.
	 * @param int       $order_id WooCommerce order that triggered the balance check.
	 * @return true|WP_Error
	 */
	public function send_wallet_balance_alert( $balance, $threshold, $order_id = 0 ) {
		$client = $this->get_pwsms_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$recipients = $this->normalize_recipients( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_recipients', '' ), null, $client );
		$template   = trim( (string) Mobo_Core_Settings::get( 'mobo_core_wallet_alert_template', '' ) );

		if ( empty( $recipients ) ) {
			return new WP_Error( 'mobo_core_wallet_sms_empty_recipients', 'No valid Mobo wallet alert recipient remains after mobile normalization/validation.' );
		}

		if ( '' === $template ) {
			return new WP_Error( 'mobo_core_wallet_sms_empty_template', 'Mobo wallet alert template is empty.' );
		}

		$message = $template;
		$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		if ( $order instanceof WC_Order && method_exists( $client, 'replace_short_codes' ) ) {
			try {
				$status  = method_exists( $order, 'get_status' ) ? $order->get_status() : 'created';
				$message = $client->replace_short_codes( $message, $status, $order );
			} catch ( Throwable $e ) {
				return new WP_Error( 'mobo_core_wallet_sms_template_exception', 'Persian WooCommerce SMS shortcode rendering failed: ' . $e->getMessage() );
			}
		}

		$custom_tags = array(
			'{mobo_wallet_balance}'             => (string) $balance,
			'{mobo_wallet_balance_formatted}'   => number_format_i18n( (float) $balance, 0 ),
			'{mobo_wallet_threshold}'           => (string) $threshold,
			'{mobo_wallet_threshold_formatted}' => number_format_i18n( (float) $threshold, 0 ),
			'{site_name}'                       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'                        => home_url( '/' ),
		);

		$message = str_ireplace( array_keys( $custom_tags ), array_values( $custom_tags ), $message );
		if ( '' === trim( $message ) ) {
			return new WP_Error( 'mobo_core_wallet_sms_empty_message', 'Rendered Mobo wallet alert message is empty.' );
		}

		return $this->dispatch_sms( $client, $recipients, $message, absint( $order_id ), 'mobo_core_wallet_sms_send_failed' );
	}

	/**
	 * Render template through PWSMS shortcodes plus Mobo-specific placeholders.
	 *
	 * @param string   $template Template.
	 * @param WC_Order $order Order.
	 * @param string   $scenario Scenario.
	 * @return string|WP_Error
	 */
	private function render_template( $template, $order, $scenario, $client = null ) {
		$message = $template;
		$status  = method_exists( $order, 'get_status' ) ? $order->get_status() : 'created';

		if ( is_object( $client ) && method_exists( $client, 'replace_short_codes' ) ) {
			try {
				$message = $client->replace_short_codes( $message, $status, $order );
			} catch ( Throwable $e ) {
				return new WP_Error( 'mobo_core_sms_template_exception', 'Persian WooCommerce SMS shortcode rendering failed: ' . $e->getMessage() );
			}
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
	 * @param WC_Order|null $order Order.
	 * @param object|null   $client PWSMS helper.
	 * @return array
	 */
	private function normalize_recipients( $raw, $order, $client = null ) {
		$raw = str_ireplace(
			array( '{customer_mobile}', '{billing_phone}', '{phone}', '{mobile}' ),
			array( $this->get_order_billing_phone( $order ), $this->get_order_billing_phone( $order ), $this->get_order_billing_phone( $order ), $this->get_order_billing_phone( $order ) ),
			(string) $raw
		);

		$parts = preg_split( '/[\s,،;]+/u', $raw );
		$valid = array();

		foreach ( $parts as $part ) {
			$mobile = $this->normalize_mobile_number( (string) $part );
			if ( '' === $mobile ) {
				continue;
			}

			$is_valid = false;
			if ( is_object( $client ) && method_exists( $client, 'validate_mobile' ) ) {
				try {
					$validated = $client->validate_mobile( $mobile );
					$is_valid = ! is_wp_error( $validated ) && (bool) $validated;
				} catch ( Throwable $e ) {
					// A broken validator must not fatal the checkout/wallet hook. Fall back to a bounded format check.
					$is_valid = $this->is_plausible_mobile_number( $mobile );
				}
			} else {
				$is_valid = $this->is_plausible_mobile_number( $mobile );
			}

			if ( $is_valid ) {
				$valid[] = $mobile;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Resolve the Persian WooCommerce SMS helper without allowing third-party
	 * bootstrap exceptions to escape into checkout or the wallet-order hook.
	 *
	 * @return object|WP_Error
	 */
	private function get_pwsms_client() {
		if ( ! function_exists( 'PWSMS' ) ) {
			return new WP_Error( 'mobo_core_sms_pwsms_missing', 'Persian WooCommerce SMS is not active or PWSMS() is unavailable.' );
		}

		try {
			$client = PWSMS();
		} catch ( Throwable $e ) {
			return new WP_Error( 'mobo_core_sms_pwsms_bootstrap_exception', 'Persian WooCommerce SMS bootstrap failed: ' . $e->getMessage() );
		}

		if ( ! is_object( $client ) || ! method_exists( $client, 'send_sms' ) ) {
			return new WP_Error( 'mobo_core_sms_pwsms_missing', 'Persian WooCommerce SMS send_sms() API is unavailable.' );
		}

		return $client;
	}

	/**
	 * Dispatch through PWSMS with a stable error contract across gateways.
	 *
	 * @param object $client PWSMS helper.
	 * @param array  $recipients Normalized recipients.
	 * @param string $message Message/template payload.
	 * @param int    $post_id Order/post context.
	 * @param string $error_code WP_Error code on failure.
	 * @return true|WP_Error
	 */
	private function dispatch_sms( $client, $recipients, $message, $post_id, $error_code ) {
		try {
			$result = $client->send_sms(
				array(
					'type'    => 4,
					'post_id' => absint( $post_id ),
					'mobile'  => array_values( $recipients ),
					'message' => (string) $message,
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error( $error_code . '_exception', 'SMS gateway threw an exception: ' . $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $this->is_successful_send_result( $result ) ) {
			return true;
		}

		return new WP_Error( $error_code, $this->describe_send_result( $result ) );
	}

	/**
	 * Accommodate stable success shapes used by SMS helpers/gateway adapters.
	 * Do not treat arbitrary non-empty strings as success because many gateways
	 * return their error text as a string.
	 *
	 * @param mixed $result Raw send result.
	 * @return bool
	 */
	private function is_successful_send_result( $result ) {
		if ( true === $result || 1 === $result || '1' === $result ) {
			return true;
		}

		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && in_array( $result['success'], array( true, 1, '1' ), true ) ) {
				return true;
			}
			if ( isset( $result['status'] ) && in_array( strtolower( (string) $result['status'] ), array( 'success', 'sent', 'ok' ), true ) ) {
				return true;
			}
		}

		if ( is_object( $result ) ) {
			if ( isset( $result->success ) && in_array( $result->success, array( true, 1, '1' ), true ) ) {
				return true;
			}
			if ( isset( $result->status ) && in_array( strtolower( (string) $result->status ), array( 'success', 'sent', 'ok' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Produce an admin-safe bounded error description from a gateway result.
	 *
	 * @param mixed $result Raw send result.
	 * @return string
	 */
	private function describe_send_result( $result ) {
		if ( false === $result ) {
			return 'SMS gateway returned false.';
		}
		if ( null === $result ) {
			return 'SMS gateway returned null.';
		}

		if ( is_scalar( $result ) ) {
			$text = (string) $result;
		} else {
			$text = wp_json_encode( $result );
		}
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$text = 'SMS gateway returned an unrecognized failure result.';
		}

		$text = sanitize_text_field( $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
	}

	/**
	 * Normalize Persian/Arabic digits and common Iranian mobile prefixes.
	 *
	 * @param string $mobile Raw mobile.
	 * @return string
	 */
	private function normalize_mobile_number( $mobile ) {
		$mobile = strtr(
			trim( (string) $mobile ),
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
		$mobile = preg_replace( '/[^0-9+]/', '', $mobile );
		$mobile = is_string( $mobile ) ? $mobile : '';

		if ( 0 === strpos( $mobile, '0098' ) && 14 === strlen( $mobile ) ) {
			$mobile = '0' . substr( $mobile, 4 );
		} elseif ( 0 === strpos( $mobile, '+98' ) && 13 === strlen( $mobile ) ) {
			$mobile = '0' . substr( $mobile, 3 );
		} elseif ( 0 === strpos( $mobile, '98' ) && 12 === strlen( $mobile ) ) {
			$mobile = '0' . substr( $mobile, 2 );
		} elseif ( 10 === strlen( $mobile ) && '9' === substr( $mobile, 0, 1 ) ) {
			$mobile = '0' . $mobile;
		}

		return $mobile;
	}

	/**
	 * Bounded fallback when a third-party mobile validator is unavailable/broken.
	 *
	 * @param string $mobile Mobile.
	 * @return bool
	 */
	private function is_plausible_mobile_number( $mobile ) {
		return (bool) preg_match( '/^09[0-9]{9}$/', (string) $mobile );
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
