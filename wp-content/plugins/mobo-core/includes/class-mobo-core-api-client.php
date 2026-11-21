<?php
/**
 * API client for manual chunked sync.
 *
 * Expected API endpoints:
 *
 * GET /get-categories?SyncId=...
 * GET /get-products?OnlyInStock=true&RemVariants=true&SyncId=...&PageNumber=1&RecordPerPage=2
 * GET /{productGuid}/get-variants?SyncId=...&PageNumber=1&RecordPerPage=5
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_API_Client {

	/**
	 * Per-request runtime HTTP deadline supplied by the real cron runner.
	 * Manual/admin requests keep their configured timeout unchanged.
	 *
	 * @var float
	 */
	private static $runtime_deadline = 0.0;

	/**
	 * Seconds reserved after a blocking API call so the runner can checkpoint.
	 *
	 * @var float
	 */
	private static $runtime_deadline_reserve = 1.0;

	/**
	 * Short request-local circuit breaker after transport/upstream failures.
	 *
	 * @var float
	 */
	private static $circuit_open_until = 0.0;

	/**
	 * Last request-local circuit error.
	 *
	 * @var WP_Error|null
	 */
	private static $circuit_error = null;

	/**
	 * Request-local cache for immutable/lightweight payload URLs.
	 *
	 * @var array
	 */
	private static $payload_cache = array();

	/**
	 * Request-local cached API connection context.
	 *
	 * @var array|null
	 */
	private static $request_context = null;

	/**
	 * Bound Portal API requests to the current runner deadline.
	 *
	 * @param float $deadline Absolute microtime deadline.
	 * @param float $reserve Seconds kept for checkpoint/finalization.
	 * @return void
	 */
	public static function set_runtime_deadline( $deadline, $reserve = 1.0 ) {
		self::$runtime_deadline         = max( 0.0, (float) $deadline );
		self::$runtime_deadline_reserve = max( 0.25, min( 5.0, (float) $reserve ) );
	}

	/**
	 * Clear the runner-specific HTTP deadline.
	 *
	 * @return void
	 */
	public static function clear_runtime_deadline() {
		self::$runtime_deadline = 0.0;
	}

	/** Clear cached connection credentials when request-local connection changes. */
	public static function clear_request_context() {
		self::$request_context = null;
		self::$payload_cache   = array();
		self::$circuit_open_until = 0.0;
		self::$circuit_error = null;
	}


	/**
	 * Get categories from API.
	 *
	 * Expected payload:
	 * [
	 *   {
	 *     "id": "...",
	 *     "title": "...",
	 *     "url": "/products/case",
	 *     "parentId": null
	 *   }
	 * ]
	 *
	 * @param string $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	public function get_categories( $sync_id ) {
		$path = add_query_arg(
			array(
				'SyncId' => sanitize_text_field( (string) $sync_id ),
			),
			'get-categories'
		);

		return $this->get_json( $path );
	}

	/**
	 * Get products page.
	 *
	 * Expected API:
	 * /get-products?OnlyInStock=true&RemVariants=true&SyncId=...&PageNumber=1&RecordPerPage=2
	 *
	 * @param int    $page_number Page number.
	 * @param int    $record_per_page Records per page.
	 * @param string $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	public function get_products_page( $page_number, $record_per_page, $sync_id, $cursor = 0, $use_cursor = false, $only_in_stock_override = null ) {
		if ( null === $only_in_stock_override ) {
			$only_in_stock = Mobo_Core_Settings::enabled( 'mobo_core_only_in_stock', '0' ) ? 'true' : 'false';
		} else {
			$only_in_stock = $only_in_stock_override ? 'true' : 'false';
		}

		$args = array(
			'OnlyInStock'   => $only_in_stock,
			'RemVariants'   => 'true',
			'SyncId'        => sanitize_text_field( (string) $sync_id ),
			'PageNumber'    => max( 1, absint( $page_number ) ),
			'RecordPerPage' => max( 1, absint( $record_per_page ) ),
		);

		if ( $use_cursor ) {
			$args['UseCursor'] = 'true';
			$args['Cursor']    = max( 0, absint( $cursor ) );
		}

		$path = add_query_arg( $args, 'get-products' );

		return $this->get_json( $path );
	}


	/**
	 * Get revision-based product changes for adaptive reconciliation.
	 *
	 * Preferred endpoint: /sync/changes?afterRevision=...&limit=...
	 * Compatibility endpoint: /api/sync/changes?afterRevision=...&limit=...
	 *
	 * @param int $after_revision Last applied revision.
	 * @param int $limit Maximum changes.
	 * @return array|WP_Error
	 */
	public function get_sync_changes( $after_revision, $limit ) {
		$args = array(
			'afterRevision' => max( 0, absint( $after_revision ) ),
			'limit'         => max( 1, min( 500, absint( $limit ) ) ),
		);

		$preference  = sanitize_key( (string) Mobo_Core_Settings::get( 'mobo_core_sync_changes_endpoint_preference', '' ) );
		$retry_after = absint( Mobo_Core_Settings::get( 'mobo_core_sync_changes_endpoint_retry_after', 0 ) );
		$base_url    = $this->get_base_url();
		$fingerprint = '' !== $base_url ? md5( strtolower( $base_url ) ) : '';
		$known_base  = sanitize_text_field( (string) Mobo_Core_Settings::get( 'mobo_core_sync_changes_endpoint_base_fingerprint', '' ) );

		if ( '' !== $fingerprint && '' !== $known_base && ! hash_equals( $known_base, $fingerprint ) ) {
			$preference  = '';
			$retry_after = 0;
			Mobo_Core_Settings::delete( 'mobo_core_sync_changes_endpoint_preference' );
			Mobo_Core_Settings::delete( 'mobo_core_sync_changes_endpoint_retry_after' );
		}

		if ( '' !== $fingerprint && $fingerprint !== $known_base ) {
			Mobo_Core_Settings::update( 'mobo_core_sync_changes_endpoint_base_fingerprint', $fingerprint, false );
		}

		if ( 'unsupported' === $preference && $retry_after > time() ) {
			return new WP_Error(
				'mobo_core_sync_changes_endpoint_unavailable_cached',
				'Revision endpoint capability is temporarily cached as unavailable.',
				array( 'retry_after' => $retry_after )
			);
		}

		$primary = add_query_arg( $args, 'sync/changes' );
		$compat  = add_query_arg( $args, 'api/sync/changes' );

		if ( 'compat' === $preference ) {
			$response = $this->get_json( $compat );
			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! $this->is_missing_endpoint_error( $response ) ) {
				return $response;
			}

			$response = $this->get_json( $primary );
			if ( ! is_wp_error( $response ) ) {
				$this->remember_sync_changes_endpoint( 'primary' );
				return $response;
			}

			$this->remember_sync_changes_unavailable_if_missing( $response );
			return $response;
		}

		$response = $this->get_json( $primary );
		if ( ! is_wp_error( $response ) ) {
			$this->remember_sync_changes_endpoint( 'primary' );
			return $response;
		}

		/* A transport/5xx failure is not endpoint discovery. Do not double the
		 * latency by probing the compatibility URL during the same outage. */
		if ( ! $this->is_missing_endpoint_error( $response ) ) {
			return $response;
		}

		$compat_response = $this->get_json( $compat );
		if ( ! is_wp_error( $compat_response ) ) {
			$this->remember_sync_changes_endpoint( 'compat' );
			return $compat_response;
		}

		$this->remember_sync_changes_unavailable_if_missing( $compat_response );
		return $compat_response;
	}


	/**
	 * Get the site-scoped recovery manifest. Portal returns only product identities
	 * with delivered ProductUpdated/UpdateVariant history for the current licensed domain.
	 * This endpoint is intentionally independent of OnlyInStock.
	 *
	 * @param int $cursor Product AutoIdentity cursor.
	 * @param int $limit Maximum identities.
	 * @return array|WP_Error
	 */
	public function get_product_recovery_manifest( $cursor = 0, $limit = 20 ) {
		$path = add_query_arg(
			array(
				'cursor' => max( 0, absint( $cursor ) ),
				'limit'  => max( 1, min( 200, absint( $limit ) ) ),
			),
			'get-product-recovery-manifest'
		);

		return $this->get_json( $path );
	}

	/**
	 * Get a single product payload by Portal numeric product ID.
	 *
	 * @param int    $portal_product_id Portal product ID.
	 * @param string $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	public function get_product_by_portal_id( $portal_product_id, $sync_id = '' ) {
		$portal_product_id = absint( $portal_product_id );
		$sync_id           = sanitize_text_field( (string) $sync_id );

		if ( $portal_product_id <= 0 ) {
			return new WP_Error( 'mobo_core_missing_portal_product_id', 'Portal product ID is missing.' );
		}

		if ( '' === $sync_id ) {
			$sync_id = 'reconcile-' . gmdate( 'YmdHis' );
		}

		$path = add_query_arg(
			array(
				'ProductPortalId' => $portal_product_id,
				'SyncId'         => $sync_id,
			),
			'get-products-by-portal-id'
		);

		return $this->get_json( $path );
	}


	/**
	 * Get a single product payload by remote product GUID.
	 *
	 * MoboCore endpoint:
	 * /get-products-by-guid?ProductId={productGuid}&SyncId=...
	 *
	 * This is used by category reapply to backfill category_guid metadata for
	 * products that were synced by older plugin versions before category refs
	 * were persisted on the WooCommerce product.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @param string $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	public function get_product_by_guid( $product_guid, $sync_id = '' ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$sync_id      = sanitize_text_field( (string) $sync_id );

		if ( '' === $product_guid ) {
			return new WP_Error( 'mobo_core_missing_product_guid', 'Product GUID is missing.' );
		}

		if ( '' === $sync_id ) {
			$sync_id = 'category-backfill-' . gmdate( 'YmdHis' );
		}

		$path = add_query_arg(
			array(
				'ProductId' => $product_guid,
				'SyncId'    => $sync_id,
			),
			'get-products-by-guid'
		);

		return $this->get_json( $path );
	}

	/**
	 * Get variants page.
	 *
	 * Expected API:
	 * /{productGuid}/get-variants?SyncId=...&PageNumber=1&RecordPerPage=5
	 *
	 * @param string $product_guid Product GUID.
	 * @param int    $page_number Page number.
	 * @param int    $record_per_page Records per page.
	 * @param string $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	public function get_variants_page( $product_guid, $page_number, $record_per_page, $sync_id, $cursor = 0, $use_cursor = false ) {
		$product_guid = rawurlencode( sanitize_text_field( (string) $product_guid ) );

		if ( '' === $product_guid ) {
			return new WP_Error( 'mobo_core_missing_product_guid', 'Product GUID is missing.' );
		}

		$args = array(
			'SyncId'        => sanitize_text_field( (string) $sync_id ),
			'PageNumber'    => max( 1, absint( $page_number ) ),
			'RecordPerPage' => max( 1, absint( $record_per_page ) ),
		);

		if ( $use_cursor ) {
			$args['UseCursor'] = 'true';
			$args['Cursor']    = max( 0, absint( $cursor ) );
		}

		$path = add_query_arg( $args, $product_guid . '/get-variants' );

		return $this->get_json( $path );
	}


	/**
	 * Get the lightweight authoritative Variant count for one product.
	 *
	 * Portal exposes this independently from the heavier get-variants snapshot.
	 * Callers must treat the count as an optimization hint only; destructive
	 * topology decisions still require the full authoritative Variant payload.
	 *
	 * @param string $product_guid Product GUID.
	 * @return int|WP_Error
	 */
	public function get_variants_count( $product_guid ) {
		$product_guid = rawurlencode( sanitize_text_field( (string) $product_guid ) );

		if ( '' === $product_guid ) {
			return new WP_Error( 'mobo_core_missing_product_guid', 'Product GUID is missing.' );
		}

		$value = $this->get_json_value( $product_guid . '/get-variants-count' );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) {
			return absint( $value );
		}

		return new WP_Error( 'mobo_core_invalid_variant_count', 'Variant count endpoint returned an invalid value.' );
	}


	/**
	 * Pull a lightweight webhook payload from MoboCore.
	 *
	 * The URL may be absolute, root-relative, or relative to the configured
	 * API base URL. The customer site's X-SEC value is sent so MoboCore can
	 * authorize the payload request.
	 *
	 * @param string $payload_url Payload URL from lightweight notification.
	 * @return array|WP_Error
	 */
	public function get_event_payload( $payload_url, $timeout = null ) {
		$payload_url = trim( (string) $payload_url );

		if ( '' === $payload_url ) {
			return new WP_Error( 'mobo_core_missing_payload_url', 'Payload URL is missing.' );
		}

		$url = $this->normalize_payload_url( $payload_url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		$cache_key = md5( $connection_id . '|' . $url );
		if ( isset( self::$payload_cache[ $cache_key ] ) && is_array( self::$payload_cache[ $cache_key ] ) ) {
			return self::$payload_cache[ $cache_key ];
		}

		$configured_timeout = null === $timeout
			? Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 )
			: max( 2, min( 180, absint( $timeout ) ) );
		$response = $this->get_json_url( $url, $configured_timeout );

		if ( ! is_wp_error( $response ) && is_array( $response ) ) {
			self::$payload_cache[ $cache_key ] = $response;
		}

		return $response;
	}


	/**
	 * Get cached address mapping from MoboCore.
	 *
	 * MoboCore returns countries/states/cities with Mobo numeric IDs. Customer sites
	 * cache this locally and use it for checkout address selects.
	 *
	 * Expected endpoint:
	 * /get-address-mapping
	 *
	 * Portal force-refresh is intentionally not exposed through the customer API.
	 *
	 * @return array|WP_Error
	 */
	public function get_address_mapping() {
		return $this->get_json( 'get-address-mapping' );
	}

	/**
	 * Resolve a native WooCommerce address through the central provider-level resolver.
	 * The backend resolves only from its cached provider catalog; this request never
	 * calls the Source provider directly.
	 *
	 * @param array $location Native/canonical location fields.
	 * @return array|WP_Error
	 */
	public function resolve_location( $location ) {
		return $this->post_json( 'resolve-location', is_array( $location ) ? $location : array() );
	}

	/**
	 * Get cached Mobo shipping methods from MoboCore.
	 *
	 * Expected endpoint:
	 * /get-mobo-shipping-methods
	 *
	 * Portal force-refresh is intentionally not exposed through the customer API.
	 *
	 * @return array|WP_Error
	 */
	public function get_mobo_shipping_methods() {
		return $this->get_json( 'get-mobo-shipping-methods' );
	}


	/**
	 * Discover whether this licensed Backend supplies a live, authenticated,
	 * side-effect-free purchase quote. This is INFORMATIONAL only: checkout must
	 * never treat presence of this endpoint as proof that a line is eligible.
	 * CP3 deliberately has no purchase-quote consumer or automatic enabling.
	 *
	 * @return array|WP_Error A validated explicitly-disabled capability.
	 */
	public function get_purchase_eligibility_capability() {
		$response = $this->get_json( 'get-purchase-eligibility-capability' );
		if ( is_wp_error( $response ) ) { return $response; }
		return self::validate_purchase_eligibility_capability_response( $response );
	}

	/** Isolated response-shape preflight; it grants NO purchasing capability. */
	public static function validate_purchase_eligibility_capability_response( $response ) {
		// Version 1 is an exact, unsupported-only envelope. Never forward
		// unknown positive evidence (e.g. trusted/available/quote) to a future
		// caller even when purchase_allowed happens to be false.
		$allowed_fields = array( 'protocol_version', 'quote_supported', 'provider_read_only_quote', 'purchase_allowed', 'reason_code' );
		if ( ! is_array( $response ) || count( $response ) !== count( $allowed_fields )
			|| array_diff( array_keys( $response ), $allowed_fields )
			|| ! isset( $response['protocol_version'], $response['reason_code'] )
			|| ! is_int( $response['protocol_version'] ) || 1 !== $response['protocol_version']
			|| ! is_string( $response['reason_code'] )
			|| 'provider_authoritative_quote_not_implemented' !== $response['reason_code']
			|| ! array_key_exists( 'quote_supported', $response ) || false !== $response['quote_supported']
			|| ! array_key_exists( 'provider_read_only_quote', $response ) || false !== $response['provider_read_only_quote']
			|| ! array_key_exists( 'purchase_allowed', $response ) || false !== $response['purchase_allowed'] ) {
			return new WP_Error( 'mobo_core_purchase_capability_unrecognized', 'Purchase-quote capability is unrecognized; automatic purchase remains blocked.' );
		}
		return $response;
	}

	/**
	 * CP4: Preflight for a future live purchase quote. A quote is NOT a purchase.
	 * This build intentionally never POSTs a quote: the real Portal.ir adapter
	 * is unsupported, and remote cart rebuild is not a read-only quote.
	 * This method must not be connected as an ELIGIBLE checkout decision.
	 */
	public function get_purchase_eligibility_quote( $request ) {
		$preflight = self::validate_purchase_eligibility_quote_request( $request );
		if ( is_wp_error( $preflight ) ) { return $preflight; }
		// The connection must be a fresh, explicit durable owner; never fall
		// back to the default/active Connection. An absent repository must fail
		// before any capability transport or Provider access.
		// CP19: even an incompletely bootstrapped WordPress instance must
		// fail BEFORE capability transport. No implicit absent-repository
		// exception to the explicit durable owner requirement.
		$owner = self::validate_purchase_eligibility_quote_local_owner( $preflight );
		if ( is_wp_error( $owner ) ) { return $owner; }
		$capability = $this->get_purchase_eligibility_capability();
		if ( is_wp_error( $capability ) ) { return $capability; }
		return new WP_Error( 'mobo_core_purchase_quote_unsupported', 'Provider has no approved authoritative live purchase quote; checkout purchase is not authorized.' );
	}

	/**
	 * Local pre-transport owner guard. This does not establish server-side
	 * license/source/product/variant entitlement; Backend separately must
	 * authorize those links. Quote support is still disabled in this build.
	 */
	public static function validate_purchase_eligibility_quote_local_owner( $request ) {
		if ( ! is_array( $request ) || ! isset( $request['connection_id'] )
			|| ! is_string( $request['connection_id'] )
			|| ! preg_match( '/^[1-9][0-9]{0,17}$/D', $request['connection_id'] )
			|| ! class_exists( 'Mobo_Core_Connection_Repository' )
			|| ! method_exists( 'Mobo_Core_Connection_Repository', 'get_by_id_fresh' ) ) {
			return new WP_Error( 'mobo_core_quote_owner_unverified', 'Quote requires a verified explicit Connection owner.' );
		}
		$id = (int) $request['connection_id'];
		if ( $id <= 0 || (string) $id !== $request['connection_id'] ) {
			return new WP_Error( 'mobo_core_quote_owner_unverified', 'Quote Connection ID is invalid.' );
		}
		// The API transport must already be explicitly scoped to this same
		// owner. A default/effective Connection is never a substitute.
		if ( class_exists( 'Mobo_Core_Connection_Context' )
			&& ( ! method_exists( 'Mobo_Core_Connection_Context', 'get_current_connection_id' )
				|| (int) Mobo_Core_Connection_Context::get_current_connection_id() !== $id ) ) {
			return new WP_Error( 'mobo_core_quote_owner_unverified', 'Quote transport Connection context is missing or mismatched.' );
		}
		$row = Mobo_Core_Connection_Repository::get_by_id_fresh( $id );
		if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) !== $id
			|| 1 !== (int) ( $row['is_enabled'] ?? 0 )
			|| '' === trim( (string) ( $row['license_token'] ?? '' ) )
			|| '' === trim( (string) ( $row['source_key'] ?? '' ) )
			|| '' === trim( (string) ( $row['api_base_url'] ?? '' ) ) ) {
			return new WP_Error( 'mobo_core_quote_owner_unverified', 'Connection is disabled, missing, or lacks source authorization.' );
		}
		return $row;
	}

	/**
	 * Pure request envelope validation. Caller must additionally resolve the
	 * local Product Map ownership; a request supplied by Woo is not authority.
	 * Text is intentionally not normalized here: no inferred Variant rebinding.
	 */
	public static function validate_purchase_eligibility_quote_request( $request ) {
		if ( ! is_array( $request ) || ! isset( $request['protocol_version'] ) || 1 !== $request['protocol_version'] ) {
			return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Invalid quote protocol.' );
		}
		foreach ( array( 'connection_id', 'product_id', 'variant_id' ) as $key ) {
			if ( ! isset( $request[ $key ] ) || ! is_string( $request[ $key ] )
				|| '' === trim( $request[ $key ] ) || strlen( $request[ $key ] ) > 192
				|| ! preg_match( '//u', $request[ $key ] ) || preg_match( '/[\x00-\x1F\x7F]/', $request[ $key ] ) ) {
				return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Invalid quote scope or identity.' );
			}
		}
		if ( ! isset( $request['quantity'] ) || ! is_string( $request['quantity'] )
			|| ! preg_match( '/^[1-9][0-9]{0,11}$/D', $request['quantity'] ) ) {
			return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Invalid quantity.' );
		}
		if ( ! isset( $request['money_unit'], $request['unit_price'] )
			|| ! is_string( $request['money_unit'] ) || ! in_array( $request['money_unit'], array( 'IRT', 'IRR' ), true )
			|| ! is_string( $request['unit_price'] ) || strlen( $request['unit_price'] ) > 40
			|| ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D', $request['unit_price'] ) ) {
			return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Invalid price or money unit.' );
		}
		// A fresh 128-bit request correlation nonce is required in every quote
		// envelope. A nonce alone is NOT anti-replay protection or authorization.
		if ( ! isset( $request['request_nonce'] ) || ! is_string( $request['request_nonce'] )
			|| ! preg_match( '/^[a-f0-9]{32}$/D', $request['request_nonce'] ) ) {
			return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Invalid quote nonce.' );
		}
		if ( ! isset( $request['attributes'] ) || ! is_array( $request['attributes'] )
			|| ! $request['attributes'] || count( $request['attributes'] ) > 32 ) {
			return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Incomplete purchase attributes.' );
		}
		$canonical_names = array();
		foreach ( $request['attributes'] as $name => $value ) {
			if ( ! is_string( $name ) || ! is_string( $value ) || '' === trim( $name ) || '' === trim( $value )
				|| strlen( $name ) > 192 || strlen( $value ) > 512
				|| ! preg_match( '//u', $name ) || ! preg_match( '//u', $value )
				|| preg_match( '/[\x00-\x1F\x7F]/', $name ) || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Invalid purchase attribute.' );
			}
			// Normalization is collision detection ONLY. We do not remap a
			// removed Variant using a display name or a normalized attribute.
			$canonical_name = trim( strtr( $name, array( 'ي' => 'ی', 'ك' => 'ک',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9' ) ) );
			if ( class_exists( 'Normalizer' ) ) {
				$canonical_name = Normalizer::normalize( $canonical_name, Normalizer::FORM_C );
			} elseif ( preg_match( '/[\x{0300}-\x{036F}]/u', $canonical_name ) ) {
				return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Ambiguous decomposed Unicode.' );
			}
			if ( ! is_string( $canonical_name ) || '' === $canonical_name || isset( $canonical_names[ $canonical_name ] ) ) {
				return new WP_Error( 'mobo_core_purchase_quote_invalid_request', 'Ambiguous purchase attribute name.' );
			}
			$canonical_names[ $canonical_name ] = true;
		}
		return $request;
	}

	/**
	 * Only the explicit unsupported answer is accepted. An arbitrary "trusted"
	 * field in JSON is NEVER a trusted quote, and a 200/true cannot enable sale.
	 */
	public static function validate_purchase_eligibility_quote_response( $response ) {
		// Reject unknown positive or nested evidence on a disabled capability.
		// Whitelisting protects future callers against a confused-deputy use of
		// a `trusted` field echoed from JSON. It does not authenticate a quote.
		$allowed_fields = array( 'protocol_version', 'status', 'purchase_allowed', 'provider_side_effect', 'reason_code' );
		if ( ! is_array( $response ) || count( $response ) !== count( $allowed_fields )
			|| array_diff( array_keys( $response ), $allowed_fields )
			|| ! isset( $response['protocol_version'], $response['status'], $response['reason_code'] )
			|| 1 !== $response['protocol_version'] || 'UNVERIFIED_PENDING' !== $response['status']
			|| 'provider_authoritative_quote_not_implemented' !== $response['reason_code']
			|| ! array_key_exists( 'purchase_allowed', $response ) || false !== $response['purchase_allowed']
			|| ! array_key_exists( 'provider_side_effect', $response ) || false !== $response['provider_side_effect'] ) {
			return new WP_Error( 'mobo_core_purchase_quote_unrecognized', 'Quote response is unrecognized; no purchase authorized.' );
		}
		return $response;
	}

	/**
	 * Get license information from MoboCore/API.
	 *
	 * Legacy plugin versions used the LicenseInfo endpoint to show whether the
	 * license is expired and how much validity remains. Keep the same endpoint
	 * so existing MoboCore contracts continue to work after migration.
	 *
	 * Expected legacy payload includes at least:
	 * - isExpired: bool
	 * - message: string
	 *
	 * @return array|WP_Error
	 */
	public function get_license_info() {
		return $this->get_json( 'LicenseInfo' );
	}

	/**
	 * Support access: submit sealed hosting-panel credentials to the Backend.
	 * POST {api_base_url}/api/support/hosting-access with the normal Token + X-SEC
	 * headers and request signing (post_json). The payload carries only the
	 * RSA-OAEP sealed password; this client never logs request bodies.
	 *
	 * @param array $payload panelType, panelUrl, username, keyId, sealedPassword.
	 * @return array|WP_Error Expected {"success":true,"receivedAt":"ISO"}.
	 */
	public function submit_support_hosting_access( $payload ) {
		return $this->post_json( 'api/support/hosting-access', is_array( $payload ) ? $payload : array() );
	}

	/**
	 * Get API base URL from the active Connection Source Registry entry.
	 * Legacy filter/option fallbacks remain only for interrupted pre-10.34.13 upgrades.
	 *
	 * @return string
	 */
	private function get_base_url() {
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists() ) {
			/* The durable Connection is authoritative once available. Returning an
			 * empty URL here is deliberate fail-closed behavior for a disabled,
			 * deleted or otherwise unresolved explicit owner. */
			$connection_url = trim( (string) Mobo_Core_Connection_Repository::get_runtime_base_url( '' ) );
			return '' !== $connection_url ? trailingslashit( esc_url_raw( $connection_url ) ) : '';
		}

		$base_url = apply_filters( 'mobo_core_api_base_url', '' );

		if ( is_string( $base_url ) && '' !== trim( $base_url ) ) {
			return trailingslashit( esc_url_raw( $base_url ) );
		}

		$base_url = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );

		if ( '' !== trim( $base_url ) ) {
			return trailingslashit( esc_url_raw( $base_url ) );
		}

		return '';
	}


	/**
	 * Network-resilience scope for the effective Portal API origin.
	 * Paths and credentials are intentionally excluded; changing host/scheme/port
	 * must not inherit a circuit opened against a different Backend endpoint.
	 *
	 * @return string
	 */
	private function get_portal_network_scope() {
		$parts = wp_parse_url( $this->get_base_url() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return ''; }
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$port   = isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}



	/**
	 * Return the license GUID required by every Portal API consumed by the plugin.
	 * The only anonymous Portal endpoint is get-products-free, which this client
	 * intentionally does not use for sync or repair.
	 *
	 * @return string|WP_Error
	 */
	private function get_license_token( $fresh_override = null ) {
		$token = null !== $fresh_override
			? trim( (string) $fresh_override )
			: ( class_exists( 'Mobo_Core_Connection_Repository' )
				? trim( (string) Mobo_Core_Connection_Repository::get_runtime_value( 'license_token', Mobo_Core_Settings::get( 'mobo_core_token', '' ) ) )
				: trim( (string) Mobo_Core_Settings::get( 'mobo_core_token', '' ) ) );

		if ( '' === $token ) {
			return new WP_Error(
				'mobo_core_missing_license_token',
				'کد لایسنس ثبت نشده است. همه APIهای Portal به‌جز get-products-free به Header Token نیاز دارند.'
			);
		}

		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token ) ) {
			return new WP_Error(
				'mobo_core_invalid_license_token',
				'ساختار کد لایسنس معتبر نیست؛ مقدار Token باید یک GUID باشد.'
			);
		}

		return $token;
	}

	/** A non-secret digest of every routing/credential field admitted for HTTP. */
	private static function route_fingerprint( $row ) {
		$fields = array( 'id', 'connection_key', 'source_key', 'provider_key', 'api_base_url', 'source_site_url', 'license_token', 'security_code', 'is_enabled' );
		$parts = array();
		foreach ( $fields as $field ) { $parts[] = (string) ( $row[ $field ] ?? '' ); }
		return hash( 'sha256', wp_json_encode( $parts ) );
	}

	/**
	 * A cached credential is never sufficient to authorize the next HTTP send.
	 * Also runs for each manually followed same-origin redirect. This check is
	 * a freshness fence, not a transaction with a concurrent admin disable.
	 * @return true|WP_Error
	 */
	private function validate_outbound_route( $context ) {
		if ( empty( $context['routeFingerprint'] ) ) { return true; } // pre-table upgrade compatibility only.
		$id = absint( $context['connectionId'] ?? 0 );
		$fresh = $id > 0 ? Mobo_Core_Connection_Repository::get_by_id_fresh( $id ) : null;
		$fresh_base_url = is_array( $fresh )
			? Mobo_Core_Connection_Repository::resolve_api_base_url( (string) ( $fresh['api_base_url'] ?? '' ) )
			: '';
		if ( ! is_array( $fresh ) || empty( $fresh['is_enabled'] )
			|| ! Mobo_Core_Connection_Repository::is_provisioned( $fresh )
			|| ! hash_equals( (string) $context['routeFingerprint'], self::route_fingerprint( $fresh ) )
			|| (string) ( $context['baseUrl'] ?? '' ) !== $fresh_base_url
			|| (string) ( $context['baseUrl'] ?? '' ) !== $this->get_base_url() ) {
			return new WP_Error( 'mobo_core_api_connection_changed', 'Connection changed before the API request; retry with a fresh routing context.' );
		}
		return true;
	}

	/**
	 * Cache connection settings for the lifetime of the PHP request.
	 *
	 * @return array|WP_Error
	 */
	private function get_request_context() {
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( is_array( self::$request_context ) && absint( isset( self::$request_context['connectionId'] ) ? self::$request_context['connectionId'] : 0 ) === $connection_id ) {
			return self::$request_context;
		}
		self::$request_context = null;
		$fresh_row = null;
		$durable_mode = class_exists( 'Mobo_Core_Connection_Repository' ) && Mobo_Core_Connection_Repository::table_exists();
		if ( $durable_mode ) {
			$fresh_row = $connection_id > 0 ? Mobo_Core_Connection_Repository::get_by_id_fresh( $connection_id ) : null;
			if ( ! is_array( $fresh_row ) || empty( $fresh_row['is_enabled'] ) || ! Mobo_Core_Connection_Repository::is_provisioned( $fresh_row ) ) {
				return new WP_Error( 'mobo_core_api_connection_changed', 'Connection is unavailable for this API request.' );
			}
		}

		$license_token = $this->get_license_token( is_array( $fresh_row ) ? (string) ( $fresh_row['license_token'] ?? '' ) : null );
		if ( is_wp_error( $license_token ) ) {
			return $license_token;
		}

		$headers = array(
			'Accept' => 'application/json',
			'Token'  => $license_token,
		);

		$security_source = is_array( $fresh_row )
			? (string) ( $fresh_row['security_code'] ?? '' )
			: ( class_exists( 'Mobo_Core_Connection_Repository' )
				? Mobo_Core_Connection_Repository::get_runtime_value( 'security_code', Mobo_Core_Settings::get( 'mobo_core_security_code', '' ) )
				: Mobo_Core_Settings::get( 'mobo_core_security_code', '' ) );
		$security_code = Mobo_Core_Settings::normalize_security_code( $security_source );
		if ( '' !== $security_code ) {
			if ( ! Mobo_Core_Settings::is_valid_security_code( $security_code ) ) {
				return new WP_Error(
					'mobo_core_invalid_security_code',
					Mobo_Core_Settings::get_security_code_validation_error( $security_code )
				);
			}

			$headers['X-SEC'] = $security_code;
		}

		self::$request_context = array(
			'connectionId'     => $connection_id,
			'headers'          => $headers,
			'routeFingerprint' => is_array( $fresh_row ) ? self::route_fingerprint( $fresh_row ) : '',
			'baseUrl'          => is_array( $fresh_row )
				? Mobo_Core_Connection_Repository::resolve_api_base_url( (string) ( $fresh_row['api_base_url'] ?? '' ) )
				: '',
		);

		return self::$request_context;
	}

	/**
	 * Clamp a configured timeout to the current runner deadline.
	 *
	 * @param int $configured Configured timeout seconds.
	 * @return int|WP_Error
	 */
	private function get_effective_timeout( $configured ) {
		$configured = max( 2, absint( $configured ) );

		if ( self::$runtime_deadline <= 0 ) {
			return self::clamp_to_request_budget( $configured );
		}

		$remaining = self::$runtime_deadline - microtime( true ) - self::$runtime_deadline_reserve;
		if ( $remaining < 1.0 ) {
			return new WP_Error(
				'mobo_core_api_runtime_budget_exhausted',
				'Portal API request skipped because the current runner slice is at its deadline.'
			);
		}

		return max( 1, min( $configured, (int) floor( $remaining ) ) );
	}

	/**
	 * Outside a runner slice (webhook receive, admin fallback, REST), keep a blocking
	 * request inside the PHP request's own wall-clock budget minus a margin so a
	 * slow Backend cannot push the request past max_execution_time / proxy timeouts.
	 * Never below 5 s and never an error: this path only shortens the wait.
	 *
	 * @param int $configured Configured timeout seconds.
	 * @return int
	 */
	private static function clamp_to_request_budget( $configured ) {
		$configured = max( 2, absint( $configured ) );
		$limit      = absint( ini_get( 'max_execution_time' ) );
		if ( $limit <= 0 ) {
			return $configured;
		}
		$started   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numeric server timestamp.
		$remaining = ( $started + $limit ) - microtime( true ) - 3.0;
		return max( min( $configured, 5 ), min( $configured, (int) floor( $remaining ) ) );
	}

	/**
	 * Whether an API error means the route itself is absent rather than the host
	 * being temporarily unavailable.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private function is_missing_endpoint_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 0;
		return in_array( $status, array( 404, 405, 410, 501 ), true );
	}

	/**
	 * Persist the working revision endpoint so future reconciliation runs avoid
	 * a failed compatibility probe.
	 *
	 * @param string $preference primary|compat.
	 * @return void
	 */
	private function remember_sync_changes_endpoint( $preference ) {
		$preference = 'compat' === $preference ? 'compat' : 'primary';
		Mobo_Core_Settings::update( 'mobo_core_sync_changes_endpoint_preference', $preference, false );
		Mobo_Core_Settings::delete( 'mobo_core_sync_changes_endpoint_retry_after' );
	}

	/**
	 * Cache a genuine route-missing result for a bounded period.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function remember_sync_changes_unavailable_if_missing( $error ) {
		if ( ! $this->is_missing_endpoint_error( $error ) ) {
			return;
		}

		$ttl = Mobo_Core_Settings::get_int( 'mobo_core_sync_changes_endpoint_probe_interval_seconds', 21600, 300, DAY_IN_SECONDS );
		Mobo_Core_Settings::update( 'mobo_core_sync_changes_endpoint_preference', 'unsupported', false );
		Mobo_Core_Settings::update( 'mobo_core_sync_changes_endpoint_retry_after', time() + $ttl, false );
	}

	/**
	 * Open a short in-request circuit after a network/upstream failure.
	 *
	 * @param WP_Error $error Error to reuse.
	 * @param int      $seconds Circuit lifetime.
	 * @return void
	 */
	private function open_request_circuit( $error, $seconds = 5 ) {
		self::$circuit_error      = is_wp_error( $error ) ? $error : null;
		self::$circuit_open_until = microtime( true ) + max( 1, min( 15, absint( $seconds ) ) );
	}

	/**
	 * Return an immediate error while the current PHP request knows the Portal is
	 * temporarily unreachable. This prevents a claimed webhook batch from waiting
	 * on the same dead host repeatedly.
	 *
	 * @return WP_Error|null
	 */
	private function get_open_circuit_error() {
		if ( self::$circuit_open_until <= microtime( true ) ) {
			return null;
		}

		$message = is_wp_error( self::$circuit_error )
			? self::$circuit_error->get_error_message()
			: 'Portal API is temporarily unavailable in this runner slice.';

		return new WP_Error( 'mobo_core_api_request_circuit_open', $message );
	}


	/**
	 * Normalize a payload URL.
	 *
	 * @param string $payload_url Payload URL.
	 * @return string|WP_Error
	 */
	private function normalize_payload_url( $payload_url ) {
		$payload_url = trim( (string) $payload_url );
		$base_url    = $this->get_base_url();

		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing for payload URL validation.' );
		}

		if ( preg_match( '#^https?://#i', $payload_url ) ) {
			$url = esc_url_raw( $payload_url );
		} elseif ( 0 === strpos( $payload_url, '/' ) ) {
			$parts = wp_parse_url( $base_url );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return new WP_Error( 'mobo_core_invalid_api_base_url', 'API base URL is invalid.' );
			}
			$port = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
			$url  = esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . $port . $payload_url );
		} else {
			$url = esc_url_raw( trailingslashit( $base_url ) . ltrim( $payload_url, '/' ) );
		}

		return $this->validate_trusted_api_url( $url );
	}

	/**
	 * Require Portal payload/API URLs to stay on the configured API origin.
	 * License Token and X-SEC must never be forwarded to an arbitrary host.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error
	 */
	private function validate_trusted_api_url( $url ) {
		$url      = esc_url_raw( (string) $url );
		$base_url = $this->get_base_url();
		$target   = wp_parse_url( $url );
		$base     = wp_parse_url( $base_url );

		if ( '' === $url || ! is_array( $target ) || ! is_array( $base ) || empty( $target['scheme'] ) || empty( $target['host'] ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return new WP_Error( 'mobo_core_invalid_payload_url', 'Payload URL is invalid.' );
		}
		if ( isset( $target['user'] ) || isset( $target['pass'] ) ) {
			return new WP_Error( 'mobo_core_untrusted_payload_url', 'Payload URL with embedded credentials is not allowed.' );
		}

		$target_scheme = strtolower( (string) $target['scheme'] );
		$base_scheme   = strtolower( (string) $base['scheme'] );
		$target_host   = strtolower( rtrim( (string) $target['host'], '.' ) );
		$base_host     = strtolower( rtrim( (string) $base['host'], '.' ) );
		$target_port   = isset( $target['port'] ) ? absint( $target['port'] ) : ( 'https' === $target_scheme ? 443 : 80 );
		$base_port     = isset( $base['port'] ) ? absint( $base['port'] ) : ( 'https' === $base_scheme ? 443 : 80 );

		if ( ! in_array( $target_scheme, array( 'http', 'https' ), true ) || $target_scheme !== $base_scheme || $target_host !== $base_host || $target_port !== $base_port ) {
			return new WP_Error( 'mobo_core_untrusted_payload_url', 'Payload URL must use the same origin as the configured Portal API.' );
		}

		return $url;
	}

	/**
	 * Resolve one redirect Location and validate it before credentials are resent.
	 *
	 * @param string $current_url Current URL.
	 * @param string $location Location header.
	 * @return string|WP_Error
	 */
	private function resolve_trusted_redirect_url( $current_url, $location ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return new WP_Error( 'mobo_core_redirect_location_missing', 'Portal redirect did not include a Location header.' );
		}
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $this->validate_trusted_api_url( $location );
		}

		$current = wp_parse_url( $current_url );
		if ( ! is_array( $current ) || empty( $current['scheme'] ) || empty( $current['host'] ) ) {
			return new WP_Error( 'mobo_core_invalid_redirect_base', 'Could not resolve Portal redirect.' );
		}
		$port   = isset( $current['port'] ) ? ':' . absint( $current['port'] ) : '';
		$origin = $current['scheme'] . '://' . $current['host'] . $port;
		if ( 0 === strpos( $location, '/' ) ) {
			return $this->validate_trusted_api_url( $origin . $location );
		}
		$path = isset( $current['path'] ) ? (string) $current['path'] : '/';
		$dir  = preg_replace( '#/[^/]*$#', '/', $path );
		return $this->validate_trusted_api_url( $origin . $dir . $location );
	}

	/**
	 * GET JSON from a full trusted Portal URL.
	 *
	 * @param string $url Full URL.
	 * @param int    $timeout Timeout seconds.
	 * @param bool   $require_array Require the decoded JSON value to be an array.
	 * @return mixed|WP_Error
	 */
	private function get_json_url( $url, $timeout = 20, $require_array = true ) {
		$url = $this->validate_trusted_api_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$network_scope = $this->get_portal_network_scope();
		$dependency_check = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::before_request( 'portal_api', 0, $network_scope ) : true;
		if ( is_wp_error( $dependency_check ) ) {
			return $dependency_check;
		}
		$circuit_error = $this->get_open_circuit_error();
		if ( is_wp_error( $circuit_error ) ) {
			return $circuit_error;
		}
		$context = $this->get_request_context();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$current_url = $url;
		for ( $redirects = 0; $redirects <= 3; $redirects++ ) {
			$effective_timeout = $this->get_effective_timeout( $timeout );
			if ( is_wp_error( $effective_timeout ) ) {
				return $effective_timeout;
			}
			$route_check = $this->validate_outbound_route( $context );
			if ( is_wp_error( $route_check ) ) { return $route_check; }
			$signed_headers = Mobo_Core_Portal_Request_Signer::sign_headers( 'GET', $current_url, '', $context['headers'] );
			if ( is_wp_error( $signed_headers ) ) {
				return $signed_headers;
			}

			$response = wp_remote_get(
				$current_url,
				array(
					'timeout'     => $effective_timeout,
					'redirection' => 0,
					'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'api_client' ),
					'headers'     => $signed_headers,
				)
			);

			if ( is_wp_error( $response ) ) {
				/* Transport errors may embed signed URLs, Token/X-SEC, filesystem
				 * paths or the upstream response. Classify, persist only sanitized
				 * evidence, and never propagate the raw transport message. */
				$failure = class_exists( 'Mobo_Core_Network_Resilience' )
					? Mobo_Core_Network_Resilience::classify_transport_error( $response )
					: array( 'failureClass' => 'transport_failure', 'retryable' => true, 'retryAfterSeconds' => 0, 'originalError' => sanitize_key( (string) $response->get_error_code() ) );
				$error = class_exists( 'Mobo_Core_Network_Resilience' )
					? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_payload_request_failed', 'Portal API GET transport failed.', $failure )
					: new WP_Error( 'mobo_core_payload_request_failed', 'Portal API GET transport failed.', array( 'original_error' => sanitize_key( (string) $response->get_error_code() ) ) );
				if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) {
					Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope );
				}
				$this->open_request_circuit( $error, max( 5, absint( isset( $failure['retryAfterSeconds'] ) ? $failure['retryAfterSeconds'] : 0 ) ) );
				return $error;
			}

			$code = absint( wp_remote_retrieve_response_code( $response ) );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				if ( $redirects >= 3 ) {
					return new WP_Error( 'mobo_core_payload_too_many_redirects', 'Portal payload request exceeded the redirect limit.' );
				}
				$current_url = $this->resolve_trusted_redirect_url( $current_url, wp_remote_retrieve_header( $response, 'location' ) );
				if ( is_wp_error( $current_url ) ) {
					return $current_url;
				}
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( $code < 200 || $code >= 300 ) {
				$error_payload = json_decode( $body, true );
				$error_status  = is_array( $error_payload ) && isset( $error_payload['status'] ) ? sanitize_key( (string) $error_payload['status'] ) : '';
				$retry_after = (string) wp_remote_retrieve_header( $response, 'retry-after' );
				$failure = class_exists( 'Mobo_Core_Network_Resilience' )
					? Mobo_Core_Network_Resilience::classify_http_status( $code, $retry_after )
					: array( 'failureClass' => 'http_error', 'status' => $code, 'retryable' => false, 'retryAfterSeconds' => 0 );
				$error = class_exists( 'Mobo_Core_Network_Resilience' )
					? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_payload_http_error', sprintf( 'Portal API GET returned HTTP %d.', $code ), $failure, array( 'portal_status' => $error_status ) )
					: new WP_Error( 'mobo_core_payload_http_error', sprintf( 'Portal API GET returned HTTP %d.', $code ), array( 'status' => $code, 'portal_status' => $error_status ) );
				if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) {
					Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope );
				}
				if ( ! empty( $failure['retryable'] ) || ! empty( $failure['operatorAction'] ) ) {
					$this->open_request_circuit( $error, max( 5, absint( isset( $failure['retryAfterSeconds'] ) ? $failure['retryAfterSeconds'] : 0 ) ) );
				}
				return $error;
			}

			if ( '' === trim( $body ) ) {
				$failure = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::invalid_response_failure( 'empty_response' ) : array();
				if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope ); }
				return class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_empty_payload_response', 'Payload endpoint returned empty response.', $failure ) : new WP_Error( 'mobo_core_empty_payload_response', 'Payload endpoint returned empty response.' );
			}
			$json = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ( $require_array && ! is_array( $json ) ) ) {
				$failure = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::invalid_response_failure( 'invalid_json' ) : array();
				if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope ); }
				return class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_invalid_payload_json', 'Payload endpoint returned invalid JSON.', $failure ) : new WP_Error( 'mobo_core_invalid_payload_json', 'Payload endpoint returned invalid JSON.' );
			}
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_success( 'portal_api', 0, $network_scope ); }
			return $json;
		}

		return new WP_Error( 'mobo_core_payload_request_failed', 'Portal payload request failed unexpectedly.' );
	}

	/**
	 * GET JSON from API.
	 *
	 * @param string $path Relative path.
	 * @return array|WP_Error
	 */
	private function post_json( $path, $payload ) {
		$base_url = $this->get_base_url();
		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing.' );
		}
		$network_scope = $this->get_portal_network_scope();
		$dependency_check = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::before_request( 'portal_api', 0, $network_scope ) : true;
		if ( is_wp_error( $dependency_check ) ) { return $dependency_check; }
		$url = $this->validate_trusted_api_url( $base_url . ltrim( (string) $path, '/' ) );
		if ( is_wp_error( $url ) ) { return $url; }
		$context = $this->get_request_context();
		if ( is_wp_error( $context ) ) { return $context; }
		$body = wp_json_encode( is_array( $payload ) ? $payload : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) { return new WP_Error( 'mobo_core_api_json_encode_failed', 'Could not encode API request JSON.' ); }
		$headers = $context['headers'];
		$headers['Content-Type'] = 'application/json; charset=utf-8';
		$headers['Accept'] = 'application/json';
		$headers = Mobo_Core_Portal_Request_Signer::sign_headers( 'POST', $url, $body, $headers );
		if ( is_wp_error( $headers ) ) { return $headers; }
		$timeout = $this->get_effective_timeout( Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 ) );
		if ( is_wp_error( $timeout ) ) { return $timeout; }
		$route_check = $this->validate_outbound_route( $context );
		if ( is_wp_error( $route_check ) ) { return $route_check; }
		$response = wp_remote_post( $url, array( 'timeout' => $timeout, 'redirection' => 0, 'sslverify' => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'api_client' ), 'headers' => $headers, 'body' => $body ) );
		if ( is_wp_error( $response ) ) {
			$failure = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::classify_transport_error( $response ) : array( 'failureClass' => 'transport_failure', 'retryable' => true, 'retryAfterSeconds' => 0, 'originalError' => sanitize_key( (string) $response->get_error_code() ) );
			$error = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_payload_request_failed', 'Portal API POST transport failed.', $failure ) : new WP_Error( 'mobo_core_payload_request_failed', 'Portal API POST transport failed.', array( 'original_error' => sanitize_key( (string) $response->get_error_code() ) ) );
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope ); }
			$this->open_request_circuit( $error, max( 5, absint( isset( $failure['retryAfterSeconds'] ) ? $failure['retryAfterSeconds'] : 0 ) ) );
			return $error;
		}
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$raw = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$portal_status = is_array( $json ) && isset( $json['status'] ) ? sanitize_key( (string) $json['status'] ) : '';
			$failure = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::classify_http_status( $code, (string) wp_remote_retrieve_header( $response, 'retry-after' ) ) : array( 'failureClass' => 'http_error', 'status' => $code, 'retryable' => false, 'retryAfterSeconds' => 0 );
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope ); }
			$error = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_payload_http_error', sprintf( 'Portal API POST returned HTTP %d.', $code ), $failure, array( 'portal_status' => $portal_status ) ) : new WP_Error( 'mobo_core_payload_http_error', sprintf( 'Portal API POST returned HTTP %d.', $code ), array( 'status' => $code, 'portal_status' => $portal_status ) );
			if ( ! empty( $failure['retryable'] ) || ! empty( $failure['operatorAction'] ) ) { $this->open_request_circuit( $error, max( 5, absint( isset( $failure['retryAfterSeconds'] ) ? $failure['retryAfterSeconds'] : 0 ) ) ); }
			return $error;
		}
		if ( ! is_array( $json ) ) {
			$failure = class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::invalid_response_failure( 'invalid_json' ) : array();
			if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_failure( 'portal_api', $failure, 0, $network_scope ); }
			return class_exists( 'Mobo_Core_Network_Resilience' ) ? Mobo_Core_Network_Resilience::to_wp_error( 'mobo_core_invalid_payload_json', 'Payload endpoint returned invalid JSON.', $failure ) : new WP_Error( 'mobo_core_invalid_payload_json', 'Payload endpoint returned invalid JSON.' );
		}
		if ( class_exists( 'Mobo_Core_Network_Resilience' ) ) { Mobo_Core_Network_Resilience::record_success( 'portal_api', 0, $network_scope ); }
		return $json;
	}

	private function get_json( $path ) {
		$base_url = $this->get_base_url();

		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing.' );
		}

		$url = $base_url . ltrim( $path, '/' );

		return $this->get_json_url( $url, Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 ) );
	}


	/**
	 * GET any valid JSON value from API. Used only by scalar/lightweight endpoints.
	 * The same route freshness, signing, redirect and circuit-breaker rules as the
	 * normal array client are retained.
	 *
	 * @param string $path Relative path.
	 * @return mixed|WP_Error
	 */
	private function get_json_value( $path ) {
		$base_url = $this->get_base_url();

		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing.' );
		}

		$url = $base_url . ltrim( $path, '/' );

		return $this->get_json_url( $url, Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 ), false );
	}
}