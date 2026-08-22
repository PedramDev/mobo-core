<?php
/**
 * File-based webhook queue.
 *
 * Webhook flow:
 * 1. REST /webhook receives JSON.
 * 2. Payload is stored as a JSON file.
 * 3. Queue processing is best-effort and chunk-safe.
 * 4. Failed/expired files are moved to webhook-files/failed/.
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
class Mobo_Core_Webhook_Queue {

	/**
	 * Store webhook payload as a file.
	 *
	 * @param array $payload Payload.
	 * @return string|WP_Error
	 */
	public function store( $payload ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mobo_core_invalid_webhook_payload', 'Invalid webhook payload.' );
		}

		$this->ensure_dirs();

		$event = $this->detect_event( $payload );

		if ( '' === $event ) {
			return new WP_Error( 'mobo_core_missing_webhook_event', 'Webhook event is missing.' );
		}

		$sync_id = $this->get_value( $payload, 'syncId', '' );

		/*
		 * Prefer the table-backed queue for new events. The JSON file queue remains
		 * as a safe fallback for old installs or write failures.
		 */
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) ) {
			$event_store = new Mobo_Core_Sync_Event_Store();
			$event_id    = $event_store->enqueue( $payload );

			if ( ! is_wp_error( $event_id ) && absint( $event_id ) > 0 ) {
				return 'event:' . absint( $event_id );
			}
		}

		$envelope = array(
			'id'        => wp_generate_uuid4(),
			'event'     => sanitize_text_field( (string) $event ),
			'syncId'    => sanitize_text_field( (string) $sync_id ),
			'try'       => 0,
			'createdAt' => time(),
			'updatedAt' => time(),
			'expiresAt' => time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ),
			'payload'   => $payload,
		);

		$filename = $this->build_filename( $envelope['event'], $envelope['id'] );
		$path     = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . $filename;

		$json = wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error( 'mobo_core_webhook_encode_failed', 'Could not encode webhook payload.' );
		}

		$written = $this->write_json_atomically( $path, $json );

		if ( ! $written ) {
			return new WP_Error( 'mobo_core_webhook_write_failed', 'Could not write webhook file.' );
		}

		return $path;
	}

	/**
	 * Return lightweight queue status.
	 *
	 * @return array
	 */
	public function get_status() {
		$this->ensure_dirs();

		$file_count = count( $this->get_queue_files() );
		$table_pending = 0;
		$table_due = 0;
		$table_failed = 0;
		$table_timing = array();
		$file_timing  = $this->get_file_timing_stats();

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store = new Mobo_Core_Sync_Event_Store();
			$summary = method_exists( $store, 'get_summary' ) ? $store->get_summary() : array();
			$table_pending = isset( $summary['pendingCount'] ) ? absint( $summary['pendingCount'] ) : $store->count_pending();
			$table_due = isset( $summary['dueCount'] ) ? absint( $summary['dueCount'] ) : ( method_exists( $store, 'count_due' ) ? $store->count_due() : $table_pending );
			$table_failed = isset( $summary['failedCount'] ) ? absint( $summary['failedCount'] ) : $store->count_failed();
			$table_timing = $this->get_table_timing_stats( $summary );
		}

		$last_result = get_option( 'mobo_core_webhook_queue_last_result', array() );
		if ( ! is_array( $last_result ) ) {
			$last_result = array();
		}

		return array(
			'pendingFiles'       => $file_count,
			'pendingTableEvents' => $table_pending,
			'dueTableEvents'     => $table_due,
			'failedTableEvents'  => $table_failed,
			'hasPending'         => $file_count > 0 || $table_pending > 0,
			'hasDue'             => $file_count > 0 || $table_due > 0,
			'lastAttemptAt'      => absint( get_option( 'mobo_core_webhook_queue_last_attempt_at', 0 ) ),
			'lastSuccessAt'      => absint( get_option( 'mobo_core_webhook_queue_last_success_at', 0 ) ),
			'lastActivityAt'     => absint( get_option( 'mobo_core_webhook_queue_last_activity_at', 0 ) ),
			'lastResult'         => $last_result,
			'tableTiming'        => $table_timing,
			'fileTiming'         => $file_timing,
		);
	}

	/**
	 * Whether there is due work that can run now.
	 *
	 * @return bool
	 */
	public function has_due_work() {
		/*
		 * Runner hot path: do not build the full admin status (counts, timing stats,
		 * legacy-file timing) just to answer a boolean question.
		 */
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store = new Mobo_Core_Sync_Event_Store();
			if ( method_exists( $store, 'has_due_events' ) && $store->has_due_events() ) {
				return true;
			}
		}

		$this->ensure_dirs();
		return ! empty( $this->get_queue_files() );
	}

	/**
	 * Whether foreground webhook work is genuinely runnable right now.
	 *
	 * Unlike has_due_work(), this helper honors nextRetryAt on legacy JSON queue
	 * items. Repair/Sync cooperative preemption uses this stricter predicate so a
	 * deferred fallback file cannot cause a busy loop that yields every product step.
	 *
	 * @return bool
	 */
	public function has_priority_work_due_now() {
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store = new Mobo_Core_Sync_Event_Store();
			if ( method_exists( $store, 'has_due_events' ) && $store->has_due_events() ) {
				return true;
			}
		}

		$this->ensure_dirs();
		$now = time();

		foreach ( $this->get_queue_files() as $file ) {
			$item = $this->read_file( $file );

			/* Malformed/expired items are runnable cleanup work and should be allowed
			 * to reach the queue processor instead of blocking behind Repair. */
			if ( is_wp_error( $item ) || ! is_array( $item ) ) {
				return true;
			}

			$retry_at = isset( $item['nextRetryAt'] ) ? absint( $item['nextRetryAt'] ) : 0;
			if ( $retry_at <= 0 || $retry_at <= $now ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fast legacy-file pressure check used after a table-backed process pass.
	 * This deliberately performs no database query.
	 *
	 * @return bool
	 */
	public function has_legacy_files() {
		$this->ensure_dirs();
		return ! empty( $this->get_queue_files() );
	}

	/**
	 * Process webhook queue.
	 *
	 * @param int|null $time_budget Optional bounded time budget override.
	 * @param int|null $max_items Optional item limit override.
	 * @param int|null $adaptive_ceiling Optional ceiling for the existing pressure-based adaptive batch.
	 * @return array
	 */
	public function process( $time_budget = null, $max_items = null, $adaptive_ceiling = null ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'webhook-queue' ), array( 'processed' => 0, 'failed' => 0, 'remainingFile' => true, 'remainingTable' => true, 'remainingDueTable' => false ) );
		}

		$this->ensure_dirs();
		update_option( 'mobo_core_webhook_queue_last_attempt_at', time(), false );

		$configured_budget = null === $time_budget
			? Mobo_Core_Settings::get_int( 'mobo_core_sync_time_budget_seconds', 8, 2, 25 )
			: max( 1, min( 25, absint( $time_budget ) ) );
		$request_timeout = max(
			15,
			Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 ),
			Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 )
		);
		$lock_ttl = min( 300, max( 30, $configured_budget + 15, $request_timeout + 30 ) );
		$lock = Mobo_Core_Lock::acquire( 'webhook_queue', $lock_ttl );

		if ( false === $lock ) {
			$result = array(
				'success'       => false,
				'status'        => 'locked',
				'processed'     => 0,
				'failed'        => 0,
				'remainingFile' => true,
				'messages'      => array( 'صف وب‌هوک در حال پردازش است.' ),
			);

			$this->save_process_result( $result );
			return $result;
		}

		try {
			$result = $this->process_locked( $configured_budget, $max_items, $adaptive_ceiling );
		} finally {
			Mobo_Core_Lock::release( 'webhook_queue', $lock );
		}

		$this->save_process_result( $result );
		return $result;
	}

	/**
	 * Persist the latest webhook queue processor result for admin diagnostics.
	 *
	 * @param array $result Processor result.
	 * @return void
	 */
	private function save_process_result( $result ) {
		if ( ! is_array( $result ) ) {
			$result = array( 'success' => false, 'status' => 'invalid-result' );
		}

		update_option( 'mobo_core_webhook_queue_last_result', $result, false );

		if ( ! empty( $result['success'] ) ) {
			update_option( 'mobo_core_webhook_queue_last_success_at', time(), false );
		}

		$processed = isset( $result['processed'] ) ? absint( $result['processed'] ) : 0;
		$failed    = isset( $result['failed'] ) ? absint( $result['failed'] ) : 0;

		if ( $processed > 0 || $failed > 0 ) {
			update_option( 'mobo_core_webhook_queue_last_activity_at', time(), false );
		}
	}

	/**
	 * Read timing information from the table-backed webhook queue.
	 *
	 * @return array
	 */
	private function get_table_timing_stats( $summary = array() ) {
		global $wpdb;

		if ( ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return array();
		}

		$table = Mobo_Core_Sync_Event_Store::table_name();

		if ( ! is_array( $summary ) || empty( $summary ) ) {
			$store   = new Mobo_Core_Sync_Event_Store();
			$summary = method_exists( $store, 'get_summary' ) ? $store->get_summary() : array();
		}

		$last = $wpdb->get_row(
			"SELECT event_type, status, try_count, updated_at, last_error
			FROM {$table}
			WHERE status IN ('pending', 'processing', 'failed')
			ORDER BY updated_at DESC, id DESC
			LIMIT 1",
			ARRAY_A
		);

		return array(
			'oldestPendingAt'      => $this->mysql_gmt_to_timestamp( isset( $summary['oldestPendingAt'] ) ? $summary['oldestPendingAt'] : '' ),
			'newestPendingUpdateAt'=> $this->mysql_gmt_to_timestamp( isset( $summary['newestPendingUpdateAt'] ) ? $summary['newestPendingUpdateAt'] : '' ),
			'nextDeferredAt'       => $this->mysql_gmt_to_timestamp( isset( $summary['nextDeferredAt'] ) ? $summary['nextDeferredAt'] : '' ),
			'lastEventType'        => isset( $last['event_type'] ) ? sanitize_text_field( (string) $last['event_type'] ) : '',
			'lastStatus'           => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : '',
			'lastTryCount'         => isset( $last['try_count'] ) ? absint( $last['try_count'] ) : 0,
			'lastUpdatedAt'        => $this->mysql_gmt_to_timestamp( isset( $last['updated_at'] ) ? $last['updated_at'] : '' ),
			'lastError'            => isset( $last['last_error'] ) ? sanitize_text_field( (string) $last['last_error'] ) : '',
		);
	}

	/**
	 * Read timing information from legacy JSON webhook files.
	 *
	 * @return array
	 */
	private function get_file_timing_stats() {
		$files = $this->get_queue_files();

		if ( empty( $files ) ) {
			return array();
		}

		$oldest = 0;
		$newest = 0;
		$next_retry = 0;

		foreach ( $files as $file ) {
			$item = $this->read_file( $file );
			if ( is_wp_error( $item ) || ! is_array( $item ) ) {
				continue;
			}

			$created = isset( $item['createdAt'] ) ? absint( $item['createdAt'] ) : 0;
			$updated = isset( $item['updatedAt'] ) ? absint( $item['updatedAt'] ) : 0;
			$retry   = isset( $item['nextRetryAt'] ) ? absint( $item['nextRetryAt'] ) : 0;

			if ( $created > 0 && ( 0 === $oldest || $created < $oldest ) ) {
				$oldest = $created;
			}

			if ( $updated > $newest ) {
				$newest = $updated;
			}

			if ( $retry > time() && ( 0 === $next_retry || $retry < $next_retry ) ) {
				$next_retry = $retry;
			}
		}

		return array(
			'oldestPendingAt'       => $oldest,
			'newestPendingUpdateAt' => $newest,
			'nextDeferredAt'        => $next_retry,
		);
	}

	/**
	 * Convert a GMT MySQL datetime string to a timestamp.
	 *
	 * @param string $value MySQL datetime.
	 * @return int
	 */
	private function mysql_gmt_to_timestamp( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? 0 : absint( $timestamp );
	}


	/**
	 * Choose a bounded webhook batch from current queue pressure and the available
	 * time slice. This remains intentionally conservative: expensive payload pulls
	 * can still stop on the existing time budget, while a large local backlog gets
	 * more useful work per lock acquisition on capable hosts.
	 *
	 * @param int $base Base configured item limit.
	 * @param int $budget Current time budget.
	 * @return int
	 */
	private function resolve_adaptive_max_items( $base, $budget, $ceiling = null ) {
		$base   = max( 1, min( 10, absint( $base ) ) );
		$budget = max( 1, absint( $budget ) );

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_webhook_adaptive_batch_enabled', '1' ) ) {
			return $base;
		}

		$pressure = count( $this->get_queue_files() );
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store = new Mobo_Core_Sync_Event_Store();
			$pressure += $store->count_due();
		}

		$high = Mobo_Core_Settings::get_int( 'mobo_core_webhook_high_pressure_threshold', 25, 5, 5000 );
		$max  = Mobo_Core_Settings::get_int( 'mobo_core_webhook_adaptive_batch_max', 10, 1, 10 );
		if ( null !== $ceiling ) {
			$max = min( $max, max( 1, min( 10, absint( $ceiling ) ) ) );
			$base = min( $base, $max );
		}
		$target = $base;

		if ( $pressure >= max( 100, $high * 4 ) ) {
			$target = $max;
		} elseif ( $pressure >= $high ) {
			$target = min( $max, max( $base, 8 ) );
		} elseif ( $pressure >= 10 ) {
			$target = min( $max, max( $base, 6 ) );
		} elseif ( $pressure <= 2 ) {
			$target = min( $base, 2 );
		}

		/* Small heartbeat slices must stay cooperative even under queue pressure. */
		if ( $budget <= 4 ) {
			$target = min( $target, 3 );
		} elseif ( $budget <= 6 ) {
			$target = min( $target, 5 );
		}

		return max( 1, min( 10, $target ) );
	}


	/**
	 * Process queue while lock is held.
	 *
	 * @param int|null $time_budget Optional bounded time budget override.
	 * @param int|null $max_items Optional item limit override.
	 * @return array
	 */
	private function process_locked( $time_budget = null, $max_items = null, $adaptive_ceiling = null ) {
		$started_at = time();
		$this->maybe_cleanup_terminal_history();
		$budget     = null === $time_budget
			? Mobo_Core_Settings::get_int( 'mobo_core_sync_time_budget_seconds', 8, 2, 25 )
			: max( 1, min( 25, absint( $time_budget ) ) );
		$base_max_files = Mobo_Core_Settings::get_int( 'mobo_core_webhook_files_per_run', 4, 1, 10 );
		$max_files      = null === $max_items
			? $this->resolve_adaptive_max_items( $base_max_files, $budget, $adaptive_ceiling )
			: max( 1, min( 10, absint( $max_items ) ) );

		$processed = 0;
		$failed    = 0;
		$messages  = array();
		$remaining_table     = false;
		$remaining_due_table = false;
		$used_table          = false;

		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$table_result = $this->process_table_events( $started_at, $budget, $max_files );

			$processed += isset( $table_result['processed'] ) ? absint( $table_result['processed'] ) : 0;
			$failed    += isset( $table_result['failed'] ) ? absint( $table_result['failed'] ) : 0;
			$remaining_table     = ! empty( $table_result['remainingTable'] );
			$remaining_due_table = ! empty( $table_result['remainingDueTable'] );

			if ( ! empty( $table_result['messages'] ) && is_array( $table_result['messages'] ) ) {
				$messages = array_merge( $messages, $table_result['messages'] );
			}

			$used_table = $processed > 0 || $failed > 0 || $remaining_table;

			if ( $processed >= $max_files || ( time() - $started_at ) >= $budget ) {
				return array(
					'success'        => true,
					'status'         => 'processed',
					'processed'      => $processed,
					'failed'         => $failed,
					'remainingFile'     => $remaining_table || ! empty( $this->get_queue_files() ),
					'remainingTable'    => $remaining_table,
					'remainingDueTable' => $remaining_due_table,
					'messages'       => $messages,
				);
			}
		}

		$files = $this->get_queue_files();

		if ( empty( $files ) ) {
			if ( $used_table ) {
				return array(
					'success'        => true,
					'status'         => $processed > 0 || $failed > 0 ? 'processed' : 'empty',
					'processed'      => $processed,
					'failed'         => $failed,
					'remainingFile'     => $remaining_table,
					'remainingTable'    => $remaining_table,
					'remainingDueTable' => $remaining_due_table,
					'messages'       => empty( $messages ) ? array( 'صف وب‌هوک خالی است.' ) : $messages,
				);
			}

			return array(
				'success'       => true,
				'status'        => 'empty',
				'processed'     => 0,
				'failed'        => 0,
				'remainingFile' => false,
				'messages'      => array( 'صف وب‌هوک خالی است.' ),
			);
		}

		foreach ( $files as $file ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				$messages[] = 'پردازش صف برای آپدیت امن افزونه در مرز امن متوقف شد.';
				break;
			}

			if ( $processed >= $max_files ) {
				break;
			}

			if ( ( time() - $started_at ) >= $budget ) {
				$messages[] = 'بودجه زمانی پردازش صف به پایان رسید.';
				break;
			}

			$item = $this->read_file( $file );

			if ( is_wp_error( $item ) ) {
				/*
				* Invalid JSON can never be processed.
				* Move it away and continue to the next file.
				*/
				if ( ! $this->move_to_failed( $file, 'invalid-json' ) ) {
					$messages[] = 'فایل JSON نامعتبر قابل انتقال به failed نبود؛ پردازش متوقف شد.';
					break;
				}
				$failed++;
				$messages[] = 'یک فایل وب‌هوک نامعتبر به failed منتقل شد.';
				continue;
			}

			$item = $this->normalize_queue_item( $item, $file );

			/* A previous run may have completed the side effect but failed to remove the
			 * fallback file from the active directory. Never execute a terminal envelope
			 * again; only retry its archival move. */
			if ( ! empty( $item['terminal'] ) ) {
				if ( $this->move_to_processed( $file ) || ! file_exists( $file ) ) {
					$messages[] = 'یک فایل terminal وب‌هوک بدون اجرای دوباره از صف فعال خارج شد.';
					continue;
				}
				$failed++;
				$messages[] = 'فایل terminal وب‌هوک قابل آرشیو نبود؛ برای جلوگیری از اجرای دوباره پردازش متوقف شد.';
				break;
			}

			if ( empty( $item['event'] ) || empty( $item['payload'] ) || ! is_array( $item['payload'] ) ) {
				/*
				* Invalid envelope can never be processed.
				* Move it away and continue to the next file.
				*/
				if ( ! $this->move_to_failed( $file, 'invalid-envelope' ) ) {
					$messages[] = 'envelope نامعتبر قابل انتقال به failed نبود؛ پردازش متوقف شد.';
					break;
				}
				$failed++;
				$messages[] = 'ساختار فایل وب‌هوک نامعتبر بود.';
				continue;
			}

			if ( ! empty( $item['nextRetryAt'] ) && time() < absint( $item['nextRetryAt'] ) ) {
				$messages[] = 'یک فایل وب‌هوک هنوز در زمان defer است و بعداً پردازش می‌شود.';
				continue;
			}

			if ( ! empty( $item['expiresAt'] ) && time() > absint( $item['expiresAt'] ) ) {
				/*
				* Expired item is no longer valid.
				* Move it away and continue to the next file.
				*/
				if ( ! $this->move_to_failed( $file, 'expired' ) ) {
					$messages[] = 'فایل منقضی قابل انتقال به failed نبود؛ پردازش متوقف شد.';
					break;
				}
				$failed++;
				$messages[] = 'یک وب‌هوک منقضی شد و به failed منتقل شد.';
				continue;
			}

			$result = $this->process_item_safely( $item );

			if ( ! is_array( $result ) ) {
				$result = array(
					'success' => false,
					'message' => 'Invalid processor result.',
					'data'    => array(),
				);
			}

			if ( ! empty( $result['success'] ) ) {
				$data        = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
				$delete_file = array_key_exists( 'deleteFile', $data ) ? (bool) $data['deleteFile'] : true;

				if ( ! $delete_file && $this->should_retire_waiting_for_parent( $item, $data ) ) {
					$data = $this->build_waiting_parent_retired_data( $item, $data );
					$item['lastResult'] = $data;
					if ( ! $this->write_item( $file, $item ) ) {
						$failed++;
						$messages[] = 'ذخیره وضعیت نهایی فایل وب‌هوک ناموفق بود؛ نسخه durable قبلی حفظ شد و پردازش متوقف شد.';
						break;
					}
					if ( ! $this->move_to_failed( $file, 'parent-wait-timeout' ) ) {
						$failed++;
						$messages[] = 'فایل parent-wait terminal قابل انتقال از صف active نبود؛ پردازش متوقف شد.';
						break;
					}

					$processed++;
					$messages[] = 'UpdateVariant بیش از مهلت مجاز منتظر محصول مادر ماند و از صف فایل خارج شد.';

					continue;
				}

				if ( $delete_file ) {
					if ( ! $this->retire_completed_file( $file, $item, $data ) ) {
						$failed++;
						$messages[] = 'side-effect وب‌هوک موفق بود، اما خروج durable فایل از صف تأیید نشد؛ envelope terminal شد و اجرای دوباره متوقف شد.';
						break;
					}
				} else {
					$item['try']       = absint( $item['try'] );
					$item['updatedAt'] = time();

					if ( ! empty( $data['deferSeconds'] ) ) {
						$item['nextRetryAt'] = time() + absint( $data['deferSeconds'] );
					} else {
						unset( $item['nextRetryAt'] );
					}

					if ( isset( $item['payload'] ) && is_array( $item['payload'] ) && isset( $result['payload'] ) && is_array( $result['payload'] ) ) {
						$item['payload'] = $result['payload'];
					}

					if ( ! $this->write_item( $file, $item ) ) {
						$failed++;
						$messages[] = 'ذخیره وضعیت defer وب‌هوک ناموفق بود؛ نسخه durable قبلی حفظ شد و پردازش متوقف شد.';
						break;
					}

					if ( ! empty( $data['waitingForParent'] ) ) {
						$messages[] = 'UpdateVariant فایل منتظر محصول مادر است؛ این فایل defer شد و runner سراغ فایل بعدی رفت.';

						continue;
					}
				}

				$processed++;
				$messages[] = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : 'وب‌هوک پردازش شد.';

				continue;
			}

			/*
			* Business/processing failure.
			*
			* Important:
			* Queue is ordered. Later files may depend on this file.
			* Example:
			* - ProductUpdated fails
			* - UpdateVariant for the same product must NOT run
			*
			* Therefore:
			* - keep current file in queue
			* - increment try
			* - stop this queue run
			*/
			$item['try']       = absint( $item['try'] ) + 1;
			$item['updatedAt'] = time();
			$item['lastError'] = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : 'Webhook processing failed.';

			$max_try = Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 );

			if ( $item['try'] >= $max_try ) {
				/*
				* This file is blocking the ordered queue and reached max tries.
				* Move it to failed, then stop this run.
				*
				* We intentionally do NOT continue in the same run. The next run can
				* continue with the next ordered file after the failed blocker is moved.
				*/
				if ( ! $this->write_item( $file, $item ) ) {
					$failed++;
					$messages[] = 'ذخیره آخرین خطای وب‌هوک ناموفق بود؛ فایل قبلی حفظ شد و پردازش متوقف شد.';
					break;
				}
				if ( ! $this->move_to_failed( $file, 'max-try' ) ) {
					$failed++;
					$messages[] = 'انتقال فایل max-try به failed ناموفق بود؛ نسخه active حفظ شد و پردازش متوقف شد.';
					break;
				}

				$failed++;
				$messages[] = 'یک وب‌هوک پس از چند تلاش ناموفق به failed منتقل شد. پردازش صف در این اجرا متوقف شد.';

				break;
			}

			if ( ! $this->write_item( $file, $item ) ) {
				$failed++;
				$messages[] = 'ذخیره وضعیت retry وب‌هوک ناموفق بود؛ فایل قبلی حفظ شد و پردازش متوقف شد.';
				break;
			}

			$failed++;
			$messages[] = 'پردازش وب‌هوک ناموفق بود و برای تلاش بعدی در صف ماند. پردازش فایل‌های بعدی متوقف شد.';

			break;
		}

		return array(
			'success'       => true,
			'status'        => 'processed',
			'processed'     => $processed,
			'failed'        => $failed,
			'remainingFile'     => $remaining_table || ! empty( $this->get_queue_files() ),
			'remainingTable'    => $remaining_table,
			'remainingDueTable' => $remaining_due_table,
			'messages'       => $messages,
		);
	}

	/**
	 * Store remote Mobo shipping method changes for admin review.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function process_shipping_methods_changed_payload( $payload ) {
		if ( class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) ) {
			$manager = new Mobo_Core_Remote_Shipping_Methods();
			$result  = $manager->store_snapshot( $payload, 'webhook' );
			if ( empty( $result['success'] ) ) {
				return array(
					'success' => false,
					'message' => isset( $result['message'] ) ? $result['message'] : 'Mobo shipping methods payload was invalid.',
				);
			}
		}

		return array(
			'success' => true,
			'message' => 'Mobo shipping methods change was stored for admin review.',
			'data'    => array( 'deleteFile' => true ),
		);
	}

	/**
	 * Store webhook delivery status notification for admin display.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function process_webhook_delivery_status_payload( $payload ) {
		$data = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		$at   = time();
		update_option( 'mobo_core_portal_webhook_delivery_status', $data, false );
		update_option( 'mobo_core_portal_webhook_delivery_status_at', $at, false );

		$stored_data = get_option( 'mobo_core_portal_webhook_delivery_status', null );
		$stored_at   = absint( get_option( 'mobo_core_portal_webhook_delivery_status_at', 0 ) );
		if ( maybe_serialize( $stored_data ) !== maybe_serialize( $data ) || $stored_at !== $at ) {
			return array(
				'success' => false,
				'message' => 'Webhook delivery status could not be durably stored.',
				'data'    => array(),
			);
		}

		return array(
			'success' => true,
			'message' => 'MoboCore webhook delivery status was stored.',
			'data'    => array( 'deleteFile' => true ),
		);
	}

	/**
	 * Parent wait timeout for UpdateVariant events.
	 *
	 * @return int
	 */
	private function get_parent_wait_timeout_seconds() {
		return Mobo_Core_Settings::get_int( 'mobo_core_variant_parent_wait_timeout_seconds', 600, 60, 86400 );
	}

	/**
	 * Check whether a deferred UpdateVariant should stop waiting for its parent.
	 *
	 * @param array $item Queue item.
	 * @param array $data Processor data.
	 * @return bool
	 */
	private function should_retire_waiting_for_parent( $item, $data ) {
		if ( ! is_array( $data ) || empty( $data['waitingForParent'] ) ) {
			return false;
		}

		$created_at = isset( $item['createdAt'] ) ? absint( $item['createdAt'] ) : 0;

		if ( $created_at <= 0 ) {
			return false;
		}

		return ( time() - $created_at ) >= $this->get_parent_wait_timeout_seconds();
	}

	/**
	 * Build diagnostic data for a retired missing-parent variant event.
	 *
	 * @param array $item Queue item.
	 * @param array $data Processor data.
	 * @return array
	 */
	private function build_waiting_parent_retired_data( $item, $data ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$created_at = isset( $item['createdAt'] ) ? absint( $item['createdAt'] ) : 0;
		$timeout    = $this->get_parent_wait_timeout_seconds();

		$data['deleteFile']               = true;
		$data['waitingForParent']         = true;
		$data['retiredBecause']           = 'parent_wait_timeout';
		$data['retiredAt']                = gmdate( 'Y-m-d H:i:s' );
		$data['parentWaitTimeoutSeconds'] = $timeout;
		$data['parentWaitAgeSeconds']     = $created_at > 0 ? max( 0, time() - $created_at ) : 0;

		return $data;
	}

	/**
	 * Process table-backed events.
	 *
	 * @param int $started_at Run start timestamp.
	 * @param int $budget Time budget in seconds.
	 * @param int $max_items Max events in this run.
	 * @return array
	 */
	private function process_table_events( $started_at, $budget, $max_items ) {
		$store = new Mobo_Core_Sync_Event_Store();

		$processed = 0;
		$failed    = 0;
		$messages  = array();
		$busy_products = array();

		/*
		 * The old implementation scanned progress_json LIKE '%waitingForParent%' on
		 * every webhook pass. Deferred rows already self-retire when they become due,
		 * so this safety sweep only needs to run occasionally.
		 */
		$parent_wait_timeout = $this->get_parent_wait_timeout_seconds();
		$retired_waiting     = 0;
		$last_retire_scan    = absint( get_option( 'mobo_core_parent_wait_retirement_scan_at', 0 ) );
		if ( method_exists( $store, 'retire_stale_parent_waiting_events' ) && ( $last_retire_scan <= 0 || ( time() - $last_retire_scan ) >= 300 ) ) {
			update_option( 'mobo_core_parent_wait_retirement_scan_at', time(), false );
			$retired_waiting = $store->retire_stale_parent_waiting_events( $parent_wait_timeout, max( 50, absint( $max_items ) * 20 ) );

			if ( $retired_waiting > 0 ) {
				$processed += $retired_waiting;
				$messages[] = sprintf( '%d event تنوع که بیش از حد منتظر محصول مادر مانده بود از صف خارج شد.', $retired_waiting );
			}
		}

		$remaining_slots = max( 0, absint( $max_items ) - $processed );
		$bulk_claim      = $remaining_slots > 0 && method_exists( $store, 'claim_due_events' );
		$rows            = array();
		$claim_ttl       = min(
			300,
			max(
				120,
				Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 ) + 60,
				Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 ) + 60
			)
		);

		if ( $remaining_slots > 0 ) {
			if ( $bulk_claim ) {
				/* A single product/variation application may legitimately outlive the runner's
				 * cooperative time budget. Keep row ownership aligned with the longest remote
				 * request plus a local write margin rather than the short scheduling slice. */
				$rows = $store->claim_due_events( $remaining_slots, $claim_ttl );
			} else {
				$scan_limit = max( $remaining_slots, min( 50, $remaining_slots * 10 ) );
				$rows       = $store->get_due_events( $scan_limit );
			}
		}

		if ( empty( $rows ) ) {
			$summary = method_exists( $store, 'get_summary' ) ? $store->get_summary() : array();
			$pending = isset( $summary['pendingCount'] ) ? absint( $summary['pendingCount'] ) : $store->count_pending();
			$due     = isset( $summary['dueCount'] ) ? absint( $summary['dueCount'] ) : ( method_exists( $store, 'count_due' ) ? $store->count_due() : $pending );

			return array(
				'processed'         => $retired_waiting,
				'failed'            => 0,
				'remainingTable'    => $pending > 0,
				'remainingDueTable' => $due > 0,
				'messages'          => $messages,
			);
		}

		$claimed_ids        = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		$batch_claim_token = $bulk_claim && ! empty( $rows[0]['claim_token'] ) ? sanitize_text_field( (string) $rows[0]['claim_token'] ) : '';

		try {
			foreach ( $rows as $row ) {
				if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
					$messages[] = 'پردازش جدول وب‌هوک برای آپدیت امن افزونه در مرز امن متوقف شد.';
					break;
				}

				if ( $processed >= $max_items ) {
					break;
				}

				if ( ( time() - $started_at ) >= $budget ) {
					$messages[] = 'بودجه زمانی پردازش جدول وب‌هوک به پایان رسید.';
					break;
				}

				$event_id    = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				$claim_token = isset( $row['claim_token'] ) ? sanitize_text_field( (string) $row['claim_token'] ) : '';
				if ( $event_id <= 0 ) {
					continue;
				}

				$expires_at = isset( $row['expires_at'] ) ? strtotime( (string) $row['expires_at'] ) : 0;
				if ( $expires_at > 0 && time() > $expires_at ) {
					if ( ! $store->mark_failure( $event_id, 'Webhook event expired.', absint( $row['try_count'] ), true, $claim_token ) ) {
						$messages[] = 'commit وضعیت expired وب‌هوک به دلیل از دست رفتن claim یا خطای DB انجام نشد.';
						break;
					}
					$failed++;
					$messages[] = 'یک event وب‌هوک منقضی شد و failed شد.';
					continue;
				}

				if ( empty( $row['_mobo_bulk_claimed'] ) ) {
					$claim_token = $store->lock_event( $event_id, $claim_ttl );
					if ( false === $claim_token ) {
						continue;
					}
				}

				$item = $store->row_to_item( $row );
				if ( is_wp_error( $item ) ) {
					if ( ! $store->mark_failure( $event_id, $item->get_error_message(), absint( $row['try_count'] ) + 1, true, $claim_token ) ) {
						$messages[] = 'commit وضعیت payload نامعتبر به دلیل از دست رفتن claim یا خطای DB انجام نشد.';
						break;
					}
					$failed++;
					$messages[] = 'payload یک event وب‌هوک نامعتبر بود و failed شد.';
					continue;
				}

				/*
				 * Product-level contention preflight. For rows whose queue identity is
				 * already the parent product, do not pull a remote lightweight payload
				 * when manual/repair/another worker owns that product. The actual write
				 * path still acquires the authoritative product lock afterwards.
				 */
				$event_type  = isset( $row['event_type'] ) ? sanitize_text_field( (string) $row['event_type'] ) : '';
				$entity_type = isset( $row['entity_type'] ) ? sanitize_key( (string) $row['entity_type'] ) : '';
				$product_guid = 'product' === $entity_type && in_array( $event_type, array( 'ProductUpdated', 'UpdateVariant' ), true )
					? sanitize_text_field( (string) ( isset( $row['entity_guid'] ) ? $row['entity_guid'] : '' ) )
					: '';

				if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
					$is_busy = isset( $busy_products[ $product_guid ] )
						? (bool) $busy_products[ $product_guid ]
						: ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid )
							|| Mobo_Core_Product_Concurrency::is_product_lock_busy( $product_guid ) );
					$busy_products[ $product_guid ] = $is_busy;

					if ( $is_busy ) {
						$deferred = $store->mark_pending_progress(
							$event_id,
							isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array(),
							array(
								'deleteFile'        => false,
								'deferSeconds'      => 15,
								'waitingForProduct' => true,
								'waitingReason'     => 'product_preflight_busy',
								'productGuid'       => $product_guid,
							),
							$claim_token
						);
						if ( ! $deferred ) {
							$messages[] = 'defer کردن event به دلیل از دست رفتن claim یا خطای DB commit نشد.';
							break;
						}
						$messages[] = 'محصول در مسیر دیگری در حال پردازش است؛ event پیش از payload pull برای ۱۵ ثانیه defer شد.';
						continue;
					}
				}

				$remaining_seconds = max( 0, $budget - ( time() - $started_at ) );
				if ( $remaining_seconds <= 2 ) {
					$messages[] = 'بودجه زمانی قبل از شروع payload pull بعدی به حاشیه امن رسید.';
					break;
				}

				$payload_timeout = max(
					2,
					min(
						Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 ),
						$remaining_seconds - 1
					)
				);
				$result = $this->process_item_safely( $item, $payload_timeout );
				if ( ! is_array( $result ) ) {
					$result = array(
						'success' => false,
						'message' => 'Invalid processor result.',
						'data'    => array(),
					);
				}

				if ( ! empty( $result['success'] ) ) {
					$data        = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
					$delete_file = array_key_exists( 'deleteFile', $data ) ? (bool) $data['deleteFile'] : true;

					if ( ! $delete_file && $this->should_retire_waiting_for_parent( $item, $data ) ) {
						$updated_payload = isset( $result['payload'] ) && is_array( $result['payload'] ) ? $result['payload'] : $item['payload'];
						$data            = $this->build_waiting_parent_retired_data( $item, $data );

						if ( method_exists( $store, 'mark_done_with_progress' ) ) {
							$transitioned = $store->mark_done_with_progress( $event_id, $updated_payload, $data, $claim_token );
						} else {
							$transitioned = $store->mark_done( $event_id, $claim_token );
						}

						if ( empty( $transitioned ) ) {
							$messages[] = 'commit نهایی event منتظر parent به دلیل از دست رفتن claim یا خطای DB انجام نشد.';
							break;
						}

						$processed++;
						$messages[] = 'UpdateVariant بیش از مهلت مجاز منتظر محصول مادر ماند و از صف خارج شد.';
						continue;
					}

					if ( ! $delete_file && ! empty( $data['waitingForParent'] ) ) {
						$updated_payload = isset( $result['payload'] ) && is_array( $result['payload'] ) ? $result['payload'] : $item['payload'];
						$transitioned = $store->mark_pending_progress( $event_id, $updated_payload, $data, $claim_token );
						if ( ! $transitioned ) {
							$messages[] = 'commit defer مربوط به parent به دلیل از دست رفتن claim یا خطای DB انجام نشد.';
							break;
						}
						$messages[] = 'UpdateVariant منتظر محصول مادر است؛ این event defer شد و runner سراغ event بعدی رفت.';
						continue;
					}

					if ( $delete_file ) {
						$transitioned = $store->mark_done( $event_id, $claim_token );
					} else {
						$updated_payload = isset( $result['payload'] ) && is_array( $result['payload'] ) ? $result['payload'] : $item['payload'];
						$transitioned = $store->mark_pending_progress( $event_id, $updated_payload, $data, $claim_token );
					}

					if ( ! $transitioned ) {
						$messages[] = 'نتیجه event اجرا شد اما commit queue به دلیل claim/DB failure تأیید نشد؛ stale worker موفق اعلام نشد.';
						break;
					}

					$processed++;
					$messages[] = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : 'event وب‌هوک پردازش شد.';
					continue;
				}

				$try_count = absint( $row['try_count'] ) + 1;
				$message   = isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : 'Webhook event processing failed.';
				$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_webhook_max_try', 5, 1, 20 );
				$data      = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
				$is_payload_pull_failure = ! empty( $data['payloadPullFailed'] );

				if ( $is_payload_pull_failure ) {
					update_option( 'mobo_core_last_payload_pull_error', $message, false );
					update_option( 'mobo_core_last_payload_pull_error_at', time(), false );

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						Mobo_Core_Logger::error( 'Mobo Core payload pull failed: ' . $message );
					}

					if ( method_exists( $store, 'mark_retry_now' ) ) {
						$transitioned = $store->mark_retry_now( $event_id, $message, $try_count, $try_count >= $max_try, $claim_token );
					} else {
						$transitioned = $store->mark_failure( $event_id, $message, $try_count, $try_count >= $max_try, $claim_token );
					}
				} else {
					$transitioned = $store->mark_failure( $event_id, $message, $try_count, $try_count >= $max_try, $claim_token );
				}

				if ( empty( $transitioned ) ) {
					$messages[] = 'ثبت retry/failure event به دلیل از دست رفتن claim یا خطای DB انجام نشد.';
					break;
				}

				$failed++;
				if ( $try_count >= $max_try ) {
					$messages[] = 'یک event وب‌هوک پس از چند تلاش ناموفق failed شد. پردازش در این اجرا متوقف شد.';
				} else {
					$messages[] = 'پردازش event وب‌هوک ناموفق بود و برای retry در صف ماند. پردازش در این اجرا متوقف شد.';
				}
				break;
			}
		} finally {
			if ( $bulk_claim && ! empty( $claimed_ids ) && method_exists( $store, 'release_claimed_events' ) ) {
				$store->release_claimed_events( $claimed_ids, $batch_claim_token );
			}
		}

		$summary = method_exists( $store, 'get_summary' ) ? $store->get_summary() : array();
		$pending = isset( $summary['pendingCount'] ) ? absint( $summary['pendingCount'] ) : $store->count_pending();
		$due     = isset( $summary['dueCount'] ) ? absint( $summary['dueCount'] ) : ( method_exists( $store, 'count_due' ) ? $store->count_due() : $pending );

		return array(
			'processed'         => $processed,
			'failed'            => $failed,
			'remainingTable'    => $pending > 0,
			'remainingDueTable' => $due > 0,
			'messages'          => $messages,
		);
	}

	/**
	 * Process one webhook item.
	 *
	 * @param array $item Queue item.
	 * @return array
	 */
	private function process_item( $item, $payload_timeout = null ) {
		$event   = sanitize_text_field( (string) $item['event'] );
		$payload = isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array();

		$payload_result = $this->resolve_lightweight_payload( $event, $payload, $payload_timeout );

		if ( is_wp_error( $payload_result ) ) {
			$message = $payload_result->get_error_message();
			update_option( 'mobo_core_last_payload_pull_error', $message, false );
			update_option( 'mobo_core_last_payload_pull_error_at', time(), false );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Mobo_Core_Logger::error( 'Mobo Core payload pull failed: ' . $message );
			}

			return array(
				'success' => false,
				'message' => $message,
				'data'    => array(
					'payloadPullFailed' => true,
				),
			);
		}

		if ( is_array( $payload_result ) ) {
			$payload = $payload_result;
		}

		/*
		 * Mark foreground webhook application explicitly. Product Sync uses this
		 * context only for an ordering watermark; it is not part of the Mobo payload
		 * hash and remains harmless when a partially processed event is persisted.
		 */
		$payload['_moboWebhookForegroundContext'] = '1';

		$product_sync = new Mobo_Core_Product_Sync();

		switch ( $event ) {
			case 'ProductUpdated':
				$result = $product_sync->process_product_updated_payload( $payload );

				if ( class_exists( 'Mobo_Core_Reconciliation' ) && is_array( $result ) ) {
					Mobo_Core_Reconciliation::record_webhook_result( 'ProductUpdated', $payload, $result );
				}

				if ( is_array( $result ) ) {
					$result['payload'] = $payload;
				}

				return $result;

			case 'UpdateVariant':
				$result = $product_sync->process_update_variant_payload( $payload );
				if ( class_exists( 'Mobo_Core_Reconciliation' ) && is_array( $result ) ) {
					Mobo_Core_Reconciliation::record_webhook_result( 'UpdateVariant', $payload, $result );
				}
				return $result;

			case 'ShippingMethodsChanged':
				return $this->process_shipping_methods_changed_payload( $payload );

			case 'WebhookDeliveryStatusChanged':
				return $this->process_webhook_delivery_status_payload( $payload );

			default:
				return array(
					'success' => false,
					'message' => 'Unsupported webhook event: ' . $event,
					'data'    => array(),
				);
		}
	}

	/**
	 * Keep one malformed/legacy webhook payload from terminating the worker before
	 * its durable retry counter is updated. After max-try the existing queue policy
	 * retires the poison event and later Variant events can continue.
	 *
	 * @param array    $item Queue item.
	 * @param int|null $payload_timeout Optional payload pull timeout.
	 * @return array
	 */
	private function process_item_safely( $item, $payload_timeout = null ) {
		try {
			$result = $this->process_item( $item, $payload_timeout );
		} catch ( Throwable $throwable ) {
			$message = sanitize_text_field( (string) $throwable->getMessage() );
			if ( '' === $message ) {
				$message = 'Unhandled webhook processor exception.';
			}

			try {
				Mobo_Core_Logger::error( 'Mobo Core webhook processor exception: ' . $message );
			} catch ( Throwable $logging_error ) {
				/* Retry accounting is more important than an optional diagnostic write. */
			}

			return array(
				'success' => false,
				'message' => $message,
				'data'    => array(
					'processorException' => true,
				),
			);
		}

		return is_array( $result )
			? $result
			: array(
				'success' => false,
				'message' => 'Invalid processor result.',
				'data'    => array(),
			);
	}


	/**
	 * Resolve lightweight webhook notifications into the real payload.
	 *
	 * MoboCore phase-3 notifications contain only EventId/Type/ChangesUrl. Old full
	 * payload webhooks still bypass this method and are processed as before.
	 *
	 * @param string $event Expected event name.
	 * @param array  $payload Current payload/notification.
	 * @return array|WP_Error
	 */
	private function resolve_lightweight_payload( $event, $payload, $payload_timeout = null ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_pull_payload_enabled', '1' ) ) {
			return $payload;
		}

		$payload_url = $this->first_non_empty(
			array(
				$this->get_value( $payload, 'changesUrl', '' ),
				$this->get_value( $payload, 'payloadUrl', '' ),
				$this->get_value( $payload, 'url', '' ),
			)
		);

		if ( '' === $payload_url ) {
			return $this->unwrap_event_model_payload( $event, $payload );
		}

		/*
		 * If a payload already contains data and only happens to also contain a URL,
		 * keep the local data. This protects custom/legacy payload shapes.
		 */
		$existing_data = $this->get_value( $payload, 'data', null );
		if ( is_array( $existing_data ) && ! empty( $existing_data ) ) {
			return $this->unwrap_event_model_payload( $event, $payload );
		}

		$api      = new Mobo_Core_API_Client();
		$fetched  = $api->get_event_payload( $payload_url, $payload_timeout );

		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		$normalized = $this->unwrap_event_model_payload( $event, $fetched );

		if ( ! is_array( $normalized ) ) {
			return new WP_Error( 'mobo_core_invalid_pulled_payload', 'Pulled payload is invalid.' );
		}

		if ( ! isset( $normalized['syncId'] ) ) {
			$sync_id = $this->get_value( $payload, 'syncId', '' );
			if ( '' !== $sync_id ) {
				$normalized['syncId'] = sanitize_text_field( (string) $sync_id );
			}
		}

		$normalized['_moboPulledFrom'] = esc_url_raw( $payload_url );
		$normalized['_moboPulledAt']   = time();

		if ( 'UpdateVariant' === $event ) {
			$normalized = $this->ensure_update_variant_product_context( $normalized, $payload_url, $payload );
		}

		return $normalized;
	}

	/**
	 * Unwrap MoboCore EventModel<T> payloads:
	 * { event/type, data: {...} } or { Event/Type, Data: {...} }
	 *
	 * @param string $expected_event Expected event.
	 * @param array  $payload Payload.
	 * @return array
	 */
	private function unwrap_event_model_payload( $expected_event, $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$data = $this->get_value( $payload, 'data', null );

		if ( ! is_array( $data ) ) {
			return $payload;
		}

		$event = $this->detect_event( $payload );

		if ( '' !== $event && '' !== $expected_event && $event !== $expected_event ) {
			/*
			 * Do not unwrap a mismatched EventModel. Let the processor fail clearly
			 * rather than silently processing the wrong event type.
			 */
			return $payload;
		}

		/*
		 * Important: MoboCore paged payloads are shaped like:
		 * { productId: "...", data: [ variants/products ], pageNumber: ... }.
		 * The list in data is not an EventModel wrapper; unwrapping it would drop
		 * productId/page/cursor metadata and UpdateVariant would fail with
		 * "productId is required". Only unwrap associative EventModel data.
		 */
		if ( $this->is_list_array( $data ) ) {
			return $payload;
		}

		return $data;
	}


	private function ensure_update_variant_product_context( $normalized, $payload_url, $notification_payload ) {
		if ( ! is_array( $normalized ) ) {
			return $normalized;
		}

		$product_guid = $this->first_non_empty(
			array(
				$this->get_value( $normalized, 'product_guid', '' ),
				$this->get_value( $normalized, 'productId', '' ),
				$this->get_value( $normalized, 'productGuid', '' ),
				$this->get_value( $normalized, 'parentProductId', '' ),
				$this->get_value( $normalized, 'parentGuid', '' ),
			)
		);

		$data = $this->get_value( $normalized, 'data', null );
		if ( '' === $product_guid && is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			$product_guid = $this->first_non_empty(
				array(
					$this->get_value( $data[0], 'product_guid', '' ),
					$this->get_value( $data[0], 'productId', '' ),
					$this->get_value( $data[0], 'productGuid', '' ),
					$this->get_value( $data[0], 'parentProductId', '' ),
					$this->get_value( $data[0], 'parentGuid', '' ),
				)
			);
		}

		if ( '' === $product_guid ) {
			$product_guid = $this->first_non_empty(
				array(
					$this->get_value( $notification_payload, 'product_guid', '' ),
					$this->get_value( $notification_payload, 'productId', '' ),
					$this->get_value( $notification_payload, 'productGuid', '' ),
					$this->get_value( $notification_payload, 'entityGuid', '' ),
					$this->get_value( $notification_payload, 'entityId', '' ),
				)
			);
		}

		if ( '' === $product_guid ) {
			$product_guid = $this->extract_product_guid_from_variants_url( $payload_url );
		}

		if ( '' !== $product_guid ) {
			$normalized['productId'] = sanitize_text_field( (string) $product_guid );

			if ( is_array( $data ) ) {
				foreach ( $data as $index => $variant_data ) {
					if ( is_array( $variant_data ) ) {
						$variant_product_guid = $this->first_non_empty(
							array(
								$this->get_value( $variant_data, 'product_guid', '' ),
								$this->get_value( $variant_data, 'productId', '' ),
								$this->get_value( $variant_data, 'productGuid', '' ),
								$this->get_value( $variant_data, 'parentProductId', '' ),
								$this->get_value( $variant_data, 'parentGuid', '' ),
							)
						);

						if ( '' === $variant_product_guid ) {
							$data[ $index ]['productId'] = sanitize_text_field( (string) $product_guid );
						}
					}
				}

				$normalized['data'] = $data;
			}
		}

		return $normalized;
	}

	private function extract_product_guid_from_variants_url( $url ) {
		$url  = trim( (string) $url );
		$path = '' === $url ? '' : wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );

		foreach ( $segments as $index => $segment ) {
			if ( 'get-variants' === strtolower( $segment ) && $index > 0 ) {
				return sanitize_text_field( rawurldecode( (string) $segments[ $index - 1 ] ) );
			}
		}

		return '';
	}

	/**
	 * First non-empty scalar helper.
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
	 * Get queue files sorted by filename.
	 *
	 * @return array
	 */
	private function get_queue_files() {
		$dir = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR );

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( $dir . '*.json' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		$files = array_filter(
			$files,
			function ( $file ) {
				return is_string( $file )
					&& is_file( $file )
					&& is_readable( $file )
					&& ! $this->has_terminal_marker( $file );
			}
		);

		usort(
			$files,
			static function ( $a, $b ) {
				return strnatcasecmp( basename( $a ), basename( $b ) );
			}
		);

		return array_values( $files );
	}

	/**
	 * Read JSON file.
	 *
	 * @param string $file File path.
	 * @return array|WP_Error
	 */
	private function read_file( $file ) {
		if ( ! is_string( $file ) || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'mobo_core_webhook_file_not_readable', 'Webhook file is not readable.' );
		}

		$contents = file_get_contents( $file );

		if ( false === $contents || '' === trim( $contents ) ) {
			return new WP_Error( 'mobo_core_webhook_file_empty', 'Webhook file is empty.' );
		}

		$json = json_decode( $contents, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'mobo_core_webhook_file_invalid_json', 'Webhook file contains invalid JSON.' );
		}

		return $json;
	}

	/**
	 * Write item back to file.
	 *
	 * @param string $file File path.
	 * @param array  $item Item.
	 * @return bool
	 */
	private function write_item( $file, $item ) {
		$json = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return false;
		}

		return $this->write_json_atomically( $file, $json );
	}

	/**
	 * Persist a queue JSON document using a same-directory temp file and rename.
	 *
	 * The file queue is the database-failure fallback, so a PHP crash during a
	 * retry-state rewrite must not truncate the only durable copy of the event.
	 * Same-directory rename is atomic on normal Unix filesystems. We intentionally
	 * never rewrite the durable active file in place when rename-over-existing is
	 * unavailable; callers retry or use a separate terminal sidecar commit marker.
	 *
	 * @param string $file Final file path.
	 * @param string $json Encoded JSON.
	 * @return bool
	 */
	private function write_json_atomically( $file, $json ) {
		$file = (string) $file;
		$json = (string) $json;

		if ( '' === $file || '' === $json ) {
			return false;
		}

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$tmp = trailingslashit( $dir ) . '.' . basename( $file ) . '.tmp-' . wp_generate_password( 12, false, false );
		$written = file_put_contents( $tmp, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same-directory temp write is required for atomic rename.

		if ( false === $written || strlen( $json ) !== (int) $written ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return false;
		}

		if ( @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same-directory rename is the durability boundary.
			return true;
		}

		/*
		 * Never fall back to rewriting the durable queue file in place. On filesystems
		 * that reject rename-over-existing (notably some Windows setups), an in-place
		 * file_put_contents() can truncate the last known-good event if PHP/IO fails
		 * mid-write. Keeping the old document is safer: the worker will retry it and
		 * the caller can report that the new retry-state was not durably committed.
		 */
		wp_delete_file( $tmp );
		return false;
	}

	/**
	 * Normalize new envelope or old raw payload.
	 *
	 * @param array  $data File data.
	 * @param string $file File path.
	 * @return array
	 */
	private function normalize_queue_item( $data, $file ) {
		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) {
			$event = isset( $data['event'] ) ? sanitize_text_field( (string) $data['event'] ) : $this->detect_event( $data['payload'] );

			$data['event']     = $event;
			$data['try']       = isset( $data['try'] ) ? absint( $data['try'] ) : 0;
			$data['createdAt'] = isset( $data['createdAt'] ) ? absint( $data['createdAt'] ) : filemtime( $file );
			$data['updatedAt'] = isset( $data['updatedAt'] ) ? absint( $data['updatedAt'] ) : time();
			$data['expiresAt'] = isset( $data['expiresAt'] ) ? absint( $data['expiresAt'] ) : time() + DAY_IN_SECONDS;

			return $data;
		}

		/*
		 * Legacy raw payload support.
		 */
		$event = $this->detect_event( $data );

		return array(
			'id'        => wp_generate_uuid4(),
			'event'     => sanitize_text_field( (string) $event ),
			'syncId'    => sanitize_text_field( (string) $this->get_value( $data, 'syncId', '' ) ),
			'try'       => 0,
			'createdAt' => filemtime( $file ),
			'updatedAt' => time(),
			'expiresAt' => time() + ( DAY_IN_SECONDS * Mobo_Core_Settings::get_int( 'mobo_core_webhook_expire_days', 2, 1, 30 ) ),
			'payload'   => $data,
		);
	}

	/**
	 * Detect event name from payload.
	 *
	 * Supports:
	 * - event
	 * - type
	 * - Type
	 *
	 * Also supports old C# EventWebhook wrapper:
	 * {
	 *   "type": "ProductUpdated",
	 *   "data": "{...json string...}"
	 * }
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function detect_event( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$event = $this->get_value( $payload, 'event', '' );

		if ( '' === $event ) {
			$event = $this->get_value( $payload, 'type', '' );
		}

		if ( is_numeric( $event ) ) {
			$event = $this->map_numeric_event_type( absint( $event ) );
		}

		return sanitize_text_field( (string) $event );
	}

	/**
	 * Retire a successfully processed fallback file without risking duplicate execution.
	 *
	 * The file queue exists specifically for database/write-failure scenarios. Deleting
	 * the active file without checking the result can replay an already successful
	 * event forever on hosts with filesystem permission problems. Prefer an atomic move
	 * into a protected processed directory; if that cannot be done, persist a terminal
	 * marker in the envelope so a later pass never invokes the business side effect again.
	 *
	 * @param string $file Active queue file.
	 * @param array  $item Queue envelope.
	 * @param array  $result_data Processor result data.
	 * @return bool
	 */
	private function retire_completed_file( $file, $item, $result_data = array() ) {
		if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
			return true;
		}

		if ( $this->move_to_processed( $file ) || ! file_exists( $file ) ) {
			$this->delete_terminal_marker( $file );
			return true;
		}

		/*
		 * On some Windows/WAMP/filesystem combinations rename-over-existing and even
		 * an atomic rewrite of the active JSON can be unavailable. The sidecar is a
		 * separate durable commit record: get_queue_files() excludes any active JSON
		 * that owns this marker, so the already-applied business side effect can never
		 * execute again merely because the original queue file could not be archived.
		 */
		$marker = array(
			'terminal'    => true,
			'status'      => 'processed',
			'completedAt' => time(),
			'eventId'     => isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '',
			'event'       => isset( $item['event'] ) ? sanitize_text_field( (string) $item['event'] ) : '',
			'lastResult'  => is_array( $result_data ) ? $result_data : array(),
		);

		if ( $this->write_terminal_marker( $file, $marker ) ) {
			return true;
		}

		/* Last compatibility attempt: if replacing the active JSON is supported, mark
		 * the envelope itself terminal. Failure here is reported to the caller and the
		 * processor stops rather than pretending completion was durably recorded. */
		$item = is_array( $item ) ? $item : array();
		$item['terminal']       = true;
		$item['terminalStatus'] = 'processed';
		$item['completedAt']    = time();
		$item['updatedAt']      = time();
		if ( is_array( $result_data ) && ! empty( $result_data ) ) {
			$item['lastResult'] = $result_data;
		}

		return $this->write_item( $file, $item );
	}

	/**
	 * Return the sidecar path that durably suppresses replay of a completed file.
	 *
	 * @param string $file Active queue JSON path.
	 * @return string
	 */
	private function terminal_marker_path( $file ) {
		return (string) $file . '.terminal';
	}

	/**
	 * Whether a completed fallback file has a durable sidecar commit marker.
	 *
	 * @param string $file Active queue JSON path.
	 * @return bool
	 */
	private function has_terminal_marker( $file ) {
		$marker = $this->terminal_marker_path( $file );
		return '' !== $marker && is_file( $marker ) && is_readable( $marker );
	}

	/**
	 * Persist a terminal sidecar without mutating the last-known-good queue JSON.
	 *
	 * @param string $file Active queue JSON path.
	 * @param array  $marker Marker payload.
	 * @return bool
	 */
	private function write_terminal_marker( $file, $marker ) {
		$path = $this->terminal_marker_path( $file );
		if ( '' === $path ) {
			return false;
		}
		if ( is_file( $path ) ) {
			return true;
		}
		$json = wp_json_encode( is_array( $marker ) ? $marker : array( 'terminal' => true ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) && '' !== $json && $this->write_json_atomically( $path, $json );
	}

	/**
	 * Remove a terminal sidecar after its active JSON was archived successfully.
	 *
	 * @param string $file Active queue JSON path.
	 * @return void
	 */
	private function delete_terminal_marker( $file ) {
		$marker = $this->terminal_marker_path( $file );
		if ( is_file( $marker ) ) {
			wp_delete_file( $marker );
		}
	}

	/**
	 * Move one completed file out of the active queue.
	 *
	 * @param string $file Active queue file.
	 * @return bool
	 */
	private function move_to_processed( $file ) {
		$this->ensure_dirs();

		if ( ! file_exists( $file ) ) {
			return true;
		}

		$processed_dir = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'processed/';
		$target        = trailingslashit( $processed_dir ) . gmdate( 'Ymd-His' ) . '-processed-' . basename( $file );

		return $this->move_file( $file, $target );
	}

	/**
	 * Move file to failed directory.
	 *
	 * @param string $file File.
	 * @param string $reason Reason.
	 * @return bool
	 */
	private function move_to_failed( $file, $reason ) {
		$this->ensure_dirs();

		if ( ! file_exists( $file ) ) {
			return true;
		}

		$failed_dir = trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/';
		$target     = trailingslashit( $failed_dir ) . gmdate( 'Ymd-His' ) . '-' . sanitize_file_name( $reason ) . '-' . basename( $file );

		return $this->move_file( $file, $target );
	}

	/**
	 * Move a queue file using the WordPress filesystem abstraction.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return bool
	 */
	private function move_file( $source, $target ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			Mobo_Core_Logger::error( 'Mobo Core could not initialize WP_Filesystem for a webhook queue move.' );
			return false;
		}

		$moved = $wp_filesystem->move( $source, $target, true );
		if ( ! $moved ) {
			Mobo_Core_Logger::error( 'Mobo Core could not move a webhook queue file to its terminal directory.' );
		} else {
			$this->delete_terminal_marker( $source );
		}

		return (bool) $moved;
	}


	/**
	 * Bounded retention cleanup for terminal fallback artifacts.
	 *
	 * Active queue JSON without a terminal marker is never touched here. Processed
	 * history is short-lived because the table-backed event log is the primary audit
	 * record; failed files are kept longer for diagnostics. A terminal sidecar proves
	 * that the business side effect already committed, so its stranded active JSON may
	 * also be removed after the processed retention window.
	 *
	 * @return void
	 */
	private function maybe_cleanup_terminal_history() {
		$now      = time();
		$last_run = absint( get_option( 'mobo_core_webhook_file_retention_last_run', 0 ) );
		if ( $last_run > 0 && ( $now - $last_run ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}

		update_option( 'mobo_core_webhook_file_retention_last_run', $now, false );
		$this->ensure_dirs();

		$processed_days = Mobo_Core_Settings::get_int( 'mobo_core_webhook_processed_retention_days', 7, 1, 90 );
		$failed_days    = Mobo_Core_Settings::get_int( 'mobo_core_webhook_failed_retention_days', 30, 7, 365 );
		$processed_cut  = $now - ( $processed_days * DAY_IN_SECONDS );
		$failed_cut     = $now - ( $failed_days * DAY_IN_SECONDS );
		$deleted        = 0;
		$limit          = 100;

		$sets = array(
			array( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'processed/*.json', $processed_cut ),
			array( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/*.json', $failed_cut ),
		);

		foreach ( $sets as $set ) {
			$files = glob( $set[0] );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( $deleted >= $limit ) {
					break 2;
				}
				$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Retention cleanup is best-effort.
				if ( false !== $mtime && $mtime < $set[1] && $this->delete_file_verified( $file ) ) {
					$deleted++;
				}
			}
		}

		if ( $deleted >= $limit ) {
			return;
		}

		/* Stranded active JSON guarded by a terminal marker is already committed and
		 * cannot replay. Retire the pair after the processed retention period. */
		$markers = glob( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . '*.json.terminal' );
		if ( ! is_array( $markers ) ) {
			return;
		}
		foreach ( $markers as $marker ) {
			if ( $deleted >= $limit ) {
				break;
			}
			$mtime = @filemtime( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Retention cleanup is best-effort.
			if ( false === $mtime || $mtime >= $processed_cut ) {
				continue;
			}
			$active = substr( $marker, 0, -strlen( '.terminal' ) );
			if ( is_file( $active ) && ! $this->delete_file_verified( $active ) ) {
				continue;
			}
			if ( $this->delete_file_verified( $marker ) ) {
				$deleted++;
			}
		}
	}


	/**
	 * Delete a file and verify the filesystem postcondition.
	 *
	 * wp_delete_file() is a fire-and-forget helper on some WordPress versions, so
	 * callers must not treat its return value as authoritative.
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private function delete_file_verified( $file ) {
		$file = (string) $file;
		if ( '' === $file || ! file_exists( $file ) ) {
			return true;
		}
		wp_delete_file( $file );
		clearstatcache( true, $file );
		return ! file_exists( $file );
	}

	/**
	 * Build sortable queue filename.
	 *
	 * Filename starts with UTC microtime so files are processed in receive order
	 * when queue files are sorted by filename.
	 *
	 * @param string $event Event.
	 * @param string $id ID, usually webhook id / product id / sync id.
	 * @return string
	 */
	private function build_filename( $event, $id ) {
		$microtime = microtime( true );
		$seconds   = (int) floor( $microtime );
		$micro     = (int) round( ( $microtime - $seconds ) * 1000000 );

		$prefix = gmdate( 'Ymd-His', $seconds ) . '-' . str_pad( (string) $micro, 6, '0', STR_PAD_LEFT );

		$event = sanitize_file_name( sanitize_key( (string) $event ) );
		$id    = sanitize_file_name( sanitize_text_field( (string) $id ) );

		if ( '' === $event ) {
			$event = 'webhook';
		}

		if ( '' === $id ) {
			$id = 'no-id';
		}

		$random = wp_generate_password( 8, false, false );

		return $prefix . '--' . $event . '--' . $id . '--' . $random . '.json';
	}

	/**
	 * Ensure queue directories and protections.
	 *
	 * @return void
	 */
	private function ensure_dirs() {
		$this->protect_dir( MOBO_CORE_WEBHOOK_FILE_DIR );
		$this->protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'failed/' );
		$this->protect_dir( trailingslashit( MOBO_CORE_WEBHOOK_FILE_DIR ) . 'processed/' );
	}

	/**
	 * Protect directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Map old numeric event type if required.
	 *
	 * Adjust these numbers if old enum values differ.
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
	 * Check if array is a list-style array.
	 *
	 * Uses a PHP 7.4-compatible sequential-key check.
	 *
	 * @param mixed $array Value to inspect.
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

			++$expected;
		}

		return true;
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

	/**
	 * Convert mixed value to boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return ! empty( $value );
	}
}
