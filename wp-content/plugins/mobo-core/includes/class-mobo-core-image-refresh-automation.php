<?php
/**
 * Safe automation coordinator for the legacy-image refresh workflow.
 *
 * It advances one bounded workflow batch per cron/self-runner slice. Read-only
 * scans, queue creation, replacement, WebP subsize verification/repair and
 * post-replacement cleanup are fully autonomous. The administrator starts one
 * workflow and the coordinator performs prerequisite Repair, retry/backoff, safe
 * deletion and quarantine decisions without manual approval or pause gates.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Image_Refresh_Automation {

	const OPTION_ENABLED                 = 'mobo_core_image_refresh_automation_enabled';
	const OPTION_DELETE_ORPHAN_APPROVED  = 'mobo_core_image_refresh_auto_delete_orphan_approved';
	const OPTION_STARTED_AT              = 'mobo_core_image_refresh_automation_started_at';
	const OPTION_COMPLETED_AT            = 'mobo_core_image_refresh_automation_completed_at';
	const OPTION_LAST_RESULT             = 'mobo_core_image_refresh_automation_last_result';
	const OPTION_LAST_RUN_AT             = 'mobo_core_image_refresh_automation_last_run_at';
	const OPTION_LAST_TICK_STARTED_AT    = 'mobo_core_image_refresh_automation_last_tick_started_at';
	const OPTION_LAST_TICK_FINISHED_AT   = 'mobo_core_image_refresh_automation_last_tick_finished_at';
	const OPTION_LAST_TICK_SOURCE        = 'mobo_core_image_refresh_automation_last_tick_source';
	const OPTION_SUBSIZE_RETRY_COUNT     = 'mobo_core_image_refresh_subsize_retry_count';
	const OPTION_SUBSIZE_RETRY_AT        = 'mobo_core_image_refresh_subsize_retry_at';
	const OPTION_SUBSIZE_QUARANTINED     = 'mobo_core_image_refresh_subsize_quarantined';
	const OPTION_REPAIR_RETRY_COUNT      = 'mobo_core_image_refresh_repair_retry_count';
	const OPTION_REPAIR_RETRY_AT         = 'mobo_core_image_refresh_repair_retry_at';

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
			'deleteOldApproved'    => Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ),
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

		/*
		 * One click means one durable workflow generation. A double click, browser
		 * retry, stale cached form, or duplicate admin POST must not mutate retry
		 * counters/quarantine while the active generation is still running.
		 */
		if ( ! empty( $previous['enabled'] ) && 'completed' !== ( isset( $previous['status'] ) ? $previous['status'] : '' ) ) {
			return array(
				'success'           => true,
				'status'            => 'already-running',
				'step'              => absint( isset( $previous['currentStep'] ) ? $previous['currentStep'] : 0 ),
				'needsContinuation' => true,
				'progressed'        => false,
				'message'           => 'نوسازی تصاویر از قبل در حال اجرا است و همان چرخه بدون ایجاد اجرای موازی ادامه پیدا می کند.',
			);
		}

		if ( 'completed' === ( isset( $previous['status'] ) ? $previous['status'] : '' ) ) {
			$this->reset_for_new_cycle();
		}

		$retried_legacy_failures = 0;
		if ( class_exists( 'Mobo_Core_Image_Refresh_Queue' ) && Mobo_Core_Image_Refresh_Queue::table_exists() ) {
			$refresh_queue            = new Mobo_Core_Image_Refresh_Queue();
			$retried_legacy_failures = $refresh_queue->retry_failed();
		}

		/* One click owns every workflow decision from here onward. Safe deletion is
		 * still guarded by the per-attachment/per-family audits immediately before
		 * mutation; these flags merely remove administrator approval gates. */
		update_option( self::OPTION_ENABLED, '1', false );
		update_option( self::OPTION_COMPLETED_AT, 0, false );
		/* Destructive switches stay off during replacement. The coordinator arms
		 * them only when their dedicated safety-audit stage is reached. */
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );
		if ( absint( get_option( self::OPTION_STARTED_AT, 0 ) ) <= 0 ) {
			update_option( self::OPTION_STARTED_AT, time(), false );
		}

		$repair = $this->ensure_product_repair_ready();
		if ( empty( $repair['ready'] ) ) {
			return $this->save_result(
				array(
					'success'               => true,
					'status'                => isset( $repair['status'] ) ? sanitize_key( (string) $repair['status'] ) : 'waiting-product-repair',
					'step'                  => 0,
					'needsContinuation'     => ! empty( $repair['needsContinuation'] ),
					'progressed'            => ! empty( $repair['progressed'] ),
					'retriedLegacyFailures' => absint( $retried_legacy_failures ),
					'message'               => isset( $repair['message'] ) ? sanitize_text_field( (string) $repair['message'] ) : 'ترمیم پیش نیاز به صورت خودکار در حال انجام است.',
				)
			);
		}

		return $this->save_result(
			array(
				'success'               => true,
				'status'                => 'started',
				'step'                  => 1,
				'needsContinuation'     => true,
				'progressed'            => true,
				'retriedLegacyFailures' => absint( $retried_legacy_failures ),
				'message'               => $retried_legacy_failures > 0
					? sprintf( 'نوسازی خودکار شروع شد و %d مورد قرنطینه شده از چرخه قبل برای تلاش تازه آزاد شد.', absint( $retried_legacy_failures ) )
					: 'نوسازی خودکار شروع شد. از این نقطه تمام Repair، Retry، بررسی ایمنی و پاکسازی توسط سیستم انجام می شود.',
			)
		);
	}

	/**
	 * Legacy compatibility endpoint. An active one-click workflow is intentionally
	 * not pausable from wp-admin; stale forms/direct requests cannot strand it.
	 *
	 * @param string $reason Legacy reason.
	 * @param string $status Legacy status.
	 * @return array
	 */
	public function pause( $reason = '', $status = 'running' ) {
		$current = self::get_status();
		if ( empty( $current['enabled'] ) ) {
			return $this->save_result(
				array(
					'success'           => true,
					'status'            => isset( $current['status'] ) ? sanitize_key( (string) $current['status'] ) : 'idle',
					'step'              => absint( isset( $current['currentStep'] ) ? $current['currentStep'] : 0 ),
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'چرخه فعالی برای توقف وجود ندارد.',
				)
			);
		}

		return $this->save_result(
			array(
				'success'           => true,
				'status'            => 'running',
				'step'              => $this->detect_step(),
				'needsContinuation' => true,
				'progressed'        => false,
				'message'           => 'نوسازی تصاویر پس از شروع به صورت خودکار تا رسیدن به حالت پایدار ادامه پیدا می کند و نیاز به توقف یا تصمیم مدیر ندارد.',
			)
		);
	}

	/**
	 * Legacy compatibility action: persistently enable replaced-old deletion.
	 *
	 * @return array
	 */
	public function approve_delete_old() {
		$current = self::get_status();

		return array(
			'success'           => true,
			'status'            => ! empty( $current['enabled'] ) ? 'managed-automatically' : 'idle',
			'step'              => absint( isset( $current['currentStep'] ) ? $current['currentStep'] : 0 ),
			'needsContinuation' => ! empty( $current['enabled'] ),
			'progressed'        => false,
			'message'           => 'تصمیم حذف پیوست قدیمی فقط در Stage ایمن و توسط خود نوسازی خودکار انجام می شود.',
		);
	}


	/**
	 * Arm Stage 7 automatic draining when the manual workflow has already reached
	 * the safe replaced-attachment deletion stage.
	 *
	 * This does not reset cursors or earlier verification state. It only converts
	 * an already-safe manual Stage 7 into the normal bounded Cron/Self Runner
	 * automation so the administrator never has to click once per batch.
	 *
	 * @return array
	 */
	public function arm_delete_old_autodrain() {
		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_delete_old', '0' ) ) {
			return array(
				'success'           => true,
				'status'            => 'delete-old-disabled',
				'step'              => $this->detect_step(),
				'armed'             => false,
				'needsContinuation' => false,
				'progressed'        => false,
				'message'           => 'تنظیم حذف پیوست قدیمی خاموش است؛ Stage 7 خودکار فعال نشد.',
			);
		}

		if ( ! class_exists( 'Mobo_Core_Product_Sync' ) || ! Mobo_Core_Product_Sync::is_repair_completed() ) {
			return array(
				'success'           => false,
				'status'            => 'locked-until-repair',
				'step'              => 0,
				'armed'             => false,
				'needsContinuation' => false,
				'progressed'        => false,
				'message'           => 'ترمیم محصولات کامل نیست؛ Stage 7 خودکار فعال نشد.',
			);
		}

		$step = $this->detect_step();
		if ( 7 !== $step ) {
			$status = self::get_status();
			return array(
				'success'           => true,
				'status'            => ! empty( $status['enabled'] ) ? 'automation-already-active' : 'stage7-not-ready',
				'step'              => $step,
				'armed'             => false,
				'needsContinuation' => ! empty( $status['enabled'] ),
				'progressed'        => false,
				'message'           => 0 === $step
					? 'Stage 7 به نقطه پایدار رسیده است و ادامه خودکار لازم نیست.'
					: 'Workflow هنوز در Stage 7 امن قرار ندارد؛ وضعیت فعلی بدون تغییر حفظ شد.',
			);
		}

		update_option( self::OPTION_ENABLED, '1', false );
		update_option( self::OPTION_COMPLETED_AT, 0, false );
		if ( absint( get_option( self::OPTION_STARTED_AT, 0 ) ) <= 0 ) {
			update_option( self::OPTION_STARTED_AT, time(), false );
		}

		return $this->save_result(
			array(
				'success'           => true,
				'status'            => 'stage7-autodrain-armed',
				'step'              => 7,
				'armed'             => true,
				'needsContinuation' => true,
				'progressed'        => false,
				'message'           => 'Stage 7 خودکار فعال شد. Cursor فعلی حفظ می‌شود و Cron/Self Runner انتقال مرجع و حذف امن پیوست‌های قدیمی را batch به batch تا نقطه پایدار ادامه می‌دهد.',
			)
		);
	}

	/**
	 * Approve and resume automatic deletion of orphan raster families.
	 *
	 * @return array
	 */
	public function approve_delete_orphans() {
		$current = self::get_status();

		return array(
			'success'           => true,
			'status'            => ! empty( $current['enabled'] ) ? 'managed-automatically' : 'idle',
			'step'              => absint( isset( $current['currentStep'] ) ? $current['currentStep'] : 0 ),
			'needsContinuation' => ! empty( $current['enabled'] ),
			'progressed'        => false,
			'message'           => 'تصمیم پاکسازی فایل یتیم فقط پس از Safety Audit و توسط خود نوسازی خودکار انجام می شود.',
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

		/* Product Recovery owns the mutation pipeline whenever a recovery generation
		 * is pending. Image Refresh must not mutate Product/Media state ahead of it. */
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
			return $this->save_result(
				array(
					'success'           => true,
					'status'            => 'deferred-recovery',
					'step'              => $this->detect_step(),
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'بازیابی محصولات در اولویت است؛ نوسازی تصاویر پس از آزاد شدن همان pipeline خودکار ادامه پیدا می کند.',
				)
			);
		}

		$pipeline_lock = class_exists( 'Mobo_Core_Recovery_Coordinator' )
			? Mobo_Core_Recovery_Coordinator::acquire( 300 )
			: '__mobo_no_pipeline_lock__';
		if ( false === $pipeline_lock ) {
			return $this->save_result(
				array(
					'success'           => true,
					'status'            => 'pipeline-locked',
					'step'              => $this->detect_step(),
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'یک عملیات بازیابی/گرم سازی دیگر pipeline سایت را در اختیار دارد؛ نوسازی تصاویر خودکار در اجرای بعدی ادامه پیدا می کند.',
				)
			);
		}

		/* Close the schedule-vs-start race after acquiring the shared site lease. */
		if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) && Mobo_Core_Recovery_Coordinator::recovery_pending() ) {
			Mobo_Core_Recovery_Coordinator::release( $pipeline_lock );
			return $this->save_result(
				array(
					'success'           => true,
					'status'            => 'deferred-recovery',
					'step'              => $this->detect_step(),
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'بازیابی محصولات همزمان زمان بندی شد؛ نوسازی تصاویر بدون overlap به اجرای بعدی منتقل شد.',
				)
			);
		}

		$lock = Mobo_Core_Lock::acquire( 'image_refresh_automation', 180 );
		if ( false === $lock ) {
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) ) {
				Mobo_Core_Recovery_Coordinator::release( $pipeline_lock );
			}
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
		$last_tick_telemetry_at = absint( get_option( self::OPTION_LAST_RUN_AT, 0 ) );
		$persist_tick_telemetry = 0 === $last_tick_telemetry_at || ( $tick_started - $last_tick_telemetry_at ) >= 5;

		/* These options are UI telemetry, not workflow checkpoints. During a tight
		 * self-runner continuation loop, persist them at most once every five
		 * seconds instead of generating four option writes for every stage pass. */
		if ( $persist_tick_telemetry ) {
			update_option( self::OPTION_LAST_TICK_STARTED_AT, $tick_started, false );
			update_option( self::OPTION_LAST_TICK_FINISHED_AT, 0, false );
			update_option( self::OPTION_LAST_TICK_SOURCE, sanitize_key( (string) $source ), false );
			update_option( self::OPTION_LAST_RUN_AT, $tick_started, false );
		}

		try {
			$result = $this->run_locked( sanitize_key( (string) $source ) );
		} catch ( Throwable $e ) {
			$result = $this->pause_for_error( 'یک اجرای نوسازی با خطای غیرمنتظره پایان یافت؛ چرخه فعال می ماند و خودکار دوباره تلاش می کند: ' . $e->getMessage(), 0, 'automation-exception' );
			$result['exceptionClass'] = get_class( $e );
			$result['file']           = $e->getFile();
			$result['line']           = $e->getLine();
		} finally {
			$tick_finished = time();
			if ( $persist_tick_telemetry ) {
				update_option( self::OPTION_LAST_TICK_FINISHED_AT, $tick_finished, false );
			}
			Mobo_Core_Lock::release( 'image_refresh_automation', $lock );
			if ( class_exists( 'Mobo_Core_Recovery_Coordinator' ) ) {
				Mobo_Core_Recovery_Coordinator::release( $pipeline_lock );
			}
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
		$repair = $this->ensure_product_repair_ready();
		if ( empty( $repair['ready'] ) ) {
			return array(
				'success'           => true,
				'status'            => isset( $repair['status'] ) ? sanitize_key( (string) $repair['status'] ) : 'waiting-product-repair',
				'step'              => 0,
				'needsContinuation' => ! empty( $repair['needsContinuation'] ),
				'progressed'        => ! empty( $repair['progressed'] ),
				'message'           => isset( $repair['message'] ) ? sanitize_text_field( (string) $repair['message'] ) : 'ترمیم محصولات به صورت خودکار در حال انجام است.',
			);
		}

		if ( ! class_exists( 'Mobo_Core_Image_Refresh_Service' ) || ! class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ) {
			return $this->pause_for_error( 'اجزای نوسازی تصاویر موقتاً در دسترس نیستند؛ سیستم در اجرای بعدی دوباره تلاش می کند.', 0, 'missing-components' );
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

		/* 3. Replacement queue. Terminal failed rows are quarantined and never block
		 * independent images. A fresh button click resets their retry budget. */
		if ( $state['pending'] > 0 ) {
			if ( $state['activeProcessing'] > 0 ) {
				return array(
					'success'           => true,
					'status'            => 'waiting-active-processor',
					'step'              => 3,
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'یک پردازش تصویر در حال اجراست؛ سیستم پس از آزاد شدن Worker خودکار ادامه می دهد.',
				);
			}

			if ( $state['due'] <= 0 ) {
				return array(
					'success'           => true,
					'status'            => 'waiting-retry',
					'step'              => 3,
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => sprintf( '%d تصویر در backoff خودکار است و در Cron بعدی بدون دخالت مدیر دوباره تلاش می شود.', max( $state['waitingRetry'], $state['pending'] ) ),
				);
			}

			if ( ! $this->image_environment_ready( true ) ) {
				return $this->pause_for_error( 'موتور WebP، uploads یا Shared Media فعلاً آماده نیست؛ هیچ داده ای تغییر نمی کند و سیستم خودکار دوباره امتحان می کند.', 3, 'image-environment-not-ready' );
			}

			$limit     = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
			$operation = $service->process_queue( $limit );
			$after     = $queue->get_status();
			$remaining = absint( isset( $after['pending'] ) ? $after['pending'] : 0 ) > 0;
			$result    = $this->operation_result( 3, 'process-queue', $operation, 'پردازش خودکار صف نوسازی', $remaining );
			$quarantined = absint( isset( $after['failed'] ) ? $after['failed'] : 0 );
			if ( $quarantined > 0 ) {
				$result['message'] .= sprintf( ' %d مورد پس از تلاش های خودکار کافی قرنطینه شده و مانع ادامه سایر تصاویر نیست.', $quarantined );
			}
			return $result;
		}

		/* 4 and 5. WebP subsize audit/repair. Unrepairable cuts are retained and
		 * quarantined for this cycle; Stage 7 independently refuses unsafe deletion. */
		$subsize_quarantined = Mobo_Core_Settings::enabled( self::OPTION_SUBSIZE_QUARANTINED, '0' );
		$subsize_scan        = $state['subsizeScan'];
		$subsize_repair      = $state['subsizeRepair'];
		$subsize_scan_time   = absint( isset( $subsize_scan['checkedAt'] ) ? $subsize_scan['checkedAt'] : 0 );
		$subsize_repair_time = absint( isset( $subsize_repair['checkedAt'] ) ? $subsize_repair['checkedAt'] : 0 );
		$repair_complete     = $subsize_repair_time > 0 && ! empty( $subsize_repair['cycleComplete'] );
		$repair_newer        = $repair_complete && $subsize_repair_time >= $subsize_scan_time;

		if ( ! $subsize_quarantined && ( empty( $subsize_scan['cycleComplete'] ) || $repair_newer ) ) {
			$operation = $service->audit_webp_subsizes( $scan_limit, false );
			return $this->operation_result( 4, 'scan-webp-subsizes', $operation, 'اسکن خودکار سلامت برش های WebP', empty( $operation['cycleComplete'] ) );
		}

		if ( ! $subsize_quarantined ) {
			$hard_errors = absint( isset( $subsize_scan['unsupportedEditor'] ) ? $subsize_scan['unsupportedEditor'] : 0 )
				+ absint( isset( $subsize_scan['missingOriginal'] ) ? $subsize_scan['missingOriginal'] : 0 );
			if ( $hard_errors > 0 ) {
				update_option( self::OPTION_SUBSIZE_QUARANTINED, '1', false );
				delete_option( self::OPTION_SUBSIZE_RETRY_AT );
				return array(
					'success'           => true,
					'status'            => 'subsize-quarantined',
					'step'              => 5,
					'needsContinuation' => true,
					'progressed'        => true,
					'message'           => sprintf( '%d مورد برش به دلیل فایل اصلی مفقود یا موتور نامناسب برای این چرخه قرنطینه شد. فایل قدیمی/ناامن حذف نمی شود و بقیه نوسازی ادامه دارد.', $hard_errors ),
				);
			}

			if ( absint( isset( $subsize_scan['needsRepair'] ) ? $subsize_scan['needsRepair'] : 0 ) > 0 ) {
				$retry_at = absint( get_option( self::OPTION_SUBSIZE_RETRY_AT, 0 ) );
				if ( $retry_at > time() ) {
					return array(
						'success'           => true,
						'status'            => 'waiting-subsize-retry',
						'step'              => 5,
						'needsContinuation' => false,
						'progressed'        => false,
						'message'           => 'بازسازی بعضی برش ها در backoff خودکار است و در زمان مناسب دوباره انجام می شود.',
					);
				}

				$local_needs_repair = absint( isset( $subsize_scan['localNeedsRepair'] ) ? $subsize_scan['localNeedsRepair'] : 0 );
				if ( $local_needs_repair > 0 && ! $this->image_environment_ready( false ) ) {
					return $this->pause_for_error( 'موتور WebP یا uploads فعلاً برای بازسازی برش های محلی آماده نیست؛ سیستم خودکار retry می کند.', 5, 'image-environment-not-ready' );
				}

				$operation = $service->audit_webp_subsizes( $scan_limit, true );
				if ( ! empty( $operation['cycleComplete'] ) && absint( isset( $operation['failed'] ) ? $operation['failed'] : 0 ) > 0 ) {
					$attempt = absint( get_option( self::OPTION_SUBSIZE_RETRY_COUNT, 0 ) ) + 1;
					update_option( self::OPTION_SUBSIZE_RETRY_COUNT, $attempt, false );
					if ( $attempt >= 3 ) {
						update_option( self::OPTION_SUBSIZE_QUARANTINED, '1', false );
						delete_option( self::OPTION_SUBSIZE_RETRY_AT );
						return array(
							'success'           => true,
							'status'            => 'subsize-quarantined',
							'step'              => 5,
							'needsContinuation' => true,
							'progressed'        => true,
							'message'           => 'بعضی برش ها پس از سه چرخه تعمیر کامل نشدند و برای این چرخه قرنطینه شدند. موارد ناامن حذف نمی شوند و ادامه کار متوقف نمی شود.',
						);
					}
					$delay = Mobo_Core_Settings::get_int( 'mobo_core_image_long_retry_seconds', 21600, 3600, 604800 );
					update_option( self::OPTION_SUBSIZE_RETRY_AT, time() + $delay, false );
					return array(
						'success'           => true,
						'status'            => 'waiting-subsize-retry',
						'step'              => 5,
						'needsContinuation' => false,
						'progressed'        => true,
						'message'           => sprintf( 'بازسازی برخی برش ها کامل نشد؛ تلاش خودکار %d از 3 ثبت شد و بدون دخالت مدیر دوباره امتحان می شود.', $attempt ),
					);
				}
				delete_option( self::OPTION_SUBSIZE_RETRY_AT );
				return $this->operation_result( 5, 'repair-webp-subsizes', $operation, 'بازسازی خودکار برش های ناقص WebP', true );
			}
		}

		/* 6 and 7. Safe replaced-attachment cleanup is always autonomous. */
		$replaced_scan        = $state['replacedScan'];
		$replaced_delete      = $state['replacedDelete'];
		$replaced_scan_time   = absint( isset( $replaced_scan['checkedAt'] ) ? $replaced_scan['checkedAt'] : 0 );
		$replaced_delete_time = absint( isset( $replaced_delete['checkedAt'] ) ? $replaced_delete['checkedAt'] : 0 );
		$delete_complete      = $replaced_delete_time > 0 && ! empty( $replaced_delete['cycleComplete'] );
		$delete_newer         = $delete_complete && $replaced_delete_time >= $replaced_scan_time;
		$delete_pass_progress = absint( isset( $replaced_delete['passProgress'] )
			? $replaced_delete['passProgress']
			: absint( isset( $replaced_delete['deleted'] ) ? $replaced_delete['deleted'] : 0 )
				+ absint( isset( $replaced_delete['referenceRowsUpdated'] ) ? $replaced_delete['referenceRowsUpdated'] : 0 ) );
		$delete_needs_followup = $delete_newer && $delete_pass_progress > 0;
		$delete_stable         = $delete_newer && 0 === $delete_pass_progress;

		if ( empty( $replaced_scan['cycleComplete'] ) ) {
			$operation = $service->audit_replaced_legacy_attachments( $scan_limit, false );
			return $this->operation_result( 6, 'scan-replaced-old', $operation, 'اسکن خودکار پیوست های قدیمی جایگزین شده', empty( $operation['cycleComplete'] ) );
		}

		$replaced_ready        = absint( isset( $replaced_scan['ready'] ) ? $replaced_scan['ready'] : 0 );
		$migration_candidates  = absint( isset( $replaced_scan['migrationCandidates'] ) ? $replaced_scan['migrationCandidates'] : 0 );
		$replaced_actionable   = $replaced_ready + $migration_candidates;
		if ( $replaced_actionable > 0 && ( ! $delete_newer || $delete_needs_followup ) ) {
			update_option( 'mobo_core_image_refresh_delete_old', '1', false );
			$operation = $service->audit_replaced_legacy_attachments( $scan_limit, true );
			$result    = $this->operation_result( 7, 'delete-replaced-old', $operation, 'انتقال مراجع و حذف خودکار و امن پیوست های قدیمی', true );
			if ( ! empty( $operation['cycleComplete'] ) && ! empty( $operation['needsAnotherPass'] ) ) {
				$result['message'] = sprintf(
					'یک گذر پاکسازی کامل شد: %d پیوست حذف و %d ردیف مرجع منتقل شد؛ گذر بعدی خودکار ادامه پیدا می کند.',
					absint( isset( $operation['deleted'] ) ? $operation['deleted'] : 0 ),
					absint( isset( $operation['referenceRowsUpdated'] ) ? $operation['referenceRowsUpdated'] : 0 )
				);
			}
			return $result;
		}

		if ( $delete_stable ) {
			/* Stage 7 converged. Keep the global destructive switch scoped to this
			 * stage so normal image replacement outside this workflow is unaffected. */
			update_option( 'mobo_core_image_refresh_delete_old', '0', false );
			if ( $replaced_actionable > 0 ) {
				/* Remaining rows are safety-blocked and intentionally retained. */
			}
		}

		/* 8 and 9. Orphan families: candidate classification + immediate revalidation
		 * are the authorization; there is no administrator approval gate. */
		if ( ! class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			return $this->pause_for_error( 'ماژول پاکسازی فایل ها فعلاً در دسترس نیست؛ اجرای بعدی دوباره تلاش می کند.', 8, 'missing-orphan-cleanup' );
		}

		$cleanup            = new Mobo_Core_Orphan_Image_Cleanup();
		$orphan_status      = $cleanup->get_status();
		$orphan_scan        = isset( $orphan_status['lastScan'] ) && is_array( $orphan_status['lastScan'] ) ? $orphan_status['lastScan'] : array();
		$orphan_delete      = isset( $orphan_status['lastDelete'] ) && is_array( $orphan_status['lastDelete'] ) ? $orphan_status['lastDelete'] : array();
		$orphan_scan_time   = absint( isset( $orphan_scan['checkedAt'] ) ? $orphan_scan['checkedAt'] : 0 );
		$orphan_delete_time = absint( isset( $orphan_delete['executedAt'] ) ? $orphan_delete['executedAt'] : 0 );
		$orphan_delete_done = $orphan_delete_time > 0
			&& $orphan_delete_time >= $orphan_scan_time
			&& absint( isset( $orphan_status['actionable'] ) ? $orphan_status['actionable'] : ( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ) ) <= 0;

		if ( empty( $orphan_scan['cycleComplete'] ) || $orphan_delete_done ) {
			$operation = $cleanup->scan( Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_scan_limit', $scan_limit, 50, 5000 ) );
			return $this->operation_result( 8, 'scan-orphan-families', $operation, 'اسکن خودکار خانواده های فایل بدون پیوست', empty( $operation['cycleComplete'] ) );
		}

		$orphan_candidates = absint( isset( $orphan_status['actionable'] ) ? $orphan_status['actionable'] : ( isset( $orphan_status['candidate'] ) ? $orphan_status['candidate'] : 0 ) );
		if ( $orphan_candidates > 0 ) {
			update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '1', false );
			update_option( 'mobo_core_orphan_image_cleanup_enabled', '1', false );
			$operation = $cleanup->delete_candidates( Mobo_Core_Settings::get_int( 'mobo_core_orphan_image_delete_per_run', 20, 1, 200 ) );
			$remaining = absint( isset( $operation['remainingFamilies'] ) ? $operation['remainingFamilies'] : 0 );
			return $this->operation_result( 9, 'delete-orphan-families', $operation, 'حذف خودکار و کنترل شده خانواده های بدون پیوست', $remaining > 0 );
		}

		return $this->complete();
	}

	/**
	 * Ensure the Product Repair prerequisite without asking the administrator.
	 * Existing sync work is never cancelled; the coordinator waits and starts its
	 * own Repair as soon as the sync lane becomes free.
	 *
	 * @return array
	 */
	private function ensure_product_repair_ready() {
		if ( ! class_exists( 'Mobo_Core_Product_Sync' ) ) {
			return array(
				'ready'             => false,
				'status'            => 'waiting-product-repair',
				'needsContinuation' => false,
				'progressed'        => false,
				'message'           => 'ماژول ترمیم محصولات فعلاً در دسترس نیست؛ سیستم در اجرای بعدی دوباره بررسی می کند.',
			);
		}

		if ( Mobo_Core_Product_Sync::is_repair_completed() ) {
			delete_option( self::OPTION_REPAIR_RETRY_COUNT );
			delete_option( self::OPTION_REPAIR_RETRY_AT );
			return array( 'ready' => true, 'status' => 'repair-ready', 'needsContinuation' => true, 'progressed' => false );
		}

		$product_sync = new Mobo_Core_Product_Sync();
		$status       = $product_sync->get_manual_sync_status();
		$is_active    = ! empty( $status['isRunning'] ) || ! empty( $status['isWaitingForPortal'] );
		$last_error   = isset( $status['lastError'] ) ? sanitize_text_field( (string) $status['lastError'] ) : '';

		/* A generation with lastError is not resumable by the normal Self Runner. Do
		 * not leave the one-click workflow stranded there: after bounded backoff,
		 * retire that failed generation and start a fresh idempotent Repair. */
		if ( $is_active && '' !== $last_error && empty( $status['shouldContinue'] ) ) {
			$retry_at = absint( get_option( self::OPTION_REPAIR_RETRY_AT, 0 ) );
			if ( $retry_at <= 0 ) {
				$attempt = absint( get_option( self::OPTION_REPAIR_RETRY_COUNT, 0 ) ) + 1;
				update_option( self::OPTION_REPAIR_RETRY_COUNT, $attempt, false );
				$delay = min( 1800, max( 300, $attempt * 300 ) );
				update_option( self::OPTION_REPAIR_RETRY_AT, time() + $delay, false );
				return array(
					'ready'             => false,
					'status'            => 'waiting-product-repair-retry',
					'needsContinuation' => false,
					'progressed'        => true,
					'message'           => sprintf( 'Repair محصولات با خطا پایان یافت؛ Retry خودکار شماره %d زمان بندی شد و نیازی به Resume/Cancel دستی نیست.', $attempt ),
				);
			}

			if ( $retry_at > time() ) {
				return array(
					'ready'             => false,
					'status'            => 'waiting-product-repair-retry',
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'Repair ناموفق در backoff خودکار است؛ در زمان تعیین شده بدون دخالت مدیر از ابتدا و با state تازه اجرا می شود.',
				);
			}

			$cancelled = $product_sync->cancel_manual_sync();
			if ( ! is_array( $cancelled ) || empty( $cancelled['success'] ) ) {
				return array(
					'ready'             => false,
					'status'            => 'waiting-product-repair-retry',
					'needsContinuation' => false,
					'progressed'        => false,
					'message'           => 'نسل ناموفق Repair هنوز قابل جمع کردن نیست؛ سیستم در Cron بعدی دوباره تلاش می کند.',
				);
			}
			delete_option( self::OPTION_REPAIR_RETRY_AT );
			$status    = $product_sync->get_manual_sync_status();
			$is_active = false;
		}

		if ( $is_active ) {
			$is_repair = ! empty( $status['repairMode'] );
			return array(
				'ready'             => false,
				'status'            => $is_repair ? 'waiting-product-repair' : 'waiting-current-product-sync',
				'needsContinuation' => ! empty( $status['shouldContinue'] ),
				'progressed'        => false,
				'message'           => $is_repair
					? 'ترمیم محصولات که برای نوسازی لازم است در حال اجراست و پس از تکمیل، تصاویر خودکار ادامه پیدا می کنند.'
					: 'یک Sync محصول دیگر در حال اجراست؛ سیستم آن را قطع نمی کند و بلافاصله پس از آزاد شدن مسیر، Repair لازم را خودش شروع می کند.',
			);
		}

		$started = $product_sync->start_manual_sync( '', 'image-refresh-auto-repair', true );
		$success = is_array( $started ) && ! empty( $started['success'] );
		return array(
			'ready'             => false,
			'status'            => $success ? 'waiting-product-repair' : 'waiting-product-repair-retry',
			'needsContinuation' => $success,
			'progressed'        => $success,
			'message'           => $success
				? 'Repair کامل محصولات به عنوان پیش نیاز نوسازی، خودکار شروع شد و نیازی به اقدام مدیر نیست.'
				: 'Repair هنوز قابل شروع نیست؛ سیستم بدون لغو چرخه در اجرای بعدی دوباره تلاش می کند.',
		);
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
		if ( $state['pending'] > 0 ) {
			return 3;
		}

		if ( Mobo_Core_Settings::enabled( self::OPTION_SUBSIZE_QUARANTINED, '0' ) ) {
			$scan   = array( 'cycleComplete' => true, 'needsRepair' => 0 );
			$repair = array();
		} else {
			$scan   = $state['subsizeScan'];
			$repair = $state['subsizeRepair'];
		}
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
		$delete_newer    = ! empty( $replaced_delete['cycleComplete'] ) && $rd_time >= $rs_time;
		$delete_progress = absint( isset( $replaced_delete['passProgress'] )
			? $replaced_delete['passProgress']
			: absint( isset( $replaced_delete['deleted'] ) ? $replaced_delete['deleted'] : 0 )
				+ absint( isset( $replaced_delete['referenceRowsUpdated'] ) ? $replaced_delete['referenceRowsUpdated'] : 0 ) );
		if ( empty( $replaced_scan['cycleComplete'] ) ) {
			return 6;
		}
		if ( absint( isset( $replaced_scan['ready'] ) ? $replaced_scan['ready'] : 0 ) + absint( isset( $replaced_scan['migrationCandidates'] ) ? $replaced_scan['migrationCandidates'] : 0 ) > 0
			&& ( ! $delete_newer || $delete_progress > 0 ) ) {
			return 7;
		}

		if ( class_exists( 'Mobo_Core_Orphan_Image_Cleanup' ) ) {
			$cleanup = new Mobo_Core_Orphan_Image_Cleanup();
			$status  = $cleanup->get_status();
			$scan    = isset( $status['lastScan'] ) && is_array( $status['lastScan'] ) ? $status['lastScan'] : array();
			if ( empty( $scan['cycleComplete'] ) ) {
				return 8;
			}
			if ( absint( isset( $status['actionable'] ) ? $status['actionable'] : ( isset( $status['candidate'] ) ? $status['candidate'] : 0 ) ) > 0 ) {
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
	 * Fail closed for the current stage and retry automatically without administrator action.
	 *
	 * @param string $message Message.
	 * @param int    $step Step.
	 * @param string $status Status.
	 * @return array
	 */
	private function pause_for_error( $message, $step, $status ) {
		/* Fail closed for the current mutation, but never strand the global workflow.
		 * Real Cron/Self Runner will retry the stage later. */
		update_option( self::OPTION_ENABLED, '1', false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );

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
	 * Mark a full verified workflow complete and return all destructive switches to fail-closed defaults.
	 *
	 * @return array
	 */
	private function complete() {
		update_option( self::OPTION_ENABLED, '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
		update_option( self::OPTION_COMPLETED_AT, time(), false );
		update_option( 'mobo_core_image_refresh_enabled', '0', false );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( 'mobo_core_orphan_image_cleanup_enabled', '0', false );

		$stage7  = get_option( 'mobo_core_image_refresh_last_replaced_delete', array() );
		$stage7  = is_array( $stage7 ) ? $stage7 : array();
		$blocked = absint( isset( $stage7['blocked'] ) ? $stage7['blocked'] : 0 );
		$errors  = absint( isset( $stage7['errors'] ) ? $stage7['errors'] : 0 );
		$queue_status = class_exists( 'Mobo_Core_Image_Refresh_Queue' ) ? ( new Mobo_Core_Image_Refresh_Queue() )->get_status() : array();
		$quarantined_queue = absint( isset( $queue_status['failed'] ) ? $queue_status['failed'] : 0 );
		$subsize_quarantined = Mobo_Core_Settings::enabled( self::OPTION_SUBSIZE_QUARANTINED, '0' );
		$message = 'نوسازی تصاویر به نقطه پایدار رسید و تمام تصمیم های قابل انجام خودکار انجام شد. نیازی به اقدام دیگری از مدیر سایت نیست.';
		if ( $quarantined_queue > 0 || $subsize_quarantined ) {
			$message .= sprintf( ' %d تصویر در صف پس از Retryهای کافی قرنطینه شد%s؛ موارد ناامن حفظ شده اند و حذف نشده اند.', $quarantined_queue, $subsize_quarantined ? ' و برخی برش های WebP نیز برای این چرخه قابل تعمیر نبودند' : '' );
		}
		if ( $blocked > 0 || $errors > 0 ) {
			$message .= sprintf( ' در آخرین گذر Stage 7، %d پیوست به دلیل Safety Audit نگه داشته شد و %d خطای عملیاتی ثبت شد؛ این موارد عمدا بدون حذف باقی مانده اند.', $blocked, $errors );
		}

		return array(
			'success'           => true,
			'status'            => 'completed',
			'step'              => 0,
			'needsContinuation' => false,
			'progressed'        => true,
			'message'           => $message,
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
		delete_option( self::OPTION_SUBSIZE_RETRY_COUNT );
		delete_option( self::OPTION_SUBSIZE_RETRY_AT );
		delete_option( self::OPTION_SUBSIZE_QUARANTINED );
		delete_option( self::OPTION_REPAIR_RETRY_COUNT );
		delete_option( self::OPTION_REPAIR_RETRY_AT );
		update_option( 'mobo_core_image_refresh_delete_old', '0', false );
		update_option( self::OPTION_DELETE_ORPHAN_APPROVED, '0', false );
	}

	/**
	 * Check minimum image-processing requirements without depending on admin UI.
	 *
	 * @return bool
	 */
	private function image_environment_ready( $allow_shared_worker = false ) {
		$shared_configured = $allow_shared_worker
			&& class_exists( 'Mobo_Core_Shared_Media' )
			&& ( method_exists( 'Mobo_Core_Shared_Media', 'is_configured' ) ? Mobo_Core_Shared_Media::is_configured() : Mobo_Core_Shared_Media::is_enabled() );
		if ( $shared_configured && ! Mobo_Core_Shared_Media::allow_download_fallback() ) {
			/* The central media worker owns WebP generation; WordPress only reads committed files.
			 * A transient mount outage is not permission to fall back to private uploads. */
			return Mobo_Core_Shared_Media::is_enabled()
				&& version_compare( get_bloginfo( 'version' ), '5.8', '>=' )
				&& version_compare( PHP_VERSION, '7.4', '>=' );
		}

		$uploads = wp_upload_dir();
		$writable = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] );
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
