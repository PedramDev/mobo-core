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

	const ORDER_RECOVERY_OPTION_PREFIX = 'mobo_core_mobo_order_recovery_';

	/* Immutable order-line identity snapshot. Order submission must not depend on
	 * the live WooCommerce catalogue after checkout has created the order. */
	const ITEM_META_IDENTITY_CAPTURED = '_mobo_identity_captured';
	const ITEM_META_IS_MOBO           = '_mobo_identity_is_mobo';
	const ITEM_META_PRODUCT_GUID      = '_mobo_identity_product_guid';
	const ITEM_META_VARIANT_GUID      = '_mobo_identity_variant_guid';
	const ITEM_META_PORTAL_PRODUCT_ID = '_mobo_identity_portal_product_id';
	const ITEM_META_PORTAL_VARIANT_ID = '_mobo_identity_portal_variant_id';
	const ITEM_META_SKU               = '_mobo_identity_sku';
	const ITEM_META_CAPTURED_AT       = '_mobo_identity_captured_at';

	/**
	 * Debug request id, shared by all events generated during the same PHP request.
	 *
	 * @var string|null
	 */
	private $debug_request_id = null;

	/** @var string Active shared Mobo cart runtime-lock token. */
	private $active_mobo_cart_lock_token = '';

	/** @var int Active shared Mobo cart lock TTL. */
	private $active_mobo_cart_lock_ttl = 60;

	/** @var string Active per-order submission lock name. */
	private $active_order_submission_lock_name = '';

	/** @var string Active per-order submission lock token. */
	private $active_order_submission_lock_token = '';

	/** @var int Active per-order submission lock TTL. */
	private $active_order_submission_lock_ttl = 900;

	/**
	 * Register validation hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_notices' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_errors' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'validate_store_api_checkout_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'capture_checkout_line_item_identity' ), 5, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_processed_order_identity' ), 5, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_store_api_processed_order_identity' ), 4, 1 );
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
	 * Freeze the Mobo identity of a line item while WooCommerce is building the order.
	 *
	 * @param WC_Order_Item_Product $item Cart-derived order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart item values.
	 * @param WC_Order              $order Order being created.
	 * @return void
	 */
	public function capture_checkout_line_item_identity( $item, $cart_item_key, $values, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook signature.
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product = isset( $values['data'] ) && $values['data'] instanceof WC_Product ? $values['data'] : $item->get_product();
		$this->snapshot_order_line_item_identity( $item, $product );
	}

	/**
	 * Classic checkout fallback: persist identity snapshots after the order exists.
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $posted_data Checkout data.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function capture_processed_order_identity( $order_id, $posted_data = array(), $order = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WooCommerce hook signature.
		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $order_id ) );
		}
		$this->snapshot_order_line_item_identities( $order );
	}

	/**
	 * Store API / Checkout Block fallback.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function capture_store_api_processed_order_identity( $order ) {
		$this->snapshot_order_line_item_identities( $order );
	}

	/**
	 * Persist missing line-item identity snapshots and verify they survive WC CRUD.
	 *
	 * @param WC_Order|null $order Order.
	 * @return void
	 */
	private function snapshot_order_line_item_identities( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$changed = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( 'yes' === (string) $item->get_meta( self::ITEM_META_IDENTITY_CAPTURED, true ) ) {
				continue;
			}
			$this->snapshot_order_line_item_identity( $item, $item->get_product() );
			$item_id = absint( $item->save() );
			if ( $item_id > 0 ) {
				$changed = true;
			}
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Freeze one line item's catalogue identity. Both Mobo and non-Mobo results are
	 * stored so later catalogue changes cannot retroactively change order ownership.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param WC_Product|null       $product Live product when available.
	 * @return void
	 */
	private function snapshot_order_line_item_identity( $item, $product = null ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$product_id   = absint( $item->get_product_id() );
		$variation_id = absint( $item->get_variation_id() );
		$wc_id        = $product instanceof WC_Product ? absint( $product->get_id() ) : ( $variation_id > 0 ? $variation_id : $product_id );
		$parent_id    = $product_id > 0 ? $product_id : $wc_id;
		$product_guid = $this->get_remote_product_guid( $parent_id, $wc_id );
		$variant_guid = $this->get_variant_guid( $variation_id, $wc_id );
		$portal_product_id = $this->get_portal_product_id( $parent_id, $wc_id );
		$portal_variant_id = $this->get_portal_variant_id( $variation_id, $wc_id );
		$mobo_url = $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'mobo_url', true ) ) : '';
		if ( '' === $mobo_url && $wc_id > 0 ) {
			$mobo_url = sanitize_text_field( (string) get_post_meta( $wc_id, 'mobo_url', true ) );
		}
		$is_mobo = '' !== $product_guid || '' !== $variant_guid || $portal_product_id > 0 || $portal_variant_id > 0 || '' !== $mobo_url;

		$item->update_meta_data( self::ITEM_META_IDENTITY_CAPTURED, 'yes' );
		$item->update_meta_data( self::ITEM_META_IS_MOBO, $is_mobo ? 'yes' : 'no' );
		$item->update_meta_data( self::ITEM_META_CAPTURED_AT, time() );
		$item->update_meta_data( self::ITEM_META_PRODUCT_GUID, $is_mobo ? $product_guid : '' );
		$item->update_meta_data( self::ITEM_META_VARIANT_GUID, $is_mobo ? $variant_guid : '' );
		$item->update_meta_data( self::ITEM_META_PORTAL_PRODUCT_ID, $is_mobo ? $portal_product_id : 0 );
		$item->update_meta_data( self::ITEM_META_PORTAL_VARIANT_ID, $is_mobo ? $portal_variant_id : 0 );
		$item->update_meta_data( self::ITEM_META_SKU, $product instanceof WC_Product ? sanitize_text_field( (string) $product->get_sku() ) : '' );
	}

	/**
	 * Read immutable identity when available; otherwise derive a legacy live view.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param WC_Product|null       $product Product.
	 * @return array
	 */
	private function get_order_line_item_identity( $item, $product = null ) {
		$out = array(
			'captured'        => false,
			'isMobo'          => false,
			'productGuid'     => '',
			'variantGuid'     => '',
			'portalProductId' => 0,
			'portalVariantId' => 0,
			'sku'             => '',
		);
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $out;
		}

		if ( 'yes' === (string) $item->get_meta( self::ITEM_META_IDENTITY_CAPTURED, true ) ) {
			$out['captured']        = true;
			$out['isMobo']          = 'yes' === (string) $item->get_meta( self::ITEM_META_IS_MOBO, true );
			$out['productGuid']     = sanitize_text_field( (string) $item->get_meta( self::ITEM_META_PRODUCT_GUID, true ) );
			$out['variantGuid']     = sanitize_text_field( (string) $item->get_meta( self::ITEM_META_VARIANT_GUID, true ) );
			$out['portalProductId'] = absint( $item->get_meta( self::ITEM_META_PORTAL_PRODUCT_ID, true ) );
			$out['portalVariantId'] = absint( $item->get_meta( self::ITEM_META_PORTAL_VARIANT_ID, true ) );
			$out['sku']             = sanitize_text_field( (string) $item->get_meta( self::ITEM_META_SKU, true ) );
			return $out;
		}

		$product_id   = absint( $item->get_product_id() );
		$variation_id = absint( $item->get_variation_id() );
		$wc_id        = $product instanceof WC_Product ? absint( $product->get_id() ) : ( $variation_id > 0 ? $variation_id : $product_id );
		$parent_id    = $product_id > 0 ? $product_id : $wc_id;
		$out['productGuid']     = $this->get_remote_product_guid( $parent_id, $wc_id );
		$out['variantGuid']     = $this->get_variant_guid( $variation_id, $wc_id );
		$out['portalProductId'] = $this->get_portal_product_id( $parent_id, $wc_id );
		$out['portalVariantId'] = $this->get_portal_variant_id( $variation_id, $wc_id );
		$out['sku']             = $product instanceof WC_Product ? sanitize_text_field( (string) $product->get_sku() ) : '';
		$mobo_url = $parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $parent_id, 'mobo_url', true ) ) : '';
		if ( '' === $mobo_url && $wc_id > 0 ) {
			$mobo_url = sanitize_text_field( (string) get_post_meta( $wc_id, 'mobo_url', true ) );
		}
		$out['isMobo'] = '' !== $out['productGuid'] || '' !== $out['variantGuid'] || $out['portalProductId'] > 0 || $out['portalVariantId'] > 0 || '' !== $mobo_url;
		return $out;
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
	 * Checkout Block / Store API equivalent of classic checkout validation.
	 * WooCommerce explicitly supports throwing here to stop payment safely.
	 *
	 * @param WC_Order $order Draft checkout order.
	 * @return void
	 * @throws Exception When Mobo pre-purchase validation fails.
	 */
	public function validate_store_api_checkout_order( $order ) {
		/* Checkout Blocks already has a persisted order at this hook. Freeze line-item
		 * ownership before any payment can run, even when validation toggles are off. */
		$this->snapshot_order_line_item_identities( $order );

		if ( ! $this->has_active_checkout_validation_checks() ) {
			return;
		}

		$result = $this->validate_current_cart( true );
		if ( ! empty( $result['success'] ) ) {
			return;
		}

		$messages = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
		$messages = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $messages ) ) ) );
		$message  = ! empty( $messages ) ? implode( ' | ', $messages ) : 'اعتبارسنجی خرید موبو ناموفق بود. لطفاً سبد خرید را بررسی و دوباره تلاش کنید.';
		throw new Exception( $message );
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

		$mobo_cart_enabled   = $master_enabled && $mobo_cart_raw;
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
			'moboCartForcedByAutoOrder' => false,
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
		$owns_lock = false;
		$lock      = sanitize_text_field( (string) $this->active_mobo_cart_lock_token );

		if ( '' === $lock ) {
			$lock = $this->acquire_mobo_cart_lock( 'admin_login_test' );
			if ( is_wp_error( $lock ) ) {
				update_option( 'mobo_core_checkout_mobo_login_test_at', time(), false );
				update_option( 'mobo_core_checkout_mobo_login_test_result', 'ناموفق', false );
				update_option( 'mobo_core_checkout_mobo_login_test_error', $lock->get_error_message(), false );
				return $lock;
			}
			$owns_lock = true;
		}

		try {
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
		} finally {
			if ( $owns_lock ) {
				$this->release_mobo_cart_lock( $lock );
			}
		}
	}


	/**
	 * Read the authenticated Mobo wallet balance.
	 *
	 * Uses the same option-backed userauth cookie jar as checkout/order submission,
	 * so no browser cookie is stored or hard-coded in Mobo Core.
	 *
	 * Expected payload: {"success":true,"amount":1564009}
	 *
	 * @return int|float|WP_Error
	 */
	public function get_mobo_wallet_balance() {
		$owns_lock = false;
		$lock      = sanitize_text_field( (string) $this->active_mobo_cart_lock_token );

		if ( '' === $lock ) {
			$lock = $this->acquire_mobo_cart_lock( 'wallet_balance' );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			$owns_lock = true;
		}

		try {
			$auth = $this->ensure_mobo_authenticated( false );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}

			$path     = '/site/api/v1/user/billing/transactions/balance';
			$response = $this->mobo_request( 'GET', $path, null );

			if ( $this->is_auth_error_response( $response ) ) {
				$auth = $this->ensure_mobo_authenticated( true );
				if ( is_wp_error( $auth ) ) {
					return $auth;
				}
				$response = $this->mobo_request( 'GET', $path, null );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = absint( wp_remote_retrieve_response_code( $response ) );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'mobo_core_wallet_balance_http_error', 'Mobo wallet balance returned HTTP ' . $code . '.' );
			}

			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'mobo_core_wallet_balance_json_error', 'Mobo wallet balance response was not valid JSON.' );
			}

			if ( isset( $json['success'] ) && ! $this->to_bool( $json['success'] ) ) {
				$message = isset( $json['description'] ) ? sanitize_text_field( (string) $json['description'] ) : 'Mobo wallet balance response was not successful.';
				return new WP_Error( 'mobo_core_wallet_balance_not_success', $message );
			}

			if ( ! array_key_exists( 'amount', $json ) || ! is_numeric( $json['amount'] ) ) {
				return new WP_Error( 'mobo_core_wallet_balance_missing_amount', 'Mobo wallet balance response did not include a numeric amount.' );
			}

			$amount = (float) $json['amount'];
			if ( $amount >= 0 && floor( $amount ) === $amount && $amount <= PHP_INT_MAX ) {
				return (int) $amount;
			}

			return $amount;
		} finally {
			if ( $owns_lock ) {
				$this->release_mobo_cart_lock( $lock );
			}
		}
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

		$core_errors = is_array( $errors ) ? array_values( $errors ) : array();
		$filtered_errors = apply_filters( 'mobo_core_checkout_validation_errors', $core_errors, $items );
		$filtered_errors = is_array( $filtered_errors ) ? $filtered_errors : array();

		/* Automatic Mobo purchase makes the built-in preflight a financial safety
		 * boundary. Third-party filters may add diagnostics, but must not accidentally
		 * erase a core Mobo/cart validation failure and let payment continue. Preserve
		 * historical filter replacement semantics when auto-order is disabled. */
		$errors = $this->is_order_submission_enabled()
			? array_merge( $core_errors, $filtered_errors )
			: $filtered_errors;

		$sanitized_errors = array();
		foreach ( $errors as $error ) {
			if ( ! is_scalar( $error ) ) {
				continue;
			}
			$error = sanitize_text_field( (string) $error );
			if ( '' !== $error ) {
				$sanitized_errors[] = $error;
			}
		}
		$errors = array_values( array_unique( $sanitized_errors ) );

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
		 * Remote Mobo-cart validation is an optional pre-payment checkout check.
		 * Automatic order submission performs its own mandatory authenticated
		 * clear/rebuild/compare immediately before creating the Mobo order, so it
		 * must not force this shared remote cart mutation into customer checkout.
		 * Keeping the two boundaries separate prevents a transient Mobo cart/session
		 * problem from blocking WooCommerce checkout when the merchant deliberately
		 * left this optional validation disabled.
		 */
		return $this->is_enabled() && $this->is_mobo_cart_validation_enabled();
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

		$aggregate_errors = array();
		$remote_items     = $this->aggregate_mobo_items_by_variant( $items, $aggregate_errors );
		if ( ! empty( $aggregate_errors ) ) {
			return array_values( array_unique( $aggregate_errors ) );
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

			foreach ( $remote_items as $item ) {

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
		$started_at = time();
		$wait       = Mobo_Core_Settings::get_int( 'mobo_core_checkout_mobo_cart_lock_wait_seconds', 15, 0, 45 );
		$configured_ttl = Mobo_Core_Settings::get_int( 'mobo_core_checkout_mobo_cart_lock_ttl_seconds', 60, 15, 300 );
		/* One remote request must never outlive the lease that protects the shared cart. */
		$ttl = max( $configured_ttl, min( 600, $this->get_mobo_timeout() + 30 ) );

		/* Remove the pre-10.33.25 option lock. It is no longer authoritative. */
		delete_option( 'mobo_core_shared_mobo_cart_lock' );

		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return new WP_Error( 'mobo_core_shared_cart_lock_unavailable', 'Runtime lock service is unavailable.' );
		}

		do {
			$token = Mobo_Core_Lock::acquire( 'shared_mobo_cart', $ttl );
			if ( false !== $token ) {
				$this->active_mobo_cart_lock_token = sanitize_text_field( (string) $token );
				$this->active_mobo_cart_lock_ttl   = $ttl;

				/* Credential changes request a cookie reset without mutating the shared
				 * session underneath an active checkout. Apply that reset only after this
				 * request owns the same cart/session lease. */
				if ( '1' === (string) get_option( 'mobo_core_checkout_mobo_cookie_reset_pending', '0' ) ) {
					delete_option( 'mobo_core_checkout_mobo_cookie_jar' );
					if ( null !== get_option( 'mobo_core_checkout_mobo_cookie_jar', null ) ) {
						Mobo_Core_Lock::release( 'shared_mobo_cart', $this->active_mobo_cart_lock_token );
						$this->active_mobo_cart_lock_token = '';
						return new WP_Error( 'mobo_core_shared_cart_cookie_reset_failed', 'Shared Mobo session reset is pending because the cookie jar could not be cleared.' );
					}
					delete_option( 'mobo_core_checkout_mobo_cookie_reset_pending' );
				}

				$this->debug_log( 'shared_cart_lock_acquired', array( 'purpose' => $purpose, 'ttl' => $ttl, 'waited' => time() - $started_at ) );
				return $this->active_mobo_cart_lock_token;
			}

			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return new WP_Error( 'mobo_core_shared_cart_upgrade_barrier', 'Mobo cart work is paused for plugin upgrade.' );
			}

			if ( ( time() - $started_at ) >= $wait ) {
				break;
			}
			usleep( 250000 );
		} while ( true );

		return new WP_Error( 'mobo_core_shared_cart_locked', 'Shared Mobo cart is locked by another checkout.' );
	}

	/**
	 * Renew the active shared-cart lease before a remote side effect.
	 *
	 * @return true|WP_Error
	 */
	private function renew_active_mobo_cart_lock() {
		if ( '' !== (string) $this->active_order_submission_lock_token ) {
			if (
				! class_exists( 'Mobo_Core_Lock' )
				|| ! Mobo_Core_Lock::renew(
					(string) $this->active_order_submission_lock_name,
					(string) $this->active_order_submission_lock_token,
					max( 60, absint( $this->active_order_submission_lock_ttl ) )
				)
			) {
				$this->debug_log( 'order_submission_lock_lost', array( 'lockName' => $this->active_order_submission_lock_name ) );
				return new WP_Error( 'mobo_core_order_submission_lock_lost', 'Order submission lock ownership was lost; remote mutation was stopped.' );
			}
		}

		$token = sanitize_text_field( (string) $this->active_mobo_cart_lock_token );
		if ( '' === $token ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_Lock' ) || ! Mobo_Core_Lock::renew( 'shared_mobo_cart', $token, max( 15, absint( $this->active_mobo_cart_lock_ttl ) ) ) ) {
			$this->debug_log( 'shared_cart_lock_lost', array() );
			return new WP_Error( 'mobo_core_shared_cart_lock_lost', 'Shared Mobo cart lock ownership was lost; remote cart mutation was stopped.' );
		}
		return true;
	}

	/**
	 * Release the global shared Mobo cart lock.
	 *
	 * @param string $token Lock token.
	 * @return void
	 */
	private function release_mobo_cart_lock( $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' !== $token && class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::release( 'shared_mobo_cart', $token );
		}
		if ( '' !== $this->active_mobo_cart_lock_token && hash_equals( (string) $this->active_mobo_cart_lock_token, $token ) ) {
			$this->active_mobo_cart_lock_token = '';
		}
		$this->debug_log( 'shared_cart_lock_released', array() );
	}

	/**
	 * Clear all rows from the one shared Mobo cart.
	 *
	 * @return true|WP_Error
	 */
	private function clear_shared_mobo_cart() {
		$this->debug_log( 'shared_cart_clear_start', array() );

		/*
		 * The storefront cart endpoint can briefly expose the pre-DELETE snapshot
		 * unless it is explicitly refreshed with update=true. Older builds verified
		 * deletion through update=false and could therefore block checkout even after
		 * Mobo had already accepted the DELETE. Keep the safety invariant (the remote
		 * cart must be proven empty), but converge through a few bounded authoritative
		 * passes instead of treating a transient/stale read as permanent failure.
		 */
		$max_passes    = 3;
		$deleted_count = 0;

		for ( $pass = 1; $pass <= $max_passes; $pass++ ) {
			$snapshot = $this->get_mobo_cart_snapshot_json( true );

			if ( $this->is_auth_error_response( $snapshot ) ) {
				$auth = $this->ensure_mobo_authenticated( true );
				if ( is_wp_error( $auth ) ) {
					return $auth;
				}
				$snapshot = $this->get_mobo_cart_snapshot_json( true );
			}
			if ( $this->is_auth_error_response( $snapshot ) ) {
				return new WP_Error( 'mobo_core_shared_cart_verify_auth_failed', 'احراز هویت موبو هنگام آماده‌سازی سبد معتبر نبود؛ خرید متوقف شد.' );
			}
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$items = $this->parse_mobo_snapshot_items( $snapshot );
			$this->debug_log( 'shared_cart_clear_pass', array( 'pass' => $pass, 'itemCount' => count( $items ) ) );

			if ( empty( $items ) ) {
				$this->set_mobo_cart_item_map( array() );
				$this->debug_log( 'shared_cart_clear_finish', array( 'deletedCount' => $deleted_count, 'passes' => $pass ) );
				return true;
			}

			foreach ( $items as $item ) {
				$cart_item_id = isset( $item['cartItemId'] ) ? absint( $item['cartItemId'] ) : 0;
				$variant_id   = isset( $item['portalVariantId'] ) ? absint( $item['portalVariantId'] ) : 0;

				/* A malformed stale row is removable only when its cart-row ID is valid. */
				if ( $cart_item_id <= 0 ) {
					return new WP_Error( 'mobo_core_shared_cart_malformed_item', 'سبد موبو یک آیتم بدون شناسه معتبر Cart دارد و پاک‌سازی امن آن ممکن نیست.' );
				}

				$this->debug_log( 'shared_cart_delete_existing', array( 'pass' => $pass, 'cartItemId' => $cart_item_id, 'portalVariantId' => $variant_id, 'schemaValid' => ! empty( $item['schemaValid'] ) ) );
				$response = $this->mobo_request( 'DELETE', '/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ), null );

				if ( $this->is_auth_error_response( $response ) ) {
					$auth = $this->ensure_mobo_authenticated( true );
					if ( is_wp_error( $auth ) ) {
						return $auth;
					}
					$response = $this->mobo_request( 'DELETE', '/site/api/v1/cart/' . rawurlencode( (string) $cart_item_id ), null );
				}

				if ( is_wp_error( $response ) ) {
					/* A transport failure after DELETE is ambiguous: the request may have
					 * reached Mobo. Do not fail yet; the authoritative pass below decides
					 * whether this row really survived. */
					$this->debug_log( 'shared_cart_delete_ambiguous', array( 'pass' => $pass, 'cartItemId' => $cart_item_id, 'error' => $response->get_error_message() ) );
					continue;
				}

				$code = absint( wp_remote_retrieve_response_code( $response ) );
				if ( $code >= 200 && $code < 300 ) {
					$deleted_count++;
					continue;
				}

				/* 404/410 are idempotent-delete outcomes. Other HTTP failures are also
				 * resolved by the next authoritative snapshot: if the row disappeared,
				 * checkout may safely continue; if it remains, the next pass retries it. */
				$this->debug_log( 'shared_cart_delete_http_ambiguous', array( 'pass' => $pass, 'cartItemId' => $cart_item_id, 'httpStatus' => $code ) );
			}

			/* Do not verify through update=false: that can be a stale pre-DELETE view. */
			$verify = $this->get_mobo_cart_snapshot_json( true );
			if ( $this->is_auth_error_response( $verify ) ) {
				$auth = $this->ensure_mobo_authenticated( true );
				if ( is_wp_error( $auth ) ) {
					return $auth;
				}
				$verify = $this->get_mobo_cart_snapshot_json( true );
			}
			if ( $this->is_auth_error_response( $verify ) ) {
				return new WP_Error( 'mobo_core_shared_cart_verify_auth_failed', 'احراز هویت موبو هنگام تأیید خالی بودن سبد معتبر نبود؛ خرید متوقف شد.' );
			}
			if ( is_wp_error( $verify ) ) {
				return $verify;
			}

			$leftovers = $this->parse_mobo_snapshot_items( $verify );
			if ( empty( $leftovers ) ) {
				$this->set_mobo_cart_item_map( array() );
				$this->debug_log( 'shared_cart_clear_finish', array( 'deletedCount' => $deleted_count, 'passes' => $pass ) );
				return true;
			}

			$this->debug_log( 'shared_cart_clear_retry', array( 'pass' => $pass, 'leftoverCount' => count( $leftovers ) ) );

			/* Give an eventually-consistent remote cart a short bounded window to
			 * publish the DELETE before the next authoritative pass. Worst-case
			 * added latency is 450ms and is used only when rows still appear. */
			if ( $pass < $max_passes && function_exists( 'usleep' ) ) {
				usleep( 150000 * $pass );
			}
		}

		return new WP_Error(
			'mobo_core_shared_cart_not_empty_after_clear',
			'سبد مشترک موبو بعد از چند تلاش و بروزرسانی مستقیم هنوز خالی نیست؛ خرید برای جلوگیری از اضافه‌شدن آیتم ناخواسته متوقف شد.'
		);
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
		if ( isset( $json['product']['variant'] ) && is_array( $json['product']['variant'] ) && array_key_exists( 'id', $json['product']['variant'] ) ) {
			$returned_variant_id = $this->parse_positive_integer_id( $json['product']['variant']['id'] );
			if ( $returned_variant_id <= 0 ) {
				return new WP_Error( 'mobo_core_mobo_cart_add_invalid_variant_id', 'پاسخ افزودن محصول به سبد موبو شناسه Variant معتبر نداشت.' );
			}
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

	/**
	 * Parse a remote identifier without PHP's permissive numeric coercion.
	 * Accept JSON integers or digit-only decimal strings that fit in PHP_INT_MAX.
	 * Floats (including scientific-notation JSON), mixed strings, and overflow fail closed.
	 *
	 * @param mixed $value Remote identifier.
	 * @return int
	 */
	private function parse_positive_integer_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( ! is_string( $value ) ) {
			return 0;
		}

		$value = trim( $value );
		if ( '' === $value || ! preg_match( '/^[0-9]+$/', $value ) ) {
			return 0;
		}
		$value = ltrim( $value, '0' );
		if ( '' === $value ) {
			return 0;
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			return 0;
		}

		$id = (int) $value;
		return $id > 0 ? $id : 0;
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

		return $this->normalize_mobo_cart_snapshot_json( $json );
	}

	/**
	 * Normalize the storefront cart snapshot schema without weakening validation.
	 *
	 * Mobo currently serializes an empty cart as `cart: null` on some responses,
	 * while a populated cart is an object containing `items`. Treat only explicit
	 * empty representations as an empty items array. Missing cart keys and malformed
	 * non-empty structures still fail closed.
	 *
	 * @param mixed $json Decoded response body.
	 * @return array|WP_Error
	 */
	private function normalize_mobo_cart_snapshot_json( $json ) {
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_json_error', 'Mobo cart snapshot response was not valid JSON.' );
		}

		if ( ! array_key_exists( 'cart', $json ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_schema_error', 'Mobo cart snapshot did not contain a cart field.' );
		}

		/* An explicitly null/empty cart is the storefront API representation of an
		 * empty basket. This is materially different from a missing cart key. */
		if ( null === $json['cart'] || ( is_array( $json['cart'] ) && empty( $json['cart'] ) ) ) {
			$this->debug_log( 'snapshot_empty_schema_normalized', array(
				'shape' => null === $json['cart'] ? 'cart_null' : 'cart_empty',
			) );
			$json['cart'] = array( 'items' => array() );
			return $json;
		}

		if ( ! is_array( $json['cart'] ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_schema_error', 'Mobo cart snapshot did not contain a valid cart object.' );
		}

		if ( ! array_key_exists( 'items', $json['cart'] ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_schema_error', 'Mobo cart snapshot did not contain an items field.' );
		}

		if ( null === $json['cart']['items'] ) {
			$this->debug_log( 'snapshot_empty_schema_normalized', array( 'shape' => 'items_null' ) );
			$json['cart']['items'] = array();
		}

		if ( ! is_array( $json['cart']['items'] ) ) {
			return new WP_Error( 'mobo_core_mobo_cart_snapshot_schema_error', 'Mobo cart snapshot did not contain a valid items array.' );
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
				/* Preserve malformed rows as invalid entries. Callers must fail closed
				 * instead of mistaking a partially unreadable remote cart for an empty one. */
				$out[] = array(
					'cartItemId'      => 0,
					'portalVariantId' => 0,
					'quantity'        => 0.0,
					'min'             => null,
					'max'             => null,
					'status'          => array(),
					'schemaValid'     => false,
				);
				continue;
			}

			$schema_valid = true;
			$cart_item_id = array_key_exists( 'id', $cart_item ) ? $this->parse_positive_integer_id( $cart_item['id'] ) : 0;
			if ( $cart_item_id <= 0 ) {
				$schema_valid = false;
			}

			$variant_id = 0;
			$status     = array();
			$variant    = array();
			if ( isset( $cart_item['product'] ) && is_array( $cart_item['product'] ) && isset( $cart_item['product']['variant'] ) && is_array( $cart_item['product']['variant'] ) ) {
				$variant = $cart_item['product']['variant'];
				$variant_id = array_key_exists( 'id', $variant ) ? $this->parse_positive_integer_id( $variant['id'] ) : 0;
				if ( $variant_id <= 0 ) {
					$schema_valid = false;
				}
			} else {
				$schema_valid = false;
			}

			$quantity = 0.0;
			if ( isset( $cart_item['quantity'] ) && is_scalar( $cart_item['quantity'] ) && is_numeric( $cart_item['quantity'] ) ) {
				$quantity = (float) $cart_item['quantity'];
				if ( ! is_finite( $quantity ) || $quantity < 0 ) {
					$schema_valid = false;
					$quantity = 0.0;
				}
			} else {
				$schema_valid = false;
			}

			$minimum = null;
			if ( array_key_exists( 'min', $variant ) && null !== $variant['min'] ) {
				if ( is_scalar( $variant['min'] ) && is_numeric( $variant['min'] ) ) {
					$minimum = (float) $variant['min'];
					if ( ! is_finite( $minimum ) || $minimum < 0 ) {
						$schema_valid = false;
						$minimum = null;
					}
				} else {
					$schema_valid = false;
				}
			}

			$maximum = null;
			if ( array_key_exists( 'max', $variant ) && null !== $variant['max'] ) {
				if ( is_scalar( $variant['max'] ) && is_numeric( $variant['max'] ) ) {
					$maximum = (float) $variant['max'];
					if ( ! is_finite( $maximum ) || $maximum < 0 ) {
						$schema_valid = false;
						$maximum = null;
					}
				} else {
					$schema_valid = false;
				}
			}

			if ( array_key_exists( 'status', $variant ) ) {
				if ( ! is_array( $variant['status'] ) ) {
					$schema_valid = false;
				} else {
					foreach ( $variant['status'] as $status_value ) {
						if ( ! is_scalar( $status_value ) ) {
							$schema_valid = false;
							continue;
						}
						$status[] = sanitize_key( (string) $status_value );
					}
				}
			}

			if ( $cart_item_id <= 0 || $variant_id <= 0 ) {
				$schema_valid = false;
			}

			$out[] = array(
				'cartItemId'      => $cart_item_id,
				'portalVariantId' => $variant_id,
				'quantity'        => $quantity,
				'min'             => $minimum,
				'max'             => $maximum,
				'status'          => $status,
				'schemaValid'     => $schema_valid,
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
		$names    = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( empty( $item['isMoboItem'] ) ) {
				continue;
			}
			$variant_id = absint( isset( $item['portalVariantId'] ) ? $item['portalVariantId'] : 0 );
			$quantity   = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0.0;
			if ( $variant_id <= 0 ) {
				continue;
			}
			$key = (string) $variant_id;
			$expected[ $key ] = isset( $expected[ $key ] ) ? $expected[ $key ] + $quantity : $quantity;
			if ( ! isset( $names[ $key ] ) ) {
				$names[ $key ] = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : 'محصول';
			}
		}

		foreach ( $parsed as $row ) {
			$variant_id = isset( $row['portalVariantId'] ) ? absint( $row['portalVariantId'] ) : 0;
			$cart_id    = isset( $row['cartItemId'] ) ? absint( $row['cartItemId'] ) : 0;
			if ( empty( $row['schemaValid'] ) || $variant_id <= 0 || $cart_id <= 0 ) {
				$errors[] = 'سبد موبو یک آیتم ناشناخته یا ناقص دارد؛ خرید برای جلوگیری از ارسال آیتم اشتباه متوقف شد.';
				continue;
			}
			$key = (string) $variant_id;
			if ( ! isset( $expected[ $key ] ) ) {
				$errors[] = 'سبد موبو حاوی آیتمی است که در سبد WooCommerce این سفارش وجود ندارد (Variant #' . $variant_id . ').';
				continue;
			}

			/* Validate every duplicate row independently. Keeping only the first row's
			 * status used to let a later unavailable/malformed state disappear during
			 * aggregation. */
			$row_status = isset( $row['status'] ) && is_array( $row['status'] ) ? $row['status'] : array();
			if ( ! empty( $row_status ) && ! in_array( 'approved', $row_status, true ) ) {
				$errors[] = 'یکی از ردیف‌های Variant #' . $variant_id . ' در سبد موبو وضعیت قابل تأیید ندارد.';
			}

			if ( ! isset( $remote[ $key ] ) ) {
				$remote[ $key ] = $row;
				$remote[ $key ]['quantity'] = isset( $row['quantity'] ) ? (float) $row['quantity'] : 0.0;
			} else {
				/* If Mobo returns duplicate rows for one variant, compare the total and
				 * retain the strictest usable bounds from every row. */
				$remote[ $key ]['quantity'] += isset( $row['quantity'] ) ? (float) $row['quantity'] : 0.0;
				$remote[ $key ]['duplicateRows'] = absint( isset( $remote[ $key ]['duplicateRows'] ) ? $remote[ $key ]['duplicateRows'] : 1 ) + 1;

				$row_min = isset( $row['min'] ) && null !== $row['min'] ? (float) $row['min'] : null;
				$old_min = isset( $remote[ $key ]['min'] ) && null !== $remote[ $key ]['min'] ? (float) $remote[ $key ]['min'] : null;
				if ( null !== $row_min ) {
					$remote[ $key ]['min'] = null === $old_min ? $row_min : max( $old_min, $row_min );
				}

				$row_max = isset( $row['max'] ) && null !== $row['max'] ? (float) $row['max'] : null;
				$old_max = isset( $remote[ $key ]['max'] ) && null !== $remote[ $key ]['max'] ? (float) $remote[ $key ]['max'] : null;
				if ( null !== $row_max && $row_max > 0 ) {
					$remote[ $key ]['max'] = null === $old_max || $old_max <= 0 ? $row_max : min( $old_max, $row_max );
				}
			}
		}

		foreach ( $expected as $key => $expected_qty ) {
			$name = isset( $names[ $key ] ) ? $names[ $key ] : 'محصول';
			if ( ! isset( $remote[ $key ] ) ) {
				$errors[] = sprintf( 'محصول «%s» بعد از آماده‌سازی در سبد موبو پیدا نشد.', $name );
				continue;
			}

			$remote_qty = isset( $remote[ $key ]['quantity'] ) ? (float) $remote[ $key ]['quantity'] : 0.0;
			if ( abs( $remote_qty - (float) $expected_qty ) > 0.0001 ) {
				$errors[] = sprintf( 'تعداد محصول «%s» در سبد موبو با سبد سایت برابر نیست. تعداد درخواستی: %s، تعداد موبو: %s', $name, wc_format_decimal( $expected_qty ), wc_format_decimal( $remote_qty ) );
			}

			$status = isset( $remote[ $key ]['status'] ) && is_array( $remote[ $key ]['status'] ) ? $remote[ $key ]['status'] : array();
			if ( ! empty( $status ) && ! in_array( 'approved', $status, true ) ) {
				$errors[] = sprintf( 'محصول «%s» در موبو وضعیت قابل تایید ندارد.', $name );
			}
			$minimum = isset( $remote[ $key ]['min'] ) && null !== $remote[ $key ]['min'] ? (float) $remote[ $key ]['min'] : null;
			$maximum = isset( $remote[ $key ]['max'] ) && null !== $remote[ $key ]['max'] ? (float) $remote[ $key ]['max'] : null;
			if ( null !== $minimum && $minimum > 0 && (float) $expected_qty < $minimum ) {
				$errors[] = sprintf( 'حداقل تعداد قابل خرید محصول «%s» در موبو %s است.', $name, wc_format_decimal( $minimum ) );
			}
			if ( null !== $maximum && $maximum > 0 && (float) $expected_qty > $maximum ) {
				$errors[] = sprintf( 'حداکثر تعداد قابل خرید محصول «%s» در موبو %s است.', $name, wc_format_decimal( $maximum ) );
			}
		}

		$this->debug_log( 'shared_cart_snapshot_compared', array( 'expected' => $expected, 'remoteCount' => count( $remote ), 'parsedRows' => count( $parsed ), 'errorCount' => count( $errors ) ) );
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

		$lease = $this->renew_active_mobo_cart_lock();
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}

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

		$lease = $this->renew_active_mobo_cart_lock();
		if ( is_wp_error( $lease ) ) {
			return $lease;
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

		if ( ! $this->set_mobo_cookie_jar( $jar ) ) {
			return new WP_Error( 'mobo_core_mobo_cookie_persist_failed', 'Mobo login succeeded but the shared session cookie could not be persisted locally.' );
		}
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
		$lease = $this->renew_active_mobo_cart_lock();
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}

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

		$this->debug_log( 'api_request', array( 'method' => strtoupper( (string) $method ), 'path' => $this->sanitize_mobo_log_path( $path ), 'payload' => $this->sanitize_debug_payload( $payload ), 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );

		$response = wp_remote_request( $this->mobo_url( $path ), $args );

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'api_response_error', array( 'method' => strtoupper( (string) $method ), 'path' => $this->sanitize_mobo_log_path( $path ), 'error' => $response->get_error_message() ) );
		}

		if ( ! is_wp_error( $response ) ) {
			$jar = $this->merge_cookie_jar_from_response( $jar, $response );
			if ( ! $this->set_mobo_cookie_jar( $jar ) ) {
				$this->debug_log( 'api_cookie_persist_failed', array( 'method' => strtoupper( (string) $method ), 'path' => $this->sanitize_mobo_log_path( $path ) ) );
				return new WP_Error(
					'mobo_core_mobo_cookie_persist_failed',
					'Mobo responded but the shared session cookie state could not be persisted locally.',
					array( 'requestMayHaveReachedServer' => true, 'path' => $this->sanitize_mobo_log_path( $path ) )
				);
			}
			$this->debug_log( 'api_response', array( 'method' => strtoupper( (string) $method ), 'path' => $this->sanitize_mobo_log_path( $path ), 'httpStatus' => absint( wp_remote_retrieve_response_code( $response ) ), 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
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

		/* The generic validation URL may intentionally point to a third-party service.
		 * Portal credentials must never follow that configurable URL. Attach Token/X-SEC
		 * only when the destination is exactly the configured Portal API origin. */
		if ( $this->external_validation_uses_portal_origin( $url ) ) {
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
	 * Whether a configurable validation endpoint is the exact Portal API origin.
	 *
	 * @param string $url Validation URL.
	 * @return bool
	 */
	private function external_validation_uses_portal_origin( $url ) {
		$portal = apply_filters( 'mobo_core_api_base_url', '' );
		if ( ! is_string( $portal ) || '' === trim( $portal ) ) {
			$portal = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		}

		$target_parts = wp_parse_url( esc_url_raw( trim( (string) $url ) ) );
		$portal_parts = wp_parse_url( esc_url_raw( trim( (string) $portal ) ) );
		if ( ! is_array( $target_parts ) || ! is_array( $portal_parts ) ) {
			return false;
		}

		$target_scheme = isset( $target_parts['scheme'] ) ? strtolower( (string) $target_parts['scheme'] ) : '';
		$portal_scheme = isset( $portal_parts['scheme'] ) ? strtolower( (string) $portal_parts['scheme'] ) : '';
		$target_host   = isset( $target_parts['host'] ) ? strtolower( rtrim( (string) $target_parts['host'], '.' ) ) : '';
		$portal_host   = isset( $portal_parts['host'] ) ? strtolower( rtrim( (string) $portal_parts['host'], '.' ) ) : '';
		if ( '' === $target_scheme || '' === $portal_scheme || '' === $target_host || '' === $portal_host ) {
			return false;
		}
		if ( $target_scheme !== $portal_scheme || $target_host !== $portal_host ) {
			return false;
		}

		$target_port = isset( $target_parts['port'] ) ? absint( $target_parts['port'] ) : ( 'https' === $target_scheme ? 443 : ( 'http' === $target_scheme ? 80 : 0 ) );
		$portal_port = isset( $portal_parts['port'] ) ? absint( $portal_parts['port'] ) : ( 'https' === $portal_scheme ? 443 : ( 'http' === $portal_scheme ? 80 : 0 ) );
		return $target_port > 0 && $target_port === $portal_port;
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
	 * Get saved variant GUID for variable or simple Mobo products.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $product_id Product/variation ID fallback.
	 * @return string
	 */
	private function get_variant_guid( $variation_id, $product_id ) {
		$keys = array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' );
		$ids  = array_values( array_unique( array_filter( array( absint( $variation_id ), absint( $product_id ) ) ) ) );
		foreach ( $ids as $id ) {
			foreach ( $keys as $key ) {
				$value = sanitize_text_field( (string) get_post_meta( $id, $key, true ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
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
	 * Parse an explicit boolean acknowledgement without treating unknown values as false.
	 * Financial response fields use this tri-state parser so null/pending/garbage can
	 * never reopen automatic Wallet retry after an irreversible request.
	 *
	 * @param mixed $value Value.
	 * @return array{known:bool,value:bool}
	 */
	private function parse_explicit_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return array( 'known' => true, 'value' => $value );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$number = (float) $value;
			if ( is_finite( $number ) && ( 0.0 === $number || 1.0 === $number ) ) {
				return array( 'known' => true, 'value' => 1.0 === $number );
			}
			return array( 'known' => false, 'value' => false );
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return array( 'known' => true, 'value' => true );
			}
			if ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
				return array( 'known' => true, 'value' => false );
			}
		}

		return array( 'known' => false, 'value' => false );
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
	 * @return bool
	 */
	private function set_mobo_cookie_jar( $jar ) {
		$jar = is_array( $jar ) ? $jar : array();
		update_option( 'mobo_core_checkout_mobo_cookie_jar', $jar, false );
		$stored = get_option( 'mobo_core_checkout_mobo_cookie_jar', array() );
		return is_array( $stored ) && $stored === $jar;
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

			$cart_item_id = isset( $cart_item['id'] ) ? $this->parse_positive_integer_id( $cart_item['id'] ) : 0;
			$variant_id   = 0;

			if ( isset( $cart_item['product'] ) && is_array( $cart_item['product'] ) && isset( $cart_item['product']['variant'] ) && is_array( $cart_item['product']['variant'] ) && isset( $cart_item['product']['variant']['id'] ) ) {
				$variant_id = $this->parse_positive_integer_id( $cart_item['product']['variant']['id'] );
			}

			if ( $cart_item_id <= 0 || $variant_id <= 0 ) {
				continue;
			}

			$quantity = null;
			if ( isset( $cart_item['quantity'] ) && is_numeric( $cart_item['quantity'] ) ) {
				$parsed_quantity = (float) $cart_item['quantity'];
				if ( is_finite( $parsed_quantity ) && $parsed_quantity >= 0 ) {
					$quantity = $parsed_quantity;
				}
			}

			$map[ (string) $variant_id ] = array(
				'cartItemId' => $cart_item_id,
				'quantity'   => $quantity,
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
			'uri'         => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) ) : '',
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

				if ( $this->is_sensitive_log_key( $key ) ) {
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

		if ( 'invalid' === sanitize_key( (string) ( isset( $scope['status'] ) ? $scope['status'] : '' ) ) ) {
			/* Queue only for durable local inspection; the processor will mark review and
			 * must not perform any Mobo network mutation for an unknown legacy line. */
			$this->queue_mobo_order_id_for_later(
				absint( $order_id ),
				array( 'trigger' => 'status_processing_invalid_scope', 'oldStatus' => $old_status, 'newStatus' => $new_status )
			);
			return;
		}

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

		$token = str_replace( '-', '', wp_generate_uuid4() );
		$locked = $this->with_order_queue_state_lock(
			function () use ( $order_id, $context, $token ) {
				$queue = $this->get_option_backed_order_queue();
				$queue[ (string) $order_id ] = array(
					'orderId'    => $order_id,
					'queueToken' => $token,
					'queuedAt'   => time(),
					'context'    => $this->sanitize_order_log_value( is_array( $context ) ? $context : array() ),
				);
				return $this->save_option_backed_order_queue( $queue );
			}
		);

		if ( true !== $locked ) {
			/*
			 * Do not depend on WP-Cron for durability. Sites commonly disable WP-Cron
			 * and run only Mobo's Real Cron/Self Runner. A unique recovery option can
			 * be discovered by that runner without touching the WC_Order during the
			 * status-transition hook and without a shared read-modify-write race.
			 */
			$persisted = $this->persist_order_queue_recovery_marker( $order_id, $context, $token );
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time() + 10, 'mobo_core_queue_mobo_order_submission', array( $order_id, is_array( $context ) ? $context : array() ) );
			}
			if ( $persisted && class_exists( 'Mobo_Core_Self_Runner' ) ) {
				Mobo_Core_Self_Runner::kick( 'order-submission-recovery', false );
			}
			return $persisted;
		}

		if ( class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'order-submission-queued', false );
		}

		return true;
	}

	/**
	 * Persist an independent recovery marker when the shared queue lock is busy.
	 *
	 * @param int    $order_id Order ID.
	 * @param array  $context Context.
	 * @param string $token Queue token.
	 * @return bool
	 */
	private function persist_order_queue_recovery_marker( $order_id, $context, $token ) {
		$order_id = absint( $order_id );
		$token    = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		if ( $order_id <= 0 || '' === $token ) {
			return false;
		}

		$option_name = self::ORDER_RECOVERY_OPTION_PREFIX . $order_id . '_' . substr( strtolower( $token ), 0, 24 );
		$value = array(
			'orderId'    => $order_id,
			'queueToken' => $token,
			'queuedAt'   => time(),
			'context'    => $this->sanitize_order_log_value( is_array( $context ) ? $context : array() ),
		);

		return (bool) add_option( $option_name, $value, '', 'no' );
	}

	/**
	 * Read a bounded set of independent order recovery markers.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_order_queue_recovery_items( $limit ) {
		global $wpdb;

		$limit = max( 1, min( 10, absint( $limit ) ) );
		$like  = $wpdb->esc_like( self::ORDER_RECOVERY_OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded recovery scan of plugin-owned non-autoload options.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $like, $limit ), ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$option_name = isset( $row['option_name'] ) ? sanitize_key( (string) $row['option_name'] ) : '';
			$item        = isset( $row['option_value'] ) ? maybe_unserialize( $row['option_value'] ) : array();
			$order_id    = is_array( $item ) && isset( $item['orderId'] ) ? absint( $item['orderId'] ) : 0;
			if ( '' === $option_name || $order_id <= 0 ) {
				if ( '' !== $option_name ) {
					delete_option( $option_name );
				}
				continue;
			}
			$out[] = array(
				'queueKey'       => null,
				'queueToken'     => '',
				'recoveryOption' => $option_name,
				'orderId'        => $order_id,
				'context'        => isset( $item['context'] ) && is_array( $item['context'] ) ? $item['context'] : array(),
			);
		}

		return $out;
	}

	/**
	 * Remove the exact durable marker(s) owned by one queue snapshot.
	 *
	 * @param array $queue_item Queue item.
	 * @return void
	 */
	private function cleanup_order_queue_item( $queue_item ) {
		if ( ! is_array( $queue_item ) ) {
			return;
		}
		if ( array_key_exists( 'queueKey', $queue_item ) && null !== $queue_item['queueKey'] ) {
			$this->remove_order_queue_item_if_token_matches( $queue_item['queueKey'], isset( $queue_item['queueToken'] ) ? $queue_item['queueToken'] : '' );
		}
		if ( ! empty( $queue_item['recoveryOption'] ) ) {
			delete_option( sanitize_key( (string) $queue_item['recoveryOption'] ) );
		}
	}

	/**
	 * Execute a very short queue-state mutation under a runtime lock.
	 *
	 * @param callable $callback Callback.
	 * @return mixed|false
	 */
	private function with_order_queue_state_lock( $callback ) {
		if ( ! is_callable( $callback ) ) {
			return false;
		}

		/*
		 * Queue persistence is a millisecond-scale state mutation, not runtime work.
		 * It must remain writable while an upgrade barrier is draining workers, otherwise
		 * an order status transition can be lost before the new plugin version starts.
		 * A connection-scoped MySQL/MariaDB advisory lock gives us atomic read-modify-write
		 * without participating in Mobo_Core_Lock's upgrade barrier.
		 */
		global $wpdb;
		$db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$lock_name = 'mobo_order_q_' . substr( hash( 'sha256', $db_name . '|' . (string) $wpdb->prefix ), 0, 40 );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic option queue guard.
		if ( '1' !== (string) $acquired ) {
			return false;
		}

		try {
			return call_user_func( $callback );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the connection-scoped queue guard.
		}
	}

	/**
	 * Stable token for a queue snapshot, including pre-token legacy rows.
	 *
	 * @param mixed  $item Queue item.
	 * @param string $key Queue key.
	 * @return string
	 */
	private function order_queue_item_token( $item, $key ) {
		if ( is_array( $item ) && ! empty( $item['queueToken'] ) ) {
			return sanitize_text_field( (string) $item['queueToken'] );
		}
		$encoded = wp_json_encode( array( 'key' => (string) $key, 'item' => $item ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return 'legacy-' . sha1( false === $encoded ? serialize( $item ) : $encoded );
	}

	/**
	 * Remove only the exact queue snapshot processed by this worker.
	 * A concurrent re-enqueue of the same order receives a new token and is preserved.
	 *
	 * @param string|int $key Queue key.
	 * @param string     $expected_token Snapshot token.
	 * @return bool
	 */
	private function remove_order_queue_item_if_token_matches( $key, $expected_token ) {
		$key            = (string) $key;
		$expected_token = sanitize_text_field( (string) $expected_token );
		if ( '' === $key || '' === $expected_token ) {
			return false;
		}

		$result = $this->with_order_queue_state_lock(
			function () use ( $key, $expected_token ) {
				$queue = $this->get_option_backed_order_queue();
				if ( ! array_key_exists( $key, $queue ) ) {
					return true;
				}
				$current_token = $this->order_queue_item_token( $queue[ $key ], $key );
				if ( ! hash_equals( $expected_token, $current_token ) ) {
					return false;
				}
				unset( $queue[ $key ] );
				return $this->save_option_backed_order_queue( $queue );
			}
		);

		return true === $result;
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
	 * @return bool
	 */
	private function save_option_backed_order_queue( $queue ) {
		$option_name = 'mobo_core_mobo_order_submission_queue';
		$queue       = is_array( $queue ) ? $queue : array();

		if ( empty( $queue ) ) {
			delete_option( $option_name );
			$current = get_option( $option_name, null );
			return null === $current || false === $current || array() === $current;
		}

		update_option( $option_name, $queue, false );
		$current = get_option( $option_name, array() );
		return is_array( $current ) && $current === $queue;
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
		if ( in_array( $status, array( 'queued', 'running', 'uncertain' ), true ) ) {
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
				$this->remove_order_queue_item_if_token_matches( $key, $this->order_queue_item_token( $item, $key ) );
				continue;
			}
			$queue_items[] = array(
				'queueKey'       => $key,
				'queueToken'     => $this->order_queue_item_token( $item, $key ),
				'recoveryOption' => '',
				'orderId'        => $order_id,
				'context'        => is_array( $item ) && isset( $item['context'] ) && is_array( $item['context'] ) ? $item['context'] : array(),
			);
		}

		$seen_order_ids = array();
		foreach ( $queue_items as $item ) {
			$seen_id = absint( isset( $item['orderId'] ) ? $item['orderId'] : 0 );
			if ( $seen_id > 0 ) {
				$seen_order_ids[ $seen_id ] = true;
			}
		}
		foreach ( $this->get_order_queue_recovery_items( $limit + 1 ) as $recovery_item ) {
			$recovery_order_id = absint( isset( $recovery_item['orderId'] ) ? $recovery_item['orderId'] : 0 );
			if ( $recovery_order_id > 0 && isset( $seen_order_ids[ $recovery_order_id ] ) ) {
				/* The shared queue now contains this order; its emergency marker is redundant. */
				$this->cleanup_order_queue_item( $recovery_item );
				continue;
			}
			if ( $recovery_order_id > 0 ) {
				$seen_order_ids[ $recovery_order_id ] = true;
			}
			$queue_items[] = $recovery_item;
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
						'queueKey'       => null,
						'queueToken'     => '',
						'recoveryOption' => '',
						'orderId'        => absint( $legacy_order_id ),
						'context'        => array( 'legacyMetaQueue' => true ),
					);
				}
			}
		}

		if ( empty( $queue_items ) ) {
			$result['status'] = 'empty';
			return $result;
		}

		foreach ( $queue_items as $queue_item ) {
			/* The setting can be disabled while a batch of up to five orders is already
			 * running. Re-check it before every order so a mid-batch admin change is
			 * respected without consuming/removing the durable queue entry. */
			if ( ! $this->is_order_submission_enabled() ) {
				$result['status']    = 'disabled';
				$result['remaining'] = true;
				break;
			}

			$order_id = absint( $queue_item['orderId'] );
			$order    = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				$result['skipped']++;
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}

			if ( $this->order_was_already_sent_to_mobo( $order ) ) {
				$result['skipped']++;
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}

			if ( 'processing' !== sanitize_key( (string) $order->get_status() ) ) {
				/* A cancelled/refunded/on-hold order must not remain visually "queued" after
				 * its durable queue marker is removed. Record a terminal pre-payment abort. */
				if ( 'queued' === sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) ) ) {
					$order->delete_meta_data( '_mobo_order_submit_queued' );
					$order->delete_meta_data( '_mobo_order_submit_context' );
					$order->delete_meta_data( '_mobo_order_submit_attempted' );
					$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
					$order->update_meta_data( '_mobo_order_submit_status', 'aborted' );
					$order->update_meta_data( '_mobo_order_last_error_code', 'order_not_processing' );
					$order->update_meta_data( '_mobo_order_last_error', 'Order left processing before Mobo submission started.' );
					$order->save();
					$this->append_order_log( $order, 'queued_order_aborted_not_processing', array( 'status' => sanitize_key( (string) $order->get_status() ) ) );
				}
				$result['skipped']++;
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}

			$attempt_state = $this->get_order_submission_attempt_state( $order );
			if ( 'running_recent' === $attempt_state ) {
				/* The original request may still be alive. Never overlap a second purchase attempt. */
				$result['skipped']++;
				$result['remaining'] = true;
				continue;
			}
			if ( 'running_stale' === $attempt_state || 'uncertain' === $attempt_state ) {
				$this->mark_order_submission_uncertain( $order );
				$result['skipped']++;
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}

			$scope = $this->get_order_mobo_item_scope( $order );
			if ( 'invalid' === sanitize_key( (string) ( isset( $scope['status'] ) ? $scope['status'] : '' ) ) ) {
				$result['skipped']++;
				$this->mark_order_scope_invalid( $order, $scope, 'queue_processor' );
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}
			if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
				$result['skipped']++;
				$this->mark_order_as_not_mobo( $order, $scope, 'queue_processor' );
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}

			$context = is_array( $queue_item['context'] ) ? $queue_item['context'] : array();
			$context['processor'] = $source;

			$result['processed']++;
			$submit = $this->submit_order_to_mobo( $order, $context );

			if ( is_wp_error( $submit ) && in_array( $submit->get_error_code(), array( 'mobo_core_order_submission_busy', 'mobo_core_order_submission_lock_unavailable', 'mobo_core_order_submission_state_persist_failed', 'mobo_core_order_submission_deferred' ), true ) ) {
				$result['skipped']++;
				$result['remaining'] = true;
				continue;
			}

			$this->cleanup_order_queue_item( $queue_item );

			if ( is_wp_error( $submit ) && in_array( $submit->get_error_code(), array( 'mobo_core_order_submission_superseded', 'mobo_core_order_submission_uncertain', 'mobo_core_order_submission_aborted', 'mobo_core_order_not_processing' ), true ) ) {
				$result['skipped']++;
				continue;
			}

			if ( true === $submit ) {
				$result['success']++;
				$completed_order = wc_get_order( $order_id );
				if ( $completed_order instanceof WC_Order ) {
					$purchase_fingerprint = sanitize_text_field( (string) $completed_order->get_meta( '_mobo_order_purchase_business_fingerprint', true ) );
					if ( '' !== $purchase_fingerprint ) {
						$this->mark_post_payment_order_divergence( $order_id, array( 'fingerprint' => $purchase_fingerprint ) );
						$completed_order = wc_get_order( $order_id );
					}
				}
				$post_payment_diverged = $completed_order instanceof WC_Order && 'yes' === (string) $completed_order->get_meta( '_mobo_order_post_payment_diverged', true );
				$current_status        = $completed_order instanceof WC_Order ? sanitize_key( (string) $completed_order->get_status() ) : '';

				/* Never overwrite a cancellation/on-hold/completion that raced with the remote
				 * Wallet call. Mobo success is durable, but local status must be preserved and
				 * reviewed instead of being silently forced back to processing/completed. */
				if ( $completed_order instanceof WC_Order && ( $post_payment_diverged || 'processing' !== $current_status ) ) {
					$completed_order->update_meta_data( '_mobo_order_requires_review', 'yes' );
					$completed_order->save();
					$this->append_order_log(
						$completed_order,
						'order_submission_status_transition_suppressed',
						array( 'status' => $current_status, 'postPaymentDiverged' => $post_payment_diverged )
					);
				} elseif ( $completed_order instanceof WC_Order && 'mixed' === (string) $scope['status'] ) {
					$note = 'اقلام موبو با موفقیت در موبو ثبت شدند. این سفارش ترکیبی است و برای پردازش اقلام غیرموبو در وضعیت در حال انجام باقی می‌ماند.';
					$completed_order->update_meta_data( '_mobo_order_scope_status', 'mixed' );
					$completed_order->update_meta_data( '_mobo_order_kept_processing', 'yes' );
					$completed_order->save();
					$completed_order->add_order_note( $note );

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
					&& Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_auto_complete_enabled', '1' )
				) {
					$completed_order->update_status( 'completed', 'تمام اقلام سفارش موبو بودند؛ سفارش با موفقیت در موبو ثبت شد و وضعیت به تکمیل شده تغییر کرد.', true );
				}
			} else {
				$result['failed']++;
			}
		}

		if ( ! empty( $this->get_option_backed_order_queue() ) || ! empty( $this->get_order_queue_recovery_items( 1 ) ) ) {
			$result['remaining'] = true;
		}
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
		$mobo_id   = absint( $order->get_meta( '_mobo_order_id', true ) );

		return 'yes' === $submitted || $mobo_id > 0;
	}

	/**
	 * Classify a previous one-shot submission attempt without guessing that it succeeded.
	 *
	 * @param WC_Order $order Order.
	 * @return string none|running_recent|running_stale|uncertain
	 */
	private function get_order_submission_attempt_state( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'uncertain';
		}
		$status = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		if ( 'uncertain' === $status ) {
			return 'uncertain';
		}
		if ( 'running' !== $status || 'yes' !== (string) $order->get_meta( '_mobo_order_submit_attempted', true ) ) {
			return 'none';
		}
		$attempted_at = absint( $order->get_meta( '_mobo_order_submit_attempted_at', true ) );
		$stale_after  = max( 120, absint( apply_filters( 'mobo_core_order_submission_uncertain_after_seconds', 600, $order->get_id() ) ) );
		return $attempted_at > 0 && ( time() - $attempted_at ) < $stale_after ? 'running_recent' : 'running_stale';
	}

	/**
	 * A request may have created/paid the remote order before local success was saved.
	 * Automatic retry is unsafe; require an explicit admin verification/retry.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $code    Diagnostic code.
	 * @param string   $message Safe operator-facing message.
	 * @param array    $context Optional log context.
	 * @return WP_Error|void
	 */
	private function mark_order_submission_uncertain( $order, $code = 'submission_state_uncertain', $message = '', $context = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$code = sanitize_key( (string) $code );
		if ( '' === $code ) {
			$code = 'submission_state_uncertain';
		}
		$message = sanitize_text_field( (string) $message );
		if ( '' === $message ) {
			$message = 'نتیجه آخرین تلاش ارسال به موبو نامشخص است. قبل از ارسال مجدد، وجود سفارش در موبو بررسی شود.';
		}
		$context = is_array( $context ) ? $context : array();

		$already = 'uncertain' === sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		$this->persist_order_payment_guard(
			absint( $order->get_id() ),
			'uncertain',
			array(
				'code'        => $code,
				'attemptedAt' => absint( $order->get_meta( '_mobo_order_submit_attempted_at', true ) ),
			)
		);
		$order->update_meta_data( '_mobo_order_submit_status', 'uncertain' );
		$order->update_meta_data( '_mobo_order_last_error_code', $code );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->update_meta_data( '_mobo_order_uncertain_at', time() );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->save();
		if ( ! $already ) {
			$order->add_order_note( 'نتیجه آخرین تلاش ثبت سفارش در موبو نامشخص است. برای جلوگیری از سفارش تکراری، Retry خودکار متوقف شد؛ ابتدا سفارش در موبو بررسی و سپس در صورت نیاز Retry دستی انجام شود.' );
			$this->append_order_log(
				$order,
				'order_submission_uncertain',
				array_merge(
					array(
						'attemptedAt' => absint( $order->get_meta( '_mobo_order_submit_attempted_at', true ) ),
						'code'        => $code,
					),
					$context
				)
			);
		}

		return new WP_Error( 'mobo_core_order_submission_uncertain', $message, array( 'reason' => $code ) );
	}


	/**
	 * Private filesystem directory for payment uncertainty fences.
	 *
	 * These small JSON files are intentionally independent of the WordPress database.
	 * Their sole purpose is preventing an automatic second Wallet Payment when MySQL
	 * fails exactly after the irreversible remote request may have reached Mobo.
	 *
	 * @return string
	 */
	private function order_payment_guard_dir() {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base || ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $base ) . 'mobo-core/order-payment-guards';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		/* Best-effort web-server denial. The file names contain only order IDs and the
		 * JSON body contains no Mobo token/password, but they still represent private
		 * operational state and should never be served publicly. */
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Emergency DB-independent payment fence.
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! is_file( $htaccess ) ) {
			@file_put_contents( $htaccess, "Deny from all\n", LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Best-effort private directory protection.
		}

		return $dir;
	}

	/**
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function order_payment_guard_path( $order_id ) {
		$dir = $this->order_payment_guard_dir();
		return '' === $dir ? '' : trailingslashit( $dir ) . 'order-' . absint( $order_id ) . '.json';
	}

	/**
	 * Atomically persist a DB-independent payment fence.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $state State label.
	 * @param array  $context Non-secret diagnostic context.
	 * @return bool
	 */
	private function persist_order_payment_guard( $order_id, $state, $context = array() ) {
		$order_id = absint( $order_id );
		$path     = $this->order_payment_guard_path( $order_id );
		if ( $order_id <= 0 || '' === $path ) {
			return false;
		}

		$payload = array(
			'orderId'   => $order_id,
			'state'     => sanitize_key( (string) $state ),
			'createdAt' => time(),
			'context'   => $this->sanitize_order_log_value( is_array( $context ) ? $context : array() ),
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return false;
		}

		$tmp = $path . '.tmp-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
		$written = @file_put_contents( $tmp, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Must remain independent of DB/WordPress options.
		if ( false === $written || $written !== strlen( $json ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup failed temp fence only.
			return false;
		}

		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory commit is required.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup failed temp fence only.
			return false;
		}

		clearstatcache( true, $path );
		return is_file( $path ) && filesize( $path ) === strlen( $json );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array|null
	 */
	private function read_order_payment_guard( $order_id ) {
		$path = $this->order_payment_guard_path( $order_id );
		if ( '' === $path || ! is_file( $path ) ) {
			return null;
		}
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private DB-independent fence.
		$json = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $json ) || absint( isset( $json['orderId'] ) ? $json['orderId'] : 0 ) !== absint( $order_id ) ) {
			/* Corrupt marker is safer to treat as uncertain than to silently remove. */
			return array( 'orderId' => absint( $order_id ), 'state' => 'corrupt' );
		}
		return $json;
	}

	/**
	 * @param int $order_id Order ID.
	 * @return bool True when no guard remains.
	 */
	private function clear_order_payment_guard( $order_id ) {
		$path = $this->order_payment_guard_path( $order_id );
		if ( '' === $path || ! is_file( $path ) ) {
			return true;
		}
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete is verified by postcondition below.
		clearstatcache( true, $path );
		return ! is_file( $path );
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
			$product  = $line_item->get_product();
			$name     = wp_strip_all_tags( $line_item->get_name() );
			$identity = $this->get_order_line_item_identity( $line_item, $product );

			if ( ! empty( $identity['isMobo'] ) ) {
				$scope['mobo']++;
				continue;
			}

			if ( ! $product instanceof WC_Product && empty( $identity['captured'] ) ) {
				$scope['invalid']++;
				$scope['nonMoboNames'][] = '' !== $name ? $name : 'محصول نامشخص';
				continue;
			}

			$scope['nonMobo']++;
			$scope['nonMoboNames'][] = '' !== $name ? $name : 'محصول';
		}

		if ( $scope['total'] <= 0 ) {
			$scope['status'] = 'empty';
		} elseif ( $scope['invalid'] > 0 ) {
			/* An uncaptured legacy line whose catalogue object disappeared has unknown
			 * ownership. Never downgrade that uncertainty to mixed/non-Mobo and buy only
			 * the visible subset of the order. */
			$scope['status'] = 'invalid';
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] > 0 ) {
			$scope['status'] = 'mixed';
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] <= 0 ) {
			$scope['status'] = 'non_mobo';
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
	private function is_order_line_item_mobo( $line_item, $product = null ) {
		if ( ! $line_item instanceof WC_Order_Item_Product ) {
			return false;
		}
		$identity = $this->get_order_line_item_identity( $line_item, $product );
		return ! empty( $identity['isMobo'] );
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
	 * Fail closed when a legacy order contains an uncaptured line whose product no
	 * longer exists. Its Mobo/non-Mobo ownership cannot be reconstructed safely from
	 * the order row alone, so partial remote purchase is forbidden.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $scope Scope.
	 * @param string   $source Source key.
	 * @return void
	 */
	private function mark_order_scope_invalid( $order, $scope, $source = '' ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$names = is_array( $scope ) && isset( $scope['nonMoboNames'] ) && is_array( $scope['nonMoboNames'] )
			? array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $scope['nonMoboNames'] ) ) ), 0, 5 )
			: array();
		$message = 'ارسال سفارش به موبو متوقف شد چون حداقل یک آیتم قدیمی سفارش دیگر Product/Variation قابل خواندن ندارد و قبل از این نسخه هویت موبوی آن روی Order Item ذخیره نشده بود. برای جلوگیری از خرید ناقص، سفارش نیاز به بررسی دارد.';
		if ( ! empty( $names ) ) {
			$message .= ' آیتم‌های نامشخص: ' . implode( '، ', $names );
		}

		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->update_meta_data( '_mobo_order_submit_status', 'blocked_invalid_scope' );
		$order->update_meta_data( '_mobo_order_last_error_code', 'legacy_order_item_identity_missing' );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->update_meta_data( '_mobo_order_requires_review', 'yes' );
		$order->save();
		$order->add_order_note( $message );
		$this->append_order_log( $order, 'order_submission_blocked_invalid_scope', array( 'source' => sanitize_key( (string) $source ), 'scope' => $scope ) );
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

		$order_id = absint( $order->get_id() );
		if ( $order_id <= 0 || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return new WP_Error( 'mobo_core_order_submission_lock_unavailable', 'Per-order submission lock is unavailable.' );
		}

		$observed_status       = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		$observed_attempted_at = absint( $order->get_meta( '_mobo_order_submit_attempted_at', true ) );
		$lock_name             = 'order_submission_' . $order_id;
		$lock_ttl              = max( 300, absint( apply_filters( 'mobo_core_order_submission_lock_ttl', 900, $order_id ) ) );
		$lock_token            = Mobo_Core_Lock::acquire( $lock_name, $lock_ttl );

		if ( false === $lock_token ) {
			return new WP_Error( 'mobo_core_order_submission_busy', 'Another request already owns this WooCommerce order submission.' );
		}

		$this->active_order_submission_lock_name  = $lock_name;
		$this->active_order_submission_lock_token = sanitize_text_field( (string) $lock_token );
		$this->active_order_submission_lock_ttl   = $lock_ttl;

		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof WC_Order ) {
				return new WP_Error( 'mobo_core_invalid_order', 'WooCommerce order disappeared before submission.' );
			}

			if ( $this->order_was_already_sent_to_mobo( $fresh ) ) {
				$this->clear_order_payment_guard( $order_id );
				return true;
			}

			$fresh_status       = sanitize_key( (string) $fresh->get_meta( '_mobo_order_submit_status', true ) );
			$fresh_attempted_at = absint( $fresh->get_meta( '_mobo_order_submit_attempted_at', true ) );
			$explicit_admin_retry = 'admin_manual_retry' === sanitize_key( (string) ( isset( $context['trigger'] ) ? $context['trigger'] : '' ) );
			$verified_absent      = ! empty( $context['verifiedAbsentInMobo'] );
			$payment_guard        = $this->read_order_payment_guard( $order_id );

			/* A filesystem guard is the independent safety lane for the narrow case where
			 * Mobo may have handled Wallet Payment but WooCommerce could not persist the
			 * uncertain/success state because the database failed at the same boundary. */
			if ( is_array( $payment_guard ) && ! ( $explicit_admin_retry && $verified_absent ) ) {
				return new WP_Error( 'mobo_core_order_submission_uncertain', 'A previous payment attempt has a durable uncertainty guard and must be verified before retry.' );
			}

			if ( 'uncertain' === $fresh_status && ! ( $explicit_admin_retry && $verified_absent ) ) {
				return new WP_Error( 'mobo_core_order_submission_uncertain', 'A previous submission attempt is uncertain and must be verified before retry.' );
			}

			/* A different request may have completed a failed/uncertain attempt between
			 * the caller reading the order and acquiring this per-order lease. Never let
			 * that stale caller become an implicit second retry. Explicit admin retry
			 * clears the attempt metadata before entering this wrapper, so its observed
			 * generation still matches the durable one. */
			if ( $fresh_attempted_at !== $observed_attempted_at || $fresh_status !== $observed_status ) {
				return new WP_Error( 'mobo_core_order_submission_superseded', 'The order submission state changed while this request was waiting; stale retry was suppressed.' );
			}

			if ( $explicit_admin_retry ) {
				if ( $verified_absent && ! $this->clear_order_payment_guard( $order_id ) ) {
					return new WP_Error( 'mobo_core_order_payment_guard_clear_failed', 'The durable payment uncertainty guard could not be cleared after verification.' );
				}

				/* Reset retry state only after this request owns the per-order lease. If an
				 * upgrade barrier or another worker owns the order, the previous durable
				 * failed/uncertain state must remain intact. */
				$fresh->delete_meta_data( '_mobo_order_submit_attempted' );
				$fresh->delete_meta_data( '_mobo_order_submit_attempted_at' );
				$fresh->delete_meta_data( '_mobo_order_submit_queued' );
				$fresh->delete_meta_data( '_mobo_order_submit_queued_at' );
				$fresh->delete_meta_data( '_mobo_order_submit_context' );
				$fresh->delete_meta_data( '_mobo_order_submit_status' );
				$fresh->delete_meta_data( '_mobo_order_last_error_code' );
				$fresh->delete_meta_data( '_mobo_order_last_error' );
				$fresh->delete_meta_data( '_mobo_order_failed_at' );
				$fresh->delete_meta_data( '_mobo_order_uncertain_at' );
				$fresh->save();
			}

			return $this->submit_order_to_mobo_owned( $fresh, is_array( $context ) ? $context : array() );
		} finally {
			Mobo_Core_Lock::release( $lock_name, $lock_token );
			$this->active_order_submission_lock_name  = '';
			$this->active_order_submission_lock_token = '';
		}
	}

	private function submit_order_to_mobo_owned( $order, $context = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}

		$order_id = $order->get_id();
		if ( 'processing' !== sanitize_key( (string) $order->get_status() ) ) {
			return new WP_Error( 'mobo_core_order_not_processing', 'Order is not processing.' );
		}
		$scope    = $this->get_order_mobo_item_scope( $order );

		if ( 'invalid' === sanitize_key( (string) ( isset( $scope['status'] ) ? $scope['status'] : '' ) ) ) {
			$this->mark_order_scope_invalid( $order, $scope, 'submit_guard' );
			return new WP_Error( 'mobo_core_order_scope_invalid', 'سفارش یک آیتم قدیمی با هویت نامشخص دارد؛ خرید ناقص در موبو متوقف شد.' );
		}

		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'submit_guard' );
			return new WP_Error( 'mobo_core_order_not_mobo', 'این سفارش محصول موبو ندارد.' );
		}


		$attempted_at = time();
		$order->update_meta_data( '_mobo_order_submit_attempted', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_attempted_at', $attempted_at );
		$order->update_meta_data( '_mobo_order_submit_status', 'running' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$saved_id = absint( $order->save() );
		if ( $saved_id <= 0 ) {
			return new WP_Error( 'mobo_core_order_submission_state_persist_failed', 'Order submission was not started because the local running state could not be persisted.' );
		}

		/* This is the safety boundary before any Mobo side effect. A database failure
		 * must never let Cart/Checkout/Wallet Payment start without durable local
		 * evidence that the WooCommerce order already owns an in-flight attempt. */
		$durable_order = wc_get_order( $saved_id );
		if ( ! $durable_order instanceof WC_Order
			|| 'yes' !== (string) $durable_order->get_meta( '_mobo_order_submit_attempted', true )
			|| 'running' !== sanitize_key( (string) $durable_order->get_meta( '_mobo_order_submit_status', true ) )
			|| absint( $durable_order->get_meta( '_mobo_order_submit_attempted_at', true ) ) !== $attempted_at
		) {
			return new WP_Error( 'mobo_core_order_submission_state_persist_failed', 'Order submission was not started because the durable running checkpoint could not be verified.' );
		}
		$order = $durable_order;

		$this->append_order_log( $order, 'order_submission_start', array( 'orderId' => $order_id, 'context' => $context ) );

		$errors = array();
		$items  = $this->build_order_items_payload( $order, $errors );

		if ( empty( $items ) && empty( $errors ) ) {
			$errors[] = 'سفارش هیچ آیتم موبوی قابل ارسال ندارد.';
		}

		if ( empty( $errors ) ) {
			$aggregate_errors = array();
			$items = $this->aggregate_mobo_items_by_variant( $items, $aggregate_errors );
			if ( ! empty( $aggregate_errors ) ) {
				$errors = array_merge( $errors, $aggregate_errors );
			}
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

		$business_snapshot = $this->build_order_submission_business_snapshot( $order, $items, $contact_preflight );
		if ( is_wp_error( $business_snapshot ) ) {
			return $this->fail_mobo_order_submission( $order, 'business_snapshot_failed', $business_snapshot->get_error_message() );
		}

		$lock = $this->acquire_mobo_cart_lock( 'order_submission_' . $order_id );
		if ( is_wp_error( $lock ) ) {
			if ( in_array( $lock->get_error_code(), array( 'mobo_core_shared_cart_locked', 'mobo_core_shared_cart_upgrade_barrier' ), true ) ) {
				return $this->defer_mobo_order_submission( $order, 'cart_lock_busy', $lock->get_error_message(), array( 'remoteSideEffectsStarted' => false ) );
			}
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

			/* Preserve backwards compatibility with historical empty 200 responses, but
			 * never ignore an explicit semantic rejection or malformed non-empty JSON. */
			$checkout_raw = trim( (string) wp_remote_retrieve_body( $checkout ) );
			if ( '' !== $checkout_raw ) {
				$checkout_json = json_decode( $checkout_raw, true );
				if ( ! is_array( $checkout_json ) ) {
					return $this->fail_mobo_order_submission( $order, 'checkout_json_failed', 'Mobo checkout returned a non-empty body that was not valid JSON.' );
				}
				if ( array_key_exists( 'success', $checkout_json ) && ! $this->to_bool( $checkout_json['success'] ) ) {
					$message = $this->first_non_empty_scalar(
						array(
							isset( $checkout_json['description'] ) ? $checkout_json['description'] : '',
							isset( $checkout_json['message'] ) ? $checkout_json['message'] : '',
							'Mobo checkout explicitly rejected the order.',
						)
					);
					return $this->fail_mobo_order_submission( $order, 'checkout_not_success', sanitize_text_field( $message ) );
				}
			}

			$token = isset( $checkout_payload['token'] ) && is_scalar( $checkout_payload['token'] ) ? trim( (string) $checkout_payload['token'] ) : '';
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
			$details_mobo_order_id = array_key_exists( 'id', $details ) ? $this->parse_positive_integer_id( $details['id'] ) : 0;
			$shipping_mobo_order_id = array_key_exists( 'id', $payment_details ) ? $this->parse_positive_integer_id( $payment_details['id'] ) : 0;
			if ( $details_mobo_order_id > 0 && $shipping_mobo_order_id > 0 && $details_mobo_order_id !== $shipping_mobo_order_id ) {
				return $this->fail_mobo_order_submission( $order, 'mobo_order_id_changed_before_payment', 'شناسه سفارش موبو بین مرحله Details و Shipping تغییر کرد؛ برای جلوگیری از پرداخت روی سفارش اشتباه، Wallet شروع نشد.' );
			}
			$pre_payment_mobo_order_id = $shipping_mobo_order_id > 0 ? $shipping_mobo_order_id : $details_mobo_order_id;
			if ( $pre_payment_mobo_order_id <= 0 ) {
				return $this->fail_mobo_order_submission( $order, 'mobo_order_id_missing_before_payment', 'شناسه سفارش موبو قبل از مرحله Wallet موجود نیست؛ برای جلوگیری از پرداختی که بعداً قابل تطبیق نباشد، پرداخت شروع نشد.' );
			}

			/* Re-read the WooCommerce order at the last reversible boundary. An admin,
			 * payment callback, stock plugin, or customer-side workflow may have changed
			 * status/items/address/shipping while the remote cart was being prepared. */
			$final_guard = $this->validate_order_before_wallet_payment( $order_id, $business_snapshot, $shipping_id, $shippings_json );
			if ( is_wp_error( $final_guard ) ) {
				$guard_data = $final_guard->get_error_data();
				$guard_action = is_array( $guard_data ) && isset( $guard_data['action'] ) ? sanitize_key( (string) $guard_data['action'] ) : 'fail';
				if ( 'abort' === $guard_action ) {
					return $this->abort_mobo_order_submission_before_payment( $order, $final_guard->get_error_code(), $final_guard->get_error_message() );
				}
				if ( 'defer' === $guard_action ) {
					return $this->defer_mobo_order_submission( $order, $final_guard->get_error_code(), $final_guard->get_error_message(), array( 'remoteSideEffectsStarted' => true ) );
				}
				return $this->fail_mobo_order_submission( $order, $final_guard->get_error_code(), $final_guard->get_error_message() );
			}
			$order = $final_guard;
			$payment_payload = $this->build_mobo_order_stage_payload( $payment_details, $shipping_id, 'wallet', null );

			/* Persist an out-of-database fence immediately before the irreversible Wallet
			 * Payment POST. If MySQL dies after Mobo receives the request, the next PHP
			 * process still has durable evidence that automatic retry is unsafe. */
			if ( ! $this->persist_order_payment_guard( $order_id, 'payment_inflight', array( 'attemptedAt' => $attempted_at ) ) ) {
				return $this->fail_mobo_order_submission( $order, 'payment_guard_persist_failed', 'پرداخت به موبو شروع نشد چون نشانگر ایمنی مستقل پرداخت روی دیسک قابل ذخیره نبود.' );
			}

			/* Never replay the irreversible wallet call after an auth-shaped response.
			 * Any non-successful acknowledgement is handled below as uncertain unless
			 * Mobo explicitly proves that the wallet was not charged. */
			$payment = $this->order_mobo_request( $order, 'POST', '/site/api/v1/cart/payment/wallet', $payment_payload, 'cart_payment_wallet', false );
			if ( is_wp_error( $payment ) ) {
				if ( $this->payment_error_is_ambiguous( $payment ) ) {
					return $this->mark_order_submission_uncertain(
						$order,
						'payment_result_uncertain',
						'درخواست پرداخت به موبو ارسال شد اما نتیجه قابل اثبات نیست. قبل از Retry، وضعیت سفارش/پرداخت در موبو بررسی شود.',
						array( 'remoteError' => $payment->get_error_message() )
					);
				}
				$this->clear_order_payment_guard( $order_id );
				return $this->fail_mobo_order_submission( $order, 'payment_failed', $payment->get_error_message() );
			}

			$payment_json = $this->decode_mobo_response_json( $payment );
			if ( is_wp_error( $payment_json ) ) {
				return $this->mark_order_submission_uncertain(
					$order,
					'payment_response_unreadable',
					'موبو پاسخ موفق HTTP به مرحله پرداخت داد اما بدنه پاسخ قابل تفسیر نبود. قبل از Retry، وضعیت پرداخت در موبو بررسی شود.'
				);
			}

			$payment_success_state = array_key_exists( 'success', $payment_json )
				? $this->parse_explicit_boolean( $payment_json['success'] )
				: array( 'known' => false, 'value' => false );
			$payment_paid_state = array_key_exists( 'paid', $payment_json )
				? $this->parse_explicit_boolean( $payment_json['paid'] )
				: array( 'known' => false, 'value' => false );
			$payment_success_known = ! empty( $payment_success_state['known'] );
			$payment_paid_known    = ! empty( $payment_paid_state['known'] );
			$payment_success       = $payment_success_known && ! empty( $payment_success_state['value'] );
			$payment_paid          = $payment_paid_known && ! empty( $payment_paid_state['value'] );

			/* If the irreversible payment response chooses to echo an order ID, it must
			 * agree with the strictly validated pre-payment ID. A mismatch/malformed ID
			 * after Wallet is uncertainty, never a retryable local failure. */
			$payment_order_id_present = false;
			$payment_order_id = 0;
			if ( array_key_exists( 'id', $payment_json ) ) {
				$payment_order_id_present = true;
				$payment_order_id = $this->parse_positive_integer_id( $payment_json['id'] );
			} elseif ( isset( $payment_json['details'] ) && is_array( $payment_json['details'] ) && array_key_exists( 'id', $payment_json['details'] ) ) {
				$payment_order_id_present = true;
				$payment_order_id = $this->parse_positive_integer_id( $payment_json['details']['id'] );
			}
			if ( $payment_order_id_present && ( $payment_order_id <= 0 || $payment_order_id !== $pre_payment_mobo_order_id ) ) {
				return $this->mark_order_submission_uncertain(
					$order,
					'payment_order_id_mismatch',
					'پاسخ Wallet شناسه سفارش معتبر و سازگار با سفارش موبو قبل از پرداخت برنگرداند. برای جلوگیری از پرداخت تکراری، Retry خودکار متوقف شد.',
					array( 'prePaymentMoboOrderId' => $pre_payment_mobo_order_id, 'paymentMoboOrderId' => $payment_order_id )
				);
			}

			/* A definitive unpaid acknowledgement is safe to classify as failed only
			 * when Mobo returned an explicitly recognized boolean false. Values such as
			 * null, "pending", arrays, or arbitrary numerics are not proof that the
			 * irreversible Wallet POST did not charge and therefore remain uncertain. */
			if ( $payment_paid_known && ! $payment_paid ) {
				$this->clear_order_payment_guard( $order_id );
				return $this->fail_mobo_order_submission( $order, 'payment_not_paid', isset( $payment_json['description'] ) ? sanitize_text_field( (string) $payment_json['description'] ) : 'Mobo wallet payment explicitly reported paid=false.' );
			}

			if ( ! $payment_success_known || ! $payment_paid_known || ! $payment_success || ! $payment_paid ) {
				return $this->mark_order_submission_uncertain(
					$order,
					'payment_ack_incomplete',
					'پاسخ مرحله پرداخت، موفقیت و پرداخت قطعی را به‌صورت صریح و سازگار تأیید نکرد. قبل از Retry، وضعیت سفارش در موبو بررسی شود.',
					array( 'response' => $this->sanitize_order_log_value( $payment_json ) )
				);
			}

			$mobo_order_id = $pre_payment_mobo_order_id;
			if ( ! $this->mark_mobo_order_submission_success( $order, $mobo_order_id, $token, $shipping_id, $payment_json ) ) {
				/* The remote order ID is known, so this is not a safe retryable failure.
				 * Re-save the same object as uncertain; its pending meta still contains the
				 * known Mobo order ID/token, giving the local database a second chance to
				 * retain evidence that prevents a duplicate purchase. */
				return $this->mark_order_submission_uncertain(
					$order,
					'local_success_commit_failed',
					'پرداخت و سفارش در موبو موفق بود، اما ثبت durable وضعیت موفقیت در WooCommerce تأیید نشد. سفارش موبو موجود است؛ Retry نکنید و ابتدا وضعیت محلی را بررسی کنید.',
					array( 'moboOrderId' => $mobo_order_id )
				);
			}

			/* A mutation can still race in the narrow window between the final pre-payment
			 * check and the Wallet acknowledgement. The Mobo purchase must remain durable
			 * (never retry it), but flag any changed Woo business state for manual review
			 * and suppress automatic Woo status transitions below. */
			$this->mark_post_payment_order_divergence( $order_id, $business_snapshot );

			/* Local success is now durable, so a leftover filesystem fence is stale and
			 * can be removed. Failure to remove it is safe: durable success is checked
			 * before the guard on the next request and will retire it then. */
			$this->clear_order_payment_guard( $order_id );

			/*
			 * The remote purchase is complete at this point. Release the shared cart/session
			 * before best-effort post-purchase listeners run: wallet monitoring may create a
			 * separate validator instance and re-authenticate the shared cookie jar. Holding
			 * the cart lease across that hook would either deadlock the listener or let it
			 * mutate the shared session without owning the lease.
			 */
			$this->release_mobo_cart_lock( $lock );
			$lock = '';

			/* The purchase is already durable in Mobo and WooCommerce. A secondary
			 * listener (wallet alert, revenue bookkeeping, SMS, or a third-party hook)
			 * must never turn that success into a retryable purchase failure. */
			try {
				do_action( 'mobo_core_mobo_order_submission_success', $order_id, $mobo_order_id, $payment_json );
			} catch ( Throwable $post_success_error ) {
				$fresh_after_hook = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				if ( $fresh_after_hook instanceof WC_Order ) {
					$fresh_after_hook->update_meta_data( '_mobo_order_post_success_hook_failed', 'yes' );
					$fresh_after_hook->update_meta_data( '_mobo_order_requires_review', 'yes' );
					$fresh_after_hook->save();
					$this->append_order_log(
						$fresh_after_hook,
						'post_success_hook_failed',
						array(
							'exceptionClass' => get_class( $post_success_error ),
							'messageBytes'   => strlen( (string) $post_success_error->getMessage() ),
							'messageSha256'  => hash( 'sha256', (string) $post_success_error->getMessage() ),
						)
					);
				}
			}

			/* A post-success listener can itself mutate items/address/status. Recheck the
			 * paid order after all listeners, while keeping any earlier divergence sticky. */
			$this->mark_post_payment_order_divergence( $order_id, $business_snapshot );

			return true;
		} finally {
			if ( '' !== sanitize_text_field( (string) $lock ) ) {
				$this->release_mobo_cart_lock( $lock );
			}
		}
	}

	private function build_order_items_payload( $order, &$errors ) {
		$errors = is_array( $errors ) ? $errors : array();
		$items  = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product      = $line_item->get_product();
			$product_id   = absint( $line_item->get_product_id() );
			$variation_id = absint( $line_item->get_variation_id() );
			$wc_id        = $product instanceof WC_Product ? absint( $product->get_id() ) : ( $variation_id > 0 ? $variation_id : $product_id );
			$quantity     = (float) $line_item->get_quantity();
			$parent_id    = $product_id > 0 ? $product_id : $wc_id;
			$identity     = $this->get_order_line_item_identity( $line_item, $product );
			$name         = wp_strip_all_tags( $line_item->get_name() );

			if ( empty( $identity['isMobo'] ) ) {
				/* Mixed orders are valid. Non-Mobo items stay in WooCommerce only. */
				continue;
			}

			$product_guid      = sanitize_text_field( (string) $identity['productGuid'] );
			$variant_guid      = sanitize_text_field( (string) $identity['variantGuid'] );
			$portal_product_id = absint( $identity['portalProductId'] );
			$portal_variant_id = absint( $identity['portalVariantId'] );

			if ( $quantity <= 0 || ! is_finite( $quantity ) ) {
				$errors[] = sprintf( 'تعداد محصول «%s» در سفارش معتبر نیست.', $name );
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

			/* New orders use their immutable checkout snapshot. Legacy orders still need
			 * the live sync-completeness fence because their identity was never frozen. */
			if ( empty( $identity['captured'] ) && $product instanceof WC_Product && $this->is_sync_incomplete( $parent_id, $variation_id ) ) {
				$errors[] = sprintf( 'همگام‌سازی محصول «%s» هنوز کامل نشده است.', $name );
			}

			$items[] = array(
				'cartKey'         => 'order_item_' . absint( $item_id ),
				'orderItemId'     => absint( $item_id ),
				'productId'       => $parent_id,
				'variationId'     => $variation_id,
				'wcProductId'     => $wc_id,
				'quantity'        => $quantity,
				'sku'             => '' !== (string) $identity['sku'] ? sanitize_text_field( (string) $identity['sku'] ) : ( $product instanceof WC_Product ? sanitize_text_field( (string) $product->get_sku() ) : '' ),
				'name'            => $name,
				'productGuid'     => $product_guid,
				'variantGuid'     => $variant_guid,
				'portalProductId' => $portal_product_id,
				'portalVariantId' => $portal_variant_id,
				'isMoboItem'      => true,
				'identityFrozen'  => ! empty( $identity['captured'] ),
			);
		}

		return empty( $errors ) ? $items : array();
	}

	/**
	 * Collapse multiple WooCommerce rows of the same Mobo variant into one remote
	 * cart mutation. Add-ons can legitimately split one variation into multiple
	 * cart/order rows; Mobo's shared cart must receive the summed quantity once.
	 *
	 * @param array $items Mobo item payloads.
	 * @param array $errors Output errors.
	 * @return array
	 */
	private function aggregate_mobo_items_by_variant( $items, &$errors ) {
		$errors = is_array( $errors ) ? $errors : array();
		$grouped = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item['isMoboItem'] ) ) {
				continue;
			}
			$variant_id = absint( isset( $item['portalVariantId'] ) ? $item['portalVariantId'] : 0 );
			$quantity   = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0.0;
			$name       = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : 'محصول';
			if ( $variant_id <= 0 || $quantity <= 0 || ! is_finite( $quantity ) ) {
				$errors[] = sprintf( 'شناسه یا تعداد قابل ارسال برای محصول «%s» معتبر نیست.', $name );
				continue;
			}

			$key = (string) $variant_id;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = $item;
				$grouped[ $key ]['quantity'] = $quantity;
				$grouped[ $key ]['sourceRows'] = 1;
				continue;
			}

			foreach ( array( 'productGuid', 'variantGuid' ) as $identity_key ) {
				$left  = sanitize_text_field( (string) ( isset( $grouped[ $key ][ $identity_key ] ) ? $grouped[ $key ][ $identity_key ] : '' ) );
				$right = sanitize_text_field( (string) ( isset( $item[ $identity_key ] ) ? $item[ $identity_key ] : '' ) );
				if ( '' !== $left && '' !== $right && ! hash_equals( $left, $right ) ) {
					$errors[] = 'دو آیتم سفارش با یک portal_variant_id به شناسه‌های متفاوت موبو اشاره می‌کنند؛ ارسال برای جلوگیری از خرید اشتباه متوقف شد.';
					continue 2;
				}
			}
			$grouped[ $key ]['quantity'] += $quantity;
			$grouped[ $key ]['sourceRows'] = absint( $grouped[ $key ]['sourceRows'] ) + 1;
		}

		return empty( $errors ) ? array_values( $grouped ) : array();
	}

	/**
	 * Parse the opaque Mobo cart token without coercing arrays/objects or allowing
	 * control characters into the checkout/details URL flow.
	 *
	 * @param mixed $value Remote cart token.
	 * @return string
	 */
	private function parse_mobo_cart_token( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$token = trim( (string) $value );
		if ( '' === $token || strlen( $token ) > 1024 || preg_match( '/[\\x00-\\x1F\\x7F]/', $token ) ) {
			return '';
		}
		return $token;
	}

	private function build_mobo_checkout_payload( $order, $snapshot ) {
		if ( ! isset( $snapshot['cart'] ) || ! is_array( $snapshot['cart'] ) ) {
			return new WP_Error( 'mobo_core_cart_snapshot_missing', 'Mobo cart snapshot does not contain cart.' );
		}

		$cart  = $snapshot['cart'];
		$token = array_key_exists( 'token', $cart ) ? $this->parse_mobo_cart_token( $cart['token'] ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'mobo_core_cart_token_invalid', 'Mobo cart token is missing or malformed.' );
		}
		$cart['token'] = $token;

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
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}

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
		$recipient_name   = $billing_name;
		$recipient_mobile = $billing_mobile;
		if ( 'shipping' === $group ) {
			$shipping_name = method_exists( $order, 'get_formatted_shipping_full_name' ) ? trim( (string) $order->get_formatted_shipping_full_name() ) : '';
			if ( '' === $shipping_name ) {
				$shipping_name = trim( (string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name() );
			}
			if ( '' !== $shipping_name ) {
				$recipient_name = $shipping_name;
			}
			$shipping_phone = method_exists( $order, 'get_shipping_phone' ) ? trim( (string) $order->get_shipping_phone() ) : '';
			if ( '' !== $shipping_phone ) {
				$recipient_mobile = $shipping_phone;
			}
		}

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

		if ( '' === $recipient_name ) {
			return new WP_Error( 'mobo_core_recipient_name_missing', 'نام گیرنده برای ثبت سفارش در موبو خالی است.' );
		}
		if ( '' === $recipient_mobile ) {
			return new WP_Error( 'mobo_core_recipient_mobile_missing', 'شماره موبایل گیرنده برای ثبت سفارش در موبو خالی است.' );
		}
		if ( '' === $address ) {
			return new WP_Error( 'mobo_core_recipient_address_missing', 'آدرس گیرنده برای ثبت سفارش در موبو خالی است.' );
		}

		return array(
			'senderName'      => sanitize_text_field( $sender_name ),
			'senderMobile'    => sanitize_text_field( $sender_mobile ),
			'billingName'     => sanitize_text_field( $recipient_name ),
			'billingMobile'   => sanitize_text_field( $recipient_mobile ),
			'recipientName'   => sanitize_text_field( $recipient_name ),
			'recipientMobile' => sanitize_text_field( $recipient_mobile ),
			'addressGroup'    => sanitize_key( $group ),
			'zipcode'         => sanitize_text_field( (string) $postcode ),
			'address'         => sanitize_text_field( $address ),
			'countryId'       => $country_id,
			'stateId'         => $state_id,
			'cityId'          => $city_id,
			'latitude'        => $this->order_meta_float_or_null( $order, '_mobo_' . $group . '_city_latitude' ),
			'longitude'       => $this->order_meta_float_or_null( $order, '_mobo_' . $group . '_city_longitude' ),
		);
	}

	/**
	 * Freeze the business meaning of the WooCommerce order for the duration of one
	 * remote purchase attempt. Volatile technical metadata is deliberately excluded.
	 *
	 * @param WC_Order   $order Order.
	 * @param array|null $items Optional pre-built/aggregated Mobo items.
	 * @param array|null $contact Optional pre-built contact payload.
	 * @return array|WP_Error
	 */
	private function build_order_submission_business_snapshot( $order, $items = null, $contact = null ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}

		if ( null === $items ) {
			$item_errors = array();
			$items = $this->build_order_items_payload( $order, $item_errors );
			if ( ! empty( $item_errors ) ) {
				return new WP_Error( 'mobo_core_final_item_validation_failed', implode( ' | ', $item_errors ) );
			}
			$aggregate_errors = array();
			$items = $this->aggregate_mobo_items_by_variant( $items, $aggregate_errors );
			if ( ! empty( $aggregate_errors ) ) {
				return new WP_Error( 'mobo_core_final_item_aggregation_failed', implode( ' | ', $aggregate_errors ) );
			}
		}

		if ( null === $contact ) {
			$contact = $this->build_mobo_order_contact_payload( $order );
		}
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$normalized_items = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$normalized_items[] = array(
				'portalVariantId' => absint( isset( $item['portalVariantId'] ) ? $item['portalVariantId'] : 0 ),
				'productGuid'     => sanitize_text_field( (string) ( isset( $item['productGuid'] ) ? $item['productGuid'] : '' ) ),
				'variantGuid'     => sanitize_text_field( (string) ( isset( $item['variantGuid'] ) ? $item['variantGuid'] : '' ) ),
				'quantity'        => wc_format_decimal( isset( $item['quantity'] ) ? $item['quantity'] : 0, 6 ),
			);
		}
		usort( $normalized_items, function ( $a, $b ) {
			return absint( $a['portalVariantId'] ) <=> absint( $b['portalVariantId'] );
		} );

		/* Fingerprint the complete Woo line-item structure too, not only the subset
		 * sent to Mobo. A non-Mobo line added/removed from a mixed order while the
		 * supplier cart is being prepared still changes the business meaning of the
		 * paid Woo order and must force a fresh preflight before Wallet. */
		$order_lines = array();
		foreach ( $order->get_items( 'line_item' ) as $line_item_id => $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$order_lines[] = array(
				'itemId'      => absint( $line_item_id ),
				'productId'   => absint( $line_item->get_product_id() ),
				'variationId' => absint( $line_item->get_variation_id() ),
				'quantity'    => wc_format_decimal( $line_item->get_quantity(), 6 ),
				'subtotal'    => wc_format_decimal( $line_item->get_subtotal(), 6 ),
				'total'       => wc_format_decimal( $line_item->get_total(), 6 ),
				'subtotalTax' => wc_format_decimal( $line_item->get_subtotal_tax(), 6 ),
				'totalTax'    => wc_format_decimal( $line_item->get_total_tax(), 6 ),
			);
		}
		usort( $order_lines, function ( $a, $b ) {
			return absint( $a['itemId'] ) <=> absint( $b['itemId'] );
		} );

		$shipping_lines = array();
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			$shipping_lines[] = array(
				'itemId'         => method_exists( $shipping_item, 'get_id' ) ? absint( $shipping_item->get_id() ) : 0,
				'methodId'       => method_exists( $shipping_item, 'get_method_id' ) ? sanitize_key( (string) $shipping_item->get_method_id() ) : '',
				'instanceId'     => method_exists( $shipping_item, 'get_instance_id' ) ? absint( $shipping_item->get_instance_id() ) : 0,
				'moboShippingId' => method_exists( $shipping_item, 'get_meta' ) ? absint( $shipping_item->get_meta( '_mobo_shipping_id', true ) ) : 0,
				'total'          => method_exists( $shipping_item, 'get_total' ) ? wc_format_decimal( $shipping_item->get_total(), 6 ) : '0',
				'totalTax'       => method_exists( $shipping_item, 'get_total_tax' ) ? wc_format_decimal( $shipping_item->get_total_tax(), 6 ) : '0',
			);
		}
		usort( $shipping_lines, function ( $a, $b ) {
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		} );

		/* Fees/coupons/tax rows can be edited without necessarily producing a unique
		 * order total (for example, equal-and-opposite fee/coupon changes). Fingerprint
		 * their business structure so a semantically changed paid order cannot slip
		 * through merely because aggregate totals happen to remain equal. */
		$fee_lines = array();
		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			$fee_lines[] = array(
				'itemId'   => method_exists( $fee_item, 'get_id' ) ? absint( $fee_item->get_id() ) : 0,
				'name'     => method_exists( $fee_item, 'get_name' ) ? sanitize_text_field( (string) $fee_item->get_name() ) : '',
				'taxClass' => method_exists( $fee_item, 'get_tax_class' ) ? sanitize_key( (string) $fee_item->get_tax_class() ) : '',
				'total'    => method_exists( $fee_item, 'get_total' ) ? wc_format_decimal( $fee_item->get_total(), 6 ) : '0',
				'totalTax' => method_exists( $fee_item, 'get_total_tax' ) ? wc_format_decimal( $fee_item->get_total_tax(), 6 ) : '0',
			);
		}
		usort( $fee_lines, function ( $a, $b ) {
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		} );

		$coupon_lines = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$coupon_lines[] = array(
				'itemId'      => method_exists( $coupon_item, 'get_id' ) ? absint( $coupon_item->get_id() ) : 0,
				'code'        => method_exists( $coupon_item, 'get_code' ) ? sanitize_text_field( (string) $coupon_item->get_code() ) : '',
				'discount'    => method_exists( $coupon_item, 'get_discount' ) ? wc_format_decimal( $coupon_item->get_discount(), 6 ) : '0',
				'discountTax' => method_exists( $coupon_item, 'get_discount_tax' ) ? wc_format_decimal( $coupon_item->get_discount_tax(), 6 ) : '0',
			);
		}
		usort( $coupon_lines, function ( $a, $b ) {
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		} );

		$tax_lines = array();
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$tax_lines[] = array(
				'itemId'      => method_exists( $tax_item, 'get_id' ) ? absint( $tax_item->get_id() ) : 0,
				'rateId'      => method_exists( $tax_item, 'get_rate_id' ) ? absint( $tax_item->get_rate_id() ) : 0,
				'label'       => method_exists( $tax_item, 'get_label' ) ? sanitize_text_field( (string) $tax_item->get_label() ) : '',
				'taxTotal'    => method_exists( $tax_item, 'get_tax_total' ) ? wc_format_decimal( $tax_item->get_tax_total(), 6 ) : '0',
				'shippingTax' => method_exists( $tax_item, 'get_shipping_tax_total' ) ? wc_format_decimal( $tax_item->get_shipping_tax_total(), 6 ) : '0',
			);
		}
		usort( $tax_lines, function ( $a, $b ) {
			return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
		} );

		$contact_snapshot = array(
			'senderName'      => sanitize_text_field( (string) $contact['senderName'] ),
			'senderMobile'    => sanitize_text_field( (string) $contact['senderMobile'] ),
			'recipientName'   => sanitize_text_field( (string) ( isset( $contact['recipientName'] ) ? $contact['recipientName'] : $contact['billingName'] ) ),
			'recipientMobile' => sanitize_text_field( (string) ( isset( $contact['recipientMobile'] ) ? $contact['recipientMobile'] : $contact['billingMobile'] ) ),
			'addressGroup'    => sanitize_key( (string) ( isset( $contact['addressGroup'] ) ? $contact['addressGroup'] : '' ) ),
			'zipcode'         => sanitize_text_field( (string) $contact['zipcode'] ),
			'address'         => sanitize_text_field( (string) $contact['address'] ),
			'countryId'       => absint( $contact['countryId'] ),
			'stateId'         => absint( $contact['stateId'] ),
			'cityId'          => absint( $contact['cityId'] ),
			'latitude'        => isset( $contact['latitude'] ) && is_numeric( $contact['latitude'] ) ? (float) $contact['latitude'] : null,
			'longitude'       => isset( $contact['longitude'] ) && is_numeric( $contact['longitude'] ) ? (float) $contact['longitude'] : null,
		);

		/* Detect account/config changes while a remote purchase is in flight without
		 * persisting the username/password themselves in the order snapshot. */
		$mobo_username = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' );
		$mobo_password = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_password', '' );
		$mobo_config_fingerprint = hash( 'sha256', untrailingslashit( $this->get_mobo_site_url() ) . "\0" . $mobo_username . "\0" . $mobo_password );

		$payload = array(
			'orderId'               => absint( $order->get_id() ),
			'status'                => sanitize_key( (string) $order->get_status() ),
			'items'                 => $normalized_items,
			'orderLines'            => $order_lines,
			'contact'               => $contact_snapshot,
			'shippingLines'         => $shipping_lines,
			'feeLines'              => $fee_lines,
			'couponLines'           => $coupon_lines,
			'taxLines'              => $tax_lines,
			'financial'             => array(
				'currency'       => sanitize_text_field( (string) $order->get_currency() ),
				'orderTotal'     => wc_format_decimal( $order->get_total(), 6 ),
				'totalTax'       => wc_format_decimal( $order->get_total_tax(), 6 ),
				'shippingTotal'  => wc_format_decimal( $order->get_shipping_total(), 6 ),
				'shippingTax'    => wc_format_decimal( $order->get_shipping_tax(), 6 ),
				'discountTotal'  => wc_format_decimal( $order->get_discount_total(), 6 ),
				'discountTax'    => wc_format_decimal( $order->get_discount_tax(), 6 ),
				'totalRefunded'  => wc_format_decimal( $order->get_total_refunded(), 6 ),
				'paymentMethod'  => sanitize_key( (string) $order->get_payment_method() ),
			),
			'moboConfigFingerprint' => $mobo_config_fingerprint,
		);
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return new WP_Error( 'mobo_core_business_snapshot_json_failed', 'Could not encode WooCommerce order business snapshot.' );
		}
		$payload['fingerprint'] = hash( 'sha256', $encoded );
		return $payload;
	}

	/**
	 * Final safety check immediately before the irreversible wallet call.
	 *
	 * @return WC_Order|WP_Error
	 */
	private function validate_order_before_wallet_payment( $order_id, $expected_snapshot, $expected_shipping_id, $shippings_json ) {
		/* Auto-order may be switched off while this worker is preparing the remote cart.
		 * The shared-cart work is reversible; Wallet Payment is not. Pause at this last
		 * boundary and leave the order durably queued until the merchant enables it again. */
		if ( ! $this->is_order_submission_enabled() ) {
			return new WP_Error(
				'mobo_core_order_submission_disabled_before_payment',
				'ثبت خودکار سفارش موبو هنگام آماده‌سازی سفارش غیرفعال شد؛ Wallet اجرا نشد و سفارش برای ادامه احتمالی در صف باقی ماند.',
				array( 'action' => 'defer' )
			);
		}

		$fresh = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( ! $fresh instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_final_order_missing', 'سفارش WooCommerce قبل از پرداخت موبو دیگر قابل خواندن نیست.', array( 'action' => 'fail' ) );
		}
		if ( 'processing' !== sanitize_key( (string) $fresh->get_status() ) ) {
			return new WP_Error( 'mobo_core_final_order_status_changed', 'وضعیت سفارش WooCommerce قبل از پرداخت موبو از «در حال انجام» خارج شد؛ پرداخت موبو متوقف شد.', array( 'action' => 'abort', 'status' => $fresh->get_status() ) );
		}

		$current_snapshot = $this->build_order_submission_business_snapshot( $fresh );
		if ( is_wp_error( $current_snapshot ) ) {
			return new WP_Error( 'mobo_core_final_order_validation_failed', $current_snapshot->get_error_message(), array( 'action' => 'fail', 'originalCode' => $current_snapshot->get_error_code() ) );
		}
		$expected_fingerprint = is_array( $expected_snapshot ) && isset( $expected_snapshot['fingerprint'] ) ? sanitize_text_field( (string) $expected_snapshot['fingerprint'] ) : '';
		$current_fingerprint  = isset( $current_snapshot['fingerprint'] ) ? sanitize_text_field( (string) $current_snapshot['fingerprint'] ) : '';
		if ( '' === $expected_fingerprint || '' === $current_fingerprint || ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) {
			return new WP_Error( 'mobo_core_order_changed_before_payment', 'اقلام، تعداد، مبالغ/روش پرداخت، گیرنده، آدرس یا روش ارسال سفارش هنگام آماده‌سازی موبو تغییر کرد؛ پرداخت انجام نشد و سفارش با داده تازه دوباره در صف قرار گرفت.', array( 'action' => 'defer' ) );
		}

		$current_shipping_id = $this->resolve_mobo_shipping_id( $fresh, is_array( $shippings_json ) ? $shippings_json : array() );
		if ( is_wp_error( $current_shipping_id ) ) {
			return new WP_Error( 'mobo_core_final_shipping_resolve_failed', $current_shipping_id->get_error_message(), array( 'action' => 'fail', 'originalCode' => $current_shipping_id->get_error_code() ) );
		}
		if ( absint( $current_shipping_id ) !== absint( $expected_shipping_id ) ) {
			return new WP_Error( 'mobo_core_shipping_changed_before_payment', 'نگاشت روش ارسال WooCommerce به موبو هنگام آماده‌سازی سفارش تغییر کرد؛ پرداخت انجام نشد و سفارش دوباره در صف قرار گرفت.', array( 'action' => 'defer' ) );
		}

		return $fresh;
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
			$remote_shipping_id = is_array( $shipping ) && array_key_exists( 'id', $shipping )
				? $this->parse_positive_integer_id( $shipping['id'] )
				: 0;
			if ( $remote_shipping_id > 0 && $remote_shipping_id === $shipping_id ) {
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

	private function order_mobo_request( $order, $method, $path, $payload, $step, $allow_auth_retry = true ) {
		$response = $this->mobo_request( $method, $path, $payload );
		if ( $allow_auth_retry && $this->is_auth_error_response( $response ) ) {
			$this->append_order_log( $order, $step . '_auth_retry', array(
				'httpStatus' => is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) ),
				'response'   => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $this->sanitize_order_response_body( wp_remote_retrieve_body( $response ) ),
			) );
			$auth = $this->ensure_mobo_authenticated( true );
			if ( is_wp_error( $auth ) ) {
				$this->append_order_log( $order, $step . '_auth_failed', array( 'error' => $auth->get_error_message() ) );
				return new WP_Error(
					'mobo_core_order_auth_retry_failed',
					$auth->get_error_message(),
					array(
						'originalCode'            => $auth->get_error_code(),
						'requestMayHaveReachedServer' => false,
					)
				);
			}
			$this->append_order_log( $order, $step . '_auth_success', array( 'cookieJar' => $this->mask_cookie_jar( $this->get_mobo_cookie_jar() ) ) );
			$response = $this->mobo_request( $method, $path, $payload );
		}

		$this->append_order_log( $order, $step, array(
			'method'     => strtoupper( (string) $method ),
			'path'       => $this->sanitize_mobo_log_path( $path ),
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
			return new WP_Error(
				'mobo_core_http_error',
				'Mobo API returned HTTP ' . $code . ' for ' . $this->sanitize_mobo_log_path( $path ),
				array(
					'httpStatus' => $code,
					'path'       => $this->sanitize_mobo_log_path( $path ),
				)
			);
		}

		return $response;
	}

	/**
	 * Decide whether a payment error can safely be retried automatically.
	 * Transport failures and server-side/timeout-style responses occur after the
	 * irreversible payment request may have reached Mobo, so they are ambiguous.
	 *
	 * @param WP_Error $error Payment request error.
	 * @return bool
	 */
	private function payment_error_is_ambiguous( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$error_code = (string) $error->get_error_code();
		if ( in_array( $error_code, array( 'mobo_core_shared_cart_lock_lost', 'mobo_core_order_submission_lock_lost', 'mobo_core_shared_cart_lock_unavailable', 'mobo_core_shared_cart_upgrade_barrier', 'mobo_core_mobo_json_error', 'mobo_core_mobo_credentials_missing', 'mobo_core_mobo_login_http_error', 'mobo_core_mobo_login_missing_cookie', 'mobo_core_order_auth_retry_failed' ), true ) ) {
			return false;
		}

		if ( 'mobo_core_http_error' === $error_code ) {
			/* This classifier is called only after the irreversible Wallet POST. An
			 * HTTP error page/status is not proof that Mobo did not charge the wallet.
			 * Automatic replay is therefore forbidden for every non-2xx status. */
			return true;
		}

		/* A raw HTTP transport error means WordPress cannot prove whether Mobo handled the POST. */
		return true;
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

	/**
	 * Detect a WooCommerce business-state change that happened after the last
	 * reversible guard but before/while Mobo Wallet Payment completed. The remote
	 * purchase stays successful; this flag only prevents silent local divergence.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $expected_snapshot Snapshot immediately before remote work.
	 * @return bool True when divergence (or inability to verify) was recorded.
	 */
	private function mark_post_payment_order_divergence( $order_id, $expected_snapshot ) {
		$fresh = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( ! $fresh instanceof WC_Order ) {
			return true;
		}

		$expected_fingerprint = is_array( $expected_snapshot ) && isset( $expected_snapshot['fingerprint'] ) ? sanitize_text_field( (string) $expected_snapshot['fingerprint'] ) : '';
		$current_snapshot     = $this->build_order_submission_business_snapshot( $fresh );
		$current_fingerprint  = is_array( $current_snapshot ) && isset( $current_snapshot['fingerprint'] ) ? sanitize_text_field( (string) $current_snapshot['fingerprint'] ) : '';
		$reason               = '';

		if ( is_wp_error( $current_snapshot ) ) {
			$reason = 'post_payment_snapshot_failed:' . sanitize_key( (string) $current_snapshot->get_error_code() );
		} elseif ( '' === $expected_fingerprint || '' === $current_fingerprint ) {
			$reason = 'post_payment_fingerprint_missing';
		} elseif ( ! hash_equals( $expected_fingerprint, $current_fingerprint ) ) {
			$reason = 'order_changed_after_final_guard';
		}

		$fresh->update_meta_data( '_mobo_order_purchase_business_fingerprint', $expected_fingerprint );
		$already_diverged = 'yes' === (string) $fresh->get_meta( '_mobo_order_post_payment_diverged', true );
		if ( '' === $reason ) {
			/* Post-payment divergence is sticky evidence. If a concurrent actor briefly
			 * changed the order and later changed it back, a later clean fingerprint must
			 * not erase the fact that manual review was already required. Likewise, do
			 * not clear the generic review flag owned by post-success hook failures. */
			$fresh->save();
			return $already_diverged;
		}

		$fresh->update_meta_data( '_mobo_order_post_payment_diverged', 'yes' );
		$fresh->update_meta_data( '_mobo_order_post_payment_diverged_at', time() );
		$fresh->update_meta_data( '_mobo_order_post_payment_divergence_reason', sanitize_key( $reason ) );
		$fresh->update_meta_data( '_mobo_order_requires_review', 'yes' );
		$fresh->save();
		$fresh->add_order_note( 'هشدار: سفارش در موبو پرداخت و ثبت شد، اما وضعیت تجاری سفارش WooCommerce در فاصله بسیار کوتاه مرحله پرداخت تغییر کرده یا قابل تأیید نبود. Retry نکنید؛ اقلام، تعداد، آدرس و روش ارسال با سفارش موبو تطبیق داده شوند.' );
		$this->append_order_log(
			$fresh,
			'order_post_payment_divergence',
			array(
				'reason'              => $reason,
				'expectedFingerprint' => $expected_fingerprint,
				'currentFingerprint'  => $current_fingerprint,
				'currentStatus'       => sanitize_key( (string) $fresh->get_status() ),
			)
		);
		return true;
	}

	private function mark_mobo_order_submission_success( $order, $mobo_order_id, $token, $shipping_id, $payment_json ) {
		if ( ! $order instanceof WC_Order || absint( $mobo_order_id ) <= 0 ) {
			return false;
		}

		$order->update_meta_data( '_mobo_order_submitted', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_status', 'success' );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->update_meta_data( '_mobo_order_submitted_at', time() );
		$order->update_meta_data( '_mobo_order_id', absint( $mobo_order_id ) );
		$order->update_meta_data( '_mobo_order_token', sanitize_text_field( (string) $token ) );
		$order->update_meta_data( '_mobo_order_shipping_id', absint( $shipping_id ) );
		$order->update_meta_data( '_mobo_order_paid', ! empty( $payment_json['paid'] ) && $this->to_bool( $payment_json['paid'] ) ? 'yes' : 'no' );
		$order->delete_meta_data( '_mobo_order_last_error_code' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$order->delete_meta_data( '_mobo_order_failed_at' );
		$order->delete_meta_data( '_mobo_order_uncertain_at' );
		$saved_id = absint( $order->save() );
		if ( $saved_id <= 0 ) {
			return false;
		}

		$fresh = wc_get_order( $saved_id );
		if ( ! $fresh instanceof WC_Order
			|| 'yes' !== (string) $fresh->get_meta( '_mobo_order_submitted', true )
			|| absint( $fresh->get_meta( '_mobo_order_id', true ) ) !== absint( $mobo_order_id )
		) {
			return false;
		}

		$fresh->add_order_note( sprintf( 'اقلام موبو سفارش با موفقیت در موبو ثبت شدند. Mobo Order ID: %s', absint( $mobo_order_id ) ) );
		$this->append_order_log( $fresh, 'order_submission_success', array( 'moboOrderId' => absint( $mobo_order_id ), 'shippingId' => absint( $shipping_id ) ) );
		return true;
	}

	/**
	 * Put a safe-to-retry submission back in the durable queue. This method is only
	 * valid before Wallet Payment has started.
	 *
	 * @return WP_Error
	 */
	private function defer_mobo_order_submission( $order, $code, $message, $context = array() ) {
		$message = sanitize_text_field( (string) $message );
		$code    = sanitize_key( (string) $code );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_order_submission_deferred', $message );
		}

		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->update_meta_data( '_mobo_order_submit_queued', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_queued_at', time() );
		$order->update_meta_data( '_mobo_order_submit_status', 'queued' );
		$order->update_meta_data( '_mobo_order_submit_context', array( 'trigger' => 'automatic_defer', 'reason' => $code ) );
		$order->update_meta_data( '_mobo_order_last_error_code', $code );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->delete_meta_data( '_mobo_order_failed_at' );
		$order->save();

		$this->append_order_log( $order, 'order_submission_deferred', array_merge( array( 'code' => $code, 'message' => $message ), is_array( $context ) ? $context : array() ) );
		$this->queue_mobo_order_id_for_later( absint( $order->get_id() ), array( 'trigger' => 'automatic_defer', 'reason' => $code ) );
		return new WP_Error( 'mobo_core_order_submission_deferred', $message, array( 'reason' => $code ) );
	}

	/**
	 * Stop a purchase when the WooCommerce order becomes ineligible before payment.
	 * Remote cart/checkout preparation may have happened, but Wallet Payment has not.
	 *
	 * @return WP_Error
	 */
	private function abort_mobo_order_submission_before_payment( $order, $code, $message ) {
		$message = sanitize_text_field( (string) $message );
		$code    = sanitize_key( (string) $code );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_order_submission_aborted', $message );
		}

		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->update_meta_data( '_mobo_order_submit_status', 'aborted' );
		$order->update_meta_data( '_mobo_order_last_error_code', $code );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->save();
		$order->add_order_note( 'پرداخت سفارش در موبو قبل از مرحله Wallet متوقف شد: ' . $message );
		$this->append_order_log( $order, 'order_submission_aborted_before_payment', array( 'code' => $code, 'message' => $message ) );
		return new WP_Error( 'mobo_core_order_submission_aborted', $message, array( 'reason' => $code ) );
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

	/**
	 * Keep endpoint diagnostics without persisting query-string secrets such as the
	 * checkout token used by details/shippings requests.
	 *
	 * @param mixed $path Relative Mobo API path.
	 * @return string
	 */
	private function sanitize_mobo_log_path( $path ) {
		$path = (string) $path;
		$parts = explode( '?', $path, 2 );
		$base = sanitize_text_field( (string) $parts[0] );
		if ( count( $parts ) < 2 || '' === trim( (string) $parts[1] ) ) {
			return $base;
		}

		/* The query is never required for support diagnosis; endpoint + HTTP status are
		 * sufficient. Dropping the entire query also protects future secret parameters. */
		return $base . '?[query-masked]';
	}

	private function sanitize_order_response_body( $body ) {
		$body = (string) $body;
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			return $this->sanitize_order_log_value( $json );
		}

		/* HTML/plain-text error pages can contain reflected request data, usernames,
		 * addresses or gateway/session details with no keys available for redaction.
		 * Persist only bounded diagnostics, never the raw non-JSON body. */
		return array(
			'nonJsonBodyOmitted' => true,
			'bytes'              => strlen( $body ),
			'sha256'             => hash( 'sha256', $body ),
		);
	}

	/**
	 * Whether a normalized log key may contain credentials or replayable secrets.
	 *
	 * @param mixed $key Key.
	 * @return bool
	 */
	private function is_sensitive_log_key( $key ) {
		$key = is_string( $key ) ? sanitize_key( $key ) : '';
		return in_array(
			$key,
			array( 'password', 'username', 'userauth', 'csrf', 'csrftoken', 'csrf_token', 'cookie', 'cart', 'token', 'authorization', 'x-sec', 'x_sec', 'securitycode', 'security_code', 'package_token', 'sendername', 'sendermobile', 'billingname', 'billingmobile', 'recipientname', 'recipientmobile', 'name', 'mobile', 'phone', 'email', 'address', 'address1', 'address2', 'zipcode', 'postcode' ),
			true
		);
	}

	private function sanitize_order_log_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$clean_key = is_string( $key ) ? sanitize_key( (string) $key ) : absint( $key );
				if ( $this->is_sensitive_log_key( $clean_key ) ) {
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

		$attempt_state    = $this->get_order_submission_attempt_state( $order );
		$submit_status    = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		$verified_absent  = isset( $_REQUEST['verified_absent'] ) && '1' === sanitize_text_field( wp_unslash( $_REQUEST['verified_absent'] ) );
		$currently_queued = 'queued' === $submit_status || $this->is_order_id_in_option_queue( $order_id );

		if ( 'running_recent' === $attempt_state || $currently_queued ) {
			$order->add_order_note( 'ارسال مجدد به موبو انجام نشد، چون تلاش قبلی هنوز در حال اجرا یا در صف است.' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		if ( in_array( $attempt_state, array( 'running_stale', 'uncertain' ), true ) && ! $verified_absent ) {
			$order->add_order_note( 'Retry این سفارش مسدود شد: نتیجه تلاش قبلی قطعی نیست. ابتدا در موبو بررسی کنید که سفارش/پرداخت ایجاد نشده باشد؛ سپس از دکمه تأییدشده Retry استفاده کنید.' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		$scope = $this->get_order_mobo_item_scope( $order );
		if ( 'invalid' === sanitize_key( (string) ( isset( $scope['status'] ) ? $scope['status'] : '' ) ) ) {
			$this->mark_order_scope_invalid( $order, $scope, 'admin_manual_retry' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'admin_manual_retry' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		$this->append_order_log(
			$order,
			'admin_manual_retry_requested',
			array(
				'userId'                 => get_current_user_id(),
				'previousAttemptState'   => $attempt_state,
				'verifiedAbsentInMobo'   => $verified_absent,
			)
		);
		$result = $this->submit_order_to_mobo( $order, array( 'trigger' => 'admin_manual_retry', 'userId' => get_current_user_id(), 'verifiedAbsentInMobo' => $verified_absent ) );

		if ( true === $result ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$purchase_fingerprint = sanitize_text_field( (string) $order->get_meta( '_mobo_order_purchase_business_fingerprint', true ) );
				if ( '' !== $purchase_fingerprint ) {
					$this->mark_post_payment_order_divergence( $order_id, array( 'fingerprint' => $purchase_fingerprint ) );
					$order = wc_get_order( $order_id );
				}
				if ( ! $order instanceof WC_Order ) {
					wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
					exit;
				}
				$post_payment_diverged = 'yes' === (string) $order->get_meta( '_mobo_order_post_payment_diverged', true );
				$current_status        = sanitize_key( (string) $order->get_status() );

				if ( $post_payment_diverged || 'processing' !== $current_status ) {
					$order->update_meta_data( '_mobo_order_requires_review', 'yes' );
					$order->save();
					$this->append_order_log(
						$order,
						'admin_retry_status_transition_suppressed',
						array(
							'wooStatus'           => $current_status,
							'postPaymentDiverged' => $post_payment_diverged,
						)
					);
				} elseif ( 'mixed' === (string) $scope['status'] ) {
					$order->update_meta_data( '_mobo_order_scope_status', 'mixed' );
					$order->update_meta_data( '_mobo_order_kept_processing', 'yes' );
					$order->save();
					$order->add_order_note( 'اقلام موبو ثبت شدند؛ سفارش ترکیبی برای پردازش اقلام غیرموبو در وضعیت در حال انجام باقی ماند.' );
				} elseif (
					'all_mobo' === (string) $scope['status']
					&& Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_auto_complete_enabled', '1' )
				) {
					$order->update_status( 'completed', 'تمام اقلام سفارش موبو بودند؛ سفارش با موفقیت در موبو ثبت شد و وضعیت به تکمیل شده تغییر کرد.', true );
				}
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
		$stored_token = (string) $order->get_meta( '_mobo_order_token', true );
		$masked_token = '' === $stored_token ? '' : ( strlen( $stored_token ) <= 6 ? '••••••' : '••••••' . substr( $stored_token, -6 ) );
		echo '<p><strong>Token:</strong> ' . esc_html( $masked_token ) . '</p>';
		echo '<p><strong>کد خطا:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_last_error_code', true ) ) . '</p>';
		echo '<p><strong>آخرین خطا:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_last_error', true ) ) . '</p>';
		echo '<p><strong>آخرین مرحله لاگ:</strong> ' . esc_html( $last_action ) . ' / HTTP: ' . esc_html( $last_http ) . '</p>';

		echo '<textarea readonly onclick="this.select();" style="width:100%;min-height:260px;font-family:monospace;font-size:11px;direction:ltr;">' . esc_textarea( $copy ) . '</textarea>';
		echo '<p class="description">این لاگ برای کپی کردن و ارسال به پشتیبان نرم‌افزار است. رمز و cookie داخل آن ذخیره نمی‌شود. روی textarea کلیک کنید تا متن انتخاب شود.</p>';

		if ( 'yes' !== (string) $order->get_meta( '_mobo_order_submitted', true ) && '' === (string) $order->get_meta( '_mobo_order_id', true ) ) {
			$attempt_state    = $this->get_order_submission_attempt_state( $order );
			$submit_status    = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
			$currently_queued = 'queued' === $submit_status || $this->is_order_id_in_option_queue( $order_id );

			if ( 'running_recent' === $attempt_state || $currently_queued ) {
				echo '<p class="description" style="color:#996800;">Retry در دسترس نیست؛ تلاش قبلی هنوز در صف یا در حال اجرا است.</p>';
			} else {
				$verified_absent = in_array( $attempt_state, array( 'running_stale', 'uncertain' ), true );
				$args = array(
					'action'   => 'mobo_core_retry_mobo_order_submission',
					'order_id' => $order_id,
				);
				if ( $verified_absent ) {
					$args['verified_absent'] = '1';
				}
				$retry_url = wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'mobo_core_retry_mobo_order_submission_' . $order_id );

				if ( $verified_absent ) {
					echo '<p class="description" style="color:#b32d2e;"><strong>هشدار:</strong> نتیجه تلاش قبلی قطعی نیست. فقط اگر در پنل موبو بررسی کرده‌اید و مطمئن هستید سفارش/پرداخت ایجاد نشده است Retry کنید.</p>';
					echo '<p style="margin-top:10px;"><a href="' . esc_url( $retry_url ) . '" class="button button-primary" onclick="return confirm(&quot;تأیید می‌کنم در موبو بررسی کرده‌ام و این سفارش/پرداخت ایجاد نشده است. ارسال مجدد انجام شود؟&quot;);">تأیید عدم ثبت در موبو و ارسال مجدد</a></p>';
				} else {
					echo '<p style="margin-top:10px;"><a href="' . esc_url( $retry_url ) . '" class="button button-primary" onclick="return confirm(&quot;ارسال مجدد این سفارش به موبو انجام شود؟&quot;);">ارسال مجدد به موبو</a></p>';
				}
			}
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
		if ( 'uncertain' === $status ) {
			return 'نیازمند بررسی در موبو';
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
