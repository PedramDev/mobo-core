<?php
/** Custom-storage repository for variation operational state. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
final class Mobo_Core_Variant_State_Repository {

	/** @return int */
	private function connection_id() {
		return class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
	}

	/** Woo Variation IDs are installation-global ownership anchors. @return bool */
	private function woo_owner_conflicts( $woo_variation_id, $connection_id ) {
		global $wpdb;
		$woo_variation_id = absint( $woo_variation_id );
		$connection_id = absint( $connection_id );
		if ( $connection_id <= 0 || $woo_variation_id <= 0 || $connection_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = self::table_name();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM {$table} WHERE woo_variation_id=%d LIMIT 1", $woo_variation_id ) );
		return null !== $owner && absint( $owner ) !== $connection_id;
	}

	/** @return string */
	public static function table_name() {
		return Mobo_Core_Storage_Schema::table_name( 'variants' );
	}

	/**
	 * Upsert local/remote variation identity only.
	 *
	 * @return bool
	 */
	public function upsert_identity( $variant_guid, $product_guid, $woo_variation_id = 0, $woo_product_id = 0, $portal_variant_id = 0 ) {
		global $wpdb;
		$connection_id = $this->connection_id();

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		$now               = current_time( 'mysql', true );
		$table             = self::table_name();
		$woo_variation_id  = absint( $woo_variation_id );
		$woo_product_id    = absint( $woo_product_id );
		$portal_variant_id = absint( $portal_variant_id );
		if ( $this->woo_owner_conflicts( $woo_variation_id, $connection_id ) ) { return false; }
		$sql               = "INSERT INTO {$table}
			(connection_id, variant_guid, product_guid, woo_variation_id, woo_product_id, portal_variant_id, created_at, updated_at)
			VALUES (%d, %s, %s, NULLIF(%d,0), NULLIF(%d,0), NULLIF(%d,0), %s, %s)
			ON DUPLICATE KEY UPDATE
				product_guid = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid), VALUES(product_guid), product_guid),
				woo_variation_id = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(woo_variation_id) IS NOT NULL, VALUES(woo_variation_id), woo_variation_id),
				woo_product_id = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(woo_product_id) IS NOT NULL, VALUES(woo_product_id), woo_product_id),
				portal_variant_id = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(portal_variant_id) IS NOT NULL, VALUES(portal_variant_id), portal_variant_id),
				updated_at = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid), VALUES(updated_at), updated_at)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, $variant_guid, $product_guid, $woo_variation_id, $woo_product_id, $portal_variant_id, $now, $now ) );
		if ( false === $result ) {
			return false;
		}

		$persisted = $wpdb->get_row( $wpdb->prepare( "SELECT product_guid, woo_variation_id, woo_product_id, portal_variant_id FROM {$table} WHERE connection_id=%d AND variant_guid = %s LIMIT 1", $connection_id, $variant_guid ), ARRAY_A );
		return is_array( $persisted )
			&& (string) ( isset( $persisted['product_guid'] ) ? $persisted['product_guid'] : '' ) === $product_guid
			&& ( $woo_variation_id <= 0 || absint( isset( $persisted['woo_variation_id'] ) ? $persisted['woo_variation_id'] : 0 ) === $woo_variation_id )
			&& ( $woo_product_id <= 0 || absint( isset( $persisted['woo_product_id'] ) ? $persisted['woo_product_id'] : 0 ) === $woo_product_id )
			&& ( $portal_variant_id <= 0 || absint( isset( $persisted['portal_variant_id'] ) ? $persisted['portal_variant_id'] : 0 ) === $portal_variant_id );
	}

	/** @return bool */
	public function mark_desired( $variant_guid, $product_guid, $revision, $hash = '', $dirty_flags = 0 ) {
		global $wpdb;
		$connection_id = $this->connection_id();

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return false;
		}

		$revision    = max( 0, (int) $revision );
		$hash        = self::normalize_hash( $hash );
		$dirty_flags = max( 0, (int) $dirty_flags );
		$now         = current_time( 'mysql', true );
		$table       = self::table_name();
		$sql         = "INSERT INTO {$table}
			(connection_id, variant_guid, product_guid, desired_revision, desired_hash, sync_status, dirty_flags, last_received_at, created_at, updated_at)
			VALUES (%d, %s, %s, %d, %s, 'pending', %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				product_guid = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))), VALUES(product_guid), product_guid),
				desired_hash = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))) AND VALUES(desired_hash) <> '', VALUES(desired_hash), desired_hash),
				sync_status = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))) AND (VALUES(desired_revision) > applied_revision OR (VALUES(desired_hash) <> '' AND BINARY VALUES(desired_hash) <> BINARY applied_hash)), 'pending', sync_status),
				dirty_flags = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))), dirty_flags | VALUES(dirty_flags), dirty_flags),
				last_received_at = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))), VALUES(last_received_at), last_received_at),
				updated_at = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))), VALUES(updated_at), updated_at),
				desired_revision = IF((VALUES(desired_revision) > desired_revision OR (VALUES(desired_revision) = desired_revision AND (desired_hash = '' OR VALUES(desired_hash) = '' OR BINARY desired_hash = BINARY VALUES(desired_hash)) AND (product_guid = '' OR BINARY product_guid = BINARY VALUES(product_guid)))), GREATEST(desired_revision, VALUES(desired_revision)), desired_revision)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $connection_id, $variant_guid, $product_guid, $revision, $hash, $dirty_flags, $now, $now, $now ) );
		if ( false === $result ) { return false; }
		$after = $wpdb->get_row( $wpdb->prepare(
			"SELECT desired_revision, desired_hash, product_guid FROM {$table} WHERE connection_id = %d AND variant_guid = %s LIMIT 1",
			$connection_id, $variant_guid
		), ARRAY_A );
		return is_array( $after )
			&& absint( $after['desired_revision'] ?? -1 ) === $revision
			&& ( '' === $hash || hash_equals( $hash, (string) ( $after['desired_hash'] ?? '' ) ) )
			&& $product_guid === (string) ( $after['product_guid'] ?? '' );
	}

	/** @return bool */
	public function mark_applied( $variant_guid, $revision, $hash = '' ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
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
			WHERE connection_id = %d AND variant_guid = %s
			AND desired_revision = %d AND applied_revision <= %d
			AND (claim_token IS NULL OR claim_token = '')
			AND (%s = '' OR desired_hash = '' OR BINARY desired_hash = BINARY %s)
			AND (applied_revision < %d OR applied_hash = '' OR %s = '' OR BINARY applied_hash = BINARY %s)";
		$result = $wpdb->query( $wpdb->prepare(
			$sql, $revision, $hash, $hash, $now, $now,
			$connection_id, $variant_guid, $revision, $revision,
			$hash, $hash, $revision, $hash, $hash
		) );
		if ( false === $result ) { return false; }
		$after = $wpdb->get_row( $wpdb->prepare(
			"SELECT desired_revision, applied_revision, applied_hash, sync_status, claim_token FROM {$table} WHERE connection_id = %d AND variant_guid = %s LIMIT 1",
			$connection_id, $variant_guid
		), ARRAY_A );
		return is_array( $after )
			&& absint( $after['desired_revision'] ?? -1 ) === $revision
			&& absint( $after['applied_revision'] ?? -1 ) === $revision
			&& 'applied' === (string) ( $after['sync_status'] ?? '' )
			&& '' === (string) ( $after['claim_token'] ?? '' )
			&& ( '' === $hash || hash_equals( $hash, (string) ( $after['applied_hash'] ?? '' ) ) );
	}

	/**
	 * Mirror the already-committed legacy variation state into custom storage.
	 *
	 * @return bool
	 */
	public function mirror_legacy_state( $variant_guid, $product_guid, $woo_variation_id, $woo_product_id, $portal_variant_id, $desired_revision, $applied_revision, $hash, $sync_incomplete, $variant_seen_revision = null, $variant_applied_revision = null, $meta_presence = array(), $sync_incomplete_meta = null ) {
		global $wpdb;
		$connection_id = $this->connection_id();

		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }

		$woo_variation_id  = absint( $woo_variation_id );
		$woo_product_id    = absint( $woo_product_id );
		$portal_variant_id = absint( $portal_variant_id );
		if ( $this->woo_owner_conflicts( $woo_variation_id, $connection_id ) ) { return false; }
		$desired_revision  = max( 0, (int) $desired_revision );
		$applied_revision  = max( 0, (int) $applied_revision );
		$desired_revision  = max( $desired_revision, $applied_revision );
		$hash              = self::normalize_hash( $hash );
		$sync_incomplete   = (bool) $sync_incomplete;
		$sync_incomplete_meta = null === $sync_incomplete_meta ? $sync_incomplete : (bool) $sync_incomplete_meta;
		$variant_seen_revision    = null === $variant_seen_revision ? $desired_revision : max( 0, (int) $variant_seen_revision );
		$variant_applied_revision = null === $variant_applied_revision ? $applied_revision : max( 0, (int) $variant_applied_revision );
		$meta_presence = is_array( $meta_presence ) ? $meta_presence : array();
		$presence = array(
			'variant_seen_revision'    => array_key_exists( 'variant_seen_revision', $meta_presence ) ? ! empty( $meta_presence['variant_seen_revision'] ) : true,
			'variant_applied_revision' => array_key_exists( 'variant_applied_revision', $meta_presence ) ? ! empty( $meta_presence['variant_applied_revision'] ) : true,
			'variant_source_hash'      => array_key_exists( 'variant_source_hash', $meta_presence ) ? ! empty( $meta_presence['variant_source_hash'] ) : true,
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
			(connection_id, variant_guid, product_guid, woo_variation_id, woo_product_id, portal_variant_id, variant_seen_revision, variant_seen_revision_present, variant_applied_revision, variant_applied_revision_present, variant_source_hash, variant_source_hash_present, sync_incomplete_meta, sync_incomplete_meta_present, sync_incomplete, desired_revision, applied_revision, desired_hash, applied_hash, sync_status, dirty_flags, last_received_at, last_applied_at, created_at, updated_at)
			VALUES (%d, %s, %s, NULLIF(%d,0), NULLIF(%d,0), NULLIF(%d,0), %d, %d, %d, %d, %s, %d, %d, %d, %d, %d, %d, %s, %s, %s, %d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				product_guid = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(product_guid), product_guid),
				woo_variation_id = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND VALUES(woo_variation_id) IS NOT NULL, VALUES(woo_variation_id), woo_variation_id),
				woo_product_id = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND VALUES(woo_product_id) IS NOT NULL, VALUES(woo_product_id), woo_product_id),
				portal_variant_id = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND VALUES(portal_variant_id) IS NOT NULL, VALUES(portal_variant_id), portal_variant_id),
				variant_source_hash = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(variant_source_hash), variant_source_hash),
				variant_source_hash_present = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(variant_source_hash_present), variant_source_hash_present),
				sync_incomplete_meta = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(sync_incomplete_meta), sync_incomplete_meta),
				sync_incomplete_meta_present = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(sync_incomplete_meta_present), sync_incomplete_meta_present),
				sync_incomplete = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(sync_incomplete), sync_incomplete),
				variant_seen_revision_present = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(variant_seen_revision_present), variant_seen_revision_present),
				variant_applied_revision_present = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(variant_applied_revision_present), variant_applied_revision_present),
				variant_seen_revision = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(variant_seen_revision), variant_seen_revision),
				variant_applied_revision = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, VALUES(variant_applied_revision), variant_applied_revision),
				desired_revision = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, GREATEST(desired_revision, VALUES(desired_revision)), desired_revision),
				applied_revision = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision, GREATEST(applied_revision, VALUES(applied_revision)), applied_revision),
				desired_hash = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(desired_hash), desired_hash),
				applied_hash = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), VALUES(applied_hash), applied_hash),
				sync_status = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), IF((VALUES(sync_status) = 'pending' AND VALUES(desired_revision) >= desired_revision) OR desired_revision > applied_revision, 'pending', 'applied'), sync_status),
				dirty_flags = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(variant_seen_revision) >= variant_seen_revision AND VALUES(variant_applied_revision) >= variant_applied_revision AND (VALUES(variant_seen_revision) > variant_seen_revision OR VALUES(variant_applied_revision) > variant_applied_revision OR variant_source_hash = '' OR VALUES(variant_source_hash) = variant_source_hash), IF((VALUES(sync_status) = 'pending' AND VALUES(desired_revision) >= desired_revision) OR desired_revision > applied_revision, dirty_flags | 1, 0), dirty_flags),
				last_received_at = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid), VALUES(last_received_at), last_received_at),
				last_applied_at = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid) AND VALUES(last_applied_at) IS NOT NULL, VALUES(last_applied_at), last_applied_at),
				updated_at = IF(connection_id = VALUES(connection_id) AND variant_guid = VALUES(variant_guid), VALUES(updated_at), updated_at)";

		$result = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$connection_id, $variant_guid, $product_guid, $woo_variation_id, $woo_product_id, $portal_variant_id,
				$variant_seen_revision, $presence['variant_seen_revision'] ? 1 : 0,
				$variant_applied_revision, $presence['variant_applied_revision'] ? 1 : 0,
				$hash, $presence['variant_source_hash'] ? 1 : 0,
				$sync_incomplete_meta ? 1 : 0, $presence['sync_incomplete_meta'] ? 1 : 0,
				$sync_incomplete ? 1 : 0, $desired_revision, $applied_revision, $desired_hash, $applied_hash,
				$status, $dirty_flags, $now, $last_applied_at, $now, $now
			)
		);
		if ( false === $result ) { return false; }

		$persisted = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND variant_guid = %s LIMIT 1", $connection_id, $variant_guid ), ARRAY_A );
		if ( ! is_array( $persisted )
			|| (string) ( isset( $persisted['product_guid'] ) ? $persisted['product_guid'] : '' ) !== $product_guid
			|| ( $woo_variation_id > 0 && absint( isset( $persisted['woo_variation_id'] ) ? $persisted['woo_variation_id'] : 0 ) !== $woo_variation_id )
			|| ( $woo_product_id > 0 && absint( isset( $persisted['woo_product_id'] ) ? $persisted['woo_product_id'] : 0 ) !== $woo_product_id ) ) { return false; }
		$exact = array( 'variant_seen_revision'=>$variant_seen_revision, 'variant_applied_revision'=>$variant_applied_revision );
		$persisted_dominates = false;
		foreach ( $exact as $field=>$expected ) {
			$actual = absint( isset( $persisted[ $field ] ) ? $persisted[ $field ] : -1 );
			if ( $actual < $expected ) { return false; }
			if ( $actual > $expected ) { $persisted_dominates = true; }
		}

		// A fully dominated snapshot is a benign stale/no-op mirror, not a repository failure.
		if ( $persisted_dominates ) { return true; }

		// Equal watermarks must still match hash/presence exactly; otherwise fail closed.
		foreach ( $exact as $field=>$expected ) {
			if ( absint( isset( $persisted[ $field . '_present' ] ) ? $persisted[ $field . '_present' ] : -1 ) !== ( $presence[ $field ] ? 1 : 0 ) ) { return false; }
		}
		if ( (string) ( isset( $persisted['variant_source_hash'] ) ? $persisted['variant_source_hash'] : '' ) !== $hash ) { return false; }
		if ( absint( isset( $persisted['variant_source_hash_present'] ) ? $persisted['variant_source_hash_present'] : -1 ) !== ( $presence['variant_source_hash'] ? 1 : 0 ) ) { return false; }
		if ( absint( isset( $persisted['sync_incomplete_meta'] ) ? $persisted['sync_incomplete_meta'] : -1 ) !== ( $sync_incomplete_meta ? 1 : 0 ) ) { return false; }
		if ( absint( isset( $persisted['sync_incomplete_meta_present'] ) ? $persisted['sync_incomplete_meta_present'] : -1 ) !== ( $presence['sync_incomplete_meta'] ? 1 : 0 ) ) { return false; }
		return true;
	}


	/**
	 * Bind or safely rebind one Woo Variation to a remote Variant GUID while
	 * preserving the same parent Product identity. Rebinding is rejected if the
	 * target GUID already belongs to another row.
	 * @return bool
	 */
	public function terminal_bind_variant( $woo_variation_id, $woo_product_id, $variant_guid, $product_guid ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_variation_id = absint( $woo_variation_id );
		$woo_product_id = absint( $woo_product_id );
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$product_guid = sanitize_text_field( (string) $product_guid );
		if ( $connection_id <= 0 || $woo_variation_id <= 0 || $woo_product_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Remote_Identity_Policy::is_valid( $product_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		if ( $this->woo_owner_conflicts( $woo_variation_id, $connection_id ) ) { return false; }
		$table = self::table_name();
		$row = $this->get_by_woo_variation_id( $woo_variation_id );
		if ( is_array( $row ) ) {
			if ( absint( isset( $row['woo_product_id'] ) ? $row['woo_product_id'] : 0 ) !== $woo_product_id || sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) ) !== $product_guid ) { return false; }
			$current = sanitize_text_field( (string) ( isset( $row['variant_guid'] ) ? $row['variant_guid'] : '' ) );
			if ( $current === $variant_guid ) { return true; }
			$owner = $wpdb->get_var( $wpdb->prepare( "SELECT woo_variation_id FROM {$table} WHERE connection_id=%d AND variant_guid=%s AND woo_variation_id<>%d LIMIT 1", $connection_id, $variant_guid, $woo_variation_id ) );
			if ( null !== $owner ) { return false; }
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET variant_guid=%s,updated_at=%s WHERE connection_id=%d AND woo_variation_id=%d AND product_guid=%s", $variant_guid, current_time( 'mysql', true ), $connection_id, $woo_variation_id, $product_guid ) );
			if ( false === $updated ) { return false; }
			$after = $this->get_by_woo_variation_id( $woo_variation_id );
			return is_array( $after ) && sanitize_text_field( (string) $after['variant_guid'] ) === $variant_guid;
		}
		return $this->upsert_identity( $variant_guid, $product_guid, $woo_variation_id, $woo_product_id, 0 );
	}

	/** Set canonical Portal Variant ID for an already-bound Variation. @return bool */
	public function terminal_set_portal_variant_id( $woo_variation_id, $portal_variant_id ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_variation_id = absint( $woo_variation_id ); $portal_variant_id = absint( $portal_variant_id );
		if ( $connection_id <= 0 || $woo_variation_id <= 0 || $portal_variant_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$table = self::table_name();
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET portal_variant_id=%d,updated_at=%s WHERE connection_id=%d AND woo_variation_id=%d", $portal_variant_id, current_time( 'mysql', true ), $connection_id, $woo_variation_id ) );
		if ( false === $result ) { return false; }
		$row = $this->get_by_woo_variation_id( $woo_variation_id );
		return is_array( $row ) && absint( isset( $row['portal_variant_id'] ) ? $row['portal_variant_id'] : 0 ) === $portal_variant_id;
	}

	/** Delete Custom state for one definitely retired Woo object ID. @return bool */
	public function delete_by_woo_id( $woo_id ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_id = absint( $woo_id );
		if ( $connection_id <= 0 || $woo_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$deleted = $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'woo_variation_id' => $woo_id ), array( '%d', '%d' ) );
		if ( false !== $deleted && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $woo_id ); }
		return false !== $deleted;
	}

	/** Delete only the exact row observed by the reconciler. @return bool */
	public function delete_exact( $id, $variant_guid, $woo_id ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$id = absint( $id );
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		$woo_id = absint( $woo_id );
		if ( $connection_id <= 0 || $id <= 0 || $woo_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return false; }
		$deleted = $wpdb->delete( self::table_name(), array( 'connection_id' => $connection_id, 'id' => $id, 'variant_guid' => $variant_guid, 'woo_variation_id' => $woo_id ), array( '%d', '%d', '%s', '%d' ) );
		if ( false !== $deleted && class_exists( 'Mobo_Core_Storage_Primary_State_Read', false ) ) { Mobo_Core_Storage_Primary_State_Read::invalidate_object( $woo_id ); }
		return false !== $deleted;
	}

	/** @return array|null */
	public function get_by_guid( $variant_guid ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( $connection_id <= 0 || ! Mobo_Core_Remote_Identity_Policy::is_valid( $variant_guid ) || ! Mobo_Core_Storage_Schema::is_ready() ) {
			return null;
		}
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND variant_guid = %s LIMIT 1", $connection_id, $variant_guid ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}


	/** Read one Variation row by Woo ID for Custom-only state reads. @return array|null */
	public function get_by_woo_variation_id( $woo_variation_id ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$woo_variation_id = absint( $woo_variation_id );
		if ( $connection_id <= 0 || $woo_variation_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return null; }
		$table = Mobo_Core_Storage_Schema::table_name( 'variants' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id=%d AND woo_variation_id=%d LIMIT 1", $connection_id, $woo_variation_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Set-based Custom state lookup keyed by Variant GUID.
	 *
	 * @param array $variant_guids Remote GUIDs.
	 * @return array<string,array>
	 */
	public function get_by_guids( $variant_guids ) {
		global $wpdb;
		$connection_id = $this->connection_id();
		$result = array();
		if ( $connection_id <= 0 || ! is_array( $variant_guids ) || empty( $variant_guids ) || ! Mobo_Core_Storage_Schema::is_ready() ) { return $result; }
		$clean = array();
		foreach ( $variant_guids as $guid ) {
			$guid = sanitize_text_field( (string) $guid );
			if ( Mobo_Core_Remote_Identity_Policy::is_valid( $guid ) ) { $clean[ $guid ] = true; }
		}
		$table = self::table_name();
		foreach ( array_chunk( array_keys( $clean ), 400 ) as $chunk ) {
			if ( empty( $chunk ) ) { continue; }
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql = "SELECT * FROM {$table} WHERE connection_id=%d AND variant_guid IN ({$placeholders})";
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql, $connection_id ), $chunk ) );
			$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- fixed internal table with generated placeholders.
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$guid = sanitize_text_field( (string) ( isset( $row['variant_guid'] ) ? $row['variant_guid'] : '' ) );
				if ( '' !== $guid ) { $result[ $guid ] = $row; }
			}
		}
		return $result;
	}

	/** Read the global Woo Variation owner without depending on current Connection context. @return array|null */
	public function get_owner_by_woo_variation_id( $woo_variation_id ) {
		global $wpdb;
		$woo_variation_id = absint( $woo_variation_id );
		if ( $woo_variation_id <= 0 || ! Mobo_Core_Storage_Schema::is_ready() ) { return null; }
		$table = self::table_name();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE woo_variation_id=%d LIMIT 1", $woo_variation_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return string */
	private static function normalize_hash( $hash ) {
		return class_exists( 'Mobo_Core_Source_Hash_Policy' ) ? Mobo_Core_Source_Hash_Policy::normalize( $hash ) : '';
	}
}
