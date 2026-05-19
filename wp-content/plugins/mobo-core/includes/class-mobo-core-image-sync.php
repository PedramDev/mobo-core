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
				update_post_meta( $attachment_id, 'image_guid', $image_guid );
				update_post_meta( $attachment_id, 'img_guid', $image_guid );
				update_post_meta( $attachment_id, 'mobo_source_url', $url );
				update_post_meta( $attachment_id, 'mobo_sync_incomplete', '0' );

				if ( 0 === $index ) {
					set_post_thumbnail( $product_id, $attachment_id );
					update_post_meta( $product_id, '_thumbnail_id', $attachment_id );
				}

				/*
				 * Product Image must also be included in Product Gallery.
				 */
				if ( ! in_array( $attachment_id, $gallery_ids, true ) ) {
					$gallery_ids[] = $attachment_id;
				}
			} else {
				$skipped++;
			}

			$processed++;
			$index++;
		}

		$this->save_gallery_ids( $product_id, $gallery_ids );
		$this->sync_woocommerce_product_image_objects( $product_id, $gallery_ids );

		return array(
			'done'       => $index >= $total,
			'nextOffset' => $index >= $total ? 0 : $index,
			'processed'  => $processed,
			'skipped'    => $skipped,
		);
	}

	private function load_media_dependencies() {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	private function download_image( $url, $product_id, $image_guid ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );

		if ( '' === $url || $product_id <= 0 || '' === $image_guid ) {
			return 0;
		}

		$existing_id = $this->find_attachment_by_guid( $image_guid );

		if ( $existing_id > 0 ) {
			return $existing_id;
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
		update_post_meta( $attachment_id, 'mobo_sync_incomplete', '0' );

		return $attachment_id;
	}

	private function sync_woocommerce_product_image_objects( $product_id, $gallery_ids ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$thumbnail_id = absint( get_post_thumbnail_id( $product_id ) );

		if ( $thumbnail_id > 0 ) {
			$product->set_image_id( $thumbnail_id );
		}

		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );

		if ( ! empty( $gallery_ids ) ) {
			$product->set_gallery_image_ids( $gallery_ids );
		} else {
			$product->set_gallery_image_ids( array() );
		}

		$product->save();

		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
	}

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

	private function save_gallery_ids( $product_id, $gallery_ids ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );

		if ( empty( $gallery_ids ) ) {
			delete_post_meta( $product_id, '_product_image_gallery' );
			return;
		}

		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
	}

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