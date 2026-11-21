<?php
/**
 * Pure classification rules for legacy Mobo raster (JPG/PNG) attachments.
 *
 * Older MoboCore versions downloaded product images as JPG/PNG straight from
 * the Source store into the media library and marked them only with the
 * `img_guid` postmeta (later also `image_guid`). Newer versions import resized
 * WebP images from the Backend. This class decides, from facts gathered by the
 * read-only scanner, what a legacy attachment is. It never touches WordPress,
 * the database or the filesystem itself (filesystem probes are injected), so
 * it is fully unit-testable.
 *
 * Ownership proof is GUID-first and identical to Mobo_Core_Image_Prune_Queue:
 * a valid `image_guid`/`img_guid` (agreeing when both exist) proves the plugin
 * created the attachment. `mobo_source_url` alone, a file name, an upload date
 * or a matching product never prove ownership. Anything not provably created
 * by the plugin is a merchant upload and is always protected.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Legacy_Image_Policy {

	/* Not a JPG/PNG attachment (WebP, SVG, video, ...): out of scope. */
	const CLASS_NOT_LEGACY = 'not_legacy';
	/* (c) No provable plugin ownership: merchant upload, never touched. */
	const CLASS_PROTECTED_NOT_MOBO = 'protected_not_mobo';
	/* Ownership markers present but conflicting/invalid, unsafe path or an incomplete check. */
	const CLASS_PROTECTED_UNCERTAIN = 'protected_uncertain';
	/* Shared Media attachments are owned by the central worker. */
	const CLASS_PROTECTED_SHARED_MEDIA = 'protected_shared_media';
	/* Another attachment row points at the same physical file (very old versions overwrote by basename). */
	const CLASS_PROTECTED_SHARED_FILE = 'protected_shared_file';
	/* Still the current desired-state attachment of the image queue, or in an active refresh. */
	const CLASS_PROTECTED_LIVE = 'protected_live';
	/* Used outside Mobo products (merchant product, term, option, post content, ...). */
	const CLASS_PROTECTED_FOREIGN_USE = 'protected_foreign_use';
	/* (a) Used only by Mobo products and a WebP replacement exists or can be fetched. */
	const CLASS_REPLACE_THEN_DELETE = 'replace_then_delete';
	/* Used only by Mobo products but no WebP replacement is known: keep. */
	const CLASS_IN_USE_NO_REPLACEMENT = 'in_use_no_replacement';
	/* (b) Provably Mobo-created and not referenced by any checked location. */
	const CLASS_DELETE_CANDIDATE = 'delete_candidate';

	/**
	 * All classes in report order.
	 *
	 * @return array
	 */
	public static function classes() {
		return array(
			self::CLASS_DELETE_CANDIDATE,
			self::CLASS_REPLACE_THEN_DELETE,
			self::CLASS_IN_USE_NO_REPLACEMENT,
			self::CLASS_PROTECTED_FOREIGN_USE,
			self::CLASS_PROTECTED_LIVE,
			self::CLASS_PROTECTED_SHARED_FILE,
			self::CLASS_PROTECTED_SHARED_MEDIA,
			self::CLASS_PROTECTED_UNCERTAIN,
			self::CLASS_PROTECTED_NOT_MOBO,
		);
	}

	/**
	 * Whether the class is a cleanup candidate (a) or (b).
	 *
	 * @param string $class Class.
	 * @return bool
	 */
	public static function is_candidate_class( $class ) {
		return in_array( (string) $class, array( self::CLASS_DELETE_CANDIDATE, self::CLASS_REPLACE_THEN_DELETE ), true );
	}

	/**
	 * Whether a MIME type / attached file describe a legacy raster image.
	 *
	 * @param string $mime Stored post_mime_type.
	 * @param string $file Attached file (relative or absolute).
	 * @return bool
	 */
	public static function is_legacy_raster( $mime, $file ) {
		$mime = strtolower( trim( (string) $mime ) );
		$path = (string) $file;
		$q    = strpos( $path, '?' );
		if ( false !== $q ) {
			$path = substr( $path, 0, $q );
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'webp' === $ext || 'image/webp' === $mime ) {
			return false;
		}
		return in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || in_array( $ext, array( 'jpg', 'jpeg', 'jpe', 'png' ), true );
	}

	/**
	 * Remote identity syntax check. Mirrors Mobo_Core_Remote_Identity_Policy when
	 * available; otherwise accepts a conservative opaque-id charset.
	 *
	 * @param string $value Identity.
	 * @return bool
	 */
	public static function is_valid_identity( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}
		if ( class_exists( 'Mobo_Core_Remote_Identity_Policy' ) && method_exists( 'Mobo_Core_Remote_Identity_Policy', 'is_valid' ) ) {
			return (bool) Mobo_Core_Remote_Identity_Policy::is_valid( $value );
		}
		/* Same rule as Mobo_Core_Remote_Identity_Policy::is_valid(). */
		return false === strpos( $value, '/' ) && false === strpos( $value, '\\' ) && false === strpos( $value, '://' );
	}

	/**
	 * GUID-first ownership identity.
	 *
	 * @param string $image_guid Primary identity meta.
	 * @param string $img_guid Legacy identity meta.
	 * @return array{canonical:string,conflict:bool,invalid:bool,marked:bool}
	 */
	public static function identity( $image_guid, $img_guid ) {
		$primary_raw = trim( (string) $image_guid );
		$legacy_raw  = trim( (string) $img_guid );
		$primary_ok  = '' !== $primary_raw && self::is_valid_identity( $primary_raw );
		$legacy_ok   = '' !== $legacy_raw && self::is_valid_identity( $legacy_raw );
		$invalid     = ( '' !== $primary_raw && ! $primary_ok ) || ( '' !== $legacy_raw && ! $legacy_ok );
		$primary     = $primary_ok ? strtolower( $primary_raw ) : '';
		$legacy      = $legacy_ok ? strtolower( $legacy_raw ) : '';
		$conflict    = '' !== $primary && '' !== $legacy && $primary !== $legacy;

		return array(
			'canonical' => ( ! $invalid && ! $conflict ) ? ( '' !== $primary ? $primary : $legacy ) : '',
			'conflict'  => $conflict,
			'invalid'   => $invalid,
			'marked'    => '' !== $primary_raw || '' !== $legacy_raw,
		);
	}

	/**
	 * Whether a relative upload path is safe (no traversal, not absolute, no NUL).
	 *
	 * @param string $relative Relative path from _wp_attached_file.
	 * @return bool
	 */
	public static function is_safe_relative_path( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		if ( '' === trim( $relative ) || false !== strpos( $relative, "\0" ) ) {
			return false;
		}
		if ( '/' === substr( $relative, 0, 1 ) || 1 === preg_match( '#^[A-Za-z]:/#', $relative ) || false !== strpos( $relative, '://' ) ) {
			return false;
		}
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Classify one attachment from gathered facts.
	 *
	 * Facts (all optional, missing = unknown = conservative):
	 * - mime, file (relative _wp_attached_file)
	 * - image_guid, img_guid
	 * - shared_media (bool)
	 * - shares_physical_file (bool)
	 * - parent_non_mobo (bool) post_parent is an existing non-Mobo post (copied attachment)
	 * - live_in_image_queue (bool) attachment is a current image-queue attachment
	 * - active_refresh (bool) attachment is in a pending/processing refresh row
	 * - guid_pending_raster (bool) a not-done image-queue row for this GUID has a
	 *   non-WebP source and might reuse this JPG/PNG
	 * - refs_complete (bool) every reference check of the scanner ran
	 * - refs (array of array{kind:string, mobo:bool|null})
	 *   kind: featured|gallery|variation|term|option|content|other
	 * - replaced_by_valid (bool) a recorded, valid WebP replacement exists
	 * - webp_available (bool) a current WebP attachment/source exists for the GUID
	 *
	 * @param array $facts Facts.
	 * @return array{class:string,reason:string,guid:string}
	 */
	public static function classify( array $facts ) {
		$get = static function ( $key, $default = null ) use ( $facts ) {
			return array_key_exists( $key, $facts ) ? $facts[ $key ] : $default;
		};

		if ( ! self::is_legacy_raster( (string) $get( 'mime', '' ), (string) $get( 'file', '' ) ) ) {
			return self::result( self::CLASS_NOT_LEGACY, 'not-jpg-png', '' );
		}

		$identity = self::identity( (string) $get( 'image_guid', '' ), (string) $get( 'img_guid', '' ) );
		if ( ! $identity['marked'] ) {
			return self::result( self::CLASS_PROTECTED_NOT_MOBO, 'no-plugin-ownership-marker', '' );
		}
		if ( $identity['conflict'] || $identity['invalid'] || '' === $identity['canonical'] ) {
			return self::result( self::CLASS_PROTECTED_UNCERTAIN, $identity['conflict'] ? 'guid-conflict' : 'guid-invalid', '' );
		}
		$guid = $identity['canonical'];

		if ( true === $get( 'shared_media', false ) ) {
			return self::result( self::CLASS_PROTECTED_SHARED_MEDIA, 'shared-media', $guid );
		}

		$file = (string) $get( 'file', '' );
		if ( '' !== $file && ! self::is_safe_relative_path( $file ) ) {
			return self::result( self::CLASS_PROTECTED_UNCERTAIN, 'unsafe-path', $guid );
		}

		if ( true === $get( 'shares_physical_file', false ) ) {
			return self::result( self::CLASS_PROTECTED_SHARED_FILE, 'file-shared-with-another-attachment', $guid );
		}

		if ( true === $get( 'parent_non_mobo', false ) ) {
			return self::result( self::CLASS_PROTECTED_FOREIGN_USE, 'attached-to-non-mobo-post', $guid );
		}

		if ( true === $get( 'live_in_image_queue', false ) || true === $get( 'active_refresh', false ) ) {
			return self::result( self::CLASS_PROTECTED_LIVE, true === $get( 'active_refresh', false ) ? 'active-refresh' : 'current-image-queue-attachment', $guid );
		}

		if ( true === $get( 'guid_pending_raster', false ) ) {
			return self::result( self::CLASS_PROTECTED_UNCERTAIN, 'guid-pending-with-raster-source', $guid );
		}

		if ( true !== $get( 'refs_complete', false ) ) {
			return self::result( self::CLASS_PROTECTED_UNCERTAIN, 'reference-check-incomplete', $guid );
		}

		$refs = $get( 'refs', array() );
		$refs = is_array( $refs ) ? $refs : array();
		if ( ! empty( $refs ) ) {
			foreach ( $refs as $ref ) {
				$kind = is_array( $ref ) && isset( $ref['kind'] ) ? (string) $ref['kind'] : 'other';
				$mobo = is_array( $ref ) && array_key_exists( 'mobo', $ref ) ? $ref['mobo'] : null;
				if ( ! in_array( $kind, array( 'featured', 'gallery', 'variation' ), true ) || true !== $mobo ) {
					return self::result( self::CLASS_PROTECTED_FOREIGN_USE, 'referenced-by-' . preg_replace( '/[^a-z_]/', '', $kind ) . ( true === $mobo ? '' : '-non-mobo' ), $guid );
				}
			}
			if ( true === $get( 'replaced_by_valid', false ) || true === $get( 'webp_available', false ) ) {
				return self::result( self::CLASS_REPLACE_THEN_DELETE, true === $get( 'replaced_by_valid', false ) ? 'replacement-recorded-still-referenced' : 'webp-replacement-available', $guid );
			}
			return self::result( self::CLASS_IN_USE_NO_REPLACEMENT, 'no-webp-replacement-known', $guid );
		}

		return self::result( self::CLASS_DELETE_CANDIDATE, true === $get( 'replaced_by_valid', false ) ? 'replaced-and-unreferenced' : 'unreferenced', $guid );
	}

	/**
	 * Every physical file WordPress registered for one attachment, relative to
	 * the uploads base directory: main file, original_image and all sizes.
	 *
	 * @param string $relative_file _wp_attached_file.
	 * @param mixed  $metadata Unserialized _wp_attachment_metadata.
	 * @return array<string,int> relative path => byte size recorded in metadata (0 = unknown)
	 */
	public static function family_files( $relative_file, $metadata ) {
		$relative_file = ltrim( str_replace( '\\', '/', (string) $relative_file ), '/' );
		$metadata      = is_array( $metadata ) ? $metadata : array();
		$files         = array();
		if ( '' === $relative_file || ! self::is_safe_relative_path( $relative_file ) ) {
			return $files;
		}
		$dir    = dirname( $relative_file );
		$prefix = ( '.' === $dir || '' === $dir ) ? '' : $dir . '/';

		$files[ $relative_file ] = isset( $metadata['filesize'] ) ? absint( $metadata['filesize'] ) : 0;

		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$original = $prefix . basename( str_replace( '\\', '/', $metadata['original_image'] ) );
			if ( self::is_safe_relative_path( $original ) && ! isset( $files[ $original ] ) ) {
				$files[ $original ] = 0;
			}
		}

		$sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}
			$path = $prefix . basename( str_replace( '\\', '/', $size['file'] ) );
			if ( ! self::is_safe_relative_path( $path ) ) {
				continue;
			}
			if ( ! isset( $files[ $path ] ) || 0 === $files[ $path ] ) {
				$files[ $path ] = isset( $size['filesize'] ) ? absint( $size['filesize'] ) : 0;
			}
		}

		return $files;
	}

	/**
	 * Estimate disk usage of one attachment family.
	 *
	 * Bytes come from the real file when it exists (size probe), so the figure
	 * is what deletion would actually free. `metaBytes` is the figure recorded in
	 * WordPress metadata (useful on copies without upload files).
	 *
	 * @param string        $relative_file _wp_attached_file.
	 * @param mixed         $metadata Unserialized metadata.
	 * @param callable|null $size_probe function( string $relative ): int|false (false = missing).
	 * @return array{files:int,existing:int,missing:int,bytes:int,metaBytes:int,paths:array}
	 */
	public static function estimate_family( $relative_file, $metadata, $size_probe = null ) {
		$files  = self::family_files( $relative_file, $metadata );
		$result = array( 'files' => count( $files ), 'existing' => 0, 'missing' => 0, 'bytes' => 0, 'metaBytes' => 0, 'paths' => array_keys( $files ) );
		foreach ( $files as $path => $meta_bytes ) {
			$result['metaBytes'] += absint( $meta_bytes );
			if ( ! is_callable( $size_probe ) ) {
				continue;
			}
			$size = call_user_func( $size_probe, $path );
			if ( false === $size || null === $size ) {
				$result['missing']++;
				continue;
			}
			$result['existing']++;
			$result['bytes'] += absint( $size );
		}
		return $result;
	}

	/**
	 * Parse a WooCommerce gallery CSV into attachment IDs.
	 *
	 * @param string $csv Gallery value.
	 * @return int[]
	 */
	public static function parse_gallery( $csv ) {
		$ids = array();
		foreach ( explode( ',', (string) $csv ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part && ctype_digit( $part ) ) {
				$ids[] = (int) $part;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Every image reference a text (post_content/excerpt) can hold.
	 *
	 * Collected: basenames of any *.jpg/*.jpeg/*.jpe/*.png token (raw, URL-
	 * decoded and JSON-unescaped forms, lower-cased), `wp-image-{id}` classes,
	 * `[gallery ids=".."]`/`include` lists and block attributes such as
	 * `"id":123`. The set is deliberately broad: a false hit only protects an
	 * attachment, a miss could delete a used one.
	 *
	 * @param string $text Text.
	 * @return array{basenames:string[],ids:int[]}
	 */
	public static function extract_content_references( $text ) {
		$text = (string) $text;
		$out  = array( 'basenames' => array(), 'ids' => array() );
		if ( '' === $text ) {
			return $out;
		}
		$basenames = array();
		$ids       = array();
		$variants  = array( $text, str_replace( '\\/', '/', $text ), rawurldecode( $text ) );
		foreach ( array_unique( $variants ) as $variant ) {
			if ( preg_match_all( '#([^\s"\'<>()\[\]{},=/\\\\|]+\.(?:jpe?g|jpe|png))(?![a-z0-9])#i', $variant, $m ) ) {
				foreach ( $m[1] as $basename ) {
					$basenames[ strtolower( $basename ) ] = true;
				}
			}
		}
		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		if ( preg_match_all( '/(?:ids|include)\s*=\s*\\\\?["\']([^"\'\\\\]*)/i', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( self::parse_gallery( $list ) as $id ) {
					$ids[ $id ] = true;
				}
			}
		}
		if ( preg_match_all( '/"(?:id|ids|mediaId|attachmentId|attachment_id|imageId|image_id)"\s*:\s*\[?\s*"?(\d+(?:\s*,\s*\d+)*)/', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( self::parse_gallery( $list ) as $id ) {
					$ids[ $id ] = true;
				}
			}
		}
		$out['basenames'] = array_keys( $basenames );
		$out['ids']       = array_keys( $ids );
		return $out;
	}

	/**
	 * Build a compact result.
	 *
	 * @param string $class Class.
	 * @param string $reason Reason code.
	 * @param string $guid Canonical GUID.
	 * @return array
	 */
	private static function result( $class, $reason, $guid ) {
		return array( 'class' => $class, 'reason' => $reason, 'guid' => (string) $guid );
	}
}
