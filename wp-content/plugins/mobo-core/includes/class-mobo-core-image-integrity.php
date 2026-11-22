<?php
/**
 * Physical integrity and decode-cost checks for downloaded images.
 *
 * A dropped connection can leave a truncated JPEG/PNG whose header is intact:
 * WordPress accepts it, generates half-grey thumbnails and Mobo links it to the
 * product. A huge (e.g. 6000x6000) image can exhaust memory_limit inside GD or
 * Imagick and kill the runner request before any error can be recorded.
 *
 * Every check reads at most a few kilobytes from the file and never decodes the
 * bitmap. Pure helpers (testable without WordPress).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Image_Integrity {

	/** Bytes per pixel a GD/Imagick decode needs, including working copies. */
	const BYTES_PER_PIXEL = 5;
	/** Memory kept free for WordPress itself while decoding. */
	const MEMORY_RESERVE_BYTES = 16777216; // 16 MiB.
	/** Upper bound when memory headroom cannot be measured on this host. */
	const MAX_PIXELS_UNMEASURED = 50000000; // 50 MP.
	/** Bytes inspected at the end of a file for the format's end marker. */
	const TAIL_BYTES = 4096;

	/** Prefix of a transient (retryable) integrity failure. */
	const TRUNCATED_PREFIX = 'Downloaded image is truncated or corrupt:';
	/** Prefix of an image too large for any request on this host. */
	const TOO_LARGE_PREFIX = 'Permanent: image is too large to process within memory_limit:';
	/** Prefix of an image too large for the memory left in this request. */
	const HEADROOM_PREFIX  = 'Image deferred: not enough free memory in this request to decode it:';

	/**
	 * Pure: compare a streamed body with its declared Content-Length.
	 *
	 * @param mixed $content_length Header value ('' / null = unknown).
	 * @param int   $actual_bytes Bytes on disk.
	 * @return bool False only when a length was declared and differs.
	 */
	public static function length_matches( $content_length, $actual_bytes ) {
		if ( is_array( $content_length ) ) {
			$content_length = end( $content_length );
		}
		$content_length = trim( (string) $content_length );
		if ( '' === $content_length || ! preg_match( '/^\d+$/', $content_length ) ) {
			return true; /* Unknown (chunked/compressed): rely on the structural check. */
		}
		return (int) $content_length === (int) $actual_bytes;
	}

	/**
	 * Pure: structural completeness of an image from its head and tail bytes.
	 *
	 * @param string $head First bytes (>= 16).
	 * @param string $tail Last bytes (up to TAIL_BYTES).
	 * @param int    $size File size in bytes.
	 * @return string '' when complete, otherwise a short reason code.
	 */
	public static function structure_problem( $head, $tail, $size ) {
		$head = (string) $head;
		$tail = (string) $tail;
		$size = (int) $size;
		if ( $size <= 0 || strlen( $head ) < 12 ) {
			return 'empty';
		}
		if ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			/* JPEG: EOI (FF D9) must be present near the end; encoders may append
			 * a few padding bytes, never kilobytes of missing scan data. */
			return false === strpos( substr( $tail, -1024 ), "\xFF\xD9" ) ? 'jpeg-missing-eoi' : '';
		}
		if ( "\x89PNG\r\n\x1A\n" === substr( $head, 0, 8 ) ) {
			return false === strpos( substr( $tail, -64 ), 'IEND' ) ? 'png-missing-iend' : '';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			$riff = unpack( 'V', substr( $head, 4, 4 ) );
			$need = is_array( $riff ) ? (int) $riff[1] + 8 : 0;
			return ( $need > 0 && $size < $need ) ? 'webp-short-riff' : '';
		}
		if ( 'GIF8' === substr( $head, 0, 4 ) ) {
			return ';' === substr( rtrim( $tail, "\0" ), -1 ) ? '' : 'gif-missing-trailer';
		}
		return ''; /* Unknown format: WordPress' own MIME validation decides. */
	}

	/**
	 * Pure: may an image of these dimensions be decoded now?
	 *
	 * @param int $width Pixels.
	 * @param int $height Pixels.
	 * @param int $memory_limit memory_limit bytes (0 = unlimited/unknown).
	 * @param int $headroom Bytes still free in this request (PHP_INT_MAX = unknown).
	 * @return string ok|too_large|headroom
	 */
	public static function decode_budget( $width, $height, $memory_limit, $headroom ) {
		$pixels = max( 0, (int) $width ) * max( 0, (int) $height );
		if ( $pixels <= 0 ) {
			return 'ok';
		}
		$need = $pixels * self::BYTES_PER_PIXEL + self::MEMORY_RESERVE_BYTES;
		if ( (int) $memory_limit > 0 && $need > (int) $memory_limit ) {
			return 'too_large';
		}
		if ( (int) $memory_limit <= 0 && $pixels > self::MAX_PIXELS_UNMEASURED ) {
			return 'too_large';
		}
		if ( (int) $headroom !== PHP_INT_MAX && $need > (int) $headroom ) {
			return 'headroom';
		}
		return 'ok';
	}

	/**
	 * Inspect a local file: structure, Content-Length and decode budget.
	 *
	 * @param string     $path File.
	 * @param mixed      $content_length Declared length ('' = unknown).
	 * @param int|null   $memory_limit Override (tests); null = this host.
	 * @param int|null   $headroom Override (tests); null = this request.
	 * @return string '' when acceptable, otherwise a failure message with one of
	 *                the class prefixes.
	 */
	public static function inspect_file( $path, $content_length = '', $memory_limit = null, $headroom = null ) {
		$path = (string) $path;
		if ( '' === $path || ! is_file( $path ) ) {
			return self::TRUNCATED_PREFIX . ' file-missing';
		}
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- concurrent removal is reported below.
		if ( ! self::length_matches( $content_length, $size ) ) {
			return self::TRUNCATED_PREFIX . ' content-length-mismatch (' . (int) ( is_array( $content_length ) ? end( $content_length ) : $content_length ) . ' declared, ' . $size . ' received)';
		}
		$head = '';
		$tail = '';
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only probe.
		if ( is_resource( $handle ) ) {
			$head = (string) fread( $handle, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( $size > self::TAIL_BYTES ) {
				fseek( $handle, -self::TAIL_BYTES, SEEK_END );
			} else {
				fseek( $handle, 0 );
			}
			$tail = (string) fread( $handle, self::TAIL_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$problem = self::structure_problem( $head, $tail, $size );
		if ( '' !== $problem ) {
			return self::TRUNCATED_PREFIX . ' ' . $problem;
		}

		$dims = self::dimensions( $path );
		if ( empty( $dims ) ) {
			return ''; /* Not a raster WordPress understands: its MIME check decides. */
		}
		$memory_limit = null === $memory_limit ? ( class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::memory_limit_bytes() : 0 ) : (int) $memory_limit;
		$headroom     = null === $headroom ? ( class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::memory_headroom_bytes() : PHP_INT_MAX ) : (int) $headroom;
		$budget       = self::decode_budget( $dims[0], $dims[1], $memory_limit, $headroom );
		if ( 'too_large' === $budget ) {
			return self::TOO_LARGE_PREFIX . ' ' . (int) $dims[0] . 'x' . (int) $dims[1];
		}
		if ( 'headroom' === $budget ) {
			return self::HEADROOM_PREFIX . ' ' . (int) $dims[0] . 'x' . (int) $dims[1];
		}
		return '';
	}

	/**
	 * Header-only dimensions (no bitmap decode).
	 *
	 * @param string $path File.
	 * @return int[] [width, height] or empty.
	 */
	public static function dimensions( $path ) {
		$can = class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::can( 'getimagesize' ) : function_exists( 'getimagesize' );
		if ( ! $can ) {
			return array();
		}
		$info = @getimagesize( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt input is expected.
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return array();
		}
		return array( (int) $info[0], (int) $info[1] );
	}
}
