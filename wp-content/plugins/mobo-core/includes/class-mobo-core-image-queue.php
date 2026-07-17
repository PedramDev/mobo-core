<?php
/**
 * Table-backed image sync queue.
 *
 * The queue lets product/webhook sync resume image imports without repeatedly
 * downloading the same attachment. It is deliberately bounded and safe for weak
 * shared WooCommerce hosts.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/*
 * This component operates on Mobo Core's internal queue/map tables. Direct
 * database access is required for atomic batching and cursor updates; table
 * identifiers are generated internally and all external values are prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Image_Queue {

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mobo_image_queue';
	}

	/**
	 * Create/update table schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			queue_key varchar(191) NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_guid varchar(191) NOT NULL DEFAULT '',
			image_guid varchar(191) NOT NULL DEFAULT '',
			source_url text NULL,
			position_index int(10) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'pending',
			try_count int(10) unsigned NOT NULL DEFAULT 0,
			next_retry_at datetime NULL,
			locked_until datetime NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY queue_key (queue_key),
			KEY product_status (product_id, status, next_retry_at),
			KEY image_guid (image_guid),
			KEY status_retry (status, next_retry_at),
			KEY locked_until (locked_until),
			KEY attachment_id (attachment_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Check whether queue table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table_name();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Add/update image queue rows for one product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_guid Remote product GUID.
	 * @param array  $images Normalized image rows.
	 * @return array
	 */
	public function enqueue_product_images( $product_id, $product_guid, $images ) {
		global $wpdb;

		$product_id   = absint( $product_id );
		$product_guid = sanitize_text_field( (string) $product_guid );

		if ( $product_id <= 0 || ! is_array( $images ) || empty( $images ) || ! self::table_exists() ) {
			return array( 'enqueued' => 0, 'skipped' => 0 );
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$count = 0;
		$skip  = 0;

		foreach ( array_values( $images ) as $position => $image ) {
			if ( ! is_array( $image ) ) {
				$skip++;
				continue;
			}

			$image_guid = $this->get_image_guid( $image );
			$url        = $this->get_image_url( $image );

			if ( '' === $image_guid || '' === $url ) {
				$skip++;
				continue;
			}

			$key = $this->queue_key( $product_id, $image_guid );

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, status, attachment_id, source_url FROM {$table} WHERE queue_key = %s LIMIT 1",
					$key
				),
				ARRAY_A
			);

			$attachment_id        = is_array( $existing ) ? absint( $existing['attachment_id'] ) : 0;
			$existing_url         = is_array( $existing ) ? esc_url_raw( (string) $existing['source_url'] ) : '';
			$status               = is_array( $existing ) ? sanitize_key( (string) $existing['status'] ) : 'pending';
			$attachment_compatible = $attachment_id > 0 && $this->attachment_matches_source( $attachment_id, $url );

			if ( 'done' === $status && $attachment_compatible && $existing_url === $url ) {
				$wpdb->update(
					$table,
					array(
						'product_id'     => $product_id,
						'product_guid'   => $product_guid,
						'position_index' => absint( $position ),
						'updated_at'     => $now,
					),
					array( 'id' => absint( $existing['id'] ) ),
					array( '%d', '%s', '%d', '%s' ),
					array( '%d' )
				);

				$count++;
				continue;
			}

			$data = array(
				'queue_key'      => $key,
				'product_id'     => $product_id,
				'product_guid'   => $product_guid,
				'image_guid'     => $image_guid,
				'source_url'     => $url,
				'position_index' => absint( $position ),
				'updated_at'     => $now,
			);

			if ( is_array( $existing ) ) {
				/*
				 * Do not re-open the same pending/processing/failed image on every
				 * product-sync step. Re-enqueueing used to reset next_retry_at and
				 * locked_until, which could keep manual sync stuck forever on the same
				 * three bad/slow images. Only reset the queue row when the source URL
				 * actually changed.
				 */
				if ( $existing_url !== $url || ! $attachment_compatible ) {
					$data['status']        = 'pending';
					$data['try_count']     = 0;
					$data['next_retry_at'] = null;
					$data['locked_until']  = null;
					$data['last_error']    = null;
					$data['attachment_id'] = 0;
				}

				$wpdb->update(
					$table,
					$data,
					array( 'id' => absint( $existing['id'] ) ),
					null,
					array( '%d' )
				);
			} else {
				$data['attachment_id'] = 0;
				$data['try_count']     = 0;
				$data['status']        = 'pending';
				$data['next_retry_at'] = null;
				$data['locked_until']  = null;
				$data['last_error']    = null;
				$data['created_at']    = $now;

				$wpdb->insert( $table, $data );
			}

			$count++;
		}

		return array( 'enqueued' => $count, 'skipped' => $skip );
	}

	/**
	 * Get due rows for one product.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_due_product_images( $product_id, $limit ) {
		global $wpdb;

		$product_id = absint( $product_id );
		$limit      = max( 1, min( 50, absint( $limit ) ) );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE product_id = %d
				AND (
					(status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)
				ORDER BY position_index ASC, id ASC
				LIMIT %d",
				$product_id,
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count immediately due image rows for one product.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public function count_due_by_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE product_id = %d
					AND (
						(status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
						OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
					)",
					$product_id,
					$now,
					$now
				)
			)
		);
	}

	/**
	 * Get due rows across all products.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_due_images( $limit ) {
		global $wpdb;

		$limit = max( 1, min( 50, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE (
					(status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)
				ORDER BY updated_at ASC, id ASC
				LIMIT %d",
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Lock image row.
	 *
	 * @param int $id Row ID.
	 * @param int $ttl TTL seconds.
	 * @return bool
	 */
	public function lock( $id, $ttl = 90 ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', locked_until = %s, updated_at = %s
				WHERE id = %d
				AND (
					status = 'pending'
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)
				)",
				$until,
				$now,
				$id,
				$now
			)
		);

		return 1 === absint( $updated );
	}

	/**
	 * Mark image as done.
	 *
	 * @param int $id Row ID.
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function mark_done( $id, $attachment_id ) {
		$this->update_status(
			$id,
			'done',
			array(
				'attachment_id'  => absint( $attachment_id ),
				'next_retry_at' => null,
				'locked_until'  => null,
				'last_error'    => null,
			)
		);
	}

	/**
	 * Mark image retry/failure.
	 *
	 * @param int    $id Row ID.
	 * @param string $message Error message.
	 * @param int    $try_count Try count.
	 * @param bool   $final_failed Final failure.
	 * @return void
	 */
	public function mark_failure( $id, $message, $try_count, $final_failed = false ) {
		$status = $final_failed ? 'failed' : 'pending';
		$delay  = $final_failed ? null : min( 900, max( 60, absint( $try_count ) * Mobo_Core_Settings::get_int( 'mobo_core_image_retry_base_seconds', 120, 30, 900 ) ) );

		$this->update_status(
			$id,
			$status,
			array(
				'try_count'     => absint( $try_count ),
				'next_retry_at' => null === $delay ? null : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_until'  => null,
				'last_error'    => sanitize_text_field( (string) $message ),
			)
		);
	}

	/**
	 * Count not-done images for product.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $include_failed Include failed rows.
	 * @return int
	 */
	public function count_pending_by_product( $product_id, $include_failed = false ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		if ( $include_failed ) {
			return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status <> 'done'", $product_id ) ) );
		}

		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status IN ('pending', 'processing')", $product_id ) ) );
	}

	/**
	 * Get done attachment IDs for a product ordered by source position.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_done_attachment_ids_for_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table}
				WHERE product_id = %d AND status = 'done' AND attachment_id > 0
				ORDER BY position_index ASC, id ASC",
				$product_id
			)
		);

		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Get all image queue rows for one product ordered by source position.
	 *
	 * This is used to keep the WooCommerce featured image tied to the first
	 * image from the Mobo payload. It is important for products migrated from
	 * the old plugin because the old plugin could set the last image as the
	 * featured image.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public function get_ordered_rows_for_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, image_guid, source_url, position_index, attachment_id, status
				FROM {$table}
				WHERE product_id = %d
				ORDER BY position_index ASC, id ASC",
				$product_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count due image rows.
	 *
	 * @return int
	 */
	public function count_due() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE (status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s))
					OR (status = 'processing' AND locked_until IS NOT NULL AND locked_until < %s)",
					$now,
					$now
				)
			)
		);
	}

	/**
	 * Count pending/processing image rows.
	 *
	 * @return int
	 */
	public function count_pending() {
		return $this->count_by_statuses( array( 'pending', 'processing' ) );
	}

	/**
	 * Count failed image rows.
	 *
	 * @return int
	 */
	public function count_failed() {
		return $this->count_by_statuses( array( 'failed' ) );
	}

	/**
	 * Get compact status.
	 *
	 * @return array
	 */
	public function get_status() {
		return array(
			'enabled' => Mobo_Core_Settings::enabled( 'mobo_core_image_queue_enabled', '1' ),
			'pending' => $this->count_pending(),
			'due'     => $this->count_due(),
			'failed'  => $this->count_failed(),
		);
	}

	/**
	 * Update row status and fields.
	 *
	 * @param int    $id Row ID.
	 * @param string $status Status.
	 * @param array  $fields Extra fields.
	 * @return void
	 */
	private function update_status( $id, $status, $fields = array() ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 || ! self::table_exists() ) {
			return;
		}

		$data = array_merge(
			array(
				'status'     => sanitize_key( (string) $status ),
				'updated_at' => current_time( 'mysql', true ),
			),
			is_array( $fields ) ? $fields : array()
		);

		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'attachment_id', 'try_count' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$wpdb->update( self::table_name(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Count rows by statuses.
	 *
	 * @param array $statuses Statuses.
	 * @return int
	 */
	private function count_by_statuses( $statuses ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) ) );

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table = self::table_name();
		$total = 0;

		foreach ( $statuses as $status ) {
			$total += absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} WHERE status = %s",
						$status
					)
				)
			);
		}

		return $total;
	}

	/**
	 * Build queue key.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $image_guid Image GUID.
	 * @return string
	 */
	private function queue_key( $product_id, $image_guid ) {
		return md5( absint( $product_id ) . '|' . sanitize_text_field( (string) $image_guid ) );
	}

	/**
	 * Check attachment exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function attachment_exists( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		return $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id );
	}

	/**
	 * Check whether an existing attachment is still compatible with source URL.
	 *
	 * A done row pointing to an old jpg/png must not block a new WebP source from
	 * being downloaded.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Source URL.
	 * @return bool
	 */
	private function attachment_matches_source( $attachment_id, $url ) {
		$attachment_id = absint( $attachment_id );
		$url           = esc_url_raw( (string) $url );

		if ( ! $this->attachment_exists( $attachment_id ) ) {
			return false;
		}

		if ( class_exists( 'Mobo_Core_Shared_Media' ) && Mobo_Core_Shared_Media::enabled() ) {
			if ( ! Mobo_Core_Shared_Media::is_shared_attachment( $attachment_id ) ) {
				return false;
			}
			$image_guid = sanitize_text_field( (string) get_post_meta( $attachment_id, 'image_guid', true ) );
			return Mobo_Core_Shared_Media::refresh_attachment( $attachment_id, $image_guid, $url ) > 0;
		}

		if ( $this->is_webp_url( $url ) ) {
			return $this->is_attachment_webp( $attachment_id );
		}

		return true;
	}

	/**
	 * Detect WebP source URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_webp_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		return 'webp' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Detect WebP attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_attachment_webp( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$mime          = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$file          = (string) get_attached_file( $attachment_id );
		$ext           = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return 'image/webp' === $mime || 'webp' === $ext;
	}

	private function get_image_guid( $image ) {
		$keys = array( 'image_guid', 'img_guid', 'imageGuid', 'imageId', 'guid', 'remote_guid', 'remoteGuid', 'id' );

		foreach ( $keys as $key ) {
			$value = sanitize_text_field( (string) $this->get_value( $image, $key, '' ) );

			if ( $this->is_remote_guid_value( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function get_image_url( $image ) {
		$keys = array( 'url', 'src' );

		foreach ( $keys as $key ) {
			$value = esc_url_raw( (string) $this->get_value( $image, $key, '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
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
