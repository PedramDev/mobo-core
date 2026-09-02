<?php
/**
 * Remote category GUID to local WooCommerce category map.
 *
 * This table is a safe layer over legacy term meta:
 * - category_guid on product_cat terms remains supported.
 * - manual mappings are preferred only during product assignment.
 * - category sync always updates the synced term, not the manually mapped term.
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
class Mobo_Core_Category_Map {

	/** @var bool|null Request-local table existence cache. */
	private static $table_exists_cache = null;

	/** @var array Request-local GUID/column lookup cache, including misses. */
	private $term_lookup_cache = array();

	/** @var array Request-local product_cat existence cache by term ID. */
	private $term_exists_cache = array();

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mobo_category_map';
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
			manual_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
			synced_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
			remote_name varchar(255) NOT NULL DEFAULT '',
			remote_slug varchar(191) NOT NULL DEFAULT '',
			remote_url text NULL,
			parent_remote_guid varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY remote_guid (remote_guid),
			KEY manual_term_id (manual_term_id),
			KEY synced_term_id (synced_term_id),
			KEY parent_remote_guid (parent_remote_guid)
		) {$charset_collate};";

		dbDelta( $sql );
		self::$table_exists_cache = null;
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
		self::$table_exists_cache = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		return (bool) self::$table_exists_cache;
	}

	/**
	 * Get manually mapped local term ID.
	 *
	 * @param string $guid Remote category GUID.
	 * @return int
	 */
	public function get_manual_term_id( $guid ) {
		return $this->get_term_id_by_column( $guid, 'manual_term_id', true );
	}


	/**
	 * Get manually mapped local term ID using GUID identifiers only.
	 *
	 * URL/path/slug are stored for display and diagnostics, not identity.
	 *
	 * @param array $identifiers Candidate remote GUID identifiers.
	 * @return int
	 */
	public function get_manual_term_id_by_identifiers( $identifiers ) {
		return $this->get_term_id_by_identifiers( $identifiers, 'manual_term_id', true );
	}

	/**
	 * Get synced local term ID.
	 *
	 * @param string $guid Remote category GUID.
	 * @return int
	 */
	public function get_synced_term_id( $guid ) {
		return $this->get_term_id_by_column( $guid, 'synced_term_id', false );
	}


	/**
	 * Get synced local term ID using GUID identifiers only.
	 *
	 * @param array $identifiers Candidate remote GUID identifiers.
	 * @return int
	 */
	public function get_synced_term_id_by_identifiers( $identifiers ) {
		return $this->get_term_id_by_identifiers( $identifiers, 'synced_term_id', false );
	}

	/**
	 * Get best assignment term: manual first, synced fallback.
	 *
	 * @param string $guid Remote category GUID.
	 * @return array
	 */
	public function resolve_assignment_term( $guid ) {
		$manual = $this->get_manual_term_id( $guid );

		if ( $manual > 0 ) {
			return array(
				'term_id' => $manual,
				'source'  => 'mapped',
			);
		}

		$synced = $this->get_synced_term_id( $guid );

		if ( $synced > 0 ) {
			return array(
				'term_id' => $synced,
				'source'  => 'synced',
			);
		}

		return array(
			'term_id' => 0,
			'source'  => 'missing',
		);
	}


	/**
	 * Get best assignment term using GUID identifiers: manual first, synced fallback.
	 *
	 * @param array $identifiers Candidate remote GUID identifiers.
	 * @return array
	 */
	public function resolve_assignment_term_by_identifiers( $identifiers ) {
		$manual = $this->get_manual_term_id_by_identifiers( $identifiers );

		if ( $manual > 0 ) {
			return array(
				'term_id' => $manual,
				'source'  => 'mapped',
			);
		}

		$synced = $this->get_synced_term_id_by_identifiers( $identifiers );

		if ( $synced > 0 ) {
			return array(
				'term_id' => $synced,
				'source'  => 'synced',
			);
		}

		return array(
			'term_id' => 0,
			'source'  => 'missing',
		);
	}


	/**
	 * Upsert remote category metadata for mapping only.
	 *
	 * This must not create, update, or assign WooCommerce product_cat terms.
	 * It only prepares rows in the mapping table so the admin can choose
	 * local categories before product sync.
	 *
	 * @param string $guid Remote GUID.
	 * @param string $name Remote name.
	 * @param string $url Remote URL/path.
	 * @param string $parent_guid Parent remote GUID.
	 * @return array
	 */
	public function upsert_remote_category_for_mapping( $guid, $name = '', $url = '', $parent_guid = '' ) {
		global $wpdb;

		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid || ! self::table_exists() ) {
			return array(
				'success' => false,
				'created' => false,
			);
		}

		$now                = current_time( 'mysql', true );
		$table              = self::table_name();
		$remote_name        = sanitize_text_field( (string) $name );
		$remote_url         = sanitize_text_field( (string) $url );
		$remote_slug        = sanitize_title( $this->slug_from_url( $remote_url ) );
		$parent_remote_guid = sanitize_text_field( (string) $parent_guid );

		/*
		 * Empty metadata means "preserve what is already known", not "write the
		 * value seen by an earlier SELECT". Perform the merge inside the atomic
		 * upsert so a partial product payload cannot replay a stale metadata
		 * snapshot over a newer category payload that committed concurrently.
		 * Manual/synced term ownership is intentionally untouched on duplicate.
		 */
		$query = "INSERT INTO {$table}
			(remote_guid, manual_term_id, synced_term_id, remote_name, remote_slug, remote_url, parent_remote_guid, created_at, updated_at)
			VALUES (%s, 0, 0, %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				remote_name = IF(VALUES(remote_name) = '', remote_name, VALUES(remote_name)),
				remote_slug = IF(VALUES(remote_slug) = '', remote_slug, VALUES(remote_slug)),
				remote_url = IF(VALUES(remote_url) = '', remote_url, VALUES(remote_url)),
				parent_remote_guid = IF(VALUES(parent_remote_guid) = '', parent_remote_guid, VALUES(parent_remote_guid)),
				updated_at = IF(VALUES(updated_at) > updated_at, VALUES(updated_at), updated_at)";

		$updated = $wpdb->query(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is static and only values are variable.
				$guid,
				$remote_name,
				$remote_slug,
				$remote_url,
				$parent_remote_guid,
				$now,
				$now
			)
		);

		if ( false === $updated ) {
			return array(
				'success' => false,
				'created' => false,
			);
		}

		/*
		 * Verify only fields this payload explicitly owns. Empty fields are
		 * preserve-intent and may legitimately contain a newer concurrent value.
		 */
		$expected = array( 'remote_guid' => $guid );
		if ( '' !== $remote_name ) {
			$expected['remote_name'] = $remote_name;
		}
		if ( '' !== $remote_slug ) {
			$expected['remote_slug'] = $remote_slug;
		}
		if ( '' !== $remote_url ) {
			$expected['remote_url'] = $remote_url;
		}
		if ( '' !== $parent_remote_guid ) {
			$expected['parent_remote_guid'] = $parent_remote_guid;
		}

		$success = $this->verify_mapping_row( $guid, $expected );

		return array(
			'success' => $success,
			'created' => $success && 1 === (int) $updated,
		);
	}

	/**
	 * Upsert synced category metadata. Manual mapping is preserved.
	 *
	 * @param string $guid Remote GUID.
	 * @param int    $synced_term_id Synced Woo term ID.
	 * @param string $name Remote name.
	 * @param string $url Remote URL/path.
	 * @param string $parent_guid Parent remote GUID.
	 * @return bool
	 */
	public function upsert_synced_category( $guid, $synced_term_id, $name = '', $url = '', $parent_guid = '' ) {
		global $wpdb;

		$guid           = sanitize_text_field( (string) $guid );
		$synced_term_id = absint( $synced_term_id );

		if ( '' === $guid || $synced_term_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$now                = current_time( 'mysql', true );
		$table              = self::table_name();
		$remote_name        = sanitize_text_field( (string) $name );
		$remote_url         = sanitize_text_field( (string) $url );
		$remote_slug        = sanitize_title( $this->slug_from_url( $remote_url ) );
		$parent_remote_guid = sanitize_text_field( (string) $parent_guid );

		/*
		 * synced_term_id belongs to this sync write, while empty metadata fields
		 * mean preserve-current. Merge those fields atomically in MySQL so an old
		 * partial payload cannot clobber fresher category metadata read after it.
		 * Manual mapping remains untouched on duplicate.
		 */
		$query = "INSERT INTO {$table}
			(remote_guid, manual_term_id, synced_term_id, remote_name, remote_slug, remote_url, parent_remote_guid, created_at, updated_at)
			VALUES (%s, 0, %d, %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				synced_term_id = VALUES(synced_term_id),
				remote_name = IF(VALUES(remote_name) = '', remote_name, VALUES(remote_name)),
				remote_slug = IF(VALUES(remote_slug) = '', remote_slug, VALUES(remote_slug)),
				remote_url = IF(VALUES(remote_url) = '', remote_url, VALUES(remote_url)),
				parent_remote_guid = IF(VALUES(parent_remote_guid) = '', parent_remote_guid, VALUES(parent_remote_guid)),
				updated_at = IF(VALUES(updated_at) > updated_at, VALUES(updated_at), updated_at)";

		$updated = $wpdb->query(
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query structure is static and only values are variable.
				$guid,
				$synced_term_id,
				$remote_name,
				$remote_slug,
				$remote_url,
				$parent_remote_guid,
				$now,
				$now
			)
		);

		if ( false === $updated ) {
			return false;
		}

		$expected = array(
			'remote_guid'    => $guid,
			'synced_term_id' => $synced_term_id,
		);
		if ( '' !== $remote_name ) {
			$expected['remote_name'] = $remote_name;
		}
		if ( '' !== $remote_slug ) {
			$expected['remote_slug'] = $remote_slug;
		}
		if ( '' !== $remote_url ) {
			$expected['remote_url'] = $remote_url;
		}
		if ( '' !== $parent_remote_guid ) {
			$expected['parent_remote_guid'] = $parent_remote_guid;
		}

		$success = $this->verify_mapping_row( $guid, $expected );
		if ( $success ) {
			$this->term_lookup_cache[ 'synced_term_id|' . $guid ] = $synced_term_id;
		}
		return $success;
	}

	/**
	 * Update manual mapping. 0 clears manual mapping and keeps synced fallback.
	 *
	 * @param string $guid Remote GUID.
	 * @param int    $term_id Local Woo product_cat term ID.
	 * @return bool
	 */
	public function update_manual_mapping( $guid, $term_id ) {
		global $wpdb;

		$guid    = sanitize_text_field( (string) $guid );
		$term_id = absint( $term_id );

		if ( '' === $guid || ! self::table_exists() ) {
			return false;
		}

		if ( $term_id > 0 && ! $this->term_exists( $term_id ) ) {
			$term_id = 0;
		}

		$now   = current_time( 'mysql', true );
		$table = self::table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE remote_guid = %s LIMIT 1",
				$guid
			)
		);

		if ( $existing_id ) {
			$success = false !== $wpdb->update(
				$table,
				array(
					'manual_term_id' => $term_id,
					'updated_at'     => $now,
				),
				array( 'id' => absint( $existing_id ) ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$success = false !== $wpdb->insert(
				$table,
				array(
					'remote_guid'    => $guid,
					'manual_term_id' => $term_id,
					'synced_term_id' => 0,
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%s', '%d', '%d', '%s', '%s' )
			);
		}

		if ( $success ) {
			$success = $this->verify_mapping_row(
				$guid,
				array(
					'manual_term_id' => $term_id,
				)
			);
		}
		if ( $success ) {
			$this->term_lookup_cache[ 'manual_term_id|' . $guid ] = $term_id;
		}
		return $success;
	}

	/**
	 * Verify a category-map write by reading the canonical row back from MySQL.
	 *
	 * A successful wpdb write call only proves that MySQL accepted the statement;
	 * it does not prove that the row now contains the identity/mapping state this
	 * request intends to advertise to product assignment and admin mapping code.
	 *
	 * @param string $guid Remote category GUID.
	 * @param array  $expected Expected persisted columns (subset is allowed).
	 * @return bool
	 */
	private function verify_mapping_row( $guid, $expected ) {
		global $wpdb;

		$guid = sanitize_text_field( (string) $guid );
		if ( '' === $guid || ! is_array( $expected ) || empty( $expected ) || ! self::table_exists() ) {
			return false;
		}

		$allowed = array(
			'remote_guid',
			'manual_term_id',
			'synced_term_id',
			'remote_name',
			'remote_slug',
			'remote_url',
			'parent_remote_guid',
		);
		$columns = array_values( array_intersect( array_keys( $expected ), $allowed ) );
		if ( empty( $columns ) ) {
			return false;
		}

		$select_columns = array_unique( array_merge( array( 'remote_guid' ), $columns ) );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . implode( ', ', $select_columns ) . ' FROM ' . self::table_name() . ' WHERE remote_guid = %s LIMIT 1',
				$guid
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! isset( $row['remote_guid'] ) || $guid !== (string) $row['remote_guid'] ) {
			return false;
		}

		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return false;
			}
			if ( in_array( $column, array( 'manual_term_id', 'synced_term_id' ), true ) ) {
				if ( absint( $row[ $column ] ) !== absint( $expected[ $column ] ) ) {
					return false;
				}
			} elseif ( (string) $row[ $column ] !== (string) $expected[ $column ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * List mappings for admin UI.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function list_mappings( $limit = 500 ) {
		global $wpdb;

		$limit = max( 1, min( 2000, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY remote_name ASC, id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$manual_id = absint( isset( $row['manual_term_id'] ) ? $row['manual_term_id'] : 0 );
			$synced_id = absint( isset( $row['synced_term_id'] ) ? $row['synced_term_id'] : 0 );

			$rows[ $index ]['manual_term_name'] = $manual_id > 0 ? $this->get_term_name( $manual_id ) : '';
			$rows[ $index ]['synced_term_name'] = $synced_id > 0 ? $this->get_term_name( $synced_id ) : '';
		}

		return $rows;
	}

	/**
	 * Seed one legacy category mapping without overwriting an existing canonical row.
	 *
	 * Legacy term meta is bootstrap evidence only. It must not elect a canonical term
	 * when the same remote GUID exists on more than one product_cat term, and it must
	 * never overwrite a row already established by normal sync or manual mapping.
	 * INSERT IGNORE closes the race with a concurrent category-sync writer: whichever
	 * writer establishes the unique remote_guid first wins, and this legacy path never
	 * rewrites that winner afterwards.
	 *
	 * @param string $guid Remote category GUID.
	 * @param int    $term_id Local product_cat term ID.
	 * @return bool True when safely inserted, preserved, or intentionally skipped.
	 */
	private function seed_legacy_category_if_safe( $guid, $term_id ) {
		global $wpdb;

		$guid    = sanitize_text_field( (string) $guid );
		$term_id = absint( $term_id );
		if ( '' === $guid || $term_id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
			return true;
		}

		$current_guid = sanitize_text_field( (string) get_term_meta( $term_id, 'category_guid', true ) );
		if ( '' === $current_guid || ! hash_equals( $guid, $current_guid ) ) {
			return true;
		}

		$table = self::table_name();

		/* Existing rows are durable/current evidence and are preserved exactly. */
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, manual_term_id, synced_term_id FROM {$table} WHERE remote_guid = %s LIMIT 1",
				$guid
			),
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			return true;
		}

		$live_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT t.term_id)
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
				INNER JOIN {$wpdb->termmeta} gm ON gm.term_id = t.term_id AND gm.meta_key = 'category_guid'
				WHERE gm.meta_value = %s",
				$guid
			)
		);
		if ( null === $live_count ) {
			return false;
		}

		/* Ambiguous legacy identity is intentionally skipped; Repair/sync owns election. */
		if ( 1 !== absint( $live_count ) ) {
			return true;
		}

		$remote_name        = sanitize_text_field( (string) $term->name );
		$remote_url         = sanitize_text_field( (string) get_term_meta( $term_id, 'mobo_category_url', true ) );
		$remote_slug        = sanitize_title( $this->slug_from_url( $remote_url ) );
		$parent_remote_guid = sanitize_text_field( (string) get_term_meta( $term_id, 'mobo_parent_category_guid', true ) );
		$now                = current_time( 'mysql', true );

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				(remote_guid, manual_term_id, synced_term_id, remote_name, remote_slug, remote_url, parent_remote_guid, created_at, updated_at)
				VALUES (%s, 0, %d, %s, %s, %s, %s, %s, %s)",
				$guid,
				$term_id,
				$remote_name,
				$remote_slug,
				$remote_url,
				$parent_remote_guid,
				$now,
				$now
			)
		);
		if ( false === $inserted ) {
			return false;
		}

		$persisted = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT manual_term_id, synced_term_id, remote_name, remote_slug, remote_url, parent_remote_guid FROM {$table} WHERE remote_guid = %s LIMIT 1",
				$guid
			),
			ARRAY_A
		);
		if ( ! is_array( $persisted ) ) {
			return false;
		}

		/* If our INSERT won, require exact read-back. Concurrent winners are preserved. */
		if ( absint( $inserted ) > 0
			&& ( 0 !== absint( $persisted['manual_term_id'] ?? 0 )
				|| $term_id !== absint( $persisted['synced_term_id'] ?? 0 )
				|| $remote_name !== (string) ( $persisted['remote_name'] ?? '' )
				|| $remote_slug !== (string) ( $persisted['remote_slug'] ?? '' )
				|| $remote_url !== (string) ( $persisted['remote_url'] ?? '' )
				|| $parent_remote_guid !== (string) ( $persisted['parent_remote_guid'] ?? '' ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Seed map from legacy term meta category_guid.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public function seed_from_legacy_term_meta( $limit = 500 ) {
		global $wpdb;

		$limit = max( 50, min( 2000, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array( 'categories' => 0 );
		}

		$cursor = absint( get_option( 'mobo_core_category_map_cursor', 0 ) );

		$sql = $wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, guid_meta.meta_value AS remote_guid,
				url_meta.meta_value AS remote_url,
				parent_meta.meta_value AS parent_remote_guid
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
			INNER JOIN {$wpdb->termmeta} guid_meta ON guid_meta.term_id = t.term_id AND guid_meta.meta_key = 'category_guid'
			LEFT JOIN {$wpdb->termmeta} url_meta ON url_meta.term_id = t.term_id AND url_meta.meta_key = 'mobo_category_url'
			LEFT JOIN {$wpdb->termmeta} parent_meta ON parent_meta.term_id = t.term_id AND parent_meta.meta_key = 'mobo_parent_category_guid'
			WHERE t.term_id > %d
			AND guid_meta.meta_value <> ''
			ORDER BY t.term_id ASC
			LIMIT %d",
			$cursor,
			$limit
		);

		$query_result = $wpdb->query( $sql );
		if ( false === $query_result ) {
			return array(
				'categories' => 0,
				'stalled'    => true,
				'error'      => 'Category Map legacy seed database read failed; cursor and completion remain retryable.',
				'cursor'     => $cursor,
			);
		}

		if ( ! is_array( $wpdb->last_result ) ) {
			return array(
				'categories' => 0,
				'stalled'    => true,
				'error'      => 'Category Map legacy seed result could not be read; cursor and completion remain retryable.',
				'cursor'     => $cursor,
			);
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
			update_option( 'mobo_core_category_map_seed_completed_at', time(), false );
			return array(
				'categories' => 0,
				'stalled'    => false,
				'error'      => '',
				'cursor'     => $cursor,
			);
		}

		$count   = 0;
		$last    = $cursor;
		$stalled = false;
		$error   = '';

		foreach ( $rows as $row ) {
			$term_id = absint( $row['term_id'] );
			$guid    = sanitize_text_field( (string) $row['remote_guid'] );

			if ( $term_id <= 0 || '' === $guid ) {
				$last = max( $last, $term_id );
				continue;
			}

			if ( ! $this->seed_legacy_category_if_safe( $guid, $term_id ) ) {
				$stalled = true;
				$error   = 'Category Map legacy seed stalled because safe bootstrap persistence could not be proven.';
				break;
			}

			$count++;
			$last = max( $last, $term_id );
		}

		update_option( 'mobo_core_category_map_cursor', $last, false );

		return array(
			'categories' => $count,
			'stalled'    => $stalled,
			'error'      => $error,
			'cursor'     => $last,
		);
	}


	/**
	 * Get term ID by GUID identifiers and validate existence.
	 *
	 * GUID is the only valid remote identity. URL/path/slug are stored only for
	 * display and diagnostics; they must never be used to match a category.
	 *
	 * @param array  $identifiers Candidate identifiers.
	 * @param string $column manual_term_id or synced_term_id.
	 * @param bool   $clear_stale Clear stale manual mapping.
	 * @return int
	 */
	private function get_term_id_by_identifiers( $identifiers, $column, $clear_stale ) {
		$column = sanitize_key( (string) $column );

		if ( ! in_array( $column, array( 'manual_term_id', 'synced_term_id' ), true ) || ! self::table_exists() ) {
			return 0;
		}

		$normalized = $this->normalize_identifiers( $identifiers );

		if ( empty( $normalized['values'] ) ) {
			return 0;
		}

		foreach ( $normalized['values'] as $identifier ) {
			$term_id = $this->get_term_id_by_column( $identifier, $column, $clear_stale );
			if ( $term_id > 0 ) {
				return $term_id;
			}
		}

		return 0;
	}

	/**
	 * Validate a map row term ID and optionally clear stale mappings.
	 *
	 * Kept for backward compatibility with older code paths, but GUID-only
	 * matching above does not query URL/path/slug rows.
	 *
	 * @param mixed  $row Row.
	 * @param string $column Column name.
	 * @param bool   $clear_stale Clear stale values.
	 * @return int
	 */
	private function validate_identifier_row( $row, $column, $clear_stale ) {
		global $wpdb;

		if ( ! is_array( $row ) || empty( $row['term_id'] ) ) {
			return 0;
		}

		$term_id = absint( $row['term_id'] );

		if ( $term_id <= 0 ) {
			return 0;
		}

		if ( $this->term_exists( $term_id ) ) {
			return $term_id;
		}

		if ( $clear_stale && ! empty( $row['id'] ) ) {
			$clear_result = $this->clear_stale_term_id_if_unchanged( absint( $row['id'] ), $column, $term_id );
			if ( 1 !== $clear_result ) {
				$current_term_id = $this->read_current_term_id_by_row( absint( $row['id'] ), $column );
				if ( $current_term_id > 0 && $this->term_exists( $current_term_id ) ) {
					return $current_term_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Clear a stale term mapping only if the observed value is still unchanged.
	 *
	 * @param int    $row_id Map row ID.
	 * @param string $column manual_term_id or synced_term_id.
	 * @param int    $term_id Stale term ID observed by the lookup.
	 * @return int|false Number of updated rows, or false on SQL failure.
	 */
	private function clear_stale_term_id_if_unchanged( $row_id, $column, $term_id ) {
		global $wpdb;

		$row_id  = absint( $row_id );
		$column  = sanitize_key( (string) $column );
		$term_id = absint( $term_id );

		if ( $row_id <= 0 || $term_id <= 0 || ! in_array( $column, array( 'manual_term_id', 'synced_term_id' ), true ) || ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$sql   = 'manual_term_id' === $column
			? "UPDATE {$table} SET manual_term_id = 0, updated_at = %s WHERE id = %d AND manual_term_id = %d"
			: "UPDATE {$table} SET synced_term_id = 0, updated_at = %s WHERE id = %d AND synced_term_id = %d";

		return $wpdb->query(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is selected from two fixed internal statements above.
				current_time( 'mysql', true ),
				$row_id,
				$term_id
			)
		);
	}

	/**
	 * Re-read a term mapping after a compare-and-clear miss.
	 *
	 * @param int    $row_id Map row ID.
	 * @param string $column manual_term_id or synced_term_id.
	 * @return int
	 */
	private function read_current_term_id_by_row( $row_id, $column ) {
		global $wpdb;

		$row_id = absint( $row_id );
		$column = sanitize_key( (string) $column );

		if ( $row_id <= 0 || ! in_array( $column, array( 'manual_term_id', 'synced_term_id' ), true ) || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$sql   = 'manual_term_id' === $column
			? "SELECT manual_term_id FROM {$table} WHERE id = %d LIMIT 1"
			: "SELECT synced_term_id FROM {$table} WHERE id = %d LIMIT 1";

		return absint(
			$wpdb->get_var(
				$wpdb->prepare( $sql, $row_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is selected from two fixed internal statements above.
			)
		);
	}

	/**
	 * Normalize GUID identifiers.
	 *
	 * URL/path/slug are intentionally ignored here. They are presentation or
	 * routing values, not durable identity keys.
	 *
	 * @param mixed $identifiers Identifiers.
	 * @return array
	 */
	private function normalize_identifiers( $identifiers ) {
		if ( ! is_array( $identifiers ) ) {
			$identifiers = array( $identifiers );
		}

		$values = array();

		foreach ( $identifiers as $identifier ) {
			$identifier = sanitize_text_field( (string) $identifier );
			$identifier = trim( $identifier );

			if ( '' === $identifier || ! $this->is_remote_guid_value( $identifier ) ) {
				continue;
			}

			$values[] = $identifier;
		}

		$values = array_values( array_unique( array_filter( $values ) ) );

		return array(
			'values' => $values,
			'slugs'  => array(),
		);
	}

	/**
	 * Check whether a value is usable as a remote GUID.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_remote_guid_value( $value ) {
	return Mobo_Core_Remote_Identity_Policy::is_valid( $value );
}

	/**
	 * Get term ID by table column and validate existence.
	 *
	 * @param string $guid Remote category GUID.
	 * @param string $column Column name.
	 * @param bool   $clear_stale Clear stale value.
	 * @return int
	 */
	private function get_term_id_by_column( $guid, $column, $clear_stale ) {
		global $wpdb;

		$guid   = sanitize_text_field( (string) $guid );
		$column = sanitize_key( (string) $column );

		if ( '' === $guid || ! in_array( $column, array( 'manual_term_id', 'synced_term_id' ), true ) || ! self::table_exists() ) {
			return 0;
		}

		$cache_key = $column . '|' . $guid;
		if ( array_key_exists( $cache_key, $this->term_lookup_cache ) ) {
			return absint( $this->term_lookup_cache[ $cache_key ] );
		}

		$table = self::table_name();
		$sql   = 'manual_term_id' === $column
			? "SELECT id, manual_term_id AS term_id FROM {$table} WHERE remote_guid = %s LIMIT 1"
			: "SELECT id, synced_term_id AS term_id FROM {$table} WHERE remote_guid = %s LIMIT 1";
		$row   = $wpdb->get_row(
			$wpdb->prepare( $sql, $guid ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is selected from two fixed internal statements above.
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['term_id'] ) ) {
			return 0;
		}

		$term_id = absint( $row['term_id'] );

		if ( $term_id <= 0 ) {
			$this->term_lookup_cache[ $cache_key ] = 0;
			return 0;
		}

		if ( ! $this->term_exists( $term_id ) ) {
			if ( $clear_stale ) {
				$clear_result = $this->clear_stale_term_id_if_unchanged( absint( $row['id'] ), $column, $term_id );
				if ( 1 !== $clear_result ) {
					$current_term_id = $this->read_current_term_id_by_row( absint( $row['id'] ), $column );
					if ( $current_term_id > 0 && $this->term_exists( $current_term_id ) ) {
						$this->term_lookup_cache[ $cache_key ] = $current_term_id;
						return $current_term_id;
					}
				}
			}

			$this->term_lookup_cache[ $cache_key ] = 0;
			return 0;
		}

		$this->term_lookup_cache[ $cache_key ] = $term_id;
		return $term_id;
	}

	/**
	 * Check term exists as product_cat.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	private function term_exists( $term_id ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return false;
		}
		if ( array_key_exists( $term_id, $this->term_exists_cache ) ) {
			return (bool) $this->term_exists_cache[ $term_id ];
		}

		$term = get_term( $term_id, 'product_cat' );
		$this->term_exists_cache[ $term_id ] = ( $term instanceof WP_Term && ! is_wp_error( $term ) );
		return (bool) $this->term_exists_cache[ $term_id ];
	}

	/**
	 * Get term name.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private function get_term_name( $term_id ) {
		$term = get_term( absint( $term_id ), 'product_cat' );

		if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
			return '';
		}

		return $term->name;
	}

	/**
	 * Slug from remote URL/path.
	 *
	 * @param string $url URL/path.
	 * @return string
	 */
	private function slug_from_url( $url ) {
		$url = sanitize_text_field( (string) $url );
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = $url;
		}

		$path  = trim( $path, '/' );
		$parts = array_filter( explode( '/', $path ) );

		if ( empty( $parts ) ) {
			return '';
		}

		$last = end( $parts );

		return sanitize_title( $last );
	}
}
