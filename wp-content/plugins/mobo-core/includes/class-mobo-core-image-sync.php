<?php
/**
 * Image sync service.
 *
 * Chunk-safe WooCommerce image sync with optional table-backed queue.
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
	 * Process images for a product.
	 *
	 * When the image queue is enabled, image rows are first upserted into the
	 * queue table and then a bounded number of due rows is processed. This makes
	 * image sync resumable and avoids re-downloading attachments that already
	 * exist by image GUID or source URL.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
	 * @param int   $offset Legacy offset kept for backward compatibility.
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

		if ( $this->should_use_queue() ) {
			return $this->process_images_with_queue( $product_id, $images, $limit );
		}

		return $this->process_images_direct( $product_id, $images, $offset, $limit );
	}

	/**
	 * Process due image queue rows across products.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function process_queue( $limit = 0 ) {
		if ( ! $this->should_use_queue() ) {
			return array(
				'success'   => true,
				'status'    => 'disabled',
				'processed' => 0,
				'failed'    => 0,
				'remaining' => false,
			);
		}

		$limit = $limit > 0 ? absint( $limit ) : Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 1, 0, 10 );

		if ( $limit <= 0 ) {
			$queue = new Mobo_Core_Image_Queue();
			return array(
				'success'   => true,
				'status'    => 'disabled-by-limit',
				'processed' => 0,
				'failed'    => 0,
				'remaining' => $queue->count_due() > 0,
			);
		}

		$queue = new Mobo_Core_Image_Queue();
		$rows  = $queue->get_due_images( $limit );

		return $this->process_queue_rows( $queue, $rows, $limit );
	}

	/**
	 * Return compact queue status.
	 *
	 * @return array
	 */
	public function get_queue_status() {
		if ( ! class_exists( 'Mobo_Core_Image_Queue' ) || ! Mobo_Core_Image_Queue::table_exists() ) {
			return array(
				'enabled' => false,
				'pending' => 0,
				'due'     => 0,
				'failed'  => 0,
			);
		}

		$queue = new Mobo_Core_Image_Queue();

		return $queue->get_status();
	}

	/**
	 * Table-backed image processing for one product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function process_images_with_queue( $product_id, $images, $limit ) {
		$queue        = new Mobo_Core_Image_Queue();
		$product_guid = $this->get_product_guid( $product_id );

		$enqueue = $queue->enqueue_product_images( $product_id, $product_guid, $images );
		$rows    = $queue->get_due_product_images( $product_id, $limit );
		$result  = $this->process_queue_rows( $queue, $rows, $limit );

		$this->sync_woocommerce_product_image_objects_from_queue( $product_id, $queue );

		$pending  = $queue->count_pending_by_product( $product_id, false );
		$blocking = Mobo_Core_Settings::enabled( 'mobo_core_image_queue_blocking', '1' );

		return array(
			'done'       => $blocking ? 0 === $pending : true,
			'nextOffset' => $blocking && $pending > 0 ? 1 : 0,
			'processed'  => isset( $result['processed'] ) ? absint( $result['processed'] ) : 0,
			'skipped'    => isset( $enqueue['skipped'] ) ? absint( $enqueue['skipped'] ) : 0,
			'queued'     => isset( $enqueue['enqueued'] ) ? absint( $enqueue['enqueued'] ) : 0,
			'pending'    => $pending,
		);
	}

	/**
	 * Legacy direct chunk image processing fallback.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images Images.
	 * @param int   $offset Offset.
	 * @param int   $limit Limit.
	 * @return array
	 */
	private function process_images_direct( $product_id, $images, $offset, $limit ) {
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

			$attachment_id = $this->find_existing_attachment( $image_guid, $url );

			if ( $attachment_id <= 0 ) {
				$attachment_id = $this->download_image( $url, $product_id, $image_guid );
			}

			if ( $attachment_id > 0 ) {
				$this->mark_attachment_synced( $attachment_id, $image_guid, $url );

				if ( 0 === $index ) {
					set_post_thumbnail( $product_id, $attachment_id );
				}

				if ( ! in_array( $attachment_id, $gallery_ids, true ) ) {
					$gallery_ids[] = $attachment_id;
				}
			} else {
				$skipped++;
			}

			$processed++;
			$index++;
		}

		$this->sync_woocommerce_product_image_objects( $product_id, $gallery_ids );

		return array(
			'done'       => $index >= $total,
			'nextOffset' => $index >= $total ? 0 : $index,
			'processed'  => $processed,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Process queue rows.
	 *
	 * @param Mobo_Core_Image_Queue $queue Queue.
	 * @param array                 $rows Rows.
	 * @param int                   $limit Limit.
	 * @return array
	 */
	private function process_queue_rows( Mobo_Core_Image_Queue $queue, $rows, $limit ) {
		$limit     = max( 1, min( 50, absint( $limit ) ) );
		$processed = 0;
		$failed    = 0;
		$touched   = array();

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array(
				'success'   => true,
				'status'    => 'empty',
				'processed' => 0,
				'failed'    => 0,
				'remaining' => $queue->count_due() > 0,
			);
		}

		$this->load_media_dependencies();

		foreach ( $rows as $row ) {
			if ( $processed >= $limit ) {
				break;
			}

			$id         = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			$image_guid = isset( $row['image_guid'] ) ? sanitize_text_field( (string) $row['image_guid'] ) : '';
			$url        = isset( $row['source_url'] ) ? esc_url_raw( (string) $row['source_url'] ) : '';

			if ( $id <= 0 || $product_id <= 0 || '' === $image_guid || '' === $url ) {
				if ( $id > 0 ) {
					$queue->mark_failure( $id, 'Invalid image queue row.', isset( $row['try_count'] ) ? absint( $row['try_count'] ) + 1 : 1, true );
				}

				$failed++;
				continue;
			}

			if ( ! $queue->lock( $id, 120 ) ) {
				continue;
			}

			$attachment_id = $this->find_existing_attachment( $image_guid, $url );

			if ( $attachment_id <= 0 ) {
				$attachment_id = $this->download_image( $url, $product_id, $image_guid );
			}

			if ( $attachment_id > 0 ) {
				$this->mark_attachment_synced( $attachment_id, $image_guid, $url );
				$queue->mark_done( $id, $attachment_id );
				$touched[ $product_id ] = true;
				$processed++;
				continue;
			}

			$try_count = isset( $row['try_count'] ) ? absint( $row['try_count'] ) + 1 : 1;
			$max_try   = Mobo_Core_Settings::get_int( 'mobo_core_image_max_try', 5, 1, 20 );

			$queue->mark_failure( $id, 'Image download failed.', $try_count, $try_count >= $max_try );
			$failed++;
		}

		foreach ( array_keys( $touched ) as $product_id ) {
			$this->sync_woocommerce_product_image_objects_from_queue( absint( $product_id ), $queue );
		}

		return array(
			'success'   => true,
			'status'    => 'processed',
			'processed' => $processed,
			'failed'    => $failed,
			'remaining' => $queue->count_due() > 0,
		);
	}

	private function should_use_queue() {
		return class_exists( 'Mobo_Core_Image_Queue' )
			&& Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' )
			&& Mobo_Core_Image_Queue::table_exists();
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

		$existing_id = $this->find_existing_attachment( $image_guid, $url );

		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		if ( $this->is_local_or_private_image_url( $url ) ) {
			$attachment_id = $this->download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid );
		} else {
			$disable_sslverify = static function ( $args, $request_url ) {
				$args['sslverify'] = false;

				return $args;
			};

			$allow_local_image_host = $this->build_safe_local_image_host_filter( $url );

			add_filter( 'http_request_args', $disable_sslverify, 10, 2 );
			add_filter( 'http_request_host_is_external', $allow_local_image_host, 10, 3 );

			try {
				$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
			} finally {
				remove_filter( 'http_request_args', $disable_sslverify, 10 );
				remove_filter( 'http_request_host_is_external', $allow_local_image_host, 10 );
			}

			if ( is_wp_error( $attachment_id ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'Mobo Core image sideload failed, trying unsafe-local fallback: ' . $attachment_id->get_error_message() );
				}

				$attachment_id = $this->download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid );
			}
		}

		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 ) {
			return 0;
		}

		$this->mark_attachment_synced( $attachment_id, $image_guid, $url );

		return $attachment_id;
	}


	/**
	 * Build a temporary whitelist filter for WordPress safe HTTP requests.
	 *
	 * WordPress blocks localhost/private hosts in download_url()/media_sideload_image()
	 * via wp_safe_remote_get(). During local WAMP + local .NET tests, images may be
	 * served from 127.0.0.1 or localhost. This filter only allows the exact image
	 * host for the duration of the sideload request.
	 *
	 * @param string $url Image URL.
	 * @return callable
	 */

	/**
	 * Download an image using wp_remote_get with reject_unsafe_urls disabled.
	 *
	 * This is only a fallback for local/dev environments where WordPress rejects
	 * localhost/private IP URLs before media_sideload_image() can download them.
	 * It still imports the file via media_handle_sideload(), so WordPress validates
	 * the file type before creating the attachment.
	 *
	 * @param string $url Image URL.
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @return int Attachment ID or 0.
	 */
	private function download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid ) {
		$url        = esc_url_raw( (string) $url );
		$product_id = absint( $product_id );
		$image_guid = sanitize_text_field( (string) $image_guid );

		if ( '' === $url || $product_id <= 0 ) {
			return 0;
		}

		$file_name = $this->get_download_file_name( $url, $image_guid );
		$tmp_file  = wp_tempnam( $file_name );

		if ( ! $tmp_file ) {
			return 0;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 30,
				'redirection'        => 5,
				'sslverify'          => false,
				'reject_unsafe_urls' => false,
				'stream'             => true,
				'filename'           => $tmp_file,
				'headers'            => array(
					'User-Agent' => 'Mobo Core/' . ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : 'dev' ) . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Mobo Core image fallback download failed: ' . $response->get_error_message() );
			}

			return 0;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 || ! file_exists( $tmp_file ) || filesize( $tmp_file ) <= 0 ) {
			@unlink( $tmp_file );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Mobo Core image fallback download failed with HTTP ' . $code . ': ' . $url );
			}

			return 0;
		}

		$file = array(
			'name'     => $file_name,
			'tmp_name' => $tmp_file,
		);

		$allow_webp = static function ( $mimes ) {
			$mimes['webp'] = 'image/webp';
			return $mimes;
		};

		add_filter( 'upload_mimes', $allow_webp, 10, 1 );

		try {
			$attachment_id = media_handle_sideload( $file, $product_id );
		} finally {
			remove_filter( 'upload_mimes', $allow_webp, 10 );
		}

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Mobo Core image fallback sideload failed: ' . $attachment_id->get_error_message() );
			}

			return 0;
		}

		$attachment_id = absint( $attachment_id );

		if ( $attachment_id > 0 ) {
			$this->mark_attachment_synced( $attachment_id, $image_guid, $url );
		}

		return $attachment_id;
	}

	/**
	 * Build a safe filename for sideloaded images.
	 *
	 * @param string $url Image URL.
	 * @param string $image_guid Image GUID.
	 * @return string
	 */
	private function get_download_file_name( $url, $image_guid ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );

		if ( '' === $name || '.' === $name || false === strpos( $name, '.' ) ) {
			$name = sanitize_file_name( ( '' !== $image_guid ? $image_guid : md5( $url ) ) . '.webp' );
		}

		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed   = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp' );

		if ( ! in_array( $extension, $allowed, true ) ) {
			$name .= '.webp';
		}

		return $name;
	}

	/**
	 * Detect local/private image URLs that WordPress safe HTTP blocks.
	 *
	 * In local WAMP + local .NET tests a friendly host such as codeya.ir may
	 * resolve to 127.0.0.1 via the Windows hosts file. For these URLs, skip
	 * media_sideload_image() and use the explicit unsafe-local fallback directly.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private function is_local_or_private_image_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! is_string( $ip ) || '' === $ip || $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( '127.' === substr( $ip, 0, 4 ) || '::1' === $ip ) {
			return true;
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	private function build_safe_local_image_host_filter( $url ) {
		$image_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$api_host   = strtolower( (string) wp_parse_url( (string) Mobo_Core_Settings::get( 'mobo_core_api_base_url', '' ), PHP_URL_HOST ) );

		$allowed_hosts = array_filter(
			array_unique(
				array(
					$image_host,
					$api_host,
					'localhost',
					'127.0.0.1',
					'::1',
				)
			)
		);

		return static function ( $external, $host, $request_url ) use ( $allowed_hosts ) {
			$host = strtolower( (string) $host );

			if ( in_array( $host, $allowed_hosts, true ) ) {
				return true;
			}

			return $external;
		};
	}

	private function mark_attachment_synced( $attachment_id, $image_guid, $url ) {
		$attachment_id = absint( $attachment_id );
		$image_guid    = sanitize_text_field( (string) $image_guid );
		$url           = esc_url_raw( (string) $url );

		if ( $attachment_id <= 0 ) {
			return;
		}

		if ( '' !== $image_guid ) {
			update_post_meta( $attachment_id, 'image_guid', $image_guid );
			update_post_meta( $attachment_id, 'img_guid', $image_guid );
		}

		if ( '' !== $url ) {
			update_post_meta( $attachment_id, 'mobo_source_url', $url );
		}

		update_post_meta( $attachment_id, 'mobo_sync_incomplete', '0' );
	}

	private function sync_woocommerce_product_image_objects_from_queue( $product_id, Mobo_Core_Image_Queue $queue ) {
		$ids = $queue->get_done_attachment_ids_for_product( $product_id );

		if ( empty( $ids ) ) {
			return;
		}

		$this->sync_woocommerce_product_image_objects( $product_id, $ids );
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

		$gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $gallery_ids ) ) ) );

		if ( ! empty( $gallery_ids ) ) {
			$product->set_image_id( absint( $gallery_ids[0] ) );
			$product->set_gallery_image_ids( $gallery_ids );
		} else {
			$product->set_image_id( 0 );
			$product->set_gallery_image_ids( array() );
		}

		$product->save();

		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
	}

	private function find_existing_attachment( $image_guid, $url ) {
		$image_guid = sanitize_text_field( (string) $image_guid );
		$url        = esc_url_raw( (string) $url );

		$id = $this->find_attachment_by_guid( $image_guid );

		if ( $id <= 0 && '' !== $url ) {
			$id = $this->find_attachment_by_meta( 'mobo_source_url', $url );
		}

		return $id;
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

		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product ) {
			$ids = $product->get_gallery_image_ids();
			$image_id = absint( $product->get_image_id() );

			if ( $image_id > 0 ) {
				array_unshift( $ids, $image_id );
			}

			return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}

		return array();
	}

	private function get_product_guid( $product_id ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return '';
		}

		$product = wc_get_product( $product_id );

		if ( $product instanceof WC_Product ) {
			$guid = $product->get_meta( 'product_guid', true );
			return sanitize_text_field( (string) $guid );
		}

		return sanitize_text_field( (string) get_post_meta( $product_id, 'product_guid', true ) );
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
