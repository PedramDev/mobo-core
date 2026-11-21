<?php
/**
 * Durable WooCommerce product-type transition policy.
 *
 * Product type is owned by WooCommerce CRUD. Mobo must not pre-write the
 * product_type taxonomy before WC_Product::save(), because doing so hides the
 * old type from WooCommerce and suppresses its product-type transition hook.
 *
 * This policy performs the typed CRUD save, refreshes the WordPress/WooCommerce
 * read boundary, and verifies raw taxonomy, factory resolution and the freshly
 * loaded product class before a caller may acknowledge convergence.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Product_Type_Policy {

	/**
	 * Convert a product to Variable through WooCommerce CRUD and verify durability.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product_Variable|WP_Error
	 */
	public static function to_variable( $product_id ) {
		return self::transition( $product_id, 'variable', false );
	}

	/**
	 * Convert a product to Simple while preserving existing variation posts.
	 *
	 * Variation ownership/quarantine is deliberately outside this policy. The
	 * caller must preflight/retire Mobo-owned children first. The scoped WooCommerce
	 * filter only prevents the type-change hook from hard-deleting the remaining
	 * quarantined/manual children during the typed CRUD save.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product_Simple|WP_Error
	 */
	public static function to_simple_preserving_variations( $product_id ) {
		return self::transition( $product_id, 'simple', true );
	}

	/**
	 * Execute one canonical WooCommerce product-type transition.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $target_type simple|variable.
	 * @param bool   $preserve_variations Preserve child posts on Variable->Simple.
	 * @return WC_Product|WP_Error
	 */
	private static function transition( $product_id, $target_type, $preserve_variations ) {
		$product_id  = absint( $product_id );
		$target_type = sanitize_key( (string) $target_type );

		if ( $product_id <= 0 || ! in_array( $target_type, array( 'simple', 'variable' ), true ) ) {
			return new WP_Error( 'mobo_core_product_type_transition_invalid', 'WooCommerce product type transition request is invalid.' );
		}
		if ( ! class_exists( 'WC_Product_Factory' ) || ! class_exists( 'WC_Product_Simple' ) || ! class_exists( 'WC_Product_Variable' ) ) {
			return new WP_Error( 'mobo_core_product_type_transition_unavailable', 'WooCommerce product type services are unavailable.' );
		}

		self::refresh_read_boundary( $product_id );
		$before_type = sanitize_key( (string) WC_Product_Factory::get_product_type( $product_id ) );

		/* If storage already advertises the target type, still prove all three read
		 * boundaries. This self-heals a stale request-local product instance without
		 * performing an unnecessary second save. */
		if ( $before_type === $target_type ) {
			$verified = self::verify_transition( $product_id, $target_type, $before_type, $product_id );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			return $verified;
		}

		$preserve_filter = null;
		if ( $preserve_variations && 'variable' === $before_type && 'simple' === $target_type ) {
			$preserve_filter = static function( $delete_variations, $candidate, $from, $to ) use ( $product_id ) {
				if ( $candidate instanceof WC_Product
					&& absint( $candidate->get_id() ) === $product_id
					&& 'variable' === (string) $from
					&& 'simple' === (string) $to
				) {
					return false;
				}
				return $delete_variations;
			};
			add_filter( 'woocommerce_delete_variations_on_product_type_change', $preserve_filter, PHP_INT_MAX, 4 );
		}

		$saved_id = 0;
		try {
			$typed    = 'variable' === $target_type ? new WC_Product_Variable( $product_id ) : new WC_Product_Simple( $product_id );
			$saved_id = absint( $typed->save() );
		} catch ( Throwable $e ) {
			$code = 'variable' === $target_type ? 'mobo_core_variable_product_save_exception' : 'mobo_core_simple_product_save_exception';
			return new WP_Error(
				$code,
				'WooCommerce ' . $target_type . ' product conversion threw an exception: ' . $e->getMessage(),
				array(
					'productId'  => $product_id,
					'beforeType' => $before_type,
					'targetType' => $target_type,
				)
			);
		} finally {
			if ( null !== $preserve_filter ) {
				remove_filter( 'woocommerce_delete_variations_on_product_type_change', $preserve_filter, PHP_INT_MAX );
			}
		}

		self::refresh_read_boundary( $product_id );
		return self::verify_transition( $product_id, $target_type, $before_type, $saved_id );
	}

	/**
	 * Verify raw taxonomy, WooCommerce factory type and fresh product class.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $target_type Target type.
	 * @param string $before_type Type before mutation.
	 * @param int    $saved_id CRUD save return value.
	 * @return WC_Product|WP_Error
	 */
	private static function verify_transition( $product_id, $target_type, $before_type, $saved_id ) {
		$raw_types = wp_get_object_terms( $product_id, 'product_type', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $raw_types ) ) {
			return new WP_Error(
				'mobo_core_product_type_readback_failed',
				'WooCommerce product type taxonomy could not be read back after conversion: ' . $raw_types->get_error_message(),
				array( 'productId' => $product_id, 'beforeType' => $before_type, 'targetType' => $target_type, 'savedId' => absint( $saved_id ) )
			);
		}

		$raw_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $raw_types ) ? $raw_types : array() ) ) ) );
		sort( $raw_types, SORT_STRING );
		$factory_type = sanitize_key( (string) WC_Product_Factory::get_product_type( $product_id ) );
		$fresh        = wc_get_product( $product_id );
		$class_ok     = 'variable' === $target_type
			? $fresh instanceof WC_Product_Variable
			: ( $fresh instanceof WC_Product_Simple && ! $fresh instanceof WC_Product_Variable );

		if ( absint( $saved_id ) !== $product_id
			|| array( $target_type ) !== $raw_types
			|| $factory_type !== $target_type
			|| ! $class_ok
		) {
			$code = 'variable' === $target_type ? 'mobo_core_variable_product_save_failed' : 'mobo_core_simple_product_save_failed';
			$message = 'variable' === $target_type
				? 'WooCommerce variable product could not be durably saved after type conversion.'
				: 'WooCommerce simple product conversion did not persist successfully.';
			return new WP_Error(
				$code,
				$message,
				array(
					'productId'   => $product_id,
					'beforeType'  => $before_type,
					'targetType'  => $target_type,
					'savedId'     => absint( $saved_id ),
					'rawTypes'    => $raw_types,
					'factoryType' => $factory_type,
					'freshClass'  => is_object( $fresh ) ? get_class( $fresh ) : '',
				)
			);
		}

		return $fresh;
	}

	/**
	 * Clear the supported WordPress/WooCommerce read boundary before verification.
	 *
	 * WooCommerce 10.5+ may keep WC_Product instances in a request-local cache.
	 * clean_post_cache() participates in its public invalidation hook, while the
	 * term/cache-group invalidation below protects older WooCommerce versions too.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private static function refresh_read_boundary( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}

		clean_object_term_cache( $product_id, 'product' );
		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		if ( class_exists( 'WC_Cache_Helper' ) && is_callable( array( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
		}
	}
}
