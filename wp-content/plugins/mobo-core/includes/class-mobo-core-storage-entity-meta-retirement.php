<?php
/**
 * Terminal retirement for the remaining Mobo-owned Product/Variation postmeta.
 *
 * Operational state and identity use their dedicated typed storage introduced in
 * 10.33.94/10.33.95. This class owns the remaining Mobo namespaced metadata in
 * a generic row store so WordPress multi-value metadata semantics remain intact.
 * Attachment/order/native WooCommerce metadata are deliberately out of scope.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Entity_Meta_Retirement {

	const TARGET_VERSION     = '10.33.96';
	const WORKER_NAME        = 'storage_entity_meta_retirement_v1';
	const FENCE_VERSION      = 1;
	const FENCE_OPTION       = 'mobo_core_entity_meta_terminal_fence_v1';
	const TRANSITION_OPTION  = 'mobo_core_entity_meta_transition_v1';
	const COPY_BATCH         = 100;
	const CLEANUP_BATCH      = 200;
	const GRACE_SECONDS      = 120;
	const MAX_FAILED_META_IDS = 200;
	const BULK_UPSERT_TARGET_BYTES = 524288;
	/* Synthetic positive meta IDs let WooCommerce WC_Data round-trip Custom rows through update/delete_metadata_by_mid(). */
	const WC_META_ID_OFFSET   = 4000000000000000000;
	const WC_META_ID_RANGE    = 1000000000000000000;

	/** @var bool|null */
	private static $fenced = null;

	/** @var bool|null */
	private static $transitioning = null;

	/** @var bool Request-local cache: terminal zero-residue was already proven. */
	private static $zero_residue_verified = false;

	/** Request-local row-cache bucket for mobo_entity_meta. */
	const ROW_CACHE_BUCKET = 'entity_meta';

	/**
	 * Explicit non-namespaced Product metadata owned by MoboCore.
	 *
	 * `published_at` predates the mobo_ namespace. It is retired only for objects
	 * that already have terminal Mobo identity in the typed Product/Variant tables.
	 *
	 * @return array<string,bool>
	 */
	public static function explicit_managed_keys() {
		return array(
			'published_at' => true,
		);
	}

	/** @return array<string,bool> */
	private static function excluded_specialized_keys() {
		return array(
			/* 10.33.94 operational typed state. */
			'_mobo_product_seen_revision'     => true,
			'_mobo_product_applied_revision'  => true,
			'_mobo_variant_seen_revision'     => true,
			'_mobo_variant_applied_revision'  => true,
			'_mobo_product_source_hash'        => true,
			'_mobo_variant_source_hash'        => true,
			'mobo_sync_incomplete'             => true,

			/* 10.33.95 typed identity aliases. */
			'product_guid'                     => true,
			'variant_guid'                     => true,
			'mobo_variant_guid'                => true,
			'_mobo_variant_guid'               => true,
			'portal_product_id'                => true,
			'mobo_portal_product_id'           => true,
			'_mobo_portal_product_id'          => true,
			'PortalProductId'                  => true,
			'portal_variant_id'                => true,
			'mobo_portal_variant_id'           => true,
			'_mobo_portal_variant_id'          => true,
			'PortalVariantId'                  => true,
		);
	}

	/**
	 * Cheap key classification that does not touch the database.
	 *
	 * @param string $meta_key Metadata key.
	 * @return bool
	 */
	public static function is_managed_key( $meta_key ) {
		$meta_key = (string) $meta_key;
		if ( '' === $meta_key || isset( self::excluded_specialized_keys()[ $meta_key ] ) ) {
			return false;
		}
		if ( isset( self::explicit_managed_keys()[ $meta_key ] ) ) {
			return true;
		}
		return 0 === strpos( $meta_key, '_mobo_' ) || 0 === strpos( $meta_key, 'mobo_' );
	}

	/** @return string */
	public static function table_name() {
		return class_exists( 'Mobo_Core_Storage_Schema' ) ? Mobo_Core_Storage_Schema::table_name( 'entity_meta' ) : '';
	}

	/** @return bool */
	private static function storage_ready() {
		/* Storage_Schema::is_ready() already requires the currently installed schema
		 * generation and validates every required table/column/index. Do not pin this
		 * retirement worker to the schema generation that originally introduced
		 * entity_meta: later compatible schema generations (v7+) must remain usable. */
		return class_exists( 'Mobo_Core_Storage_Schema' )
			&& Mobo_Core_Storage_Schema::is_ready()
			&& '' !== self::table_name();
	}

	/**
	 * Return whether an explicit legacy key belongs to a terminal Mobo object.
	 * Namespaced keys do not need this extra ownership proof.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $post_type Post type.
	 * @return bool|null True/false, or null on a database read error.
	 */
	private static function explicit_key_object_is_mobo( $object_id, $post_type ) {
		global $wpdb;
		$object_id = absint( $object_id );
		if ( $object_id <= 0 || ! self::storage_ready() ) {
			return null;
		}
		$table = 'product' === $post_type
			? Mobo_Core_Storage_Schema::table_name( 'products' )
			: Mobo_Core_Storage_Schema::table_name( 'variants' );
		$column = 'product' === $post_type ? 'woo_product_id' : 'woo_variation_id';
		/* 10.34.78: ownership is re-checked on every published_at read. Cache only a
		 * positive proof for this request; any write to the typed table flushes it. */
		$bucket = 'product' === $post_type ? 'entity_own_product' : 'entity_own_variant';
		$hit    = false;
		if ( true === Mobo_Core_Storage_Row_Cache::get( $bucket, (string) $object_id, $hit ) && $hit ) {
			return true;
		}
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$column}=%d LIMIT 1", $object_id ) );
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			return null;
		}
		$owned = absint( $found ) > 0;
		if ( $owned ) {
			Mobo_Core_Storage_Row_Cache::register_table( $bucket, $table );
			Mobo_Core_Storage_Row_Cache::set( $bucket, (string) $object_id, true, $object_id );
		}
		return $owned;
	}

	/**
	 * Whether the key is managed for one concrete Product/Variation object.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Metadata key.
	 * @return bool
	 */
	public static function key_applies_to_object( $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		if ( $object_id <= 0 || ! self::is_managed_key( $meta_key ) ) {
			return false;
		}
		$post_type = get_post_type( $object_id );
		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return false;
		}
		if ( isset( self::explicit_managed_keys()[ $meta_key ] ) ) {
			/* A transient custom-storage read error fails closed once authority is active. */
			$owned = self::explicit_key_object_is_mobo( $object_id, $post_type );
			return null === $owned ? self::is_authoritative_without_filter() : (bool) $owned;
		}
		return true;
	}

	/** @return bool */
	private static function is_fenced_without_filter() {
		if ( true === self::$fenced ) {
			return true;
		}
		$option_fence  = self::FENCE_VERSION === absint( get_option( self::FENCE_OPTION, 0 ) );
		$version_fence = version_compare( (string) get_option( 'mobo_core_db_version', '0' ), self::TARGET_VERSION, '>=' );
		if ( $option_fence || $version_fence ) {
			self::$fenced = true;
			return true;
		}
		$state = self::load_state();
		if ( ! empty( $state['fenceActive'] ) && self::FENCE_VERSION === absint( $state['fenceVersion'] ?? 0 ) ) {
			self::$fenced = true;
			update_option( self::FENCE_OPTION, self::FENCE_VERSION, false );
			return true;
		}
		return false;
	}

	/** @return bool */
	private static function is_transitioning_without_filter() {
		if ( true === self::$transitioning ) {
			return true;
		}
		if ( self::FENCE_VERSION === absint( get_option( self::TRANSITION_OPTION, 0 ) ) ) {
			self::$transitioning = true;
			return true;
		}
		$state = self::load_state();
		if ( ! empty( $state['transitionActive'] ) && self::FENCE_VERSION === absint( $state['transitionVersion'] ?? 0 ) ) {
			self::$transitioning = true;
			update_option( self::TRANSITION_OPTION, self::FENCE_VERSION, false );
			return true;
		}
		return false;
	}

	/** @return bool */
	public static function is_fenced() {
		$active = self::is_fenced_without_filter();
		return $active && false !== apply_filters( 'mobo_core_entity_meta_terminal_authority_active', true );
	}

	/** @return bool */
	public static function is_transitioning() {
		$active = ! self::is_fenced_without_filter() && self::is_transitioning_without_filter();
		return $active && false !== apply_filters( 'mobo_core_entity_meta_terminal_authority_active', true );
	}

	/** @return bool */
	private static function is_authoritative_without_filter() {
		return self::is_fenced_without_filter() || self::is_transitioning_without_filter();
	}

	/** @return bool */
	public static function is_authoritative() {
		$active = self::is_authoritative_without_filter();
		return $active && false !== apply_filters( 'mobo_core_entity_meta_terminal_authority_active', true );
	}

	/** @return void */
	public static function reset_request_cache() {
		self::$fenced        = null;
		self::$transitioning = null;
		self::$zero_residue_verified = false;
		if ( class_exists( 'Mobo_Core_Storage_Row_Cache', false ) ) {
			Mobo_Core_Storage_Row_Cache::flush_bucket( self::ROW_CACHE_BUCKET );
		}
	}


	/** @return int */
	private static function copy_batch_size() { return class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 1000 : self::COPY_BATCH; }
	/** @return int */
	private static function cleanup_batch_size() { return class_exists( 'Mobo_Core_Migration' ) && Mobo_Core_Migration::is_accelerated_context() ? 1000 : self::CLEANUP_BATCH; }

	/** @return array */
	public static function get_status() {
		$state = self::load_state();
		unset( $state['__persistedStateJson'], $state['__stateLoadError'] );
		$state['workerName']    = self::WORKER_NAME;
		$state['targetVersion'] = self::TARGET_VERSION;
		$state['transitioning'] = self::is_transitioning();
		$state['fenced']        = self::is_fenced();
		$state['authoritative'] = self::is_authoritative();
		$state['scope']         = 'remaining Mobo-owned Product/Variation metadata only; Woo native, attachment and order metadata retained';
		$state['managedPrefixes'] = array( '_mobo_', 'mobo_' );
		$state['explicitManagedKeys'] = array_keys( self::explicit_managed_keys() );
		$failed_commits = isset( $state['failedMetaIds'] ) && is_array( $state['failedMetaIds'] ) ? array_values( array_filter( array_map( 'absint', $state['failedMetaIds'] ) ) ) : array();
		$failed_deletes = isset( $state['failedDeletedMetaIds'] ) && is_array( $state['failedDeletedMetaIds'] ) ? array_values( array_filter( array_map( 'absint', $state['failedDeletedMetaIds'] ) ) ) : array();
		$state['lateRepairPending'] = 1 === absint( get_option( 'mobo_core_entity_meta_late_repair_pending_v1', 0 ) ) || ! empty( $failed_commits ) || ! empty( $failed_deletes ) || ! empty( $state['cleanupPending'] );
		$state['failedMetaIdCount'] = count( $failed_commits );
		$state['failedDeletedMetaIdCount'] = count( $failed_deletes );
		return $state;
	}

	/**
	 * WordPress get_post_metadata filter.
	 *
	 * @return mixed
	 */
	public static function filter_read( $check, $object_id, $meta_key, $single = false ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::key_applies_to_object( $object_id, $meta_key ) ) {
			return $check;
		}
		$rows = self::read_custom_rows( $object_id, $meta_key );
		if ( null === $rows ) {
			/* Terminal Custom authority never falls back to a retired Legacy row. */
			return $single ? '' : array();
		}
		if ( empty( $rows ) ) {
			return $single ? '' : array();
		}
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = maybe_unserialize( $row['meta_value'] ?? '' );
		}
		/* The dynamic metadata filter is also used by metadata_exists(). For a
		 * present single value return a one-item array: get_metadata_raw() unwraps
		 * it, while metadata_exists() remains true for '', '0' and false. */
		return $single ? array( $values[0] ) : $values;
	}

	/** @return mixed */
	public static function filter_add( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::key_applies_to_object( $object_id, $meta_key ) ) {
			return $check;
		}
		$result = self::add_custom( $object_id, $meta_key, $meta_value, (bool) $unique );
		if ( false !== $result ) {
			self::retire_legacy_rows_for_key( $object_id, $meta_key );
			return self::virtual_meta_id( $result );
		}
		return false;
	}

	/** @return mixed */
	public static function filter_update( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::key_applies_to_object( $object_id, $meta_key ) ) {
			return $check;
		}
		$result = self::update_custom( $object_id, $meta_key, $meta_value, $prev_value );
		if ( false !== $result ) {
			self::retire_legacy_rows_for_key( $object_id, $meta_key );
		}
		return (bool) $result;
	}

	/** @return mixed */
	public static function filter_delete( $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::is_managed_key( $meta_key ) ) {
			return $check;
		}

		/* Preserve WordPress' global delete semantics outside Product/Variation scope.
		 * We delete our custom rows first and then allow WordPress to continue against
		 * attachment/order/other-post Legacy rows for the same key. */
		if ( $delete_all ) {
			/* Retire Product/Variation Legacy first. If the request dies before Custom
			 * deletion, the delete simply remains incomplete rather than resurrecting
			 * stale Legacy into Custom during migration replay. WordPress may continue to
			 * delete same-key metadata on non-Mobo object types after we return null. */
			if ( ! self::retire_all_legacy_rows_for_key( $meta_key, $meta_value ) ) { return false; }
			$ok = self::delete_custom_all_for_key( $meta_key, $meta_value );
			return $ok ? null : false;
		}

		if ( ! self::key_applies_to_object( $object_id, $meta_key ) ) {
			return $check;
		}
		/* Delete Legacy first, Custom second. A hard kill between them leaves the
		 * pre-delete Custom value (safe/retryable) instead of a Legacy-only value that
		 * a later migration could accidentally resurrect. */
		if ( ! self::retire_legacy_rows_for_key( $object_id, $meta_key ) ) { return false; }
		return (bool) self::delete_custom( $object_id, $meta_key, $meta_value );
	}

	/**
	 * WooCommerce's WC_Data_Store_WP bypasses get_post_meta() for custom meta reads
	 * and update_post_meta() for updates to an existing meta row. Inject terminal
	 * Custom rows into that raw read surface and give them synthetic positive IDs so
	 * update/delete_metadata_by_mid() can be routed back here safely.
	 *
	 * @param array   $raw_meta_data Raw WC metadata rows.
	 * @param WC_Data $object Woo object.
	 * @return array
	 */
	public static function filter_woocommerce_read_meta( $raw_meta_data, $object ) {
		if ( ! self::is_authoritative() || ! self::storage_ready() || ! is_object( $object ) || ! method_exists( $object, 'get_id' ) ) {
			return is_array( $raw_meta_data ) ? $raw_meta_data : array();
		}
		$object_id = absint( $object->get_id() );
		$post_type = get_post_type( $object_id );
		if ( $object_id <= 0 || ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return is_array( $raw_meta_data ) ? $raw_meta_data : array();
		}

		/* Never expose a late/residual Legacy Mobo row after terminal authority. */
		$filtered = array();
		foreach ( is_array( $raw_meta_data ) ? $raw_meta_data : array() as $row ) {
			$key = is_object( $row ) && isset( $row->meta_key ) ? (string) $row->meta_key : '';
			$mid = is_object( $row ) && isset( $row->meta_id ) ? (int) $row->meta_id : 0;
			if ( self::is_virtual_meta_id( $mid ) ) {
				continue;
			}
			if ( self::is_managed_key( $key ) && self::key_applies_to_object( $object_id, $key ) ) {
				continue;
			}
			$filtered[] = $row;
		}

		$grouped = self::object_rows( $object_id );
		if ( null === $grouped ) {
			/* Fail closed: native Woo meta survives, retired Mobo meta never falls back. */
			return $filtered;
		}
		$rows = array();
		foreach ( $grouped as $key_rows ) {
			foreach ( $key_rows as $key_row ) {
				$rows[] = $key_row;
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = absint( $a['sort_order'] ?? 0 ) <=> absint( $b['sort_order'] ?? 0 );
				return 0 !== $cmp ? $cmp : ( absint( $a['id'] ?? 0 ) <=> absint( $b['id'] ?? 0 ) );
			}
		);
		foreach ( $rows as $row ) {
			$row       = (object) $row;
			$custom_id = absint( $row->id ?? 0 );
			$key       = (string) ( $row->meta_key ?? '' );
			if ( $custom_id <= 0 || ! self::is_managed_key( $key ) || ! self::key_applies_to_object( $object_id, $key ) ) {
				continue;
			}
			$virtual = self::virtual_meta_id( $custom_id );
			if ( false === $virtual ) {
				continue;
			}
			$meta = new stdClass();
			$meta->meta_id    = $virtual;
			$meta->meta_key   = $key;
			$meta->meta_value = (string) ( $row->meta_value ?? '' );
			$filtered[]       = $meta;
		}
		return $filtered;
	}

	/** @return mixed */
	public static function filter_get_by_mid( $check, $meta_id ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::is_virtual_meta_id( $meta_id ) ) {
			return $check;
		}
		$row = self::custom_row_from_virtual_meta_id( $meta_id );
		if ( null === $row ) {
			return false;
		}
		$meta = new stdClass();
		$meta->meta_id    = (string) (int) $meta_id;
		$meta->post_id    = (string) absint( $row['woo_object_id'] ?? 0 );
		$meta->meta_key   = (string) ( $row['meta_key'] ?? '' );
		$meta->meta_value = maybe_unserialize( $row['meta_value'] ?? '' );
		return $meta;
	}

	/** @return mixed */
	public static function filter_update_by_mid( $check, $meta_id, $meta_value, $meta_key = false ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::is_virtual_meta_id( $meta_id ) ) {
			return $check;
		}
		$row = self::custom_row_from_virtual_meta_id( $meta_id );
		if ( null === $row ) {
			return false;
		}
		$object_id = absint( $row['woo_object_id'] ?? 0 );
		$key       = false === $meta_key ? (string) ( $row['meta_key'] ?? '' ) : (string) $meta_key;
		if ( ! self::key_applies_to_object( $object_id, $key ) ) {
			return false;
		}
		$subtype = get_object_subtype( 'post', $object_id );
		$meta_value = sanitize_meta( $key, $meta_value, 'post', $subtype );
		$raw = maybe_serialize( $meta_value );
		$raw = null === $raw ? '' : (string) $raw;
		global $wpdb;
		$table = self::table_name();
		$updated = $wpdb->update(
			$table,
			array(
				'meta_key'       => $key,
				'meta_value'     => $raw,
				'value_hash'     => hash( 'sha256', $raw ),
				'legacy_meta_id' => null,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $row['id'] ?? 0 ) ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return false;
		}
		self::retire_legacy_rows_for_key( $object_id, (string) ( $row['meta_key'] ?? '' ) );
		if ( $key !== (string) ( $row['meta_key'] ?? '' ) ) {
			self::retire_legacy_rows_for_key( $object_id, $key );
		}
		self::clear_meta_cache( $object_id );
		return true;
	}

	/** @return mixed */
	public static function filter_delete_by_mid( $check, $meta_id ) {
		if ( null !== $check || ! self::is_authoritative() || ! self::is_virtual_meta_id( $meta_id ) ) {
			return $check;
		}
		$row = self::custom_row_from_virtual_meta_id( $meta_id );
		if ( null === $row ) {
			return false;
		}
		global $wpdb;
		$table   = self::table_name();
		$object_id = absint( $row['woo_object_id'] ?? 0 );
		if ( ! self::retire_legacy_rows_for_key( $object_id, (string) ( $row['meta_key'] ?? '' ) ) ) { return false; }
		$deleted = $wpdb->delete( $table, array( 'id' => absint( $row['id'] ?? 0 ) ), array( '%d' ) );
		if ( false === $deleted ) { return false; }
		self::clear_meta_cache( $object_id );
		return $deleted > 0;
	}

	/** @return int|false */
	private static function virtual_meta_id( $custom_id ) {
		$custom_id = absint( $custom_id );
		if ( $custom_id <= 0 || $custom_id >= self::WC_META_ID_RANGE || $custom_id >= ( PHP_INT_MAX - self::WC_META_ID_OFFSET ) ) {
			return false;
		}
		return self::WC_META_ID_OFFSET + $custom_id;
	}

	/** @return bool */
	private static function is_virtual_meta_id( $meta_id ) {
		if ( ! is_numeric( $meta_id ) ) { return false; }
		$id = (int) $meta_id;
		return $id > self::WC_META_ID_OFFSET && $id < ( self::WC_META_ID_OFFSET + self::WC_META_ID_RANGE );
	}

	/** @return array|null */
	private static function custom_row_from_virtual_meta_id( $meta_id ) {
		if ( ! self::is_virtual_meta_id( $meta_id ) || ! self::storage_ready() ) {
			return null;
		}
		$custom_id = (int) $meta_id - self::WC_META_ID_OFFSET;
		if ( $custom_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = self::table_name();
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,woo_object_id,object_type,meta_key,meta_value,sort_order FROM {$table} WHERE id=%d LIMIT 1", $custom_id ), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $row ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * Mirror an already committed Legacy postmeta add/update.
	 *
	 * @param int|array $meta_ids Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key Key.
	 * @return void
	 */
	public static function handle_legacy_commit( $meta_ids, $object_id, $meta_key ) {
		if ( ! self::storage_ready() || ! self::is_managed_key( $meta_key ) || ! self::key_applies_to_object( $object_id, $meta_key ) ) {
			return;
		}
		$ids = is_array( $meta_ids ) ? $meta_ids : array( $meta_ids );
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$late = self::is_authoritative_without_filter();
		foreach ( $ids as $meta_id ) {
			if ( ! self::mirror_legacy_meta_id( $meta_id, $late ) ) {
				if ( $late ) {
					self::record_failed_meta_ids( array( $meta_id ) );
				}
				continue;
			}
			if ( $late && ! self::delete_legacy_meta_ids( array( $meta_id ), true ) ) {
				self::record_failed_meta_ids( array( $meta_id ) );
			}
		}
	}

	/**
	 * Mirror an already committed Legacy postmeta delete.
	 *
	 * @param int|array $meta_ids Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key Key.
	 * @return void
	 */
	public static function handle_legacy_delete( $meta_ids, $object_id, $meta_key ) {
		if ( ! self::storage_ready() || ! self::is_managed_key( $meta_key ) || ! in_array( get_post_type( absint( $object_id ) ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$ids = is_array( $meta_ids ) ? $meta_ids : array( $meta_ids );
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE legacy_meta_id IN ({$ph})", $ids ) );
		if ( false === $deleted ) {
			self::record_failed_deleted_meta_ids( $ids );
		}
	}

	/** @return void */
	public static function purge_deleted_object( $post_id, $post = null ) {
		$post_id = absint( $post_id );
		$type = $post instanceof WP_Post ? (string) $post->post_type : (string) get_post_type( $post_id );
		if ( $post_id <= 0 || ! in_array( $type, array( 'product', 'product_variation' ), true ) || ! self::storage_ready() ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$wpdb->delete( $table, array( 'woo_object_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * One resumable migration step.
	 *
	 * @return bool True only after permanent fence + physical zero-residue proof.
	 */
	public static function run_migration_step() {
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) {
			return false;
		}
		if ( ! self::prerequisites_ready() ) {
			return false;
		}

		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }

		/* Recover state from either redundant durable authority marker. Use the fast
		 * existence probe here; the exact COUNT remains reserved for terminal proof. */
		if ( self::is_fenced_without_filter() ) {
			$has_residue = self::has_physical_residue();
			if ( false === $has_residue ) {
				$state['fenceActive']      = true;
				$state['fenceVersion']     = self::FENCE_VERSION;
				$state['transitionActive'] = false;
				$state['complete']         = true;
				$state['physicalResidue']  = 0;
				$state['status']           = 'physical-retirement-complete';
				$state['graceRemainingSeconds'] = 0;
				$state['completedAt']      = absint( $state['completedAt'] ?? 0 ) ?: time();
				if ( ! self::persist_state( $state, true ) ) {
					return false;
				}
				self::$zero_residue_verified = true;
				return true;
			}
		}

		if ( self::is_transitioning_without_filter() && empty( $state['transitionActive'] ) ) {
			$state['transitionActive']      = true;
			$state['transitionVersion']     = self::FENCE_VERSION;
			$state['transitionActivatedAt'] = time();
			$state['status']                = 'transition-state-recovered';
			if ( ! self::persist_state( $state ) ) {
				return false;
			}
		}

		if ( ! empty( $state['complete'] ) ) {
			$late_residue = self::has_physical_residue();
			if ( null === $late_residue ) { return false; }
			if ( ! $late_residue ) { self::$zero_residue_verified = true; return true; }
			/* Permanent Custom authority stays active. Re-open only cleanup so a Legacy
			 * row committed by an abnormally long pre-fence request cannot survive. */
			$state['complete'] = false;
			$state['transitionActive'] = true;
			$state['transitionVersion'] = self::FENCE_VERSION;
			$state['transitionActivatedAt'] = max( 1, time() - self::GRACE_SECONDS );
			$state['status'] = 'late-residue-recovery';
			$state['physicalResidue'] = null;
			$state['lastError'] = '';
			if ( ! self::persist_state( $state ) ) { return false; }
			$state = self::load_state();
			if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		}

		if ( empty( $state['transitionActive'] ) ) {
			if ( empty( $state['copyComplete'] ) ) {
				$copy = self::copy_one_batch( absint( $state['copyCursor'] ?? 0 ) );
				if ( false === $copy ) {
					self::record_state_error( self::load_state(), 'copy_failed', 'copy-error' );
					return false;
				}
				$state = self::load_state();
				if ( ! empty( $copy['rows'] ) ) {
					$state['copyCursor'] = absint( $copy['cursor'] );
					$state['copiedRows'] = absint( $state['copiedRows'] ?? 0 ) + absint( $copy['rows'] );
					$state['status']     = 'copy-running';
					if ( ! self::persist_state( $state ) ) { return false; }
					return false;
				}
				$state['copyComplete'] = true;
				$state['status']       = 'copy-complete';
				if ( ! self::persist_state( $state ) ) { return false; }
			}

			$pruned = self::prune_stale_pretransition_mirrors();
			if ( false === $pruned ) {
				self::record_state_error( self::load_state(), 'stale_mirror_cleanup_failed', 'proof-error' );
				return false;
			}
			if ( $pruned > 0 ) {
				$state = self::load_state();
				$state['status'] = 'stale-mirror-cleanup';
				$state['prunedStaleMirrors'] = absint( $state['prunedStaleMirrors'] ?? 0 ) + absint( $pruned );
				self::persist_state( $state );
				return false;
			}
			$mismatch = self::parity_mismatch_exists();
			if ( null === $mismatch ) {
				self::record_state_error( self::load_state(), 'parity_query_failed', 'proof-error' );
				return false;
			}
			if ( $mismatch ) {
				/* Exact counting is diagnostic-only on the exceptional path. */
				$mismatches = self::parity_mismatch_count();
				$state = self::load_state();
				$state['copyCursor']   = 0;
				$state['copyComplete'] = false;
				$state['status']       = 'proof-retry';
				$state['parityMismatchCount'] = null === $mismatches ? -1 : $mismatches;
				self::persist_state( $state );
				return false;
			}
			return self::activate_transition();
		}

		$transition_at = absint( $state['transitionActivatedAt'] ?? 0 );
		if ( $transition_at <= 0 ) {
			self::record_state_error( $state, 'transition_timestamp_missing', 'blocked' );
			return false;
		}
		if ( time() < $transition_at + self::GRACE_SECONDS ) {
			$state['status'] = 'transition-grace';
			$state['graceRemainingSeconds'] = max( 0, $transition_at + self::GRACE_SECONDS - time() );
			self::persist_state( $state );
			return false;
		}

		/* If a previous request died after journaling/detaching/deleting a cleanup
		 * batch, finish that exact batch before any stale-mirror pruning. This is what
		 * makes kill-at-any-instruction recovery deterministic. */
		if ( ! self::recover_pending_cleanup() ) {
			self::record_state_error( self::load_state(), 'pending_cleanup_recovery_failed', 'cleanup-error' );
			return false;
		}

		if ( ! self::retry_failed_legacy_commits() ) {
			self::record_state_error( self::load_state(), 'late_commit_replay_failed', 'blocked' );
			return false;
		}

		/* A request that started before transition may have deleted a mirrored Legacy
		 * row without reaching the normal deleted_post_meta action. Drain such stale
		 * source-linked mirrors again after grace so deleted metadata cannot resurrect. */
		$pruned = self::prune_stale_pretransition_mirrors();
		if ( false === $pruned ) {
			self::record_state_error( self::load_state(), 'post_grace_stale_mirror_cleanup_failed', 'blocked' );
			return false;
		}
		if ( $pruned > 0 ) {
			$state = self::load_state();
			$state['status'] = 'post-grace-stale-mirror-cleanup';
			$state['prunedStaleMirrors'] = absint( $state['prunedStaleMirrors'] ?? 0 ) + absint( $pruned );
			self::persist_state( $state );
			return false;
		}

		$state = self::load_state();
		$cleanup = self::cleanup_one_batch( $state );
		if ( false === $cleanup ) {
			self::record_state_error( self::load_state(), 'physical_cleanup_failed', 'cleanup-error' );
			return false;
		}
		if ( empty( $cleanup['done'] ) ) {
			return false;
		}

		/* One final stale-source drain closes the deletion race between the earlier
		 * post-grace prune and the physical zero-residue proof. */
		$final_pruned = self::prune_stale_pretransition_mirrors();
		if ( false === $final_pruned ) {
			self::record_state_error( self::load_state(), 'final_stale_mirror_cleanup_failed', 'blocked' );
			return false;
		}
		if ( $final_pruned > 0 ) {
			$state = self::load_state();
			$state['status'] = 'final-stale-mirror-cleanup';
			$state['prunedStaleMirrors'] = absint( $state['prunedStaleMirrors'] ?? 0 ) + absint( $final_pruned );
			self::persist_state( $state );
			return false;
		}

		$residue = self::physical_residue_count();
		if ( null === $residue || $residue > 0 ) {
			$state = self::load_state();
			$state['status']          = 'residue-proof-failed';
			$state['physicalResidue'] = null === $residue ? -1 : $residue;
			/* A late/direct write can land behind cleanupCursor. Never skip it: rewind
			 * the scan and let the next slice drain residue before terminal authority. */
			if ( null !== $residue && $residue > 0 ) {
				$state['cleanupCursor'] = 0;
				$state['cleanupRewinds'] = absint( $state['cleanupRewinds'] ?? 0 ) + 1;
			}
			self::persist_state( $state );
			return false;
		}

		return self::activate_permanent_fence();
	}

	/** @return bool */
	private static function prerequisites_ready() {
		if ( ! self::storage_ready() ) {
			return false;
		}
		if ( ! class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement' )
			|| ! Mobo_Core_Storage_Operational_Meta_Retirement::is_physically_retired() ) {
			return false;
		}
		if ( ! class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement' )
			|| ! Mobo_Core_Storage_Identity_Meta_Retirement::is_physically_retired() ) {
			return false;
		}
		return true;
	}

	/** @return array|false */
	private static function copy_one_batch( $cursor ) {
		$cursor = absint( $cursor );
		$rows = self::legacy_candidate_rows( $cursor, self::copy_batch_size() );
		if ( null === $rows ) {
			return false;
		}
		if ( empty( $rows ) ) {
			return array( 'rows' => 0, 'cursor' => $cursor );
		}
		$last = $cursor;
		foreach ( $rows as $row ) { $last = max( $last, absint( $row['meta_id'] ?? 0 ) ); }
		if ( ! self::mirror_legacy_rows_bulk( $rows ) ) { return false; }
		return array( 'rows' => count( $rows ), 'cursor' => $last );
	}

	/**
	 * Return only rows actually owned by this worker. Specialized 10.33.94/95 keys
	 * are excluded in SQL rather than filtered after LIMIT, preventing a page made
	 * entirely of excluded keys from looking like end-of-scan.
	 *
	 * @return array|null
	 */
	private static function legacy_candidate_rows( $cursor, $limit ) {
		global $wpdb;
		$cursor = absint( $cursor );
		$limit  = max( 1, min( 2000, absint( $limit ) ) );
		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$special_keys = array_keys( self::excluded_specialized_keys() );
		$special_ph = implode( ',', array_fill( 0, count( $special_keys ), '%s' ) );
		$prefix_a = $wpdb->esc_like( '_mobo_' ) . '%';
		$prefix_b = $wpdb->esc_like( 'mobo_' ) . '%';
		$wpdb->last_error = '';
		$sql = "SELECT pm.meta_id,pm.post_id,pm.meta_key,pm.meta_value,p.post_type
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			WHERE pm.meta_id>%d
			AND p.post_type IN ('product','product_variation')
			AND pm.meta_key NOT IN ({$special_ph})
			AND (
				pm.meta_key LIKE %s
				OR pm.meta_key LIKE %s
				OR (pm.meta_key='published_at' AND (
					(p.post_type='product' AND EXISTS (SELECT 1 FROM {$products} cp WHERE cp.woo_product_id=p.ID))
					OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM {$variants} cv WHERE cv.woo_variation_id=p.ID))
				))
			)
			ORDER BY pm.meta_id ASC LIMIT %d";
		$args = array_merge( array( $cursor ), $special_keys, array( $prefix_a, $prefix_b, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
			return null;
		}
		return $rows;
	}


	/**
	 * Mirror a bounded set of already-selected Legacy rows with one multi-row upsert.
	 * Initial/cleanup migration rows use legacy meta_id as stable sort order; because
	 * wp_postmeta.meta_id is monotonic this preserves Legacy ordering while avoiding
	 * one INSERT round-trip per metadata row.
	 *
	 * @param array $rows Candidate rows.
	 * @return bool
	 */
	private static function mirror_legacy_rows_bulk( $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) { return true; }

		/* Heal the only dangerous crash window of the legacy runtime path: an older
		 * request may have detached legacy_meta_id after updating Custom but died before
		 * deleting wp_postmeta. The immutable legacy_origin_meta_id survives detach. Preserve
		 * that exact descendant for cleanup, and DO NOT overwrite its newer Custom value
		 * with the stale Legacy value. */
		$preserved_map = self::find_detached_descendants( $rows );
		$preserved = false === $preserved_map ? false : array_keys( $preserved_map );
		if ( false === $preserved ) { return false; }
		$preserved = array_fill_keys( array_map( 'absint', $preserved ), true );

		/* Row-count alone is unsafe on shared hosting: a few unusually large meta values
		 * can make a 1000-row statement exceed max_allowed_packet or create a PHP memory
		 * spike. Split the already-bounded page into byte-bounded idempotent UPSERTs.
		 * The migration cursor is advanced only after every chunk succeeds, so a process
		 * kill between chunks simply replays earlier chunks on the next request. */
		$chunks = array(); $chunk = array(); $chunk_bytes = 0;
		foreach ( $rows as $row ) {
			$meta_id = absint( $row['meta_id'] ?? 0 );
			$object_id = absint( $row['post_id'] ?? 0 );
			$key = (string) ( $row['meta_key'] ?? '' );
			$type = (string) ( $row['post_type'] ?? '' );
			if ( $meta_id <= 0 || $object_id <= 0 || ! self::is_managed_key( $key ) || ! in_array( $type, array( 'product','product_variation' ), true ) ) { return false; }
			if ( isset( $preserved[ $meta_id ] ) ) { continue; }
			$raw = array_key_exists( 'meta_value', $row ) && null !== $row['meta_value'] ? (string) $row['meta_value'] : '';
			/* Prepared SQL escaping can expand strings. A 2x estimate plus fixed row
			 * overhead deliberately errs high so the final packet remains conservative. */
			$estimated = max( 256, ( strlen( $raw ) * 2 ) + strlen( $key ) + strlen( $type ) + 192 );
			if ( ! empty( $chunk ) && ( $chunk_bytes + $estimated ) > self::BULK_UPSERT_TARGET_BYTES ) {
				$chunks[] = $chunk; $chunk = array(); $chunk_bytes = 0;
			}
			$chunk[] = $row; $chunk_bytes += $estimated;
		}
		if ( ! empty( $chunk ) ) { $chunks[] = $chunk; }
		foreach ( $chunks as $write_chunk ) {
			if ( ! self::mirror_legacy_rows_bulk_chunk( $write_chunk ) ) { return false; }
		}
		return true;
	}

	/** Execute one byte-bounded idempotent Entity UPSERT chunk. @return bool */
	private static function mirror_legacy_rows_bulk_chunk( $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) { return true; }
		$table = self::table_name(); $now = current_time( 'mysql', true ); $values = array(); $args = array();
		foreach ( $rows as $row ) {
			$meta_id = absint( $row['meta_id'] ?? 0 ); $object_id = absint( $row['post_id'] ?? 0 ); $key = (string) ( $row['meta_key'] ?? '' ); $type = (string) ( $row['post_type'] ?? '' );
			if ( $meta_id <= 0 || $object_id <= 0 || ! self::is_managed_key( $key ) || ! in_array( $type, array( 'product','product_variation' ), true ) ) { return false; }
			$raw = array_key_exists( 'meta_value', $row ) && null !== $row['meta_value'] ? (string) $row['meta_value'] : '';
			$values[] = '(%d,%s,%s,%s,%s,%d,%d,%d,%s,%s)';
			array_push( $args, $object_id, $type, $key, $raw, hash( 'sha256', $raw ), $meta_id, $meta_id, $meta_id, $now, $now );
		}
		$sql = "INSERT INTO {$table} (woo_object_id,object_type,meta_key,meta_value,value_hash,sort_order,legacy_meta_id,legacy_origin_meta_id,created_at,updated_at) VALUES " . implode( ',', $values ) .
			" ON DUPLICATE KEY UPDATE woo_object_id=VALUES(woo_object_id),object_type=VALUES(object_type),meta_key=VALUES(meta_key),meta_value=VALUES(meta_value),value_hash=VALUES(value_hash),updated_at=VALUES(updated_at)";
		$wpdb->last_error = ''; $result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		return false !== $result && '' === trim( (string) $wpdb->last_error );
	}

	/**
	 * Find exact detached descendants of still-existing Legacy rows. The caller must
	 * preserve their Custom value and must not remirror stale Legacy over them.
	 *
	 * @param array $rows Legacy candidate rows.
	 * @return array|false
	 */
	private static function find_detached_descendants( $rows ) {
		global $wpdb;
		$candidate = array();
		foreach ( (array) $rows as $row ) {
			$meta_id = absint( $row['meta_id'] ?? 0 );
			$object_id = absint( $row['post_id'] ?? 0 );
			if ( $meta_id <= 0 || $object_id <= 0 ) { continue; }
			$candidate[ $meta_id ] = $object_id;
		}
		$meta_ids = array_keys( $candidate );
		if ( empty( $meta_ids ) ) { return array(); }
		$table = self::table_name();
		$ph = implode( ',', array_fill( 0, count( $meta_ids ), '%d' ) );
		$wpdb->last_error = '';
		$found = $wpdb->get_results( $wpdb->prepare(
			"SELECT id,woo_object_id,legacy_origin_meta_id FROM {$table} WHERE legacy_meta_id IS NULL AND legacy_origin_meta_id IN ({$ph})",
			$meta_ids
		), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $found ) ) { return false; }
		$out = array();
		foreach ( $found as $row ) {
			$id = absint( $row['id'] ?? 0 );
			$object_id = absint( $row['woo_object_id'] ?? 0 );
			$legacy_id = absint( $row['legacy_origin_meta_id'] ?? 0 );
			if ( $id <= 0 || $legacy_id <= 0 || ! isset( $candidate[ $legacy_id ] ) ) { continue; }
			/* Immutable origin is exact. A moved/duplicated origin is corruption and must
			 * fail closed rather than choosing or remirroring stale Legacy data. */
			if ( $object_id !== absint( $candidate[ $legacy_id ] ) || isset( $out[ $legacy_id ] ) ) { return false; }
			$out[ $legacy_id ] = $id;
		}
		return $out;
	}



	/** @return bool */
	private static function mirror_legacy_meta_id( $meta_id, $late ) {
		global $wpdb;
		$meta_id = absint( $meta_id );
		if ( $meta_id <= 0 ) {
			return false;
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pm.meta_id,pm.post_id,pm.meta_key,pm.meta_value,p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_id=%d LIMIT 1",
				$meta_id
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) ) {
			return false;
		}
		if ( ! is_array( $row ) ) {
			return true;
		}
		if ( ! self::is_managed_key( (string) ( $row['meta_key'] ?? '' ) ) || ! self::key_applies_to_object( absint( $row['post_id'] ?? 0 ), (string) ( $row['meta_key'] ?? '' ) ) ) {
			return true;
		}
		return self::mirror_legacy_row( $row, (bool) $late );
	}

	/** @return bool */
	private static function mirror_legacy_row( $row, $late ) {
		global $wpdb;
		$meta_id   = absint( $row['meta_id'] ?? 0 );
		$object_id = absint( $row['post_id'] ?? 0 );
		$key       = (string) ( $row['meta_key'] ?? '' );
		$type      = (string) ( $row['post_type'] ?? get_post_type( $object_id ) );
		if ( $meta_id <= 0 || $object_id <= 0 || ! self::is_managed_key( $key ) || ! in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return false;
		}
		/* A previously mirrored source can have been detached by a newer authoritative
		 * Custom mutation while its Legacy row survived because that old request died
		 * before retirement. Late-repair replay of the same meta_id must preserve the
		 * detached descendant rather than letting the unique origin upsert overwrite
		 * the newer Custom value with stale Legacy data. */
		$detached = self::find_detached_descendants( array( $row ) );
		if ( false === $detached ) { return false; }
		if ( isset( $detached[ $meta_id ] ) ) { return true; }

		$raw = array_key_exists( 'meta_value', $row ) ? $row['meta_value'] : '';
		$raw = null === $raw ? '' : (string) $raw;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$sort  = $late ? self::next_sort_order( $object_id, $key ) : $meta_id;
		if ( $sort <= 0 ) {
			$sort = $meta_id;
		}
		$sql = "INSERT INTO {$table} (woo_object_id,object_type,meta_key,meta_value,value_hash,sort_order,legacy_meta_id,legacy_origin_meta_id,created_at,updated_at)
			VALUES (%d,%s,%s,%s,%s,%d,%d,%d,%s,%s)
			ON DUPLICATE KEY UPDATE woo_object_id=VALUES(woo_object_id),object_type=VALUES(object_type),meta_key=VALUES(meta_key),meta_value=VALUES(meta_value),value_hash=VALUES(value_hash),updated_at=VALUES(updated_at)";
		$ok = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$object_id,
				$type,
				$key,
				$raw,
				hash( 'sha256', $raw ),
				$sort,
				$meta_id,
				$meta_id,
				$now,
				$now
			)
		);
		return false !== $ok;
	}

	/** @return int */
	private static function next_sort_order( $object_id, $meta_key ) {
		global $wpdb;
		$table = self::table_name();
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE woo_object_id=%d AND meta_key=%s", absint( $object_id ), (string) $meta_key ) );
		return max( 1, absint( $max ) + 1 );
	}

	/**
	 * Request-local snapshot of every Custom row of one object, grouped by key in
	 * sort order. One SELECT serves all subsequent _mobo_* reads of that object until
	 * a write invalidates it (see Mobo_Core_Storage_Row_Cache).
	 *
	 * @param int $object_id Woo object ID.
	 * @return array<string,array<int,array>>|null Null on read error (never cached).
	 */
	private static function object_rows( $object_id ) {
		global $wpdb;
		$object_id = absint( $object_id );
		if ( $object_id <= 0 || ! self::storage_ready() ) {
			return null;
		}
		$hit    = false;
		$cached = Mobo_Core_Storage_Row_Cache::get( self::ROW_CACHE_BUCKET, (string) $object_id, $hit );
		if ( $hit && is_array( $cached ) ) {
			return $cached;
		}
		$table = self::table_name();
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,meta_key,meta_value,sort_order,legacy_meta_id FROM {$table} WHERE woo_object_id=%d ORDER BY sort_order ASC,id ASC",
				$object_id
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
			return null;
		}
		$grouped = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$grouped[ (string) ( $row['meta_key'] ?? '' ) ][] = $row;
		}
		Mobo_Core_Storage_Row_Cache::register_table( self::ROW_CACHE_BUCKET, $table );
		Mobo_Core_Storage_Row_Cache::set( self::ROW_CACHE_BUCKET, (string) $object_id, $grouped, $object_id );
		return $grouped;
	}

	/**
	 * Rows of one key. Read paths use the request-local object snapshot; write
	 * decisions pass $fresh=true and always read the database.
	 *
	 * @return array|null
	 */
	private static function read_custom_rows( $object_id, $meta_key, $fresh = false ) {
		global $wpdb;
		if ( ! self::storage_ready() ) {
			return null;
		}
		if ( ! $fresh && Mobo_Core_Storage_Row_Cache::enabled() ) {
			$grouped = self::object_rows( $object_id );
			if ( null === $grouped ) {
				return null;
			}
			return isset( $grouped[ (string) $meta_key ] ) ? $grouped[ (string) $meta_key ] : array();
		}
		$table = self::table_name();
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,meta_value,sort_order,legacy_meta_id FROM {$table} WHERE woo_object_id=%d AND meta_key=%s ORDER BY sort_order ASC,id ASC",
				absint( $object_id ),
				(string) $meta_key
			),
			ARRAY_A
		);
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $rows ) ) {
			return null;
		}
		return $rows;
	}

	/** @return int|false */
	private static function add_custom( $object_id, $meta_key, $meta_value, $unique ) {
		global $wpdb;
		$rows = self::read_custom_rows( $object_id, $meta_key, true );
		if ( null === $rows || ( $unique && ! empty( $rows ) ) ) {
			return false;
		}
		$type = get_post_type( absint( $object_id ) );
		if ( ! in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return false;
		}
		$raw   = maybe_serialize( $meta_value );
		$raw   = null === $raw ? '' : (string) $raw;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$sort  = self::next_sort_order( $object_id, $meta_key );
		Mobo_Core_Storage_Row_Cache::begin_scoped_write( self::ROW_CACHE_BUCKET );
		try {
		$ok = $wpdb->insert(
			$table,
			array(
				'woo_object_id' => absint( $object_id ),
				'object_type'   => $type,
				'meta_key'      => (string) $meta_key,
				'meta_value'    => $raw,
				'value_hash'    => hash( 'sha256', $raw ),
				'sort_order'    => $sort,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		} finally {
			Mobo_Core_Storage_Row_Cache::end_scoped_write( self::ROW_CACHE_BUCKET );
			Mobo_Core_Storage_Row_Cache::forget_object( $object_id, self::ROW_CACHE_BUCKET );
		}
		if ( false === $ok ) {
			return false;
		}
		self::clear_meta_cache( $object_id );
		return absint( $wpdb->insert_id );
	}

	/** @return bool */
	private static function update_custom( $object_id, $meta_key, $meta_value, $prev_value ) {
		global $wpdb;
		$rows = self::read_custom_rows( $object_id, $meta_key, true );
		if ( null === $rows ) {
			return false;
		}
		if ( empty( $rows ) ) {
			return false !== self::add_custom( $object_id, $meta_key, $meta_value, false );
		}
		$new_raw = maybe_serialize( $meta_value );
		$new_raw = null === $new_raw ? '' : (string) $new_raw;
		$prev_is_set = ! empty( $prev_value );
		$prev_raw = $prev_is_set ? maybe_serialize( $prev_value ) : '';
		$prev_raw = null === $prev_raw ? '' : (string) $prev_raw;

		if ( ! $prev_is_set && 1 === count( $rows ) && (string) ( $rows[0]['meta_value'] ?? '' ) === $new_raw ) {
			return false;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			if ( ! $prev_is_set || (string) ( $row['meta_value'] ?? '' ) === $prev_raw ) {
				$ids[] = absint( $row['id'] ?? 0 );
			}
		}
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return false;
		}
		$table = self::table_name();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args  = array_merge( array( $new_raw, hash( 'sha256', $new_raw ), current_time( 'mysql', true ) ), $ids );
		/* Detach source identity in the SAME statement as the authoritative Custom
		 * value change. A kill can therefore expose either the old linked value or the
		 * new detached value, never new-value + stale source-link. */
		Mobo_Core_Storage_Row_Cache::begin_scoped_write( self::ROW_CACHE_BUCKET );
		try {
			$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_value=%s,value_hash=%s,legacy_meta_id=NULL,updated_at=%s WHERE id IN ({$ph})", $args ) );
		} finally {
			Mobo_Core_Storage_Row_Cache::end_scoped_write( self::ROW_CACHE_BUCKET );
			Mobo_Core_Storage_Row_Cache::forget_object( $object_id, self::ROW_CACHE_BUCKET );
		}
		if ( false === $ok ) {
			return false;
		}
		self::clear_meta_cache( $object_id );
		return true;
	}

	/** @return bool */
	private static function delete_custom( $object_id, $meta_key, $meta_value = '' ) {
		global $wpdb;
		$rows = self::read_custom_rows( $object_id, $meta_key, true );
		if ( null === $rows || empty( $rows ) ) {
			return false;
		}
		$match_value = '' !== $meta_value && null !== $meta_value && false !== $meta_value;
		$raw = $match_value ? maybe_serialize( $meta_value ) : '';
		$raw = null === $raw ? '' : (string) $raw;
		$ids = array();
		foreach ( $rows as $row ) {
			if ( ! $match_value || (string) ( $row['meta_value'] ?? '' ) === $raw ) {
				$ids[] = absint( $row['id'] ?? 0 );
			}
		}
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return false;
		}
		$table = self::table_name();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		Mobo_Core_Storage_Row_Cache::begin_scoped_write( self::ROW_CACHE_BUCKET );
		try {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ph})", $ids ) );
		} finally {
			Mobo_Core_Storage_Row_Cache::end_scoped_write( self::ROW_CACHE_BUCKET );
			Mobo_Core_Storage_Row_Cache::forget_object( $object_id, self::ROW_CACHE_BUCKET );
		}
		if ( false === $deleted ) {
			return false;
		}
		self::clear_meta_cache( $object_id );
		return $deleted > 0;
	}

	/** @return bool */
	private static function delete_custom_all_for_key( $meta_key, $meta_value = '' ) {
		global $wpdb;
		if ( ! self::storage_ready() ) {
			return false;
		}
		$table = self::table_name();
		$match_value = '' !== $meta_value && null !== $meta_value && false !== $meta_value;
		if ( $match_value ) {
			$raw = maybe_serialize( $meta_value );
			$raw = null === $raw ? '' : (string) $raw;
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key=%s AND BINARY meta_value=BINARY %s", (string) $meta_key, $raw ) );
		} else {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key=%s", (string) $meta_key ) );
		}
		return false !== $deleted;
	}

	/** @return void */
	private static function clear_meta_cache( $object_id ) {
		wp_cache_delete( absint( $object_id ), 'post_meta' );
		Mobo_Core_Storage_Row_Cache::forget_object( absint( $object_id ), self::ROW_CACHE_BUCKET );
	}

	/**
	 * Remove now-non-authoritative Legacy rows for one key after a Custom mutation.
	 *
	 * @return bool
	 */
	private static function retire_legacy_rows_for_key( $object_id, $meta_key ) {
		global $wpdb;
		$object_id = absint( $object_id );
		if ( $object_id <= 0 ) {
			return false;
		}
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s", $object_id, (string) $meta_key ) );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $ids ) ) {
			return false;
		}
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		return empty( $ids ) ? true : self::delete_legacy_meta_ids( $ids, true );
	}

	/**
	 * Retire all in-scope Product/Variation Legacy rows for one managed key before a
	 * global Custom delete. Non-Product metadata is deliberately left for WordPress.
	 *
	 * @return bool
	 */
	private static function retire_all_legacy_rows_for_key( $meta_key, $meta_value = '' ) {
		global $wpdb;
		$meta_key = (string) $meta_key;
		if ( ! self::is_managed_key( $meta_key ) ) { return true; }
		$match_value = '' !== $meta_value && null !== $meta_value && false !== $meta_value;
		$args = array( $meta_key );
		$value_sql = '';
		if ( $match_value ) {
			$raw = maybe_serialize( $meta_value ); $raw = null === $raw ? '' : (string) $raw;
			$value_sql = ' AND BINARY pm.meta_value=BINARY %s'; $args[] = $raw;
		}
		$ownership_sql = '';
		if ( isset( self::explicit_managed_keys()[ $meta_key ] ) ) {
			$products=Mobo_Core_Storage_Schema::table_name('products');$variants=Mobo_Core_Storage_Schema::table_name('variants');
			$ownership_sql = " AND ((p.post_type='product' AND EXISTS (SELECT 1 FROM {$products} cp WHERE cp.woo_product_id=p.ID)) OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM {$variants} cv WHERE cv.woo_variation_id=p.ID)))";
		}
		$sql = "SELECT pm.meta_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN ('product','product_variation') AND pm.meta_key=%s{$value_sql}{$ownership_sql}";
		$wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare($sql,$args));
		if(''!==trim((string)$wpdb->last_error)||!is_array($ids)){return false;}
		$ids=array_values(array_filter(array_unique(array_map('absint',$ids))));
		return empty($ids)?true:self::delete_legacy_meta_ids($ids,true);
	}

	/** @return bool */
	private static function delete_legacy_meta_ids( $ids, $detach_custom_source ) {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return true;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		if ( $detach_custom_source ) {
			$table = self::table_name();
			$detached = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET legacy_meta_id=NULL WHERE legacy_meta_id IN ({$ph})", $ids ) );
			if ( false === $detached ) {
				return false;
			}
		}
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_id IN ({$ph})", $ids ) );
		$deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$ph})", $ids ) );
		if ( false === $deleted ) {
			return false;
		}
		foreach ( is_array( $post_ids ) ? $post_ids : array() as $post_id ) {
			self::clear_meta_cache( $post_id );
		}
		return true;
	}

	/** @return int|false Number pruned, zero when fully drained, false on query failure. */
	private static function prune_stale_pretransition_mirrors() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( "SELECT em.id FROM {$table} em LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_id=em.legacy_meta_id WHERE em.legacy_meta_id IS NOT NULL AND pm.meta_id IS NULL ORDER BY em.id ASC LIMIT 200" );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $ids ) ) {
			return false;
		}
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ph})", $ids ) );
		return false === $deleted ? false : absint( $deleted );
	}

	/**
	 * Fast success-path parity probe. Full COUNT is diagnostic-only when a mismatch
	 * actually exists, avoiding a complete count on the normal clean transition.
	 *
	 * @return bool|null
	 */
	private static function parity_mismatch_exists() {
		global $wpdb;
		$table    = self::table_name();
		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$special_keys = array_keys( self::excluded_specialized_keys() );
		$special_ph = implode( ',', array_fill( 0, count( $special_keys ), '%s' ) );
		$prefix_a = $wpdb->esc_like( '_mobo_' ) . '%';
		$prefix_b = $wpdb->esc_like( 'mobo_' ) . '%';
		$wpdb->last_error = '';
		$sql = "SELECT 1 FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			LEFT JOIN {$table} em ON em.legacy_meta_id=pm.meta_id
				AND em.woo_object_id=pm.post_id
				AND BINARY em.meta_key=BINARY pm.meta_key
				AND BINARY COALESCE(em.meta_value,'')=BINARY COALESCE(pm.meta_value,'')
			WHERE p.post_type IN ('product','product_variation')
			AND pm.meta_key NOT IN ({$special_ph})
			AND (
				pm.meta_key LIKE %s OR pm.meta_key LIKE %s
				OR (pm.meta_key='published_at' AND (
					(p.post_type='product' AND EXISTS (SELECT 1 FROM {$products} cp WHERE cp.woo_product_id=p.ID))
					OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM {$variants} cv WHERE cv.woo_variation_id=p.ID))
				))
			)
			AND em.id IS NULL LIMIT 1";
		$args = array_merge( $special_keys, array( $prefix_a, $prefix_b ) );
		$hit = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { return null; }
		return null !== $hit;
	}

	/** @return int|null */
	private static function parity_mismatch_count() {
		global $wpdb;
		$table    = self::table_name();
		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$special_keys = array_keys( self::excluded_specialized_keys() );
		$special_ph = implode( ',', array_fill( 0, count( $special_keys ), '%s' ) );
		$prefix_a = $wpdb->esc_like( '_mobo_' ) . '%';
		$prefix_b = $wpdb->esc_like( 'mobo_' ) . '%';
		$wpdb->last_error = '';
		$sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			LEFT JOIN {$table} em ON em.legacy_meta_id=pm.meta_id
				AND em.woo_object_id=pm.post_id
				AND BINARY em.meta_key=BINARY pm.meta_key
				AND BINARY COALESCE(em.meta_value,'')=BINARY COALESCE(pm.meta_value,'')
			WHERE p.post_type IN ('product','product_variation')
			AND pm.meta_key NOT IN ({$special_ph})
			AND (
				pm.meta_key LIKE %s OR pm.meta_key LIKE %s
				OR (pm.meta_key='published_at' AND (
					(p.post_type='product' AND EXISTS (SELECT 1 FROM {$products} cp WHERE cp.woo_product_id=p.ID))
					OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM {$variants} cv WHERE cv.woo_variation_id=p.ID))
				))
			)
			AND em.id IS NULL";
		$args = array_merge( $special_keys, array( $prefix_a, $prefix_b ) );
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return '' !== trim( (string) $wpdb->last_error ) ? null : absint( $count );
	}


	/** @return bool */
	private static function activate_transition() {
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		update_option( self::TRANSITION_OPTION, self::FENCE_VERSION, false );
		if ( self::FENCE_VERSION !== absint( get_option( self::TRANSITION_OPTION, 0 ) ) ) {
			self::record_state_error( $state, 'transition_marker_write_failed', 'transition-error' );
			return false;
		}
		$state['transitionActive']      = true;
		$state['transitionVersion']     = self::FENCE_VERSION;
		$state['transitionActivatedAt'] = time();
		$state['status']                = 'transition-grace';
		$state['parityMismatchCount']   = 0;
		$state['lastError']             = '';
		if ( ! self::persist_state( $state ) ) {
			self::$transitioning = true;
			return false;
		}
		self::$transitioning = true;
		return false;
	}

	/** @return bool */
	private static function activate_permanent_fence() {
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		update_option( self::FENCE_OPTION, self::FENCE_VERSION, false );
		if ( self::FENCE_VERSION !== absint( get_option( self::FENCE_OPTION, 0 ) ) ) {
			self::record_state_error( $state, 'fence_marker_write_failed', 'fence-error' );
			return false;
		}
		$state['fenceActive']      = true;
		$state['fenceVersion']     = self::FENCE_VERSION;
		$state['fenceActivatedAt'] = time();
		$state['transitionActive'] = false;
		$state['complete']         = true;
		$state['completedAt']      = time();
		$state['physicalResidue']  = 0;
		$state['status']           = 'physical-retirement-complete';
				$state['graceRemainingSeconds'] = 0;
		$state['lastError']        = '';
		if ( ! self::persist_state( $state, true ) ) {
			self::$fenced = true;
			return false;
		}
		delete_option( self::TRANSITION_OPTION );
		self::$transitioning = false;
		self::$fenced = true;
		self::$zero_residue_verified = true;
		return true;
	}

	/**
	 * Resume an already-journaled cleanup batch. The journal is persisted before
	 * either the source link is detached or Legacy is deleted, so a killed process
	 * can deterministically finish the same exact batch without re-mirroring it.
	 *
	 * @return bool
	 */
	private static function recover_pending_cleanup() {
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		$pending = isset( $state['cleanupPending'] ) && is_array( $state['cleanupPending'] ) ? $state['cleanupPending'] : array();
		if ( empty( $pending ) ) { return true; }
		return false !== self::finalize_pending_cleanup( $state );
	}

	/**
	 * Run one cursor-bounded cleanup batch using a durable two-phase journal.
	 *
	 * @param array $state Current worker state.
	 * @return array|false {done:bool,deleted:int,cursor:int}
	 */
	private static function cleanup_one_batch( $state ) {
		global $wpdb;
		if ( ! empty( $state['cleanupPending'] ) ) {
			$done = self::finalize_pending_cleanup( $state );
			return false === $done ? false : array( 'done'=>false, 'deleted'=>absint( $done ), 'cursor'=>absint( $state['cleanupCursor'] ?? 0 ) );
		}
		$cursor = absint( $state['cleanupCursor'] ?? 0 );
		$rows = self::legacy_candidate_rows( $cursor, self::cleanup_batch_size() );
		if ( null === $rows ) { return false; }
		if ( empty( $rows ) ) { return array( 'done'=>true, 'deleted'=>0, 'cursor'=>$cursor ); }

		$ids = array(); $post_ids = array(); $next_cursor = $cursor;
		foreach ( $rows as $row ) {
			$meta_id = absint( $row['meta_id'] ?? 0 );
			if ( $meta_id <= 0 ) { return false; }
			$ids[] = $meta_id;
			$post_ids[ absint( $row['post_id'] ?? 0 ) ] = true;
			$next_cursor = max( $next_cursor, $meta_id );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) { return false; }

		/* Idempotent copy first. If the process dies here, the unique legacy_meta_id
		 * upsert lets the next request repeat this stage safely. */
		if ( ! self::mirror_legacy_rows_bulk( $rows ) ) {
			self::record_failed_meta_ids( $ids );
			return false;
		}
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }

		$table = self::table_name();
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$mapped = $wpdb->get_results( $wpdb->prepare( "SELECT id,legacy_meta_id,legacy_origin_meta_id FROM {$table} WHERE legacy_meta_id IN ({$ph})", $ids ), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $mapped ) ) { return false; }
		$entity_by_legacy = array();
		foreach ( $mapped as $map_row ) {
			$entity_id = absint( $map_row['id'] ?? 0 ); $legacy_id = absint( $map_row['legacy_meta_id'] ?? 0 ); $origin_id = absint( $map_row['legacy_origin_meta_id'] ?? 0 );
			if ( $entity_id <= 0 || $legacy_id <= 0 || $origin_id !== $legacy_id || isset( $entity_by_legacy[ $legacy_id ] ) ) { return false; }
			$entity_by_legacy[ $legacy_id ] = $entity_id;
		}
		$detached = self::find_detached_descendants( $rows );
		if ( false === $detached ) { return false; }
		foreach ( $detached as $legacy_id=>$entity_id ) {
			$legacy_id=absint($legacy_id);$entity_id=absint($entity_id);
			if ( isset( $entity_by_legacy[ $legacy_id ] ) && $entity_by_legacy[ $legacy_id ] !== $entity_id ) { return false; }
			$entity_by_legacy[ $legacy_id ] = $entity_id;
		}
		$entity_ids = array();
		foreach ( $ids as $legacy_id ) { if ( empty( $entity_by_legacy[ $legacy_id ] ) ) { return false; } $entity_ids[] = absint( $entity_by_legacy[ $legacy_id ] ); }
		/* One Legacy source must resolve to one distinct Custom descendant. Any
		 * many-to-one corruption is ambiguous and must block rather than delete. */
		if ( count( array_unique( $entity_ids ) ) !== count( $ids ) ) { return false; }

		/* This is the commit point for the destructive phase. Anything after this can
		 * be replayed from exact IDs even if PHP/FPM/DB transport dies mid-batch. */
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		$state['cleanupPending'] = array(
			'legacyMetaIds' => $ids,
			'entityRowIds'  => array_values( array_unique( $entity_ids ) ),
			'postIds'       => array_values( array_filter( array_map( 'absint', array_keys( $post_ids ) ) ) ),
			'nextCursor'    => $next_cursor,
			'journaledAt'   => time(),
		);
		$state['status'] = 'physical-cleanup-journaled';
		if ( ! self::persist_state( $state ) ) { return false; }
		$deleted = self::finalize_pending_cleanup( $state );
		return false === $deleted ? false : array( 'done'=>false, 'deleted'=>absint( $deleted ), 'cursor'=>$next_cursor );
	}

	/**
	 * Finish the destructive half of a journaled batch. This function is idempotent:
	 * detached links and already-deleted Legacy rows are both accepted on replay.
	 *
	 * @param array $state State containing cleanupPending.
	 * @return int|false Number of Legacy rows in the journal, or false on failure.
	 */
	private static function finalize_pending_cleanup( $state ) {
		global $wpdb;
		$pending = isset( $state['cleanupPending'] ) && is_array( $state['cleanupPending'] ) ? $state['cleanupPending'] : array();
		$legacy_ids = array_values( array_filter( array_unique( array_map( 'absint', $pending['legacyMetaIds'] ?? array() ) ) ) );
		$entity_ids = array_values( array_filter( array_unique( array_map( 'absint', $pending['entityRowIds'] ?? array() ) ) ) );
		$post_ids   = array_values( array_filter( array_unique( array_map( 'absint', $pending['postIds'] ?? array() ) ) ) );
		$next_cursor = absint( $pending['nextCursor'] ?? 0 );
		if ( empty( $legacy_ids ) || empty( $entity_ids ) || $next_cursor <= 0 ) { return false; }
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }

		$table = self::table_name();
		$entity_ph = implode( ',', array_fill( 0, count( $entity_ids ), '%d' ) );
		/* Detach only the exact custom rows recorded in the durable journal. If this
		 * succeeds and the process dies before Legacy DELETE, recovery never remirrors
		 * the source and therefore cannot create a duplicate Custom row. */
		$detached = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET legacy_meta_id=NULL WHERE id IN ({$entity_ph}) AND legacy_meta_id IS NOT NULL", $entity_ids ) );
		if ( false === $detached ) { return false; }
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }

		$legacy_ph = implode( ',', array_fill( 0, count( $legacy_ids ), '%d' ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$legacy_ph})", $legacy_ids ) );
		if ( false === $deleted ) { return false; }
		foreach ( $post_ids as $post_id ) { self::clear_meta_cache( $post_id ); }
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }

		/* Confirm no Legacy source row from the journal survived before advancing the
		 * cursor. This also covers odd storage-engine/connection failure behaviour. */
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_id IN ({$legacy_ph}) LIMIT 1", $legacy_ids ) );
		if ( '' !== trim( (string) $wpdb->last_error ) || null !== $remaining ) { return false; }

		$fresh = self::load_state();
		if ( ! empty( $fresh['__stateLoadError'] ) ) { return false; }
		$current_pending = isset( $fresh['cleanupPending'] ) && is_array( $fresh['cleanupPending'] ) ? $fresh['cleanupPending'] : array();
		/* Refuse to clear/advance if another owner replaced the journal. */
		if ( array_values( array_map( 'absint', $current_pending['legacyMetaIds'] ?? array() ) ) !== $legacy_ids ) { return false; }
		$fresh['cleanupCursor'] = max( absint( $fresh['cleanupCursor'] ?? 0 ), $next_cursor );
		$fresh['cleanedRows'] = absint( $fresh['cleanedRows'] ?? 0 ) + count( $legacy_ids );
		$fresh['cleanupPending'] = array();
		$fresh['status'] = 'physical-cleanup';
		$fresh['graceRemainingSeconds'] = 0;
		$fresh['lastError'] = '';
		if ( ! self::persist_state( $fresh ) ) { return false; }
		return count( $legacy_ids );
	}

	/** Fast boolean residue probe used by prerequisite/complete gates. @return bool|null */
	private static function has_physical_residue() {
		$rows = self::legacy_candidate_rows( 0, 1 );
		return null === $rows ? null : ! empty( $rows );
	}

	/** Durable terminal prerequisite with request-local zero-residue caching. @return bool */
	public static function is_physically_retired() {
		if ( ! self::is_fenced_without_filter() ) { return false; }
		$state = self::load_state();
		if ( empty( $state['complete'] ) || ! empty( $state['cleanupPending'] ) ) { return false; }
		if ( self::$zero_residue_verified ) { return true; }
		$has = self::has_physical_residue();
		if ( null === $has || $has ) { return false; }
		self::$zero_residue_verified = true;
		return true;
	}


	/** @return int|null */
	public static function physical_residue_count() {
		global $wpdb;
		if ( ! self::storage_ready() ) { return null; }
		$products = Mobo_Core_Storage_Schema::table_name( 'products' );
		$variants = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$special_keys = array_keys( self::excluded_specialized_keys() );
		$special_ph = implode( ',', array_fill( 0, count( $special_keys ), '%s' ) );
		$prefix_a = $wpdb->esc_like( '_mobo_' ) . '%';
		$prefix_b = $wpdb->esc_like( 'mobo_' ) . '%';
		$wpdb->last_error = '';
		$sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			WHERE p.post_type IN ('product','product_variation')
			AND pm.meta_key NOT IN ({$special_ph})
			AND (
				pm.meta_key LIKE %s OR pm.meta_key LIKE %s
				OR (pm.meta_key='published_at' AND (
					(p.post_type='product' AND EXISTS (SELECT 1 FROM {$products} cp WHERE cp.woo_product_id=p.ID))
					OR (p.post_type='product_variation' AND EXISTS (SELECT 1 FROM {$variants} cv WHERE cv.woo_variation_id=p.ID))
				))
			)";
		$args = array_merge( $special_keys, array( $prefix_a, $prefix_b ) );
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return '' !== trim( (string) $wpdb->last_error ) ? null : absint( $count );
	}


	/** @return bool */
	private static function retry_failed_legacy_commits() {
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		$ids = isset( $state['failedMetaIds'] ) && is_array( $state['failedMetaIds'] ) ? $state['failedMetaIds'] : array();
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return true;
		}
		$remaining = array();
		foreach ( $ids as $meta_id ) {
			if ( ! self::mirror_legacy_meta_id( $meta_id, true ) || ! self::delete_legacy_meta_ids( array( $meta_id ), true ) ) {
				$remaining[] = $meta_id;
			}
		}
		$state = self::load_state();
		$state['failedMetaIds'] = $remaining;
		$state['lateCommitReplayRemaining'] = count( $remaining );
		self::persist_state( $state );
		return empty( $remaining );
	}

	/** @return void */
	private static function record_failed_meta_ids( $ids ) {
		$state = self::load_state();
		$current = isset( $state['failedMetaIds'] ) && is_array( $state['failedMetaIds'] ) ? $state['failedMetaIds'] : array();
		$current = array_values( array_filter( array_unique( array_merge( array_map( 'absint', $current ), array_map( 'absint', (array) $ids ) ) ) ) );
		if ( count( $current ) > self::MAX_FAILED_META_IDS ) {
			$current = array_slice( $current, -self::MAX_FAILED_META_IDS );
			$state['failedMetaIdsTruncated'] = true;
		}
		$state['failedMetaIds'] = $current;
		$state['lateCommitMirrorFailures'] = absint( $state['lateCommitMirrorFailures'] ?? 0 ) + count( (array) $ids );
		$state['status'] = 'late-commit-mirror-failed';
		self::persist_state( $state );
		update_option( 'mobo_core_entity_meta_late_repair_pending_v1', 1, false );
	}

	/** @return void */
	private static function record_failed_deleted_meta_ids( $ids ) {
		$state = self::load_state();
		$current = isset( $state['failedDeletedMetaIds'] ) && is_array( $state['failedDeletedMetaIds'] ) ? $state['failedDeletedMetaIds'] : array();
		$current = array_values( array_filter( array_unique( array_merge( array_map( 'absint', $current ), array_map( 'absint', (array) $ids ) ) ) ) );
		if ( count( $current ) > self::MAX_FAILED_META_IDS ) {
			$current = array_slice( $current, -self::MAX_FAILED_META_IDS );
			$state['failedDeletedMetaIdsTruncated'] = true;
		}
		$state['failedDeletedMetaIds'] = $current;
		$state['lateDeleteFailures'] = absint( $state['lateDeleteFailures'] ?? 0 ) + count( (array) $ids );
		$state['status'] = 'late-delete-mirror-failed';
		self::persist_state( $state );
		update_option( 'mobo_core_entity_meta_late_repair_pending_v1', 1, false );
	}

	/**
	 * Retry a previously recorded post-terminal mirror/delete failure. This runs only
	 * when the tiny pending option exists, so ordinary requests pay no runtime-table
	 * read after a healthy cutover.
	 *
	 * @return bool
	 */
	public static function drain_late_failures() {
		if ( ! self::is_authoritative_without_filter() || ! self::storage_ready() ) { return false; }
		if ( ! self::recover_pending_cleanup() ) { return false; }
		if ( ! self::retry_failed_legacy_commits() ) { return false; }
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		$deleted_ids = isset( $state['failedDeletedMetaIds'] ) && is_array( $state['failedDeletedMetaIds'] ) ? array_values( array_filter( array_unique( array_map( 'absint', $state['failedDeletedMetaIds'] ) ) ) ) : array();
		if ( ! empty( $deleted_ids ) ) {
			global $wpdb;
			$table = self::table_name();
			$ph = implode( ',', array_fill( 0, count( $deleted_ids ), '%d' ) );
			$ok = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE legacy_meta_id IN ({$ph})", $deleted_ids ) );
			if ( false === $ok ) { return false; }
			$state = self::load_state();
			if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
			$state['failedDeletedMetaIds'] = array();
			self::persist_state( $state );
		}
		$cleanup_state = self::load_state();
		if ( ! empty( $cleanup_state['__stateLoadError'] ) ) { return false; }
		$cleanup = self::cleanup_one_batch( $cleanup_state );
		if ( false === $cleanup ) { return false; }
		$pruned = self::prune_stale_pretransition_mirrors();
		if ( false === $pruned ) { return false; }
		$state = self::load_state();
		if ( ! empty( $state['__stateLoadError'] ) ) { return false; }
		$pending_commits = isset( $state['failedMetaIds'] ) && is_array( $state['failedMetaIds'] ) ? array_filter( array_map( 'absint', $state['failedMetaIds'] ) ) : array();
		$pending_deletes = isset( $state['failedDeletedMetaIds'] ) && is_array( $state['failedDeletedMetaIds'] ) ? array_filter( array_map( 'absint', $state['failedDeletedMetaIds'] ) ) : array();
		$residue = self::physical_residue_count();
		if ( empty( $pending_commits ) && empty( $pending_deletes ) && 0 === $residue ) {
			delete_option( 'mobo_core_entity_meta_late_repair_pending_v1' );
			return true;
		}
		return false;
	}

	/** @return array */
	private static function load_state() {
		global $wpdb;
		$base = array(
			'status' => 'pending',
			'copyCursor' => 0,
			'copyComplete' => false,
			'copiedRows' => 0,
			'transitionActive' => false,
			'transitionVersion' => 0,
			'transitionActivatedAt' => 0,
			'fenceActive' => false,
			'fenceVersion' => 0,
			'fenceActivatedAt' => 0,
			'cleanedRows' => 0,
			'cleanupCursor' => 0,
			'cleanupPending' => array(),
			'cleanupRewinds' => 0,
			'physicalResidue' => null,
			'failedMetaIds' => array(),
			'failedDeletedMetaIds' => array(),
			'complete' => false,
			'lastError' => '',
			'stateRevision' => 0,
			'__persistedStateJson' => null,
			'__stateLoadError' => false,
		);
		if ( ! self::storage_ready() ) { return $base; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT state_json FROM {$table} WHERE connection_id=0 AND worker_name=%s LIMIT 1", self::WORKER_NAME ), ARRAY_A );
		if ( '' !== trim( (string) $wpdb->last_error ) ) { $base['__stateLoadError'] = true; return $base; }
		if ( ! is_array( $row ) ) { return $base; }
		$json = (string) ( $row['state_json'] ?? '' );
		$base['__persistedStateJson'] = $json;
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) { $base['__stateLoadError'] = true; return $base; }
		$out = array_merge( $base, $decoded );
		$out['__persistedStateJson'] = $json;
		$out['__stateLoadError'] = false;
		return $out;
	}

	/** CAS-persist worker state so stale lock owners cannot overwrite newer progress. @return bool */
	private static function persist_state( $state, $finished = false ) {
		global $wpdb;
		if ( class_exists( 'Mobo_Core_Migration' ) && ! Mobo_Core_Migration::accelerated_slice_lock_is_valid() ) { return false; }
		if ( ! empty( $state['__stateLoadError'] ) || ! self::storage_ready() ) { return false; }
		$table = Mobo_Core_Storage_Schema::table_name( 'runtime' );
		$now = current_time( 'mysql', true );
		$expected_raw = $state['__persistedStateJson'] ?? null;
		unset( $state['__persistedStateJson'], $state['__stateLoadError'] );
		$state['stateRevision'] = absint( $state['stateRevision'] ?? 0 ) + 1;
		$json = wp_json_encode( $state );
		if ( ! is_string( $json ) ) { return false; }
		$finished_at = $finished ? $now : '';
		$cursor = (string) absint( $state['copyCursor'] ?? 0 );
		$error = sanitize_key( (string) ( $state['lastError'] ?? '' ) );
		if ( null === $expected_raw ) {
			$sql = "INSERT IGNORE INTO {$table} (connection_id,worker_name,last_started_at,last_finished_at,last_cursor,consecutive_failures,circuit_state,last_error_code,state_json,created_at,updated_at) VALUES (0,%s,%s,NULLIF(%s,''),%s,0,'closed',%s,%s,%s,%s)";
			return 1 === absint( $wpdb->query( $wpdb->prepare( $sql, self::WORKER_NAME, $now, $finished_at, $cursor, $error, $json, $now, $now ) ) );
		}
		$sql = "UPDATE {$table} SET last_started_at=%s,last_finished_at=IF(%s='',last_finished_at,%s),last_cursor=%s,last_error_code=%s,state_json=%s,updated_at=%s WHERE connection_id=0 AND worker_name=%s AND BINARY state_json=BINARY %s";
		return 1 === absint( $wpdb->query( $wpdb->prepare( $sql, $now, $finished_at, $finished_at, $cursor, $error, $json, $now, self::WORKER_NAME, (string) $expected_raw ) ) );
	}

	/** @return void */
	private static function record_state_error( $state, $error, $status ) {
		$state['status']    = sanitize_key( (string) $status );
		$state['lastError'] = sanitize_key( (string) $error );
		self::persist_state( $state );
	}
}
