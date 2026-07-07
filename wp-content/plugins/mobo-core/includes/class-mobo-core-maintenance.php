<?php
/**
 * Periodic bounded database maintenance.
 *
 * This class is intentionally invisible in the admin UI. It runs from the real
 * cron runner with conservative fixed retention windows and small batches so it
 * cannot block normal webhook/product/order processing.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Maintenance {

	const RUN_INTERVAL_SECONDS = 21600; // 6 hours.
	const SYNC_DONE_RETENTION_DAYS = 7;
	const SYNC_FAILED_RETENTION_DAYS = 30;
	const IMAGE_FAILED_RETENTION_DAYS = 30;
	const IMAGE_ORPHAN_RETENTION_DAYS = 30;
	const IMAGE_DONE_RETENTION_DAYS = 180;
	const ACTION_COMPLETED_LOG_RETENTION_DAYS = 14;
	const ACTION_FAILED_LOG_RETENTION_DAYS = 30;
	const ACTION_ORPHAN_LOG_RETENTION_DAYS = 7;
	const DEFAULT_BATCH_LIMIT = 500;

	/**
	 * Run maintenance when due.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function maybe_run( $source = 'real-cron' ) {
		$now      = time();
		$last_run = absint( get_option( 'mobo_core_maintenance_last_run_at', 0 ) );

		if ( $last_run > 0 && ( $now - $last_run ) < self::RUN_INTERVAL_SECONDS ) {
			return array(
				'success'      => true,
				'status'       => 'skipped-not-due',
				'lastRunAt'    => $last_run,
				'nextDueAt'    => $last_run + self::RUN_INTERVAL_SECONDS,
				'intervalSecs' => self::RUN_INTERVAL_SECONDS,
			);
		}

		if ( ! class_exists( 'Mobo_Core_Lock' ) ) {
			return array(
				'success' => false,
				'status'  => 'lock-class-missing',
			);
		}

		$lock = Mobo_Core_Lock::acquire( 'maintenance_cleanup', 300 );

		if ( false === $lock ) {
			return array(
				'success' => true,
				'status'  => 'locked',
			);
		}

		try {
			$result = self::run_now( $source );
		} catch ( Throwable $e ) {
			$result = array(
				'success'        => false,
				'status'         => 'exception',
				'message'        => $e->getMessage(),
				'exceptionClass' => get_class( $e ),
				'file'           => $e->getFile(),
				'line'           => $e->getLine(),
				'executedAt'     => time(),
			);
		} finally {
			Mobo_Core_Lock::release( 'maintenance_cleanup', $lock );
		}

		update_option( 'mobo_core_maintenance_last_run_at', time(), false );
		update_option( 'mobo_core_maintenance_last_result', $result, false );

		return $result;
	}

	/**
	 * Run a bounded cleanup pass immediately.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	private static function run_now( $source ) {
		return array(
			'success'         => true,
			'status'          => 'ok',
			'source'          => sanitize_key( (string) $source ),
			'executedAt'      => time(),
			'syncEvents'      => self::cleanup_sync_events(),
			'imageQueue'      => self::cleanup_image_queue(),
			'actionScheduler' => self::cleanup_action_scheduler_logs(),
			'wpCron'          => self::cleanup_wp_cron_option(),
		);
	}

	/**
	 * Cleanup terminal webhook/sync event rows only.
	 *
	 * @return array
	 */
	private static function cleanup_sync_events() {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return array( 'status' => 'missing-table', 'deleted' => 0 );
		}

		$table       = Mobo_Core_Sync_Event_Store::table_name();
		$done_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::SYNC_DONE_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$fail_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::SYNC_FAILED_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$limit       = self::DEFAULT_BATCH_LIMIT;

		$deleted_done = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE status = 'done'
				AND updated_at < %s
				ORDER BY id ASC
				LIMIT %d",
				$done_cutoff,
				$limit
			)
		);

		$remaining = max( 0, $limit - absint( false === $deleted_done ? 0 : $deleted_done ) );
		$deleted_failed = 0;

		if ( $remaining > 0 ) {
			$deleted_failed = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE status = 'failed'
					AND updated_at < %s
					ORDER BY id ASC
					LIMIT %d",
					$fail_cutoff,
					$remaining
				)
			);
		}

		return array(
			'status'             => 'ok',
			'deletedDone'        => absint( false === $deleted_done ? 0 : $deleted_done ),
			'deletedFailed'      => absint( false === $deleted_failed ? 0 : $deleted_failed ),
			'doneRetentionDays'  => self::SYNC_DONE_RETENTION_DAYS,
			'failedRetentionDays'=> self::SYNC_FAILED_RETENTION_DAYS,
		);
	}

	/**
	 * Cleanup stale image queue rows without deleting media attachments.
	 *
	 * @return array
	 */
	private static function cleanup_image_queue() {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return array( 'status' => 'missing-table', 'deleted' => 0 );
		}

		$table       = Mobo_Core_Image_Queue::table_name();
		$posts_table = $wpdb->posts;
		$limit       = self::DEFAULT_BATCH_LIMIT;
		$fail_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_FAILED_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$orphan_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_ORPHAN_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$done_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_DONE_RETENTION_DAYS * DAY_IN_SECONDS ) );

		$deleted_failed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE status = 'failed'
				AND updated_at < %s
				ORDER BY id ASC
				LIMIT %d",
				$fail_cutoff,
				$limit
			)
		);

		$used = absint( false === $deleted_failed ? 0 : $deleted_failed );
		$deleted_missing_product = 0;
		$deleted_missing_attachment = 0;
		$deleted_old_done = 0;

		if ( $used < $limit ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT q.id
					FROM {$table} q
					LEFT JOIN {$posts_table} p ON p.ID = q.product_id
					WHERE q.updated_at < %s
					AND q.product_id > 0
					AND p.ID IS NULL
					ORDER BY q.id ASC
					LIMIT %d",
					$orphan_cutoff,
					$limit - $used
				)
			);

			$deleted_missing_product = self::delete_ids( $table, $ids );
			$used += $deleted_missing_product;
		}

		if ( $used < $limit ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT q.id
					FROM {$table} q
					LEFT JOIN {$posts_table} a ON a.ID = q.attachment_id AND a.post_type = 'attachment'
					WHERE q.status = 'done'
					AND q.updated_at < %s
					AND q.attachment_id > 0
					AND a.ID IS NULL
					ORDER BY q.id ASC
					LIMIT %d",
					$orphan_cutoff,
					$limit - $used
				)
			);

			$deleted_missing_attachment = self::delete_ids( $table, $ids );
			$used += $deleted_missing_attachment;
		}

		if ( $used < $limit ) {
			$deleted_old_done = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE status = 'done'
					AND updated_at < %s
					ORDER BY id ASC
					LIMIT %d",
					$done_cutoff,
					$limit - $used
				)
			);
		}

		return array(
			'status'                   => 'ok',
			'deletedFailed'            => $used >= 0 ? absint( false === $deleted_failed ? 0 : $deleted_failed ) : 0,
			'deletedMissingProduct'    => absint( $deleted_missing_product ),
			'deletedMissingAttachment' => absint( $deleted_missing_attachment ),
			'deletedOldDone'           => absint( false === $deleted_old_done ? 0 : $deleted_old_done ),
			'failedRetentionDays'      => self::IMAGE_FAILED_RETENTION_DAYS,
			'orphanRetentionDays'      => self::IMAGE_ORPHAN_RETENTION_DAYS,
			'doneRetentionDays'        => self::IMAGE_DONE_RETENTION_DAYS,
		);
	}

	/**
	 * Cleanup Action Scheduler log rows. Mobo does not write these logs, but large
	 * WooCommerce installs can grow this table quickly.
	 *
	 * @return array
	 */
	private static function cleanup_action_scheduler_logs() {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		if ( ! self::table_exists( $actions_table ) || ! self::table_exists( $logs_table ) ) {
			return array( 'status' => 'missing-table', 'deleted' => 0 );
		}

		$limit = self::DEFAULT_BATCH_LIMIT;
		$completed_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_COMPLETED_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$failed_cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_FAILED_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$orphan_cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_ORPHAN_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT l.log_id
				FROM {$logs_table} l
				INNER JOIN {$actions_table} a ON a.action_id = l.action_id
				WHERE a.status IN ('complete', 'canceled')
				AND l.log_date_gmt < %s
				ORDER BY l.log_id ASC
				LIMIT %d",
				$completed_cutoff,
				$limit
			)
		);

		$deleted_completed = self::delete_ids( $logs_table, $ids, 'log_id' );
		$used = $deleted_completed;
		$deleted_failed = 0;
		$deleted_orphan = 0;

		if ( $used < $limit ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id
					FROM {$logs_table} l
					INNER JOIN {$actions_table} a ON a.action_id = l.action_id
					WHERE a.status = 'failed'
					AND l.log_date_gmt < %s
					ORDER BY l.log_id ASC
					LIMIT %d",
					$failed_cutoff,
					$limit - $used
				)
			);

			$deleted_failed = self::delete_ids( $logs_table, $ids, 'log_id' );
			$used += $deleted_failed;
		}

		if ( $used < $limit ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id
					FROM {$logs_table} l
					LEFT JOIN {$actions_table} a ON a.action_id = l.action_id
					WHERE a.action_id IS NULL
					AND l.log_date_gmt < %s
					ORDER BY l.log_id ASC
					LIMIT %d",
					$orphan_cutoff,
					$limit - $used
				)
			);

			$deleted_orphan = self::delete_ids( $logs_table, $ids, 'log_id' );
		}

		return array(
			'status'                     => 'ok',
			'deletedCompletedOrCanceled' => absint( $deleted_completed ),
			'deletedFailed'              => absint( $deleted_failed ),
			'deletedOrphan'              => absint( $deleted_orphan ),
			'completedRetentionDays'     => self::ACTION_COMPLETED_LOG_RETENTION_DAYS,
			'failedRetentionDays'        => self::ACTION_FAILED_LOG_RETENTION_DAYS,
			'orphanRetentionDays'        => self::ACTION_ORPHAN_LOG_RETENTION_DAYS,
		);
	}

	/**
	 * Cleanup leftover Mobo WP-Cron hooks from the wp_options cron array.
	 *
	 * @return array
	 */
	private static function cleanup_wp_cron_option() {
		$hooks = self::mobo_cron_hooks();

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			foreach ( $hooks as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		}

		$cron = get_option( 'cron', array() );

		if ( ! is_array( $cron ) ) {
			return array( 'status' => 'invalid-cron-option', 'removed' => 0 );
		}

		$removed = 0;

		foreach ( $cron as $timestamp => $events ) {
			if ( 'version' === $timestamp || ! is_array( $events ) ) {
				continue;
			}

			foreach ( $hooks as $hook ) {
				if ( isset( $cron[ $timestamp ][ $hook ] ) ) {
					$removed += is_array( $cron[ $timestamp ][ $hook ] ) ? count( $cron[ $timestamp ][ $hook ] ) : 1;
					unset( $cron[ $timestamp ][ $hook ] );
				}
			}

			if ( isset( $cron[ $timestamp ] ) && is_array( $cron[ $timestamp ] ) && empty( $cron[ $timestamp ] ) ) {
				unset( $cron[ $timestamp ] );
			}
		}

		if ( $removed > 0 ) {
			update_option( 'cron', $cron );
		}

		return array(
			'status'  => 'ok',
			'removed' => absint( $removed ),
			'hooks'   => $hooks,
		);
	}

	/**
	 * Mobo-related WP-Cron hooks that should not stay in wp_options:cron.
	 *
	 * @return array
	 */
	public static function mobo_cron_hooks() {
		return array(
			'mobo_core_cron',
			'mobo_core_sync_cron',
			'mobo_core_product_sync_cron',
			'mobo_core_products_sync_cron',
			'mobo_core_webhook_cron',
			'mobo_core_webhook_queue_cron',
			'mobo_core_process_webhook_queue',
			'mobo_core_run_webhooks',
			'mobo_core_update_products',
			'mobo_core_update_variants',
			'mobo_core_process_queued_mobo_orders',
			'mobo_core_queue_mobo_order_submission',
			'mobo_cron_hook',
			'mobo_sync_cron_hook',
			'mobo_webhook_cron_hook',
		);
	}

	/**
	 * Delete rows by ID list.
	 *
	 * @param string $table Table name.
	 * @param array  $ids IDs.
	 * @param string $id_column ID column name.
	 * @return int
	 */
	private static function delete_ids( $table, $ids, $id_column = 'id' ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$id_column = sanitize_key( (string) $id_column );
		if ( '' === $id_column ) {
			$id_column = 'id';
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$id_column} IN ({$placeholders})", $ids ) );

		return false === $deleted ? 0 : absint( $deleted );
	}

	/**
	 * Check whether a DB table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		$table = (string) $table;
		if ( '' === $table ) {
			return false;
		}

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
