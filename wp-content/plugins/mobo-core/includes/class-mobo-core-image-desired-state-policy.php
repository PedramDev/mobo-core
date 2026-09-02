<?php
/**
 * Shared authoritative image desired-state validation policy.
 *
 * Absent images preserve existing state. Present [] is valid authoritative empty.
 * Present non-array, malformed rows, duplicate identities, or rows without a safe
 * HTTP(S) source fail closed before any partial image mutation is accepted.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Image_Desired_State_Policy {

	/**
	 * Inspect the images field without collapsing absent/null/empty.
	 *
	 * @param mixed $payload Product payload.
	 * @return array
	 */
	public static function inspect_field( $payload ) {
		return Mobo_Core_Payload_Field_Policy::inspect( $payload, Mobo_Core_Payload_Field_Policy::image_aliases() );
	}

	/**
	 * Validate one authoritative image collection.
	 *
	 * @param mixed $images Images value.
	 * @return true|WP_Error
	 */
	public static function validate_collection( $images ) {
		if ( ! is_array( $images ) ) {
			return new WP_Error( 'mobo_core_product_images_invalid', 'Mobo product images must be an array when the field is present.' );
		}

		$seen = array();
		foreach ( $images as $image ) {
			if ( ! is_array( $image ) ) {
				return new WP_Error( 'mobo_core_product_image_row_invalid', 'Mobo product contains a malformed image row.' );
			}

			$image_guid = self::image_guid( $image );
			$url        = self::image_url( $image );
			if ( '' === $image_guid || '' === $url ) {
				return new WP_Error( 'mobo_core_product_image_row_invalid', 'Mobo product image row is missing a stable image identity or HTTP(S) source URL.' );
			}

			$key = strtolower( $image_guid );
			if ( isset( $seen[ $key ] ) ) {
				return new WP_Error( 'mobo_core_product_image_duplicate', 'Mobo product image desired state contains a duplicate image identity.' );
			}
			$seen[ $key ] = true;
		}

		return true;
	}

	/**
	 * Extract the first stable image identity.
	 *
	 * @param array $image Image row.
	 * @return string
	 */
	public static function image_guid( $image ) {
		if ( ! is_array( $image ) ) {
			return '';
		}
		foreach ( array( 'image_guid', 'img_guid', 'imageGuid', 'imageId', 'guid', 'remote_guid', 'remoteGuid', 'id' ) as $key ) {
			if ( ! array_key_exists( $key, $image ) ) {
				$pascal = ucfirst( $key );
				if ( ! array_key_exists( $pascal, $image ) ) {
					continue;
				}
				$value = $image[ $pascal ];
			} else {
				$value = $image[ $key ];
			}
			if ( Mobo_Core_Remote_Identity_Policy::is_valid( $value ) ) {
				return trim( sanitize_text_field( (string) $value ) );
			}
		}
		return '';
	}

	/**
	 * Extract a safe HTTP(S) image source URL.
	 *
	 * @param array $image Image row.
	 * @return string
	 */
	public static function image_url( $image ) {
		if ( ! is_array( $image ) ) {
			return '';
		}
		foreach ( array( 'url', 'src' ) as $key ) {
			$value = array_key_exists( $key, $image ) ? $image[ $key ] : ( array_key_exists( ucfirst( $key ), $image ) ? $image[ ucfirst( $key ) ] : '' );
			if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
				continue;
			}
			$raw    = trim( (string) $value );
			$scheme = strtolower( (string) wp_parse_url( $raw, PHP_URL_SCHEME ) );
			/* esc_url_raw() may normalize a scheme-less token into an HTTP URL. Desired
			 * state must carry an explicit transport scheme, otherwise malformed input
			 * could be silently promoted into an actionable download URL. */
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}
			$url = esc_url_raw( $raw );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}
}
