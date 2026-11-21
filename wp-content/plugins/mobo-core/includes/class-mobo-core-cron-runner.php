<?php
/**
 * Real cron runner.
 *
 * This class is intentionally independent from WP-Cron. It is triggered by a
 * server/cPanel cron that executes mobo-cron.php directly, by the authenticated
 * HTTP fallback (/wp-json/mobo-core/v1/cron/run with the Cron/Worker token in
 * X-SEC), or by WP-CLI/custom integrations. Query-token HTTP authentication is
 * retained only for backward compatibility and is not the preferred setup.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Cron_Runner {

	const SCHEDULE_TOLERANCE_SECONDS = 5;
	const BASELINE_SUSPEND_OPTION = 'mobo_core_test_baseline_suspend_real_cron_until';

	/** @var string Site-wide dispatcher lease token owned by this request. */
	private $dispatcher_token = '';

	/**
	 * Run one bounded cron slice.
	 *
	 * One invocation drains multiple fair queue rounds until the effective time
	 * budget is exhausted, no immediately runnable work remains, or progress
	 * stops. The global runner lease is renewed before every major stage.
	 *
	 * @param string $source Source label.
	 * @param bool   $send_health_report Legacy opt-in push. Portal normally pulls /health.
	 * @param array  $runtime_overrides Optional bounded runtime overrides.
	 * @return array
	 */
	public function run( $source = 'real-cron', $send_health_report = false, $runtime_overrides = array() ) {
		$this->dispatcher_token = isset( $runtime_overrides['dispatcherToken'] ) ? sanitize_text_field( (string) $runtime_overrides['dispatcherToken'] ) : '';
		$suppress_continuation_kick = ! empty( $runtime_overrides['suppressContinuationKick'] );

		$source = sanitize_key( (string) $source );
		$source = '' !== $source ? $source : 'real-cron';
		$scheduler_hit_recorded = ! empty( $runtime_overrides['schedulerHitRecorded'] );

		/* Deterministic WAMP baseline fence. This option is intentionally ignored
		 * outside a clearly local/development site, is time bounded, and is checked
		 * before lastHitAt, locks or queue mutation. */
		if ( self::is_baseline_suspended() ) {
			return array(
				'success'    => true,
				'status'     => 'baseline-suspended',
				'source'     => $source,
				'executedAt' => time(),
				'message'    => 'Real cron is temporarily suspended by the local deterministic test baseline.',
			);
		}

		if ( class_exists( 'Mobo_Core_Settings' ) && method_exists( 'Mobo_Core_Settings', 'prime_runtime_options' ) ) {
			Mobo_Core_Settings::prime_runtime_options();
		}

		if ( self::is_real_scheduler_source( $source ) && ! $scheduler_hit_recorded ) {
			self::record_real_cron_hit();
		}


		/* Database migration is driven by the customer's existing real cron and is no
		 * longer coupled to storefront traffic. One cron invocation advances many
		 * durable bounded batches inside a short wall-clock budget.
		 *
		 * 10.34.77 hardening: migration no longer monopolizes the runner. Once the
		 * structural prerequisites are in place (Migration::normal_runtime_ready():
		 * schema, Connections, ownership, large-runtime cutover, Product Map seed),
		 * a bounded share of this slice advances migration and the ordinary runner
		 * (webhooks, sync, repair, orders) still runs afterwards under the same
		 * runner lease. Only while those prerequisites are genuinely missing does the
		 * slice stay migration-only, and it reports the exact reason. Blocked or
		 * marked retirement objects never stop ordinary work. */
		$migration_mode = 'normal';
		$runtime_blocked_reason = '';
		if ( class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::needs_budgeted_migration_slice() ) {
			$runtime_ready = Mobo_Core_Migration::normal_runtime_ready( $runtime_blocked_reason );
			$migration_mode = class_exists( 'Mobo_Core_Migration_Quarantine' )
				? Mobo_Core_Migration_Quarantine::decide_runner_mode( true, $runtime_ready )
				: ( $runtime_ready ? 'shared' : 'migration-only' );
			$upgrade_barrier_active = class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active();
			if ( 'migration-only' === $migration_mode && ! $upgrade_barrier_active ) {
				$migration = Mobo_Core_Migration::run_budgeted_slice( $source, 12, 200 );
				$progressed = 'progressed' === (string) ( $migration['status'] ?? '' );
				$result = array(
					'success' => ! empty( $migration['success'] ),
					'status' => ! empty( $migration['needsContinuation'] ) ? 'database-migration' : 'database-migration-complete',
					'source' => $source,
					'executedAt' => time(),
					/* Kick an immediate continuation only after real progress; a waiting
					 * or blocked migration resumes on the next scheduled invocation
					 * instead of spinning loopback requests. */
					'needsContinuation' => ! empty( $migration['needsContinuation'] ) && $progressed,
					'runtimeBlockedReason' => $runtime_blocked_reason,
					'databaseMigration' => $migration,
				);
				$this->save_last_result( $result );
				return $result;
			}
		}

		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			/* An administrator-started Unicode conversion intentionally owns the same
			 * global runtime barrier as plugin upgrade work. While that specific
			 * checkpointed workflow is active, Cron may advance exactly one bounded
			 * database-maintenance step instead of starting ordinary Source workers. */
			if ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) && Mobo_Core_Database_Unicode_Migration::should_continue_automatically() ) {
				$migration = Mobo_Core_Database_Unicode_Migration::process_slice( $source );
				$result = array(
					'success'              => ! empty( $migration['success'] ),
					'status'               => ! empty( $migration['success'] ) ? 'database-unicode-maintenance' : 'database-unicode-maintenance-failed',
					'source'               => $source,
					'executedAt'           => time(),
					'needsContinuation'    => ! empty( $migration['needsContinuation'] ),
					'databaseUnicodeMigration' => $migration,
					'upgradeBarrier'       => Mobo_Core_Upgrade_Coordinator::get_status(),
				);
				$this->save_last_result( $result );
				return $result;
			}

			$result               = Mobo_Core_Upgrade_Coordinator::paused_result( 'cron-runner' );
			$result['source']     = $source;
			$result['executedAt'] = time();
			$this->save_last_result( $result );
			return $result;
		}

		if ( class_exists( 'Mobo_Core_Database_Unicode_Migration' ) ) {
			$unicode_migration = Mobo_Core_Database_Unicode_Migration::get_status();
			if ( Mobo_Core_Database_Unicode_Migration::should_block_runtime_lock( 'real_cron_runner' ) ) {
				$result = array(
					'success' => true,
					'status' => 'database-unicode-blocked',
					'source' => $source,
					'executedAt' => time(),
					'needsContinuation' => false,
					'databaseUnicodeMigration' => $unicode_migration,
					'message' => 'Mobo workers remain fail-closed until the explicit database Unicode remediation reaches terminal verification.',
				);
				$this->save_last_result( $result );
				return $result;
			}
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		try {
			$config = $this->get_runtime_config( $runtime_overrides );
			$lock   = Mobo_Core_Lock::acquire( 'real_cron_runner', $config['lockTtlSeconds'] );
		} catch ( Throwable $e ) {
			$result = $this->exception_result( 'lock-exception', $e, array( 'source' => $source ) );
			$this->save_last_result( $result );
			return $result;
		}

		if ( false === $lock ) {
			$result = array(
				'success' => false,
				'status'  => 'locked',
				'source'  => $source,
				'message' => 'Cron runner is already running.',
				'lock'    => Mobo_Core_Lock::get_status( 'real_cron_runner' ),
			);

			$this->save_last_result( $result );
			return $result;
		}

		/*
		 * A fatal error or explicit exit normally still runs shutdown callbacks.
		 * release() verifies the token, so it cannot delete a newer owner's lease.
		 * If shutdown itself is skipped, the finite lease expires automatically.
		 */
		register_shutdown_function( array( 'Mobo_Core_Lock', 'release' ), 'real_cron_runner', $lock );

		/*
		 * Interrupted requests can leave finite product/runtime lease rows behind.
		 * They are already self-healing on the next acquire/status check for the
		 * same name, but a real runner slice is the natural bounded housekeeping
		 * point for reclaiming stale residue proactively. recover_stale_locks()
		 * compare-deletes only the exact malformed/expired payload it inspected and
		 * never shortens or force-releases a healthy live lease. Cleanup failure must
		 * not block queue progress.
		 */
		$stale_lock_recovery = array();
		try {
			if ( method_exists( 'Mobo_Core_Lock', 'recover_stale_locks' ) ) {
				$stale_lock_recovery = Mobo_Core_Lock::recover_stale_locks();
			}
		} catch ( Throwable $e ) {
			$stale_lock_recovery = array(
				'error' => 'lock-recovery-exception',
				'exceptionClass' => get_class( $e ),
			);
		}

		$shared_migration = null;
		try {
			/* Shared mode: advance migration first with a bounded share of the slice,
			 * serialized with ordinary work by the runner lease held here, then hand the
			 * remaining budget to the normal dispatcher. */
			if ( 'shared' === $migration_mode ) {
				$migration_share = class_exists( 'Mobo_Core_Migration_Quarantine' )
					? Mobo_Core_Migration_Quarantine::shared_migration_budget( $config['effectiveTimeBudgetSeconds'] )
					: 4;
				$migration_started = microtime( true );
				try {
					$shared_migration = Mobo_Core_Migration::run_budgeted_slice( $source, $migration_share, 200 );
				} catch ( Throwable $migration_error ) {
					/* Migration failure must never stop ordinary work. */
					$shared_migration = array( 'success' => false, 'status' => 'migration-exception', 'exceptionClass' => get_class( $migration_error ) );
				}
				$spent = (int) ceil( microtime( true ) - $migration_started );
				$config['effectiveTimeBudgetSeconds'] = max( 1, (int) $config['effectiveTimeBudgetSeconds'] - $spent );
			}

			if ( class_exists( 'Mobo_Core_API_Client' ) && method_exists( 'Mobo_Core_API_Client', 'set_runtime_deadline' ) ) {
				Mobo_Core_API_Client::set_runtime_deadline( microtime( true ) + max( 1, (int) $config['effectiveTimeBudgetSeconds'] ), 1.0 );
			}

			$result = $this->run_connection_dispatcher_locked( $source, $lock, $config );
		} catch ( Throwable $e ) {
			$result = $this->exception_result(
				'runner-exception',
				$e,
				array(
					'source' => $source,
					'runner' => array(
						'configuredTimeBudgetSeconds' => $config['configuredTimeBudgetSeconds'],
						'effectiveTimeBudgetSeconds'  => $config['effectiveTimeBudgetSeconds'],
						'lockTtlSeconds'              => $config['lockTtlSeconds'],
					),
				)
			);
		} finally {
			if ( class_exists( 'Mobo_Core_API_Client' ) && method_exists( 'Mobo_Core_API_Client', 'clear_runtime_deadline' ) ) {
				Mobo_Core_API_Client::clear_runtime_deadline();
			}
			$result['lockReleased'] = Mobo_Core_Lock::release( 'real_cron_runner', $lock );
		}

		if ( null !== $shared_migration && is_array( $result ) ) {
			$result['databaseMigration'] = $shared_migration;
			$result['migrationMode'] = 'shared';
			if ( 'progressed' === (string) ( $shared_migration['status'] ?? '' ) && empty( $result['needsContinuation'] ) && ! empty( $result['success'] ) ) {
				$result['needsContinuation'] = true;
			}
		}

		if ( ! empty( $stale_lock_recovery ) ) {
			if ( isset( $stale_lock_recovery['error'] ) ) {
				$result['staleLockRecovery'] = array(
					'status' => 'error',
					'error'  => sanitize_text_field( (string) $stale_lock_recovery['error'] ),
				);
			} else {
				$result['staleLockRecovery'] = array(
					'status'    => 'ok',
					'scanned'   => absint( isset( $stale_lock_recovery['scanned'] ) ? $stale_lock_recovery['scanned'] : 0 ),
					'recovered' => absint( isset( $stale_lock_recovery['recovered'] ) ? $stale_lock_recovery['recovered'] : 0 ),
					'active'    => absint( isset( $stale_lock_recovery['active'] ) ? $stale_lock_recovery['active'] : 0 ),
					'raced'     => absint( isset( $stale_lock_recovery['raced'] ) ? $stale_lock_recovery['raced'] : 0 ),
				);
			}
		}

		/* Connection-aware dispatch flushes diagnostics inside each Connection
		 * context. Retain the legacy aggregate path only when the Connection layer
		 * is unavailable during an old/partial upgrade. */
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' )
			&& ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Context' ) ) ) {
			Mobo_Core_Runtime_Diagnostics::record_runner_result( $result );
			Mobo_Core_Runtime_Diagnostics::flush();
		}

		/*
		 * Continue immediately after a bounded slice when real progress was made
		 * and due work remains. This applies to direct cPanel/PHP cron as well as
		 * the local REST worker, so a full queue does not wait for the next minute.
		 */
		if ( class_exists( 'Mobo_Core_Self_Runner' ) && ! $suppress_continuation_kick ) {
			try {
				if ( Mobo_Core_Self_Runner::should_continue_after_result( $result ) ) {
					$result['continuationKick'] = Mobo_Core_Self_Runner::kick( 'cron-continuation', true );
				} else {
					$result['continuationKick'] = array(
						'success' => true,
						'status'  => 'not-needed',
					);
				}
			} catch ( Throwable $e ) {
				$result['continuationKick'] = $this->exception_result( 'continuation-kick-exception', $e );
			}
		}

		/*
		 * Persist the current runner result before building health payloads so the
		 * report contains this invocation rather than the previous cron slice.
		 */
		$this->save_last_result( $result );

		if ( $send_health_report && class_exists( 'Mobo_Core_Health_Reporter' ) ) {
			try {
				$health_reporter       = new Mobo_Core_Health_Reporter();
				$result['healthReport'] = $health_reporter->send_report( $source );
			} catch ( Throwable $e ) {
				$result['healthReport'] = $this->exception_result( 'health-report-exception', $e );
			}

			/* Only health-report runs need a second write because the first write is
			 * deliberately visible to the health payload being generated above. */
			$this->save_last_result( $result );
		}

		return $result;
	}

	/** Whether the local deterministic test runner currently fences real cron. */
	public static function is_baseline_suspended() {
		if ( ! self::is_local_test_environment() ) {
			return false;
		}
		$until = absint( get_option( self::BASELINE_SUSPEND_OPTION, 0 ) );
		return $until > time();
	}

	/**
	 * Fail closed toward production: the test-only fence is honored only when the
	 * WordPress environment or host is unambiguously local/development.
	 */
	private static function is_local_test_environment() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$type = sanitize_key( (string) wp_get_environment_type() );
			if ( in_array( $type, array( 'local', 'development' ), true ) ) {
				return true;
			}
		}
		$url  = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(?:^|\.)(?:local|test)$/', $host );
	}

	/** Whether a runner source represents the externally scheduled Mobo Cron path. */
	private static function is_real_scheduler_source( $source ) {
		return in_array( sanitize_key( (string) $source ), array( 'real-cron', 'cpanel-local-php-cron' ), true );
	}

	/** Record one real scheduler hit while preserving the previous timestamp. */
	public static function record_real_cron_hit( $timestamp = 0 ) {
		$timestamp = absint( $timestamp );
		if ( $timestamp <= 0 ) { $timestamp = time(); }
		$last = absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) );
		if ( $last > 0 && $timestamp >= $last ) {
			update_option( 'mobo_core_real_cron_previous_hit_at', $last, false );
		}
		update_option( 'mobo_core_real_cron_last_hit_at', $timestamp, false );
	}

	/**
	 * Build a secure cron URL for the current site.
	 *
	 * @return string
	 */
	public static function build_cron_endpoint() {
		return rest_url( 'mobo-core/v1/cron/run' );
	}

	/**
	 * Build the legacy authenticated cron URL.
	 *
	 * Kept for backward compatibility with older integrations. New admin UI does
	 * not render this token-bearing URL; X-SEC is preferred so the secret does not
	 * enter browser history or ordinary access logs.
	 *
	 * @return string
	 */
	public static function build_cron_url() {
		$token = (string) get_option( 'mobo_core_cron_token', '' );

		if ( '' === trim( $token ) ) {
			return '';
		}

		return add_query_arg(
			array( 'token' => rawurlencode( $token ) ),
			self::build_cron_endpoint()
		);
	}

	/**
	 * Get cron status for admin UI and central health checks.
	 *
	 * @return array
	 */
	public static function get_status() {
		$last_hit = absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) );
		$last_ok  = absint( get_option( 'mobo_core_real_cron_last_success_at', 0 ) );
		$last_res = get_option( 'mobo_core_real_cron_last_result', array() );
		$scheduler_res = get_option( 'mobo_core_real_cron_last_scheduler_result', array() );

		if ( ! is_array( $last_res ) ) {
			$last_res = array();
		}
		if ( ! is_array( $scheduler_res ) ) {
			$scheduler_res = array();
		}
		/*
		 * Before UX17 the shared last-success option could be advanced by a
		 * manual/self-runner slice, or by a partial slice. Until this build has
		 * observed at least one result whose provenance is explicitly the real
		 * scheduler, that historical timestamp is not trustworthy as proof of a
		 * healthy scheduled run. Fail closed instead of bootstrapping green health
		 * from legacy data.
		 */
		$has_verified_scheduler_history = ! empty( $scheduler_res )
			&& isset( $scheduler_res['source'] )
			&& self::is_real_scheduler_source( sanitize_key( (string) $scheduler_res['source'] ) );
		$verified_last_ok = $has_verified_scheduler_history ? $last_ok : 0;

		$expected_interval = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_expected_interval_seconds', 60, 60, 3600 );
		$tolerance         = self::SCHEDULE_TOLERANCE_SECONDS;
		$previous_hit      = absint( get_option( 'mobo_core_real_cron_previous_hit_at', 0 ) );
		$last_interval     = $last_hit > 0 && $previous_hit > 0 && $last_hit >= $previous_hit ? $last_hit - $previous_hit : 0;
		$interval_delta    = $last_interval > 0 ? $last_interval - $expected_interval : 0;
		$schedule_status   = 'unknown';
		if ( $last_interval > 0 ) {
			$schedule_status = abs( $interval_delta ) <= $tolerance ? 'on-time' : ( $interval_delta > $tolerance ? 'late' : 'early' );
		}
		$next_estimated_at = $last_hit > 0 ? $last_hit + $expected_interval : 0;
		$is_overdue        = $next_estimated_at > 0 && time() > ( $next_estimated_at + $tolerance );
		$lock_status       = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( 'real_cron_runner' ) : array();

		return array(
			'cronEndpoint'            => self::build_cron_endpoint(),
			'cronUrl'                 => self::build_cron_url(),
			'lastHitAt'               => $last_hit,
			'lastSuccessAt'           => $verified_last_ok,
			'schedulerHistoryVerified'=> $has_verified_scheduler_history,
			'nextEstimatedAt'         => $next_estimated_at,
			'expectedIntervalSeconds' => $expected_interval,
			'scheduleToleranceSeconds'=> $tolerance,
			'previousHitAt'            => $previous_hit,
			'lastIntervalSeconds'      => $last_interval,
			'lastIntervalDeltaSeconds' => $interval_delta,
			'scheduleStatus'           => $schedule_status,
			'isOverdue'               => $is_overdue,
			'secondsSinceLastHit'     => $last_hit > 0 ? max( 0, time() - $last_hit ) : 0,
			'secondsSinceLastSuccess' => $verified_last_ok > 0 ? max( 0, time() - $verified_last_ok ) : 0,
			'isActive'                => $last_hit > 0 && ( time() - $last_hit ) < HOUR_IN_SECONDS,
			'lock'                    => $lock_status,
			'lastResult'              => $last_res,
			'schedulerLastResult'     => $scheduler_res,
		);
	}

	/**
	 * Return scheduler status safe for authenticated remote diagnostics.
	 *
	 * The legacy runnable cronUrl embeds the Cron/Worker secret and must never be
	 * returned through a status endpoint authenticated by a different credential.
	 *
	 * @return array
	 */
	public static function get_external_status() {
		$status = self::get_status();
		unset( $status['cronUrl'] );
		return $status;
	}

	/**
	 * Return a compact, token-free status for the central health report.
	 *
	 * @return array
	 */
	public static function get_health_status() {
		$status = self::get_status();
		$last   = isset( $status['schedulerLastResult'] ) && is_array( $status['schedulerLastResult'] )
			? $status['schedulerLastResult']
			: array();
		$runner = isset( $last['runner'] ) && is_array( $last['runner'] ) ? $last['runner'] : array();
		$scheduler = isset( $last['scheduler'] ) && is_array( $last['scheduler'] ) ? $last['scheduler'] : array();

		return array(
			'pluginVersion'           => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'lastHitAt'               => isset( $status['lastHitAt'] ) ? absint( $status['lastHitAt'] ) : 0,
			'lastSuccessAt'           => isset( $status['lastSuccessAt'] ) ? absint( $status['lastSuccessAt'] ) : 0,
			'expectedIntervalSeconds' => isset( $status['expectedIntervalSeconds'] ) ? absint( $status['expectedIntervalSeconds'] ) : 0,
			'scheduleToleranceSeconds'=> isset( $status['scheduleToleranceSeconds'] ) ? absint( $status['scheduleToleranceSeconds'] ) : 0,
			'lastIntervalSeconds'      => isset( $status['lastIntervalSeconds'] ) ? absint( $status['lastIntervalSeconds'] ) : 0,
			'lastIntervalDeltaSeconds' => isset( $status['lastIntervalDeltaSeconds'] ) ? intval( $status['lastIntervalDeltaSeconds'] ) : 0,
			'scheduleStatus'           => isset( $status['scheduleStatus'] ) ? sanitize_key( (string) $status['scheduleStatus'] ) : 'unknown',
			'isOverdue'               => ! empty( $status['isOverdue'] ),
			'lock'                    => isset( $status['lock'] ) && is_array( $status['lock'] ) ? $status['lock'] : array(),
			'lastRun'                 => array(
				'success'                       => ! empty( $last['success'] ),
				'status'                        => isset( $last['status'] ) ? sanitize_key( (string) $last['status'] ) : 'never-run',
				'source'                        => isset( $last['source'] ) ? sanitize_key( (string) $last['source'] ) : '',
				'executedAt'                    => isset( $last['executedAt'] ) ? absint( $last['executedAt'] ) : 0,
				'needsContinuation'             => ! empty( $last['needsContinuation'] ),
				'rounds'                        => isset( $runner['rounds'] ) ? absint( $runner['rounds'] ) : 0,
				'maxRounds'                     => isset( $runner['maxRounds'] ) ? absint( $runner['maxRounds'] ) : 0,
				'productStepsPerRound'          => isset( $runner['productStepsPerRound'] ) ? absint( $runner['productStepsPerRound'] ) : 0,
				'configuredTimeBudgetSeconds'   => isset( $runner['configuredTimeBudgetSeconds'] ) ? absint( $runner['configuredTimeBudgetSeconds'] ) : 0,
				'effectiveTimeBudgetSeconds'    => isset( $runner['effectiveTimeBudgetSeconds'] ) ? absint( $runner['effectiveTimeBudgetSeconds'] ) : 0,
				'safetyMarginSeconds'           => isset( $runner['safetyMarginSeconds'] ) ? absint( $runner['safetyMarginSeconds'] ) : 0,
				'elapsedMs'                     => isset( $runner['elapsedMs'] ) ? absint( $runner['elapsedMs'] ) : 0,
				'memoryUsageBytes'              => isset( $runner['memoryUsageBytes'] ) ? absint( $runner['memoryUsageBytes'] ) : 0,
				'memoryPeakBytes'               => isset( $runner['memoryPeakBytes'] ) ? absint( $runner['memoryPeakBytes'] ) : 0,
				'memoryLimitBytes'              => isset( $runner['memoryLimitBytes'] ) ? absint( $runner['memoryLimitBytes'] ) : 0,
				'memoryReserveBytes'            => isset( $runner['memoryReserveBytes'] ) ? absint( $runner['memoryReserveBytes'] ) : 0,
				'stopReason'                    => isset( $runner['stopReason'] ) ? sanitize_key( (string) $runner['stopReason'] ) : '',
				'madeProgress'                  => ! empty( $runner['madeProgress'] ),
				'hasImmediateWork'              => ! empty( $runner['hasImmediateWork'] ),
				'lockRenewals'                  => isset( $runner['lockRenewals'] ) ? absint( $runner['lockRenewals'] ) : 0,
				'lockLost'                      => ! empty( $runner['lockLost'] ),
				'configuredLockTtlSeconds'      => isset( $runner['configuredLockTtlSeconds'] ) ? absint( $runner['configuredLockTtlSeconds'] ) : 0,
				'effectiveLockTtlSeconds'       => isset( $runner['effectiveLockTtlSeconds'] ) ? absint( $runner['effectiveLockTtlSeconds'] ) : 0,
				'longestBlockingTimeoutSeconds' => isset( $runner['longestBlockingTimeoutSeconds'] ) ? absint( $runner['longestBlockingTimeoutSeconds'] ) : 0,
				'queuePasses'                   => isset( $runner['queuePasses'] ) && is_array( $runner['queuePasses'] )
					? array_map( 'absint', $runner['queuePasses'] )
					: array(),
				'failedStages'                  => isset( $runner['failedStages'] ) && is_array( $runner['failedStages'] )
					? array_values( array_map( 'sanitize_key', $runner['failedStages'] ) )
					: array(),
				'contendedStages'               => isset( $runner['contendedStages'] ) && is_array( $runner['contendedStages'] )
					? array_values( array_map( 'sanitize_key', $runner['contendedStages'] ) )
					: array(),
				'priorityScheduler'             => array(
					'webhookPressure'    => ! empty( $scheduler['webhookPressure'] ),
					'backgroundCapacity' => isset( $scheduler['backgroundCapacity'] ) ? absint( $scheduler['backgroundCapacity'] ) : 0,
					'selectedStages'     => isset( $scheduler['selectedStages'] ) && is_array( $scheduler['selectedStages'] ) ? array_values( array_map( 'sanitize_key', $scheduler['selectedStages'] ) ) : array(),
					'deferredStages'     => isset( $scheduler['deferredStages'] ) && is_array( $scheduler['deferredStages'] ) ? array_values( array_map( 'sanitize_key', $scheduler['deferredStages'] ) ) : array(),
				),
				'adaptiveBudget'                 => isset( $runner['adaptiveBudget'] ) && is_array( $runner['adaptiveBudget'] ) ? $runner['adaptiveBudget'] : array(),
				'circuitBreakers'                => isset( $runner['circuitBreakers'] ) && is_array( $runner['circuitBreakers'] ) ? $runner['circuitBreakers'] : array(),
			),
		);
	}

	/**
	 * Resolve safe runtime budgets and lease TTL.
	 *
	 * The configured lock TTL is treated as a minimum. The actual lease also
	 * covers the longest configured blocking HTTP request plus recovery grace,
	 * preventing overlap while one request is legitimately still in flight.
	 *
	 * @param array $overrides Optional heartbeat/runtime limits.
	 * @return array
	 */
	private function get_runtime_config( $overrides = array() ) {
		$configured_budget = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_time_budget_seconds', 25, 5, 55 );
		$safety_margin     = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_safety_margin_seconds', 3, 1, 10 );
		$php_limit         = absint( ini_get( 'max_execution_time' ) );
		$effective_budget  = $configured_budget;

		if ( $php_limit > 0 ) {
			$effective_budget = min( $configured_budget, max( 1, $php_limit - $safety_margin ) );
		}

		$api_timeout     = Mobo_Core_Settings::get_int( 'mobo_core_api_request_timeout_seconds', 60, 5, 180 );
		$payload_timeout = Mobo_Core_Settings::get_int( 'mobo_core_payload_pull_timeout_seconds', 60, 5, 180 );

		/* The real-cron lease is installation-global and is acquired before any
		 * Source/Connection context is selected. Checkout timeout is Connection-owned,
		 * so reading it here would accidentally size the global lease from Default (or
		 * from whatever request context happened to exist). Use the allowed upper bound
		 * instead: the lease must safely cover every enabled Connection. */
		$checkout_timeout = 20;
		$blocking_timeout = max( 15, $api_timeout, $payload_timeout, $checkout_timeout );
		$configured_ttl   = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_lock_ttl_seconds', 120, 30, 600 );
		$lock_ttl         = min( 600, max( $configured_ttl, $effective_budget + 30, $blocking_timeout + 30 ) );

		$max_rounds              = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_max_rounds', 100, 1, 500 );
		$product_steps_per_round = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_max_sync_steps', 3, 1, 20 );
		$memory_limit_bytes      = $this->get_memory_limit_bytes();
		$memory_reserve_mb       = Mobo_Core_Settings::get_int( 'mobo_core_real_cron_memory_reserve_mb', 16, 8, 128 );
		$memory_reserve_bytes    = $memory_reserve_mb * 1024 * 1024;

		if ( is_array( $overrides ) ) {
			if ( isset( $overrides['maxTimeBudgetSeconds'] ) ) {
				$override_budget  = max( 3, min( 55, absint( $overrides['maxTimeBudgetSeconds'] ) ) );
				$configured_budget = min( $configured_budget, $override_budget );
				$effective_budget  = min( $effective_budget, $override_budget );
			}

			if ( isset( $overrides['maxRounds'] ) ) {
				$max_rounds = max( 1, min( 500, absint( $overrides['maxRounds'] ) ) );
			}

			if ( isset( $overrides['productStepsPerRound'] ) ) {
				$product_steps_per_round = max( 1, min( 20, absint( $overrides['productStepsPerRound'] ) ) );
			}
		}

		$lock_ttl = min( 600, max( $configured_ttl, $effective_budget + 30, $blocking_timeout + 30 ) );

		return array(
			'configuredTimeBudgetSeconds' => $configured_budget,
			'effectiveTimeBudgetSeconds'  => $effective_budget,
			'safetyMarginSeconds'         => $safety_margin,
			'maxRounds'                   => $max_rounds,
			'productStepsPerRound'        => $product_steps_per_round,
			'lockTtlSeconds'              => $lock_ttl,
			'configuredLockTtlSeconds'    => $configured_ttl,
			'longestBlockingTimeout'      => $blocking_timeout,
			'memoryLimitBytes'            => $memory_limit_bytes,
			'memoryReserveBytes'          => $memory_reserve_bytes,
		);
	}

	/**
	 * Run Source-owned queues under explicit Connection contexts while retaining
	 * one installation-wide real-cron lease. A round-robin cursor prevents a
	 * consistently busy Default Connection from starving later Sources.
	 *
	 * Single-Connection installs use the exact same runtime path inside the Default
	 * Connection context. Multi-Connection remains fail-closed until the ownership
	 * migration publishes its durable readiness proof.
	 */
	private function run_connection_dispatcher_locked( $source, $lock_token, $config ) {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! class_exists( 'Mobo_Core_Connection_Context' ) ) {
			return $this->run_locked( $source, $lock_token, $config );
		}

		$connections = Mobo_Core_Connection_Repository::list_enabled();
		if ( empty( $connections ) ) {
			return array(
				'success' => false,
				'status' => 'connection-missing',
				'source' => $source,
				'executedAt' => time(),
				'message' => 'No enabled Mobo Connection is available for cron dispatch.',
			);
		}
		if ( count( $connections ) > 1 && ! Mobo_Core_Connection_Repository::multi_connection_runtime_ready() ) {
			return array(
				'success' => false,
				'status' => 'multi-connection-not-ready',
				'source' => $source,
				'executedAt' => time(),
				'message' => 'Multi-Connection ownership migration is not complete.',
			);
		}

		$cursor_id = absint( get_option( 'mobo_core_connection_dispatch_cursor_id', 0 ) );
		$start = 0;
		if ( count( $connections ) > 1 && $cursor_id > 0 ) {
			foreach ( $connections as $index => $connection ) {
				if ( absint( $connection['id'] ?? 0 ) === $cursor_id ) {
					$start = ( $index + 1 ) % count( $connections );
					break;
				}
			}
		}
		if ( $start > 0 ) {
			$connections = array_merge( array_slice( $connections, $start ), array_slice( $connections, 0, $start ) );
		}

		$started_at = microtime( true );
		$deadline = $started_at + max( 1, (int) $config['effectiveTimeBudgetSeconds'] );
		$results = array();
		$overall_success = true;
		$needs_continuation = false;
		$made_progress = false;
		$last_processed_id = 0;
		$dispatcher_renewals = 0;
		$dispatcher_lock_lost = false;
		$total = count( $connections );

		/* A separate warmup turn prevents the general Connection dispatch cursor
		 * from letting a high-volume Source consume every spare GET. Only due
		 * Connections compete, and selection rotates after its own last turn. */
		$warmup_turn_id = 0;
		if ( $total > 1 && class_exists( 'Mobo_Core_Cache_Warmer' ) ) {
			$last_warmup_id = absint( get_option( 'mobo_core_cache_warmup_dispatch_cursor_id', 0 ) );
			$warmup_start = 0;
			foreach ( $connections as $idx => $candidate ) {
				if ( absint( $candidate['id'] ?? 0 ) === $last_warmup_id ) {
					$warmup_start = ( $idx + 1 ) % $total;
					break;
				}
			}
			for ( $probe = 0; $probe < $total; $probe++ ) {
				$candidate = $connections[ ( $warmup_start + $probe ) % $total ];
				$candidate_id = absint( $candidate['id'] ?? 0 );
				if ( $candidate_id <= 0 ) { continue; }
				$due = Mobo_Core_Connection_Context::run( $candidate_id, static function () {
					return Mobo_Core_Cache_Warmer::has_due_work();
				} );
				if ( $due ) { $warmup_turn_id = $candidate_id; break; }
			}
			/* UX60: do not advance this cursor until the selected Connection
			 * actually enters its warmup stage. It may be skipped because earlier
			 * foreground work consumed the entire request budget. */
		}

		foreach ( $connections as $index => $connection ) {
			$connection_id = absint( $connection['id'] ?? 0 );
			if ( $connection_id <= 0 ) { continue; }
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0.25 ) {
				$needs_continuation = true;
				break;
			}
			$left = max( 1, $total - $index );
			$connection_config = $config;
			$connection_config['effectiveTimeBudgetSeconds'] = max( 1, min( (int) ceil( $remaining ), (int) max( 1, floor( $remaining / $left ) ) ) );
			$connection_config['maxRounds'] = max( 1, min( absint( $config['maxRounds'] ), (int) max( 1, ceil( absint( $config['maxRounds'] ) / $total ) ) ) );
			if ( $total > 1 ) {
				$connection_config['ux53MultiConnectionWarmup'] = true;
				$connection_config['ux53WarmupSelected'] = $connection_id === $warmup_turn_id && $warmup_turn_id > 0;
			}

			/* UX60: the prior Source slice may have spent its entire TTL in a
			 * blocking API call or diagnostic flush. Verify the installation-global
			 * owner again before entering a *different* Source's mutation pipeline. */
			if ( ! $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $dispatcher_renewals ) ) {
				$dispatcher_lock_lost = true;
				$overall_success = false;
				$needs_continuation = true;
				break;
			}

			$result = Mobo_Core_Connection_Context::run( $connection_id, function () use ( $source, $lock_token, $connection_config ) {
				if ( class_exists( 'Mobo_Core_API_Client' ) && method_exists( 'Mobo_Core_API_Client', 'clear_request_context' ) ) {
					Mobo_Core_API_Client::clear_request_context();
				}
				$connection_result = $this->run_locked( $source, $lock_token, $connection_config );
				if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
					Mobo_Core_Runtime_Diagnostics::record_runner_result( $connection_result );
					Mobo_Core_Runtime_Diagnostics::flush();
				}
				return $connection_result;
			} );
			$results[] = array(
				'connectionId' => $connection_id,
				'sourceKey' => sanitize_key( (string) ( $connection['source_key'] ?? '' ) ),
				'displayName' => sanitize_text_field( (string) ( $connection['display_name'] ?? '' ) ),
				'result' => $result,
			);
			$last_processed_id = $connection_id;
			$overall_success = $overall_success && ! empty( $result['success'] );
			$needs_continuation = $needs_continuation || ! empty( $result['needsContinuation'] );
			$made_progress = $made_progress || ! empty( $result['runner']['madeProgress'] );
			if ( ! empty( $result['runner']['lockLost'] ) || 'lock-lost' === ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
				/* An inner slice surrendered the global lease. Never dispatch the next
				 * Connection in the same PHP process after another runner may own it. */
				$dispatcher_lock_lost = true;
				$overall_success = false;
				$needs_continuation = true;
				break;
			}
		}

		if ( $last_processed_id > 0 ) {
			update_option( 'mobo_core_connection_dispatch_cursor_id', $last_processed_id, false );
		}
		/* UX60: processing only the first of several configured Sources because
		 * the budget expired (or the lease was lost) is NOT a single-Connection
		 * installation. Preserve the aggregate continuation/error evidence. */
		if ( 1 === $total && 1 === count( $results ) && ! $dispatcher_lock_lost ) {
			$single = $results[0]['result'];
			$single['connectionId'] = $results[0]['connectionId'];
			$single['sourceKey'] = $results[0]['sourceKey'];
			return $single;
		}

		return array(
			'success' => $overall_success,
			'status' => $dispatcher_lock_lost ? 'lock-lost' : ( $overall_success ? 'ok' : 'partial' ),
			'source' => $source,
			'executedAt' => time(),
			'needsContinuation' => $needs_continuation,
			'connections' => $results,
			'runner' => array(
				'multiConnection' => true,
				'dispatcherLockLost' => $dispatcher_lock_lost,
				'lockRenewals' => $dispatcher_renewals,
				'connectionCount' => count( $results ),
				'madeProgress' => $made_progress,
				'elapsedMs' => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
			),
			'message' => 'Cron queue drain completed across enabled Mobo Connections.',
		);
	}

	/**
	 * Drain fair queue rounds while the runner lease is held.
	 *
	 * @param string $source Source.
	 * @param string $lock_token Lock owner token.
	 * @param array  $config Runtime configuration.
	 * @return array
	 */
	private function run_locked( $source, $lock_token, $config ) {
		$started_at      = microtime( true );
		$deadline        = $started_at + max( 1, (int) $config['effectiveTimeBudgetSeconds'] );
		$rounds          = 0;
		$lock_renewals   = 0;
		$lock_lost       = false;
		$upgrade_paused  = false;
		$stop_reason     = 'queues-empty-or-deferred';
		$made_progress   = false;
		$immediate_work  = false;
		$disabled_stages = array();
		$aggregate       = $this->empty_aggregate_result( $source );

		/* Build one immutable self-tuning profile for this invocation. Configured
		 * values remain the baseline; only request-local execution limits change. */
		$adaptive_tuning = class_exists( 'Mobo_Core_Adaptive_Tuner' )
			? Mobo_Core_Adaptive_Tuner::build_profile( $config )
			: array();
		if ( ! empty( $adaptive_tuning['limits']['productStepsPerRound'] ) ) {
			$config['productStepsPerRound'] = max( 1, min( 20, absint( $adaptive_tuning['limits']['productStepsPerRound'] ) ) );
		}
		$config['adaptiveTuning'] = $adaptive_tuning;

		/* Capture queue pressure once. The table-backed summaries are request-cached,
		 * and later rounds use their own stage results rather than issuing repeated
		 * COUNT/existence probes. */
		$queue_snapshot = $this->build_queue_snapshot();
		$adaptive_budget = class_exists( 'Mobo_Core_Adaptive_Budget' )
			? Mobo_Core_Adaptive_Budget::build_profile( $config, $adaptive_tuning, $queue_snapshot )
			: array();
		$circuit_breakers = class_exists( 'Mobo_Core_Circuit_Breaker' )
			? Mobo_Core_Circuit_Breaker::build_profile()
			: array();
		$config['queueSnapshot']    = $queue_snapshot;
		$config['adaptiveBudget']   = $adaptive_budget;
		$config['circuitBreakers']  = $circuit_breakers;

		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			if ( ! empty( $adaptive_tuning ) ) {
				Mobo_Core_Runtime_Diagnostics::record_adaptive_profile( $adaptive_tuning );
			}
			if ( ! empty( $adaptive_budget ) && method_exists( 'Mobo_Core_Runtime_Diagnostics', 'record_budget_profile' ) ) {
				Mobo_Core_Runtime_Diagnostics::record_budget_profile( $adaptive_budget );
			}
			if ( ! empty( $circuit_breakers ) && method_exists( 'Mobo_Core_Runtime_Diagnostics', 'record_circuit_profile' ) ) {
				Mobo_Core_Runtime_Diagnostics::record_circuit_profile( $circuit_breakers );
			}
		}

		/* Deferred category/tag/shop/home page-cache invalidation is driven by the real Mobo runner. */
		if ( class_exists( 'Mobo_Core_Cache_Purger' ) && method_exists( 'Mobo_Core_Cache_Purger', 'process_due_archive_purge' ) && $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $lock_renewals ) ) {
			/* UX60: snapshot collection can be expensive; do not start the first
			 * mutating archive purge without revalidating the outer runner owner. */
			try {
				$aggregate['archiveCachePurge'] = Mobo_Core_Cache_Purger::process_due_archive_purge( $source );
			} catch ( Throwable $e ) {
				$aggregate['archiveCachePurge'] = $this->exception_result( 'archive-cache-purge-exception', $e );
			}
		}

		while ( $rounds < absint( $config['maxRounds'] ) ) {
			if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
				$upgrade_paused = true;
				$stop_reason    = 'plugin-upgrade-barrier';
				break;
			}

			if ( ! $this->has_time_remaining( $deadline, $config['safetyMarginSeconds'] ) ) {
				$stop_reason = 'time-budget-exhausted';
				break;
			}

			if ( ! $this->has_memory_headroom( $config ) ) {
				$stop_reason = 'memory-pressure';
				break;
			}

			if ( ! $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $lock_renewals ) ) {
				$lock_lost   = true;
				$stop_reason = 'lock-lost';
				break;
			}

			$rounds++;
			$round = $this->run_one_round(
				$source,
				$rounds,
				$deadline,
				$config,
				$lock_token,
				$lock_renewals,
				$disabled_stages
			);

			$this->merge_round_result( $aggregate, $round );
			if ( ! empty( $config['ux53MultiConnectionWarmup'] ) && ! empty( $round['queuePasses']['cacheWarmup'] ) ) {
				$config['ux53WarmupTurnConsumed'] = true; // At most one warmup stage/GET in this Connection slice.
			}
			$made_progress  = $made_progress || ! empty( $round['madeProgress'] );
			$immediate_work = ! empty( $round['hasImmediateWork'] );

			if ( ! empty( $round['lockLost'] ) ) {
				$lock_lost   = true;
				$stop_reason = 'lock-lost';
				break;
			}

			if ( ! empty( $round['upgradePaused'] ) ) {
				$upgrade_paused = true;
				$stop_reason    = 'plugin-upgrade-barrier';
				break;
			}

			if ( ! empty( $round['deadlineReached'] ) || ! $this->has_time_remaining( $deadline, $config['safetyMarginSeconds'] ) ) {
				$stop_reason = 'time-budget-exhausted';
				break;
			}

			if ( ! empty( $round['memoryPressure'] ) || ! $this->has_memory_headroom( $config ) ) {
				$stop_reason = 'memory-pressure';
				break;
			}

			if ( empty( $round['madeProgress'] ) ) {
				$stop_reason = $immediate_work ? 'no-progress' : 'queues-empty-or-deferred';
				break;
			}

			if ( ! $immediate_work ) {
				$stop_reason = 'queues-empty-or-deferred';
				break;
			}
		}

		if ( ! $upgrade_paused && $rounds >= absint( $config['maxRounds'] ) && $immediate_work ) {
			$stop_reason = 'max-rounds-reached';
		}

		/*
		 * Health/read-cache refresh is owned by the same deterministic real cron as
		 * every other Mobo background stage. Run it only from leftover budget so
		 * webhook/product/image work keeps priority and WP-Cron is never required.
		 */
		if ( ! $lock_lost && ! $upgrade_paused && class_exists( 'Mobo_Core_Health_Read_Cache' )
			&& method_exists( 'Mobo_Core_Health_Read_Cache', 'refresh_due_from_real_cron' ) ) {
			if ( $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $lock_renewals ) ) {
				try {
					$aggregate['healthSnapshots'] = Mobo_Core_Health_Read_Cache::refresh_due_from_real_cron( $deadline );
				} catch ( Throwable $e ) {
					$aggregate['healthSnapshots'] = $this->exception_result( 'health-snapshot-refresh-exception', $e );
				}
			} else {
				$lock_lost   = true;
				$stop_reason = 'lock-lost';
			}
		}

		$elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

		/*
		 * Auto-chain only when progress was made. A no-progress/locked/deferred
		 * queue waits for the next real cron and cannot create a tight loop.
		 */
		$needs_continuation = ! $lock_lost
			&& ! $upgrade_paused
			&& $made_progress
			&& (
				$immediate_work
				|| in_array( $stop_reason, array( 'time-budget-exhausted', 'memory-pressure', 'max-rounds-reached' ), true )
			);

		$aggregate['success']           = ! $lock_lost;
		$aggregate['status']            = $upgrade_paused
			? 'paused-for-upgrade'
			: ( $lock_lost ? 'lock-lost' : ( empty( $aggregate['runnerErrors'] ) ? 'ok' : 'partial' ) );
		$aggregate['executedAt']        = time();
		$aggregate['needsContinuation'] = $needs_continuation;
		$aggregate['message']           = $upgrade_paused
			? 'Cron processing reached a safe boundary and paused for plugin upgrade.'
			: ( $lock_lost
				? 'Cron processing stopped because the runner lease was lost.'
				: 'Cron queue drain slice completed.' );
		if ( $upgrade_paused && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
			$aggregate['upgradeBarrier'] = Mobo_Core_Upgrade_Coordinator::get_status();
		}
		$aggregate['runner']            = array(
			'pluginVersion'                   => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '',
			'configuredTimeBudgetSeconds'     => absint( $config['configuredTimeBudgetSeconds'] ),
			'effectiveTimeBudgetSeconds'      => absint( $config['effectiveTimeBudgetSeconds'] ),
			'safetyMarginSeconds'             => absint( $config['safetyMarginSeconds'] ),
			'elapsedMs'                       => $elapsed_ms,
			'memoryUsageBytes'                => function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0,
			'memoryPeakBytes'                 => function_exists( 'memory_get_peak_usage' ) ? memory_get_peak_usage( true ) : 0,
			'memoryLimitBytes'                => absint( $config['memoryLimitBytes'] ),
			'memoryReserveBytes'              => absint( $config['memoryReserveBytes'] ),
			'rounds'                          => $rounds,
			'maxRounds'                       => absint( $config['maxRounds'] ),
			'productStepsPerRound'            => absint( $config['productStepsPerRound'] ),
			'stopReason'                      => $stop_reason,
			'madeProgress'                    => $made_progress,
			'hasImmediateWork'                => $immediate_work,
			'lockLost'                        => $lock_lost,
			'upgradePaused'                    => $upgrade_paused,
			'lockRenewals'                    => $lock_renewals,
			'configuredLockTtlSeconds'        => absint( $config['configuredLockTtlSeconds'] ),
			'effectiveLockTtlSeconds'         => absint( $config['lockTtlSeconds'] ),
			'longestBlockingTimeoutSeconds'   => absint( $config['longestBlockingTimeout'] ),
			'failedStages'                    => array_values( array_keys( array_filter( $disabled_stages, static function ( $reason ) { return 'contended' !== $reason; } ) ) ),
			'contendedStages'                 => isset( $aggregate['contendedStages'] ) ? array_values( array_unique( array_map( 'sanitize_key', $aggregate['contendedStages'] ) ) ) : array(),
			'queuePasses'                     => $aggregate['queuePasses'],
			'adaptiveTuning'                  => isset( $config['adaptiveTuning'] ) && is_array( $config['adaptiveTuning'] ) ? $config['adaptiveTuning'] : array(),
			'adaptiveBudget'                  => isset( $config['adaptiveBudget'] ) && is_array( $config['adaptiveBudget'] ) ? $config['adaptiveBudget'] : array(),
			'circuitBreakers'                 => isset( $config['circuitBreakers'] ) && is_array( $config['circuitBreakers'] ) ? $config['circuitBreakers'] : array(),
		);

		/*
		 * "Last success" must mean a fully healthy runner slice. A partial slice can
		 * retain success=true for transport/backward-compatibility semantics while
		 * still containing failed stages; do not let that overwrite healthy history.
		 */
		if ( 'ok' === $aggregate['status'] && self::is_real_scheduler_source( $source ) ) {
			update_option( 'mobo_core_real_cron_last_success_at', time(), false );
		}

		return $aggregate;
	}

	/**
	 * Run one fair pass over all queue families.
	 *
	 * @param string $source Source.
	 * @param int    $round_number Round number.
	 * @param float  $deadline Absolute microtime deadline.
	 * @param array  $config Runtime config.
	 * @param string $lock_token Lock token.
	 * @param int    $lock_renewals Renewal counter by reference.
	 * @param array  $disabled_stages Stages disabled after an exception by reference.
	 * @return array
	 */
	private function run_one_round( $source, $round_number, $deadline, $config, $lock_token, &$lock_renewals, &$disabled_stages ) {
		$round = $this->empty_round_result();

		/* Webhook queue. */
		if ( Mobo_Core_Settings::enabled( 'mobo_core_real_cron_process_webhooks', '1' ) && ! isset( $disabled_stages['webhookQueue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$remaining_seconds = max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) );
			$webhook_default_budget = Mobo_Core_Settings::get_int( 'mobo_core_sync_time_budget_seconds', 8, 2, 25 );
			$webhook_budget = min(
				$remaining_seconds,
				$this->stage_budget_seconds( $config, 'webhookQueue', $webhook_default_budget, 1, 25 )
			);
			$webhook_ceiling = isset( $config['adaptiveTuning']['limits']['webhookQueue'] ) ? max( 1, min( 10, absint( $config['adaptiveTuning']['limits']['webhookQueue'] ) ) ) : Mobo_Core_Settings::get_int( 'mobo_core_webhook_files_per_run', 4, 1, 10 );
			$webhook_ceiling = $this->stage_capacity_limit( $config, 'webhookQueue', $webhook_ceiling );
			$round['webhookQueue'] = $this->execute_stage(
				'webhookQueue',
				function () use ( $webhook_budget, $webhook_ceiling ) {
					$queue = new Mobo_Core_Webhook_Queue();
					return $queue->process( $webhook_budget, null, $webhook_ceiling );
				},
				array( 'processed' => 0, 'failed' => 1, 'status' => 'exception', 'remainingFile' => true, 'remainingTable' => true, 'remainingDueTable' => false ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['webhookQueue']++;
		}

		/*
		 * Re-check pressure without another table query. The queue processor already
		 * reports due table work; legacy files use a filesystem-only existence check.
		 */
		$webhook_due_pressure = ! empty( $round['webhookQueue']['remainingDueTable'] );
		if ( ! $webhook_due_pressure && ! isset( $disabled_stages['webhookQueue'] ) && class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
			try {
				$pressure_queue = new Mobo_Core_Webhook_Queue();
				$webhook_due_pressure = method_exists( $pressure_queue, 'has_legacy_files' ) && $pressure_queue->has_legacy_files();
			} catch ( Throwable $e ) {
				$webhook_due_pressure = false;
			}
		}

		/* Weighted fair scheduling chooses a bounded set of background stages.
		 * Critical webhook/order/product lanes remain outside this competition. */
		$active_stages = $this->round_active_stages( $round_number, $config, $round );
		$scheduler = class_exists( 'Mobo_Core_Fair_Scheduler' )
			? Mobo_Core_Fair_Scheduler::decide(
				$round_number,
				$active_stages,
				isset( $config['adaptiveBudget'] ) ? $config['adaptiveBudget'] : array(),
				isset( $config['circuitBreakers'] ) ? $config['circuitBreakers'] : array(),
				$webhook_due_pressure
			)
			: array( 'allow' => array(), 'selectedStages' => array(), 'deferredStages' => array(), 'webhookPressure' => $webhook_due_pressure );

		$allow = isset( $scheduler['allow'] ) && is_array( $scheduler['allow'] ) ? $scheduler['allow'] : array();
		$allow_parent_finalize = ! empty( $allow['parentFinalize'] );
		$allow_image_queue     = ! empty( $allow['imageQueue'] );
		$allow_reprice_queue   = ! empty( $allow['repriceQueue'] );
		$allow_recategorize    = ! empty( $allow['recategorizeQueue'] );
		$allow_image_refresh   = ! empty( $allow['imageRefreshQueue'] );
		$allow_cache_warmup    = ! empty( $allow['cacheWarmup'] );
		if ( ! empty( $config['ux53MultiConnectionWarmup'] ) ) {
			$allow_cache_warmup = $allow_cache_warmup && ! empty( $config['ux53WarmupSelected'] ) && empty( $config['ux53WarmupTurnConsumed'] );
		}
		$allow_maintenance     = ! empty( $allow['maintenance'] );

		$round['scheduler'] = $scheduler;
		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			Mobo_Core_Runtime_Diagnostics::record_scheduler_decision( $round_number, $round['scheduler'], $webhook_due_pressure ? 1 : 0 );
		}

		/*
		 * Customer order submission is a foreground business operation. Run it
		 * immediately after the webhook pass instead of after all sync/background
		 * queues. With enough budget, process two orders; otherwise stay at one.
		 */
		if ( Mobo_Core_Order_Submission_Policy::is_enabled() && class_exists( 'Mobo_Core_Checkout_Validator' ) && ! isset( $disabled_stages['orderSubmissions'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$order_remaining = max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) );
			$order_budget    = min( $order_remaining, $this->stage_budget_seconds( $config, 'orderSubmissions', 4, 1, 12 ) );
			$order_limit     = ( $order_remaining >= 18 && $order_budget >= 3 ) ? 2 : 1;
			$order_limit     = $this->stage_capacity_limit( $config, 'orderSubmissions', $order_limit );
			$round['orderSubmissions'] = $this->execute_stage(
				'orderSubmissions',
				function () use ( $source, $order_limit ) {
					$validator = new Mobo_Core_Checkout_Validator();
					return $validator->process_queued_mobo_order_submissions( $order_limit, $source );
				},
				array( 'status' => 'exception', 'processed' => 0, 'success' => 0, 'failed' => 1, 'skipped' => 0, 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['orderSubmissions']++;
		}


		/*
		 * Coalesced variable-parent finalization.
		 *
		 * If immediately due webhook work still exists, keep draining desired-state
		 * events first so several UpdateVariant rows for the same parent collapse into
		 * one parent sync/purge. Once the due webhook backlog is clear, finalize each
		 * touched parent once before lower-priority image/cache work.
		 */
		if ( $this->option_queue_has_items( 'mobo_core_parent_finalize_queue' ) && class_exists( 'Mobo_Core_Parent_Finalize_Queue' ) && ! isset( $disabled_stages['parentFinalize'] ) ) {
			$webhook_pressure = $webhook_due_pressure;

			if ( $this->stage_circuit_open( $config, 'parentFinalize' ) ) {
				$parent_status = Mobo_Core_Parent_Finalize_Queue::get_status();
				$round['parentFinalize'] = array(
					'success'      => true,
					'status'       => 'circuit-open',
					'processed'    => 0,
					'finalized'    => 0,
					'dropped'      => 0,
					'failed'       => 0,
					'remaining'    => ! empty( $parent_status['hasPending'] ),
					'remainingDue' => false,
					'pendingCount' => isset( $parent_status['pendingCount'] ) ? absint( $parent_status['pendingCount'] ) : 0,
				);
			} elseif ( ! $allow_parent_finalize ) {
				$parent_status = Mobo_Core_Parent_Finalize_Queue::get_status();
				$round['parentFinalize'] = array(
					'success'      => true,
					'status'       => $webhook_pressure ? 'deferred-for-webhook-pressure' : 'deferred-by-fair-scheduler',
					'processed'    => 0,
					'finalized'    => 0,
					'dropped'      => 0,
					'failed'       => 0,
					'remaining'    => ! empty( $parent_status['hasPending'] ),
					'remainingDue' => false,
					'pendingCount' => isset( $parent_status['pendingCount'] ) ? absint( $parent_status['pendingCount'] ) : 0,
				);
			} elseif ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$parent_configured = Mobo_Core_Settings::get_int( 'mobo_core_parent_finalize_batch_size', 10, 1, 50 );
				$parent_tuned = isset( $config['adaptiveTuning']['limits']['parentFinalize'] ) ? absint( $config['adaptiveTuning']['limits']['parentFinalize'] ) : $parent_configured;
				$parent_limit  = $webhook_pressure ? 1 : max( 1, min( 50, $parent_tuned ) );
				$parent_limit  = $this->stage_capacity_limit( $config, 'parentFinalize', $parent_limit );
				$parent_budget = $webhook_pressure ? 2 : $this->stage_budget_seconds( $config, 'parentFinalize', 6, 1, 8 );
				$parent_budget = min( $parent_budget, max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) ) );
				$round['parentFinalize'] = $this->execute_stage(
					'parentFinalize',
					function () use ( $parent_limit, $parent_budget ) {
						return Mobo_Core_Parent_Finalize_Queue::process_batch( $parent_limit, $parent_budget );
					},
					array( 'success' => false, 'status' => 'exception', 'processed' => 0, 'finalized' => 0, 'dropped' => 0, 'failed' => 1, 'remaining' => true, 'remainingDue' => true, 'pendingCount' => 0 ),
					$disabled_stages,
					$round['stageErrors']
				);
				$round['queuePasses']['parentFinalize']++;
			}
		}

		/*
		 * Explicit admin maintenance queues run before image/product background work.
		 *
		 * These jobs are finite, user-requested operations. Keeping them behind the
		 * image lane allowed a busy image backlog to consume the runner deadline on
		 * every request, so a selected reprice/recategorize stage could repeatedly
		 * receive no execution time. Product-level locks still serialize them against
		 * webhook/manual sync writes.
		 */
		/* Reprice queue. */
		$reprice_active = $this->option_state_is_running( 'mobo_core_reprice_state' );
		if ( $reprice_active && class_exists( 'Mobo_Core_Reprice_Queue' ) && ! isset( $disabled_stages['repriceQueue'] ) && $allow_reprice_queue && ! $this->stage_circuit_open( $config, 'repriceQueue' ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$reprice_budget = $webhook_due_pressure ? 2 : $this->stage_budget_seconds( $config, 'repriceQueue', 7, 1, 10 );
			$reprice_budget = min( $reprice_budget, max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) ) );
			$reprice_base   = Mobo_Core_Settings::get_int( 'mobo_core_reprice_batch_size', 20, 1, 200 );
			$reprice_limit  = isset( $config['adaptiveTuning']['limits']['repriceQueue'] ) ? max( 1, min( 200, absint( $config['adaptiveTuning']['limits']['repriceQueue'] ) ) ) : $reprice_base;
			$reprice_limit  = $this->stage_capacity_limit( $config, 'repriceQueue', $reprice_limit );
			$round['repriceQueue'] = $this->execute_stage(
				'repriceQueue',
				function () use ( $reprice_limit, $reprice_budget ) {
					$queue = new Mobo_Core_Reprice_Queue();
					return $queue->process_batch( $reprice_limit, $reprice_budget );
				},
				array( 'processed' => 0, 'updated' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['repriceQueue']++;
		}

		/* Recategorize queue. */
		$recategorize_active = $this->option_state_is_running( 'mobo_core_recategorize_state' );
		if ( $recategorize_active && class_exists( 'Mobo_Core_Recategorize_Queue' ) && ! isset( $disabled_stages['recategorizeQueue'] ) && $allow_recategorize && ! $this->stage_circuit_open( $config, 'recategorizeQueue' ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$recategorize_budget = $webhook_due_pressure ? 2 : $this->stage_budget_seconds( $config, 'recategorizeQueue', 7, 1, 10 );
			$recategorize_budget = min( $recategorize_budget, max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) ) );
			$recategorize_base   = Mobo_Core_Settings::get_int( 'mobo_core_recategorize_batch_size', 20, 1, 200 );
			$recategorize_limit  = isset( $config['adaptiveTuning']['limits']['recategorizeQueue'] ) ? max( 1, min( 200, absint( $config['adaptiveTuning']['limits']['recategorizeQueue'] ) ) ) : $recategorize_base;
			$recategorize_limit  = $this->stage_capacity_limit( $config, 'recategorizeQueue', $recategorize_limit );
			$round['recategorizeQueue'] = $this->execute_stage(
				'recategorizeQueue',
				function () use ( $recategorize_limit, $recategorize_budget ) {
					$queue = new Mobo_Core_Recategorize_Queue();
					return $queue->process_batch( $recategorize_limit, $recategorize_budget );
				},
				array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['recategorizeQueue']++;
		}

		/* Product image queue + bounded stale-media prune queue. */
		$image_sync             = null;
		$base_image_limit       = max( 1, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 1, 10 ) );
		$configured_image_limit = isset( $config['adaptiveTuning']['limits']['imageQueue'] )
			? max( 1, min( 10, absint( $config['adaptiveTuning']['limits']['imageQueue'] ) ) )
			: $base_image_limit;
		$image_limit            = $configured_image_limit;
		$image_import_enabled   = Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' ) && class_exists( 'Mobo_Core_Image_Sync' );
		$prune_due              = false;
		if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) && Mobo_Core_Image_Prune_Queue::table_exists() ) {
			try {
				$prune_probe = new Mobo_Core_Image_Prune_Queue();
				$prune_due   = $prune_probe->has_due_work();
			} catch ( Throwable $e ) {
				$prune_due = false;
			}
		}

		$image_circuit_open = $this->stage_circuit_open( $config, 'imageQueue' );
		if ( ( $prune_due || ( $image_import_enabled && ! $image_circuit_open ) ) && ! isset( $disabled_stages['imageQueue'] ) && $allow_image_queue && empty( $round['parentFinalize']['remainingDue'] ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$image_remaining = max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) );
			$image_budget    = min( $image_remaining, $this->stage_budget_seconds( $config, 'imageQueue', 6, 1, 12 ) );
			$image_limit     = $webhook_due_pressure ? 1 : ( $image_budget < 3 ? 1 : ( $image_budget < 6 ? min( 2, $configured_image_limit ) : $configured_image_limit ) );
			$image_limit     = $this->stage_capacity_limit( $config, 'imageQueue', $image_limit );
			$image_sync      = $image_import_enabled ? new Mobo_Core_Image_Sync() : null;

			$round['imageQueue'] = $this->execute_stage(
				'imageQueue',
				function () use ( $image_sync, $image_import_enabled, $image_circuit_open, $image_limit, $image_budget ) {
					$result = array( 'processed' => 0, 'failed' => 0, 'status' => 'empty', 'remaining' => false );
					if ( $image_import_enabled && ! $image_circuit_open && $image_sync instanceof Mobo_Core_Image_Sync ) {
						$result = $image_sync->process_queue( $image_limit );
						$result = is_array( $result ) ? $result : array( 'processed' => 0, 'failed' => 1, 'status' => 'invalid-result', 'remaining' => true );
					}
					if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) && Mobo_Core_Image_Prune_Queue::table_exists() ) {
						$prune = new Mobo_Core_Image_Prune_Queue();
						$prune_result = $prune->process_due( max( 1, min( 3, $image_limit ) ), max( 1, min( 4, $image_budget ) ) );
						$result['prune']     = $prune_result;
						$result['processed'] = absint( isset( $result['processed'] ) ? $result['processed'] : 0 ) + absint( isset( $prune_result['processed'] ) ? $prune_result['processed'] : 0 );
						$result['failed']    = absint( isset( $result['failed'] ) ? $result['failed'] : 0 ) + absint( isset( $prune_result['failed'] ) ? $prune_result['failed'] : 0 );
						$result['remaining'] = ! empty( $result['remaining'] ) || ! empty( $prune_result['remaining'] );
						if ( 'empty' === sanitize_key( (string) ( isset( $result['status'] ) ? $result['status'] : '' ) ) && 'empty' !== sanitize_key( (string) ( isset( $prune_result['status'] ) ? $prune_result['status'] : '' ) ) ) {
							$result['status'] = sanitize_key( (string) $prune_result['status'] );
						}
					}
					return $result;
				},
				array( 'processed' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);
			$round['queuePasses']['imageQueue']++;
		}

		/* Image refresh workflow/queue. */
		$image_refresh_active = $this->image_refresh_workflow_active();
		if ( $image_refresh_active && ! isset( $disabled_stages['imageRefreshQueue'] ) && $allow_image_refresh && ! $this->stage_circuit_open( $config, 'imageRefreshQueue' ) ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			if ( class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ) {
				$round['imageRefreshAutomation'] = $this->execute_stage(
					'imageRefreshQueue',
					function () use ( $source ) {
						$automation = new Mobo_Core_Image_Refresh_Automation();
						return $automation->run_tick( $source );
					},
					array( 'success' => false, 'status' => 'exception', 'needsContinuation' => false, 'progressed' => false ),
					$disabled_stages,
					$round['stageErrors']
				);

				if ( isset( $round['imageRefreshAutomation']['operation'] ) && is_array( $round['imageRefreshAutomation']['operation'] ) ) {
					$round['imageRefreshQueue'] = $round['imageRefreshAutomation']['operation'];
				}
			} elseif ( class_exists( 'Mobo_Core_Image_Refresh_Service' ) ) {
				$image_refresh_limit = Mobo_Core_Settings::get_int( 'mobo_core_image_refresh_per_run', 2, 1, 20 );
				$image_refresh_limit = $this->stage_capacity_limit( $config, 'imageRefreshQueue', $image_refresh_limit );
				$round['imageRefreshQueue'] = $this->execute_stage(
					'imageRefreshQueue',
					function () use ( $image_refresh_limit ) {
						$service = new Mobo_Core_Image_Refresh_Service();
						return $service->process_queue( $image_refresh_limit );
					},
					array( 'processed' => 0, 'failed' => 1, 'skipped' => 0, 'status' => 'exception', 'remaining' => true ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
			$round['queuePasses']['imageRefreshQueue']++;
		}

		/* Product sync steps. */
		if ( ! isset( $disabled_stages['productSync'] ) && ! $this->stage_circuit_open( $config, 'productSync' ) && $this->sync_state_should_continue() && class_exists( 'Mobo_Core_Product_Sync' ) ) {
			$product_sync = new Mobo_Core_Product_Sync();
			$status       = $product_sync->get_manual_sync_status();
			$steps        = 0;
			$product_step_limit = $this->stage_capacity_limit( $config, 'productSync', absint( $config['productStepsPerRound'] ) );
			$product_budget_seconds = $this->stage_budget_seconds( $config, 'productSync', max( 2, absint( $config['effectiveTimeBudgetSeconds'] ) ), 1, 20 );
			$product_deadline = min( $deadline, microtime( true ) + $product_budget_seconds );
			$urgent_webhook_yield = false;

			/* The real runner may execute several idempotent sync steps in one request.
			 * Keep their cursor/state in memory and write a durable checkpoint every
			 * few steps or seconds, then always flush at the round boundary. */
			if ( method_exists( $product_sync, 'begin_checkpoint_coalescing' ) ) {
				$product_sync->begin_checkpoint_coalescing( 3, 2.0 );
			}

			try {
				while ( ! empty( $status['shouldContinue'] ) && $steps < $product_step_limit && microtime( true ) < ( $product_deadline - 0.15 ) ) {
					if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
						break;
					}

					$step_started_at = microtime( true );
					try {
						$round['lastStep'] = $product_sync->run_manual_sync_step();
						$status = $product_sync->get_manual_sync_status();

						/* Lock contention is not a processed product step and must never hot-loop.
						 * Defer Product Sync for the rest of this runner invocation; the next real
						 * cron tick can retry after the canonical manual_sync lease changes. */
						if ( ! empty( $round['lastStep']['data']['locked'] ) ) {
							$disabled_stages['productSync'] = 'contended';
							$round['contendedStages'][]      = 'productSync';
							if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
								Mobo_Core_Runtime_Diagnostics::record_stage(
									'productSync',
									max( 0, (int) round( ( microtime( true ) - $step_started_at ) * 1000 ) ),
									array( 'processed' => 0, 'failed' => 0, 'status' => 'contended' )
								);
							}
							break;
						}

						$steps++;
						if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
							Mobo_Core_Runtime_Diagnostics::record_stage(
								'productSync',
								max( 0, (int) round( ( microtime( true ) - $step_started_at ) * 1000 ) ),
								array(
									'processed' => 1,
									'failed'    => empty( $round['lastStep']['success'] ) ? 1 : 0,
									'status'    => isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'running',
								)
							);
						}

						/* Webhook is the foreground freshness lane. A webhook can arrive after
						 * this round already passed its normal webhook stage, while a long Repair/
						 * Sync still has several cooperative steps left. Yield immediately after
						 * the current durable sync checkpoint so the next round starts at the
						 * webhook queue instead of consuming the rest of the product budget. */
						if ( class_exists( 'Mobo_Core_Webhook_Queue' ) ) {
							$priority_queue = new Mobo_Core_Webhook_Queue();
							if ( method_exists( $priority_queue, 'has_priority_work_due_now' ) && $priority_queue->has_priority_work_due_now() ) {
								$urgent_webhook_yield = true;
								break;
							}
						}
					} catch ( Throwable $e ) {
						if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
							Mobo_Core_Runtime_Diagnostics::record_stage(
								'productSync',
								max( 0, (int) round( ( microtime( true ) - $step_started_at ) * 1000 ) ),
								array( 'processed' => 1, 'failed' => 1, 'status' => 'exception' )
							);
						}
						$disabled_stages['productSync'] = true;
						$round['stageErrors'][] = $this->compact_stage_error( 'productSync', $e );
						break;
					}
				}
			} finally {
				if ( method_exists( $product_sync, 'end_checkpoint_coalescing' ) ) {
					$product_sync->end_checkpoint_coalescing();
				}
				$status = $product_sync->get_manual_sync_status();
			}

			$round['productSteps']  = $steps;
			$round['productStatus'] = $status;
			if ( $steps > 0 ) {
				$round['queuePasses']['productSync']++;
			}

			if ( $urgent_webhook_yield ) {
				$round['scheduler']['webhookPressure'] = true;
				$round['webhookPriorityYield'] = true;
				$this->finalize_round_state( $round, $disabled_stages );
				return $round;
			}
		}



		/* Due configuration syncs only need one check per invocation. */
		if ( 1 === absint( $round_number ) ) {
			if ( class_exists( 'Mobo_Core_Address_Mapping' ) && ! isset( $disabled_stages['addressMapping'] ) ) {
				if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
					return $round;
				}

				$round['addressMapping'] = $this->execute_stage(
					'addressMapping',
					function () use ( $source ) {
						$mapping = new Mobo_Core_Address_Mapping();
						return $mapping->maybe_sync_if_due( $source, false );
					},
					array( 'success' => false, 'status' => 'exception' ),
					$disabled_stages,
					$round['stageErrors']
				);
			}

			if ( class_exists( 'Mobo_Core_Remote_Shipping_Methods' ) && ! isset( $disabled_stages['remoteShipping'] ) ) {
				if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
					return $round;
				}

				$round['remoteShipping'] = $this->execute_stage(
					'remoteShipping',
					function () use ( $source ) {
						$shipping = new Mobo_Core_Remote_Shipping_Methods();
						return $shipping->maybe_sync_if_due( $source, false );
					},
					array( 'success' => false, 'status' => 'exception' ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}


		/* A late image pass consumes images enqueued by product sync in this round. */
		$webhook_pressure_for_side_effects = $webhook_due_pressure || ! empty( $round['parentFinalize']['remainingDue'] );
		if ( $image_sync instanceof Mobo_Core_Image_Sync && ! isset( $disabled_stages['imageQueue'] ) && $allow_image_queue && ! $this->stage_circuit_open( $config, 'imageQueue' ) && ! $webhook_pressure_for_side_effects ) {
			if ( ! $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				return $round;
			}

			$late_image_remaining = max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) );
			$late_image_budget = min( $late_image_remaining, $this->stage_budget_seconds( $config, 'imageQueue', 5, 1, 10 ) );
			$late_image_limit = $late_image_budget < 3 ? 1 : ( $late_image_budget < 6 ? min( 2, $configured_image_limit ) : $configured_image_limit );
			$late_image_limit = $this->stage_capacity_limit( $config, 'imageQueue', $late_image_limit );
			$late_image = $this->execute_stage(
				'imageQueue',
				function () use ( $image_sync, $late_image_limit ) {
					return $image_sync->process_queue( $late_image_limit );
				},
				array( 'processed' => 0, 'failed' => 1, 'status' => 'exception', 'remaining' => true ),
				$disabled_stages,
				$round['stageErrors']
			);

			$round['imageQueue'] = $this->merge_queue_counters(
				$round['imageQueue'],
				$late_image,
				array( 'processed', 'failed' ),
				array( 'remaining' )
			);
			$round['imageQueue']['latePass'] = $late_image;
			$round['queuePasses']['imageQueue']++;
		}

		/*
		 * Exact product-page cache warmup.
		 *
		 * This is deliberately low-priority: synchronization and image/order queues
		 * run first. The warmup queue was populated only after a successful targeted
		 * page-cache purge in a previous request/shutdown and contains product URLs,
		 * never category/home/shop URLs.
		 */
		$cache_side_effect_pressure = $webhook_due_pressure
			|| ! empty( $round['parentFinalize']['remainingDue'] )
			|| ! empty( $round['imageQueue']['remaining'] );
		if ( Mobo_Core_Settings::enabled( 'mobo_core_cache_warmup_enabled', '1' ) && $this->option_queue_has_items( 'mobo_core_cache_warmup_queue', 'items' ) && class_exists( 'Mobo_Core_Cache_Warmer' ) && ! isset( $disabled_stages['cacheWarmup'] ) && $allow_cache_warmup && ! $this->stage_circuit_open( $config, 'cacheWarmup' ) && ! $cache_side_effect_pressure ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				/* UX60: claim the durable fairness turn only at the actual execution
				 * boundary. No work/GET is allowed when the cursor cannot be saved. */
				if ( ! empty( $config['ux53MultiConnectionWarmup'] ) ) {
					$warm_owner_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
					$warm_cursor_key = 'mobo_core_cache_warmup_dispatch_cursor_id';
					if ( $warm_owner_id <= 0 || ( ! update_option( $warm_cursor_key, $warm_owner_id, false ) && absint( get_option( $warm_cursor_key, 0 ) ) !== $warm_owner_id ) ) {
						$round['cacheWarmup']['status'] = 'fairness-cursor-persist-failed';
						$round['cacheWarmup']['success'] = false;
						$round['cacheWarmup']['remainingDue'] = true;
						$disabled_stages['cacheWarmup'] = 'fairness-cursor-persist-failed';
						$round['stageErrors']['cacheWarmup'] = 'fairness-cursor-persist-failed';
						$this->finalize_round_state( $round, $disabled_stages );
						return $round;
					}
				}
				$warm_base   = Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_batch_size', 2, 1, 10 );
				$warm_limit  = isset( $config['adaptiveTuning']['limits']['cacheWarmup'] ) ? max( 1, min( 10, absint( $config['adaptiveTuning']['limits']['cacheWarmup'] ) ) ) : $warm_base;
				$warm_limit  = $this->stage_capacity_limit( $config, 'cacheWarmup', $warm_limit );
				if ( ! empty( $config['ux53MultiConnectionWarmup'] ) ) { $warm_limit = 1; }
				$warm_budget = $this->stage_budget_seconds( $config, 'cacheWarmup', 5, 1, 20 );
				$warm_budget = min( $warm_budget, max( 1, (int) floor( $deadline - microtime( true ) - 0.25 ) ) );
				$round['cacheWarmup'] = $this->execute_stage(
					'cacheWarmup',
					function () use ( $warm_limit, $warm_budget, $source ) {
						return Mobo_Core_Cache_Warmer::process_batch( $warm_limit, $warm_budget, $source );
					},
					array(
						'success'      => false,
						'status'       => 'exception',
						'processed'    => 0,
						'warmed'       => 0,
						'failed'       => 1,
						'dropped'      => 0,
						'deferred'     => 0,
						'remaining'    => true,
						'remainingDue' => false,
						'pendingCount' => 0,
					),
					$disabled_stages,
					$round['stageErrors']
				);
				$round['queuePasses']['cacheWarmup']++;
			}
		}

		/*
		 * Custom-storage Product/Variation backfill is the lowest-priority bounded
		 * stage. It runs at most once per runner invocation, never while foreground
		 * queue families still report immediate work, and never creates its own
		 * continuation chain. Dual-write keeps new commits current while old rows are
		 * drained across ordinary real-cron/heartbeat invocations.
		 */
		$storage_backfill_pressure = $webhook_due_pressure
			|| ! empty( $round['parentFinalize']['remainingDue'] )
			|| ! empty( $round['imageQueue']['remaining'] )
			|| ! empty( $round['imageRefreshQueue']['remaining'] )
			|| ! empty( $round['productStatus']['shouldContinue'] )
			|| ! empty( $round['repriceQueue']['remaining'] )
			|| ! empty( $round['recategorizeQueue']['remaining'] )
			|| ! empty( $round['orderSubmissions']['remaining'] )
			|| ! empty( $round['cacheWarmup']['remainingDue'] );
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storageBackfill'] )
			&& class_exists( 'Mobo_Core_Storage_Backfill' )
			&& Mobo_Core_Storage_Backfill::should_run() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storageBackfill'] = $this->execute_stage(
					'storageBackfill',
					function () {
						return Mobo_Core_Storage_Backfill::process_batch( Mobo_Core_Storage_Backfill::DEFAULT_BATCH );
					},
					array( 'success' => false, 'status' => 'exception', 'processed' => 0, 'mirrored' => 0, 'skipped' => 0, 'failed' => 1, 'remaining' => true ),
					$disabled_stages,
					$round['stageErrors']
				);
				$round['queuePasses']['storageBackfill']++;
			}
		}


		/* 10.33.75: after backfill, bounded reconciliation refreshes Custom Storage
		 * from authoritative Legacy state and cleans rows tied to retired Woo objects.
		 * It remains low priority and never changes Legacy/Woo state. */
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storageReconciliation'] )
			&& class_exists( 'Mobo_Core_Storage_Reconciler' )
			&& Mobo_Core_Storage_Reconciler::should_run() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storageReconciliation'] = $this->execute_stage(
					'storageReconciliation',
					function () {
						return Mobo_Core_Storage_Reconciler::process_batch( Mobo_Core_Storage_Reconciler::DEFAULT_BATCH );
					},
					array( 'success' => false, 'status' => 'exception', 'processed' => 0, 'mirrored' => 0, 'cleaned' => 0, 'failed' => 1, 'ambiguous' => 0 ),
					$disabled_stages,
					$round['stageErrors']
				);
				$round['queuePasses']['storageReconciliation']++;
			}
		}

		/* 10.33.75: refresh the custom-primary promotion lease only
		 * after foreground pressure is clear. This stage never changes storage mode
		 * and is throttled internally to one checkpoint per five minutes. */
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storagePromotionGate'] )
			&& class_exists( 'Mobo_Core_Storage_Promotion_Gate' )
			&& Mobo_Core_Storage_Promotion_Gate::should_evaluate() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storagePromotionGate'] = $this->execute_stage(
					'storagePromotionGate',
					function () {
						return Mobo_Core_Storage_Promotion_Gate::evaluate();
					},
					array( 'eligible' => false, 'status' => 'exception', 'blockers' => array( 'exception' ) ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		/* 10.33.79: collect/evaluate the stronger Custom-Only readiness lease only
		 * after Custom-Primary itself is healthy. This stage is evidence-only and
		 * never changes the requested/effective storage mode. */
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storageCustomOnlyGate'] )
			&& class_exists( 'Mobo_Core_Storage_Custom_Only_Gate' )
			&& Mobo_Core_Storage_Custom_Only_Gate::should_evaluate() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storageCustomOnlyGate'] = $this->execute_stage(
					'storageCustomOnlyGate',
					function () {
						return Mobo_Core_Storage_Custom_Only_Gate::evaluate();
					},
					array( 'eligible' => false, 'status' => 'exception', 'blockers' => array( 'exception' ) ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		/* 10.33.82: evidence-only Legacy retirement readiness. This stage never
		 * suppresses Product Map/postmeta writes; it only proves that effective
		 * Custom-Only traffic no longer depends on Legacy reads. */
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storageLegacyRetirementGate'] )
			&& class_exists( 'Mobo_Core_Storage_Legacy_Retirement_Gate' )
			&& Mobo_Core_Storage_Legacy_Retirement_Gate::should_evaluate() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storageLegacyRetirementGate'] = $this->execute_stage(
					'storageLegacyRetirementGate',
					function () { return Mobo_Core_Storage_Legacy_Retirement_Gate::evaluate(); },
					array( 'eligible'=>false, 'status'=>'exception', 'blockers'=>array('exception') ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		/* 10.33.84: per-key operational-postmeta suppression readiness. Evidence only;
		 * this stage never suppresses or deletes postmeta. It runs after the global
		 * Legacy retirement gate so each key is evaluated only against a proven
		 * Custom-Only baseline. */
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storagePostmetaSuppressionReadiness'] )
			&& class_exists( 'Mobo_Core_Storage_Postmeta_Suppression_Readiness' )
			&& Mobo_Core_Storage_Postmeta_Suppression_Readiness::should_evaluate() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storagePostmetaSuppressionReadiness'] = $this->execute_stage(
					'storagePostmetaSuppressionReadiness',
					function () { return Mobo_Core_Storage_Postmeta_Suppression_Readiness::evaluate(); },
					array( 'eligible'=>false, 'allEligible'=>false, 'status'=>'exception', 'eligibleKeys'=>array() ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		/* 10.33.95: terminal metadata-retirement evidence. This stage is
		 * non-destructive and evaluates the already-migrated state: both the seven
		 * operational keys and Product/Variation identity must have active durable
		 * fences, completed migrations and exact zero-residue proofs. */
		if ( 1 === absint( $round_number )
			&& ! $storage_backfill_pressure
			&& ! isset( $disabled_stages['storageRetirementFinalizer'] )
			&& class_exists( 'Mobo_Core_Storage_Retirement_Finalizer' )
			&& Mobo_Core_Storage_Retirement_Finalizer::should_evaluate() ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['storageRetirementFinalizer'] = $this->execute_stage(
					'storageRetirementFinalizer',
					function () { return Mobo_Core_Storage_Retirement_Finalizer::evaluate(); },
					array( 'logicalRetirementComplete'=>false, 'status'=>'exception', 'blockers'=>array('exception'), 'physicalDeletionAllowed'=>false ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		/* Maintenance is opportunistic and only checked once per invocation. */
		if ( 1 === absint( $round_number ) && ! $webhook_due_pressure && $allow_maintenance && ! $this->stage_circuit_open( $config, 'maintenance' ) && class_exists( 'Mobo_Core_Maintenance' ) && ! isset( $disabled_stages['maintenance'] ) ) {
			if ( $this->prepare_stage( $deadline, $config, $lock_token, $lock_renewals, $round, $disabled_stages ) ) {
				$round['maintenance'] = $this->execute_stage(
					'maintenance',
					function () use ( $source ) {
						return Mobo_Core_Maintenance::maybe_run( $source );
					},
					array( 'success' => false, 'status' => 'exception' ),
					$disabled_stages,
					$round['stageErrors']
				);
			}
		}

		$this->finalize_round_state( $round, $disabled_stages );
		return $round;
	}

	/**
	 * Initialize aggregate result.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private function empty_aggregate_result( $source ) {
		return array(
			'success'                => true,
			'status'                 => 'ok',
			'source'                 => sanitize_key( (string) $source ),
			'webhookQueue'           => array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remainingFile' => false, 'remainingTable' => false, 'remainingDueTable' => false ),
			'parentFinalize'         => array( 'success' => true, 'status' => 'skipped', 'processed' => 0, 'finalized' => 0, 'dropped' => 0, 'failed' => 0, 'remaining' => false, 'remainingDue' => false, 'pendingCount' => 0 ),
			'imageQueue'             => array( 'processed' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false ),
			'imageRefreshQueue'      => array( 'processed' => 0, 'failed' => 0, 'skipped' => 0, 'status' => 'skipped', 'remaining' => false ),
			'imageRefreshAutomation' => array( 'success' => true, 'status' => 'disabled', 'needsContinuation' => false, 'progressed' => false ),
			'repriceQueue'           => array( 'processed' => 0, 'updated' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false ),
			'recategorizeQueue'      => array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'status' => 'skipped', 'remaining' => false ),
			'addressMapping'         => array( 'status' => 'skipped' ),
			'remoteShipping'         => array( 'status' => 'skipped' ),
			'orderSubmissions'       => array( 'status' => 'skipped', 'processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => false ),
			'cacheWarmup'           => array( 'success' => true, 'status' => 'skipped', 'processed' => 0, 'warmed' => 0, 'failed' => 0, 'dropped' => 0, 'deferred' => 0, 'remaining' => false, 'remainingDue' => false, 'pendingCount' => 0, 'lastError' => '' ),
			'storageBackfill'        => array( 'success' => true, 'status' => 'skipped', 'processed' => 0, 'mirrored' => 0, 'skipped' => 0, 'failed' => 0, 'gaps' => 0, 'cursor' => 0, 'remaining' => false ),
			'storageReconciliation'  => array( 'success' => true, 'status' => 'skipped', 'processed' => 0, 'mirrored' => 0, 'cleaned' => 0, 'failed' => 0, 'ambiguous' => 0 ),
			'storagePromotionGate'   => array( 'eligible' => false, 'status' => 'skipped', 'blockers' => array() ),
			'storageCustomOnlyGate'  => array( 'eligible' => false, 'status' => 'skipped', 'blockers' => array() ),
			'storageLegacyRetirementGate' => array( 'eligible' => false, 'status' => 'skipped', 'blockers' => array() ),
			'storagePostmetaSuppressionReadiness' => array( 'eligible' => false, 'allEligible' => false, 'status' => 'skipped', 'eligibleKeys' => array() ),
			'storageRetirementFinalizer' => array( 'logicalRetirementComplete' => false, 'status' => 'skipped', 'blockers' => array(), 'physicalDeletionAllowed' => false ),
			'maintenance'            => array( 'status' => 'skipped' ),
			'scheduler'              => array( 'webhookPressure' => false, 'backgroundCapacity' => 0, 'selectedStages' => array(), 'deferredStages' => array(), 'allow' => array(), 'scores' => array() ),
			'productSteps'           => 0,
			'productStatus'          => array(),
			'lastStep'               => null,
			'runnerErrors'           => array(),
			'contendedStages'        => array(),
			'queuePasses'            => array(
				'webhookQueue'      => 0,
				'parentFinalize'    => 0,
				'imageQueue'        => 0,
				'imageRefreshQueue' => 0,
				'productSync'       => 0,
				'repriceQueue'      => 0,
				'recategorizeQueue' => 0,
				'orderSubmissions'  => 0,
				'cacheWarmup'      => 0,
				'storageBackfill'  => 0,
				'storageReconciliation' => 0,
			),
		);
	}

	/**
	 * Initialize one round result.
	 *
	 * @return array
	 */
	private function empty_round_result() {
		$result = $this->empty_aggregate_result( 'round' );
		$result['stageErrors']      = array();
		$result['madeProgress']     = false;
		$result['hasImmediateWork'] = false;
		$result['deadlineReached']  = false;
		$result['memoryPressure']   = false;
		$result['lockLost']         = false;
		unset( $result['source'], $result['runnerErrors'] );
		return $result;
	}



	/**
	 * Whether the image-refresh workflow is currently allowed to advance.
	 *
	 * The automation coordinator owns the durable start/pause state. The old
	 * mobo_core_image_refresh_enabled option remains only as a compatibility
	 * fallback for installs that cannot load the coordinator class yet.
	 *
	 * @return bool
	 */
	private function image_refresh_workflow_active() {
		if ( class_exists( 'Mobo_Core_Image_Refresh_Automation' ) ) {
			$status = Mobo_Core_Image_Refresh_Automation::get_status();
			return is_array( $status ) && ! empty( $status['enabled'] );
		}

		return Mobo_Core_Settings::enabled( 'mobo_core_image_refresh_enabled', '0' );
	}


	/**
	 * Capture one bounded queue-pressure snapshot for the invocation.
	 *
	 * Table-backed queue summaries are already request-cached by their stores.
	 * Option-backed queues are read once here and reused by the adaptive budget.
	 *
	 * @return array
	 */
	private function build_queue_snapshot() {
		$snapshot = array();

		$webhook_pending = 0;
		$webhook_due     = 0;
		if ( Mobo_Core_Settings::enabled( 'mobo_core_real_cron_process_webhooks', '1' ) && class_exists( 'Mobo_Core_Sync_Event_Store' ) ) {
			try {
				$store   = new Mobo_Core_Sync_Event_Store();
				$summary = $store->get_summary();
				$webhook_pending = absint( isset( $summary['pendingCount'] ) ? $summary['pendingCount'] : 0 );
				$webhook_due     = absint( isset( $summary['dueCount'] ) ? $summary['dueCount'] : 0 );
			} catch ( Throwable $e ) {
				$webhook_pending = 0;
				$webhook_due     = 0;
			}
		}
		$snapshot['webhookQueue'] = array(
			'active'  => $webhook_due > 0 || $webhook_pending > 0,
			'backlog' => max( $webhook_due, $webhook_pending ),
		);

		$order_count = ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() )
			? Mobo_Core_Work_Queue_Store::count( 'order_submission' )
			: ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() )
				? count( (array) get_option( 'mobo_core_mobo_order_submission_queue', array() ) )
				: 0 );
		$snapshot['orderSubmissions'] = array(
			'active'  => Mobo_Core_Order_Submission_Policy::is_enabled() && $order_count > 0,
			'backlog' => $order_count,
		);

		$parent_count = ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() )
			? Mobo_Core_Work_Queue_Store::count( 'parent_finalize' )
			: ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() )
				? count( (array) get_option( 'mobo_core_parent_finalize_queue', array() ) )
				: 0 );
		$snapshot['parentFinalize'] = array( 'active' => $parent_count > 0, 'backlog' => $parent_count );

		$image_pending = 0;
		$image_due     = 0;
		if ( Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' ) && class_exists( 'Mobo_Core_Image_Queue' ) ) {
			try {
				$image_store   = new Mobo_Core_Image_Queue();
				$image_summary = $image_store->get_summary();
				$image_pending = absint( isset( $image_summary['pending'] ) ? $image_summary['pending'] : 0 );
				$image_due     = absint( isset( $image_summary['due'] ) ? $image_summary['due'] : 0 );
			} catch ( Throwable $e ) {
				$image_pending = 0;
				$image_due     = 0;
			}
		}
		/* Physical stale-media cleanup is independent from remote image downloading.
		 * A disabled image import queue must not strand durable prune debt forever. */
		if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) && Mobo_Core_Image_Prune_Queue::table_exists() ) {
			try {
				$prune_store   = new Mobo_Core_Image_Prune_Queue();
				$prune_summary = $prune_store->get_summary();
				$prune_pending = absint( isset( $prune_summary['pending'] ) ? $prune_summary['pending'] : 0 );
				$prune_due     = absint( isset( $prune_summary['due'] ) ? $prune_summary['due'] : 0 );
				if ( $prune_store->has_due_work() && 0 === $prune_due ) {
					$prune_due = 1; /* Legacy seed debt. */
				}
				$image_pending += $prune_pending;
				$image_due     += $prune_due;
			} catch ( Throwable $e ) {
				/* Fail open for the scheduler; schema migration will retry separately. */
			}
		}
		$snapshot['imageQueue'] = array( 'active' => $image_due > 0 || $image_pending > 0, 'backlog' => max( $image_due, $image_pending ) );

		$sync_state = ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() )
			? Mobo_Core_State_Store::get( 'sync_state', array() )
			: ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() )
				? get_option( 'mobo_core_sync_state', array() )
				: array() );
		$sync_state = is_array( $sync_state ) ? $sync_state : array();
		$product_queue_count = isset( $sync_state['productQueue'] ) && is_array( $sync_state['productQueue'] ) ? count( $sync_state['productQueue'] ) : 0;
		$product_remaining   = absint( isset( $sync_state['remainingProducts'] ) ? $sync_state['remainingProducts'] : 0 );
		$product_total       = absint( isset( $sync_state['productTotalCount'] ) ? $sync_state['productTotalCount'] : 0 );
		$product_processed   = absint( isset( $sync_state['processedProducts'] ) ? $sync_state['processedProducts'] : 0 );
		if ( $product_total > $product_processed ) {
			$product_remaining = max( $product_remaining, $product_total - $product_processed );
		}
		$product_backlog = max( $product_queue_count, $product_remaining );
		$sync_status     = isset( $sync_state['status'] ) ? sanitize_key( (string) $sync_state['status'] ) : '';
		$snapshot['productSync'] = array(
			'active'  => in_array( $sync_status, array( 'running', 'waiting_for_portal' ), true ),
			'backlog' => $product_backlog,
		);

		$image_refresh_active = $this->image_refresh_workflow_active();
		$snapshot['imageRefreshQueue'] = array( 'active' => $image_refresh_active, 'backlog' => $image_refresh_active ? 1 : 0 );

		$reprice = class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available()
			? Mobo_Core_State_Store::get( 'mobo_core_reprice_state', array() )
			: array();
		$reprice = is_array( $reprice ) ? $reprice : array();
		$reprice_active = 'running' === sanitize_key( (string) ( isset( $reprice['status'] ) ? $reprice['status'] : '' ) );
		$reprice_backlog = $reprice_active ? max( 1, absint( isset( $reprice['total'] ) ? $reprice['total'] : 0 ) - absint( isset( $reprice['processed'] ) ? $reprice['processed'] : 0 ) ) : 0;
		$snapshot['repriceQueue'] = array( 'active' => $reprice_active, 'backlog' => $reprice_backlog );

		$recat = class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available()
			? Mobo_Core_State_Store::get( 'mobo_core_recategorize_state', array() )
			: array();
		$recat = is_array( $recat ) ? $recat : array();
		$recat_active = 'running' === sanitize_key( (string) ( isset( $recat['status'] ) ? $recat['status'] : '' ) );
		$recat_backlog = $recat_active ? max( 1, absint( isset( $recat['total'] ) ? $recat['total'] : 0 ) - absint( isset( $recat['processed'] ) ? $recat['processed'] : 0 ) ) : 0;
		$snapshot['recategorizeQueue'] = array( 'active' => $recat_active, 'backlog' => $recat_backlog );

		if ( class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
			$warm_stats = Mobo_Core_Work_Queue_Store::queue_stats( 'cache_warmup' );
			$hot_stats  = Mobo_Core_Work_Queue_Store::queue_stats( 'cache_warmup_hot' );
			$warm_pending = $warm_stats['pending'] + $hot_stats['pending'];
			$warm_due = $warm_stats['due'] + $hot_stats['due'];
		} elseif ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) {
			$warm_legacy = get_option( 'mobo_core_cache_warmup_queue', array() );
			$warm_items  = isset( $warm_legacy['items'] ) && is_array( $warm_legacy['items'] ) ? $warm_legacy['items'] : array();
			$warm_pending = count( $warm_items );
			$warm_due = 0;
			foreach ( $warm_items as $warm_item ) {
				if ( is_array( $warm_item ) && absint( isset( $warm_item['nextAttemptAt'] ) ? $warm_item['nextAttemptAt'] : 0 ) <= time() ) { $warm_due++; }
			}
		} else {
			$warm_pending = 0;
			$warm_due = 0;
		}
		$snapshot['cacheWarmup'] = array( 'active' => $warm_pending > 0, 'backlog' => max( $warm_due, $warm_pending ) );

		$snapshot['maintenance']    = array( 'active' => true, 'backlog' => 1 );

		return $snapshot;
	}

	/**
	 * Resolve the active stages for the current fair-scheduler round.
	 *
	 * The invocation snapshot is the baseline. Results from the webhook pass are
	 * allowed to activate parent/image work that was created after the snapshot.
	 *
	 * @param int   $round_number Round number.
	 * @param array $config Runtime config.
	 * @param array $round Partial round result.
	 * @return array
	 */
	private function round_active_stages( $round_number, $config, $round ) {
		$snapshot = isset( $config['queueSnapshot'] ) && is_array( $config['queueSnapshot'] ) ? $config['queueSnapshot'] : array();
		$active   = array();
		foreach ( array( 'parentFinalize', 'imageQueue', 'imageRefreshQueue', 'repriceQueue', 'recategorizeQueue', 'cacheWarmup', 'maintenance' ) as $stage ) {
			$active[ $stage ] = ! empty( $snapshot[ $stage ]['active'] );
		}

		/* Webhook/product mutations can enqueue these after the initial snapshot. */
		if ( absint( isset( $round['webhookQueue']['processed'] ) ? $round['webhookQueue']['processed'] : 0 ) > 0 ) {
			$active['parentFinalize'] = true;
			$active['imageQueue']     = true;
		}
		if ( ! empty( $snapshot['productSync']['active'] ) ) {
			/* Product sync can enqueue either remote image work or local prune debt later
			 * in this invocation. Reserve the image-family slot independent of whether
			 * remote image downloading itself is enabled. */
			$active['imageQueue'] = true;
		}
		if ( class_exists( 'Mobo_Core_Image_Prune_Queue' ) && Mobo_Core_Image_Prune_Queue::table_exists() ) {
			try {
				$prune_probe = new Mobo_Core_Image_Prune_Queue();
				if ( $prune_probe->has_due_work() ) {
					$active['imageQueue'] = true;
				}
			} catch ( Throwable $e ) {
				/* Keep scheduler state from failing because optional cleanup diagnostics failed. */
			}
		}
		if ( $this->option_queue_has_items( 'mobo_core_parent_finalize_queue' ) ) {
			$active['parentFinalize'] = true;
		}
		if ( $this->option_queue_has_items( 'mobo_core_cache_warmup_queue', 'items' ) ) {
			$active['cacheWarmup'] = true;
		}
		$active['repriceQueue']      = $this->option_state_is_running( 'mobo_core_reprice_state' );
		$active['recategorizeQueue'] = $this->option_state_is_running( 'mobo_core_recategorize_state' );
		$active['maintenance']       = 1 === absint( $round_number );

		return $active;
	}

	/** Get the request-local adaptive budget for one stage. */
	private function stage_budget_seconds( $config, $stage, $fallback, $min = 1, $max = 20 ) {
		$profile = isset( $config['adaptiveBudget'] ) && is_array( $config['adaptiveBudget'] ) ? $config['adaptiveBudget'] : array();
		if ( class_exists( 'Mobo_Core_Adaptive_Budget' ) ) {
			return Mobo_Core_Adaptive_Budget::seconds_for( $profile, $stage, $fallback, $min, $max );
		}
		return max( absint( $min ), min( absint( $max ), max( 1, absint( $fallback ) ) ) );
	}

	/** Whether a non-critical stage is isolated by its circuit breaker. */
	private function stage_circuit_open( $config, $stage ) {
		$profile = isset( $config['circuitBreakers'] ) && is_array( $config['circuitBreakers'] ) ? $config['circuitBreakers'] : array();
		return class_exists( 'Mobo_Core_Circuit_Breaker' ) && Mobo_Core_Circuit_Breaker::should_skip( $profile, $stage );
	}

	/** Apply degraded/half-open capacity limits without mutating admin settings. */
	private function stage_capacity_limit( $config, $stage, $limit ) {
		$limit   = max( 1, absint( $limit ) );
		$profile = isset( $config['circuitBreakers'] ) && is_array( $config['circuitBreakers'] ) ? $config['circuitBreakers'] : array();
		if ( ! class_exists( 'Mobo_Core_Circuit_Breaker' ) ) {
			return $limit;
		}
		if ( Mobo_Core_Circuit_Breaker::is_probe( $profile, $stage ) ) {
			return 1;
		}
		if ( Mobo_Core_Circuit_Breaker::is_degraded( $profile, $stage ) ) {
			return max( 1, (int) floor( $limit / 2 ) );
		}
		return $limit;
	}

	/**
	 * Cheap preflight for the manual/repair sync state without autoloading the
	 * large Product Sync class when no durable sync is runnable.
	 *
	 * @return bool
	 */
	private function sync_state_should_continue() {
		$state = ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() )
			? Mobo_Core_State_Store::get( 'sync_state', array() )
			: ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() )
				? get_option( 'mobo_core_sync_state', array() )
				: array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return false;
		}

		$status     = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : '';
		$last_error = isset( $state['lastError'] ) ? trim( (string) $state['lastError'] ) : '';
		if ( '' !== $last_error ) {
			return false;
		}
		if ( 'running' === $status ) {
			return true;
		}
		if ( 'waiting_for_portal' === $status ) {
			$next_retry = absint( isset( $state['nextRetryAt'] ) ? $state['nextRetryAt'] : 0 );
			return 0 === $next_retry || $next_retry <= time();
		}
		return false;
	}

	/**
	 * Check an option-backed background queue state before autoloading its class.
	 *
	 * @param string $option_name State option.
	 * @return bool
	 */
	private function option_state_is_running( $option_name ) {
		$option_name = sanitize_key( (string) $option_name );
		if ( in_array( $option_name, array( 'mobo_core_reprice_state', 'mobo_core_recategorize_state' ), true ) ) {
			$state = class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available()
				? Mobo_Core_State_Store::get( $option_name, array() )
				: array();
		} else {
			$state = get_option( $option_name, array() );
		}
		return is_array( $state ) && 'running' === sanitize_key( (string) ( isset( $state['status'] ) ? $state['status'] : '' ) );
	}


	/**
	 * Cheap option-backed queue preflight used to avoid autoloading queue classes
	 * when their durable queue is empty.
	 *
	 * @param string $option_name Queue option.
	 * @param string $items_key Optional nested items key.
	 * @return bool
	 */
	private function option_queue_has_items( $option_name, $items_key = '' ) {
		unset( $items_key );
		$map = array(
			'mobo_core_parent_finalize_queue'      => 'parent_finalize',
			'mobo_core_cache_warmup_queue'         => 'cache_warmup',
			'mobo_core_mobo_order_submission_queue'=> 'order_submission',
		);
		$option_name = sanitize_key( (string) $option_name );
		if ( isset( $map[ $option_name ] ) && class_exists( 'Mobo_Core_Work_Queue_Store' ) && Mobo_Core_Work_Queue_Store::is_available() ) {
			if ( 'mobo_core_cache_warmup_queue' === $option_name ) {
				if ( Mobo_Core_Work_Queue_Store::has_due_one( 'cache_warmup_hot' ) || Mobo_Core_Work_Queue_Store::has_due_one( 'cache_warmup' ) ) { return true; }
				// UX51 option queue can exist at the moment UX52 is installed, with no
				// newer product mutation to trigger migration. Wake the worker once so
				// Cache_Warmer::row_store_ready() imports this Default-owned legacy map.
				if ( ( ! class_exists( 'Mobo_Core_Large_Runtime_Storage' ) || Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) &&
					( ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) ) {
					$legacy = get_option( $option_name, array() );
					return is_array( $legacy ) && ! empty( $legacy['items'] );
				}
				return false;
			}
			return Mobo_Core_Work_Queue_Store::count( $map[ $option_name ] ) > 0;
		}
		if ( isset( $map[ $option_name ] ) && class_exists( 'Mobo_Core_Large_Runtime_Storage' ) && ! Mobo_Core_Large_Runtime_Storage::legacy_fallback_allowed() ) {
			return false;
		}
		if ( isset( $map[ $option_name ] ) && class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) {
			return false;
		}
		$legacy = get_option( $option_name, array() );
		if ( 'mobo_core_cache_warmup_queue' === $option_name ) {
			return is_array( $legacy ) && ! empty( $legacy['items'] );
		}
		return is_array( $legacy ) && ! empty( $legacy );
	}

	/**
	 * Renew lease and verify time before a major stage.
	 *
	 * @param float  $deadline Deadline.
	 * @param array  $config Config.
	 * @param string $lock_token Token.
	 * @param int    $lock_renewals Renewal counter.
	 * @param array  $round Round result.
	 * @return bool
	 */
	private function prepare_stage( $deadline, $config, $lock_token, &$lock_renewals, &$round, $disabled_stages = array() ) {
		if ( class_exists( 'Mobo_Core_Upgrade_Coordinator' ) && Mobo_Core_Upgrade_Coordinator::is_active() ) {
			$round['upgradePaused'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		if ( ! $this->has_time_remaining( $deadline, $config['safetyMarginSeconds'] ) ) {
			$round['deadlineReached'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		if ( ! $this->has_memory_headroom( $config ) ) {
			$round['memoryPressure'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		if ( ! $this->renew_runner_lock( $lock_token, $config['lockTtlSeconds'], $lock_renewals ) ) {
			$round['lockLost'] = true;
			$this->finalize_round_state( $round, $disabled_stages );
			return false;
		}

		return true;
	}

	/**
	 * Execute one stage without allowing its exception to abort other queues.
	 *
	 * @param string   $stage Stage name.
	 * @param callable $callback Callback.
	 * @param array    $fallback Fallback result.
	 * @param array    $disabled_stages Disabled stages by reference.
	 * @param array    $errors Errors by reference.
	 * @return array
	 */
	private function execute_stage( $stage, $callback, $fallback, &$disabled_stages, &$errors ) {
		$started_at = microtime( true );
		try {
			$result = call_user_func( $callback );
			$result = is_array( $result ) ? $result : $fallback;
		} catch ( Throwable $e ) {
			$disabled_stages[ (string) $stage ] = true;
			$errors[] = $this->compact_stage_error( $stage, $e );
			$fallback['exceptionClass'] = get_class( $e );
			$fallback['message']        = 'Cron stage failed; see the stage and exception type in diagnostics.';
			$result = $fallback;
		}

		if ( class_exists( 'Mobo_Core_Runtime_Diagnostics' ) ) {
			$elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
			Mobo_Core_Runtime_Diagnostics::record_stage( $stage, $elapsed_ms, $result );
		}

		return $result;
	}

	/**
	 * Finalize progress and immediate-work flags for a round.
	 *
	 * @param array $round Round by reference.
	 * @param array $disabled_stages Disabled stages.
	 * @return void
	 */
	private function finalize_round_state( &$round, $disabled_stages ) {
		$webhook_processed = absint( isset( $round['webhookQueue']['processed'] ) ? $round['webhookQueue']['processed'] : 0 );
		$parent_finalized  = absint( isset( $round['parentFinalize']['finalized'] ) ? $round['parentFinalize']['finalized'] : 0 );
		$image_processed   = absint( isset( $round['imageQueue']['processed'] ) ? $round['imageQueue']['processed'] : 0 );
		$refresh_processed = absint( isset( $round['imageRefreshQueue']['processed'] ) ? $round['imageRefreshQueue']['processed'] : 0 );
		$reprice_processed = absint( isset( $round['repriceQueue']['processed'] ) ? $round['repriceQueue']['processed'] : 0 );
		$recat_processed   = absint( isset( $round['recategorizeQueue']['processed'] ) ? $round['recategorizeQueue']['processed'] : 0 );
		$order_processed   = absint( isset( $round['orderSubmissions']['processed'] ) ? $round['orderSubmissions']['processed'] : 0 );
		$warm_processed    = absint( isset( $round['cacheWarmup']['processed'] ) ? $round['cacheWarmup']['processed'] : 0 );
		$backfill_processed = absint( isset( $round['storageBackfill']['processed'] ) ? $round['storageBackfill']['processed'] : 0 );
		$reconciliation_processed = absint( isset( $round['storageReconciliation']['processed'] ) ? $round['storageReconciliation']['processed'] : 0 ) + absint( isset( $round['storageReconciliation']['cleaned'] ) ? $round['storageReconciliation']['cleaned'] : 0 );
		$product_steps     = absint( isset( $round['productSteps'] ) ? $round['productSteps'] : 0 );
		$automation_moved  = ! empty( $round['imageRefreshAutomation']['progressed'] );

		$round['madeProgress'] = ( $webhook_processed + $parent_finalized + $image_processed + $refresh_processed + $reprice_processed + $recat_processed + $order_processed + $warm_processed + $backfill_processed + $reconciliation_processed + $product_steps ) > 0 || $automation_moved;

		/* Reuse the queue processor/scheduler result. Avoid a second table existence/
		 * due-work probe at the end of every round. */
		$webhook_due = ! isset( $disabled_stages['webhookQueue'] )
			&& ( ! empty( $round['webhookQueue']['remainingDueTable'] ) || ! empty( $round['scheduler']['webhookPressure'] ) );

		$product_continue = ! isset( $disabled_stages['productSync'] ) && ! empty( $round['productStatus']['shouldContinue'] );
		$automation_continue = ! isset( $disabled_stages['imageRefreshQueue'] )
			&& ! empty( $round['imageRefreshAutomation']['needsContinuation'] )
			&& ! empty( $round['imageRefreshAutomation']['progressed'] );

		$round['hasImmediateWork'] = $webhook_due
			|| ( ! isset( $disabled_stages['parentFinalize'] ) && ! empty( $round['parentFinalize']['remainingDue'] ) )
			|| ( ! isset( $disabled_stages['imageQueue'] ) && ! empty( $round['imageQueue']['remaining'] ) )
			|| ( ! isset( $disabled_stages['imageRefreshQueue'] ) && ! empty( $round['imageRefreshQueue']['remaining'] ) )
			|| $automation_continue
			|| $product_continue
			|| ( ! isset( $disabled_stages['repriceQueue'] ) && ! empty( $round['repriceQueue']['remaining'] ) )
			|| ( ! isset( $disabled_stages['recategorizeQueue'] ) && ! empty( $round['recategorizeQueue']['remaining'] ) )
			|| ( ! isset( $disabled_stages['orderSubmissions'] ) && ! empty( $round['orderSubmissions']['remaining'] ) )
			|| ( ! isset( $disabled_stages['cacheWarmup'] ) && ! empty( $round['cacheWarmup']['remainingDue'] ) );
	}

	/**
	 * Merge a round into aggregate counters and latest statuses.
	 *
	 * @param array $aggregate Aggregate by reference.
	 * @param array $round Round.
	 * @return void
	 */
	private function merge_round_result( &$aggregate, $round ) {
		$aggregate['webhookQueue'] = $this->merge_queue_counters(
			$aggregate['webhookQueue'],
			$round['webhookQueue'],
			array( 'processed', 'failed' ),
			array( 'remainingFile', 'remainingTable', 'remainingDueTable' )
		);
		$aggregate['parentFinalize'] = $this->merge_queue_counters(
			$aggregate['parentFinalize'],
			$round['parentFinalize'],
			array( 'processed', 'finalized', 'dropped', 'failed' ),
			array( 'remaining', 'remainingDue' )
		);
		$aggregate['imageQueue'] = $this->merge_queue_counters(
			$aggregate['imageQueue'],
			$round['imageQueue'],
			array( 'processed', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['imageRefreshQueue'] = $this->merge_queue_counters(
			$aggregate['imageRefreshQueue'],
			$round['imageRefreshQueue'],
			array( 'processed', 'failed', 'skipped' ),
			array( 'remaining' )
		);
		$aggregate['repriceQueue'] = $this->merge_queue_counters(
			$aggregate['repriceQueue'],
			$round['repriceQueue'],
			array( 'processed', 'updated', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['recategorizeQueue'] = $this->merge_queue_counters(
			$aggregate['recategorizeQueue'],
			$round['recategorizeQueue'],
			array( 'processed', 'updated', 'skipped', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['orderSubmissions'] = $this->merge_queue_counters(
			$aggregate['orderSubmissions'],
			$round['orderSubmissions'],
			array( 'processed', 'success', 'failed', 'skipped' ),
			array( 'remaining' )
		);

		$aggregate['cacheWarmup'] = $this->merge_queue_counters(
			$aggregate['cacheWarmup'],
			$round['cacheWarmup'],
			array( 'processed', 'warmed', 'failed', 'dropped' ),
			array( 'remaining', 'remainingDue' )
		);
		$aggregate['storageBackfill'] = $this->merge_queue_counters(
			$aggregate['storageBackfill'],
			$round['storageBackfill'],
			array( 'processed', 'mirrored', 'skipped', 'failed' ),
			array( 'remaining' )
		);
		$aggregate['storageReconciliation'] = $this->merge_queue_counters(
			$aggregate['storageReconciliation'],
			$round['storageReconciliation'],
			array( 'processed', 'mirrored', 'cleaned', 'failed', 'ambiguous' ),
			array()
		);
		if ( isset( $round['storagePromotionGate'] ) && is_array( $round['storagePromotionGate'] ) && 'skipped' !== ( isset( $round['storagePromotionGate']['status'] ) ? $round['storagePromotionGate']['status'] : '' ) ) {
			$aggregate['storagePromotionGate'] = $round['storagePromotionGate'];
		}

		foreach ( array( 'storageCustomOnlyGate', 'storageLegacyRetirementGate', 'storagePostmetaSuppressionReadiness', 'storageRetirementFinalizer' ) as $storage_gate_key ) {
			if ( isset( $round[ $storage_gate_key ] ) && is_array( $round[ $storage_gate_key ] ) && 'skipped' !== ( isset( $round[ $storage_gate_key ]['status'] ) ? $round[ $storage_gate_key ]['status'] : '' ) ) {
				$aggregate[ $storage_gate_key ] = $round[ $storage_gate_key ];
			}
		}

		$aggregate['imageRefreshAutomation'] = $round['imageRefreshAutomation'];
		if ( isset( $round['scheduler'] ) && is_array( $round['scheduler'] ) ) {
			$aggregate['scheduler'] = $round['scheduler'];
		}
		$aggregate['productSteps'] += absint( isset( $round['productSteps'] ) ? $round['productSteps'] : 0 );
		$aggregate['productStatus'] = isset( $round['productStatus'] ) && is_array( $round['productStatus'] ) ? $round['productStatus'] : $aggregate['productStatus'];
		if ( null !== $round['lastStep'] ) {
			$aggregate['lastStep'] = $round['lastStep'];
		}

		foreach ( array( 'addressMapping', 'remoteShipping', 'maintenance' ) as $key ) {
			if ( isset( $round[ $key ] ) && is_array( $round[ $key ] ) && 'skipped' !== ( isset( $round[ $key ]['status'] ) ? $round[ $key ]['status'] : '' ) ) {
				$aggregate[ $key ] = $round[ $key ];
			}
		}

		if ( ! empty( $round['stageErrors'] ) && is_array( $round['stageErrors'] ) ) {
			$aggregate['runnerErrors'] = array_slice( array_merge( $aggregate['runnerErrors'], $round['stageErrors'] ), -20 );
		}

		if ( ! empty( $round['contendedStages'] ) && is_array( $round['contendedStages'] ) ) {
			$aggregate['contendedStages'] = array_values( array_unique( array_merge( $aggregate['contendedStages'], array_map( 'sanitize_key', $round['contendedStages'] ) ) ) );
		}

		foreach ( $aggregate['queuePasses'] as $key => $count ) {
			$aggregate['queuePasses'][ $key ] = absint( $count ) + absint( isset( $round['queuePasses'][ $key ] ) ? $round['queuePasses'][ $key ] : 0 );
		}
	}

	/**
	 * Merge counters while preserving latest status/remaining flags.
	 *
	 * @param array $current Current aggregate.
	 * @param array $next Next result.
	 * @param array $sum_keys Numeric keys to sum.
	 * @param array $latest_bool_keys Boolean keys to take from latest result.
	 * @return array
	 */
	private function merge_queue_counters( $current, $next, $sum_keys, $latest_bool_keys ) {
		$current = is_array( $current ) ? $current : array();
		$next    = is_array( $next ) ? $next : array();
		$merged  = array_merge( $current, $next );

		foreach ( $sum_keys as $key ) {
			$merged[ $key ] = absint( isset( $current[ $key ] ) ? $current[ $key ] : 0 ) + absint( isset( $next[ $key ] ) ? $next[ $key ] : 0 );
		}

		$next_status = isset( $next['status'] ) ? sanitize_key( (string) $next['status'] ) : '';
		foreach ( $latest_bool_keys as $key ) {
			if ( 'skipped' === $next_status && array_key_exists( $key, $current ) ) {
				$merged[ $key ] = ! empty( $current[ $key ] );
			} else {
				$merged[ $key ] = ! empty( $next[ $key ] );
			}
		}

		return $merged;
	}

	/**
	 * Renew the current runner lease.
	 *
	 * @param string $token Token.
	 * @param int    $ttl TTL.
	 * @param int    $renewals Renewal counter.
	 * @return bool
	 */
	private function renew_runner_lock( $token, $ttl, &$renewals ) {
		$renewed = Mobo_Core_Lock::renew( 'real_cron_runner', $token, $ttl );
		if ( ! $renewed ) {
			return false;
		}

		/* Keep the outer request dispatcher alive with the same stage cadence as the
		 * inner runner lease. If dispatcher ownership is ever lost, fail closed: a
		 * second request may already have become eligible to claim the site. */
		if ( '' !== $this->dispatcher_token && class_exists( 'Mobo_Core_Self_Runner' ) ) {
			if ( ! Mobo_Core_Self_Runner::renew_worker_request( $this->dispatcher_token ) ) {
				return false;
			}
		}

		$renewals++;
		return true;
	}

	/**
	 * Parse PHP memory_limit into bytes. Zero means unlimited/unknown.
	 *
	 * @return int
	 */
	private function get_memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (float) $raw;
		if ( 'g' === $unit ) {
			$value *= 1024;
			$unit = 'm';
		}
		if ( 'm' === $unit ) {
			$value *= 1024;
			$unit = 'k';
		}
		if ( 'k' === $unit ) {
			$value *= 1024;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Stop before PHP reaches an OOM boundary. A continuation is scheduled only
	 * when the current slice actually made progress, avoiding tight restart loops.
	 *
	 * @param array $config Runtime config.
	 * @return bool
	 */
	private function has_memory_headroom( $config ) {
		$limit   = isset( $config['memoryLimitBytes'] ) ? absint( $config['memoryLimitBytes'] ) : 0;
		$reserve = isset( $config['memoryReserveBytes'] ) ? absint( $config['memoryReserveBytes'] ) : 0;

		if ( $limit <= 0 || ! function_exists( 'memory_get_usage' ) ) {
			return true;
		}

		return memory_get_usage( true ) + $reserve < $limit;
	}

	/**
	 * Whether enough cooperative time remains to start another stage.
	 *
	 * @param float $deadline Deadline.
	 * @param int   $margin Safety margin.
	 * @return bool
	 */
	private function has_time_remaining( $deadline, $margin = 0 ) {
		return microtime( true ) < ( (float) $deadline - max( 0, (int) $margin ) );
	}

	/**
	 * Compact stage exception for diagnostics and health reports.
	 *
	 * @param string    $stage Stage.
	 * @param Throwable $e Exception.
	 * @return array
	 */
	private function compact_stage_error( $stage, Throwable $e ) {
		return array(
			'stage'          => sanitize_key( (string) $stage ),
			'message'        => 'Cron stage raised an exception.',
			'exceptionClass' => get_class( $e ),
			'at'             => time(),
		);
	}

	/**
	 * Save compact last result.
	 *
	 * @param array $result Result.
	 * @return void
	 */
	private function save_last_result( $result ) {
		update_option( 'mobo_core_real_cron_last_result', $result, false );

		$source = is_array( $result ) && isset( $result['source'] ) ? sanitize_key( (string) $result['source'] ) : '';
		if ( self::is_real_scheduler_source( $source ) ) {
			$previous_scheduler_result = get_option( 'mobo_core_real_cron_last_scheduler_result', null );
			$had_verified_scheduler_history = is_array( $previous_scheduler_result )
				&& ! empty( $previous_scheduler_result )
				&& isset( $previous_scheduler_result['source'] )
				&& self::is_real_scheduler_source( sanitize_key( (string) $previous_scheduler_result['source'] ) );

			/*
			 * The pre-UX17 last-success option had broader semantics. On the first
			 * scheduler result seen by the corrected provenance model, clear that
			 * legacy value unless the new scheduler result itself is fully healthy.
			 * A healthy result has already refreshed the timestamp before this method
			 * runs; a partial/error result must not inherit stale green evidence.
			 */
			$result_status = is_array( $result ) && isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '';
			if ( ! $had_verified_scheduler_history && 'ok' !== $result_status ) {
				update_option( 'mobo_core_real_cron_last_success_at', 0, false );
			}

			update_option( 'mobo_core_real_cron_last_scheduler_result', $result, false );
		}
	}

	/**
	 * Build a compact, JSON-safe exception result for admin/CLI diagnostics.
	 *
	 * @param string    $status Status key.
	 * @param Throwable $e Exception.
	 * @param array     $extra Extra fields.
	 * @return array
	 */
	private function exception_result( $status, Throwable $e, $extra = array() ) {
		$result = array(
			'success'        => false,
			'status'         => sanitize_key( (string) $status ),
			'message'        => 'Cron runner raised an exception.',
			'exceptionClass' => get_class( $e ),
			'executedAt'     => time(),
		);

		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$result = array_merge( $result, $extra );
		}

		return $result;
	}
}
