<?php
/** Shared variation identity/signature policy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Variation_Identity_Policy {
	/**
	 * Build the deterministic Woo variation selection signature used by both
	 * ordinary Product Sync and Repair duplicate/identity detection.
	 *
	 * @param mixed $attributes Attribute map.
	 * @return string
	 */
	public static function attribute_signature( $attributes ) {
		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return '';
		}
		$normalized = array();
		foreach ( $attributes as $key => $value ) {
			$key   = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
			$value = sanitize_title( (string) $value );
			if ( '' !== $key && '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}
		ksort( $normalized );
		return empty( $normalized ) ? '' : md5( wp_json_encode( $normalized ) );
	}
}
