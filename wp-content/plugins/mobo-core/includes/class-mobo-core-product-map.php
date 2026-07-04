<?php
/**
 * Fast remote GUID to local WooCommerce object map.
 *
 * This table is a performance layer over legacy post meta:
 * - product_guid for products
 * - variant_guid for product variations
 *
 * Existing customer installs remain safe because every lookup can fallback to
 * legacy meta_query and then repair this table lazily.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Product_Map {

	const TYPE_PRODUCT   = 'product';
	const TYPE_VARIATION = 'variation';

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mobo_product_map';
	}

	/**
	 * Create/update table schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			remote_guid varchar(191) NOT NULL,
			wp_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(32) NOT NULL,
			parent_remote_guid varchar(191) NOT NULL DEFAULT '',
			last_hash varchar(64) NOT NULL DEFAULT '',
			sync_incomplete tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY remote_object (remote_guid, object_type),
			KEY wp_post_id (wp_post_id),
			KEY object_type (object_type),
			KEY parent_remote_guid (parent_remote_guid)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Check whether table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table_name();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Get product ID by remote product GUID.
	 *
	 * @param string $guid Remote product GUID.
	 * @return int
	 */
	public function get_product_id( $guid ) {
		return $this->get_post_id( $guid, self::TYPE_PRODUCT, 'product' );
	}

	/**
	 * Get variation ID by remote variant GUID.
	 *
	 * @param string $guid Remote variant GUID.
	 * @return int
	 */
	public function get_variation_id( $guid ) {
		return $this->get_post_id( $guid, self::TYPE_VARIATION, 'product_variation' );
	}

	/**
	 * Upsert product mapping.
	 *
	 * @param string $guid Remote product GUID.
	 * @param int    $post_id Product post ID.
	 * @param string $last_hash Optional hash.
	 * @param bool   $sync_incomplete Sync incomplete flag.
	 * @return bool
	 */
	public function upsert_product( $guid, $post_id, $last_hash = '', $sync_incomplete = false ) {
		return $this->upsert( $guid, $post_id, self::TYPE_PRODUCT, '', $last_hash, $sync_incomplete );
	}

	/**
	 * Upsert variation mapping.
	 *
	 * @param string $guid Remote variant GUID.
	 * @param int    $post_id Variation post ID.
	 * @param string $parent_guid Remote parent product GUID.
	 * @param string $last_hash Optional hash.
	 * @param bool   $sync_incomplete Sync incomplete flag.
	 * @return bool
	 */
	public function upsert_variation( $guid, $post_id, $parent_guid = '', $last_hash = '', $sync_incomplete = false ) {
		return $this->upsert( $guid, $post_id, self::TYPE_VARIATION, $parent_guid, $last_hash, $sync_incomplete );
	}

	/**
	 * Upsert a map row.
	 *
	 * @param string $guid Remote GUID.
	 * @param int    $post_id Local post ID.
	 * @param string $object_type product|variation.
	 * @param string $parent_guid Parent remote GUID.
	 * @param string $last_hash Optional hash.
	 * @param bool   $sync_incomplete Sync incomplete flag.
	 * @return bool
	 */
	public function upsert( $guid, $post_id, $object_type, $parent_guid = '', $last_hash = '', $sync_incomplete = false ) {
		global $wpdb;

		$guid        = sanitize_text_field( (string) $guid );
		$post_id     = absint( $post_id );
		$object_type = sanitize_key( (string) $object_type );

		if ( '' === $guid || $post_id <= 0 || ! in_array( $object_type, array( self::TYPE_PRODUCT, self::TYPE_VARIATION ), true ) ) {
			return false;
		}

		if ( ! self::table_exists() ) {
			return false;
		}

		$now   = current_time( 'mysql', true );
		$table = self::table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE remote_guid = %s AND object_type = %s LIMIT 1",
				$guid,
				$object_type
			)
		);

		$data = array(
			'remote_guid'        => $guid,
			'wp_post_id'         => $post_id,
			'object_type'        => $object_type,
			'parent_remote_guid' => sanitize_text_field( (string) $parent_guid ),
			'last_hash'          => sanitize_text_field( (string) $last_hash ),
			'sync_incomplete'    => $sync_incomplete ? 1 : 0,
			'updated_at'         => $now,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( $existing_id ) {
			return false !== $wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ), $formats, array( '%d' ) );
		}

		$data['created_at'] = $now;
		$formats[]          = '%s';

		return false !== $wpdb->insert( $table, $data, $formats );
	}

	/**
	 * Get post ID from map and validate it still exists with expected post type.
	 * Invalid stale rows are removed.
	 *
	 * @param string $guid Remote GUID.
	 * @param string $object_type product|variation.
	 * @param string $expected_post_type WP post type.
	 * @return int
	 */
	private function get_post_id( $guid, $object_type, $expected_post_type ) {
		global $wpdb;

		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, wp_post_id FROM {$table} WHERE remote_guid = %s AND object_type = %s LIMIT 1",
				$guid,
				sanitize_key( (string) $object_type )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['wp_post_id'] ) ) {
			return 0;
		}

		$post_id = absint( $row['wp_post_id'] );

		if ( $post_id <= 0 || get_post_type( $post_id ) !== $expected_post_type ) {
			$this->delete_row( absint( $row['id'] ) );
			return 0;
		}

		return $post_id;
	}

	/**
	 * Delete map row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private function delete_row( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return;
		}

		$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Incrementally seed product/variation map from legacy post meta.
	 *
	 * This method is intentionally bounded. If a site has many products, missing
	 * rows are still repaired lazily by normal sync lookup fallback.
	 *
	 * @param int $limit Max rows per object type.
	 * @return array
	 */
	public function seed_from_legacy_meta( $limit = 500 ) {
		global $wpdb;

		$limit = max( 50, min( 2000, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array( 'products' => 0, 'variations' => 0 );
		}

		$products   = $this->seed_products_from_legacy_meta( $limit );
		$variations = $this->seed_variations_from_legacy_meta( $limit );

		if ( 0 === $products && 0 === $variations ) {
			update_option( 'mobo_core_product_map_seed_completed_at', time(), false );
		}

		return array(
			'products'   => $products,
			'variations' => $variations,
		);
	}

	/**
	 * Seed product rows.
	 *
	 * @param int $limit Limit.
	 * @return int
	 */
	private function seed_products_from_legacy_meta( $limit ) {
		global $wpdb;

		$cursor = absint( get_option( 'mobo_core_product_map_product_cursor', 0 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value AS remote_guid
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_guid'
				WHERE p.ID > %d
				AND p.post_type = 'product'
				AND pm.meta_value <> ''
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$count = 0;
		$last  = $cursor;

		foreach ( $rows as $row ) {
			$post_id = absint( $row['ID'] );
			$guid    = sanitize_text_field( (string) $row['remote_guid'] );

			if ( $post_id > 0 && '' !== $guid && $this->upsert_product( $guid, $post_id ) ) {
				$count++;
			}

			$last = max( $last, $post_id );
		}

		update_option( 'mobo_core_product_map_product_cursor', $last, false );

		return $count;
	}

	/**
	 * Seed variation rows.
	 *
	 * @param int $limit Limit.
	 * @return int
	 */
	private function seed_variations_from_legacy_meta( $limit ) {
		global $wpdb;

		$cursor = absint( get_option( 'mobo_core_product_map_variation_cursor', 0 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, vm.meta_value AS remote_guid, pm.meta_value AS parent_remote_guid
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = 'variant_guid'
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_guid'
				WHERE p.ID > %d
				AND p.post_type = 'product_variation'
				AND vm.meta_value <> ''
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$count = 0;
		$last  = $cursor;

		foreach ( $rows as $row ) {
			$post_id     = absint( $row['ID'] );
			$guid        = sanitize_text_field( (string) $row['remote_guid'] );
			$parent_guid = sanitize_text_field( (string) $row['parent_remote_guid'] );

			if ( $post_id > 0 && '' !== $guid && $this->upsert_variation( $guid, $post_id, $parent_guid ) ) {
				$count++;
			}

			$last = max( $last, $post_id );
		}

		update_option( 'mobo_core_product_map_variation_cursor', $last, false );

		return $count;
	}
}
