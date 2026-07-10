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
	public function get_products_page( $page_number, $record_per_page, $sync_id, $cursor = 0, $use_cursor = false ) {
		$only_in_stock = Mobo_Core_Settings::enabled( 'mobo_core_only_in_stock', '0' ) ? 'true' : 'false';

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
	public function get_event_payload( $payload_url ) {
		$payload_url = trim( (string) $payload_url );

		if ( '' === $payload_url ) {
			return new WP_Error( 'mobo_core_missing_payload_url', 'Payload URL is missing.' );
		}

		$url = $this->normalize_payload_url( $payload_url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return $this->get_json_url( $url, Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 ) );
	}


	/**
	 * Get cached address mapping from MoboCore.
	 *
	 * MoboCore returns countries/states/cities with Mobo numeric IDs. Customer sites
	 * cache this locally and use it for checkout address selects.
	 *
	 * Expected endpoint:
	 * /get-address-mapping?force=true|false
	 *
	 * @param bool $force Ask MoboCore to refresh if needed.
	 * @return array|WP_Error
	 */
	public function get_address_mapping( $force = false ) {
		$path = add_query_arg(
			array(
				'force' => $force ? 'true' : 'false',
			),
			'get-address-mapping'
		);

		return $this->get_json( $path );
	}

	/**
	 * Get cached Mobo shipping methods from MoboCore.
	 *
	 * Expected endpoint:
	 * /get-mobo-shipping-methods?force=true|false
	 *
	 * @param bool $force Ask MoboCore to refresh if supported.
	 * @return array|WP_Error
	 */
	public function get_mobo_shipping_methods( $force = false ) {
		$path = add_query_arg(
			array(
				'force' => $force ? 'true' : 'false',
			),
			'get-mobo-shipping-methods'
		);

		return $this->get_json( $path );
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
	 * Normalize a payload URL.
	 *
	 * @param string $payload_url Payload URL.
	 * @return string|WP_Error
	 */
	private function normalize_payload_url( $payload_url ) {
		$payload_url = trim( (string) $payload_url );

		if ( preg_match( '#^https?://#i', $payload_url ) ) {
			return esc_url_raw( $payload_url );
		}

		$base_url = $this->get_base_url();

		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing for relative payload URL.' );
		}

		if ( 0 === strpos( $payload_url, '/' ) ) {
			$parts = wp_parse_url( $base_url );

			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return new WP_Error( 'mobo_core_invalid_api_base_url', 'API base URL is invalid.' );
			}

			$port = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
			return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . $port . $payload_url );
		}

		return esc_url_raw( trailingslashit( $base_url ) . ltrim( $payload_url, '/' ) );
	}

	/**
	 * GET JSON from a full URL.
	 *
	 * @param string $url Full URL.
	 * @param int    $timeout Timeout seconds.
	 * @return array|WP_Error
	 */
	private function get_json_url( $url, $timeout = 20 ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'mobo_core_invalid_payload_url', 'Payload URL is invalid.' );
		}

		$headers = array(
			'Accept' => 'application/json',
		);

		$security_code = (string) Mobo_Core_Settings::get( 'mobo_core_security_code', '' );

		if ( '' !== trim( $security_code ) ) {
			$headers['X-SEC'] = trim( $security_code );
		}

		$token = (string) Mobo_Core_Settings::get( 'mobo_core_token', '' );

		if ( '' !== trim( $token ) ) {
			$headers['Token'] = trim( $token );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => max( 5, absint( $timeout ) ),
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'api_client' ),
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = sprintf(
				'Payload request failed. URL=%s Error=%s',
				$url,
				$response->get_error_message()
			);

			return new WP_Error(
				'mobo_core_payload_request_failed',
				$error_message,
				array(
					'url'            => $url,
					'original_error' => $response->get_error_code(),
					'error_message'  => $response->get_error_message(),
				)
			);
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mobo_core_payload_http_error',
				sprintf( 'Payload HTTP error. URL=%s Status=%d', $url, $code ),
				array(
					'url'    => $url,
					'status' => $code,
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $body ) ) {
			return new WP_Error( 'mobo_core_empty_payload_response', 'Payload endpoint returned empty response.' );
		}

		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_invalid_payload_json', 'Payload endpoint returned invalid JSON.' );
		}

		return $json;
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