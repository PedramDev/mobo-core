<?php
/**
 * Recover images for existing Mobo products that currently have an unusable
 * linked featured/gallery image.
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
	 * to products whose linked featured/gallery image is physically missing or invalid. Scanning
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

			if ( $product_id <= 0 || '' === $product_guid ) {
				continue;
			}

			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
				continue;
			}

			if ( ! $this->product_needs_image( $product_id ) ) {
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
	 * Whether a local product has any linked image that requires source recovery.
	 *
	 * Preserve the historical featured-image semantics, then extend the same
	 * physical-file validation to gallery attachments. A gallery-only gap is
	 * suppressed while the product already has active image-queue work because
	 * that queue owns replacement of the complete desired image set.
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

		$featured_id = absint( $product->get_image_id() );
		if ( ! $this->attachment_is_usable( $featured_id ) ) {
			return true;
		}

		$gallery_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) $product->get_gallery_image_ids() )
				)
			)
		);

		$gallery_needs_recovery = false;
		foreach ( $gallery_ids as $gallery_id ) {
			if ( ! $this->attachment_is_usable( $gallery_id ) ) {
				$gallery_needs_recovery = true;
				break;
			}
		}

		if ( ! $gallery_needs_recovery ) {
			return false;
		}

		/*
		 * A current image queue is product-scoped desired-state coverage. Do not
		 * launch a second product snapshot fetch merely because an older linked
		 * gallery attachment is still missing while replacement rows are active.
		 * Terminal/absent queue state intentionally falls through to recovery.
		 */
		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$queue = new Mobo_Core_Image_Queue();
			if ( $queue->count_pending_by_product( $product_id, false ) > 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check the same local attachment invariants used by missing-image recovery.
	 *
	 * Shared Media is naturally covered because get_attached_file() is filtered to
	 * the configured shared repository when the attachment is virtual/shared.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_is_usable( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		try {
			$file = get_attached_file( $attachment_id );
			if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) ) {
				return false;
			}

			$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent deletion is treated as an unhealthy attachment.
			if ( false === $size || $size <= 0 ) {
				return false;
			}

			/* Detect non-image HTTP/error payloads that happen to be non-empty files. */
			if ( function_exists( 'wp_get_image_mime' ) ) {
				$mime = strtolower( (string) wp_get_image_mime( $file ) );
				if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
					return false;
				}
			}

			return true;
		} catch ( Throwable $error ) {
			return false;
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

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'excluded-url',
				'message' => 'محصول در فهرست عدم همگام‌سازی است؛ بازیابی تصویر آن اجرا نشد.',
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

		if ( ! is_array( $response ) || ! Mobo_Core_Payload_Field_Policy::is_present( $response, array( 'data', 'Data' ) ) ) {
			return new WP_Error( 'mobo_core_missing_image_recovery_data_missing', 'Product image recovery response is missing explicit data.' );
		}

		$items = Mobo_Core_Payload_Field_Policy::value( $response, array( 'data', 'Data' ), null );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'mobo_core_missing_image_recovery_data_invalid', 'Product image recovery data is malformed.' );
		}
		$item = array();

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
		if ( empty( $item ) ) {
			return new WP_Error( 'mobo_core_missing_image_recovery_product_missing', 'Product image recovery response did not contain the requested product.' );
		}

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_payload_excluded( $item, true ) ) {
			return array(
				'success' => true,
				'skipped' => true,
				'reason'  => 'excluded-url',
				'message' => 'Snapshot فعلی محصول مستثنی است؛ تصاویر آن تغییر نکرد.',
			);
		}

		$image_field = Mobo_Core_Image_Desired_State_Policy::inspect_field( $item );
		if ( empty( $image_field['present'] ) ) {
			return new WP_Error( 'mobo_core_missing_image_recovery_images_missing', 'Current product snapshot omitted image desired state; existing image state was preserved.' );
		}
		$image_integrity = Mobo_Core_Image_Desired_State_Policy::validate_collection( $image_field['value'] );
		if ( is_wp_error( $image_integrity ) ) {
			return $image_integrity;
		}
		$images = $image_field['value'];

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
