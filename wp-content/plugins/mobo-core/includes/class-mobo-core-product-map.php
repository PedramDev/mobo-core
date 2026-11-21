<?php
/**
 * Fast remote GUID to local WooCommerce object map.
 *
 * Durable GUID-to-Woo mapping used across upgrade generations. Before the
 * 10.33.95 identity terminal fence, bounded legacy postmeta seeding/fallback is
 * permitted for compatibility. After that irreversible fence, Product/Variant
 * identity is Custom-Storage authoritative and no lookup may depend on retired
 * identity postmeta.
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

	/** @return int */
	private static function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** @return bool */
	private static function is_default_connection() {
		if ( ! class_exists( 'Mobo_Core_Connection_Repository' ) ) { return false; }
		$default = Mobo_Core_Connection_Repository::get_default();
		return is_array( $default ) && absint( $default['id'] ?? 0 ) === self::connection_id();
	}

	/** Prevent one Woo object from being owned by two Sources. @return bool */
	private static function post_owner_conflicts( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$connection_id = self::connection_id();
		if ( $post_id <= 0 || $connection_id <= 0 || ! self::table_exists() ) { return false; }
		$table = self::table_name();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM {$table} WHERE wp_post_id=%d AND connection_id<>%d LIMIT 1", $post_id, $connection_id ) );
		/* An unavailable owner SELECT is not proof that the Woo ID is unowned. */
		return '' !== (string) $wpdb->last_error || null !== $owner;
	}

	/**
	 * Installation-global Woo post guard, deliberately not scoped to a Source.
	 * The short runtime lease makes this writer visible to the upgrade drain;
	 * the MySQL named lock serializes the owner SELECT and map mutation even if
	 * competing Source requests race. Never proceed when either lock is absent.
	 * @return array|false
	 */
	private static function acquire_post_owner_guard( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! class_exists( 'Mobo_Core_Lock' ) ) { return false; }
		$runtime_name = 'product_map_woo_' . $post_id;
		$runtime_token = Mobo_Core_Lock::acquire( $runtime_name, 120 );
		if ( false === $runtime_token ) { return false; }
		$db_name = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$mysql_name = 'mobo_pm_' . substr( hash( 'sha256', $db_name . '|' . (string) $wpdb->prefix . '|' . $post_id ), 0, 40 );
		$named = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $mysql_name ) );
		if ( '1' !== (string) $named ) {
			Mobo_Core_Lock::release( $runtime_name, $runtime_token );
			return false;
		}
		return array( 'runtimeName' => $runtime_name, 'runtimeToken' => $runtime_token, 'mysqlName' => $mysql_name );
	}

	/** Compare-release both components; the caller's finally always invokes this. */
	private static function release_post_owner_guard( $guard ) {
		global $wpdb;
		if ( ! is_array( $guard ) ) { return; }
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $guard['mysqlName'] ) );
		Mobo_Core_Lock::release( (string) $guard['runtimeName'], (string) $guard['runtimeToken'] );
	}

	/** @var bool Whether the current legacy seed pass stalled on a durable DB write. */
	private $legacy_seed_stalled = false;


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
		$charset_collate = Mobo_Core_Database_Charset::schema_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_guid varchar(191) NOT NULL,
			wp_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(32) NOT NULL,
			parent_remote_guid varchar(191) NOT NULL DEFAULT '',
			last_hash varchar(64) NOT NULL DEFAULT '',
			sync_incomplete tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY remote_object (connection_id, remote_guid(140), object_type),
			KEY wp_post_id (connection_id, wp_post_id),
			KEY object_type (connection_id, object_type),
			KEY parent_remote_guid (connection_id, parent_remote_guid(140)),
			KEY parent_object (connection_id, parent_remote_guid(110), object_type),
			KEY updated_id (connection_id, updated_at, id)
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
		return self::connection_id() . '|' . sanitize_key( (string) $object_type ) . '|' . sanitize_text_field( (string) $guid );
	}

	/**
	 * Get product ID by remote product GUID.
	 *
	 * @param string $guid Remote product GUID.
	 * @return int
	 */
	public function get_product_id( $guid ) {
		return $this->get_rollout_post_id( $guid, self::TYPE_PRODUCT, 'product' );
	}

	/**
	 * Get variation ID by remote variant GUID.
	 *
	 * @param string $guid Remote variant GUID.
	 * @return int
	 */
	public function get_variation_id( $guid ) {
		return $this->get_rollout_post_id( $guid, self::TYPE_VARIATION, 'product_variation' );
	}

	/**
	 * Get product post status by remote product GUID.
	 *
	 * @param string $guid Remote product GUID.
	 * @return string
	 */
	public function get_product_post_status( $guid ) {
		if ( self::custom_primary_enabled() ) {
			$post_id = $this->get_rollout_post_id( $guid, self::TYPE_PRODUCT, 'product' );
			return $post_id > 0 ? sanitize_key( (string) get_post_status( $post_id ) ) : '';
		}
		$status = $this->get_post_status( $guid, self::TYPE_PRODUCT, 'product' );
		if ( self::shadow_read_enabled() ) { self::observe_shadow_lookup( $guid, self::TYPE_PRODUCT, $this->get_post_id( $guid, self::TYPE_PRODUCT, 'product' ) ); }
		return $status;
	}

	/**
	 * Get variation post status by remote variant GUID.
	 *
	 * @param string $guid Remote variant GUID.
	 * @return string
	 */
	public function get_variation_post_status( $guid ) {
		if ( self::custom_primary_enabled() ) {
			$post_id = $this->get_rollout_post_id( $guid, self::TYPE_VARIATION, 'product_variation' );
			return $post_id > 0 ? sanitize_key( (string) get_post_status( $post_id ) ) : '';
		}
		$status = $this->get_post_status( $guid, self::TYPE_VARIATION, 'product_variation' );
		if ( self::shadow_read_enabled() ) { self::observe_shadow_lookup( $guid, self::TYPE_VARIATION, $this->get_post_id( $guid, self::TYPE_VARIATION, 'product_variation' ) ); }
		return $status;
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
		$connection_id = self::connection_id();

		$guid        = sanitize_text_field( (string) $guid );
		$post_id     = absint( $post_id );
		$object_type = sanitize_key( (string) $object_type );

		if ( $connection_id <= 0 || '' === $guid || $post_id <= 0 || ! in_array( $object_type, array( self::TYPE_PRODUCT, self::TYPE_VARIATION ), true ) ) {
			return false;
		}

		if ( ! self::table_exists() ) { return false; }
		$owner_guard = self::acquire_post_owner_guard( $post_id );
		if ( false === $owner_guard ) { return false; }
		try {
		if ( self::post_owner_conflicts( $post_id ) ) { return false; }

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
		/*
		 * remote_object uses a 150-character GUID prefix so utf8mb4 remains compatible
		 * with legacy 767-byte InnoDB key limits. Therefore a pathological pair of
		 * long identifiers can collide at the index level even though the full GUIDs
		 * differ. Never let that prefix collision mutate the existing mapping: every
		 * ON DUPLICATE assignment is conditional on full GUID equality under the table collation, followed
		 * by an exact read-back postcondition. Normal UUID/numeric identifiers take the
		 * same single-write fast path.
		 */
		$query = "INSERT INTO {$table}
			(connection_id, remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at)
			VALUES (%d, %s, %d, %s, %s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE
				wp_post_id = IF(connection_id = VALUES(connection_id) AND BINARY remote_guid = BINARY VALUES(remote_guid) AND object_type = VALUES(object_type), VALUES(wp_post_id), wp_post_id),
				parent_remote_guid = IF(connection_id = VALUES(connection_id) AND BINARY remote_guid = BINARY VALUES(remote_guid) AND object_type = VALUES(object_type), VALUES(parent_remote_guid), parent_remote_guid),
				last_hash = IF(connection_id = VALUES(connection_id) AND BINARY remote_guid = BINARY VALUES(remote_guid) AND object_type = VALUES(object_type), VALUES(last_hash), last_hash),
				sync_incomplete = IF(connection_id = VALUES(connection_id) AND BINARY remote_guid = BINARY VALUES(remote_guid) AND object_type = VALUES(object_type), VALUES(sync_incomplete), sync_incomplete),
				updated_at = IF(connection_id = VALUES(connection_id) AND BINARY remote_guid = BINARY VALUES(remote_guid) AND object_type = VALUES(object_type), VALUES(updated_at), updated_at)";

		$updated = $wpdb->query(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is static and only values are variable.
				$connection_id, $guid, $post_id, $object_type, $parent_guid, $last_hash, $incomplete, $now, $now
			)
		);

		if ( false === $updated ) {
			return false;
		}

		$persisted = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wp_post_id, parent_remote_guid, last_hash, sync_incomplete FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
				$connection_id,
				$guid,
				$object_type
			),
			ARRAY_A
		);
		if ( ! is_array( $persisted )
			|| absint( $persisted['wp_post_id'] ) !== $post_id
			|| (string) $persisted['parent_remote_guid'] !== $parent_guid
			|| (string) $persisted['last_hash'] !== $last_hash
			|| absint( $persisted['sync_incomplete'] ) !== $incomplete ) {
			return false;
		}

		/* A WooCommerce variation must have exactly one current remote GUID. During
		 * identity migration (same attribute signature, new Portal GUID), keep the old
		 * mapping until the new GUID has been durably upserted, then retire every stale
		 * reverse mapping for the same local variation. This avoids a crash window where
		 * neither identity is resolvable, and prevents a later old-GUID event from being
		 * routed to the migrated variation. */
		if ( self::TYPE_VARIATION === $object_type ) {
			if ( ! Mobo_Core_Lock::renew( $owner_guard['runtimeName'], $owner_guard['runtimeToken'], 120 ) ) { return false; }
			/*
			 * Reverse-map cleanup must be owned by the write that just passed the
			 * durability read-back. A concurrent identity migration can otherwise
			 * retire this GUID and install a newer GUID before this DELETE executes;
			 * an unconditional `remote_guid <> current` cleanup would then erase the
			 * newer mapping. Gate the DELETE on the expected owner row in the same SQL
			 * statement, then re-read ownership before reporting success.
			 */
			$stale_deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE stale FROM {$table} AS stale
					INNER JOIN {$table} AS owner
						ON owner.connection_id = %d
						AND BINARY owner.remote_guid = BINARY %s
						AND owner.object_type = %s
						AND owner.wp_post_id = %d
						AND BINARY owner.parent_remote_guid = BINARY %s
						AND owner.last_hash = %s
						AND owner.sync_incomplete = %d
					WHERE stale.connection_id = %d
						AND stale.wp_post_id = %d
						AND stale.object_type = %s
						AND BINARY stale.remote_guid <> BINARY %s",
					$connection_id,
					$guid,
					self::TYPE_VARIATION,
					$post_id,
					$parent_guid,
					$last_hash,
					$incomplete,
					$connection_id,
					$post_id,
					self::TYPE_VARIATION,
					$guid
				)
			);
			if ( false === $stale_deleted ) {
				return false;
			}

			$persisted_after_cleanup = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT wp_post_id, parent_remote_guid, last_hash, sync_incomplete FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
					$guid,
					self::TYPE_VARIATION
				),
				ARRAY_A
			);
			if ( ! is_array( $persisted_after_cleanup )
				|| absint( $persisted_after_cleanup['wp_post_id'] ) !== $post_id
				|| (string) $persisted_after_cleanup['parent_remote_guid'] !== $parent_guid
				|| (string) $persisted_after_cleanup['last_hash'] !== $last_hash
				|| absint( $persisted_after_cleanup['sync_incomplete'] ) !== $incomplete ) {
				return false;
			}
		}

		$cache_key = self::lookup_cache_key( $guid, $object_type );
		if ( self::TYPE_VARIATION === $object_type ) {
			self::$lookup_cache = array();
			self::$status_cache = array();
		}
		self::$lookup_cache[ $cache_key ] = $post_id;
		unset( self::$status_cache[ $cache_key ] );
		if ( 'custom-only' === sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) ) && class_exists( 'Mobo_Core_Storage_Legacy_Dependency' ) ) {
			Mobo_Core_Storage_Legacy_Dependency::record_legacy_write( 'product_map', $post_id, $object_type );
		}
		return true;
		} finally {
			self::release_post_owner_guard( $owner_guard );
		}
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
		if ( self::custom_primary_enabled() ) {
			return $this->prime_variation_ids_custom_primary( $guids );
		}
		return $this->prime_variation_ids_legacy( $guids );
	}

	/** Legacy Product Map bulk lookup retained for shadow mode and primary fallback. @return array<string,int> */
	private function prime_variation_ids_legacy( $guids ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return array(); }

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
			$sql          = "SELECT remote_guid, wp_post_id FROM {$table} WHERE connection_id=%d AND object_type = %s AND remote_guid IN ({$placeholders})";
			$args         = array_merge( array( $connection_id, self::TYPE_VARIATION ), $chunk );
			$prepared     = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
			$rows         = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- $prepared is produced by wpdb::prepare() from internal table/placeholder structure.
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

		if ( self::shadow_read_enabled() && ! empty( $result ) ) {
			Mobo_Core_Storage_Parity::observe_variant_lookups( $result );
		}

		return $result;
	}

	/** Guarded set-based Custom-Primary variation lookup with Legacy fallback. @return array<string,int> */
	private function prime_variation_ids_custom_primary( $guids ) {
		if ( ! is_array( $guids ) || empty( $guids ) ) { return array(); }
		$clean = array();
		foreach ( $guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( '' !== $guid ) { $clean[ $guid ] = true; }
		}
		$clean = array_keys( $clean );
		if ( empty( $clean ) ) { return array(); }

		$attempts = Mobo_Core_Storage_Primary_Read::lookup_variants( $clean );
		$result = array();
		$fallback = array();
		foreach ( $clean as $guid ) {
			$attempt = isset( $attempts[ $guid ] ) && is_array( $attempts[ $guid ] ) ? $attempts[ $guid ] : array( 'status'=>'missing', 'postId'=>0, 'reason'=>'custom_missing' );
			if ( 'hit' === sanitize_key( (string) ( isset( $attempt['status'] ) ? $attempt['status'] : '' ) ) ) {
				$post_id = absint( isset( $attempt['postId'] ) ? $attempt['postId'] : 0 );
				$result[ $guid ] = $post_id;
				$key = self::lookup_cache_key( $guid, self::TYPE_VARIATION );
				self::$lookup_cache[ $key ] = $post_id;
				self::$status_cache[ $key ] = sanitize_key( (string) get_post_status( $post_id ) );
			} else {
				$fallback[ $guid ] = $attempt;
			}
		}

		if ( ! empty( $fallback ) ) {
			$legacy = $this->prime_variation_ids_legacy( array_keys( $fallback ) );
			foreach ( $fallback as $guid => $attempt ) {
				$legacy_id = absint( isset( $legacy[ $guid ] ) ? $legacy[ $guid ] : 0 );
				$result[ $guid ] = $legacy_id;
				$status = sanitize_key( (string) ( isset( $attempt['status'] ) ? $attempt['status'] : '' ) );
				if ( 'invalid' === $status || $legacy_id > 0 ) {
					Mobo_Core_Storage_Primary_Read::record_fallback( 'variant', $guid, (string) ( isset( $attempt['reason'] ) ? $attempt['reason'] : 'custom_primary_fallback' ), absint( isset( $attempt['postId'] ) ? $attempt['postId'] : 0 ), $legacy_id );
				}
			}
		}

		$ordered = array();
		foreach ( $clean as $guid ) { $ordered[ $guid ] = absint( isset( $result[ $guid ] ) ? $result[ $guid ] : 0 ); }
		return $ordered;
	}

	/** Whether this request is authorized to prefer guarded Custom identities. @return bool */
	private static function custom_primary_enabled() {
		$requested = sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) );
		if ( ! in_array( $requested, array( 'custom-primary', 'custom-only' ), true ) ) { return false; }
		return class_exists( 'Mobo_Core_Storage_Mode' )
			&& Mobo_Core_Storage_Mode::reads_custom_primary()
			&& class_exists( 'Mobo_Core_Storage_Primary_Read' )
			&& Mobo_Core_Storage_Primary_Read::request_allowed();
	}

	/**
	 * Resolve one identity according to the rollout mode. Custom Primary is guarded
	 * by retained Woo identity meta and falls back to the unchanged Legacy map.
	 * @return int
	 */
	private function get_rollout_post_id( $guid, $object_type, $expected_post_type ) {
		$guid = sanitize_text_field( (string) $guid );
		if ( self::custom_primary_enabled() ) {
			$attempt = self::TYPE_VARIATION === $object_type
				? Mobo_Core_Storage_Primary_Read::lookup_variant( $guid )
				: Mobo_Core_Storage_Primary_Read::lookup_product( $guid );
			if ( 'hit' === sanitize_key( (string) ( isset( $attempt['status'] ) ? $attempt['status'] : '' ) ) ) {
				return absint( isset( $attempt['postId'] ) ? $attempt['postId'] : 0 );
			}
			$legacy_id = $this->get_post_id( $guid, $object_type, $expected_post_type );
			$status = sanitize_key( (string) ( isset( $attempt['status'] ) ? $attempt['status'] : '' ) );
			if ( 'invalid' === $status || $legacy_id > 0 ) {
				Mobo_Core_Storage_Primary_Read::record_fallback(
					self::TYPE_VARIATION === $object_type ? 'variant' : 'product',
					$guid,
					(string) ( isset( $attempt['reason'] ) ? $attempt['reason'] : 'custom_primary_fallback' ),
					absint( isset( $attempt['postId'] ) ? $attempt['postId'] : 0 ),
					$legacy_id
				);
			}
			return $legacy_id;
		}

		$post_id = $this->get_post_id( $guid, $object_type, $expected_post_type );
		self::observe_shadow_lookup( $guid, $object_type, $post_id );
		return $post_id;
	}

	/**
	 * Whether this request should perform read-only custom-storage parity checks.
	 * The raw option pre-check keeps the parity/storage classes unloaded on normal
	 * legacy and dual-write traffic. Future primary modes are capped to shadow-read.
	 *
	 * @return bool
	 */
	private static function shadow_read_enabled() {
		$requested = sanitize_key( (string) get_option( 'mobo_core_storage_mode', 'legacy' ) );
		if ( ! in_array( $requested, array( 'shadow-read', 'custom-primary', 'custom-only' ), true ) ) {
			return false;
		}
		return class_exists( 'Mobo_Core_Storage_Mode' ) && Mobo_Core_Storage_Mode::compares_shadow_reads();
	}

	/** @return void */
	private static function observe_shadow_lookup( $guid, $object_type, $post_id ) {
		if ( ! self::shadow_read_enabled() ) {
			return;
		}
		if ( self::TYPE_VARIATION === $object_type ) {
			Mobo_Core_Storage_Parity::observe_variant_lookup( $guid, $post_id );
			return;
		}
		Mobo_Core_Storage_Parity::observe_product_lookup( $guid, $post_id );
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
		$connection_id = self::connection_id();

		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );
		if ( $connection_id <= 0 || '' === $guid || ! self::table_exists() ) {
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
					"SELECT id, wp_post_id FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
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
				$cleanup_result = $this->delete_stale_row_if_unchanged(
					absint( $row['id'] ),
					$guid,
					$object_type,
					$post_id
				);

				if ( 1 !== $cleanup_result ) {
					$post_id = $this->read_current_valid_post_id( $guid, $object_type, $expected_post_type );
					if ( $post_id > 0 ) {
						self::$lookup_cache[ $cache_key ] = $post_id;
					} else {
						self::$lookup_cache[ $cache_key ] = 0;
						return 0;
					}
				} else {
					self::$lookup_cache[ $cache_key ] = 0;
					return 0;
				}
			} else {
				self::$lookup_cache[ $cache_key ] = $post_id;
			}
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
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return ''; }

		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );

		if ( $connection_id <= 0 || '' === $guid || ! self::table_exists() ) {
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
					"SELECT id, wp_post_id FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
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
				$cleanup_result = $this->delete_stale_row_if_unchanged( $row_id, $guid, $object_type, $post_id );
				if ( 1 !== $cleanup_result ) {
					$post_id = $this->read_current_valid_post_id( $guid, $object_type, $expected_post_type );
					if ( $post_id > 0 ) {
						self::$lookup_cache[ $cache_key ] = $post_id;
						$status = sanitize_key( (string) get_post_status( $post_id ) );
						self::$status_cache[ $cache_key ] = $status;
						return $status;
					}
				}
			} else {
				self::$lookup_cache[ $cache_key ] = 0;
			}
			self::$lookup_cache[ $cache_key ] = 0;
			self::$status_cache[ $cache_key ] = '';
			return '';
		}

		$status = sanitize_key( (string) get_post_status( $post_id ) );
		self::$status_cache[ $cache_key ] = $status;
		return $status;
	}

	/**
	 * Delete a stale map row only if the identity snapshot is still unchanged.
	 *
	 * This prevents a lookup cleanup from deleting a row that a concurrent sync
	 * repaired after the lookup read but before the cleanup write.
	 *
	 * @param int    $id Row ID.
	 * @param string $guid Remote GUID.
	 * @param string $object_type Object type.
	 * @param int    $post_id Stale post ID observed by the lookup.
	 * @return int|false Number of deleted rows, or false on SQL failure.
	 */
	private function delete_stale_row_if_unchanged( $id, $guid, $object_type, $post_id ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }

		$id          = absint( $id );
		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );
		$post_id     = absint( $post_id );

		if ( $id <= 0 || '' === $guid || '' === $object_type || ! self::table_exists() ) {
			return false;
		}

		$table  = self::table_name();
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE connection_id=%d AND id = %d AND BINARY remote_guid = BINARY %s AND object_type = %s AND wp_post_id = %d",
				$connection_id,
				$id,
				$guid,
				$object_type,
				$post_id
			)
		);

		self::$lookup_cache = array();
		self::$status_cache = array();

		return $result;
	}

	/**
	 * Re-read the current mapping after a compare-and-delete miss.
	 *
	 * The method is intentionally read-only: if the row is still stale because
	 * the cleanup SQL failed, the caller fails closed and leaves it retryable.
	 *
	 * @param string $guid Remote GUID.
	 * @param string $object_type Object type.
	 * @param string $expected_post_type Expected WordPress post type.
	 * @return int
	 */
	private function read_current_valid_post_id( $guid, $object_type, $expected_post_type ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return 0; }

		$guid               = sanitize_text_field( (string) $guid );
		$object_type        = sanitize_key( (string) $object_type );
		$expected_post_type = sanitize_key( (string) $expected_post_type );

		if ( '' === $guid || '' === $object_type || '' === $expected_post_type || ! self::table_exists() ) {
			return 0;
		}

		$table   = self::table_name();
		$post_id = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT wp_post_id FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
					$guid,
					$object_type
				)
			)
		);

		if ( $post_id <= 0 || get_post_type( $post_id ) !== $expected_post_type ) {
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
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return; }

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return;
		}

		$wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'id' => $id ), array( '%d', '%d' ) );
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
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$deleted = $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'wp_post_id' => $post_id ), array( '%d', '%d' ) );
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
	 * Snapshot exact variation map rows for one local post before quarantine.
	 * Product rows sharing the same wp_post_id are intentionally excluded.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|WP_Error
	 */
	public function snapshot_variation_rows_by_post_id( $post_id ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return new WP_Error( 'mobo_core_product_map_snapshot_unavailable', 'Variation Product Map snapshot is unavailable.' ); }

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! self::table_exists() ) {
			return new WP_Error( 'mobo_core_product_map_snapshot_unavailable', 'Variation Product Map snapshot is unavailable.' );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at FROM ' . self::table_name() . ' WHERE connection_id=%d AND wp_post_id = %d AND object_type = %s ORDER BY id ASC',
				$connection_id,
				$post_id,
				self::TYPE_VARIATION
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'mobo_core_product_map_snapshot_read_failed', '' !== (string) $wpdb->last_error ? sanitize_text_field( (string) $wpdb->last_error ) : 'Could not snapshot variation Product Map rows.' );
		}
		return $rows;
	}

	/**
	 * Restore an exact variation-map snapshot after a failed quarantine transition.
	 *
	 * @param int   $post_id WordPress post ID.
	 * @param array $rows Snapshot from snapshot_variation_rows_by_post_id().
	 * @return bool
	 */
	public function restore_variation_rows_snapshot( $post_id, $rows ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 ) { return false; }

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! is_array( $rows ) || ! self::table_exists() ) {
			return false;
		}

		/*
		 * Rollback must never erase or overwrite Product Map durability evidence
		 * written after the snapshot was taken. In particular, REPLACE is unsafe:
		 * the remote_guid unique key can now belong to a different parent/post.
		 *
		 * Restore only missing snapshot rows. A duplicate primary/remote key is an
		 * atomic no-op, preserving the current row exactly. Rows created after an
		 * empty snapshot are likewise left untouched.
		 */
		$table      = self::table_name();
		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || self::TYPE_VARIATION !== sanitize_key( isset( $row['object_type'] ) ? $row['object_type'] : '' ) || absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 ) !== $post_id ) {
				return false;
			}

			$id          = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			$remote_guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			if ( $id <= 0 || '' === $remote_guid ) {
				return false;
			}

			$normalized[] = array(
				'id'                 => $id,
				'remote_guid'        => $remote_guid,
				'wp_post_id'         => $post_id,
				'object_type'        => self::TYPE_VARIATION,
				'parent_remote_guid' => sanitize_text_field( (string) ( isset( $row['parent_remote_guid'] ) ? $row['parent_remote_guid'] : '' ) ),
				'last_hash'          => sanitize_text_field( (string) ( isset( $row['last_hash'] ) ? $row['last_hash'] : '' ) ),
				'sync_incomplete'    => ! empty( $row['sync_incomplete'] ) ? 1 : 0,
				'created_at'         => sanitize_text_field( (string) ( isset( $row['created_at'] ) ? $row['created_at'] : current_time( 'mysql', true ) ) ),
				'updated_at'         => sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : current_time( 'mysql', true ) ) ),
			);
		}

		foreach ( $normalized as $snapshot ) {
			$sql = "INSERT INTO {$table} (id, connection_id, remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at) VALUES (%d, %d, %s, %d, %s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE id = id";
			$result = $wpdb->query(
				$wpdb->prepare(
					$sql,
					$snapshot['id'],
					$connection_id,
					$snapshot['remote_guid'],
					$snapshot['wp_post_id'],
					$snapshot['object_type'],
					$snapshot['parent_remote_guid'],
					$snapshot['last_hash'],
					$snapshot['sync_incomplete'],
					$snapshot['created_at'],
					$snapshot['updated_at']
				)
			);
			if ( false === $result ) {
				return false;
			}
		}

		self::$lookup_cache = array();
		self::$status_cache = array();

		/*
		 * Verify that every snapshot GUID still has durable variation-map evidence.
		 * Exact snapshot state means it was restored/already present. A differing
		 * row means a concurrent writer superseded the snapshot and must win.
		 */
		foreach ( $normalized as $snapshot ) {
			$wpdb->last_error = '';
			$current = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
					$snapshot['remote_guid'],
					self::TYPE_VARIATION
				),
				ARRAY_A
			);
			if ( ! is_array( $current ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete only variation rows for one post and verify no variation mapping remains.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return bool
	 */
	public function delete_variation_by_post_id_verified( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! $this->delete_variation_by_post_id( $post_id ) ) {
			return false;
		}
		$rows = $this->snapshot_variation_rows_by_post_id( $post_id );
		return is_array( $rows ) && empty( $rows );
	}

	/**
	 * Delete variation mappings owned by one remote product, optionally keeping
	 * the GUIDs present in the current authoritative snapshot.
	 *
	 * @param string $parent_guid Parent remote product GUID.
	 * @param array  $keep_guids Remote variation GUIDs to retain.
	 * @param string $error Database error message, if any.
	 * @return int Number of deleted rows.
	 */
	public function delete_variations_for_parent( $parent_guid, $keep_guids = array(), &$error = '' ) {
		$error = '';
		global $wpdb;
		$connection_id = self::connection_id();

		$parent_guid = sanitize_text_field( (string) $parent_guid );
		if ( $connection_id <= 0 || '' === $parent_guid ) {
			return 0;
		}

		if ( ! self::table_exists() ) {
			$error = 'Mobo product-map table is unavailable.';
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
				"SELECT id, remote_guid, wp_post_id, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at FROM {$table} WHERE connection_id=%d AND object_type = %s AND BINARY parent_remote_guid = BINARY %s",
				$connection_id,
				self::TYPE_VARIATION,
				$parent_guid
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$error = '' !== (string) $wpdb->last_error ? sanitize_text_field( (string) $wpdb->last_error ) : 'Could not read variation mappings for the parent product.';
			return 0;
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$delete_rows  = array();
		$delete_guids = array();
		foreach ( $rows as $row ) {
			$remote_guid = sanitize_text_field( (string) ( isset( $row['remote_guid'] ) ? $row['remote_guid'] : '' ) );
			if ( '' !== $remote_guid && isset( $keep[ $remote_guid ] ) ) {
				continue;
			}

			$id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			if ( $id > 0 && '' !== $remote_guid ) {
				$delete_rows[] = array(
					'id'                 => $id,
					'remote_guid'        => $remote_guid,
					'wp_post_id'         => absint( isset( $row['wp_post_id'] ) ? $row['wp_post_id'] : 0 ),
					'parent_remote_guid' => sanitize_text_field( (string) ( isset( $row['parent_remote_guid'] ) ? $row['parent_remote_guid'] : '' ) ),
					'last_hash'          => sanitize_text_field( (string) ( isset( $row['last_hash'] ) ? $row['last_hash'] : '' ) ),
					'sync_incomplete'    => ! empty( $row['sync_incomplete'] ) ? 1 : 0,
					'created_at'         => sanitize_text_field( (string) ( isset( $row['created_at'] ) ? $row['created_at'] : '' ) ),
					'updated_at'         => sanitize_text_field( (string) ( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) ),
				);
				$delete_guids[] = $remote_guid;
			}
		}

		if ( empty( $delete_rows ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array_chunk( $delete_rows, 100 ) as $chunk ) {
			$clauses = array();
			$args    = array( $connection_id, self::TYPE_VARIATION );
			foreach ( $chunk as $snapshot ) {
				$clauses[] = '(id = %d AND BINARY remote_guid = BINARY %s AND wp_post_id = %d AND BINARY parent_remote_guid = BINARY %s AND last_hash = %s AND sync_incomplete = %d AND created_at = %s AND updated_at = %s)';
				$args[] = $snapshot['id'];
				$args[] = $snapshot['remote_guid'];
				$args[] = $snapshot['wp_post_id'];
				$args[] = $snapshot['parent_remote_guid'];
				$args[] = $snapshot['last_hash'];
				$args[] = $snapshot['sync_incomplete'];
				$args[] = $snapshot['created_at'];
				$args[] = $snapshot['updated_at'];
			}

			$sql = "DELETE FROM {$table} WHERE connection_id=%d AND object_type = %s AND (" . implode( ' OR ', $clauses ) . ')';
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Clause structure is static; all snapshot values are prepared.
			if ( false === $result ) {
				$error = '' !== (string) $wpdb->last_error ? sanitize_text_field( (string) $wpdb->last_error ) : 'Could not delete stale variation mappings.';
				break;
			}
			$deleted += absint( $result );
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
		$connection_id = self::connection_id();

		$guid        = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );
		if ( $connection_id <= 0 || '' === $guid || ! self::table_exists() ) {
			return false;
		}

		$deleted = $wpdb->delete(
			self::table_name(),
			array(
				'connection_id' => $connection_id,
				'remote_guid' => $guid,
				'object_type' => $object_type,
			),
			array( '%d', '%s', '%s' )
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
		if ( ! self::is_default_connection() ) { return array( 'products' => 0, 'variations' => 0, 'stalled' => false ); }
		global $wpdb;

		$limit = max( 50, min( 2000, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array( 'products' => 0, 'variations' => 0 );
		}

		$this->legacy_seed_stalled = false;
		$products   = $this->seed_products_from_legacy_meta( $limit );
		$product_stalled = $this->legacy_seed_stalled;

		$this->legacy_seed_stalled = false;
		$variations = $this->seed_variations_from_legacy_meta( $limit );
		$variation_stalled = $this->legacy_seed_stalled;
		$stalled = $product_stalled || $variation_stalled;

		if ( ! $stalled && 0 === $products && 0 === $variations ) {
			update_option( 'mobo_core_product_map_seed_completed_at', time(), false );
		}

		return array(
			'products'   => $products,
			'variations' => $variations,
			'stalled'    => $stalled,
		);
	}

	/**
	 * Seed one legacy mapping without overwriting a durable/current mapping.
	 *
	 * Legacy seeding is a bootstrap aid, not a canonical-election mechanism. Existing
	 * rows are therefore preserved exactly. A missing row is inserted only when the
	 * legacy GUID belongs to exactly one live post of the expected type. INSERT IGNORE
	 * closes the race with normal sync: if another writer establishes the mapping after
	 * our pre-check, its row wins and is preserved.
	 *
	 * @param string $guid Remote GUID.
	 * @param int    $post_id Local post ID.
	 * @param string $object_type product|variation.
	 * @param string $parent_guid Parent product GUID for variations.
	 * @param string $last_hash Source hash evidence from legacy post meta.
	 * @param bool   $sync_incomplete Legacy incomplete marker.
	 * @return bool True when safely inserted, preserved, or intentionally skipped.
	 */
	private function seed_legacy_mapping_if_safe( $guid, $post_id, $object_type, $parent_guid = '', $last_hash = '', $sync_incomplete = false ) {
		global $wpdb;
		$connection_id = self::connection_id();

		$guid        = sanitize_text_field( (string) $guid );
		$post_id     = absint( $post_id );
		$object_type = sanitize_key( (string) $object_type );
		$parent_guid = sanitize_text_field( (string) $parent_guid );
		$last_hash   = sanitize_text_field( (string) $last_hash );
		$incomplete  = $sync_incomplete ? 1 : 0;

		if ( $connection_id <= 0 || ! self::is_default_connection() || '' === $guid || $post_id <= 0 || ! in_array( $object_type, array( self::TYPE_PRODUCT, self::TYPE_VARIATION ), true ) || ! self::table_exists() ) {
			return false;
		}

		$post_type = self::TYPE_PRODUCT === $object_type ? 'product' : 'product_variation';
		$meta_key  = self::TYPE_PRODUCT === $object_type ? 'product_guid' : 'variant_guid';
		$lock_guid = self::TYPE_PRODUCT === $object_type ? $guid : $parent_guid;

		/* A variation without durable parent identity cannot participate in the parent lock contract. */
		if ( '' === $lock_guid ) {
			return self::TYPE_VARIATION === $object_type;
		}
		if ( ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			return false;
		}

		$lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $lock_guid, 0, 120 );
		if ( false === $lock ) {
			/* Do not acknowledge the cursor while another writer owns this product. */
			return false;
		}

		$owner_guard = false;
		try {
			/* Revalidate the legacy identity after acquiring the same lock used by sync/Repair. */
			if ( get_post_type( $post_id ) !== $post_type ) {
				return true;
			}
			$status = sanitize_key( (string) get_post_status( $post_id ) );
			if ( in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
				return true;
			}
			$current_guid = sanitize_text_field( (string) get_post_meta( $post_id, $meta_key, true ) );
			if ( '' === $current_guid || ! hash_equals( $guid, $current_guid ) ) {
				return true;
			}

			if ( self::TYPE_VARIATION === $object_type ) {
				$parent_id = absint( wp_get_post_parent_id( $post_id ) );
				if ( $parent_id <= 0 || 'product' !== get_post_type( $parent_id ) ) {
					return true;
				}
				$actual_parent_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
				$variation_parent_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );
				if ( '' === $actual_parent_guid || ! hash_equals( $parent_guid, $actual_parent_guid ) ) {
					return true;
				}
				if ( '' !== $variation_parent_guid && ! hash_equals( $actual_parent_guid, $variation_parent_guid ) ) {
					return true;
				}
			}

			/* Legacy seeding must participate in the same installation-global Woo owner
			 * guard as ordinary sync. A per-Source product lock is not enough. */
			$owner_guard = self::acquire_post_owner_guard( $post_id );
			if ( false === $owner_guard || self::post_owner_conflicts( $post_id ) ) { return false; }
			$table = self::table_name();

			/* Never let migration/maintenance rewrite an existing canonical/durable row. */
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT wp_post_id FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
					$guid,
					$object_type
				),
				ARRAY_A
			);
			if ( is_array( $existing ) ) {
				$cache_key = self::lookup_cache_key( $guid, $object_type );
				self::$lookup_cache[ $cache_key ] = absint( $existing['wp_post_id'] ?? 0 );
				unset( self::$status_cache[ $cache_key ] );
				return true;
			}

			$live_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} gm ON gm.post_id = p.ID AND gm.meta_key = %s
					WHERE p.post_type = %s
					AND p.post_status NOT IN ('trash', 'auto-draft')
					AND gm.meta_value = %s",
					$meta_key,
					$post_type,
					$guid
				)
			);

			if ( null === $live_count ) {
				return false;
			}

			/* Duplicate legacy identity is ambiguous. Leave canonical election to Repair/sync. */
			if ( 1 !== absint( $live_count ) ) {
				return true;
			}

			$now = current_time( 'mysql', true );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
					(connection_id, remote_guid, wp_post_id, object_type, parent_remote_guid, last_hash, sync_incomplete, created_at, updated_at)
					VALUES (%d, %s, %d, %s, %s, %s, %d, %s, %s)",
					$connection_id,
					$guid,
					$post_id,
					$object_type,
					$parent_guid,
					$last_hash,
					$incomplete,
					$now,
					$now
				)
			);
			if ( false === $inserted ) {
				return false;
			}

			$persisted = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT wp_post_id, parent_remote_guid, last_hash, sync_incomplete FROM {$table} WHERE connection_id=%d AND BINARY remote_guid = BINARY %s AND object_type = %s LIMIT 1",
					$connection_id,
					$guid,
					$object_type
				),
				ARRAY_A
			);
			if ( ! is_array( $persisted ) ) {
				/* Includes pathological unique-prefix collision with a different full GUID. */
				return false;
			}

			/* If our INSERT won, its durability evidence must read back exactly. */
			if ( absint( $inserted ) > 0
				&& ( absint( $persisted['wp_post_id'] ?? 0 ) !== $post_id
					|| (string) ( $persisted['parent_remote_guid'] ?? '' ) !== $parent_guid
					|| (string) ( $persisted['last_hash'] ?? '' ) !== $last_hash
					|| absint( $persisted['sync_incomplete'] ?? 0 ) !== $incomplete ) ) {
				return false;
			}

			/* If a concurrent writer won, preserve its row exactly rather than overwriting it. */
			$cache_key = self::lookup_cache_key( $guid, $object_type );
			self::$lookup_cache[ $cache_key ] = absint( $persisted['wp_post_id'] ?? 0 );
			unset( self::$status_cache[ $cache_key ] );
			return true;
		} finally {
			if ( is_array( $owner_guard ) ) { self::release_post_owner_guard( $owner_guard ); }
			Mobo_Core_Product_Concurrency::release_product_lock( $lock );
		}
	}

	/**
	 * Seed product rows.
	 *
	 * @param int $limit Limit.
	 * @return int
	 */
	private function seed_products_from_legacy_meta( $limit ) {
		global $wpdb;
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::is_default_connection() ) { return 0; }

		$cursor = absint( get_option( 'mobo_core_product_map_product_cursor', 0 ) );

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() && class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			$products = Mobo_Core_Storage_Schema::table_name( 'products' );
			$sql = $wpdb->prepare(
				"SELECT p.ID, c.product_guid AS remote_guid FROM {$wpdb->posts} p INNER JOIN {$products} c ON c.woo_product_id=p.ID AND c.connection_id=%d WHERE p.ID>%d AND p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND c.product_guid<>'' ORDER BY p.ID ASC LIMIT %d",
				$connection_id, $cursor, $limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID, pm.meta_value AS remote_guid
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_guid'
				WHERE p.ID > %d
				AND p.post_type = 'product'
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND pm.meta_value <> ''
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor, $limit
			);
		}

		$query_result = $wpdb->query( $sql );
		if ( false === $query_result ) {
			/* get_results() normalizes SQL errors to an empty array; query() preserves failure. */
			$this->legacy_seed_stalled = true;
			return 0;
		}

		if ( ! is_array( $wpdb->last_result ) ) {
			$this->legacy_seed_stalled = true;
			return 0;
		}

		$rows = array();
		foreach ( $wpdb->last_result as $row ) {
			if ( is_object( $row ) ) {
				$rows[] = get_object_vars( $row );
			} elseif ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$count  = 0;
		$last   = $cursor;
		$mapped = $this->mapped_guid_set( $rows, self::TYPE_PRODUCT );

		foreach ( $rows as $row ) {
			$post_id = absint( $row['ID'] );
			$guid    = sanitize_text_field( (string) $row['remote_guid'] );

			if ( $post_id <= 0 || '' === $guid ) {
				$last = max( $last, $post_id );
				continue;
			}

			/* Same outcome as seed_legacy_mapping_if_safe() for an existing row (keep it,
			 * acknowledge the cursor) without its per-row locks and reads. */
			if ( isset( $mapped[ $guid ] ) ) {
				$count++;
				$last = max( $last, $post_id );
				continue;
			}

			$last_hash  = sanitize_text_field( (string) get_post_meta( $post_id, '_mobo_product_source_hash', true ) );
			$incomplete = 1 === absint( get_post_meta( $post_id, 'mobo_sync_incomplete', true ) );

			if ( ! $this->seed_legacy_mapping_if_safe( $guid, $post_id, self::TYPE_PRODUCT, '', $last_hash, $incomplete ) ) {
				$this->legacy_seed_stalled = true;
				break;
			}

			$count++;
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
		$connection_id = self::connection_id();
		if ( $connection_id <= 0 || ! self::is_default_connection() ) { return 0; }

		$cursor = absint( get_option( 'mobo_core_product_map_variation_cursor', 0 ) );

		if ( class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' ) && Mobo_Core_Storage_Identity_Meta_Retirement::is_fenced() && class_exists( 'Mobo_Core_Storage_Schema' ) && Mobo_Core_Storage_Schema::is_ready() ) {
			$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
			$sql = $wpdb->prepare(
				"SELECT p.ID,p.post_parent,c.variant_guid AS remote_guid,c.product_guid AS parent_remote_guid FROM {$wpdb->posts} p INNER JOIN {$variants} c ON c.woo_variation_id=p.ID AND c.connection_id=%d WHERE p.ID>%d AND p.post_type='product_variation' AND p.post_status NOT IN ('trash','auto-draft') AND c.variant_guid<>'' ORDER BY p.ID ASC LIMIT %d",
				$connection_id, $cursor, $limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT p.ID, p.post_parent, vm.meta_value AS remote_guid, pm.meta_value AS parent_remote_guid
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = 'variant_guid'
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_guid'
				WHERE p.ID > %d
				AND p.post_type = 'product_variation'
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND vm.meta_value <> ''
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor, $limit
			);
		}

		$query_result = $wpdb->query( $sql );
		if ( false === $query_result ) {
			/* get_results() normalizes SQL errors to an empty array; query() preserves failure. */
			$this->legacy_seed_stalled = true;
			return 0;
		}

		if ( ! is_array( $wpdb->last_result ) ) {
			$this->legacy_seed_stalled = true;
			return 0;
		}

		$rows = array();
		foreach ( $wpdb->last_result as $row ) {
			if ( is_object( $row ) ) {
				$rows[] = get_object_vars( $row );
			} elseif ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$count  = 0;
		$last   = $cursor;
		$mapped = $this->mapped_guid_set( $rows, self::TYPE_VARIATION );

		foreach ( $rows as $row ) {
			$post_id     = absint( $row['ID'] );
			$guid        = sanitize_text_field( (string) $row['remote_guid'] );
			$parent_guid = sanitize_text_field( (string) $row['parent_remote_guid'] );

			if ( $post_id <= 0 || '' === $guid ) {
				$last = max( $last, $post_id );
				continue;
			}

			/* Already mapped: the per-row path would preserve the row and return true. */
			if ( isset( $mapped[ $guid ] ) ) {
				$count++;
				$last = max( $last, $post_id );
				continue;
			}

			if ( '' === $parent_guid ) {
				$parent_id = absint( $row['post_parent'] ?? 0 );
				if ( $parent_id > 0 ) {
					$parent_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
				}
			}

			$last_hash  = sanitize_text_field( (string) get_post_meta( $post_id, '_mobo_variant_source_hash', true ) );
			$incomplete = 1 === absint( get_post_meta( $post_id, 'mobo_sync_incomplete', true ) );

			if ( ! $this->seed_legacy_mapping_if_safe( $guid, $post_id, self::TYPE_VARIATION, $parent_guid, $last_hash, $incomplete ) ) {
				$this->legacy_seed_stalled = true;
				break;
			}

			$count++;
			$last = max( $last, $post_id );
		}

		update_option( 'mobo_core_product_map_variation_cursor', $last, false );

		return $count;
	}

	/**
	 * GUIDs of one seed batch that already have a map row for this Connection and
	 * object type (exact, case-sensitive match), in one indexed query. An existing
	 * row is never rewritten by the seed, so such rows need no per-row lock/proof;
	 * a complete map is therefore re-validated at query speed. On a read error the
	 * set is empty and every row takes the original safe per-row path.
	 *
	 * @param array  $rows        Seed rows with remote_guid.
	 * @param string $object_type product|variation.
	 * @return array<string,bool>
	 */
	private function mapped_guid_set( $rows, $object_type ) {
		global $wpdb;
		$connection_id = self::connection_id();
		$guids = array();
		foreach ( (array) $rows as $row ) {
			$guid = sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) );
			if ( '' !== $guid ) {
				$guids[ $guid ] = true;
			}
		}
		if ( $connection_id <= 0 || empty( $guids ) ) {
			return array();
		}
		$list = array_keys( $guids );
		$placeholders = implode( ',', array_fill( 0, count( $list ), '%s' ) );
		$table = self::table_name();
		$wpdb->last_error = '';
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT remote_guid FROM {$table} WHERE connection_id=%d AND object_type=%s AND remote_guid IN ({$placeholders})",
				array_merge( array( $connection_id, sanitize_key( (string) $object_type ) ), $list )
			)
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $found ) ) {
			return array();
		}
		return self::exact_guid_intersection( $list, $found );
	}

	/**
	 * Pure helper (unit tested): exact, case-sensitive intersection; collation-level
	 * matches that differ in case/trailing spaces are not treated as mapped.
	 *
	 * @param string[] $wanted Batch GUIDs.
	 * @param string[] $found  GUIDs returned by SQL.
	 * @return array<string,bool>
	 */
	public static function exact_guid_intersection( $wanted, $found ) {
		$found_set = array();
		foreach ( (array) $found as $guid ) {
			$found_set[ (string) $guid ] = true;
		}
		$out = array();
		foreach ( (array) $wanted as $guid ) {
			$guid = (string) $guid;
			if ( isset( $found_set[ $guid ] ) ) {
				$out[ $guid ] = true;
			}
		}
		return $out;
	}

}
