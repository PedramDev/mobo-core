<?php
/**
 * Runtime lock helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Lock {

	/**
	 * Acquire lock.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl TTL seconds.
	 * @return string|false
	 */
	public static function acquire( $name, $ttl ) {
		$name  = sanitize_key( $name );
		$ttl   = max( 5, absint( $ttl ) );
		$key   = 'mobo_core_lock_' . $name;
		$token = wp_generate_uuid4();

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, $token, $ttl );

		return $token;
	}

	/**
	 * Release lock.
	 *
	 * @param string $name Lock name.
	 * @param string $token Lock token.
	 * @return void
	 */
	public static function release( $name, $token ) {
		$name = sanitize_key( $name );
		$key  = 'mobo_core_lock_' . $name;

		if ( get_transient( $key ) === $token ) {
			delete_transient( $key );
		}
	}
}