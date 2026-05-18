<?php
/**
 * Category sync service.
 *
 * Full category API payload:
 * [
 *   {
 *     "id": "609f97c4-2011-4186-8729-e1aa8a798c3a",
 *     "title": "🏷️تخفیف 🏷️",
 *     "url": "/takhfif",
 *     "parentId": null
 *   },
 *   {
 *     "id": "e753567b-e764-4981-bb0f-2df38414854f",
 *     "title": "محصولات",
 *     "url": "/products",
 *     "parentId": null
 *   },
 *   {
 *     "id": "0e6426d4-7039-485e-93c2-12e5812ab662",
 *     "title": "قاب و کاور گوشی",
 *     "url": "/products/case",
 *     "parentId": "e753567b-e764-4981-bb0f-2df38414854f"
 *   }
 * ]
 *
 * Product category reference payload:
 * [
 *   {
 *     "categoryId": "0e6426d4-7039-485e-93c2-12e5812ab662"
 *   }
 * ]
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Category_Sync {

	/**
	 * Sync full categories payload from API.
	 *
	 * Accepts:
	 * - direct array of categories
	 * - paged object with data[]
	 *
	 * @param mixed $payload Category payload.
	 * @return array
	 */
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
	 * If automatic categories are disabled:
	 * - use mobo_default_category_id if configured.
	 *
	 * If automatic categories are enabled:
	 * - find terms by category_guid from productCategories[].categoryId.
	 *
	 * @param int   $product_id Product ID.
	 * @param mixed $categories Product category refs.
	 * @param bool  $auto_categories_enabled Whether global_update_categories is enabled.
	 * @return array
	 */
	public function assign_product_categories( $product_id, $categories, $auto_categories_enabled ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return array(
				'assigned' => 0,
				'source'   => 'none',
			);
		}

		if ( ! $auto_categories_enabled ) {
			return $this->assign_default_category( $product_id );
		}

		if ( ! is_array( $categories ) ) {
			return array(
				'assigned' => 0,
				'source'   => 'auto',
			);
		}

		$term_ids = array();

		foreach ( $categories as $category_ref ) {
			if ( ! is_array( $category_ref ) ) {
				continue;
			}

			$category_guid = $this->get_category_guid( $category_ref );

			if ( '' === $category_guid ) {
				continue;
			}

			$term_id = $this->find_term_id_by_guid( $category_guid );

			if ( $term_id > 0 ) {
				$term_ids[] = $term_id;
			}
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
		}

		return array(
			'assigned' => count( $term_ids ),
			'source'   => 'auto',
		);
	}

/**
 * Create or update one WooCommerce product category.
 *
 * Critical rule:
 * category_guid must be persisted immediately after term creation.
 * If host shuts down mid-sync, next run must find the same category
 * and continue instead of creating duplicate categories.
 *
 * Mapping:
 * id       -> category_guid
 * title    -> term name
 * url      -> slug
 * parentId -> parent category_guid
 *
 * @param array $category_data Category payload.
 * @return array
 */
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
		update_term_meta( $term_id, 'mobo_sync_incomplete', '0' );

		return array(
			'term_id' => $term_id,
			'created' => false,
		);
	}

	/*
	 * Critical early creation:
	 * Create a minimal term first, then immediately persist category_guid.
	 */
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

	/*
	 * Persist GUID immediately after term exists.
	 */
	update_term_meta( $term_id, 'category_guid', $category_guid );
	update_term_meta( $term_id, 'mobo_sync_incomplete', '1' );

	/*
	 * Now safely apply full data including parent.
	 */
	$result = wp_update_term( $term_id, 'product_cat', $args );

	if ( is_wp_error( $result ) && isset( $args['slug'] ) ) {
		unset( $args['slug'] );
		$result = wp_update_term( $term_id, 'product_cat', $args );
	}

	$this->save_category_meta( $term_id, $category_guid, $url, $parent_guid );
	update_term_meta( $term_id, 'mobo_sync_incomplete', '0' );

	return array(
		'term_id' => $term_id,
		'created' => true,
	);
}

	/**
	 * Find product category term by category_guid.
	 *
	 * @param string $category_guid Category GUID.
	 * @return int
	 */
	public function find_term_id_by_guid( $category_guid ) {
		$category_guid = sanitize_text_field( (string) $category_guid );

		if ( '' === $category_guid ) {
			return 0;
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

		return absint( $terms[0]->term_id );
	}

	/**
	 * Assign configured default category.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function assign_default_category( $product_id ) {
		$product_id          = absint( $product_id );
		$default_category_id = absint( get_option( 'mobo_default_category_id', 0 ) );

		if ( $product_id <= 0 || $default_category_id <= 0 ) {
			return array(
				'assigned' => 0,
				'source'   => 'disabled',
			);
		}

		$term = term_exists( $default_category_id, 'product_cat' );

		if ( empty( $term ) || is_wp_error( $term ) ) {
			return array(
				'assigned' => 0,
				'source'   => 'default-missing',
			);
		}

		wp_set_object_terms( $product_id, array( $default_category_id ), 'product_cat', false );

		return array(
			'assigned' => 1,
			'source'   => 'default',
		);
	}

	/**
	 * Extract category GUID from either full category payload or product category reference.
	 *
	 * Supported keys:
	 * - id
	 * - categoryId
	 * - categoryGuid
	 *
	 * @param array $category_data Category data.
	 * @return string
	 */
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

	/**
	 * Save category meta.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $category_guid Category GUID.
	 * @param string $url Source URL.
	 * @param string $parent_guid Parent GUID.
	 * @return void
	 */
	private function save_category_meta( $term_id, $category_guid, $url, $parent_guid ) {
		$term_id = absint( $term_id );

		if ( $term_id <= 0 ) {
			return;
		}

		update_term_meta( $term_id, 'category_guid', sanitize_text_field( (string) $category_guid ) );
		update_term_meta( $term_id, 'mobo_category_url', sanitize_text_field( (string) $url ) );
		update_term_meta( $term_id, 'mobo_parent_category_guid', sanitize_text_field( (string) $parent_guid ) );
	}

	/**
	 * Create slug from category URL.
	 *
	 * Examples:
	 * /products/case      -> case
	 * /takhfif            -> takhfif
	 * /products/iphone/15 -> 15
	 *
	 * @param string $url Category URL.
	 * @return string
	 */
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

	/**
	 * Case-tolerant getter.
	 *
	 * @param array  $array Source array.
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
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