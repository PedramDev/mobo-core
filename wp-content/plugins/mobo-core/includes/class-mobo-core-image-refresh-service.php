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

	const SCAN_CURSOR_OPTION           = 'mobo_core_image_refresh_scan_cursor';
	const ENQUEUE_CURSOR_OPTION        = 'mobo_core_image_refresh_enqueue_cursor';
	const SUBSIZE_SCAN_CURSOR_OPTION   = 'mobo_core_image_subsize_scan_cursor';
	const SUBSIZE_REPAIR_CURSOR_OPTION = 'mobo_core_image_subsize_repair_cursor';
	const REPLACED_SCAN_CURSOR_OPTION  = 'mobo_core_image_replaced_scan_cursor';
	const REPLACED_DELETE_CURSOR_OPTION = 'mobo_core_image_replaced_delete_cursor';

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
				'message'   => 'نوسازی تصاویر تا قبل از تکمیل ترمیم محصولات قفل است.',
				'processed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'remaining' => false,
			),
			is_array( $extra ) ? $extra : array()
		);
	}

	/**
	 * Shared-media mode delegates download, conversion and every registered cut
	 * to the central mirror worker. WordPress must remain read-only.
	 *
	 * @param array $extra Extra data.
	 * @return array
	 */
	private function shared_media_result( $extra = array() ) {
		return array_merge(
			array(
				'success'   => true,
				'status'    => 'managed_by_shared_media',
				'message'   => 'تولید، نوسازی و برش تصاویر توسط مخزن اشتراکی مرکزی انجام می‌شود و WordPress در این حالت فقط خواندنی است.',
				'processed' => 0,
				'failed'    => 0,
				'skipped'   => 0,
				'remaining' => false,
			),
			is_array( $extra ) ? $extra : array()
		);
	}

	private function is_shared_media_mode() {
		return class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::enabled();
	}

	/**
	 * Scan legacy Mobo attachments without changing data.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function scan_legacy_images( $limit = 500 ) {
		if ( $this->is_shared_media_mode() ) {
			$result = $this->shared_media_result(
				array( 'checkedAt' => time(), 'scanned' => 0, 'legacyRaster' => 0, 'queueable' => 0, 'totalLegacyBytes' => 0, 'cycleComplete' => true )
			);
			update_option( 'mobo_core_image_refresh_last_scan', $result, false );
			return $result;
		}
		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result(
				array(
					'checkedAt'       => time(),
					'scanned'         => 0,
					'legacyRaster'    => 0,
					'queueable'       => 0,
					'totalLegacyBytes'=> 0,
					'cursorStart'     => absint( get_option( self::SCAN_CURSOR_OPTION, 0 ) ),
					'cursorEnd'       => absint( get_option( self::SCAN_CURSOR_OPTION, 0 ) ),
					'cycleComplete'   => false,
				)
			);
			update_option( 'mobo_core_image_refresh_last_scan', $result, false );
			return $result;
		}

		$batch       = $this->get_mobo_attachment_batch( $limit, self::SCAN_CURSOR_OPTION );
		$attachments = isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array();
		$cursor_start = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$previous     = get_option( 'mobo_core_image_refresh_last_scan', array() );
		$previous     = is_array( $previous ) ? $previous : array();
		$continue_cycle = $cursor_start > 0 && ! empty( $previous ) && empty( $previous['cycleComplete'] );
		$cycle_id      = $continue_cycle && ! empty( $previous['cycleId'] )
			? sanitize_text_field( (string) $previous['cycleId'] )
			: wp_generate_uuid4();
		$result      = array(
			'scanned'             => $continue_cycle ? absint( isset( $previous['scanned'] ) ? $previous['scanned'] : 0 ) : 0,
			'moboAttachments'     => $continue_cycle ? absint( isset( $previous['moboAttachments'] ) ? $previous['moboAttachments'] : 0 ) : 0,
			'legacyRaster'        => $continue_cycle ? absint( isset( $previous['legacyRaster'] ) ? $previous['legacyRaster'] : 0 ) : 0,
			'webp'                => $continue_cycle ? absint( isset( $previous['webp'] ) ? $previous['webp'] : 0 ) : 0,
			'missingFile'         => $continue_cycle ? absint( isset( $previous['missingFile'] ) ? $previous['missingFile'] : 0 ) : 0,
			'withoutProduct'      => $continue_cycle ? absint( isset( $previous['withoutProduct'] ) ? $previous['withoutProduct'] : 0 ) : 0,
			'withoutSourceUrl'    => $continue_cycle ? absint( isset( $previous['withoutSourceUrl'] ) ? $previous['withoutSourceUrl'] : 0 ) : 0,
			'queueable'           => $continue_cycle ? absint( isset( $previous['queueable'] ) ? $previous['queueable'] : 0 ) : 0,
			'totalLegacyBytes'    => $continue_cycle ? absint( isset( $previous['totalLegacyBytes'] ) ? $previous['totalLegacyBytes'] : 0 ) : 0,
			'cursorStart'         => $cursor_start,
			'cursorEnd'           => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'cycleComplete'       => ! empty( $batch['cycleComplete'] ),
			'estimatedTotal'       => isset( $batch['estimatedTotal'] ) ? absint( $batch['estimatedTotal'] ) : 0,
			'cycleId'             => $cycle_id,
			'cycleStartedAt'      => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
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
				$result['totalLegacyBytes'] += $this->get_attachment_family_size( $attachment_id );
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
		if ( $this->is_shared_media_mode() ) {
			$result = $this->shared_media_result(
				array( 'checkedAt' => time(), 'scanned' => 0, 'enqueued' => 0, 'cycleComplete' => true, 'processingStarted' => false )
			);
			update_option( 'mobo_core_image_refresh_last_enqueue', $result, false );
			return $result;
		}
		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result(
				array(
					'checkedAt'       => time(),
					'scanned'         => 0,
					'enqueued'        => 0,
					'withoutProduct'  => 0,
					'withoutSourceUrl'=> 0,
					'cursorStart'     => absint( get_option( self::ENQUEUE_CURSOR_OPTION, 0 ) ),
					'cursorEnd'       => absint( get_option( self::ENQUEUE_CURSOR_OPTION, 0 ) ),
					'cycleComplete'   => false,
				)
			);
			update_option( 'mobo_core_image_refresh_last_enqueue', $result, false );
			return $result;
		}

		$queue       = new Mobo_Core_Image_Refresh_Queue();
		$batch       = $this->get_mobo_attachment_batch( $limit, self::ENQUEUE_CURSOR_OPTION );
		$attachments = isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array();
		$cursor_start = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$previous     = get_option( 'mobo_core_image_refresh_last_enqueue', array() );
		$previous     = is_array( $previous ) ? $previous : array();
		$continue_cycle = $cursor_start > 0 && ! empty( $previous ) && empty( $previous['cycleComplete'] );
		$source_scan   = get_option( 'mobo_core_image_refresh_last_scan', array() );
		$source_scan   = is_array( $source_scan ) ? $source_scan : array();
		$source_scan_cycle_id = ! empty( $source_scan['cycleId'] )
			? sanitize_text_field( (string) $source_scan['cycleId'] )
			: '';
		if ( $continue_cycle && ! empty( $previous['sourceScanCycleId'] ) ) {
			$source_scan_cycle_id = sanitize_text_field( (string) $previous['sourceScanCycleId'] );
		}

		if ( ! $continue_cycle ) {
			$this->invalidate_post_queue_verification_state();
		}
		$result      = array(
			'scanned'          => $continue_cycle ? absint( isset( $previous['scanned'] ) ? $previous['scanned'] : 0 ) : 0,
			'enqueued'         => $continue_cycle ? absint( isset( $previous['enqueued'] ) ? $previous['enqueued'] : 0 ) : 0,
			'requeued'         => $continue_cycle ? absint( isset( $previous['requeued'] ) ? $previous['requeued'] : 0 ) : 0,
			'alreadyQueued'    => $continue_cycle ? absint( isset( $previous['alreadyQueued'] ) ? $previous['alreadyQueued'] : 0 ) : 0,
			'alreadyDone'      => $continue_cycle ? absint( isset( $previous['alreadyDone'] ) ? $previous['alreadyDone'] : 0 ) : 0,
			'skipped'          => $continue_cycle ? absint( isset( $previous['skipped'] ) ? $previous['skipped'] : 0 ) : 0,
			'withoutProduct'   => $continue_cycle ? absint( isset( $previous['withoutProduct'] ) ? $previous['withoutProduct'] : 0 ) : 0,
			'withoutSourceUrl' => $continue_cycle ? absint( isset( $previous['withoutSourceUrl'] ) ? $previous['withoutSourceUrl'] : 0 ) : 0,
			'cursorStart'      => $cursor_start,
			'cursorEnd'        => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'cycleComplete'    => ! empty( $batch['cycleComplete'] ),
			'estimatedTotal'    => isset( $batch['estimatedTotal'] ) ? absint( $batch['estimatedTotal'] ) : 0,
			'sourceScanCycleId'=> $source_scan_cycle_id,
			'cycleStartedAt'   => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
			'checkedAt'        => time(),
			'processingStarted'=> false,
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
			$size    = $this->get_attachment_family_size( $attachment_id );

			foreach ( $product_ids as $product_id ) {
				$product_id = absint( $product_id );
				$new_url    = $this->find_new_source_url( $product_id, $image_guid, $old_url );

				if ( '' === $new_url ) {
					$result['withoutSourceUrl']++;
					continue;
				}

				$enqueue_result = method_exists( $queue, 'enqueue_with_result' )
					? $queue->enqueue_with_result(
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
					)
					: array( 'success' => $queue->enqueue( array(
						'product_id'        => $product_id,
						'product_guid'      => sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) ),
						'image_guid'        => $image_guid,
						'old_attachment_id' => $attachment_id,
						'old_file_path'     => is_string( $file ) ? $file : '',
						'old_mime_type'     => $mime,
						'old_file_size'     => $size,
						'new_source_url'    => $new_url,
					) ), 'action' => 'inserted' );

				$action = isset( $enqueue_result['action'] ) ? sanitize_key( (string) $enqueue_result['action'] ) : '';
				if ( empty( $enqueue_result['success'] ) ) {
					$result['skipped']++;
				} elseif ( 'inserted' === $action ) {
					$result['enqueued']++;
				} elseif ( 'requeued' === $action ) {
					$result['requeued']++;
				} elseif ( 'already_done' === $action ) {
					$result['alreadyDone']++;
				} else {
					$result['alreadyQueued']++;
				}
			}
		}

		update_option( 'mobo_core_image_refresh_last_enqueue', $result, false );

		/* Queue construction never processes media. It only invalidates older
		 * downstream audit reports because they cannot certify a newly built queue. */
		return $result;
	}

	/**
	 * Process bounded refresh jobs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function process_queue( $limit = 0 ) {
		if ( $this->is_shared_media_mode() ) {
			return $this->save_last_result( $this->shared_media_result() );
		}
		if ( ! $this->is_unlocked() ) {
			return $this->save_last_result( $this->locked_result() );
		}

		$last_scan    = get_option( 'mobo_core_image_refresh_last_scan', array() );
		$last_enqueue = get_option( 'mobo_core_image_refresh_last_enqueue', array() );
		$scan_time    = is_array( $last_scan ) ? absint( isset( $last_scan['checkedAt'] ) ? $last_scan['checkedAt'] : 0 ) : 0;
		$enqueue_time = is_array( $last_enqueue ) ? absint( isset( $last_enqueue['checkedAt'] ) ? $last_enqueue['checkedAt'] : 0 ) : 0;
		$scan_cycle_id = is_array( $last_scan ) && ! empty( $last_scan['cycleId'] )
			? sanitize_text_field( (string) $last_scan['cycleId'] )
			: '';
		$enqueue_scan_cycle_id = is_array( $last_enqueue ) && ! empty( $last_enqueue['sourceScanCycleId'] )
			? sanitize_text_field( (string) $last_enqueue['sourceScanCycleId'] )
			: '';
		$queue_matches_scan = '' !== $scan_cycle_id
			? hash_equals( $scan_cycle_id, $enqueue_scan_cycle_id )
			: $enqueue_time >= $scan_time;
		$scan_ready   = $scan_time > 0 && ! empty( $last_scan['cycleComplete'] );
		$queue_ready  = $enqueue_time > 0 && ! empty( $last_enqueue['cycleComplete'] ) && $queue_matches_scan;

		if ( ! $scan_ready || ! $queue_ready ) {
			return $this->save_last_result(
				array(
					'success'   => false,
					'status'    => 'workflow_blocked',
					'processed' => 0,
					'failed'    => 0,
					'remaining' => false,
					'message'   => 'پردازش صف تا تکمیل کامل مرحله ۱ و مرحله ۲ متوقف است.',
				)
			);
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

		$this->invalidate_post_queue_verification_state();

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
				$queue->mark_skipped( $id, isset( $result['message'] ) ? $result['message'] : 'این ردیف بدون تغییر رد شد.' );
				$skipped++;
				continue;
			}

			$try_count = isset( $row['try_count'] ) ? absint( $row['try_count'] ) + 1 : 1;
			$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_max_try', 5, 1, 20 );
			$queue->mark_failure( $id, isset( $result['message'] ) ? $result['message'] : 'نوسازی تصویر ناموفق بود.', $try_count, $try_count >= $max_try );
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
			return array( 'success' => false, 'skipped' => true, 'message' => 'اطلاعات این ردیف صف ناقص یا نامعتبر است.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'محصول مربوط به این ردیف دیگر وجود ندارد.' );
		}

		if ( 'attachment' !== get_post_type( $old_attachment_id ) ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'پیوست تصویر قدیمی دیگر وجود ندارد.' );
		}

		if ( ! $this->product_uses_attachment( $product_id, $old_attachment_id ) ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'محصول دیگر از تصویر قدیمی این ردیف استفاده نمی کند.' );
		}

		$image_sync = new Mobo_Core_Image_Sync();
		if ( ! method_exists( $image_sync, 'import_image_for_refresh' ) ) {
			return array( 'success' => false, 'message' => 'بخش دریافت تصویر جدید در افزونه در دسترس نیست.' );
		}

		$new_attachment_id = absint( $image_sync->import_image_for_refresh( $new_source_url, $product_id, $image_guid, $old_attachment_id ) );

		if ( $new_attachment_id <= 0 || 'attachment' !== get_post_type( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'دریافت یا ثبت تصویر WebP ناموفق بود.' );
		}

		if ( ! $this->is_valid_new_attachment( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'فایل دریافت شده یک تصویر معتبر نیست.' );
		}

		if ( $new_attachment_id === $old_attachment_id || ! $this->is_webp_attachment( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'تصویر جایگزین یک فایل WebP مستقل و معتبر نیست.' );
		}

		$generate_subsizes = Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_generate_subsizes', '1' );
		if ( $generate_subsizes ) {
			$subsize_result = $this->ensure_attachment_subsizes( $new_attachment_id );
		} else {
			$subsize_health = $this->inspect_attachment_subsizes( $new_attachment_id );
			$subsize_result = array(
				'success'    => ! empty( $subsize_health['healthy'] ),
				'generated'  => 0,
				'registered' => isset( $subsize_health['registered'] ) ? absint( $subsize_health['registered'] ) : 0,
				'message'    => isset( $subsize_health['message'] ) ? (string) $subsize_health['message'] : 'وضعیت برش های تصویر مشخص نیست.',
			);
		}

		if ( empty( $subsize_result['success'] ) ) {
			return array(
				'success' => false,
				'message' => 'تصویر WebP دریافت شد، اما کنترل نهایی برش های وردپرس ناموفق بود: ' . sanitize_text_field( isset( $subsize_result['message'] ) ? (string) $subsize_result['message'] : 'خطای نامشخص' ),
			);
		}

		$this->replace_product_attachment( $product, $old_attachment_id, $new_attachment_id );
		$this->mark_refresh_completed( $product_id, $old_attachment_id, $new_attachment_id, $image_guid, $new_source_url );

		$note = 'تصویر قدیمی نگه داشته شد.';
		if ( Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ) ) {
			$delete_check = $this->safe_delete_old_attachment( $old_attachment_id, $new_attachment_id );
			$note         = ! empty( $delete_check['deleted'] ) ? ( isset( $delete_check['message'] ) ? (string) $delete_check['message'] : 'تصویر قدیمی با موفقیت و به صورت امن حذف شد.' ) : ( isset( $delete_check['message'] ) ? $delete_check['message'] : 'تصویر قدیمی نگه داشته شد.' );
		}

		$note .= ' برش های WebP کنترل نهایی شدند؛ تعداد برش های ثبت شده: ' . absint( isset( $subsize_result['registered'] ) ? $subsize_result['registered'] : 0 ) . '، فایل جدید ساخته شده: ' . absint( isset( $subsize_result['generated'] ) ? $subsize_result['generated'] : 0 ) . '.';

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

		if ( class_exists( 'Mobo_Core_Product_Activity' ) ) {
			Mobo_Core_Product_Activity::mark( $product_id, 'image_refresh', $now );
		}

		if ( $old_attachment_id > 0 && 'attachment' === get_post_type( $old_attachment_id ) ) {
			update_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_at', $now );
			update_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', absint( $new_attachment_id ) );
		}
	}

	/**
	 * Safely delete old attachment if unused.
	 *
	 * @param int $attachment_id Old attachment ID.
	 * @param int $new_attachment_id Replacement attachment ID.
	 * @return array
	 */
	private function safe_delete_old_attachment( $attachment_id, $new_attachment_id = 0 ) {
		$attachment_id     = absint( $attachment_id );
		$new_attachment_id = absint( $new_attachment_id );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'deleted' => false, 'message' => 'تصویر قدیمی وجود ندارد.' );
		}

		if ( ! $this->is_mobo_attachment( $attachment_id ) ) {
			return array( 'deleted' => false, 'message' => 'تصویر قدیمی به عنوان تصویر موبو ثبت نشده است.' );
		}

		if ( $this->count_all_products_using_attachment( $attachment_id ) > 0 ) {
			return array( 'deleted' => false, 'message' => 'تصویر قدیمی هنوز توسط یک یا چند محصول استفاده می شود.' );
		}

		if ( $this->attachment_has_external_references( $attachment_id ) ) {
			return array( 'deleted' => false, 'message' => 'تصویر قدیمی هنوز در محتوا، متادیتا، دسته بندی یا تنظیمات سایت مرجع دارد.' );
		}

		$old_file          = get_attached_file( $attachment_id );
		$old_file          = is_string( $old_file ) ? $this->normalize_file_path( $old_file ) : '';
		$family_snapshot   = $this->get_legacy_attachment_family_paths( $attachment_id );
		$new_file          = $new_attachment_id > 0 ? get_attached_file( $new_attachment_id ) : '';
		$new_file          = is_string( $new_file ) ? $this->normalize_file_path( $new_file ) : '';
		$deleted           = wp_delete_attachment( $attachment_id, true );

		if ( ! $deleted ) {
			return array( 'deleted' => false, 'message' => 'حذف پیوست قدیمی توسط وردپرس ناموفق بود.' );
		}

		$leftover_result = array( 'deletedFiles' => 0, 'bytes' => 0, 'keptFiles' => 0 );
		if ( Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_cleanup_leftover_subsizes', '1' ) ) {
			$leftover_result = $this->cleanup_leftover_legacy_family( $family_snapshot, $old_file, $new_file );
		}

		$message = 'تصویر قدیمی و برش های ثبت شده آن با موفقیت و به صورت امن حذف شدند.';
		if ( ! empty( $leftover_result['deletedFiles'] ) ) {
			$message .= ' تعداد برش های جا مانده و ثبت نشده که حذف شد: ' . absint( $leftover_result['deletedFiles'] ) . '.';
		}
		if ( ! empty( $leftover_result['keptFiles'] ) ) {
			$message .= ' تعداد فایل های دارای مرجع یا ثبت شده که نگه داشته شد: ' . absint( $leftover_result['keptFiles'] ) . '.';
		}

		return array(
			'deleted'              => true,
			'message'              => $message,
			'leftoverDeletedFiles' => isset( $leftover_result['deletedFiles'] ) ? absint( $leftover_result['deletedFiles'] ) : 0,
			'leftoverBytes'        => isset( $leftover_result['bytes'] ) ? absint( $leftover_result['bytes'] ) : 0,
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
	private function get_mobo_attachment_batch( $limit, $cursor_option ) {
		global $wpdb;

		$limit         = max( 1, min( 5000, absint( $limit ) ) );
		$cursor_option = sanitize_key( (string) $cursor_option );
		$cursor_start  = absint( get_option( $cursor_option, 0 ) );
		$fetch_limit   = $limit + 1;

		$estimated_total = absint(
			$wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} marker
					ON marker.post_id = p.ID
					AND marker.meta_key IN ('image_guid', 'img_guid', 'mobo_source_url')
				WHERE p.post_type = 'attachment'
				AND p.post_status IN ('inherit', 'private')"
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} marker
					ON marker.post_id = p.ID
					AND marker.meta_key IN ('image_guid', 'img_guid', 'mobo_source_url')
				WHERE p.post_type = 'attachment'
				AND p.post_status IN ('inherit', 'private')
				AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor_start,
				$fetch_limit
			)
		);

		$ids            = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		$has_more       = count( $ids ) > $limit;
		$ids            = array_slice( $ids, 0, $limit );
		$cursor_end     = ! empty( $ids ) ? absint( end( $ids ) ) : $cursor_start;
		$cycle_complete = ! $has_more;

		if ( $cycle_complete ) {
			update_option( $cursor_option, 0, false );
		} else {
			update_option( $cursor_option, $cursor_end, false );
		}

		return array(
			'ids'           => $ids,
			'cursorStart'   => $cursor_start,
			'cursorEnd'     => $cursor_end,
			'cycleComplete' => $cycle_complete,
			'estimatedTotal' => $estimated_total,
		);
	}



	/**
	 * Get old Mobo attachments that were already replaced by a WebP attachment.
	 *
	 * @param int    $limit Limit.
	 * @param string $cursor_option Cursor option.
	 * @return array
	 */
	private function get_replaced_attachment_batch( $limit, $cursor_option ) {
		global $wpdb;

		$limit         = max( 1, min( 5000, absint( $limit ) ) );
		$cursor_option = sanitize_key( (string) $cursor_option );
		$cursor_start  = absint( get_option( $cursor_option, 0 ) );
		$fetch_limit   = $limit + 1;

		$estimated_total = absint(
			$wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
					AND pm.meta_key = 'mobo_image_refresh_replaced_by_attachment_id'
				WHERE p.post_type = 'attachment'
				AND p.post_status IN ('inherit', 'private')"
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
					AND pm.meta_key = 'mobo_image_refresh_replaced_by_attachment_id'
				WHERE p.post_type = 'attachment'
				AND p.post_status IN ('inherit', 'private')
				AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor_start,
				$fetch_limit
			)
		);

		$ids            = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		$has_more       = count( $ids ) > $limit;
		$ids            = array_slice( $ids, 0, $limit );
		$cursor_end     = ! empty( $ids ) ? absint( end( $ids ) ) : $cursor_start;
		$cycle_complete = ! $has_more;

		update_option( $cursor_option, $cycle_complete ? 0 : $cursor_end, false );

		return array(
			'ids'           => $ids,
			'cursorStart'   => $cursor_start,
			'cursorEnd'     => $cursor_end,
			'cycleComplete' => $cycle_complete,
			'estimatedTotal' => $estimated_total,
		);
	}

	/**
	 * Audit or repair currently registered WordPress cuts for Mobo WebP images.
	 *
	 * The scan is read-only when $repair is false. Repair mode only recreates
	 * missing metadata/files for the replacement WebP attachment and never
	 * changes product image IDs or deletes legacy files.
	 *
	 * @param int  $limit Limit.
	 * @param bool $repair Whether missing cuts should be regenerated.
	 * @return array
	 */
	public function audit_webp_subsizes( $limit = 500, $repair = false ) {
		if ( $this->is_shared_media_mode() ) {
			return $this->shared_media_result( array( 'checkedAt' => time(), 'scanned' => 0, 'healthy' => 0, 'unhealthy' => 0, 'repaired' => 0, 'cycleComplete' => true ) );
		}
		$repair        = (bool) $repair;
		$cursor_option = $repair ? self::SUBSIZE_REPAIR_CURSOR_OPTION : self::SUBSIZE_SCAN_CURSOR_OPTION;
		$option_name   = $repair ? 'mobo_core_image_refresh_last_subsize_repair' : 'mobo_core_image_refresh_last_subsize_scan';

		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result(
				array(
					'mode'          => $repair ? 'repair' : 'scan',
					'checkedAt'     => time(),
					'scanned'       => 0,
					'webpChecked'   => 0,
					'healthy'       => 0,
					'needsRepair'   => 0,
					'repaired'      => 0,
					'generatedFiles'=> 0,
					'failed'        => 0,
					'issues'        => array(),
					'cursorStart'   => absint( get_option( $cursor_option, 0 ) ),
					'cursorEnd'     => absint( get_option( $cursor_option, 0 ) ),
					'cycleComplete' => false,
				)
			);
			update_option( $option_name, $result, false );
			return $result;
		}

		$batch       = $this->get_mobo_attachment_batch( $limit, $cursor_option );
		$attachments = isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array();
		$cursor_start = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$previous     = get_option( $option_name, array() );
		$previous     = is_array( $previous ) ? $previous : array();
		$continue_cycle = $cursor_start > 0
			&& ! empty( $previous )
			&& empty( $previous['cycleComplete'] )
			&& ( isset( $previous['mode'] ) ? (string) $previous['mode'] : '' ) === ( $repair ? 'repair' : 'scan' );

		$result      = array(
			'mode'              => $repair ? 'repair' : 'scan',
			'scanned'           => $continue_cycle ? absint( isset( $previous['scanned'] ) ? $previous['scanned'] : 0 ) : 0,
			'webpChecked'       => $continue_cycle ? absint( isset( $previous['webpChecked'] ) ? $previous['webpChecked'] : 0 ) : 0,
			'healthy'           => $continue_cycle ? absint( isset( $previous['healthy'] ) ? $previous['healthy'] : 0 ) : 0,
			'needsRepair'       => $continue_cycle ? absint( isset( $previous['needsRepair'] ) ? $previous['needsRepair'] : 0 ) : 0,
			'repaired'          => $continue_cycle ? absint( isset( $previous['repaired'] ) ? $previous['repaired'] : 0 ) : 0,
			'generatedFiles'    => $continue_cycle ? absint( isset( $previous['generatedFiles'] ) ? $previous['generatedFiles'] : 0 ) : 0,
			'failed'            => $continue_cycle ? absint( isset( $previous['failed'] ) ? $previous['failed'] : 0 ) : 0,
			'unsupportedEditor' => $continue_cycle ? absint( isset( $previous['unsupportedEditor'] ) ? $previous['unsupportedEditor'] : 0 ) : 0,
			'missingOriginal'   => $continue_cycle ? absint( isset( $previous['missingOriginal'] ) ? $previous['missingOriginal'] : 0 ) : 0,
			'issues'            => $continue_cycle && ! empty( $previous['issues'] ) && is_array( $previous['issues'] ) ? array_slice( $previous['issues'], 0, 20 ) : array(),
			'cursorStart'       => $cursor_start,
			'cursorEnd'         => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'cycleComplete'     => ! empty( $batch['cycleComplete'] ),
			'estimatedTotal'     => isset( $batch['estimatedTotal'] ) ? absint( $batch['estimatedTotal'] ) : 0,
			'cycleStartedAt'    => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
			'checkedAt'         => time(),
		);

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$result['scanned']++;

			if ( ! $this->is_webp_attachment( $attachment_id ) ) {
				continue;
			}

			$result['webpChecked']++;
			$health = $this->inspect_attachment_subsizes( $attachment_id );

			if ( isset( $health['editorSupported'] ) && ! $health['editorSupported'] ) {
				$result['unsupportedEditor']++;
			}
			if ( 'missing_original' === ( isset( $health['code'] ) ? $health['code'] : '' ) ) {
				$result['missingOriginal']++;
			}

			if ( ! empty( $health['healthy'] ) ) {
				$result['healthy']++;
				if ( isset( $health['editorSupported'] ) && ! $health['editorSupported'] && count( $result['issues'] ) < 20 ) {
					$file = get_attached_file( $attachment_id );
					$result['issues'][] = array(
						'attachmentId' => $attachment_id,
						'file'         => is_string( $file ) ? $this->relative_upload_path( $file ) : '',
						'missingSizes' => array(),
						'missingFiles' => array(),
						'wrongFormatFiles' => array(),
						'message'      => sanitize_text_field( isset( $health['message'] ) ? (string) $health['message'] : 'برش های فعلی کامل هستند، اما موتور تصویر سرور امکان بازسازی WebP را ندارد.' ),
					);
				}
				continue;
			}

			$result['needsRepair']++;

			if ( $repair ) {
				$repair_result = $this->ensure_attachment_subsizes( $attachment_id );
				if ( ! empty( $repair_result['success'] ) ) {
					$verified = $this->inspect_attachment_subsizes( $attachment_id );
					if ( ! empty( $verified['healthy'] ) ) {
						$result['repaired']++;
						$result['generatedFiles'] += absint( isset( $repair_result['generated'] ) ? $repair_result['generated'] : 0 );
						continue;
					}
					$health = $verified;
				} else {
					$health['message'] = isset( $repair_result['message'] ) ? (string) $repair_result['message'] : 'بازسازی برش ها ناموفق بود.';
				}
				$result['failed']++;
			}

			if ( count( $result['issues'] ) < 20 ) {
				$file = get_attached_file( $attachment_id );
				$result['issues'][] = array(
					'attachmentId' => $attachment_id,
					'file'         => is_string( $file ) ? $this->relative_upload_path( $file ) : '',
					'missingSizes' => isset( $health['missingSizes'] ) && is_array( $health['missingSizes'] ) ? array_values( $health['missingSizes'] ) : array(),
					'missingFiles' => isset( $health['missingFiles'] ) && is_array( $health['missingFiles'] ) ? array_values( $health['missingFiles'] ) : array(),
					'wrongFormatFiles' => isset( $health['wrongFormatFiles'] ) && is_array( $health['wrongFormatFiles'] ) ? array_values( $health['wrongFormatFiles'] ) : array(),
					'message'      => sanitize_text_field( isset( $health['message'] ) ? (string) $health['message'] : 'وضعیت برش های تصویر کامل نیست.' ),
				);
			}
		}

		update_option( $option_name, $result, false );
		return $result;
	}


	/**
	 * Audit or delete legacy attachments that were already replaced successfully.
	 *
	 * This handles the safe dry-run workflow where product replacement was allowed
	 * while old-attachment deletion was disabled. It never treats a registered old
	 * attachment as an orphan; instead it follows the durable replacement metadata,
	 * verifies the replacement WebP and its cuts, then reuses the same conservative
	 * deletion checks as normal queue processing.
	 *
	 * @param int  $limit Limit.
	 * @param bool $delete Whether safe deletion should be attempted.
	 * @return array
	 */
	public function audit_replaced_legacy_attachments( $limit = 500, $delete = false ) {
		if ( $this->is_shared_media_mode() ) {
			return $this->shared_media_result( array( 'checkedAt' => time(), 'scanned' => 0, 'safeToDelete' => 0, 'deleted' => 0, 'cycleComplete' => true ) );
		}
		$delete        = (bool) $delete;
		$cursor_option = $delete ? self::REPLACED_DELETE_CURSOR_OPTION : self::REPLACED_SCAN_CURSOR_OPTION;
		$option_name   = $delete ? 'mobo_core_image_refresh_last_replaced_delete' : 'mobo_core_image_refresh_last_replaced_scan';

		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result(
				array(
					'mode'               => $delete ? 'delete' : 'scan',
					'scanned'            => 0,
					'ready'              => 0,
					'deleted'            => 0,
					'failed'             => 0,
					'stillUsed'          => 0,
					'externalReferences' => 0,
					'invalidReplacement' => 0,
					'invalidSubsizes'    => 0,
					'issues'             => array(),
					'cursorStart'        => absint( get_option( $cursor_option, 0 ) ),
					'cursorEnd'          => absint( get_option( $cursor_option, 0 ) ),
					'cycleComplete'      => false,
					'checkedAt'          => time(),
				)
			);
			update_option( $option_name, $result, false );
			return $result;
		}

		if ( $delete && ! Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ) ) {
			$result = array(
				'mode'               => 'delete',
				'status'             => 'disabled',
				'message'            => 'ابتدا گزینه حذف پیوست قدیمی بعد از جایگزینی امن را فعال و تنظیمات را ذخیره کنید.',
				'scanned'            => 0,
				'ready'              => 0,
				'deleted'            => 0,
				'failed'             => 0,
				'stillUsed'          => 0,
				'externalReferences' => 0,
				'invalidReplacement' => 0,
				'invalidSubsizes'    => 0,
				'issues'             => array(),
				'cursorStart'        => absint( get_option( $cursor_option, 0 ) ),
				'cursorEnd'          => absint( get_option( $cursor_option, 0 ) ),
				'cycleComplete'      => false,
				'checkedAt'          => time(),
			);
			update_option( $option_name, $result, false );
			return $result;
		}

		$batch          = $this->get_replaced_attachment_batch( $limit, $cursor_option );
		$attachment_ids = isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array();
		$cursor_start   = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$previous       = get_option( $option_name, array() );
		$previous       = is_array( $previous ) ? $previous : array();
		$continue_cycle = $cursor_start > 0
			&& ! empty( $previous )
			&& empty( $previous['cycleComplete'] )
			&& ( isset( $previous['mode'] ) ? (string) $previous['mode'] : '' ) === ( $delete ? 'delete' : 'scan' );

		$result = array(
			'mode'               => $delete ? 'delete' : 'scan',
			'status'             => 'processed',
			'scanned'            => $continue_cycle ? absint( isset( $previous['scanned'] ) ? $previous['scanned'] : 0 ) : 0,
			'ready'              => $continue_cycle ? absint( isset( $previous['ready'] ) ? $previous['ready'] : 0 ) : 0,
			'deleted'            => $continue_cycle ? absint( isset( $previous['deleted'] ) ? $previous['deleted'] : 0 ) : 0,
			'failed'             => $continue_cycle ? absint( isset( $previous['failed'] ) ? $previous['failed'] : 0 ) : 0,
			'stillUsed'          => $continue_cycle ? absint( isset( $previous['stillUsed'] ) ? $previous['stillUsed'] : 0 ) : 0,
			'externalReferences' => $continue_cycle ? absint( isset( $previous['externalReferences'] ) ? $previous['externalReferences'] : 0 ) : 0,
			'invalidReplacement' => $continue_cycle ? absint( isset( $previous['invalidReplacement'] ) ? $previous['invalidReplacement'] : 0 ) : 0,
			'invalidSubsizes'    => $continue_cycle ? absint( isset( $previous['invalidSubsizes'] ) ? $previous['invalidSubsizes'] : 0 ) : 0,
			'issues'             => $continue_cycle && ! empty( $previous['issues'] ) && is_array( $previous['issues'] ) ? array_slice( $previous['issues'], 0, 20 ) : array(),
			'cursorStart'        => $cursor_start,
			'cursorEnd'          => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'cycleComplete'      => ! empty( $batch['cycleComplete'] ),
			'estimatedTotal'      => isset( $batch['estimatedTotal'] ) ? absint( $batch['estimatedTotal'] ) : 0,
			'cycleStartedAt'     => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
			'checkedAt'          => time(),
		);

		foreach ( $attachment_ids as $old_attachment_id ) {
			$old_attachment_id = absint( $old_attachment_id );
			$result['scanned']++;
			$new_attachment_id = absint( get_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', true ) );
			$reason            = '';

			if ( $new_attachment_id <= 0 || ! $this->is_valid_new_attachment( $new_attachment_id ) || ! $this->is_webp_attachment( $new_attachment_id ) ) {
				$result['invalidReplacement']++;
				$reason = 'تصویر WebP جایگزین وجود ندارد یا معتبر نیست.';
			} else {
				$health = $this->inspect_attachment_subsizes( $new_attachment_id );
				if ( empty( $health['healthy'] ) ) {
					$result['invalidSubsizes']++;
					$reason = 'برش های تصویر WebP جایگزین کامل نیست: ' . ( isset( $health['message'] ) ? (string) $health['message'] : 'خطای نامشخص' );
				} elseif ( $this->count_all_products_using_attachment( $old_attachment_id ) > 0 ) {
					$result['stillUsed']++;
					$reason = 'این پیوست قدیمی هنوز توسط یک یا چند محصول استفاده می شود.';
				} elseif ( $this->attachment_has_external_references( $old_attachment_id ) ) {
					$result['externalReferences']++;
					$reason = 'این پیوست قدیمی هنوز در محتوا، متادیتا، دسته بندی یا تنظیمات سایت مرجع دارد.';
				} else {
					$result['ready']++;
					if ( $delete ) {
						$delete_result = $this->safe_delete_old_attachment( $old_attachment_id, $new_attachment_id );
						if ( ! empty( $delete_result['deleted'] ) ) {
							$result['deleted']++;
							continue;
						}
						$result['failed']++;
						$reason = isset( $delete_result['message'] ) ? (string) $delete_result['message'] : 'حذف پیوست قدیمی ناموفق بود.';
					} else {
						continue;
					}
				}
			}

			if ( count( $result['issues'] ) < 20 ) {
				$old_file = get_attached_file( $old_attachment_id );
				$result['issues'][] = array(
					'oldAttachmentId' => $old_attachment_id,
					'oldFile'         => is_string( $old_file ) ? $this->relative_upload_path( $old_file ) : '',
					'newAttachmentId' => $new_attachment_id,
					'reason'          => sanitize_text_field( $reason ),
				);
			}
		}

		update_option( $option_name, $result, false );
		return $result;
	}

	/**
	 * Inspect attachment cuts without modifying metadata or files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function inspect_attachment_subsizes( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) || ! $this->is_webp_attachment( $attachment_id ) ) {
			return array(
				'healthy'         => false,
				'code'            => 'invalid_attachment',
				'message'         => 'پیوست WebP نامعتبر است.',
				'missingSizes'    => array(),
				'missingFiles'    => array(),
				'wrongFormatFiles'=> array(),
				'registered'      => 0,
				'editorSupported' => false,
			);
		}

		$this->load_media_dependencies();
		$file = get_attached_file( $attachment_id );
		$file = is_string( $file ) ? $this->normalize_file_path( $file ) : '';
		if ( '' === $file || ! is_file( $file ) || filesize( $file ) <= 0 ) {
			return array(
				'healthy'         => false,
				'code'            => 'missing_original',
				'message'         => 'فایل اصلی WebP وجود ندارد یا خالی است.',
				'missingSizes'    => array(),
				'missingFiles'    => array( '' !== $file ? basename( $file ) : 'فایل اصلی' ),
				'wrongFormatFiles'=> array(),
				'registered'      => 0,
				'editorSupported' => false,
			);
		}

		$metadata       = wp_get_attachment_metadata( $attachment_id );
		$metadata_valid = is_array( $metadata )
			&& ! empty( $metadata['file'] )
			&& ! empty( $metadata['width'] )
			&& ! empty( $metadata['height'] );
		$registered         = is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0;
		$missing_sizes      = array();
		$wrong_format_files = array();

		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				$size_file = is_array( $size_data ) && ! empty( $size_data['file'] ) ? basename( (string) $size_data['file'] ) : '';
				if ( '' !== $size_file && 'webp' !== strtolower( pathinfo( $size_file, PATHINFO_EXTENSION ) ) ) {
					$wrong_format_files[] = $size_file;
				}
			}
		}
		$wrong_format_files = array_values( array_unique( array_filter( $wrong_format_files ) ) );

		if ( $metadata_valid && function_exists( 'wp_get_missing_image_subsizes' ) ) {
			$missing = wp_get_missing_image_subsizes( $attachment_id );
			if ( is_array( $missing ) ) {
				$missing_sizes = array_keys( $missing );
			}
		}

		$missing_files = array();
		foreach ( $this->get_attachment_registered_absolute_paths( $attachment_id ) as $registered_path ) {
			if ( ! is_file( $registered_path ) || filesize( $registered_path ) <= 0 ) {
				$missing_files[] = basename( $registered_path );
			}
		}
		$missing_files = array_values( array_unique( array_filter( $missing_files ) ) );

		$editor           = wp_get_image_editor( $file );
		$editor_supported = ! is_wp_error( $editor );
		$healthy          = $metadata_valid && empty( $missing_sizes ) && empty( $missing_files ) && empty( $wrong_format_files );

		if ( $healthy && $editor_supported ) {
			$code    = 'healthy';
			$message = 'تمام برش های لازم موجود هستند و موتور تصویر سرور نیز امکان بازسازی WebP را دارد.';
		} elseif ( $healthy ) {
			$code    = 'healthy_editor_unavailable';
			$message = 'برش های فعلی کامل هستند، اما موتور تصویر سرور امکان بازسازی WebP را ندارد: ' . $editor->get_error_message();
		} elseif ( ! $metadata_valid && ! $editor_supported ) {
			$code    = 'unsupported_editor';
			$message = 'متادیتای تصویر ناقص است و موتور تصویر سرور نیز قادر به بازسازی WebP نیست: ' . $editor->get_error_message();
		} elseif ( ! $editor_supported ) {
			$code    = 'unsupported_editor';
			$message = 'یک یا چند برش ناقص است و موتور تصویر سرور قادر به بازسازی WebP نیست: ' . $editor->get_error_message();
		} elseif ( ! $metadata_valid ) {
			$code    = 'missing_metadata';
			$message = 'متادیتای اصلی تصویر ناقص است و باید بازسازی شود.';
		} elseif ( ! empty( $wrong_format_files ) ) {
			$code    = 'wrong_subsize_format';
			$message = 'یک یا چند برش با فرمتی غیر از WebP ثبت شده است و باید دوباره ساخته شود.';
		} else {
			$code    = 'missing_subsizes';
			$message = 'یک یا چند برش لازم در متادیتا یا فایل های uploads ناقص است.';
		}

		return array(
			'healthy'         => $healthy,
			'code'            => $code,
			'message'         => $message,
			'missingSizes'    => $missing_sizes,
			'missingFiles'    => $missing_files,
			'wrongFormatFiles'=> $wrong_format_files,
			'registered'      => $registered,
			'editorSupported' => $editor_supported,
			'metadataValid'   => $metadata_valid,
		);
	}

	/**
	 * Reset independent scan/enqueue cursors.
	 *
	 * @return void
	 */
	public function reset_cursors() {
		$this->reset_workflow_state( false );
	}

	/**
	 * Reset queue construction and all dependent verification stages.
	 *
	 * @param bool $keep_legacy_scan Keep the completed stage-one scan.
	 * @return void
	 */
	public function reset_workflow_state( $keep_legacy_scan = true ) {
		if ( ! $keep_legacy_scan ) {
			delete_option( self::SCAN_CURSOR_OPTION );
			delete_option( 'mobo_core_image_refresh_last_scan' );
		}

		delete_option( self::ENQUEUE_CURSOR_OPTION );
		delete_option( self::SUBSIZE_SCAN_CURSOR_OPTION );
		delete_option( self::SUBSIZE_REPAIR_CURSOR_OPTION );
		delete_option( self::REPLACED_SCAN_CURSOR_OPTION );
		delete_option( self::REPLACED_DELETE_CURSOR_OPTION );
		delete_option( 'mobo_core_image_refresh_last_enqueue' );
		delete_option( 'mobo_core_image_refresh_last_result' );
		delete_option( 'mobo_core_image_refresh_last_subsize_scan' );
		delete_option( 'mobo_core_image_refresh_last_subsize_repair' );
		delete_option( 'mobo_core_image_refresh_last_replaced_scan' );
		delete_option( 'mobo_core_image_refresh_last_replaced_delete' );
	}

	/**
	 * Invalidate all verification and deletion stages that depend on the current
	 * queue output. This is called whenever a new queue cycle starts or any queue
	 * row is processed/retried, so old health scans can never certify new media.
	 *
	 * @return void
	 */
	public function invalidate_post_queue_verification_state() {
		delete_option( self::SUBSIZE_SCAN_CURSOR_OPTION );
		delete_option( self::SUBSIZE_REPAIR_CURSOR_OPTION );
		delete_option( self::REPLACED_SCAN_CURSOR_OPTION );
		delete_option( self::REPLACED_DELETE_CURSOR_OPTION );
		delete_option( 'mobo_core_image_refresh_last_subsize_scan' );
		delete_option( 'mobo_core_image_refresh_last_subsize_repair' );
		delete_option( 'mobo_core_image_refresh_last_replaced_scan' );
		delete_option( 'mobo_core_image_refresh_last_replaced_delete' );

		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( true );
		}
	}


	/**
	 * Load WordPress media helpers used for metadata/subsize repair.
	 *
	 * @return void
	 */
	private function load_media_dependencies() {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) || ! function_exists( 'wp_update_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
	}

	/**
	 * Ensure attachment metadata and all currently registered WordPress image
	 * subsizes exist for the replacement WebP.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function ensure_attachment_subsizes( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'پیوست تصویر جایگزین نامعتبر است.' );
		}

		$this->load_media_dependencies();
		$file = get_attached_file( $attachment_id );
		$file = is_string( $file ) ? $this->normalize_file_path( $file ) : '';

		if ( '' === $file || ! is_file( $file ) || filesize( $file ) <= 0 ) {
			return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'فایل تصویر جایگزین وجود ندارد یا خالی است.' );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'کتابخانه پردازش تصویر سرور قادر به باز کردن یا ساخت WebP نیست: ' . $editor->get_error_message() );
		}

		/*
		 * wp_update_image_subsizes() trusts metadata entries and skips a size when
		 * its metadata already exists, even if the physical file was removed. Drop
		 * only stale size entries first so WordPress can regenerate those cuts.
		 */
		$metadata         = wp_get_attachment_metadata( $attachment_id );
		$metadata_changed = false;
		$dir              = dirname( $file );

		if ( is_array( $metadata ) ) {
			$attached_relative = $this->relative_upload_path( $file );
			if ( '' !== $attached_relative && ( empty( $metadata['file'] ) || $this->normalize_file_path( (string) $metadata['file'] ) !== $this->normalize_file_path( $attached_relative ) ) ) {
				$metadata['file'] = $attached_relative;
				$metadata_changed = true;
			}

			if ( ! empty( $metadata['original_image'] ) ) {
				$original_file = $this->normalize_file_path( trailingslashit( $dir ) . basename( (string) $metadata['original_image'] ) );
				if ( ! is_file( $original_file ) || filesize( $original_file ) <= 0 ) {
					unset( $metadata['original_image'] );
					$metadata_changed = true;
				}
			}

			if ( isset( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
				foreach ( $metadata['backup_sizes'] as $backup_name => $backup_data ) {
					$backup_file = is_array( $backup_data ) && ! empty( $backup_data['file'] )
						? $this->normalize_file_path( trailingslashit( $dir ) . basename( (string) $backup_data['file'] ) )
						: '';
					if ( '' === $backup_file || ! is_file( $backup_file ) || filesize( $backup_file ) <= 0 ) {
						unset( $metadata['backup_sizes'][ $backup_name ] );
						$metadata_changed = true;
					}
				}
			}
		}

		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				$size_file = is_array( $size_data ) && ! empty( $size_data['file'] )
					? $this->normalize_file_path( trailingslashit( $dir ) . basename( (string) $size_data['file'] ) )
					: '';

				$wrong_format = is_array( $size_data ) && ! empty( $size_data['file'] )
					&& 'webp' !== strtolower( pathinfo( (string) $size_data['file'], PATHINFO_EXTENSION ) );

				if ( '' === $size_file || ! is_file( $size_file ) || filesize( $size_file ) <= 0 || $wrong_format ) {
					unset( $metadata['sizes'][ $size_name ] );
					$metadata_changed = true;
				}
			}
		}

		if ( $metadata_changed ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$before = $this->count_existing_attachment_files( $attachment_id );

		if ( function_exists( 'wp_update_image_subsizes' ) ) {
			$updated = wp_update_image_subsizes( $attachment_id );
			if ( is_wp_error( $updated ) ) {
				return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'وردپرس نتوانست برش های تصویر را بازسازی کند. جزئیات فنی: ' . sanitize_text_field( $updated->get_error_message() ) );
			}
		} else {
			$generated_metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( is_wp_error( $generated_metadata ) || ! is_array( $generated_metadata ) ) {
				return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => is_wp_error( $generated_metadata ) ? 'وردپرس نتوانست متادیتا و برش های تصویر را تولید کند. جزئیات فنی: ' . sanitize_text_field( $generated_metadata->get_error_message() ) : 'وردپرس نتوانست متادیتای تصویر را تولید کند.' );
			}
			wp_update_attachment_metadata( $attachment_id, $generated_metadata );
		}

		$health = $this->inspect_attachment_subsizes( $attachment_id );
		if ( empty( $health['healthy'] ) ) {
			$details = array();
			if ( ! empty( $health['missingSizes'] ) ) {
				$details[] = 'سایزهای ساخته نشده: ' . implode( '، ', array_slice( array_values( array_unique( $health['missingSizes'] ) ), 0, 8 ) );
			}
			if ( ! empty( $health['missingFiles'] ) ) {
				$details[] = 'فایل های مفقود: ' . implode( '، ', array_slice( array_values( array_unique( $health['missingFiles'] ) ), 0, 5 ) );
			}
			if ( ! empty( $health['wrongFormatFiles'] ) ) {
				$details[] = 'برش های غیر WebP: ' . implode( '، ', array_slice( array_values( array_unique( $health['wrongFormatFiles'] ) ), 0, 5 ) );
			}
			if ( empty( $details ) && ! empty( $health['message'] ) ) {
				$details[] = (string) $health['message'];
			}
			return array(
				'success'    => false,
				'generated'  => 0,
				'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
				'message'    => 'پس از بازسازی، برش های تصویر هنوز کامل نیستند. ' . implode( ' | ', $details ),
			);
		}

		$after = $this->count_existing_attachment_files( $attachment_id );

		return array(
			'success'    => true,
			'generated'  => max( 0, $after - $before ),
			'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
			'message'    => 'تمام برش های لازم بررسی و تکمیل شدند.',
		);
	}

	/**
	 * Count physical files currently registered for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function count_existing_attachment_files( $attachment_id ) {
		$count = 0;

		foreach ( $this->get_attachment_registered_absolute_paths( $attachment_id ) as $path ) {
			if ( is_file( $path ) && filesize( $path ) > 0 ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Calculate the complete registered attachment family size.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function get_attachment_family_size( $attachment_id ) {
		$total = 0;
		foreach ( $this->get_attachment_registered_absolute_paths( $attachment_id ) as $path ) {
			if ( is_file( $path ) ) {
				$total += absint( filesize( $path ) );
			}
		}

		return $total;
	}

	/**
	 * Snapshot every registered and on-disk legacy derivative before the old
	 * attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function get_legacy_attachment_family_paths( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$paths         = $this->get_attachment_registered_absolute_paths( $attachment_id );
		$file          = get_attached_file( $attachment_id );
		$file          = is_string( $file ) ? $this->normalize_file_path( $file ) : '';
		$metadata      = wp_get_attachment_metadata( $attachment_id );

		if ( '' === $file ) {
			return $paths;
		}

		$dir  = dirname( $file );
		$stem = pathinfo( basename( $file ), PATHINFO_FILENAME );

		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) ) {
			$stem = pathinfo( basename( (string) $metadata['original_image'] ), PATHINFO_FILENAME );
		} else {
			$stem = preg_replace( '/-(?:scaled|rotated)$/i', '', $stem );
		}

		if ( '' !== $stem && is_dir( $dir ) && is_readable( $dir ) ) {
			$pattern = '/^' . preg_quote( $stem, '/' ) . '(?:(?:-e\d{6,})|(?:-\d+x\d+)|(?:-scaled)|(?:-rotated))*\.(?:jpe?g|png)$/i';
			foreach ( (array) scandir( $dir ) as $item ) {
				if ( 1 !== preg_match( $pattern, (string) $item ) ) {
					continue;
				}
				$path = $this->normalize_file_path( $dir . '/' . $item );
				if ( is_file( $path ) ) {
					$paths[] = $path;
				}
			}
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Resolve all core-registered files for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function get_attachment_registered_absolute_paths( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$uploads       = wp_upload_dir( null, false );
		$basedir       = isset( $uploads['basedir'] ) ? $this->normalize_file_path( (string) $uploads['basedir'] ) : '';
		$attached      = ltrim( $this->normalize_file_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ), '/' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$relative      = array();

		if ( '' !== $attached ) {
			$relative[] = $attached;
		}

		if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			$metadata_file = ltrim( $this->normalize_file_path( (string) $metadata['file'] ), '/' );
			$relative[]    = $metadata_file;
			$dir           = dirname( $metadata_file );
			$dir           = '.' === $dir ? '' : $dir;

			if ( ! empty( $metadata['original_image'] ) ) {
				$relative[] = ltrim( ( '' !== $dir ? trailingslashit( $dir ) : '' ) . basename( (string) $metadata['original_image'] ), '/' );
			}

			foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$relative[] = ltrim( ( '' !== $dir ? trailingslashit( $dir ) : '' ) . basename( (string) $size['file'] ), '/' );
				}
			}

			foreach ( isset( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ? $metadata['backup_sizes'] : array() as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$relative[] = ltrim( ( '' !== $dir ? trailingslashit( $dir ) : '' ) . basename( (string) $size['file'] ), '/' );
				}
			}
		}

		$paths = array();
		foreach ( array_values( array_unique( array_filter( $relative ) ) ) as $item ) {
			if ( '' !== $basedir ) {
				$paths[] = $this->normalize_file_path( trailingslashit( $basedir ) . ltrim( $item, '/' ) );
			}
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Remove unregistered leftover derivatives that wp_delete_attachment() could
	 * not know about. Every file is rechecked after the attachment row is gone.
	 *
	 * @param array  $snapshot Family paths captured before deletion.
	 * @param string $old_file Old main file.
	 * @param string $new_file Replacement file.
	 * @return array
	 */
	private function cleanup_leftover_legacy_family( $snapshot, $old_file, $new_file ) {
		$result   = array( 'deletedFiles' => 0, 'bytes' => 0, 'keptFiles' => 0 );
		$new_file = $this->normalize_file_path( (string) $new_file );

		foreach ( array_values( array_unique( array_filter( array_map( array( $this, 'normalize_file_path' ), (array) $snapshot ) ) ) ) as $path ) {
			if ( ! is_file( $path ) || ( '' !== $new_file && $path === $new_file ) || ! $this->is_legacy_raster_path( $path ) || ! $this->is_inside_uploads_path( $path ) ) {
				continue;
			}

			if ( $this->is_file_registered_by_wordpress( $path ) || $this->is_file_path_referenced( $path ) ) {
				$result['keptFiles']++;
				continue;
			}

			$size = absint( filesize( $path ) );
			wp_delete_file( $path );
			if ( ! is_file( $path ) ) {
				$result['deletedFiles']++;
				$result['bytes'] += $size;
			} else {
				$result['keptFiles']++;
			}
		}

		return $result;
	}

	/**
	 * Check exact attachment and metadata registrations for a physical file.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_file_registered_by_wordpress( $path ) {
		global $wpdb;

		$relative = $this->relative_upload_path( $path );
		$name     = basename( $this->normalize_file_path( $path ) );
		if ( '' === $relative || '' === $name ) {
			return true;
		}

		if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $relative ) ) ) > 0 ) {
			return true;
		}

		$like = '%' . $wpdb->esc_like( $name ) . '%';
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s LIMIT 1", $like ) ) ) > 0;
	}

	/**
	 * Conservative database reference check for a physical file.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_file_path_referenced( $path ) {
		global $wpdb;

		$relative = $this->relative_upload_path( $path );
		if ( '' === $relative ) {
			return true;
		}

		$uploads = wp_upload_dir( null, false );
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		$needles = array_filter( array( $relative, basename( $relative ), '' !== $baseurl ? $baseurl . '/' . $relative : '' ) );

		foreach ( array_values( array_unique( $needles ) ) as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND (post_content LIKE %s OR guid LIKE %s) LIMIT 1", $like, $like ) ) ) > 0 ) {
				return true;
			}
			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1", $like ) ) ) > 0 ) {
				return true;
			}
			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $like ) ) ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve an absolute path relative to uploads.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function relative_upload_path( $path ) {
		$uploads = wp_upload_dir( null, false );
		$basedir = isset( $uploads['basedir'] ) ? $this->normalize_file_path( (string) $uploads['basedir'] ) : '';
		$path    = $this->normalize_file_path( (string) $path );

		if ( '' === $basedir || '' === $path || 0 !== strpos( trailingslashit( $path ), trailingslashit( $basedir ) ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( trailingslashit( $basedir ) ) ), '/' );
	}

	private function is_inside_uploads_path( $path ) {
		return '' !== $this->relative_upload_path( $path );
	}

	private function normalize_file_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		if ( function_exists( 'wp_normalize_path' ) ) {
			$path = wp_normalize_path( $path );
		}
		return untrailingslashit( $path );
	}

	private function is_legacy_raster_path( $path ) {
		return in_array( strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'png' ), true );
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
	 * Protect attachment IDs referenced outside WooCommerce image fields.
	 *
	 * Deletion is intentionally conservative. Exact scalar IDs and common
	 * serialized integer/string representations are checked in post, term and
	 * user metadata plus options. False positives keep a legacy attachment; they
	 * never cause data loss.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_has_external_references( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return true;
		}

		if ( $this->attachment_used_in_content( $attachment_id ) ) {
			return true;
		}

		$value             = (string) $attachment_id;
		$serialized_int    = '%i:' . $wpdb->esc_like( $value ) . ';%';
		$serialized_string = '%s:' . strlen( $value ) . ':\"' . $wpdb->esc_like( $value ) . '\";%';

		$postmeta_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				WHERE post_id <> %d
				AND meta_key NOT IN ('_edit_lock', '_edit_last', 'mobo_image_refresh_last_old_attachment_id', 'mobo_refreshed_from_attachment_id', 'mobo_replaces_attachment_id')
				AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s)",
				$attachment_id,
				$value,
				$serialized_int,
				$serialized_string
			)
		);

		if ( absint( $postmeta_count ) > 0 ) {
			return true;
		}

		$meta_tables = array(
			isset( $wpdb->termmeta ) ? $wpdb->termmeta : '',
			isset( $wpdb->usermeta ) ? $wpdb->usermeta : '',
		);

		foreach ( array_filter( $meta_tables ) as $table ) {
			$column = $table === $wpdb->usermeta ? 'umeta_id' : 'meta_id';
			$count  = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT({$column}) FROM {$table} WHERE meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s",
					$value,
					$serialized_int,
					$serialized_string
				)
			);

			if ( absint( $count ) > 0 ) {
				return true;
			}
		}

		$options_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE (option_name = 'site_icon' OR option_name LIKE 'theme_mods\_%' OR option_name LIKE 'widget\_%')
				AND (option_value = %s OR option_value LIKE %s OR option_value LIKE %s)",
				$value,
				$serialized_int,
				$serialized_string
			)
		);

		return absint( $options_count ) > 0;
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
