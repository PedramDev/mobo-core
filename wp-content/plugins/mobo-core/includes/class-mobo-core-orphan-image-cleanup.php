<?php
/**
 * Safe cleanup for old Mobo raster files that exist in uploads but are not
 * registered as WordPress attachments.
 *
 * The scanner derives candidates from final Mobo WebP attachment filenames.
 * For a final image like uploads/2026/07/abc.webp it only considers files in
 * the same directory named abc.jpg, abc.jpeg, abc.png or WordPress-size variants
 * such as abc-300x300.jpg. Candidates are never deleted unless they are inside
 * uploads, not known by Media Library, not referenced in content/meta/options,
 * and the matching WebP attachment still exists.
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
class Mobo_Core_Orphan_Image_Cleanup {

	/**
	 * Return table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mobo_orphan_image_files';
	}

	/**
	 * Create/update table schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_key varchar(191) NOT NULL,
			relative_path text NOT NULL,
			absolute_path text NOT NULL,
			matched_webp_relative_path text NOT NULL,
			matched_webp_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'candidate',
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_key (file_key),
			KEY matched_webp_attachment_id (matched_webp_attachment_id),
			KEY status_updated (status, updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Check whether table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table_name();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}


	/**
	 * Orphan cleanup is locked until product Repair has completed once.
	 *
	 * @return bool
	 */
	private function is_unlocked() {
		return class_exists( 'Mobo_Core_Product_Sync' ) && Mobo_Core_Product_Sync::is_repair_completed();
	}

	/**
	 * Scan uploads for old raster files matching final Mobo WebP filenames.
	 *
	 * @param int $limit Max Mobo WebP attachments to inspect.
	 * @return array
	 */
	public function scan( $limit = 500 ) {
		$limit = max( 1, min( 5000, absint( $limit ) ) );

		if ( ! $this->is_unlocked() ) {
			$result = array(
				'scannedWebp'    => 0,
				'candidateFiles' => 0,
				'skippedFiles'   => 0,
				'alreadyDeleted' => 0,
				'totalBytes'     => 0,
				'checkedAt'      => time(),
				'status'         => 'locked_until_repair',
			);
			update_option( 'mobo_core_orphan_image_cleanup_last_scan', $result, false );
			return $result;
		}

		$result = array(
			'scannedWebp'      => 0,
			'candidateFiles'   => 0,
			'skippedFiles'     => 0,
			'alreadyDeleted'   => 0,
			'totalBytes'       => 0,
			'checkedAt'        => time(),
		);

		if ( ! self::table_exists() ) {
			self::create_table();
		}

		$attachments = $this->get_mobo_webp_attachment_ids( $limit );
		$seen        = array();

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$webp_file     = get_attached_file( $attachment_id );

			if ( ! is_string( $webp_file ) || '' === $webp_file || ! $this->is_webp_file_path( $webp_file ) || ! file_exists( $webp_file ) ) {
				continue;
			}

			$result['scannedWebp']++;

			$candidates = $this->find_raster_candidates_for_webp( $webp_file );

			foreach ( $candidates as $candidate_path ) {
				$key = md5( $this->normalize_path( $candidate_path ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;

				$validation = $this->validate_candidate_file( $candidate_path, $attachment_id, $webp_file );
				$size       = is_file( $candidate_path ) ? absint( filesize( $candidate_path ) ) : 0;
				$status     = ! empty( $validation['valid'] ) ? 'candidate' : 'skipped';
				$message    = isset( $validation['message'] ) ? (string) $validation['message'] : '';

				$this->upsert_file_row( $candidate_path, $webp_file, $attachment_id, $status, $message, $size );

				if ( 'candidate' === $status ) {
					$result['candidateFiles']++;
					$result['totalBytes'] += $size;
				} else {
					$result['skippedFiles']++;
				}
			}
		}

		update_option( 'mobo_core_orphan_image_cleanup_last_scan', $result, false );

		return $result;
	}

	/**
	 * Delete bounded candidate rows after re-validating them.
	 *
	 * @param int $limit Delete limit.
	 * @return array
	 */
	public function delete_candidates( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, absint( $limit ) ) );

		$result = array(
			'checked'   => 0,
			'deleted'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'bytes'     => 0,
			'executedAt'=> time(),
		);

		if ( ! $this->is_unlocked() ) {
			$result['status'] = 'locked_until_repair';
			update_option( 'mobo_core_orphan_image_cleanup_last_delete', $result, false );
			return $result;
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_orphan_image_cleanup_enabled', '1' ) ) {
			$result['status'] = 'disabled';
			update_option( 'mobo_core_orphan_image_cleanup_last_delete', $result, false );
			return $result;
		}

		if ( ! self::table_exists() ) {
			$result['status'] = 'missing_table';
			update_option( 'mobo_core_orphan_image_cleanup_last_delete', $result, false );
			return $result;
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'candidate' ORDER BY updated_at ASC, id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result['checked']++;

			$id             = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$path           = isset( $row['absolute_path'] ) ? (string) $row['absolute_path'] : '';
			$attachment_id  = isset( $row['matched_webp_attachment_id'] ) ? absint( $row['matched_webp_attachment_id'] ) : 0;
			$webp_file      = $attachment_id > 0 ? get_attached_file( $attachment_id ) : '';
			$validation     = $this->validate_candidate_file( $path, $attachment_id, is_string( $webp_file ) ? $webp_file : '' );

			if ( empty( $validation['valid'] ) ) {
				$this->update_row_status( $id, 'skipped', isset( $validation['message'] ) ? (string) $validation['message'] : 'Candidate is no longer safe.' );
				$result['skipped']++;
				continue;
			}

			$size = is_file( $path ) ? absint( filesize( $path ) ) : 0;

			wp_delete_file( $path );

			if ( ! file_exists( $path ) ) {
				$this->update_row_status( $id, 'deleted', 'Deleted safely.', true, $size );
				$result['deleted']++;
				$result['bytes'] += $size;
				continue;
			}

			$this->update_row_status( $id, 'failed', 'wp_delete_file() failed.' );
			$result['failed']++;
		}

		$result['status'] = 'done';
		update_option( 'mobo_core_orphan_image_cleanup_last_delete', $result, false );

		return $result;
	}

	/**
	 * Reset table rows.
	 *
	 * @param bool $only_rescanable If true keep deleted rows.
	 * @return int
	 */
	public function reset( $only_rescanable = false ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		if ( $only_rescanable ) {
			return absint( $wpdb->query( "DELETE FROM {$table} WHERE status IN ('candidate', 'skipped', 'failed')" ) );
		}

		return absint( $wpdb->query( "TRUNCATE TABLE {$table}" ) );
	}

	/**
	 * Get summary status.
	 *
	 * @return array
	 */
	public function get_status() {
		return array(
			'enabled'    => Mobo_Core_Settings::enabled( 'mobo_core_orphan_image_cleanup_enabled', '1' ),
			'candidate'  => $this->count_by_statuses( array( 'candidate' ) ),
			'skipped'    => $this->count_by_statuses( array( 'skipped' ) ),
			'deleted'    => $this->count_by_statuses( array( 'deleted' ) ),
			'failed'     => $this->count_by_statuses( array( 'failed' ) ),
			'lastScan'   => get_option( 'mobo_core_orphan_image_cleanup_last_scan', array() ),
			'lastDelete' => get_option( 'mobo_core_orphan_image_cleanup_last_delete', array() ),
		);
	}

	/**
	 * Get recent rows.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function get_recent_rows( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get Mobo WebP attachment IDs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_mobo_webp_attachment_ids( $limit ) {
		$limit = max( 1, min( 5000, absint( $limit ) ) );

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance/synchronization lookup on indexed post IDs.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => 'image_guid',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'img_guid',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'mobo_source_url',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$ids = array();
		foreach ( is_array( $query->posts ) ? $query->posts : array() as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file          = get_attached_file( $attachment_id );
			if ( is_string( $file ) && $this->is_webp_file_path( $file ) && file_exists( $file ) ) {
				$ids[] = $attachment_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Find same-basename legacy raster files beside a final WebP file.
	 *
	 * @param string $webp_file WebP file path.
	 * @return array
	 */
	private function find_raster_candidates_for_webp( $webp_file ) {
		$webp_file = $this->normalize_path( (string) $webp_file );
		$dir       = dirname( $webp_file );
		$base      = preg_replace( '/\.webp$/i', '', basename( $webp_file ) );

		if ( '' === $base || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return array();
		}

		$items      = scandir( $dir );
		$candidates = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			if ( ! preg_match( '/^' . preg_quote( $base, '/' ) . '(-\d+x\d+)?\.(jpe?g|png)$/i', $item ) ) {
				continue;
			}

			$path = $dir . '/' . $item;
			if ( is_file( $path ) ) {
				$candidates[] = $path;
			}
		}

		return $candidates;
	}

	/**
	 * Validate a candidate before listing/deleting.
	 *
	 * @param string $candidate_path Candidate path.
	 * @param int    $webp_attachment_id Matching WebP attachment ID.
	 * @param string $webp_file Matching WebP path.
	 * @return array
	 */
	private function validate_candidate_file( $candidate_path, $webp_attachment_id, $webp_file ) {
		$candidate_path    = $this->normalize_path( (string) $candidate_path );
		$webp_attachment_id = absint( $webp_attachment_id );
		$webp_file         = $this->normalize_path( (string) $webp_file );

		if ( '' === $candidate_path || ! is_file( $candidate_path ) ) {
			return array( 'valid' => false, 'message' => 'File does not exist.' );
		}

		if ( ! $this->is_inside_uploads( $candidate_path ) ) {
			return array( 'valid' => false, 'message' => 'File is outside uploads.' );
		}

		if ( ! $this->is_legacy_raster_file_path( $candidate_path ) ) {
			return array( 'valid' => false, 'message' => 'File is not jpg/jpeg/png.' );
		}

		if ( $webp_attachment_id <= 0 || 'attachment' !== get_post_type( $webp_attachment_id ) ) {
			return array( 'valid' => false, 'message' => 'Matching WebP attachment is missing.' );
		}

		if ( '' === $webp_file || ! is_file( $webp_file ) || ! $this->is_webp_file_path( $webp_file ) ) {
			return array( 'valid' => false, 'message' => 'Matching WebP file is missing.' );
		}

		if ( ! $this->is_same_base_legacy_file( $candidate_path, $webp_file ) ) {
			return array( 'valid' => false, 'message' => 'File name does not match final WebP basename.' );
		}

		if ( $this->is_known_wordpress_file( $candidate_path ) ) {
			return array( 'valid' => false, 'message' => 'File is registered by Media Library or attachment metadata.' );
		}

		$relative_path = $this->relative_to_uploads( $candidate_path );
		if ( '' === $relative_path ) {
			return array( 'valid' => false, 'message' => 'Could not resolve uploads-relative path.' );
		}

		if ( $this->is_referenced_in_database( $relative_path ) ) {
			return array( 'valid' => false, 'message' => 'File path is referenced in database content/meta/options.' );
		}

		return array( 'valid' => true, 'message' => 'Candidate is safe.' );
	}

	/**
	 * Upsert scan result row.
	 *
	 * @param string $candidate_path Candidate path.
	 * @param string $webp_file WebP path.
	 * @param int    $webp_attachment_id WebP attachment ID.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param int    $size Size.
	 * @return void
	 */
	private function upsert_file_row( $candidate_path, $webp_file, $webp_attachment_id, $status, $message, $size ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$candidate_path = $this->normalize_path( (string) $candidate_path );
		$webp_file      = $this->normalize_path( (string) $webp_file );
		$relative_path  = $this->relative_to_uploads( $candidate_path );
		$webp_relative  = $this->relative_to_uploads( $webp_file );

		if ( '' === $relative_path || '' === $webp_relative ) {
			return;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$key   = md5( $relative_path );
		$data  = array(
			'file_key'                   => $key,
			'relative_path'              => $relative_path,
			'absolute_path'              => $candidate_path,
			'matched_webp_relative_path' => $webp_relative,
			'matched_webp_attachment_id' => absint( $webp_attachment_id ),
			'file_size'                  => absint( $size ),
			'status'                     => sanitize_key( (string) $status ),
			'last_error'                 => sanitize_text_field( (string) $message ),
			'updated_at'                 => $now,
			'deleted_at'                 => null,
		);

		$existing_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_key = %s LIMIT 1", $key ) ) );

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			return;
		}

		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
	}

	/**
	 * Update row status.
	 *
	 * @param int    $id Row ID.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param bool   $deleted Whether deleted.
	 * @param int    $size Deleted bytes.
	 * @return void
	 */
	private function update_row_status( $id, $status, $message, $deleted = false, $size = 0 ) {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 || ! self::table_exists() ) {
			return;
		}

		$data = array(
			'status'     => sanitize_key( (string) $status ),
			'last_error' => sanitize_text_field( (string) $message ),
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( $deleted ) {
			$data['deleted_at'] = current_time( 'mysql', true );
			$data['file_size']  = absint( $size );
		}

		$wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
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
	 * Is this file a same-basename raster beside the WebP file?
	 *
	 * @param string $candidate_path Candidate path.
	 * @param string $webp_file WebP path.
	 * @return bool
	 */
	private function is_same_base_legacy_file( $candidate_path, $webp_file ) {
		$candidate_path = $this->normalize_path( (string) $candidate_path );
		$webp_file      = $this->normalize_path( (string) $webp_file );

		if ( dirname( $candidate_path ) !== dirname( $webp_file ) ) {
			return false;
		}

		$base = preg_replace( '/\.webp$/i', '', basename( $webp_file ) );
		$name = basename( $candidate_path );

		return 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '(-\d+x\d+)?\.(jpe?g|png)$/i', $name );
	}

	/**
	 * Check if file is known by WordPress attachment storage.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_known_wordpress_file( $path ) {
		global $wpdb;

		$relative = $this->relative_to_uploads( $path );
		$name     = basename( $this->normalize_path( (string) $path ) );

		if ( '' === $relative || '' === $name ) {
			return true;
		}

		$attached_id = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$relative
				)
			)
		);

		if ( $attached_id > 0 ) {
			return true;
		}

		$like_name = '%' . $wpdb->esc_like( $name ) . '%';
		$meta_id   = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s LIMIT 1",
					$like_name
				)
			)
		);

		return $meta_id > 0;
	}

	/**
	 * Check if path is referenced in common database locations.
	 *
	 * @param string $relative_path Uploads-relative path.
	 * @return bool
	 */
	private function is_referenced_in_database( $relative_path ) {
		global $wpdb;

		$relative_path = ltrim( $this->normalize_path( (string) $relative_path ), '/' );
		$name          = basename( $relative_path );

		$needles = array_values(
			array_unique(
				array_filter(
					array(
						$relative_path,
						str_replace( '/', '\\/', $relative_path ),
						$name,
					)
				)
			)
		);

		foreach ( $needles as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';

			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s OR guid LIKE %s LIMIT 1", $like, $like ) ) ) > 0 ) {
				return true;
			}

			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1", $like ) ) ) > 0 ) {
				return true;
			}

			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $like ) ) ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve path relative to uploads.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function relative_to_uploads( $path ) {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? $this->normalize_path( (string) $uploads['basedir'] ) : '';
		$path    = $this->normalize_path( (string) $path );

		if ( '' === $base || '' === $path || 0 !== strpos( trailingslashit( $path ), trailingslashit( $base ) ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( trailingslashit( $base ) ) ), '/' );
	}

	/**
	 * Check path inside uploads.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_inside_uploads( $path ) {
		return '' !== $this->relative_to_uploads( $path );
	}

	/**
	 * Normalize path separators.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		if ( function_exists( 'wp_normalize_path' ) ) {
			$path = wp_normalize_path( $path );
		}

		return untrailingslashit( $path );
	}

	/**
	 * Is WebP file path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_webp_file_path( $path ) {
		return 'webp' === strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Is old raster path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_legacy_raster_file_path( $path ) {
		$ext = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
	}
}
