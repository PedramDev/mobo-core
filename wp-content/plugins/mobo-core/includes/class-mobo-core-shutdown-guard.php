<?php
/**
 * Early shutdown guard: releases Mobo leases even when the request dies.
 *
 * Registered from mobo-core.php while the plugin file loads, i.e. before
 * WooCommerce registers its own shutdown error logger. On a memory-limit fatal
 * WooCommerce's logger can fatal a second time, and PHP then skips every later
 * shutdown callback; Mobo leases registered later (runner lock, dispatcher
 * lease) used to leak for their full TTL (~150 s of "locked" cron after
 * griffinstore's 256 MB fatals). This guard runs first:
 *   1. drops a small pre-allocated memory reserve and request-local caches so the
 *      release queries have memory to run in,
 *   2. releases every lease tracked with track_lock() (owner-checked, so a no-op
 *      after the normal release),
 *   3. records which runner stage the request died in (for graceful degradation).
 *
 * PHP 7.4 compatible. No WordPress dependency at load time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Mobo_Core_Shutdown_Guard', false ) ) {

	final class Mobo_Core_Shutdown_Guard {

		/** Size of the memory reserve released on shutdown. */
		const RESERVE_BYTES = 262144;

		/** @var string|null */
		private static $reserve = null;

		/** @var array<string,string> lock name => token */
		private static $locks = array();

		/** @var bool */
		private static $registered = false;

		/** @var callable[] Extra callbacks run after the locks are released (fatal only). */
		private static $on_fatal = array();

		/** @var bool Test hook: true once on_shutdown() ran. */
		public static $ran = false;

		/**
		 * Register the guard (idempotent) and allocate the memory reserve.
		 *
		 * @return void
		 */
		public static function register() {
			if ( self::$registered ) {
				return;
			}
			self::$registered = true;
			self::$reserve    = str_repeat( ' ', self::RESERVE_BYTES );
			if ( class_exists( 'Mobo_Core_Environment', false ) ) {
				Mobo_Core_Environment::on_shutdown( array( __CLASS__, 'on_shutdown' ) );
			} elseif ( function_exists( 'register_shutdown_function' ) ) {
				register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
			}
		}

		/**
		 * Track a lease so it is released if the request dies.
		 *
		 * @param string $name  Mobo_Core_Lock name.
		 * @param string $token Owner token.
		 * @return void
		 */
		public static function track_lock( $name, $token ) {
			$name  = (string) $name;
			$token = (string) $token;
			if ( '' !== $name && '' !== $token ) {
				self::$locks[ $name ] = $token;
			}
		}

		/**
		 * Stop tracking a lease (after its normal release).
		 *
		 * @param string $name Lock name.
		 * @return void
		 */
		public static function untrack_lock( $name ) {
			unset( self::$locks[ (string) $name ] );
		}

		/** @return array<string,string> Tracked leases (tests). */
		public static function tracked() {
			return self::$locks;
		}

		/**
		 * Run a callback only when the request ends with a fatal error.
		 *
		 * @param string   $key      Unique key.
		 * @param callable $callback Callback receiving the error_get_last() array.
		 * @return void
		 */
		public static function on_fatal( $key, $callback ) {
			if ( is_callable( $callback ) ) {
				self::$on_fatal[ (string) $key ] = $callback;
			}
		}

		/**
		 * Remove a fatal-only callback.
		 *
		 * @param string $key Key.
		 * @return void
		 */
		public static function forget_on_fatal( $key ) {
			unset( self::$on_fatal[ (string) $key ] );
		}

		/** @return array|null The fatal error of this request, if any. */
		public static function fatal_error() {
			$error = function_exists( 'error_get_last' ) ? error_get_last() : null;
			$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
			return is_array( $error ) && isset( $error['type'] ) && ( (int) $error['type'] & $fatal ) ? $error : null;
		}

		/**
		 * Shutdown callback.
		 *
		 * @return void
		 */
		public static function on_shutdown() {
			self::$ran     = true;
			self::$reserve = null;
			$fatal         = self::fatal_error();
			if ( null !== $fatal && class_exists( 'Mobo_Core_Storage_Row_Cache', false ) && method_exists( 'Mobo_Core_Storage_Row_Cache', 'flush_all' ) ) {
				try { Mobo_Core_Storage_Row_Cache::flush_all(); } catch ( Throwable $e ) { unset( $e ); }
			}
			if ( null !== $fatal && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
			foreach ( self::$locks as $name => $token ) {
				try {
					if ( class_exists( 'Mobo_Core_Lock', false ) ) {
						Mobo_Core_Lock::release( $name, $token );
					}
				} catch ( Throwable $e ) {
					unset( $e );
				}
			}
			self::$locks = array();
			if ( null !== $fatal ) {
				foreach ( self::$on_fatal as $callback ) {
					try { call_user_func( $callback, $fatal ); } catch ( Throwable $e ) { unset( $e ); }
				}
			}
			self::$on_fatal = array();
		}

		/** Test hook. @return void */
		public static function reset() {
			self::$locks    = array();
			self::$on_fatal = array();
			self::$ran      = false;
		}
	}
}
