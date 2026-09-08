<?php
/**
 * Table-backed image sync queue.
 *
 * The queue lets product/webhook sync resume image imports without repeatedly
 * downloading the same attachment. It is deliberately bounded and safe for weak
 * shared WooCommerce hosts.
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
class Mobo_Core_Image_Queue {

	/** @var bool|null Request-local table existence cache. */
	private static $table_exists_cache = null;

	/** @var array|null Request-local queue summary cache. */
	private static $summary_cache = null;

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mobo_image_queue';
	}

	/**
	 * Create/update table schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			queue_key varchar(191) NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_guid varchar(191) NOT NULL DEFAULT '',
			image_guid varchar(191) NOT NULL DEFAULT '',
			source_url text NULL,
			position_index int(10) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'pending',
			try_count int(10) unsigned NOT NULL DEFAULT 0,
			next_retry_at datetime NULL,
			locked_until datetime NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY queue_key (queue_key),
			KEY product_status (product_id, status, next_retry_at),
			KEY image_guid (image_guid),
			KEY status_retry (status, next_retry_at),
			KEY status_retry_id (status, next_retry_at, id),
			KEY locked_until (locked_until),
			KEY status_locked_id (status, locked_until, id),
			KEY status_updated_id (status, updated_at, id),
			KEY updated_id (updated_at, id),
			KEY attachment_id (attachment_id)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
		self::$summary_cache      = null;
	}

	/**
	 * Check whether queue table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}

		$table = self::table_name();
		self::$table_exists_cache = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return (bool) self::$table_exists_cache;
	}

	/** @return void */
	private static function invalidate_summary_cache() {
		self::$summary_cache = null;
	}

	/**
	 * Add/update image queue rows for one product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_guid Remote product GUID.
	 * @param array  $images Normalized image rows.
	 * @return array
	 */
	public function enqueue_product_images( $product_id, $product_guid, $images ) {
		global $wpdb;

		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( $product_id <= 0 || ! is_array( $images ) ) {
			return array( 'enqueued' => 0, 'skipped' => 0, 'removed' => 0, 'error' => '' );
		}
		if ( ! self::table_exists() ) {
			return array( 'enqueued' => 0, 'skipped' => 0, 'error' => 'Image queue table is unavailable.' );
		}

		$table      = self::table_name();
		$now        = current_time( 'mysql', true );

		if ( empty( $images ) ) {
			$wpdb->last_error = '';
			$removed_attachment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT attachment_id FROM {$table} WHERE product_id = %d AND attachment_id > 0",
					$product_id
				)
			);
			if ( ! is_array( $removed_attachment_ids ) && '' !== (string) $wpdb->last_error ) {
				return array( 'enqueued' => 0, 'skipped' => 0, 'removed' => 0, 'removedAttachmentIds' => array(), 'error' => 'Could not read obsolete image attachments before clearing the queue.' );
			}

			$removed_attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $removed_attachment_ids ) ? $removed_attachment_ids : array() ) ) ) );
			$wpdb->last_error = '';
			$removed = $wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) );
			if ( false === $removed ) {
				return array( 'enqueued' => 0, 'skipped' => 0, 'removed' => 0, 'removedAttachmentIds' => array(), 'error' => 'Could not clear obsolete image queue rows.' );
			}
			self::invalidate_summary_cache();
			return array( 'enqueued' => 0, 'skipped' => 0, 'removed' => absint( $removed ), 'removedAttachmentIds' => $removed_attachment_ids, 'error' => '' );
		}
		$count                     = 0;
		$skip                      = 0;
		$db_error                  = '';
		$normalized                = array();
		$seen_keys                 = array();
		$superseded_attachment_ids = array();

		foreach ( array_values( $images ) as $position => $image ) {
			if ( ! is_array( $image ) ) {
				$skip++;
				continue;
			}

			$image_guid = $this->get_image_guid( $image );
			$url        = $this->get_image_url( $image );
			if ( '' === $image_guid || '' === $url ) {
				$skip++;
				continue;
			}

			$key = $this->queue_key( $product_id, $image_guid );
			if ( isset( $seen_keys[ $key ] ) ) {
				/* Duplicate remote identities are not authoritative desired-state input.
				 * Keep the first occurrence and defer destructive pruning until Portal
				 * sends an unambiguous image list. */
				$skip++;
				continue;
			}
			$seen_keys[ $key ] = true;
			$normalized[] = array(
				'key'       => $key,
				'position'  => absint( $position ),
				'imageGuid' => $image_guid,
				'url'       => $url,
			);
		}

		if ( empty( $normalized ) ) {
			return array( 'enqueued' => 0, 'skipped' => $skip, 'removed' => 0, 'error' => '' );
		}

		/* One lookup for the full product image set instead of one SELECT per image. */
		$keys         = array_values( array_unique( array_filter( array_map( 'strval', wp_list_pluck( $normalized, 'key' ) ) ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$query        = "SELECT id, queue_key, status, attachment_id, source_url FROM {$table} WHERE queue_key IN ({$placeholders})";
		$wpdb->last_error = '';
		$rows         = $wpdb->get_results( $wpdb->prepare( $query, $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholder list are generated internally; all values are bound by wpdb::prepare().
		if ( ! is_array( $rows ) && '' !== (string) $wpdb->last_error ) {
			return array( 'enqueued' => 0, 'skipped' => $skip, 'removed' => 0, 'error' => 'Could not read the image queue.' );
		}
		$existing_map = array();
		$existing_attachment_ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['queue_key'] ) ) {
				$existing_map[ (string) $row['queue_key'] ] = $row;
				$existing_attachment_id = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
				if ( $existing_attachment_id > 0 ) {
					$existing_attachment_ids[] = $existing_attachment_id;
				}
			}
		}

		/* Queue keys were case-sensitive before 10.33.24 while remote image GUID
		 * identity is case-insensitive. When the new canonical key misses, adopt the
		 * legacy row for the same product/GUID instead of inserting a second row. */
		if ( count( $existing_map ) < count( $keys ) ) {
			$requested_by_guid = array();
			foreach ( $normalized as $item ) {
				$canonical_guid = strtolower( trim( sanitize_text_field( (string) $item['imageGuid'] ) ) );
				if ( '' !== $canonical_guid ) {
					$requested_by_guid[ $canonical_guid ] = (string) $item['key'];
				}
			}

			$wpdb->last_error = '';
			$legacy_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, queue_key, status, attachment_id, source_url, image_guid FROM {$table} WHERE product_id = %d",
					$product_id
				),
				ARRAY_A
			);

			if ( ! is_array( $legacy_rows ) && '' !== (string) $wpdb->last_error ) {
				return array( 'enqueued' => 0, 'skipped' => $skip, 'removed' => 0, 'error' => 'Could not read legacy image queue rows.' );
			}

			foreach ( is_array( $legacy_rows ) ? $legacy_rows : array() as $legacy_row ) {
				$legacy_guid = strtolower( trim( sanitize_text_field( (string) ( isset( $legacy_row['image_guid'] ) ? $legacy_row['image_guid'] : '' ) ) ) );
				if ( '' === $legacy_guid || ! isset( $requested_by_guid[ $legacy_guid ] ) ) {
					continue;
				}
				$canonical_key = $requested_by_guid[ $legacy_guid ];
				if ( isset( $existing_map[ $canonical_key ] ) ) {
					continue;
				}
				$existing_map[ $canonical_key ] = $legacy_row;
				$legacy_attachment_id = absint( isset( $legacy_row['attachment_id'] ) ? $legacy_row['attachment_id'] : 0 );
				if ( $legacy_attachment_id > 0 ) {
					$existing_attachment_ids[] = $legacy_attachment_id;
				}
			}
		}

		$existing_attachment_ids = array_values( array_unique( $existing_attachment_ids ) );
		if ( ! empty( $existing_attachment_ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $existing_attachment_ids, false, true );
		}

		foreach ( $normalized as $item ) {
			$key        = isset( $item['key'] ) ? (string) $item['key'] : '';
			$image_guid = sanitize_text_field( (string) $item['imageGuid'] );
			$url        = esc_url_raw( (string) $item['url'] );
			$position   = absint( $item['position'] );
			$existing   = isset( $existing_map[ $key ] ) && is_array( $existing_map[ $key ] ) ? $existing_map[ $key ] : null;

			$attachment_id         = is_array( $existing ) ? absint( $existing['attachment_id'] ) : 0;
			$existing_url          = is_array( $existing ) ? esc_url_raw( (string) $existing['source_url'] ) : '';
			$status                = is_array( $existing ) ? sanitize_key( (string) $existing['status'] ) : 'pending';
			$attachment_compatible = $attachment_id > 0 && $this->attachment_matches_identity( $attachment_id, $image_guid, $url );

			if ( 'done' === $status && $attachment_compatible && $existing_url === $url ) {
				$write_result = $wpdb->update(
					$table,
					array(
						'queue_key'      => $key,
						'product_id'     => $product_id,
						'product_guid'   => $product_guid,
						'image_guid'     => $image_guid,
						'position_index' => $position,
						'updated_at'     => $now,
					),
					array( 'id' => absint( $existing['id'] ) ),
					array( '%s', '%d', '%s', '%s', '%d', '%s' ),
					array( '%d' )
				);
				if ( false === $write_result ) {
					$db_error = 'Could not refresh an existing image queue row.';
					continue;
				}
				$count++;
				continue;
			}

			$data = array(
				'queue_key'      => $key,
				'product_id'     => $product_id,
				'product_guid'   => $product_guid,
				'image_guid'     => $image_guid,
				'source_url'     => $url,
				'position_index' => $position,
				'updated_at'     => $now,
			);

			$superseded_attachment_id = 0;
			if ( is_array( $existing ) ) {
				$source_changed = $existing_url !== $url;

				/*
				 * Do not steal an active worker's lease. A changed desired source updates
				 * the identity in place, causing the old worker's guarded commit to fail;
				 * the replacement becomes claimable when the short lease expires. This
				 * avoids running two workers for one queue row at the same time.
				 */
				if ( 'processing' === $status ) {
					if ( $source_changed ) {
						$data['try_count']     = 0;
						$data['next_retry_at'] = null;
						$data['last_error']    = null;
						$data['attachment_id'] = 0;
						$superseded_attachment_id = $attachment_id;
					}
				} elseif ( $source_changed || ! $attachment_compatible ) {
					$data['status']        = 'pending';
					$data['try_count']     = 0;
					$data['next_retry_at'] = null;
					$data['locked_until']  = null;
					$data['last_error']    = null;
					$data['attachment_id'] = 0;
					$superseded_attachment_id = $attachment_id;
				}

				$write_result = $wpdb->update( $table, $data, array( 'id' => absint( $existing['id'] ) ), null, array( '%d' ) );
			} else {
				$data['attachment_id'] = 0;
				$data['try_count']     = 0;
				$data['status']        = 'pending';
				$data['next_retry_at'] = null;
				$data['locked_until']  = null;
				$data['last_error']    = null;
				$data['created_at']    = $now;
				$write_result = $wpdb->insert( $table, $data );
			}

			if ( false === $write_result ) {
				$db_error = 'Could not persist the desired image queue row.';
				continue;
			}

			if ( $superseded_attachment_id > 0 ) {
				$superseded_attachment_ids[] = absint( $superseded_attachment_id );
			}

			$count++;
		}

		/*
		 * The queue is also the durable desired image order for a product. Remove rows
		 * that belonged to an older non-empty payload; otherwise get_ordered_rows_for_product()
		 * keeps deleted Mobo images in the WooCommerce gallery forever. This removes
		 * only queue state. Attachment IDs from pruned rows are returned to the image
		 * sync layer, which performs separate reference proof before any physical delete.
		 */
		/* Never prune desired-state rows from a partially malformed payload. One bad
		 * image row must not make a previously valid gallery image disappear. A fully
		 * valid non-empty payload is required before absence is authoritative. */
		$prune_safe             = '' === $db_error && 0 === $skip && count( $normalized ) === count( array_values( $images ) );
		$prune_error            = '';
		$removed_attachment_ids = array();
		$removed                = $prune_safe ? $this->prune_product_rows_except( $product_id, $keys, $prune_error, $removed_attachment_ids ) : 0;
		if ( '' !== $prune_error ) {
			$db_error = $prune_error;
		}

		$removed_attachment_ids = array_values( array_unique( array_merge(
			$removed_attachment_ids,
			array_filter( array_map( 'absint', $superseded_attachment_ids ) )
		) ) );

		self::invalidate_summary_cache();
		return array(
			'enqueued'             => $count,
			'skipped'              => $skip,
			'removed'              => $removed,
			'removedAttachmentIds' => $removed_attachment_ids,
			'pruneDeferred'        => ! $prune_safe || '' !== $db_error,
			'error'                => $db_error,
		);
	}

	/**
	 * Remove obsolete queue rows for one product while preserving the current
	 * desired non-empty image set. Attachments and files are intentionally kept.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $keep_keys Current desired queue keys.
	 * @return int Removed row count.
	 */
	private function prune_product_rows_except( $product_id, $keep_keys, &$error = '', &$removed_attachment_ids = array() ) {
		global $wpdb;

		$error                  = '';
		$removed_attachment_ids = array();
		$product_id             = absint( $product_id );
		$keep_keys              = array_values( array_unique( array_filter( array_map( 'strval', is_array( $keep_keys ) ? $keep_keys : array() ) ) ) );

		if ( $product_id <= 0 || empty( $keep_keys ) || ! self::table_exists() ) {
			return 0;
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $keep_keys ), '%s' ) );
		$args         = array_merge( array( $product_id ), $keep_keys );
		$select_query = "SELECT DISTINCT attachment_id FROM {$table} WHERE product_id = %d AND queue_key NOT IN ({$placeholders}) AND attachment_id > 0";
		$wpdb->last_error = '';
		$candidates = $wpdb->get_col( $wpdb->prepare( $select_query, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholder list are internal; all values are prepared.
		if ( ! is_array( $candidates ) && '' !== (string) $wpdb->last_error ) {
			$error = 'Could not read stale image attachment candidates before pruning.';
			return 0;
		}

		$query   = "DELETE FROM {$table} WHERE product_id = %d AND queue_key NOT IN ({$placeholders})";
		$deleted = $wpdb->query( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholder list are internal; all values are prepared.

		if ( false === $deleted ) {
			$error = 'Could not prune stale image queue rows.';
			return 0;
		}

		$removed_attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $candidates ) ? $candidates : array() ) ) ) );

		return absint( $deleted );
	}

	/**
	 * Get due rows for one product.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_due_product_images( $product_id, $limit ) {
		global $wpdb;

		$product_id = absint( $product_id );
		$limit      = max( 1, min( 50, absint( $limit ) ) );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE product_id = %d
				AND (
					(status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)
				ORDER BY position_index ASC, id ASC
				LIMIT %d",
				$product_id,
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count immediately due image rows for one product.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public function count_due_by_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE product_id = %d
					AND (
						(status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
					)",
					$product_id,
					$now,
					$now
				)
			)
		);
	}

	/**
	 * Return pending/due counters for one product with a single query.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_product_summary( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array( 'pending' => 0, 'due' => 0 );
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN status IN ('pending','processing','attaching') THEN 1 ELSE 0 END) AS pending_count,
					SUM(CASE WHEN (status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s) THEN 1 ELSE 0 END) AS due_count
				FROM {$table}
				WHERE product_id = %d",
				$now,
				$now,
				$product_id
			),
			ARRAY_A
		);

		return array(
			'pending' => absint( is_array( $row ) && isset( $row['pending_count'] ) ? $row['pending_count'] : 0 ),
			'due'     => absint( is_array( $row ) && isset( $row['due_count'] ) ? $row['due_count'] : 0 ),
		);
	}

	/**
	 * Fast boolean due check for runner pressure decisions.
	 *
	 * @return bool
	 */
	public function has_due() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE (status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				LIMIT 1",
				$now,
				$now
			)
		);

		return absint( $id ) > 0;
	}

	/**
	 * Aggregate queue counters in one query.
	 *
	 * @param bool $force Force a fresh query.
	 * @return array
	 */
	public function get_summary( $force = false ) {
		global $wpdb;

		if ( ! $force && is_array( self::$summary_cache ) ) {
			return self::$summary_cache;
		}

		$empty = array(
			'pending'         => 0,
			'due'             => 0,
			'failed'          => 0,
			'attaching'       => 0,
			'nextRetryAt'     => '',
			'oldestPendingAt' => '',
		);

		if ( ! self::table_exists() ) {
			self::$summary_cache = $empty;
			return $empty;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN status IN ('pending','processing','attaching') THEN 1 ELSE 0 END) AS pending_count,
					SUM(CASE WHEN (status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s) THEN 1 ELSE 0 END) AS due_count,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
					SUM(CASE WHEN status = 'attaching' THEN 1 ELSE 0 END) AS attaching_count,
					MIN(CASE WHEN status IN ('pending','processing','attaching') THEN created_at ELSE NULL END) AS oldest_pending_at,
					MIN(CASE WHEN status IN ('pending','attaching') AND next_retry_at IS NOT NULL THEN next_retry_at ELSE NULL END) AS next_retry_at
				FROM {$table}
				WHERE status IN ('pending','processing','attaching','failed')",
				$now,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			self::$summary_cache = $empty;
			return $empty;
		}

		self::$summary_cache = array(
			'pending'         => absint( isset( $row['pending_count'] ) ? $row['pending_count'] : 0 ),
			'due'             => absint( isset( $row['due_count'] ) ? $row['due_count'] : 0 ),
			'failed'          => absint( isset( $row['failed_count'] ) ? $row['failed_count'] : 0 ),
			'attaching'       => absint( isset( $row['attaching_count'] ) ? $row['attaching_count'] : 0 ),
			'nextRetryAt'     => isset( $row['next_retry_at'] ) ? (string) $row['next_retry_at'] : '',
			'oldestPendingAt' => isset( $row['oldest_pending_at'] ) ? (string) $row['oldest_pending_at'] : '',
		);

		return self::$summary_cache;
	}

	/**
	 * Terminally classify due queue rows whose WooCommerce product no longer exists.
	 *
	 * Orphan cleanup is intentionally separate from the normal image batch. A large
	 * historical orphan prefix must never consume every images-per-run slot and hide
	 * valid rows behind it. This is a status transition only: no attachment/file is
	 * deleted and no Repair/Reconciliation workflow is invoked.
	 *
	 * @param int $limit Maximum orphan rows to classify in one worker pass.
	 * @return int Number of rows terminally classified.
	 */
	public function terminalize_due_missing_products( $limit = 100 ) {
		global $wpdb;

		$limit = max( 1, min( 500, absint( $limit ) ) );
		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT q.id
				FROM {$table} q
				LEFT JOIN {$wpdb->posts} p
					ON p.ID = q.product_id
					AND p.post_type = 'product'
				WHERE p.ID IS NULL
					AND (
						(q.status IN ('pending','attaching') AND (q.next_retry_at IS NULL OR q.next_retry_at <= %s))
						OR (q.status = 'processing' AND q.locked_until IS NOT NULL AND q.locked_until < %s)
					)
				ORDER BY q.updated_at ASC, q.id ASC
				LIMIT %d",
				$now,
				$now,
				$limit
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args            = array_merge( array( $now ), $ids, array( $now, $now ) );
		$updated         = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic ID placeholders are generated from integer IDs only.
				"UPDATE {$table}
				SET status = 'failed',
					attachment_id = 0,
					try_count = GREATEST(1, try_count + 1),
					next_retry_at = NULL,
					locked_until = NULL,
					last_error = 'Permanent: Product does not exist.',
					updated_at = %s
				WHERE id IN ({$id_placeholders})
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->posts} p
						WHERE p.ID = {$table}.product_id AND p.post_type = 'product'
					)
					AND (
						(status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
					)",
				$args
			)
		);

		$updated = false === $updated ? 0 : absint( $updated );
		if ( $updated > 0 ) {
			self::invalidate_summary_cache();
		}

		return $updated;
	}

	/**
	 * Bulk-claim due image rows for the dedicated image worker.
	 *
	 * @param int $limit Limit.
	 * @param int $ttl Claim TTL.
	 * @return array
	 */
	public function claim_due_images( $limit, $ttl = 120 ) {
		global $wpdb;

		$limit = max( 1, min( 50, absint( $limit ) ) );
		$ttl   = max( 30, min( 300, absint( $ttl ) ) );
		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT q.id FROM {$table} q
				INNER JOIN {$wpdb->posts} p
					ON p.ID = q.product_id
					AND p.post_type = 'product'
				WHERE (q.status IN ('pending','attaching') AND (q.next_retry_at IS NULL OR q.next_retry_at <= %s))
					OR (q.status = 'processing' AND q.locked_until IS NOT NULL AND q.locked_until < %s)
				ORDER BY q.updated_at ASC, q.id ASC
				LIMIT %d",
				$now,
				$now,
				$limit
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$locked_until    = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$update_args     = array_merge( array( $locked_until, $now ), $ids, array( $now, $now ) );
		$updated         = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $update_args contains two fixed values, dynamic %d ID replacements, and two trailing fixed values; wpdb::prepare() expands the single array at runtime.
				"UPDATE {$table}
				SET status = 'processing', locked_until = %s, updated_at = %s
				WHERE id IN ({$id_placeholders})
					AND ((status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s))",
				$update_args
			)
		);

		if ( false === $updated || absint( $updated ) <= 0 ) {
			return array();
		}

		self::invalidate_summary_cache();
		$select_args = array_merge( $ids, array( $locked_until ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_id, product_guid, image_guid, source_url, position_index, attachment_id, try_count, last_error, locked_until, updated_at
				FROM {$table}
				WHERE id IN ({$id_placeholders}) AND status = 'processing' AND locked_until = %s
				ORDER BY updated_at ASC, id ASC",
				$select_args
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['_mobo_bulk_claimed'] = 1;
			$row['_mobo_claim_token']  = isset( $row['locked_until'] ) ? sanitize_text_field( (string) $row['locked_until'] ) : '';
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Release rows that were bulk-claimed but not handled before a safe stop.
	 *
	 * @param array  $ids Row IDs.
	 * @param string $claim_token Exact persisted bulk-claim token.
	 * @return int
	 */
	public function release_claimed_images( $ids, $claim_token = '' ) {
		global $wpdb;

		$ids         = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( empty( $ids ) || '' === $claim_token || ! self::table_exists() ) {
			return 0;
		}

		$table          = self::table_name();
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now            = current_time( 'mysql', true );
		$args           = array_merge( array( $now ), $ids, array( $claim_token ) );
		$updated        = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'pending', locked_until = NULL, updated_at = %s
				WHERE status = 'processing' AND id IN ({$id_placeholders}) AND locked_until = %s",
				$args
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Get due rows across all products.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_due_images( $limit ) {
		global $wpdb;

		$limit = max( 1, min( 50, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE (
					(status IN ('pending','attaching') AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)
				ORDER BY updated_at ASC, id ASC
				LIMIT %d",
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lock image row.
	 *
	 * @param int $id Row ID.
	 * @param int $ttl TTL seconds.
	 * @return bool
	 */
	public function lock( $id, $ttl = 90 ) {
		return '' !== $this->lock_with_token( $id, $ttl );
	}

	/**
	 * Claim one image row and return the exact persisted lease token.
	 *
	 * The token is the claimed locked_until value. Conditional worker commits must
	 * present the same token so a worker whose lease expired cannot commit after a
	 * newer worker reclaimed the same row/source identity.
	 *
	 * @param int $id Row ID.
	 * @param int $ttl TTL seconds.
	 * @return string Empty string on failure.
	 */
	public function lock_with_token( $id, $ttl = 90 ) {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 || ! self::table_exists() ) {
			return '';
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', locked_until = %s, updated_at = %s
				WHERE id = %d
				AND (
					status IN ('pending','attaching')
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)",
				$until, $now, $id, $now
			)
		);
		if ( 1 !== absint( $updated ) ) {
			return '';
		}

		$persisted = sanitize_text_field( (string) $wpdb->get_var( $wpdb->prepare( "SELECT locked_until FROM {$table} WHERE id = %d AND status = 'processing' LIMIT 1", $id ) ) );
		if ( '' === $persisted || ! hash_equals( $until, $persisted ) ) {
			return '';
		}
		self::invalidate_summary_cache();
		return $persisted;
	}

	/**
	 * Persist an attempt before entering sideload/subsize code that can terminate
	 * PHP at the host time/memory boundary. A stale lease can then advance instead
	 * of retrying forever with an unchanged try_count.
	 *
	 * @return bool
	 */
	public function begin_attempt_if_current( $id, $try_count, $product_id, $image_guid, $source_url, $claim_token = '' ) {
		global $wpdb;
		$id         = absint( $id );
		$try_count  = max( 1, absint( $try_count ) );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$source_url = esc_url_raw( (string) $source_url );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( $id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $source_url || ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$lease_sql = '' !== $claim_token ? ' AND locked_until = %s' : '';
		$args = array( $try_count, current_time( 'mysql', true ), $id, $try_count, $product_id, $image_guid, $source_url );
		if ( '' !== $claim_token ) { $args[] = $claim_token; }
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET try_count = %d, updated_at = %s
				WHERE id = %d AND status = 'processing' AND try_count < %d
					AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s{$lease_sql}",
				$args
			)
		);
		return 1 === absint( $updated );
	}

	/**
	 * Mark an imported attachment as waiting for WooCommerce linkage.
	 *
	 * The intermediate state closes the race window between creating an
	 * attachment and assigning the product featured/gallery images. If PHP stops
	 * after this update, the next queue pass can finish the linkage without
	 * downloading the file again.
	 *
	 * @param int $id Row ID.
	 * @param int $attachment_id Attachment ID.
	 * @param int $retry_delay Optional linkage retry delay while the current batch coalesces product saves.
	 * @return void
	 */
	public function mark_attaching( $id, $attachment_id, $retry_delay = 0 ) {
		$retry_delay = max( 0, min( 300, absint( $retry_delay ) ) );
		$this->update_status(
			$id,
			'attaching',
			array(
				'attachment_id' => absint( $attachment_id ),
				'next_retry_at' => $retry_delay > 0 ? gmdate( 'Y-m-d H:i:s', time() + $retry_delay ) : null,
				'locked_until'  => null,
				'last_error'    => null,
			)
		);
	}


	/**
	 * Commit the processing -> attaching transition only if the queue row still
	 * represents the exact identity claimed by this worker. A webhook may supersede
	 * the source URL while a download is in flight.
	 *
	 * @return bool
	 */
	public function mark_attaching_if_current( $id, $attachment_id, $product_id, $image_guid, $source_url, $retry_delay = 0, $claim_token = '' ) {
		global $wpdb;

		$id            = absint( $id );
		$attachment_id = absint( $attachment_id );
		$product_id    = absint( $product_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		$source_url    = esc_url_raw( (string) $source_url );
		$retry_delay   = max( 0, min( 300, absint( $retry_delay ) ) );
		$claim_token   = sanitize_text_field( (string) $claim_token );
		if ( $id <= 0 || $attachment_id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $source_url || ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$lease_sql = '' !== $claim_token ? ' AND locked_until = %s' : '';
		if ( $retry_delay > 0 ) {
			$args = array( $attachment_id, gmdate( 'Y-m-d H:i:s', time() + $retry_delay ), $now, $id, $product_id, $image_guid, $source_url );
			if ( '' !== $claim_token ) { $args[] = $claim_token; }
			$sql = "UPDATE {$table} SET status = 'attaching', attachment_id = %d, next_retry_at = %s, locked_until = NULL, last_error = NULL, updated_at = %s WHERE id = %d AND status = 'processing' AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s{$lease_sql}";
		} else {
			$args = array( $attachment_id, $now, $id, $product_id, $image_guid, $source_url );
			if ( '' !== $claim_token ) { $args[] = $claim_token; }
			$sql = "UPDATE {$table} SET status = 'attaching', attachment_id = %d, next_retry_at = NULL, locked_until = NULL, last_error = NULL, updated_at = %s WHERE id = %d AND status = 'processing' AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s{$lease_sql}";
		}
		$updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( 1 === absint( $updated ) ) { self::invalidate_summary_cache(); return true; }
		return false;
	}

	/**
	 * Mark image as done.
	 *
	 * @param int $id Row ID.
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function mark_done( $id, $attachment_id ) {
		$this->update_status(
			$id,
			'done',
			array(
				'attachment_id'  => absint( $attachment_id ),
				'next_retry_at' => null,
				'locked_until'  => null,
				'last_error'    => null,
			)
		);
	}


	/**
	 * Mark multiple successfully linked queue rows done in one database write.
	 *
	 * Rows must already contain a valid attachment_id from mark_attaching(). The
	 * status predicate keeps unrelated/retried rows from being completed by a
	 * stale batch.
	 *
	 * @param array $ids Queue row IDs.
	 * @return int Updated row count.
	 */
	public function mark_done_many( $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( empty( $ids ) || ! self::table_exists() ) {
			return 0;
		}

		$table           = self::table_name();
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now             = current_time( 'mysql', true );
		$args            = array_merge( array( $now ), $ids );
		$updated         = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'done', next_retry_at = NULL, locked_until = NULL, last_error = NULL, updated_at = %s
				WHERE id IN ({$id_placeholders}) AND status = 'attaching' AND attachment_id > 0",
				$args
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Mark image retry/failure.
	 *
	 * @param int    $id Row ID.
	 * @param string $message Error message.
	 * @param int    $try_count Try count.
	 * @param bool   $final_failed Final failure.
	 * @return void
	 */
	public function mark_failure( $id, $message, $try_count, $final_failed = false ) {
		$try_count = max( 1, absint( $try_count ) );
		$status    = $final_failed ? 'failed' : 'pending';
		$delay     = $final_failed ? null : $this->calculate_retry_delay( $id, $try_count );
		$message   = sanitize_text_field( (string) $message );

		if ( $final_failed && 0 !== strpos( $message, 'Permanent:' ) ) {
			$message = 'Permanent: ' . $message;
		}

		$this->update_status(
			$id,
			$status,
			array(
				'attachment_id' => 0,
				'try_count'     => $try_count,
				'next_retry_at' => null === $delay ? null : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_until'  => null,
				'last_error'    => $message,
			)
		);
	}


	/**
	 * Mark a processing row failed only while the exact claim is still owned.
	 *
	 * This is used before source identity can be trusted (for example malformed
	 * rows). A stale worker must not terminally fail a row reclaimed by another
	 * worker merely because the row id is unchanged.
	 *
	 * @param int    $id Row ID.
	 * @param string $message Error.
	 * @param int    $try_count Try count.
	 * @param bool   $final_failed Final failure.
	 * @param string $claim_token Exact persisted claim token.
	 * @return bool
	 */
	public function mark_failure_if_claimed( $id, $message, $try_count, $final_failed = false, $claim_token = '' ) {
		global $wpdb;

		$id          = absint( $id );
		$try_count   = max( 1, absint( $try_count ) );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( $id <= 0 || '' === $claim_token || ! self::table_exists() ) {
			return false;
		}

		$status  = $final_failed ? 'failed' : 'pending';
		$delay   = $final_failed ? null : $this->calculate_retry_delay( $id, $try_count );
		$message = sanitize_text_field( (string) $message );
		if ( $final_failed && 0 !== strpos( $message, 'Permanent:' ) ) {
			$message = 'Permanent: ' . $message;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		if ( null === $delay ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = %s, attachment_id = 0, try_count = %d, next_retry_at = NULL, locked_until = NULL, last_error = %s, updated_at = %s
					WHERE id = %d AND status = 'processing' AND locked_until = %s",
					$status,
					$try_count,
					$message,
					$now,
					$id,
					$claim_token
				)
			);
		} else {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = %s, attachment_id = 0, try_count = %d, next_retry_at = %s, locked_until = NULL, last_error = %s, updated_at = %s
					WHERE id = %d AND status = 'processing' AND locked_until = %s",
					$status,
					$try_count,
					gmdate( 'Y-m-d H:i:s', time() + $delay ),
					$message,
					$now,
					$id,
					$claim_token
				)
			);
		}

		if ( 1 === absint( $updated ) ) {
			self::invalidate_summary_cache();
			return true;
		}
		return false;
	}

	/**
	 * Mark a claimed row failed only if its source identity has not been superseded
	 * while the worker was downloading/validating the image.
	 *
	 * @return bool
	 */
	/**
	 * Release a stale processing lease after the desired source identity changed.
	 * The negative identity predicate prevents this worker from unlocking its own
	 * still-current row by mistake.
	 *
	 * @return bool
	 */
	/**
	 * Requeue a durable done/attaching row for storage repair only when it still
	 * represents the identity that failed the final linkage/readiness check.
	 *
	 * @return bool
	 */
	public function schedule_repair_if_current( $id, $message, $try_count, $product_id, $image_guid, $source_url ) {
		global $wpdb;
		$id         = absint( $id );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$source_url = esc_url_raw( (string) $source_url );
		$try_count  = max( 1, absint( $try_count ) );
		if ( $id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $source_url || ! self::table_exists() ) {
			return false;
		}

		$delay = $this->calculate_retry_delay( $id, $try_count );
		$table = self::table_name();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'pending', attachment_id = 0, try_count = %d, next_retry_at = %s, locked_until = NULL, last_error = %s, updated_at = %s
				WHERE id = %d AND status IN ('done','attaching') AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s",
				$try_count,
				gmdate( 'Y-m-d H:i:s', time() + $delay ),
				sanitize_text_field( (string) $message ),
				current_time( 'mysql', true ),
				$id,
				$product_id,
				$image_guid,
				$source_url
			)
		);
		if ( 1 === absint( $updated ) ) {
			self::invalidate_summary_cache();
			return true;
		}
		return false;
	}

	public function release_if_superseded( $id, $product_id, $image_guid, $source_url, $claim_token = '' ) {
		global $wpdb;
		$id         = absint( $id );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$source_url = esc_url_raw( (string) $source_url );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( $id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $source_url || ! self::table_exists() ) { return false; }

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$lease_sql = '' !== $claim_token ? ' AND locked_until = %s' : '';
		$args = array( $now, $id, $product_id, $image_guid, $source_url );
		if ( '' !== $claim_token ) { $args[] = $claim_token; }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', locked_until = NULL, next_retry_at = NULL, updated_at = %s WHERE id = %d AND status = 'processing' AND NOT (product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s){$lease_sql}", $args ) );
		if ( 1 === absint( $updated ) ) { self::invalidate_summary_cache(); return true; }
		return false;
	}

	/**
	 * Terminate one claimed image row because its parent product is currently
	 * excluded by the administrator URL policy. The exact lease/identity fence
	 * prevents an old worker from cancelling a superseding image row.
	 *
	 * A later authoritative enqueue re-opens this row automatically because an
	 * `excluded` row has no reusable attachment and is normalized back to pending.
	 *
	 * @return bool
	 */
	public function mark_excluded_if_current( $id, $product_id, $image_guid, $source_url, $claim_token = '' ) {
		global $wpdb;

		$id          = absint( $id );
		$product_id  = absint( $product_id );
		$image_guid  = sanitize_text_field( (string) $image_guid );
		$source_url  = esc_url_raw( (string) $source_url );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( $id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $source_url || ! self::table_exists() ) {
			return false;
		}

		$table     = self::table_name();
		$now       = current_time( 'mysql', true );
		$lease_sql = '' !== $claim_token ? ' AND locked_until = %s' : '';
		$args      = array( $now, $id, $product_id, $image_guid, $source_url );
		if ( '' !== $claim_token ) {
			$args[] = $claim_token;
		}

		$sql = "UPDATE {$table} SET status = 'excluded', attachment_id = 0, next_retry_at = NULL, locked_until = NULL, last_error = 'Product excluded by administrator URL policy.', updated_at = %s WHERE id = %d AND status = 'processing' AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s{$lease_sql}";
		$updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( 1 === absint( $updated ) ) {
			self::invalidate_summary_cache();
			return true;
		}

		return false;
	}

	public function mark_failure_if_current( $id, $message, $try_count, $product_id, $image_guid, $source_url, $final_failed = false, $claim_token = '' ) {
		global $wpdb;

		$id         = absint( $id );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );
		$source_url = esc_url_raw( (string) $source_url );
		$try_count  = max( 1, absint( $try_count ) );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( $id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $source_url || ! self::table_exists() ) { return false; }

		$status  = $final_failed ? 'failed' : 'pending';
		$delay   = $final_failed ? null : $this->calculate_retry_delay( $id, $try_count );
		$message = sanitize_text_field( (string) $message );
		if ( $final_failed && 0 !== strpos( $message, 'Permanent:' ) ) { $message = 'Permanent: ' . $message; }
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$lease_sql = '' !== $claim_token ? ' AND locked_until = %s' : '';
		if ( null === $delay ) {
			$args = array( $status, $try_count, $message, $now, $id, $product_id, $image_guid, $source_url );
			if ( '' !== $claim_token ) { $args[] = $claim_token; }
			$sql = "UPDATE {$table} SET status = %s, attachment_id = 0, try_count = %d, next_retry_at = NULL, locked_until = NULL, last_error = %s, updated_at = %s WHERE id = %d AND status = 'processing' AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s{$lease_sql}";
		} else {
			$args = array( $status, $try_count, gmdate( 'Y-m-d H:i:s', time() + $delay ), $message, $now, $id, $product_id, $image_guid, $source_url );
			if ( '' !== $claim_token ) { $args[] = $claim_token; }
			$sql = "UPDATE {$table} SET status = %s, attachment_id = 0, try_count = %d, next_retry_at = %s, locked_until = NULL, last_error = %s, updated_at = %s WHERE id = %d AND status = 'processing' AND product_id = %d AND image_guid = %s AND BINARY source_url = BINARY %s{$lease_sql}";
		}
		$updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( 1 === absint( $updated ) ) { self::invalidate_summary_cache(); return true; }
		return false;
	}

	/**
	 * Calculate bounded retry delay.
	 *
	 * The first configured attempts use the legacy short backoff. Later attempts
	 * switch to a long retry interval instead of becoming terminal. A small
	 * deterministic jitter prevents many customer sites retrying the same image
	 * at exactly the same second.
	 *
	 * @param int $id Queue row ID.
	 * @param int $try_count Try count.
	 * @return int Delay in seconds.
	 */
	private function calculate_retry_delay( $id, $try_count ) {
		$base_seconds = Mobo_Core_Settings::get_int( 'mobo_core_image_retry_base_seconds', 120, 30, 900 );
		$fast_tries   = Mobo_Core_Settings::get_int( 'mobo_core_image_max_try', 5, 1, 20 );

		if ( $try_count <= $fast_tries ) {
			$delay = min( 900, max( 60, $try_count * $base_seconds ) );
		} else {
			$delay = Mobo_Core_Settings::get_int( 'mobo_core_image_long_retry_seconds', 21600, 3600, 604800 );
		}

		$jitter_max = max( 1, (int) floor( $delay / 10 ) );
		$jitter     = absint( $id + ( $try_count * 37 ) ) % $jitter_max;

		return $delay + $jitter;
	}


	/**
	 * Count not-done images for product.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $include_failed Include failed rows.
	 * @return int
	 */
	public function count_pending_by_product( $product_id, $include_failed = false ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		if ( $include_failed ) {
			return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status <> 'done'", $product_id ) ) );
		}

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status IN ('pending', 'processing', 'attaching')", $product_id ) ) );
	}

	/**
	 * Get done attachment IDs for a product ordered by source position.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_done_attachment_ids_for_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table}
				WHERE product_id = %d AND status = 'done' AND attachment_id > 0
				ORDER BY position_index ASC, id ASC",
				$product_id
			)
		);

		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Get all image queue rows for one product ordered by source position.
	 *
	 * This is used to keep the WooCommerce featured image tied to the first
	 * image from the Mobo payload. It is important for products migrated from
	 * the old plugin because the old plugin could set the last image as the
	 * featured image.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_ordered_rows_for_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, image_guid, source_url, position_index, attachment_id, status, try_count
				FROM {$table}
				WHERE product_id = %d
				ORDER BY position_index ASC, id ASC",
				$product_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count due image rows.
	 *
	 * @return int
	 */
	public function count_due() {
		$summary = $this->get_summary();
		return absint( isset( $summary['due'] ) ? $summary['due'] : 0 );
	}

	/**
	 * Count pending/processing image rows.
	 *
	 * @return int
	 */
	public function count_pending() {
		$summary = $this->get_summary();
		return absint( isset( $summary['pending'] ) ? $summary['pending'] : 0 );
	}

	/**
	 * Count failed image rows.
	 *
	 * @return int
	 */
	public function count_failed() {
		$summary = $this->get_summary();
		return absint( isset( $summary['failed'] ) ? $summary['failed'] : 0 );
	}

	/**
	 * Count attachments waiting for product linkage.
	 *
	 * @return int
	 */
	public function count_attaching() {
		$summary = $this->get_summary();
		return absint( isset( $summary['attaching'] ) ? $summary['attaching'] : 0 );
	}

	/**
	 * Return the nearest scheduled retry timestamp.
	 *
	 * @return string
	 */
	public function get_next_retry_at() {
		$summary = $this->get_summary();
		return isset( $summary['nextRetryAt'] ) ? (string) $summary['nextRetryAt'] : '';
	}

	/**
	 * Re-open non-permanent failed rows created by older releases.
	 *
	 * Only rows with an existing WooCommerce product, GUID and HTTP(S) source URL
	 * are recovered. New permanent failures are prefixed with `Permanent:` and
	 * remain terminal.
	 *
	 * @param int $limit Maximum rows to recover.
	 * @return array
	 */
	public static function recover_legacy_failed( $limit = 500 ) {
		global $wpdb;

		$limit = max( 1, min( 5000, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array( 'status' => 'missing-table', 'recovered' => 0, 'remaining' => 0 );
		}

		$table = self::table_name();
		$now            = current_time( 'mysql', true );
		$permanent_like = $wpdb->esc_like( 'Permanent:' ) . '%';
		$ids            = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT q.id
				FROM {$table} q
				INNER JOIN {$wpdb->posts} p
					ON p.ID = q.product_id
					AND p.post_type = 'product'
					AND p.post_status NOT IN ('trash','auto-draft')
				WHERE q.status = 'failed'
					AND q.image_guid <> ''
					AND q.source_url IS NOT NULL
					AND q.source_url <> ''
					AND q.source_url REGEXP '^https?://'
					AND (q.last_error IS NULL OR q.last_error NOT LIKE %s)
				ORDER BY q.updated_at ASC, q.id ASC
				LIMIT %d",
				$permanent_like,
				$limit
			)
		);

		$ids       = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		$recovered = 0;

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args         = array_merge( array( $now, $now ), $ids );
			$query        = "UPDATE {$table}
				SET status = 'pending',
					try_count = 0,
					next_retry_at = %s,
					locked_until = NULL,
					last_error = 'Recovered legacy terminal image failure; retry scheduled.',
					updated_at = %s
				WHERE id IN ({$placeholders})";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query text is built only from an internal table name and a generated list of %d placeholders.
			$updated   = $wpdb->query( $wpdb->prepare( $query, $args ) );
			$recovered = absint( false === $updated ? 0 : $updated );
			if ( $recovered > 0 ) {
				self::invalidate_summary_cache();
			}
		}

		$remaining = absint(
			$wpdb->get_var(
				"SELECT COUNT(*)
				FROM {$table} q
				INNER JOIN {$wpdb->posts} p
					ON p.ID = q.product_id
					AND p.post_type = 'product'
					AND p.post_status NOT IN ('trash','auto-draft')
				WHERE q.status = 'failed'
					AND q.image_guid <> ''
					AND q.source_url IS NOT NULL
					AND q.source_url <> ''
					AND q.source_url REGEXP '^https?://'
					AND (q.last_error IS NULL OR q.last_error NOT LIKE 'Permanent:%')"
			)
		);

		return array(
			'status'    => 'ok',
			'recovered' => $recovered,
			'remaining' => $remaining,
		);
	}

	/**
	 * Schedule repair for completed rows whose featured-image linkage was lost.
	 *
	 * This catches the old race where an attachment was marked done immediately
	 * before PHP stopped, leaving `_thumbnail_id` unchanged. The source file is
	 * reused; no re-download occurs.
	 *
	 * @param int $limit Product limit.
	 * @return array
	 */
	public static function schedule_linkage_repairs( $limit = 100 ) {
		global $wpdb;

		$limit = max( 1, min( 1000, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array( 'status' => 'missing-table', 'scheduled' => 0 );
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT q.id, q.product_id, q.attachment_id
				FROM {$table} q
				INNER JOIN {$wpdb->posts} p
					ON p.ID = q.product_id
					AND p.post_type = 'product'
					AND p.post_status NOT IN ('trash','auto-draft')
				INNER JOIN {$wpdb->posts} a
					ON a.ID = q.attachment_id
					AND a.post_type = 'attachment'
				LEFT JOIN {$wpdb->postmeta} thumb
					ON thumb.post_id = q.product_id
					AND thumb.meta_key = '_thumbnail_id'
				LEFT JOIN {$wpdb->postmeta} gallery
					ON gallery.post_id = q.product_id
					AND gallery.meta_key = '_product_image_gallery'
				WHERE q.status = 'done'
					AND q.attachment_id > 0
					AND (
						( q.position_index = 0 AND (
							thumb.meta_value IS NULL
							OR thumb.meta_value = ''
							OR CAST(thumb.meta_value AS UNSIGNED) <> q.attachment_id
							OR FIND_IN_SET(CAST(q.attachment_id AS CHAR), COALESCE(gallery.meta_value, '')) > 0
						) )
						OR ( q.position_index > 0 AND FIND_IN_SET(CAST(q.attachment_id AS CHAR), COALESCE(gallery.meta_value, '')) = 0 )
					)
				ORDER BY q.updated_at ASC, q.id ASC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$ids = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids       = array_values( array_unique( $ids ) );
		$scheduled = 0;

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args         = array_merge( array( $now, $now ), $ids );
			$query        = "UPDATE {$table}
				SET status = 'attaching',
					next_retry_at = %s,
					locked_until = NULL,
					last_error = 'Product image linkage recovery scheduled.',
					updated_at = %s
				WHERE status = 'done'
					AND id IN ({$placeholders})";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query text is built only from an internal table name and a generated list of %d placeholders.
			$updated   = $wpdb->query( $wpdb->prepare( $query, $args ) );
			$scheduled = absint( false === $updated ? 0 : $updated );
			if ( $scheduled > 0 ) {
				self::invalidate_summary_cache();
			}
		}

		return array(
			'status'    => 'ok',
			'scheduled' => $scheduled,
		);
	}

	/**
	 * Requeue completed rows whose physical image file is missing, corrupt or no
	 * longer matches the recorded source URL. The audit is cursor-based and
	 * bounded so maintenance can safely cover large stores over several runs.
	 *
	 * @param int $limit Maximum rows to requeue in one call.
	 * @return array
	 */
	public static function schedule_file_repairs( $limit = 75 ) {
		global $wpdb;

		$limit = max( 1, min( 500, absint( $limit ) ) );
		if ( ! self::table_exists() ) {
			return array( 'status' => 'missing-table', 'scheduled' => 0, 'scanned' => 0, 'cycleComplete' => true );
		}

		$table       = self::table_name();
		$cursor_key  = 'mobo_core_image_queue_file_audit_cursor';
		$cursor      = absint( get_option( $cursor_key, 0 ) );
		$scan_limit  = max( 100, min( 1000, $limit * 8 ) );
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, image_guid, source_url
				FROM {$table}
				WHERE status = 'done'
					AND attachment_id > 0
					AND id > %d
				ORDER BY id ASC
				LIMIT %d",
				$cursor,
				$scan_limit
			),
			ARRAY_A
		);

		$rows      = is_array( $rows ) ? $rows : array();
		$matcher         = new self();
		$refresh_service = class_exists( 'Mobo_Core_Image_Refresh_Service' ) ? new Mobo_Core_Image_Refresh_Service() : null;
		$repair_ids      = array();
		$scanned   = 0;
		$cursor_end = $cursor;
		$stopped_for_limit = false;

		foreach ( $rows as $row ) {
			$id            = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			$attachment_id = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
			$image_guid    = sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) );
			$source_url    = esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) );

			if ( $id <= 0 ) {
				continue;
			}

			$scanned++;
			$cursor_end = $id;

			$attachment_healthy = $matcher->attachment_matches_identity( $attachment_id, $image_guid, $source_url );
			$shared_attachment  = class_exists( 'Mobo_Core_Shared_Media' ) && method_exists( 'Mobo_Core_Shared_Media', 'is_shared_attachment' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id );
			if ( $attachment_healthy && ( $matcher->is_webp_url( $source_url ) || $shared_attachment ) && $refresh_service && method_exists( $refresh_service, 'inspect_webp_attachment_health' ) ) {
				$storage_health     = $refresh_service->inspect_webp_attachment_health( $attachment_id );
				$attachment_healthy = ! empty( $storage_health['healthy'] );
			}

			if ( ! $attachment_healthy ) {
				$repair_ids[] = $id;
				if ( count( $repair_ids ) >= $limit ) {
					$stopped_for_limit = true;
					break;
				}
			}
		}

		$scheduled = 0;
		if ( ! empty( $repair_ids ) ) {
			$now          = current_time( 'mysql', true );
			$placeholders = implode( ',', array_fill( 0, count( $repair_ids ), '%d' ) );
			$args         = array_merge( array( $now, $now ), $repair_ids );
			$query        = "UPDATE {$table}
				SET status = 'pending', attachment_id = 0, try_count = 0,
					next_retry_at = %s, locked_until = NULL,
					last_error = 'Completed image file was missing, corrupt, or source-mismatched; source retry scheduled.',
					updated_at = %s
				WHERE status = 'done' AND id IN ({$placeholders})";
			$updated      = $wpdb->query( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal table name and generated %d placeholder list; all values are bound.
			$scheduled    = absint( false === $updated ? 0 : $updated );
			if ( $scheduled > 0 ) {
				self::invalidate_summary_cache();
			}
		}

		$cycle_complete = ! $stopped_for_limit && count( $rows ) < $scan_limit;
		if ( $cycle_complete ) {
			update_option( $cursor_key, 0, false );
		} elseif ( $cursor_end > 0 ) {
			update_option( $cursor_key, $cursor_end, false );
		}

		return array(
			'status'        => 'ok',
			'scheduled'     => $scheduled,
			'scanned'       => $scanned,
			'cursorStart'   => $cursor,
			'cursorEnd'     => $cycle_complete ? 0 : $cursor_end,
			'cycleComplete' => $cycle_complete,
		);
	}

	/**
	 * Get compact status.
	 *
	 * @return array
	 */
	public function get_status() {
		$summary = $this->get_summary();
		return array(
			'enabled'     => Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' ),
			'pending'     => absint( isset( $summary['pending'] ) ? $summary['pending'] : 0 ),
			'attaching'   => absint( isset( $summary['attaching'] ) ? $summary['attaching'] : 0 ),
			'due'         => absint( isset( $summary['due'] ) ? $summary['due'] : 0 ),
			'failed'      => absint( isset( $summary['failed'] ) ? $summary['failed'] : 0 ),
			'nextRetryAt' => isset( $summary['nextRetryAt'] ) ? (string) $summary['nextRetryAt'] : '',
		);
	}

	/**
	 * Update row status and fields.
	 *
	 * @param int    $id Row ID.
	 * @param string $status Status.
	 * @param array  $fields Extra fields.
	 * @return void
	 */
	private function update_status( $id, $status, $fields = array() ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return;
		}

		$data = array_merge(
			array(
				'status'     => sanitize_key( (string) $status ),
				'updated_at' => current_time( 'mysql', true ),
			),
			is_array( $fields ) ? $fields : array()
		);

		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'attachment_id', 'try_count' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$updated = $wpdb->update( self::table_name(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false !== $updated ) {
			self::invalidate_summary_cache();
		}
	}

	/**
	 * Count rows by statuses.
	 *
	 * @param array $statuses Statuses.
	 * @return int
	 */
	private function count_by_statuses( $statuses ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) ) );
		if ( empty( $statuses ) ) {
			return 0;
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$query        = "SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})";

		return absint( $wpdb->get_var( $wpdb->prepare( $query, $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholder list are generated internally; all status values are bound by wpdb::prepare().
	}

	/**
	 * Build queue key.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @return string
	 */
	private function queue_key( $product_id, $image_guid ) {
		/* Remote GUID identity is treated case-insensitively everywhere else in the
		 * image subsystem. Normalize here as well so a casing-only Portal change
		 * cannot create a second durable queue identity for the same image. */
		$image_guid = strtolower( trim( sanitize_text_field( (string) $image_guid ) ) );
		return md5( absint( $product_id ) . '|' . $image_guid );
	}

	/**
	 * Check attachment exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_exists( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		return $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id );
	}

	/**
	 * Check that a queue attachment belongs to the same remote image identity and
	 * still contains a healthy payload for the expected source. Attachments from
	 * older releases with no stored image GUID remain adoptable; an attachment
	 * explicitly owned by another GUID must never satisfy this queue row merely
	 * because both remote records currently expose the same URL.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $image_guid Remote image GUID.
	 * @param string $url Source URL.
	 * @return bool
	 */
	private function attachment_matches_identity( $attachment_id, $image_guid, $url ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = strtolower( trim( sanitize_text_field( (string) $image_guid ) ) );
		if ( $attachment_id <= 0 || '' === $image_guid ) {
			return false;
		}

		$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
		if ( '' === $stored_guid ) {
			$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
		}
		if ( '' === $stored_guid && class_exists( 'Mobo_Core_Shared_Media' ) && method_exists( 'Mobo_Core_Shared_Media', 'is_shared_attachment' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
			$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, '_mobo_shared_media_image_id', true ) );
		}

		/* Resolver code may temporarily adopt a legacy unclaimed attachment, but a
		 * durable queue row is not converged until the Mobo GUID has actually been
		 * persisted. Otherwise old rows can remain done forever while every source-hash
		 * fast-path check correctly reports identity drift. */
		if ( '' === $stored_guid || ! hash_equals( strtolower( trim( $stored_guid ) ), $image_guid ) ) {
			return false;
		}

		/* Explicitly incomplete is a recoverable import checkpoint, not a durable
		 * `done` attachment. Requeue it so readiness can finish and commit the marker. */
		if ( '1' === (string) get_post_meta( $attachment_id, 'mobo_sync_incomplete', true ) ) {
			return false;
		}

		return $this->attachment_matches_source( $attachment_id, $url );
	}

	/**
	 * Check whether an existing attachment is still compatible with source URL.
	 *
	 * A done row pointing to an old jpg/png must not block a new WebP source from
	 * being downloaded.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Source URL.
	 * @return bool
	 */
	private function attachment_matches_source( $attachment_id, $url ) {
		$attachment_id = absint( $attachment_id );
		$url           = esc_url_raw( (string) $url );

		if ( ! $this->attachment_exists( $attachment_id ) ) {
			return false;
		}

		$stored_source_url = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
		if ( '' !== $stored_source_url && '' !== $url && $stored_source_url !== $url ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) ) {
			return false;
		}

		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A concurrent file removal is treated as an invalid attachment.
		if ( false === $size || $size <= 0 ) {
			return false;
		}

		$detected_mime = '';
		if ( function_exists( 'wp_get_image_mime' ) ) {
			$detected_mime = strtolower( (string) wp_get_image_mime( $file ) );
			if ( '' === $detected_mime || 0 !== strpos( $detected_mime, 'image/' ) ) {
				return false;
			}
		}

		if ( class_exists( 'Mobo_Core_Shared_Media' )
			&& method_exists( 'Mobo_Core_Shared_Media', 'is_shared_attachment' )
			&& Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id )
			&& method_exists( 'Mobo_Core_Shared_Media', 'attachment_health' ) ) {
			$shared_health = Mobo_Core_Shared_Media::attachment_health( $attachment_id, true );
			if ( empty( $shared_health['healthy'] ) ) {
				return false;
			}
		}

		if ( $this->is_webp_url( $url ) ) {
			if ( '' !== $detected_mime && 'image/webp' !== $detected_mime ) {
				return false;
			}

			return $this->is_attachment_webp( $attachment_id );
		}

		return true;
	}

	/**
	 * Detect WebP source URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_webp_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		return 'webp' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Detect WebP attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_attachment_webp( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$mime          = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$file          = (string) get_attached_file( $attachment_id );
		$ext           = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return 'image/webp' === $mime || 'webp' === $ext;
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
