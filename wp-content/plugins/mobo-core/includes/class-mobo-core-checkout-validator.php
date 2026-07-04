<?php
/**
 * Checkout / pre-purchase validation.
 *
 * HPOS-safe: this class validates WooCommerce cart items only. It does not
 * read or write shop_order posts, order meta, or HPOS order tables.
 *
 * The external validation endpoint is intentionally configurable and optional.
 * If no endpoint is configured, validation remains local-only.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Checkout_Validator {

	/**
	 * Register validation hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_notices' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_errors' ), 20, 2 );
	}

	/**
	 * Validate cart and add WooCommerce notices.
	 *
	 * @return void
	 */
	public function validate_cart_notices() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$result = $this->validate_current_cart( $this->should_run_external_validation_now() );

		if ( ! empty( $result['success'] ) ) {
			return;
		}

		foreach ( $result['errors'] as $message ) {
			$message = sanitize_text_field( (string) $message );

			if ( '' === $message ) {
				continue;
			}

			if ( function_exists( 'wc_has_notice' ) && wc_has_notice( $message, 'error' ) ) {
				continue;
			}

			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Validate checkout and add errors to WooCommerce/WP_Error object.
	 *
	 * @param array    $data Checkout data.
	 * @param WP_Error $errors Error object.
	 * @return void
	 */
	public function validate_checkout_errors( $data, $errors ) {
		if ( ! $this->is_enabled() || ! ( $errors instanceof WP_Error ) ) {
			return;
		}

		$result = $this->validate_current_cart();

		if ( ! empty( $result['success'] ) ) {
			return;
		}

		foreach ( $result['errors'] as $message ) {
			$message = sanitize_text_field( (string) $message );

			if ( '' === $message ) {
				continue;
			}

			$errors->add( 'mobo_core_checkout_validation', $message );
		}
	}

	/**
	 * Return last validation status for admin UI / health.
	 *
	 * @return array
	 */
	public function get_last_status() {
		$result = get_option( 'mobo_core_checkout_last_validation_result', array() );

		if ( ! is_array( $result ) ) {
			$result = array();
		}

		return array(
			'enabled'       => $this->is_enabled(),
			'external'      => Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' ),
			'lastAttemptAt' => absint( get_option( 'mobo_core_checkout_last_validation_attempt_at', 0 ) ),
			'lastSuccessAt' => absint( get_option( 'mobo_core_checkout_last_validation_success_at', 0 ) ),
			'lastResult'    => $result,
		);
	}

	/**
	 * Validate the active cart.
	 *
	 * @return array
	 */
	public function validate_current_cart( $include_external = true ) {
		$errors = array();
		$items  = $this->build_cart_items_payload( $errors );

		if ( empty( $items ) && empty( $errors ) ) {
			return $this->result( true, array(), array( 'items' => array() ) );
		}

		$external_errors = $include_external ? $this->validate_external( $items ) : array();

		if ( ! empty( $external_errors ) ) {
			$errors = array_merge( $errors, $external_errors );
		}

		$errors = apply_filters( 'mobo_core_checkout_validation_errors', $errors, $items );

		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		$errors = array_values( array_filter( array_map( 'sanitize_text_field', $errors ) ) );

		return $this->result( empty( $errors ), $errors, array( 'items' => $items ) );
	}


	/**
	 * Avoid calling external validation on the normal cart page.
	 *
	 * Local checks may run on cart and checkout, but the external pre-purchase
	 * API is intended for checkout/payment flow.
	 *
	 * @return bool
	 */
	private function should_run_external_validation_now() {
		if ( function_exists( 'is_cart' ) && is_cart() && ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check plugin-level enable flag.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return Mobo_Core_Settings::enabled( 'mobo_core_checkout_validation_enabled', '0' );
	}

	/**
	 * Build sanitized cart item payload and local validation errors.
	 *
	 * @param array $errors Output errors.
	 * @return array
	 */
	private function build_cart_items_payload( &$errors ) {
		$errors = is_array( $errors ) ? $errors : array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$cart = WC()->cart->get_cart();

		if ( ! is_array( $cart ) || empty( $cart ) ) {
			return array();
		}

		$only_mobo_products = Mobo_Core_Settings::enabled( 'mobo_core_checkout_validate_only_mobo_products', '1' );
		$block_incomplete  = Mobo_Core_Settings::enabled( 'mobo_core_checkout_block_incomplete_sync', '1' );
		$require_guid      = Mobo_Core_Settings::enabled( 'mobo_core_checkout_require_remote_guid', '1' );
		$check_stock       = Mobo_Core_Settings::enabled( 'mobo_core_checkout_local_stock_check_enabled', '1' );

		$items = array();

		foreach ( $cart as $cart_key => $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( ! $product instanceof WC_Product ) {
				$errors[] = 'یکی از آیتم‌های سبد خرید معتبر نیست. لطفاً سبد خرید را بروزرسانی کنید.';
				continue;
			}

			$product_id   = absint( $product->get_id() );
			$variation_id = absint( isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0 );
			$parent_id    = absint( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
			$quantity     = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;

			if ( $parent_id <= 0 ) {
				$parent_id = $product_id;
			}

			$product_guid = $this->get_remote_product_guid( $parent_id, $product_id );
			$variant_guid = $variation_id > 0 ? sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) ) : '';
			$is_mobo_item = '' !== $product_guid || '' !== $variant_guid || '' !== sanitize_text_field( (string) get_post_meta( $parent_id, 'mobo_url', true ) );

			if ( $only_mobo_products && ! $is_mobo_item ) {
				continue;
			}

			$name = wp_strip_all_tags( $product->get_name() );

			if ( $require_guid && '' === $product_guid && '' === $variant_guid ) {
				$errors[] = sprintf( 'محصول «%s» شناسه همگام‌سازی معتبر ندارد.', $name );
			}

			if ( $block_incomplete && $this->is_sync_incomplete( $parent_id, $variation_id ) ) {
				$errors[] = sprintf( 'همگام‌سازی محصول «%s» هنوز کامل نشده است. چند دقیقه بعد دوباره تلاش کنید.', $name );
			}

			if ( $check_stock ) {
				if ( ! $product->is_purchasable() ) {
					$errors[] = sprintf( 'محصول «%s» در حال حاضر قابل خرید نیست.', $name );
				}

				if ( ! $product->is_in_stock() ) {
					$errors[] = sprintf( 'محصول «%s» در حال حاضر موجود نیست.', $name );
				} elseif ( $product->managing_stock() && method_exists( $product, 'has_enough_stock' ) && ! $product->has_enough_stock( $quantity ) ) {
					$errors[] = sprintf( 'موجودی محصول «%s» برای تعداد انتخاب‌شده کافی نیست.', $name );
				}
			}

			$items[] = array(
				'cartKey'          => sanitize_text_field( (string) $cart_key ),
				'productId'        => $parent_id,
				'variationId'      => $variation_id,
				'wcProductId'      => $product_id,
				'quantity'         => $quantity,
				'sku'              => sanitize_text_field( (string) $product->get_sku() ),
				'name'             => $name,
				'productGuid'      => $product_guid,
				'variantGuid'      => $variant_guid,
				'isMoboItem'       => $is_mobo_item,
				'price'            => wc_format_decimal( $product->get_price(), wc_get_price_decimals() ),
				'stockQuantity'    => null === $product->get_stock_quantity() ? null : (float) $product->get_stock_quantity(),
				'stockStatus'      => sanitize_key( (string) $product->get_stock_status() ),
				'syncIncomplete'   => $this->is_sync_incomplete( $parent_id, $variation_id ),
			);
		}

		return $items;
	}

	/**
	 * Optional external validation.
	 *
	 * @param array $items Cart item payload.
	 * @return array Errors.
	 */
	private function validate_external( $items ) {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' ) ) {
			return array();
		}

		$url = trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_validation_url', '' ) );
		$url = apply_filters( 'mobo_core_checkout_validation_external_url', $url, $items );

		if ( '' === $url ) {
			return array();
		}

		$payload = array(
			'siteUrl'   => home_url( '/' ),
			'cartHash'  => function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_hash() : '',
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'items'     => $items,
			'timestamp' => time(),
		);

		$payload = apply_filters( 'mobo_core_checkout_validation_payload', $payload, $items );

		if ( ! is_array( $payload ) ) {
			$payload = array( 'items' => $items );
		}

		$timeout = Mobo_Core_Settings::get_int( 'mobo_core_checkout_external_timeout_seconds', 3, 1, 10 );
		$body    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $body ) {
			return $this->external_error_result( 'خطا در آماده‌سازی اطلاعات اعتبارسنجی خرید.' );
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json; charset=utf-8',
		);

		$security_code = trim( (string) Mobo_Core_Settings::get( 'mobo_core_security_code', '' ) );
		if ( '' !== $security_code ) {
			$headers['X-SEC'] = $security_code;
		}

		$token = trim( (string) Mobo_Core_Settings::get( 'mobo_core_token', '' ) );
		if ( '' !== $token ) {
			$headers['Token'] = $token;
		}

		update_option( 'mobo_core_checkout_last_validation_attempt_at', time(), false );

		$response = wp_remote_post(
			esc_url_raw( $url ),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => false,
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			update_option(
				'mobo_core_checkout_last_validation_result',
				array(
					'success' => false,
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				),
				false
			);

			return $this->external_error_result( 'خطا در ارتباط با سرویس اعتبارسنجی خرید.' );
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			update_option(
				'mobo_core_checkout_last_validation_result',
				array(
					'success' => false,
					'status'  => $code,
					'message' => 'Invalid external validation response.',
				),
				false
			);

			return $this->external_error_result( 'پاسخ سرویس اعتبارسنجی خرید معتبر نیست.' );
		}

		$json = apply_filters( 'mobo_core_checkout_validation_external_response', $json, $items, $payload );

		if ( ! is_array( $json ) ) {
			$json = array();
		}

		$errors = $this->extract_external_errors( $json );

		update_option(
			'mobo_core_checkout_last_validation_result',
			array(
				'success' => empty( $errors ),
				'status'  => $code,
				'message' => isset( $json['message'] ) ? sanitize_text_field( (string) $json['message'] ) : '',
				'errors'  => $errors,
			),
			false
		);

		if ( empty( $errors ) ) {
			update_option( 'mobo_core_checkout_last_validation_success_at', time(), false );
		}

		return $errors;
	}

	/**
	 * External validation error behavior.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private function external_error_result( $message ) {
		$behavior = sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_external_error_behavior', 'allow' ) );

		if ( 'block' !== $behavior ) {
			return array();
		}

		return array( sanitize_text_field( (string) $message ) );
	}

	/**
	 * Parse external API response.
	 *
	 * Supported shapes:
	 * { "allow": true }
	 * { "success": true }
	 * { "allow": false, "message": "..." }
	 * { "items": [{ "allow": false, "message": "..." }] }
	 * { "errors": ["..."] }
	 *
	 * @param array $json Response.
	 * @return array
	 */
	private function extract_external_errors( $json ) {
		$errors = array();

		if ( isset( $json['errors'] ) && is_array( $json['errors'] ) ) {
			foreach ( $json['errors'] as $error ) {
				if ( is_string( $error ) && '' !== trim( $error ) ) {
					$errors[] = $error;
				} elseif ( is_array( $error ) && ! empty( $error['message'] ) ) {
					$errors[] = $error['message'];
				}
			}
		}

		$allow = null;

		if ( array_key_exists( 'allow', $json ) ) {
			$allow = $this->to_bool( $json['allow'] );
		} elseif ( array_key_exists( 'success', $json ) ) {
			$allow = $this->to_bool( $json['success'] );
		}

		if ( false === $allow ) {
			$errors[] = ! empty( $json['message'] ) ? $json['message'] : 'امکان ثبت سفارش برای یک یا چند محصول وجود ندارد.';
		}

		if ( isset( $json['items'] ) && is_array( $json['items'] ) ) {
			foreach ( $json['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( array_key_exists( 'allow', $item ) && ! $this->to_bool( $item['allow'] ) ) {
					$errors[] = ! empty( $item['message'] ) ? $item['message'] : 'یکی از محصولات سبد خرید قابل ثبت نیست.';
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $errors ) ) ) );
	}

	/**
	 * Get parent product GUID with fallback.
	 *
	 * @param int $parent_id Parent product ID.
	 * @param int $product_id Actual WC product ID.
	 * @return string
	 */
	private function get_remote_product_guid( $parent_id, $product_id ) {
		$parent_id  = absint( $parent_id );
		$product_id = absint( $product_id );

		$guid = $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) ) : '';

		if ( '' === $guid && $product_id > 0 ) {
			$guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
		}

		return $guid;
	}

	/**
	 * Check incomplete sync meta.
	 *
	 * @param int $parent_id Parent ID.
	 * @param int $variation_id Variation ID.
	 * @return bool
	 */
	private function is_sync_incomplete( $parent_id, $variation_id ) {
		$parent_id    = absint( $parent_id );
		$variation_id = absint( $variation_id );

		if ( $parent_id > 0 && '1' === (string) get_post_meta( $parent_id, 'mobo_sync_incomplete', true ) ) {
			return true;
		}

		if ( $variation_id > 0 && '1' === (string) get_post_meta( $variation_id, 'mobo_sync_incomplete', true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build standard result.
	 *
	 * @param bool  $success Success.
	 * @param array $errors Errors.
	 * @param array $data Extra data.
	 * @return array
	 */
	private function result( $success, $errors, $data = array() ) {
		return array(
			'success' => (bool) $success,
			'errors'  => is_array( $errors ) ? $errors : array(),
			'data'    => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Convert value to bool.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on', 'allow', 'allowed' ), true );
		}

		return ! empty( $value );
	}
}
