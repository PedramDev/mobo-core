<?php
/**
 * READ-ONLY scanner for legacy Mobo JPG/PNG attachments.
 *
 * Three resumable phases, each run in bounded steps inside the admin request's
 * time budget (clamped to Mobo_Core_Request_Deadline):
 *
 * 1. attachments: primary-key batches of JPG/PNG attachments. Ownership
 *    (strong proof only, see Mobo_Core_Legacy_Image_Policy), shared files,
 *    image/refresh queues, featured/gallery/variation use, WPML translations,
 *    termmeta/site options are checked with grouped queries. Attachments that
 *    would be candidates are NOT counted yet: they go into the candidate index
 *    (option chunks of CHUNK_SIZE entries holding their family file paths).
 * 2. refs: one keyset-paged pass (byte budget per page, no per-attachment LIKE
 *    queries) over post content/excerpt (all types and statuses incl.
 *    revisions, Elementor templates), ALL postmeta, termmeta, term
 *    descriptions (incl. Polylang groups), usermeta, options (Elementor kit,
 *    theme mods, widgets, ACF options), slider/plugin tables that exist
 *    (Revolution Slider, Smart Slider, LayerSlider, Basalam media) and the
 *    Elementor CSS files. Every candidate whose ID or any family basename (or a
 *    size/-scaled/thumb variant of it) is found gets a hit bit per source.
 * 3. finalize: candidate chunks are classified (hit => protected_referenced,
 *    incomplete index => uncertain, else unused/replace) and counted.
 *
 * It never changes posts, metadata, files or plugin queues: the only writes are
 * its own options (state, candidate chunks, hit map, Stop flag).
 *
 * Crash safety: every step is computed in memory and committed with its cursor
 * in one update_option(). Candidate chunks are keyed by attachment ID and the
 * hit map is a bitwise union, so re-running a step after a crash/timeout never
 * loses or double counts anything. A row larger than ROW_MAX_BYTES (or an
 * unreadable CSS file) is skipped and marks the whole index incomplete, which
 * turns every remaining candidate into "unsure" instead of "unused".
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Bounded read-only SELECTs over core, Mobo and known plugin tables. Identifier
 * lists are absint()-sanitized integers, table/column names come from
 * $wpdb/SHOW TABLES and are validated; every string value is prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
class Mobo_Core_Legacy_Image_Audit {

	const STATE_VERSION  = 2;
	const STATE_OPTION   = 'mobo_core_legacy_image_audit_state';
	/* v1 leftover (content reference set); deleted when a new cycle starts. */
	const CONTENT_OPTION = 'mobo_core_legacy_image_audit_content_refs';
	const HITS_OPTION    = 'mobo_core_legacy_image_audit_hits';
	const CAND_PREFIX    = 'mobo_core_legacy_image_audit_cand_';
	const STOP_OPTION    = 'mobo_core_legacy_image_audit_stop';
	const DELETED_OPTION = 'mobo_core_legacy_image_cleanup_deleted_total';
	const LOCK_NAME      = 'legacy_image_audit';
	const SAMPLE_LIMIT   = 30;
	const BATCH_DEFAULT  = 40;
	const BATCH_MAX      = 100;

	/** Candidates per index chunk option. */
	const CHUNK_SIZE = 500;
	/** Candidate index capacity; beyond it attachments are "unsure". */
	const PENDING_MAX = 15000;
	/** Reference pass: rows planned per page and value bytes fetched per page. */
	const PAGE_ROWS  = 2000;
	const PAGE_BYTES = 2097152;
	/** A single value larger than this is skipped and marks the index incomplete. */
	const ROW_MAX_BYTES = 4194304;
	/** Elementor CSS files. */
	const CSS_DIR            = 'elementor/css';
	const CSS_MAX_FILE_BYTES = 2097152;
	const CSS_FILES_PER_PAGE = 200;
	const CSS_MAX_FILES      = 50000;
	/** ACF field definitions read per cycle. */
	const ACF_MAX_FIELDS = 5000;
	/** Stored incomplete-index details (reason keys). */
	const INCOMPLETE_MAX = 20;
	/** Seconds of the request deadline left to the caller after a slice. */
	const DEADLINE_RESERVE = 1.5;

	/** Product identity keys used by legacy (pre custom storage) Mobo products. */
	const PRODUCT_IDENTITY_KEYS = array( 'product_guid', 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId', 'mobo_url' );

	/** Popular variation-gallery plugin keys; any hit is treated as a foreign reference. */
	const EXTRA_GALLERY_KEYS = array( 'woo_variation_gallery_images', '_wc_additional_variation_images', 'rtwpvg_images', '_product_image_gallery_variation' );

	/**
	 * Postmeta keys the reference pass ignores: Woo featured/gallery are checked
	 * precisely (with Mobo/merchant ownership) in the attachment pass; the rest
	 * are Mobo provenance/identity markers that link old and new attachments
	 * but are not a use of the image.
	 */
	const SKIP_META_KEYS = array( '_thumbnail_id', '_product_image_gallery', 'image_guid', 'img_guid', 'mobo_source_url', 'mobo_refreshed_from_attachment_id', 'mobo_replaces_attachment_id', 'mobo_image_format', 'mobo_sync_incomplete', 'mobo_image_content_token', 'mobo_shared_media', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
	const SKIP_META_PREFIXES = array( 'mobo_image_refresh_', '_mobo_quarantined_' );
	/** Attachment file keys: compared by full relative path (same physical file), never by basename. */
	const ATTACHMENT_FILE_KEYS = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );
	/** Options never scanned: caches (regenerated from scanned data), WP internals, our own image job state. */
	const SKIP_OPTION_PREFIXES = array( '_transient_', '_site_transient_', 'mobo_core_legacy_image_', 'mobo_core_image_', 'mobo_core_orphan_image_' );
	const SKIP_OPTIONS         = array( 'rewrite_rules', 'cron', 'rs-library', 'rs-templates', 'rs-templates-new' );
	/** Remote catalogue caches (Elementor / Slider Revolution template libraries): item "id"s are not attachments. */
	const SKIP_OPTION_REMOTE_PREFIXES = array( 'elementor_remote_info_', 'rtl_elementor_remote_info_' );
	/** Plugin tables scanned when present: table-name prefix (after $wpdb->prefix) => report source. */
	const PLUGIN_TABLE_PATTERNS = array(
		'revslider_'                  => 'slider',
		'nextend2_smartslider3_'      => 'slider',
		'layerslider'                 => 'slider',
		'sync_basalam_uploaded_media' => 'plugin',
	);

	/** @var array<int,bool|null> Request cache: product id => is Mobo product. */
	private $mobo_product_cache = array();

	/** @var array|null Request cache of the candidate index. */
	private $index = null;

	/** @var string Key of the cached index. */
	private $index_key = '';

	/** @var bool|null Request cache: WPML translations table present. */
	private $wpml_table = null;

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
		$sources = array();
		foreach ( array_keys( Mobo_Core_Legacy_Image_Policy::reference_sources() ) as $source ) {
			$sources[ $source ] = 0;
		}
		return array(
			'version'            => self::STATE_VERSION,
			'status'             => 'idle',
			'phase'              => 'attachments',
			'cycleId'            => '',
			'cursor'             => 0,
			'scanned'            => 0,
			'estimatedTotal'     => 0,
			'batches'            => 0,
			'startedAt'          => 0,
			'updatedAt'          => 0,
			'completedAt'        => 0,
			'lastRunAt'          => 0,
			'lastError'          => '',
			'classes'            => $classes,
			'samples'            => array(),
			'pending'            => 0,
			'pendingOverflow'    => false,
			'sourcesReady'       => false,
			'sources'            => array(),
			'sourceIndex'        => 0,
			'sourceCursor'       => 0,
			'sourceMax'          => 0,
			'refRows'            => 0,
			'refBytes'           => 0,
			'refPages'           => 0,
			'incomplete'         => false,
			'incompleteReasons'  => array(),
			'acfNames'           => array(),
			'finalizeChunk'      => 0,
			'referencedBySource' => $sources,
			'slices'             => 0,
			'lastSliceSeconds'   => 0,
			'maxSliceSeconds'    => 0,
			'lastSliceSteps'     => 0,
			'peakMemory'         => 0,
		);
	}

	/**
	 * Current normalized state. A state written by an older scanner version is
	 * discarded (its "unused" figures used weaker rules).
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$base  = self::empty_state();
		if ( ! isset( $state['version'] ) || self::STATE_VERSION !== (int) $state['version'] ) {
			return $base;
		}
		$state = array_merge( $base, $state );
		$state['classes']            = array_merge( $base['classes'], is_array( $state['classes'] ) ? $state['classes'] : array() );
		$state['referencedBySource'] = array_merge( $base['referencedBySource'], is_array( $state['referencedBySource'] ) ? $state['referencedBySource'] : array() );
		$state['samples']            = is_array( $state['samples'] ) ? array_slice( $state['samples'], 0, self::SAMPLE_LIMIT ) : array();
		$state['sources']            = is_array( $state['sources'] ) ? $state['sources'] : array();
		$state['incompleteReasons']  = is_array( $state['incompleteReasons'] ) ? $state['incompleteReasons'] : array();
		$state['acfNames']           = is_array( $state['acfNames'] ) ? $state['acfNames'] : array();
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
		$get   = static function ( $class ) use ( $by_class ) {
			return absint( isset( $by_class[ $class ] ) ? $by_class[ $class ] : 0 );
		};
		$unused = isset( $state['classes'][ Mobo_Core_Legacy_Image_Policy::CLASS_DELETE_CANDIDATE ] ) ? $state['classes'][ Mobo_Core_Legacy_Image_Policy::CLASS_DELETE_CANDIDATE ] : array();
		return array(
			'scanStatus'         => sanitize_key( (string) $state['status'] ),
			'scanComplete'       => 'complete' === $state['status'],
			'phase'              => sanitize_key( (string) $state['phase'] ),
			'scanned'            => absint( $state['scanned'] ),
			'estimatedTotal'     => absint( $state['estimatedTotal'] ),
			'candidates'         => $candidates,
			'replaceThenDelete'  => $get( Mobo_Core_Legacy_Image_Policy::CLASS_REPLACE_THEN_DELETE ),
			'deleteCandidates'   => $get( Mobo_Core_Legacy_Image_Policy::CLASS_DELETE_CANDIDATE ),
			'unusedBytes'        => absint( isset( $unused['bytes'] ) ? $unused['bytes'] : 0 ),
			'unusedMetadataBytes'=> absint( isset( $unused['metaBytes'] ) ? $unused['metaBytes'] : 0 ),
			'protected'          => $protected,
			'referenced'         => $get( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_REFERENCED ),
			'referencedBySource' => array_map( 'absint', $state['referencedBySource'] ),
			'unsureWeakOwnership'=> $get( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_WEAK_OWNERSHIP ),
			'unsure'             => $get( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_UNCERTAIN ),
			'merchant'           => $get( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_NOT_MOBO ),
			'merchantProductUse' => $get( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_FOREIGN_USE ),
			'pendingCandidates'  => 'complete' === $state['status'] ? 0 : absint( $state['pending'] ),
			'referenceIndexComplete' => empty( $state['incomplete'] ) && empty( $state['pendingOverflow'] ),
			'incompleteReasons'  => array_slice( array_map( 'sanitize_text_field', array_keys( $state['incompleteReasons'] ) ), 0, 5 ),
			'referenceRows'      => absint( $state['refRows'] ),
			'maxSliceSeconds'    => round( (float) $state['maxSliceSeconds'], 2 ),
			'bytes'              => $bytes,
			'metadataBytes'      => $meta_bytes,
			'deleted'            => absint( get_option( self::DELETED_OPTION, 0 ) ),
			'deletionEnabled'    => false,
			'lastRunAt'          => absint( $state['lastRunAt'] ),
			'completedAt'        => absint( $state['completedAt'] ),
			'classes'            => $by_class,
		);
	}

	/**
	 * Request a cooperative stop. The running slice stops before its next step.
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
		$state = self::get_state();
		delete_option( self::STOP_OPTION );
		$this->clear_index( absint( $state['pending'] ) );
		update_option( self::STATE_OPTION, self::empty_state(), false );
		return self::get_state();
	}

	/**
	 * Start a new scan cycle or continue the current one for a bounded time.
	 *
	 * @param float $time_budget Seconds (clamped to the request deadline).
	 * @param int   $batch_size Attachments per batch.
	 * @return array State after the slice.
	 */
	public function run( $time_budget = 8.0, $batch_size = self::BATCH_DEFAULT ) {
		global $wpdb;
		$time_budget = max( 0.5, min( 25.0, (float) $time_budget ) );
		if ( class_exists( 'Mobo_Core_Request_Deadline' ) ) {
			$time_budget = min( $time_budget, Mobo_Core_Request_Deadline::clamp_seconds( $time_budget, self::DEADLINE_RESERVE ) );
		}
		$batch_size = max( 5, min( self::BATCH_MAX, absint( $batch_size ) ) );
		if ( $time_budget < 0.5 ) {
			$state              = self::get_state();
			$state['lastError'] = 'زمان کافی در این درخواست باقی نمانده است؛ دوباره ادامه دهید.';
			return $state;
		}

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
				$this->clear_index( absint( $state['pending'] ) );
				delete_option( self::CONTENT_OPTION );
				$state                   = self::empty_state();
				$state['status']         = 'running';
				$state['cycleId']        = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'legacy', true );
				$state['startedAt']      = time();
				$state['estimatedTotal'] = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" ) );
			}
			$state['status']    = 'running';
			$state['lastError'] = '';

			$started  = microtime( true );
			$deadline = $started + $time_budget;
			$steps    = 0;
			while ( microtime( true ) < $deadline && $steps < 2000 ) {
				if ( $this->stop_requested() ) {
					$state['status'] = 'stopped';
					break;
				}
				$steps++;
				$result = $this->step( $state, $batch_size );
				if ( '' !== (string) $result['error'] ) {
					/* Uncertain database state: nothing of this step is committed. */
					$state['lastError'] = sanitize_text_field( (string) $result['error'] );
					break;
				}
				$state              = $result['state'];
				$state['updatedAt'] = time();
				/* Commit cursor + counters atomically after every step. */
				update_option( self::STATE_OPTION, $state, false );
				if ( 'complete' === $state['status'] ) {
					break;
				}
			}
			$elapsed                    = microtime( true ) - $started;
			$state['slices']            = absint( $state['slices'] ) + 1;
			$state['lastSliceSeconds']  = round( $elapsed, 3 );
			$state['maxSliceSeconds']   = round( max( (float) $state['maxSliceSeconds'], $elapsed ), 3 );
			$state['lastSliceSteps']    = $steps;
			$state['peakMemory']        = max( absint( $state['peakMemory'] ), (int) ( class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::memory_peak( true ) : ( function_exists( 'memory_get_peak_usage' ) ? memory_get_peak_usage( true ) : 0 ) ) );
			$state['lastRunAt']         = time();
			update_option( self::STATE_OPTION, $state, false );
			return $state;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && '__no_lock__' !== $lock ) {
				Mobo_Core_Lock::release( self::LOCK_NAME, $lock );
			}
		}
	}

	/**
	 * One bounded unit of work for the current phase (pure w.r.t. the state
	 * option: the caller commits the returned state).
	 *
	 * @param array $state State.
	 * @param int   $batch_size Attachment batch size.
	 * @return array{state:array,error:string}
	 */
	public function step( array $state, $batch_size = self::BATCH_DEFAULT ) {
		$phase = (string) $state['phase'];
		if ( 'attachments' === $phase ) {
			$batch = $this->scan_batch( absint( $state['cursor'] ), $batch_size );
			if ( ! empty( $batch['error'] ) ) {
				return array( 'state' => $state, 'error' => (string) $batch['error'] );
			}
			$state = $this->commit_attachment_batch( $state, $batch );
			$state['lastRunAt'] = time();
			if ( ! empty( $batch['done'] ) ) {
				$state['phase'] = absint( $state['pending'] ) > 0 ? 'refs' : 'finalize';
			}
			return array( 'state' => $state, 'error' => '' );
		}
		if ( 'refs' === $phase ) {
			return $this->ref_step( $state );
		}
		if ( 'finalize' === $phase ) {
			return $this->finalize_step( $state );
		}
		$state['phase'] = 'attachments';
		return array( 'state' => $state, 'error' => '' );
	}

	/**
	 * Add one computed batch to the state (pure). Items flagged `pending` are
	 * never counted here: they are counted once, in the finalize phase.
	 *
	 * @param array $state State.
	 * @param array $batch Batch result.
	 * @return array
	 */
	public function merge_batch( array $state, array $batch ) {
		if ( isset( $batch['cursorEnd'] ) ) {
			$state['cursor'] = max( absint( $state['cursor'] ), absint( $batch['cursorEnd'] ) );
		}
		$state['scanned'] = absint( $state['scanned'] ) + absint( isset( $batch['scanned'] ) ? $batch['scanned'] : 0 );
		$state['batches'] = absint( $state['batches'] ) + 1;
		foreach ( isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : array() as $item ) {
			$class = isset( $item['class'] ) ? (string) $item['class'] : '';
			if ( ! isset( $state['classes'][ $class ] ) || ! empty( $item['pending'] ) ) {
				continue;
			}
			$state['classes'][ $class ]['count']++;
			$state['classes'][ $class ]['bytes']        += absint( isset( $item['bytes'] ) ? $item['bytes'] : 0 );
			$state['classes'][ $class ]['metaBytes']    += absint( isset( $item['metaBytes'] ) ? $item['metaBytes'] : 0 );
			$state['classes'][ $class ]['files']        += absint( isset( $item['files'] ) ? $item['files'] : 0 );
			$state['classes'][ $class ]['missingFiles'] += absint( isset( $item['missing'] ) ? $item['missing'] : 0 );
			foreach ( isset( $item['sources'] ) && is_array( $item['sources'] ) ? $item['sources'] : array() as $source ) {
				if ( isset( $state['referencedBySource'][ $source ] ) ) {
					$state['referencedBySource'][ $source ]++;
				}
			}
			$candidate  = Mobo_Core_Legacy_Image_Policy::is_candidate_class( $class );
			$sampleable = $candidate || in_array( $class, array( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_UNCERTAIN, Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_WEAK_OWNERSHIP ), true );
			if ( $sampleable && ! $candidate ) {
				/* Unsure samples (attachment pass) keep room for candidate samples (finalize). */
				$unsure_samples = 0;
				foreach ( $state['samples'] as $sample ) {
					$unsure_samples += Mobo_Core_Legacy_Image_Policy::is_candidate_class( isset( $sample['class'] ) ? $sample['class'] : '' ) ? 0 : 1;
				}
				$sampleable = $unsure_samples < (int) floor( self::SAMPLE_LIMIT / 3 );
			}
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
	 * Store a batch's candidates in the index chunks, then count the rest.
	 *
	 * Idempotent: entries are keyed by attachment ID at a position derived from
	 * the committed state, and entries a previous uncommitted attempt left for
	 * the same ID range are dropped first.
	 *
	 * @param array $state State.
	 * @param array $batch scan_batch() result.
	 * @return array New state.
	 */
	public function commit_attachment_batch( array $state, array $batch ) {
		$pending = array();
		$items   = array();
		$room    = max( 0, self::PENDING_MAX - absint( $state['pending'] ) );
		foreach ( isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : array() as $item ) {
			if ( ! empty( $item['pending'] ) ) {
				if ( count( $pending ) < $room ) {
					$pending[] = $item;
					continue;
				}
				$state['pendingOverflow'] = true;
				$item['pending'] = false;
				$item['class']   = Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_UNCERTAIN;
				$item['reason']  = 'candidate-index-full';
			}
			$items[] = $item;
		}
		$low  = absint( $state['cursor'] );
		$high = absint( isset( $batch['cursorEnd'] ) ? $batch['cursorEnd'] : $low );
		$this->write_chunks( absint( $state['pending'] ), $pending, $low, $high );
		$batch['items']   = $items;
		$state            = $this->merge_batch( $state, $batch );
		$state['pending'] = absint( $state['pending'] ) + count( $pending );
		return $state;
	}

	/**
	 * Write candidate entries into their chunk options.
	 *
	 * @param int   $start Committed pending count (position of the first entry).
	 * @param array $items Pending items.
	 * @param int   $low Batch ID range start (exclusive).
	 * @param int   $high Batch ID range end (inclusive).
	 * @return void
	 */
	private function write_chunks( $start, array $items, $low, $high ) {
		$by_chunk = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$chunk = intdiv( $start + $i, self::CHUNK_SIZE );
			$by_chunk[ $chunk ][ absint( $item['id'] ) ] = array(
				'c'  => (string) $item['class'],
				'r'  => (string) $item['reason'],
				'f'  => (string) $item['file'],
				'b'  => absint( $item['bytes'] ),
				'mb' => absint( $item['metaBytes'] ),
				'n'  => absint( $item['files'] ),
				'm'  => absint( $item['missing'] ),
				'p'  => array_values( array_map( 'strval', isset( $item['paths'] ) && is_array( $item['paths'] ) ? $item['paths'] : array() ) ),
			);
		}
		$first = intdiv( $start, self::CHUNK_SIZE );
		$touch = array_unique( array_merge( array( $first, $first + 1 ), array_keys( $by_chunk ) ) );
		foreach ( $touch as $chunk ) {
			$existing = get_option( self::CAND_PREFIX . $chunk, array() );
			$existing = is_array( $existing ) ? $existing : array();
			$before   = $existing;
			foreach ( array_keys( $existing ) as $id ) {
				if ( $id > $low && $id <= $high ) {
					unset( $existing[ $id ] );
				}
			}
			if ( isset( $by_chunk[ $chunk ] ) ) {
				$existing = $by_chunk[ $chunk ] + $existing;
				ksort( $existing );
			}
			if ( $existing === $before ) {
				continue;
			}
			if ( empty( $existing ) ) {
				delete_option( self::CAND_PREFIX . $chunk );
			} else {
				update_option( self::CAND_PREFIX . $chunk, $existing, false );
			}
		}
		$this->index = null;
	}

	/**
	 * Delete candidate chunks and the hit map.
	 *
	 * @param int $pending Pending count of the cycle being cleared.
	 * @return void
	 */
	private function clear_index( $pending ) {
		$chunks = (int) ceil( absint( $pending ) / self::CHUNK_SIZE ) + 2;
		for ( $i = 0; $i < $chunks || ( $i < $chunks + 1000 && false !== get_option( self::CAND_PREFIX . $i, false ) ); $i++ ) {
			delete_option( self::CAND_PREFIX . $i );
		}
		delete_option( self::HITS_OPTION );
		$this->index = null;
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

	/* ------------------------------------------------------------------ */
	/* Phase 1: attachments                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Classify one primary-key batch of JPG/PNG attachments (read-only).
	 *
	 * @param int $cursor Last processed attachment ID.
	 * @param int $limit Batch size.
	 * @return array{items:array,scanned:int,cursorEnd:int,done:bool,error:string}
	 */
	public function scan_batch( $cursor, $limit ) {
		global $wpdb;
		$out  = array( 'items' => array(), 'scanned' => 0, 'cursorEnd' => absint( $cursor ), 'done' => false, 'error' => '' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_mime_type, post_parent, post_title, LENGTH(post_content) + LENGTH(post_excerpt) AS text_len FROM {$wpdb->posts}
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
		$posts  = array();
		foreach ( $rows as $row ) {
			$id = absint( $row['ID'] );
			if ( $id > 0 ) {
				$ids[]        = $id;
				$posts[ $id ] = array(
					'mime'     => (string) $row['post_mime_type'],
					'parent'   => absint( $row['post_parent'] ),
					'title'    => (string) $row['post_title'],
					'text_len' => absint( $row['text_len'] ),
				);
			}
		}
		$out['scanned']   = count( $ids );
		$out['cursorEnd'] = max( $ids );
		$out['done']      = count( $rows ) < absint( $limit );

		$meta = $this->fetch_meta( $ids, array( 'image_guid', 'img_guid', 'mobo_source_url', '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', 'mobo_image_refresh_replaced_by_attachment_id', 'mobo_image_refresh_identity_source', 'mobo_shared_media' ) );
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
				/* (c) merchant upload or earliest unmarked plugin version: counted only, never inspected further. */
				$out['items'][] = array( 'id' => $id, 'class' => Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_NOT_MOBO, 'reason' => 'no-plugin-ownership-marker' );
			}
		}
		if ( empty( $marked ) ) {
			return $out;
		}

		$parents = array();
		foreach ( $marked as $id ) {
			$parents[ $id ] = $posts[ $id ]['parent'];
		}
		$context = $this->gather_context( $marked, $meta, $parents );
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
			$metadata = isset( $m['_wp_attachment_metadata'] ) ? self::safe_unserialize( $m['_wp_attachment_metadata'] ) : array();
			$facts    = array(
				'mime'                 => $posts[ $id ]['mime'],
				'file'                 => $file,
				'image_guid'           => (string) ( isset( $m['image_guid'] ) ? $m['image_guid'] : '' ),
				'img_guid'             => (string) ( isset( $m['img_guid'] ) ? $m['img_guid'] : '' ),
				'shared_media'         => '1' === (string) ( isset( $m['mobo_shared_media'] ) ? $m['mobo_shared_media'] : '' ),
				'shares_physical_file' => isset( $context['sharedFile'][ $id ] ),
				'live_in_image_queue'  => isset( $context['liveQueue'][ $id ] ),
				'active_refresh'       => isset( $context['activeRefresh'][ $id ] ),
				'guid_pending_raster'  => false,
				'webp_available'       => false,
				'refs_complete'        => ! isset( $context['parentUnknown'][ $id ] ),
				'parent_non_mobo'      => isset( $context['parentNonMobo'][ $id ] ),
				'refs'                 => isset( $context['refs'][ $id ] ) ? $context['refs'][ $id ] : array(),
				'replaced_by_valid'    => isset( $context['validReplacement'][ $id ] ),
				'linkage'              => array(),
				'filename_pattern'     => Mobo_Core_Legacy_Image_Policy::filename_pattern( $posts[ $id ]['title'], $posts[ $id ]['text_len'], $file, $metadata, (string) ( isset( $m['mobo_source_url'] ) ? $m['mobo_source_url'] : '' ) ),
			);
			$identity = Mobo_Core_Legacy_Image_Policy::identity( $facts['image_guid'], $facts['img_guid'] );
			$guid     = $identity['canonical'];
			if ( isset( $context['parentMobo'][ $id ] ) ) {
				$facts['linkage'][] = 'parent-mobo-product';
			}
			if ( absint( isset( $m['mobo_image_refresh_replaced_by_attachment_id'] ) ? $m['mobo_image_refresh_replaced_by_attachment_id'] : 0 ) > 0 || '' !== trim( (string) ( isset( $m['mobo_image_refresh_identity_source'] ) ? $m['mobo_image_refresh_identity_source'] : '' ) ) ) {
				$facts['linkage'][] = 'refresh-marker';
			}
			if ( '' !== $guid && isset( $context['guid'][ $guid ] ) ) {
				$facts['linkage'][]           = 'image-queue-guid';
				$facts['guid_pending_raster'] = ! empty( $context['guid'][ $guid ]['pendingRaster'] );
				$facts['webp_available']      = ! empty( $context['guid'][ $guid ]['webp'] );
			}
			$verdict  = Mobo_Core_Legacy_Image_Policy::classify( $facts );
			$estimate = Mobo_Core_Legacy_Image_Policy::estimate_family( $file, $metadata, $probe );
			$item     = array(
				'id'        => $id,
				'class'     => $verdict['class'],
				'reason'    => $verdict['reason'],
				'file'      => $file,
				'bytes'     => $estimate['bytes'],
				'metaBytes' => $estimate['metaBytes'],
				'files'     => $estimate['files'],
				'missing'   => $estimate['missing'],
			);
			if ( Mobo_Core_Legacy_Image_Policy::CLASS_PROTECTED_REFERENCED === $verdict['class'] ) {
				$item['sources'] = array( substr( (string) $verdict['reason'], strlen( 'referenced-by-' ) ) );
			}
			if ( Mobo_Core_Legacy_Image_Policy::is_candidate_class( $verdict['class'] ) ) {
				/* Waits for the site-wide reference index (phase 2). */
				$item['pending'] = true;
				$item['paths']   = self::candidate_paths( $file, $metadata, isset( $m['_wp_attachment_backup_sizes'] ) ? self::safe_unserialize( $m['_wp_attachment_backup_sizes'] ) : array() );
			}
			$out['items'][] = $item;
		}

		return $out;
	}

	/**
	 * Every relative path of an attachment family incl. edited-image backups.
	 *
	 * @param string $file _wp_attached_file.
	 * @param mixed  $metadata Unserialized metadata.
	 * @param mixed  $backup_sizes Unserialized _wp_attachment_backup_sizes.
	 * @return string[]
	 */
	public static function candidate_paths( $file, $metadata, $backup_sizes ) {
		$paths = array_keys( Mobo_Core_Legacy_Image_Policy::family_files( $file, $metadata ) );
		$file  = ltrim( str_replace( '\\', '/', (string) $file ), '/' );
		$dir   = dirname( $file );
		$pre   = ( '.' === $dir || '' === $dir ) ? '' : $dir . '/';
		foreach ( is_array( $backup_sizes ) ? $backup_sizes : array() as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$path = $pre . Mobo_Core_Legacy_Image_Policy::base_name( str_replace( '\\', '/', $size['file'] ) );
				if ( Mobo_Core_Legacy_Image_Policy::is_safe_relative_path( $path ) ) {
					$paths[] = $path;
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * unserialize() without objects (read-only parse of WordPress metadata).
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function safe_unserialize( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$trimmed = trim( $value );
		if ( 1 !== preg_match( '/^(?:a|s|i|b|d|N):/', $trimmed ) ) {
			return $value;
		}
		$data = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disabled.
		return ( false === $data && 'b:0;' !== $trimmed ) ? $value : $data;
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
	 * Gather ownership linkage and precise reference facts for a batch of marked attachments.
	 *
	 * @param int[] $ids Marked attachment IDs.
	 * @param array $meta Bulk meta of the batch.
	 * @param array $parents Attachment ID => post_parent.
	 * @return array
	 */
	private function gather_context( array $ids, array $meta, array $parents = array() ) {
		global $wpdb;
		$ctx = array( 'parentNonMobo' => array(), 'parentMobo' => array(), 'parentUnknown' => array(), 'refs' => array(), 'sharedFile' => array(), 'liveQueue' => array(), 'activeRefresh' => array(), 'validReplacement' => array(), 'guid' => array(), 'error' => '' );
		$id_list   = implode( ',', array_map( 'absint', $ids ) );
		$id_values = array_map( 'strval', array_map( 'absint', $ids ) );
		$in_values = implode( ',', array_fill( 0, count( $id_values ), '%s' ) );
		$fail      = static function () use ( $wpdb ) {
			return '' !== (string) $wpdb->last_error;
		};

		/* 0. Parent. Legacy Mobo downloads were inserted without a parent;
		 * "duplicate product" plugins copy attachments together with their
		 * img_guid meta and attach the copy to the merchant's product, so such a
		 * copy is merchant-owned. A Mobo product parent is ownership linkage. */
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
				} else {
					$ctx['parentMobo'][ $id ] = true;
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
				$hits  = Mobo_Core_Legacy_Image_Policy::parse_gallery( (string) $row['meta_value'] );
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

		/* 7. Term meta equal to the ID (category thumbnails etc.) and core site options. */
		if ( ! empty( $wpdb->termmeta ) ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value IN ({$in_values})", $id_values ) );
			if ( $fail() ) {
				$ctx['error'] = 'termmeta-query-failed';
				return $ctx;
			}
			foreach ( (array) $rows as $value ) {
				$ctx['refs'][ absint( $value ) ][] = array( 'kind' => 'termmeta', 'mobo' => null );
			}
		}
		$option_ids = array( absint( get_option( 'site_icon', 0 ) ), absint( get_option( 'woocommerce_placeholder_image', 0 ) ) );
		if ( function_exists( 'get_theme_mod' ) ) {
			$option_ids[] = absint( get_theme_mod( 'custom_logo', 0 ) );
		}
		foreach ( $option_ids as $oid ) {
			if ( $oid > 0 && isset( $wanted[ $oid ] ) ) {
				$ctx['refs'][ $oid ][] = array( 'kind' => 'options', 'mobo' => null );
			}
		}

		/* 8. WPML: an attachment with translations is linked to them. */
		if ( $this->wpml_table_exists() ) {
			$table = $wpdb->prefix . 'icl_translations';
			$rows  = $wpdb->get_results( "SELECT t.element_id, (SELECT COUNT(*) FROM {$table} t2 WHERE t2.trid = t.trid) AS members FROM {$table} t WHERE t.element_type = 'post_attachment' AND t.element_id IN ({$id_list})", ARRAY_A );
			if ( $fail() ) {
				$ctx['error'] = 'wpml-query-failed';
				return $ctx;
			}
			foreach ( (array) $rows as $row ) {
				if ( absint( $row['members'] ) > 1 ) {
					$ctx['refs'][ absint( $row['element_id'] ) ][] = array( 'kind' => 'translation', 'mobo' => null );
				}
			}
		}

		return $ctx;
	}

	/**
	 * Whether the WPML translations table exists (cached per request).
	 *
	 * @return bool
	 */
	private function wpml_table_exists() {
		global $wpdb;
		if ( null === $this->wpml_table ) {
			$table            = $wpdb->prefix . 'icl_translations';
			$found            = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$this->wpml_table = is_string( $found ) && strtolower( $found ) === strtolower( $table );
		}
		return $this->wpml_table;
	}

	/* ------------------------------------------------------------------ */
	/* Phase 2: site-wide reference index                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Describe every reference source of this site (core tables, plugin tables
	 * that exist, Elementor CSS directory) and read ACF media field names.
	 *
	 * @param array $state State.
	 * @return array{state:array,error:string}
	 */
	public function init_sources( array $state ) {
		global $wpdb;
		$sources   = array();
		$sources[] = array( 'id' => 'posts', 'kind' => 'posts', 'table' => $wpdb->posts, 'pk' => 'ID', 'owner' => 'ID', 'type' => 'post_type', 'cols' => array( 'post_content', 'post_excerpt' ), 'source' => 'content' );
		$sources[] = array( 'id' => 'postmeta', 'kind' => 'meta', 'table' => $wpdb->postmeta, 'pk' => 'meta_id', 'owner' => 'post_id', 'key' => 'meta_key', 'cols' => array( 'meta_value' ), 'source' => 'postmeta', 'self' => true );
		if ( ! empty( $wpdb->termmeta ) ) {
			$sources[] = array( 'id' => 'termmeta', 'kind' => 'meta', 'table' => $wpdb->termmeta, 'pk' => 'meta_id', 'owner' => 'term_id', 'key' => 'meta_key', 'cols' => array( 'meta_value' ), 'source' => 'termmeta' );
		}
		if ( ! empty( $wpdb->term_taxonomy ) ) {
			$sources[] = array( 'id' => 'term_taxonomy', 'kind' => 'termtax', 'table' => $wpdb->term_taxonomy, 'pk' => 'term_taxonomy_id', 'key' => 'taxonomy', 'cols' => array( 'description' ), 'source' => 'termmeta' );
		}
		if ( ! empty( $wpdb->usermeta ) ) {
			$sources[] = array( 'id' => 'usermeta', 'kind' => 'meta', 'table' => $wpdb->usermeta, 'pk' => 'umeta_id', 'owner' => 'user_id', 'key' => 'meta_key', 'cols' => array( 'meta_value' ), 'source' => 'usermeta' );
		}
		$sources[] = array( 'id' => 'options', 'kind' => 'options', 'table' => $wpdb->options, 'pk' => 'option_id', 'key' => 'option_name', 'cols' => array( 'option_value' ), 'source' => 'options' );

		foreach ( self::PLUGIN_TABLE_PATTERNS as $pattern => $source ) {
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $pattern ) . '%' ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return array( 'state' => $state, 'error' => 'plugin-table-detection-failed' );
			}
			foreach ( (array) $tables as $table ) {
				$table = (string) $table;
				if ( ! self::is_identifier( $table ) ) {
					continue;
				}
				$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
				if ( '' !== (string) $wpdb->last_error ) {
					return array( 'state' => $state, 'error' => 'plugin-table-columns-failed' );
				}
				$pk   = '';
				$text = array();
				foreach ( (array) $columns as $column ) {
					$name = isset( $column['Field'] ) ? (string) $column['Field'] : '';
					$type = strtolower( isset( $column['Type'] ) ? (string) $column['Type'] : '' );
					if ( ! self::is_identifier( $name ) ) {
						continue;
					}
					if ( '' === $pk && 'PRI' === ( isset( $column['Key'] ) ? (string) $column['Key'] : '' ) && 1 === preg_match( '/int/', $type ) ) {
						$pk = $name;
					} elseif ( 1 === preg_match( '/text|blob|json|^varchar\((?:[3-9]\d|\d{3,})\)/', $type ) ) {
						$text[] = $name;
					}
				}
				if ( '' !== $pk && ! empty( $text ) ) {
					$sources[] = array( 'id' => $table, 'kind' => 'plain', 'table' => $table, 'pk' => $pk, 'cols' => array_slice( $text, 0, 8 ), 'source' => $source );
				}
			}
		}

		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$basedir = isset( $uploads['basedir'] ) ? rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) : '';
		if ( '' !== $basedir && is_dir( $basedir . '/' . self::CSS_DIR ) ) {
			$sources[] = array( 'id' => 'elementor-css', 'kind' => 'css', 'source' => 'css' );
		}

		/* ACF image/gallery/file field names (field definitions stored in the DB). */
		$names = array();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT post_excerpt, post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field' ORDER BY ID ASC LIMIT %d", self::ACF_MAX_FIELDS + 1 ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return array( 'state' => $state, 'error' => 'acf-field-query-failed' );
		}
		foreach ( array_slice( (array) $rows, 0, self::ACF_MAX_FIELDS ) as $row ) {
			$settings = self::safe_unserialize( (string) $row['post_content'] );
			$type     = is_array( $settings ) && isset( $settings['type'] ) ? (string) $settings['type'] : '';
			$name     = trim( (string) $row['post_excerpt'] );
			if ( '' !== $name && in_array( $type, array( 'image', 'gallery', 'file', 'image_aspect_ratio_crop', 'photo_gallery' ), true ) ) {
				$names[ $name ] = 1;
			}
		}
		if ( count( (array) $rows ) > self::ACF_MAX_FIELDS ) {
			$state = self::mark_incomplete( $state, 'acf-field-limit' );
		}

		$state['sources']      = $sources;
		$state['acfNames']     = $names;
		$state['sourcesReady'] = true;
		$state['sourceIndex']  = 0;
		$state['sourceCursor'] = 0;
		$state['sourceMax']    = 0;
		return array( 'state' => $state, 'error' => '' );
	}

	/**
	 * One reference-pass page of the current source.
	 *
	 * @param array $state State.
	 * @return array{state:array,error:string}
	 */
	public function ref_step( array $state ) {
		if ( empty( $state['sourcesReady'] ) ) {
			return $this->init_sources( $state );
		}
		$index = absint( $state['sourceIndex'] );
		if ( $index >= count( $state['sources'] ) ) {
			$state['phase'] = 'finalize';
			return array( 'state' => $state, 'error' => '' );
		}
		$source = $state['sources'][ $index ];
		$lookup = $this->load_index( $state );
		if ( 'css' === $source['kind'] ) {
			$page = $this->css_page( (string) $state['sourceCursor'], $lookup );
		} else {
			$page = $this->rows_page( $source, absint( $state['sourceCursor'] ), absint( $state['sourceMax'] ), $lookup, is_array( $state['acfNames'] ) ? $state['acfNames'] : array() );
		}
		if ( '' !== (string) $page['error'] ) {
			return array( 'state' => $state, 'error' => (string) $page['error'] );
		}
		/* Hits first (bitwise union: replaying a page after a crash is harmless), then the cursor. */
		if ( ! empty( $page['hits'] ) ) {
			$hits    = get_option( self::HITS_OPTION, array() );
			$hits    = is_array( $hits ) ? $hits : array();
			$changed = false;
			foreach ( $page['hits'] as $id => $mask ) {
				$old = isset( $hits[ $id ] ) ? (int) $hits[ $id ] : 0;
				if ( ( $old | (int) $mask ) !== $old ) {
					$hits[ $id ] = $old | (int) $mask;
					$changed     = true;
				}
			}
			if ( $changed ) {
				update_option( self::HITS_OPTION, $hits, false );
			}
		}
		foreach ( $page['incomplete'] as $reason ) {
			$state = self::mark_incomplete( $state, $reason );
		}
		$state['refRows']  = absint( $state['refRows'] ) + absint( $page['rows'] );
		$state['refBytes'] = absint( $state['refBytes'] ) + absint( $page['bytes'] );
		$state['refPages'] = absint( $state['refPages'] ) + 1;
		if ( ! empty( $page['done'] ) ) {
			$state['sourceIndex']  = $index + 1;
			$state['sourceCursor'] = 0;
			$state['sourceMax']    = 0;
			if ( $state['sourceIndex'] >= count( $state['sources'] ) ) {
				$state['phase'] = 'finalize';
			}
		} else {
			$state['sourceCursor'] = $page['cursorEnd'];
			$state['sourceMax']    = absint( $page['max'] );
		}
		return array( 'state' => $state, 'error' => '' );
	}

	/**
	 * Record an incomplete-index reason (idempotent, bounded).
	 *
	 * @param array  $state State.
	 * @param string $reason Reason key (e.g. "oversized:wp_postmeta#123:5000000").
	 * @return array
	 */
	public static function mark_incomplete( array $state, $reason ) {
		$state['incomplete'] = true;
		$reasons             = is_array( $state['incompleteReasons'] ) ? $state['incompleteReasons'] : array();
		$reason              = substr( (string) $reason, 0, 120 );
		if ( ! isset( $reasons[ $reason ] ) && count( $reasons ) < self::INCOMPLETE_MAX ) {
			$reasons[ $reason ] = 1;
		}
		$state['incompleteReasons'] = $reasons;
		return $state;
	}

	/**
	 * Candidate lookup structures from the index chunks (cached per request).
	 *
	 * @param array $state State.
	 * @return array{ids:array,base:array,root:array,paths:array}
	 */
	public function load_index( array $state ) {
		$key = (string) $state['cycleId'] . ':' . absint( $state['pending'] );
		if ( null !== $this->index && $key === $this->index_key ) {
			return $this->index;
		}
		$index  = array( 'ids' => array(), 'base' => array(), 'root' => array(), 'paths' => array() );
		$chunks = (int) ceil( absint( $state['pending'] ) / self::CHUNK_SIZE );
		for ( $c = 0; $c < $chunks; $c++ ) {
			$entries = get_option( self::CAND_PREFIX . $c, array() );
			foreach ( is_array( $entries ) ? $entries : array() as $id => $entry ) {
				$id                  = absint( $id );
				$index['ids'][ $id ] = true;
				foreach ( isset( $entry['p'] ) && is_array( $entry['p'] ) ? $entry['p'] : array() as $path ) {
					$path = Mobo_Core_Legacy_Image_Policy::lower( str_replace( '\\', '/', (string) $path ) );
					$base = Mobo_Core_Legacy_Image_Policy::base_name( $path );
					$index['paths'][ $id ][ $path ]                                      = true;
					$index['base'][ $base ][ $id ]                                       = true;
					$index['root'][ Mobo_Core_Legacy_Image_Policy::basename_root( $base ) ][ $id ] = true;
				}
			}
		}
		$this->index     = $index;
		$this->index_key = $key;
		return $index;
	}

	/**
	 * Candidate IDs a set of referenced basenames points at.
	 *
	 * @param string[] $basenames Lower-cased basenames.
	 * @param array    $index Index.
	 * @return array<int,bool>
	 */
	public static function match_basenames( array $basenames, array $index ) {
		$out = array();
		foreach ( $basenames as $basename ) {
			$basename = Mobo_Core_Legacy_Image_Policy::lower( (string) $basename );
			if ( isset( $index['base'][ $basename ] ) ) {
				$out += $index['base'][ $basename ];
			}
			$root = Mobo_Core_Legacy_Image_Policy::basename_root( $basename );
			if ( isset( $index['root'][ $root ] ) ) {
				$out += $index['root'][ $root ];
			}
		}
		return $out;
	}

	/**
	 * Read one byte-budgeted keyset page of a table source and match it.
	 *
	 * @param array $source Source descriptor.
	 * @param int   $cursor Last processed primary key.
	 * @param int   $max Highest primary key of this source for the cycle (0 = not yet read).
	 * @param array $index Candidate index.
	 * @param array $acf ACF media field names.
	 * @return array{hits:array,cursorEnd:int,max:int,done:bool,rows:int,bytes:int,incomplete:array,error:string}
	 */
	public function rows_page( array $source, $cursor, $max, array $index, array $acf = array() ) {
		global $wpdb;
		$out = array( 'hits' => array(), 'cursorEnd' => absint( $cursor ), 'max' => absint( $max ), 'done' => false, 'rows' => 0, 'bytes' => 0, 'incomplete' => array(), 'error' => '' );
		$table = (string) $source['table'];
		$pk    = (string) $source['pk'];
		$cols  = isset( $source['cols'] ) && is_array( $source['cols'] ) ? array_values( $source['cols'] ) : array();
		foreach ( array_merge( array( $table, $pk ), $cols ) as $identifier ) {
			if ( ! self::is_identifier( $identifier ) ) {
				$out['error'] = 'invalid-source';
				return $out;
			}
		}
		if ( empty( $cols ) ) {
			$out['done'] = true;
			return $out;
		}
		if ( $out['max'] <= 0 ) {
			$out['max'] = absint( $wpdb->get_var( "SELECT MAX(`{$pk}`) FROM `{$table}`" ) );
			if ( '' !== (string) $wpdb->last_error ) {
				$out['error'] = 'source-max-failed';
				return $out;
			}
			if ( $out['max'] <= 0 ) {
				$out['done'] = true;
				return $out;
			}
		}
		$len_parts = array();
		foreach ( $cols as $col ) {
			$len_parts[] = "COALESCE(LENGTH(`{$col}`),0)";
		}
		$len   = implode( ' + ', $len_parts );
		$extra = '';
		if ( 'options' === $source['kind'] ) {
			/* Skipped server-side too, so a huge cache can never mark the index incomplete. */
			$likes = array();
			foreach ( array_merge( self::SKIP_OPTION_PREFIXES, self::SKIP_OPTION_REMOTE_PREFIXES ) as $prefix ) {
				$likes[] = $wpdb->esc_like( $prefix ) . '%';
			}
			$extra = $wpdb->prepare(
				str_repeat( ' AND `option_name` NOT LIKE %s', count( $likes ) ) . ' AND `option_name` NOT IN (' . implode( ',', array_fill( 0, count( self::SKIP_OPTIONS ), '%s' ) ) . ')',
				array_merge( $likes, self::SKIP_OPTIONS )
			);
		}
		$plan = $wpdb->get_results(
			$wpdb->prepare( "SELECT `{$pk}` AS pk, ({$len}) AS len FROM `{$table}` WHERE `{$pk}` > %d AND `{$pk}` <= %d{$extra} ORDER BY `{$pk}` ASC LIMIT %d", absint( $cursor ), $out['max'], self::PAGE_ROWS ),
			ARRAY_A
		);
		if ( ! is_array( $plan ) || '' !== (string) $wpdb->last_error ) {
			$out['error'] = 'source-plan-failed';
			return $out;
		}
		if ( empty( $plan ) ) {
			$out['done'] = true;
			return $out;
		}
		$end   = absint( $cursor );
		$bytes = 0;
		$taken = 0;
		foreach ( $plan as $row ) {
			$row_len = absint( $row['len'] );
			if ( $row_len > self::ROW_MAX_BYTES ) {
				/* Never loaded: the index is incomplete, every candidate becomes "unsure". */
				$out['incomplete'][] = 'oversized:' . $table . '#' . absint( $row['pk'] ) . ':' . $row_len;
				$end                 = absint( $row['pk'] );
				$taken++;
				continue;
			}
			if ( $bytes > 0 && $bytes + $row_len > self::PAGE_BYTES ) {
				break;
			}
			$bytes += $row_len;
			$end    = absint( $row['pk'] );
			$taken++;
		}
		$out['done']      = $taken === count( $plan ) && count( $plan ) < self::PAGE_ROWS;
		$out['cursorEnd'] = $end;
		$out['bytes']     = $bytes;

		$select = array( "`{$pk}` AS pk" );
		foreach ( array( 'owner' => 'owner_id', 'key' => 'k', 'type' => 't' ) as $field => $alias ) {
			if ( ! empty( $source[ $field ] ) ) {
				if ( ! self::is_identifier( (string) $source[ $field ] ) ) {
					$out['error'] = 'invalid-source';
					return $out;
				}
				$select[] = '`' . $source[ $field ] . "` AS {$alias}";
			}
		}
		foreach ( $cols as $i => $col ) {
			$select[] = "`{$col}` AS c{$i}";
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ' . implode( ', ', $select ) . " FROM `{$table}` WHERE `{$pk}` > %d AND `{$pk}` <= %d AND ({$len}) <= %d{$extra} ORDER BY `{$pk}` ASC", absint( $cursor ), $end, self::ROW_MAX_BYTES ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			$out['error'] = 'source-fetch-failed';
			return $out;
		}
		$out['rows'] = count( $rows );
		$maybe       = array();
		foreach ( $rows as $row ) {
			foreach ( $this->match_row( $source, $row, $index, $acf, $maybe ) as $id => $mask ) {
				$out['hits'][ $id ] = ( isset( $out['hits'][ $id ] ) ? $out['hits'][ $id ] : 0 ) | $mask;
			}
		}
		if ( ! empty( $maybe ) ) {
			$probe = $this->acf_probe( $source, $maybe );
			if ( null === $probe ) {
				$out['error'] = 'acf-probe-failed';
				return $out;
			}
			foreach ( $probe as $id => $mask ) {
				$out['hits'][ $id ] = ( isset( $out['hits'][ $id ] ) ? $out['hits'][ $id ] : 0 ) | $mask;
			}
		}
		return $out;
	}

	/**
	 * Match one row of a reference source against the candidate index.
	 *
	 * @param array $source Source descriptor.
	 * @param array $row Row (pk, owner_id, k, t, c0..cN).
	 * @param array $index Candidate index.
	 * @param array $acf ACF media field names.
	 * @param array $maybe Out: numeric values equal to a candidate ID under a non-media key (ACF probe).
	 * @return array<int,int> Candidate ID => hit mask.
	 */
	public function match_row( array $source, array $row, array $index, array $acf, array &$maybe ) {
		$P     = 'Mobo_Core_Legacy_Image_Policy';
		$kind  = (string) $source['kind'];
		$owner = isset( $row['owner_id'] ) ? absint( $row['owner_id'] ) : 0;
		$self  = ( 'posts' === $kind || ! empty( $source['self'] ) ) ? $owner : 0;
		$key   = isset( $row['k'] ) ? (string) $row['k'] : '';
		$texts = array();
		foreach ( $row as $field => $value ) {
			if ( 1 === preg_match( '/^c\d+$/', (string) $field ) && null !== $value && '' !== (string) $value ) {
				$texts[] = (string) $value;
			}
		}
		if ( empty( $texts ) || empty( $index['ids'] ) ) {
			return array();
		}
		$name = (string) $source['source'];
		$acf_key = false;
		if ( 'posts' === $kind ) {
			$type = isset( $row['t'] ) ? (string) $row['t'] : '';
			$name = 'revision' === $type ? 'revision' : ( in_array( $type, array( 'elementor_library', 'e-landing-page', 'elementor_snippet' ), true ) ? 'elementor' : 'content' );
		} elseif ( 'meta' === $kind ) {
			if ( in_array( $key, self::SKIP_META_KEYS, true ) || self::has_prefix( $key, self::SKIP_META_PREFIXES ) ) {
				return array();
			}
			if ( 'postmeta' === $source['id'] && in_array( $key, self::ATTACHMENT_FILE_KEYS, true ) ) {
				return $this->match_attachment_file_row( $key, $texts[0], $owner, $index );
			}
			$acf_key = $P::is_acf_media_key( $key, $acf );
			$name    = 0 === strpos( $key, '_elementor' ) ? 'elementor' : ( $acf_key ? 'acf' : $name );
		} elseif ( 'options' === $kind ) {
			if ( in_array( $key, self::SKIP_OPTIONS, true ) || self::has_prefix( $key, self::SKIP_OPTION_PREFIXES ) || self::has_prefix( $key, self::SKIP_OPTION_REMOTE_PREFIXES ) ) {
				return array();
			}
			$acf_key = $P::is_acf_media_key( $key, $acf );
			$name    = false !== stripos( $key, 'elementor' ) ? 'elementor' : ( $acf_key ? 'acf' : 'options' );
		} elseif ( 'termtax' === $kind && 'post_translations' === $key ) {
			/* Polylang: serialized lang => post_id group. A translated attachment is linked. */
			$group = $P::extract_value_ids( 'ids', $texts[0], true );
			$hits  = array();
			if ( count( $group ) > 1 ) {
				foreach ( $group as $id ) {
					if ( isset( $index['ids'][ $id ] ) ) {
						$hits[ $id ] = $P::source_bit( 'translation' );
					}
				}
			}
			return $hits;
		}
		$bit = $P::source_bit( $name );
		$ids = array();
		$bases = array();
		foreach ( $texts as $text ) {
			$found = $P::extract_content_references( $text );
			foreach ( $found['ids'] as $id ) {
				$ids[ $id ] = true;
			}
			foreach ( $found['basenames'] as $basename ) {
				$bases[ $basename ] = true;
			}
		}
		if ( '' !== $key && in_array( $kind, array( 'meta', 'options' ), true ) ) {
			foreach ( $P::extract_value_ids( $key, $texts[0], $acf_key ) as $id ) {
				$ids[ $id ] = true;
			}
			$plain = trim( $texts[0] );
			if ( ! $acf_key && ! $P::is_media_key( $key ) && 1 === preg_match( '/^\d{1,19}$/', $plain ) && isset( $index['ids'][ (int) $plain ] ) && (int) $plain !== $self ) {
				/* Numeric value equal to a candidate under an unknown key: a reference only
				 * when ACF declares the key (`_key` => field_xxx), checked in one query per page. */
				$maybe[] = array( 'owner' => $owner, 'key' => $key, 'id' => (int) $plain );
			}
		}
		$hits = array();
		foreach ( array_keys( $ids ) as $id ) {
			if ( isset( $index['ids'][ $id ] ) && $id !== $self ) {
				$hits[ $id ] = $bit;
			}
		}
		foreach ( array_keys( self::match_basenames( array_keys( $bases ), $index ) ) as $id ) {
			if ( $id !== $self ) {
				$hits[ $id ] = $bit;
			}
		}
		return $hits;
	}

	/**
	 * Another attachment's file meta: a hit only when it names the exact same
	 * relative path as a candidate's family file (same physical file).
	 *
	 * @param string $key Meta key.
	 * @param string $value Raw value.
	 * @param int    $owner Owning attachment ID.
	 * @param array  $index Candidate index.
	 * @return array<int,int>
	 */
	private function match_attachment_file_row( $key, $value, $owner, array $index ) {
		$paths = array();
		if ( '_wp_attached_file' === $key ) {
			$paths[] = (string) $value;
		} elseif ( '_wp_attachment_metadata' === $key ) {
			$data = self::safe_unserialize( $value );
			if ( is_array( $data ) && ! empty( $data['file'] ) ) {
				$paths = array_keys( Mobo_Core_Legacy_Image_Policy::family_files( (string) $data['file'], $data ) );
			}
		} else {
			/* Backup sizes hold basenames only: same basename in a candidate's family protects it. */
			$data = self::safe_unserialize( $value );
			$bases = array();
			foreach ( is_array( $data ) ? $data : array() as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$bases[] = Mobo_Core_Legacy_Image_Policy::lower( Mobo_Core_Legacy_Image_Policy::base_name( $size['file'] ) );
				}
			}
			$hits = array();
			foreach ( $bases as $base ) {
				foreach ( isset( $index['base'][ $base ] ) ? array_keys( $index['base'][ $base ] ) : array() as $id ) {
					if ( $id !== $owner ) {
						$hits[ $id ] = Mobo_Core_Legacy_Image_Policy::source_bit( 'sharedfile' );
					}
				}
			}
			return $hits;
		}
		$hits = array();
		foreach ( $paths as $path ) {
			$path = Mobo_Core_Legacy_Image_Policy::lower( ltrim( str_replace( '\\', '/', (string) $path ), '/' ) );
			$base = Mobo_Core_Legacy_Image_Policy::base_name( $path );
			foreach ( isset( $index['base'][ $base ] ) ? array_keys( $index['base'][ $base ] ) : array() as $id ) {
				if ( $id !== $owner && isset( $index['paths'][ $id ][ $path ] ) ) {
					$hits[ $id ] = Mobo_Core_Legacy_Image_Policy::source_bit( 'sharedfile' );
				}
			}
		}
		return $hits;
	}

	/**
	 * Resolve numeric values under unknown keys: an ACF value row `key` has a
	 * sibling `_key` whose value is an ACF field key (`field_...`).
	 *
	 * @param array $source Source descriptor.
	 * @param array $maybe Items {owner, key, id}.
	 * @return array<int,int>|null Hits; null on DB error.
	 */
	private function acf_probe( array $source, array $maybe ) {
		global $wpdb;
		$maybe = array_slice( $maybe, 0, 500 );
		$keys  = array();
		foreach ( $maybe as $item ) {
			$keys[ '_' . $item['key'] ] = true;
		}
		$keys = array_keys( $keys );
		$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$bit  = Mobo_Core_Legacy_Image_Policy::source_bit( 'acf' );
		$hits = array();
		if ( 'options' === $source['kind'] ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ({$ph}) AND option_value LIKE %s", array_merge( $keys, array( 'field\_%' ) ) ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return null;
			}
			$found = array_flip( array_map( 'strval', (array) $rows ) );
			foreach ( $maybe as $item ) {
				if ( isset( $found[ '_' . $item['key'] ] ) ) {
					$hits[ $item['id'] ] = $bit;
				}
			}
			return $hits;
		}
		$table = (string) $source['table'];
		$owner = (string) $source['owner'];
		if ( ! self::is_identifier( $table ) || ! self::is_identifier( $owner ) ) {
			return null;
		}
		$owners = array_values( array_unique( array_map( 'absint', array_column( $maybe, 'owner' ) ) ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT `{$owner}` AS owner_id, meta_key FROM `{$table}` WHERE `{$owner}` IN (" . implode( ',', $owners ) . ") AND meta_key IN ({$ph}) AND meta_value LIKE %s", array_merge( $keys, array( 'field\_%' ) ) ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$found = array();
		foreach ( $rows as $row ) {
			$found[ absint( $row['owner_id'] ) . '|' . (string) $row['meta_key'] ] = true;
		}
		foreach ( $maybe as $item ) {
			if ( isset( $found[ absint( $item['owner'] ) . '|_' . $item['key'] ] ) ) {
				$hits[ $item['id'] ] = $bit;
			}
		}
		return $hits;
	}

	/**
	 * One page of Elementor generated CSS files (uploads/elementor/css/*.css),
	 * cursor = last processed file name. Oversized/unreadable files mark the
	 * index incomplete.
	 *
	 * @param string $cursor Last processed file name ('' / '0' = start).
	 * @param array  $index Candidate index.
	 * @return array{hits:array,cursorEnd:string,max:int,done:bool,rows:int,bytes:int,incomplete:array,error:string}
	 */
	public function css_page( $cursor, array $index ) {
		$out     = array( 'hits' => array(), 'cursorEnd' => (string) $cursor, 'max' => 0, 'done' => true, 'rows' => 0, 'bytes' => 0, 'incomplete' => array(), 'error' => '' );
		$cursor  = '0' === (string) $cursor ? '' : (string) $cursor;
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$basedir = isset( $uploads['basedir'] ) ? rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' ) : '';
		$dir     = $basedir . '/' . self::CSS_DIR;
		if ( '' === $basedir || ! is_dir( $dir ) ) {
			return $out;
		}
		$files = glob( $dir . '/*.css', GLOB_NOSORT );
		if ( false === $files ) {
			$out['incomplete'][] = 'css-directory-unreadable';
			return $out;
		}
		$names = array();
		foreach ( $files as $file ) {
			$names[] = Mobo_Core_Legacy_Image_Policy::base_name( $file );
		}
		sort( $names, SORT_STRING );
		if ( count( $names ) > self::CSS_MAX_FILES ) {
			$out['incomplete'][] = 'css-file-limit';
			$names               = array_slice( $names, 0, self::CSS_MAX_FILES );
		}
		$bit   = Mobo_Core_Legacy_Image_Policy::source_bit( 'css' );
		$count = 0;
		foreach ( $names as $i => $name ) {
			if ( '' !== $cursor && strcmp( $name, $cursor ) <= 0 ) {
				continue;
			}
			if ( $count >= self::CSS_FILES_PER_PAGE || ( $out['bytes'] > 0 && $out['bytes'] >= self::PAGE_BYTES ) ) {
				$out['done'] = false;
				break;
			}
			$count++;
			$out['cursorEnd'] = $name;
			$path             = $dir . '/' . $name;
			$size             = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- read-only probe.
			if ( false === $size ) {
				$out['incomplete'][] = 'css-unreadable:' . $name;
				continue;
			}
			if ( $size > self::CSS_MAX_FILE_BYTES ) {
				$out['incomplete'][] = 'css-oversized:' . $name;
				continue;
			}
			$text = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local read.
			if ( false === $text ) {
				$out['incomplete'][] = 'css-unreadable:' . $name;
				continue;
			}
			$out['rows']++;
			$out['bytes'] += strlen( $text );
			$found         = Mobo_Core_Legacy_Image_Policy::extract_content_references( $text );
			foreach ( array_keys( self::match_basenames( $found['basenames'], $index ) ) as $id ) {
				$out['hits'][ $id ] = $bit;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Phase 3: finalize                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Classify and count one candidate chunk.
	 *
	 * @param array $state State.
	 * @return array{state:array,error:string}
	 */
	public function finalize_step( array $state ) {
		$chunks = (int) ceil( absint( $state['pending'] ) / self::CHUNK_SIZE );
		$chunk  = absint( $state['finalizeChunk'] );
		if ( $chunk >= $chunks ) {
			$state['status']      = 'complete';
			$state['completedAt'] = time();
			return array( 'state' => $state, 'error' => '' );
		}
		$entries    = get_option( self::CAND_PREFIX . $chunk, array() );
		$hits       = get_option( self::HITS_OPTION, array() );
		$hits       = is_array( $hits ) ? $hits : array();
		$incomplete = ! empty( $state['incomplete'] );
		$items      = array();
		foreach ( is_array( $entries ) ? $entries : array() as $id => $entry ) {
			$verdict = Mobo_Core_Legacy_Image_Policy::finalize_candidate( (string) $entry['c'], isset( $hits[ $id ] ) ? (int) $hits[ $id ] : 0, $incomplete );
			$items[] = array(
				'id'        => absint( $id ),
				'class'     => $verdict['class'],
				'reason'    => '' !== $verdict['reason'] ? $verdict['reason'] : (string) $entry['r'],
				'file'      => (string) $entry['f'],
				'bytes'     => absint( $entry['b'] ),
				'metaBytes' => absint( $entry['mb'] ),
				'files'     => absint( $entry['n'] ),
				'missing'   => absint( $entry['m'] ),
				'sources'   => $verdict['sources'],
			);
		}
		$state                  = $this->merge_batch( $state, array( 'items' => $items, 'scanned' => 0 ) );
		$state['finalizeChunk'] = $chunk + 1;
		if ( $state['finalizeChunk'] >= $chunks ) {
			$state['status']      = 'complete';
			$state['completedAt'] = time();
		}
		return array( 'state' => $state, 'error' => '' );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether a key starts with one of the prefixes.
	 *
	 * @param string   $key Key.
	 * @param string[] $prefixes Prefixes.
	 * @return bool
	 */
	private static function has_prefix( $key, array $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( (string) $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Safe SQL identifier (table/column name).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private static function is_identifier( $name ) {
		return 1 === preg_match( '/^[A-Za-z0-9_$]{1,64}$/', (string) $name );
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
