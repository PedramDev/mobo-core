<?php
/**
 * Product and variation sync.
 *
 * Clean v2 implementation preserving legacy behavior/options/GUIDs.
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
class Mobo_Core_Product_Sync {

	const STATE_OPTION = 'mobo_core_sync_state';
	const REPAIR_COMPLETED_OPTION = 'mobo_core_repair_completed_at';
	const REPAIR_LAST_SYNC_ID_OPTION = 'mobo_core_repair_last_sync_id';
	const REPAIR_SYNC_ID_META = '_mobo_last_repair_sync_id';
	const REPAIR_SYNCED_AT_META = '_mobo_last_repair_synced_at';
	const REPAIR_APPLIED_REVISION_META = '_mobo_last_repair_applied_revision';
	const REPAIR_WEBHOOK_US_META = '_mobo_last_repair_webhook_us';
	const STALE_RUNNING_RECOVERY_SECONDS = 15;

	/** Request-local checkpoint coalescing used only by the real cron runner. */
	private $checkpoint_coalescing = false;
	private $checkpoint_state = null;
	private $checkpoint_pending_steps = 0;
	private $checkpoint_last_flush_at = 0.0;
	private $checkpoint_max_steps = 3;
	private $checkpoint_max_seconds = 2.0;

	private $rules;
	private $price_calculator;
	private $image_sync;
	private $category_sync;
	private $product_map;
	private $repair_mode = false;
	private $last_parent_core_noop    = false;

	/** @var array Request-local blocked-status decisions by object type/GUID. */
	private $blocked_status_cache = array();

	/** @var array Request-local variation attribute-signature index by parent product ID. */
	private $variation_signature_index = array();

	/** @var array Request-local parent portal_product_id cache. */
	private $parent_portal_product_id_cache = array();

	public function __construct() {
		$this->rules            = new Mobo_Core_Legacy_Rules();
		$this->price_calculator = new Mobo_Core_Price_Calculator( $this->rules );
		$this->image_sync       = new Mobo_Core_Image_Sync();
		$this->category_sync    = new Mobo_Core_Category_Sync();
		$this->product_map      = class_exists( 'Mobo_Core_Product_Map' ) ? new Mobo_Core_Product_Map() : null;
	}

	/**
	 * Enable repair semantics for this Product Sync instance.
	 *
	 * At the Product Sync instance level, Repair bypasses the stored source-hash
	 * shortcut while preserving field/price/stock policy. The existing manual
	 * Repair workflow additionally runs Mobo_Core_Repair_Integrity before the
	 * authoritative Portal snapshot.
	 *
	 * @param bool $enabled Enable repair mode.
	 * @return self
	 */
	public function set_repair_mode( $enabled = true ) {
		$this->repair_mode = (bool) $enabled;
		return $this;
	}

	public function start_manual_sync( $sync_id = '', $source = 'admin', $repair_mode = false ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'شروع Sync تا پایان آپدیت افزونه متوقف است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync_start', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( false, 'درخواست دیگری هم‌زمان در حال شروع Sync/Repair است.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			return $this->start_manual_sync_unlocked( $sync_id, $source, $repair_mode );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync_start', $lock );
			}
		}
	}

	private function start_manual_sync_unlocked( $sync_id = '', $source = 'admin', $repair_mode = false ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'شروع Sync تا پایان آپدیت افزونه متوقف است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		if ( class_exists( 'Mobo_Core_Lock' ) ) {
			Mobo_Core_Lock::recover_stale_locks( array( 'manual_sync', 'manual_sync_start', 'self_runner_kick', 'worker_dispatcher' ) );
			if ( Mobo_Core_Lock::is_locked( 'manual_sync' ) ) {
				return $this->result( false, 'مرحله قبلی Sync هنوز در حال پایان یافتن است. پس از آزاد شدن Worker دوباره شروع کنید.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
			}
		}

		$sync_id     = sanitize_text_field( (string) $sync_id );
		$repair_mode = (bool) $repair_mode;
		$current     = $this->get_manual_sync_status();

		if ( ! empty( $current['isRunning'] ) ) {
			$recovered = $this->recover_stalled_manual_sync_checkpoint( $current, $sync_id, $source, $repair_mode );
			if ( is_array( $recovered ) ) {
				return $recovered;
			}
		}

		if ( ! empty( $current['isRunning'] ) || ! empty( $current['isWaitingForPortal'] ) ) {
			$current_sync_id = isset( $current['syncId'] ) ? sanitize_text_field( (string) $current['syncId'] ) : '';
			$current_repair  = ! empty( $current['repairMode'] );

			if ( '' !== $sync_id && '' !== $current_sync_id && hash_equals( $current_sync_id, $sync_id ) && $current_repair === $repair_mode ) {
				return $this->result( true, 'این درخواست Sync قبلاً پذیرفته شده و هنوز در حال اجرا است.', array_merge( $current, array( 'alreadyAccepted' => true ) ) );
			}

			return $this->result( false, 'یک Sync یا Repair دیگر در حال اجرا است.', $current );
		}

		if ( $repair_mode ) {
			delete_option( self::REPAIR_COMPLETED_OPTION );
		}

		if ( '' === $sync_id ) {
			$sync_id = wp_generate_uuid4();
		}

		$state = array(
			'syncId'                       => $sync_id,
			'status'                       => 'running',
			'source'                       => sanitize_key( (string) $source ),
			'repairMode'                   => $repair_mode,
			'hashCheckBypassed'            => $repair_mode,

			'categorySynced'               => false,

			'productPage'                  => 1,
			'productCursor'                => 0,
			'productCursorMode'            => Mobo_Core_Settings::enabled( 'mobo_core_product_cursor_sync_enabled', '1' ) ? 'cursor' : 'page',
			'productCursorSupported'       => false,
			'productQueue'                 => array(),

			'currentProductGuid'           => '',
			'currentProductId'             => 0,
			'currentProductPortalId'       => 0,
			'currentProductSourceRevision' => 0,
			'currentProductSourceVersion'  => '',
			'currentProductSourceHash'     => '',
			'currentProductImages'         => array(),
			'currentProductImagesPresent'  => false,
			'currentProductAttributes'     => array(),
			'currentProductImageOffset'    => 0,
			'currentProductWasExisting'    => false,
			'currentProductImagesDone'     => false,
			'currentProductCanHaveVariants'=> false,

			'variantPage'                  => 1,

			'productTotalCount'            => 0,
			'productTotalPages'            => 0,
			'processedProducts'            => 0,
			'remainingProducts'            => 0,

			'currentVariantTotalCount'     => 0,
			'currentVariantTotalPages'     => 0,
			'currentVariantProcessedPages' => 0,
			'currentVariantCursor'          => 0,

			'startedAt'                    => time(),
			'completedAt'                  => 0,
			'updatedAt'                    => time(),
			'lastMessage'                  => $repair_mode ? 'Repair محصولات شروع شد؛ ابتدا ترمیم محافظه‌کارانه داده‌های قدیمی و سپس Sync کامل اجرا می‌شود.' : 'همگام‌سازی محصولات شروع شد.',
			'lastError'                    => '',
			'transientRetryCount'           => 0,
			'lastTransientError'            => '',
			'waitingForPortalSince'         => 0,
			'nextRetryAt'                  => 0,
			'cancelRequestedAt'            => 0,
			'staleRecoveryCount'           => 0,
			'lastStaleRecoveryAt'          => 0,
			'lastStaleRecoveryReason'      => '',

			'repairIntegrityPhase'           => $repair_mode ? 'portal-variant-duplicates' : 'done',
			'repairIntegrityCursor'          => 0,
			'repairIntegrityComplete'        => ! $repair_mode,
			'repairIntegrityStats'           => array(),

			'repairProductsComplete'         => false,
			'postSyncIntegrityPhase'           => $repair_mode ? 'variation-topology' : 'done',
			'postSyncIntegrityCursor'          => 0,
			'postSyncIntegrityComplete'        => ! $repair_mode,
			'postSyncIntegrityStats'           => array(),
			'missingImageRecoveryCursor'      => 0,
			'missingImageRecoveryScanned'     => 0,
			'missingImageRecoveryQueued'      => 0,
			'missingImageRecoverySkipped'     => 0,
			'missingImageRecoveryFailed'      => 0,
			'missingImageRecoveryComplete'    => false,
		);

		if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
			return $this->result( false, 'شروع Sync به دلیل ذخیره نشدن checkpoint اولیه متوقف شد.', $this->get_manual_sync_status() );
		}

		return $this->result(
			true,
			$repair_mode ? 'Repair محصولات شروع شد.' : 'همگام‌سازی محصولات شروع شد.',
			$this->get_manual_sync_status()
		);
	}



	/**
	 * Recover a stalled running checkpoint without resetting any cursor/state.
	 *
	 * Only a generation with no active manual_sync lease and no checkpoint
	 * progress for the safety window is eligible. Remote callers with another
	 * explicit syncId cannot take over the old generation.
	 *
	 * @param array  $current Current public status.
	 * @param string $requested_sync_id Requested generation.
	 * @param string $source Request source.
	 * @param bool   $repair_mode Requested mode.
	 * @return array|null Result when recovered, otherwise null.
	 */
	private function recover_stalled_manual_sync_checkpoint( $current, $requested_sync_id, $source, $repair_mode ) {
		if ( empty( $current['isRunning'] ) || empty( $current['stalledWithoutWorker'] ) ) {
			return null;
		}

		$current_repair = ! empty( $current['repairMode'] );
		if ( $current_repair !== (bool) $repair_mode ) {
			return null;
		}

		$current_sync_id   = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );
		$requested_sync_id = sanitize_text_field( (string) $requested_sync_id );
		if ( '' !== $requested_sync_id && '' !== $current_sync_id && ! hash_equals( $current_sync_id, $requested_sync_id ) ) {
			return null;
		}

		if ( class_exists( 'Mobo_Core_Lock' ) && Mobo_Core_Lock::is_locked( 'manual_sync' ) ) {
			return null;
		}

		$state = $this->get_manual_sync_state();
		if ( 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			return null;
		}

		$state_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
		if ( '' === $state_sync_id || ( '' !== $current_sync_id && ! hash_equals( $current_sync_id, $state_sync_id ) ) ) {
			return null;
		}

		$updated_at = absint( $state['updatedAt'] ?? 0 );
		if ( $updated_at <= 0 || ( time() - $updated_at ) < self::STALE_RUNNING_RECOVERY_SECONDS ) {
			return null;
		}

		$state['staleRecoveryCount']      = absint( $state['staleRecoveryCount'] ?? 0 ) + 1;
		$state['lastStaleRecoveryAt']     = time();
		$state['lastStaleRecoveryReason'] = 'worker-missing';
		$state['lastError']               = '';
		$state['lastMessage']             = $current_repair
			? 'Repair بدون Worker فعال از آخرین checkpoint پایدار بازیابی شد و ادامه می‌یابد.'
			: 'Sync بدون Worker فعال از آخرین checkpoint پایدار بازیابی شد و ادامه می‌یابد.';

		if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
			return $this->result( false, 'Checkpoint گیرکرده تشخیص داده شد اما ثبت recovery پایدار نشد؛ state دست‌نخورده باقی ماند.', $this->get_manual_sync_status() );
		}

		return $this->result(
			true,
			$state['lastMessage'],
			array_merge( $this->get_manual_sync_status(), array( 'staleRecovered' => true, 'recoveredSyncId' => $state_sync_id, 'recoverySource' => sanitize_key( (string) $source ) ) )
		);
	}

	/**
	 * Resume the same durable manual-sync generation after a Portal wait.
	 *
	 * Only waiting_for_portal is resumable. Done/cancelled generations must be
	 * restarted explicitly so stale cursors cannot be revived accidentally.
	 *
	 * @return array
	 */
	public function resume_manual_sync() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'ادامه Sync تا پایان آپدیت افزونه متوقف است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( false, 'یک Worker Sync فعال است؛ Resume بدون تغییر state متوقف شد.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			wp_cache_delete( self::STATE_OPTION, 'options' );
			$state = $this->get_manual_sync_state();
			$status = sanitize_key( (string) ( $state['status'] ?? '' ) );
			$sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );

			if ( 'running' === $status && '' !== $sync_id ) {
				return $this->result( true, 'Sync از قبل در حال اجرا است.', array_merge( $this->get_manual_sync_status(), array( 'alreadyRunning' => true ) ) );
			}

			if ( 'waiting_for_portal' !== $status || '' === $sync_id ) {
				return $this->result( false, 'فقط Sync منتظر اتصال به MoboCore قابل Resume است. برای وضعیت فعلی Start جدید انجام دهید.', $this->get_manual_sync_status() );
			}

			$state['status']                = 'running';
			$state['lastError']             = '';
			$state['transientRetryCount']   = 0;
			$state['lastTransientError']    = '';
			$state['waitingForPortalSince'] = 0;
			$state['nextRetryAt']           = 0;
			$state['lastMessage']           = 'همگام‌سازی از همان نسل و آخرین checkpoint ذخیره‌شده ادامه داده می‌شود.';
			$state['updatedAt']             = time();

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'نسل Sync هنگام Resume تغییر کرد؛ state دست‌نخورده باقی ماند.', $this->get_manual_sync_status() );
			}

			return $this->result( true, 'ادامه Sync از آخرین checkpoint شروع شد.', $this->get_manual_sync_status() );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync', $lock );
			}
		}
	}

	public function cancel_manual_sync() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( false, 'لغو Sync هنگام Drain آپدیت مجاز نیست؛ وضعیت و Cursor حفظ شده‌اند.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$state = $this->get_manual_sync_state();
		$now   = time();

		$state['status']            = 'cancelled';
		$state['completedAt']       = $now;
		$state['updatedAt']         = $now;
		$state['cancelRequestedAt'] = $now;
		$state['nextRetryAt']       = 0;
		$state['lastError']         = '';
		$state['lastTransientError'] = '';
		$state['lastMessage']       = 'همگام‌سازی محصولات متوقف شد.';

		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}

		return $this->result(
			true,
			'همگام‌سازی محصولات متوقف شد.',
			$this->get_manual_sync_status()
		);
	}

	public function reset_manual_sync_state() {
		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync', 30 ) : 'no-lock';
		if ( false === $lock ) {
			return false;
		}

		try {
			delete_option( self::STATE_OPTION );
			wp_cache_delete( self::STATE_OPTION, 'options' );
			if ( null !== get_option( self::STATE_OPTION, null ) ) {
				return false;
			}
			$this->checkpoint_state         = null;
			$this->checkpoint_pending_steps = 0;
			return true;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync', $lock );
			}
		}
	}

	public function get_manual_sync_state() {
		$default = array(
			'syncId'                       => '',
			'status'                       => 'idle',
			'source'                       => '',
			'repairMode'                   => false,
			'hashCheckBypassed'            => false,

			'categorySynced'               => false,

			'productPage'                  => 1,
			'productCursor'                => 0,
			'productCursorMode'            => 'cursor',
			'productCursorSupported'       => false,
			'productQueue'                 => array(),

			'currentProductGuid'           => '',
			'currentProductId'             => 0,
			'currentProductPortalId'       => 0,
			'currentProductSourceRevision' => 0,
			'currentProductSourceVersion'  => '',
			'currentProductSourceHash'     => '',
			'currentProductImages'         => array(),
			'currentProductImagesPresent'  => false,
			'currentProductAttributes'     => array(),
			'currentProductImageOffset'    => 0,
			'currentProductWasExisting'    => false,
			'currentProductImagesDone'     => false,
			'currentProductCanHaveVariants'=> false,

			'variantPage'                  => 1,

			'productTotalCount'            => 0,
			'productTotalPages'            => 0,
			'processedProducts'            => 0,
			'remainingProducts'            => 0,

			'currentVariantTotalCount'     => 0,
			'currentVariantTotalPages'     => 0,
			'currentVariantProcessedPages' => 0,
			'currentVariantCursor'          => 0,

			'startedAt'                    => 0,
			'completedAt'                  => 0,
			'updatedAt'                    => 0,
			'lastMessage'                  => '',
			'lastError'                    => '',
			'transientRetryCount'           => 0,
			'lastTransientError'            => '',
			'waitingForPortalSince'         => 0,
			'nextRetryAt'                  => 0,
			'cancelRequestedAt'            => 0,
			'staleRecoveryCount'           => 0,
			'lastStaleRecoveryAt'          => 0,
			'lastStaleRecoveryReason'      => '',

			'repairIntegrityPhase'           => 'done',
			'repairIntegrityCursor'          => 0,
			'repairIntegrityComplete'        => true,
			'repairIntegrityStats'           => array(),

			'repairProductsComplete'         => false,
			'postSyncIntegrityPhase'           => 'done',
			'postSyncIntegrityCursor'          => 0,
			'postSyncIntegrityComplete'        => true,
			'postSyncIntegrityStats'           => array(),
			'missingImageRecoveryCursor'      => 0,
			'missingImageRecoveryScanned'     => 0,
			'missingImageRecoveryQueued'      => 0,
			'missingImageRecoverySkipped'     => 0,
			'missingImageRecoveryFailed'      => 0,
			'missingImageRecoveryComplete'    => false,
		);

		$state = $this->checkpoint_coalescing && is_array( $this->checkpoint_state )
			? $this->checkpoint_state
			: get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$had_post_sync_state = array_key_exists( 'postSyncIntegrityComplete', $state );
		$state = wp_parse_args( $state, $default );
		/* An in-flight Repair created by an older build did not have the post-sync
		 * phase. Do not silently treat that missing field as completed after upgrade. */
		if ( ! empty( $state['repairMode'] ) && ! $had_post_sync_state ) {
			$state['postSyncIntegrityPhase']    = 'variation-topology';
			$state['postSyncIntegrityCursor']   = 0;
			$state['postSyncIntegrityComplete'] = false;
			$state['postSyncIntegrityStats']    = array();
		}

		return $state;
	}

	public function get_manual_sync_status() {
		$state = $this->get_manual_sync_state();

		$total           = absint( $state['productTotalCount'] );
		$processed       = absint( $state['processedProducts'] );
		$last_error      = sanitize_text_field( (string) $state['lastError'] );
		$current_status  = sanitize_key( (string) $state['status'] );

		/*
		 * MoboCore totalCount can be a stale estimate while hasMore=false is the
		 * authoritative terminal signal. When sync is already done, the UI must not
		 * keep showing a phantom remaining product such as 529/530.
		 */
		if ( 'done' === $current_status ) {
			if ( $total <= 0 || $processed < $total ) {
				$total = $processed;
			}

			$remaining = 0;
			$progress  = 100;
		} else {
			$remaining = $total > 0 ? max( 0, $total - $processed ) : 0;
			$progress  = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 2 ) ) : 0;
		}

		$next_retry_at   = absint( $state['nextRetryAt'] ?? 0 );
		$is_waiting      = 'waiting_for_portal' === $current_status;
		$is_retry_due    = $is_waiting && ( 0 === $next_retry_at || $next_retry_at <= time() );
		$should_continue = ( 'running' === $current_status && '' === $last_error ) || ( $is_retry_due && '' === $last_error );
		$worker_lock     = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'manual_sync' ) : array( 'active' => false );
		$updated_at      = absint( $state['updatedAt'] ?? 0 );
		$stalled_without_worker = 'running' === $current_status
			&& empty( $worker_lock['active'] )
			&& $updated_at > 0
			&& ( time() - $updated_at ) >= self::STALE_RUNNING_RECOVERY_SECONDS;

		return array(
			'syncId'                       => sanitize_text_field( (string) $state['syncId'] ),
			'status'                       => $current_status,
			'source'                       => sanitize_key( (string) $state['source'] ),
			'repairMode'                   => ! empty( $state['repairMode'] ),
			'hashCheckBypassed'            => ! empty( $state['hashCheckBypassed'] ),
			'repairCompletedAt'            => self::get_repair_completed_at(),
			'isRepairCompleted'            => self::is_repair_completed(),

			'isRunning'                    => 'running' === $current_status,
			'isWaitingForPortal'            => $is_waiting,
			'isRetryDue'                    => $is_retry_due,
			'secondsUntilNextRetry'         => $is_waiting && $next_retry_at > time() ? max( 0, $next_retry_at - time() ) : 0,
			'isDone'                       => 'done' === $current_status,
			'isCancelled'                  => 'cancelled' === $current_status,

			'categorySynced'               => (bool) $state['categorySynced'],
			'repairIntegrityPhase'           => sanitize_key( (string) $state['repairIntegrityPhase'] ),
			'repairIntegrityCursor'          => absint( $state['repairIntegrityCursor'] ),
			'repairIntegrityComplete'        => ! empty( $state['repairIntegrityComplete'] ),
			'repairIntegrityStats'           => is_array( $state['repairIntegrityStats'] ) ? $state['repairIntegrityStats'] : array(),
			'repairProductsComplete'         => ! empty( $state['repairProductsComplete'] ),
			'postSyncIntegrityPhase'           => sanitize_key( (string) $state['postSyncIntegrityPhase'] ),
			'postSyncIntegrityCursor'          => absint( $state['postSyncIntegrityCursor'] ),
			'postSyncIntegrityComplete'        => ! empty( $state['postSyncIntegrityComplete'] ),
			'postSyncIntegrityStats'           => is_array( $state['postSyncIntegrityStats'] ) ? $state['postSyncIntegrityStats'] : array(),
			'missingImageRecoveryCursor'      => absint( $state['missingImageRecoveryCursor'] ),
			'missingImageRecoveryScanned'     => absint( $state['missingImageRecoveryScanned'] ),
			'missingImageRecoveryQueued'      => absint( $state['missingImageRecoveryQueued'] ),
			'missingImageRecoverySkipped'     => absint( $state['missingImageRecoverySkipped'] ),
			'missingImageRecoveryFailed'      => absint( $state['missingImageRecoveryFailed'] ),
			'missingImageRecoveryComplete'    => ! empty( $state['missingImageRecoveryComplete'] ),

			'productPage'                  => absint( $state['productPage'] ),
			'productCursor'                => absint( $state['productCursor'] ),
			'productCursorMode'            => sanitize_key( (string) $state['productCursorMode'] ),
			'productCursorSupported'       => (bool) $state['productCursorSupported'],
			'queuedProducts'               => is_array( $state['productQueue'] ) ? count( $state['productQueue'] ) : 0,

			'currentProductGuid'           => sanitize_text_field( (string) $state['currentProductGuid'] ),
			'currentProductId'             => absint( $state['currentProductId'] ),
			'currentProductImageOffset'    => absint( $state['currentProductImageOffset'] ),
			'currentProductImagesCount'    => is_array( $state['currentProductImages'] ) ? count( $state['currentProductImages'] ) : 0,
			'currentProductAttributesCount'=> is_array( $state['currentProductAttributes'] ) ? count( $state['currentProductAttributes'] ) : 0,
			'currentProductImagesDone'     => (bool) $state['currentProductImagesDone'],
			'currentProductCanHaveVariants'=> (bool) $state['currentProductCanHaveVariants'],

			'variantPage'                  => absint( $state['variantPage'] ),

			'productTotalCount'            => $total,
			'productTotalPages'            => absint( $state['productTotalPages'] ),
			'processedProducts'            => $processed,
			'remainingProducts'            => $remaining,
			'progressPercent'              => $progress,

			'currentVariantTotalCount'     => absint( $state['currentVariantTotalCount'] ),
			'currentVariantTotalPages'     => absint( $state['currentVariantTotalPages'] ),
			'currentVariantProcessedPages' => absint( $state['currentVariantProcessedPages'] ),
			'currentVariantCursor'          => absint( $state['currentVariantCursor'] ),

			'startedAt'                    => absint( $state['startedAt'] ),
			'completedAt'                  => absint( $state['completedAt'] ),
			'updatedAt'                    => absint( $state['updatedAt'] ),
			'waitingForPortalSince'         => absint( $state['waitingForPortalSince'] ?? 0 ),
			'nextRetryAt'                  => $next_retry_at,
			'cancelRequestedAt'            => absint( $state['cancelRequestedAt'] ?? 0 ),
			'workerLock'                    => $worker_lock,
			'stalledWithoutWorker'          => $stalled_without_worker,
			'staleRecoveryCount'            => absint( $state['staleRecoveryCount'] ?? 0 ),
			'lastStaleRecoveryAt'           => absint( $state['lastStaleRecoveryAt'] ?? 0 ),
			'lastStaleRecoveryReason'       => sanitize_key( (string) ( $state['lastStaleRecoveryReason'] ?? '' ) ),

			'lastMessage'                  => sanitize_text_field( (string) $state['lastMessage'] ),
			'lastError'                    => $last_error,
			'transientRetryCount'           => absint( $state['transientRetryCount'] ?? 0 ),
			'lastTransientError'            => sanitize_text_field( (string) ( $state['lastTransientError'] ?? '' ) ),

			'shouldContinue'               => $should_continue,
			'recommendedDelayMs'           => $should_continue ? 0 : ( $is_waiting && $next_retry_at > time() ? max( 1000, ( $next_retry_at - time() ) * 1000 ) : 5000 ),
		);
	}

	public function run_manual_sync_step() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( true, 'Sync در نقطه امن برای آپدیت افزونه Pause شده است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( 'manual_sync', 300 ) : 'no-lock';
		if ( false === $lock ) {
			return $this->result( true, 'یک Worker دیگر در حال اجرای مرحله Sync است؛ این اجرا بدون تغییر state متوقف شد.', array_merge( $this->get_manual_sync_status(), array( 'locked' => true ) ) );
		}

		try {
			/*
			 * Cron may coalesce several checkpoints in one PHP request while the
			 * manual_sync lock is intentionally released between steps. A cancel or
			 * a newer sync generation can therefore be persisted by another request
			 * between two iterations. Re-read only the durable generation boundary
			 * after acquiring ownership, before any product mutation.
			 */
			if ( $this->checkpoint_coalescing && is_array( $this->checkpoint_state ) ) {
				wp_cache_delete( self::STATE_OPTION, 'options' );
				$durable       = get_option( self::STATE_OPTION, array() );
				$buffered_id   = sanitize_text_field( (string) ( $this->checkpoint_state['syncId'] ?? '' ) );
				$durable_id    = is_array( $durable ) ? sanitize_text_field( (string) ( $durable['syncId'] ?? '' ) ) : '';
				$durable_state = is_array( $durable ) ? sanitize_key( (string) ( $durable['status'] ?? '' ) ) : '';

				$generation_changed = '' !== $buffered_id && '' !== $durable_id && ! hash_equals( $buffered_id, $durable_id );
				$cancelled_current  = 'cancelled' === $durable_state && '' !== $buffered_id && '' !== $durable_id && hash_equals( $buffered_id, $durable_id );

				if ( $generation_changed || $cancelled_current ) {
					$this->checkpoint_state         = is_array( $durable ) ? $durable : array();
					$this->checkpoint_pending_steps = 0;
					$this->checkpoint_last_flush_at = microtime( true );

					return $this->result(
						! $cancelled_current,
						$cancelled_current ? 'Sync لغو شده است؛ Worker قدیمی قبل از هر تغییر جدید متوقف شد.' : 'نسل جدید Sync شناسایی شد؛ Worker قدیمی بدون تغییر داده متوقف شد.',
						array_merge( $this->get_manual_sync_status(), array( 'staleGenerationStopped' => true ) )
					);
				}
			}

			if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
				return Mobo_Core_Cache_Mutation_Guard::run(
					function () {
						return $this->run_manual_sync_step_guarded();
					},
					! empty( $this->get_manual_sync_state()['repairMode'] ) ? 'manual-repair' : 'manual-sync'
				);
			}

			return $this->run_manual_sync_step_guarded();
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock' !== $lock ) {
				Mobo_Core_Lock::release( 'manual_sync', $lock );
			}
		}
	}

	/**
	 * Execute one manual Sync/Repair step inside the cache mutation guard.
	 *
	 * @return array
	 */
	private function run_manual_sync_step_guarded() {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return $this->result( true, 'Sync در نقطه امن برای آپدیت افزونه Pause شده است.', array_merge( $this->get_manual_sync_status(), array( 'pausedForUpgrade' => true, 'upgradeBarrier' => Mobo_Core_Upgrade_Coordinator::get_status() ) ) );
		}

		$api            = new Mobo_Core_API_Client();
		$state          = $this->get_manual_sync_state();
		$this->repair_mode = ! empty( $state['repairMode'] );
		$products_limit = Mobo_Core_Settings::get_int( 'mobo_core_products_per_page', 1, 1, 20 );
		$variants_limit = Mobo_Core_Settings::get_int( 'mobo_core_variants_per_page', 5, 1, 100 );

		if ( 'idle' === $state['status'] || '' === $state['syncId'] ) {
			return $this->result( false, 'همگام‌سازی شروع نشده است.', $this->get_manual_sync_status() );
		}

		if ( 'cancelled' === $state['status'] ) {
			return $this->result( false, 'همگام‌سازی متوقف شده است.', $this->get_manual_sync_status() );
		}

		if ( 'done' === $state['status'] ) {
			return $this->result( true, 'همگام‌سازی قبلاً کامل شده است.', $this->get_manual_sync_status() );
		}

		if ( 'waiting_for_portal' === $state['status'] ) {
			$next_retry_at = absint( $state['nextRetryAt'] ?? 0 );

			if ( $next_retry_at > 0 && $next_retry_at > time() ) {
				$state['updatedAt'] = time();
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, 'MoboCore هنوز آماده تلاش مجدد نیست. وضعیت sync حفظ شده است.', $this->get_manual_sync_status() );
			}

			$state['status']              = 'running';
			$state['transientRetryCount'] = 0;
			$state['lastError']           = '';
			$state['lastMessage']         = 'اتصال به MoboCore دوباره بررسی می‌شود؛ ادامه از آخرین نقطه ذخیره‌شده.';
			$state['updatedAt']           = time();
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
		}

		/*
		 * The existing Repair button also performs bounded legacy data cleanup
		 * before the authoritative Portal snapshot. Each call handles one small
		 * slice so large stores do not turn an admin click into a long request.
		 */
		if ( ! empty( $state['repairMode'] ) && empty( $state['repairIntegrityComplete'] ) ) {
			if ( ! class_exists( 'Mobo_Core_Repair_Integrity' ) ) {
				$state['lastError']   = 'Repair integrity service is unavailable.';
				$state['lastMessage'] = 'مرحله ترمیم داده‌های قدیمی در دسترس نیست.';
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$integrity = new Mobo_Core_Repair_Integrity();
			$integrity_result = $integrity->run_slice( $state );
			if ( is_wp_error( $integrity_result ) ) {
				$state['lastError']   = sanitize_text_field( $integrity_result->get_error_message() );
				$state['lastMessage'] = 'ترمیم داده‌های قدیمی با خطا متوقف شد: ' . $state['lastError'];
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$state['lastError']   = '';
			$state['lastMessage'] = sanitize_text_field( (string) ( isset( $integrity_result['message'] ) ? $integrity_result['message'] : 'مرحله ترمیم داده‌های قدیمی اجرا شد.' ) );
			$state['updatedAt']   = time();
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Once the authoritative product pass is complete, run a second bounded
		 * integrity pass over only the parents that were durably completed by this
		 * exact Repair generation. This avoids treating OnlyInStock omissions as
		 * authoritative topology evidence.
		 */
		if ( ! empty( $state['repairMode'] ) && ! empty( $state['repairProductsComplete'] ) && empty( $state['postSyncIntegrityComplete'] ) ) {
			if ( ! class_exists( 'Mobo_Core_Repair_Integrity' ) ) {
				return $this->result( false, 'مرحله نهایی Repair در دسترس نیست.', $this->get_manual_sync_status() );
			}
			$integrity = new Mobo_Core_Repair_Integrity();
			$post_result = $integrity->run_post_sync_slice( $state );
			if ( is_wp_error( $post_result ) ) {
				$state['lastError']   = sanitize_text_field( $post_result->get_error_message() );
				$state['lastMessage'] = 'مرحله نهایی Repair با خطا متوقف شد: ' . $state['lastError'];
				$this->save_manual_sync_state( $state );
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}
			$state['lastError']   = '';
			$state['lastMessage'] = sanitize_text_field( (string) ( $post_result['message'] ?? 'مرحله نهایی Repair اجرا شد.' ) );
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint مرحله نهایی Repair ذخیره نشد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Repair-only image recovery pass. The normal product list may exclude a
		 * product that became out of stock mid-sync. Existing local products that
		 * have no usable image are fetched individually by GUID and only their
		 * image payload is applied. Stock, fields and variants are untouched.
		 */
		if ( ! empty( $state['repairMode'] ) && ! empty( $state['repairProductsComplete'] ) && ! empty( $state['postSyncIntegrityComplete'] ) && empty( $state['missingImageRecoveryComplete'] ) ) {
			return $this->run_missing_image_repair_step( $state );
		}

		/*
		 * Step 1: Sync categories once before products.
		 */
		if ( empty( $state['categorySynced'] ) ) {
			if ( $this->rules->should_update_categories() ) {
				$response = $api->get_categories( $state['syncId'] );

				if ( is_wp_error( $response ) ) {
					return $this->handle_transient_request_error( $state, $response, 'خطا در همگام‌سازی دسته‌بندی‌ها.' );
				}

				$categories = $this->normalize_categories_api_response( $response );
				if ( is_wp_error( $categories ) ) {
					return $this->handle_transient_request_error( $state, $categories, 'پاسخ دسته‌بندی MoboCore نامعتبر بود.' );
				}
				$this->clear_transient_request_error( $state );

				$category_result = $this->category_sync->sync_categories_payload( $categories );
				if ( empty( $category_result['complete'] ) || absint( $category_result['skipped'] ) > 0 ) {
					$error = new WP_Error(
						'mobo_core_category_snapshot_incomplete',
						sprintf( 'Category snapshot was only partially applied; %d row(s) were invalid or unresolved.', absint( $category_result['skipped'] ) )
					);
					return $this->handle_transient_request_error( $state, $error, 'همگام‌سازی دسته‌بندی ناقص بود و در اجرای بعد دوباره تلاش می‌شود.' );
				}

				update_option( 'mobo_core_categories_last_sync_at', time(), false );

				$state['categorySynced'] = true;
				$state['lastError']      = '';
				$state['lastMessage']    = sprintf(
					'دسته‌بندی‌ها همگام شدند. ایجاد: %d، بروزرسانی: %d، رد شده: %d',
					absint( $category_result['created'] ),
					absint( $category_result['updated'] ),
					absint( $category_result['skipped'] )
				);

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$state['categorySynced'] = true;
			$state['lastError']      = '';
			$state['lastMessage']    = 'همگام‌سازی دسته‌بندی غیرفعال است؛ در صورت ایجاد محصول جدید، دسته پیشفرض استفاده می‌شود.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 2: Fetch product page when queue is empty and no current product exists.
		 */
		if ( empty( $state['productQueue'] ) && '' === $state['currentProductGuid'] ) {
			$use_product_cursor = Mobo_Core_Settings::enabled( 'mobo_core_product_cursor_sync_enabled', '1' ) && 'page-fallback' !== (string) $state['productCursorMode'];

			$response = $api->get_products_page(
				absint( $state['productPage'] ),
				$products_limit,
				$state['syncId'],
				absint( $state['productCursor'] ),
				$use_product_cursor
			);

			if ( is_wp_error( $response ) ) {
				return $this->handle_transient_request_error( $state, $response, 'خطا در دریافت صفحه محصولات.' );
			}

			$items = $this->extract_explicit_list_data( $response, 'products page' );
			if ( is_wp_error( $items ) ) {
				return $this->handle_transient_request_error( $state, $items, 'پاسخ صفحه محصولات MoboCore نامعتبر بود.' );
			}
			$has_more_result = $this->validated_page_has_more( $response, absint( $state['productPage'] ) );
			if ( is_wp_error( $has_more_result ) ) {
				return $this->handle_transient_request_error( $state, $has_more_result, 'صفحه‌بندی محصولات MoboCore نامعتبر بود.' );
			}
			$has_more = (bool) $has_more_result;
			$this->clear_transient_request_error( $state );

			$total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );
			$total_pages = absint( $this->get_value( $response, 'totalPages', 0 ) );

			if ( $total_count > 0 ) {
				$state['productTotalCount'] = $total_count;
			}

			if ( $total_pages > 0 ) {
				$state['productTotalPages'] = $total_pages;
			}


			$cursor_mode = sanitize_key( (string) $this->get_value( $response, 'cursorMode', '' ) );
			if ( '' !== $cursor_mode ) {
				$state['productCursorMode']      = $cursor_mode;
				$state['productCursorSupported'] = true;

				$next_cursor = $this->get_value( $response, 'nextCursor', null );
				if ( $has_more && ( null === $next_cursor || '' === $next_cursor || absint( $next_cursor ) <= absint( $state['productCursor'] ) ) ) {
					$error = new WP_Error( 'mobo_core_products_cursor_stalled', 'Product cursor did not advance while hasMore=true.' );
					return $this->handle_transient_request_error( $state, $error, 'صفحه‌بندی محصولات MoboCore متوقف شد.' );
				}
				if ( null !== $next_cursor && '' !== $next_cursor ) {
					$state['productCursor'] = absint( $next_cursor );
				}
			} elseif ( $use_product_cursor ) {
				/*
				 * Backward compatibility: older MoboCore builds ignore UseCursor/Cursor.
				 * After the first legacy response, fall back to page-number mode.
				 */
				$state['productCursorMode']      = 'page-fallback';
				$state['productCursorSupported'] = false;
			}

			/* Every manual product page is a point-in-time snapshot. Persist a local
			 * capture fence on each queued item so a foreground webhook that lands
			 * after this fetch can never be overwritten when the item is processed
			 * later. This protection is required for normal Full Sync as well as
			 * Repair. */
			$manual_snapshot_us = $this->current_wall_clock_us();

			foreach ( $items as $product_data ) {
				if ( is_array( $product_data ) ) {
					$product_data['_moboManualSnapshotUs'] = $manual_snapshot_us;
					if ( $this->is_repair_mode() ) {
						/* Backward-compatible marker for checkpoints created by older builds. */
						$product_data['_moboRepairSnapshotUs'] = $manual_snapshot_us;
					}
					$state['productQueue'][] = $product_data;
				}
			}

			$state['productPage'] = absint( $state['productPage'] ) + 1;

			if ( empty( $state['productQueue'] ) && ! $this->to_bool( $has_more ) ) {
				/*
				 * If MoboCore says there is no next page, sync is complete even when a
				 * previously reported totalCount was one item higher. Persist the
				 * effective total so the admin UI does not show a phantom remaining item.
				 */
				$state['productTotalCount'] = absint( $state['processedProducts'] );
				$state['status']            = 'done';
				$state['completedAt']       = time();
				$state['lastError']         = '';
				$state['lastMessage']       = 'همگام‌سازی محصولات کامل شد.';
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, 'همگام‌سازی محصولات کامل شد.', $this->get_manual_sync_status() );
			}

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, 'صفحه محصولات دریافت شد.', $this->get_manual_sync_status() );
		}

		/*
		 * Step 3: Upsert one parent product.
		 */
		if ( '' === $state['currentProductGuid'] ) {
			$product_data = array_shift( $state['productQueue'] );

			if ( ! is_array( $product_data ) ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول نامعتبر رد شد.';

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			if ( $this->should_skip_product_by_url( $product_data ) ) {
				$skipped_url = sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );

				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن آدرس در لیست عدم همگام‌سازی رد شد: ' . $skipped_url;

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result(
					true,
					$state['lastMessage'],
					$this->get_manual_sync_status()
				);
			}

			$product_guid       = $this->extract_product_guid( $product_data );
			$manual_snapshot_us = isset( $product_data['_moboManualSnapshotUs'] )
				? absint( $product_data['_moboManualSnapshotUs'] )
				: ( isset( $product_data['_moboRepairSnapshotUs'] ) ? absint( $product_data['_moboRepairSnapshotUs'] ) : 0 );

			/*
			 * Manual/Repair pages are snapshots. A foreground webhook may have applied
			 * newer parent/variant state after this page was fetched but before this
			 * queued product reaches Step 3. Never let that old page overwrite the
			 * webhook. Refresh the exact product outside the product lock so webhook
			 * latency stays low; a second watermark check after lock acquisition closes
			 * the race.
			 */
			if ( '' !== $product_guid && $manual_snapshot_us > 0 ) {
				$last_webhook_us = $this->get_last_webhook_applied_us( $product_guid );

				if ( $last_webhook_us > $manual_snapshot_us ) {
					$fresh_response = $api->get_product_by_guid( $product_guid, $state['syncId'] );

					if ( is_wp_error( $fresh_response ) ) {
						array_unshift( $state['productQueue'], $product_data );
						return $this->handle_transient_request_error( $state, $fresh_response, 'Manual Sync برای جلوگیری از بازنویسی Webhook منتظر Snapshot تازه محصول است.' );
					}

					$fresh_items = $this->extract_explicit_list_data( $fresh_response, 'repair exact product refresh' );

					if ( is_wp_error( $fresh_items ) ) {
						array_unshift( $state['productQueue'], $product_data );
						return $this->handle_transient_request_error( $state, $fresh_items, 'Manual Sync پاسخ Snapshot تازه محصول را معتبر دریافت نکرد.' );
					}

					$fresh_product = null;
					foreach ( $fresh_items as $fresh_candidate ) {
						if ( is_array( $fresh_candidate ) && $product_guid === $this->extract_product_guid( $fresh_candidate ) ) {
							$fresh_product = $fresh_candidate;
							break;
						}
					}

					if ( ! is_array( $fresh_product ) ) {
						/* The exact endpoint no longer exposes this product. Keep the newer
						 * webhook-applied local state rather than replaying an older Repair page. */
						$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
						$state['lastError']         = '';
						$state['lastMessage']       = 'Repair یک Snapshot قدیمی را کنار گذاشت تا وضعیت جدیدتر Webhook حفظ شود: ' . $product_guid;
						$state['updatedAt']         = time();
						if ( ! $this->save_manual_sync_state( $state ) ) {
							return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
						}

						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}

					$product_data = $fresh_product;
					if ( $this->should_skip_product_by_url( $product_data ) ) {
						$skipped_url = class_exists( 'Mobo_Core_Product_Exclusions' )
							? Mobo_Core_Product_Exclusions::get_payload_url( $product_data )
							: sanitize_text_field( (string) $this->get_value( $product_data, 'url', '' ) );
						$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
						$state['lastError']         = '';
						$state['lastMessage']       = 'Snapshot تازه محصول در فهرست عدم همگام‌سازی است و Repair/Sync آن را تغییر نداد: ' . sanitize_text_field( (string) $skipped_url );
						$state['updatedAt']         = time();
						if ( ! $this->save_manual_sync_state( $state ) ) {
							return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
						}
						return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
					}

					$manual_snapshot_us                    = $this->current_wall_clock_us();
					$product_data['_moboManualSnapshotUs'] = $manual_snapshot_us;
					if ( $this->is_repair_mode() ) {
						$product_data['_moboRepairSnapshotUs'] = $manual_snapshot_us;
					}
					$this->clear_transient_request_error( $state );
				}
			}

			if ( '' !== $product_guid && ! $this->is_repair_mode() && $this->is_remote_product_trashed( $product_guid ) ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول به دلیل قرار داشتن در سطل زباله وردپرس رد شد: ' . $product_guid;

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$product_lock = false;

			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );

				if ( false === $product_lock ) {
					array_unshift( $state['productQueue'], $product_data );
					$state['lastError']   = '';
					$state['lastMessage'] = 'این محصول در مسیر دیگری در حال پردازش است؛ sync در مرحله بعدی دوباره تلاش می‌کند: ' . $product_guid;
					$state['updatedAt']   = time();
					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
					}

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}
			}

			try {
				if ( '' !== $product_guid && $manual_snapshot_us > 0 && $this->get_last_webhook_applied_us( $product_guid ) > $manual_snapshot_us ) {
					array_unshift( $state['productQueue'], $product_data );
					$state['lastError']   = '';
					$state['lastMessage'] = 'Webhook جدیدتری هنگام آماده‌سازی Manual Sync رسید؛ این محصول در مرحله بعد با Snapshot تازه پردازش می‌شود: ' . $product_guid;
					$state['updatedAt']   = time();
					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
					}

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}

				$existing_product_id = '' !== $product_guid ? $this->find_product_id_by_guid( $product_guid ) : 0;
				$was_existing        = $existing_product_id > 0;
				$desired_attributes  = $this->get_value( $product_data, 'attributes', array() );
				if ( ! is_array( $desired_attributes ) ) {
					$desired_attributes = array();
				}

				$attribute_structure_changed = false;
				if ( $existing_product_id > 0 && $this->product_attributes_field_present( $product_data ) ) {
					$existing_product = wc_get_product( $existing_product_id );
					$attribute_structure_changed = $existing_product instanceof WC_Product
						&& $this->product_attribute_structure_changed( $existing_product, $desired_attributes );
				}

				/*
				 * Do not delete existing variations before the replacement snapshot has
				 * been fetched. The terminal authoritative variant page removes stale
				 * children only after the replacement set is proven complete.
				 */
				$product_id = $this->upsert_parent_product( $product_data, true );
				$product_source_version  = $this->extract_source_version( $product_data, $product_data );
				$product_source_revision = $this->extract_source_revision( $product_data, $product_data );
				$product_source_hash     = '' !== $product_guid ? $this->build_product_source_hash( $product_data, $product_guid ) : '';
				$product_portal_id       = $this->extract_portal_product_id( $product_data );
				if ( $product_id > 0 && ( '' !== $product_source_version || $product_source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'product', $product_source_version, $product_source_revision, true ) ) {
					$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
					$state['lastError']   = 'Product ordering watermark could not be persisted.';
					$state['lastMessage'] = 'محصول اعمال شد اما checkpoint ترتیب آن ذخیره نشد؛ همان محصول در اجرای بعد idempotent تکرار می‌شود.';
					$this->save_manual_sync_state( $state );
					return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
				}
				if ( $attribute_structure_changed && $product_id > 0 ) {
					update_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', '1' );
					update_post_meta( $product_id, '_mobo_desired_state_rebuild_requested_at', gmdate( 'c' ) );
					if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
						Mobo_Core_Sync_Health::mark_behind( $product_guid, $product_id );
					}
				}
			} finally {
				if ( false !== $product_lock && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
					Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
				}
			}

			if ( $product_id <= 0 ) {
				$state['processedProducts'] = absint( $state['processedProducts'] ) + 1;
				$state['lastError']         = '';
				$state['lastMessage']       = 'محصول نامعتبر رد شد.';

				if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
					$state['status']      = 'done';
					$state['completedAt'] = time();
					$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
				}

				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}

				return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
			}

			$state['currentProductGuid']            = $product_guid;
			$state['currentProductId']              = absint( $product_id );
			$state['currentProductPortalId']        = isset( $product_portal_id ) ? absint( $product_portal_id ) : 0;
			$state['currentProductSourceRevision']  = isset( $product_source_revision ) ? absint( $product_source_revision ) : 0;
			$state['currentProductSourceVersion']   = isset( $product_source_version ) ? sanitize_text_field( (string) $product_source_version ) : '';
			$state['currentProductSourceHash']      = isset( $product_source_hash ) ? sanitize_text_field( (string) $product_source_hash ) : '';
			$state['currentProductImages']          = $this->get_product_images_from_payload( $product_data );
			$state['currentProductImagesPresent']   = $this->product_images_field_present( $product_data );
			$state['currentProductAttributes']      = is_array( $desired_attributes ) ? $desired_attributes : array();
			$state['currentProductImageOffset']     = 0;
			$state['currentProductWasExisting']     = $was_existing;
			$state['currentProductImagesDone']      = false;
			$state['currentProductCanHaveVariants'] = $this->product_payload_can_have_variants( $product_data, $product_id );

			$state['variantPage']                   = 1;
			$state['currentVariantTotalCount']      = 0;
			$state['currentVariantTotalPages']      = 0;
			$state['currentVariantProcessedPages']  = 0;
			$state['currentVariantCursor']          = 0;
			$state['lastError']                     = '';
			$state['lastMessage']                   = $this->last_parent_core_noop
				? 'محصول اصلی بدون تغییر بود و ذخیره مجدد نشد: ' . $product_guid
				: 'محصول اصلی همگام شد: ' . $product_guid;

			if ( ! $this->reset_seen_variants( $product_guid, $state['syncId'] ) ) {
				$state['lastError']   = 'Could not durably initialize the variation seen-set.';
				$state['lastMessage'] = 'Checkpoint تنوع‌های محصول ذخیره نشد؛ برای جلوگیری از حذف اشتباه هیچ Variantی پردازش نشد.';
				if ( ! $this->save_manual_sync_state( $state ) ) {
					return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
				}
				return $this->result( false, $state['lastMessage'], $this->get_manual_sync_status() );
			}
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint محصول پس از آماده‌سازی Variantها به‌صورت پایدار ذخیره نشد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 4: Process product images in chunks before variants/simple finish.
		 */
		if ( empty( $state['currentProductImagesDone'] ) ) {
			$product_id   = absint( $state['currentProductId'] );
			$images       = is_array( $state['currentProductImages'] ) ? $state['currentProductImages'] : array();
			$image_offset = absint( $state['currentProductImageOffset'] );
			$was_existing = ! empty( $state['currentProductWasExisting'] );

			$images_present = ! empty( $state['currentProductImagesPresent'] );
			$should_process_images = $product_id > 0
				&& ( ! $was_existing || $images_present )
				&& ( ! $was_existing || $this->rules->should_update_images() )
				&& $this->product_images_need_processing( $product_id, $images, ! $was_existing );

			if ( $should_process_images ) {
				$image_result = $this->image_sync->process_images(
					$product_id,
					$images,
					$image_offset,
					false
				);

				if ( ! empty( $image_result['error'] ) ) {
					return $this->handle_transient_request_error( $state, (string) $image_result['error'], 'ثبت Desired State تصاویر در صف دیتابیس ناموفق بود.' );
				}

				if ( empty( $image_result['done'] ) ) {
					$state['currentProductImageOffset'] = absint( $image_result['nextOffset'] );
					$state['lastError']                 = '';
					$state['lastMessage']               = sprintf( 'تصاویر محصول در حال پردازش است. پردازش‌شده: %d، باقی‌مانده صف: %d', isset( $image_result['processed'] ) ? absint( $image_result['processed'] ) : 0, isset( $image_result['pending'] ) ? absint( $image_result['pending'] ) : 0 );

					if ( ! $this->save_manual_sync_state( $state ) ) {
						return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
					}

					return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
				}

				if ( ! empty( $image_result['pending'] ) ) {
					if ( ! $this->mark_product_images_pending( $product_id, $images ) ) {
						return $this->handle_transient_request_error( $state, 'Image pending desired-state hash could not be committed.', 'ثبت watermark در انتظار تصاویر ناموفق بود.' );
					}
				} elseif ( ! $this->mark_product_images_converged( $product_id, $images ) ) {
					return $this->handle_transient_request_error( $state, 'Image desired-state hash could not be committed.', 'ثبت watermark تصاویر ناموفق بود.' );
				}
			}

			$state['currentProductImagesDone']   = true;
			$state['currentProductImageOffset']  = 0;
			$state['lastError']                  = '';
			$state['lastMessage']                = 'تصاویر محصول به صف امن منتقل شد و همگام‌سازی محصول ادامه پیدا می‌کند.';

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 5: Product without variation attributes is simple.
		 *
		 * A Mobo storefront "simple" product still has one purchasable Variant row.
		 * WooCommerce does not create a WC_Product_Variation for that row, therefore
		 * the storefront portal_variant_id and remote variant GUID must be persisted
		 * on the WC_Product_Simple itself. Older builds stopped here without calling
		 * get-variants, leaving the product visible in WooCommerce but impossible to
		 * add to the Mobo cart during checkout/order submission.
		 */
		if ( empty( $state['currentProductCanHaveVariants'] ) ) {
			$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );
			$product_id   = absint( $state['currentProductId'] );

			if ( $product_id <= 0 && '' !== $product_guid ) {
				$product_id = $this->find_product_id_by_guid( $product_guid );
			}

			if ( $product_id > 0 ) {
				/*
				 * Fetch/validate Portal state outside the shared product lock. Only the
				 * local topology/identity commit is serialized. This keeps Portal latency
				 * out of the critical section while closing the Step-3 -> Step-5 TOCTOU.
				 */
				$simple_snapshot = $this->fetch_simple_product_variant_snapshot_from_api(
					$api,
					$product_guid,
					$product_id,
					$state['syncId']
				);

				if ( is_wp_error( $simple_snapshot ) ) {
					return $this->handle_transient_request_error( $state, $simple_snapshot, 'خطا در دریافت شناسه قابل خرید محصول ساده.' );
				}

				$simple_variant = $this->commit_manual_simple_product_variant_snapshot(
					$product_id,
					$product_guid,
					$simple_snapshot,
					isset( $state['currentProductAttributes'] ) && is_array( $state['currentProductAttributes'] )
						? $state['currentProductAttributes']
						: array()
				);

				if ( is_wp_error( $simple_variant ) ) {
					return $this->handle_transient_request_error( $state, $simple_variant, 'محصول ساده برای commit امن منتظر lock/revalidation والد است.' );
				}

				$message = ! empty( $simple_variant['success'] )
					? 'محصول ساده و شناسه قابل خرید آن با موفقیت همگام شد.'
					: ( isset( $simple_variant['message'] ) ? $simple_variant['message'] : 'شناسه قابل خرید محصول ساده پیدا نشد؛ محصول برای خرید مسدود شد.' );

				wc_delete_product_transients( $product_id );
			} else {
				$message = 'محصول ساده محلی پیدا نشد و پردازش آن رد شد.';
			}

			if ( ! $this->finish_current_product_state( $state, $message ) ) {
				return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_product_map_finalize_failed', 'Product Map final checkpoint was not durably stored.' ), 'ثبت نهایی Product Map محصول ناموفق بود.' );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		/*
		 * Step 6: Sync one variants page for current product.
		 */
		$product_guid = sanitize_text_field( (string) $state['currentProductGuid'] );

		$use_variant_cursor = Mobo_Core_Settings::enabled( 'mobo_core_variant_cursor_sync_enabled', '1' );
		$variant_cursor     = max( 0, absint( $state['currentVariantCursor'] ?? 0 ) );

		$response = $api->get_variants_page(
			$product_guid,
			absint( $state['variantPage'] ),
			$variants_limit,
			$state['syncId'],
			$variant_cursor,
			$use_variant_cursor
		);

		if ( is_wp_error( $response ) ) {
			return $this->handle_transient_request_error( $state, $response, 'خطا در دریافت تنوع‌های محصول.' );
		}

		$variant_items = $this->validated_variant_page_items( $response );
		if ( is_wp_error( $variant_items ) ) {
			return $this->handle_transient_request_error( $state, $variant_items, 'پاسخ تنوع‌های MoboCore نامعتبر بود.' );
		}
		$variant_has_more_result = $this->validated_page_has_more( $response, absint( $state['variantPage'] ) );
		if ( is_wp_error( $variant_has_more_result ) ) {
			return $this->handle_transient_request_error( $state, $variant_has_more_result, 'صفحه‌بندی تنوع‌های MoboCore نامعتبر بود.' );
		}
		$variant_has_more = (bool) $variant_has_more_result;
		$this->clear_transient_request_error( $state );

		$variant_items_count = count( $variant_items );
		$variant_total_count = absint( $this->get_value( $response, 'totalCount', 0 ) );

		/*
		 * Some MoboCore/Swagger payloads may return totalCount=0 while data still contains variants.
		 * Treat the actual data as authoritative for this page; never mark existing variations
		 * as simple/out-of-stock just because totalCount is zero.
		 */
		if ( 0 === $variant_total_count && $variant_items_count > 0 ) {
			$variant_total_count = $variant_items_count;
		}

		$product_id = absint( $state['currentProductId'] );

		if ( $product_id <= 0 ) {
			$product_id = $this->find_product_id_by_guid( $product_guid );
		}

		/*
		 * Do not mutate product topology from variant_total_count alone. A zero count
		 * is destructive evidence only when process_update_variant_payload() also sees
		 * an explicit valid attributes=[] collection on the authoritative page. This
		 * keeps manual/API sync on the same presence policy as webhook processing.
		 */

		$state['currentVariantTotalCount'] = $variant_total_count;
		$state['currentVariantTotalPages'] = absint( $this->get_value( $response, 'totalPages', 0 ) );

		$variant_cursor_mode = sanitize_key( (string) $this->get_value( $response, 'cursorMode', '' ) );
		$variant_next_cursor = $this->get_value( $response, 'nextCursor', null );
		if ( '' !== $variant_cursor_mode && $variant_has_more && ( null === $variant_next_cursor || '' === $variant_next_cursor || absint( $variant_next_cursor ) <= $variant_cursor ) ) {
			$error = new WP_Error( 'mobo_core_variants_cursor_stalled', 'Variant cursor did not advance while hasMore=true.' );
			return $this->handle_transient_request_error( $state, $error, 'صفحه‌بندی تنوع‌های MoboCore متوقف شد.' );
		}
		if ( '' !== $variant_cursor_mode && null !== $variant_next_cursor && '' !== $variant_next_cursor ) {
			$state['currentVariantCursor'] = absint( $variant_next_cursor );
		}

		$variant_source_version  = $this->extract_source_version( $response, $response );
		$variant_source_revision = $this->extract_source_revision( $response, $response );
		$payload = array(
			'event'                    => 'UpdateVariant',
			'variantListAuthoritative' => true,
			'syncId'                   => $state['syncId'],
			'eventVersion'              => $variant_source_version,
			'sourceRevision'            => $variant_source_revision,
			'productId'     => $product_guid,
			'totalCount'    => $variant_total_count,
			'pageNumber'    => $this->get_value( $response, 'pageNumber', $state['variantPage'] ),
			'recordPerPage' => $this->get_value( $response, 'recordPerPage', $variants_limit ),
			'hasMore'       => $variant_has_more,
			'isLastPage'    => ! $variant_has_more,
			'attributes'    => is_array( $state['currentProductAttributes'] ) ? $state['currentProductAttributes'] : array(),
			'attributeOrderSignificant' => false,
			'data'          => $variant_items,
		);

		$result = $this->process_update_variant_payload( $payload, true );

		if ( empty( $result['success'] ) ) {
			$state['lastError']   = isset( $result['message'] ) ? $result['message'] : 'خطا در پردازش تنوع محصول.';
			$state['lastMessage'] = 'خطا در پردازش تنوع محصول.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( false, $state['lastError'], $this->get_manual_sync_status() );
		}

		$state['currentVariantProcessedPages'] = absint( $state['currentVariantProcessedPages'] ) + 1;

		$variant_items     = $this->get_value( $payload, 'data', array() );
		$variant_has_more  = $this->to_bool( $payload['hasMore'] );
		$payload_last_page = $this->get_value( $payload, 'isLastPage', null );
		$total_pages       = absint( $state['currentVariantTotalPages'] );

		if ( null !== $payload_last_page && $this->to_bool( $payload_last_page ) ) {
			$variant_has_more = false;
		}

		if ( $total_pages > 0 && absint( $state['currentVariantProcessedPages'] ) >= $total_pages ) {
			$variant_has_more = false;
		}

		if ( is_array( $variant_items ) && empty( $variant_items ) ) {
			$variant_has_more = false;
		}

		if ( $variant_has_more ) {
			$state['variantPage'] = absint( $state['variantPage'] ) + 1;
			$state['lastError']   = '';
			$state['lastMessage'] = $result['message'];

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		if ( ! $this->finish_current_product_state( $state, $result['message'] ) ) {
			return $this->handle_transient_request_error( $state, new WP_Error( 'mobo_core_product_map_finalize_failed', 'Product Map final checkpoint was not durably stored.' ), 'ثبت نهایی Product Map محصول ناموفق بود.' );
		}

		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}



	/**
	 * Extract the product GUID currently being processed from a ProductUpdated payload.
	 *
	 * @param array $payload Payload by reference.
	 * @return string
	 */
	private function extract_current_product_guid_from_product_updated_payload( &$payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$items = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $items ) ) {
			return '';
		}

		$product_index = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );

		if ( ! isset( $items[ $product_index ] ) || ! is_array( $items[ $product_index ] ) ) {
			return '';
		}

		return $this->extract_product_guid( $items[ $product_index ] );
	}

	/**
	 * Extract parent product GUID from all known UpdateVariant payload shapes.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function extract_product_guid_from_update_variant_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$inner_data = $this->get_value( $payload, 'data', null );
		if ( is_array( $inner_data ) && ! $this->is_list_array( $inner_data ) ) {
			$inner_product_guid = $this->extract_product_guid( $inner_data );
			$inner_variants     = $this->get_value( $inner_data, 'data', null );

			if ( '' !== $inner_product_guid || is_array( $inner_variants ) ) {
				$payload = array_merge( $payload, $inner_data );
			}
		}

		$product_guid = $this->extract_product_guid( $payload );
		$variants     = $this->get_value( $payload, 'data', array() );

		if ( is_array( $variants ) && ! $this->is_list_array( $variants ) ) {
			$nested_variants = $this->get_value( $variants, 'data', null );
			if ( is_array( $nested_variants ) ) {
				$variants = $nested_variants;
			}
		}

		if ( '' === $product_guid && is_array( $variants ) && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$product_guid = $this->extract_product_guid( $variants[0] );
		}

		if ( '' === $product_guid ) {
			$variant_guid = $this->extract_variant_guid( $payload );
			if ( '' !== $variant_guid ) {
				$product_guid = $this->find_parent_product_guid_by_variant_guid( $variant_guid );
			}
		}

		return sanitize_text_field( (string) $product_guid );
	}

	/**
	 * Current wall-clock timestamp in microseconds for local ordering guards.
	 *
	 * @return int
	 */
	private function current_wall_clock_us() {
		return (int) floor( microtime( true ) * 1000000 );
	}

	/**
	 * Record that a foreground webhook successfully applied product state.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return bool True only when the ordering fence is durably persisted.
	 */
	private function mark_webhook_product_applied( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}

		$watermark = $this->current_wall_clock_us();
		return $this->persist_post_meta_verified( $product_id, '_mobo_last_webhook_applied_us', $watermark );
	}

	/**
	 * Read the most recent foreground webhook watermark for a Mobo product.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @return int
	 */
	private function get_last_webhook_applied_us( $product_guid ) {
		$product_id = $this->find_product_id_by_guid( $product_guid );
		if ( $product_id <= 0 ) {
			return 0;
		}

		return absint( get_post_meta( $product_id, '_mobo_last_webhook_applied_us', true ) );
	}

	/**
	 * Whether the current manual sync run is a repair pass.
	 *
	 * Within payload application, Repair bypasses the source-hash short-circuit and
	 * may restore an exact trashed identity that is present in the authoritative
	 * Repair payload. Field update decisions still follow normal Mobo settings.
	 * Manual Repair also runs the bounded legacy-integrity stage before payloads.
	 *
	 * @return bool
	 */
	private function is_repair_mode() {
		return (bool) $this->repair_mode;
	}

	/**
	 * Get repair completion timestamp.
	 *
	 * @return int
	 */
	public static function get_repair_completed_at() {
		return absint( get_option( self::REPAIR_COMPLETED_OPTION, 0 ) );
	}

	/**
	 * Whether the required repair pass has completed.
	 *
	 * @return bool
	 */
	public static function is_repair_completed() {
		return self::get_repair_completed_at() > 0;
	}

	public function process_product_updated_payload( &$payload ) {
		$context = $this->extract_product_updated_result_context( $payload );
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			$result = Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( &$payload ) {
					return $this->process_product_updated_payload_guarded( $payload );
				},
				$this->is_repair_mode() ? 'repair-product' : 'webhook-product'
			);
		} else {
			$result = $this->process_product_updated_payload_guarded( $payload );
		}

		return $this->attach_result_sync_context( $result, $context );
	}

	/** Build durable identity/revision context for the ProductUpdated item about to run. */
	private function extract_product_updated_result_context( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$items   = $this->get_value( $payload, 'data', array() );
		$index   = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );
		$item    = is_array( $items ) && isset( $items[ $index ] ) && is_array( $items[ $index ] ) ? $items[ $index ] : array();
		$guid    = $this->extract_product_guid( $item );
		return array(
			'productGuid'    => $guid,
			'productId'      => '' !== $guid ? $this->find_product_id_by_guid( $guid ) : 0,
			'portalProductId'=> $this->extract_portal_product_id( $item ),
			'sourceRevision' => $this->extract_source_revision( $item, $payload ),
			'sourceVersion'  => $this->extract_source_version( $item, $payload ),
			'sourceHash'     => '' !== $guid ? $this->build_product_source_hash( $item, $guid ) : '',
		);
	}

	/** Attach item identity to every checkpoint result, including batch partials. */
	private function attach_result_sync_context( $result, $context ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			$result['data'] = array();
		}
		foreach ( array( 'productGuid', 'productId', 'portalProductId', 'sourceRevision', 'sourceVersion', 'sourceHash' ) as $key ) {
			if ( ! isset( $result['data'][ $key ] ) && isset( $context[ $key ] ) && '' !== (string) $context[ $key ] && 0 !== $context[ $key ] ) {
				$result['data'][ $key ] = $context[ $key ];
			}
		}
		return $result;
	}

	/** Extract a numeric source revision from item/wrapper without inventing order. */
	private function extract_source_revision( $item, $wrapper = array() ) {
	return Mobo_Core_Ordering_Policy::extract_numeric_revision_from_sources( $item, $wrapper );
}

	/** Extract the raw ordering watermark without assuming it is numeric. */
	private function extract_source_version( $item, $wrapper = array() ) {
	return Mobo_Core_Ordering_Policy::extract_version_from_sources( $item, $wrapper );
}

	/** Compare numeric or ISO-date source versions; null means format is not safely ordered. */
	private function compare_source_versions( $left, $right ) {
	return Mobo_Core_Ordering_Policy::compare_versions( $left, $right );
}

	/**
	 * Process ProductUpdated after entering the Mobo cache mutation scope.
	 *
	 * @param array $payload Payload, by reference.
	 * @return array
	 */
	private function process_product_updated_payload_guarded( &$payload ) {
		$product_guid = $this->extract_current_product_guid_from_product_updated_payload( $payload );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'manual_sync_active', 45 );
			}

			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );

			if ( false === $lock ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'product_lock_busy', 30 );
			}

			try {
				return $this->process_product_updated_payload_unlocked( $payload );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->process_product_updated_payload_unlocked( $payload );
	}

	private function process_product_updated_payload_unlocked( &$payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->result( false, 'Invalid ProductUpdated payload.' );
		}

		$items = $this->get_value( $payload, 'data', array() );

		if ( ! is_array( $items ) ) {
			return $this->result( false, 'ProductUpdated data must be array.' );
		}

		$product_index = max( 0, absint( $this->get_value( $payload, '_moboProductIndex', 0 ) ) );
		$image_offset  = max( 0, absint( $this->get_value( $payload, '_moboImageOffset', 0 ) ) );

		if ( ! isset( $items[ $product_index ] ) || ! is_array( $items[ $product_index ] ) ) {
			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result( true, 'ProductUpdated completed.', array( 'deleteFile' => true ) );
		}

		$product_data = $items[ $product_index ];
		if ( $this->should_skip_product_by_url( $product_data ) ) {
			$product_index++;

			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			if ( $product_index < count( $items ) ) {
				return $this->result(
					true,
					'ProductUpdated skipped excluded product; products remaining.',
					array(
						'deleteFile'    => false,
						'healthSkipped' => true,
						'skippedBecause'=> 'excluded_url',
					)
				);
			}

			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated skipped excluded product.',
				array(
					'deleteFile'    => true,
					'healthSkipped' => true,
					'skippedBecause'=> 'excluded_url',
				)
			);
		}

		$product_guid = $this->extract_product_guid( $product_data );

		if ( '' === $product_guid ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;
			$terminal = $product_index >= count( $items );
			if ( $terminal ) {
				unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
			}
			return $this->result(
				true,
				'ProductUpdated skipped malformed product without a stable GUID.',
				array(
					'deleteFile'     => $terminal,
					'healthSkipped'  => true,
					'skippedBecause' => 'missing_product_guid',
				)
			);
		}

		if ( $this->is_remote_product_trashed( $product_guid ) ) {
			$product_index++;
			$payload['_moboProductIndex'] = $product_index;
			$payload['_moboImageOffset']  = 0;

			if ( $product_index < count( $items ) ) {
				return $this->result(
					true,
					'ProductUpdated skipped trashed product; products remaining.',
					array(
						'deleteFile'    => false,
						'productGuid'   => $product_guid,
						'healthSkipped' => true,
						'skippedBecause'=> 'product_trashed',
					)
				);
			}

			unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

			return $this->result(
				true,
				'ProductUpdated skipped trashed product.',
				array(
					'deleteFile'    => true,
					'productGuid'   => $product_guid,
					'healthSkipped' => true,
					'skippedBecause'=> 'product_trashed',
				)
			);
		}

		$existing_product_id       = '' !== $product_guid ? $this->find_product_id_by_guid( $product_guid ) : 0;
		$source_version            = $this->extract_source_version( $product_data, $payload );
		$source_revision           = $this->extract_source_revision( $product_data, $payload );
		if ( $existing_product_id > 0 && '' !== $source_version ) {
			$seen_version = sanitize_text_field( (string) get_post_meta( $existing_product_id, '_mobo_product_seen_event_version', true ) );
			$version_cmp  = $this->compare_source_versions( $source_version, $seen_version );
			if ( -1 === $version_cmp ) {
				$product_index++;
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = 0;
				$terminal = $product_index >= count( $items );
				if ( $terminal ) {
					unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
				}
				return $this->result( true, 'Stale ProductUpdated event version skipped.', array( 'deleteFile' => $terminal, 'productGuid' => $product_guid, 'productId' => $existing_product_id, 'sourceRevision' => $source_revision, 'sourceVersion' => $source_version, 'staleSkipped' => true ) );
			}
			if ( ( null !== $version_cmp || '' === $seen_version ) && ! $this->persist_post_meta_verified( $existing_product_id, '_mobo_product_seen_event_version', $source_version ) ) {
				return $this->result( false, 'Product event-version watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		if ( $existing_product_id > 0 && $source_revision > 0 ) {
			$seen_revision = absint( get_post_meta( $existing_product_id, '_mobo_product_seen_revision', true ) );
			if ( $seen_revision > $source_revision ) {
				$product_index++;
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = 0;
				$terminal = $product_index >= count( $items );
				if ( $terminal ) {
					unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );
				}
				return $this->result( true, 'Stale ProductUpdated revision skipped.', array( 'deleteFile' => $terminal, 'productGuid' => $product_guid, 'productId' => $existing_product_id, 'sourceRevision' => $source_revision, 'staleSkipped' => true ) );
			}
			if ( ! $this->persist_post_meta_verified( $existing_product_id, '_mobo_product_seen_revision', $source_revision ) ) {
				return $this->result( false, 'Product revision watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		$was_existing             = $existing_product_id > 0;
		$attribute_structure_changed = $was_existing && $this->product_attributes_field_present( $product_data ) && $this->product_attribute_structure_changed(
			wc_get_product( $existing_product_id ),
			$this->get_value( $product_data, 'attributes', array() )
		);

		/* Keep the current variations until a complete authoritative replacement
		 * snapshot is available. ProductUpdated alone is not proof that all variant
		 * data has arrived, so deleting children here can make a product unsellable
		 * when the follow-up variant event is delayed or lost. */
		$product_id = $this->upsert_parent_product( $product_data, true );
		if ( $product_id > 0 && ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'product', $source_version, $source_revision, true ) ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result( false, 'Product ordering watermark could not be committed after mutation; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
		}

		if ( $product_id > 0 && ! empty( $payload['_moboWebhookForegroundContext'] ) && ! $this->mark_webhook_product_applied( $product_id ) ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result( false, 'Foreground webhook ordering fence could not be persisted; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
		}

		if ( $attribute_structure_changed && $product_id > 0 ) {
			update_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', '1' );
			update_post_meta( $product_id, '_mobo_desired_state_rebuild_requested_at', gmdate( 'c' ) );
			if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $product_id );
			}
		}

		if ( $product_id <= 0 ) {
			return $this->result(
				false,
				'ProductUpdated could not durably create/update the product; event will retry without advancing the batch cursor.',
				array(
					'deleteFile'   => false,
					'productGuid'  => $product_guid,
					'retryable'    => true,
				)
			);
		}

		$product_images = $this->get_product_images_from_payload( $product_data );
		$images_dirty   = ( ! $was_existing || $this->product_images_field_present( $product_data ) )
			&& ( ! $was_existing || $this->rules->should_update_images() )
			&& $this->product_images_need_processing( $product_id, $product_images, ! $was_existing );

		if ( $images_dirty ) {
			$image_result = $this->image_sync->process_images(
				$product_id,
				$product_images,
				$image_offset,
				false
			);

			if ( ! empty( $image_result['error'] ) ) {
				return $this->result(
					true,
					'ProductUpdated منتظر ثبت امن Desired State تصاویر در صف دیتابیس است.',
					array(
						'deleteFile'   => false,
						'deferSeconds' => 60,
						'productId'    => $product_id,
						'error'        => sanitize_text_field( (string) $image_result['error'] ),
					)
				);
			}

			if ( empty( $image_result['done'] ) ) {
				$payload['_moboProductIndex'] = $product_index;
				$payload['_moboImageOffset']  = absint( $image_result['nextOffset'] );

				return $this->result(
					true,
					'ProductUpdated partially processed; images remaining.',
					array(
						'deleteFile' => false,
						'productId'  => $product_id,
						'offset'     => absint( $image_result['nextOffset'] ),
					)
				);
			}

			if ( ! empty( $image_result['pending'] ) ) {
				if ( ! $this->mark_product_images_pending( $product_id, $product_images ) ) {
					return $this->result( false, 'ثبت watermark در انتظار تصاویر محصول ناموفق بود؛ event برای retry حفظ شد.', array( 'deleteFile' => false, 'productId' => $product_id, 'retryable' => true ) );
				}
			} elseif ( ! $this->mark_product_images_converged( $product_id, $product_images ) ) {
				return $this->result( false, 'ثبت watermark نهایی تصاویر محصول ناموفق بود؛ event برای retry حفظ شد.', array( 'deleteFile' => false, 'productId' => $product_id, 'retryable' => true ) );
			}
		}

		/* ProductUpdated must leave a simple product immediately purchasable. */
		if ( ! $this->product_payload_can_have_variants( $product_data, $product_id ) ) {
			$api            = new Mobo_Core_API_Client();
			$simple_variant = $this->sync_simple_product_variant_from_api( $api, $product_guid, $product_id, 'product-updated-' . gmdate( 'YmdHis' ) );

			if ( is_wp_error( $simple_variant ) ) {
				return $this->result(
					true,
					'ProductUpdated منتظر دریافت شناسه قابل خرید محصول ساده است.',
					array(
						'deleteFile'   => false,
						'deferSeconds' => 60,
						'productId'    => $product_id,
						'error'        => $simple_variant->get_error_message(),
					)
				);
			}
		}

		if ( ! $this->product_payload_can_have_variants( $product_data, $product_id ) ) {
			if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( true, 'ProductUpdated منتظر ثبت پایدار Product Map است.', array( 'deleteFile' => false, 'deferSeconds' => 60, 'productGuid' => $product_guid, 'productId' => $product_id ) );
			}
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
				return $this->result( true, 'ProductUpdated منتظر ثبت پایدار completion marker است.', array( 'deleteFile' => false, 'deferSeconds' => 60, 'productGuid' => $product_guid, 'productId' => $product_id ) );
			}
		}

		$product_index++;
		$payload['_moboProductIndex'] = $product_index;
		$payload['_moboImageOffset']  = 0;

		if ( $product_index < count( $items ) ) {
			return $this->result( true, 'ProductUpdated partially processed; products remaining.', array( 'deleteFile' => false ) );
		}

		unset( $payload['_moboProductIndex'], $payload['_moboImageOffset'] );

		return $this->result( true, 'ProductUpdated processed.', array( 'deleteFile' => true ) );
	}

	public function process_update_variant_payload( $payload, $from_manual_sync = false ) {
		if ( class_exists( 'Mobo_Core_Cache_Mutation_Guard' ) ) {
			return Mobo_Core_Cache_Mutation_Guard::run(
				function () use ( $payload, $from_manual_sync ) {
					return $this->process_update_variant_payload_guarded( $payload, $from_manual_sync );
				},
				( $from_manual_sync || $this->is_repair_mode() ) ? 'repair-variant' : 'webhook-variant'
			);
		}

		return $this->process_update_variant_payload_guarded( $payload, $from_manual_sync );
	}

	/**
	 * Process UpdateVariant after entering the Mobo cache mutation scope.
	 *
	 * @param array $payload Payload.
	 * @param bool  $from_manual_sync Whether called by manual Sync/Repair.
	 * @return array
	 */
	private function process_update_variant_payload_guarded( $payload, $from_manual_sync = false ) {
		$product_guid = $this->extract_product_guid_from_update_variant_payload( $payload );

		if ( '' !== $product_guid && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			if ( ! $from_manual_sync && Mobo_Core_Product_Concurrency::is_manual_sync_busy_for_product( $product_guid ) ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'manual_sync_active', 45 );
			}

			$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );

			if ( false === $lock ) {
				return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'product_lock_busy', 30 );
			}

			try {
				return $this->process_update_variant_payload_unlocked( $payload, $lock );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $lock );
			}
		}

		return $this->process_update_variant_payload_unlocked( $payload );
	}

	private function process_update_variant_payload_unlocked( $payload, $product_lock = false ) {
		if ( ! is_array( $payload ) ) {
			return $this->result( false, 'Invalid UpdateVariant payload.' );
		}

		/*
		 * Be tolerant of all known MoboCore shapes:
		 * 1) VariantSyncPagedResult: { productId, data: [...] }
		 * 2) EventModel wrapper: { event: UpdateVariant, data: { productId, data: [...] } }
		 * 3) Legacy list wrapper: { data: [ { productId, ... } ] }
		 * 4) Variant-specific metadata: { variantId/entityGuid } where parent can be found from product_map.
		 */
		$inner_data = $this->get_value( $payload, 'data', null );
		if ( is_array( $inner_data ) && ! $this->is_list_array( $inner_data ) ) {
			$inner_product_guid = $this->extract_product_guid( $inner_data );

			$inner_variants = $this->get_value( $inner_data, 'data', null );
			if ( '' !== $inner_product_guid || is_array( $inner_variants ) ) {
				$payload = array_merge( $payload, $inner_data );
			}
		}

		$product_guid = $this->extract_product_guid( $payload );
		$sync_id      = sanitize_text_field( (string) $this->get_value( $payload, 'syncId', '' ) );
		$page_number  = max( 1, absint( $this->get_value( $payload, 'pageNumber', 1 ) ) );
		$has_more     = $this->get_value( $payload, 'hasMore', false );
		$is_last_page = $this->get_value( $payload, 'isLastPage', null );
		$variant_collection_inspection = Mobo_Core_Payload_Field_Policy::inspect( $payload, array( 'data', 'Data' ) );
		$variant_collection_present    = ! empty( $variant_collection_inspection['present'] );
		$variant_collection_is_list    = $variant_collection_present && is_array( $variant_collection_inspection['value'] ) && $this->is_list_array( $variant_collection_inspection['value'] );
		$variants     = $this->get_value( $payload, 'data', array() );

		if ( is_array( $variants ) && ! $this->is_list_array( $variants ) ) {
			$nested_variants = $this->get_value( $variants, 'data', null );
			if ( is_array( $nested_variants ) ) {
				$variants = $nested_variants;
			} elseif ( $this->looks_like_variant_payload( $variants ) ) {
				$variants = array( $variants );
			}
		}

		if ( ! is_array( $variants ) || ! $this->is_list_array( $variants ) ) {
			$variants = array();
		}

		if ( '' === $product_guid && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$product_guid = $this->extract_product_guid( $variants[0] );
		}


		if ( '' === $product_guid ) {
			$variant_guid = $this->extract_variant_guid( $payload );
			if ( '' !== $variant_guid ) {
				$product_guid = $this->find_parent_product_guid_by_variant_guid( $variant_guid );
			}
		}

		if ( '' === $product_guid ) {
			return $this->result( false, $this->build_missing_product_id_message( $payload, $variants ) );
		}

		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_product_guid_excluded( $product_guid ) ) {
			return $this->result(
				true,
				'محصول مادر در فهرست عدم همگام‌سازی است؛ رویداد تنوع بدون mutation خاتمه یافت.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'excluded_url',
				)
			);
		}

		foreach ( $variants as $variant_index => $variant_data ) {
			if ( is_array( $variant_data ) ) {
				$variant_product_guid = $this->extract_product_guid( $variant_data );

				if ( '' === $variant_product_guid ) {
					$variants[ $variant_index ]['productId'] = $product_guid;
				}
			}
		}

		if ( $this->is_remote_product_trashed( $product_guid ) ) {
			return $this->result(
				true,
				'محصول مادر در سطل زباله وردپرس است؛ تنوع‌های آن رد شدند.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'parent_trashed',
				)
			);
		}

		if ( '' === $sync_id ) {
			$sync_id = 'no-sync-id-' . $product_guid;
		}

		$product_id = $this->find_product_id_by_guid( $product_guid );

		if ( $product_id > 0 && class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $product_id ) ) {
			return $this->result(
				true,
				'محصول مادر در فهرست عدم همگام‌سازی است؛ رویداد تنوع بدون mutation خاتمه یافت.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'healthSkipped'  => true,
					'skippedBecause' => 'excluded_url',
				)
			);
		}

		if ( $product_id <= 0 ) {
			return $this->result(
				true,
				'محصول مادر هنوز ساخته نشده است؛ پردازش تنوع‌ها برای تلاش بعدی به تعویق افتاد.',
				array(
					'deleteFile'       => false,
					'deferSeconds'     => 120,
					'waitingForParent' => true,
					'productGuid'      => $product_guid,
				)
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $this->result( false, 'Invalid parent product.' );
		}

		$variant_list_authoritative = $this->is_authoritative_variant_list_payload( $payload, $variants );
		$source_version             = $this->extract_source_version( $payload, $payload );
		$source_revision            = $this->extract_source_revision( $payload, $payload );

		$attribute_inspection       = Mobo_Core_Payload_Field_Policy::inspect( $payload, Mobo_Core_Payload_Field_Policy::attribute_aliases() );
		$desired_attributes_present = ! empty( $attribute_inspection['present'] );
		$desired_attributes_valid   = ! $desired_attributes_present || is_array( $attribute_inspection['value'] );
		$desired_attributes         = $desired_attributes_present && is_array( $attribute_inspection['value'] ) ? $attribute_inspection['value'] : array();

		/*
		 * A destructive topology snapshot requires explicit, well-formed evidence for
		 * both collections. Missing attributes preserves topology; malformed attributes
		 * or malformed/absent snapshot data fail closed before any watermark/mutation.
		 */
		if ( $variant_list_authoritative && ( ! $variant_collection_present || ! $variant_collection_is_list ) ) {
			return $this->result( false, 'Authoritative Variant snapshot data is absent or malformed; topology mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}
		if ( $variant_list_authoritative && $desired_attributes_present && ! $desired_attributes_valid ) {
			return $this->result( false, 'Authoritative Variant attributes are malformed; topology mutation was blocked.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}
		$topology_authoritative = $variant_list_authoritative && $variant_collection_is_list && $desired_attributes_present && $desired_attributes_valid;

		/* Validate every parent ordering fence before persisting either watermark. */
		if ( $variant_list_authoritative ) {
			$seen_event_version = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_seen_event_version', true ) );
			$seen_revision      = absint( get_post_meta( $product_id, '_mobo_variant_seen_revision', true ) );
			$version_cmp        = '' !== $source_version ? $this->compare_source_versions( $source_version, $seen_event_version ) : null;
			if ( '' !== $source_version && -1 === $version_cmp ) {
				return $this->result( true, 'Stale authoritative UpdateVariant event version skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'sourceVersion' => $source_version, 'staleSkipped' => true ) );
			}
			if ( $source_revision > 0 && $seen_revision > $source_revision ) {
				return $this->result( true, 'Stale authoritative UpdateVariant revision skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'staleSkipped' => true ) );
			}
			if ( '' !== $source_version && ( null !== $version_cmp || '' === $seen_event_version ) && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_event_version', $source_version ) ) {
				return $this->result( false, 'Variant snapshot event-version watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_revision', $source_revision ) ) {
				return $this->result( false, 'Variant snapshot revision watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		if ( $topology_authoritative ) {
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'Authoritative Variant topology crash marker could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		$desired_attribute_count = count( $this->build_product_attributes( $desired_attributes ) );
		if ( $topology_authoritative && 0 === $desired_attribute_count ) {
			$preflight_simple = $this->preflight_simple_variation_cleanup( $product_id );
			if ( is_wp_error( $preflight_simple ) ) {
				return $this->result( false, $preflight_simple->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}
		$variant_total = max( count( $variants ), absint( $this->get_value( $payload, 'totalCount', count( $variants ) ) ) );

		$is_last_page = null === $is_last_page ? ! $this->to_bool( $has_more ) : $this->to_bool( $is_last_page );

		/*
		 * Multi-page authoritative snapshots should not purge/warm the same product
		 * after every intermediate variants page. The final page performs one parent
		 * sync and one targeted cache invalidation for the converged storefront state.
		 */
		if ( $variant_list_authoritative && ! $is_last_page && class_exists( 'Mobo_Core_Cache_Purger' ) && method_exists( 'Mobo_Core_Cache_Purger', 'suppress_product_for_request' ) ) {
			Mobo_Core_Cache_Purger::suppress_product_for_request( $product_id );
		}

		$parent_mutated = false;

		if ( $topology_authoritative ) {
			$structure_changed = $this->product_attribute_structure_changed( $product, $desired_attributes );

			if ( $structure_changed ) {
				/* Preserve old children until this authoritative snapshot reaches its
				 * validated terminal page. finalize_missing_variants() then deletes only
				 * children absent from the complete replacement set. */
				update_post_meta( $product_id, '_mobo_desired_state_rebuild_pending', '1' );
				update_post_meta( $product_id, '_mobo_desired_state_rebuild_requested_at', gmdate( 'c' ) );
				$parent_mutated = true;
			}

			if ( $this->apply_desired_product_attributes( $product_id, $desired_attributes ) ) {
				$parent_mutated = true;
			}
			$product = wc_get_product( $product_id );
		}

		/*
		 * A simple WooCommerce product maps its one Mobo storefront Variant onto
		 * the parent product itself. An authoritative snapshot with no variation
		 * attributes is also allowed to convert an old variable product back to simple.
		 */
		$authoritative_simple_snapshot = $topology_authoritative
			&& 0 === $desired_attribute_count;

		if ( 1 === count( $variants ) && $variant_total <= 1 && ! $this->variant_has_selection_attributes( $variants[0] ) && ( $authoritative_simple_snapshot || $product instanceof WC_Product_Simple || '1' === (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true ) ) ) {
			if ( ! $variant_list_authoritative && ( '' !== $source_version || $source_revision > 0 ) ) {
				$simple_seen_version  = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_variant_seen_event_version', true ) );
				$simple_seen_revision = absint( get_post_meta( $product_id, '_mobo_variant_seen_revision', true ) );
				$simple_version_cmp   = '' !== $source_version ? $this->compare_source_versions( $source_version, $simple_seen_version ) : null;
				if ( '' !== $source_version && -1 === $simple_version_cmp ) {
					return $this->result( true, 'Stale simple UpdateVariant event version skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'sourceVersion' => $source_version, 'staleSkipped' => true ) );
				}
				if ( $source_revision > 0 && $simple_seen_revision > $source_revision ) {
					return $this->result( true, 'Stale simple UpdateVariant revision skipped.', array( 'deleteFile' => true, 'productGuid' => $product_guid, 'productId' => $product_id, 'sourceRevision' => $source_revision, 'staleSkipped' => true ) );
				}
				if ( '' !== $source_version && ( null !== $simple_version_cmp || '' === $simple_seen_version ) && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_event_version', $source_version ) ) {
					return $this->result( false, 'Simple-variant event-version watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
				if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $product_id, '_mobo_variant_seen_revision', $source_revision ) ) {
					return $this->result( false, 'Simple-variant revision watermark could not be persisted; mutation was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
			}


			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				return $this->result( false, 'Simple-product crash marker could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'ثبت Product Map پیش از تغییر محصول ساده ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$simple_result = $this->apply_simple_variant_to_product( $product_id, $variants[0], $product_guid );

			if ( is_wp_error( $simple_result ) ) {
				return $this->result( false, $simple_result->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid ) );
			}

			if ( $authoritative_simple_snapshot ) {
				if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
					$mapping_error = '';
					$this->product_map->delete_variations_for_parent( $product_guid, array(), $mapping_error );
					if ( '' !== $mapping_error ) {
						return $this->result(
							false,
							'پاک‌سازی نگاشت تنوع‌های قدیمی پس از تبدیل محصول به ساده ناموفق بود و در اجرای بعد دوباره تلاش می‌شود.',
							array( 'deleteFile' => false, 'productGuid' => $product_guid, 'mappingError' => $mapping_error )
						);
					}
				}
				delete_post_meta( $product_id, '_mobo_desired_state_rebuild_pending' );
				delete_post_meta( $product_id, '_mobo_missing_variants_finalize_reason' );
				delete_post_meta( $product_id, '_mobo_missing_variants_seen_count' );
				delete_post_meta( $product_id, '_mobo_missing_variants_expected_count' );
				update_post_meta( $product_id, '_mobo_desired_state_last_completed_at', gmdate( 'c' ) );
				if ( ! $this->clear_seen_variants( $product_guid, $sync_id ) ) {
					return $this->result( false, 'Authoritative variation seen-set could not be cleared durably after finalization; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
			}

			if ( ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'variant', $source_version, $source_revision, true ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( false, 'Simple-variant ordering watermark could not be committed after mutation; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( false, 'ثبت نهایی Product Map محصول ساده ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
				return $this->result( false, 'Simple-product completion marker could not be persisted; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}

			if ( ! empty( $payload['_moboWebhookForegroundContext'] ) && ! $this->mark_webhook_product_applied( $product_id ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'Simple-product webhook ordering fence could not be persisted; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
			}

			return $this->result(
				true,
				'شناسه قابل خرید محصول ساده بروزرسانی شد.',
				array(
					'deleteFile'       => true,
					'productGuid'      => $product_guid,
					'simpleProduct'    => true,
					'portalVariantId'  => isset( $simple_result['portalVariantId'] ) ? absint( $simple_result['portalVariantId'] ) : 0,
				)
			);
		}

		/* A delta can arrive after a newer delta/snapshot has already converged.
		 * Prove that the entire page is stale before touching parent topology;
		 * otherwise an old delta could convert a newer simple parent back to
		 * variable even though every child mutation is subsequently skipped. */
		if ( ! $variant_list_authoritative && $this->delta_variant_payload_is_fully_stale( $product_id, $variants, $source_version, $source_revision ) ) {
			return $this->result(
				true,
				'Stale UpdateVariant delta skipped before parent mutation.',
				array(
					'deleteFile'     => true,
					'productGuid'    => $product_guid,
					'productId'      => $product_id,
					'sourceRevision' => $source_revision,
					'sourceVersion'  => $source_version,
					'staleSkipped'   => true,
				)
			);
		}

		if ( ! $variant_list_authoritative ) {
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				return $this->result( false, 'Variant delta crash marker could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}

		/*
		 * Delta variant webhooks are coalesced at the parent level. Suppress the
		 * WooCommerce CRUD purge generated by each child save; the parent-finalize
		 * queue will perform one recalculation and one targeted purge after this
		 * webhook batch. Authoritative snapshots keep their existing final-page rule.
		 */
		if ( ! $variant_list_authoritative && class_exists( 'Mobo_Core_Cache_Purger' ) && method_exists( 'Mobo_Core_Cache_Purger', 'suppress_product_for_request' ) ) {
			Mobo_Core_Cache_Purger::suppress_product_for_request( $product_id );
		}

		if ( $topology_authoritative && ! $this->initialize_seen_variants( $product_guid, $sync_id ) ) {
			return $this->result( false, 'Could not durably initialize the authoritative variation seen-set; snapshot finalization was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$before_product_type = $product instanceof WC_Product ? (string) $product->get_type() : '';
		$type_result = ( $variant_list_authoritative && ! $topology_authoritative )
			? $product
			: $this->ensure_product_type_for_variants( $product_id, ( $variant_total > 0 || $desired_attribute_count > 0 ) ? max( 1, $variant_total ) : 0 );
		if ( is_wp_error( $type_result ) ) {
			return $this->result( false, $type_result->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product && $before_product_type !== (string) $product->get_type() ) {
			$parent_mutated = true;
		}

		if ( $topology_authoritative && 0 === $desired_attribute_count && 0 === $variant_total && empty( $variants ) ) {
			$empty_simple = $this->apply_authoritative_simple_without_purchase_variant( $product_id, $product_guid );
			if ( is_wp_error( $empty_simple ) ) {
				return $this->result( false, $empty_simple->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$product = wc_get_product( $product_id );
			$parent_mutated = true;
		}

		/* Prime GUID->ID, post and meta caches once for this variant page. */
		$this->prime_variation_page_context( $product_id, $variants );

		$updated            = 0;
		$unchanged          = 0;
		$skipped            = 0;
		$stale_skipped      = 0;
		$seen_variant_guids = array();
		$last_lock_renewal  = time();

		foreach ( $variants as $variant_data ) {
			/*
			 * Very large/slow variant pages may outlive the initial runtime lease.
			 * MySQL GET_LOCK still serializes writers, but the runtime heartbeat is
			 * what the upgrade barrier can observe. Renew at a coarse interval so a
			 * live mutation cannot become invisible during a deploy drain.
			 */
			if ( false !== $product_lock && ( time() - $last_lock_renewal ) >= 45 && class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				if ( ! Mobo_Core_Product_Concurrency::renew_product_lock( $product_lock, 180 ) ) {
					return Mobo_Core_Product_Concurrency::defer_result( $product_guid, 'product_lock_lease_lost', 45 );
				}
				$last_lock_renewal = time();
			}

			if ( ! is_array( $variant_data ) ) {
				$skipped++;
				continue;
			}

			$incoming_variant_guid = $this->extract_variant_guid( $variant_data );
			if ( ! $variant_list_authoritative && ( '' !== $source_version || $source_revision > 0 ) && '' !== $incoming_variant_guid ) {
				$existing_variation_id = $this->find_variation_id_by_guid( $incoming_variant_guid );
				if ( $existing_variation_id > 0 && absint( wp_get_post_parent_id( $existing_variation_id ) ) === $product_id ) {
					$variation_seen_version  = sanitize_text_field( (string) get_post_meta( $existing_variation_id, '_mobo_variant_seen_event_version', true ) );
					$variation_seen_revision = absint( get_post_meta( $existing_variation_id, '_mobo_variant_seen_revision', true ) );
					$variation_version_cmp   = '' !== $source_version ? $this->compare_source_versions( $source_version, $variation_seen_version ) : null;
					if ( ( '' !== $source_version && -1 === $variation_version_cmp ) || ( $source_revision > 0 && $variation_seen_revision > $source_revision ) ) {
						$stale_skipped++;
						$unchanged++;
						continue;
					}
					if ( '' !== $source_version && ( null !== $variation_version_cmp || '' === $variation_seen_version ) && ! $this->persist_post_meta_verified( $existing_variation_id, '_mobo_variant_seen_event_version', $source_version ) ) {
						return $this->result( false, 'Variation event-version watermark could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
					}
					if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $existing_variation_id, '_mobo_variant_seen_revision', $source_revision ) ) {
						return $this->result( false, 'Variation revision watermark could not be persisted before mutation.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
					}
				}
			}


			$variation_result = $this->upsert_variation( $product, $variant_data );
			$variation_id     = is_array( $variation_result ) && isset( $variation_result['id'] ) ? absint( $variation_result['id'] ) : absint( $variation_result );

			if ( $variation_id > 0 ) {
				$variant_guid = $this->extract_variant_guid( $variant_data );

				/* Every successful child mutation, including authoritative snapshots, must
				 * persist the child watermark. Otherwise a late pre-snapshot delta can pass
				 * the per-variation stale guard and overwrite the newer snapshot state. */
				if ( ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $variation_id, 'variant', $source_version, $source_revision, true ) ) {
					$this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' );
					if ( '' !== $variant_guid ) {
						$this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true );
					}
					return $this->result( false, 'Variation ordering watermark could not be committed after mutation; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}

				if ( $topology_authoritative && '' !== $variant_guid ) {
					$seen_variant_guids[ $variant_guid ] = true;
				}

				if ( is_array( $variation_result ) && empty( $variation_result['changed'] ) ) {
					$unchanged++;
				} else {
					$updated++;
				}
			} else {
				$intentional_trash_skip = is_array( $variation_result ) && ! empty( $variation_result['skipped_trash'] );
				if ( $intentional_trash_skip ) {
					/* A locally trashed Mobo variation is an explicit retention policy, not an
					 * incomplete source page. Count its remote identity as seen so an
					 * authoritative snapshot can finish without restoring it or retrying forever. */
					if ( $topology_authoritative && '' !== $incoming_variant_guid ) {
						$seen_variant_guids[ $incoming_variant_guid ] = true;
					}
					$unchanged++;
				} else {
					$skipped++;
				}
			}
		}

		if ( $topology_authoritative && ! empty( $seen_variant_guids ) && ! $this->mark_variants_seen_bulk( $product_guid, $sync_id, array_keys( $seen_variant_guids ) ) ) {
			return $this->result( false, 'Could not durably persist the authoritative variation seen-set; destructive finalization was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
		}

		$deleted_missing_variants          = 0;
		$authoritative_snapshot_incomplete = false;

		if ( $topology_authoritative ) {
			$expected_variant_total = absint( $this->get_value( $payload, 'totalCount', 0 ) );
			$seen_variant_count     = $this->count_seen_variants( $product_guid, $sync_id );
			if ( $seen_variant_count < 0 ) {
				return $this->result( false, 'Authoritative variation seen-set could not be read back; snapshot finalization was deferred.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			$snapshot_complete      = ( 0 === $expected_variant_total && $is_last_page )
				|| ( $expected_variant_total > 0 && $seen_variant_count >= $expected_variant_total );

			if ( $snapshot_complete ) {
				$finalize_result = $this->finalize_missing_variants( $product, $product_guid, $sync_id );
				if ( is_wp_error( $finalize_result ) ) {
					update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
					update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'local_variation_delete_failed' );
					return $this->result( false, $finalize_result->get_error_message(), array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
				$deleted_missing_variants = absint( $finalize_result );
				delete_post_meta( $product_id, '_mobo_desired_state_rebuild_pending' );
				delete_post_meta( $product_id, '_mobo_missing_variants_finalize_reason' );
				delete_post_meta( $product_id, '_mobo_missing_variants_seen_count' );
				delete_post_meta( $product_id, '_mobo_missing_variants_expected_count' );
				update_post_meta( $product_id, '_mobo_desired_state_last_completed_at', gmdate( 'c' ) );
				if ( ! $this->clear_seen_variants( $product_guid, $sync_id ) ) {
					return $this->result( false, 'Authoritative variation seen-set could not be cleared durably after finalization; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
				}
			} elseif ( $is_last_page ) {
				$authoritative_snapshot_incomplete = true;
				/*
				 * Keep the accumulated set. A delayed earlier page from the same sync can
				 * still complete the snapshot and safely trigger finalization.
				 */
				update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
				update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'authoritative_snapshot_incomplete' );
				update_post_meta( $product_id, '_mobo_missing_variants_seen_count', $seen_variant_count );
				update_post_meta( $product_id, '_mobo_missing_variants_expected_count', $expected_variant_total );
			}
		} elseif ( $is_last_page ) {
			update_post_meta( $product_id, '_mobo_missing_variants_finalize_skipped_at', gmdate( 'c' ) );
			update_post_meta( $product_id, '_mobo_missing_variants_finalize_reason', 'variant_delta_webhook_not_authoritative' );
			update_post_meta( $product_id, '_mobo_missing_variants_payload_event', sanitize_text_field( (string) $this->get_value( $payload, 'event', '' ) ) );
		}

		if ( $parent_mutated || $updated > 0 || $deleted_missing_variants > 0 ) {
			if ( ! $this->persist_post_meta_verified( $product_id, '_mobo_parent_sync_pending', '1' ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'Parent-finalize checkpoint could not be persisted after Variant mutation; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
			}
		}

		$parent_sync_performed = false;
		$parent_sync_deferred  = false;
		$parent_sync_pending   = '1' === sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_parent_sync_pending', true ) );

		if ( $is_last_page && $parent_sync_pending ) {
			if ( $variant_list_authoritative && is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
				WC_Product_Variable::sync( $product_id );
				delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
				wc_delete_product_transients( $product_id );
				if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
					Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-snapshot-converged' );
				}
				$parent_sync_performed = true;
			} elseif ( ! $variant_list_authoritative && class_exists( 'Mobo_Core_Parent_Finalize_Queue' ) ) {
				$queued = Mobo_Core_Parent_Finalize_Queue::enqueue( $product_id, $product_guid, 'variant-delta' );
				$parent_sync_deferred = is_array( $queued ) && ! empty( $queued['success'] );

				/*
				 * A transient option-lock failure must not leave the parent permanently
				 * dirty with this request's cache purge suppressed. Fall back to the old
				 * immediate convergence path only when enqueue itself failed.
				 */
				if ( ! $parent_sync_deferred && is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
					WC_Product_Variable::sync( $product_id );
					delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
					wc_delete_product_transients( $product_id );
					if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
						if ( method_exists( 'Mobo_Core_Cache_Purger', 'unsuppress_product_for_request' ) ) {
							Mobo_Core_Cache_Purger::unsuppress_product_for_request( $product_id );
						}
						Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-delta-converged-fallback' );
					}
					$parent_sync_performed = true;
				}
			} elseif ( is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
				/* Compatibility fallback if the coalescing queue is unavailable. */
				WC_Product_Variable::sync( $product_id );
				delete_post_meta( $product_id, '_mobo_parent_sync_pending' );
				wc_delete_product_transients( $product_id );
				if ( class_exists( 'Mobo_Core_Cache_Purger' ) ) {
					if ( method_exists( 'Mobo_Core_Cache_Purger', 'unsuppress_product_for_request' ) ) {
						Mobo_Core_Cache_Purger::unsuppress_product_for_request( $product_id );
					}
					Mobo_Core_Cache_Purger::queue_product( $product_id, 'variant-delta-converged' );
				}
				$parent_sync_performed = true;
			}
		}

		if ( ! empty( $payload['_moboWebhookForegroundContext'] ) && ! $this->mark_webhook_product_applied( $product_id ) ) {
			$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result( false, 'Variant webhook ordering fence could not be persisted; event will retry idempotently.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'productId' => $product_id, 'retryable' => true ) );
		}

		if ( $skipped > 0 || $authoritative_snapshot_incomplete ) {
			$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			return $this->result(
				true,
				'تنوع‌ها هنوز کامل همگام نشده‌اند و event برای retry حفظ شد.',
				array(
					'deleteFile' => false,
					'deferSeconds' => 60,
					'productGuid' => $product_guid,
					'pageNumber' => $page_number,
					'skipped' => $skipped,
					'authoritativeSnapshotIncomplete' => $authoritative_snapshot_incomplete,
				)
			);
		}

		if ( $is_last_page ) {
			/* Ordering evidence is part of the completion commit. Persist it before
			 * advertising a clean Product Map / crash marker so a process death can
			 * never acknowledge a snapshot whose stale guard was not durable. */
			if ( $variant_list_authoritative && ( '' !== $source_version || $source_revision > 0 ) && ! $this->persist_ordering_watermarks( $product_id, 'variant', $source_version, $source_revision, true ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'Authoritative Variant ordering watermark could not be committed; event will retry.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				return $this->result( false, 'ثبت نهایی Product Map پس از Variant Sync ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
				$this->upsert_product_map( $product_guid, $product_id, true );
				return $this->result( false, 'ثبت نهایی completion marker پس از Variant Sync ناموفق بود.', array( 'deleteFile' => false, 'productGuid' => $product_guid, 'retryable' => true ) );
			}
		}

		$message = sprintf(
			'تنوع‌های محصول پردازش شد. محصول: %s، صفحه: %d، بروزرسانی: %d، بدون تغییر: %d، رد شده: %d، stale: %d',
			$product_guid,
			$page_number,
			$updated,
			$unchanged,
			$skipped,
			$stale_skipped
		);

		return $this->result(
			true,
			$message,
			array(
				'deleteFile'  => true,
				'productGuid' => $product_guid,
				'pageNumber'  => $page_number,
				'updated'     => $updated,
				'unchanged'   => $unchanged,
				'skipped'              => $skipped,
				'staleSkipped'         => $stale_skipped,
				'sourceRevision'       => $source_revision,
				'sourceVersion'        => $source_version,
				'isLastPage'           => $is_last_page,
				'parentSyncPerformed'  => $parent_sync_performed,
				'parentSyncDeferred'   => $parent_sync_deferred || ( ! $is_last_page && ( $parent_mutated || $updated > 0 ) ),
				'deletedMissingVariants' => absint( $deleted_missing_variants ),
			)
		);
	}


	/**
	 * Fetch remote categories only for admin mapping.
	 *
	 * This method never creates or updates WooCommerce product_cat terms and
	 * does not respect/change the automatic category update schedule.
	 *
	 * @param string $sync_id Optional sync ID.
	 * @return array
	 */
	public function preload_categories_for_mapping( $sync_id = '' ) {
		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = 'category-mapping-' . gmdate( 'YmdHis' );
		}

		$api      = new Mobo_Core_API_Client();
		$response = $api->get_categories( $sync_id );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				$response->get_error_message(),
				array(
					'skipped' => false,
					'synced'  => false,
				)
			);
		}

		$categories = $this->normalize_categories_api_response( $response );
		if ( is_wp_error( $categories ) ) {
			return $this->result( false, $categories->get_error_message(), array( 'skipped' => false, 'synced' => false ) );
		}

		$category_result = $this->category_sync->load_categories_for_mapping_payload( $categories );

		if ( empty( $category_result['complete'] ) || absint( $category_result['skipped'] ) > 0 ) {
			return $this->result(
				false,
				sprintf( 'Snapshot دسته‌بندی برای نگاشت ناقص بود؛ %d ردیف نامعتبر یا حل‌نشده بود و زمان موفقیت جلو نرفت.', absint( $category_result['skipped'] ) ),
				array(
					'skipped'      => false,
					'synced'       => false,
					'mappingOnly'  => true,
					'created'      => absint( $category_result['created'] ),
					'updated'      => absint( $category_result['updated'] ),
					'skippedItems' => absint( $category_result['skipped'] ),
				)
			);
		}

		update_option( 'mobo_core_categories_mapping_loaded_at', time(), false );

		return $this->result(
			true,
			'دسته‌بندی‌ها فقط برای نگاشت لود شدند. هیچ دسته‌ای در ووکامرس ساخته یا بروزرسانی نشد.',
			array(
				'skipped'      => false,
				'synced'       => true,
				'mappingOnly'  => true,
				'created'      => absint( $category_result['created'] ),
				'updated'      => absint( $category_result['updated'] ),
				'skippedItems' => absint( $category_result['skipped'] ),
			)
		);
	}

	/**
	 * Ensure categories are synced if refresh interval has passed.
	 *
	 * This is intended to be called by C# through REST.
	 * It does not rely on WP-Cron.
	 *
	 * @param string $sync_id Optional sync ID.
	 * @param bool   $force Force sync.
	 * @return array
	 */
	public function ensure_categories_synced_if_due( $sync_id = '', $force = false ) {
		if ( ! $force && ! $this->rules->should_update_categories() ) {
			return $this->result(
				true,
				'آپدیت اتوماتیک دسته‌بندی‌ها غیرفعال است.',
				array(
					'skipped'  => true,
					'reason'   => 'disabled',
					'synced'   => false,
					'forced'   => (bool) $force,
				)
			);
		}

		$sync_id = sanitize_text_field( (string) $sync_id );

		if ( '' === $sync_id ) {
			$sync_id = 'category-refresh-' . gmdate( 'YmdHis' );
		}

		$last_sync_at = absint( get_option( 'mobo_core_categories_last_sync_at', 0 ) );

		$interval_hours = absint( get_option( 'mobo_core_categories_refresh_interval_hours', 12 ) );

		if ( $interval_hours <= 0 ) {
			$interval_hours = 12;
		}

		$interval_seconds = $interval_hours * HOUR_IN_SECONDS;
		$now              = time();

		if ( ! $force && $last_sync_at > 0 && ( $now - $last_sync_at ) < $interval_seconds ) {
			return $this->result(
				true,
				'هنوز زمان بروزرسانی دوره‌ای دسته‌بندی‌ها نرسیده است.',
				array(
					'skipped'        => true,
					'reason'         => 'not-due',
					'synced'         => false,
					'forced'         => false,
					'lastSyncAt'     => $last_sync_at,
					'nextSyncAt'     => $last_sync_at + $interval_seconds,
					'intervalHours'  => $interval_hours,
				)
			);
		}

		$api      = new Mobo_Core_API_Client();
		$response = $api->get_categories( $sync_id );

		if ( is_wp_error( $response ) ) {
			return $this->result(
				false,
				$response->get_error_message(),
				array(
					'skipped' => false,
					'synced'  => false,
					'forced'  => (bool) $force,
				)
			);
		}

		$categories = $this->normalize_categories_api_response( $response );
		if ( is_wp_error( $categories ) ) {
			return $this->result( false, $categories->get_error_message(), array( 'skipped' => false, 'synced' => false, 'forced' => (bool) $force ) );
		}
		$category_result = $this->category_sync->sync_categories_payload( $categories );

		if ( empty( $category_result['complete'] ) || absint( $category_result['skipped'] ) > 0 ) {
			return $this->result(
				false,
				sprintf( 'Snapshot دسته‌بندی ناقص بود؛ %d ردیف نامعتبر یا حل‌نشده بود. زمان آخرین Sync موفق تغییر نکرد.', absint( $category_result['skipped'] ) ),
				array(
					'skipped'       => false,
					'synced'        => false,
					'forced'        => (bool) $force,
					'lastSyncAt'    => $last_sync_at,
					'intervalHours' => $interval_hours,
					'created'       => absint( $category_result['created'] ),
					'updated'       => absint( $category_result['updated'] ),
					'skippedItems'  => absint( $category_result['skipped'] ),
				)
			);
		}

		update_option( 'mobo_core_categories_last_sync_at', $now, false );

		return $this->result(
			true,
			'دسته‌بندی‌ها بروزرسانی شدند.',
			array(
				'skipped'       => false,
				'synced'        => true,
				'forced'        => (bool) $force,
				'lastSyncAt'    => $now,
				'intervalHours' => $interval_hours,
				'created'       => absint( $category_result['created'] ),
				'updated'       => absint( $category_result['updated'] ),
				'skippedItems'  => absint( $category_result['skipped'] ),
			)
		);
	}

	/**
	 * Recover an image for one existing local product during Repair.
	 *
	 * @param array $state Manual sync state.
	 * @return array
	 */
	private function run_missing_image_repair_step( &$state ) {
		if ( ! class_exists( 'Mobo_Core_Missing_Image_Recovery' ) ) {
			$state['missingImageRecoveryComplete'] = true;
			$state['status']                       = 'done';
			$state['completedAt']                  = time();
			$state['lastError']                    = '';
			$state['lastMessage']                  = 'Repair کامل شد؛ سرویس بازیابی تصویر در دسترس نبود.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$recovery = new Mobo_Core_Missing_Image_Recovery();
		if ( ! $recovery->is_enabled() ) {
			$state['missingImageRecoveryComplete'] = true;
			$state['status']                       = 'done';
			$state['completedAt']                  = time();
			$state['lastError']                    = '';
			$state['lastMessage']                  = 'Repair کامل شد؛ بازیابی تصویر محصولات بدون عکس به دلیل غیرفعال بودن بروزرسانی تصاویر رد شد.';
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$cursor = absint( $state['missingImageRecoveryCursor'] ?? 0 );
		$batch  = $recovery->get_candidate_batch( 1, $cursor );
		$rows   = isset( $batch['rows'] ) && is_array( $batch['rows'] ) ? $batch['rows'] : array();

		$state['missingImageRecoveryScanned'] = absint( $state['missingImageRecoveryScanned'] ?? 0 ) + absint( $batch['scanned'] ?? 0 );

		if ( empty( $rows ) ) {
			$state['missingImageRecoveryCursor'] = absint( $batch['cursorEnd'] ?? $cursor );
			$state['lastError']                  = '';

			if ( ! empty( $batch['cycleComplete'] ) ) {
				$state['missingImageRecoveryCursor']   = 0;
				$state['missingImageRecoveryComplete'] = true;
				$state['status']                       = 'done';
				$state['completedAt']                  = time();
				$state['lastMessage']                  = sprintf(
					'Repair کامل شد. محصولات محلی بررسی‌شده برای تصویر: %d، صف‌شده: %d، ردشده: %d، ناموفق: %d.',
					absint( $state['missingImageRecoveryScanned'] ?? 0 ),
					absint( $state['missingImageRecoveryQueued'] ?? 0 ),
					absint( $state['missingImageRecoverySkipped'] ?? 0 ),
					absint( $state['missingImageRecoveryFailed'] ?? 0 )
				);
			} else {
				$state['lastMessage'] = 'Repair محصولات اصلی کامل است؛ جست‌وجوی محصولات محلی بدون تصویر ادامه دارد.';
			}

			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}
			return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
		}

		$row          = $rows[0];
		$product_id   = absint( $row['product_id'] ?? 0 );
		$product_guid = sanitize_text_field( (string) ( $row['product_guid'] ?? '' ) );
		$result       = $recovery->recover_product(
			$product_id,
			$product_guid,
			'repair-missing-image-' . sanitize_text_field( (string) $state['syncId'] )
		);

		if ( is_wp_error( $result ) ) {
			$state['missingImageRecoveryFailed'] = absint( $state['missingImageRecoveryFailed'] ?? 0 ) + 1;
			return $this->handle_transient_request_error( $state, $result, 'خطا در دریافت تصویر محصول محلی بدون عکس.' );
		}

		$this->clear_transient_request_error( $state );
		$state['missingImageRecoveryCursor'] = absint( $batch['cursorEnd'] ?? $product_id );

		if ( ! empty( $result['skipped'] ) ) {
			$state['missingImageRecoverySkipped'] = absint( $state['missingImageRecoverySkipped'] ?? 0 ) + 1;
		} else {
			$state['missingImageRecoveryQueued'] = absint( $state['missingImageRecoveryQueued'] ?? 0 ) + 1;
		}

		if ( ! empty( $batch['cycleComplete'] ) ) {
			$state['missingImageRecoveryCursor']   = 0;
			$state['missingImageRecoveryComplete'] = true;
			$state['status']                       = 'done';
			$state['completedAt']                  = time();
			$state['lastMessage']                  = sprintf(
				'Repair و بازیابی تصاویر کامل شد. محصولات بررسی‌شده: %d، صف‌شده: %d، ردشده: %d، ناموفق: %d.',
				absint( $state['missingImageRecoveryScanned'] ?? 0 ),
				absint( $state['missingImageRecoveryQueued'] ?? 0 ),
				absint( $state['missingImageRecoverySkipped'] ?? 0 ),
				absint( $state['missingImageRecoveryFailed'] ?? 0 )
			);
		} else {
			$state['lastMessage'] = sprintf(
				'تصویر محصول بدون عکس بررسی شد: %s. صف‌شده: %d، ردشده: %d.',
				$product_guid,
				absint( $state['missingImageRecoveryQueued'] ?? 0 ),
				absint( $state['missingImageRecoverySkipped'] ?? 0 )
			);
		}

		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}
		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	private function save_manual_sync_state( $state ) {
		if ( ! is_array( $state ) ) {
			return false;
		}

		$incoming_status  = sanitize_key( (string) ( $state['status'] ?? '' ) );
		$incoming_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
		$current          = $this->checkpoint_coalescing ? array() : get_option( self::STATE_OPTION, array() );

		/*
		 * A running worker may have loaded the state before the admin clicked
		 * cancel. Without this guard, that stale worker can save its old
		 * "running" snapshot after the cancel request and make the UI look
		 * alive again. Once a syncId is cancelled, only another explicit start or
		 * resume action is allowed to move it out of cancelled state.
		 */
		if ( is_array( $current ) ) {
			$current_status  = sanitize_key( (string) ( $current['status'] ?? '' ) );
			$current_sync_id = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );

			/* A stale worker from an older generation may never overwrite a newer run. */
			if ( '' !== $current_sync_id && '' !== $incoming_sync_id && ! hash_equals( $current_sync_id, $incoming_sync_id ) ) {
				return false;
			}

			if ( 'cancelled' === $current_status && 'cancelled' !== $incoming_status && '' !== $incoming_sync_id && $incoming_sync_id === $current_sync_id ) {
				return false;
			}
		}

		/*
		 * A normal Repair product pass may finish without ever seeing a product
		 * that became out of stock while OnlyInStock is enabled. Before marking
		 * Repair complete, switch to the local missing-image recovery pass.
		 */
		if ( ! empty( $state['repairMode'] ) && 'done' === $incoming_status && empty( $state['missingImageRecoveryComplete'] ) ) {
			$state['repairProductsComplete'] = true;
			$state['status']                 = 'running';
			$state['completedAt']            = 0;
			$state['lastError']              = '';
			$state['lastMessage']            = 'Repair محصولات اصلی کامل شد؛ بازیابی تصاویر محصولات محلی بدون عکس شروع می‌شود.';
			$incoming_status                 = 'running';
		}

		$state['updatedAt'] = time();

		if ( $this->checkpoint_coalescing ) {
			$this->checkpoint_state = $state;
			$this->checkpoint_pending_steps++;

			$terminal = in_array( sanitize_key( (string) $state['status'] ), array( 'done', 'cancelled', 'waiting_for_portal' ), true )
				|| '' !== sanitize_text_field( (string) ( $state['lastError'] ?? '' ) );
			$elapsed = microtime( true ) - (float) $this->checkpoint_last_flush_at;

			if ( ! $terminal && $this->checkpoint_pending_steps < $this->checkpoint_max_steps && $elapsed < $this->checkpoint_max_seconds ) {
				return true;
			}

			return $this->flush_manual_sync_checkpoint();
		}

		if ( ! $this->persist_manual_sync_state_verified( $state ) ) {
			return false;
		}
		$this->persist_repair_completion_if_needed( $state );

		return true;
	}

	/**
	 * Durably persist the manual-sync checkpoint and verify the exact read-back.
	 * update_option() returns false both for failures and no-op writes, so the
	 * postcondition—not its boolean return—is the durability boundary.
	 *
	 * @param array $state Sync state.
	 * @return bool
	 */
	private function persist_manual_sync_state_verified( $state ) {
		return is_array( $state ) && Mobo_Core_Durable_State_Policy::update_option_verified( self::STATE_OPTION, $state, false );
	}

	/**
	 * Begin bounded request-local checkpoint coalescing. Public so the real cron
	 * runner can combine several idempotent sync steps into one option write.
	 * Manual/admin one-step calls never enable this and retain immediate durability.
	 *
	 * @param int   $max_steps Max deferred step writes.
	 * @param float $max_seconds Max seconds between durable checkpoints.
	 * @return void
	 */
	public function begin_checkpoint_coalescing( $max_steps = 3, $max_seconds = 2.0 ) {
		$this->checkpoint_coalescing   = true;
		$this->checkpoint_pending_steps = 0;
		$this->checkpoint_max_steps    = max( 1, min( 10, absint( $max_steps ) ) );
		$this->checkpoint_max_seconds  = max( 0.5, min( 5.0, (float) $max_seconds ) );
		$this->checkpoint_last_flush_at = microtime( true );
		$this->checkpoint_state        = $this->get_manual_sync_state();
	}

	/**
	 * Flush a buffered sync checkpoint while preserving an admin cancellation
	 * that may have arrived from another request. At most a few idempotent steps
	 * are replayed after an abrupt PHP/server crash.
	 *
	 * @return bool
	 */
	public function flush_manual_sync_checkpoint() {
		if ( ! $this->checkpoint_coalescing || ! is_array( $this->checkpoint_state ) || $this->checkpoint_pending_steps <= 0 ) {
			return true;
		}

		/* Avoid a stale request-local options-cache entry hiding a concurrent cancel. */
		wp_cache_delete( self::STATE_OPTION, 'options' );
		$current = get_option( self::STATE_OPTION, array() );
		$pending = $this->checkpoint_state;

		if ( is_array( $current ) ) {
			$current_status  = sanitize_key( (string) ( $current['status'] ?? '' ) );
			$current_sync_id = sanitize_text_field( (string) ( $current['syncId'] ?? '' ) );
			$pending_sync_id = sanitize_text_field( (string) ( $pending['syncId'] ?? '' ) );

			if ( '' !== $current_sync_id && '' !== $pending_sync_id && ! hash_equals( $current_sync_id, $pending_sync_id ) ) {
				$this->checkpoint_state         = $current;
				$this->checkpoint_pending_steps = 0;
				$this->checkpoint_last_flush_at = microtime( true );
				return false;
			}

			if ( 'cancelled' === $current_status && '' !== $current_sync_id && $current_sync_id === $pending_sync_id ) {
				$this->checkpoint_state         = $current;
				$this->checkpoint_pending_steps = 0;
				$this->checkpoint_last_flush_at = microtime( true );
				return false;
			}
		}

		if ( ! $this->persist_manual_sync_state_verified( $pending ) ) {
			return false;
		}
		$this->persist_repair_completion_if_needed( $pending );
		$this->checkpoint_pending_steps = 0;
		$this->checkpoint_last_flush_at = microtime( true );
		return true;
	}

	/** End coalescing and durably flush the latest checkpoint. */
	public function end_checkpoint_coalescing() {
		$result = $this->flush_manual_sync_checkpoint();
		$this->checkpoint_coalescing = false;
		$this->checkpoint_state      = null;
		return $result;
	}

	/** Persist repair-completion flags only when the durable state checkpoint is accepted. */
	private function persist_repair_completion_if_needed( $state ) {
		if ( ! is_array( $state ) || empty( $state['repairMode'] ) || 'done' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			return;
		}

		$completed_at = ! empty( $state['completedAt'] ) ? absint( $state['completedAt'] ) : time();
		update_option( self::REPAIR_COMPLETED_OPTION, $completed_at, false );
		update_option( self::REPAIR_LAST_SYNC_ID_OPTION, sanitize_text_field( (string) ( $state['syncId'] ?? '' ) ), false );
		update_option( 'mobo_core_legacy_repair_required', '0', false );
		delete_option( 'mobo_core_desired_state_repair_required' );
		delete_option( 'mobo_core_desired_state_repair_queue_pending' );
	}

	private function finish_current_product_state( &$state, $message ) {
		$completed_guid = sanitize_text_field( (string) $state['currentProductGuid'] );
		$completed_id   = absint( $state['currentProductId'] );
		if ( '' !== $completed_guid && $completed_id > 0 ) {
			if ( ! $this->upsert_product_map( $completed_guid, $completed_id, false ) ) {
				$this->update_post_meta_if_changed( $completed_id, 'mobo_sync_incomplete', '1' );
				return false;
			}
			if ( ! $this->persist_post_meta_verified( $completed_id, 'mobo_sync_incomplete', '0' ) ) {
				$this->upsert_product_map( $completed_guid, $completed_id, true );
				return false;
			}

			/* Commit the Repair-generation marker only for products that reached the
			 * same durable completion boundary as normal sync. The timestamp is written
			 * first and the syncId last, making syncId the commit marker. Post-sync
			 * cleanup also requires mobo_sync_incomplete=0. */
			if ( ! empty( $state['repairMode'] ) ) {
				$repair_sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
				$repair_applied_revision = absint( get_post_meta( $completed_id, '_mobo_product_applied_revision', true ) );
				$repair_webhook_us = absint( get_post_meta( $completed_id, '_mobo_last_webhook_applied_us', true ) );
				if ( '' === $repair_sync_id
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_APPLIED_REVISION_META, $repair_applied_revision )
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_WEBHOOK_US_META, $repair_webhook_us )
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_SYNCED_AT_META, time() )
					|| ! $this->persist_post_meta_verified( $completed_id, self::REPAIR_SYNC_ID_META, $repair_sync_id ) ) {
					$this->persist_post_meta_verified( $completed_id, 'mobo_sync_incomplete', '1' );
					$this->upsert_product_map( $completed_guid, $completed_id, true );
					return false;
				}
			}

			if ( class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_synced(
					$completed_guid,
					$completed_id,
					absint( $state['currentProductSourceRevision'] ?? 0 ),
					sanitize_text_field( (string) ( $state['currentProductSourceHash'] ?? '' ) ),
					absint( $state['currentProductPortalId'] ?? 0 ),
					sanitize_text_field( (string) ( $state['currentProductSourceVersion'] ?? '' ) )
				);
			}
		}

		$state['processedProducts']              = absint( $state['processedProducts'] ) + 1;
		$state['currentProductGuid']             = '';
		$state['currentProductId']               = 0;
		$state['currentProductPortalId']         = 0;
		$state['currentProductSourceRevision']   = 0;
		$state['currentProductSourceVersion']    = '';
		$state['currentProductSourceHash']       = '';
		$state['currentProductImages']           = array();
		$state['currentProductImagesPresent']    = false;
		$state['currentProductAttributes']       = array();
		$state['currentProductImageOffset']      = 0;
		$state['currentProductWasExisting']      = false;
		$state['currentProductImagesDone']       = false;
		$state['currentProductCanHaveVariants']  = false;
		$state['variantPage']                    = 1;
		$state['currentVariantTotalCount']       = 0;
		$state['currentVariantTotalPages']       = 0;
		$state['currentVariantProcessedPages']   = 0;
		$state['currentVariantCursor']            = 0;
		$state['lastError']                      = '';
		$state['lastMessage']                    = sanitize_text_field( (string) $message );

		if ( absint( $state['productTotalCount'] ) > 0 && absint( $state['processedProducts'] ) >= absint( $state['productTotalCount'] ) ) {
			$state['status']      = 'done';
			$state['completedAt'] = time();
			$state['lastMessage'] = 'همگام‌سازی محصولات کامل شد.';
		}

		return $this->save_manual_sync_state( $state );
	}

	private function upsert_parent_product( $data, $skip_images ) {
		$this->last_parent_core_noop    = false;

		$product_guid = $this->extract_product_guid( $data );

		if ( '' === $product_guid ) {
			return 0;
		}

		$product_id      = $this->find_product_id_by_guid( $product_guid );
		$is_new_product  = $product_id <= 0;
		$payload_integrity = $this->validate_product_desired_state_payload( $data, $is_new_product );
		if ( is_wp_error( $payload_integrity ) ) {
			throw new RuntimeException( $payload_integrity->get_error_message() );
		}
		if ( $product_id <= 0 && $this->is_repair_mode() ) {
			$product_id = $this->restore_trashed_mobo_object_by_identity( 'product', 'product_guid', $product_guid, 0 );
			if ( $product_id > 0 ) {
				$this->upsert_product_map( $product_guid, $product_id, false );
			}
		}
		$is_new_product  = $product_id <= 0;
		$incoming_hash   = $this->build_product_source_hash( $data, $product_guid );

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );

			/*
			 * An existing Mobo identity must never be type-coerced merely because the
			 * WooCommerce factory could not materialize it. Constructing
			 * WC_Product_Simple with an existing Variable product ID can make
			 * WC_Product::save() run WooCommerce's Variable->non-Variable transition,
			 * which force-deletes every child variation before Mobo's quarantine/map
			 * lifecycle has a chance to preserve forensic identity. Treat a transient
			 * or structural factory failure as retryable/fail-closed instead.
			 */
			if ( ! $product instanceof WC_Product ) {
				throw new RuntimeException(
					sprintf(
						'WooCommerce could not load existing Mobo product %d (%s); mutation was deferred to avoid unsafe product-type coercion.',
						$product_id,
						$product_guid
					)
				);
			}
		} else {
			$product = new WC_Product_Simple();
		}

		/*
		 * Fast path for an already converged parent product. The source hash contains
		 * only Mobo-controlled storefront fields and the active pricing/update policy.
		 * A small state verification protects against local drift (price/stock/title/
		 * slug/attributes). Internal identity/category-reference metadata can still be
		 * repaired below without running WC_Product::save(), so no page-cache purge is
		 * generated for an internal-only change.
		 */
		if ( ! $is_new_product && ! $this->is_repair_mode() && '' !== $incoming_hash ) {
			$old_hash       = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_source_hash', true ) );
			$old_incomplete = sanitize_text_field( (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true ) );

			/*
			 * Frontend dirty classification: the source hash may change because policy
			 * configuration or internal source metadata changed even though the rendered
			 * WooCommerce state is already identical. In that case refresh internal
			 * convergence metadata directly and avoid WC CRUD/cache hooks entirely.
			 */
			if ( '1' !== $old_incomplete && $this->product_frontend_state_matches_payload( $product, $data ) ) {
				if ( ! $this->sync_product_identity_meta_fast( $product_id, $data, $product_guid ) || ! $this->sync_price_source_meta_fast( $product_id, $data ) ) {
					throw new RuntimeException( 'Could not durably refresh product source bookkeeping on the no-op path.' );
				}
				$this->cleanup_stock_diagnostic_meta_fast( $product_id, $data );
				if ( $this->product_attributes_field_present( $data ) && ! $this->store_product_attribute_guids_if_changed( $product_id, $this->get_value( $data, 'attributes', array() ) ) ) {
					throw new RuntimeException( 'Could not durably persist product attribute identity metadata.' );
				}
				if ( $this->product_categories_field_present( $data ) ) {
					$fast_category_refs = $this->get_raw_product_categories_field( $data );
					if ( ! is_array( $fast_category_refs ) || ! $this->store_product_category_refs_if_changed( $product_id, $fast_category_refs ) ) {
						throw new RuntimeException( 'Could not durably persist product category reference metadata.' );
					}
					$fast_category_assignment = $this->category_sync->assign_product_categories(
						$product_id,
						$fast_category_refs,
						$this->rules->should_update_categories(),
						false,
						true
					);
					if ( is_array( $fast_category_assignment ) && ! empty( $fast_category_assignment['error'] ) ) {
						throw new RuntimeException( 'Category assignment failed on product no-op path: ' . sanitize_text_field( (string) $fast_category_assignment['error'] ) );
					}
				}
				if ( ! $this->persist_post_meta_verified( $product_id, '_mobo_product_source_hash', $incoming_hash ) ) {
					throw new RuntimeException( 'Could not durably persist the product source hash.' );
				}
				if ( $old_hash !== $incoming_hash ) {
					$this->update_post_meta_if_changed( $product_id, '_mobo_product_source_hash_updated_at', gmdate( 'c' ) );
				}
				$this->last_parent_core_noop = true;
				if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
					Mobo_Core_Runtime_Diagnostics::increment( 'product_noop' );
				}
				if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
					$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
					throw new RuntimeException( 'Could not refresh product mapping metadata.' );
				}

				/* A missing simple-product storefront identity must still be repairable. */
				if ( ! $this->product_payload_can_have_variants( $data, $product_id ) ) {
					$embedded_variant = $this->extract_embedded_simple_variant_data( $data );
					if ( ! empty( $embedded_variant ) && $this->get_stored_portal_variant_id( $product_id ) <= 0 ) {
						if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
							throw new RuntimeException( 'Could not durably persist the simple-product repair crash marker.' );
						}
						$embedded_result = $this->apply_simple_variant_to_product( $product_id, $embedded_variant, $product_guid );
						if ( is_wp_error( $embedded_result ) ) {
							throw new RuntimeException( $embedded_result->get_error_message() );
						}
						if ( ! $this->upsert_product_map( $product_guid, $product_id, false ) ) {
							throw new RuntimeException( 'Could not finalize embedded simple-product mapping.' );
						}
						if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '0' ) ) {
							$this->upsert_product_map( $product_guid, $product_id, true );
							throw new RuntimeException( 'Could not durably persist the simple-product repair completion marker.' );
						}
					}
				}

				return $product_id;
			}
		}

		/*
		 * New products are now assembled fully in memory and persisted with one
		 * WooCommerce CRUD save. The incomplete marker stays at 1 during that save,
		 * preserving crash detection without paying for a second WC save/hook cycle.
		 */
		if ( $is_new_product ) {
			$initial_title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' === $initial_title ) {
				$initial_title = 'Mobo Product ' . $product_guid;
			}

			$product->set_name( $initial_title );
			$product->set_status( 'publish' );
			$this->update_product_meta_if_changed( $product, 'product_guid', $product_guid );
			$this->store_portal_product_id_on_product_object( $product, $data );
			$product->update_meta_data( 'mobo_sync_incomplete', '1' );
		} else {
			/*
			 * Existing products used to perform a full WooCommerce CRUD save here only
			 * to mark the sync incomplete, followed by another save after field updates.
			 * Direct metadata keeps crash-recovery semantics while removing that first
			 * expensive save/hook/cache-invalidation cycle.
			 */
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				throw new RuntimeException( 'Could not durably persist the product crash marker before mutation.' );
			}
		}

		if ( $is_new_product || $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' !== $title && (string) $product->get_name( 'edit' ) !== $title ) {
				$product->set_name( $title );
			}
		}

		$price_field_present   = $this->product_price_field_present( $data );
		$compare_field_present = $this->product_compare_price_field_present( $data );
		if ( $is_new_product || ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $price_field_present || $compare_field_present ) ) ) {
			$this->apply_price_to_product( $product, $data, 'product', $is_new_product );
		}

		if ( $is_new_product || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

			if ( $stock_present ) {
				if ( ! $this->apply_api_stock( $product, $stock_value ) ) {
					throw new RuntimeException( 'Invalid Mobo stock payload for product.' );
				}
			} elseif ( $is_new_product ) {
				$this->apply_api_stock( $product, null );
			}
		}

		if ( $is_new_product || $this->rules->should_update_slug() ) {
			$this->apply_product_slug( $product, $data );
		}

		$this->apply_product_dates( $product, $data );

		$url = sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );

		if ( '' !== $url ) {
			$this->update_product_meta_if_changed( $product, 'mobo_url', $url );
		}

		$this->update_product_meta_if_changed( $product, 'product_guid', $product_guid );
		$this->store_portal_product_id_on_product_object( $product, $data );

		$attributes_present        = $is_new_product || $this->product_attributes_field_present( $data );
		$desired_attribute_payload = $this->get_value( $data, 'attributes', array() );
		$attribute_state_dirty     = $attributes_present && ( $is_new_product || ! $this->product_attributes_match_payload( $product, $desired_attribute_payload ) );
		$default_attributes_dirty  = $attributes_present && ! empty( $product->get_default_attributes() );

		/*
		 * Price/stock/title-only updates must not re-serialize _product_attributes.
		 * WC_Product::set_attributes() marks the property dirty even when callers
		 * reconstruct an identical object graph, causing unnecessary postmeta work.
		 */
		if ( $attribute_state_dirty || $default_attributes_dirty ) {
			$product->set_default_attributes( array() );
			$product->set_attributes( $this->build_product_attributes( $desired_attribute_payload ) );
		}

		$source_hash_changed = false;
		if ( '' !== $incoming_hash ) {
			$source_hash_changed = $this->update_product_meta_if_changed( $product, '_mobo_product_source_hash', $incoming_hash );
			if ( $source_hash_changed ) {
				$this->update_product_meta_if_changed( $product, '_mobo_product_source_hash_updated_at', gmdate( 'c' ) );
			}
		}
		$product_id = absint( $product->save() );
		if ( $product_id > 0 && class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'product_save' );
			if ( $is_new_product ) {
				Mobo_Core_Runtime_Diagnostics::increment( 'product_created' );
			}
		}

		if ( $product_id > 0 ) {
			/* Keep both persistence layers incomplete until category/image/simple-variant
			 * side effects have durably committed. The caller clears the marker only at
			 * its terminal desired-state checkpoint. */
			if ( ! $this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' ) ) {
				throw new RuntimeException( 'Could not verify the product crash marker after WooCommerce save.' );
			}
			if ( ! $this->sync_product_identity_meta_fast( $product_id, $data, $product_guid ) || ! $this->sync_price_source_meta_fast( $product_id, $data ) || ( '' !== $incoming_hash && ! $this->persist_post_meta_verified( $product_id, '_mobo_product_source_hash', $incoming_hash ) ) ) {
				throw new RuntimeException( 'Could not verify product identity/source bookkeeping after WooCommerce save.' );
			}
			if ( ! $this->upsert_product_map( $product_guid, $product_id, true ) ) {
				throw new RuntimeException( 'Could not durably commit product identity mapping.' );
			}
		}

		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( $attributes_present && ! $this->store_product_attribute_guids_if_changed( $product_id, $this->get_value( $data, 'attributes', array() ) ) ) {
			throw new RuntimeException( 'Could not durably persist product attribute identity metadata.' );
		}

		$categories_field_present = $this->product_categories_field_present( $data );
		$categories_present       = $is_new_product || $categories_field_present;
		$product_category_refs    = $categories_field_present ? $this->get_raw_product_categories_field( $data ) : array();
		if ( ! is_array( $product_category_refs ) ) {
			$product_category_refs = array();
		}
		if ( $categories_field_present && ! $this->store_product_category_refs_if_changed( $product_id, $product_category_refs ) ) {
			throw new RuntimeException( 'Could not durably persist product category reference metadata.' );
		}

		$category_assignment = $categories_present ? $this->category_sync->assign_product_categories(
			$product_id,
			$product_category_refs,
			$this->rules->should_update_categories(),
			$is_new_product,
			$categories_field_present
		) : array();

		if ( is_array( $category_assignment ) && ! empty( $category_assignment['error'] ) ) {
			$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
			$this->upsert_product_map( $product_guid, $product_id, true );
			throw new RuntimeException( 'Category assignment failed for product ' . $product_id . ': ' . sanitize_text_field( (string) $category_assignment['error'] ) );
		}

		if ( ! $skip_images && ( $is_new_product || $this->product_images_field_present( $data ) ) && ( $is_new_product || $this->rules->should_update_images() ) && $this->product_images_need_processing( $product_id, $this->get_product_images_from_payload( $data ), $is_new_product ) ) {
			$image_result = $this->image_sync->process_images(
				$product_id,
				$this->get_product_images_from_payload( $data ),
				0,
				false
			);
			if ( ! empty( $image_result['error'] ) ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				throw new RuntimeException( 'Image queue persistence failed for product ' . $product_id . ': ' . sanitize_text_field( (string) $image_result['error'] ) );
			}
			if ( ! empty( $image_result['pending'] ) ) {
				if ( ! $this->mark_product_images_pending( $product_id, $this->get_product_images_from_payload( $data ) ) ) {
					$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
					$this->upsert_product_map( $product_guid, $product_id, true );
					throw new RuntimeException( 'Could not durably persist the pending image desired-state hash.' );
				}
			} elseif ( ! empty( $image_result['done'] ) && ! $this->mark_product_images_converged( $product_id, $this->get_product_images_from_payload( $data ) ) ) {
				$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
				$this->upsert_product_map( $product_guid, $product_id, true );
				throw new RuntimeException( 'Could not durably persist the converged image desired-state hash.' );
			}
		}

		/* Preserve embedded single-variant behavior. */
		if ( ! $this->product_payload_can_have_variants( $data, $product_id ) ) {
			$embedded_variant = $this->extract_embedded_simple_variant_data( $data );

			if ( ! empty( $embedded_variant ) ) {
				$embedded_result = $this->apply_simple_variant_to_product( $product_id, $embedded_variant, $product_guid );
				if ( is_wp_error( $embedded_result ) ) {
					$this->persist_post_meta_verified( $product_id, 'mobo_sync_incomplete', '1' );
					$this->upsert_product_map( $product_guid, $product_id, true );
					throw new RuntimeException( $embedded_result->get_error_message() );
				}
			}
		}

		return $product_id;
	}

	private function upsert_variation( $parent, $data ) {
		if ( ! $parent instanceof WC_Product ) {
			return array(
				'id'      => 0,
				'changed' => false,
			);
		}

		$parent_id    = absint( $parent->get_id() );
		$variant_guid = $this->extract_variant_guid( $data );

		if ( $parent_id <= 0 || '' === $variant_guid ) {
			return array(
				'id'      => 0,
				'changed' => false,
			);
		}

		$money_integrity = $this->validate_money_payload_fields( $data, 'variation' );
		if ( is_wp_error( $money_integrity ) ) {
			throw new RuntimeException( $money_integrity->get_error_message() );
		}

		$product_guid  = $this->extract_product_guid( $data );
		$incoming_hash = $this->build_variation_source_hash( $data, $product_guid );

		if ( ! $this->is_repair_mode() && $this->is_remote_variation_trashed( $variant_guid ) ) {
			return array(
				'id'             => 0,
				'changed'        => false,
				'skipped_trash'  => true,
			);
		}

		/*
		 * Fail closed before mutating an existing variation. Mobo variants represent
		 * concrete selections, so every parent variation attribute must be present in
		 * each variant payload. Older Portal/title parsing could emit only a subset;
		 * accepting that payload permanently detached one WooCommerce attribute.
		 */
		$raw_attrs       = $this->get_value( $data, 'attributes', array() );
		$raw_integrity   = $this->validate_raw_variation_attribute_payload( $raw_attrs );
		if ( is_wp_error( $raw_integrity ) ) {
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_detected_at', gmdate( 'c' ) );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_variant_guid', $variant_guid );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_reason', sanitize_text_field( $raw_integrity->get_error_message() ) );
			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $parent_id );
			}
			throw new RuntimeException( $raw_integrity->get_error_message() );
		}

		$attrs           = $this->normalize_variation_attributes( $raw_attrs );
		$attrs_integrity = $this->validate_variation_attribute_completeness( $parent, $attrs );
		if ( is_wp_error( $attrs_integrity ) ) {
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_detected_at', gmdate( 'c' ) );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_variant_guid', $variant_guid );
			update_post_meta( $parent_id, '_mobo_variant_attribute_drift_reason', sanitize_text_field( $attrs_integrity->get_error_message() ) );
			if ( '' !== $product_guid && class_exists( 'Mobo_Core_Sync_Health' ) ) {
				Mobo_Core_Sync_Health::mark_behind( $product_guid, $parent_id );
			}
			throw new RuntimeException( $attrs_integrity->get_error_message() );
		}

		$variation_id            = $this->find_variation_id_by_guid( $variant_guid );
		if ( $variation_id <= 0 && $this->is_repair_mode() ) {
			$variation_id = $this->restore_trashed_mobo_object_by_identity( 'product_variation', 'variant_guid', $variant_guid, $parent_id );
		}
		$matched_by_portal_id    = false;
		$matched_by_signature    = false;

		/* PortalVariantId is the concrete purchase identity. Prefer an existing
		 * sibling with that identity before falling back to storefront signature;
		 * this closes the race/crash window that previously created another local
		 * variation when a GUID map was temporarily missing. */
		if ( $variation_id <= 0 ) {
			$variation_id = $this->find_variation_id_by_portal_variant_id( $parent_id, $data );
			$matched_by_portal_id = $variation_id > 0;
		}

		if ( $variation_id <= 0 ) {
			$variation_id = $this->find_variation_id_by_attribute_signature( $parent_id, $data );
			$matched_by_signature = $variation_id > 0;
		}

		if ( $variation_id > 0 ) {
			$existing_parent_id = absint( wp_get_post_parent_id( $variation_id ) );
			if ( $existing_parent_id > 0 && $existing_parent_id !== $parent_id ) {
				throw new RuntimeException( 'Existing variation identity belongs to another parent product; refusing cross-parent reassignment.' );
			}
		}

		$is_new_variation = $variation_id <= 0;

		if ( ! $is_new_variation && $variation_id > 0 ) {
			$old_hash          = sanitize_text_field( (string) get_post_meta( $variation_id, '_mobo_variant_source_hash', true ) );
			$old_incomplete    = sanitize_text_field( (string) get_post_meta( $variation_id, 'mobo_sync_incomplete', true ) );
			$current_parent_id = absint( wp_get_post_parent_id( $variation_id ) );
			$current_parent_ok = 0 === $current_parent_id || $current_parent_id === $parent_id;

			if ( ! $this->is_repair_mode() && '' !== $incoming_hash && '1' !== $old_incomplete && $current_parent_ok && $this->variation_frontend_state_matches_payload( $variation_id, $parent_id, $data ) ) {
				/*
				 * A remote variant may be recreated with a new GUID while keeping the
				 * exact same storefront attribute signature. In that case the existing
				 * WooCommerce variation is intentionally reused. The fast/no-op path
				 * must still move the local identity to the new GUID before authoritative
				 * missing-variant finalization runs; otherwise the reused variation still
				 * looks like the old, now-missing GUID and is deleted immediately after
				 * being matched.
				 */
				if ( ! $this->persist_post_meta_verified( $variation_id, 'variant_guid', $variant_guid ) || ! $this->store_portal_ids_on_variation_post( $variation_id, $data, $product_guid, $parent_id ) || ! $this->sync_price_source_meta_fast( $variation_id, $data ) || ! $this->persist_post_meta_verified( $variation_id, '_mobo_variant_source_hash', $incoming_hash ) ) {
					throw new RuntimeException( 'Could not durably refresh variation source bookkeeping on the no-op path.' );
				}
				$this->cleanup_stock_diagnostic_meta_fast( $variation_id, $data );
				if ( $old_hash !== $incoming_hash ) {
					$this->update_post_meta_if_changed( $variation_id, '_mobo_variant_source_hash_updated_at', gmdate( 'c' ) );
				}
				if ( $matched_by_signature || $matched_by_portal_id ) {
					$stored_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
					if ( $stored_guid !== $variant_guid || ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false ) ) {
						$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
						throw new RuntimeException( 'Could not commit migrated variation identity.' );
					}
				}

				if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
					Mobo_Core_Runtime_Diagnostics::increment( 'variation_noop' );
				}
				if ( ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false ) ) {
					$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
					throw new RuntimeException( 'Could not refresh variation mapping metadata.' );
				}
				return array(
					'id'      => $variation_id,
					'changed' => false,
				);
			}
		}

		if ( $variation_id > 0 ) {
			if ( ! $this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' ) || ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true ) ) {
				throw new RuntimeException( 'Could not durably persist the variation crash marker before mutation.' );
			}
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation( $variation_id );
			}
		} else {
			$variation = new WC_Product_Variation();
		}

		if ( $is_new_variation ) {
			$initial_title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' === $initial_title ) {
				$initial_title = 'Mobo Variant ' . $variant_guid;
			}

			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' );
			$variation->set_name( $initial_title );
			$this->update_product_meta_if_changed( $variation, 'variant_guid', $variant_guid );
			$this->store_portal_variant_id_on_product_object( $variation, $data );
			$this->store_portal_product_id_on_product_object( $variation, $data, $parent_id );
			$variation->update_meta_data( 'mobo_sync_incomplete', '1' );

			if ( '' !== $product_guid ) {
				$this->update_product_meta_if_changed( $variation, 'product_guid', $product_guid );
			}
		} else {
			if ( absint( $variation->get_parent_id( 'edit' ) ) !== $parent_id ) {
				$variation->set_parent_id( $parent_id );
			}
		}

		if ( $is_new_variation || $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );

			if ( '' !== $title && (string) $variation->get_name( 'edit' ) !== $title ) {
				$variation->set_name( $title );
			}
		}

		if ( $is_new_variation || $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$this->apply_price_to_product( $variation, $data, 'variation', $is_new_variation );
		}

		if ( $is_new_variation || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

			if ( $stock_present ) {
				if ( ! $this->is_valid_api_stock_payload_value( $stock_value ) ) {
					throw new RuntimeException( 'Invalid Mobo stock payload for variation.' );
				}

				$this->apply_api_stock( $variation, $stock_value );
			} elseif ( $is_new_variation ) {
				$this->apply_api_stock( $variation, null );
			}
		}

		if ( $this->variation_attribute_signature( $variation->get_attributes( 'edit' ) ) !== $this->variation_attribute_signature( $attrs ) ) {
			$variation->set_attributes( $attrs );
		}

		$this->update_product_meta_if_changed( $variation, 'variant_guid', $variant_guid );
		$this->store_portal_variant_id_on_product_object( $variation, $data );
		$this->store_portal_product_id_on_product_object( $variation, $data, $parent_id );

		if ( '' !== $product_guid ) {
			$this->update_product_meta_if_changed( $variation, 'product_guid', $product_guid );
		}

		$variation_hash_changed = false;
		if ( '' !== $incoming_hash ) {
			$variation_hash_changed = $this->update_product_meta_if_changed( $variation, '_mobo_variant_source_hash', $incoming_hash );
			if ( $variation_hash_changed ) {
				$this->update_product_meta_if_changed( $variation, '_mobo_variant_source_hash_updated_at', gmdate( 'c' ) );
			}
		}
		$variation_id = absint( $variation->save() );
		if ( $variation_id <= 0 ) {
			throw new RuntimeException( 'WooCommerce variation save returned no durable post ID.' );
		}
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'variation_save' );
			if ( $is_new_variation ) {
				Mobo_Core_Runtime_Diagnostics::increment( 'variation_created' );
			}
		}

		if ( $variation_id > 0 ) {
			$this->remember_variation_signature( $parent_id, $variation_id, $attrs );
			if ( ! $this->persist_post_meta_verified( $variation_id, 'variant_guid', $variant_guid ) || ! $this->store_portal_ids_on_variation_post( $variation_id, $data, $product_guid, $parent_id ) || ! $this->sync_price_source_meta_fast( $variation_id, $data ) || ( '' !== $incoming_hash && ! $this->persist_post_meta_verified( $variation_id, '_mobo_variant_source_hash', $incoming_hash ) ) ) {
				$this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '1' );
				throw new RuntimeException( 'Could not verify variation identity/source bookkeeping after WooCommerce save.' );
			}
			$stored_guid = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
			if ( $stored_guid !== $variant_guid || ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, true ) ) {
				$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
				throw new RuntimeException( 'Could not commit variation identity mapping.' );
			}
			if ( ! $this->upsert_variation_map( $variant_guid, $variation_id, $product_guid, false ) ) {
				$this->update_post_meta_if_changed( $variation_id, 'mobo_sync_incomplete', '1' );
				throw new RuntimeException( 'Could not finalize variation identity mapping.' );
			}
			if ( ! $this->persist_post_meta_verified( $variation_id, 'mobo_sync_incomplete', '0' ) ) {
				throw new RuntimeException( 'Could not durably persist the variation completion marker.' );
			}
		}

		return array(
			'id'      => $variation_id,
			'changed' => $variation_id > 0,
		);
	}



	private function store_portal_product_id_on_product_object( $product, $data, $fallback_product_id = 0 ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$portal_product_id = $this->extract_portal_product_id( $data );

		if ( $portal_product_id <= 0 && $fallback_product_id > 0 ) {
			$portal_product_id = $this->get_cached_parent_portal_product_id( $fallback_product_id );
		}

		if ( $portal_product_id <= 0 ) {
			return;
		}

		$this->update_product_meta_if_changed( $product, 'portal_product_id', $portal_product_id );
		$this->update_product_meta_if_changed( $product, 'mobo_portal_product_id', $portal_product_id );
		$this->update_product_meta_if_changed( $product, '_mobo_portal_product_id', $portal_product_id );
	}

	private function store_portal_variant_id_on_product_object( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $data );

		if ( $portal_variant_id <= 0 ) {
			return;
		}

		$this->update_product_meta_if_changed( $product, 'portal_variant_id', $portal_variant_id );
		$this->update_product_meta_if_changed( $product, 'mobo_portal_variant_id', $portal_variant_id );
		$this->update_product_meta_if_changed( $product, '_mobo_portal_variant_id', $portal_variant_id );
	}

	private function store_portal_ids_on_variation_post( $variation_id, $data, $product_guid = '', $parent_id = 0 ) {
		$variation_id = absint( $variation_id );

		if ( $variation_id <= 0 ) {
			return false;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $data );

		if ( $portal_variant_id > 0 ) {
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $key ) {
				if ( ! $this->persist_post_meta_verified( $variation_id, $key, $portal_variant_id ) ) {
					return false;
				}
			}
		}

		$portal_product_id = $this->extract_portal_product_id( $data );

		if ( $portal_product_id <= 0 && $parent_id > 0 ) {
			$portal_product_id = $this->get_cached_parent_portal_product_id( $parent_id );
		}

		if ( $portal_product_id > 0 ) {
			foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $key ) {
				if ( ! $this->persist_post_meta_verified( $variation_id, $key, $portal_product_id ) ) {
					return false;
				}
			}
		}

		if ( '' !== $product_guid && ! $this->persist_post_meta_verified( $variation_id, 'product_guid', sanitize_text_field( (string) $product_guid ) ) ) {
			return false;
		}

		return true;
	}

	private function product_attribute_structure_changed( $product, $desired_attributes ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$current = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$current[] = sanitize_title( (string) $attribute->get_name() );
		}

		$desired = array();
		foreach ( $this->normalize_product_attributes_for_hash( $desired_attributes ) as $attribute ) {
			$desired[] = sanitize_title( (string) ( isset( $attribute['name'] ) ? $attribute['name'] : '' ) );
		}

		$current = array_values( array_unique( array_filter( $current ) ) );
		$desired = array_values( array_unique( array_filter( $desired ) ) );
		sort( $current, SORT_STRING );
		sort( $desired, SORT_STRING );

		return $current !== $desired;
	}

	private function apply_desired_product_attributes( $product_id, $attributes ) {
		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		if ( $product_id <= 0 || ! $product instanceof WC_Product ) {
			return false;
		}

		if ( $this->product_attributes_match_payload( $product, $attributes ) ) {
			if ( ! $this->store_product_attribute_guids_if_changed( $product_id, $attributes ) ) {
				throw new RuntimeException( 'Product attribute identity metadata failed verification.' );
			}
			return false;
		}

		$product->set_default_attributes( array() );
		$product->set_attributes( $this->build_product_attributes( $attributes ) );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== $product_id ) {
			throw new RuntimeException( 'WooCommerce did not persist the desired product attributes.' );
		}
		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product || ! $this->product_attributes_match_payload( $fresh, $attributes ) ) {
			throw new RuntimeException( 'WooCommerce product attributes failed their post-write verification.' );
		}
		if ( ! $this->store_product_attribute_guids( $product_id, $attributes ) ) {
			throw new RuntimeException( 'Product attribute identity metadata failed verification after attribute save.' );
		}
		return true;
	}

	/**
	 * Return live variation children by post_parent regardless of the parent WC type.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_live_variation_children( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}


	/**
	 * Return Trash children whose shared quarantine marker proves a prior retirement
	 * attempt already crossed the storefront boundary but still needs idempotent
	 * Product Map/identity cleanup. Manually trashed variations without this marker
	 * are deliberately excluded.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_pending_quarantine_children( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'trash' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_mobo_variation_quarantine_reason',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Move one Mobo-owned variation to Trash/quarantine using the shared crash-safe policy.
	 *
	 * @param int    $variation_id Variation ID.
	 * @param string $reason Reason key.
	 * @param array  $context Context.
	 * @return true|WP_Error
	 */
	private function quarantine_variation( $variation_id, $reason, $context = array() ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || ! class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ) {
			return new WP_Error( 'mobo_core_variation_lifecycle_unavailable', 'Variation lifecycle policy is unavailable.' );
		}
		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		if ( $parent_id > 0 ) {
			unset( $this->variation_signature_index[ $parent_id ] );
		}
		return Mobo_Core_Variation_Lifecycle_Policy::quarantine( $variation_id, $reason, $context, $this->product_map );
	}

	/**
	 * Prime all local identities/meta needed by one UpdateVariant page.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param array $variants Variant payload list.
	 * @return void
	 */
	private function prime_variation_page_context( $parent_id, $variants ) {
		$parent_id = absint( $parent_id );
		$guids     = array();
		foreach ( is_array( $variants ) ? $variants : array() as $variant_data ) {
			if ( ! is_array( $variant_data ) ) {
				continue;
			}
			$guid = $this->extract_variant_guid( $variant_data );
			if ( '' !== $guid ) {
				$guids[ $guid ] = true;
			}
		}

		if ( ! empty( $guids ) && $this->product_map instanceof Mobo_Core_Product_Map && method_exists( $this->product_map, 'prime_variation_ids' ) ) {
			$this->product_map->prime_variation_ids( array_keys( $guids ) );
		}

		if ( $parent_id > 0 ) {
			$this->get_cached_parent_portal_product_id( $parent_id );
		}
	}

	/**
	 * Cache parent portal product identity for all variations processed in this request.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return int
	 */
	private function get_cached_parent_portal_product_id( $parent_id ) {
		$parent_id = absint( $parent_id );
		if ( $parent_id <= 0 ) {
			return 0;
		}
		if ( ! array_key_exists( $parent_id, $this->parent_portal_product_id_cache ) ) {
			$this->parent_portal_product_id_cache[ $parent_id ] = absint( get_post_meta( $parent_id, 'portal_product_id', true ) );
		}
		return absint( $this->parent_portal_product_id_cache[ $parent_id ] );
	}

	/**
	 * Keep an already-built signature index current after a variation save.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param int   $variation_id Variation ID.
	 * @param array $attrs Normalized attributes.
	 * @return void
	 */
	private function remember_variation_signature( $parent_id, $variation_id, $attrs ) {
		$parent_id    = absint( $parent_id );
		$variation_id = absint( $variation_id );
		if ( $parent_id <= 0 || $variation_id <= 0 || ! array_key_exists( $parent_id, $this->variation_signature_index ) ) {
			return;
		}
		/* A changed Variation must not remain reachable through its old signature. */
		foreach ( $this->variation_signature_index[ $parent_id ] as $known_signature => $known_id ) {
			if ( absint( $known_id ) === $variation_id ) {
				unset( $this->variation_signature_index[ $parent_id ][ $known_signature ] );
			}
		}
		$signature = $this->variation_attribute_signature( $attrs );
		if ( '' !== $signature ) {
			$this->variation_signature_index[ $parent_id ][ $signature ] = $variation_id;
		}
	}

	private function find_variation_id_by_attribute_signature( $parent_id, $data ) {
		$parent_id = absint( $parent_id );
		$incoming  = $this->variation_attribute_signature( $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) ) );
		if ( $parent_id <= 0 || '' === $incoming ) {
			return 0;
		}

		if ( ! array_key_exists( $parent_id, $this->variation_signature_index ) ) {
			$index = array();
			$children = get_posts(
				array(
					'post_type'              => 'product_variation',
					'post_parent'            => $parent_id,
					'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$children = array_values( array_filter( array_map( 'absint', is_array( $children ) ? $children : array() ) ) );
			if ( ! empty( $children ) ) {
				update_meta_cache( 'post', $children );
			}

			foreach ( $children as $variation_id ) {
				$all_meta = get_post_meta( $variation_id );
				$attrs    = array();
				if ( is_array( $all_meta ) ) {
					foreach ( $all_meta as $meta_key => $values ) {
						if ( 0 !== strpos( (string) $meta_key, 'attribute_' ) ) {
							continue;
						}
						$value = is_array( $values ) && isset( $values[0] ) ? $values[0] : '';
						if ( is_scalar( $value ) && '' !== (string) $value ) {
							$attrs[ $meta_key ] = (string) $value;
						}
					}
				}
				$signature = $this->variation_attribute_signature( $attrs );
				if ( '' !== $signature && ! isset( $index[ $signature ] ) ) {
					$index[ $signature ] = $variation_id;
				}
			}

			$this->variation_signature_index[ $parent_id ] = $index;
		}

		return isset( $this->variation_signature_index[ $parent_id ][ $incoming ] )
			? absint( $this->variation_signature_index[ $parent_id ][ $incoming ] )
			: 0;
	}

	private function variation_attribute_signature( $attributes ) {
	return Mobo_Core_Variation_Identity_Policy::attribute_signature( $attributes );
}

	/**
	 * Whether a ProductUpdated payload contains every field that contributes to the
	 * canonical parent source hash. Partial payloads may mutate explicit fields, but
	 * must never replace the last complete-snapshot hash with a partial-state hash.
	 */
	private function product_payload_has_complete_source_hash_state( $data ) {
		if ( ! is_array( $data ) || ! $this->product_attributes_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_title() && ! $this->product_title_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_price() && ! $this->product_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_compare_price() && ! $this->product_compare_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$this->get_stock_value_from_payload( $data, $stock_present );
			if ( ! $stock_present ) {
				return false;
			}
		}
		if ( $this->rules->should_update_slug() && ! $this->product_slug_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_categories() && ! $this->product_categories_field_present( $data ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Same completeness boundary for one variation snapshot. Attributes are identity
	 * state and every enabled storefront field must be present before replacing the
	 * canonical variation hash.
	 */
	private function variation_payload_has_complete_source_hash_state( $data ) {
		if ( ! is_array( $data ) || ! $this->product_attributes_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_title() && ! $this->product_title_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_price() && ! $this->product_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_compare_price() && ! $this->product_compare_price_field_present( $data ) ) {
			return false;
		}
		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$this->get_stock_value_from_payload( $data, $stock_present );
			if ( ! $stock_present ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a deterministic hash for Mobo-controlled parent storefront fields.
	 * Internal identity metadata and images are intentionally excluded: identity
	 * can be repaired without WC CRUD, while images have their own convergence hash.
	 */
	private function build_product_source_hash( $data, $product_guid = '' ) {
		if ( ! is_array( $data ) || ! $this->product_payload_has_complete_source_hash_state( $data ) ) {
			return '';
		}

		$hash_data = array(
			'productId'        => sanitize_text_field( (string) $product_guid ),
			'updateTitle'      => $this->rules->should_update_title() ? 1 : 0,
			'updatePrice'      => $this->rules->should_update_price() ? 1 : 0,
			'updateCompare'    => $this->rules->should_update_compare_price() ? 1 : 0,
			'updateStock'      => $this->rules->should_update_stock() ? 1 : 0,
			'updateSlug'       => $this->rules->should_update_slug() ? 1 : 0,
			'updateCategories' => $this->rules->should_update_categories() ? 1 : 0,
		);

		if ( $this->product_attributes_field_present( $data ) ) {
			$hash_data['attributes'] = $this->normalize_product_attributes_for_hash( $this->get_value( $data, 'attributes', array() ) );
		}

		if ( $this->rules->should_update_title() && $this->product_title_field_present( $data ) ) {
			$hash_data['title'] = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
		}
		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			if ( $this->product_price_field_present( $data ) ) {
				$hash_data['price'] = $this->get_value( $data, 'price', null );
			}
			if ( $this->product_compare_price_field_present( $data ) ) {
				$hash_data['comparePrice'] = $this->get_compare_price_field_value( $data, null );
			}
			$hash_data['pricePolicyHash'] = $this->build_price_policy_hash();
		}
		if ( $this->rules->should_update_stock() ) {
			$present = false;
			$stock_value = $this->get_stock_value_from_payload( $data, $present );
			if ( $present ) {
				$hash_data['stock'] = $stock_value;
			}
		}
		if ( $this->rules->should_update_slug() && $this->product_slug_field_present( $data ) ) {
			$hash_data['slug'] = $this->expected_product_slug( $data );
		}
		if ( $this->rules->should_update_categories() && $this->product_categories_field_present( $data ) ) {
			$hash_data['categories'] = $this->normalize_category_guids_for_hash( $this->get_product_category_refs_from_payload( $data ) );
		}

		$json = wp_json_encode( $this->sort_array_for_hash( $hash_data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : md5( $json );
	}

	private function product_frontend_state_matches_payload( $product, $data ) {
		if ( ! $product instanceof WC_Product || ! is_array( $data ) ) {
			return false;
		}

		if ( $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
			if ( '' !== $title && (string) $product->get_name() !== $title ) {
				return false;
			}
		}

		if ( $this->rules->should_update_slug() ) {
			$slug = $this->expected_product_slug( $data );
			if ( '' !== $slug && (string) $product->get_slug() !== $slug ) {
				return false;
			}
		}

		if ( $this->rules->should_update_stock() ) {
			$present = false;
			$stock   = $this->get_stock_value_from_payload( $data, $present );
			if ( $present && ! $this->product_stock_matches_value( $product, $stock ) ) {
				return false;
			}
		}

		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			$product_id      = absint( $product->get_id() );
			$raw_price       = $this->product_price_field_present( $data ) ? $this->get_value( $data, 'price', null ) : get_post_meta( $product_id, 'mobo_api_price', true );
			$raw_compare     = $this->product_compare_price_field_present( $data ) ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $product_id, 'mobo_api_compare_price', true );
			if ( ! $this->product_price_field_present( $data ) && ( null === $raw_price || '' === $raw_price ) ) {
				/* Compare-price-only partial payload cannot be rendered safely without the
				 * source price. Preserve storefront prices; fast bookkeeping still stores
				 * the explicit comparePrice for the next complete price payload. */
			} else {
			$pair = $this->price_calculator->calculate_price_pair(
				$product_id,
				$raw_price,
				$raw_compare,
				'product'
			);
			if ( ! empty( $pair['error'] ) ) {
				return false;
			}
			if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] && wc_format_decimal( $product->get_regular_price() ) !== wc_format_decimal( $pair['regular_price'] ) ) {
				return false;
			}
			if ( isset( $pair['sale_price'] ) && wc_format_decimal( $product->get_sale_price() ) !== wc_format_decimal( $pair['sale_price'] ) ) {
				return false;
			}
			}
		}

		if ( $this->product_attributes_field_present( $data ) && ! $this->product_attributes_match_payload( $product, $this->get_value( $data, 'attributes', array() ) ) ) {
			return false;
		}

		$published_at = sanitize_text_field( (string) $this->get_value( $data, 'publishedAt', '' ) );
		if ( '' !== $published_at && sanitize_text_field( (string) get_post_meta( $product->get_id(), 'published_at', true ) ) !== $published_at ) {
			return false;
		}

		if ( $this->rules->should_update_categories() && $this->product_categories_field_present( $data ) ) {
			$current = get_post_meta( $product->get_id(), 'mobo_product_category_guids', true );
			$current = is_array( $current ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $current ) ) ) ) : array();
			sort( $current, SORT_STRING );
			if ( $current !== $this->normalize_category_guids_for_hash( $this->get_product_category_refs_from_payload( $data ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function product_stock_matches_value( $product, $stock ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		/* Keep read-side convergence consistent with apply_api_stock(): nullable stock
		 * means the source does not manage a numeric quantity for this item. */
		if ( null === $stock || '' === $stock ) {
			return ! $product->get_manage_stock()
				&& null === $product->get_stock_quantity()
				&& 'instock' === (string) $product->get_stock_status();
		}

		$normalized = $this->normalize_api_stock_quantity( $stock );
		if ( null === $normalized ) {
			return false;
		}
		return $product->get_manage_stock()
			&& null !== $product->get_stock_quantity()
			&& (int) $product->get_stock_quantity() === (int) $normalized
			&& (string) $product->get_stock_status() === ( $normalized > 0 ? 'instock' : 'outofstock' );
	}

	private function expected_product_slug( $data ) {
		$slug = sanitize_title( (string) $this->get_value( $data, 'slug', '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( trim( (string) $this->get_value( $data, 'url', '' ), '/' ) );
		}
		return $slug;
	}

	private function normalize_product_attributes_for_hash( $attributes ) {
		$result   = array();
		$position = 0;

		foreach ( is_array( $attributes ) ? $attributes : array() as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $this->get_value( $attribute_data, 'name', '' ) );
			if ( '' === $name ) {
				continue;
			}
			$values = $this->get_value( $attribute_data, 'values', array() );
			if ( ! is_array( $values ) ) {
				continue;
			}

			$options = array();
			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}
				$value = sanitize_text_field( (string) $this->get_value( $value_data, 'value', '' ) );
				if ( '' !== $value ) {
					$options[] = $value;
				}
			}
			$options = array_values( array_unique( $options ) );
			if ( empty( $options ) ) {
				continue;
			}

			$result[] = array(
				'name'      => sanitize_title( $name ),
				'options'   => array_values( array_map( 'strval', $options ) ),
				'position'  => $position,
				'visible'   => 1,
				'variation' => 1,
			);
			$position++;
		}

		return $result;
	}

	private function current_product_attributes_for_hash( $product ) {
		$result = array();
		if ( ! $product instanceof WC_Product ) {
			return $result;
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$options = array_values( array_map( 'strval', $attribute->get_options() ) );
			$result[] = array(
				'name'      => sanitize_title( (string) $attribute->get_name() ),
				'options'   => $options,
				'position'  => absint( $attribute->get_position() ),
				'visible'   => $attribute->get_visible() ? 1 : 0,
				'variation' => $attribute->get_variation() ? 1 : 0,
			);
		}
		return $result;
	}

	private function product_attributes_match_payload( $product, $attributes ) {
		return $this->current_product_attributes_for_hash( $product ) === $this->normalize_product_attributes_for_hash( $attributes );
	}

	private function normalize_category_guids_for_hash( $refs ) {
		$guids = array();
		if ( is_array( $refs ) ) {
			foreach ( $refs as $ref ) {
				if ( is_array( $ref ) ) {
					$guid = $this->extract_category_guid_for_storage( $ref );
				} else {
					$guid = sanitize_text_field( (string) $ref );
				}
				if ( '' !== $guid && $this->is_remote_guid_value( $guid ) ) {
					$guids[] = $guid;
				}
			}
		}
		$guids = array_values( array_unique( array_filter( $guids ) ) );
		sort( $guids, SORT_STRING );
		return $guids;
	}

	private function sync_product_identity_meta_fast( $product_id, $data, $product_guid ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( ! $this->persist_post_meta_verified( $product_id, 'product_guid', sanitize_text_field( (string) $product_guid ) ) ) {
			return false;
		}
		$portal_product_id = $this->extract_portal_product_id( $data );
		if ( $portal_product_id > 0 ) {
			foreach ( array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' ) as $key ) {
				if ( ! $this->persist_post_meta_verified( $product_id, $key, $portal_product_id ) ) {
					return false;
				}
			}
		}
		$url = sanitize_text_field( (string) $this->get_value( $data, 'url', '' ) );
		if ( '' !== $url && ! $this->persist_post_meta_verified( $product_id, 'mobo_url', $url ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Persist source-price bookkeeping without invoking WooCommerce CRUD/cache hooks.
	 * Used only after the rendered price has already been verified as converged.
	 *
	 * @param int   $post_id Product/variation ID.
	 * @param array $data Source payload.
	 * @return bool True only when all correctness-relevant bookkeeping converged.
	 */
	private function sync_price_source_meta_fast( $post_id, $data ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! is_array( $data ) ) {
			return false;
		}
		if ( ! $this->rules->should_update_price() && ! $this->rules->should_update_compare_price() ) {
			return true;
		}

		$price_present   = $this->product_price_field_present( $data );
		$compare_present = $this->product_compare_price_field_present( $data );
		if ( ! $price_present && ! $compare_present ) {
			return true;
		}

		$raw_price   = $price_present ? $this->get_value( $data, 'price', null ) : get_post_meta( $post_id, 'mobo_api_price', true );
		$raw_compare = $compare_present ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $post_id, 'mobo_api_compare_price', true );
		$changed     = false;

		if ( $price_present ) {
			$desired = null === $raw_price || '' === $raw_price ? '' : wc_format_decimal( $raw_price );
			$changed = maybe_serialize( get_post_meta( $post_id, 'mobo_api_price', true ) ) !== maybe_serialize( $desired ) || $changed;
			if ( ! $this->persist_post_meta_verified( $post_id, 'mobo_api_price', $desired ) ) {
				return false;
			}
		}
		if ( $compare_present ) {
			$desired = null === $raw_compare || '' === $raw_compare ? '' : wc_format_decimal( $raw_compare );
			$changed = maybe_serialize( get_post_meta( $post_id, 'mobo_api_compare_price', true ) ) !== maybe_serialize( $desired ) || $changed;
			if ( ! $this->persist_post_meta_verified( $post_id, 'mobo_api_compare_price', $desired ) ) {
				return false;
			}
		}

		$fast_context = 'product_variation' === get_post_type( $post_id ) ? 'variation' : 'product';
		$fast_parent_id = 'variation' === $fast_context ? absint( wp_get_post_parent_id( $post_id ) ) : 0;
		$policy_type = class_exists( 'Mobo_Core_Product_Pricing_Policy' ) ? Mobo_Core_Product_Pricing_Policy::effective_policy_type( $post_id, $fast_parent_id, $fast_context ) : (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		$changed     = (string) get_post_meta( $post_id, 'mobo_price_policy_type', true ) !== $policy_type || $changed;
		if ( ! $this->persist_post_meta_verified( $post_id, 'mobo_price_policy_type', $policy_type ) ) {
			return false;
		}

		if ( $changed && ! $this->persist_post_meta_verified( $post_id, 'mobo_price_policy_updated_at', gmdate( 'c' ) ) ) {
			return false;
		}
		return true;
	}

	private function update_post_meta_if_changed( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}
		$current = get_post_meta( $post_id, $key, true );
		if ( $current == $value ) { // Intentional loose compare for numeric metadata stored as strings.
			return false;
		}
		return false !== update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Persist a correctness-critical postmeta value and verify the exact read-back.
	 * Unlike update_post_meta_if_changed(), true means durable convergence rather
	 * than "a write was attempted/changed". This is used for crash markers and
	 * ordering watermarks that must survive before an event can be acknowledged.
	 */
	private function persist_post_meta_verified( $post_id, $key, $value ) {
		return Mobo_Core_Durable_State_Policy::update_post_meta_verified( $post_id, $key, $value );
	}

	private function delete_post_meta_verified( $post_id, $key ) {
		$post_id = absint( $post_id );
		$key     = sanitize_key( (string) $key );
		if ( $post_id <= 0 || '' === $key ) {
			return false;
		}
		if ( metadata_exists( 'post', $post_id, $key ) ) {
			delete_post_meta( $post_id, $key );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		return ! metadata_exists( 'post', $post_id, $key );
	}

	private function persist_ordering_watermarks( $post_id, $scope, $source_version, $source_revision, $applied = false ) {
		$post_id         = absint( $post_id );
		$scope           = 'variant' === sanitize_key( (string) $scope ) ? 'variant' : 'product';
		$source_version  = sanitize_text_field( (string) $source_version );
		$source_revision = absint( $source_revision );
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( '' !== $source_version && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_seen_event_version', $source_version ) ) {
			return false;
		}
		if ( $source_revision > 0 && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_seen_revision', $source_revision ) ) {
			return false;
		}
		if ( $applied && '' !== $source_version && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_applied_event_version', $source_version ) ) {
			return false;
		}
		if ( $applied && $source_revision > 0 && ! $this->persist_post_meta_verified( $post_id, '_mobo_' . $scope . '_applied_revision', $source_revision ) ) {
			return false;
		}
		return true;
	}

	private function delete_post_meta_if_present( $post_id, $key ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! metadata_exists( 'post', $post_id, $key ) ) {
			return false;
		}
		return (bool) delete_post_meta( $post_id, $key );
	}

	/**
	 * Queue WooCommerce object metadata only when the persisted/edit value changed.
	 * This keeps WC_Data::save_meta_data() from issuing UPDATEs for identical Mobo
	 * bookkeeping values during price/stock-only syncs.
	 *
	 * @param WC_Product $product Product or variation object.
	 * @param string     $key Meta key.
	 * @param mixed      $value Desired value.
	 * @return bool True when a metadata mutation was queued.
	 */
	private function update_product_meta_if_changed( $product, $key, $value ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$current = $product->get_meta( $key, true, 'edit' );
		if ( $current == $value ) { // Intentional loose compare for numeric metadata stored as strings.
			return false;
		}

		$product->update_meta_data( $key, $value );
		return true;
	}

	private function delete_product_meta_if_present( $product, $key ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = absint( $product->get_id() );
		$exists     = $product_id > 0
			? metadata_exists( 'post', $product_id, $key )
			: '' !== (string) $product->get_meta( $key, true, 'edit' );

		if ( ! $exists ) {
			return false;
		}

		$product->delete_meta_data( $key );
		return true;
	}

	/**
	 * Remove obsolete stock diagnostics even when storefront state is already
	 * converged and the WooCommerce CRUD no-op path is taken.
	 *
	 * @param int   $post_id Product/variation ID.
	 * @param array $data Source payload.
	 * @return void
	 */
	private function cleanup_stock_diagnostic_meta_fast( $post_id, $data ) {
		if ( ! $this->rules->should_update_stock() || ! is_array( $data ) ) {
			return;
		}

		$present = false;
		$stock   = $this->get_stock_value_from_payload( $data, $present );
		if ( ! $present ) {
			return;
		}

		$changed = $this->delete_post_meta_if_present( $post_id, '_mobo_stock_payload_missing' );
		if ( null === $stock || '' === $stock ) {
			$changed = $this->delete_post_meta_if_present( $post_id, '_mobo_last_api_stock_quantity' ) || $changed;
		}

		if ( $changed ) {
			$this->update_post_meta_if_changed( $post_id, '_mobo_last_api_stock_applied_at', gmdate( 'c' ) );
		}
	}

	private function store_product_attribute_guids_if_changed( $product_id, $attributes ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		return $this->store_product_attribute_guids( $product_id, is_array( $attributes ) ? $attributes : array() );
	}

	private function store_product_category_refs_if_changed( $product_id, $category_refs ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		/* Always verify both halves of the stored category reference state. A stale
		 * refs JSON must not survive merely because the GUID list already matches. */
		return $this->store_product_category_refs( $product_id, is_array( $category_refs ) ? $category_refs : array() );
	}

	private function build_product_images_source_hash( $images ) {
		$images = is_array( $images ) ? $images : array();
		$json   = wp_json_encode( $this->sort_array_for_hash( $images ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : md5( $json );
	}

	private function product_images_need_processing( $product_id, $images, $is_new = false ) {
		$product_id = absint( $product_id );
		$images     = is_array( $images ) ? $images : array();
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( empty( $images ) ) {
			$incoming = $this->build_product_images_source_hash( array() );
			$stored   = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_images_source_hash', true ) );
			$product  = wc_get_product( $product_id );
			$has_linked_images = $product instanceof WC_Product && ( absint( $product->get_image_id() ) > 0 || ! empty( $product->get_gallery_image_ids() ) );
			$has_queue_rows = false;
			if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
				$queue = new Mobo_Core_Image_Queue();
				$has_queue_rows = ! empty( $queue->get_ordered_rows_for_product( $product_id ) );
			}
			return $is_new || $incoming !== $stored || $has_linked_images || $has_queue_rows;
		}
		if ( $is_new ) {
			return true;
		}
		$incoming = $this->build_product_images_source_hash( $images );
		$stored   = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_product_images_source_hash', true ) );
		if ( '' === $incoming || $incoming !== $stored ) {
			return true;
		}

		/*
		 * A source hash proves only that Portal sent the same payload again; it does
		 * not prove local storage is still converged. Older releases checked only for
		 * a featured image, so a deleted gallery file, missing durable queue row or a
		 * stale attachment could remain broken forever behind the no-op fast path.
		 */
		return ! $this->product_image_fast_path_is_healthy( $product_id, $images );
	}

	/**
	 * Verify the cheap, local invariants required before skipping image sync.
	 * No file is generated or deleted here; any drift simply re-enters the normal
	 * queue where the stricter storage/readiness checks perform the repair.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Desired image payload.
	 * @return bool
	 */
	private function product_image_fast_path_is_healthy( $product_id, $images ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return false;
		}

		$desired = array();
		foreach ( array_values( is_array( $images ) ? $images : array() ) as $position => $image ) {
			if ( ! is_array( $image ) ) {
				return false;
			}

			$image_guid = Mobo_Core_Image_Desired_State_Policy::image_guid( $image );
			$url        = Mobo_Core_Image_Desired_State_Policy::image_url( $image );

			if ( '' === $image_guid || '' === $url ) {
				return false;
			}

			$desired[] = array(
				'image_guid' => $image_guid,
				'source_url' => $url,
				'position'   => absint( $position ),
			);
		}

		$queue = new Mobo_Core_Image_Queue();
		$rows  = $queue->get_ordered_rows_for_product( $product_id );
		if ( count( $rows ) !== count( $desired ) ) {
			return false;
		}

		$attachment_ids = array();
		$deep_health_service = class_exists( 'Mobo_Core_Image_Refresh_Service' ) ? new Mobo_Core_Image_Refresh_Service() : null;
		foreach ( $desired as $index => $wanted ) {
			$row = isset( $rows[ $index ] ) && is_array( $rows[ $index ] ) ? $rows[ $index ] : array();
			if ( 'done' !== sanitize_key( (string) ( isset( $row['status'] ) ? $row['status'] : '' ) )
				|| strtolower( trim( sanitize_text_field( (string) ( isset( $row['image_guid'] ) ? $row['image_guid'] : '' ) ) ) ) !== strtolower( trim( $wanted['image_guid'] ) )
				|| esc_url_raw( (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ) ) !== $wanted['source_url']
				|| absint( isset( $row['position_index'] ) ? $row['position_index'] : -1 ) !== $wanted['position'] ) {
				return false;
			}

			$attachment_id = absint( isset( $row['attachment_id'] ) ? $row['attachment_id'] : 0 );
			if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
				return false;
			}

			$stored_source = esc_url_raw( (string) get_post_meta( $attachment_id, 'mobo_source_url', true ) );
			$stored_guid   = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
			if ( '' === $stored_guid ) {
				$stored_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'img_guid', true ) );
			}
			if ( $stored_source !== $wanted['source_url'] || ! hash_equals( strtolower( trim( $stored_guid ) ), strtolower( trim( $wanted['image_guid'] ) ) ) ) {
				return false;
			}

			$wanted_path = (string) wp_parse_url( (string) $wanted['source_url'], PHP_URL_PATH );
			$wanted_webp = 'webp' === strtolower( pathinfo( $wanted_path, PATHINFO_EXTENSION ) );
			$shared_attachment = class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id );

			if ( ( $wanted_webp || $shared_attachment ) && $deep_health_service && method_exists( $deep_health_service, 'inspect_webp_attachment_health' ) ) {
				$health = $deep_health_service->inspect_webp_attachment_health( $attachment_id );
				if ( empty( $health['healthy'] ) ) {
					return false;
				}
			} elseif ( $shared_attachment ) {
				/* Shared Media must never take a shallow fast path if the deep verifier is
				 * unavailable; entering the queue is safer than accepting stale manifest data. */
				return false;
			} else {
				$file = get_attached_file( $attachment_id );
				$file = is_string( $file ) ? $file : '';
				if ( '' === $file || ! is_file( $file ) ) {
					return false;
				}
				$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Concurrent deletion is treated as drift.
				if ( false === $size || $size <= 0 ) {
					return false;
				}
				if ( function_exists( 'wp_get_image_mime' ) ) {
					$mime = strtolower( (string) wp_get_image_mime( $file ) );
					if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
						return false;
					}
				}
			}

			$attachment_ids[] = $attachment_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$raw_gallery_ids = array_values( array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ) );
		$current_gallery = array_values( array_unique( $raw_gallery_ids ) );
		$image_id        = absint( $product->get_image_id() );

		/* Do not normalize corruption away. Duplicate gallery entries or a featured
		 * image repeated inside _product_image_gallery are local drift and must re-enter
		 * image sync so WooCommerce metadata is repaired. */
		if ( count( $raw_gallery_ids ) !== count( $current_gallery ) || ( $image_id > 0 && in_array( $image_id, $current_gallery, true ) ) ) {
			return false;
		}

		$current_ids = $current_gallery;
		if ( $image_id > 0 ) {
			array_unshift( $current_ids, $image_id );
		}

		return $current_ids === array_values( array_unique( $attachment_ids ) );
	}

	private function mark_product_images_pending( $product_id, $images ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		$hash = $this->build_product_images_source_hash( $images );
		if ( '' === $hash || ! $this->persist_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash', $hash ) ) {
			return false;
		}
		return $this->persist_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash_updated_at', gmdate( 'c' ) );
	}

	private function mark_product_images_converged( $product_id, $images ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		$hash = $this->build_product_images_source_hash( $images );
		if ( '' === $hash || ! $this->persist_post_meta_verified( $product_id, '_mobo_product_images_source_hash', $hash ) ) {
			return false;
		}
		if ( ! $this->persist_post_meta_verified( $product_id, '_mobo_product_images_source_hash_updated_at', gmdate( 'c' ) ) ) {
			return false;
		}
		return $this->delete_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash' )
			&& $this->delete_post_meta_verified( $product_id, '_mobo_product_images_pending_source_hash_updated_at' );
	}


	/**
	 * Return true only when every concrete variation in a delta is provably older
	 * than the durable per-variation watermark. Unknown/new identities make the
	 * answer false so we fail open to normal validation rather than drop work.
	 *
	 * @param int    $parent_id Parent product ID.
	 * @param array  $variants Variant payloads.
	 * @param string $source_version Generic event ordering watermark.
	 * @param int    $source_revision Numeric source revision.
	 * @return bool
	 */
	private function delta_variant_payload_is_fully_stale( $parent_id, $variants, $source_version = '', $source_revision = 0 ) {
		$parent_id       = absint( $parent_id );
		$source_version  = sanitize_text_field( (string) $source_version );
		$source_revision = absint( $source_revision );
		if ( $parent_id <= 0 || ! is_array( $variants ) || empty( $variants ) || ( '' === $source_version && $source_revision <= 0 ) ) {
			return false;
		}

		$checked = 0;
		foreach ( $variants as $variant_data ) {
			if ( ! is_array( $variant_data ) ) {
				return false;
			}
			$variant_guid = $this->extract_variant_guid( $variant_data );
			if ( '' === $variant_guid ) {
				return false;
			}
			$variation_id = $this->find_variation_id_by_guid( $variant_guid );
			if ( $variation_id <= 0 || absint( wp_get_post_parent_id( $variation_id ) ) !== $parent_id ) {
				return false;
			}

			$is_stale = false;
			if ( '' !== $source_version ) {
				$seen_version = sanitize_text_field( (string) get_post_meta( $variation_id, '_mobo_variant_seen_event_version', true ) );
				if ( '' !== $seen_version && -1 === $this->compare_source_versions( $source_version, $seen_version ) ) {
					$is_stale = true;
				}
			}
			if ( ! $is_stale && $source_revision > 0 ) {
				$seen_revision = absint( get_post_meta( $variation_id, '_mobo_variant_seen_revision', true ) );
				if ( $seen_revision > $source_revision ) {
					$is_stale = true;
				}
			}
			if ( ! $is_stale ) {
				return false;
			}
			$checked++;
		}

		return $checked > 0;
	}

	/**
	 * Verify the storefront-relevant state of one existing variation.
	 *
	 * This allows a hash/policy change to refresh only Mobo internal metadata when
	 * title/price/stock/attributes are already converged, avoiding a WooCommerce
	 * variation save and the hooks/cache churn attached to it.
	 *
	 * @param int   $variation_id Variation ID.
	 * @param int   $parent_id Expected parent ID.
	 * @param array $data Desired variation payload.
	 * @return bool
	 */
	private function variation_frontend_state_matches_payload( $variation_id, $parent_id, $data ) {
		$variation = wc_get_product( absint( $variation_id ) );
		if ( ! $variation instanceof WC_Product_Variation || ! is_array( $data ) ) {
			return false;
		}

		if ( absint( $variation->get_parent_id() ) !== absint( $parent_id ) || 'publish' !== (string) $variation->get_status() ) {
			return false;
		}

		if ( $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
			if ( '' !== $title && (string) $variation->get_name() !== $title ) {
				return false;
			}
		}

		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			$raw_price   = $this->product_price_field_present( $data ) ? $this->get_value( $data, 'price', null ) : get_post_meta( absint( $variation_id ), 'mobo_api_price', true );
			$raw_compare = $this->product_compare_price_field_present( $data ) ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( absint( $variation_id ), 'mobo_api_compare_price', true );
			if ( $this->product_price_field_present( $data ) || ( null !== $raw_price && '' !== $raw_price ) ) {
				$pair = $this->price_calculator->calculate_price_pair(
					absint( $variation_id ),
					$raw_price,
					$raw_compare,
					'variation',
					absint( $parent_id )
				);
				if ( ! empty( $pair['error'] ) ) {
					return false;
				}
				if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] && wc_format_decimal( $variation->get_regular_price() ) !== wc_format_decimal( $pair['regular_price'] ) ) {
					return false;
				}
				if ( isset( $pair['sale_price'] ) && wc_format_decimal( $variation->get_sale_price() ) !== wc_format_decimal( $pair['sale_price'] ) ) {
					return false;
				}
			}
		}

		if ( $this->rules->should_update_stock() && ! $this->variation_stock_matches_payload( $variation_id, $data ) ) {
			return false;
		}

		$incoming_attributes = $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) );
		if ( $this->variation_attribute_signature( $variation->get_attributes() ) !== $this->variation_attribute_signature( $incoming_attributes ) ) {
			return false;
		}

		return true;
	}


	private function build_variation_source_hash( $data, $product_guid = '' ) {
		if ( ! is_array( $data ) || ! $this->variation_payload_has_complete_source_hash_state( $data ) ) {
			return '';
		}

		$hash_data = array(
			'variantId'     => $this->extract_variant_guid( $data ),
			'productId'     => sanitize_text_field( (string) $product_guid ),
			'attributes'    => $this->get_value( $data, 'attributes', array() ),
			'updateTitle'   => $this->rules->should_update_title() ? 1 : 0,
			'updatePrice'   => $this->rules->should_update_price() ? 1 : 0,
			'updateCompare' => $this->rules->should_update_compare_price() ? 1 : 0,
			'updateStock'   => $this->rules->should_update_stock() ? 1 : 0,
		);

		if ( $this->rules->should_update_title() && $this->product_title_field_present( $data ) ) {
			$hash_data['title'] = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
		}

		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			if ( $this->product_price_field_present( $data ) ) {
				$hash_data['price'] = $this->get_value( $data, 'price', null );
			}
			if ( $this->product_compare_price_field_present( $data ) ) {
				$hash_data['comparePrice'] = $this->get_compare_price_field_value( $data, null );
			}
			$hash_data['pricePolicyHash'] = $this->build_price_policy_hash();
		}

		if ( $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );
			if ( $stock_present ) {
				$hash_data['stock'] = $stock_value;
			}
		}

		$hash_data = $this->sort_array_for_hash( $hash_data );
		$json      = wp_json_encode( $hash_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : md5( $json );
	}

	private function build_price_policy_hash() {
		$policy = array(
			'mobo_price_type'                   => (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' ),
			'global_additional_price'           => (string) Mobo_Core_Settings::get( 'global_additional_price', 0 ),
			'global_additional_percentage'      => (string) Mobo_Core_Settings::get( 'global_additional_percentage', 0 ),
			'global_product_auto_compare_price' => (string) Mobo_Core_Settings::get( 'global_product_auto_compare_price', '0' ),
			'mobo_dynamic_price'                => (string) Mobo_Core_Settings::get( 'mobo_dynamic_price', '[]' ),
		);

		$json = wp_json_encode( $this->sort_array_for_hash( $policy ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? '' : md5( $json );
	}

	private function sort_array_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! $this->is_list_array( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_array_for_hash( $item );
		}

		return $value;
	}

	/**
	 * Resolve whether the desired/current product is variable without treating an
	 * omitted partial-payload attributes field as an explicit empty attribute set.
	 */
	private function product_payload_can_have_variants( $data, $product_id = 0 ) {
		if ( $this->product_attributes_field_present( $data ) ) {
			return $this->product_has_variation_attributes( $data );
		}

		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product_Variable ) {
			return true;
		}
		if ( $product instanceof WC_Product ) {
			foreach ( $product->get_attributes() as $attribute ) {
				if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
					return true;
				}
			}
		}

		return false;
	}

	private function product_has_variation_attributes( $data ) {
		$attributes = $this->get_value( $data, 'attributes', array() );

		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return false;
		}

		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) $this->get_value( $attribute_data, 'name', '' ) );

			if ( '' === $name ) {
				continue;
			}

			$values = $this->get_value( $attribute_data, 'values', array() );

			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $this->get_value( $value_data, 'value', '' ) );

				if ( '' !== $value ) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Fetch and validate the authoritative simple-product Variant snapshot.
	 *
	 * This method is deliberately read-only with respect to WooCommerce/Mobo state.
	 * Callers that are not already under the shared product lock can therefore keep
	 * Portal HTTP outside the critical section and serialize only the local commit.
	 *
	 * @param Mobo_Core_API_Client $api API client.
	 * @param string               $product_guid Remote product GUID.
	 * @param int                  $product_id WooCommerce product ID.
	 * @param string               $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	private function fetch_simple_product_variant_snapshot_from_api( $api, $product_guid, $product_id, $sync_id ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$product_id   = absint( $product_id );
		$sync_id      = sanitize_text_field( (string) $sync_id );

		if ( ! is_object( $api ) || ! is_callable( array( $api, 'get_variants_page' ) ) || '' === $product_guid || $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_simple_variant_invalid_context', 'Simple product variant sync context is invalid.' );
		}

		$response = $api->get_variants_page( $product_guid, 1, 2, $sync_id, 0, false );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$variants = $this->validated_variant_page_items( $response );
		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		$page_has_more = $this->validated_page_has_more( $response, 1 );
		if ( is_wp_error( $page_has_more ) ) {
			return $page_has_more;
		}
		if ( $page_has_more ) {
			return new WP_Error( 'mobo_core_simple_variant_pagination_ambiguous', 'Simple product variant lookup returned additional pages; automatic selection is unsafe.' );
		}

		$total      = absint( $this->get_value( $response, 'totalCount', count( $variants ) ) );
		$total      = max( $total, count( $variants ) );
		$valid_rows = array_values( array_filter( $variants, array( $this, 'looks_like_variant_payload' ) ) );

		if ( 1 === count( $valid_rows ) && $total <= 1 ) {
			return array(
				'mode'       => 'mapped',
				'variant'    => $valid_rows[0],
				'totalCount' => $total,
			);
		}

		if ( 0 === count( $valid_rows ) && 0 === $total ) {
			return array(
				'mode'       => 'authoritative_missing',
				'totalCount' => 0,
				'message'    => 'محصول ساده در موبو هیچ Variant قابل خریدی ندارد؛ state معتبر ولی غیرقابل‌خرید به‌صورت ناموجود ثبت شد.',
			);
		}

		return array(
			'mode'       => 'ambiguous',
			'totalCount' => $total,
			'validCount' => count( $valid_rows ),
			'message'    => sprintf( 'برای محصول ساده پاسخ Variant مبهم است (%d مورد معتبر، totalCount=%d). انتخاب خودکار ناامن است؛ محصول برای خرید مسدود شد.', count( $valid_rows ), $total ),
		);
	}

	/**
	 * Apply a previously validated simple-product Variant snapshot.
	 *
	 * The caller is responsible for holding the shared product lock whenever this
	 * method can reach topology/quarantine mutation.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $product_guid Product GUID.
	 * @param array  $snapshot Validated snapshot.
	 * @return array|WP_Error
	 */
	private function apply_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$mode         = is_array( $snapshot ) ? sanitize_key( (string) ( isset( $snapshot['mode'] ) ? $snapshot['mode'] : '' ) ) : '';

		if ( $product_id <= 0 || '' === $product_guid || '' === $mode ) {
			return new WP_Error( 'mobo_core_simple_variant_snapshot_invalid', 'Validated simple-product Variant snapshot is invalid.' );
		}

		if ( 'mapped' === $mode ) {
			$variant = isset( $snapshot['variant'] ) && is_array( $snapshot['variant'] ) ? $snapshot['variant'] : array();
			$result  = $this->apply_simple_variant_to_product( $product_id, $variant, $product_guid );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'         => true,
				'productId'       => $product_id,
				'portalVariantId' => isset( $result['portalVariantId'] ) ? absint( $result['portalVariantId'] ) : 0,
				'variantGuid'     => isset( $result['variantGuid'] ) ? sanitize_text_field( (string) $result['variantGuid'] ) : '',
				'message'         => 'شناسه قابل خرید محصول ساده همگام شد.',
			);
		}

		if ( 'authoritative_missing' === $mode ) {
			$empty_state = $this->apply_authoritative_simple_without_purchase_variant( $product_id, $product_guid );
			if ( is_wp_error( $empty_state ) ) {
				return $empty_state;
			}

			return array(
				'success'              => true,
				'productId'            => $product_id,
				'portalVariantId'      => 0,
				'variantGuid'          => '',
				'purchasable'          => false,
				'authoritativeMissing' => true,
				'message'              => isset( $snapshot['message'] ) ? sanitize_text_field( (string) $snapshot['message'] ) : 'Authoritative Source topology contains no purchasable Variant.',
			);
		}

		if ( 'ambiguous' === $mode ) {
			$message = isset( $snapshot['message'] ) ? sanitize_text_field( (string) $snapshot['message'] ) : 'Simple product Variant snapshot is ambiguous.';
			$this->mark_simple_product_variant_unresolved( $product_id, 'ambiguous', $message );
			return new WP_Error( 'mobo_core_simple_variant_ambiguous', $message );
		}

		return new WP_Error( 'mobo_core_simple_variant_snapshot_mode_invalid', 'Simple-product Variant snapshot mode is invalid.' );
	}

	/**
	 * Revalidate the exact parent before a Manual/Repair Step-5 Simple commit.
	 *
	 * No mutation is performed here. The caller already holds the shared product
	 * lock, so a successful proof remains stable until the subsequent commit.
	 *
	 * @param int    $product_id Expected WooCommerce product ID.
	 * @param string $product_guid Expected remote product GUID.
	 * @param array  $expected_attributes Parent attributes from the accepted snapshot.
	 * @return true|WP_Error
	 */
	private function revalidate_manual_simple_product_target( $product_id, $product_guid, $expected_attributes ) {
		$product_id          = absint( $product_id );
		$product_guid        = sanitize_text_field( (string) $product_guid );
		$expected_attributes = is_array( $expected_attributes ) ? $expected_attributes : array();

		if ( $product_id <= 0 || '' === $product_guid ) {
			return new WP_Error( 'mobo_core_manual_simple_revalidation_invalid', 'Manual Simple commit target identity is invalid.' );
		}

		$post = get_post( $product_id );
		if ( ! $post instanceof WP_Post
			|| 'product' !== (string) $post->post_type
			|| in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true )
		) {
			return new WP_Error( 'mobo_core_manual_simple_parent_missing', 'Manual Simple commit parent no longer exists as an active product.' );
		}

		$stored_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
		if ( '' === $stored_guid || ! hash_equals( $product_guid, $stored_guid ) ) {
			return new WP_Error( 'mobo_core_manual_simple_parent_identity_changed', 'Manual Simple commit parent identity changed before lock acquisition.' );
		}

		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$ids = Mobo_Core_Product_Concurrency::get_product_ids_by_guid( $product_guid );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
			if ( 1 !== count( $ids ) || $product_id !== absint( $ids[0] ) ) {
				return new WP_Error( 'mobo_core_manual_simple_parent_ownership_ambiguous', 'Manual Simple commit parent GUID ownership is no longer unique.' );
			}
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$mapped_id = absint( $this->product_map->get_product_id( $product_guid ) );
			if ( $mapped_id > 0 && $mapped_id !== $product_id ) {
				return new WP_Error( 'mobo_core_manual_simple_parent_map_changed', 'Manual Simple commit Product Map ownership changed before mutation.' );
			}
		}

		$current = wc_get_product( $product_id );
		if ( ! $current instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_manual_simple_parent_unloadable', 'Manual Simple commit parent could not be reloaded inside the product lock.' );
		}

		if ( $this->product_attribute_structure_changed( $current, $expected_attributes ) ) {
			return new WP_Error( 'mobo_core_manual_simple_parent_topology_changed', 'Manual Simple commit parent attribute topology changed before mutation.' );
		}

		return true;
	}

	/**
	 * Commit Manual/Repair Step-5 Simple state under the shared parent product lock.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $product_guid Product GUID.
	 * @param array  $snapshot Validated Portal snapshot fetched outside the lock.
	 * @param array  $expected_attributes Parent attributes from the accepted product snapshot.
	 * @return array|WP_Error
	 */
	private function commit_manual_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot, $expected_attributes ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( $product_id <= 0 || '' === $product_guid || ! is_array( $snapshot ) ) {
			return new WP_Error( 'mobo_core_manual_simple_commit_invalid', 'Manual Simple commit context is invalid.' );
		}

		if ( ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			return new WP_Error( 'mobo_core_manual_simple_lock_unavailable', 'Shared product lock service is unavailable; Manual Simple mutation was deferred.' );
		}

		$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 5, 180 );
		if ( false === $lock ) {
			return new WP_Error( 'mobo_core_manual_simple_product_lock_busy', 'Shared parent product lock is busy; Manual Simple mutation was deferred.' );
		}

		try {
			$revalidated = $this->revalidate_manual_simple_product_target( $product_id, $product_guid, $expected_attributes );
			if ( is_wp_error( $revalidated ) ) {
				return $revalidated;
			}

			return $this->apply_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot );
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $lock );
		}
	}

	/**
	 * Resolve and persist the one purchasable Mobo variant of a simple product.
	 *
	 * Existing guarded webhook/variant callers already hold the shared product lock.
	 * Manual/Repair Step 5 uses fetch_simple_product_variant_snapshot_from_api()
	 * followed by commit_manual_simple_product_variant_snapshot() instead.
	 *
	 * @param Mobo_Core_API_Client $api API client.
	 * @param string               $product_guid Remote product GUID.
	 * @param int                  $product_id WooCommerce product ID.
	 * @param string               $sync_id Sync ID.
	 * @return array|WP_Error
	 */
	private function sync_simple_product_variant_from_api( $api, $product_guid, $product_id, $sync_id ) {
		$snapshot = $this->fetch_simple_product_variant_snapshot_from_api( $api, $product_guid, $product_id, $sync_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		return $this->apply_simple_product_variant_snapshot( $product_id, $product_guid, $snapshot );
	}

	/**
	 * Persist a Mobo storefront variant on WC_Product_Simple.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param array  $variant_data Variant payload.
	 * @param string $product_guid Product GUID.
	 * @return array|WP_Error
	 */
	private function apply_simple_variant_to_product( $product_id, $variant_data, $product_guid = '' ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( $product_id <= 0 || ! is_array( $variant_data ) ) {
			return new WP_Error( 'mobo_core_simple_variant_invalid_payload', 'Simple product variant payload is invalid.' );
		}

		$money_integrity = $this->validate_money_payload_fields( $variant_data, 'simple-variant' );
		if ( is_wp_error( $money_integrity ) ) {
			return $money_integrity;
		}

		$portal_variant_id = $this->extract_portal_variant_id( $variant_data );
		$variant_guid      = $this->extract_simple_variant_guid( $variant_data );

		if ( $portal_variant_id <= 0 ) {
			$this->mark_simple_product_variant_unresolved( $product_id, 'portal_variant_id_missing', 'شناسه قابل خرید موبو برای محصول ساده در پاسخ API موجود نیست.' );
			return new WP_Error( 'mobo_core_simple_portal_variant_id_missing', 'Simple Mobo product portal_variant_id is missing.' );
		}

		$incoming_hash = $this->build_variation_source_hash( $variant_data, $product_guid );
		$current       = wc_get_product( $product_id );
		$old_hash      = sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_simple_variant_source_hash', true ) );
		$old_mapped    = '1' === sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true ) );
		$old_incomplete = sanitize_text_field( (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true ) );

		if ( ! $this->is_repair_mode() && $old_mapped && '1' !== $old_incomplete && '' !== $incoming_hash && $old_hash === $incoming_hash && $current instanceof WC_Product_Simple && $this->simple_product_matches_variant_payload( $current, $variant_data ) ) {
			$this->store_simple_variant_identity_meta_fast( $product_id, $variant_data, $product_guid );
			return array(
				'productId'       => $product_id,
				'portalVariantId' => $portal_variant_id,
				'variantGuid'     => $variant_guid,
				'changed'         => false,
			);
		}

		if ( $this->variant_has_selection_attributes( $variant_data ) ) {
			return new WP_Error( 'mobo_core_simple_variant_has_attributes', 'A simple product variant unexpectedly contains selection attributes.' );
		}

		if ( ! $old_mapped || $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) {
			$price_validation = $this->price_calculator->calculate_price_pair(
				$product_id,
				$this->get_value( $variant_data, 'price', null ),
				$this->get_compare_price_field_value( $variant_data, null ),
				'product'
			);
			if ( ! empty( $price_validation['error'] ) ) {
				return new WP_Error( 'mobo_core_simple_price_invalid', sanitize_text_field( (string) $price_validation['error'] ) );
			}
		}

		if ( ! $old_mapped || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $variant_data, $stock_present );
			/* Portal exposes stock as a nullable integer. An explicit JSON null is not a
			 * malformed value; it means stock is unspecified and must follow the same
			 * semantics already implemented by apply_api_stock( null ). Only non-empty
			 * values that cannot be normalized are rejected fail-closed. */
			if ( $stock_present && ! $this->is_valid_api_stock_payload_value( $stock_value ) ) {
				return new WP_Error( 'mobo_core_simple_stock_invalid', 'Simple Mobo product stock is present but invalid.' );
			}
		}

		$simple_conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $simple_conversion ) ) {
			return $simple_conversion;
		}
		$product = new WC_Product_Simple( $product_id );

		$was_mapped = '1' === sanitize_text_field( (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true ) );
		$this->update_product_meta_if_changed( $product, 'portal_variant_id', $portal_variant_id );
		$this->update_product_meta_if_changed( $product, 'mobo_portal_variant_id', $portal_variant_id );
		$this->update_product_meta_if_changed( $product, '_mobo_portal_variant_id', $portal_variant_id );

		if ( '' !== $variant_guid ) {
			$this->update_product_meta_if_changed( $product, 'variant_guid', $variant_guid );
			$this->update_product_meta_if_changed( $product, 'mobo_variant_guid', $variant_guid );
			$this->update_product_meta_if_changed( $product, '_mobo_variant_guid', $variant_guid );
		}

		if ( '' !== $product_guid ) {
			$product->update_meta_data( 'product_guid', $product_guid );
		}

		$this->store_portal_product_id_on_product_object( $product, $variant_data, $product_id );
		$this->apply_price_to_product( $product, $variant_data, 'product', ! $was_mapped );

		if ( ! $was_mapped || $this->rules->should_update_stock() ) {
			$stock_present = false;
			$stock_value   = $this->get_stock_value_from_payload( $variant_data, $stock_present );

			if ( $stock_present ) {
				if ( ! $this->apply_api_stock( $product, $stock_value ) ) {
					return new WP_Error( 'mobo_core_simple_stock_invalid', 'Simple Mobo product stock is present but invalid.' );
				}
			} elseif ( ! $was_mapped ) {
				$this->apply_api_stock( $product, null );
			}
		}

		$product->update_meta_data( '_mobo_simple_variant_mapped', '1' );
		$product->update_meta_data( '_mobo_simple_variant_mapped_at', gmdate( 'c' ) );
		$product->update_meta_data( '_mobo_simple_variant_resolution_status', 'mapped' );
		$product->update_meta_data( '_mobo_simple_variant_source_hash', $incoming_hash );
		$product->delete_meta_data( '_mobo_simple_variant_resolution_message' );
		$saved_id = absint( $product->save() );
		if ( $saved_id !== $product_id ) {
			return new WP_Error( 'mobo_core_simple_variant_persist_failed', 'WooCommerce did not persist the simple Mobo variant state.' );
		}

		$fresh = wc_get_product( $product_id );
		if ( ! $fresh instanceof WC_Product_Simple
			|| '1' !== (string) $fresh->get_meta( '_mobo_simple_variant_mapped', true )
			|| absint( $fresh->get_meta( '_mobo_portal_variant_id', true ) ) !== $portal_variant_id
			|| ! $this->simple_product_matches_variant_payload( $fresh, $variant_data )
		) {
			return new WP_Error( 'mobo_core_simple_variant_postcondition_failed', 'Simple Mobo variant save failed its post-write verification.' );
		}

		wc_delete_product_transients( $product_id );

		return array(
			'productId'       => $product_id,
			'portalVariantId' => $portal_variant_id,
			'variantGuid'     => $variant_guid,
		);
	}

	/**
	 * Commit an authoritative Simple topology with no purchasable source Variant.
	 * Old parent-level Variant identity is removed and checkout is made impossible,
	 * while the topology itself can still converge as a complete out-of-stock state.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_guid Product GUID.
	 * @return true|WP_Error
	 */
	private function apply_authoritative_simple_without_purchase_variant( $product_id, $product_guid ) {
		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_authoritative_simple_empty_invalid', 'Authoritative empty Simple state has an invalid product ID.' );
		}
		$conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $conversion ) ) {
			return $conversion;
		}
		$cleanup = $this->clear_simple_variant_mapping_from_parent( $product_id );
		if ( is_wp_error( $cleanup ) ) {
			return $cleanup;
		}
		$product = new WC_Product_Simple( $product_id );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );
		$product->update_meta_data( '_mobo_simple_variant_mapped', '0' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_status', 'authoritative_missing' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_message', 'Authoritative Source topology contains no purchasable Variant.' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_at', gmdate( 'c' ) );
		if ( '' !== $product_guid ) {
			$product->update_meta_data( 'product_guid', $product_guid );
		}
		$saved = absint( $product->save() );
		$fresh = $saved === $product_id ? wc_get_product( $product_id ) : false;
		if ( $saved !== $product_id || ! $fresh instanceof WC_Product_Simple || 'outofstock' !== (string) $fresh->get_stock_status() || absint( $fresh->get_meta( '_mobo_portal_variant_id', true ) ) > 0 || '' !== trim( (string) $fresh->get_meta( 'variant_guid', true ) ) ) {
			return new WP_Error( 'mobo_core_authoritative_simple_empty_postcondition', 'Authoritative Simple zero-Variant state failed post-write verification.' );
		}
		wc_delete_product_transients( $product_id );
		return true;
	}

	private function mark_simple_product_variant_unresolved( $product_id, $reason, $message ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
		if ( '' !== $product_guid ) {
			$this->upsert_product_map( $product_guid, $product_id, true );
		}

		$simple_conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $simple_conversion ) ) {
			$current = wc_get_product( $product_id );
			if ( $current instanceof WC_Product ) {
				$current->set_stock_status( 'outofstock' );
				$current->update_meta_data( '_mobo_simple_variant_mapped', '0' );
				$current->update_meta_data( '_mobo_simple_variant_resolution_status', 'conversion_failed' );
				$current->update_meta_data( '_mobo_simple_variant_resolution_message', sanitize_text_field( (string) $message ) );
				$current->update_meta_data( '_mobo_simple_variant_resolution_at', gmdate( 'c' ) );
				$current->update_meta_data( 'mobo_sync_incomplete', '1' );
				$current->save();
			}
			return;
		}
		$product = new WC_Product_Simple( $product_id );

		foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ) as $meta_key ) {
			$product->delete_meta_data( $meta_key );
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );
		$product->update_meta_data( '_mobo_simple_variant_mapped', '0' );
		$product->update_meta_data( '_mobo_simple_variant_resolution_status', sanitize_key( (string) $reason ) );
		$product->update_meta_data( '_mobo_simple_variant_resolution_message', sanitize_text_field( (string) $message ) );
		$product->update_meta_data( '_mobo_simple_variant_resolution_at', gmdate( 'c' ) );
		$product->update_meta_data( 'mobo_sync_incomplete', '1' );
		$product->save();

		/* Stale parent-level Variant mappings are not purchase authority, but leaving
		 * them behind can misroute future identity lookup. Remove only variation rows
		 * pointing at this exact parent; failure stays explicitly incomplete/retryable. */
		if ( $this->product_map instanceof Mobo_Core_Product_Map && ! $this->product_map->delete_variation_by_post_id_verified( $product_id ) ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			update_post_meta( $product_id, '_mobo_simple_variant_resolution_status', 'map_cleanup_failed' );
		}

		wc_delete_product_transients( $product_id );
	}

	private function simple_product_matches_variant_payload( $product, $data ) {
		if ( ! $product instanceof WC_Product_Simple ) {
			return false;
		}
		if ( $this->rules->should_update_title() ) {
			$title = sanitize_text_field( (string) $this->get_value( $data, 'title', '' ) );
			if ( '' !== $title && (string) $product->get_name() !== $title ) {
				return false;
			}
		}
		if ( $this->rules->should_update_stock() ) {
			$present = false;
			$stock   = $this->get_stock_value_from_payload( $data, $present );
			if ( $present && ! $this->product_stock_matches_value( $product, $stock ) ) {
				return false;
			}
		}
		if ( ( $this->rules->should_update_price() || $this->rules->should_update_compare_price() ) && ( $this->product_price_field_present( $data ) || $this->product_compare_price_field_present( $data ) ) ) {
			$product_id  = absint( $product->get_id() );
			$raw_price   = $this->product_price_field_present( $data ) ? $this->get_value( $data, 'price', null ) : get_post_meta( $product_id, 'mobo_api_price', true );
			$raw_compare = $this->product_compare_price_field_present( $data ) ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $product_id, 'mobo_api_compare_price', true );
			if ( $this->product_price_field_present( $data ) || ( null !== $raw_price && '' !== $raw_price ) ) {
				$pair = $this->price_calculator->calculate_price_pair(
					$product_id,
					$raw_price,
					$raw_compare,
					'product'
				);
				if ( ! empty( $pair['error'] ) ) {
					return false;
				}
				if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] && wc_format_decimal( $product->get_regular_price() ) !== wc_format_decimal( $pair['regular_price'] ) ) {
					return false;
				}
				if ( isset( $pair['sale_price'] ) && wc_format_decimal( $product->get_sale_price() ) !== wc_format_decimal( $pair['sale_price'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function store_simple_variant_identity_meta_fast( $product_id, $variant_data, $product_guid = '' ) {
		$portal_variant_id = $this->extract_portal_variant_id( $variant_data );
		$variant_guid      = $this->extract_simple_variant_guid( $variant_data );
		if ( $portal_variant_id > 0 ) {
			foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $key ) {
				$this->update_post_meta_if_changed( $product_id, $key, $portal_variant_id );
			}
		}
		if ( '' !== $variant_guid ) {
			foreach ( array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ) as $key ) {
				$this->update_post_meta_if_changed( $product_id, $key, $variant_guid );
			}
		}
		if ( '' !== $product_guid ) {
			$this->update_post_meta_if_changed( $product_id, 'product_guid', sanitize_text_field( (string) $product_guid ) );
		}
	}

	private function extract_embedded_simple_variant_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		foreach ( array( 'variant', 'defaultVariant', 'default_variant', 'singleVariant', 'single_variant', 'productVariant', 'product_variant' ) as $key ) {
			$candidate = $this->get_value( $data, $key, null );
			if ( is_array( $candidate ) && $this->looks_like_variant_payload( $candidate ) ) {
				return $candidate;
			}
		}

		foreach ( array( 'variants', 'variantItems', 'variant_items' ) as $key ) {
			$candidates = $this->get_value( $data, $key, null );
			if ( is_array( $candidates ) && $this->is_list_array( $candidates ) && 1 === count( $candidates ) && is_array( $candidates[0] ) ) {
				return $candidates[0];
			}
		}

		return $this->looks_like_variant_payload( $data ) ? $data : array();
	}

	private function looks_like_variant_payload( $data ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return false;
		}

		foreach ( array( 'portal_variant_id', 'portalVariantId', 'PortalVariantId', 'variant_guid', 'variantGuid', 'variantId', 'variant_id' ) as $key ) {
			if ( null !== $this->get_value( $data, $key, null ) ) {
				return true;
			}
		}

		/* Storefront cart/API variant objects have id plus variant-specific fields. */
		$id = $this->extract_positive_int_from_payload( $data, array( 'id' ) );
		$variant_detail_present = Mobo_Core_Payload_Field_Policy::is_present(
			$data,
			array( 'status', 'Status', 'min', 'Min', 'max', 'Max', 'price', 'Price', 'type', 'Type', 'attributes', 'Attributes' )
		);
		return $id > 0 && $variant_detail_present;
	}

	private function extract_simple_variant_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( array( 'variant_guid', 'variantGuid', 'variantId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entity_id', 'entityId' ) as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function variant_has_selection_attributes( $variant_data ) {
		if ( ! is_array( $variant_data ) ) {
			return false;
		}

		return ! empty( $this->normalize_variation_attributes( $this->get_value( $variant_data, 'attributes', array() ) ) );
	}

	private function get_stored_portal_variant_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return 0;
		}

		foreach ( array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' ) as $meta_key ) {
			$value = absint( get_post_meta( $product_id, $meta_key, true ) );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return 0;
	}

	/**
	 * Validate every child that an authoritative Simple transition may retire
	 * before parent attributes/type are mutated. Manual/non-Mobo children are
	 * intentionally preserved; conflicting Mobo aliases or unavailable map state
	 * block the transition fail-closed.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	private function preflight_simple_variation_cleanup( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ) {
			return new WP_Error( 'mobo_core_simple_preflight_unavailable', 'Variation lifecycle preflight is unavailable for Simple topology conversion.' );
		}

		$children = array_values( array_unique( array_merge( $this->get_live_variation_children( $product_id ), $this->get_pending_quarantine_children( $product_id ) ) ) );
		foreach ( $children as $variation_id ) {
			$preflight = Mobo_Core_Variation_Lifecycle_Policy::preflight_quarantine( $variation_id, $this->product_map );
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
			/* Non-Mobo children are outside Mobo topology ownership and remain live. */
			if ( empty( $preflight['owned'] ) ) {
				continue;
			}
		}
		return true;
	}

	/**
	 * Verify that an authoritative Simple transition preserved the historical
	 * quarantine object and did not mutate merchant/manual variations.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $owned_children Mobo-owned child IDs expected in Trash.
	 * @param array $manual_children Snapshot of non-Mobo children.
	 * @return true|WP_Error
	 */
	private function verify_simple_variation_preservation( $product_id, $owned_children, $manual_children ) {
		$product_id = absint( $product_id );

		foreach ( array_values( array_unique( array_map( 'absint', is_array( $owned_children ) ? $owned_children : array() ) ) ) as $variation_id ) {
			if ( $variation_id <= 0 ) {
				continue;
			}
			$post = get_post( $variation_id );
			if ( ! $post instanceof WP_Post
				|| 'product_variation' !== $post->post_type
				|| 'trash' !== $post->post_status
				|| absint( $post->post_parent ) !== $product_id
				|| '' === trim( (string) get_post_meta( $variation_id, '_mobo_variation_quarantine_reason', true ) )
			) {
				return new WP_Error(
					'mobo_core_simple_conversion_quarantine_not_preserved',
					'Historical Mobo variation quarantine was not preserved during Simple conversion.',
					array( 'productId' => $product_id, 'variationId' => $variation_id )
				);
			}
		}

		foreach ( is_array( $manual_children ) ? $manual_children : array() as $variation_id => $snapshot ) {
			$variation_id = absint( $variation_id );
			$post         = $variation_id > 0 ? get_post( $variation_id ) : null;
			if ( ! $post instanceof WP_Post
				|| 'product_variation' !== $post->post_type
				|| (string) $post->post_status !== (string) ( isset( $snapshot['status'] ) ? $snapshot['status'] : '' )
				|| absint( $post->post_parent ) !== absint( isset( $snapshot['parentId'] ) ? $snapshot['parentId'] : 0 )
			) {
				return new WP_Error(
					'mobo_core_simple_conversion_manual_variation_changed',
					'Manual/non-Mobo variation changed during authoritative Simple conversion; conversion safety verification failed.',
					array( 'productId' => $product_id, 'variationId' => $variation_id )
				);
			}
		}

		return true;
	}

	private function force_product_simple_if_needed( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_simple_product_invalid', 'Product ID is invalid for simple-product conversion.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_simple_product_missing', 'Product could not be loaded for simple-product conversion.' );
		}

		/*
		 * Do not trust the current parent type as proof that children are already gone.
		 * Older builds could persist product_type=simple and leave live historical Mobo
		 * variations behind. Query by post_parent so retries self-heal that state too.
		 */
		$children        = array_values( array_unique( array_merge( $this->get_live_variation_children( $product_id ), $this->get_pending_quarantine_children( $product_id ) ) ) );
		$failed          = array();
		$owned_children  = array();
		$manual_children = array();

		foreach ( $children as $variation_id ) {
			$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $variation_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
			if ( is_wp_error( $identity ) ) {
				$failed[ $variation_id ] = $identity->get_error_message();
				continue;
			}
			if ( empty( $identity['owned'] ) ) {
				/* Manual/non-Mobo variations are outside Mobo topology ownership. Snapshot
				 * their exact post state because WooCommerce normally force-deletes every
				 * variation when a Variable product is saved as non-Variable. */
				$post = get_post( $variation_id );
				if ( $post instanceof WP_Post ) {
					$manual_children[ absint( $variation_id ) ] = array(
						'status'   => (string) $post->post_status,
						'parentId' => absint( $post->post_parent ),
					);
				}
				continue;
			}
			$owned_children[] = absint( $variation_id );
			$retired = $this->quarantine_variation( $variation_id, 'authoritative-variable-to-simple', array( 'parentId' => $product_id ) );
			if ( is_wp_error( $retired ) ) {
				$failed[ $variation_id ] = $retired->get_error_message();
			}
		}

		if ( ! empty( $failed ) ) {
			update_post_meta( $product_id, '_mobo_simple_conversion_failed_variations', wp_json_encode( $failed ) );
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return new WP_Error( 'mobo_core_simple_conversion_quarantine_failed', 'One or more Mobo variations could not be quarantined; product type was preserved for a safe retry.', array( 'variations' => $failed ) );
		}

		if ( $product instanceof WC_Product_Simple && ! $product instanceof WC_Product_Variable ) {
			$preserved = $this->verify_simple_variation_preservation( $product_id, $owned_children, $manual_children );
			if ( is_wp_error( $preserved ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return $preserved;
			}
			delete_post_meta( $product_id, '_mobo_simple_conversion_failed_variations' );
			return true;
		}

		$type_write = wp_set_object_terms( $product_id, 'simple', 'product_type', false );
		if ( is_wp_error( $type_write ) ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return new WP_Error( 'mobo_core_simple_product_type_write_failed', 'WooCommerce product_type could not be changed to simple: ' . $type_write->get_error_message() );
		}

		/*
		 * WooCommerce's woocommerce_product_type_changed handler normally calls
		 * delete_variations( $product_id, true ) when Variable becomes non-Variable.
		 * That force-deletes BOTH quarantined Mobo children and merchant/manual
		 * variations. Use WooCommerce's own deletion-control filter, scoped to this
		 * exact parent and exact Variable->Simple transition, then remove it in a
		 * finally block so no unrelated save in this request is affected.
		 */
		$preserve_variations = static function( $delete_variations, $candidate, $from, $to ) use ( $product_id ) {
			if ( $candidate instanceof WC_Product
				&& absint( $candidate->get_id() ) === $product_id
				&& 'variable' === (string) $from
				&& 'simple' === (string) $to
			) {
				return false;
			}
			return $delete_variations;
		};

		add_filter( 'woocommerce_delete_variations_on_product_type_change', $preserve_variations, PHP_INT_MAX, 4 );
		$saved_id = 0;
		try {
			$simple   = new WC_Product_Simple( $product_id );
			$saved_id = absint( $simple->save() );
		} catch ( Throwable $e ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return new WP_Error( 'mobo_core_simple_product_save_exception', 'WooCommerce simple product conversion threw an exception: ' . $e->getMessage() );
		} finally {
			remove_filter( 'woocommerce_delete_variations_on_product_type_change', $preserve_variations, PHP_INT_MAX );
		}

		$fresh = $saved_id === $product_id ? wc_get_product( $product_id ) : false;
		if ( $saved_id !== $product_id || ! $fresh instanceof WC_Product_Simple || $fresh instanceof WC_Product_Variable ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return new WP_Error( 'mobo_core_simple_product_save_failed', 'WooCommerce simple product conversion did not persist successfully.' );
		}

		$preserved = $this->verify_simple_variation_preservation( $product_id, $owned_children, $manual_children );
		if ( is_wp_error( $preserved ) ) {
			update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
			return $preserved;
		}

		delete_post_meta( $product_id, '_mobo_simple_conversion_failed_variations' );
		wc_delete_product_transients( $product_id );
		return true;
	}

	private function clear_simple_variant_mapping_from_parent( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return new WP_Error( 'mobo_core_simple_variant_cleanup_invalid', 'Product ID is invalid for simple-variant identity cleanup.' );
		}

		/*
		 * Checkout/purchase identity is the safety boundary. Clear and verify those
		 * aliases before touching Product Map so a database cleanup failure can leave
		 * an incomplete mapping to retry, but can never leave a stale purchasable
		 * Variant identity on a now-Simple parent.
		 */
		$identity_keys = array(
			'portal_variant_id',
			'mobo_portal_variant_id',
			'_mobo_portal_variant_id',
			'variant_guid',
			'mobo_variant_guid',
			'_mobo_variant_guid',
			'_mobo_simple_variant_mapped',
			'_mobo_simple_variant_mapped_at',
			'_mobo_simple_variant_resolution_status',
			'_mobo_simple_variant_resolution_message',
			'_mobo_simple_variant_resolution_at',
			'_mobo_simple_variant_source_hash',
		);
		foreach ( $identity_keys as $meta_key ) {
			delete_post_meta( $product_id, $meta_key );
		}
		foreach ( $identity_keys as $meta_key ) {
			if ( '' !== (string) get_post_meta( $product_id, $meta_key, true ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return new WP_Error( 'mobo_core_simple_variant_meta_cleanup_failed', 'Legacy simple-variant purchase identity metadata did not clear durably after product type conversion.' );
			}
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			/* Delete only variation rows that point at this parent post and verify the
			 * read-back. A stale remote GUID alias must not delete a row owned by another
			 * local object. */
			if ( ! $this->product_map->delete_variation_by_post_id_verified( $product_id ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return new WP_Error( 'mobo_core_simple_variant_map_post_cleanup_failed', 'Could not remove and verify legacy variation mappings that still point at the parent product.' );
			}
		}

		return true;
	}

	private function ensure_product_type_for_variants( $product_id, $variant_total_count ) {
		$product_id          = absint( $product_id );
		$variant_total_count = absint( $variant_total_count );

		if ( $product_id <= 0 ) {
			return null;
		}

		$current = wc_get_product( $product_id );

		if ( ! $current instanceof WC_Product ) {
			return null;
		}

		if ( $variant_total_count > 0 ) {
			if ( $current instanceof WC_Product_Variable ) {
				/* Older builds could persist the variable product_type while failing to
				 * remove a parent-level simple-variant identity/map. Re-run the cleanup
				 * when any such identity remains so retries are self-healing. */
				$has_parent_variant_identity = '1' === (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true )
					|| '' !== trim( (string) get_post_meta( $product_id, 'variant_guid', true ) )
					|| absint( get_post_meta( $product_id, 'portal_variant_id', true ) ) > 0;
				if ( $has_parent_variant_identity ) {
					$cleanup = $this->clear_simple_variant_mapping_from_parent( $product_id );
					if ( is_wp_error( $cleanup ) ) {
						return $cleanup;
					}
				}
				return $current;
			}

			$had_simple_variant_mapping = '1' === (string) get_post_meta( $product_id, '_mobo_simple_variant_mapped', true );
			$type_write = wp_set_object_terms( $product_id, 'variable', 'product_type', false );
			if ( is_wp_error( $type_write ) ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return new WP_Error( 'mobo_core_variable_product_type_write_failed', 'WooCommerce product_type could not be changed to variable: ' . $type_write->get_error_message() );
			}

			$variable    = new WC_Product_Variable( $product_id );
			$variable_id = absint( $variable->save() );
			$fresh       = $variable_id === $product_id ? wc_get_product( $product_id ) : false;
			if ( $variable_id !== $product_id || ! $fresh instanceof WC_Product_Variable ) {
				update_post_meta( $product_id, 'mobo_sync_incomplete', '1' );
				return new WP_Error( 'mobo_core_variable_product_save_failed', 'WooCommerce variable product could not be durably saved after type conversion.' );
			}

			/* The parent-level simple-variant identity is recovery evidence until the
			 * product_type mutation itself is durable. Removing it before the taxonomy
			 * write succeeds can orphan a simple product after a transient DB failure. */
			if ( $had_simple_variant_mapping ) {
				$cleanup = $this->clear_simple_variant_mapping_from_parent( $product_id );
				if ( is_wp_error( $cleanup ) ) {
					return $cleanup;
				}
			}

			return $variable;
		}

		$simple_conversion = $this->force_product_simple_if_needed( $product_id );
		if ( is_wp_error( $simple_conversion ) ) {
			return $simple_conversion;
		}

		return wc_get_product( $product_id );
	}

	private function is_authoritative_variant_list_payload( $payload, $variants ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$explicit_keys = array(
			'variantListAuthoritative',
			'variant_list_authoritative',
			'authoritativeVariantList',
			'authoritative_variant_list',
			'isFullVariantSnapshot',
			'is_full_variant_snapshot',
			'fullVariantSync',
			'full_variant_sync',
		);

		foreach ( $explicit_keys as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return $this->to_bool( $payload[ $key ] );
			}
		}

		/*
		 * Webhook UpdateVariant messages are deltas, not full snapshots. A payload like
		 * { event: UpdateVariant, data: { totalCount: 1, data: [one variant] } }
		 * must never mark the other product variations as missing/out-of-stock.
		 */
		$event = strtolower( sanitize_text_field( (string) $this->get_value( $payload, 'event', '' ) ) );

		if ( 'updatevariant' === $event ) {
			return false;
		}

		$entity_type = strtolower( sanitize_text_field( (string) $this->get_value( $payload, 'entityType', $this->get_value( $payload, 'entity_type', '' ) ) ) );

		if ( 'variant' === $entity_type ) {
			return false;
		}

		/*
		 * Safety rule: UpdateVariant payloads are treated as delta/webhook updates unless
		 * they explicitly opt in via variantListAuthoritative/isFullVariantSnapshot.
		 * This prevents four single-variant webhooks from marking each other's variants
		 * as missing/out-of-stock. Full manual/API syncs set the explicit flag above.
		 */
		return false;
	}

	private function has_response_key( $response, $key ) {
		if ( ! is_array( $response ) ) {
			return false;
		}
		if ( array_key_exists( $key, $response ) || array_key_exists( ucfirst( $key ), $response ) ) {
			return true;
		}
		return false;
	}

	private function extract_explicit_list_data( $response, $context ) {
		if ( ! is_array( $response ) || ! $this->has_response_key( $response, 'data' ) ) {
			return new WP_Error( 'mobo_core_api_data_missing', sanitize_text_field( (string) $context ) . ' response is missing explicit data.' );
		}
		$data = $this->get_value( $response, 'data', null );
		if ( ! is_array( $data ) || ! $this->is_list_array( $data ) ) {
			return new WP_Error( 'mobo_core_api_data_invalid', sanitize_text_field( (string) $context ) . ' response data must be a list.' );
		}
		return $data;
	}

	private function validated_variant_page_items( $response ) {
		if ( ! is_array( $response ) || ! $this->has_response_key( $response, 'data' ) ) {
			return new WP_Error( 'mobo_core_variant_data_missing', 'Variant response is missing explicit data.' );
		}
		$raw = $this->get_value( $response, 'data', null );
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'mobo_core_variant_data_invalid', 'Variant response data is not an array.' );
		}
		$items = $this->normalize_variant_items_from_response( $response );
		if ( empty( $items ) && ! empty( $raw ) ) {
			return new WP_Error( 'mobo_core_variant_data_shape_invalid', 'Variant response data has an unsupported shape.' );
		}
		return $items;
	}

	private function validated_page_has_more( $response, $page_number ) {
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'mobo_core_pagination_invalid', 'Pagination response is invalid.' );
		}
		if ( $this->has_response_key( $response, 'hasMore' ) ) {
			return $this->to_bool( $this->get_value( $response, 'hasMore', false ) );
		}
		if ( $this->has_response_key( $response, 'isLastPage' ) ) {
			return ! $this->to_bool( $this->get_value( $response, 'isLastPage', false ) );
		}
		$total_pages = absint( $this->get_value( $response, 'totalPages', 0 ) );
		if ( $total_pages > 0 ) {
			$current_page = max( 1, absint( $this->get_value( $response, 'pageNumber', $page_number ) ) );
			return $current_page < $total_pages;
		}
		return new WP_Error( 'mobo_core_pagination_signal_missing', 'Response is missing hasMore/isLastPage/totalPages.' );
	}

	private function normalize_categories_api_response( $response ) {
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'mobo_core_categories_shape_invalid', 'Categories response is not an array.' );
		}
		if ( $this->is_list_array( $response ) ) {
			return $response;
		}
		if ( ! $this->has_response_key( $response, 'data' ) ) {
			return new WP_Error( 'mobo_core_categories_data_missing', 'Categories envelope is missing explicit data.' );
		}
		$data = $this->get_value( $response, 'data', null );
		if ( ! is_array( $data ) || ! $this->is_list_array( $data ) ) {
			return new WP_Error( 'mobo_core_categories_data_invalid', 'Categories data must be a list.' );
		}
		return $data;
	}

	private function normalize_variant_items_from_response( $response ) {
		$items = $this->get_value( $response, 'data', array() );

		if ( is_array( $items ) && ! $this->is_list_array( $items ) ) {
			$nested_items = $this->get_value( $items, 'data', null );

			if ( is_array( $nested_items ) ) {
				$items = $nested_items;
			} elseif ( $this->looks_like_variant_payload( $items ) ) {
				/* A single variant may be returned as data:{...}, not data:[{...}]. */
				$items = array( $items );
			}
		}

		return is_array( $items ) && $this->is_list_array( $items ) ? $items : array();
	}

	private function get_stock_value_from_payload( $data, &$present = false ) {
		$inspection = Mobo_Core_Payload_Field_Policy::inspect( $data, Mobo_Core_Payload_Field_Policy::stock_aliases() );
		$present    = ! empty( $inspection['present'] );

		return $present ? $inspection['value'] : null;
	}

	private function variation_stock_matches_payload( $variation_id, $data ) {
		if ( ! $this->rules->should_update_stock() ) {
			return true;
		}

		$stock_present = false;
		$stock_value   = $this->get_stock_value_from_payload( $data, $stock_present );

		if ( ! $stock_present ) {
			return true;
		}

		$variation = wc_get_product( absint( $variation_id ) );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return false;
		}

		return $this->product_stock_matches_value( $variation, $stock_value );
	}

	private function is_valid_api_stock_payload_value( $stock ) {
		if ( null === $stock || '' === $stock ) {
			return true;
		}

		return null !== $this->normalize_api_stock_quantity( $stock );
	}

	private function normalize_api_stock_quantity( $stock ) {
		/* Portal's stock contract is nullable integer. A negative source balance means
		 * there is no sellable stock and converges to WooCommerce quantity zero instead
		 * of poisoning the entire Product/Variation event. Never coerce fractional,
		 * scientific-notation, boolean, or partially numeric values. JSON integers
		 * decode as int; integer strings are accepted for older serializers. */
		if ( is_int( $stock ) ) {
			return max( 0, $stock );
		}

		if ( ! is_string( $stock ) ) {
			return null;
		}

		$raw_stock = trim( $stock );
		if ( '' === $raw_stock || ! preg_match( '/^-?[0-9]+$/D', $raw_stock ) ) {
			return null;
		}

		if ( '-' === substr( $raw_stock, 0, 1 ) ) {
			return 0;
		}

		$digits = ltrim( $raw_stock, '0' );
		if ( '' === $digits ) {
			return 0;
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) {
			return null;
		}

		return (int) $digits;
	}

	private function apply_api_stock( $product, $stock ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( null === $stock || '' === $stock ) {
			$changed = false;
			if ( (bool) $product->get_manage_stock( 'edit' ) ) {
				$product->set_manage_stock( false );
				$changed = true;
			}
			if ( null !== $product->get_stock_quantity( 'edit' ) ) {
				$product->set_stock_quantity( null );
				$changed = true;
			}
			if ( 'instock' !== (string) $product->get_stock_status( 'edit' ) ) {
				$product->set_stock_status( 'instock' );
				$changed = true;
			}
			$changed = $this->delete_product_meta_if_present( $product, '_mobo_stock_payload_missing' ) || $changed;
			$changed = $this->delete_product_meta_if_present( $product, '_mobo_last_api_stock_quantity' ) || $changed;
			$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_raw', '' ) || $changed;
			$changed = $this->update_product_meta_if_changed( $product, '_mobo_stock_update_source', 'api-empty-stock' ) || $changed;
			if ( $changed ) {
				$this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_applied_at', gmdate( 'c' ) );
			}
			return true;
		}

		$raw_stock       = is_scalar( $stock ) ? trim( (string) $stock ) : '';
		$stock_quantity  = $this->normalize_api_stock_quantity( $stock );
		$sanitized_stock = sanitize_text_field( $raw_stock );

		if ( null === $stock_quantity ) {
			$changed = false;
			$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_raw', $sanitized_stock ) || $changed;
			$changed = $this->update_product_meta_if_changed( $product, '_mobo_stock_update_source', 'api-invalid-stock-skipped' ) || $changed;
			if ( $changed ) {
				$this->update_product_meta_if_changed( $product, '_mobo_stock_update_skipped_at', gmdate( 'c' ) );
			}
			return false;
		}

		$expected_status = $stock_quantity > 0 ? 'instock' : 'outofstock';
		$changed         = false;

		if ( ! (bool) $product->get_manage_stock( 'edit' ) ) {
			$product->set_manage_stock( true );
			$changed = true;
		}
		if ( null === $product->get_stock_quantity( 'edit' ) || (int) $product->get_stock_quantity( 'edit' ) !== $stock_quantity ) {
			$product->set_stock_quantity( $stock_quantity );
			$changed = true;
		}
		if ( (string) $product->get_stock_status( 'edit' ) !== $expected_status ) {
			$product->set_stock_status( $expected_status );
			$changed = true;
		}

		$changed = $this->delete_product_meta_if_present( $product, '_mobo_stock_payload_missing' ) || $changed;
		$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_raw', $sanitized_stock ) || $changed;
		$changed = $this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_quantity', $stock_quantity ) || $changed;
		$changed = $this->update_product_meta_if_changed( $product, '_mobo_stock_update_source', 'api-stock' ) || $changed;

		if ( $changed ) {
			$this->update_product_meta_if_changed( $product, '_mobo_last_api_stock_applied_at', gmdate( 'c' ) );
		}

		return true;
	}

	private function apply_product_slug( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		/*
		 * Current behavior: use url as slug source.
		 * If API later sends explicit slug, prefer slug.
		 */
		$slug = sanitize_title( (string) $this->get_value( $data, 'slug', '' ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( trim( (string) $this->get_value( $data, 'url', '' ), '/' ) );
		}

		if ( '' === $slug || (string) $product->get_slug( 'edit' ) === $slug ) {
			return;
		}

		$product->set_slug( $slug );
	}

	private function apply_product_dates( $product, $data ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$published_at = sanitize_text_field( (string) $this->get_value( $data, 'publishedAt', '' ) );

		if ( '' === $published_at ) {
			return;
		}

		$timestamp = strtotime( $published_at );

		if ( false === $timestamp || $timestamp <= 0 ) {
			$this->update_product_meta_if_changed( $product, 'published_at', $published_at );
			return;
		}

		$gmt_date   = gmdate( 'Y-m-d H:i:s', $timestamp );
		$local_date = get_date_from_gmt( $gmt_date, 'Y-m-d H:i:s' );
		$current_created = $product->get_date_created( 'edit' );

		if ( ! $current_created instanceof WC_DateTime || (int) $current_created->getTimestamp() !== (int) $timestamp ) {
			$date = new WC_DateTime( '@' . $timestamp );
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			$product->set_date_created( $date );
		}


		$this->update_product_meta_if_changed( $product, 'published_at', $published_at );
		$this->update_product_meta_if_changed( $product, 'mobo_published_at_gmt', $gmt_date );
		$this->update_product_meta_if_changed( $product, 'mobo_published_at_local', $local_date );
	}

	private function apply_price_to_product( $product, $data, $context, $is_new_object = false ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! $is_new_object && ! $this->rules->should_update_price() && ! $this->rules->should_update_compare_price() ) {
			return;
		}

		$price_present   = $this->product_price_field_present( $data );
		$compare_present = $this->product_compare_price_field_present( $data );
		if ( ! $is_new_object && ! $price_present && ! $compare_present ) {
			return;
		}

		$object_id         = absint( $product->get_id() );
		$raw_price         = $price_present || $is_new_object ? $this->get_value( $data, 'price', null ) : get_post_meta( $object_id, 'mobo_api_price', true );
		$raw_compare_price = $compare_present || $is_new_object ? $this->get_compare_price_field_value( $data, null ) : get_post_meta( $object_id, 'mobo_api_compare_price', true );
		$source_changed    = false;

		if ( ! $is_new_object && ! $price_present && ( null === $raw_price || '' === $raw_price ) ) {
			if ( $compare_present ) {
				$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_api_compare_price', null === $raw_compare_price || '' === $raw_compare_price ? '' : wc_format_decimal( $raw_compare_price ) ) || $source_changed;
			}
			if ( $source_changed ) {
				$this->update_product_meta_if_changed( $product, 'mobo_price_policy_updated_at', gmdate( 'c' ) );
			}
			return;
		}

		$parent_id = 'variation' === sanitize_key( (string) $context ) ? absint( $product->get_parent_id( 'edit' ) ) : 0;
		$pair = $this->price_calculator->calculate_price_pair(
			$object_id,
			$raw_price,
			$raw_compare_price,
			$context,
			$parent_id
		);

		if ( ! empty( $pair['error'] ) ) {
			throw new RuntimeException( 'Invalid Mobo price payload: ' . sanitize_text_field( (string) $pair['error'] ) );
		}

		if ( $price_present || $is_new_object ) {
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_api_price', null === $raw_price || '' === $raw_price ? '' : wc_format_decimal( $raw_price ) ) || $source_changed;
		}
		if ( $compare_present || $is_new_object ) {
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_api_compare_price', null === $raw_compare_price || '' === $raw_compare_price ? '' : wc_format_decimal( $raw_compare_price ) ) || $source_changed;
		}
		$effective_policy_type = isset( $pair['policy_type'] ) && '' !== (string) $pair['policy_type'] ? sanitize_key( (string) $pair['policy_type'] ) : (string) Mobo_Core_Settings::get( 'mobo_price_type', 'static-price' );
		$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_price_policy_type', $effective_policy_type ) || $source_changed;

		if ( null !== $pair['regular_price'] && '' !== $pair['regular_price'] ) {
			$desired_regular = wc_format_decimal( $pair['regular_price'] );
			if ( wc_format_decimal( $product->get_regular_price( 'edit' ) ) !== $desired_regular ) {
				$product->set_regular_price( $pair['regular_price'] );
			}
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_calculated_regular_price', $pair['regular_price'] ) || $source_changed;
		}

		if ( isset( $pair['sale_price'] ) ) {
			$desired_sale = wc_format_decimal( $pair['sale_price'] );
			if ( wc_format_decimal( $product->get_sale_price( 'edit' ) ) !== $desired_sale ) {
				$product->set_sale_price( $pair['sale_price'] );
			}
			$source_changed = $this->update_product_meta_if_changed( $product, 'mobo_calculated_sale_price', $pair['sale_price'] ) || $source_changed;
		}

		if ( $source_changed ) {
			$this->update_product_meta_if_changed( $product, 'mobo_price_policy_updated_at', gmdate( 'c' ) );
		}
	}


	/**
	 * Treat temporary API/HTTP failures as retryable manual-sync errors.
	 *
	 * A single timeout must not poison the sync state with lastError, because
	 * get_manual_sync_status() stops the self-runner when lastError is not empty.
	 *
	 * @param array    $state   Current manual sync state.
	 * @param WP_Error $error   Request error.
	 * @param string   $message Human message.
	 * @return array
	 */
	private function handle_transient_request_error( &$state, $error, $message ) {
		$try_count = absint( $state['transientRetryCount'] ?? 0 ) + 1;
		$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_transient_retry_max_try', 10, 1, 50 );
		$error_msg = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

		$state['transientRetryCount'] = $try_count;
		$state['lastTransientError']  = sanitize_text_field( $error_msg );
		$state['updatedAt']           = time();

		if ( $try_count >= $max_try ) {
			$delay_seconds = Mobo_Core_Settings::get_int( 'mobo_core_waiting_for_portal_retry_delay_seconds', 60, 10, 3600 );

			$state['status']                = 'waiting_for_portal';
			$state['waitingForPortalSince'] = empty( $state['waitingForPortalSince'] ) ? time() : absint( $state['waitingForPortalSince'] );
			$state['nextRetryAt']           = time() + $delay_seconds;
			$state['lastError']             = '';
			$state['lastMessage']           = sprintf( '%s اتصال به MoboCore پس از %d تلاش برقرار نشد. sync متوقف نشده؛ از همین نقطه در تلاش بعدی ادامه می‌دهد.', $message, $max_try );
			if ( ! $this->save_manual_sync_state( $state ) ) {
				return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
			}

			return $this->result( true, $state['lastMessage'] . ' ' . $error_msg, $this->get_manual_sync_status() );
		}

		/* Keep lastError empty so the self-runner keeps the sync resumable. */
		$state['status']      = 'running';
		$state['nextRetryAt'] = 0;
		$state['lastError']   = '';
		$state['lastMessage'] = sprintf( '%s خطای موقت؛ تلاش مجدد %d از %d. %s', $message, $try_count, $max_try, $error_msg );
		if ( ! $this->save_manual_sync_state( $state ) ) {
			return $this->result( false, 'Checkpoint همگام‌سازی به‌صورت پایدار ذخیره نشد؛ عملیات در اجرای بعد از آخرین state تأییدشده ادامه می‌یابد.', $this->get_manual_sync_status() );
		}

		return $this->result( true, $state['lastMessage'], $this->get_manual_sync_status() );
	}

	/**
	 * Clear transient request retry state after a successful API response.
	 *
	 * @param array $state Current state.
	 * @return void
	 */
	private function clear_transient_request_error( &$state ) {
		$state['transientRetryCount']   = 0;
		$state['lastTransientError']    = '';
		$state['lastError']             = '';
		$state['waitingForPortalSince'] = 0;
		$state['nextRetryAt']           = 0;
		if ( 'waiting_for_portal' === sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$state['status'] = 'running';
		}
	}

	/**
	 * Validate explicitly present source money fields before mutation. Absent values
	 * preserve existing state; null/empty are explicit nullable state; malformed
	 * numeric input fails closed instead of being wc_format_decimal()-collapsed.
	 *
	 * @param array  $data Payload.
	 * @param string $context Diagnostic context.
	 * @return true|WP_Error
	 */
	private function validate_money_payload_fields( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mobo_core_money_payload_invalid', 'Mobo money payload is invalid.' );
		}

		if ( $this->product_price_field_present( $data ) ) {
			$price = Mobo_Core_Payload_Field_Policy::value( $data, Mobo_Core_Payload_Field_Policy::price_aliases(), null );
			if ( ! Mobo_Core_Money_Policy::is_valid_source_amount( $price ) ) {
				return new WP_Error( 'mobo_core_price_payload_invalid', 'Mobo ' . sanitize_key( (string) $context ) . ' price is present but malformed.' );
			}
		}

		if ( $this->product_compare_price_field_present( $data ) ) {
			$compare = Mobo_Core_Payload_Field_Policy::value( $data, Mobo_Core_Payload_Field_Policy::compare_price_aliases(), null );
			if ( ! Mobo_Core_Money_Policy::is_valid_source_amount( $compare ) ) {
				return new WP_Error( 'mobo_core_compare_price_payload_invalid', 'Mobo ' . sanitize_key( (string) $context ) . ' comparePrice is present but malformed.' );
			}
		}

		return true;
	}

	/**
	 * Validate present authoritative Product collection fields before any WooCommerce
	 * mutation. Explicit empty arrays are valid desired state; malformed/non-array or
	 * partially invalid collections are not silently normalized into destructive
	 * subsets.
	 *
	 * @param array $data Product payload.
	 * @param bool  $is_new_product Whether this is a new local product.
	 * @return true|WP_Error
	 */
	private function validate_product_desired_state_payload( $data, $is_new_product = false ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mobo_core_product_payload_invalid', 'Mobo product payload must be an object/array.' );
		}

		$money_integrity = $this->validate_money_payload_fields( $data, 'product' );
		if ( is_wp_error( $money_integrity ) ) {
			return $money_integrity;
		}

		if ( $this->product_images_field_present( $data ) && ( $is_new_product || $this->rules->should_update_images() ) ) {
			$images           = Mobo_Core_Payload_Field_Policy::value( $data, Mobo_Core_Payload_Field_Policy::image_aliases(), null );
			$image_integrity  = Mobo_Core_Image_Desired_State_Policy::validate_collection( $images );
			if ( is_wp_error( $image_integrity ) ) {
				return $image_integrity;
			}
		}

		if ( $this->product_attributes_field_present( $data ) ) {
			$attributes = $this->get_value( $data, 'attributes', null );
			if ( ! is_array( $attributes ) ) {
				return new WP_Error( 'mobo_core_product_attributes_invalid', 'Mobo product attributes must be an array when the field is present.' );
			}
			$seen_names = array();
			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) ) {
					return new WP_Error( 'mobo_core_product_attribute_row_invalid', 'Mobo product contains a malformed attribute row.' );
				}
				$name = sanitize_text_field( (string) $this->get_value( $attribute, 'name', '' ) );
				$key  = sanitize_title( $name );
				$values = $this->get_value( $attribute, 'values', null );
				if ( '' === $key || ! is_array( $values ) || empty( $values ) ) {
					return new WP_Error( 'mobo_core_product_attribute_row_invalid', 'Mobo product attribute must contain a name and at least one value.' );
				}
				if ( isset( $seen_names[ $key ] ) ) {
					return new WP_Error( 'mobo_core_product_attribute_duplicate', 'Mobo product contains duplicate attribute definitions: ' . $key );
				}
				$seen_names[ $key ] = true;
				$seen_values = array();
				foreach ( $values as $value_row ) {
					if ( ! is_array( $value_row ) ) {
						return new WP_Error( 'mobo_core_product_attribute_value_invalid', 'Mobo product attribute contains a malformed value row.' );
					}
					$value = sanitize_text_field( (string) $this->get_value( $value_row, 'value', '' ) );
					if ( '' === $value ) {
						return new WP_Error( 'mobo_core_product_attribute_value_invalid', 'Mobo product attribute contains an empty value.' );
					}
					$value_key = strtolower( $value );
					if ( isset( $seen_values[ $value_key ] ) ) {
						return new WP_Error( 'mobo_core_product_attribute_value_duplicate', 'Mobo product attribute contains a duplicate value: ' . $name . '=' . $value );
					}
					$seen_values[ $value_key ] = true;
				}
			}
		}

		if ( $this->product_categories_field_present( $data ) ) {
			$categories = $this->get_raw_product_categories_field( $data );
			if ( ! is_array( $categories ) ) {
				return new WP_Error( 'mobo_core_product_categories_invalid', 'Mobo product categories must be an array when the field is present.' );
			}
			$seen_categories = array();
			foreach ( $categories as $ref ) {
				$guid = is_array( $ref ) ? $this->extract_category_guid_for_storage( $ref ) : sanitize_text_field( (string) $ref );
				if ( ! $this->is_remote_guid_value( $guid ) ) {
					return new WP_Error( 'mobo_core_product_category_row_invalid', 'Mobo product contains a category row without a stable remote identity.' );
				}
				$key = strtolower( $guid );
				if ( isset( $seen_categories[ $key ] ) ) {
					return new WP_Error( 'mobo_core_product_category_duplicate', 'Mobo product category desired state contains a duplicate remote identity.' );
				}
				$seen_categories[ $key ] = true;
			}
		}

		return true;
	}

	private function get_raw_product_categories_field( $data ) {
		return Mobo_Core_Payload_Field_Policy::value(
			$data,
			Mobo_Core_Payload_Field_Policy::category_aliases(),
			null
		);
	}

	private function get_product_images_from_payload( $data ) {
		$images = Mobo_Core_Payload_Field_Policy::value(
			$data,
			Mobo_Core_Payload_Field_Policy::image_aliases(),
			array()
		);

		return is_array( $images ) ? $images : array();
	}

	private function product_title_field_present( $data ) {
		return is_array( $data ) && ( array_key_exists( 'title', $data ) || array_key_exists( 'Title', $data ) );
	}

	private function product_slug_field_present( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( array( 'slug', 'Slug', 'url', 'Url' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return true;
			}
		}
		return false;
	}

	private function product_published_at_field_present( $data ) {
		return is_array( $data ) && ( array_key_exists( 'publishedAt', $data ) || array_key_exists( 'PublishedAt', $data ) );
	}

	private function product_price_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::price_aliases() );
	}

	private function product_compare_price_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::compare_price_aliases() );
	}

	private function get_compare_price_field_value( $data, $default = null ) {
		return Mobo_Core_Payload_Field_Policy::value(
			$data,
			Mobo_Core_Payload_Field_Policy::compare_price_aliases(),
			$default
		);
	}

	private function product_images_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::image_aliases() );
	}

	private function product_attributes_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::attribute_aliases() );
	}

	private function product_categories_field_present( $data ) {
		return Mobo_Core_Payload_Field_Policy::is_present( $data, Mobo_Core_Payload_Field_Policy::category_aliases() );
	}

	private function build_product_attributes( $attributes ) {
		$result   = array();
		$position = 0;

		if ( ! is_array( $attributes ) ) {
			return $result;
		}

		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) $this->get_value( $attribute_data, 'name', '' ) );

			if ( '' === $name ) {
				continue;
			}

			$values = $this->get_value( $attribute_data, 'values', array() );

			if ( ! is_array( $values ) ) {
				continue;
			}

			$options = array();

			foreach ( $values as $value_data ) {
				if ( ! is_array( $value_data ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $this->get_value( $value_data, 'value', '' ) );

				if ( '' !== $value ) {
					$options[] = $value;
				}
			}

			$options = array_values( array_unique( $options ) );

			if ( empty( $options ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( $options );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$result[] = $attribute;
			$position++;
		}

		return $result;
	}

	private function store_product_attribute_guids( $product_id, $attributes ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! is_array( $attributes ) ) {
			return false;
		}

		$map = array();
		foreach ( $attributes as $attribute_data ) {
			if ( ! is_array( $attribute_data ) ) {
				continue;
			}
			$guid = $this->extract_attribute_guid( $attribute_data );
			$name = sanitize_text_field( (string) $this->get_value( $attribute_data, 'name', '' ) );
			if ( '' !== $guid && '' !== $name ) {
				$map[ $name ] = $guid;
			}
		}

		if ( empty( $map ) ) {
			return $this->delete_post_meta_verified( $product_id, 'attribute_guid' ) && $this->delete_post_meta_verified( $product_id, 'mobo_attribute_guid_map' );
		}

		return $this->persist_post_meta_verified( $product_id, 'attribute_guid', $map ) && $this->persist_post_meta_verified( $product_id, 'mobo_attribute_guid_map', $map );
	}

	private function extract_attribute_guid( $attribute_data ) {
		if ( ! is_array( $attribute_data ) ) {
			return '';
		}

		$keys = array( 'attribute_guid', 'attributeGuid', 'attributeId', 'guid', 'remote_guid', 'remoteGuid', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $attribute_data, $key, '' ) );

			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Validate that a concrete Mobo variation contains exactly one non-empty value
	 * for every variation attribute defined by its parent product.
	 *
	 * @param WC_Product $parent Parent product.
	 * @param array      $attrs Normalized variation attributes.
	 * @return true|WP_Error
	 */
	/**
	 * Validate raw variation attribute rows before normalization can collapse them.
	 * Duplicate names are ambiguous even when both values are individually valid;
	 * silently keeping the last row can purchase/sync the wrong concrete variant.
	 *
	 * @param mixed $attributes Raw variant attributes.
	 * @return true|WP_Error
	 */
	private function validate_raw_variation_attribute_payload( $attributes ) {
		if ( ! is_array( $attributes ) ) {
			return new WP_Error( 'mobo_core_variant_attributes_invalid', 'Mobo variant attributes must be an array.' );
		}

		$seen = array();
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				return new WP_Error( 'mobo_core_variant_attribute_row_invalid', 'Mobo variant contains a malformed attribute row.' );
			}

			$name   = sanitize_text_field( (string) $this->get_value( $attribute, 'name', '' ) );
			$option = sanitize_text_field( (string) $this->get_value( $attribute, 'option', '' ) );
			$key    = sanitize_title( $name );

			if ( '' === $key || '' === $option ) {
				return new WP_Error( 'mobo_core_variant_attribute_row_invalid', 'Mobo variant contains an empty attribute name or selection.' );
			}
			if ( isset( $seen[ $key ] ) ) {
				return new WP_Error( 'mobo_core_variant_attribute_duplicate', 'Mobo variant contains a duplicate attribute selection: ' . $key );
			}
			$seen[ $key ] = true;
		}

		return true;
	}

	private function validate_variation_attribute_completeness( $parent, $attrs ) {
		if ( ! $parent instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_variant_parent_invalid', 'Variant parent is invalid.' );
		}

		$expected = array();
		foreach ( (array) $parent->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key = sanitize_title( (string) $attribute->get_name() );
			if ( '' !== $key ) {
				$options = array();
				foreach ( (array) $attribute->get_options() as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
				$expected[ $key ] = array_values( array_unique( $options ) );
			}
		}

		$expected_keys = array_keys( $expected );
		$actual_keys   = array();
		foreach ( is_array( $attrs ) ? $attrs : array() as $key => $value ) {
			$key   = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $key || '' === $value ) {
				return new WP_Error( 'mobo_core_variant_attribute_empty', 'Mobo variant contains an empty attribute selection.' );
			}
			if ( isset( $expected[ $key ] ) && ! empty( $expected[ $key ] ) && ! in_array( $value, $expected[ $key ], true ) ) {
				return new WP_Error( 'mobo_core_variant_attribute_value_invalid', 'Mobo variant attribute value is not allowed by the parent attribute: ' . $key . '=' . $value );
			}
			$actual_keys[] = $key;
		}

		$expected_keys = array_values( array_unique( $expected_keys ) );
		$actual_keys   = array_values( array_unique( $actual_keys ) );
		sort( $expected_keys, SORT_STRING );
		sort( $actual_keys, SORT_STRING );

		if ( $expected_keys !== $actual_keys ) {
			$missing = array_values( array_diff( $expected_keys, $actual_keys ) );
			$extra   = array_values( array_diff( $actual_keys, $expected_keys ) );
			return new WP_Error(
				'mobo_core_variant_attribute_incomplete',
				'Incomplete Mobo variant attributes. Missing: ' . implode( ', ', $missing ) . '; extra: ' . implode( ', ', $extra )
			);
		}

		return true;
	}

	private function normalize_variation_attributes( $attributes ) {
		$result = array();

		if ( ! is_array( $attributes ) ) {
			return $result;
		}

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			$name   = sanitize_text_field( (string) $this->get_value( $attribute, 'name', '' ) );
			$option = sanitize_text_field( (string) $this->get_value( $attribute, 'option', '' ) );

			if ( '' === $name || '' === $option ) {
				continue;
			}

			$key = sanitize_title( $name );

			if ( '' !== $key ) {
				$result[ $key ] = $option;
			}
		}

		return $result;
	}

	private function finalize_missing_variants( $product, $product_guid, $sync_id ) {
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'mobo_core_missing_variants_product_invalid', 'Product could not be loaded while finalizing missing variations.' );
		}

		$parent_id = absint( $product->get_id() );
		if ( $parent_id <= 0 || ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) ) {
			return new WP_Error( 'mobo_core_missing_variants_parent_excluded', 'Excluded/invalid parent cannot be mutated during variation finalization.' );
		}

		$seen_option = $this->seen_option_name( $product_guid, $sync_id );
		wp_cache_delete( $seen_option, 'options' );
		$seen = get_option( $seen_option, null );
		if ( ! is_array( $seen ) ) {
			return new WP_Error( 'mobo_core_seen_variants_checkpoint_missing', 'Authoritative variation seen-set is missing or unreadable; destructive finalization was blocked.' );
		}

		$children = array_values( array_unique( array_merge( $this->get_live_variation_children( $parent_id ), $this->get_pending_quarantine_children( $parent_id ) ) ) );
		if ( ! empty( $children ) ) {
			update_meta_cache( 'post', $children );
		}

		$quarantined = 0;
		$failed      = array();
		foreach ( $children as $variation_id ) {
			$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $variation_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
			if ( is_wp_error( $identity ) ) {
				$failed[ $variation_id ] = $identity->get_error_message();
				continue;
			}
			if ( empty( $identity['owned'] ) ) {
				continue;
			}
			$variant_guid = sanitize_text_field( (string) $identity['variantGuid'] );
			if ( '' !== $variant_guid && isset( $seen[ $variant_guid ] ) ) {
				continue;
			}

			$result = $this->quarantine_variation(
				$variation_id,
				'authoritative-variant-removed',
				array( 'parentId' => $parent_id, 'productGuid' => $product_guid, 'syncId' => $sync_id )
			);
			if ( is_wp_error( $result ) ) {
				$failed[ $variation_id ] = $result->get_error_message();
			} else {
				$quarantined++;
			}
		}

		if ( ! empty( $failed ) ) {
			return new WP_Error(
				'mobo_core_missing_variants_quarantine_failed',
				'One or more stale Mobo variations could not be quarantined; authoritative finalization was not committed and will be retried.',
				array( 'variations' => $failed )
			);
		}

		/* Remove only stale variation mapping rows after live retirement succeeded. */
		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$mapping_error = '';
			$this->product_map->delete_variations_for_parent( $product_guid, array_keys( $seen ), $mapping_error );
			if ( '' !== $mapping_error ) {
				return new WP_Error(
					'mobo_core_missing_variants_mapping_cleanup_failed',
					'Stale variations were quarantined, but stale variation mapping cleanup failed; finalization will retry.',
					array( 'mappingError' => $mapping_error )
				);
			}
		}

		return $quarantined;
	}

	private function is_remote_product_trashed( $guid ) {
		return $this->remote_post_has_blocked_status( $guid, 'product', 'product_guid', 'product' );
	}

	private function is_remote_variation_trashed( $guid ) {
		return $this->remote_post_has_blocked_status( $guid, 'product_variation', 'variant_guid', 'variation' );
	}

	private function remote_post_has_blocked_status( $guid, $post_type, $meta_key, $object_type ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return false;
		}

		$cache_key = sanitize_key( (string) $object_type ) . '|' . $guid;
		if ( array_key_exists( $cache_key, $this->blocked_status_cache ) ) {
			return (bool) $this->blocked_status_cache[ $cache_key ];
		}

		$status = '';
		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			if ( 'product' === $object_type && method_exists( $this->product_map, 'get_product_post_status' ) ) {
				$status = $this->product_map->get_product_post_status( $guid );
			} elseif ( 'variation' === $object_type && method_exists( $this->product_map, 'get_variation_post_status' ) ) {
				$status = $this->product_map->get_variation_post_status( $guid );
			}
		}

		/* A valid map row is authoritative; do not run a second legacy meta_query. */
		if ( '' !== $status ) {
			$blocked = in_array( $status, array( 'trash', 'auto-draft' ), true );
			$this->blocked_status_cache[ $cache_key ] = $blocked;
			return $blocked;
		}

		$blocked = $this->find_blocked_post_id_by_meta( $post_type, $meta_key, $guid ) > 0;
		$this->blocked_status_cache[ $cache_key ] = $blocked;
		return $blocked;
	}

	private function find_blocked_post_id_by_meta( $post_type, $meta_key, $meta_value ) {
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'trash', 'auto-draft' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					array(
						'key'   => sanitize_key( $meta_key ),
						'value' => $meta_value,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	private function find_product_id_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		$mapped_product_id = 0;

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$mapped_product_id = $this->product_map->get_product_id( $guid );
		}

		if ( class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$canonical_id = Mobo_Core_Product_Concurrency::get_canonical_product_id( $guid, $mapped_product_id );

			if ( $canonical_id > 0 ) {
				if ( $canonical_id !== $mapped_product_id ) {
					/*
					 * A legacy/canonical identity fallback is bootstrap evidence only.
					 * When no canonical Map row owns this identity yet, preserve the
					 * durable crash marker instead of silently electing the product as
					 * complete. Existing Map rows remain authoritative and are never
					 * overwritten from legacy postmeta here.
					 */
					$canonical_incomplete = '1' === (string) get_post_meta( $canonical_id, 'mobo_sync_incomplete', true );
					$this->upsert_product_map( $guid, $canonical_id, $canonical_incomplete );
				}
				return $canonical_id;
			}
		}

		if ( $mapped_product_id > 0 ) {
			return $mapped_product_id;
		}

		$product_id = $this->find_post_id_by_meta( 'product', 'product_guid', $guid );

		if ( $product_id > 0 ) {
			$legacy_incomplete = '1' === (string) get_post_meta( $product_id, 'mobo_sync_incomplete', true );
			$this->upsert_product_map( $guid, $product_id, $legacy_incomplete );
		}

		return $product_id;
	}

	/**
	 * Restore a trashed Mobo-owned object only while an authoritative Repair
	 * payload for the exact identity is being processed. Normal sync/webhook
	 * continues to respect a merchant trash action.
	 *
	 * @param string $post_type product|product_variation.
	 * @param string $meta_key Identity meta key.
	 * @param string $meta_value Identity value.
	 * @param int    $expected_parent Expected parent for variations.
	 * @return int
	 */
	private function restore_trashed_mobo_object_by_identity( $post_type, $meta_key, $meta_value, $expected_parent = 0 ) {
		$post_type       = sanitize_key( (string) $post_type );
		$meta_key        = sanitize_key( (string) $meta_key );
		$meta_value      = sanitize_text_field( (string) $meta_value );
		$expected_parent = absint( $expected_parent );
		if ( '' === $post_type || '' === $meta_key || '' === $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'trash' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 2,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded authoritative Repair identity lookup.
				'meta_query'             => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);
		$ids = array_values( array_filter( array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) ) );
		if ( 1 !== count( $ids ) ) {
			/* Multiple trashed objects with one identity are ambiguous. The bounded
			 * integrity stage reports duplicates; never guess which parent/history to restore. */
			return 0;
		}

		$post_id = absint( $ids[0] );
		if ( 'product_variation' === $post_type && $expected_parent > 0 && absint( wp_get_post_parent_id( $post_id ) ) !== $expected_parent ) {
			return 0;
		}

		$restored = wp_untrash_post( $post_id );
		if ( ! $restored || 'trash' === get_post_status( $post_id ) ) {
			return 0;
		}

		$cache_key = ( 'product_variation' === $post_type ? 'variation' : 'product' ) . '|' . $meta_value;
		unset( $this->blocked_status_cache[ $cache_key ] );
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::increment( 'repair_untrashed_object' );
		}
		return $post_id;
	}

	/**
	 * Find an active sibling by concrete Portal purchase identity.
	 *
	 * If legacy data contains more than one candidate, prefer the candidate whose
	 * attribute signature matches the incoming payload; if several still match,
	 * prefer an exact incoming GUID and finally the oldest complete local object.
	 * This method never crosses parent-product boundaries.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param array $data Variation payload.
	 * @return int
	 */
	/**
	 * Find an existing WooCommerce variation by the canonical Mobo variant GUID.
	 *
	 * GUID is the first identity lookup. PortalVariantId and attribute signature
	 * remain fallback identities when the local GUID map is missing.
	 *
	 * @param string $guid Variant GUID.
	 * @return int
	 */
	private function find_variation_id_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private', 'draft', 'trash' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => 'variant_guid',
						'value' => $guid,
					),
				),
			)
		);

		return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
	}

	private function find_variation_id_by_portal_variant_id( $parent_id, $data ) {
		global $wpdb;

		$parent_id         = absint( $parent_id );
		$portal_variant_id = $this->extract_portal_variant_id( $data );
		if ( $parent_id <= 0 || $portal_variant_id <= 0 ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'product_variation'
				AND p.post_parent = %d
				AND p.post_status IN ('publish','private','draft','pending')
				AND pm.meta_key IN ('_mobo_portal_variant_id','mobo_portal_variant_id','portal_variant_id')
				AND pm.meta_value = %s
				ORDER BY p.ID ASC",
				$parent_id,
				(string) $portal_variant_id
			)
		);
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		if ( 1 === count( $ids ) ) {
			return absint( $ids[0] );
		}

		$incoming_signature = $this->variation_attribute_signature( $this->normalize_variation_attributes( $this->get_value( $data, 'attributes', array() ) ) );
		$incoming_guid      = $this->extract_variant_guid( $data );
		$ranked             = array();
		$exact_guid_ids     = array();
		$signature_ids      = array();

		foreach ( $ids as $id ) {
			$all_meta = get_post_meta( $id );
			$attrs    = array();
			foreach ( is_array( $all_meta ) ? $all_meta : array() as $key => $values ) {
				if ( 0 !== strpos( (string) $key, 'attribute_' ) ) {
					continue;
				}
				$value = is_array( $values ) && isset( $values[0] ) ? $values[0] : '';
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$attrs[ $key ] = (string) $value;
				}
			}

			$stored_signature = $this->variation_attribute_signature( $attrs );
			$stored_guid      = sanitize_text_field( (string) get_post_meta( $id, 'variant_guid', true ) );
			$score            = 0;
			if ( '' !== $incoming_signature && $stored_signature === $incoming_signature ) {
				$score += 100;
				$signature_ids[] = $id;
			}
			if ( '' !== $incoming_guid && $stored_guid === $incoming_guid ) {
				$score += 1000;
				$exact_guid_ids[] = $id;
			}
			if ( '1' !== (string) get_post_meta( $id, 'mobo_sync_incomplete', true ) ) {
				$score += 10;
			}
			$ranked[] = array( 'id' => $id, 'score' => $score );
		}

		/* With duplicate Portal identity, never choose a candidate merely because it
		 * is older/complete. At least the exact remote GUID or the concrete incoming
		 * attribute signature must agree. This prevents an ambiguous legacy duplicate
		 * set from being silently mutated into the wrong Variation. */
		if ( empty( $exact_guid_ids ) && empty( $signature_ids ) ) {
			throw new RuntimeException( 'Duplicate PortalVariantId candidates are ambiguous; no existing Variation matches the incoming GUID or attribute signature.' );
		}

		usort(
			$ranked,
			static function ( $left, $right ) {
				if ( absint( $left['score'] ) === absint( $right['score'] ) ) {
					return absint( $left['id'] ) <=> absint( $right['id'] );
				}
				return absint( $right['score'] ) <=> absint( $left['score'] );
			}
		);

		return ! empty( $ranked[0]['id'] ) ? absint( $ranked[0]['id'] ) : 0;
	}

	/**
	 * Persist product GUID map if the table is available.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @param int    $product_id Product ID.
	 * @param bool   $sync_incomplete Sync incomplete.
	 * @return void
	 */
	private function upsert_product_map( $product_guid, $product_id, $sync_incomplete = false ) {
		if ( ! ( $this->product_map instanceof Mobo_Core_Product_Map ) ) {
			return false;
		}

		$last_hash = sanitize_text_field( (string) get_post_meta( absint( $product_id ), '_mobo_product_source_hash', true ) );
		$stored = (bool) $this->product_map->upsert_product( $product_guid, $product_id, $last_hash, $sync_incomplete );
		if ( ! $stored ) {
			return false;
		}
		if ( class_exists( 'Mobo_Core_Product_Ledger' ) ) {
			Mobo_Core_Product_Ledger::record( $product_guid, $product_id, $this->is_repair_mode() ? 'repair' : 'sync', false );
		}
		return true;
	}

	/**
	 * Persist variation GUID map if the table is available.
	 *
	 * @param string $variant_guid Remote variant GUID.
	 * @param int    $variation_id Variation ID.
	 * @param string $product_guid Parent remote product GUID.
	 * @param bool   $sync_incomplete Sync incomplete.
	 * @return bool
	 */
	private function upsert_variation_map( $variant_guid, $variation_id, $product_guid = '', $sync_incomplete = false ) {
		if ( ! ( $this->product_map instanceof Mobo_Core_Product_Map ) ) {
			return false;
		}

		$last_hash = sanitize_text_field( (string) get_post_meta( absint( $variation_id ), '_mobo_variant_source_hash', true ) );
		return (bool) $this->product_map->upsert_variation( $variant_guid, $variation_id, $product_guid, $last_hash, $sync_incomplete );
	}

	private function find_post_id_by_meta( $post_type, $meta_key, $meta_value ) {
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => sanitize_key( $post_type ),
				'post_status'            => array( 'publish', 'draft', 'private', 'pending', 'inherit' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					array(
						'key'   => sanitize_key( $meta_key ),
						'value' => $meta_value,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	private function persist_seen_variants_verified( $option_name, $seen ) {
		$option_name = sanitize_key( (string) $option_name );
		$seen        = is_array( $seen ) ? $seen : array();
		if ( '' === $option_name ) {
			return false;
		}

		update_option( $option_name, $seen, false );
		wp_cache_delete( $option_name, 'options' );
		$stored = get_option( $option_name, null );
		return is_array( $stored ) && maybe_serialize( $stored ) === maybe_serialize( $seen );
	}

	private function reset_seen_variants( $product_guid, $sync_id ) {
		return $this->persist_seen_variants_verified( $this->seen_option_name( $product_guid, $sync_id ), array() );
	}

	private function initialize_seen_variants( $product_guid, $sync_id ) {
		$option_name = $this->seen_option_name( $product_guid, $sync_id );
		wp_cache_delete( $option_name, 'options' );
		$existing = get_option( $option_name, null );
		if ( is_array( $existing ) ) {
			return true;
		}
		return $this->persist_seen_variants_verified( $option_name, array() );
	}

	private function mark_variant_seen( $product_guid, $sync_id, $variant_guid ) {
		return $this->mark_variants_seen_bulk( $product_guid, $sync_id, array( $variant_guid ) );
	}

	/**
	 * Persist all variation GUIDs seen on one authoritative page with one option write.
	 *
	 * @param string $product_guid Product GUID.
	 * @param string $sync_id Sync ID.
	 * @param array  $variant_guids Variation GUIDs.
	 * @return void
	 */
	private function mark_variants_seen_bulk( $product_guid, $sync_id, $variant_guids ) {
		$option_name = $this->seen_option_name( $product_guid, $sync_id );
		$seen        = get_option( $option_name, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}

		$changed = false;
		foreach ( is_array( $variant_guids ) ? $variant_guids : array() as $variant_guid ) {
			$variant_guid = sanitize_text_field( (string) $variant_guid );
			if ( '' === $variant_guid || isset( $seen[ $variant_guid ] ) ) {
				continue;
			}
			$seen[ $variant_guid ] = 1;
			$changed = true;
		}

		if ( ! $changed ) {
			return true;
		}

		return $this->persist_seen_variants_verified( $option_name, $seen );
	}

	private function count_seen_variants( $product_guid, $sync_id ) {
		$option_name = $this->seen_option_name( $product_guid, $sync_id );
		wp_cache_delete( $option_name, 'options' );
		$seen = get_option( $option_name, null );

		return is_array( $seen ) ? count( $seen ) : -1;
	}


	/**
	 * Deprecated: product identity is GUID-only and must be present in payload.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function extract_product_guid_from_url( $url ) {
		return '';
	}

	private function build_missing_product_id_message( $payload, $variants ) {
		$payload_keys = is_array( $payload ) ? implode( ',', array_slice( array_keys( $payload ), 0, 20 ) ) : '';
		$data_keys    = '';
		$variant_keys = '';

		$data = $this->get_value( $payload, 'data', null );
		if ( is_array( $data ) ) {
			$data_keys = implode( ',', array_slice( array_keys( $data ), 0, 20 ) );
		}

		if ( is_array( $variants ) && isset( $variants[0] ) && is_array( $variants[0] ) ) {
			$variant_keys = implode( ',', array_slice( array_keys( $variants[0] ), 0, 20 ) );
		}

		$pulled_from = is_array( $payload ) ? (string) $this->get_value( $payload, '_moboPulledFrom', '' ) : '';

		return sprintf(
			'productId is required. PayloadKeys=%s DataKeys=%s FirstVariantKeys=%s PulledFrom=%s',
			$payload_keys,
			$data_keys,
			$variant_keys,
			$pulled_from
		);
	}

	private function clear_seen_variants( $product_guid, $sync_id ) {
		$option_name = $this->seen_option_name( $product_guid, $sync_id );
		delete_option( $option_name );
		wp_cache_delete( $option_name, 'options' );
		return null === get_option( $option_name, null );
	}

	private function seen_option_name( $product_guid, $sync_id ) {
		return 'mobo_seen_variants_' . md5( sanitize_text_field( (string) $product_guid ) . '|' . sanitize_text_field( (string) $sync_id ) );
	}

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




	private function extract_portal_product_id( $data ) {
		return $this->extract_positive_int_from_payload(
			$data,
			array(
				'portal_product_id',
				'portalProductId',
				'PortalProductId',
				'portal_product_id_api',
				'portalId',
			)
		);
	}

	private function extract_portal_variant_id( $data ) {
		$portal_variant_id = $this->extract_positive_int_from_payload(
			$data,
			array(
				'portal_variant_id',
				'portalVariantId',
				'PortalVariantId',
				'portalVariantID',
				'variant_portal_id',
				'variantPortalId',
			)
		);

		if ( $portal_variant_id > 0 ) {
			return $portal_variant_id;
		}

		/*
		 * Storefront simple products expose the purchasable ID as variant.id.
		 * A bare numeric id is accepted only when the array itself is clearly a
		 * variant payload, so the Mobo product ID cannot be mistaken for a variant.
		 */
		if ( $this->looks_like_variant_payload( $data ) ) {
			return $this->extract_positive_int_from_payload(
				$data,
				array( 'id', 'variant_id', 'variantIdNumeric', 'portalId' )
			);
		}

		$embedded = $this->extract_embedded_simple_variant_data( $data );

		if ( ! empty( $embedded ) && $embedded !== $data ) {
			return $this->extract_portal_variant_id( $embedded );
		}

		return 0;
	}

	private function extract_positive_int_from_payload( $data, $keys ) {
		if ( ! is_array( $data ) || ! is_array( $keys ) ) {
			return 0;
		}

		foreach ( $keys as $key ) {
			$value = $this->get_value( $data, $key, null );

			if ( null === $value || '' === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
				continue;
			}

			$int_value = absint( $value );

			if ( $int_value > 0 ) {
				return $int_value;
			}
		}

		return 0;
	}

	/**
	 * Extract remote product GUID from a payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_product_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$keys = array( 'product_guid', 'productGuid', 'productId', 'parent_product_guid', 'parentProductId', 'parentGuid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entityId', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract remote variation GUID from a payload.
	 *
	 * @param array $data Payload.
	 * @return string
	 */
	private function extract_variant_guid( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$keys = array( 'variant_guid', 'variantGuid', 'variantId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entity_id', 'entityId', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $data, $key, '' ) );
			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
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
	 * Return the first non-empty scalar value.
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
	 * Resolve parent product GUID from a known remote variant GUID.
	 *
	 * @param string $variant_guid Remote variant GUID.
	 * @return string
	 */
	private function find_parent_product_guid_by_variant_guid( $variant_guid ) {
		global $wpdb;

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( '' === $variant_guid ) {
			return '';
		}

		$parent_guid = '';

		if ( class_exists( 'Mobo_Core_Product_Map' ) && Mobo_Core_Product_Map::table_exists() ) {
			$table = Mobo_Core_Product_Map::table_name();
			$parent_guid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT parent_remote_guid FROM {$table} WHERE remote_guid = %s AND object_type = %s LIMIT 1",
					$variant_guid,
					Mobo_Core_Product_Map::TYPE_VARIATION
				)
			);
		}

		if ( '' !== sanitize_text_field( (string) $parent_guid ) ) {
			return sanitize_text_field( (string) $parent_guid );
		}

		/* Simple products keep the remote variant GUID on the product post. */
		$product_id = absint( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'product' AND pm.meta_key IN ('variant_guid','mobo_variant_guid','_mobo_variant_guid') AND pm.meta_value = %s LIMIT 1",
				$variant_guid
			)
		) );

		return $product_id > 0 ? sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) ) : '';
	}

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

	private function result( $success, $message, $data = array() ) {
		return array(
			'success' => (bool) $success,
			'message' => sanitize_text_field( (string) $message ),
			'data'    => is_array( $data ) ? $data : array(),
		);
	}

	

/**
 * Return product category refs from all supported payload field names.
 *
 * Identity must be based on category GUIDs, but the collection name can differ
 * between .NET serializers or older payload versions.
 *
 * @param array $data Product payload.
 * @return array
 */
private function get_product_category_refs_from_payload( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	$keys = array(
		'product_categories',
		'productCategories',
		'ProductCategories',
		'category_refs',
		'categoryRefs',
		'categories',
		'Categories',
		'category_guids',
		'categoryGuids',
		'CategoryGuids',
	);

	foreach ( $keys as $key ) {
		$value = $this->get_value( $data, $key, null );

		if ( is_array( $value ) && ! empty( $value ) ) {
			return $value;
		}
	}

	return array();
}


/**
 * Store product category refs for later category reapply runs.
 *
 * @param int   $product_id Product ID.
 * @param mixed $category_refs Raw category refs.
 * @return void
 */
private function store_product_category_refs( $product_id, $category_refs ) {
	$product_id = absint( $product_id );

	if ( $product_id <= 0 ) {
		return false;
	}

	if ( ! is_array( $category_refs ) ) {
		return false;
	}

	$normalized = array();
	$guids      = array();
	foreach ( $category_refs as $ref ) {
		if ( ! is_array( $ref ) ) {
			$guid = sanitize_text_field( (string) $ref );
			if ( $this->is_remote_guid_value( $guid ) ) {
				$normalized[] = array( 'id' => $guid );
				$guids[]      = $guid;
			}
			continue;
		}
		$guid = $this->extract_category_guid_for_storage( $ref );
		if ( '' === $guid ) {
			continue;
		}
		$normalized[] = array(
			'id'       => $guid,
			'title'    => sanitize_text_field( (string) $this->get_value( $ref, 'title', '' ) ),
			'url'      => sanitize_text_field( (string) $this->get_value( $ref, 'url', '' ) ),
			'parentId' => sanitize_text_field( (string) $this->get_value( $ref, 'parentId', '' ) ),
		);
		$guids[] = $guid;
	}

	/* An explicit empty list is authoritative desired state, not absence of local
	 * knowledge. Persist [] plus a marker so Recategorize can never resurrect
	 * categories from an older webhook/API fallback after the source intentionally
	 * cleared every category. */
	$json = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return false;
	}
	$guids = array_values( array_unique( array_filter( $guids ) ) );
	return $this->persist_post_meta_verified( $product_id, 'mobo_product_category_refs_json', $json )
		&& $this->persist_post_meta_verified( $product_id, 'mobo_product_category_guids', $guids )
		&& $this->persist_post_meta_verified( $product_id, '_mobo_product_category_refs_authoritative', '1' );
}

/**
 * Extract category GUID for storage.
 *
 * @param array $ref Category ref.
 * @return string
 */
private function extract_category_guid_for_storage( $ref ) {
	$guids = $this->collect_category_guid_candidates_for_storage( $ref );

	return ! empty( $guids ) ? sanitize_text_field( (string) $guids[0] ) : '';
}

/**
 * Collect category GUID candidates for storage, preferring actual category GUIDs over relation IDs.
 *
 * @param mixed $ref Category ref.
 * @return array
 */
private function collect_category_guid_candidates_for_storage( $ref ) {
	return Mobo_Core_Remote_Identity_Policy::collect_category_guid_candidates( $ref );
}

/**
 * Append storage GUID candidate.
 *
 * @param array $guids GUID list.
 * @param mixed $value Raw value.
 * @return void
 */
private function append_category_guid_candidate_for_storage( &$guids, $value ) {
	$value = trim( sanitize_text_field( (string) $value ) );
	if ( '' !== $value && $this->is_remote_guid_value( $value ) ) {
		$guids[] = $value;
	}
}

/**
 * Check if product should be excluded by URL.
 *
 * @param array $product_data Product payload.
 * @return bool
 */
private function should_skip_product_by_url( $product_data ) {
	return Mobo_Core_Product_Exclusions::is_payload_excluded( $product_data, true );
}

/**
 * Get excluded product URLs from the single shared policy source of truth.
 *
 * @return array
 */
private function get_excluded_product_urls() {
	return Mobo_Core_Product_Exclusions::get_excluded_urls();
}

/**
 * Normalize product URL/path through the single shared exclusion policy.
 *
 * @param string $url URL or path.
 * @return string
 */
private function normalize_product_url_for_exclusion( $url ) {
	return Mobo_Core_Product_Exclusions::normalize_url( $url );
}
}
