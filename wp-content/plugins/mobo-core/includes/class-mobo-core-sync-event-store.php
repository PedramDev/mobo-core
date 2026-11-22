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

	/*
	 * S2 retry policy.
	 *
	 * - Every claim is an attempt: claiming increments try_count. A transition
	 *   that is not a failure (deferral, partial progress, a claim released at a
	 *   time budget) gives the attempt back.
	 * - A claim whose lease expires (fatal error, OOM, kill, timeout) is
	 *   reclaimed alone, never together with other rows, and at most
	 *   CRASH_RECLAIM_LIMIT times; then the row is failed instead of crashing
	 *   the runner again for the two-day event lifetime.
	 * - Failures back off exponentially (BACKOFF_BASE_SECONDS doubling, capped
	 *   at BACKOFF_CAP_SECONDS).
	 * - A transient failure (database write error, full disk/table, lost
	 *   connection, network/Backend outage) is not final after the ordinary
	 *   max-try budget; it keeps retrying up to TRANSIENT_MAX_TRY attempts
	 *   (about five hours), and failed transient rows are re-armed automatically
	 *   every AUTO_RETRY_INTERVAL for AUTO_RETRY_MAX_AGE after creation unless a
	 *   newer event superseded them.
	 */
	const BACKOFF_BASE_SECONDS     = 30;
	const BACKOFF_CAP_SECONDS      = 3600;
	const TRANSIENT_MAX_TRY        = 12;
	const CRASH_RECLAIM_LIMIT      = 3;
	const NULL_LOCK_STALE_SECONDS  = 900;
	const AUTO_RETRY_INTERVAL      = 1800;
	const AUTO_RETRY_MAX_AGE       = 604800; // 7 days.
	const AUTO_RETRY_OPTION        = 'mobo_core_sync_event_auto_retry_at';
	const RECLAIM_MARKER           = 'Previous processing attempt ended abnormally (fatal error, timeout or kill); reclaimed ';
	const CRASH_FAILED_MESSAGE     = 'Event processing ended abnormally repeatedly (fatal error, timeout or kill); stopped to protect the site. Retry after fixing the cause.';

	/**
	 * Pure: does a failure message describe a temporary condition of the site or
	 * the network rather than a defect of the event?
	 *
	 * @param string $message Failure message.
	 * @return bool
	 */
	public static function is_transient_failure( $message ) {
		$message = strtolower( (string) $message );
		if ( '' === $message ) {
			return false;
		}
		$needles = array(
			// Database / storage.
			'database error', 'db error', 'db write', 'database write', 'could not write', 'could not be written',
			'write failed', 'failed to write', 'insert failed', 'update failed', 'could not insert', 'could not update',
			'could not be saved', 'could not save', 'deadlock', 'lock wait timeout', 'gone away', 'lost connection',
			'too many connections', 'is full', 'disk full', 'no space left', 'errno: 28', 'errno 28', 'quota',
			'read-only', 'read only file', 'storage is not ready', 'storage not ready',
			// The plugin's own durable-write failure wording (product/variant sync).
			'could not be persisted', 'could not be committed', 'could not durably', 'could not persist',
			'could not flush', 'database write failure',
			// Network / Backend availability.
			'timed out', 'timeout', 'curl error', 'could not resolve', 'connection refused', 'connection reset',
			'http 500', 'http 502', 'http 503', 'http 504', 'http_request_failed', 'service unavailable', 'bad gateway',
			'payload pull',
		);
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pure: exponential backoff with a small deterministic jitter.
	 *
	 * @param int $try_count Attempts so far (>= 1).
	 * @param int $id Row ID (jitter seed).
	 * @return int Seconds.
	 */
	public static function retry_delay( $try_count, $id = 0 ) {
		$try_count = max( 1, absint( $try_count ) );
		$delay     = self::BACKOFF_BASE_SECONDS;
		for ( $i = 1; $i < $try_count && $delay < self::BACKOFF_CAP_SECONDS; $i++ ) {
			$delay *= 2;
		}
		$delay  = min( self::BACKOFF_CAP_SECONDS, $delay );
		$jitter = absint( absint( $id ) * 7 + $try_count * 13 ) % max( 1, (int) floor( $delay / 10 ) );
		return $delay + $jitter;
	}

	/**
	 * Pure: final failure or retry, and when.
	 *
	 * An explicit final request before the try budget is spent (non-retryable
	 * ordering conflict, expired or undecodable event) is honoured. A final
	 * request caused only by the exhausted budget is overridden for transient
	 * failures until TRANSIENT_MAX_TRY.
	 *
	 * @param int    $try_count Attempts so far.
	 * @param int    $max_try Ordinary budget.
	 * @param bool   $final_requested Caller's decision.
	 * @param string $message Failure message.
	 * @param int    $id Row ID.
	 * @return array{final:bool,delay:int,transient:bool}
	 */
	public static function failure_policy( $try_count, $max_try, $final_requested, $message, $id = 0 ) {
		$try_count = absint( $try_count );
		$max_try   = max( 1, absint( $max_try ) );
		$transient = self::is_transient_failure( $message );
		$final     = (bool) $final_requested;
		if ( $final && $transient && $try_count >= $max_try ) {
			$final = $try_count >= max( $max_try, self::TRANSIENT_MAX_TRY );
		}
		return array(
			'final'     => $final,
			'delay'     => $final ? 0 : self::retry_delay( max( 1, $try_count ), $id ),
			'transient' => $transient,
		);
	}

	/**
	 * Pure: how many times a row was already reclaimed after an abnormal end.
	 *
	 * @param string $last_error Row last_error.
	 * @return int
	 */
	public static function reclaim_count( $last_error ) {
		$last_error = (string) $last_error;
		if ( 0 !== strpos( $last_error, self::RECLAIM_MARKER ) ) {
			return 0;
		}
		return absint( substr( $last_error, strlen( self::RECLAIM_MARKER ) ) );
	}

	/** @param int $count Reclaims. @return string */
	public static function reclaim_message( $count ) {
		return self::RECLAIM_MARKER . absint( $count ) . ' time(s).';
	}

	/** Ordinary attempt budget. @return int */
	private static function max_try() {
		return class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 ) : 5;
	}

	/** Resolve active/default Connection for every queue operation. */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @var bool|null Request-local table existence cache. */
	private static $table_exists_cache = null;

	/** @var array Request-local queue summary cache keyed by Connection. */
	private static $summary_cache = array();

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
		$charset_collate = Mobo_Core_Database_Charset::schema_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
			UNIQUE KEY event_uuid (connection_id,event_uuid),
			KEY connection_status (connection_id,status,next_retry_at,id),
			KEY remote_event_id (connection_id,remote_event_id(150)),
			KEY status_retry_id (connection_id,status,next_retry_at,id),
			KEY locked_until (connection_id,locked_until),
			KEY claim_token (connection_id,claim_token),
			KEY status_locked_id (connection_id,status,locked_until,id),
			KEY status_updated_id (connection_id,status,updated_at,id),
			KEY event_entity_id (connection_id,event_type,entity_guid(100),id),
			KEY entity_lookup (connection_id,entity_type,entity_guid(120)),
			KEY created_at (connection_id,created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
		self::$summary_cache      = array();
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
		self::$summary_cache = array();
	}


	/**
	 * Acquire a connection-scoped lock for one remote event identity.
	 *
	 * @param string $remote_event_id Remote event identity.
	 * @return string Lock name or empty string.
	 */
	private function acquire_remote_event_dedupe_lock( $remote_event_id, $connection_id = 0 ) {
		global $wpdb;

		$remote_event_id = sanitize_text_field( (string) $remote_event_id );
		$connection_id  = absint( $connection_id );
		if ( '' === $remote_event_id ) {
			return '';
		}

		$db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		$lock_name = 'mobo_evt_' . substr( hash( 'sha256', $db_name . '|' . (string) $wpdb->prefix . '|' . $connection_id . '|' . $remote_event_id ), 0, 40 );
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
	public function enqueue( $raw_payload, $connection_id = 0 ) {
		global $wpdb;

		if ( ! is_array( $raw_payload ) ) {
			return new WP_Error( 'mobo_core_invalid_event_payload', 'Invalid event payload.' );
		}

		if ( ! self::table_exists() ) {
			return new WP_Error( 'mobo_core_event_table_missing', 'Sync event table is missing.' );
		}

		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 && class_exists( 'Mobo_Core_Connection_Context' ) ) {
			$connection_id = Mobo_Core_Connection_Context::get_current_connection_id();
		}
		if ( $connection_id <= 0 && class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$default = Mobo_Core_Connection_Repository::get_default();
			$connection_id = is_array( $default ) ? absint( $default['id'] ?? 0 ) : 0;
		}
		if ( $connection_id <= 0 ) {
			return new WP_Error( 'mobo_core_event_connection_missing', 'Could not resolve webhook connection for event persistence.' );
		}
		/* This public store can be called independently of the REST queue. Enforce
		 * the durable owner here too, before a pending row can be persisted. */
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			return new WP_Error( 'mobo_core_event_connection_missing', 'Connection repository is unavailable.' );
		}
		$owner = Mobo_Core_Connection_Repository::get_by_id( $connection_id );
		if ( ! is_array( $owner ) || 1 !== absint( $owner['is_enabled'] ?? 0 ) ) {
			return new WP_Error( 'mobo_core_event_connection_disabled', 'Webhook Connection is missing or disabled.' );
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
			$dedupe_lock_name = $this->acquire_remote_event_dedupe_lock( $remote_event_id, $connection_id );
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE connection_id = %d AND remote_event_id = %s AND status IN ('pending', 'processing', 'done') ORDER BY id DESC LIMIT 1",
					$connection_id,
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
				'connection_id'    => $connection_id,
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
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( '' !== $dedupe_lock_name ) {
			$this->release_remote_event_dedupe_lock( $dedupe_lock_name );
		}

		if ( false === $inserted ) {
			return new WP_Error( 'mobo_core_event_insert_failed', 'Could not store sync event.' );
		}

		$new_id = absint( $wpdb->insert_id );
		if ( $new_id <= 0 ) {
			return new WP_Error( 'mobo_core_event_insert_unverified', 'Sync event insert did not return a durable row id.' );
		}

		/* event_version is part of the ordering contract, not optional diagnostics.
		 * Read the canonical row back before accepting the enqueue so a successful
		 * statement can never advertise an event whose durable identity/version differs
		 * from the payload the worker will later process. */
		$persisted = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT connection_id, event_uuid, remote_event_id, event_type, entity_type, entity_guid, sync_id, event_version FROM {$table} WHERE id = %d LIMIT 1",
				$new_id
			),
			ARRAY_A
		);
		$expected_version = sanitize_text_field( (string) $normalized['version'] );
		if ( ! is_array( $persisted )
			|| $connection_id !== absint( $persisted['connection_id'] ?? 0 )
			|| $event_uuid !== (string) ( isset( $persisted['event_uuid'] ) ? $persisted['event_uuid'] : '' )
			|| $remote_event_id !== (string) ( isset( $persisted['remote_event_id'] ) ? $persisted['remote_event_id'] : '' )
			|| $event_type !== (string) ( isset( $persisted['event_type'] ) ? $persisted['event_type'] : '' )
			|| $entity_type !== (string) ( isset( $persisted['entity_type'] ) ? $persisted['entity_type'] : '' )
			|| $entity_guid !== (string) ( isset( $persisted['entity_guid'] ) ? $persisted['entity_guid'] : '' )
			|| sanitize_text_field( (string) $normalized['syncId'] ) !== (string) ( isset( $persisted['sync_id'] ) ? $persisted['sync_id'] : '' )
			|| $expected_version !== (string) ( isset( $persisted['event_version'] ) ? $persisted['event_version'] : '' )
		) {
			return new WP_Error( 'mobo_core_event_insert_readback_failed', 'Sync event could not be verified after persistence.' );
		}

		self::invalidate_summary_cache();

		/*
		 * Last desired state wins for safe single-entity events that have not started
		 * processing yet. Never collapse multi-product payloads or authoritative
		 * multi-variation snapshots: their pages/variants carry independent state.
		 */
		if ( $new_id > 0 && $this->is_coalescible_entity_event( $event_type, $entity_type, $entity_guid, $payload ) ) {
			$this->coalesce_older_pending_entity_events( $new_id, $event_uuid, sanitize_text_field( (string) $normalized['version'] ), $payload_json, $event_type, $entity_type, $entity_guid, $connection_id );
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

		/* product-delta-v1 events are partial desired state: they must never
		 * supersede an older (possibly full) pending event of the same product. */
		if ( class_exists( 'Mobo_Core_Product_Delta' ) && Mobo_Core_Product_Delta::is_delta( $payload ) ) {
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
	 * @param string $new_payload_json Canonical persisted payload JSON for replay/conflict proof.
	 * @param string $event_type Event type.
	 * @param string $entity_type Entity type.
	 * @param string $entity_guid Entity GUID.
	 * @return int Number coalesced.
	 */
	private function coalesce_older_pending_entity_events( $new_id, $new_uuid, $new_version, $new_payload_json, $event_type, $entity_type, $entity_guid, $connection_id = 0 ) {
		global $wpdb;

		$new_id      = absint( $new_id );
		$new_uuid    = sanitize_text_field( (string) $new_uuid );
		$new_version      = sanitize_text_field( (string) $new_version );
		$new_payload_json = (string) $new_payload_json;
		$event_type  = sanitize_text_field( (string) $event_type );
		$entity_type = sanitize_key( (string) $entity_type );
		$entity_guid   = sanitize_text_field( (string) $entity_guid );
		$connection_id = absint( $connection_id );

		if ( $new_id <= 0 || $connection_id <= 0 || '' === $event_type || '' === $entity_type || '' === $entity_guid || ! self::table_exists() ) {
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
				"SELECT id, event_uuid, event_version, status, payload_json FROM {$table}
				WHERE id < %d
					AND connection_id = %d
					AND status IN ('pending','processing','done','failed')
					AND event_type = %s
					AND entity_type = %s
					AND entity_guid = %s
				ORDER BY id DESC",
				$new_id,
				$connection_id,
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
			$old_status       = sanitize_key( (string) ( isset( $candidate['status'] ) ? $candidate['status'] : '' ) );
			$old_payload_json = (string) ( isset( $candidate['payload_json'] ) ? $candidate['payload_json'] : '' );
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
					array( 'id' => $new_id, 'connection_id' => $connection_id, 'status' => 'pending' ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d', '%d', '%s' )
				);
				self::invalidate_summary_cache();
				return 0;
			}

			/* Equal ordering evidence is only a replay when the persisted desired-state
			 * payload is equivalent. The same revision with a different payload is an
			 * ordering contract violation and must fail closed before any mutation. */
			if ( 0 === $comparison ) {
				$payload_equivalent = $this->event_payloads_equivalent( $new_payload_json, $old_payload_json );
				if ( ! $payload_equivalent ) {
					$progress = wp_json_encode(
						array(
							'deleteFile'          => false,
							'orderingConflict'    => true,
							'conflictsWithEventId'=> sanitize_text_field( (string) ( isset( $candidate['event_uuid'] ) ? $candidate['event_uuid'] : '' ) ),
							'reason'              => 'equal-version-payload-conflict',
						),
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
					);
					$wpdb->update(
						$table,
						array(
							'status'        => 'failed',
							'locked_until'  => null,
							'next_retry_at' => null,
							'last_error'    => 'Equal event version carried a conflicting desired-state payload.',
							'progress_json' => false === $progress ? '{"orderingConflict":true}' : $progress,
							'updated_at'    => $now,
						),
						array( 'id' => $new_id, 'connection_id' => $connection_id, 'status' => 'pending' )
					);
					self::invalidate_summary_cache();
					return 0;
				}

				/* A failed equal-version owner may be recovered by the new equivalent row.
				 * For pending/processing/done owners, keep the existing owner/evidence and
				 * retire the duplicate arrival without reapplying the same desired state. */
				if ( 'failed' !== $old_status ) {
					$progress = wp_json_encode(
						array(
							'deleteFile'          => true,
							'superseded'          => true,
							'supersededByEventId' => sanitize_text_field( (string) ( isset( $candidate['event_uuid'] ) ? $candidate['event_uuid'] : '' ) ),
							'reason'              => 'equal-version-replay',
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
						array( 'id' => $new_id, 'connection_id' => $connection_id, 'status' => 'pending' )
					);
					self::invalidate_summary_cache();
					return 0;
				}

				continue;
			}

			/* Two non-empty but incomparable versions are not ordered by arrival.
			 * Preserve both rows and let the processor fail closed if durable state
			 * already carries a different incomparable watermark. */
			if ( null === $comparison && '' !== $new_version && '' !== $old_version && $new_version !== $old_version ) {
				continue;
			}

			/* Only still-pending rows may be physically superseded. Processing, done
			 * and failed rows remain immutable evidence for recovery/diagnostics. */
			if ( $old_id > 0 && 'pending' === $old_status ) {
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
			array( false === $progress ? '{"superseded":true}' : $progress, $now, $connection_id ),
			$ids_to_supersede
		);
		$updated = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $update_args contains the two fixed %s values followed by dynamic %d ID replacements; wpdb::prepare() expands the single array at runtime.
				"UPDATE {$table}
				SET status = 'done', locked_until = NULL, next_retry_at = NULL, last_error = NULL, progress_json = %s, updated_at = %s
				WHERE connection_id = %d AND status = 'pending' AND id IN ({$id_placeholders})",
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
	/**
	 * Compare two persisted event payloads as desired-state evidence.
	 * Transport/ordering metadata is removed because it is already represented by
	 * the row identity/version contract; associative key order is canonicalized.
	 *
	 * @param string $left_json Left payload JSON.
	 * @param string $right_json Right payload JSON.
	 * @return bool
	 */
	private function event_payloads_equivalent( $left_json, $right_json ) {
		$left  = json_decode( (string) $left_json, true );
		$right = json_decode( (string) $right_json, true );
		if ( ! is_array( $left ) || ! is_array( $right ) ) {
			return hash_equals( (string) $left_json, (string) $right_json );
		}
		$left  = $this->canonicalize_event_payload_for_compare( $left );
		$right = $this->canonicalize_event_payload_for_compare( $right );
		$left_encoded  = wp_json_encode( $left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$right_encoded = wp_json_encode( $right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $left_encoded || false === $right_encoded ) {
			return false;
		}
		return hash_equals( hash( 'sha256', $left_encoded ), hash( 'sha256', $right_encoded ) );
	}

	/**
	 * Canonicalize payload evidence without treating delivery metadata as state.
	 *
	 * @param mixed $value Payload value.
	 * @return mixed
	 */
	private function canonicalize_event_payload_for_compare( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $this->is_list_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out[] = $this->canonicalize_event_payload_for_compare( $item );
			}
			return $out;
		}

		$ignored = array( 'syncid', 'eventid', 'event_id', 'webhookid', 'webhook_id', 'sourcerevision', 'revision', 'eventversion', '_moboeventversion', 'version', 'entityversion', 'entityguid' );
		$out     = array();
		foreach ( $value as $key => $item ) {
			$lower = strtolower( (string) $key );
			/* envA T15: '_mobo*' keys (_moboWebhookForegroundContext, _moboQueueEventUuid,
			 * ...) and entityGuid (rehydrated from the row's entity_guid column) are
			 * added by this plugin while processing and persisted with a partially or
			 * fully processed row. Comparing them made an identical re-delivery of the
			 * same revision (Backend retry with a new event id) fail as an "ordering
			 * conflict" instead of being recognised as a replay. */
			if ( in_array( $lower, $ignored, true ) || 0 === strpos( $lower, '_mobo' ) ) {
				continue;
			}
			$out[ $key ] = $this->canonicalize_event_payload_for_compare( $item );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private function compare_event_versions( $left, $right ) {
	return Mobo_Core_Ordering_Policy::compare_versions( $left, $right );
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

		$this->maybe_auto_retry_failed_events();

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$connection_id = self::connection_id();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE connection_id = %d AND ((status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
					OR (status = 'processing' AND locked_until IS NULL AND updated_at < %s))
				LIMIT 1",
				$connection_id,
				$now,
				$now,
				self::null_lock_cutoff()
			)
		);

		return absint( $id ) > 0;
	}

	/**
	 * Whether older ProductUpdated/UpdateVariant events for a product (or for any of
	 * the given variants) are still pending/processing. A delta must be applied after
	 * them; otherwise its newer revision would make the older complete event look
	 * stale. Read errors answer true (defer, never reorder).
	 *
	 * @param string $product_guid Product GUID.
	 * @param array  $variant_guids Variant GUIDs touched by the delta.
	 * @param string $current_event_uuid Queue UUID of the delta itself ('' = inline).
	 * @return bool
	 */
	public function has_open_events_before( $product_guid, $variant_guids = array(), $current_event_uuid = '' ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( '' === $product_guid || ! self::table_exists() ) {
			return false;
		}

		$table         = self::table_name();
		$connection_id = self::connection_id();
		$guids         = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $variant_guids ) ) ) ) );
		$args          = array( $connection_id, $product_guid );
		$variant_sql   = '';
		if ( ! empty( $guids ) ) {
			$variant_sql = " OR (entity_type = 'variant' AND entity_guid IN (" . implode( ',', array_fill( 0, count( $guids ), '%s' ) ) . '))';
			$args        = array_merge( $args, $guids );
		}
		$before_sql         = '';
		$current_event_uuid = sanitize_text_field( (string) $current_event_uuid );
		if ( '' !== $current_event_uuid ) {
			$before_sql = " AND id < COALESCE((SELECT x.id FROM {$table} x WHERE x.connection_id = %d AND x.event_uuid = %s LIMIT 1), 18446744073709551615)";
			$args[]     = $connection_id;
			$args[]     = $current_event_uuid;
		}

		$wpdb->last_error = '';
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE connection_id = %d
					AND status IN ('pending','processing')
					AND event_type IN ('ProductUpdated','UpdateVariant')
					AND ((entity_type = 'product' AND entity_guid = %s){$variant_sql}){$before_sql}
				LIMIT 1",
				$args
			)
		);
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			return true;
		}
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

		$connection_id = self::connection_id();
		if ( ! $force && isset( self::$summary_cache[ $connection_id ] ) && is_array( self::$summary_cache[ $connection_id ] ) ) {
			return self::$summary_cache[ $connection_id ];
		}

		$empty = array(
			'available'            => false,
			'readFailed'           => false,
			'pendingCount'         => 0, // Backward-compatible active count: pending + processing.
			'queuedPendingCount'   => 0,
			'processingCount'      => 0,
			'activeCount'          => 0,
			'dueCount'             => 0,
			'failedCount'          => 0,
			'actionableFailedCount'=> 0,
			'historicalFailedCount'=> 0,
			'oldestPendingAt'      => '',
			'newestPendingUpdateAt'=> '',
			'nextDeferredAt'       => '',
		);

		if ( ! self::table_exists() ) {
			self::$summary_cache[ $connection_id ] = $empty;
			return $empty;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$wpdb->last_error = '';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN e.status IN ('pending','processing') THEN 1 ELSE 0 END) AS active_count,
					SUM(CASE WHEN e.status = 'pending' THEN 1 ELSE 0 END) AS queued_pending_count,
					SUM(CASE WHEN e.status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
					SUM(CASE WHEN (e.status = 'pending' AND (e.next_retry_at IS NULL OR e.next_retry_at <= %s))
						OR (e.status = 'processing' AND e.locked_until IS NOT NULL AND e.locked_until < %s) THEN 1 ELSE 0 END) AS due_count,
					SUM(CASE WHEN e.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
					MIN(CASE WHEN e.status IN ('pending','processing') THEN e.created_at ELSE NULL END) AS oldest_pending_at,
					MAX(CASE WHEN e.status IN ('pending','processing') THEN e.updated_at ELSE NULL END) AS newest_pending_update_at,
					MIN(CASE WHEN e.status = 'pending' AND e.next_retry_at IS NOT NULL AND e.next_retry_at > %s THEN e.next_retry_at ELSE NULL END) AS next_deferred_at
				FROM {$table} e
				WHERE e.connection_id = %d AND e.status IN ('pending','processing','failed')",
				$now,
				$now,
				$now,
				$connection_id,
			),
			ARRAY_A
		);

		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $row ) ) {
			$empty['readFailed'] = true;
			self::$summary_cache[ $connection_id ] = $empty;
			return $empty;
		}

		$failed_total      = absint( isset( $row['failed_count'] ) ? $row['failed_count'] : 0 );
		$failed_actionable = 0;

		/*
		 * Failed-event actionability must use the exact same ordering-aware
		 * supersession policy as Retry Failed. A newer arrival is not enough:
		 * a lower version or a different non-empty incomparable version can still
		 * leave the failed row as the only recoverable desired state.
		 *
		 * The additional reads only run when failed rows exist, and the request
		 * summary cache prevents repeated classification in the same request.
		 */
		if ( $failed_total > 0 ) {
			$wpdb->last_error = '';
			$failed_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_type, entity_type, entity_guid, event_version
					 FROM {$table}
					 WHERE connection_id = %d AND status = 'failed'
					 ORDER BY id ASC",
					$connection_id
				),
				ARRAY_A
			);

			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $failed_rows ) ) {
				$empty['readFailed'] = true;
				self::$summary_cache[ $connection_id ] = $empty;
				return $empty;
			}

			$classification = $this->classify_failed_rows_for_retry( $failed_rows, $table, $connection_id );
			if ( ! empty( $classification['readFailed'] ) ) {
				$empty['readFailed'] = true;
				self::$summary_cache[ $connection_id ] = $empty;
				return $empty;
			}

			$failed_total      = count( $failed_rows );
			$failed_actionable = count( $classification['retryIds'] );
		}

		$active_count = absint( isset( $row['active_count'] ) ? $row['active_count'] : 0 );
		self::$summary_cache[ $connection_id ] = array(
			'available'             => true,
			'readFailed'            => false,
			'pendingCount'          => $active_count, // Backward-compatible alias used by scheduler pressure.
			'queuedPendingCount'    => absint( isset( $row['queued_pending_count'] ) ? $row['queued_pending_count'] : 0 ),
			'processingCount'       => absint( isset( $row['processing_count'] ) ? $row['processing_count'] : 0 ),
			'activeCount'           => $active_count,
			'dueCount'              => absint( isset( $row['due_count'] ) ? $row['due_count'] : 0 ),
			'failedCount'           => $failed_total, // Retained terminal history; backward compatibility.
			'actionableFailedCount'=> $failed_actionable,
			'historicalFailedCount'=> max( 0, $failed_total - $failed_actionable ),
			'oldestPendingAt'       => isset( $row['oldest_pending_at'] ) ? (string) $row['oldest_pending_at'] : '',
			'newestPendingUpdateAt' => isset( $row['newest_pending_update_at'] ) ? (string) $row['newest_pending_update_at'] : '',
			'nextDeferredAt'        => isset( $row['next_deferred_at'] ) ? (string) $row['next_deferred_at'] : '',
		);

		return self::$summary_cache[ $connection_id ];
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
		$connection_id = self::connection_id();
		$null_cutoff   = self::null_lock_cutoff();

		/* S2: a row whose lease expired was being processed when PHP died (fatal,
		 * OOM, kill, timeout). Reclaim it alone so a poison event cannot take
		 * innocent rows of the same batch down with it, and fail it after
		 * CRASH_RECLAIM_LIMIT abnormal ends. */
		$reclaim_error = '';
		$ids           = array();
		$stale         = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, last_error FROM {$table}
				WHERE connection_id = %d AND status = 'processing'
					AND ((locked_until IS NOT NULL AND locked_until < %s) OR (locked_until IS NULL AND updated_at < %s))
				ORDER BY id ASC
				LIMIT 1",
				$connection_id,
				$now,
				$null_cutoff
			),
			ARRAY_A
		);
		if ( is_array( $stale ) && absint( isset( $stale['id'] ) ? $stale['id'] : 0 ) > 0 ) {
			$reclaims = self::reclaim_count( isset( $stale['last_error'] ) ? $stale['last_error'] : '' ) + 1;
			if ( $reclaims > self::CRASH_RECLAIM_LIMIT ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						SET status = 'failed', locked_until = NULL, claim_token = '', next_retry_at = NULL, last_error = %s, progress_json = '{}', updated_at = %s
						WHERE connection_id = %d AND id = %d AND status = 'processing'
							AND ((locked_until IS NOT NULL AND locked_until < %s) OR (locked_until IS NULL AND updated_at < %s))",
						self::CRASH_FAILED_MESSAGE,
						$now,
						$connection_id,
						absint( $stale['id'] ),
						$now,
						$null_cutoff
					)
				);
				self::invalidate_summary_cache();
			} else {
				$ids           = array( absint( $stale['id'] ) );
				$reclaim_error = self::reclaim_message( $reclaims );
			}
		}

		if ( empty( $ids ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					WHERE connection_id = %d AND status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s)
					ORDER BY CASE WHEN event_type = 'ProductUpdated' THEN 0 ELSE 1 END, id ASC
					LIMIT %d",
					$connection_id,
					$now,
					$limit
				)
			);
		}

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$locked_until    = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$claim_token     = str_replace( '-', '', wp_generate_uuid4() );
		$error_sql       = '' !== $reclaim_error ? ', last_error = %s' : '';
		$update_args     = array( $locked_until, $claim_token, $now );
		if ( '' !== $reclaim_error ) {
			$update_args[] = $reclaim_error;
		}
		$update_args     = array_merge( $update_args, array( $connection_id ), $ids, array( $now, $now, $null_cutoff ) );
		/* Every claim is an attempt (try_count + 1). */
		$updated         = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $update_args contains fixed values, dynamic %d ID replacements, and trailing fixed values; wpdb::prepare() expands the single array at runtime.
				"UPDATE {$table}
				SET status = 'processing', try_count = try_count + 1, locked_until = %s, claim_token = %s, updated_at = %s{$error_sql}
				WHERE connection_id = %d AND id IN ({$id_placeholders})
					AND ((status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
						OR (status = 'processing' AND locked_until IS NULL AND updated_at < %s))",
				$update_args
			)
		);

		if ( false === $updated || absint( $updated ) <= 0 ) {
			return array();
		}

		self::invalidate_summary_cache();

		$select_args = array_merge( array( $connection_id ), $ids, array( $claim_token ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, connection_id, event_uuid, remote_event_id, event_type, entity_type, entity_guid, sync_id, event_version, claim_token,
					try_count, expires_at, payload_json, created_at, updated_at
				FROM {$table}
				WHERE connection_id = %d AND id IN ({$id_placeholders}) AND status = 'processing' AND claim_token = %s
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
			/* try_count already includes this claim. Callers historically compute
			 * "this attempt" as try_count + 1 from an unclaimed row, so expose the
			 * pre-claim value and keep the claimed attempt number separately. */
			$row['_mobo_claim_attempt'] = absint( isset( $row['try_count'] ) ? $row['try_count'] : 0 );
			$row['try_count']           = max( 0, $row['_mobo_claim_attempt'] - 1 );
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
		$connection_id = self::connection_id();
		$id_sql = implode( ',', $ids );
		$now    = current_time( 'mysql', true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET try_count = IF(try_count > 0, try_count - 1, 0), status = 'pending', locked_until = NULL, claim_token = '', updated_at = %s
				WHERE connection_id = %d AND status = 'processing' AND claim_token = %s AND id IN ({$id_sql})",
				$now,
				$connection_id,
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
		$connection_id = self::connection_id();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE connection_id = %d AND ((
					status = 'pending'
					AND (next_retry_at IS NULL OR next_retry_at <= %s)
				)
				OR (
					status = 'processing'
					AND locked_until IS NOT NULL
					AND locked_until < %s
				)
				OR (
					status = 'processing'
					AND locked_until IS NULL
					AND updated_at < %s
				))
				ORDER BY CASE WHEN event_type = 'ProductUpdated' THEN 0 ELSE 1 END, id ASC
				LIMIT %d",
				$connection_id,
				$now,
				$now,
				self::null_lock_cutoff(),
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
		$connection_id = self::connection_id();
		$now          = current_time( 'mysql', true );
		$locked_until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );
		$claim_token  = str_replace( '-', '', wp_generate_uuid4() );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', try_count = try_count + 1, locked_until = %s, claim_token = %s, updated_at = %s
				WHERE id = %d AND connection_id = %d
				AND (
					status = 'pending'
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
					OR (status = 'processing' AND locked_until IS NULL AND updated_at < %s)
				)",
				$locked_until,
				$claim_token,
				$now,
				$id,
				$connection_id,
				$now,
				self::null_lock_cutoff()
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

		$event_version = isset( $row['event_version'] ) ? sanitize_text_field( (string) $row['event_version'] ) : '';
		if ( '' !== $event_version ) {
			/* event_version is an ordering watermark persisted outside payload_json.
			 * Rehydrate it for processors so delayed retries cannot lose ordering context. */
			if ( ! isset( $payload['eventVersion'] ) ) {
				$payload['eventVersion'] = $event_version;
			}
			if ( ! isset( $payload['_moboEventVersion'] ) ) {
				$payload['_moboEventVersion'] = $event_version;
			}
		}

		return array(
			'connectionId'  => isset( $row['connection_id'] ) ? absint( $row['connection_id'] ) : 0,
			'id'            => isset( $row['event_uuid'] ) ? sanitize_text_field( (string) $row['event_uuid'] ) : '',
			'eventVersion'  => $event_version,
			'remoteEventId' => isset( $row['remote_event_id'] ) ? sanitize_text_field( (string) $row['remote_event_id'] ) : '',
			'event'         => isset( $row['event_type'] ) ? sanitize_text_field( (string) $row['event_type'] ) : '',
			'syncId'        => isset( $row['sync_id'] ) ? sanitize_text_field( (string) $row['sync_id'] ) : '',
			'try'       => isset( $row['try_count'] ) ? absint( $row['try_count'] ) : 0,
			'createdAt' => isset( $row['created_at'] ) ? self::utc_timestamp( $row['created_at'], time() ) : time(),
			'updatedAt' => isset( $row['updated_at'] ) ? self::utc_timestamp( $row['updated_at'], time() ) : time(),
			'expiresAt' => isset( $row['expires_at'] ) ? self::utc_timestamp( $row['expires_at'], 0 ) : 0,
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
		return $this->update_status( $id, 'done', array( 'locked_until' => null, 'claim_token' => '', 'next_retry_at' => null, 'last_error' => null, 'progress_json' => '{}' ), $claim_token );
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
			'progress_json' => '{}',
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
		/* Deprecated compatibility surface. A missing parent is not evidence that the
		 * variant event is obsolete: sites can legitimately be offline for hours or
		 * days. Rows are now retired only by explicit supersession/deduplication. */
		unset( $timeout_seconds, $limit );
		return 0;
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
		/* Encoding failure must not erase the last durable resume checkpoint or
		 * release ownership as if the replacement payload had been persisted. */
		if ( false === $payload_json || false === $progress_json ) {
			return false;
		}
		$defer_seconds = is_array( $progress ) && isset( $progress['deferSeconds'] ) ? absint( $progress['deferSeconds'] ) : 0;

		return $this->update_status(
			$id,
			'pending',
			array(
				/* Deferral / partial progress is not a failed attempt. */
				'try_count'     => self::TRY_COUNT_UNCLAIM,
				'payload_json'  => $payload_json,
				'progress_json' => $progress_json,
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
		$policy = self::failure_policy( $try_count, self::max_try(), $final_failed, $message, $id );
		$status = $policy['final'] ? 'failed' : 'pending';
		$delay  = $policy['final'] ? null : $policy['delay'];

		return $this->update_status(
			$id,
			$status,
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => null === $delay ? null : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_until'  => null,
				'claim_token'   => '',
				'last_error'    => sanitize_text_field( (string) $message ),
				'progress_json' => '{}',
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
		/* Immediate for the first two attempts (no cron may wake the site later);
		 * afterwards the same exponential/transient policy as mark_failure(). */
		$policy = self::failure_policy( $try_count, self::max_try(), $final_failed, $message, $id );
		$delay  = ( $policy['final'] || absint( $try_count ) <= 2 ) ? 0 : $policy['delay'];
		return $this->update_status(
			$id,
			$policy['final'] ? 'failed' : 'pending',
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => $delay > 0 ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : null,
				'locked_until'  => null,
				'claim_token'   => '',
				'last_error'    => sanitize_text_field( (string) $message ),
				'progress_json' => '{}',
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
		$connection_id    = self::connection_id();
		$now              = current_time( 'mysql', true );
		$stock_error_like = '%' . $wpdb->esc_like( 'stock' ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE connection_id = %d AND ((status = 'processing' AND (locked_until IS NULL OR locked_until < %s))
					OR (status = 'pending' AND try_count > 0 AND last_error LIKE %s))
				ORDER BY id ASC
				LIMIT %d",
				$connection_id,
				$now,
				$stock_error_like,
				$limit
			)
		);

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		/* Selection is not ownership: a worker can reclaim or complete a row
		 * before this UPDATE. Recheck recovery eligibility atomically so a fresh
		 * claim/terminal result is never reset by a stale maintenance snapshot. */
		$id_sql  = implode( ',', $ids );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET try_count = IF(status = 'processing', try_count, 0), status = 'pending', next_retry_at = NULL,
					locked_until = NULL, claim_token = '', last_error = NULL, updated_at = %s
				WHERE connection_id = %d AND id IN ({$id_sql})
					AND ((status = 'processing' AND (locked_until IS NULL OR locked_until < %s))
						OR (status = 'pending' AND try_count > 0 AND last_error LIKE %s))",
				$now,
				$connection_id,
				$now,
				$stock_error_like
			)
		);

		if ( false !== $updated && absint( $updated ) > 0 ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/**
	 * Classify failed rows with the canonical version-aware supersession policy.
	 *
	 * Rows are grouped by queue identity so multiple failed rows for the same
	 * entity share one bounded later-row read. Reading the newest 100 rows after
	 * the earliest failed id is equivalent to the former per-failed-row LIMIT 100:
	 * every later failed id sees either the same newest 100 candidates or a subset.
	 *
	 * @param array  $failed_rows Failed event rows.
	 * @param string $table Event table.
	 * @param int    $connection_id Connection ID.
	 * @return array
	 */
	private function classify_failed_rows_for_retry( $failed_rows, $table, $connection_id ) {
		global $wpdb;

		$result = array(
			'retryIds'      => array(),
			'supersededIds' => array(),
			'readFailed'    => false,
		);

		if ( empty( $failed_rows ) || ! is_array( $failed_rows ) ) {
			return $result;
		}

		$groups = array();
		foreach ( $failed_rows as $failed_row ) {
			$failed_id   = absint( isset( $failed_row['id'] ) ? $failed_row['id'] : 0 );
			$event_type  = sanitize_text_field( (string) ( isset( $failed_row['event_type'] ) ? $failed_row['event_type'] : '' ) );
			$entity_type = sanitize_key( (string) ( isset( $failed_row['entity_type'] ) ? $failed_row['entity_type'] : '' ) );
			$entity_guid = sanitize_text_field( (string) ( isset( $failed_row['entity_guid'] ) ? $failed_row['entity_guid'] : '' ) );

			if ( $failed_id <= 0 ) {
				continue;
			}
			if ( '' === $entity_guid ) {
				$result['retryIds'][] = $failed_id;
				continue;
			}

			$group_key = wp_json_encode( array( $event_type, $entity_type, $entity_guid ) );
			if ( false === $group_key ) {
				$group_key = $event_type . "\x1f" . $entity_type . "\x1f" . $entity_guid;
			}
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'eventType'  => $event_type,
					'entityType' => $entity_type,
					'entityGuid' => $entity_guid,
					'minId'      => $failed_id,
					'rows'       => array(),
				);
			}
			$groups[ $group_key ]['minId'] = min( absint( $groups[ $group_key ]['minId'] ), $failed_id );
			$groups[ $group_key ]['rows'][] = $failed_row;
		}

		foreach ( $groups as $group ) {
			$wpdb->last_error = '';
			$later_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_version, status FROM {$table}
					 WHERE connection_id = %d AND id > %d
					   AND event_type = %s AND entity_type = %s AND entity_guid = %s
					   AND status IN ('pending','processing','done','failed')
					 ORDER BY id DESC LIMIT 100",
					$connection_id,
					absint( $group['minId'] ),
					(string) $group['eventType'],
					(string) $group['entityType'],
					(string) $group['entityGuid']
				),
				ARRAY_A
			);

			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $later_rows ) ) {
				$result['readFailed'] = true;
				return $result;
			}

			foreach ( $group['rows'] as $failed_row ) {
				$failed_id      = absint( isset( $failed_row['id'] ) ? $failed_row['id'] : 0 );
				$failed_version = sanitize_text_field( (string) ( isset( $failed_row['event_version'] ) ? $failed_row['event_version'] : '' ) );
				$superseded     = false;

				foreach ( $later_rows as $later_row ) {
					$later_id = absint( isset( $later_row['id'] ) ? $later_row['id'] : 0 );
					if ( $later_id <= $failed_id ) {
						continue;
					}

					$later_version = sanitize_text_field( (string) ( isset( $later_row['event_version'] ) ? $later_row['event_version'] : '' ) );
					$comparison    = $this->compare_event_versions( $later_version, $failed_version );

					if ( null !== $comparison ) {
						if ( $comparison >= 0 ) {
							$superseded = true;
							break;
						}
						continue;
					}

					/* Legacy rows with no ordering evidence retain arrival-order fallback.
					 * Two different non-empty incomparable versions do not: suppressing the
					 * failed row in that case can permanently discard the only recoverable
					 * newer desired state. */
					if ( '' === $failed_version || '' === $later_version ) {
						$superseded = true;
						break;
					}
				}

				if ( $superseded ) {
					$result['supersededIds'][] = $failed_id;
				} else {
					$result['retryIds'][] = $failed_id;
				}
			}
		}

		$result['retryIds'] = array_values( array_unique( array_filter( array_map( 'absint', $result['retryIds'] ) ) ) );
		$result['supersededIds'] = array_values( array_unique( array_filter( array_map( 'absint', $result['supersededIds'] ) ) ) );
		return $result;
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

		$limit         = max( 1, min( 1000, absint( $limit ) ) );
		$table         = self::table_name();
		$connection_id = self::connection_id();
		$now           = current_time( 'mysql', true );

		$wpdb->last_error = '';
		$failed_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, entity_type, entity_guid, event_version
				 FROM {$table}
				 WHERE connection_id = %d AND status = 'failed'
				 ORDER BY id ASC LIMIT %d",
				$connection_id,
				$limit
			),
			ARRAY_A
		);

		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $failed_rows ) ) {
			return 0;
		}
		if ( empty( $failed_rows ) ) {
			return 0;
		}

		$classification = $this->classify_failed_rows_for_retry( $failed_rows, $table, $connection_id );
		if ( ! empty( $classification['readFailed'] ) ) {
			return 0;
		}

		$ids            = $classification['retryIds'];
		$superseded_ids = $classification['supersededIds'];

		/* Superseded failed rows are durable historical evidence, but they are no
		 * longer retryable work. Retire them so repeated maintenance runs cannot
		 * starve recoverable failed rows behind the same already-superseded prefix. */
		$superseded_updated = 0;
		if ( ! empty( $superseded_ids ) ) {
			$superseded_progress = wp_json_encode(
				array(
					'superseded' => true,
					'reason'     => 'newer-or-equal-event-version',
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			$superseded_placeholders = implode( ',', array_fill( 0, count( $superseded_ids ), '%d' ) );
			$superseded_params       = array_merge( array( false === $superseded_progress ? '{"superseded":true}' : $superseded_progress, $now, $connection_id ), $superseded_ids );
			$superseded_result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					 SET status = 'done', next_retry_at = NULL, locked_until = NULL, claim_token = '', last_error = NULL, progress_json = %s, updated_at = %s
					 WHERE connection_id = %d AND status = 'failed' AND id IN ({$superseded_placeholders})",
					$superseded_params
				)
			);
			$superseded_updated = false === $superseded_result ? 0 : absint( $superseded_result );
		}

		if ( empty( $ids ) ) {
			if ( ! empty( $superseded_ids ) ) {
				self::invalidate_summary_cache();
			}
			return 0;
		}

		/* Another retry/worker may already have advanced a selected failed row.
		 * Only rows still failed can be re-armed; never clear a newer live claim. */
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $now, $connection_id ), $ids );
		$updated      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'pending', try_count = 0, next_retry_at = NULL, locked_until = NULL, claim_token = '', last_error = NULL, updated_at = %s
				 WHERE connection_id = %d AND status = 'failed' AND id IN ({$placeholders})",
				$params
			)
		);

		if ( $superseded_updated > 0 || ( false !== $updated && absint( $updated ) > 0 ) ) {
			self::invalidate_summary_cache();
		}

		return false === $updated ? 0 : absint( $updated );
	}

	/** Sentinel for update_status(): give the current claim's attempt back. */
	const TRY_COUNT_UNCLAIM = '__mobo_unclaim__';

	/** UTC cutoff before which a processing row without a lease is abandoned. */
	private static function null_lock_cutoff() {
		return gmdate( 'Y-m-d H:i:s', time() - self::NULL_LOCK_STALE_SECONDS );
	}

	/**
	 * Parse a UTC MySQL DATETIME regardless of the PHP default timezone.
	 *
	 * @param string $value DATETIME (UTC).
	 * @param int    $fallback Returned when unparsable.
	 * @return int
	 */
	public static function utc_timestamp( $value, $fallback = 0 ) {
		$value = trim( (string) $value );
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return (int) $fallback;
		}
		$ts = class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::utc_strtotime( $value ) : strtotime( $value . ' UTC' );
		return false === $ts ? (int) $fallback : (int) $ts;
	}

	/** Throttled automatic re-arm of transient failures (S2). @return int */
	public function maybe_auto_retry_failed_events() {
		$connection_id = self::connection_id();
		$state         = get_option( self::AUTO_RETRY_OPTION, array() );
		$state         = is_array( $state ) ? $state : array();
		$last          = absint( isset( $state[ $connection_id ] ) ? $state[ $connection_id ] : 0 );
		if ( $last > 0 && ( time() - $last ) < self::AUTO_RETRY_INTERVAL ) {
			return 0;
		}
		$state[ $connection_id ] = time();
		update_option( self::AUTO_RETRY_OPTION, $state, false );
		return $this->auto_retry_failed_events( 100 );
	}

	/**
	 * Re-arm failed rows whose failure was transient, that are younger than
	 * AUTO_RETRY_MAX_AGE and that no newer event superseded. The attempt counter
	 * is kept (a still-broken site fails the row again after one attempt), the
	 * row's expiry is extended because it was proven to be still relevant.
	 * The re-arm UPDATE itself is the storage-recovery probe: while the
	 * database cannot write, nothing changes.
	 *
	 * @param int $limit Max rows.
	 * @return int Rows re-armed.
	 */
	public function auto_retry_failed_events( $limit = 100 ) {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$limit         = max( 1, min( 500, absint( $limit ) ) );
		$table         = self::table_name();
		$connection_id = self::connection_id();
		$now           = current_time( 'mysql', true );
		$min_created   = gmdate( 'Y-m-d H:i:s', time() - self::AUTO_RETRY_MAX_AGE );

		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, entity_type, entity_guid, event_version, last_error
				 FROM {$table}
				 WHERE connection_id = %d AND status = 'failed' AND created_at >= %s
				 ORDER BY id ASC LIMIT %d",
				$connection_id,
				$min_created,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}
		$transient_rows = array();
		foreach ( $rows as $row ) {
			if ( self::is_transient_failure( isset( $row['last_error'] ) ? $row['last_error'] : '' ) ) {
				$transient_rows[] = $row;
			}
		}
		if ( empty( $transient_rows ) ) {
			return 0;
		}
		$classification = $this->classify_failed_rows_for_retry( $transient_rows, $table, $connection_id );
		if ( ! empty( $classification['readFailed'] ) || empty( $classification['retryIds'] ) ) {
			return 0;
		}
		$ids          = $classification['retryIds'];
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$expire_days  = class_exists( 'Mobo_Core_Settings' ) ? Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) : 2;
		$expires_at   = gmdate( 'Y-m-d H:i:s', time() + ( $expire_days * 86400 ) );
		$params       = array_merge( array( $expires_at, $now, $connection_id ), $ids );
		$updated      = $wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic integer ID placeholders.
				"UPDATE {$table}
				 SET status = 'pending', next_retry_at = NULL, locked_until = NULL, claim_token = '', expires_at = %s, updated_at = %s
				 WHERE connection_id = %d AND status = 'failed' AND id IN ({$placeholders})",
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
		$connection_id = self::connection_id();
		$query        = "SELECT COUNT(*) FROM {$table} WHERE connection_id = %d AND status IN ({$placeholders})";
		$args         = array_merge( array( $connection_id ), $statuses );

		return absint( $wpdb->get_var( $wpdb->prepare( $query, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and placeholder list are generated internally; all status values are bound by wpdb::prepare().
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
		$connection_id = self::connection_id();

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
		$unclaim     = isset( $data['try_count'] ) && self::TRY_COUNT_UNCLAIM === $data['try_count'];
		if ( $unclaim && '' === $claim_token ) {
			/* No claim to give back without an exact lease: keep the counter. */
			unset( $data['try_count'] );
			$formats = array();
			foreach ( $data as $value ) {
				$formats[] = '%s';
			}
			$unclaim = false;
		}
		if ( '' !== $claim_token ) {
			$table = self::table_name();
			$sets  = array();
			$args  = array();
			foreach ( $data as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key ) {
					continue;
				}
				if ( 'try_count' === $key && $unclaim ) {
					/* Must precede the status assignment (left-to-right SET). */
					array_unshift( $sets, 'try_count = IF(try_count > 0, try_count - 1, 0)' );
					continue;
				}
				$sets[] = $key . ' = ' . ( null === $value ? 'NULL' : ( 'try_count' === $key ? '%d' : '%s' ) );
				if ( null !== $value ) {
					$args[] = $value;
				}
			}
			$args[] = $id;
			$args[] = $connection_id;
			$args[] = $claim_token;
			$updated = empty( $sets ) ? false : $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE id = %d AND connection_id = %d AND status = 'processing' AND claim_token = %s",
					$args
				)
			);
		} else {
			$updated = $wpdb->update( self::table_name(), $data, array( 'id' => $id, 'connection_id' => $connection_id ), $formats, array( '%d', '%d' ) );
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
					$this->get_value( $raw, 'sourceRevision', '' ),
					$this->get_value( $raw, 'revision', '' ),
					$this->get_value( $raw, 'eventVersion', '' ),
					$this->get_value( $raw, 'version', '' ),
					$this->get_value( $raw, 'Version', '' ),
					$this->get_value( $raw, 'entityVersion', '' ),
					$this->get_value( $payload, 'sourceRevision', '' ),
					$this->get_value( $payload, 'revision', '' ),
					$this->get_value( $payload, 'eventVersion', '' ),
					$this->get_value( $payload, 'version', '' ),
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
	return Mobo_Core_Event_Type_Policy::detect( $payload );
}

	/**
	 * Map old numeric event type if required.
	 *
	 * @param int $type Numeric event type.
	 * @return string
	 */
	private function map_numeric_event_type( $type ) {
	return Mobo_Core_Event_Type_Policy::map_numeric( $type );
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
