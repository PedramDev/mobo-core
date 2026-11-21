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

	const ORDER_RECOVERY_OPTION_PREFIX = 'mobo_core_mobo_order_recovery_'; // legacy migration source only
	const ORDER_QUEUE_TYPE            = 'order_submission';
	const ORDER_RECOVERY_QUEUE_TYPE   = 'order_recovery';

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
	const ITEM_META_CONNECTION_ID     = '_mobo_identity_connection_id';
	/** M4: item was a Mobo product held in migration quarantine when ordered. */
	const ITEM_META_QUARANTINED       = '_mobo_identity_quarantined';

	/**
	 * Internal immutable line-item identity keys. These values are required for
	 * fail-closed order ownership after checkout, but are never customer-facing
	 * order attributes and must stay hidden from formatted WooCommerce item meta.
	 *
	 * @return string[]
	 */
	public static function get_internal_order_item_meta_keys() {
		return array(
			self::ITEM_META_IDENTITY_CAPTURED,
			self::ITEM_META_IS_MOBO,
			self::ITEM_META_PRODUCT_GUID,
			self::ITEM_META_VARIANT_GUID,
			self::ITEM_META_PORTAL_PRODUCT_ID,
			self::ITEM_META_PORTAL_VARIANT_ID,
			self::ITEM_META_SKU,
			self::ITEM_META_CAPTURED_AT,
			self::ITEM_META_CONNECTION_ID,
		);
	}

	/**
	 * Debug request id, shared by all events generated during the same PHP request.
	 *
	 * @var string|null
	 */
	private $debug_request_id = null;

	/** @var Mobo_Core_Provider_Interface|null Request-local provider resolved from the active Connection Source. */
	private $provider = null;

	/** @var string Source key used to populate the request-local provider cache. */
	private $provider_source_key = '';

	/** @var string Active shared Mobo cart runtime-lock token. */
	private $active_mobo_cart_lock_token = '';

	/** @var int Active shared Mobo cart lock TTL. */
	private $active_mobo_cart_lock_ttl = 60;

	/**
	 * True only after the current shared-cart lease has proven the remote cart
	 * empty. Every add-to-cart mutation is fail-closed unless this rebuild gate
	 * was opened by clear_shared_mobo_cart() under the same runtime lock.
	 *
	 * @var bool
	 */
	private $shared_mobo_cart_rebuild_ready = false;

	/** @var string Active per-order submission lock name. */
	private $active_order_submission_lock_name = '';

	/** @var string Active per-order submission lock token. */
	private $active_order_submission_lock_token = '';

	/** @var int Active per-order submission lock TTL. */
	private $active_order_submission_lock_ttl = 900;

	/** @var string Request-local digest of the admitted server-provisioned route.
	 * Never persist the digest or any credential from the Connection row to an order. */
	private $active_order_route_fingerprint = '';
	/** @var int Owner of the active order's admitted route. */
	private $active_order_route_connection_id = 0;
	/** PERF04: a bounded recovery read must never be interpreted as an empty queue on SQL/corruption failure. */
	private $order_recovery_read_failed = false;

	/**
	 * Register validation hooks.
	 *
	 * @return void
	 */

	/**
	 * Disposable NO-SEND preview; install only on isolated disposable WAMP, never production.
	 * This barrier cannot authorize a provider dispatch, regardless of filters,
	 * authentication, Woo status, a persisted PREPARED row, or local exceptions.
	 */
	private static function p17_blocked( $boundary ) {
		return new WP_Error( 'mobo_p17_no_send_staged', 'Disposable staged: remote side effects are disabled.', array(
			'boundary' => (string) $boundary,
			'purchase_allowed' => false,
			'provider_calls_permitted' => 0,
			'requestMayHaveReachedServer' => false,
			'productionSendAuthorized' => false,
		) );
	}

	/** Operational candidate sink: explicit caller method, unchanged WP response/error semantics. */
	private function production_candidate_transport( $method, $url, array $args ) {
		$args['method'] = $method;
		$response = wp_remote_request( $url, $args );
		/* Source failures feed the support error log (path only, never the query,
		 * headers or body). The response itself is returned unchanged. */
		if ( class_exists( 'Mobo_Core_Error_Log' ) ) {
			if ( is_wp_error( $response ) ) {
				Mobo_Core_Error_Log::capture_wp_error( 'source', $response, array( 'method' => (string) $method, 'endpoint' => (string) $url ) );
			} elseif ( function_exists( 'wp_remote_retrieve_response_code' ) && absint( wp_remote_retrieve_response_code( $response ) ) >= 500 ) {
				Mobo_Core_Error_Log::capture( 'warning', 'source.http_' . absint( wp_remote_retrieve_response_code( $response ) ), 'Source returned HTTP ' . absint( wp_remote_retrieve_response_code( $response ) ) . '.', array( 'method' => (string) $method, 'endpoint' => Mobo_Core_Error_Log::endpoint_path( $url ), 'status' => absint( wp_remote_retrieve_response_code( $response ) ) ) );
			}
		}
		return $response;
	}

	/**
	 * INTEGRATION-04: local-only discovery fingerprint, not a Source quote.
	 * Freeze the exact Woo item identity, variant attributes, quantities,
	 * monetary fields, and local address *before* invoking any remote Location
	 * Resolver. The digest is deliberately not an authorization token.
	 * Never persist the PII snapshot; persist only its SHA-256 fingerprint.
	 * A post-Location, Source-verified snapshot MUST use a distinct, versioned
	 * authorization protocol in the later production implementation.
	 */
	private function p17_local_intent_fingerprint( $order, $scope, $items ) {
		if ( ! $order instanceof WC_Order || ! is_array( $scope ) || ! is_array( $items )
			|| absint( $order->get_id() ) < 1 || absint( $scope['connectionId'] ?? 0 ) < 1 || empty( $items ) ) {
			return new WP_Error( 'mobo_p17_local_intent_unverifiable', 'Local order scope cannot be frozen.' );
		}
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $line ) {
			if ( ! $line instanceof WC_Order_Item_Product ) {
				return new WP_Error( 'mobo_p17_local_intent_unverifiable', 'Order line cannot be frozen.' );
			}
			$meta = array();
			foreach ( $line->get_meta_data() as $field ) {
				if ( ! is_object( $field ) || ! method_exists( $field, 'get_data' ) ) {
					return new WP_Error( 'mobo_p17_local_intent_unverifiable', 'Variant attributes cannot be frozen.' );
				}
				$meta[] = $field->get_data();
			}
			usort( $meta, function ( $a, $b ) { return strcmp( (string) ( $a['id'] ?? 0 ), (string) ( $b['id'] ?? 0 ) ); } );
			$lines[] = array(
				'itemId' => absint( $item_id ),
				'productId' => absint( $line->get_product_id() ),
				'variationId' => absint( $line->get_variation_id() ),
				'quantity' => wc_format_decimal( $line->get_quantity(), 6 ),
				'subtotal' => wc_format_decimal( $line->get_subtotal(), 6 ),
				'total' => wc_format_decimal( $line->get_total(), 6 ),
				'subtotalTax' => wc_format_decimal( $line->get_subtotal_tax(), 6 ),
				'totalTax' => wc_format_decimal( $line->get_total_tax(), 6 ),
				'attributesAndIdentityMeta' => $meta,
			);
		}
		usort( $lines, function ( $a, $b ) { return $a['itemId'] <=> $b['itemId']; } );
		$extra = array();
		foreach ( array( 'shipping', 'fee', 'coupon', 'tax' ) as $type ) {
			$extra[ $type ] = array();
			foreach ( $order->get_items( $type ) as $line ) {
				if ( ! is_object( $line ) || ! method_exists( $line, 'get_data' ) ) {
					return new WP_Error( 'mobo_p17_local_intent_unverifiable', 'Order charges cannot be frozen.' );
				}
				$extra[ $type ][] = $line->get_data();
			}
		}
		$payload = array(
			'contract' => 'P17_LOCAL_ORDER_INTENT_V1_NOT_SOURCE_VERIFIED',
			'orderId' => absint( $order->get_id() ),
			'connectionId' => absint( $scope['connectionId'] ),
			'currency' => (string) $order->get_currency(),
			'orderTotal' => wc_format_decimal( $order->get_total(), 6 ),
			'paymentMethod' => (string) $order->get_payment_method(),
			'localBilling' => $order->get_address( 'billing' ),
			'localShipping' => $order->get_address( 'shipping' ),
			'items' => $items,
			'lines' => $lines,
			'otherOrderLines' => $extra,
		);
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return new WP_Error( 'mobo_p17_local_intent_unverifiable', 'Local order fingerprint could not be encoded.' );
		}
		return hash( 'sha256', $encoded );
	}

	/**
	 * INTEGRATION-05, STAGED ONLY. Reading an immutable, local order intent
	 * must not depend on successful remote Source provisioning. Nevertheless,
	 * the exact locally owned Connection must still exist and be enabled;
	 * neither a missing row nor the Default Connection may be substituted.
	 *
	 * This is NOT a purchase-route check: no Provider or Wallet operation may
	 * rely on it. The normal get_fresh_order_route_fingerprint() gate remains
	 * mandatory for any production mutation.
	 */
	private function p17_local_owner_exists_for_no_send( $connection_id ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' )
			|| ! method_exists( 'Mobo_Core_Connection_Repository', 'get_by_id_fresh' ) ) {
			return false;
		}
		$owner = Mobo_Core_Connection_Repository::get_by_id_fresh( $connection_id );
		return is_array( $owner )
			&& $connection_id === absint( $owner['id'] ?? 0 )
			&& ! empty( $owner['is_enabled'] );
	}

	/**
	 * I07 disposable-only WooCommerce safety: a NO-SEND intent must not
	 * remain in processing/fulfilment. Only PROCESSING may move to ON-HOLD.
	 * Never reopen cancelled/refunded orders or demote completed orders.
	 * Status change is Woo CRUD / HPOS-safe. The processing->on-hold hook
	 * cannot enqueue because the normal submission hook only accepts new
	 * status=processing. This marker is NOT a Source quote or purchase grant.
	 */
	private function p17_hold_no_send( $order, $reason ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$staged_hold = defined( 'MOBO_CORE_PUBLISH_STAGED_NO_SEND' ) && MOBO_CORE_PUBLISH_STAGED_NO_SEND;
		$order->update_meta_data( '_mobo_order_submit_status', 'held_no_send' );
		$order->update_meta_data( '_mobo_order_requires_review', 'yes' );
		$order->update_meta_data( '_mobo_order_last_error_code', $reason );
		$order->update_meta_data( '_mobo_order_last_error', 'سفارش برای بررسی نگه داشته شد؛ هیچ خرید یا برداشت کیف پول انجام نشد.' );
		$staged_mail_guard = null;
		if ( $staged_hold && 'processing' === (string) $order->get_status() ) {
			/* Disposable-only: suppress mail ONLY during this order's status save.
			 * Do not leak the global WP filter into subsequent operations. */
			if ( function_exists( 'add_filter' ) && function_exists( 'remove_filter' ) ) {
				$staged_mail_guard = static function ( $preempt ) { return true; };
				add_filter( 'pre_wp_mail', $staged_mail_guard, PHP_INT_MAX, 1 );
			}
		}
		try {
			if ( $staged_hold && 'processing' === (string) $order->get_status() ) {
				$order->set_status( 'on-hold', 'ارسال موبو در نسخه آزمایشی مسدود است؛ سفارش برای بررسی نگه داشته شد.' );
			}
			$saved_id = absint( $order->save() );
		} finally {
			if ( null !== $staged_mail_guard ) {
				remove_filter( 'pre_wp_mail', $staged_mail_guard, PHP_INT_MAX );
			}
		}
		return $saved_id === absint( $order->get_id() )
			&& ( ! $staged_hold || 'on-hold' === (string) $order->get_status() );
	}

	/**
	 * P17 staged Outbox PREPARED-only wiring. The genuine pinned Store itself
	 * returns purchase_allowed=false and provider_calls_permitted=0.
	 * This function ALWAYS returns an error, even when prepare_intent succeeds.
	 * No DDL, no migration, no authorization, no provider or wallet invocation.
	 */
	private function p17_prepare_outbox_no_send( $order, $scope, $business_snapshot ) {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? absint( $order->get_id() ) : 0;
		$connection_id = is_array( $scope ) && isset( $scope['connectionId'] ) ? absint( $scope['connectionId'] ) : 0;
		$fingerprint = is_array( $business_snapshot ) && isset( $business_snapshot['fingerprint'] ) && is_string( $business_snapshot['fingerprint'] )
			? $business_snapshot['fingerprint'] : '';
		if ( 0 === $order_id || 0 === $connection_id || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			$this->p17_hold_no_send( $order, 'staged_outbox_scope_unverified' );
			return self::p17_blocked( 'invalid_outbox_scope' );
		}
		if ( ! class_exists( 'Mobo_Core_Purchase_Outbox_Store' ) || ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			$this->p17_hold_no_send( $order, 'staged_outbox_store_unavailable' );
			return self::p17_blocked( 'outbox_store_unavailable' );
		}
		$frozen = array(
			'status' => 'FROZEN_NOT_AUTHORIZED',
			'order_id' => $order_id,
			'connection_id' => (string) $connection_id,
			'generation' => 1, // P17 no-send discovery only; production generation model NOT defined.
			'payload_fingerprint' => $fingerprint,
			'idempotency_key' => 'mobo1_' . hash( 'sha256', 'p17-no-send' . "\0" . $order_id . "\0" . $connection_id . "\0" . $fingerprint ),
			'purchase_allowed' => false,
			'provider_calls_permitted' => 0,
		);
		$store = new Mobo_Core_Purchase_Outbox_Store( $GLOBALS['wpdb'] );
		$result = $store->prepare_intent( $frozen );
		/* A PREPARED or pre-existing row is NOT Source verification and does not
		 * authorize purchase. Every other status remains held for review. */
		$outbox_status = is_array( $result ) && isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : 'UNVERIFIED';
		$known_durable = in_array( $outbox_status, array( 'PREPARED_DURABLE_NOT_AUTHORIZED', 'EXISTING_DURABLE_NOT_AUTHORIZED' ), true );
		$saved_hold = $this->p17_hold_no_send( $order, $known_durable ? 'staged_outbox_no_send' : 'staged_outbox_unverified' );
		$error = self::p17_blocked( 'outbox_prepared_but_dispatch_forbidden' );
		// Do not expose credentials, raw request bodies, or DB error strings.
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array(
			'boundary' => 'outbox_prepared_but_dispatch_forbidden',
			'wooHoldSaved' => $saved_hold,
			'outboxStatus' => $outbox_status,
			'purchase_allowed' => false,
			'provider_calls_permitted' => 0,
			'requestMayHaveReachedServer' => false,
			'productionSendAuthorized' => false,
		) );
	}

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
			if ( class_exists( 'Mobo_Core_Order_Price_Review' ) ) {
				Mobo_Core_Order_Price_Review::register_admin_hooks();
			}
			if ( class_exists( 'Mobo_Core_Address_Attention' ) ) {
				Mobo_Core_Address_Attention::register_admin_hooks();
			}
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
		$connection_id = $this->resolve_woo_item_connection_id( $parent_id, $variation_id );
		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) && $current_connection_id !== $connection_id ) {
			Mobo_Core_Connection_Context::run( $connection_id, function () use ( $item, $product ) { $this->snapshot_order_line_item_identity( $item, $product ); } );
			return;
		}
		/* Terminal ownership is authoritative. Identity compatibility values are
		 * meaningful only after Custom Storage proves the owning Connection. */
		$is_mobo = $connection_id > 0;
		$product_guid = $is_mobo ? $this->get_remote_product_guid( $parent_id, $wc_id ) : '';
		$variant_guid = $is_mobo ? $this->get_variant_guid( $variation_id, $wc_id ) : '';
		$portal_product_id = $is_mobo ? $this->get_portal_product_id( $parent_id, $wc_id ) : 0;
		$portal_variant_id = $is_mobo ? $this->get_portal_variant_id( $variation_id, $wc_id ) : 0;

		$item->update_meta_data( self::ITEM_META_IDENTITY_CAPTURED, 'yes' );
		$item->update_meta_data( self::ITEM_META_IS_MOBO, $is_mobo ? 'yes' : 'no' );
		$item->update_meta_data( self::ITEM_META_CAPTURED_AT, time() );
		$item->update_meta_data( self::ITEM_META_PRODUCT_GUID, $is_mobo ? $product_guid : '' );
		$item->update_meta_data( self::ITEM_META_VARIANT_GUID, $is_mobo ? $variant_guid : '' );
		$item->update_meta_data( self::ITEM_META_PORTAL_PRODUCT_ID, $is_mobo ? $portal_product_id : 0 );
		$item->update_meta_data( self::ITEM_META_PORTAL_VARIANT_ID, $is_mobo ? $portal_variant_id : 0 );
		$item->update_meta_data( self::ITEM_META_SKU, $product instanceof WC_Product ? sanitize_text_field( (string) $product->get_sku() ) : '' );
		$item->update_meta_data( self::ITEM_META_CONNECTION_ID, $is_mobo ? $connection_id : 0 );
		if ( ! $is_mobo && $this->is_quarantined_mobo_line_item( $item ) ) {
			$item->update_meta_data( self::ITEM_META_QUARANTINED, 'yes' );
		}
	}

	/**
	 * M4: a Mobo-owned product/variation held in migration quarantine has no
	 * Custom ownership row, so it looks "non-Mobo". It must never be silently
	 * skipped: such orders go to manual review instead.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function is_quarantined_mobo_line_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}
		if ( 'yes' === (string) $item->get_meta( self::ITEM_META_QUARANTINED, true ) ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_Migration_Quarantine' ) || Mobo_Core_Migration_Quarantine::count() <= 0 ) {
			return false;
		}
		$variation_id = absint( $item->get_variation_id() );
		$product_id   = absint( $item->get_product_id() );
		return ( $variation_id > 0 && Mobo_Core_Migration_Quarantine::is_marked( $variation_id ) )
			|| ( $product_id > 0 && Mobo_Core_Migration_Quarantine::is_marked( $product_id ) );
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
			'connectionId'    => 0,
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
			$out['connectionId']    = absint( $item->get_meta( self::ITEM_META_CONNECTION_ID, true ) );
			return $out;
		}

		$product_id   = absint( $item->get_product_id() );
		$variation_id = absint( $item->get_variation_id() );
		$wc_id        = $product instanceof WC_Product ? absint( $product->get_id() ) : ( $variation_id > 0 ? $variation_id : $product_id );
		$parent_id    = $product_id > 0 ? $product_id : $wc_id;
		$owner_connection_id = $this->resolve_woo_item_connection_id( $parent_id, $variation_id );
		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $owner_connection_id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) && $current_connection_id !== $owner_connection_id ) {
			return Mobo_Core_Connection_Context::run( $owner_connection_id, function () use ( $item, $product ) { return $this->get_order_line_item_identity( $item, $product ); } );
		}
		$out['connectionId'] = $owner_connection_id;
		$out['isMobo']       = $owner_connection_id > 0;
		if ( $out['isMobo'] ) {
			$out['productGuid']     = $this->get_remote_product_guid( $parent_id, $wc_id );
			$out['variantGuid']     = $this->get_variant_guid( $variation_id, $wc_id );
			$out['portalProductId'] = $this->get_portal_product_id( $parent_id, $wc_id );
			$out['portalVariantId'] = $this->get_portal_variant_id( $variation_id, $wc_id );
		}
		$out['sku'] = $product instanceof WC_Product ? sanitize_text_field( (string) $product->get_sku() ) : '';
		return $out;
	}

	/** Resolve the globally-owned Connection for one Woo line item. @return int */
	private function resolve_woo_item_connection_id( $product_id, $variation_id = 0 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$product_owner_id = 0;
		$variation_owner_id = 0;
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			if ( $variation_id > 0 && class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
				$row = ( new Mobo_Core_Variant_State_Repository() )->get_owner_by_woo_variation_id( $variation_id );
				$variation_owner_id = is_array( $row ) ? absint( $row['connection_id'] ?? 0 ) : 0;
			}
			if ( $product_id > 0 && class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
				$row = ( new Mobo_Core_Product_State_Repository() )->get_owner_by_woo_product_id( $product_id );
				$product_owner_id = is_array( $row ) ? absint( $row['connection_id'] ?? 0 ) : 0;
			}
		}
		if ( $variation_owner_id > 0 && $product_owner_id > 0 && $variation_owner_id !== $product_owner_id ) { return 0; }
		return $variation_owner_id > 0 ? $variation_owner_id : $product_owner_id;
	}

	/**
	 * Validate cart and add WooCommerce notices.
	 *
	 * @return void
	 */
	public function validate_cart_notices() {
		if ( ! $this->has_active_checkout_validation_checks_for_current_cart() ) {
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
		if ( ! ( $errors instanceof WP_Error ) ) {
			return;
		}

		/* HARDEN10 financial boundary: automatic Mobo submission is all-or-nothing.
		 * Reject a mixed, multi-Connection or ownership-ambiguous cart before the
		 * payment gateway can create an otherwise-paid order that policy will later
		 * refuse to submit remotely. Optional remote/cart validation switches remain
		 * independent and are not forced on by this scope preflight. */
		$scope_error = $this->validate_auto_order_cart_scope_pre_payment();
		if ( is_wp_error( $scope_error ) ) {
			$errors->add( 'mobo_core_checkout_order_scope', sanitize_text_field( (string) $scope_error->get_error_message() ) );
			return;
		}

		/* B6: unmapped shipping method / unresolvable destination are rejected before
		 * payment for Mobo-only auto-order carts (fails open on our outage). */
		if ( empty( $errors->get_error_codes() ) ) {
			$destination_error = $this->validate_auto_order_destination_pre_payment( is_array( $data ) ? $data : array(), null );
			if ( is_wp_error( $destination_error ) ) {
				$errors->add( 'mobo_core_checkout_destination', sanitize_text_field( (string) $destination_error->get_error_message() ) );
				return;
			}
		}

		if ( ! $this->has_active_checkout_validation_checks_for_current_cart() ) {
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

		$scope_error = $this->validate_auto_order_order_scope_pre_payment( $order );
		if ( is_wp_error( $scope_error ) ) {
			throw new Exception( sanitize_text_field( (string) $scope_error->get_error_message() ) );
		}

		$destination_error = $this->validate_auto_order_destination_pre_payment( array(), $order );
		if ( is_wp_error( $destination_error ) ) {
			throw new Exception( sanitize_text_field( (string) $destination_error->get_error_message() ) );
		}

		if ( ! $this->has_active_checkout_validation_checks_for_current_cart() ) {
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
		$result = Mobo_Core_Settings::get( 'mobo_core_checkout_last_validation_result', array() );

		if ( ! is_array( $result ) ) {
			$result = array();
		}

		$master_enabled = $this->is_enabled();
		$mobo_cart_raw = $this->is_mobo_cart_validation_enabled();
		$auto_order_enabled = Mobo_Core_Order_Submission_Policy::is_enabled();
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
			'lastAttemptAt'    => absint( Mobo_Core_Settings::get( 'mobo_core_checkout_last_validation_attempt_at', 0 ) ),
			'lastSuccessAt'    => absint( Mobo_Core_Settings::get( 'mobo_core_checkout_last_validation_success_at', 0 ) ),
			'lastMoboLoginAt'  => absint( Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_login_success_at', 0 ) ),
			'lastMoboCartAt'   => absint( Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_cart_success_at', 0 ) ),
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
				Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_at', time(), false );
				Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_result', 'ناموفق', false );
				Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_error', 'Login check lock unavailable [' . substr( sanitize_key( (string) $lock->get_error_code() ), 0, 64 ) . '].', false );
				return $lock;
			}
			$owns_lock = true;
		}

		try {
			$this->clear_mobo_cookie_jar();
			$result = $this->ensure_mobo_authenticated( true );

			Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_at', time(), false );

			if ( is_wp_error( $result ) ) {
				Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_result', 'ناموفق', false );
				Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_error', 'Login check failed [' . substr( sanitize_key( (string) $result->get_error_code() ), 0, 64 ) . '].', false );
				return $result;
			}

			Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_test_result', 'موفق', false );
			Mobo_Core_Settings::delete( 'mobo_core_checkout_mobo_login_test_error' );
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

			$path     = $this->get_provider_endpoint( 'wallet_balance' );
			if ( '' === $path ) { return new WP_Error( 'mobo_core_provider_wallet_unsupported', 'The active Source provider does not support wallet balance.' ); }
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

			/* CP34: preserve the original JSON numeric literal from the HTTP body
			 * before json_decode can silently round it. Display-only; never an
			 * authorization to debit Wallet or purchase from the Provider. */
			$raw_wallet_response = (string) wp_remote_retrieve_body( $response );
			$observation = Mobo_Core_Wallet_Balance_Observation::inspect_raw_response( $raw_wallet_response );
			if ( 'RESPONSE_NOT_SUCCESS' === $observation['status'] ) {
				$message = 'Mobo wallet balance response was not successful.';
				return new WP_Error( 'mobo_core_wallet_balance_not_success', $message );
			}
			if ( in_array( $observation['status'], array( 'INVALID_RAW_RESPONSE', 'INVALID_RESPONSE_ENVELOPE', 'DUPLICATE_WIRE_FIELD' ), true ) ) {
				return new WP_Error( 'mobo_core_wallet_balance_json_error', 'Mobo wallet balance JSON response failed wire verification.' );
			}
			if ( 'MISSING_AMOUNT' === $observation['status'] ) {
				return new WP_Error( 'mobo_core_wallet_balance_missing_amount', 'Mobo wallet balance response did not include an amount.' );
			}
			if ( 'INVALID_SUCCESS_ACKNOWLEDGEMENT' === $observation['status'] ) {
				return new WP_Error( 'mobo_core_wallet_balance_invalid_success_ack', 'Mobo wallet balance response success acknowledgement is malformed.' );
			}
			if ( 'DISPLAY_ONLY_UNVERIFIED_UNIT' !== $observation['status'] ) {
				return new WP_Error( 'mobo_core_wallet_balance_invalid_amount', 'Mobo wallet balance amount is not safely representable.' );
			}
			return $observation['balance'];
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
		$connection_ids = $this->get_current_cart_mobo_connection_ids();
		if ( 1 === count( $connection_ids ) && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$connection_id = absint( reset( $connection_ids ) );
			return Mobo_Core_Connection_Context::run( $connection_id, function () use ( $include_external, $connection_id ) { return $this->validate_current_cart_in_connection( $include_external, $connection_id ); } );
		}

		/* A cart spanning multiple Sources has no single shared remote account/cart.
		 * Validate each Source-owned subset inside its own Connection context instead
		 * of skipping configured pre-purchase checks or probing the Default Connection.
		 * Automatic order submission remains independently fail-closed for the final
		 * multi-Connection order. */
		if ( count( $connection_ids ) > 1 ) {
			$errors = array();
			$items  = array();
			$parts  = array();
			foreach ( $connection_ids as $connection_id ) {
				$connection_id = absint( $connection_id );
				if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
					continue;
				}
				$part = Mobo_Core_Connection_Context::run( $connection_id, function () use ( $include_external, $connection_id ) {
					return $this->validate_current_cart_in_connection( $include_external, $connection_id );
				} );
				if ( is_array( $part ) ) {
					$parts[ $connection_id ] = $part;
					if ( isset( $part['errors'] ) && is_array( $part['errors'] ) ) {
						$errors = array_merge( $errors, $part['errors'] );
					}
					if ( isset( $part['meta']['items'] ) && is_array( $part['meta']['items'] ) ) {
						$items = array_merge( $items, $part['meta']['items'] );
					}
				}
			}
			$errors = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $errors ) ) ) );
			return $this->result( empty( $errors ), $errors, array( 'items' => $items, 'connectionIds' => $connection_ids, 'connectionResults' => $parts ) );
		}

		return $this->validate_current_cart_in_connection( $include_external, 0 );
	}

	/** Validate the active cart inside its owning Connection context. @return array */
	private function validate_current_cart_in_connection( $include_external = true, $target_connection_id = 0 ) {
		$errors = array();
		$items  = $this->build_cart_items_payload( $errors, $target_connection_id );

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
		/* Core validation errors are a customer-safety boundary whenever the merchant
		 * enabled the corresponding check. Third-party filters may add diagnostics or
		 * stricter validation, but they must never erase built-in failures. */
		$errors = array_merge( $core_errors, $filtered_errors );

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

	/** Return unique Connection owners represented by Mobo items in the live cart. @return array<int,int> */
	private function get_current_cart_mobo_connection_ids() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return array(); }
		$cart = WC()->cart->get_cart();
		if ( ! is_array( $cart ) ) { return array(); }
		$ids = array();
		foreach ( $cart as $cart_item ) {
			$product_id = absint( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
			$variation_id = absint( isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0 );
			$id = $this->resolve_woo_item_connection_id( $product_id, $variation_id );
			if ( $id > 0 ) { $ids[ $id ] = $id; }
		}
		return array_values( $ids );
	}

	/** Evaluate validation switches in the owning Connection rather than Default. @return bool */
	private function has_active_checkout_validation_checks_for_current_cart() {
		$ids = $this->get_current_cart_mobo_connection_ids();
		if ( empty( $ids ) ) { return false; }
		foreach ( $ids as $id ) {
			$active = class_exists( 'Mobo_Core_Connection_Context' )
				? Mobo_Core_Connection_Context::run( $id, function () { return $this->has_active_checkout_validation_checks(); } )
				: $this->has_active_checkout_validation_checks();
			if ( $active ) { return true; }
		}
		return false;
	}


	/**
	 * Classify the live WooCommerce cart for the automatic-order all-or-nothing
	 * pre-payment boundary. This intentionally performs no remote request.
	 *
	 * @return array
	 */
	private function get_current_cart_order_scope() {
		$scope = array(
			'status'        => 'empty',
			'total'         => 0,
			'mobo'          => 0,
			'nonMobo'       => 0,
			'invalid'       => 0,
			'connectionId'  => 0,
			'connectionIds' => array(),
		);
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return $scope; }
		$cart = WC()->cart->get_cart();
		if ( ! is_array( $cart ) ) { return $scope; }
		$connections = array();
		foreach ( $cart as $cart_item ) {
			if ( ! is_array( $cart_item ) ) { $scope['invalid']++; $scope['total']++; continue; }
			$product_id   = absint( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
			$variation_id = absint( isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0 );
			$scope['total']++;
			if ( $product_id <= 0 && $variation_id <= 0 ) { $scope['invalid']++; continue; }
			$connection_id = $this->resolve_woo_item_connection_id( $product_id, $variation_id );
			if ( $connection_id > 0 ) {
				$scope['mobo']++;
				$connections[ $connection_id ] = true;
			} else {
				$scope['nonMobo']++;
			}
		}
		$scope['connectionIds'] = array_values( array_map( 'absint', array_keys( $connections ) ) );
		if ( 1 === count( $scope['connectionIds'] ) ) { $scope['connectionId'] = absint( $scope['connectionIds'][0] ); }
		if ( $scope['total'] <= 0 ) {
			$scope['status'] = 'empty';
		} elseif ( $scope['invalid'] > 0 ) {
			$scope['status'] = 'invalid';
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] > 0 ) {
			$scope['status'] = 'mixed';
		} elseif ( count( $scope['connectionIds'] ) > 1 ) {
			$scope['status'] = 'multi_connection';
		} elseif ( $scope['mobo'] > 0 ) {
			$scope['status'] = 'all_mobo';
		} else {
			$scope['status'] = 'non_mobo';
		}
		return $scope;
	}

	/**
	 * Whether automatic submission is enabled for at least one Mobo owner that is
	 * actually represented by this checkout. An unrelated enabled Connection must
	 * never make an otherwise non-Mobo cart fail.
	 *
	 * @param array $connection_ids Cart/order owner ids.
	 * @return bool
	 */
	private function auto_order_enabled_for_connection_ids( $connection_ids ) {
		if ( ! class_exists( 'Mobo_Core_Order_Submission_Policy' ) ) { return false; }
		$connection_ids = is_array( $connection_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $connection_ids ) ) ) ) : array();
		foreach ( $connection_ids as $connection_id ) {
			$enabled = class_exists( 'Mobo_Core_Connection_Context' )
				? Mobo_Core_Connection_Context::run( $connection_id, function () { return Mobo_Core_Order_Submission_Policy::is_enabled(); } )
				: Mobo_Core_Order_Submission_Policy::is_enabled();
			if ( $enabled ) { return true; }
		}
		return false;
	}

	/** Build the customer-safe error for a pre-payment automatic-order scope block. */
	private function auto_order_scope_pre_payment_error( $scope ) {
		$status = sanitize_key( (string) ( is_array( $scope ) && isset( $scope['status'] ) ? $scope['status'] : 'invalid' ) );
		if ( 'mixed' === $status ) {
			return new WP_Error( 'mobo_core_checkout_mixed_order_blocked', 'این سبد خرید شامل محصول موبو و غیرموبو است. برای جلوگیری از پرداخت سفارشی که امکان ارسال کامل خودکار آن وجود ندارد، پرداخت متوقف شد.' );
		}
		if ( 'multi_connection' === $status ) {
			return new WP_Error( 'mobo_core_checkout_multi_connection_blocked', 'این سبد خرید شامل محصولات متعلق به بیش از یک منبع موبو است. تا زمان تعریف Split Order، پرداخت این سبد برای ارسال خودکار متوقف است.' );
		}
		if ( 'invalid' === $status ) {
			return new WP_Error( 'mobo_core_checkout_owner_ambiguous', 'مالکیت حداقل یک آیتم سبد خرید قابل اثبات نیست. برای جلوگیری از خرید ناقص، پرداخت متوقف شد.' );
		}
		return true;
	}

	/** Classic checkout pre-payment all-or-nothing automatic-order guard. */
	private function validate_auto_order_cart_scope_pre_payment() {
		$scope = $this->get_current_cart_order_scope();
		if ( ! $this->auto_order_enabled_for_connection_ids( $scope['connectionIds'] ?? array() ) ) { return true; }
		return $this->auto_order_scope_pre_payment_error( $scope );
	}

	/**
	 * B6 pre-payment destination guard for a Mobo-only cart/order whose single owning
	 * Connection submits orders automatically. Runs inside that Connection. Any
	 * failure to evaluate allows checkout (see Mobo_Core_Checkout_Preflight).
	 *
	 * @param array         $data Classic posted data.
	 * @param WC_Order|null $order Store API draft order, null for classic checkout.
	 * @return true|WP_Error
	 */
	private function validate_auto_order_destination_pre_payment( $data, $order ) {
		if ( ! class_exists( 'Mobo_Core_Checkout_Preflight' ) ) {
			return true;
		}
		try {
			$scope = $order instanceof WC_Order ? $this->get_order_mobo_item_scope( $order ) : $this->get_current_cart_order_scope();
			$connection_id = absint( $scope['connectionId'] ?? 0 );
			if ( 'all_mobo' !== ( $scope['status'] ?? '' ) || $connection_id <= 0 || ! $this->auto_order_enabled_for_connection_ids( array( $connection_id ) ) ) {
				return true;
			}
			$run = function () use ( $data, $order ) {
				return $order instanceof WC_Order
					? Mobo_Core_Checkout_Preflight::validate_order( $order, $this->get_order_address_group_for_mobo( $order ) )
					: Mobo_Core_Checkout_Preflight::validate_checkout_data( $data );
			};
			$result = class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::run( $connection_id, $run ) : $run();
			return is_wp_error( $result ) ? $result : true;
		} catch ( Throwable $e ) {
			return true;
		}
	}

	/** Checkout Block/Store API equivalent using the already-frozen order identity. */
	private function validate_auto_order_order_scope_pre_payment( $order ) {
		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->auto_order_enabled_for_connection_ids( $scope['connectionIds'] ?? array() ) ) { return true; }
		return $this->auto_order_scope_pre_payment_error( $scope );
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
		/* S1: a clone/staging copy must not clear/rebuild the original site's shared
		 * Source cart during its own checkout; its orders are held for review. */
		if ( $this->clone_guard_blocks_purchase() ) {
			return false;
		}
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
	private function build_cart_items_payload( &$errors, $target_connection_id = 0 ) {
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
		/* Purchase eligibility is independent from sync convergence. Identity and authoritative cart/stock validation remain fail-closed. */
		$block_incomplete   = false;
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

			$connection_id = $this->resolve_woo_item_connection_id( $parent_id, $variation_id );
			$target_connection_id = absint( $target_connection_id );
			if ( $target_connection_id > 0 && $connection_id > 0 && $connection_id !== $target_connection_id ) {
				/* Multi-Connection validation is intentionally partitioned by durable owner. */
				continue;
			}
			$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			if ( $connection_id > 0 && $current_connection_id > 0 && $connection_id !== $current_connection_id ) {
				$errors[] = 'مالک Connection یکی از محصولات سبد خرید با context اعتبارسنجی همخوانی ندارد.';
				continue;
			}

			/* Terminal Custom Storage ownership is the authority for whether an item is
			 * Mobo-owned. Compatibility meta may expose identity values, but stale/legacy
			 * postmeta alone must never resurrect Mobo checkout ownership. */
			$is_mobo_item = $connection_id > 0;
			if ( $only_mobo_products && ! $is_mobo_item ) {
				continue;
			}

			$product_guid      = $this->get_remote_product_guid( $parent_id, $product_id );
			$variant_guid      = $this->get_variant_guid( $variation_id, $product_id );
			$portal_product_id = $this->get_portal_product_id( $parent_id, $product_id );
			$portal_variant_id = $this->get_portal_variant_id( $variation_id, $product_id );

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
				'connectionId'     => $connection_id,
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

		Mobo_Core_Settings::update( 'mobo_core_checkout_last_validation_attempt_at', time(), false );
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
					/*
					 * Never re-authenticate and retry an individual Add in-place. Authentication
					 * can switch the remote cart/session, which invalidates the preceding Clear.
					 * The next validation attempt must restart the whole Clear -> Add All flow.
					 */
					$this->shared_mobo_cart_rebuild_ready = false;
					$this->debug_log( 'shared_cart_add_auth_changed_rebuild_required', array( 'portalVariantId' => $portal_variant_id ) );
					$errors[] = 'نشست موبو هنگام بازسازی سبد منقضی شد؛ برای جلوگیری از ترکیب سبد قدیمی و جدید، بازسازی باید از مرحله پاک‌سازی کامل دوباره شروع شود.';
					break;
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

				if ( $this->is_auth_error_response( $snapshot ) ) {
					$this->shared_mobo_cart_rebuild_ready = false;
					$this->debug_log( 'shared_cart_snapshot_auth_changed_rebuild_required', array() );
					$errors[] = 'نشست موبو پیش از تأیید نهایی سبد منقضی شد؛ بازسازی سبد باید از مرحله پاک‌سازی کامل دوباره انجام شود.';
				} elseif ( is_wp_error( $snapshot ) ) {
					$this->debug_log( 'shared_cart_snapshot_failed', array( 'error' => $snapshot->get_error_message() ) );
					$errors[] = 'خواندن snapshot سبد موبو بعد از آماده‌سازی انجام نشد.';
				} else {
					$compare_errors = $this->compare_mobo_snapshot_with_items( $snapshot, $items );

					if ( ! empty( $compare_errors ) ) {
						$errors = array_merge( $errors, $compare_errors );
					} else {
						/* Rebuild is complete; no later add may reuse this clear proof. */
						$this->shared_mobo_cart_rebuild_ready = false;
					}
				}
			}

			$this->store_mobo_validation_result( empty( $errors ), empty( $errors ) ? 200 : 0, 'shared_cart_validation', empty( $errors ) ? 'OK' : 'Shared Mobo cart validation failed.', $results );
			$this->debug_log( 'shared_cart_validation_finish', array( 'success' => empty( $errors ), 'errorCount' => count( $errors ), 'resultCount' => count( $results ) ) );

			if ( empty( $errors ) ) {
				Mobo_Core_Settings::update( 'mobo_core_checkout_last_validation_success_at', time(), false );
				Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cart_success_at', time(), false );
			}

			return $errors;
		} finally {
			$this->release_mobo_cart_lock( $lock );
		}
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
				/* A new lease never inherits proof that a previous rebuild started empty. */
				$this->shared_mobo_cart_rebuild_ready = false;

				/* Credential changes request a cookie reset without mutating the shared
				 * session underneath an active checkout. Apply that reset only after this
				 * request owns the same cart/session lease. */
				if ( '1' === (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_cookie_reset_pending', '0' ) ) {
					Mobo_Core_Settings::delete( 'mobo_core_checkout_mobo_cookie_jar' );
					if ( null !== Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_cookie_jar', null ) ) {
						Mobo_Core_Lock::release( 'shared_mobo_cart', $this->active_mobo_cart_lock_token );
						$this->active_mobo_cart_lock_token = '';
						return new WP_Error( 'mobo_core_shared_cart_cookie_reset_failed', 'Shared Mobo session reset is pending because the cookie jar could not be cleared.' );
					}
					Mobo_Core_Settings::delete( 'mobo_core_checkout_mobo_cookie_reset_pending' );
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
			Mobo_Core_Environment::sleep_us( 250000 );
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
		/* Never allow a later add to reuse clear-proof from a released lease. */
		$this->shared_mobo_cart_rebuild_ready = false;
		$this->debug_log( 'shared_cart_lock_released', array() );
	}

	/**
	 * Clear all rows from the one shared Mobo cart.
	 *
	 * @return true|WP_Error
	 */
	private function clear_shared_mobo_cart() {

		/* Clear proof is per-attempt: close the add gate until emptiness is proven. */
		$this->shared_mobo_cart_rebuild_ready = false;

		if ( ! $this->provider_uses_replace_cart_sync() ) {
			return new WP_Error( 'mobo_core_provider_cart_strategy_unsupported', 'The active Source provider does not support replace/rebuild cart synchronization.' );
		}
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
				$this->shared_mobo_cart_rebuild_ready = true;
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
				$clear_path = $this->get_provider_endpoint( 'cart_clear_item', array( 'cart_item_id' => $cart_item_id ) );
				if ( '' === $clear_path ) { return new WP_Error( 'mobo_core_provider_cart_clear_unsupported', 'The active Source provider cannot clear the remote cart safely.' ); }
				$response = $this->mobo_request( 'DELETE', $clear_path, null );

				if ( $this->is_auth_error_response( $response ) ) {
					$auth = $this->ensure_mobo_authenticated( true );
					if ( is_wp_error( $auth ) ) {
						return $auth;
					}
					$response = $this->mobo_request( 'DELETE', $clear_path, null );
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
				$this->shared_mobo_cart_rebuild_ready = true;
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
	 * @param float         $quantity Quantity.
	 * @param WC_Order|null $order Optional order for durable request logging.
	 * @return array|WP_Error
	 */
	private function add_mobo_cart_item_by_variant( $portal_variant_id, $quantity, $order = null ) {

		$portal_variant_id = absint( $portal_variant_id );
		$quantity          = max( 0, (float) $quantity );

		if ( $portal_variant_id <= 0 ) {
			return new WP_Error( 'mobo_core_invalid_portal_variant_id', 'Invalid portal_variant_id.' );
		}

		/*
		 * Deterministic replace/rebuild invariant: no POST /cart is legal until the
		 * current shared-cart lease has read the authoritative remote cart, removed
		 * every existing row and proven it empty. This makes Clear -> Add All a
		 * runtime rule instead of only a call-site convention.
		 */
		if ( ! $this->shared_mobo_cart_rebuild_ready || '' === sanitize_text_field( (string) $this->active_mobo_cart_lock_token ) ) {
			return new WP_Error(
				'mobo_core_shared_cart_clear_required_before_add',
				'Remote cart add was blocked because the cart was not proven empty under the active shared-cart lock.'
			);
		}

		$provider = $this->get_mobo_provider();
		$path = $this->get_provider_endpoint( 'cart_add' );
		if ( ! $provider instanceof Mobo_Core_Provider_Interface || '' === $path ) {
			return new WP_Error( 'mobo_core_provider_cart_add_unsupported', 'The active Source provider does not support cart rebuild.' );
		}

		$payload  = $provider->build_cart_add_payload( $portal_variant_id, $quantity );
		$response = $this->mobo_request( 'POST', $path, $payload );

		/*
		 * Cart Add intentionally bypasses order_mobo_request()'s generic auth retry.
		 * Re-authentication may bind a different remote cart, so only a complete
		 * rebuild beginning with clear_shared_mobo_cart() may retry this mutation.
		 */
		if ( $order instanceof WC_Order ) {
			$this->append_order_log( $order, 'cart_add_item', array(
				'method'     => 'POST',
				'path'       => $this->sanitize_mobo_log_path( $path ),
				'httpStatus' => is_wp_error( $response ) ? 0 : absint( wp_remote_retrieve_response_code( $response ) ),
				'cookieJar'  => $this->mask_cookie_jar( $this->get_mobo_cookie_jar() ),
				'payload'    => $this->sanitize_order_log_value( $payload ),
				'response'   => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $this->sanitize_order_response_body( wp_remote_retrieve_body( $response ) ),
			) );
		}

		return $response;
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
	 * Ensure Mobo session is authenticated.
	 *
	 * @param bool $force Force login.
	 * @return true|WP_Error
	 */
	private function ensure_mobo_authenticated( $force = false ) {

		$route = $this->fresh_checkout_route_preflight();
		if ( is_wp_error( $route ) ) { return $route; }
		if ( '' === $this->get_mobo_site_url() ) {
			return new WP_Error( 'mobo_core_source_checkout_unavailable', 'The active Mobo source does not define a checkout storefront URL.' );
		}
		$username = trim( (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_username', '' ) );
		$password = (string) Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_password', '' );

		if ( '' === $username || '' === $password ) {
			return new WP_Error( 'mobo_core_mobo_credentials_missing', 'Mobo username or password is missing.' );
		}

		$provider = $this->get_mobo_provider();
		if ( ! $provider instanceof Mobo_Core_Provider_Interface ) {
			return new WP_Error( 'mobo_core_provider_unavailable', 'No supported provider is bound to the active Connection Source.' );
		}
		$preflight_path = $this->get_provider_endpoint( 'auth_preflight' );
		$login_path = $this->get_provider_endpoint( 'auth_login' );
		$auth_cookie_name = sanitize_key( (string) $provider->get_auth_cookie_name() );
		if ( '' === $preflight_path || '' === $login_path || '' === $auth_cookie_name ) {
			return new WP_Error( 'mobo_core_provider_auth_unsupported', 'The active Source provider does not support storefront authentication.' );
		}

		$jar = $this->get_mobo_cookie_jar();
		if ( ! $force && ! empty( $jar[ $auth_cookie_name ] ) ) {
			$this->debug_log( 'login_reuse_cookie', array( 'provider' => $provider->get_key(), 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
			return true;
		}

		$jar = array();
		$this->debug_log( 'login_start', array( 'force' => $force, 'username' => $username, 'provider' => $provider->get_key() ) );

		$lease = $this->renew_active_mobo_cart_lock();
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}

		$route = $this->fresh_checkout_route_preflight();
		if ( is_wp_error( $route ) ) { return $route; }
		$dependency_check = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::before_request( 'provider_storefront' ) : true;
		if ( is_wp_error( $dependency_check ) ) { return $dependency_check; }
		$pre = $this->production_candidate_transport(
			'GET',
			$this->mobo_url( $preflight_path ),
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

		if ( is_wp_error( $pre ) && class_exists( 'Mobo_Core_Network_Resilience' ) ) {
			Mobo_Core_Network_Resilience::record_failure( 'provider_storefront', Mobo_Core_Network_Resilience::classify_transport_error( $pre ) );
		} elseif ( ! is_wp_error( $pre ) ) {
			$pre_code = absint( wp_remote_retrieve_response_code( $pre ) );
			if ( $pre_code >= 400 && class_exists( 'Mobo_Core_Network_Resilience' ) ) {
				Mobo_Core_Network_Resilience::record_failure( 'provider_storefront', Mobo_Core_Network_Resilience::classify_http_status( $pre_code, (string) wp_remote_retrieve_header( $pre, 'retry-after' ) ) );
			}
			$jar = $this->merge_cookie_jar_from_response( $jar, $pre );
		}

		$lease = $this->renew_active_mobo_cart_lock();
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}

		$route = $this->fresh_checkout_route_preflight();
		if ( is_wp_error( $route ) ) { return $route; }
		$dependency_check = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::before_request( 'provider_storefront' ) : true;
		if ( is_wp_error( $dependency_check ) ) { return $dependency_check; }
		$response = $this->production_candidate_transport(
			'POST',
			$this->mobo_url( $login_path ),
			array(
				'timeout'     => $this->get_mobo_timeout(),
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'checkout_validator' ),
				'headers'     => array(
					'Accept'       => 'application/json, text/plain, */*',
					'Content-Type' => 'application/json; charset=utf-8',
					'Origin'       => untrailingslashit( $this->get_mobo_site_url() ),
					'Referer'      => trailingslashit( $this->get_mobo_site_url() ) . ltrim( $preflight_path, '/' ),
					'User-Agent'   => 'MoboCore-CheckoutValidator/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '1.0' ),
					'Cookie'       => $this->cookie_header( $jar ),
				),
				'body'        => wp_json_encode(
					$provider->build_login_payload( $username, $password ),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'login_wp_error', array( 'errorCode' => sanitize_key( (string) $response->get_error_code() ) ) );
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) {
				$failure = Mobo_Core_Network_Resilience::classify_transport_error( $response );
				Mobo_Core_Network_Resilience::record_failure( 'provider_storefront', $failure );
				return Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_mobo_login_transport_error', 'Mobo storefront authentication transport failed.', $failure, array( 'requestMayHaveReachedServer' => true ) );
			}
			return new WP_Error( 'mobo_core_mobo_login_transport_error', 'Mobo storefront authentication transport failed.', array( 'transportCode' => sanitize_key( (string) $response->get_error_code() ), 'requestMayHaveReachedServer' => true ) );
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			$this->debug_log( 'login_http_error', array( 'httpStatus' => $code ) );
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) {
				$failure = Mobo_Core_Network_Resilience::classify_http_status( $code, (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
				Mobo_Core_Network_Resilience::record_failure( 'provider_storefront', $failure );
				return Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_mobo_login_http_error', 'Mobo storefront authentication was rejected.', $failure, array( 'requestMayHaveReachedServer' => true ) );
			}
			return new WP_Error( 'mobo_core_mobo_login_http_error', 'Mobo login failed with HTTP status ' . $code );
		}
		if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_success( 'provider_storefront' ); }

		$jar = $this->merge_cookie_jar_from_response( $jar, $response );

		if ( empty( $jar[ $auth_cookie_name ] ) ) {
			$this->debug_log( 'login_missing_userauth', array( 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
			return new WP_Error( 'mobo_core_mobo_login_missing_cookie', 'Mobo login response did not return userauth cookie.' );
		}

		if ( ! $this->set_mobo_cookie_jar( $jar ) ) {
			return new WP_Error( 'mobo_core_mobo_cookie_persist_failed', 'Mobo login succeeded but the shared session cookie could not be persisted locally.' );
		}
		$this->debug_log( 'login_success', array( 'httpStatus' => $code, 'cookieJar' => $this->mask_cookie_jar( $jar ) ) );
		Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_login_success_at', time(), false );

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

		$path = (string) $path;
		if ( '' === trim( $path ) ) {
			return new WP_Error( 'mobo_core_provider_operation_unsupported', 'The active Source provider does not expose the requested remote operation.' );
		}
		if ( '' === $this->get_mobo_site_url() ) {
			return new WP_Error( 'mobo_core_source_checkout_unavailable', 'The active Mobo source does not define a checkout storefront URL.' );
		}
		$lease = $this->renew_active_mobo_cart_lock();
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		/* The order lock is not a provisioning lock. Re-read the actual route
		 * before every outbound request: a request-local cached row could otherwise
		 * combine a cart prepared for Source A with a new Source B's wallet route. */
		$route = $this->fresh_checkout_route_preflight();
		if ( is_wp_error( $route ) ) { return $route; }

		$jar     = $this->get_mobo_cookie_jar();
		$cart_page_path = $this->get_provider_endpoint( 'cart_page' );
		$headers = array(
			'Accept'     => 'application/json, text/plain, */*',
			'Origin'     => untrailingslashit( $this->get_mobo_site_url() ),
			'Referer'    => trailingslashit( $this->get_mobo_site_url() ) . ltrim( '' !== $cart_page_path ? $cart_page_path : '/', '/' ),
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

		/* Keep the pre-send fence as close to the transport as possible. */
		$route = $this->fresh_checkout_route_preflight();
		if ( is_wp_error( $route ) ) { return $route; }
		$dependency_check = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::before_request( 'provider_storefront' ) : true;
		if ( is_wp_error( $dependency_check ) ) { return $dependency_check; }
		$response = $this->production_candidate_transport( $args['method'], $this->mobo_url( $path ), $args );

		if ( is_wp_error( $response ) ) {
			/* The WordPress transport message may contain URL/query/cookie/credentials.
			 * Preserve uncertainty for Wallet, but never persist or return raw text. */
			$transport_code = sanitize_key( (string) $response->get_error_code() );
			$this->debug_log( 'api_response_error', array( 'method' => strtoupper( (string) $method ), 'path' => $this->sanitize_mobo_log_path( $path ), 'errorCode' => $transport_code ) );
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) {
				$failure = Mobo_Core_Network_Resilience::classify_transport_error( $response );
				Mobo_Core_Network_Resilience::record_failure( 'provider_storefront', $failure );
				return Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_mobo_transport_error', 'Mobo storefront HTTP transport failed.', $failure, array( 'requestMayHaveReachedServer' => true ) );
			}
			return new WP_Error( 'mobo_core_mobo_transport_error', 'Mobo storefront HTTP transport failed.', array( 'transportCode' => $transport_code, 'requestMayHaveReachedServer' => true ) );
		}

		if ( ! is_wp_error( $response ) ) {
			$response_code = absint( wp_remote_retrieve_response_code( $response ) );
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) {
				if ( $response_code >= 400 ) {
					Mobo_Core_Network_Resilience::record_failure( 'provider_storefront', Mobo_Core_Network_Resilience::classify_http_status( $response_code, (string) wp_remote_retrieve_header( $response, 'retry-after' ) ) );
				} elseif ( $response_code >= 200 && $response_code < 400 ) {
					Mobo_Core_Network_Resilience::record_success( 'provider_storefront' );
				}
			}
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
		/* Checkout can record an order in the disposable shop when its optional
		 * external-validation feature is disabled. Enabled validation NEVER
		 * downgrades absent Source evidence to an allow decision. */
		if ( ! $this->is_external_validation_effective() ) { return array(); }
		return array( 'External Source validation is unavailable in staged NO-SEND mode.' );

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
		$portal_validation_fingerprint = '';
		$portal_validation_owner_id = 0;
		if ( $this->external_validation_uses_portal_origin( $url ) ) {
			$security_source = Mobo_Core_Settings::get( 'mobo_core_security_code', '' );
			$token_source = Mobo_Core_Settings::get( 'mobo_core_token', '' );
			if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
				$portal_validation_owner_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
				$portal_validation_fingerprint = $this->get_fresh_order_route_fingerprint( $portal_validation_owner_id );
				$fresh_owner = Mobo_Core_Connection_Repository::get_by_id_fresh( $portal_validation_owner_id );
				$fresh_portal_url = is_array( $fresh_owner )
					? Mobo_Core_Connection_Repository::resolve_api_base_url( (string) ( $fresh_owner['api_base_url'] ?? '' ) )
					: '';
				if ( '' === $portal_validation_fingerprint || ! is_array( $fresh_owner )
					|| ! $this->external_validation_uses_portal_origin( $url, $fresh_portal_url ) ) {
					return $this->external_error_result( 'مسیر اتصال سرویس اعتبارسنجی تغییر کرده است؛ درخواست ارسال نشد.' );
				}
				$security_source = (string) $fresh_owner['security_code'];
				$token_source = (string) $fresh_owner['license_token'];
			}
			$security_code = Mobo_Core_Settings::normalize_security_code( $security_source );
			if ( '' !== $security_code ) {
				if ( ! Mobo_Core_Settings::is_valid_security_code( $security_code ) ) {
					return $this->external_error_result( Mobo_Core_Settings::get_security_code_validation_error( $security_code ) );
				}

				$headers['X-SEC'] = $security_code;
			}

			$token = trim( (string) $token_source );
			if ( '' !== $token ) {
				$headers['Token'] = $token;
			}

			$signed_headers = Mobo_Core_Portal_Request_Signer::sign_headers( 'POST', $url, $body, $headers );
			if ( is_wp_error( $signed_headers ) ) {
				return $this->external_error_result( $signed_headers->get_error_message() );
			}
			$headers = $signed_headers;
		}

		if ( '' !== $portal_validation_fingerprint ) {
			$current_fingerprint = $this->get_fresh_order_route_fingerprint( $portal_validation_owner_id );
			if ( '' === $current_fingerprint || ! hash_equals( $portal_validation_fingerprint, $current_fingerprint ) ) {
				return $this->external_error_result( 'مسیر اتصال سرویس اعتبارسنجی پیش از ارسال تغییر کرد.' );
			}
		}
		$response = $this->production_candidate_transport(
			'POST',
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
	private function external_validation_uses_portal_origin( $url, $portal_override = '' ) {
		$portal = '' !== trim( (string) $portal_override ) ? (string) $portal_override : apply_filters( 'mobo_core_api_base_url', '' );
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
		Mobo_Core_Settings::update(
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
	 * Resolve the internal provider adapter from the active provisioned Connection.
	 *
	 * Provider choice is server-owned and is never editable by the customer.
	 *
	 * @return Mobo_Core_Provider_Interface|null
	 */
	private function get_mobo_provider() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Provider_Registry' ) ) {
			return null;
		}
		$connection = Mobo_Core_Connection_Repository::get_runtime_connection();
		if ( ! is_array( $connection ) || ! Mobo_Core_Connection_Repository::is_provisioned( $connection ) ) {
			$this->provider = null;
			$this->provider_source_key = '';
			return null;
		}
		$provider_key = Mobo_Core_Provider_Registry::normalize_key( $connection['provider_key'] ?? '' );
		if ( '' === $provider_key ) {
			$this->provider = null;
			$this->provider_source_key = '';
			return null;
		}
		if ( $this->provider instanceof Mobo_Core_Provider_Interface && hash_equals( $this->provider_source_key, $provider_key ) ) {
			return $this->provider;
		}
		$this->provider = Mobo_Core_Provider_Registry::get( $provider_key );
		$this->provider_source_key = $provider_key;
		return $this->provider instanceof Mobo_Core_Provider_Interface ? $this->provider : null;
	}

	/** Resolve one provider protocol endpoint. @return string */
	private function get_provider_endpoint( $operation, $args = array() ) {
		$provider = $this->get_mobo_provider();
		if ( ! $provider instanceof Mobo_Core_Provider_Interface ) { return ''; }
		return (string) $provider->get_endpoint( $operation, is_array( $args ) ? $args : array() );
	}

	/**
	 * Mobo checkout uses deterministic replace/rebuild cart synchronization.
	 * Incremental update/delete operations are intentionally not part of the
	 * provider contract.
	 *
	 * @return bool
	 */
	private function provider_uses_replace_cart_sync() {
		$provider = $this->get_mobo_provider();
		return $provider instanceof Mobo_Core_Provider_Interface
			&& 'replace' === sanitize_key( (string) $provider->get_cart_sync_strategy() );
	}

	/**
	 * Mobo site URL.
	 *
	 * @return string
	 */
	private function get_mobo_site_url() {
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return Mobo_Core_Connection_Repository::get_runtime_source_site_url( '' );
		}
		return '';
	}

	/**
	 * Build full Mobo URL.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function mobo_url( $path ) {
		$site_url = $this->get_mobo_site_url();
		return '' !== $site_url ? $site_url . '/' . ltrim( (string) $path, '/' ) : '';
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
		$jar = Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_cookie_jar', array() );

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
		Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cookie_jar', $jar, false );
		$stored = Mobo_Core_Settings::get( 'mobo_core_checkout_mobo_cookie_jar', array() );
		return is_array( $stored ) && $stored === $jar;
	}

	/**
	 * Clear the shared Mobo cookie jar.
	 *
	 * @return void
	 */
	private function clear_mobo_cookie_jar() {
		Mobo_Core_Settings::delete( 'mobo_core_checkout_mobo_cookie_jar' );
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
		$path = $this->get_provider_endpoint( 'cart_snapshot', array( 'update' => (bool) $update ) );
		if ( '' === $path ) { return new WP_Error( 'mobo_core_provider_cart_snapshot_unsupported', 'The active Source provider does not expose a cart snapshot.' ); }
		$response = $this->mobo_request( 'GET', $path, null );

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
		Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cart_snapshot_at', time(), false );

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
		Mobo_Core_Settings::update( 'mobo_core_checkout_mobo_cart_item_map_count', count( $map ), false );
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
	 * Legacy checkout debug option is a pre-Connection global artifact. Only the
	 * enabled Default Connection may read/migrate/delete it during rollback-safe
	 * fallback. Additional Connections fail closed when the event store is absent.
	 *
	 * @return bool
	 */
	private function legacy_mobo_debug_option_allowed() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return true;
		}
		$runtime = Mobo_Core_Connection_Repository::get_runtime_connection();
		return is_array( $runtime ) && ! empty( $runtime['is_default'] );
	}

	/**
	 * Return recent Mobo cart debug log entries for admin UI.
	 *
	 * @return array
	 */
	public function get_mobo_debug_log() {
		if ( class_exists( 'Mobo_Core_Event_Store' ) && Mobo_Core_Event_Store::is_available() ) {
			if ( $this->legacy_mobo_debug_option_allowed() && ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) { Mobo_Core_Event_Store::migrate_option_log( 'mobo_core_checkout_mobo_debug_log', 'checkout_debug', 500 ); }
			return Mobo_Core_Event_Store::latest( 'checkout_debug', 500 );
		}
		if ( ! $this->legacy_mobo_debug_option_allowed() || ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) { return array(); }
		$legacy = get_option( 'mobo_core_checkout_mobo_debug_log', array() );
		return is_array( $legacy ) ? array_slice( $legacy, 0, 500 ) : array();
	}

	/**
	 * Clear Mobo cart debug log.
	 *
	 * @return void
	 */
	public function clear_mobo_debug_log() {
		if ( class_exists( 'Mobo_Core_Event_Store' ) ) {
			Mobo_Core_Event_Store::clear( 'checkout_debug' );
		}
		if ( $this->legacy_mobo_debug_option_allowed() ) {
			delete_option( 'mobo_core_checkout_mobo_debug_log' );
		}
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

		if ( class_exists( 'Mobo_Core_Event_Store' ) && Mobo_Core_Event_Store::is_available() ) {
			Mobo_Core_Event_Store::append( 'checkout_debug', $entry, 500 );
			return;
		}
		if ( ! $this->legacy_mobo_debug_option_allowed() || ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) { return; }
		$legacy = get_option( 'mobo_core_checkout_mobo_debug_log', array() );
		$legacy = is_array( $legacy ) ? $legacy : array();
		array_unshift( $legacy, $entry );
		$legacy = array_slice( $legacy, 0, 500 );
		if ( class_exists( 'Mobo_Core_Durable_State_Policy' ) ) {
			Mobo_Core_Durable_State_Policy::update_option_verified( 'mobo_core_checkout_mobo_debug_log', $legacy, false );
		} else {
			update_option( 'mobo_core_checkout_mobo_debug_log', $legacy, false );
		}
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

		$order_object = $order instanceof WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null );
		$scope        = $this->get_order_mobo_item_scope( $order_object );

		if ( 'invalid' === sanitize_key( (string) ( isset( $scope['status'] ) ? $scope['status'] : '' ) ) ) {
			/* Unknown legacy ownership must never be guessed. Keep this order completely
			 * outside remote submission and leave an informational note only. */
			$this->add_unsubmittable_order_note( absint( $order_id ), $scope, 'status_processing_invalid_scope' );
			return;
		}

		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->add_non_mobo_order_note( absint( $order_id ), $scope, 'status_processing' );
			return;
		}

		/* Mixed Mobo/non-Mobo orders and all-Mobo orders spanning more than one
		 * Connection are intentionally fail-closed. Split-order behavior is not
		 * implemented and no partial remote purchase is allowed. */
		if ( ! $this->order_scope_is_submittable( $scope ) ) {
			if ( 'quarantined' === sanitize_key( (string) ( $scope['status'] ?? '' ) ) && $order_object instanceof WC_Order ) {
				/* M4: manual-review path (meta only; the order row is not re-saved here). */
				$order_object->update_meta_data( '_mobo_order_scope_status', 'quarantined' );
				$order_object->update_meta_data( '_mobo_order_submit_status', 'blocked_quarantined' );
				$order_object->update_meta_data( '_mobo_order_requires_review', 'yes' );
				$order_object->save_meta_data();
			}
			$this->add_unsubmittable_order_note( absint( $order_id ), $scope, 'status_processing' );
			return;
		}

		$connection_id = absint( isset( $scope['connectionId'] ) ? $scope['connectionId'] : 0 );
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$this->add_unsubmittable_order_note( absint( $order_id ), $scope, 'status_processing_missing_connection_context' );
			return;
		}

		/* Read the Source-owned enable flag and persist the queue under the order's
		 * owning Connection. Checkout/status hooks otherwise have no explicit Source
		 * context and would silently enqueue Connection B work into Default/A. */
		Mobo_Core_Connection_Context::run(
			$connection_id,
			function () use ( $order_id, $old_status, $new_status, $connection_id ) {
				if ( ! $this->is_order_submission_enabled() ) { return; }
				if ( $this->clone_guard_blocks_purchase() ) {
					/* S1: never queue a real Source purchase from a clone/staging copy. */
					$this->hold_order_for_clone_review( absint( $order_id ), 'status_processing' );
					return;
				}
				$this->queue_mobo_order_id_for_later(
					absint( $order_id ),
					array(
						'trigger'      => 'status_processing',
						'oldStatus'    => $old_status,
						'newStatus'    => $new_status,
						'connectionId' => $connection_id,
					)
				);
			}
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
		$order_id = absint( $order_id );
		$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->order_scope_is_submittable( $scope ) || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
			if ( $order_id > 0 && $this->order_scope_has_mobo_items( $scope ) ) { $this->add_unsubmittable_order_note( $order_id, $scope, 'scheduled_queue' ); }
			return;
		}
		$connection_id = absint( $scope['connectionId'] );
		Mobo_Core_Connection_Context::run(
			$connection_id,
			function () use ( $order_id, $context, $connection_id ) {
				if ( ! $this->is_order_submission_enabled() ) { return; }
				$context = is_array( $context ) ? $context : array();
				$context['connectionId'] = $connection_id;
				$next_attempt_at = isset( $context['nextAttemptAt'] ) ? absint( $context['nextAttemptAt'] ) : 0;
				unset( $context['nextAttemptAt'] );
				$this->queue_mobo_order_id_for_later( $order_id, $context, $next_attempt_at > time() ? $next_attempt_at : 0 );
			}
		);
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
	 * @param int   $next_attempt_at Optional epoch before which the row is not claimable (deferral backoff).
	 * @return bool
	 */
	private function queue_mobo_order_id_for_later( $order_id, $context = array(), $next_attempt_at = 0 ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return false;
		}
		$next_attempt_at = absint( $next_attempt_at );

		$token = str_replace( '-', '', wp_generate_uuid4() );
		$locked = $this->with_order_queue_state_lock(
			function () use ( $order_id, $context, $token, $next_attempt_at ) {
				$item = array(
					'orderId'    => $order_id,
					'queueToken' => $token,
					'queuedAt'   => time(),
					'context'    => $this->sanitize_order_log_value( is_array( $context ) ? $context : array() ),
				);
				if ( $next_attempt_at > 0 ) {
					/* Mirrored into the indexed available_at column by the row store. */
					$item['nextAttemptAt'] = $next_attempt_at;
				}
				if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
					return $this->primary_order_row_store_ready()
						&& Mobo_Core_Work_Queue_Store::put( self::ORDER_QUEUE_TYPE, (string) $order_id, $item );
				}
				$queue = $this->get_option_backed_order_queue();
				$queue[ (string) $order_id ] = $item;
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
			$persisted = $this->persist_order_queue_recovery_marker( $order_id, $context, $token, $next_attempt_at );
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				$scheduled_context = is_array( $context ) ? $context : array();
				if ( $next_attempt_at > 0 ) { $scheduled_context['nextAttemptAt'] = $next_attempt_at; }
				wp_schedule_single_event( time() + 10, 'mobo_core_queue_mobo_order_submission', array( $order_id, $scheduled_context ) );
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

	/** Legacy wp_options order-queue fallback is safe only for a single enabled Connection. @return bool */
	private function legacy_order_queue_fallback_allowed() {
		if ( class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() && Mobo_Core_Connection_Repository::count_enabled() > 1 ) { return false; }
		return true;
	}

	/**
	 * PERF06: keep terminal Default-only legacy import ahead of direct primary
	 * row operations. Incomplete cutover is never interpreted as an empty queue.
	 */
	private function primary_order_row_store_ready() {
		if ( ! class_exists( 'Mobo_Core_Work_Queue_Store' ) || ! Mobo_Core_Work_Queue_Store::is_available() ) { return false; }
		if ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() )
			&& ! Mobo_Core_Work_Queue_Store::migrate_option_map( 'mobo_core_mobo_order_submission_queue', self::ORDER_QUEUE_TYPE ) ) { return false; }
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
	private function persist_order_queue_recovery_marker( $order_id, $context, $token, $next_attempt_at = 0 ) {
		$order_id = absint( $order_id );
		$token    = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		if ( $order_id <= 0 || '' === $token ) {
			return false;
		}

		$key = $order_id . '_' . substr( strtolower( $token ), 0, 24 );
		$value = array(
			'orderId'    => $order_id,
			'queueToken' => $token,
			'queuedAt'   => time(),
			'context'    => $this->sanitize_order_log_value( is_array( $context ) ? $context : array() ),
		);
		if ( absint( $next_attempt_at ) > 0 ) {
			$value['nextAttemptAt'] = absint( $next_attempt_at );
		}

		if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
			return Mobo_Core_Work_Queue_Store::put( self::ORDER_RECOVERY_QUEUE_TYPE, $key, $value );
		}
		if ( ! $this->legacy_order_queue_fallback_allowed() ) { return false; }
		$option = self::ORDER_RECOVERY_OPTION_PREFIX . $key;
		return class_exists( 'Mobo_Core_Durable_State_Policy' )
			? Mobo_Core_Durable_State_Policy::update_option_verified( $option, $value, false )
			: update_option( $option, $value, false );
	}

	/**
	 * Read a bounded set of independent order recovery markers.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_order_queue_recovery_items( $limit ) {
		$limit = max( 1, min( 10, absint( $limit ) ) );
		$this->order_recovery_read_failed = false;
		if ( ! class_exists( 'Mobo_Core_Work_Queue_Store' ) || ! Mobo_Core_Work_Queue_Store::is_available() ) {
			if ( ! $this->legacy_order_queue_fallback_allowed() ) { return array(); }
			global $wpdb;
			$like  = $wpdb->esc_like( self::ORDER_RECOVERY_OPTION_PREFIX ) . '%';
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $like, $limit ) );
			$out   = array();
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				$item = get_option( $name, array() );
				$order_id = is_array( $item ) && isset( $item['orderId'] ) ? absint( $item['orderId'] ) : 0;
				if ( $order_id <= 0 ) { delete_option( $name ); continue; }
				if ( isset( $item['nextAttemptAt'] ) && absint( $item['nextAttemptAt'] ) > time() ) { continue; } // Deferral backoff.
				$out[] = array(
					'queueKey' => null, 'queueToken' => '',
					'recoveryKey' => substr( (string) $name, strlen( self::ORDER_RECOVERY_OPTION_PREFIX ) ),
					'orderId' => $order_id,
					'context' => isset( $item['context'] ) && is_array( $item['context'] ) ? $item['context'] : array(),
				);
			}
			return $out;
		}
		/* Migrate any pre-10.33.92 independent recovery options before reading rows. */
		if ( class_exists( 'Mobo_Core_State_Store' ) && ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) ) {
			Mobo_Core_State_Store::migrate_option_prefix( self::ORDER_RECOVERY_OPTION_PREFIX, 10000 );
			foreach ( Mobo_Core_State_Store::list_prefix( self::ORDER_RECOVERY_OPTION_PREFIX, 10000 ) as $legacy_key => $legacy_item ) {
				if ( ! is_array( $legacy_item ) || empty( $legacy_item['orderId'] ) ) {
					Mobo_Core_State_Store::delete( $legacy_key );
					continue;
				}
				$key = substr( $legacy_key, strlen( self::ORDER_RECOVERY_OPTION_PREFIX ) );
				if ( Mobo_Core_Work_Queue_Store::put( self::ORDER_RECOVERY_QUEUE_TYPE, $key, $legacy_item ) ) {
					Mobo_Core_State_Store::delete( $legacy_key );
				}
			}
		}
		/* PERF04: only the requested page is loaded/decoded. Do not process a partial
		 * page when any row on it has failed the digest/payload check. Only due rows
		 * (available_at reached) are read so a backed-off marker cannot block later ones. */
		$page = Mobo_Core_Work_Queue_Store::first_due_rows_checked( self::ORDER_RECOVERY_QUEUE_TYPE, $limit );
		$this->order_recovery_read_failed = 'ok' !== $page['status'];
		if ( $this->order_recovery_read_failed ) {
			return array();
		}
		$out  = array();
		foreach ( $page['rows'] as $key => $row ) {
			$item = $row['payload'];
			$order_id = is_array( $item ) && isset( $item['orderId'] ) ? absint( $item['orderId'] ) : 0;
			if ( $order_id <= 0 ) {
				Mobo_Core_Work_Queue_Store::delete_key_if_hash( self::ORDER_RECOVERY_QUEUE_TYPE, $key, $row['payload_hash'] );
				continue;
			}
			$out[] = array(
				'queueKey'       => null,
				'queueToken'     => '',
				'recoveryKey'    => (string) $key,
				'recoveryHash'   => (string) $row['payload_hash'],
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
			$this->remove_order_queue_item_if_token_matches( $queue_item['queueKey'], isset( $queue_item['queueToken'] ) ? $queue_item['queueToken'] : '', isset( $queue_item['queueHash'] ) ? $queue_item['queueHash'] : '' );
		}
		if ( ! empty( $queue_item['recoveryKey'] ) ) {
			$recovery_key = (string) $queue_item['recoveryKey'];
			if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
				/* Never erase an emergency marker that was re-enqueued after this
				 * snapshot was read. Its exact payload hash is the cleanup preimage. */
				if ( ! empty( $queue_item['recoveryHash'] ) ) {
					Mobo_Core_Work_Queue_Store::delete_key_if_hash( self::ORDER_RECOVERY_QUEUE_TYPE, $recovery_key, $queue_item['recoveryHash'] );
				}
			} elseif ( $this->legacy_order_queue_fallback_allowed() ) {
				Mobo_Core_Settings::delete( self::ORDER_RECOVERY_OPTION_PREFIX . $recovery_key );
			}
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
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 ) { return false; }
		$db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$lock_name = 'mobo_order_q_' . substr( hash( 'sha256', $db_name . '|' . (string) $wpdb->prefix . '|' . $connection_id ), 0, 40 );
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
	private function remove_order_queue_item_if_token_matches( $key, $expected_token, $snapshot_hash = '' ) {
		$key            = (string) $key;
		$expected_token = sanitize_text_field( (string) $expected_token );
		if ( '' === $key || '' === $expected_token ) { return false; }

		$result = $this->with_order_queue_state_lock(
			function () use ( $key, $expected_token, $snapshot_hash ) {
				if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
					if ( ! $this->primary_order_row_store_ready() ) { return false; }
					$read = Mobo_Core_Work_Queue_Store::read_one_checked( self::ORDER_QUEUE_TYPE, $key );
					if ( 'missing' === $read['status'] ) { return true; }
					if ( 'ok' !== $read['status'] ) { return false; }
					$current_token = $this->order_queue_item_token( $read['row']['payload'], $key );
					if ( ! hash_equals( $expected_token, $current_token ) ) { return false; }
					$hash = (string) $read['row']['payload_hash'];
					if ( '' !== $snapshot_hash && ! hash_equals( (string) $snapshot_hash, $hash ) ) { return false; }
					return Mobo_Core_Work_Queue_Store::delete_key_if_hash( self::ORDER_QUEUE_TYPE, $key, $hash );
				}
				$queue = $this->get_option_backed_order_queue();
				if ( ! array_key_exists( $key, $queue ) ) { return true; }
				$current_token = $this->order_queue_item_token( $queue[ $key ], $key );
				if ( ! hash_equals( $expected_token, $current_token ) ) { return false; }
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
		if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
			if ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) { Mobo_Core_Work_Queue_Store::migrate_option_map( 'mobo_core_mobo_order_submission_queue', self::ORDER_QUEUE_TYPE ); }
			return Mobo_Core_Work_Queue_Store::load_map( self::ORDER_QUEUE_TYPE );
		}
		if ( ! $this->legacy_order_queue_fallback_allowed() ) { return array(); }
		$legacy = get_option( 'mobo_core_mobo_order_submission_queue', array() );
		return is_array( $legacy ) ? $legacy : array();
	}

	/**
	 * Persist the option-backed queue.
	 *
	 * @param array $queue Queue.
	 * @return bool
	 */
	private function save_option_backed_order_queue( $queue ) {
		$queue = is_array( $queue ) ? $queue : array();
		if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
			return Mobo_Core_Work_Queue_Store::replace_map( self::ORDER_QUEUE_TYPE, $queue );
		}
		if ( ! $this->legacy_order_queue_fallback_allowed() ) { return false; }
		return class_exists( 'Mobo_Core_Durable_State_Policy' )
			? Mobo_Core_Durable_State_Policy::update_option_verified( 'mobo_core_mobo_order_submission_queue', $queue, false )
			: update_option( 'mobo_core_mobo_order_submission_queue', $queue, false );
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

		if ( $this->clone_guard_blocks_purchase() ) {
			$this->hold_order_for_clone_review( $order, 'queue_request' );
			return new WP_Error( 'mobo_core_clone_guard_blocked', Mobo_Core_Clone_Guard::blocked_message() );
		}

		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'queue_request' );
			return new WP_Error( 'mobo_core_order_not_mobo', 'این سفارش مربوط به موبو نیست.' );
		}
		if ( ! $this->order_scope_is_submittable( $scope ) ) {
			$this->mark_order_scope_unsubmittable( $order, $scope, 'queue_request' );
			return new WP_Error( 'mobo_core_order_scope_not_submittable', 'این سفارش طبق سیاست فعلی قابل ارسال خودکار به موبو نیست.' );
		}
		$connection_block_reason = $this->get_order_submission_connection_block_reason( $scope );
		if ( '' !== $connection_block_reason ) {
			$this->append_order_log( $order, 'order_submission_money_or_connection_blocked', array(
				'reason' => $connection_block_reason,
				'connectionId' => absint( $scope['connectionId'] ?? 0 ),
			) );
			return new WP_Error( 'mobo_core_order_connection_blocked', $connection_block_reason );
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

		$row_mode = class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available();
		$primary_page_full = false;
		$primary_hashes = array();
		if ( $row_mode ) {
			if ( ! $this->primary_order_row_store_ready() ) {
				$result['status'] = 'queue-read-failed';
				$result['remaining'] = true;
				return $result;
			}
			/* PERF06: inspect a bounded page. A full page may conceal an
			 * additional primary row: never promote a recovery marker or legacy
			 * Woo lookup ahead of an unseen owner-scoped primary queue record.
			 * Only DUE rows are read (available_at NULL/reached, ordered by
			 * available_at, id): a deferred order with backoff can no longer keep
			 * its old id at the head and block every later paid order. */
			$page_size = max( 16, $limit + 1 );
			$page = Mobo_Core_Work_Queue_Store::first_due_rows_checked( self::ORDER_QUEUE_TYPE, $page_size );
			if ( 'ok' !== $page['status'] ) {
				$result['status'] = 'queue-read-failed';
				$result['remaining'] = true;
				return $result;
			}
			$primary_page_full = count( $page['rows'] ) >= $page_size;
			$queue = array();
			foreach ( $page['rows'] as $key => $row ) {
				$queue[ $key ] = $row['payload'];
				$primary_hashes[ $key ] = $row['payload_hash'];
			}
		} else {
			$queue = $this->get_option_backed_order_queue();
		}
		$queue_items = array();
		foreach ( $queue as $key => $item ) {
			$order_id = is_array( $item ) && isset( $item['orderId'] ) ? absint( $item['orderId'] ) : absint( $key );
			if ( $order_id <= 0 ) {
				$removed = $this->remove_order_queue_item_if_token_matches( $key, $this->order_queue_item_token( $item, $key ), isset( $primary_hashes[ $key ] ) ? $primary_hashes[ $key ] : '' );
				if ( ! $removed ) { $result['remaining'] = true; }
				continue;
			}
			if ( ! $row_mode && is_array( $item ) && isset( $item['nextAttemptAt'] ) && absint( $item['nextAttemptAt'] ) > time() ) {
				continue; // Option-backed fallback: honor the same deferral backoff as available_at.
			}
			$queue_items[] = array(
				'queueKey'       => $key,
				'queueToken'     => $this->order_queue_item_token( $item, $key ),
				'queueHash'      => isset( $primary_hashes[ $key ] ) ? $primary_hashes[ $key ] : '',
				'recoveryOption' => '',
				'orderId'        => $order_id,
				'context'        => is_array( $item ) && isset( $item['context'] ) && is_array( $item['context'] ) ? $item['context'] : array(),
			);
		}
		if ( $primary_page_full ) { $result['remaining'] = true; }

		$seen_order_ids = array();
		foreach ( $queue_items as $item ) {
			$seen_id = absint( isset( $item['orderId'] ) ? $item['orderId'] : 0 );
			if ( $seen_id > 0 ) {
				$seen_order_ids[ $seen_id ] = true;
			}
		}
		$recovery_items = ( ! $primary_page_full && count( $queue_items ) < $limit )
			? $this->get_order_queue_recovery_items( $limit + 1 )
			: array();
		if ( $this->order_recovery_read_failed ) {
			$result['status'] = 'queue-read-failed';
			$result['remaining'] = true;
			return $result;
		}
		foreach ( $recovery_items as $recovery_item ) {
			$recovery_order_id = absint( isset( $recovery_item['orderId'] ) ? $recovery_item['orderId'] : 0 );
			if ( $recovery_order_id > 0 && isset( $seen_order_ids[ $recovery_order_id ] ) ) {
				/* The shared queue now contains this order; its emergency marker is redundant. */
				$this->cleanup_order_queue_item( $recovery_item );
				continue;
			}
			if ( $row_mode && $recovery_order_id > 0 ) {
				/* The primary page now holds only due rows, so a backed-off primary row
				 * is not in $seen_order_ids. It is still authoritative (it carries the
				 * deferral backoff); the emergency marker must not bypass it. */
				$primary_read = Mobo_Core_Work_Queue_Store::read_one_checked( self::ORDER_QUEUE_TYPE, (string) $recovery_order_id );
				if ( 'ok' === $primary_read['status'] ) {
					$this->cleanup_order_queue_item( $recovery_item );
					continue;
				}
				if ( 'missing' !== $primary_read['status'] ) {
					$result['remaining'] = true;
					continue;
				}
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

		if ( empty( $queue_items ) && ! $primary_page_full && function_exists( 'wc_get_orders' ) ) {
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
					/* A deferred order keeps meta status "queued". Never let this legacy
					 * meta lookup bypass its backoff or its authoritative primary row. */
					$legacy_order = wc_get_order( absint( $legacy_order_id ) );
					if ( $legacy_order instanceof WC_Order && absint( $legacy_order->get_meta( '_mobo_order_submit_next_attempt_at', true ) ) > time() ) {
						continue;
					}
					if ( $row_mode ) {
						$legacy_primary = Mobo_Core_Work_Queue_Store::read_one_checked( self::ORDER_QUEUE_TYPE, (string) absint( $legacy_order_id ) );
						if ( 'missing' !== $legacy_primary['status'] ) {
							continue;
						}
					}
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
			$result['status'] = $result['remaining'] ? 'waiting' : 'empty';
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

			/* S1: on a clone/staging copy (or an un-reprovisioned domain move) no queued,
			 * running or uncertain order copied from the original may reach the Source,
			 * not even for reconciliation. Hold it for manual review and retire the
			 * queue marker so a later re-provisioning cannot silently replay it. */
			if ( $this->clone_guard_blocks_purchase() ) {
				$this->hold_order_for_clone_review( $order, 'queue_processor' );
				$result['skipped']++;
				$result['status'] = 'clone_guard_blocked';
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}

			if ( 'processing' !== sanitize_key( (string) $order->get_status() ) ) {
				$nonprocessing_attempt_state = $this->get_order_submission_attempt_state( $order );
				if ( 'running_recent' === $nonprocessing_attempt_state ) {
					/* The remote payment may be live: do not overwrite running with
					 * aborted from another worker's stale order snapshot. */
					$result['skipped']++;
					$result['remaining'] = true;
					continue;
				}
				if ( 'running_stale' === $nonprocessing_attempt_state ) {
					$quarantined = $this->quarantine_stale_queued_order_attempt( $order_id );
					if ( 'uncertain' === $quarantined ) {
						$fresh_uncertain = wc_get_order( $order_id );
						$reconciled = $fresh_uncertain instanceof WC_Order ? $this->reconcile_uncertain_paid_order_with_cart_lock( $fresh_uncertain ) : false;
						if ( true === $reconciled ) { $result['success']++; } else { $result['skipped']++; }
						$this->cleanup_order_queue_item( $queue_item );
					} elseif ( 'already_sent' === $quarantined ) {
						$result['skipped']++;
						$this->cleanup_order_queue_item( $queue_item );
					} else {
						$result['skipped']++;
						$result['remaining'] = true;
					}
					continue;
				}
				if ( 'uncertain' === $nonprocessing_attempt_state ) {
					$reconciled = $this->reconcile_uncertain_paid_order_with_cart_lock( $order );
					if ( true === $reconciled ) { $result['success']++; } else { $result['skipped']++; }
					$this->cleanup_order_queue_item( $queue_item );
					continue;
				}
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

			/* A historical failed/aborted purchase must never be revived just
			 * because an old recovery row survived a crash or a queue rewrite.
			 * Only a separately authorized administrator action may retry it.
			 * A price-review order is terminal for automation in the same way. */
			$durable_submit_status = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
			if ( in_array( $durable_submit_status, array( 'failed', 'aborted', 'price_review', 'blocked_clone' ), true ) ) {
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
			if ( 'running_stale' === $attempt_state ) {
				$quarantined = $this->quarantine_stale_queued_order_attempt( $order_id );
				if ( 'uncertain' === $quarantined ) {
					$fresh_uncertain = wc_get_order( $order_id );
					$reconciled = $fresh_uncertain instanceof WC_Order ? $this->reconcile_uncertain_paid_order_with_cart_lock( $fresh_uncertain ) : false;
					if ( true === $reconciled ) { $result['success']++; } else { $result['skipped']++; }
					$this->cleanup_order_queue_item( $queue_item );
				} elseif ( 'already_sent' === $quarantined ) {
					$result['skipped']++;
					$this->cleanup_order_queue_item( $queue_item );
				} else {
					$result['skipped']++;
					$result['remaining'] = true;
				}
				continue;
			}
			if ( 'uncertain' === $attempt_state ) {
				$reconciled = $this->reconcile_uncertain_paid_order_with_cart_lock( $order );
				if ( true === $reconciled ) { $result['success']++; } else { $result['skipped']++; }
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
			if ( ! $this->order_scope_is_submittable( $scope ) ) {
				$result['skipped']++;
				$this->mark_order_scope_unsubmittable( $order, $scope, 'queue_processor' );
				$this->cleanup_order_queue_item( $queue_item );
				continue;
			}
			$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			if ( $current_connection_id <= 0 || $current_connection_id !== absint( $scope['connectionId'] ) ) {
				/* Never mutate another Connection's Woo order state from this worker. A
				 * correctly scoped work-queue row cannot reach this branch; legacy/global
				 * evidence is left intact for the owning Connection to consume. */
				$result['skipped']++;
				$result['remaining'] = true;
				continue;
			}

			$context = is_array( $queue_item['context'] ) ? $queue_item['context'] : array();
			$context['connectionId'] = $current_connection_id;
			$context['processor'] = $source;

			$result['processed']++;
			/* Correlate every purchase step (eligibility, checkout, wallet, details) of
			 * this order; record the outcome for the remote diagnostics bundle. */
			$order_scope   = class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::push( 'order', $order_id ) : 0;
			$order_started = microtime( true );
			try {
				$submit = $this->submit_order_to_mobo( $order, $context );
				if ( class_exists( 'Mobo_Core_Error_Log' ) ) {
					Mobo_Core_Error_Log::record_event( 'order', (string) $order_id, true === $submit ? 'submitted' : ( is_wp_error( $submit ) ? (string) $submit->get_error_code() : 'not_submitted' ), (int) round( ( microtime( true ) - $order_started ) * 1000 ) );
					if ( is_wp_error( $submit ) && ! in_array( $submit->get_error_code(), array( 'mobo_core_order_submission_busy', 'mobo_core_order_submission_deferred', 'mobo_core_order_submission_superseded', 'mobo_core_order_not_processing' ), true ) ) {
						Mobo_Core_Error_Log::capture_wp_error( 'order', $submit, array( 'orderId' => $order_id ) );
					}
				}
			} finally {
				if ( $order_scope > 0 ) {
					Mobo_Core_Correlation::pop( $order_scope );
				}
			}

			if ( is_wp_error( $submit ) && in_array( $submit->get_error_code(), array( 'mobo_core_order_submission_busy', 'mobo_core_order_submission_lock_unavailable', 'mobo_core_order_submission_state_persist_failed', 'mobo_core_order_submission_deferred' ), true ) ) {
				$result['skipped']++;
				$result['remaining'] = true;
				continue;
			}
			if ( is_wp_error( $submit ) && 'mobo_core_order_submission_uncertain' === $submit->get_error_code() ) {
				/* Keep the exact queue row for one later positive-only reconciliation pass.
				 * The next pass never replays Checkout/Wallet: the uncertain-state branch
				 * above only performs GET Details reconciliation, then retires the row. */
				$result['skipped']++;
				$result['remaining'] = true;
				continue;
			}

			$this->cleanup_order_queue_item( $queue_item );

			/* Price review: queue row retired above, so later paid orders proceed. */
			if ( is_wp_error( $submit ) && in_array( $submit->get_error_code(), array( 'mobo_core_order_submission_superseded', 'mobo_core_order_submission_uncertain', 'mobo_core_order_submission_aborted', 'mobo_core_order_not_processing', 'mobo_core_order_submission_price_review' ), true ) ) {
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

		/* PERF06: end-of-batch continuation must not re-materialize the full
		 * primary queue. A count failure is an error, not an empty queue. */
		/* Only DUE rows ask for an immediate continuation; backed-off rows are
		 * picked up by a later runner pass once available_at is reached. */
		if ( $row_mode ) {
			$primary_counts = Mobo_Core_Work_Queue_Store::pending_due_counts_strict( self::ORDER_QUEUE_TYPE );
			$primary_count  = is_array( $primary_counts ) ? absint( $primary_counts['due'] ) : -1;
		} else {
			$primary_count = 0;
			foreach ( $this->get_option_backed_order_queue() as $option_item ) {
				if ( ! is_array( $option_item ) || ! isset( $option_item['nextAttemptAt'] ) || absint( $option_item['nextAttemptAt'] ) <= time() ) {
					$primary_count++;
				}
			}
		}
		if ( $primary_count < 0 ) {
			$result['status'] = 'queue-read-failed';
			$result['remaining'] = true;
			return $result;
		}
		if ( $primary_count > 0 || ! empty( $this->get_order_queue_recovery_items( 1 ) ) ) {
			$result['remaining'] = true;
		}
		if ( $this->order_recovery_read_failed ) {
			$result['status'] = 'queue-read-failed';
			$result['remaining'] = true;
		}
		return $result;
	}

	private function is_order_submission_enabled() {
		return Mobo_Core_Order_Submission_Policy::is_enabled();
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
			/* A partial/older recovery record is not evidence of a fresh attempt.
			 * Preserve confirmed success, but never lose remote-attempt evidence
			 * merely because the summary status/attempted flag is missing. */
			if ( $this->order_was_already_sent_to_mobo( $order ) ) {
				return 'none';
			}
			if ( 'running' === $status
				|| absint( $order->get_meta( '_mobo_order_pending_remote_id', true ) ) > 0
				|| absint( $order->get_meta( '_mobo_order_pending_shipping_id', true ) ) > 0 ) {
				return 'uncertain';
			}
			foreach ( array( '_mobo_order_pending_stage', '_mobo_order_pending_attempt_key', '_mobo_order_pending_token', '_mobo_order_pending_token_sha256' ) as $key ) {
				$checkpoint = $order->get_meta( $key, true );
				if ( is_string( $checkpoint ) ? '' !== trim( $checkpoint ) : ! empty( $checkpoint ) ) {
					return 'uncertain';
				}
			}
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


	/** Generate a durable logical identity for one remote purchase attempt. */
	private function generate_order_submission_attempt_key( $order_id ) {
		$order_id = absint( $order_id );
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mobo-', true );
		return hash( 'sha256', $order_id . '|' . (string) $uuid . '|' . microtime( true ) );
	}

	/**
	 * Persist positive remote-order evidence before any later stage can fail.
	 * The opaque cart token is private Woo order metadata; logs only expose hashes.
	 */
	private function persist_order_remote_checkpoint( $order, $stage, $token = '', $remote_order_id = 0, $shipping_id = 0, $business_fingerprint = '' ) {
		if ( ! $order instanceof WC_Order ) { return false; }
		$stage = sanitize_key( (string) $stage );
		$token = $this->parse_mobo_cart_token( $token );
		$remote_order_id = absint( $remote_order_id );
		$shipping_id = absint( $shipping_id );
		$attempt_key = sanitize_text_field( (string) $order->get_meta( '_mobo_order_submit_attempt_key', true ) );
		if ( '' === $stage || '' === $attempt_key ) { return false; }

		$order->update_meta_data( '_mobo_order_pending_stage', $stage );
		$order->update_meta_data( '_mobo_order_pending_attempt_key', $attempt_key );
		$order->update_meta_data( '_mobo_order_pending_updated_at', time() );
		if ( '' !== $token ) {
			$order->update_meta_data( '_mobo_order_pending_token', $token );
			$order->update_meta_data( '_mobo_order_pending_token_sha256', hash( 'sha256', $token ) );
		}
		if ( $remote_order_id > 0 ) { $order->update_meta_data( '_mobo_order_pending_remote_id', $remote_order_id ); }
		if ( $shipping_id > 0 ) { $order->update_meta_data( '_mobo_order_pending_shipping_id', $shipping_id ); }
		if ( '' !== (string) $business_fingerprint ) { $order->update_meta_data( '_mobo_order_pending_business_fingerprint', sanitize_text_field( (string) $business_fingerprint ) ); }
		$saved_id = absint( $order->save() );
		if ( $saved_id <= 0 ) { return false; }
		$fresh = wc_get_order( $saved_id );
		if ( ! $fresh instanceof WC_Order ) { return false; }
		if ( $stage !== sanitize_key( (string) $fresh->get_meta( '_mobo_order_pending_stage', true ) ) ) { return false; }
		if ( ! hash_equals( $attempt_key, sanitize_text_field( (string) $fresh->get_meta( '_mobo_order_pending_attempt_key', true ) ) ) ) { return false; }
		if ( '' !== $token && ! hash_equals( hash( 'sha256', $token ), sanitize_text_field( (string) $fresh->get_meta( '_mobo_order_pending_token_sha256', true ) ) ) ) { return false; }
		if ( $remote_order_id > 0 && $remote_order_id !== absint( $fresh->get_meta( '_mobo_order_pending_remote_id', true ) ) ) { return false; }
		if ( $shipping_id > 0 && $shipping_id !== absint( $fresh->get_meta( '_mobo_order_pending_shipping_id', true ) ) ) { return false; }
		return true;
	}

	private function clear_order_remote_checkpoint( $order, $clear_attempt_key = false ) {
		if ( ! $order instanceof WC_Order ) { return false; }
		foreach ( array( '_mobo_order_pending_stage', '_mobo_order_pending_attempt_key', '_mobo_order_pending_updated_at', '_mobo_order_pending_token', '_mobo_order_pending_token_sha256', '_mobo_order_pending_remote_id', '_mobo_order_pending_shipping_id', '_mobo_order_pending_business_fingerprint' ) as $key ) {
			$order->delete_meta_data( $key );
		}
		if ( $clear_attempt_key ) { $order->delete_meta_data( '_mobo_order_submit_attempt_key' ); }
		$order_id = absint( $order->save() );
		if ( $order_id <= 0 ) { return false; }
		$fresh = wc_get_order( $order_id );
		if ( ! $fresh instanceof WC_Order ) { return false; }
		return '' === (string) $fresh->get_meta( '_mobo_order_pending_stage', true )
			&& '' === (string) $fresh->get_meta( '_mobo_order_pending_token', true )
			&& 0 === absint( $fresh->get_meta( '_mobo_order_pending_remote_id', true ) );
	}

	private function order_request_error_is_ambiguous( $error ) {
		if ( ! is_wp_error( $error ) ) { return false; }
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['requestMayHaveReachedServer'] ) ) { return true; }
		if ( 'mobo_core_http_error' === (string) $error->get_error_code() ) {
			$status = is_array( $data ) && isset( $data['httpStatus'] ) ? absint( $data['httpStatus'] ) : 0;
			return 0 === $status || 408 === $status || 409 === $status || 425 === $status || 429 === $status || $status >= 500;
		}
		return 'mobo_core_mobo_transport_error' === (string) $error->get_error_code();
	}

	/** Positive-only reconciliation. Missing/negative evidence never proves safe replay. */
	private function reconcile_remote_order_details( $order, $token, $expected_order_id = 0 ) {
		$token = $this->parse_mobo_cart_token( $token );
		if ( ! $order instanceof WC_Order || '' === $token ) {
			return new WP_Error( 'mobo_core_order_reconciliation_missing_token', 'Remote order reconciliation has no durable cart token.' );
		}
		$response = $this->order_mobo_request( $order, 'GET', $this->get_provider_endpoint( 'cart_details', array( 'token' => $token ) ), null, 'order_reconcile_details' );
		if ( is_wp_error( $response ) ) { return $response; }
		$json = $this->decode_mobo_response_json( $response );
		if ( is_wp_error( $json ) ) { return $json; }
		$provider = $this->get_mobo_provider();
		if ( ! $provider instanceof Mobo_Core_Provider_Interface || ! method_exists( $provider, 'interpret_order_reconciliation_details' ) ) {
			return new WP_Error( 'mobo_core_order_reconciliation_unsupported', 'The active provider cannot interpret remote order reconciliation evidence.' );
		}
		$state = $provider->interpret_order_reconciliation_details( $json, absint( $expected_order_id ) );
		if ( ! is_array( $state ) || empty( $state['remoteOrderPresent'] ) || empty( $state['idMatches'] ) || absint( $state['remoteOrderId'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'mobo_core_order_reconciliation_inconclusive', 'Remote order details did not positively prove the expected order.' );
		}
		return $state;
	}

	/**
	 * Heal an uncertain local state only when Provider details positively prove that
	 * the exact known remote order is paid. It never treats paid=false/missing as
	 * proof that another Wallet POST is safe.
	 */
	private function reconcile_uncertain_paid_order( $order ) {
		if ( ! $order instanceof WC_Order ) { return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' ); }
		$token = $this->parse_mobo_cart_token( $order->get_meta( '_mobo_order_pending_token', true ) );
		$remote_id = absint( $order->get_meta( '_mobo_order_pending_remote_id', true ) );
		$shipping_id = absint( $order->get_meta( '_mobo_order_pending_shipping_id', true ) );
		if ( '' === $token || $remote_id <= 0 ) {
			return new WP_Error( 'mobo_core_order_reconciliation_missing_identity', 'No durable remote order identity is available for automatic reconciliation.' );
		}
		$state = $this->reconcile_remote_order_details( $order, $token, $remote_id );
		if ( is_wp_error( $state ) ) { return $state; }
		if ( empty( $state['paidKnown'] ) || empty( $state['paid'] ) ) {
			return new WP_Error( 'mobo_core_order_reconciliation_payment_inconclusive', 'The remote order exists, but paid=true was not positively proven. Automatic replay remains blocked.', array( 'remoteOrderId' => $remote_id ) );
		}
		$payment_json = array( 'success' => true, 'paid' => true, 'id' => $remote_id, 'reconciled' => true );
		if ( ! $this->mark_mobo_order_submission_success( $order, $remote_id, $token, $shipping_id, $payment_json ) ) {
			return new WP_Error( 'mobo_core_order_reconciliation_local_commit_failed', 'Remote paid order was proven but local success could not be committed durably.' );
		}
		$this->clear_order_payment_guard( absint( $order->get_id() ) );
		$fresh = wc_get_order( absint( $order->get_id() ) );
		if ( $fresh instanceof WC_Order ) {
			$fresh->update_meta_data( '_mobo_order_reconciled_at', time() );
			$fresh->update_meta_data( '_mobo_order_reconciled', 'yes' );
			$fresh->save();
			$this->append_order_log( $fresh, 'order_submission_reconciled_paid', array( 'moboOrderId' => $remote_id ) );
		}
		return true;
	}

	/** Run positive-only reconciliation while owning the shared Provider session. */
	private function reconcile_uncertain_paid_order_with_cart_lock( $order ) {
		if ( ! $order instanceof WC_Order || ! class_exists( 'Mobo_Core_Lock' ) ) { return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order or lock service unavailable.' ); }
		$order_id = absint( $order->get_id() );
		$order_lock_name = 'order_submission_' . $order_id;
		$order_lock_ttl = max( 120, absint( apply_filters( 'mobo_core_order_reconciliation_lock_ttl', 300, $order_id ) ) );
		$order_lock_token = Mobo_Core_Lock::acquire( $order_lock_name, $order_lock_ttl );
		if ( false === $order_lock_token ) { return new WP_Error( 'mobo_core_order_submission_busy', 'Another request owns this order submission/reconciliation.' ); }
		$this->active_order_submission_lock_name = $order_lock_name;
		$this->active_order_submission_lock_token = sanitize_text_field( (string) $order_lock_token );
		$this->active_order_submission_lock_ttl = $order_lock_ttl;
		$lock = $this->acquire_mobo_cart_lock( 'order_reconcile_' . $order_id );
		if ( is_wp_error( $lock ) ) {
			Mobo_Core_Lock::release( $order_lock_name, $order_lock_token );
			$this->active_order_submission_lock_name = '';
			$this->active_order_submission_lock_token = '';
			return $lock;
		}
		$result = null;
		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof WC_Order || $this->order_was_already_sent_to_mobo( $fresh ) ) {
				/* Already-sent is a terminal no-op, not a newly reconciled transition.
				 * Returning a distinct value prevents post-success listeners from being
				 * fired a second time by a stale queue/admin reconciliation request. */
				$result = $fresh instanceof WC_Order ? 'already_sent' : new WP_Error( 'mobo_core_invalid_order', 'Order disappeared before reconciliation.' );
			} else {
				$auth = $this->ensure_mobo_authenticated( true );
				$result = is_wp_error( $auth ) ? $auth : $this->reconcile_uncertain_paid_order( $fresh );
			}
		} finally {
			$this->release_mobo_cart_lock( $lock );
			Mobo_Core_Lock::release( $order_lock_name, $order_lock_token );
			$this->active_order_submission_lock_name = '';
			$this->active_order_submission_lock_token = '';
		}
		if ( true === $result ) {
			$fresh = wc_get_order( $order_id );
			if ( $fresh instanceof WC_Order ) {
				$fingerprint = sanitize_text_field( (string) $fresh->get_meta( '_mobo_order_purchase_business_fingerprint', true ) );
				if ( '' !== $fingerprint ) {
					$this->mark_post_payment_order_divergence( $order_id, array( 'fingerprint' => $fingerprint ) );
					$fresh = wc_get_order( $order_id );
				}
				$mobo_order_id = $fresh instanceof WC_Order ? absint( $fresh->get_meta( '_mobo_order_id', true ) ) : 0;
				if ( $mobo_order_id > 0 ) {
					$reconcile_payment = array( 'success' => true, 'paid' => true, 'id' => $mobo_order_id, 'reconciled' => true );
					/* Secondary post-success listeners can themselves have side effects.
					 * Fence the reconciled dispatch durably before invoking them so a stale
					 * queue/admin request cannot replay those listeners after local success.
					 * Listener failure is review-only, matching the normal purchase path. */
					if ( 'yes' !== sanitize_key( (string) $fresh->get_meta( '_mobo_order_reconcile_post_success_dispatched', true ) ) ) {
						$fresh->update_meta_data( '_mobo_order_reconcile_post_success_dispatched', 'yes' );
						$fresh->update_meta_data( '_mobo_order_reconcile_post_success_dispatched_at', time() );
						$fresh->save();
						try {
							do_action( 'mobo_core_mobo_order_submission_success', $order_id, $mobo_order_id, $reconcile_payment );
						} catch ( Throwable $post_success_error ) {
							$fresh = wc_get_order( $order_id );
							if ( $fresh instanceof WC_Order ) {
								$fresh->update_meta_data( '_mobo_order_post_success_hook_failed', 'yes' );
								$fresh->update_meta_data( '_mobo_order_requires_review', 'yes' );
								$fresh->save();
							}
						}
					}
				}
			}
		}
		return $result;
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
	/**
	 * Quarantine a stale running order only after acquiring the SAME per-order
	 * lease used by the purchasing worker. A second cron pass must never overwrite
	 * a live Wallet success using an old WC_Order object. Unknown lock/DB state
	 * leaves the queue intact; cleanup is allowed only after durable re-read.
	 *
	 * @return string uncertain|already_sent|busy|changed|persist_failed
	 */
	private function quarantine_stale_queued_order_attempt( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 || ! class_exists( 'Mobo_Core_Lock' ) || ! function_exists( 'wc_get_order' ) ) {
			return 'busy';
		}
		$lock_name = 'order_submission_' . $order_id;
		$token = Mobo_Core_Lock::acquire( $lock_name, 60 );
		if ( false === $token ) { return 'busy'; }
		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof WC_Order ) { return 'changed'; }
			if ( $this->order_was_already_sent_to_mobo( $fresh ) ) { return 'already_sent'; }
			$status = $this->get_order_submission_attempt_state( $fresh );
			if ( 'uncertain' === $status ) { return 'uncertain'; }
			if ( 'running_stale' !== $status ) { return 'changed'; }
			$this->mark_order_submission_uncertain( $fresh );
			$verified = wc_get_order( $order_id );
			if ( ! $verified instanceof WC_Order ) { return 'persist_failed'; }
			if ( $this->order_was_already_sent_to_mobo( $verified ) ) { return 'already_sent'; }
			return 'uncertain' === sanitize_key( (string) $verified->get_meta( '_mobo_order_submit_status', true ) )
				? 'uncertain' : 'persist_failed';
		} finally {
			Mobo_Core_Lock::release( $lock_name, $token );
		}
	}

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
	 * Only all-Mobo orders owned by exactly one Connection may enter remote
	 * submission. Mixed and multi-Connection orders are classified here so callers
	 * can fail closed without partial purchasing.
	 *
	 * @param WC_Order|null $order Order object.
	 * @return array
	 */
	private function get_order_mobo_item_scope( $order ) {
		$scope = array(
			'status'        => 'invalid',
			'total'         => 0,
			'mobo'          => 0,
			'nonMobo'       => 0,
			'invalid'       => 0,
			'connectionId'  => 0,
			'connectionIds' => array(),
			'nonMoboNames'  => array(),
			'quarantined'   => 0,
		);

		if ( ! $order instanceof WC_Order ) { return $scope; }

		$connections = array();
		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) { continue; }
			$scope['total']++;
			$product  = $line_item->get_product();
			$name     = wp_strip_all_tags( $line_item->get_name() );
			$identity = $this->get_order_line_item_identity( $line_item, $product );

			if ( ! empty( $identity['isMobo'] ) ) {
				$connection_id = absint( $identity['connectionId'] ?? 0 );
				if ( $connection_id <= 0 ) {
					$scope['invalid']++;
					$scope['nonMoboNames'][] = '' !== $name ? $name : 'محصول موبو با مالک نامشخص';
					continue;
				}
				$scope['mobo']++;
				$connections[ $connection_id ] = true;
				continue;
			}

			if ( $this->is_quarantined_mobo_line_item( $line_item ) ) {
				$scope['quarantined']++;
				$scope['nonMoboNames'][] = '' !== $name ? $name : 'محصول موبو در قرنطینه';
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

		$scope['connectionIds'] = array_values( array_map( 'absint', array_keys( $connections ) ) );
		if ( 1 === count( $scope['connectionIds'] ) ) { $scope['connectionId'] = absint( $scope['connectionIds'][0] ); }

		if ( $scope['total'] <= 0 ) {
			$scope['status'] = 'empty';
		} elseif ( $scope['invalid'] > 0 ) {
			$scope['status'] = 'invalid';
		} elseif ( $scope['quarantined'] > 0 ) {
			$scope['status'] = 'quarantined'; // Never submitted automatically; manual review (M4).
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] > 0 ) {
			$scope['status'] = 'mixed';
		} elseif ( $scope['nonMobo'] > 0 && $scope['mobo'] <= 0 ) {
			$scope['status'] = 'non_mobo';
		} elseif ( count( $scope['connectionIds'] ) > 1 ) {
			$scope['status'] = 'multi_connection';
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
	 * This helper only reports presence. It does not mean the order may be submitted:
	 * mixed orders and multi-Connection orders are blocked by order_scope_is_submittable().
	 *
	 * @param array $scope Scope from get_order_mobo_item_scope().
	 * @return bool
	 */
	private function order_scope_has_mobo_items( $scope ) {
		/* Quarantined items are Mobo-owned: route them to the unsubmittable/review
		 * path instead of the silent "not a Mobo order" path (M4). */
		return is_array( $scope ) && ( ( isset( $scope['mobo'] ) && absint( $scope['mobo'] ) > 0 ) || absint( $scope['quarantined'] ?? 0 ) > 0 );
	}

	/** Only an all-Mobo order owned by exactly one Connection may mutate a remote Source. */
	private function order_scope_is_submittable( $scope ) {
		return is_array( $scope )
			&& 'all_mobo' === sanitize_key( (string) ( $scope['status'] ?? '' ) )
			&& absint( $scope['connectionId'] ?? 0 ) > 0
			&& 1 === count( is_array( $scope['connectionIds'] ?? null ) ? $scope['connectionIds'] : array() );
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
	 * Add a note from status/scheduled hooks without saving the WC_Order row.
	 * This is used for expected fail-closed scopes where remote mutation is forbidden.
	 */
	private function add_unsubmittable_order_note( $order_id, $scope, $source = '' ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) { return; }
		$status = sanitize_key( (string) ( is_array( $scope ) && isset( $scope['status'] ) ? $scope['status'] : 'invalid' ) );
		$transient_key = 'mobo_core_unsubmittable_note_' . $order_id . '_' . $status . '_' . sanitize_key( (string) $source );
		if ( get_transient( $transient_key ) ) { return; }
		$message = $this->build_unsubmittable_order_message( $scope );
		if ( function_exists( 'wc_create_order_note' ) ) {
			wc_create_order_note( $order_id, $message, false, true );
		}
		set_transient( $transient_key, '1', DAY_IN_SECONDS );
	}

	/** Persist a terminal local block for a scope that must never mutate Mobo. */
	private function mark_order_scope_unsubmittable( $order, $scope, $source = '' ) {
		if ( ! $order instanceof WC_Order ) { return; }
		$status = sanitize_key( (string) ( is_array( $scope ) && isset( $scope['status'] ) ? $scope['status'] : 'invalid' ) );
		$message = $this->build_unsubmittable_order_message( $scope );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->update_meta_data( '_mobo_order_scope_status', $status );
		$order->update_meta_data( '_mobo_order_submit_status', 'blocked_' . $status );
		$order->update_meta_data( '_mobo_order_last_error_code', 'order_scope_' . $status );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		if ( in_array( $status, array( 'multi_connection', 'invalid', 'quarantined' ), true ) ) {
			$order->update_meta_data( '_mobo_order_requires_review', 'yes' );
		}
		$order->save();
		$order->add_order_note( $message );
		$this->append_order_log( $order, 'order_submission_blocked_scope', array( 'source' => sanitize_key( (string) $source ), 'scope' => $scope ) );
	}

	/** @return bool Whether the S1 clone guard currently refuses Source purchases. */
	private function clone_guard_blocks_purchase() {
		return class_exists( 'Mobo_Core_Clone_Guard' ) && Mobo_Core_Clone_Guard::is_purchase_blocked();
	}

	/**
	 * S1: hold one paid order for manual review because this installation is a
	 * suspected clone/staging copy (or a domain move that was not re-provisioned).
	 * Nothing is sent to the Source. Running/uncertain/sent evidence copied from
	 * the original site is never overwritten; only an idle/queued status becomes
	 * the terminal "blocked_clone" (only an administrator retry can submit it).
	 *
	 * @param WC_Order|int $order Order or id.
	 * @param string       $source Caller.
	 * @return bool Whether a hold was recorded.
	 */
	private function hold_order_for_clone_review( $order, $source = '' ) {
		if ( ! $order instanceof WC_Order ) {
			$order = absint( $order ) > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order ) ) : null;
		}
		if ( ! $order instanceof WC_Order || ! class_exists( 'Mobo_Core_Clone_Guard' ) ) {
			return false;
		}
		if ( 'yes' === (string) $order->get_meta( Mobo_Core_Clone_Guard::ORDER_META_BLOCKED, true ) ) {
			return false; /* Already held: no duplicate notes on every worker pass. */
		}
		$status = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		$order->update_meta_data( Mobo_Core_Clone_Guard::ORDER_META_BLOCKED, 'yes' );
		$order->update_meta_data( '_mobo_order_requires_review', 'yes' );
		if ( in_array( $status, array( '', 'queued' ), true ) ) {
			$order->delete_meta_data( '_mobo_order_submit_queued' );
			$order->delete_meta_data( '_mobo_order_submit_context' );
			$order->update_meta_data( '_mobo_order_submit_status', Mobo_Core_Clone_Guard::SUBMIT_STATUS );
		}
		$order->update_meta_data( '_mobo_order_last_error_code', Mobo_Core_Clone_Guard::ERROR_CODE );
		$order->update_meta_data( '_mobo_order_last_error', Mobo_Core_Clone_Guard::blocked_message() );
		$order->save();
		$order->add_order_note( Mobo_Core_Clone_Guard::order_note() );
		$this->append_order_log( $order, 'order_submission_blocked_clone_guard', array(
			'source'  => sanitize_key( (string) $source ),
			'status'  => $status,
			'reasons' => Mobo_Core_Clone_Guard::health_payload()['reasons'],
		) );
		return true;
	}

	/** Human-readable explanation for the current fail-closed order policy. */
	private function build_unsubmittable_order_message( $scope ) {
		$status = sanitize_key( (string) ( is_array( $scope ) && isset( $scope['status'] ) ? $scope['status'] : 'invalid' ) );
		if ( 'mixed' === $status ) {
			return 'این سفارش شامل محصول موبو و غیرموبو است. طبق سیاست فعلی، برای جلوگیری از خرید ناقص هیچ بخشی از سفارش به موبو ارسال نمی‌شود.';
		}
		if ( 'multi_connection' === $status ) {
			return 'این سفارش شامل محصولات متعلق به بیش از یک منبع موبو است. تا زمان تعریف سیاست Split Order، ارسال خودکار کل سفارش به موبو متوقف است.';
		}
		if ( 'invalid' === $status ) {
			return 'ارسال سفارش به موبو متوقف شد چون مالکیت حداقل یک آیتم سفارش قابل اثبات نیست.';
		}
		if ( 'quarantined' === $status ) {
			return 'این سفارش شامل محصول موبویی است که در قرنطینه مهاجرت قرار دارد؛ سفارش به‌صورت خودکار به موبو ارسال نشد و نیاز به بررسی و ثبت دستی دارد. محصول(ها): ' . implode( '، ', array_slice( is_array( $scope['nonMoboNames'] ?? null ) ? $scope['nonMoboNames'] : array(), 0, 5 ) );
		}
		return 'این سفارش طبق سیاست فعلی برای ارسال خودکار به موبو معتبر نیست.';
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
		$scope = $this->get_order_mobo_item_scope( $order );
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$this->mark_order_as_not_mobo( $order, $scope, 'submit_entry' );
			return new WP_Error( 'mobo_core_order_not_mobo', 'این سفارش محصول موبو ندارد.' );
		}
		if ( ! $this->order_scope_is_submittable( $scope ) ) {
			$this->mark_order_scope_unsubmittable( $order, $scope, 'submit_entry' );
			return new WP_Error( 'mobo_core_order_scope_not_submittable', 'این سفارش طبق سیاست فعلی قابل ارسال خودکار به موبو نیست.' );
		}
		$connection_id = absint( $scope['connectionId'] );
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
			return new WP_Error( 'mobo_core_order_connection_context_missing', 'Order Source context is unavailable.' );
		}
		return Mobo_Core_Connection_Context::run(
			$connection_id,
			function () use ( $order, $context, $connection_id ) {
				$context = is_array( $context ) ? $context : array();
				$context['connectionId'] = $connection_id;
				return $this->submit_order_to_mobo_in_connection( $order, $context, $connection_id );
			}
		);
	}

	/** Execute the one-shot purchase while the owning Connection context is active. */
	/**
	 * Compare the server-owned route without relying on request-local Connection caches.
	 * Covers disable/delete/reprovision between remote cart preparation and Wallet.
	 * No raw credentials or route are written into order meta/diagnostic logs.
	 * This check narrows the race; it is not a transactional disable/HTTP barrier.
	 *
	 * @return string SHA-256 digest or empty string if the owner is not eligible.
	 */
	/**
	 * Shared-cart and admin-login HTTP must observe the same durable owner that
	 * populated their request-local URL/provider/cookie context. A disabled or
	 * reprovisioned Source cannot be silently replaced by Default. This is a
	 * pre-send fence, NOT a cross-process HTTP cancellation protocol.
	 * @return true|WP_Error
	 */
	private function fresh_checkout_route_preflight() {
		/* S1: a clone/staging copy or an un-reprovisioned domain move never talks to
		 * the Source (login, shared cart, checkout, Wallet, balance). Every outbound
		 * Source request passes through this fence, so it is the hard backstop for
		 * the entry-point holds below. */
		if ( class_exists( 'Mobo_Core_Clone_Guard' ) && Mobo_Core_Clone_Guard::is_purchase_blocked() ) {
			return Mobo_Core_Clone_Guard::blocked_error();
		}
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return true; // Only for pre-Connection-table upgrade compatibility.
		}
		$id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		$fresh = $id > 0 ? Mobo_Core_Connection_Repository::get_by_id_fresh( $id ) : null;
		$cached = Mobo_Core_Connection_Repository::get_runtime_connection();
		$valid = $id > 0 && is_array( $fresh ) && is_array( $cached )
			&& absint( $cached['id'] ?? 0 ) === $id
			&& ! empty( $fresh['is_enabled'] )
			&& Mobo_Core_Connection_Repository::is_provisioned( $fresh );
		if ( $valid ) {
			foreach ( array( 'provider_key', 'source_key', 'source_site_url', 'api_base_url', 'connection_key', 'license_token', 'security_code', 'money_unit', 'money_fraction_digits', 'money_price_representation', 'money_contract_version' ) as $field ) {
				if ( ! hash_equals( (string) ( $cached[ $field ] ?? '' ), (string) ( $fresh[ $field ] ?? '' ) ) ) {
					$valid = false;
					break;
				}
			}
		}
		if ( $valid && '' !== $this->active_order_route_fingerprint ) {
			$valid = $id === absint( $this->active_order_route_connection_id ) && $this->active_order_route_is_current();
		}
		return $valid ? true : new WP_Error( 'mobo_core_order_connection_changed', 'Connection routing changed before the remote request; it was not sent.', array( 'requestMayHaveReachedServer' => false ) );
	}

	private function get_fresh_order_route_fingerprint( $connection_id ) {
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' )
			|| ! method_exists( 'Mobo_Core_Connection_Repository', 'get_by_id_fresh' ) ) {
			return '';
		}
		$row = Mobo_Core_Connection_Repository::get_by_id_fresh( $connection_id );
		if ( ! is_array( $row ) || empty( $row['is_enabled'] )
			|| ! Mobo_Core_Connection_Repository::is_provisioned( $row ) ) {
			return '';
		}
		$parts = array( (string) $connection_id );
		foreach ( array( 'provider_key', 'source_key', 'source_site_url', 'api_base_url', 'connection_key', 'license_token', 'security_code', 'money_unit', 'money_fraction_digits', 'money_price_representation', 'money_contract_version' ) as $field ) {
			$parts[] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
		}
		$encoded = wp_json_encode( $parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) && '' !== $encoded ? hash( 'sha256', $encoded ) : '';
	}

	/** Return true only while the current attempt still owns its original live route. */
	private function active_order_route_is_current() {
		$expected = (string) $this->active_order_route_fingerprint;
		$id = absint( $this->active_order_route_connection_id );
		if ( '' === $expected || $id <= 0 ) { return false; }
		$current = $this->get_fresh_order_route_fingerprint( $id );
		return '' !== $current && hash_equals( $expected, $current );
	}

	private function submit_order_to_mobo_in_connection( $order, $context, $expected_connection_id ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_invalid_order', 'Invalid WooCommerce order.' );
		}
		$expected_connection_id = absint( $expected_connection_id );
		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $expected_connection_id <= 0 || $current_connection_id !== $expected_connection_id ) {
			return new WP_Error( 'mobo_core_order_connection_context_mismatch', 'Order Source context does not match the active Connection.' );
		}

		$order_id = absint( $order->get_id() );
		if ( $this->clone_guard_blocks_purchase() ) {
			/* S1 entry fence for every purchase path (queue worker, admin retry, CLI). */
			$this->hold_order_for_clone_review( $order, 'submit_entry' );
			return new WP_Error( 'mobo_core_clone_guard_blocked', Mobo_Core_Clone_Guard::blocked_message(), array( 'requestMayHaveReachedServer' => false ) );
		}
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
			if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
				return $this->fail_mobo_order_submission( $order, 'money_policy_unavailable', 'Monetary compatibility policy is unavailable; order was not sent.' );
			}
			$staged_no_send = defined( 'MOBO_CORE_PUBLISH_STAGED_NO_SEND' ) && MOBO_CORE_PUBLISH_STAGED_NO_SEND;
			/* Store the LOCAL observation without making any assertion about remote
			 * provisioning. The production execution MUST retain Source money and
			 * fresh-route gates; no staged PREPARED record may authorize sending. */
			if ( $staged_no_send ) {
				if ( ! $this->p17_local_owner_exists_for_no_send( $expected_connection_id ) ) {
					$this->p17_hold_no_send( $order, 'staged_local_connection_missing_or_disabled' );
					return self::p17_blocked( 'staged_local_owner_unavailable' );
				}
			} else {
				$money_compatibility = Mobo_Core_Money_Policy::assert_compatible( $expected_connection_id, true );
				if ( is_wp_error( $money_compatibility ) ) {
					return $this->fail_mobo_order_submission(
						$order,
						'money_contract_incompatible',
						$money_compatibility->get_error_message()
					);
				}
			}
			/* Immutable Woo order unit must match the local shop even for NO-SEND.
			 * Neither IRR/IRT exchange nor Source money claims are inferred here. */
			$order_unit = Mobo_Core_Money_Policy::normalize_unit( $order->get_currency() );
			if ( '' === $order_unit || $order_unit !== Mobo_Core_Money_Policy::store_unit() ) {
				if ( $staged_no_send ) {
					$this->p17_hold_no_send( $order, 'staged_order_currency_mismatch' );
					return self::p17_blocked( 'staged_order_currency_mismatch' );
				}
				return $this->fail_mobo_order_submission( $order, 'order_currency_mismatch', 'ارز ثبت‌شده روی سفارش با ارز فعلی فروشگاه یکسان نیست؛ خرید موبو متوقف شد.' );
			}
			if ( ! $staged_no_send ) {
				$route_fingerprint = $this->get_fresh_order_route_fingerprint( $expected_connection_id );
				if ( '' === $route_fingerprint ) {
					/* No blind default-Source fallback and no remote side effects. */
					return new WP_Error( 'mobo_core_order_submission_deferred', 'Order owner is disabled, missing or not currently provisioned.' );
				}
				$this->active_order_route_connection_id = $expected_connection_id;
				$this->active_order_route_fingerprint   = $route_fingerprint;
			}

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

			/* Every entry point must honor recovery evidence, not just the queue
			 * consumer. Owning a new lease does not retire an older remote attempt. */
			$attempt_state = $this->get_order_submission_attempt_state( $fresh );
			if ( in_array( $attempt_state, array( 'uncertain', 'running_recent', 'running_stale' ), true ) && ! ( $explicit_admin_retry && $verified_absent ) ) {
				return new WP_Error( 'mobo_core_order_submission_uncertain', 'A previous submission attempt is running or uncertain and must be verified before retry.' );
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
				$pending_remote_id = absint( $fresh->get_meta( '_mobo_order_pending_remote_id', true ) );
				if ( $pending_remote_id > 0 ) {
					return new WP_Error( 'mobo_core_order_submission_uncertain', 'A durable remote order ID exists; manual absence override cannot start a second Checkout.' );
				}
				if ( $verified_absent && ! $this->clear_order_payment_guard( $order_id ) ) {
					return new WP_Error( 'mobo_core_order_payment_guard_clear_failed', 'The durable payment uncertainty guard could not be cleared after verification.' );
				}
				if ( $verified_absent && ! $this->clear_order_remote_checkpoint( $fresh, true ) ) {
					return new WP_Error( 'mobo_core_order_remote_checkpoint_clear_failed', 'The remote-attempt checkpoint could not be retired after verified absence.' );
				}
				$fresh = wc_get_order( $order_id );
				if ( ! $fresh instanceof WC_Order ) { return new WP_Error( 'mobo_core_invalid_order', 'Order disappeared after retry checkpoint cleanup.' ); }

				/* Reset retry state only after this request owns the per-order lease. If an
				 * upgrade barrier or another worker owns the order, the previous durable
				 * failed/uncertain state must remain intact. */
				$fresh->delete_meta_data( '_mobo_order_submit_attempted' );
				$fresh->delete_meta_data( '_mobo_order_submit_attempted_at' );
				$fresh->delete_meta_data( '_mobo_order_submit_attempt_key' );
				$fresh->delete_meta_data( '_mobo_order_submit_queued' );
				$fresh->delete_meta_data( '_mobo_order_submit_queued_at' );
				$fresh->delete_meta_data( '_mobo_order_submit_context' );
				$fresh->delete_meta_data( '_mobo_order_submit_status' );
				$fresh->delete_meta_data( '_mobo_order_last_error_code' );
				$fresh->delete_meta_data( '_mobo_order_last_error' );
				$fresh->delete_meta_data( '_mobo_order_failed_at' );
				$fresh->delete_meta_data( '_mobo_order_uncertain_at' );
				if ( 'yes' === (string) $fresh->get_meta( '_mobo_order_clone_blocked', true ) ) {
					/* The administrator reviewed an S1 clone hold on the re-provisioned site. */
					$fresh->delete_meta_data( '_mobo_order_clone_blocked' );
					$fresh->delete_meta_data( '_mobo_order_requires_review' );
				}
				$this->clear_order_defer_backoff_meta( $fresh );
				$fresh->save();
			}

			return $this->submit_order_to_mobo_owned( $fresh, is_array( $context ) ? $context : array() );
		} finally {
			/* No route snapshot may survive this order's lease or bleed into the
			 * next order processed by the same validator instance. */
			$this->active_order_route_fingerprint = '';
			$this->active_order_route_connection_id = 0;
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
		if ( ! $this->order_scope_is_submittable( $scope ) ) {
			$this->mark_order_scope_unsubmittable( $order, $scope, 'submit_guard' );
			return new WP_Error( 'mobo_core_order_scope_not_submittable', 'این سفارش طبق سیاست فعلی قابل ارسال خودکار به موبو نیست.' );
		}
		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $current_connection_id <= 0 || $current_connection_id !== absint( $scope['connectionId'] ) ) {
			return new WP_Error( 'mobo_core_order_connection_context_mismatch', 'Order Source context changed before remote submission.' );
		}

		$attempted_at = time();
		$attempt_key = $this->generate_order_submission_attempt_key( $order_id );
		$order->update_meta_data( '_mobo_order_submit_attempted', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_attempted_at', $attempted_at );
		$order->update_meta_data( '_mobo_order_submit_attempt_key', $attempt_key );
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
			|| ! hash_equals( $attempt_key, sanitize_text_field( (string) $durable_order->get_meta( '_mobo_order_submit_attempt_key', true ) ) )
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

		/* INTEGRATION-04: the staged path must freeze a strictly local order
		 * intent BEFORE any remote Location Resolver, cart or Provider access.
		 * This is NOT a Source-verified business quote or a send authorization. */
		if ( defined( 'MOBO_CORE_PUBLISH_STAGED_NO_SEND' ) && MOBO_CORE_PUBLISH_STAGED_NO_SEND ) {
			$local_fingerprint = $this->p17_local_intent_fingerprint( $order, $scope, $items );
			if ( is_wp_error( $local_fingerprint ) ) {
				$this->p17_hold_no_send( $order, 'staged_local_intent_unverifiable' );
				return self::p17_blocked( 'local_intent_unverifiable' );
			}
			return $this->p17_prepare_outbox_no_send( $order, $scope, array( 'fingerprint' => $local_fingerprint ) );
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
			$error_data = $contact_preflight->get_error_data();
			if ( is_array( $error_data ) && 'defer' === sanitize_key( (string) ( $error_data['action'] ?? '' ) ) ) {
				return $this->defer_mobo_order_submission( $order, 'location_resolution_pending', $contact_preflight->get_error_message(), array( 'remoteSideEffectsStarted' => false ) );
			}
			return $this->fail_mobo_order_submission( $order, 'checkout_payload_failed', $contact_preflight->get_error_message() );
		}

		if ( ! $this->provider_uses_replace_cart_sync() ) {
			return $this->fail_mobo_order_submission( $order, 'provider_cart_strategy_unsupported', 'The active Source provider does not support deterministic replace/rebuild cart synchronization.' );
		}

		$business_snapshot = $this->build_order_submission_business_snapshot( $order, $items, $contact_preflight );
		if ( is_wp_error( $business_snapshot ) ) {
			return $this->fail_mobo_order_submission( $order, 'business_snapshot_failed', $business_snapshot->get_error_message() );
		}

		/* Operational candidate: continue into the existing guarded submission lifecycle. */

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

				$response  = $this->add_mobo_cart_item_by_variant( $portal_variant_id, $quantity, $order );
				if ( $this->is_auth_error_response( $response ) ) {
					$this->shared_mobo_cart_rebuild_ready = false;
					return $this->defer_mobo_order_submission(
						$order,
						'cart_rebuild_auth_changed',
						'Mobo authentication changed during cart rebuild; the complete Clear -> Add All sequence will be restarted before any checkout/payment step.',
						array( 'remoteSideEffectsStarted' => true, 'portalVariantId' => $portal_variant_id )
					);
				}
				$add_check = $this->validate_mobo_cart_add_response( $response, $portal_variant_id, $name );

				if ( is_wp_error( $add_check ) ) {
					return $this->fail_mobo_order_submission( $order, 'cart_add_failed', $add_check->get_error_message() );
				}
			}

			/* Provider storefront cart flow may require refresh with update=true. */
			$snapshot = $this->get_mobo_cart_snapshot_json( true );
			if ( $this->is_auth_error_response( $snapshot ) ) {
				$this->shared_mobo_cart_rebuild_ready = false;
				return $this->defer_mobo_order_submission(
					$order,
					'cart_rebuild_verify_auth_changed',
					'Mobo authentication changed before final cart verification; the complete Clear -> Add All sequence will be restarted.',
					array( 'remoteSideEffectsStarted' => true )
				);
			}
			if ( is_wp_error( $snapshot ) ) {
				return $this->fail_mobo_order_submission( $order, 'cart_snapshot_failed', $snapshot->get_error_message() );
			}

			$compare_errors = $this->compare_mobo_snapshot_with_items( $snapshot, $items );
			if ( ! empty( $compare_errors ) ) {
				return $this->fail_mobo_order_submission( $order, 'cart_compare_failed', implode( ' | ', $compare_errors ) );
			}
			/* The final authoritative snapshot seals this Clear -> Add All rebuild. */
			$this->shared_mobo_cart_rebuild_ready = false;

			/* Per-line, per-unit Source price guard. Stops BEFORE Checkout creates a
			 * remote order and long before Wallet; shipping is never compared. */
			$price_guard = $this->enforce_source_price_match_before_checkout( $order, $snapshot, $context );
			if ( true !== $price_guard ) {
				return $price_guard;
			}

			$checkout_payload = $this->build_mobo_checkout_payload( $order, $snapshot, $contact_preflight );
			if ( is_wp_error( $checkout_payload ) ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_payload_failed', $checkout_payload->get_error_message() );
			}

			$token = isset( $checkout_payload['token'] ) && is_scalar( $checkout_payload['token'] ) ? $this->parse_mobo_cart_token( $checkout_payload['token'] ) : '';
			if ( '' === $token ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_token_missing', 'Mobo checkout token is missing.' );
			}
			$business_fingerprint = isset( $business_snapshot['fingerprint'] ) ? sanitize_text_field( (string) $business_snapshot['fingerprint'] ) : '';
			if ( ! $this->persist_order_remote_checkpoint( $order, 'checkout_prepared', $token, 0, 0, $business_fingerprint ) ) {
				return $this->fail_mobo_order_submission( $order, 'checkout_checkpoint_persist_failed', 'Checkout was not sent because the durable remote-attempt checkpoint could not be verified.' );
			}

			$checkout = $this->order_mobo_request( $order, 'POST', $this->get_provider_endpoint( 'cart_checkout' ), $checkout_payload, 'cart_checkout' );
			$checkout_needs_positive_reconcile = false;
			if ( is_wp_error( $checkout ) ) {
				if ( ! $this->order_request_error_is_ambiguous( $checkout ) ) {
					$this->clear_order_remote_checkpoint( $order, false );
					return $this->fail_mobo_order_submission( $order, 'checkout_failed', $checkout->get_error_message() );
				}
				$checkout_needs_positive_reconcile = true;
			}

			if ( ! is_wp_error( $checkout ) ) {
				$checkout_code = absint( wp_remote_retrieve_response_code( $checkout ) );
				if ( 200 !== $checkout_code ) {
					$checkout_needs_positive_reconcile = true;
				}

				/* Preserve historical empty-200 compatibility. Malformed non-empty 2xx is
				 * ambiguous because Checkout may already have created the remote order. */
				$checkout_raw = trim( (string) wp_remote_retrieve_body( $checkout ) );
				if ( '' !== $checkout_raw ) {
					$checkout_json = json_decode( $checkout_raw, true );
					if ( ! is_array( $checkout_json ) ) {
						$checkout_needs_positive_reconcile = true;
					} elseif ( array_key_exists( 'success', $checkout_json ) && ! $this->to_bool( $checkout_json['success'] ) ) {
						$message = $this->first_non_empty_scalar( array(
							isset( $checkout_json['description'] ) ? $checkout_json['description'] : '',
							isset( $checkout_json['message'] ) ? $checkout_json['message'] : '',
							'Mobo checkout explicitly rejected the order.',
						) );
						$this->clear_order_remote_checkpoint( $order, false );
						return $this->fail_mobo_order_submission( $order, 'checkout_not_success', sanitize_text_field( $message ) );
					}
				}
			}

			$details_json = $this->get_mobo_order_details( $order, $token );
			if ( is_wp_error( $details_json ) ) {
				return $this->mark_order_submission_uncertain(
					$order,
					$checkout_needs_positive_reconcile ? 'checkout_result_uncertain' : 'checkout_committed_details_unavailable',
					'Checkout may have created a remote order, but its durable identity could not be positively reconciled. Automatic resubmission is blocked.',
					array( 'remoteStage' => 'checkout', 'tokenSha256' => hash( 'sha256', $token ) )
				);
			}

			$details = isset( $details_json['details'] ) && is_array( $details_json['details'] ) ? $details_json['details'] : array();
			$details_mobo_order_id = array_key_exists( 'id', $details ) ? $this->parse_positive_integer_id( $details['id'] ) : 0;
			if ( empty( $details ) || $details_mobo_order_id <= 0 ) {
				return $this->mark_order_submission_uncertain(
					$order,
					'checkout_reconciliation_inconclusive',
					'Checkout returned or may have returned successfully, but Details did not positively expose a remote order ID. Automatic replay is blocked.',
					array( 'remoteStage' => 'checkout', 'tokenSha256' => hash( 'sha256', $token ) )
				);
			}
			if ( ! $this->persist_order_remote_checkpoint( $order, 'remote_order_known', $token, $details_mobo_order_id, 0, $business_fingerprint ) ) {
				return $this->mark_order_submission_uncertain( $order, 'remote_order_checkpoint_failed', 'A remote Mobo order exists, but its identity could not be committed locally. Automatic replay is blocked.', array( 'moboOrderId' => $details_mobo_order_id ) );
			}
			if ( $checkout_needs_positive_reconcile ) {
				$this->append_order_log( $order, 'checkout_positive_reconciliation', array( 'moboOrderId' => $details_mobo_order_id ) );
			}

			$shippings_json = $this->get_mobo_order_shippings( $order, $token );
			if ( is_wp_error( $shippings_json ) ) {
				return $this->mark_order_submission_uncertain( $order, 'remote_order_shipping_options_unavailable', 'The remote order exists but shipping options could not be retrieved. Automatic replay from Checkout is blocked.', array( 'moboOrderId' => $details_mobo_order_id ) );
			}

			$shipping_id = $this->resolve_mobo_shipping_id( $order, $shippings_json );
			if ( is_wp_error( $shipping_id ) ) {
				return $this->mark_order_submission_uncertain( $order, 'remote_order_shipping_resolution_failed', 'The remote order exists but its shipping method could not be resolved safely. Automatic replay from Checkout is blocked.', array( 'moboOrderId' => $details_mobo_order_id ) );
			}

			$shipping_payload = $this->build_mobo_order_stage_payload( $details, $shipping_id, 'wallet', null );
			$shipping = $this->order_mobo_request( $order, 'POST', $this->get_provider_endpoint( 'cart_shipping' ), $shipping_payload, 'cart_shipping' );
			if ( is_wp_error( $shipping ) ) {
				return $this->mark_order_submission_uncertain( $order, 'remote_order_shipping_request_uncertain', 'The remote order already exists, but the Shipping mutation was not positively acknowledged. Automatic Checkout replay is blocked.', array( 'moboOrderId' => $details_mobo_order_id ) );
			}

			$shipping_json = $this->decode_mobo_response_json( $shipping );
			if ( is_wp_error( $shipping_json ) ) {
				return $this->mark_order_submission_uncertain( $order, 'remote_order_shipping_response_unreadable', 'The remote order already exists and Shipping returned an unreadable response. Automatic Checkout replay is blocked.', array( 'moboOrderId' => $details_mobo_order_id ) );
			}
			if ( isset( $shipping_json['success'] ) && ! $this->to_bool( $shipping_json['success'] ) ) {
				return $this->mark_order_submission_uncertain( $order, 'remote_order_shipping_rejected', isset( $shipping_json['description'] ) ? sanitize_text_field( (string) $shipping_json['description'] ) : 'The remote order exists but Shipping was rejected; automatic Checkout replay is blocked.', array( 'moboOrderId' => $details_mobo_order_id ) );
			}

			$payment_details = isset( $shipping_json['details'] ) && is_array( $shipping_json['details'] ) ? $shipping_json['details'] : $details;
			$details_mobo_order_id = array_key_exists( 'id', $details ) ? $this->parse_positive_integer_id( $details['id'] ) : 0;
			$shipping_mobo_order_id = array_key_exists( 'id', $payment_details ) ? $this->parse_positive_integer_id( $payment_details['id'] ) : 0;
			if ( $details_mobo_order_id > 0 && $shipping_mobo_order_id > 0 && $details_mobo_order_id !== $shipping_mobo_order_id ) {
				return $this->mark_order_submission_uncertain( $order, 'mobo_order_id_changed_before_payment', 'Remote order identity changed between Details and Shipping. Wallet was not started and automatic Checkout replay is blocked.', array( 'detailsMoboOrderId' => $details_mobo_order_id, 'shippingMoboOrderId' => $shipping_mobo_order_id ) );
			}
			$pre_payment_mobo_order_id = $shipping_mobo_order_id > 0 ? $shipping_mobo_order_id : $details_mobo_order_id;
			if ( $pre_payment_mobo_order_id <= 0 ) {
				return $this->mark_order_submission_uncertain( $order, 'mobo_order_id_missing_before_payment', 'A remote Checkout exists but its order ID is not provable before Wallet. Automatic replay is blocked.' );
			}
			if ( ! $this->persist_order_remote_checkpoint( $order, 'shipping_applied', $token, $pre_payment_mobo_order_id, $shipping_id, $business_fingerprint ) ) {
				return $this->mark_order_submission_uncertain( $order, 'pre_payment_checkpoint_failed', 'The remote order exists, but its pre-payment checkpoint could not be persisted. Wallet was not sent and automatic Checkout replay is blocked.', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
			}

			/* Re-read the WooCommerce order at the last reversible boundary. An admin,
			 * payment callback, stock plugin, or customer-side workflow may have changed
			 * status/items/address/shipping while the remote cart was being prepared. */
			$final_guard = $this->validate_order_before_wallet_payment( $order_id, $business_snapshot, $shipping_id, $shippings_json );
			if ( is_wp_error( $final_guard ) ) {
				/* A remote order already exists. Even though Wallet has not started,
				 * rebuilding Checkout on a later automatic attempt could create a second
				 * remote order. Quarantine the attempt instead of returning retryable. */
				return $this->mark_order_submission_uncertain(
					$order,
					$final_guard->get_error_code(),
					$final_guard->get_error_message(),
					array( 'remoteSideEffectsStarted' => true, 'moboOrderId' => $pre_payment_mobo_order_id, 'remoteStage' => 'pre_payment_guard' )
				);
			}
			$order = $final_guard;
			if ( ! $this->active_order_route_is_current() ) {
				return $this->mark_order_submission_uncertain( $order, 'order_connection_changed_before_payment', 'Connection routing changed after a remote order was created. Wallet was not sent; automatic Checkout replay is blocked.', array( 'remoteSideEffectsStarted' => true, 'moboOrderId' => $pre_payment_mobo_order_id ) );
			}
			$payment_payload = $this->build_mobo_order_stage_payload( $payment_details, $shipping_id, 'wallet', null );

			/* Persist an out-of-database fence immediately before the irreversible Wallet
			 * Payment POST. If MySQL dies after Mobo receives the request, the next PHP
			 * process still has durable evidence that automatic retry is unsafe. */
			if ( ! $this->persist_order_payment_guard( $order_id, 'payment_inflight', array( 'attemptedAt' => $attempted_at, 'moboOrderId' => $pre_payment_mobo_order_id, 'attemptKey' => sanitize_text_field( (string) $order->get_meta( '_mobo_order_submit_attempt_key', true ) ) ) ) ) {
				return $this->mark_order_submission_uncertain( $order, 'payment_guard_persist_failed', 'A remote order exists but the independent pre-payment fence could not be persisted. Wallet was not sent; automatic Checkout replay is blocked.', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
			}
			if ( ! $this->persist_order_remote_checkpoint( $order, 'payment_inflight', $token, $pre_payment_mobo_order_id, $shipping_id, $business_fingerprint ) ) {
				return $this->mark_order_submission_uncertain( $order, 'payment_checkpoint_persist_failed', 'The remote order exists but the payment checkpoint could not be committed. Wallet was not sent; automatic replay is blocked.', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
			}

			/* Never replay the irreversible wallet call after an auth-shaped response.
			 * Any non-successful acknowledgement is handled below as uncertain unless
			 * Mobo explicitly proves that the wallet was not charged. */
			$payment = $this->order_mobo_request( $order, 'POST', $this->get_provider_endpoint( 'cart_wallet_payment' ), $payment_payload, 'cart_payment_wallet', false );
			$payment_json = null;
			if ( is_wp_error( $payment ) ) {
				if ( 'mobo_core_order_connection_changed' === $payment->get_error_code() ) {
					if ( ! $this->clear_order_payment_guard( $order_id ) ) {
						return $this->mark_order_submission_uncertain( $order, 'payment_guard_clear_failed', 'The payment was not sent, but its filesystem guard could not be retired safely.' );
					}
					return $this->mark_order_submission_uncertain( $order, 'order_connection_changed_before_wallet_request', 'The remote order exists but routing changed before Wallet was sent. Automatic Checkout replay is blocked.', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
				}
				if ( $this->payment_error_is_ambiguous( $payment ) ) {
					$reconcile = $this->reconcile_remote_order_details( $order, $token, $pre_payment_mobo_order_id );
					if ( ! is_wp_error( $reconcile ) && ! empty( $reconcile['paidKnown'] ) && ! empty( $reconcile['paid'] ) ) {
						$payment_json = array( 'success' => true, 'paid' => true, 'id' => $pre_payment_mobo_order_id, 'reconciled' => true );
						$this->append_order_log( $order, 'wallet_positive_reconciliation', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
					} else {
						return $this->mark_order_submission_uncertain( $order, 'payment_result_uncertain', 'Wallet may have reached Mobo and paid=true could not be positively reconciled. Automatic replay remains blocked.', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
					}
				} else {
					/* Even a definite local/pre-request Wallet error occurs after the remote
					 * order exists. Do not rebuild Checkout automatically. */
					$this->clear_order_payment_guard( $order_id );
					return $this->mark_order_submission_uncertain( $order, 'payment_not_sent_remote_order_exists', $payment->get_error_message(), array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
				}
			}

			if ( null === $payment_json ) {
				$payment_json = $this->decode_mobo_response_json( $payment );
				if ( is_wp_error( $payment_json ) ) {
					$reconcile = $this->reconcile_remote_order_details( $order, $token, $pre_payment_mobo_order_id );
					if ( ! is_wp_error( $reconcile ) && ! empty( $reconcile['paidKnown'] ) && ! empty( $reconcile['paid'] ) ) {
						$payment_json = array( 'success' => true, 'paid' => true, 'id' => $pre_payment_mobo_order_id, 'reconciled' => true );
						$this->append_order_log( $order, 'wallet_unreadable_response_reconciled', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
					} else {
						return $this->mark_order_submission_uncertain( $order, 'payment_response_unreadable', 'Wallet returned an unreadable response and paid=true could not be positively reconciled. Automatic replay remains blocked.', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
					}
				}
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

			/* Even an explicit paid=false is not proof that creating a second remote
			 * order is safe: Checkout already produced a durable remote identity.
			 * Values such as null, "pending", arrays, or arbitrary numerics are even
			 * less conclusive. All of these remain quarantined from Checkout replay. */
			if ( $payment_paid_known && ! $payment_paid ) {
				$this->clear_order_payment_guard( $order_id );
				return $this->mark_order_submission_uncertain(
					$order,
					'payment_not_paid_remote_order_exists',
					isset( $payment_json['description'] ) ? sanitize_text_field( (string) $payment_json['description'] ) : 'Mobo explicitly reported paid=false, but the remote order already exists. Automatic Checkout replay is blocked.',
					array( 'moboOrderId' => $pre_payment_mobo_order_id )
				);
			}

			if ( ! $payment_success_known || ! $payment_paid_known || ! $payment_success || ! $payment_paid ) {
				$reconcile = $this->reconcile_remote_order_details( $order, $token, $pre_payment_mobo_order_id );
				if ( ! is_wp_error( $reconcile ) && ! empty( $reconcile['paidKnown'] ) && ! empty( $reconcile['paid'] ) ) {
					$payment_json = array( 'success' => true, 'paid' => true, 'id' => $pre_payment_mobo_order_id, 'reconciled' => true );
					$payment_success_known = true;
					$payment_paid_known = true;
					$payment_success = true;
					$payment_paid = true;
					$this->append_order_log( $order, 'wallet_incomplete_ack_reconciled', array( 'moboOrderId' => $pre_payment_mobo_order_id ) );
				} else {
					return $this->mark_order_submission_uncertain(
						$order,
						'payment_ack_incomplete',
						'Wallet acknowledgement was incomplete and paid=true could not be positively reconciled. Automatic replay remains blocked.',
						array( 'moboOrderId' => $pre_payment_mobo_order_id, 'response' => $this->sanitize_order_log_value( $payment_json ) )
					);
				}
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
				$errors[] = sprintf( 'سفارش ترکیبی است؛ آیتم غیرموبو «%s» اجازه ارسال جزئی سفارش به موبو را نمی‌دهد.', $name );
				continue;
			}
			$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			$item_connection_id = absint( isset( $identity['connectionId'] ) ? $identity['connectionId'] : 0 );
			if ( $current_connection_id <= 0 || $item_connection_id <= 0 || $item_connection_id !== $current_connection_id ) {
				$errors[] = sprintf( 'مالکیت منبع آیتم «%s» با Connection فعال سفارش سازگار نیست.', $name );
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

	private function build_mobo_checkout_payload( $order, $snapshot, $contact = null ) {
		if ( ! isset( $snapshot['cart'] ) || ! is_array( $snapshot['cart'] ) ) {
			return new WP_Error( 'mobo_core_cart_snapshot_missing', 'Mobo cart snapshot does not contain cart.' );
		}

		$cart  = $snapshot['cart'];
		$token = array_key_exists( 'token', $cart ) ? $this->parse_mobo_cart_token( $cart['token'] ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'mobo_core_cart_token_invalid', 'Mobo cart token is missing or malformed.' );
		}
		$cart['token'] = $token;

		if ( ! is_array( $contact ) ) {
			$contact = $this->build_mobo_order_contact_payload( $order );
		}
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$provider = $this->get_mobo_provider();
		if ( ! $provider instanceof Mobo_Core_Provider_Interface ) {
			return new WP_Error( 'mobo_core_provider_unavailable', 'No supported provider is bound to the active Connection Source.' );
		}
		/* Contact field naming (e.g. Portal.ir's recipient name in `phone` and
		 * recipient mobile in `email`) is provider contract knowledge: see
		 * Mobo_Core_Provider_Portal::build_order_contact_payload(). */
		foreach ( (array) $provider->build_order_contact_payload( $contact ) as $field => $value ) {
			$cart[ $field ] = $value;
		}
		$location_fields = $provider->build_order_location_payload( isset( $contact['resolvedLocation'] ) ? $contact['resolvedLocation'] : array() );
		if ( is_wp_error( $location_fields ) ) {
			return $location_fields;
		}
		foreach ( is_array( $location_fields ) ? $location_fields : array() as $field => $value ) {
			$cart[ $field ] = $value;
		}

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
		if ( '' === $recipient_mobile ) {
			$recipient_mobile = $this->order_recipient_mobile_fallback( $order, $group );
		}
		/* A5: normalize before sending (Persian/Arabic digits, separators, +98/0098). */
		$recipient_mobile = Mobo_Core_Address_Normalizer::phone( $recipient_mobile );
		$recipient_name   = Mobo_Core_Address_Normalizer::text( $recipient_name );

		if ( ! class_exists( 'Mobo_Core_Address_Mapping' ) ) {
			return new WP_Error( 'mobo_core_location_resolver_unavailable', 'Central location resolver integration is unavailable.', array( 'action' => 'defer' ) );
		}
		$address_mapping = new Mobo_Core_Address_Mapping();
		if ( ! method_exists( $address_mapping, 'resolve_order_group_via_backend' ) ) {
			return new WP_Error( 'mobo_core_location_resolver_unavailable', 'Central location resolver integration is unavailable.', array( 'action' => 'defer' ) );
		}
		$resolved = $address_mapping->resolve_order_group_via_backend( $order, $group );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( method_exists( $address_mapping, 'write_order_group_location_meta' ) ) {
			$address_mapping->write_order_group_location_meta( $order, $group, $resolved );
		}
		$country_id = absint( $resolved['countryId'] ?? 0 );
		$state_id   = absint( $resolved['stateId'] ?? 0 );
		$city_id    = absint( $resolved['cityId'] ?? 0 );

		$address_1 = 'shipping' === $group ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$address_2 = 'shipping' === $group ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$postcode  = Mobo_Core_Address_Normalizer::postcode( 'shipping' === $group ? $order->get_shipping_postcode() : $order->get_billing_postcode() );
		$address   = Mobo_Core_Address_Normalizer::text( trim( trim( (string) $address_1 ) . ' ' . trim( (string) $address_2 ) ) );

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
			'countryExternalId' => sanitize_text_field( (string) ( $resolved['countryExternalId'] ?? '' ) ),
			'stateExternalId'   => sanitize_text_field( (string) ( $resolved['stateExternalId'] ?? '' ) ),
			'cityExternalId'    => sanitize_text_field( (string) ( $resolved['cityExternalId'] ?? '' ) ),
			'resolvedLocation'  => $resolved,
			'latitude'        => $this->order_meta_float_or_null( $order, '_mobo_' . $group . '_city_latitude' ),
			'longitude'       => $this->order_meta_float_or_null( $order, '_mobo_' . $group . '_city_longitude' ),
		);
	}

	/**
	 * Recipient mobile when the standard billing/shipping phone is empty. Only keys
	 * that unambiguously hold the customer's mobile are read:
	 * - order meta _{group}_mobile / _billing_mobile (the "mobile" field that Iranian
	 *   checkout-field editors add next to the core fields);
	 * - Digits (digits_phone user meta of the order's customer, E.164 e.g. +98912...).
	 * Anything else must be mapped explicitly with the mobo_core_order_recipient_mobile filter.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $group billing|shipping.
	 * @return string
	 */
	private function order_recipient_mobile_fallback( $order, $group ) {
		$mobile = '';
		$keys   = array_unique( array( '_' . $group . '_mobile', '_billing_mobile' ) );
		foreach ( $keys as $key ) {
			$value = trim( sanitize_text_field( (string) $order->get_meta( $key, true ) ) );
			if ( '' !== $value ) {
				$mobile = $value;
				break;
			}
		}
		$customer_id = method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : 0;
		if ( '' === $mobile && $customer_id > 0 && function_exists( 'get_user_meta' ) ) {
			$mobile = trim( sanitize_text_field( (string) get_user_meta( $customer_id, 'digits_phone', true ) ) );
		}
		$filtered = apply_filters( 'mobo_core_order_recipient_mobile', $mobile, $order, $group );
		return is_scalar( $filtered ) ? trim( sanitize_text_field( (string) $filtered ) ) : $mobile;
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
			'countryExternalId' => sanitize_text_field( (string) ( $contact['countryExternalId'] ?? '' ) ),
			'stateExternalId'   => sanitize_text_field( (string) ( $contact['stateExternalId'] ?? '' ) ),
			'cityExternalId'    => sanitize_text_field( (string) ( $contact['cityExternalId'] ?? '' ) ),
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

		/* Last irreversible boundary: neither a Store currency switch nor a
		 * changed signed Source monetary contract may reach Wallet unnoticed. */
		$current_connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		$final_money = class_exists( 'Mobo_Core_Money_Policy' ) && $current_connection_id > 0
			? Mobo_Core_Money_Policy::assert_compatible( $current_connection_id, true )
			: new WP_Error( 'mobo_core_money_context_missing', 'Order monetary Connection is unavailable.' );
		if ( is_wp_error( $final_money ) || ! class_exists( 'Mobo_Core_Money_Policy' ) || '' === Mobo_Core_Money_Policy::normalize_unit( $fresh->get_currency() )
			|| Mobo_Core_Money_Policy::normalize_unit( $fresh->get_currency() ) !== Mobo_Core_Money_Policy::store_unit() ) {
			return new WP_Error( 'mobo_core_final_order_currency_changed', 'ارز سفارش یا قرارداد Source قبل از پرداخت موبو تغییر کرده است؛ Wallet اجرا نمی‌شود.', array( 'action' => 'abort' ) );
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
		$response = $this->order_mobo_request( $order, 'GET', $this->get_provider_endpoint( 'cart_details', array( 'token' => (string) $token ) ), null, 'cart_details' );
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
		$response = $this->order_mobo_request( $order, 'GET', $this->get_provider_endpoint( 'cart_shippings', array( 'token' => (string) $token ) ), null, 'cart_shippings' );
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
		$provider = $this->get_mobo_provider();
		if ( $provider instanceof Mobo_Core_Provider_Interface ) {
			return $provider->build_order_stage_payload( $details, $shipping_id, $mode, $gateway_id );
		}
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
		if ( in_array( $error_code, array( 'mobo_core_shared_cart_lock_lost', 'mobo_core_order_submission_lock_lost', 'mobo_core_shared_cart_lock_unavailable', 'mobo_core_shared_cart_upgrade_barrier', 'mobo_core_mobo_json_error', 'mobo_core_mobo_credentials_missing', 'mobo_core_mobo_login_http_error', 'mobo_core_mobo_login_missing_cookie', 'mobo_core_order_auth_retry_failed', 'mobo_core_clone_guard_blocked' ), true ) ) {
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
		$pending_business_fingerprint = sanitize_text_field( (string) $order->get_meta( '_mobo_order_pending_business_fingerprint', true ) );
		if ( '' !== $pending_business_fingerprint ) { $order->update_meta_data( '_mobo_order_purchase_business_fingerprint', $pending_business_fingerprint ); }
		$order->delete_meta_data( '_mobo_order_last_error_code' );
		$order->delete_meta_data( '_mobo_order_last_error' );
		$order->delete_meta_data( '_mobo_order_failed_at' );
		$order->delete_meta_data( '_mobo_order_uncertain_at' );
		$this->clear_order_defer_backoff_meta( $order );
		foreach ( array( '_mobo_order_pending_stage', '_mobo_order_pending_attempt_key', '_mobo_order_pending_updated_at', '_mobo_order_pending_token', '_mobo_order_pending_token_sha256', '_mobo_order_pending_remote_id', '_mobo_order_pending_shipping_id', '_mobo_order_pending_business_fingerprint' ) as $pending_key ) {
			$order->delete_meta_data( $pending_key );
		}
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

		/* Bounded exponential backoff per consecutive deferral reason (1m..1h).
		 * The row keeps its id but becomes unclaimable until available_at, so it
		 * can no longer block later paid orders of the same Connection. */
		$previous_reason = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_defer_reason', true ) );
		$defer_count     = $previous_reason === $code ? absint( $order->get_meta( '_mobo_order_submit_defer_count', true ) ) + 1 : 1;
		$delay           = class_exists( 'Mobo_Core_Order_Price_Review' ) ? Mobo_Core_Order_Price_Review::defer_backoff_seconds( $defer_count ) : 60;
		$next_attempt_at = time() + $delay;

		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->update_meta_data( '_mobo_order_submit_queued', 'yes' );
		$order->update_meta_data( '_mobo_order_submit_queued_at', time() );
		$order->update_meta_data( '_mobo_order_submit_status', 'queued' );
		$order->update_meta_data( '_mobo_order_submit_context', array( 'trigger' => 'automatic_defer', 'reason' => $code ) );
		$order->update_meta_data( '_mobo_order_last_error_code', $code );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->update_meta_data( '_mobo_order_submit_defer_reason', $code );
		$order->update_meta_data( '_mobo_order_submit_defer_count', $defer_count );
		$order->update_meta_data( '_mobo_order_submit_next_attempt_at', $next_attempt_at );
		if ( 1 === $defer_count || absint( $order->get_meta( '_mobo_order_submit_defer_first_at', true ) ) <= 0 ) {
			$order->update_meta_data( '_mobo_order_submit_defer_first_at', time() );
		}
		$order->delete_meta_data( '_mobo_order_failed_at' );
		$order->save();

		/* B7: an unresolvable address must not stay silent forever. Escalate once
		 * (note + one email + admin notice); retries continue with backoff. */
		if ( 'location_resolution_pending' === $code && class_exists( 'Mobo_Core_Address_Attention' )
			&& Mobo_Core_Address_Attention::should_notify( $defer_count, absint( $order->get_meta( '_mobo_order_submit_defer_first_at', true ) ), time(), '' !== (string) $order->get_meta( Mobo_Core_Address_Attention::META_NOTIFIED_AT, true ) ) ) {
			Mobo_Core_Address_Attention::notify( $order, $message );
		}

		if ( 1 === $defer_count ) {
			/* One note per (new) reason keeps the reason visible without hourly note spam. */
			$order->add_order_note( sprintf(
				'ثبت خودکار سفارش در موبو موقتاً به تعویق افتاد (%s): %s تلاش بعدی به‌صورت خودکار حدود %s انجام می‌شود و در صورت تکرار، فاصله تلاش‌ها تا حداکثر یک ساعت افزایش می‌یابد. سفارش‌های بعدی منتظر این سفارش نمی‌مانند.',
				$code,
				$message,
				function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $next_attempt_at ) : gmdate( 'Y-m-d H:i', $next_attempt_at ) . ' UTC'
			) );
		}
		$this->append_order_log( $order, 'order_submission_deferred', array_merge( array( 'code' => $code, 'message' => $message, 'deferCount' => $defer_count, 'delaySeconds' => $delay, 'nextAttemptAt' => $next_attempt_at ), is_array( $context ) ? $context : array() ) );
		$this->queue_mobo_order_id_for_later( absint( $order->get_id() ), array( 'trigger' => 'automatic_defer', 'reason' => $code ), $next_attempt_at );
		return new WP_Error( 'mobo_core_order_submission_deferred', $message, array( 'reason' => $code, 'nextAttemptAt' => $next_attempt_at ) );
	}

	/** Forget deferral backoff state once an attempt reaches a non-deferred outcome (caller saves). */
	private function clear_order_defer_backoff_meta( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->delete_meta_data( '_mobo_order_submit_defer_reason' );
		$order->delete_meta_data( '_mobo_order_submit_defer_count' );
		$order->delete_meta_data( '_mobo_order_submit_next_attempt_at' );
		$order->delete_meta_data( '_mobo_order_submit_defer_first_at' );
		if ( class_exists( 'Mobo_Core_Address_Attention' ) ) {
			$order->delete_meta_data( Mobo_Core_Address_Attention::META_NOTIFIED_AT );
			Mobo_Core_Address_Attention::clear( absint( $order->get_id() ) );
		}
	}

	/**
	 * Expected Source per-unit price of every Mobo order line, keyed by portal
	 * variant id, in the Connection's Source money unit.
	 *
	 * Priority: the immutable checkout snapshot of the synced Source price
	 * (_mobo_revenue_source_unit_cost, captured from mobo_api_price when the
	 * customer ordered), then the currently synced mobo_api_price of the
	 * variation (or the simple product). Both are raw Source amounts, so they
	 * share the Source unit with the Source cart. Lines of one variant that
	 * disagree, or have no synced price, yield null (= mismatch, fail closed).
	 *
	 * @return array variantKey => array{name:string, expected:?string, origin:string}
	 */
	private function build_expected_source_unit_prices( $order ) {
		$out = array();
		if ( ! $order instanceof WC_Order || ! class_exists( 'Mobo_Core_Order_Price_Review' ) ) {
			return $out;
		}
		$snapshot_key = class_exists( 'Mobo_Core_Revenue_Ledger' ) && defined( 'Mobo_Core_Revenue_Ledger::ITEM_META_SOURCE_UNIT_COST' )
			? Mobo_Core_Revenue_Ledger::ITEM_META_SOURCE_UNIT_COST
			: '_mobo_revenue_source_unit_cost';
		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product    = $line_item->get_product();
			$identity   = $this->get_order_line_item_identity( $line_item, $product );
			$variant_id = absint( isset( $identity['portalVariantId'] ) ? $identity['portalVariantId'] : 0 );
			if ( $variant_id <= 0 ) {
				continue;
			}
			$origin   = 'checkout_snapshot';
			$expected = Mobo_Core_Order_Price_Review::canonical_decimal( (string) $line_item->get_meta( $snapshot_key, true ) );
			if ( null === $expected && class_exists( 'Mobo_Core_Money_Policy' ) ) {
				$origin = 'synced_mobo_api_price';
				$live   = Mobo_Core_Money_Policy::get_mobo_api_price( absint( $line_item->get_variation_id() ), absint( $line_item->get_product_id() ) );
				$expected = null === $live ? null : Mobo_Core_Order_Price_Review::canonical_decimal( (float) $live );
			}
			if ( null === $expected ) {
				$origin = 'unknown';
			}
			$key = (string) $variant_id;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'name' => wp_strip_all_tags( $line_item->get_name() ), 'expected' => $expected, 'origin' => $origin );
			} elseif ( $out[ $key ]['expected'] !== $expected ) {
				$out[ $key ]['expected'] = null;
				$out[ $key ]['origin']   = 'ambiguous';
			}
		}
		return $out;
	}

	/**
	 * Per-unit Source prices of the rebuilt Source cart, converted to $target_unit.
	 *
	 * @return array variantKey => list<?string>
	 */
	private function build_remote_source_unit_prices( $snapshot, $target_unit, &$fields ) {
		$fields = array();
		$out    = array();
		if ( ! class_exists( 'Mobo_Core_Order_Price_Review' ) ) {
			return $out;
		}
		$declared = Mobo_Core_Order_Price_Review::extract_snapshot_unit( $snapshot );
		$unit     = '' !== $declared ? $declared : $target_unit;
		$rows     = isset( $snapshot['cart']['items'] ) && is_array( $snapshot['cart']['items'] ) ? $snapshot['cart']['items'] : array();
		foreach ( $rows as $cart_item ) {
			$variant_id = is_array( $cart_item ) && isset( $cart_item['product']['variant'] ) && is_array( $cart_item['product']['variant'] ) && array_key_exists( 'id', $cart_item['product']['variant'] )
				? $this->parse_positive_integer_id( $cart_item['product']['variant']['id'] )
				: 0;
			if ( $variant_id <= 0 ) {
				continue; // Unknown rows already fail the existing cart comparison.
			}
			$price = Mobo_Core_Order_Price_Review::extract_remote_unit_price( $cart_item );
			$fields[ (string) $variant_id ] = $price['field'];
			$out[ (string) $variant_id ][]  = null === $price['price'] ? null : Mobo_Core_Order_Price_Review::convert_unit( $price['price'], $unit, $target_unit );
		}
		return $out;
	}

	/**
	 * Owner decision: the wallet must never pay a Source price that differs from
	 * the price the plugin expects. Runs after the cart rebuild was verified and
	 * BEFORE Checkout, so no remote order exists and no Wallet call happened.
	 * The filled Source cart is intentionally left as is (no destructive call).
	 *
	 * @return true|WP_Error
	 */
	private function enforce_source_price_match_before_checkout( $order, $snapshot, $context ) {
		if ( ! class_exists( 'Mobo_Core_Order_Price_Review' ) ) {
			return $this->fail_mobo_order_submission( $order, 'price_guard_unavailable', 'کنترل قیمت Source در دسترس نیست؛ خرید خودکار برای جلوگیری از پرداخت با قیمت نامعلوم متوقف شد.' );
		}
		/* Per-Connection kill-switch (default ON) for Sources whose cart price format differs. */
		if ( ! Mobo_Core_Order_Price_Review::guard_enabled() ) {
			$this->append_order_log( $order, 'source_price_check_disabled', array( 'setting' => Mobo_Core_Order_Price_Review::OPTION_GUARD_ENABLED ) );
			return true;
		}
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		$contract      = class_exists( 'Mobo_Core_Money_Policy' ) ? Mobo_Core_Money_Policy::get_source_contract( $connection_id, true ) : array( 'unit' => '' );
		$unit          = Mobo_Core_Order_Price_Review::normalize_unit( isset( $contract['unit'] ) ? $contract['unit'] : '' );

		$fields     = array();
		$expected   = $this->build_expected_source_unit_prices( $order );
		$remote     = $this->build_remote_source_unit_prices( $snapshot, $unit, $fields );
		$mismatches = '' === $unit ? array() : Mobo_Core_Order_Price_Review::compare_lines( $expected, $remote );
		if ( '' === $unit ) {
			foreach ( $expected as $key => $line ) {
				$mismatches[] = array( 'variantId' => (string) $key, 'name' => $line['name'], 'expected' => $line['expected'], 'remote' => null, 'reason' => 'source_unit_unknown' );
			}
		}
		$origins = array();
		foreach ( $expected as $key => $line ) {
			$origins[ $key ] = $line['origin'];
		}

		if ( empty( $mismatches ) ) {
			$this->append_order_log( $order, 'source_price_check_passed', array( 'unit' => $unit, 'lines' => count( $expected ), 'expectedOrigin' => $origins, 'remoteField' => $fields ) );
			return true;
		}

		$fingerprint  = Mobo_Core_Order_Price_Review::mismatch_fingerprint( $mismatches );
		$acknowledged = is_array( $context )
			&& 'admin_manual_retry' === sanitize_key( (string) ( isset( $context['trigger'] ) ? $context['trigger'] : '' ) )
			&& ! empty( $context['priceReviewAcknowledged'] )
			&& hash_equals( (string) $context['priceReviewAcknowledged'], $fingerprint );
		if ( $acknowledged ) {
			/* The administrator explicitly reviewed exactly these Source prices. */
			$this->append_order_log( $order, 'source_price_difference_accepted_by_admin', array( 'unit' => $unit, 'mismatches' => $mismatches, 'userId' => absint( isset( $context['userId'] ) ? $context['userId'] : 0 ) ) );
			$order->add_order_note( 'مدیر اختلاف قیمت Source را بررسی و تأیید کرد؛ خرید با قیمت فعلی Source ادامه یافت.' );
			return true;
		}

		return $this->mark_order_price_review( $order, $mismatches, $unit, $fingerprint, $origins, $fields );
	}

	/**
	 * Terminal-for-automation state "needs manual review: price difference".
	 * It is not "queued"/"running"/"uncertain", so the queue row is retired and
	 * the admin Retry button (with its existing safety checks) stays available.
	 *
	 * @return WP_Error
	 */
	private function mark_order_price_review( $order, $mismatches, $unit, $fingerprint, $origins = array(), $fields = array() ) {
		$message = 'نیازمند بررسی دستی: قیمت Source با قیمت همگام‌شده تفاوت دارد؛ پرداخت از کیف پول انجام نشد.';
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mobo_core_order_submission_price_review', $message );
		}
		$lines  = Mobo_Core_Order_Price_Review::describe_mismatches( $mismatches, $unit );
		$stored = array();
		foreach ( is_array( $mismatches ) ? $mismatches : array() as $row ) {
			$stored[] = array(
				'variantId' => sanitize_text_field( (string) ( isset( $row['variantId'] ) ? $row['variantId'] : '' ) ),
				'name'      => sanitize_text_field( (string) ( isset( $row['name'] ) ? $row['name'] : '' ) ),
				'expected'  => isset( $row['expected'] ) && null !== $row['expected'] ? sanitize_text_field( (string) $row['expected'] ) : null,
				'remote'    => isset( $row['remote'] ) && null !== $row['remote'] ? sanitize_text_field( (string) $row['remote'] ) : null,
				'reason'    => sanitize_key( (string) ( isset( $row['reason'] ) ? $row['reason'] : '' ) ),
			);
		}

		$order->delete_meta_data( '_mobo_order_submit_attempted' );
		$order->delete_meta_data( '_mobo_order_submit_attempted_at' );
		$order->delete_meta_data( '_mobo_order_submit_queued' );
		$order->delete_meta_data( '_mobo_order_submit_context' );
		$this->clear_order_defer_backoff_meta( $order );
		$order->update_meta_data( '_mobo_order_submit_status', Mobo_Core_Order_Price_Review::STATUS );
		$order->update_meta_data( '_mobo_order_last_error_code', 'source_price_mismatch' );
		$order->update_meta_data( '_mobo_order_last_error', $message );
		$order->update_meta_data( '_mobo_order_price_review_at', time() );
		$order->update_meta_data( '_mobo_order_price_review_unit', $unit );
		$order->update_meta_data( '_mobo_order_price_review_items', $stored );
		$order->update_meta_data( '_mobo_order_price_review_fingerprint', $fingerprint );
		$order->save();

		$order->add_order_note(
			"خرید خودکار موبو متوقف شد: قیمت Source برای اقلام زیر با قیمت مورد انتظار افزونه تفاوت دارد. پرداخت از کیف پول انجام نشد و سفارشی در موبو ثبت نشد (سبد Source دست‌نخورده باقی ماند).\n"
			. implode( "\n", $lines )
			. "\nپس از بررسی، از کادر «ثبت سفارش در موبو» گزینه ارسال مجدد را بزنید تا خرید با همین قیمت‌های Source انجام شود."
		);
		$this->append_order_log( $order, 'order_submission_price_review', array( 'unit' => $unit, 'mismatches' => $mismatches, 'expectedOrigin' => $origins, 'remoteField' => $fields ) );

		Mobo_Core_Order_Price_Review::register_order( absint( $order->get_id() ) );
		$mailed = Mobo_Core_Order_Price_Review::send_admin_email_once( $order, $mismatches, $unit );
		$this->append_order_log( $order, 'order_price_review_admin_email', array( 'sent' => $mailed ) );

		return new WP_Error( 'mobo_core_order_submission_price_review', $message, array( 'reason' => 'source_price_mismatch' ) );
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
		$this->clear_order_defer_backoff_meta( $order );
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
		$this->clear_order_defer_backoff_meta( $order );
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
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $request_method ) {
			wp_die( esc_html__( 'این عملیات فقط با درخواست امن POST مجاز است.', 'mobo-core' ), '', array( 'response' => 405 ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		/* A manual Retry can spend the merchant's Source wallet: require the store
		 * management capability (shop managers have it) in addition to the per-order
		 * edit capability, and keep the per-order nonce. */
		if ( $order_id <= 0 || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order_id ) ) {
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

		if ( $this->clone_guard_blocks_purchase() ) {
			$order->add_order_note( 'ارسال دستی به موبو انجام نشد. ' . Mobo_Core_Clone_Guard::blocked_message() );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		$attempt_state    = $this->get_order_submission_attempt_state( $order );
		$submit_status    = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		$verified_absent  = isset( $_POST['verified_absent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['verified_absent'] ) );
		$currently_queued = 'queued' === $submit_status || $this->is_order_id_in_option_queue( $order_id );
		$pending_remote_id = absint( $order->get_meta( '_mobo_order_pending_remote_id', true ) );
		$pending_token = $this->parse_mobo_cart_token( $order->get_meta( '_mobo_order_pending_token', true ) );

		if ( 'running_recent' === $attempt_state || $currently_queued ) {
			$order->add_order_note( 'ارسال مجدد به موبو انجام نشد، چون تلاش قبلی هنوز در حال اجرا یا در صف است.' );
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
		if ( ! $this->order_scope_is_submittable( $scope ) ) {
			$this->mark_order_scope_unsubmittable( $order, $scope, 'admin_manual_retry' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		$connection_block_reason = $this->get_order_submission_connection_block_reason( $scope );
		if ( '' !== $connection_block_reason ) {
			$order->add_order_note( 'ارسال دستی به موبو انجام نشد: ' . $connection_block_reason );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
			exit;
		}

		if ( in_array( $attempt_state, array( 'running_stale', 'uncertain' ), true ) ) {
			if ( $pending_remote_id > 0 && '' !== $pending_token && class_exists( 'Mobo_Core_Connection_Context' ) ) {
				$connection_id = absint( isset( $scope['connectionId'] ) ? $scope['connectionId'] : 0 );
				$reconciled = $connection_id > 0 ? Mobo_Core_Connection_Context::run( $connection_id, function () use ( $order ) { return $this->reconcile_uncertain_paid_order_with_cart_lock( $order ); } ) : new WP_Error( 'mobo_core_order_connection_context_missing', 'Order Source context is unavailable.' );
				if ( true === $reconciled ) {
					$order = wc_get_order( $order_id );
					if ( $order instanceof WC_Order ) { $order->add_order_note( 'وضعیت مبهم با استعلام مثبت از موبو بازیابی شد؛ سفارش قبلی پرداخت‌شده بود و Retry انجام نشد.' ); }
					wp_safe_redirect( wp_get_referer() ? wp_get_referer() : ( $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' ) ) );
					exit;
				}
				$order->add_order_note( 'Retry مسدود شد: یک Mobo Order ID durable برای تلاش قبلی وجود دارد و paid=true هنوز به‌صورت مثبت اثبات نشده است. تأیید دستیِ «عدم وجود سفارش» نمی‌تواند این evidence را override کند.' );
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
				exit;
			}
			if ( ! $verified_absent ) {
				$order->add_order_note( 'Retry این سفارش مسدود شد: نتیجه تلاش قبلی قطعی نیست و هیچ remote identity قابل reconciliation موجود نیست. ابتدا عدم وجود سفارش/پرداخت در موبو را بررسی و سپس Retry تأییدشده را استفاده کنید.' );
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $order->get_edit_order_url() );
				exit;
			}
		}

		/* Retrying a price-review order is the administrator's explicit acceptance of
		 * exactly the Source prices that were reviewed. If the Source price changed
		 * again since then, the guard stops once more with a fresh review. */
		$retry_context = array( 'trigger' => 'admin_manual_retry', 'userId' => get_current_user_id(), 'verifiedAbsentInMobo' => $verified_absent );
		if ( 'price_review' === $submit_status ) {
			$retry_context['priceReviewAcknowledged'] = sanitize_text_field( (string) $order->get_meta( '_mobo_order_price_review_fingerprint', true ) );
		}

		$this->append_order_log(
			$order,
			'admin_manual_retry_requested',
			array(
				'userId'                 => get_current_user_id(),
				'previousAttemptState'   => $attempt_state,
				'verifiedAbsentInMobo'   => $verified_absent,
				'priceReviewAcknowledged' => 'price_review' === $submit_status,
			)
		);
		$result = $this->submit_order_to_mobo( $order, $retry_context );

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
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $request_method ) {
			wp_die( esc_html__( 'این عملیات فقط با درخواست امن POST مجاز است.', 'mobo-core' ), '', array( 'response' => 405 ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
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

	/** Return an operator-facing reason when the order owner's Connection cannot mutate runtime state. */
	private function get_order_submission_connection_block_reason( $scope ) {
		$connection_id = is_array( $scope ) && isset( $scope['connectionId'] ) ? absint( $scope['connectionId'] ) : 0;
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return 'اتصال مالک این سفارش قابل تشخیص نیست؛ ارسال به موبو متوقف است.';
		}
		$connection = Mobo_Core_Connection_Repository::get_by_id( $connection_id );
		if ( ! is_array( $connection ) ) {
			return 'اتصال مالک این سفارش دیگر در دسترس نیست؛ ارسال به موبو متوقف است.';
		}
		if ( empty( $connection['is_enabled'] ) ) {
			return 'اتصال مالک این سفارش غیرفعال است. ابتدا وضعیت Connection را بررسی کنید.';
		}
		if ( ! Mobo_Core_Connection_Repository::is_provisioned( $connection ) ) {
			return 'اتصال مالک این سفارش هنوز Provision نشده است. ابتدا صفحه «اتصال و لایسنس» را تکمیل/بررسی کنید.';
		}
		if ( ! class_exists( 'Mobo_Core_Money_Policy' ) ) {
			return 'سیاست واحد پول موبو در دسترس نیست؛ ارسال سفارش Fail-Closed متوقف شده است.';
		}
		$money_status = Mobo_Core_Money_Policy::compatibility_status( $connection_id, true );
		if ( empty( $money_status['compatible'] ) ) {
			$source_unit = sanitize_text_field( (string) ( $money_status['sourceUnit'] ?? '' ) );
			$store_unit = sanitize_text_field( (string) ( $money_status['storeUnit'] ?? '' ) );
			if ( '' !== $source_unit && '' !== $store_unit ) {
				return sprintf( 'واحد پول Source (%s) با واحد پول WooCommerce (%s) سازگار نیست؛ ارسال سفارش متوقف است.', $source_unit, $store_unit );
			}
			return 'قرارداد واحد پول این Connection ناقص است؛ Backend باید Connection را مجدداً Provision کند.';
		}
		static $multi_runtime_ready = null;
		if ( null === $multi_runtime_ready ) {
			$multi_runtime_ready = Mobo_Core_Connection_Repository::multi_connection_runtime_ready();
		}
		if ( ! $multi_runtime_ready ) {
			return 'مالکیت Multi-Connection هنوز برای runtime آماده نیست؛ ارسال سفارش Fail-Closed باقی می‌ماند.';
		}
		return '';
	}

	/**
	 * Build one authoritative administrator-facing state for Order List and Order Detail.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function get_order_admin_submission_state( $order ) {
		$state = array(
			'label'                  => '—',
			'message'                => '',
			'tone'                   => 'neutral',
			'canRetry'               => false,
			'requiresVerifiedAbsent' => false,
			'retryLabel'             => 'ارسال به موبو',
			'scope'                  => array(),
		);
		if ( ! $order instanceof WC_Order ) {
			return $state;
		}

		$requires_review       = 'yes' === (string) $order->get_meta( '_mobo_order_requires_review', true );
		$post_payment_diverged = 'yes' === (string) $order->get_meta( '_mobo_order_post_payment_diverged', true );
		$submitted             = 'yes' === (string) $order->get_meta( '_mobo_order_submitted', true );
		$mobo_order_id         = absint( $order->get_meta( '_mobo_order_id', true ) );

		/* Submitted orders do not need a fresh catalogue/scope walk merely to render
		 * the order list. Their durable remote-success/review evidence is authoritative. */
		if ( $submitted || $mobo_order_id > 0 ) {
			if ( $requires_review || $post_payment_diverged ) {
				$state['label']   = 'ثبت شد؛ نیازمند بررسی';
				$state['tone']    = 'warning';
				$state['message'] = $post_payment_diverged
					? 'سفارش/پرداخت در موبو ثبت شده است، اما وضعیت تجاری WooCommerce پس از مرز پرداخت تغییر کرده یا قابل تأیید نبوده است. Retry نکنید؛ اقلام، تعداد، آدرس و روش ارسال را با سفارش موبو تطبیق دهید.'
					: 'سفارش در موبو ثبت شده است اما یک وضعیت پس از ثبت نیازمند بررسی مدیر است. Retry نکنید و جزئیات فنی/یادداشت‌های سفارش را بررسی کنید.';
			} else {
				$state['label']   = $mobo_order_id > 0 ? 'ثبت شد #' . $mobo_order_id : 'ثبت شد';
				$state['tone']    = 'success';
				$state['message'] = 'این سفارش در موبو ثبت شده است و ارسال مجدد مجاز نیست.';
			}
			return $state;
		}

		$scope                 = $this->get_order_mobo_item_scope( $order );
		$scope_status          = sanitize_key( (string) ( isset( $scope['status'] ) ? $scope['status'] : 'invalid' ) );
		$submit_status         = sanitize_key( (string) $order->get_meta( '_mobo_order_submit_status', true ) );
		$attempt_state         = $this->get_order_submission_attempt_state( $order );
		$currently_queued      = 'queued' === $submit_status || $this->is_order_id_in_option_queue( $order->get_id() );
		$last_error            = sanitize_text_field( (string) $order->get_meta( '_mobo_order_last_error', true ) );
		$state['scope']        = $scope;

		if ( 'invalid' === $scope_status ) {
			$state['label']   = 'هویت نامشخص؛ نیازمند بررسی';
			$state['tone']    = 'error';
			$state['message'] = $this->build_unsubmittable_order_message( $scope );
			return $state;
		}
		if ( ! $this->order_scope_has_mobo_items( $scope ) ) {
			$state['label']   = 'مربوط به موبو نیست';
			$state['message'] = 'این سفارش آیتم موبوی قابل ارسال ندارد و هیچ عملیات ارسال به موبو برای آن لازم نیست.';
			return $state;
		}
		if ( ! $this->order_scope_is_submittable( $scope ) ) {
			$state['label']   = 'multi_connection' === $scope_status ? 'چند اتصال؛ ارسال نمی‌شود' : ( 'mixed' === $scope_status ? 'ترکیبی؛ ارسال نمی‌شود' : 'ارسال مسدود است' );
			$state['tone']    = in_array( $scope_status, array( 'multi_connection', 'invalid' ), true ) ? 'error' : 'warning';
			$state['message'] = $this->build_unsubmittable_order_message( $scope );
			return $state;
		}

		if ( $this->clone_guard_blocks_purchase() ) {
			$state['label']   = 'سایت کپی/staging؛ خرید متوقف';
			$state['tone']    = 'error';
			$state['message'] = Mobo_Core_Clone_Guard::blocked_message();
			return $state;
		}

		$connection_block_reason = $this->get_order_submission_connection_block_reason( $scope );
		if ( '' !== $connection_block_reason ) {
			$state['label']   = 'اتصال مالک آماده نیست';
			$state['tone']    = 'error';
			$state['message'] = $connection_block_reason;
			return $state;
		}

		if ( 'blocked_clone' === $submit_status && 'processing' === sanitize_key( (string) $order->get_status() ) && ! in_array( $attempt_state, array( 'running_recent', 'running_stale', 'uncertain' ), true ) ) {
			$state['label']      = 'نگه‌داشته‌شده (سایت کپی)؛ نیازمند بررسی';
			$state['tone']       = 'warning';
			$state['message']    = 'این سفارش زمانی پرداخت شد که سایت نسخه کپی یا دامنه آن تغییرکرده تشخیص داده شده بود و خودکار ارسال نشد. پیش از ارسال دستی بررسی کنید که این سفارش قبلاً از سایت اصلی در موبو ثبت نشده باشد.';
			$state['canRetry']   = true;
			$state['retryLabel'] = 'بررسی کردم؛ ارسال دستی به موبو';
			return $state;
		}

		if ( 'processing' !== sanitize_key( (string) $order->get_status() ) ) {
			$state['label']   = 'خارج از وضعیت ارسال';
			$state['message'] = 'ارسال سفارش موبو فقط زمانی مجاز است که سفارش WooCommerce در وضعیت «در حال انجام (processing)» باشد.';
			return $state;
		}

		if ( 'running_recent' === $attempt_state ) {
			$state['label']   = 'در حال ارسال';
			$state['tone']    = 'info';
			$state['message'] = 'یک تلاش ارسال هنوز در حال اجرا است. برای جلوگیری از سفارش تکراری، Retry در دسترس نیست.';
			return $state;
		}
		if ( $currently_queued ) {
			$state['label']   = 'در صف ارسال';
			$state['tone']    = 'info';
			$state['message'] = 'سفارش در صف پردازش موبو قرار دارد. تا تعیین نتیجه صف، Retry در دسترس نیست.';
			$next_attempt_at  = absint( $order->get_meta( '_mobo_order_submit_next_attempt_at', true ) );
			if ( $next_attempt_at > time() ) {
				$state['label']    = 'در صف؛ تلاش مجدد خودکار';
				$state['message'] .= sprintf(
					' ارسال موقتاً به تعویق افتاده است (%s، تلاش %s): %s تلاش بعدی حدود %s؛ سفارش‌های دیگر منتظر این سفارش نمی‌مانند.',
					sanitize_key( (string) $order->get_meta( '_mobo_order_submit_defer_reason', true ) ),
					absint( $order->get_meta( '_mobo_order_submit_defer_count', true ) ),
					$last_error,
					function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $next_attempt_at ) : gmdate( 'Y-m-d H:i', $next_attempt_at ) . ' UTC'
				);
			}
			return $state;
		}
		if ( in_array( $attempt_state, array( 'running_stale', 'uncertain' ), true ) ) {
			$state['label']                  = 'نتیجه نامشخص؛ بررسی در موبو';
			$state['tone']                   = 'error';
			$state['message']                = 'ممکن است تلاش قبلی در موبو سفارش یا پرداخت ایجاد کرده باشد. قبل از هر Retry، وجود سفارش/پرداخت را در پنل موبو بررسی کنید.';
			$state['canRetry']               = true;
			$state['requiresVerifiedAbsent'] = true;
			$state['retryLabel']             = 'تأیید عدم ثبت در موبو و ارسال مجدد';
			return $state;
		}

		if ( 'failed' === $submit_status ) {
			$state['label']      = 'ارسال ناموفق';
			$state['tone']       = 'error';
			$state['message']    = '' !== $last_error ? 'آخرین تلاش ناموفق بود: ' . $last_error : 'آخرین تلاش ارسال ناموفق بود. جزئیات فنی را بررسی کنید و در صورت رفع علت Retry کنید.';
			$state['canRetry']   = true;
			$state['retryLabel'] = 'ارسال مجدد به موبو';
			return $state;
		}
		if ( 'price_review' === $submit_status ) {
			$review_items = $order->get_meta( '_mobo_order_price_review_items', true );
			$review_lines = class_exists( 'Mobo_Core_Order_Price_Review' )
				? Mobo_Core_Order_Price_Review::describe_mismatches( is_array( $review_items ) ? $review_items : array(), (string) $order->get_meta( '_mobo_order_price_review_unit', true ) )
				: array();
			$state['label']       = 'نیازمند بررسی: اختلاف قیمت';
			$state['tone']        = 'warning';
			$state['message']     = 'قیمت Source با قیمت مورد انتظار افزونه تفاوت داشت؛ خرید خودکار و پرداخت از کیف پول انجام نشد. ' . implode( ' ', $review_lines );
			$state['canRetry']    = true;
			$state['retryLabel']  = 'بررسی کردم؛ خرید با قیمت فعلی Source';
			$state['confirmText'] = 'قیمت‌های فعلی Source را بررسی کرده‌اید و خرید از کیف پول موبو با همین قیمت‌ها انجام شود؟ اگر قیمت Source دوباره تغییر کرده باشد، خرید مجدداً برای بررسی متوقف می‌شود.';
			return $state;
		}
		if ( 'aborted' === $submit_status ) {
			$state['label']      = 'ارسال قبل از پرداخت متوقف شد';
			$state['tone']       = 'warning';
			$state['message']    = '' !== $last_error ? $last_error : 'تلاش قبلی قبل از Wallet Payment متوقف شد. اگر علت برطرف شده است، ارسال مجدد مجاز است.';
			$state['canRetry']   = true;
			$state['retryLabel'] = 'ارسال مجدد به موبو';
			return $state;
		}

		$state['label']      = 'آماده ارسال';
		$state['message']    = 'این سفارش تماماً موبو، متعلق به یک Connection و در وضعیت processing است. ارسال دستی در صورت نیاز مجاز است.';
		$state['canRetry']   = true;
		$state['retryLabel'] = 'ارسال به موبو';
		return $state;
	}

	/** Render a POST-only submission action after the same fail-closed state classification used by the list. */
	private function render_admin_order_action_form( $order_id, $state ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 || ! is_array( $state ) || empty( $state['canRetry'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$verified_absent = ! empty( $state['requiresVerifiedAbsent'] );
		$confirm = $verified_absent
			? 'تأیید می‌کنم در موبو بررسی کرده‌ام و این سفارش/پرداخت ایجاد نشده است. ارسال مجدد انجام شود؟'
			: ( ! empty( $state['confirmText'] ) ? (string) $state['confirmText'] : 'ارسال این سفارش به موبو انجام شود؟' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
		echo '<input type="hidden" name="action" value="mobo_core_retry_mobo_order_submission">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '">';
		if ( $verified_absent ) {
			echo '<input type="hidden" name="verified_absent" value="1">';
		}
		wp_nonce_field( 'mobo_core_retry_mobo_order_submission_' . $order_id );
		echo '<button type="submit" class="button button-primary" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">' . esc_html( (string) $state['retryLabel'] ) . '</button>';
		echo '</form>';
	}

	public function render_order_meta_box( $object ) {
		$order = $this->resolve_admin_order_object( $object );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>سفارش پیدا نشد.</p>';
			return;
		}

		$order_id    = $order->get_id();
		$state       = $this->get_order_admin_submission_state( $order );
		$log         = $order->get_meta( '_mobo_order_submission_log', true );
		$log_array   = is_array( $log ) ? $log : array();
		$copy        = wp_json_encode( $log_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$last_entry  = ! empty( $log_array ) ? end( $log_array ) : array();
		$last_action = is_array( $last_entry ) && isset( $last_entry['action'] ) ? (string) $last_entry['action'] : '—';
		$last_http   = '—';
		if ( is_array( $last_entry ) && isset( $last_entry['context'] ) && is_array( $last_entry['context'] ) && isset( $last_entry['context']['httpstatus'] ) ) {
			$last_http = (string) $last_entry['context']['httpstatus'];
		}

		echo '<div id="mobo-order-admin-state">';
		echo '<p><strong>وضعیت موبو:</strong> ' . esc_html( (string) $state['label'] ) . '</p>';
		if ( '' !== (string) $state['message'] ) {
			echo '<p>' . esc_html( (string) $state['message'] ) . '</p>';
		}
		if ( ! empty( $state['requiresVerifiedAbsent'] ) ) {
			echo '<p><strong>اقدام لازم:</strong> قبل از Retry، سفارش/پرداخت را در پنل موبو جستجو کنید. Retry بدون این بررسی می‌تواند سفارش تکراری ایجاد کند.</p>';
		}
		$this->render_admin_order_action_form( $order_id, $state );

		echo '<details style="margin-top:12px;">';
		echo '<summary style="cursor:pointer;font-weight:600;">جزئیات فنی</summary>';
		echo '<div style="margin-top:10px;">';
		echo '<p><strong>Mobo Order ID:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_id', true ) ) . '</p>';
		$stored_token = (string) $order->get_meta( '_mobo_order_token', true );
		$masked_token = '' === $stored_token ? '—' : ( strlen( $stored_token ) <= 6 ? '••••••' : '••••••' . substr( $stored_token, -6 ) );
		echo '<p><strong>توکن سفارش (ماسک‌شده):</strong> ' . esc_html( $masked_token ) . '</p>';
		echo '<p><strong>کد وضعیت/خطا:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_last_error_code', true ) ) . '</p>';
		echo '<p><strong>آخرین پیام:</strong> ' . esc_html( (string) $order->get_meta( '_mobo_order_last_error', true ) ) . '</p>';
		echo '<p><strong>آخرین مرحله لاگ:</strong> ' . esc_html( $last_action ) . ' / HTTP: ' . esc_html( $last_http ) . '</p>';
		if ( ! empty( $log_array ) ) {
			echo '<textarea readonly onclick="this.select();" style="width:100%;min-height:220px;font-family:monospace;font-size:11px;direction:ltr;">' . esc_textarea( $copy ) . '</textarea>';
			echo '<p class="description">این لاگ برای پشتیبانی است. فیلدهای حساس شناخته‌شده ماسک می‌شوند و body غیر JSON به‌صورت خام ذخیره نمی‌شود. پاک‌کردن لاگ، وضعیت ارسال/Retry guard یا شناسه سفارش موبو را تغییر نمی‌دهد.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
			echo '<input type="hidden" name="action" value="mobo_core_clear_mobo_order_log">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '">';
			wp_nonce_field( 'mobo_core_clear_mobo_order_log_' . $order_id );
			echo '<button type="submit" class="button" onclick="return confirm(&quot;فقط لاگ فنی این سفارش پاک شود؟ وضعیت ارسال و guardهای ایمنی حفظ می‌شوند.&quot;);">پاک کردن لاگ فنی</button>';
			echo '</form>';
		} else {
			echo '<p class="description">برای این سفارش هنوز لاگ فنی موبو ثبت نشده است.</p>';
		}
		echo '</div></details></div>';
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
		if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
			/* This probe also gates administrator manual Retry. SQL/cutover failure
			 * means UNKNOWN, never 'not queued' (which could allow another payment). */
			if ( ! $this->primary_order_row_store_ready() ) { return true; }
			$read = Mobo_Core_Work_Queue_Store::read_one_checked( self::ORDER_QUEUE_TYPE, (string) $order_id );
			return 'missing' !== $read['status'];
		}
		$queue = $this->get_option_backed_order_queue();
		return isset( $queue[ (string) $order_id ] );
	}

	private function get_order_mobo_submission_label( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '—';
		}
		$state = $this->get_order_admin_submission_state( $order );
		return isset( $state['label'] ) ? sanitize_text_field( (string) $state['label'] ) : '—';
	}


}
