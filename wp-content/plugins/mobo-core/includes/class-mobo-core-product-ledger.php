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

	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	private static function is_default_connection() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return false; }
		$default = Mobo_Core_Connection_Repository::get_default();
		return is_array( $default ) && absint( $default['id'] ?? 0 ) === self::connection_id();
	}

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
		$charset_collate = Mobo_Core_Database_Charset::schema_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_guid varchar(191) NOT NULL,
			last_wp_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			last_source varchar(32) NOT NULL DEFAULT 'sync',
			last_restored_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_guid (connection_id, product_guid(180)),
			KEY last_wp_product_id (connection_id, last_wp_product_id),
			KEY last_seen_at (connection_id, last_seen_at)
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
		$connection_id = self::connection_id();

		$product_guid  = sanitize_text_field( (string) $product_guid );
		$wp_product_id = absint( $wp_product_id );
		$source        = substr( sanitize_key( (string) $source ), 0, 32 );
		if ( $connection_id <= 0 || '' === $product_guid || ! self::table_exists() ) {
			return false;
		}

		$now   = current_time( 'mysql', true );
		$table = self::table_name();
		$restored_value_sql = $restored ? '%s' : 'NULL';
		$sql   = "INSERT INTO {$table}
			(connection_id, product_guid, last_wp_product_id, first_seen_at, last_seen_at, last_source, last_restored_at)
			VALUES (%d, %s, %d, %s, %s, %s, {$restored_value_sql})
			ON DUPLICATE KEY UPDATE
				last_wp_product_id = IF(connection_id=VALUES(connection_id) AND product_guid=VALUES(product_guid) AND VALUES(last_wp_product_id) > 0, VALUES(last_wp_product_id), last_wp_product_id),
				last_seen_at = IF(connection_id=VALUES(connection_id) AND product_guid=VALUES(product_guid), VALUES(last_seen_at), last_seen_at),
				last_source = IF(connection_id=VALUES(connection_id) AND product_guid=VALUES(product_guid), VALUES(last_source), last_source),
				last_restored_at = IF(connection_id=VALUES(connection_id) AND product_guid=VALUES(product_guid) AND VALUES(last_restored_at) IS NOT NULL, VALUES(last_restored_at), last_restored_at)";

		$args = array( $connection_id, $product_guid, $wp_product_id, $now, $now, $source );
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
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$after_id = max( 0, absint( $after_id ) );
		$limit    = max( 1, min( 200, absint( $limit ) ) );
		$table    = self::table_name();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_guid, last_wp_product_id FROM {$table} WHERE connection_id=%d AND id > %d ORDER BY id ASC LIMIT %d",
				$connection_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public static function has( $product_guid ) {
		global $wpdb;
		$connection_id = self::connection_id();
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || '' === $product_guid || ! self::table_exists() ) {
			return false;
		}
		$table = self::table_name();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE connection_id=%d AND product_guid = %s LIMIT 1", $connection_id, $product_guid )
		);
	}

	/**
	 * Seed durable evidence from data that proves a parent product existed locally.
	 * Live product-map/postmeta rows and image queue rows are safe evidence. Merely
	 * receiving a webhook is intentionally not treated as confirmed local import.
	 */
	public static function seed_existing_evidence() {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::table_exists() ) {
			return array( 'success' => false, 'seeded' => 0 );
		}

		$table  = self::table_name();
		$now    = current_time( 'mysql', true );
		$seeded = 0;

		$insert_rows = function ( $select_sql, $source ) use ( $wpdb, $table, $now, $connection_id, &$seeded ) {
			$source = substr( sanitize_key( (string) $source ), 0, 32 );
			$sql = "INSERT IGNORE INTO {$table} (connection_id, product_guid, last_wp_product_id, first_seen_at, last_seen_at, last_source)
				SELECT %d, evidence.product_guid, evidence.wp_product_id, %s, %s, %s FROM ({$select_sql}) evidence
				WHERE evidence.product_guid <> ''";
			$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, $now, $now, $source ) );
			if ( false !== $result ) {
				$seeded += absint( $result );
			}
		};

		if ( class_exists( 'Mobo_Core_Product_Map' ) && Mobo_Core_Product_Map::table_exists() ) {
			$map = Mobo_Core_Product_Map::table_name();
			$insert_rows(
				"SELECT remote_guid AS product_guid, wp_post_id AS wp_product_id FROM {$map} WHERE connection_id={$connection_id} AND object_type = 'product' AND remote_guid <> ''",
				'product-map'
			);
		}

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() ) {
			$products = Mobo_Core_Storage_Schema::table_name( 'products' );
			$insert_rows(
				"SELECT c.product_guid AS product_guid, c.woo_product_id AS wp_product_id FROM {$products} c WHERE c.connection_id={$connection_id} AND c.product_guid <> '' AND c.woo_product_id > 0",
				'custom-products'
			);
		} elseif ( self::is_default_connection() ) {
			$insert_rows(
				"SELECT pm.meta_value AS product_guid, p.ID AS wp_product_id
				 FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'product' AND pm.meta_key = 'product_guid' AND pm.meta_value <> ''",
				'postmeta'
			);
		}

		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$image_table = Mobo_Core_Image_Queue::table_name();
			$insert_rows(
				"SELECT DISTINCT product_guid, product_id AS wp_product_id FROM {$image_table} WHERE connection_id={$connection_id} AND product_guid <> '' AND product_id > 0",
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
				 WHERE connection_id={$connection_id} AND status = 'done' AND event_type = 'ProductUpdated'
				   AND entity_type = 'product' AND entity_guid <> ''",
				'sync-event'
			);
		}

		return array( 'success' => true, 'seeded' => $seeded );
	}
}
