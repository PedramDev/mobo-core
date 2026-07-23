<?php
/**
 * Safe automation coordinator for the legacy-image refresh workflow.
 *
 * It advances one bounded workflow batch per cron/self-runner slice. Read-only
 * scans, queue creation, replacement, WebP subsize verification/repair and
 * post-replacement audits are automatic. Destructive stages pause until the
 * administrator gives one explicit approval for that stage.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Image_Refresh_Automation {

	const OPTION_ENABLED                 = 'mobo_core_image_refresh_automation_enabled';
	const OPTION_DELETE_OLD_APPROVED     = 'mobo_core_image_refresh_auto_delete_old_approved';
	const OPTION_DELETE_ORPHAN_APPROVED  = 'mobo_core_image_refresh_auto_delete_orphan_approved';
	const OPTION_STARTED_AT              = 'mobo_core_image_refresh_automation_started_at';
	const OPTION_COMPLETED_AT            = 'mobo_core_image_refresh_automation_completed_at';
	const OPTION_LAST_RESULT             = 'mobo_core_image_refresh_automation_last_result';
	const OPTION_LAST_RUN_AT             = 'mobo_core_image_refresh_automation_last_run_at';
	const OPTION_LAST_TICK_STARTED_AT    = 'mobo_core_image_refresh_automation_last_tick_started_at';
	const OPTION_LAST_TICK_FINISHED_AT   = 'mobo_core_image_refresh_automation_last_tick_finished_at';
	const OPTION_LAST_TICK_SOURCE        = 'mobo_core_image_refresh_automation_last_tick_source';

	/**
	 * Return current automation status for admin and health reporting.
	 *
	 * @return array
	 */
	public static function get_status() {
		$last = get_option( self::OPTION_LAST_RESULT, array() );
		$last = is_array( $last ) ? $last : array();

		$tick_started  = absint( get_option( self::OPTION_LAST_TICK_STARTED_AT, 0 ) );
		$tick_finished = absint( get_option( self::OPTION_LAST_TICK_FINISHED_AT, 0 ) );
		$lock_active   = class_exists( 'Mobo_Core_Lock' ) && Mobo_Core_Lock::is_locked( 'image_refresh_automation' );
		$tick_open     = $tick_started > 0 && $tick_started > $tick_finished;
		$tick_age      = $tick_open ? max( 0, time() - $tick_started ) : 0;

		return array(
			'enabled'              => Mobo_Core_Settings::enabled( self::OPTION_ENABLED, '0' ),
			'deleteOldApproved'    => Mobo_Core_Settings::enabled( self::OPTION_DELETE_OLD_APPROVED, '0' ),
			'deleteOrphanApproved' => Mobo_Core_Settings::enabled( self::OPTION_DELETE_ORPHAN_APPROVED, '0' ),
			'startedAt'            => absint( get_option( self::OPTION_STARTED_AT, 0 ) ),
			'completedAt'          => absint( get_option( self::OPTION_COMPLETED_AT, 0 ) ),
			'lastRunAt'            => absint( get_option( self::OPTION_LAST_RUN_AT, 0 ) ),
			'lastTickStartedAt'    => $tick_started,
			'lastTickFinishedAt'   => $tick_finished,
			'lastTickDuration'     => $tick_started > 0 && $tick_finished >= $tick_started ? $tick_finished - $tick_started : 0,
			'lastTickSource'       => sanitize_key( (string) get_option( self::OPTION_LAST_TICK_SOURCE, '' ) ),
			'batchRunning'         => $lock_active && $tick_open && $tick_age <= 240,
			'batchPossiblyStuck'   => $tick_open && $tick_age > 240,
			'currentStep'          => absint( isset( $last['step'] ) ? $last['step'] : 0 ),
			'status'               => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : 'idle',
			'waitingApproval'      => isset( $last['waitingApproval'] ) ? sanitize_key( (string) $last['waitingApproval'] ) : '',
			'message'              => isset( $last['message'] ) ? sanitize_text_field( (string) $last['message'] ) : '',
			'lastResult'           => $last,
		);
	}

	/**
	 * Start or resume automation without resetting current safe progress.
	 *
	 * @return array
	 */
	public function start() {
		$previous = self::get_status();
		if ( 'completed' === ( isset( $previous['status'] ) ? $previous['status'] : '' ) ) {
			$this->reset_for_new_cycle();
		}

		if ( ! class_exists( 'Mobo_Core_Product_Sync' ) || ! Mobo_Core_Product_Sync::is_repair_completed() ) {
			return $this->save_result(
				array(
					'success' => false,
					'status'  => 'locked-until-repair',
					'step'    => 0,
					'message' => 'ابتدا ترمیم محصولات باید یک بار کامل شود؛ سپس اجرای خودکار نوسازی تصاویر قابل شروع است.',
				)
			);
		}

		update_option( self::OPTION_ENABLED, '1', false );
		update_option( self::OPTION_COMPLETED_AT, 0, false );
		if ( absint( get_option( self::OPTION_STARTED_AT, 0 ) ) <= 0 ) {
			update_option( self::OPTION_STARTED_AT, time(), false );
		}

		/* Destructive approvals are one-time gates and are never inherited when
		 * starting/resuming through the generic start action. Approval actions resume
		 * their own stage directly. */
		update_option( self::OPTION_DELETE_OLD_APPROVED, '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );

		return $this->save_result(
			array(
				'success'           => true,
				'status'            => 'started',
				'step'              => 1,
				'needsContinuation' => true,
				'progressed'        => true,
				'message'           => 'اجرای خودکار امن فعال شد. مراحل غیرحذفی با Cron یا Self Runner ادامه پیدا می‌کنند.',
			)
		);
	}

	/**
	 * Pause automation and turn off all execution/deletion switches.
	 *
	 * @param string $reason Pause reason.
	 * @param string $status Status key.
	 * @return array
	 */
	public function pause( $reason = 'اجرای خودکار توسط مدیر متوقف شد.', $status = 'paused' ) {
		update_option( self::OPTION_ENABLED, '0', false );
		update_option( self::OPTION_DELETE_OLD_APPROVED, '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );

		return $this->save_result(
			array(
				'success' => true,
				'status'  => sanitize_key( (string) $status ),
				'step'    => $this->detect_step(),
				'message' => sanitize_text_field( (string) $reason ),
			)
		);
	}

	/**
	 * Approve and resume automatic deletion of replaced old attachments.
	 *
	 * @return array
	 */
	public function approve_delete_old() {
		$status = self::get_status();
		if ( 'delete-old' !== ( isset( $status['waitingApproval'] ) ? $status['waitingApproval'] : '' ) ) {
			return array(
				'success' => false,
				'status'  => 'approval-not-available',
				'step'    => $this->detect_step(),
				'message' => 'در وضعیت فعلی، مرحله حذف پیوست های قدیمی منتظر تایید مدیر نیست.',
			);
		}

		update_option( self::OPTION_DELETE_OLD_APPROVED, '1', false );
		update_option( self::OPTION_ENABLED, '1', false );
		update_option( 'mobo_core_image_refresh_delete_old', '1', false );

		return $this->save_result(
			array(
				'success'           => true,
				'status'            => 'delete-old-approved',
				'step'              => 7,
				'needsContinuation' => true,
				'progressed'        => true,
				'message'           => 'حذف امن پیوست‌های قدیمی برای این مرحله تایید شد و به صورت batch ادامه پیدا می‌کند.',
			)
		);
	}

	/**
	 * Approve and resume automatic deletion of orphan raster families.
	 *
	 * @return array
	 */
	public function approve_delete_orphans() {
		$status = self::get_status();
		if ( 'delete-orphan' !== ( isset( $status['waitingApproval'] ) ? $status['waitingApproval'] : '' ) ) {
			return array(
				'success' => false,
				'status'  => 'approval-not-available',
				'step'    => $this->detect_step(),
				'message' => 'در وضعیت فعلی، مرحله حذف خانواده های بدون پیوست منتظر تایید مدیر نیست.',
			);
		}

		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '1', false );
		update_option( self::OPTION_ENABLED, '1', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '1', false );

		return $this->save_result(
			array(
				'success'           => true,
				'status'            => 'delete-orphan-approved',
				'step'              => 9,
				'needsContinuation' => true,
				'progressed'        => true,
				'message'           => 'حذف کنترل‌شده خانواده‌های بدون پیوست تایید شد و به صورت batch ادامه پیدا می‌کند.',
			)
		);
	}

	/**
	 * Execute one bounded automation action.
	 *
	 * @param string $source Runner source.
	 * @return array
	 */
	public function run_tick( $source = 'real-cron' ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return array_merge( Mobo_Core_Upgrade_Coordinator::paused_result( 'image-refresh-automation' ), array( 'progressed' => false, 'needsContinuation' => false ) );
		}

		if ( ! Mobo_Core_Settings::enabled( self::OPTION_ENABLED, '0' ) ) {
			return array(
				'success'           => true,
				'status'            => 'disabled',
				'step'              => $this->detect_step(),
				'needsContinuation' => false,
				'progressed'        => false,
				'message'           => 'اجرای خودکار نوسازی تصاویر غیرفعال است.',
			);
		}

		$lock = Mobo_Core_Lock::acquire( 'image_refresh_automation', 180 );
		if ( false === $lock ) {
			return $this->save_result(
				array(
					'success'           => true,
					'status'            => 'locked',
					'step'              => $this->detect_step(),
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'یک اجرای دیگر نوسازی خودکار هم اکنون فعال است.',
				)
			);
		}

		$tick_started = time();
		update_option( self::OPTION_LAST_TICK_STARTED_AT, $tick_started, false );
		update_option( self::OPTION_LAST_TICK_FINISHED_AT, 0, false );
		update_option( self::OPTION_LAST_TICK_SOURCE, sanitize_key( (string) $source ), false );

		try {
			update_option( self::OPTION_LAST_RUN_AT, $tick_started, false );
			$result = $this->run_locked( sanitize_key( (string) $source ) );
		} catch ( Throwable $e ) {
			$result = $this->pause_for_error( 'اجرای خودکار به دلیل خطای غیرمنتظره متوقف شد: ' . $e->getMessage(), 0, 'automation-exception' );
			$result['exceptionClass'] = get_class( $e );
			$result['file']           = $e->getFile();
			$result['line']           = $e->getLine();
		} finally {
			$tick_finished = time();
			update_option( self::OPTION_LAST_TICK_FINISHED_AT, $tick_finished, false );
			Mobo_Core_Lock::release( 'image_refresh_automation', $lock );
		}

		$result['source']          = sanitize_key( (string) $source );
		$result['tickStartedAt']   = $tick_started;
		$result['tickFinishedAt']  = isset( $tick_finished ) ? $tick_finished : time();
		$result['durationSeconds'] = max( 0, $result['tickFinishedAt'] - $tick_started );
		return $this->save_result( $result );
	}

	/**
	 * Run one stage while the automation lock is held.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function run_locked( $source ) {
		if ( ! class_exists( 'Mobo_Core_Product_Sync' ) || ! Mobo_Core_Product_Sync::is_repair_completed() ) {
			return $this->pause_for_error( 'ترمیم محصولات کامل نیست؛ اجرای خودکار متوقف شد.', 0, 'locked-until-repair' );
		}

		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Service' ) || ! class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ) {
			return $this->pause_for_error( 'کلاس‌های نوسازی تصاویر در دسترس نیستند.', 0, 'missing-components' );
		}

		$service    = new Mobo_Core_Image_Refresh_Service();
		$queue      = new Mobo_Core_Image_Refresh_Queue();
		$scan_limit = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_scan_limit', 500, 50, 5000 );
		$state      = $this->read_state( $queue );

		/* 1. Read-only legacy scan. */
		if ( empty( $state['scanComplete'] ) ) {
			$operation = $service->scan_legacy_images( $scan_limit );
			return $this->operation_result( 1, 'scan-legacy', $operation, 'بررسی خودکار تصاویر قدیمی', empty( $operation['cycleComplete'] ) );
		}

		/* 2. Queue construction. */
		if ( empty( $state['enqueueComplete'] ) ) {
			$operation = $service->enqueue_legacy_images( $scan_limit );
			return $this->operation_result( 2, 'enqueue', $operation, 'ساخت خودکار صف نوسازی', empty( $operation['cycleComplete'] ) );
		}

		/* 3. Replacement queue. */
		if ( $state['failed'] > 0 ) {
			return $this->pause_for_error( sprintf( '%d ردیف نوسازی ناموفق است. علت را بررسی و بعد از رفع مشکل اجرای خودکار را ادامه دهید.', $state['failed'] ), 3, 'queue-failed' );
		}

		if ( $state['pending'] > 0 ) {
			if ( $state['activeProcessing'] > 0 ) {
				return array(
					'success'           => true,
					'status'            => 'waiting-active-processor',
					'step'              => 3,
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'پردازش دیگری صف نوسازی را در اختیار دارد؛ اجرای بعدی دوباره بررسی می‌کند.',
				);
			}

			if ( $state['due'] <= 0 ) {
				return array(
					'success'           => true,
					'status'            => 'waiting-retry',
					'step'              => 3,
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => sprintf( '%d ردیف تا زمان تلاش مجدد منتظر است. Cron بعدی آن‌ها را ادامه می‌دهد.', max( $state['waitingRetry'], $state['pending'] ) ),
				);
			}

			if ( ! $this->image_environment_ready() ) {
				return $this->pause_for_error( 'موتور تصویر WebP یا دسترسی نوشتن uploads آماده نیست؛ اجرای خودکار متوقف شد.', 3, 'image-environment-not-ready' );
			}

			update_option( 'mobo_core_image_refresh_enabled', '1', false );
			$limit     = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
			$operation = $service->process_queue( $limit );
			$after     = $queue->get_status();

			if ( absint( isset( $after['failed'] ) ? $after['failed'] : 0 ) > 0 ) {
				return $this->pause_for_error( 'پس از پردازش، یک یا چند ردیف به وضعیت ناموفق نهایی رسید. جزئیات صف را بررسی کنید.', 3, 'queue-failed' );
			}

			$remaining = absint( isset( $after['pending'] ) ? $after['pending'] : 0 ) > 0;
			return $this->operation_result( 3, 'process-queue', $operation, 'پردازش خودکار صف نوسازی', $remaining );
		}

		update_option( 'mobo_core_image_refresh_enabled', '0', false );

		/* 4 and 5. WebP subsize audit, repair and verification. */
		$subsize_scan        = $state['subsizeScan'];
		$subsize_repair      = $state['subsizeRepair'];
		$subsize_scan_time   = absint( isset( $subsize_scan['checkedAt'] ) ? $subsize_scan['checkedAt'] : 0 );
		$subsize_repair_time = absint( isset( $subsize_repair['checkedAt'] ) ? $subsize_repair['checkedAt'] : 0 );
		$repair_complete     = $subsize_repair_time > 0 && ! empty( $subsize_repair['cycleComplete'] );
		$repair_newer        = $repair_complete && $subsize_repair_time >= $subsize_scan_time;

		if ( empty( $subsize_scan['cycleComplete'] ) || $repair_newer ) {
			$operation = $service->audit_webp_subsizes( $scan_limit, false );
			return $this->operation_result( 4, 'scan-webp-subsizes', $operation, 'اسکن خودکار سلامت برش‌های WebP', empty( $operation['cycleComplete'] ) );
		}

		$hard_errors = absint( isset( $subsize_scan['unsupportedEditor'] ) ? $subsize_scan['unsupportedEditor'] : 0 )
			+ absint( isset( $subsize_scan['missingOriginal'] ) ? $subsize_scan['missingOriginal'] : 0 );
		if ( $hard_errors > 0 ) {
			return $this->pause_for_error( 'فایل اصلی WebP مفقود است یا موتور تصویر امکان بازسازی ندارد. جزئیات مرحله ۴ را بررسی کنید.', 4, 'subsize-hard-error' );
		}

		if ( absint( isset( $subsize_scan['needsRepair'] ) ? $subsize_scan['needsRepair'] : 0 ) > 0 ) {
			if ( ! $this->image_environment_ready() ) {
				return $this->pause_for_error( 'برای بازسازی برش‌ها، موتور WebP یا دسترسی uploads آماده نیست.', 5, 'image-environment-not-ready' );
			}

			$operation = $service->audit_webp_subsizes( $scan_limit, true );
			if ( ! empty( $operation['cycleComplete'] ) && absint( isset( $operation['failed'] ) ? $operation['failed'] : 0 ) > 0 ) {
				return $this->pause_for_error( 'بازسازی یک یا چند تصویر کامل نشد. جزئیات مرحله ۵ را بررسی کنید.', 5, 'subsize-repair-failed' );
			}
			return $this->operation_result( 5, 'repair-webp-subsizes', $operation, 'بازسازی خودکار برش‌های ناقص WebP', true );
		}

		/* 6 and 7. Audit replaced old attachments; delete only after approval. */
		$replaced_scan        = $state['replacedScan'];
		$replaced_delete      = $state['replacedDelete'];
		$replaced_scan_time   = absint( isset( $replaced_scan['checkedAt'] ) ? $replaced_scan['checkedAt'] : 0 );
		$replaced_delete_time = absint( isset( $replaced_delete['checkedAt'] ) ? $replaced_delete['checkedAt'] : 0 );
		$delete_complete      = $replaced_delete_time > 0 && ! empty( $replaced_delete['cycleComplete'] );
		$delete_newer         = $delete_complete && $replaced_delete_time >= $replaced_scan_time;

		if ( empty( $replaced_scan['cycleComplete'] ) || $delete_newer ) {
			$operation = $service->audit_replaced_legacy_attachments( $scan_limit, false );
			return $this->operation_result( 6, 'scan-replaced-old', $operation, 'اسکن خودکار پیوست‌های قدیمی جایگزین‌شده', empty( $operation['cycleComplete'] ) );
		}

		$replaced_ready = absint( isset( $replaced_scan['ready'] ) ? $replaced_scan['ready'] : 0 );
		if ( $replaced_ready > 0 ) {
			if ( ! Mobo_Core_Settings::enabled( self::OPTION_DELETE_OLD_APPROVED, '0' ) ) {
				update_option( 'mobo_core_image_refresh_delete_old', '0', false );
				return array(
					'success'           => true,
					'status'            => 'waiting-delete-old-approval',
					'step'              => 7,
					'waitingApproval'   => 'delete-old',
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => sprintf( '%d پیوست قدیمی شرایط حذف امن دارد. پس از بررسی بکاپ، یک بار حذف این مرحله را تایید کنید.', $replaced_ready ),
				);
			}

			update_option( 'mobo_core_image_refresh_delete_old', '1', false );
			$operation = $service->audit_replaced_legacy_attachments( $scan_limit, true );
			if ( absint( isset( $operation['failed'] ) ? $operation['failed'] : 0 ) > 0 ) {
				return $this->pause_for_error( 'حذف یک یا چند پیوست قدیمی ناموفق بود. جزئیات مرحله ۷ را بررسی کنید.', 7, 'delete-old-failed' );
			}
			if ( ! empty( $operation['cycleComplete'] ) ) {
				update_option( self::OPTION_DELETE_OLD_APPROVED, '0', false );
				update_option( 'mobo_core_image_refresh_delete_old', '0', false );
			}
			return $this->operation_result( 7, 'delete-replaced-old', $operation, 'حذف خودکار و امن پیوست‌های قدیمی', true );
		}

		/* 8 and 9. Scan orphan raster families; delete only after approval. */
		if ( ! class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			return $this->pause_for_error( 'ماژول پاکسازی خانواده‌های فایل در دسترس نیست.', 8, 'missing-orphan-cleanup' );
		}

		$cleanup            = new Mobo_Core_Orphan_Image_Cleanup();
		$orphan_status      = $cleanup->get_status();
		$orphan_scan        = isset( $orphan_status['lastScan'] ) && is_array( $orphan_status['lastScan'] ) ? $orphan_status['lastScan'] : array();
		$orphan_delete      = isset( $orphan_status['lastDelete'] ) && is_array( $orphan_status['lastDelete'] ) ? $orphan_status['lastDelete'] : array();
		$orphan_scan_time   = absint( isset( $orphan_scan['checkedAt'] ) ? $orphan_scan['checkedAt'] : 0 );
		$orphan_delete_time = absint( isset( $orphan_delete['executedAt'] ) ? $orphan_delete['executedAt'] : 0 );
		$orphan_delete_done = $orphan_delete_time > 0
			&& $orphan_delete_time >= $orphan_scan_time
			&& absint( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ) <= 0
			&& absint( isset( $orphan_delete['failedFamilies'] ) ? $orphan_delete['failedFamilies'] : ( isset( $orphan_delete['failed'] ) ? $orphan_delete['failed'] : 0 ) ) <= 0;

		if ( empty( $orphan_scan['cycleComplete'] ) || $orphan_delete_done ) {
			$operation = $cleanup->scan( Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_scan_limit', $scan_limit, 50, 5000 ) );
			return $this->operation_result( 8, 'scan-orphan-families', $operation, 'اسکن خودکار خانواده‌های فایل بدون پیوست', empty( $operation['cycleComplete'] ) );
		}

		$orphan_candidates = absint( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 );
		if ( $orphan_candidates > 0 ) {
			if ( ! Mobo_Core_Settings::enabled( self::OPTION_DELETE_ORPHAN_APPROVED, '0' ) ) {
				update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
				return array(
					'success'           => true,
					'status'            => 'waiting-delete-orphan-approval',
					'step'              => 9,
					'waitingApproval'   => 'delete-orphan',
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => sprintf( '%d خانواده فایل بدون پیوست شرایط حذف دارد. پس از بررسی فهرست و بکاپ، یک بار حذف این مرحله را تایید کنید.', $orphan_candidates ),
				);
			}

			update_option( 'mobo_core_orphan_image_cleanup_enabled', '1', false );
			$operation = $cleanup->delete_candidates( Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_delete_per_run', 20, 1, 200 ) );
			$failed    = absint( isset( $operation['failedFamilies'] ) ? $operation['failedFamilies'] : ( isset( $operation['failed'] ) ? $operation['failed'] : 0 ) );
			if ( $failed > 0 ) {
				return $this->pause_for_error( 'حذف یک یا چند خانواده فایل ناموفق بود. جزئیات مرحله ۹ را بررسی کنید.', 9, 'delete-orphan-failed' );
			}

			$remaining = absint( isset( $operation['remainingFamilies'] ) ? $operation['remainingFamilies'] : 0 );
			if ( $remaining <= 0 ) {
				update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
				update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
			}
			return $this->operation_result( 9, 'delete-orphan-families', $operation, 'حذف خودکار و کنترل‌شده خانواده‌های بدون پیوست', true );
		}

		return $this->complete();
	}

	/**
	 * Read workflow state needed by the coordinator.
	 *
	 * @param Mobo_Core_Image_Refresh_Queue $queue Queue.
	 * @return array
	 */
	private function read_state( Mobo_Core_Image_Refresh_Queue $queue ) {
		$status        = $queue->get_status();
		$scan          = isset( $status['lastScan'] ) && is_array( $status['lastScan'] ) ? $status['lastScan'] : array();
		$enqueue       = isset( $status['lastEnqueue'] ) && is_array( $status['lastEnqueue'] ) ? $status['lastEnqueue'] : array();
		$scan_time     = absint( isset( $scan['checkedAt'] ) ? $scan['checkedAt'] : 0 );
		$enqueue_time  = absint( isset( $enqueue['checkedAt'] ) ? $enqueue['checkedAt'] : 0 );
		$scan_cycle    = ! empty( $scan['cycleId'] ) ? sanitize_text_field( (string) $scan['cycleId'] ) : '';
		$enqueue_cycle = ! empty( $enqueue['sourceScanCycleId'] ) ? sanitize_text_field( (string) $enqueue['sourceScanCycleId'] ) : '';
		$matches       = '' !== $scan_cycle ? hash_equals( $scan_cycle, $enqueue_cycle ) : $enqueue_time >= $scan_time;

		return array(
			'status'           => $status,
			'scan'             => $scan,
			'enqueue'          => $enqueue,
			'scanComplete'     => $scan_time > 0 && ! empty( $scan['cycleComplete'] ),
			'enqueueComplete'  => $enqueue_time > 0 && ! empty( $enqueue['cycleComplete'] ) && $matches,
			'pending'          => absint( isset( $status['pending'] ) ? $status['pending'] : 0 ),
			'due'              => absint( isset( $status['due'] ) ? $status['due'] : 0 ),
			'failed'           => absint( isset( $status['failed'] ) ? $status['failed'] : 0 ),
			'activeProcessing' => absint( isset( $status['activeProcessing'] ) ? $status['activeProcessing'] : 0 ),
			'waitingRetry'     => absint( isset( $status['waitingRetry'] ) ? $status['waitingRetry'] : 0 ),
			'subsizeScan'      => $this->array_option( 'mobo_core_image_refresh_last_subsize_scan' ),
			'subsizeRepair'    => $this->array_option( 'mobo_core_image_refresh_last_subsize_repair' ),
			'replacedScan'     => $this->array_option( 'mobo_core_image_refresh_last_replaced_scan' ),
			'replacedDelete'   => $this->array_option( 'mobo_core_image_refresh_last_replaced_delete' ),
		);
	}

	/**
	 * Infer the next workflow step for status-only calls.
	 *
	 * @return int
	 */
	private function detect_step() {
		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ) {
			return 0;
		}

		$state = $this->read_state( new Mobo_Core_Image_Refresh_Queue() );
		if ( empty( $state['scanComplete'] ) ) {
			return 1;
		}
		if ( empty( $state['enqueueComplete'] ) ) {
			return 2;
		}
		if ( $state['pending'] > 0 || $state['failed'] > 0 ) {
			return 3;
		}

		$scan   = $state['subsizeScan'];
		$repair = $state['subsizeRepair'];
		$scan_time   = absint( isset( $scan['checkedAt'] ) ? $scan['checkedAt'] : 0 );
		$repair_time = absint( isset( $repair['checkedAt'] ) ? $repair['checkedAt'] : 0 );
		if ( empty( $scan['cycleComplete'] ) || ( ! empty( $repair['cycleComplete'] ) && $repair_time >= $scan_time ) ) {
			return 4;
		}
		if ( absint( isset( $scan['needsRepair'] ) ? $scan['needsRepair'] : 0 ) > 0 ) {
			return 5;
		}

		$replaced_scan   = $state['replacedScan'];
		$replaced_delete = $state['replacedDelete'];
		$rs_time         = absint( isset( $replaced_scan['checkedAt'] ) ? $replaced_scan['checkedAt'] : 0 );
		$rd_time         = absint( isset( $replaced_delete['checkedAt'] ) ? $replaced_delete['checkedAt'] : 0 );
		if ( empty( $replaced_scan['cycleComplete'] ) || ( ! empty( $replaced_delete['cycleComplete'] ) && $rd_time >= $rs_time ) ) {
			return 6;
		}
		if ( absint( isset( $replaced_scan['ready'] ) ? $replaced_scan['ready'] : 0 ) > 0 ) {
			return 7;
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$status  = $cleanup->get_status();
			$scan    = isset( $status['lastScan'] ) && is_array( $status['lastScan'] ) ? $status['lastScan'] : array();
			if ( empty( $scan['cycleComplete'] ) ) {
				return 8;
			}
			if ( absint( isset( $status['candidate'] ) ? $status['candidate'] : 0 ) > 0 ) {
				return 9;
			}
		}

		return 0;
	}

	/**
	 * Standard result wrapper for one bounded operation.
	 *
	 * @param int    $step Step.
	 * @param string $status Status.
	 * @param array  $operation Operation result.
	 * @param string $label Persian label.
	 * @param bool   $remaining Whether another slice is useful now.
	 * @return array
	 */
	private function operation_result( $step, $status, $operation, $label, $remaining ) {
		$operation = is_array( $operation ) ? $operation : array();
		$complete  = ! empty( $operation['cycleComplete'] );
		$message   = $label . ( $complete ? '؛ دوره این مرحله کامل شد.' : '؛ یک batch انجام شد و ادامه دارد.' );

		return array(
			'success'           => array_key_exists( 'success', $operation ) ? (bool) $operation['success'] : true,
			'status'            => sanitize_key( (string) $status ),
			'step'              => absint( $step ),
			'needsContinuation' => true,
			'remaining'         => (bool) $remaining,
			'progressed'        => true,
			'message'           => $message,
			'operation'         => $operation,
		);
	}

	/**
	 * Stop on a condition that needs administrator attention.
	 *
	 * @param string $message Message.
	 * @param int    $step Step.
	 * @param string $status Status.
	 * @return array
	 */
	private function pause_for_error( $message, $step, $status ) {
		update_option( self::OPTION_ENABLED, '0', false );
		update_option( self::OPTION_DELETE_OLD_APPROVED, '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );

		return array(
			'success'           => false,
			'status'            => sanitize_key( (string) $status ),
			'step'              => absint( $step ),
			'needsContinuation' => false,
			'progressed'        => false,
			'message'           => sanitize_text_field( (string) $message ),
		);
	}

	/**
	 * Mark a full verified workflow complete and turn every execution switch off.
	 *
	 * @return array
	 */
	private function complete() {
		update_option( self::OPTION_ENABLED, '0', false );
		update_option( self::OPTION_DELETE_OLD_APPROVED, '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
		update_option( self::OPTION_COMPLETED_AT, time(), false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );

		return array(
			'success'           => true,
			'status'            => 'completed',
			'step'              => 0,
			'needsContinuation' => false,
			'progressed'        => true,
			'message'           => 'تمام مراحل نوسازی، کنترل برش‌ها و اسکن‌های تاییدی کامل شد. همه کلیدهای اجرایی و حذفی خاموش شدند.',
		);
	}

	/**
	 * Reset workflow records for a new verified cycle without touching media.
	 *
	 * @return void
	 */
	private function reset_for_new_cycle() {
		if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ) {
			$queue = new Mobo_Core_Image_Refresh_Queue();
			$queue->reset( false );
		}

		if ( class_exists( 'Mobo_Core_Image_Refresh_Service' ) ) {
			$service = new Mobo_Core_Image_Refresh_Service();
			$service->reset_workflow_state( false );
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$cleanup->reset( false );
		}

		update_option( self::OPTION_STARTED_AT, 0, false );
		update_option( self::OPTION_COMPLETED_AT, 0, false );
	}

	/**
	 * Check minimum image-processing requirements without depending on admin UI.
	 *
	 * @return bool
	 */
	private function image_environment_ready() {
		$uploads = wp_upload_dir();
		$writable = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );
		$wp_webp = false;
		if ( function_exists( 'wp_image_editor_supports' ) ) {
			$wp_webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		} elseif ( function_exists( 'imagewebp' ) ) {
			$wp_webp = true;
		} elseif ( class_exists( 'Imagick' ) ) {
			try {
				$wp_webp = ! empty( Imagick::queryFormats( 'WEBP' ) );
			} catch ( Throwable $e ) {
				$wp_webp = false;
			}
		}

		return version_compare( get_bloginfo( 'version' ), '5.8', '>=' )
			&& version_compare( PHP_VERSION, '7.4', '>=' )
			&& $writable
			&& (bool) $wp_webp;
	}

	/**
	 * Read an array option safely.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	private function array_option( $name ) {
		$value = get_option( $name, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Save compact automation result.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private function save_result( $result ) {
		$result = is_array( $result ) ? $result : array();
		$result['updatedAt'] = time();
		update_option( self::OPTION_LAST_RESULT, $result, false );
		return $result;
	}
}
