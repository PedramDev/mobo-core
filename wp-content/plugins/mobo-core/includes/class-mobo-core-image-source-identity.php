<?php
/**
 * Conservative "is this the same image?" policy.
 *
 * Previously any byte difference in the source URL of an already imported image
 * GUID (CDN host switch, signed/cache-busting query string, http->https) forced a
 * full re-download + sideload + thumbnail generation. The Backend now serves
 * content-hashed file names for changed images, so:
 *
 * 1. When both sides carry a content token (contentHash/hash/etag/contentToken),
 *    the token decides.
 * 2. Otherwise two URLs are the same image only when their path (directory and
 *    file name, case-sensitive, with an extension) is identical; scheme, host,
 *    port, query string and fragment are ignored.
 *
 * Anything else (different path or file name, missing extension, unparsable URL)
 * is treated as a new image.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Image_Source_Identity {

	/** Attachment meta key holding the last known content token. */
	const TOKEN_META = 'mobo_image_content_token';

	/**
	 * Canonical path form of a URL ('' when not comparable).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function canonical( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return '';
		}
		$scheme = strtolower( isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$path = '/' . ltrim( preg_replace( '#/+#', '/', (string) $parts['path'] ), '/' );
		$base = basename( $path );
		if ( '' === $base || false === strpos( $base, '.' ) || '.' === substr( $base, -1 ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Whether two source descriptions refer to the same image content.
	 *
	 * @param string $old_url Stored URL.
	 * @param string $new_url Incoming URL.
	 * @param string $old_token Stored content token.
	 * @param string $new_token Incoming content token.
	 * @return bool
	 */
	public static function same_source( $old_url, $new_url, $old_token = '', $new_token = '' ) {
		$old_token = self::normalize_token( $old_token );
		$new_token = self::normalize_token( $new_token );
		if ( '' !== $old_token && '' !== $new_token ) {
			return hash_equals( $old_token, $new_token );
		}
		$old_url = trim( (string) $old_url );
		$new_url = trim( (string) $new_url );
		if ( '' !== $old_url && $old_url === $new_url ) {
			return true;
		}
		$a = self::canonical( $old_url );
		return '' !== $a && $a === self::canonical( $new_url );
	}

	/**
	 * Content token carried by one image payload row ('' when absent).
	 *
	 * @param array $image Image payload row.
	 * @return string
	 */
	public static function content_token( $image ) {
		if ( ! is_array( $image ) ) {
			return '';
		}
		foreach ( array( 'contentHash', 'ContentHash', 'contentToken', 'ContentToken', 'hash', 'Hash', 'etag', 'ETag', 'eTag', 'Etag' ) as $key ) {
			if ( array_key_exists( $key, $image ) && is_scalar( $image[ $key ] ) ) {
				$token = self::normalize_token( $image[ $key ] );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}
		return '';
	}

	/**
	 * Stable form of an image collection for convergence hashing: every http(s)
	 * URL string is replaced by its canonical path so host/query-only changes no
	 * longer look like an image change. All other fields are preserved.
	 *
	 * @param mixed $value Images payload.
	 * @return mixed
	 */
	public static function canonicalize_for_hash( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::canonicalize_for_hash( $item );
			}
			return $out;
		}
		if ( is_string( $value ) && preg_match( '#^https?://#i', trim( $value ) ) ) {
			$canonical = self::canonical( $value );
			return '' !== $canonical ? 'mobo-path:' . $canonical : $value;
		}
		return $value;
	}

	/**
	 * @param mixed $token Raw token.
	 * @return string
	 */
	private static function normalize_token( $token ) {
		if ( ! is_scalar( $token ) ) {
			return '';
		}
		$token = trim( (string) $token, " \t\n\r\0\x0B\"" );
		if ( 0 === strpos( $token, 'W/' ) ) {
			$token = trim( substr( $token, 2 ), '"' );
		}
		return preg_match( '/^[A-Za-z0-9_\-:.+\/=]{4,128}$/', $token ) ? $token : '';
	}
}
