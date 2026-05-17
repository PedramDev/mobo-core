<?php
/**
 * Image sync service.
 *
 * Uses image_guid/img_guid and processes images in chunks.
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Image_Sync {

	/**
	 * Process product images with offset.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
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
			);
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$total       = count( $images );
		$processed   = 0;
		$index       = $offset;
		$gallery_ids = $this->get_existing_gallery_ids( $product_id );

		while ( $index < $total && $processed < $limit ) {
			$image = isset( $images[ $index ] ) && is_array( $images[ $index ] ) ? $images[ $index ] : array();

			$image_guid = isset( $image['id'] ) ? sanitize_text_field( (string) $image['id'] ) : '';
			$url        = isset( $image['url'] ) ? esc_url_raw( (string) $image['url'] ) : '';

			if ( '' !== $image_guid && '' !== $url ) {
				$attachment_id = $this->find_attachment_by_guid( $image_guid );

				if ( $attachment_id <= 0 ) {
					$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );

					if ( ! is_wp_error( $attachment_id ) && absint( $attachment_id ) > 0 ) {
						$attachment_id = absint( $attachment_id );
						update_post_meta( $attachment_id, 'image_guid', $image_guid );
						update_post_meta( $attachment_id, 'img_guid', $image_guid );
						update_post_meta( $attachment_id, 'mobo_source_url', $url );
					}
				}

				if ( ! is_wp_error( $attachment_id ) && absint( $attachment_id ) > 0 ) {
					$attachment_id = absint( $attachment_id );

					if ( 0 === $index ) {
						set_post_thumbnail( $product_id, $attachment_id );
					} elseif ( ! in_array( $attachment_id, $gallery_ids, true ) ) {
						$gallery_ids[] = $attachment_id;
					}
				}
			}

			$processed++;
			$index++;
		}

		$this->save_gallery_ids( $product_id, $gallery_ids );

		return array(
			'done'       => $index >= $total,
			'nextOffset' => $index >= $total ? 0 : $index,
			'processed'  => $processed,
		);
	}

	/**
	 * Get existing gallery IDs.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_existing_gallery_ids( $product_id ) {
		$gallery = get_post_meta( $product_id, '_product_image_gallery', true );

		if ( ! is_string( $gallery ) || '' === $gallery ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) );
	}

	/**
	 * Save gallery IDs.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $gallery_ids Gallery IDs.
	 * @return void
	 */
	private function save_gallery_ids( $product_id, $gallery_ids ) {
		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );

		if ( empty( $gallery_ids ) ) {
			return;
		}

		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
	}

	/**
	 * Find attachment by image_guid/img_guid.
	 *
	 * @param string $guid GUID.
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
	 * Find attachment by meta.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $meta_value Meta value.
	 * @return int
	 */
	private function find_attachment_by_meta( $meta_key, $meta_value ) {
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
						'key'   => sanitize_key( $meta_key ),
						'value' => sanitize_text_field( (string) $meta_value ),
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}
}