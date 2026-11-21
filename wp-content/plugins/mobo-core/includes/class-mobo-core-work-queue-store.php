<?php
/** Row-based durable queue storage for Mobo operational queues. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Work_Queue_Store {
	private static $availability = null;
	/** Per-request fail-closed signal: a failed/corrupt map read cannot become an empty replacement. */
	private static $read_faults = array();

	private static function read_identity( $connection_id, $queue_type ) {
		return absint( $connection_id ) . ':' . self::queue_type( $queue_type );
	}

	/** Non-secret evidence for Health/test contracts; never exposes queue payloads. */
	public static function map_read_failed( $queue_type ) {
		return ! empty( self::$read_faults[ self::read_identity( self::connection_id(), $queue_type ) ] );
	}

	public static function table_name() {
		global $wpdb;
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'work_queue' );
			if ( '' !== $table ) { return $table; }
		}
		return $wpdb->prefix . 'mobo_work_queue';
	}
	public static function reset_availability_cache() { self::$availability = null; }
	public static function is_available() {
		global $wpdb;
		if ( null !== self::$availability ) { return (bool) self::$availability; }
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$availability = ( (string) $found === (string) $table );
		return (bool) self::$availability;
	}

	public static function load_map( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() ) { return array(); }
		$table = self::table_name();
		$identity = self::read_identity( $connection_id, $queue_type );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT dedupe_key,payload,payload_hash FROM {$table} WHERE connection_id=%d AND queue_type=%s ORDER BY id ASC", $connection_id, $queue_type ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== trim( (string) $wpdb->last_error ) ) {
			self::$read_faults[ $identity ] = true;
			return array(); // Callers must not interpret this as a safe replacement.
		}
		$out = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['dedupe_key'] ) ? (string) $row['dedupe_key'] : '';
			$raw = isset( $row['payload'] ) ? (string) $row['payload'] : '';
			$expected = isset( $row['payload_hash'] ) ? (string) $row['payload_hash'] : '';
			$value = maybe_unserialize( $raw );
			if ( '' === $key || isset( $out[ $key ] ) || ! is_array( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( $expected, hash( 'sha256', $raw ) ) ) {
				self::$read_faults[ $identity ] = true;
				return array(); // Preserve the original rows for manual recovery.
			}
			$out[ $key ] = $value;
		}
		unset( self::$read_faults[ $identity ] );
		return $out;
	}

	/**
	 * Replace one logical map using per-row hashes. Unchanged items are not rewritten.
	 * Caller owns higher-level queue locking where required by workflow semantics.
	 */
	public static function replace_map( $queue_type, $items ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$items = is_array( $items ) ? $items : array();
		$connection_id = self::connection_id();
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() || self::map_read_failed( $queue_type ) ) { return false; }
		$table = self::table_name();
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT dedupe_key,payload_hash FROM {$table} WHERE connection_id=%d AND queue_type=%s", $connection_id, $queue_type ), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $existing ) ) { return false; }
		$hashes = array();
		foreach ( $existing as $row ) {
			$hashes[ (string) $row['dedupe_key'] ] = (string) $row['payload_hash'];
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$desired = array();
		foreach ( $items as $key => $payload ) {
			$key = self::dedupe_key( $key );
			if ( '' === $key || ! is_array( $payload ) ) { continue; }
			$desired[ $key ] = true;
			$serialized = maybe_serialize( $payload );
			$hash = hash( 'sha256', (string) $serialized );
			if ( isset( $hashes[ $key ] ) && hash_equals( $hash, $hashes[ $key ] ) ) { continue; }
			/* UX61: unlike an intentional new enqueue, replacement of a map read
			 * earlier must never overwrite a newer concurrent queue revision. */
			if ( isset( $hashes[ $key ] ) ) {
				if ( ! self::update_row_if_hash( $connection_id, $queue_type, $key, $payload, $serialized, $hash, $hashes[ $key ], $now ) ) { return false; }
			} elseif ( ! self::upsert_row( $connection_id, $queue_type, $key, $payload, $serialized, $hash, $now, false ) ) {
				return false;
			}
		}
		$to_delete = array_diff( array_keys( $hashes ), array_keys( $desired ) );
		foreach ( $to_delete as $key ) {
			/* UX61: the queue row may have been re-enqueued since the snapshot.
			 * Never delete a revision with a different hash or an active claim. */
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s AND dedupe_key=%s AND payload_hash=%s AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP())",
				$connection_id, $queue_type, self::dedupe_hash( $connection_id, $queue_type, $key ), $key, $hashes[ $key ], ''
			) );
			if ( 1 !== $deleted ) { return false; } // A competing writer won; retry from a fresh map.
		}
		$stored = self::load_map( $queue_type );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return false; }
		return self::maps_equal( $stored, $items );
	}

	/** Atomic upsert of one queue item without reading/replacing the whole queue. */
	public static function put( $queue_type, $key, $payload ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || '' === $key || $connection_id <= 0 || ! is_array( $payload ) || ! self::is_available() ) { return false; }
		$serialized = maybe_serialize( $payload );
		$hash = hash( 'sha256', (string) $serialized );
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( ! self::upsert_row( $connection_id, $queue_type, $key, $payload, $serialized, $hash, $now ) ) { return false; }
		/* PERF01: verify this exact owner/key, not every unrelated item in the queue.
		 * A corrupt row, missing row, SQL error or newer competing payload fails closed. */
		$readback = self::read_one_checked( $queue_type, $key );
		return 'ok' === $readback['status']
			&& hash_equals( $hash, (string) $readback['row']['payload_hash'] )
			&& maybe_serialize( $readback['row']['payload'] ) === $serialized;
	}

	/** CAS update of a map snapshot; an active claim or newer payload wins. */
	private static function update_row_if_hash( $connection_id, $queue_type, $key, $payload, $serialized, $hash, $expected_hash, $now ) {
		global $wpdb;
		$table = self::table_name();
		$object_id = isset( $payload['productId'] ) ? absint( $payload['productId'] ) : ( isset( $payload['orderId'] ) ? absint( $payload['orderId'] ) : 0 );
		$available_at = self::epoch_to_mysql( isset( $payload['nextAttemptAt'] ) ? absint( $payload['nextAttemptAt'] ) : ( isset( $payload['dueAt'] ) ? absint( $payload['dueAt'] ) : 0 ) );
		$queued_at_us = self::payload_queued_at_us( $payload );
		$claim_token = isset( $payload['processingToken'] ) ? sanitize_text_field( (string) $payload['processingToken'] ) : '';
		$claim_expires = self::epoch_to_mysql( isset( $payload['processingUntil'] ) ? absint( $payload['processingUntil'] ) : 0 );
		$sql = $wpdb->prepare(
			"UPDATE {$table} SET object_id=%d,payload=%s,payload_hash=%s,queued_at_us=%d,available_at=NULLIF(%s,''),claim_token=%s,claim_expires_at=NULLIF(%s,''),attempt_count=%d,last_error=%s,updated_at=%s WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s AND dedupe_key=%s AND payload_hash=%s AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP())",
			$object_id, $serialized, $hash, $queued_at_us, $available_at, $claim_token, $claim_expires,
			isset( $payload['attempts'] ) ? absint( $payload['attempts'] ) : 0,
			isset( $payload['lastError'] ) ? sanitize_text_field( (string) $payload['lastError'] ) : '',
			$now, $connection_id, $queue_type, self::dedupe_hash( $connection_id, $queue_type, $key ), $key, $expected_hash, ''
		);
		return 1 === $wpdb->query( $sql );
	}

	private static function upsert_row( $connection_id, $queue_type, $key, $payload, $serialized, $hash, $now, $overwrite = true ) {
		global $wpdb;
		$table = self::table_name();
		$dedupe_hash = self::dedupe_hash( $connection_id, $queue_type, $key );
		$object_id = isset( $payload['productId'] ) ? absint( $payload['productId'] ) : ( isset( $payload['orderId'] ) ? absint( $payload['orderId'] ) : 0 );
		$available_at = self::epoch_to_mysql( isset( $payload['nextAttemptAt'] ) ? absint( $payload['nextAttemptAt'] ) : ( isset( $payload['dueAt'] ) ? absint( $payload['dueAt'] ) : 0 ) );
		$queued_at_us = self::payload_queued_at_us( $payload );
		$claim_token = isset( $payload['processingToken'] ) ? sanitize_text_field( (string) $payload['processingToken'] ) : '';
		$claim_expires = self::epoch_to_mysql( isset( $payload['processingUntil'] ) ? absint( $payload['processingUntil'] ) : 0 );
		$attempts = isset( $payload['attempts'] ) ? absint( $payload['attempts'] ) : 0;
		$last_error = isset( $payload['lastError'] ) ? sanitize_text_field( (string) $payload['lastError'] ) : '';
		$on_conflict = $overwrite
			? 'dedupe_key=VALUES(dedupe_key),object_id=VALUES(object_id),payload=VALUES(payload),payload_hash=VALUES(payload_hash),queued_at_us=VALUES(queued_at_us),available_at=VALUES(available_at),claim_token=VALUES(claim_token),claim_expires_at=VALUES(claim_expires_at),attempt_count=VALUES(attempt_count),last_error=VALUES(last_error),updated_at=VALUES(updated_at)'
			: 'id=id';
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (connection_id,queue_type,dedupe_key,dedupe_hash,object_id,payload,payload_hash,queued_at_us,available_at,claim_token,claim_expires_at,attempt_count,last_error,created_at,updated_at)
			 VALUES (%d,%s,%s,%s,%d,%s,%s,%d,NULLIF(%s,''),%s,NULLIF(%s,''),%d,%s,%s,%s)
			 ON DUPLICATE KEY UPDATE {$on_conflict}",
			$connection_id, $queue_type, $key, $dedupe_hash, $object_id, $serialized, $hash, $queued_at_us, $available_at, $claim_token, $claim_expires, $attempts, $last_error, $now, $now
		);
		return false !== $wpdb->query( $sql );
	}

	public static function delete_key( $queue_type, $key ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || '' === $key || $connection_id <= 0 || ! self::is_available() ) { return false; }
		return false !== $wpdb->delete(
			self::table_name(),
			array( 'connection_id' => $connection_id, 'queue_type' => $queue_type, 'dedupe_hash' => self::dedupe_hash( $connection_id, $queue_type, $key ) ),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * UX52: Single-row queue primitives. Never load/replace a whole warmup map
	 * for an ordinary enqueue, claim, commit, or health query. Ownership is
	 * always inferred from the active Connection Context, never from input.
	 */
	/**
	 * Strict indexed read: absence, query failure and corrupt bytes are different.
	 * The public single-row path must not silently convert corruption to an empty
	 * payload when used for write verification or capacity-sensitive operations.
	 * @return array{status:string,row:?array} status ok|missing|error
	 */
	public static function read_one_checked( $queue_type, $key ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$key        = self::dedupe_key( $key );
		$id         = self::connection_id();
		if ( '' === $queue_type || '' === $key || $id <= 0 || ! self::is_available() ) {
			return array( 'status' => 'error', 'row' => null );
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT dedupe_key,payload,payload_hash,claim_token,claim_expires_at FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s LIMIT 1',
			$id, $queue_type, self::dedupe_hash( $id, $queue_type, $key )
		), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			return array( 'status' => 'error', 'row' => null );
		}
		if ( null === $row ) {
			return array( 'status' => 'missing', 'row' => null );
		}
		if ( ! is_array( $row ) || ! isset( $row['dedupe_key'], $row['payload'], $row['payload_hash'] )
			|| (string) $row['dedupe_key'] !== $key ) {
			return array( 'status' => 'error', 'row' => null );
		}
		$raw      = (string) $row['payload'];
		$expected = (string) $row['payload_hash'];
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( $expected, hash( 'sha256', $raw ) ) ) {
			return array( 'status' => 'error', 'row' => null );
		}
		$payload = maybe_unserialize( $raw );
		if ( ! is_array( $payload ) ) {
			return array( 'status' => 'error', 'row' => null );
		}
		$row['payload'] = $payload;
		return array( 'status' => 'ok', 'row' => $row );
	}

	/** Compatibility reader for existing row consumers; strict callers use checked status. */
	public static function read_one( $queue_type, $key ) {
		$read = self::read_one_checked( $queue_type, $key );
		return 'ok' === $read['status'] ? $read['row'] : null;
	}

	/**
	 * PERF04: read only the earliest rows of one owner/queue in the SAME id
	 * order as load_map(), without decoding the rest of the queue. Validate the
	 * entire requested page before returning any item. An SQL or corrupt-payload
	 * failure is never returned as an apparently empty success page.
	 *
	 * @return array{status:string,rows:array} status ok|error; rows keyed by the full dedupe key.
	 */
	public static function first_rows_checked( $queue_type, $limit ) {
		return self::first_rows_page_checked( $queue_type, $limit, false );
	}

	/**
	 * Head-of-line safe variant of first_rows_checked(): only rows whose
	 * available_at is NULL or already reached, without a live claim, ordered by
	 * available_at, id. A deferred row (available_at in the future) therefore can
	 * never hide later due rows of the same owner/queue. Same strict validation.
	 *
	 * @return array{status:string,rows:array} status ok|error
	 */
	public static function first_due_rows_checked( $queue_type, $limit ) {
		return self::first_rows_page_checked( $queue_type, $limit, true );
	}

	private static function first_rows_page_checked( $queue_type, $limit, $due_only ) {
		global $wpdb;
		$queue_type    = self::queue_type( $queue_type );
		$connection_id = self::connection_id();
		$limit         = max( 1, min( 100, absint( $limit ) ) );
		$error         = array( 'status' => 'error', 'rows' => array() );
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() ) {
			return $error;
		}
		$sql = $due_only
			? $wpdb->prepare(
				'SELECT dedupe_key,dedupe_hash,payload,payload_hash FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP()) ORDER BY available_at ASC,id ASC LIMIT %d',
				$connection_id, $queue_type, '', $limit
			)
			: $wpdb->prepare(
				'SELECT dedupe_key,dedupe_hash,payload,payload_hash FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s ORDER BY id ASC LIMIT %d',
				$connection_id, $queue_type, $limit
			);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== trim( (string) $wpdb->last_error ) ) {
			return $error;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['dedupe_key'] ) ? (string) $row['dedupe_key'] : '';
			$raw = isset( $row['payload'] ) ? (string) $row['payload'] : '';
			$hash = isset( $row['payload_hash'] ) ? (string) $row['payload_hash'] : '';
			if ( '' === $key || isset( $out[ $key ] )
				|| ! isset( $row['dedupe_hash'] ) || ! hash_equals( self::dedupe_hash( $connection_id, $queue_type, $key ), (string) $row['dedupe_hash'] )
				|| ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $raw ) ) ) {
				return $error;
			}
			$payload = maybe_unserialize( $raw );
			if ( ! is_array( $payload ) ) {
				return $error;
			}
			$out[ $key ] = array( 'payload' => $payload, 'payload_hash' => $hash );
		}
		return array( 'status' => 'ok', 'rows' => $out );
	}

	/** Delete one exact snapshot; a re-enqueue or live claim must survive cleanup. */
	public static function delete_key_if_hash( $queue_type, $key, $expected_hash ) {
		global $wpdb;
		$queue_type    = self::queue_type( $queue_type );
		$key           = self::dedupe_key( $key );
		$connection_id = self::connection_id();
		$expected_hash = (string) $expected_hash;
		if ( '' === $queue_type || '' === $key || $connection_id <= 0 || ! self::is_available()
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ) {
			return false;
		}
		$deleted = $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s AND dedupe_key=%s AND payload_hash=%s AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP())',
			$connection_id, $queue_type, self::dedupe_hash( $connection_id, $queue_type, $key ), $key, $expected_hash, ''
		) );
		return 1 === $deleted;
	}

	/**
	 * PERF05: CAS payload/claim transition for one existing row. Used only when
	 * the caller holds its logical queue lease; expiry of that lease cannot let
	 * an older snapshot clobber a newer enqueue or a live competing claim.
	 */
	public static function replace_key_if_hash( $queue_type, $key, $expected_hash, $replacement ) {
		$queue_type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$id = self::connection_id();
		$expected_hash = (string) $expected_hash;
		if ( '' === $queue_type || '' === $key || $id <= 0 || ! self::is_available()
			|| ! is_array( $replacement ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ) { return false; }
		$serialized = maybe_serialize( $replacement );
		$new_hash = hash( 'sha256', (string) $serialized );
		if ( hash_equals( $expected_hash, $new_hash ) ) { return false; } // Must be a real generation transition.
		if ( ! self::update_row_if_hash( $id, $queue_type, $key, $replacement, $serialized, $new_hash, $expected_hash, gmdate( 'Y-m-d H:i:s' ) ) ) { return false; }
		$check = self::read_one_checked( $queue_type, $key );
		return 'ok' === $check['status'] && hash_equals( $new_hash, (string) $check['row']['payload_hash'] )
			&& maybe_serialize( $check['row']['payload'] ) === $serialized;
	}

	/** Write only this item. ON DUPLICATE KEY UPDATE invalidates stale claims. */
	public static function put_direct( $queue_type, $key, $payload ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$id = self::connection_id();
		if ( '' === $queue_type || '' === $key || $id <= 0 || ! is_array( $payload ) || ! self::is_available() ) { return false; }
		$serialized = maybe_serialize( $payload );
		$hash = hash( 'sha256', (string) $serialized );
		$now = gmdate( 'Y-m-d H:i:s' );
		return self::upsert_row( $id, $queue_type, $key, $payload, $serialized, $hash, $now );
	}

	/** Constant-size indexed due probe; no COUNT/SUM scan or payload decoding. */
	public static function has_due_one( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$id = self::connection_id();
		if ( '' === $queue_type || $id <= 0 || ! self::is_available() ) { return false; }
		$found = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_name() .
			' WHERE connection_id=%d AND queue_type=%s AND (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP()) ORDER BY available_at ASC,id ASC LIMIT 1',
			$id, $queue_type, ''
		) );
		return null !== $found && '' === trim( (string) $wpdb->last_error );
	}

	/** Add one legacy row only if its current revision is absent. Never update a
	 * newer row or invalidate an active claim during option-map import. */
	public static function insert_missing_direct( $queue_type, $key, $payload ) {
		global $wpdb;
		$type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$id = self::connection_id();
		if ( '' === $type || '' === $key || $id <= 0 || ! is_array( $payload ) || ! self::is_available() ) { return false; }
		$serialized = maybe_serialize( $payload );
		$now = gmdate( 'Y-m-d H:i:s' );
		$object_id = isset( $payload['productId'] ) ? absint( $payload['productId'] ) : 0;
		$queued_at_us = self::payload_queued_at_us( $payload );
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::table_name() . ' (connection_id,queue_type,dedupe_key,dedupe_hash,object_id,payload,payload_hash,queued_at_us,available_at,claim_token,claim_expires_at,attempt_count,last_error,created_at,updated_at)
			 VALUES (%d,%s,%s,%s,%d,%s,%s,%d,NULLIF(%s,\'\'),%s,NULL,%d,%s,%s,%s)
			 ON DUPLICATE KEY UPDATE id=id',
			$id, $type, $key, self::dedupe_hash( $id, $type, $key ), $object_id, $serialized, hash( 'sha256', (string) $serialized ), $queued_at_us,
			self::epoch_to_mysql( isset( $payload['nextAttemptAt'] ) ? $payload['nextAttemptAt'] : 0 ), '',
			absint( isset( $payload['attempts'] ) ? $payload['attempts'] : 0 ), '', $now, $now
		);
		if ( false === $wpdb->query( $sql ) ) { return false; }
		return null !== self::read_one( $type, $key );
	}

	/** Indexed product-existence probe across warmup lanes (migration only). */
	private static function warmup_product_exists( $product_id ) {
		global $wpdb;
		$id = self::connection_id();
		if ( $id <= 0 || $product_id <= 0 ) { return false; }
		$found = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type IN (%s,%s) AND object_id=%d LIMIT 1',
			$id, 'cache_warmup', 'cache_warmup_hot', $product_id
		) );
		return '' !== trim( (string) $wpdb->last_error ) ? null : null !== $found;
	}

	/** UX53: additive, bounded, checkpointed legacy warmup import. This is
	 * deliberately NOT replace_map(): deleting the destination map loses fresh
	 * enqueue rows. The same mutex as Cache_Warmer::enqueue_url/claim is held.
	 * A crash may replay up to 100 entries; the insert is idempotent and never
	 * overwrites a newer revision. Legacy is removed only after the final step. */
	public static function migrate_legacy_warmup_option( $option_name, $allow_partial = false ) {
		$option_name = sanitize_key( (string) $option_name );
		if ( '' === $option_name || ! self::is_available() ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return true; }
		$token = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'cache_warmup_queue', 45 ) : '__mobo_no_lock__';
		if ( false === $token ) { return false; }
		$cursor_key = 'mobo_core_warmup_legacy_import_cursor';
		try {
			$sentinel = new stdClass();
			$legacy = get_option( $option_name, $sentinel );
			if ( $sentinel === $legacy ) { delete_option( $cursor_key ); return true; }
			if ( ! is_array( $legacy ) || ! isset( $legacy['items'] ) || ! is_array( $legacy['items'] ) ) { return false; }
			// This importer inserts rows outside ordinary enqueue. Invalidate the
			// admission hint before every bounded slice (including failed slices),
			// otherwise a 30K import could consume HOT reserve unnoticed for 60s.
			$owner_id = self::connection_id();
			if ( $owner_id > 0 ) { delete_option( 'mobo_core_warmup_admission_' . $owner_id ); }
			$items = $legacy['items'];
			$fingerprint = hash( 'sha256', maybe_serialize( $legacy ) );
			$cursor = get_option( $cursor_key, array() );
			$offset = is_array( $cursor ) && isset( $cursor['fingerprint'] ) && hash_equals( $fingerprint, (string) $cursor['fingerprint'] ) ? absint( isset( $cursor['offset'] ) ? $cursor['offset'] : 0 ) : 0;
			$batch = array_slice( $items, $offset, 100, true );
			foreach ( $batch as $old_key => $payload ) {
				if ( ! is_array( $payload ) ) { return false; } // Never silently drop unknown legacy payloads.
				$product_id = isset( $payload['productId'] ) ? absint( $payload['productId'] ) : 0;
				$key = $product_id > 0 ? 'product:' . $product_id : self::dedupe_key( $old_key );
				if ( '' === $key ) { return false; }
				if ( $product_id > 0 ) {
					$present = self::warmup_product_exists( $product_id );
					if ( null === $present ) { return false; }
					if ( $present ) { continue; }
				}
				unset( $payload['processingToken'], $payload['processingUntil'] );
				if ( empty( $payload['revision'] ) ) { $payload['revision'] = wp_generate_uuid4(); }
				if ( ! self::insert_missing_direct( 'cache_warmup', $key, $payload ) ) { return false; }
			}
			$next = $offset + count( $batch );
			// An option write failure cannot retire source data; retry only this bounded batch.
			if ( $next < count( $items ) ) {
				$checkpoint = array( 'fingerprint' => $fingerprint, 'offset' => $next );
				if ( ! update_option( $cursor_key, $checkpoint, false ) && get_option( $cursor_key, array() ) !== $checkpoint ) { return false; }
				return (bool) $allow_partial; // Strict cutover blocks; runtime can use its additive live rows.
			}
			// The source may only be retired under the same mutex after all entries persisted.
			if ( ! delete_option( $option_name ) ) { return false; }
			wp_cache_delete( $option_name, 'options' );
			if ( $sentinel !== get_option( $option_name, $sentinel ) ) { return false; }
			delete_option( $cursor_key );
			// Admission count must be recomputed: legacy imports bypass normal enqueue.
			$id = self::connection_id();
			if ( $id > 0 ) { delete_option( 'mobo_core_warmup_admission_' . $id ); }
			return true;
		} finally {
			if ( '__mobo_no_lock__' !== $token ) { Mobo_Core_Lock::release( 'cache_warmup_queue', $token ); }
		}
	}

	/**
	 * PERF11: bounded, crash-safe backfill of the indexed queue ordering column.
	 * The payload remains authoritative during migration. A concurrent enqueue
	 * changes payload_hash/queued_at_us and therefore cannot be overwritten by
	 * this backfill. Callers may keep using the historical payload map until the
	 * returned status is complete.
	 *
	 * @return array{status:string,updated:int} status complete|incomplete|error
	 */
	public static function backfill_queued_at_us( $queue_type, $limit = 250 ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$id = self::connection_id();
		$limit = max( 1, min( 500, absint( $limit ) ) );
		if ( '' === $queue_type || $id <= 0 || ! self::is_available() ) { return array( 'status' => 'error', 'updated' => 0 ); }
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id,dedupe_key,dedupe_hash,payload,payload_hash FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND queued_at_us=0 ORDER BY id ASC LIMIT %d',
			$id, $queue_type, $limit
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== trim( (string) $wpdb->last_error ) ) { return array( 'status' => 'error', 'updated' => 0 ); }
		$updated = 0;
		foreach ( $rows as $row ) {
			$raw = isset( $row['payload'] ) ? (string) $row['payload'] : '';
			$expected_hash = isset( $row['payload_hash'] ) ? (string) $row['payload_hash'] : '';
			$key = isset( $row['dedupe_key'] ) ? self::dedupe_key( $row['dedupe_key'] ) : '';
			if ( '' === $key || ! isset( $row['dedupe_hash'] ) || ! hash_equals( self::dedupe_hash( $id, $queue_type, $key ), (string) $row['dedupe_hash'] )
				|| ! preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) || ! hash_equals( $expected_hash, hash( 'sha256', $raw ) ) ) {
				return array( 'status' => 'error', 'updated' => $updated );
			}
			$payload = maybe_unserialize( $raw );
			$queued_at_us = is_array( $payload ) ? self::payload_queued_at_us( $payload ) : 0;
			if ( $queued_at_us <= 0 ) { return array( 'status' => 'error', 'updated' => $updated ); }
			$changed = $wpdb->query( $wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET queued_at_us=%d WHERE id=%d AND connection_id=%d AND queue_type=%s AND payload_hash=%s AND queued_at_us=0',
				$queued_at_us, absint( $row['id'] ), $id, $queue_type, $expected_hash
			) );
			if ( false === $changed || '' !== trim( (string) $wpdb->last_error ) ) { return array( 'status' => 'error', 'updated' => $updated ); }
			if ( 1 === $changed ) { $updated++; continue; }
			$current = $wpdb->get_row( $wpdb->prepare(
				'SELECT payload,payload_hash,queued_at_us FROM ' . self::table_name() . ' WHERE id=%d AND connection_id=%d AND queue_type=%s LIMIT 1',
				absint( $row['id'] ), $id, $queue_type
			), ARRAY_A );
			if ( ! is_array( $current ) || '' !== trim( (string) $wpdb->last_error ) ) { return array( 'status' => 'error', 'updated' => $updated ); }
			$current_raw = isset( $current['payload'] ) ? (string) $current['payload'] : '';
			$current_hash = isset( $current['payload_hash'] ) ? (string) $current['payload_hash'] : '';
			$current_payload = maybe_unserialize( $current_raw );
			if ( ! is_array( $current_payload ) || ! preg_match( '/^[a-f0-9]{64}$/D', $current_hash )
				|| ! hash_equals( $current_hash, hash( 'sha256', $current_raw ) )
				|| self::payload_queued_at_us( $current_payload ) <= 0
				|| self::payload_queued_at_us( $current_payload ) !== absint( $current['queued_at_us'] ) ) {
				return array( 'status' => 'error', 'updated' => $updated );
			}
		}
		$remaining = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND queued_at_us=0 LIMIT 1',
			$id, $queue_type
		) );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return array( 'status' => 'error', 'updated' => $updated ); }
		return array( 'status' => null === $remaining ? 'complete' : 'incomplete', 'updated' => $updated );
	}

	/**
	 * PERF12: materialize only the bounded oldest due rows after PERF11 backfill.
	 * The indexed queued_at_us value must exactly mirror payload queuedAt; drift or
	 * corrupt bytes fail closed rather than silently changing parent ordering.
	 *
	 * @return array{status:string,rows:array} status ok|error
	 */
	public static function oldest_due_rows_checked( $queue_type, $limit ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$id = self::connection_id();
		$limit = max( 1, min( 50, absint( $limit ) ) );
		$error = array( 'status' => 'error', 'rows' => array() );
		if ( '' === $queue_type || $id <= 0 || ! self::is_available() ) { return $error; }
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id,dedupe_key,dedupe_hash,payload,payload_hash,queued_at_us FROM ' . self::table_name() .
			' WHERE connection_id=%d AND queue_type=%s AND queued_at_us>0 AND (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP()) ORDER BY queued_at_us ASC,id ASC LIMIT %d',
			$id, $queue_type, '', $limit
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== trim( (string) $wpdb->last_error ) ) { return $error; }
		$out = array();
		foreach ( $rows as $row ) {
			$key = isset( $row['dedupe_key'] ) ? (string) $row['dedupe_key'] : '';
			$raw = isset( $row['payload'] ) ? (string) $row['payload'] : '';
			$hash = isset( $row['payload_hash'] ) ? (string) $row['payload_hash'] : '';
			$payload = maybe_unserialize( $raw );
			if ( '' === $key || isset( $out[ $key ] ) || ! is_array( $payload )
				|| ! isset( $row['dedupe_hash'] ) || ! hash_equals( self::dedupe_hash( $id, $queue_type, $key ), (string) $row['dedupe_hash'] )
				|| ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! hash_equals( $hash, hash( 'sha256', $raw ) )
				|| self::payload_queued_at_us( $payload ) <= 0 || self::payload_queued_at_us( $payload ) !== absint( $row['queued_at_us'] ) ) {
				return $error;
			}
			$out[ $key ] = array( 'payload' => $payload, 'payload_hash' => $hash, 'queued_at_us' => absint( $row['queued_at_us'] ) );
		}
		return array( 'status' => 'ok', 'rows' => $out );
	}

	/** At most one candidate is materialized, using queue_due index. */
	public static function next_due_one( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$id = self::connection_id();
		if ( '' === $queue_type || $id <= 0 || ! self::is_available() ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT dedupe_key,payload,payload_hash FROM ' . self::table_name() .
			' WHERE connection_id=%d AND queue_type=%s AND (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP()) ORDER BY available_at ASC,id ASC LIMIT 1',
			$id, $queue_type, ''
		), ARRAY_A );
		if ( ! is_array( $row ) ) { return null; }
		$payload = maybe_unserialize( $row['payload'] );
		if ( ! is_array( $payload ) || ! hash_equals( (string) $row['payload_hash'], hash( 'sha256', (string) $row['payload'] ) ) ) { return null; }
		$row['payload'] = $payload;
		return $row;
	}

	/** CAS claim: a concurrent re-enqueue changes payload_hash or clears token. */
	public static function claim_one( $queue_type, $key, $expected_hash, $token, $ttl ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$id = self::connection_id();
		$token = sanitize_text_field( (string) $token );
		if ( '' === $queue_type || '' === $key || '' === $token || $id <= 0 || ! self::is_available() ) { return false; }
		$sql = $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET claim_token=%s,claim_expires_at=%s,updated_at=%s WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s AND dedupe_key=%s AND payload_hash=%s AND (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP())',
			$token, gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) ), gmdate( 'Y-m-d H:i:s' ), $id, $queue_type,
			self::dedupe_hash( $id, $queue_type, $key ), $key, (string) $expected_hash, '' );
		return 1 === $wpdb->query( $sql );
	}

	/** CAS commit prevents a slow HTTP response from consuming a newer revision. */
	public static function finish_claim( $queue_type, $key, $expected_hash, $token, $replacement = null ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$key = self::dedupe_key( $key );
		$id = self::connection_id();
		if ( '' === $queue_type || '' === $key || $id <= 0 || ! self::is_available() || ( null !== $replacement && ! is_array( $replacement ) ) ) { return false; }
		$where = array( $id, $queue_type, self::dedupe_hash( $id, $queue_type, $key ), $key, (string) $expected_hash, sanitize_text_field( (string) $token ) );
		if ( null === $replacement ) {
			$sql = $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s AND dedupe_key=%s AND payload_hash=%s AND claim_token=%s', $where );
		} else {
			$serialized = maybe_serialize( $replacement );
			$sql = $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET payload=%s,payload_hash=%s,queued_at_us=%d,available_at=NULLIF(%s,\'\'),claim_token=%s,claim_expires_at=NULL,attempt_count=%d,last_error=%s,updated_at=%s WHERE connection_id=%d AND queue_type=%s AND dedupe_hash=%s AND dedupe_key=%s AND payload_hash=%s AND claim_token=%s',
				array_merge( array( $serialized, hash( 'sha256', (string) $serialized ), self::payload_queued_at_us( $replacement ), self::epoch_to_mysql( isset( $replacement['nextAttemptAt'] ) ? $replacement['nextAttemptAt'] : 0 ), '', absint( isset( $replacement['attempts'] ) ? $replacement['attempts'] : 0 ), sanitize_text_field( (string) ( isset( $replacement['lastError'] ) ? $replacement['lastError'] : '' ) ), gmdate( 'Y-m-d H:i:s' ) ), $where ) );
		}
		return 1 === $wpdb->query( $sql );
	}

	/** Retire URL-keyed legacy duplicates only for this Connection/product. */
	public static function delete_other_object_keys( $queue_type, $object_id, $keep_key ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$id = self::connection_id();
		$object_id = absint( $object_id );
		if ( '' === $queue_type || $id <= 0 || $object_id <= 0 || ! self::is_available() ) { return false; }
		return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s AND object_id=%d AND dedupe_hash<>%s',
			$id, $queue_type, $object_id, self::dedupe_hash( $id, $queue_type, self::dedupe_key( $keep_key ) ) ) );
	}

	/** One indexed aggregate query, not a PHP materialization of 30K rows. */
	public static function queue_stats( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$id = self::connection_id();
		$empty = array( 'pending' => 0, 'due' => 0, 'oldestAt' => '', 'nextAt' => '', 'claimed' => 0, 'retrying' => 0 );
		if ( '' === $queue_type || $id <= 0 || ! self::is_available() ) { return $empty; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS pending, SUM(CASE WHEN (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP()) THEN 1 ELSE 0 END) AS due, MIN(created_at) AS oldestAt, MIN(CASE WHEN available_at>UTC_TIMESTAMP() THEN available_at ELSE NULL END) AS nextAt, SUM(CASE WHEN claim_token<>%s AND claim_expires_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) AS claimed, SUM(CASE WHEN attempt_count>0 THEN 1 ELSE 0 END) AS retrying FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s', '', '', $id, $queue_type ), ARRAY_A );
		if ( ! is_array( $row ) ) { return $empty; }
		return array( 'pending' => absint( $row['pending'] ), 'due' => absint( $row['due'] ), 'oldestAt' => (string) $row['oldestAt'], 'nextAt' => (string) $row['nextAt'], 'claimed' => absint( $row['claimed'] ), 'retrying' => absint( $row['retrying'] ) );
	}

	public static function clear( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() ) { return false; }
		return false !== $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'queue_type' => $queue_type ), array( '%d', '%s' ) );
	}

	/**
	 * PERF07: strict constant-memory pending/due summary for one queue.
	 * Uses indexed row columns already kept in sync with nextAttemptAt/claim expiry;
	 * SQL failure is explicit and must not be interpreted as an empty queue.
	 *
	 * @return array|false Array with pending/due, or false on an unreadable queue.
	 */
	public static function pending_due_counts_strict( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() ) { return false; }
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT COUNT(*) AS pending, SUM(CASE WHEN (available_at IS NULL OR available_at<=UTC_TIMESTAMP()) AND (claim_token=%s OR claim_expires_at IS NULL OR claim_expires_at<=UTC_TIMESTAMP()) THEN 1 ELSE 0 END) AS due FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s',
			'', $connection_id, $queue_type
		), ARRAY_A );
		if ( ! is_array( $row ) || '' !== trim( (string) $wpdb->last_error ) || ! isset( $row['pending'] ) ) { return false; }
		return array( 'pending' => absint( $row['pending'] ), 'due' => absint( isset( $row['due'] ) ? $row['due'] : 0 ) );
	}

	/** Strict count for a hard admission gate: SQL errors must never mean zero. */
	public static function count_strict( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() ) { return -1; }
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s', $connection_id, $queue_type ) );
		if ( null === $count || '' !== trim( (string) $wpdb->last_error ) ) { return -1; }
		return absint( $count );
	}

	public static function count( $queue_type ) {
		global $wpdb;
		$queue_type = self::queue_type( $queue_type );
		$connection_id = self::connection_id();
		if ( '' === $queue_type || $connection_id <= 0 || ! self::is_available() ) { return 0; }
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE connection_id=%d AND queue_type=%s', $connection_id, $queue_type ) ) );
	}

	public static function migrate_option_map( $option_name, $queue_type, $items_key = '' ) {
		$option_name = sanitize_key( (string) $option_name );
		$queue_type = self::queue_type( $queue_type );
		if ( 'cache_warmup' === $queue_type && 'items' === $items_key ) { return self::migrate_legacy_warmup_option( $option_name ); }
		if ( '' === $option_name || '' === $queue_type ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) {
			/* Installation-global legacy options belong exclusively to Default. */
			return true;
		}
		/* A fresh installation can have no Connection yet. Nothing exists to
		 * migrate, so do not block ordinary schema readiness on missing ownership. */
		$probe_sentinel = new stdClass();
		if ( $probe_sentinel === get_option( $option_name, $probe_sentinel ) ) { return true; }
		if ( self::connection_id() <= 0 || ! self::is_available() || ! class_exists( 'Mobo_Core_Lock' ) ) { return false; }
		/* UX61: independent importers serialize with each other; live producers may
		 * still write new table revisions, which INSERT-IF-MISSING cannot overwrite. */
		$import_lock = 'work_queue_legacy_import_' . $queue_type;
		$token = Mobo_Core_Lock::acquire( $import_lock, 120 );
		if ( false === $token ) { return false; }
		$cursor_key = 'mobo_core_work_queue_import_' . $queue_type;
		try {
			$sentinel = new stdClass();
			$legacy = get_option( $option_name, $sentinel );
			if ( $sentinel === $legacy ) { delete_option( $cursor_key ); return true; }
			if ( ! is_array( $legacy ) ) { return false; }
			if ( '' !== $items_key && ( ! isset( $legacy[ $items_key ] ) || ! is_array( $legacy[ $items_key ] ) ) ) { return false; }
			$map = '' !== $items_key ? $legacy[ $items_key ] : $legacy;
			$fingerprint = hash( 'sha256', maybe_serialize( $legacy ) );
			$cursor = get_option( $cursor_key, array() );
			$offset = is_array( $cursor ) && isset( $cursor['fingerprint'] ) && hash_equals( $fingerprint, (string) $cursor['fingerprint'] ) ? absint( isset( $cursor['offset'] ) ? $cursor['offset'] : 0 ) : 0;
			$batch = array_slice( $map, $offset, 100, true );
			$slice_index = 0;
			foreach ( $batch as $key => $payload ) {
				if ( 0 === ( $slice_index++ % 20 ) && ! Mobo_Core_Lock::renew( $import_lock, $token, 120 ) ) { return false; }
				/* Reject invalid/truncated identities rather than merge distinct
				 * legacy jobs or silently drop an unreadable order/parent marker. */
				$normalized = self::dedupe_key( $key );
				if ( ! is_array( $payload ) || '' === $normalized || (string) $key !== $normalized ) { return false; }
				if ( ! self::insert_missing_direct( $queue_type, $normalized, $payload ) ) { return false; }
			}
			$next = $offset + count( $batch );
			/* The source may change while a bounded batch imports. A changed
			 * fingerprint restarts import without discarding destination rows. */
			if ( ! Mobo_Core_Lock::renew( $import_lock, $token, 120 ) ) { return false; }
			/* A fresh DB read avoids relying on WP's request-local options cache
			 * when another process updates the legacy source during this slice. */
			global $wpdb;
			$source_bytes = maybe_serialize( $legacy );
			$current_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $option_name ) );
			if ( null === $current_raw || '' !== trim( (string) $wpdb->last_error ) || ! hash_equals( (string) $source_bytes, (string) $current_raw ) ) {
				wp_cache_delete( $option_name, 'options' );
				return false;
			}
			if ( $next < count( $map ) ) {
				$checkpoint = array( 'fingerprint' => $fingerprint, 'offset' => $next );
				if ( ! update_option( $cursor_key, $checkpoint, false ) && get_option( $cursor_key, array() ) !== $checkpoint ) { return false; }
				return false; // Incomplete cutover is never reported as completed.
			}
			/* Final retirement is a single SQL compare-and-delete, not a stale
			 * get_option() followed by an unconditional delete_option(). */
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s", $option_name, $source_bytes ) );
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 !== $deleted ) { return false; }
			$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $option_name ) );
			if ( null !== $remaining || '' !== trim( (string) $wpdb->last_error ) ) { return false; }
			delete_option( $cursor_key );
			return true;
		} finally {
			Mobo_Core_Lock::release( $import_lock, $token );
		}
	}

	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_effective_connection_id() : 0;
	}
	private static function dedupe_hash( $connection_id, $queue_type, $key ) { return hash( 'sha256', absint( $connection_id ) . '|' . $queue_type . '|' . $key ); }
	private static function maps_equal( $left, $right ) {
		$left = is_array( $left ) ? $left : array(); $right = is_array( $right ) ? $right : array();
		ksort( $left ); ksort( $right );
		return maybe_serialize( $left ) === maybe_serialize( $right );
	}
	private static function queue_type( $value ) { return substr( sanitize_key( (string) $value ), 0, 48 ); }
	private static function dedupe_key( $value ) { return substr( sanitize_text_field( (string) $value ), 0, 191 ); }
	/** Mirror payload queuedAt into an indexed, sortable microsecond column. */
	private static function payload_queued_at_us( $payload ) {
		if ( ! is_array( $payload ) ) { return 0; }
		if ( isset( $payload['queuedAtUs'] ) && absint( $payload['queuedAtUs'] ) > 0 ) { return absint( $payload['queuedAtUs'] ); }
		$seconds = isset( $payload['queuedAt'] ) ? absint( $payload['queuedAt'] ) : 0;
		return $seconds > 0 ? (int) ( $seconds * 1000000 ) : 0;
	}

	private static function epoch_to_mysql( $epoch ) { $epoch = absint( $epoch ); return $epoch > 0 ? gmdate( 'Y-m-d H:i:s', $epoch ) : null; }
}
