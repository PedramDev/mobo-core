<?php
/**
 * Image sync service.
 *
 * Chunk-safe WooCommerce image sync with optional table-backed queue.
 *
 * Preserves:
 * - image_guid
 * - img_guid
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Image_Sync {

	/**
	 * Last image resolution error for queue diagnostics.
	 *
	 * @var string
	 */
	private $last_image_error = '';

	/**
	 * Request-local attachment resolution cache.
	 *
	 * Image resolution can ask for the same GUID/source several times while a row
	 * moves from queue lookup to reuse/import validation. Cache both hits and misses
	 * for the lifetime of the PHP request so repeated WP_Query/meta lookups disappear.
	 *
	 * @var array<string,int>
	 */
	private $attachment_lookup_cache = array();

	/**
	 * Request-local attachment meta-query cache.
	 *
	 * @var array<string,array{limit:int,ids:array}>
	 */
	private $attachment_meta_lookup_cache = array();

	/**
	 * Request-local positive readiness cache. A verified attachment does not need
	 * its full subsize/manifest health checked again while rebuilding image order.
	 *
	 * @var array<string,bool>
	 */
	private $attachment_readiness_cache = array();


	/**
	 * Process images for a product.
	 *
	 * When the image queue is enabled, image rows are first upserted into the
	 * queue table and then a bounded number of due rows is processed. This makes
	 * image sync resumable and avoids re-downloading attachments that already
	 * exist by image GUID or source URL.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
	 * @param int       $offset Legacy offset kept for backward compatibility.
	 * @param bool|null $blocking_override Optional blocking override for queue mode.
	 * @return array
	 */
	public function process_images( $product_id, $images, $offset, $blocking_override = null ) {
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			return Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( $product_id, $images, $offset, $blocking_override ) {
					return $this->process_images_guarded( $product_id, $images, $offset, $blocking_override );
				},
				'image-sync'
			);
		}

		return $this->process_images_guarded( $product_id, $images, $offset, $blocking_override );
	}

	/**
	 * Process product images inside the cache mutation scope.
	 *
	 * @param int        $product_id Product ID.
	 * @param array      $images Images.
	 * @param int        $offset Offset.
	 * @param bool|null  $blocking_override Blocking override.
	 * @return array
	 */
	private function process_images_guarded( $product_id, $images, $offset, $blocking_override = null ) {
		$product_id = absint( $product_id );
		$offset     = max( 0, absint( $offset ) );
		$limit      = Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 1, 0, 10 );

		if ( $product_id <= 0 || ! is_array( $images ) || empty( $images ) || $limit <= 0 ) {
			return array(
				'done'       => true,
				'nextOffset' => 0,
				'processed'  => 0,
				'skipped'    => 0,
			);
		}

		if ( $this->should_use_queue() ) {
			return $this->process_images_with_queue( $product_id, $images, $limit, $blocking_override );
		}

		return $this->process_images_direct( $product_id, $images, $offset, $limit );
	}

	/**
	 * Process due image queue rows across products.
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
				'image-queue'
			);
		}

		return $this->process_queue_guarded( $limit );
	}

	/**
	 * Process image queue inside the cache mutation scope.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function process_queue_guarded( $limit = 0 ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'image-queue' ), array( 'processed' => 0, 'failed' => 0, 'remaining' => true ) );
		}

		$worker_lock = Mobo_Core_Lock::acquire( 'image_queue_worker', 300 );
		if ( false === $worker_lock ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'image-queue' ), array( 'processed' => 0, 'failed' => 0, 'remaining' => true ) );
			}

			return array(
				'success'   => true,
				'status'    => 'locked',
				'processed' => 0,
				'failed'    => 0,
				'remaining' => true,
			);
		}

		try {
			if ( ! $this->should_use_queue() ) {
				return array(
					'success'   => true,
					'status'    => 'disabled',
					'processed' => 0,
					'failed'    => 0,
					'remaining' => false,
				);
			}

			$limit = $limit > 0 ? absint( $limit ) : Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 1, 0, 10 );

			if ( $limit <= 0 ) {
				$queue = new Mobo_Core_Image_Queue();
				return array(
					'success'   => true,
					'status'    => 'disabled-by-limit',
					'processed' => 0,
					'failed'    => 0,
					'remaining' => method_exists( $queue, 'has_due' ) ? $queue->has_due() : $queue->count_due() > 0,
				);
			}

			$queue = new Mobo_Core_Image_Queue();
			$rows  = $queue->get_due_images( $limit );

			return $this->process_queue_rows( $queue, $rows, $limit );
		} finally {
			Mobo_Core_Lock::release( 'image_queue_worker', $worker_lock );
		}
	}

	/**
	 * Return compact queue status.
	 *
	 * @return array
	 */
	public function get_queue_status() {
		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return array(
				'enabled' => false,
				'pending' => 0,
				'due'     => 0,
				'failed'  => 0,
			);
		}

		$queue = new Mobo_Core_Image_Queue();

		return $queue->get_status();
	}

	/**
	 * Table-backed image processing for one product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function process_images_with_queue( $product_id, $images, $limit, $blocking_override = null ) {
		/*
		 * Keep this variable local and explicit. Older cached copies of this file
		 * could reference the override without a default; this guard also keeps the
		 * method safe if custom integrations call it through reflection/tests.
		 */
		$queue_blocking_override = is_bool( $blocking_override ) ? $blocking_override : null;

		$queue        = new Mobo_Core_Image_Queue();
		$product_guid = $this->get_product_guid( $product_id );

		$enqueue  = $queue->enqueue_product_images( $product_id, $product_guid, $images );
		$enqueue_error = isset( $enqueue['error'] ) ? sanitize_text_field( (string) $enqueue['error'] ) : '';
		if ( '' !== $enqueue_error ) {
			return array(
				'done'        => false,
				'nextOffset'  => 0,
				'processed'   => 0,
				'failed'      => 1,
				'skipped'     => isset( $enqueue['skipped'] ) ? absint( $enqueue['skipped'] ) : 0,
				'queued'      => isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0,
				'removed'     => isset( $enqueue['removed'] ) ? absint( $enqueue['removed'] ) : 0,
				'pending'     => 0,
				'due'         => 0,
				'blocking'    => false,
				'queuedAsync' => false,
				'error'       => $enqueue_error,
			);
		}
		$blocking = is_bool( $queue_blocking_override ) ? $queue_blocking_override : Mobo_Core_Settings::enabled( 'mobo_core_image_queue_blocking', '0' );

		/*
		 * A non-blocking Product Sync must truly be non-blocking: enqueue desired
		 * images and leave network/media import to the real runner. Older versions
		 * still downloaded a bounded set here even when the caller explicitly passed
		 * false, which made product throughput depend on remote image latency.
		 */
		if ( false === $blocking ) {
			$result = array(
				'processed' => 0,
				'failed'    => 0,
				'status'    => 'queued-async',
			);
		} else {
			$rows   = $queue->get_due_product_images( $product_id, $limit );
			$result = $this->process_queue_rows( $queue, $rows, $limit );
		}

		/* Attach any rows that had already completed in an earlier runner pass. */
		$this->sync_woocommerce_product_image_objects_from_queue( $product_id, $queue );

		if ( method_exists( $queue, 'get_product_summary' ) ) {
			$product_queue_summary = $queue->get_product_summary( $product_id );
			$pending        = absint( isset( $product_queue_summary['pending'] ) ? $product_queue_summary['pending'] : 0 );
			$due_by_product = absint( isset( $product_queue_summary['due'] ) ? $product_queue_summary['due'] : 0 );
		} else {
			$pending        = $queue->count_pending_by_product( $product_id, false );
			$due_by_product = method_exists( $queue, 'count_due_by_product' ) ? $queue->count_due_by_product( $product_id ) : $pending;
		}
		$processed      = isset( $result['processed'] ) ? absint( $result['processed'] ) : 0;
		$failed         = isset( $result['failed'] ) ? absint( $result['failed'] ) : 0;

		/*
		 * Never keep product sync stuck on image rows that are waiting for a
		 * future retry or an expired lock. The image queue is independent and cron
		 * will continue processing it. Blocking is only useful while there are
		 * immediately due rows for the same product.
		 */
		$done = true;

		if ( $blocking && $pending > 0 && $due_by_product > 0 ) {
			$done = false;
		}

		return array(
			'done'       => $done,
			'nextOffset' => $done ? 0 : 1,
			'processed'  => $processed,
			'failed'     => $failed,
			'skipped'    => isset( $enqueue['skipped'] ) ? absint( $enqueue['skipped'] ) : 0,
			'queued'     => isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0,
			'removed'    => isset( $enqueue['removed'] ) ? absint( $enqueue['removed'] ) : 0,
			'pending'    => $pending,
			'due'        => $due_by_product,
			'blocking'   => $blocking,
			'queuedAsync'=> ! $blocking,
			'error'      => '',
		);
	}

	/**
	 * Legacy direct chunk image processing fallback.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
	 * @param int   $offset Offset.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function process_images_direct( $product_id, $images, $offset, $limit ) {
		$this->load_media_dependencies();

		$total     = count( $images );
		$processed = 0;
		$skipped   = 0;
		$index     = $offset;

		while ( $index < $total && $processed < $limit ) {
			$image = isset( $images[ $index ] ) && is_array( $images[ $index ] ) ? $images[ $index ] : array();

			$image_guid = $this->get_image_guid( $image );
			$url        = $this->get_image_url( $image );

			if ( '' === $image_guid || '' === $url ) {
				$skipped++;
				$processed++;
				$index++;
				continue;
			}

			$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid );

			if ( $attachment_id > 0 && $this->ensure_attachment_ready_for_linkage( $attachment_id, $url ) ) {
				$this->mark_attachment_synced( $attachment_id, $image_guid, $url );
			} else {
				if ( $attachment_id > 0 ) {
					update_post_meta( $attachment_id, 'mobo_sync_incomplete', '1' );
				}
				$skipped++;
			}

			$processed++;
			$index++;
		}

		/*
		 * Rebuild the WooCommerce image order from the full Mobo payload, not from
		 * the current chunk. This prevents later chunks from making the second/third
		 * image the product's featured image. It also fixes products created by the
		 * old plugin where the featured image could be the last image.
		 */
		$this->sync_woocommerce_product_image_objects_from_payload_order( $product_id, $images );

		return array(
			'done'       => $index >= $total,
			'nextOffset' => $index >= $total ? 0 : $index,
			'processed'  => $processed,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Process queue rows.
	 *
	 * @param Mobo_Core_Image_Queue $queue Queue.
	 * @param array                 $rows Rows.
	 * @param int                   $limit Limit.
	 * @return array
	 */
	private function process_queue_rows( Mobo_Core_Image_Queue $queue, $rows, $limit ) {
		$limit     = max( 1, min( 50, absint( $limit ) ) );
		$processed = 0;
		$failed    = 0;
		$deferred  = 0;
		$touched   = array();
		$link_rows = array();
		$paused_for_upgrade = false;

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array(
				'success'   => true,
				'status'    => 'empty',
				'processed' => 0,
				'failed'    => 0,
				'deferred'  => 0,
				'remaining' => method_exists( $queue, 'has_due' ) ? $queue->has_due() : $queue->count_due() > 0,
			);
		}

		$this->load_media_dependencies();

		/*
		 * Prime posts/meta once for the full claimed batch. get_post_type(),
		 * get_post_mime_type(), get_attached_file() and WC hydration can otherwise
		 * fan out into repeated point queries while processing several images.
		 */
		$prime_ids = array();
		foreach ( $rows as $prime_row ) {
			if ( ! is_array( $prime_row ) ) {
				continue;
			}
			$product_id = isset( $prime_row['product_id'] ) ? absint( $prime_row['product_id'] ) : 0;
			$attachment_id = isset( $prime_row['attachment_id'] ) ? absint( $prime_row['attachment_id'] ) : 0;
			if ( $product_id > 0 ) {
				$prime_ids[] = $product_id;
			}
			if ( $attachment_id > 0 ) {
				$prime_ids[] = $attachment_id;
			}
		}
		$prime_ids = array_values( array_unique( array_filter( array_map( 'absint', $prime_ids ) ) ) );
		if ( ! empty( $prime_ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $prime_ids, false, true );
		}

		$claimed_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		$bulk_claim  = ! empty( $claimed_ids ) && ! empty( $rows[0]['_mobo_bulk_claimed'] ) && method_exists( $queue, 'release_claimed_images' );

		try {
			foreach ( $rows as $row ) {
				if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
					$paused_for_upgrade = true;
					break;
				}

				if ( $processed >= $limit ) {
					break;
				}

				$id            = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				$product_id    = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
				$image_guid    = isset( $row['image_guid'] ) ? sanitize_text_field( (string) $row['image_guid'] ) : '';
				$url           = isset( $row['source_url'] ) ? esc_url_raw( (string) $row['source_url'] ) : '';
				$attachment_id = isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0;
				$try_count     = isset( $row['try_count'] ) ? absint( $row['try_count'] ) + 1 : 1;

				if ( $id <= 0 ) {
					$failed++;
					continue;
				}

				if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
					$queue->mark_failure( $id, 'Product does not exist.', $try_count, true );
					$failed++;
					continue;
				}

				if ( '' === $image_guid || ! $this->is_valid_image_source_url( $url ) ) {
					$queue->mark_failure( $id, 'Image GUID or HTTP(S) source URL is invalid.', $try_count, true );
					$failed++;
					continue;
				}

				if ( empty( $row['_mobo_bulk_claimed'] ) && ! $queue->lock( $id, 300 ) ) {
					continue;
				}

				/*
				 * An attachment may already exist when a previous PHP request stopped
				 * between import and WooCommerce product linkage. Reuse it and finish
				 * the state transition without downloading again. In Shared Media mode
				 * only a real shared attachment may bypass import/conversion.
				 */
				$shared_mode = class_exists( 'Mobo_Core_Shared_Media' )
				&& ( method_exists( 'Mobo_Core_Shared_Media', 'is_configured' ) ? Mobo_Core_Shared_Media::is_configured() : Mobo_Core_Shared_Media::is_enabled() );
				$can_reuse_queued_attachment = $attachment_id > 0
					&& $this->attachment_guid_matches_or_unclaimed( $attachment_id, $image_guid )
					&& $this->is_attachment_reusable_for_source( $attachment_id, $url );

				if ( $can_reuse_queued_attachment && $shared_mode ) {
					if ( ! method_exists( 'Mobo_Core_Shared_Media', 'is_shared_attachment' )
						|| ! Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
						$can_reuse_queued_attachment = false;
					} elseif ( method_exists( 'Mobo_Core_Shared_Media', 'attachment_health' ) ) {
						$shared_health = Mobo_Core_Shared_Media::attachment_health( $attachment_id );
						$can_reuse_queued_attachment = ! empty( $shared_health['healthy'] );
					}
				}

				if ( ! $can_reuse_queued_attachment ) {
					$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid );
				}

				if ( $attachment_id > 0 && $this->ensure_attachment_ready_for_linkage( $attachment_id, $url ) ) {
					/*
					 * Commit only if this row still describes the source claimed by this
					 * worker. A webhook may supersede source_url while the download is in
					 * flight; a stale worker must never attach/complete the newer state.
					 */
					$attaching_committed = method_exists( $queue, 'mark_attaching_if_current' )
						? $queue->mark_attaching_if_current( $id, $attachment_id, $product_id, $image_guid, $url, 90 )
						: true;
					if ( ! $attaching_committed ) {
						if ( method_exists( $queue, 'release_if_superseded' ) ) {
							$queue->release_if_superseded( $id, $product_id, $image_guid, $url );
						}
						$deferred++;
						continue;
					}
					if ( ! method_exists( $queue, 'mark_attaching_if_current' ) ) {
						$queue->mark_attaching( $id, $attachment_id, 90 );
					}

					$this->mark_attachment_synced( $attachment_id, $image_guid, $url );
					$touched[ $product_id ] = true;
					if ( ! isset( $link_rows[ $product_id ] ) ) {
						$link_rows[ $product_id ] = array();
					}
					$link_rows[ $product_id ][] = $id;
					$processed++;
					continue;
				}

				if ( $attachment_id > 0 ) {
					update_post_meta( $attachment_id, 'mobo_sync_incomplete', '1' );
				}

				$message = $this->get_last_image_error();
				if ( '' === $message ) {
					$message = 'Image source is not ready or the download/import failed.';
				}

				/*
				 * Network errors, timeouts, HTTP 404/5xx responses and a not-yet-ready
				 * shared-media manifest are recoverable. They remain pending forever
				 * with a bounded long-term backoff instead of becoming terminal.
				 *
				 * A shared-media manifest that is merely not ready is expected
				 * backpressure from the single media writer, not an image-worker
				 * failure. Count it as deferred so Runtime Diagnostics/Circuit Breaker
				 * do not isolate the image stage while it is correctly waiting.
				 */
				$failure_committed = method_exists( $queue, 'mark_failure_if_current' )
					? $queue->mark_failure_if_current( $id, $message, $try_count, $product_id, $image_guid, $url, false )
					: true;
				if ( ! $failure_committed ) {
					/* The row was superseded while this worker was active. */
					if ( method_exists( $queue, 'release_if_superseded' ) ) {
						$queue->release_if_superseded( $id, $product_id, $image_guid, $url );
					}
					$deferred++;
					continue;
				}
				if ( ! method_exists( $queue, 'mark_failure_if_current' ) ) {
					$queue->mark_failure( $id, $message, $try_count, false );
				}
				if ( 0 === strpos( $message, 'Shared-media manifest is not ready or is incompatible.' ) ) {
					$deferred++;
				} else {
					$failed++;
				}
			}

			/* One WooCommerce image linkage/save per touched product, not per image. */
			foreach ( array_keys( $touched ) as $product_id ) {
				$product_id = absint( $product_id );
				$row_ids    = isset( $link_rows[ $product_id ] ) ? array_values( array_unique( array_filter( array_map( 'absint', $link_rows[ $product_id ] ) ) ) ) : array();
				if ( $product_id <= 0 || empty( $row_ids ) ) {
					continue;
				}

				try {
					$link_ok = $this->sync_woocommerce_product_image_objects_from_queue( $product_id, $queue );
				} catch ( \Throwable $e ) {
					$link_ok = false;
					$failed++;
					if ( class_exists( 'Mobo_Core_Logger' ) ) {
						Mobo_Core_Logger::error( 'Image product linkage failed for product ' . $product_id . ': ' . $e->getMessage() );
					}
				}

				if ( $link_ok ) {
					if ( method_exists( $queue, 'mark_done_many' ) ) {
						$queue->mark_done_many( $row_ids );
					} else {
						foreach ( $row_ids as $row_id ) {
							$attachment_id = absint( $this->attachment_id_for_queue_row( $rows, $row_id ) );
							if ( $attachment_id > 0 ) {
								$queue->mark_done( $row_id, $attachment_id );
							}
						}
					}
				}
			}
		} finally {
			if ( $bulk_claim ) {
				$queue->release_claimed_images( $claimed_ids );
			}
		}

		return array(
			'success'   => true,
			'status'    => $paused_for_upgrade ? 'paused-for-upgrade' : 'processed',
			'processed' => $processed,
			'failed'    => $failed,
			'deferred'  => $deferred,
			'remaining' => $paused_for_upgrade || ( method_exists( $queue, 'has_due' ) ? $queue->has_due() : $queue->count_due() > 0 ),
		);
	}

	/**
	 * Resolve an attachment ID from the original claimed row array for old queue
	 * implementations that do not expose bulk completion. New releases use
	 * mark_done_many(), so this is only a compatibility fallback.
	 *
	 * @param array $rows Queue rows.
	 * @param int   $row_id Row ID.
	 * @return int
	 */
	private function attachment_id_for_queue_row( $rows, $row_id ) {
		$row_id = absint( $row_id );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && absint( isset( $row['id'] ) ? $row['id'] : 0 ) === $row_id ) {
				return absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
			}
		}
		return 0;
	}

	/**
	 * Validate an HTTP(S) source URL without rejecting local development hosts.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_valid_image_source_url( $url ) {
		$parts  = wp_parse_url( (string) $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? trim( (string) $parts['host'] ) : '';

		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host;
	}

	/**
	 * Store a bounded diagnostic message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function set_last_image_error( $message ) {
		$this->last_image_error = sanitize_text_field( (string) $message );
	}

	/**
	 * Return the latest diagnostic message.
	 *
	 * @return string
	 */
	private function get_last_image_error() {
		return sanitize_text_field( (string) $this->last_image_error );
	}

	private function should_use_queue() {
		return class_exists( 'Mobo_Core_Image_Queue' )
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' )
			&& Mobo_Core_Image_Queue::table_exists();
	}

	private function load_media_dependencies() {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Resolve an attachment from the private shared repository or the normal
	 * per-site WordPress media library. Shared mode is strict by default: when
	 * the worker manifest is not ready, the queue retries later and no duplicate
	 * site-local image is downloaded.
	 *
	 * @param string $url Source URL.
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Remote image GUID.
	 * @param bool   $allow_shared_local_conversion Whether Shared Media may convert an existing local attachment in place.
	 * @return int Attachment ID or 0.
	 */
	private function resolve_image_attachment( $url, $product_id, $image_guid, $allow_shared_local_conversion = true ) {
		$this->last_image_error = '';
		$url                    = esc_url_raw( (string) $url );
		$product_id             = absint( $product_id );
		$image_guid             = sanitize_text_field( (string) $image_guid );

		if ( '' === $url || $product_id <= 0 || '' === $image_guid ) {
			$this->set_last_image_error( 'Image resolution arguments are invalid.' );
			return 0;
		}

		$shared_mode = class_exists( 'Mobo_Core_Shared_Media' )
			&& ( method_exists( 'Mobo_Core_Shared_Media', 'is_configured' ) ? Mobo_Core_Shared_Media::is_configured() : Mobo_Core_Shared_Media::is_enabled() );

		/*
		 * Fast path for ordinary per-site media. Shared Media may intentionally
		 * convert an older local attachment in place, so it must continue through
		 * the identity lock even when a reusable local attachment already exists.
		 */
		$existing_id = $this->find_existing_attachment( $image_guid, $url );
		if ( ! $shared_mode && $existing_id > 0 ) {
			return $existing_id;
		}

		/*
		 * The normal image queue and Image Refresh have independent worker locks.
		 * Without one lock per remote image identity they can both observe a miss
		 * and sideload the same source concurrently, causing WordPress to create
		 * collision files such as image-1.webp, image-2.webp, ... . Serialize only
		 * the import/conversion for this GUID and source URL; unrelated images remain parallel.
		 */
		$identity_locks = array();
		$lock_names     = array(
			'image_import_guid_' . substr( hash( 'sha256', strtolower( $image_guid ) ), 0, 32 ),
			'image_import_url_' . substr( hash( 'sha256', strtolower( $url ) ), 0, 32 ),
		);
		sort( $lock_names, SORT_STRING );

		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			foreach ( $lock_names as $lock_name ) {
				$lock_token = Mobo_Core_Lock::acquire( $lock_name, 300 );
				if ( false === $lock_token ) {
					foreach ( array_reverse( $identity_locks, true ) as $owned_name => $owned_token ) {
						Mobo_Core_Lock::release( $owned_name, $owned_token );
					}

					/* The owner may have completed between our first lookup and lock attempt. */
					$this->forget_attachment_lookup_caches( $image_guid, $url );
					$existing_id = $this->find_existing_attachment( $image_guid, $url );
					if ( $existing_id > 0 ) {
						if ( ! $shared_mode ) {
							return $existing_id;
						}

						/* In Shared Media mode another request may currently be converting this
						 * very attachment in place. Never bypass that critical section by linking
						 * its old local representation. A committed shared attachment is safe to
						 * reuse; otherwise defer and let the owner finish. */
						if ( Mobo_Core_Shared_Media::is_shared_attachment( $existing_id ) ) {
							$shared_health = method_exists( 'Mobo_Core_Shared_Media', 'attachment_health' )
								? Mobo_Core_Shared_Media::attachment_health( $existing_id )
								: array( 'healthy' => true );
							if ( ! empty( $shared_health['healthy'] ) ) {
								return $existing_id;
							}
						}
					}

					$this->set_last_image_error( 'Image import is already in progress for this image GUID or source URL.' );
					return 0;
				}

				$identity_locks[ $lock_name ] = $lock_token;
			}
		}

		try {
			/* Re-check inside the identity lock to close the lookup/import race. */
			$this->forget_attachment_lookup_caches( $image_guid, $url );
			$existing_id = $this->find_existing_attachment( $image_guid, $url );

			if ( $shared_mode ) {
				/*
				 * Normal sync may convert an older local attachment to shared mode in place.
				 * Legacy refresh explicitly disables that behavior because it must replace
				 * the old attachment with a distinct shared attachment before cleanup.
				 */
				$shared_existing_id = 0;
				if ( $existing_id > 0 && ( $allow_shared_local_conversion || Mobo_Core_Shared_Media::is_shared_attachment( $existing_id ) ) ) {
					$shared_existing_id = $existing_id;
				} elseif ( $allow_shared_local_conversion ) {
					$shared_existing_id = $this->find_attachment_by_guid( $image_guid );
				}

				$shared_id = Mobo_Core_Shared_Media::import_attachment(
					$image_guid,
					$product_id,
					$url,
					$shared_existing_id
				);

				if ( $shared_id > 0 ) {
					return $shared_id;
				}

				if ( ! Mobo_Core_Shared_Media::allow_download_fallback() ) {
					$this->set_last_image_error( 'Shared-media manifest is not ready or is incompatible.' );
					return 0;
				}
			}

			if ( $existing_id > 0 ) {
				return $existing_id;
			}

			return $this->download_image( $url, $product_id, $image_guid );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && ! empty( $identity_locks ) ) {
				foreach ( array_reverse( $identity_locks, true ) as $lock_name => $lock_token ) {
					Mobo_Core_Lock::release( $lock_name, $lock_token );
				}
			}
		}
	}

	private function download_image( $url, $product_id, $image_guid ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );

		if ( '' === $url || $product_id <= 0 || '' === $image_guid ) {
			$this->set_last_image_error( 'Image download arguments are invalid.' );
			return 0;
		}

		$existing_id = $this->find_existing_attachment( $image_guid, $url );

		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		if ( $this->is_local_or_private_image_url( $url ) ) {
			if ( ! (bool) apply_filters( 'mobo_core_allow_unsafe_local_image_download', false, $url, $product_id ) ) {
				$this->set_last_image_error( 'WordPress blocked a local/private image URL.' );
				return 0;
			}

			$attachment_id = $this->download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid );
		} else {
			$secure_image_request_args = static function ( $args, $request_url ) {
				$args['sslverify'] = (bool) apply_filters( 'mobo_core_http_sslverify', true, 'image_sideload' );
				$args['timeout']   = min( 20, max( 8, isset( $args['timeout'] ) ? absint( $args['timeout'] ) : 15 ) );
				$args['redirection'] = min( 3, isset( $args['redirection'] ) ? absint( $args['redirection'] ) : 3 );

				return $args;
			};

			$identity_bootstrap = $this->register_attachment_identity_bootstrap( $product_id, $image_guid, $url );

			add_filter( 'http_request_args', $secure_image_request_args, 10, 2 );

			try {
				$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
			} finally {
				remove_filter( 'http_request_args', $secure_image_request_args, 10 );
				$this->unregister_attachment_identity_bootstrap( $identity_bootstrap );
			}

			if ( is_wp_error( $attachment_id ) ) {
				$error_message = $attachment_id->get_error_message();
				$this->set_last_image_error( 'WordPress image sideload failed: ' . $error_message );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					Mobo_Core_Logger::error( 'Mobo Core image sideload failed, trying unsafe-local fallback: ' . $error_message );
				}

				$attachment_id = (bool) apply_filters( 'mobo_core_allow_unsafe_local_image_download', false, $url, $product_id )
					? $this->download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid )
					: 0;
			}
		}

		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 ) {
			if ( '' === $this->get_last_image_error() ) {
				$this->set_last_image_error( 'Image download/import returned no attachment.' );
			}
			return 0;
		}

		/* The sideload API returning an attachment ID is not the final storage commit.
		 * Verify the physical payload immediately; otherwise a mislabeled/corrupt WebP
		 * source can create one unusable attachment on every retry. This attachment was
		 * created inside the current identity lock and has not been linked to a product,
		 * so deleting it here is safe and prevents orphan accumulation. */
		if ( ! $this->is_attachment_reusable_for_source( $attachment_id, $url ) ) {
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
			}
			$this->set_last_image_error( 'Downloaded image failed physical MIME/file validation and was discarded before product linkage.' );
			return 0;
		}

		/* Do not mark the attachment complete here. The physical original passed the
		 * first gate, but metadata/subsize readiness is verified by the caller. Keep
		 * the identity durable and explicitly incomplete across any crash in that gap. */
		$this->update_attachment_meta_if_changed( $attachment_id, 'image_guid', $image_guid );
		$this->update_attachment_meta_if_changed( $attachment_id, 'img_guid', $image_guid );
		$this->update_attachment_meta_if_changed( $attachment_id, 'mobo_source_url', $url );
		$this->update_attachment_meta_if_changed( $attachment_id, 'mobo_sync_incomplete', '1' );

		return $attachment_id;
	}


	/**
	 * Build a temporary whitelist filter for WordPress safe HTTP requests.
	 *
	 * WordPress blocks localhost/private hosts in download_url()/media_sideload_image()
	 * via wp_safe_remote_get(). During local WAMP + local .NET tests, images may be
	 * served from 127.0.0.1 or localhost. This filter only allows the exact image
	 * host for the duration of the sideload request.
	 *
	 * @param string $url Image URL.
	 * @return callable
	 */

	/**
	 * Download an image using wp_remote_get with reject_unsafe_urls disabled.
	 *
	 * This fallback is disabled by default and only runs when a developer explicitly
	 * enables the mobo_core_allow_unsafe_local_image_download filter for local/dev use.
	 * It is only intended for environments where WordPress rejects
	 * localhost/private IP URLs before media_sideload_image() can download them.
	 * It still imports the file via media_handle_sideload(), so WordPress validates
	 * the file type before creating the attachment.
	 *
	 * @param string $url Image URL.
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @return int Attachment ID or 0.
	 */
	private function download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );

		if ( '' === $url || $product_id <= 0 ) {
			$this->set_last_image_error( 'Unsafe-local fallback arguments are invalid.' );
			return 0;
		}

		$file_name = $this->get_download_file_name( $url, $image_guid );
		$tmp_file  = wp_tempnam( $file_name );

		if ( ! $tmp_file ) {
			$this->set_last_image_error( 'Could not create a temporary image file.' );
			return 0;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 15,
				'redirection'        => 5,
				'sslverify'          => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'image_sync' ),
				'reject_unsafe_urls' => ! (bool) apply_filters( 'mobo_core_allow_unsafe_local_image_download', false, $url, $product_id ),
				'stream'             => true,
				'filename'           => $tmp_file,
				'headers'            => array(
					'User-Agent' => 'Mobo Core/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : 'dev' ) . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp_file );
			$error_message = $response->get_error_message();
			$this->set_last_image_error( 'Image HTTP request failed: ' . $error_message );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Mobo_Core_Logger::error( 'Mobo Core image fallback download failed: ' . $error_message );
			}

			return 0;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 || ! file_exists( $tmp_file ) || filesize( $tmp_file ) <= 0 ) {
			wp_delete_file( $tmp_file );
			$this->set_last_image_error( 'Image HTTP response was ' . $code . ' or the response body was empty.' );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Mobo_Core_Logger::error( 'Mobo Core image fallback download failed with HTTP ' . $code . ': ' . $url );
			}

			return 0;
		}

		$file = array(
			'name'     => $file_name,
			'tmp_name' => $tmp_file,
		);

		$allow_webp = static function ( $mimes ) {
			$mimes['webp'] = 'image/webp';
			return $mimes;
		};

		$identity_bootstrap = $this->register_attachment_identity_bootstrap( $product_id, $image_guid, $url );
		add_filter( 'upload_mimes', $allow_webp, 10, 1 );

		try {
			$attachment_id = media_handle_sideload( $file, $product_id );
		} finally {
			remove_filter( 'upload_mimes', $allow_webp, 10 );
			$this->unregister_attachment_identity_bootstrap( $identity_bootstrap );
		}

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp_file );
			$error_message = $attachment_id->get_error_message();
			$this->set_last_image_error( 'WordPress media import failed: ' . $error_message );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Mobo_Core_Logger::error( 'Mobo Core image fallback sideload failed: ' . $error_message );
			}

			return 0;
		}

		/* Final identity completion is deliberately owned by download_image() after
		 * physical validation. The add_attachment bootstrap above leaves this row
		 * marked incomplete if PHP stops before that final gate. */
		return absint( $attachment_id );
	}

	/**
	 * Register the Mobo identity at the earliest possible WordPress attachment
	 * lifecycle point. media_sideload_image()/media_handle_sideload() create the DB
	 * row before returning control to us; without this hook a fatal error in that
	 * gap leaves an unidentifiable physical file and the next retry creates -1/-2
	 * collision filenames. The first attachment inserted for the expected parent is
	 * claimed and marked incomplete until the outer storage validation commits it.
	 *
	 * @param int    $product_id Parent product ID.
	 * @param string $image_guid Remote image GUID.
	 * @param string $url Source URL.
	 * @return callable|null
	 */
	private function register_attachment_identity_bootstrap( $product_id, $image_guid, $url ) {
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$url        = esc_url_raw( (string) $url );
		if ( $product_id <= 0 || '' === $image_guid || '' === $url ) {
			return null;
		}

		$claimed = 0;
		$callback = function ( $attachment_id ) use ( $product_id, $image_guid, $url, &$claimed ) {
			$attachment_id = absint( $attachment_id );
			if ( $claimed > 0 || $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
				return;
			}

			$post = get_post( $attachment_id );
			if ( ! $post || absint( $post->post_parent ) !== $product_id ) {
				return;
			}

			$claimed = $attachment_id;
			update_post_meta( $attachment_id, 'image_guid', $image_guid );
			update_post_meta( $attachment_id, 'img_guid', $image_guid );
			update_post_meta( $attachment_id, 'mobo_source_url', $url );
			update_post_meta( $attachment_id, 'mobo_sync_incomplete', '1' );
			update_post_meta( $attachment_id, 'mobo_image_format', $this->is_webp_url( $url ) ? 'webp' : 'image' );
		};

		add_action( 'add_attachment', $callback, 1, 1 );
		return $callback;
	}

	/**
	 * Remove a request-scoped attachment bootstrap hook.
	 *
	 * @param callable|null $callback Callback returned by register_attachment_identity_bootstrap().
	 * @return void
	 */
	private function unregister_attachment_identity_bootstrap( $callback ) {
		if ( is_callable( $callback ) ) {
			remove_action( 'add_attachment', $callback, 1 );
		}
	}

	/**
	 * Build a safe filename for sideloaded images.
	 *
	 * @param string $url Image URL.
	 * @param string $image_guid Image GUID.
	 * @return string
	 */
	private function get_download_file_name( $url, $image_guid ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );

		if ( '' === $name || '.' === $name || false === strpos( $name, '.' ) ) {
			$name = sanitize_file_name( ( '' !== $image_guid ? $image_guid : md5( $url ) ) . '.webp' );
		}

		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed   = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' );

		if ( ! in_array( $extension, $allowed, true ) ) {
			$name .= '.webp';
		}

		return $name;
	}

	/**
	 * Detect local/private image URLs that WordPress safe HTTP blocks.
	 *
	 * In local WAMP + local .NET tests a friendly host such as codeya.ir may
	 * resolve to 127.0.0.1 via the Windows hosts file. For these URLs, skip
	 * media_sideload_image() and use the explicit unsafe-local fallback directly.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private function is_local_or_private_image_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! is_string( $ip ) || '' === $ip || $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( '127.' === substr( $ip, 0, 4 ) || '::1' === $ip ) {
			return true;
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	private function build_safe_local_image_host_filter( $url ) {
		$image_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$api_host   = strtolower( (string) wp_parse_url( (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' ), PHP_URL_HOST ) );

		$allowed_hosts = array_filter(
			array_unique(
				array(
					$image_host,
					$api_host,
					'localhost',
					'127.0.0.1',
					'::1',
				)
			)
		);

		return static function ( $external, $host, $request_url ) use ( $allowed_hosts ) {
			$host = strtolower( (string) $host );

			if ( in_array( $host, $allowed_hosts, true ) ) {
				return true;
			}

			return $external;
		};
	}

	/**
	 * Import a replacement image for the legacy-image refresh queue.
	 *
	 * @param string $url New image URL.
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Remote image GUID.
	 * @param int    $old_attachment_id Old attachment being replaced.
	 * @return int Attachment ID or 0.
	 */
	public function import_image_for_refresh( $url, $product_id, $image_guid, $old_attachment_id = 0 ) {
		$this->load_media_dependencies();

		if ( class_exists( 'Mobo_Core_Shared_Media' )
			&& ( method_exists( 'Mobo_Core_Shared_Media', 'is_configured' ) ? Mobo_Core_Shared_Media::is_configured() : Mobo_Core_Shared_Media::is_enabled() ) ) {
			/*
			 * Route Shared Media refresh through the same GUID/source identity lock as
			 * normal imports, but keep the refresh invariant that the old local
			 * attachment is never converted in place.
			 */
			$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid, false );
		} else {
			$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid );
		}

		$attachment_id = absint( $attachment_id );

		if ( $attachment_id > 0 ) {
			update_post_meta( $attachment_id, 'mobo_replaces_attachment_id', absint( $old_attachment_id ) );
			update_post_meta( $attachment_id, 'mobo_image_format', $this->is_attachment_webp( $attachment_id ) ? 'webp' : 'image' );
		}

		return $attachment_id;
	}

	/**
	 * Final storage gate before an attachment is linked to a WooCommerce product.
	 * A successful sideload is not enough: WebP metadata/subsizes (or the complete
	 * Shared Media manifest) must also be healthy.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Current source URL.
	 * @return bool
	 */
	private function ensure_attachment_ready_for_linkage( $attachment_id, $url ) {
		$attachment_id = absint( $attachment_id );
		$url           = esc_url_raw( (string) $url );
		$cache_key     = $attachment_id . '|' . md5( $url );
		if ( isset( $this->attachment_readiness_cache[ $cache_key ] ) && $this->attachment_readiness_cache[ $cache_key ] ) {
			return true;
		}

		if ( ! $this->is_attachment_reusable_for_source( $attachment_id, $url ) ) {
			$this->set_last_image_error( 'Imported attachment failed final file/source validation.' );
			return false;
		}

		if ( ! $this->is_webp_url( $url ) && ! $this->is_attachment_webp( $attachment_id ) ) {
			$this->attachment_readiness_cache[ $cache_key ] = true;
			return true;
		}

		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Service' ) ) {
			$this->set_last_image_error( 'WebP readiness service is unavailable.' );
			return false;
		}

		$refresh = new Mobo_Core_Image_Refresh_Service();
		if ( ! method_exists( $refresh, 'ensure_webp_attachment_ready' ) ) {
			$this->set_last_image_error( 'WebP readiness verifier is unavailable.' );
			return false;
		}

		$result = $refresh->ensure_webp_attachment_ready( $attachment_id );
		if ( empty( $result['success'] ) ) {
			$this->set_last_image_error( 'Image file exists but final WebP/subsize validation failed: ' . sanitize_text_field( isset( $result['message'] ) ? (string) $result['message'] : 'Unknown readiness error.' ) );
			return false;
		}

		$this->attachment_readiness_cache[ $cache_key ] = true;
		return true;
	}

	private function mark_attachment_synced( $attachment_id, $image_guid, $url ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		$url           = esc_url_raw( (string) $url );

		if ( $attachment_id <= 0 ) {
			return;
		}

		if ( '' !== $image_guid ) {
			$this->update_attachment_meta_if_changed( $attachment_id, 'image_guid', $image_guid );
			$this->update_attachment_meta_if_changed( $attachment_id, 'img_guid', $image_guid );
		}

		if ( '' !== $url ) {
			$this->update_attachment_meta_if_changed( $attachment_id, 'mobo_source_url', $url );
		}

		$this->update_attachment_meta_if_changed( $attachment_id, 'mobo_sync_incomplete', '0' );

		/* Seed request-local lookup caches with the newly confirmed identity. */
		if ( '' !== $image_guid || '' !== $url ) {
			$this->attachment_lookup_cache[ $this->attachment_lookup_key( $image_guid, $url ) ] = $attachment_id;
		}
		if ( '' !== $image_guid ) {
			$this->prime_attachment_meta_lookup_cache( 'image_guid', $image_guid, $attachment_id );
			$this->prime_attachment_meta_lookup_cache( 'img_guid', $image_guid, $attachment_id );
		}
		if ( '' !== $url ) {
			$this->prime_attachment_meta_lookup_cache( 'mobo_source_url', $url, $attachment_id );
		}
	}

	/**
	 * Update attachment metadata only when its scalar value actually changed.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function update_attachment_meta_if_changed( $attachment_id, $meta_key, $value ) {
		$attachment_id = absint( $attachment_id );
		$meta_key      = sanitize_key( (string) $meta_key );
		if ( $attachment_id <= 0 || '' === $meta_key ) {
			return;
		}

		$current = get_post_meta( $attachment_id, $meta_key, true );
		if ( (string) $current === (string) $value ) {
			return;
		}

		update_post_meta( $attachment_id, $meta_key, $value );
	}

	private function sync_woocommerce_product_image_objects_from_queue( $product_id, Mobo_Core_Image_Queue $queue ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return false;
		}

		$rows = method_exists( $queue, 'get_ordered_rows_for_product' ) ? $queue->get_ordered_rows_for_product( $product_id ) : array();

		if ( empty( $rows ) ) {
			$ids = $queue->get_done_attachment_ids_for_product( $product_id );

			if ( empty( $ids ) ) {
				return false;
			}

			return $this->sync_woocommerce_product_image_objects( $product_id, $ids );
		}

		$ids            = array();
		$first_done_id  = 0;
		$first_position = null;

		foreach ( $rows as $row ) {
			$position      = isset( $row['position_index'] ) ? absint( $row['position_index'] ) : 0;
			$attachment_id = isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0;
			$status        = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';

			if ( null === $first_position ) {
				$first_position = $position;
			}

			if ( ! in_array( $status, array( 'done', 'attaching' ), true ) || $attachment_id <= 0 ) {
				continue;
			}

			$source_url = isset( $row['source_url'] ) ? esc_url_raw( (string) $row['source_url'] ) : '';
			if ( ! $this->is_attachment_reusable_for_source( $attachment_id, $source_url )
				|| ! $this->ensure_attachment_ready_for_linkage( $attachment_id, $source_url ) ) {
				/* A durable done row from an older release may have a healthy original but
				 * incomplete/corrupt cuts. Do not silently omit it forever: move it back to
				 * the normal repair path so storage readiness and product linkage converge. */
				$row_id    = absint( isset( $row['id'] ) ? $row['id'] : 0 );
				$try_count = absint( isset( $row['try_count'] ) ? $row['try_count'] : 0 ) + 1;
				if ( $row_id > 0 ) {
					$message    = $this->get_last_image_error();
					$message    = '' !== $message ? $message : 'Attachment failed final storage readiness while rebuilding the product gallery.';
					$image_guid = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
					if ( method_exists( $queue, 'schedule_repair_if_current' ) ) {
						$queue->schedule_repair_if_current( $row_id, $message, $try_count, $product_id, $image_guid, $source_url );
					} else {
						$queue->mark_failure( $row_id, $message, $try_count, false );
					}
				}
				continue;
			}

			if ( $position === $first_position ) {
				$first_done_id = $attachment_id;
			}

			$ids[] = $attachment_id;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return false;
		}

		if ( $first_done_id > 0 ) {
			return $this->sync_woocommerce_product_image_objects( $product_id, $ids );
		}

		/*
		 * If the first Mobo image is not downloaded yet, do not promote a later
		 * image to featured image. Updating only the gallery is safer and avoids a
		 * visible first-image flip on old/new products.
		 */
		return $this->sync_woocommerce_product_gallery_only( $product_id, $ids );
	}

	/**
	 * Sync product images by the exact order received from the Mobo product payload.
	 *
	 * The old plugin stored only img_guid and sometimes made the last uploaded image
	 * the featured image. This method intentionally resolves attachments by
	 * image_guid/img_guid/mobo_source_url and sets the WooCommerce featured image to
	 * the first Mobo image only when that first image is available.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Mobo image payload.
	 * @return void
	 */
	private function sync_woocommerce_product_image_objects_from_payload_order( $product_id, $images ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! is_array( $images ) || empty( $images ) ) {
			return;
		}

		$ordered_ids     = array();
		$first_remote_id = 0;
		$first_seen      = false;

		foreach ( array_values( $images ) as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$image_guid = $this->get_image_guid( $image );
			$url        = $this->get_image_url( $image );

			if ( '' === $image_guid || '' === $url ) {
				continue;
			}

			$attachment_id = $this->find_existing_attachment( $image_guid, $url );
			if ( $attachment_id > 0 && ! $this->ensure_attachment_ready_for_linkage( $attachment_id, $url ) ) {
				$attachment_id = 0;
			}

			if ( ! $first_seen ) {
				$first_seen      = true;
				$first_remote_id = $attachment_id;
			}

			if ( $attachment_id > 0 ) {
				$ordered_ids[] = $attachment_id;
			}
		}

		$ordered_ids = array_values( array_unique( array_filter( array_map( 'absint', $ordered_ids ) ) ) );

		if ( empty( $ordered_ids ) ) {
			return;
		}

		if ( $first_remote_id > 0 ) {
			$this->sync_woocommerce_product_image_objects( $product_id, $ordered_ids );
			return;
		}

		$this->sync_woocommerce_product_gallery_only( $product_id, $ordered_ids );
	}

	/**
	 * Update gallery without changing the featured image.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $gallery_ids Attachment IDs.
	 * @return bool
	 */
	private function sync_woocommerce_product_gallery_only( $product_id, $gallery_ids ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );

		/* Gallery-only mode intentionally preserves the current featured image. It
		 * must therefore also exclude that ID from WooCommerce gallery metadata;
		 * otherwise a partial async image batch can duplicate the featured image. */
		$current_featured = absint( $product->get_image_id() );
		if ( $current_featured > 0 ) {
			$gallery_ids = array_values( array_filter( $gallery_ids, static function ( $id ) use ( $current_featured ) {
				return absint( $id ) !== $current_featured;
			} ) );
		}

		if ( empty( $gallery_ids ) ) {
			/* This path is used while the authoritative first image is still pending.
			 * With no ready non-featured image, preserve the existing gallery rather
			 * than turning a transient download gap into destructive UI churn. */
			return true;
		}

		$current_gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ) ) );
		if ( $current_gallery === $gallery_ids ) {
			return true;
		}

		$product->set_gallery_image_ids( $gallery_ids );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== $product_id ) {
			return false;
		}

		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product ) {
			return false;
		}
		$fresh_gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) $fresh->get_gallery_image_ids() ) ) ) );
		if ( $fresh_gallery !== $gallery_ids ) {
			return false;
		}

		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
		return true;
	}

	private function sync_woocommerce_product_image_objects( $product_id, $gallery_ids ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$ordered_ids      = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );
		$desired_image_id = ! empty( $ordered_ids ) ? absint( $ordered_ids[0] ) : 0;
		/* WooCommerce stores the featured image separately in _thumbnail_id. The
		 * gallery meta must contain only the remaining images; storing the first ID
		 * in both places causes duplicate rendering and false reference counts. */
		$desired_gallery = $desired_image_id > 0 ? array_values( array_slice( $ordered_ids, 1 ) ) : $ordered_ids;
		$current_gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ) ) );

		if ( absint( $product->get_image_id() ) === $desired_image_id && $current_gallery === $desired_gallery ) {
			return true;
		}

		$product->set_image_id( $desired_image_id );
		$product->set_gallery_image_ids( $desired_gallery );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== $product_id ) {
			return false;
		}

		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product ) {
			return false;
		}
		$fresh_gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) $fresh->get_gallery_image_ids() ) ) ) );
		if ( absint( $fresh->get_image_id() ) !== $desired_image_id || $fresh_gallery !== $desired_gallery ) {
			return false;
		}

		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
		return true;
	}

	private function find_existing_attachment( $image_guid, $url ) {
		$image_guid = sanitize_text_field( (string) $image_guid );
		$url        = esc_url_raw( (string) $url );
		$cache_key  = $this->attachment_lookup_key( $image_guid, $url );

		if ( array_key_exists( $cache_key, $this->attachment_lookup_cache ) ) {
			$cached_id = absint( $this->attachment_lookup_cache[ $cache_key ] );
			if ( $cached_id <= 0 ) {
				return 0;
			}

			/* A request-local hit can become stale after repair/deletion in the same request. */
			if ( $this->attachment_guid_matches_or_unclaimed( $cached_id, $image_guid )
				&& $this->is_attachment_reusable_for_source( $cached_id, $url ) ) {
				return $cached_id;
			}

			unset( $this->attachment_lookup_cache[ $cache_key ] );
		}

		$candidates = array();

		if ( '' !== $image_guid ) {
			$candidates = array_merge( $candidates, $this->find_attachments_by_meta( 'image_guid', $image_guid, 10 ) );
			$candidates = array_merge( $candidates, $this->find_attachments_by_meta( 'img_guid', $image_guid, 10 ) );
		}

		if ( '' !== $url ) {
			$candidates = array_merge( $candidates, $this->find_attachments_by_meta( 'mobo_source_url', $url, 10 ) );
		}

		$candidates = array_values( array_unique( array_filter( array_map( 'absint', $candidates ) ) ) );

		foreach ( $candidates as $attachment_id ) {
			if ( $this->attachment_guid_matches_or_unclaimed( $attachment_id, $image_guid )
				&& $this->is_attachment_reusable_for_source( $attachment_id, $url ) ) {
				$this->attachment_lookup_cache[ $cache_key ] = absint( $attachment_id );
				return absint( $attachment_id );
			}
		}

		$this->attachment_lookup_cache[ $cache_key ] = 0;
		return 0;
	}

	private function attachment_lookup_key( $image_guid, $url ) {
		return md5( sanitize_text_field( (string) $image_guid ) . '|' . esc_url_raw( (string) $url ) );
	}

	/**
	 * Forget request-local identity/meta lookup entries before a post-lock recheck.
	 *
	 * A miss cached immediately before another worker completes an import must not
	 * survive into the identity-lock critical section, otherwise the second worker
	 * can still create a collision copy despite owning the lock after the first one.
	 *
	 * @param string $image_guid Remote image GUID.
	 * @param string $url Source URL.
	 * @return void
	 */
	private function forget_attachment_lookup_caches( $image_guid, $url ) {
		$image_guid = sanitize_text_field( (string) $image_guid );
		$url        = esc_url_raw( (string) $url );

		unset( $this->attachment_lookup_cache[ $this->attachment_lookup_key( $image_guid, $url ) ] );

		if ( '' !== $image_guid ) {
			unset( $this->attachment_meta_lookup_cache[ 'image_guid|' . md5( $image_guid ) ] );
			unset( $this->attachment_meta_lookup_cache[ 'img_guid|' . md5( $image_guid ) ] );
		}

		if ( '' !== $url ) {
			unset( $this->attachment_meta_lookup_cache[ 'mobo_source_url|' . md5( $url ) ] );
		}
	}

	private function find_attachment_by_guid( $guid ) {
		$ids = $this->find_attachments_by_guid( $guid, 1 );

		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	private function find_attachments_by_guid( $guid, $limit = 10 ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return array();
		}

		$ids = $this->find_attachments_by_meta( 'image_guid', $guid, $limit );
		$ids = array_merge( $ids, $this->find_attachments_by_meta( 'img_guid', $guid, $limit ) );

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private function find_attachment_by_meta( $meta_key, $meta_value ) {
		$ids = $this->find_attachments_by_meta( $meta_key, $meta_value, 1 );

		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	private function find_attachments_by_meta( $meta_key, $meta_value, $limit = 10 ) {
		$meta_key   = sanitize_key( $meta_key );
		$meta_value = sanitize_text_field( (string) $meta_value );
		$limit      = max( 1, min( 50, absint( $limit ) ) );

		if ( '' === $meta_key || '' === $meta_value ) {
			return array();
		}

		$cache_key = $meta_key . '|' . md5( $meta_value );
		if ( isset( $this->attachment_meta_lookup_cache[ $cache_key ] )
			&& is_array( $this->attachment_meta_lookup_cache[ $cache_key ] )
			&& absint( isset( $this->attachment_meta_lookup_cache[ $cache_key ]['limit'] ) ? $this->attachment_meta_lookup_cache[ $cache_key ]['limit'] : 0 ) >= $limit ) {
			$cached_ids = isset( $this->attachment_meta_lookup_cache[ $cache_key ]['ids'] ) ? (array) $this->attachment_meta_lookup_cache[ $cache_key ]['ids'] : array();
			return array_slice( array_values( array_filter( array_map( 'absint', $cached_ids ) ) ), 0, $limit );
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) ) );
		$this->attachment_meta_lookup_cache[ $cache_key ] = array(
			'limit' => $limit,
			'ids'   => $ids,
		);

		return $ids;
	}

	private function prime_attachment_meta_lookup_cache( $meta_key, $meta_value, $attachment_id ) {
		$meta_key      = sanitize_key( (string) $meta_key );
		$meta_value    = sanitize_text_field( (string) $meta_value );
		$attachment_id = absint( $attachment_id );
		if ( '' === $meta_key || '' === $meta_value || $attachment_id <= 0 ) {
			return;
		}

		$cache_key = $meta_key . '|' . md5( $meta_value );
		$current   = isset( $this->attachment_meta_lookup_cache[ $cache_key ] ) && is_array( $this->attachment_meta_lookup_cache[ $cache_key ] )
			? $this->attachment_meta_lookup_cache[ $cache_key ]
			: array( 'limit' => 10, 'ids' => array() );
		$ids = isset( $current['ids'] ) ? (array) $current['ids'] : array();
		array_unshift( $ids, $attachment_id );
		$current['ids']   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$current['limit'] = max( 10, absint( isset( $current['limit'] ) ? $current['limit'] : 0 ) );
		$this->attachment_meta_lookup_cache[ $cache_key ] = $current;
	}

	/**
	 * An exact source URL is not sufficient identity when different Mobo image
	 * records happen to point at the same physical URL. Never steal an attachment
	 * already owned by another GUID; legacy attachments with no GUID may be adopted.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $image_guid Desired image GUID.
	 * @return bool
	 */
	private function attachment_guid_matches_or_unclaimed( $attachment_id, $image_guid ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		if ( $attachment_id <= 0 || '' === $image_guid ) {
			return false;
		}

		$stored = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
		if ( '' === $stored ) {
			$stored = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
		}
		if ( '' === $stored && class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			$stored = sanitize_text_field( (string) get_post_meta( $attachment_id, '_mobo_shared_media_image_id', true ) );
			if ( '' !== $stored ) {
				return hash_equals( strtolower( $stored ), strtolower( $image_guid ) );
			}
		}

		return '' === $stored || hash_equals( strtolower( trim( $stored ) ), strtolower( trim( $image_guid ) ) );
	}

	private function is_attachment_reusable_for_source( $attachment_id, $url ) {
		$attachment_id = absint( $attachment_id );
		$url           = esc_url_raw( (string) $url );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		/*
		 * A GUID identifies the remote image record, but its source URL can change
		 * when the image is replaced/re-encoded. Once an attachment has a recorded
		 * Mobo source URL, a different current URL must trigger a fresh import instead
		 * of silently relabeling the old physical file with the new URL. Legacy rows
		 * without mobo_source_url remain eligible for GUID-based recovery.
		 */
		$stored_source_url = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
		if ( '' !== $stored_source_url && '' !== $url && ! hash_equals( $stored_source_url, $url ) ) {
			return false;
		}

		/*
		 * A database Attachment row is not enough. Files can be removed manually, by
		 * a failed migration/cache cleanup, or left as zero-byte files after an I/O
		 * failure. Reusing such a row prevents both the normal queue and Missing Image
		 * Recovery from ever downloading a healthy replacement.
		 */
		$detected_mime = '';
		try {
			$file = get_attached_file( $attachment_id );
			$file = is_string( $file ) ? $file : '';

			if ( '' === $file || ! is_file( $file ) || filesize( $file ) <= 0 ) {
				return false;
			}

			/* A non-empty HTML/error payload renamed as an image must not be reused. */
			if ( function_exists( 'wp_get_image_mime' ) ) {
				$detected_mime = strtolower( (string) wp_get_image_mime( $file ) );
				if ( '' === $detected_mime || 0 !== strpos( $detected_mime, 'image/' ) ) {
					return false;
				}
			}
		} catch ( Throwable $error ) {
			return false;
		}

		/* Physical bytes, filename extension and WordPress MIME must describe the
		 * same format. This is important beyond WebP: a .jpg containing WebP/PNG (or
		 * stale post_mime_type) may be decoded by PHP yet served with the wrong HTTP
		 * Content-Type by Apache/nginx/CDNs. Reject inconsistent storage identities so
		 * a retry produces a clean attachment instead of preserving mislabeled media. */
		if ( '' !== $detected_mime && isset( $file ) && '' !== (string) $file ) {
			$physical_ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
			$mime_by_ext  = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'jpe'  => 'image/jpeg',
				'png'  => 'image/png',
				'gif'  => 'image/gif',
				'webp' => 'image/webp',
			);
			if ( isset( $mime_by_ext[ $physical_ext ] ) && ! hash_equals( $mime_by_ext[ $physical_ext ], $detected_mime ) ) {
				return false;
			}

			$stored_mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
			if ( '' !== $stored_mime && 0 === strpos( $stored_mime, 'image/' ) && ! hash_equals( $stored_mime, $detected_mime ) ) {
				return false;
			}
		}

		/*
		 * If the new source is WebP, do not reuse an older jpg/png attachment with
		 * the same image_guid. This is the critical migration behavior: it allows the
		 * normal image queue to download the new WebP instead of silently keeping the
		 * heavy legacy file.
		 */
		if ( $this->is_webp_url( $url ) ) {
			if ( '' !== $detected_mime && 'image/webp' !== $detected_mime ) {
				return false;
			}

			/* Content alone is not enough for a reusable WebP attachment. A file that
			 * contains WebP bytes but is stored as .jpg/.png (or whose attachment MIME
			 * still says JPEG/PNG) may be served with the wrong HTTP Content-Type by the
			 * web server/CDN. Reject it so the normal import path creates a clean WebP
			 * attachment rather than perpetuating an inconsistent storage identity. */
			$physical_file = isset( $file ) ? (string) $file : (string) get_attached_file( $attachment_id );
			$physical_ext  = strtolower( pathinfo( $physical_file, PATHINFO_EXTENSION ) );
			$stored_mime   = strtolower( (string) get_post_mime_type( $attachment_id ) );
			if ( 'webp' !== $physical_ext || 'image/webp' !== $stored_mime ) {
				return false;
			}

			return $this->is_attachment_webp( $attachment_id );
		}

		return true;
	}

	private function is_webp_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		return 'webp' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	private function is_attachment_webp( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$mime          = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$file          = (string) get_attached_file( $attachment_id );
		$ext           = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return 'image/webp' === $mime || 'webp' === $ext;
	}

	private function get_existing_gallery_ids( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return array();
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product ) {
			$ids = $product->get_gallery_image_ids();
			$image_id = absint( $product->get_image_id() );

			if ( $image_id > 0 ) {
				array_unshift( $ids, $image_id );
			}

			return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}

		return array();
	}

	private function get_product_guid( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return '';
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product ) {
			$guid = $product->get_meta( 'product_guid', true );
			return sanitize_text_field( (string) $guid );
		}

		return sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
	}

	private function get_image_guid( $image ) {
		$keys = array(
			'image_guid',
			'img_guid',
			'imageGuid',
			'imageId',
			'guid',
			'remote_guid',
			'remoteGuid',
			'id',
		);

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $image, $key, '' ) );

			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function get_image_url( $image ) {
		$keys = array(
			'url',
			'src',
		);

		foreach ( $keys as $key ) {
			$value = esc_url_raw( (string) $this->get_value( $image, $key, '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}


	/**
	 * Check whether a value is usable as a remote GUID.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_remote_guid_value( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return false;
		}

		if ( false !== strpos( $value, '/' ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '://' ) ) {
			return false;
		}

		return true;
	}

	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}

		$pascal = ucfirst( $key );

		if ( array_key_exists( $pascal, $array ) ) {
			return $array[ $pascal ];
		}

		return $default;
	}
}
