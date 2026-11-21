<?php
/**
 * Bounded Product/Variation custom-storage backfill.
 *
 * The legacy mobo_product_map table is the cursor source. Backfill never scans
 * wp_postmeta globally: it reads a small ordered map batch, resolves only those
 * mapped Woo objects and mirrors their already-committed legacy state through
 * Mobo_Core_Storage_Bridge. Runtime reads remain legacy-only.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Backfill {

	const MIGRATION_KEY     = 'product_variant_state_v1';
	const MIGRATION_VERSION = 1;
	const DEFAULT_BATCH     = 50;
	const MAX_BATCH         = 250;

	/** @return string */
	private static function migration_table() {
		return Mobo_Core_Storage_Schema::table_name( 'migrations' );
	}

	/** Active durable Connection owner for this backfill lane. @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/**
	 * Validate the current durable owner and, only for Default, adopt a legacy
	 * connection_id=0 cursor. Non-Default Connections get independent rows and
	 * must never inherit the pre-Multi-Connection cursor.
	 *
	 * @param int $connection_id Durable Connection id.
	 * @return bool
	 */
	private static function prepare_owner_state( $connection_id ) {
		global $wpdb;
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) { return false; }
		$row = Mobo_Core_Connection_Repository::get_by_id( $connection_id );
		if ( ! is_array( $row ) || empty( $row['is_enabled'] ) ) { return false; }
		if ( empty( $row['is_default'] ) ) { return true; }
		if ( ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return false; }

		$table = self::migration_table();
		$owned = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE connection_id=%d AND migration_key=%s LIMIT 1", $connection_id, self::MIGRATION_KEY ) ) );
		if ( $owned > 0 ) { return true; }
		$legacy = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE connection_id=0 AND migration_key=%s ORDER BY id ASC LIMIT 1", self::MIGRATION_KEY ) ) );
		if ( $legacy <= 0 ) { return true; }
		return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET connection_id=%d WHERE id=%d AND connection_id=0", $connection_id, $legacy ) );
	}

	/**
	 * Create the durable migration row without doing any catalog work.
	 * Existing progress for the same migration version is preserved.
	 *
	 * @return bool
	 */
	public static function schedule() {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::prepare_owner_state( $connection_id ) ) { return false; }
		$table = self::migration_table();
		$now   = current_time( 'mysql', true );
		$sql   = "INSERT INTO {$table}
			(connection_id, migration_key, migration_version, status, cursor_bigint, cursor_text, attempt_count, last_error, created_at, updated_at)
			VALUES (%d, %s, %d, 'pending', 0, '0', 0, NULL, %s, %s)
			ON DUPLICATE KEY UPDATE
				status = IF(migration_version < VALUES(migration_version), 'pending', status),
				cursor_bigint = IF(migration_version < VALUES(migration_version), 0, cursor_bigint),
				cursor_text = IF(migration_version < VALUES(migration_version), '0', cursor_text),
				attempt_count = IF(migration_version < VALUES(migration_version), 0, attempt_count),
				last_error = IF(migration_version < VALUES(migration_version), NULL, last_error),
				started_at = IF(migration_version < VALUES(migration_version), NULL, started_at),
				completed_at = IF(migration_version < VALUES(migration_version), NULL, completed_at),
				updated_at = IF(migration_version < VALUES(migration_version), VALUES(updated_at), updated_at),
				migration_version = GREATEST(migration_version, VALUES(migration_version))";

		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, self::MIGRATION_KEY, self::MIGRATION_VERSION, $now, $now ) );
		return false !== $result;
	}

	/**
	 * Whether the current runtime is allowed to advance backfill.
	 *
	 * Backfill is deliberately dormant in legacy mode. This guarantees that a
	 * customer who has not entered the dual-write rollout pays no catalog-walk
	 * cost and prevents a one-time backfill from immediately becoming stale.
	 *
	 * @return bool
	 */
	public static function should_run() {
		return class_exists( 'Mobo_Core_Storage_Mode' )
			&& Mobo_Core_Storage_Mode::writes_custom()
			&& class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready()
			&& class_exists( 'Mobo_Core_Product_Map' )
			&& Mobo_Core_Product_Map::table_exists();
	}

	/**
	 * Return compact durable progress for health/tests.
	 *
	 * @return array
	 */
	public static function get_status() {
		global $wpdb;

		$base = array(
			'connectionId'     => self::connection_id(),
			'migrationKey'     => self::MIGRATION_KEY,
			'migrationVersion' => self::MIGRATION_VERSION,
			'status'           => 'unavailable',
			'cursor'           => 0,
			'gaps'             => 0,
			'attemptCount'     => 0,
			'lastError'        => '',
			'startedAt'        => '',
			'completedAt'      => '',
			'updatedAt'        => '',
			'runnable'         => self::should_run(),
		);

		if ( ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			$base['status'] = 'schema-unavailable';
			return $base;
		}

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::prepare_owner_state( $connection_id ) ) { $base['status'] = 'owner-unavailable'; return $base; }
		$table = self::migration_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND migration_key = %s LIMIT 1", $connection_id, self::MIGRATION_KEY ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			$base['status'] = 'not-scheduled';
			return $base;
		}

		$base['status']       = sanitize_key( (string) ( isset( $row['status'] ) ? $row['status'] : 'pending' ) );
		$base['cursor']       = absint( isset( $row['cursor_bigint'] ) ? $row['cursor_bigint'] : 0 );
		$base['gaps']         = absint( isset( $row['cursor_text'] ) ? $row['cursor_text'] : 0 );
		$base['attemptCount'] = absint( isset( $row['attempt_count'] ) ? $row['attempt_count'] : 0 );
		$base['lastError']    = sanitize_text_field( (string) ( isset( $row['last_error'] ) ? $row['last_error'] : '' ) );
		$base['startedAt']    = sanitize_text_field( (string) ( isset( $row['started_at'] ) ? $row['started_at'] : '' ) );
		$base['completedAt']  = sanitize_text_field( (string) ( isset( $row['completed_at'] ) ? $row['completed_at'] : '' ) );
		$base['updatedAt']    = sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) );
		return $base;
	}

	/**
	 * Process at most one ordered legacy-map batch.
	 *
	 * Cursor advances only across successful mirrors or rows proven stale. A live,
	 * representable object that fails to mirror is retried on a later real-runner
	 * invocation; the failed row is never silently skipped.
	 *
	 * @param int $limit Maximum map rows for this invocation.
	 * @return array
	 */
	public static function process_batch( $limit = self::DEFAULT_BATCH, $trusted_pre_fence = false ) {
		global $wpdb;

		$limit = max( 1, min( self::MAX_BATCH, absint( $limit ) ) );
		$result = array(
			'success'   => true,
			'status'    => 'dormant',
			'processed' => 0,
			'mirrored'  => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'gaps'      => 0,
			'cursor'    => 0,
			'remaining' => false,
		);

		if ( ! self::should_run() ) {
			$status = self::get_status();
			$result['status']    = Mobo_Core_Storage_Mode::writes_custom() ? 'blocked' : 'dormant';
			$result['cursor']    = absint( isset( $status['cursor'] ) ? $status['cursor'] : 0 );
			$result['gaps']      = absint( isset( $status['gaps'] ) ? $status['gaps'] : 0 );
			$result['remaining'] = ! in_array( sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) ), array( 'completed', 'completed-gaps' ), true );
			return $result;
		}

		if ( ! self::schedule() ) {
			$result['success'] = false;
			$result['status']  = 'schedule-failed';
			$result['failed']  = 1;
			return $result;
		}

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::prepare_owner_state( $connection_id ) ) { $result['success']=false; $result['status']='owner-unavailable'; $result['failed']=1; return $result; }
		$migration_table = self::migration_table();
		$state = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$migration_table} WHERE connection_id=%d AND migration_key = %s LIMIT 1", $connection_id, self::MIGRATION_KEY ), ARRAY_A );
		if ( ! is_array( $state ) ) {
			$result['success'] = false;
			$result['status']  = 'state-missing';
			$result['failed']  = 1;
			return $result;
		}

		$status = sanitize_key( (string) ( isset( $state['status'] ) ? $state['status'] : 'pending' ) );
		$cursor = absint( isset( $state['cursor_bigint'] ) ? $state['cursor_bigint'] : 0 );
		$gaps   = absint( isset( $state['cursor_text'] ) ? $state['cursor_text'] : 0 );
		$result['cursor'] = $cursor;
		$result['gaps']   = $gaps;

		if ( in_array( $status, array( 'completed', 'completed-gaps' ), true ) ) {
			$result['status']    = $status;
			$result['remaining'] = false;
			return $result;
		}

		$map_table = Mobo_Core_Product_Map::table_name();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete
				 FROM {$map_table}
				 WHERE connection_id=%d AND id > %d AND object_type IN (%s, %s)
				 ORDER BY id ASC
				 LIMIT %d",
				$connection_id,
				$cursor,
				Mobo_Core_Product_Map::TYPE_PRODUCT,
				Mobo_Core_Product_Map::TYPE_VARIATION,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$final_status = $gaps > 0 ? 'completed-gaps' : 'completed';
			self::persist_progress( $cursor, $gaps, $final_status, '', true, false );
			$result['status']    = $final_status;
			$result['remaining'] = false;
			return $result;
		}

		/* Prime only the bounded Post objects used by this backfill unit. Do not load
		 * full postmeta for the page: bridge reads remain authoritative and the small
		 * unit size keeps sudden host termination replay-safe. Post-object priming
		 * removes repeated type/status/parent queries without creating a large retained
		 * meta cache across a multi-iteration Cron slice. */
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'wp_post_id' ) ) ) ) );
		if ( ! empty( $post_ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}
		/* MIGOPT09: bulk-prime only this bounded 100-row unit's Legacy postmeta.
		 * WordPress otherwise lazy-loads the full meta cache once per object, which
		 * creates avoidable query fan-out. The cache is explicitly released in the
		 * finally block below so a fast multi-iteration Cron slice cannot retain one
		 * completed unit after another until request shutdown. */
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		$started = ! empty( $state['started_at'] );
		$legacy_scope = (bool) $trusted_pre_fence
			&& class_exists( 'Mobo_Core_Storage_Bridge' )
			&& method_exists( 'Mobo_Core_Storage_Bridge', 'begin_migration_pre_fence_legacy_reads' );
		if ( $legacy_scope ) {
			Mobo_Core_Storage_Bridge::begin_migration_pre_fence_legacy_reads();
		}
		try {
		foreach ( $rows as $row ) {
			$row_id      = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			$guid        = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			$post_id     = absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 );
			$object_type = sanitize_key( (string) ( isset( $row['object_type'] ) ? $row['object_type'] : '' ) );
			$incomplete  = ! empty( $row['sync_incomplete'] );

			if ( $row_id <= 0 ) {
				continue;
			}

			$expected_type = Mobo_Core_Product_Map::TYPE_VARIATION === $object_type ? 'product_variation' : 'product';
			$current_type  = $post_id > 0 ? get_post_type( $post_id ) : false;
			$current_status = $post_id > 0 ? sanitize_key( (string) get_post_status( $post_id ) ) : '';

			/* Deleted/trash/retargeted map rows are legacy residue, not backfill gaps. */
			if ( $post_id <= 0 || $current_type !== $expected_type || in_array( $current_status, array( 'trash', 'auto-draft' ), true ) ) {
				$cursor = $row_id;
				$result['processed']++;
				$result['skipped']++;
				continue;
			}

			if ( ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $guid ) ) {
				$gaps++;
				$cursor = $row_id;
				$result['processed']++;
				$result['skipped']++;
				continue;
			}

			$mirrored = false;
			if ( Mobo_Core_Product_Map::TYPE_PRODUCT === $object_type ) {
				$mirrored = Mobo_Core_Storage_Bridge::mirror_product_legacy_state( $guid, $post_id, $incomplete );
			} elseif ( Mobo_Core_Product_Map::TYPE_VARIATION === $object_type ) {
				$parent_guid = self::resolve_parent_guid( $row, $post_id );
				if ( '' === $parent_guid ) {
					$gaps++;
					$cursor = $row_id;
					$result['processed']++;
					$result['skipped']++;
					continue;
				}
				$mirrored = Mobo_Core_Storage_Bridge::mirror_variant_legacy_state( $guid, $post_id, $parent_guid, $incomplete );
			}

			if ( ! $mirrored ) {
				$message = sprintf( 'Custom-storage backfill failed for %s %s at legacy map id %d.', $object_type, $guid, $row_id );
				self::persist_progress( $cursor, $gaps, 'retry', $message, false, true );
				$result['success']   = false;
				$result['status']    = 'retry';
				$result['failed']    = 1;
				$result['cursor']    = $cursor;
				$result['gaps']      = $gaps;
				$result['remaining'] = true;
				return $result;
			}

			$cursor = $row_id;
			$result['processed']++;
			$result['mirrored']++;
		}
		} finally {
			if ( $legacy_scope ) {
				Mobo_Core_Storage_Bridge::end_migration_pre_fence_legacy_reads();
			}
			/* Keep cache lifetime equal to the durable Backfill unit. Replays after a
			 * kill still rebuild from Legacy, while successful units do not accumulate
			 * heavy wp_postmeta caches across the rest of the Cron request. */
			foreach ( $post_ids as $post_id ) {
				$post_id = absint( $post_id );
				if ( $post_id > 0 ) { wp_cache_delete( $post_id, 'post_meta' ); }
			}
		}

		$remaining = count( $rows ) >= $limit;
		$next_status = $remaining ? 'running' : ( $gaps > 0 ? 'completed-gaps' : 'completed' );
		self::persist_progress( $cursor, $gaps, $next_status, '', ! $remaining, false, ! $started );

		$result['status']    = $next_status;
		$result['cursor']    = $cursor;
		$result['gaps']      = $gaps;
		$result['remaining'] = $remaining;
		return $result;
	}

	/**
	 * Resolve a variation's parent GUID without catalog-wide queries.
	 *
	 * @param array $row Legacy map row.
	 * @param int   $variation_id Variation post ID.
	 * @return string
	 */
	private static function resolve_parent_guid( $row, $variation_id ) {
		global $wpdb;

		$parent_guid = sanitize_text_field( (string) ( isset( $row['parent_remote_guid'] ) ? $row['parent_remote_guid'] : '' ) );
		if ( class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && Mobo_Core_Remote_Identity_Policy::is_valid( $parent_guid ) ) {
			return $parent_guid;
		}

		$parent_id = absint( wp_get_post_parent_id( absint( $variation_id ) ) );
		if ( $parent_id <= 0 ) {
			return '';
		}

		$parent_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
		if ( Mobo_Core_Remote_Identity_Policy::is_valid( $parent_guid ) ) {
			return $parent_guid;
		}

		$map_table = Mobo_Core_Product_Map::table_name();
		$parent_guid = sanitize_text_field(
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT remote_guid FROM {$map_table} WHERE connection_id=%d AND wp_post_id = %d AND object_type = %s ORDER BY id DESC LIMIT 1",
					self::connection_id(),
					$parent_id,
					Mobo_Core_Product_Map::TYPE_PRODUCT
				)
			)
		);
		return Mobo_Core_Remote_Identity_Policy::is_valid( $parent_guid ) ? $parent_guid : '';
	}

	/**
	 * Persist one durable cursor checkpoint.
	 *
	 * @return bool
	 */
	private static function persist_progress( $cursor, $gaps, $status, $last_error = '', $complete = false, $increment_attempt = false, $set_started = false ) {
		global $wpdb;

		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$table      = self::migration_table();
		$now        = current_time( 'mysql', true );
		$status     = sanitize_key( (string) $status );
		$last_error = '' === trim( (string) $last_error ) ? null : substr( sanitize_text_field( (string) $last_error ), 0, 1000 );
		$sql = "UPDATE {$table}
			SET status = %s,
				cursor_bigint = %d,
				cursor_text = %s,
				attempt_count = " . ( $increment_attempt ? 'attempt_count + 1' : '0' ) . ",
				last_error = %s,
				started_at = " . ( $set_started ? 'IFNULL(started_at, %s)' : 'started_at' ) . ",
				completed_at = " . ( $complete ? '%s' : 'NULL' ) . ",
				updated_at = %s
			WHERE connection_id=%d AND migration_key = %s";

		$args = array( $status, absint( $cursor ), (string) absint( $gaps ), $last_error );
		if ( $set_started ) { $args[] = $now; }
		if ( $complete ) { $args[] = $now; }
		$args[] = $now;
		$args[] = $connection_id;
		$args[] = self::MIGRATION_KEY;

		$updated = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		return false !== $updated;
	}
}
