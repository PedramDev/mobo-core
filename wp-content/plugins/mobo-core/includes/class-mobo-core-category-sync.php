<?php
/**
 * Category sync service.
 *
 * Payload contract from C#:
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
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Category_Sync {

	/**
	 * Sync categories in the exact order provided by C# and assign them to product.
	 *
	 * Important:
	 * - C# sends parents first.
	 * - id is stored as category_guid.
	 * - title is WooCommerce category name.
	 * - url is converted to slug.
	 * - parentId is resolved through category_guid.
	 *
	 * @param int   $product_id Product ID.
	 * @param mixed $categories Category payload.
	 * @return array
	 */
	public function sync_and_assign_to_product( $product_id, $categories ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! is_array( $categories ) ) {
			return array(
				'assigned' => 0,
				'created'  => 0,
				'updated'  => 0,
				'skipped'  => 0,
			);
		}

		$term_ids = array();
		$created  = 0;
		$updated  = 0;
		$skipped  = 0;

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

			$term_ids[] = absint( $result['term_id'] );

			if ( ! empty( $result['created'] ) ) {
				$created++;
			} else {
				$updated++;
			}
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $product_id, $term_ids, 'product_cat', false );
		}

		return array(
			'assigned' => count( $term_ids ),
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Create or update one WooCommerce product category.
	 *
	 * @param array $category_data Category payload.
	 * @return array
	 */
	public function upsert_category( $category_data ) {
		$category_guid = sanitize_text_field( (string) $this->get_value( $category_data, 'id', '' ) );
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

		/*
		 * If this category already exists, title can be empty and we still keep it.
		 * If it does not exist, title is required to create a meaningful term.
		 */
		if ( $term_id <= 0 && '' === $title ) {
			return array(
				'term_id' => 0,
				'created' => false,
			);
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
			$result = wp_update_term( $term_id, 'product_cat', $args );

			if ( is_wp_error( $result ) ) {
				/*
				 * If slug conflict happens, retry without slug.
				 * This keeps production sync from failing because of old/duplicate slugs.
				 */
				if ( isset( $args['slug'] ) ) {
					unset( $args['slug'] );
					$result = wp_update_term( $term_id, 'product_cat', $args );
				}

				if ( is_wp_error( $result ) ) {
					return array(
						'term_id' => $term_id,
						'created' => false,
					);
				}
			}

			update_term_meta( $term_id, 'category_guid', $category_guid );
			update_term_meta( $term_id, 'mobo_category_url', $url );
			update_term_meta( $term_id, 'mobo_parent_category_guid', $parent_guid );

			return array(
				'term_id' => $term_id,
				'created' => false,
			);
		}

		$insert_args = $args;

		if ( empty( $insert_args['name'] ) ) {
			$insert_args['name'] = $category_guid;
		}

		$result = wp_insert_term( $insert_args['name'], 'product_cat', $insert_args );

		if ( is_wp_error( $result ) ) {
			/*
			 * Retry without slug if slug already exists.
			 */
			if ( isset( $insert_args['slug'] ) ) {
				unset( $insert_args['slug'] );
				$result = wp_insert_term( $insert_args['name'], 'product_cat', $insert_args );
			}

			if ( is_wp_error( $result ) ) {
				return array(
					'term_id' => 0,
					'created' => false,
				);
			}
		}

		$term_id = ! empty( $result['term_id'] ) ? absint( $result['term_id'] ) : 0;

		if ( $term_id <= 0 ) {
			return array(
				'term_id' => 0,
				'created' => false,
			);
		}

		update_term_meta( $term_id, 'category_guid', $category_guid );
		update_term_meta( $term_id, 'mobo_category_url', $url );
		update_term_meta( $term_id, 'mobo_parent_category_guid', $parent_guid );

		return array(
			'term_id' => $term_id,
			'created' => true,
		);
	}

	/**
	 * Find product category by category_guid.
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
	 * Create a stable slug from category url.
	 *
	 * Examples:
	 * /products/case      => case
	 * /takhfif            => takhfif
	 * /products/iphone/15 => 15
	 *
	 * This matches your C# ordering logic where URL path determines hierarchy/slug.
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