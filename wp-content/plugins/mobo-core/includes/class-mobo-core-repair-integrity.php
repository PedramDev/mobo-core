<?php
/**
 * Bounded data-integrity repair stages used by the existing product Repair.
 *
 * The service is deliberately conservative:
 * - it never deletes parent products;
 * - duplicate variations are only quarantined when source identity, parent and
 *   storefront attribute signature all agree;
 * - signature-only duplicates are reported, not mutated;
 * - price-meta cleanup is restricted to Mobo-owned products/variations;
 * - stale shipping mappings are removed only when their WooCommerce instance
 *   no longer exists.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Repair_Integrity {

	const LAST_RESULT_OPTION = 'mobo_core_repair_integrity_last_result';
	const PHASE_PORTAL_VARIANTS = 'portal-variant-duplicates';
	const PHASE_TRASHED_PRODUCTS = 'trashed-products';
	const PHASE_SIGNATURE_REPORT = 'variation-signature-report';
	const PHASE_PRICE_META = 'price-meta';
	const PHASE_SHIPPING = 'shipping-mappings';
	const PHASE_DONE = 'done';

	/** @var Mobo_Core_Product_Map|null */
	private $product_map;

	public function __construct() {
		$this->product_map = class_exists( 'Mobo_Core_Product_Map' ) ? new Mobo_Core_Product_Map() : null;
	}

	/**
	 * Run one bounded repair slice and mutate the manual-sync state by reference.
	 *
	 * @param array $state Manual sync state.
	 * @return array|WP_Error
	 */
	public function run_slice( &$state ) {
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'mobo_core_repair_integrity_state_invalid', 'Repair integrity state is invalid.' );
		}

		$phase = isset( $state['repairIntegrityPhase'] ) ? sanitize_key( (string) $state['repairIntegrityPhase'] ) : '';
		if ( '' === $phase ) {
			$phase = self::PHASE_PORTAL_VARIANTS;
		}

		if ( empty( $state['repairIntegrityStats'] ) || ! is_array( $state['repairIntegrityStats'] ) ) {
			$state['repairIntegrityStats'] = $this->empty_stats();
		} else {
			$state['repairIntegrityStats'] = wp_parse_args( $state['repairIntegrityStats'], $this->empty_stats() );
		}

		switch ( $phase ) {
			case self::PHASE_PORTAL_VARIANTS:
				return $this->run_portal_variant_duplicate_slice( $state );

			case self::PHASE_TRASHED_PRODUCTS:
				return $this->run_trashed_product_recovery_slice( $state );

			case self::PHASE_SIGNATURE_REPORT:
				return $this->run_signature_report_slice( $state );

			case self::PHASE_PRICE_META:
				return $this->run_price_meta_slice( $state );

			case self::PHASE_SHIPPING:
				return $this->run_shipping_cleanup_slice( $state );

			case self::PHASE_DONE:
			default:
				$state['repairIntegrityComplete'] = true;
				return array( 'success' => true, 'done' => true, 'message' => $this->completion_message( $state['repairIntegrityStats'] ) );
		}
	}

	private function empty_stats() {
		return array(
			'portalVariantGroupsScanned' => 0,
			'portalVariantGroupsRepaired' => 0,
			'portalVariantDuplicatesQuarantined' => 0,
			'portalVariantAmbiguousGroups' => 0,
			'portalVariantExcludedGroups' => 0,
			'trashedProductsScanned' => 0,
			'trashedProductsRestored' => 0,
			'trashedProductsExcluded' => 0,
			'trashedProductsRemoteMissing' => 0,
			'trashedProductsAmbiguous' => 0,
			'variationSignatureDuplicateGroups' => 0,
			'variationSignatureDuplicateRows' => 0,
			'priceMetaObjectsScanned' => 0,
			'priceMetaObjectsRepaired' => 0,
			'priceMetaRowsRemoved' => 0,
			'priceMetaObjectsExcluded' => 0,
			'staleShippingMappingsRemoved' => 0,
			'legacyShippingMethodsDisabled' => 0,
			'legacyShippingFallbacksDisabled' => 0,
			'legacyShippingMirrorsDisabled' => 0,
			'legacyShippingZonesRemoved' => 0,
			'errors' => 0,
			'ambiguousSamples' => array(),
			'repairedSamples' => array(),
		);
	}

	private function run_portal_variant_duplicate_slice( &$state ) {
		global $wpdb;

		$cursor = absint( isset( $state['repairIntegrityCursor'] ) ? $state['repairIntegrityCursor'] : 0 );
		$limit  = 25;
		$portal_keys = class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::portal_variant_id_meta_keys() : array( '_mobo_portal_variant_id', 'mobo_portal_variant_id', 'portal_variant_id' );
		$portal_keys = array_values( array_filter( array_map( 'sanitize_key', $portal_keys ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $portal_keys ), '%s' ) );
		$sql = "SELECT CAST(pm.meta_value AS UNSIGNED) AS portal_variant_id, COUNT(DISTINCT p.ID) AS object_count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key IN ({$placeholders})
			AND pm.meta_value REGEXP '^[0-9]+$'
			AND CAST(pm.meta_value AS UNSIGNED) > %d
			AND p.post_type = 'product_variation'
			AND p.post_status IN ('publish','private','draft','pending')
			GROUP BY CAST(pm.meta_value AS UNSIGNED)
			HAVING COUNT(DISTINCT p.ID) > 1
			ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC
			LIMIT %d";
		$args = array_merge( $portal_keys, array( $cursor, $limit ) );
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		if ( empty( $rows ) ) {
			$state['repairIntegrityPhase']  = self::PHASE_TRASHED_PRODUCTS;
			$state['repairIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => 'Repair هویت تنوع‌ها کامل شد؛ محصولات Mobo داخل Trash با Portal تطبیق داده می‌شوند.' );
		}

		foreach ( $rows as $row ) {
			$portal_variant_id = absint( isset( $row['portal_variant_id'] ) ? $row['portal_variant_id'] : 0 );
			if ( $portal_variant_id <= 0 ) {
				continue;
			}
			$state['repairIntegrityStats']['portalVariantGroupsScanned']++;

			$result = $this->repair_portal_variant_group( $portal_variant_id );
			if ( is_wp_error( $result ) ) {
				$state['repairIntegrityStats']['errors']++;
				$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], array( 'portalVariantId' => $portal_variant_id, 'reason' => $result->get_error_message() ) );
				/* Keep the cursor before this identity. A failed quarantine transition is
				 * not an acknowledged Repair result and must remain retryable. */
				return $result;
			}

			$state['repairIntegrityCursor'] = max( absint( $state['repairIntegrityCursor'] ), $portal_variant_id );
			if ( ! empty( $result['repaired'] ) ) {
				$state['repairIntegrityStats']['portalVariantGroupsRepaired']++;
				$state['repairIntegrityStats']['portalVariantDuplicatesQuarantined'] += absint( $result['quarantined'] );
				$this->append_sample( $state['repairIntegrityStats']['repairedSamples'], $result );
			} elseif ( ! empty( $result['excluded'] ) ) {
				$state['repairIntegrityStats']['portalVariantExcludedGroups']++;
			} elseif ( ! empty( $result['ambiguous'] ) ) {
				$state['repairIntegrityStats']['portalVariantAmbiguousGroups']++;
				$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], $result );
			}
		}

		return array(
			'success' => true,
			'done'    => false,
			'message' => sprintf(
				'Repair هویت تنوع‌ها: %d گروه بررسی، %d گروه اصلاح، %d تنوع اضافی قرنطینه، %d گروه مبهم.',
				absint( $state['repairIntegrityStats']['portalVariantGroupsScanned'] ),
				absint( $state['repairIntegrityStats']['portalVariantGroupsRepaired'] ),
				absint( $state['repairIntegrityStats']['portalVariantDuplicatesQuarantined'] ),
				absint( $state['repairIntegrityStats']['portalVariantAmbiguousGroups'] )
			),
		);
	}

	private function repair_portal_variant_group( $portal_variant_id, $product_lock_token = false, $locked_product_guid = '' ) {
		global $wpdb;

		$portal_keys = class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::portal_variant_id_meta_keys() : array( '_mobo_portal_variant_id', 'mobo_portal_variant_id', 'portal_variant_id' );
		$portal_keys = array_values( array_filter( array_map( 'sanitize_key', $portal_keys ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $portal_keys ), '%s' ) );
		$sql = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = 'product_variation'
			AND p.post_status IN ('publish','private','draft','pending')
			AND pm.meta_key IN ({$placeholders})
			AND pm.meta_value REGEXP '^[0-9]+$'
			AND CAST(pm.meta_value AS UNSIGNED) = %d
			ORDER BY p.ID ASC";
		$args = array_merge( $portal_keys, array( absint( $portal_variant_id ) ) );
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
		$ids = $wpdb->get_col( $prepared );
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( count( $ids ) <= 1 ) {
			return array( 'repaired' => false, 'ambiguous' => false, 'portalVariantId' => absint( $portal_variant_id ) );
		}

		foreach ( $ids as $id ) {
			$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
			if ( is_wp_error( $identity ) || empty( $identity['owned'] ) || absint( $identity['portalId'] ) !== absint( $portal_variant_id ) ) {
				return array(
					'repaired' => false,
					'ambiguous' => true,
					'portalVariantId' => absint( $portal_variant_id ),
					'ids' => $ids,
					'reason' => 'variation identity aliases conflict or are incomplete on at least one local variation',
				);
			}
		}

		$parents    = array();
		$signatures = array();
		foreach ( $ids as $id ) {
			$parent_id = absint( wp_get_post_parent_id( $id ) );
			$signature = $this->variation_signature_from_post( $id );
			$parents[ $parent_id ] = true;
			$signatures[ $signature ] = true;
		}

		if ( 1 !== count( $parents ) || isset( $parents[0] ) || 1 !== count( $signatures ) || isset( $signatures[''] ) ) {
			return array(
				'repaired' => false,
				'ambiguous' => true,
				'portalVariantId' => absint( $portal_variant_id ),
				'ids' => $ids,
				'reason' => 'duplicate identity spans different parents or different/empty attribute signatures',
			);
		}

		$parent_id = absint( array_key_first( $parents ) );
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) {
			return array(
				'repaired'        => false,
				'ambiguous'       => false,
				'excluded'        => true,
				'portalVariantId' => absint( $portal_variant_id ),
				'parentId'        => $parent_id,
				'ids'             => $ids,
				'reason'          => 'parent product is excluded from Mobo synchronization',
			);
		}

		/* MOBO-4430 portal duplicate product lock: revalidate the duplicate group while holding the shared product lock. */
		$product_guid_for_lock = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
		if ( '' === $product_guid_for_lock || ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			return new WP_Error( 'mobo_core_repair_product_lock_identity_missing', 'Repair cannot prove the shared product-lock identity for this duplicate group.' );
		}

		if ( false === $product_lock_token ) {
			$acquired_product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid_for_lock, 0, 180 );
			if ( false === $acquired_product_lock || is_wp_error( $acquired_product_lock ) ) {
				return new WP_Error( 'mobo_core_repair_product_lock_busy', 'Another Mobo product or variation operation currently owns this product lock; retry Repair after it completes.' );
			}

			try {
				return $this->repair_portal_variant_group( $portal_variant_id, $acquired_product_lock, $product_guid_for_lock );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $acquired_product_lock );
			}
		}

		if ( $product_guid_for_lock !== sanitize_text_field( (string) $locked_product_guid ) ) {
			return new WP_Error( 'mobo_core_repair_product_lock_identity_changed', 'Duplicate group product identity changed while Repair was waiting for the shared product lock; retry Repair.' );
		}
		/* MOBO-4428 portal duplicate durability: stale Product Map evidence must never choose or rewrite a canonical variation. */
		$map_proof = $this->prove_portal_duplicate_map_durability( $ids, $parent_id );
		if ( empty( $map_proof['safe'] ) ) {
			return array(
				'repaired' => false,
				'ambiguous' => true,
				'portalVariantId' => absint( $portal_variant_id ),
				'parentId' => $parent_id,
				'ids' => $ids,
				'reason' => sanitize_text_field( (string) ( $map_proof['reason'] ?? 'Portal duplicate Product Map durability could not be proven' ) ),
			);
		}

		$canonical_id = absint( $map_proof['canonicalId'] ?? 0 );
		if ( $canonical_id <= 0 ) {
			$canonical_id = $this->choose_canonical_variation( $ids );
		}
		if ( $canonical_id <= 0 ) {
			return new WP_Error( 'mobo_core_repair_canonical_variation_missing', 'Could not choose a canonical duplicate variation.' );
		}

		$canonical_guid = sanitize_text_field( (string) get_post_meta( $canonical_id, 'variant_guid', true ) );
		$product_guid   = sanitize_text_field( (string) get_post_meta( $canonical_id, 'product_guid', true ) );
		$source_hash    = sanitize_text_field( (string) get_post_meta( $canonical_id, '_mobo_variant_source_hash', true ) );
		if ( '' === $canonical_guid || '' === $product_guid || '' === $source_hash ) {
			return array(
				'repaired' => false,
				'ambiguous' => true,
				'portalVariantId' => absint( $portal_variant_id ),
				'parentId' => $parent_id,
				'ids' => $ids,
				'reason' => 'canonical duplicate variation lacks durable GUID/product/source-hash evidence',
			);
		}

		$this->replace_meta_single( $canonical_id, '_mobo_portal_variant_id', (string) absint( $portal_variant_id ) );
		$this->replace_meta_single( $canonical_id, 'mobo_portal_variant_id', (string) absint( $portal_variant_id ) );
		$this->replace_meta_single( $canonical_id, 'portal_variant_id', (string) absint( $portal_variant_id ) );

		if ( ! $this->product_map->upsert_variation( $canonical_guid, $canonical_id, $product_guid, $source_hash, false ) ) {
			return new WP_Error( 'mobo_core_repair_variation_map_persist_failed', 'Could not durably persist the canonical duplicate Variation Map row.' );
		}

		$quarantined = 0;
		foreach ( $ids as $id ) {
			if ( $id === $canonical_id ) {
				continue;
			}
			if ( $this->quarantine_exact_duplicate_variation( $id, $canonical_id, $portal_variant_id, $parent_id ) ) {
				$quarantined++;
			}
		}

		$expected_quarantine = max( 0, count( $ids ) - 1 );
		if ( $quarantined !== $expected_quarantine ) {
			return new WP_Error( 'mobo_core_repair_duplicate_quarantine_incomplete', 'Duplicate variation group was only partially quarantined; rerun Repair after reviewing the remaining live duplicate.' );
		}

		$this->refresh_product_caches( $parent_id );
		return array(
			'repaired' => true,
			'ambiguous' => false,
			'portalVariantId' => absint( $portal_variant_id ),
			'parentId' => $parent_id,
			'keptId' => $canonical_id,
			'quarantined' => $quarantined,
		);
	}

	/**
	 * Prove that any Product Map evidence attached to a Portal-ID duplicate group is durable.
	 *
	 * A valid existing map may choose the canonical member. Missing map evidence is allowed,
	 * but stale, incomplete, split, or hash-disagreeing evidence must fail closed.
	 *
	 * @param array $ids Duplicate variation IDs.
	 * @param int   $parent_id Shared parent product ID.
	 * @return array
	 */
	private function prove_portal_duplicate_map_durability( $ids, $parent_id ) {
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		$parent_id = absint( $parent_id );
		if ( count( $ids ) <= 1 || $parent_id <= 0 || ! $this->product_map instanceof Mobo_Core_Product_Map ) {
			return array( 'safe' => false, 'reason' => 'Portal duplicate Product Map proof services unavailable' );
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
		if ( '' === $product_guid ) {
			return array( 'safe' => false, 'reason' => 'Portal duplicate parent product GUID is missing' );
		}

		$mapped_candidates = array();
		foreach ( $ids as $id ) {
			$variant_guid = sanitize_text_field( (string) get_post_meta( $id, 'variant_guid', true ) );
			$member_product_guid = sanitize_text_field( (string) get_post_meta( $id, 'product_guid', true ) );
			if ( '' === $variant_guid || '' === $member_product_guid || $member_product_guid !== $product_guid ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate member lacks durable product or variation GUID identity' );
			}

			$mapped_id_for_guid = absint( $this->product_map->get_variation_id( $variant_guid ) );
			if ( $mapped_id_for_guid > 0 && ! in_array( $mapped_id_for_guid, $ids, true ) ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate variation GUID is mapped outside the duplicate group' );
			}

			$rows = $this->product_map->snapshot_variation_rows_by_post_id( $id );
			if ( is_wp_error( $rows ) ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate Product Map snapshot failed' );
			}
			if ( count( $rows ) > 1 ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate member owns multiple variation map rows' );
			}
			if ( empty( $rows ) ) {
				continue;
			}

			$row = $rows[0];
			$source_hash = sanitize_text_field( (string) get_post_meta( $id, '_mobo_variant_source_hash', true ) );
			$map_hash = sanitize_text_field( (string) ( $row['last_hash'] ?? '' ) );
			if ( $variant_guid !== sanitize_text_field( (string) ( $row['remote_guid'] ?? '' ) )
				|| $id !== absint( $row['wp_post_id'] ?? 0 )
				|| 'variation' !== sanitize_key( (string) ( $row['object_type'] ?? '' ) )
				|| $product_guid !== sanitize_text_field( (string) ( $row['parent_remote_guid'] ?? '' ) ) ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate Product Map identity disagrees with the mapped member' );
			}
			if ( ! empty( $row['sync_incomplete'] ) || '1' === (string) get_post_meta( $id, 'mobo_sync_incomplete', true ) ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate mapped member or map row is sync incomplete' );
			}
			if ( '' === $source_hash || '' === $map_hash || ! hash_equals( $source_hash, $map_hash ) ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate mapped member source hash disagrees with Product Map' );
			}
			if ( $mapped_id_for_guid !== $id ) {
				return array( 'safe' => false, 'reason' => 'Portal duplicate Product Map lookup does not resolve to the mapped member' );
			}

			$mapped_candidates[] = $id;
		}

		if ( count( $mapped_candidates ) > 1 ) {
			return array( 'safe' => false, 'reason' => 'Portal duplicate group has multiple mapped canonical candidates' );
		}

		return array(
			'safe' => true,
			'canonicalId' => ! empty( $mapped_candidates ) ? absint( $mapped_candidates[0] ) : 0,
			'productGuid' => $product_guid,
		);
	}
	private function choose_canonical_variation( $ids ) {
		$ranked = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id <= 0 || 'product_variation' !== get_post_type( $id ) ) {
				continue;
			}
			$score = 0;
			$guid = sanitize_text_field( (string) get_post_meta( $id, 'variant_guid', true ) );
			if ( '' !== $guid && $this->product_map instanceof Mobo_Core_Product_Map && absint( $this->product_map->get_variation_id( $guid ) ) === $id ) {
				$score += 100;
			}
			if ( '1' !== (string) get_post_meta( $id, 'mobo_sync_incomplete', true ) ) {
				$score += 20;
			}
			if ( '' !== (string) get_post_meta( $id, '_mobo_variant_source_hash', true ) ) {
				$score += 10;
			}
			if ( 'publish' === get_post_status( $id ) ) {
				$score += 5;
			}
			$ranked[] = array( 'id' => $id, 'score' => $score );
		}
		usort(
			$ranked,
			static function ( $a, $b ) {
				if ( absint( $a['score'] ) === absint( $b['score'] ) ) {
					/* Prefer the older local ID to preserve historical order references. */
					return absint( $a['id'] ) <=> absint( $b['id'] );
				}
				return absint( $b['score'] ) <=> absint( $a['score'] );
			}
		);
		return ! empty( $ranked[0]['id'] ) ? absint( $ranked[0]['id'] ) : 0;
	}

	private function portal_variant_identity_value( $variation_id ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 ) {
			return 0;
		}
		$keys = class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::portal_variant_id_meta_keys() : array( '_mobo_portal_variant_id', 'mobo_portal_variant_id', 'portal_variant_id' );
		$values = array();
		foreach ( $keys as $key ) {
			$value = absint( get_post_meta( $variation_id, $key, true ) );
			if ( $value > 0 ) {
				$values[ $value ] = true;
			}
		}
		if ( count( $values ) > 1 ) {
			return new WP_Error( 'mobo_core_repair_portal_variant_alias_conflict', 'Variation has conflicting Portal variant identity aliases.' );
		}
		return empty( $values ) ? 0 : absint( array_key_first( $values ) );
	}

	private function quarantine_exact_duplicate_variation( $variation_id, $canonical_id, $portal_variant_id, $parent_id ) {
		$variation_id = absint( $variation_id );
		$canonical_id = absint( $canonical_id );
		$parent_id    = absint( $parent_id );
		if ( $variation_id <= 0 || $canonical_id <= 0 || $variation_id === $canonical_id || 'product_variation' !== get_post_type( $variation_id ) ) {
			return false;
		}
		if ( absint( wp_get_post_parent_id( $variation_id ) ) !== $parent_id || $this->variation_signature_from_post( $variation_id ) !== $this->variation_signature_from_post( $canonical_id ) ) {
			return false;
		}
		$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $variation_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
		if ( is_wp_error( $identity ) || empty( $identity['owned'] ) || absint( $identity['portalId'] ) !== absint( $portal_variant_id ) ) {
			return false;
		}
		$result = Mobo_Core_Variation_Lifecycle_Policy::quarantine(
			$variation_id,
			'repair-exact-duplicate',
			array( 'canonicalId' => $canonical_id, 'portalVariantId' => absint( $portal_variant_id ), 'parentId' => $parent_id, 'repairDuplicate' => true ),
			$this->product_map
		);
		return ! is_wp_error( $result );
	}


	/**
	 * Restore a trashed Mobo parent only when the current Portal endpoint confirms
	 * the exact GUID. This is intentionally one network lookup per slice so an
	 * explicit Repair remains bounded even when an old store has many Trash rows.
	 *
	 * @param array $state Manual sync state.
	 * @return array|WP_Error
	 */
	private function run_trashed_product_recovery_slice( &$state ) {
		global $wpdb;

		$cursor = absint( isset( $state['repairIntegrityCursor'] ) ? $state['repairIntegrityCursor'] : 0 );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.ID, MAX(pm.meta_value) AS product_guid
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.ID > %d
				AND p.post_type = 'product'
				AND p.post_status = 'trash'
				AND pm.meta_key = 'product_guid'
				AND pm.meta_value <> ''
				GROUP BY p.ID
				ORDER BY p.ID ASC
				LIMIT 1",
				$cursor
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			$state['repairIntegrityPhase']  = self::PHASE_SIGNATURE_REPORT;
			$state['repairIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => sprintf( 'بررسی Trash کامل شد: %d محصول بررسی، %d محصول با تأیید Portal بازیابی شد؛ محصولات مستثنی بدون تغییر باقی ماندند.', absint( $state['repairIntegrityStats']['trashedProductsScanned'] ), absint( $state['repairIntegrityStats']['trashedProductsRestored'] ) ) );
		}

		$post_id      = absint( isset( $row['ID'] ) ? $row['ID'] : 0 );
		$product_guid = sanitize_text_field( (string) ( isset( $row['product_guid'] ) ? $row['product_guid'] : '' ) );
		if ( $post_id <= 0 || '' === $product_guid ) {
			$state['repairIntegrityCursor'] = max( $cursor, $post_id );
			$state['repairIntegrityStats']['trashedProductsAmbiguous']++;
			return array( 'success' => true, 'done' => false, 'message' => 'یک محصول Trash هویت معتبر نداشت و بدون تغییر رد شد.' );
		}

		$state['repairIntegrityStats']['trashedProductsScanned']++;

		/* If the GUID already has another active local parent, this Trash row is a
		 * local duplicate/history record and must never be auto-restored. */
		$active_count = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'product'
					AND p.post_status <> 'trash'
					AND pm.meta_key = 'product_guid'
					AND pm.meta_value = %s",
					$product_guid
				)
			)
		);
		$trash_count = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'product'
					AND p.post_status = 'trash'
					AND pm.meta_key = 'product_guid'
					AND pm.meta_value = %s",
					$product_guid
				)
			)
		);
		if ( $active_count > 0 || 1 !== $trash_count ) {
			$state['repairIntegrityCursor'] = $post_id;
			$state['repairIntegrityStats']['trashedProductsAmbiguous']++;
			$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], array( 'productId' => $post_id, 'productGuid' => $product_guid, 'reason' => 'trashed identity is not unique or already has an active local product' ) );
			return array( 'success' => true, 'done' => false, 'message' => 'محصول Trash به دلیل هویت محلی مبهم بدون تغییر باقی ماند.' );
		}

		/* Repair must never resurrect a product the administrator explicitly excluded.
		 * Check local durable URL evidence before making any Portal request. */
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $post_id ) ) {
			$state['repairIntegrityCursor'] = $post_id;
			$state['repairIntegrityStats']['trashedProductsExcluded']++;
			return array( 'success' => true, 'done' => false, 'message' => 'محصول Trash در فهرست عدم همگام‌سازی است و Repair آن را بازیابی نکرد.' );
		}

		if ( ! class_exists( 'Mobo_Core_API_Client' ) ) {
			return new WP_Error( 'mobo_core_repair_api_unavailable', 'Portal API client is unavailable for trashed-product verification.' );
		}
		$api      = new Mobo_Core_API_Client();
		$response = $api->get_product_by_guid( $product_guid, 'repair-trash-' . $post_id );
		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) ? absint( isset( $data['status'] ) ? $data['status'] : 0 ) : 0;
			if ( 404 === $status ) {
				$state['repairIntegrityCursor'] = $post_id;
				$state['repairIntegrityStats']['trashedProductsRemoteMissing']++;
				return array( 'success' => true, 'done' => false, 'message' => 'محصول Trash در Portal فعلی پیدا نشد و بدون تغییر باقی ماند.' );
			}
			return $response;
		}

		$items = $this->payload_value( $response, 'data', array() );
		$items = is_array( $items ) ? $items : array();
		$confirmed = false;
		$confirmed_candidate = array();
		foreach ( $items as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$candidate_guid = sanitize_text_field( (string) $this->payload_value( $candidate, 'productId', '' ) );
			if ( '' === $candidate_guid ) {
				$candidate_guid = sanitize_text_field( (string) $this->payload_value( $candidate, 'id', '' ) );
			}
			if ( '' !== $candidate_guid && 0 === strcasecmp( $candidate_guid, $product_guid ) ) {
				$confirmed = true;
				$confirmed_candidate = $candidate;
				break;
			}
		}
		if ( ! $confirmed ) {
			$state['repairIntegrityCursor'] = $post_id;
			$state['repairIntegrityStats']['trashedProductsRemoteMissing']++;
			return array( 'success' => true, 'done' => false, 'message' => 'Portal هویت دقیق محصول Trash را تأیید نکرد؛ محصول بدون تغییر باقی ماند.' );
		}

		/* Re-check the authoritative fresh payload as well. This covers old local
		 * rows which predate mobo_url or whose local URL meta is incomplete. */
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_payload_excluded( $confirmed_candidate, true ) ) {
			$state['repairIntegrityCursor'] = $post_id;
			$state['repairIntegrityStats']['trashedProductsExcluded']++;
			return array( 'success' => true, 'done' => false, 'message' => 'Portal محصول Trash را تأیید کرد، اما URL آن مستثنی است؛ محصول در Trash باقی ماند.' );
		}

		/* MOBO-4432 trashed-product recovery lock: remote confirmation is only evidence;
		 * all local resurrection/Map mutation must be revalidated under the same shared
		 * product lock used by normal Product/Variation writers. */
		if ( ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			return new WP_Error( 'mobo_core_repair_product_lock_unavailable', 'Shared product locking is unavailable for trashed-product recovery.' );
		}

		$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 180 );
		if ( false === $product_lock || is_wp_error( $product_lock ) ) {
			return new WP_Error( 'mobo_core_repair_product_lock_busy', 'Another Mobo product or variation operation currently owns this product lock; retry Repair after it completes.' );
		}

		try {
			$locked_post = get_post( $post_id );
			$locked_guid = sanitize_text_field( (string) get_post_meta( $post_id, 'product_guid', true ) );
			if ( ! $locked_post || 'product' !== $locked_post->post_type || 'trash' !== get_post_status( $post_id ) || 0 !== strcasecmp( $locked_guid, $product_guid ) ) {
				return new WP_Error( 'mobo_core_repair_trash_identity_changed', 'Trashed-product identity changed while Repair was waiting for the shared product lock; retry Repair.' );
			}

			$active_count_locked = absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT p.ID)
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
						WHERE p.post_type = 'product'
						AND p.post_status <> 'trash'
						AND pm.meta_key = 'product_guid'
						AND pm.meta_value = %s",
						$product_guid
					)
				)
			);
			$trash_count_locked = absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT p.ID)
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
						WHERE p.post_type = 'product'
						AND p.post_status = 'trash'
						AND pm.meta_key = 'product_guid'
						AND pm.meta_value = %s",
						$product_guid
					)
				)
			);

			if ( $active_count_locked > 0 || 1 !== $trash_count_locked ) {
				$state['repairIntegrityCursor'] = $post_id;
				$state['repairIntegrityStats']['trashedProductsAmbiguous']++;
				$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], array( 'productId' => $post_id, 'productGuid' => $product_guid, 'reason' => 'trashed identity changed during Portal verification or an active canonical product now exists' ) );
				return array( 'success' => true, 'done' => false, 'message' => 'Trash recovery stopped because local product identity changed during Portal verification.' );
			}

			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $post_id ) ) {
				$state['repairIntegrityCursor'] = $post_id;
				$state['repairIntegrityStats']['trashedProductsExcluded']++;
				return array( 'success' => true, 'done' => false, 'message' => 'Trash recovery stopped because the product became locally excluded during Portal verification.' );
			}

			if ( $this->product_map instanceof Mobo_Core_Product_Map && Mobo_Core_Product_Map::table_exists() ) {
				$map_owner_id = absint(
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT wp_post_id FROM " . Mobo_Core_Product_Map::table_name() . " WHERE remote_guid = %s AND object_type = 'product' LIMIT 1",
							$product_guid
						)
					)
				);
				if ( $map_owner_id > 0 && $map_owner_id !== $post_id ) {
					$state['repairIntegrityCursor'] = $post_id;
					$state['repairIntegrityStats']['trashedProductsAmbiguous']++;
					$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], array( 'productId' => $post_id, 'productGuid' => $product_guid, 'reason' => 'Product Map ownership moved to another local product during Portal verification', 'mapOwnerId' => $map_owner_id ) );
					return array( 'success' => true, 'done' => false, 'message' => 'Trash recovery stopped because Product Map ownership moved during Portal verification.' );
				}
			}
		$restored = wp_untrash_post( $post_id );
		if ( ! $restored || 'trash' === get_post_status( $post_id ) ) {
			$state['repairIntegrityCursor'] = $post_id;
			$state['repairIntegrityStats']['errors']++;
			$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], array( 'productId' => $post_id, 'productGuid' => $product_guid, 'reason' => 'Portal confirmed identity but wp_untrash_post failed' ) );
			return array( 'success' => true, 'done' => false, 'message' => 'Portal محصول را تأیید کرد ولی بازیابی WordPress ناموفق بود؛ مورد برای بررسی باقی ماند.' );
		}

		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$this->product_map->upsert_product( $product_guid, $post_id, sanitize_text_field( (string) get_post_meta( $post_id, '_mobo_product_source_hash', true ) ), '1' === (string) get_post_meta( $post_id, 'mobo_sync_incomplete', true ) );
		}
		$state['repairIntegrityCursor'] = $post_id;
		$state['repairIntegrityStats']['trashedProductsRestored']++;
		$this->refresh_product_caches( $post_id );
		$this->append_sample( $state['repairIntegrityStats']['repairedSamples'], array( 'productId' => $post_id, 'productGuid' => $product_guid, 'repair' => 'portal-confirmed-untrash' ) );

		return array( 'success' => true, 'done' => false, 'message' => sprintf( 'محصول Trash با تأیید مستقیم Portal بازیابی شد: #%d.', $post_id ) );
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
		}
	}

	private function run_signature_report_slice( &$state ) {
		global $wpdb;

		$cursor = absint( isset( $state['repairIntegrityCursor'] ) ? $state['repairIntegrityCursor'] : 0 );
		$parents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.post_parent
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_guid'
				WHERE p.post_type = 'product_variation'
				AND p.post_status IN ('publish','private','draft','pending')
				AND p.post_parent > %d
				ORDER BY p.post_parent ASC
				LIMIT 25",
				$cursor
			)
		);
		$parents = array_values( array_filter( array_unique( array_map( 'absint', is_array( $parents ) ? $parents : array() ) ) ) );
		if ( empty( $parents ) ) {
			$state['repairIntegrityPhase']  = self::PHASE_PRICE_META;
			$state['repairIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => 'گزارش تنوع‌های هم‌امضا کامل شد؛ پاکسازی meta قیمت شروع می‌شود.' );
		}

		foreach ( $parents as $parent_id ) {
			$state['repairIntegrityCursor'] = max( absint( $state['repairIntegrityCursor'] ), $parent_id );
			$children = get_posts(
				array(
					'post_type' => 'product_variation',
					'post_parent' => $parent_id,
					'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page' => -1,
					'fields' => 'ids',
					'orderby' => 'ID',
					'order' => 'ASC',
					'no_found_rows' => true,
				)
			);
			$by_signature = array();
			foreach ( is_array( $children ) ? $children : array() as $child_id ) {
				$signature = $this->variation_signature_from_post( absint( $child_id ) );
				if ( '' !== $signature ) {
					$by_signature[ $signature ][] = absint( $child_id );
				}
			}
			foreach ( $by_signature as $signature => $ids ) {
				if ( count( $ids ) <= 1 ) {
					continue;
				}
				$state['repairIntegrityStats']['variationSignatureDuplicateGroups']++;
				$state['repairIntegrityStats']['variationSignatureDuplicateRows'] += count( $ids ) - 1;
				$this->append_sample(
					$state['repairIntegrityStats']['ambiguousSamples'],
					array( 'parentId' => $parent_id, 'signature' => $signature, 'ids' => array_values( $ids ), 'reason' => 'signature-only duplicate; preserved for authoritative Portal sync' )
				);
			}
		}

		return array(
			'success' => true,
			'done' => false,
			'message' => sprintf(
				'گزارش تنوع‌های هم‌امضا: %d گروه (%d ردیف اضافی)؛ موارد مبهم حذف نشدند.',
				absint( $state['repairIntegrityStats']['variationSignatureDuplicateGroups'] ),
				absint( $state['repairIntegrityStats']['variationSignatureDuplicateRows'] )
			),
		);
	}

	/**
	 * Run the bounded final integrity pass after the authoritative Repair product
	 * snapshot. Only parents carrying the exact current Repair commit marker are
	 * eligible, so OnlyInStock omissions never become destructive evidence.
	 *
	 * @param array $state Manual sync state.
	 * @return array|WP_Error
	 */
	public function run_post_sync_slice( &$state ) {
		if ( ! is_array( $state ) || empty( $state['repairMode'] ) ) {
			return new WP_Error( 'mobo_core_post_repair_state_invalid', 'Post-sync Repair requires an active Repair state.' );
		}
		$sync_id = sanitize_text_field( (string) ( $state['syncId'] ?? '' ) );
		if ( '' === $sync_id ) {
			return new WP_Error( 'mobo_core_post_repair_sync_id_missing', 'Post-sync Repair requires a syncId.' );
		}
		if ( empty( $state['postSyncIntegrityStats'] ) || ! is_array( $state['postSyncIntegrityStats'] ) ) {
			$state['postSyncIntegrityStats'] = $this->empty_post_sync_stats();
		} else {
			$state['postSyncIntegrityStats'] = wp_parse_args( $state['postSyncIntegrityStats'], $this->empty_post_sync_stats() );
		}
		$phase = sanitize_key( (string) ( $state['postSyncIntegrityPhase'] ?? 'variation-topology' ) );
		if ( '' === $phase ) {
			$phase = 'variation-topology';
		}
		switch ( $phase ) {
			case 'variation-topology':
				return $this->run_post_sync_variation_slice( $state, $sync_id );
			case 'legacy-simple-topology':
				return $this->run_post_sync_legacy_simple_slice( $state, $sync_id );
			case 'map-backed-duplicate-variants':
				return $this->run_post_sync_map_backed_duplicate_slice( $state, $sync_id );
			case 'price-meta':
				return $this->run_post_sync_price_meta_slice( $state );
			case 'done':
			default:
				$state['postSyncIntegrityComplete'] = true;
				return array( 'success' => true, 'done' => true, 'message' => $this->post_sync_completion_message( $state['postSyncIntegrityStats'] ) );
		}
	}

	private function empty_post_sync_stats() {
		return array(
			'parentsScanned' => 0,
			'simpleParentVariationsQuarantined' => 0,
			'legacyParentsScanned' => 0,
			'legacySimpleParentVariationsQuarantined' => 0,
			'legacyCanonicalProofSkipped' => 0,
			'legacyIdentityConflictsPreserved' => 0,
			'duplicateVariationsQuarantined' => 0,
			'mapBackedDuplicateGroupsScanned' => 0,
			'mapBackedDuplicateGroupsRepaired' => 0,
			'mapBackedDuplicateVariationsQuarantined' => 0,
			'mapBackedDuplicateProofSkipped' => 0,
			'mapBackedDuplicateIdentityConflictsPreserved' => 0,
			'ambiguousDuplicateGroups' => 0,
			'staleFenceSkipped' => 0,
			'productLockBusySkipped' => 0,
			'priceMetaObjectsScanned' => 0,
			'priceMetaObjectsRepaired' => 0,
			'priceMetaRowsRemoved' => 0,
			'priceMetaObjectsExcluded' => 0,
			'errors' => 0,
			'samples' => array(),
		);
	}

	private function run_post_sync_variation_slice( &$state, $sync_id ) {
		global $wpdb;
		$cursor = absint( $state['postSyncIntegrityCursor'] ?? 0 );
		$marker_key = class_exists( 'Mobo_Core_Product_Sync' ) ? Mobo_Core_Product_Sync::REPAIR_SYNC_ID_META : '_mobo_last_repair_sync_id';
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} marker ON marker.post_id=p.ID AND marker.meta_key=%s AND marker.meta_value=%s
				INNER JOIN {$wpdb->postmeta} complete ON complete.post_id=p.ID AND complete.meta_key='mobo_sync_incomplete' AND complete.meta_value='0'
				WHERE p.ID > %d
				AND p.post_type='product'
				AND p.post_status NOT IN ('trash','auto-draft')
				ORDER BY p.ID ASC
				LIMIT 20",
				$marker_key,
				$sync_id,
				$cursor
			)
		);
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( empty( $ids ) ) {
			$state['postSyncIntegrityPhase'] = 'legacy-simple-topology';
			$state['postSyncIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => 'بررسی topology نسل جاری کامل شد؛ cleanup محافظه‌کارانه legacy زیر parentهای Simple شروع می‌شود.' );
		}

		foreach ( $ids as $parent_id ) {
			$state['postSyncIntegrityStats']['parentsScanned']++;
			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) {
				$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
				continue;
			}

			/*
			 * A Repair-generation marker is only ordering evidence while the exact
			 * product watermarks still match the completion checkpoint that wrote it.
			 * A newer webhook therefore makes this parent permanently ineligible for
			 * this Repair generation instead of allowing stale post-sync mutation.
			 */
			if ( ! $this->repair_marker_is_current( $parent_id, $sync_id, $marker_key ) ) {
				$state['postSyncIntegrityStats']['staleFenceSkipped']++;
				$this->append_sample(
					$state['postSyncIntegrityStats']['samples'],
					array(
						'parentId' => $parent_id,
						'reason'   => 'repair ordering marker no longer matches current product watermark',
					)
				);
				$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
				continue;
			}

			$product_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
			if ( '' === $product_guid || ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
				$state['postSyncIntegrityStats']['errors']++;
				$this->append_sample(
					$state['postSyncIntegrityStats']['samples'],
					array(
						'parentId' => $parent_id,
						'reason'   => 'post-sync Repair requires durable product GUID and product lock support',
					)
				);
				$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
				continue;
			}

			$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 180 );
			if ( false === $product_lock ) {
				$state['postSyncIntegrityStats']['productLockBusySkipped']++;
				/*
				 * Do not advance the cursor. Lock contention is transient, so the same
				 * parent must be retried rather than silently skipped.
				 */
				return array(
					'success' => true,
					'done'    => false,
					'message' => sprintf( 'Repair نهایی منتظر آزاد شدن lock محصول %d است؛ parent در اجرای بعد دوباره بررسی می‌شود.', $parent_id ),
				);
			}

			try {
				/* Re-check after acquiring the product lock to close the race between
				 * initial eligibility and mutation. Normal Mobo writers use this same
				 * lock, so watermarks cannot advance during the cleanup below. */
				if ( ! $this->repair_marker_is_current( $parent_id, $sync_id, $marker_key ) ) {
					$state['postSyncIntegrityStats']['staleFenceSkipped']++;
					$this->append_sample(
						$state['postSyncIntegrityStats']['samples'],
						array(
							'parentId' => $parent_id,
							'reason'   => 'repair ordering marker changed before product lock acquisition',
						)
					);
					$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
					continue;
				}

				$product = wc_get_product( $parent_id );
				if ( ! $product instanceof WC_Product ) {
					$state['postSyncIntegrityStats']['errors']++;
					$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
					continue;
				}
				$children = get_posts(
					array(
						'post_type' => 'product_variation',
						'post_parent' => $parent_id,
						'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
						'posts_per_page' => -1,
						'fields' => 'ids',
						'orderby' => 'ID',
						'order' => 'ASC',
						'no_found_rows' => true,
					)
				);
				$children = array_values( array_filter( array_unique( array_map( 'absint', is_array( $children ) ? $children : array() ) ) ) );
				if ( empty( $children ) ) {
					$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
					continue;
				}

				if ( ! $product->is_type( 'variable' ) ) {
					foreach ( $children as $child_id ) {
						$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $child_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
						if ( is_wp_error( $identity ) ) {
							$state['postSyncIntegrityStats']['identityConflictsPreserved'] = absint( $state['postSyncIntegrityStats']['identityConflictsPreserved'] ?? 0 ) + 1;
							$this->append_sample( $state['postSyncIntegrityStats']['samples'], array( 'parentId' => $parent_id, 'variationId' => $child_id, 'reason' => 'variation identity aliases conflict; simple-parent child preserved fail-closed' ) );
							continue;
						}
						if ( empty( $identity['owned'] ) ) {
							continue;
						}
						$quarantine = $this->quarantine_post_sync_variation( $child_id, 0, $sync_id, 'authoritative-parent-is-not-variable' );
						if ( is_wp_error( $quarantine ) ) {
							$state['postSyncIntegrityStats']['errors']++;
							/* Do not advance this parent. A failed Trash/map transition must be
							 * retried under the same product lock/generation. */
							return $quarantine;
						}
						if ( true === $quarantine ) {
							$state['postSyncIntegrityStats']['simpleParentVariationsQuarantined']++;
						} else {
							$state['postSyncIntegrityStats']['errors']++;
						}
					}
					$this->refresh_product_caches( $parent_id );
					$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
					continue;
				}

				$by_signature      = array();
				$unsafe_signatures = array();
				foreach ( $children as $child_id ) {
					$signature = $this->variation_signature_from_post( $child_id );
					if ( '' === $signature ) {
						continue;
					}
					$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $child_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
					if ( is_wp_error( $identity ) ) {
						$unsafe_signatures[ $signature ][] = $child_id;
						continue;
					}
					if ( empty( $identity['owned'] ) ) {
						continue;
					}
					$by_signature[ $signature ][] = $child_id;
				}
				foreach ( $by_signature as $signature => $group_ids ) {
					if ( ! empty( $unsafe_signatures[ $signature ] ) ) {
						$all_ids = array_values( array_unique( array_merge( $group_ids, $unsafe_signatures[ $signature ] ) ) );
						$state['postSyncIntegrityStats']['ambiguousDuplicateGroups']++;
						$this->append_sample( $state['postSyncIntegrityStats']['samples'], array( 'parentId' => $parent_id, 'signature' => $signature, 'ids' => $all_ids, 'reason' => 'variation identity aliases conflict; signature group preserved fail-closed' ) );
						continue;
					}
					if ( count( $group_ids ) <= 1 ) {
						continue;
					}

					/* Resolve every alias family before choosing a canonical. One conflicting
					 * identity makes the whole signature group ambiguous so a clean sibling is
					 * never retired based on a contradictory canonical candidate. */
					$resolved     = array();
					$group_unsafe = false;
					foreach ( $group_ids as $child_id ) {
						$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $child_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
						if ( is_wp_error( $identity ) || empty( $identity['owned'] ) ) {
							$group_unsafe = true;
							break;
						}
						$resolved[ $child_id ] = $identity;
					}
					if ( $group_unsafe ) {
						$state['postSyncIntegrityStats']['ambiguousDuplicateGroups']++;
						$this->append_sample( $state['postSyncIntegrityStats']['samples'], array( 'parentId' => $parent_id, 'signature' => $signature, 'ids' => $group_ids, 'reason' => 'variation identity aliases conflict or ownership is ambiguous' ) );
						continue;
					}

					$identity_ids = array();
					foreach ( $resolved as $child_id => $identity ) {
						if ( '' !== sanitize_text_field( (string) $identity['variantGuid'] ) || absint( $identity['portalId'] ) > 0 ) {
							$identity_ids[] = absint( $child_id );
						}
					}
					if ( 1 !== count( $identity_ids ) ) {
						$state['postSyncIntegrityStats']['ambiguousDuplicateGroups']++;
						$this->append_sample( $state['postSyncIntegrityStats']['samples'], array( 'parentId' => $parent_id, 'signature' => $signature, 'ids' => $group_ids, 'reason' => 'expected exactly one unambiguous durable variation identity' ) );
						continue;
					}
					$canonical_id = absint( $identity_ids[0] );
					foreach ( $group_ids as $child_id ) {
						$identity = $resolved[ $child_id ];
						$has_durable_identity = '' !== sanitize_text_field( (string) $identity['variantGuid'] ) || absint( $identity['portalId'] ) > 0;
						if ( $child_id === $canonical_id || $has_durable_identity ) {
							continue;
						}
						$quarantine = $this->quarantine_post_sync_variation( $child_id, $canonical_id, $sync_id, 'duplicate-signature-with-single-durable-canonical' );
						if ( is_wp_error( $quarantine ) ) {
							$state['postSyncIntegrityStats']['errors']++;
							return $quarantine;
						}
						if ( true === $quarantine ) {
							$state['postSyncIntegrityStats']['duplicateVariationsQuarantined']++;
						} else {
							$state['postSyncIntegrityStats']['errors']++;
						}
					}
				}
				$this->refresh_product_caches( $parent_id );
				$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $parent_id );
			} finally {
				Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
			}
		}

		return array(
			'success' => true,
			'done' => false,
			'message' => sprintf(
				'بررسی نهایی تنوع‌ها: %d parent، %d child stale و %d duplicate ایمن قرنطینه شد؛ %d گروه مبهم و %d parent با ordering جدیدتر حفظ شد.',
				absint( $state['postSyncIntegrityStats']['parentsScanned'] ),
				absint( $state['postSyncIntegrityStats']['simpleParentVariationsQuarantined'] ),
				absint( $state['postSyncIntegrityStats']['duplicateVariationsQuarantined'] ),
				absint( $state['postSyncIntegrityStats']['ambiguousDuplicateGroups'] ),
				absint( $state['postSyncIntegrityStats']['staleFenceSkipped'] )
			),
		);
	}


	/**
	 * Find legacy local parents that still have live variations carrying durable
	 * Mobo variation identity. This phase is intentionally independent of the
	 * current Repair-generation marker: products that are no longer returned by
	 * the current source can otherwise keep an impossible local topology forever.
	 *
	 * Mutation remains fail-closed and is allowed only after canonical local
	 * product proof + product lock + a second proof under the lock.
	 *
	 * @param int $cursor Parent product ID cursor.
	 * @param int $limit Maximum parents.
	 * @return array
	 */
	private function find_legacy_simple_parent_ids( $cursor, $limit ) {
		global $wpdb;

		$cursor = absint( $cursor );
		$limit  = max( 1, min( 100, absint( $limit ) ) );
		$identity_keys = class_exists( 'Mobo_Core_Product_Identity_Policy' )
			? Mobo_Core_Product_Identity_Policy::variation_identity_meta_keys()
			: array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid', 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
		$identity_keys = array_values( array_filter( array_map( 'sanitize_key', $identity_keys ) ) );
		if ( empty( $identity_keys ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $identity_keys ), '%s' ) );
		$sql = "SELECT DISTINCT parent.ID
			FROM {$wpdb->posts} child
			INNER JOIN {$wpdb->posts} parent ON parent.ID=child.post_parent
			INNER JOIN {$wpdb->postmeta} own ON own.post_id=child.ID
			INNER JOIN {$wpdb->postmeta} complete ON complete.post_id=parent.ID AND complete.meta_key='mobo_sync_incomplete' AND complete.meta_value='0'
			WHERE parent.ID > %d
			AND parent.post_type='product'
			AND parent.post_status NOT IN ('trash','auto-draft')
			AND child.post_type='product_variation'
			AND child.post_status IN ('publish','private','draft','pending')
			AND own.meta_key IN ({$placeholders})
			AND own.meta_value <> ''
			ORDER BY parent.ID ASC
			LIMIT %d";
		$args = array_merge( array( $cursor ), $identity_keys, array( $limit ) );
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
		$ids = $wpdb->get_col( $prepared );

		return array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Prove that a legacy local product is the unique canonical Mobo object for
	 * its product_guid and is currently a complete non-Variable product.
	 *
	 * This is local-topology evidence, not source-generation evidence. It does
	 * not authorize field overwrite; it only authorizes retirement of live
	 * Mobo-owned variation children that are impossible beneath the current
	 * non-Variable parent.
	 *
	 * @param int    $parent_id Parent product ID.
	 * @param string $reason Failure/skip reason by reference.
	 * @return array|false Array contains guid/product on success.
	 */
	private function prove_legacy_simple_parent_canonical( $parent_id, &$reason ) {
		global $wpdb;

		$parent_id = absint( $parent_id );
		$reason    = '';
		if ( $parent_id <= 0 ) {
			$reason = 'invalid-parent';
			return false;
		}
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) {
			$reason = 'excluded-parent';
			return false;
		}
		if ( '0' !== (string) get_post_meta( $parent_id, 'mobo_sync_incomplete', true ) ) {
			$reason = 'parent-sync-incomplete';
			return false;
		}

		$product = wc_get_product( $parent_id );
		if ( ! $product instanceof WC_Product ) {
			$reason = 'parent-not-loadable';
			return false;
		}
		if ( $product->is_type( 'variable' ) ) {
			$reason = 'parent-is-variable';
			return false;
		}

		$product_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
		if ( '' === $product_guid ) {
			$reason = 'missing-product-guid';
			return false;
		}
		if ( ! $this->product_map instanceof Mobo_Core_Product_Map || ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			$reason = 'canonical-proof-support-missing';
			return false;
		}

		$mapped_id = absint( $this->product_map->get_product_id( $product_guid ) );
		if ( $mapped_id !== $parent_id ) {
			$reason = 'product-map-points-elsewhere';
			return false;
		}

		$canonical_id = absint( Mobo_Core_Product_Concurrency::get_canonical_product_id( $product_guid, $mapped_id ) );
		if ( $canonical_id !== $parent_id ) {
			$reason = 'canonical-resolver-points-elsewhere';
			return false;
		}

		/* Product Map + resolver agreement is strong, but also require exactly one
		 * active local product carrying the canonical product_guid. This prevents a
		 * duplicate/collision family from being mutated by local topology cleanup. */
		$matching_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='product_guid' AND pm.meta_value=%s
				WHERE p.post_type='product'
				AND p.post_status NOT IN ('trash','auto-draft')
				ORDER BY p.ID ASC
				LIMIT 2",
				$product_guid
			)
		);
		$matching_ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $matching_ids ) ? $matching_ids : array() ) ) ) );
		if ( 1 !== count( $matching_ids ) || $matching_ids[0] !== $parent_id ) {
			$reason = 'product-guid-not-unique';
			return false;
		}

		return array(
			'guid'    => $product_guid,
			'product' => $product,
		);
	}

	/**
	 * Process one canonical legacy non-Variable parent.
	 *
	 * @param array  $state Repair state.
	 * @param string $sync_id Repair sync ID (for forensic context only).
	 * @param int    $parent_id Parent product ID.
	 * @return array|WP_Error
	 */
	private function process_legacy_simple_parent( &$state, $sync_id, $parent_id ) {
		$parent_id = absint( $parent_id );
		$state['postSyncIntegrityStats']['legacyParentsScanned']++;

		$reason = '';
		$proof  = $this->prove_legacy_simple_parent_canonical( $parent_id, $reason );
		if ( false === $proof ) {
			/* Variable parents are expected to appear in the broad candidate query
			 * and are not an integrity problem. Other proof failures are preserved
			 * fail-closed and surfaced diagnostically. */
			if ( 'parent-is-variable' !== $reason ) {
				$state['postSyncIntegrityStats']['legacyCanonicalProofSkipped']++;
				$this->append_sample(
					$state['postSyncIntegrityStats']['samples'],
					array( 'parentId' => $parent_id, 'reason' => 'legacy local-topology proof failed: ' . $reason )
				);
			}
			return array( 'success' => true, 'done' => false, 'skipped' => true );
		}

		$product_guid = sanitize_text_field( (string) $proof['guid'] );
		$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 180 );
		if ( false === $product_lock ) {
			$state['postSyncIntegrityStats']['productLockBusySkipped']++;
			return array(
				'success' => true,
				'done'    => false,
				'retry'   => true,
				'message' => sprintf( 'Repair legacy topology منتظر آزاد شدن lock محصول %d است؛ parent در اجرای بعد دوباره بررسی می‌شود.', $parent_id ),
			);
		}

		try {
			/* Close the race with webhook/product writers. We do not rely on an old
			 * generation marker here; instead we re-prove current local topology and
			 * canonical ownership while holding the same per-product lock used by
			 * normal Mobo writers. */
			$reason = '';
			$proof  = $this->prove_legacy_simple_parent_canonical( $parent_id, $reason );
			if ( false === $proof ) {
				if ( 'parent-is-variable' !== $reason ) {
					$state['postSyncIntegrityStats']['legacyCanonicalProofSkipped']++;
					$this->append_sample(
						$state['postSyncIntegrityStats']['samples'],
						array( 'parentId' => $parent_id, 'reason' => 'legacy proof changed under product lock: ' . $reason )
					);
				}
				return array( 'success' => true, 'done' => false, 'skipped' => true );
			}

			$children = get_posts(
				array(
					'post_type' => 'product_variation',
					'post_parent' => $parent_id,
					'post_status' => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page' => -1,
					'fields' => 'ids',
					'orderby' => 'ID',
					'order' => 'ASC',
					'no_found_rows' => true,
				)
			);
			$children = array_values( array_filter( array_unique( array_map( 'absint', is_array( $children ) ? $children : array() ) ) ) );

			foreach ( $children as $child_id ) {
				$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' )
					? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $child_id )
					: new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );

				if ( is_wp_error( $identity ) ) {
					$state['postSyncIntegrityStats']['legacyIdentityConflictsPreserved']++;
					$this->append_sample(
						$state['postSyncIntegrityStats']['samples'],
						array(
							'parentId'    => $parent_id,
							'variationId' => $child_id,
							'reason'      => 'legacy simple-parent variation identity is ambiguous; preserved fail-closed',
						)
					);
					continue;
				}
				if ( empty( $identity['owned'] ) ) {
					/* Merchant/manual variations are never topology-owned by MoboCore. */
					continue;
				}

				$quarantine = $this->quarantine_post_sync_variation(
					$child_id,
					0,
					$sync_id,
					'legacy-local-simple-parent'
				);
				if ( is_wp_error( $quarantine ) ) {
					$state['postSyncIntegrityStats']['errors']++;
					return $quarantine;
				}
				if ( true === $quarantine ) {
					$state['postSyncIntegrityStats']['legacySimpleParentVariationsQuarantined']++;
				}
			}

			$this->refresh_product_caches( $parent_id );
			return array( 'success' => true, 'done' => false );
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
		}
	}

	/**
	 * Repair local impossible topology left by legacy versions for products that
	 * are canonical locally but were not present in this Repair source generation.
	 *
	 * @param array  $state Repair state.
	 * @param string $sync_id Repair sync ID.
	 * @return array|WP_Error
	 */
	private function run_post_sync_legacy_simple_slice( &$state, $sync_id ) {
		$cursor = absint( $state['postSyncIntegrityCursor'] ?? 0 );
		$ids    = $this->find_legacy_simple_parent_ids( $cursor, 20 );

		if ( empty( $ids ) ) {
			$state['postSyncIntegrityPhase'] = 'map-backed-duplicate-variants';
			$state['postSyncIntegrityCursor'] = 0;
			return array(
				'success' => true,
				'done'    => false,
				'message' => 'cleanup محافظه‌کارانه legacy topology کامل شد؛ duplicate variant GUIDهای map-backed بررسی می‌شوند.',
			);
		}

		foreach ( $ids as $parent_id ) {
			$result = $this->process_legacy_simple_parent( $state, $sync_id, $parent_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['retry'] ) ) {
				/* Transient product-lock contention: do not advance cursor. */
				return $result;
			}
			$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), absint( $parent_id ) );
		}

		return array(
			'success' => true,
			'done'    => false,
			'message' => sprintf(
				'Legacy topology: %d parent بررسی، %d Mobo variation قرنطینه، %d proof نامطمئن و %d identity مبهم بدون mutation حفظ شد.',
				absint( $state['postSyncIntegrityStats']['legacyParentsScanned'] ),
				absint( $state['postSyncIntegrityStats']['legacySimpleParentVariationsQuarantined'] ),
				absint( $state['postSyncIntegrityStats']['legacyCanonicalProofSkipped'] ),
				absint( $state['postSyncIntegrityStats']['legacyIdentityConflictsPreserved'] )
			),
		);
	}



	/**
	 * Find live duplicate variant_guid groups using the oldest member as a
	 * bounded numeric cursor.
	 *
	 * @param int $cursor Cursor.
	 * @param int $limit Limit.
	 * @return array
	 */
	private function find_map_backed_duplicate_variant_groups( $cursor, $limit ) {
		global $wpdb;
		$cursor = absint( $cursor );
		$limit  = max( 1, min( 100, absint( $limit ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS variant_guid,
				        MIN(p.ID) AS cursor_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id
				WHERE pm.meta_key='variant_guid'
				AND pm.meta_value <> ''
				AND p.post_type='product_variation'
				AND p.post_status IN ('publish','private','draft','pending')
				GROUP BY pm.meta_value
				HAVING COUNT(DISTINCT p.ID) > 1
				   AND MIN(p.ID) > %d
				ORDER BY MIN(p.ID) ASC
				LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$guid = sanitize_text_field( (string) ( $row['variant_guid'] ?? '' ) );
			$cid  = absint( $row['cursor_id'] ?? 0 );
			if ( '' !== $guid && $cid > 0 ) {
				$out[] = array( 'variantGuid' => $guid, 'cursorId' => $cid );
			}
		}
		return $out;
	}

	private function live_variation_ids_by_guid( $variant_guid ) {
		global $wpdb;
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( '' === $variant_guid ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
				WHERE p.post_type='product_variation'
				AND p.post_status IN ('publish','private','draft','pending')
				AND pm.meta_key='variant_guid'
				AND pm.meta_value=%s
				ORDER BY p.ID ASC",
				$variant_guid
			)
		);

		return array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Product Map can break a duplicate identity tie only when map, parent
	 * product identity, Portal identity and storefront signature all agree.
	 *
	 * @param string $variant_guid Variant GUID.
	 * @return array
	 */
	private function prove_map_backed_duplicate_group( $variant_guid ) {
		$variant_guid = sanitize_text_field( (string) $variant_guid );
		if ( '' === $variant_guid
			|| ! $this->product_map instanceof Mobo_Core_Product_Map
			|| ! class_exists( 'Mobo_Core_Product_Concurrency' )
			|| ! class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ) {
			return array( 'safe' => false, 'reason' => 'canonical proof services unavailable' );
		}

		$ids = $this->live_variation_ids_by_guid( $variant_guid );
		if ( count( $ids ) <= 1 ) {
			return array( 'safe' => false, 'resolved' => true, 'ids' => $ids, 'reason' => 'duplicate no longer exists' );
		}

		$canonical_id = absint( $this->product_map->get_variation_id( $variant_guid ) );
		if ( $canonical_id <= 0 || ! in_array( $canonical_id, $ids, true ) ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'Product Map does not point inside duplicate group' );
		}

		$canonical_identity = Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $canonical_id );
		if ( is_wp_error( $canonical_identity )
			|| empty( $canonical_identity['owned'] )
			|| $variant_guid !== sanitize_text_field( (string) ( $canonical_identity['variantGuid'] ?? '' ) )
			|| absint( $canonical_identity['portalId'] ?? 0 ) <= 0 ) {
			return array( 'safe' => false, 'identityConflict' => true, 'ids' => $ids, 'reason' => 'canonical member lacks unambiguous GUID + Portal identity' );
		}
		$canonical_portal_id = absint( $canonical_identity['portalId'] );

		$canonical_parent_id = absint( wp_get_post_parent_id( $canonical_id ) );
		$canonical_parent    = $canonical_parent_id > 0 ? wc_get_product( $canonical_parent_id ) : false;
		$product_guid        = sanitize_text_field( (string) get_post_meta( $canonical_parent_id, 'product_guid', true ) );
		if ( ! $canonical_parent instanceof WC_Product
			|| ! $canonical_parent->is_type( 'variable' )
			|| '' === $product_guid
			|| '1' === (string) get_post_meta( $canonical_parent_id, 'mobo_sync_incomplete', true ) ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical parent is not a complete durable Variable product' );
		}
		if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $canonical_parent_id ) ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical parent excluded' );
		}

		$mapped_parent_id = absint( $this->product_map->get_product_id( $product_guid ) );
		if ( $mapped_parent_id !== $canonical_parent_id ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'Product Map product parent disagrees' );
		}
		if ( absint( Mobo_Core_Product_Concurrency::get_canonical_product_id( $product_guid, $mapped_parent_id ) ) !== $canonical_parent_id ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical product resolver disagrees' );
		}

		$map_rows = $this->product_map->snapshot_variation_rows_by_post_id( $canonical_id );
		if ( is_wp_error( $map_rows ) || 1 !== count( $map_rows ) ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical member map row count is not exactly one' );
		}
		$map_row = $map_rows[0];
		if ( $variant_guid !== sanitize_text_field( (string) ( $map_row['remote_guid'] ?? '' ) )
			|| $canonical_id !== absint( $map_row['wp_post_id'] ?? 0 )
			|| 'variation' !== sanitize_key( (string) ( $map_row['object_type'] ?? '' ) )
			|| $product_guid !== sanitize_text_field( (string) ( $map_row['parent_remote_guid'] ?? '' ) )
			|| ! empty( $map_row['sync_incomplete'] ) ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical member map row is incomplete or parent identity differs' );
		}
		/* MOBO-4426 stale-map proof: canonical variation durability must agree with Product Map. */
		$canonical_variation_incomplete = sanitize_text_field( (string) get_post_meta( $canonical_id, 'mobo_sync_incomplete', true ) );
		if ( '1' === $canonical_variation_incomplete ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical variation is marked sync incomplete' );
		}

		$variation_source_hash = sanitize_text_field( (string) get_post_meta( $canonical_id, '_mobo_variant_source_hash', true ) );
		$map_last_hash          = sanitize_text_field( (string) ( $map_row['last_hash'] ?? '' ) );
		if ( '' === $variation_source_hash || '' === $map_last_hash || $variation_source_hash !== $map_last_hash ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical variation source hash disagrees with Product Map' );
		}

		$signature = $this->variation_signature_from_post( $canonical_id );
		if ( '' === $signature ) {
			return array( 'safe' => false, 'ids' => $ids, 'reason' => 'canonical signature empty' );
		}

		$stale_ids = array();
		foreach ( $ids as $id ) {
			$identity = Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $id );
			if ( is_wp_error( $identity )
				|| empty( $identity['owned'] )
				|| $variant_guid !== sanitize_text_field( (string) ( $identity['variantGuid'] ?? '' ) ) ) {
				return array( 'safe' => false, 'identityConflict' => true, 'ids' => $ids, 'reason' => 'member identity aliases conflict or ownership ambiguous' );
			}
			$portal_id = absint( $identity['portalId'] ?? 0 );
			if ( $portal_id > 0 && $portal_id !== $canonical_portal_id ) {
				return array( 'safe' => false, 'identityConflict' => true, 'ids' => $ids, 'reason' => 'member claims different Portal variant ID' );
			}

			$parent_id = absint( wp_get_post_parent_id( $id ) );
			$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : false;
			if ( $parent_id <= 0
				|| ! $parent instanceof WC_Product
				|| ! $parent->is_type( 'variable' )
				|| $product_guid !== sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) ) ) {
				return array( 'safe' => false, 'ids' => $ids, 'reason' => 'member parent does not represent same Variable product GUID' );
			}
			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) {
				return array( 'safe' => false, 'ids' => $ids, 'reason' => 'member parent excluded' );
			}
			if ( $signature !== $this->variation_signature_from_post( $id ) ) {
				return array( 'safe' => false, 'ids' => $ids, 'reason' => 'member signature differs' );
			}

			if ( $id !== $canonical_id ) {
				$member_rows = $this->product_map->snapshot_variation_rows_by_post_id( $id );
				if ( is_wp_error( $member_rows ) || ! empty( $member_rows ) ) {
					return array( 'safe' => false, 'ids' => $ids, 'reason' => 'non-canonical member still owns variation map row' );
				}
				$stale_ids[] = absint( $id );
			}
		}

		if ( empty( $stale_ids ) ) {
			return array( 'safe' => false, 'resolved' => true, 'ids' => $ids, 'reason' => 'no stale member remains' );
		}

		return array(
			'safe' => true,
			'variantGuid' => $variant_guid,
			'productGuid' => $product_guid,
			'canonicalId' => $canonical_id,
			'canonicalParentId' => $canonical_parent_id,
			'canonicalPortalId' => $canonical_portal_id,
			'signature' => $signature,
			'ids' => $ids,
			'staleIds' => array_values( $stale_ids ),
		);
	}

	private function quarantine_map_backed_duplicate_variation( $variation_id, $canonical_id, $sync_id, $proof ) {
		$variation_id = absint( $variation_id );
		$canonical_id = absint( $canonical_id );
		$sync_id      = sanitize_text_field( (string) $sync_id );

		if ( $variation_id <= 0 || $canonical_id <= 0 || $variation_id === $canonical_id || '' === $sync_id || ! is_array( $proof ) || empty( $proof['safe'] ) ) {
			return new WP_Error( 'mobo_core_map_duplicate_proof_invalid', 'Map-backed duplicate proof is invalid.' );
		}

		$variant_guid = sanitize_text_field( (string) ( $proof['variantGuid'] ?? '' ) );
		$product_guid = sanitize_text_field( (string) ( $proof['productGuid'] ?? '' ) );
		if ( '' === $variant_guid || '' === $product_guid
			|| $variant_guid !== sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) )
			|| $variant_guid !== sanitize_text_field( (string) get_post_meta( $canonical_id, 'variant_guid', true ) )
			|| $product_guid !== sanitize_text_field( (string) get_post_meta( absint( wp_get_post_parent_id( $variation_id ) ), 'product_guid', true ) )
			|| $product_guid !== sanitize_text_field( (string) get_post_meta( absint( wp_get_post_parent_id( $canonical_id ) ), 'product_guid', true ) )
			|| $this->variation_signature_from_post( $variation_id ) !== $this->variation_signature_from_post( $canonical_id ) ) {
			return new WP_Error( 'mobo_core_map_duplicate_recheck_failed', 'Map-backed duplicate changed before quarantine.' );
		}

		$result = Mobo_Core_Variation_Lifecycle_Policy::quarantine(
			$variation_id,
			'repair-duplicate-variant-guid-map-backed-canonical',
			array(
				'canonicalId' => $canonical_id,
				'syncId' => $sync_id,
				'variantGuid' => $variant_guid,
				'productGuid' => $product_guid,
				'canonicalParentId' => absint( $proof['canonicalParentId'] ?? 0 ),
				'staleParentId' => absint( wp_get_post_parent_id( $variation_id ) ),
				'repairPostSyncReason' => 'duplicate-variant-guid-map-backed-canonical',
			),
			$this->product_map
		);
		return is_wp_error( $result ) ? $result : true;
	}

	private function process_map_backed_duplicate_group( &$state, $sync_id, $variant_guid ) {
		$state['postSyncIntegrityStats']['mapBackedDuplicateGroupsScanned']++;

		$proof = $this->prove_map_backed_duplicate_group( $variant_guid );
		if ( empty( $proof['safe'] ) ) {
			if ( ! empty( $proof['resolved'] ) ) {
				return array( 'success' => true, 'done' => false );
			}
			$key = ! empty( $proof['identityConflict'] ) ? 'mapBackedDuplicateIdentityConflictsPreserved' : 'mapBackedDuplicateProofSkipped';
			$state['postSyncIntegrityStats'][ $key ]++;
			$this->append_sample(
				$state['postSyncIntegrityStats']['samples'],
				array(
					'variantGuid' => sanitize_text_field( (string) $variant_guid ),
					'ids' => array_values( array_map( 'absint', (array) ( $proof['ids'] ?? array() ) ) ),
					'reason' => sanitize_text_field( (string) ( $proof['reason'] ?? 'map-backed duplicate proof failed' ) ),
				)
			);
			return array( 'success' => true, 'done' => false );
		}

		$product_guid = sanitize_text_field( (string) $proof['productGuid'] );
		$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 180 );
		if ( false === $product_lock ) {
			$state['postSyncIntegrityStats']['productLockBusySkipped']++;
			return array( 'success' => true, 'done' => false, 'retry' => true, 'message' => 'Map-backed duplicate cleanup is waiting for the normal product lock.' );
		}

		try {
			$proof = $this->prove_map_backed_duplicate_group( $variant_guid );
			if ( empty( $proof['safe'] ) ) {
				if ( ! empty( $proof['resolved'] ) ) {
					return array( 'success' => true, 'done' => false );
				}
				$key = ! empty( $proof['identityConflict'] ) ? 'mapBackedDuplicateIdentityConflictsPreserved' : 'mapBackedDuplicateProofSkipped';
				$state['postSyncIntegrityStats'][ $key ]++;
				$this->append_sample(
					$state['postSyncIntegrityStats']['samples'],
					array(
						'variantGuid' => sanitize_text_field( (string) $variant_guid ),
						'ids' => array_values( array_map( 'absint', (array) ( $proof['ids'] ?? array() ) ) ),
						'reason' => 'proof changed under product lock: ' . sanitize_text_field( (string) ( $proof['reason'] ?? 'unknown' ) ),
					)
				);
				return array( 'success' => true, 'done' => false );
			}

			$canonical_id = absint( $proof['canonicalId'] );
			$quarantined  = 0;
			foreach ( (array) $proof['staleIds'] as $stale_id ) {
				$r = $this->quarantine_map_backed_duplicate_variation( $stale_id, $canonical_id, $sync_id, $proof );
				if ( is_wp_error( $r ) ) {
					$state['postSyncIntegrityStats']['errors']++;
					return $r;
				}
				if ( true === $r ) {
					$quarantined++;
					$state['postSyncIntegrityStats']['mapBackedDuplicateVariationsQuarantined']++;
					$state['postSyncIntegrityStats']['duplicateVariationsQuarantined']++;
				}
			}

			if ( $quarantined !== count( (array) $proof['staleIds'] ) ) {
				$state['postSyncIntegrityStats']['errors']++;
				return new WP_Error( 'mobo_core_map_duplicate_quarantine_incomplete', 'Map-backed duplicate group was partially quarantined; rerun Repair.' );
			}

			$state['postSyncIntegrityStats']['mapBackedDuplicateGroupsRepaired']++;
			foreach ( array_unique( array_map( 'absint', array_merge( array( $proof['canonicalParentId'] ), array_map( 'wp_get_post_parent_id', (array) $proof['ids'] ) ) ) ) as $parent_id ) {
				if ( $parent_id > 0 ) {
					$this->refresh_product_caches( $parent_id );
				}
			}
			return array( 'success' => true, 'done' => false );
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
		}
	}

	private function run_post_sync_map_backed_duplicate_slice( &$state, $sync_id ) {
		$cursor = absint( $state['postSyncIntegrityCursor'] ?? 0 );
		$groups = $this->find_map_backed_duplicate_variant_groups( $cursor, 20 );

		if ( empty( $groups ) ) {
			$state['postSyncIntegrityPhase'] = 'price-meta';
			$state['postSyncIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => 'Map-backed duplicate variant GUID cleanup complete; price metadata cleanup starts.' );
		}

		foreach ( $groups as $group ) {
			$result = $this->process_map_backed_duplicate_group(
				$state,
				$sync_id,
				sanitize_text_field( (string) ( $group['variantGuid'] ?? '' ) )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['retry'] ) ) {
				return $result;
			}
			$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), absint( $group['cursorId'] ?? 0 ) );
		}

		return array(
			'success' => true,
			'done' => false,
			'message' => sprintf(
				'Map-backed duplicate GUIDs: %d groups scanned, %d repaired, %d stale variations quarantined; %d proof failures and %d identity conflicts preserved.',
				absint( $state['postSyncIntegrityStats']['mapBackedDuplicateGroupsScanned'] ),
				absint( $state['postSyncIntegrityStats']['mapBackedDuplicateGroupsRepaired'] ),
				absint( $state['postSyncIntegrityStats']['mapBackedDuplicateVariationsQuarantined'] ),
				absint( $state['postSyncIntegrityStats']['mapBackedDuplicateProofSkipped'] ),
				absint( $state['postSyncIntegrityStats']['mapBackedDuplicateIdentityConflictsPreserved'] )
			),
		);
	}


	private function run_post_sync_price_meta_slice( &$state ) {
		$cursor = absint( $state['postSyncIntegrityCursor'] ?? 0 );
		$ids = $this->find_duplicate_price_meta_ids( $cursor, 50 );
		/* MOBO-4456: DB read failure is retryable evidence, never phase completion. */
		if ( is_wp_error( $ids ) ) {
			$state['postSyncIntegrityStats']['errors'] = absint( $state['postSyncIntegrityStats']['errors'] ?? 0 ) + 1;
			return $ids;
		}
		if ( empty( $ids ) ) {
			$state['postSyncIntegrityPhase'] = 'done';
			$state['postSyncIntegrityCursor'] = 0;
			$state['postSyncIntegrityComplete'] = true;
			return array( 'success' => true, 'done' => true, 'message' => $this->post_sync_completion_message( $state['postSyncIntegrityStats'] ) );
		}
		foreach ( $ids as $post_id ) {
			$cleanup_result = $this->cleanup_price_meta_object( $post_id, $state['postSyncIntegrityStats'] );
			if ( is_wp_error( $cleanup_result ) ) {
				if ( 'mobo_core_repair_product_lock_busy' === $cleanup_result->get_error_code() ) {
					$state['postSyncIntegrityStats']['productLockBusySkipped'] = absint( $state['postSyncIntegrityStats']['productLockBusySkipped'] ?? 0 ) + 1;
					return array( 'success' => true, 'done' => false, 'retry' => true, 'message' => sprintf( 'Post-sync price metadata cleanup is waiting for the shared product lock for object %d.', $post_id ) );
				}
				$state['postSyncIntegrityStats']['errors'] = absint( $state['postSyncIntegrityStats']['errors'] ?? 0 ) + 1;
			}
			$state['postSyncIntegrityCursor'] = max( absint( $state['postSyncIntegrityCursor'] ), $post_id );
		}
		return array(
			'success' => true,
			'done' => false,
			'message' => sprintf(
				'پاکسازی نهایی قیمت: %d object بررسی، %d اصلاح، %d meta اضافی حذف شد.',
				absint( $state['postSyncIntegrityStats']['priceMetaObjectsScanned'] ),
				absint( $state['postSyncIntegrityStats']['priceMetaObjectsRepaired'] ),
				absint( $state['postSyncIntegrityStats']['priceMetaRowsRemoved'] )
			),
		);
	}

	private function post_sync_completion_message( $stats ) {
		return sprintf(
			'Repair نهایی کامل شد: %d Variation نسل جاری و %d Variation legacy زیر parent ساده، %d duplicate ایمن (%d map-backed) و %d meta قیمت اضافی قرنطینه/حذف شد؛ %d گروه مبهم، %d legacy proof نامطمئن، %d duplicate proof نامطمئن و %d parent با ordering جدیدتر بدون mutation باقی ماند.',
			absint( $stats['simpleParentVariationsQuarantined'] ?? 0 ),
			absint( $stats['legacySimpleParentVariationsQuarantined'] ?? 0 ),
			absint( $stats['duplicateVariationsQuarantined'] ?? 0 ),
			absint( $stats['mapBackedDuplicateVariationsQuarantined'] ?? 0 ),
			absint( $stats['priceMetaRowsRemoved'] ?? 0 ),
			absint( $stats['ambiguousDuplicateGroups'] ?? 0 ),
			absint( $stats['legacyCanonicalProofSkipped'] ?? 0 ),
			absint( $stats['mapBackedDuplicateProofSkipped'] ?? 0 ),
			absint( $stats['staleFenceSkipped'] ?? 0 )
		);
	}


	/**
	 * Verify that a current-Repair completion marker still describes the current
	 * product ordering state. Marker metadata must exist even when its numeric
	 * value is zero; otherwise an old pre-fence marker could be mistaken for
	 * valid evidence.
	 *
	 * @param int    $parent_id Parent product ID.
	 * @param string $sync_id Current Repair sync ID.
	 * @param string $marker_key Repair sync marker meta key.
	 * @return bool
	 */
	private function repair_marker_is_current( $parent_id, $sync_id, $marker_key ) {
		$parent_id  = absint( $parent_id );
		$sync_id    = sanitize_text_field( (string) $sync_id );
		$marker_key = sanitize_key( (string) $marker_key );
		if ( $parent_id <= 0 || '' === $sync_id || '' === $marker_key ) {
			return false;
		}
		if ( $sync_id !== sanitize_text_field( (string) get_post_meta( $parent_id, $marker_key, true ) )
			|| '0' !== (string) get_post_meta( $parent_id, 'mobo_sync_incomplete', true ) ) {
			return false;
		}

		$revision_marker_key = class_exists( 'Mobo_Core_Product_Sync' ) ? Mobo_Core_Product_Sync::REPAIR_APPLIED_REVISION_META : '_mobo_last_repair_applied_revision';
		$webhook_marker_key  = class_exists( 'Mobo_Core_Product_Sync' ) ? Mobo_Core_Product_Sync::REPAIR_WEBHOOK_US_META : '_mobo_last_repair_webhook_us';
		if ( ! metadata_exists( 'post', $parent_id, $revision_marker_key ) || ! metadata_exists( 'post', $parent_id, $webhook_marker_key ) ) {
			return false;
		}

		$marked_revision = absint( get_post_meta( $parent_id, $revision_marker_key, true ) );
		$current_revision = absint( get_post_meta( $parent_id, '_mobo_product_applied_revision', true ) );
		$marked_webhook_us = absint( get_post_meta( $parent_id, $webhook_marker_key, true ) );
		$current_webhook_us = absint( get_post_meta( $parent_id, '_mobo_last_webhook_applied_us', true ) );

		return $marked_revision === $current_revision && $marked_webhook_us === $current_webhook_us;
	}


	private function has_durable_variation_identity( $variation_id ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 || ! class_exists( 'Mobo_Core_Product_Identity_Policy' ) ) {
			return false;
		}
		$numeric = Mobo_Core_Product_Identity_Policy::numeric_identity_meta_keys();
		foreach ( Mobo_Core_Product_Identity_Policy::variation_identity_meta_keys() as $key ) {
			$value = get_post_meta( $variation_id, $key, true );
			if ( in_array( $key, $numeric, true ) ? absint( $value ) > 0 : ( is_scalar( $value ) && '' !== trim( (string) $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function quarantine_post_sync_variation( $variation_id, $canonical_id, $sync_id, $reason ) {
		$variation_id = absint( $variation_id );
		$canonical_id = absint( $canonical_id );
		$sync_id      = sanitize_text_field( (string) $sync_id );
		$reason       = sanitize_key( (string) $reason );
		if ( $variation_id <= 0 || '' === $sync_id || 'product_variation' !== get_post_type( $variation_id ) || 'trash' === get_post_status( $variation_id ) ) {
			return false;
		}
		$parent_id = absint( wp_get_post_parent_id( $variation_id ) );
		if ( $parent_id <= 0 || ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $parent_id ) ) ) {
			return false;
		}
		$identity = class_exists( 'Mobo_Core_Variation_Lifecycle_Policy' ) ? Mobo_Core_Variation_Lifecycle_Policy::inspect_identity( $variation_id ) : new WP_Error( 'mobo_core_variation_lifecycle_missing', 'Variation lifecycle policy is unavailable.' );
		if ( is_wp_error( $identity ) || empty( $identity['owned'] ) ) {
			return false;
		}
		if ( $canonical_id > 0 ) {
			if ( $canonical_id === $variation_id || absint( wp_get_post_parent_id( $canonical_id ) ) !== $parent_id || 'trash' === get_post_status( $canonical_id ) ) {
				return false;
			}
			$signature = $this->variation_signature_from_post( $variation_id );
			if ( '' === $signature || $signature !== $this->variation_signature_from_post( $canonical_id ) ) {
				return false;
			}
		}
		$result = Mobo_Core_Variation_Lifecycle_Policy::quarantine(
			$variation_id,
			'repair-' . $reason,
			array( 'canonicalId' => $canonical_id, 'syncId' => $sync_id, 'parentId' => $parent_id, 'repairPostSyncReason' => $reason ),
			$this->product_map
		);
		return is_wp_error( $result ) ? $result : true;
	}


	private function run_price_meta_slice( &$state ) {
		$cursor = absint( isset( $state['repairIntegrityCursor'] ) ? $state['repairIntegrityCursor'] : 0 );
		$ids = $this->find_duplicate_price_meta_ids( $cursor, 50 );
		/* MOBO-4456: DB read failure is retryable evidence, never phase completion. */
		if ( is_wp_error( $ids ) ) {
			$state['repairIntegrityStats']['errors'] = absint( $state['repairIntegrityStats']['errors'] ?? 0 ) + 1;
			return $ids;
		}
		if ( empty( $ids ) ) {
			$state['repairIntegrityPhase']  = self::PHASE_SHIPPING;
			$state['repairIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => 'پاکسازی meta قیمت کامل شد؛ نگاشت‌های قدیمی ارسال بررسی می‌شوند.' );
		}
		foreach ( $ids as $post_id ) {
			$cleanup_result = $this->cleanup_price_meta_object( $post_id, $state['repairIntegrityStats'] );
			if ( is_wp_error( $cleanup_result ) ) {
				if ( 'mobo_core_repair_product_lock_busy' === $cleanup_result->get_error_code() ) {
					$state['repairIntegrityStats']['productLockBusySkipped'] = absint( $state['repairIntegrityStats']['productLockBusySkipped'] ?? 0 ) + 1;
					return array( 'success' => true, 'done' => false, 'retry' => true, 'message' => sprintf( 'Price metadata cleanup is waiting for the shared product lock for object %d.', $post_id ) );
				}
				$state['repairIntegrityStats']['errors'] = absint( $state['repairIntegrityStats']['errors'] ?? 0 ) + 1;
			}
			$state['repairIntegrityCursor'] = max( absint( $state['repairIntegrityCursor'] ), $post_id );
		}
		return array(
			'success' => true,
			'done' => false,
			'message' => sprintf(
				'پاکسازی قیمت: %d محصول/تنوع بررسی، %d اصلاح، %d meta اضافی حذف شد.',
				absint( $state['repairIntegrityStats']['priceMetaObjectsScanned'] ),
				absint( $state['repairIntegrityStats']['priceMetaObjectsRepaired'] ),
				absint( $state['repairIntegrityStats']['priceMetaRowsRemoved'] )
			),
		);
	}

	private function find_duplicate_price_meta_ids( $cursor, $limit ) {
		global $wpdb;
		$cursor = absint( $cursor );
		$limit  = max( 1, min( 200, absint( $limit ) ) );
		$identity_keys = class_exists( 'Mobo_Core_Product_Identity_Policy' ) ? Mobo_Core_Product_Identity_Policy::identity_meta_keys() : array( 'product_guid', 'variant_guid', 'portal_product_id', 'mobo_portal_product_id', '_mobo_portal_product_id', 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
		$identity_keys = array_values( array_filter( array_map( 'sanitize_key', $identity_keys ) ) );
		if ( empty( $identity_keys ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $identity_keys ), '%s' ) );
		$sql = "SELECT pm.post_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.post_id > %d
			AND p.post_type IN ('product','product_variation')
			AND p.post_status NOT IN ('trash','auto-draft')
			AND pm.meta_key IN ('_price','_regular_price','_sale_price')
			AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} own
				WHERE own.post_id = pm.post_id
				AND own.meta_key IN ({$placeholders})
				AND own.meta_value <> ''
			)
			GROUP BY pm.post_id
			HAVING COUNT(*) > COUNT(DISTINCT pm.meta_key)
			ORDER BY pm.post_id ASC
			LIMIT %d";
		$args = array_merge( array( $cursor ), $identity_keys, array( $limit ) );
		$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
		$ids = $wpdb->get_col( $prepared );
		if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $ids ) ) {
			return new WP_Error(
				'mobo_core_repair_price_meta_read_failed',
				'Unable to read duplicate WooCommerce price metadata from the database; Repair phase and cursor were preserved for retry.'
			);
		}
		return array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
	}

	private function cleanup_price_meta_object( $post_id, &$stats ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}

		/* MOBO-4434 price-meta product lock: rewrite duplicate WooCommerce price metadata only while holding the shared parent product lock. */
		$post_type = get_post_type( $post_id );
		$parent_id = 'product_variation' === $post_type ? absint( wp_get_post_parent_id( $post_id ) ) : $post_id;
		if ( $parent_id <= 0 || ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return false;
		}
		$product_guid = sanitize_text_field( (string) get_post_meta( $parent_id, 'product_guid', true ) );
		if ( '' === $product_guid ) {
			return new WP_Error( 'mobo_core_repair_price_meta_lock_identity_missing', 'Price metadata cleanup requires the durable parent product GUID before mutation.' );
		}
		if ( ! class_exists( 'Mobo_Core_Product_Concurrency' ) ) {
			return new WP_Error( 'mobo_core_repair_product_lock_unavailable', 'Shared product locking is unavailable for price metadata cleanup.' );
		}

		$product_lock = Mobo_Core_Product_Concurrency::acquire_product_lock( $product_guid, 0, 180 );
		if ( false === $product_lock || is_wp_error( $product_lock ) ) {
			return new WP_Error( 'mobo_core_repair_product_lock_busy', 'Another Mobo product or variation operation currently owns this product lock; retry Repair after it completes.' );
		}

		try {
			/* Revalidate under the same lock used by normal Product/Variation writers.
			 * This closes the stale WC_Product snapshot race before duplicate meta rows
			 * are collapsed back to one canonical value. */
			$locked_type = get_post_type( $post_id );
			$locked_parent_id = 'product_variation' === $locked_type ? absint( wp_get_post_parent_id( $post_id ) ) : $post_id;
			$locked_guid = $locked_parent_id > 0 ? sanitize_text_field( (string) get_post_meta( $locked_parent_id, 'product_guid', true ) ) : '';
			if ( $locked_parent_id !== $parent_id || ! in_array( $locked_type, array( 'product', 'product_variation' ), true ) || 'trash' === get_post_status( $post_id ) || 'auto-draft' === get_post_status( $post_id ) || 0 !== strcasecmp( $locked_guid, $product_guid ) ) {
				return new WP_Error( 'mobo_core_repair_price_meta_identity_changed', 'Price metadata object identity changed while Repair was waiting for the shared product lock.' );
			}

			$stats['priceMetaObjectsScanned'] = absint( $stats['priceMetaObjectsScanned'] ?? 0 ) + 1;
			if ( class_exists( 'Mobo_Core_Product_Identity_Policy' ) && ! Mobo_Core_Product_Identity_Policy::is_mobo_object_id( $post_id ) ) {
				return false;
			}
			if ( class_exists( 'Mobo_Core_Product_Exclusions' ) && Mobo_Core_Product_Exclusions::is_local_product_excluded( $post_id ) ) {
				$stats['priceMetaObjectsExcluded'] = absint( $stats['priceMetaObjectsExcluded'] ?? 0 ) + 1;
				return false;
			}

			$product = wc_get_product( $post_id );
			if ( ! $product instanceof WC_Product ) {
				return false;
			}
			$desired = array(
				'_price'         => (string) $product->get_price( 'edit' ),
				'_regular_price' => (string) $product->get_regular_price( 'edit' ),
				'_sale_price'    => (string) $product->get_sale_price( 'edit' ),
			);
			$changed = false;
			foreach ( $desired as $key => $value ) {
				$values = get_post_meta( $post_id, $key, false );
				if ( ! is_array( $values ) || count( $values ) <= 1 ) {
					continue;
				}
				$before = count( $values );
				if ( ! $this->replace_meta_single( $post_id, $key, $value ) ) {
					$stats['errors'] = absint( $stats['errors'] ?? 0 ) + 1;
					continue;
				}
				$stats['priceMetaRowsRemoved'] = absint( $stats['priceMetaRowsRemoved'] ?? 0 ) + max( 0, $before - 1 );
				$changed = true;
			}
			if ( $changed ) {
				$stats['priceMetaObjectsRepaired'] = absint( $stats['priceMetaObjectsRepaired'] ?? 0 ) + 1;
				$this->refresh_product_caches( $parent_id );
			}
			return $changed;
		} finally {
			Mobo_Core_Product_Concurrency::release_product_lock( $product_lock );
		}
	}

	private function run_shipping_cleanup_slice( &$state ) {
		global $wpdb;

		$existing_instances = $wpdb->get_col( "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods" );
		$existing = array_fill_keys( array_map( 'absint', is_array( $existing_instances ) ? $existing_instances : array() ), true );
		$options = $wpdb->get_results(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE 'mobo_core_wc_shipping_method_map_zone_%'
			OR option_name LIKE 'mobo_core_wc_shipping_method_map_mobo_only_zone_%'
			OR option_name LIKE 'mobo_core_wc_shipping_method_map_mixed_zone_%'",
			ARRAY_A
		);
		foreach ( is_array( $options ) ? $options : array() as $row ) {
			$name = isset( $row['option_name'] ) ? sanitize_key( (string) $row['option_name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			if ( ! preg_match( '/_zone_\d+_[a-z0-9_-]+_(\d+)(?:_posted)?$/', $name, $match ) ) {
				continue;
			}
			$instance_id = absint( $match[1] );
			if ( $instance_id > 0 && ! isset( $existing[ $instance_id ] ) && delete_option( $name ) ) {
				$state['repairIntegrityStats']['staleShippingMappingsRemoved']++;
			}
		}

		$state['repairIntegrityStats']['staleShippingMappingsRemoved'] += $this->prune_stale_shipping_tracking_options( $existing );

		if ( class_exists( 'Mobo_Core_Automatic_Shipping' ) ) {
			$legacy = ( new Mobo_Core_Automatic_Shipping() )->retire_legacy_runtime( 'repair-integrity' );
			if ( is_array( $legacy ) ) {
				$state['repairIntegrityStats']['legacyShippingMethodsDisabled'] += absint( isset( $legacy['disabledMoboMethods'] ) ? $legacy['disabledMoboMethods'] : 0 );
				$state['repairIntegrityStats']['legacyShippingFallbacksDisabled'] += absint( isset( $legacy['disabledFallbackMethods'] ) ? $legacy['disabledFallbackMethods'] : 0 );
				$state['repairIntegrityStats']['legacyShippingMirrorsDisabled'] += absint( isset( $legacy['disabledMirrorMethods'] ) ? $legacy['disabledMirrorMethods'] : 0 );
				$state['repairIntegrityStats']['legacyShippingZonesRemoved'] += absint( isset( $legacy['deletedLegacyZones'] ) ? $legacy['deletedLegacyZones'] : 0 );
				if ( empty( $legacy['success'] ) ) {
					$state['repairIntegrityStats']['errors']++;
				}
			}
		}

		$state['repairIntegrityPhase']    = self::PHASE_DONE;
		$state['repairIntegrityComplete'] = true;
		$state['repairIntegrityCursor']   = 0;
		$result = array_merge(
			$state['repairIntegrityStats'],
			array( 'completedAt' => time(), 'version' => defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '' )
		);
		update_option( self::LAST_RESULT_OPTION, $result, false );

		return array( 'success' => true, 'done' => true, 'message' => $this->completion_message( $state['repairIntegrityStats'] ) );
	}


	/**
	 * Remove stale IDs only from plugin-owned legacy tracking options.
	 * Merchant WooCommerce shipping settings are never edited here.
	 *
	 * @param array $existing Existing Woo shipping instance IDs as keys.
	 * @return int Removed stale references.
	 */
	private function prune_stale_shipping_tracking_options( $existing ) {
		if ( ! class_exists( 'Mobo_Core_Automatic_Shipping' ) ) {
			return 0;
		}
		$existing = is_array( $existing ) ? $existing : array();
		$removed  = 0;

		$managed = get_option( Mobo_Core_Automatic_Shipping::OPTION_MANAGED_RATES, array() );
		if ( is_array( $managed ) ) {
			$kept = array();
			foreach ( $managed as $entry ) {
				$instance_id = is_array( $entry ) ? absint( isset( $entry['instanceId'] ) ? $entry['instanceId'] : 0 ) : 0;
				if ( $instance_id > 0 && ! isset( $existing[ $instance_id ] ) ) {
					$removed++;
					continue;
				}
				$kept[] = $entry;
			}
			if ( count( $kept ) !== count( $managed ) ) {
				update_option( Mobo_Core_Automatic_Shipping::OPTION_MANAGED_RATES, array_values( $kept ), false );
			}
		}

		$fallbacks = get_option( Mobo_Core_Automatic_Shipping::OPTION_STORE_FALLBACKS, array() );
		if ( is_array( $fallbacks ) ) {
			$kept = array();
			foreach ( $fallbacks as $instance_id ) {
				$id = absint( $instance_id );
				if ( $id > 0 && ! isset( $existing[ $id ] ) ) {
					$removed++;
					continue;
				}
				$kept[] = $instance_id;
			}
			if ( count( $kept ) !== count( $fallbacks ) ) {
				update_option( Mobo_Core_Automatic_Shipping::OPTION_STORE_FALLBACKS, array_values( $kept ), false );
			}
		}

		$mirrors = get_option( Mobo_Core_Automatic_Shipping::OPTION_STORE_EXISTING_MIRRORS, array() );
		if ( is_array( $mirrors ) ) {
			$changed = false;
			foreach ( $mirrors as $zone_key => $zone_map ) {
				if ( ! is_array( $zone_map ) ) {
					continue;
				}
				foreach ( $zone_map as $source_key => $instance_id ) {
					$id = absint( $instance_id );
					if ( $id > 0 && ! isset( $existing[ $id ] ) ) {
						unset( $mirrors[ $zone_key ][ $source_key ] );
						$removed++;
						$changed = true;
					}
				}
				if ( empty( $mirrors[ $zone_key ] ) ) {
					unset( $mirrors[ $zone_key ] );
				}
			}
			if ( $changed ) {
				update_option( Mobo_Core_Automatic_Shipping::OPTION_STORE_EXISTING_MIRRORS, $mirrors, false );
			}
		}

		return $removed;
	}

	private function completion_message( $stats ) {
		return sprintf(
			'Repair داده‌های قدیمی کامل شد: %d تنوع اضافی قرنطینه، %d محصول Trash با تأیید Portal بازیابی، %d مورد مبهم بدون حذف، %d محصول/تنوع قیمت اصلاح، %d نگاشت ارسال منقضی حذف، %d خطای محافظه‌کارانه.',
			absint( isset( $stats['portalVariantDuplicatesQuarantined'] ) ? $stats['portalVariantDuplicatesQuarantined'] : 0 ),
			absint( isset( $stats['trashedProductsRestored'] ) ? $stats['trashedProductsRestored'] : 0 ),
			absint( isset( $stats['portalVariantAmbiguousGroups'] ) ? $stats['portalVariantAmbiguousGroups'] : 0 ) + absint( isset( $stats['variationSignatureDuplicateGroups'] ) ? $stats['variationSignatureDuplicateGroups'] : 0 ) + absint( isset( $stats['trashedProductsAmbiguous'] ) ? $stats['trashedProductsAmbiguous'] : 0 ),
			absint( isset( $stats['priceMetaObjectsRepaired'] ) ? $stats['priceMetaObjectsRepaired'] : 0 ),
			absint( isset( $stats['staleShippingMappingsRemoved'] ) ? $stats['staleShippingMappingsRemoved'] : 0 ),
			absint( isset( $stats['errors'] ) ? $stats['errors'] : 0 )
		);
	}

	private function variation_signature_from_post( $variation_id ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id <= 0 ) {
			return '';
		}
		$meta  = get_post_meta( $variation_id );
		$attrs = array();
		foreach ( is_array( $meta ) ? $meta : array() as $key => $values ) {
			if ( 0 !== strpos( (string) $key, 'attribute_' ) ) {
				continue;
			}
			$value = is_array( $values ) && isset( $values[0] ) ? $values[0] : '';
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$attrs[ (string) $key ] = (string) $value;
			}
		}
		return $this->variation_signature( $attrs );
	}

	private function variation_signature( $attributes ) {
	return Mobo_Core_Variation_Identity_Policy::attribute_signature( $attributes );
}

	private function replace_meta_single( $post_id, $key, $value ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$key     = sanitize_key( (string) $key );
		if ( $post_id <= 0 || '' === $key ) {
			return false;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC",
				$post_id,
				$key
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$expected = class_exists( 'Mobo_Core_Durable_State_Policy' ) ? Mobo_Core_Durable_State_Policy::canonical_meta_value( $value ) : $value;
		if ( empty( $rows ) ) {
			if ( false === add_post_meta( $post_id, $key, $expected, true ) ) {
				return false;
			}
		} else {
			$canonical_meta_id = absint( $rows[0]['meta_id'] ?? 0 );
			if ( $canonical_meta_id <= 0 ) {
				return false;
			}
			$updated = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => maybe_serialize( $expected ) ),
				array( 'meta_id' => $canonical_meta_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return false;
			}
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s AND meta_id<>%d",
					$post_id,
					$key,
					$canonical_meta_id
				)
			);
			if ( false === $deleted ) {
				return false;
			}
		}
		wp_cache_delete( $post_id, 'post_meta' );
		$stored = get_post_meta( $post_id, $key, false );
		if ( ! is_array( $stored ) || 1 !== count( $stored ) ) {
			return false;
		}
		$actual = class_exists( 'Mobo_Core_Durable_State_Policy' ) ? Mobo_Core_Durable_State_Policy::canonical_meta_value( $stored[0] ) : $stored[0];
		return maybe_serialize( $actual ) === maybe_serialize( $expected );
	}

	private function refresh_product_caches( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}
		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}


	private function payload_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}
		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}
		$pascal = ucfirst( (string) $key );
		return array_key_exists( $pascal, $array ) ? $array[ $pascal ] : $default;
	}

	private function append_sample( &$samples, $sample ) {
		if ( ! is_array( $samples ) ) {
			$samples = array();
		}
		if ( count( $samples ) < 25 ) {
			$samples[] = $sample;
		}
	}
}
