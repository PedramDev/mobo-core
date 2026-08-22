<?php
/**
 * Private shared-media adapter.
 *
 * This feature is intentionally configured only through server-level constants
 * or environment variables. It has no public admin setting and stores no secret
 * in the WordPress database.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Shared_Media {

	const META_IMAGE_ID = '_mobo_shared_media_image_id';
	const META_FILE     = '_mobo_shared_media_file';
	const META_REVISION = '_mobo_shared_media_revision';
	const META_PROFILE  = '_mobo_shared_media_profile_hash';

	/**
	 * Register URL/path filters only on private shared-media sites.
	 */
	public static function init() {
		if ( ! self::is_configured() ) {
			return;
		}

		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 20, 2 );
		add_filter( 'get_attached_file', array( __CLASS__, 'filter_attached_file' ), 20, 2 );
		add_filter( 'wp_get_attachment_metadata', array( __CLASS__, 'filter_attachment_metadata' ), 20, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_image_srcset' ), 20, 5 );
	}

	/**
	 * Whether this installation must use the shared repository.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! self::is_configured() ) {
			return false;
		}

		$root = self::root_path();
		return '' !== $root && is_dir( $root ) && is_readable( $root );
	}

	/**
	 * Whether this site is configured to use Shared Media, even when the mounted
	 * repository is temporarily unavailable. Storage/sync code must distinguish
	 * this from is_enabled(): a transient mount outage must not silently switch a
	 * strict shared site back to per-site downloads and recreate duplicate media.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return self::config_bool( 'MOBO_CORE_SHARED_MEDIA_ENABLED', false )
			&& '' !== self::root_path()
			&& '' !== self::base_url();
	}

	/**
	 * When false, a missing worker manifest keeps the image queue pending instead
	 * of downloading another private copy into this site's uploads directory.
	 *
	 * @return bool
	 */
	public static function allow_download_fallback() {
		return self::config_bool( 'MOBO_CORE_SHARED_MEDIA_FALLBACK_TO_DOWNLOAD', false );
	}

	/**
	 * Whether private sites should remove superseded per-site uploads after the
	 * shared attachment has been verified and installed.
	 *
	 * @return bool
	 */
	public static function should_delete_local_copies() {
		return self::config_bool( 'MOBO_CORE_SHARED_MEDIA_DELETE_LOCAL_COPIES', true );
	}

	/**
	 * Create or update one virtual WordPress attachment backed by shared files.
	 *
	 * @param string $image_id Remote ProductImage GUID.
	 * @param int    $product_id Parent product ID.
	 * @param string $source_url Original Mobo source URL.
	 * @param int    $existing_attachment_id Existing local/shared attachment candidate.
	 * @return int Attachment ID or 0 when the worker has not produced the manifest yet.
	 */
	public static function import_attachment( $image_id, $product_id, $source_url, $existing_attachment_id = 0 ) {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		$image_id  = self::normalize_image_id( $image_id );
		$product_id = absint( $product_id );
		$source_url = esc_url_raw( (string) $source_url );

		if ( '' === $image_id || $product_id <= 0 ) {
			return 0;
		}

		$manifest = self::read_manifest( $image_id );
		if ( empty( $manifest ) ) {
			return 0;
		}

		$validation = self::validate_manifest_files( $manifest, true );
		if ( empty( $validation['healthy'] ) ) {
			return 0;
		}

		$original = isset( $manifest['original'] ) && is_array( $manifest['original'] ) ? $manifest['original'] : array();
		$relative = isset( $validation['originalRelative'] ) ? (string) $validation['originalRelative'] : '';
		if ( '' === $relative ) {
			return 0;
		}

		/*
		 * Build metadata only after every manifest-advertised file has passed physical
		 * size, MIME and dimension validation. This makes Shared Media import atomic
		 * from WordPress' point of view: a partially written worker family can never
		 * become a usable attachment.
		 */
		$metadata = self::build_attachment_metadata( $manifest );
		if ( empty( $metadata ) ) {
			return 0;
		}

		$attachment_id = absint( $existing_attachment_id );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			$attachment_id = self::find_attachment( $image_id );
		}

		$old_files = array();
		if ( $attachment_id > 0 && ! self::is_shared_attachment( $attachment_id ) && self::config_bool( 'MOBO_CORE_SHARED_MEDIA_DELETE_LOCAL_COPIES', true ) ) {
			$old_files = self::collect_local_attachment_files( $attachment_id );
		}

		$existing_attachment = $attachment_id > 0;
		$rollback_snapshot   = $existing_attachment ? self::snapshot_attachment_state( $attachment_id ) : array();

		$mime  = isset( $original['mime'] ) ? sanitize_mime_type( (string) $original['mime'] ) : 'image/webp';
		$title = isset( $manifest['title'] ) ? sanitize_text_field( (string) $manifest['title'] ) : $image_id;
		$url   = self::url_for_file( $relative );

		$post_data = array(
			'post_mime_type' => $mime ? $mime : 'image/webp',
			'post_title'     => '' !== $title ? $title : $image_id,
			'post_status'    => 'inherit',
			'post_parent'    => $product_id,
			'guid'           => $url,
		);

		if ( $attachment_id > 0 ) {
			$post_data['ID'] = $attachment_id;
			$result = wp_update_post( wp_slash( $post_data ), true );
		} else {
			$result = wp_insert_attachment( wp_slash( $post_data ), false, $product_id, true );
		}

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$attachment_id = absint( $result );
		if ( $attachment_id <= 0 ) {
			return 0;
		}

		update_post_meta( $attachment_id, '_wp_attached_file', $relative );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
		update_post_meta( $attachment_id, self::META_IMAGE_ID, $image_id );
		update_post_meta( $attachment_id, 'image_guid', $image_id );
		update_post_meta( $attachment_id, 'img_guid', $image_id );
		update_post_meta( $attachment_id, self::META_FILE, $relative );
		update_post_meta( $attachment_id, self::META_REVISION, sanitize_text_field( isset( $manifest['revision'] ) ? (string) $manifest['revision'] : '' ) );
		update_post_meta( $attachment_id, self::META_PROFILE, sanitize_text_field( isset( $manifest['profileHash'] ) ? (string) $manifest['profileHash'] : '' ) );
		update_post_meta( $attachment_id, 'mobo_shared_media', '1' );
		update_post_meta( $attachment_id, 'mobo_image_format', 'webp' );

		if ( '' !== $source_url ) {
			update_post_meta( $attachment_id, 'mobo_source_url', $source_url );
		}

		clean_post_cache( $attachment_id );

		/* Treat post/meta mutation as committed only after the resulting virtual
		 * attachment can be read back against the same complete manifest. If a DB
		 * write was interrupted, retain any previous local files and let the queue
		 * retry instead of deleting the last known-good copy. */
		$committed_health = self::attachment_health( $attachment_id, true );
		if ( empty( $committed_health['healthy'] ) ) {
			/* Never expose a half-converted virtual attachment. Existing attachments
			 * are restored byte-for-byte at the post/meta level; newly-created virtual
			 * rows are removed from the database without invoking attachment deletion
			 * hooks that could unlink read-only Shared Media files. */
			if ( $existing_attachment && ! empty( $rollback_snapshot ) ) {
				self::restore_attachment_state( $attachment_id, $rollback_snapshot );
			} elseif ( ! $existing_attachment ) {
				self::delete_virtual_attachment_record( $attachment_id );
			}
			return 0;
		}
		delete_post_meta( $attachment_id, 'mobo_sync_incomplete' );

		if ( ! empty( $old_files ) ) {
			self::delete_old_local_files( $old_files );
		}

		return $attachment_id;
	}

	/**
	 * Return shared public URL for virtual attachments.
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		$relative = self::attachment_relative_file( $attachment_id );
		return '' !== $relative ? self::url_for_file( $relative ) : $url;
	}

	/**
	 * Return the read-only local path so image dimensions and diagnostics work.
	 */
	public static function filter_attached_file( $file, $attachment_id ) {
		$relative = self::attachment_relative_file( $attachment_id );
		if ( '' === $relative ) {
			return $file;
		}

		$path = self::absolute_file( $relative );
		return '' !== $path ? $path : $file;
	}

	/**
	 * Add missing WordPress/WooCommerce aliases to existing shared attachments.
	 *
	 * Older shared attachments may contain the centrally generated dimension keys
	 * (for example 600x800 and 768x1024) without the registered aliases used by
	 * wp_get_attachment_image_src(), such as woocommerce_single or large. Returning
	 * the augmented metadata makes those existing attachments use the generated
	 * shared-media cuts immediately after the plugin update, without rewriting the
	 * database or generating per-site files.
	 *
	 * @param array|false $metadata Attachment metadata.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public static function filter_attachment_metadata( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || ! self::is_shared_attachment( $attachment_id ) ) {
			return $metadata;
		}

		return self::add_registered_size_aliases( $metadata );
	}

	/**
	 * Rewrite responsive-image source URLs for shared attachments.
	 *
	 * WordPress builds srcset candidates from the uploads base URL and attachment
	 * metadata. Shared attachments intentionally keep paths such as
	 * objects/ab/cd/file--300x300.webp in that metadata, so without this filter
	 * WordPress prefixes the site's wp-content/uploads URL. Replace only those
	 * shared-media candidates with the configured public shared-media base URL.
	 *
	 * @param array|false $sources Responsive image sources keyed by width.
	 * @param array       $size_array Requested image dimensions.
	 * @param string      $image_src Selected image URL.
	 * @param array       $image_meta Attachment metadata.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public static function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		$original_sources = $sources;

		try {
			if ( ! self::is_shared_attachment( $attachment_id ) ) {
				return $sources;
			}

			foreach ( $sources as $width => $source ) {
				if ( ! is_array( $source ) || empty( $source['url'] ) ) {
					continue;
				}

				$relative = self::shared_relative_file_from_url( $source['url'] );
				if ( '' === $relative ) {
					continue;
				}

				$sources[ $width ]['url'] = self::url_for_file( $relative );
			}
		} catch ( Throwable $error ) {
			return $original_sources;
		}

		return $sources;
	}

	/**
	 * Refresh only WordPress metadata for an existing shared attachment from the
	 * current committed worker manifest. No image file is generated or modified.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Attachment ID or 0.
	 */
	public static function refresh_attachment_from_manifest( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$image_id      = self::normalize_image_id( get_post_meta( $attachment_id, self::META_IMAGE_ID, true ) );
		if ( $attachment_id <= 0 || ! self::is_enabled() || ! self::is_shared_attachment( $attachment_id ) || '' === $image_id ) {
			return 0;
		}

		$lock_name  = 'image_import_guid_' . substr( hash( 'sha256', strtolower( $image_id ) ), 0, 32 );
		$lock_token = class_exists( 'Mobo_Core_Lock' ) ? Mobo_Core_Lock::acquire( $lock_name, 300 ) : 'no-lock-class';
		if ( false === $lock_token ) {
			return 0;
		}

		try {
			return self::refresh_attachment_from_manifest_unlocked( $attachment_id, $image_id );
		} finally {
			if ( class_exists( 'Mobo_Core_Lock' ) && 'no-lock-class' !== $lock_token ) {
				Mobo_Core_Lock::release( $lock_name, $lock_token );
			}
		}
	}

	/**
	 * Commit refreshed Shared Media metadata while the remote GUID lock is held.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $image_id Remote image GUID.
	 * @return int
	 */
	private static function refresh_attachment_from_manifest_unlocked( $attachment_id, $image_id ) {
		$manifest = self::read_manifest( $image_id );
		if ( empty( $manifest ) ) {
			return 0;
		}

		$validation = self::validate_manifest_files( $manifest, true );
		$metadata   = ! empty( $validation['healthy'] ) ? self::build_attachment_metadata( $manifest ) : array();
		$relative   = isset( $validation['originalRelative'] ) ? (string) $validation['originalRelative'] : '';
		if ( empty( $metadata ) || '' === $relative ) {
			return 0;
		}

		$original          = isset( $manifest['original'] ) && is_array( $manifest['original'] ) ? $manifest['original'] : array();
		$mime              = isset( $original['mime'] ) ? sanitize_mime_type( (string) $original['mime'] ) : 'image/webp';
		$title             = isset( $manifest['title'] ) ? sanitize_text_field( (string) $manifest['title'] ) : $image_id;
		$url               = self::url_for_file( $relative );
		$rollback_snapshot = self::snapshot_attachment_state( $attachment_id );
		$result            = wp_update_post(
			wp_slash(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $mime ? $mime : 'image/webp',
					'post_title'     => '' !== $title ? $title : $image_id,
					'guid'           => $url,
				)
			),
			true
		);
		if ( is_wp_error( $result ) || absint( $result ) <= 0 ) {
			return 0;
		}

		update_post_meta( $attachment_id, '_wp_attached_file', $relative );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
		update_post_meta( $attachment_id, self::META_IMAGE_ID, $image_id );
		update_post_meta( $attachment_id, 'image_guid', $image_id );
		update_post_meta( $attachment_id, 'img_guid', $image_id );
		update_post_meta( $attachment_id, self::META_FILE, $relative );
		update_post_meta( $attachment_id, self::META_REVISION, sanitize_text_field( isset( $manifest['revision'] ) ? (string) $manifest['revision'] : '' ) );
		update_post_meta( $attachment_id, self::META_PROFILE, sanitize_text_field( isset( $manifest['profileHash'] ) ? (string) $manifest['profileHash'] : '' ) );
		update_post_meta( $attachment_id, 'mobo_shared_media', '1' );
		update_post_meta( $attachment_id, 'mobo_image_format', 'webp' );
		clean_post_cache( $attachment_id );

		$committed_health = self::attachment_health( $attachment_id, true );
		if ( empty( $committed_health['healthy'] ) ) {
			if ( ! empty( $rollback_snapshot ) ) {
				self::restore_attachment_state( $attachment_id, $rollback_snapshot );
			}
			return 0;
		}
		delete_post_meta( $attachment_id, 'mobo_sync_incomplete' );

		return $attachment_id;
	}

	/**
	 * Verify that a virtual attachment still has its complete worker manifest and
	 * all generated files. This intentionally ignores unrelated WordPress image
	 * sizes that are not part of the centrally approved profile.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public static function attachment_health( $attachment_id, $deep = true ) {
		$attachment_id = absint( $attachment_id );
		$image_id      = self::normalize_image_id( get_post_meta( $attachment_id, self::META_IMAGE_ID, true ) );

		if ( $attachment_id <= 0 || ! self::is_shared_attachment( $attachment_id ) || '' === $image_id ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared attachment metadata is invalid.' );
		}

		/* All persisted identity fields must agree. A partial DB write can leave the
		 * manifest pointer correct while image_guid/post MIME still belong to the old
		 * local attachment; treating that state as healthy causes future resolver
		 * decisions to oscillate between identities. */
		$stored_image_guid = self::normalize_image_id( get_post_meta( $attachment_id, 'image_guid', true ) );
		$stored_img_guid   = self::normalize_image_id( get_post_meta( $attachment_id, 'img_guid', true ) );
		if ( '' === $stored_image_guid || '' === $stored_img_guid
			|| ! hash_equals( strtolower( $image_id ), strtolower( $stored_image_guid ) )
			|| ! hash_equals( strtolower( $image_id ), strtolower( $stored_img_guid ) ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared attachment GUID identity fields are incomplete or inconsistent.' );
		}

		if ( 'webp' !== strtolower( trim( (string) get_post_meta( $attachment_id, 'mobo_image_format', true ) ) ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared attachment format marker is stale.' );
		}

		$manifest = self::read_manifest( $image_id );
		if ( empty( $manifest ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared-media manifest is missing or incompatible.' );
		}

		$manifest_original = isset( $manifest['original'] ) && is_array( $manifest['original'] ) ? $manifest['original'] : array();
		$expected_mime     = strtolower( sanitize_mime_type( isset( $manifest_original['mime'] ) ? (string) $manifest_original['mime'] : 'image/webp' ) );
		$expected_mime     = 'image/jpg' === $expected_mime ? 'image/jpeg' : $expected_mime;
		$stored_post_mime  = strtolower( (string) get_post_mime_type( $attachment_id ) );
		if ( '' !== $expected_mime && ! hash_equals( $expected_mime, $stored_post_mime ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared attachment post MIME does not match the committed manifest.' );
		}

		$manifest_revision = sanitize_text_field( isset( $manifest['revision'] ) ? (string) $manifest['revision'] : '' );
		$stored_revision   = sanitize_text_field( (string) get_post_meta( $attachment_id, self::META_REVISION, true ) );
		if ( '' !== $manifest_revision && ! hash_equals( $manifest_revision, $stored_revision ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared-media manifest revision changed and the attachment must be refreshed.' );
		}

		$manifest_profile = strtolower( trim( isset( $manifest['profileHash'] ) ? (string) $manifest['profileHash'] : '' ) );
		$stored_profile   = strtolower( trim( (string) get_post_meta( $attachment_id, self::META_PROFILE, true ) ) );
		if ( '' !== $manifest_profile && ! hash_equals( $manifest_profile, $stored_profile ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared-media profile changed and the attachment metadata must be refreshed.' );
		}

		$validation = self::validate_manifest_files( $manifest, (bool) $deep );
		if ( empty( $validation['healthy'] ) ) {
			return array(
				'healthy'    => false,
				'registered' => isset( $validation['registered'] ) ? absint( $validation['registered'] ) : 0,
				'message'    => isset( $validation['message'] ) ? (string) $validation['message'] : 'Shared-media files failed validation.',
			);
		}

		$expected_relative = isset( $validation['originalRelative'] ) ? (string) $validation['originalRelative'] : '';
		$stored_relative   = self::safe_relative_file( get_post_meta( $attachment_id, self::META_FILE, true ) );
		if ( '' === $stored_relative || '' === $expected_relative || ! hash_equals( $expected_relative, $stored_relative ) ) {
			return array( 'healthy' => false, 'registered' => absint( $validation['registered'] ), 'message' => 'Shared attachment points to an outdated manifest file.' );
		}

		$stored_attached_file = self::safe_relative_file( get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		if ( '' === $stored_attached_file || ! hash_equals( $expected_relative, $stored_attached_file ) ) {
			return array( 'healthy' => false, 'registered' => absint( $validation['registered'] ), 'message' => 'WordPress attached-file metadata is not committed to the current shared manifest.' );
		}

		$expected_metadata = self::build_attachment_metadata( $manifest );
		$stored_metadata   = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		if ( empty( $expected_metadata ) || ! self::stored_attachment_metadata_matches( $stored_metadata, $expected_metadata ) ) {
			return array( 'healthy' => false, 'registered' => absint( $validation['registered'] ), 'message' => 'WordPress attachment metadata is incomplete or stale for the current shared manifest.' );
		}

		return array(
			'healthy'    => true,
			'registered' => absint( $validation['registered'] ),
			'message'    => 'Shared-media manifest and every advertised generated file passed integrity checks.',
		);
	}

	/**
	 * Verify the persisted WordPress attachment metadata contains every manifest
	 * field required for safe image resolution. Extra plugin-added metadata is
	 * allowed; only the centrally committed file/dimension rows are authoritative.
	 *
	 * @param mixed $stored Persisted _wp_attachment_metadata value.
	 * @param array $expected Metadata built from the current manifest.
	 * @return bool
	 */
	private static function stored_attachment_metadata_matches( $stored, $expected ) {
		if ( ! is_array( $stored ) || ! is_array( $expected ) ) {
			return false;
		}

		foreach ( array( 'width', 'height' ) as $key ) {
			if ( absint( isset( $stored[ $key ] ) ? $stored[ $key ] : 0 ) !== absint( isset( $expected[ $key ] ) ? $expected[ $key ] : 0 ) ) {
				return false;
			}
		}

		$stored_file   = self::safe_relative_syntax( isset( $stored['file'] ) ? $stored['file'] : '' );
		$expected_file = self::safe_relative_syntax( isset( $expected['file'] ) ? $expected['file'] : '' );
		if ( '' === $stored_file || '' === $expected_file || ! hash_equals( $expected_file, $stored_file ) ) {
			return false;
		}

		$stored_sizes   = isset( $stored['sizes'] ) && is_array( $stored['sizes'] ) ? $stored['sizes'] : array();
		$expected_sizes = isset( $expected['sizes'] ) && is_array( $expected['sizes'] ) ? $expected['sizes'] : array();
		foreach ( $expected_sizes as $name => $expected_row ) {
			if ( ! is_array( $expected_row ) || ! isset( $stored_sizes[ $name ] ) || ! is_array( $stored_sizes[ $name ] ) ) {
				return false;
			}
			$stored_row = $stored_sizes[ $name ];
			if ( basename( (string) ( isset( $stored_row['file'] ) ? $stored_row['file'] : '' ) ) !== basename( (string) ( isset( $expected_row['file'] ) ? $expected_row['file'] : '' ) )
				|| absint( isset( $stored_row['width'] ) ? $stored_row['width'] : 0 ) !== absint( isset( $expected_row['width'] ) ? $expected_row['width'] : 0 )
				|| absint( isset( $stored_row['height'] ) ? $stored_row['height'] : 0 ) !== absint( isset( $expected_row['height'] ) ? $expected_row['height'] : 0 ) ) {
				return false;
			}

			$expected_mime = strtolower( sanitize_mime_type( isset( $expected_row['mime-type'] ) ? (string) $expected_row['mime-type'] : '' ) );
			$stored_mime   = strtolower( sanitize_mime_type( isset( $stored_row['mime-type'] ) ? (string) $stored_row['mime-type'] : '' ) );
			if ( '' !== $expected_mime && $stored_mime !== $expected_mime ) {
				return false;
			}

			$expected_bytes = absint( isset( $expected_row['filesize'] ) ? $expected_row['filesize'] : 0 );
			$stored_bytes   = absint( isset( $stored_row['filesize'] ) ? $stored_row['filesize'] : 0 );
			if ( $expected_bytes > 0 && $stored_bytes !== $expected_bytes ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate every physical file advertised by a worker manifest before the
	 * attachment is created/reused. The manifest is the commit record: if any cut
	 * is missing, empty, the wrong MIME/dimensions, or has an unexpected byte count,
	 * the family is treated as not ready and the image queue retries later.
	 *
	 * @param array $manifest Worker manifest.
	 * @return array
	 */
	private static function validate_manifest_files( $manifest, $deep = true ) {
		if ( ! is_array( $manifest ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => 'Shared-media manifest is invalid.', 'originalRelative' => '' );
		}

		$original = isset( $manifest['original'] ) && is_array( $manifest['original'] ) ? $manifest['original'] : array();
		$original_check = self::validate_manifest_file_row( $original, 'original', (bool) $deep );
		if ( empty( $original_check['healthy'] ) ) {
			return array( 'healthy' => false, 'registered' => 0, 'message' => isset( $original_check['message'] ) ? $original_check['message'] : 'Shared-media original failed validation.', 'originalRelative' => '' );
		}

		$sizes = isset( $manifest['sizes'] ) && is_array( $manifest['sizes'] ) ? $manifest['sizes'] : array();
		foreach ( $sizes as $name => $row ) {
			if ( ! is_array( $row ) ) {
				return array( 'healthy' => false, 'registered' => count( $sizes ), 'message' => 'Shared-media size manifest row is invalid: ' . sanitize_key( (string) $name ) . '.', 'originalRelative' => $original_check['relative'] );
			}

			$check = self::validate_manifest_file_row( $row, sanitize_key( (string) $name ), (bool) $deep );
			if ( empty( $check['healthy'] ) ) {
				return array( 'healthy' => false, 'registered' => count( $sizes ), 'message' => isset( $check['message'] ) ? $check['message'] : 'Shared-media generated size failed validation.', 'originalRelative' => $original_check['relative'] );
			}
		}

		return array(
			'healthy'          => true,
			'registered'       => count( $sizes ),
			'message'          => 'Shared-media manifest files are complete.',
			'originalRelative' => $original_check['relative'],
		);
	}

	/** @return array */
	private static function validate_manifest_file_row( $row, $label, $deep = true ) {
		$relative = self::safe_relative_file( isset( $row['file'] ) ? $row['file'] : '' );
		$width    = isset( $row['width'] ) ? absint( $row['width'] ) : 0;
		$height   = isset( $row['height'] ) ? absint( $row['height'] ) : 0;
		$label    = sanitize_text_field( (string) $label );

		if ( '' === $relative || $width <= 0 || $height <= 0 ) {
			return array( 'healthy' => false, 'relative' => '', 'message' => 'Shared-media file metadata is incomplete: ' . $label . '.' );
		}

		$absolute = self::absolute_file( $relative );
		if ( '' === $absolute || ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return array( 'healthy' => false, 'relative' => $relative, 'message' => 'Shared-media file is missing or unreadable: ' . $label . '.' );
		}

		$actual_bytes = filesize( $absolute );
		if ( false === $actual_bytes || $actual_bytes <= 0 ) {
			return array( 'healthy' => false, 'relative' => $relative, 'message' => 'Shared-media file is empty: ' . $label . '.' );
		}

		$expected_bytes = isset( $row['bytes'] ) ? absint( $row['bytes'] ) : 0;
		if ( $expected_bytes > 0 && $expected_bytes !== absint( $actual_bytes ) ) {
			return array( 'healthy' => false, 'relative' => $relative, 'message' => 'Shared-media file size does not match its committed manifest: ' . $label . '.' );
		}

		if ( $deep && function_exists( 'wp_get_image_mime' ) ) {
			$actual_mime   = strtolower( (string) wp_get_image_mime( $absolute ) );
			$expected_mime = strtolower( sanitize_mime_type( isset( $row['mime'] ) ? (string) $row['mime'] : 'image/webp' ) );
			$expected_mime = 'image/jpg' === $expected_mime ? 'image/jpeg' : $expected_mime;
			if ( '' === $actual_mime || 0 !== strpos( $actual_mime, 'image/' ) || ( '' !== $expected_mime && $expected_mime !== $actual_mime ) ) {
				return array( 'healthy' => false, 'relative' => $relative, 'message' => 'Shared-media file MIME does not match its manifest: ' . $label . '.' );
			}
		}

		if ( $deep ) {
			$dimensions = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $absolute ) : @getimagesize( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fallback only when WordPress helper is unavailable.
			if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) || absint( $dimensions[0] ) !== $width || absint( $dimensions[1] ) !== $height ) {
				return array( 'healthy' => false, 'relative' => $relative, 'message' => 'Shared-media file dimensions do not match its manifest: ' . $label . '.' );
			}
		}

		return array( 'healthy' => true, 'relative' => $relative, 'message' => '' );
	}

	private static function build_attachment_metadata( $manifest ) {
		$original = isset( $manifest['original'] ) && is_array( $manifest['original'] ) ? $manifest['original'] : array();
		$relative = self::safe_relative_file( isset( $original['file'] ) ? $original['file'] : '' );
		$width    = isset( $original['width'] ) ? absint( $original['width'] ) : 0;
		$height   = isset( $original['height'] ) ? absint( $original['height'] ) : 0;

		if ( '' === $relative || $width <= 0 || $height <= 0 ) {
			return array();
		}

		$metadata = array(
			'width'      => $width,
			'height'     => $height,
			'file'       => $relative,
			'filesize'   => isset( $original['bytes'] ) ? absint( $original['bytes'] ) : 0,
			'sizes'      => array(),
			'image_meta' => array(),
		);

		$sizes = isset( $manifest['sizes'] ) && is_array( $manifest['sizes'] ) ? $manifest['sizes'] : array();
		foreach ( $sizes as $name => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$file = self::safe_relative_file( isset( $row['file'] ) ? $row['file'] : '' );
			$sw   = isset( $row['width'] ) ? absint( $row['width'] ) : 0;
			$sh   = isset( $row['height'] ) ? absint( $row['height'] ) : 0;
			if ( '' === $file || $sw <= 0 || $sh <= 0 ) {
				continue;
			}

			$size_row = array(
				'file'      => basename( $file ),
				'width'     => $sw,
				'height'    => $sh,
				'mime-type' => isset( $row['mime'] ) ? sanitize_mime_type( (string) $row['mime'] ) : 'image/webp',
				'filesize'  => isset( $row['bytes'] ) ? absint( $row['bytes'] ) : 0,
			);
			$metadata['sizes'][ sanitize_key( (string) $name ) ] = $size_row;
		}

		return self::add_registered_size_aliases( $metadata );
	}

	/**
	 * Map registered WordPress/WooCommerce image-size names to generated shared cuts.
	 *
	 * Exact width/height matches retain the existing behavior. When a registered
	 * size is a bounding box (for example 1024x1024) or has one unconstrained
	 * dimension (for example 600x0), WordPress' own resize calculation is used to
	 * resolve the actual output dimensions before looking up the worker-generated
	 * file. This maps, among others, large and medium_large to 768x1024 and
	 * woocommerce_single to 600x800 for a 960x1280 source image.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return array
	 */
	private static function add_registered_size_aliases( $metadata ) {
		if ( ! is_array( $metadata ) || ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
			return $metadata;
		}

		$original_width  = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
		$original_height = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
		if ( $original_width <= 0 || $original_height <= 0 || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $metadata;
		}

		$dimension_map = array();
		foreach ( $metadata['sizes'] as $size_row ) {
			if ( ! is_array( $size_row ) || empty( $size_row['file'] ) ) {
				continue;
			}

			$size_width  = isset( $size_row['width'] ) ? absint( $size_row['width'] ) : 0;
			$size_height = isset( $size_row['height'] ) ? absint( $size_row['height'] ) : 0;
			if ( $size_width <= 0 || $size_height <= 0 ) {
				continue;
			}

			$key = $size_width . 'x' . $size_height;
			if ( ! isset( $dimension_map[ $key ] ) ) {
				$dimension_map[ $key ] = $size_row;
			}
		}

		foreach ( wp_get_registered_image_subsizes() as $registered_name => $definition ) {
			$alias = sanitize_key( (string) $registered_name );
			if ( '' === $alias || isset( $metadata['sizes'][ $alias ] ) || ! is_array( $definition ) ) {
				continue;
			}

			$requested_width  = isset( $definition['width'] ) ? absint( $definition['width'] ) : 0;
			$requested_height = isset( $definition['height'] ) ? absint( $definition['height'] ) : 0;
			$crop             = isset( $definition['crop'] ) ? $definition['crop'] : false;

			if ( $requested_width <= 0 && $requested_height <= 0 ) {
				continue;
			}

			/* Preserve the previous exact-dimension mapping whenever available. */
			$exact_key = $requested_width . 'x' . $requested_height;
			if ( $requested_width > 0 && $requested_height > 0 && isset( $dimension_map[ $exact_key ] ) ) {
				$metadata['sizes'][ $alias ] = $dimension_map[ $exact_key ];
				continue;
			}

			$resolved = self::resolve_registered_size_dimensions(
				$original_width,
				$original_height,
				$requested_width,
				$requested_height,
				$crop
			);

			if ( empty( $resolved ) ) {
				continue;
			}

			$resolved_key = absint( $resolved[0] ) . 'x' . absint( $resolved[1] );
			if ( isset( $dimension_map[ $resolved_key ] ) ) {
				$metadata['sizes'][ $alias ] = $dimension_map[ $resolved_key ];
			}
		}

		return $metadata;
	}

	/**
	 * Resolve the output dimensions WordPress expects for a registered image size.
	 *
	 * @return array<int,int> Empty when WordPress would use the original image.
	 */
	private static function resolve_registered_size_dimensions( $original_width, $original_height, $requested_width, $requested_height, $crop ) {
		if ( ! function_exists( 'image_resize_dimensions' ) ) {
			return array();
		}

		$dimensions = image_resize_dimensions(
			absint( $original_width ),
			absint( $original_height ),
			absint( $requested_width ),
			absint( $requested_height ),
			$crop
		);

		if ( ! is_array( $dimensions ) || ! isset( $dimensions[4], $dimensions[5] ) ) {
			return array();
		}

		$output_width  = absint( $dimensions[4] );
		$output_height = absint( $dimensions[5] );

		return $output_width > 0 && $output_height > 0
			? array( $output_width, $output_height )
			: array();
	}

	private static function read_manifest( $image_id ) {
		$path = self::root_path() . '/metadata/' . self::shard( $image_id ) . '/' . $image_id . '.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$manifest = json_decode( $raw, true );
		if ( ! is_array( $manifest ) || 1 !== absint( isset( $manifest['schemaVersion'] ) ? $manifest['schemaVersion'] : 0 ) ) {
			return array();
		}

		if ( $image_id !== self::normalize_image_id( isset( $manifest['imageId'] ) ? $manifest['imageId'] : '' ) ) {
			return array();
		}

		$expected_profile = strtolower( trim( self::config_string( 'MOBO_CORE_SHARED_MEDIA_PROFILE_HASH', '' ) ) );
		$actual_profile   = strtolower( trim( isset( $manifest['profileHash'] ) ? (string) $manifest['profileHash'] : '' ) );
		if ( '' !== $expected_profile && ! hash_equals( $expected_profile, $actual_profile ) ) {
			return array();
		}

		return $manifest;
	}

	/**
	 * Snapshot every post field/meta key mutated by Shared Media installation so a
	 * detected partial commit can be rolled back without touching physical files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private static function snapshot_attachment_state( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$post          = get_post( $attachment_id );
		if ( $attachment_id <= 0 || ! $post || 'attachment' !== $post->post_type ) {
			return array();
		}

		$keys = array(
			'_wp_attached_file',
			'_wp_attachment_metadata',
			self::META_IMAGE_ID,
			'image_guid',
			'img_guid',
			self::META_FILE,
			self::META_REVISION,
			self::META_PROFILE,
			'mobo_shared_media',
			'mobo_image_format',
			'mobo_source_url',
			'mobo_sync_incomplete',
		);
		$meta = array();
		foreach ( $keys as $key ) {
			$exists       = metadata_exists( 'post', $attachment_id, $key );
			$meta[ $key ] = array(
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( $attachment_id, $key, true ) : null,
			);
		}

		return array(
			'post' => array(
				'post_mime_type' => (string) $post->post_mime_type,
				'post_title'     => (string) $post->post_title,
				'post_status'    => (string) $post->post_status,
				'post_parent'    => absint( $post->post_parent ),
				'guid'           => (string) $post->guid,
			),
			'meta' => $meta,
		);
	}

	/**
	 * Restore a Shared Media mutation snapshot after post-write verification fails.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $snapshot Snapshot from snapshot_attachment_state().
	 * @return void
	 */
	private static function restore_attachment_state( $attachment_id, $snapshot ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 || ! is_array( $snapshot ) || empty( $snapshot['post'] ) ) {
			return;
		}

		$post = is_array( $snapshot['post'] ) ? $snapshot['post'] : array();
		wp_update_post(
			wp_slash(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => isset( $post['post_mime_type'] ) ? (string) $post['post_mime_type'] : '',
					'post_title'     => isset( $post['post_title'] ) ? (string) $post['post_title'] : '',
					'post_status'    => isset( $post['post_status'] ) ? (string) $post['post_status'] : 'inherit',
					'post_parent'    => isset( $post['post_parent'] ) ? absint( $post['post_parent'] ) : 0,
					'guid'           => isset( $post['guid'] ) ? (string) $post['guid'] : '',
				)
			)
		);

		foreach ( isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : array() as $key => $row ) {
			if ( ! is_array( $row ) || empty( $row['exists'] ) ) {
				delete_post_meta( $attachment_id, $key );
				continue;
			}
			update_post_meta( $attachment_id, $key, isset( $row['value'] ) ? $row['value'] : '' );
		}
		clean_post_cache( $attachment_id );
	}

	/**
	 * Remove a newly-created virtual attachment row after a failed commit without
	 * calling wp_delete_attachment(), which could try to unlink worker-owned files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private static function delete_virtual_attachment_record( $attachment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return;
		}

		$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $attachment_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $attachment_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->posts, array( 'ID' => $attachment_id, 'post_type' => 'attachment' ), array( '%d', '%s' ) );
		clean_post_cache( $attachment_id );
	}

	private static function find_attachment( $image_id ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded GUID lookup.
				'meta_query'             => array(
					array(
						'key'   => self::META_IMAGE_ID,
						'value' => $image_id,
					),
				),
			)
		);

		return ! empty( $query->posts[0] ) ? absint( $query->posts[0] ) : 0;
	}

	public static function is_shared_attachment( $attachment_id ) {
		return '1' === (string) get_post_meta( absint( $attachment_id ), 'mobo_shared_media', true );
	}

	private static function attachment_relative_file( $attachment_id ) {
		if ( ! self::is_configured() || ! self::is_shared_attachment( $attachment_id ) ) {
			return '';
		}

		/* URL generation only needs a syntactically safe committed relative path.
		 * Physical readability is checked separately by filter_attached_file()/health.
		 * This keeps public Shared Media URLs stable during a temporary local mount outage. */
		return self::safe_relative_syntax( get_post_meta( absint( $attachment_id ), self::META_FILE, true ) );
	}

	private static function collect_local_attachment_files( $attachment_id ) {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$file    = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$meta    = wp_get_attachment_metadata( $attachment_id );
		$files   = array();

		if ( '' === $base || '' === $file || 0 === strpos( $file, '/' ) || false !== strpos( $file, '..' ) ) {
			return array();
		}

		$files[] = trailingslashit( $base ) . ltrim( $file, '/' );
		$dir = dirname( $file );
		$dir = '.' === $dir ? '' : trailingslashit( $dir );

		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['file'] ) ) {
					$files[] = trailingslashit( $base ) . $dir . basename( (string) $row['file'] );
				}
			}
		}

		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$files[] = trailingslashit( $base ) . $dir . basename( (string) $meta['original_image'] );
		}

		return array_values( array_unique( $files ) );
	}

	private static function delete_old_local_files( $files ) {
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		if ( '' === $base ) {
			return;
		}

		$prefix = trailingslashit( $base );
		foreach ( (array) $files as $file ) {
			$normalized = wp_normalize_path( (string) $file );
			if ( 0 !== strpos( $normalized, $prefix ) || ! is_file( $normalized ) ) {
				continue;
			}
			wp_delete_file( $normalized );
		}
	}

	private static function safe_relative_file( $value ) {
		$value = self::safe_relative_syntax( $value );
		return '' !== $value && '' !== self::absolute_file( $value ) ? $value : '';
	}

	/**
	 * Validate only the relative-path syntax without requiring the shared mount to
	 * be readable. Used for public URL rewriting during transient repository outages.
	 *
	 * @param mixed $value Relative path candidate.
	 * @return string
	 */
	private static function safe_relative_syntax( $value ) {
		$value = str_replace( '\\', '/', trim( (string) $value ) );
		if ( '' === $value || '/' === substr( $value, 0, 1 ) || false !== strpos( $value, "\0" ) ) {
			return '';
		}

		$parts = explode( '/', $value );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return '';
			}
		}

		return $value;
	}

	private static function absolute_file( $relative ) {
		$root = self::root_path();
		if ( '' === $root ) {
			return '';
		}

		$root_real = realpath( $root );
		$file_real = realpath( trailingslashit( $root ) . ltrim( (string) $relative, '/' ) );
		if ( false === $root_real || false === $file_real ) {
			return '';
		}

		$root_real = wp_normalize_path( $root_real );
		$file_real = wp_normalize_path( $file_real );
		if ( 0 !== strpos( $file_real, trailingslashit( $root_real ) ) || ! is_file( $file_real ) || ! is_readable( $file_real ) ) {
			return '';
		}

		return $file_real;
	}

	/**
	 * Extract and validate an objects/... shared-media path from a generated URL.
	 *
	 * @param string $url Candidate image URL.
	 * @return string
	 */
	private static function shared_relative_file_from_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$path     = '/' . ltrim( str_replace( '\\', '/', rawurldecode( $path ) ), '/' );
		$marker   = '/objects/';
		$position = strpos( $path, $marker );
		if ( false === $position ) {
			return '';
		}

		$relative = ltrim( substr( $path, $position + 1 ), '/' );
		return self::safe_relative_syntax( $relative );
	}

	private static function url_for_file( $relative ) {
		return trailingslashit( self::base_url() ) . str_replace( '%2F', '/', rawurlencode( str_replace( '\\', '/', $relative ) ) );
	}

	private static function root_path() {
		$value = self::config_string( 'MOBO_CORE_SHARED_MEDIA_ROOT', '' );
		return '' !== $value ? untrailingslashit( wp_normalize_path( $value ) ) : '';
	}

	private static function base_url() {
		$value = esc_url_raw( self::config_string( 'MOBO_CORE_SHARED_MEDIA_BASE_URL', '' ) );
		return '' !== $value ? untrailingslashit( $value ) : '';
	}

	private static function normalize_image_id( $value ) {
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
		return preg_match( '/^[a-z0-9][a-z0-9_-]{2,190}$/', $value ) ? $value : '';
	}

	private static function shard( $image_id ) {
		$hex = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $image_id ) );
		$hex = str_pad( $hex, 4, '0' );
		return substr( $hex, 0, 2 ) . '/' . substr( $hex, 2, 2 );
	}

	private static function config_string( $name, $default = '' ) {
		if ( defined( $name ) ) {
			$value = constant( $name );
		} else {
			$value = getenv( $name );
		}
		return false === $value || null === $value ? (string) $default : trim( (string) $value );
	}

	private static function config_bool( $name, $default = false ) {
		$value = strtolower( self::config_string( $name, $default ? '1' : '0' ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}
}
