<?php
/**
 * Shared payload-field presence policy.
 *
 * A field being absent is different from an explicitly present null/empty value.
 * Collection callers can additionally distinguish a valid [] from malformed
 * scalar/object shapes before they mutate durable WooCommerce state.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Payload_Field_Policy {

	const ABSENT       = 'absent';
	const NULL_VALUE   = 'null';
	const EMPTY_ARRAY  = 'empty-array';
	const ARRAY_VALUE  = 'array';
	const EMPTY_SCALAR = 'empty-scalar';
	const SCALAR_VALUE = 'scalar';
	const OTHER_VALUE  = 'other';

	/**
	 * Canonical category desired-state aliases used by Product Sync and
	 * Recategorize recovery.
	 *
	 * @return array
	 */
	public static function category_aliases() {
		return array(
			'product_categories',
			'productCategories',
			'ProductCategories',
			'category_refs',
			'categoryRefs',
			'categories',
			'Categories',
			'category_guids',
			'categoryGuids',
			'CategoryGuids',
		);
	}

	/** @return array */
	public static function image_aliases() {
		return array( 'images', 'Images' );
	}

	/** @return array */
	public static function attribute_aliases() {
		return array( 'attributes', 'Attributes' );
	}

	/** @return array */
	public static function price_aliases() {
		return array( 'price', 'Price' );
	}

	/** @return array */
	public static function compare_price_aliases() {
		return array( 'comparePrice', 'ComparePrice', 'compare_price' );
	}

	/** @return array */
	public static function stock_aliases() {
		return array(
			'stock',
			'Stock',
			'stock_quantity',
			'stockQuantity',
			'StockQuantity',
			'quantity',
			'Quantity',
			'inventory',
			'Inventory',
			'inventoryQuantity',
			'InventoryQuantity',
		);
	}

	/**
	 * Find the first explicitly present alias without collapsing null/empty.
	 *
	 * @param mixed $payload Payload.
	 * @param array $aliases Accepted keys in priority order.
	 * @return array present,key,value,state
	 */
	public static function inspect( $payload, $aliases ) {
		if ( ! is_array( $payload ) || ! is_array( $aliases ) ) {
			return self::absent_result();
		}

		foreach ( $aliases as $alias ) {
			$alias = (string) $alias;
			if ( '' === $alias || ! array_key_exists( $alias, $payload ) ) {
				continue;
			}

			$value = $payload[ $alias ];
			return array(
				'present' => true,
				'key'     => $alias,
				'value'   => $value,
				'state'   => self::classify_value( $value ),
			);
		}

		return self::absent_result();
	}

	/**
	 * Whether any alias is explicitly present.
	 *
	 * @param mixed $payload Payload.
	 * @param array $aliases Aliases.
	 * @return bool
	 */
	public static function is_present( $payload, $aliases ) {
		$inspection = self::inspect( $payload, $aliases );
		return ! empty( $inspection['present'] );
	}

	/**
	 * Return the exact value of the first present alias, or default when absent.
	 *
	 * @param mixed $payload Payload.
	 * @param array $aliases Aliases.
	 * @param mixed $default Default.
	 * @return mixed
	 */
	public static function value( $payload, $aliases, $default = null ) {
		$inspection = self::inspect( $payload, $aliases );
		return ! empty( $inspection['present'] ) ? $inspection['value'] : $default;
	}

	/**
	 * Collection fields are valid only when present as arrays. [] is deliberately
	 * valid authoritative empty; scalar/null/object values are malformed.
	 *
	 * @param mixed $payload Payload.
	 * @param array $aliases Aliases.
	 * @return bool
	 */
	public static function is_valid_collection_when_present( $payload, $aliases ) {
		$inspection = self::inspect( $payload, $aliases );
		return empty( $inspection['present'] ) || is_array( $inspection['value'] );
	}

	/** @return array */
	private static function absent_result() {
		return array(
			'present' => false,
			'key'     => '',
			'value'   => null,
			'state'   => self::ABSENT,
		);
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function classify_value( $value ) {
		if ( null === $value ) {
			return self::NULL_VALUE;
		}
		if ( is_array( $value ) ) {
			return empty( $value ) ? self::EMPTY_ARRAY : self::ARRAY_VALUE;
		}
		if ( is_scalar( $value ) ) {
			return '' === trim( (string) $value ) ? self::EMPTY_SCALAR : self::SCALAR_VALUE;
		}
		return self::OTHER_VALUE;
	}
}
