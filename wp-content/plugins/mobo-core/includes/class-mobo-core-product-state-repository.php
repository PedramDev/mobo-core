<?php
/** Custom-storage repository for product operational state. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Product_State_Repository {

	/** @return int */
	private function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Woo Product IDs are installation-global ownership anchors. @return bool */
	private function woo_owner_conflicts( $woo_product_id, $connection_id ) {
		global $wpdb;
		$woo_product_id = absint( $woo_product_id );
		$connection_id = absint( $connection_id );
		if ( $woo_product_id <= 0 || $connection_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = self::table_name();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM {$table} WHERE woo_product_id=%d LIMIT 1", $woo_product_id ) );
		return null !== $owner && absint( $owner ) !== $connection_id;
	}

	/** Request-local row-cache bucket (see Mobo_Core_Storage_Row_Cache). */
	const ROW_CACHE_BUCKET = 'products';

	/** @return string */
	public static function table_name() {
		return Mobo_Core_Storage_Schema::table_name( 'products' );
	}

	/**
	 * Read one row through the request-local cache. Any write to the Products
	 * table (from any code path) invalidates the bucket via the `query` filter,
	 * so a read after a write always reaches the database. Errors are not cached.
	 *
	 * @param string $cache_key Cache key.
	 * @param string $prepared Prepared SELECT.
	 * @return array|null
	 */
	private static function cached_row( $cache_key, $prepared, $object_id = 0 ) {
		global $wpdb;
		$hit    = false;
		$cached = Mobo_Core_Storage_Row_Cache::get( self::ROW_CACHE_BUCKET, $cache_key, $hit );
		if ( $hit ) {
			return is_array( $cached ) ? $cached : null;
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $prepared, ARRAY_A );
		$row = is_array( $row ) ? $row : null;
		if ( '' === trim( (string) $wpdb->last_error ) ) {
			$object_id = is_array( $row ) ? absint( $row['woo_product_id'] ?? 0 ) : absint( $object_id );
			Mobo_Core_Storage_Row_Cache::register_table( self::ROW_CACHE_BUCKET, self::table_name() );
			Mobo_Core_Storage_Row_Cache::set( self::ROW_CACHE_BUCKET, $cache_key, $row, $object_id );
		}
		return $row;
	}

	/**
	 * Upsert identity without changing desired/applied runtime state.
	 *
	 * @param string $product_guid Remote opaque product identity.
	 * @param int    $woo_product_id Local Woo product ID.
	 * @param int    $portal_product_id Optional numeric Portal ID.
	 * @return bool
	 */
	public function upsert_identity( $product_guid, $woo_product_id = 0, $portal_product_id = 0 ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );
		$connection_id     = $this->connection_id();
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}


		$woo_product_id    = absint( $woo_product_id );
		$portal_product_id = absint( $portal_product_id );
		if ( $connection_id <= 0 || $this->woo_owner_conflicts( $woo_product_id, $connection_id ) ) { return false; }
		$now               = current_time( 'mysql', true );
		$table             = self::table_name();

		$sql = "INSERT INTO {$table}
			(connection_id, product_guid, woo_product_id, portal_product_id, created_at, updated_at)
			VALUES (%d, %s, NULLIF(%d,0), NULLIF(%d,0), %s, %s)
			ON DUPLICATE KEY UPDATE
				woo_product_id = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(woo_product_id) IS NOT NULL, VALUES(woo_product_id), woo_product_id),
				portal_product_id = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(portal_product_id) IS NOT NULL, VALUES(portal_product_id), portal_product_id),
				updated_at = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid), VALUES(updated_at), updated_at)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, $product_guid, $woo_product_id, $portal_product_id, $now, $now ) );
		if ( false === $result ) {
			return false;
		}

		$persisted = $wpdb->get_row( $wpdb->prepare( "SELECT woo_product_id, portal_product_id FROM {$table} WHERE connection_id=%d AND product_guid = %s LIMIT 1", $connection_id, $product_guid ), ARRAY_A );
		return is_array( $persisted )
			&& ( $woo_product_id <= 0 || absint( isset( $persisted['woo_product_id'] ) ? $persisted['woo_product_id'] : 0 ) === $woo_product_id )
			&& ( $portal_product_id <= 0 || absint( isset( $persisted['portal_product_id'] ) ? $persisted['portal_product_id'] : 0 ) === $portal_product_id );
	}


	/**
	 * Persist the one purchasable remote Variant identity carried by a Woo Simple
	 * product. This is product-owned identity: no synthetic Woo variation row is
	 * created and the normal Product GUID/Woo-ID binding must already be coherent.
	 *
	 * @param string $product_guid Remote product GUID.
	 * @param int    $woo_product_id Woo Simple product ID.
	 * @param string $simple_variant_guid Remote variant GUID.
	 * @param int    $simple_portal_variant_id Numeric Portal variant ID.
	 * @return bool
	 */
	public function upsert_simple_variant_identity( $product_guid, $woo_product_id, $simple_variant_guid, $simple_portal_variant_id = 0 ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );
		$simple_variant_guid = sanitize_text_field( (string) $simple_variant_guid );
		$connection_id = $this->connection_id();
		$woo_product_id = absint( $woo_product_id );
		$simple_portal_variant_id = absint( $simple_portal_variant_id );
		if ( $connection_id <= 0 || $this->woo_owner_conflicts( $woo_product_id, $connection_id ) ) { return false; }
		if ( $woo_product_id <= 0
			|| ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid )
			|| ! Mobo_Core_Remote_Identity_Policy::is_valid( $simple_variant_guid )
			|| ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		$table = self::table_name();
		$now = current_time( 'mysql', true );
		$sql = "INSERT INTO {$table}
			(connection_id, product_guid, woo_product_id, simple_variant_guid, simple_portal_variant_id, created_at, updated_at)
			VALUES (%d, %s, %d, %s, NULLIF(%d,0), %s, %s)
			ON DUPLICATE KEY UPDATE
				woo_product_id = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid), VALUES(woo_product_id), woo_product_id),
				simple_variant_guid = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid), VALUES(simple_variant_guid), simple_variant_guid),
				simple_portal_variant_id = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(simple_portal_variant_id) IS NOT NULL, VALUES(simple_portal_variant_id), simple_portal_variant_id),
				updated_at = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid), VALUES(updated_at), updated_at)";
		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, $product_guid, $woo_product_id, $simple_variant_guid, $simple_portal_variant_id, $now, $now ) );
		if ( false === $result ) { return false; }

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT woo_product_id,simple_variant_guid,simple_portal_variant_id FROM {$table} WHERE connection_id=%d AND product_guid=%s LIMIT 1", $connection_id, $product_guid ), ARRAY_A );
		return is_array( $row )
			&& absint( isset( $row['woo_product_id'] ) ? $row['woo_product_id'] : 0 ) === $woo_product_id
			&& sanitize_text_field( (string) ( isset( $row['simple_variant_guid'] ) ? $row['simple_variant_guid'] : '' ) ) === $simple_variant_guid
			&& ( $simple_portal_variant_id <= 0 || absint( isset( $row['simple_portal_variant_id'] ) ? $row['simple_portal_variant_id'] : 0 ) === $simple_portal_variant_id );
	}


	/** Terminal identity bind. Product GUID rebinds are deliberately forbidden. @return bool */
	public function terminal_bind_product( $woo_product_id, $product_guid ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_product_id = absint( $woo_product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || $woo_product_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = self::table_name();
		$by_woo = $wpdb->get_row( $wpdb->prepare( "SELECT id,connection_id,product_guid FROM {$table} WHERE woo_product_id=%d LIMIT 1", $woo_product_id ), ARRAY_A );
		if ( is_array( $by_woo ) ) {
			return absint( isset( $by_woo['connection_id'] ) ? $by_woo['connection_id'] : 0 ) === $connection_id && sanitize_text_field( (string) $by_woo['product_guid'] ) === $product_guid;
		}
		$by_guid = $wpdb->get_row( $wpdb->prepare( "SELECT woo_product_id FROM {$table} WHERE connection_id=%d AND product_guid=%s LIMIT 1", $connection_id, $product_guid ), ARRAY_A );
		$stale_owner = is_array( $by_guid ) ? absint( isset( $by_guid['woo_product_id'] ) ? $by_guid['woo_product_id'] : 0 ) : 0;
		if ( $stale_owner > 0 && $stale_owner !== $woo_product_id ) {
			/* M12: the row may point at a post that was hard-deleted without the
			 * deleted_post cleanup (kill, bulk delete, backup restore). Re-point
			 * exactly that leftover row (CAS on the old id) instead of refusing the
			 * bind forever, which made every retry create another duplicate. A live
			 * (even trashed) owner is never taken over. */
			if ( ! $this->woo_post_missing( $stale_owner ) ) { return false; }
			$repointed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET woo_product_id=%d, updated_at=%s WHERE connection_id=%d AND product_guid=%s AND woo_product_id=%d", $woo_product_id, current_time( 'mysql', true ), $connection_id, $product_guid, $stale_owner ) );
			if ( 1 !== (int) $repointed ) { return false; }
			if ( class_exists( 'Mobo_Core_Storage_Row_Cache' ) && method_exists( 'Mobo_Core_Storage_Row_Cache', 'flush_all' ) ) { Mobo_Core_Storage_Row_Cache::flush_all(); }
			if ( class_exists( 'Mobo_Core_Logger' ) ) {
				Mobo_Core_Logger::warning( 'Repaired a product identity row that pointed at a deleted post.', array( 'productGuid' => $product_guid, 'deletedPostId' => $stale_owner, 'productId' => $woo_product_id ) );
			}
		}
		return $this->upsert_identity( $product_guid, $woo_product_id, 0 );
	}

	/**
	 * Whether a Woo post id no longer exists at all (hard-deleted).
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private function woo_post_missing( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) { return false; }
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d LIMIT 1", $post_id ) );
		return '' === (string) $wpdb->last_error && null === $found;
	}

	/** Set canonical Portal product ID for an already-bound Product. @return bool */
	public function terminal_set_portal_product_id( $woo_product_id, $portal_product_id ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_product_id = absint( $woo_product_id );
		$portal_product_id = absint( $portal_product_id );
		if ( $connection_id <= 0 || $woo_product_id <= 0 || $portal_product_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = self::table_name();
		$now = current_time( 'mysql', true );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET portal_product_id=%d,updated_at=%s WHERE connection_id=%d AND woo_product_id=%d", $portal_product_id, $now, $connection_id, $woo_product_id ) );
		if ( false === $updated ) { return false; }
		$row = $this->get_by_woo_product_id( $woo_product_id );
		return is_array( $row ) && absint( isset( $row['portal_product_id'] ) ? $row['portal_product_id'] : 0 ) === $portal_product_id;
	}

	/**
	 * Set/clear product-owned Simple Variant identity. Null means preserve; an
	 * explicit empty GUID or zero Portal ID clears that canonical field.
	 * @return bool
	 */
	public function terminal_set_simple_identity( $woo_product_id, $simple_variant_guid = null, $simple_portal_variant_id = null ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_product_id = absint( $woo_product_id );
		if ( $connection_id <= 0 || $woo_product_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = self::table_name();
		$row = $this->get_by_woo_product_id( $woo_product_id );
		if ( ! is_array( $row ) ) { return false; }
		$sets = array(); $args = array();
		if ( null !== $simple_variant_guid ) {
			$guid = sanitize_text_field( (string) $simple_variant_guid );
			if ( '' !== $guid && ! Mobo_Core_Remote_Identity_Policy::is_valid( $guid ) ) { return false; }
			if ( '' !== $guid ) {
				$owner = $wpdb->get_var( $wpdb->prepare( "SELECT woo_product_id FROM {$table} WHERE connection_id=%d AND simple_variant_guid=%s AND woo_product_id<>%d LIMIT 1", $connection_id, $guid, $woo_product_id ) );
				if ( null !== $owner ) { return false; }
			}
			$sets[] = 'simple_variant_guid=NULLIF(%s,\'\')'; $args[] = $guid;
		}
		if ( null !== $simple_portal_variant_id ) {
			$id = absint( $simple_portal_variant_id );
			$sets[] = 'simple_portal_variant_id=NULLIF(%d,0)'; $args[] = $id;
		}
		if ( empty( $sets ) ) { return true; }
		$sets[] = 'updated_at=%s'; $args[] = current_time( 'mysql', true );
		$args[] = $connection_id;
		$args[] = $woo_product_id;
		$sql = "UPDATE {$table} SET " . implode( ',', $sets ) . ' WHERE connection_id=%d AND woo_product_id=%d';
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( false === $result ) { return false; }
		$after = $this->get_by_woo_product_id( $woo_product_id );
		if ( ! is_array( $after ) ) { return false; }
		if ( null !== $simple_variant_guid && sanitize_text_field( (string) ( isset( $after['simple_variant_guid'] ) ? $after['simple_variant_guid'] : '' ) ) !== sanitize_text_field( (string) $simple_variant_guid ) ) { return false; }
		if ( null !== $simple_portal_variant_id && absint( isset( $after['simple_portal_variant_id'] ) ? $after['simple_portal_variant_id'] : 0 ) !== absint( $simple_portal_variant_id ) ) { return false; }
		return true;
	}

	/**
	 * Mark a desired revision/hash and dirty flags.
	 *
	 * @param string $product_guid Product identity.
	 * @param int    $revision Desired revision.
	 * @param string $hash Canonical desired-state hash.
	 * @param int    $dirty_flags Dirty component bitmap.
	 * @return bool
	 */
	public function mark_desired( $product_guid, $revision, $hash = '', $dirty_flags = 0 ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );
		$connection_id = $this->connection_id();
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		$revision    = max( 0, (int) $revision );
		$hash        = self::normalize_hash( $hash );
		$dirty_flags = max( 0, (int) $dirty_flags );
		$now         = current_time( 'mysql', true );
		$table       = self::table_name();

		$sql = "INSERT INTO {$table}
			(connection_id, product_guid, desired_revision, desired_hash, sync_status, dirty_flags, last_received_at, created_at, updated_at)
			VALUES (%d, %s, %d, %s, 'pending', %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				desired_hash = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)))) AND VALUES(desired_hash) <> '', VALUES(desired_hash), desired_hash),
				sync_status = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)))) AND (VALUES(desired_revision) > applied_revision OR (VALUES(desired_hash) <> '' AND BINARY VALUES(desired_hash) <> BINARY applied_hash)), 'pending', sync_status),
				dirty_flags = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)))), dirty_flags | VALUES(dirty_flags), dirty_flags),
				last_received_at = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)))), VALUES(last_received_at), last_received_at),
				updated_at = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)))), VALUES(updated_at), updated_at),
				desired_revision = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)))), GREATEST(desired_revision, VALUES(desired_revision)), desired_revision)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, $product_guid, $revision, $hash, $dirty_flags, $now, $now, $now ) );
		if ( false === $result ) { return false; }
		$after = $wpdb->get_row( $wpdb->prepare(
			"SELECT desired_revision, desired_hash FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1",
			$connection_id, $product_guid
		), ARRAY_A );
		return is_array( $after )
			&& absint( $after['desired_revision'] ?? -1 ) === $revision
			&& ( '' === $hash || hash_equals( $hash, (string) ( $after['desired_hash'] ?? '' ) ) );
	}

	/**
	 * Mark a revision/hash successfully applied and clear retry state.
	 *
	 * @param string $product_guid Product identity.
	 * @param int    $revision Applied revision.
	 * @param string $hash Applied hash.
	 * @return bool
	 */
	public function mark_applied( $product_guid, $revision, $hash = '' ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$revision = max( 0, (int) $revision );
		$hash = self::normalize_hash( $hash );
		$now = current_time( 'mysql', true );
		$table = self::table_name();
		/* Only the exact current generation can be acknowledged. A late/stale ack
		 * cannot clear a newer pending revision or another worker's claim. An
		 * equal revision with a different nonempty hash is also not accepted. */
		$sql = "UPDATE {$table} SET
			applied_revision = %d,
			applied_hash = IF(%s <> '', %s, applied_hash),
			sync_status = 'applied', dirty_flags = 0,
			attempt_count = 0, next_attempt_at = NULL,
			claimed_at = NULL, claim_token = '',
			last_applied_at = %s, last_error_code = '', last_error = NULL,
			updated_at = %s
			WHERE connection_id = %d AND product_guid = %s
			AND desired_revision = %d AND applied_revision <= %d
			AND (claim_token IS NULL OR claim_token = '')
			AND (%s = '' OR desired_hash = '' OR BINARY desired_hash = BINARY %s)
			AND (applied_revision < %d OR applied_hash = '' OR %s = '' OR BINARY applied_hash = BINARY %s)";
		$result = $wpdb->query( $wpdb->prepare(
			$sql, $revision, $hash, $hash, $now, $now,
			$connection_id, $product_guid, $revision, $revision,
			$hash, $hash, $revision, $hash, $hash
		) );
		if ( false === $result ) { return false; }
		$after = $wpdb->get_row( $wpdb->prepare(
			"SELECT desired_revision, applied_revision, applied_hash, sync_status, claim_token FROM {$table} WHERE connection_id = %d AND product_guid = %s LIMIT 1",
			$connection_id, $product_guid
		), ARRAY_A );
		return is_array( $after )
			&& absint( $after['desired_revision'] ?? -1 ) === $revision
			&& absint( $after['applied_revision'] ?? -1 ) === $revision
			&& 'applied' === (string) ( $after['sync_status'] ?? '' )
			&& '' === (string) ( $after['claim_token'] ?? '' )
			&& ( '' === $hash || hash_equals( $hash, (string) ( $after['applied_hash'] ?? '' ) ) );
	}

	/**
	 * Mirror the already-committed legacy product state into custom storage.
	 *
	 * Legacy remains authoritative for state construction; 10.33.78 may read this exact mirror first only under a valid promotion lease; exact Legacy watermarks are mirrored losslessly alongside aggregate compatibility fields.
	 * This method is deliberately one-way and never mutates WordPress/Woo state.
	 *
	 * @return bool
	 */
	public function mirror_legacy_state( $product_guid, $woo_product_id, $portal_product_id, $desired_revision, $applied_revision, $hash, $sync_incomplete, $product_seen_revision = null, $product_applied_revision = null, $variant_seen_revision = null, $variant_applied_revision = null, $meta_presence = array(), $sync_incomplete_meta = null ) {
		global $wpdb;

		$product_guid = sanitize_text_field( (string) $product_guid );
		$connection_id = $this->connection_id();
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}


		$woo_product_id    = absint( $woo_product_id );
		$portal_product_id = absint( $portal_product_id );
		if ( $connection_id <= 0 || $this->woo_owner_conflicts( $woo_product_id, $connection_id ) ) { return false; }
		$desired_revision  = max( 0, (int) $desired_revision );
		$applied_revision  = max( 0, (int) $applied_revision );
		$desired_revision  = max( $desired_revision, $applied_revision );
		$hash              = self::normalize_hash( $hash );
		$sync_incomplete   = (bool) $sync_incomplete;
		$sync_incomplete_meta = null === $sync_incomplete_meta ? $sync_incomplete : (bool) $sync_incomplete_meta;
		$product_seen_revision    = null === $product_seen_revision ? $desired_revision : max( 0, (int) $product_seen_revision );
		$product_applied_revision = null === $product_applied_revision ? $applied_revision : max( 0, (int) $product_applied_revision );
		$variant_seen_revision    = null === $variant_seen_revision ? 0 : max( 0, (int) $variant_seen_revision );
		$variant_applied_revision = null === $variant_applied_revision ? 0 : max( 0, (int) $variant_applied_revision );
		$meta_presence = is_array( $meta_presence ) ? $meta_presence : array();
		$presence = array(
			'product_seen_revision'    => array_key_exists( 'product_seen_revision', $meta_presence ) ? ! empty( $meta_presence['product_seen_revision'] ) : true,
			'product_applied_revision' => array_key_exists( 'product_applied_revision', $meta_presence ) ? ! empty( $meta_presence['product_applied_revision'] ) : true,
			'variant_seen_revision'    => array_key_exists( 'variant_seen_revision', $meta_presence ) ? ! empty( $meta_presence['variant_seen_revision'] ) : true,
			'variant_applied_revision' => array_key_exists( 'variant_applied_revision', $meta_presence ) ? ! empty( $meta_presence['variant_applied_revision'] ) : true,
			'product_source_hash'      => array_key_exists( 'product_source_hash', $meta_presence ) ? ! empty( $meta_presence['product_source_hash'] ) : true,
			'sync_incomplete_meta'     => array_key_exists( 'sync_incomplete_meta', $meta_presence ) ? ! empty( $meta_presence['sync_incomplete_meta'] ) : true,
		);
		$status            = ( $sync_incomplete || $desired_revision > $applied_revision ) ? 'pending' : 'applied';
		$desired_hash      = $hash;
		$applied_hash      = $sync_incomplete ? '' : $hash;
		$dirty_flags       = 'pending' === $status ? 1 : 0;
		$now               = current_time( 'mysql', true );
		$last_applied_at   = 'applied' === $status ? $now : null;
		$table             = self::table_name();

		$sql = "INSERT INTO {$table}
			(connection_id, product_guid, woo_product_id, portal_product_id, product_seen_revision, product_seen_revision_present, product_applied_revision, product_applied_revision_present, variant_seen_revision, variant_seen_revision_present, variant_applied_revision, variant_applied_revision_present, product_source_hash, product_source_hash_present, sync_incomplete_meta, sync_incomplete_meta_present, sync_incomplete, desired_revision, applied_revision, desired_hash, applied_hash, sync_status, dirty_flags, last_received_at, last_applied_at, created_at, updated_at)
			VALUES (%d, %s, NULLIF(%d,0), NULLIF(%d,0), %d, %d, %d, %d, %d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %s, %s, %s, %d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				woo_product_id = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(woo_product_id) IS NOT NULL, VALUES(woo_product_id), woo_product_id),
				portal_product_id = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(portal_product_id) IS NOT NULL, VALUES(portal_product_id), portal_product_id),
				product_source_hash = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(product_source_hash), product_source_hash),
				product_source_hash_present = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(product_source_hash_present), product_source_hash_present),
				sync_incomplete_meta = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(sync_incomplete_meta), sync_incomplete_meta),
				sync_incomplete_meta_present = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(sync_incomplete_meta_present), sync_incomplete_meta_present),
				sync_incomplete = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(sync_incomplete), sync_incomplete),
				product_seen_revision_present = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(product_seen_revision_present), product_seen_revision_present),
				product_applied_revision_present = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(product_applied_revision_present), product_applied_revision_present),
				variant_seen_revision_present = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(variant_seen_revision_present), variant_seen_revision_present),
				variant_applied_revision_present = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(variant_applied_revision_present), variant_applied_revision_present),
				product_seen_revision = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(product_seen_revision), product_seen_revision),
				product_applied_revision = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(product_applied_revision), product_applied_revision),
				variant_seen_revision = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(variant_seen_revision), variant_seen_revision),
				variant_applied_revision = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(variant_applied_revision), variant_applied_revision),
				desired_revision = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, GREATEST(desired_revision, VALUES(desired_revision)), desired_revision),
				applied_revision = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, GREATEST(applied_revision, VALUES(applied_revision)), applied_revision),
				desired_hash = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(desired_hash), desired_hash),
				applied_hash = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), VALUES(applied_hash), applied_hash),
				sync_status = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), IF((VALUES(sync_status) = 'pending' AND VALUES(desired_revision) >= desired_revision) OR desired_revision > applied_revision, 'pending', 'applied'), sync_status),
				dirty_flags = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(product_seen_revision) >= product_seen_revision AND VALUES(product_applied_revision) >= product_applied_revision AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(product_seen_revision) > product_seen_revision OR VALUES(product_applied_revision) > product_applied_revision OR VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR product_source_hash = '' OR VALUES(product_source_hash) = product_source_hash), IF((VALUES(sync_status) = 'pending' AND VALUES(desired_revision) >= desired_revision) OR desired_revision > applied_revision, dirty_flags | 1, 0), dirty_flags),
				last_received_at = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid), VALUES(last_received_at), last_received_at),
				last_applied_at = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid) AND VALUES(last_applied_at) IS NOT NULL, VALUES(last_applied_at), last_applied_at),
				updated_at = IF(connection_id = VALUES(connection_id) AND product_guid = VALUES(product_guid), VALUES(updated_at), updated_at)";

		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$connection_id,
				$product_guid,
				$woo_product_id,
				$portal_product_id,
				$product_seen_revision,
				$presence['product_seen_revision'] ? 1 : 0,
				$product_applied_revision,
				$presence['product_applied_revision'] ? 1 : 0,
				$variant_seen_revision,
				$presence['variant_seen_revision'] ? 1 : 0,
				$variant_applied_revision,
				$presence['variant_applied_revision'] ? 1 : 0,
				$hash,
				$presence['product_source_hash'] ? 1 : 0,
				$sync_incomplete_meta ? 1 : 0,
				$presence['sync_incomplete_meta'] ? 1 : 0,
				$sync_incomplete ? 1 : 0,
				$desired_revision,
				$applied_revision,
				$desired_hash,
				$applied_hash,
				$status,
				$dirty_flags,
				$now,
				$last_applied_at,
				$now,
				$now
			)
		);
		if ( false === $result ) { return false; }

		$persisted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND product_guid = %s LIMIT 1", $connection_id, $product_guid ), ARRAY_A );
		if ( ! is_array( $persisted ) || ( $woo_product_id > 0 && absint( isset( $persisted['woo_product_id'] ) ? $persisted['woo_product_id'] : 0 ) !== $woo_product_id ) ) { return false; }
		$exact = array(
			'product_seen_revision' => $product_seen_revision,
			'product_applied_revision' => $product_applied_revision,
			'variant_seen_revision' => $variant_seen_revision,
			'variant_applied_revision' => $variant_applied_revision,
		);
		$persisted_dominates = false;
		foreach ( $exact as $field => $expected ) {
			$actual = absint( isset( $persisted[ $field ] ) ? $persisted[ $field ] : -1 );
			if ( $actual < $expected ) { return false; }
			if ( $actual > $expected ) { $persisted_dominates = true; }
		}

		// A fully dominated snapshot is a benign stale/no-op mirror, not a repository failure.
		// Do not compare its hash/presence bits against the newer persisted state.
		if ( $persisted_dominates ) { return true; }

		// Equal watermarks must still match exactly. Same-revision/different-state is drift,
		// not a stale snapshot, and must remain fail-closed for reconciliation/gating.
		foreach ( $exact as $field => $expected ) {
			if ( absint( isset( $persisted[ $field . '_present' ] ) ? $persisted[ $field . '_present' ] : -1 ) !== ( $presence[ $field ] ? 1 : 0 ) ) { return false; }
		}
		if ( (string) ( isset( $persisted['product_source_hash'] ) ? $persisted['product_source_hash'] : '' ) !== $hash ) { return false; }
		if ( absint( isset( $persisted['product_source_hash_present'] ) ? $persisted['product_source_hash_present'] : -1 ) !== ( $presence['product_source_hash'] ? 1 : 0 ) ) { return false; }
		if ( absint( isset( $persisted['sync_incomplete_meta'] ) ? $persisted['sync_incomplete_meta'] : -1 ) !== ( $sync_incomplete_meta ? 1 : 0 ) ) { return false; }
		if ( absint( isset( $persisted['sync_incomplete_meta_present'] ) ? $persisted['sync_incomplete_meta_present'] : -1 ) !== ( $presence['sync_incomplete_meta'] ? 1 : 0 ) ) { return false; }
		return true;
	}

	/** Delete Custom state for one definitely retired Woo object ID. @return bool */
	public function delete_by_woo_id( $woo_id ) {
		global $wpdb;
		$woo_id = absint( $woo_id );
		if ( $woo_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$connection_id = $this->connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$deleted = $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'woo_product_id' => $woo_id ), array( '%d', '%d' ) );
		if ( false !== $deleted && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $woo_id ); }
		return false !== $deleted;
	}

	/** Delete only the exact row observed by the reconciler. @return bool */
	public function delete_exact( $id, $product_guid, $woo_id ) {
		global $wpdb;
		$id = absint( $id );
		$product_guid = sanitize_text_field( (string) $product_guid );
		$woo_id = absint( $woo_id );
		if ( $id <= 0 || $woo_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$connection_id = $this->connection_id();
		if ( $connection_id <= 0 ) { return false; }
		$deleted = $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'id' => $id, 'product_guid' => $product_guid, 'woo_product_id' => $woo_id ), array( '%d', '%d', '%s', '%d' ) );
		if ( false !== $deleted && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $woo_id ); }
		return false !== $deleted;
	}

	/** @return array|null */
	public function get_by_guid( $product_guid ) {
		global $wpdb;
		$product_guid = sanitize_text_field( (string) $product_guid );
		$connection_id = $this->connection_id();
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return null;
		}
		$table = self::table_name();
		return self::cached_row( 'g|' . $connection_id . '|' . $product_guid, $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND product_guid = %s LIMIT 1", $connection_id, $product_guid ) );
	}

	/** Read one Product row by Woo ID for Custom-only state reads. @return array|null */
	public function get_by_woo_product_id( $woo_product_id ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_product_id = absint( $woo_product_id );
		if ( $connection_id <= 0 || $woo_product_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return null; }
		$table = Mobo_Core_Storage_Schema::table_name( 'products' );
		return self::cached_row( 'w|' . $connection_id . '|' . $woo_product_id, $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND woo_product_id=%d LIMIT 1", $connection_id, $woo_product_id ), $woo_product_id );
	}

	/** Read the global Woo Product owner without depending on current Connection context. @return array|null */
	public function get_owner_by_woo_product_id( $woo_product_id ) {
		global $wpdb;
		$woo_product_id = absint( $woo_product_id );
		if ( $woo_product_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return null; }
		$table = self::table_name();
		return self::cached_row( 'o|' . $woo_product_id, $wpdb->prepare( "SELECT * FROM {$table} WHERE woo_product_id=%d LIMIT 1", $woo_product_id ), $woo_product_id );
	}

	/** @return string */
	private static function normalize_hash( $hash ) {
		return class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $hash ) : '';
	}
}
