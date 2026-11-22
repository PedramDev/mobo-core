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
	 * One bounded fresh-import escape hatch for a locally incomplete attachment.
	 *
	 * The exact attempt is intentional. Once the old identity is quarantined, a
	 * later disk-full or network failure must not quarantine every newly-created
	 * attachment and accumulate orphan files on each retry.
	 */
	const FRESH_REIMPORT_ATTEMPT = 3;
	/** Distinctive prefix of a readiness (WebP/subsize) failure message. */
	const READINESS_FAILURE_PREFIX = 'Image file exists but final WebP/subsize validation failed:';
	/** Seconds between storage-recovery revival probes (M5). */
	const STORAGE_REVIVAL_INTERVAL = 600;

	/** Per-host image breaker state (global option, bounded). */
	const HOST_BREAKER_OPTION = 'mobo_core_image_host_breaker';
	/** Window in which distinct failing images of one host are counted. */
	const HOST_BREAKER_WINDOW = 900;
	/** Distinct images of one host failing with host-level errors that open its breaker. */
	const HOST_BREAKER_DISTINCT = 3;
	const HOST_BREAKER_BASE_COOLDOWN = 120;
	const HOST_BREAKER_MAX_COOLDOWN = 1800;
	const HOST_BREAKER_MAX_HOSTS = 50;
	const HOST_BREAKER_MAX_KEYS = 20;

	/**
	 * Pure: failure class of one image attempt.
	 *  - storage: local storage/WebP service not ready (systemic for this site);
	 *  - host: transport failure (timeout, DNS, connection refused) or HTTP
	 *    5xx/429/408 from the image host (systemic only across distinct images);
	 *  - item: everything that belongs to one image/source (404/410/403, invalid
	 *    URL or image, integrity/readiness/too-large, policy-blocked, permanent).
	 *
	 * @param string $message Queue failure message.
	 * @return string item|host|storage
	 */
	public static function classify_failure_message( $message ) {
		$message = (string) $message;
		if ( 0 === strpos( $message, 'Permanent:' ) ) {
			return 'item';
		}
		foreach ( array( 'Image storage is not ready', 'Could not create a temporary image file.', 'WebP readiness service is unavailable.', 'WebP readiness verifier is unavailable.' ) as $marker ) {
			if ( false !== strpos( $message, $marker ) ) {
				return 'storage';
			}
		}
		if ( 0 === strpos( $message, 'Image HTTP request failed:' ) || false !== strpos( $message, 'wp_media_http_request_failed' ) ) {
			return 'host';
		}
		if ( preg_match( '/(?:\b|_)http_(5\d\d|429|408)\b/', $message ) || preg_match( '/^Image HTTP response was (5\d\d|429|408)\b/', $message ) ) {
			return 'host';
		}
		return 'item';
	}

	/**
	 * Pure: lower-case host of an image source URL ('' when none).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function image_source_host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( trim( $host, " \t\n\r\0\x0B[]" ) ) : '';
	}

	/**
	 * Pure: until when a host's breaker is open (0 = closed).
	 *
	 * @param array  $state Breaker state.
	 * @param string $host Host.
	 * @param int    $now Unix time.
	 * @return int
	 */
	public static function host_breaker_open_until( $state, $host, $now ) {
		$host = (string) $host;
		if ( '' === $host || ! is_array( $state ) || ! isset( $state[ $host ] ) || ! is_array( $state[ $host ] ) ) {
			return 0;
		}
		$until = absint( isset( $state[ $host ]['openUntil'] ) ? $state[ $host ]['openUntil'] : 0 );
		return $until > absint( $now ) ? $until : 0;
	}

	/**
	 * Pure: apply one attempt outcome to the per-host breaker. A single image that
	 * keeps failing never opens its host (distinct image keys are counted); an
	 * open host is probed again after its cooldown and reopens with an escalating
	 * cooldown when the probe fails; any success closes and resets the host.
	 *
	 * @param array  $state Breaker state.
	 * @param string $host Host.
	 * @param string $outcome success|host_failure.
	 * @param string $image_key Image identity (GUID or source URL).
	 * @param int    $now Unix time.
	 * @return array New state.
	 */
	public static function host_breaker_record( $state, $host, $outcome, $image_key, $now ) {
		$state = is_array( $state ) ? $state : array();
		$host  = (string) $host;
		$now   = absint( $now );
		if ( '' === $host || ! in_array( $outcome, array( 'success', 'host_failure' ), true ) ) {
			return $state;
		}
		if ( 'success' === $outcome ) {
			$entry = array( 'failures' => array(), 'openUntil' => 0, 'openCount' => 0, 'probe' => false, 'lastSuccessAt' => $now, 'lastEventAt' => $now );
		} else {
			$old   = isset( $state[ $host ] ) && is_array( $state[ $host ] ) ? $state[ $host ] : array();
			$entry = array(
				'failures'      => isset( $old['failures'] ) && is_array( $old['failures'] ) ? $old['failures'] : array(),
				'openUntil'     => absint( isset( $old['openUntil'] ) ? $old['openUntil'] : 0 ),
				'openCount'     => absint( isset( $old['openCount'] ) ? $old['openCount'] : 0 ),
				'probe'         => ! empty( $old['probe'] ),
				'lastSuccessAt' => absint( isset( $old['lastSuccessAt'] ) ? $old['lastSuccessAt'] : 0 ),
				'lastEventAt'   => $now,
			);
			foreach ( $entry['failures'] as $key => $at ) {
				if ( absint( $at ) + self::HOST_BREAKER_WINDOW < $now ) {
					unset( $entry['failures'][ $key ] );
				}
			}
			$key = substr( (string) $image_key, 0, 191 );
			$entry['failures'][ '' !== $key ? $key : 'unknown' ] = $now;
			if ( count( $entry['failures'] ) > self::HOST_BREAKER_MAX_KEYS ) {
				asort( $entry['failures'] );
				$entry['failures'] = array_slice( $entry['failures'], -self::HOST_BREAKER_MAX_KEYS, null, true );
			}
			$probe_failed = $entry['probe'] && $entry['openUntil'] > 0 && $now >= $entry['openUntil'];
			if ( $probe_failed || count( $entry['failures'] ) >= self::HOST_BREAKER_DISTINCT ) {
				$entry['openCount']++;
				$entry['openUntil'] = $now + min( self::HOST_BREAKER_MAX_COOLDOWN, self::HOST_BREAKER_BASE_COOLDOWN * (int) pow( 2, min( 4, $entry['openCount'] - 1 ) ) );
				$entry['probe']     = true;
				$entry['failures']  = array();
			}
		}
		$state[ $host ] = $entry;
		if ( count( $state ) > self::HOST_BREAKER_MAX_HOSTS ) {
			uasort(
				$state,
				static function ( $a, $b ) {
					return absint( isset( $a['lastEventAt'] ) ? $a['lastEventAt'] : 0 ) - absint( isset( $b['lastEventAt'] ) ? $b['lastEventAt'] : 0 );
				}
			);
			$state = array_slice( $state, -self::HOST_BREAKER_MAX_HOSTS, null, true );
		}
		return $state;
	}

	/**
	 * Pure: failures of one image pass that count toward the image-stage circuit
	 * breaker. Per-item failures never count. Host-level failures count only when
	 * they span at least two distinct hosts and no host succeeded in the pass
	 * (network/DNS outage); a single failing host is isolated by its own breaker.
	 * Storage and internal (linkage/exception) failures always count.
	 *
	 * @param int   $storage_failed Storage-not-ready failures.
	 * @param array $host_failed Host => host-level failure count.
	 * @param array $successful_hosts Host => true for hosts that succeeded.
	 * @param int   $internal_failed Internal failures.
	 * @return int
	 */
	public static function circuit_failed_count( $storage_failed, $host_failed, $successful_hosts, $internal_failed ) {
		$count       = absint( $storage_failed ) + absint( $internal_failed );
		$host_failed = is_array( $host_failed ) ? array_filter( array_map( 'absint', $host_failed ) ) : array();
		if ( count( $host_failed ) >= 2 && empty( $successful_hosts ) ) {
			$count += array_sum( $host_failed );
		}
		return $count;
	}

	/** @return array Per-host breaker state. */
	private function load_host_breaker() {
		$state = get_option( self::HOST_BREAKER_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array $state Per-host breaker state.
	 * @return void
	 */
	private function save_host_breaker( $state ) {
		update_option( self::HOST_BREAKER_OPTION, is_array( $state ) ? $state : array(), false );
	}


	/**
	 * Last image resolution error for queue diagnostics.
	 *
	 * @var string
	 */
	private $last_image_error = '';

	/** @var string Integrity/decode-budget refusal recorded by the sideload guard. */
	private $sideload_integrity_error = '';

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
		if ( is_array( $images ) && class_exists( 'Mobo_Core_Image_Desired_State_Policy' ) ) {
			$images = Mobo_Core_Image_Desired_State_Policy::ordered_collection( $images );
		}

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
		/* Zero is not a safe 'disabled' value: returning done before Desired State is
		 * enqueued can make callers mark images converged without durable queue evidence.
		 * Image updates are disabled by product policy/queue mode, not by a zero batch. */
		$limit      = Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 1, 1, 10 );

		if ( $product_id <= 0 || ! is_array( $images ) ) {
			return array(
				'done'       => true,
				'nextOffset' => 0,
				'processed'  => 0,
				'skipped'    => 0,
			);
		}

		/* Cleanup ownership is mirrored on attachment metadata, so an explicit empty
		 * desired-state retry can still converge after the owning product was deleted.
		 * Do this before the images-per-run gate: deletion cleanup is not image import
		 * work and must not become permanently disabled by a zero download budget. */
		if ( empty( $images ) && ! get_post( $product_id ) ) {
			return $this->retry_pruned_cleanup_for_deleted_owner( $product_id );
		}

		if ( $limit <= 0 ) {
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

		return $this->process_images_direct_authoritative( $product_id, $images, $offset, $limit );
	}

	/**
	 * Preserve authoritative queue membership and cleanup semantics even when queue
	 * execution is disabled. Queue rows remain the durable desired-state ledger; only
	 * asynchronous queue processing is disabled by the setting.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Desired images.
	 * @param int   $offset Legacy direct offset.
	 * @param int   $limit Direct processing limit.
	 * @return array
	 */
	private function process_images_direct_authoritative( $product_id, $images, $offset, $limit ) {
		$queue                  = null;
		$removed_attachment_ids = array();
		$enqueue                = array( 'enqueued' => 0, 'skipped' => 0, 'removed' => 0, 'error' => '' );

		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$queue   = new Mobo_Core_Image_Queue();
			$enqueue = $queue->enqueue_product_images( $product_id, $this->get_product_guid( $product_id ), $images );
			$removed_attachment_ids = isset( $enqueue['removedAttachmentIds'] ) && is_array( $enqueue['removedAttachmentIds'] )
				? array_values( array_unique( array_filter( array_map( 'absint', $enqueue['removedAttachmentIds'] ) ) ) )
				: array();

			$enqueue_error = isset( $enqueue['error'] ) ? sanitize_text_field( (string) $enqueue['error'] ) : '';
			if ( '' === $enqueue_error && ( ! empty( $enqueue['skipped'] ) || ! empty( $enqueue['pruneDeferred'] ) ) ) {
				$enqueue_error = 'Image desired state was not fully accepted; malformed or ambiguous rows were preserved for retry.';
			}
			if ( '' !== $enqueue_error ) {
				return array(
					'done'       => false,
					'nextOffset' => 0,
					'processed'  => 0,
					'failed'     => 1,
					'skipped'    => isset( $enqueue['skipped'] ) ? absint( $enqueue['skipped'] ) : 0,
					'queued'     => isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0,
					'removed'    => isset( $enqueue['removed'] ) ? absint( $enqueue['removed'] ) : 0,
					'error'      => $enqueue_error,
				);
			}
		}

		$removed_attachment_ids = array_values( array_unique( array_merge(
			$this->get_pending_pruned_cleanup_ids( $product_id ),
			$removed_attachment_ids
		) ) );
		$unlink_ok = true;
		if ( $queue instanceof Mobo_Core_Image_Queue ) {
			/* Queue is authoritative; do not trust the older payload's image array if
			 * another request has replaced the desired set since our enqueue. */
			$pruned_unlink = $this->unlink_pruned_against_current_desired( $product_id, $queue, $removed_attachment_ids );
			$removed_attachment_ids = isset( $pruned_unlink['ids'] ) ? (array) $pruned_unlink['ids'] : array();
			$unlink_ok = ! empty( $pruned_unlink['success'] );
		} else {
			/* Legacy direct fallback: only explicitly owned GUIDs from current Woo
			 * linkage can become candidates; URL is not destructive authority. */
			$removed_attachment_ids = array_values( array_unique( array_merge(
				$removed_attachment_ids,
				$this->get_direct_pruned_attachment_candidates( $product_id, $images )
			) ) );
			$removed_attachment_ids = $this->filter_pruned_attachment_candidates_against_images( $removed_attachment_ids, $images );
			$this->set_pending_pruned_cleanup_ids( $product_id, $removed_attachment_ids );
			$unlink_ok = empty( $removed_attachment_ids ) || $this->unlink_pruned_attachments_from_product( $product_id, $removed_attachment_ids );
		}

		if ( ! $unlink_ok ) {
			return array(
				'done'       => false,
				'nextOffset' => 0,
				'processed'  => 0,
				'failed'     => 1,
				'skipped'    => isset( $enqueue['skipped'] ) ? absint( $enqueue['skipped'] ) : 0,
				'queued'     => isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0,
				'removed'    => isset( $enqueue['removed'] ) ? absint( $enqueue['removed'] ) : 0,
				'error'      => 'Stale image desired-state rows were pruned but WooCommerce could not safely detach their attachments.',
			);
		}

		if ( empty( $images ) ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return array( 'done' => false, 'nextOffset' => 0, 'processed' => 0, 'failed' => 1, 'skipped' => 0, 'error' => 'Product could not be reloaded while committing an explicit empty image desired state.' );
			}
			$product->set_image_id( 0 );
			$product->set_gallery_image_ids( array() );
			if ( absint( $product->save() ) !== $product_id ) {
				return array( 'done' => false, 'nextOffset' => 0, 'processed' => 0, 'failed' => 1, 'skipped' => 0, 'error' => 'WooCommerce did not confirm the explicit empty image desired-state save.' );
			}
			$result = array( 'done' => true, 'nextOffset' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'error' => '' );
		} else {
			$result = $this->process_images_direct( $product_id, $images, $offset, $limit );
		}

		$cleanup = $this->enqueue_pruned_attachments_for_background_cleanup( $product_id, $removed_attachment_ids );

		$result['queued']             = isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0;
		$result['removed']            = isset( $enqueue['removed'] ) ? absint( $enqueue['removed'] ) : 0;
		$result['deletedAttachments'] = 0;
		$result['cleanupQueued']      = isset( $cleanup['queued'] ) ? absint( $cleanup['queued'] ) : 0;
		$result['cleanupRetained']    = isset( $cleanup['retained'] ) ? absint( $cleanup['retained'] ) : 0;
		if ( ! isset( $result['error'] ) ) {
			$result['error'] = '';
		}
		return $result;
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

			$limit = $limit > 0 ? absint( $limit ) : Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 1, 1, 10 );

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

			/*
			 * Drain historical orphan rows outside the normal image batch. They are
			 * terminal state transitions, not download failures, so they must not consume
			 * images-per-run capacity or trip the image-stage failure circuit. No rows,
			 * attachments or files are deleted here.
			 */
			$terminalized = method_exists( $queue, 'terminalize_due_missing_products' )
				? $queue->terminalize_due_missing_products( max( 25, min( 100, $limit * 20 ) ) )
				: 0;

			/* M5: once storage is healthy again, expedite rows that were only
			 * waiting for disk/quota and revive (bounded) terminal readiness rows. */
			$revived = $this->maybe_revive_storage_blocked_images( $queue );

			/*
			 * Claim the selected rows before any row-level validation. In particular,
			 * missing/deleted products and malformed source identities are terminally
			 * classified by process_queue_rows() through mark_failure_if_claimed().
			 * Reading due rows without a claim leaves those branches without a lease
			 * token, so they can only defer and the oldest rows can permanently block
			 * every newer image (head-of-line starvation).
			 */
			$rows = method_exists( $queue, 'claim_due_images' )
				? $queue->claim_due_images( $limit, 300 )
				: $queue->get_due_images( $limit );

			$result = $this->process_queue_rows( $queue, $rows, $limit );
			if ( is_array( $result ) ) {
				$result['terminalized'] = absint( $terminalized );
				$result['revived']      = absint( $revived );
				$result['convergedImageHashes'] = $this->promote_pending_image_hashes( $queue, min( 10, max( 1, $limit ) ) );
			}
			return $result;
		} finally {
			Mobo_Core_Lock::release( 'image_queue_worker', $worker_lock );
		}
	}

	/**
	 * M5: after a storage outage, give storage-blocked images their turn back.
	 *
	 * Throttled to one probe per REVIVAL_INTERVAL. Only runs when the storage
	 * probe passes, so it never churns while the disk is still full.
	 *
	 * @param Mobo_Core_Image_Queue $queue Queue.
	 * @return int Rows expedited or revived.
	 */
	private function maybe_revive_storage_blocked_images( $queue ) {
		if ( ! is_object( $queue ) || ! method_exists( $queue, 'revive_storage_blocked' ) || ! class_exists( 'Mobo_Core_Image_Storage' ) ) {
			return 0;
		}
		$last = absint( get_option( 'mobo_core_image_storage_revival_at', 0 ) );
		if ( $last > 0 && ( time() - $last ) < self::STORAGE_REVIVAL_INTERVAL ) {
			return 0;
		}
		update_option( 'mobo_core_image_storage_revival_at', time(), false );
		$storage = Mobo_Core_Image_Storage::check();
		if ( empty( $storage['ready'] ) ) {
			return 0;
		}
		return absint( $queue->revive_storage_blocked( 100 ) );
	}

	/**
	 * Return compact queue status.
	 *
	 * @return array
	 */
	public function get_queue_status() {
		$status = array(
			'enabled'       => false,
			'pending'       => 0,
			'due'           => 0,
			'failed'        => 0,
			'prunePending'  => 0,
			'pruneDue'      => 0,
			'pruneFailed'   => 0,
			'pruneRetained' => 0,
		);
		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$queue  = new Mobo_Core_Image_Queue();
			$remote = $queue->get_status();
			$status = array_merge( $status, is_array( $remote ) ? $remote : array() );
		}

		if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) && Mobo_Core_Image_Prune_Queue::table_exists() ) {
			$prune   = new Mobo_Core_Image_Prune_Queue();
			$summary = $prune->get_summary();
			$prune_due = absint( isset( $summary['due'] ) ? $summary['due'] : 0 );
			if ( $prune->has_due_work() && 0 === $prune_due ) {
				$prune_due = 1; /* Legacy seed debt. */
			}
			$status['prunePending']  = absint( isset( $summary['pending'] ) ? $summary['pending'] : 0 );
			$status['pruneDue']      = $prune_due;
			$status['pruneFailed']   = absint( isset( $summary['failed'] ) ? $summary['failed'] : 0 );
			$status['pruneRetained'] = absint( isset( $summary['retained'] ) ? $summary['retained'] : 0 );
			$status['pending']       = absint( isset( $status['pending'] ) ? $status['pending'] : 0 ) + $status['prunePending'];
			$status['due']           = absint( isset( $status['due'] ) ? $status['due'] : 0 ) + $status['pruneDue'];
			$status['failed']        = absint( isset( $status['failed'] ) ? $status['failed'] : 0 ) + $status['pruneFailed'];
			$status['enabled']       = true;
		}

		return $status;
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
		$product_id = absint( $product_id );
		if ( $product_id > 0 && class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return array(
				'done'        => true,
				'nextOffset'  => 0,
				'processed'   => 0,
				'failed'      => 0,
				'skipped'     => is_array( $images ) ? count( $images ) : 0,
				'queued'      => 0,
				'removed'     => 0,
				'pending'     => 0,
				'due'         => 0,
				'blocking'    => false,
				'queuedAsync' => false,
				'error'       => '',
				'excluded'    => true,
			);
		}

		/*
		 * Keep this variable local and explicit. Older cached copies of this file
		 * could reference the override without a default; this guard also keeps the
		 * method safe if custom integrations call it through reflection/tests.
		 */
		$queue_blocking_override = is_bool( $blocking_override ) ? $blocking_override : null;

		$queue        = new Mobo_Core_Image_Queue();
		$product_guid = $this->get_product_guid( $product_id );

		$enqueue  = $queue->enqueue_product_images( $product_id, $product_guid, $images );
		$removed_attachment_ids = isset( $enqueue['removedAttachmentIds'] ) && is_array( $enqueue['removedAttachmentIds'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $enqueue['removedAttachmentIds'] ) ) ) )
			: array();

		/* A valid row can supersede an old attachment even when another row in the
		 * same payload is malformed and forces the overall enqueue pass to retry.
		 * Preserve those cleanup candidates before any early return; a later valid
		 * pass will re-filter them against the then-current Desired State before
		 * detach/delete, so persistence here is non-destructive. */
		if ( ! empty( $removed_attachment_ids ) ) {
			$this->set_pending_pruned_cleanup_ids(
				$product_id,
				array_values( array_unique( array_merge(
					$this->get_pending_pruned_cleanup_ids( $product_id ),
					$removed_attachment_ids
				) ) )
			);
		}

		$enqueue_error = isset( $enqueue['error'] ) ? sanitize_text_field( (string) $enqueue['error'] ) : '';
		if ( '' === $enqueue_error && ( ! empty( $enqueue['skipped'] ) || ! empty( $enqueue['pruneDeferred'] ) ) ) {
			$enqueue_error = 'Image desired state was not fully accepted; malformed or ambiguous rows were preserved for retry.';
		}
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
		/* Queue pruning establishes authoritative absence. Merge durable cleanup
		 * candidates from an earlier interrupted pass before filtering against the
		 * CURRENT desired queue. This ordering is important: an image may have been
		 * pruned, left pending because WooCommerce save/delete failed, and then be
		 * reintroduced by a newer Desired State before the retry. In that case the
		 * stale cleanup candidate must be cancelled rather than detached/deleted. */
		$removed_attachment_ids = array_values( array_unique( array_merge(
			$this->get_pending_pruned_cleanup_ids( $product_id ),
			$removed_attachment_ids
		) ) );
		$pruned_unlink = $this->unlink_pruned_against_current_desired( $product_id, $queue, $removed_attachment_ids );
		$removed_attachment_ids = isset( $pruned_unlink['ids'] ) ? (array) $pruned_unlink['ids'] : array();
		if ( empty( $pruned_unlink['success'] ) ) {
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
				'error'       => 'Stale image queue rows were pruned but WooCommerce could not safely detach their attachments.',
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

		/* Linkage must be fenced by the SAME desired-state lease as enqueue/prune.
		 * In particular, an old explicit-empty payload must not clear a gallery
		 * which a newer webhook reintroduced after enqueue released its lease. */
		if ( empty( $images ) ) {
			/* Explicit empty is a destructive Woo commit, not an async queue hint. */
			if ( ! $this->commit_explicit_empty_image_desired_state( $product_id, $queue ) ) {
				return array(
					'done' => false, 'nextOffset' => 0, 'processed' => 0, 'failed' => 1,
					'skipped' => isset( $enqueue['skipped'] ) ? absint( $enqueue['skipped'] ) : 0,
					'queued' => isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0,
					'removed' => isset( $enqueue['removed'] ) ? absint( $enqueue['removed'] ) : 0,
					'pending' => 0, 'due' => 0, 'blocking' => false, 'queuedAsync' => false,
					'error' => 'Explicit empty image snapshot was superseded or could not be committed safely.',
				);
			}
		} else {
			/* Non-empty linkage can legitimately return false while async rows have not
			 * downloaded yet. They stay durable; the image runner links them later. */
			$this->sync_woocommerce_product_image_objects_from_queue( $product_id, $queue );
		}

		/* Product/Repair hot-path stops at durable detach + enqueue. Physical deletion
		 * and the cross-site reference proof are performed by the bounded prune queue
		 * outside the manual_sync lease. */
		$cleanup = $this->enqueue_pruned_attachments_for_background_cleanup( $product_id, $removed_attachment_ids );

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
			'deletedAttachments' => 0,
			'cleanupQueued'      => isset( $cleanup['queued'] ) ? absint( $cleanup['queued'] ) : 0,
			'cleanupRetained'    => isset( $cleanup['retained'] ) ? absint( $cleanup['retained'] ) : 0,
			'error'      => '',
		);
	}


	/**
	 * Read durable cleanup candidates left by an earlier interrupted prune pass.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_pending_pruned_cleanup_ids( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}

		/* Hot-path reads are deliberately owner-local. Older builds mirrored owner
		 * IDs on attachment metadata and searched those serialized arrays with LIKE;
		 * 10.33.58 migrates that legacy debt in bounded Image Prune Queue slices
		 * instead of performing a cross-attachment wildcard query during Repair. */
		$value = get_post_meta( $product_id, '_mobo_pruned_cleanup_pending', true );
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
	}

	/**
	 * Persist only candidates that still need a later destructive cleanup retry.
	 * Ownership is mirrored on each attachment so cleanup survives deletion of the
	 * original product post. Both keys are explicitly ignored by live-reference proof.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $attachment_ids Attachment IDs.
	 * @return void
	 */
	private function set_pending_pruned_cleanup_ids( $product_id, $attachment_ids ) {
		$product_id     = absint( $product_id );
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		if ( $product_id <= 0 ) {
			return;
		}

		$previous = $this->get_pending_pruned_cleanup_ids( $product_id );
		$all      = array_values( array_unique( array_merge( $previous, $attachment_ids ) ) );
		foreach ( $all as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}
			$owners = get_post_meta( $attachment_id, '_mobo_pruned_cleanup_owner_ids', true );
			$owners = array_values( array_unique( array_filter( array_map( 'absint', is_array( $owners ) ? $owners : array() ) ) ) );
			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				if ( ! in_array( $product_id, $owners, true ) ) {
					$owners[] = $product_id;
				}
			} else {
				$owners = array_values( array_filter( $owners, static function ( $owner_id ) use ( $product_id ) {
					return absint( $owner_id ) !== $product_id;
				} ) );
			}
			if ( empty( $owners ) ) {
				delete_post_meta( $attachment_id, '_mobo_pruned_cleanup_owner_ids' );
			} else {
				update_post_meta( $attachment_id, '_mobo_pruned_cleanup_owner_ids', $owners );
			}
		}

		if ( get_post( $product_id ) ) {
			if ( empty( $attachment_ids ) ) {
				delete_post_meta( $product_id, '_mobo_pruned_cleanup_pending' );
			} else {
				update_post_meta( $product_id, '_mobo_pruned_cleanup_pending', $attachment_ids );
			}
		}
	}


	/**
	 * Move physical cleanup debt out of Product/Repair hot-path.
	 *
	 * Once stale attachments are durably detached from the product, enqueue them
	 * into the bounded GUID-first prune queue. If the schema is temporarily
	 * unavailable, preserve the legacy pending metadata and let migration retry.
	 *
	 * @param int   $product_id Owner product ID.
	 * @param array $attachment_ids Stale attachment IDs.
	 * @return array
	 */
	private function enqueue_pruned_attachments_for_background_cleanup( $product_id, $attachment_ids ) {
		$product_id     = absint( $product_id );
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		$result = array( 'queued' => 0, 'retained' => 0, 'skipped' => 0 );
		if ( empty( $attachment_ids ) ) {
			$this->set_pending_pruned_cleanup_ids( $product_id, array() );
			return $result;
		}

		if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) && Mobo_Core_Image_Prune_Queue::table_exists() ) {
			$queue  = new Mobo_Core_Image_Prune_Queue();
			$result = $queue->enqueue_attachments( $product_id, $attachment_ids );
			/* Every candidate now has a durable table row, including retained/no-GUID
			 * candidates, so the old per-product retry list can be cleared safely. */
			if ( ( absint( $result['queued'] ) + absint( $result['retained'] ) + absint( $result['skipped'] ) ) >= count( $attachment_ids )
				&& 0 === absint( $result['skipped'] ) ) {
				$this->set_pending_pruned_cleanup_ids( $product_id, array() );
				return $result;
			}
		}

		/* Schema/insert failure: preserve old durable debt, but never block Repair. */
		$this->set_pending_pruned_cleanup_ids( $product_id, $attachment_ids );
		return $result;
	}


	/**
	 * Build the canonical Mobo image identity used for prune decisions.
	 * image_guid/img_guid are stable remote identities; source URL is intentionally
	 * not part of identity because Portal may move/refresh the same image GUID.
	 *
	 * @param string $guid Image GUID.
	 * @param string $url Source URL retained only for signature compatibility.
	 * @return string
	 */
	private function pruned_identity_key( $guid, $url = '' ) {
		unset( $url );
		$guid = trim( sanitize_text_field( (string) $guid ) );
		if ( '' === $guid || ( class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && ! Mobo_Core_Remote_Identity_Policy::is_valid( $guid ) ) ) {
			return '';
		}
		return strtolower( $guid );
	}

	/** @return string */
	private function pruned_attachment_identity_key( $attachment_id ) {
		$primary = (string) get_post_meta( $attachment_id, 'image_guid', true );
		$legacy  = (string) get_post_meta( $attachment_id, 'img_guid', true );
		$primary_key = $this->pruned_identity_key( $primary );
		$legacy_key  = $this->pruned_identity_key( $legacy );
		if ( '' !== $primary_key && '' !== $legacy_key && ! hash_equals( $primary_key, $legacy_key ) ) {
			return '';
		}
		return '' !== $primary_key ? $primary_key : $legacy_key;
	}

	/** @return array<string,bool> */
	private function pruned_desired_identity_keys_from_images( $images ) {
		$keys = array();
		foreach ( is_array( $images ) ? $images : array() as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$guid = class_exists( 'Mobo_Core_Image_Desired_State_Policy' )
				? Mobo_Core_Image_Desired_State_Policy::image_guid( $image )
				: '';
			$identity = $this->pruned_identity_key( $guid );
			if ( '' !== $identity ) {
				$keys[ $identity ] = true;
			}
		}
		return $keys;
	}

	/**
	 * Remove cleanup candidates that are exactly reintroduced in an explicit payload.
	 *
	 * @param array $attachment_ids Candidate IDs.
	 * @param array $images Desired images.
	 * @return array
	 */
	private function filter_pruned_attachment_candidates_against_images( $attachment_ids, $images ) {
		$desired  = $this->pruned_desired_identity_keys_from_images( $images );
		$filtered = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) ) as $attachment_id ) {
			$identity = $this->pruned_attachment_identity_key( $attachment_id );
			if ( '' !== $identity && isset( $desired[ $identity ] ) ) {
				continue;
			}
			$filtered[] = $attachment_id;
		}
		return $filtered;
	}

	/**
	 * Derive stale image candidates from current Woo linkage when the durable image
	 * queue table is unavailable. This fallback is intentionally fail-closed:
	 * attachments without valid image_guid/img_guid ownership are never inferred stale.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Current desired payload.
	 * @return array
	 */
	private function get_direct_pruned_attachment_candidates( $product_id, $images ) {
		$product_id = absint( $product_id );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$current_ids = array_merge(
			array( absint( $product->get_image_id() ) ),
			array_map( 'absint', (array) $product->get_gallery_image_ids() )
		);
		$current_ids = array_values( array_unique( array_filter( $current_ids ) ) );
		$desired     = $this->pruned_desired_identity_keys_from_images( $images );
		$stale       = array();

		foreach ( $current_ids as $attachment_id ) {
			if ( ! $this->is_mobo_owned_attachment( $attachment_id ) ) {
				continue;
			}
			$identity = $this->pruned_attachment_identity_key( $attachment_id );
			if ( '' === $identity ) {
				continue;
			}
			if ( ! isset( $desired[ $identity ] ) ) {
				$stale[] = $attachment_id;
			}
		}

		return array_values( array_unique( $stale ) );
	}

	/**
	 * Remove prune candidates still represented by the current desired GUID identity.
	 * image_guid/img_guid are authoritative; source URL may legitimately change for
	 * the same remote image and therefore is not part of destructive identity.
	 *
	 * @param int                   $product_id Product ID.
	 * @param Mobo_Core_Image_Queue $queue Image queue.
	 * @param array                 $attachment_ids Candidate attachment IDs.
	 * @param array|null            $images Optional current payload.
	 * @return array
	 */
	private function filter_pruned_attachment_candidates( $product_id, Mobo_Core_Image_Queue $queue, $attachment_ids, $images = null ) {
		$product_id     = absint( $product_id );
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		if ( $product_id <= 0 || empty( $attachment_ids ) ) {
			return array();
		}

		$rows             = method_exists( $queue, 'get_ordered_rows_for_product' ) ? $queue->get_ordered_rows_for_product( $product_id ) : array();
		$still_desired_id = array();
		$desired_identity = is_array( $images ) ? $this->pruned_desired_identity_keys_from_images( $images ) : array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$current_attachment_id = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
			if ( $current_attachment_id > 0 ) {
				$still_desired_id[ $current_attachment_id ] = true;
			}
			$identity = $this->pruned_identity_key(
				isset( $row['image_guid'] ) ? $row['image_guid'] : '',
				isset( $row['source_url'] ) ? $row['source_url'] : ''
			);
			if ( '' !== $identity ) {
				$desired_identity[ $identity ] = true;
			}
		}

		$filtered = array();
		foreach ( $attachment_ids as $attachment_id ) {
			if ( isset( $still_desired_id[ $attachment_id ] ) ) {
				continue;
			}
			$identity = $this->pruned_attachment_identity_key( $attachment_id );
			if ( '' !== $identity && isset( $desired_identity[ $identity ] ) ) {
				continue;
			}
			$filtered[] = $attachment_id;
		}

		return array_values( array_unique( $filtered ) );
	}

	/**
	 * UX58: Re-evaluate prune candidates and detach Woo links under the same
	 * Connection/product lease used by image desired-state replacement. A newer
	 * webhook cannot reintroduce an image between the last queue read and the
	 * WooCommerce save. No image download or editor work occurs under this lock.
	 *
	 * @return array{success:bool,ids:array}
	 */
	/** UX58: explicit empty image set is a Woo mutation and needs the same lease. */
	private function commit_explicit_empty_image_desired_state( $product_id, Mobo_Core_Image_Queue $queue ) {
		$product_id = absint( $product_id );
		$lock_name = Mobo_Core_Image_Queue::desired_state_lock_name( $product_id );
		if ( $product_id <= 0 || '' === $lock_name || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return false;
		}
		$token = Mobo_Core_Lock::acquire( $lock_name, 300 );
		if ( false === $token ) { return false; }
		try {
			/* An overlapping newer enqueue supersedes this empty snapshot. */
			if ( ! empty( $queue->get_ordered_rows_for_product( $product_id ) ) ) { return false; }
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) { return false; }
			if ( absint( $product->get_image_id() ) > 0 || ! empty( $product->get_gallery_image_ids() ) ) {
				$product->set_image_id( 0 );
				$product->set_gallery_image_ids( array() );
				if ( absint( $product->save() ) !== $product_id ) { return false; }
			}
			$fresh = wc_get_product( $product_id );
			return $fresh instanceof WC_Product && absint( $fresh->get_image_id() ) === 0 && empty( $fresh->get_gallery_image_ids() );
		} finally {
			Mobo_Core_Lock::release( $lock_name, $token );
		}
	}

	private function unlink_pruned_against_current_desired( $product_id, Mobo_Core_Image_Queue $queue, $attachment_ids ) {
		$lock_name = Mobo_Core_Image_Queue::desired_state_lock_name( $product_id );
		if ( '' === $lock_name || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return array( 'success' => false, 'ids' => array() );
		}
		$lock_token = Mobo_Core_Lock::acquire( $lock_name, 300 );
		if ( false === $lock_token ) {
			return array( 'success' => false, 'ids' => array() );
		}
		try {
			$ids = $this->filter_pruned_attachment_candidates( $product_id, $queue, $attachment_ids );
			$this->set_pending_pruned_cleanup_ids( $product_id, $ids );
			$success = empty( $ids ) || $this->unlink_pruned_attachments_from_product( $product_id, $ids );
			return array( 'success' => $success, 'ids' => $ids );
		} finally {
			Mobo_Core_Lock::release( $lock_name, $lock_token );
		}
	}

	/**
	 * Detach stale attachments from the current product before destructive cleanup.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $attachment_ids Stale attachment IDs.
	 * @return bool
	 */
	private function unlink_pruned_attachments_from_product( $product_id, $attachment_ids ) {
		$product_id     = absint( $product_id );
		$attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		if ( $product_id <= 0 || empty( $attachment_ids ) ) {
			return true;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$changed  = false;
		$image_id = absint( $product->get_image_id() );
		if ( $image_id > 0 && in_array( $image_id, $attachment_ids, true ) ) {
			$product->set_image_id( 0 );
			$changed = true;
		}

		$current_gallery = array_values( array_unique( array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ) ) );
		$desired_gallery = array_values( array_filter( $current_gallery, static function ( $attachment_id ) use ( $attachment_ids ) {
			return ! in_array( absint( $attachment_id ), $attachment_ids, true );
		} ) );
		if ( $desired_gallery !== $current_gallery ) {
			$product->set_gallery_image_ids( $desired_gallery );
			$changed = true;
		}

		if ( ! $changed ) {
			return true;
		}

		if ( absint( $product->save() ) !== $product_id ) {
			return false;
		}

		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product ) {
			return false;
		}
		if ( in_array( absint( $fresh->get_image_id() ), $attachment_ids, true ) ) {
			return false;
		}
		foreach ( (array) $fresh->get_gallery_image_ids() as $gallery_id ) {
			if ( in_array( absint( $gallery_id ), $attachment_ids, true ) ) {
				return false;
			}
		}

		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
		return true;
	}

	/**
	 * Retry cleanup for a product ID after its owner post was permanently deleted.
	 *
	 * @param int $product_id Deleted product ID.
	 * @return array
	 */
	private function retry_pruned_cleanup_for_deleted_owner( $product_id ) {
		$candidates = $this->get_pending_pruned_cleanup_ids( $product_id );
		$cleanup    = $this->enqueue_pruned_attachments_for_background_cleanup( $product_id, $candidates );
		return array(
			'done'               => true,
			'nextOffset'         => 0,
			'processed'          => 0,
			'failed'             => 0,
			'skipped'            => 0,
			'deletedAttachments' => 0,
			'cleanupQueued'      => isset( $cleanup['queued'] ) ? absint( $cleanup['queued'] ) : 0,
			'cleanupRetained'    => isset( $cleanup['retained'] ) ? absint( $cleanup['retained'] ) : 0,
			'error'              => '',
		);
	}

	/**
	 * Only attachments carrying Mobo image identity are eligible for destructive
	 * desired-state cleanup.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_mobo_owned_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$image_guid = trim( sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) ) );
		$img_guid   = trim( sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) ) );
		if ( '' === $image_guid && '' === $img_guid ) {
			return false;
		}
		if ( class_exists( 'Mobo_Core_Remote_Identity_Policy' ) ) {
			if ( ( '' !== $image_guid && ! Mobo_Core_Remote_Identity_Policy::is_valid( $image_guid ) )
				|| ( '' !== $img_guid && ! Mobo_Core_Remote_Identity_Policy::is_valid( $img_guid ) ) ) {
				return false;
			}
		}
		if ( '' !== $image_guid && '' !== $img_guid && ! hash_equals( strtolower( $image_guid ), strtolower( $img_guid ) ) ) {
			return false;
		}

		return true;
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
				if ( ! $this->mark_attachment_synced( $attachment_id, $image_guid, $url ) ) {
					$skipped++;
				}
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
		$excluded  = 0;
		$touched   = array();
		$link_rows = array();
		$paused_for_upgrade = false;
		/* Circuit accounting: only systemic failures may isolate the image stage. */
		$item_failed      = 0;
		$storage_failed   = 0;
		$internal_failed  = 0;
		$host_failed      = array();
		$successful_hosts = array();
		$host_deferred    = 0;
		$host_state       = null;
		$host_state_dirty = false;
		$image_host       = '';

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

		$claimed_ids       = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		$batch_claim_token = ! empty( $rows[0]['_mobo_claim_token'] ) ? sanitize_text_field( (string) $rows[0]['_mobo_claim_token'] ) : '';
		$bulk_claim        = ! empty( $claimed_ids ) && '' !== $batch_claim_token && ! empty( $rows[0]['_mobo_bulk_claimed'] ) && method_exists( $queue, 'release_claimed_images' );

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
				$previous_error = isset( $row['last_error'] ) ? sanitize_text_field( (string) $row['last_error'] ) : '';
				$fresh_reimport_prepared = false;
				$claim_token = isset( $row['_mobo_claim_token'] ) ? sanitize_text_field( (string) $row['_mobo_claim_token'] ) : '';

				if ( $id <= 0 ) {
					$failed++;
					$item_failed++;
					continue;
				}

				if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
					$committed = '' !== $claim_token && method_exists( $queue, 'mark_failure_if_claimed' )
						? $queue->mark_failure_if_claimed( $id, 'Product does not exist.', $try_count, true, $claim_token )
						: false;
					if ( $committed ) {
						$failed++;
						$item_failed++;
					} else {
						$deferred++;
					}
					continue;
				}

				if ( '' === $image_guid || ! $this->is_valid_image_source_url( $url ) ) {
					$committed = '' !== $claim_token && method_exists( $queue, 'mark_failure_if_claimed' )
						? $queue->mark_failure_if_claimed( $id, 'Image GUID or HTTP(S) source URL is invalid.', $try_count, true, $claim_token )
						: false;
					if ( $committed ) {
						$failed++;
						$item_failed++;
					} else {
						$deferred++;
					}
					continue;
				}

				if ( empty( $row['_mobo_bulk_claimed'] ) ) {
					if ( method_exists( $queue, 'lock_with_token' ) ) {
						$claim_token = $queue->lock_with_token( $id, 300 );
						if ( '' === $claim_token ) {
							continue;
						}
					} elseif ( ! $queue->lock( $id, 300 ) ) {
						continue;
					}
				}

				if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
					$excluded_committed = method_exists( $queue, 'mark_excluded_if_current' )
						? $queue->mark_excluded_if_current( $id, $product_id, $image_guid, $url, $claim_token )
						: false;
					if ( $excluded_committed ) {
						$this->delete_product_meta_verified( $product_id, '_mobo_product_images_pending_source_hash' );
						$this->delete_product_meta_verified( $product_id, '_mobo_product_images_pending_source_hash_updated_at' );
						$excluded++;
					} else {
						if ( method_exists( $queue, 'release_if_superseded' ) ) {
							$queue->release_if_superseded( $id, $product_id, $image_guid, $url, $claim_token );
						}
						$deferred++;
					}
					continue;
				}

				/* Per-host breaker: while the image host is isolated, defer rows that need
				 * a download until its cooldown ends without counting an attempt, so the
				 * item's try count/backoff and the other hosts' images are unaffected. */
				$image_host = self::image_source_host( $url );
				if ( null === $host_state ) {
					$host_state = $this->load_host_breaker();
				}
				$host_open_until = $attachment_id <= 0 ? self::host_breaker_open_until( $host_state, $image_host, time() ) : 0;
				if ( $host_open_until > 0 && '' !== $claim_token && method_exists( $queue, 'defer_claimed_until' )
					&& $queue->defer_claimed_until( $id, $host_open_until + ( $id % 30 ), $claim_token ) ) {
					$host_deferred++;
					$deferred++;
					continue;
				}

				if ( method_exists( $queue, 'begin_attempt_if_current' )
					&& ! $queue->begin_attempt_if_current( $id, $try_count, $product_id, $image_guid, $url, $claim_token ) ) {
					if ( method_exists( $queue, 'release_if_superseded' ) ) {
						$queue->release_if_superseded( $id, $product_id, $image_guid, $url, $claim_token );
					}
					$deferred++;
					continue;
				}

				/* If attempts one and two already returned the distinctive readiness
				 * failure, quarantine before invoking the expensive editor a third time.
				 * This also makes a persisted attempt checkpoint useful after a prior
				 * timeout: the next lease can escape the same oversized local original. */
				$quarantine_error = $previous_error;
				if ( $attachment_id > 0 && 3 === $try_count && '' === $quarantine_error
					&& '1' === (string) get_post_meta( $attachment_id, 'mobo_sync_incomplete', true ) ) {
					/* Two requests may have terminated inside the image editor before a
					 * graceful error could be committed. The persisted attempt checkpoint
					 * is sufficient evidence to take the same exact third-attempt escape
					 * hatch, but only for an explicitly incomplete Mobo attachment. */
					$quarantine_error = 'Image file exists but final WebP/subsize validation failed: previous image attempt ended before readiness commit.';
				}
				if ( $attachment_id > 0 && '' !== $quarantine_error ) {
					$fresh_reimport_prepared = $this->maybe_quarantine_incomplete_attachment_for_fresh_reimport(
						$attachment_id,
						$image_guid,
						$url,
						$try_count,
						$quarantine_error
					);
					if ( $fresh_reimport_prepared ) {
						$attachment_id = 0;
					}
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
						? $queue->mark_attaching_if_current( $id, $attachment_id, $product_id, $image_guid, $url, 90, $claim_token )
						: true;
					if ( ! $attaching_committed ) {
						if ( method_exists( $queue, 'release_if_superseded' ) ) {
							$queue->release_if_superseded( $id, $product_id, $image_guid, $url, $claim_token );
						}
						$deferred++;
						continue;
					}
					if ( ! method_exists( $queue, 'mark_attaching_if_current' ) ) {
						$queue->mark_attaching( $id, $attachment_id, 90 );
					}

					if ( ! $this->mark_attachment_synced( $attachment_id, $image_guid, $url ) ) {
						/* Keep the durable row in attaching state. The next due pass can reuse
						 * the already-created attachment and retry only the identity/completion
						 * commit instead of acknowledging an attachment that cannot be found
						 * reliably by GUID/source on a later request. */
						$deferred++;
						continue;
					}
					/* M5: a successful import closes the identity's readiness history;
					 * a later failure (new host problem) starts from a clean budget. */
					if ( class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ) {
						Mobo_Core_Image_Reimport_Ledger::forget( Mobo_Core_Image_Reimport_Ledger::key( $this->ledger_connection_id(), $image_guid, $url ) );
					}
					$touched[ $product_id ] = true;
					if ( ! isset( $link_rows[ $product_id ] ) ) {
						$link_rows[ $product_id ] = array();
					}
					$link_rows[ $product_id ][] = $id;
					$processed++;
					if ( ! $can_reuse_queued_attachment && '' !== $image_host ) {
						$successful_hosts[ $image_host ] = true;
						if ( isset( $host_state[ $image_host ] ) ) {
							$host_state       = self::host_breaker_record( $host_state, $image_host, 'success', '', time() );
							$host_state_dirty = true;
						}
					}
					continue;
				}

				if ( $attachment_id > 0 ) {
					update_post_meta( $attachment_id, 'mobo_sync_incomplete', '1' );
				}

				$message = $this->get_last_image_error();
				if ( '' === $message ) {
					$message = 'Image source is not ready or the download/import failed.';
				}

				/* H7: a readiness failure (file exists, WebP/subsizes never converge) is
				 * counted in a durable per-image ledger that survives queue resets. After
				 * the one bounded fresh reimport (or too many failures) it becomes a
				 * terminal failure instead of an endless retry/duplicate loop. */
				$terminal_readiness = false;
				if ( 0 === strpos( $message, self::READINESS_FAILURE_PREFIX ) && class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ) {
					$ledger_key   = Mobo_Core_Image_Reimport_Ledger::key( $this->ledger_connection_id(), $image_guid, $url );
					$ledger_entry = Mobo_Core_Image_Reimport_Ledger::record_readiness_failure( $ledger_key );
					$terminal_readiness = Mobo_Core_Image_Reimport_Ledger::is_terminal( $ledger_entry, $try_count, self::FRESH_REIMPORT_ATTEMPT );
					if ( $terminal_readiness ) {
						$message = 'Permanent: ' . Mobo_Core_Image_Reimport_Ledger::TERMINAL_MARKER . ' after the bounded fresh reimport; no further attachments are created for this image. ' . $message;
					}
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
				/* An image that can never be decoded within memory_limit is terminal
				 * (bounded re-check after 7 days or with a new source file). */
				if ( ! $terminal_readiness && class_exists( 'Mobo_Core_Image_Integrity' ) && 0 === strpos( $message, Mobo_Core_Image_Integrity::TOO_LARGE_PREFIX ) ) {
					$terminal_readiness = true;
				}
				$failure_committed = method_exists( $queue, 'mark_failure_if_current' )
					? $queue->mark_failure_if_current( $id, $message, $try_count, $product_id, $image_guid, $url, $terminal_readiness, $claim_token )
					: true;
				if ( ! $failure_committed ) {
					/* The row was superseded while this worker was active. */
					if ( method_exists( $queue, 'release_if_superseded' ) ) {
						$queue->release_if_superseded( $id, $product_id, $image_guid, $url, $claim_token );
					}
					$deferred++;
					continue;
				}
				if ( ! method_exists( $queue, 'mark_failure_if_current' ) ) {
					$queue->mark_failure( $id, $message, $try_count, $terminal_readiness );
				}

				/*
				 * A legacy oversized WebP can leave a durable attachment whose original
				 * exists but whose metadata/subsizes can never converge on this host. The
				 * ordinary identity lookup would reuse that same attachment forever, even
				 * after the authoritative source was replaced with a smaller file.
				 *
				 * Quarantine only after the queue failure was conditionally committed for
				 * the same GUID/source. No file or attachment is deleted: the current
				 * product image remains visible until the next retry imports and validates
				 * a fresh attachment. The exact-attempt gate guarantees that a temporary
				 * disk-full condition cannot create an unbounded reimport loop.
				 */
				if ( ! $fresh_reimport_prepared && ! $terminal_readiness ) {
					$this->maybe_quarantine_incomplete_attachment_for_fresh_reimport(
						$attachment_id,
						$image_guid,
						$url,
						$try_count,
						$message
					);
				}

				if ( 0 === strpos( $message, 'Shared-media manifest is not ready or is incompatible.' ) ) {
					$deferred++;
				} else {
					$failed++;
					$failure_class = $terminal_readiness ? 'item' : self::classify_failure_message( $message );
					if ( 'storage' === $failure_class ) {
						$storage_failed++;
					} elseif ( 'host' === $failure_class && '' !== $image_host ) {
						$host_failed[ $image_host ] = ( isset( $host_failed[ $image_host ] ) ? $host_failed[ $image_host ] : 0 ) + 1;
						$host_state       = self::host_breaker_record( $host_state, $image_host, 'host_failure', '' !== $image_guid ? $image_guid : $url, time() );
						$host_state_dirty = true;
					} else {
						$item_failed++;
					}
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
					$internal_failed++;
					if ( class_exists( 'Mobo_Core_Logger' ) ) {
						Mobo_Core_Logger::error( 'Image product linkage failed for product ' . $product_id . ' [' . get_class( $e ) . '].' );
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
					$this->promote_pending_image_hash_if_converged( $product_id, $queue, true );
				}
			}
		} finally {
			if ( $bulk_claim ) {
				$queue->release_claimed_images( $claimed_ids, $batch_claim_token );
			}
			if ( $host_state_dirty && is_array( $host_state ) ) {
				$this->save_host_breaker( $host_state );
			}
		}

		$open_hosts = array();
		foreach ( is_array( $host_state ) ? array_keys( $host_state ) : array() as $state_host ) {
			if ( self::host_breaker_open_until( $host_state, $state_host, time() ) > 0 ) {
				$open_hosts[] = (string) $state_host;
			}
		}

		return array(
			'success'   => true,
			'status'    => $paused_for_upgrade ? 'paused-for-upgrade' : 'processed',
			'processed' => $processed,
			'failed'    => $failed,
			/* Only systemic failures feed the image-stage circuit breaker. */
			'circuitFailed' => self::circuit_failed_count( $storage_failed, $host_failed, $successful_hosts, $internal_failed ),
			'itemFailed'    => $item_failed,
			'hostFailed'    => array_sum( $host_failed ),
			'storageFailed' => $storage_failed,
			'hostDeferred'  => $host_deferred,
			'openHosts'     => array_slice( $open_hosts, 0, 10 ),
			'deferred'  => $deferred,
			'excluded'  => $excluded,
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
	/**
	 * Reason of the last failed import (download guard, storage, sideload) for
	 * callers such as the image refresh service.
	 *
	 * @return string
	 */
	public function last_import_error() {
		return $this->get_last_image_error();
	}

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
	 * @param bool   $force_fresh Bypass reusable attachment lookup for a source refresh.
	 * @param string $refresh_generation Stable workflow generation used for cache busting.
	 * @return int Attachment ID or 0.
	 */
	private function resolve_image_attachment( $url, $product_id, $image_guid, $allow_shared_local_conversion = true, $force_fresh = false, $refresh_generation = '' ) {
		$this->last_image_error = '';
		$url                    = esc_url_raw( (string) $url );
		$product_id             = absint( $product_id );
		$image_guid             = sanitize_text_field( (string) $image_guid );
		$force_fresh            = (bool) $force_fresh;
		$refresh_generation     = sanitize_text_field( (string) $refresh_generation );

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
		if ( ! $shared_mode && ! $force_fresh && $existing_id > 0 ) {
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
					if ( ! $force_fresh && $existing_id > 0 ) {
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

			if ( ! $force_fresh && $existing_id > 0 ) {
				return $existing_id;
			}

			return $this->download_image( $url, $product_id, $image_guid, $force_fresh, $refresh_generation );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && ! empty( $identity_locks ) ) {
				foreach ( array_reverse( $identity_locks, true ) as $lock_name => $lock_token ) {
					Mobo_Core_Lock::release( $lock_name, $lock_token );
				}
			}
		}
	}

	private function download_image( $url, $product_id, $image_guid, $force_fresh = false, $refresh_generation = '' ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$force_fresh = (bool) $force_fresh;
		$refresh_generation = sanitize_text_field( (string) $refresh_generation );

		if ( '' === $url || $product_id <= 0 || '' === $image_guid ) {
			$this->set_last_image_error( 'Image download arguments are invalid.' );
			return 0;
		}

		$existing_id = $force_fresh ? 0 : $this->find_existing_attachment( $image_guid, $url );

		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		/* Do not enter WordPress sideload/metadata generation while the hosting
		 * account cannot safely accept another image family. This turns disk/quota
		 * exhaustion into an ordinary queue backoff instead of partial collision
		 * attachments such as image-10.webp, image-11.webp, ... . */
		if ( class_exists( 'Mobo_Core_Image_Storage' ) ) {
			$storage = Mobo_Core_Image_Storage::check();
			if ( empty( $storage['ready'] ) ) {
				$this->set_last_image_error( 'Image storage is not ready: ' . sanitize_text_field( isset( $storage['message'] ) ? (string) $storage['message'] : 'Insufficient writable storage.' ) );
				return 0;
			}
		}

		$request_url = $url;
		if ( $force_fresh ) {
			$cache_token = '' !== $refresh_generation ? $refresh_generation : (string) time();
			$request_url = esc_url_raw( add_query_arg( 'mobo_refresh', $cache_token, $url ) );
		}

		if ( $this->is_local_or_private_image_url( $url ) ) {
			if ( ! $this->allow_unsafe_local_image_download( $url, $product_id ) ) {
				$this->set_last_image_error( 'WordPress blocked a local/private image URL.' );
				return 0;
			}
			if ( ! $this->is_trusted_unsafe_local_image_source( $url ) ) {
				$this->set_last_image_error( 'Local/private image URL is outside the trusted Mobo image origin policy.' );
				return 0;
			}

			$attachment_id = $this->download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid, $request_url, $force_fresh );
		} else {
			$secure_image_request_args = static function ( $args, $http_url ) use ( $force_fresh ) {
				$args['sslverify'] = (bool) apply_filters( 'mobo_core_http_sslverify', true, 'image_sideload' );
				$args['timeout']   = min( 20, max( 8, isset( $args['timeout'] ) ? absint( $args['timeout'] ) : 15 ) );
				$args['redirection'] = min( 3, isset( $args['redirection'] ) ? absint( $args['redirection'] ) : 3 );
				if ( $force_fresh ) {
					$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
					$args['headers']['Cache-Control'] = 'no-cache, no-store, max-age=0';
					$args['headers']['Pragma']        = 'no-cache';
				}

				return $args;
			};

			$identity_bootstrap = $this->register_attachment_identity_bootstrap( $product_id, $image_guid, $url );

			add_filter( 'http_request_args', $secure_image_request_args, 10, 2 );

			if ( class_exists( 'Mobo_Core_Image_Size_Policy' ) ) {
				Mobo_Core_Image_Size_Policy::begin_sideload();
			}
			$integrity_guard = $this->register_sideload_integrity_guard( true );
			try {
				$attachment_id = media_sideload_image( $request_url, $product_id, null, 'id' );
			} finally {
				$this->unregister_sideload_integrity_guard( $integrity_guard );
				if ( class_exists( 'Mobo_Core_Image_Size_Policy' ) ) {
					Mobo_Core_Image_Size_Policy::end_sideload();
				}
				remove_filter( 'http_request_args', $secure_image_request_args, 10 );
				$this->unregister_attachment_identity_bootstrap( $identity_bootstrap );
			}

			if ( is_wp_error( $attachment_id ) ) {
				$error_message = 'wp_media_' . substr( sanitize_key( (string) $attachment_id->get_error_code() ), 0, 64 );
				$this->set_last_image_error( 'WordPress image sideload failed: ' . $error_message );
				if ( '' !== $this->sideload_integrity_error ) {
					/* Truncated/corrupt download or an image too large to decode:
					 * report the real reason (it drives retry vs terminal). */
					$this->set_last_image_error( $this->sideload_integrity_error );
					return 0;
				}

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					Mobo_Core_Logger::error( 'Mobo Core image sideload failed, trying unsafe-local fallback: ' . $error_message );
				}

				$attachment_id = $this->allow_unsafe_local_image_download( $url, $product_id )
					&& $this->is_local_or_private_image_url( $url )
					&& $this->is_trusted_unsafe_local_image_source( $url )
					? $this->download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid, $request_url, $force_fresh )
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

		/* Disk/quota full while WordPress copied the verified temp file into uploads
		 * or wrote its "-scaled" copy / sub-sizes: the stored bytes are short or
		 * empty although the download was complete. Never link such an attachment;
		 * report it as a storage condition so it is retried and revived once the
		 * storage probe passes, never counted as a defect of the image. */
		$stored_problem = $this->stored_attachment_problem( $attachment_id );
		if ( '' !== $stored_problem ) {
			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
			}
			$this->set_last_image_error(
				( class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ? Mobo_Core_Image_Reimport_Ledger::STORAGE_NOT_READY_PREFIX : 'Image storage is not ready:' )
				. ' stored copy in uploads is incomplete (' . $stored_problem . '); the uploads disk or quota is probably full.'
			);
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
	 * Decide whether Mobo image sync may use the local/private HTTP fallback.
	 *
	 * IMPORTANT: This MUST remain hard-coded TRUE. Do not change it back to false
	 * and do not make it filter-dependent again. Mobo/Portal image origins may
	 * legitimately resolve to local/private destinations; disabling this path can
	 * leave the image queue permanently deferred with zero convergence.
	 *
	 * @param string $url Image URL.
	 * @param int    $product_id Product ID.
	 * @return bool
	 */
	private function allow_unsafe_local_image_download( $url, $product_id ) {
		return true;
	}

	/**
	 * Restrict the hard-coded local/private fallback to Mobo-controlled image origins.
	 *
	 * The compatibility flag above intentionally remains TRUE, but that must not turn
	 * arbitrary queue payload URLs into a general SSRF primitive. Private fallback is
	 * limited to the exact server-configured Mobo API/source origin and the
	 * dedicated /images/ path. Scheme and effective port are part of that origin.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private function is_trusted_unsafe_local_image_source( $url ) {
		$parts = wp_parse_url( esc_url_raw( (string) $url ) );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( 0 !== strpos( $path, '/images/' ) ) {
			return false;
		}

		$api_base = class_exists( 'Mobo_Core_Connection_Repository' )
			? (string) Mobo_Core_Connection_Repository::get_runtime_base_url( '' )
			: '';
		if ( '' === trim( (string) $api_base ) ) {
			$api_base = apply_filters( 'mobo_core_api_base_url', '' );
		}
		if ( ! is_string( $api_base ) || '' === trim( $api_base ) ) {
			$api_base = (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		}
		if ( '' === trim( $api_base ) && defined( 'MOBO_API_BASE_URL' ) ) {
			$api_base = (string) MOBO_API_BASE_URL;
		}

		$source_site = class_exists( 'Mobo_Core_Connection_Repository' )
			? (string) Mobo_Core_Connection_Repository::get_runtime_source_site_url( '' )
			: '';

		/* Unsafe-local transport is allowed only for the exact configured origin.
		 * Host-only matching would let a payload silently switch scheme or probe a
		 * different private service port on the same host. */
		return $this->configured_image_origin_matches( $parts, $api_base )
			|| $this->configured_image_origin_matches( $parts, $source_site );
	}

	/**
	 * Compare a parsed image URL to one server-owned configured HTTP(S) origin.
	 * Paths on the configured base URL are intentionally ignored; image downloads
	 * remain constrained separately to the dedicated /images/ path.
	 *
	 * @param array  $target_parts Parsed target URL.
	 * @param string $configured_url Server-provisioned API/source URL.
	 * @return bool
	 */
	private function configured_image_origin_matches( $target_parts, $configured_url ) {
		$configured_url = trim( (string) $configured_url );
		if ( '' === $configured_url || ! is_array( $target_parts ) ) {
			return false;
		}

		$configured = wp_parse_url( esc_url_raw( $configured_url ) );
		if ( ! is_array( $configured ) || isset( $configured['user'] ) || isset( $configured['pass'] ) ) {
			return false;
		}

		$target_scheme = isset( $target_parts['scheme'] ) ? strtolower( (string) $target_parts['scheme'] ) : '';
		$target_host   = isset( $target_parts['host'] ) ? strtolower( rtrim( (string) $target_parts['host'], '.' ) ) : '';
		$config_scheme = isset( $configured['scheme'] ) ? strtolower( (string) $configured['scheme'] ) : '';
		$config_host   = isset( $configured['host'] ) ? strtolower( rtrim( (string) $configured['host'], '.' ) ) : '';

		if ( ! in_array( $target_scheme, array( 'http', 'https' ), true )
			|| ! in_array( $config_scheme, array( 'http', 'https' ), true )
			|| '' === $target_host
			|| '' === $config_host ) {
			return false;
		}

		$target_port = isset( $target_parts['port'] )
			? absint( $target_parts['port'] )
			: ( 'https' === $target_scheme ? 443 : 80 );
		$config_port = isset( $configured['port'] )
			? absint( $configured['port'] )
			: ( 'https' === $config_scheme ? 443 : 80 );

		return hash_equals( $config_scheme, $target_scheme )
			&& hash_equals( $config_host, $target_host )
			&& $config_port === $target_port;
	}

	/**
	 * Download an image using wp_remote_get with reject_unsafe_urls disabled.
	 *
	 * This fallback is enabled by default for Mobo image sync so configured Portal/Mobo
	 * image origins that resolve to local/private destinations are not rejected solely
	 * by WordPress safe-URL validation. It is only intended for environments where WordPress rejects
	 * localhost/private IP URLs before media_sideload_image() can download them.
	 * It still imports the file via media_handle_sideload(), so WordPress validates
	 * the file type before creating the attachment.
	 *
	 * @param string $url Image URL.
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @return int Attachment ID or 0.
	 */
	private function download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid, $request_url = '', $force_fresh = false ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$request_url = esc_url_raw( (string) $request_url );
		$request_url = '' !== $request_url ? $request_url : $url;
		$force_fresh = (bool) $force_fresh;

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
			$request_url,
			array(
				'timeout'            => 15,
				/* Never follow redirects while safe-URL rejection is disabled. */
				'redirection'        => 0,
				'sslverify'          => (bool) apply_filters( 'mobo_core_http_sslverify', true, 'image_sync' ),
				'reject_unsafe_urls' => ! ( $this->allow_unsafe_local_image_download( $url, $product_id ) && $this->is_trusted_unsafe_local_image_source( $url ) ),
				'stream'             => true,
				'filename'           => $tmp_file,
				'headers'            => array(
					'User-Agent' => 'Mobo Core/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : 'dev' ) . '; ' . home_url( '/' ),
					'Cache-Control' => $force_fresh ? 'no-cache, no-store, max-age=0' : 'max-age=0',
					'Pragma'        => $force_fresh ? 'no-cache' : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp_file );
			$error_message = 'http_' . substr( sanitize_key( (string) $response->get_error_code() ), 0, 64 );
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
				Mobo_Core_Logger::error( 'Mobo Core image fallback download failed with HTTP ' . $code . '.' );
			}

			return 0;
		}

		/* Truncated body with an intact header (dropped connection): compare the
		 * declared Content-Length and the format's end marker before WordPress
		 * creates an attachment and thumbnails from half an image. */
		if ( class_exists( 'Mobo_Core_Image_Integrity' ) ) {
			$encoding = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) ) );
			$declared = ( '' === $encoding || 'identity' === $encoding ) ? wp_remote_retrieve_header( $response, 'content-length' ) : '';
			$problem  = Mobo_Core_Image_Integrity::inspect_file( $tmp_file, $declared );
			if ( '' !== $problem ) {
				wp_delete_file( $tmp_file );
				$this->set_last_image_error( $problem );
				return 0;
			}
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

		if ( class_exists( 'Mobo_Core_Image_Size_Policy' ) ) {
			Mobo_Core_Image_Size_Policy::begin_sideload();
		}
		$integrity_guard = $this->register_sideload_integrity_guard( false );
		try {
			$attachment_id = media_handle_sideload( $file, $product_id );
		} finally {
			$this->unregister_sideload_integrity_guard( $integrity_guard );
			if ( class_exists( 'Mobo_Core_Image_Size_Policy' ) ) {
				Mobo_Core_Image_Size_Policy::end_sideload();
			}
			remove_filter( 'upload_mimes', $allow_webp, 10 );
			$this->unregister_attachment_identity_bootstrap( $identity_bootstrap );
		}

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp_file );
			$error_message = 'wp_media_' . substr( sanitize_key( (string) $attachment_id->get_error_code() ), 0, 64 );
			$this->set_last_image_error( '' !== $this->sideload_integrity_error ? $this->sideload_integrity_error : 'WordPress media import failed: ' . $error_message );

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
	 * Install the per-sideload integrity guard.
	 *
	 * - http_response (download_url() streams into a temp file): a body shorter
	 *   or longer than its Content-Length is turned into a WP_Error, so WordPress
	 *   deletes the temp file instead of importing half an image.
	 * - wp_handle_sideload_prefilter: before WordPress moves the file and builds
	 *   thumbnails, the format end marker and the decode memory budget are
	 *   checked without decoding the bitmap.
	 *
	 * @param bool $watch_http Also verify streamed HTTP bodies.
	 * @return array Registered callbacks.
	 */
	private function register_sideload_integrity_guard( $watch_http ) {
		$this->sideload_integrity_error = '';
		if ( ! class_exists( 'Mobo_Core_Image_Integrity' ) ) {
			return array();
		}
		$guard = array();
		if ( $watch_http ) {
			$guard['http_response'] = function ( $response, $args, $url ) {
				unset( $url );
				if ( is_wp_error( $response ) || ! is_array( $args ) || empty( $args['stream'] ) || empty( $args['filename'] ) ) {
					return $response;
				}
				$code = absint( wp_remote_retrieve_response_code( $response ) );
				if ( $code < 200 || $code >= 300 ) {
					return $response;
				}
				$encoding = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) ) );
				if ( '' !== $encoding && 'identity' !== $encoding ) {
					return $response;
				}
				$declared = wp_remote_retrieve_header( $response, 'content-length' );
				$size     = is_file( (string) $args['filename'] ) ? (int) @filesize( (string) $args['filename'] ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- concurrent removal tolerated.
				if ( ! Mobo_Core_Image_Integrity::length_matches( $declared, $size ) ) {
					$this->sideload_integrity_error = Mobo_Core_Image_Integrity::TRUNCATED_PREFIX . ' content-length-mismatch (' . absint( is_array( $declared ) ? end( $declared ) : $declared ) . ' declared, ' . $size . ' received)';
					return new WP_Error( 'mobo_image_truncated', $this->sideload_integrity_error );
				}
				return $response;
			};
			add_filter( 'http_response', $guard['http_response'], 10, 3 );
		}
		$guard['wp_handle_sideload_prefilter'] = function ( $file ) {
			if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
				return $file;
			}
			$problem = Mobo_Core_Image_Integrity::inspect_file( (string) $file['tmp_name'], '' );
			if ( '' !== $problem ) {
				if ( 0 === strpos( $problem, Mobo_Core_Image_Integrity::TOO_LARGE_PREFIX ) && class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ) {
					/* Re-enqueue must not download it again on every product sync;
					 * revived by a new source file or the bounded 7-day re-check. */
					$problem .= ' [' . Mobo_Core_Image_Reimport_Ledger::TERMINAL_MARKER . ']';
				}
				$this->sideload_integrity_error = $problem;
				$file['error'] = $problem;
			}
			return $file;
		};
		add_filter( 'wp_handle_sideload_prefilter', $guard['wp_handle_sideload_prefilter'], 5, 1 );
		return $guard;
	}

	/**
	 * @param array $guard Callbacks from register_sideload_integrity_guard().
	 * @return void
	 */
	private function unregister_sideload_integrity_guard( $guard ) {
		foreach ( is_array( $guard ) ? $guard : array() as $hook => $callback ) {
			remove_filter( $hook, $callback, 'http_response' === $hook ? 10 : 5 );
		}
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
	/**
	 * Structural problem of a freshly sideloaded attachment's STORED files
	 * (attached file + original_image + every generated size in metadata).
	 *
	 * @param int $attachment_id Attachment.
	 * @return string '' when every stored file is complete, else "<file>:<reason>".
	 */
	private function stored_attachment_problem( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || ! class_exists( 'Mobo_Core_Image_Integrity' ) || ! method_exists( 'Mobo_Core_Image_Integrity', 'stored_structure_problem' ) ) {
			return '';
		}
		$file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id, true ) : '';
		if ( '' === $file ) {
			return '';
		}
		$paths = array( $file );
		$meta  = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id, true ) : array();
		if ( is_array( $meta ) ) {
			$dir = dirname( $file );
			if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
				$paths[] = $dir . '/' . wp_basename( $meta['original_image'] );
			}
			foreach ( ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$paths[] = $dir . '/' . wp_basename( $size['file'] );
				}
			}
		}
		foreach ( array_unique( $paths ) as $path ) {
			$problem = Mobo_Core_Image_Integrity::stored_structure_problem( $path );
			/* A missing local file is not a short write: media-offload/CDN plugins
			 * delete local copies on purpose. Only bytes that exist and are empty
			 * or structurally cut off prove a failed write. */
			if ( '' !== $problem && 'missing' !== $problem && 'unreadable' !== $problem ) {
				return sanitize_file_name( wp_basename( $path ) ) . ':' . $problem;
			}
		}
		return '';
	}

	private function is_local_or_private_image_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : Mobo_Core_Environment::resolve_host( $host );

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
		$api_base   = class_exists( 'Mobo_Core_Connection_Repository' )
			? (string) Mobo_Core_Connection_Repository::get_runtime_base_url( Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' ) )
			: (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' );
		$api_host   = strtolower( (string) wp_parse_url( $api_base, PHP_URL_HOST ) );

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
	 * @param bool   $force_fresh Force a distinct local sideload.
	 * @param string $refresh_generation Workflow generation token.
	 * @return int Attachment ID or 0.
	 */
	public function import_image_for_refresh( $url, $product_id, $image_guid, $old_attachment_id = 0, $force_fresh = false, $refresh_generation = '' ) {
		$this->load_media_dependencies();

		if ( class_exists( 'Mobo_Core_Shared_Media' )
			&& ( method_exists( 'Mobo_Core_Shared_Media', 'is_configured' ) ? Mobo_Core_Shared_Media::is_configured() : Mobo_Core_Shared_Media::is_enabled() ) ) {
			/*
			 * Route Shared Media refresh through the same GUID/source identity lock as
			 * normal imports, but keep the refresh invariant that the old local
			 * attachment is never converted in place.
			 */
			$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid, false, false, '' );
		} else {
			$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid, true, (bool) $force_fresh, $refresh_generation );
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
			$readiness_message = sanitize_text_field( isset( $result['message'] ) ? (string) $result['message'] : 'Unknown readiness error.' );
			/* M5: a full disk / exhausted quota while WordPress writes subsizes is a
			 * property of the host at this moment, not of the image. Report it with
			 * the storage prefix so it is never counted in the durable readiness
			 * ledger, never triggers a fresh reimport and never becomes terminal.
			 * A generic editor failure is re-probed: if storage is not ready right
			 * after the failure, the write most likely failed for lack of space. */
			if ( is_array( $result ) && isset( $result['code'] ) && 'too_large' === (string) $result['code'] && class_exists( 'Mobo_Core_Image_Integrity' ) ) {
				/* Terminal like a too-large download (bounded 7-day re-check). */
				$message = 0 === strpos( $readiness_message, Mobo_Core_Image_Integrity::TOO_LARGE_PREFIX ) ? $readiness_message : Mobo_Core_Image_Integrity::TOO_LARGE_PREFIX . ' ' . $readiness_message;
				$this->set_last_image_error( $message . ( class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ? ' [' . Mobo_Core_Image_Reimport_Ledger::TERMINAL_MARKER . ']' : '' ) );
				return false;
			}
			if ( $this->is_storage_transient_readiness_failure( $result, $readiness_message ) ) {
				$this->set_last_image_error( Mobo_Core_Image_Reimport_Ledger::STORAGE_NOT_READY_PREFIX . ' ' . $readiness_message );
				return false;
			}
			$this->set_last_image_error( 'Image file exists but final WebP/subsize validation failed: ' . $readiness_message );
			return false;
		}

		$this->attachment_readiness_cache[ $cache_key ] = true;
		return true;
	}

	/**
	 * Whether a failed readiness result was caused by temporary storage/quota
	 * exhaustion (M5).
	 *
	 * @param array  $result Readiness result.
	 * @param string $message Sanitized message.
	 * @return bool
	 */
	private function is_storage_transient_readiness_failure( $result, $message ) {
		if ( ! class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ) {
			return false;
		}
		if ( is_array( $result ) && isset( $result['code'] ) && 'storage_not_ready' === (string) $result['code'] ) {
			return true;
		}
		if ( Mobo_Core_Image_Reimport_Ledger::is_storage_transient_message( $message ) ) {
			return true;
		}
		if ( class_exists( 'Mobo_Core_Image_Storage' ) ) {
			$storage = Mobo_Core_Image_Storage::check();
			return empty( $storage['ready'] );
		}
		return false;
	}

	private function mark_attachment_synced( $attachment_id, $image_guid, $url ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		$url           = esc_url_raw( (string) $url );

		if ( $attachment_id <= 0 ) {
			return false;
		}

		/* Attachment identity is correctness state: later retries use these keys to
		 * reuse the physical media object instead of creating duplicate -1/-2 files.
		 * Never let the queue become done until each identity key and the incomplete
		 * marker can be read back exactly from WordPress metadata. */
		if ( '' !== $image_guid ) {
			if ( ! $this->persist_attachment_meta_verified( $attachment_id, 'image_guid', $image_guid )
				|| ! $this->persist_attachment_meta_verified( $attachment_id, 'img_guid', $image_guid ) ) {
				return false;
			}
		}

		if ( '' !== $url && ! $this->persist_attachment_meta_verified( $attachment_id, 'mobo_source_url', $url ) ) {
			return false;
		}

		if ( ! $this->persist_attachment_meta_verified( $attachment_id, 'mobo_sync_incomplete', '0' ) ) {
			return false;
		}

		/* Seed request-local lookup caches only after the durable postcondition passed. */
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
		return true;
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
			return false;
		}

		$current = get_post_meta( $attachment_id, $meta_key, true );
		if ( (string) $current === (string) $value ) {
			return false;
		}

		return false !== update_post_meta( $attachment_id, $meta_key, $value );
	}

	/** Persist correctness-critical attachment metadata and verify exact read-back. */
	private function persist_attachment_meta_verified( $attachment_id, $meta_key, $value ) {
		return Mobo_Core_Durable_State_Policy::update_post_meta_verified( $attachment_id, $meta_key, $value );
	}

	/**
	 * Promote the exact desired image hash accepted by Product Sync only after the
	 * durable queue and WooCommerce linkage have converged. Keeping a separate
	 * pending hash closes the gap where asynchronous image work completed after the
	 * ProductUpdated event had already been acknowledged.
	 *
	 * @param int                   $product_id Product ID.
	 * @param Mobo_Core_Image_Queue $queue Queue service.
	 * @param bool                  $link_already_verified Whether this request just linked the product successfully.
	 * @return bool
	 */
	private function promote_pending_image_hash_if_converged( $product_id, Mobo_Core_Image_Queue $queue, $link_already_verified = false ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return false;
		}

		$pending_hash = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_images_pending_source_hash', true ) );
		if ( '' === $pending_hash ) {
			return false;
		}

		$rows = $queue->get_ordered_rows_for_product( $product_id );
		if ( empty( $rows ) ) {
			return false;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row )
				|| 'done' !== sanitize_key( (string) ( isset( $row['status'] ) ? $row['status'] : '' ) )
				|| absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 ) <= 0 ) {
				return false;
			}
		}

		if ( ! $link_already_verified && ! $this->sync_woocommerce_product_image_objects_from_queue( $product_id, $queue ) ) {
			return false;
		}

		if ( ! $this->persist_product_meta_verified( $product_id, '_mobo_product_images_source_hash', $pending_hash ) ) {
			return false;
		}
		if ( ! $this->persist_product_meta_verified( $product_id, '_mobo_product_images_source_hash_updated_at', gmdate( 'c' ) ) ) {
			return false;
		}
		if ( ! $this->delete_product_meta_verified( $product_id, '_mobo_product_images_pending_source_hash' ) ) {
			return false;
		}
		return $this->delete_product_meta_verified( $product_id, '_mobo_product_images_pending_source_hash_updated_at' );
	}

	/**
	 * Bounded crash-recovery sweep for products whose queue finished after the
	 * originating ProductUpdated request ended. The meta_key index keeps this
	 * inexpensive and avoids a full catalog scan.
	 *
	 * @return int Number of hashes promoted.
	 */
	private function promote_pending_image_hashes( Mobo_Core_Image_Queue $queue, $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, min( 20, absint( $limit ) ) );
		if ( class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) && Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative() ) {
			$table = Mobo_Core_Storage_Entity_Meta_Retirement::table_name();
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT woo_object_id FROM {$table} WHERE meta_key=%s AND COALESCE(meta_value,'')<>'' ORDER BY woo_object_id ASC LIMIT %d",
					'_mobo_product_images_pending_source_hash',
					$limit
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id ASC LIMIT %d",
					'_mobo_product_images_pending_source_hash',
					$limit
				)
			);
		}
		$promoted = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $product_id ) {
			if ( $this->promote_pending_image_hash_if_converged( absint( $product_id ), $queue, false ) ) {
				$promoted++;
			}
		}
		return $promoted;
	}

	private function persist_product_meta_verified( $product_id, $key, $value ) {
		return Mobo_Core_Durable_State_Policy::update_post_meta_verified( $product_id, $key, $value );
	}

	private function delete_product_meta_verified( $product_id, $key ) {
		$product_id = absint( $product_id );
		$key        = sanitize_key( (string) $key );
		if ( $product_id <= 0 || '' === $key ) {
			return false;
		}
		if ( metadata_exists( 'post', $product_id, $key ) ) {
			delete_post_meta( $product_id, $key );
		}
		wp_cache_delete( $product_id, 'post_meta' );
		return ! metadata_exists( 'post', $product_id, $key );
	}

	private function sync_woocommerce_product_image_objects_from_queue( $product_id, Mobo_Core_Image_Queue $queue ) {
		$lock_name = Mobo_Core_Image_Queue::desired_state_lock_name( $product_id );
		if ( '' === $lock_name || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return false;
		}
		$lock_token = Mobo_Core_Lock::acquire( $lock_name, 300 );
		if ( false === $lock_token ) {
			/* The new desired set may be replacing/pruning queue rows. Leave rows in
			 * attaching state; the next pass retries rather than saving an old gallery. */
			return false;
		}
		try {
			return $this->sync_woocommerce_product_image_objects_from_queue_locked( $product_id, $queue );
		} finally {
			Mobo_Core_Lock::release( $lock_name, $lock_token );
		}
	}

	/** Build and save gallery while the same desired-state lease as enqueue is held. */
	private function sync_woocommerce_product_image_objects_from_queue_locked( $product_id, Mobo_Core_Image_Queue $queue ) {
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

	/**
	 * Connection owning the reimport ledger entry.
	 *
	 * @return int
	 */
	private function ledger_connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/**
	 * Retire one repeatedly-unready local attachment from Mobo identity lookup.
	 *
	 * Values are moved to private quarantine meta instead of deleted so the old
	 * attachment remains auditable and reversible. Product/gallery references are
	 * deliberately untouched until a fresh attachment passes the final readiness
	 * gate and the normal queue commit replaces them.
	 *
	 * @param int    $attachment_id Attachment ID returned by identity resolution.
	 * @param string $image_guid Current authoritative image GUID.
	 * @param string $url Current authoritative source URL.
	 * @param int    $try_count Queue attempt being committed.
	 * @param string $message Readiness failure message.
	 * @return bool Whether at least one live identity marker was quarantined.
	 */
	private function maybe_quarantine_incomplete_attachment_for_fresh_reimport( $attachment_id, $image_guid, $url, $try_count, $message ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		$url           = esc_url_raw( (string) $url );
		$message       = sanitize_text_field( (string) $message );

		if (
			self::FRESH_REIMPORT_ATTEMPT !== absint( $try_count )
			|| $attachment_id <= 0
			|| '' === $image_guid
			|| '' === $url
			|| 0 !== strpos( $message, 'Image file exists but final WebP/subsize validation failed:' )
			|| 'attachment' !== get_post_type( $attachment_id )
			|| '1' !== (string) get_post_meta( $attachment_id, 'mobo_sync_incomplete', true )
		) {
			return false;
		}

		/* Shared Media readiness is controlled by its committed manifest. Removing
		 * local identity markers there would bypass the single-writer contract. */
		if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			return false;
		}

		/* H7: at most MAX_FRESH_REIMPORTS new attachments per image identity, no
		 * matter how often the queue row is re-enqueued and its try_count reset. */
		$ledger_key = '';
		if ( class_exists( 'Mobo_Core_Image_Reimport_Ledger' ) ) {
			$ledger_key = Mobo_Core_Image_Reimport_Ledger::key( $this->ledger_connection_id(), $image_guid, $url );
			if ( ! Mobo_Core_Image_Reimport_Ledger::may_fresh_reimport( Mobo_Core_Image_Reimport_Ledger::get( $ledger_key ) ) ) {
				return false;
			}
		}

		$stored_image_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
		$stored_legacy_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
		$stored_source_url  = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
		$has_stored_guid    = '' !== $stored_image_guid || '' !== $stored_legacy_guid;
		$guid_matches       = ( '' !== $stored_image_guid && hash_equals( $stored_image_guid, $image_guid ) )
			|| ( '' !== $stored_legacy_guid && hash_equals( $stored_legacy_guid, $image_guid ) );
		$source_matches     = '' !== $stored_source_url && hash_equals( $stored_source_url, $url );

		/* An attachment owned by a different GUID is never quarantined merely
		 * because two remote records happened to share one source URL. */
		if ( ( $has_stored_guid && ! $guid_matches ) || ( ! $has_stored_guid && ! $source_matches ) ) {
			return false;
		}

		$moved = false;
		if ( '' !== $stored_image_guid && hash_equals( $stored_image_guid, $image_guid ) ) {
			update_post_meta( $attachment_id, '_mobo_quarantined_image_guid', $stored_image_guid );
			$moved = delete_post_meta( $attachment_id, 'image_guid', $stored_image_guid ) || $moved;
		}
		if ( '' !== $stored_legacy_guid && hash_equals( $stored_legacy_guid, $image_guid ) ) {
			update_post_meta( $attachment_id, '_mobo_quarantined_img_guid', $stored_legacy_guid );
			$moved = delete_post_meta( $attachment_id, 'img_guid', $stored_legacy_guid ) || $moved;
		}
		if ( $source_matches ) {
			update_post_meta( $attachment_id, '_mobo_quarantined_source_url', $stored_source_url );
			$moved = delete_post_meta( $attachment_id, 'mobo_source_url', $stored_source_url ) || $moved;
		}

		if ( ! $moved ) {
			return false;
		}

		update_post_meta( $attachment_id, '_mobo_quarantine_reason', 'fresh-reimport-after-readiness-failure' );
		update_post_meta( $attachment_id, '_mobo_quarantined_at', time() );
		if ( '' !== $ledger_key ) {
			Mobo_Core_Image_Reimport_Ledger::record_fresh_reimport( $ledger_key );
		}
		$this->forget_attachment_lookup_caches( $image_guid, $url );
		clean_post_cache( $attachment_id );

		if ( class_exists( 'Mobo_Core_Logger' ) ) {
			Mobo_Core_Logger::warning(
				'Mobo Core quarantined an incomplete image identity for one bounded fresh source reimport.',
				array(
					'attachment_id' => $attachment_id,
					'image_guid'    => $image_guid,
					'try_count'     => absint( $try_count ),
				)
			);
		}

		return true;
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

		/* A queue row can still carry the old attachment_id after its live Mobo
		 * identity has been quarantined. Treat the marker as an explicit reuse veto;
		 * otherwise the "unclaimed" compatibility path below would select the same
		 * incomplete file again and the fresh-import escape hatch would be ineffective. */
		if ( 'fresh-reimport-after-readiness-failure' === (string) get_post_meta( $attachment_id, '_mobo_quarantine_reason', true ) ) {
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

		/* Product identity is terminal Custom Storage after 10.33.95. Always use
		 * the metadata API here so the identity retirement filter remains authoritative;
		 * WC_Product::get_meta() reads raw wp_postmeta and cannot see the typed row. */
		return sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
	}

	private function get_image_guid( $image ) {
		return Mobo_Core_Image_Desired_State_Policy::image_guid( $image );
	}

	private function get_image_url( $image ) {
		return Mobo_Core_Image_Desired_State_Policy::image_url( $image );
	}


	/**
	 * Check whether a value is usable as a remote GUID.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_remote_guid_value( $value ) {
	return Mobo_Core_Remote_Identity_Policy::is_valid( $value );
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
