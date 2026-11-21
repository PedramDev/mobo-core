<?php
/**
 * In-flight crash marker for product-delta-v1 writes (M5).
 *
 * A delta's phase 2 saves several WooCommerce objects one after another. A PHP
 * kill in the middle left a half-applied product with no marker (the incomplete
 * flag was only set in the catch block). Now the marker is written before the
 * first write and removed after the last; a leftover marker makes the next delta
 * on that product ask for a full snapshot, and a bounded sweep marks products
 * whose marker outlived any product lock as incomplete + requests a snapshot.
 *
 * The key is deliberately outside the managed `_mobo_` / `mobo_` prefixes so it
 * stays a plain wp_postmeta row that the sweep can find by meta_key.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Delta_Inflight {

	/** Marker meta key (value "{revision}|{unixTime}|{productGuid}"). */
	const META_KEY = '_mbcore_delta_inflight';

	/** A marker older than this cannot belong to a live writer (product lock TTL is 120s). */
	const STALE_AFTER_SECONDS = 300;

	/** Sweep bound per request. */
	const SWEEP_LIMIT = 20;

	/**
	 * Build a marker value.
	 *
	 * @param string $guid     Product GUID.
	 * @param int    $revision Delta revision.
	 * @param int    $now      Unix time.
	 * @return string
	 */
	public static function value( $guid, $revision, $now ) {
		return absint( $revision ) . '|' . absint( $now ) . '|' . sanitize_text_field( (string) $guid );
	}

	/**
	 * Parse a marker value.
	 *
	 * @param string $value Raw value.
	 * @return array{revision:int,at:int,productGuid:string}|null
	 */
	public static function parse( $value ) {
		$parts = explode( '|', (string) $value, 3 );
		if ( 3 !== count( $parts ) || absint( $parts[1] ) <= 0 || '' === trim( $parts[2] ) ) {
			return null;
		}
		return array( 'revision' => absint( $parts[0] ), 'at' => absint( $parts[1] ), 'productGuid' => (string) $parts[2] );
	}

	/**
	 * Write the marker before the first delta write.
	 *
	 * @param int    $product_id Product id.
	 * @param string $guid       Product GUID.
	 * @param int    $revision   Delta revision.
	 * @return bool
	 */
	public static function mark( $product_id, $guid, $revision ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return false;
		}
		$value = self::value( $guid, $revision, time() );
		update_post_meta( $product_id, self::META_KEY, $value );
		return $value === (string) get_post_meta( $product_id, self::META_KEY, true );
	}

	/**
	 * Remove the marker after the last delta write (or once superseded).
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public static function clear( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id > 0 ) {
			delete_post_meta( $product_id, self::META_KEY );
		}
	}

	/**
	 * Whether a product carries a leftover marker.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function is_marked( $product_id ) {
		$product_id = absint( $product_id );
		return $product_id > 0 && '' !== (string) get_post_meta( $product_id, self::META_KEY, true );
	}

	/**
	 * Markers older than STALE_AFTER_SECONDS (bounded).
	 *
	 * @param int $now   Unix time.
	 * @param int $limit Max rows.
	 * @return array<int,array{productId:int,productGuid:string,revision:int}>
	 */
	public static function stale( $now, $limit = self::SWEEP_LIMIT ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product' ORDER BY pm.meta_id ASC LIMIT %d",
				self::META_KEY,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$parsed = self::parse( isset( $row['meta_value'] ) ? $row['meta_value'] : '' );
			if ( null === $parsed ) {
				$parsed = array( 'revision' => 0, 'at' => 0, 'productGuid' => '' ); // Malformed: just cleaned up.
			}
			if ( $parsed['at'] > (int) $now - self::STALE_AFTER_SECONDS ) {
				continue;
			}
			$out[] = array( 'productId' => absint( $row['post_id'] ), 'productGuid' => $parsed['productGuid'], 'revision' => $parsed['revision'] );
		}
		return $out;
	}
}
