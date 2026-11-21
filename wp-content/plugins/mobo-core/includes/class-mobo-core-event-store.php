<?php
/** Bounded operational event storage outside wp_options. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Event_Store {
	private static $availability = null;

	public static function table_name() {
		global $wpdb;
		$table = class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'events' ) : '';
		return '' !== $table ? $table : $wpdb->prefix . 'mobo_events';
	}

	public static function reset_availability_cache() { self::$availability = null; }

	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	public static function is_available() {
		global $wpdb;
		if ( null !== self::$availability ) { return (bool) self::$availability; }
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$availability = ( (string) $found === $table );
		return (bool) self::$availability;
	}

	public static function append( $event_type, $payload, $max_rows = 500 ) {
		global $wpdb;
		$event_type = substr( sanitize_key( (string) $event_type ), 0, 64 );
		if ( '' === $event_type || ! self::is_available() ) { return false; }
		$table = self::table_name();
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$serialized = maybe_serialize( $payload );
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( false === $wpdb->insert( $table, array( 'connection_id' => $connection_id, 'event_type' => $event_type, 'payload' => $serialized, 'created_at' => $now ), array( '%d', '%s', '%s', '%s' ) ) ) { return false; }
		$max_rows = max( 1, min( 5000, absint( $max_rows ) ) );
		$pruned = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE connection_id=%d AND event_type=%s AND id NOT IN (SELECT id FROM (SELECT id FROM {$table} WHERE connection_id=%d AND event_type=%s ORDER BY id DESC LIMIT %d) keep_rows)",
			$connection_id,
			$event_type,
			$connection_id,
			$event_type,
			$max_rows
		) );
		return false !== $pruned;
	}

	public static function latest( $event_type, $limit = 500 ) {
		global $wpdb;
		$event_type = substr( sanitize_key( (string) $event_type ), 0, 64 );
		$limit = max( 1, min( 5000, absint( $limit ) ) );
		if ( '' === $event_type || ! self::is_available() ) { return array(); }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return array(); }
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT payload FROM ' . self::table_name() . ' WHERE connection_id=%d AND event_type=%s ORDER BY id DESC LIMIT %d', $connection_id, $event_type, $limit ) );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) { return array(); }
		$out = array();
		foreach ( $rows as $payload ) { $out[] = maybe_unserialize( $payload ); }
		return $out;
	}

	public static function clear( $event_type ) {
		global $wpdb;
		$event_type = substr( sanitize_key( (string) $event_type ), 0, 64 );
		if ( '' === $event_type || ! self::is_available() ) { return false; }
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		return false !== $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'event_type' => $event_type ), array( '%d', '%s' ) );
	}

	/** Migrate the bounded newest-first legacy log and verify its visible window. */
	public static function migrate_option_log( $option_name, $event_type, $max_rows = 500 ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }
		if ( class_exists( 'Mobo_Core_Connection_Settings' ) && ! Mobo_Core_Connection_Settings::is_default_connection( $connection_id ) ) {
			/* The option-backed log predates Multi-Connection and is Default-owned. */
			return true;
		}
		$sentinel = new stdClass();
		$legacy = get_option( $option_name, $sentinel );
		if ( $sentinel === $legacy ) { return true; }
		if ( ! is_array( $legacy ) || ! self::is_available() ) { return false; }
		$max_rows = max( 1, min( 5000, absint( $max_rows ) ) );
		$desired = array_values( array_slice( $legacy, 0, $max_rows ) );
		foreach ( array_reverse( $desired ) as $entry ) {
			if ( ! self::append( $event_type, $entry, $max_rows ) ) { return false; }
		}
		$stored = self::latest( $event_type, $max_rows );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return false; }
		if ( maybe_serialize( array_slice( $stored, 0, count( $desired ) ) ) !== maybe_serialize( $desired ) ) { return false; }
		delete_option( $option_name );
		wp_cache_delete( $option_name, 'options' );
		return $sentinel === get_option( $option_name, $sentinel );
	}
}
