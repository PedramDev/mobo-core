<?php
/**
 * Recover images for existing Mobo products that currently have no usable
 * featured image.
 *
 * This path intentionally ignores the customer's OnlyInStock product-list
 * filter. It fetches one already-known product by its GUID and applies images
 * only; product fields, stock and variants are never updated here.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Direct database access is used only for cursor-based discovery of existing
 * local Mobo products. Identifiers are internal and all external values are
 * prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Missing_Image_Recovery {

	/**
	 * Whether automatic image updates allow this recovery path.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return Mobo_Core_Settings::enabled( 'global_update_images', '1' );
	}

	/**
	 * Return a cursor batch of existing local Mobo products and runtime-filter it
	 * to products whose featured image is physically missing or invalid. Scanning
	 * the full Mobo product set also catches stale attachment metadata that still
	 * points at a deleted/corrupt file.
	 *
	 * @param int $limit Maximum local products to inspect.
	 * @param int $after_id Cursor.
	 * @return array
	 */
	public function get_candidate_batch( $limit, $after_id = 0 ) {
		global $wpdb;

		$limit       = max( 1, min( 5000, absint( $limit ) ) );
		$after_id    = max( 0, absint( $after_id ) );
		$fetch_limit = $limit + 1;

		/*
		 * Deliberately scan every known local Mobo product, not only rows whose
		 * attachment metadata is missing. A valid `_wp_attached_file` database
		 * value can still point at a deleted, zero-byte or non-image file. The
		 * bounded runtime check below is the authoritative health test.
		 */
		$estimated_total = absint(
			$wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} guid_meta
					ON guid_meta.post_id = p.ID
					AND guid_meta.meta_key = 'product_guid'
					AND guid_meta.meta_value <> ''
				WHERE p.post_type = 'product'
					AND p.post_status NOT IN ('trash', 'auto-draft')"
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, guid_meta.meta_value AS product_guid
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} guid_meta
					ON guid_meta.post_id = p.ID
					AND guid_meta.meta_key = 'product_guid'
					AND guid_meta.meta_value <> ''
				WHERE p.post_type = 'product'
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				$after_id,
				$fetch_limit
			),
			ARRAY_A
		);

		$rows       = is_array( $rows ) ? $rows : array();
		$has_more   = count( $rows ) > $limit;
		$rows       = array_slice( $rows, 0, $limit );
		$candidates = array();
		$cursor_end = $after_id;

		foreach ( $rows as $row ) {
			$product_id   = absint( isset( $row['ID'] ) ? $row['ID'] : 0 );
			$product_guid = sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) );

			if ( $product_id > 0 ) {
				$cursor_end = $product_id;
			}

			if ( $product_id <= 0 || '' === $product_guid || ! $this->product_needs_image( $product_id ) ) {
				continue;
			}

			$candidates[] = array(
				'product_id'   => $product_id,
				'product_guid' => $product_guid,
			);
		}

		return array(
			'rows'           => $candidates,
			'scanned'        => count( $rows ),
			'cursorStart'    => $after_id,
			'cursorEnd'      => $cursor_end,
			'cycleComplete'  => ! $has_more,
			'estimatedTotal' => $estimated_total,
		);
	}

	/**
	 * Whether a local product has no usable featured image.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function product_needs_image( $product_id ) {
		$product_id = absint( $product_id );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

		if ( ! $product instanceof WC_Product || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		$attachment_id = absint( $product->get_image_id() );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return true;
		}

		try {
			$file = get_attached_file( $attachment_id );
			if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || filesize( $file ) <= 0 ) {
				return true;
			}

			/* Detect non-image HTTP/error payloads that happen to be non-empty files. */
			if ( function_exists( 'wp_get_image_mime' ) ) {
				$mime = strtolower( (string) wp_get_image_mime( $file ) );
				if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
					return true;
				}
			}

			return false;
		} catch ( Throwable $error ) {
			return true;
		}
	}

	/**
	 * Fetch the known product by GUID and enqueue/process images only.
	 *
	 * @param int    $product_id Local product ID.
	 * @param string $product_guid Remote product GUID.
	 * @param string $sync_id Diagnostic sync ID.
	 * @return array|WP_Error
	 */
	public function recover_product( $product_id, $product_guid, $sync_id = '' ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$sync_id      = sanitize_text_field( (string) $sync_id );

		if ( ! $this->is_enabled() ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'image-updates-disabled',
				'message' => 'بروزرسانی اتوماتیک تصاویر غیرفعال است.',
			);
		}

		if ( $product_id <= 0 || '' === $product_guid || 'product' !== get_post_type( $product_id ) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'invalid-local-product',
				'message' => 'محصول محلی یا GUID آن معتبر نیست.',
			);
		}

		if ( ! $this->product_needs_image( $product_id ) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'image-already-present',
				'message' => 'محصول در حال حاضر تصویر معتبر دارد.',
			);
		}

		if ( '' === $sync_id ) {
			$sync_id = 'missing-image-' . gmdate( 'YmdHis' );
		}

		$api      = new Mobo_Core_API_Client();
		$response = $api->get_product_by_guid( $product_guid, $sync_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = $this->get_value( $response, 'data', array() );
		$items = is_array( $items ) ? $items : array();
		$item  = array();

		foreach ( $items as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_guid = sanitize_text_field( (string) $this->get_value( $candidate, 'productId', '' ) );
			if ( '' === $candidate_guid ) {
				$candidate_guid = sanitize_text_field( (string) $this->get_value( $candidate, 'id', '' ) );
			}

			if ( '' === $candidate_guid || strtolower( $candidate_guid ) === strtolower( $product_guid ) ) {
				$item = $candidate;
				break;
			}
		}

		if ( empty( $item ) && ! empty( $items[0] ) && is_array( $items[0] ) ) {
			$item = $items[0];
		}

		$images = $this->get_value( $item, 'images', array() );
		$images = is_array( $images ) ? $images : array();

		if ( empty( $images ) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'remote-product-has-no-images',
				'message' => 'برای این محصول در Portal تصویری ثبت نشده است.',
			);
		}

		$image_sync = new Mobo_Core_Image_Sync();
		$result     = $image_sync->process_images( $product_id, $images, 0, false );
		$result     = is_array( $result ) ? $result : array();

		return array(
			'success'   => true,
			'skipped'   => false,
			'reason'    => 'images-enqueued',
			'message'   => 'تصاویر محصول بدون تغییر موجودی، مشخصات یا Variantها به صف امن منتقل شدند.',
			'queued'    => absint( isset( $result['queued'] ) ? $result['queued'] : 0 ),
			'processed' => absint( isset( $result['processed'] ) ? $result['processed'] : 0 ),
			'pending'   => absint( isset( $result['pending'] ) ? $result['pending'] : 0 ),
			'failed'    => absint( isset( $result['failed'] ) ? $result['failed'] : 0 ),
		);
	}

	/**
	 * Read camelCase/PascalCase payload values.
	 *
	 * @param array  $array Payload.
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}

		$pascal = ucfirst( $key );
		return array_key_exists( $pascal, $array ) ? $array[ $pascal ] : $default;
	}
}
