<?php
/**
 * Explicit, resumable utf8mb4 conversion workflow.
 *
 * This workflow is deliberately administrator-started. It converts the current
 * WordPress installation database default plus tables under the active site
 * prefix one DDL boundary at a time, checkpoints every successful step and uses
 * the global runtime barrier so queue/sync workers drain before ALTER work.
 *
 * wp-config.php is never edited. If DB_CHARSET/DB_COLLATE still select a
 * three-byte charset after the physical conversion, the workflow stops in an
 * awaiting-config state and requires the operator to update configuration.
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
	const BARRIER_TTL       = 7200;
	const CONTINUE_SECONDS  = 300;
	const PLAN_LIMIT        = 2000;
	const STEP_LOCK         = 'database_unicode_migration_step';

	/**
	 * Start a new migration or resume an explicitly paused/failed run.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function start( $source = 'admin' ) {
		$current = self::read_state();
		$status  = isset( $current['status'] ) ? sanitize_key( (string) $current['status'] ) : '';

		if ( in_array( $status, array( 'draining', 'migrating' ), true ) ) {
			return self::public_state( $current );
		}

		if ( ! class_exists( 'Mobo_Core_Database_Charset' ) ) {
			return self::failure_result( 'unicode-checker-unavailable', 'Database Unicode checker is unavailable.' );
		}

		$unicode = Mobo_Core_Database_Charset::get_status( false, true );
		if ( empty( $unicode['serverSupportsUtf8mb4'] ) ) {
			return self::failure_result( 'utf8mb4-unsupported', 'Database server does not report utf8mb4 support.' );
		}

		$plan = self::build_plan();
		if ( is_wp_error( $plan ) ) {
			return self::failure_result( $plan->get_error_code(), $plan->get_error_message() );
		}

		$now = time();
		$run_id = wp_generate_uuid4();
		$state = array(
			'runId'              => $run_id,
			'status'             => 'draining',
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
			'tables'             => array_values( $plan['tables'] ),
			'cursor'             => 0,
			'totalTables'        => count( $plan['tables'] ),
			'indexPreflight'     => isset( $plan['indexPreflight'] ) && is_array( $plan['indexPreflight'] ) ? $plan['indexPreflight'] : array(),
			'foreignKeyPreflight'=> isset( $plan['foreignKeyPreflight'] ) && is_array( $plan['foreignKeyPreflight'] ) ? $plan['foreignKeyPreflight'] : array(),
			'convertedTables'    => 0,
			'nextEligibleAt'     => $now,
			'configActionNeeded' => false,
			'pauseRequested'     => false,
		);
		self::write_state( $state );

		return self::process_slice( $source . '-start' );
	}

	/**
	 * Continue one bounded DDL step. Never converts more than one table per call.
	 *
	 * @param string $source Source label.
	 * @return array
	 */
	public static function process_slice( $source = 'maintenance' ) {
		$state = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';

		if ( empty( $state ) ) {
			return array( 'success' => true, 'status' => 'idle', 'needsContinuation' => false );
		}

		if ( 'awaiting-config' === $status ) {
			return self::try_finalize_after_config( $state, $source );
		}

		if ( ! in_array( $status, array( 'draining', 'migrating' ), true ) ) {
			$out = self::public_state( $state );
			$out['success'] = 'failed' !== $status;
			$out['needsContinuation'] = false;
			return $out;
		}

		$barrier = self::ensure_barrier( $state );
		if ( is_wp_error( $barrier ) ) {
			return self::fail_run( $state, $barrier->get_error_message(), $barrier->get_error_code() );
		}
		$state = self::read_state();

		$blocking = class_exists( 'Mobo_Core_Upgrade_Coordinator' )
			? Mobo_Core_Upgrade_Coordinator::get_blocking_locks()
			: array();
		if ( ! empty( $blocking ) ) {
			$state['status'] = 'draining';
			$state['updatedAt'] = time();
			$state['nextEligibleAt'] = time() + 10;
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['needsContinuation'] = true;
			$out['blockingLocks'] = $blocking;
			return $out;
		}

		$state['status'] = 'migrating';
		$state['source'] = sanitize_key( (string) $source );
		$state['updatedAt'] = time();
		self::write_state( $state );

		$step_lock = Mobo_Core_Lock::acquire( self::STEP_LOCK, self::BARRIER_TTL );
		if ( false === $step_lock ) {
			$out = self::public_state( self::read_state() );
			$out['success'] = true;
			$out['needsContinuation'] = true;
			$out['stepLocked'] = true;
			return $out;
		}

		try {
			/* Close the Pause/barrier-expiry race between the drain check above and
			 * taking the DDL step lease. Re-read durable state only after exclusive
			 * step ownership; stale request-local state must never authorize ALTER. */
			$state = self::read_state();
			$live_status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
			if ( ! in_array( $live_status, array( 'draining', 'migrating' ), true ) ) {
				$out = self::public_state( $state );
				$out['success'] = 'failed' !== $live_status;
				$out['needsContinuation'] = false;
				return $out;
			}
			if ( ! empty( $state['pauseRequested'] ) ) {
				return self::pause_at_safe_boundary( $state, 'pre-ddl' );
			}

			$barrier = self::ensure_barrier( $state );
			if ( is_wp_error( $barrier ) ) {
				return self::fail_run( $state, $barrier->get_error_message(), $barrier->get_error_code() );
			}
			$state = self::read_state();
			$blocking = class_exists( 'Mobo_Core_Upgrade_Coordinator' )
				? Mobo_Core_Upgrade_Coordinator::get_blocking_locks()
				: array();
			if ( ! empty( $blocking ) ) {
				$state['status'] = 'draining';
				$state['updatedAt'] = time();
				$state['nextEligibleAt'] = time() + 10;
				self::write_state( $state );
				$out = self::public_state( $state );
				$out['success'] = true;
				$out['needsContinuation'] = true;
				$out['blockingLocks'] = $blocking;
				return $out;
			}

		if ( ! empty( $state['databasePending'] ) ) {
			$converted = self::convert_database_default( $state );
			if ( is_wp_error( $converted ) ) {
				return self::fail_run( $state, $converted->get_error_message(), $converted->get_error_code() );
			}
			$state = self::read_state();
			$state['databasePending'] = false;
			$state['lastStepAt'] = time();
			$state['updatedAt'] = time();
			$state['nextEligibleAt'] = time() + 1;
			self::write_state( $state );
			if ( ! empty( $state['pauseRequested'] ) ) {
				return self::pause_at_safe_boundary( $state, 'database-default' );
			}
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['status'] = 'migrating';
			$out['needsContinuation'] = true;
			$out['step'] = 'database-default';
			return $out;
		}

		$tables = isset( $state['tables'] ) && is_array( $state['tables'] ) ? array_values( $state['tables'] ) : array();
		$cursor = isset( $state['cursor'] ) ? absint( $state['cursor'] ) : 0;
		if ( $cursor < count( $tables ) ) {
			$table = (string) $tables[ $cursor ];
			$converted = self::convert_table( $table, (string) $state['targetCollation'] );
			if ( is_wp_error( $converted ) ) {
				return self::fail_run( $state, $converted->get_error_message(), $converted->get_error_code() );
			}

			$state = self::read_state();
			$state['cursor'] = $cursor + 1;
			$state['convertedTables'] = min( count( $tables ), absint( $state['convertedTables'] ?? 0 ) + 1 );
			$state['lastCompletedTable'] = $table;
			$state['lastStepAt'] = time();
			$state['updatedAt'] = time();
			$state['nextEligibleAt'] = time() + 1;
			self::write_state( $state );
			Mobo_Core_Database_Charset::reset_cache();

			if ( ! empty( $state['pauseRequested'] ) ) {
				return self::pause_at_safe_boundary( $state, 'table' );
			}
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['needsContinuation'] = true;
			$out['step'] = 'table';
			return $out;
		}

		return self::finish_physical_conversion( $state, $source );
		} finally {
			Mobo_Core_Lock::release( self::STEP_LOCK, $step_lock );
		}
	}

	/**
	 * Pause future conversion at the current safe boundary.
	 *
	 * @return array
	 */
	public static function pause() {
		$state = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		if ( ! in_array( $status, array( 'draining', 'migrating' ), true ) ) {
			return self::public_state( $state );
		}

		$step = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::get_status( self::STEP_LOCK ) : array();
		if ( ! empty( $step['active'] ) ) {
			$state['pauseRequested'] = true;
			$state['updatedAt'] = time();
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['pauseRequested'] = true;
			return $out;
		}

		return self::pause_at_safe_boundary( $state, 'manual' );
	}

	/**
	 * Resume an existing checkpoint, or re-check awaiting-config completion.
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

		if ( in_array( $status, array( 'paused', 'failed' ), true ) ) {
			/* Rebuild only the remaining physical plan. This prevents a failed ALTER
			 * from being marked complete merely because an old cursor advanced. */
			$plan = self::build_plan();
			if ( is_wp_error( $plan ) ) {
				return self::failure_result( $plan->get_error_code(), $plan->get_error_message() );
			}
			$state['status'] = 'draining';
			$state['source'] = sanitize_key( (string) $source );
			$state['updatedAt'] = time();
			$state['failedAt'] = 0;
			$state['pausedAt'] = 0;
			$state['lastError'] = '';
			$state['barrierToken'] = '';
			$state['pauseRequested'] = false;
			$state['targetCollation'] = (string) $plan['targetCollation'];
			$state['databaseName'] = (string) $plan['databaseName'];
			$state['databasePending'] = ! empty( $plan['databasePending'] );
			$state['tables'] = array_values( $plan['tables'] );
			$state['cursor'] = 0;
			$state['totalTables'] = count( $plan['tables'] );
			$state['indexPreflight'] = isset( $plan['indexPreflight'] ) && is_array( $plan['indexPreflight'] ) ? $plan['indexPreflight'] : array();
			$state['foreignKeyPreflight'] = isset( $plan['foreignKeyPreflight'] ) && is_array( $plan['foreignKeyPreflight'] ) ? $plan['foreignKeyPreflight'] : array();
			$state['convertedTables'] = 0;
			$state['nextEligibleAt'] = time();
			self::write_state( $state );
		}

		return self::process_slice( $source . '-resume' );
	}

	/**
	 * Fail closed for Mobo mutation leases after an explicit conversion has begun
	 * until terminal Unicode verification succeeds. The global barrier may be
	 * released while paused/failed/awaiting-config so support/updater traffic is
	 * not held hostage by a stale long lease, but ordinary workers stay blocked.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function should_block_runtime_lock( $name ) {
		$name = sanitize_key( (string) $name );
		if ( in_array( $name, array( 'plugin_upgrade_barrier', 'remote_plugin_upgrade', self::STEP_LOCK, 'health_snapshot_refresh' ), true ) ) {
			return false;
		}
		$state = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		return in_array( $status, array( 'draining', 'migrating', 'paused', 'failed', 'awaiting-config' ), true );
	}

	/** @return bool */
	public static function should_continue_automatically() {
		$state = self::read_state();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : '';
		return in_array( $status, array( 'draining', 'migrating' ), true );
	}

	/** @return array */
	public static function get_status() {
		$state = self::read_state();
		if ( empty( $state ) ) {
			return array(
				'status' => 'idle',
				'active' => false,
				'needsContinuation' => false,
				'configActionNeeded' => false,
			);
		}
		return self::public_state( $state );
	}

	/**
	 * Build a deterministic list of current-site-prefix tables requiring conversion.
	 *
	 * @return array|WP_Error
	 */
	private static function build_plan() {
		global $wpdb;

		$db_name = defined( 'DB_NAME' ) ? trim( (string) DB_NAME ) : trim( (string) $wpdb->get_var( 'SELECT DATABASE()' ) );
		if ( '' === $db_name ) {
			return new WP_Error( 'database-name-unavailable', 'Could not resolve the active WordPress database name.' );
		}

		$collation = self::preferred_collation();
		if ( '' === $collation ) {
			return new WP_Error( 'utf8mb4-collation-unavailable', 'No supported utf8mb4 collation is available on the database server.' );
		}

		$prefix = (string) $wpdb->prefix;
		$pattern = $wpdb->esc_like( $prefix ) . '%';
		$table_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = \'BASE TABLE\' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME LIMIT %d',
				$db_name,
				$pattern,
				self::PLAN_LIMIT + 1
			),
			ARRAY_A
		);
		if ( ! is_array( $table_rows ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'table-plan-query-failed', self::bounded_db_error( 'Could not enumerate WordPress tables for Unicode conversion.' ) );
		}
		if ( count( $table_rows ) > self::PLAN_LIMIT ) {
			return new WP_Error( 'table-plan-too-large', 'Unicode conversion plan exceeds the bounded table limit.' );
		}

		$tables = array();
		foreach ( $table_rows as $row ) {
			$table = isset( $row['TABLE_NAME'] ) ? (string) $row['TABLE_NAME'] : '';
			$table_collation = strtolower( trim( (string) ( $row['TABLE_COLLATION'] ?? '' ) ) );
			if ( '' === $table || 0 !== strpos( $table, $prefix ) ) {
				continue;
			}
			$needs = ! Mobo_Core_Database_Charset::is_full_unicode_collation( $table_collation );
			if ( ! $needs ) {
				$bad_columns = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> %s',
						$db_name,
						$table,
						'utf8mb4'
					)
				);
				if ( null === $bad_columns || '' !== (string) $wpdb->last_error ) {
					return new WP_Error( 'column-plan-query-failed', self::bounded_db_error( 'Could not inspect text columns for Unicode conversion.' ) );
				}
				$needs = absint( $bad_columns ) > 0;
			}
			if ( $needs ) {
				$tables[] = $table;
			}
		}

		$db_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT DEFAULT_CHARACTER_SET_NAME AS charset_name, DEFAULT_COLLATION_NAME AS collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s LIMIT 1',
				$db_name
			),
			ARRAY_A
		);
		$db_charset = is_array( $db_row ) ? strtolower( trim( (string) ( $db_row['charset_name'] ?? '' ) ) ) : '';
		$db_collation = is_array( $db_row ) ? strtolower( trim( (string) ( $db_row['collation_name'] ?? '' ) ) ) : '';

		$tables = array_values( array_unique( $tables ) );
		$index_preflight = self::preflight_index_compatibility( $db_name, $tables );
		if ( is_wp_error( $index_preflight ) ) {
			return $index_preflight;
		}
		$foreign_key_preflight = self::preflight_foreign_key_compatibility( $db_name, $tables, $collation );
		if ( is_wp_error( $foreign_key_preflight ) ) {
			return $foreign_key_preflight;
		}

		return array(
			'databaseName'    => $db_name,
			'targetCollation' => $collation,
			'databasePending' => 'utf8mb4' !== $db_charset || ! Mobo_Core_Database_Charset::is_full_unicode_collation( $db_collation ),
			'tables'          => $tables,
			'indexPreflight'  => $index_preflight,
			'foreignKeyPreflight' => $foreign_key_preflight,
		);
	}

	/**
	 * Conservative utf8mb4 key-length preflight. ALTER CONVERT preserves index
	 * definitions, so reject a plan that is already provably wider than the
	 * target engine/row-format key limit instead of discovering that mid-run.
	 *
	 * @param string $db_name Database name.
	 * @param array $planned_tables Tables that actually require conversion.
	 * @return array|WP_Error
	 */
	private static function preflight_index_compatibility( $db_name, $planned_tables ) {
		global $wpdb;
		if ( empty( $planned_tables ) ) {
			return array( 'checkedIndexes' => 0, 'maxEstimatedBytes' => 0, 'safe' => true );
		}

		$planned_tables = array_values( array_unique( array_map( 'strval', $planned_tables ) ) );
		$planned = array_fill_keys( $planned_tables, true );
		$placeholders = implode( ', ', array_fill( 0, count( $planned_tables ), '%s' ) );
		$query = "SELECT s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX, s.SUB_PART, c.DATA_TYPE, c.CHARACTER_MAXIMUM_LENGTH, c.CHARACTER_OCTET_LENGTH, c.CHARACTER_SET_NAME, t.ENGINE, t.ROW_FORMAT
			 FROM information_schema.STATISTICS s
			 JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=s.TABLE_SCHEMA AND c.TABLE_NAME=s.TABLE_NAME AND c.COLUMN_NAME=s.COLUMN_NAME
			 JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=s.TABLE_SCHEMA AND t.TABLE_NAME=s.TABLE_NAME
			 WHERE s.TABLE_SCHEMA=%s AND s.TABLE_NAME IN (" . $placeholders . ")
			 ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX
			 LIMIT 20001";
		$args = array_merge( array( $db_name ), $planned_tables );
		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, $args ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'index-preflight-query-failed', self::bounded_db_error( 'Could not inspect indexes before utf8mb4 conversion.' ) );
		}
		if ( count( $rows ) > 20000 ) {
			return new WP_Error( 'index-preflight-too-large', 'Index preflight exceeded the bounded inspection limit.' );
		}

		$version = strtolower( trim( (string) $wpdb->get_var( 'SELECT VERSION()' ) ) );
		$is_mariadb = false !== strpos( $version, 'mariadb' );
		$numeric_version = preg_match( '/([0-9]+(?:\.[0-9]+){1,2})/', $version, $m ) ? $m[1] : '0.0.0';
		$modern_large_prefix = $is_mariadb ? version_compare( $numeric_version, '10.2.2', '>=' ) : version_compare( $numeric_version, '5.7.7', '>=' );
		$large_prefix_value = '';
		$old_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		$large_prefix_query = $wpdb->get_var( 'SELECT @@innodb_large_prefix' );
		if ( null !== $large_prefix_query ) {
			$large_prefix_value = strtolower( trim( (string) $large_prefix_query ) );
		}
		$wpdb->last_error = $old_error;
		$large_prefix_on = in_array( $large_prefix_value, array( '1', 'on', 'true' ), true );

		$indexes = array();
		foreach ( $rows as $row ) {
			$table = (string) ( $row['TABLE_NAME'] ?? '' );
			if ( ! isset( $planned[ $table ] ) ) {
				continue;
			}
			$index = (string) ( $row['INDEX_NAME'] ?? '' );
			$key = $table . "\0" . $index;
			if ( ! isset( $indexes[ $key ] ) ) {
				$engine = strtoupper( trim( (string) ( $row['ENGINE'] ?? '' ) ) );
				$row_format = strtoupper( trim( (string) ( $row['ROW_FORMAT'] ?? '' ) ) );
				if ( 'MYISAM' === $engine ) {
					$limit = 1000;
				} elseif ( 'INNODB' === $engine ) {
					$limit = ( $modern_large_prefix || ( $large_prefix_on && in_array( $row_format, array( 'DYNAMIC', 'COMPRESSED' ), true ) ) ) ? 3072 : 767;
				} else {
					$limit = 3072;
				}
				$indexes[ $key ] = array( 'table' => $table, 'index' => $index, 'bytes' => 0, 'limit' => $limit );
			}

			$charset = strtolower( trim( (string) ( $row['CHARACTER_SET_NAME'] ?? '' ) ) );
			if ( '' !== $charset ) {
				$chars = absint( $row['SUB_PART'] ?? 0 );
				if ( $chars <= 0 ) {
					$chars = absint( $row['CHARACTER_MAXIMUM_LENGTH'] ?? 0 );
				}
				$bytes = $chars > 0 ? $chars * 4 : 0;
			} else {
				$binary_prefix = absint( $row['SUB_PART'] ?? 0 );
				$octets = absint( $row['CHARACTER_OCTET_LENGTH'] ?? 0 );
				$bytes = $binary_prefix > 0 ? $binary_prefix : ( $octets > 0 ? $octets : self::non_text_index_bytes( (string) ( $row['DATA_TYPE'] ?? '' ) ) );
			}
			$indexes[ $key ]['bytes'] += $bytes;
		}

		$max_bytes = 0;
		foreach ( $indexes as $meta ) {
			$max_bytes = max( $max_bytes, absint( $meta['bytes'] ) );
			if ( absint( $meta['bytes'] ) > absint( $meta['limit'] ) ) {
				return new WP_Error(
					'utf8mb4-index-preflight-failed',
					sprintf(
						'Index %s on table %s is estimated at %d bytes under utf8mb4, above the detected %d-byte key limit.',
						(string) $meta['index'],
						(string) $meta['table'],
						absint( $meta['bytes'] ),
						absint( $meta['limit'] )
					)
				);
			}
		}

		return array(
			'checkedIndexes' => count( $indexes ),
			'maxEstimatedBytes' => $max_bytes,
			'safe' => true,
			'serverVersion' => substr( sanitize_text_field( $version ), 0, 80 ),
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

	/** @return int */
	private static function non_text_index_bytes( $data_type ) {
		$type = strtolower( trim( (string) $data_type ) );
		$map = array(
			'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'integer' => 4, 'bigint' => 8,
			'float' => 4, 'double' => 8, 'real' => 8, 'date' => 3, 'time' => 3, 'timestamp' => 4, 'datetime' => 8,
			'year' => 1, 'bit' => 8, 'decimal' => 16, 'numeric' => 16, 'binary' => 16, 'varbinary' => 32,
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : 16;
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

	/** @return true|WP_Error */
	private static function convert_table( $table, $collation ) {
		global $wpdb;
		$table = (string) $table;
		$collation = strtolower( trim( (string) $collation ) );
		if ( '' === $table || '' === $collation ) {
			return new WP_Error( 'table-conversion-state-invalid', 'Table conversion state is incomplete.' );
		}

		/* Never execute a plan entry outside the current site prefix, even if the
		 * persisted option was manually modified. */
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

		$sql = 'ALTER TABLE ' . self::quote_identifier( $table ) . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . self::collation_sql( $collation );
		if ( false === $wpdb->query( $sql ) ) {
			return new WP_Error( 'table-convert-failed', self::bounded_db_error( 'Could not convert table ' . $table . ' to utf8mb4.' ) );
		}

		$verify = self::verify_table( $table );
		if ( is_wp_error( $verify ) ) {
			return $verify;
		}
		return true;
	}

	/** @return true|WP_Error */
	private static function verify_table( $table ) {
		$readiness = self::table_readiness( $table );
		if ( is_wp_error( $readiness ) ) {
			return $readiness;
		}
		return ! empty( $readiness['ready'] )
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
		/* A table removed after planning no longer needs conversion. */
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
		$charset = is_array( $row ) ? strtolower( trim( (string) ( $row['charset_name'] ?? '' ) ) ) : '';
		$collation = is_array( $row ) ? strtolower( trim( (string) ( $row['collation_name'] ?? '' ) ) ) : '';
		return 'utf8mb4' === $charset && Mobo_Core_Database_Charset::is_full_unicode_collation( $collation );
	}

	/** @return array */
	private static function finish_physical_conversion( $state, $source ) {
		/* Re-plan at the terminal boundary. A third-party table can be created by
		 * code that does not participate in Mobo locks; do not report completion
		 * while a newly discovered current-prefix table is still below utf8mb4. */
		$remaining = self::build_plan();
		if ( is_wp_error( $remaining ) ) {
			return self::fail_run( $state, $remaining->get_error_message(), $remaining->get_error_code() );
		}
		if ( ! empty( $remaining['databasePending'] ) || ! empty( $remaining['tables'] ) ) {
			$state['databasePending'] = ! empty( $remaining['databasePending'] );
			$state['tables'] = array_values( $remaining['tables'] );
			$state['cursor'] = 0;
			$state['totalTables'] = count( $remaining['tables'] );
			$state['convertedTables'] = 0;
			$state['targetCollation'] = (string) $remaining['targetCollation'];
			$state['indexPreflight'] = isset( $remaining['indexPreflight'] ) && is_array( $remaining['indexPreflight'] ) ? $remaining['indexPreflight'] : array();
			$state['foreignKeyPreflight'] = isset( $remaining['foreignKeyPreflight'] ) && is_array( $remaining['foreignKeyPreflight'] ) ? $remaining['foreignKeyPreflight'] : array();
			$state['status'] = 'migrating';
			$state['updatedAt'] = time();
			$state['nextEligibleAt'] = time() + 1;
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['needsContinuation'] = true;
			$out['step'] = 'replan';
			return $out;
		}

		Mobo_Core_Database_Charset::reset_cache();
		$unicode = Mobo_Core_Database_Charset::get_status( true, true );
		if ( ! empty( $unicode['ready'] ) ) {
			return self::complete_run( $state, $source, $unicode );
		}

		$config_problem = empty( $unicode['configReady'] ) || empty( $unicode['wpdbReady'] ) || empty( $unicode['connectionReady'] );
		if ( $config_problem ) {
			self::release_barrier( $state, 'database-unicode-awaiting-config' );
			$state = self::read_state();
			$state['status'] = 'awaiting-config';
			$state['configActionNeeded'] = true;
			$state['barrierToken'] = '';
			$state['updatedAt'] = time();
			$state['nextEligibleAt'] = 0;
			$state['lastError'] = isset( $unicode['message'] ) ? sanitize_text_field( (string) $unicode['message'] ) : 'WordPress database configuration still requires utf8mb4.';
			self::write_state( $state );
			$out = self::public_state( $state );
			$out['success'] = true;
			$out['needsContinuation'] = false;
			$out['databaseUnicode'] = $unicode;
			return $out;
		}

		$message = isset( $unicode['message'] ) ? (string) $unicode['message'] : 'Unicode verification failed after physical conversion.';
		return self::fail_run( $state, $message, 'post-conversion-verify-failed' );
	}

	/** @return array */
	private static function try_finalize_after_config( $state, $source ) {
		$remaining = self::build_plan();
		if ( is_wp_error( $remaining ) ) {
			return self::failure_result( $remaining->get_error_code(), $remaining->get_error_message() );
		}
		if ( ! empty( $remaining['databasePending'] ) || ! empty( $remaining['tables'] ) ) {
			$state['status'] = 'draining';
			$state['databasePending'] = ! empty( $remaining['databasePending'] );
			$state['tables'] = array_values( $remaining['tables'] );
			$state['cursor'] = 0;
			$state['totalTables'] = count( $remaining['tables'] );
			$state['convertedTables'] = 0;
			$state['targetCollation'] = (string) $remaining['targetCollation'];
			$state['indexPreflight'] = isset( $remaining['indexPreflight'] ) && is_array( $remaining['indexPreflight'] ) ? $remaining['indexPreflight'] : array();
			$state['foreignKeyPreflight'] = isset( $remaining['foreignKeyPreflight'] ) && is_array( $remaining['foreignKeyPreflight'] ) ? $remaining['foreignKeyPreflight'] : array();
			$state['configActionNeeded'] = false;
			$state['pauseRequested'] = false;
			$state['updatedAt'] = time();
			$state['nextEligibleAt'] = time();
			self::write_state( $state );
			return self::process_slice( $source . '-physical-resume' );
		}

		Mobo_Core_Database_Charset::reset_cache();
		$unicode = Mobo_Core_Database_Charset::get_status( true, true );
		if ( ! empty( $unicode['ready'] ) ) {
			return self::complete_run( $state, $source, $unicode );
		}
		$state['configActionNeeded'] = empty( $unicode['configReady'] ) || empty( $unicode['wpdbReady'] ) || empty( $unicode['connectionReady'] );
		$state['updatedAt'] = time();
		$state['lastError'] = isset( $unicode['message'] ) ? sanitize_text_field( (string) $unicode['message'] ) : 'Unicode verification is not ready.';
		self::write_state( $state );
		$out = self::public_state( $state );
		$out['success'] = true;
		$out['needsContinuation'] = false;
		$out['databaseUnicode'] = $unicode;
		return $out;
	}

	/** @return array */
	private static function complete_run( $state, $source, $unicode ) {
		self::release_barrier( $state, 'database-unicode-completed' );
		$state = self::read_state();
		$now = time();
		$state['status'] = 'completed';
		$state['source'] = sanitize_key( (string) $source );
		$state['completedAt'] = $now;
		$state['updatedAt'] = $now;
		$state['barrierToken'] = '';
		$state['configActionNeeded'] = false;
		$state['lastError'] = '';
		$state['nextEligibleAt'] = 0;
		self::write_state( $state );
		update_option(
			self::LAST_AUDIT_OPTION,
			array(
				'runId' => sanitize_text_field( (string) ( $state['runId'] ?? '' ) ),
				'result' => 'completed',
				'startedAt' => absint( $state['startedAt'] ?? 0 ),
				'completedAt' => $now,
				'totalTables' => absint( $state['totalTables'] ?? 0 ),
				'lastCompletedTable' => sanitize_text_field( (string) ( $state['lastCompletedTable'] ?? '' ) ),
				'roundTripSuccess' => ! empty( $unicode['roundTrip']['success'] ),
			),
			false
		);
		$out = self::public_state( $state );
		$out['success'] = true;
		$out['needsContinuation'] = false;
		$out['databaseUnicode'] = $unicode;
		return $out;
	}

	/** @return array */
	private static function fail_run( $state, $message, $code = 'migration-failed' ) {
		self::release_barrier( $state, 'database-unicode-failed', array( 'lastError' => $message ) );
		$state = self::read_state();
		$now = time();
		$state['status'] = 'failed';
		$state['failedAt'] = $now;
		$state['updatedAt'] = $now;
		$state['barrierToken'] = '';
		$state['nextEligibleAt'] = 0;
		$state['lastError'] = substr( sanitize_text_field( (string) $message ), 0, 500 );
		$state['errorCode'] = sanitize_key( (string) $code );
		self::write_state( $state );
		update_option(
			self::LAST_AUDIT_OPTION,
			array(
				'runId' => sanitize_text_field( (string) ( $state['runId'] ?? '' ) ),
				'result' => 'failed',
				'errorCode' => sanitize_key( (string) $code ),
				'lastError' => $state['lastError'],
				'startedAt' => absint( $state['startedAt'] ?? 0 ),
				'failedAt' => $now,
				'cursor' => absint( $state['cursor'] ?? 0 ),
				'totalTables' => absint( $state['totalTables'] ?? 0 ),
				'lastCompletedTable' => sanitize_text_field( (string) ( $state['lastCompletedTable'] ?? '' ) ),
			),
			false
		);
		$out = self::public_state( $state );
		$out['success'] = false;
		$out['needsContinuation'] = false;
		return $out;
	}

	/** @return array */
	private static function failure_result( $code, $message ) {
		return array(
			'success' => false,
			'status' => 'failed',
			'errorCode' => sanitize_key( (string) $code ),
			'lastError' => substr( sanitize_text_field( (string) $message ), 0, 500 ),
			'needsContinuation' => false,
		);
	}

	/** @return array */
	private static function pause_at_safe_boundary( $state, $step ) {
		self::release_barrier( $state, 'database-unicode-paused' );
		$state = self::read_state();
		$state['status'] = 'paused';
		$state['pausedAt'] = time();
		$state['updatedAt'] = time();
		$state['barrierToken'] = '';
		$state['pauseRequested'] = false;
		$state['nextEligibleAt'] = 0;
		self::write_state( $state );
		$out = self::public_state( $state );
		$out['success'] = true;
		$out['needsContinuation'] = false;
		$out['pausedAfterStep'] = sanitize_key( (string) $step );
		return $out;
	}

	/**
	 * Release only the barrier token stored by this run.
	 *
	 * @param array  $state State.
	 * @param string $result Audit result.
	 * @param array  $details Details.
	 * @return void
	 */
	private static function release_barrier( $state, $result, $details = array() ) {
		$token = isset( $state['barrierToken'] ) ? trim( (string) $state['barrierToken'] ) : '';
		if ( '' !== $token && class_exists( 'Mobo_Core_Upgrade_Coordinator' ) ) {
			Mobo_Core_Upgrade_Coordinator::release( $token, $result, $details );
		}
	}

	/** @return array */
	private static function public_state( $state ) {
		$state = is_array( $state ) ? $state : array();
		$status = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : 'idle';
		$cursor = absint( $state['cursor'] ?? 0 );
		$total = absint( $state['totalTables'] ?? 0 );
		return array(
			'active'             => in_array( $status, array( 'draining', 'migrating' ), true ),
			'status'             => $status,
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
			'indexPreflight'     => isset( $state['indexPreflight'] ) && is_array( $state['indexPreflight'] ) ? $state['indexPreflight'] : array(),
			'foreignKeyPreflight'=> isset( $state['foreignKeyPreflight'] ) && is_array( $state['foreignKeyPreflight'] ) ? $state['foreignKeyPreflight'] : array(),
			'databasePending'    => ! empty( $state['databasePending'] ),
			'totalTables'        => $total,
			'convertedTables'    => min( $total, max( absint( $state['convertedTables'] ?? 0 ), $cursor ) ),
			'remainingTables'    => max( 0, $total - $cursor ),
			'configActionNeeded' => ! empty( $state['configActionNeeded'] ),
			'pauseRequested'     => ! empty( $state['pauseRequested'] ),
			'needsContinuation'  => in_array( $status, array( 'draining', 'migrating' ), true ),
			'nextEligibleAt'     => absint( $state['nextEligibleAt'] ?? 0 ),
			'lastAudit'          => self::last_audit(),
		);
	}

	/** @return array */
	private static function last_audit() {
		$audit = get_option( self::LAST_AUDIT_OPTION, array() );
		if ( ! is_array( $audit ) ) {
			return array();
		}
		/* The audit is already bounded and contains no barrier token. */
		return $audit;
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
