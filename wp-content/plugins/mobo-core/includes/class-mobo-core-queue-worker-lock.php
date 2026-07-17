<?php
/**
 * Operating-system process lock for Mobo queue workers.
 *
 * The lock file may remain on disk after a run. Worker ownership is determined
 * only by flock(), never by the existence of the file itself.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Queue_Worker_Lock {

	/**
	 * Whether the dedicated cPanel CLI queue worker is enabled.
	 *
	 * Disabled is the safe default so existing installations are not switched
	 * away from their current runner until wp-config.php is updated explicitly.
	 *
	 * @return bool
	 */
	public static function is_cli_worker_enabled() {
		return defined( 'MOBO_QUEUE_WORKER_ENABLED' ) && (bool) MOBO_QUEUE_WORKER_ENABLED;
	}

	/**
	 * Resolve the bounded worker runtime.
	 *
	 * @return int
	 */
	public static function max_runtime() {
		$value = defined( 'MOBO_QUEUE_WORKER_MAX_RUNTIME' ) ? (int) MOBO_QUEUE_WORKER_MAX_RUNTIME : 50;
		return max( 5, min( 55, $value ) );
	}

	/**
	 * Resolve idle sleep seconds.
	 *
	 * @return int
	 */
	public static function idle_sleep() {
		$value = defined( 'MOBO_QUEUE_WORKER_IDLE_SLEEP' ) ? (int) MOBO_QUEUE_WORKER_IDLE_SLEEP : 10;
		return max( 1, min( 30, $value ) );
	}

	/**
	 * Resolve the worker lock file path.
	 *
	 * @return string
	 */
	public static function path() {
		$configured = defined( 'MOBO_QUEUE_WORKER_LOCK_PATH' ) ? trim( (string) MOBO_QUEUE_WORKER_LOCK_PATH ) : '';

		if ( '' !== $configured ) {
			return $configured;
		}

		$base = defined( 'MOBO_CORE_DATA_DIR' ) && '' !== trim( (string) MOBO_CORE_DATA_DIR )
			? trailingslashit( (string) MOBO_CORE_DATA_DIR ) . 'locks/'
			: trailingslashit( sys_get_temp_dir() );

		$site_hash = substr( hash( 'sha256', (string) ABSPATH ), 0, 16 );
		return $base . 'mobo-queue-worker-' . $site_hash . '.lock';
	}

	/**
	 * Acquire a non-blocking exclusive OS lock.
	 *
	 * @return array|WP_Error Lock descriptor or error.
	 */
	public static function acquire() {
		$path      = self::path();
		$directory = dirname( $path );

		if ( ! is_dir( $directory ) ) {
			$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $directory ) : @mkdir( $directory, 0750, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback for early/runtime filesystem creation.
			if ( ! $created && ! is_dir( $directory ) ) {
				return new WP_Error( 'mobo_queue_worker_lock_directory', 'Unable to create queue worker lock directory: ' . $directory );
			}
		}

		$handle = @fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- flock requires a native file handle.
		if ( false === $handle ) {
			return new WP_Error( 'mobo_queue_worker_lock_open', 'Unable to open queue worker lock file: ' . $path );
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native handle belongs to flock.
			return new WP_Error( 'mobo_queue_worker_locked', 'Another Mobo queue worker is already running.', array( 'path' => $path ) );
		}

		$diagnostic = wp_json_encode(
			array(
				'pid'       => function_exists( 'getmypid' ) ? getmypid() : 0,
				'startedAt' => gmdate( 'c' ),
				'site'      => home_url( '/' ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, (string) $diagnostic . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native handle belongs to flock.
		fflush( $handle );

		return array(
			'handle' => $handle,
			'path'   => $path,
		);
	}

	/**
	 * Release a previously acquired lock.
	 *
	 * @param array|null $lock Lock descriptor.
	 * @return void
	 */
	public static function release( $lock ) {
		if ( ! is_array( $lock ) || ! isset( $lock['handle'] ) || ! is_resource( $lock['handle'] ) ) {
			return;
		}

		flock( $lock['handle'], LOCK_UN );
		fclose( $lock['handle'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native handle belongs to flock.
	}
}
