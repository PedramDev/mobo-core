<?php
/**
 * Compatibility boundary between legacy persistence and custom storage.
 *
 * Release 10.33.89 keeps dual-write mirroring authoritative whenever the normal Legacy path runs, while explicitly opted-in Custom-Only suppression may short-circuit selected operational postmeta updates only after Custom-first commit succeeds.
 * Successful legacy commits are mirrored to Custom Storage; shadow-read builds
 * canonical parity evidence and promotion-gated identity lookups may read Custom
 * first while retaining fail-safe Legacy fallback.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Storage_Bridge {

	const LAST_ERROR_OPTION = 'mobo_core_storage_dual_write_last_error';
	const LAST_ERROR_AT_OPTION = 'mobo_core_storage_dual_write_last_error_at';
	const FAILURE_COUNT_OPTION = 'mobo_core_storage_dual_write_failure_count';

	/** @var int Trusted migration-only scope that pins Operational reads to Legacy before the terminal fence. */
	private static $migration_pre_fence_legacy_depth = 0;

	/** @return string */
	public static function mode() {
		return Mobo_Core_Storage_Mode::effective();
	}

	/** @return bool */
	public static function custom_schema_ready() {
		return Mobo_Core_Storage_Schema::is_ready();
	}

	/** @return bool */
	public static function should_write_custom() {
		return Mobo_Core_Storage_Mode::writes_custom();
	}

	/** @return bool */
	public static function should_read_custom_primary() {
		return Mobo_Core_Storage_Mode::reads_custom_primary();
	}

	/** @return bool */
	public static function should_compare_shadow_read() {
		return Mobo_Core_Storage_Mode::compares_shadow_reads();
	}

	/**
	 * Enter a trusted migration-only pre-fence Legacy read scope.
	 *
	 * This scope exists solely to avoid repeatedly re-reading the durable terminal
	 * fence while one migration worker is already holding the site-wide migration
	 * lock and has proven that the fence is not active. Runtime/webhook/write paths
	 * never enter this scope and retain per-call fence checks.
	 *
	 * @return void
	 */
	public static function begin_migration_pre_fence_legacy_reads() {
		self::$migration_pre_fence_legacy_depth++;
	}

	/** @return void */
	public static function end_migration_pre_fence_legacy_reads() {
		if ( self::$migration_pre_fence_legacy_depth > 0 ) {
			self::$migration_pre_fence_legacy_depth--;
		}
	}

	/** @return bool */
	private static function migration_pre_fence_legacy_reads_active() {
		return self::$migration_pre_fence_legacy_depth > 0;
	}

	/**
	 * Canonicalize the currently authoritative legacy Product state.
	 * Passing null for $sync_incomplete resolves the flag from Product Map.
	 *
	 * @return array|null
	 */
	public static function legacy_product_snapshot( $product_guid, $product_id, $sync_incomplete = null ) {
		$product_guid = sanitize_text_field( (string) $product_guid );
		$product_id   = absint( $product_id );
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) || ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) ) {
			return null;
		}

		if ( null === $sync_incomplete ) {
			$map = self::legacy_map_snapshot( $product_guid, Mobo_Core_Product_Map::TYPE_PRODUCT );
			$sync_incomplete = is_array( $map ) ? ! empty( $map['sync_incomplete'] ) : false;
		}

		$product_seen_revision    = absint( self::operational_post_meta( $product_id, '_mobo_product_seen_revision' ) );
		$product_applied_revision = absint( self::operational_post_meta( $product_id, '_mobo_product_applied_revision' ) );
		$variant_seen_revision    = absint( self::operational_post_meta( $product_id, '_mobo_variant_seen_revision' ) );
		$variant_applied_revision = absint( self::operational_post_meta( $product_id, '_mobo_variant_applied_revision' ) );
		$product_source_hash_raw  = self::operational_post_meta( $product_id, '_mobo_product_source_hash' );
		$sync_incomplete_meta_raw = self::operational_post_meta( $product_id, 'mobo_sync_incomplete' );
		$meta_presence = array(
			'product_seen_revision'    => self::operational_meta_exists( $product_id, '_mobo_product_seen_revision' ),
			'product_applied_revision' => self::operational_meta_exists( $product_id, '_mobo_product_applied_revision' ),
			'variant_seen_revision'    => self::operational_meta_exists( $product_id, '_mobo_variant_seen_revision' ),
			'variant_applied_revision' => self::operational_meta_exists( $product_id, '_mobo_variant_applied_revision' ),
			'product_source_hash'      => self::operational_meta_exists( $product_id, '_mobo_product_source_hash' ),
			'sync_incomplete_meta'     => self::operational_meta_exists( $product_id, 'mobo_sync_incomplete' ),
		);
		/* A clean Product Map can be committed immediately before the durable
		 * completion marker flips from 1 to 0. During that narrow crash-safe
		 * handoff, never mirror a contradictory Custom row (derived complete,
		 * explicit marker incomplete): the explicit incomplete marker keeps the
		 * aggregate state pending until its own verified terminal mutation lands. */
		if ( ! empty( $meta_presence['sync_incomplete_meta'] ) && '1' === (string) $sync_incomplete_meta_raw ) {
			$sync_incomplete = true;
		}
		$desired_revision = max( $product_seen_revision, $variant_seen_revision );
		$applied_revision = self::aggregate_product_applied_revision( $product_id );
		if ( ! $sync_incomplete ) {
			/* Legacy completion is the authoritative overall product boundary. */
			$applied_revision = max( $applied_revision, $desired_revision );
		}
		$desired_revision = max( $desired_revision, $applied_revision );
		$product_source_hash = self::normalize_hash( $product_source_hash_raw );
		$hash = $product_source_hash;
		$status = ( $sync_incomplete || $desired_revision > $applied_revision ) ? 'pending' : 'applied';

		return array(
			'product_guid'      => $product_guid,
			'woo_product_id'    => $product_id,
			'portal_product_id' => self::first_positive_meta( $product_id, array( 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'PortalProductId' ) ),
			'simple_variant_guid' => self::first_valid_guid_meta( $product_id, array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' ) ),
			'simple_portal_variant_id' => self::first_positive_meta( $product_id, array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId' ) ),
			'product_seen_revision'    => $product_seen_revision,
			'product_applied_revision' => $product_applied_revision,
			'variant_seen_revision'    => $variant_seen_revision,
			'variant_applied_revision' => $variant_applied_revision,
			'product_source_hash'      => $product_source_hash,
			'sync_incomplete_meta'     => '1' === (string) $sync_incomplete_meta_raw,
			'meta_presence'            => $meta_presence,
			'product_seen_revision_present'    => ! empty( $meta_presence['product_seen_revision'] ),
			'product_applied_revision_present' => ! empty( $meta_presence['product_applied_revision'] ),
			'variant_seen_revision_present'    => ! empty( $meta_presence['variant_seen_revision'] ),
			'variant_applied_revision_present' => ! empty( $meta_presence['variant_applied_revision'] ),
			'product_source_hash_present'      => ! empty( $meta_presence['product_source_hash'] ),
			'sync_incomplete_meta_present'     => ! empty( $meta_presence['sync_incomplete_meta'] ),
			'desired_revision'  => $desired_revision,
			'applied_revision'  => $applied_revision,
			'desired_hash'      => $hash,
			'applied_hash'      => $sync_incomplete ? '' : $hash,
			'sync_status'       => $status,
			'dirty_flags'       => 'pending' === $status ? 1 : 0,
			'sync_incomplete'   => (bool) $sync_incomplete,
		);
	}

	/**
	 * Canonicalize the currently authoritative legacy Variation state.
	 * Parent GUID/sync flag may be supplied from a bulk Product Map read.
	 *
	 * @return array|null
	 */
	public static function legacy_variant_snapshot( $variant_guid, $variation_id, $product_guid = '', $sync_incomplete = null ) {
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || 'product_variation' !== get_post_type( $variation_id ) || ! class_exists( 'Mobo_Core_Remote_Identity_Policy' ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) ) {
			return null;
		}

		$map = null;
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || null === $sync_incomplete ) {
			$map = self::legacy_map_snapshot( $variant_guid, Mobo_Core_Product_Map::TYPE_VARIATION );
		}
		if ( ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) && is_array( $map ) ) {
			$product_guid = sanitize_text_field( (string) ( isset( $map['parent_remote_guid'] ) ? $map['parent_remote_guid'] : '' ) );
		}
		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		if ( ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) && $parent_id > 0 ) {
			$product_guid = sanitize_text_field( (string) self::legacy_post_meta( $parent_id, 'product_guid' ) );
		}
		if ( ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) ) {
			return null;
		}
		if ( null === $sync_incomplete ) {
			$sync_incomplete = is_array( $map ) ? ! empty( $map['sync_incomplete'] ) : false;
		}

		$variant_seen_revision    = absint( self::operational_post_meta( $variation_id, '_mobo_variant_seen_revision' ) );
		$variant_applied_revision = absint( self::operational_post_meta( $variation_id, '_mobo_variant_applied_revision' ) );
		$variant_source_hash_raw  = self::operational_post_meta( $variation_id, '_mobo_variant_source_hash' );
		$sync_incomplete_meta_raw = self::operational_post_meta( $variation_id, 'mobo_sync_incomplete' );
		$meta_presence = array(
			'variant_seen_revision'    => self::operational_meta_exists( $variation_id, '_mobo_variant_seen_revision' ),
			'variant_applied_revision' => self::operational_meta_exists( $variation_id, '_mobo_variant_applied_revision' ),
			'variant_source_hash'      => self::operational_meta_exists( $variation_id, '_mobo_variant_source_hash' ),
			'sync_incomplete_meta'     => self::operational_meta_exists( $variation_id, 'mobo_sync_incomplete' ),
		);
		/* Keep the aggregate Variation state fail-closed while the explicit crash
		 * marker is still 1, even if the Variation Map clean commit has already
		 * succeeded. The following verified marker mutation then converges both. */
		if ( ! empty( $meta_presence['sync_incomplete_meta'] ) && '1' === (string) $sync_incomplete_meta_raw ) {
			$sync_incomplete = true;
		}
		$desired_revision = $variant_seen_revision;
		$applied_revision = $variant_applied_revision;
		$desired_revision = max( $desired_revision, $applied_revision );
		$variant_source_hash = self::normalize_hash( $variant_source_hash_raw );
		$hash = $variant_source_hash;
		$status = ( $sync_incomplete || $desired_revision > $applied_revision ) ? 'pending' : 'applied';

		return array(
			'variant_guid'      => $variant_guid,
			'product_guid'      => $product_guid,
			'woo_variation_id'  => $variation_id,
			'woo_product_id'    => $parent_id,
			'portal_variant_id' => self::first_positive_meta( $variation_id, array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id', 'PortalVariantId' ) ),
			'variant_seen_revision'    => $variant_seen_revision,
			'variant_applied_revision' => $variant_applied_revision,
			'variant_source_hash'      => $variant_source_hash,
			'sync_incomplete_meta'     => '1' === (string) $sync_incomplete_meta_raw,
			'meta_presence'            => $meta_presence,
			'variant_seen_revision_present'    => ! empty( $meta_presence['variant_seen_revision'] ),
			'variant_applied_revision_present' => ! empty( $meta_presence['variant_applied_revision'] ),
			'variant_source_hash_present'      => ! empty( $meta_presence['variant_source_hash'] ),
			'sync_incomplete_meta_present'     => ! empty( $meta_presence['sync_incomplete_meta'] ),
			'desired_revision'  => $desired_revision,
			'applied_revision'  => $applied_revision,
			'desired_hash'      => $hash,
			'applied_hash'      => $sync_incomplete ? '' : $hash,
			'sync_status'       => $status,
			'dirty_flags'       => 'pending' === $status ? 1 : 0,
			'sync_incomplete'   => (bool) $sync_incomplete,
		);
	}

	/**
	 * Mirror a successful legacy Product Map commit.
	 *
	 * @return bool True when disabled or mirrored successfully; false only for an
	 *              observable custom-storage mirror failure.
	 */
	public static function mirror_product_legacy_state( $product_guid, $product_id, $sync_incomplete = false ) {
		if ( ! self::should_write_custom() ) {
			return true;
		}

		$product_guid = sanitize_text_field( (string) $product_guid );
		$product_id   = absint( $product_id );
		try {
			$snapshot = self::legacy_product_snapshot( $product_guid, $product_id, (bool) $sync_incomplete );
			if ( ! is_array( $snapshot ) ) {
				return self::record_failure( 'product', $product_guid, $product_id, 'invalid_identity' );
			}
			$repo = new Mobo_Core_Product_State_Repository();
			$ok = $repo->mirror_legacy_state(
				$snapshot['product_guid'],
				$snapshot['woo_product_id'],
				$snapshot['portal_product_id'],
				$snapshot['desired_revision'],
				$snapshot['applied_revision'],
				$snapshot['desired_hash'],
				! empty( $snapshot['sync_incomplete'] ),
				$snapshot['product_seen_revision'],
				$snapshot['product_applied_revision'],
				$snapshot['variant_seen_revision'],
				$snapshot['variant_applied_revision'],
				$snapshot['meta_presence'],
				! empty( $snapshot['sync_incomplete_meta'] )
			);
			/*
			 * Migration may call this bridge from plugins_loaded, before WooCommerce
			 * has fired woocommerce_init. Never instantiate WC_Product here. Legacy
			 * simple-variant identity is explicit on the Product row; if either value
			 * exists, mirror it directly into the dedicated Product-state columns.
			 */
			if ( $ok && 'product' === get_post_type( $product_id ) ) {
				$simple_guid   = Mobo_Core_Remote_Identity_Policy::is_valid( $snapshot['simple_variant_guid'] ) ? $snapshot['simple_variant_guid'] : null;
				$simple_portal = absint( $snapshot['simple_portal_variant_id'] ) > 0 ? absint( $snapshot['simple_portal_variant_id'] ) : null;
				if ( null !== $simple_guid || null !== $simple_portal ) {
					$ok = $repo->terminal_set_simple_identity( $product_id, $simple_guid, $simple_portal );
				}
			}
			if ( $ok && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $product_id ); }
			return $ok ? true : self::record_failure( 'product', $product_guid, $product_id, 'repository_write_failed' );
		} catch ( Throwable $e ) {
			return self::record_failure( 'product', $product_guid, $product_id, 'internal_exception' );
		}
	}

	/** Mirror a successful legacy Variation Product Map commit. @return bool */
	public static function mirror_variant_legacy_state( $variant_guid, $variation_id, $product_guid, $sync_incomplete = false ) {
		if ( ! self::should_write_custom() ) {
			return true;
		}

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$variation_id = absint( $variation_id );
		try {
			$snapshot = self::legacy_variant_snapshot( $variant_guid, $variation_id, $product_guid, (bool) $sync_incomplete );
			if ( ! is_array( $snapshot ) ) {
				return self::record_failure( 'variant', $variant_guid, $variation_id, 'invalid_identity' );
			}
			$repo = new Mobo_Core_Variant_State_Repository();
			$ok = $repo->mirror_legacy_state(
				$snapshot['variant_guid'],
				$snapshot['product_guid'],
				$snapshot['woo_variation_id'],
				$snapshot['woo_product_id'],
				$snapshot['portal_variant_id'],
				$snapshot['desired_revision'],
				$snapshot['applied_revision'],
				$snapshot['desired_hash'],
				! empty( $snapshot['sync_incomplete'] ),
				$snapshot['variant_seen_revision'],
				$snapshot['variant_applied_revision'],
				$snapshot['meta_presence'],
				! empty( $snapshot['sync_incomplete_meta'] )
			);
			if ( $ok && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $variation_id ); }
			return $ok ? true : self::record_failure( 'variant', $variant_guid, $variation_id, 'repository_write_failed' );
		} catch ( Throwable $e ) {
			return self::record_failure( 'variant', $variant_guid, $variation_id, 'internal_exception' );
		}
	}

	/** @return array|null */
	private static function legacy_map_snapshot( $guid, $object_type ) {
		global $wpdb;
		$guid = sanitize_text_field( (string) $guid );
		$object_type = sanitize_key( (string) $object_type );
		if ( '' === $guid || ! class_exists( 'Mobo_Core_Product_Map' ) || ! Mobo_Core_Product_Map::table_exists() ) {
			return null;
		}
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 ) { return null; }
		$table = Mobo_Core_Product_Map::table_name();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wp_post_id, parent_remote_guid, sync_incomplete FROM {$table} WHERE connection_id=%d AND remote_guid = %s AND object_type = %s LIMIT 1",
				$connection_id,
				$guid,
				$object_type
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Derive one conservative product-level applied boundary from legacy watermarks.
	 */
	private static function aggregate_product_applied_revision( $product_id ) {
		$product_id = absint( $product_id );
		$product_seen = absint( self::operational_post_meta( $product_id, '_mobo_product_seen_revision' ) );
		$variant_seen = absint( self::operational_post_meta( $product_id, '_mobo_variant_seen_revision' ) );
		$product_applied = absint( self::operational_post_meta( $product_id, '_mobo_product_applied_revision' ) );
		$variant_applied = absint( self::operational_post_meta( $product_id, '_mobo_variant_applied_revision' ) );

		$required = array();
		if ( $product_seen > 0 ) { $required[] = $product_applied; }
		if ( $variant_seen > 0 ) { $required[] = $variant_applied; }
		if ( ! empty( $required ) ) {
			return min( $required );
		}
		return max( $product_applied, $variant_applied );
	}


	/** @return string */
	private static function first_valid_guid_meta( $post_id, $keys ) {
		$post_id = absint( $post_id );
		foreach ( (array) $keys as $key ) {
			$value = sanitize_text_field( (string) self::legacy_post_meta( $post_id, (string) $key ) );
			if ( Mobo_Core_Remote_Identity_Policy::is_valid( $value ) ) { return $value; }
		}
		return '';
	}

	/** @return int */
	private static function first_positive_meta( $post_id, $keys ) {
		$post_id = absint( $post_id );
		foreach ( (array) $keys as $key ) {
			$value = absint( self::legacy_post_meta( $post_id, (string) $key ) );
			if ( $value > 0 ) { return $value; }
		}
		return 0;
	}

	/**
	 * Read one operational value from the authority that is valid for the current
	 * retirement phase. Before terminal cutover the Legacy row remains canonical;
	 * after the durable fence, physical postmeta may already be retired and the
	 * Custom-backed WordPress meta view is authoritative. Product Map mirroring
	 * must never reconstruct terminal operational presence/value from deleted
	 * Legacy rows.
	 *
	 * @return mixed
	 */
	private static function operational_post_meta( $post_id, $meta_key ) {
		$post_id = absint( $post_id );
		$meta_key = (string) $meta_key;
		if ( self::migration_pre_fence_legacy_reads_active() ) {
			return self::legacy_post_meta( $post_id, $meta_key );
		}
		$terminal = class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement', false )
			&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
			&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $post_id, $meta_key );
		if ( $terminal ) {
			return get_post_meta( $post_id, $meta_key, true );
		}
		return self::legacy_post_meta( $post_id, $meta_key );
	}

	/** Preserve absent-vs-explicit-empty/zero semantics for the current operational authority. @return bool */
	private static function operational_meta_exists( $post_id, $meta_key ) {
		$post_id = absint( $post_id );
		$meta_key = (string) $meta_key;
		if ( self::migration_pre_fence_legacy_reads_active() ) {
			return self::legacy_meta_exists( $post_id, $meta_key );
		}
		$terminal = class_exists( 'Mobo_Core_Storage_Operational_Meta_Retirement', false )
			&& Mobo_Core_Storage_Operational_Meta_Retirement::is_fenced()
			&& Mobo_Core_Storage_Operational_Meta_Retirement::key_applies_to_object( $post_id, $meta_key );
		if ( $terminal ) {
			return metadata_exists( 'post', $post_id, $meta_key );
		}
		return self::legacy_meta_exists( $post_id, $meta_key );
	}

	/** Read one postmeta value from the authoritative Legacy store without Custom-Primary short-circuiting it. @return mixed */
	private static function legacy_post_meta( $post_id, $meta_key ) {
		$primary_guarded = class_exists( 'Mobo_Core_Storage_Primary_State_Read' );
		$identity_guarded = class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement', false );
		if ( $primary_guarded ) { Mobo_Core_Storage_Primary_State_Read::begin_legacy_bypass(); }
		if ( $identity_guarded ) { Mobo_Core_Storage_Identity_Meta_Retirement::begin_legacy_read_bypass(); }
		try {
			return get_post_meta( absint( $post_id ), (string) $meta_key, true );
		} finally {
			if ( $identity_guarded ) { Mobo_Core_Storage_Identity_Meta_Retirement::end_legacy_read_bypass(); }
			if ( $primary_guarded ) { Mobo_Core_Storage_Primary_State_Read::end_legacy_bypass(); }
		}
	}

	/** Whether one authoritative Legacy postmeta row exists, preserving absent-vs-explicit-empty/zero semantics. @return bool */
	private static function legacy_meta_exists( $post_id, $meta_key ) {
		$primary_guarded = class_exists( 'Mobo_Core_Storage_Primary_State_Read' );
		$identity_guarded = class_exists( 'Mobo_Core_Storage_Identity_Meta_Retirement', false );
		if ( $primary_guarded ) { Mobo_Core_Storage_Primary_State_Read::begin_legacy_bypass(); }
		if ( $identity_guarded ) { Mobo_Core_Storage_Identity_Meta_Retirement::begin_legacy_read_bypass(); }
		try {
			return metadata_exists( 'post', absint( $post_id ), (string) $meta_key );
		} finally {
			if ( $identity_guarded ) { Mobo_Core_Storage_Identity_Meta_Retirement::end_legacy_read_bypass(); }
			if ( $primary_guarded ) { Mobo_Core_Storage_Primary_State_Read::end_legacy_bypass(); }
		}
	}

	/** @return string */
	private static function normalize_hash( $hash ) {
		return class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $hash ) : '';
	}

	/** Connection-owned dual-write failure count. @return int */
	public static function failure_count() {
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() && self::telemetry_connection_id() > 0 ) {
			Mobo_Core_State_Store::migrate_option( self::FAILURE_COUNT_OPTION, self::FAILURE_COUNT_OPTION );
			return absint( Mobo_Core_State_Store::get( self::FAILURE_COUNT_OPTION, 0 ) );
		}
		return self::legacy_telemetry_allowed() ? absint( get_option( self::FAILURE_COUNT_OPTION, 0 ) ) : 0;
	}

	/** Connection-owned last dual-write failure payload. @return string */
	public static function last_error() {
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() && self::telemetry_connection_id() > 0 ) {
			Mobo_Core_State_Store::migrate_option( self::LAST_ERROR_OPTION, self::LAST_ERROR_OPTION );
			return (string) Mobo_Core_State_Store::get( self::LAST_ERROR_OPTION, '' );
		}
		return self::legacy_telemetry_allowed() ? (string) get_option( self::LAST_ERROR_OPTION, '' ) : '';
	}

	/** Connection-owned last dual-write failure time. @return int */
	public static function last_error_at() {
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() && self::telemetry_connection_id() > 0 ) {
			Mobo_Core_State_Store::migrate_option( self::LAST_ERROR_AT_OPTION, self::LAST_ERROR_AT_OPTION );
			return absint( Mobo_Core_State_Store::get( self::LAST_ERROR_AT_OPTION, 0 ) );
		}
		return self::legacy_telemetry_allowed() ? absint( get_option( self::LAST_ERROR_AT_OPTION, 0 ) ) : 0;
	}

	/** @return int */
	private static function telemetry_connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Legacy wp_options telemetry belongs only to the durable Default owner. @return bool */
	private static function legacy_telemetry_allowed() {
		return ! class_exists( 'Mobo_Core_Connection_Context' ) || ! method_exists( 'Mobo_Core_Connection_Context', 'legacy_default_owner_allowed' ) || Mobo_Core_Connection_Context::legacy_default_owner_allowed();
	}

	/**
	 * Persist failure telemetry only on the abnormal path. No success-path option
	 * writes are added to storefront/product synchronization traffic.
	 *
	 * @return false
	 */
	private static function record_failure( $entity_type, $guid, $object_id, $message ) {
		$payload = array(
			'entityType' => sanitize_key( (string) $entity_type ),
			'guid'       => sanitize_text_field( (string) $guid ),
			'objectId'   => absint( $object_id ),
			'message'    => substr( sanitize_text_field( (string) $message ), 0, 500 ),
			'at'         => gmdate( 'c' ),
		);
		$encoded = wp_json_encode( $payload );
		$connection_id = self::telemetry_connection_id();
		if ( class_exists( 'Mobo_Core_State_Store' ) && Mobo_Core_State_Store::is_available() && $connection_id > 0 ) {
			Mobo_Core_State_Store::migrate_option( self::LAST_ERROR_OPTION, self::LAST_ERROR_OPTION );
			Mobo_Core_State_Store::migrate_option( self::LAST_ERROR_AT_OPTION, self::LAST_ERROR_AT_OPTION );
			Mobo_Core_State_Store::migrate_option( self::FAILURE_COUNT_OPTION, self::FAILURE_COUNT_OPTION );
			$count = self::failure_count() + 1;
			Mobo_Core_State_Store::set( self::LAST_ERROR_OPTION, $encoded );
			Mobo_Core_State_Store::set( self::LAST_ERROR_AT_OPTION, time() );
			Mobo_Core_State_Store::set( self::FAILURE_COUNT_OPTION, $count );
		} elseif ( self::legacy_telemetry_allowed() ) {
			if ( false === get_option( self::LAST_ERROR_OPTION, false ) ) { add_option( self::LAST_ERROR_OPTION, $encoded, '', false ); } else { update_option( self::LAST_ERROR_OPTION, $encoded, false ); }
			if ( false === get_option( self::LAST_ERROR_AT_OPTION, false ) ) { add_option( self::LAST_ERROR_AT_OPTION, time(), '', false ); } else { update_option( self::LAST_ERROR_AT_OPTION, time(), false ); }
			$count = absint( get_option( self::FAILURE_COUNT_OPTION, 0 ) ) + 1;
			if ( false === get_option( self::FAILURE_COUNT_OPTION, false ) ) { add_option( self::FAILURE_COUNT_OPTION, $count, '', false ); } else { update_option( self::FAILURE_COUNT_OPTION, $count, false ); }
		}
		if ( class_exists( 'Mobo_Core_Storage_Mode' ) ) { Mobo_Core_Storage_Mode::reset_request_cache(); }
		return false;
	}
}
