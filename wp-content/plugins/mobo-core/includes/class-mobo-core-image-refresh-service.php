<?php
/**
 * Mobo image refresh service.
 *
 * Finds Mobo-owned attachments, queues canonical-source WebP replacement jobs,
 * processes them in small batches, and removes old attachments only when safe.
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
	const MISSING_SCAN_CURSOR_OPTION   = 'mobo_core_image_refresh_missing_scan_cursor';
	const MISSING_ENQUEUE_CURSOR_OPTION = 'mobo_core_image_refresh_missing_enqueue_cursor';
	const SUBSIZE_SCAN_CURSOR_OPTION   = 'mobo_core_image_subsize_scan_cursor';
	const SUBSIZE_REPAIR_CURSOR_OPTION = 'mobo_core_image_subsize_repair_cursor';
	const REPLACED_SCAN_CURSOR_OPTION  = 'mobo_core_image_replaced_scan_cursor';
	const REPLACED_DELETE_CURSOR_OPTION = 'mobo_core_image_replaced_delete_cursor';
	const FORCE_SOURCE_REIMPORT_OPTION = 'mobo_core_image_refresh_force_source_reimport';
	const GENERATION_ID_OPTION         = 'mobo_core_image_refresh_generation_id';
	const GENERATION_STATS_OPTION      = 'mobo_core_image_refresh_generation_stats';

	/** Cached authoritative image identities from the local image queue. @var array|null */
	private $current_image_identity_map = null;

	/**
	 * Whether this workflow must fetch the canonical source again even when the
	 * current local attachment is already a healthy WebP.
	 *
	 * @return bool
	 */
	public static function force_source_reimport_enabled() {
		return '1' === (string) get_option( self::FORCE_SOURCE_REIMPORT_OPTION, '1' )
			&& '' !== sanitize_text_field( (string) get_option( self::GENERATION_ID_OPTION, '' ) );
	}

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
	 * Scan legacy Mobo attachments without changing data.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function scan_legacy_images( $limit = 500 ) {
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

		$previous     = get_option( 'mobo_core_image_refresh_last_scan', array() );
		$previous     = is_array( $previous ) ? $previous : array();
		$legacy_already_complete = ! empty( $previous['legacyComplete'] ) && empty( $previous['cycleComplete'] );
		$batch = $legacy_already_complete
			? array( 'ids' => array(), 'cursorStart' => 0, 'cursorEnd' => 0, 'cycleComplete' => true, 'estimatedTotal' => absint( isset( $previous['legacyEstimatedTotal'] ) ? $previous['legacyEstimatedTotal'] : 0 ) )
			: $this->get_legacy_refresh_attachment_batch( $limit, self::SCAN_CURSOR_OPTION );
		$attachments = isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array();
		$cursor_start = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$recovery     = class_exists( 'Mobo_Core_Missing_Image_Recovery' ) ? new Mobo_Core_Missing_Image_Recovery() : null;
		$missing_cursor_start = absint( get_option( self::MISSING_SCAN_CURSOR_OPTION, 0 ) );
		$missing_batch = $recovery instanceof Mobo_Core_Missing_Image_Recovery && $recovery->is_enabled()
			? $recovery->get_candidate_batch( $limit, $missing_cursor_start )
			: array( 'rows' => array(), 'scanned' => 0, 'cursorStart' => 0, 'cursorEnd' => 0, 'cycleComplete' => true, 'estimatedTotal' => 0 );
		$missing_rows = isset( $missing_batch['rows'] ) && is_array( $missing_batch['rows'] ) ? $missing_batch['rows'] : array();
		$continue_cycle = ( $legacy_already_complete || $cursor_start > 0 || $missing_cursor_start > 0 ) && ! empty( $previous ) && empty( $previous['cycleComplete'] );
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
			'missingImageScanned'=> $continue_cycle ? absint( isset( $previous['missingImageScanned'] ) ? $previous['missingImageScanned'] : 0 ) : 0,
			'missingImageProducts'=> $continue_cycle ? absint( isset( $previous['missingImageProducts'] ) ? $previous['missingImageProducts'] : 0 ) : 0,
			'recoveredIdentity'   => $continue_cycle ? absint( isset( $previous['recoveredIdentity'] ) ? $previous['recoveredIdentity'] : 0 ) : 0,
			'recoveredDetached'   => $continue_cycle ? absint( isset( $previous['recoveredDetached'] ) ? $previous['recoveredDetached'] : 0 ) : 0,
			'unrelatedRaster'     => $continue_cycle ? absint( isset( $previous['unrelatedRaster'] ) ? $previous['unrelatedRaster'] : 0 ) : 0,
			'sourceWebp'          => $continue_cycle ? absint( isset( $previous['sourceWebp'] ) ? $previous['sourceWebp'] : 0 ) : 0,
			'sharedSkipped'       => $continue_cycle ? absint( isset( $previous['sharedSkipped'] ) ? $previous['sharedSkipped'] : 0 ) : 0,
			'cursorStart'         => $cursor_start,
			'cursorEnd'           => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'missingCursorStart'  => $missing_cursor_start,
			'missingCursorEnd'    => isset( $missing_batch['cursorEnd'] ) ? absint( $missing_batch['cursorEnd'] ) : 0,
			'legacyComplete'      => $legacy_already_complete || ! empty( $batch['cycleComplete'] ),
			'legacyEstimatedTotal'=> absint( isset( $batch['estimatedTotal'] ) ? $batch['estimatedTotal'] : 0 ),
			'cycleComplete'       => ( $legacy_already_complete || ! empty( $batch['cycleComplete'] ) ) && ! empty( $missing_batch['cycleComplete'] ),
			'estimatedTotal'       => absint( isset( $batch['estimatedTotal'] ) ? $batch['estimatedTotal'] : 0 ) + absint( isset( $missing_batch['estimatedTotal'] ) ? $missing_batch['estimatedTotal'] : 0 ),
			'cycleId'             => $cycle_id,
			'cycleStartedAt'      => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
			'checkedAt'           => time(),
		);

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$result['scanned']++;

			$is_marked      = $this->is_mobo_attachment( $attachment_id );
			$is_legacy      = $this->is_legacy_raster_attachment( $attachment_id );
			$force_source   = self::force_source_reimport_enabled();
			$is_webp        = $this->is_webp_attachment( $attachment_id );
			$product_ids    = ( $is_legacy || ( $force_source && $is_marked && $is_webp ) ) ? $this->find_products_using_attachment( $attachment_id ) : array();

			if ( ! $is_marked && $is_legacy && empty( $product_ids ) ) {
				$detached_match = $this->recover_detached_legacy_identity( $attachment_id, false );
				if ( ! empty( $detached_match['recovered'] ) ) {
					$is_marked = true;
					$result['recoveredDetached'] = absint( isset( $result['recoveredDetached'] ) ? $result['recoveredDetached'] : 0 ) + 1;
				}
			}

			/* The bounded discovery pass can see unrelated site JPG/PNG files. They
			 * are never modified unless ownership is proven from a Mobo product or a
			 * unique current Portal image identity in the local image queue. */
			if ( ! $is_marked && empty( $product_ids ) ) {
				$result['unrelatedRaster'] = absint( isset( $result['unrelatedRaster'] ) ? $result['unrelatedRaster'] : 0 ) + 1;
				continue;
			}

			$result['moboAttachments']++;

			if ( $is_webp ) {
				$result['webp']++;
				if ( ! $force_source ) {
					continue;
				}
				if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
					$result['sharedSkipped']++;
					continue;
				}
				$result['sourceWebp']++;
			}

			if ( ! $is_legacy && ! ( $force_source && $is_marked && $is_webp ) ) {
				continue;
			}

			if ( $is_legacy ) {
				$result['legacyRaster']++;
			}

			$file = get_attached_file( $attachment_id );
			if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
				$result['missingFile']++;
			} else {
				$result['totalLegacyBytes'] += $this->get_attachment_family_size( $attachment_id );
			}

			if ( empty( $product_ids ) ) {
				$result['withoutProduct']++;
				continue;
			}

			$has_source = false;
			foreach ( $product_ids as $product_id ) {
				$identity = $this->resolve_refresh_identity( $attachment_id, $product_id, false );
				if ( ! empty( $identity['image_guid'] ) && ! empty( $identity['new_source_url'] ) ) {
					$has_source = true;
					$result['queueable']++;
					if ( ! empty( $identity['recovered'] ) ) {
						$result['recoveredIdentity'] = absint( isset( $result['recoveredIdentity'] ) ? $result['recoveredIdentity'] : 0 ) + 1;
					}
				}
			}

			if ( ! $has_source ) {
				$result['withoutSourceUrl']++;
			}
		}

		$result['missingImageScanned'] += absint( isset( $missing_batch['scanned'] ) ? $missing_batch['scanned'] : 0 );
		$result['missingImageProducts'] += count( $missing_rows );

		if ( ! empty( $missing_batch['cycleComplete'] ) ) {
			update_option( self::MISSING_SCAN_CURSOR_OPTION, 0, false );
		} else {
			update_option( self::MISSING_SCAN_CURSOR_OPTION, absint( isset( $missing_batch['cursorEnd'] ) ? $missing_batch['cursorEnd'] : $missing_cursor_start ), false );
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
		$previous     = get_option( 'mobo_core_image_refresh_last_enqueue', array() );
		$previous     = is_array( $previous ) ? $previous : array();
		$legacy_already_complete = ! empty( $previous['legacyComplete'] ) && empty( $previous['cycleComplete'] );
		$batch = $legacy_already_complete
			? array( 'ids' => array(), 'cursorStart' => 0, 'cursorEnd' => 0, 'cycleComplete' => true, 'estimatedTotal' => absint( isset( $previous['legacyEstimatedTotal'] ) ? $previous['legacyEstimatedTotal'] : 0 ) )
			: $this->get_legacy_refresh_attachment_batch( $limit, self::ENQUEUE_CURSOR_OPTION );
		$attachments = isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array();
		$cursor_start = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$recovery     = class_exists( 'Mobo_Core_Missing_Image_Recovery' ) ? new Mobo_Core_Missing_Image_Recovery() : null;
		$missing_cursor_start = absint( get_option( self::MISSING_ENQUEUE_CURSOR_OPTION, 0 ) );
		$missing_batch = $recovery instanceof Mobo_Core_Missing_Image_Recovery && $recovery->is_enabled()
			? $recovery->get_candidate_batch( $limit, $missing_cursor_start )
			: array( 'rows' => array(), 'scanned' => 0, 'cursorStart' => 0, 'cursorEnd' => 0, 'cycleComplete' => true, 'estimatedTotal' => 0 );
		$missing_rows = isset( $missing_batch['rows'] ) && is_array( $missing_batch['rows'] ) ? $missing_batch['rows'] : array();
		$continue_cycle = ( $legacy_already_complete || $cursor_start > 0 || $missing_cursor_start > 0 ) && ! empty( $previous ) && empty( $previous['cycleComplete'] );
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
			'missingImageScanned' => $continue_cycle ? absint( isset( $previous['missingImageScanned'] ) ? $previous['missingImageScanned'] : 0 ) : 0,
			'missingImageQueued'  => $continue_cycle ? absint( isset( $previous['missingImageQueued'] ) ? $previous['missingImageQueued'] : 0 ) : 0,
			'missingImageSkipped' => $continue_cycle ? absint( isset( $previous['missingImageSkipped'] ) ? $previous['missingImageSkipped'] : 0 ) : 0,
			'missingImageFailed'  => $continue_cycle ? absint( isset( $previous['missingImageFailed'] ) ? $previous['missingImageFailed'] : 0 ) : 0,
			'missingImagePending' => $continue_cycle ? absint( isset( $previous['missingImagePending'] ) ? $previous['missingImagePending'] : 0 ) : 0,
			'recoveredIdentity'  => $continue_cycle ? absint( isset( $previous['recoveredIdentity'] ) ? $previous['recoveredIdentity'] : 0 ) : 0,
			'detachedRecovered'  => $continue_cycle ? absint( isset( $previous['detachedRecovered'] ) ? $previous['detachedRecovered'] : 0 ) : 0,
			'sourceWebp'         => $continue_cycle ? absint( isset( $previous['sourceWebp'] ) ? $previous['sourceWebp'] : 0 ) : 0,
			'sharedSkipped'      => $continue_cycle ? absint( isset( $previous['sharedSkipped'] ) ? $previous['sharedSkipped'] : 0 ) : 0,
			'cursorStart'      => $cursor_start,
			'cursorEnd'        => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'missingCursorStart' => $missing_cursor_start,
			'missingCursorEnd'   => isset( $missing_batch['cursorEnd'] ) ? absint( $missing_batch['cursorEnd'] ) : 0,
			'legacyComplete'     => $legacy_already_complete || ! empty( $batch['cycleComplete'] ),
			'legacyEstimatedTotal'=> absint( isset( $batch['estimatedTotal'] ) ? $batch['estimatedTotal'] : 0 ),
			'cycleComplete'    => ( $legacy_already_complete || ! empty( $batch['cycleComplete'] ) ) && ! empty( $missing_batch['cycleComplete'] ),
			'estimatedTotal'    => absint( isset( $batch['estimatedTotal'] ) ? $batch['estimatedTotal'] : 0 ) + absint( isset( $missing_batch['estimatedTotal'] ) ? $missing_batch['estimatedTotal'] : 0 ),
			'sourceScanCycleId'=> $source_scan_cycle_id,
			'cycleStartedAt'   => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
			'checkedAt'        => time(),
			'processingStarted'=> false,
		);

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$result['scanned']++;

			$is_legacy    = $this->is_legacy_raster_attachment( $attachment_id );
			$is_webp      = $this->is_webp_attachment( $attachment_id );
			$force_source = self::force_source_reimport_enabled();
			$is_full_refresh_candidate = $force_source && $is_webp && $this->is_mobo_attachment( $attachment_id );

			if ( ! $is_legacy && ! $is_full_refresh_candidate ) {
				$result['skipped']++;
				continue;
			}

			/* A site must not create a private replacement for a worker-owned Shared
			 * Media WebP. The central worker remains authoritative for that payload. */
			if ( $is_full_refresh_candidate && class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
				$result['sharedSkipped']++;
				continue;
			}
			if ( $is_full_refresh_candidate ) {
				$result['sourceWebp']++;
			}

			$product_ids = $this->find_products_using_attachment( $attachment_id );
			if ( empty( $product_ids ) ) {
				$detached = $this->recover_detached_legacy_identity( $attachment_id );
				if ( ! empty( $detached['recovered'] ) ) {
					$result['detachedRecovered'] = absint( isset( $result['detachedRecovered'] ) ? $result['detachedRecovered'] : 0 ) + 1;
				}
				$result['withoutProduct']++;
				continue;
			}

			$file = get_attached_file( $attachment_id );
			$mime = (string) get_post_mime_type( $attachment_id );
			$size = $this->get_attachment_family_size( $attachment_id );

			foreach ( $product_ids as $product_id ) {
				$product_id = absint( $product_id );
				$identity   = $this->resolve_refresh_identity( $attachment_id, $product_id );
				$image_guid = isset( $identity['image_guid'] ) ? sanitize_text_field( (string) $identity['image_guid'] ) : '';
				$new_url    = isset( $identity['new_source_url'] ) ? esc_url_raw( (string) $identity['new_source_url'] ) : '';

				if ( '' === $image_guid || '' === $new_url ) {
					$result['withoutSourceUrl']++;
					continue;
				}

				if ( ! empty( $identity['recovered'] ) ) {
					$result['recoveredIdentity'] = absint( isset( $result['recoveredIdentity'] ) ? $result['recoveredIdentity'] : 0 ) + 1;
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
				} elseif ( in_array( $action, array( 'requeued', 'source_changed_requeued' ), true ) ) {
					$result['requeued']++;
				} elseif ( 'already_done' === $action ) {
					$result['alreadyDone']++;
				} else {
					$result['alreadyQueued']++;
				}
			}
		}

		$result['missingImageScanned'] += absint( isset( $missing_batch['scanned'] ) ? $missing_batch['scanned'] : 0 );
		$missing_cursor_end = $missing_cursor_start;
		$missing_error      = false;

		foreach ( $missing_rows as $missing_row ) {
			$product_id   = absint( isset( $missing_row['product_id'] ) ? $missing_row['product_id'] : 0 );
			$product_guid = sanitize_text_field( (string) ( isset( $missing_row['product_guid'] ) ? $missing_row['product_guid'] : '' ) );
			$recover      = $recovery->recover_product( $product_id, $product_guid, 'image-refresh-missing-' . gmdate( 'YmdHis' ) );

			if ( is_wp_error( $recover ) ) {
				$result['missingImageFailed']++;
				$result['lastError'] = sanitize_text_field( $recover->get_error_message() );
				$missing_error = true;
				break;
			}

			$missing_cursor_end = max( $missing_cursor_end, $product_id );
			if ( ! empty( $recover['skipped'] ) ) {
				$result['missingImageSkipped']++;
			} else {
				$result['missingImageQueued']++;
				$result['missingImagePending'] += absint( isset( $recover['pending'] ) ? $recover['pending'] : 0 );
			}
		}

		if ( $missing_error ) {
			$result['cycleComplete'] = false;
			update_option( self::MISSING_ENQUEUE_CURSOR_OPTION, $missing_cursor_end, false );
		} elseif ( ! empty( $missing_batch['cycleComplete'] ) ) {
			update_option( self::MISSING_ENQUEUE_CURSOR_OPTION, 0, false );
		} else {
			update_option( self::MISSING_ENQUEUE_CURSOR_OPTION, absint( isset( $missing_batch['cursorEnd'] ) ? $missing_batch['cursorEnd'] : $missing_cursor_end ), false );
		}

		update_option( 'mobo_core_image_refresh_last_enqueue', $result, false );

		/* Queue construction normally only builds the replacement queue. Missing
		 * product images are intentionally handed to the separate safe image queue,
		 * which may process immediately or continue through cron retries. */
		return $result;
	}

	/**
	 * Process bounded refresh jobs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function process_queue( $limit = 0 ) {
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			return Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( $limit ) {
					return $this->process_queue_guarded( $limit );
				},
				'image-refresh-queue'
			);
		}

		return $this->process_queue_guarded( $limit );
	}

	/**
	 * Process image refresh queue inside the cache mutation scope.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function process_queue_guarded( $limit = 0 ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'image-refresh-queue' ), array( 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => true ) );
		}

		$worker_lock = Mobo_Core_Lock::acquire( 'image_refresh_queue_worker', 300 );
		if ( false === $worker_lock ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'image-refresh-queue' ), array( 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => true ) );
			}

			return array( 'success' => true, 'status' => 'locked', 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => true );
		}

		try {
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
		$superseded = 0;
		$paused_for_upgrade = false;

		foreach ( $rows as $row ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				$paused_for_upgrade = true;
				break;
			}

			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$claim_token = '';

			if ( $id <= 0 ) {
				continue;
			}
			if ( method_exists( $queue, 'lock_with_token' ) ) {
				$claim_token = $queue->lock_with_token( $id, 300 );
				if ( '' === $claim_token ) {
					continue;
				}
			} elseif ( ! $queue->lock( $id, 300 ) ) {
				continue;
			}
			$row['_mobo_claim_token'] = $claim_token;

			$product_id        = absint( isset( $row['product_id'] ) ? $row['product_id'] : 0 );
			$image_guid        = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
			$old_attachment_id = absint( isset( $row['old_attachment_id'] ) ? $row['old_attachment_id'] : 0 );
			$new_source_url    = esc_url_raw( (string) ( isset( $row['new_source_url'] ) ? $row['new_source_url'] : '' ) );
			$try_count         = isset( $row['try_count'] ) ? absint( $row['try_count'] ) + 1 : 1;
			if ( method_exists( $queue, 'is_current_identity' )
				&& ! $queue->is_current_identity( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token ) ) {
				if ( method_exists( $queue, 'release_if_superseded' ) ) {
					$queue->release_if_superseded( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token );
				}
				$superseded++;
				continue;
			}
			$total_try_limit = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_total_try_limit', 12, 3, 50 );
			if ( $try_count > $total_try_limit ) {
				/* A prior request may have terminated after begin_attempt() and before
				 * mark_failure(). Once its stale lease is reclaimed, close the row here
				 * instead of invoking the same fatal-prone editor forever. */
				$committed = method_exists( $queue, 'mark_failure_if_current' )
					? $queue->mark_failure_if_current( $id, 'Image refresh exceeded its persisted attempt limit after an interrupted worker.', $total_try_limit, true, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token )
					: true;
				if ( ! method_exists( $queue, 'mark_failure_if_current' ) ) {
					$queue->mark_failure( $id, 'Image refresh exceeded its persisted attempt limit after an interrupted worker.', $total_try_limit, true );
				}
				if ( $committed ) {
					$failed++;
				} else {
					$superseded++;
				}
				continue;
			}
			if ( method_exists( $queue, 'begin_attempt_if_current' )
				&& ! $queue->begin_attempt_if_current( $id, $try_count, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token ) ) {
				if ( method_exists( $queue, 'release_if_superseded' ) ) {
					$queue->release_if_superseded( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token );
				}
				$superseded++;
				continue;
			}

			$result = $this->process_row( $row, $queue );
			if ( ! empty( $result['stale'] ) ) {
				if ( method_exists( $queue, 'release_if_superseded' ) ) {
					$queue->release_if_superseded( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token );
				}
				$superseded++;
				continue;
			}

			if ( ! empty( $result['success'] ) ) {
				$note       = isset( $result['note'] ) ? $result['note'] : '';
				$new_id     = isset( $result['newAttachmentId'] ) ? absint( $result['newAttachmentId'] ) : 0;
				$committed  = method_exists( $queue, 'mark_done_if_current' )
					? $queue->mark_done_if_current( $id, $new_id, $note, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token )
					: true;
				if ( ! $committed ) {
					if ( method_exists( $queue, 'release_if_superseded' ) ) {
						$queue->release_if_superseded( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token );
					}
					$superseded++;
					continue;
				}
				if ( ! method_exists( $queue, 'mark_done_if_current' ) ) {
					$queue->mark_done( $id, $new_id, $note );
				}
				$processed++;
				continue;
			}

			if ( ! empty( $result['skipped'] ) ) {
				$message   = isset( $result['message'] ) ? $result['message'] : 'این ردیف بدون تغییر رد شد.';
				$committed = method_exists( $queue, 'mark_skipped_if_current' )
					? $queue->mark_skipped_if_current( $id, $message, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token )
					: true;
				if ( ! $committed ) {
					if ( method_exists( $queue, 'release_if_superseded' ) ) {
						$queue->release_if_superseded( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token );
					}
					$superseded++;
					continue;
				}
				if ( ! method_exists( $queue, 'mark_skipped_if_current' ) ) {
					$queue->mark_skipped( $id, $message );
				}
				$skipped++;
				continue;
			}

			$message   = isset( $result['message'] ) ? $result['message'] : 'نوسازی تصویر ناموفق بود.';
			/* Network/storage/worker failures are recoverable. Only an explicitly permanent result may become terminal. */
			$committed = method_exists( $queue, 'mark_failure_if_current' )
				? $queue->mark_failure_if_current( $id, $message, $try_count, ! empty( $result['permanent'] ), $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token )
				: true;
			if ( ! $committed ) {
				if ( method_exists( $queue, 'release_if_superseded' ) ) {
					$queue->release_if_superseded( $id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token );
				}
				$superseded++;
				continue;
			}
			if ( ! method_exists( $queue, 'mark_failure_if_current' ) ) {
				$queue->mark_failure( $id, $message, $try_count, ! empty( $result['permanent'] ) );
			}
			$failed++;
		}

		return $this->save_last_result(
			array(
				'success'   => true,
				'status'    => $paused_for_upgrade ? 'paused-for-upgrade' : 'processed',
				'processed' => $processed,
				'failed'    => $failed,
				'skipped'   => $skipped,
				'superseded'=> $superseded,
				'remaining' => $paused_for_upgrade || $queue->count_due() > 0,
			)
		);
	
		} finally {
			Mobo_Core_Lock::release( 'image_refresh_queue_worker', $worker_lock );
		}
	}

	/**
	 * Process one row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function process_row( $row, Mobo_Core_Image_Refresh_Queue $queue = null ) {
		$product_id        = absint( isset( $row['product_id'] ) ? $row['product_id'] : 0 );
		$old_attachment_id = absint( isset( $row['old_attachment_id'] ) ? $row['old_attachment_id'] : 0 );
		$image_guid        = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
		$new_source_url    = esc_url_raw( (string) ( isset( $row['new_source_url'] ) ? $row['new_source_url'] : '' ) );
		$claim_token       = isset( $row['_mobo_claim_token'] ) ? sanitize_text_field( (string) $row['_mobo_claim_token'] ) : '';

		if ( $product_id <= 0 || $old_attachment_id <= 0 || '' === $image_guid || '' === $new_source_url ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'اطلاعات این ردیف صف ناقص یا نامعتبر است.' );
		}

		$row_id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
		if ( $queue instanceof Mobo_Core_Image_Refresh_Queue && method_exists( $queue, 'is_current_identity' )
			&& ! $queue->is_current_identity( $row_id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token ) ) {
			return array( 'success' => false, 'stale' => true, 'message' => 'این ردیف هنگام پردازش با منبع جدید جایگزین شد.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'محصول مربوط به این ردیف دیگر وجود ندارد.' );
		}

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return array( 'success' => false, 'skipped' => true, 'message' => 'محصول در فهرست عدم همگام‌سازی است؛ نوسازی تصویر آن بدون mutation خاتمه یافت.' );
		}

		$active_old_attachment_id = $old_attachment_id;
		$old_exists               = 'attachment' === get_post_type( $old_attachment_id );
		$old_in_use               = $old_exists && $this->product_uses_attachment( $product_id, $old_attachment_id );

		/*
		 * Crash-safe reconciliation: PHP may stop after the product was saved with the
		 * new WebP but before mark_refresh_completed()/queue mark_done(). On retry the
		 * old attachment is no longer in use, which used to turn a successful refresh
		 * into a misleading skipped row. Recover only when the product demonstrably
		 * uses a healthy attachment matching this exact image GUID/source identity.
		 */
		if ( ! $old_exists || ! $old_in_use ) {
			$preferred_id = absint( isset( $row['new_attachment_id'] ) ? $row['new_attachment_id'] : 0 );
			$recovered_id = $this->find_valid_webp_attachment_for_identity( $image_guid, $new_source_url, $preferred_id );

			if ( $recovered_id > 0 && $this->product_uses_attachment( $product_id, $recovered_id ) ) {
				$ready = $this->ensure_webp_attachment_ready( $recovered_id );
				if ( empty( $ready['success'] ) ) {
					return array( 'success' => false, 'message' => 'جایگزینی قبلاً روی محصول انجام شده، اما فایل WebP بازیابی‌شده هنوز سالم/کامل نیست: ' . sanitize_text_field( isset( $ready['message'] ) ? (string) $ready['message'] : 'خطای نامشخص' ) );
				}

				if ( $queue instanceof Mobo_Core_Image_Refresh_Queue && method_exists( $queue, 'is_current_identity' )
					&& ! $queue->is_current_identity( $row_id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token ) ) {
					return array( 'success' => false, 'stale' => true, 'message' => 'منبع تصویر در زمان بازیابی اجرای نیمه‌تمام تغییر کرد.' );
				}

				$this->mark_refresh_completed( $product_id, $old_attachment_id, $recovered_id, $image_guid, $new_source_url );
				$note = 'اجرای نیمه‌تمام قبلی شناسایی شد و بدون دانلود تکراری تکمیل شد.';
				$shared_attachment = class_exists( 'Mobo_Core_Shared_Media' )
					&& Mobo_Core_Shared_Media::is_shared_attachment( $recovered_id );
				$delete_old = $old_exists && ( self::force_source_reimport_enabled()
					|| Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
					|| ( $shared_attachment && Mobo_Core_Shared_Media::should_delete_local_copies() ) );
				if ( $delete_old ) {
					$delete_check = $this->safe_delete_old_attachment( $old_attachment_id, $recovered_id );
					$note .= ' ' . ( isset( $delete_check['message'] ) ? (string) $delete_check['message'] : 'تصویر قدیمی نگه داشته شد.' );
				}

				return array( 'success' => true, 'newAttachmentId' => $recovered_id, 'note' => $note );
			}

			/* A superseded worker may already have replaced the original legacy
			 * attachment with an older source for the same image GUID before its CAS
			 * commit was rejected. Continue from that in-use successor instead of
			 * permanently skipping the newer desired source. */
			$continuation_id = $this->find_product_attachment_for_image_guid( $product_id, $image_guid );
			if ( $continuation_id > 0 ) {
				$active_old_attachment_id = $continuation_id;
			} else {
				return array(
					'success' => false,
					'skipped' => true,
					'message' => $old_exists ? 'محصول دیگر از تصویر قدیمی این ردیف استفاده نمی کند و جایگزین/ادامه معتبر همان GUID روی محصول پیدا نشد.' : 'پیوست تصویر قدیمی دیگر وجود ندارد و جایگزین/ادامه معتبر همان GUID روی محصول پیدا نشد.',
				);
			}
		}

		$image_sync = new Mobo_Core_Image_Sync();
		if ( ! method_exists( $image_sync, 'import_image_for_refresh' ) ) {
			return array( 'success' => false, 'message' => 'بخش دریافت تصویر جدید در افزونه در دسترس نیست.' );
		}

		$force_source_reimport = self::force_source_reimport_enabled();
		$reused_generation     = false;
		$new_attachment_id     = $force_source_reimport ? $this->find_generation_replacement( $active_old_attachment_id, $image_guid, $new_source_url ) : 0;

		if ( $new_attachment_id > 0 ) {
			$reused_generation = true;
		} else {
			$new_attachment_id = absint(
				$image_sync->import_image_for_refresh(
					$new_source_url,
					$product_id,
					$image_guid,
					$active_old_attachment_id,
					$force_source_reimport,
					sanitize_text_field( (string) get_option( self::GENERATION_ID_OPTION, '' ) )
				)
			);
		}

		if ( $new_attachment_id <= 0 || 'attachment' !== get_post_type( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'دریافت یا ثبت تصویر WebP ناموفق بود.' );
		}

		if ( ! $this->is_valid_new_attachment( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'فایل دریافت شده یک تصویر معتبر نیست.' );
		}

		if ( $new_attachment_id === $active_old_attachment_id || ! $this->is_webp_attachment( $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'تصویر جایگزین یک فایل WebP مستقل و معتبر نیست.' );
		}

		$shared_attachment = class_exists( 'Mobo_Core_Shared_Media' )
			&& Mobo_Core_Shared_Media::is_shared_attachment( $new_attachment_id );

		$subsize_result = $this->ensure_webp_attachment_ready( $new_attachment_id );

		if ( empty( $subsize_result['success'] ) ) {
			return array(
				'success' => false,
				'message' => 'تصویر WebP دریافت شد، اما کنترل نهایی برش های وردپرس ناموفق بود: ' . sanitize_text_field( isset( $subsize_result['message'] ) ? (string) $subsize_result['message'] : 'خطای نامشخص' ),
			);
		}

		/* Local sideloads are born with mobo_sync_incomplete=1 at add_attachment.
		 * Shared imports already commit their manifest atomically, but writing the
		 * same final marker is harmless and gives both storage modes one readiness
		 * boundary: only a fully verified replacement is complete. */
		if ( ! $this->persist_post_meta_verified( $new_attachment_id, 'mobo_sync_incomplete', '0' ) ) {
			return array( 'success' => false, 'message' => 'تصویر WebP آماده شد، اما completion marker آن به‌صورت پایدار ذخیره نشد؛ محصول هنوز جایگزین نشد و اجرای بعد دوباره تلاش می‌کند.' );
		}
		if ( $force_source_reimport && ! $reused_generation ) {
			$this->register_generation_candidate( $active_old_attachment_id, $new_attachment_id );
			$this->record_generation_stats(
				absint( isset( $row['old_file_size'] ) ? $row['old_file_size'] : $this->get_attachment_family_size( $active_old_attachment_id ) ),
				$this->get_attachment_family_size( $new_attachment_id ),
				false
			);
		}

		if ( $queue instanceof Mobo_Core_Image_Refresh_Queue && method_exists( $queue, 'is_current_identity' )
			&& ! $queue->is_current_identity( $row_id, $product_id, $image_guid, $old_attachment_id, $new_source_url, $claim_token ) ) {
			return array( 'success' => false, 'stale' => true, 'message' => 'منبع تصویر قبل از جایگزینی محصول تغییر کرد.' );
		}

		if ( ! $this->replace_product_attachment( $product, $active_old_attachment_id, $new_attachment_id ) ) {
			return array( 'success' => false, 'message' => 'تصویر جدید آماده شد، اما ذخیره Featured/Gallery محصول در WooCommerce تأیید نشد و در اجرای بعد دوباره تلاش می‌شود.' );
		}
		$this->mark_refresh_completed( $product_id, $active_old_attachment_id, $new_attachment_id, $image_guid, $new_source_url );
		if ( $reused_generation ) {
			$this->record_generation_stats( 0, 0, true );
		}

		$note       = 'تصویر قدیمی نگه داشته شد.';
		$delete_old = $force_source_reimport
			|| Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' )
			|| ( $shared_attachment && Mobo_Core_Shared_Media::should_delete_local_copies() );
		if ( $delete_old ) {
			$delete_check = $this->safe_delete_old_attachment( $active_old_attachment_id, $new_attachment_id );
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
	 * @return bool
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

			$featured_id = absint( $product->get_image_id() );
			$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', $gallery_ids ) ) ) );
			if ( $featured_id > 0 ) {
				$gallery_ids = array_values( array_filter( $gallery_ids, static function ( $id ) use ( $featured_id ) {
					return absint( $id ) !== $featured_id;
				} ) );
			}
			$product->set_gallery_image_ids( $gallery_ids );
		}

		$saved_id = absint( $product->save() );
		if ( $saved_id !== absint( $product_id ) ) {
			return false;
		}

		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product ) {
			return false;
		}
		$fresh_gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) $fresh->get_gallery_image_ids() ) ) ) );
		if ( absint( $fresh->get_image_id() ) === $old_attachment_id || in_array( $old_attachment_id, $fresh_gallery, true ) ) {
			return false;
		}
		if ( absint( $fresh->get_image_id() ) !== $new_attachment_id && ! in_array( $new_attachment_id, $fresh_gallery, true ) ) {
			return false;
		}

		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
		return true;
	}


	/** Persist correctness-critical postmeta and verify exact read-back. */
	private function persist_post_meta_verified( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		$key     = sanitize_key( (string) $key );
		if ( $post_id <= 0 || '' === $key ) {
			return false;
		}
		$current = get_post_meta( $post_id, $key, true );
		if ( maybe_serialize( $current ) !== maybe_serialize( $value ) ) {
			update_post_meta( $post_id, $key, $value );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		return maybe_serialize( get_post_meta( $post_id, $key, true ) ) === maybe_serialize( $value );
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
		$generation_id = sanitize_text_field( (string) get_option( self::GENERATION_ID_OPTION, '' ) );

		update_post_meta( $product_id, 'mobo_image_refresh_last_completed_at', $now );
		update_post_meta( $product_id, 'mobo_image_refresh_last_old_attachment_id', absint( $old_attachment_id ) );
		update_post_meta( $product_id, 'mobo_image_refresh_last_new_attachment_id', absint( $new_attachment_id ) );

		update_post_meta( $new_attachment_id, 'mobo_image_refresh_completed_at', $now );
		update_post_meta( $new_attachment_id, 'mobo_refreshed_from_attachment_id', absint( $old_attachment_id ) );
		update_post_meta( $new_attachment_id, 'mobo_image_refresh_source_url', esc_url_raw( (string) $new_source_url ) );
		update_post_meta( $new_attachment_id, 'mobo_image_refresh_product_id', absint( $product_id ) );
		if ( '' !== $generation_id ) {
			update_post_meta( $new_attachment_id, 'mobo_image_refresh_generation_id', $generation_id );
		}

		if ( $old_attachment_id > 0 && 'attachment' === get_post_type( $old_attachment_id ) ) {
			update_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_at', $now );
			update_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', absint( $new_attachment_id ) );
			delete_post_meta( $old_attachment_id, 'mobo_image_refresh_generation_candidate_id' );
			if ( '' !== $generation_id ) {
				update_post_meta( $old_attachment_id, 'mobo_image_refresh_generation_id', $generation_id );
			}
		}
	}

	/**
	 * Reuse the one fresh download already produced for this old attachment in the
	 * current workflow generation. This avoids one download per product when the
	 * same gallery attachment is referenced by several products.
	 *
	 * @param int    $old_attachment_id Old attachment ID.
	 * @param string $image_guid Expected image GUID.
	 * @param string $source_url Expected canonical source URL.
	 * @return int
	 */
	private function find_generation_replacement( $old_attachment_id, $image_guid, $source_url ) {
		$old_attachment_id = absint( $old_attachment_id );
		$generation_id     = sanitize_text_field( (string) get_option( self::GENERATION_ID_OPTION, '' ) );
		$image_guid        = sanitize_text_field( (string) $image_guid );
		$source_url        = esc_url_raw( (string) $source_url );
		if ( $old_attachment_id <= 0 || '' === $generation_id ) {
			return 0;
		}

		if ( $generation_id !== sanitize_text_field( (string) get_post_meta( $old_attachment_id, 'mobo_image_refresh_generation_id', true ) ) ) {
			return 0;
		}

		$replacement_id = absint( get_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', true ) );
		if ( $replacement_id <= 0 ) {
			$replacement_id = absint( get_post_meta( $old_attachment_id, 'mobo_image_refresh_generation_candidate_id', true ) );
		}
		if ( $replacement_id <= 0
			|| $generation_id !== sanitize_text_field( (string) get_post_meta( $replacement_id, 'mobo_image_refresh_generation_id', true ) )
			|| $image_guid !== $this->get_image_guid_from_attachment( $replacement_id )
			|| $source_url !== esc_url_raw( (string) get_post_meta( $replacement_id, 'mobo_source_url', true ) )
			|| ! $this->is_valid_new_attachment( $replacement_id )
			|| ! $this->is_webp_attachment( $replacement_id ) ) {
			return 0;
		}

		$ready = $this->ensure_webp_attachment_ready( $replacement_id );
		return ! empty( $ready['success'] ) ? $replacement_id : 0;
	}

	/**
	 * Persist a verified but not-yet-linked generation download so a failed product
	 * save can retry without consuming disk with another identical sideload.
	 *
	 * @param int $old_attachment_id Old attachment ID.
	 * @param int $new_attachment_id New attachment ID.
	 * @return void
	 */
	private function register_generation_candidate( $old_attachment_id, $new_attachment_id ) {
		$generation_id = sanitize_text_field( (string) get_option( self::GENERATION_ID_OPTION, '' ) );
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		if ( '' === $generation_id || $old_attachment_id <= 0 || $new_attachment_id <= 0 ) {
			return;
		}
		update_post_meta( $old_attachment_id, 'mobo_image_refresh_generation_id', $generation_id );
		update_post_meta( $old_attachment_id, 'mobo_image_refresh_generation_candidate_id', $new_attachment_id );
		update_post_meta( $new_attachment_id, 'mobo_image_refresh_generation_id', $generation_id );
	}

	/**
	 * Store a compact, generation-scoped size report for the admin page.
	 *
	 * @param int  $old_bytes Previous attachment-family size.
	 * @param int  $new_bytes New attachment-family size.
	 * @param bool $reused Whether an existing generation download was reused.
	 * @return void
	 */
	private function record_generation_stats( $old_bytes, $new_bytes, $reused ) {
		$generation_id = sanitize_text_field( (string) get_option( self::GENERATION_ID_OPTION, '' ) );
		if ( '' === $generation_id ) {
			return;
		}

		$stats = get_option( self::GENERATION_STATS_OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		if ( $generation_id !== sanitize_text_field( (string) ( isset( $stats['generationId'] ) ? $stats['generationId'] : '' ) ) ) {
			$stats = array( 'generationId' => $generation_id );
		}

		if ( $reused ) {
			$stats['reusedLinks'] = absint( isset( $stats['reusedLinks'] ) ? $stats['reusedLinks'] : 0 ) + 1;
		} else {
			$stats['freshDownloads'] = absint( isset( $stats['freshDownloads'] ) ? $stats['freshDownloads'] : 0 ) + 1;
			$stats['bytesBefore']     = absint( isset( $stats['bytesBefore'] ) ? $stats['bytesBefore'] : 0 ) + absint( $old_bytes );
			$stats['bytesAfter']      = absint( isset( $stats['bytesAfter'] ) ? $stats['bytesAfter'] : 0 ) + absint( $new_bytes );
		}
		$stats['updatedAt'] = time();
		update_option( self::GENERATION_STATS_OPTION, $stats, false );
	}

	/**
	 * Safely migrate every known reference to the verified WebP replacement and
	 * delete the old attachment only when no reference remains.
	 *
	 * @param int $attachment_id Old attachment ID.
	 * @param int $new_attachment_id Replacement attachment ID.
	 * @return array
	 */
	private function safe_delete_old_attachment( $attachment_id, $new_attachment_id = 0 ) {
		$attachment_id     = absint( $attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		$migration_result  = array(
			'attempted'   => false,
			'updatedRows' => 0,
			'errors'      => 0,
		);

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'deleted' => false, 'outcome' => 'blocked', 'message' => 'تصویر قدیمی وجود ندارد.', 'referenceMigration' => $migration_result );
		}

		if ( ! $this->is_mobo_attachment( $attachment_id ) ) {
			return array( 'deleted' => false, 'outcome' => 'blocked', 'message' => 'تصویر قدیمی به عنوان تصویر موبو ثبت نشده است.', 'referenceMigration' => $migration_result );
		}

		/* Shared Media files are worker-owned/read-only from the WordPress site's
		 * perspective. wp_delete_attachment(..., true) would otherwise ask WordPress
		 * to unlink the centrally shared physical family. Never do that from a site
		 * refresh/cleanup path, even when a superseded refresh created a newer shared
		 * attachment for the same image GUID. */
		if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			return array( 'deleted' => false, 'outcome' => 'blocked', 'message' => 'پیوست قدیمی Shared Media است؛ حذف فایل فیزیکی فقط در اختیار Worker/مخزن مرکزی است و از سایت انجام نشد.', 'referenceMigration' => $migration_result );
		}

		if ( $new_attachment_id <= 0 || ! $this->is_valid_new_attachment( $new_attachment_id ) || ! $this->is_webp_attachment( $new_attachment_id ) ) {
			return array( 'deleted' => false, 'outcome' => 'blocked', 'message' => 'تصویر WebP جایگزین معتبر نیست؛ انتقال مراجع و حذف انجام نشد.', 'referenceMigration' => $migration_result );
		}

		$registered_replacement = absint( get_post_meta( $attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', true ) );
		if ( $registered_replacement !== $new_attachment_id ) {
			return array( 'deleted' => false, 'outcome' => 'blocked', 'message' => 'ارتباط پیوست قدیمی با WebP جایگزین در سابقه نوسازی تایید نشد.', 'referenceMigration' => $migration_result );
		}

		$identity_preflight = $this->product_references_match_replacement_identity( $attachment_id, $new_attachment_id );
		if ( empty( $identity_preflight['safe'] ) ) {
			return array(
				'deleted'             => false,
				'outcome'             => 'blocked',
				'message'             => isset( $identity_preflight['message'] ) ? (string) $identity_preflight['message'] : 'یک یا چند محصول هنوز هویت تصویر متفاوت/نامشخصی برای پیوست قدیمی دارند؛ انتقال سراسری مرجع انجام نشد.',
				'referenceMigration'  => $migration_result,
				'remainingReferences' => true,
			);
		}

		/* Migration is safe and idempotent for this verified old -> new pair.
		 * Migrate first, then perform one authoritative reference audit. */
		$migration_result   = $this->migrate_attachment_references( $attachment_id, $new_attachment_id );
		$product_references = $this->count_all_products_using_attachment( $attachment_id );
		if ( $product_references > 0 ) {
			return array(
				'deleted'             => false,
				'outcome'             => 'blocked',
				'message'             => 'انتقال مراجع اجرا شد، اما این پیوست قدیمی هنوز توسط یک یا چند محصول استفاده می شود و برای جلوگیری از خرابی نگه داشته شد.',
				'referenceMigration'  => $migration_result,
				'remainingReferences' => true,
				'referenceLocations'  => array( 'product×' . absint( $product_references ) ),
			);
		}

		$external = $this->get_external_reference_diagnostics( $attachment_id, $new_attachment_id );
		if ( ! empty( $external['hasReferences'] ) ) {
			return array(
				'deleted'             => false,
				'outcome'             => 'blocked',
				'message'             => 'انتقال مراجع امن اجرا شد، اما هنوز مرجع ساختاری یا داده سریالایز ناشناخته ای باقی مانده است؛ این مورد رد شد و بقیه مرحله ۷ ادامه پیدا می کند.',
				'referenceMigration'  => $migration_result,
				'remainingReferences' => true,
				'referenceLocations'  => isset( $external['locations'] ) && is_array( $external['locations'] ) ? $external['locations'] : array(),
			);
		}

		$old_file          = get_attached_file( $attachment_id );
		$old_file          = is_string( $old_file ) ? $this->normalize_file_path( $old_file ) : '';
		$family_snapshot   = $this->get_legacy_attachment_family_paths( $attachment_id );
		$new_file          = get_attached_file( $new_attachment_id );
		$new_file          = is_string( $new_file ) ? $this->normalize_file_path( $new_file ) : '';
		$deleted           = wp_delete_attachment( $attachment_id, true );

		if ( ! $deleted ) {
			return array( 'deleted' => false, 'outcome' => 'error', 'message' => 'حذف پیوست قدیمی توسط وردپرس ناموفق بود.', 'referenceMigration' => $migration_result );
		}

		$leftover_result = array( 'deletedFiles' => 0, 'bytes' => 0, 'keptFiles' => 0 );
		/* Safe invariant: unregistered/unreferenced leftovers are always cleaned after the attachment itself is safely deleted. */
		$leftover_result = $this->cleanup_leftover_legacy_family( $family_snapshot, $old_file, $new_file );

		$message = 'تصویر قدیمی و برش های ثبت شده آن با موفقیت و به صورت امن حذف شدند.';
		if ( ! empty( $migration_result['updatedRows'] ) ) {
			$message .= ' مراجع سایت پیش از حذف به WebP منتقل شد؛ تعداد ردیف های به روز شده: ' . absint( $migration_result['updatedRows'] ) . '.';
		}
		if ( ! empty( $leftover_result['deletedFiles'] ) ) {
			$message .= ' تعداد برش های جا مانده و ثبت نشده که حذف شد: ' . absint( $leftover_result['deletedFiles'] ) . '.';
		}
		if ( ! empty( $leftover_result['keptFiles'] ) ) {
			$message .= ' تعداد فایل های دارای مرجع یا ثبت شده که نگه داشته شد: ' . absint( $leftover_result['keptFiles'] ) . '.';
		}

		return array(
			'deleted'              => true,
			'outcome'              => 'deleted',
			'message'              => $message,
			'leftoverDeletedFiles' => isset( $leftover_result['deletedFiles'] ) ? absint( $leftover_result['deletedFiles'] ) : 0,
			'leftoverBytes'        => isset( $leftover_result['bytes'] ) ? absint( $leftover_result['bytes'] ) : 0,
			'referenceMigration'   => $migration_result,
			'remainingReferences'  => false,
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

		/* In a full-source generation the URL intentionally stays the same. The
		 * downloader adds a request-only cache buster while attachment identity and
		 * stored metadata keep this canonical URL. */
		if ( self::force_source_reimport_enabled() && '' !== $old_url && $this->is_webp_url( $old_url ) ) {
			return $old_url;
		}

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
	 * Resolve the current Portal identity for a legacy attachment.
	 *
	 * @param int $attachment_id Legacy attachment ID.
	 * @param int  $product_id Product/variation ID using it.
	 * @param bool $persist Whether recovered identity metadata may be written.
	 * @return array
	 */
	private function resolve_refresh_identity( $attachment_id, $product_id, $persist = true ) {
		$attachment_id = absint( $attachment_id );
		$product_id    = absint( $product_id );
		$image_guid    = $this->get_image_guid_from_attachment( $attachment_id );
		$old_url       = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );

		if ( $attachment_id <= 0 || $product_id <= 0 ) {
			return array( 'image_guid' => '', 'new_source_url' => '', 'recovered' => false );
		}

		if ( '' !== $image_guid ) {
			$new_url = $this->find_new_source_url( $product_id, $image_guid, $old_url );
			if ( '' !== $new_url ) {
				return array( 'image_guid' => $image_guid, 'new_source_url' => $new_url, 'recovered' => false );
			}
		}

		$rows = $this->get_current_product_image_rows( $product_id );
		if ( empty( $rows ) ) {
			return array( 'image_guid' => '', 'new_source_url' => '', 'recovered' => false );
		}

		if ( '' !== $image_guid ) {
			$guid_matches = array();
			foreach ( $rows as $row ) {
				$row_guid = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
				$row_url  = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
				if ( '' !== $row_guid && hash_equals( $image_guid, $row_guid ) && $this->is_webp_url( $row_url ) ) {
					$guid_matches[] = $row;
				}
			}
			$guid_matches = $this->unique_identity_rows( $guid_matches );
			if ( 1 === count( $guid_matches ) ) {
				return array( 'image_guid' => $image_guid, 'new_source_url' => esc_url_raw( (string) $guid_matches[0]['source_url'] ), 'recovered' => false );
			}
		}

		$legacy_key = $this->attachment_basename_key( $attachment_id );
		if ( '' !== $legacy_key ) {
			$basename_matches = array();
			foreach ( $rows as $row ) {
				$row_url = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
				if ( $this->is_webp_url( $row_url ) && $legacy_key === $this->source_basename_key( $row_url ) ) {
					$basename_matches[] = $row;
				}
			}
			$basename_matches = $this->unique_identity_rows( $basename_matches );
			if ( 1 === count( $basename_matches ) ) {
				return $persist ? $this->mark_recovered_attachment_identity( $attachment_id, $basename_matches[0] ) : $this->preview_recovered_attachment_identity( $basename_matches[0] );
			}
		}

		$positions = $this->attachment_product_positions( $product_id, $attachment_id );
		if ( ! empty( $positions ) ) {
			$position_matches = array();
			foreach ( $rows as $row ) {
				$position = isset( $row['position_index'] ) ? absint( $row['position_index'] ) : -1;
				$row_url  = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
				if ( in_array( $position, $positions, true ) && $this->is_webp_url( $row_url ) ) {
					$position_matches[] = $row;
				}
			}
			$position_matches = $this->unique_identity_rows( $position_matches );
			if ( 1 === count( $position_matches ) ) {
				return $persist ? $this->mark_recovered_attachment_identity( $attachment_id, $position_matches[0] ) : $this->preview_recovered_attachment_identity( $position_matches[0] );
			}
		}

		return array( 'image_guid' => '', 'new_source_url' => '', 'recovered' => false );
	}

	/**
	 * Recover an old registered JPG/PNG that is no longer linked to a product but
	 * has one unique current WebP identity in the local authoritative image queue.
	 *
	 * @param int  $attachment_id Legacy attachment ID.
	 * @param bool $persist Whether replacement metadata may be written.
	 * @return array
	 */
	private function recover_detached_legacy_identity( $attachment_id, $persist = true ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || ! $this->is_legacy_raster_attachment( $attachment_id ) ) {
			return array( 'recovered' => false );
		}

		$existing_replacement = absint( get_post_meta( $attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', true ) );
		if ( $existing_replacement > 0 && $this->is_valid_new_attachment( $existing_replacement ) && $this->is_webp_attachment( $existing_replacement ) ) {
			return array( 'recovered' => false, 'replacement_attachment_id' => $existing_replacement );
		}

		$key = $this->attachment_basename_key( $attachment_id );
		$map = $this->get_current_image_identity_map();
		if ( '' === $key || empty( $map['byBasename'][ $key ] ) || ! is_array( $map['byBasename'][ $key ] ) || ! empty( $map['byBasename'][ $key ]['ambiguous'] ) ) {
			return array( 'recovered' => false );
		}

		$row               = $map['byBasename'][ $key ];
		$image_guid        = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
		$new_source_url    = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
		$new_attachment_id = $this->find_valid_webp_attachment_for_identity(
			$image_guid,
			$new_source_url,
			absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 )
		);

		if ( '' === $image_guid || ! $this->is_webp_url( $new_source_url ) || $new_attachment_id <= 0 ) {
			return array( 'recovered' => false );
		}
		$row['attachment_id'] = $new_attachment_id;

		$identity = $persist ? $this->mark_recovered_attachment_identity( $attachment_id, $row ) : $this->preview_recovered_attachment_identity( $row );
		if ( $persist ) {
			update_post_meta( $attachment_id, 'mobo_image_refresh_replaced_at', time() );
			update_post_meta( $attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', $new_attachment_id );
			update_post_meta( $new_attachment_id, 'mobo_refreshed_from_attachment_id', $attachment_id );
		}
		$identity['replacement_attachment_id'] = $new_attachment_id;

		return $identity;
	}

	/**
	 * Find a real local WebP attachment for one current Mobo image identity.
	 *
	 * The main image queue can temporarily have attachment_id=0 (or a stale ID)
	 * even when Image Refresh already imported the WebP, so detached cleanup must
	 * not rely on that column alone.
	 *
	 * @param string $image_guid Image GUID.
	 * @param string $source_url Current WebP source URL.
	 * @param int    $preferred_id Preferred attachment from the image queue.
	 * @return int
	 */
	private function find_valid_webp_attachment_for_identity( $image_guid, $source_url, $preferred_id = 0 ) {
		global $wpdb;

		$image_guid  = sanitize_text_field( (string) $image_guid );
		$source_url  = esc_url_raw( (string) $source_url );
		$preferred_id = absint( $preferred_id );
		if ( $preferred_id > 0
			&& $this->attachment_matches_refresh_identity( $preferred_id, $image_guid, $source_url )
			&& $this->is_valid_new_attachment( $preferred_id )
			&& $this->is_webp_attachment( $preferred_id ) ) {
			return $preferred_id;
		}

		if ( '' === $image_guid || ! $this->is_webp_url( $source_url ) ) {
			return 0;
		}

		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'attachment'
					AND p.post_status IN ('inherit', 'private')
					AND (
						(pm.meta_key IN ('image_guid', 'img_guid', '_mobo_shared_media_image_id') AND pm.meta_value = %s)
						OR (pm.meta_key = 'mobo_source_url' AND pm.meta_value = %s)
					)
				ORDER BY p.ID DESC
				LIMIT %d",
				$image_guid,
				$source_url,
				20
			)
		);

		foreach ( is_array( $candidates ) ? $candidates : array() as $candidate_id ) {
			$candidate_id = absint( $candidate_id );
			if ( $candidate_id > 0
				&& $this->attachment_matches_refresh_identity( $candidate_id, $image_guid, $source_url )
				&& $this->is_valid_new_attachment( $candidate_id )
				&& $this->is_webp_attachment( $candidate_id ) ) {
				return $candidate_id;
			}
		}

		return 0;
	}

	/**
	 * Require a recovered attachment to belong to the exact refresh identity.
	 * Existing metadata may be legacy/incomplete, but any populated GUID/source
	 * value must agree and at least one identity value must positively match.
	 */
	private function attachment_matches_refresh_identity( $attachment_id, $image_guid, $source_url ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		$source_url    = esc_url_raw( (string) $source_url );
		if ( $attachment_id <= 0 || '' === $image_guid || '' === $source_url ) {
			return false;
		}

		$wanted_guid = strtolower( trim( $image_guid ) );
		$guid_a      = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
		$guid_b      = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
		$guid_shared = '';
		if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			$guid_shared = sanitize_text_field( (string) get_post_meta( $attachment_id, '_mobo_shared_media_image_id', true ) );
		}
		$stored_source = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );

		foreach ( array( $guid_a, $guid_b, $guid_shared ) as $stored_guid ) {
			if ( '' !== $stored_guid && ! hash_equals( $wanted_guid, strtolower( trim( $stored_guid ) ) ) ) {
				return false;
			}
		}
		if ( '' !== $stored_source && ! hash_equals( $source_url, $stored_source ) ) {
			return false;
		}

		$guid_match = false;
		foreach ( array( $guid_a, $guid_b, $guid_shared ) as $stored_guid ) {
			if ( '' !== $stored_guid && hash_equals( $wanted_guid, strtolower( trim( $stored_guid ) ) ) ) {
				$guid_match = true;
				break;
			}
		}
		$source_match = '' !== $stored_source && hash_equals( $source_url, $stored_source );

		return $guid_match || $source_match;
	}

	/** @return array */
	private function get_current_product_image_rows( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return array();
		}
		$queue = new Mobo_Core_Image_Queue();
		$rows  = method_exists( $queue, 'get_ordered_rows_for_product' ) ? $queue->get_ordered_rows_for_product( $product_id ) : array();
		if ( empty( $rows ) && 'product_variation' === get_post_type( $product_id ) ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id > 0 ) {
				$rows = $queue->get_ordered_rows_for_product( $parent_id );
			}
		}
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array */
	private function get_current_image_identity_map() {
		global $wpdb;
		if ( is_array( $this->current_image_identity_map ) ) {
			return $this->current_image_identity_map;
		}
		$this->current_image_identity_map = array( 'byBasename' => array() );
		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return $this->current_image_identity_map;
		}

		$table = Mobo_Core_Image_Queue::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT image_guid, source_url, attachment_id, product_id, position_index, status
				FROM {$table}
				WHERE image_guid <> '' AND source_url <> ''
				ORDER BY updated_at DESC, id DESC
				LIMIT %d",
				50000
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$url  = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
			$guid = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
			$key  = $this->source_basename_key( $url );
			if ( '' === $key || '' === $guid || ! $this->is_webp_url( $url ) ) {
				continue;
			}

			if ( ! isset( $this->current_image_identity_map['byBasename'][ $key ] ) ) {
				$row['ambiguous'] = false;
				$this->current_image_identity_map['byBasename'][ $key ] = $row;
				continue;
			}

			$existing      = $this->current_image_identity_map['byBasename'][ $key ];
			$existing_guid = sanitize_text_field( (string) ( isset( $existing['image_guid'] ) ? $existing['image_guid'] : '' ) );
			if ( ! empty( $existing['ambiguous'] ) ) {
				continue;
			}
			if ( '' !== $existing_guid && ! hash_equals( $existing_guid, $guid ) ) {
				$this->current_image_identity_map['byBasename'][ $key ]['ambiguous'] = true;
				continue;
			}

			$existing_attachment = absint( isset( $existing['attachment_id'] ) ? $existing['attachment_id'] : 0 );
			$row_attachment      = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
			if ( $existing_attachment <= 0 && $row_attachment > 0 ) {
				$row['ambiguous'] = false;
				$this->current_image_identity_map['byBasename'][ $key ] = $row;
			}
		}

		return $this->current_image_identity_map;
	}

	/** @return array */
	private function preview_recovered_attachment_identity( $row ) {
		$image_guid = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
		$url        = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
		if ( '' === $image_guid || ! $this->is_webp_url( $url ) ) {
			return array( 'image_guid' => '', 'new_source_url' => '', 'recovered' => false );
		}
		return array( 'image_guid' => $image_guid, 'new_source_url' => $url, 'recovered' => true );
	}

	/** @return array */
	private function mark_recovered_attachment_identity( $attachment_id, $row ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
		$url           = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
		if ( $attachment_id <= 0 || '' === $image_guid || ! $this->is_webp_url( $url ) ) {
			return array( 'image_guid' => '', 'new_source_url' => '', 'recovered' => false );
		}
		update_post_meta( $attachment_id, 'image_guid', $image_guid );
		update_post_meta( $attachment_id, 'img_guid', $image_guid );
		update_post_meta( $attachment_id, 'mobo_image_refresh_identity_recovered_at', time() );
		update_post_meta( $attachment_id, 'mobo_image_refresh_identity_source', 'image-queue' );
		return array( 'image_guid' => $image_guid, 'new_source_url' => $url, 'recovered' => true );
	}

	/** @return array */
	private function unique_identity_rows( $rows ) {
		$unique = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$guid = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
			$url  = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );
			if ( '' !== $guid && '' !== $url ) {
				$unique[ $guid . '|' . $url ] = $row;
			}
		}
		return array_values( $unique );
	}

	/** @return array */
	private function attachment_product_positions( $product_id, $attachment_id ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		$attachment_id = absint( $attachment_id );
		$positions     = array();
		if ( absint( $product->get_image_id() ) === $attachment_id ) {
			return array( 0 );
		}
		$gallery_ids = method_exists( $product, 'get_gallery_image_ids' ) ? $product->get_gallery_image_ids() : array();
		$gallery_ids = array_values( array_map( 'absint', is_array( $gallery_ids ) ? $gallery_ids : array() ) );
		foreach ( $gallery_ids as $index => $gallery_id ) {
			if ( $gallery_id === $attachment_id ) {
				$positions[] = absint( $index );
				$positions[] = absint( $index + 1 );
			}
		}
		return array_values( array_unique( $positions ) );
	}

	/** @return string */
	private function attachment_basename_key( $attachment_id ) {
		$file = get_attached_file( absint( $attachment_id ) );
		return is_string( $file ) && '' !== $file ? strtolower( rawurldecode( (string) pathinfo( basename( $file ), PATHINFO_FILENAME ) ) ) : '';
	}

	/** @return string */
	private function source_basename_key( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return '' !== $path ? strtolower( rawurldecode( (string) pathinfo( basename( $path ), PATHINFO_FILENAME ) ) ) : '';
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

		update_option( $cursor_option, $cycle_complete ? 0 : $cursor_end, false );

		return array(
			'ids'            => $ids,
			'cursorStart'    => $cursor_start,
			'cursorEnd'      => $cursor_end,
			'cycleComplete'  => $cycle_complete,
			'estimatedTotal' => $estimated_total,
		);
	}

	/**
	 * Discover legacy raster candidates for refresh, including very old Mobo
	 * attachments that predate attachment-level image identity metadata.
	 * Ownership is resolved later from Mobo product references or the current
	 * image queue catalog; unrelated site images are ignored.
	 *
	 * @param int    $limit Limit.
	 * @param string $cursor_option Cursor option.
	 * @return array
	 */
	private function get_legacy_refresh_attachment_batch( $limit, $cursor_option ) {
		global $wpdb;

		$limit         = max( 1, min( 5000, absint( $limit ) ) );
		$cursor_option = sanitize_key( (string) $cursor_option );
		$cursor_start  = absint( get_option( $cursor_option, 0 ) );
		$fetch_limit   = $limit + 1;
		$jpg_like      = '%.jpg';
		$jpeg_like     = '%.jpeg';
		$png_like      = '%.png';

		$base_sql = "FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} attached
				ON attached.post_id = p.ID
				AND attached.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment'
				AND p.post_status IN ('inherit', 'private')
				AND (
					p.post_mime_type IN ('image/jpeg', 'image/png')
					OR LOWER(attached.meta_value) LIKE %s
					OR LOWER(attached.meta_value) LIKE %s
					OR LOWER(attached.meta_value) LIKE %s
					OR EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} marker
						WHERE marker.post_id = p.ID
							AND marker.meta_key IN ('image_guid', 'img_guid', 'mobo_source_url')
					)
				)";

		$estimated_total = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID) {$base_sql}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $base_sql contains the three %s placeholders bound immediately below.
					$jpg_like,
					$jpeg_like,
					$png_like
				)
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $base_sql contributes three %s placeholders in addition to %d/%d below.
				"SELECT DISTINCT p.ID {$base_sql}
					AND p.ID > %d
					ORDER BY p.ID ASC
					LIMIT %d",
				$jpg_like,
				$jpeg_like,
				$png_like,
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
			'ids'            => $ids,
			'cursorStart'    => $cursor_start,
			'cursorEnd'      => $cursor_end,
			'cycleComplete'  => $cycle_complete,
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
		$cycle_complete = empty( $ids ) && ! $has_more;

		/* Do not advance the durable cursor here. Stage 6/7 can contain expensive
		 * reference work and PHP may be interrupted mid-batch. The caller advances
		 * the cursor only after each attachment was actually processed. */
		return array(
			'ids'            => $ids,
			'cursorStart'    => $cursor_start,
			'cursorEnd'      => $cursor_end,
			'cycleComplete'  => $cycle_complete,
			'hasMore'        => $has_more,
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
					'localNeedsRepair' => 0,
					'sharedNeedsRepair'=> 0,
					'sharedChecked'    => 0,
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
			'localNeedsRepair'  => $continue_cycle ? absint( isset( $previous['localNeedsRepair'] ) ? $previous['localNeedsRepair'] : 0 ) : 0,
			'sharedNeedsRepair' => $continue_cycle ? absint( isset( $previous['sharedNeedsRepair'] ) ? $previous['sharedNeedsRepair'] : 0 ) : 0,
			'sharedChecked'     => $continue_cycle ? absint( isset( $previous['sharedChecked'] ) ? $previous['sharedChecked'] : 0 ) : 0,
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

			$shared_attachment = class_exists( 'Mobo_Core_Shared_Media' )
				&& Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id );
			if ( $shared_attachment ) {
				$shared_health = Mobo_Core_Shared_Media::attachment_health( $attachment_id );
				if ( ! isset( $result['sharedChecked'] ) ) {
					$result['sharedChecked'] = 0;
				}
				if ( ! isset( $result['sharedNeedsRepair'] ) ) {
					$result['sharedNeedsRepair'] = 0;
				}
				$result['sharedChecked']++;

				if ( ! empty( $shared_health['healthy'] ) ) {
					$result['healthy']++;
					continue;
				}

				$result['needsRepair']++;
				$result['sharedNeedsRepair']++;
				if ( $repair && method_exists( 'Mobo_Core_Shared_Media', 'refresh_attachment_from_manifest' ) ) {
					$refreshed = Mobo_Core_Shared_Media::refresh_attachment_from_manifest( $attachment_id );
					$verified  = $refreshed > 0 ? Mobo_Core_Shared_Media::attachment_health( $attachment_id ) : array();
					if ( ! empty( $verified['healthy'] ) ) {
						$result['repaired']++;
						continue;
					}
					$result['failed']++;
					$shared_health = ! empty( $verified ) ? $verified : $shared_health;
				}

				if ( count( $result['issues'] ) < 20 ) {
					$result['issues'][] = array(
						'attachmentId'    => $attachment_id,
						'file'            => (string) get_post_meta( $attachment_id, '_mobo_shared_media_file', true ),
						'missingSizes'    => array(),
						'missingFiles'    => array(),
						'wrongFormatFiles'=> array(),
						'message'         => sanitize_text_field( isset( $shared_health['message'] ) ? (string) $shared_health['message'] : 'Shared Media attachment is not healthy.' ),
					);
				}
				continue;
			}

			if ( ! isset( $result['localNeedsRepair'] ) ) {
				$result['localNeedsRepair'] = 0;
			}
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
			$result['localNeedsRepair']++;

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
	 * Scan mode is strictly read-only. Delete mode first migrates known references
	 * from the old attachment/URLs to the verified WebP replacement and only then
	 * performs the final conservative reference audit and attachment deletion.
	 *
	 * @param int  $limit Limit.
	 * @param bool $delete Whether reference migration + safe deletion should be attempted.
	 * @return array
	 */
	public function audit_replaced_legacy_attachments( $limit = 500, $delete = false ) {
		$delete        = (bool) $delete;
		$cursor_option = $delete ? self::REPLACED_DELETE_CURSOR_OPTION : self::REPLACED_SCAN_CURSOR_OPTION;
		$option_name   = $delete ? 'mobo_core_image_refresh_last_replaced_delete' : 'mobo_core_image_refresh_last_replaced_scan';

		/* Reference cleanup is intentionally much smaller than the generic image
		 * scan batch. Large metadata tables can make one old attachment expensive. */
		$limit = max( 1, min( $delete ? 5 : 50, absint( $limit ) ) );

		$empty_result = array(
			'mode'                     => $delete ? 'delete' : 'scan',
			'scanned'                  => 0,
			'ready'                    => 0,
			'deleted'                  => 0,
			'failed'                   => 0,
			'blocked'                  => 0,
			'errors'                   => 0,
			'stillUsed'                => 0,
			'externalReferences'       => 0,
			'migrationCandidates'      => 0,
			'referencesMigrated'       => 0,
			'referenceRowsUpdated'     => 0,
			'referenceMigrationErrors' => 0,
			'remainingReferences'      => 0,
			'invalidReplacement'       => 0,
			'invalidSubsizes'          => 0,
			'issues'                   => array(),
			'cursorStart'              => absint( get_option( $cursor_option, 0 ) ),
			'cursorEnd'                => absint( get_option( $cursor_option, 0 ) ),
			'cycleComplete'            => false,
			'checkedAt'                => time(),
		);

		if ( ! $this->is_unlocked() ) {
			$result = $this->locked_result( $empty_result );
			update_option( $option_name, $result, false );
			return $result;
		}

		if ( $delete && ! Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ) ) {
			$result            = $empty_result;
			$result['status']  = 'disabled';
			$result['message'] = 'ابتدا گزینه حذف پیوست قدیمی بعد از جایگزینی امن را فعال و تنظیمات را ذخیره کنید.';
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

		$result = $empty_result;
		$result['status']         = 'processing';
		$result['cursorStart']    = $cursor_start;
		$result['cursorEnd']      = $cursor_start;
		$result['cycleComplete']  = false;
		$result['estimatedTotal'] = isset( $batch['estimatedTotal'] ) ? absint( $batch['estimatedTotal'] ) : 0;
		$result['cycleStartedAt'] = $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time();
		$result['checkedAt']      = time();

		if ( $continue_cycle ) {
			foreach ( array( 'scanned', 'ready', 'deleted', 'failed', 'blocked', 'errors', 'stillUsed', 'externalReferences', 'migrationCandidates', 'referencesMigrated', 'referenceRowsUpdated', 'referenceMigrationErrors', 'remainingReferences', 'invalidReplacement', 'invalidSubsizes' ) as $counter ) {
				$result[ $counter ] = absint( isset( $previous[ $counter ] ) ? $previous[ $counter ] : 0 );
			}
			$result['issues'] = ! empty( $previous['issues'] ) && is_array( $previous['issues'] ) ? array_slice( $previous['issues'], 0, 20 ) : array();
		}

		/* Expose a running heartbeat before expensive work starts. */
		update_option( $option_name, $result, false );

		if ( empty( $attachment_ids ) ) {
			$result['status']           = 'processed';
			$result['cycleComplete']    = true;
			$result['passProgress']     = 0;
			$result['stableComplete']   = $delete;
			$result['needsAnotherPass'] = false;
			$result['checkedAt']        = time();
			update_option( $cursor_option, 0, false );
			update_option( $option_name, $result, false );
			return $result;
		}

		$processed_in_batch = 0;
		$batch_started      = microtime( true );
		$time_budget        = $delete ? 8.0 : 12.0;

		foreach ( $attachment_ids as $old_attachment_id ) {
			$old_attachment_id = absint( $old_attachment_id );
			$result['scanned']++;
			$processed_in_batch++;
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
				} elseif ( ! $delete ) {
					/* Stage 6 verifies replacement health only. The expensive global
					 * reference migration/audit belongs to Stage 7 and runs once. */
					$product_refs = $this->count_all_products_using_attachment( $old_attachment_id );
					if ( $product_refs > 0 ) {
						$result['stillUsed']++;
					}
					$result['migrationCandidates']++;
					$reason = $product_refs > 0
						? 'پیوست قدیمی هنوز در محصول مرجع دارد؛ مرحله ۷ ابتدا مرجع را به WebP منتقل و سپس ایمنی حذف را بررسی می کند.'
						: 'WebP جایگزین معتبر است؛ مرحله ۷ انتقال مراجع ساختاری و Audit نهایی را انجام می دهد و فقط در صورت صفر شدن مراجع حذف می کند.';
				} else {
					$delete_result = $this->safe_delete_old_attachment( $old_attachment_id, $new_attachment_id );
					$migration     = isset( $delete_result['referenceMigration'] ) && is_array( $delete_result['referenceMigration'] ) ? $delete_result['referenceMigration'] : array();
					if ( ! empty( $migration['attempted'] ) && absint( isset( $migration['updatedRows'] ) ? $migration['updatedRows'] : 0 ) > 0 ) {
						$result['referencesMigrated']++;
					}
					$result['referenceRowsUpdated'] += absint( isset( $migration['updatedRows'] ) ? $migration['updatedRows'] : 0 );
					$result['referenceMigrationErrors'] += absint( isset( $migration['errors'] ) ? $migration['errors'] : 0 );

					if ( ! empty( $delete_result['deleted'] ) ) {
						$result['ready']++;
						$result['deleted']++;
					} else {
						$result['failed']++; // Backward-compatible aggregate: blocked + operational errors.
						$outcome = isset( $delete_result['outcome'] ) ? sanitize_key( (string) $delete_result['outcome'] ) : 'blocked';
						if ( 'error' === $outcome ) {
							$result['errors']++;
						} else {
							$result['blocked']++;
						}
						if ( ! empty( $delete_result['remainingReferences'] ) ) {
							$result['remainingReferences']++;
						}
						$reason = isset( $delete_result['message'] ) ? (string) $delete_result['message'] : 'پیوست قدیمی برای بررسی بعدی نگه داشته شد.';
						if ( ! empty( $delete_result['referenceLocations'] ) && is_array( $delete_result['referenceLocations'] ) ) {
							$reason .= ' محل مرجع: ' . implode( '، ', array_slice( array_map( 'sanitize_text_field', $delete_result['referenceLocations'] ), 0, 5 ) );
						}
					}
				}
			}

			if ( '' !== $reason && count( $result['issues'] ) < 20 ) {
				$old_file = get_attached_file( $old_attachment_id );
				$result['issues'][] = array(
					'oldAttachmentId' => $old_attachment_id,
					'oldFile'         => is_string( $old_file ) ? $this->relative_upload_path( $old_file ) : '',
					'newAttachmentId' => $new_attachment_id,
					'reason'          => sanitize_text_field( $reason ),
				);
			}

			/* Advance only after this attachment completed. */
			$result['cursorEnd'] = $old_attachment_id;
			$result['checkedAt'] = time();
			update_option( $cursor_option, $old_attachment_id, false );
			update_option( $option_name, $result, false );

			if ( ( microtime( true ) - $batch_started ) >= $time_budget ) {
				break;
			}
		}

		$processed_all_fetched   = $processed_in_batch >= count( $attachment_ids );
		$has_more                = ! empty( $batch['hasMore'] );
		$result['cycleComplete'] = $processed_all_fetched && ! $has_more;
		$result['status']        = 'processed';
		$result['checkedAt']     = time();

		if ( $result['cycleComplete'] ) {
			update_option( $cursor_option, 0, false );
		} elseif ( $processed_in_batch <= 0 ) {
			update_option( $cursor_option, $cursor_start, false );
		}

		/* A Stage 7 pass is final only when a full pass makes no new progress.
		 * If references were migrated or attachments were deleted, another pass can
		 * expose newly-deletable attachments and must be scheduled automatically. */
		$result['passProgress'] = $delete
			? absint( isset( $result['deleted'] ) ? $result['deleted'] : 0 )
				+ absint( isset( $result['referenceRowsUpdated'] ) ? $result['referenceRowsUpdated'] : 0 )
			: 0;
		$result['stableComplete'] = $delete
			&& ! empty( $result['cycleComplete'] )
			&& 0 === absint( $result['passProgress'] );
		$result['needsAnotherPass'] = $delete
			&& ! empty( $result['cycleComplete'] )
			&& absint( $result['passProgress'] ) > 0;

		update_option( $option_name, $result, false );
		return $result;
	}

	/**
	 * Verify one WebP attachment is actually ready for product linkage. Shared
	 * attachments must match their committed manifest; local attachments must have
	 * complete WordPress/WooCommerce subsizes, repairing only when inspection finds
	 * a real gap. This is also used by the normal image-storage queue.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function ensure_webp_attachment_ready( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'پیوست WebP معتبر نیست.' );
		}

		/* A shared marker remains authoritative even during a temporary mount outage.
		 * Never fall through to local subsize generation for worker-owned media. */
		if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			if ( ! Mobo_Core_Shared_Media::is_enabled() ) {
				return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'پیوست متعلق به Shared Media است، اما مخزن مشترک اکنون قابل خواندن نیست.' );
			}

			$health = Mobo_Core_Shared_Media::attachment_health( $attachment_id );
			return array(
				'success'    => ! empty( $health['healthy'] ),
				'generated'  => 0,
				'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
				'message'    => isset( $health['message'] ) ? (string) $health['message'] : 'وضعیت مخزن اشتراکی تصویر مشخص نیست.',
			);
		}

		if ( ! $this->is_valid_new_attachment( $attachment_id ) || ! $this->is_webp_attachment( $attachment_id ) ) {
			return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'پیوست WebP معتبر و قابل خواندن نیست.' );
		}

		$health = $this->inspect_attachment_subsizes( $attachment_id );
		if ( ! empty( $health['healthy'] ) ) {
			return array(
				'success'    => true,
				'generated'  => 0,
				'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
				'message'    => isset( $health['message'] ) ? (string) $health['message'] : 'برش های تصویر کامل هستند.',
			);
		}

		return $this->ensure_attachment_subsizes( $attachment_id );
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
				'healthy'                => false,
				'code'                   => 'invalid_attachment',
				'message'                => 'پیوست WebP نامعتبر است.',
				'missingSizes'           => array(),
				'missingFiles'           => array(),
				'wrongFormatFiles'       => array(),
				'dimensionMismatchFiles' => array(),
				'registered'             => 0,
				'editorSupported'        => false,
			);
		}

		$this->load_media_dependencies();
		$file = get_attached_file( $attachment_id );
		$file = is_string( $file ) ? $this->normalize_file_path( $file ) : '';
		if ( '' === $file || ! is_file( $file ) || filesize( $file ) <= 0 ) {
			return array(
				'healthy'                => false,
				'code'                   => 'missing_original',
				'message'                => 'فایل اصلی WebP وجود ندارد یا خالی است.',
				'missingSizes'           => array(),
				'missingFiles'           => array( '' !== $file ? basename( $file ) : 'فایل اصلی' ),
				'wrongFormatFiles'       => array(),
				'dimensionMismatchFiles' => array(),
				'registered'             => 0,
				'editorSupported'        => false,
			);
		}

		$stored_mime       = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$physical_ext      = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$actual_mime       = function_exists( 'wp_get_image_mime' ) ? strtolower( (string) wp_get_image_mime( $file ) ) : '';
		$original_format_ok = 'webp' === $physical_ext
			&& 'image/webp' === $stored_mime
			&& ( '' === $actual_mime || 'image/webp' === $actual_mime );

		$metadata          = wp_get_attachment_metadata( $attachment_id );
		$attached_relative = $this->relative_upload_path( $file );
		$metadata_file     = is_array( $metadata ) && ! empty( $metadata['file'] ) ? ltrim( $this->normalize_file_path( (string) $metadata['file'] ), '/' ) : '';
		$original_dims     = $this->get_physical_image_dimensions( $file );
		$metadata_valid    = is_array( $metadata )
			&& '' !== $metadata_file
			&& '' !== $attached_relative
			&& hash_equals( ltrim( $this->normalize_file_path( $attached_relative ), '/' ), $metadata_file )
			&& ! empty( $metadata['width'] )
			&& ! empty( $metadata['height'] )
			&& ! empty( $original_dims )
			&& absint( $metadata['width'] ) === absint( $original_dims[0] )
			&& absint( $metadata['height'] ) === absint( $original_dims[1] );
		$registered               = is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0;
		$missing_sizes            = array();
		$wrong_format_files       = array();
		$dimension_mismatch_files = array();
		$registered_expected_dims = ! empty( $original_dims )
			? $this->get_registered_subsize_expected_dimensions( absint( $original_dims[0] ), absint( $original_dims[1] ) )
			: array();
		$registered_size_names = $this->get_registered_subsize_names();

		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				$size_file = is_array( $size_data ) && ! empty( $size_data['file'] ) ? basename( (string) $size_data['file'] ) : '';
				if ( '' === $size_file ) {
					$dimension_mismatch_files[] = sanitize_key( (string) $size_name );
					continue;
				}

				$size_path  = $this->normalize_file_path( trailingslashit( $dir ) . $size_file );
				$wrong_mime = false;
				if ( is_file( $size_path ) && function_exists( 'wp_get_image_mime' ) ) {
					$wrong_mime = 'image/webp' !== strtolower( (string) wp_get_image_mime( $size_path ) );
				}
				if ( 'webp' !== strtolower( pathinfo( $size_file, PATHINFO_EXTENSION ) ) || $wrong_mime ) {
					$wrong_format_files[] = $size_file;
				}

				if ( is_file( $size_path ) && filesize( $size_path ) > 0 ) {
					$actual_dims = $this->get_physical_image_dimensions( $size_path );
					$expected_w  = is_array( $size_data ) ? absint( isset( $size_data['width'] ) ? $size_data['width'] : 0 ) : 0;
					$expected_h  = is_array( $size_data ) ? absint( isset( $size_data['height'] ) ? $size_data['height'] : 0 ) : 0;
					if ( empty( $actual_dims ) || $expected_w <= 0 || $expected_h <= 0 || absint( $actual_dims[0] ) !== $expected_w || absint( $actual_dims[1] ) !== $expected_h ) {
						$dimension_mismatch_files[] = $size_file;
					}

					/* A metadata row can be internally self-consistent and still be stale for
					 * the site's current registered image size definition. Recompute the
					 * expected output from the physical original so theme/settings changes
					 * cannot leave an old cut permanently marked healthy. */
					$size_key = sanitize_key( (string) $size_name );
					if ( isset( $registered_size_names[ $size_key ] ) && ! isset( $registered_expected_dims[ $size_key ] ) ) {
						/* The size is registered, but the current original is too small (or the
						 * current resize rules intentionally produce no derivative). A stale cut
						 * left from an older/larger original must not remain authoritative. */
						$dimension_mismatch_files[] = $size_file;
					} elseif ( isset( $registered_expected_dims[ $size_key ] ) ) {
						$registered_w = absint( $registered_expected_dims[ $size_key ][0] );
						$registered_h = absint( $registered_expected_dims[ $size_key ][1] );
						if ( $expected_w !== $registered_w || $expected_h !== $registered_h
							|| empty( $actual_dims )
							|| absint( $actual_dims[0] ) !== $registered_w
							|| absint( $actual_dims[1] ) !== $registered_h ) {
							$dimension_mismatch_files[] = $size_file;
						}
					}
				}
			}
		}
		$wrong_format_files       = array_values( array_unique( array_filter( $wrong_format_files ) ) );
		$dimension_mismatch_files = array_values( array_unique( array_filter( $dimension_mismatch_files ) ) );

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
		$healthy          = $original_format_ok && $metadata_valid && empty( $missing_sizes ) && empty( $missing_files ) && empty( $wrong_format_files ) && empty( $dimension_mismatch_files );

		if ( $healthy && $editor_supported ) {
			$code    = 'healthy';
			$message = 'تمام برش های لازم موجود هستند، مسیر و ابعاد متادیتا با فایل واقعی تطبیق دارد و موتور تصویر سرور نیز WebP را پشتیبانی می‌کند.';
		} elseif ( $healthy ) {
			$code    = 'healthy_editor_unavailable';
			$message = 'برش های فعلی کامل هستند، اما موتور تصویر سرور امکان بازسازی WebP را ندارد: ' . $editor->get_error_message();
		} elseif ( ! $original_format_ok ) {
			$code    = 'wrong_original_storage_format';
			$message = 'فایل اصلی از نظر محتوای تصویر WebP است، اما پسوند فایل یا MIME پیوست با image/webp سازگار نیست؛ برای جلوگیری از Content-Type اشتباه باید Attachment تمیز دوباره import شود.';
		} elseif ( ! $metadata_valid && ! $editor_supported ) {
			$code    = 'unsupported_editor';
			$message = 'مسیر/ابعاد متادیتای تصویر با فایل اصلی سازگار نیست و موتور تصویر سرور نیز قادر به بازسازی WebP نیست: ' . $editor->get_error_message();
		} elseif ( ! $editor_supported ) {
			$code    = 'unsupported_editor';
			$message = 'یک یا چند برش ناقص یا ناسازگار است و موتور تصویر سرور قادر به بازسازی WebP نیست: ' . $editor->get_error_message();
		} elseif ( ! $metadata_valid ) {
			$code    = 'stale_metadata';
			$message = 'مسیر یا ابعاد متادیتای اصلی تصویر با فایل واقعی تطبیق ندارد و باید بازسازی شود.';
		} elseif ( ! empty( $wrong_format_files ) ) {
			$code    = 'wrong_subsize_format';
			$message = 'یک یا چند برش با فرمتی غیر از WebP ثبت شده است و باید دوباره ساخته شود.';
		} elseif ( ! empty( $dimension_mismatch_files ) ) {
			$code    = 'wrong_subsize_dimensions';
			$message = 'ابعاد واقعی یک یا چند برش با متادیتای ثبت‌شده تطبیق ندارد و باید دوباره ساخته شود.';
		} else {
			$code    = 'missing_subsizes';
			$message = 'یک یا چند برش لازم در متادیتا یا فایل های uploads ناقص است.';
		}

		return array(
			'healthy'                => $healthy,
			'code'                   => $code,
			'message'                => $message,
			'missingSizes'           => $missing_sizes,
			'missingFiles'           => $missing_files,
			'wrongFormatFiles'       => $wrong_format_files,
			'dimensionMismatchFiles' => $dimension_mismatch_files,
			'registered'             => $registered,
			'editorSupported'        => $editor_supported,
			'metadataValid'          => $metadata_valid,
		);
	}

	/**
	 * Public, read-only WebP storage health used by the durable image queue. It
	 * intentionally performs no generation; a bad done row is merely requeued and
	 * the normal linkage path repairs it under the subsize mutation lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function inspect_webp_attachment_health( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array( 'healthy' => false, 'code' => 'invalid_attachment', 'message' => 'Invalid attachment.' );
		}

		if ( '1' === (string) get_post_meta( $attachment_id, 'mobo_sync_incomplete', true ) ) {
			return array( 'healthy' => false, 'code' => 'incomplete_commit', 'message' => 'Attachment import has not crossed the final storage commit boundary.' );
		}

		if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			return Mobo_Core_Shared_Media::attachment_health( $attachment_id, true );
		}

		return $this->inspect_attachment_subsizes( $attachment_id );
	}

	/**
	 * Read actual image dimensions without trusting attachment metadata.
	 *
	 * @param string $path Absolute file path.
	 * @return array<int,int>
	 */
	private function get_physical_image_dimensions( $path ) {
		$path = $this->normalize_file_path( (string) $path );
		if ( '' === $path || ! is_file( $path ) ) {
			return array();
		}

		$dimensions = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback only when the WordPress helper is unavailable.
		if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return array();
		}

		return array( absint( $dimensions[0] ), absint( $dimensions[1] ) );
	}

	/**
	 * Return the names of sizes currently registered with WordPress. A registered
	 * size may intentionally have no derivative for a small original; that absence
	 * is meaningful when deciding whether an old metadata row is stale.
	 *
	 * @return array<string,bool>
	 */
	private function get_registered_subsize_names() {
		if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
			return array();
		}

		$definitions = wp_get_registered_image_subsizes();
		if ( ! is_array( $definitions ) ) {
			return array();
		}

		$names = array();
		foreach ( array_keys( $definitions ) as $name ) {
			$key = sanitize_key( (string) $name );
			if ( '' !== $key ) {
				$names[ $key ] = true;
			}
		}
		return $names;
	}

	/**
	 * Calculate the physical output dimensions expected for the site's current
	 * registered image sizes. This catches stale-but-self-consistent metadata
	 * after theme/size setting changes, which wp_get_missing_image_subsizes()
	 * cannot detect because that API primarily checks size names.
	 *
	 * @param int $original_width Physical original width.
	 * @param int $original_height Physical original height.
	 * @return array<string,array<int,int>>
	 */
	private function get_registered_subsize_expected_dimensions( $original_width, $original_height ) {
		$original_width  = absint( $original_width );
		$original_height = absint( $original_height );
		if ( $original_width <= 0 || $original_height <= 0 || ! function_exists( 'wp_get_registered_image_subsizes' ) || ! function_exists( 'image_resize_dimensions' ) ) {
			return array();
		}

		$registered = wp_get_registered_image_subsizes();
		if ( ! is_array( $registered ) ) {
			return array();
		}

		$expected = array();
		foreach ( $registered as $size_name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$target_width  = absint( isset( $definition['width'] ) ? $definition['width'] : 0 );
			$target_height = absint( isset( $definition['height'] ) ? $definition['height'] : 0 );
			$crop          = isset( $definition['crop'] ) ? $definition['crop'] : false;
			if ( $target_width <= 0 && $target_height <= 0 ) {
				continue;
			}

			$resized = image_resize_dimensions( $original_width, $original_height, $target_width, $target_height, $crop );
			if ( ! is_array( $resized ) || ! isset( $resized[4], $resized[5] ) || absint( $resized[4] ) <= 0 || absint( $resized[5] ) <= 0 ) {
				continue;
			}

			$expected[ sanitize_key( (string) $size_name ) ] = array( absint( $resized[4] ), absint( $resized[5] ) );
		}

		return $expected;
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
			delete_option( self::MISSING_SCAN_CURSOR_OPTION );
			delete_option( 'mobo_core_image_refresh_last_scan' );
		}

		delete_option( self::ENQUEUE_CURSOR_OPTION );
		delete_option( self::MISSING_ENQUEUE_CURSOR_OPTION );
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

		/* Keep the administrator's persistent old-attachment deletion preference. */
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
		if ( $attachment_id <= 0 ) {
			return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => 'پیوست تصویر جایگزین نامعتبر است.' );
		}

		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return $this->ensure_attachment_subsizes_unlocked( $attachment_id );
		}

		$lock_name = 'image_subsizes_' . $attachment_id;
		$lock_token = Mobo_Core_Lock::acquire( $lock_name, 300 );
		if ( false === $lock_token ) {
			/* Another queue/refresh request may already be repairing this attachment.
			 * Re-read health once; otherwise defer instead of racing metadata/files. */
			$health = $this->inspect_attachment_subsizes( $attachment_id );
			if ( ! empty( $health['healthy'] ) ) {
				return array(
					'success'    => true,
					'generated'  => 0,
					'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
					'message'    => 'برش های تصویر توسط اجرای همزمان تکمیل شده‌اند.',
				);
			}

			return array(
				'success'    => false,
				'generated'  => 0,
				'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
				'message'    => 'بازسازی برش‌های این تصویر در اجرای دیگری در حال انجام است؛ این ردیف بعداً دوباره بررسی می‌شود.',
			);
		}

		try {
			/* Re-check after entering the critical section; the previous owner may have
			 * completed immediately before this lease was acquired. */
			$health = $this->inspect_attachment_subsizes( $attachment_id );
			if ( ! empty( $health['healthy'] ) ) {
				return array(
					'success'    => true,
					'generated'  => 0,
					'registered' => isset( $health['registered'] ) ? absint( $health['registered'] ) : 0,
					'message'    => 'تمام برش های لازم از قبل کامل هستند.',
				);
			}

			return $this->ensure_attachment_subsizes_unlocked( $attachment_id );
		} finally {
			Mobo_Core_Lock::release( $lock_name, $lock_token );
		}
	}

	/**
	 * Generate/repair local subsizes while the per-attachment mutation lock is held.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function ensure_attachment_subsizes_unlocked( $attachment_id ) {
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

		if ( class_exists( 'Mobo_Core_Image_Storage' ) ) {
			$storage = Mobo_Core_Image_Storage::check();
			if ( empty( $storage['ready'] ) ) {
				return array(
					'success'    => false,
					'generated'  => 0,
					'registered' => 0,
					'message'    => 'ساخت برش‌ها به دلیل آماده نبودن فضای ذخیره‌سازی متوقف شد: ' . sanitize_text_field( isset( $storage['message'] ) ? (string) $storage['message'] : 'فضای کافی وجود ندارد.' ),
				);
			}
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

		/* Missing metadata needs a full regeneration. wp_update_image_subsizes() is
		 * optimized for an existing metadata array and is not a reliable recovery
		 * primitive when the root record itself was lost. */
		if ( ! is_array( $metadata ) ) {
			$generated_metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( is_wp_error( $generated_metadata ) || ! is_array( $generated_metadata ) ) {
				return array( 'success' => false, 'generated' => 0, 'registered' => 0, 'message' => is_wp_error( $generated_metadata ) ? 'وردپرس نتوانست متادیتای گمشده تصویر را بازسازی کند. جزئیات فنی: ' . sanitize_text_field( $generated_metadata->get_error_message() ) : 'وردپرس نتوانست متادیتای گمشده تصویر را بازسازی کند.' );
			}
			wp_update_attachment_metadata( $attachment_id, $generated_metadata );
			$metadata = $generated_metadata;
		}

		if ( is_array( $metadata ) ) {
			$attached_relative = $this->relative_upload_path( $file );
			if ( '' !== $attached_relative && ( empty( $metadata['file'] ) || $this->normalize_file_path( (string) $metadata['file'] ) !== $this->normalize_file_path( $attached_relative ) ) ) {
				$metadata['file'] = $attached_relative;
				$metadata_changed = true;
			}

			$actual_original_dims = $this->get_physical_image_dimensions( $file );
			if ( ! empty( $actual_original_dims )
				&& ( absint( isset( $metadata['width'] ) ? $metadata['width'] : 0 ) !== absint( $actual_original_dims[0] )
					|| absint( isset( $metadata['height'] ) ? $metadata['height'] : 0 ) !== absint( $actual_original_dims[1] ) ) ) {
				$metadata['width']  = absint( $actual_original_dims[0] );
				$metadata['height'] = absint( $actual_original_dims[1] );
				$metadata_changed   = true;
			}

			$actual_original_size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent removal is handled by the earlier existence guard.
			if ( false !== $actual_original_size && $actual_original_size > 0 && absint( isset( $metadata['filesize'] ) ? $metadata['filesize'] : 0 ) !== absint( $actual_original_size ) ) {
				$metadata['filesize'] = absint( $actual_original_size );
				$metadata_changed      = true;
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

		$registered_expected_dims = ! empty( $actual_original_dims )
			? $this->get_registered_subsize_expected_dimensions( absint( $actual_original_dims[0] ), absint( $actual_original_dims[1] ) )
			: array();
		$registered_size_names = $this->get_registered_subsize_names();

		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				$size_file = is_array( $size_data ) && ! empty( $size_data['file'] )
					? $this->normalize_file_path( trailingslashit( $dir ) . basename( (string) $size_data['file'] ) )
					: '';

				$wrong_format = is_array( $size_data ) && ! empty( $size_data['file'] )
					&& 'webp' !== strtolower( pathinfo( (string) $size_data['file'], PATHINFO_EXTENSION ) );
				if ( ! $wrong_format && '' !== $size_file && is_file( $size_file ) && function_exists( 'wp_get_image_mime' ) ) {
					$wrong_format = 'image/webp' !== strtolower( (string) wp_get_image_mime( $size_file ) );
				}

				$wrong_dimensions = false;
				if ( ! $wrong_format && '' !== $size_file && is_file( $size_file ) && filesize( $size_file ) > 0 ) {
					$actual_dims = $this->get_physical_image_dimensions( $size_file );
					$expected_w  = is_array( $size_data ) ? absint( isset( $size_data['width'] ) ? $size_data['width'] : 0 ) : 0;
					$expected_h  = is_array( $size_data ) ? absint( isset( $size_data['height'] ) ? $size_data['height'] : 0 ) : 0;
					$wrong_dimensions = empty( $actual_dims ) || $expected_w <= 0 || $expected_h <= 0 || absint( $actual_dims[0] ) !== $expected_w || absint( $actual_dims[1] ) !== $expected_h;

					$size_key = sanitize_key( (string) $size_name );
					if ( ! $wrong_dimensions && isset( $registered_size_names[ $size_key ] ) && ! isset( $registered_expected_dims[ $size_key ] ) ) {
						$wrong_dimensions = true;
					} elseif ( ! $wrong_dimensions && isset( $registered_expected_dims[ $size_key ] ) ) {
						$registered_w = absint( $registered_expected_dims[ $size_key ][0] );
						$registered_h = absint( $registered_expected_dims[ $size_key ][1] );
						$wrong_dimensions = $expected_w !== $registered_w || $expected_h !== $registered_h
							|| absint( $actual_dims[0] ) !== $registered_w || absint( $actual_dims[1] ) !== $registered_h;
					}
				}

				if ( '' === $size_file || ! is_file( $size_file ) || filesize( $size_file ) <= 0 || $wrong_format || $wrong_dimensions ) {
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
			$pattern = '/^' . preg_quote( $stem, '/' ) . '(?:-\d+)?(?:(?:-e\d{6,})|(?:-\d+x\d+)|(?:-scaled)|(?:-rotated))*\.(?:jpe?g|png)$/i';
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

		if ( '' !== $file && is_file( $file ) && function_exists( 'wp_get_image_mime' ) ) {
			$actual_mime = strtolower( (string) wp_get_image_mime( $file ) );
			if ( '' !== $actual_mime ) {
				return 'image/webp' === $actual_mime;
			}
		}

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

		if ( $attachment_id <= 0 || ! is_string( $file ) || '' === $file || ! is_file( $file ) || filesize( $file ) <= 0 ) {
			return false;
		}

		$stored_mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
		if ( 0 !== strpos( $stored_mime, 'image/' ) ) {
			return false;
		}

		if ( function_exists( 'wp_get_image_mime' ) ) {
			$actual_mime = strtolower( (string) wp_get_image_mime( $file ) );
			if ( '' === $actual_mime || 0 !== strpos( $actual_mime, 'image/' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Before migrating every reference from one old attachment to one replacement,
	 * prove that every product still using the old attachment resolves to the same
	 * current Mobo image identity. One legacy attachment can be shared by multiple
	 * products; if their desired images later diverge, a global replacement would
	 * silently assign the wrong image to some products.
	 *
	 * @return array
	 */
	private function product_references_match_replacement_identity( $old_attachment_id, $new_attachment_id ) {
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		$product_ids       = $this->find_products_using_attachment( $old_attachment_id );
		if ( empty( $product_ids ) ) {
			return array( 'safe' => true, 'message' => '' );
		}

		$expected_guid   = $this->get_image_guid_from_attachment( $new_attachment_id );
		$expected_source = esc_url_raw( (string) get_post_meta( $new_attachment_id, 'mobo_source_url', true ) );
		if ( '' === $expected_guid || '' === $expected_source ) {
			return array( 'safe' => false, 'message' => 'هویت GUID/Source تصویر جایگزین برای انتقال سراسری مرجع کامل نیست.' );
		}

		foreach ( $product_ids as $product_id ) {
			$identity = $this->resolve_refresh_identity( $old_attachment_id, absint( $product_id ), false );
			$guid     = sanitize_text_field( (string) ( isset( $identity['image_guid'] ) ? $identity['image_guid'] : '' ) );
			$source   = esc_url_raw( (string) ( isset( $identity['new_source_url'] ) ? $identity['new_source_url'] : '' ) );
			if ( '' === $guid || '' === $source || ! hash_equals( $expected_guid, $guid ) || ! hash_equals( $expected_source, $source ) ) {
				return array(
					'safe'    => false,
					'message' => 'پیوست قدیمی هنوز توسط محصولی با هویت تصویر متفاوت یا نامشخص استفاده می‌شود؛ برای جلوگیری از جایگزینی اشتباه، حذف و انتقال سراسری متوقف شد.',
				);
			}
		}

		return array( 'safe' => true, 'message' => '' );
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
	 * Find the attachment currently used by one product for the same remote image
	 * GUID, regardless of its older source URL. This is used only to continue a
	 * refresh that was superseded after a prior worker had already replaced the
	 * original attachment.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Remote image GUID.
	 * @return int
	 */
	private function find_product_attachment_for_image_guid( $product_id, $image_guid ) {
		$product_id = absint( $product_id );
		$image_guid = strtolower( trim( sanitize_text_field( (string) $image_guid ) ) );
		if ( $product_id <= 0 || '' === $image_guid ) {
			return 0;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}

		$ids = array();
		$image_id = absint( $product->get_image_id() );
		if ( $image_id > 0 ) {
			$ids[] = $image_id;
		}
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			$ids = array_merge( $ids, array_map( 'absint', (array) $product->get_gallery_image_ids() ) );
		}

		foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $attachment_id ) {
			$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
			if ( '' === $stored_guid ) {
				$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
			}
			if ( '' === $stored_guid && class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
				$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, '_mobo_shared_media_image_id', true ) );
			}
			if ( '' !== $stored_guid && hash_equals( strtolower( trim( $stored_guid ) ), $image_guid ) ) {
				return absint( $attachment_id );
			}
		}

		return 0;
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
	 * Build exact text replacements for one verified old -> new attachment pair.
	 * Original URLs, registered WordPress subsize URLs, relative URL paths,
	 * basenames and the wp-image-* class are included.
	 *
	 * @param int $old_attachment_id Old attachment.
	 * @param int $new_attachment_id New attachment.
	 * @return array
	 */
	private function build_attachment_text_replacement_map( $old_attachment_id, $new_attachment_id ) {
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		$map               = array();

		if ( $old_attachment_id <= 0 || $new_attachment_id <= 0 ) {
			return $map;
		}

		$map[ 'wp-image-' . $old_attachment_id ] = 'wp-image-' . $new_attachment_id;
		$map[ 'attachment_' . $old_attachment_id ] = 'attachment_' . $new_attachment_id;
		$old_url = wp_get_attachment_url( $old_attachment_id );
		$new_url = wp_get_attachment_url( $new_attachment_id );
		$this->append_attachment_url_replacement( $map, $old_url, $new_url );

		$old_meta = wp_get_attachment_metadata( $old_attachment_id );
		$new_meta = wp_get_attachment_metadata( $new_attachment_id );
		$old_meta = is_array( $old_meta ) ? $old_meta : array();
		$new_meta = is_array( $new_meta ) ? $new_meta : array();

		$old_sizes = isset( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ? $old_meta['sizes'] : array();
		$new_sizes = isset( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ? $new_meta['sizes'] : array();
		if ( is_string( $old_url ) && '' !== $old_url && is_string( $new_url ) && '' !== $new_url ) {
			$old_dir = trailingslashit( dirname( $old_url ) );
			$new_dir = trailingslashit( dirname( $new_url ) );
			foreach ( $old_sizes as $size_name => $old_size ) {
				if ( empty( $old_size['file'] ) || empty( $new_sizes[ $size_name ]['file'] ) ) {
					continue;
				}
				$this->append_attachment_url_replacement(
					$map,
					$old_dir . ltrim( (string) $old_size['file'], '/' ),
					$new_dir . ltrim( (string) $new_sizes[ $size_name ]['file'], '/' )
				);
			}
		}

		uksort(
			$map,
			static function ( $left, $right ) {
				return strlen( (string) $right ) <=> strlen( (string) $left );
			}
		);

		return $map;
	}

	/**
	 * Add URL, URL-path and basename forms to a replacement map.
	 *
	 * @param array  $map Map by reference.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return void
	 */
	private function append_attachment_url_replacement( &$map, $old_url, $new_url ) {
		$old_url = is_string( $old_url ) ? trim( $old_url ) : '';
		$new_url = is_string( $new_url ) ? trim( $new_url ) : '';
		if ( '' === $old_url || '' === $new_url || $old_url === $new_url ) {
			return;
		}

		$map[ $old_url ] = $new_url;
		$old_path = (string) wp_parse_url( $old_url, PHP_URL_PATH );
		$new_path = (string) wp_parse_url( $new_url, PHP_URL_PATH );
		if ( '' !== $old_path && '' !== $new_path && $old_path !== $new_path ) {
			$map[ $old_path ] = $new_path;
		}

		$old_base = basename( $old_path );
		$new_base = basename( $new_path );
		if ( '' !== $old_base && '' !== $new_base && $old_base !== $new_base ) {
			$map[ $old_base ] = $new_base;
		}
	}

	/**
	 * Return searchable tokens that prove an attachment is still referenced.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function get_attachment_reference_tokens( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return array();
		}

		$tokens = array( 'wp-image-' . $attachment_id, 'attachment_' . $attachment_id );
		$url    = wp_get_attachment_url( $attachment_id );
		$meta   = wp_get_attachment_metadata( $attachment_id );
		$meta   = is_array( $meta ) ? $meta : array();

		if ( is_string( $url ) && '' !== $url ) {
			$tokens[] = $url;
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path ) {
				$tokens[] = $path;
				$tokens[] = basename( $path );
			}
			$dir   = trailingslashit( dirname( $url ) );
			$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
			foreach ( $sizes as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_url = $dir . ltrim( (string) $size['file'], '/' );
				$tokens[] = $size_url;
				$size_path = (string) wp_parse_url( $size_url, PHP_URL_PATH );
				if ( '' !== $size_path ) {
					$tokens[] = $size_path;
					$tokens[] = basename( $size_path );
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $tokens ) ) ) );
	}

	/**
	 * Prepare a SQL fragment for scalar/serialized attachment IDs and known URL tokens.
	 *
	 * @param string $column SQL column name generated internally.
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $tokens Search tokens.
	 * @param array  $params Prepared-query parameters by reference.
	 * @return string
	 */
	private function build_attachment_reference_sql( $column, $attachment_id, $tokens, &$params ) {
		global $wpdb;
		$value      = (string) absint( $attachment_id );
		$conditions = array( $column . ' = %s', $column . ' LIKE %s', $column . ' LIKE %s' );
		$params[]   = $value;
		$params[]   = '%i:' . $wpdb->esc_like( $value ) . ';%';
		$params[]   = '%s:' . strlen( $value ) . ':"' . $wpdb->esc_like( $value ) . '";%';

		/* Common JSON/block representations. These are guard patterns only; a generic
		 * "id" value is migrated automatically only when the same value also contains
		 * an old image URL/token. Otherwise deletion remains blocked conservatively. */
		foreach ( array( '"id":' . $value, '"id":"' . $value . '"', '"attachment_id":' . $value, '"attachmentId":' . $value, '"image_id":' . $value, '"imageId":' . $value, '"media_id":' . $value, '"mediaId":' . $value, '"thumbnail_id":' . $value, '"thumbnailId":' . $value, '"featured_image_id":' . $value, '"featuredImageId":' . $value, '"custom_logo":' . $value, '"site_icon":' . $value ) as $json_token ) {
			$conditions[] = $column . ' LIKE %s';
			$params[]     = '%' . $wpdb->esc_like( $json_token ) . '%';
		}
		foreach ( array( '"id"', '"attachment_id"', '"attachmentId"', '"image_id"', '"imageId"', '"media_id"', '"mediaId"', '"thumbnail_id"', '"thumbnailId"', '"featured_image_id"', '"featuredImageId"', '"custom_logo"', '"site_icon"', '"image"', '"images"', '"gallery"', '"media"', '"thumbnail"', '"featured_image"', '"background_image"', '"logo"' ) as $json_key ) {
			$conditions[] = $column . ' LIKE %s';
			$params[]     = '%' . $wpdb->esc_like( $json_key ) . '%' . $wpdb->esc_like( $value ) . '%';
		}

		foreach ( is_array( $tokens ) ? $tokens : array() as $token ) {
			$token = (string) $token;
			if ( strlen( $token ) < 4 ) {
				continue;
			}
			$conditions[] = $column . ' LIKE %s';
			$params[]     = '%' . $wpdb->esc_like( $token ) . '%';
		}

		return '(' . implode( ' OR ', $conditions ) . ')';
	}

	/**
	 * Normalize one reference-structure key for conservative media matching.
	 *
	 * @param mixed $key Array/object key.
	 * @return string
	 */
	private function normalize_reference_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		$key = str_replace( array( '-', ' ', '.' ), '_', $key );
		return preg_replace( '/_+/', '_', $key );
	}


	/**
	 * Whether a numeric metadata/option key is known to represent business/runtime
	 * state rather than an attachment identity. This mirrors the desired-state
	 * cleanup classifier: equal integers must not be rewritten merely because an
	 * attachment happens to have the same auto-increment ID.
	 *
	 * Unknown scalar keys intentionally remain fail-closed during the final audit;
	 * they are not auto-migrated because custom fields such as an ACF image field
	 * may legitimately store a bare attachment ID.
	 *
	 * @param mixed $key Metadata/option/structure key.
	 * @return bool
	 */
	private function refresh_reference_key_is_nonmedia_numeric( $key ) {
		$key = $this->normalize_reference_key( $key );
		if ( '' === $key ) {
			return false;
		}

		if ( in_array( $key, array( 'page_on_front', 'page_for_posts', 'default_category' ), true ) ) {
			return true;
		}

		$media_token = '(?:attachments?|images?|media|thumbnails?|galler(?:y|ies)|photos?|pictures?|icons?|logos?|avatars?|posters?|covers?|backgrounds?|banners?)';
		$has_media   = 1 === preg_match( '/(?:^|_)' . $media_token . '(?:_|$)/', $key );

		if ( $has_media && preg_match(
			'/(?:^|_)' . $media_token . '(?:_[a-z0-9]+)*_(?:metadata|meta_data|dimension|dimensions|width|height|filesize|file_size|bytes|size|price|cost|amount|stock|quantity|qty|count|counter|total|revision|version|generation|cursor|offset|limit|seconds|interval|duration|timestamp|time|date|weight|length|rating|rate|percent|percentage|tax|discount|attempt|retry|scan|audit|status|result|enabled|approved|started|finished|pending|recovery|cleanup|quarantined|order|position|index|priority|alt|caption|title|description|context|expiry|expires|per_run|max_try|min_free|blocking|force|generate|delete)(?:_|$)/',
			$key
		) ) {
			return true;
		}

		if ( ! $has_media && preg_match(
			'/(?:^|_)(?:metadata|meta_data|dimension|dimensions|width|height|filesize|file_size|bytes|size|price|regular_price|sale_price|cost|amount|stock|quantity|qty|count|counter|total|revision|version|generation|cursor|offset|limit|seconds|interval|duration|timestamp|time|date|weight|length|rating|rate|percent|percentage|tax|discount|attempt|retry|scan|audit|status|result|enabled|approved|started|finished|pending|recovery|cleanup|quarantined|order|position|index|priority|alt|caption|title|description|context|expiry|expires)(?:_|$)/',
			$key
		) ) {
			return true;
		}

		if ( ! $has_media && preg_match(
			'/(?:^|_)(?:portal|product|variation|order|user|customer|term|category|tag|page|post|parent|author|attribute|shipping_class|tax_class)(?:_[a-z0-9]+)*_ids?(?:_|$)/',
			$key
		) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a key explicitly represents an attachment/media ID.
	 * Generic "id" is intentionally excluded and requires local image evidence.
	 *
	 * @param mixed $key Array/object key.
	 * @return bool
	 */
	private function is_explicit_media_id_key( $key ) {
		$key = $this->normalize_reference_key( $key );
		return in_array(
			$key,
			array(
				'attachment_id',
				'attachmentid',
				'image_id',
				'imageid',
				'media_id',
				'mediaid',
				'thumbnail_id',
				'thumbnailid',
				'_thumbnail_id',
				'featured_image_id',
				'featuredimageid',
				'custom_logo',
				'site_icon',
			),
			true
		);
	}

	/**
	 * Whether a key denotes a container that is expected to hold media data.
	 * This lets JSON/serialized lists such as gallery/image structures migrate
	 * their numeric attachment IDs without treating every generic ID as media.
	 *
	 * @param mixed $key Array/object key.
	 * @return bool
	 */
	private function is_media_container_key( $key ) {
		$key = $this->normalize_reference_key( $key );
		if ( '' === $key ) {
			return false;
		}

		if ( in_array( $key, array( 'image', 'images', 'gallery', 'media', 'thumbnail', 'featured_image', 'background_image', 'background_overlay_image', 'logo' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '/(?:^|_)(?:image|images|gallery|media|thumbnail|logo)$/', $key );
	}

	/**
	 * Check a decoded JSON/serialized node for a verified old-image text token.
	 * Object inspection is limited to public properties; inaccessible properties
	 * remain untouched and the final database audit will keep deletion blocked.
	 *
	 * @param mixed $value Decoded value.
	 * @param array $text_map Old => new text replacements.
	 * @param int   $depth Recursion depth.
	 * @return bool
	 */
	private function reference_value_has_old_image_evidence( $value, $text_map, $depth = 0 ) {
		if ( $depth > 32 ) {
			return false;
		}

		$contains_token = static function ( $candidate ) use ( $text_map ) {
			if ( ! is_string( $candidate ) ) {
				return false;
			}
			foreach ( array_keys( is_array( $text_map ) ? $text_map : array() ) as $old_token ) {
				$old_token = (string) $old_token;
				if ( strlen( $old_token ) >= 4 && false !== strpos( $candidate, $old_token ) ) {
					return true;
				}
			}
			return false;
		};

		if ( is_string( $value ) ) {
			return $contains_token( $value );
		}

		/* Evidence is intentionally local to direct scalar properties. Deep
		 * descendants are evaluated when recursion reaches their own node, which
		 * prevents one old image URL from authorizing unrelated generic IDs in a
		 * large Elementor/page-builder document. */
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $contains_token( $item ) ) {
					return true;
				}
			}
			return false;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $item ) {
				if ( $contains_token( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Decode a JSON object/array string without changing scalar strings.
	 * Decoding to objects (rather than associative arrays) preserves the JSON
	 * distinction between {} and [] when it is encoded again.
	 *
	 * @param string $value Raw string.
	 * @param mixed  $decoded Decoded value by reference.
	 * @return bool
	 */
	private function decode_reference_json( $value, &$decoded ) {
		$value = trim( (string) $value );
		if ( strlen( $value ) < 2 || ! in_array( $value[0], array( '{', '[' ), true ) ) {
			return false;
		}

		$decoded = json_decode( $value, false, 512, JSON_BIGINT_AS_STRING );
		return JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) );
	}

	/**
	 * Replace an attachment reference inside plain text, JSON, PHP-serialized
	 * arrays/objects and nested combinations of those formats.
	 *
	 * JSON is decoded and encoded structurally. PHP serialized values are opened
	 * with WordPress maybe_unserialize()/is_serialized() and reserialized after
	 * recursive mutation, so string-length markers are never edited manually.
	 *
	 * Generic keys named "id" are migrated only when their local structure also
	 * contains a verified old image URL/token, or when the containing key is an
	 * explicit media container. Unknown/private object state remains unchanged and
	 * therefore continues to block deletion during the final reference audit.
	 *
	 * @param mixed  $value Value.
	 * @param int    $old_attachment_id Old attachment.
	 * @param int    $new_attachment_id New attachment.
	 * @param array  $text_map Text replacements.
	 * @param string $context_key Metadata/option/structure key.
	 * @param int    $changes Change counter by reference.
	 * @param int    $depth Recursion depth.
	 * @param bool   $parent_evidence Parent node contains verified old-image text.
	 * @param bool   $parent_media_context Parent node is explicitly media-shaped.
	 * @return mixed
	 */
	private function migrate_reference_value( $value, $old_attachment_id, $new_attachment_id, $text_map, $context_key, &$changes, $depth = 0, $parent_evidence = false, $parent_media_context = false ) {
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		if ( $depth > 32 ) {
			return $value;
		}

		$normalized_key = $this->normalize_reference_key( $context_key );
		$allow_exact_id = $this->is_explicit_media_id_key( $context_key )
			|| $this->is_media_container_key( $context_key )
			|| ( 'id' === $normalized_key && $parent_evidence )
			|| ( $parent_media_context && ( 'id' === $normalized_key || ctype_digit( (string) $context_key ) ) );

		if ( is_int( $value ) && $value === $old_attachment_id && $allow_exact_id ) {
			$changes++;
			return $new_attachment_id;
		}

		if ( is_string( $value ) ) {
			/* Nested serialized strings must be decoded and serialized again; direct
			 * text replacement would corrupt PHP serialization length markers. */
			if ( function_exists( 'is_serialized' ) && is_serialized( $value ) ) {
				$decoded_serialized = maybe_unserialize( $value );
				$nested_changes     = 0;
				$decoded_serialized = $this->migrate_reference_value( $decoded_serialized, $old_attachment_id, $new_attachment_id, $text_map, $context_key, $nested_changes, $depth + 1, $parent_evidence, $parent_media_context );
				if ( $nested_changes > 0 ) {
					$changes += $nested_changes;
					return serialize( $decoded_serialized );
				}
			}

			/* Elementor and many builders store JSON directly in metadata. Parse the
			 * complete structure first; regex is only a fallback for mixed HTML/text. */
			$decoded_json = null;
			if ( $this->decode_reference_json( $value, $decoded_json ) ) {
				$json_changes = 0;
				$decoded_json = $this->migrate_reference_value( $decoded_json, $old_attachment_id, $new_attachment_id, $text_map, $context_key, $json_changes, $depth + 1, false, $this->is_media_container_key( $context_key ) );
				if ( $json_changes > 0 ) {
					$encoded_json = wp_json_encode( $decoded_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					if ( false !== $encoded_json ) {
						$changes += $json_changes;
						return $encoded_json;
					}
				}
			}

			if ( $value === (string) $old_attachment_id && $allow_exact_id ) {
				$changes++;
				return (string) $new_attachment_id;
			}

			$has_old_text_token = $parent_evidence || $this->reference_value_has_old_image_evidence( $value, $text_map );

			/* Explicit media-ID keys in mixed HTML/JSON fragments are safe to migrate. */
			$explicit_pattern = '/((?:["\']?(?:attachment_id|attachmentId|image_id|imageId|media_id|mediaId|thumbnail_id|thumbnailId)["\']?\\s*[:=]\\s*["\']?))' . preg_quote( (string) $old_attachment_id, '/' ) . '(["\']?)/';
			$value = preg_replace( $explicit_pattern, '${1}' . $new_attachment_id . '${2}', $value, -1, $explicit_count );
			if ( $explicit_count > 0 ) {
				$changes += $explicit_count;
			}

			$html_pattern = '/((?:data-attachment-id|data-image-id|data-media-id)\\s*=\\s*["\'])' . preg_quote( (string) $old_attachment_id, '/' ) . '(["\'])/i';
			$value = preg_replace( $html_pattern, '${1}' . $new_attachment_id . '${2}', $value, -1, $html_count );
			if ( $html_count > 0 ) {
				$changes += $html_count;
			}

			/* Generic JSON/block id is changed only when the same fragment also proves
			 * the old image through URL/basename/wp-image evidence. */
			if ( $has_old_text_token ) {
				$generic_id_pattern = '/((["\']id["\']\\s*:\\s*["\']?))' . preg_quote( (string) $old_attachment_id, '/' ) . '(["\']?)/';
				$value = preg_replace( $generic_id_pattern, '${1}' . $new_attachment_id . '${3}', $value, -1, $generic_id_count );
				if ( $generic_id_count > 0 ) {
					$changes += $generic_id_count;
				}
			}

			$gallery_shortcode_count = 0;
			$value = preg_replace_callback(
				'/((?:ids|include)\\s*=\\s*["\'])([^"\']*)(["\'])/i',
				static function ( $matches ) use ( $old_attachment_id, $new_attachment_id, &$gallery_shortcode_count ) {
					$ids = array_map( 'trim', explode( ',', isset( $matches[2] ) ? (string) $matches[2] : '' ) );
					foreach ( $ids as &$id ) {
						if ( $id === (string) $old_attachment_id ) {
							$id = (string) $new_attachment_id;
							$gallery_shortcode_count++;
						}
					}
					unset( $id );
					return $matches[1] . implode( ',', $ids ) . $matches[3];
				},
				$value
			);
			if ( $gallery_shortcode_count > 0 ) {
				$changes += $gallery_shortcode_count;
			}

			if ( '_product_image_gallery' === $context_key && false !== strpos( ',' . $value . ',', ',' . $old_attachment_id . ',' ) ) {
				$ids = array_map( 'trim', explode( ',', $value ) );
				foreach ( $ids as &$id ) {
					if ( $id === (string) $old_attachment_id ) {
						$id = (string) $new_attachment_id;
						$changes++;
					}
				}
				unset( $id );
				$value = implode( ',', $ids );
			}

			if ( ! empty( $text_map ) ) {
				$replaced = strtr( $value, $text_map );
				if ( $replaced !== $value ) {
					$changes++;
					$value = $replaced;
				}
			}
			return $value;
		}

		if ( is_array( $value ) ) {
			$node_evidence      = $this->reference_value_has_old_image_evidence( $value, $text_map );
			$node_media_context = $parent_media_context || $this->is_media_container_key( $context_key );
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->migrate_reference_value( $item, $old_attachment_id, $new_attachment_id, $text_map, (string) $key, $changes, $depth + 1, $node_evidence, $node_media_context );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			$node_evidence      = $this->reference_value_has_old_image_evidence( $value, $text_map );
			$node_media_context = $parent_media_context || $this->is_media_container_key( $context_key );
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$child_changes = 0;
				$new_item = $this->migrate_reference_value( $item, $old_attachment_id, $new_attachment_id, $text_map, (string) $key, $child_changes, $depth + 1, $node_evidence, $node_media_context );
				if ( $child_changes <= 0 ) {
					continue;
				}

				/* Nested objects are mutated in place, so identity can remain equal even
				 * though public descendants changed. In that case only propagate the
				 * verified child change count to the parent. */
				if ( is_object( $item ) && $new_item === $item ) {
					$changes += $child_changes;
					continue;
				}

				try {
					$value->{$key} = $new_item;
					$changes += $child_changes;
				} catch ( Throwable $throwable ) {
					/* Leave inaccessible/typed object state unchanged. The final reference
					 * audit will detect it and prevent deleting the old attachment. */
				}
			}
		}

		return $value;
	}

	/**
	 * Migrate reference rows in one WordPress metadata table.
	 *
	 * @param string $meta_type Metadata type: post, term or user.
	 * @param string $table Internal table name.
	 * @param string $meta_id_column Meta-ID column.
	 * @param string $object_id_column Object-ID column.
	 * @param string $meta_key_column Meta-key column.
	 * @param int    $old_attachment_id Old attachment.
	 * @param int    $new_attachment_id New attachment.
	 * @param array  $text_map Text replacements.
	 * @param bool   $exclude_old_attachment Whether old attachment's own postmeta must be skipped.
	 * @return array
	 */
	private function migrate_metadata_reference_rows( $meta_type, $table, $meta_id_column, $object_id_column, $meta_key_column, $old_attachment_id, $new_attachment_id, $text_map, $exclude_old_attachment = false ) {
		global $wpdb;
		$result = array( 'updatedRows' => 0, 'updatedValues' => 0, 'errors' => 0 );
		$params = array();
		$tokens = array_keys( is_array( $text_map ) ? $text_map : array() );
		$where  = $this->build_attachment_reference_sql( 'meta_value', $old_attachment_id, $tokens, $params );

		$extra = '';
		if ( 'post' === $meta_type ) {
			$extra = " AND {$meta_key_column} NOT LIKE %s AND {$meta_key_column} NOT IN ('mobo_refreshed_from_attachment_id','mobo_replaces_attachment_id')";
			$params[] = $wpdb->esc_like( 'mobo_image_refresh_' ) . '%';
			if ( $exclude_old_attachment ) {
				$extra .= " AND {$object_id_column} <> %d";
				$params[] = absint( $old_attachment_id );
			}
			$extra .= " OR ({$meta_key_column} = '_product_image_gallery' AND CONCAT(',', meta_value, ',') LIKE %s)";
			$params[] = '%,' . $wpdb->esc_like( (string) absint( $old_attachment_id ) ) . ',%';
		}

		$sql = "SELECT {$meta_id_column} AS meta_id, {$object_id_column} AS object_id, {$meta_key_column} AS meta_key, meta_value FROM {$table} WHERE (" . $where . $extra . ')';
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		$rows = $wpdb->get_results( $prepared ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced immediately above by wpdb::prepare() with internally generated SQL fragments.
		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$raw     = isset( $row->meta_value ) ? (string) $row->meta_value : '';
			$value   = maybe_unserialize( $raw );
			$changes = 0;
			$key     = isset( $row->meta_key ) ? (string) $row->meta_key : '';
			$value   = $this->migrate_reference_value( $value, $old_attachment_id, $new_attachment_id, $text_map, $key, $changes );
			if ( $changes <= 0 ) {
				continue;
			}

			/* Metadata APIs unslash scalar strings before storage. Re-slash JSON/plain
			 * strings here so decoded/re-encoded builder data is persisted byte-safe. */
			$update_value = is_string( $value ) ? wp_slash( $value ) : $value;
			$updated = update_metadata_by_mid( $meta_type, absint( $row->meta_id ), $update_value );
			if ( false === $updated ) {
				$result['errors']++;
				continue;
			}
			$result['updatedRows']++;
			$result['updatedValues'] += $changes;

			if ( 'post' === $meta_type ) {
				$object_id = absint( isset( $row->object_id ) ? $row->object_id : 0 );
				if ( $object_id > 0 ) {
					clean_post_cache( $object_id );
					if ( function_exists( 'wc_delete_product_transients' ) && in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
						wc_delete_product_transients( $object_id );
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Move safe references from the old attachment to its verified WebP replacement.
	 *
	 * @param int $old_attachment_id Old attachment.
	 * @param int $new_attachment_id New attachment.
	 * @return array
	 */
	private function migrate_attachment_references( $old_attachment_id, $new_attachment_id ) {
		global $wpdb;
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		$result = array(
			'attempted'     => true,
			'updatedRows'   => 0,
			'updatedValues' => 0,
			'contentRows'   => 0,
			'postmetaRows'  => 0,
			'termmetaRows'  => 0,
			'usermetaRows'  => 0,
			'optionRows'    => 0,
			'errors'        => 0,
		);

		if ( $old_attachment_id <= 0 || $new_attachment_id <= 0 || 'attachment' !== get_post_type( $old_attachment_id ) || 'attachment' !== get_post_type( $new_attachment_id ) || ! $this->is_webp_attachment( $new_attachment_id ) ) {
			$result['errors']++;
			return $result;
		}

		if ( absint( get_post_meta( $old_attachment_id, 'mobo_image_refresh_replaced_by_attachment_id', true ) ) !== $new_attachment_id ) {
			$result['errors']++;
			return $result;
		}

		$text_map = $this->build_attachment_text_replacement_map( $old_attachment_id, $new_attachment_id );
		$params   = array();
		$where    = $this->build_attachment_reference_sql( 'post_content', $old_attachment_id, array_keys( $text_map ), $params );
		$sql      = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND {$where}";
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		$posts    = $wpdb->get_results( $prepared ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced immediately above by wpdb::prepare() with internally generated SQL fragments.
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			$old_content = isset( $post->post_content ) ? (string) $post->post_content : '';
			$changes     = 0;
			$new_content = $this->migrate_reference_value( $old_content, $old_attachment_id, $new_attachment_id, $text_map, 'post_content', $changes );
			if ( $changes <= 0 || $new_content === $old_content ) {
				continue;
			}
			$updated = $wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => absint( $post->ID ) ), array( '%s' ), array( '%d' ) );
			if ( false === $updated ) {
				$result['errors']++;
				continue;
			}
			$result['contentRows']++;
			$result['updatedRows']++;
			$result['updatedValues'] += $changes;
			clean_post_cache( absint( $post->ID ) );
		}

		$meta_sets = array(
			array( 'post', $wpdb->postmeta, 'meta_id', 'post_id', 'meta_key', true, 'postmetaRows' ),
			array( 'term', $wpdb->termmeta, 'meta_id', 'term_id', 'meta_key', false, 'termmetaRows' ),
			array( 'user', $wpdb->usermeta, 'umeta_id', 'user_id', 'meta_key', false, 'usermetaRows' ),
		);
		foreach ( $meta_sets as $set ) {
			$part = $this->migrate_metadata_reference_rows( $set[0], $set[1], $set[2], $set[3], $set[4], $old_attachment_id, $new_attachment_id, $text_map, $set[5] );
			$result[ $set[6] ] += absint( isset( $part['updatedRows'] ) ? $part['updatedRows'] : 0 );
			$result['updatedRows'] += absint( isset( $part['updatedRows'] ) ? $part['updatedRows'] : 0 );
			$result['updatedValues'] += absint( isset( $part['updatedValues'] ) ? $part['updatedValues'] : 0 );
			$result['errors'] += absint( isset( $part['errors'] ) ? $part['errors'] : 0 );
		}

		$params = array();
		$tokens = array_keys( $text_map );
		$where  = $this->build_attachment_reference_sql( 'option_value', $old_attachment_id, $tokens, $params );
		$params = array_merge( array( $wpdb->esc_like( 'theme_mods_' ) . '%', $wpdb->esc_like( 'widget_' ) . '%' ), $params );
		$sql = "SELECT option_name, option_value FROM {$wpdb->options} WHERE (option_name = 'site_icon' OR option_name LIKE %s OR option_name LIKE %s) AND {$where}";
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		$options = $wpdb->get_results( $prepared ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced immediately above by wpdb::prepare() with internally generated SQL fragments.
		foreach ( is_array( $options ) ? $options : array() as $option ) {
			$name    = isset( $option->option_name ) ? (string) $option->option_name : '';
			$value   = maybe_unserialize( isset( $option->option_value ) ? (string) $option->option_value : '' );
			$changes = 0;
			$value   = $this->migrate_reference_value( $value, $old_attachment_id, $new_attachment_id, $text_map, $name, $changes );
			if ( $changes <= 0 ) {
				continue;
			}
			if ( ! update_option( $name, $value ) ) {
				/* update_option(false) can also mean identical value; verify before calling it an error. */
				if ( get_option( $name ) !== $value ) {
					$result['errors']++;
					continue;
				}
			}
			$result['optionRows']++;
			$result['updatedRows']++;
			$result['updatedValues'] += $changes;
		}

		return $result;
	}


	/**
	 * Verify whether one candidate metadata/option row still contains a real
	 * attachment reference after structural migration. The SQL prefilter is broad
	 * by design; this second pass prevents unrelated generic JSON IDs from blocking
	 * deletion forever.
	 *
	 * @param string $raw Raw stored value.
	 * @param string $context_key Metadata/option key.
	 * @param int    $old_attachment_id Old attachment.
	 * @param int    $new_attachment_id Replacement attachment.
	 * @param array  $text_map Old => new text map.
	 * @return bool
	 */
	private function candidate_value_has_attachment_reference( $raw, $context_key, $old_attachment_id, $new_attachment_id, $text_map ) {
		$raw               = (string) $raw;
		$old_attachment_id = absint( $old_attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		if ( '' === $raw || $old_attachment_id <= 0 || $new_attachment_id <= 0 ) {
			return false;
		}

		$value   = maybe_unserialize( $raw );
		$changes = 0;
		$this->migrate_reference_value( $value, $old_attachment_id, $new_attachment_id, $text_map, (string) $context_key, $changes );
		if ( $changes > 0 ) {
			return true;
		}

		/* A bare top-level integer in known business/runtime metadata is not media.
		 * For an unknown custom-field key, however, keep deletion fail-closed without
		 * rewriting the value: ACF and other integrations may store attachment IDs in
		 * arbitrarily named scalar fields. Plain post_content containing only a number
		 * is not treated as media evidence. */
		if ( trim( $raw ) === (string) $old_attachment_id ) {
			if ( $this->refresh_reference_key_is_nonmedia_numeric( $context_key ) || 'post_content' === $this->normalize_reference_key( $context_key ) ) {
				return false;
			}
			return true;
		}

		if ( $this->refresh_reference_key_is_nonmedia_numeric( $context_key ) ) {
			return false;
		}

		/* Serialized objects can contain private/inaccessible state that WordPress can
		 * deserialize but this migrator intentionally cannot mutate safely. Preserve
		 * those attachments when the raw serialized payload still proves the old ID
		 * or an old image token is present. */
		if ( function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {
			$id = (string) $old_attachment_id;
			if ( false !== strpos( $raw, 'i:' . $id . ';' ) || false !== strpos( $raw, 's:' . strlen( $id ) . ':"' . $id . '";' ) ) {
				return true;
			}
			foreach ( array_keys( is_array( $text_map ) ? $text_map : array() ) as $token ) {
				$token = (string) $token;
				if ( strlen( $token ) >= 4 && false !== strpos( $raw, $token ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return exact remaining-reference locations outside products. Candidate SQL
	 * matches are structurally verified before they are treated as blockers.
	 *
	 * @param int $attachment_id Old attachment ID.
	 * @param int $new_attachment_id Replacement attachment ID.
	 * @return array
	 */
	private function get_external_reference_diagnostics( $attachment_id, $new_attachment_id ) {
		global $wpdb;
		$attachment_id     = absint( $attachment_id );
		$new_attachment_id = absint( $new_attachment_id );
		$result            = array( 'hasReferences' => false, 'locations' => array() );
		if ( $attachment_id <= 0 || $new_attachment_id <= 0 ) {
			$result['hasReferences'] = true;
			$result['locations'][]    = 'invalid-reference-audit';
			return $result;
		}

		$tokens   = $this->get_attachment_reference_tokens( $attachment_id );
		$text_map = $this->build_attachment_text_replacement_map( $attachment_id, $new_attachment_id );
		$add      = static function ( &$locations, $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value && ! in_array( $value, $locations, true ) && count( $locations ) < 12 ) {
				$locations[] = $value;
			}
		};

		$params   = array();
		$where    = $this->build_attachment_reference_sql( 'post_content', $attachment_id, $tokens, $params );
		$sql      = "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND {$where}";
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		$rows     = $wpdb->get_results( $prepared ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced immediately above by wpdb::prepare() with internally generated SQL fragments.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $this->candidate_value_has_attachment_reference( isset( $row->post_content ) ? (string) $row->post_content : '', 'post_content', $attachment_id, $new_attachment_id, $text_map ) ) {
				$result['hasReferences'] = true;
				$add( $result['locations'], 'post_content#' . absint( isset( $row->ID ) ? $row->ID : 0 ) . ':' . sanitize_key( isset( $row->post_type ) ? (string) $row->post_type : 'post' ) );
			}
		}

		$meta_sets = array(
			array( 'postmeta', $wpdb->postmeta, 'post_id', 'meta_key', "post_id <> %d AND meta_key NOT LIKE %s AND meta_key NOT IN ('mobo_refreshed_from_attachment_id','mobo_replaces_attachment_id')", array( $attachment_id, $wpdb->esc_like( 'mobo_image_refresh_' ) . '%' ) ),
			array( 'termmeta', $wpdb->termmeta, 'term_id', 'meta_key', '1=1', array() ),
			array( 'usermeta', $wpdb->usermeta, 'user_id', 'meta_key', '1=1', array() ),
		);
		foreach ( $meta_sets as $set ) {
			$params = array();
			$where  = $this->build_attachment_reference_sql( 'meta_value', $attachment_id, $tokens, $params );
			$prefix_params = isset( $set[5] ) && is_array( $set[5] ) ? $set[5] : array();
			$params = array_merge( $prefix_params, $params );
			$sql = "SELECT {$set[2]} AS object_id, {$set[3]} AS meta_key, meta_value FROM {$set[1]} WHERE {$set[4]} AND {$where}";
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
			$rows = $wpdb->get_results( $prepared ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced immediately above by wpdb::prepare() from strict internal metadata table descriptors.
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$key = isset( $row->meta_key ) ? (string) $row->meta_key : '';
				$raw = isset( $row->meta_value ) ? (string) $row->meta_value : '';
				if ( ! $this->candidate_value_has_attachment_reference( $raw, $key, $attachment_id, $new_attachment_id, $text_map ) ) {
					continue;
				}
				$result['hasReferences'] = true;
				$add( $result['locations'], $set[0] . '#' . absint( isset( $row->object_id ) ? $row->object_id : 0 ) . ':' . $key );
			}
		}

		$params = array();
		$where  = $this->build_attachment_reference_sql( 'option_value', $attachment_id, $tokens, $params );
		$params = array_merge( array( $wpdb->esc_like( 'theme_mods_' ) . '%', $wpdb->esc_like( 'widget_' ) . '%' ), $params );
		$sql = "SELECT option_name, option_value FROM {$wpdb->options} WHERE (option_name = 'site_icon' OR option_name LIKE %s OR option_name LIKE %s) AND {$where}";
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
		$rows = $wpdb->get_results( $prepared ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced immediately above by wpdb::prepare() with internally generated SQL fragments.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = isset( $row->option_name ) ? (string) $row->option_name : '';
			$raw  = isset( $row->option_value ) ? (string) $row->option_value : '';
			if ( ! $this->candidate_value_has_attachment_reference( $raw, $name, $attachment_id, $new_attachment_id, $text_map ) ) {
				continue;
			}
			$result['hasReferences'] = true;
			$add( $result['locations'], 'option:' . $name );
		}

		return $result;
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
