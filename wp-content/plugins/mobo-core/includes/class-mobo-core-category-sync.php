<?php
/**
 * Category sync service.
 *
 * Payload contract:
 * [
 *   {
 *     "id": "609f97c4-2011-4186-8729-e1aa8a798c3a",
 *     "title": "🏷️تخفیف 🏷️",
 *     "url": "/takhfif",
 *     "parentId": null
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

		if ( is_wp_error( $result ) && isset( $insert_args['slug'] ) ) {
			unset( $insert_args['slug'] );
			$result = wp_insert_term( $insert_args['name'], 'product_cat', $insert_args );
		}

		if ( is_wp_error( $result ) || empty( $result['term_id'] ) ) {
			return array(
				'term_id' => 0,
				'created' => false,
			);
		}

		$term_id = absint( $result['term_id'] );

		$this->save_category_meta( $term_id, $category_guid, $url, $parent_guid );

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
	 * Save category meta.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $category_guid Category GUID.
	 * @param string $url Category URL.
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
	 * @param array  $array Source.
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