<?php
/**
 * Shared storage-capacity preflight for every image mutation path.
 *
 * Filesystem writability alone is not enough on shared hosting: an exhausted
 * account quota/inode limit can still report a writable uploads directory. A
 * tiny create/write/delete probe closes that gap before WordPress starts an
 * expensive sideload or subsize generation.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Image_Storage {

	const DEFAULT_MIN_FREE_BYTES = 268435456; // 256 MiB safety reserve.

	/** @var array|null Latest request-local diagnostic result. */
	private static $cached = null;

	/**
	 * Check uploads capacity without leaving a probe file behind.
	 *
	 * @param int  $minimum_free_bytes Required filesystem reserve.
	 * @param bool $write_probe Whether to verify a real small write.
	 * @return array
	 */
	public static function check( $minimum_free_bytes = 0, $write_probe = true ) {
		$minimum_free_bytes = absint( $minimum_free_bytes );
		if ( $minimum_free_bytes <= 0 ) {
			$minimum_free_bytes = class_exists( 'Mobo_Core_Settings' )
				? Mobo_Core_Settings::get_int( 'mobo_core_image_min_free_bytes', self::DEFAULT_MIN_FREE_BYTES, 67108864, 2147483647 )
				: self::DEFAULT_MIN_FREE_BYTES;
		}

		/* Do not reuse a prior success: one large source can consume the remaining
		 * reserve inside the same worker request. Each mutation gets a fresh capacity
		 * and quota/inode probe. */

		$uploads = wp_upload_dir( null, true );
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$path    = isset( $uploads['path'] ) ? wp_normalize_path( (string) $uploads['path'] ) : $basedir;
		$result  = array(
			'ready'             => false,
			'code'              => 'uploads-unavailable',
			'message'           => 'مسیر uploads برای پردازش تصویر در دسترس نیست.',
			'freeBytes'         => null,
			'minimumFreeBytes'  => $minimum_free_bytes,
			'writeProbePassed'  => false,
		);

		if ( ! empty( $uploads['error'] ) || '' === $basedir || '' === $path ) {
			self::$cached = $result;
			return $result;
		}

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			$result['code']    = 'uploads-create-failed';
			$result['message'] = 'وردپرس نتوانست پوشه مقصد uploads را ایجاد کند.';
			self::$cached      = $result;
			return $result;
		}

		if ( ! wp_is_writable( $path ) ) {
			$result['code']    = 'uploads-not-writable';
			$result['message'] = 'پوشه uploads اجازه نوشتن ندارد.';
			self::$cached      = $result;
			return $result;
		}

		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( $basedir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disable or restrict this probe.
			if ( false !== $free && is_numeric( $free ) ) {
				$result['freeBytes'] = max( 0, (int) $free );
				if ( $result['freeBytes'] < $minimum_free_bytes ) {
					$result['code']    = 'storage-reserve-low';
					$result['message'] = sprintf(
						'فضای آزاد uploads کمتر از حد امن است؛ آزاد: %s مگابایت، حداقل لازم: %s مگابایت.',
						number_format_i18n( $result['freeBytes'] / 1048576, 1 ),
						number_format_i18n( $minimum_free_bytes / 1048576, 0 )
					);
					self::$cached = $result;
					return $result;
				}
			}
		}

		if ( $write_probe ) {
			$probe_name = wp_unique_filename( $path, '.mobo-image-storage-probe.tmp' );
			$probe_path = wp_normalize_path( trailingslashit( $path ) . $probe_name );
			$handle     = @fopen( $probe_path, 'x+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is the capacity signal.
			$written    = false;
			if ( is_resource( $handle ) ) {
				$written = 4 === @fwrite( $handle, 'mobo' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Quota/inode failure is expected and handled.
				@fflush( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort durability probe.
				@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
			}
			if ( is_file( $probe_path ) ) {
				wp_delete_file( $probe_path );
			}

			if ( ! $written ) {
				$result['code']    = 'storage-write-probe-failed';
				$result['message'] = 'نوشتن آزمایشی در uploads ناموفق بود؛ سهمیه فضا یا inode ها ممکن است پر شده باشد.';
				self::$cached      = $result;
				return $result;
			}
			$result['writeProbePassed'] = true;
		} else {
			$result['writeProbePassed'] = true;
		}

		$result['ready']   = true;
		$result['code']    = 'ready';
		$result['message'] = 'فضای ذخیره‌سازی تصویر آماده است.';
		self::$cached      = $result;
		return $result;
	}

	/** @return void */
	public static function reset_request_cache() {
		self::$cached = null;
	}
}
