<?php
/**
 * Cheap "does this Mobo table exist?" checks.
 *
 * Several stores ran `SHOW TABLES LIKE …` on every call; on casemobilee the
 * Connection-settings probe was the single most frequent query of a cron slice
 * (434 processlist samples while requests hit the 30 s limit).
 *
 * - Positive results are memoized per request and persisted (non-autoloaded
 *   option, TTL) so later requests skip the probe entirely.
 * - Negative results are never cached: a table may be created later in the same
 *   upgrade request.
 * - flush() (called when Mobo installs/upgrades its schema) drops everything; a
 *   change of mobo_core_db_version or the storage schema version also invalidates
 *   the persisted map.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Table_Cache {

	/** Persisted positive results. */
	const OPTION = 'mobo_core_table_exists_v1';

	/** Lifetime of a persisted positive result (seconds). */
	const TTL = 3600;

	/** @var array<string,bool> */
	private static $memo = array();

	/** @var array|null Persisted map loaded for this request. */
	private static $persisted = null;

	/** @var int Probes issued in this request (tests/diagnostics). */
	public static $probes = 0;

	/**
	 * Whether $table exists.
	 *
	 * @param string $table Physical table name.
	 * @return bool
	 */
	public static function exists( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( '' === $table ) {
			return false;
		}
		if ( ! empty( self::$memo[ $table ] ) ) {
			return true;
		}
		$map = self::persisted();
		$now = time();
		if ( isset( $map['tables'][ $table ] ) && absint( $map['tables'][ $table ] ) > $now ) {
			self::$memo[ $table ] = true;
			return true;
		}
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		self::$probes++;
		$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$exists = (string) $found === $table;
		if ( $exists ) {
			self::$memo[ $table ] = true;
			$map['tables'][ $table ] = $now + self::TTL;
			self::$persisted = $map;
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTION, $map, false );
			}
		}
		return $exists;
	}

	/**
	 * Forget every cached result (schema install/upgrade/uninstall).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$memo      = array();
		self::$persisted = null;
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION );
		}
	}

	/** @return string Fingerprint that invalidates the persisted map on schema/version changes. */
	private static function fingerprint() {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}
		return (string) get_option( 'mobo_core_db_version', '' ) . '|' . (string) get_option( 'mobo_core_storage_schema_version', '' );
	}

	/** @return array{fp:string,tables:array<string,int>} */
	private static function persisted() {
		if ( null !== self::$persisted ) {
			return self::$persisted;
		}
		$fp  = self::fingerprint();
		$map = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $map ) || ! isset( $map['fp'], $map['tables'] ) || ! is_array( $map['tables'] ) || (string) $map['fp'] !== $fp ) {
			$map = array( 'fp' => $fp, 'tables' => array() );
		}
		self::$persisted = $map;
		return $map;
	}

	/** Test hook. @return void */
	public static function reset() {
		self::$memo      = array();
		self::$persisted = null;
		self::$probes    = 0;
	}
}
