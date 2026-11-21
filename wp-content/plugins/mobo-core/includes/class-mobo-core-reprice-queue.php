<?php
/**
 * Re-apply pricing policy to synced products and variations.
 *
 * This worker recalculates WooCommerce prices from the raw API prices stored
 * in post meta by product sync. It is intentionally cursor-based and bounded
 * so large stores do not time out when the pricing policy changes.
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
class Mobo_Core_Reprice_Queue {

	const STATE_OPTION = 'mobo_core_reprice_state';

	/**
	 * Price calculator.
	 *
	 * @var Mobo_Core_Price_Calculator
	 */
	private $price_calculator;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->price_calculator = new Mobo_Core_Price_Calculator( new Mobo_Core_Legacy_Rules() );
	}

	/**
	 * Fingerprint calculator-affecting global settings for one Reprice generation.
	 *
	 * @return string
	 */
	private function current_policy_fingerprint() {
		$policy = array(
			'autoCompare' => (string) Mobo_Core_Settings::get( 'global_product_auto_compare_price', '0' ),
			'type'        => (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ),
			'fixed'       => (string) Mobo_Core_Settings::get( 'global_additional_price', '0' ),
			'percentage'  => (string) Mobo_Core_Settings::get( 'global_additional_percentage', '0' ),
			'dynamic'     => (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ),
		);

		/* C75B-P4G: Currency is part of a Reprice generation, not merely a
		 * rendering preference. Read the RAW option: an unsaved default must
		 * never look equivalent to an explicit admin opt-in (including 'store').
		 * These are already-read WP/WC settings, not extra per-item SQL scans. */
		$currency_preference = class_exists( 'Mobo_Core_Money_Target_Settings' )
			? get_option( Mobo_Core_Money_Target_Settings::OPTION, null )
			: null;
		$policy['currencyPreferenceSaved'] = null !== $currency_preference;
		$policy['currencyPreference']      = is_string( $currency_preference ) ? $currency_preference : '';
		$policy['wooCurrency']             = function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency() : '';
		$policy['wooPriceDecimals']        = function_exists( 'wc_get_price_decimals' )
			? (int) wc_get_price_decimals() : -1;

		return hash( 'sha256', wp_json_encode( $policy ) );
	}

	/**
	 * Whether the stored generation still represents the current global policy.
	 * Old states without a fingerprint are treated as stale rather than resumable.
	 *
	 * @param array $state Reprice state.
	 * @return bool
	 */
	private function generation_matches_current_policy( $state ) {
		$fingerprint = isset( $state['policyFingerprint'] ) ? trim( (string) $state['policyFingerprint'] ) : '';
		return '' !== $fingerprint && hash_equals( $fingerprint, $this->current_policy_fingerprint() );
	}

	/**
	 * Start a new repricing run.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public function start( $source = 'admin' ) {
		$control_lock = Mobo_Core_Lock::acquire( 'reprice_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'عملیات قیمت‌گذاری در حال اجرا است؛ پس از پایان batch جاری دوباره تلاش کنید.' );
		}

		try {
		/* UX55: Never overwrite a generation that advanced its variation cursor
		 * while still owing a durable variable-parent recalculation. */
		$previous = $this->get_state();
		if ( ! empty( $previous['pendingParentIds'] ) ) {
			$parents_ready = $this->finalize_pending_parents( $previous );
			if ( ! $this->persist_state_verified( $previous ) ) {
				return array( 'success' => false, 'status' => 'checkpoint-failed' );
			}
			if ( ! $parents_ready ) {
				return array( 'success' => false, 'status' => 'parent-finalize-pending', 'message' => $previous['lastMessage'] );
			}
		}
		/* MOBO-4455: a failed COUNT read must not create a false zero-total generation. */
		$total = $this->count_items();
		if ( is_wp_error( $total ) ) {
			return array(
				'success' => false,
				'status'  => 'db-read-failed',
				'message' => $total->get_error_message(),
			);
		}

		$state = array(
			'status'       => 'running',
			'source'       => sanitize_key( (string) $source ),
			'lastPostId'   => 0,
			'processed'    => 0,
			'updated'      => 0,
			'failed'       => 0,
			'total'        => absint( $total ),
			'lastError'    => '',
			'lastMessage'  => 'اعمال مجدد سیاست قیمت‌گذاری شروع شد.',
			'startedAt'    => time(),
			'updatedAt'    => time(),
			'completedAt'  => 0,
			'policyType'        => (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ),
			'policyFingerprint' => $this->current_policy_fingerprint(),
			'failureAttempts'   => array(),
			'pendingParentIds' => array(),
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
			Mobo_Core_Lock::release( 'reprice_queue_worker', $control_lock );
		}
	}

	/**
	 * Cancel current repricing run.
	 *
	 * @return array
	 */
	public function cancel() {
		$control_lock = Mobo_Core_Lock::acquire( 'reprice_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'Worker قیمت‌گذاری هنوز batch جاری را تمام نکرده است؛ دوباره تلاش کنید.' );
		}

		try {
		$state = $this->get_state();

		if ( ! in_array( $state['status'], array( 'running', 'waiting' ), true ) ) {
			return array( 'success' => true, 'message' => 'عملیات قیمت‌گذاری فعال نیست.' );
		}

		$state['status']      = 'cancelled';
		$state['lastMessage'] = 'اعمال مجدد قیمت‌گذاری متوقف شد.';
		$state['updatedAt']   = time();
		if ( ! $this->persist_state_verified( $state ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'وضعیت لغو صف به‌صورت پایدار ذخیره نشد.' );
		}

		return array( 'success' => true, 'message' => $state['lastMessage'] );
		} finally {
			Mobo_Core_Lock::release( 'reprice_queue_worker', $control_lock );
		}
	}

	/**
	 * Resume a manually cancelled repricing generation from its durable cursor.
	 *
	 * This does not start a new generation: counters, total, cursor, failure
	 * attempts and startedAt remain intact. Only an explicit cancelled state is
	 * resumable; done/idle/running states are left unchanged.
	 *
	 * @return array
	 */
	public function resume() {
		$control_lock = Mobo_Core_Lock::acquire( 'reprice_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'Worker قیمت‌گذاری هنوز batch جاری را تمام نکرده است؛ دوباره تلاش کنید.' );
		}

		try {
			$state = $this->get_state();

			if ( 'cancelled' !== $state['status'] ) {
				return array( 'success' => false, 'status' => $state['status'], 'message' => 'فقط عملیات قیمت‌گذاری متوقف‌شده قابل ادامه است.' );
			}

			if ( ! $this->generation_matches_current_policy( $state ) ) {
				$state['status']      = 'invalidated';
				$state['lastError']   = '';
				$state['lastMessage'] = 'سیاست قیمت‌گذاری پس از توقف تغییر کرده است؛ ادامه checkpoint قبلی امن نیست و باید اجرای جدید از ابتدا شروع شود.';
				$state['updatedAt']   = time();
				$this->persist_state_verified( $state );
				return array( 'success' => false, 'status' => 'invalidated', 'message' => $state['lastMessage'] );
			}

			$state['status']       = 'running';
			$state['completedAt']  = 0;
			$state['updatedAt']    = time();
			$state['lastMessage']  = 'اعمال مجدد قیمت‌گذاری از آخرین checkpoint ادامه یافت.';

			if ( ! $this->persist_state_verified( $state ) ) {
				return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'وضعیت ادامه صف به‌صورت پایدار ذخیره نشد.' );
			}

			return array(
				'success' => true,
				'message' => $state['lastMessage'],
				'status'  => $this->get_status(),
			);
		} finally {
			Mobo_Core_Lock::release( 'reprice_queue_worker', $control_lock );
		}
	}

	/**
	 * Reset state.
	 *
	 * @return array
	 */
	public function reset() {
		$control_lock = Mobo_Core_Lock::acquire( 'reprice_queue_worker', 30 );
		if ( false === $control_lock ) {
			return array( 'success' => false, 'status' => 'locked', 'message' => 'Worker قیمت‌گذاری هنوز batch جاری را تمام نکرده است؛ وضعیت فعلاً پاک نشد.' );
		}

		try {
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() || ! Mobo_Core_State_Store::delete( self::STATE_OPTION ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'پاک‌سازی وضعیت صف در دیتابیس تأیید نشد.' );
		}
		if ( null !== Mobo_Core_State_Store::get( self::STATE_OPTION, null ) ) {
			return array( 'success' => false, 'status' => 'checkpoint-failed', 'message' => 'پاک‌سازی وضعیت صف در دیتابیس تأیید نشد.' );
		}

		return array( 'success' => true, 'message' => 'وضعیت اعمال مجدد قیمت‌گذاری پاک شد.' );
		} finally {
			Mobo_Core_Lock::release( 'reprice_queue_worker', $control_lock );
		}
	}

	/**
	 * Process one bounded batch.
	 *
	 * @param int|null $limit Batch size.
	 * @param int|null $time_budget_seconds Cooperative execution budget.
	 * @return array
	 */
	public function process_batch( $limit = null, $time_budget_seconds = null ) {
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			return Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( $limit, $time_budget_seconds ) {
					return $this->process_batch_guarded( $limit, $time_budget_seconds );
				},
				'reprice-queue'
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
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'reprice-queue' ), array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true ) );
		}

		$worker_lock = Mobo_Core_Lock::acquire( 'reprice_queue_worker', 300 );
		if ( false === $worker_lock ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'reprice-queue' ), array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true ) );
			}

			return array( 'success' => true, 'status' => 'locked', 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true );
		}

		try {
			$state = $this->get_state();

			/* Recover a parent pending from an earlier crash before changing generation
			 * or moving beyond lastPostId. A repeated WC parent sync is idempotent. */
			if ( ! empty( $state['pendingParentIds'] ) ) {
				$parents_ready = $this->finalize_pending_parents( $state );
				$state['updatedAt'] = time();
				if ( ! $this->persist_state_verified( $state ) ) {
					return array( 'success' => false, 'status' => 'checkpoint-failed', 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true );
				}
				if ( ! $parents_ready ) {
					return array( 'success' => false, 'status' => 'parent-finalize-pending', 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true );
				}
			}

			if ( 'running' === $state['status'] && ! $this->generation_matches_current_policy( $state ) ) {
				$state['status']      = 'invalidated';
				$state['lastError']   = '';
				$state['lastMessage'] = 'سیاست قیمت‌گذاری در طول اجرای Reprice تغییر کرد. اجرای قبلی متوقف شد؛ برای جلوگیری از قیمت‌های مخلوط، یک اجرای جدید از ابتدا شروع کنید.';
				$state['updatedAt']   = time();
				$this->persist_state_verified( $state );
				return array(
					'processed' => 0,
					'updated'   => 0,
					'failed'    => 0,
					'remaining' => false,
					'status'    => 'invalidated',
					'message'   => $state['lastMessage'],
				);
			}

		if ( 'running' !== $state['status'] ) {
			return array(
				'processed' => 0,
				'updated'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => $state['status'],
			);
		}

		$limit = null === $limit
			? Mobo_Core_Settings::get_int( 'mobo_core_reprice_batch_size', 20, 1, 200 )
			: absint( $limit );

		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$time_budget_seconds = null === $time_budget_seconds ? 0 : max( 1, min( 20, (int) $time_budget_seconds ) );
		$deadline = $time_budget_seconds > 0 ? microtime( true ) + $time_budget_seconds : 0.0;

		$ids = $this->get_next_item_ids( absint( $state['lastPostId'] ), $limit );

		/* MOBO-4455: distinguish a database read failure from a genuinely empty queue. */
		if ( is_wp_error( $ids ) ) {
			return array(
				'success'    => false,
				'status'     => 'db-read-failed',
				'processed'  => 0,
				'updated'    => 0,
				'failed'     => 0,
				'remaining'  => true,
				'errorCode'  => $ids->get_error_code(),
				'message'    => $ids->get_error_message(),
			);
		}

		if ( empty( $ids ) ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد سیاست قیمت‌گذاری کامل شد.';
			$state['updatedAt']   = time();
			$state['completedAt'] = time();
			if ( ! $this->persist_state_verified( $state ) ) {
				return array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => true, 'status' => 'checkpoint-failed', 'checkpointFailed' => true );
			}

			return array(
				'processed' => 0,
				'updated'   => 0,
				'failed'    => 0,
				'remaining' => false,
				'status'    => 'done',
				'message'   => $state['lastMessage'],
			);
		}

		$processed = 0;
		$updated   = 0;
		$failed    = 0;
		$policy_changed_mid_batch = false;

		$paused_for_upgrade = false;
		$budget_exhausted   = false;
		$retry_blocked      = false;
		$checkpoint_failed  = false;
		$last_checkpoint_at = microtime( true );
		$checkpoint_every   = 5;
		$checkpoint_seconds = 2.0;

		foreach ( $ids as $post_id ) {
			/* A settings change after this batch began must not be silently declared
			 * converged under the old policy generation. */
			if ( ! $this->generation_matches_current_policy( $state ) ) {
				$policy_changed_mid_batch = true;
				break;
			}
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
				$result = $this->reprice_object( $post_id );
				$reason = is_array( $result ) && isset( $result['reason'] ) ? sanitize_key( (string) $result['reason'] ) : '';

				if ( in_array( $reason, array( 'product-sync-active', 'product-lock-busy' ), true ) ) {
					/* A transient product lock must defer the cursor, not permanently skip this object. */
					$retry_blocked = true;
					$state['lastMessage'] = sprintf( 'قیمت‌گذاری محصول %d به دلیل همگام‌سازی هم‌زمان به اجرای بعد موکول شد.', $post_id );
					break;
				}

				$processed++;
				$state['lastPostId'] = max( absint( $state['lastPostId'] ), $post_id );
				if ( isset( $state['failureAttempts'][ (string) $post_id ] ) ) {
					unset( $state['failureAttempts'][ (string) $post_id ] );
				}

				if ( ! empty( $result['updated'] ) ) {
					$updated++;
				}

				if ( ! empty( $result['parentId'] ) ) {
					$parent_id = absint( $result['parentId'] );
					$state['pendingParentIds'][ (string) $parent_id ] = $parent_id;
				}
			} catch ( Throwable $e ) {
				$failed++;
				$state['lastError'] = 'Reprice item ' . $post_id . ' failed (' . sanitize_key( get_class( $e ) ) . ').';
				$key = (string) $post_id;
				$attempts = isset( $state['failureAttempts'][ $key ] ) ? absint( $state['failureAttempts'][ $key ] ) + 1 : 1;
				$state['failureAttempts'][ $key ] = $attempts;
				$max_attempts = max( 1, min( 10, absint( apply_filters( 'mobo_core_reprice_failure_retry_limit', 3, $post_id ) ) ) );
				if ( $attempts < $max_attempts ) {
					$retry_blocked = true;
					$state['lastMessage'] = sprintf( 'قیمت‌گذاری محصول %d ناموفق بود و در اجرای بعد دوباره تلاش می‌شود (%d/%d).', $post_id, $attempts, $max_attempts );
					break;
				}

				/* Persistent failures are surfaced but cannot block the entire store forever. */
				unset( $state['failureAttempts'][ $key ] );
				$processed++;
				$state['lastPostId'] = max( absint( $state['lastPostId'] ), $post_id );
			}

			/*
			 * Durable cursor checkpoints are bounded rather than written after every
			 * object. Repricing is idempotent, so after an abrupt crash at most four
			 * already-attempted rows are replayed, while large batches avoid dozens of
			 * serialized wp_options writes.
			 */
			if ( 0 === ( $processed % $checkpoint_every ) || ( microtime( true ) - $last_checkpoint_at ) >= $checkpoint_seconds ) {
				$checkpoint = $state;
				$checkpoint['processed']   = absint( $state['processed'] ) + $processed;
				$checkpoint['updated']     = absint( $state['updated'] ) + $updated;
				$checkpoint['failed']      = absint( $state['failed'] ) + $failed;
				$checkpoint['updatedAt']   = time();
				$checkpoint['lastMessage'] = sprintf( 'در حال اعمال مجدد قیمت؛ آخرین شناسه بررسی‌شده: %d', $post_id );
				if ( ! $this->persist_state_verified( $checkpoint ) ) {
					$checkpoint_failed = true;
					$state['lastError'] = 'Queue checkpoint could not be persisted durably.';
					break;
				}
				$last_checkpoint_at = microtime( true );
			}
		}

		/* Parent IDs are persisted with the same checkpoint as lastPostId. A crash
		 * after the cursor save can no longer lose an outstanding derived-price sync. */
		$parents_ready = $this->finalize_pending_parents( $state );
		if ( ! $parents_ready ) {
			$retry_blocked = true;
		}
		if ( ! $this->generation_matches_current_policy( $state ) ) {
			$policy_changed_mid_batch = true;
		}

		$state['processed']   = absint( $state['processed'] ) + $processed;
		$state['updated']     = absint( $state['updated'] ) + $updated;
		$state['failed']      = absint( $state['failed'] ) + $failed;
		$state['updatedAt']   = time();
		if ( $parents_ready ) {
			$state['lastMessage'] = sprintf( 'در این مرحله %d مورد بررسی شد؛ %d مورد به‌روزرسانی شد.', $processed, $updated );
		}

		$remaining = $paused_for_upgrade || $budget_exhausted || $retry_blocked || $checkpoint_failed || count( $ids ) >= $limit || ! empty( $state['pendingParentIds'] );

		if ( $policy_changed_mid_batch && ! $checkpoint_failed && empty( $state['pendingParentIds'] ) ) {
			$state['status'] = 'invalidated';
			$state['lastMessage'] = 'The Reprice policy changed during this batch; begin a new generation.';
			$state['completedAt'] = 0;
			$remaining = false;
		} elseif ( ! $remaining ) {
			$state['status']      = 'done';
			$state['lastMessage'] = 'اعمال مجدد سیاست قیمت‌گذاری کامل شد.';
			$state['completedAt'] = time();
		}

		if ( ! $this->persist_state_verified( $state ) ) {
			$checkpoint_failed = true;
			$remaining = true;
		}

		return array(
			'processed' => $processed,
			'updated'   => $updated,
			'failed'    => $failed,
			'remaining' => $remaining,
			'status'    => $checkpoint_failed ? 'checkpoint-failed' : ( ! $parents_ready ? 'parent-finalize-pending' : ( $paused_for_upgrade ? 'paused-for-upgrade' : ( $budget_exhausted ? 'budget-exhausted' : $state['status'] ) ) ),
			'checkpointFailed' => $checkpoint_failed,
			'budgetExhausted' => $budget_exhausted,
			'retryBlocked'    => $retry_blocked,
			'state'     => $state,
		);
	
		} finally {
			Mobo_Core_Lock::release( 'reprice_queue_worker', $worker_lock );
		}
	}


	/**
	 * Flush parent recalculations recorded alongside a durable Reprice cursor.
	 * Returns false without clearing a pending parent when its lock, owner,
	 * WooCommerce sync or cache invalidation fails; retry remains bounded by
	 * the normal worker budget and cannot be skipped by a later cursor page.
	 */
	private function finalize_pending_parents( &$state ) {
		$pending = isset( $state['pendingParentIds'] ) && is_array( $state['pendingParentIds'] ) ? $state['pendingParentIds'] : array();
		foreach ( $pending as $key => $pending_id ) {
			$parent_id = absint( $pending_id );
			if ( $parent_id <= 0 || ! function_exists( 'wc_get_product' ) || ! is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
				$state['lastError'] = 'Reprice pending parent finalization is unavailable.';
				$state['lastMessage'] = 'Parent finalization remains pending; an operator must check the parent target.';
				return false;
			}
			/* Never reconcile an object re-owned by another Source. */
			$post = get_post( $parent_id );
			if ( ! $post || ! $this->is_owned_by_current_connection( $post ) ) {
				$state['lastError'] = 'Reprice parent ' . $parent_id . ' no longer belongs to this Connection.';
				$state['lastMessage'] = 'Parent owner mismatch; pending result was preserved.';
				return false;
			}
			$product_guid = $this->get_product_guid_for_lock( $parent_id );
			/* A missing GUID/lock service must not downgrade this parent to an
			 * unprotected WC sync that could race an active product/stock writer. */
			if ( '' === $product_guid || ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				$state['lastMessage'] = 'Parent lock identity is unavailable; durable finalization remains pending.';
				return false;
			}
			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				$state['lastMessage'] = 'Parent finalization deferred for an active product sync.';
				return false;
			}
			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 120 );
			if ( false === $lock ) {
				$state['lastMessage'] = 'Parent finalization awaits the shared product lock.';
				return false;
			}
			try {
				WC_Product_Variable::sync( $parent_id );
				wc_delete_product_transients( $parent_id );
				unset( $state['pendingParentIds'][ $key ] );
			} catch ( Throwable $e ) {
				$state['lastError'] = 'Parent ' . $parent_id . ' finalization failed (' . sanitize_key( get_class( $e ) ) . ').';
				$state['lastMessage'] = 'A durable parent finalization remains pending.';
				Mobo_Core_Logger::error( 'Mobo Core reprice parent finalization failed for product ' . $parent_id . ' (' . sanitize_key( get_class( $e ) ) . ').' );
				return false;
			} finally {
				if ( false !== $lock ) {
					Mobo_Core_Product_Concurrency::release_product_lock( $lock );
				}
			}
		}
		return true;
	}

	/** Persist queue state in the current Connection-owned state row. */
	private function persist_state_verified( $state ) {
		return is_array( $state )
			&& class_exists( 'Mobo_Core_State_Store' )
			&& Mobo_Core_State_Store::is_available()
			&& Mobo_Core_State_Store::set( self::STATE_OPTION, $state );
	}

	/**
	 * Return UI/status payload.
	 *
	 * @return array
	 */
	public function get_status() {
		$state = $this->get_state();
		$total = absint( $state['total'] );
		$total_available = true;
		$total_error = '';

		if ( $total <= 0 && in_array( $state['status'], array( 'idle', 'done', 'cancelled', 'invalidated' ), true ) ) {
			$count = $this->count_items();
			if ( is_wp_error( $count ) ) {
				$total = 0;
				$total_available = false;
				$total_error = sanitize_text_field( $count->get_error_message() );
			} else {
				$total = absint( $count );
			}
		}

		$processed = absint( $state['processed'] );
		$percent   = $total_available && $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 2 ) ) : 0;
		$policy_changed = in_array( $state['status'], array( 'running', 'cancelled' ), true ) && ! $this->generation_matches_current_policy( $state );

		return array(
			'status'          => $policy_changed ? 'invalidated' : $state['status'],
			'total'           => $total,
			'totalAvailable'  => $total_available,
			'totalError'      => $total_error,
			'processed'       => $processed,
			'updated'         => absint( $state['updated'] ),
			'failed'          => absint( $state['failed'] ),
			'lastPostId'      => absint( $state['lastPostId'] ),
			'pendingParents'  => count( is_array( $state['pendingParentIds'] ) ? $state['pendingParentIds'] : array() ),
			'percent'         => $percent,
			'lastMessage'     => $policy_changed
				? 'سیاست قیمت‌گذاری تغییر کرده است؛ checkpoint قبلی قابل ادامه نیست و اجرای جدید باید از ابتدا شروع شود.'
				: (string) $state['lastMessage'],
			'lastError'       => (string) $state['lastError'],
			'updatedAt'       => absint( $state['updatedAt'] ),
			'policyType'      => (string) $state['policyType'],
			'policyChanged'   => $policy_changed,
			'shouldContinue'  => ! $policy_changed && 'running' === $state['status'],
		);
	}

	/**
	 * Reprice one Mobo product family while the caller holds the shared product lock.
	 *
	 * All purchasable Mobo members are preflighted before the first save. If a
	 * runtime save/postcondition fails, already-mutated members are restored from
	 * an exact pricing snapshot and the caller can safely keep the request pending.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array|WP_Error
	 */
	public function reprice_product_family_locked( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'mobo_core_family_reprice_invalid_product', 'Product is invalid for targeted repricing.' );
		}
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return new WP_Error( 'mobo_core_family_reprice_excluded', 'Excluded product cannot be repriced.' );
		}
		if ( ! class_exists( 'Mobo_Core_Product_Identity_Policy' ) || ! Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $product_id ) ) {
			return new WP_Error( 'mobo_core_family_reprice_not_mobo', 'Only a Mobo-owned product can be repriced by product override.' );
		}
		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) && Mobo_Core_Product_Concurrency::is_non_canonical_product( $product_id ) ) {
			return new WP_Error( 'mobo_core_family_reprice_non_canonical', 'Duplicate non-canonical Mobo product cannot be repriced.' );
		}

		$parent = wc_get_product( $product_id );
		if ( ! $parent instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_family_reprice_missing_product', 'WooCommerce product could not be loaded.' );
		}

		$targets = array();
		if ( $parent->is_type( 'variable' ) ) {
			$children = get_posts( array(
				'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $product_id,
				'fields' => 'ids', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
			) );
			foreach ( (array) $children as $child_id ) {
				$child_id = absint( $child_id );
				if ( $child_id <= 0 ) { continue; }
				$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $child_id ) : null;
				if ( is_wp_error( $identity ) ) {
					return new WP_Error( 'mobo_core_family_reprice_identity_conflict', 'Variation identity is ambiguous; targeted repricing was blocked for object ' . $child_id . ': ' . $identity->get_error_message() );
				}
				if ( is_array( $identity ) && ! empty( $identity['owned'] ) ) { $targets[] = $child_id; }
			}
		} else {
			$targets[] = $product_id;
		}
		$targets = array_values( array_unique( array_filter( array_map( 'absint', $targets ) ) ) );

		/* Validate the entire desired family before changing the first storefront price. */
		foreach ( $targets as $target_id ) {
			$raw_price = get_post_meta( $target_id, 'mobo_api_price', true );
			if ( '' === $raw_price || null === $raw_price ) {
				return new WP_Error( 'mobo_core_family_reprice_source_missing', 'Stored Mobo source price is missing for object ' . $target_id . '.' );
			}
			$product = wc_get_product( $target_id );
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error( 'mobo_core_family_reprice_member_missing', 'WooCommerce object could not be loaded for targeted repricing: ' . $target_id . '.' );
			}
			$pair = $this->price_calculator->calculate_price_pair(
				$target_id,
				$raw_price,
				get_post_meta( $target_id, 'mobo_api_compare_price', true ),
				$product->is_type( 'variation' ) ? 'variation' : 'product',
				$product->is_type( 'variation' ) ? $product_id : 0
			);
			if ( ! empty( $pair['error'] ) ) {
				return new WP_Error( 'mobo_core_family_reprice_source_invalid', 'Stored Mobo source price is invalid for object ' . $target_id . ': ' . sanitize_text_field( (string) $pair['error'] ) );
			}
		}

		$snapshots = array();
		foreach ( $targets as $target_id ) {
			$snapshot = $this->snapshot_price_state( $target_id );
			if ( is_wp_error( $snapshot ) ) { return $snapshot; }
			$snapshots[ $target_id ] = $snapshot;
		}

		$updated = 0;
		try {
			foreach ( $targets as $target_id ) {
				$result = $this->reprice_object_locked( $target_id );
				if ( ! empty( $result['updated'] ) ) { $updated++; }
			}
			if ( $parent->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $product_id );
				wc_delete_product_transients( $product_id );
			}
		} catch ( Throwable $e ) {
			$rollback_errors = array();
			foreach ( array_reverse( $snapshots, true ) as $target_id => $snapshot ) {
				$restored = $this->restore_price_state( $target_id, $snapshot );
				if ( is_wp_error( $restored ) ) { $rollback_errors[] = $restored->get_error_message(); }
			}
			if ( $parent->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
				try { WC_Product_Variable::sync( $product_id ); wc_delete_product_transients( $product_id ); } catch ( Throwable $rollback_parent_error ) { $rollback_errors[] = $rollback_parent_error->getMessage(); }
			}
			$message = 'Targeted product repricing failed and was rolled back (internal exception: ' . sanitize_key( get_class( $e ) ) . ').';
			if ( ! empty( $rollback_errors ) ) { $message .= ' Rollback warning: ' . implode( ' | ', array_map( 'sanitize_text_field', $rollback_errors ) ); }
			return new WP_Error( 'mobo_core_family_reprice_failed', $message );
		}

		return array( 'success' => true, 'status' => 'applied', 'processed' => count( $targets ), 'updated' => $updated );
	}

	private function snapshot_price_state( $post_id ) {
		$product = wc_get_product( absint( $post_id ) );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_price_snapshot_missing', 'Cannot snapshot missing WooCommerce object.' );
		}
		$meta = array();
		foreach ( array( 'mobo_calculated_regular_price', 'mobo_calculated_sale_price', 'mobo_price_policy_type', 'mobo_price_policy_updated_at' ) as $key ) {
			$meta[ $key ] = get_post_meta( $post_id, $key, false );
		}
		return array(
			'regular' => (string) $product->get_regular_price( 'edit' ),
			'sale' => (string) $product->get_sale_price( 'edit' ),
			'price' => (string) $product->get_price( 'edit' ),
			'meta' => $meta,
		);
	}

	private function restore_price_state( $post_id, $snapshot ) {
		$product = wc_get_product( absint( $post_id ) );
		if ( ! $product instanceof WC_Product || ! is_array( $snapshot ) ) {
			return new WP_Error( 'mobo_core_price_rollback_missing', 'Cannot restore missing WooCommerce pricing snapshot.' );
		}
		$product->set_regular_price( isset( $snapshot['regular'] ) ? $snapshot['regular'] : '' );
		$product->set_sale_price( isset( $snapshot['sale'] ) ? $snapshot['sale'] : '' );
		$product->set_price( isset( $snapshot['price'] ) ? $snapshot['price'] : '' );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== absint( $post_id ) ) {
			return new WP_Error( 'mobo_core_price_rollback_save_failed', 'WooCommerce pricing rollback save failed for object ' . absint( $post_id ) . '.' );
		}
		foreach ( isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : array() as $key => $values ) {
			delete_post_meta( $post_id, $key );
			foreach ( (array) $values as $value ) { add_post_meta( $post_id, $key, $value, false ); }
		}
		$fresh = wc_get_product( absint( $post_id ) );
		if ( ! $fresh instanceof WC_Product
			|| (string) $fresh->get_regular_price( 'edit' ) !== (string) ( isset( $snapshot['regular'] ) ? $snapshot['regular'] : '' )
			|| (string) $fresh->get_sale_price( 'edit' ) !== (string) ( isset( $snapshot['sale'] ) ? $snapshot['sale'] : '' ) ) {
			return new WP_Error( 'mobo_core_price_rollback_postcondition_failed', 'WooCommerce pricing rollback postcondition failed for object ' . absint( $post_id ) . '.' );
		}
		wc_delete_product_transients( absint( $post_id ) );
		return true;
	}

	/**
	 * Reprice one product or variation from saved API price meta.
	 *
	 * @param int $post_id Product/variation ID.
	 * @return array
	 */
	private function reprice_object( $post_id ) {
		$post_id = absint( $post_id );

		/*
		 * Variable parents are not writable repricing targets. Detect this before
		 * product-level sync/lock checks so a busy parent cannot pin the global
		 * cursor at the same ID forever. Product sync owns any concurrent type
		 * transition and applies the current pricing policy to the resulting
		 * purchasable object; this queue only needs to process the variations and
		 * later synchronize their derived parent price.
		 */
		$preflight_product = wc_get_product( $post_id );
		if ( $preflight_product instanceof WC_Product && $preflight_product->is_type( 'variable' ) ) {
			return array(
				'updated' => false,
				'skipped' => true,
				'reason'  => 'variable-parent-derived',
			);
		}

		$product_guid = $this->get_product_guid_for_lock( $post_id );
		$parent_id = $this->get_parent_product_id_for_lock( $post_id );

		if ( $parent_id > 0 && class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) {
			return array( 'updated' => false, 'skipped' => true, 'reason' => 'excluded-url' );
		}

		if ( $parent_id > 0 && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_non_canonical_product( $parent_id ) ) {
				return array( 'updated' => false, 'skipped' => true, 'reason' => 'duplicate-non-canonical' );
			}
		}

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return array( 'updated' => false, 'skipped' => true, 'reason' => 'product-sync-active' );
			}

			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 120 );

			if ( false === $lock ) {
				return array( 'updated' => false, 'skipped' => true, 'reason' => 'product-lock-busy' );
			}

			try {
				return $this->reprice_object_locked( $post_id );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->reprice_object_locked( $post_id );
	}

	private function reprice_object_locked( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $this->is_reprice_allowed_post( $post_id ) ) {
			return array( 'updated' => false, 'skipped' => true );
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof WC_Product ) {
			return array( 'updated' => false );
		}

		/*
		 * Variable-parent storefront prices are derived from their variations by
		 * WooCommerce. Writing regular/sale price directly to the parent creates a
		 * false postcondition failure because WC_Product_Variable may normalize the
		 * parent back to its child-derived state during save/read-back. The queue
		 * processes Mobo-owned variations separately and synchronizes each changed
		 * parent after the batch, so the parent row itself is intentionally a no-op.
		 */
		if ( $product->is_type( 'variable' ) ) {
			return array(
				'updated' => false,
				'skipped' => true,
				'reason'  => 'variable-parent-derived',
			);
		}

		$raw_price = get_post_meta( $post_id, 'mobo_api_price', true );

		if ( '' === $raw_price || null === $raw_price ) {
			return array( 'updated' => false );
		}

		$raw_compare_price = get_post_meta( $post_id, 'mobo_api_compare_price', true );
		$context = $product->is_type( 'variation' ) ? 'variation' : 'product';

		$pair = $this->price_calculator->calculate_price_pair(
			$post_id,
			$raw_price,
			$raw_compare_price,
			$context,
			$product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : 0
		);

		if ( ! empty( $pair['error'] ) ) {
			throw new RuntimeException( 'Stored Mobo source price is invalid for object ' . $post_id . ': ' . sanitize_text_field( (string) $pair['error'] ) );
		}

		$frontend_changed = false;
		$desired_regular  = null !== $pair['regular_price'] && '' !== $pair['regular_price'] ? wc_format_decimal( $pair['regular_price'] ) : null;
		$desired_sale     = isset( $pair['sale_price'] ) ? wc_format_decimal( $pair['sale_price'] ) : '';
		$desired_active   = '' !== $desired_sale ? $desired_sale : $desired_regular;

		if ( null !== $desired_regular && wc_format_decimal( $product->get_regular_price( 'edit' ) ) !== $desired_regular ) {
			$product->set_regular_price( $pair['regular_price'] );
			$frontend_changed = true;
		}

		if ( wc_format_decimal( $product->get_sale_price( 'edit' ) ) !== $desired_sale ) {
			$product->set_sale_price( isset( $pair['sale_price'] ) ? $pair['sale_price'] : '' );
			$frontend_changed = true;
		}

		/*
		 * MOBO-4457 r2: durable active WooCommerce price repair.
		 *
		 * WC_Product_Variation derives get_price( 'edit' ) from regular/sale and
		 * therefore cannot reveal a stale durable _price row. Simple products can
		 * also keep a stale _price after set_price() + save() when regular/sale did
		 * not change. Use the durable meta value as the crash-replay truth.
		 */
		$stored_active = get_post_meta( $post_id, '_price', true );
		$stored_active = '' !== $stored_active && null !== $stored_active ? wc_format_decimal( $stored_active ) : '';

		if ( null !== $desired_active && $stored_active !== $desired_active ) {
			$product->set_price( $desired_active );
			$frontend_changed = true;
		}

		$policy_type = isset( $pair['policy_type'] ) && '' !== (string) $pair['policy_type'] ? sanitize_key( (string) $pair['policy_type'] ) : (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		$meta_values = array(
			'mobo_calculated_regular_price' => null !== $desired_regular ? $pair['regular_price'] : '',
			'mobo_calculated_sale_price'    => isset( $pair['sale_price'] ) ? $pair['sale_price'] : '',
			'mobo_price_policy_type'         => $policy_type,
		);

		$meta_changed = false;

		if ( $frontend_changed ) {
			foreach ( $meta_values as $key => $value ) {
				$current = $product->get_meta( $key, true, 'edit' );
				if ( $current != $value ) { // Numeric metadata is stored as strings by WordPress.
					$product->update_meta_data( $key, $value );
					$meta_changed = true;
				}
			}
			if ( $meta_changed ) {
				$product->update_meta_data( 'mobo_price_policy_updated_at', gmdate( 'c' ) );
			}

			$saved_id = absint( $product->save() );
			if ( $saved_id !== $post_id ) {
				throw new RuntimeException( 'WooCommerce price save could not be verified for object ' . $post_id . '.' );
			}

			/*
			 * WooCommerce 10.8.x does not persist the price prop to _price when
			 * price is the only changed price prop. This is the same condition
			 * handled explicitly by wc_apply_sale_state_for_product().
			 */
			$stored_active_after_save = get_post_meta( $post_id, '_price', true );
			$stored_active_after_save = '' !== $stored_active_after_save && null !== $stored_active_after_save ? wc_format_decimal( $stored_active_after_save ) : '';

			if ( null !== $desired_active && $stored_active_after_save !== $desired_active ) {
				$active_written = update_post_meta( $post_id, '_price', $desired_active );
				$stored_active_after_write = get_post_meta( $post_id, '_price', true );
				$stored_active_after_write = '' !== $stored_active_after_write && null !== $stored_active_after_write ? wc_format_decimal( $stored_active_after_write ) : '';

				if ( false === $active_written && $stored_active_after_write !== $desired_active ) {
					throw new RuntimeException( 'WooCommerce active price meta could not be persisted for object ' . $post_id . '.' );
				}
				if ( $stored_active_after_write !== $desired_active ) {
					throw new RuntimeException( 'WooCommerce active price meta postcondition failed for object ' . $post_id . '.' );
				}

				$data_store = WC_Data_Store::load( 'product' );
				if ( $data_store->has_callable( 'refresh_product_lookup_table' ) ) {
					$data_store->refresh_product_lookup_table( $post_id );
				}
			}

			/*
			 * Existing duplicate WooCommerce price rows can make get_post_meta(..., true)
			 * and WC_Product disagree even after a successful save. While this worker
			 * holds the shared product lock, collapse only duplicate rows to the
			 * authoritative calculator result before evaluating the durable postcondition.
			 */
			if ( class_exists( 'Mobo_Core_Price_Meta_Integrity' ) ) {
				$price_meta_desired = array(
					'_price'      => null !== $desired_active ? $desired_active : '',
					'_sale_price' => $desired_sale,
				);
				if ( null !== $desired_regular ) {
					$price_meta_desired['_regular_price'] = $desired_regular;
				}
				$collapsed = Mobo_Core_Price_Meta_Integrity::collapse_duplicates_verified( $post_id, $price_meta_desired );
				if ( is_wp_error( $collapsed ) ) {
					throw new RuntimeException( $collapsed->get_error_message() );
				}
				if ( ! empty( $collapsed['changed'] ) ) {
					clean_post_cache( $post_id );
				}
			}

			$fresh_product = wc_get_product( $post_id );
			$durable_active = get_post_meta( $post_id, '_price', true );
			$durable_active = '' !== $durable_active && null !== $durable_active ? wc_format_decimal( $durable_active ) : '';

			if ( ! $fresh_product instanceof WC_Product
				|| ( null !== $desired_regular && wc_format_decimal( $fresh_product->get_regular_price( 'edit' ) ) !== $desired_regular )
				|| wc_format_decimal( $fresh_product->get_sale_price( 'edit' ) ) !== $desired_sale
				|| ( null !== $desired_active && $durable_active !== $desired_active )
			) {
				throw new RuntimeException( 'WooCommerce price postcondition failed for object ' . $post_id . '.' );
			}
			wc_delete_product_transients( $post_id );
		} else {
			/*
			 * The rendered price is already correct. Refresh Mobo bookkeeping directly
			 * without firing WooCommerce product-save hooks or lookup/transient churn.
			 */
			foreach ( $meta_values as $key => $value ) {
				$current = get_post_meta( $post_id, $key, true );
				if ( $current != $value ) {
					$written = update_post_meta( $post_id, $key, $value );
					$stored  = get_post_meta( $post_id, $key, true );
					if ( false === $written && $stored != $value ) {
						throw new RuntimeException( 'Price bookkeeping meta could not be persisted for object ' . $post_id . ' (' . sanitize_key( $key ) . ').' );
					}
					if ( $stored != $value ) {
						throw new RuntimeException( 'Price bookkeeping meta postcondition failed for object ' . $post_id . ' (' . sanitize_key( $key ) . ').' );
					}
					$meta_changed = true;
				}
			}
			if ( $meta_changed ) {
				update_post_meta( $post_id, 'mobo_price_policy_updated_at', gmdate( 'c' ) );
			}
		}

		return array(
			'updated'  => $frontend_changed || $meta_changed,
			'changed'  => $frontend_changed,
			/*
			 * Parent `_price` is a child-derived multi-row set in WooCommerce.
			 * Request a bounded parent sync for every successfully processed
			 * variation, not only changed variations, so stale historical parent
			 * state converges even when the child itself is already correct.
			 */
			'parentId' => $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : 0,
		);
	}


	/**
	 * Return parent product ID used for product-level concurrency checks.
	 *
	 * @param int $post_id Product or variation ID.
	 * @return int
	 */
	private function get_parent_product_id_for_lock( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		if ( 'product' === $post->post_type ) {
			return $post_id;
		}

		if ( 'product_variation' === $post->post_type ) {
			return absint( $post->post_parent );
		}

		return 0;
	}

	/**
	 * Return product_guid for product-level lock.
	 *
	 * @param int $post_id Product or variation ID.
	 * @return string
	 */
	private function get_product_guid_for_lock( $post_id ) {
		$parent_id = $this->get_parent_product_id_for_lock( $post_id );

		if ( $parent_id <= 0 ) {
			return '';
		}

		return sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
	}

	/**
	 * Check whether a product/variation is allowed to be repriced.
	 *
	 * Reprice is intentionally limited to published synced objects. A variation
	 * is only eligible when both the variation and its parent product are
	 * published. Draft/private/pending/trash objects are left untouched.
	 *
	 * @param int $post_id Product or variation post ID.
	 * @return bool
	 */
	private function is_reprice_allowed_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! $this->is_owned_by_current_connection( $post ) ) {
			return false;
		}

		if ( 'product' === $post->post_type ) {
			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $post_id ) ) {
				return false;
			}

			if ( class_exists( 'Mobo_Core_Product_Concurrency' ) && Mobo_Core_Product_Concurrency::is_non_canonical_product( $post_id ) ) {
				return false;
			}

			return true;
		}

		if ( 'product_variation' !== $post->post_type ) {
			return false;
		}

		$parent_id = absint( $post->post_parent );

		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = get_post( $parent_id );

		if ( ! ( $parent instanceof WP_Post ) || 'product' !== $parent->post_type || 'publish' !== $parent->post_status ) {
			return false;
		}

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) {
			return false;
		}

		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) && Mobo_Core_Product_Concurrency::is_non_canonical_product( $parent_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Prove that a Woo product/variation belongs to the active Connection.
	 *
	 * Woo post IDs are installation-global, but remote identities are not. A
	 * cursor worker must therefore never accept a post merely because it carries
	 * historical Mobo meta from another Source.
	 *
	 * @param WP_Post $post Product or variation post.
	 * @return bool
	 */
	private function is_owned_by_current_connection( $post ) {
		if ( ! ( $post instanceof WP_Post ) || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		if ( 'product' === $post->post_type && class_exists( 'Mobo_Core_Product_State_Repository' ) ) {
			return is_array( ( new Mobo_Core_Product_State_Repository() )->get_by_woo_product_id( $post->ID ) );
		}
		if ( 'product_variation' === $post->post_type && class_exists( 'Mobo_Core_Variant_State_Repository' ) ) {
			return is_array( ( new Mobo_Core_Variant_State_Repository() )->get_by_woo_variation_id( $post->ID ) );
		}

		return false;
	}

	/**
	 * Count repricable objects.
	 *
	 * @return int|WP_Error
	 */
	private function count_items() {
		global $wpdb;

		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return new WP_Error( 'mobo_core_reprice_owner_unavailable', 'Unable to prove the active Connection ownership for the Reprice queue.' );
		}

		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		if ( '' === $products || '' === $variants ) {
			return new WP_Error( 'mobo_core_reprice_owner_unavailable', 'Connection-owned product storage is unavailable for the Reprice queue.' );
		}

		/* PERF08: ownership is already unique in the custom product/variant tables.
		 * Count those direct owner rows and use EXISTS for price metadata so duplicate
		 * meta rows cannot multiply candidates and force COUNT(DISTINCT) over a wide
		 * posts/meta/owner join. Products and variations have globally unique post IDs. */
		if ( class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) && Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative() ) {
			$entity_meta = Mobo_Core_Storage_Entity_Meta_Retirement::table_name();
			$sql = $wpdb->prepare(
				"SELECT
					(SELECT COUNT(*)
					 FROM {$products} mp
					 INNER JOIN {$wpdb->posts} p ON p.ID=mp.woo_product_id AND p.post_type='product' AND p.post_status='publish'
					 WHERE mp.connection_id=%d
					 AND EXISTS (SELECT 1 FROM {$entity_meta} em WHERE em.woo_object_id=p.ID AND em.meta_key='mobo_api_price' AND COALESCE(em.meta_value,'')<>'' LIMIT 1))
					+
					(SELECT COUNT(*)
					 FROM {$variants} mv
					 INNER JOIN {$wpdb->posts} p ON p.ID=mv.woo_variation_id AND p.post_type='product_variation' AND p.post_status='publish'
					 INNER JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent AND parent.post_type='product' AND parent.post_status='publish'
					 WHERE mv.connection_id=%d
					 AND EXISTS (SELECT 1 FROM {$entity_meta} em WHERE em.woo_object_id=p.ID AND em.meta_key='mobo_api_price' AND COALESCE(em.meta_value,'')<>'' LIMIT 1)) AS total",
				$connection_id,
				$connection_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT
					(SELECT COUNT(*)
					 FROM {$products} mp
					 INNER JOIN {$wpdb->posts} p ON p.ID=mp.woo_product_id AND p.post_type='product' AND p.post_status='publish'
					 WHERE mp.connection_id=%d
					 AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='mobo_api_price' AND pm.meta_value<>'' LIMIT 1))
					+
					(SELECT COUNT(*)
					 FROM {$variants} mv
					 INNER JOIN {$wpdb->posts} p ON p.ID=mv.woo_variation_id AND p.post_type='product_variation' AND p.post_status='publish'
					 INNER JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent AND parent.post_type='product' AND parent.post_status='publish'
					 WHERE mv.connection_id=%d
					 AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='mobo_api_price' AND pm.meta_value<>'' LIMIT 1)) AS total",
				$connection_id,
				$connection_id
			);
		}

		$count = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above with Connection ownership placeholders.
		if ( '' !== trim( (string) $wpdb->last_error ) || null === $count ) {
			return new WP_Error(
				'mobo_core_reprice_count_read_failed',
				'Unable to read the Reprice queue total from the database; the existing queue state was not replaced.'
			);
		}

		return absint( $count );
	}

	/**
	 * Get next IDs using stable ID cursor.
	 *
	 * @param int $last_id Last post ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_next_item_ids( $last_id, $limit ) {
		global $wpdb;

		$last_id = absint( $last_id );
		$limit   = max( 1, absint( $limit ) );
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Storage_Schema' ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return new WP_Error( 'mobo_core_reprice_owner_unavailable', 'Unable to prove the active Connection ownership for the Reprice queue.' );
		}

		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		if ( '' === $products || '' === $variants ) {
			return new WP_Error( 'mobo_core_reprice_owner_unavailable', 'Connection-owned product storage is unavailable for the Reprice queue.' );
		}

		/* PERF08: two ownership-native branches avoid LEFT JOIN + DISTINCT row
		 * multiplication. UNION ALL is safe because WordPress post IDs are globally
		 * unique and each custom storage table has a unique Woo object ID. The outer
		 * ORDER/LIMIT preserves the exact stable post-ID cursor contract. */
		if ( class_exists( 'Mobo_Core_Storage_Entity_Meta_Retirement' ) && Mobo_Core_Storage_Entity_Meta_Retirement::is_authoritative() ) {
			$entity_meta = Mobo_Core_Storage_Entity_Meta_Retirement::table_name();
			$sql = $wpdb->prepare(
				"(SELECT p.ID AS ID
				  FROM {$products} mp
				  INNER JOIN {$wpdb->posts} p ON p.ID=mp.woo_product_id AND p.post_type='product' AND p.post_status='publish'
				  WHERE mp.connection_id=%d AND p.ID>%d
				  AND EXISTS (SELECT 1 FROM {$entity_meta} em WHERE em.woo_object_id=p.ID AND em.meta_key='mobo_api_price' AND COALESCE(em.meta_value,'')<>'' LIMIT 1))
				 UNION ALL
				 (SELECT p.ID AS ID
				  FROM {$variants} mv
				  INNER JOIN {$wpdb->posts} p ON p.ID=mv.woo_variation_id AND p.post_type='product_variation' AND p.post_status='publish'
				  INNER JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent AND parent.post_type='product' AND parent.post_status='publish'
				  WHERE mv.connection_id=%d AND p.ID>%d
				  AND EXISTS (SELECT 1 FROM {$entity_meta} em WHERE em.woo_object_id=p.ID AND em.meta_key='mobo_api_price' AND COALESCE(em.meta_value,'')<>'' LIMIT 1))
				 ORDER BY ID ASC LIMIT %d",
				$connection_id,
				$last_id,
				$connection_id,
				$last_id,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"(SELECT p.ID AS ID
				  FROM {$products} mp
				  INNER JOIN {$wpdb->posts} p ON p.ID=mp.woo_product_id AND p.post_type='product' AND p.post_status='publish'
				  WHERE mp.connection_id=%d AND p.ID>%d
				  AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='mobo_api_price' AND pm.meta_value<>'' LIMIT 1))
				 UNION ALL
				 (SELECT p.ID AS ID
				  FROM {$variants} mv
				  INNER JOIN {$wpdb->posts} p ON p.ID=mv.woo_variation_id AND p.post_type='product_variation' AND p.post_status='publish'
				  INNER JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent AND parent.post_type='product' AND parent.post_status='publish'
				  WHERE mv.connection_id=%d AND p.ID>%d
				  AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='mobo_api_price' AND pm.meta_value<>'' LIMIT 1))
				 ORDER BY ID ASC LIMIT %d",
				$connection_id,
				$last_id,
				$connection_id,
				$last_id,
				$limit
			);
		}

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with bounded placeholders.
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $ids ) ) {
			return new WP_Error(
				'mobo_core_reprice_candidate_read_failed',
				'Unable to read the next Reprice candidates from the database; cursor and counters were preserved for retry.'
			);
		}

		return array_map( 'absint', $ids );
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
			'failed'      => 0,
			'total'       => 0,
			'lastError'   => '',
			'lastMessage' => '',
			'startedAt'   => 0,
			'updatedAt'   => 0,
			'completedAt' => 0,
			'policyType'        => '',
			'policyFingerprint' => '',
			'failureAttempts'   => array(),
			'pendingParentIds' => array(),
		);

		return array_merge( $defaults, $state );
	}
}
