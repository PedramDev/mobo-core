<?php
/**
 * Legacy Mobo image refresh service.
 *
 * Finds Mobo-owned raster attachments, queues WebP replacement jobs, processes
 * the jobs in small batches, and removes old attachments only when safe.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/*
 * This component operates on Mobo Core's internal queue/map tables. Direct
 * database access is required for atomic batching and cursor updates; table
 * identifiers are generated internally and all external values are prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Image_Refresh_Service {

	/**
	 * Get combined status.
	 *
	 * @return array
	 */
	public function get_status() {
		$queue = new Mobo_Core_Image_Refresh_Queue();

		return $queue->get_status();
	}


	/**
	 * Image refresh is locked until product Repair has completed once.
	 *
	 * @return bool
	 */
	private function is_unlocked() {
		return class_exists( 'Mobo_Core_Product_Sync' ) && Mobo_Core_Product_Sync::is_repair_completed();
	}

	/**
	 * Return a standard locked result.
	 *
	 * @param array $extra Extra data.
	 * @return array
	 */
	private function locked_result( $extra = array() ) {
		return array_merge(
			array(
				'success'   => true,
				'status'    => 'locked_until_repair',
				'message'   => 'Image refresh is locked until product Repair completes.',
				'processed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'remaining' => false,
			),
			is_array( $extra ) ? $extra : array()
		);
	}

	/**
	 * Scan legacy Mobo attachments without changing data.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function scan_legacy_images( $limit = 500 ) {
		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result( array( 'checkedAt' => time(), 'scanned' => 0, 'legacyRaster' => 0, 'queueable' => 0, 'totalLegacyBytes' => 0 ) );
			update_option( 'mobo_core_image_refresh_last_scan', $result, false );
			return $result;
		}

		$attachments = $this->get_mobo_attachment_ids( $limit );
		$result      = array(
			'scanned'             => 0,
			'moboAttachments'     => 0,
			'legacyRaster'        => 0,
			'webp'                => 0,
			'missingFile'         => 0,
			'withoutProduct'      => 0,
			'withoutSourceUrl'    => 0,
			'queueable'           => 0,
			'totalLegacyBytes'    => 0,
			'checkedAt'           => time(),
		);

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$result['scanned']++;
			$result['moboAttachments']++;

			if ( $this->is_webp_attachment( $attachment_id ) ) {
				$result['webp']++;
				continue;
			}

			if ( ! $this->is_legacy_raster_attachment( $attachment_id ) ) {
				continue;
			}

			$result['legacyRaster']++;

			$file = get_attached_file( $attachment_id );
			if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
				$result['missingFile']++;
			} else {
				$result['totalLegacyBytes'] += absint( filesize( $file ) );
			}

			$product_ids = $this->find_products_using_attachment( $attachment_id );
			if ( empty( $product_ids ) ) {
				$result['withoutProduct']++;
				continue;
			}

			$image_guid = $this->get_image_guid_from_attachment( $attachment_id );
			$old_url    = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
			$has_source = false;

			foreach ( $product_ids as $product_id ) {
				$new_url = $this->find_new_source_url( $product_id, $image_guid, $old_url );
				if ( '' !== $new_url ) {
					$has_source = true;
					$result['queueable']++;
				}
			}

			if ( ! $has_source ) {
				$result['withoutSourceUrl']++;
			}
		}

		update_option( 'mobo_core_image_refresh_last_scan', $result, false );

		return $result;
	}

	/**
	 * Build refresh queue from legacy Mobo attachments.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function enqueue_legacy_images( $limit = 500 ) {
		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result( array( 'checkedAt' => time(), 'scanned' => 0, 'enqueued' => 0, 'withoutProduct' => 0, 'withoutSourceUrl' => 0 ) );
			update_option( 'mobo_core_image_refresh_last_enqueue', $result, false );
			return $result;
		}

		$queue       = new Mobo_Core_Image_Refresh_Queue();
		$attachments = $this->get_mobo_attachment_ids( $limit );
		$result      = array(
			'scanned'          => 0,
			'enqueued'         => 0,
			'skipped'          => 0,
			'withoutProduct'   => 0,
			'withoutSourceUrl' => 0,
			'checkedAt'        => time(),
		);

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$result['scanned']++;

			if ( ! $this->is_legacy_raster_attachment( $attachment_id ) ) {
				$result['skipped']++;
				continue;
			}

			$image_guid = $this->get_image_guid_from_attachment( $attachment_id );
			if ( '' === $image_guid ) {
				$result['skipped']++;
				continue;
			}

			$product_ids = $this->find_products_using_attachment( $attachment_id );
			if ( empty( $product_ids ) ) {
				$result['withoutProduct']++;
				continue;
			}

			$old_url = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
			$file    = get_attached_file( $attachment_id );
			$mime    = (string) get_post_mime_type( $attachment_id );
			$size    = is_string( $file ) && '' !== $file && file_exists( $file ) ? absint( filesize( $file ) ) : 0;

			foreach ( $product_ids as $product_id ) {
				$product_id = absint( $product_id );
				$new_url    = $this->find_new_source_url( $product_id, $image_guid, $old_url );

				if ( '' === $new_url ) {
					$result['withoutSourceUrl']++;
					continue;
				}

				$ok = $queue->enqueue(
					array(
						'product_id'        => $product_id,
						'product_guid'      => sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) ),
						'image_guid'        => $image_guid,
						'old_attachment_id' => $attachment_id,
						'old_file_path'     => is_string( $file ) ? $file : '',
						'old_mime_type'     => $mime,
						'old_file_size'     => $size,
						'new_source_url'    => $new_url,
					)
				);

				if ( $ok ) {
					$result['enqueued']++;
				} else {
					$result['skipped']++;
				}
			}
		}

		update_option( 'mobo_core_image_refresh_last_enqueue', $result, false );

		if ( $result['enqueued'] > 0 && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			Mobo_Core_Self_Runner::kick( 'image-refresh-enqueue', true );
		}

		return $result;
	}

	/**
	 * Process bounded refresh jobs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function process_queue( $limit = 0 ) {
		if ( ! $this->is_unlocked() ) {
			return $this->save_last_result( $this->locked_result() );
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_enabled', '0' ) ) {
			return $this->save_last_result(
				array(
					'success'   => true,
					'status'    => 'disabled',
					'processed' => 0,
					'failed'    => 0,
					'remaining' => false,
				)
			);
		}

		$limit = $limit > 0 ? absint( $limit ) : Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
		$limit = max( 1, min( 20, $limit ) );
		$queue = new Mobo_Core_Image_Refresh_Queue();
		$rows  = $queue->get_due_jobs( $limit );

		if ( empty( $rows ) ) {
			return $this->save_last_result(
				array(
					'success'   => true,
					'status'    => 'empty',
					'processed' => 0,
					'failed'    => 0,
					'remaining' => $queue->count_due() > 0,
				)
			);
		}

		$processed = 0;
		$failed    = 0;
		$skipped   = 0;

		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;

			if ( $id <= 0 || ! $queue->lock( $id, 180 ) ) {
				continue;
			}

			$result = $this->process_row( $row );

			if ( ! empty( $result['success'] ) ) {
				$queue->mark_done( $id, isset( $result['newAttachmentId'] ) ? absint( $result['newAttachmentId'] ) : 0, isset( $result['note'] ) ? $result['note'] : '' );
				$processed++;
				continue;
			}

			if ( ! empty( $result['skipped'] ) ) {
				$queue->mark_skipped( $id, isset( $result['message'] ) ? $result['message'] : 'Skipped.' );
				$skipped++;
				continue;
			}

			$try_count = isset( $row['try_count'] ) ? absint( $row['try_count'] ) + 1 : 1;
			$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_max_try', 5, 1, 20 );
			$queue->mark_failure( $id, isset( $result['message'] ) ? $result['message'] : 'Image refresh failed.', $try_count, $try_count >= $max_try );
			$failed++;
		}

		return $this->save_last_result(
			array(
				'success'   => true,
				'status'    => 'processed',
				'processed' => $processed,
				'failed'    => $failed,
				'skipped'   => $skipped,
				'remaining' => $queue->count_due() > 0,
			)
		);
	}

	/**
	 * Process one row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function process_row( $row ) {
		$product_id        = absint( isset( $row['product_id'] ) ? $row['product_id'] : 0 );
		$old_attachment_id = absint( isset( $row['old_attachment_id'] ) ? $row['old_attachment_id'] : 0 );
		$image_guid        = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
		$new_source_url    = esc_url_raw( (string) ( isset( $row['new_source_url'] ) ? $row['new_source_url'] : '' ) );

		if ( $product_id <= 0 || $old_attachment_id <= 0 || '' === $image_guid || '' === $new_source_url ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'Invalid refresh queue row.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'Product does not exist.' );
		}

		if ( 'attachment' !== get_post_type( $old_attachment_id ) ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'Old attachment does not exist.' );
		}

		if ( ! $this->product_uses_attachment( $product_id, $old_attachment_id ) ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'Product no longer uses old attachment.' );
		}

		$image_sync = new Mobo_Core_Image_Sync();
		if ( ! method_exists( $image_sync, 'import_image_for_refresh' ) ) {
			return array( 'success' => false, 'message' => 'Image sync refresh importer is missing.' );
		}

		$new_attachment_id = absint( $image_sync->import_image_for_refresh( $new_source_url, $product_id, $image_guid, $old_attachment_id ) );

		if ( $new_attachment_id <= 0 || 'attachment' !== get_post_type( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'WebP import failed.' );
		}

		if ( ! $this->is_valid_new_attachment( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'Imported attachment is not a valid image.' );
		}

		$this->replace_product_attachment( $product, $old_attachment_id, $new_attachment_id );
		$this->mark_refresh_completed( $product_id, $old_attachment_id, $new_attachment_id, $image_guid, $new_source_url );

		$note = 'Old attachment kept.';
		if ( Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '1' ) ) {
			$delete_check = $this->safe_delete_old_attachment( $old_attachment_id );
			$note         = ! empty( $delete_check['deleted'] ) ? 'Old attachment deleted safely.' : ( isset( $delete_check['message'] ) ? $delete_check['message'] : 'Old attachment kept.' );
		}

		return array(
			'success'         => true,
			'newAttachmentId' => $new_attachment_id,
			'note'            => $note,
		);
	}

	/**
	 * Replace attachment ID in product image/gallery.
	 *
	 * @param WC_Product $product Product object.
	 * @param int        $old_attachment_id Old attachment.
	 * @param int        $new_attachment_id New attachment.
	 * @return void
	 */
	private function replace_product_attachment( WC_Product $product, $old_attachment_id, $new_attachment_id ) {
		$product_id        = $product->get_id();
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );

		if ( absint( $product->get_image_id() ) === $old_attachment_id ) {
			$product->set_image_id( $new_attachment_id );
		}

		if ( method_exists( $product, 'get_gallery_image_ids' ) && method_exists( $product, 'set_gallery_image_ids' ) ) {
			$gallery_ids = $product->get_gallery_image_ids();
			$gallery_ids = array_map(
				static function ( $id ) use ( $old_attachment_id, $new_attachment_id ) {
					$id = absint( $id );
					return $old_attachment_id === $id ? $new_attachment_id : $id;
				},
				is_array( $gallery_ids ) ? $gallery_ids : array()
			);

			$product->set_gallery_image_ids( array_values( array_unique( array_filter( array_map( 'absint', $gallery_ids ) ) ) ) );
		}

		$product->save();
		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
	}


	/**
	 * Persist an audit trail for completed image refresh replacements.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $old_attachment_id Old attachment.
	 * @param int    $new_attachment_id New attachment.
	 * @param string $image_guid Remote image GUID.
	 * @param string $new_source_url New source URL.
	 * @return void
	 */
	private function mark_refresh_completed( $product_id, $old_attachment_id, $new_attachment_id, $image_guid, $new_source_url ) {
		$now = time();

		update_post_meta( $product_id, 'mobo_image_refresh_last_completed_at', $now );
		update_post_meta( $product_id, 'mobo_image_refresh_last_old_attachment_id', absint( $old_attachment_id ) );
		update_post_meta( $product_id, 'mobo_image_refresh_last_new_attachment_id', absint( $new_attachment_id ) );

		update_post_meta( $new_attachment_id, 'mobo_image_refresh_completed_at', $now );
		update_post_meta( $new_attachment_id, 'mobo_refreshed_from_attachment_id', absint( $old_attachment_id ) );
		update_post_meta( $new_attachment_id, 'mobo_image_refresh_source_url', esc_url_raw( (string) $new_source_url ) );
		update_post_meta( $new_attachment_id, 'mobo_image_refresh_product_id', absint( $product_id ) );

		if ( $old_attachment_id > 0 && 'attachment' === get_post_type( $old_attachment_id ) ) {
			update_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_at', $now );
			update_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', absint( $new_attachment_id ) );
		}
	}

	/**
	 * Safely delete old attachment if unused.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function safe_delete_old_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'deleted' => false, 'message' => 'Old attachment missing.' );
		}

		if ( ! $this->is_mobo_attachment( $attachment_id ) ) {
			return array( 'deleted' => false, 'message' => 'Old attachment is not marked as Mobo.' );
		}

		if ( $this->count_all_products_using_attachment( $attachment_id ) > 0 ) {
			return array( 'deleted' => false, 'message' => 'Old attachment is still used by products.' );
		}

		if ( $this->attachment_used_in_content( $attachment_id ) ) {
			return array( 'deleted' => false, 'message' => 'Old attachment is used in post content.' );
		}

		$deleted = wp_delete_attachment( $attachment_id, true );

		return array(
			'deleted' => (bool) $deleted,
			'message' => $deleted ? 'Old attachment deleted safely.' : 'wp_delete_attachment failed.',
		);
	}

	/**
	 * Find source URL for new WebP image.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @param string $old_url Old URL.
	 * @return string
	 */
	private function find_new_source_url( $product_id, $image_guid, $old_url ) {
		global $wpdb;

		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$old_url    = esc_url_raw( (string) $old_url );

		if ( '' !== $image_guid && class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$table = Mobo_Core_Image_Queue::table_name();
			$rows  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT source_url FROM {$table}
					WHERE image_guid = %s AND product_id = %d AND source_url IS NOT NULL AND source_url <> ''
					ORDER BY updated_at DESC, id DESC
					LIMIT 5",
					$image_guid,
					$product_id
				)
			);

			foreach ( is_array( $rows ) ? $rows : array() as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( '' !== $url && $url !== $old_url && $this->is_webp_url( $url ) ) {
					return $url;
				}
			}

			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT source_url FROM {$table}
					WHERE image_guid = %s AND source_url IS NOT NULL AND source_url <> ''
					ORDER BY updated_at DESC, id DESC
					LIMIT 5",
					$image_guid
				)
			);

			foreach ( is_array( $rows ) ? $rows : array() as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( '' !== $url && $url !== $old_url && $this->is_webp_url( $url ) ) {
					return $url;
				}
			}
		}

		if ( '' !== $old_url ) {
			$candidate = $this->convert_url_to_webp_candidate( $old_url );
			if ( '' !== $candidate && $candidate !== $old_url ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Convert old image URL path extension to webp as a controlled fallback.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function convert_url_to_webp_candidate( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}

		$path = (string) $parts['path'];
		if ( ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $path ) ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( 'webp' === $extension ) {
				return $url;
			}
			return '';
		}

		$parts['path']  = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
		$rebuilt        = $parts['scheme'] . '://' . $parts['host'];
		$rebuilt       .= isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$rebuilt       .= $parts['path'];
		$rebuilt       .= isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return esc_url_raw( $rebuilt );
	}

	/**
	 * Get Mobo attachment IDs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_mobo_attachment_ids( $limit ) {
		$limit = max( 1, min( 5000, absint( $limit ) ) );

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => 'image_guid',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'img_guid',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'mobo_source_url',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) ) ) );
	}

	/**
	 * Is attachment marked as Mobo.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_mobo_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		return '' !== $this->get_image_guid_from_attachment( $attachment_id ) || '' !== esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
	}

	/**
	 * Get image GUID from attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function get_image_guid_from_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );

		if ( '' === $image_guid ) {
			$image_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
		}

		return $image_guid;
	}

	/**
	 * Is old jpeg/png attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_legacy_raster_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$mime          = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$file          = (string) get_attached_file( $attachment_id );
		$ext           = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
	}

	/**
	 * Is WebP attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_webp_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$mime          = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$file          = (string) get_attached_file( $attachment_id );
		$ext           = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return 'image/webp' === $mime || 'webp' === $ext;
	}

	/**
	 * Is valid imported image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_valid_new_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$file          = get_attached_file( $attachment_id );

		return $attachment_id > 0 && is_string( $file ) && '' !== $file && file_exists( $file ) && filesize( $file ) > 0 && 0 === strpos( strtolower( (string) get_post_mime_type( $attachment_id ) ), 'image/' );
	}

	/**
	 * Find product/variation IDs using attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function find_products_using_attachment( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$ids = array();

		$featured = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND pm.meta_key = '_thumbnail_id'
				AND pm.meta_value = %s",
				(string) $attachment_id
			)
		);

		$ids = array_merge( $ids, is_array( $featured ) ? $featured : array() );

		$gallery_like = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'product'
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND pm.meta_key = '_product_image_gallery'
				AND CONCAT(',', pm.meta_value, ',') LIKE %s",
				'%,' . $wpdb->esc_like( (string) $attachment_id ) . ',%'
			)
		);

		$ids = array_merge( $ids, is_array( $gallery_like ) ? $gallery_like : array() );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		return array_values(
			array_filter(
				$ids,
				array( $this, 'is_mobo_product' )
			)
		);
	}

	/**
	 * Is product a Mobo product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function is_mobo_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 && $this->is_mobo_product( $parent_id ) ) {
				return true;
			}
		}

		return '' !== sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) )
			|| '' !== sanitize_text_field( (string) get_post_meta( $product_id, 'portal_product_id', true ) )
			|| '' !== sanitize_text_field( (string) get_post_meta( $product_id, 'mobo_portal_product_id', true ) )
			|| '' !== sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_portal_product_id', true ) )
			|| '' !== sanitize_text_field( (string) get_post_meta( $product_id, 'PortalProductId', true ) )
			|| '' !== sanitize_text_field( (string) get_post_meta( $product_id, 'mobo_url', true ) );
	}

	/**
	 * Check if a product still uses attachment.
	 *
	 * @param int $product_id Product ID.
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function product_uses_attachment( $product_id, $attachment_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$attachment_id = absint( $attachment_id );
		if ( absint( $product->get_image_id() ) === $attachment_id ) {
			return true;
		}

		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			$gallery_ids = $product->get_gallery_image_ids();
			return in_array( $attachment_id, array_map( 'absint', is_array( $gallery_ids ) ? $gallery_ids : array() ), true );
		}

		return false;
	}

	/**
	 * Count all products/variations using attachment, regardless of Mobo ownership.
	 *
	 * This is used only before deleting the old attachment. Queue creation is
	 * restricted to Mobo products, but deletion must protect every product.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function count_all_products_using_attachment( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		$featured = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type IN ('product', 'product_variation')
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND pm.meta_key = '_thumbnail_id'
					AND pm.meta_value = %s",
					(string) $attachment_id
				)
			)
		);

		$gallery = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'product'
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND pm.meta_key = '_product_image_gallery'
					AND CONCAT(',', pm.meta_value, ',') LIKE %s",
					'%,' . $wpdb->esc_like( (string) $attachment_id ) . ',%'
				)
			)
		);

		return $featured + $gallery;
	}

	/**
	 * Check if attachment is used in post content.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_used_in_content( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$url      = wp_get_attachment_url( $attachment_id );
		$patterns = array( 'wp-image-' . $attachment_id );

		if ( is_string( $url ) && '' !== $url ) {
			$patterns[] = $url;
			$patterns[] = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		}

		foreach ( array_filter( $patterns ) as $pattern ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s",
					'%' . $wpdb->esc_like( (string) $pattern ) . '%'
				)
			);

			if ( absint( $count ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect webp URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_webp_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return 'webp' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Save last result.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private function save_last_result( $result ) {
		$result['executedAt'] = time();
		update_option( 'mobo_core_image_refresh_last_result', $result, false );

		return $result;
	}
}
