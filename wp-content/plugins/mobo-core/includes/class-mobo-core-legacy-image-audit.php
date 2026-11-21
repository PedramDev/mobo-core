<?php
/**
 * READ-ONLY scanner for legacy Mobo JPG/PNG attachments.
 *
 * Walks image attachments in primary-key order inside a per-request time
 * budget, classifies every JPG/PNG attachment with Mobo_Core_Legacy_Image_Policy
 * and stores counts, estimated disk usage and a bounded sample list in one
 * option. It never changes posts, metadata, files or plugin queues: the only
 * write is its own state option (cursor + counters) and the Stop flag.
 *
 * Crash safety: one batch is computed in memory and committed together with
 * the cursor in a single update_option(). If PHP or the database dies mid-batch
 * the batch is simply recomputed on the next run; counters are never added
 * twice and the cursor never moves past unclassified attachments.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Bounded read-only SELECTs over core and Mobo tables. Identifier lists are
 * absint()-sanitized integers; every string value is prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
class Mobo_Core_Legacy_Image_Audit {

	const STATE_OPTION   = 'mobo_core_legacy_image_audit_state';
	const CONTENT_OPTION = 'mobo_core_legacy_image_audit_content_refs';
	const CONTENT_SPAN   = 2000;
	const CONTENT_MAX    = 60000;
	const STOP_OPTION    = 'mobo_core_legacy_image_audit_stop';
	const DELETED_OPTION = 'mobo_core_legacy_image_cleanup_deleted_total';
	const LOCK_NAME      = 'legacy_image_audit';
	const SAMPLE_LIMIT   = 30;
	const BATCH_DEFAULT  = 40;
	const BATCH_MAX      = 100;

	/** Product identity keys used by legacy (pre custom storage) Mobo products. */
	const PRODUCT_IDENTITY_KEYS = array( 'product_guid', 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId', 'mobo_url' );

	/** Popular variation-gallery plugin keys; any hit is treated as a foreign reference. */
	const EXTRA_GALLERY_KEYS = array( 'woo_variation_gallery_images', '_wc_additional_variation_images', 'rtwpvg_images', '_product_image_gallery_variation' );

	/** @var array<int,bool> Request cache: product id => is Mobo product. */
	private $mobo_product_cache = array();

	/**
	 * Default/empty state.
	 *
	 * @return array
	 */
	public static function empty_state() {
		$classes = array();
		foreach ( Mobo_Core_Legacy_Image_Policy::classes() as $class ) {
			$classes[ $class ] = array( 'count' => 0, 'bytes' => 0, 'metaBytes' => 0, 'files' => 0, 'missingFiles' => 0 );
		}
		return array(
			'version'        => 1,
			'status'         => 'idle',
			'phase'          => 'content',
			'contentCursor'  => 0,
			'contentMaxId'   => 0,
			'contentRefs'    => 0,
			'contentOverflow'=> false,
			'cycleId'        => '',
			'cursor'         => 0,
			'scanned'        => 0,
			'estimatedTotal' => 0,
			'batches'        => 0,
			'startedAt'      => 0,
			'updatedAt'      => 0,
			'completedAt'    => 0,
			'lastRunAt'      => 0,
			'lastError'      => '',
			'classes'        => $classes,
			'samples'        => array(),
		);
	}

	/**
	 * Current normalized state.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$base  = self::empty_state();
		$state = array_merge( $base, $state );
		$state['classes'] = array_merge( $base['classes'], is_array( $state['classes'] ) ? $state['classes'] : array() );
		$state['samples'] = is_array( $state['samples'] ) ? array_slice( $state['samples'], 0, self::SAMPLE_LIMIT ) : array();
		return $state;
	}

	/**
	 * Compact numbers for the health report / Backend support.
	 *
	 * @return array
	 */
	public static function health_summary() {
		$state      = self::get_state();
		$candidates = 0;
		$bytes      = 0;
		$meta_bytes = 0;
		$protected  = 0;
		$by_class   = array();
		foreach ( $state['classes'] as $class => $row ) {
			$count = absint( isset( $row['count'] ) ? $row['count'] : 0 );
			$by_class[ $class ] = $count;
			if ( Mobo_Core_Legacy_Image_Policy::is_candidate_class( $class ) ) {
				$candidates += $count;
				$bytes      += absint( isset( $row['bytes'] ) ? $row['bytes'] : 0 );
				$meta_bytes += absint( isset( $row['metaBytes'] ) ? $row['metaBytes'] : 0 );
			} elseif ( 0 === strpos( (string) $class, 'protected_' ) || Mobo_Core_Legacy_Image_Policy::CLASS_IN_USE_NO_REPLACEMENT === $class ) {
				$protected += $count;
			}
		}
		return array(
			'scanStatus'       => sanitize_key( (string) $state['status'] ),
			'scanComplete'     => 'complete' === $state['status'],
			'scanned'          => absint( $state['scanned'] ),
			'estimatedTotal'   => absint( $state['estimatedTotal'] ),
			'candidates'       => $candidates,
			'replaceThenDelete'=> absint( isset( $by_class[ Mobo_Core_Legacy_Image_Policy::CLASS_REPLACE_THEN_DELETE ] ) ? $by_class[ Mobo_Core_Legacy_Image_Policy::CLASS_REPLACE_THEN_DELETE ] : 0 ),
			'deleteCandidates' => absint( isset( $by_class[ Mobo_Core_Legacy_Image_Policy::CLASS_DELETE_CANDIDATE ] ) ? $by_class[ Mobo_Core_Legacy_Image_Policy::CLASS_DELETE_CANDIDATE ] : 0 ),
			'protected'        => $protected,
			'bytes'            => $bytes,
			'metadataBytes'    => $meta_bytes,
			'deleted'          => absint( get_option( self::DELETED_OPTION, 0 ) ),
			'deletionEnabled'  => false,
			'lastRunAt'        => absint( $state['lastRunAt'] ),
			'completedAt'      => absint( $state['completedAt'] ),
			'classes'          => $by_class,
		);
	}

	/**
	 * Request a cooperative stop. The running slice stops before its next batch.
	 *
	 * @return array
	 */
	public function request_stop() {
		update_option( self::STOP_OPTION, '1', false );
		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			$state['status']    = 'stopped';
			$state['updatedAt'] = time();
			update_option( self::STATE_OPTION, $state, false );
		}
		return $state;
	}

	/**
	 * Forget all scan results (no media is touched).
	 *
	 * @return array
	 */
	public function reset() {
		delete_option( self::STOP_OPTION );
		delete_option( self::CONTENT_OPTION );
		update_option( self::STATE_OPTION, self::empty_state(), false );
		return self::get_state();
	}

	/**
	 * Start a new scan cycle or continue the current one for a bounded time.
	 *
	 * @param float $time_budget Seconds.
	 * @param int   $batch_size Attachments per batch.
	 * @return array State after the slice.
	 */
	public function run( $time_budget = 8.0, $batch_size = self::BATCH_DEFAULT ) {
		global $wpdb;
		$time_budget = max( 1.0, min( 25.0, (float) $time_budget ) );
		$batch_size  = max( 5, min( self::BATCH_MAX, absint( $batch_size ) ) );

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( self::LOCK_NAME, (int) ceil( $time_budget ) + 60 ) : '__no_lock__';
		if ( false === $lock ) {
			$state              = self::get_state();
			$state['lastError'] = 'یک اسکن دیگر هم‌اکنون در حال اجراست.';
			return $state;
		}

		try {
			/* An explicit run clears an earlier Stop. */
			delete_option( self::STOP_OPTION );
			$state = self::get_state();
			if ( in_array( $state['status'], array( 'idle', 'complete' ), true ) ) {
				$state              = self::empty_state();
				$state['status']    = 'running';
				$state['cycleId']   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'legacy', true );
				$state['startedAt'] = time();
				$state['estimatedTotal'] = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" ) );
				$state['contentMaxId']   = absint( $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) );
				update_option( self::CONTENT_OPTION, array( 'b' => array(), 'i' => array() ), false );
			}
			$state['status']    = 'running';
			$state['lastError'] = '';

			$deadline = microtime( true ) + $time_budget;
			$guard    = 0;
			while ( microtime( true ) < $deadline && $guard < 500 ) {
				$guard++;
				if ( $this->stop_requested() ) {
					$state['status'] = 'stopped';
					break;
				}
				if ( 'content' === $state['phase'] ) {
					/* Phase 1: one bounded pass over post content collecting every
					 * referenced upload basename / attachment id into a compact set. */
					$refs = get_option( self::CONTENT_OPTION, array() );
					$refs = is_array( $refs ) ? $refs : array();
					$page = $this->content_page( absint( $state['contentCursor'] ), absint( $state['contentMaxId'] ), $refs );
					if ( ! empty( $page['error'] ) ) {
						$state['lastError'] = sanitize_text_field( (string) $page['error'] );
						break;
					}
					/* Union semantics: re-processing a page after a crash is harmless. */
					update_option( self::CONTENT_OPTION, $page['refs'], false );
					$state['contentCursor']   = absint( $page['cursorEnd'] );
					$state['contentRefs']     = count( $page['refs']['b'] ) + count( $page['refs']['i'] );
					$state['contentOverflow'] = ! empty( $state['contentOverflow'] ) || ! empty( $page['overflow'] );
					if ( ! empty( $page['done'] ) ) {
						$state['phase'] = 'attachments';
					}
					$state['updatedAt'] = time();
					update_option( self::STATE_OPTION, $state, false );
					continue;
				}
				$content_refs = get_option( self::CONTENT_OPTION, array() );
				$batch = $this->scan_batch( absint( $state['cursor'] ), $batch_size, is_array( $content_refs ) ? $content_refs : array(), ! empty( $state['contentOverflow'] ) );
				if ( ! empty( $batch['error'] ) ) {
					/* Uncertain database state: do not advance; keep the cycle resumable. */
					$state['lastError'] = sanitize_text_field( (string) $batch['error'] );
					break;
				}
				$state = $this->merge_batch( $state, $batch );
				$state['updatedAt'] = time();
				$state['lastRunAt'] = time();
				if ( ! empty( $batch['done'] ) ) {
					$state['status']      = 'complete';
					$state['completedAt'] = time();
				}
				/* Commit cursor + counters atomically after every batch. */
				update_option( self::STATE_OPTION, $state, false );
				if ( 'complete' === $state['status'] ) {
					break;
				}
			}
			$state['lastRunAt'] = time();
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && '__no_lock__' !== $lock ) {
				Mobo_Core_Lock::release( self::LOCK_NAME, $lock );
			}
		}
	}

	/**
	 * Add one computed batch to the state (pure).
	 *
	 * @param array $state State.
	 * @param array $batch Batch result.
	 * @return array
	 */
	public function merge_batch( array $state, array $batch ) {
		$state['cursor']   = max( absint( $state['cursor'] ), absint( isset( $batch['cursorEnd'] ) ? $batch['cursorEnd'] : 0 ) );
		$state['scanned']  = absint( $state['scanned'] ) + absint( isset( $batch['scanned'] ) ? $batch['scanned'] : 0 );
		$state['batches']  = absint( $state['batches'] ) + 1;
		foreach ( isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : array() as $item ) {
			$class = isset( $item['class'] ) ? (string) $item['class'] : '';
			if ( ! isset( $state['classes'][ $class ] ) ) {
				continue;
			}
			$state['classes'][ $class ]['count']++;
			$state['classes'][ $class ]['bytes']        += absint( isset( $item['bytes'] ) ? $item['bytes'] : 0 );
			$state['classes'][ $class ]['metaBytes']    += absint( isset( $item['metaBytes'] ) ? $item['metaBytes'] : 0 );
			$state['classes'][ $class ]['files']        += absint( isset( $item['files'] ) ? $item['files'] : 0 );
			$state['classes'][ $class ]['missingFiles'] += absint( isset( $item['missing'] ) ? $item['missing'] : 0 );
			$sampleable = Mobo_Core_Legacy_Image_Policy::is_candidate_class( $class ) || Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_UNCERTAIN === $class;
			if ( $sampleable && count( $state['samples'] ) < self::SAMPLE_LIMIT ) {
				$state['samples'][] = array(
					'id'        => absint( isset( $item['id'] ) ? $item['id'] : 0 ),
					'class'     => $class,
					'reason'    => sanitize_key( (string) ( isset( $item['reason'] ) ? $item['reason'] : '' ) ),
					'file'      => sanitize_text_field( (string) ( isset( $item['file'] ) ? $item['file'] : '' ) ),
					'bytes'     => absint( isset( $item['bytes'] ) ? $item['bytes'] : 0 ),
					'metaBytes' => absint( isset( $item['metaBytes'] ) ? $item['metaBytes'] : 0 ),
					'files'     => absint( isset( $item['files'] ) ? $item['files'] : 0 ),
					'missing'   => absint( isset( $item['missing'] ) ? $item['missing'] : 0 ),
				);
			}
		}
		return $state;
	}

	/**
	 * Fresh read of the Stop flag (bypasses the request-local option cache).
	 *
	 * @return bool
	 */
	private function stop_requested() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::STOP_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return '1' === (string) get_option( self::STOP_OPTION, '0' );
	}

	/**
	 * Classify one primary-key batch of JPG/PNG attachments (read-only).
	 *
	 * @param int $cursor Last processed attachment ID.
	 * @param int $limit Batch size.
	 * @return array{items:array,scanned:int,cursorEnd:int,done:bool,error:string}
	 */
	public function scan_batch( $cursor, $limit, array $content_refs = array(), $content_overflow = false ) {
		global $wpdb;
		$out  = array( 'items' => array(), 'scanned' => 0, 'cursorEnd' => absint( $cursor ), 'done' => false, 'error' => '' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_mime_type, post_parent FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND ID > %d AND post_mime_type IN ('image/jpeg','image/png')
				ORDER BY ID ASC LIMIT %d",
				absint( $cursor ),
				absint( $limit )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			$out['error'] = 'attachment-query-failed';
			return $out;
		}
		if ( empty( $rows ) ) {
			$out['done'] = true;
			return $out;
		}

		$ids    = array();
		$mime   = array();
		$parent = array();
		foreach ( $rows as $row ) {
			$id = absint( $row['ID'] );
			if ( $id > 0 ) {
				$ids[]       = $id;
				$mime[ $id ]   = (string) $row['post_mime_type'];
				$parent[ $id ] = absint( $row['post_parent'] );
			}
		}
		$out['scanned']   = count( $ids );
		$out['cursorEnd'] = max( $ids );
		$out['done']      = count( $rows ) < absint( $limit );

		$meta = $this->fetch_meta( $ids, array( 'image_guid', 'img_guid', 'mobo_source_url', '_wp_attached_file', '_wp_attachment_metadata', 'mobo_image_refresh_replaced_by_attachment_id', 'mobo_shared_media' ) );
		if ( null === $meta ) {
			$out['error'] = 'meta-query-failed';
			return $out;
		}

		$marked = array();
		foreach ( $ids as $id ) {
			$m = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			if ( '' !== trim( (string) ( isset( $m['image_guid'] ) ? $m['image_guid'] : '' ) ) || '' !== trim( (string) ( isset( $m['img_guid'] ) ? $m['img_guid'] : '' ) ) ) {
				$marked[] = $id;
			} else {
				/* (c) merchant upload: counted only, never inspected further. */
				$out['items'][] = array( 'id' => $id, 'class' => Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_NOT_MOBO, 'reason' => 'no-plugin-ownership-marker' );
			}
		}
		if ( empty( $marked ) ) {
			return $out;
		}

		$context = $this->gather_context( $marked, $meta, $content_refs, $parent );
		if ( ! empty( $context['error'] ) ) {
			$out['error'] = (string) $context['error'];
			return $out;
		}

		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$basedir = isset( $uploads['basedir'] ) ? rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) : '';
		$probe   = static function ( $relative ) use ( $basedir ) {
			if ( '' === $basedir ) {
				return false;
			}
			$path = $basedir . '/' . ltrim( (string) $relative, '/' );
			return is_file( $path ) ? (int) @filesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- read-only size probe on shared hosting.
		};

		foreach ( $marked as $id ) {
			$m        = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$file     = (string) ( isset( $m['_wp_attached_file'] ) ? $m['_wp_attached_file'] : '' );
			$metadata = isset( $m['_wp_attachment_metadata'] ) ? maybe_unserialize( $m['_wp_attachment_metadata'] ) : array();
			$facts    = array(
				'mime'                 => $mime[ $id ],
				'file'                 => $file,
				'image_guid'           => (string) ( isset( $m['image_guid'] ) ? $m['image_guid'] : '' ),
				'img_guid'             => (string) ( isset( $m['img_guid'] ) ? $m['img_guid'] : '' ),
				'shared_media'         => '1' === (string) ( isset( $m['mobo_shared_media'] ) ? $m['mobo_shared_media'] : '' ),
				'shares_physical_file' => isset( $context['sharedFile'][ $id ] ),
				'live_in_image_queue'  => isset( $context['liveQueue'][ $id ] ),
				'active_refresh'       => isset( $context['activeRefresh'][ $id ] ),
				'guid_pending_raster'  => false,
				'webp_available'       => false,
				'refs_complete'        => ! $content_overflow && ! isset( $context['parentUnknown'][ $id ] ),
				'parent_non_mobo'      => isset( $context['parentNonMobo'][ $id ] ),
				'refs'                 => isset( $context['refs'][ $id ] ) ? $context['refs'][ $id ] : array(),
				'replaced_by_valid'    => isset( $context['validReplacement'][ $id ] ),
			);
			$identity = Mobo_Core_Legacy_Image_Policy::identity( $facts['image_guid'], $facts['img_guid'] );
			$guid     = $identity['canonical'];
			if ( '' !== $guid && isset( $context['guid'][ $guid ] ) ) {
				$facts['guid_pending_raster'] = ! empty( $context['guid'][ $guid ]['pendingRaster'] );
				$facts['webp_available']      = ! empty( $context['guid'][ $guid ]['webp'] );
			}
			$verdict  = Mobo_Core_Legacy_Image_Policy::classify( $facts );
			$estimate = Mobo_Core_Legacy_Image_Policy::estimate_family( $file, $metadata, $probe );
			$out['items'][] = array(
				'id'        => $id,
				'class'     => $verdict['class'],
				'reason'    => $verdict['reason'],
				'file'      => $file,
				'bytes'     => $estimate['bytes'],
				'metaBytes' => $estimate['metaBytes'],
				'files'     => $estimate['files'],
				'missing'   => $estimate['missing'],
			);
		}

		return $out;
	}

	/**
	 * Bulk postmeta read.
	 *
	 * @param int[]    $ids Post IDs.
	 * @param string[] $keys Meta keys.
	 * @return array|null post_id => key => first value; null on DB error.
	 */
	private function fetch_meta( array $ids, array $keys ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) || empty( $keys ) ) {
			return array();
		}
		$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql  = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ") AND meta_key IN ({$key_placeholders}) ORDER BY meta_id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $keys ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$pid = absint( $row['post_id'] );
			$key = (string) $row['meta_key'];
			if ( ! isset( $out[ $pid ][ $key ] ) ) {
				$out[ $pid ][ $key ] = (string) $row['meta_value'];
			}
		}
		return $out;
	}

	/**
	 * Gather every reference/ownership fact for a batch of marked attachments.
	 *
	 * @param int[] $ids Marked attachment IDs.
	 * @param array $meta Bulk meta of the batch.
	 * @return array
	 */
	private function gather_context( array $ids, array $meta, array $content_refs = array(), array $parents = array() ) {
		global $wpdb;
		$ctx = array( 'parentNonMobo' => array(), 'parentUnknown' => array(), 'refs' => array(), 'sharedFile' => array(), 'liveQueue' => array(), 'activeRefresh' => array(), 'validReplacement' => array(), 'guid' => array(), 'error' => '' );
		$id_list   = implode( ',', array_map( 'absint', $ids ) );
		$id_values = array_map( 'strval', array_map( 'absint', $ids ) );
		$in_values = implode( ',', array_fill( 0, count( $id_values ), '%s' ) );
		$fail      = static function () use ( $wpdb ) {
			return '' !== (string) $wpdb->last_error;
		};

		/* 0. Attached to a post that is not a Mobo product. Legacy Mobo downloads
		 * were inserted without a parent; "duplicate product" plugins copy
		 * attachments together with their img_guid meta and attach the copy to the
		 * merchant's product, so such a copy is merchant-owned. */
		$parent_ids = array();
		foreach ( $ids as $id ) {
			if ( ! empty( $parents[ $id ] ) ) {
				$parent_ids[ absint( $parents[ $id ] ) ] = true;
			}
		}
		if ( ! empty( $parent_ids ) ) {
			$rows = $wpdb->get_results( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_map( 'absint', array_keys( $parent_ids ) ) ) . ')', ARRAY_A );
			if ( $fail() ) {
				$ctx['error'] = 'parent-query-failed';
				return $ctx;
			}
			$types = array();
			foreach ( (array) $rows as $row ) {
				$types[ absint( $row['ID'] ) ] = (string) $row['post_type'];
			}
			foreach ( $ids as $id ) {
				$pid = absint( isset( $parents[ $id ] ) ? $parents[ $id ] : 0 );
				if ( $pid <= 0 || ! isset( $types[ $pid ] ) ) {
					continue; /* No parent, or the parent post no longer exists. */
				}
				$mobo = in_array( $types[ $pid ], array( 'product', 'product_variation' ), true ) ? $this->is_mobo_product( $pid ) : false;
				if ( false === $mobo ) {
					$ctx['parentNonMobo'][ $id ] = true;
				} elseif ( null === $mobo ) {
					$ctx['parentUnknown'][ $id ] = true;
				}
			}
		}

		/* 1. Physical file shared with another attachment row. */
		$files = array();
		foreach ( $ids as $id ) {
			$file = isset( $meta[ $id ]['_wp_attached_file'] ) ? (string) $meta[ $id ]['_wp_attached_file'] : '';
			if ( '' !== $file ) {
				$files[ $id ] = $file;
			}
		}
		if ( ! empty( $files ) ) {
			$unique = array_values( array_unique( $files ) );
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN (" . implode( ',', array_fill( 0, count( $unique ), '%s' ) ) . ')', $unique ), ARRAY_A );
			if ( $fail() ) {
				$ctx['error'] = 'shared-file-query-failed';
				return $ctx;
			}
			$owners = array();
			foreach ( (array) $rows as $row ) {
				$owners[ (string) $row['meta_value'] ][ absint( $row['post_id'] ) ] = true;
			}
			foreach ( $files as $id => $file ) {
				if ( isset( $owners[ $file ] ) && count( $owners[ $file ] ) > 1 ) {
					$ctx['sharedFile'][ $id ] = true;
				}
			}
		}

		/* 2. Image queue (all Connections): current attachments and GUID state. */
		$guids = array();
		foreach ( $ids as $id ) {
			foreach ( array( 'image_guid', 'img_guid' ) as $key ) {
				$value = isset( $meta[ $id ][ $key ] ) ? trim( (string) $meta[ $id ][ $key ] ) : '';
				if ( '' !== $value ) {
					$guids[ $value ] = true;
				}
			}
		}
		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$table = Mobo_Core_Image_Queue::table_name();
			$rows  = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table} WHERE attachment_id IN ({$id_list})" );
			if ( $fail() ) {
				$ctx['error'] = 'image-queue-query-failed';
				return $ctx;
			}
			foreach ( (array) $rows as $aid ) {
				$ctx['liveQueue'][ absint( $aid ) ] = true;
			}
			if ( ! empty( $guids ) ) {
				$keys  = array_keys( $guids );
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT image_guid, attachment_id, status, source_url FROM {$table} WHERE image_guid IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ), ARRAY_A );
				if ( $fail() ) {
					$ctx['error'] = 'image-queue-guid-query-failed';
					return $ctx;
				}
				$done_attachments = array();
				foreach ( (array) $rows as $row ) {
					$g      = strtolower( trim( (string) $row['image_guid'] ) );
					$status = sanitize_key( (string) $row['status'] );
					$source = strtolower( (string) parse_url( (string) $row['source_url'], PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure parsing.
					$is_webp_source = 'webp' === pathinfo( $source, PATHINFO_EXTENSION );
					if ( ! isset( $ctx['guid'][ $g ] ) ) {
						$ctx['guid'][ $g ] = array( 'pendingRaster' => false, 'webp' => false );
					}
					if ( 'done' !== $status && ! $is_webp_source ) {
						$ctx['guid'][ $g ]['pendingRaster'] = true;
					}
					if ( $is_webp_source ) {
						/* The Backend serves a WebP for this image: replacement can be fetched. */
						$ctx['guid'][ $g ]['webp'] = true;
					}
					if ( 'done' === $status && absint( $row['attachment_id'] ) > 0 ) {
						$done_attachments[ absint( $row['attachment_id'] ) ][] = $g;
					}
				}
				if ( ! empty( $done_attachments ) ) {
					$webp_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/webp' AND ID IN (" . implode( ',', array_map( 'absint', array_keys( $done_attachments ) ) ) . ')' );
					if ( $fail() ) {
						$ctx['error'] = 'webp-attachment-query-failed';
						return $ctx;
					}
					foreach ( (array) $webp_ids as $wid ) {
						foreach ( $done_attachments[ absint( $wid ) ] as $g ) {
							$ctx['guid'][ $g ]['webp'] = true;
						}
					}
				}
			}
		}

		/* 3. Active refresh rows. */
		if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) && Mobo_Core_Image_Refresh_Queue::table_exists() ) {
			$table = Mobo_Core_Image_Refresh_Queue::table_name();
			$rows  = $wpdb->get_col( "SELECT DISTINCT old_attachment_id FROM {$table} WHERE status IN ('pending','processing') AND old_attachment_id IN ({$id_list})" );
			if ( $fail() ) {
				$ctx['error'] = 'refresh-queue-query-failed';
				return $ctx;
			}
			foreach ( (array) $rows as $aid ) {
				$ctx['activeRefresh'][ absint( $aid ) ] = true;
			}
		}

		/* 4. Recorded WebP replacement still valid. */
		$replacements = array();
		foreach ( $ids as $id ) {
			$rid = absint( isset( $meta[ $id ]['mobo_image_refresh_replaced_by_attachment_id'] ) ? $meta[ $id ]['mobo_image_refresh_replaced_by_attachment_id'] : 0 );
			if ( $rid > 0 ) {
				$replacements[ $id ] = $rid;
			}
		}
		if ( ! empty( $replacements ) ) {
			$valid = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/webp' AND ID IN (" . implode( ',', array_map( 'absint', array_unique( array_values( $replacements ) ) ) ) . ')' );
			if ( $fail() ) {
				$ctx['error'] = 'replacement-query-failed';
				return $ctx;
			}
			$valid = array_flip( array_map( 'absint', (array) $valid ) );
			foreach ( $replacements as $id => $rid ) {
				if ( isset( $valid[ $rid ] ) ) {
					$ctx['validReplacement'][ $id ] = true;
				}
			}
		}

		/* 5. Featured image of any post (any status, incl. trash and variations). */
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value, p.post_type, p.post_parent FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value IN ({$in_values})", $id_values ), ARRAY_A );
		if ( $fail() ) {
			$ctx['error'] = 'thumbnail-query-failed';
			return $ctx;
		}
		foreach ( (array) $rows as $row ) {
			$aid  = absint( $row['meta_value'] );
			$type = (string) $row['post_type'];
			if ( 'product' === $type ) {
				$ctx['refs'][ $aid ][] = array( 'kind' => 'featured', 'mobo' => $this->is_mobo_product( absint( $row['post_id'] ) ) );
			} elseif ( 'product_variation' === $type ) {
				$ctx['refs'][ $aid ][] = array( 'kind' => 'variation', 'mobo' => $this->is_mobo_product( absint( $row['post_parent'] ) ) );
			} else {
				$ctx['refs'][ $aid ][] = array( 'kind' => 'other', 'mobo' => null );
			}
		}

		/* 6. WooCommerce gallery + popular variation-gallery plugin keys. */
		$alternation = implode( '|', array_map( 'absint', $ids ) );
		$regexp      = '(^|[^0-9])(' . $alternation . ')([^0-9]|$)';
		$keys        = array_merge( array( '_product_image_gallery' ), self::EXTRA_GALLERY_KEYS );
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_parent FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') AND pm.meta_value REGEXP %s', array_merge( $keys, array( $regexp ) ) ), ARRAY_A );
		if ( $fail() ) {
			$ctx['error'] = 'gallery-query-failed';
			return $ctx;
		}
		$wanted = array_flip( array_map( 'absint', $ids ) );
		foreach ( (array) $rows as $row ) {
			if ( '_product_image_gallery' === $row['meta_key'] ) {
				$hits = Mobo_Core_Legacy_Image_Policy::parse_gallery( (string) $row['meta_value'] );
				$owner = 'product_variation' === $row['post_type'] ? absint( $row['post_parent'] ) : absint( $row['post_id'] );
				foreach ( $hits as $aid ) {
					if ( isset( $wanted[ $aid ] ) ) {
						$ctx['refs'][ $aid ][] = array( 'kind' => 'product' === $row['post_type'] || 'product_variation' === $row['post_type'] ? 'gallery' : 'other', 'mobo' => $this->is_mobo_product( $owner ) );
					}
				}
			} else {
				preg_match_all( '/\d+/', (string) $row['meta_value'], $m );
				foreach ( array_unique( array_map( 'absint', $m[0] ) ) as $aid ) {
					if ( isset( $wanted[ $aid ] ) ) {
						$ctx['refs'][ $aid ][] = array( 'kind' => 'other', 'mobo' => null );
					}
				}
			}
		}

		/* 7. Term meta (category thumbnails etc.) and a few site options. */
		if ( ! empty( $wpdb->termmeta ) ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value IN ({$in_values})", $id_values ) );
			if ( $fail() ) {
				$ctx['error'] = 'termmeta-query-failed';
				return $ctx;
			}
			foreach ( (array) $rows as $value ) {
				$ctx['refs'][ absint( $value ) ][] = array( 'kind' => 'term', 'mobo' => null );
			}
		}
		$option_ids = array( absint( get_option( 'site_icon', 0 ) ), absint( get_option( 'woocommerce_placeholder_image', 0 ) ) );
		if ( function_exists( 'get_theme_mod' ) ) {
			$option_ids[] = absint( get_theme_mod( 'custom_logo', 0 ) );
		}
		foreach ( $option_ids as $oid ) {
			if ( $oid > 0 && isset( $wanted[ $oid ] ) ) {
				$ctx['refs'][ $oid ][] = array( 'kind' => 'option', 'mobo' => null );
			}
		}

		/* 8. Post content / excerpt: compare with the set built in phase 1. */
		$content_basenames = isset( $content_refs['b'] ) && is_array( $content_refs['b'] ) ? $content_refs['b'] : array();
		$content_ids       = isset( $content_refs['i'] ) && is_array( $content_refs['i'] ) ? $content_refs['i'] : array();
		foreach ( $ids as $id ) {
			if ( isset( $content_ids[ $id ] ) ) {
				$ctx['refs'][ $id ][] = array( 'kind' => 'content', 'mobo' => null );
				continue;
			}
			$file     = isset( $meta[ $id ]['_wp_attached_file'] ) ? (string) $meta[ $id ]['_wp_attached_file'] : '';
			$metadata = isset( $meta[ $id ]['_wp_attachment_metadata'] ) ? maybe_unserialize( $meta[ $id ]['_wp_attachment_metadata'] ) : array();
			foreach ( array_keys( Mobo_Core_Legacy_Image_Policy::family_files( $file, $metadata ) ) as $relative ) {
				if ( isset( $content_basenames[ strtolower( basename( $relative ) ) ] ) ) {
					$ctx['refs'][ $id ][] = array( 'kind' => 'content', 'mobo' => null );
					break;
				}
			}
		}

		return $ctx;
	}

	/**
	 * Phase 1 page: collect references from post_content/post_excerpt of every
	 * non-attachment post (any status, revisions included) in one primary-key
	 * range. Read-only.
	 *
	 * @param int   $cursor Last processed post ID.
	 * @param int   $max_id Highest post ID at cycle start.
	 * @param array $refs Accumulated set array{b: basename=>1, i: id=>1}.
	 * @return array{refs:array,cursorEnd:int,done:bool,overflow:bool,error:string}
	 */
	public function content_page( $cursor, $max_id, array $refs ) {
		global $wpdb;
		$refs = array(
			'b' => isset( $refs['b'] ) && is_array( $refs['b'] ) ? $refs['b'] : array(),
			'i' => isset( $refs['i'] ) && is_array( $refs['i'] ) ? $refs['i'] : array(),
		);
		$cursor = absint( $cursor );
		$end    = $cursor + self::CONTENT_SPAN;
		$out    = array( 'refs' => $refs, 'cursorEnd' => $cursor, 'done' => false, 'overflow' => false, 'error' => '' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_content, post_excerpt FROM {$wpdb->posts}
				WHERE ID > %d AND ID <= %d AND post_type <> 'attachment'
				AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s
					OR post_excerpt LIKE %s OR post_excerpt LIKE %s OR post_excerpt LIKE %s )",
				$cursor,
				$end,
				'%.jp%',
				'%.png%',
				'%wp-image-%',
				'%ids=%',
				'%"id":%',
				'%.jp%',
				'%.png%',
				'%wp-image-%'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			$out['error'] = 'content-query-failed';
			return $out;
		}
		foreach ( $rows as $row ) {
			$found = Mobo_Core_Legacy_Image_Policy::extract_content_references( (string) $row['post_content'] . "\n" . (string) $row['post_excerpt'] );
			foreach ( $found['basenames'] as $basename ) {
				$out['refs']['b'][ $basename ] = 1;
			}
			foreach ( $found['ids'] as $id ) {
				$out['refs']['i'][ $id ] = 1;
			}
		}
		if ( count( $out['refs']['b'] ) + count( $out['refs']['i'] ) > self::CONTENT_MAX ) {
			/* Too many references to hold safely: every marked attachment becomes
			 * "uncertain" instead of risking a false "unreferenced". */
			$out['overflow'] = true;
			$out['refs']     = array( 'b' => array(), 'i' => array() );
		}
		$out['cursorEnd'] = $end;
		$out['done']      = $end >= absint( $max_id );
		return $out;
	}

	/**
	 * Whether a product (any Connection) is a Mobo product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool|null Null when ownership cannot be determined.
	 */
	private function is_mobo_product( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $product_id, $this->mobo_product_cache ) ) {
			return $this->mobo_product_cache[ $product_id ];
		}
		$result = false;
		try {
			if ( class_exists( 'Mobo_Core_Product_State_Repository' ) && class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
				$owner = ( new Mobo_Core_Product_State_Repository() )->get_owner_by_woo_product_id( $product_id );
				if ( is_array( $owner ) ) {
					$result = true;
				}
			}
		} catch ( Throwable $error ) {
			$result = null;
		}
		if ( false === $result ) {
			$keys  = self::PRODUCT_IDENTITY_KEYS;
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND meta_value <> '' LIMIT 1", array_merge( array( $product_id ), $keys ) ) );
			if ( '' !== (string) $wpdb->last_error ) {
				$result = null;
			} else {
				$result = absint( $found ) > 0;
			}
		}
		$this->mobo_product_cache[ $product_id ] = $result;
		return $result;
	}
}
