<?php
/**
 * Image sync service.
 *
 * Chunk-safe WooCommerce image sync.
 *
 * Preserves:
 * - image_guid
 * - img_guid
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Image_Sync {

	/**
	 * Process product images with offset.
	 *
	 * Expected image payload:
	 * [
	 *   {
	 *     "id": "image-guid",
	 *     "url": "https://example.com/image.jpg"
	 *   }
	 * ]
	 *
	 * Also supports:
	 * - imageId
	 * - imageGuid
	 * - guid
	 * - src
	 *
	 * @param int   $product_id Product ID.
	 * @param mixed $images Images.
	 * @param int   $offset Offset.
	 * @return array
	 */
	public function process_images( $product_id, $images, $offset ) {
		$product_id = absint( $product_id );
		$offset     = max( 0, absint( $offset ) );
		$limit      = Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 1, 0, 10 );

		if ( $product_id <= 0 || ! is_array( $images ) || empty( $images ) || $limit <= 0 ) {
			return array(
				'done'       => true,
				'nextOffset' => 0,
				'processed'  => 0,
				'skipped'    => 0,
			);
		}

		$this->load_media_dependencies();

		$total       = count( $images );
		$processed   = 0;
		$skipped     = 0;
		$index       = $offset;
		$gallery_ids = $this->get_existing_gallery_ids( $product_id );

		while ( $index < $total && $processed < $limit ) {
			$image = isset( $images[ $index ] ) && is_array( $images[ $index ] ) ? $images[ $index ] : array();

			$image_guid = $this->get_image_guid( $image );
			$url        = $this->get_image_url( $image );

			if ( '' === $image_guid || '' === $url ) {
				$skipped++;
				$processed++;
				$index++;
				continue;
			}

			$attachment_id = $this->find_attachment_by_guid( $image_guid );

			if ( $attachment_id <= 0 ) {
				$attachment_id = $this->download_image( $url, $product_id, $image_guid );
			}

			if ( $attachment_id > 0 ) {
				if ( 0 === $index ) {
					set_post_thumbnail( $product_id, $attachment_id );
				} elseif ( ! in_array( $attachment_id, $gallery_ids, true ) ) {
					$gallery_ids[] = $attachment_id;
				}
			} else {
				$skipped++;
			}

			$processed++;
			$index++;
		}

		$this->save_gallery_ids( $product_id, $gallery_ids );

		return array(
			'done'       => $index >= $total,
			'nextOffset' => $index >= $total ? 0 : $index,
			'processed'  => $processed,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Load WordPress media dependencies.
	 *
	 * @return void
	 */
	private function load_media_dependencies() {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Download image and attach to product.
	 *
	 * @param string $url Image URL.
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @return int
	 */
	private function download_image( $url, $product_id, $image_guid ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );

		if ( '' === $url || $product_id <= 0 || '' === $image_guid ) {
			return 0;
		}

		$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 ) {
			return 0;
		}

		update_post_meta( $attachment_id, 'image_guid', $image_guid );
		update_post_meta( $attachment_id, 'img_guid', $image_guid );
		update_post_meta( $attachment_id, 'mobo_source_url', $url );

		return $attachment_id;
	}

	/**
	 * Find attachment by image_guid or img_guid.
	 *
	 * @param string $guid Image GUID.
	 * @return int
	 */
	private function find_attachment_by_guid( $guid ) {
		$guid = sanitize_text_field( (string) $guid );

		if ( '' === $guid ) {
			return 0;
		}

		$id = $this->find_attachment_by_meta( 'image_guid', $guid );

		if ( $id <= 0 ) {
			$id = $this->find_attachment_by_meta( 'img_guid', $guid );
		}

		return $id;
	}

	/**
	 * Find attachment by meta key/value.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $meta_value Meta value.
	 * @return int
	 */
	private function find_attachment_by_meta( $meta_key, $meta_value ) {
		$meta_key   = sanitize_key( $meta_key );
		$meta_value = sanitize_text_field( (string) $meta_value );

		if ( '' === $meta_key || '' === $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	/**
	 * Get existing gallery IDs.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_existing_gallery_ids( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return array();
		}

		$gallery = get_post_meta( $product_id, '_product_image_gallery', true );

		if ( ! is_string( $gallery ) || '' === $gallery ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) ) );
	}

	/**
	 * Save gallery IDs.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $gallery_ids Gallery IDs.
	 * @return void
	 */
	private function save_gallery_ids( $product_id, $gallery_ids ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );

		if ( empty( $gallery_ids ) ) {
			return;
		}

		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
	}

	/**
	 * Extract image GUID.
	 *
	 * Supported keys:
	 * - id
	 * - imageId
	 * - imageGuid
	 * - guid
	 *
	 * @param array $image Image data.
	 * @return string
	 */
	private function get_image_guid( $image ) {
		$keys = array(
			'id',
			'imageId',
			'imageGuid',
			'guid',
		);

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $image, $key, '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract image URL.
	 *
	 * Supported keys:
	 * - url
	 * - src
	 *
	 * @param array $image Image data.
	 * @return string
	 */
	private function get_image_url( $image ) {
		$keys = array(
			'url',
			'src',
		);

		foreach ( $keys as $key ) {
			$value = esc_url_raw( (string) $this->get_value( $image, $key, '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
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