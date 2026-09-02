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

		$preference  = sanitize_key( (string) get_option( 'mobo_core_sync_changes_endpoint_preference', '' ) );
		$retry_after = absint( get_option( 'mobo_core_sync_changes_endpoint_retry_after', 0 ) );
		$base_url    = $this->get_base_url();
		$fingerprint = '' !== $base_url ? md5( strtolower( $base_url ) ) : '';
		$known_base  = sanitize_text_field( (string) get_option( 'mobo_core_sync_changes_endpoint_base_fingerprint', '' ) );

		if ( '' !== $fingerprint && '' !== $known_base && ! hash_equals( $known_base, $fingerprint ) ) {
			$preference  = '';
			$retry_after = 0;
			delete_option( 'mobo_core_sync_changes_endpoint_preference' );
			delete_option( 'mobo_core_sync_changes_endpoint_retry_after' );
		}

		if ( '' !== $fingerprint && $fingerprint !== $known_base ) {
			update_option( 'mobo_core_sync_changes_endpoint_base_fingerprint', $fingerprint, false );
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

		$cache_key = md5( $url );
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
	 * Get API base URL from plugin/legacy configuration.
	 *
	 * Priority:
	 * 1. mobo_core_api_base_url filter
	 * 2. mobo_core_api_base_url option fallback
	 *
	 * @return string
	 */
	private function get_base_url() {
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
	 * Return the license GUID required by every Portal API consumed by the plugin.
	 * The only anonymous Portal endpoint is get-products-free, which this client
	 * intentionally does not use for sync or repair.
	 *
	 * @return string|WP_Error
	 */
	private function get_license_token() {
		$token = trim( (string) Mobo_Core_Settings::get( 'mobo_core_token', '' ) );

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

	/**
	 * Cache connection settings for the lifetime of the PHP request.
	 *
	 * @return array|WP_Error
	 */
	private function get_request_context() {
		if ( is_array( self::$request_context ) ) {
			return self::$request_context;
		}

		$license_token = $this->get_license_token();
		if ( is_wp_error( $license_token ) ) {
			return $license_token;
		}

		$headers = array(
			'Accept' => 'application/json',
			'Token'  => $license_token,
		);

		$security_code = Mobo_Core_Settings::normalize_security_code( Mobo_Core_Settings::get( 'mobo_core_security_code', '' ) );
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
			'headers' => $headers,
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
			return $configured;
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
		update_option( 'mobo_core_sync_changes_endpoint_preference', $preference, false );
		delete_option( 'mobo_core_sync_changes_endpoint_retry_after' );
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
		update_option( 'mobo_core_sync_changes_endpoint_preference', 'unsupported', false );
		update_option( 'mobo_core_sync_changes_endpoint_retry_after', time() + $ttl, false );
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
	 * @return array|WP_Error
	 */
	private function get_json_url( $url, $timeout = 20 ) {
		$url = $this->validate_trusted_api_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
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
				$error = new WP_Error(
					'mobo_core_payload_request_failed',
					sprintf( 'Payload request failed. URL=%s Error=%s', $current_url, $response->get_error_message() ),
					array( 'url' => $current_url, 'original_error' => $response->get_error_code(), 'error_message' => $response->get_error_message() )
				);
				$this->open_request_circuit( $error, 5 );
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
				$error_message = is_array( $error_payload ) && isset( $error_payload['message'] ) ? sanitize_text_field( (string) $error_payload['message'] ) : '';
				$error = new WP_Error(
					'mobo_core_payload_http_error',
					'' !== $error_message ? $error_message : sprintf( 'Portal API HTTP error. URL=%s Status=%d', $current_url, $code ),
					array( 'url' => $current_url, 'status' => $code, 'portal_status' => $error_status, 'body' => function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 1000 ) : substr( $body, 0, 1000 ) )
				);
				if ( in_array( $code, array( 502, 503, 504 ), true ) ) {
					$this->open_request_circuit( $error, 5 );
				}
				return $error;
			}

			if ( '' === trim( $body ) ) {
				return new WP_Error( 'mobo_core_empty_payload_response', 'Payload endpoint returned empty response.' );
			}
			$json = json_decode( $body, true );
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'mobo_core_invalid_payload_json', 'Payload endpoint returned invalid JSON.' );
			}
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
	private function get_json( $path ) {
		$base_url = $this->get_base_url();

		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing.' );
		}

		$url = $base_url . ltrim( $path, '/' );

		return $this->get_json_url( $url, Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 ) );
	}
}