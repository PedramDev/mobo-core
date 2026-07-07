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
			$title         = sanitize_text_field( (string) $this->get_value( $category_data, 'title', '' ) );
			$url           = sanitize_text_field( (string) $this->get_value( $category_data, 'url', '' ) );
			$parent_guid   = sanitize_text_field( (string) $this->get_value( $category_data, 'parentId', '' ) );

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
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
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

		foreach ( $categories as $category_data ) {
			if ( ! is_array( $category_data ) ) {
				$skipped++;
				continue;
			}

			$result = $this->upsert_category( $category_data );

			if ( empty( $result['term_id'] ) ) {
				$skipped++;
				continue;
			}

			if ( ! empty( $result['created'] ) ) {
				$created++;
			} else {
				$updated++;
			}
		}

		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
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
				wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
				update_post_meta( $product_id, 'mobo_category_assign_source', 'manual-mapping' );

				if ( ! empty( $manual_result['missing_guids'] ) ) {
					update_post_meta( $product_id, 'mobo_category_missing_guids', array_values( array_unique( $manual_result['missing_guids'] ) ) );
				} else {
					delete_post_meta( $product_id, 'mobo_category_missing_guids' );
				}

				return array(
					'assigned'     => count( $term_ids ),
					'source'       => 'manual-mapping',
					'changed'      => true,
					'missingGuids' => array_values( array_unique( $manual_result['missing_guids'] ) ),
				);
			}
		}

		if ( $mapping_required && ! empty( $manual_result['missing_guids'] ) ) {
			update_post_meta( $product_id, 'mobo_category_assign_source', 'mapping-required-missing' );
			update_post_meta( $product_id, 'mobo_category_missing_guids', array_values( array_unique( $manual_result['missing_guids'] ) ) );

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
					update_post_meta( $product_id, 'mobo_category_assign_source', 'auto-disabled-new-default' );
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

		if ( ! empty( $missing_guids ) ) {
			update_post_meta( $product_id, 'mobo_category_missing_guids', array_values( array_unique( $missing_guids ) ) );
		} else {
			delete_post_meta( $product_id, 'mobo_category_missing_guids' );
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
			update_post_meta( $product_id, 'mobo_category_assign_source', implode( ',', $sources ) );

			return array(
				'assigned'      => count( $term_ids ),
				'source'        => ! empty( $sources ) ? implode( ',', $sources ) : 'mapped-or-synced',
				'changed'       => true,
				'missingGuids'  => array_values( array_unique( $missing_guids ) ),
			);
		}

		if ( $mapping_required && ! empty( $missing_guids ) ) {
			update_post_meta( $product_id, 'mobo_category_assign_source', 'mapping-required-missing' );

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
				update_post_meta( $product_id, 'mobo_category_assign_source', 'new-product-default' );
			}

			return $result;
		}

		update_post_meta( $product_id, 'mobo_category_assign_source', 'existing-product-category-unchanged' );

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
		$title         = sanitize_text_field( (string) $this->get_value( $category_data, 'title', '' ) );
		$url           = sanitize_text_field( (string) $this->get_value( $category_data, 'url', '' ) );
		$parent_guid   = sanitize_text_field( (string) $this->get_value( $category_data, 'parentId', '' ) );

		if ( '' === $category_guid ) {
			return array(
				'term_id' => 0,
				'created' => false,
			);
		}

		$term_id = $this->find_term_id_by_guid( $category_guid );

		if ( $term_id <= 0 && '' === $title ) {
			$title = 'Mobo Category ' . $category_guid;
		}

		$args = array();

		if ( '' !== $title ) {
			$args['name'] = $title;
		}

		$slug = $this->slug_from_url( $url );

		if ( '' !== $slug ) {
			$args['slug'] = $slug;
		}

		if ( '' !== $parent_guid ) {
			$parent_term_id = $this->find_term_id_by_guid( $parent_guid );

			if ( $parent_term_id > 0 ) {
				$args['parent'] = $parent_term_id;
			}
		} else {
			$args['parent'] = 0;
		}

		if ( $term_id > 0 ) {
			// Existing WooCommerce categories are protected by default.
			// Keep the local category name, slug, parent and metadata untouched; only refresh the internal mapping.
			$this->upsert_category_map( $category_guid, $term_id, $title, $url, $parent_guid );

			return array(
				'term_id'        => $term_id,
				'created'        => false,
				'skipped_update' => true,
			);
		}

		$insert_name = '' !== $title ? $title : 'Mobo Category ' . $category_guid;

		$insert_args = array();

		if ( isset( $args['slug'] ) ) {
			$insert_args['slug'] = $args['slug'];
		}

		$result = wp_insert_term( $insert_name, 'product_cat', $insert_args );

		if ( is_wp_error( $result ) && isset( $insert_args['slug'] ) ) {
			unset( $insert_args['slug'] );
			$result = wp_insert_term( $insert_name, 'product_cat', $insert_args );
		}

		if ( is_wp_error( $result ) || empty( $result['term_id'] ) ) {
			return array(
				'term_id' => 0,
				'created' => false,
			);
		}

		$term_id = absint( $result['term_id'] );

		update_term_meta( $term_id, 'category_guid', $category_guid );
		update_term_meta( $term_id, 'mobo_sync_incomplete', '1' );

		$result = wp_update_term( $term_id, 'product_cat', $args );

		if ( is_wp_error( $result ) && isset( $args['slug'] ) ) {
			unset( $args['slug'] );
			$result = wp_update_term( $term_id, 'product_cat', $args );
		}

		$this->save_category_meta( $term_id, $category_guid, $url, $parent_guid );
		$this->upsert_category_map( $category_guid, $term_id, $insert_name, $url, $parent_guid );
		update_term_meta( $term_id, 'mobo_sync_incomplete', '0' );

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

		if ( $this->category_map instanceof Mobo_Core_Category_Map ) {
			$term_id = $this->category_map->get_synced_term_id( $category_guid );

			if ( $term_id > 0 ) {
				return $term_id;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array(
					array(
						'key'   => 'category_guid',
						'value' => $category_guid,
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms[0] ) ) {
			return 0;
		}

		$term_id = absint( $terms[0]->term_id );

		if ( $term_id > 0 ) {
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

		wp_set_object_terms( $product_id, array( $default_category_id ), 'product_cat', false );

		return array(
			'assigned' => 1,
			'source'   => 'default',
			'changed'  => true,
		);
	}

	private function get_category_guid( $category_data ) {
		$identifiers = $this->collect_category_guid_candidates( $category_data );

		return ! empty( $identifiers ) ? sanitize_text_field( (string) $identifiers[0] ) : '';
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
			return;
		}

		$this->category_map->upsert_synced_category( $category_guid, $term_id, $name, $url, $parent_guid );
	}

	private function save_category_meta( $term_id, $category_guid, $url, $parent_guid ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return;
		}

		update_term_meta( $term_id, 'category_guid', sanitize_text_field( (string) $category_guid ) );
		update_term_meta( $term_id, 'mobo_category_url', sanitize_text_field( (string) $url ) );
		update_term_meta( $term_id, 'mobo_parent_category_guid', sanitize_text_field( (string) $parent_guid ) );
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