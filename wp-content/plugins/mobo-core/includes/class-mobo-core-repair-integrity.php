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
			'trashedProductsScanned' => 0,
			'trashedProductsRestored' => 0,
			'trashedProductsRemoteMissing' => 0,
			'trashedProductsAmbiguous' => 0,
			'variationSignatureDuplicateGroups' => 0,
			'variationSignatureDuplicateRows' => 0,
			'priceMetaObjectsScanned' => 0,
			'priceMetaObjectsRepaired' => 0,
			'priceMetaRowsRemoved' => 0,
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
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST(pm.meta_value AS UNSIGNED) AS portal_variant_id, COUNT(DISTINCT p.ID) AS object_count
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_mobo_portal_variant_id'
				AND pm.meta_value REGEXP '^[0-9]+$'
				AND CAST(pm.meta_value AS UNSIGNED) > %d
				AND p.post_type = 'product_variation'
				AND p.post_status IN ('publish','private','draft','pending')
				GROUP BY CAST(pm.meta_value AS UNSIGNED)
				HAVING COUNT(DISTINCT p.ID) > 1
				ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC
				LIMIT %d",
				$cursor,
				$limit
			),
			ARRAY_A
		);

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
			$state['repairIntegrityCursor'] = max( absint( $state['repairIntegrityCursor'] ), $portal_variant_id );
			$state['repairIntegrityStats']['portalVariantGroupsScanned']++;

			$result = $this->repair_portal_variant_group( $portal_variant_id );
			if ( is_wp_error( $result ) ) {
				$state['repairIntegrityStats']['errors']++;
				$this->append_sample( $state['repairIntegrityStats']['ambiguousSamples'], array( 'portalVariantId' => $portal_variant_id, 'reason' => $result->get_error_message() ) );
				continue;
			}

			if ( ! empty( $result['repaired'] ) ) {
				$state['repairIntegrityStats']['portalVariantGroupsRepaired']++;
				$state['repairIntegrityStats']['portalVariantDuplicatesQuarantined'] += absint( $result['quarantined'] );
				$this->append_sample( $state['repairIntegrityStats']['repairedSamples'], $result );
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

	private function repair_portal_variant_group( $portal_variant_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'product_variation'
				AND p.post_status IN ('publish','private','draft','pending')
				AND pm.meta_key = '_mobo_portal_variant_id'
				AND pm.meta_value REGEXP '^[0-9]+$'
				AND CAST(pm.meta_value AS UNSIGNED) = %d
				ORDER BY p.ID ASC",
				absint( $portal_variant_id )
			)
		);
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( count( $ids ) <= 1 ) {
			return array( 'repaired' => false, 'ambiguous' => false, 'portalVariantId' => absint( $portal_variant_id ) );
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

		$parent_id    = absint( array_key_first( $parents ) );
		$canonical_id = $this->choose_canonical_variation( $ids );
		if ( $canonical_id <= 0 ) {
			return new WP_Error( 'mobo_core_repair_canonical_variation_missing', 'Could not choose a canonical duplicate variation.' );
		}

		$this->replace_meta_single( $canonical_id, '_mobo_portal_variant_id', (string) absint( $portal_variant_id ) );
		$this->replace_meta_single( $canonical_id, 'mobo_portal_variant_id', (string) absint( $portal_variant_id ) );
		$this->replace_meta_single( $canonical_id, 'portal_variant_id', (string) absint( $portal_variant_id ) );

		$canonical_guid = sanitize_text_field( (string) get_post_meta( $canonical_id, 'variant_guid', true ) );
		$product_guid   = sanitize_text_field( (string) get_post_meta( $canonical_id, 'product_guid', true ) );
		if ( '' !== $canonical_guid && $this->product_map instanceof Mobo_Core_Product_Map ) {
			$this->product_map->upsert_variation( $canonical_guid, $canonical_id, $product_guid, '', false );
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

	private function quarantine_exact_duplicate_variation( $variation_id, $canonical_id, $portal_variant_id, $parent_id ) {
		$variation_id = absint( $variation_id );
		$canonical_id = absint( $canonical_id );
		$parent_id    = absint( $parent_id );
		if ( $variation_id <= 0 || $canonical_id <= 0 || $variation_id === $canonical_id || 'product_variation' !== get_post_type( $variation_id ) ) {
			return false;
		}
		if ( absint( wp_get_post_parent_id( $variation_id ) ) !== $parent_id || absint( get_post_meta( $variation_id, '_mobo_portal_variant_id', true ) ) !== absint( $portal_variant_id ) ) {
			return false;
		}
		/* wp_trash_post() permanently deletes when WordPress Trash retention is disabled. */
		if ( defined( 'EMPTY_TRASH_DAYS' ) && (int) EMPTY_TRASH_DAYS <= 0 ) {
			return false;
		}
		if ( $this->variation_signature_from_post( $variation_id ) !== $this->variation_signature_from_post( $canonical_id ) ) {
			return false;
		}

		$identity_keys = array( 'variant_guid', 'mobo_variant_guid', '_mobo_variant_guid', 'portal_variant_id', 'mobo_portal_variant_id', '_mobo_portal_variant_id' );
		$snapshot = array();
		foreach ( $identity_keys as $key ) {
			$snapshot[ $key ] = get_post_meta( $variation_id, $key, false );
		}

		update_post_meta( $variation_id, '_mobo_repair_duplicate_canonical_id', $canonical_id );
		update_post_meta( $variation_id, '_mobo_repair_duplicate_portal_variant_id', absint( $portal_variant_id ) );
		update_post_meta( $variation_id, '_mobo_repair_duplicate_quarantined_at', gmdate( 'c' ) );
		update_post_meta( $variation_id, '_mobo_repair_previous_identity', wp_json_encode( $snapshot ) );

		/* Strip blocking identities before trashing; restore them if trashing fails. */
		foreach ( $identity_keys as $key ) {
			delete_post_meta( $variation_id, $key );
		}
		if ( $this->product_map instanceof Mobo_Core_Product_Map ) {
			$this->product_map->delete_by_post_id( $variation_id );
		}

		$trashed = wp_trash_post( $variation_id );
		if ( ! $trashed || 'trash' !== get_post_status( $variation_id ) ) {
			foreach ( $snapshot as $key => $values ) {
				delete_post_meta( $variation_id, $key );
				foreach ( is_array( $values ) ? $values : array() as $value ) {
					add_post_meta( $variation_id, $key, maybe_unserialize( $value ), false );
				}
			}
			/* The reverse-map row was removed before Trash to prevent stale identity
			 * ownership. If Trash failed, restore that mapping together with meta. */
			$restore_guid    = sanitize_text_field( (string) get_post_meta( $variation_id, 'variant_guid', true ) );
			$restore_parent  = sanitize_text_field( (string) get_post_meta( $variation_id, 'product_guid', true ) );
			$restore_hash    = sanitize_text_field( (string) get_post_meta( $variation_id, '_mobo_variant_source_hash', true ) );
			$restore_pending = '1' === (string) get_post_meta( $variation_id, 'mobo_sync_incomplete', true );
			if ( '' !== $restore_guid && $this->product_map instanceof Mobo_Core_Product_Map ) {
				$this->product_map->upsert_variation( $restore_guid, $variation_id, $restore_parent, $restore_hash, $restore_pending );
			}
			return false;
		}
		return true;
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
			return array( 'success' => true, 'done' => false, 'message' => sprintf( 'بررسی Trash کامل شد: %d محصول بررسی، %d محصول با تأیید Portal بازیابی شد.', absint( $state['repairIntegrityStats']['trashedProductsScanned'] ), absint( $state['repairIntegrityStats']['trashedProductsRestored'] ) ) );
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
				break;
			}
		}
		if ( ! $confirmed ) {
			$state['repairIntegrityCursor'] = $post_id;
			$state['repairIntegrityStats']['trashedProductsRemoteMissing']++;
			return array( 'success' => true, 'done' => false, 'message' => 'Portal هویت دقیق محصول Trash را تأیید نکرد؛ محصول بدون تغییر باقی ماند.' );
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

	private function run_price_meta_slice( &$state ) {
		global $wpdb;

		$cursor = absint( isset( $state['repairIntegrityCursor'] ) ? $state['repairIntegrityCursor'] : 0 );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.post_id > %d
				AND p.post_type IN ('product','product_variation')
				AND p.post_status NOT IN ('trash','auto-draft')
				AND pm.meta_key IN ('_price','_regular_price','_sale_price')
				AND EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} own
					WHERE own.post_id = pm.post_id
					AND own.meta_key IN ('product_guid','variant_guid','_mobo_portal_product_id','_mobo_portal_variant_id')
					AND own.meta_value <> ''
				)
				GROUP BY pm.post_id
				HAVING COUNT(*) > COUNT(DISTINCT pm.meta_key)
				ORDER BY pm.post_id ASC
				LIMIT 50",
				$cursor
			)
		);
		$ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		if ( empty( $ids ) ) {
			$state['repairIntegrityPhase']  = self::PHASE_SHIPPING;
			$state['repairIntegrityCursor'] = 0;
			return array( 'success' => true, 'done' => false, 'message' => 'پاکسازی meta قیمت کامل شد؛ نگاشت‌های قدیمی ارسال بررسی می‌شوند.' );
		}

		foreach ( $ids as $post_id ) {
			$state['repairIntegrityCursor'] = max( absint( $state['repairIntegrityCursor'] ), $post_id );
			$state['repairIntegrityStats']['priceMetaObjectsScanned']++;
			$product = wc_get_product( $post_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
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
				$state['repairIntegrityStats']['priceMetaRowsRemoved'] += max( 0, count( $values ) - 1 );
				$this->replace_meta_single( $post_id, $key, $value );
				$changed = true;
			}
			if ( $changed ) {
				$state['repairIntegrityStats']['priceMetaObjectsRepaired']++;
				$parent_id = 'product_variation' === get_post_type( $post_id ) ? absint( wp_get_post_parent_id( $post_id ) ) : $post_id;
				$this->refresh_product_caches( $parent_id );
			}
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
		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return '';
		}
		$normalized = array();
		foreach ( $attributes as $key => $value ) {
			$key = preg_replace( '/^attribute_/', '', sanitize_title( (string) $key ) );
			$value = sanitize_title( (string) $value );
			if ( '' !== $key && '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}
		ksort( $normalized );
		return empty( $normalized ) ? '' : md5( wp_json_encode( $normalized ) );
	}

	private function replace_meta_single( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		$key     = sanitize_key( (string) $key );
		if ( $post_id <= 0 || '' === $key ) {
			return false;
		}
		delete_post_meta( $post_id, $key );
		return (bool) add_post_meta( $post_id, $key, $value, true );
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
