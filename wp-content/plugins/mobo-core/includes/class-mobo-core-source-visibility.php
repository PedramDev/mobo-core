<?php
/**
 * Source product status / visibility policy.
 *
 * Owner rules (Backend contract, additive fields on ProductUpdated / UpdateVariant):
 * - A product whose Source status is not published is never CREATED on this site.
 * - An existing product that becomes draft/hidden/deleted/absent on the Source is never
 *   trashed, deleted or set to draft (SEO). Its page/URL stay, but it is hidden from the
 *   catalog and search (catalog_visibility = hidden), shown out of stock and made not
 *   purchasable. When the Source publishes it again, the previous catalog visibility is
 *   restored; stock/price come back from the normal desired-state payload.
 * - Inactive variants are out of stock / not purchasable, never deleted for that reason.
 * - A payload WITHOUT these fields (10.33.67-era Backend) is "unknown": nothing changes.
 * - Nothing here is ever driven by an empty or failed pull: visibility only changes from an
 *   explicit field inside a concrete product payload.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Source_Visibility {

	const VISIBLE = 'visible';
	const HIDDEN  = 'hidden';
	const UNKNOWN = '';

	const META_HIDDEN          = '_mobo_source_hidden';
	const META_HIDDEN_AT       = '_mobo_source_hidden_at';
	const META_PREV_VISIBILITY = '_mobo_source_hidden_prev_visibility';
	const META_SOURCE_STATUS   = '_mobo_source_status';
	const META_INACTIVE        = '_mobo_source_inactive';

	/** @var bool */
	private static $initialized = false;

	/**
	 * Register storefront guards. Cheap: object meta is already loaded with the product.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 50, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter_purchasable' ), 50, 2 );
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'filter_in_stock' ), 50, 2 );
		add_filter( 'woocommerce_product_get_stock_status', array( __CLASS__, 'filter_stock_status' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_status', array( __CLASS__, 'filter_stock_status' ), 50, 2 );
	}

	/**
	 * Read a payload key in camelCase / PascalCase / snake_case.
	 *
	 * @param array  $data Payload.
	 * @param string $key camelCase key.
	 * @param bool   $present Out: whether the key exists.
	 * @return mixed
	 */
	private static function payload_value( $data, $key, &$present = false ) {
		$present = false;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$snake = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $key ) );
		foreach ( array( $key, ucfirst( $key ), $snake ) as $candidate ) {
			if ( array_key_exists( $candidate, $data ) ) {
				$present = true;
				return $data[ $candidate ];
			}
		}
		return null;
	}

	/**
	 * Normalize a Source status string.
	 *
	 * @param mixed $status Raw status.
	 * @return string published|draft|hidden|deleted|absent|inactive|'' (unknown).
	 */
	public static function normalize_status( $status ) {
		if ( ! is_string( $status ) ) {
			return '';
		}
		$status = strtolower( trim( $status ) );
		if ( in_array( $status, array( 'published', 'publish', 'active', 'visible' ), true ) ) {
			return 'published';
		}
		if ( in_array( $status, array( 'draft', 'hidden', 'deleted', 'absent', 'inactive' ), true ) ) {
			return $status;
		}
		return '';
	}

	/**
	 * Effective product visibility requested by a product payload.
	 *
	 * @param array $data Product payload.
	 * @return string visible|hidden|'' (unknown: field absent or unrecognized).
	 */
	public static function payload_visibility( $data ) {
		$present    = false;
		$visibility = self::payload_value( $data, 'visibility', $present );
		if ( $present && is_string( $visibility ) ) {
			$visibility = strtolower( trim( $visibility ) );
			if ( self::VISIBLE === $visibility || self::HIDDEN === $visibility ) {
				return $visibility;
			}
		}

		$status = self::normalize_status( self::payload_value( $data, 'sourceStatus', $present ) );
		if ( '' === $status ) {
			return self::UNKNOWN;
		}
		return 'published' === $status ? self::VISIBLE : self::HIDDEN;
	}

	/**
	 * Whether a product payload may CREATE a new local product.
	 * Unknown (legacy payload) keeps the historical behavior.
	 *
	 * @param array $data Product payload.
	 * @return bool
	 */
	public static function payload_allows_creation( $data ) {
		return self::HIDDEN !== self::payload_visibility( $data );
	}

	/**
	 * Parent visibility repeated on UpdateVariant pages.
	 *
	 * @param array $payload Variant page payload (already unwrapped).
	 * @return string visible|hidden|''.
	 */
	public static function variant_page_parent_visibility( $payload ) {
		$present    = false;
		$visibility = self::payload_value( $payload, 'productVisibility', $present );
		if ( $present && is_string( $visibility ) ) {
			$visibility = strtolower( trim( $visibility ) );
			if ( self::VISIBLE === $visibility || self::HIDDEN === $visibility ) {
				return $visibility;
			}
		}
		$status = self::normalize_status( self::payload_value( $payload, 'productSourceStatus', $present ) );
		if ( '' === $status ) {
			return self::UNKNOWN;
		}
		return 'published' === $status ? self::VISIBLE : self::HIDDEN;
	}

	/**
	 * Variant purchasability from a variant payload.
	 *
	 * @param array $data Variant payload.
	 * @return bool|null True/false when explicit, null when unknown.
	 */
	public static function payload_variant_purchasable( $data ) {
		$present     = false;
		$purchasable = self::payload_value( $data, 'purchasable', $present );
		if ( $present && is_bool( $purchasable ) ) {
			return $purchasable;
		}
		if ( $present && ( 0 === $purchasable || 1 === $purchasable || '0' === $purchasable || '1' === $purchasable ) ) {
			return 1 === (int) $purchasable;
		}
		$status = self::normalize_status( self::payload_value( $data, 'sourceStatus', $present ) );
		if ( '' === $status ) {
			return null;
		}
		return 'published' === $status;
	}

	/**
	 * Apply product visibility. Never trashes, deletes or changes post_status.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $visibility visible|hidden|''.
	 * @param string $source_status Optional raw source status for diagnostics.
	 * @return bool True when local state changed.
	 */
	public static function apply_to_product( $product_id, $visibility, $source_status = '' ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || self::UNKNOWN === $visibility || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$is_hidden = '1' === (string) get_post_meta( $product_id, self::META_HIDDEN, true );

		if ( self::HIDDEN === $visibility ) {
			if ( '' !== $source_status ) {
				update_post_meta( $product_id, self::META_SOURCE_STATUS, sanitize_key( $source_status ) );
			}
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return false;
			}
			$changed = false;
			if ( ! $is_hidden ) {
				$current = (string) $product->get_catalog_visibility( 'edit' );
				update_post_meta( $product_id, self::META_PREV_VISIBILITY, '' !== $current ? $current : 'visible' );
				update_post_meta( $product_id, self::META_HIDDEN_AT, gmdate( 'c' ) );
				update_post_meta( $product_id, self::META_HIDDEN, '1' );
				$changed = true;
			}
			if ( 'hidden' !== (string) $product->get_catalog_visibility( 'edit' ) ) {
				$product->set_catalog_visibility( 'hidden' );
				$product->save();
				$changed = true;
			}
			if ( $changed ) {
				self::log( 'info', 'Source product hidden on this site (kept published for SEO; out of stock, not purchasable, hidden from catalog/search).', array( 'productId' => $product_id, 'sourceStatus' => $source_status ) );
			}
			return $changed;
		}

		/* visible */
		delete_post_meta( $product_id, self::META_SOURCE_STATUS );
		if ( ! $is_hidden ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$previous = sanitize_key( (string) get_post_meta( $product_id, self::META_PREV_VISIBILITY, true ) );
		if ( ! in_array( $previous, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
			$previous = 'visible';
		}
		/* Restore only what Mobo changed: if the merchant edited the visibility while the
		 * product was hidden by the Source, keep the merchant's choice. */
		if ( 'hidden' === (string) $product->get_catalog_visibility( 'edit' ) && $previous !== 'hidden' ) {
			$product->set_catalog_visibility( $previous );
			$product->save();
		}
		delete_post_meta( $product_id, self::META_HIDDEN );
		delete_post_meta( $product_id, self::META_HIDDEN_AT );
		delete_post_meta( $product_id, self::META_PREV_VISIBILITY );
		self::log( 'info', 'Source product published again; catalog visibility restored.', array( 'productId' => $product_id, 'visibility' => $previous ) );
		return true;
	}

	/**
	 * Apply variant (or single-variant simple product) purchasability flag.
	 *
	 * @param int       $post_id Variation or simple product ID.
	 * @param bool|null $purchasable Null = unknown, nothing changes.
	 * @return bool
	 */
	public static function apply_inactive_flag( $post_id, $purchasable ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || null === $purchasable ) {
			return false;
		}
		$is_inactive = '1' === (string) get_post_meta( $post_id, self::META_INACTIVE, true );
		if ( false === $purchasable && ! $is_inactive ) {
			update_post_meta( $post_id, self::META_INACTIVE, '1' );
			return true;
		}
		if ( true === $purchasable && $is_inactive ) {
			delete_post_meta( $post_id, self::META_INACTIVE );
			return true;
		}
		return false;
	}

	/**
	 * Whether the Source currently blocks sales of this product/variation.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_blocked( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return false;
		}
		$id = absint( $product->get_id() );
		if ( $id <= 0 ) {
			return false;
		}
		if ( '1' === (string) $product->get_meta( self::META_HIDDEN, true, 'edit' ) || '1' === (string) $product->get_meta( self::META_INACTIVE, true, 'edit' ) ) {
			return true;
		}
		$parent_id = method_exists( $product, 'get_parent_id' ) ? absint( $product->get_parent_id( 'edit' ) ) : 0;
		return $parent_id > 0 && '1' === (string) get_post_meta( $parent_id, self::META_HIDDEN, true );
	}

	/** @return bool */
	public static function filter_purchasable( $purchasable, $product = null ) {
		return ( $purchasable && self::is_blocked( $product ) ) ? false : $purchasable;
	}

	/** @return bool */
	public static function filter_in_stock( $in_stock, $product = null ) {
		return ( $in_stock && self::is_blocked( $product ) ) ? false : $in_stock;
	}

	/** @return string */
	public static function filter_stock_status( $status, $product = null ) {
		return self::is_blocked( $product ) ? 'outofstock' : $status;
	}

	/**
	 * @param string $level Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function log( $level, $message, $context = array() ) {
		if ( ! class_exists( 'Mobo_Core_Logger' ) ) {
			return;
		}
		/* The logger exposes warning/error only; state transitions are logged as warnings
		 * so the operator can audit every Source-driven visibility change. */
		if ( 'error' === $level ) {
			Mobo_Core_Logger::error( $message, $context );
		} else {
			Mobo_Core_Logger::warning( $message, $context );
		}
	}
}
