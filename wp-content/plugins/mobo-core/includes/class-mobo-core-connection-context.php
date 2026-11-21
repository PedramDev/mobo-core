<?php
/**
 * Request-local connection context for multi-backend routing.
 *
 * The context is intentionally in-memory only. Durable ownership lives in
 * connection-aware tables/queue rows; this class only selects which connection
 * one bounded operation is currently executing for.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Connection_Context {
	/** @var int */
	private static $connection_id = 0;

	public static function get_current_connection_id() {
		return absint( self::$connection_id );
	}

	/**
	 * Return the active Connection id, falling back to the enabled Default
	 * Connection only when no explicit request/worker context has been selected.
	 *
	 * Source-owned runtime code should use this helper instead of assuming that
	 * connection id 0 means the Default Connection. Durable rows never use 0 as
	 * an owned Connection after the multi-connection ownership migration.
	 *
	 * @return int
	 */
	public static function get_effective_connection_id() {
		$id = self::get_current_connection_id();
		if ( $id > 0 ) { return $id; }
		if ( class_exists( 'Mobo_Core_Connection_Repository' ) ) {
			$row = Mobo_Core_Connection_Repository::get_default();
			return is_array( $row ) ? absint( $row['id'] ?? 0 ) : 0;
		}
		return 0;
	}

	/** @return bool */
	public static function has_explicit_context() {
		return self::get_current_connection_id() > 0;
	}

	/**
	 * Legacy option-backed Source state predates Multi-Connection and belongs to
	 * the durable Default Connection only. Before the Connection table exists,
	 * preserve upgrade compatibility; afterwards, non-Default or unavailable
	 * explicit owners must never read, mutate, adopt, or delete that legacy row.
	 *
	 * @return bool
	 */
	public static function legacy_default_owner_allowed() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) || ! Mobo_Core_Connection_Repository::table_exists() ) {
			return true;
		}
		$id = self::get_effective_connection_id();
		if ( $id <= 0 ) { return false; }
		$row = Mobo_Core_Connection_Repository::get_by_id( $id );
		return is_array( $row ) && 1 === absint( $row['is_default'] ?? 0 );
	}

	public static function set_current_connection_id( $connection_id ) {
		self::$connection_id = absint( $connection_id );
		if ( class_exists( 'Mobo_Core_API_Client' ) && method_exists( 'Mobo_Core_API_Client', 'clear_request_context' ) ) {
			Mobo_Core_API_Client::clear_request_context();
		}
	}

	public static function clear() {
		self::set_current_connection_id( 0 );
	}

	/**
	 * Execute a callback in one connection context and always restore the caller.
	 *
	 * @param int      $connection_id Connection row id.
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function run( $connection_id, $callback ) {
		$previous = self::get_current_connection_id();
		self::set_current_connection_id( $connection_id );
		try {
			return call_user_func( $callback );
		} finally {
			self::set_current_connection_id( $previous );
		}
	}
}
