<?php
/**
 * Crash-safe Mobo product creation (provisional identity marker).
 *
 * WooCommerce creates a product with wp_insert_post() (published) and only
 * later writes its metadata; Mobo binds the GUID after that. A PHP kill or DB
 * restart in between used to leave a published product with no identity: the
 * next sync could not find it and created a duplicate whose orders were never
 * sent to the Source.
 *
 * Now every Mobo product insert carries a provisional marker written by
 * wp_insert_post() itself (meta_input, before any save hook runs). The next sync
 * of the same GUID adopts the marked product instead of creating a new one, and a
 * bounded orphan scan binds marked products that never got an identity. Merchant
 * products are never deleted.
 *
 * The meta key is deliberately OUTSIDE the managed `_mobo_` / `mobo_` prefixes,
 * so it always stays in wp_postmeta (not diverted to Custom entity storage) and
 * can be found with a plain indexed meta_key query.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Product_Creation_Intent {

	/** Provisional identity marker (value: "v1|{connectionId}|{productGuid}"). */
	const META_KEY = '_mbcore_creation_intent';

	/** Marker value after the orphan scan found another product already owning the GUID. */
	const DUPLICATE_PREFIX = 'dup|';

	/** Hard bound of one orphan scan. */
	const SCAN_LIMIT = 20;

	/**
	 * Marker value for one Connection + GUID.
	 *
	 * @param int    $connection_id Connection id.
	 * @param string $product_guid  Source product GUID.
	 * @return string '' when either part is missing.
	 */
	public static function marker_value( $connection_id, $product_guid ) {
		$connection_id = absint( $connection_id );
		$product_guid  = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || '' === $product_guid ) {
			return '';
		}
		return 'v1|' . $connection_id . '|' . $product_guid;
	}

	/**
	 * Parse a marker value.
	 *
	 * @param string $value Marker value.
	 * @return array{connectionId:int,productGuid:string}|null
	 */
	public static function parse_marker( $value ) {
		$parts = explode( '|', (string) $value, 3 );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] || absint( $parts[1] ) <= 0 || '' === trim( $parts[2] ) ) {
			return null;
		}
		return array( 'connectionId' => absint( $parts[1] ), 'productGuid' => (string) $parts[2] );
	}

	/**
	 * Add the marker to wp_insert_post() args (woocommerce_new_product_data).
	 *
	 * @param array  $args   Insert args.
	 * @param string $marker Marker value.
	 * @return array
	 */
	public static function add_marker_to_insert_args( $args, $marker ) {
		if ( ! is_array( $args ) || '' === (string) $marker ) {
			return $args;
		}
		$meta = isset( $args['meta_input'] ) && is_array( $args['meta_input'] ) ? $args['meta_input'] : array();
		$meta[ self::META_KEY ] = (string) $marker;
		$args['meta_input'] = $meta;
		return $args;
	}

	/**
	 * Run a NEW product's first save with the marker written by wp_insert_post().
	 *
	 * @param int      $connection_id Connection id.
	 * @param string   $product_guid  GUID.
	 * @param callable $save          Performs $product->save(); returns the id.
	 * @return mixed Result of $save.
	 */
	public static function save_with_marker( $connection_id, $product_guid, $save ) {
		$marker = self::marker_value( $connection_id, $product_guid );
		if ( '' === $marker || ! function_exists( 'add_filter' ) ) {
			return call_user_func( $save );
		}
		$filter = static function ( $args ) use ( $marker ) {
			return self::add_marker_to_insert_args( $args, $marker );
		};
		add_filter( 'woocommerce_new_product_data', $filter, PHP_INT_MAX );
		try {
			return call_user_func( $save );
		} finally {
			remove_filter( 'woocommerce_new_product_data', $filter, PHP_INT_MAX );
		}
	}

	/**
	 * Find a previously created (crashed) product carrying this GUID's marker.
	 * Trashed products are never adopted (merchant decision is respected).
	 *
	 * @param int    $connection_id Connection id.
	 * @param string $product_guid  GUID.
	 * @return int Lowest matching product id or 0.
	 */
	public static function find_adoptable( $connection_id, $product_guid ) {
		global $wpdb;
		$marker = self::marker_value( $connection_id, $product_guid );
		if ( '' === $marker || ! isset( $wpdb ) ) {
			return 0;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') ORDER BY pm.post_id ASC LIMIT 1",
				self::META_KEY,
				$marker
			)
		);
		return absint( $id );
	}

	/**
	 * Bounded list of marked products of one Connection (orphan candidates).
	 *
	 * @param int $connection_id Connection id.
	 * @param int $limit         Max rows.
	 * @return array<int,array{postId:int,productGuid:string}>
	 */
	public static function list_marked( $connection_id, $limit = self::SCAN_LIMIT ) {
		global $wpdb;
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || ! isset( $wpdb ) ) {
			return array();
		}
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') ORDER BY pm.post_id ASC LIMIT %d",
				self::META_KEY,
				$wpdb->esc_like( 'v1|' . $connection_id . '|' ) . '%',
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$parsed = self::parse_marker( isset( $row['meta_value'] ) ? $row['meta_value'] : '' );
			if ( null === $parsed || $parsed['connectionId'] !== $connection_id ) {
				continue;
			}
			$out[] = array( 'postId' => absint( $row['post_id'] ), 'productGuid' => $parsed['productGuid'] );
		}
		return $out;
	}

	/**
	 * Decide what the orphan scan does with one marked product (pure).
	 *
	 * @param int $post_id  Marked product id.
	 * @param int $bound_id Product currently bound to the marker's GUID (0 = none).
	 * @return string clear|adopt|duplicate
	 */
	public static function orphan_action( $post_id, $bound_id ) {
		$post_id  = absint( $post_id );
		$bound_id = absint( $bound_id );
		if ( $bound_id === $post_id ) {
			return 'clear';
		}
		return $bound_id > 0 ? 'duplicate' : 'adopt';
	}

	/**
	 * Remove the marker after the identity is durably bound.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public static function clear( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id > 0 && function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( $product_id, self::META_KEY );
		}
	}

	/**
	 * Park a marked product whose GUID is already owned by another product: the
	 * scan never picks it again, and it is never deleted (the merchant decides).
	 *
	 * @param int    $product_id Product id.
	 * @param string $marker     Original marker value.
	 * @return void
	 */
	public static function park_duplicate( $product_id, $marker ) {
		$product_id = absint( $product_id );
		if ( $product_id > 0 && function_exists( 'update_post_meta' ) ) {
			update_post_meta( $product_id, self::META_KEY, self::DUPLICATE_PREFIX . (string) $marker );
		}
	}

	/**
	 * M11: a quarantined (marked) legacy product whose legacy product_guid
	 * postmeta row equals this GUID. After the identity fence those rows are
	 * hidden from get_post_meta(), so lookups missed them and sync created a
	 * duplicate. Only the Default Connection owns legacy identity rows.
	 *
	 * @param string $product_guid  GUID.
	 * @param int    $connection_id Current Connection id.
	 * @return int Lowest matching marked product id (several = pre-existing duplicates; one is adopted, none is created), or 0.
	 */
	public static function find_quarantined_legacy_product( $product_guid, $connection_id ) {
		global $wpdb;
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( '' === $product_guid || ! isset( $wpdb ) || ! class_exists( 'Mobo_Core_Migration_Quarantine' ) ) {
			return 0;
		}
		$default = absint( Mobo_Core_Migration_Quarantine::default_connection_id() );
		if ( $default > 0 && absint( $connection_id ) !== $default ) {
			return 0;
		}
		$marked = array();
		foreach ( Mobo_Core_Migration_Quarantine::all() as $post_id => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['t'] ) && '' !== (string) $entry['t'] && 'product' !== (string) $entry['t'] ) ) {
				continue;
			}
			$marked[] = absint( $post_id );
		}
		$marked = array_values( array_filter( $marked ) );
		if ( empty( $marked ) ) {
			return 0;
		}
		$marked       = array_slice( $marked, 0, 1000 );
		$placeholders = implode( ',', array_fill( 0, count( $marked ), '%d' ) );
		$args         = array_merge( array( $product_guid ), $marked );
		$id           = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'product_guid' AND pm.meta_value = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND pm.post_id IN ({$placeholders}) ORDER BY pm.post_id ASC LIMIT 1",
				$args
			)
		);
		return absint( $id );
	}
}
