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

	private $category_map;

	/** @var array Request-local remote category GUID => WooCommerce term ID cache, including misses. */
	private $term_id_by_guid_cache = array();

	/** @var array Request-local product ID => sorted product_cat term IDs. */
	private $product_category_ids_cache = array();

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
	public function load_categories_for_mapping_payload( $payload ) {
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

		foreach ( $categories as $category_data ) {
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

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'complete' => 0 === $skipped,
		);
	}

	public function sync_categories_payload( $payload ) {
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

		foreach ( $categories as $category_data ) {
			if ( ! is_array( $category_data ) ) {
				$skipped++;
				continue;
			}
			$pending[] = $category_data;
		}

		/*
		 * Category payload order is not authoritative. A child can arrive before
		 * its parent, so defer only that child and retry after the rest of the
		 * snapshot has had a chance to create its ancestors. Cycles or genuinely
		 * missing parents stop when a pass makes no progress.
		 */
		$max_passes = max( 1, count( $pending ) );
		for ( $pass = 0; $pass < $max_passes && ! empty( $pending ); $pass++ ) {
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
				break;
			}

			$pending = $next_pending;
		}

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'complete' => 0 === $skipped,
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
	 * @return array
	 */
	public function assign_product_categories( $product_id, $categories, $auto_categories_enabled, $is_new_product = false ) {
		$product_id              = absint( $product_id );
		$is_new_product          = (bool) $is_new_product;
		$auto_categories_enabled = (bool) $auto_categories_enabled;
		$mapping_enabled         = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_enabled', '1' );
		$mapping_required        = Mobo_Core_Settings::enabled( 'mobo_core_category_mapping_required', '0' );
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

			return array(
				'assigned'     => 0,
				'source'       => 'mapping-required-missing',
				'changed'      => false,
				'missingGuids' => array_values( array_unique( $manual_result['missing_guids'] ) ),
			);
		}

		/*
		 * Automatic category update disabled:
		 * - Manual mapping above still applies.
		 * - New product without mapping gets default category.
		 * - Existing product without mapping remains untouched.
		 */
		if ( ! $auto_categories_enabled ) {
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

			return array(
				'assigned'     => 0,
				'source'       => 'mapping-required-missing',
				'changed'      => false,
				'missingGuids' => array_values( array_unique( $missing_guids ) ),
			);
		}

		/*
		 * No resolved category. Do not overwrite categories on existing products.
		 * New products can still receive the configured default category.
		 */
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
		$identifiers = array();

		if ( ! is_array( $category_ref ) ) {
			$value = sanitize_text_field( (string) $category_ref );
			return $this->is_remote_guid_value( $value ) ? array( $value ) : array();
		}

		/* Explicit category GUID fields first. */
		$primary_keys = array(
			'category_guid',
			'categoryGuid',
			'categoryId',
			'categoryGUID',
			'guid',
			'remote_guid',
			'remoteGuid',
			'portal_category_id',
			'portalCategoryId',
			'category_portal_id',
			'categoryPortalId',
		);

		foreach ( $primary_keys as $key ) {
			$this->append_guid_candidate( $identifiers, $this->get_value( $category_ref, $key, '' ) );
		}

		/* Actual category object, when payload wraps the relation. */
		$nested = $this->get_value( $category_ref, 'category', null );
		if ( is_array( $nested ) ) {
			foreach ( $this->collect_category_guid_candidates( $nested ) as $nested_guid ) {
				$this->append_guid_candidate( $identifiers, $nested_guid );
			}
		} else {
			$this->append_guid_candidate( $identifiers, $nested );
		}

		/* Last-resort compatibility only. These may be relation GUIDs in some payloads. */
		$fallback_keys = array( 'product_category_id', 'productCategoryId', 'product_category_guid', 'productCategoryGuid', 'id' );
		foreach ( $fallback_keys as $key ) {
			$this->append_guid_candidate( $identifiers, $this->get_value( $category_ref, $key, '' ) );
		}

		return array_values( array_unique( array_filter( $identifiers ) ) );
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
		if ( ! is_array( $identifiers ) || empty( $identifiers ) ) {
			return '';
		}

		foreach ( $identifiers as $identifier ) {
			$identifier = sanitize_text_field( (string) $identifier );
			if ( '' !== $identifier && false === strpos( $identifier, '/' ) ) {
				return $identifier;
			}
		}

		return sanitize_text_field( (string) reset( $identifiers ) );
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

		$term_id        = $this->find_term_id_by_guid( $category_guid );
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

		$args = array();
		if ( '' !== $title ) {
			$args['name'] = $title;
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
				$updated_term = wp_update_term( $term_id, 'product_cat', array( 'name' => $title ) );
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

			if ( ! $this->upsert_category_map( $category_guid, $term_id, $title, $url, $parent_guid ) ) {
				return array(
					'term_id'    => $term_id,
					'created'    => false,
					'incomplete' => true,
					'error'      => 'WooCommerce category exists but its Mobo category map could not be persisted.',
				);
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

		$result = wp_insert_term( $title, 'product_cat', $insert_args );
		if ( is_wp_error( $result ) && isset( $insert_args['slug'] ) ) {
			unset( $insert_args['slug'] );
			$result = wp_insert_term( $title, 'product_cat', $insert_args );
		}

		if ( is_wp_error( $result ) || empty( $result['term_id'] ) ) {
			return array(
				'term_id' => 0,
				'created' => false,
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

	public function find_term_id_by_guid( $category_guid ) {
		$category_guid = sanitize_text_field( (string) $category_guid );

		if ( '' === $category_guid ) {
			return 0;
		}

		if ( array_key_exists( $category_guid, $this->term_id_by_guid_cache ) ) {
			return absint( $this->term_id_by_guid_cache[ $category_guid ] );
		}

		if ( $this->category_map instanceof Mobo_Core_Category_Map ) {
			$term_id = $this->category_map->get_synced_term_id( $category_guid );

			if ( $term_id > 0 ) {
				$this->term_id_by_guid_cache[ $category_guid ] = absint( $term_id );
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

		if ( $term_id > 0 ) {
			$this->term_id_by_guid_cache[ $category_guid ] = $term_id;
			$this->upsert_category_map( $category_guid, $term_id, '', '', '' );
		}

		return $term_id;
	}

	private function assign_default_category( $product_id ) {
		$product_id          = absint( $product_id );
		$default_category_id = absint( get_option( 'mobo_default_category_id', 0 ) );

		if ( $product_id <= 0 || $default_category_id <= 0 ) {
			return array(
				'assigned' => 0,
				'source'   => 'default-not-configured',
				'changed'  => false,
			);
		}

		$term = term_exists( $default_category_id, 'product_cat' );

		if ( empty( $term ) || is_wp_error( $term ) ) {
			return array(
				'assigned' => 0,
				'source'   => 'default-missing',
				'changed'  => false,
			);
		}

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

		if ( $product_id <= 0 || empty( $desired ) ) {
			return false;
		}

		$current = $this->get_product_category_ids( $product_id );
		if ( $current === $desired ) {
			return false;
		}

		$result = wp_set_object_terms( $product_id, $desired, 'product_cat', false );
		if ( is_wp_error( $result ) ) {
			$error = sanitize_text_field( $result->get_error_message() );
			if ( '' === $error ) {
				$error = 'WordPress rejected the product category assignment.';
			}
			return false;
		}

		$this->product_category_ids_cache[ $product_id ] = $desired;
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
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return false;
		}

		if ( false !== strpos( $value, '/' ) || false !== strpos( $value, '\\' ) || false !== strpos( $value, '://' ) ) {
			return false;
		}

		return true;
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
			&& $this->set_term_meta_verified( $term_id, 'mobo_category_url', sanitize_text_field( (string) $url ) )
			&& $this->set_term_meta_verified( $term_id, 'mobo_parent_category_guid', sanitize_text_field( (string) $parent_guid ) );
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
		$term_id = absint( $term_id );
		$key     = sanitize_key( (string) $key );
		if ( $term_id <= 0 || '' === $key ) {
			return false;
		}

		update_term_meta( $term_id, $key, $value );
		$stored = get_term_meta( $term_id, $key, true );

		return maybe_serialize( $stored ) === maybe_serialize( $value );
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