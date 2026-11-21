<?php
/**
 * Durable large-state storage outside wp_options.
 *
 * Keeps checkpoint/snapshot payloads in a plugin-owned table so large arrays do
 * not participate in the WordPress options cache or serialized option rewrites.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_State_Store {
	private static $availability = null;

	public static function table_name() {
		global $wpdb;
		if ( class_exists( 'Mobo_Core_Storage_Schema' ) ) {
			$table = Mobo_Core_Storage_Schema::table_name( 'state' );
			if ( '' !== $table ) { return $table; }
		}
		return $wpdb->prefix . 'mobo_state';
	}

	public static function is_available() {
		global $wpdb;
		if ( null !== self::$availability ) { return (bool) self::$availability; }
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$availability = ( (string) $found === (string) $table );
		return (bool) self::$availability;
	}

	public static function reset_availability_cache() { self::$availability = null; }

	public static function get( $key, $default = null ) {
		global $wpdb;
		$key = self::normalize_key( $key );
		if ( '' === $key || ! self::is_available() ) { return $default; }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return $default; }
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT state_value FROM {$table} WHERE connection_id=%d AND state_key = %s LIMIT 1", $connection_id, $key ) );
		if ( null === $value ) { return $default; }
		return maybe_unserialize( $value );
	}

	public static function has( $key ) {
		global $wpdb;
		$key = self::normalize_key( $key );
		if ( '' === $key || ! self::is_available() ) { return false; }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT state_key FROM {$table} WHERE connection_id=%d AND state_key = %s LIMIT 1", $connection_id, $key ) );
		return $key === (string) $found;
	}

	public static function set( $key, $value ) {
		global $wpdb;
		$key = self::normalize_key( $key );
		if ( '' === $key || ! self::is_available() ) { return false; }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$serialized = maybe_serialize( $value );
		$hash = hash( 'sha256', (string) $serialized );
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (connection_id,state_key,state_value,value_hash,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,%s)
			 ON DUPLICATE KEY UPDATE state_value=VALUES(state_value), value_hash=VALUES(value_hash), updated_at=VALUES(updated_at)",
			$connection_id, $key, $serialized, $hash, $now, $now
		);
		$result = $wpdb->query( $sql );
		if ( false === $result ) { return false; }
		$stored = self::get( $key, null );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return false; }
		return maybe_serialize( $stored ) === $serialized;
	}

	public static function delete( $key ) {
		global $wpdb;
		$key = self::normalize_key( $key );
		if ( '' === $key || ! self::is_available() ) { return false; }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$result = $wpdb->delete( $table, array( 'connection_id' => $connection_id, 'state_key' => $key ), array( '%d', '%s' ) );
		return false !== $result;
	}

	public static function list_prefix( $prefix, $limit = 100 ) {
		global $wpdb;
		$prefix = self::normalize_key( $prefix, true );
		$limit  = max( 1, min( 10000, absint( $limit ) ) );
		if ( '' === $prefix || ! self::is_available() ) { return array(); }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return array(); }
		$like = $wpdb->esc_like( $prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT state_key,state_value FROM {$table} WHERE connection_id=%d AND state_key LIKE %s ORDER BY id ASC LIMIT %d", $connection_id, $like, $limit ), ARRAY_A );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = isset( $row['state_key'] ) ? (string) $row['state_key'] : '';
			if ( '' === $key ) { continue; }
			$out[ $key ] = maybe_unserialize( isset( $row['state_value'] ) ? $row['state_value'] : '' );
		}
		return $out;
	}

	public static function delete_prefix( $prefix ) {
		global $wpdb;
		$prefix = self::normalize_key( $prefix, true );
		if ( '' === $prefix || ! self::is_available() ) { return false; }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$like = $wpdb->esc_like( $prefix ) . '%';
		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE connection_id=%d AND state_key LIKE %s", $connection_id, $like ) );
	}

	/** Migrate one legacy option only after the new row verifies byte-for-byte. */
	public static function migrate_option( $option_name, $state_key = '' ) {
		$option_name = sanitize_key( (string) $option_name );
		$state_key   = '' !== (string) $state_key ? self::normalize_key( $state_key ) : $option_name;
		if ( '' === $option_name || '' === $state_key || ! self::is_available() ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) {
			/* The legacy wp_options row is Default-owned. For another Connection,
			 * skipping migration is success: there is simply no legacy state to adopt. */
			return true;
		}
		$sentinel = new stdClass();
		$legacy = get_option( $option_name, $sentinel );
		if ( $sentinel === $legacy ) { return true; }
		if ( ! self::set( $state_key, $legacy ) ) { return false; }
		$stored = self::get( $state_key, null );
		global $wpdb;
		if ( '' !== trim( (string) $wpdb->last_error ) || maybe_serialize( $stored ) !== maybe_serialize( $legacy ) ) { return false; }
		delete_option( $option_name );
		wp_cache_delete( $option_name, 'options' );
		return $sentinel === get_option( $option_name, $sentinel );
	}

	/** Migrate plugin-owned legacy option prefix to same-named state rows. */
	public static function migrate_option_prefix( $prefix, $limit = 10000 ) {
		global $wpdb;
		$prefix = sanitize_key( (string) $prefix );
		$limit  = max( 1, min( 50000, absint( $limit ) ) );
		if ( '' === $prefix || ! self::is_available() ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Context' ) && method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) && ! Mobo_Core_Connection_Context::legacy_default_owner_allowed() ) { return true; }
		$like = $wpdb->esc_like( $prefix ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $like, $limit ) );
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( ! self::migrate_option( $name, $name ) ) { return false; }
		}
		return true;
	}

	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? Mobo_Core_Connection_Context::get_effective_connection_id() : 0;
	}

	private static function normalize_key( $key, $allow_prefix = false ) {
		$key = strtolower( trim( (string) $key ) );
		$key = preg_replace( '/[^a-z0-9_:\-.]/', '', $key );
		if ( ! $allow_prefix ) { $key = substr( $key, 0, 191 ); }
		return (string) $key;
	}
}
