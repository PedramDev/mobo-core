<?php
/**
 * Safe wrappers for PHP functions that cheap shared hosts disable.
 *
 * php.ini disable_functions (and Suhosin's executor blacklist) remove functions
 * such as set_time_limit, ignore_user_abort, ini_get, getenv, error_log or
 * register_shutdown_function. On PHP 8 calling a disabled function is a fatal
 * "Call to undefined function"; on PHP 7.4 it is a warning plus NULL. Every
 * plugin call to such a function goes through this class, which checks
 * function_exists() once per request and falls back to behavior that keeps the
 * feature correct (request deadline helper, resumable jobs, WordPress shutdown
 * hook, random tokens, ...).
 *
 * The file is required early by mobo-core.php (not autoloaded) because it also
 * carries the ctype_digit() polyfill for PHP builds without ext-ctype.
 *
 * Tests can simulate a disabled function with simulate_disabled().
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Mobo_Core_Environment', false ) ) {

	final class Mobo_Core_Environment {

		/** @var array<string,bool> function name => callable, memoized per request. */
		private static $can = array();

		/** @var array<string,bool> Test-only simulated disabled functions. */
		private static $simulated = array();

		/**
		 * Test helper: treat these functions as disabled (empty array resets).
		 *
		 * @param string[] $functions Function names.
		 * @return void
		 */
		public static function simulate_disabled( $functions ) {
			self::$simulated = array();
			foreach ( (array) $functions as $function ) {
				self::$simulated[ strtolower( (string) $function ) ] = true;
			}
			self::$can = array();
		}

		/**
		 * Whether a PHP function can be called on this host.
		 *
		 * function_exists() is false for functions listed in disable_functions on
		 * PHP 7.4 and 8.x alike, and for functions of a missing extension.
		 *
		 * @param string $function Function name.
		 * @return bool
		 */
		public static function can( $function ) {
			$key = strtolower( (string) $function );
			if ( ! isset( self::$can[ $key ] ) ) {
				/* Extensions such as Xdebug can keep a disabled function visible to
				 * function_exists() on PHP 7.4, so the ini list is checked as well. */
				self::$can[ $key ] = ! isset( self::$simulated[ $key ] ) && function_exists( $key ) && ! isset( self::ini_disabled()[ $key ] );
			}
			return self::$can[ $key ];
		}

		/** @var array<string,bool>|null Parsed disable_functions + Suhosin blacklist. */
		private static $ini_disabled = null;

		/**
		 * Functions listed in disable_functions / suhosin.executor.func.blacklist.
		 * Read with a raw ini_get() guarded by function_exists() (can() must not
		 * recurse into itself here).
		 *
		 * @return array<string,bool>
		 */
		private static function ini_disabled() {
			if ( null === self::$ini_disabled ) {
				self::$ini_disabled = array();
				if ( function_exists( 'ini_get' ) ) {
					foreach ( array( 'disable_functions', 'suhosin.executor.func.blacklist' ) as $directive ) {
						$raw = @ini_get( $directive ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ini_get itself may be disabled on PHP 7.4 (warning + null).
						foreach ( explode( ',', strtolower( is_string( $raw ) ? $raw : '' ) ) as $name ) {
							$name = trim( $name );
							if ( '' !== $name && preg_match( '/^[a-z0-9_]+$/', $name ) ) {
								self::$ini_disabled[ $name ] = true;
							}
						}
					}
				}
			}
			return self::$ini_disabled;
		}

		/**
		 * ini_get() that never fatals. Returns $default when ini_get is disabled or
		 * the directive is unknown.
		 *
		 * @param string $directive Directive.
		 * @param string $default   Fallback.
		 * @return string
		 */
		public static function ini( $directive, $default = '' ) {
			if ( ! self::can( 'ini_get' ) ) {
				return (string) $default;
			}
			$value = ini_get( (string) $directive );
			return false === $value ? (string) $default : (string) $value;
		}

		/**
		 * Best-effort set_time_limit(). Without it the caller must rely on the
		 * request deadline helper (Mobo_Core_Request_Deadline), which every
		 * budgeted stage already does.
		 *
		 * @param int $seconds Seconds (0 = unlimited).
		 * @return bool True when the limit was applied.
		 */
		public static function try_set_time_limit( $seconds ) {
			if ( ! self::can( 'set_time_limit' ) ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Shared hosts may refuse it at runtime.
			return (bool) @set_time_limit( max( 0, (int) $seconds ) );
		}

		/**
		 * Best-effort ignore_user_abort(). Without it a disconnecting client may stop
		 * the request; all Mobo jobs are checkpointed and resumable, so the next
		 * worker continues from the last durable cursor.
		 *
		 * @param bool $enabled Value.
		 * @return bool True when applied.
		 */
		public static function ignore_user_abort( $enabled = true ) {
			if ( ! self::can( 'ignore_user_abort' ) ) {
				return false;
			}
			ignore_user_abort( (bool) $enabled );
			return true;
		}

		/**
		 * register_shutdown_function() with a WordPress "shutdown" action fallback.
		 *
		 * @param callable $callback Callback.
		 * @param mixed    ...$args  Arguments.
		 * @return string 'native', 'wp-shutdown' or 'none'.
		 */
		public static function on_shutdown( $callback, ...$args ) {
			if ( self::can( 'register_shutdown_function' ) ) {
				register_shutdown_function( $callback, ...$args );
				return 'native';
			}
			if ( function_exists( 'add_action' ) ) {
				add_action(
					'shutdown',
					static function () use ( $callback, $args ) {
						call_user_func_array( $callback, $args );
					},
					PHP_INT_MAX
				);
				return 'wp-shutdown';
			}
			return 'none';
		}

		/**
		 * memory_get_usage() or 0.
		 *
		 * @param bool $real Real usage.
		 * @return int
		 */
		public static function memory_usage( $real = true ) {
			return self::can( 'memory_get_usage' ) ? (int) memory_get_usage( (bool) $real ) : 0;
		}

		/**
		 * memory_get_peak_usage() or 0.
		 *
		 * @param bool $real Real usage.
		 * @return int
		 */
		public static function memory_peak( $real = true ) {
			return self::can( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( (bool) $real ) : 0;
		}

		/**
		 * getenv() or false.
		 *
		 * @param string $name Variable.
		 * @return string|false
		 */
		public static function env( $name ) {
			return self::can( 'getenv' ) ? getenv( (string) $name ) : false;
		}

		/** @return string Loaded php.ini path or ''. */
		public static function ini_file() {
			if ( ! self::can( 'php_ini_loaded_file' ) ) {
				return '';
			}
			$file = php_ini_loaded_file();
			return is_string( $file ) ? $file : '';
		}

		/**
		 * Write to the PHP error log only when error_log() exists.
		 *
		 * @param string $message Message.
		 * @return bool
		 */
		public static function log( $message ) {
			if ( ! self::can( 'error_log' ) ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Optional diagnostic sink.
			return (bool) error_log( (string) $message );
		}

		/**
		 * usleep() or a no-op (callers only use it to back off briefly).
		 *
		 * @param int $microseconds Microseconds.
		 * @return void
		 */
		public static function sleep_us( $microseconds ) {
			$microseconds = max( 0, (int) $microseconds );
			if ( $microseconds > 0 && self::can( 'usleep' ) ) {
				usleep( $microseconds );
			}
		}

		/**
		 * scandir() or an empty list.
		 *
		 * @param string $directory Directory.
		 * @return string[]
		 */
		public static function scandir( $directory ) {
			if ( ! self::can( 'scandir' ) ) {
				return array();
			}
			$items = @scandir( (string) $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir/permission failures are handled as empty.
			return is_array( $items ) ? $items : array();
		}

		/**
		 * gethostbynamel() or an empty list (callers treat it as unresolvable).
		 *
		 * @param string $host Host.
		 * @return string[]
		 */
		public static function resolve_ipv4( $host ) {
			if ( ! self::can( 'gethostbynamel' ) ) {
				return array();
			}
			$ips = @gethostbynamel( (string) $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled by caller.
			return is_array( $ips ) ? $ips : array();
		}

		/**
		 * gethostbyname() semantics: returns the host unchanged when it cannot be
		 * resolved (or DNS functions are disabled).
		 *
		 * @param string $host Host.
		 * @return string
		 */
		public static function resolve_host( $host ) {
			$host = (string) $host;
			if ( ! self::can( 'gethostbyname' ) ) {
				return $host;
			}
			return (string) gethostbyname( $host );
		}

		/**
		 * Cryptographically strong random hex string with fallbacks for hosts that
		 * disable random_bytes(): random_int(), then wp_generate_password().
		 *
		 * @param int $bytes Number of random bytes (hex length = 2 * bytes).
		 * @return string
		 */
		public static function random_hex( $bytes ) {
			$bytes = max( 1, min( 256, (int) $bytes ) );
			if ( self::can( 'random_bytes' ) ) {
				try {
					return bin2hex( random_bytes( $bytes ) );
				} catch ( Throwable $e ) {
					unset( $e );
				}
			}
			$hex = '';
			if ( self::can( 'random_int' ) ) {
				try {
					for ( $i = 0; $i < $bytes; $i++ ) {
						$hex .= sprintf( '%02x', random_int( 0, 255 ) );
					}
					return $hex;
				} catch ( Throwable $e ) {
					$hex = '';
				}
			}
			$wp_password = function_exists( 'wp_generate_password' );
			while ( strlen( $hex ) < 2 * $bytes ) {
				$seed = $wp_password ? wp_generate_password( 64, true, true ) : uniqid( '', true ) . mt_rand();
				$hex .= hash( 'sha256', $seed . '|' . strlen( $hex ) . '|' . microtime( true ) );
			}
			return substr( $hex, 0, 2 * $bytes );
		}

		/**
		 * Random binary string (random_bytes() semantics) built on random_hex().
		 *
		 * @param int $bytes Length.
		 * @return string
		 */
		public static function random_raw( $bytes ) {
			$hex = self::random_hex( $bytes );
			$raw = function_exists( 'hex2bin' ) ? hex2bin( $hex ) : pack( 'H*', $hex );
			return is_string( $raw ) ? $raw : '';
		}

		/**
		 * Functions disabled by php.ini disable_functions or Suhosin's blacklist.
		 *
		 * @return string[] Lower-case names.
		 */
		public static function disabled_functions() {
			$names = self::ini_disabled();
			foreach ( array_keys( self::$simulated ) as $name ) {
				$names[ $name ] = true;
			}
			ksort( $names );
			return array_keys( $names );
		}
	}
}

/*
 * ext-ctype polyfill (some minimal PHP builds omit it). Only declared when the
 * extension is genuinely absent, so it can never clash with the real function or
 * with symfony/polyfill-ctype, which uses the same guard.
 */
if ( ! function_exists( 'ctype_digit' ) ) {
	/**
	 * @param mixed $text Value.
	 * @return bool
	 */
	function ctype_digit( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- polyfill of a PHP core function.
		if ( is_int( $text ) ) {
			if ( $text >= -128 && $text <= 255 ) {
				$text = chr( $text < 0 ? $text + 256 : $text );
			} else {
				$text = (string) $text;
			}
		}
		return is_string( $text ) && '' !== $text && ! preg_match( '/[^0-9]/', $text );
	}
}
