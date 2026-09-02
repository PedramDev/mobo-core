<?php
/** Shared Mobo product ownership/classification policy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Product_Identity_Policy {
	/** @return array */
	public static function product_guid_meta_keys() {
		return array( 'product_guid' );
	}

	/** @return array */
	public static function variant_guid_meta_keys() {
		return array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' );
	}

	/** @return array */
	public static function portal_product_id_meta_keys() {
		return array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id' );
	}

	/** @return array */
	public static function portal_variant_id_meta_keys() {
		return array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
	}

	/** @return array */
	public static function product_identity_meta_keys() {
		return array_values( array_unique( array_merge( self::product_guid_meta_keys(), self::portal_product_id_meta_keys() ) ) );
	}

	/** @return array */
	public static function variation_identity_meta_keys() {
		return array_values( array_unique( array_merge( self::variant_guid_meta_keys(), self::portal_variant_id_meta_keys() ) ) );
	}

	/**
	 * Durable local identity meta keys that prove an object belongs to Mobo.
	 * Keep maintenance/repair ownership checks aligned with checkout/runtime
	 * classification so an alias cannot silently fall out of one path.
	 *
	 * @return array
	 */
	public static function identity_meta_keys() {
		return array_values( array_unique( array_merge( self::product_identity_meta_keys(), self::variation_identity_meta_keys() ) ) );
	}

	/** @return array */
	public static function numeric_identity_meta_keys() {
		return array_values( array_unique( array_merge( self::portal_product_id_meta_keys(), self::portal_variant_id_meta_keys() ) ) );
	}

	/**
	 * Whether one local product/variation object has durable Mobo identity.
	 *
	 * @param int $object_id WordPress post ID.
	 * @return bool
	 */
	public static function is_mobo_object_id( $object_id ) {
		$object_id = absint( $object_id );
		if ( $object_id <= 0 ) {
			return false;
		}
		$numeric_keys = self::numeric_identity_meta_keys();
		foreach ( self::identity_meta_keys() as $key ) {
			$value = get_post_meta( $object_id, $key, true );
			if ( in_array( $key, $numeric_keys, true ) ) {
				if ( absint( $value ) > 0 ) {
					return true;
				}
			} elseif ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect whether a WooCommerce line/product belongs to Mobo.
	 * Keep checkout, shipping and SMS classification on the same identity evidence.
	 *
	 * @param WC_Product|false $product Product.
	 * @param int              $product_id Product ID.
	 * @param int              $variation_id Variation ID.
	 * @return bool
	 */
	public static function is_mobo_product( $product, $product_id, $variation_id ) {
		$ids = array_filter( array( absint( $variation_id ), absint( $product_id ), $product instanceof WC_Product ? absint( $product->get_id() ) : 0 ) );
		foreach ( $ids as $id ) {
			if ( self::is_mobo_object_id( $id ) ) {
				return true;
			}
		}
		return false;
	}
}
