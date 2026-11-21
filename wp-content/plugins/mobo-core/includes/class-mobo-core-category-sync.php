<?php
/**
 * Category sync service.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Category_Sync {

	/** Product meta: product carries a temporary category until its Source category resolves. */
	const FALLBACK_PENDING_META = 'mobo_category_fallback_pending';

	/** Option: unresolved Source category GUIDs asking for an early category refresh. */
	const SYNC_REQUEST_OPTION = 'mobo_core_category_sync_request';

	private $category_map;

	/** @var array Request-local remote category GUID => WooCommerce term ID cache, including misses. */
	private $term_id_by_guid_cache = array();

	/** @var array Request-local product ID => sorted product_cat term IDs. */
	private $product_category_ids_cache = array();

	/** UX59: Snapshot apply lock token; only populated by authoritative HTTP lanes. */
	private $snapshot_lease_name = '';
	private $snapshot_lease_token = '';

	/**
	 * Apply one external category snapshot after fencing its HTTP request-start time.
	 * State and lock are Connection-owned. A newer already-committed snapshot wins,
	 * even if its response happened to arrive before an older in-flight GET.
	 * Direct legacy/payload callers without a start time retain their old contract.
	 */
	private function apply_fenced_snapshot( $payload, $started_us, $mapping_only ) {
		$started_us = absint( $started_us );
		if ( $started_us <= 0 ) {
			return $mapping_only ? $this->load_categories_for_mapping_payload_locked( $payload ) : $this->sync_categories_payload_locked( $payload );
		}
		$connection_id = class_exists( 'Mobo_Core_Connection_Context' ) ? absint( Mobo_Core_Connection_Context::get_effective_connection_id() ) : 0;
		if ( $connection_id <= 0 || ! class_exists( 'Mobo_Core_Lock' ) || ! class_exists( 'Mobo_Core_State_Store' ) || ! Mobo_Core_State_Store::is_available() ) {
			return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category snapshot owner or durable state is unavailable.' );
		}
		/* An authoritative empty array is valid only while its destination table
		 * is actually available; do not commit a generation to a missing map. */
		if ( ! $this->category_map instanceof Mobo_Core_Category_Map || ! Mobo_Core_Category_Map::table_exists() ) {
			return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category mapping table is unavailable.' );
		}
		$lock_name = 'category_snapshot_c' . $connection_id;
		$token = Mobo_Core_Lock::acquire( $lock_name, 900 );
		if ( false === $token ) {
			return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category snapshot mutation is busy; retry with a fresh fetch.' );
		}
		try {
			$committed_us = absint( Mobo_Core_State_Store::get( 'category_snapshot_committed_us', 0 ) );
			if ( $committed_us >= $started_us ) {
				return array( 'complete' => false, 'skipped' => 1, 'stale' => true, 'error' => 'Category HTTP snapshot predates the committed snapshot.' );
			}
			$this->snapshot_lease_name = $lock_name;
			$this->snapshot_lease_token = $token;
			$result = $mapping_only ? $this->load_categories_for_mapping_payload_locked( $payload ) : $this->sync_categories_payload_locked( $payload );
			if ( ! empty( $result['complete'] ) && empty( $result['skipped'] ) ) {
				if ( ! Mobo_Core_Lock::renew( $lock_name, $token, 900 )
					|| ! Mobo_Core_State_Store::set( 'category_snapshot_committed_us', $started_us ) ) {
					$result['complete'] = false;
					$result['skipped'] = 1;
					$result['error'] = 'Category snapshot watermark could not be durably committed.';
				}
			}
			return $result;
		} finally {
			$this->snapshot_lease_name = '';
			$this->snapshot_lease_token = '';
			Mobo_Core_Lock::release( $lock_name, $token );
		}
	}

	/** @return bool */
	private function category_snapshot_lease_valid() {
		return '' === $this->snapshot_lease_name || (
			'' !== $this->snapshot_lease_token
			&& Mobo_Core_Lock::renew( $this->snapshot_lease_name, $this->snapshot_lease_token, 900 )
		);
	}

	public function __construct() {
		$this->category_map = class_exists( 'Mobo_Core_Category_Map' ) ? new Mobo_Core_Category_Map() : null;
	}


	/**
	 * Load remote categories into the mapping table only.
	 *
	 * This method intentionally does not create or update WooCommerce terms.
	 * It is used by the admin button "load categories before product sync".
	 *
	 * @param mixed $payload API payload.
	 * @return array
	 */
	public function load_categories_for_mapping_payload( $payload, $snapshot_started_us = 0 ) {
		$requested_before = time();
		$result = $this->apply_fenced_snapshot( $payload, $snapshot_started_us, true );
		$this->after_category_snapshot( $result, $requested_before );
		return $result;
	}

	/**
	 * After any category snapshot: clear the early-refresh request and let the
	 * Recategorize queue re-apply categories to products that received a
	 * temporary fallback category while their Source category was missing.
	 *
	 * @param array $result Snapshot result.
	 * @param int   $requested_before Snapshot start time.
	 * @return void
	 */
	private function after_category_snapshot( $result, $requested_before ) {
		if ( ! is_array( $result ) || ! empty( $result['stale'] ) ) {
			return;
		}
		$progress = ! empty( $result['complete'] ) || absint( $result['created'] ?? 0 ) > 0 || absint( $result['updated'] ?? 0 ) > 0;
		if ( ! $progress ) {
			return;
		}
		if ( ! empty( $result['complete'] ) ) {
			self::clear_category_sync_request( $requested_before );
		}
		if ( class_exists( 'Mobo_Core_Recategorize_Queue' ) ) {
			try {
				Mobo_Core_Recategorize_Queue::maybe_start_pending_reapply( 'category-snapshot' );
			} catch ( Throwable $e ) {
				/* Re-apply is best effort; the snapshot itself already committed. */
			}
		}
	}

	/** Source payload application; caller holds the optional HTTP-snapshot lease. */
	private function load_categories_for_mapping_payload_locked( $payload ) {
		$categories = $payload;

		if ( is_array( $payload ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$categories = $payload['data'];
		}

		if ( ! is_array( $categories ) || ! ( $this->category_map instanceof Mobo_Core_Category_Map ) ) {
			return array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
			);
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$present_guids = array();

		foreach ( $categories as $category_data ) {
			if ( ! $this->category_snapshot_lease_valid() ) {
				return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category snapshot lease was lost.' );
			}
			if ( ! is_array( $category_data ) ) {
				$skipped++;
				continue;
			}

			$category_guid = $this->get_category_guid( $category_data );
			$title         = $this->get_category_title( $category_data, $category_guid );
			$url           = $this->get_category_url( $category_data );
			$parent_guid   = $this->get_parent_category_guid( $category_data );

			if ( '' === $category_guid ) {
				$skipped++;
				continue;
			}

			$present_guids[ $category_guid ] = true;
			$result = $this->category_map->upsert_remote_category_for_mapping( $category_guid, $title, $url, $parent_guid );

			if ( empty( $result['success'] ) ) {
				$skipped++;
				continue;
			}

			if ( ! empty( $result['created'] ) ) {
				$created++;
			} elseif ( ! empty( $result['skipped_update'] ) ) {
				$skipped++;
			} else {
				$updated++;
			}
		}

		$complete = 0 === $skipped;
		if ( $complete && class_exists( 'Mobo_Core_Settings' ) ) {
			$active_guids = array_values( array_keys( $present_guids ) );
			Mobo_Core_Settings::update( 'mobo_core_category_mapping_active_guids', $active_guids, false );
			Mobo_Core_Settings::update( 'mobo_core_category_mapping_active_snapshot_at', time(), false );
		}

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'complete' => $complete,
			'active'   => count( $present_guids ),
		);
	}

	public function sync_categories_payload( $payload, $snapshot_started_us = 0 ) {
		$requested_before = time();
		$result = $this->apply_fenced_snapshot( $payload, $snapshot_started_us, false );
		$this->after_category_snapshot( $result, $requested_before );
		return $result;
	}

	/** Source payload application; caller holds the optional HTTP-snapshot lease. */
	private function sync_categories_payload_locked( $payload ) {
		$categories = $payload;

		if ( is_array( $payload ) && isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$categories = $payload['data'];
		}

		if ( ! is_array( $categories ) ) {
			return array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
			);
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$pending = array();
		$present_guids = array();
		$unresolved = array();

		foreach ( $categories as $category_data ) {
			if ( ! $this->category_snapshot_lease_valid() ) {
				return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category snapshot lease was lost.' );
			}
			if ( ! is_array( $category_data ) ) {
				$skipped++;
				continue;
			}
			$pending[] = $category_data;
			$guid = $this->get_category_guid( $category_data );
			if ( '' !== $guid ) { $present_guids[ $guid ] = true; }
		}

		/*
		 * Category payload order is not authoritative. A child can arrive before
		 * its parent, so defer only that child and retry after the rest of the
		 * snapshot has had a chance to create its ancestors. Cycles or genuinely
		 * missing parents stop when a pass makes no progress.
		 */
		$max_passes = max( 1, count( $pending ) );
		for ( $pass = 0; $pass < $max_passes && ! empty( $pending ); $pass++ ) {
			if ( ! $this->category_snapshot_lease_valid() ) {
				return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category snapshot lease was lost before applying the next pass.' );
			}
			$next_pending = array();
			$progress     = 0;

			foreach ( $pending as $category_data ) {
				$result = $this->upsert_category( $category_data );

				if ( ! empty( $result['missing_parent'] ) ) {
					$next_pending[] = $category_data;
					continue;
				}

				$progress++;
				if ( empty( $result['term_id'] ) || ! empty( $result['incomplete'] ) ) {
					$skipped++;
					continue;
				}

				if ( ! empty( $result['created'] ) ) {
					$created++;
				} else {
					$updated++;
				}
			}

			if ( empty( $next_pending ) ) {
				break;
			}

			if ( 0 === $progress ) {
				$skipped += count( $next_pending );
				foreach ( $next_pending as $stalled_category ) {
					$unresolved[] = array( 'guid' => $this->get_category_guid( $stalled_category ), 'parentGuid' => $this->get_parent_category_guid( $stalled_category ) );
				}
				break;
			}

			$pending = $next_pending;
		}

		$retirement = array( 'retired' => 0, 'failed' => 0 );
		/* A stale/expired writer must never retire rows by absence. */
		if ( ! $this->category_snapshot_lease_valid() ) {
			return array( 'complete' => false, 'skipped' => 1, 'error' => 'Category snapshot lease was lost before retirement.' );
		}
		if ( 0 === $skipped && $this->category_map instanceof Mobo_Core_Category_Map && method_exists( $this->category_map, 'retire_absent_remote_categories' ) ) {
			$retirement = $this->category_map->retire_absent_remote_categories( array_keys( $present_guids ) );
			if ( ! empty( $retirement['failed'] ) ) { $skipped += absint( $retirement['failed'] ); }
		}
		if ( 0 === $skipped && class_exists( 'Mobo_Core_Settings' ) ) {
			Mobo_Core_Settings::update( 'mobo_core_category_mapping_active_guids', array_values( array_keys( $present_guids ) ), false );
			Mobo_Core_Settings::update( 'mobo_core_category_mapping_active_snapshot_at', time(), false );
		}
		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'complete' => 0 === $skipped,
			'retired'  => absint( $retirement['retired'] ?? 0 ),
			'unresolved' => $unresolved,
		);
	}

	/**
	 * Assign product categories.
	 *
	 * Rules:
	 * - Auto enabled + API categories found:
	 *   assign API categories for new/existing product.
	 *
	 * - Auto enabled + API categories not found:
	 *   assign default category for new/existing product.
	 *
	 * - Auto disabled + new product:
	 *   assign default category.
	 *
	 * - Auto disabled + existing product:
	 *   do not change categories.
	 *
	 * @param int   $product_id Product ID.
	 * @param mixed $categories Product category refs.
	 * @param bool  $auto_categories_enabled Auto categories enabled.
	 * @param bool  $is_new_product Is new product.
	 * @param bool  $authoritative_state_present Whether the payload explicitly carried category desired state.
	 * @return array
	 */
	public function assign_product_categories( $product_id, $categories, $auto_categories_enabled, $is_new_product = false, $authoritative_state_present = true ) {
		$product_id                 = absint( $product_id );
		$is_new_product             = (bool) $is_new_product;
		$auto_categories_enabled    = (bool) $auto_categories_enabled;
		$authoritative_state_present = (bool) $authoritative_state_present;
		$policy                  = Mobo_Core_Category_Assignment_Policy::settings( $auto_categories_enabled );
		$mapping_enabled         = (bool) $policy['mapping_enabled'];
		$mapping_required        = (bool) $policy['mapping_required'];
		$auto_categories_enabled = (bool) $policy['auto_categories_enabled'];
		$category_refs           = is_array( $categories ) ? $categories : array();

		if ( $product_id <= 0 ) {
			return array(
				'assigned' => 0,
				'source'   => 'none',
				'changed'  => false,
			);
		}

		/*
		 * Manual mapping is product assignment, not category creation.
		 * It must work even when automatic WooCommerce category sync is disabled.
		 */
		$manual_result = $this->resolve_manual_mapped_terms( $category_refs, $mapping_enabled );

		if ( ! empty( $manual_result['term_ids'] ) ) {
			$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $manual_result['term_ids'] ) ) ) );

			if ( ! empty( $term_ids ) ) {
				$assignment_error = '';
				$changed = $this->set_product_categories_if_changed( $product_id, $term_ids, $assignment_error );
				if ( '' === $assignment_error ) {
					$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'manual-mapping' );
				}
				$this->store_missing_category_guids_if_changed( $product_id, $manual_result['missing_guids'] );
				$this->apply_category_pending_state(
					$product_id,
					$manual_result['missing_guids'],
					$this->category_fallback_decision( $product_id, $is_new_product, $policy, count( $term_ids ), $manual_result['missing_guids'] )
				);

				return array(
					'assigned'     => count( $term_ids ),
					'source'       => 'manual-mapping',
					'changed'      => $changed,
					'missingGuids' => array_values( array_unique( $manual_result['missing_guids'] ) ),
					'error'        => $assignment_error,
				);
			}
		}

		if ( $mapping_required && ! empty( $manual_result['missing_guids'] ) ) {
			$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'mapping-required-missing' );
			$this->store_missing_category_guids_if_changed( $product_id, $manual_result['missing_guids'] );
			$fallback = $this->apply_missing_category_fallback( $product_id, $is_new_product, $policy, 0, $manual_result['missing_guids'] );

			return array(
				'assigned'     => $fallback['assigned'],
				'source'       => 'mapping-required-missing',
				'changed'      => $fallback['changed'],
				'missingGuids' => array_values( array_unique( $manual_result['missing_guids'] ) ),
				'fallback'     => $fallback['kind'],
				'error'        => $fallback['error'],
			);
		}

		/*
		 * Automatic category update disabled:
		 * - Manual mapping above still applies.
		 * - New product without mapping gets default category.
		 * - Existing product without mapping remains untouched.
		 */
		if ( ! $auto_categories_enabled ) {
			/* Only a later manual mapping can change this product (auto off). */
			$this->apply_category_pending_state(
				$product_id,
				$manual_result['missing_guids'],
				$this->category_fallback_decision( $product_id, $is_new_product, $policy, 0, $manual_result['missing_guids'] )
			);
			if ( $is_new_product ) {
				$result = $this->assign_default_category( $product_id );
				if ( ! empty( $result['changed'] ) ) {
					$result['source'] = 'auto-disabled-new-default';
					$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'auto-disabled-new-default' );
				}
				return $result;
			}

			return array(
				'assigned' => 0,
				'source'   => 'disabled-existing-product-unchanged',
				'changed'  => false,
			);
		}

		/*
		 * ProductUpdated is an authoritative snapshot for categories when the field
		 * is present. An explicit [] means the source removed every category and must
		 * not be confused with a partial payload that omitted the field entirely.
		 */
		if ( $authoritative_state_present && empty( $category_refs ) ) {
			$this->store_missing_category_guids_if_changed( $product_id, array() );
			$this->apply_category_pending_state( $product_id, array(), array( 'pending' => false, 'request_category_sync' => false ) );
			$assignment_error = '';
			$changed = $this->set_product_categories_if_changed( $product_id, array(), $assignment_error );
			if ( '' === $assignment_error ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'api-explicit-empty' );
			}

			return array(
				'assigned'     => 0,
				'source'       => 'api-explicit-empty',
				'changed'      => $changed,
				'missingGuids' => array(),
				'error'        => $assignment_error,
			);
		}

		$term_ids       = array();
		$sources        = array();
		$missing_guids  = array();

		foreach ( $category_refs as $category_ref ) {
			if ( ! is_array( $category_ref ) ) {
				continue;
			}

			$category_guid = $this->get_category_guid( $category_ref );

			if ( '' === $category_guid ) {
				continue;
			}

			$term_id = 0;
			$source  = 'missing';

			if ( $mapping_enabled && $this->category_map instanceof Mobo_Core_Category_Map ) {
				$identifiers = $this->get_category_identifiers( $category_ref );

				if ( method_exists( $this->category_map, 'resolve_assignment_term_by_identifiers' ) ) {
					$resolved = $this->category_map->resolve_assignment_term_by_identifiers( $identifiers );
				} else {
					$resolved = $this->category_map->resolve_assignment_term( $category_guid );
				}

				$term_id  = absint( isset( $resolved['term_id'] ) ? $resolved['term_id'] : 0 );
				$source   = sanitize_key( isset( $resolved['source'] ) ? $resolved['source'] : 'missing' );
			}

			if ( $term_id <= 0 ) {
				$term_id = $this->find_term_id_by_guid( $category_guid );

				if ( $term_id > 0 ) {
					$source = 'legacy-synced';
				}
			}

			if ( $term_id <= 0 && ! $mapping_required ) {
				$created = $this->upsert_category( $category_ref );
				$term_id = absint( isset( $created['term_id'] ) ? $created['term_id'] : 0 );

				if ( $term_id > 0 ) {
					$source = ! empty( $created['created'] ) ? 'auto-created' : ( ! empty( $created['skipped_update'] ) ? 'existing-category-kept' : 'auto-updated' );
				}
			}

			if ( $term_id > 0 ) {
				$term_ids[] = $term_id;
				$sources[]  = $source;
				continue;
			}

			$missing_guids[] = $category_guid;
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		$sources  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $sources ) ) ) );

		$this->store_missing_category_guids_if_changed( $product_id, $missing_guids );

		if ( ! empty( $term_ids ) ) {
			$this->apply_category_pending_state(
				$product_id,
				$missing_guids,
				$this->category_fallback_decision( $product_id, $is_new_product, $policy, count( $term_ids ), $missing_guids )
			);
			$assignment_error = '';
			$changed = $this->set_product_categories_if_changed( $product_id, $term_ids, $assignment_error );
			if ( '' === $assignment_error ) {
				$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', implode( ',', $sources ) );
			}

			return array(
				'assigned'      => count( $term_ids ),
				'source'        => ! empty( $sources ) ? implode( ',', $sources ) : 'mapped-or-synced',
				'changed'       => $changed,
				'missingGuids'  => array_values( array_unique( $missing_guids ) ),
				'error'         => $assignment_error,
			);
		}

		if ( $mapping_required && ! empty( $missing_guids ) ) {
			$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'mapping-required-missing' );
			$fallback = $this->apply_missing_category_fallback( $product_id, $is_new_product, $policy, 0, $missing_guids );

			return array(
				'assigned'     => $fallback['assigned'],
				'source'       => 'mapping-required-missing',
				'changed'      => $fallback['changed'],
				'missingGuids' => array_values( array_unique( $missing_guids ) ),
				'fallback'     => $fallback['kind'],
				'error'        => $fallback['error'],
			);
		}

		/*
		 * No resolved category. Do not overwrite categories on existing products.
		 * New products can still receive the configured default category.
		 */
		if ( ! empty( $missing_guids ) ) {
			/* Never wait for the category: assign the merchant fallback (or
			 * Uncategorized) temporarily, mark pending and request a category sync.
			 * An existing product that already has categories keeps them. */
			$fallback = $this->apply_missing_category_fallback( $product_id, $is_new_product, $policy, 0, $missing_guids );
			if ( $fallback['assigned'] > 0 || '' !== $fallback['error'] ) {
				$source = 'default' === $fallback['kind'] ? 'new-product-default' : 'temporary-uncategorized';
				if ( '' === $fallback['error'] ) {
					$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', $source );
				}
				return array(
					'assigned'     => $fallback['assigned'],
					'source'       => $source,
					'changed'      => $fallback['changed'],
					'missingGuids' => array_values( array_unique( $missing_guids ) ),
					'fallback'     => $fallback['kind'],
					'error'        => $fallback['error'],
				);
			}
		} else {
			$this->apply_category_pending_state( $product_id, array(), array( 'pending' => false, 'request_category_sync' => false ) );
		}

		if ( $is_new_product ) {
			$result = $this->assign_default_category( $product_id );

			if ( ! empty( $result['changed'] ) ) {
				$result['source'] = 'new-product-default';
				$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'new-product-default' );
			}

			return $result;
		}

		$this->update_post_meta_if_changed( $product_id, 'mobo_category_assign_source', 'existing-product-category-unchanged' );

		return array(
			'assigned'     => 0,
			'source'       => 'existing-product-category-unchanged',
			'changed'      => false,
			'missingGuids' => array_values( array_unique( $missing_guids ) ),
		);
	}



	/**
	 * Resolve only manual mappings for product assignment.
	 *
	 * @param array $category_refs Remote product category refs.
	 * @param bool  $mapping_enabled Mapping enabled.
	 * @return array
	 */
	private function resolve_manual_mapped_terms( $category_refs, $mapping_enabled ) {
		$result = array(
			'term_ids'      => array(),
			'missing_guids' => array(),
		);

		if ( ! $mapping_enabled || ! ( $this->category_map instanceof Mobo_Core_Category_Map ) || ! is_array( $category_refs ) ) {
			return $result;
		}

		foreach ( $category_refs as $category_ref ) {
			$identifiers = $this->get_category_identifiers( $category_ref );

			if ( empty( $identifiers ) ) {
				continue;
			}

			$term_id = 0;

			if ( method_exists( $this->category_map, 'get_manual_term_id_by_identifiers' ) ) {
				$term_id = $this->category_map->get_manual_term_id_by_identifiers( $identifiers );
			}

			if ( $term_id <= 0 ) {
				foreach ( $identifiers as $identifier ) {
					$term_id = $this->category_map->get_manual_term_id( $identifier );
					if ( $term_id > 0 ) {
						break;
					}
				}
			}

			if ( $term_id > 0 ) {
				$result['term_ids'][] = $term_id;
			} else {
				$result['missing_guids'][] = $this->get_primary_category_identifier( $identifiers );
			}
		}

		$result['term_ids']      = array_values( array_unique( array_filter( array_map( 'absint', $result['term_ids'] ) ) ) );
		$result['missing_guids'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $result['missing_guids'] ) ) ) );

		return $result;
	}


	/**
	 * Return GUID identifiers from a category reference.
	 *
	 * Category assignment is GUID-only. URL/path/slug are ignored here because
	 * they are not durable identity keys.
	 *
	 * @param mixed $category_ref Category ref.
	 * @return array
	 */
	private function get_category_identifiers( $category_ref ) {
		return $this->collect_category_guid_candidates( $category_ref );
	}

	/**
	 * Collect all category GUID candidates from a product-category reference.
	 *
	 * Important: some payloads have a wrapper/relation GUID at top-level `id`,
	 * while the actual category GUID is inside `category.id` or `category.guid`.
	 * Mapping must match the actual category GUID, but collecting all valid GUIDs
	 * lets older payload shapes still resolve correctly. URL/path/slug are never used.
	 *
	 * @param mixed $category_ref Category reference.
	 * @return array
	 */
	private function collect_category_guid_candidates( $category_ref ) {
	return Mobo_Core_Remote_Identity_Policy::collect_category_guid_candidates( $category_ref );
}

	/**
	 * Append a GUID candidate if valid.
	 *
	 * @param array $identifiers Candidate list.
	 * @param mixed $value Raw value.
	 * @return void
	 */
	private function append_guid_candidate( &$identifiers, $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = trim( $value );

		if ( '' !== $value && $this->is_remote_guid_value( $value ) ) {
			$identifiers[] = $value;
		}
	}

	/**
	 * Pick a readable primary identifier for diagnostics.
	 *
	 * @param array $identifiers Identifiers.
	 * @return string
	 */
	private function get_primary_category_identifier( $identifiers ) {
	return Mobo_Core_Remote_Identity_Policy::primary_identifier( $identifiers );
}

	public function upsert_category( $category_data ) {
		$category_guid = $this->get_category_guid( $category_data );
		$title         = $this->get_category_title( $category_data, $category_guid );
		$url           = $this->get_category_url( $category_data );
		$parent_guid   = $this->get_parent_category_guid( $category_data );

		if ( '' === $category_guid ) {
			return array(
				'term_id' => 0,
				'created' => false,
			);
		}

		$term_id        = $this->find_term_id_by_guid( $category_guid, true );
		$parent_term_id = 0;

		if ( '' !== $parent_guid ) {
			$parent_term_id = $this->find_term_id_by_guid( $parent_guid );
			if ( $parent_term_id <= 0 ) {
				return array(
					'term_id'       => $term_id,
					'created'       => false,
					'missing_parent' => true,
					'parent_guid'   => $parent_guid,
				);
			}
		}

		/* wp_terms.name may still be utf8/utf8mb3 on shared hosts: a four-byte
		 * character (emoji) would make wp_insert_term()/wp_update_term() fail and
		 * keep the whole category snapshot incomplete forever. The guard returns
		 * the title unchanged when the column supports utf8mb4. The Mobo category
		 * map (utf8mb4 custom table) keeps the original Source title. */
		$term_title = ( '' !== $title && class_exists( 'Mobo_Core_Unicode_Guard' ) )
			? (string) Mobo_Core_Unicode_Guard::prepare_term_name( $title, 'category-title' )
			: $title;

		$args = array();
		if ( '' !== $term_title ) {
			$args['name'] = $term_title;
		}

		$slug = $this->slug_from_url( $url );
		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}
		$args['parent'] = '' !== $parent_guid ? $parent_term_id : 0;

		if ( $term_id > 0 ) {
			$current_term = get_term( $term_id, 'product_cat' );
			$incomplete   = '1' === (string) get_term_meta( $term_id, 'mobo_sync_incomplete', true );

			/* A half-created Mobo term is owned by this workflow and may be fully repaired. */
			if ( $incomplete ) {
				$updated_term = wp_update_term( $term_id, 'product_cat', $args );
				if ( is_wp_error( $updated_term ) && isset( $args['slug'] ) ) {
					$retry_args = $args;
					unset( $retry_args['slug'] );
					$updated_term = wp_update_term( $term_id, 'product_cat', $retry_args );
				}

				if ( is_wp_error( $updated_term ) ) {
					return array(
						'term_id'    => $term_id,
						'created'    => false,
						'incomplete' => true,
						'error'      => sanitize_text_field( $updated_term->get_error_message() ),
					);
				}

				if ( ! $this->save_category_meta( $term_id, $category_guid, $url, $parent_guid ) ) {
					return array(
						'term_id'    => $term_id,
						'created'    => false,
						'incomplete' => true,
						'error'      => 'Category term was repaired but its identity metadata did not persist.',
					);
				}
				if ( ! $this->upsert_category_map( $category_guid, $term_id, $title, $url, $parent_guid ) ) {
					return array(
						'term_id'    => $term_id,
						'created'    => false,
						'incomplete' => true,
						'error'      => 'Category term was repaired but the Mobo category map did not persist.',
					);
				}
				$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
				if ( ! $this->set_term_meta_verified( $term_id, 'mobo_sync_incomplete', '0' ) ) {
					return array(
						'term_id'    => $term_id,
						'created'    => false,
						'incomplete' => true,
						'error'      => 'Category identity committed, but the completion marker did not persist.',
					);
				}

				return array(
					'term_id'          => $term_id,
					'created'          => false,
					'incomplete_repaired' => true,
				);
			}

			$placeholder_repaired = false;
			$hierarchy_repaired   = false;

			/* Existing customer categories remain protected except generated placeholders. */
			if (
				'' !== $title
				&& $current_term instanceof WP_Term
				&& $this->is_placeholder_category_title( $current_term->name, $category_guid )
			) {
				$updated_term = wp_update_term( $term_id, 'product_cat', array( 'name' => $term_title ) );
				$placeholder_repaired = ! is_wp_error( $updated_term );
			}

			/*
			 * Older builds could create a child at root when its parent had not been
			 * seen yet, while still recording the intended parent GUID. Repair only
			 * that exact generated state. A customer move to another non-root parent
			 * is deliberately preserved.
			 */
			$stored_parent_guid = sanitize_text_field( (string) get_term_meta( $term_id, 'mobo_parent_category_guid', true ) );
			if (
				'' !== $parent_guid
				&& $parent_term_id > 0
				&& $current_term instanceof WP_Term
				&& 0 === absint( $current_term->parent )
				&& hash_equals( $parent_guid, $stored_parent_guid )
			) {
				$updated_parent = wp_update_term( $term_id, 'product_cat', array( 'parent' => $parent_term_id ) );
				$hierarchy_repaired = ! is_wp_error( $updated_parent );
			}

			/* A source category that reappears after authoritative retirement is
			 * resurrected in place; do not leave the local term marked retired. */
			if ( ! $this->set_term_meta_verified( $term_id, 'mobo_category_retired', '0' ) ) {
				return array( 'term_id' => $term_id, 'created' => false, 'incomplete' => true, 'error' => 'Category retirement marker could not be cleared.' );
			}

			if ( ! $this->upsert_category_map( $category_guid, $term_id, $title, $url, $parent_guid ) ) {
				return array(
					'term_id'    => $term_id,
					'created'    => false,
					'incomplete' => true,
					'error'      => 'WooCommerce category exists but its Mobo category map could not be persisted.',
				);
			}
			/* Retirement can interleave with the two persistence layers. After the
			 * durable map points at this term, reassert that it is not retired. */
			if ( ! $this->set_term_meta_verified( $term_id, 'mobo_category_retired', '0' ) ) {
				return array( 'term_id' => $term_id, 'created' => false, 'incomplete' => true, 'error' => 'Reactivated category retirement marker was not verified.' );
			}
			$this->term_id_by_guid_cache[ $category_guid ] = $term_id;

			return array(
				'term_id'              => $term_id,
				'created'              => false,
				'skipped_update'       => ! $placeholder_repaired && ! $hierarchy_repaired,
				'placeholder_repaired' => $placeholder_repaired,
				'hierarchy_repaired'   => $hierarchy_repaired,
			);
		}

		/* Never create a customer-visible category from an incomplete GUID-only payload. */
		if ( '' === $title ) {
			if ( $this->category_map instanceof Mobo_Core_Category_Map ) {
				$this->category_map->upsert_remote_category_for_mapping( $category_guid, '', $url, $parent_guid );
			}

			return array(
				'term_id'       => 0,
				'created'       => false,
				'missing_title' => true,
			);
		}

		$insert_args = array();
		if ( isset( $args['slug'] ) ) {
			$insert_args['slug'] = $args['slug'];
		}
		if ( $parent_term_id > 0 ) {
			$insert_args['parent'] = $parent_term_id;
		}

		$result = wp_insert_term( $term_title, 'product_cat', $insert_args );
		if ( is_wp_error( $result ) && isset( $insert_args['slug'] ) ) {
			unset( $insert_args['slug'] );
			$result = wp_insert_term( $term_title, 'product_cat', $insert_args );
		}

		/*
		 * B19: if PHP was killed between wp_insert_term() and the category_guid
		 * bootstrap below, the term exists without any Source identity. Every retry
		 * then hit "term_exists" and returned term_id=0, so this GUID never resolved
		 * again. Adopt such an orphan (same name, same parent, no or same GUID).
		 */
		$adopted = false;
		if ( is_wp_error( $result ) ) {
			$orphan_id = $this->find_adoptable_orphan_term( $result, $term_title, $parent_term_id, $category_guid, isset( $args['slug'] ) ? (string) $args['slug'] : '' );
			if ( $orphan_id > 0 ) {
				$result  = array( 'term_id' => $orphan_id );
				$adopted = true;
			}
		}

		if ( is_wp_error( $result ) || empty( $result['term_id'] ) ) {
			return array(
				'term_id' => 0,
				'created' => false,
				'error'   => is_wp_error( $result ) ? sanitize_text_field( $result->get_error_message() ) : 'Category term could not be created.',
			);
		}

		$term_id = absint( $result['term_id'] );

		/*
		 * Establish a durable identity before any later step can fail. A newly
		 * inserted term without category_guid/incomplete metadata is dangerous:
		 * the next retry may not be able to find it and can create a duplicate.
		 * Both markers therefore have to survive a read-back before the term is
		 * allowed to enter the repairable half-created state. If that bootstrap
		 * cannot be persisted, roll back the term we created in this call.
		 */
		$identity_bootstrapped = $this->set_term_meta_verified( $term_id, 'category_guid', $category_guid )
			&& $this->set_term_meta_verified( $term_id, 'mobo_sync_incomplete', '1' );

		if ( ! $identity_bootstrapped && $adopted ) {
			/* Never delete a term this call did not create. */
			return array( 'term_id' => 0, 'created' => false, 'incomplete' => true, 'error' => 'Orphan category term could not be adopted (identity metadata did not persist).' );
		}

		if ( ! $identity_bootstrapped ) {
			$deleted = wp_delete_term( $term_id, 'product_cat' );
			unset( $this->term_id_by_guid_cache[ $category_guid ] );

			return array(
				'term_id'    => ( ! is_wp_error( $deleted ) && false !== $deleted ) ? 0 : $term_id,
				'created'    => false,
				'incomplete' => true,
				'rolled_back'=> ! is_wp_error( $deleted ) && false !== $deleted,
				'error'      => 'Category term identity bootstrap did not persist; the newly-created term was rolled back when possible.',
			);
		}

		$updated_term = wp_update_term( $term_id, 'product_cat', $args );
		if ( is_wp_error( $updated_term ) && isset( $args['slug'] ) ) {
			$retry_args = $args;
			unset( $retry_args['slug'] );
			$updated_term = wp_update_term( $term_id, 'product_cat', $retry_args );
		}

		if ( is_wp_error( $updated_term ) ) {
			/* Keep category_guid + incomplete=1 so a later payload can safely repair it. */
			$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
			return array(
				'term_id'    => $term_id,
				'created'    => true,
				'incomplete' => true,
				'error'      => sanitize_text_field( $updated_term->get_error_message() ),
			);
		}

		if ( ! $this->save_category_meta( $term_id, $category_guid, $url, $parent_guid ) ) {
			$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
			return array(
				'term_id'    => $term_id,
				'created'    => true,
				'incomplete' => true,
				'error'      => 'Category term was created but its identity metadata did not persist.',
			);
		}
		if ( ! $this->upsert_category_map( $category_guid, $term_id, $title, $url, $parent_guid ) ) {
			/* Keep mobo_sync_incomplete=1 so a later pass can safely finish the
			 * identity commit without treating this half-created term as customer-owned. */
			$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
			return array(
				'term_id'    => $term_id,
				'created'    => true,
				'incomplete' => true,
				'error'      => 'Category term was created but its Mobo category map could not be persisted.',
			);
		}
		$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
		if ( ! $this->set_term_meta_verified( $term_id, 'mobo_sync_incomplete', '0' ) ) {
			return array(
				'term_id'    => $term_id,
				'created'    => true,
				'incomplete' => true,
				'error'      => 'Category identity committed, but the completion marker did not persist.',
			);
		}

		return array(
			'term_id' => $term_id,
			'created' => true,
		);
	}

	public function find_term_id_by_guid( $category_guid, $include_retired = false ) {
		$category_guid = sanitize_text_field( (string) $category_guid );

		if ( '' === $category_guid ) {
			return 0;
		}

		if ( ! $include_retired && array_key_exists( $category_guid, $this->term_id_by_guid_cache ) ) {
			return absint( $this->term_id_by_guid_cache[ $category_guid ] );
		}

		if ( $this->category_map instanceof Mobo_Core_Category_Map ) {
			$term_id = $this->category_map->get_synced_term_id( $category_guid );

			if ( $term_id > 0 ) {
				if ( ! $include_retired && '1' === (string) get_term_meta( $term_id, 'mobo_category_retired', true ) ) {
					$this->term_id_by_guid_cache[ $category_guid ] = 0;
					return 0;
				}
				if ( ! $include_retired ) { $this->term_id_by_guid_cache[ $category_guid ] = absint( $term_id ); }
				return absint( $term_id );
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query' => array(
					array(
						'key'   => 'category_guid',
						'value' => $category_guid,
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms[0] ) ) {
			$this->term_id_by_guid_cache[ $category_guid ] = 0;
			return 0;
		}

		$term_id = absint( $terms[0]->term_id );
		if ( $term_id > 0 && ! $include_retired && '1' === (string) get_term_meta( $term_id, 'mobo_category_retired', true ) ) {
			$this->term_id_by_guid_cache[ $category_guid ] = 0;
			return 0;
		}

		if ( $term_id > 0 && ! $include_retired ) {
			$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
			$this->upsert_category_map( $category_guid, $term_id, '', '', '' );
		}

		return $term_id;
	}

	/**
	 * Build the pure fallback decision for one product.
	 *
	 * @param int   $product_id Product ID.
	 * @param bool  $is_new_product New product.
	 * @param array $policy Category policy snapshot.
	 * @param int   $resolved_count Resolved term count.
	 * @param array $missing_guids Unresolved Source category GUIDs.
	 * @return array
	 */
	private function category_fallback_decision( $product_id, $is_new_product, $policy, $resolved_count, $missing_guids ) {
		$missing_guids = is_array( $missing_guids ) ? array_filter( $missing_guids, 'strlen' ) : array();
		$needs_terms   = ! empty( $missing_guids ) && 0 === absint( $resolved_count );
		return Mobo_Core_Category_Assignment_Policy::decide_missing_category_fallback(
			array(
				'is_new_product'          => (bool) $is_new_product,
				'auto_categories_enabled' => ! empty( $policy['auto_categories_enabled'] ),
				'mapping_enabled'         => ! empty( $policy['mapping_enabled'] ),
				'mapping_required'        => ! empty( $policy['mapping_required'] ),
				'resolved_count'          => absint( $resolved_count ),
				'missing_count'           => count( $missing_guids ),
				'current_count'           => $needs_terms ? count( $this->get_product_category_ids( $product_id ) ) : 0,
				'default_term_id'         => $needs_terms ? Mobo_Core_Category_Assignment_Policy::default_term_id() : 0,
				'uncategorized_term_id'   => $needs_terms ? Mobo_Core_Category_Assignment_Policy::uncategorized_term_id() : 0,
			)
		);
	}

	/**
	 * Apply the temporary fallback (if the decision asks for one) plus the
	 * pending marker / category sync request.
	 *
	 * @return array assigned, changed, kind, error.
	 */
	private function apply_missing_category_fallback( $product_id, $is_new_product, $policy, $resolved_count, $missing_guids ) {
		$decision = $this->category_fallback_decision( $product_id, $is_new_product, $policy, $resolved_count, $missing_guids );
		$out      = array( 'assigned' => 0, 'changed' => false, 'kind' => '', 'error' => '' );
		if ( 'assign_fallback' === $decision['action'] && absint( $decision['term_id'] ) > 0 ) {
			$error          = '';
			$out['changed'] = $this->set_product_categories_if_changed( $product_id, array( absint( $decision['term_id'] ) ), $error );
			$out['error']   = $error;
			$out['kind']    = (string) $decision['kind'];
			$out['assigned'] = '' === $error ? 1 : 0;
		}
		$this->apply_category_pending_state( $product_id, $missing_guids, $decision );
		return $out;
	}

	/**
	 * Persist/clear the "temporary category, re-apply later" marker and request
	 * a category sync for unresolved GUIDs.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $missing_guids Unresolved GUIDs.
	 * @param array $decision Decision (pending, request_category_sync).
	 * @return void
	 */
	private function apply_category_pending_state( $product_id, $missing_guids, $decision ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}
		if ( ! empty( $decision['pending'] ) ) {
			$this->update_post_meta_if_changed( $product_id, self::FALLBACK_PENDING_META, '1' );
		} elseif ( metadata_exists( 'post', $product_id, self::FALLBACK_PENDING_META ) ) {
			delete_post_meta( $product_id, self::FALLBACK_PENDING_META );
		}
		if ( ! empty( $decision['request_category_sync'] ) ) {
			self::request_category_sync( is_array( $missing_guids ) ? $missing_guids : array() );
		}
	}

	/**
	 * Ask the next category refresh to run even if its interval has not elapsed.
	 * Throttled: the option is rewritten at most once per 10 minutes unless new
	 * GUIDs appear, so a large catalogue sync does not write it per product.
	 *
	 * @param array $guids Unresolved Source category GUIDs.
	 * @return void
	 */
	public static function request_category_sync( $guids ) {
		$guids   = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', is_array( $guids ) ? $guids : array() ), 'strlen' ) ) );
		$current = get_option( self::SYNC_REQUEST_OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$known   = isset( $current['guids'] ) && is_array( $current['guids'] ) ? $current['guids'] : array();
		$new     = array_diff( $guids, $known );
		$at      = absint( $current['at'] ?? 0 );
		if ( empty( $new ) && $at > 0 && ( time() - $at ) < 600 ) {
			return;
		}
		update_option(
			self::SYNC_REQUEST_OPTION,
			array(
				'at'    => time(),
				'guids' => array_slice( array_values( array_unique( array_merge( $known, $guids ) ) ), -200 ),
			),
			false
		);
	}

	/**
	 * Pending category sync request, or an empty array.
	 *
	 * @return array
	 */
	public static function get_category_sync_request() {
		$current = get_option( self::SYNC_REQUEST_OPTION, array() );
		return is_array( $current ) ? $current : array();
	}

	/**
	 * Clear the request after a category snapshot was applied (only when no newer
	 * request arrived while the snapshot was running).
	 *
	 * @param int $applied_at Time the snapshot fetch started.
	 * @return void
	 */
	public static function clear_category_sync_request( $applied_at ) {
		$current = self::get_category_sync_request();
		if ( ! empty( $current ) && absint( $current['at'] ?? 0 ) <= absint( $applied_at ) ) {
			delete_option( self::SYNC_REQUEST_OPTION );
		}
	}

	/**
	 * Pure adoption rule for an existing term that blocks wp_insert_term().
	 *
	 * @param string $existing_guid category_guid meta of the existing term ('' = none).
	 * @param int    $existing_parent Existing term parent.
	 * @param int    $desired_parent Desired parent.
	 * @param string $category_guid Source GUID being created.
	 * @param bool   $names_match Existing name equals the desired name (guard-aware).
	 * @param bool   $retired Existing term is a retired Mobo term.
	 * @return bool
	 */
	public static function orphan_term_is_adoptable( $existing_guid, $existing_parent, $desired_parent, $category_guid, $names_match, $retired = false ) {
		$existing_guid = trim( (string) $existing_guid );
		$category_guid = trim( (string) $category_guid );
		if ( '' === $category_guid || ! $names_match || absint( $existing_parent ) !== absint( $desired_parent ) ) {
			return false;
		}
		/* A term owned by another Source category is never stolen. */
		if ( '' !== $existing_guid ) {
			return hash_equals( $existing_guid, $category_guid ) && ! $retired;
		}
		return true;
	}

	/**
	 * Locate a term that blocks creation and may be adopted for this GUID.
	 *
	 * @param WP_Error $error wp_insert_term() error.
	 * @param string   $name Desired (guarded) term name.
	 * @param int      $parent_term_id Desired parent.
	 * @param string   $category_guid Source GUID.
	 * @param string   $slug Desired slug.
	 * @return int
	 */
	private function find_adoptable_orphan_term( $error, $name, $parent_term_id, $category_guid, $slug = '' ) {
		$candidates = array();
		if ( $error instanceof WP_Error ) {
			$data = $error->get_error_data( 'term_exists' );
			if ( is_numeric( $data ) && absint( $data ) > 0 ) {
				$candidates[] = absint( $data );
			}
		}
		if ( empty( $candidates ) && '' !== (string) $name ) {
			$found = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'name'       => (string) $name,
					'parent'     => absint( $parent_term_id ),
					'number'     => 3,
					'fields'     => 'ids',
				)
			);
			if ( is_array( $found ) ) {
				$candidates = array_merge( $candidates, array_map( 'absint', $found ) );
			}
		}
		if ( empty( $candidates ) && '' !== (string) $slug ) {
			$by_slug = get_term_by( 'slug', (string) $slug, 'product_cat' );
			if ( $by_slug instanceof WP_Term ) {
				$candidates[] = absint( $by_slug->term_id );
			}
		}
		foreach ( array_unique( $candidates ) as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$names_match = class_exists( 'Mobo_Core_Unicode_Guard' )
				? Mobo_Core_Unicode_Guard::equivalent( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ), html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ) )
				: html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) === html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
			$existing_guid = sanitize_text_field( (string) get_term_meta( $term_id, 'category_guid', true ) );
			$retired       = '1' === (string) get_term_meta( $term_id, 'mobo_category_retired', true );
			if ( self::orphan_term_is_adoptable( $existing_guid, absint( $term->parent ), $parent_term_id, $category_guid, $names_match, $retired ) ) {
				if ( class_exists( 'Mobo_Core_Logger' ) ) {
					Mobo_Core_Logger::warning( 'Mobo Core adopted an orphan WooCommerce category term for a Source category.', array( 'termId' => $term_id, 'categoryGuid' => $category_guid ) );
				}
				return absint( $term_id );
			}
		}
		return 0;
	}

	private function assign_default_category( $product_id ) {
		$product_id = absint( $product_id );
		$fallback   = Mobo_Core_Category_Assignment_Policy::fallback_status();

		if ( $product_id <= 0 || 'missing' === $fallback['status'] ) {
			return array(
				'assigned' => 0,
				'source'   => 'default-not-configured',
				'changed'  => false,
			);
		}

		if ( 'valid' !== $fallback['status'] || absint( $fallback['term_id'] ) <= 0 ) {
			return array(
				'assigned' => 0,
				'source'   => 'default-missing',
				'changed'  => false,
			);
		}

		$default_category_id = absint( $fallback['term_id'] );
		$assignment_error = '';
		$changed = $this->set_product_categories_if_changed( $product_id, array( $default_category_id ), $assignment_error );

		return array(
			'assigned' => 1,
			'source'   => 'default',
			'changed'  => $changed,
			'error'    => $assignment_error,
		);
	}

	/**
	 * Replace product categories only when the exact term set changed.
	 * Avoids taxonomy hooks, term counting and cache churn on price/stock-only syncs.
	 *
	 * @param int   $product_id Product ID.
	 * @param array  $term_ids Desired product_cat IDs.
	 * @param string $error Assignment error message, if any.
	 * @return bool True only when WordPress taxonomy state changed.
	 */
	private function set_product_categories_if_changed( $product_id, $term_ids, &$error = '' ) {
		$error = '';
		$product_id = absint( $product_id );
		$desired    = array_values( array_unique( array_filter( array_map( 'absint', is_array( $term_ids ) ? $term_ids : array() ) ) ) );
		sort( $desired, SORT_NUMERIC );

		if ( $product_id <= 0 ) {
			return false;
		}

		$current = $this->get_product_category_ids( $product_id );
		if ( $current === $desired ) {
			return false;
		}

		/* WooCommerce force-create assigns default_product_cat when a product has no
		 * categories. Authoritative [] must be able to remove that bootstrap term after
		 * the product save, so use the relationship-deletion primitive for the empty set. */
		if ( empty( $desired ) ) {
			wp_delete_object_term_relationships( $product_id, 'product_cat' );
			$result = array();
		} else {
			$result = wp_set_object_terms( $product_id, $desired, 'product_cat', false );
		}
		if ( is_wp_error( $result ) ) {
			$error = sanitize_text_field( $result->get_error_message() );
			if ( '' === $error ) {
				$error = 'WordPress rejected the product category assignment.';
			}
			return false;
		}

		/* Read the taxonomy state back from WordPress instead of trusting the write
		 * return value. This is also what makes an explicit empty desired set durable. */
		unset( $this->product_category_ids_cache[ $product_id ] );
		$persisted = $this->get_product_category_ids( $product_id );
		if ( $persisted !== $desired ) {
			$error = 'Product category assignment did not persist the requested term set.';
			return false;
		}

		return true;
	}

	/**
	 * Return sorted product_cat IDs with request-local caching.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_product_category_ids( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		if ( array_key_exists( $product_id, $this->product_category_ids_cache ) ) {
			return $this->product_category_ids_cache[ $product_id ];
		}

		$ids = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			$ids = array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		sort( $ids, SORT_NUMERIC );
		$this->product_category_ids_cache[ $product_id ] = $ids;
		return $ids;
	}

	private function update_post_meta_if_changed( $post_id, $key, $value ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}
		$current = get_post_meta( $post_id, $key, true );
		if ( $current == $value ) { // Intentional loose comparison for serialized/numeric metadata.
			return false;
		}
		return false !== update_post_meta( $post_id, $key, $value );
	}

	private function store_missing_category_guids_if_changed( $product_id, $missing_guids ) {
		$product_id = absint( $product_id );
		$missing    = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', is_array( $missing_guids ) ? $missing_guids : array() ) ) ) );
		if ( $product_id <= 0 ) {
			return;
		}
		if ( empty( $missing ) ) {
			if ( metadata_exists( 'post', $product_id, 'mobo_category_missing_guids' ) ) {
				delete_post_meta( $product_id, 'mobo_category_missing_guids' );
			}
			return;
		}
		$this->update_post_meta_if_changed( $product_id, 'mobo_category_missing_guids', $missing );
	}

	private function get_category_guid( $category_data ) {
		$identifiers = $this->collect_category_guid_candidates( $category_data );

		return ! empty( $identifiers ) ? sanitize_text_field( (string) $identifiers[0] ) : '';
	}

	/**
	 * Extract a readable category title from flat or relation-wrapped payloads.
	 *
	 * Product payloads may wrap the real category object inside `category`, while
	 * category-list endpoints may return a flat object. Older code only checked
	 * top-level `title`, which caused GUID placeholders to be created.
	 *
	 * @param array  $category_data Category payload.
	 * @param string $category_guid Resolved category GUID.
	 * @return string
	 */
	private function get_category_title( $category_data, $category_guid = '' ) {
		$title_keys = array( 'title', 'name', 'categoryTitle', 'categoryName', 'displayName', 'label' );

		foreach ( $title_keys as $key ) {
			$title = $this->normalize_category_title( $this->get_value( $category_data, $key, '' ), $category_guid );

			if ( '' !== $title ) {
				return $title;
			}
		}

		$nested = $this->get_value( $category_data, 'category', null );

		if ( is_array( $nested ) && $nested !== $category_data ) {
			return $this->get_category_title( $nested, $category_guid );
		}

		return '';
	}

	/**
	 * Extract category URL/path from flat or relation-wrapped payloads.
	 *
	 * @param array $category_data Category payload.
	 * @return string
	 */
	private function get_category_url( $category_data ) {
		foreach ( array( 'url', 'path', 'categoryUrl', 'categoryPath' ) as $key ) {
			$value = trim( sanitize_text_field( (string) $this->get_value( $category_data, $key, '' ) ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		$nested = $this->get_value( $category_data, 'category', null );

		if ( is_array( $nested ) && $nested !== $category_data ) {
			return $this->get_category_url( $nested );
		}

		return '';
	}

	/**
	 * Extract parent category GUID from flat or relation-wrapped payloads.
	 *
	 * @param array $category_data Category payload.
	 * @return string
	 */
	private function get_parent_category_guid( $category_data ) {
		foreach ( array( 'parentId', 'parentGuid', 'parentCategoryId', 'parentCategoryGuid' ) as $key ) {
			$value = trim( sanitize_text_field( (string) $this->get_value( $category_data, $key, '' ) ) );

			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		$nested = $this->get_value( $category_data, 'category', null );

		if ( is_array( $nested ) && $nested !== $category_data ) {
			return $this->get_parent_category_guid( $nested );
		}

		return '';
	}

	/**
	 * Normalize and validate a customer-visible category title.
	 *
	 * @param mixed  $title Raw title.
	 * @param string $category_guid Category GUID.
	 * @return string
	 */
	private function normalize_category_title( $title, $category_guid = '' ) {
		$title = trim( sanitize_text_field( (string) $title ) );

		if ( '' === $title ) {
			return '';
		}

		$category_guid = trim( sanitize_text_field( (string) $category_guid ) );

		if ( '' !== $category_guid && 0 === strcasecmp( $title, $category_guid ) ) {
			return '';
		}

		if ( $this->is_placeholder_category_title( $title, $category_guid ) ) {
			return '';
		}

		return $title;
	}

	/**
	 * Determine whether a title is the old generated GUID placeholder.
	 *
	 * @param string $title Category title.
	 * @param string $category_guid Category GUID.
	 * @return bool
	 */
	private function is_placeholder_category_title( $title, $category_guid = '' ) {
		$title         = trim( sanitize_text_field( (string) $title ) );
		$category_guid = trim( sanitize_text_field( (string) $category_guid ) );

		if ( '' === $title ) {
			return false;
		}

		if ( '' !== $category_guid && 0 === strcasecmp( $title, 'Mobo Category ' . $category_guid ) ) {
			return true;
		}

		return 1 === preg_match( '/^Mobo Category\s+[0-9a-f-]{16,}$/i', $title );
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

	private function upsert_category_map( $category_guid, $term_id, $name = '', $url = '', $parent_guid = '' ) {
		if ( ! ( $this->category_map instanceof Mobo_Core_Category_Map ) ) {
			return false;
		}

		return (bool) $this->category_map->upsert_synced_category( $category_guid, $term_id, $name, $url, $parent_guid );
	}

	private function save_category_meta( $term_id, $category_guid, $url, $parent_guid ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return false;
		}

		return $this->set_term_meta_verified( $term_id, 'category_guid', sanitize_text_field( (string) $category_guid ) )
			&& $this->set_term_meta_verified( $term_id, 'mobo_category_url', $this->termmeta_text( sanitize_text_field( (string) $url ) ) )
			&& $this->set_term_meta_verified( $term_id, 'mobo_parent_category_guid', sanitize_text_field( (string) $parent_guid ) )
			&& $this->set_term_meta_verified( $term_id, 'mobo_category_retired', '0' );
	}

	/**
	 * Persist term metadata and prove the postcondition. update_term_meta() returns
	 * false both for a real DB failure and for an unchanged value, so the stored
	 * value—not the raw return code—is the durability boundary.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Expected value.
	 * @return bool
	 */
	private function set_term_meta_verified( $term_id, $key, $value ) {
		return Mobo_Core_Durable_State_Policy::update_term_meta_verified( $term_id, $key, $value );
	}

	/**
	 * Guard Source text stored in wp_termmeta (may be utf8mb3 on old installs).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function termmeta_text( $value ) {
		global $wpdb;
		if ( ! class_exists( 'Mobo_Core_Unicode_Guard' ) || ! is_object( $wpdb ) ) {
			return (string) $value;
		}
		return (string) Mobo_Core_Unicode_Guard::prepare_for_column( (string) $value, $wpdb->termmeta, 'meta_value', 'category-url' );
	}

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

	private function get_value( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		if ( array_key_exists( $key, $array ) ) {
			return $array[ $key ];
		}

		$pascal = ucfirst( $key );

		if ( array_key_exists( $pascal, $array ) ) {
			return $array[ $pascal ];
		}

		return $default;
	}
}