<?php
/**
 * Durable "ever imported" product ledger.
 *
 * Unlike the live product map, ledger rows are append-only recovery evidence.
 * They are never removed when a WooCommerce product disappears or the source
 * no longer returns it. This lets a later recovery distinguish a previously
 * imported product from a product that has never belonged to this store.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Product_Ledger {

	/** @var bool|null */
	private static $table_exists_cache = null;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mobo_product_ledger';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_guid varchar(191) NOT NULL,
			last_wp_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			last_source varchar(32) NOT NULL DEFAULT 'sync',
			last_restored_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_guid (product_guid(150)),
			KEY last_wp_product_id (last_wp_product_id),
			KEY last_seen_at (last_seen_at)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
	}

	public static function table_exists() {
		global $wpdb;
		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}
		$table = self::table_name();
		self::$table_exists_cache = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (bool) self::$table_exists_cache;
	}

	public static function record( $product_guid, $wp_product_id = 0, $source = 'sync', $restored = false ) {
		global $wpdb;

		$product_guid  = sanitize_text_field( (string) $product_guid );
		$wp_product_id = absint( $wp_product_id );
		$source        = substr( sanitize_key( (string) $source ), 0, 32 );
		if ( '' === $product_guid || ! self::table_exists() ) {
			return false;
		}

		$now   = current_time( 'mysql', true );
		$table = self::table_name();
		$restored_value_sql = $restored ? '%s' : 'NULL';
		$sql   = "INSERT INTO {$table}
			(product_guid, last_wp_product_id, first_seen_at, last_seen_at, last_source, last_restored_at)
			VALUES (%s, %d, %s, %s, %s, {$restored_value_sql})
			ON DUPLICATE KEY UPDATE
				last_wp_product_id = IF(VALUES(last_wp_product_id) > 0, VALUES(last_wp_product_id), last_wp_product_id),
				last_seen_at = VALUES(last_seen_at),
				last_source = VALUES(last_source),
				last_restored_at = IF(VALUES(last_restored_at) IS NOT NULL, VALUES(last_restored_at), last_restored_at)";

		$args = array( $product_guid, $wp_product_id, $now, $now, $source );
		if ( $restored ) {
			$args[] = $now;
		}
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		return false !== $result;
	}

	/**
	 * Read append-only recovery evidence in stable primary-key order.
	 *
	 * @param int $after_id Last processed ledger row ID.
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function get_after_id( $after_id = 0, $limit = 20 ) {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array();
		}

		$after_id = max( 0, absint( $after_id ) );
		$limit    = max( 1, min( 200, absint( $limit ) ) );
		$table    = self::table_name();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_guid, last_wp_product_id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public static function has( $product_guid ) {
		global $wpdb;
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( '' === $product_guid || ! self::table_exists() ) {
			return false;
		}
		$table = self::table_name();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE product_guid = %s LIMIT 1", $product_guid )
		);
	}

	/**
	 * Seed durable evidence from data that proves a parent product existed locally.
	 * Live product-map/postmeta rows and image queue rows are safe evidence. Merely
	 * receiving a webhook is intentionally not treated as confirmed local import.
	 */
	public static function seed_existing_evidence() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return array( 'success' => false, 'seeded' => 0 );
		}

		$table  = self::table_name();
		$now    = current_time( 'mysql', true );
		$seeded = 0;

		$insert_rows = function ( $select_sql, $source ) use ( $wpdb, $table, $now, &$seeded ) {
			$source = substr( sanitize_key( (string) $source ), 0, 32 );
			$sql = "INSERT IGNORE INTO {$table} (product_guid, last_wp_product_id, first_seen_at, last_seen_at, last_source)
				SELECT evidence.product_guid, evidence.wp_product_id, %s, %s, %s FROM ({$select_sql}) evidence
				WHERE evidence.product_guid <> ''";
			$result = $wpdb->query( $wpdb->prepare( $sql, $now, $now, $source ) );
			if ( false !== $result ) {
				$seeded += absint( $result );
			}
		};

		if ( class_exists( 'Mobo_Core_Product_Map' ) && Mobo_Core_Product_Map::table_exists() ) {
			$map = Mobo_Core_Product_Map::table_name();
			$insert_rows(
				"SELECT remote_guid AS product_guid, wp_post_id AS wp_product_id FROM {$map} WHERE object_type = 'product' AND remote_guid <> ''",
				'product-map'
			);
		}

		$insert_rows(
			"SELECT pm.meta_value AS product_guid, p.ID AS wp_product_id
			 FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'product' AND pm.meta_key = 'product_guid' AND pm.meta_value <> ''",
			'postmeta'
		);

		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$image_table = Mobo_Core_Image_Queue::table_name();
			$insert_rows(
				"SELECT DISTINCT product_guid, product_id AS wp_product_id FROM {$image_table} WHERE product_guid <> '' AND product_id > 0",
				'image-queue'
			);
		}

		/*
		 * A completed local ProductUpdated queue row is stronger evidence than a
		 * merely delivered remote webhook: the site's own worker finished handling
		 * that product identity. Keep this short-retention evidence before normal
		 * maintenance removes completed sync-event rows.
		 */
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$event_table = Mobo_Core_Sync_Event_Store::table_name();
			$insert_rows(
				"SELECT DISTINCT entity_guid AS product_guid, 0 AS wp_product_id
				 FROM {$event_table}
				 WHERE status = 'done' AND event_type = 'ProductUpdated'
				   AND entity_type = 'product' AND entity_guid <> ''",
				'sync-event'
			);
		}

		return array( 'success' => true, 'seeded' => $seeded );
	}
}
