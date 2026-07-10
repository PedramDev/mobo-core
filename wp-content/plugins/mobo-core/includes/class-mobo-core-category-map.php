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

		$now   = current_time( 'mysql', true );
		$table = self::table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE remote_guid = %s LIMIT 1",
				$guid
			)
		);

		$data = array(
			'remote_guid'        => $guid,
			'remote_name'        => sanitize_text_field( (string) $name ),
			'remote_slug'        => sanitize_title( $this->slug_from_url( $url ) ),
			'remote_url'         => sanitize_text_field( (string) $url ),
			'parent_remote_guid' => sanitize_text_field( (string) $parent_guid ),
			'updated_at'         => $now,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing_id ) {
			$success = false !== $wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ), $formats, array( '%d' ) );

			return array(
				'success' => $success,
				'created' => false,
			);
		}

		$data['manual_term_id'] = 0;
		$data['synced_term_id'] = 0;
		$data['created_at']     = $now;
		$formats[]              = '%d';
		$formats[]              = '%d';
		$formats[]              = '%s';

		$success = false !== $wpdb->insert( $table, $data, $formats );

		return array(
			'success' => $success,
			'created' => $success,
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

		$now   = current_time( 'mysql', true );
		$table = self::table_name();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE remote_guid = %s LIMIT 1",
				$guid
			)
		);

		$data = array(
			'remote_guid'        => $guid,
			'synced_term_id'     => $synced_term_id,
			'remote_name'        => sanitize_text_field( (string) $name ),
			'remote_slug'        => sanitize_title( $this->slug_from_url( $url ) ),
			'remote_url'         => sanitize_text_field( (string) $url ),
			'parent_remote_guid' => sanitize_text_field( (string) $parent_guid ),
			'updated_at'         => $now,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing_id ) {
			return false !== $wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ), $formats, array( '%d' ) );
		}

		$data['manual_term_id'] = 0;
		$data['created_at']     = $now;
		$formats[]              = '%d';
		$formats[]              = '%s';

		return false !== $wpdb->insert( $table, $data, $formats );
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
			return false !== $wpdb->update(
				$table,
				array(
					'manual_term_id' => $term_id,
					'updated_at'     => $now,
				),
				array( 'id' => absint( $existing_id ) ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		return false !== $wpdb->insert(
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
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
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			update_option( 'mobo_core_category_map_seed_completed_at', time(), false );
			return array( 'categories' => 0 );
		}

		$count = 0;
		$last  = $cursor;

		foreach ( $rows as $row ) {
			$term_id = absint( $row['term_id'] );
			$guid    = sanitize_text_field( (string) $row['remote_guid'] );

			if ( $term_id > 0 && '' !== $guid && $this->upsert_synced_category( $guid, $term_id, $row['name'], $row['remote_url'], $row['parent_remote_guid'] ) ) {
				$count++;
			}

			$last = max( $last, $term_id );
		}

		update_option( 'mobo_core_category_map_cursor', $last, false );

		return array( 'categories' => $count );
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
			$wpdb->update(
				self::table_name(),
				array( $column => 0, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => absint( $row['id'] ) ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		return 0;
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
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return false;
		}

		if ( false !== strpos( $value, '/' ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '://' ) ) {
			return false;
		}

		return true;
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
			return 0;
		}

		if ( ! $this->term_exists( $term_id ) ) {
			if ( $clear_stale ) {
				$wpdb->update(
					$table,
					array( $column => 0, 'updated_at' => current_time( 'mysql', true ) ),
					array( 'id' => absint( $row['id'] ) ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}

			return 0;
		}

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

		$term = get_term( $term_id, 'product_cat' );

		return $term instanceof WP_Term && ! is_wp_error( $term );
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
