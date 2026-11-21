<?php
/**
 * Re-apply current category mapping to synced published products.
 *
 * This worker is intentionally cursor-based and bounded. It only touches
 * published parent products that were synced by Mobo Core. Variations are not
 * processed because WooCommerce category assignment belongs to parent products.
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
class Mobo_Core_Recategorize_Queue {

	const STATE_OPTION = 'mobo_core_recategorize_state';

	/** Targeted mode: only products that carry a temporary fallback category. */
	const MODE_PENDING = 'pending';

	/** @var string Current run mode (all|pending). */
	private $mode = 'all';

	/**
	 * Category sync service.
	 *
	 * @var Mobo_Core_Category_Sync
	 */
	private $category_sync;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->category_sync = new Mobo_Core_Category_Sync();
	}

	/**
	 * Start a new category reapply run.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public function start( $source = 'admin' ) {
		$control_lock = Mobo_Core_Lock::acquire( 'recategorize_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'عملیات دسته‌بندی در حال اجرا است؛ پس از پایان batch جاری دوباره تلاش کنید.' );
		}

		try {
		$state = array(
			'status'      => 'running',
			'source'      => sanitize_key( (string) $source ),
			'lastPostId'  => 0,
			'processed'   => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'total'       => $this->count_items(),
			'lastError'   => '',
			'lastMessage' => 'اعمال مجدد دسته‌بندی‌ها شروع شد.',
			'startedAt'   => time(),
			'updatedAt'   => time(),
			'completedAt' => 0,
			'failureAttempts' => array(),
			'mode'        => 'all',
		);

		if ( ! $this->persist_state_verified( $state ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'وضعیت شروع صف به‌صورت پایدار ذخیره نشد؛ هیچ اجرای جدیدی تأیید نشد.' );
		}

		return array(
			'success' => true,
			'message' => $state['lastMessage'],
			'status'  => $this->get_status(),
		);
		} finally {
			Mobo_Core_Lock::release( 'recategorize_queue_worker', $control_lock );
		}
	}

	/**
	 * Start a targeted re-apply for products that received a temporary fallback
	 * category because their Source category was not yet synced/mapped. Called
	 * after category snapshots and manual mapping saves. Never interrupts an
	 * active run (a full run already covers every product).
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function maybe_start_pending_reapply( $source = 'category-sync' ) {
		$queue = new self();
		$state = $queue->get_state();
		if ( in_array( $state['status'], array( 'running', 'waiting' ), true ) ) {
			return array( 'success' => true, 'status' => 'already-running' );
		}
		$queue->mode = self::MODE_PENDING;
		$total = $queue->count_items();
		if ( $total <= 0 ) {
			return array( 'success' => true, 'status' => 'nothing-pending' );
		}
		$control_lock = Mobo_Core_Lock::acquire( 'recategorize_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked' );
		}
		try {
			$state = array(
				'status'          => 'running',
				'source'          => sanitize_key( (string) $source ),
				'mode'            => self::MODE_PENDING,
				'lastPostId'      => 0,
				'processed'       => 0,
				'updated'         => 0,
				'skipped'         => 0,
				'failed'          => 0,
				'total'           => $total,
				'lastError'       => '',
				'lastMessage'     => sprintf( 'اعمال دسته‌بندی صحیح روی %d محصولی که دسته موقت گرفته بودند شروع شد.', $total ),
				'startedAt'       => time(),
				'updatedAt'       => time(),
				'completedAt'     => 0,
				'failureAttempts' => array(),
			);
			$ok = $queue->persist_state_verified( $state );
			return array( 'success' => $ok, 'status' => $ok ? 'started' : 'checkpoint-failed', 'total' => $total );
		} finally {
			Mobo_Core_Lock::release( 'recategorize_queue_worker', $control_lock );
		}
	}

	/**
	 * Cancel current run.
	 *
	 * @return array
	 */
	public function cancel() {
		$control_lock = Mobo_Core_Lock::acquire( 'recategorize_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'Worker دسته‌بندی هنوز batch جاری را تمام نکرده است؛ دوباره تلاش کنید.' );
		}

		try {
		$state = $this->get_state();

		if ( ! in_array( $state['status'], array( 'running', 'waiting' ), true ) ) {
			return array( 'success' => true, 'message' => 'عملیات اعمال دسته‌بندی فعال نیست.' );
		}

		$state['status']      = 'cancelled';
		$state['lastMessage'] = 'اعمال مجدد دسته‌بندی‌ها متوقف شد.';
		$state['updatedAt']   = time();
		if ( ! $this->persist_state_verified( $state ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'وضعیت لغو صف به‌صورت پایدار ذخیره نشد.' );
		}

		return array( 'success' => true, 'message' => $state['lastMessage'] );
		} finally {
			Mobo_Core_Lock::release( 'recategorize_queue_worker', $control_lock );
		}
	}

	/**
	 * Reset state.
	 *
	 * @return array
	 */
	public function reset() {
		$control_lock = Mobo_Core_Lock::acquire( 'recategorize_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'Worker دسته‌بندی هنوز batch جاری را تمام نکرده است؛ وضعیت فعلاً پاک نشد.' );
		}

		try {
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() || ! Mobo_Core_State_Store::delete( self::STATE_OPTION ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'پاک‌سازی وضعیت صف در دیتابیس تأیید نشد.' );
		}
		if ( null !== Mobo_Core_State_Store::get( self::STATE_OPTION, null ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'پاک‌سازی وضعیت صف در دیتابیس تأیید نشد.' );
		}

		return array( 'success' => true, 'message' => 'وضعیت اعمال مجدد دسته‌بندی‌ها پاک شد.' );
		} finally {
			Mobo_Core_Lock::release( 'recategorize_queue_worker', $control_lock );
		}
	}

	/**
	 * Process one bounded batch.
	 *
	 * @param int|null $limit Batch size.
	 * @return array
	 */
	public function process_batch( $limit = null, $time_budget_seconds = null ) {
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			return Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( $limit, $time_budget_seconds ) {
					return $this->process_batch_guarded( $limit, $time_budget_seconds );
				},
				'recategorize-queue'
			);
		}

		return $this->process_batch_guarded( $limit, $time_budget_seconds );
	}

	/**
	 * Execute the mutation batch inside the cache mutation scope.
	 *
	 * @param int|null $limit Batch size.
	 * @param int|null $time_budget_seconds Cooperative execution budget.
	 * @return array
	 */
	private function process_batch_guarded( $limit = null, $time_budget_seconds = null ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'recategorize-queue' ), array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => true ) );
		}

		$worker_lock = Mobo_Core_Lock::acquire( 'recategorize_queue_worker', 300 );
		if ( false === $worker_lock ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'recategorize-queue' ), array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => true ) );
			}

			return array( 'success' => true, 'status' => 'locked', 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => true );
		}

		$correlation = 0;
		try {
			$state = $this->get_state();
			$this->mode = self::MODE_PENDING === (string) $state['mode'] ? self::MODE_PENDING : 'all';
			$correlation = class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::push( 'recategorize', self::run_key( $state ) ) : 0;

		if ( 'running' !== $state['status'] ) {
			return array(
				'processed' => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => $state['status'],
			);
		}

		$limit = null === $limit
			? Mobo_Core_Settings::get_int( 'mobo_core_recategorize_batch_size', 20, 1, 200 )
			: absint( $limit );

		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$time_budget_seconds = null === $time_budget_seconds ? 0 : max( 1, min( 20, (int) $time_budget_seconds ) );
		$deadline = $time_budget_seconds > 0 ? microtime( true ) + $time_budget_seconds : 0.0;

		$ids = $this->get_next_item_ids( absint( $state['lastPostId'] ), $limit );

		if ( empty( $ids ) ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد دسته‌بندی‌ها کامل شد.';
			$state['updatedAt']   = time();
			$state['completedAt'] = time();
			if ( ! $this->persist_state_verified( $state ) ) {
				return array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true, 'status' => 'checkpoint-failed', 'checkpointFailed' => true );
			}

			return array(
				'processed' => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => 'done',
				'message'   => $state['lastMessage'],
			);
		}

		$processed = 0;
		$updated   = 0;
		$skipped   = 0;
		$failed    = 0;

		$paused_for_upgrade = false;
		$budget_exhausted   = false;
		$retry_blocked      = false;
		$checkpoint_failed  = false;
		$last_checkpoint_at = microtime( true );
		$checkpoint_every   = 5;
		$checkpoint_seconds = 2.0;

		foreach ( $ids as $post_id ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				$paused_for_upgrade = true;
				break;
			}

			if ( $deadline > 0 && microtime( true ) >= ( $deadline - 0.15 ) ) {
				$budget_exhausted = true;
				break;
			}

			$post_id = absint( $post_id );

			try {
				$result = $this->recategorize_product( $post_id );
				$reason = is_array( $result ) && isset( $result['reason'] ) ? sanitize_key( (string) $result['reason'] ) : '';
				if ( in_array( $reason, array( 'product-sync-active', 'product-lock-busy' ), true ) ) {
					$retry_blocked = true;
					$state['lastMessage'] = sprintf( 'دسته‌بندی محصول %d به دلیل همگام‌سازی هم‌زمان به اجرای بعد موکول شد.', $post_id );
					break;
				}

				$processed++;
				$state['lastPostId'] = max( absint( $state['lastPostId'] ), $post_id );
				if ( isset( $state['failureAttempts'][ (string) $post_id ] ) ) {
					unset( $state['failureAttempts'][ (string) $post_id ] );
				}

				if ( ! empty( $result['changed'] ) ) {
					$updated++;
				} elseif ( ! empty( $result['skipped'] ) ) {
					$skipped++;
				}
			} catch ( Throwable $e ) {
				$failed++;
				$state['lastError'] = 'Recategorize internal exception (' . sanitize_key( get_class( $e ) ) . ').';
				Mobo_Core_Logger::error( 'Mobo Core recategorize failed for product ' . $post_id . ': internal exception (' . sanitize_key( get_class( $e ) ) . ').' );
				if ( class_exists( 'Mobo_Core_Error_Log' ) ) {
					Mobo_Core_Error_Log::capture_exception( 'job.recategorize', $e, array( 'itemId' => $post_id ) );
				}
				$key = (string) $post_id;
				$attempts = isset( $state['failureAttempts'][ $key ] ) ? absint( $state['failureAttempts'][ $key ] ) + 1 : 1;
				$state['failureAttempts'][ $key ] = $attempts;
				$max_attempts = max( 1, min( 10, absint( apply_filters( 'mobo_core_recategorize_failure_retry_limit', 3, $post_id ) ) ) );
				if ( $attempts < $max_attempts ) {
					$retry_blocked = true;
					$state['lastMessage'] = sprintf( 'دسته‌بندی محصول %d ناموفق بود و در اجرای بعد دوباره تلاش می‌شود (%d/%d).', $post_id, $attempts, $max_attempts );
					break;
				}
				unset( $state['failureAttempts'][ $key ] );
				$processed++;
				$state['lastPostId'] = max( absint( $state['lastPostId'] ), $post_id );
			}

			/* Recategorization is idempotent; checkpoint every few objects/seconds
			 * instead of serializing the full state option after every product. */
			if ( 0 === ( $processed % $checkpoint_every ) || ( microtime( true ) - $last_checkpoint_at ) >= $checkpoint_seconds ) {
				$checkpoint = $state;
				$checkpoint['processed']   = absint( $state['processed'] ) + $processed;
				$checkpoint['updated']     = absint( $state['updated'] ) + $updated;
				$checkpoint['skipped']     = absint( $state['skipped'] ) + $skipped;
				$checkpoint['failed']      = absint( $state['failed'] ) + $failed;
				$checkpoint['updatedAt']   = time();
				$checkpoint['lastMessage'] = sprintf( 'در حال اعمال مجدد دسته‌بندی؛ آخرین محصول بررسی‌شده: %d', $post_id );
				if ( ! $this->persist_state_verified( $checkpoint ) ) {
					$checkpoint_failed = true;
					$state['lastError'] = 'Queue checkpoint could not be persisted durably.';
					break;
				}
				$last_checkpoint_at = microtime( true );
			}
		}

		$state['processed']   = absint( $state['processed'] ) + $processed;
		$state['updated']     = absint( $state['updated'] ) + $updated;
		$state['skipped']     = absint( $state['skipped'] ) + $skipped;
		$state['failed']      = absint( $state['failed'] ) + $failed;
		$state['updatedAt']   = time();
		$state['lastMessage'] = sprintf( 'در این مرحله %d محصول بررسی شد؛ %d محصول تغییر کرد، %d محصول رد شد.', $processed, $updated, $skipped );

		$remaining = $paused_for_upgrade || $budget_exhausted || $retry_blocked || $checkpoint_failed || count( $ids ) >= $limit;

		if ( ! $remaining ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد دسته‌بندی‌ها کامل شد.';
			$state['completedAt'] = time();
		}

		if ( ! $this->persist_state_verified( $state ) ) {
			$checkpoint_failed = true;
			$remaining = true;
		}

		return array(
			'processed' => $processed,
			'updated'   => $updated,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'remaining' => $remaining,
			'status'    => $checkpoint_failed ? 'checkpoint-failed' : ( $paused_for_upgrade ? 'paused-for-upgrade' : ( $budget_exhausted ? 'budget-exhausted' : $state['status'] ) ),
			'checkpointFailed' => $checkpoint_failed,
			'budgetExhausted' => $budget_exhausted,
			'retryBlocked'    => $retry_blocked,
			'state'     => $state,
		);
	
		} finally {
			if ( $correlation > 0 ) {
				Mobo_Core_Correlation::pop( $correlation );
			}
			Mobo_Core_Lock::release( 'recategorize_queue_worker', $worker_lock );
		}
	}


	/** Persist queue state in the current Connection-owned state row. */
	private function persist_state_verified( $state ) {
		$ok = is_array( $state )
			&& class_exists( 'Mobo_Core_State_Store' )
			&& Mobo_Core_State_Store::is_available()
			&& Mobo_Core_State_Store::set( self::STATE_OPTION, $state );
		if ( $ok ) {
			$this->observe_diagnostics( $state );
		}
		return $ok;
	}

	/** Diagnostics run key: one run = one start stamp + mode. */
	private static function run_key( $state ) {
		return absint( $state['startedAt'] ?? 0 ) . '-' . sanitize_key( (string) ( $state['mode'] ?? 'all' ) );
	}

	/**
	 * Mirror durable state transitions into the shared job diagnostics (watchdog,
	 * Persian status line, support bundle). Never blocks the queue.
	 *
	 * @param array $state Persisted state.
	 * @return void
	 */
	private function observe_diagnostics( $state ) {
		if ( ! class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			return;
		}
		try {
			Mobo_Core_Job_Diagnostics::observe(
				'recategorize',
				array(
					'runKey'    => self::run_key( $state ),
					'status'    => (string) $state['status'],
					'source'    => (string) ( $state['source'] ?? '' ),
					'processed' => absint( $state['processed'] ?? 0 ),
					'total'     => absint( $state['total'] ?? 0 ),
					'cursor'    => absint( $state['lastPostId'] ?? 0 ),
					'lastError' => (string) ( $state['lastError'] ?? '' ),
					'step'      => 'items',
				)
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/** Watchdog tick (cron round): bounded self-healing for a running run. */
	public function watchdog_tick( $context = 'cron' ) {
		if ( ! class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			return array( 'health' => 'idle', 'performed' => 'none' );
		}
		$state = $this->get_state();
		return Mobo_Core_Job_Diagnostics::generic_watchdog( 'recategorize', (string) $state['status'], 'recategorize_queue_worker', $context );
	}

	/** JSON-able support bundle for «کپی گزارش عیب‌یابی». No secrets. */
	public function debug_bundle() {
		$state  = $this->get_state();
		$status = $this->get_status();
		if ( ! class_exists( 'Mobo_Core_Job_Diagnostics' ) ) {
			return array( 'status' => $status );
		}
		return Mobo_Core_Job_Diagnostics::job_bundle( 'recategorize', $status, 'recategorize_queue_worker', array( 'cursor' => array( 'lastPostId' => absint( $state['lastPostId'] ), 'failureAttempts' => $state['failureAttempts'], 'mode' => (string) $state['mode'] ) ) );
	}

	/** Compact payload for the health report (jobs.recategorize). */
	public function health_payload() {
		$state = $this->get_state();
		return class_exists( 'Mobo_Core_Job_Diagnostics' )
			? Mobo_Core_Job_Diagnostics::health_payload_for( 'recategorize', (string) $state['status'], absint( $state['processed'] ), absint( $state['total'] ), 'recategorize_queue_worker' )
			: array( 'status' => (string) $state['status'] );
	}

	/**
	 * Return UI/status payload.
	 *
	 * @return array
	 */
	public function get_status() {
		$state = $this->get_state();
		$total = absint( $state['total'] );

		if ( $total <= 0 && in_array( $state['status'], array( 'idle', 'done' ), true ) ) {
			$total = $this->count_items();
		}

		$processed = absint( $state['processed'] );
		$percent   = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 2 ) ) : 0;

		return array(
			'status'         => $state['status'],
			'total'          => $total,
			'processed'      => $processed,
			'updated'        => absint( $state['updated'] ),
			'skipped'        => absint( $state['skipped'] ),
			'failed'         => absint( $state['failed'] ),
			'lastPostId'     => absint( $state['lastPostId'] ),
			'percent'        => $percent,
			'lastMessage'    => (string) $state['lastMessage'],
			'lastError'      => (string) $state['lastError'],
			'updatedAt'      => absint( $state['updatedAt'] ),
			'shouldContinue' => 'running' === $state['status'],
			'mode'           => (string) $state['mode'],
		) + ( class_exists( 'Mobo_Core_Job_Diagnostics' ) ? Mobo_Core_Job_Diagnostics::status_extras( 'recategorize', (string) $state['status'], $percent, 'recategorize_queue_worker' ) : array() );
	}

	/**
	 * Reapply categories to one published synced product.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function recategorize_product( $post_id ) {
		$post_id = absint( $post_id );
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $post_id ) ) {
			return false;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return array( 'changed' => false, 'skipped' => true, 'reason' => 'product-sync-active' );
			}

			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 120 );

			if ( false === $lock ) {
				return array( 'changed' => false, 'skipped' => true, 'reason' => 'product-lock-busy' );
			}

			try {
				return $this->recategorize_product_locked( $post_id );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->recategorize_product_locked( $post_id );
	}

	private function recategorize_product_locked( $post_id ) {
		$post_id = absint( $post_id );

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $post_id ) ) {
			return array( 'changed' => false, 'skipped' => true, 'reason' => 'excluded-url' );
		}

		if ( ! $this->is_allowed_product( $post_id ) ) {
			return array( 'changed' => false, 'skipped' => true, 'reason' => 'not-allowed' );
		}

		$category_refs = $this->get_product_category_refs( $post_id );

		if ( empty( $category_refs ) ) {
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'no-category-refs' );
			if ( self::MODE_PENDING === $this->mode ) {
				delete_post_meta( $post_id, Mobo_Core_Category_Sync::FALLBACK_PENDING_META );
			}
			return array( 'changed' => false, 'skipped' => true, 'reason' => 'no-category-refs' );
		}

		$before = $this->get_product_term_ids( $post_id );

		if ( self::MODE_PENDING === $this->mode ) {
			/*
			 * Targeted fallback re-apply: the product got a temporary category only
			 * because its Source category was not ready. Use the merchant's real
			 * policy (automatic sync on/off, manual mapping, mapping required) so the
			 * correct category replaces the temporary one. assign_product_categories()
			 * clears the pending marker once nothing remains unresolved.
			 */
			$policy = Mobo_Core_Category_Assignment_Policy::settings();
			$result = $this->category_sync->assign_product_categories(
				$post_id,
				$category_refs,
				! empty( $policy['auto_categories_enabled'] ),
				false,
				true
			);
			if ( is_array( $result ) && ! empty( $result['error'] ) ) {
				throw new RuntimeException( 'Category assignment failed for product ' . $post_id . ': ' . sanitize_text_field( (string) $result['error'] ) );
			}
			$after = $this->get_product_term_ids( $post_id );
			update_post_meta( $post_id, 'mobo_category_reapply_at', gmdate( 'c' ) );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'pending-' . ( isset( $result['source'] ) ? sanitize_key( (string) $result['source'] ) : '' ) );
			return array( 'changed' => $before !== $after, 'skipped' => $before === $after, 'result' => $result );
		}

		/*
		 * This tool is intentionally mapping-only.
		 * It must re-apply the customer's saved category mapping to existing published products,
		 * not create new WooCommerce categories, not use the default category, and not mutate
		 * local category structures just because automatic category sync is enabled.
		 */
		$result = $this->category_sync->assign_product_categories(
			$post_id,
			$category_refs,
			false,
			false
		);

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			throw new RuntimeException( 'Category assignment failed for product ' . $post_id . ': ' . sanitize_text_field( (string) $result['error'] ) );
		}

		$after   = $this->get_product_term_ids( $post_id );
		$changed = $before !== $after;

		update_post_meta( $post_id, 'mobo_category_reapply_at', gmdate( 'c' ) );
		update_post_meta( $post_id, 'mobo_category_reapply_source', isset( $result['source'] ) ? sanitize_text_field( (string) $result['source'] ) : '' );

		return array(
			'changed' => $changed,
			'skipped' => ! $changed,
			'result'  => $result,
		);
	}

	/**
	 * Check whether a product is eligible.
	 *
	 * @param int $post_id Product ID.
	 * @return bool
	 */
	private function is_allowed_product( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_non_canonical_product( $post_id ) ) {
				return false;
			}

		}

		return true;
	}

	/**
	 * Return saved category refs or recover them from latest product event.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function get_product_category_refs( $post_id ) {
		$post_id = absint( $post_id );
		$refs    = array();

		/* Since 10.33.44.12 Product Sync persists an explicit authoritative marker.
		 * This is deliberately checked before any historical fallback: [] means the
		 * source intentionally wants no categories, not that local evidence is missing. */
		$authoritative_local = '1' === (string) get_post_meta( $post_id, '_mobo_product_category_refs_authoritative', true );
		$stored_refs         = $this->decode_refs_json( get_post_meta( $post_id, 'mobo_product_category_refs_json', true ) );
		$guids               = get_post_meta( $post_id, 'mobo_product_category_guids', true );
		if ( ! empty( $stored_refs ) ) {
			$refs = array_merge( $refs, $stored_refs );
		}
		if ( is_array( $guids ) && ! empty( $guids ) ) {
			$refs = array_merge( $refs, $this->refs_from_guids( $guids ) );
		}
		$refs = $this->normalize_category_refs( $refs );
		if ( $authoritative_local ) {
			return $refs;
		}

		/* Legacy installs may have partial/non-authoritative local metadata. Inspect the
		 * newest ProductUpdated evidence, but stop even on an explicit empty list. */
		$event_known     = false;
		$event_malformed = false;
		$event_refs      = $this->recover_refs_from_latest_event( $post_id, $event_known, $event_malformed );
		if ( $event_known ) {
			$event_refs = $this->normalize_category_refs( $event_refs );
			$this->store_category_refs_meta( $post_id, $event_refs );
			return $event_refs;
		}
		if ( ! empty( $event_refs ) ) {
			$refs = array_merge( $refs, $event_refs );
		}

		/* If no event carries category state, ask the current API snapshot. A valid
		 * response with an explicit empty category list also becomes authoritative. */
		if ( empty( $refs ) ) {
			$api_known = false;
			$api_refs  = $this->recover_refs_from_api( $post_id, $api_known );
			if ( $api_known ) {
				$api_refs = $this->normalize_category_refs( $api_refs );
				$this->store_category_refs_meta( $post_id, $api_refs );
				return $api_refs;
			}
			if ( ! empty( $api_refs ) ) {
				$refs = array_merge( $refs, $api_refs );
			}
		}

		$refs = $this->normalize_category_refs( $refs );
		if ( ! empty( $refs ) ) {
			$this->store_category_refs_meta( $post_id, $refs );
		}
		return $refs;
	}


	/**
	 * Recover category refs directly from MoboCore by product GUID.
	 *
	 * This is the fallback that makes recategorize work for products synced by
	 * older plugin versions: the product already exists in WooCommerce, mapping
	 * is now configured, but no category_guid metadata is stored locally.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function recover_refs_from_api( $post_id, &$known = false ) {
		$known          = false;
		$post_id        = absint( $post_id );
		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( $post_id <= 0 || '' === $product_guid || ! class_exists( 'Mobo_Core_API_Client' ) ) {
			return array();
		}

		$api     = new Mobo_Core_API_Client();
		$sync_id = 'category-backfill-' . gmdate( 'YmdHis' );
		$result  = $api->get_product_by_guid( $product_guid, $sync_id );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, 'mobo_category_backfill_error', sanitize_text_field( $result->get_error_message() ) );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill-failed' );
			return array();
		}

		if ( ! is_array( $result ) ) {
			update_post_meta( $post_id, 'mobo_category_backfill_error', 'API returned an invalid product payload.' );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill-invalid' );
			return array();
		}

		$payload_with_state = $result;
		if ( ! $this->category_refs_field_present( $payload_with_state ) ) {
			$product_payload = $this->find_product_payload_in_event( $result, $product_guid );
			if ( is_array( $product_payload ) ) {
				$payload_with_state = $product_payload;
			}
		}
		$inspection = Mobo_Core_Payload_Field_Policy::inspect( $payload_with_state, Mobo_Core_Payload_Field_Policy::category_aliases() );
		$known      = ! empty( $inspection['present'] ) && is_array( $inspection['value'] );
		if ( ! empty( $inspection['present'] ) && ! is_array( $inspection['value'] ) ) {
			update_post_meta( $post_id, 'mobo_category_backfill_error', 'API product category desired state is present but malformed.' );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill-malformed' );
			return array();
		}

		$refs       = $known ? $inspection['value'] : array();
		$normalized = $known ? $this->normalize_category_refs( $refs ) : array();

		if ( $known ) {
			delete_post_meta( $post_id, 'mobo_category_backfill_error' );
			update_post_meta( $post_id, 'mobo_category_reapply_source', 'api-backfill' );
		}

		return $normalized;
	}

	/**
	 * Decode refs JSON.
	 *
	 * @param mixed $json JSON value.
	 * @return array
	 */
	private function decode_refs_json( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $this->normalize_category_refs( $decoded ) : array();
	}

	/**
	 * Recover category refs from latest stored ProductUpdated event.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function recover_refs_from_latest_event( $post_id, &$known = false, &$malformed = false ) {
		global $wpdb;

		$known        = false;
		$malformed    = false;
		$post_id      = absint( $post_id );
		$product_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );

		if ( '' === $product_guid || ! class_exists( 'Mobo_Core_Sync_Event_Store' ) || ! Mobo_Core_Sync_Event_Store::table_exists() ) {
			return array();
		}

		$table = Mobo_Core_Sync_Event_Store::table_name();
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 ) { return array(); }

		/*
		 * Fast path: current ProductUpdated rows carry entity_guid and are covered by
		 * an indexed event_type/entity_guid/id lookup. The old LONGTEXT LIKE search is
		 * retained only for legacy rows where entity_guid was not populated.
		 */
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload_json FROM {$table}
				WHERE connection_id = %d
					AND event_type = 'ProductUpdated'
					AND entity_guid = %s
				ORDER BY id DESC
				LIMIT 10",
				$connection_id,
				$product_guid
			)
		);

		$refs = $this->extract_category_refs_from_event_rows( $rows, $product_guid, $known, $malformed );
		if ( $known || $malformed ) {
			return $refs;
		}

		$like = '%' . $wpdb->esc_like( $product_guid ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT payload_json FROM {$table}
				WHERE connection_id = %d
					AND event_type = 'ProductUpdated'
					AND entity_guid = ''
					AND payload_json LIKE %s
				ORDER BY id DESC
				LIMIT 10",
				$connection_id,
				$like
			)
		);

		return $this->extract_category_refs_from_event_rows( $rows, $product_guid, $known, $malformed );
	}

	/**
	 * Extract normalized category refs from stored event JSON rows.
	 *
	 * @param array  $rows Stored payload JSON rows.
	 * @param string $product_guid Product GUID.
	 * @return array
	 */
	private function extract_category_refs_from_event_rows( $rows, $product_guid, &$known = false, &$malformed = false ) {
		$known = false;
		$malformed = false;
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $json ) {
			if ( ! is_string( $json ) || '' === $json ) {
				continue;
			}

			$payload = json_decode( $json, true );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$payload_with_state = $payload;
			if ( ! $this->category_refs_field_present( $payload_with_state ) ) {
				$product_payload = $this->find_product_payload_in_event( $payload, $product_guid );
				if ( is_array( $product_payload ) ) {
					$payload_with_state = $product_payload;
				}
			}
			$inspection = Mobo_Core_Payload_Field_Policy::inspect( $payload_with_state, Mobo_Core_Payload_Field_Policy::category_aliases() );
			if ( empty( $inspection['present'] ) ) {
				continue;
			}
			if ( ! is_array( $inspection['value'] ) ) {
				$malformed = true;
				return array();
			}
			$known = true;
			return $this->normalize_category_refs( $inspection['value'] );
		}

		return array();
	}


	/** Whether a payload explicitly carries category desired state, including []. */
	private function category_refs_field_present( $payload ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $payload, Mobo_Core_Payload_Field_Policy::category_aliases() );
	}


	/**
	 * Find the product object inside a ProductUpdated event payload.
	 *
	 * ProductUpdated payloads can be either a single product object or a paged
	 * envelope with data: [ product, ... ]. Older reapply code treated data as
	 * an object and therefore could not recover productCategories for already
	 * synced products.
	 *
	 * @param array  $payload Event payload.
	 * @param string $product_guid Product GUID.
	 * @return array
	 */
	private function find_product_payload_in_event( $payload, $product_guid ) {
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( $this->payload_matches_product_guid( $payload, $product_guid ) ) {
			return $payload;
		}

		$data = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( $this->payload_matches_product_guid( $data, $product_guid ) ) {
			return $data;
		}

		foreach ( $data as $item ) {
			if ( is_array( $item ) && $this->payload_matches_product_guid( $item, $product_guid ) ) {
				return $item;
			}
		}

		/* If the event contains exactly one product, use it as a safe fallback. */
		if ( 1 === count( $data ) ) {
			$only = reset( $data );
			return is_array( $only ) ? $only : array();
		}

		return array();
	}

	/**
	 * Check whether a payload belongs to a product GUID.
	 *
	 * @param array  $payload Product payload.
	 * @param string $product_guid Product GUID.
	 * @return bool
	 */
	private function payload_matches_product_guid( $payload, $product_guid ) {
		if ( ! is_array( $payload ) || '' === $product_guid ) {
			return false;
		}

		$keys = array( 'product_guid', 'productGuid', 'productId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $payload, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) && $product_guid === $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize and sanitize category refs.
	 *
	 * @param array $refs Raw refs.
	 * @return array
	 */
	private function normalize_category_refs( $refs ) {
		if ( ! is_array( $refs ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) ) {
				$guid = sanitize_text_field( (string) $ref );
				if ( $this->is_remote_guid_value( $guid ) ) {
					$normalized[] = array( 'id' => $guid );
				}
				continue;
			}

			$guid = $this->extract_category_guid( $ref );

			if ( '' === $guid ) {
				continue;
			}

			$normalized[] = array(
				'id'       => $guid,
				'title'    => sanitize_text_field( (string) $this->get_value( $ref, 'title', '' ) ),
				'url'      => sanitize_text_field( (string) $this->get_value( $ref, 'url', '' ) ),
				'parentId' => sanitize_text_field( (string) $this->get_value( $ref, 'parentId', '' ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Convert GUID list to category refs.
	 *
	 * @param array $guids GUIDs.
	 * @return array
	 */
	private function refs_from_guids( $guids ) {
		$refs = array();

		foreach ( $guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( $this->is_remote_guid_value( $guid ) ) {
				$refs[] = array( 'id' => $guid );
			}
		}

		return $refs;
	}

	/**
	 * Store category refs meta for future reapply runs.
	 *
	 * @param int   $post_id Product ID.
	 * @param array $refs Refs.
	 * @return void
	 */
	private function store_category_refs_meta( $post_id, $refs ) {
		$post_id = absint( $post_id );
		$refs    = $this->normalize_category_refs( $refs );

		if ( $post_id <= 0 ) {
			return;
		}

		$guids = array();
		foreach ( $refs as $ref ) {
			$guid = $this->extract_category_guid( $ref );
			if ( '' !== $guid ) {
				$guids[] = $guid;
			}
		}

		$json = class_exists( 'Mobo_Core_Unicode_Guard' )
			? Mobo_Core_Unicode_Guard::json_for_meta( $refs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 'category-refs' )
			: wp_json_encode( $refs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, 'mobo_product_category_refs_json', $json );
		update_post_meta( $post_id, 'mobo_product_category_guids', array_values( array_unique( $guids ) ) );
		update_post_meta( $post_id, '_mobo_product_category_refs_authoritative', '1' );
	}

	/**
	 * Get product term IDs sorted.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	private function get_product_term_ids( $post_id ) {
		$terms = wp_get_object_terms( absint( $post_id ), 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$terms = array_values( array_unique( array_filter( array_map( 'absint', $terms ) ) ) );
		sort( $terms );

		return $terms;
	}

	/**
	 * Extract category GUID from ref.
	 *
	 * @param array $ref Ref.
	 * @return string
	 */
	private function extract_category_guid( $ref ) {
		$guids = $this->collect_category_guid_candidates( $ref );

		return ! empty( $guids ) ? sanitize_text_field( (string) $guids[0] ) : '';
	}

	/**
	 * Collect category GUID candidates from all supported payload shapes.
	 *
	 * @param mixed $ref Category ref.
	 * @return array
	 */
	private function collect_category_guid_candidates( $ref ) {
	return Mobo_Core_Remote_Identity_Policy::collect_category_guid_candidates( $ref );
}

	/**
	 * Append a valid category GUID candidate.
	 *
	 * @param array $guids GUID list.
	 * @param mixed $value Raw value.
	 * @return void
	 */
	private function append_category_guid_candidate( &$guids, $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' !== $value && $this->is_remote_guid_value( $value ) ) {
			$guids[] = $value;
		}
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

	/**
	 * Count products eligible for category reapply.
	 *
	 * @return int
	 */
	private function count_items() {
		global $wpdb;

		if ( self::MODE_PENDING === $this->mode ) {
			return absint( $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} fp ON fp.post_id = p.ID AND fp.meta_key = %s AND fp.meta_value = '1' WHERE p.post_type = 'product' AND p.post_status = 'publish'",
				Mobo_Core_Category_Sync::FALLBACK_PENDING_META
			) ) );
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'products' );
			$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			if ( $connection_id <= 0 ) { return 0; }
			return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_product_id=p.ID WHERE c.connection_id=%d AND p.post_type='product' AND p.post_status='publish' AND c.product_guid<>''", $connection_id ) ) );
		}

		$table = class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'products' ) : '';
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 || '' === $table ) { return 0; }
		return absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pg ON pg.post_id = p.ID
			 INNER JOIN {$table} c ON c.connection_id = %d AND c.woo_product_id = p.ID
			 WHERE p.post_type = 'product'
			 AND p.post_status = 'publish'
			 AND pg.meta_key = 'product_guid'
			 AND pg.meta_value <> ''",
			$connection_id
		) ) );
	}

	/**
	 * Get next eligible product IDs.
	 *
	 * @param int $last_id Last ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_next_item_ids( $last_id, $limit ) {
		global $wpdb;

		$last_id = absint( $last_id );
		$limit   = max( 1, absint( $limit ) );

		if ( self::MODE_PENDING === $this->mode ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} fp ON fp.post_id = p.ID AND fp.meta_key = %s AND fp.meta_value = '1' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
				Mobo_Core_Category_Sync::FALLBACK_PENDING_META,
				$last_id,
				$limit
			) );
			return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'products' );
			$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			if ( $connection_id <= 0 ) { return array(); }
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} c ON c.woo_product_id=p.ID WHERE c.connection_id=%d AND p.post_type='product' AND p.post_status='publish' AND c.product_guid<>'' AND p.ID>%d ORDER BY p.ID ASC LIMIT %d",
				$connection_id, $last_id, $limit
			);
		} else {
			$table = class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'products' ) : '';
			$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
			if ( $connection_id <= 0 || '' === $table ) { return array(); }
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pg ON pg.post_id = p.ID
				 INNER JOIN {$table} c ON c.connection_id = %d AND c.woo_product_id = p.ID
				 WHERE p.post_type = 'product'
				 AND p.post_status = 'publish'
				 AND pg.meta_key = 'product_guid'
				 AND pg.meta_value <> ''
				 AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$connection_id,
				$last_id,
				$limit
			);
		}

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with bounded integer placeholders.

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Get normalized state.
	 *
	 * @return array
	 */
	private function get_state() {
		$state = class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available()
			? Mobo_Core_State_Store::get( self::STATE_OPTION, array() )
			: array();

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$defaults = array(
			'status'      => 'idle',
			'source'      => '',
			'lastPostId'  => 0,
			'processed'   => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'total'       => 0,
			'lastError'   => '',
			'lastMessage' => '',
			'startedAt'   => 0,
			'updatedAt'   => 0,
			'completedAt' => 0,
			'failureAttempts' => array(),
			'mode'        => 'all',
		);

		return array_merge( $defaults, $state );
	}

	/**
	 * Safe array value reader with PascalCase fallback.
	 *
	 * @param array  $array Array.
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
