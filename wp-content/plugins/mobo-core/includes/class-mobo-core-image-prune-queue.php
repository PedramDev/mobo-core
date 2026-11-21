<?php
/**
 * Durable, bounded cleanup queue for stale Mobo image attachments.
 *
 * Product/Repair sync only detaches stale media and enqueues cleanup debt. The
 * expensive cross-site reference proof is resumed here in small primary-key
 * slices so a large wp_postmeta/wp_options table cannot hold manual_sync for a
 * full PHP max_execution_time window.
 *
 * Identity policy is GUID-first:
 * - image_guid is canonical when present.
 * - img_guid is the legacy compatibility identity.
 * - when both exist they must agree case-insensitively.
 * - mobo_source_url alone never proves destructive ownership.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Image_Prune_Queue {

	const LEGACY_SEED_STATE_OPTION = 'mobo_core_image_prune_legacy_seed_state';
	const SCAN_BATCH_SIZE          = 80;

	/** @var bool|null */
	private static $table_exists_cache = null;

	/** @var array<int,array> Per-Connection request-local summary cache. */
	private static $summary_cache = array();

	/** Return table name. */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mobo_image_prune_queue';
	}

	/** Active Source owner for Source-scoped cleanup debt. */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Whether the active context is the enabled Default Connection. */
	private static function is_default_connection_context() {
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return false;
		}
		$default = Mobo_Core_Connection_Repository::get_default();
		return is_array( $default ) && $connection_id === absint( isset( $default['id'] ) ? $default['id'] : 0 );
	}

	/** Create/update durable cleanup table. */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = Mobo_Core_Database_Charset::schema_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL,
			owner_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			image_guid varchar(191) NOT NULL DEFAULT '',
			legacy_img_guid varchar(191) NOT NULL DEFAULT '',
			source_url text NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			phase varchar(24) NOT NULL DEFAULT 'postmeta',
			postmeta_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			option_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			post_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			proof_pass tinyint(3) unsigned NOT NULL DEFAULT 0,
			try_count int(10) unsigned NOT NULL DEFAULT 0,
			next_retry_at datetime NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY attachment_id (connection_id, attachment_id),
			KEY status_retry_id (connection_id, status, next_retry_at, id),
			KEY image_guid (connection_id, image_guid),
			KEY owner_product (connection_id, owner_product_id, status),
			KEY updated_id (connection_id, updated_at, id),
			KEY status_updated_id (connection_id, status, updated_at, id),
			KEY attachment_lookup (attachment_id)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
		self::$summary_cache      = array();
	}

	/** Whether the durable cleanup table exists. */
	public static function table_exists() {
		global $wpdb;
		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}
		$table = self::table_name();
		self::$table_exists_cache = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (bool) self::$table_exists_cache;
	}

	private static function invalidate_summary() {
		self::$summary_cache = array();
	}

	/** Compact queue summary for scheduler/admin health. */
	public function get_summary() {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::table_exists() ) {
			return array( 'pending' => 0, 'due' => 0, 'failed' => 0, 'retained' => 0 );
		}
		if ( isset( self::$summary_cache[ $connection_id ] ) && is_array( self::$summary_cache[ $connection_id ] ) ) {
			return self::$summary_cache[ $connection_id ];
		}
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		/* Query only this Source's active/evidence states through Connection-first
		 * indexes. Historical done rows are intentionally excluded from health. */
		$pending  = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id = %d AND status = 'pending'", $connection_id ) ) );
		$due      = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id = %d AND status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s)", $connection_id, $now ) ) );
		$failed   = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id = %d AND status = 'failed'", $connection_id ) ) );
		$retained = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id = %d AND status = 'retained'", $connection_id ) ) );
		self::$summary_cache[ $connection_id ] = array(
			'pending'  => $pending,
			'due'      => $due,
			'failed'   => $failed,
			'retained' => $retained,
		);
		return self::$summary_cache[ $connection_id ];
	}

	/** Cheap due-work predicate used by the fair scheduler. */
	public function has_due_work() {
		$summary = $this->get_summary();
		return absint( $summary['due'] ) > 0 || $this->legacy_seed_pending();
	}

	/** Enqueue stale attachment IDs after product linkage has been detached. */
	public function enqueue_attachments( $owner_product_id, $attachment_ids ) {
		$connection_id    = self::connection_id();
		$owner_product_id = absint( $owner_product_id );
		$attachment_ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) $attachment_ids ) ) ) );
		$result = array( 'queued' => 0, 'retained' => 0, 'skipped' => 0 );
		if ( $connection_id <= 0 || empty( $attachment_ids ) || ! self::table_exists() ) {
			return $result;
		}
		foreach ( $attachment_ids as $attachment_id ) {
			$outcome = $this->enqueue_attachment( $owner_product_id, $attachment_id );
			if ( isset( $result[ $outcome ] ) ) {
				$result[ $outcome ]++;
			} else {
				$result['skipped']++;
			}
		}
		return $result;
	}

	/** Queue one candidate using GUID-first ownership evidence. */
	private function enqueue_attachment( $owner_product_id, $attachment_id ) {
		global $wpdb;
		$connection_id = self::connection_id();
		$attachment_id = absint( $attachment_id );
		if ( $connection_id <= 0 || $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return 'skipped';
		}

		$identity = $this->read_attachment_identity( $attachment_id );
		$status   = 'pending';
		$error    = '';
		if ( ! empty( $identity['conflict'] ) ) {
			$status = 'retained';
			$error  = 'Mobo image identity conflict: image_guid and img_guid disagree.';
		} elseif ( ! empty( $identity['invalid'] ) ) {
			$status = 'retained';
			$error  = 'Invalid image_guid/img_guid ownership identity; destructive cleanup refused.';
		} elseif ( '' === $identity['canonical'] ) {
			$status = 'retained';
			$error  = 'No image_guid/img_guid ownership proof; destructive cleanup refused.';
		} elseif ( class_exists( 'Mobo_Core_Shared_Media' ) && method_exists( 'Mobo_Core_Shared_Media', 'is_shared_attachment' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			$status = 'retained';
			$error  = 'Shared Media attachment is centrally owned; local physical deletion refused.';
		}

		$table    = self::table_name();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id = %d AND attachment_id = %d LIMIT 1", $connection_id, $attachment_id ), ARRAY_A );
		$now      = current_time( 'mysql', true );
		$data = array(
			'connection_id'    => $connection_id,
			'owner_product_id' => absint( $owner_product_id ),
			'image_guid'       => $identity['canonical'],
			'legacy_img_guid'  => $identity['legacy'],
			'source_url'       => $identity['source_url'],
			'status'           => $status,
			'last_error'       => $error,
			'updated_at'       => $now,
		);

		$reset_scan = ! is_array( $existing )
			|| 'pending' !== sanitize_key( (string) ( isset( $existing['status'] ) ? $existing['status'] : '' ) )
			|| strtolower( trim( (string) ( isset( $existing['image_guid'] ) ? $existing['image_guid'] : '' ) ) ) !== $identity['canonical'];
		if ( $reset_scan ) {
			$data['phase']           = 'postmeta';
			$data['postmeta_cursor'] = 0;
			$data['option_cursor']   = 0;
			$data['post_cursor']     = 0;
			$data['proof_pass']      = 0;
			$data['try_count']       = 0;
			$data['next_retry_at']   = null;
		}

		if ( is_array( $existing ) ) {
			$ok = false !== $wpdb->update( $table, $data, array( 'id' => absint( $existing['id'] ), 'connection_id' => $connection_id ) );
		} else {
			$data['attachment_id'] = $attachment_id;
			$data['created_at']    = $now;
			$ok = false !== $wpdb->insert( $table, $data );
		}
		if ( ! $ok ) {
			return 'skipped';
		}
		self::invalidate_summary();
		return 'pending' === $status ? 'queued' : 'retained';
	}

	/** Process a bounded cleanup slice. */
	public function process_due( $limit = 2, $time_budget_seconds = 2 ) {
		global $wpdb;
		$limit               = max( 1, min( 10, absint( $limit ) ) );
		$time_budget_seconds = max( 1, min( 10, absint( $time_budget_seconds ) ) );
		$result = array( 'processed' => 0, 'deleted' => 0, 'retained' => 0, 'failed' => 0, 'status' => 'empty', 'remaining' => false );
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::table_exists() ) {
			$result['status'] = 'unavailable';
			return $result;
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'image_prune_worker', 120 ) : 'no-lock';
		if ( false === $lock ) {
			$result['status']    = 'locked';
			$result['remaining'] = true;
			return $result;
		}

		$deadline = microtime( true ) + $time_budget_seconds;
		try {
			$this->seed_legacy_candidates( min( 30, self::SCAN_BATCH_SIZE ) );
			$this->cleanup_historical_rows();
			$table = self::table_name();
			$now   = current_time( 'mysql', true );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE connection_id = %d AND status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s) ORDER BY id ASC LIMIT %d",
					$connection_id,
					$now,
					$limit
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( microtime( true ) >= ( $deadline - 0.10 ) ) {
					break;
				}
				$outcome = $this->process_candidate_slice( $row );
				$result['processed']++;
				if ( 'processed' !== $outcome && isset( $result[ $outcome ] ) ) {
					$result[ $outcome ]++;
				}
			}
			$summary = $this->get_summary();
			$result['remaining'] = absint( $summary['pending'] ) > 0 || $this->legacy_seed_pending();
			$result['status']    = $result['processed'] > 0 ? 'processed' : ( $result['remaining'] ? 'deferred' : 'empty' );
			$result['summary']   = $summary;
			return $result;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'image_prune_worker', $lock );
			}
		}
	}

	/** Execute one PK-bounded scan slice for a candidate. */
	private function process_candidate_slice( $row ) {
		global $wpdb;
		$id            = absint( isset( $row['id'] ) ? $row['id'] : 0 );
		$attachment_id = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
		if ( $id <= 0 || $attachment_id <= 0 ) {
			return 'failed';
		}
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			$this->mark_status( $id, 'done', 'Attachment no longer exists.' );
			return 'deleted';
		}

		$identity = $this->read_attachment_identity( $attachment_id );
		$stored   = strtolower( trim( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) ) );
		if ( ! empty( $identity['conflict'] ) || '' === $identity['canonical'] || ( '' !== $stored && ! hash_equals( $stored, $identity['canonical'] ) ) ) {
			$this->mark_status( $id, 'retained', 'Image GUID identity changed, disappeared, or conflicts; cleanup refused.' );
			return 'retained';
		}

		$fast_reason = $this->fast_live_reference_reason( $attachment_id, $identity['canonical'] );
		if ( '' !== $fast_reason ) {
			$this->mark_status( $id, 'retained', $fast_reason );
			return 'retained';
		}

		$needles = $this->attachment_reference_needles( $attachment_id );
		$phase   = sanitize_key( (string) ( isset( $row['phase'] ) ? $row['phase'] : 'postmeta' ) );
		if ( 'postmeta' === $phase ) {
			$cursor = absint( isset( $row['postmeta_cursor'] ) ? $row['postmeta_cursor'] : 0 );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_id > %d AND post_id <> %d
					 AND meta_key NOT IN ('_mobo_pruned_cleanup_pending','_mobo_pruned_cleanup_owner_ids','_wp_attachment_metadata','_wp_attachment_backup_sizes','image_guid','img_guid','mobo_source_url')
					 ORDER BY meta_id ASC LIMIT %d",
					$cursor,
					$attachment_id,
					self::SCAN_BATCH_SIZE
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $scan_row ) {
				if ( $this->storage_value_references_attachment( isset( $scan_row['meta_key'] ) ? $scan_row['meta_key'] : '', isset( $scan_row['meta_value'] ) ? $scan_row['meta_value'] : '', $attachment_id, $needles ) ) {
					$this->mark_status( $id, 'retained', 'Referenced by post metadata.' );
					return 'retained';
				}
			}
			if ( count( $rows ) >= self::SCAN_BATCH_SIZE ) {
				$last = end( $rows );
				$this->update_cursor( $id, 'postmeta_cursor', absint( $last['meta_id'] ) );
				return 'processed';
			}
			$this->advance_phase( $id, 'options' );
			return 'processed';
		}

		if ( 'options' === $phase ) {
			$cursor = absint( isset( $row['option_cursor'] ) ? $row['option_cursor'] : 0 );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT %d",
					$cursor,
					self::SCAN_BATCH_SIZE
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $scan_row ) {
				if ( $this->storage_value_references_attachment( isset( $scan_row['option_name'] ) ? $scan_row['option_name'] : '', isset( $scan_row['option_value'] ) ? $scan_row['option_value'] : '', $attachment_id, $needles ) ) {
					$this->mark_status( $id, 'retained', 'Referenced by WordPress option data.' );
					return 'retained';
				}
			}
			if ( count( $rows ) >= self::SCAN_BATCH_SIZE ) {
				$last = end( $rows );
				$this->update_cursor( $id, 'option_cursor', absint( $last['option_id'] ) );
				return 'processed';
			}
			$this->advance_phase( $id, 'posts' );
			return 'processed';
		}

		if ( 'posts' === $phase ) {
			$cursor = absint( isset( $row['post_cursor'] ) ? $row['post_cursor'] : 0 );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content, post_excerpt, guid FROM {$wpdb->posts}
					 WHERE ID > %d AND ID <> %d AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d",
					$cursor,
					$attachment_id,
					self::SCAN_BATCH_SIZE
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $scan_row ) {
				$text = (string) ( isset( $scan_row['post_content'] ) ? $scan_row['post_content'] : '' ) . "\n" . (string) ( isset( $scan_row['post_excerpt'] ) ? $scan_row['post_excerpt'] : '' ) . "\n" . (string) ( isset( $scan_row['guid'] ) ? $scan_row['guid'] : '' );
				if ( $this->text_references_attachment( $text, $attachment_id, $needles, true ) ) {
					$this->mark_status( $id, 'retained', 'Referenced by post content.' );
					return 'retained';
				}
			}
			if ( count( $rows ) >= self::SCAN_BATCH_SIZE ) {
				$last = end( $rows );
				$this->update_cursor( $id, 'post_cursor', absint( $last['ID'] ) );
				return 'processed';
			}
			$proof_pass = absint( isset( $row['proof_pass'] ) ? $row['proof_pass'] : 0 );
			if ( $proof_pass < 1 ) {
				/* Two complete bounded passes reduce the race window between a long-running
				 * cross-store proof and the final destructive boundary. The second pass is
				 * resumable exactly like the first and still never performs wildcard SQL. */
				$wpdb->update(
					self::table_name(),
					array(
						'phase'           => 'postmeta',
						'postmeta_cursor' => 0,
						'option_cursor'   => 0,
						'post_cursor'     => 0,
						'proof_pass'      => 1,
						'updated_at'      => current_time( 'mysql', true ),
					),
					array( 'id' => $id, 'connection_id' => self::connection_id() )
				);
				return 'processed';
			}
			$this->advance_phase( $id, 'finalize' );
			return 'processed';
		}

		/* Final destructive boundary: revalidate identity and all fast references. */
		$identity = $this->read_attachment_identity( $attachment_id );
		if ( ! empty( $identity['conflict'] ) || '' === $identity['canonical'] || ! hash_equals( $stored, $identity['canonical'] ) ) {
			$this->mark_status( $id, 'retained', 'Image identity changed before delete boundary.' );
			return 'retained';
		}
		$fast_reason = $this->fast_live_reference_reason( $attachment_id, $identity['canonical'] );
		if ( '' !== $fast_reason ) {
			$this->mark_status( $id, 'retained', $fast_reason );
			return 'retained';
		}

		/*
		 * wp_delete_attachment() removes the database row even when WordPress refuses
		 * to unlink a file outside uploads. Preserve the stronger Mobo ownership
		 * boundary here: a non-empty attachment path must be provably inside the
		 * current uploads tree before any destructive database/file cleanup begins.
		 * A genuinely missing file is still eligible for database cleanup when its
		 * registered path is safely rooted under uploads.
		 */
		$path_reason = $this->attachment_delete_path_safety_reason( $attachment_id );
		if ( '' !== $path_reason ) {
			$this->mark_status( $id, 'retained', $path_reason );
			return 'retained';
		}

		/* Freeze every enabled Connection's image mutation workers at the final
		 * physical boundary. The bounded proof above may take multiple requests; a
		 * second Source must not attach/refresh this shared WordPress attachment in
		 * the narrow interval between the final live-reference check and delete. */
		$worker_locks = $this->acquire_all_image_worker_locks( 120 );
		if ( false === $worker_locks ) {
			$this->defer_without_penalty( $id, 30, 'Image workers are active; physical delete boundary deferred.' );
			return 'processed';
		}

		$physical_lock_name = 'image_physical_delete_' . $attachment_id;
		$physical_lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( $physical_lock_name, 120 ) : '__mobo_no_lock__';
		if ( false === $physical_lock ) {
			$this->release_all_image_worker_locks( $worker_locks );
			$this->defer_without_penalty( $id, 30, 'Another physical media delete owns this attachment; deferred.' );
			return 'processed';
		}

		try {
			/* Revalidate under the global media barrier; checks made before acquiring
			 * the locks are not sufficient for a destructive shared-file operation. */
			$identity = $this->read_attachment_identity( $attachment_id );
			if ( ! empty( $identity['conflict'] ) || '' === $identity['canonical'] || ! hash_equals( $stored, $identity['canonical'] ) ) {
				$this->mark_status( $id, 'retained', 'Image identity changed at locked delete boundary.' );
				return 'retained';
			}
			$fast_reason = $this->fast_live_reference_reason( $attachment_id, $identity['canonical'] );
			if ( '' !== $fast_reason ) {
				$this->mark_status( $id, 'retained', $fast_reason );
				return 'retained';
			}
			$path_reason = $this->attachment_delete_path_safety_reason( $attachment_id );
			if ( '' !== $path_reason ) {
				$this->mark_status( $id, 'retained', $path_reason );
				return 'retained';
			}

			$deleted = wp_delete_attachment( $attachment_id, true );
			if ( false !== $deleted || 'attachment' !== get_post_type( $attachment_id ) ) {
				$this->mark_status( $id, 'done', 'Stale Mobo attachment deleted after bounded reference proof.' );
				delete_post_meta( $attachment_id, '_mobo_pruned_cleanup_owner_ids' );
				return 'deleted';
			}

			$tries = absint( isset( $row['try_count'] ) ? $row['try_count'] : 0 ) + 1;
			if ( $tries >= 3 ) {
				$this->mark_status( $id, 'failed', 'WordPress refused permanent attachment deletion after three attempts.', $tries );
				return 'failed';
			}
			$this->schedule_retry( $id, $tries, 'WordPress refused permanent attachment deletion.' );
			return 'failed';
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && '__mobo_no_lock__' !== $physical_lock ) {
				Mobo_Core_Lock::release( $physical_lock_name, $physical_lock );
			}
			$this->release_all_image_worker_locks( $worker_locks );
		}
	}

	/** GUID-first identity reader. */
	private function read_attachment_identity( $attachment_id ) {
		$primary_raw = trim( sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) ) );
		$legacy_raw  = trim( sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) ) );
		$source      = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );

		$primary_valid = '' !== $primary_raw && ( ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || Mobo_Core_Remote_Identity_Policy::is_valid( $primary_raw ) );
		$legacy_valid  = '' !== $legacy_raw && ( ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || Mobo_Core_Remote_Identity_Policy::is_valid( $legacy_raw ) );
		$primary       = $primary_valid ? strtolower( $primary_raw ) : '';
		$legacy        = $legacy_valid ? strtolower( $legacy_raw ) : '';
		$invalid       = ( '' !== $primary_raw && ! $primary_valid ) || ( '' !== $legacy_raw && ! $legacy_valid );
		$conflict      = '' !== $primary && '' !== $legacy && ! hash_equals( $primary, $legacy );

		return array(
			'primary'    => $primary,
			'legacy'     => $legacy,
			'canonical'  => ! $invalid && ! $conflict ? ( '' !== $primary ? $primary : $legacy ) : '',
			'source_url' => $source,
			'conflict'   => $conflict,
			'invalid'    => $invalid,
		);
	}

	/** Fast indexed/exact live-reference checks before any broad bounded scan. */
	private function fast_live_reference_reason( $attachment_id, $image_guid ) {
		global $wpdb;
		$connection_id = self::connection_id();
		$attachment_id = absint( $attachment_id );
		$image_guid    = strtolower( trim( sanitize_text_field( (string) $image_guid ) ) );
		if ( $connection_id <= 0 || $attachment_id <= 0 || '' === $image_guid ) {
			return 'Missing GUID identity at live-reference boundary.';
		}
		if ( class_exists( 'Mobo_Core_Shared_Media' ) && method_exists( 'Mobo_Core_Shared_Media', 'is_shared_attachment' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			return 'Shared Media attachment is still centrally owned.';
		}
		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$table = Mobo_Core_Image_Queue::table_name();
			$live = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE (connection_id = %d AND image_guid = %s) OR attachment_id = %d LIMIT 1", $connection_id, $image_guid, $attachment_id ) );
			if ( absint( $live ) > 0 ) {
				return 'Image GUID/attachment is still present in the authoritative Mobo image desired-state queue.';
			}
		}
		if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) && Mobo_Core_Image_Refresh_Queue::table_exists() ) {
			$table = Mobo_Core_Image_Refresh_Queue::table_name();
			$live = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE status IN ('pending','processing') AND ((connection_id = %d AND image_guid = %s) OR old_attachment_id = %d OR new_attachment_id = %d) LIMIT 1",
					$connection_id,
					$image_guid,
					$attachment_id,
					$attachment_id
				)
			);
			if ( absint( $live ) > 0 ) {
				return 'Image is still involved in an active Mobo refresh operation.';
			}
		}

		$thumbnail = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) $attachment_id ) );
		if ( absint( $thumbnail ) > 0 ) {
			return 'Attachment is still used as a WordPress/WooCommerce featured image.';
		}
		$gallery = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, REPLACE(meta_value, ' ', '')) > 0 LIMIT 1", (string) $attachment_id ) );
		if ( absint( $gallery ) > 0 ) {
			return 'Attachment is still present in a WooCommerce product gallery.';
		}
		if ( absint( get_option( 'site_icon', 0 ) ) === $attachment_id ) {
			return 'Attachment is the current WordPress site icon.';
		}
		return '';
	}

	/**
	 * Refuse destructive attachment cleanup unless the registered physical path is
	 * provably rooted inside the current WordPress uploads directory.
	 *
	 * An empty attached-file path is treated as database-only stale attachment debt
	 * and is allowed through: there are no registered main-file bytes to escape the
	 * uploads boundary. For a non-empty missing path, lexical containment plus the
	 * nearest existing parent's realpath must still prove the uploads boundary.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Empty when safe, otherwise a retention reason.
	 */
	private function attachment_delete_path_safety_reason( $attachment_id ) {
		$file = get_attached_file( absint( $attachment_id ) );
		$file = is_string( $file ) ? trim( $file ) : '';
		if ( '' === $file ) {
			return '';
		}
		if ( false !== strpos( $file, "\0" ) ) {
			return 'Attachment file path is invalid; destructive cleanup refused.';
		}

		$file = $this->normalize_filesystem_path( $file );
		if ( '' === $file || $this->path_has_parent_traversal( $file ) ) {
			return 'Attachment file path is unsafe; destructive cleanup refused.';
		}

		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? $this->normalize_filesystem_path( (string) $uploads['basedir'] ) : '';
		if ( '' === $basedir ) {
			return 'WordPress uploads directory is unavailable; destructive cleanup refused.';
		}
		if ( ! $this->path_is_within_directory( $file, $basedir, false ) ) {
			return 'Attachment file is outside WordPress uploads; destructive cleanup refused.';
		}

		$real_base = realpath( $basedir );
		if ( false === $real_base ) {
			return 'WordPress uploads directory could not be canonicalized; destructive cleanup refused.';
		}
		$real_base = $this->normalize_filesystem_path( $real_base );

		/* Existing files (including symlink paths) must canonicalize inside uploads. */
		if ( file_exists( $file ) || is_link( $file ) ) {
			$real_file = realpath( $file );
			if ( false === $real_file || ! $this->path_is_within_directory( $this->normalize_filesystem_path( $real_file ), $real_base, false ) ) {
				return 'Attachment file resolves outside WordPress uploads; destructive cleanup refused.';
			}
			return '';
		}

		/*
		 * The main file is already missing. Walk upward until an existing parent is
		 * found so an uploads-local lexical path cannot hide a symlinked directory
		 * that actually resolves outside uploads.
		 */
		$parent = dirname( $file );
		for ( $guard = 0; $guard < 32 && '' !== $parent; $guard++ ) {
			$normalized_parent = $this->normalize_filesystem_path( $parent );
			if ( file_exists( $parent ) || is_link( $parent ) ) {
				$real_parent = realpath( $parent );
				if ( false === $real_parent || ! $this->path_is_within_directory( $this->normalize_filesystem_path( $real_parent ), $real_base, true ) ) {
					return 'Attachment parent directory resolves outside WordPress uploads; destructive cleanup refused.';
				}
				return '';
			}
			$next = dirname( $parent );
			if ( $next === $parent || $this->normalize_filesystem_path( $next ) === $normalized_parent ) {
				break;
			}
			$parent = $next;
		}

		/* Lexical containment was proven and no existing parent contradicted it. */
		return '';
	}

	/** Normalize a filesystem path without resolving symlinks. */
	private function normalize_filesystem_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		if ( function_exists( 'wp_normalize_path' ) ) {
			$path = wp_normalize_path( $path );
		}
		return untrailingslashit( $path );
	}

	/** Detect explicit parent-directory traversal segments. */
	private function path_has_parent_traversal( $path ) {
		return 1 === preg_match( '#(?:^|/)\.\.(?:/|$)#', $this->normalize_filesystem_path( $path ) );
	}

	/**
	 * Compare one normalized path against a directory boundary.
	 *
	 * @param string $path Path to test.
	 * @param string $directory Directory boundary.
	 * @param bool   $allow_equal Whether the directory itself is accepted.
	 * @return bool
	 */
	private function path_is_within_directory( $path, $directory, $allow_equal = false ) {
		$path      = $this->normalize_filesystem_path( $path );
		$directory = $this->normalize_filesystem_path( $directory );
		if ( '' === $path || '' === $directory ) {
			return false;
		}

		/* Windows filesystems are normally case-insensitive; compare drive paths so. */
		if ( '\\' === DIRECTORY_SEPARATOR || preg_match( '/^[a-z]:\//i', $path ) || preg_match( '/^[a-z]:\//i', $directory ) ) {
			$path      = strtolower( $path );
			$directory = strtolower( $directory );
		}
		if ( $path === $directory ) {
			return (bool) $allow_equal;
		}

		return 0 === strpos( $path, trailingslashit( $directory ) );
	}

	/** Build file/URL needles only after GUID ownership has been proven. */
	private function attachment_reference_needles( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		$file = is_string( $file ) ? wp_normalize_path( $file ) : '';
		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) : '';
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		$relative = '' !== $file && '' !== $basedir && 0 === strpos( $file, $basedir ) ? ltrim( substr( $file, strlen( $basedir ) ), '/' ) : '';
		$needles = array();
		if ( '' !== $relative ) {
			$needles[] = $relative;
			$needles[] = basename( $relative );
			if ( '' !== $baseurl ) {
				$needles[] = $baseurl . '/' . $relative;
			}
		}
		return array_values( array_unique( array_filter( $needles ) ) );
	}

	/** Inspect one meta/option value without SQL wildcard scans. */
	private function storage_value_references_attachment( $key, $value, $attachment_id, $needles ) {
		$key = (string) $key;
		if ( $this->is_known_nonmedia_numeric_key( $key ) ) {
			$semantic = false;
		} else {
			$semantic = $this->is_media_semantic_key( $key );
		}
		return $this->structured_value_references_attachment( $value, $attachment_id, $needles, $semantic, 0 );
	}

	private function structured_value_references_attachment( $value, $attachment_id, $needles, $semantic_parent, $depth ) {
		if ( $depth > 6 ) {
			return false;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $key => $child ) {
				$semantic = $semantic_parent || $this->is_media_semantic_key( $key );
				if ( $this->structured_value_references_attachment( $child, $attachment_id, $needles, $semantic, $depth + 1 ) ) {
					return true;
				}
			}
			return false;
		}

		$text = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $text ) {
			return false;
		}
		$maybe = maybe_unserialize( $text );
		if ( $maybe !== $text && ( is_array( $maybe ) || is_object( $maybe ) ) && $this->structured_value_references_attachment( $maybe, $attachment_id, $needles, $semantic_parent, $depth + 1 ) ) {
			return true;
		}
		$first = substr( ltrim( $text ), 0, 1 );
		if ( '{' === $first || '[' === $first ) {
			$decoded = json_decode( $text, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) && $this->structured_value_references_attachment( $decoded, $attachment_id, $needles, $semantic_parent, $depth + 1 ) ) {
				return true;
			}
		}
		return $this->text_references_attachment( $text, $attachment_id, $needles, $semantic_parent );
	}

	private function text_references_attachment( $text, $attachment_id, $needles, $semantic ) {
		$text = (string) $text;
		$id   = (string) absint( $attachment_id );
		if ( '' === $text || '0' === $id ) {
			return false;
		}
		if ( false !== strpos( $text, 'wp-image-' . $id ) ) {
			return true;
		}
		foreach ( (array) $needles as $needle ) {
			if ( '' !== $needle && false !== stripos( $text, $needle ) ) {
				return true;
			}
		}
		if ( $semantic && preg_match( '/(?<!\d)' . preg_quote( $id, '/' ) . '(?!\d)/', $text ) ) {
			return true;
		}
		return false;
	}

	private function normalize_key( $key ) {
		$key = trim( (string) $key );
		$key = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key );
		return strtolower( str_replace( array( '-', '.', ':', ' ' ), '_', (string) $key ) );
	}

	private function is_media_semantic_key( $key ) {
		$key = $this->normalize_key( $key );
		if ( '' === $key || $this->is_known_nonmedia_numeric_key( $key ) ) {
			return false;
		}
		return 1 === preg_match( '/(?:^|_)(?:attachments?|images?|media|thumbnails?|galler(?:y|ies)|photos?|pictures?|icons?|logos?|avatars?|posters?|covers?|backgrounds?|banners?|featured)(?:_|$)/', $key );
	}

	private function is_known_nonmedia_numeric_key( $key ) {
		$key = $this->normalize_key( $key );
		if ( '' === $key ) {
			return false;
		}
		if ( in_array( $key, array( '_mobo_pruned_cleanup_pending', '_mobo_pruned_cleanup_owner_ids', 'page_on_front', 'page_for_posts', 'default_category' ), true ) ) {
			return true;
		}
		if ( preg_match( '/(?:^|_)(?:price|cost|amount|stock|quantity|qty|count|counter|total|revision|version|generation|cursor|offset|limit|seconds|interval|duration|timestamp|time|date|weight|length|rating|rate|percent|percentage|tax|discount|attempt|retry|scan|status|position|index|priority|width|height|filesize|file_size)(?:_|$)/', $key ) ) {
			return true;
		}
		if ( preg_match( '/(?:^|_)(?:portal|product|variation|order|user|customer|term|category|tag|page|post|parent|author|attribute|shipping_class|tax_class)(?:_[a-z0-9]+)*_ids?(?:_|$)/', $key ) ) {
			return true;
		}
		return false;
	}

	/** Freeze image mutation workers for every enabled Connection. @return array|false */
	private function acquire_all_image_worker_locks( $ttl = 120 ) {
		if ( ! class_exists( 'Mobo_Core_Lock' ) ) { return array(); }

		$connection_ids = array();
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			foreach ( Mobo_Core_Connection_Repository::list_enabled() as $connection ) {
				$connection_id = is_array( $connection ) ? absint( isset( $connection['id'] ) ? $connection['id'] : 0 ) : 0;
				if ( $connection_id > 0 ) { $connection_ids[] = $connection_id; }
			}
		}
		$connection_ids = array_values( array_unique( $connection_ids ) );
		sort( $connection_ids, SORT_NUMERIC );

		if ( empty( $connection_ids ) ) {
			$queue_token = Mobo_Core_Lock::acquire( 'image_queue_worker', $ttl );
			if ( false === $queue_token ) { return false; }
			$refresh_token = Mobo_Core_Lock::acquire( 'image_refresh_queue_worker', $ttl );
			if ( false === $refresh_token ) {
				Mobo_Core_Lock::release( 'image_queue_worker', $queue_token );
				return false;
			}
			return array(
				array( 'connection_id' => 0, 'name' => 'image_queue_worker', 'token' => $queue_token ),
				array( 'connection_id' => 0, 'name' => 'image_refresh_queue_worker', 'token' => $refresh_token ),
			);
		}

		if ( ! class_exists( 'Mobo_Core_Connection_Context' ) ) { return false; }
		$locks = array();
		foreach ( $connection_ids as $connection_id ) {
			foreach ( array( 'image_queue_worker', 'image_refresh_queue_worker' ) as $name ) {
				$token = Mobo_Core_Connection_Context::run(
					$connection_id,
					static function () use ( $name, $ttl ) { return Mobo_Core_Lock::acquire( $name, $ttl ); }
				);
				if ( false === $token ) {
					$this->release_all_image_worker_locks( $locks );
					return false;
				}
				$locks[] = array( 'connection_id' => $connection_id, 'name' => $name, 'token' => $token );
			}
		}
		return $locks;
	}

	/** Release locks returned by acquire_all_image_worker_locks(). */
	private function release_all_image_worker_locks( $locks ) {
		if ( ! class_exists( 'Mobo_Core_Lock' ) || ! is_array( $locks ) ) { return; }
		foreach ( array_reverse( $locks ) as $lock ) {
			$name = isset( $lock['name'] ) ? (string) $lock['name'] : '';
			$token = isset( $lock['token'] ) ? (string) $lock['token'] : '';
			$connection_id = isset( $lock['connection_id'] ) ? absint( $lock['connection_id'] ) : 0;
			if ( '' === $name || '' === $token ) { continue; }
			if ( $connection_id > 0 && class_exists( 'Mobo_Core_Connection_Context' ) ) {
				Mobo_Core_Connection_Context::run(
					$connection_id,
					static function () use ( $name, $token ) { Mobo_Core_Lock::release( $name, $token ); }
				);
			} else {
				Mobo_Core_Lock::release( $name, $token );
			}
		}
	}

	/** Defer a benign lock-contention retry without consuming destructive retries. */
	private function defer_without_penalty( $id, $seconds, $message ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'phase'         => 'finalize',
				'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + max( 5, absint( $seconds ) ) ),
				'last_error'    => sanitize_text_field( (string) $message ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ), 'connection_id' => self::connection_id() )
		);
		self::invalidate_summary();
	}

	private function mark_status( $id, $status, $message, $try_count = null ) {
		global $wpdb;
		$data = array(
			'status'        => sanitize_key( (string) $status ),
			'last_error'    => sanitize_text_field( (string) $message ),
			'next_retry_at' => null,
			'updated_at'    => current_time( 'mysql', true ),
		);
		if ( null !== $try_count ) {
			$data['try_count'] = absint( $try_count );
		}
		$wpdb->update( self::table_name(), $data, array( 'id' => absint( $id ), 'connection_id' => self::connection_id() ) );
		self::invalidate_summary();
	}

	private function update_cursor( $id, $column, $cursor ) {
		global $wpdb;
		$allowed = array( 'postmeta_cursor', 'option_cursor', 'post_cursor' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return;
		}
		$wpdb->update( self::table_name(), array( $column => absint( $cursor ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ), 'connection_id' => self::connection_id() ) );
	}

	private function advance_phase( $id, $phase ) {
		global $wpdb;
		$wpdb->update( self::table_name(), array( 'phase' => sanitize_key( $phase ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ), 'connection_id' => self::connection_id() ) );
	}

	private function schedule_retry( $id, $tries, $message ) {
		global $wpdb;
		$delay = min( HOUR_IN_SECONDS, 60 * max( 1, absint( $tries ) ) );
		$wpdb->update(
			self::table_name(),
			array(
				'phase'         => 'finalize',
				'try_count'     => absint( $tries ),
				'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'last_error'    => sanitize_text_field( (string) $message ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ), 'connection_id' => self::connection_id() )
		);
		self::invalidate_summary();
	}

	/** Bounded retention so completed cleanup rows cannot grow without limit. */
	private function cleanup_historical_rows() {
		global $wpdb;
		$table  = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return; }
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE connection_id = %d AND status = 'done' AND updated_at < %s ORDER BY id ASC LIMIT 100",
				$connection_id,
				$cutoff
			)
		);
		if ( false !== $deleted && absint( $deleted ) > 0 ) {
			self::invalidate_summary();
		}
	}

	/** Whether legacy prune metadata still needs to be migrated into this table. */
	private function legacy_seed_pending() {
		if ( ! self::is_default_connection_context() ) { return false; }
		$state = get_option( self::LEGACY_SEED_STATE_OPTION, array() );
		return is_array( $state ) && empty( $state['complete'] );
	}

	/** Seed old per-post cleanup debt using exact meta_key + primary-key cursors. */
	public function seed_legacy_candidates( $limit = 30 ) {
		global $wpdb;
		$limit = max( 1, min( 100, absint( $limit ) ) );
		if ( ! self::is_default_connection_context() ) { return array( 'processed' => 0, 'complete' => true ); }
		$state = get_option( self::LEGACY_SEED_STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return array( 'processed' => 0, 'complete' => true );
		}
		if ( ! empty( $state['complete'] ) ) {
			return array( 'processed' => 0, 'complete' => true );
		}
		$phase  = sanitize_key( (string) ( isset( $state['phase'] ) ? $state['phase'] : 'product' ) );
		$cursor = absint( isset( $state['cursor'] ) ? $state['cursor'] : 0 );
		$key    = 'owner' === $phase ? '_mobo_pruned_cleanup_owner_ids' : '_mobo_pruned_cleanup_pending';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$key,
				$cursor,
				$limit
			),
			ARRAY_A
		);
		$processed = 0;
		$stalled   = false;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta_id = absint( $row['meta_id'] );
			$post_id = absint( $row['post_id'] );
			$value   = maybe_unserialize( $row['meta_value'] );
			$durable = true;
			if ( 'owner' === $phase ) {
				$owners = is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
				$owner  = ! empty( $owners ) ? absint( reset( $owners ) ) : 0;
				if ( 'attachment' === get_post_type( $post_id ) ) {
					$outcome = $this->enqueue_attachments( $owner, array( $post_id ) );
					$durable = ( absint( $outcome['queued'] ) + absint( $outcome['retained'] ) ) >= 1;
				}
			} else {
				$ids = is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) ) : array();
				$live_ids = array_values( array_filter( $ids, static function ( $attachment_id ) {
					return 'attachment' === get_post_type( absint( $attachment_id ) );
				} ) );
				if ( ! empty( $live_ids ) ) {
					$outcome = $this->enqueue_attachments( $post_id, $live_ids );
					$durable = ( absint( $outcome['queued'] ) + absint( $outcome['retained'] ) ) >= count( $live_ids );
				}
			}

			if ( ! $durable ) {
				/* Keep the legacy metadata and do not advance past this row. A transient
				 * table/insert failure must never silently discard cleanup debt. */
				$stalled = true;
				break;
			}

			delete_metadata_by_mid( 'post', $meta_id );
			$cursor = $meta_id;
			$processed++;
		}
		if ( ! $stalled && count( $rows ) < $limit ) {
			if ( 'product' === $phase ) {
				$state = array( 'phase' => 'owner', 'cursor' => 0, 'complete' => false );
			} else {
				$state = array( 'phase' => 'done', 'cursor' => 0, 'complete' => true, 'completedAt' => time() );
			}
		} else {
			$state['phase']    = $phase;
			$state['cursor']   = $cursor;
			$state['complete'] = false;
		}
		update_option( self::LEGACY_SEED_STATE_OPTION, $state, false );
		return array( 'processed' => $processed, 'complete' => ! empty( $state['complete'] ) );
	}
}
