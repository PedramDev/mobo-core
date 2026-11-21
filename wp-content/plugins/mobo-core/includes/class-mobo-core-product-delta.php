<?php
/**
 * product-delta-v1 contract helpers (pure logic, unit tested).
 *
 * A `ProductUpdated` payload with `"delta": true` carries the product identifiers
 * (same id fields as a full payload), the revision, ONLY the changed basic fields
 * among price/compareAtPrice/stock/publishedAt/sourceStatus/visibility/purchasable,
 * and `variants` with ONLY the changed variants (identifiers + price,
 * compareAtPrice, stock, sourceStatus, purchasable).
 *
 * Rules:
 * - Only the fields present are applied; everything else is left untouched.
 * - Stale revisions are skipped exactly like full payloads.
 * - If the product or any referenced variant is unknown locally, or its local
 *   state is quarantined/inconsistent, nothing is guessed or applied: the caller
 *   answers `needFullSnapshot: true` so the Backend sends a full snapshot.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Product_Delta {

	const CAPABILITY = 'product-delta-v1';

	/** Field => accepted payload key aliases (camelCase first). */
	const PRODUCT_FIELD_ALIASES = array(
		'price'          => array( 'price', 'Price' ),
		'compareAtPrice' => array( 'compareAtPrice', 'CompareAtPrice', 'comparePrice', 'ComparePrice', 'compareAt', 'CompareAt' ),
		'stock'          => array( 'stock', 'Stock' ),
		'publishedAt'    => array( 'publishedAt', 'PublishedAt' ),
		'sourceStatus'   => array( 'sourceStatus', 'SourceStatus' ),
		'visibility'     => array( 'visibility', 'Visibility' ),
		'purchasable'    => array( 'purchasable', 'Purchasable' ),
	);

	/** @return array<string,array<int,string>> */
	public static function variant_field_aliases() {
		$aliases = self::PRODUCT_FIELD_ALIASES;
		unset( $aliases['publishedAt'], $aliases['visibility'] );
		return $aliases;
	}

	/**
	 * Whether a payload is a product-delta-v1 delta.
	 *
	 * @param mixed $payload Payload.
	 * @return bool
	 */
	public static function is_delta( $payload ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}
		if ( self::truthy( self::value( $payload, 'delta' ) ) ) {
			return true;
		}
		$item = self::product_item( $payload );
		return is_array( $item ) && self::truthy( self::value( $item, 'delta' ) );
	}

	/**
	 * The single product object of a delta payload.
	 * Accepts {data:[{...}]}, {data:{...}} or a flat product object.
	 *
	 * @param array $payload Payload.
	 * @return array|null
	 */
	public static function product_item( $payload ) {
		if ( ! is_array( $payload ) ) {
			return null;
		}
		$data = self::value( $payload, 'data' );
		if ( is_array( $data ) ) {
			if ( self::is_list( $data ) ) {
				return isset( $data[0] ) && is_array( $data[0] ) ? $data[0] : null;
			}
			return $data;
		}
		return $payload;
	}

	/**
	 * Remote product GUID (same id fields as a full payload).
	 *
	 * @param array $item Product object.
	 * @return string
	 */
	public static function product_guid( $item ) {
		return self::first_guid( $item, array( 'product_guid', 'productGuid', 'productId', 'parent_product_guid', 'parentProductId', 'parentGuid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entityId', 'id' ) );
	}

	/**
	 * Remote variant GUID (same id fields as a full payload).
	 *
	 * @param array $variant Variant object.
	 * @return string
	 */
	public static function variant_guid( $variant ) {
		return self::first_guid( $variant, array( 'variant_guid', 'variantGuid', 'variantId', 'guid', 'remote_guid', 'remoteGuid', 'entity_guid', 'entityGuid', 'entity_id', 'entityId', 'id' ) );
	}

	/**
	 * Changed variants of the delta.
	 *
	 * @param array $item Product object.
	 * @param array $payload Whole payload (fallback location).
	 * @return array|null List, or null when present but malformed.
	 */
	public static function variants( $item, $payload = array() ) {
		$variants = self::value( is_array( $item ) ? $item : array(), 'variants' );
		if ( null === $variants && is_array( $payload ) ) {
			$variants = self::value( $payload, 'variants' );
		}
		if ( null === $variants ) {
			return array();
		}
		if ( ! is_array( $variants ) || ! self::is_list( $variants ) ) {
			return null;
		}
		return $variants;
	}

	/**
	 * Delta revision (product level, falls back to the envelope).
	 *
	 * @param array $item Product object.
	 * @param array $payload Payload.
	 * @return int
	 */
	public static function revision( $item, $payload = array() ) {
		if ( class_exists( 'Mobo_Core_Ordering_Policy' ) ) {
			return absint( Mobo_Core_Ordering_Policy::extract_numeric_revision_from_sources( $item, $payload ) );
		}
		foreach ( array( $item, $payload ) as $source ) {
			foreach ( array( 'sourceRevision', 'revision' ) as $key ) {
				$value = is_array( $source ) ? self::value( $source, $key ) : null;
				if ( is_scalar( $value ) && ctype_digit( trim( (string) $value ) ) ) {
					return absint( $value );
				}
			}
		}
		return 0;
	}

	/**
	 * Raw subset of a product/variant object restricted to delta fields that are
	 * present. Original key spellings are preserved so existing field readers work.
	 *
	 * @param array $data Object.
	 * @param array $aliases Field alias map.
	 * @return array
	 */
	public static function present_fields( $data, $aliases ) {
		$out = array();
		if ( ! is_array( $data ) ) {
			return $out;
		}
		foreach ( $aliases as $field => $keys ) {
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$out[ $key ] = $data[ $key ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Logical field names present in a subset.
	 *
	 * @param array $subset Present fields.
	 * @param array $aliases Alias map.
	 * @return array<string,mixed> logical field => value
	 */
	public static function logical_fields( $subset, $aliases ) {
		$out = array();
		foreach ( $aliases as $field => $keys ) {
			foreach ( $keys as $key ) {
				if ( is_array( $subset ) && array_key_exists( $key, $subset ) ) {
					$out[ $field ] = $subset[ $key ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Structural validation.
	 *
	 * @param array $payload Payload.
	 * @return true|WP_Error
	 */
	public static function validate( $payload ) {
		$item = self::product_item( $payload );
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'mobo_core_delta_product_missing', 'Delta payload has no product object.' );
		}
		if ( '' === self::product_guid( $item ) ) {
			return new WP_Error( 'mobo_core_delta_product_guid_missing', 'Delta payload has no product identifier.' );
		}
		$variants = self::variants( $item, $payload );
		if ( null === $variants ) {
			return new WP_Error( 'mobo_core_delta_variants_invalid', 'Delta variants must be a list.' );
		}
		$seen = array();
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				return new WP_Error( 'mobo_core_delta_variant_invalid', 'Delta variant row must be an object.' );
			}
			$guid = self::variant_guid( $variant );
			if ( '' === $guid ) {
				return new WP_Error( 'mobo_core_delta_variant_guid_missing', 'Delta variant has no identifier.' );
			}
			$key = strtolower( $guid );
			if ( isset( $seen[ $key ] ) ) {
				return new WP_Error( 'mobo_core_delta_variant_duplicate', 'Delta contains the same variant twice.' );
			}
			$seen[ $key ] = true;
		}
		return true;
	}

	/**
	 * Decide whether a delta can be applied locally without guessing.
	 *
	 * The resolver must provide:
	 * - resolve_delta_product( $guid ): array{id:int,state:string,type?:string}
	 * - resolve_delta_variant( $guid, $product ): array{id:int,state:string,context?:string}
	 * where state is ok|missing|quarantined|inconsistent.
	 *
	 * @param array  $payload Payload.
	 * @param object $resolver Resolver.
	 * @return array{status:string,reason:string,productGuid:string,product:array,targets:array,revision:int}
	 */
	public static function precheck( $payload, $resolver ) {
		$result = array(
			'status'      => 'need_full',
			'reason'      => '',
			'productGuid' => '',
			'product'     => array(),
			'targets'     => array(),
			'revision'    => 0,
		);
		$valid = self::validate( $payload );
		if ( is_wp_error( $valid ) ) {
			$result['status'] = 'invalid';
			$result['reason'] = $valid->get_error_code();
			return $result;
		}
		$item = self::product_item( $payload );
		$result['productGuid'] = self::product_guid( $item );
		$result['revision']    = self::revision( $item, $payload );
		if ( $result['revision'] <= 0 ) {
			/* Without a revision the stale-ordering rule cannot be enforced. */
			$result['reason'] = 'revision_missing';
			return $result;
		}
		if ( ! is_object( $resolver ) || ! method_exists( $resolver, 'resolve_delta_product' ) || ! method_exists( $resolver, 'resolve_delta_variant' ) ) {
			$result['reason'] = 'resolver_unavailable';
			return $result;
		}
		$product = $resolver->resolve_delta_product( $result['productGuid'] );
		$product = is_array( $product ) ? $product : array();
		$state   = isset( $product['state'] ) ? (string) $product['state'] : 'missing';
		if ( 'ok' !== $state || absint( $product['id'] ?? 0 ) <= 0 ) {
			$result['reason'] = 'product_' . ( '' !== $state ? $state : 'missing' );
			return $result;
		}
		$result['product'] = $product;
		foreach ( self::variants( $item, $payload ) as $variant ) {
			$guid   = self::variant_guid( $variant );
			$target = $resolver->resolve_delta_variant( $guid, $product );
			$target = is_array( $target ) ? $target : array();
			$vstate = isset( $target['state'] ) ? (string) $target['state'] : 'missing';
			if ( 'ok' !== $vstate || absint( $target['id'] ?? 0 ) <= 0 ) {
				$result['reason']  = 'variant_' . ( '' !== $vstate ? $vstate : 'missing' );
				$result['targets'] = array();
				return $result;
			}
			$target['guid']    = $guid;
			$target['fields']  = self::present_fields( $variant, self::variant_field_aliases() );
			$target['context'] = isset( $target['context'] ) && 'product' === $target['context'] ? 'product' : 'variation';
			$variant_revision  = self::revision( $variant, array() );
			$target['revision'] = $variant_revision > 0 ? $variant_revision : $result['revision'];
			$result['targets'][] = $target;
		}
		$result['status'] = 'ok';
		return $result;
	}

	/** @return mixed */
	private static function value( $array, $key ) {
		if ( ! is_array( $array ) ) {
			return null;
		}
		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}
		$pascal = ucfirst( (string) $key );
		return array_key_exists( $pascal, $array ) ? $array[ $pascal ] : null;
	}

	/** @return bool */
	private static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		return is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true );
	}

	/** @return bool */
	private static function is_list( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}
		$i = 0;
		foreach ( array_keys( $array ) as $key ) {
			if ( $key !== $i ) {
				return false;
			}
			$i++;
		}
		return true;
	}

	/** @return string */
	private static function first_guid( $data, $keys ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( $keys as $key ) {
			$value = self::value( $data, $key );
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( (string) $value ) );
			$valid = class_exists( 'Mobo_Core_Remote_Identity_Policy' ) ? Mobo_Core_Remote_Identity_Policy::is_valid( $value ) : '' !== $value;
			if ( $valid ) {
				return $value;
			}
		}
		return '';
	}
}
