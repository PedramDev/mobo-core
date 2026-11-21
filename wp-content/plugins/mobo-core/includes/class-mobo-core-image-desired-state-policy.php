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

		$seen                 = array();
		$seen_positions       = array();
		$explicit_positions   = 0;
		$positioned_row_count = 0;

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

			$position_state = self::inspect_position( $image );
			if ( ! empty( $position_state['present'] ) ) {
				$explicit_positions++;
				if ( empty( $position_state['valid'] ) ) {
					return new WP_Error( 'mobo_core_product_image_position_invalid', 'Mobo product image position must be a non-negative integer.' );
				}

				$position = absint( $position_state['value'] );
				if ( isset( $seen_positions[ $position ] ) ) {
					return new WP_Error( 'mobo_core_product_image_position_duplicate', 'Mobo product image desired state contains duplicate image positions.' );
				}
				$seen_positions[ $position ] = true;
				$positioned_row_count++;
			}
		}

		if ( $explicit_positions > 0 ) {
			if ( $positioned_row_count !== count( $images ) ) {
				return new WP_Error( 'mobo_core_product_image_position_incomplete', 'Mobo product image desired state mixes positioned and unpositioned rows.' );
			}

			$expected_positions = range( 0, max( 0, count( $images ) - 1 ) );
			$actual_positions   = array_keys( $seen_positions );
			sort( $actual_positions, SORT_NUMERIC );
			if ( $actual_positions !== $expected_positions ) {
				return new WP_Error( 'mobo_core_product_image_position_gap', 'Mobo product image positions must be contiguous and start at zero.' );
			}
		}

		return true;
	}

	/**
	 * Return a collection ordered by explicit position when Portal provides one.
	 * Older Portal builds sent only array order; that order remains the fallback.
	 *
	 * @param array $images Images.
	 * @return array
	 */
	public static function ordered_collection( $images ) {
		if ( ! is_array( $images ) || empty( $images ) ) {
			return is_array( $images ) ? array_values( $images ) : array();
		}

		$rows           = array();
		$has_positions  = false;
		$all_positioned = true;

		foreach ( array_values( $images ) as $fallback_index => $image ) {
			$position_state = is_array( $image ) ? self::inspect_position( $image ) : array( 'present' => false, 'valid' => false, 'value' => null );
			$has_positions  = $has_positions || ! empty( $position_state['present'] );
			$all_positioned = $all_positioned && ! empty( $position_state['present'] ) && ! empty( $position_state['valid'] );

			$rows[] = array(
				'image'    => $image,
				'position' => ! empty( $position_state['present'] ) && ! empty( $position_state['valid'] )
					? absint( $position_state['value'] )
					: absint( $fallback_index ),
				'fallback' => absint( $fallback_index ),
			);
		}

		if ( $has_positions && $all_positioned ) {
			usort(
				$rows,
				static function ( $left, $right ) {
					$position_compare = absint( $left['position'] ) <=> absint( $right['position'] );
					return 0 !== $position_compare ? $position_compare : ( absint( $left['fallback'] ) <=> absint( $right['fallback'] ) );
				}
			);
		}

		return array_values( array_map( static function ( $row ) {
			return $row['image'];
		}, $rows ) );
	}

	/**
	 * Inspect explicit image position aliases without confusing "absent" with zero.
	 *
	 * @param array $image Image row.
	 * @return array{present:bool,valid:bool,value:int|null}
	 */
	private static function inspect_position( $image ) {
		if ( ! is_array( $image ) ) {
			return array( 'present' => false, 'valid' => false, 'value' => null );
		}

		foreach ( array( 'position', 'positionIndex', 'position_index', 'sortOrder', 'sort_order', 'order', 'index' ) as $key ) {
			$candidates = array( $key, ucfirst( $key ) );
			foreach ( $candidates as $candidate ) {
				if ( ! array_key_exists( $candidate, $image ) ) {
					continue;
				}

				$value = $image[ $candidate ];
				if ( is_int( $value ) && $value >= 0 ) {
					return array( 'present' => true, 'valid' => true, 'value' => $value );
				}
				if ( is_string( $value ) && preg_match( '/^\\d+$/', trim( $value ) ) ) {
					return array( 'present' => true, 'valid' => true, 'value' => absint( trim( $value ) ) );
				}
				return array( 'present' => true, 'valid' => false, 'value' => null );
			}
		}

		return array( 'present' => false, 'valid' => false, 'value' => null );
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
