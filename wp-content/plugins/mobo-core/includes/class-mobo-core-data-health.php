<?php
/**
 * Data health ("سلامت داده"): resumable, deadline-aware scanner for corrupted
 * customer data plus guarded repair jobs.
 *
 * Shape (same pattern as the legacy image audit and the Sync/Repair workers):
 * - One state option; every step commits cursor + counters, so a killed request
 *   (30 s limit, OOM, deploy) resumes from the last committed step.
 * - A slice runs under a runtime lock, stops before the request deadline
 *   (Mobo_Core_Request_Deadline) and when PHP memory headroom gets low.
 * - Only Mobo_Core_Environment wrappers are used for functions that cheap hosts
 *   disable.
 * - Driven by: the admin tab (AJAX steps while the page is open), an hourly
 *   WP-Cron tick (+ a one-minute continuation event while work is pending).
 *
 * Phases of a scan: purge (old findings) -> detectors (in registry order) ->
 * complete -> optional automatic class-(a) repair job.
 *
 * Repair jobs (one at a time, never while a scan, Sync/Repair, upgrade barrier
 * or Unicode migration runs): 'apply' processes stored findings of chosen
 * detectors with backup; 'undo' walks one batch backwards with CAS.
 *
 * The health payload sent to the Backend contains counts only (no IDs, titles,
 * GUIDs or customer data).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Data_Health {

	const STATE_VERSION   = 1;
	const STATE_OPTION    = 'mobo_core_data_health_state';
	const JOB_OPTION      = 'mobo_core_data_health_job';
	const BATCHES_OPTION  = 'mobo_core_data_health_batches';
	const STOP_OPTION     = 'mobo_core_data_health_stop';
	const AUTO_OPTION     = 'mobo_core_data_health_auto_repair';
	const DAILY_OPTION    = 'mobo_core_data_health_daily_scan';
	const LOCK_NAME       = 'data_health';
	const CRON_HOOK       = 'mobo_core_data_health_tick';
	const SAMPLE_LIMIT    = 10;
	const MAX_BATCHES     = 30;
	const DEADLINE_RESERVE = 2.0;
	const MIN_HEADROOM    = 25165824; // 24 MB.
	const MAX_STEP_ERRORS = 3;
	const JOB_CHUNK       = 25;
	const RESCAN_SECONDS  = 86400;

	/* ------------------------------------------------------------------ */
	/* State                                                              */
	/* ------------------------------------------------------------------ */

	/** @return array */
	public static function empty_state() {
		return array(
			'version'          => self::STATE_VERSION,
			'status'           => 'idle',
			'phase'            => '',
			'scanId'           => '',
			'trigger'          => '',
			'startedAt'        => 0,
			'finishedAt'       => 0,
			'updatedAt'        => 0,
			'index'            => 0,
			'cursor'           => 0,
			'detectors'        => array(),
			'lastError'        => '',
			'slices'           => 0,
			'steps'            => 0,
			'lastSliceSeconds' => 0,
			'maxSliceSeconds'  => 0,
			'peakMemory'       => 0,
			'memoryStops'      => 0,
			'lastCompleteAt'   => 0,
			'lastCompleteScan' => '',
			'storage'          => false,
		);
	}

	/** @return array */
	public static function get_state() {
		$raw   = get_option( self::STATE_OPTION, array() );
		$state = array_merge( self::empty_state(), is_array( $raw ) ? $raw : array() );
		if ( ! is_array( $state['detectors'] ) ) {
			$state['detectors'] = array();
		}
		return $state;
	}

	/** @return void */
	private static function save_state( array $state ) {
		$state['updatedAt'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/** @return array */
	public static function empty_job() {
		return array(
			'type'       => '',
			'status'     => 'idle',
			'batchId'    => '',
			'scanId'     => '',
			'detectors'  => array(),
			'classes'    => array(),
			'index'      => 0,
			'cursor'     => 0,
			'processed'  => 0,
			'applied'    => 0,
			'undone'     => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'conflicts'  => 0,
			'startedAt'  => 0,
			'updatedAt'  => 0,
			'finishedAt' => 0,
			'lastError'  => '',
			'actor'      => '',
		);
	}

	/** @return array */
	public static function get_job() {
		$raw = get_option( self::JOB_OPTION, array() );
		return array_merge( self::empty_job(), is_array( $raw ) ? $raw : array() );
	}

	/** @return void */
	private static function save_job( array $job ) {
		$job['updatedAt'] = time();
		update_option( self::JOB_OPTION, $job, false );
	}

	/** @return array */
	public static function get_batches() {
		$raw = get_option( self::BATCHES_OPTION, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/** @return void */
	private static function upsert_batch( array $batch ) {
		$batches = self::get_batches();
		$found   = false;
		foreach ( $batches as $i => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $batch['id'] ) {
				$batches[ $i ] = array_merge( $existing, $batch );
				$found         = true;
			}
		}
		if ( ! $found ) {
			array_unshift( $batches, $batch );
		}
		update_option( self::BATCHES_OPTION, array_slice( $batches, 0, self::MAX_BATCHES ), false );
	}

	/** @return bool */
	public static function auto_repair_enabled() {
		return '0' !== (string) get_option( self::AUTO_OPTION, '1' );
	}

	/** @return bool */
	public static function daily_scan_enabled() {
		return '0' !== (string) get_option( self::DAILY_OPTION, '1' );
	}

	/** @return string */
	private static function new_id( $prefix ) {
		$rand = class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::random_hex( 6 ) : substr( md5( uniqid( '', true ) ), 0, 12 );
		return $prefix . gmdate( 'ymdHis' ) . '-' . $rand;
	}

	/* ------------------------------------------------------------------ */
	/* Commands                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Start a new scan (or keep the running one).
	 *
	 * @param string $trigger admin|cron|cli.
	 * @return array|WP_Error State.
	 */
	public static function start_scan( $trigger = 'admin' ) {
		$job = self::get_job();
		if ( 'running' === $job['status'] ) {
			return new WP_Error( 'mobo_core_data_health_job_running', 'یک عملیات ترمیم در حال اجراست؛ پس از پایان آن دوباره بررسی کنید.' );
		}
		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			return $state;
		}
		delete_option( self::STOP_OPTION );
		$fresh                     = self::empty_state();
		$fresh['status']           = 'running';
		$fresh['phase']            = 'purge';
		$fresh['scanId']           = self::new_id( 's' );
		$fresh['trigger']          = sanitize_key( $trigger );
		$fresh['startedAt']        = time();
		$fresh['lastCompleteAt']   = $state['lastCompleteAt'];
		$fresh['lastCompleteScan'] = $state['lastCompleteScan'];
		$fresh['storage']          = Mobo_Core_Data_Health_Store::ensure();
		foreach ( Mobo_Core_Data_Health_Detectors::definitions() as $id => $def ) {
			$fresh['detectors'][ $id ] = self::empty_detector_state( $def );
		}
		self::save_state( $fresh );
		self::schedule_continue();
		return $fresh;
	}

	/** @return array */
	private static function empty_detector_state( array $def ) {
		return array(
			'class'   => $def['class'],
			'count'   => 0,
			'groups'  => 0,
			'covered' => 0,
			'stored'  => 0,
			'byClass' => array( 'a' => 0, 'b' => 0, 'c' => 0 ),
			'done'    => false,
			'error'   => '',
			'errors'  => 0,
			'note'    => '',
			'samples' => array(),
		);
	}

	/** Cooperative stop of the scan and of a running job. @return void */
	public static function request_stop() {
		update_option( self::STOP_OPTION, '1', false );
		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'stopped';
			self::save_state( $state );
		}
		$job = self::get_job();
		if ( 'running' === $job['status'] ) {
			$job['status'] = 'stopped';
			self::save_job( $job );
		}
	}

	/** Continue a stopped scan/job from its cursor. @return void */
	public static function resume() {
		delete_option( self::STOP_OPTION );
		$state = self::get_state();
		if ( 'stopped' === $state['status'] || 'failed' === $state['status'] ) {
			$state['status']    = 'running';
			$state['lastError'] = '';
			self::save_state( $state );
		}
		$job = self::get_job();
		if ( in_array( $job['status'], array( 'stopped', 'blocked', 'failed' ), true ) ) {
			$job['status']    = 'running';
			$job['lastError'] = '';
			self::save_job( $job );
		}
		self::schedule_continue();
	}

	/**
	 * Why repairs may not run right now ('' = allowed).
	 *
	 * @return string
	 */
	public static function repairs_blocked_reason() {
		if ( ! Mobo_Core_Data_Health_Store::is_ready() && ! Mobo_Core_Data_Health_Store::ensure() ) {
			return 'جدول پشتیبان ساخته نشد؛ بدون پشتیبان هیچ ترمیمی انجام نمی‌شود.';
		}
		if ( class_exists( 'Mobo_Core_Lock' ) && Mobo_Core_Lock::is_locked( 'manual_sync' ) ) {
			return 'همگام‌سازی/Repair در حال اجراست؛ ترمیم بعد از پایان آن ممکن است.';
		}
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && method_exists( 'Mobo_Core_Upgrade_Coordinator', 'is_active' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			return 'ارتقای افزونه در حال انجام است.';
		}
		if ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) && method_exists( 'Mobo_Core_Database_Unicode_Migration', 'should_block_runtime_lock' ) && Mobo_Core_Database_Unicode_Migration::should_block_runtime_lock( self::LOCK_NAME ) ) {
			return 'تبدیل utf8mb4 پایگاه داده در جریان است.';
		}
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'mobo_core_data_health_repairs_blocked', '' ) : '';
		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Create an apply job for confirmed detectors of the last complete scan.
	 *
	 * @param string[] $detectors Detector ids.
	 * @param string[] $classes Repair classes allowed ('a', 'b').
	 * @param string   $actor Login (admin) or 'auto'.
	 * @return array|WP_Error Job.
	 */
	public static function start_apply( array $detectors, array $classes, $actor ) {
		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			return new WP_Error( 'mobo_core_data_health_scan_running', 'بررسی هنوز تمام نشده است.' );
		}
		if ( '' === (string) $state['lastCompleteScan'] ) {
			return new WP_Error( 'mobo_core_data_health_no_scan', 'ابتدا یک بررسی کامل اجرا کنید.' );
		}
		$job = self::get_job();
		if ( 'running' === $job['status'] ) {
			return new WP_Error( 'mobo_core_data_health_job_running', 'یک عملیات ترمیم دیگر در حال اجراست.' );
		}
		$blocked = self::repairs_blocked_reason();
		if ( '' !== $blocked ) {
			return new WP_Error( 'mobo_core_data_health_blocked', $blocked );
		}
		$defs  = Mobo_Core_Data_Health_Detectors::definitions();
		$valid = array();
		foreach ( $detectors as $id ) {
			$id = sanitize_key( (string) $id );
			if ( isset( $defs[ $id ] ) && 'c' !== $defs[ $id ]['class'] && '' !== $defs[ $id ]['action'] ) {
				$valid[] = $id;
			}
		}
		$classes = array_values( array_intersect( array( 'a', 'b' ), array_map( 'strval', $classes ) ) );
		if ( empty( $valid ) || empty( $classes ) ) {
			return new WP_Error( 'mobo_core_data_health_nothing', 'موردی برای ترمیم انتخاب نشده است.' );
		}
		$job              = self::empty_job();
		$job['type']      = 'apply';
		$job['status']    = 'running';
		$job['batchId']   = self::new_id( 'b' );
		$job['scanId']    = (string) $state['lastCompleteScan'];
		$job['detectors'] = $valid;
		$job['classes']   = $classes;
		$job['startedAt'] = time();
		$job['actor']     = sanitize_text_field( (string) $actor );
		self::save_job( $job );
		self::upsert_batch(
			array(
				'id'        => $job['batchId'],
				'type'      => 'auto' === $actor ? 'auto' : 'admin',
				'detectors' => $valid,
				'actor'     => $job['actor'],
				'createdAt' => time(),
				'status'    => 'running',
				'applied'   => 0,
				'undone'    => 0,
			)
		);
		self::schedule_continue();
		return $job;
	}

	/**
	 * Create an undo job for one batch.
	 *
	 * @param string $batch_id Batch.
	 * @param string $actor Login.
	 * @return array|WP_Error
	 */
	public static function start_undo( $batch_id, $actor ) {
		$batch_id = sanitize_text_field( (string) $batch_id );
		$job      = self::get_job();
		if ( 'running' === $job['status'] ) {
			return new WP_Error( 'mobo_core_data_health_job_running', 'یک عملیات ترمیم دیگر در حال اجراست.' );
		}
		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			return new WP_Error( 'mobo_core_data_health_scan_running', 'بررسی در حال اجراست؛ بعد از پایان آن بازگردانی کنید.' );
		}
		$known = false;
		foreach ( self::get_batches() as $batch ) {
			if ( isset( $batch['id'] ) && $batch['id'] === $batch_id ) {
				$known = true;
			}
		}
		if ( ! $known ) {
			return new WP_Error( 'mobo_core_data_health_unknown_batch', 'این دسته ترمیم پیدا نشد.' );
		}
		$blocked = self::repairs_blocked_reason();
		if ( '' !== $blocked ) {
			return new WP_Error( 'mobo_core_data_health_blocked', $blocked );
		}
		$job              = self::empty_job();
		$job['type']      = 'undo';
		$job['status']    = 'running';
		$job['batchId']   = $batch_id;
		$job['startedAt'] = time();
		$job['actor']     = sanitize_text_field( (string) $actor );
		self::save_job( $job );
		self::upsert_batch( array( 'id' => $batch_id, 'status' => 'undoing' ) );
		self::schedule_continue();
		return $job;
	}

	/* ------------------------------------------------------------------ */
	/* Slice runner                                                       */
	/* ------------------------------------------------------------------ */

	/** @return bool Fresh read of the Stop flag. */
	private static function stop_requested() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::STOP_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return '1' === (string) get_option( self::STOP_OPTION, '0' );
	}

	/**
	 * Stop before the next step/item? Uses the shared runtime guard (release the
	 * request caches at 60 % of memory_limit, stop at 75 %) plus an absolute
	 * headroom floor for small limits. Unknown usage/limit never stops work.
	 *
	 * @return bool
	 */
	private static function memory_low() {
		if ( class_exists( 'Mobo_Core_Request_Deadline' ) && method_exists( 'Mobo_Core_Request_Deadline', 'memory_guard' ) && Mobo_Core_Request_Deadline::memory_guard( 0.6, 0.75 ) ) {
			return true;
		}
		return class_exists( 'Mobo_Core_Environment' ) && Mobo_Core_Environment::memory_headroom_bytes() < self::MIN_HEADROOM;
	}

	/**
	 * Per-item stop check inside repair chunks: own slice deadline, the request
	 * deadline (shared helper) and memory.
	 *
	 * @param float $deadline Absolute microtime of this slice.
	 * @return bool
	 */
	private static function must_yield( $deadline ) {
		if ( microtime( true ) >= $deadline ) {
			return true;
		}
		if ( class_exists( 'Mobo_Core_Request_Deadline' ) && ! Mobo_Core_Request_Deadline::has_time( self::DEADLINE_RESERVE ) ) {
			return true;
		}
		return self::memory_low();
	}

	/**
	 * Fatal during a slice (memory/time limit): leave a visible trace; the lease
	 * itself is released by Mobo_Core_Shutdown_Guard and the cursor of the last
	 * committed step is intact.
	 *
	 * @param array $error error_get_last().
	 * @return void
	 */
	public static function record_fatal( $error ) {
		$state = self::get_state();
		$state['fatalStops'] = absint( isset( $state['fatalStops'] ) ? $state['fatalStops'] : 0 ) + 1;
		$state['lastError']  = 'درخواست قبلی با خطای مهلک PHP قطع شد (حافظه یا زمان)؛ از آخرین مرحله ذخیره‌شده ادامه می‌یابد.';
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Run the pending scan or job for at most $budget seconds (clamped to the
	 * request deadline).
	 *
	 * @param float $budget Seconds.
	 * @return array{state:array,job:array,ran:string}
	 */
	public static function run_slice( $budget = 8.0 ) {
		$budget = max( 0.5, min( 25.0, (float) $budget ) );
		if ( class_exists( 'Mobo_Core_Request_Deadline' ) ) {
			$budget = min( $budget, Mobo_Core_Request_Deadline::clamp_seconds( $budget, self::DEADLINE_RESERVE ) );
		}
		$out = array( 'state' => self::get_state(), 'job' => self::get_job(), 'ran' => '' );
		if ( $budget < 0.5 ) {
			return $out;
		}
		$work = 'running' === $out['job']['status'] ? 'job' : ( 'running' === $out['state']['status'] ? 'scan' : '' );
		if ( '' === $work ) {
			return $out;
		}
		$ttl  = class_exists( 'Mobo_Core_Request_Deadline' ) && method_exists( 'Mobo_Core_Request_Deadline', 'runner_lock_ttl' ) ? Mobo_Core_Request_Deadline::runner_lock_ttl( (int) ceil( $budget ) ) : (int) ceil( $budget ) + 60;
		$lock = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( self::LOCK_NAME, $ttl ) : '__no_lock__';
		if ( false === $lock ) {
			return $out;
		}
		$guarded = class_exists( 'Mobo_Core_Shutdown_Guard' ) && '__no_lock__' !== $lock;
		if ( $guarded ) {
			/* A fatal (memory/time) must not keep the lease for its full TTL. */
			Mobo_Core_Shutdown_Guard::track_lock( self::LOCK_NAME, (string) $lock );
			Mobo_Core_Shutdown_Guard::on_fatal( 'data_health', array( __CLASS__, 'record_fatal' ) );
		}
		$started = microtime( true );
		try {
			if ( 'job' === $work ) {
				$out['job'] = self::run_job( $started + $budget );
			} else {
				$out['state'] = self::run_scan( $started + $budget );
				/* The automatic class-(a) job starts right after a complete scan. */
				if ( 'complete' === $out['state']['status'] && $out['state']['finishedAt'] >= (int) $started && self::auto_repair_enabled() ) {
					self::maybe_start_auto_job( $out['state'] );
				}
			}
			$out['ran'] = $work;
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && '__no_lock__' !== $lock ) {
				Mobo_Core_Lock::release( self::LOCK_NAME, $lock );
			}
			if ( $guarded ) {
				Mobo_Core_Shutdown_Guard::untrack_lock( self::LOCK_NAME );
				Mobo_Core_Shutdown_Guard::forget_on_fatal( 'data_health' );
			}
		}
		$out['state'] = self::get_state();
		$out['job']   = self::get_job();
		if ( 'running' === $out['state']['status'] || 'running' === $out['job']['status'] ) {
			self::schedule_continue();
		}
		return $out;
	}

	/**
	 * Scan steps until the deadline.
	 *
	 * @param float $deadline Absolute microtime.
	 * @return array State.
	 */
	private static function run_scan( $deadline ) {
		$state   = self::get_state();
		$defs    = Mobo_Core_Data_Health_Detectors::definitions();
		$ids     = array_keys( $defs );
		$started = microtime( true );
		$steps   = 0;
		while ( 'running' === $state['status'] && microtime( true ) < $deadline && $steps < 500 ) {
			if ( self::stop_requested() ) {
				$state['status'] = 'stopped';
				break;
			}
			if ( self::memory_low() ) {
				$state['memoryStops']++;
				$state['lastError'] = 'حافظه PHP رو به پایان است؛ ادامه در درخواست بعدی.';
				break;
			}
			$steps++;
			if ( 'purge' === $state['phase'] ) {
				$deleted = Mobo_Core_Data_Health_Store::purge_old_findings( $state['scanId'] );
				if ( false === $deleted ) {
					$state['lastError'] = 'پاک کردن نتایج قبلی ناموفق بود؛ دوباره تلاش می‌شود.';
					break;
				}
				if ( 0 === $deleted ) {
					$state['phase']  = 'scan';
					$state['index']  = 0;
					$state['cursor'] = self::initial_cursor( $defs[ $ids[0] ] );
				}
				self::save_state( $state );
				continue;
			}
			$index = absint( $state['index'] );
			if ( $index >= count( $ids ) ) {
				$state['status']           = 'complete';
				$state['phase']            = 'done';
				$state['finishedAt']       = time();
				$state['lastCompleteAt']   = $state['finishedAt'];
				$state['lastCompleteScan'] = $state['scanId'];
				$state['lastError']        = '';
				self::save_state( $state );
				break;
			}
			$id     = $ids[ $index ];
			$dstate = isset( $state['detectors'][ $id ] ) ? $state['detectors'][ $id ] : self::empty_detector_state( $defs[ $id ] );
			$result = Mobo_Core_Data_Health_Detectors::step( $id, $state['cursor'], $dstate );
			if ( '' !== $result['error'] ) {
				$dstate['errors']++;
				$dstate['error'] = sanitize_text_field( $result['error'] );
				if ( $dstate['errors'] >= self::MAX_STEP_ERRORS ) {
					/* Give up on this detector for this scan, keep the others going. */
					$dstate['done']             = true;
					$state['detectors'][ $id ] = $dstate;
					self::advance_detector( $state, $defs, $ids );
					self::save_state( $state );
					continue;
				}
				$state['detectors'][ $id ] = $dstate;
				$state['lastError']        = 'خطای خواندن پایگاه داده؛ همین مرحله دوباره اجرا می‌شود.';
				self::save_state( $state );
				break;
			}
			$store = self::store_findings( $state['scanId'], $id, $result['findings'], $dstate );
			if ( false === $store ) {
				$dstate['errors']++;
				$dstate['error']           = 'findings_write_failed';
				$state['detectors'][ $id ] = $dstate;
				$state['lastError']        = 'ذخیره نتایج ناموفق بود؛ همین مرحله دوباره اجرا می‌شود.';
				self::save_state( $state );
				break;
			}
			$dstate['errors']  = 0;
			$dstate['error']   = '';
			$dstate['groups'] += absint( $result['groups'] );
			$state['cursor']   = $result['next'];
			if ( $result['done'] ) {
				$dstate['done'] = true;
			}
			$state['detectors'][ $id ] = $dstate;
			if ( $result['done'] ) {
				self::advance_detector( $state, $defs, $ids );
			}
			$state['steps']++;
			self::save_state( $state );
		}
		$elapsed                   = microtime( true ) - $started;
		$state['slices']++;
		$state['lastSliceSeconds'] = round( $elapsed, 3 );
		$state['maxSliceSeconds']  = round( max( (float) $state['maxSliceSeconds'], $elapsed ), 3 );
		$state['peakMemory']       = max( absint( $state['peakMemory'] ), class_exists( 'Mobo_Core_Environment' ) ? (int) Mobo_Core_Environment::memory_peak( true ) : 0 );
		self::save_state( $state );
		return $state;
	}

	/** @return int|string */
	private static function initial_cursor( array $def ) {
		return 'str' === $def['cursor'] ? '' : 0;
	}

	/** @return void */
	private static function advance_detector( array &$state, array $defs, array $ids ) {
		$state['index'] = absint( $state['index'] ) + 1;
		if ( isset( $ids[ $state['index'] ] ) ) {
			$state['cursor'] = self::initial_cursor( $defs[ $ids[ $state['index'] ] ] );
		}
	}

	/**
	 * Count and persist findings of one step.
	 *
	 * @param string $scan_id Scan.
	 * @param string $id Detector.
	 * @param array  $findings Findings.
	 * @param array  $dstate Detector state (updated).
	 * @return bool
	 */
	private static function store_findings( $scan_id, $id, array $findings, array &$dstate ) {
		/* Work on a copy: counters are committed only when the rows are written. */
		$work = $dstate;
		$keep = array();
		foreach ( $findings as $f ) {
			if ( 'covered' === $f['status'] ) {
				$work['covered'] += $f['weight'];
			} else {
				$work['count']                 += $f['weight'];
				$work['byClass'][ $f['class'] ] = absint( isset( $work['byClass'][ $f['class'] ] ) ? $work['byClass'][ $f['class'] ] : 0 ) + $f['weight'];
			}
			if ( count( $work['samples'] ) < self::SAMPLE_LIMIT && 'covered' !== $f['status'] ) {
				$work['samples'][] = $f['object_id'] > 0 ? $f['object_id'] : substr( (string) $f['object_ref'], 0, 60 );
			}
			if ( $work['stored'] + count( $keep ) < Mobo_Core_Data_Health_Store::MAX_FINDINGS_PER_DETECTOR ) {
				$keep[] = $f;
			} else {
				$work['note'] = 'findings_truncated';
			}
		}
		if ( ! Mobo_Core_Data_Health_Store::is_ready() ) {
			$dstate = $work;
			return true;
		}
		if ( ! Mobo_Core_Data_Health_Store::delete_uncommitted( $scan_id, $id, absint( isset( $work['lastId'] ) ? $work['lastId'] : 0 ) ) ) {
			return false;
		}
		if ( empty( $keep ) ) {
			$dstate = $work;
			return true;
		}
		$written = Mobo_Core_Data_Health_Store::insert_findings( $scan_id, $id, $keep );
		if ( false === $written ) {
			return false;
		}
		$work['stored'] += $written;
		$work['lastId']  = Mobo_Core_Data_Health_Store::max_finding_id( $scan_id, $id );
		$dstate          = $work;
		return true;
	}

	/** @return void */
	private static function maybe_start_auto_job( array $state ) {
		if ( '' !== self::repairs_blocked_reason() ) {
			return;
		}
		$auto = array();
		foreach ( Mobo_Core_Data_Health_Detectors::definitions() as $id => $def ) {
			if ( 'a' === $def['class'] && isset( $state['detectors'][ $id ] ) && absint( $state['detectors'][ $id ]['byClass']['a'] ) > 0 ) {
				$auto[] = $id;
			}
		}
		if ( ! empty( $auto ) ) {
			self::start_apply( $auto, array( 'a' ), 'auto' );
		}
	}

	/**
	 * Repair job steps until the deadline.
	 *
	 * @param float $deadline Absolute microtime.
	 * @return array Job.
	 */
	private static function run_job( $deadline ) {
		$job     = self::get_job();
		$blocked = self::repairs_blocked_reason();
		if ( '' !== $blocked ) {
			$job['status']    = 'blocked';
			$job['lastError'] = $blocked;
			self::save_job( $job );
			return $job;
		}
		$steps = 0;
		while ( 'running' === $job['status'] && microtime( true ) < $deadline && $steps < 400 ) {
			if ( self::stop_requested() ) {
				$job['status'] = 'stopped';
				break;
			}
			if ( self::memory_low() ) {
				$job['lastError'] = 'حافظه PHP رو به پایان است؛ ادامه در درخواست بعدی.';
				break;
			}
			$steps++;
			$job = 'undo' === $job['type'] ? self::undo_step( $job, $deadline ) : self::apply_step( $job, $deadline );
			self::save_job( $job );
		}
		if ( in_array( $job['status'], array( 'complete', 'failed' ), true ) ) {
			$counts = Mobo_Core_Data_Health_Store::batch_counts( $job['batchId'] );
			self::upsert_batch(
				array(
					'id'         => $job['batchId'],
					'status'     => 'undo' === $job['type'] ? ( ! empty( $counts['applied'] ) || ! empty( $counts['conflict'] ) ? 'partially_undone' : 'undone' ) : ( 'failed' === $job['status'] ? 'failed' : 'applied' ),
					'applied'    => absint( isset( $counts['applied'] ) ? $counts['applied'] : 0 ),
					'undone'     => absint( isset( $counts['undone'] ) ? $counts['undone'] : 0 ),
					'conflicts'  => absint( isset( $counts['conflict'] ) ? $counts['conflict'] : 0 ),
					'failed'     => absint( isset( $counts['failed'] ) ? $counts['failed'] : 0 ),
					'finishedAt' => time(),
				)
			);
		}
		return $job;
	}

	/** @return array */
	private static function apply_step( array $job, $deadline = INF ) {
		$index = absint( $job['index'] );
		if ( ! isset( $job['detectors'][ $index ] ) ) {
			$job['status']     = 'complete';
			$job['finishedAt'] = time();
			return $job;
		}
		$detector = $job['detectors'][ $index ];
		$rows     = Mobo_Core_Data_Health_Store::findings_page( $job['scanId'], $detector, absint( $job['cursor'] ), self::JOB_CHUNK, 'open' );
		if ( false === $rows ) {
			$job['lastError'] = 'خواندن فهرست موارد ناموفق بود؛ دوباره تلاش می‌شود.';
			return $job;
		}
		if ( empty( $rows ) ) {
			$job['index']  = $index + 1;
			$job['cursor'] = 0;
			return $job;
		}
		$handled = 0;
		foreach ( $rows as $row ) {
			/* Per-item yield (at least one item per call so work always advances);
			 * the cursor only moves past items really handled. */
			if ( $handled > 0 && self::must_yield( $deadline ) ) {
				break;
			}
			$handled++;
			$job['cursor'] = absint( $row['id'] );
			if ( ! in_array( (string) $row['repair_class'], $job['classes'], true ) || '' === (string) $row['action'] ) {
				continue;
			}
			$job['processed']++;
			$result = Mobo_Core_Data_Health_Repair::apply_finding( $row, $job['batchId'] );
			if ( 'applied' === $result['result'] ) {
				$job['applied']++;
				Mobo_Core_Data_Health_Store::set_finding_status( absint( $row['id'] ), 'applied', $job['batchId'] );
			} elseif ( 'skipped' === $result['result'] ) {
				$job['skipped']++;
				Mobo_Core_Data_Health_Store::set_finding_status( absint( $row['id'] ), 'skipped', $job['batchId'] );
			} else {
				$job['failed']++;
				$job['lastError'] = sanitize_text_field( $result['note'] );
				Mobo_Core_Data_Health_Store::set_finding_status( absint( $row['id'] ), 'failed', $job['batchId'] );
			}
		}
		/* Group-level effects (term recount, parent sync) are done per item. */
		self::upsert_batch( array( 'id' => $job['batchId'], 'applied' => $job['applied'] ) );
		return $job;
	}

	/** @return array */
	private static function undo_step( array $job, $deadline = INF ) {
		$rows = Mobo_Core_Data_Health_Store::backup_page_desc( $job['batchId'], absint( $job['cursor'] ), self::JOB_CHUNK );
		if ( false === $rows ) {
			$job['lastError'] = 'خواندن پشتیبان ناموفق بود؛ دوباره تلاش می‌شود.';
			return $job;
		}
		if ( empty( $rows ) ) {
			$job['status']     = 'complete';
			$job['finishedAt'] = time();
			return $job;
		}
		$handled = 0;
		foreach ( $rows as $row ) {
			/* Per-item yield (at least one item per call so work always advances);
			 * the cursor only moves past items really handled. */
			if ( $handled > 0 && self::must_yield( $deadline ) ) {
				break;
			}
			$handled++;
			$job['cursor'] = absint( $row['id'] );
			if ( ! in_array( (string) $row['status'], array( 'applied', 'pending' ), true ) ) {
				continue;
			}
			$job['processed']++;
			$result = Mobo_Core_Data_Health_Repair::undo_row( $row );
			if ( 'undone' === $result['result'] ) {
				$job['undone']++;
				if ( absint( $row['finding_id'] ) > 0 ) {
					Mobo_Core_Data_Health_Store::set_finding_status( absint( $row['finding_id'] ), 'open', '' );
				}
			} elseif ( 'conflict' === $result['result'] ) {
				$job['conflicts']++;
			} elseif ( 'skipped' === $result['result'] ) {
				$job['skipped']++;
			} else {
				$job['failed']++;
				$job['lastError'] = sanitize_text_field( $result['note'] );
			}
		}
		return $job;
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                         */
	/* ------------------------------------------------------------------ */

	/** Register the hourly tick once (admin_init). @return void */
	public static function ensure_schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 900, 'hourly', self::CRON_HOOK );
		}
	}

	/** One-minute continuation while work is pending. @return void */
	private static function schedule_continue() {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::CRON_HOOK, array( 'continue' ) ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK, array( 'continue' ) );
		}
	}

	/**
	 * WP-Cron entry: continue pending work, or start the daily scan.
	 *
	 * @param string $mode '' (hourly) or 'continue'.
	 * @return void
	 */
	public static function cron_tick( $mode = '' ) {
		try {
			$state = self::get_state();
			$job   = self::get_job();
			if ( 'running' !== $state['status'] && 'running' !== $job['status'] ) {
				$due = self::daily_scan_enabled() && time() - absint( $state['lastCompleteAt'] ) >= self::RESCAN_SECONDS && time() - absint( $state['startedAt'] ) >= 3600;
				if ( ! $due || 'stopped' === $state['status'] ) {
					return;
				}
				$started = self::start_scan( 'cron' );
				if ( is_wp_error( $started ) ) {
					return;
				}
			}
			self::run_slice( 8.0 );
		} catch ( Throwable $e ) {
			if ( class_exists( 'Mobo_Core_Logger' ) ) {
				Mobo_Core_Logger::warning( 'Data health cron slice failed; it resumes from the last committed step.', array( 'error' => $e->getMessage() ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Reporting                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Counts-only summary for the health payload / diagnostics bundle. No IDs,
	 * titles, GUIDs or customer data. Option reads only.
	 *
	 * @return array
	 */
	public static function health_summary() {
		$state    = self::get_state();
		$job      = self::get_job();
		$defs     = Mobo_Core_Data_Health_Detectors::definitions();
		$counts   = array();
		$by_class = array( 'a' => 0, 'b' => 0, 'c' => 0 );
		$done     = 0;
		foreach ( $defs as $id => $def ) {
			$d = isset( $state['detectors'][ $id ] ) ? $state['detectors'][ $id ] : null;
			if ( ! is_array( $d ) ) {
				continue;
			}
			$counts[ $id ] = absint( $d['count'] );
			foreach ( array( 'a', 'b', 'c' ) as $class ) {
				$by_class[ $class ] += absint( isset( $d['byClass'][ $class ] ) ? $d['byClass'][ $class ] : 0 );
			}
			if ( ! empty( $d['done'] ) ) {
				$done++;
			}
		}
		$errors = array();
		foreach ( $state['detectors'] as $id => $d ) {
			if ( is_array( $d ) && '' !== (string) $d['error'] ) {
				$errors[] = sanitize_key( $id );
			}
		}
		$batches = self::get_batches();
		$last    = isset( $batches[0] ) ? $batches[0] : array();
		return array(
			'status'          => sanitize_key( (string) $state['status'] ),
			'scanComplete'    => 'complete' === $state['status'],
			'progressPct'     => empty( $defs ) ? 0 : (int) floor( 100 * $done / count( $defs ) ),
			'lastCompleteAt'  => absint( $state['lastCompleteAt'] ) > 0 ? gmdate( 'c', absint( $state['lastCompleteAt'] ) ) : '',
			'issues'          => $counts,
			'byRepairClass'   => array( 'automatic' => $by_class['a'], 'adminConfirm' => $by_class['b'], 'reportOnly' => $by_class['c'] ),
			'detectorErrors'  => $errors,
			'storageReady'    => Mobo_Core_Data_Health_Store::is_ready(),
			'autoRepair'      => self::auto_repair_enabled(),
			'job'             => array(
				'type'      => sanitize_key( (string) $job['type'] ),
				'status'    => sanitize_key( (string) $job['status'] ),
				'applied'   => absint( $job['applied'] ),
				'undone'    => absint( $job['undone'] ),
				'skipped'   => absint( $job['skipped'] ),
				'failed'    => absint( $job['failed'] ),
				'conflicts' => absint( $job['conflicts'] ),
			),
			'lastBatch'       => empty( $last ) ? null : array(
				'type'    => sanitize_key( (string) ( isset( $last['type'] ) ? $last['type'] : '' ) ),
				'status'  => sanitize_key( (string) ( isset( $last['status'] ) ? $last['status'] : '' ) ),
				'applied' => absint( isset( $last['applied'] ) ? $last['applied'] : 0 ),
				'undone'  => absint( isset( $last['undone'] ) ? $last['undone'] : 0 ),
				'at'      => ! empty( $last['createdAt'] ) ? gmdate( 'c', absint( $last['createdAt'] ) ) : '',
			),
			'peakMemoryBytes' => absint( $state['peakMemory'] ),
			'maxSliceSeconds' => (float) $state['maxSliceSeconds'],
		);
	}
}
