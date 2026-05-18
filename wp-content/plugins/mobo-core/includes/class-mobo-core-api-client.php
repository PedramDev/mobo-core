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
	public function get_products_page( $page_number, $record_per_page, $sync_id ) {
		$only_in_stock = Mobo_Core_Settings::enabled( 'mobo_core_only_in_stock', '0' ) ? 'true' : 'false';

		$path = add_query_arg(
			array(
				'OnlyInStock'   => $only_in_stock,
				'RemVariants'   => 'true',
				'SyncId'        => sanitize_text_field( (string) $sync_id ),
				'PageNumber'    => max( 1, absint( $page_number ) ),
				'RecordPerPage' => max( 1, absint( $record_per_page ) ),
			),
			'get-products'
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
	public function get_variants_page( $product_guid, $page_number, $record_per_page, $sync_id ) {
		$product_guid = rawurlencode( sanitize_text_field( (string) $product_guid ) );

		if ( '' === $product_guid ) {
			return new WP_Error( 'mobo_core_missing_product_guid', 'Product GUID is missing.' );
		}

		$path = add_query_arg(
			array(
				'SyncId'        => sanitize_text_field( (string) $sync_id ),
				'PageNumber'    => max( 1, absint( $page_number ) ),
				'RecordPerPage' => max( 1, absint( $record_per_page ) ),
			),
			$product_guid . '/get-variants'
		);

		return $this->get_json( $path );
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

		$headers = array(
			'Accept' => 'application/json',
		);

		$token = (string) Mobo_Core_Settings::get( 'mobo_core_api_token', '' );

		if ( '' !== trim( $token ) ) {
			$headers['Token'] = trim( $token );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => false,
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mobo_core_api_request_failed',
				'API request failed.',
				array(
					'original_error' => $response->get_error_code(),
				)
			);
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mobo_core_api_http_error',
				'API HTTP error.',
				array(
					'status' => $code,
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $body ) ) {
			return new WP_Error( 'mobo_core_empty_api_response', 'API returned empty response.' );
		}

		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_invalid_api_json', 'API returned invalid JSON.' );
		}

		return $json;
	}
}