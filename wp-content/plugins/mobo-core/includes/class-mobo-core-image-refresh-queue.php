<?php
/**
 * Controlled queue for replacing legacy Mobo jpg/png attachments with WebP.
 *
 * This queue is intentionally separate from wp_mobo_image_queue because refresh
 * jobs have a destructive cleanup phase. Old files are never removed until a
 * new attachment is imported, the product points to the new attachment, and the
 * old attachment is proven unused elsewhere.
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
class Mobo_Core_Image_Refresh_Queue {

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mobo_image_refresh_queue';
	}

	/**
	 * Create/update table schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			queue_key varchar(191) NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_guid varchar(191) NOT NULL DEFAULT '',
			image_guid varchar(191) NOT NULL DEFAULT '',
			old_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			new_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			old_file_path text NULL,
			old_mime_type varchar(80) NOT NULL DEFAULT '',
			old_file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			new_source_url text NULL,
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
			KEY old_attachment_id (old_attachment_id),
			KEY new_attachment_id (new_attachment_id),
			KEY status_retry (status, next_retry_at),
			KEY locked_until (locked_until)
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
	 * Add/update one refresh job.
	 *
	 * @param array $job Job data.
	 * @return bool
	 */
	public function enqueue( $job ) {
		$result = $this->enqueue_with_result( $job );

		return ! empty( $result['success'] );
	}

	/**
	 * Add/update one refresh job and report whether it was newly queued,
	 * re-queued, already pending, or already completed.
	 *
	 * @param array $job Job data.
	 * @return array
	 */
	public function enqueue_with_result( $job ) {
		global $wpdb;

		if ( ! self::table_exists() || ! is_array( $job ) ) {
			return array( 'success' => false, 'action' => 'invalid' );
		}

		$product_id        = absint( isset( $job['product_id'] ) ? $job['product_id'] : 0 );
		$image_guid        = sanitize_text_field( (string) ( isset( $job['image_guid'] ) ? $job['image_guid'] : '' ) );
		$old_attachment_id = absint( isset( $job['old_attachment_id'] ) ? $job['old_attachment_id'] : 0 );
		$new_source_url    = esc_url_raw( (string) ( isset( $job['new_source_url'] ) ? $job['new_source_url'] : '' ) );

		if ( $product_id <= 0 || '' === $image_guid || $old_attachment_id <= 0 || '' === $new_source_url ) {
			return array( 'success' => false, 'action' => 'invalid' );
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$key   = $this->queue_key( $product_id, $image_guid, $old_attachment_id );
		$data  = array(
			'queue_key'         => $key,
			'product_id'        => $product_id,
			'product_guid'      => sanitize_text_field( (string) ( isset( $job['product_guid'] ) ? $job['product_guid'] : '' ) ),
			'image_guid'        => $image_guid,
			'old_attachment_id' => $old_attachment_id,
			'old_file_path'     => sanitize_text_field( (string) ( isset( $job['old_file_path'] ) ? $job['old_file_path'] : '' ) ),
			'old_mime_type'     => sanitize_text_field( (string) ( isset( $job['old_mime_type'] ) ? $job['old_mime_type'] : '' ) ),
			'old_file_size'     => absint( isset( $job['old_file_size'] ) ? $job['old_file_size'] : 0 ),
			'new_source_url'    => $new_source_url,
			'updated_at'        => $now,
		);

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, new_attachment_id FROM {$table} WHERE queue_key = %s LIMIT 1", $key ),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			$id     = absint( $existing['id'] );
			$status = sanitize_key( (string) $existing['status'] );

			if ( 'done' === $status ) {
				$wpdb->update( $table, $data, array( 'id' => $id ) );
				return array( 'success' => true, 'action' => 'already_done', 'id' => $id );
			}

			if ( in_array( $status, array( 'pending', 'processing' ), true ) ) {
				/* Do not unlock/reset a row that another runner may currently own. */
				$wpdb->update( $table, $data, array( 'id' => $id ) );
				return array( 'success' => true, 'action' => 'already_queued', 'id' => $id );
			}

			$data['status']        = 'pending';
			$data['try_count']     = 0;
			$data['next_retry_at'] = null;
			$data['locked_until']  = null;
			$data['last_error']    = null;
			$updated               = $wpdb->update( $table, $data, array( 'id' => $id ) );

			return array(
				'success' => false !== $updated,
				'action'  => false !== $updated ? 'requeued' : 'failed',
				'id'      => $id,
			);
		}

		$data['new_attachment_id'] = 0;
		$data['status']            = 'pending';
		$data['try_count']         = 0;
		$data['next_retry_at']     = null;
		$data['locked_until']      = null;
		$data['last_error']        = null;
		$data['created_at']        = $now;
		$inserted                  = $wpdb->insert( $table, $data );

		return array(
			'success' => false !== $inserted,
			'action'  => false !== $inserted ? 'inserted' : 'failed',
			'id'      => false !== $inserted ? absint( $wpdb->insert_id ) : 0,
		);
	}

	/**
	 * Get due jobs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_due_jobs( $limit ) {
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
					(status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
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
	 * Lock a job.
	 *
	 * @param int $id Row ID.
	 * @param int $ttl TTL seconds.
	 * @return bool
	 */
	public function lock( $id, $ttl = 120 ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
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
					status = 'pending'
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)",
				$until,
				$now,
				$id,
				$now
			)
		);

		return 1 === absint( $updated );
	}

	/**
	 * Mark done.
	 *
	 * @param int    $id Row ID.
	 * @param int    $new_attachment_id New attachment ID.
	 * @param string $note Optional note.
	 * @return void
	 */
	public function mark_done( $id, $new_attachment_id, $note = '' ) {
		$this->update_status(
			$id,
			'done',
			array(
				'new_attachment_id' => absint( $new_attachment_id ),
				'next_retry_at'     => null,
				'locked_until'      => null,
				'last_error'        => sanitize_text_field( (string) $note ),
			)
		);
	}

	/**
	 * Mark skipped.
	 *
	 * @param int    $id Row ID.
	 * @param string $message Message.
	 * @return void
	 */
	public function mark_skipped( $id, $message ) {
		$this->update_status(
			$id,
			'skipped',
			array(
				'next_retry_at' => null,
				'locked_until'  => null,
				'last_error'    => sanitize_text_field( (string) $message ),
			)
		);
	}

	/**
	 * Mark retry/failure.
	 *
	 * @param int    $id Row ID.
	 * @param string $message Error message.
	 * @param int    $try_count Try count.
	 * @param bool   $final_failed Whether final failed.
	 * @return void
	 */
	public function mark_failure( $id, $message, $try_count, $final_failed = false ) {
		$status = $final_failed ? 'failed' : 'pending';
		$delay  = $final_failed ? null : min( 1800, max( 60, absint( $try_count ) * Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_retry_base_seconds', 120, 30, 1800 ) ) );

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
	 * Reset failed rows back to pending.
	 *
	 * @return int
	 */
	public function retry_failed() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		return absint(
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = 'pending', next_retry_at = NULL, locked_until = NULL, updated_at = %s
					WHERE status = 'failed'",
					$now
				)
			)
		);
	}

	/**
	 * Clear queue rows.
	 *
	 * @param bool $only_done Clear only done/skipped rows.
	 * @return int
	 */
	public function reset( $only_done = false ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		if ( $only_done ) {
			return absint( $wpdb->query( "DELETE FROM {$table} WHERE status IN ('done', 'skipped')" ) );
		}

		return absint( $wpdb->query( "TRUNCATE TABLE {$table}" ) );
	}

	/**
	 * Count due jobs.
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
	 * Get status counters.
	 *
	 * @return array
	 */
	public function get_status() {
		$pending_count    = $this->count_by_statuses( array( 'pending' ) );
		$processing_count = $this->count_by_statuses( array( 'processing' ) );

		return array(
			'enabled'          => Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_enabled', '0' ),
			'pending'          => $pending_count + $processing_count,
			'pendingRows'      => $pending_count,
			'processing'       => $processing_count,
			'activeProcessing' => $this->count_active_processing(),
			'waitingRetry'     => $this->count_waiting_retry(),
			'due'              => $this->count_due(),
			'done'             => $this->count_by_statuses( array( 'done' ) ),
			'skipped'          => $this->count_by_statuses( array( 'skipped' ) ),
			'failed'           => $this->count_by_statuses( array( 'failed' ) ),
			'scanCursor'       => absint( get_option( 'mobo_core_image_refresh_scan_cursor', 0 ) ),
			'enqueueCursor'    => absint( get_option( 'mobo_core_image_refresh_enqueue_cursor', 0 ) ),
			'lastResult'       => get_option( 'mobo_core_image_refresh_last_result', array() ),
			'lastScan'         => get_option( 'mobo_core_image_refresh_last_scan', array() ),
			'lastEnqueue'      => get_option( 'mobo_core_image_refresh_last_enqueue', array() ),
		);
	}

	/**
	 * Count rows that are currently owned by a live processor.
	 *
	 * @return int
	 */
	public function count_active_processing() {
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
					WHERE status = 'processing'
					AND locked_until IS NOT NULL
					AND locked_until >= %s",
					$now
				)
			)
		);
	}

	/**
	 * Count pending rows whose retry time has not arrived yet.
	 *
	 * @return int
	 */
	public function count_waiting_retry() {
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
					WHERE status = 'pending'
					AND next_retry_at IS NOT NULL
					AND next_retry_at > %s",
					$now
				)
			)
		);
	}

	/**
	 * Get recent rows for admin diagnostics.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent_rows( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Update status and fields.
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
			if ( in_array( $key, array( 'new_attachment_id', 'try_count' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$wpdb->update( self::table_name(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
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
	 * Build queue key.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @param int    $old_attachment_id Old attachment ID.
	 * @return string
	 */
	private function queue_key( $product_id, $image_guid, $old_attachment_id ) {
		return md5( absint( $product_id ) . '|' . sanitize_text_field( (string) $image_guid ) . '|' . absint( $old_attachment_id ) );
	}
}
