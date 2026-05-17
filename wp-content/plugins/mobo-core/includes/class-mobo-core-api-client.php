<?php
/**
 * API client for manual chunked sync.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_API_Client {

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
				'PageNumber'    => absint( $page_number ),
				'RecordPerPage' => absint( $record_per_page ),
			),
			'get-products'
		);

		return $this->get_json( $path );
	}

	/**
	 * Get variants page.
	 *
	 * Recommended API:
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

		$path = add_query_arg(
			array(
				'SyncId'        => sanitize_text_field( (string) $sync_id ),
				'PageNumber'    => absint( $page_number ),
				'RecordPerPage' => absint( $record_per_page ),
			),
			$product_guid . '/get-variants'
		);

		return $this->get_json( $path );
	}

	/**
	 * GET JSON from API.
	 *
	 * @param string $path Relative path.
	 * @return array|WP_Error
	 */
	private function get_json( $path ) {
		$base_url = esc_url_raw( (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' ) );

		if ( '' === $base_url ) {
			return new WP_Error( 'mobo_core_missing_api_base_url', 'API base URL is missing.' );
		}

		$url = trailingslashit( $base_url ) . ltrim( $path, '/' );

		$headers = array(
			'Accept' => 'application/json',
		);

		$token = (string) Mobo_Core_Settings::get( 'mobo_core_api_token', '' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

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
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_invalid_api_json', 'API returned invalid JSON.' );
		}

		return $json;
	}
}