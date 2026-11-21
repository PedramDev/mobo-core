<?php
/**
 * Intermediate image-size policy for Mobo-owned attachments.
 *
 * The Backend already serves resized WebP images. WordPress nevertheless generated
 * every registered intermediate size (often 8-15 files incl. theme/plugin sizes)
 * for each sideloaded Mobo image, which dominated image-import CPU and disk use on
 * shared hosts. During Mobo sideloads, and for already identified Mobo attachments
 * (so subsize scans/regeneration stay consistent), only the sizes WooCommerce
 * actually renders are generated.
 *
 * Configuration:
 * - option `mobo_core_image_subsizes`: comma-separated size names, or `all` to
 *   restore WordPress' default behaviour;
 * - filter `mobo_core_image_subsizes` (array|null; null = all sizes).
 *
 * Shared-media attachments (manifest based) are never touched.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Image_Size_Policy {

	const OPTION        = 'mobo_core_image_subsizes';
	const DEFAULT_SIZES = 'woocommerce_thumbnail,woocommerce_gallery_thumbnail,woocommerce_single,thumbnail';

	/** @var int Active Mobo sideload depth. */
	private static $depth = 0;

	/** @return void */
	public static function begin_sideload() {
		self::$depth++;
	}

	/** @return void */
	public static function end_sideload() {
		self::$depth = max( 0, self::$depth - 1 );
	}

	/** @return bool */
	public static function is_sideloading() {
		return self::$depth > 0;
	}

	/**
	 * Allowed size names, or null for "all registered sizes".
	 *
	 * @return array|null
	 */
	public static function allowed_sizes() {
		$raw = class_exists( 'Mobo_Core_Settings' )
			? Mobo_Core_Settings::get( self::OPTION, self::DEFAULT_SIZES )
			: ( function_exists( 'get_option' ) ? get_option( self::OPTION, self::DEFAULT_SIZES ) : self::DEFAULT_SIZES );
		$sizes = self::parse( $raw );
		if ( function_exists( 'apply_filters' ) ) {
			$sizes = apply_filters( 'mobo_core_image_subsizes', $sizes );
		}
		if ( null === $sizes || 'all' === $sizes ) {
			return null;
		}
		return is_array( $sizes ) ? array_values( array_filter( array_map( 'strval', $sizes ) ) ) : null;
	}

	/**
	 * Parse the stored option.
	 *
	 * @param mixed $raw Stored value.
	 * @return array|null
	 */
	public static function parse( $raw ) {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} else {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				$raw = self::DEFAULT_SIZES;
			}
			if ( 'all' === strtolower( $raw ) ) {
				return null;
			}
			$list = explode( ',', $raw );
		}
		$out = array();
		foreach ( $list as $name ) {
			$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );
			if ( '' !== $name ) {
				$out[ $name ] = true;
			}
		}
		return empty( $out ) ? null : array_keys( $out );
	}

	/**
	 * Pure restriction (unit tested). Falls back to the original list when none of
	 * the allowed sizes is registered, so a misconfiguration never drops all sizes.
	 *
	 * @param array      $sizes Registered size definitions keyed by name.
	 * @param array|null $allowed Allowed names.
	 * @return array
	 */
	public static function restrict( $sizes, $allowed ) {
		if ( ! is_array( $sizes ) || null === $allowed ) {
			return $sizes;
		}
		$kept = array_intersect_key( $sizes, array_flip( $allowed ) );
		return empty( $kept ) ? $sizes : $kept;
	}

	/**
	 * `intermediate_image_sizes_advanced` filter.
	 *
	 * @param array $sizes Size definitions.
	 * @param array $image_meta Image metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public static function filter_sizes( $sizes, $image_meta = array(), $attachment_id = 0 ) {
		unset( $image_meta );
		if ( ! is_array( $sizes ) || empty( $sizes ) ) {
			return $sizes;
		}
		if ( ! self::is_sideloading() && ! self::is_mobo_attachment( $attachment_id ) ) {
			return $sizes;
		}
		return self::restrict( $sizes, self::allowed_sizes() );
	}

	/**
	 * Mobo-imported (non shared-media) attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_mobo_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		if ( '' !== (string) get_post_meta( $attachment_id, '_mobo_shared_media_image_id', true ) ) {
			return false;
		}
		return '' !== (string) get_post_meta( $attachment_id, 'image_guid', true )
			|| '' !== (string) get_post_meta( $attachment_id, 'img_guid', true );
	}
}
