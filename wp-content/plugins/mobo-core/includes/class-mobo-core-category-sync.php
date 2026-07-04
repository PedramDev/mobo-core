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

		if ( $product_id <= 0 ) {
			return array(
				'assigned' => 0,
				'source'   => 'none',
				'changed'  => false,
			);
		}

		/*
		* Auto category update disabled:
		* - new product gets default category
		* - existing product must remain unchanged
		*/
		if ( ! $auto_categories_enabled ) {
			if ( $is_new_product ) {
				return $this->assign_default_category( $product_id );
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
		$category_refs  = is_array( $categories ) ? $categories : array();

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
				$resolved = $this->category_map->resolve_assignment_term( $category_guid );
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
					$source = ! empty( $created['created'] ) ? 'auto-created' : 'auto-updated';
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
		* Important fallback:
		* Auto category is enabled, but API categories were missing/not found.
		* Use default category in every case.
		*/
		$result = $this->assign_default_category( $product_id );

		if ( ! empty( $result['changed'] ) ) {
			$result['source'] = 'auto-fallback-default';
			update_post_meta( $product_id, 'mobo_category_assign_source', 'auto-fallback-default' );
		}

		return $result;
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
			update_term_meta( $term_id, 'category_guid', $category_guid );
			update_term_meta( $term_id, 'mobo_sync_incomplete', '1' );

			$result = wp_update_term( $term_id, 'product_cat', $args );

			if ( is_wp_error( $result ) && isset( $args['slug'] ) ) {
				unset( $args['slug'] );
				$result = wp_update_term( $term_id, 'product_cat', $args );
			}

			if ( is_wp_error( $result ) ) {
				return array(
					'term_id' => $term_id,
					'created' => false,
				);
			}

			$this->save_category_meta( $term_id, $category_guid, $url, $parent_guid );
			$this->upsert_category_map( $category_guid, $term_id, $title, $url, $parent_guid );
			update_term_meta( $term_id, 'mobo_sync_incomplete', '0' );

			return array(
				'term_id' => $term_id,
				'created' => false,
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
		$keys = array(
			'id',
			'categoryId',
			'categoryGuid',
		);

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $category_data, $key, '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
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