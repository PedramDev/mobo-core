<?php
/**
 * Manual, resumable utf8mb4 conversion tool (owner decision: manual only).
 *
 * Nothing in this class runs automatically. Every DDL step is triggered by an
 * administrator or support clicking a button; cron never continues it. Between
 * steps the global runtime barrier is released, so product sync keeps running
 * (Mobo_Core_Unicode_Guard keeps writes safe on still-utf8mb3 columns).
 *
 * One step = one table (plus following small tables while the step budget
 * allows). Order and per-table strategy come from Mobo_Core_Database_Unicode_Planner:
 * term tables first, then Mobo tables, then the rest, smallest first; tables that
 * are already utf8mb4 are skipped; tables whose indexes would exceed the key
 * length limit are converted with ROW_FORMAT=DYNAMIC or skipped with a clear
 * message. Row counts are compared before/after every table.
 *
 * wp-config.php is never edited. If DB_CHARSET/DB_COLLATE still select a
 * three-byte charset after the physical conversion, the workflow stops in an
 * awaiting-config state.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Database_Unicode_Migration {

	const STATE_OPTION      = 'mobo_core_database_unicode_migration_state';
	const LAST_AUDIT_OPTION = 'mobo_core_database_unicode_migration_last_audit';
	const PRECHECK_OPTION   = 'mobo_core_database_unicode_precheck';
	const PRECHECK_TTL      = 3600;
	const BARRIER_TTL       = 7200;
	const PLAN_LIMIT        = 2000;
	const STEP_LOCK         = 'database_unicode_migration_step';
	const STEP_BUDGET       = 20;
	const SMALL_TABLE_BYTES = 8388608;

	/**
	 * Pre-check: server, per-table charset/size/strategy, estimate. Read-only.
	 * Stored for the admin screen; required (fresh) before start.
	 *
	 * @return array
	 */
	public static function precheck() {
		global $wpdb;
		$collected = self::collect_plan_inputs();
		if ( is_wp_error( $collected ) ) {
			return array( 'success' => false, 'error' => $collected->get_error_message() );
		}
		$plan = Mobo_Core_Database_Unicode_Planner::build( $collected['tables'], $collected['columns'], $collected['indexes'], $collected['server'], (string) $wpdb->prefix );
		$entries = array();
		foreach ( $plan['entries'] as $entry ) {
			$entries[] = $entry;
			if ( count( $entries ) >= 200 ) {
				break;
			}
		}
		$out = array(
			'success'               => true,
			'checkedAt'             => time(),
			'server'                => $collected['server'],
			'databaseName'          => $collected['databaseName'],
			'databaseCharset'       => $collected['databaseCharset'],
			'databaseCollation'     => $collected['databaseCollation'],
			'connectionCharset'     => isset( $wpdb->charset ) ? sanitize_key( (string) $wpdb->charset ) : '',
			'configCharset'         => defined( 'DB_CHARSET' ) ? sanitize_key( (string) DB_CHARSET ) : '',
			'targetCollation'       => self::preferred_collation(),
			'entries'               => $entries,
			'tablesToConvert'       => count( $plan['entries'] ),
			'tablesSkippedByIndex'  => count( array_filter( $plan['entries'], static function ( $e ) { return Mobo_Core_Database_Unicode_Planner::STRATEGY_SKIP === $e['strategy']; } ) ),
			'tablesAlreadyUtf8mb4'  => count( $plan['alreadyReady'] ),
			'totalBytes'            => $plan['totalBytes'],
			'totalEstimatedSeconds' => $plan['totalEstimatedSeconds'],
		);
		update_option( self::PRECHECK_OPTION, $out, false );
		return $out;
	}

	/** @return array Stored pre-check (may be stale/empty). */
	public static function get_precheck() {
		$p = get_option( self::PRECHECK_OPTION, array() );
		return is_array( $p ) ? $p : array();
	}

	/** @return bool */
	public static function precheck_is_fresh() {
		$p = self::get_precheck();
		return ! empty( $p['success'] ) && ( time() - absint( $p['checkedAt'] ?? 0 ) ) < self::PRECHECK_TTL;
	}

	/**
	 * Start a new run (manual). Executes the first step immediately.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function start( $source = 'admin' ) {
		$current = self::read_state();
		$status  = isset( $current['status'] ) ? sanitize_key( (string) $current['status'] ) : '';
		if ( in_array( $status, array( 'ready', 'paused', 'failed', 'partial', 'awaiting-config', 'draining', 'migrating' ), true ) ) {
			return self::resume( $source );
		}
		if ( ! class_exists( 'Mobo_Core_Database_Charset' ) ) {
			return self::failure_result( 'unicode-checker-unavailable', 'Database Unicode checker is unavailable.' );
		}
		$unicode = Mobo_Core_Database_Charset::get_status( false, true );
		if ( empty( $unicode['serverSupportsUtf8mb4'] ) ) {
			return self::failure_result( 'utf8mb4-unsupported', 'سرور دیتابیس از utf8mb4 پشتیبانی نمی‌کند.' );
		}
		$plan = self::build_plan( array() );
		if ( is_wp_error( $plan ) ) {
			return self::failure_result( $plan->get_error_code(), $plan->get_error_message() );
		}
		$now   = time();
		$state = array(
			'runId'              => wp_generate_uuid4(),
			'status'             => 'ready',
			'source'             => sanitize_key( (string) $source ),
			'startedAt'          => $now,
			'updatedAt'          => $now,
			'completedAt'        => 0,
			'failedAt'           => 0,
			'pausedAt'           => 0,
			'lastStepAt'         => 0,
			'lastCompletedTable' => '',
			'lastError'          => '',
			'barrierToken'       => '',
			'targetCharset'      => 'utf8mb4',
			'targetCollation'    => (string) $plan['targetCollation'],
			'databaseName'       => (string) $plan['databaseName'],
			'databasePending'    => ! empty( $plan['databasePending'] ),
			'tables'             => $plan['tables'],
			'tablePlan'          => $plan['tablePlan'],
			'tableResults'       => array(),
			'excludedTables'     => array(),
			'pendingTable'       => array(),
			'cursor'             => 0,
			'totalTables'        => count( $plan['tables'] ),
			'convertedTables'    => 0,
			'foreignKeyPreflight'=> $plan['foreignKeyPreflight'],
			'configActionNeeded' => false,
			'pauseRequested'     => false,
		);
		self::write_state( $state );
		return self::process_slice( $source . '-start' );
	}

	/**
	 * One manual step: convert the next table (and following small tables while
	 * the step budget allows). Never runs concurrently with itself.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function process_slice( $source = 'admin' ) {
		$state  = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		if ( empty( $state ) ) {
			return array( 'success' => true, 'status' => 'idle', 'needsContinuation' => false );
		}
		if ( 'awaiting-config' === $status ) {
			return self::try_finalize_after_config( $state, $source );
		}
		if ( ! in_array( $status, array( 'ready', 'draining', 'migrating' ), true ) ) {
			$out = self::public_state( $state );
			$out['success'] = 'failed' !== $status;
			return $out;
		}

		$step_lock = Mobo_Core_Lock::acquire( self::STEP_LOCK, self::BARRIER_TTL );
		if ( false === $step_lock ) {
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['stepLocked'] = true;
			$out['lastError'] = 'یک مرحله تبدیل دیگر هم‌اکنون در حال اجراست؛ چند لحظه بعد دوباره تلاش کنید.';
			return $out;
		}

		$started = microtime( true );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled on some shared hosts.
		}

		try {
			$state = self::read_state();

			/* A previous request may have died while its ALTER kept running on the
			 * server. Never start a second ALTER on the same table. */
			$pending = isset( $state['pendingTable'] ) && is_array( $state['pendingTable'] ) ? $state['pendingTable'] : array();
			if ( ! empty( $pending['table'] ) && self::alter_running( (string) $pending['table'] ) ) {
				$out = self::public_state( $state );
				$out['success'] = true;
				$out['lastError'] = sprintf( 'تبدیل جدول %s هنوز روی سرور دیتابیس در حال اجراست؛ پس از پایان آن دوباره کلیک کنید.', (string) $pending['table'] );
				return $out;
			}
			if ( ! empty( $pending['table'] ) ) {
				$state = self::reconcile_pending_table( $state );
			}

			if ( ! empty( $state['databasePending'] ) ) {
				$converted = self::convert_database_default( $state );
				$state     = self::read_state();
				if ( is_wp_error( $converted ) ) {
					$state['tableResults']['(database default)'] = array( 'status' => 'failed', 'error' => $converted->get_error_message(), 'at' => time() );
				}
				$state['databasePending'] = false;
				self::write_state( $state );
			}

			$steps = 0;
			while ( true ) {
				$state  = self::read_state();
				$tables = isset( $state['tables'] ) && is_array( $state['tables'] ) ? array_values( $state['tables'] ) : array();
				$cursor = absint( $state['cursor'] ?? 0 );
				if ( $cursor >= count( $tables ) ) {
					return self::finish_physical_conversion( $state, $source );
				}
				if ( ! empty( $state['pauseRequested'] ) ) {
					return self::pause_at_safe_boundary( $state, 'table' );
				}
				$table = (string) $tables[ $cursor ];
				$meta  = isset( $state['tablePlan'][ $table ] ) && is_array( $state['tablePlan'][ $table ] ) ? $state['tablePlan'][ $table ] : array( 'strategy' => 'convert', 'bytes' => 0 );

				/* Only convert another table in the same click when it is small and
				 * the step budget is not used up. */
				if ( $steps > 0 && ( ( microtime( true ) - $started ) > ( self::STEP_BUDGET / 2 ) || absint( $meta['bytes'] ?? 0 ) > self::SMALL_TABLE_BYTES ) ) {
					$out = self::public_state( $state );
					$out['success'] = true;
					$out['step'] = 'table';
					return $out;
				}

				$result = self::convert_one_table( $state, $table, $meta );
				$steps++;
				if ( isset( $result['stop'] ) ) {
					return $result['stop'];
				}
			}
		} finally {
			Mobo_Core_Lock::release( self::STEP_LOCK, $step_lock );
		}
	}

	/**
	 * Convert (or record a skip for) one table and advance the cursor.
	 *
	 * @param array  $state State.
	 * @param string $table Table.
	 * @param array  $meta Plan entry.
	 * @return array Empty to continue; ['stop' => result] to return.
	 */
	private static function convert_one_table( $state, $table, $meta ) {
		global $wpdb;
		$strategy = (string) ( $meta['strategy'] ?? Mobo_Core_Database_Unicode_Planner::STRATEGY_CONVERT );

		if ( Mobo_Core_Database_Unicode_Planner::STRATEGY_SKIP === $strategy ) {
			self::record_table_result( $table, array( 'status' => 'skipped', 'error' => (string) ( $meta['reason'] ?? 'Index key length.' ) ), true );
			return array();
		}
		if ( 0 !== strpos( $table, (string) $wpdb->prefix ) ) {
			self::record_table_result( $table, array( 'status' => 'failed', 'error' => 'Refusing to convert a table outside the active WordPress site prefix.' ), true );
			return array();
		}
		$readiness = self::table_readiness( $table );
		if ( ! is_wp_error( $readiness ) && ! empty( $readiness['ready'] ) ) {
			self::record_table_result( $table, array( 'status' => 'already', 'error' => '' ), false );
			return array();
		}

		/* Hold the barrier only for this table's DDL: Mobo workers drain first and
		 * resume right after; no sync is blocked between manual steps. */
		$barrier = self::ensure_barrier( $state );
		if ( is_wp_error( $barrier ) ) {
			return array( 'stop' => self::step_blocked( $barrier->get_error_message() ) );
		}
		$quiet = Mobo_Core_Upgrade_Coordinator::wait_for_quiescence( $barrier, 15, self::BARRIER_TTL );
		if ( is_wp_error( $quiet ) ) {
			self::release_barrier( self::read_state(), 'database-unicode-workers-busy' );
			$s = self::read_state();
			$s['barrierToken'] = '';
			self::write_state( $s );
			return array( 'stop' => self::step_blocked( 'Workerهای همگام‌سازی هنوز مشغول هستند؛ چند ثانیه بعد دوباره «تبدیل جدول بعدی» را بزنید.' ) );
		}

		$rows_before = self::exact_row_count( $table );
		$state = self::read_state();
		$state['status']       = 'migrating';
		$state['updatedAt']    = time();
		$state['pendingTable'] = array( 'table' => $table, 'rowsBefore' => $rows_before, 'startedAt' => time(), 'strategy' => $strategy );
		self::write_state( $state );

		$t0        = microtime( true );
		$converted = self::convert_table( $table, (string) $state['targetCollation'], Mobo_Core_Database_Unicode_Planner::STRATEGY_CONVERT_DYNAMIC === $strategy );
		$seconds   = round( microtime( true ) - $t0, 2 );
		$rows_after = is_wp_error( $converted ) ? null : self::exact_row_count( $table );

		self::release_barrier( self::read_state(), 'database-unicode-table-step' );
		$state = self::read_state();
		$state['barrierToken'] = '';
		$state['pendingTable'] = array();
		$state['status']       = 'ready';
		self::write_state( $state );

		if ( is_wp_error( $converted ) ) {
			self::record_table_result( $table, array( 'status' => 'failed', 'error' => $converted->get_error_message(), 'rowsBefore' => $rows_before, 'seconds' => $seconds ), true );
		} elseif ( null !== $rows_before && null !== $rows_after && $rows_before !== $rows_after ) {
			self::record_table_result( $table, array( 'status' => 'failed', 'error' => sprintf( 'تعداد ردیف قبل (%d) و بعد (%d) از تبدیل برابر نیست؛ جدول را بررسی کنید.', $rows_before, $rows_after ), 'rowsBefore' => $rows_before, 'rowsAfter' => $rows_after, 'seconds' => $seconds ), true );
		} else {
			self::record_table_result( $table, array( 'status' => 'converted', 'error' => '', 'rowsBefore' => $rows_before, 'rowsAfter' => $rows_after, 'seconds' => $seconds, 'rowFormatDynamic' => Mobo_Core_Database_Unicode_Planner::STRATEGY_CONVERT_DYNAMIC === $strategy ), false );
		}
		Mobo_Core_Database_Charset::reset_cache();
		if ( class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			Mobo_Core_Unicode_Guard::reset_cache();
		}
		return array();
	}

	/**
	 * Store a per-table result and advance the cursor.
	 *
	 * @param string $table Table.
	 * @param array  $result Result.
	 * @param bool   $exclude Exclude from re-plans of this run.
	 * @return void
	 */
	private static function record_table_result( $table, $result, $exclude ) {
		$state = self::read_state();
		$result['at'] = time();
		if ( isset( $result['error'] ) ) {
			$result['error'] = substr( sanitize_text_field( (string) $result['error'] ), 0, 400 );
		}
		$state['tableResults'][ $table ] = $result;
		if ( $exclude ) {
			$state['excludedTables'][] = $table;
			$state['excludedTables']   = array_values( array_unique( $state['excludedTables'] ) );
			$state['lastError']        = $table . ': ' . (string) ( $result['error'] ?? '' );
		} else {
			$state['convertedTables']    = absint( $state['convertedTables'] ?? 0 ) + 1;
			$state['lastCompletedTable'] = $table;
		}
		$state['cursor']     = absint( $state['cursor'] ?? 0 ) + 1;
		$state['lastStepAt'] = time();
		$state['updatedAt']  = time();
		self::write_state( $state );
	}

	/**
	 * Resolve a checkpoint whose request died during an ALTER that has finished.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function reconcile_pending_table( $state ) {
		$pending = $state['pendingTable'];
		$table   = (string) $pending['table'];
		$tables  = isset( $state['tables'] ) && is_array( $state['tables'] ) ? array_values( $state['tables'] ) : array();
		$cursor  = absint( $state['cursor'] ?? 0 );
		self::release_barrier( $state, 'database-unicode-recovered' );
		$state['barrierToken'] = '';
		$state['pendingTable'] = array();
		$state['status']       = 'ready';
		self::write_state( $state );
		if ( ! isset( $tables[ $cursor ] ) || $tables[ $cursor ] !== $table ) {
			return self::read_state();
		}
		$readiness = self::table_readiness( $table );
		if ( ! is_wp_error( $readiness ) && ! empty( $readiness['ready'] ) ) {
			$after  = self::exact_row_count( $table );
			$before = isset( $pending['rowsBefore'] ) ? $pending['rowsBefore'] : null;
			if ( null !== $before && null !== $after && (int) $before !== (int) $after ) {
				self::record_table_result( $table, array( 'status' => 'failed', 'error' => sprintf( 'تعداد ردیف قبل (%d) و بعد (%d) از تبدیل برابر نیست.', $before, $after ) ), true );
			} else {
				self::record_table_result( $table, array( 'status' => 'converted', 'error' => '', 'rowsBefore' => $before, 'rowsAfter' => $after, 'recovered' => true ), false );
			}
		}
		/* Not ready: the ALTER was rolled back by the server; the cursor stays and
		 * the table is simply converted again on this step. */
		return self::read_state();
	}

	/** @return array */
	private static function step_blocked( $message ) {
		$state = self::read_state();
		$state['status']    = 'ready';
		$state['updatedAt'] = time();
		$state['lastError'] = substr( sanitize_text_field( (string) $message ), 0, 400 );
		self::write_state( $state );
		$out = self::public_state( $state );
		$out['success'] = true;
		$out['blocked'] = true;
		return $out;
	}

	/**
	 * Whether an ALTER on the table is still executing (own-user threads are
	 * visible in PROCESSLIST even on shared hosting).
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	private static function alter_running( $table ) {
		global $wpdb;
		$old   = (string) $wpdb->last_error;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' AND INFO LIKE %s AND ID <> CONNECTION_ID()",
				'ALTER TABLE ' . $wpdb->esc_like( self::quote_identifier( $table ) ) . '%'
			)
		);
		$wpdb->last_error = $old;
		return absint( $count ) > 0;
	}

	/** @return int|null */
	private static function exact_row_count( $table ) {
		global $wpdb;
		$old   = (string) $wpdb->last_error;
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::quote_identifier( $table ) );
		$error = (string) $wpdb->last_error;
		$wpdb->last_error = $old;
		return ( null === $count || '' !== $error ) ? null : (int) $count;
	}

	/**
	 * Mark a pause; between manual steps nothing runs, so this only blocks the
	 * next click until Resume (or stops a step after its current table).
	 *
	 * @return array
	 */
	public static function pause() {
		$state  = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		if ( ! in_array( $status, array( 'ready', 'draining', 'migrating' ), true ) ) {
			return self::public_state( $state );
		}
		$step = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( self::STEP_LOCK ) : array();
		if ( ! empty( $step['active'] ) ) {
			$state['pauseRequested'] = true;
			$state['updatedAt']      = time();
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['pauseRequested'] = true;
			return $out;
		}
		return self::pause_at_safe_boundary( $state, 'manual' );
	}

	/**
	 * Resume (manual): re-plan the remaining tables and run one step.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function resume( $source = 'admin' ) {
		$state = self::read_state();
		if ( empty( $state ) ) {
			return self::start( $source );
		}
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		if ( 'completed' === $status ) {
			return self::public_state( $state );
		}
		if ( 'awaiting-config' === $status ) {
			return self::try_finalize_after_config( $state, $source );
		}
		if ( in_array( $status, array( 'paused', 'failed', 'partial' ), true ) ) {
			/* Retry previously failed/skipped tables too: the operator may have fixed
			 * the cause (e.g. enabled large prefixes). */
			$plan = self::build_plan( array() );
			if ( is_wp_error( $plan ) ) {
				return self::failure_result( $plan->get_error_code(), $plan->get_error_message() );
			}
			$state['status']          = 'ready';
			$state['source']          = sanitize_key( (string) $source );
			$state['updatedAt']       = time();
			$state['failedAt']        = 0;
			$state['pausedAt']        = 0;
			$state['lastError']       = '';
			$state['barrierToken']    = '';
			$state['pauseRequested']  = false;
			$state['targetCollation'] = (string) $plan['targetCollation'];
			$state['databasePending'] = ! empty( $plan['databasePending'] );
			$state['tables']          = $plan['tables'];
			$state['tablePlan']       = $plan['tablePlan'];
			$state['excludedTables']  = array();
			$state['cursor']          = 0;
			$state['totalTables']     = count( $plan['tables'] );
			$state['convertedTables'] = 0;
			self::write_state( $state );
		}
		return self::process_slice( $source . '-resume' );
	}

	/**
	 * Mobo workers are blocked only while a table DDL is actually running in the
	 * current step (the barrier also covers that). A stale "migrating" checkpoint
	 * from a killed request stops blocking after the barrier TTL.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function should_block_runtime_lock( $name ) {
		$name = sanitize_key( (string) $name );
		if ( in_array( $name, array( 'plugin_upgrade_barrier', 'remote_plugin_upgrade', self::STEP_LOCK, 'health_snapshot_refresh' ), true ) ) {
			return false;
		}
		$state  = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		if ( ! in_array( $status, array( 'draining', 'migrating' ), true ) ) {
			return false;
		}
		return ( time() - absint( $state['updatedAt'] ?? 0 ) ) < self::BARRIER_TTL;
	}

	/**
	 * Owner decision: conversion runs only from an explicit admin/support click.
	 * Cron must never continue it.
	 *
	 * @return bool
	 */
	public static function should_continue_automatically() {
		return false;
	}

	/** @return array */
	public static function get_status() {
		$state = self::read_state();
		if ( empty( $state ) ) {
			return array(
				'status'             => 'idle',
				'active'             => false,
				'needsContinuation'  => false,
				'configActionNeeded' => false,
			);
		}
		return self::public_state( $state );
	}

	/**
	 * Read-only metadata used by the planner.
	 *
	 * @return array|WP_Error
	 */
	private static function collect_plan_inputs() {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? trim( (string) DB_NAME ) : trim( (string) $wpdb->get_var( 'SELECT DATABASE()' ) );
		if ( '' === $db_name ) {
			return new WP_Error( 'database-name-unavailable', 'Could not resolve the active WordPress database name.' );
		}
		$pattern = $wpdb->esc_like( (string) $wpdb->prefix ) . '%';
		$tables  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME LIMIT %d",
				$db_name,
				$pattern,
				self::PLAN_LIMIT + 1
			),
			ARRAY_A
		);
		if ( ! is_array( $tables ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'table-plan-query-failed', self::bounded_db_error( 'Could not enumerate WordPress tables for Unicode conversion.' ) );
		}
		if ( count( $tables ) > self::PLAN_LIMIT ) {
			return new WP_Error( 'table-plan-too-large', 'Unicode conversion plan exceeds the bounded table limit.' );
		}
		$columns = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND CHARACTER_SET_NAME IS NOT NULL',
				$db_name,
				$pattern
			),
			ARRAY_A
		);
		if ( ! is_array( $columns ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'column-plan-query-failed', self::bounded_db_error( 'Could not inspect text columns for Unicode conversion.' ) );
		}
		$indexes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.TABLE_NAME, s.INDEX_NAME, s.INDEX_TYPE, s.SEQ_IN_INDEX, s.SUB_PART, c.DATA_TYPE, c.CHARACTER_MAXIMUM_LENGTH, c.CHARACTER_OCTET_LENGTH, c.CHARACTER_SET_NAME
				 FROM information_schema.STATISTICS s
				 JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = s.TABLE_SCHEMA AND c.TABLE_NAME = s.TABLE_NAME AND c.COLUMN_NAME = s.COLUMN_NAME
				 WHERE s.TABLE_SCHEMA = %s AND s.TABLE_NAME LIKE %s
				 ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX
				 LIMIT 20001',
				$db_name,
				$pattern
			),
			ARRAY_A
		);
		if ( ! is_array( $indexes ) ) {
			return new WP_Error( 'index-preflight-query-failed', self::bounded_db_error( 'Could not inspect indexes before utf8mb4 conversion.' ) );
		}
		$old          = (string) $wpdb->last_error;
		$version      = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$large_prefix = $wpdb->get_var( 'SELECT @@innodb_large_prefix' );
		$file_format  = $wpdb->get_var( 'SELECT @@innodb_file_format' );
		$wpdb->last_error = $old;
		$db_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT DEFAULT_CHARACTER_SET_NAME AS charset_name, DEFAULT_COLLATION_NAME AS collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s LIMIT 1',
				$db_name
			),
			ARRAY_A
		);
		return array(
			'databaseName'      => $db_name,
			'databaseCharset'   => is_array( $db_row ) ? strtolower( trim( (string) ( $db_row['charset_name'] ?? '' ) ) ) : '',
			'databaseCollation' => is_array( $db_row ) ? strtolower( trim( (string) ( $db_row['collation_name'] ?? '' ) ) ) : '',
			'tables'            => $tables,
			'columns'           => $columns,
			'indexes'           => $indexes,
			'server'            => Mobo_Core_Database_Unicode_Planner::server_info( $version, $large_prefix, $file_format ),
		);
	}

	/**
	 * Ordered remaining plan.
	 *
	 * @param array $excluded Tables excluded from this run.
	 * @return array|WP_Error
	 */
	private static function build_plan( $excluded ) {
		global $wpdb;
		$collation = self::preferred_collation();
		if ( '' === $collation ) {
			return new WP_Error( 'utf8mb4-collation-unavailable', 'No supported utf8mb4 collation is available on the database server.' );
		}
		$collected = self::collect_plan_inputs();
		if ( is_wp_error( $collected ) ) {
			return $collected;
		}
		$plan = Mobo_Core_Database_Unicode_Planner::build( $collected['tables'], $collected['columns'], $collected['indexes'], $collected['server'], (string) $wpdb->prefix, $excluded );
		$tables     = array();
		$table_plan = array();
		foreach ( $plan['entries'] as $entry ) {
			$tables[] = $entry['table'];
			$table_plan[ $entry['table'] ] = array(
				'strategy'         => $entry['strategy'],
				'reason'           => $entry['reason'],
				'bytes'            => $entry['bytes'],
				'rows'             => $entry['rows'],
				'priority'         => $entry['priority'],
				'maxIndexBytes'    => $entry['maxIndexBytes'],
				'estimatedSeconds' => $entry['estimatedSeconds'],
			);
		}
		$fk = self::preflight_foreign_key_compatibility( $collected['databaseName'], $tables, $collation );
		if ( is_wp_error( $fk ) ) {
			return $fk;
		}
		return array(
			'databaseName'        => $collected['databaseName'],
			'targetCollation'     => $collation,
			'databasePending'     => 'utf8mb4' !== $collected['databaseCharset'] || ! Mobo_Core_Database_Charset::is_full_unicode_collation( $collected['databaseCollation'] ),
			'tables'              => $tables,
			'tablePlan'           => $table_plan,
			'foreignKeyPreflight' => $fk,
		);
	}

	/**
	 * Refuse one-table-at-a-time conversion when a textual foreign-key column
	 * would change charset/collation on only one side of the relationship. MySQL
	 * requires compatible FK column definitions; discovering this after earlier
	 * tables were converted would leave a needless partial migration.
	 *
	 * @param string $db_name Database name.
	 * @param array  $planned_tables Tables requiring conversion.
	 * @param string $target_collation Target utf8mb4 collation.
	 * @return array|WP_Error
	 */
	private static function preflight_foreign_key_compatibility( $db_name, $planned_tables, $target_collation ) {
		global $wpdb;
		if ( empty( $planned_tables ) ) {
			return array( 'checkedForeignKeys' => 0, 'safe' => true );
		}

		$planned_tables = array_values( array_unique( array_map( 'strval', $planned_tables ) ) );
		$planned = array_fill_keys( $planned_tables, true );
		$placeholders = implode( ', ', array_fill( 0, count( $planned_tables ), '%s' ) );
		$query = "SELECT k.CONSTRAINT_NAME, k.TABLE_NAME AS CHILD_TABLE, k.COLUMN_NAME AS CHILD_COLUMN,
				k.REFERENCED_TABLE_NAME AS PARENT_TABLE, k.REFERENCED_COLUMN_NAME AS PARENT_COLUMN,
				cc.CHARACTER_SET_NAME AS CHILD_CHARSET, cc.COLLATION_NAME AS CHILD_COLLATION,
				pc.CHARACTER_SET_NAME AS PARENT_CHARSET, pc.COLLATION_NAME AS PARENT_COLLATION
			 FROM information_schema.KEY_COLUMN_USAGE k
			 JOIN information_schema.COLUMNS cc ON cc.TABLE_SCHEMA=k.TABLE_SCHEMA AND cc.TABLE_NAME=k.TABLE_NAME AND cc.COLUMN_NAME=k.COLUMN_NAME
			 JOIN information_schema.COLUMNS pc ON pc.TABLE_SCHEMA=k.REFERENCED_TABLE_SCHEMA AND pc.TABLE_NAME=k.REFERENCED_TABLE_NAME AND pc.COLUMN_NAME=k.REFERENCED_COLUMN_NAME
			 WHERE k.TABLE_SCHEMA=%s AND k.REFERENCED_TABLE_SCHEMA=%s AND k.REFERENCED_TABLE_NAME IS NOT NULL
			   AND (k.TABLE_NAME IN (" . $placeholders . ") OR k.REFERENCED_TABLE_NAME IN (" . $placeholders . "))
			   AND (cc.CHARACTER_SET_NAME IS NOT NULL OR pc.CHARACTER_SET_NAME IS NOT NULL)
			 ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
			 LIMIT 501";
		$args = array_merge( array( $db_name, $db_name ), $planned_tables, $planned_tables );
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'foreign-key-preflight-query-failed', self::bounded_db_error( 'Could not inspect foreign keys before utf8mb4 conversion.' ) );
		}
		if ( count( $rows ) > 500 ) {
			return new WP_Error( 'foreign-key-preflight-too-large', 'Unicode conversion foreign-key inspection exceeded the bounded relationship limit.' );
		}

		$target_collation = strtolower( trim( (string) $target_collation ) );
		$blockers = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$constraint = (string) ( $row['CONSTRAINT_NAME'] ?? '' );
			$child_table = (string) ( $row['CHILD_TABLE'] ?? '' );
			$parent_table = (string) ( $row['PARENT_TABLE'] ?? '' );
			$key = $child_table . '|' . $constraint;
			$seen[ $key ] = true;

			$child_planned = isset( $planned[ $child_table ] );
			$parent_planned = isset( $planned[ $parent_table ] );
			$child_charset = strtolower( trim( (string) ( $row['CHILD_CHARSET'] ?? '' ) ) );
			$parent_charset = strtolower( trim( (string) ( $row['PARENT_CHARSET'] ?? '' ) ) );
			$child_collation = strtolower( trim( (string) ( $row['CHILD_COLLATION'] ?? '' ) ) );
			$parent_collation = strtolower( trim( (string) ( $row['PARENT_COLLATION'] ?? '' ) ) );

			$child_would_change = $child_planned && ( 'utf8mb4' !== $child_charset || $target_collation !== $child_collation );
			$parent_would_change = $parent_planned && ( 'utf8mb4' !== $parent_charset || $target_collation !== $parent_collation );
			if ( ! $child_would_change && ! $parent_would_change ) {
				continue;
			}

			$blockers[] = array(
				'constraint' => sanitize_text_field( $constraint ),
				'child' => sanitize_text_field( $child_table . '.' . (string) ( $row['CHILD_COLUMN'] ?? '' ) ),
				'parent' => sanitize_text_field( $parent_table . '.' . (string) ( $row['PARENT_COLUMN'] ?? '' ) ),
			);
			if ( count( $blockers ) >= 10 ) {
				break;
			}
		}

		if ( ! empty( $blockers ) ) {
			$first = $blockers[0];
			return new WP_Error(
				'utf8mb4-foreign-key-preflight-failed',
				sprintf(
					'Foreign key %s (%s -> %s) uses text columns whose definition would change during one-table-at-a-time utf8mb4 conversion. Resolve or temporarily remove/recreate the FK before starting migration.',
					(string) $first['constraint'],
					(string) $first['child'],
					(string) $first['parent']
				)
			);
		}

		return array(
			'checkedForeignKeys' => count( $seen ),
			'safe' => true,
		);
	}

	/** @return string */
	private static function preferred_collation() {
		global $wpdb;
		$candidates = array();
		if ( defined( 'DB_COLLATE' ) && Mobo_Core_Database_Charset::is_full_unicode_collation( DB_COLLATE ) ) {
			$candidates[] = strtolower( trim( (string) DB_COLLATE ) );
		}
		if ( isset( $wpdb->collate ) && Mobo_Core_Database_Charset::is_full_unicode_collation( $wpdb->collate ) ) {
			$candidates[] = strtolower( trim( (string) $wpdb->collate ) );
		}
		$candidates[] = 'utf8mb4_unicode_ci';
		$candidates[] = 'utf8mb4_general_ci';

		$available = $wpdb->get_col( $wpdb->prepare( 'SHOW COLLATION WHERE Charset = %s', 'utf8mb4' ), 0 );
		$set = array();
		foreach ( is_array( $available ) ? $available : array() as $item ) {
			$item = strtolower( trim( (string) $item ) );
			if ( '' !== $item ) {
				$set[ $item ] = true;
			}
		}
		foreach ( $candidates as $candidate ) {
			if ( isset( $set[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/** @return string|WP_Error */
	private static function ensure_barrier( $state ) {
		if ( ! class_exists( 'Mobo_Core_Upgrade_Coordinator' ) || ! class_exists( 'Mobo_Core_Lock' ) ) {
			return new WP_Error( 'runtime-barrier-unavailable', 'Runtime barrier is unavailable; conversion cannot start safely.' );
		}

		$token = isset( $state['barrierToken'] ) ? trim( (string) $state['barrierToken'] ) : '';
		if ( '' !== $token && Mobo_Core_Upgrade_Coordinator::renew( $token, self::BARRIER_TTL ) ) {
			return $token;
		}

		$barrier_status = Mobo_Core_Upgrade_Coordinator::get_status();
		if ( ! empty( $barrier_status['active'] ) ) {
			return new WP_Error( 'runtime-barrier-busy', 'Another protected upgrade/maintenance operation currently owns the runtime barrier.' );
		}

		$run_id = isset( $state['runId'] ) ? sanitize_text_field( (string) $state['runId'] ) : wp_generate_uuid4();
		$token = Mobo_Core_Upgrade_Coordinator::activate( 'database-utf8mb4-' . $run_id, self::BARRIER_TTL );
		if ( false === $token ) {
			return new WP_Error( 'runtime-barrier-acquire-failed', 'Could not acquire the runtime barrier for database conversion.' );
		}

		$state['barrierToken'] = $token;
		$state['updatedAt'] = time();
		self::write_state( $state );
		if ( ! Mobo_Core_Upgrade_Coordinator::mark_stage( $token, 'database-unicode-migration' )
			|| ! Mobo_Core_Upgrade_Coordinator::renew( $token, self::BARRIER_TTL ) ) {
			Mobo_Core_Upgrade_Coordinator::release( $token, 'database-unicode-barrier-stage-failed' );
			$state['barrierToken'] = '';
			self::write_state( $state );
			return new WP_Error( 'runtime-barrier-stage-failed', 'Could not establish the extended database-maintenance barrier lease.' );
		}
		return $token;
	}

	/** @return true|WP_Error */
	private static function convert_database_default( $state ) {
		global $wpdb;
		$db_name = isset( $state['databaseName'] ) ? (string) $state['databaseName'] : '';
		$collation = isset( $state['targetCollation'] ) ? (string) $state['targetCollation'] : '';
		if ( '' === $db_name || '' === $collation ) {
			return new WP_Error( 'database-conversion-state-invalid', 'Database conversion state is incomplete.' );
		}

		/* A previous request may have completed the DDL after PHP lost the response
		 * but before the checkpoint was persisted. Verify first so retry is idempotent. */
		if ( self::database_default_is_ready( $db_name ) ) {
			return true;
		}

		$sql = 'ALTER DATABASE ' . self::quote_identifier( $db_name ) . ' CHARACTER SET utf8mb4 COLLATE ' . self::collation_sql( $collation );
		if ( false === $wpdb->query( $sql ) ) {
			return new WP_Error( 'database-default-convert-failed', self::bounded_db_error( 'Could not convert the database default charset/collation.' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT DEFAULT_CHARACTER_SET_NAME AS charset_name, DEFAULT_COLLATION_NAME AS collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s LIMIT 1',
				$db_name
			),
			ARRAY_A
		);
		$charset = is_array( $row ) ? strtolower( trim( (string) ( $row['charset_name'] ?? '' ) ) ) : '';
		$actual_collation = is_array( $row ) ? strtolower( trim( (string) ( $row['collation_name'] ?? '' ) ) ) : '';
		if ( 'utf8mb4' !== $charset || ! Mobo_Core_Database_Charset::is_full_unicode_collation( $actual_collation ) ) {
			return new WP_Error( 'database-default-verify-failed', 'Database default conversion did not verify as utf8mb4.' );
		}
		Mobo_Core_Database_Charset::reset_cache();
		return true;
	}

	/**
	 * Build the DDL for one table (public for tests and the pre-check screen).
	 *
	 * @param string $table Table.
	 * @param string $collation Target collation.
	 * @param bool   $dynamic Also switch ROW_FORMAT=DYNAMIC (index prefix limit).
	 * @return string
	 */
	public static function conversion_sql( $table, $collation, $dynamic = false ) {
		return 'ALTER TABLE ' . self::quote_identifier( $table )
			. ( $dynamic ? ' ROW_FORMAT=DYNAMIC,' : '' )
			. ' CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . self::collation_sql( $collation );
	}

	/** @return true|WP_Error */
	private static function convert_table( $table, $collation, $dynamic = false ) {
		global $wpdb;
		$table     = (string) $table;
		$collation = strtolower( trim( (string) $collation ) );
		if ( '' === $table || '' === $collation ) {
			return new WP_Error( 'table-conversion-state-invalid', 'Table conversion state is incomplete.' );
		}
		if ( 0 !== strpos( $table, (string) $wpdb->prefix ) ) {
			return new WP_Error( 'table-outside-site-prefix', 'Refusing to convert a table outside the active WordPress site prefix.' );
		}
		$preflight = self::table_readiness( $table );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( ! empty( $preflight['ready'] ) ) {
			return true;
		}
		if ( false === $wpdb->query( self::conversion_sql( $table, $collation, $dynamic ) ) ) {
			return new WP_Error( 'table-convert-failed', self::bounded_db_error( 'Could not convert table ' . $table . ' to utf8mb4.' ) );
		}
		$verify = self::table_readiness( $table );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}
		return ! empty( $verify['ready'] )
			? true
			: new WP_Error( 'table-columns-verify-failed', 'Table or one of its text columns remained below utf8mb4 after conversion.' );
	}

	/** @return array|WP_Error */
	private static function table_readiness( $table ) {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : trim( (string) $wpdb->get_var( 'SELECT DATABASE()' ) );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
				$db_name,
				$table
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'table-readiness-query-failed', self::bounded_db_error( 'Could not inspect table Unicode readiness.' ) );
		}
		if ( ! is_array( $row ) ) {
			return array( 'ready' => true, 'exists' => false );
		}
		$default_ready = Mobo_Core_Database_Charset::is_full_unicode_collation( (string) ( $row['TABLE_COLLATION'] ?? '' ) );
		$bad = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> %s',
				$db_name,
				$table,
				'utf8mb4'
			)
		);
		if ( null === $bad || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'column-readiness-query-failed', self::bounded_db_error( 'Could not inspect text column Unicode readiness.' ) );
		}
		$bad = absint( $bad );
		return array( 'ready' => $default_ready && 0 === $bad, 'exists' => true, 'unsupportedColumns' => $bad );
	}

	/** @return bool */
	private static function database_default_is_ready( $db_name ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT DEFAULT_CHARACTER_SET_NAME AS charset_name, DEFAULT_COLLATION_NAME AS collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s LIMIT 1',
				$db_name
			),
			ARRAY_A
		);
		$charset   = is_array( $row ) ? strtolower( trim( (string) ( $row['charset_name'] ?? '' ) ) ) : '';
		$collation = is_array( $row ) ? strtolower( trim( (string) ( $row['collation_name'] ?? '' ) ) ) : '';
		return 'utf8mb4' === $charset && Mobo_Core_Database_Charset::is_full_unicode_collation( $collation );
	}

	/** @return array */
	private static function finish_physical_conversion( $state, $source ) {
		$excluded  = isset( $state['excludedTables'] ) && is_array( $state['excludedTables'] ) ? $state['excludedTables'] : array();
		$remaining = self::build_plan( $excluded );
		if ( is_wp_error( $remaining ) ) {
			return self::fail_run( $state, $remaining->get_error_message(), $remaining->get_error_code() );
		}
		if ( ! empty( $remaining['tables'] ) ) {
			/* A table appeared (or was not converted) since planning. */
			$state['tables']      = $remaining['tables'];
			$state['tablePlan']   = $remaining['tablePlan'];
			$state['cursor']      = 0;
			$state['totalTables'] = count( $remaining['tables'] );
			$state['status']      = 'ready';
			$state['updatedAt']   = time();
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['step']    = 'replan';
			return $out;
		}

		Mobo_Core_Database_Charset::reset_cache();
		if ( class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			Mobo_Core_Unicode_Guard::reset_cache();
		}
		if ( ! empty( $excluded ) ) {
			$state['status']    = 'partial';
			$state['updatedAt'] = time();
			$state['lastError'] = sprintf( 'تبدیل انجام شد اما %d جدول تبدیل نشد (جزئیات هر جدول در جدول نتایج). همگام‌سازی با جایگزینی خودکار کاراکترهای ۴ بایتی ادامه دارد.', count( $excluded ) );
			self::write_state( $state );
			self::write_audit( $state, 'partial' );
			$out = self::public_state( $state );
			$out['success'] = true;
			return $out;
		}

		$unicode = Mobo_Core_Database_Charset::get_status( true, true );
		if ( ! empty( $unicode['ready'] ) ) {
			return self::complete_run( $state, $source, $unicode );
		}
		$config_problem = empty( $unicode['configReady'] ) || empty( $unicode['wpdbReady'] ) || empty( $unicode['connectionReady'] );
		if ( $config_problem ) {
			$state['status']             = 'awaiting-config';
			$state['configActionNeeded'] = true;
			$state['updatedAt']          = time();
			$state['lastError']          = isset( $unicode['message'] ) ? sanitize_text_field( (string) $unicode['message'] ) : 'WordPress database configuration still requires utf8mb4.';
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['success']         = true;
			$out['databaseUnicode'] = $unicode;
			return $out;
		}
		$state['status']    = 'partial';
		$state['updatedAt'] = time();
		$state['lastError'] = isset( $unicode['message'] ) ? sanitize_text_field( (string) $unicode['message'] ) : 'Unicode verification is not ready.';
		self::write_state( $state );
		$out = self::public_state( $state );
		$out['success']         = true;
		$out['databaseUnicode'] = $unicode;
		return $out;
	}

	/** @return array */
	private static function try_finalize_after_config( $state, $source ) {
		Mobo_Core_Database_Charset::reset_cache();
		$unicode = Mobo_Core_Database_Charset::get_status( true, true );
		if ( ! empty( $unicode['ready'] ) ) {
			return self::complete_run( $state, $source, $unicode );
		}
		$state['configActionNeeded'] = empty( $unicode['configReady'] ) || empty( $unicode['wpdbReady'] ) || empty( $unicode['connectionReady'] );
		$state['updatedAt']          = time();
		$state['lastError']          = isset( $unicode['message'] ) ? sanitize_text_field( (string) $unicode['message'] ) : 'Unicode verification is not ready.';
		if ( empty( $state['configActionNeeded'] ) ) {
			$state['status'] = 'partial';
		}
		self::write_state( $state );
		$out = self::public_state( $state );
		$out['success']         = true;
		$out['databaseUnicode'] = $unicode;
		return $out;
	}

	/** @return array */
	private static function complete_run( $state, $source, $unicode ) {
		self::release_barrier( $state, 'database-unicode-completed' );
		$now = time();
		$state['status']             = 'completed';
		$state['source']             = sanitize_key( (string) $source );
		$state['completedAt']        = $now;
		$state['updatedAt']          = $now;
		$state['barrierToken']       = '';
		$state['configActionNeeded'] = false;
		$state['lastError']          = '';
		self::write_state( $state );
		self::write_audit( $state, 'completed', array( 'roundTripSuccess' => ! empty( $unicode['roundTrip']['success'] ) ) );
		/* Readiness db_charset becomes ok: forget cached column support and the
		 * replacement counters collected while the tables were utf8mb3. */
		if ( class_exists( 'Mobo_Core_Unicode_Guard' ) ) {
			Mobo_Core_Unicode_Guard::reset_cache( true );
		}
		$out = self::public_state( $state );
		$out['success']         = true;
		$out['databaseUnicode'] = $unicode;
		return $out;
	}

	/** @return array */
	private static function fail_run( $state, $message, $code = 'migration-failed' ) {
		self::release_barrier( $state, 'database-unicode-failed', array( 'lastError' => $message ) );
		$now = time();
		$state['status']       = 'failed';
		$state['failedAt']     = $now;
		$state['updatedAt']    = $now;
		$state['barrierToken'] = '';
		$state['lastError']    = substr( sanitize_text_field( (string) $message ), 0, 500 );
		$state['errorCode']    = sanitize_key( (string) $code );
		self::write_state( $state );
		self::write_audit( $state, 'failed', array( 'errorCode' => sanitize_key( (string) $code ), 'lastError' => $state['lastError'] ) );
		$out = self::public_state( $state );
		$out['success'] = false;
		return $out;
	}

	/** @return void */
	private static function write_audit( $state, $result, $extra = array() ) {
		update_option(
			self::LAST_AUDIT_OPTION,
			array_merge(
				array(
					'runId'              => sanitize_text_field( (string) ( $state['runId'] ?? '' ) ),
					'result'             => sanitize_key( (string) $result ),
					'startedAt'          => absint( $state['startedAt'] ?? 0 ),
					'at'                 => time(),
					'totalTables'        => absint( $state['totalTables'] ?? 0 ),
					'excludedTables'     => array_slice( isset( $state['excludedTables'] ) && is_array( $state['excludedTables'] ) ? $state['excludedTables'] : array(), 0, 50 ),
					'lastCompletedTable' => sanitize_text_field( (string) ( $state['lastCompletedTable'] ?? '' ) ),
				),
				is_array( $extra ) ? $extra : array()
			),
			false
		);
	}

	/** @return array */
	private static function failure_result( $code, $message ) {
		return array(
			'success'           => false,
			'status'            => 'failed',
			'errorCode'         => sanitize_key( (string) $code ),
			'lastError'         => substr( sanitize_text_field( (string) $message ), 0, 500 ),
			'needsContinuation' => false,
		);
	}

	/** @return array */
	private static function pause_at_safe_boundary( $state, $step ) {
		self::release_barrier( $state, 'database-unicode-paused' );
		$state = self::read_state();
		$state['status']         = 'paused';
		$state['pausedAt']       = time();
		$state['updatedAt']      = time();
		$state['barrierToken']   = '';
		$state['pauseRequested'] = false;
		self::write_state( $state );
		$out = self::public_state( $state );
		$out['success']         = true;
		$out['pausedAfterStep'] = sanitize_key( (string) $step );
		return $out;
	}

	/** @return void */
	private static function release_barrier( $state, $result, $details = array() ) {
		$token = isset( $state['barrierToken'] ) ? trim( (string) $state['barrierToken'] ) : '';
		if ( '' !== $token && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
			Mobo_Core_Upgrade_Coordinator::release( $token, $result, $details );
		}
	}

	/** @return array */
	private static function public_state( $state ) {
		$state   = is_array( $state ) ? $state : array();
		$status  = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		$cursor  = absint( $state['cursor'] ?? 0 );
		$total   = absint( $state['totalTables'] ?? 0 );
		$results = isset( $state['tableResults'] ) && is_array( $state['tableResults'] ) ? $state['tableResults'] : array();
		return array(
			'active'             => in_array( $status, array( 'draining', 'migrating' ), true ),
			'status'             => $status,
			'manualOnly'         => true,
			'runId'              => sanitize_text_field( (string) ( $state['runId'] ?? '' ) ),
			'startedAt'          => absint( $state['startedAt'] ?? 0 ),
			'updatedAt'          => absint( $state['updatedAt'] ?? 0 ),
			'completedAt'        => absint( $state['completedAt'] ?? 0 ),
			'failedAt'           => absint( $state['failedAt'] ?? 0 ),
			'pausedAt'           => absint( $state['pausedAt'] ?? 0 ),
			'lastStepAt'         => absint( $state['lastStepAt'] ?? 0 ),
			'lastCompletedTable' => sanitize_text_field( (string) ( $state['lastCompletedTable'] ?? '' ) ),
			'lastError'          => substr( sanitize_text_field( (string) ( $state['lastError'] ?? '' ) ), 0, 500 ),
			'errorCode'          => sanitize_key( (string) ( $state['errorCode'] ?? '' ) ),
			'targetCharset'      => 'utf8mb4',
			'targetCollation'    => sanitize_text_field( (string) ( $state['targetCollation'] ?? '' ) ),
			'foreignKeyPreflight'=> isset( $state['foreignKeyPreflight'] ) && is_array( $state['foreignKeyPreflight'] ) ? $state['foreignKeyPreflight'] : array(),
			'databasePending'    => ! empty( $state['databasePending'] ),
			'tables'             => isset( $state['tables'] ) && is_array( $state['tables'] ) ? array_slice( $state['tables'], 0, 200 ) : array(),
			'tablePlan'          => isset( $state['tablePlan'] ) && is_array( $state['tablePlan'] ) ? $state['tablePlan'] : array(),
			'tableResults'       => $results,
			'excludedTables'     => isset( $state['excludedTables'] ) && is_array( $state['excludedTables'] ) ? $state['excludedTables'] : array(),
			'pendingTable'       => isset( $state['pendingTable'] ) && is_array( $state['pendingTable'] ) ? $state['pendingTable'] : array(),
			'cursor'             => $cursor,
			'totalTables'        => $total,
			'convertedTables'    => absint( $state['convertedTables'] ?? 0 ),
			'remainingTables'    => max( 0, $total - $cursor ),
			'nextTable'          => isset( $state['tables'][ $cursor ] ) ? (string) $state['tables'][ $cursor ] : '',
			'configActionNeeded' => ! empty( $state['configActionNeeded'] ),
			'pauseRequested'     => ! empty( $state['pauseRequested'] ),
			'needsContinuation'  => 'ready' === $status,
			'lastAudit'          => self::last_audit(),
		);
	}

	/** @return array */
	private static function last_audit() {
		$audit = get_option( self::LAST_AUDIT_OPTION, array() );
		return is_array( $audit ) ? $audit : array();
	}

	/** @return array */
	private static function read_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/** @return void */
	private static function write_state( $state ) {
		update_option( self::STATE_OPTION, is_array( $state ) ? $state : array(), false );
	}

	/** @return string */
	private static function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}

	/** @return string */
	private static function collation_sql( $collation ) {
		$collation = strtolower( trim( (string) $collation ) );
		return preg_match( '/^utf8mb4_[a-z0-9_]+$/', $collation ) ? $collation : 'utf8mb4_unicode_ci';
	}

	/** @return string */
	private static function bounded_db_error( $fallback ) {
		global $wpdb;
		$error = trim( (string) $wpdb->last_error );
		return '' !== $error ? substr( sanitize_text_field( $error ), 0, 400 ) : (string) $fallback;
	}
}
