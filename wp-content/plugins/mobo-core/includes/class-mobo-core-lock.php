<?php
/**
 * Runtime lock helper.
 *
 * Uses transients to prevent concurrent execution.
 * This is important because C# may call endpoints repeatedly.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Lock {

	/**
	 * Acquire a named lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl TTL in seconds.
	 * @return string|false Lock token or false.
	 */
	public static function acquire( $name, $ttl = 30 ) {
		$name = sanitize_key( (string) $name );
		$ttl  = max( 5, absint( $ttl ) );

		if ( '' === $name ) {
			return false;
		}

		$key   = self::key( $name );
		$token = wp_generate_uuid4();

		$current = get_transient( $key );

		if ( false !== $current && '' !== $current ) {
			return false;
		}

		set_transient( $key, $token, $ttl );

		/*
		 * Verify ownership.
		 * This reduces race-condition risk on object-cache backed installs.
		 */
		$stored = get_transient( $key );

		if ( $stored !== $token ) {
			return false;
		}

		return $token;
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $name Lock name.
	 * @param string $token Lock token.
	 * @return bool
	 */
	public static function release( $name, $token ) {
		$name  = sanitize_key( (string) $name );
		$token = sanitize_text_field( (string) $token );

		if ( '' === $name || '' === $token ) {
			return false;
		}

		$key    = self::key( $name );
		$stored = get_transient( $key );

		if ( $stored !== $token ) {
			return false;
		}

		delete_transient( $key );

		return true;
	}

	/**
	 * Check whether a named lock currently exists.
	 *
	 * This is read-only and is intended for admin diagnostics/status screens.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function is_locked( $name ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name ) {
			return false;
		}

		$current = get_transient( self::key( $name ) );
		return false !== $current && '' !== $current;
	}

	/**
	 * Force delete a lock.
	 *
	 * Use only for admin/debug cleanup.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function force_release( $name ) {
		$name = sanitize_key( (string) $name );

		if ( '' === $name ) {
			return;
		}

		delete_transient( self::key( $name ) );
	}

	/**
	 * Build transient key.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function key( $name ) {
		return 'mobo_core_lock_' . sanitize_key( (string) $name );
	}
}