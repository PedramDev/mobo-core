<?php
/**
 * Table-backed sync event queue.
 *
 * New webhook payloads are stored here instead of only using JSON files. The old
 * file queue remains as a fallback for legacy pending files and write failures.
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
class Mobo_Core_Sync_Event_Store {

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

		return $wpdb->prefix . 'mobo_sync_events';
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
			event_uuid varchar(64) NOT NULL,
			remote_event_id varchar(191) NOT NULL DEFAULT '',
			event_type varchar(64) NOT NULL,
			entity_type varchar(64) NOT NULL DEFAULT '',
			entity_guid varchar(191) NOT NULL DEFAULT '',
			sync_id varchar(191) NOT NULL DEFAULT '',
			event_version varchar(64) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'pending',
			try_count int(10) unsigned NOT NULL DEFAULT 0,
			next_retry_at datetime NULL,
			locked_until datetime NULL,
			claim_token varchar(64) NOT NULL DEFAULT '',
			expires_at datetime NULL,
			payload_json longtext NOT NULL,
			progress_json longtext NULL,
			last_error text NULL,
			source varchar(32) NOT NULL DEFAULT 'webhook',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY remote_event_id (remote_event_id),
			KEY status_retry (status, next_retry_at),
			KEY status_retry_id (status, next_retry_at, id),
			KEY locked_until (locked_until),
			KEY claim_token (claim_token),
			KEY status_locked_id (status, locked_until, id),
			KEY status_updated_id (status, updated_at, id),
			KEY event_entity_id (event_type, entity_guid(100), id),
			KEY entity_lookup (entity_type, entity_guid(120)),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
		self::$summary_cache      = null;
	}

	/**
	 * Check whether table exists.
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

	/**
	 * Clear request-local aggregate cache after a queue mutation.
	 *
	 * @return void
	 */
	private static function invalidate_summary_cache() {
		self::$summary_cache = null;
	}


	/**
	 * Acquire a connection-scoped lock for one remote event identity.
	 *
	 * @param string $remote_event_id Remote event identity.
	 * @return string Lock name or empty string.
	 */
	private function acquire_remote_event_dedupe_lock( $remote_event_id ) {
		global $wpdb;

		$remote_event_id = sanitize_text_field( (string) $remote_event_id );
		if ( '' === $remote_event_id ) {
			return '';
		}

		$db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$lock_name = 'mobo_evt_' . substr( hash( 'sha256', $db_name . '|' . (string) $wpdb->prefix . '|' . $remote_event_id ), 0, 40 );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );

		return '1' === (string) $acquired ? $lock_name : '';
	}

	/**
	 * Release a connection-scoped remote-event dedupe lock.
	 *
	 * @param string $lock_name Lock name.
	 * @return void
	 */
	private function release_remote_event_dedupe_lock( $lock_name ) {
		global $wpdb;

		$lock_name = sanitize_text_field( (string) $lock_name );
		if ( '' !== $lock_name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Enqueue webhook payload.
	 *
	 * @param array $raw_payload Raw webhook payload.
	 * @return int|WP_Error
	 */
	public function enqueue( $raw_payload ) {
		global $wpdb;

		if ( ! is_array( $raw_payload ) ) {
			return new WP_Error( 'mobo_core_invalid_event_payload', 'Invalid event payload.' );
		}

		if ( ! self::table_exists() ) {
			return new WP_Error( 'mobo_core_event_table_missing', 'Sync event table is missing.' );
		}

		$normalized = $this->normalize_payload( $raw_payload );
		$event_type = sanitize_text_field( (string) $normalized['eventType'] );
		$payload    = isset( $normalized['payload'] ) && is_array( $normalized['payload'] ) ? $normalized['payload'] : array();

		if ( '' === $event_type ) {
			return new WP_Error( 'mobo_core_event_type_missing', 'Webhook event is missing.' );
		}

		$entity_type = sanitize_key( (string) $normalized['entityType'] );
		$entity_guid = sanitize_text_field( (string) $normalized['entityGuid'] );

		if ( 'UpdateVariant' === $event_type ) {
			$variant_guids        = $this->extract_update_variant_variant_guids( $payload );
			$variant_product_guid = $this->extract_update_variant_product_guid( $payload );

			if ( 1 === count( $variant_guids ) ) {
				$entity_type = 'variant';
				$entity_guid = $variant_guids[0];
			} elseif ( '' !== $variant_product_guid ) {
				$entity_type = 'product';
				$entity_guid = $variant_product_guid;
			}
		}

		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $payload_json ) {
			return new WP_Error( 'mobo_core_event_encode_failed', 'Could not encode event payload.' );
		}

		$remote_event_id = sanitize_text_field( (string) $normalized['remoteEventId'] );
		$table           = self::table_name();

		if ( 'UpdateVariant' === $event_type ) {
			$variant_identity_key = $this->build_update_variant_identity_key( $payload );

			if ( '' !== $remote_event_id && '' !== $variant_identity_key ) {
				/*
				 * Some senders reuse the same remote event id for multiple variant
				 * changes of the same parent product. Keep idempotency for identical
				 * retries, but do not collapse different variant GUIDs into one row.
				 */
				$remote_event_id = 'remote:updatevariant:' . md5( $remote_event_id . '|' . $variant_identity_key );
			}
		}

		if ( '' === $remote_event_id ) {
			$remote_event_id = $this->build_local_dedupe_id( $event_type, $payload, sanitize_text_field( (string) $normalized['syncId'] ), $entity_type, $entity_guid );
		}

		$dedupe_lock_name = '';
		if ( '' !== $remote_event_id ) {
			/*
			 * SELECT-then-INSERT is not a dedupe boundary under concurrency. Serialize
			 * only this tiny database critical section so two identical webhook
			 * requests cannot both observe a miss and create duplicate queue rows.
			 */
			$dedupe_lock_name = $this->acquire_remote_event_dedupe_lock( $remote_event_id );
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE remote_event_id = %s AND status IN ('pending', 'processing', 'done') ORDER BY id DESC LIMIT 1",
					$remote_event_id
				)
			);

			if ( $existing ) {
				if ( '' !== $dedupe_lock_name ) {
					$this->release_remote_event_dedupe_lock( $dedupe_lock_name );
				}
				return absint( $existing );
			}

			if ( '' === $dedupe_lock_name ) {
				return new WP_Error( 'mobo_core_event_dedupe_lock_busy', 'Could not obtain webhook dedupe lock.' );
			}
		}

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ) );

		$event_uuid = wp_generate_uuid4();

		$inserted = $wpdb->insert(
			$table,
			array(
				'event_uuid'       => $event_uuid,
				'remote_event_id'  => $remote_event_id,
				'event_type'       => $event_type,
				'entity_type'      => $entity_type,
				'entity_guid'      => $entity_guid,
				'sync_id'          => sanitize_text_field( (string) $normalized['syncId'] ),
				'event_version'    => sanitize_text_field( (string) $normalized['version'] ),
				'status'           => 'pending',
				'try_count'        => 0,
				'next_retry_at'    => null,
				'locked_until'     => null,
				'expires_at'       => $expires,
				'payload_json'     => $payload_json,
				'progress_json'    => null,
				'last_error'       => null,
				'source'           => 'webhook',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( '' !== $dedupe_lock_name ) {
			$this->release_remote_event_dedupe_lock( $dedupe_lock_name );
		}

		if ( false === $inserted ) {
			return new WP_Error( 'mobo_core_event_insert_failed', 'Could not store sync event.' );
		}

		$new_id = absint( $wpdb->insert_id );
		self::invalidate_summary_cache();

		/*
		 * Last desired state wins for safe single-entity events that have not started
		 * processing yet. Never collapse multi-product payloads or authoritative
		 * multi-variation snapshots: their pages/variants carry independent state.
		 */
		if ( $new_id > 0 && $this->is_coalescible_entity_event( $event_type, $entity_type, $entity_guid, $payload ) ) {
			$this->coalesce_older_pending_entity_events( $new_id, $event_uuid, sanitize_text_field( (string) $normalized['version'] ), $event_type, $entity_type, $entity_guid );
		}

		return $new_id;
	}


	/**
	 * Whether an event may safely supersede older unprocessed events for the same
	 * entity. ProductUpdated is coalesced only when it contains exactly one product;
	 * UpdateVariant is coalesced only when the queue identity is one exact variant.
	 *
	 * @param string $event_type Event type.
	 * @param string $entity_type Entity type.
	 * @param string $entity_guid Entity GUID.
	 * @param array  $payload Payload.
	 * @return bool
	 */
	private function is_coalescible_entity_event( $event_type, $entity_type, $entity_guid, $payload ) {
		$event_type = sanitize_text_field( (string) $event_type );
		$entity_type = sanitize_key( (string) $entity_type );
		$entity_guid = sanitize_text_field( (string) $entity_guid );

		if ( '' === $entity_guid ) {
			return false;
		}

		if ( 'UpdateVariant' === $event_type ) {
			return 'variant' === $entity_type && 1 === count( $this->extract_update_variant_variant_guids( $payload ) );
		}

		if ( 'ProductUpdated' !== $event_type || 'product' !== $entity_type || ! is_array( $payload ) ) {
			return false;
		}

		$data = $this->get_value( $payload, 'data', array() );
		if ( is_array( $data ) && ! $this->is_list_array( $data ) ) {
			$nested = $this->get_value( $data, 'data', null );
			if ( is_array( $nested ) ) {
				$data = $nested;
			} else {
				/* A single product object wrapped in data is safe to coalesce. */
				return true;
			}
		}

		return is_array( $data ) && $this->is_list_array( $data ) && 1 === count( $data );
	}

	/**
	 * Mark older still-pending copies of one safe entity event as completed.
	 * Processing rows are never touched, so a worker that already owns a row can
	 * finish safely under the existing product lock.
	 *
	 * @param int    $new_id New event row ID.
	 * @param string $new_uuid New event UUID.
	 * @param string $new_version New event/entity version when supplied.
	 * @param string $event_type Event type.
	 * @param string $entity_type Entity type.
	 * @param string $entity_guid Entity GUID.
	 * @return int Number coalesced.
	 */
	private function coalesce_older_pending_entity_events( $new_id, $new_uuid, $new_version, $event_type, $entity_type, $entity_guid ) {
		global $wpdb;

		$new_id      = absint( $new_id );
		$new_uuid    = sanitize_text_field( (string) $new_uuid );
		$new_version = sanitize_text_field( (string) $new_version );
		$event_type  = sanitize_text_field( (string) $event_type );
		$entity_type = sanitize_key( (string) $entity_type );
		$entity_guid = sanitize_text_field( (string) $entity_guid );

		if ( $new_id <= 0 || '' === $event_type || '' === $entity_type || '' === $entity_guid || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		/*
		 * Arrival order is the fallback coalescing rule, but when both rows carry a
		 * comparable entity/event version we refuse to let a late stale delivery
		 * supersede a newer desired state that arrived earlier.
		 */
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_uuid, event_version FROM {$table}
				WHERE id < %d
					AND status = 'pending'
					AND event_type = %s
					AND entity_type = %s
					AND entity_guid = %s
				ORDER BY id DESC",
				$new_id,
				$event_type,
				$entity_type,
				$entity_guid
			),
			ARRAY_A
		);

		if ( empty( $candidates ) || ! is_array( $candidates ) ) {
			return 0;
		}

		$ids_to_supersede = array();
		foreach ( $candidates as $candidate ) {
			$old_id      = absint( isset( $candidate['id'] ) ? $candidate['id'] : 0 );
			$old_version = sanitize_text_field( (string) ( isset( $candidate['event_version'] ) ? $candidate['event_version'] : '' ) );
			$comparison  = $this->compare_event_versions( $new_version, $old_version );

			if ( null !== $comparison && $comparison < 0 ) {
				/* The just-arrived row is stale. Retire it, preserving the newer pending row. */
				$progress = wp_json_encode(
					array(
						'deleteFile'          => true,
						'superseded'          => true,
						'supersededByEventId' => sanitize_text_field( (string) ( isset( $candidate['event_uuid'] ) ? $candidate['event_uuid'] : '' ) ),
						'reason'              => 'older-event-version',
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);
				$wpdb->update(
					$table,
					array(
						'status'        => 'done',
						'locked_until'  => null,
						'next_retry_at' => null,
						'last_error'    => null,
						'progress_json' => false === $progress ? '{"superseded":true}' : $progress,
						'updated_at'    => $now,
					),
					array( 'id' => $new_id, 'status' => 'pending' ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d', '%s' )
				);
				self::invalidate_summary_cache();
				return 0;
			}

			if ( $old_id > 0 ) {
				$ids_to_supersede[] = $old_id;
			}
		}

		$ids_to_supersede = array_values( array_unique( array_filter( array_map( 'absint', $ids_to_supersede ) ) ) );
		if ( empty( $ids_to_supersede ) ) {
			return 0;
		}

		$progress = wp_json_encode(
			array(
				'deleteFile'          => true,
				'superseded'          => true,
				'supersededByEventId' => $new_uuid,
				'reason'              => 'newer-desired-state',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$id_placeholders = implode( ',', array_fill( 0, count( $ids_to_supersede ), '%d' ) );
		$update_args     = array_merge(
			array( false === $progress ? '{"superseded":true}' : $progress, $now ),
			$ids_to_supersede
		);
		$updated = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $update_args contains the two fixed %s values followed by dynamic %d ID replacements; wpdb::prepare() expands the single array at runtime.
				"UPDATE {$table}
				SET status = 'done', locked_until = NULL, next_retry_at = NULL, last_error = NULL, progress_json = %s, updated_at = %s
				WHERE status = 'pending' AND id IN ({$id_placeholders})",
				$update_args
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Compare event/entity versions only when their format is safely comparable.
	 *
	 * @param string $left New version.
	 * @param string $right Existing version.
	 * @return int|null -1/0/1, or null when arrival order must be used.
	 */
	private function compare_event_versions( $left, $right ) {
		$left  = trim( (string) $left );
		$right = trim( (string) $right );

		if ( '' === $left || '' === $right ) {
			return null;
		}
		if ( $left === $right ) {
			return 0;
		}

		if ( ctype_digit( $left ) && ctype_digit( $right ) ) {
			$left_norm  = ltrim( $left, '0' );
			$right_norm = ltrim( $right, '0' );
			$left_norm  = '' === $left_norm ? '0' : $left_norm;
			$right_norm = '' === $right_norm ? '0' : $right_norm;
			if ( strlen( $left_norm ) !== strlen( $right_norm ) ) {
				return strlen( $left_norm ) < strlen( $right_norm ) ? -1 : 1;
			}
			$numeric_compare = strcmp( $left_norm, $right_norm );
			if ( 0 === $numeric_compare ) {
				return 0;
			}
			return $numeric_compare < 0 ? -1 : 1;
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}[T ]/', $left ) && preg_match( '/^\\d{4}-\\d{2}-\\d{2}[T ]/', $right ) ) {
			$left_time  = strtotime( $left );
			$right_time = strtotime( $right );
			if ( false !== $left_time && false !== $right_time && $left_time !== $right_time ) {
				return $left_time < $right_time ? -1 : 1;
			}
		}

		return null;
	}



	/**
	 * Fast existence check used by the real runner hot path.
	 *
	 * Unlike get_status(), this intentionally avoids COUNT/timing diagnostics and
	 * only asks MySQL whether one runnable row exists.
	 *
	 * @return bool
	 */
	public function has_due_events() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE (status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				LIMIT 1",
				$now,
				$now
			)
		);

		return absint( $id ) > 0;
	}

	/**
	 * Read queue counters/timing with one aggregate query.
	 *
	 * @param bool $force Force a fresh query after external/manual DB changes.
	 * @return array
	 */
	public function get_summary( $force = false ) {
		global $wpdb;

		if ( ! $force && is_array( self::$summary_cache ) ) {
			return self::$summary_cache;
		}

		$empty = array(
			'pendingCount'         => 0,
			'dueCount'             => 0,
			'failedCount'          => 0,
			'oldestPendingAt'      => '',
			'newestPendingUpdateAt'=> '',
			'nextDeferredAt'       => '',
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
					SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS pending_count,
					SUM(CASE WHEN (status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s) THEN 1 ELSE 0 END) AS due_count,
					SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
					MIN(CASE WHEN status IN ('pending','processing') THEN created_at ELSE NULL END) AS oldest_pending_at,
					MAX(CASE WHEN status IN ('pending','processing') THEN updated_at ELSE NULL END) AS newest_pending_update_at,
					MIN(CASE WHEN status = 'pending' AND next_retry_at IS NOT NULL AND next_retry_at > %s THEN next_retry_at ELSE NULL END) AS next_deferred_at
				FROM {$table}
				WHERE status IN ('pending','processing','failed')",
				$now,
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
			'pendingCount'          => absint( isset( $row['pending_count'] ) ? $row['pending_count'] : 0 ),
			'dueCount'              => absint( isset( $row['due_count'] ) ? $row['due_count'] : 0 ),
			'failedCount'           => absint( isset( $row['failed_count'] ) ? $row['failed_count'] : 0 ),
			'oldestPendingAt'       => isset( $row['oldest_pending_at'] ) ? (string) $row['oldest_pending_at'] : '',
			'newestPendingUpdateAt' => isset( $row['newest_pending_update_at'] ) ? (string) $row['newest_pending_update_at'] : '',
			'nextDeferredAt'        => isset( $row['next_deferred_at'] ) ? (string) $row['next_deferred_at'] : '',
		);

		return self::$summary_cache;
	}

	/**
	 * Claim a bounded due batch with one SELECT + one bulk UPDATE + one row fetch.
	 *
	 * The webhook processor already owns the global webhook_queue lease, so doing a
	 * per-row lock UPDATE only adds database round trips. Conditions are repeated on
	 * the bulk UPDATE to remain safe if a custom integration races this method.
	 *
	 * @param int $limit Limit.
	 * @param int $ttl Lock TTL seconds.
	 * @return array
	 */
	public function claim_due_events( $limit, $ttl = 90 ) {
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
				"SELECT id FROM {$table}
				WHERE (status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				ORDER BY CASE WHEN event_type = 'ProductUpdated' THEN 0 ELSE 1 END, id ASC
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
		$claim_token     = str_replace( '-', '', wp_generate_uuid4() );
		$update_args     = array_merge( array( $locked_until, $claim_token, $now ), $ids, array( $now, $now ) );
		$updated         = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $update_args contains two fixed values, dynamic %d ID replacements, and two trailing fixed values; wpdb::prepare() expands the single array at runtime.
				"UPDATE {$table}
				SET status = 'processing', locked_until = %s, claim_token = %s, updated_at = %s
				WHERE id IN ({$id_placeholders})
					AND ((status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s))",
				$update_args
			)
		);

		if ( false === $updated || absint( $updated ) <= 0 ) {
			return array();
		}

		self::invalidate_summary_cache();

		$select_args = array_merge( $ids, array( $claim_token ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_uuid, remote_event_id, event_type, entity_type, entity_guid, sync_id, claim_token,
					try_count, expires_at, payload_json, created_at, updated_at
				FROM {$table}
				WHERE id IN ({$id_placeholders}) AND status = 'processing' AND claim_token = %s
				ORDER BY CASE WHEN event_type = 'ProductUpdated' THEN 0 ELSE 1 END, id ASC",
				$select_args
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['_mobo_bulk_claimed'] = 1;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Release any still-processing rows from a bulk claim.
	 *
	 * Completed/retried/failed rows are not affected because the status condition
	 * only matches rows that were claimed but not handled before a time/upgrade stop.
	 *
	 * @param array $ids Claimed row IDs.
	 * @return int
	 */
	public function release_claimed_events( $ids, $claim_token = '' ) {
		global $wpdb;

		$ids         = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( empty( $ids ) || '' === $claim_token || ! self::table_exists() ) {
			return 0;
		}

		$table  = self::table_name();
		$id_sql = implode( ',', $ids );
		$now    = current_time( 'mysql', true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'pending', locked_until = NULL, claim_token = '', updated_at = %s
				WHERE status = 'processing' AND claim_token = %s AND id IN ({$id_sql})",
				$now,
				$claim_token
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Get due pending events.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_due_events( $limit ) {
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
					status = 'pending'
					AND (next_retry_at IS NULL OR next_retry_at <= %s)
				)
				OR (
					status = 'processing'
					AND locked_until IS NOT NULL
					AND locked_until < %s
				)
				ORDER BY CASE WHEN event_type = 'ProductUpdated' THEN 0 ELSE 1 END, id ASC
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
	 * Lock a pending/stale event for processing.
	 *
	 * @param int $id Event ID.
	 * @param int $ttl TTL seconds.
	 * @return string|false Claim token or false.
	 */
	public function lock_event( $id, $ttl = 90 ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$table        = self::table_name();
		$now          = current_time( 'mysql', true );
		$locked_until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );
		$claim_token  = str_replace( '-', '', wp_generate_uuid4() );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', locked_until = %s, claim_token = %s, updated_at = %s
				WHERE id = %d
				AND (
					status = 'pending'
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)",
				$locked_until,
				$claim_token,
				$now,
				$id,
				$now
			)
		);

		if ( 1 === absint( $updated ) ) {
			self::invalidate_summary_cache();
			return $claim_token;
		}

		return false;
	}

	/**
	 * Convert row to processable queue item.
	 *
	 * @param array $row Row.
	 * @return array|WP_Error
	 */
	public function row_to_item( $row ) {
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'mobo_core_invalid_event_row', 'Invalid event row.' );
		}

		$payload = json_decode( (string) $row['payload_json'], true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mobo_core_invalid_event_json', 'Invalid event payload JSON.' );
		}

		if ( isset( $row['entity_guid'] ) && '' !== trim( (string) $row['entity_guid'] ) && ! isset( $payload['entityGuid'] ) ) {
			$payload['entityGuid'] = sanitize_text_field( (string) $row['entity_guid'] );
		}

		return array(
			'id'            => isset( $row['event_uuid'] ) ? sanitize_text_field( (string) $row['event_uuid'] ) : '',
			'remoteEventId' => isset( $row['remote_event_id'] ) ? sanitize_text_field( (string) $row['remote_event_id'] ) : '',
			'event'         => isset( $row['event_type'] ) ? sanitize_text_field( (string) $row['event_type'] ) : '',
			'syncId'        => isset( $row['sync_id'] ) ? sanitize_text_field( (string) $row['sync_id'] ) : '',
			'try'       => isset( $row['try_count'] ) ? absint( $row['try_count'] ) : 0,
			'createdAt' => isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] ) : time(),
			'updatedAt' => isset( $row['updated_at'] ) ? strtotime( (string) $row['updated_at'] ) : time(),
			'expiresAt' => isset( $row['expires_at'] ) ? strtotime( (string) $row['expires_at'] ) : 0,
			'payload'   => $payload,
		);
	}

	/**
	 * Mark event as completed.
	 *
	 * @param int $id Event ID.
	 * @return bool
	 */
	public function mark_done( $id, $claim_token = '' ) {
		return $this->update_status( $id, 'done', array( 'locked_until' => null, 'claim_token' => '', 'next_retry_at' => null, 'last_error' => null ), $claim_token );
	}

	/**
	 * Mark event as completed while keeping diagnostic progress.
	 *
	 * @param int   $id Event ID.
	 * @param array $payload Event payload.
	 * @param array $progress Progress/diagnostic data.
	 * @return bool
	 */
	public function mark_done_with_progress( $id, $payload = array(), $progress = array(), $claim_token = '' ) {
		$fields = array(
			'locked_until'  => null,
			'claim_token'   => '',
			'next_retry_at' => null,
			'last_error'    => null,
		);

		if ( is_array( $payload ) && ! empty( $payload ) ) {
			$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false !== $payload_json ) {
				$fields['payload_json'] = $payload_json;
			}
		}

		if ( is_array( $progress ) && ! empty( $progress ) ) {
			$progress_json = wp_json_encode( $progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false !== $progress_json ) {
				$fields['progress_json'] = $progress_json;
			}
		}

		return $this->update_status( $id, 'done', $fields, $claim_token );
	}

	/**
	 * Retire UpdateVariant events that have been waiting for a missing parent too long.
	 *
	 * @param int $timeout_seconds Maximum wait age in seconds.
	 * @param int $limit Maximum rows to retire.
	 * @return int Number of retired events.
	 */
	public function retire_stale_parent_waiting_events( $timeout_seconds = 600, $limit = 200 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$timeout_seconds = max( 60, absint( $timeout_seconds ) );
		$limit           = max( 1, min( 1000, absint( $limit ) ) );
		$cutoff          = gmdate( 'Y-m-d H:i:s', time() - $timeout_seconds );
		$table           = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload_json, progress_json, created_at FROM {$table}
				WHERE status = 'pending'
				AND event_type = 'UpdateVariant'
				AND created_at <= %s
				AND progress_json LIKE %s
				ORDER BY id ASC
				LIMIT %d",
				$cutoff,
				'%waitingForParent%',
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return 0;
		}

		$retired = 0;

		foreach ( $rows as $row ) {
			$progress = json_decode( isset( $row['progress_json'] ) ? (string) $row['progress_json'] : '', true );

			if ( ! is_array( $progress ) || empty( $progress['waitingForParent'] ) ) {
				continue;
			}

			$payload = json_decode( isset( $row['payload_json'] ) ? (string) $row['payload_json'] : '', true );
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}

			$created_at = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] . ' UTC' ) : 0;
			$wait_age   = $created_at > 0 ? max( 0, time() - $created_at ) : $timeout_seconds;

			$progress['deleteFile']               = true;
			$progress['retiredBecause']           = 'parent_wait_timeout';
			$progress['retiredAt']                = gmdate( 'Y-m-d H:i:s' );
			$progress['parentWaitTimeoutSeconds'] = $timeout_seconds;
			$progress['parentWaitAgeSeconds']     = $wait_age;

			$payload_json  = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$progress_json = wp_json_encode( $progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$updated = $wpdb->update(
				$table,
				array(
					'status'        => 'done',
					'payload_json'  => false === $payload_json ? '{}' : $payload_json,
					'progress_json' => false === $progress_json ? '{}' : $progress_json,
					'locked_until'  => null,
					'claim_token'   => '',
					'next_retry_at' => null,
					'last_error'    => null,
					'updated_at'    => current_time( 'mysql', true ),
				),
				array(
					'id'     => absint( $row['id'] ),
					'status' => 'pending',
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( 1 === absint( $updated ) ) {
				self::invalidate_summary_cache();
				$retired++;
			}
		}

		return $retired;
	}

	/**
	 * Keep partially processed event pending with updated payload/progress.
	 *
	 * @param int   $id Event ID.
	 * @param array $payload Updated payload.
	 * @param array $progress Progress data.
	 * @return bool
	 */
	public function mark_pending_progress( $id, $payload, $progress = array(), $claim_token = '' ) {
		$payload_json  = wp_json_encode( is_array( $payload ) ? $payload : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$progress_json = wp_json_encode( is_array( $progress ) ? $progress : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$defer_seconds = is_array( $progress ) && isset( $progress['deferSeconds'] ) ? absint( $progress['deferSeconds'] ) : 0;

		return $this->update_status(
			$id,
			'pending',
			array(
				'payload_json'  => false === $payload_json ? '{}' : $payload_json,
				'progress_json' => false === $progress_json ? '{}' : $progress_json,
				'locked_until'  => null,
				'claim_token'   => '',
				'next_retry_at' => $defer_seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $defer_seconds ) : null,
				'last_error'    => null,
			),
			$claim_token
		);
	}

	/**
	 * Mark event retry or failure.
	 *
	 * @param int    $id Event ID.
	 * @param string $message Error.
	 * @param int    $try_count New try count.
	 * @param bool   $final_failed Mark as failed.
	 * @return bool
	 */
	public function mark_failure( $id, $message, $try_count, $final_failed = false, $claim_token = '' ) {
		$status = $final_failed ? 'failed' : 'pending';
		$delay  = $final_failed ? null : min( 300, max( 30, absint( $try_count ) * 30 ) );

		return $this->update_status(
			$id,
			$status,
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => null === $delay ? null : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_until'  => null,
				'claim_token'   => '',
				'last_error'    => sanitize_text_field( (string) $message ),
			),
			$claim_token
		);
	}

	/**
	 * Mark event as pending and immediately due for retry.
	 *
	 * This is used for transient payload-pull failures when no real cron/central
	 * runner exists to wake the site later. Max-try still prevents infinite loops.
	 *
	 * @param int    $id Event ID.
	 * @param string $message Error.
	 * @param int    $try_count New try count.
	 * @param bool   $final_failed Mark as failed.
	 * @return bool
	 */
	public function mark_retry_now( $id, $message, $try_count, $final_failed = false, $claim_token = '' ) {
		return $this->update_status(
			$id,
			$final_failed ? 'failed' : 'pending',
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => null,
				'locked_until'  => null,
				'claim_token'   => '',
				'last_error'    => sanitize_text_field( (string) $message ),
			),
			$claim_token
		);
	}

	/**
	 * Count due events that can be attempted now.
	 *
	 * @return int
	 */
	public function count_due() {
		$summary = $this->get_summary();
		return absint( isset( $summary['dueCount'] ) ? $summary['dueCount'] : 0 );
	}

	/**
	 * Count pending events.
	 *
	 * @return int
	 */
	public function count_pending() {
		$summary = $this->get_summary();
		return absint( isset( $summary['pendingCount'] ) ? $summary['pendingCount'] : 0 );
	}

	/**
	 * Count failed events.
	 *
	 * @return int
	 */
	public function count_failed() {
		$summary = $this->get_summary();
		return absint( isset( $summary['failedCount'] ) ? $summary['failedCount'] : 0 );
	}

	/**
	 * Re-arm queue rows stranded by pre-10.33.44.3 processor exceptions.
	 *
	 * Older nullable-stock failures could escape before mark_failure(), leaving a
	 * stale processing lease with try_count=0 forever from the operator's point of
	 * view. Rows that did reach the retry path can also retain the old stock error
	 * and a deferred next_retry_at. Failed/terminal history is intentionally not
	 * replayed because a newer desired-state event may already have superseded it.
	 *
	 * @param int $limit Maximum active rows to recover.
	 * @return int Number of rows re-armed.
	 */
	public function recover_legacy_nullable_stock_blockers( $limit = 500 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$limit            = max( 1, min( 1000, absint( $limit ) ) );
		$table            = self::table_name();
		$now              = current_time( 'mysql', true );
		$stock_error_like = '%' . $wpdb->esc_like( 'stock' ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE (status = 'processing' AND (locked_until IS NULL OR locked_until < %s))
					OR (status = 'pending' AND try_count > 0 AND last_error LIKE %s)
				ORDER BY id ASC
				LIMIT %d",
				$now,
				$stock_error_like,
				$limit
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$id_sql  = implode( ',', $ids );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'pending', try_count = 0, next_retry_at = NULL,
					locked_until = NULL, claim_token = '', last_error = NULL, updated_at = %s
				WHERE id IN ({$id_sql})",
				$now
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Re-queue failed events for another attempt.
	 *
	 * @param int $limit Maximum events to re-queue.
	 * @return int Number of events updated.
	 */
	public function retry_failed_events( $limit = 200 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$limit = max( 1, min( 1000, absint( $limit ) ) );
		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'failed' ORDER BY id ASC LIMIT %d",
				$limit
			)
		);

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return 0;
		}

		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $now ), $ids );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'pending', try_count = 0, next_retry_at = NULL, locked_until = NULL, claim_token = '', last_error = NULL, updated_at = %s
				WHERE id IN ({$placeholders})",
				$params
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Count statuses.
	 *
	 * @param array $statuses Status list.
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
	 * Update row status and fields.
	 *
	 * @param int    $id Event ID.
	 * @param string $status Status.
	 * @param array  $fields Additional fields.
	 * @return bool
	 */
	private function update_status( $id, $status, $fields = array(), $claim_token = '' ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
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
			if ( in_array( $key, array( 'try_count' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$claim_token = sanitize_text_field( (string) $claim_token );
		if ( '' !== $claim_token ) {
			$table = self::table_name();
			$sets  = array();
			$args  = array();
			foreach ( $data as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key ) {
					continue;
				}
				$sets[] = $key . ' = ' . ( null === $value ? 'NULL' : ( 'try_count' === $key ? '%d' : '%s' ) );
				if ( null !== $value ) {
					$args[] = $value;
				}
			}
			$args[] = $id;
			$args[] = $claim_token;
			$updated = empty( $sets ) ? false : $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE id = %d AND status = 'processing' AND claim_token = %s",
					$args
				)
			);
		} else {
			$updated = $wpdb->update( self::table_name(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}
		if ( false !== $updated ) {
			self::invalidate_summary_cache();
		}

		if ( '' !== $claim_token ) {
			return 1 === (int) $updated;
		}

		return false !== $updated;
	}

	private function build_local_dedupe_id( $event_type, $payload, $sync_id, $entity_type, $entity_guid ) {
		$event_type = sanitize_text_field( (string) $event_type );
		$sync_id    = sanitize_text_field( (string) $sync_id );

		if ( 'UpdateVariant' === $event_type ) {
			$identity_key = $this->build_update_variant_identity_key( $payload );

			if ( '' !== $identity_key ) {
				return 'local:updatevariant:' . md5( $sync_id . '|' . $identity_key );
			}
		}

		return '';
	}

	private function build_update_variant_identity_key( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$variant_guids = $this->extract_update_variant_variant_guids( $payload );

		if ( ! empty( $variant_guids ) ) {
			$variant_guids = array_values( array_unique( array_map( 'strval', $variant_guids ) ) );
			sort( $variant_guids );

			return 'variants:' . implode( ',', $variant_guids );
		}

		$product_guid = $this->extract_update_variant_product_guid( $payload );
		$page_number  = absint( $this->get_value( $payload, 'pageNumber', 0 ) );
		$payload_url  = $this->first_non_empty(
			array(
				$this->get_value( $payload, 'changesUrl', '' ),
				$this->get_value( $payload, 'payloadUrl', '' ),
				$this->get_value( $payload, 'url', '' ),
				$this->get_value( $payload, '_moboPulledFrom', '' ),
			)
		);
		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$payload_hash = false === $payload_json ? '' : md5( $payload_json );

		if ( '' !== $product_guid || '' !== $payload_hash ) {
			return 'product:' . $product_guid . '|page:' . $page_number . '|url:' . $payload_url . '|payload:' . $payload_hash;
		}

		return '';
	}

	private function extract_update_variant_variant_guids( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$guids = array();
		$this->append_variant_guid_from_item( $payload, $guids );

		$data = $this->get_value( $payload, 'data', null );

		if ( is_array( $data ) && ! $this->is_list_array( $data ) ) {
			$this->append_variant_guid_from_item( $data, $guids );

			$nested_data = $this->get_value( $data, 'data', null );
			if ( is_array( $nested_data ) ) {
				$data = $nested_data;
			}
		}

		if ( is_array( $data ) && $this->is_list_array( $data ) ) {
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$this->append_variant_guid_from_item( $item, $guids );
				}
			}
		}

		$variants = $this->get_value( $payload, 'variants', null );
		if ( ! is_array( $variants ) ) {
			$variants = $this->get_value( $payload, 'Variants', null );
		}
		if ( is_array( $variants ) ) {
			foreach ( $variants as $variant ) {
				if ( is_array( $variant ) ) {
					$this->append_variant_guid_from_item( $variant, $guids );
				}
			}
		}

		$guids = array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $guids ) ) );

		return array_values( array_unique( $guids ) );
	}

	private function append_variant_guid_from_item( $item, &$guids ) {
		if ( ! is_array( $item ) ) {
			return;
		}

		$guid = $this->first_non_empty(
			array(
				$this->get_value( $item, 'variant_guid', '' ),
				$this->get_value( $item, 'variantGuid', '' ),
				$this->get_value( $item, 'variantId', '' ),
				$this->get_value( $item, 'remote_guid', '' ),
				$this->get_value( $item, 'remoteGuid', '' ),
				$this->get_value( $item, 'entity_guid', '' ),
				$this->get_value( $item, 'entityGuid', '' ),
				$this->get_value( $item, 'entityId', '' ),
				$this->get_value( $item, 'guid', '' ),
			)
		);

		if ( '' !== $guid ) {
			$guids[] = $guid;
		}
	}

	private function extract_update_variant_product_guid( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$product_guid = $this->first_non_empty(
			array(
				$this->get_value( $payload, 'product_guid', '' ),
				$this->get_value( $payload, 'productGuid', '' ),
				$this->get_value( $payload, 'productId', '' ),
				$this->get_value( $payload, 'parentProductId', '' ),
				$this->get_value( $payload, 'parentGuid', '' ),
			)
		);

		if ( '' !== $product_guid ) {
			return sanitize_text_field( (string) $product_guid );
		}

		$data = $this->get_value( $payload, 'data', null );

		if ( is_array( $data ) && ! $this->is_list_array( $data ) ) {
			$product_guid = $this->first_non_empty(
				array(
					$this->get_value( $data, 'product_guid', '' ),
					$this->get_value( $data, 'productGuid', '' ),
					$this->get_value( $data, 'productId', '' ),
					$this->get_value( $data, 'parentProductId', '' ),
					$this->get_value( $data, 'parentGuid', '' ),
				)
			);

			if ( '' !== $product_guid ) {
				return sanitize_text_field( (string) $product_guid );
			}

			$data = $this->get_value( $data, 'data', null );
		}

		if ( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			$product_guid = $this->first_non_empty(
				array(
					$this->get_value( $data[0], 'product_guid', '' ),
					$this->get_value( $data[0], 'productGuid', '' ),
					$this->get_value( $data[0], 'productId', '' ),
					$this->get_value( $data[0], 'parentProductId', '' ),
					$this->get_value( $data[0], 'parentGuid', '' ),
				)
			);
		}

		return sanitize_text_field( (string) $product_guid );
	}

	/**
	 * Normalize wrapper and extract event metadata.
	 *
	 * @param array $raw Raw payload.
	 * @return array
	 */
	private function normalize_payload( $raw ) {
		$event_type = $this->detect_event( $raw );
		$payload    = $raw;

		$data = $this->get_value( $raw, 'data', null );

		if ( is_string( $data ) && '' !== trim( $data ) ) {
			$decoded = json_decode( $data, true );

			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		} elseif ( is_array( $data ) && ( isset( $raw['type'] ) || isset( $raw['event'] ) || isset( $raw['Type'] ) ) ) {
			/*
			 * Old EventWebhook wrapper may contain the actual payload inside data.
			 * If data is only the raw item list, wrap it back into the payload shape
			 * expected by product/variant processors.
			 */
			if ( $this->is_list_array( $data ) ) {
				$payload = array( 'data' => $data );

				$product_id = $this->get_value( $raw, 'product_guid', '' );
				if ( '' !== $product_id ) {
					$payload['productId'] = $product_id;
				}

				$page_number = $this->get_value( $raw, 'pageNumber', '' );
				if ( '' !== $page_number ) {
					$payload['pageNumber'] = $page_number;
				}

				$has_more = $this->get_value( $raw, 'hasMore', null );
				if ( null !== $has_more ) {
					$payload['hasMore'] = $has_more;
				}

				$total_count = $this->get_value( $raw, 'totalCount', null );
				if ( null !== $total_count ) {
					$payload['totalCount'] = $total_count;
				}
			} else {
				$payload = $data;
			}
		}

		if ( '' === $event_type ) {
			$event_type = $this->detect_event( $payload );
		}

		$sync_id = $this->first_non_empty(
			array(
				$this->get_value( $raw, 'syncId', '' ),
				$this->get_value( $payload, 'syncId', '' ),
			)
		);

		if ( '' !== $sync_id && ! isset( $payload['syncId'] ) ) {
			$payload['syncId'] = $sync_id;
		}

		$entity = $this->extract_entity( $event_type, $payload );

		return array(
			'eventType'     => $event_type,
			'payload'       => $payload,
			'remoteEventId' => $this->first_non_empty(
				array(
					$this->get_value( $raw, 'eventId', '' ),
					$this->get_value( $raw, 'event_id', '' ),
					$this->get_value( $raw, 'id', '' ),
					$this->get_value( $raw, 'webhookId', '' ),
					$this->get_value( $raw, 'WebhookId', '' ),
				)
			),
			'entityType'    => $entity['type'],
			'entityGuid'    => $entity['guid'],
			'syncId'        => $sync_id,
			'version'       => $this->first_non_empty(
				array(
					$this->get_value( $raw, 'version', '' ),
					$this->get_value( $raw, 'Version', '' ),
					$this->get_value( $raw, 'entityVersion', '' ),
				)
			),
		);
	}

	/**
	 * Extract entity metadata.
	 *
	 * @param string $event_type Event type.
	 * @param array  $payload Payload.
	 * @return array
	 */
	private function extract_entity( $event_type, $payload ) {
		$event_type = sanitize_text_field( (string) $event_type );
		$guid       = '';
		$type       = '';

		if ( 'UpdateVariant' === $event_type ) {
			$variant_guids = $this->extract_update_variant_variant_guids( $payload );

			if ( 1 === count( $variant_guids ) ) {
				$type = 'variant';
				$guid = $variant_guids[0];
			} else {
				$product_guid = $this->extract_update_variant_product_guid( $payload );

				if ( '' !== $product_guid ) {
					$type = 'product';
					$guid = $product_guid;
				}
			}
		} elseif ( 'ShippingMethodsChanged' === $event_type || 'WebhookDeliveryStatusChanged' === $event_type ) {
			/* extract_entity() has one internal contract: type/guid. Returning the
			 * normalized output names here made normalize_event() read undefined keys. */
			return array(
				'type' => 'system',
				'guid' => '',
			);
		} elseif ( 'ProductUpdated' === $event_type ) {
			$type = 'product';
			$guid = $this->first_non_empty(
				array(
					$this->get_value( $payload, 'product_guid', '' ),
					$this->get_value( $payload, 'productGuid', '' ),
					$this->get_value( $payload, 'productId', '' ),
					$this->get_value( $payload, 'entityGuid', '' ),
				)
			);

			$items = $this->get_value( $payload, 'data', array() );

			if ( '' === $guid && is_array( $items ) && isset( $items[0] ) && is_array( $items[0] ) ) {
				$guid = $this->first_non_empty( array(
					$this->get_value( $items[0], 'product_guid', '' ),
					$this->get_value( $items[0], 'productGuid', '' ),
					$this->get_value( $items[0], 'productId', '' ),
					$this->get_value( $items[0], 'guid', '' ),
					$this->get_value( $items[0], 'id', '' ),
				) );
			}
		}

		return array(
			'type' => sanitize_key( (string) $type ),
			'guid' => sanitize_text_field( (string) $guid ),
		);
	}

	/**
	 * Detect event type.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function detect_event( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$event = $this->first_non_empty(
			array(
				$this->get_value( $payload, 'event', '' ),
				$this->get_value( $payload, 'type', '' ),
				$this->get_value( $payload, 'Type', '' ),
			)
		);

		if ( is_numeric( $event ) ) {
			$event = $this->map_numeric_event_type( absint( $event ) );
		}

		return sanitize_text_field( (string) $event );
	}

	/**
	 * Map old numeric event type if required.
	 *
	 * @param int $type Numeric event type.
	 * @return string
	 */
	private function map_numeric_event_type( $type ) {
		$map = array(
			0 => 'ProductUpdated',
			1 => 'UpdateVariant',
			2 => 'ProductUpdated',
			4 => 'UpdateVariant',
			20 => 'ShippingMethodsChanged',
			21 => 'WebhookDeliveryStatusChanged',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Determine whether an array is a zero-based list.
	 *
	 * @param array $array Array.
	 * @return bool
	 */
	private function is_list_array( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		$expected = 0;

		foreach ( array_keys( $array ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}

			$expected++;
		}

		return true;
	}

	/**
	 * First non-empty scalar.
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private function first_non_empty( $values ) {
		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Case-tolerant getter.
	 *
	 * @param array  $array Source.
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

		if ( array_key_exists( $pascal, $array ) ) {
			return $array[ $pascal ];
		}

		return $default;
	}
}
