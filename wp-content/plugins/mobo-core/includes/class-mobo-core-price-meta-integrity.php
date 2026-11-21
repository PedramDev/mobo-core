<?php
/**
 * Durable singleton enforcement for WooCommerce price metadata.
 *
 * Callers must already hold the shared Mobo product lock for the product family.
 * This helper never calculates prices; it only collapses duplicate durable rows
 * to caller-provided authoritative values and verifies the exact postcondition.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class Mobo_Core_Price_Meta_Integrity {

	/**
	 * Collapse duplicate WooCommerce price rows to one verified canonical row.
	 *
	 * Missing/singleton rows are left untouched. Only keys explicitly present in
	 * $desired are eligible for mutation.
	 *
	 * @param int   $post_id Product/variation ID.
	 * @param array $desired Map of _price/_regular_price/_sale_price => value.
	 * @return array|WP_Error
	 */
	public static function collapse_duplicates_verified( $post_id, $desired ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! is_array( $desired ) ) {
			return new WP_Error( 'mobo_core_price_meta_integrity_invalid_input', 'Price metadata integrity input is invalid.' );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
			return new WP_Error(
				'mobo_core_price_meta_integrity_variable_parent_derived',
				'Variable parent price metadata is derived from visible variations and must not be collapsed as singleton metadata.'
			);
		}

		$allowed = array( '_price', '_regular_price', '_sale_price' );
		$changed = false;
		$removed = 0;

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $desired ) ) {
				continue;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value
					FROM {$wpdb->postmeta}
					WHERE post_id=%d AND meta_key=%s
					ORDER BY meta_id ASC",
					$post_id,
					$key
				),
				ARRAY_A
			);
			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
				return new WP_Error( 'mobo_core_price_meta_integrity_read_failed', 'Unable to read WooCommerce price metadata for object ' . $post_id . '.' );
			}

			if ( count( $rows ) <= 1 ) {
				continue;
			}

			$expected = class_exists( 'Mobo_Core_Durable_State_Policy' )
				? Mobo_Core_Durable_State_Policy::canonical_meta_value( $desired[ $key ] )
				: $desired[ $key ];

			$canonical_meta_id = absint( $rows[0]['meta_id'] ?? 0 );
			if ( $canonical_meta_id <= 0 ) {
				return new WP_Error( 'mobo_core_price_meta_integrity_identity_failed', 'Canonical WooCommerce price metadata row could not be identified for object ' . $post_id . '.' );
			}

			$updated = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => maybe_serialize( $expected ) ),
				array( 'meta_id' => $canonical_meta_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'mobo_core_price_meta_integrity_update_failed', 'Canonical WooCommerce price metadata could not be updated for object ' . $post_id . '.' );
			}

			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta}
					WHERE post_id=%d AND meta_key=%s AND meta_id<>%d",
					$post_id,
					$key,
					$canonical_meta_id
				)
			);
			if ( false === $deleted ) {
				return new WP_Error( 'mobo_core_price_meta_integrity_delete_failed', 'Duplicate WooCommerce price metadata could not be removed for object ' . $post_id . '.' );
			}

			$removed += max( 0, count( $rows ) - 1 );
			$changed = true;
			wp_cache_delete( $post_id, 'post_meta' );

			$stored = get_post_meta( $post_id, $key, false );
			if ( ! is_array( $stored ) || 1 !== count( $stored ) ) {
				return new WP_Error( 'mobo_core_price_meta_integrity_cardinality_failed', 'WooCommerce price metadata cardinality verification failed for object ' . $post_id . ' (' . $key . ').' );
			}
			$actual = class_exists( 'Mobo_Core_Durable_State_Policy' )
				? Mobo_Core_Durable_State_Policy::canonical_meta_value( $stored[0] )
				: $stored[0];
			if ( maybe_serialize( $actual ) !== maybe_serialize( $expected ) ) {
				return new WP_Error( 'mobo_core_price_meta_integrity_postcondition_failed', 'WooCommerce price metadata value verification failed for object ' . $post_id . ' (' . $key . ').' );
			}
		}

		return array(
			'changed' => $changed,
			'removed' => $removed,
		);
	}
}
