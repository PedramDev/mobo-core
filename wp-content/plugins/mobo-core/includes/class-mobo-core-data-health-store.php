<?php
/**
 * Data health storage: findings of the last scan and the per-row backup used
 * by guarded repairs (dry-run -> backup -> apply -> undo).
 *
 * Two small plugin-owned tables with their own schema version option, so they
 * never interfere with Mobo_Core_Storage_Schema validation or its VERSION:
 *
 * - {prefix}mobo_data_health_findings: one row per affected object of the last
 *   scan (detector, object id, group, proposed action, short JSON detail).
 * - {prefix}mobo_data_health_backup: one row per repaired object with the exact
 *   "before" and "after" snapshot. Undo restores "before" only while the live
 *   state still equals "after" (compare-and-swap), so a later merchant/Source
 *   edit is never overwritten.
 *
 * When the host refuses CREATE TABLE the scanner still counts (state option),
 * but every repair fails closed: no backup, no change.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
final class Mobo_Core_Data_Health_Store {

	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'mobo_core_data_health_schema';

	/** Hard cap of stored findings per detector and scan (the count stays exact). */
	const MAX_FINDINGS_PER_DETECTOR = 50000;

	/** Longest JSON detail stored per finding. */
	const MAX_DETAIL_BYTES = 2000;

	/** @var bool|null */
	private static $ready = null;

	/** @return string */
	public static function findings_table() {
		global $wpdb;
		return $wpdb->prefix . 'mobo_data_health_findings';
	}

	/** @return string */
	public static function backup_table() {
		global $wpdb;
		return $wpdb->prefix . 'mobo_data_health_backup';
	}

	/** @return void */
	public static function reset_request_cache() {
		self::$ready = null;
	}

	/**
	 * DDL for both tables (dbDelta format).
	 *
	 * @param string $charset_collate Charset clause.
	 * @return string[]
	 */
	public static function schema_sql( $charset_collate ) {
		$f = self::findings_table();
		$b = self::backup_table();
		return array(
			"CREATE TABLE {$f} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id varchar(40) NOT NULL DEFAULT '',
			detector varchar(48) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_ref varchar(191) NOT NULL DEFAULT '',
			group_key varchar(191) NOT NULL DEFAULT '',
			canonical_id bigint(20) unsigned NOT NULL DEFAULT 0,
			repair_class varchar(1) NOT NULL DEFAULT 'c',
			action varchar(48) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'open',
			batch_id varchar(40) NOT NULL DEFAULT '',
			detail text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY scan_detector (scan_id,detector,status,id),
			KEY detector_object (detector,object_id)
		) {$charset_collate};",
			"CREATE TABLE {$b} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id varchar(40) NOT NULL DEFAULT '',
			detector varchar(48) NOT NULL DEFAULT '',
			action varchar(48) NOT NULL DEFAULT '',
			finding_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_ref varchar(191) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'pending',
			before_json longtext NULL,
			after_json longtext NULL,
			note varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_status (batch_id,status,id),
			KEY detector_object (detector,object_id)
		) {$charset_collate};",
		);
	}

	/**
	 * Create/upgrade the tables when needed. Never throws.
	 *
	 * @return bool Tables usable.
	 */
	public static function ensure() {
		global $wpdb;
		if ( true === self::$ready ) {
			return true;
		}
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) && self::tables_exist() ) {
			self::$ready = true;
			return true;
		}
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			self::$ready = false;
			return false;
		}
		$collate = '';
		if ( class_exists( 'Mobo_Core_Database_Charset' ) && method_exists( 'Mobo_Core_Database_Charset', 'schema_charset_collate' ) ) {
			$collate = (string) Mobo_Core_Database_Charset::schema_charset_collate();
		} elseif ( method_exists( $wpdb, 'get_charset_collate' ) ) {
			$collate = (string) $wpdb->get_charset_collate();
		}
		try {
			foreach ( self::schema_sql( $collate ) as $sql ) {
				dbDelta( $sql );
			}
		} catch ( Throwable $e ) {
			self::$ready = false;
			return false;
		}
		self::$ready = self::tables_exist();
		if ( self::$ready ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		}
		return self::$ready;
	}

	/** @return bool */
	public static function is_ready() {
		if ( null === self::$ready ) {
			self::$ready = self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) && self::tables_exist();
		}
		return self::$ready;
	}

	/** @return bool */
	private static function tables_exist() {
		return self::table_exists( self::findings_table() ) && self::table_exists( self::backup_table() );
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		/* Shared positive-result cache (no SHOW TABLES on every request). */
		if ( class_exists( 'Mobo_Core_Table_Cache' ) ) {
			return Mobo_Core_Table_Cache::exists( (string) $table );
		}
		$like  = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return is_string( $found ) && strtolower( $found ) === strtolower( $table );
	}

	/** @return string */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Encode a detail array, trimmed to MAX_DETAIL_BYTES.
	 *
	 * @param array $detail Detail.
	 * @return string
	 */
	public static function encode_detail( $detail ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $detail ) : json_encode( $detail );
		if ( ! is_string( $json ) ) {
			return '{}';
		}
		if ( strlen( $json ) > self::MAX_DETAIL_BYTES ) {
			$slim = array( 'truncated' => true );
			foreach ( array( 'reason', 'canonical', 'parent', 'members' ) as $key ) {
				if ( isset( $detail[ $key ] ) ) {
					$slim[ $key ] = is_array( $detail[ $key ] ) ? array_slice( $detail[ $key ], 0, 50 ) : $detail[ $key ];
				}
			}
			$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $slim ) : json_encode( $slim );
		}
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Insert findings of one scan step. Returns the number written or false on a
	 * database error (the caller keeps its cursor and retries the step).
	 *
	 * @param string $scan_id Scan id.
	 * @param string $detector Detector id.
	 * @param array  $findings Normalized findings.
	 * @return int|false
	 */
	public static function insert_findings( $scan_id, $detector, array $findings ) {
		global $wpdb;
		if ( empty( $findings ) ) {
			return 0;
		}
		if ( ! self::is_ready() ) {
			return false;
		}
		$table  = self::findings_table();
		$now    = self::now();
		$values = array();
		foreach ( $findings as $f ) {
			$values[] = $wpdb->prepare(
				'(%s,%s,%d,%s,%s,%d,%s,%s,%s,%s,%s)',
				(string) $scan_id,
				(string) $detector,
				absint( $f['object_id'] ),
				substr( (string) $f['object_ref'], 0, 191 ),
				substr( (string) $f['group_key'], 0, 191 ),
				absint( $f['canonical_id'] ),
				(string) $f['class'],
				(string) $f['action'],
				'covered' === (string) $f['status'] ? 'covered' : 'open',
				self::encode_detail( is_array( $f['detail'] ) ? $f['detail'] : array() ),
				$now
			);
		}
		$written = 0;
		foreach ( array_chunk( $values, 200 ) as $chunk ) {
			$ok = $wpdb->query( "INSERT INTO {$table} (scan_id,detector,object_id,object_ref,group_key,canonical_id,repair_class,action,status,detail,created_at) VALUES " . implode( ',', $chunk ) );
			if ( false === $ok ) {
				return false;
			}
			$written += count( $chunk );
		}
		return $written;
	}

	/**
	 * Remove rows a killed step wrote after the last committed state (crash
	 * between insert and state commit), so a resumed step never duplicates them.
	 *
	 * @param string $scan_id Scan.
	 * @param string $detector Detector.
	 * @param int    $after_id Highest committed finding id of this detector.
	 * @return bool
	 */
	public static function delete_uncommitted( $scan_id, $detector, $after_id ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return true;
		}
		return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::findings_table() . ' WHERE scan_id=%s AND detector=%s AND id>%d', (string) $scan_id, (string) $detector, absint( $after_id ) ) );
	}

	/**
	 * Highest finding id of one detector in a scan.
	 *
	 * @param string $scan_id Scan.
	 * @param string $detector Detector.
	 * @return int
	 */
	public static function max_finding_id( $scan_id, $detector ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return 0;
		}
		return absint( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . self::findings_table() . ' WHERE scan_id=%s AND detector=%s', (string) $scan_id, (string) $detector ) ) );
	}

	/**
	 * Delete findings of older scans in bounded batches.
	 *
	 * @param string $keep_scan_id Current scan id.
	 * @param int    $limit Rows per call.
	 * @return int|false Rows deleted (0 = nothing left).
	 */
	public static function purge_old_findings( $keep_scan_id, $limit = 5000 ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return 0;
		}
		$table = self::findings_table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE scan_id<>%s ORDER BY id ASC LIMIT %d", (string) $keep_scan_id, max( 1, absint( $limit ) ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( empty( $ids ) ) {
			return 0;
		}
		$ids = array_map( 'absint', $ids );
		$ok  = $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' );
		return false === $ok ? false : count( $ids );
	}

	/**
	 * Page through findings.
	 *
	 * @param string $scan_id Scan id.
	 * @param string $detector Detector id.
	 * @param int    $after_id Cursor.
	 * @param int    $limit Limit.
	 * @param string $status Status filter ('' = any).
	 * @param string $class Repair class filter ('' = any).
	 * @return array|false
	 */
	public static function findings_page( $scan_id, $detector, $after_id, $limit, $status = 'open', $class = '' ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return false;
		}
		$table = self::findings_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id=%s AND detector=%s AND id>%d", (string) $scan_id, (string) $detector, absint( $after_id ) );
		if ( '' !== $status ) {
			$sql .= $wpdb->prepare( ' AND status=%s', (string) $status );
		}
		if ( '' !== $class ) {
			$sql .= $wpdb->prepare( ' AND repair_class=%s', (string) $class );
		}
		$sql .= $wpdb->prepare( ' ORDER BY id ASC LIMIT %d', max( 1, absint( $limit ) ) );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		return $rows;
	}

	/**
	 * Count findings by status/class for one detector.
	 *
	 * @param string $scan_id Scan.
	 * @param string $detector Detector.
	 * @param string $status Status ('' any).
	 * @param string $class Class ('' any).
	 * @return int
	 */
	public static function count_findings( $scan_id, $detector, $status = '', $class = '' ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return 0;
		}
		$table = self::findings_table();
		$sql   = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id=%s AND detector=%s", (string) $scan_id, (string) $detector );
		if ( '' !== $status ) {
			$sql .= $wpdb->prepare( ' AND status=%s', (string) $status );
		}
		if ( '' !== $class ) {
			$sql .= $wpdb->prepare( ' AND repair_class=%s', (string) $class );
		}
		return absint( $wpdb->get_var( $sql ) );
	}

	/**
	 * @param int    $finding_id Finding.
	 * @param string $status New status.
	 * @param string $batch_id Batch.
	 * @return bool
	 */
	public static function set_finding_status( $finding_id, $status, $batch_id = '' ) {
		global $wpdb;
		if ( ! self::is_ready() || absint( $finding_id ) <= 0 ) {
			return false;
		}
		return false !== $wpdb->update( self::findings_table(), array( 'status' => sanitize_key( $status ), 'batch_id' => (string) $batch_id ), array( 'id' => absint( $finding_id ) ) );
	}

	/**
	 * Write the "before" snapshot first (status pending). Returns the backup row id
	 * or 0 when the backup could not be persisted (caller must not change data).
	 *
	 * @param array $row batch_id, detector, action, finding_id, object_id, object_ref, before.
	 * @return int
	 */
	public static function backup_begin( array $row ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return 0;
		}
		$before = function_exists( 'wp_json_encode' ) ? wp_json_encode( $row['before'] ) : json_encode( $row['before'] );
		if ( ! is_string( $before ) ) {
			return 0;
		}
		$now = self::now();
		$ok  = $wpdb->insert(
			self::backup_table(),
			array(
				'batch_id'    => (string) $row['batch_id'],
				'detector'    => (string) $row['detector'],
				'action'      => (string) $row['action'],
				'finding_id'  => absint( isset( $row['finding_id'] ) ? $row['finding_id'] : 0 ),
				'object_id'   => absint( $row['object_id'] ),
				'object_ref'  => substr( (string) ( isset( $row['object_ref'] ) ? $row['object_ref'] : '' ), 0, 191 ),
				'status'      => 'pending',
				'before_json' => $before,
				'after_json'  => null,
				'note'        => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( false === $ok ) {
			return 0;
		}
		$id = absint( $wpdb->insert_id );
		if ( $id <= 0 ) {
			return 0;
		}
		/* Verify the snapshot really landed before any change is made. */
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT before_json FROM ' . self::backup_table() . ' WHERE id=%d', $id ) );
		return is_string( $stored ) && $stored === $before ? $id : 0;
	}

	/**
	 * @param int        $id Backup row.
	 * @param string     $status applied|failed|undone|conflict|skipped.
	 * @param array|null $after After snapshot (null = unchanged).
	 * @param string     $note Short note.
	 * @return bool
	 */
	public static function backup_finish( $id, $status, $after = null, $note = '' ) {
		global $wpdb;
		$data = array(
			'status'     => sanitize_key( $status ),
			'note'       => substr( sanitize_text_field( (string) $note ), 0, 191 ),
			'updated_at' => self::now(),
		);
		if ( null !== $after ) {
			$data['after_json'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $after ) : json_encode( $after );
		}
		return false !== $wpdb->update( self::backup_table(), $data, array( 'id' => absint( $id ) ) );
	}

	/**
	 * Backup rows of a batch in reverse order (undo).
	 *
	 * @param string $batch_id Batch.
	 * @param int    $before_id Exclusive upper cursor (0 = from the end).
	 * @param int    $limit Limit.
	 * @return array|false
	 */
	public static function backup_page_desc( $batch_id, $before_id, $limit ) {
		global $wpdb;
		if ( ! self::is_ready() ) {
			return false;
		}
		$table = self::backup_table();
		$sql   = $before_id > 0
			? $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id=%s AND id<%d ORDER BY id DESC LIMIT %d", (string) $batch_id, absint( $before_id ), max( 1, absint( $limit ) ) )
			: $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id=%s ORDER BY id DESC LIMIT %d", (string) $batch_id, max( 1, absint( $limit ) ) );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		return $rows;
	}

	/**
	 * Status counts of one batch.
	 *
	 * @param string $batch_id Batch.
	 * @return array<string,int>
	 */
	public static function batch_counts( $batch_id ) {
		global $wpdb;
		$out = array();
		if ( ! self::is_ready() ) {
			return $out;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM ' . self::backup_table() . ' WHERE batch_id=%s GROUP BY status', (string) $batch_id ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['status'] ] = absint( $row['n'] );
		}
		return $out;
	}
}
