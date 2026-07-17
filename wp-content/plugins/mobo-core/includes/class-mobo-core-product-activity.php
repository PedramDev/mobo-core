<?php
/**
 * Track the latest product change made by Mobo runtime processes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Product_Activity {

	const META_CHANGED_AT = '_mobo_last_changed_at';
	const META_SOURCE     = '_mobo_last_changed_source';

	/**
	 * Record an exact Mobo-originated product change timestamp.
	 *
	 * Variations are normalized to their parent product so the WooCommerce
	 * products table always shows one consolidated timestamp.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $source Change source.
	 * @param int    $timestamp Optional Unix timestamp.
	 * @return bool
	 */
	public static function mark( $product_id, $source = 'product_sync', $timestamp = 0 ) {
		$product_id = self::normalize_product_id( $product_id );

		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		$timestamp = absint( $timestamp );
		if ( $timestamp <= 0 ) {
			$timestamp = time();
		}

		$source = sanitize_key( (string) $source );
		if ( '' === $source ) {
			$source = 'product_sync';
		}

		update_post_meta( $product_id, self::META_CHANGED_AT, $timestamp );
		update_post_meta( $product_id, self::META_SOURCE, $source );

		return true;
	}

	/**
	 * Return activity information for the products table.
	 *
	 * Older products do not have the exact activity meta. For those products a
	 * conservative legacy fallback is returned and marked as approximate.
	 *
	 * @param int $product_id Product ID.
	 * @return array{timestamp:int,source:string,exact:bool}
	 */
	public static function get( $product_id ) {
		$product_id = self::normalize_product_id( $product_id );

		if ( $product_id <= 0 ) {
			return array(
				'timestamp' => 0,
				'source'    => '',
				'exact'     => false,
			);
		}

		$timestamp = absint( get_post_meta( $product_id, self::META_CHANGED_AT, true ) );
		$source    = sanitize_key( (string) get_post_meta( $product_id, self::META_SOURCE, true ) );

		if ( $timestamp > 0 ) {
			return array(
				'timestamp' => $timestamp,
				'source'    => $source,
				'exact'     => true,
			);
		}

		$legacy = self::get_legacy_timestamp( $product_id );

		return array(
			'timestamp' => $legacy,
			'source'    => $legacy > 0 ? 'legacy' : '',
			'exact'     => false,
		);
	}

	/**
	 * Human-readable source label.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	public static function source_label( $source ) {
		$labels = array(
			'product_sync'    => 'همگام‌سازی محصول',
			'variant_sync'    => 'همگام‌سازی تنوع',
			'price_sync'      => 'بازمحاسبه قیمت',
			'category_sync'   => 'اعمال دسته‌بندی',
			'image_sync'      => 'همگام‌سازی تصویر',
			'image_refresh'   => 'نوسازی تصویر',
			'legacy'          => 'تاریخ تقریبی قدیمی',
		);

		$source = sanitize_key( (string) $source );
		return isset( $labels[ $source ] ) ? $labels[ $source ] : 'پردازش موبو';
	}

	/**
	 * Normalize variation IDs to the parent product ID.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return int
	 */
	private static function normalize_product_id( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$parent_id = absint( wp_get_post_parent_id( $product_id ) );
			if ( $parent_id > 0 ) {
				return $parent_id;
			}
		}

		return $product_id;
	}

	/**
	 * Best-effort timestamp for products created before exact activity tracking.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private static function get_legacy_timestamp( $product_id ) {
		$candidates = array();

		$integer_meta = array(
			'mobo_image_refresh_last_completed_at',
		);

		foreach ( $integer_meta as $meta_key ) {
			$value = absint( get_post_meta( $product_id, $meta_key, true ) );
			if ( $value > 0 ) {
				$candidates[] = $value;
			}
		}

		$date_meta = array(
			'_mobo_last_api_stock_applied_at',
			'mobo_price_policy_updated_at',
			'mobo_category_reapply_at',
			'_mobo_simple_variant_mapped_at',
			'_mobo_simple_variant_resolution_at',
		);

		foreach ( $date_meta as $meta_key ) {
			$raw = trim( (string) get_post_meta( $product_id, $meta_key, true ) );
			if ( '' === $raw ) {
				continue;
			}

			$value = strtotime( $raw );
			if ( false !== $value && $value > 0 ) {
				$candidates[] = $value;
			}
		}

		$post = get_post( $product_id );
		if ( $post instanceof WP_Post && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			$modified = strtotime( $post->post_modified_gmt . ' UTC' );
			if ( false !== $modified && $modified > 0 ) {
				$candidates[] = $modified;
			}
		}

		return empty( $candidates ) ? 0 : max( $candidates );
	}
}
