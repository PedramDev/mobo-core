<?php
/** Shared fail-closed lifecycle policy for retiring historical Mobo variations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Variation_Lifecycle_Policy {
	/** @return array */
	private static function identity_keys() {
		return class_exists( 'Mobo_Core_Product_Identity_Policy' )
			? Mobo_Core_Product_Identity_Policy::variation_identity_meta_keys()
			: array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid', 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
	}

	/** @return array */
	private static function marker_keys() {
		return array(
			'_mobo_variation_quarantine_reason',
			'_mobo_variation_quarantined_at',
			'_mobo_variation_quarantine_context',
			'_mobo_variation_previous_identity',
			'_mobo_repair_duplicate_canonical_id',
			'_mobo_repair_duplicate_portal_variant_id',
			'_mobo_repair_duplicate_quarantined_at',
			'_mobo_repair_previous_identity',
			'_mobo_repair_post_sync_id',
			'_mobo_repair_post_sync_reason',
		);
	}

	/**
	 * Resolve alias families and reject contradictory local identity before retirement.
	 *
	 * @param int $variation_id Variation ID.
	 * @return array|WP_Error
	 */
	public static function inspect_identity( $variation_id ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || 'product_variation' !== get_post_type( $variation_id ) ) {
			return new WP_Error( 'mobo_core_variation_retire_invalid', 'Variation ID is invalid for quarantine.' );
		}

		$guid_keys = class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::variant_guid_meta_keys() : array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid' );
		$id_keys   = class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::portal_variant_id_meta_keys() : array( 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
		$guids     = array();
		$ids       = array();
		$snapshot  = array();

		foreach ( self::identity_keys() as $key ) {
			$snapshot[ $key ] = get_post_meta( $variation_id, $key, false );
		}
		foreach ( $guid_keys as $key ) {
			$value = trim( sanitize_text_field( (string) get_post_meta( $variation_id, $key, true ) ) );
			if ( '' !== $value ) {
				$guids[ $value ] = true;
			}
		}
		foreach ( $id_keys as $key ) {
			$raw = get_post_meta( $variation_id, $key, true );
			if ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
				if ( ! preg_match( '/^[0-9]+$/', trim( (string) $raw ) ) || absint( $raw ) <= 0 ) {
					return new WP_Error( 'mobo_core_variation_identity_malformed', 'Variation Portal identity alias is malformed; retirement was blocked.' );
				}
				$ids[ absint( $raw ) ] = true;
			}
		}
		if ( count( $guids ) > 1 || count( $ids ) > 1 ) {
			return new WP_Error( 'mobo_core_variation_identity_alias_conflict', 'Variation identity aliases conflict; retirement was blocked.' );
		}

		$owned = ! empty( $guids ) || ! empty( $ids );
		if ( class_exists( 'Mobo_Core_Product_Identity_Policy' ) ) {
			$owned = $owned || Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $variation_id );
		}

		return array(
			'owned'      => $owned,
			'variantGuid'=> empty( $guids ) ? '' : (string) array_key_first( $guids ),
			'portalId'   => empty( $ids ) ? 0 : absint( array_key_first( $ids ) ),
			'snapshot'   => $snapshot,
		);
	}

	/** @param int $post_id @param array $snapshot @return void */
	private static function restore_meta_snapshot( $post_id, $snapshot ) {
		foreach ( is_array( $snapshot ) ? $snapshot : array() as $key => $values ) {
			delete_post_meta( $post_id, $key );
			foreach ( is_array( $values ) ? $values : array() as $value ) {
				add_post_meta( $post_id, $key, maybe_unserialize( $value ), false );
			}
		}
	}

	/** @param int $post_id @param array $keys @return array */
	private static function snapshot_meta_keys( $post_id, $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = get_post_meta( $post_id, $key, false );
		}
		return $out;
	}

	/**
	 * Preflight a quarantine transition without mutating storefront state.
	 *
	 * This deliberately checks the exact Product Map snapshot as part of the
	 * safety gate. A Variable->Simple transition must not clear parent attributes
	 * and only afterwards discover that one child cannot be retired safely.
	 *
	 * @param int                        $variation_id Variation ID.
	 * @param Mobo_Core_Product_Map|null $product_map Product map.
	 * @return array|WP_Error
	 */
	public static function preflight_quarantine( $variation_id, $product_map = null ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || 'product_variation' !== get_post_type( $variation_id ) ) {
			return new WP_Error( 'mobo_core_variation_quarantine_invalid', 'Variation quarantine request is invalid.' );
		}
		$identity = self::inspect_identity( $variation_id );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		if ( empty( $identity['owned'] ) ) {
			return array(
				'owned'       => false,
				'identity'    => $identity,
				'mapSnapshot' => array(),
			);
		}
		if ( defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS <= 0 ) {
			return new WP_Error( 'mobo_core_variation_trash_disabled', 'WordPress Trash retention is disabled; hard deletion is forbidden.' );
		}

		$map_snapshot = array();
		if ( $product_map instanceof Mobo_Core_Product_Map ) {
			$map_snapshot = $product_map->snapshot_variation_rows_by_post_id( $variation_id );
			if ( is_wp_error( $map_snapshot ) ) {
				return $map_snapshot;
			}
		}

		return array(
			'owned'       => true,
			'identity'    => $identity,
			'mapSnapshot' => $map_snapshot,
		);
	}

	/**
	 * Persist one forensic marker and verify the exact scalar read-back.
	 *
	 * @param int    $variation_id Variation ID.
	 * @param string $key Meta key.
	 * @param string $value Scalar value.
	 * @return bool
	 */
	private static function persist_marker_verified( $variation_id, $key, $value ) {
		update_post_meta( $variation_id, $key, $value );
		clean_post_cache( $variation_id );
		return (string) get_post_meta( $variation_id, $key, true ) === (string) $value;
	}

	/**
	 * Quarantine one Mobo-owned live variation. Never hard-delete.
	 *
	 * @param int                        $variation_id Variation ID.
	 * @param string                     $reason Stable reason key.
	 * @param array                      $context Diagnostic context.
	 * @param Mobo_Core_Product_Map|null $product_map Product map.
	 * @return true|WP_Error
	 */
	public static function quarantine( $variation_id, $reason, $context = array(), $product_map = null ) {
		$variation_id = absint( $variation_id );
		$reason       = sanitize_key( (string) $reason );
		if ( $variation_id <= 0 || '' === $reason || 'product_variation' !== get_post_type( $variation_id ) ) {
			return new WP_Error( 'mobo_core_variation_quarantine_invalid', 'Variation quarantine request is invalid.' );
		}
		$already_trashed = 'trash' === get_post_status( $variation_id );
		$preflight       = self::preflight_quarantine( $variation_id, $product_map );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( empty( $preflight['owned'] ) ) {
			return new WP_Error( 'mobo_core_variation_not_mobo_owned', 'Variation has no unambiguous Mobo ownership evidence; quarantine was blocked.' );
		}
		$identity     = $preflight['identity'];
		$map_snapshot = isset( $preflight['mapSnapshot'] ) && is_array( $preflight['mapSnapshot'] ) ? $preflight['mapSnapshot'] : array();
		$marker_snapshot     = self::snapshot_meta_keys( $variation_id, self::marker_keys() );
		$existing_quarantine = '' !== trim( (string) get_post_meta( $variation_id, '_mobo_variation_quarantine_reason', true ) );

		/*
		 * A retry may resume after Trash succeeded but Product Map/identity cleanup
		 * failed. Preserve the original forensic snapshot on such retries instead of
		 * overwriting it with the already-partially-retired identity state.
		 */
		if ( ! ( $already_trashed && $existing_quarantine ) ) {
			$quarantined_at = gmdate( 'c' );
			$context_json    = wp_json_encode( is_array( $context ) ? $context : array() );
			$identity_json   = wp_json_encode( $identity['snapshot'] );
			$markers_ok      = self::persist_marker_verified( $variation_id, '_mobo_variation_quarantine_reason', $reason )
				&& self::persist_marker_verified( $variation_id, '_mobo_variation_quarantined_at', $quarantined_at )
				&& self::persist_marker_verified( $variation_id, '_mobo_variation_quarantine_context', $context_json )
				&& self::persist_marker_verified( $variation_id, '_mobo_variation_previous_identity', $identity_json );
			if ( ! $markers_ok ) {
				self::restore_meta_snapshot( $variation_id, $marker_snapshot );
				return new WP_Error( 'mobo_core_variation_quarantine_marker_write_failed', 'Variation quarantine forensic markers could not be persisted; storefront state was not mutated.' );
			}
		}
		/* Preserve legacy Repair diagnostics while all paths share one lifecycle primitive. */
		if ( ! empty( $context['repairDuplicate'] ) && ! ( $already_trashed && $existing_quarantine ) ) {
			update_post_meta( $variation_id, '_mobo_repair_duplicate_canonical_id', absint( isset( $context['canonicalId'] ) ? $context['canonicalId'] : 0 ) );
			update_post_meta( $variation_id, '_mobo_repair_duplicate_portal_variant_id', absint( isset( $context['portalVariantId'] ) ? $context['portalVariantId'] : 0 ) );
			update_post_meta( $variation_id, '_mobo_repair_duplicate_quarantined_at', gmdate( 'c' ) );
			update_post_meta( $variation_id, '_mobo_repair_previous_identity', wp_json_encode( $identity['snapshot'] ) );
		}
		if ( ! empty( $context['repairPostSyncReason'] ) && ! ( $already_trashed && $existing_quarantine ) ) {
			update_post_meta( $variation_id, '_mobo_repair_post_sync_id', sanitize_text_field( (string) ( isset( $context['syncId'] ) ? $context['syncId'] : '' ) ) );
			update_post_meta( $variation_id, '_mobo_repair_post_sync_reason', sanitize_key( (string) $context['repairPostSyncReason'] ) );
			update_post_meta( $variation_id, '_mobo_repair_duplicate_canonical_id', absint( isset( $context['canonicalId'] ) ? $context['canonicalId'] : 0 ) );
			update_post_meta( $variation_id, '_mobo_repair_duplicate_quarantined_at', gmdate( 'c' ) );
			update_post_meta( $variation_id, '_mobo_repair_previous_identity', wp_json_encode( $identity['snapshot'] ) );
		}

		if ( ! $already_trashed ) {
			$trashed = wp_trash_post( $variation_id );
			if ( ! $trashed || 'trash' !== get_post_status( $variation_id ) ) {
				self::restore_meta_snapshot( $variation_id, $identity['snapshot'] );
				self::restore_meta_snapshot( $variation_id, $marker_snapshot );
				if ( $product_map instanceof Mobo_Core_Product_Map && ! $product_map->restore_variation_rows_snapshot( $variation_id, $map_snapshot ) ) {
					return new WP_Error( 'mobo_core_variation_quarantine_rollback_map_failed', 'Trash failed and the exact Product Map snapshot could not be restored.' );
				}
				return new WP_Error( 'mobo_core_variation_quarantine_trash_failed', 'Variation could not be moved to Trash; retirement was rolled back.' );
			}
		}

		if ( $product_map instanceof Mobo_Core_Product_Map && ! $product_map->delete_variation_by_post_id_verified( $variation_id ) ) {
			return new WP_Error( 'mobo_core_variation_quarantine_map_cleanup_failed', 'Variation is quarantined, but its Product Map row could not be removed and verified.' );
		}

		foreach ( self::identity_keys() as $key ) {
			delete_post_meta( $variation_id, $key );
		}
		foreach ( self::identity_keys() as $key ) {
			if ( '' !== trim( (string) get_post_meta( $variation_id, $key, true ) ) ) {
				return new WP_Error( 'mobo_core_variation_quarantine_identity_cleanup_failed', 'Variation is quarantined, but blocking identity metadata could not be cleared.' );
			}
		}

		return true;
	}
}
