<?php
/**
 * Safe cleanup for unregistered legacy Mobo raster image families.
 *
 * A legacy image and all WordPress derivatives are treated as one family. For
 * uploads/2026/07/abc.webp, the family may contain abc.jpg, abc-300x300.jpg,
 * abc-scaled.jpg, abc-e1234567890123-150x150.jpg, and similar core-generated
 * files. Registered attachment families are ignored before queue persistence;
 * only fully unregistered and unreferenced families are stored as candidates.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This component operates on Mobo Core's internal cleanup table. Direct
 * database access is required for bounded cursor scans and atomic row updates;
 * table identifiers are generated internally and all external values are
 * prepared.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
class Mobo_Core_Orphan_Image_Cleanup {

	const CURSOR_OPTION = 'mobo_core_orphan_image_scan_cursor';

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
	 * One row represents one image family, not one physical derivative.
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
			family_key varchar(191) NOT NULL DEFAULT '',
			family_base varchar(191) NOT NULL DEFAULT '',
			relative_path text NOT NULL,
			absolute_path text NOT NULL,
			file_paths longtext NULL,
			file_count int(10) unsigned NOT NULL DEFAULT 0,
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
			KEY family_key (family_key),
			KEY matched_webp_attachment_id (matched_webp_attachment_id),
			KEY status_updated (status, updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Remove rows created by the old per-file scanner and reset scan cursors.
	 * Rows that already use the family schema are retained on defensive reruns.
	 *
	 * @return int
	 */
	public static function migrate_to_family_rows() {
		global $wpdb;

		delete_option( self::CURSOR_OPTION );
		delete_option( 'mobo_core_orphan_image_cleanup_last_scan' );

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		return absint( $wpdb->query( "DELETE FROM {$table} WHERE family_key = '' OR file_count = 0 OR status IN ('candidate', 'skipped', 'failed')" ) );
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
	 * Scan a bounded cursor batch of Mobo WebP attachments.
	 *
	 * Registered WordPress image families are counted but never persisted. A
	 * candidate row contains the complete current family so deletion can happen
	 * as one revalidated operation.
	 *
	 * @param int $limit Max Mobo WebP attachments to inspect.
	 * @return array
	 */
	public function scan( $limit = 500 ) {
		$limit = max( 1, min( 5000, absint( $limit ) ) );

		if ( ! $this->is_unlocked() ) {
			$result = array(
				'processedAttachments' => 0,
				'scannedWebp'          => 0,
				'candidateFamilies'    => 0,
				'candidateFiles'       => 0,
				'registeredFamilies'   => 0,
				'referencedFamilies'   => 0,
				'invalidFamilies'      => 0,
				'skippedFiles'         => 0,
				'alreadyDeleted'       => 0,
				'totalBytes'           => 0,
				'cursorStart'          => absint( get_option( self::CURSOR_OPTION, 0 ) ),
				'cursorEnd'            => absint( get_option( self::CURSOR_OPTION, 0 ) ),
				'cycleComplete'        => false,
				'checkedAt'            => time(),
				'status'               => 'locked_until_repair',
			);
			update_option( 'mobo_core_orphan_image_cleanup_last_scan', $result, false );
			return $result;
		}

		if ( ! self::table_exists() ) {
			self::create_table();
		}

		$batch        = $this->get_mobo_webp_attachment_batch( $limit );
		$cursor_start = isset( $batch['cursorStart'] ) ? absint( $batch['cursorStart'] ) : 0;
		$previous     = get_option( 'mobo_core_orphan_image_cleanup_last_scan', array() );
		$previous     = is_array( $previous ) ? $previous : array();
		$continue_cycle = $cursor_start > 0 && ! empty( $previous ) && empty( $previous['cycleComplete'] );
		$result = array(
			'processedAttachments' => $continue_cycle ? absint( isset( $previous['processedAttachments'] ) ? $previous['processedAttachments'] : 0 ) : 0,
			'scannedWebp'        => $continue_cycle ? absint( isset( $previous['scannedWebp'] ) ? $previous['scannedWebp'] : 0 ) : 0,
			'candidateFamilies'  => $continue_cycle ? absint( isset( $previous['candidateFamilies'] ) ? $previous['candidateFamilies'] : 0 ) : 0,
			'candidateFiles'     => $continue_cycle ? absint( isset( $previous['candidateFiles'] ) ? $previous['candidateFiles'] : 0 ) : 0,
			'registeredFamilies' => $continue_cycle ? absint( isset( $previous['registeredFamilies'] ) ? $previous['registeredFamilies'] : 0 ) : 0,
			'referencedFamilies' => $continue_cycle ? absint( isset( $previous['referencedFamilies'] ) ? $previous['referencedFamilies'] : 0 ) : 0,
			'invalidFamilies'    => $continue_cycle ? absint( isset( $previous['invalidFamilies'] ) ? $previous['invalidFamilies'] : 0 ) : 0,
			'skippedFiles'       => $continue_cycle ? absint( isset( $previous['skippedFiles'] ) ? $previous['skippedFiles'] : 0 ) : 0,
			'alreadyDeleted'     => $continue_cycle ? absint( isset( $previous['alreadyDeleted'] ) ? $previous['alreadyDeleted'] : 0 ) : 0,
			'totalBytes'         => $continue_cycle ? absint( isset( $previous['totalBytes'] ) ? $previous['totalBytes'] : 0 ) : 0,
			'cursorStart'        => $cursor_start,
			'cursorEnd'          => isset( $batch['cursorEnd'] ) ? absint( $batch['cursorEnd'] ) : 0,
			'cycleComplete'      => ! empty( $batch['cycleComplete'] ),
			'estimatedTotal'      => isset( $batch['estimatedTotal'] ) ? absint( $batch['estimatedTotal'] ) : 0,
			'cycleStartedAt'     => $continue_cycle && ! empty( $previous['cycleStartedAt'] ) ? absint( $previous['cycleStartedAt'] ) : time(),
			'checkedAt'          => time(),
			'status'             => 'done',
		);

		foreach ( isset( $batch['ids'] ) && is_array( $batch['ids'] ) ? $batch['ids'] : array() as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$result['processedAttachments']++;
			$webp_file     = get_attached_file( $attachment_id );

			if ( ! is_string( $webp_file ) || '' === $webp_file || ! $this->is_webp_file_path( $webp_file ) || ! is_file( $webp_file ) ) {
				continue;
			}

			$result['scannedWebp']++;
			$family_paths = $this->find_raster_family_for_webp( $webp_file, $attachment_id );

			if ( empty( $family_paths ) ) {
				continue;
			}

			$inspection = $this->inspect_family( $family_paths, $attachment_id, $webp_file );
			$state      = isset( $inspection['state'] ) ? sanitize_key( (string) $inspection['state'] ) : 'invalid';
			$file_count = isset( $inspection['fileCount'] ) ? absint( $inspection['fileCount'] ) : count( $family_paths );

			if ( 'candidate' === $state ) {
				$this->upsert_family_row( $family_paths, $webp_file, $attachment_id );
				$result['candidateFamilies']++;
				$result['candidateFiles'] += $file_count;
				$result['totalBytes'] += isset( $inspection['bytes'] ) ? absint( $inspection['bytes'] ) : $this->sum_file_sizes( $family_paths );
				continue;
			}

			$this->remove_rescanable_family_row( $webp_file );
			$result['skippedFiles'] += $file_count;

			if ( 'registered' === $state ) {
				$result['registeredFamilies']++;
			} elseif ( 'referenced' === $state ) {
				$result['referencedFamilies']++;
			} else {
				$result['invalidFamilies']++;
			}
		}

		update_option( 'mobo_core_orphan_image_cleanup_last_scan', $result, false );

		return $result;
	}

	/**
	 * Delete bounded candidate families after complete re-validation.
	 *
	 * @param int $limit Family limit.
	 * @return array
	 */
	public function delete_candidates( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, absint( $limit ) ) );
		$result = array(
			'checked'          => 0,
			'checkedFamilies'  => 0,
			'deleted'          => 0,
			'deletedFamilies'  => 0,
			'deletedFiles'     => 0,
			'skipped'          => 0,
			'skippedFamilies'  => 0,
			'failed'           => 0,
			'failedFamilies'   => 0,
			'remainingFamilies'=> 0,
			'bytes'            => 0,
			'executedAt'       => time(),
		);

		if ( ! $this->is_unlocked() ) {
			$result['status'] = 'locked_until_repair';
			update_option( 'mobo_core_orphan_image_cleanup_last_delete', $result, false );
			return $result;
		}

		if ( ! Mobo_Core_Settings::enabled( 'mobo_core_orphan_image_cleanup_enabled', '0' ) ) {
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
			$result['checkedFamilies']++;

			$id            = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$attachment_id = isset( $row['matched_webp_attachment_id'] ) ? absint( $row['matched_webp_attachment_id'] ) : 0;
			$webp_file     = $attachment_id > 0 ? get_attached_file( $attachment_id ) : '';
			$webp_file     = is_string( $webp_file ) ? $this->normalize_path( $webp_file ) : '';

			if ( '' === $webp_file || ! is_file( $webp_file ) || ! $this->is_webp_file_path( $webp_file ) ) {
				$this->update_row_status( $id, 'skipped', 'پیوست یا فایل WebP متناظر وجود ندارد.' );
				$result['skipped']++;
				$result['skippedFamilies']++;
				continue;
			}

			$current_paths = $this->find_raster_family_for_webp( $webp_file, $attachment_id );

			if ( empty( $current_paths ) ) {
				$this->update_row_status( $id, 'deleted', 'این خانواده دیگر روی دیسک وجود ندارد.', true, 0, 0, array() );
				$result['deletedFamilies']++;
				continue;
			}

			$inspection = $this->inspect_family( $current_paths, $attachment_id, $webp_file );
			if ( 'candidate' !== ( isset( $inspection['state'] ) ? $inspection['state'] : '' ) ) {
				$message = isset( $inspection['message'] ) ? (string) $inspection['message'] : 'این خانواده دیگر شرایط حذف امن را ندارد.';
				$this->update_row_status( $id, 'skipped', $message, false, $this->sum_file_sizes( $current_paths ), count( $current_paths ), $current_paths );
				$result['skipped']++;
				$result['skippedFamilies']++;
				continue;
			}

			$deleted_files = 0;
			$deleted_bytes = 0;
			$remaining     = array();

			foreach ( $current_paths as $path ) {
				$size = is_file( $path ) ? absint( filesize( $path ) ) : 0;
				wp_delete_file( $path );

				if ( ! is_file( $path ) ) {
					$deleted_files++;
					$deleted_bytes += $size;
				} else {
					$remaining[] = $path;
				}
			}

			$result['deleted'] += $deleted_files;
			$result['deletedFiles'] += $deleted_files;
			$result['bytes'] += $deleted_bytes;

			if ( empty( $remaining ) ) {
				$this->update_row_status( $id, 'deleted', sprintf( '%d فایل از این خانواده با موفقیت و به صورت امن حذف شد.', $deleted_files ), true, $deleted_bytes, $deleted_files, $current_paths );
				$result['deletedFamilies']++;
				continue;
			}

			$this->update_row_status(
				$id,
				'failed',
				sprintf( '%d فایل حذف شد، اما حذف %d فایل ناموفق بود.', $deleted_files, count( $remaining ) ),
				false,
				$this->sum_file_sizes( $remaining ),
				count( $remaining ),
				$remaining
			);
			$result['failed']++;
			$result['failedFamilies']++;
		}

		$result['status'] = 'done';
		$result['remainingFamilies'] = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'candidate'" ) );
		update_option( 'mobo_core_orphan_image_cleanup_last_delete', $result, false );

		return $result;
	}

	/**
	 * Reset table rows and scan cursor.
	 *
	 * @param bool $only_rescanable If true keep deleted rows.
	 * @return int
	 */
	public function reset( $only_rescanable = false ) {
		global $wpdb;

		delete_option( self::CURSOR_OPTION );
		delete_option( 'mobo_core_orphan_image_cleanup_last_scan' );

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
			'enabled'    => Mobo_Core_Settings::enabled( 'mobo_core_orphan_image_cleanup_enabled', '0' ),
			'candidate'  => $this->count_by_statuses( array( 'candidate' ) ),
			'skipped'    => $this->count_by_statuses( array( 'skipped' ) ),
			'deleted'    => $this->count_by_statuses( array( 'deleted' ) ),
			'failed'     => $this->count_by_statuses( array( 'failed' ) ),
			'cursor'     => absint( get_option( self::CURSOR_OPTION, 0 ) ),
			'lastScan'   => get_option( 'mobo_core_orphan_image_cleanup_last_scan', array() ),
			'lastDelete' => get_option( 'mobo_core_orphan_image_cleanup_last_delete', array() ),
		);
	}

	/**
	 * Get recent actionable/audit rows. Normal registered WordPress cuts are not
	 * persisted and old skipped noise is intentionally hidden.
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
				"SELECT * FROM {$table} WHERE status IN ('candidate', 'failed', 'deleted') ORDER BY updated_at DESC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a cursor batch of Mobo WebP attachment IDs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	private function get_mobo_webp_attachment_batch( $limit ) {
		global $wpdb;

		$limit        = max( 1, min( 5000, absint( $limit ) ) );
		$cursor_start = absint( get_option( self::CURSOR_OPTION, 0 ) );
		$fetch_limit  = $limit + 1;
		$like_webp    = '%.webp';

		$estimated_total = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} marker
						ON marker.post_id = p.ID
						AND marker.meta_key IN ('image_guid', 'img_guid', 'mobo_source_url')
					LEFT JOIN {$wpdb->postmeta} attached
						ON attached.post_id = p.ID
						AND attached.meta_key = '_wp_attached_file'
					WHERE p.post_type = 'attachment'
					AND p.post_status IN ('inherit', 'private')
					AND (p.post_mime_type = 'image/webp' OR LOWER(attached.meta_value) LIKE %s)",
					$like_webp
				)
			)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} marker
					ON marker.post_id = p.ID
					AND marker.meta_key IN ('image_guid', 'img_guid', 'mobo_source_url')
				LEFT JOIN {$wpdb->postmeta} attached
					ON attached.post_id = p.ID
					AND attached.meta_key = '_wp_attached_file'
				WHERE p.post_type = 'attachment'
				AND p.post_status IN ('inherit', 'private')
				AND p.ID > %d
				AND (p.post_mime_type = 'image/webp' OR LOWER(attached.meta_value) LIKE %s)
				ORDER BY p.ID ASC
				LIMIT %d",
				$cursor_start,
				$like_webp,
				$fetch_limit
			)
		);

		$ids            = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
		$has_more       = count( $ids ) > $limit;
		$ids            = array_slice( $ids, 0, $limit );
		$cursor_end     = ! empty( $ids ) ? absint( end( $ids ) ) : $cursor_start;
		$cycle_complete = ! $has_more;

		if ( $cycle_complete ) {
			update_option( self::CURSOR_OPTION, 0, false );
		} else {
			update_option( self::CURSOR_OPTION, $cursor_end, false );
		}

		return array(
			'ids'           => $ids,
			'cursorStart'   => $cursor_start,
			'cursorEnd'     => $cursor_end,
			'cycleComplete' => $cycle_complete,
			'estimatedTotal' => $estimated_total,
		);
	}

	/**
	 * Find the complete legacy raster family beside a final WebP file.
	 *
	 * @param string $webp_file WebP file path.
	 * @return array
	 */
	private function find_raster_family_for_webp( $webp_file, $webp_attachment_id = 0 ) {
		$webp_file = $this->normalize_path( (string) $webp_file );
		$dir       = dirname( $webp_file );
		$bases     = $this->legacy_family_bases( $webp_file, $webp_attachment_id );

		if ( empty( $bases ) || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return array();
		}

		$items    = scandir( $dir );
		$patterns = array_map( array( $this, 'legacy_family_pattern' ), $bases );
		$paths    = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$matched = false;
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $item ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				continue;
			}

			$path = $this->normalize_path( $dir . '/' . $item );
			if ( is_file( $path ) ) {
				$paths[] = $path;
			}
		}

		usort(
			$paths,
			static function ( $left, $right ) use ( $bases ) {
				$left_name  = basename( (string) $left );
				$right_name = basename( (string) $right );
				$left_main  = false;
				$right_main = false;

				foreach ( $bases as $base ) {
					$left_main  = $left_main || 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '\.(?:jpe?g|png)$/i', $left_name );
					$right_main = $right_main || 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '\.(?:jpe?g|png)$/i', $right_name );
				}

				if ( $left_main !== $right_main ) {
					return $left_main ? -1 : 1;
				}

				return strnatcasecmp( $left_name, $right_name );
			}
		);

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Build trusted legacy family bases from the attached WebP and its own
	 * metadata. This allows scaled/rotated/edited WordPress originals and backup
	 * files to stay in one family without blindly stripping suffixes from an
	 * arbitrary filename.
	 *
	 * @param string $webp_file WebP path.
	 * @param int    $webp_attachment_id WebP attachment ID.
	 * @return array
	 */
	private function legacy_family_bases( $webp_file, $webp_attachment_id = 0 ) {
		$webp_file          = $this->normalize_path( (string) $webp_file );
		$webp_attachment_id = absint( $webp_attachment_id );
		$names              = array( basename( $webp_file ) );
		$metadata           = $webp_attachment_id > 0 ? wp_get_attachment_metadata( $webp_attachment_id ) : array();

		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['file'] ) ) {
				$names[] = basename( (string) $metadata['file'] );
			}
			if ( ! empty( $metadata['original_image'] ) ) {
				$names[] = basename( (string) $metadata['original_image'] );
			}
			foreach ( isset( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ? $metadata['backup_sizes'] : array() as $backup ) {
				if ( is_array( $backup ) && ! empty( $backup['file'] ) ) {
					$names[] = basename( (string) $backup['file'] );
				}
			}
		}

		$bases = array();
		foreach ( array_values( array_unique( array_filter( $names ) ) ) as $name ) {
			$base = pathinfo( (string) $name, PATHINFO_FILENAME );
			if ( '' !== $base ) {
				$bases[] = $base;
			}
		}

		return array_values( array_unique( array_filter( $bases ) ) );
	}

	/**
	 * Build a pattern covering WordPress core derivatives.
	 *
	 * @param string $base Base name without extension.
	 * @return string
	 */
	private function legacy_family_pattern( $base ) {
		return '/^' . preg_quote( (string) $base, '/' ) . '(?:(?:-e\d{6,})|(?:-\d+x\d+)|(?:-scaled)|(?:-rotated))*\.(?:jpe?g|png)$/i';
	}

	/**
	 * Inspect a complete family.
	 *
	 * @param array  $paths Family paths.
	 * @param int    $webp_attachment_id Matching WebP attachment ID.
	 * @param string $webp_file Matching WebP path.
	 * @return array
	 */
	private function inspect_family( $paths, $webp_attachment_id, $webp_file ) {
		$webp_attachment_id = absint( $webp_attachment_id );
		$webp_file          = $this->normalize_path( (string) $webp_file );
		$paths              = array_values( array_unique( array_filter( array_map( array( $this, 'normalize_path' ), (array) $paths ) ) ) );

		if ( $webp_attachment_id <= 0 || 'attachment' !== get_post_type( $webp_attachment_id ) || '' === $webp_file || ! is_file( $webp_file ) || ! $this->is_webp_file_path( $webp_file ) ) {
			return array( 'state' => 'invalid', 'message' => 'پیوست یا فایل WebP متناظر وجود ندارد.', 'fileCount' => count( $paths ), 'bytes' => $this->sum_file_sizes( $paths ) );
		}

		if ( empty( $paths ) ) {
			return array( 'state' => 'invalid', 'message' => 'خانواده تصویر قدیمی خالی است.', 'fileCount' => 0, 'bytes' => 0 );
		}

		foreach ( $paths as $path ) {
			if ( ! is_file( $path ) || ! $this->is_inside_uploads( $path ) || ! $this->is_legacy_raster_file_path( $path ) || ! $this->is_same_base_legacy_file( $path, $webp_file, $webp_attachment_id ) ) {
				return array( 'state' => 'invalid', 'message' => 'خانواده شامل فایل نامعتبر یا نامرتبط است.', 'fileCount' => count( $paths ), 'bytes' => $this->sum_file_sizes( $paths ) );
			}
		}

		if ( $this->family_is_registered_by_wordpress( $paths, $webp_file ) ) {
			return array( 'state' => 'registered', 'message' => 'این خانواده متعلق به یک پیوست ثبت شده وردپرس است.', 'fileCount' => count( $paths ), 'bytes' => $this->sum_file_sizes( $paths ) );
		}

		foreach ( $paths as $path ) {
			$relative = $this->relative_to_uploads( $path );
			if ( '' === $relative || $this->is_referenced_in_database( $relative ) ) {
				return array( 'state' => 'referenced', 'message' => 'حداقل یکی از فایل های خانواده در محتوا، متادیتا یا تنظیمات دیتابیس مرجع دارد.', 'fileCount' => count( $paths ), 'bytes' => $this->sum_file_sizes( $paths ) );
			}
		}

		return array(
			'state'     => 'candidate',
			'message'   => 'این خانواده کامل است، در وردپرس ثبت نشده و برای حذف آماده است.',
			'fileCount' => count( $paths ),
			'bytes'     => $this->sum_file_sizes( $paths ),
		);
	}

	/**
	 * Check whether any member of the family is registered by a WordPress
	 * attachment's main file or attachment metadata.
	 *
	 * @param array  $paths Family paths.
	 * @param string $webp_file Matching WebP path.
	 * @return bool
	 */
	private function family_is_registered_by_wordpress( $paths, $webp_file ) {
		global $wpdb;

		$relative_paths = array();
		foreach ( (array) $paths as $path ) {
			$relative = $this->relative_to_uploads( $path );
			if ( '' !== $relative ) {
				$relative_paths[ $relative ] = true;
			}
		}

		if ( empty( $relative_paths ) ) {
			return true;
		}

		$webp_relative = $this->relative_to_uploads( $webp_file );
		$dir           = dirname( $webp_relative );
		$base          = preg_replace( '/\.webp$/i', '', basename( $webp_relative ) );
		$prefix        = ( '.' === $dir ? '' : trailingslashit( $dir ) ) . $base;
		$like_prefix   = $wpdb->esc_like( $prefix ) . '%';

		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'attachment'
				AND pm.meta_key = '_wp_attached_file'
				AND pm.meta_value LIKE %s
				LIMIT 100",
				$like_prefix
			)
		);

		foreach ( is_array( $attachment_ids ) ? $attachment_ids : array() as $attachment_id ) {
			$registered = $this->get_registered_attachment_relative_paths( absint( $attachment_id ) );
			foreach ( $registered as $relative ) {
				if ( isset( $relative_paths[ $relative ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return all attachment paths known by core metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function get_registered_attachment_relative_paths( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$attached      = ltrim( $this->normalize_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ), '/' );
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$paths         = array();

		if ( '' !== $attached ) {
			$paths[] = $attached;
		}

		if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			$metadata_file = ltrim( $this->normalize_path( (string) $metadata['file'] ), '/' );
			$paths[]       = $metadata_file;
			$dir           = dirname( $metadata_file );
			$dir           = '.' === $dir ? '' : $dir;

			if ( ! empty( $metadata['original_image'] ) ) {
				$paths[] = ltrim( ( '' !== $dir ? trailingslashit( $dir ) : '' ) . basename( (string) $metadata['original_image'] ), '/' );
			}

			foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$paths[] = ltrim( ( '' !== $dir ? trailingslashit( $dir ) : '' ) . basename( (string) $size['file'] ), '/' );
				}
			}

			foreach ( isset( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ? $metadata['backup_sizes'] : array() as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$paths[] = ltrim( ( '' !== $dir ? trailingslashit( $dir ) : '' ) . basename( (string) $size['file'] ), '/' );
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( array( $this, 'normalize_relative_path' ), $paths ) ) ) );
	}

	/**
	 * Upsert one family candidate row.
	 *
	 * @param array  $paths Family paths.
	 * @param string $webp_file WebP path.
	 * @param int    $webp_attachment_id WebP attachment ID.
	 * @return void
	 */
	private function upsert_family_row( $paths, $webp_file, $webp_attachment_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$paths         = array_values( array_unique( array_filter( array_map( array( $this, 'normalize_path' ), (array) $paths ) ) ) );
		$webp_file     = $this->normalize_path( (string) $webp_file );
		$webp_relative = $this->relative_to_uploads( $webp_file );

		if ( empty( $paths ) || '' === $webp_relative ) {
			return;
		}

		$relative_paths = array();
		foreach ( $paths as $path ) {
			$relative = $this->relative_to_uploads( $path );
			if ( '' !== $relative ) {
				$relative_paths[] = $relative;
			}
		}

		if ( empty( $relative_paths ) ) {
			return;
		}

		$representative          = $paths[0];
		$representative_relative = $relative_paths[0];
		$family_key              = $this->family_key_from_webp( $webp_file );
		$family_base             = preg_replace( '/\.webp$/i', '', basename( $webp_file ) );
		$table                   = self::table_name();
		$now                     = current_time( 'mysql', true );
		$data                    = array(
			'file_key'                   => $family_key,
			'family_key'                 => $family_key,
			'family_base'                => sanitize_file_name( (string) $family_base ),
			'relative_path'              => $representative_relative,
			'absolute_path'              => $representative,
			'file_paths'                 => wp_json_encode( $relative_paths, JSON_UNESCAPED_SLASHES ),
			'file_count'                 => count( $relative_paths ),
			'matched_webp_relative_path' => $webp_relative,
			'matched_webp_attachment_id' => absint( $webp_attachment_id ),
			'file_size'                  => $this->sum_file_sizes( $paths ),
			'status'                     => 'candidate',
			'last_error'                 => 'این خانواده کامل است، در وردپرس ثبت نشده و برای حذف آماده است.',
			'updated_at'                 => $now,
			'deleted_at'                 => null,
		);

		$existing_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_key = %s LIMIT 1", $family_key ) ) );

		if ( $existing_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			return;
		}

		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
	}

	/**
	 * Remove a stale actionable row when a family is now registered/referenced.
	 *
	 * @param string $webp_file WebP path.
	 * @return void
	 */
	private function remove_rescanable_family_row( $webp_file ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return;
		}

		$key = $this->family_key_from_webp( $webp_file );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . self::table_name() . " WHERE file_key = %s AND status IN ('candidate', 'skipped', 'failed')",
				$key
			)
		);
	}

	/**
	 * Update row state and current family snapshot.
	 *
	 * @param int    $id Row ID.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param bool   $deleted Whether fully deleted.
	 * @param int    $size Current/deleted size.
	 * @param int    $file_count File count.
	 * @param array  $paths Absolute paths.
	 * @return void
	 */
	private function update_row_status( $id, $status, $message, $deleted = false, $size = 0, $file_count = 0, $paths = array() ) {
		global $wpdb;

		$id = absint( $id );
		if ( $id <= 0 || ! self::table_exists() ) {
			return;
		}

		$relative_paths = array();
		foreach ( (array) $paths as $path ) {
			$relative = $this->relative_to_uploads( $path );
			if ( '' !== $relative ) {
				$relative_paths[] = $relative;
			}
		}

		$data = array(
			'status'     => sanitize_key( (string) $status ),
			'last_error' => sanitize_text_field( (string) $message ),
			'updated_at' => current_time( 'mysql', true ),
			'file_size'  => absint( $size ),
			'file_count' => absint( $file_count ),
			'file_paths' => wp_json_encode( array_values( array_unique( $relative_paths ) ), JSON_UNESCAPED_SLASHES ),
		);

		if ( $deleted ) {
			$data['deleted_at'] = current_time( 'mysql', true );
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
			$total += absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ) );
		}

		return $total;
	}

	/**
	 * Is this file part of the matching legacy family?
	 *
	 * @param string $candidate_path Candidate path.
	 * @param string $webp_file WebP path.
	 * @return bool
	 */
	private function is_same_base_legacy_file( $candidate_path, $webp_file, $webp_attachment_id = 0 ) {
		$candidate_path    = $this->normalize_path( (string) $candidate_path );
		$webp_file         = $this->normalize_path( (string) $webp_file );
		$webp_attachment_id = absint( $webp_attachment_id );

		if ( dirname( $candidate_path ) !== dirname( $webp_file ) ) {
			return false;
		}

		foreach ( $this->legacy_family_bases( $webp_file, $webp_attachment_id ) as $base ) {
			if ( 1 === preg_match( $this->legacy_family_pattern( $base ), basename( $candidate_path ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if path is referenced in common database locations.
	 *
	 * Full relative paths and upload URLs are checked first. The basename check is
	 * retained as a conservative final guard against manually embedded filenames.
	 *
	 * @param string $relative_path Uploads-relative path.
	 * @return bool
	 */
	private function is_referenced_in_database( $relative_path ) {
		global $wpdb;

		$relative_path = ltrim( $this->normalize_path( (string) $relative_path ), '/' );
		$name          = basename( $relative_path );
		$uploads       = wp_upload_dir( null, false );
		$baseurl       = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		$url           = '' !== $baseurl ? $baseurl . '/' . $relative_path : '';

		$needles = array_values(
			array_unique(
				array_filter(
					array(
						$relative_path,
						str_replace( '/', '\\/', $relative_path ),
						$url,
						$name,
					)
				)
			)
		);

		foreach ( $needles as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';

			if ( absint( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND (post_content LIKE %s OR guid LIKE %s) LIMIT 1", $like, $like ) ) ) > 0 ) {
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
	 * Family key from WebP relative path.
	 *
	 * @param string $webp_file WebP file.
	 * @return string
	 */
	private function family_key_from_webp( $webp_file ) {
		$relative = $this->relative_to_uploads( $webp_file );

		return md5( 'family|' . strtolower( $relative ) );
	}

	/**
	 * Sum existing file sizes.
	 *
	 * @param array $paths Paths.
	 * @return int
	 */
	private function sum_file_sizes( $paths ) {
		$total = 0;
		foreach ( (array) $paths as $path ) {
			if ( is_file( $path ) ) {
				$total += absint( filesize( $path ) );
			}
		}

		return $total;
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
	 * Normalize a relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_relative_path( $path ) {
		return ltrim( $this->normalize_path( (string) $path ), '/' );
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
