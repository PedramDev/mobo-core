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


/*
 * This component operates on Mobo Core's internal queue/map tables. Direct
 * database access is required for atomic batching and cursor updates; table
 * identifiers are generated internally and all external values are prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Product_Map {

	/** @var bool|null Request-local table existence cache. */
	private static $table_exists_cache = null;

	/** @var array Request-local remote GUID lookup cache. */
	private static $lookup_cache = array();

	/** @var array Request-local mapped post-status cache. */
	private static $status_cache = array();

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
			KEY parent_remote_guid (parent_remote_guid),
			KEY updated_id (updated_at, id)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
		self::$lookup_cache       = array();
		self::$status_cache       = array();
	}

	/**
	 * Check whether table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		if ( null !== self::$table_exists_cache ) {
			return (bool) self::$table_exists_cache;
		}

		$table = self::table_name();
		self::$table_exists_cache = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return (bool) self::$table_exists_cache;
	}

	/**
	 * Request-local cache key.
	 *
	 * @param string $guid Remote GUID.
	 * @param string $object_type Object type.
	 * @return string
	 */
	private static function lookup_cache_key( $guid, $object_type ) {
		return sanitize_key( (string) $object_type ) . '|' . sanitize_text_field( (string) $guid );
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
	 * Get product post status by remote product GUID.
	 *
	 * @param string $guid Remote product GUID.
	 * @return string
	 */
	public function get_product_post_status( $guid ) {
		return $this->get_post_status( $guid, self::TYPE_PRODUCT, 'product' );
	}

	/**
	 * Get variation post status by remote variant GUID.
	 *
	 * @param string $guid Remote variant GUID.
	 * @return string
	 */
	public function get_variation_post_status( $guid ) {
		return $this->get_post_status( $guid, self::TYPE_VARIATION, 'product_variation' );
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

		$now         = current_time( 'mysql', true );
		$table       = self::table_name();
		$parent_guid = sanitize_text_field( (string) $parent_guid );
		$last_hash   = sanitize_text_field( (string) $last_hash );
		$incomplete  = $sync_incomplete ? 1 : 0;

		/*
		 * Atomic upsert removes the SELECT-before-write round trip for every changed
		 * product/variation while remaining compatible with MySQL/MariaDB versions
		 * used by WordPress hosts. Existing created_at is intentionally preserved.
		 */
		$query = "INSERT INTO {$table}
			(remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at)
			VALUES (%s, %d, %s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				wp_post_id = %d,
				parent_remote_guid = %s,
				last_hash = %s,
				sync_incomplete = %d,
				updated_at = %s";

		$updated = $wpdb->query(
			$wpdb->prepare(
				$query,
				$guid, $post_id, $object_type, $parent_guid, $last_hash, $incomplete, $now, $now,
				$post_id, $parent_guid, $last_hash, $incomplete, $now
			)
		);

		if ( false === $updated ) {
			return false;
		}

		$cache_key = self::lookup_cache_key( $guid, $object_type );
		self::$lookup_cache[ $cache_key ] = $post_id;
		unset( self::$status_cache[ $cache_key ] );
		return true;
	}

	/**
	 * Prime a page of variation GUID mappings in one SQL round-trip.
	 *
	 * Product sync commonly receives tens of variations in one authoritative page.
	 * Without priming, each GUID lookup can issue its own SELECT. This method fills
	 * the same request-local lookup cache in bulk and primes WordPress post/meta
	 * caches so the following fast-path checks remain memory-only where possible.
	 *
	 * @param array $guids Remote variation GUIDs.
	 * @return array<string,int> Sanitized GUID => local variation ID (0 when absent/blocked).
	 */
	public function prime_variation_ids( $guids ) {
		global $wpdb;

		if ( ! is_array( $guids ) || empty( $guids ) || ! self::table_exists() ) {
			return array();
		}

		$clean = array();
		foreach ( $guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) {
				$clean[ $guid ] = true;
			}
		}
		$clean = array_keys( $clean );
		if ( empty( $clean ) ) {
			return array();
		}

		$result  = array();
		$missing = array();
		foreach ( $clean as $guid ) {
			$key = self::lookup_cache_key( $guid, self::TYPE_VARIATION );
			if ( array_key_exists( $key, self::$lookup_cache ) ) {
				$result[ $guid ] = absint( self::$lookup_cache[ $key ] );
			} else {
				$missing[] = $guid;
			}
		}

		$table = self::table_name();
		$rows_by_guid = array();
		foreach ( array_chunk( $missing, 400 ) as $chunk ) {
			if ( empty( $chunk ) ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = "SELECT remote_guid, wp_post_id FROM {$table} WHERE object_type = %s AND remote_guid IN ({$placeholders})";
			$args         = array_merge( array( self::TYPE_VARIATION ), $chunk );
			$prepared     = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
			$rows         = $wpdb->get_results( $prepared, ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$guid    = sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) );
					$post_id = absint( $row['wp_post_id'] ?? 0 );
					if ( '' !== $guid ) {
						$rows_by_guid[ $guid ] = $post_id;
					}
				}
			}
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $rows_by_guid ) ) ) );
		if ( ! empty( $post_ids ) ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $post_ids, false, false );
			}
			update_meta_cache( 'post', $post_ids );
		}

		foreach ( $missing as $guid ) {
			$key     = self::lookup_cache_key( $guid, self::TYPE_VARIATION );
			$post_id = absint( $rows_by_guid[ $guid ] ?? 0 );
			if ( $post_id > 0 && 'product_variation' === get_post_type( $post_id ) ) {
				self::$lookup_cache[ $key ] = $post_id;
				$status = sanitize_key( (string) get_post_status( $post_id ) );
				self::$status_cache[ $key ] = $status;
				$result[ $guid ] = in_array( $status, array( 'trash', 'auto-draft' ), true ) ? 0 : $post_id;
			} else {
				self::$lookup_cache[ $key ] = 0;
				self::$status_cache[ $key ] = '';
				$result[ $guid ] = 0;
			}
		}

		return $result;
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

		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );
		if ( '' === $guid || ! self::table_exists() ) {
			return 0;
		}

		$cache_key = self::lookup_cache_key( $guid, $object_type );
		if ( array_key_exists( $cache_key, self::$lookup_cache ) ) {
			$post_id = absint( self::$lookup_cache[ $cache_key ] );
			if ( $post_id <= 0 ) {
				return 0;
			}
		} else {
			$table = self::table_name();
			$row   = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, wp_post_id FROM {$table} WHERE remote_guid = %s AND object_type = %s LIMIT 1",
					$guid,
					$object_type
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) || empty( $row['wp_post_id'] ) ) {
				self::$lookup_cache[ $cache_key ] = 0;
				return 0;
			}

			$post_id = absint( $row['wp_post_id'] );
			if ( $post_id <= 0 || get_post_type( $post_id ) !== $expected_post_type ) {
				$this->delete_row( absint( $row['id'] ) );
				self::$lookup_cache[ $cache_key ] = 0;
				return 0;
			}

			self::$lookup_cache[ $cache_key ] = $post_id;
		}

		if ( get_post_type( $post_id ) !== $expected_post_type ) {
			self::$lookup_cache[ $cache_key ] = 0;
			return 0;
		}

		$status = get_post_status( $post_id );
		if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Get post status from map and validate post type.
	 *
	 * @param string $guid Remote GUID.
	 * @param string $object_type product|variation.
	 * @param string $expected_post_type WP post type.
	 * @return string
	 */
	private function get_post_status( $guid, $object_type, $expected_post_type ) {
		global $wpdb;

		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );

		if ( '' === $guid || ! self::table_exists() ) {
			return '';
		}

		$cache_key = self::lookup_cache_key( $guid, $object_type );
		if ( array_key_exists( $cache_key, self::$status_cache ) ) {
			return sanitize_key( (string) self::$status_cache[ $cache_key ] );
		}

		$post_id = array_key_exists( $cache_key, self::$lookup_cache ) ? absint( self::$lookup_cache[ $cache_key ] ) : 0;
		$row_id  = 0;

		if ( ! array_key_exists( $cache_key, self::$lookup_cache ) ) {
			$table = self::table_name();
			$row   = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, wp_post_id FROM {$table} WHERE remote_guid = %s AND object_type = %s LIMIT 1",
					$guid,
					$object_type
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				$row_id  = absint( $row['id'] ?? 0 );
				$post_id = absint( $row['wp_post_id'] ?? 0 );
			}
			self::$lookup_cache[ $cache_key ] = $post_id;
		}

		if ( $post_id <= 0 ) {
			self::$status_cache[ $cache_key ] = '';
			return '';
		}

		if ( get_post_type( $post_id ) !== $expected_post_type ) {
			if ( $row_id > 0 ) {
				$this->delete_row( $row_id );
			} else {
				self::$lookup_cache[ $cache_key ] = 0;
			}
			self::$status_cache[ $cache_key ] = '';
			return '';
		}

		$status = sanitize_key( (string) get_post_status( $post_id ) );
		self::$status_cache[ $cache_key ] = $status;
		return $status;
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
		self::$lookup_cache = array();
		self::$status_cache = array();
	}

	/**
	 * Delete a variation mapping by remote GUID.
	 *
	 * @param string $guid Remote variant GUID.
	 * @return bool
	 */
	public function delete_variation( $guid ) {
		return $this->delete_by_remote_guid( $guid, self::TYPE_VARIATION );
	}

	/**
	 * Delete every map row pointing to a local WordPress object.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public function delete_by_post_id( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$deleted = $wpdb->delete( self::table_name(), array( 'wp_post_id' => $post_id ), array( '%d' ) );
		if ( false !== $deleted ) {
			self::$lookup_cache = array();
			self::$status_cache = array();
		}
		return false !== $deleted;
	}

	/**
	 * Delete variation map rows pointing to a local WordPress object without
	 * touching the product mapping for the same post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public function delete_variation_by_post_id( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$deleted = $wpdb->delete(
			self::table_name(),
			array(
				'wp_post_id' => $post_id,
				'object_type' => self::TYPE_VARIATION,
			),
			array( '%d', '%s' )
		);
		if ( false !== $deleted ) {
			self::$lookup_cache = array();
			self::$status_cache = array();
		}
		return false !== $deleted;
	}

	/**
	 * Delete variation mappings owned by one remote product, optionally keeping
	 * the GUIDs present in the current authoritative snapshot.
	 *
	 * @param string $parent_guid Parent remote product GUID.
	 * @param array  $keep_guids Remote variation GUIDs to retain.
	 * @return int Number of deleted rows.
	 */
	public function delete_variations_for_parent( $parent_guid, $keep_guids = array() ) {
		global $wpdb;

		$parent_guid = sanitize_text_field( (string) $parent_guid );
		if ( '' === $parent_guid || ! self::table_exists() ) {
			return 0;
		}

		$keep = array();
		foreach ( is_array( $keep_guids ) ? $keep_guids : array() as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) {
				$keep[ $guid ] = true;
			}
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, remote_guid FROM {$table} WHERE object_type = %s AND parent_remote_guid = %s",
				self::TYPE_VARIATION,
				$parent_guid
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$delete_ids   = array();
		$delete_guids = array();
		foreach ( $rows as $row ) {
			$remote_guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			if ( '' !== $remote_guid && isset( $keep[ $remote_guid ] ) ) {
				continue;
			}

			$id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			if ( $id > 0 ) {
				$delete_ids[] = $id;
				if ( '' !== $remote_guid ) {
					$delete_guids[] = $remote_guid;
				}
			}
		}

		if ( empty( $delete_ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array_chunk( $delete_ids, 500 ) as $chunk ) {
			$id_sql = implode( ',', array_map( 'absint', $chunk ) );
			$result = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$id_sql})" );
			if ( false !== $result ) {
				$deleted += absint( $result );
			}
		}

		foreach ( $delete_guids as $guid ) {
			$key = self::lookup_cache_key( $guid, self::TYPE_VARIATION );
			unset( self::$lookup_cache[ $key ], self::$status_cache[ $key ] );
		}

		return $deleted;
	}

	/**
	 * Delete a map row by remote identity and object type.
	 *
	 * @param string $guid Remote GUID.
	 * @param string $object_type Object type.
	 * @return bool
	 */
	private function delete_by_remote_guid( $guid, $object_type ) {
		global $wpdb;

		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );
		if ( '' === $guid || ! self::table_exists() ) {
			return false;
		}

		$deleted = $wpdb->delete(
			self::table_name(),
			array(
				'remote_guid' => $guid,
				'object_type' => $object_type,
			),
			array( '%s', '%s' )
		);
		if ( false !== $deleted ) {
			$key = self::lookup_cache_key( $guid, $object_type );
			unset( self::$lookup_cache[ $key ], self::$status_cache[ $key ] );
		}
		return false !== $deleted;
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
				AND p.post_status NOT IN ('trash', 'auto-draft')
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
				AND p.post_status NOT IN ('trash', 'auto-draft')
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
