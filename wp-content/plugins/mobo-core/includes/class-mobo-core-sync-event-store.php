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
			KEY locked_until (locked_until),
			KEY entity_lookup (entity_type, entity_guid),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Check whether table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table_name();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
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

		if ( '' !== $remote_event_id ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE remote_event_id = %s AND status IN ('pending', 'processing', 'done') ORDER BY id DESC LIMIT 1",
					$remote_event_id
				)
			);

			if ( $existing ) {
				return absint( $existing );
			}
		}

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'event_uuid'       => wp_generate_uuid4(),
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

		if ( false === $inserted ) {
			return new WP_Error( 'mobo_core_event_insert_failed', 'Could not store sync event.' );
		}

		return absint( $wpdb->insert_id );
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
	 * @return bool
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

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', locked_until = %s, updated_at = %s
				WHERE id = %d
				AND (
					status = 'pending'
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)",
				$locked_until,
				$now,
				$id,
				$now
			)
		);

		return 1 === absint( $updated );
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
	 * @return void
	 */
	public function mark_done( $id ) {
		$this->update_status( $id, 'done', array( 'locked_until' => null, 'next_retry_at' => null, 'last_error' => null ) );
	}

	/**
	 * Mark event as completed while keeping diagnostic progress.
	 *
	 * @param int   $id Event ID.
	 * @param array $payload Event payload.
	 * @param array $progress Progress/diagnostic data.
	 * @return void
	 */
	public function mark_done_with_progress( $id, $payload = array(), $progress = array() ) {
		$fields = array(
			'locked_until'  => null,
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

		$this->update_status( $id, 'done', $fields );
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

			$this->mark_done_with_progress( absint( $row['id'] ), $payload, $progress );
			$retired++;
		}

		return $retired;
	}

	/**
	 * Keep partially processed event pending with updated payload/progress.
	 *
	 * @param int   $id Event ID.
	 * @param array $payload Updated payload.
	 * @param array $progress Progress data.
	 * @return void
	 */
	public function mark_pending_progress( $id, $payload, $progress = array() ) {
		$payload_json  = wp_json_encode( is_array( $payload ) ? $payload : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$progress_json = wp_json_encode( is_array( $progress ) ? $progress : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$defer_seconds = is_array( $progress ) && isset( $progress['deferSeconds'] ) ? absint( $progress['deferSeconds'] ) : 0;

		$this->update_status(
			$id,
			'pending',
			array(
				'payload_json'  => false === $payload_json ? '{}' : $payload_json,
				'progress_json' => false === $progress_json ? '{}' : $progress_json,
				'locked_until'  => null,
				'next_retry_at' => $defer_seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() + $defer_seconds ) : null,
				'last_error'    => null,
			)
		);
	}

	/**
	 * Mark event retry or failure.
	 *
	 * @param int    $id Event ID.
	 * @param string $message Error.
	 * @param int    $try_count New try count.
	 * @param bool   $final_failed Mark as failed.
	 * @return void
	 */
	public function mark_failure( $id, $message, $try_count, $final_failed = false ) {
		$status = $final_failed ? 'failed' : 'pending';
		$delay  = $final_failed ? null : min( 300, max( 30, absint( $try_count ) * 30 ) );

		$this->update_status(
			$id,
			$status,
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => null === $delay ? null : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_until'  => null,
				'last_error'    => sanitize_text_field( (string) $message ),
			)
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
	 * @return void
	 */
	public function mark_retry_now( $id, $message, $try_count, $final_failed = false ) {
		$this->update_status(
			$id,
			$final_failed ? 'failed' : 'pending',
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => null,
				'locked_until'  => null,
				'last_error'    => sanitize_text_field( (string) $message ),
			)
		);
	}

	/**
	 * Count due events that can be attempted now.
	 *
	 * @return int
	 */
	public function count_due() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE (status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)",
					$now,
					$now
				)
			)
		);
	}

	/**
	 * Count pending events.
	 *
	 * @return int
	 */
	public function count_pending() {
		return $this->count_by_statuses( array( 'pending', 'processing' ) );
	}

	/**
	 * Count failed events.
	 *
	 * @return int
	 */
	public function count_failed() {
		return $this->count_by_statuses( array( 'failed' ) );
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
				SET status = 'pending', try_count = 0, next_retry_at = NULL, locked_until = NULL, last_error = NULL, updated_at = %s
				WHERE id IN ({$placeholders})",
				$params
			)
		);

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

		$table = self::table_name();
		$total = 0;

		foreach ( $statuses as $status ) {
			$total += absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE status = %s",
						$status
					)
				)
			);
		}

		return $total;
	}

	/**
	 * Update row status and fields.
	 *
	 * @param int    $id Event ID.
	 * @param string $status Status.
	 * @param array  $fields Additional fields.
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
			if ( in_array( $key, array( 'try_count' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$wpdb->update( self::table_name(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
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
			return array(
				'entityType' => 'system',
				'entityGuid' => null,
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
