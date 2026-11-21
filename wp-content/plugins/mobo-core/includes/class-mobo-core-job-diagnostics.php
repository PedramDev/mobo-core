<?php
/**
 * Reusable watchdog + diagnostics helper for long-running Mobo jobs.
 *
 * Consumers: Reprice and the per-product pricing override flow (explicit API),
 * Sync, Repair and Recategorize (observe() + generic_watchdog() from a status
 * snapshot, so their state machines stay untouched):
 *
 *   $diag = Mobo_Core_Job_Diagnostics::open( 'reprice' );
 *   $diag->start_run( array( 'total' => 120 ) );
 *   $diag->progress( $processed, $total, $cursor, 'items' );
 *   $diag->error( 'message', $item_id, $throwable );
 *   $diag->event( 'skip', 'Item skipped', array( 'itemId' => 12 ) );
 *   $diag->flush();
 *
 *   $diagnosis = Mobo_Core_Job_Diagnostics::diagnose( $state, $signals, time(), $stall_seconds );
 *
 * Everything persisted is bounded (200 events, 50 poison items) and passes a
 * secret/PII filter. Storage is the Connection-scoped State Store.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Job_Diagnostics {
	const STATE_PREFIX          = 'job_diag_';
	const MAX_EVENTS            = 200;
	const MAX_POISON            = 50;
	const DEFAULT_STALL_MINUTES = 15;
	const STALL_OPTION          = 'mobo_core_job_stall_minutes';
	/** Automatic recoveries of one reason without progress before giving up. */
	const MAX_AUTO_RECOVERIES   = 5;

	/** @var string */
	private $job;
	/** @var array */
	private $data;
	/** @var bool */
	private $dirty = false;

	private function __construct( $job, $data ) {
		$this->job  = $job;
		$this->data = $data;
	}

	/** @return self */
	public static function open( $job ) {
		$job = sanitize_key( (string) $job );
		$data = array();
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() ) {
			$stored = Mobo_Core_State_Store::get( self::STATE_PREFIX . $job, array() );
			$data = is_array( $stored ) ? $stored : array();
		}
		return new self( $job, self::normalize( $data ) );
	}

	/** In-memory instance (tests, previews). */
	public static function from_array( $job, $data ) {
		return new self( sanitize_key( (string) $job ), self::normalize( is_array( $data ) ? $data : array() ) );
	}

	public static function defaults() {
		return array(
			'runId'          => '',
			'startedAt'      => 0,
			'finishedAt'     => 0,
			'status'         => 'idle',
			'step'           => '',
			'cursor'         => 0,
			'processed'      => 0,
			'total'          => 0,
			'lastProgressAt' => 0,
			'lastError'      => null,
			'poisonItems'    => array(),
			'events'         => array(),
			'stalledReason'  => '',
			'recoveries'     => array(),
			'correlationId'  => '',
			'runKey'         => '',
		);
	}

	private static function normalize( $data ) {
		$data = array_merge( self::defaults(), $data );
		$data['events'] = is_array( $data['events'] ) ? array_values( $data['events'] ) : array();
		$data['poisonItems'] = is_array( $data['poisonItems'] ) ? array_values( $data['poisonItems'] ) : array();
		$data['recoveries'] = is_array( $data['recoveries'] ) ? $data['recoveries'] : array();
		return $data;
	}

	/** @return array */
	public function data() {
		return $this->data;
	}

	public function start_run( $meta = array() ) {
		$now = time();
		$this->data = self::defaults();
		$this->data['runId'] = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'run-', true );
		$this->data['correlationId'] = class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::make( $this->job, $this->data['runId'] ) : $this->job . ':' . $this->data['runId'];
		$this->data['startedAt'] = $now;
		$this->data['lastProgressAt'] = $now;
		$this->data['status'] = 'running';
		$this->data['total'] = absint( isset( $meta['total'] ) ? $meta['total'] : 0 );
		$this->dirty = true;
		$this->event( 'start', 'Run started.', self::sanitize_context( $meta ) );
		return $this;
	}

	/** Record forward progress; lastProgressAt moves only when something advanced. */
	public function progress( $processed, $total, $cursor, $step = '' ) {
		$processed = absint( $processed );
		$cursor = absint( $cursor );
		$advanced = $processed > absint( $this->data['processed'] ) || $cursor > absint( $this->data['cursor'] );
		$this->data['processed'] = $processed;
		$this->data['total'] = absint( $total );
		$this->data['cursor'] = $cursor;
		$this->data['step'] = sanitize_key( (string) $step );
		$this->data['status'] = 'running';
		if ( $advanced ) {
			$this->data['lastProgressAt'] = time();
			$this->data['recoveries'] = array();
			$this->data['stalledReason'] = '';
		}
		$this->dirty = true;
		return $advanced;
	}

	public function touch_progress() {
		$this->data['lastProgressAt'] = time();
		$this->dirty = true;
	}

	public function event( $type, $message, $context = array() ) {
		$this->data['events'][] = array(
			'at'      => time(),
			'type'    => sanitize_key( (string) $type ),
			'message' => self::clip( $message, 300 ),
			'context' => self::sanitize_context( $context ),
			'cid'     => $this->correlation_id(),
		);
		if ( count( $this->data['events'] ) > self::MAX_EVENTS ) {
			$this->data['events'] = array_slice( $this->data['events'], -self::MAX_EVENTS );
		}
		$this->dirty = true;
	}

	/**
	 * @param string          $message   Safe message.
	 * @param int             $item_id   Item ID.
	 * @param Throwable|null  $throwable Optional exception.
	 */
	public function error( $message, $item_id = 0, $throwable = null ) {
		$error = array(
			'at'             => time(),
			'message'        => self::clip( $message, 500 ),
			'itemId'         => absint( $item_id ),
			'exceptionClass' => '',
			'file'           => '',
			'line'           => 0,
		);
		if ( $throwable instanceof Throwable ) {
			$error['exceptionClass'] = self::clip( get_class( $throwable ), 120 );
			$error['file'] = self::relative_file( $throwable->getFile() );
			$error['line'] = absint( $throwable->getLine() );
			if ( '' === $error['message'] ) {
				$error['message'] = self::clip( $throwable->getMessage(), 500 );
			}
		}
		$this->data['lastError'] = $error;
		if ( class_exists( 'Mobo_Core_Error_Log' ) ) {
			Mobo_Core_Error_Log::capture( 'error', 'job.' . $this->job, $error['message'], array( 'itemId' => $error['itemId'], 'exceptionClass' => $error['exceptionClass'] ), $throwable instanceof Throwable ? $throwable->getFile() : '', $error['line'], $this->correlation_id() );
		}
		$this->event( 'error', $error['message'], array( 'itemId' => $error['itemId'], 'exceptionClass' => $error['exceptionClass'] ) );
	}

	public function poison( $item_id, $reason, $attempts = 0 ) {
		$item_id = absint( $item_id );
		foreach ( $this->data['poisonItems'] as $existing ) {
			if ( absint( $existing['itemId'] ?? 0 ) === $item_id && $item_id > 0 ) {
				return;
			}
		}
		$this->data['poisonItems'][] = array(
			'itemId'   => $item_id,
			'reason'   => self::clip( $reason, 200 ),
			'attempts' => absint( $attempts ),
			'at'       => time(),
		);
		if ( count( $this->data['poisonItems'] ) > self::MAX_POISON ) {
			$this->data['poisonItems'] = array_slice( $this->data['poisonItems'], -self::MAX_POISON );
		}
		$this->event( 'poison', 'Item skipped after repeated failures.', array( 'itemId' => $item_id, 'reason' => $reason, 'attempts' => $attempts ) );
	}

	/** Count one automatic recovery for a reason; returns the new count. */
	public function count_recovery( $reason ) {
		$reason = sanitize_key( (string) $reason );
		$count = absint( $this->data['recoveries'][ $reason ] ?? 0 ) + 1;
		$this->data['recoveries'][ $reason ] = $count;
		$this->dirty = true;
		return $count;
	}

	public function set_stalled( $reason ) {
		$this->data['stalledReason'] = sanitize_key( (string) $reason );
		$this->data['status'] = 'stalled';
		$this->dirty = true;
	}

	public function finish( $status ) {
		$this->data['status'] = sanitize_key( (string) $status );
		$this->data['finishedAt'] = time();
		$this->data['stalledReason'] = '';
		$this->event( 'finish', 'Run finished: ' . sanitize_key( (string) $status ) . '.' );
	}

	public function flush() {
		if ( ! $this->dirty ) {
			return true;
		}
		if ( ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() ) {
			return false;
		}
		$ok = Mobo_Core_State_Store::set( self::STATE_PREFIX . $this->job, $this->data );
		if ( $ok ) {
			$this->dirty = false;
		}
		return $ok;
	}

	/** @return string Correlation id of the current run ('<job>:<runId>'). */
	public function correlation_id() {
		$cid = (string) ( $this->data['correlationId'] ?? '' );
		if ( '' === $cid && '' !== (string) ( $this->data['runId'] ?? '' ) ) {
			$cid = $this->job . ':' . (string) $this->data['runId'];
		}
		return $cid;
	}

	/* ------------------------------------------------------------------ *
	 * Snapshot-driven diagnostics for Sync, Repair and Recategorize.
	 * ------------------------------------------------------------------ */

	/** Statuses that mean "work outstanding". */
	const ACTIVE_STATUSES = array( 'running', 'queued', 'waiting', 'waiting_for_portal' );

	/** Terminal statuses. */
	const TERMINAL_STATUSES = array( 'done', 'cancelled', 'needs_attention', 'failed', 'idle' );

	/**
	 * Record start/progress/error/finish transitions from a status snapshot.
	 * Idempotent: calling it repeatedly with the same snapshot changes nothing
	 * but lastProgressAt bookkeeping, so it can run after every step/poll.
	 *
	 * @param string $job      sync|repair|recategorize.
	 * @param array  $snapshot runKey, status, processed, total, cursor, lastError, step.
	 * @param bool   $flush    Persist immediately.
	 * @return self
	 */
	public static function observe( $job, $snapshot, $flush = true ) {
		$diag     = self::open( $job );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$status   = sanitize_key( (string) ( $snapshot['status'] ?? 'idle' ) );
		$run_key  = substr( sanitize_text_field( (string) ( $snapshot['runKey'] ?? '' ) ), 0, 80 );
		$active   = in_array( $status, self::ACTIVE_STATUSES, true );

		if ( $active && '' !== $run_key && $run_key !== (string) $diag->data['runKey'] ) {
			$diag->start_run( array( 'total' => absint( $snapshot['total'] ?? 0 ), 'runKey' => $run_key, 'source' => sanitize_key( (string) ( $snapshot['source'] ?? '' ) ) ) );
			/* The run key (syncId, start stamp) is the run id, so callers can open the
			 * same correlation scope without reading diagnostics first. */
			$diag->data['runKey']        = $run_key;
			$diag->data['runId']         = $run_key;
			$diag->data['correlationId'] = class_exists( 'Mobo_Core_Correlation' ) ? Mobo_Core_Correlation::make( $job, $run_key ) : $job . ':' . $run_key;
		}
		if ( $active ) {
			$diag->progress( absint( $snapshot['processed'] ?? 0 ), absint( $snapshot['total'] ?? 0 ), absint( $snapshot['cursor'] ?? 0 ), (string) ( $snapshot['step'] ?? '' ) );
			if ( 'running' !== $status ) {
				$diag->data['status'] = 'waiting';
			}
		}
		$last_error = trim( (string) ( $snapshot['lastError'] ?? '' ) );
		$known      = is_array( $diag->data['lastError'] ) ? (string) ( $diag->data['lastError']['message'] ?? '' ) : '';
		if ( '' !== $last_error && self::clip( $last_error, 500 ) !== $known ) {
			$diag->error( $last_error, absint( $snapshot['itemId'] ?? 0 ) );
		}
		if ( in_array( $status, self::TERMINAL_STATUSES, true ) && 'idle' !== $status
			&& in_array( (string) $diag->data['status'], array( 'running', 'waiting', 'stalled' ), true ) ) {
			$diag->finish( $status );
		}
		if ( $flush ) {
			$diag->flush();
		}
		return $diag;
	}

	/** Worker lock of the manual Sync/Repair state machine. */
	const MANUAL_SYNC_LOCK = 'manual_sync';

	/**
	 * Job name of a manual sync status payload (Repair is a Sync in repair mode).
	 *
	 * @param array $status get_manual_sync_status() payload.
	 * @return string sync|repair
	 */
	public static function manual_sync_job( $status ) {
		return is_array( $status ) && ! empty( $status['repairMode'] ) ? 'repair' : 'sync';
	}

	/**
	 * Observe a Mobo_Core_Product_Sync::get_manual_sync_status() payload.
	 *
	 * @param array $status Status payload.
	 * @return void
	 */
	public static function observe_manual_sync( $status ) {
		if ( ! is_array( $status ) || '' === (string) ( $status['syncId'] ?? '' ) ) {
			return;
		}
		try {
			$step = 'products';
			if ( ! empty( $status['repairMode'] ) ) {
				if ( empty( $status['repairIntegrityComplete'] ) ) {
					$step = 'integrity_' . sanitize_key( (string) ( $status['repairIntegrityPhase'] ?? '' ) );
				} elseif ( ! empty( $status['repairProductsComplete'] ) && empty( $status['postSyncIntegrityComplete'] ) ) {
					$step = 'post_sync_' . sanitize_key( (string) ( $status['postSyncIntegrityPhase'] ?? '' ) );
				}
			}
			self::observe(
				self::manual_sync_job( $status ),
				array(
					'runKey'    => (string) $status['syncId'],
					'status'    => (string) ( $status['status'] ?? 'idle' ),
					'source'    => (string) ( $status['source'] ?? '' ),
					'processed' => absint( $status['processedProducts'] ?? 0 ),
					'total'     => absint( $status['productTotalCount'] ?? 0 ),
					'cursor'    => absint( $status['processedProducts'] ?? 0 ) + absint( $status['repairIntegrityCursor'] ?? 0 ) + absint( $status['postSyncIntegrityCursor'] ?? 0 ),
					'lastError' => (string) ( $status['lastError'] ?? '' ),
					'itemId'    => absint( $status['currentProductId'] ?? 0 ),
					'step'      => $step,
				)
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Admin/AJAX additions for a manual sync status payload.
	 *
	 * @param array $status Status payload.
	 * @return array
	 */
	public static function manual_sync_extras( $status ) {
		$status = is_array( $status ) ? $status : array();
		return self::status_extras( self::manual_sync_job( $status ), (string) ( $status['status'] ?? 'idle' ), (float) ( $status['progressPercent'] ?? 0 ), self::MANUAL_SYNC_LOCK );
	}

	/**
	 * Watchdog signals shared by every job (options + one lock read).
	 *
	 * @param string $lock_name Worker lock name.
	 * @param array  $data      Diagnostics data.
	 * @param array  $extra     Job-specific signal overrides.
	 * @return array
	 */
	public static function generic_signals( $lock_name, $data, $extra = array() ) {
		$cron_last = absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) );
		$cron_last = max( $cron_last, absint( get_option( 'mobo_core_self_runner_last_run_success_at', 0 ) ) );
		$lock = '' !== (string) $lock_name && class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( (string) $lock_name ) : array();
		return array_merge(
			array(
				'cronLastHitAt'   => $cron_last,
				'lockActive'      => ! empty( $lock['active'] ),
				'lockHeartbeatAt' => absint( $lock['lastHeartbeatAt'] ?? 0 ),
				'lockExpiresAt'   => absint( $lock['expiresAt'] ?? 0 ),
				'upgradeActive'   => class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active(),
				'recoveries'      => isset( $data['recoveries'] ) && is_array( $data['recoveries'] ) ? $data['recoveries'] : array(),
			),
			is_array( $extra ) ? $extra : array()
		);
	}

	/**
	 * Diagnosis of a job from its snapshot status + stored diagnostics.
	 *
	 * @param string $job       Job.
	 * @param string $status    Job status.
	 * @param string $lock_name Worker lock.
	 * @param array  $extra     Extra signals.
	 * @return array
	 */
	public static function diagnose_job( $job, $status, $lock_name, $extra = array() ) {
		$data   = self::open( $job )->data();
		$status = sanitize_key( (string) $status );
		$extra  = is_array( $extra ) ? $extra : array();
		if ( 'waiting_for_portal' === $status ) {
			$extra['waitingBackend'] = true;
		}
		$mapped = in_array( $status, self::ACTIVE_STATUSES, true ) ? 'running' : $status;
		if ( 'stalled' === (string) $data['status'] && in_array( $status, self::ACTIVE_STATUSES, true ) ) {
			$mapped = 'stalled';
		}
		$state = array(
			'status'         => $mapped,
			'stalledReason'  => (string) $data['stalledReason'],
			'lastProgressAt' => absint( $data['lastProgressAt'] ),
			'updatedAt'      => absint( $extra['updatedAt'] ?? 0 ),
		);
		return self::diagnose( $state, self::generic_signals( $lock_name, $data, $extra ), time(), self::stall_seconds() );
	}

	/**
	 * Bounded self-healing for snapshot jobs: release a dead holder's lease or
	 * kick the background worker; after MAX_AUTO_RECOVERIES attempts of one
	 * reason without progress the diagnostics are marked stalled with the reason
	 * (the job's own state machine is never rewritten here).
	 *
	 * @param string $job       Job.
	 * @param string $status    Job status.
	 * @param string $lock_name Worker lock.
	 * @param string $context   Caller label.
	 * @return array Diagnosis + performed.
	 */
	public static function generic_watchdog( $job, $status, $lock_name, $context = 'cron' ) {
		if ( ! in_array( sanitize_key( (string) $status ), self::ACTIVE_STATUSES, true ) ) {
			return array( 'health' => 'idle', 'performed' => 'none' );
		}
		$diagnosis = self::diagnose_job( $job, $status, $lock_name );
		/* Not stalled, or recovery already gave up (operator action required). */
		if ( 'stalled' !== $diagnosis['health'] || ( 'resume' === $diagnosis['action'] && 'stalled' === (string) self::open( $job )->data['status'] ) ) {
			return array_merge( $diagnosis, array( 'performed' => 'none' ) );
		}
		$diag  = self::open( $job );
		$count = $diag->count_recovery( $diagnosis['reason'] );
		if ( ! empty( $diagnosis['giveUp'] ) || $count > self::MAX_AUTO_RECOVERIES ) {
			$diag->set_stalled( $diagnosis['reason'] );
			$diag->event( 'stalled', 'Automatic recovery gave up; operator action required.', array( 'reason' => $diagnosis['reason'], 'attempts' => $count, 'context' => $context ) );
			$performed = 'marked-stalled';
		} else {
			$performed = 'none';
			if ( 'release_lock' === $diagnosis['action'] && '' !== (string) $lock_name && class_exists( 'Mobo_Core_Lock' ) && method_exists( 'Mobo_Core_Lock', 'recover_stale_locks' ) ) {
				Mobo_Core_Lock::recover_stale_locks( array( (string) $lock_name ) );
				$performed = 'released-stale-lock';
			} elseif ( in_array( $diagnosis['action'], array( 'resume', 'shrink_batch', 'skip_item' ), true ) && class_exists( 'Mobo_Core_Self_Runner' ) && method_exists( 'Mobo_Core_Self_Runner', 'kick' ) ) {
				Mobo_Core_Self_Runner::kick( sanitize_key( (string) $job ) . '-watchdog', false );
				$performed = 'kicked-worker';
			}
			$diag->event( 'watchdog', 'Automatic recovery: ' . $performed . '.', array( 'reason' => $diagnosis['reason'], 'attempt' => $count, 'context' => $context ) );
		}
		$diag->flush();
		return array_merge( $diagnosis, array( 'performed' => $performed ) );
	}

	/**
	 * Status-payload additions for the admin UI/AJAX of a snapshot job.
	 *
	 * @param string $job       Job.
	 * @param string $status    Job status.
	 * @param float  $percent   Percent.
	 * @param string $lock_name Worker lock.
	 * @return array{health:string,diagnosis:array,statusLine:string,lastErrorDetail:?array,correlationId:string}
	 */
	public static function status_extras( $job, $status, $percent, $lock_name ) {
		$data      = self::open( $job )->data();
		$diagnosis = self::diagnose_job( $job, $status, $lock_name );
		$line_status = in_array( sanitize_key( (string) $status ), self::ACTIVE_STATUSES, true ) ? 'running' : sanitize_key( (string) $status );
		return array(
			'health'          => (string) $diagnosis['health'],
			'diagnosis'       => $diagnosis,
			'statusLine'      => self::status_line_fa( $line_status, $percent, $diagnosis ),
			'lastErrorDetail' => isset( $data['lastError'] ) && is_array( $data['lastError'] ) ? $data['lastError'] : null,
			'correlationId'   => ( new self( sanitize_key( (string) $job ), $data ) )->correlation_id(),
		);
	}

	/**
	 * Compact health payload (jobs.<job>), same shape as jobs.reprice.
	 *
	 * @param string $job       Job.
	 * @param string $status    Job status.
	 * @param int    $processed Processed.
	 * @param int    $total     Total.
	 * @param string $lock_name Worker lock.
	 * @return array
	 */
	public static function health_payload_for( $job, $status, $processed, $total, $lock_name ) {
		$data      = self::open( $job )->data();
		$diagnosis = self::diagnose_job( $job, $status, $lock_name );
		$stalled   = 'stalled' === $diagnosis['health'];
		$error     = isset( $data['lastError'] ) && is_array( $data['lastError'] ) ? $data['lastError'] : null;
		$total     = absint( $total );
		return array(
			'status'         => $stalled ? 'stalled' : sanitize_key( (string) $status ),
			'progressPct'    => $total > 0 ? (float) min( 100, round( ( absint( $processed ) / $total ) * 100, 2 ) ) : 0.0,
			'lastProgressAt' => absint( $data['lastProgressAt'] ) > 0 ? gmdate( 'c', absint( $data['lastProgressAt'] ) ) : null,
			'stalledReason'  => $stalled ? (string) $diagnosis['reason'] : '',
			'correlationId'  => ( new self( sanitize_key( (string) $job ), $data ) )->correlation_id(),
			'lastError'      => null === $error ? null : array(
				'message'        => (string) ( $error['message'] ?? '' ),
				'itemId'         => absint( $error['itemId'] ?? 0 ),
				'exceptionClass' => (string) ( $error['exceptionClass'] ?? '' ),
				'at'             => absint( $error['at'] ?? 0 ) > 0 ? gmdate( 'c', absint( $error['at'] ) ) : null,
			),
		);
	}

	/**
	 * «کپی گزارش عیب‌یابی» bundle for a snapshot job.
	 *
	 * @param string $job       Job.
	 * @param array  $status    Status payload.
	 * @param string $lock_name Worker lock.
	 * @param array  $extra     Extra.
	 * @return array
	 */
	public static function job_bundle( $job, $status, $lock_name, $extra = array() ) {
		$status    = is_array( $status ) ? $status : array();
		$data      = self::open( $job )->data();
		$diagnosis = self::diagnose_job( $job, (string) ( $status['status'] ?? 'idle' ), $lock_name );
		$extra     = is_array( $extra ) ? $extra : array();
		$extra['signals']      = self::generic_signals( $lock_name, $data );
		$extra['stallSeconds'] = self::stall_seconds();
		$extra['recentErrors'] = class_exists( 'Mobo_Core_Error_Log' ) && '' !== (string) ( $data['correlationId'] ?? '' )
			? Mobo_Core_Error_Log::recent( 0, '', (string) $data['correlationId'], 20 )
			: array();
		return self::bundle( $job, $status, $data, $diagnosis, $extra );
	}

	/** Configured stall threshold in seconds (default 15 minutes). */
	public static function stall_seconds() {
		$minutes = class_exists( 'Mobo_Core_Settings' )
			? Mobo_Core_Settings::get_int( self::STALL_OPTION, self::DEFAULT_STALL_MINUTES, 5, 240 )
			: self::DEFAULT_STALL_MINUTES;
		return $minutes * 60;
	}

	/**
	 * Pure watchdog decision.
	 *
	 * $state:   status, lastProgressAt, updatedAt.
	 * $signals: cronLastHitAt, lockActive, lockHeartbeatAt, lockExpiresAt,
	 *           failingItem, failingAttempts, maxAttempts, blockedItem,
	 *           blockedCount, blockedLimit, budgetZeroStreak, waitingBackend,
	 *           upgradeActive, recoveries (reason=>count).
	 *
	 * @return array{health:string,reason:string,action:string,secondsSinceProgress:int,itemId:int,giveUp:bool}
	 */
	public static function diagnose( $state, $signals, $now, $stall_seconds ) {
		$state = is_array( $state ) ? $state : array();
		$signals = is_array( $signals ) ? $signals : array();
		$now = absint( $now );
		$stall_seconds = max( 60, absint( $stall_seconds ) );
		$status = sanitize_key( (string) ( $state['status'] ?? 'idle' ) );
		$last = absint( $state['lastProgressAt'] ?? 0 );
		if ( $last <= 0 ) {
			$last = absint( $state['updatedAt'] ?? 0 );
		}
		$since = $last > 0 ? max( 0, $now - $last ) : 0;
		$out = array( 'health' => 'ok', 'reason' => '', 'action' => 'none', 'secondsSinceProgress' => $since, 'itemId' => 0, 'giveUp' => false );

		if ( 'stalled' === $status ) {
			$out['health'] = 'stalled';
			$out['reason'] = sanitize_key( (string) ( $state['stalledReason'] ?? 'stalled' ) );
			$out['action'] = 'resume';
			return $out;
		}
		if ( ! in_array( $status, array( 'running', 'queued', 'waiting' ), true ) ) {
			$out['health'] = 'idle';
			return $out;
		}
		if ( $last > 0 && $since < $stall_seconds ) {
			return $out;
		}

		if ( ! empty( $signals['upgradeActive'] ) ) {
			$out['health'] = 'waiting';
			$out['reason'] = 'upgrade_in_progress';
			return $out;
		}
		if ( ! empty( $signals['waitingBackend'] ) ) {
			$out['health'] = 'waiting';
			$out['reason'] = 'waiting_backend';
			return $out;
		}

		$out['health'] = 'stalled';
		$recoveries = isset( $signals['recoveries'] ) && is_array( $signals['recoveries'] ) ? $signals['recoveries'] : array();
		$cron_last = absint( $signals['cronLastHitAt'] ?? 0 );
		$lock_active = ! empty( $signals['lockActive'] );
		$heartbeat = absint( $signals['lockHeartbeatAt'] ?? 0 );
		$expires = absint( $signals['lockExpiresAt'] ?? 0 );
		$failing = absint( $signals['failingItem'] ?? 0 );
		$failing_attempts = absint( $signals['failingAttempts'] ?? 0 );
		$blocked = absint( $signals['blockedItem'] ?? 0 );
		$blocked_count = absint( $signals['blockedCount'] ?? 0 );
		$blocked_limit = max( 1, absint( $signals['blockedLimit'] ?? 20 ) );

		if ( $lock_active && ( ( $expires > 0 && $expires <= $now ) || ( $heartbeat > 0 && ( $now - $heartbeat ) >= $stall_seconds ) ) ) {
			$out['reason'] = 'stale_lock';
			$out['action'] = 'release_lock';
		} elseif ( $failing > 0 && $failing_attempts >= 2 ) {
			$out['reason'] = 'poison_item';
			$out['action'] = 'skip_item';
			$out['itemId'] = $failing;
		} elseif ( $blocked > 0 && $blocked_count >= max( 3, (int) floor( $blocked_limit / 2 ) ) ) {
			$out['reason'] = 'item_blocked';
			$out['action'] = 'skip_item';
			$out['itemId'] = $blocked;
		} elseif ( absint( $signals['budgetZeroStreak'] ?? 0 ) >= 3 ) {
			$out['reason'] = 'budget_exhausted';
			$out['action'] = 'shrink_batch';
		} elseif ( $cron_last <= 0 || ( $now - $cron_last ) >= $stall_seconds ) {
			$out['reason'] = 'cron_not_firing';
			$out['action'] = 'resume';
		} elseif ( $lock_active ) {
			$out['reason'] = 'worker_busy_no_progress';
			$out['action'] = 'none';
		} else {
			$out['reason'] = 'no_progress_unknown';
			$out['action'] = 'resume';
		}

		if ( absint( $recoveries[ $out['reason'] ] ?? 0 ) >= self::MAX_AUTO_RECOVERIES ) {
			$out['giveUp'] = true;
		}
		return $out;
	}

	/** Persian explanation for a reason code. */
	public static function reason_fa( $reason, $item_id = 0 ) {
		$item = absint( $item_id ) > 0 ? ' (شناسه ' . absint( $item_id ) . ')' : '';
		switch ( sanitize_key( (string) $reason ) ) {
			case 'stale_lock':
				return 'قفل Worker متعلق به پردازشی است که دیگر زنده نیست (lease منقضی شده).';
			case 'poison_item':
				return 'یک محصول' . $item . ' مدام خطا می‌دهد و صف را متوقف کرده است.';
			case 'item_blocked':
				return 'یک محصول' . $item . ' مدت طولانی توسط همگام‌سازی/قفل دیگری مشغول است.';
			case 'budget_exhausted':
				return 'زمان مجاز هر اجرا قبل از پردازش حتی یک مورد تمام می‌شود (هاست کند یا محصول سنگین).';
			case 'cron_not_firing':
				return 'Cron/Worker پس‌زمینه اجرا نمی‌شود؛ هیچ پردازشی صف را جلو نمی‌برد.';
			case 'worker_busy_no_progress':
				return 'Worker فعال است ولی پیشرفتی ثبت نمی‌کند.';
			case 'waiting_backend':
				return 'منتظر در دسترس شدن سرور مرکزی (Backend) است.';
			case 'upgrade_in_progress':
				return 'به‌روزرسانی افزونه در جریان است؛ پس از پایان آن ادامه می‌یابد.';
			case 'max_recoveries':
				return 'بازیابی خودکار چند بار بدون پیشرفت تکرار شد و متوقف شد.';
			case 'no_progress_unknown':
				return 'مدتی است پیشرفتی ثبت نشده و علت مشخصی یافت نشد.';
			default:
				return '' !== (string) $reason ? 'علت: ' . sanitize_key( (string) $reason ) : '';
		}
	}

	/**
	 * Persian one-line status, e.g.
	 *   «در حال اجرا – ۴۵٪ – آخرین پیشرفت ۲ دقیقه پیش»
	 *   «گیر کرده: علت …»
	 */
	public static function status_line_fa( $status, $percent, $diagnosis, $now = null ) {
		$now = null === $now ? time() : absint( $now );
		$health = is_array( $diagnosis ) ? (string) ( $diagnosis['health'] ?? 'ok' ) : 'ok';
		$since = is_array( $diagnosis ) ? absint( $diagnosis['secondsSinceProgress'] ?? 0 ) : 0;
		if ( 'stalled' === $health ) {
			return 'گیر کرده: ' . self::reason_fa( $diagnosis['reason'] ?? '', $diagnosis['itemId'] ?? 0 );
		}
		if ( 'waiting' === $health ) {
			return 'در انتظار: ' . self::reason_fa( $diagnosis['reason'] ?? '' );
		}
		$status = sanitize_key( (string) $status );
		if ( 'running' === $status ) {
			$pct = self::persian_digits( (string) (int) floor( (float) $percent ) );
			return 'در حال اجرا – ' . $pct . '٪ – آخرین پیشرفت ' . self::relative_fa( $since );
		}
		$labels = array(
			'done'        => 'کامل شده',
			'cancelled'   => 'متوقف شده',
			'invalidated' => 'نیازمند اجرای جدید',
			'idle'        => 'آماده',
			'needs_attention' => 'نیازمند بررسی',
			'failed'      => 'ناموفق',
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	public static function relative_fa( $seconds ) {
		$seconds = absint( $seconds );
		if ( $seconds < 60 ) {
			return 'لحظاتی پیش';
		}
		if ( $seconds < 3600 ) {
			return self::persian_digits( (string) (int) floor( $seconds / 60 ) ) . ' دقیقه پیش';
		}
		if ( $seconds < 86400 ) {
			return self::persian_digits( (string) (int) floor( $seconds / 3600 ) ) . ' ساعت پیش';
		}
		return self::persian_digits( (string) (int) floor( $seconds / 86400 ) ) . ' روز پیش';
	}

	public static function persian_digits( $value ) {
		return strtr( (string) $value, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	/** Remove secret/PII-looking keys and clip values. */
	public static function sanitize_context( $context, $depth = 0 ) {
		if ( ! is_array( $context ) || $depth > 3 ) {
			return array();
		}
		$out = array();
		foreach ( $context as $key => $value ) {
			$key_string = (string) $key;
			if ( preg_match( '/token|secret|password|passwd|cookie|nonce|auth|license|api[_-]?key|security[_-]?code|email|phone|mobile|address|billing|shipping_addr|card|iban/i', $key_string ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key_string ] = self::sanitize_context( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$out[ $key_string ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$out[ $key_string ] = self::clip( $value, 300 );
			}
		}
		return $out;
	}

	/** JSON-able support bundle (no secrets). */
	public static function bundle( $job, $status, $diag, $diagnosis, $extra = array() ) {
		return array(
			'job'          => sanitize_key( (string) $job ),
			'generatedAt'  => gmdate( 'c' ),
			'plugin'       => defined( 'MOBO_CORE_VERSION' ) ? (string) MOBO_CORE_VERSION : '',
			'php'          => PHP_VERSION,
			'connectionId' => class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0,
			'status'       => self::sanitize_context( is_array( $status ) ? $status : array() ),
			'diagnosis'    => self::sanitize_context( is_array( $diagnosis ) ? $diagnosis : array() ),
			'diagnostics'  => self::sanitize_context( is_array( $diag ) ? $diag : array() ),
			'extra'        => self::sanitize_context( is_array( $extra ) ? $extra : array() ),
		);
	}

	private static function clip( $value, $max ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max, 'UTF-8' );
		}
		return substr( $value, 0, $max );
	}

	private static function relative_file( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		$pos = strpos( $file, '/mobo-core/' );
		return false === $pos ? basename( $file ) : substr( $file, $pos + 1 );
	}
}
