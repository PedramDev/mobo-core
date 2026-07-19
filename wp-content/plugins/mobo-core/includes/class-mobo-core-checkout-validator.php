<?php
/**
 * Checkout / pre-purchase validation.
 *
 * HPOS-safe: this class validates WooCommerce cart items only. It does not
 * writes WooCommerce order meta only via WC_Order CRUD APIs.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Checkout_Validator {

	/**
	 * Debug request id, shared by all events generated during the same PHP request.
	 *
	 * @var string|null
	 */
	private $debug_request_id = null;

	/**
	 * Register validation hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_notices' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_errors' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 20, 4 );
		add_action( 'mobo_core_process_queued_mobo_orders', array( $this, 'handle_scheduled_queued_order_submissions' ), 10, 0 );
		add_action( 'mobo_core_queue_mobo_order_submission', array( $this, 'handle_scheduled_order_queue' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_post_mobo_core_retry_mobo_order_submission', array( $this, 'handle_admin_retry_mobo_order_submission' ) );
			add_action( 'admin_post_mobo_core_clear_mobo_order_log', array( $this, 'handle_admin_clear_mobo_order_log' ) );
			add_action( 'add_meta_boxes', array( $this, 'register_order_meta_box' ), 20 );
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_legacy_order_column' ), 30 );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_order_column' ), 30, 2 );
			add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_hpos_order_column' ), 30 );
			add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_hpos_order_column' ), 30, 2 );
		}
		/*
		 * Single shared Mobo cart mode: do not mirror live WooCommerce cart
		 * add/update/delete operations to Mobo. The shared Mobo cart is rebuilt
		 * only during checkout validation and automatic order submission.
		 */
	}

	/**
	 * Validate cart and add WooCommerce notices.
	 *
	 * @return void
	 */
	public function validate_cart_notices() {
		if ( ! $this->has_active_checkout_validation_checks() ) {
			return;
		}

		/*
		 * Keep Mobo cart notices out of checkout rendering and checkout Ajax.
		 * WooCommerce persists notices in the customer session; an error notice
		 * created on the initial checkout page load can still be present when
		 * update_order_review calculates shipping rates. Some shipping methods then
		 * return no rates even though their zone and method are configured correctly.
		 *
		 * Therefore, cart-page notices are allowed only on the cart page. Checkout
		 * blocking remains in woocommerce_after_checkout_validation when the customer
		 * actually submits the order.
		 */
		if ( ! ( function_exists( 'is_cart' ) && is_cart() ) || $this->is_checkout_order_review_ajax_request() ) {
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
		if ( ! $this->has_active_checkout_validation_checks() || ! ( $errors instanceof WP_Error ) ) {
			return;
		}


		$result = $this->validate_current_cart( true );

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

		$master_enabled = $this->is_enabled();
		$mobo_cart_raw = $this->is_mobo_cart_validation_enabled();
		$auto_order_enabled = Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
		$local_stock_raw = Mobo_Core_Settings::enabled( 'mobo_core_checkout_local_stock_check_enabled', '0' );
		$external_raw    = Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' );

		$mobo_cart_enabled   = $auto_order_enabled || ( $master_enabled && $mobo_cart_raw );
		$local_stock_enabled = $master_enabled && $local_stock_raw;
		$external_enabled    = $master_enabled && $external_raw;
		$runtime_enabled     = $auto_order_enabled || $mobo_cart_enabled || $local_stock_enabled || $external_enabled;

		if ( ! $mobo_cart_enabled && ! $auto_order_enabled ) {
			delete_option( 'mobo_core_shared_mobo_cart_lock' );
		}

		return array(
			'enabled'           => $master_enabled,
			'runtimeEnabled'    => $runtime_enabled,
			'localStockEnabled' => $local_stock_enabled,
			'moboCartEnabled'   => $mobo_cart_enabled,
			'moboCartForcedByAutoOrder' => $auto_order_enabled,
			'autoOrderEnabled'  => $auto_order_enabled,
			'external'          => $external_enabled,
			'rawLocalStockEnabled' => $local_stock_raw,
			'rawMoboCartEnabled'   => $mobo_cart_raw,
			'rawExternalEnabled'   => $external_raw,
			'lastAttemptAt'    => absint( get_option( 'mobo_core_checkout_last_validation_attempt_at', 0 ) ),
			'lastSuccessAt'    => absint( get_option( 'mobo_core_checkout_last_validation_success_at', 0 ) ),
			'lastMoboLoginAt'  => absint( get_option( 'mobo_core_checkout_mobo_login_success_at', 0 ) ),
			'lastMoboCartAt'   => absint( get_option( 'mobo_core_checkout_mobo_cart_success_at', 0 ) ),
			'lastResult'       => $result,
		);
	}

	/**
	 * Test Mobo login from admin settings.
	 *
	 * @return true|WP_Error
	 */
	public function test_mobo_login() {
		$this->clear_mobo_cookie_jar();

		$result = $this->ensure_mobo_authenticated( true );

		update_option( 'mobo_core_checkout_mobo_login_test_at', time(), false );

		if ( is_wp_error( $result ) ) {
			update_option( 'mobo_core_checkout_mobo_login_test_result', 'ناموفق', false );
			update_option( 'mobo_core_checkout_mobo_login_test_error', $result->get_error_message(), false );

			return $result;
		}

		update_option( 'mobo_core_checkout_mobo_login_test_result', 'موفق', false );
		delete_option( 'mobo_core_checkout_mobo_login_test_error' );

		return true;
	}

	/**
	 * Validate the active cart.
	 *
	 * @param bool $include_external Include external/API validation.
	 * @return array
	 */
	public function validate_current_cart( $include_external = true ) {
		$errors = array();
		$items  = $this->build_cart_items_payload( $errors );

		if ( empty( $items ) && empty( $errors ) ) {
			return $this->result( true, array(), array( 'items' => array() ) );
		}

		if ( $include_external && $this->is_mobo_cart_validation_effective() ) {
			$mobo_errors = $this->validate_mobo_cart_api( $items );

			if ( ! empty( $mobo_errors ) ) {
				$errors = array_merge( $errors, $mobo_errors );
			}
		}

		$external_errors = ( $include_external && $this->is_external_validation_effective() ) ? $this->validate_external( $items ) : array();

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
	 * Decide whether the Mobo cart API should run for the current request.
	 *
	 * In single shared Mobo cart mode, live WooCommerce cart actions must not write
	 * to Mobo. The shared remote cart is touched only during checkout validation
	 * and later during automatic order submission.
	 *
	 * @return bool
	 */
	private function should_run_external_validation_now() {
		/*
		 * Cart/checkout page notices should remain local-only. The shared Mobo cart
		 * is rebuilt only from woocommerce_after_checkout_validation, i.e. when the
		 * customer actually submits checkout.
		 */
		return false;
	}

	/**
	 * Detect WooCommerce Ajax order-review refresh.
	 *
	 * This request recalculates totals and shipping methods while the customer is
	 * editing checkout fields. Mobo validation must not add cart error notices in
	 * this pass; otherwise shipping plugins may return no rates even though their
	 * zone and method are configured correctly.
	 *
	 * @return bool
	 */
	private function is_checkout_order_review_ajax_request() {
		$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		$wc_ajax = '';
		// Read-only request routing inspection; WooCommerce validates action-specific nonces.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if ( isset( $_GET['wc-ajax'] ) ) {
			$wc_ajax = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) );
		} elseif ( isset( $_POST['wc-ajax'] ) ) {
			$wc_ajax = sanitize_key( wp_unslash( $_POST['wc-ajax'] ) );
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		return ( $is_ajax && 'update_order_review' === $wc_ajax ) || 'woocommerce_update_order_review' === $action;
	}

	/**
	 * Detect WooCommerce cart quantity/update submissions.
	 *
	 * @return bool
	 */
	private function is_woocommerce_cart_update_request() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			return false;
		}

		$keys = array( 'update_cart', 'woocommerce-cart-nonce', '_wpnonce' );

		// Presence check only; no request value is trusted or persisted here.
		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return true;
			}
		}

		return false;
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
	 * Dedicated Mobo cart API validation flag.
	 *
	 * @return bool
	 */
	private function is_mobo_cart_validation_enabled() {
		return Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_cart_validation_enabled', '0' );
	}

	/**
	 * Master switch aware local stock flag.
	 *
	 * @return bool
	 */
	private function is_local_stock_check_effective() {
		return $this->is_enabled() && Mobo_Core_Settings::enabled( 'mobo_core_checkout_local_stock_check_enabled', '0' );
	}

	/**
	 * Master switch aware Mobo cart validation flag.
	 *
	 * @return bool
	 */
	private function is_mobo_cart_validation_effective() {
		/*
		 * Automatic order submission may only run after the exact portal_variant_id
		 * can be added to the authenticated Mobo cart. This preflight is therefore
		 * mandatory while auto-order is enabled, even when the optional checkout
		 * validation master/toggle is off.
		 */
		return $this->is_order_submission_enabled() || ( $this->is_enabled() && $this->is_mobo_cart_validation_enabled() );
	}

	/**
	 * Master switch aware external validation flag.
	 *
	 * @return bool
	 */
	private function is_external_validation_effective() {
		return $this->is_enabled() && Mobo_Core_Settings::enabled( 'mobo_core_checkout_external_validation_enabled', '0' );
	}

	/**
	 * Whether any pre-purchase validation should run on cart/checkout.
	 *
	 * The master switch alone must not change checkout behavior. At least one
	 * concrete validation mode must also be enabled.
	 *
	 * @return bool
	 */
	private function has_active_checkout_validation_checks() {
		return $this->is_local_stock_check_effective()
			|| $this->is_mobo_cart_validation_effective()
			|| $this->is_external_validation_effective();
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

		/*
		 * These rules are intentionally hard-forced for Mobo checkout safety.
		 * They are not optional UI settings anymore.
		 */
		$only_mobo_products = true;
		$block_incomplete   = true;
		$require_guid       = true;
		$check_stock        = $this->is_local_stock_check_effective() && ! $this->is_mobo_cart_validation_effective();

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

			$product_guid      = $this->get_remote_product_guid( $parent_id, $product_id );
			$variant_guid      = $variation_id > 0 ? sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) ) : '';
			$portal_product_id = $this->get_portal_product_id( $parent_id, $product_id );
			$portal_variant_id = $this->get_portal_variant_id( $variation_id, $product_id );
			$is_mobo_item      = '' !== $product_guid || '' !== $variant_guid || $portal_product_id > 0 || $portal_variant_id > 0 || '' !== sanitize_text_field( (string) get_post_meta( $parent_id, 'mobo_url', true ) );

			if ( $only_mobo_products && ! $is_mobo_item ) {
				continue;
			}

			$name = wp_strip_all_tags( $product->get_name() );

			if ( $require_guid && '' === $product_guid ) {
				$errors[] = sprintf( 'محصول «%s» شناسه product_guid معتبر ندارد.', $name );
			}

			if ( $require_guid && $variation_id > 0 && '' === $variant_guid ) {
				$errors[] = sprintf( 'تنوع انتخاب‌شده برای محصول «%s» شناسه variant_guid معتبر ندارد.', $name );
			}

			if ( $portal_variant_id <= 0 ) {
				$errors[] = $variation_id > 0
					? sprintf( 'تنوع انتخاب‌شده برای محصول «%s» شناسه portal_variant_id معتبر ندارد.', $name )
					: sprintf( 'محصول ساده «%s» شناسه قابل خرید موبو (portal_variant_id) ندارد؛ محصول را دوباره همگام‌سازی کنید.', $name );
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
				'portalProductId'  => $portal_product_id,
				'portalVariantId'  => $portal_variant_id,
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
	 * Validate cart items against the Mobo storefront cart API.
	 *
	 * Single shared Mobo cart mode:
	 * - Do not mirror live WooCommerce cart changes.
	 * - During checkout validation, acquire a global lock.
	 * - Clear the one shared Mobo cart.
	 * - Rebuild it from the current WooCommerce cart.
	 * - Fetch a snapshot and compare variant/quantity.
	 *
	 * @param array $items Cart items.
	 * @return array Error messages.
	 */
	private function validate_mobo_cart_api( $items ) {
		$errors = array();

		if ( empty( $items ) ) {
			return $errors;
		}

		update_option( 'mobo_core_checkout_last_validation_attempt_at', time(), false );
		$this->debug_log( 'shared_cart_validation_start', array( 'itemCount' => count( $items ), 'cartUpdate' => $this->is_woocommerce_cart_update_request() ) );

		$lock = $this->acquire_mobo_cart_lock( 'checkout_validation' );

		if ( is_wp_error( $lock ) ) {
			$this->debug_log( 'shared_cart_lock_failed', array( 'error' => $lock->get_error_message() ) );
			$this->store_mobo_validation_result( false, 0, 'cart_lock_failed', $lock->get_error_message(), array() );
			return array( 'در حال حاضر بررسی موجودی توسط سفارش دیگری در حال انجام است. چند لحظه بعد دوباره تلاش کنید.' );
		}

		$results = array();

		try {
			$auth = $this->ensure_mobo_authenticated( false );

			if ( is_wp_error( $auth ) ) {
				$this->debug_log( 'shared_cart_login_failed', array( 'error' => $auth->get_error_message() ) );
				$this->store_mobo_validation_result( false, 0, 'login_failed', $auth->get_error_message(), array() );
				return array( 'ارتباط با سرویس بررسی موجودی موبو برقرار نشد. لطفاً چند دقیقه بعد دوباره تلاش کنید.' );
			}

			$clear = $this->clear_shared_mobo_cart();

			if ( is_wp_error( $clear ) ) {
				$this->debug_log( 'shared_cart_clear_failed', array( 'error' => $clear->get_error_message() ) );
				$this->store_mobo_validation_result( false, 0, 'cart_clear_failed', $clear->get_error_message(), array() );
				return array( 'آماده‌سازی سبد موبو برای بررسی سفارش انجام نشد. چند دقیقه بعد دوباره تلاش کنید.' );
			}

			foreach ( $items as $item ) {
				if ( empty( $item['isMoboItem'] ) ) {
					continue;
				}

				$portal_variant_id = isset( $item['portalVariantId'] ) ? absint( $item['portalVariantId'] ) : 0;
				$name              = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : 'محصول';
				$quantity          = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;

				if ( $portal_variant_id <= 0 ) {
					$errors[] = sprintf( 'برای محصول «%s» شناسه portal_variant_id معتبر ثبت نشده است.', $name );
					continue;
				}

				$this->debug_log( 'shared_cart_add_item_start', array( 'portalVariantId' => $portal_variant_id, 'quantity' => $quantity ) );
				$response = $this->add_mobo_cart_item_by_variant( $portal_variant_id, $quantity );

				if ( $this->is_auth_error_response( $response ) ) {
					$auth = $this->ensure_mobo_authenticated( true );

					if ( is_wp_error( $auth ) ) {
						$this->store_mobo_validation_result( false, 0, 'login_failed', $auth->get_error_message(), $results );
						$errors[] = 'ورود به سرویس موبو برای بررسی موجودی انجام نشد.';
						break;
					}

					$response = $this->add_mobo_cart_item_by_variant( $portal_variant_id, $quantity );
				}

				$code      = is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) );
				$add_check = $this->validate_mobo_cart_add_response( $response, $portal_variant_id, $name );
				$results[] = array(
					'portalVariantId' => $portal_variant_id,
					'quantity'        => $quantity,
					'status'          => $code,
					'error'           => is_wp_error( $add_check ) ? $add_check->get_error_message() : '',
				);

				$this->debug_log( 'shared_cart_add_item_result', array(
					'portalVariantId' => $portal_variant_id,
					'quantity'        => $quantity,
					'httpStatus'      => $code,
					'error'           => is_wp_error( $add_check ) ? $add_check->get_error_message() : '',
				) );

				if ( is_wp_error( $add_check ) ) {
					$errors[] = sprintf( 'امکان ثبت سفارش برای محصول «%s» وجود ندارد: %s', $name, $add_check->get_error_message() );
				}
			}

			if ( empty( $errors ) ) {
				/* update=true is the authoritative storefront refresh used before checkout. */
				$snapshot = $this->get_mobo_cart_snapshot_json( true );

				if ( is_wp_error( $snapshot ) ) {
					$this->debug_log( 'shared_cart_snapshot_failed', array( 'error' => $snapshot->get_error_message() ) );
					$errors[] = 'خواندن snapshot سبد موبو بعد از آماده‌سازی انجام نشد.';
				} else {
					$compare_errors = $this->compare_mobo_snapshot_with_items( $snapshot, $items );

					if ( ! empty( $compare_errors ) ) {
						$errors = array_merge( $errors, $compare_errors );
					}
				}
			}

			$this->store_mobo_validation_result( empty( $errors ), empty( $errors ) ? 200 : 0, 'shared_cart_validation', empty( $errors ) ? 'OK' : 'Shared Mobo cart validation failed.', $results );
			$this->debug_log( 'shared_cart_validation_finish', array( 'success' => empty( $errors ), 'errorCount' => count( $errors ), 'resultCount' => count( $results ) ) );

			if ( empty( $errors ) ) {
				update_option( 'mobo_core_checkout_last_validation_success_at', time(), false );
				update_option( 'mobo_core_checkout_mobo_cart_success_at', time(), false );
			}

			return $errors;
		} finally {
			$this->release_mobo_cart_lock( $lock );
		}
	}

	/**
	 * Mirror WooCommerce cart item removal to the Mobo storefront cart.
	 *
	 * WooCommerce removes the local cart row first and keeps the removed row in
	 * WC_Cart::$removed_cart_contents. Mobo requires DELETE /site/api/v1/cart/{cart_item_id},
	 * so the cart item ID is resolved from the latest Mobo snapshot by matching
	 * items[].product.variant.id with the saved portal_variant_id.
	 *
	 * @param string  $cart_item_key WooCommerce cart item key.
	 * @param WC_Cart $cart WooCommerce cart object.
	 * @return void
	 */
	public function handle_wc_cart_item_removed( $cart_item_key, $cart ) {
		/* Single shared Mobo cart mode: live WooCommerce removals must not touch Mobo. */
		return;

		if ( ! $this->is_mobo_cart_validation_effective() ) {
			return;
		}

		$cart_item = null;

		if ( is_object( $cart ) && isset( $cart->removed_cart_contents ) && is_array( $cart->removed_cart_contents ) && isset( $cart->removed_cart_contents[ $cart_item_key ] ) ) {
			$cart_item = $cart->removed_cart_contents[ $cart_item_key ];
		}

		if ( ! is_array( $cart_item ) ) {
			return;
		}

		$variation_id = absint( isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0 );
		$product_id   = absint( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
		$product      = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$wc_id        = $product instanceof WC_Product ? absint( $product->get_id() ) : $product_id;
		$name         = $product instanceof WC_Product ? wp_strip_all_tags( $product->get_name() ) : 'محصول';

		$portal_variant_id = $this->get_portal_variant_id( $variation_id, $wc_id );

		if ( $portal_variant_id <= 0 ) {
			return;
		}

		$this->debug_log( 'wc_remove_item', array( 'portalVariantId' => $portal_variant_id, 'cartKey' => sanitize_text_field( (string) $cart_item_key ) ) );

		$result = $this->delete_mobo_cart_item_for_variant( $portal_variant_id );

		if ( true === $result ) {
			$this->debug_log( 'wc_remove_item_success', array( 'portalVariantId' => $portal_variant_id ) );
			update_option( 'mobo_core_checkout_mobo_cart_delete_success_at', time(), false );
			update_option(
				'mobo_core_checkout_mobo_cart_delete_last_result',
				array(
					'success'         => true,
					'portalVariantId' => $portal_variant_id,
					'timestamp'       => time(),
				),
				false
			);
			return;
		}

		$message = is_wp_error( $result ) ? $result->get_error_message() : 'خطای نامشخص در حذف آیتم از سبد موبو.';
		$this->debug_log( 'wc_remove_item_failed', array( 'portalVariantId' => $portal_variant_id, 'error' => $message ) );

		update_option(
			'mobo_core_checkout_mobo_cart_delete_last_result',
			array(
				'success'         => false,
				'portalVariantId' => $portal_variant_id,
				'error'           => sanitize_text_field( $message ),
				'timestamp'       => time(),
			),
			false
		);

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( sprintf( 'حذف محصول «%s» از سبد موبو انجام نشد. لطفاً سبد خرید را دوباره بروزرسانی کنید.', sanitize_text_field( $name ) ), 'error' );
		}
	}

	/**
	 * Delete a Mobo cart row for a MoboCore variant ID.
	 *
	 * @param int $portal_variant_id MoboCore variant ID.
	 * @return true|WP_Error
	 */
	private function delete_mobo_cart_item_for_variant( $portal_variant_id ) {
		$portal_variant_id = absint( $portal_variant_id );

		if ( $portal_variant_id <= 0 ) {
			return new WP_Error( 'mobo_core_invalid_portal_variant_id', 'Invalid portal_variant_id.' );
		}

		$auth = $this->ensure_mobo_authenticated( false );

		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$cart_item_id = $this->get_mobo_cart_item_id_for_variant( $portal_variant_id );
		$this->debug_log( 'delete_resolve_start', array( 'portalVariantId' => $portal_variant_id, 'cartItemId' => $cart_item_id ) );

		if ( $cart_item_id <= 0 ) {
			$snapshot = $this->refresh_mobo_cart_snapshot();

			if ( $this->is_auth_error_response( $snapshot ) ) {
				$auth = $this->ensure_mobo_authenticated( true );

				if ( is_wp_error( $auth ) ) {
					return $auth;
				}

				$snapshot = $this->refresh_mobo_cart_snapshot();
			}

			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$cart_item_id = $this->get_mobo_cart_item_id_for_variant( $portal_variant_id );
		}

		/*
		 * If the item is already absent from the Mobo cart, local removal is complete.
		 */
		if ( $cart_item_id <= 0 ) {
			$this->debug_log( 'delete_item_absent', array( 'portalVariantId' => $portal_variant_id ) );
			$this->remove_mobo_cart_item_id_for_variant( $portal_variant_id );
			return true;
		}

		$this->debug_log( 'delete_request', array( 'portalVariantId' => $portal_variant_id, 'cartItemId' => $cart_item_id ) );

		$response = $this->mobo_request(
			'DELETE',
			'/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ),
			null
		);

		if ( $this->is_auth_error_response( $response ) ) {
			$auth = $this->ensure_mobo_authenticated( true );

			if ( is_wp_error( $auth ) ) {
				return $auth;
			}

			$response = $this->mobo_request(
				'DELETE',
				'/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ),
				null
			);
		}

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'delete_wp_error', array( 'portalVariantId' => $portal_variant_id, 'cartItemId' => $cart_item_id, 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			$this->debug_log( 'delete_http_error', array( 'portalVariantId' => $portal_variant_id, 'cartItemId' => $cart_item_id, 'httpStatus' => $code ) );
			return new WP_Error( 'mobo_core_mobo_cart_delete_http_error', 'Mobo cart delete failed with HTTP status ' . $code );
		}

		$this->debug_log( 'delete_success', array( 'portalVariantId' => $portal_variant_id, 'cartItemId' => $cart_item_id, 'httpStatus' => $code ) );
		$this->remove_mobo_cart_item_id_for_variant( $portal_variant_id );
		$snapshot = $this->refresh_mobo_cart_snapshot();

		if ( $this->is_auth_error_response( $snapshot ) || is_wp_error( $snapshot ) ) {
			/* Deletion itself succeeded; snapshot refresh failure should not undo it. */
			return true;
		}

		return true;
	}

	/**
	 * Acquire a global lock for the one shared Mobo cart.
	 *
	 * @param string $purpose Lock purpose.
	 * @return string|WP_Error Lock token or error.
	 */
	private function acquire_mobo_cart_lock( $purpose = 'checkout' ) {
		$key        = 'mobo_core_shared_mobo_cart_lock';
		$token      = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mobo_lock_', true );
		$started_at = time();
		$wait       = Mobo_Core_Settings::get_int( 'mobo_core_checkout_mobo_cart_lock_wait_seconds', 15, 0, 45 );
		$ttl        = Mobo_Core_Settings::get_int( 'mobo_core_checkout_mobo_cart_lock_ttl_seconds', 60, 15, 300 );

		do {
			$now  = time();
			$lock = get_option( $key, array() );

			if ( is_array( $lock ) && ! empty( $lock['expiresAt'] ) && absint( $lock['expiresAt'] ) <= $now ) {
				delete_option( $key );
				$lock = array();
			}

			if ( ! is_array( $lock ) || empty( $lock ) ) {
				$value = array(
					'token'     => $token,
					'purpose'   => sanitize_key( (string) $purpose ),
					'createdAt' => $now,
					'expiresAt' => $now + $ttl,
					'session'   => $this->get_debug_session_id(),
				);

				if ( add_option( $key, $value, '', 'no' ) ) {
					$this->debug_log( 'shared_cart_lock_acquired', array( 'purpose' => $purpose, 'ttl' => $ttl, 'waited' => $now - $started_at ) );
					return $token;
				}
			}

			if ( ( time() - $started_at ) >= $wait ) {
				break;
			}

			usleep( 250000 );
		} while ( true );

		return new WP_Error( 'mobo_core_shared_cart_locked', 'Shared Mobo cart is locked by another checkout.' );
	}

	/**
	 * Release the global shared Mobo cart lock.
	 *
	 * @param string $token Lock token.
	 * @return void
	 */
	private function release_mobo_cart_lock( $token ) {
		$key  = 'mobo_core_shared_mobo_cart_lock';
		$lock = get_option( $key, array() );

		if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
			delete_option( $key );
			$this->debug_log( 'shared_cart_lock_released', array() );
		}
	}

	/**
	 * Clear all rows from the one shared Mobo cart.
	 *
	 * @return true|WP_Error
	 */
	private function clear_shared_mobo_cart() {
		$this->debug_log( 'shared_cart_clear_start', array() );
		$snapshot = $this->get_mobo_cart_snapshot_json();

		if ( $this->is_auth_error_response( $snapshot ) ) {
			$auth = $this->ensure_mobo_authenticated( true );

			if ( is_wp_error( $auth ) ) {
				return $auth;
			}

			$snapshot = $this->get_mobo_cart_snapshot_json();
		}

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$items = $this->parse_mobo_snapshot_items( $snapshot );

		foreach ( $items as $item ) {
			$cart_item_id = isset( $item['cartItemId'] ) ? absint( $item['cartItemId'] ) : 0;

			if ( $cart_item_id <= 0 ) {
				continue;
			}

			$this->debug_log( 'shared_cart_delete_existing', array( 'cartItemId' => $cart_item_id, 'portalVariantId' => isset( $item['portalVariantId'] ) ? absint( $item['portalVariantId'] ) : 0 ) );
			$response = $this->mobo_request( 'DELETE', '/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ), null );

			if ( $this->is_auth_error_response( $response ) ) {
				$auth = $this->ensure_mobo_authenticated( true );

				if ( is_wp_error( $auth ) ) {
					return $auth;
				}

				$response = $this->mobo_request( 'DELETE', '/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ), null );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = absint( wp_remote_retrieve_response_code( $response ) );

			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'mobo_core_shared_cart_delete_failed', 'Mobo cart clear failed with HTTP status ' . $code );
			}
		}

		$this->set_mobo_cart_item_map( array() );
		$this->debug_log( 'shared_cart_clear_finish', array( 'deletedCount' => count( $items ) ) );

		return true;
	}

	/**
	 * Add one MoboCore variant to the shared Mobo cart.
	 *
	 * @param int   $portal_variant_id MoboCore variant ID.
	 * @param float $quantity Quantity.
	 * @return array|WP_Error
	 */
	private function add_mobo_cart_item_by_variant( $portal_variant_id, $quantity ) {
		$portal_variant_id = absint( $portal_variant_id );
		$quantity          = max( 0, (float) $quantity );

		if ( $portal_variant_id <= 0 ) {
			return new WP_Error( 'mobo_core_invalid_portal_variant_id', 'Invalid portal_variant_id.' );
		}

		return $this->mobo_request(
			'POST',
			'/site/api/v1/cart',
			array(
				'quantity'   => $quantity,
				'variant_id' => $portal_variant_id,
			)
		);
	}

	/**
	 * Fetch and decode the shared Mobo cart snapshot.
	 *
	 * @return array|WP_Error
	 */

	/**
	 * Validate the semantic result of POST /cart, not only HTTP 200.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param int            $portal_variant_id Requested variant.
	 * @param string         $name Product name.
	 * @return true|WP_Error
	 */
	private function validate_mobo_cart_add_response( $response, $portal_variant_id, $name = '' ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$name = sanitize_text_field( (string) $name );

		if ( 400 === $code ) {
			return new WP_Error(
				'mobo_core_mobo_cart_item_unavailable',
				sprintf( 'آیتم «%s» در سایت ناموجود است.', '' !== $name ? $name : (string) absint( $portal_variant_id ) )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'mobo_core_mobo_cart_add_http_failed',
				sprintf( 'آیتم «%s» در سبد موبو ثبت نشد. HTTP %d', '' !== $name ? $name : (string) absint( $portal_variant_id ), $code )
			);
		}

		$raw = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( '' === $raw ) {
			return true;
		}

		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_add_invalid_json', 'پاسخ افزودن محصول به سبد موبو JSON معتبر نبود.' );
		}

		if ( array_key_exists( 'success', $json ) && ! $this->to_bool( $json['success'] ) ) {
			$message = $this->first_non_empty_scalar(
				array(
					isset( $json['description'] ) ? $json['description'] : '',
					isset( $json['message'] ) ? $json['message'] : '',
					'موبو افزودن محصول به سبد را رد کرد.',
				)
			);
			return new WP_Error( 'mobo_core_mobo_cart_add_rejected', sanitize_text_field( $message ) );
		}

		$returned_variant_id = 0;
		if ( isset( $json['product']['variant']['id'] ) ) {
			$returned_variant_id = absint( $json['product']['variant']['id'] );
		}

		if ( $returned_variant_id > 0 && $returned_variant_id !== absint( $portal_variant_id ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_add_variant_mismatch', 'شناسه Variant برگشتی موبو با محصول درخواستی برابر نیست.' );
		}

		return true;
	}

	private function first_non_empty_scalar( $values ) {
		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	private function get_mobo_cart_snapshot_json( $update = false ) {
		$response = $this->refresh_mobo_cart_snapshot( $update );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $this->is_auth_error_response( $response ) ) {
			return $response;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_json_error', 'Mobo cart snapshot response was not valid JSON.' );
		}

		return $json;
	}

	/**
	 * Parse Mobo cart snapshot items to a compact structure.
	 *
	 * @param array $json Snapshot JSON.
	 * @return array
	 */
	private function parse_mobo_snapshot_items( $json ) {
		$out   = array();
		$items = array();

		if ( isset( $json['cart']['items'] ) && is_array( $json['cart']['items'] ) ) {
			$items = $json['cart']['items'];
		}

		foreach ( $items as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$cart_item_id = isset( $cart_item['id'] ) ? absint( $cart_item['id'] ) : 0;
			$variant_id   = 0;
			$status       = array();
			$variant      = array();

			if ( isset( $cart_item['product'] ) && is_array( $cart_item['product'] ) && isset( $cart_item['product']['variant'] ) && is_array( $cart_item['product']['variant'] ) ) {
				$variant = $cart_item['product']['variant'];
				$variant_id = isset( $variant['id'] ) ? absint( $variant['id'] ) : 0;
				$status = isset( $variant['status'] ) && is_array( $variant['status'] ) ? array_map( 'sanitize_key', $variant['status'] ) : array();
			}

			$out[] = array(
				'cartItemId'      => $cart_item_id,
				'portalVariantId' => $variant_id,
				'quantity'        => isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0,
				'min'             => isset( $variant['min'] ) ? (float) $variant['min'] : null,
				'max'             => isset( $variant['max'] ) ? (float) $variant['max'] : null,
				'status'          => $status,
			);
		}

		return $out;
	}

	/**
	 * Compare the rebuilt Mobo cart snapshot against WooCommerce cart items.
	 *
	 * @param array $snapshot Snapshot JSON.
	 * @param array $items Woo items.
	 * @return array Error messages.
	 */
	private function compare_mobo_snapshot_with_items( $snapshot, $items ) {
		$errors   = array();
		$remote   = array();
		$parsed   = $this->parse_mobo_snapshot_items( $snapshot );
		$expected = array();

		foreach ( $parsed as $row ) {
			$variant_id = isset( $row['portalVariantId'] ) ? absint( $row['portalVariantId'] ) : 0;

			if ( $variant_id > 0 ) {
				$remote[ (string) $variant_id ] = $row;
			}
		}

		foreach ( $items as $item ) {
			if ( empty( $item['isMoboItem'] ) ) {
				continue;
			}

			$portal_variant_id = isset( $item['portalVariantId'] ) ? absint( $item['portalVariantId'] ) : 0;
			$name              = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : 'محصول';
			$quantity          = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;

			if ( $portal_variant_id <= 0 ) {
				continue;
			}

			$expected[ (string) $portal_variant_id ] = isset( $expected[ (string) $portal_variant_id ] ) ? $expected[ (string) $portal_variant_id ] + $quantity : $quantity;
		}

		foreach ( $items as $item ) {
			if ( empty( $item['isMoboItem'] ) ) {
				continue;
			}

			$portal_variant_id = isset( $item['portalVariantId'] ) ? absint( $item['portalVariantId'] ) : 0;
			$name              = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : 'محصول';

			if ( $portal_variant_id <= 0 ) {
				continue;
			}

			$key = (string) $portal_variant_id;

			if ( ! isset( $remote[ $key ] ) ) {
				$errors[] = sprintf( 'محصول «%s» بعد از آماده‌سازی در سبد موبو پیدا نشد.', $name );
				continue;
			}

			$remote_qty   = isset( $remote[ $key ]['quantity'] ) ? (float) $remote[ $key ]['quantity'] : 0;
			$expected_qty = isset( $expected[ $key ] ) ? (float) $expected[ $key ] : 0;

			if ( abs( $remote_qty - $expected_qty ) > 0.0001 ) {
				$errors[] = sprintf( 'تعداد محصول «%s» در سبد موبو با سبد سایت برابر نیست. تعداد درخواستی: %s، تعداد موبو: %s', $name, wc_format_decimal( $expected_qty ), wc_format_decimal( $remote_qty ) );
			}

			$status = isset( $remote[ $key ]['status'] ) && is_array( $remote[ $key ]['status'] ) ? $remote[ $key ]['status'] : array();

			if ( ! empty( $status ) && ! in_array( 'approved', $status, true ) ) {
				$errors[] = sprintf( 'محصول «%s» در موبو وضعیت قابل تایید ندارد.', $name );
			}

			$minimum = isset( $remote[ $key ]['min'] ) && null !== $remote[ $key ]['min'] ? (float) $remote[ $key ]['min'] : null;
			$maximum = isset( $remote[ $key ]['max'] ) && null !== $remote[ $key ]['max'] ? (float) $remote[ $key ]['max'] : null;

			if ( null !== $minimum && $minimum > 0 && $expected_qty < $minimum ) {
				$errors[] = sprintf( 'حداقل تعداد قابل خرید محصول «%s» در موبو %s است.', $name, wc_format_decimal( $minimum ) );
			}

			if ( null !== $maximum && $maximum > 0 && $expected_qty > $maximum ) {
				$errors[] = sprintf( 'حداکثر تعداد قابل خرید محصول «%s» در موبو %s است.', $name, wc_format_decimal( $maximum ) );
			}
		}

		$this->debug_log( 'shared_cart_snapshot_compared', array( 'expected' => $expected, 'remoteCount' => count( $remote ), 'errorCount' => count( $errors ) ) );

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Send add/update cart request to Mobo API.
	 *
	 * Flow used by mobomobo.ir:
	 * 1) POST /site/api/v1/cart creates/adds a variant with variant_id = portal_variant_id.
	 * 2) GET /site/api/v1/cart?update=false returns cart.items[].id; update=true refreshes checkout data.
	 * 3) Later quantity changes must use PUT /site/api/v1/cart/{cart_item_id}.
	 *
	 * The cart item ID is discovered by matching:
	 * cart.items[].product.variant.id == portal_variant_id.
	 *
	 * @param int   $portal_variant_id MoboCore variant ID.
	 * @param float $quantity Quantity.
	 * @param bool  $prefer_put Prefer PUT when a cart item ID can be resolved.
	 * @return array|WP_Error
	 */
	private function send_mobo_cart_item( $portal_variant_id, $quantity, $prefer_put = false ) {
		$portal_variant_id = absint( $portal_variant_id );
		$quantity          = max( 0, (float) $quantity );

		if ( $portal_variant_id <= 0 ) {
			return new WP_Error( 'mobo_core_invalid_portal_variant_id', 'Invalid portal_variant_id.' );
		}

		$cart_item_id = $this->get_mobo_cart_item_id_for_variant( $portal_variant_id );
		$this->debug_log( 'send_item_start', array( 'portalVariantId' => $portal_variant_id, 'quantity' => $quantity, 'preferPut' => $prefer_put, 'cartItemId' => $cart_item_id ) );

		if ( $prefer_put && $cart_item_id <= 0 ) {
			$snapshot = $this->refresh_mobo_cart_snapshot();

			if ( $this->is_auth_error_response( $snapshot ) ) {
				return $snapshot;
			}

			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$cart_item_id = $this->get_mobo_cart_item_id_for_variant( $portal_variant_id );
		}

		if ( $cart_item_id > 0 ) {
			$response = $this->mobo_request(
				'PUT',
				'/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ),
				array( 'quantity' => $quantity )
			);

			$code = is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) );

			if ( $this->is_auth_error_response( $response ) ) {
				return $response;
			}

			if ( 200 === $code ) {
				$snapshot = $this->refresh_mobo_cart_snapshot();

				if ( $this->is_auth_error_response( $snapshot ) || is_wp_error( $snapshot ) ) {
					return $snapshot;
				}

				if ( $quantity > 0 && $this->get_mobo_cart_item_id_for_variant( $portal_variant_id ) <= 0 ) {
					return new WP_Error( 'mobo_core_mobo_cart_item_not_found_after_put', 'Mobo cart snapshot did not contain the updated variant.' );
				}

				return $response;
			}

			/*
			 * If the remote cart row disappeared, forget the stale line ID and add it again.
			 */
			$this->remove_mobo_cart_item_id_for_variant( $portal_variant_id );
		}

		$response = $this->mobo_request(
			'POST',
			'/site/api/v1/cart',
			array(
				'quantity'   => $quantity,
				'variant_id' => $portal_variant_id,
			)
		);

		$code = is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) );

		if ( $this->is_auth_error_response( $response ) || 200 !== $code ) {
			return $response;
		}

		$snapshot = $this->refresh_mobo_cart_snapshot();

		if ( $this->is_auth_error_response( $snapshot ) || is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		if ( $quantity > 0 && $this->get_mobo_cart_item_id_for_variant( $portal_variant_id ) <= 0 ) {
			return new WP_Error( 'mobo_core_mobo_cart_item_not_found_after_post', 'Mobo cart snapshot did not contain the added variant.' );
		}

		return $response;
	}

	/**
	 * Ensure Mobo session is authenticated.
	 *
	 * @param bool $force Force login.
	 * @return true|WP_Error
	 */
	private function ensure_mobo_authenticated( $force = false ) {
		$jar = $this->get_mobo_cookie_jar();

		if ( ! $force && ! empty( $jar['userauth'] ) ) {
			$this->debug_log( 'login_reuse_cookie', array( 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
			return true;
		}

		$username = trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' ) );
		$password = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_password', '' );

		if ( '' === $username || '' === $password ) {
			return new WP_Error( 'mobo_core_mobo_credentials_missing', 'Mobo username or password is missing.' );
		}

		$jar = array();
		$this->debug_log( 'login_start', array( 'force' => $force, 'username' => $username ) );

		$pre = wp_remote_get(
			$this->mobo_url( '/site/signin' ),
			array(
				'timeout'     => $this->get_mobo_timeout(),
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'checkout_validator' ),
				'headers'     => array(
					'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'User-Agent' => 'MoboCore-CheckoutValidator/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '1.0' ),
				),
			)
		);

		if ( ! is_wp_error( $pre ) ) {
			$jar = $this->merge_cookie_jar_from_response( $jar, $pre );
		}

		$response = wp_remote_post(
			$this->mobo_url( '/site/api/v1/user/signin' ),
			array(
				'timeout'     => $this->get_mobo_timeout(),
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'checkout_validator' ),
				'headers'     => array(
					'Accept'       => 'application/json, text/plain, */*',
					'Content-Type' => 'application/json; charset=utf-8',
					'Origin'       => untrailingslashit( $this->get_mobo_site_url() ),
					'Referer'      => trailingslashit( $this->get_mobo_site_url() ) . 'site/signin',
					'User-Agent'   => 'MoboCore-CheckoutValidator/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '1.0' ),
					'Cookie'       => $this->cookie_header( $jar ),
				),
				'body'        => wp_json_encode(
					array(
						'return_url' => '',
						'username'   => $username,
						'password'   => $password,
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'login_wp_error', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			$this->debug_log( 'login_http_error', array( 'httpStatus' => $code ) );
			return new WP_Error( 'mobo_core_mobo_login_http_error', 'Mobo login failed with HTTP status ' . $code );
		}

		$jar = $this->merge_cookie_jar_from_response( $jar, $response );

		if ( empty( $jar['userauth'] ) ) {
			$this->debug_log( 'login_missing_userauth', array( 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
			return new WP_Error( 'mobo_core_mobo_login_missing_cookie', 'Mobo login response did not return userauth cookie.' );
		}

		$this->set_mobo_cookie_jar( $jar );
		$this->debug_log( 'login_success', array( 'httpStatus' => $code, 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
		update_option( 'mobo_core_checkout_mobo_login_success_at', time(), false );

		return true;
	}

	/**
	 * Make an authenticated Mobo API request.
	 *
	 * Cookies returned by Mobo, including cart/userauth, are merged back into the
	 * plugin cookie jar so later calls stay in the same storefront session.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path Path.
	 * @param array|null $payload JSON payload. Null means no request body, used for GET.
	 * @return array|WP_Error
	 */
	private function mobo_request( $method, $path, $payload = array() ) {
		$jar     = $this->get_mobo_cookie_jar();
		$headers = array(
			'Accept'     => 'application/json, text/plain, */*',
			'Origin'     => untrailingslashit( $this->get_mobo_site_url() ),
			'Referer'    => trailingslashit( $this->get_mobo_site_url() ) . 'site/cart',
			'User-Agent' => 'MoboCore-CheckoutValidator/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '1.0' ),
			'Cookie'     => $this->cookie_header( $jar ),
		);

		$args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => $this->get_mobo_timeout(),
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'checkout_validator' ),
			'headers'     => $headers,
		);

		if ( null !== $payload ) {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $body ) {
				return new WP_Error( 'mobo_core_mobo_json_error', 'Could not encode Mobo request payload.' );
			}

			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = $body;
		}

		$this->debug_log( 'api_request', array( 'method' => strtoupper( (string) $method ), 'path' => $path, 'payload' => $this->sanitize_debug_payload( $payload ), 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );

		$response = wp_remote_request( $this->mobo_url( $path ), $args );

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'api_response_error', array( 'method' => strtoupper( (string) $method ), 'path' => $path, 'error' => $response->get_error_message() ) );
		}

		if ( ! is_wp_error( $response ) ) {
			$jar = $this->merge_cookie_jar_from_response( $jar, $response );
			$this->set_mobo_cookie_jar( $jar );
			$this->debug_log( 'api_response', array( 'method' => strtoupper( (string) $method ), 'path' => $path, 'httpStatus' => absint( wp_remote_retrieve_response_code( $response ) ), 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
		}

		return $response;
	}

	/**
	 * Optional generic external validation.
	 *
	 * @param array $items Cart item payload.
	 * @return array Errors.
	 */
	private function validate_external( $items ) {
		if ( ! $this->is_external_validation_effective() ) {
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

		$security_code = Mobo_Core_Settings::normalize_security_code( Mobo_Core_Settings::get( 'mobo_core_security_code', '' ) );
		if ( '' !== $security_code ) {
			if ( ! Mobo_Core_Settings::is_valid_security_code( $security_code ) ) {
				return $this->external_error_result( Mobo_Core_Settings::get_security_code_validation_error( $security_code ) );
			}

			$headers['X-SEC'] = $security_code;
		}

		$token = trim( (string) Mobo_Core_Settings::get( 'mobo_core_token', '' ) );
		if ( '' !== $token ) {
			$headers['Token'] = $token;
		}

		$response = wp_remote_post(
			esc_url_raw( $url ),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'checkout_validator' ),
				'headers'     => $headers,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->external_error_result( 'خطا در ارتباط با سرویس اعتبارسنجی خرید.' );
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			return $this->external_error_result( 'پاسخ سرویس اعتبارسنجی خرید معتبر نیست.' );
		}

		$json = apply_filters( 'mobo_core_checkout_validation_external_response', $json, $items, $payload );

		if ( ! is_array( $json ) ) {
			$json = array();
		}

		return $this->extract_external_errors( $json );
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
	 * Get saved MoboCore product ID.
	 *
	 * @param int $parent_id Parent product ID.
	 * @param int $product_id Actual product ID.
	 * @return int
	 */
	private function get_portal_product_id( $parent_id, $product_id ) {
		$keys = array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' );
		$ids  = array( absint( $parent_id ), absint( $product_id ) );

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			foreach ( $keys as $key ) {
				$value = get_post_meta( $id, $key, true );

				if ( '' !== (string) $value && is_numeric( $value ) ) {
					return absint( $value );
				}
			}
		}

		return 0;
	}

	/**
	 * Get saved MoboCore variant ID.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private function get_portal_variant_id( $variation_id, $product_id ) {
		$keys = array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
		$ids  = array( absint( $variation_id ), absint( $product_id ) );

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			foreach ( $keys as $key ) {
				$value = get_post_meta( $id, $key, true );

				if ( '' !== (string) $value && is_numeric( $value ) ) {
					return absint( $value );
				}
			}
		}

		return 0;
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
	 * Store Mobo validation result for admin UI.
	 *
	 * @param bool   $success Success.
	 * @param int    $status HTTP status.
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param array  $items Items.
	 * @return void
	 */
	private function store_mobo_validation_result( $success, $status, $code, $message, $items ) {
		update_option(
			'mobo_core_checkout_last_validation_result',
			array(
				'success' => (bool) $success,
				'status'  => absint( $status ),
				'code'    => sanitize_text_field( (string) $code ),
				'message' => sanitize_text_field( (string) $message ),
				'items'   => is_array( $items ) ? $items : array(),
			),
			false
		);
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

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Is response an authentication error?
	 *
	 * @param mixed $response Response.
	 * @return bool
	 */
	private function is_auth_error_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( in_array( $code, array( 401, 403 ), true ) ) {
			return true;
		}

		/*
		 * Mobo sometimes returns HTTP 400 for an expired/guest session at
		 * /cart/checkout instead of a formal 401/403. Detect that message so
		 * the request layer can force-login and retry.
		 */
		if ( 400 === $code ) {
			$raw  = (string) wp_remote_retrieve_body( $response );
			$json = json_decode( $raw, true );
			$text = $raw;

			if ( is_array( $json ) ) {
				$text = wp_json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}

			$text = is_string( $text ) ? $text : '';

			if ( false !== strpos( $text, 'وارد وب' ) || false !== strpos( $text, 'کاربران وب' ) || false !== stripos( $text, 'login' ) || false !== stripos( $text, 'signin' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mobo site URL.
	 *
	 * @return string
	 */
	private function get_mobo_site_url() {
		return 'https://mobomobo.ir';
	}

	/**
	 * Build full Mobo URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function mobo_url( $path ) {
		return $this->get_mobo_site_url() . '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Mobo API timeout.
	 *
	 * @return int
	 */
	private function get_mobo_timeout() {
		return Mobo_Core_Settings::get_int( 'mobo_core_checkout_mobo_timeout_seconds', 8, 2, 20 );
	}

	/**
	 * Get the shared Mobo cookie jar.
	 *
	 * In single shared cart mode, the Mobo account and Mobo cart are shared. The
	 * cookie jar is therefore option-backed and guarded by a global cart lock during
	 * checkout validation/order submission.
	 *
	 * @return array
	 */
	private function get_mobo_cookie_jar() {
		$jar = get_option( 'mobo_core_checkout_mobo_cookie_jar', array() );

		return is_array( $jar ) ? $jar : array();
	}

	/**
	 * Persist the shared Mobo cookie jar.
	 *
	 * @param array $jar Cookie jar.
	 * @return void
	 */
	private function set_mobo_cookie_jar( $jar ) {
		update_option( 'mobo_core_checkout_mobo_cookie_jar', is_array( $jar ) ? $jar : array(), false );
	}

	/**
	 * Clear the shared Mobo cookie jar.
	 *
	 * @return void
	 */
	private function clear_mobo_cookie_jar() {
		delete_option( 'mobo_core_checkout_mobo_cookie_jar' );
	}

	/**
	 * Merge Set-Cookie headers into jar.
	 *
	 * @param array $jar Cookie jar.
	 * @param array $response HTTP response.
	 * @return array
	 */
	private function merge_cookie_jar_from_response( $jar, $response ) {
		if ( ! is_array( $jar ) ) {
			$jar = array();
		}

		$cookies = wp_remote_retrieve_cookies( $response );

		if ( is_array( $cookies ) ) {
			foreach ( $cookies as $cookie ) {
				if ( is_object( $cookie ) && method_exists( $cookie, 'getName' ) && method_exists( $cookie, 'getValue' ) ) {
					$name  = $this->sanitize_cookie_name( (string) $cookie->getName() );
					$value = sanitize_text_field( (string) $cookie->getValue() );

					if ( '' !== $name && '' !== $value ) {
						$jar[ $name ] = $value;
					}
				}
			}
		}

		$set_cookie = wp_remote_retrieve_header( $response, 'set-cookie' );

		if ( ! empty( $set_cookie ) ) {
			$headers = is_array( $set_cookie ) ? $set_cookie : array( $set_cookie );

			foreach ( $headers as $header ) {
				$first = trim( strtok( (string) $header, ';' ) );

				if ( false === strpos( $first, '=' ) ) {
					continue;
				}

				list( $name, $value ) = array_map( 'trim', explode( '=', $first, 2 ) );
				$name  = $this->sanitize_cookie_name( $name );
				$value = sanitize_text_field( $value );

				if ( '' !== $name && '' !== $value ) {
					$jar[ $name ] = $value;
				}
			}
		}

		return $jar;
	}


	/**
	 * Preserve cookie name case while stripping invalid characters.
	 *
	 * @param string $name Cookie name.
	 * @return string
	 */
	private function sanitize_cookie_name( $name ) {
		$name = trim( (string) $name );
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', $name );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Build Cookie header.
	 *
	 * @param array $jar Cookie jar.
	 * @return string
	 */
	private function cookie_header( $jar ) {
		if ( ! is_array( $jar ) || empty( $jar ) ) {
			return '';
		}

		$parts = array();

		foreach ( $jar as $name => $value ) {
			$name  = $this->sanitize_cookie_name( (string) $name );
			$value = sanitize_text_field( (string) $value );

			if ( '' !== $name && '' !== $value ) {
				$parts[] = $name . '=' . $value;
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * Fetch the authoritative Mobo cart snapshot and rebuild the variant => cart line map.
	 *
	 * This must run after every successful POST/PUT because Mobo's quantity update
	 * endpoint needs cart.items[].id, not the MoboCore variant ID.
	 *
	 * @return array|WP_Error
	 */
	private function refresh_mobo_cart_snapshot( $update = false ) {
		$update = (bool) $update;
		$this->debug_log( 'snapshot_request', array( 'update' => $update ) );
		$response = $this->mobo_request( 'GET', '/site/api/v1/cart?update=' . ( $update ? 'true' : 'false' ), null );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $this->is_auth_error_response( $response ) ) {
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( 200 !== $code ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_http_error', 'Mobo cart snapshot failed with HTTP status ' . $code );
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_json_error', 'Mobo cart snapshot response was not valid JSON.' );
		}

		$this->store_mobo_cart_item_map_from_snapshot( $json );
		$this->debug_log( 'snapshot_success', array( 'itemCount' => isset( $json['cart']['items'] ) && is_array( $json['cart']['items'] ) ? count( $json['cart']['items'] ) : 0 ) );
		update_option( 'mobo_core_checkout_mobo_cart_snapshot_at', time(), false );

		return $response;
	}

	/**
	 * Store variant => cart item ID map from GET /cart response.
	 *
	 * @param array $json Decoded snapshot.
	 * @return void
	 */
	private function store_mobo_cart_item_map_from_snapshot( $json ) {
		$map   = array();
		$items = array();

		if ( isset( $json['cart']['items'] ) && is_array( $json['cart']['items'] ) ) {
			$items = $json['cart']['items'];
		}

		foreach ( $items as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$cart_item_id = isset( $cart_item['id'] ) ? absint( $cart_item['id'] ) : 0;
			$variant_id   = 0;

			if ( isset( $cart_item['product'] ) && is_array( $cart_item['product'] ) && isset( $cart_item['product']['variant'] ) && is_array( $cart_item['product']['variant'] ) && isset( $cart_item['product']['variant']['id'] ) ) {
				$variant_id = absint( $cart_item['product']['variant']['id'] );
			}

			if ( $cart_item_id <= 0 || $variant_id <= 0 ) {
				continue;
			}

			$map[ (string) $variant_id ] = array(
				'cartItemId' => $cart_item_id,
				'quantity'   => isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : null,
				'updatedAt'  => time(),
			);
		}

		$this->set_mobo_cart_item_map( $map );
		$this->debug_log( 'cart_map_updated', array( 'map' => $map, 'mapCount' => count( $map ) ) );
		update_option( 'mobo_core_checkout_mobo_cart_item_map_count', count( $map ), false );
	}

	/**
	 * Get cart item ID for a MoboCore variant ID from current WooCommerce session.
	 *
	 * @param int $portal_variant_id MoboCore variant ID.
	 * @return int
	 */
	private function get_mobo_cart_item_id_for_variant( $portal_variant_id ) {
		$portal_variant_id = absint( $portal_variant_id );

		if ( $portal_variant_id <= 0 ) {
			return 0;
		}

		$map = $this->get_mobo_cart_item_map();
		$key = (string) $portal_variant_id;

		if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
			return 0;
		}

		return isset( $map[ $key ]['cartItemId'] ) ? absint( $map[ $key ]['cartItemId'] ) : 0;
	}

	/**
	 * Remove stale cart item ID mapping for a MoboCore variant.
	 *
	 * @param int $portal_variant_id MoboCore variant ID.
	 * @return void
	 */
	private function remove_mobo_cart_item_id_for_variant( $portal_variant_id ) {
		$portal_variant_id = absint( $portal_variant_id );

		if ( $portal_variant_id <= 0 ) {
			return;
		}

		$map = $this->get_mobo_cart_item_map();
		unset( $map[ (string) $portal_variant_id ] );
		$this->set_mobo_cart_item_map( $map );
	}

	/**
	 * Read current variant => cart item ID map.
	 *
	 * @return array
	 */
	private function get_mobo_cart_item_map() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$map = WC()->session->get( 'mobo_core_mobo_cart_item_map', array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Persist current variant => cart item ID map.
	 *
	 * @param array $map Map.
	 * @return void
	 */
	private function set_mobo_cart_item_map( $map ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		WC()->session->set( 'mobo_core_mobo_cart_item_map', is_array( $map ) ? $map : array() );
	}


	/**
	 * Return recent Mobo cart debug log entries for admin UI.
	 *
	 * @return array
	 */
	public function get_mobo_debug_log() {
		$log = get_option( 'mobo_core_checkout_mobo_debug_log', array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear Mobo cart debug log.
	 *
	 * @return void
	 */
	public function clear_mobo_debug_log() {
		delete_option( 'mobo_core_checkout_mobo_debug_log' );
	}

	/**
	 * Store a small sanitized debug event for Mobo cart synchronization.
	 *
	 * @param string $action Action name.
	 * @param array  $context Context.
	 * @return void
	 */
	private function debug_log( $action, $context = array() ) {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_checkout_mobo_debug_enabled', '0' ) ) {
			return;
		}

		$log = get_option( 'mobo_core_checkout_mobo_debug_log', array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$session_meta = $this->get_debug_session_meta();

		$entry = array(
			'time'        => time(),
			'action'      => sanitize_key( (string) $action ),
			'session'     => isset( $session_meta['session'] ) ? $session_meta['session'] : $this->get_debug_session_id(),
			'sessionMeta' => $session_meta,
			'context'     => $this->sanitize_debug_context( is_array( $context ) ? $context : array() ),
			'requestId'   => $this->get_debug_request_id(),
			'uri'         => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		);

		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 500 );

		update_option( 'mobo_core_checkout_mobo_debug_log', $log, false );
	}

	/**
	 * Return one request id for all debug events generated during the current PHP request.
	 *
	 * @return string
	 */
	private function get_debug_request_id() {
		if ( null === $this->debug_request_id ) {
			$this->debug_request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mobo_', true );
		}

		return $this->debug_request_id;
	}

	/**
	 * Return non-secret session metadata for debugging concurrent carts.
	 *
	 * @return array
	 */
	private function get_debug_session_meta() {
		$source          = '';
		$source_type     = 'unknown';
		$woo_customer_id = '';
		$session_cookie  = '';

		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$woo_customer_id = (string) WC()->session->get_customer_id();
		}

		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_session_cookie' ) ) {
			$cookie = WC()->session->get_session_cookie();

			if ( is_array( $cookie ) && isset( $cookie[0] ) ) {
				$session_cookie = (string) $cookie[0];
			}
		}

		if ( '' !== $session_cookie ) {
			$source      = $session_cookie;
			$source_type = 'wc_session_cookie';
		} elseif ( '' !== $woo_customer_id ) {
			$source      = $woo_customer_id;
			$source_type = 'wc_customer_id';
		} else {
			$source      = is_admin() ? 'admin' : 'unknown';
			$source_type = is_admin() ? 'admin' : 'fallback';
		}

		return array(
			'session'            => substr( hash( 'sha256', $source ), 0, 12 ),
			'sourceType'         => $source_type,
			'wpUserId'           => function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0,
			'wooCustomerIdHash'  => '' !== $woo_customer_id ? substr( hash( 'sha256', $woo_customer_id ), 0, 12 ) : '',
			'wcSessionHash'      => '' !== $session_cookie ? substr( hash( 'sha256', $session_cookie ), 0, 12 ) : '',
			'wcCartHash'         => $this->get_debug_wc_cart_hash(),
			'wcCartItemCount'    => $this->get_debug_wc_cart_item_count(),
			'isAdmin'            => is_admin(),
		);
	}

	/**
	 * Return a stable non-secret identifier for the current WooCommerce session.
	 *
	 * @return string
	 */
	private function get_debug_session_id() {
		$meta = $this->get_debug_session_meta();

		return isset( $meta['session'] ) ? (string) $meta['session'] : 'unknown';
	}

	/**
	 * Return a hash of current WooCommerce cart contents for debug comparison.
	 *
	 * @return string
	 */
	private function get_debug_wc_cart_hash() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$parts = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;
			$quantity     = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;

			$parts[] = $product_id . ':' . $variation_id . ':' . $quantity;
		}

		sort( $parts );

		return empty( $parts ) ? '' : substr( hash( 'sha256', implode( '|', $parts ) ), 0, 12 );
	}

	/**
	 * Return current WooCommerce cart item count.
	 *
	 * @return int
	 */
	private function get_debug_wc_cart_item_count() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		return absint( WC()->cart->get_cart_contents_count() );
	}

	/**
	 * Mask Mobo cookies before storing debug data.
	 *
	 * @param array $jar Cookie jar.
	 * @return array
	 */
	private function mask_cookie_jar( $jar ) {
		$result = array();

		if ( ! is_array( $jar ) ) {
			return $result;
		}

		foreach ( $jar as $name => $value ) {
			$name  = $this->sanitize_cookie_name( (string) $name );
			$value = (string) $value;

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$result[ $name ] = array(
				'hash'   => substr( hash( 'sha256', $value ), 0, 12 ),
				'length' => strlen( $value ),
			);
		}

		return $result;
	}

	/**
	 * Sanitize an API payload for debug logging.
	 *
	 * @param mixed $payload Payload.
	 * @return mixed
	 */
	private function sanitize_debug_payload( $payload ) {
		if ( null === $payload ) {
			return null;
		}

		if ( ! is_array( $payload ) ) {
			return sanitize_text_field( (string) $payload );
		}

		$allowed = array();

		foreach ( array( 'quantity', 'variant_id' ) as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$allowed[ $key ] = is_numeric( $payload[ $key ] ) ? (float) $payload[ $key ] : sanitize_text_field( (string) $payload[ $key ] );
			}
		}

		return $allowed;
	}

	/**
	 * Recursively sanitize debug context.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sanitize_debug_context( $value ) {
		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $key => $item ) {
				$key = is_string( $key ) ? sanitize_key( $key ) : absint( $key );

				if ( in_array( $key, array( 'password', 'userauth', 'cart', 'csrfToken', 'csrf_token', 'token' ), true ) ) {
					$out[ $key ] = '[masked]';
					continue;
				}

				$out[ $key ] = $this->sanitize_debug_context( $item );
			}

			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$value = sanitize_text_field( (string) $value );

		if ( strlen( $value ) > 300 ) {
			$value = substr( $value, 0, 300 ) . '...';
		}

		return $value;
	}


	/**
	 * Submit WooCommerce orders to Mobo once they enter processing.
	 *
	 * @param int       $order_id Order ID.
	 * @param string    $old_status Old status without wc- prefix.
	 * @param string    $new_status New status without wc- prefix.
	 * @param WC_Order  $order Order object.
	 * @return void
	 */
	public function handle_order_status_changed( $order_id, $old_status, $new_status, $order = null ) {
		if ( 'processing' !== (string) $new_status || 'completed' === (string) $old_status ) {
			return;
		}

		if ( ! $this->is_order_submission_enabled() ) {
			return;
		}

		$order_object = $order instanceof WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null );
		$scope        = $this->get_order_mobo_item_scope( $order_object );

		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->add_non_mobo_order_note( absint( $order_id ), $scope, 'status_processing' );
			return;
		}

		/*
		 * Never touch/save the WC_Order object during the status transition. Some
		 * HPOS/admin save flows can lose the requested status if another save is
		 * performed from this hook. Only enqueue the order id in wp_options and let
		 * cron inspect the already-saved order later.
		 */
		$this->queue_mobo_order_id_for_later(
			absint( $order_id ),
			array(
				'trigger'   => 'status_processing',
				'oldStatus' => $old_status,
				'newStatus' => $new_status,
			)
		);
	}

	/**
	 * Scheduled callback that only puts an order id into the option-backed queue.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $context Context.
	 * @return void
	 */
	public function handle_scheduled_order_queue( $order_id, $context = array() ) {
		$this->queue_mobo_order_id_for_later( absint( $order_id ), is_array( $context ) ? $context : array() );
	}

	/**
	 * WP-Cron callback. The same queue is also processed by the plugin real cron.
	 *
	 * @return void
	 */
	public function handle_scheduled_queued_order_submissions() {
		$this->process_queued_mobo_order_submissions( 1, 'wp-cron' );
	}

	/**
	 * Queue an order id without touching the WooCommerce order row.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $context Context.
	 * @return bool
	 */
	private function queue_mobo_order_id_for_later( $order_id, $context = array() ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return false;
		}

		$queue = get_option( 'mobo_core_mobo_order_submission_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$queue[ (string) $order_id ] = array(
			'orderId'  => $order_id,
			'queuedAt' => time(),
			'context'  => $this->sanitize_order_log_value( is_array( $context ) ? $context : array() ),
		);

		update_option( 'mobo_core_mobo_order_submission_queue', $queue, false );

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'order-submission-queued', false );
		}

		return true;
	}

	/**
	 * Read the option-backed queue.
	 *
	 * @return array
	 */
	private function get_option_backed_order_queue() {
		$queue = get_option( 'mobo_core_mobo_order_submission_queue', array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Persist the option-backed queue.
	 *
	 * @param array $queue Queue.
	 * @return void
	 */
	private function save_option_backed_order_queue( $queue ) {
		if ( empty( $queue ) ) {
			delete_option( 'mobo_core_mobo_order_submission_queue' );
			return;
		}
		update_option( 'mobo_core_mobo_order_submission_queue', $queue, false );
	}

	/**
	 * Queue an order for async Mobo submission.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $context Context.
	 * @return bool|WP_Error
	 */
	private function queue_mobo_order_submission( $order, $context = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}

		if ( 'processing' !== $order->get_status() ) {
			return new WP_Error( 'mobo_core_order_not_processing', 'Order is not processing.' );
		}

		if ( $this->order_was_already_sent_to_mobo( $order ) ) {
			return false;
		}

		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'queue_request' );
			return new WP_Error( 'mobo_core_order_not_mobo', 'این سفارش مربوط به موبو نیست.' );
		}

		$status = (string) $order->get_meta( '_mobo_order_submit_status', true );
		if ( 'queued' === $status || 'running' === $status ) {
			return false;
		}

		$order->update_meta_data( '_mobo_order_submit_queued', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_queued_at', time() );
		$order->update_meta_data( '_mobo_order_submit_status', 'queued' );
		$order->update_meta_data( '_mobo_order_submit_context', $this->sanitize_order_log_value( $context ) );
		$order->delete_meta_data( '_mobo_order_last_error_code' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$order->save();

		$this->append_order_log( $order, 'order_submission_queued', array( 'context' => $context ) );

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'order-submission-queued', false );
		}

		return true;
	}

	/**
	 * Process queued Mobo order submissions. Used by real cron and WP-Cron.
	 *
	 * @param int    $limit Limit.
	 * @param string $source Source.
	 * @return array
	 */
	public function process_queued_mobo_order_submissions( $limit = 1, $source = 'real-cron' ) {
		$limit = max( 1, min( 5, absint( $limit ) ) );

		$result = array(
			'status'    => 'ok',
			'source'    => sanitize_key( (string) $source ),
			'processed' => 0,
			'success'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'remaining' => false,
		);

		if ( ! $this->is_order_submission_enabled() || ! function_exists( 'wc_get_order' ) ) {
			$result['status'] = 'disabled';
			return $result;
		}

		$queue       = $this->get_option_backed_order_queue();
		$queue_items = array();
		foreach ( $queue as $key => $item ) {
			$order_id = is_array( $item ) && isset( $item['orderId'] ) ? absint( $item['orderId'] ) : absint( $key );
			if ( $order_id <= 0 ) {
				unset( $queue[ $key ] );
				continue;
			}
			$queue_items[] = array(
				'queueKey' => $key,
				'orderId'  => $order_id,
				'context'  => is_array( $item ) && isset( $item['context'] ) && is_array( $item['context'] ) ? $item['context'] : array(),
			);
		}

		if ( count( $queue_items ) > $limit ) {
			$result['remaining'] = true;
			$queue_items = array_slice( $queue_items, 0, $limit );
		}

		if ( empty( $queue_items ) && function_exists( 'wc_get_orders' ) ) {
			$legacy_orders = wc_get_orders(
				array(
					'limit'      => $limit + 1,
					'status'     => array( 'processing' ),
					'meta_key'   => '_mobo_order_submit_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Legacy bounded fallback queue lookup.
					'meta_value' => 'queued', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Legacy bounded fallback queue lookup.
					'orderby'    => 'date',
					'order'      => 'ASC',
					'return'     => 'ids',
				)
			);

			if ( is_array( $legacy_orders ) && ! empty( $legacy_orders ) ) {
				if ( count( $legacy_orders ) > $limit ) {
					$result['remaining'] = true;
					$legacy_orders = array_slice( $legacy_orders, 0, $limit );
				}
				foreach ( $legacy_orders as $legacy_order_id ) {
					$queue_items[] = array(
						'queueKey' => null,
						'orderId'  => absint( $legacy_order_id ),
						'context'  => array( 'legacyMetaQueue' => true ),
					);
				}
			}
		}

		if ( empty( $queue_items ) ) {
			$this->save_option_backed_order_queue( $queue );
			$result['status'] = 'empty';
			return $result;
		}

		foreach ( $queue_items as $queue_item ) {
			$order_id = absint( $queue_item['orderId'] );
			$order    = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				$result['skipped']++;
				if ( null !== $queue_item['queueKey'] ) {
					unset( $queue[ $queue_item['queueKey'] ] );
				}
				continue;
			}

			if ( 'processing' !== $order->get_status() || $this->order_was_already_sent_to_mobo( $order ) ) {
				$result['skipped']++;
				if ( null !== $queue_item['queueKey'] ) {
					unset( $queue[ $queue_item['queueKey'] ] );
				}
				continue;
			}

			$scope = $this->get_order_mobo_item_scope( $order );
			if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
				$result['skipped']++;
				$this->mark_order_as_not_mobo( $order, $scope, 'queue_processor' );
				if ( null !== $queue_item['queueKey'] ) {
					unset( $queue[ $queue_item['queueKey'] ] );
				}
				continue;
			}

			$context = is_array( $queue_item['context'] ) ? $queue_item['context'] : array();
			$context['processor'] = $source;

			$result['processed']++;
			$submit = $this->submit_order_to_mobo( $order, $context );
			if ( null !== $queue_item['queueKey'] ) {
				unset( $queue[ $queue_item['queueKey'] ] );
			}

			if ( true === $submit ) {
				$result['success']++;
				$completed_order = wc_get_order( $order_id );

				/*
				 * A mixed WooCommerce order still has local/non-Mobo fulfilment work.
				 * Successfully purchasing only its Mobo lines must never complete the
				 * parent WooCommerce order. Keep it in processing even when the global
				 * Mobo auto-complete option is enabled.
				 */
				if ( $completed_order instanceof WC_Order && 'mixed' === (string) $scope['status'] ) {
					$note = 'اقلام موبو با موفقیت در موبو ثبت شدند. این سفارش ترکیبی است و برای پردازش اقلام غیرموبو در وضعیت در حال انجام باقی می‌ماند.';
					$completed_order->update_meta_data( '_mobo_order_scope_status', 'mixed' );
					$completed_order->update_meta_data( '_mobo_order_kept_processing', 'yes' );

					if ( 'processing' !== $completed_order->get_status() ) {
						$completed_order->update_status( 'processing', $note, true );
					} else {
						$completed_order->save();
						$completed_order->add_order_note( $note );
					}

					$this->append_order_log(
						$completed_order,
						'order_submission_mixed_kept_processing',
						array(
							'moboItems'    => isset( $scope['mobo'] ) ? absint( $scope['mobo'] ) : 0,
							'nonMoboItems' => isset( $scope['nonMobo'] ) ? absint( $scope['nonMobo'] ) : 0,
						)
					);
				} elseif (
					$completed_order instanceof WC_Order
					&& 'all_mobo' === (string) $scope['status']
					&& 'completed' !== $completed_order->get_status()
					&& Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_auto_complete_enabled', '1' )
				) {
					$completed_order->update_status( 'completed', 'تمام اقلام سفارش موبو بودند؛ سفارش با موفقیت در موبو ثبت شد و وضعیت به تکمیل شده تغییر کرد.', true );
				}
			} else {
				$result['failed']++;
			}
		}

		$this->save_option_backed_order_queue( $queue );
		return $result;
	}

	private function is_order_submission_enabled() {
		return Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
	}

	private function order_was_already_sent_to_mobo( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return true;
		}

		$submitted = (string) $order->get_meta( '_mobo_order_submitted', true );
		$attempted = (string) $order->get_meta( '_mobo_order_submit_attempted', true );
		$mobo_id   = (string) $order->get_meta( '_mobo_order_id', true );

		return 'yes' === $submitted || '' !== $mobo_id || 'yes' === $attempted;
	}

	/**
	 * Inspect order line items and decide how much of the order belongs to Mobo.
	 *
	 * Orders with at least one Mobo item can enter the Mobo submission queue.
	 * In mixed orders, non-Mobo items stay only inside WooCommerce and are not sent to Mobo.
	 *
	 * @param WC_Order|null $order Order object.
	 * @return array
	 */
	private function get_order_mobo_item_scope( $order ) {
		$scope = array(
			'status'    => 'invalid',
			'total'     => 0,
			'mobo'      => 0,
			'nonMobo'   => 0,
			'invalid'   => 0,
			'nonMoboNames' => array(),
		);

		if ( ! $order instanceof WC_Order ) {
			return $scope;
		}

		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$scope['total']++;
			$product = $line_item->get_product();
			$name    = wp_strip_all_tags( $line_item->get_name() );

			if ( ! $product instanceof WC_Product ) {
				$scope['invalid']++;
				$scope['nonMoboNames'][] = '' !== $name ? $name : 'محصول نامشخص';
				continue;
			}

			if ( $this->is_order_line_item_mobo( $line_item, $product ) ) {
				$scope['mobo']++;
			} else {
				$scope['nonMobo']++;
				$scope['nonMoboNames'][] = '' !== $name ? $name : 'محصول';
			}
		}

		if ( $scope['total'] <= 0 ) {
			$scope['status'] = 'empty';
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] > 0 ) {
			$scope['status'] = 'mixed';
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] <= 0 ) {
			$scope['status'] = 'non_mobo';
		} elseif ( $scope['invalid'] > 0 ) {
			$scope['status'] = 'invalid';
		} else {
			$scope['status'] = 'all_mobo';
		}

		$scope['nonMoboNames'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $scope['nonMoboNames'] ) ) ) );
		return $scope;
	}

	/**
	 * Determine whether a single order line item is mapped to Mobo.
	 *
	 * @param WC_Order_Item_Product $line_item Order item.
	 * @param WC_Product            $product Product object.
	 * @return bool
	 */
	private function is_order_line_item_mobo( $line_item, $product ) {
		if ( ! $line_item instanceof WC_Order_Item_Product || ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id   = absint( $line_item->get_product_id() );
		$variation_id = absint( $line_item->get_variation_id() );
		$wc_id        = absint( $product->get_id() );
		$parent_id    = $product_id > 0 ? $product_id : $wc_id;

		$product_guid      = $this->get_remote_product_guid( $parent_id, $wc_id );
		$variant_guid      = $variation_id > 0 ? sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) ) : '';
		$portal_product_id = $this->get_portal_product_id( $parent_id, $wc_id );
		$portal_variant_id = $this->get_portal_variant_id( $variation_id, $wc_id );
		$mobo_url          = $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'mobo_url', true ) ) : '';

		if ( '' === $mobo_url && $wc_id > 0 ) {
			$mobo_url = sanitize_text_field( (string) get_post_meta( $wc_id, 'mobo_url', true ) );
		}

		return '' !== $product_guid || '' !== $variant_guid || $portal_product_id > 0 || $portal_variant_id > 0 || '' !== $mobo_url;
	}

	/**
	 * True when the order contains at least one Mobo item.
	 *
	 * Mixed orders are allowed; only their Mobo line items are sent to Mobo.
	 * Orders with no Mobo item must stay completely outside the Mobo submission flow.
	 *
	 * @param array $scope Scope from get_order_mobo_item_scope().
	 * @return bool
	 */
	private function order_scope_has_mobo_items( $scope ) {
		return is_array( $scope ) && isset( $scope['mobo'] ) && absint( $scope['mobo'] ) > 0;
	}

	/**
	 * Add a non-Mobo informational note without entering the Mobo queue.
	 *
	 * @param int    $order_id Order ID.
	 * @param array  $scope Scope.
	 * @param string $source Source key.
	 * @return void
	 */
	private function add_non_mobo_order_note( $order_id, $scope, $source = '' ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}

		$transient_key = 'mobo_core_non_mobo_note_' . $order_id . '_' . sanitize_key( (string) $source );
		if ( get_transient( $transient_key ) ) {
			return;
		}

		$message = $this->build_non_mobo_order_message( $scope );

		if ( function_exists( 'wc_create_order_note' ) ) {
			wc_create_order_note( $order_id, $message, false, true );
		} elseif ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->add_order_note( $message );
			}
		}

		set_transient( $transient_key, '1', DAY_IN_SECONDS );
	}

	/**
	 * Mark old queued/retry attempts as not related to Mobo.
	 *
	 * This is not called from the status-change hook, so saving order meta here is
	 * safe and gives the admin column/meta box a clear state.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $scope Scope.
	 * @param string   $source Source key.
	 * @return void
	 */
	private function mark_order_as_not_mobo( $order, $scope, $source = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$message = $this->build_non_mobo_order_message( $scope );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->update_meta_data( '_mobo_order_submit_status', 'not_mobo' );
		$order->update_meta_data( '_mobo_order_last_error_code', 'not_mobo_order' );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->save();
		$order->add_order_note( $message );
		$this->append_order_log( $order, 'order_submission_skipped_not_mobo', array( 'source' => $source, 'scope' => $scope ) );
	}

	/**
	 * Build the exact admin-facing message for non-Mobo/mixed orders.
	 *
	 * @param array $scope Scope.
	 * @return string
	 */
	private function build_non_mobo_order_message( $scope ) {
		$status = is_array( $scope ) && isset( $scope['status'] ) ? sanitize_key( (string) $scope['status'] ) : 'invalid';
		$names  = is_array( $scope ) && isset( $scope['nonMoboNames'] ) && is_array( $scope['nonMoboNames'] ) ? $scope['nonMoboNames'] : array();
		$names  = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $names ) ) ), 0, 5 );

		$message = 'این سفارش مربوط به موبو نیست و وارد صف ارسال برای موبو نشد.';

		if ( 'mixed' === $status ) {
			$message .= ' سفارش ترکیبی است و همه آیتم‌ها موبو نیستند.';
		} elseif ( 'non_mobo' === $status ) {
			$message .= ' هیچ آیتم موبوی معتبری در سفارش پیدا نشد.';
		} elseif ( 'empty' === $status ) {
			$message .= ' هیچ آیتم قابل بررسی در سفارش وجود ندارد.';
		}

		if ( ! empty( $names ) ) {
			$message .= ' آیتم‌های غیرموبو: ' . implode( '، ', $names );
		}

		return $message;
	}

	/**
	 * Submit an order to Mobo through the shared cart checkout flow.
	 *
	 * This is intentionally one-shot per WooCommerce order. The attempted flag is
	 * saved before remote calls to prevent duplicate Mobo orders if callbacks or
	 * status transitions fire more than once.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $context Context.
	 * @return true|WP_Error
	 */
	private function submit_order_to_mobo( $order, $context = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}

		$order_id = $order->get_id();
		$scope    = $this->get_order_mobo_item_scope( $order );

		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'submit_guard' );
			return new WP_Error( 'mobo_core_order_not_mobo', 'این سفارش محصول موبو ندارد.' );
		}


		$order->update_meta_data( '_mobo_order_submit_attempted', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_attempted_at', time() );
		$order->update_meta_data( '_mobo_order_submit_status', 'running' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$order->save();

		$this->append_order_log( $order, 'order_submission_start', array( 'orderId' => $order_id, 'context' => $context ) );

		$errors = array();
		$items  = $this->build_order_items_payload( $order, $errors );

		if ( empty( $items ) && empty( $errors ) ) {
			$errors[] = 'سفارش هیچ آیتم موبوی قابل ارسال ندارد.';
		}

		if ( ! empty( $errors ) ) {
			$error_code = 'local_validation_failed';
			foreach ( $errors as $error_text ) {
				if ( false !== strpos( (string) $error_text, 'غیرموبو' ) ) {
					$error_code = 'non_mobo_items_in_order';
					break;
				}
			}

			return $this->fail_mobo_order_submission( $order, $error_code, implode( ' | ', $errors ) );
		}

		/*
		 * Resolve and validate the checkout contact before touching the shared Mobo
		 * session/cart. Local mapping errors must fail without remote side effects.
		 */
		$contact_preflight = $this->build_mobo_order_contact_payload( $order );
		if ( is_wp_error( $contact_preflight ) ) {
			$this->append_order_log( $order, 'order_submission_address_preflight_failed', array(
				'code'    => $contact_preflight->get_error_code(),
				'message' => $contact_preflight->get_error_message(),
				'data'    => $contact_preflight->get_error_data(),
			) );
			return $this->fail_mobo_order_submission( $order, 'checkout_payload_failed', $contact_preflight->get_error_message() );
		}

		$lock = $this->acquire_mobo_cart_lock( 'order_submission_' . $order_id );
		if ( is_wp_error( $lock ) ) {
			return $this->fail_mobo_order_submission( $order, 'cart_lock_failed', $lock->get_error_message() );
		}

		try {
			/*
			 * Order submission must always start from a fresh authenticated Mobo
			 * session. A stale option-backed userauth may still allow guest cart
			 * operations, but /cart/checkout rejects it with HTTP 400 and the
			 * Persian "please sign in" message. Force login here so the shared
			 * cart is rebuilt under the authenticated account before checkout.
			 */
			$auth = $this->ensure_mobo_authenticated( true );
			if ( is_wp_error( $auth ) ) {
				return $this->fail_mobo_order_submission( $order, 'login_failed', $auth->get_error_message() );
			}
			$this->append_order_log( $order, 'order_submission_login_success', array( 'cookieJar' => $this->mask_cookie_jar( $this->get_mobo_cookie_jar() ) ) );

			$clear = $this->clear_shared_mobo_cart();
			if ( is_wp_error( $clear ) ) {
				return $this->fail_mobo_order_submission( $order, 'cart_clear_failed', $clear->get_error_message() );
			}

			foreach ( $items as $item ) {
				$portal_variant_id = isset( $item['portalVariantId'] ) ? absint( $item['portalVariantId'] ) : 0;
				$quantity          = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;
				$name              = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : 'محصول';

				$response  = $this->order_mobo_request( $order, 'POST', '/site/api/v1/cart', array( 'quantity' => $quantity, 'variant_id' => $portal_variant_id ), 'cart_add_item' );
				$add_check = $this->validate_mobo_cart_add_response( $response, $portal_variant_id, $name );

				if ( is_wp_error( $add_check ) ) {
					return $this->fail_mobo_order_submission( $order, 'cart_add_failed', $add_check->get_error_message() );
				}
			}

			/* Match mobomobo.ir checkout flow: refresh cart with update=true. */
			$snapshot = $this->get_mobo_cart_snapshot_json( true );
			if ( is_wp_error( $snapshot ) ) {
				return $this->fail_mobo_order_submission( $order, 'cart_snapshot_failed', $snapshot->get_error_message() );
			}

			$compare_errors = $this->compare_mobo_snapshot_with_items( $snapshot, $items );
			if ( ! empty( $compare_errors ) ) {
				return $this->fail_mobo_order_submission( $order, 'cart_compare_failed', implode( ' | ', $compare_errors ) );
			}

			$checkout_payload = $this->build_mobo_checkout_payload( $order, $snapshot );
			if ( is_wp_error( $checkout_payload ) ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_payload_failed', $checkout_payload->get_error_message() );
			}

			$checkout = $this->order_mobo_request( $order, 'POST', '/site/api/v1/cart/checkout', $checkout_payload, 'cart_checkout' );
			if ( is_wp_error( $checkout ) ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_failed', $checkout->get_error_message() );
			}

			$checkout_code = absint( wp_remote_retrieve_response_code( $checkout ) );
			if ( 200 !== $checkout_code ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_http_failed', 'Mobo checkout returned HTTP ' . $checkout_code );
			}

			$token = isset( $checkout_payload['token'] ) ? sanitize_text_field( (string) $checkout_payload['token'] ) : '';
			if ( '' === $token ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_token_missing', 'Mobo checkout token is missing.' );
			}

			$details_json = $this->get_mobo_order_details( $order, $token );
			if ( is_wp_error( $details_json ) ) {
				return $this->fail_mobo_order_submission( $order, 'details_failed', $details_json->get_error_message() );
			}

			$details = isset( $details_json['details'] ) && is_array( $details_json['details'] ) ? $details_json['details'] : array();
			if ( empty( $details ) ) {
				return $this->fail_mobo_order_submission( $order, 'details_empty', 'Mobo order details response is empty.' );
			}

			$shippings_json = $this->get_mobo_order_shippings( $order, $token );
			if ( is_wp_error( $shippings_json ) ) {
				return $this->fail_mobo_order_submission( $order, 'shippings_failed', $shippings_json->get_error_message() );
			}

			$shipping_id = $this->resolve_mobo_shipping_id( $order, $shippings_json );
			if ( is_wp_error( $shipping_id ) ) {
				return $this->fail_mobo_order_submission( $order, 'shipping_resolve_failed', $shipping_id->get_error_message() );
			}

			$shipping_payload = $this->build_mobo_order_stage_payload( $details, $shipping_id, 'wallet', null );
			$shipping = $this->order_mobo_request( $order, 'POST', '/site/api/v1/cart/shipping', $shipping_payload, 'cart_shipping' );
			if ( is_wp_error( $shipping ) ) {
				return $this->fail_mobo_order_submission( $order, 'shipping_failed', $shipping->get_error_message() );
			}

			$shipping_json = $this->decode_mobo_response_json( $shipping );
			if ( is_wp_error( $shipping_json ) ) {
				return $this->fail_mobo_order_submission( $order, 'shipping_json_failed', $shipping_json->get_error_message() );
			}
			if ( isset( $shipping_json['success'] ) && ! $this->to_bool( $shipping_json['success'] ) ) {
				return $this->fail_mobo_order_submission( $order, 'shipping_not_success', isset( $shipping_json['description'] ) ? sanitize_text_field( (string) $shipping_json['description'] ) : 'Mobo shipping response was not successful.' );
			}

			$payment_details = isset( $shipping_json['details'] ) && is_array( $shipping_json['details'] ) ? $shipping_json['details'] : $details;
			$payment_payload = $this->build_mobo_order_stage_payload( $payment_details, $shipping_id, 'wallet', null );
			$payment = $this->order_mobo_request( $order, 'POST', '/site/api/v1/cart/payment/wallet', $payment_payload, 'cart_payment_wallet' );
			if ( is_wp_error( $payment ) ) {
				return $this->fail_mobo_order_submission( $order, 'payment_failed', $payment->get_error_message() );
			}

			$payment_json = $this->decode_mobo_response_json( $payment );
			if ( is_wp_error( $payment_json ) ) {
				return $this->fail_mobo_order_submission( $order, 'payment_json_failed', $payment_json->get_error_message() );
			}

			if ( ! isset( $payment_json['success'] ) || ! $this->to_bool( $payment_json['success'] ) || ( isset( $payment_json['paid'] ) && ! $this->to_bool( $payment_json['paid'] ) ) ) {
				return $this->fail_mobo_order_submission( $order, 'payment_not_success', isset( $payment_json['description'] ) ? sanitize_text_field( (string) $payment_json['description'] ) : 'Mobo wallet payment was not successful.' );
			}

			$mobo_order_id = isset( $payment_details['id'] ) ? absint( $payment_details['id'] ) : ( isset( $details['id'] ) ? absint( $details['id'] ) : 0 );
			$this->mark_mobo_order_submission_success( $order, $mobo_order_id, $token, $shipping_id, $payment_json );

			return true;
		} finally {
			$this->release_mobo_cart_lock( $lock );
		}
	}

	private function build_order_items_payload( $order, &$errors ) {
		$errors = is_array( $errors ) ? $errors : array();
		$items  = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $line_item->get_product();
			if ( ! $product instanceof WC_Product ) {
				$errors[] = 'یکی از آیتم‌های سفارش محصول معتبر ندارد.';
				continue;
			}

			$product_id   = absint( $line_item->get_product_id() );
			$variation_id = absint( $line_item->get_variation_id() );
			$wc_id        = absint( $product->get_id() );
			$quantity     = (float) $line_item->get_quantity();
			$parent_id    = $product_id > 0 ? $product_id : $wc_id;

			$product_guid      = $this->get_remote_product_guid( $parent_id, $wc_id );
			$variant_guid      = $variation_id > 0 ? sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) ) : '';
			$portal_product_id = $this->get_portal_product_id( $parent_id, $wc_id );
			$portal_variant_id = $this->get_portal_variant_id( $variation_id, $wc_id );
			$is_mobo_item      = $this->is_order_line_item_mobo( $line_item, $product );
			$name              = wp_strip_all_tags( $line_item->get_name() );

			if ( ! $is_mobo_item ) {
				/* Mixed orders are valid. Non-Mobo items stay in WooCommerce only. */
				continue;
			}

			if ( '' === $product_guid ) {
				$errors[] = sprintf( 'محصول «%s» شناسه product_guid معتبر ندارد.', $name );
			}
			if ( $variation_id > 0 && '' === $variant_guid ) {
				$errors[] = sprintf( 'تنوع انتخاب‌شده برای محصول «%s» شناسه variant_guid معتبر ندارد.', $name );
			}
			if ( $portal_variant_id <= 0 ) {
				$errors[] = $variation_id > 0
					? sprintf( 'تنوع انتخاب‌شده برای محصول «%s» شناسه portal_variant_id معتبر ندارد.', $name )
					: sprintf( 'محصول ساده «%s» شناسه قابل خرید موبو (portal_variant_id) ندارد؛ محصول را دوباره همگام‌سازی کنید.', $name );
			}
			if ( $this->is_sync_incomplete( $parent_id, $variation_id ) ) {
				$errors[] = sprintf( 'همگام‌سازی محصول «%s» هنوز کامل نشده است.', $name );
			}

			$items[] = array(
				'cartKey'         => 'order_item_' . absint( $item_id ),
				'productId'       => $parent_id,
				'variationId'     => $variation_id,
				'wcProductId'     => $wc_id,
				'quantity'        => $quantity,
				'sku'             => sanitize_text_field( (string) $product->get_sku() ),
				'name'            => $name,
				'productGuid'     => $product_guid,
				'variantGuid'     => $variant_guid,
				'portalProductId' => $portal_product_id,
				'portalVariantId' => $portal_variant_id,
				'isMoboItem'      => $is_mobo_item,
			);
		}

		return empty( $errors ) ? $items : array();
	}

	private function build_mobo_checkout_payload( $order, $snapshot ) {
		if ( ! isset( $snapshot['cart'] ) || ! is_array( $snapshot['cart'] ) ) {
			return new WP_Error( 'mobo_core_cart_snapshot_missing', 'Mobo cart snapshot does not contain cart.' );
		}

		$cart = $snapshot['cart'];
		if ( empty( $cart['token'] ) ) {
			return new WP_Error( 'mobo_core_cart_token_missing', 'Mobo cart token is missing.' );
		}

		$contact = $this->build_mobo_order_contact_payload( $order );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$cart['description'] = '';
		$cart['name']        = $contact['senderName'];
		$cart['mobile']      = $contact['senderMobile'];
		$cart['phone']       = $contact['billingName'];
		$cart['email']       = $contact['billingMobile'];
		$cart['country']     = null;
		$cart['state']       = null;
		$cart['city']        = null;
		$cart['zipcode']     = $contact['zipcode'];
		$cart['address']     = $contact['address'];
		$cart['latitude']    = $contact['latitude'];
		$cart['longitude']   = $contact['longitude'];
		$cart['country_id']  = $contact['countryId'];
		$cart['state_id']    = $contact['stateId'];
		$cart['city_id']     = $contact['cityId'];

		return $cart;
	}

	private function build_mobo_order_contact_payload( $order ) {
		$sender_name   = trim( (string) Mobo_Core_Settings::get( 'mobo_core_mobo_order_sender_name', '' ) );
		$sender_mobile = trim( (string) Mobo_Core_Settings::get( 'mobo_core_mobo_order_sender_mobile', '' ) );

		if ( '' === $sender_name || '' === $sender_mobile ) {
			return new WP_Error( 'mobo_core_sender_missing', 'نام و شماره موبایل فرستنده/فروشگاه در تنظیمات موبو کامل نیست.' );
		}

		$billing_name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $billing_name ) {
			$billing_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		$billing_mobile = trim( (string) $order->get_billing_phone() );

		$group = $this->get_order_address_group_for_mobo( $order );
		$country_id = absint( $order->get_meta( '_mobo_' . $group . '_country_id', true ) );
		$state_id   = absint( $order->get_meta( '_mobo_' . $group . '_state_id', true ) );
		$city_id    = absint( $order->get_meta( '_mobo_' . $group . '_city_id', true ) );

		if ( ( $country_id <= 0 || $state_id <= 0 || $city_id <= 0 ) && class_exists( 'Mobo_Core_Address_Mapping' ) ) {
			$address_mapping = new Mobo_Core_Address_Mapping();
			if ( method_exists( $address_mapping, 'resolve_order_group' ) ) {
				$resolved = $address_mapping->resolve_order_group( $order, $group );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}

				$country_id = absint( isset( $resolved['countryId'] ) ? $resolved['countryId'] : 0 );
				$state_id   = absint( isset( $resolved['stateId'] ) ? $resolved['stateId'] : 0 );
				$city_id    = absint( isset( $resolved['cityId'] ) ? $resolved['cityId'] : 0 );
				if ( method_exists( $address_mapping, 'write_order_group_location_meta' ) ) {
					$address_mapping->write_order_group_location_meta( $order, $group, $resolved );
				}
			}
		}

		if ( $country_id <= 0 || $state_id <= 0 || $city_id <= 0 ) {
			return new WP_Error( 'mobo_core_location_ids_missing', 'شناسه کشور، استان یا شهر موبو روی سفارش کامل نیست. نگاشت کشور و استان و وضعیت فایل شهرهای موبو را در تب اعتبارسنجی خرید بررسی کنید.' );
		}

		$address_1 = 'shipping' === $group ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$address_2 = 'shipping' === $group ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$postcode  = 'shipping' === $group ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$address   = trim( trim( (string) $address_1 ) . ' ' . trim( (string) $address_2 ) );

		return array(
			'senderName'    => sanitize_text_field( $sender_name ),
			'senderMobile'  => sanitize_text_field( $sender_mobile ),
			'billingName'   => sanitize_text_field( $billing_name ),
			'billingMobile' => sanitize_text_field( $billing_mobile ),
			'zipcode'       => sanitize_text_field( (string) $postcode ),
			'address'       => sanitize_text_field( $address ),
			'countryId'     => $country_id,
			'stateId'       => $state_id,
			'cityId'        => $city_id,
			'latitude'      => $this->order_meta_float_or_null( $order, '_mobo_' . $group . '_city_latitude' ),
			'longitude'     => $this->order_meta_float_or_null( $order, '_mobo_' . $group . '_city_longitude' ),
		);
	}

	private function get_order_address_group_for_mobo( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'billing';
		}

		$shipping_country = method_exists( $order, 'get_shipping_country' ) ? trim( (string) $order->get_shipping_country() ) : '';
		$shipping_state   = method_exists( $order, 'get_shipping_state' ) ? trim( (string) $order->get_shipping_state() ) : '';
		$shipping_city    = method_exists( $order, 'get_shipping_city' ) ? trim( (string) $order->get_shipping_city() ) : '';
		$shipping_address = method_exists( $order, 'get_shipping_address_1' ) ? trim( (string) $order->get_shipping_address_1() ) : '';

		/*
		 * WooCommerce may keep only a default shipping country/state even when the
		 * customer did not enable a separate shipping address. Selecting shipping
		 * merely because one field is populated makes a complete billing address
		 * unusable. Use shipping only when its required address fields are complete.
		 */
		if ( '' !== $shipping_country && '' !== $shipping_state && '' !== $shipping_city && '' !== $shipping_address ) {
			return 'shipping';
		}

		return 'billing';
	}

	private function order_meta_float_or_null( $order, $key ) {
		$value = $order->get_meta( $key, true );
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function get_mobo_order_details( $order, $token ) {
		$response = $this->order_mobo_request( $order, 'GET', '/site/api/v1/cart/details?token=' . rawurlencode( (string) $token ), null, 'cart_details' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = $this->decode_mobo_response_json( $response );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( isset( $json['success'] ) && ! $this->to_bool( $json['success'] ) ) {
			return new WP_Error( 'mobo_core_details_not_success', 'Mobo details response success=false.' );
		}
		return $json;
	}

	private function get_mobo_order_shippings( $order, $token ) {
		$response = $this->order_mobo_request( $order, 'GET', '/site/api/v1/cart/shippings?token=' . rawurlencode( (string) $token ), null, 'cart_shippings' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = $this->decode_mobo_response_json( $response );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( isset( $json['success'] ) && ! $this->to_bool( $json['success'] ) ) {
			return new WP_Error( 'mobo_core_shippings_not_success', 'Mobo shippings response success=false.' );
		}
		return $json;
	}

	private function resolve_mobo_shipping_id( $order, $shippings_json ) {
		$shippings = isset( $shippings_json['shippings'] ) && is_array( $shippings_json['shippings'] ) ? $shippings_json['shippings'] : array();

		if ( class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
			$manager = new Mobo_Core_Remote_Shipping_Methods();
			$result  = $manager->resolve_shipping_id_for_order( $order, $shippings );
			if ( ! is_wp_error( $result ) ) {
				return absint( $result );
			}
			return $result;
		}

		$shipping_id = Mobo_Core_Settings::get_int( 'mobo_core_mobo_order_shipping_id', 148395514, 1, PHP_INT_MAX );
		if ( empty( $shippings ) ) {
			return $shipping_id;
		}

		foreach ( $shippings as $shipping ) {
			if ( is_array( $shipping ) && isset( $shipping['id'] ) && absint( $shipping['id'] ) === $shipping_id ) {
				return $shipping_id;
			}
		}

		return new WP_Error( 'mobo_core_shipping_id_not_available', 'شناسه روش ارسال انتخاب‌شده در لیست روش‌های ارسال موبو موجود نیست: ' . $shipping_id );
	}

	private function build_mobo_order_stage_payload( $details, $shipping_id, $mode, $gateway_id ) {
		$payload = is_array( $details ) ? $details : array();
		$payload['shipping_id'] = absint( $shipping_id );
		$payload['mode']        = sanitize_key( (string) $mode );
		$payload['gateway_id']  = null === $gateway_id ? null : absint( $gateway_id );
		return $payload;
	}

	private function order_mobo_request( $order, $method, $path, $payload, $step ) {
		$response = $this->mobo_request( $method, $path, $payload );
		if ( $this->is_auth_error_response( $response ) ) {
			$this->append_order_log( $order, $step . '_auth_retry', array(
				'httpStatus' => is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) ),
				'response'   => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $this->sanitize_order_response_body( wp_remote_retrieve_body( $response ) ),
			) );
			$auth = $this->ensure_mobo_authenticated( true );
			if ( is_wp_error( $auth ) ) {
				$this->append_order_log( $order, $step . '_auth_failed', array( 'error' => $auth->get_error_message() ) );
				return $auth;
			}
			$this->append_order_log( $order, $step . '_auth_success', array( 'cookieJar' => $this->mask_cookie_jar( $this->get_mobo_cookie_jar() ) ) );
			$response = $this->mobo_request( $method, $path, $payload );
		}

		$this->append_order_log( $order, $step, array(
			'method'     => strtoupper( (string) $method ),
			'path'       => $path,
			'httpStatus' => is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) ),
			'cookieJar'  => $this->mask_cookie_jar( $this->get_mobo_cookie_jar() ),
			'payload'    => $this->sanitize_order_log_value( $payload ),
			'response'   => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $this->sanitize_order_response_body( wp_remote_retrieve_body( $response ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'mobo_core_http_error', 'Mobo API returned HTTP ' . $code . ' for ' . $path );
		}

		return $response;
	}

	private function decode_mobo_response_json( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_response_json_error', 'Mobo response was not valid JSON.' );
		}

		return $json;
	}

	private function mark_mobo_order_submission_success( $order, $mobo_order_id, $token, $shipping_id, $payment_json ) {
		$order->update_meta_data( '_mobo_order_submitted', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_status', 'success' );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->update_meta_data( '_mobo_order_submitted_at', time() );
		$order->update_meta_data( '_mobo_order_id', absint( $mobo_order_id ) );
		$order->update_meta_data( '_mobo_order_token', sanitize_text_field( (string) $token ) );
		$order->update_meta_data( '_mobo_order_shipping_id', absint( $shipping_id ) );
		$order->update_meta_data( '_mobo_order_paid', ! empty( $payment_json['paid'] ) && $this->to_bool( $payment_json['paid'] ) ? 'yes' : 'no' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$order->save();
		$order->add_order_note( sprintf( 'اقلام موبو سفارش با موفقیت در موبو ثبت شدند. Mobo Order ID: %s', absint( $mobo_order_id ) ) );
		$this->append_order_log( $order, 'order_submission_success', array( 'moboOrderId' => absint( $mobo_order_id ), 'token' => $token, 'shippingId' => absint( $shipping_id ) ) );
	}

	private function fail_mobo_order_submission( $order, $code, $message ) {
		$message = sanitize_text_field( (string) $message );
		$order->update_meta_data( '_mobo_order_submit_status', 'failed' );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->update_meta_data( '_mobo_order_last_error_code', sanitize_key( (string) $code ) );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->update_meta_data( '_mobo_order_failed_at', time() );
		$order->save();
		$order->add_order_note( 'ثبت سفارش در موبو ناموفق بود: ' . $message );
		$this->append_order_log( $order, 'order_submission_failed', array( 'code' => $code, 'message' => $message ) );
		return new WP_Error( 'mobo_core_order_submission_failed', $message );
	}

	private function append_order_log( $order, $action, $context = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$log = $order->get_meta( '_mobo_order_submission_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'    => time(),
			'date'    => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' ),
			'action'  => sanitize_key( (string) $action ),
			'context' => $this->sanitize_order_log_value( $context ),
		);

		if ( count( $log ) > 80 ) {
			$log = array_slice( $log, -80 );
		}

		$order->update_meta_data( '_mobo_order_submission_log', $log );
		$order->save();
	}

	private function sanitize_order_response_body( $body ) {
		$body = (string) $body;
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			return $this->sanitize_order_log_value( $json );
		}
		return substr( sanitize_text_field( $body ), 0, 2000 );
	}

	private function sanitize_order_log_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$clean_key = is_string( $key ) ? sanitize_key( (string) $key ) : absint( $key );
				if ( in_array( $clean_key, array( 'password', 'userauth', 'csrf', 'csrftoken', 'csrf_token', 'cookie' ), true ) ) {
					$out[ $clean_key ] = '[masked]';
				} else {
					$out[ $clean_key ] = $this->sanitize_order_log_value( $item );
				}
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$value = sanitize_text_field( (string) $value );
		return strlen( $value ) > 2000 ? substr( $value, 0, 2000 ) . '...' : $value;
	}

	public function register_order_meta_box() {
		add_meta_box(
			'mobo_core_order_submission_box',
			'ثبت سفارش در موبو',
			array( $this, 'render_order_meta_box' ),
			array( 'shop_order', 'woocommerce_page_wc-orders' ),
			'side',
			'high'
		);
	}

	public function handle_admin_retry_mobo_order_submission() {
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0;
		if ( $order_id <= 0 || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_retry_mobo_order_submission_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
			exit;
		}

		if ( 'yes' === (string) $order->get_meta( '_mobo_order_submitted', true ) || '' !== (string) $order->get_meta( '_mobo_order_id', true ) ) {
			$order->add_order_note( 'ارسال مجدد به موبو انجام نشد، چون این سفارش قبلاً در موبو ثبت شده است.' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		if ( 'processing' !== $order->get_status() ) {
			$order->add_order_note( 'ارسال به موبو انجام نشد، چون سفارش در وضعیت درحال انجام نیست.' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'admin_manual_retry' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_queued_at' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->delete_meta_data( '_mobo_order_submit_status' );
		$order->delete_meta_data( '_mobo_order_last_error_code' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$order->delete_meta_data( '_mobo_order_failed_at' );
		$order->save();

		$this->append_order_log( $order, 'admin_manual_retry_requested', array( 'userId' => get_current_user_id() ) );
		$result = $this->submit_order_to_mobo( $order, array( 'trigger' => 'admin_manual_retry', 'userId' => get_current_user_id() ) );

		if ( true === $result && Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_auto_complete_enabled', '1' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && 'completed' !== $order->get_status() ) {
				$order->update_status( 'completed', 'سفارش با موفقیت در موبو ثبت شد و وضعیت به تکمیل شده تغییر کرد.', true );
			}
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
		exit;
	}

	public function handle_admin_clear_mobo_order_log() {
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0;
		if ( $order_id <= 0 || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ) );
		}

		check_admin_referer( 'mobo_core_clear_mobo_order_log_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->delete_meta_data( '_mobo_order_submission_log' );
			$order->save();
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	public function render_order_meta_box( $object ) {
		$order = $this->resolve_admin_order_object( $object );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>سفارش پیدا نشد.</p>';
			return;
		}

		$order_id = $order->get_id();
		$status = $this->get_order_mobo_submission_label( $order );
		$log    = $order->get_meta( '_mobo_order_submission_log', true );
		$log_array = is_array( $log ) ? $log : array();
		$copy   = wp_json_encode( $log_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$last_entry = ! empty( $log_array ) ? end( $log_array ) : array();
		$last_action = is_array( $last_entry ) && isset( $last_entry['action'] ) ? (string) $last_entry['action'] : '—';
		$last_http = '—';
		if ( is_array( $last_entry ) && isset( $last_entry['context'] ) && is_array( $last_entry['context'] ) && isset( $last_entry['context']['httpstatus'] ) ) {
			$last_http = (string) $last_entry['context']['httpstatus'];
		}

		echo '<p><strong>وضعیت:</strong> ' . esc_html( $status ) . '</p>';
		echo '<p><strong>Mobo Order ID:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_id', true ) ) . '</p>';
		echo '<p><strong>Token:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_token', true ) ) . '</p>';
		echo '<p><strong>کد خطا:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_last_error_code', true ) ) . '</p>';
		echo '<p><strong>آخرین خطا:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_last_error', true ) ) . '</p>';
		echo '<p><strong>آخرین مرحله لاگ:</strong> ' . esc_html( $last_action ) . ' / HTTP: ' . esc_html( $last_http ) . '</p>';

		echo '<textarea readonly onclick="this.select();" style="width:100%;min-height:260px;font-family:monospace;font-size:11px;direction:ltr;">' . esc_textarea( $copy ) . '</textarea>';
		echo '<p class="description">این لاگ برای کپی کردن و ارسال به پشتیبان نرم‌افزار است. رمز و cookie داخل آن ذخیره نمی‌شود. روی textarea کلیک کنید تا متن انتخاب شود.</p>';

		if ( 'yes' !== (string) $order->get_meta( '_mobo_order_submitted', true ) && '' === (string) $order->get_meta( '_mobo_order_id', true ) ) {
			$retry_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'mobo_core_retry_mobo_order_submission',
						'order_id' => $order_id,
					),
					admin_url( 'admin-post.php' )
				),
				'mobo_core_retry_mobo_order_submission_' . $order_id
			);
			echo '<p style="margin-top:10px;"><a href="' . esc_url( $retry_url ) . '" class="button button-primary" onclick="return confirm(&quot;ارسال مجدد این سفارش به موبو انجام شود؟&quot;);">ارسال مجدد به موبو</a></p>';
		}

		$clear_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'mobo_core_clear_mobo_order_log',
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'mobo_core_clear_mobo_order_log_' . $order_id
		);
		echo '<p style="margin-top:8px;"><a href="' . esc_url( $clear_url ) . '" class="button" onclick="return confirm(&quot;لاگ موبو این سفارش پاک شود؟&quot;);">پاک کردن لاگ موبو</a></p>';
	}

	public function add_legacy_order_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['mobo_core_mobo_order'] = 'موبو';
			}
		}
		if ( ! isset( $out['mobo_core_mobo_order'] ) ) {
			$out['mobo_core_mobo_order'] = 'موبو';
		}
		return $out;
	}

	public function render_legacy_order_column( $column, $post_id ) {
		if ( 'mobo_core_mobo_order' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		echo esc_html( $order instanceof WC_Order ? $this->get_order_mobo_submission_label( $order ) : '—' );
	}

	public function add_hpos_order_column( $columns ) {
		$columns['mobo_core_mobo_order'] = 'موبو';
		return $columns;
	}

	public function render_hpos_order_column( $column, $order ) {
		if ( 'mobo_core_mobo_order' !== $column ) {
			return;
		}
		$order = $this->resolve_admin_order_object( $order );
		echo esc_html( $order instanceof WC_Order ? $this->get_order_mobo_submission_label( $order ) : '—' );
	}

	private function resolve_admin_order_object( $object ) {
		if ( $object instanceof WC_Order ) {
			return $object;
		}
		if ( is_object( $object ) && isset( $object->ID ) ) {
			return wc_get_order( absint( $object->ID ) );
		}
		if ( is_numeric( $object ) ) {
			return wc_get_order( absint( $object ) );
		}
		// Read-only order-screen lookup; authorization is enforced by the surrounding admin screen.
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wc_get_order( absint( wp_unslash( $_GET['id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wc_get_order( absint( wp_unslash( $_GET['post'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return null;
	}

	private function is_order_id_in_option_queue( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return false;
		}
		$queue = $this->get_option_backed_order_queue();
		return isset( $queue[ (string) $order_id ] );
	}

	private function get_order_mobo_submission_label( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '—';
		}
		if ( 'yes' === (string) $order->get_meta( '_mobo_order_submitted', true ) ) {
			$id = absint( $order->get_meta( '_mobo_order_id', true ) );
			return $id > 0 ? 'ثبت شد #' . $id : 'ثبت شد';
		}
		$status = (string) $order->get_meta( '_mobo_order_submit_status', true );
		if ( 'not_mobo' === $status ) {
			return 'مربوط به موبو نیست';
		}
		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			return 'مربوط به موبو نیست';
		}
		if ( 'failed' === $status ) {
			return 'خطا';
		}
		if ( 'queued' === (string) $order->get_meta( '_mobo_order_submit_status', true ) || $this->is_order_id_in_option_queue( $order->get_id() ) ) {
			return 'در صف ارسال';
		}
		if ( 'yes' === (string) $order->get_meta( '_mobo_order_submit_attempted', true ) ) {
			return 'ارسال شده / منتظر نتیجه';
		}
		return 'ارسال نشده';
	}

}
