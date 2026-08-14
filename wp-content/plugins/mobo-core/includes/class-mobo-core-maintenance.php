<?php
/**
 * Periodic bounded database maintenance.
 *
 * Mobo Core keeps its active queues intentionally durable. This component only
 * removes terminal/orphaned rows after conservative retention windows and uses
 * short, indexed chunks so housekeeping can never monopolize the real-cron
 * runner. When a large historical backlog exists, maintenance temporarily
 * switches to a five-minute catch-up cadence until the old rows are drained.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Maintenance {

	const RUN_INTERVAL_SECONDS              = 21600; // 6 hours.
	const CATCHUP_INTERVAL_SECONDS          = 300;   // 5 minutes while old rows remain.
	const EXECUTION_BUDGET_SECONDS          = 3.0;
	const DELETE_CHUNK_SIZE                 = 500;
	const SYNC_DONE_RETENTION_DAYS          = 7;
	const SYNC_FAILED_RETENTION_DAYS        = 30;
	const IMAGE_FAILED_RETENTION_DAYS       = 30;
	const IMAGE_ORPHAN_RETENTION_DAYS       = 30;
	const IMAGE_DONE_RETENTION_DAYS         = 180;
	const IMAGE_REFRESH_DONE_RETENTION_DAYS = 45;
	const PRODUCT_MAP_ORPHAN_RETENTION_DAYS = 30;
	const ACTION_COMPLETED_LOG_RETENTION_DAYS = 14;
	const ACTION_FAILED_LOG_RETENTION_DAYS    = 30;
	const ACTION_ORPHAN_LOG_RETENTION_DAYS    = 7;

	/** @var array<string,bool> Request-local table existence cache. */
	private static $table_exists_cache = array();

	/**
	 * Run maintenance when due.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function maybe_run( $source = 'real-cron' ) {
		$now      = time();
		$last_run = absint( get_option( 'mobo_core_maintenance_last_run_at', 0 ) );
		$next_due = absint( get_option( 'mobo_core_maintenance_next_due_at', 0 ) );

		if ( $next_due <= 0 && $last_run > 0 ) {
			$next_due = $last_run + self::RUN_INTERVAL_SECONDS;
		}

		if ( $next_due > $now ) {
			return array(
				'success'      => true,
				'status'       => 'skipped-not-due',
				'lastRunAt'    => $last_run,
				'nextDueAt'    => $next_due,
				'intervalSecs' => max( 0, $next_due - $now ),
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

		$finished_at = time();
		$catchup     = empty( $result['success'] ) || ! empty( $result['needsContinuation'] );
		$interval    = $catchup ? self::CATCHUP_INTERVAL_SECONDS : self::RUN_INTERVAL_SECONDS;
		$next_due    = $finished_at + $interval;

		$result['lastRunAt']    = $finished_at;
		$result['nextDueAt']    = $next_due;
		$result['intervalSecs'] = $interval;

		update_option( 'mobo_core_maintenance_last_run_at', $finished_at, false );
		update_option( 'mobo_core_maintenance_next_due_at', $next_due, false );
		update_option( 'mobo_core_maintenance_last_result', $result, false );

		return $result;
	}

	/**
	 * Run one short housekeeping slice.
	 *
	 * Each subsystem receives a fair sub-budget. A large sync-event backlog cannot
	 * starve image/map maintenance, while unused time from fast stages remains
	 * available to later stages up to the overall deadline.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	private static function run_now( $source ) {
		$started  = microtime( true );
		$deadline = $started + self::EXECUTION_BUDGET_SECONDS;

		$sync_deadline    = self::slice_deadline( $deadline, 0.85 );
		$sync_events      = self::cleanup_sync_events( $sync_deadline );
		$image_deadline   = self::slice_deadline( $deadline, 0.85 );
		$image_queue      = self::cleanup_image_queue( $image_deadline );
		$refresh_deadline = self::slice_deadline( $deadline, 0.40 );
		$image_refresh    = self::cleanup_image_refresh_queue( $refresh_deadline );
		$map_deadline     = self::slice_deadline( $deadline, 0.40 );
		$product_map      = self::cleanup_product_map( $map_deadline );

		$action_scheduler = self::has_time( $deadline, 0.12 )
			? self::cleanup_action_scheduler_logs( self::slice_deadline( $deadline, 0.30 ) )
			: array( 'status' => 'skipped-budget', 'deleted' => 0, 'likelyRemaining' => false );

		$wp_cron = self::has_time( $deadline, 0.03 )
			? self::cleanup_wp_cron_option()
			: array( 'status' => 'skipped-budget', 'removed' => 0 );

		$continuation = false;
		/* Catch-up cadence is driven only by Mobo-owned tables. A third-party
		 * Action Scheduler backlog must not make Mobo wake every five minutes. */
		foreach ( array( $sync_events, $image_queue, $image_refresh, $product_map ) as $stage ) {
			if ( is_array( $stage ) && ! empty( $stage['likelyRemaining'] ) ) {
				$continuation = true;
				break;
			}
		}

		return array(
			'success'             => true,
			'status'              => $continuation ? 'catchup' : 'ok',
			'source'              => sanitize_key( (string) $source ),
			'executedAt'          => time(),
			'elapsedMs'           => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'budgetMs'            => (int) round( self::EXECUTION_BUDGET_SECONDS * 1000 ),
			'needsContinuation'   => $continuation,
			'syncEvents'          => $sync_events,
			'imageQueue'          => $image_queue,
			'imageRefreshQueue'   => $image_refresh,
			'productMap'          => $product_map,
			'actionScheduler'     => $action_scheduler,
			'wpCron'              => $wp_cron,
		);
	}

	/**
	 * Delete terminal webhook rows in indexed chunks.
	 *
	 * @param float $deadline Monotonic wall-clock deadline.
	 * @return array
	 */
	private static function cleanup_sync_events( $deadline ) {
		if ( ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return array( 'status' => 'missing-table', 'deleted' => 0, 'likelyRemaining' => false );
		}

		$table       = Mobo_Core_Sync_Event_Store::table_name();
		$done_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::SYNC_DONE_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$fail_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::SYNC_FAILED_RETENTION_DAYS * DAY_IN_SECONDS ) );

		$done   = self::delete_status_before( $table, 'done', $done_cutoff, 3500, $deadline );
		$failed = self::has_time( $deadline, 0.03 )
			? self::delete_status_before( $table, 'failed', $fail_cutoff, 1500, $deadline )
			: array( 'deleted' => 0, 'likelyRemaining' => false, 'status' => 'skipped-budget' );

		return array(
			'status'              => 'ok',
			'deletedDone'         => absint( $done['deleted'] ),
			'deletedFailed'       => absint( $failed['deleted'] ),
			'doneRetentionDays'   => self::SYNC_DONE_RETENTION_DAYS,
			'failedRetentionDays' => self::SYNC_FAILED_RETENTION_DAYS,
			'likelyRemaining'     => ! empty( $done['likelyRemaining'] ) || ! empty( $failed['likelyRemaining'] ),
		);
	}

	/**
	 * Cleanup stale image queue rows without deleting media attachments.
	 *
	 * @param float $deadline Deadline.
	 * @return array
	 */
	private static function cleanup_image_queue( $deadline ) {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return array( 'status' => 'missing-table', 'deleted' => 0, 'likelyRemaining' => false );
		}

		$table          = Mobo_Core_Image_Queue::table_name();
		$posts_table    = $wpdb->posts;
		$fail_cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_FAILED_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$orphan_cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_ORPHAN_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$done_cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_DONE_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$permanent_like = $wpdb->esc_like( 'Permanent:' ) . '%';

		$legacy_recovery = self::has_time( $deadline, 0.12 ) && method_exists( 'Mobo_Core_Image_Queue', 'recover_legacy_failed' )
			? Mobo_Core_Image_Queue::recover_legacy_failed( 150 )
			: array( 'status' => 'skipped-budget', 'recovered' => 0, 'remaining' => 0 );
		$linkage_recovery = self::has_time( $deadline, 0.12 ) && method_exists( 'Mobo_Core_Image_Queue', 'schedule_linkage_repairs' )
			? Mobo_Core_Image_Queue::schedule_linkage_repairs( 75 )
			: array( 'status' => 'skipped-budget', 'scheduled' => 0 );

		$deleted_failed = 0;
		$failed_likely  = false;
		while ( self::has_time( $deadline, 0.03 ) && $deleted_failed < 500 ) {
			$limit = min( self::DELETE_CHUNK_SIZE, 500 - $deleted_failed );
			$rows  = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE status = 'failed'
						AND updated_at < %s
						AND last_error LIKE %s
					ORDER BY id ASC
					LIMIT %d",
					$fail_cutoff,
					$permanent_like,
					$limit
				)
			);
			if ( false === $rows ) {
				break;
			}
			$rows = absint( $rows );
			$deleted_failed += $rows;
			$failed_likely = $rows >= $limit;
			if ( $rows < $limit ) {
				break;
			}
		}

		$deleted_missing_product = 0;
		$missing_product_likely  = false;
		if ( self::has_time( $deadline, 0.06 ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT q.id
					FROM {$table} q
					LEFT JOIN {$posts_table} p ON p.ID = q.product_id
					WHERE q.updated_at < %s
						AND q.product_id > 0
						AND (p.ID IS NULL OR p.post_type <> 'product' OR p.post_status IN ('trash','auto-draft'))
					ORDER BY q.updated_at ASC, q.id ASC
					LIMIT %d",
					$orphan_cutoff,
					500
				)
			);
			$ids = self::normalize_ids( $ids );
			$missing_product_likely  = count( $ids ) >= 500;
			$deleted_missing_product = self::delete_ids( $table, $ids );
		}

		$requeued_missing_attachment = 0;
		$missing_attachment_likely   = false;
		if ( self::has_time( $deadline, 0.06 ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT q.id
					FROM {$table} q
					LEFT JOIN {$posts_table} a ON a.ID = q.attachment_id AND a.post_type = 'attachment'
					WHERE q.status = 'done'
						AND q.updated_at < %s
						AND q.attachment_id > 0
						AND a.ID IS NULL
					ORDER BY q.updated_at ASC, q.id ASC
					LIMIT %d",
					$orphan_cutoff,
					500
				)
			);
			$ids = self::normalize_ids( $ids );
			$missing_attachment_likely = count( $ids ) >= 500;
			if ( ! empty( $ids ) ) {
				$now          = current_time( 'mysql', true );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$args         = array_merge( array( $now, $now ), $ids );
				$query        = "UPDATE {$table}
					SET status = 'pending', attachment_id = 0, try_count = 0,
						next_retry_at = %s, locked_until = NULL,
						last_error = 'Completed image attachment was missing; source retry scheduled.',
						updated_at = %s
					WHERE id IN ({$placeholders})";
				$updated = $wpdb->query( $wpdb->prepare( $query, $args ) );
				$requeued_missing_attachment = absint( false === $updated ? 0 : $updated );
			}
		}

		$done = self::has_time( $deadline, 0.03 )
			? self::delete_status_before( $table, 'done', $done_cutoff, 2000, $deadline )
			: array( 'deleted' => 0, 'likelyRemaining' => false );

		return array(
			'status'                    => 'ok',
			'recoveredLegacyFailed'     => isset( $legacy_recovery['recovered'] ) ? absint( $legacy_recovery['recovered'] ) : 0,
			'remainingLegacyFailed'     => isset( $legacy_recovery['remaining'] ) ? absint( $legacy_recovery['remaining'] ) : 0,
			'scheduledLinkageRepairs'   => isset( $linkage_recovery['scheduled'] ) ? absint( $linkage_recovery['scheduled'] ) : 0,
			'deletedPermanentFailed'    => $deleted_failed,
			'deletedMissingProduct'     => $deleted_missing_product,
			'requeuedMissingAttachment' => $requeued_missing_attachment,
			'deletedOldDone'            => absint( $done['deleted'] ),
			'failedRetentionDays'       => self::IMAGE_FAILED_RETENTION_DAYS,
			'orphanRetentionDays'       => self::IMAGE_ORPHAN_RETENTION_DAYS,
			'doneRetentionDays'         => self::IMAGE_DONE_RETENTION_DAYS,
			'likelyRemaining'           => $failed_likely || $missing_product_likely || $missing_attachment_likely || ! empty( $done['likelyRemaining'] ) || ! empty( $legacy_recovery['remaining'] ),
		);
	}

	/**
	 * Remove old completed/skipped image-refresh bookkeeping rows.
	 *
	 * Failed rows are intentionally preserved for manual retry/diagnostics. Active
	 * pending/processing rows are never touched.
	 *
	 * @param float $deadline Deadline.
	 * @return array
	 */
	private static function cleanup_image_refresh_queue( $deadline ) {
		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Queue' ) || ! Mobo_Core_Image_Refresh_Queue::table_exists() ) {
			return array( 'status' => 'missing-table', 'deleted' => 0, 'likelyRemaining' => false );
		}

		$table  = Mobo_Core_Image_Refresh_Queue::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::IMAGE_REFRESH_DONE_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$done   = self::delete_status_before( $table, 'done', $cutoff, 750, $deadline );
		$skip   = self::has_time( $deadline, 0.02 )
			? self::delete_status_before( $table, 'skipped', $cutoff, 750, $deadline )
			: array( 'deleted' => 0, 'likelyRemaining' => false );

		return array(
			'status'            => 'ok',
			'deletedDone'       => absint( $done['deleted'] ),
			'deletedSkipped'    => absint( $skip['deleted'] ),
			'retentionDays'     => self::IMAGE_REFRESH_DONE_RETENTION_DAYS,
			'likelyRemaining'   => ! empty( $done['likelyRemaining'] ) || ! empty( $skip['likelyRemaining'] ),
		);
	}

	/**
	 * Remove remote->local map rows only when the local post has been physically
	 * deleted for at least the retention window. Trashed posts still exist and are
	 * therefore retained.
	 *
	 * @param float $deadline Deadline.
	 * @return array
	 */
	private static function cleanup_product_map( $deadline ) {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return array( 'status' => 'missing-table', 'deleted' => 0, 'likelyRemaining' => false );
		}

		$table  = Mobo_Core_Product_Map::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::PRODUCT_MAP_ORPHAN_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$total  = 0;
		$likely = false;

		while ( self::has_time( $deadline, 0.03 ) && $total < 1000 ) {
			$limit = min( self::DELETE_CHUNK_SIZE, 1000 - $total );
			$ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT m.id
					FROM {$table} m
					LEFT JOIN {$wpdb->posts} p ON p.ID = m.wp_post_id
					WHERE m.wp_post_id > 0
						AND m.updated_at < %s
						AND p.ID IS NULL
					ORDER BY m.updated_at ASC, m.id ASC
					LIMIT %d",
					$cutoff,
					$limit
				)
			);
			$ids = self::normalize_ids( $ids );
			if ( empty( $ids ) ) {
				$likely = false;
				break;
			}
			$deleted = self::delete_ids( $table, $ids );
			$total   += $deleted;
			$likely   = count( $ids ) >= $limit;
			if ( count( $ids ) < $limit || $deleted <= 0 ) {
				break;
			}
		}

		return array(
			'status'          => 'ok',
			'deletedOrphans'  => $total,
			'retentionDays'   => self::PRODUCT_MAP_ORPHAN_RETENTION_DAYS,
			'likelyRemaining' => $likely,
		);
	}

	/**
	 * Cleanup old Action Scheduler log rows in bulk. Actions themselves are never
	 * deleted here; only old log text is pruned.
	 *
	 * @param float $deadline Deadline.
	 * @return array
	 */
	private static function cleanup_action_scheduler_logs( $deadline ) {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		if ( ! self::table_exists( $actions_table ) || ! self::table_exists( $logs_table ) ) {
			return array( 'status' => 'missing-table', 'deleted' => 0, 'likelyRemaining' => false );
		}

		$completed_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_COMPLETED_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$failed_cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_FAILED_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$orphan_cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( self::ACTION_ORPHAN_LOG_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$deleted_completed = 0;
		$deleted_failed    = 0;
		$deleted_orphan    = 0;
		$likely            = false;

		if ( self::has_time( $deadline, 0.03 ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id FROM {$logs_table} l
					INNER JOIN {$actions_table} a ON a.action_id = l.action_id
					WHERE a.status IN ('complete','canceled') AND l.log_date_gmt < %s
					ORDER BY l.log_id ASC LIMIT 500",
					$completed_cutoff
				)
			);
			$ids = self::normalize_ids( $ids );
			$likely = $likely || count( $ids ) >= 500;
			$deleted_completed = self::delete_ids( $logs_table, $ids, 'log_id' );
		}

		if ( self::has_time( $deadline, 0.03 ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id FROM {$logs_table} l
					INNER JOIN {$actions_table} a ON a.action_id = l.action_id
					WHERE a.status = 'failed' AND l.log_date_gmt < %s
					ORDER BY l.log_id ASC LIMIT 500",
					$failed_cutoff
				)
			);
			$ids = self::normalize_ids( $ids );
			$likely = $likely || count( $ids ) >= 500;
			$deleted_failed = self::delete_ids( $logs_table, $ids, 'log_id' );
		}

		if ( self::has_time( $deadline, 0.03 ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.log_id FROM {$logs_table} l
					LEFT JOIN {$actions_table} a ON a.action_id = l.action_id
					WHERE a.action_id IS NULL AND l.log_date_gmt < %s
					ORDER BY l.log_id ASC LIMIT 500",
					$orphan_cutoff
				)
			);
			$ids = self::normalize_ids( $ids );
			$likely = $likely || count( $ids ) >= 500;
			$deleted_orphan = self::delete_ids( $logs_table, $ids, 'log_id' );
		}

		return array(
			'status'                     => 'ok',
			'deletedCompletedOrCanceled' => $deleted_completed,
			'deletedFailed'              => $deleted_failed,
			'deletedOrphan'              => $deleted_orphan,
			'completedRetentionDays'     => self::ACTION_COMPLETED_LOG_RETENTION_DAYS,
			'failedRetentionDays'        => self::ACTION_FAILED_LOG_RETENTION_DAYS,
			'orphanRetentionDays'        => self::ACTION_ORPHAN_LOG_RETENTION_DAYS,
			'likelyRemaining'            => $likely,
		);
	}

	/**
	 * Cleanup leftover legacy Mobo WP-Cron hooks with one cron-option write.
	 *
	 * Calling wp_clear_scheduled_hook() once per legacy hook can repeatedly read and
	 * rewrite the large cron option. Mobo's architecture no longer uses WP-Cron, so
	 * a single deterministic pass is both safer for performance and equivalent.
	 *
	 * @return array
	 */
	private static function cleanup_wp_cron_option() {
		$hooks = self::mobo_cron_hooks();
		$cron  = get_option( 'cron', array() );
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

		return array( 'status' => 'ok', 'removed' => absint( $removed ), 'hooks' => $hooks );
	}

	/**
	 * Delete terminal rows by status/age in short indexed chunks.
	 *
	 * @param string $table Table name.
	 * @param string $status Terminal status.
	 * @param string $cutoff UTC datetime.
	 * @param int    $max_rows Maximum rows this pass.
	 * @param float  $deadline Deadline.
	 * @return array
	 */
	private static function delete_status_before( $table, $status, $cutoff, $max_rows, $deadline ) {
		global $wpdb;

		$status   = sanitize_key( (string) $status );
		$max_rows = max( 1, absint( $max_rows ) );
		$total    = 0;
		$likely   = false;

		while ( self::has_time( $deadline, 0.02 ) && $total < $max_rows ) {
			$limit = min( self::DELETE_CHUNK_SIZE, $max_rows - $total );
			$rows  = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE status = %s AND updated_at < %s
					ORDER BY id ASC
					LIMIT %d",
					$status,
					$cutoff,
					$limit
				)
			);
			if ( false === $rows ) {
				return array( 'status' => 'db-error', 'deleted' => $total, 'likelyRemaining' => $likely );
			}
			$rows  = absint( $rows );
			$total += $rows;
			$likely = $rows >= $limit;
			if ( $rows < $limit ) {
				break;
			}
		}

		return array( 'status' => 'ok', 'deleted' => $total, 'likelyRemaining' => $likely );
	}

	/**
	 * Delete an ID list in one SQL statement from a strict internal whitelist.
	 *
	 * @param string $table Table name.
	 * @param array  $ids IDs.
	 * @param string $id_column ID column.
	 * @return int
	 */
	private static function delete_ids( $table, $ids, $id_column = 'id' ) {
		global $wpdb;

		$ids = self::normalize_ids( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		$allowed = array();
		if ( class_exists( 'Mobo_Core_Image_Queue' ) ) {
			$allowed[ Mobo_Core_Image_Queue::table_name() . '|id' ] = true;
		}
		if ( class_exists( 'Mobo_Core_Product_Map' ) ) {
			$allowed[ Mobo_Core_Product_Map::table_name() . '|id' ] = true;
		}
		$allowed[ $wpdb->prefix . 'actionscheduler_logs|log_id' ] = true;

		$key = (string) $table . '|' . sanitize_key( (string) $id_column );
		if ( empty( $allowed[ $key ] ) ) {
			return 0;
		}

		$id_column = sanitize_key( (string) $id_column );
		$id_sql     = implode( ',', $ids );
		$deleted    = $wpdb->query( "DELETE FROM {$table} WHERE {$id_column} IN ({$id_sql})" );
		return absint( false === $deleted ? 0 : $deleted );
	}

	/** @return array<int,int> */
	private static function normalize_ids( $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Return a fair stage deadline without exceeding the global maintenance budget.
	 *
	 * @param float $overall_deadline Overall deadline.
	 * @param float $seconds Stage budget.
	 * @return float
	 */
	private static function slice_deadline( $overall_deadline, $seconds ) {
		return min( (float) $overall_deadline, microtime( true ) + max( 0.05, (float) $seconds ) );
	}

	/** @return bool */
	private static function has_time( $deadline, $reserve = 0.0 ) {
		return microtime( true ) + max( 0.0, (float) $reserve ) < (float) $deadline;
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
			'mobo_core_read_webhook_interval',
			'mobo_core_sync_products_24_event',
			'mobo_core_sync_products_event',
			'mobo_core_sync_categories_event',
			'mobo_cron_hook',
			'mobo_sync_cron_hook',
			'mobo_webhook_cron_hook',
		);
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
		if ( array_key_exists( $table, self::$table_exists_cache ) ) {
			return (bool) self::$table_exists_cache[ $table ];
		}

		self::$table_exists_cache[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return (bool) self::$table_exists_cache[ $table ];
	}
}
