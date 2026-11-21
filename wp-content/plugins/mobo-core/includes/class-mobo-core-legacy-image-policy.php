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
 * Ownership needs STRONG proof (customers run Elementor & co. and own many
 * images unrelated to the plugin):
 *   1. a valid ownership marker written by our code (`image_guid`/`img_guid`,
 *      agreeing when both exist), AND
 *   2. either a linkage to a Mobo-owned product (post_parent is a Mobo product,
 *      Mobo product featured/gallery reference, the GUID is in our image queue,
 *      or our refresh code recorded a replacement), or a file name our download
 *      code produced (see filename_pattern()).
 * A marker alone (e.g. `img_guid` copied by a "duplicate" plugin) is only weak
 * proof: such attachments are reported as "unsure" and protected. Attachments
 * without any marker (merchant uploads and the earliest unmarked plugin
 * version) are never candidates. `mobo_source_url` alone, a file name alone, an
 * upload date or a matching product never prove ownership.
 *
 * Reference rules (what counts as USED) are implemented as pure extractors
 * here and applied by the scanner to post content, all postmeta, termmeta,
 * usermeta, options, slider/plugin tables and Elementor CSS files.
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
	/* Used by a merchant product (featured/gallery/variation) or attached to a non-Mobo post. */
	const CLASS_PROTECTED_FOREIGN_USE = 'protected_foreign_use';
	/* Referenced from content, page builders, ACF, options, terms, sliders, CSS, translations, ... */
	const CLASS_PROTECTED_REFERENCED = 'protected_referenced';
	/* Ownership marker present but no linkage/filename proof: unsure, protected. */
	const CLASS_PROTECTED_WEAK_OWNERSHIP = 'protected_weak_ownership';
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
			self::CLASS_PROTECTED_REFERENCED,
			self::CLASS_PROTECTED_FOREIGN_USE,
			self::CLASS_PROTECTED_LIVE,
			self::CLASS_PROTECTED_SHARED_FILE,
			self::CLASS_PROTECTED_SHARED_MEDIA,
			self::CLASS_PROTECTED_WEAK_OWNERSHIP,
			self::CLASS_PROTECTED_UNCERTAIN,
			self::CLASS_PROTECTED_NOT_MOBO,
		);
	}

	/**
	 * Reference sources (report buckets) and their bit in a hit mask.
	 *
	 * @return array<string,int>
	 */
	public static function reference_sources() {
		return array(
			'content'     => 1,
			'revision'    => 2,
			'elementor'   => 4,
			'acf'         => 8,
			'postmeta'    => 16,
			'termmeta'    => 32,
			'options'     => 64,
			'usermeta'    => 128,
			'css'         => 256,
			'slider'      => 512,
			'translation' => 1024,
			'sharedfile'  => 2048,
			'plugin'      => 4096,
		);
	}

	/**
	 * Bit of one reference source (0 = unknown).
	 *
	 * @param string $source Source name.
	 * @return int
	 */
	public static function source_bit( $source ) {
		$all = self::reference_sources();
		return isset( $all[ (string) $source ] ) ? (int) $all[ (string) $source ] : 0;
	}

	/**
	 * Source names contained in a hit mask, in report order.
	 *
	 * @param int $mask Hit mask.
	 * @return string[]
	 */
	public static function sources_from_mask( $mask ) {
		$mask = (int) $mask;
		$out  = array();
		foreach ( self::reference_sources() as $name => $bit ) {
			if ( $mask & $bit ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Final class of a candidate that waited for the site-wide reference index.
	 *
	 * A hit always protects. Without a hit, an incomplete index (oversized row,
	 * unreadable file, capacity) makes the candidate "unsure", never "unused".
	 *
	 * @param string $class Class from the attachment pass (a candidate class).
	 * @param int    $mask Reference hit mask.
	 * @param bool   $index_incomplete Whether any reference source was skipped.
	 * @return array{class:string,reason:string,sources:string[]}
	 */
	public static function finalize_candidate( $class, $mask, $index_incomplete ) {
		$sources = self::sources_from_mask( (int) $mask );
		if ( ! empty( $sources ) ) {
			return array( 'class' => self::CLASS_PROTECTED_REFERENCED, 'reason' => 'referenced-by-' . $sources[0], 'sources' => $sources );
		}
		if ( $index_incomplete ) {
			return array( 'class' => self::CLASS_PROTECTED_UNCERTAIN, 'reason' => 'reference-index-incomplete', 'sources' => array() );
		}
		return array( 'class' => (string) $class, 'reason' => '', 'sources' => array() );
	}

	/**
	 * Whether a file name is one our historical download code produced.
	 *
	 * Old versions saved `basename(source URL)` into uploads and inserted the
	 * attachment with an empty caption/description and no parent:
	 * - db8c5c9..b1bce7d: post_title = sanitize_file_name(basename) WITH extension
	 *   (WordPress' own uploader never keeps the extension in the title);
	 * - Mobo Source file names are short hex tokens (e.g. `5897fb.jpeg`), kept
	 *   by every version, plus WordPress' `-N`/`-scaled`/`-rotated` suffixes;
	 * - newer image sync records `mobo_source_url`; the stored file is that
	 *   URL's basename (plus `-N`/`-scaled`).
	 * A non-empty caption/description means a person edited the attachment, so
	 * it is never treated as produced by our code.
	 *
	 * @param string $title Attachment post_title.
	 * @param int    $text_length Length of post_content + post_excerpt.
	 * @param string $attached_file _wp_attached_file.
	 * @param mixed  $metadata Unserialized _wp_attachment_metadata.
	 * @param string $source_url mobo_source_url meta (optional).
	 * @return string Pattern name, '' when none matched.
	 */
	public static function filename_pattern( $title, $text_length, $attached_file, $metadata, $source_url = '' ) {
		if ( absint( $text_length ) > 0 ) {
			return '';
		}
		$file_base = self::base_name( str_replace( '\\', '/', (string) $attached_file ) );
		if ( '' === $file_base || ! self::is_legacy_raster( '', $file_base ) ) {
			return '';
		}
		$metadata = is_array( $metadata ) ? $metadata : array();
		$original = ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ? self::base_name( $metadata['original_image'] ) : preg_replace( '/-(?:scaled|rotated)(\.[a-z0-9]+)$/i', '$1', $file_base );
		$title    = trim( (string) $title );

		if ( '' !== $title && 1 === preg_match( '/\.(?:jpe?g|jpe|png)$/i', $title ) && ( $title === $original || self::lower( $title ) === self::lower( $original ) || self::lower( $title ) === self::lower( $file_base ) ) ) {
			return 'title-equals-filename';
		}
		if ( 1 === preg_match( '/^[0-9a-f]{6,32}(?:-(?:\d{1,4}|scaled|rotated))*\.(?:jpe?g|jpe|png)$/i', $file_base ) ) {
			return 'source-hex-filename';
		}
		$source_url = trim( (string) $source_url );
		if ( '' !== $source_url ) {
			$source_path = (string) parse_url( $source_url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure parsing.
			$source_base = self::lower( rawurldecode( self::base_name( $source_path ) ) );
			$normalized  = self::lower( preg_replace( '/(?:-(?:\d{1,4}|scaled|rotated))+(\.[a-z0-9]+)$/i', '$1', $file_base ) );
			if ( '' !== $source_base && ( $source_base === self::lower( $file_base ) || $source_base === $normalized ) ) {
				return 'source-url-filename';
			}
		}
		return '';
	}

	/**
	 * Ownership strength from an identity, linkage facts and a filename pattern.
	 *
	 * @param array  $identity identity() result.
	 * @param array  $linkage Linkage names (parent-mobo-product, mobo-product-ref, image-queue-guid, refresh-marker).
	 * @param string $filename_pattern filename_pattern() result.
	 * @return array{strong:bool,proof:string}
	 */
	public static function ownership_strength( array $identity, array $linkage, $filename_pattern ) {
		if ( empty( $identity['marked'] ) || ! empty( $identity['conflict'] ) || ! empty( $identity['invalid'] ) || '' === (string) ( isset( $identity['canonical'] ) ? $identity['canonical'] : '' ) ) {
			return array( 'strong' => false, 'proof' => '' );
		}
		$linkage = array_values( array_filter( array_map( 'strval', $linkage ) ) );
		if ( ! empty( $linkage ) ) {
			return array( 'strong' => true, 'proof' => 'marker+' . $linkage[0] );
		}
		if ( '' !== (string) $filename_pattern ) {
			return array( 'strong' => true, 'proof' => 'marker+' . (string) $filename_pattern );
		}
		return array( 'strong' => false, 'proof' => 'marker-only' );
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
	 *   kind: featured|gallery|variation|other (product context) or a
	 *   reference_sources() name (termmeta, options, translation, ...)
	 * - linkage (string[]) parent-mobo-product|image-queue-guid|refresh-marker
	 * - filename_pattern (string) filename_pattern() result
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
		$mobo_product_ref = false;
		foreach ( $refs as $ref ) {
			$kind = is_array( $ref ) && isset( $ref['kind'] ) ? (string) $ref['kind'] : 'other';
			$mobo = is_array( $ref ) && array_key_exists( 'mobo', $ref ) ? $ref['mobo'] : null;
			if ( in_array( $kind, array( 'featured', 'gallery', 'variation' ), true ) && true === $mobo ) {
				$mobo_product_ref = true;
				continue;
			}
			$kind = preg_replace( '/[^a-z_]/', '', $kind );
			if ( 0 !== self::source_bit( $kind ) ) {
				return self::result( self::CLASS_PROTECTED_REFERENCED, 'referenced-by-' . $kind, $guid );
			}
			return self::result( self::CLASS_PROTECTED_FOREIGN_USE, 'referenced-by-' . $kind . ( true === $mobo ? '' : '-non-mobo' ), $guid );
		}

		$linkage = $get( 'linkage', array() );
		$linkage = is_array( $linkage ) ? $linkage : array();
		if ( $mobo_product_ref ) {
			$linkage[] = 'mobo-product-ref';
		}
		$ownership = self::ownership_strength( $identity, $linkage, (string) $get( 'filename_pattern', '' ) );
		if ( ! $ownership['strong'] ) {
			return self::result( self::CLASS_PROTECTED_WEAK_OWNERSHIP, 'weak-ownership-marker-only', $guid );
		}

		if ( ! empty( $refs ) ) {
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
			$original = $prefix . self::base_name( $metadata['original_image'] );
			if ( self::is_safe_relative_path( $original ) && ! isset( $files[ $original ] ) ) {
				$files[ $original ] = 0;
			}
		}

		$sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}
			$path = $prefix . self::base_name( $size['file'] );
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
		$add_ids   = static function ( $list ) use ( &$ids ) {
			foreach ( self::parse_gallery( preg_replace( '/[\s"\'\\\\]+/', '', (string) $list ) ) as $id ) {
				$ids[ $id ] = true;
			}
		};

		/* Basenames: only texts that can hold a raster file name are scanned. */
		if ( false !== stripos( $text, 'jp' ) || false !== stripos( $text, 'png' ) ) {
			$variants = array( $text );
			if ( false !== strpos( $text, '\\/' ) ) {
				$variants[] = str_replace( '\\/', '/', $text );
			}
			if ( false !== strpos( $text, '%' ) ) {
				$variants[] = rawurldecode( str_replace( '\\/', '/', $text ) );
			}
			if ( false !== stripos( $text, '\\u' ) ) {
				$variants[] = self::unescape_unicode( str_replace( '\\/', '/', $text ) );
			}
			if ( false !== strpos( $text, '&#' ) || false !== strpos( $text, '&amp;' ) ) {
				$variants[] = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			}
			foreach ( array_unique( $variants ) as $variant ) {
				if ( preg_match_all( '#([^\s"\'<>()\[\]{},=/\\\\|;:*?]+\.(?:jpe?g|jpe|png))(?![a-z0-9])#i', $variant, $m ) ) {
					foreach ( $m[1] as $basename ) {
						$basenames[ self::lower( $basename ) ] = true;
					}
				}
			}
		}

		/* Gutenberg/classic editor classes. */
		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		/* data-id / data-attachment-id / data-image-id attributes (galleries, sliders). */
		if ( preg_match_all( '/data-(?:id|attachment-id|image-id|media-id)\s*=\s*\\\\?["\']?(\d+)/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		/* Shortcode / HTML attributes: [gallery ids=".."], [vc_single_image image="12"],
		 * [vc_gallery images="1,2"], bg_image="5", id="7", include="1,2" ... */
		if ( preg_match_all( '/([A-Za-z][A-Za-z0-9_\-]*)\s*=\s*\\\\?["\']?\s*(\d+(?:\s*,\s*\d+)*)(?![A-Za-z0-9_\-.])/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				if ( self::is_media_key( $match[1] ) || in_array( strtolower( $match[1] ), array( 'include', 'images', 'img' ), true ) ) {
					$add_ids( $match[2] );
				}
			}
		}
		/* JSON (Elementor, blocks, sliders): "id":12, "ids":[1,2], "mediaId":"5", "image_id":7 ... */
		if ( preg_match_all( '/"([A-Za-z][A-Za-z0-9_\-]*)"\s*:\s*\[?\s*"?(\d+(?:\s*"?\s*,\s*"?\s*\d+)*)(?![A-Za-z0-9_\-.])/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				if ( self::is_media_key( $match[1][0] ) && self::bare_id_in_media_context( $text, $match[1][0], $match[0][1] ) ) {
					$add_ids( $match[2][0] );
				}
			}
		}
		/* Escaped JSON inside strings: \"id\":12 */
		if ( false !== strpos( $text, '\\"' ) && preg_match_all( '/\\\\"([A-Za-z][A-Za-z0-9_\-]*)\\\\"\s*:\s*\[?\s*(?:\\\\")?(\d+)(?![A-Za-z0-9_\-.])/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				if ( self::is_media_key( $match[1][0] ) && self::bare_id_in_media_context( $text, $match[1][0], $match[0][1] ) ) {
					$ids[ (int) $match[2][0] ] = true;
				}
			}
		}
		/* PHP-serialized arrays (options, ACF, theme mods, widgets, kits):
		 * s:2:"id";i:12;  s:8:"image_id";s:2:"12";  s:7:"gallery";a:2:{i:0;i:5;i:1;s:1:"6";} */
		if ( false !== strpos( $text, ':"' ) && preg_match_all( '/s:\d+:"([A-Za-z0-9_\-]+)";(?:i:(\d+);|s:\d+:"(\d+(?:,\d+)*)";|a:\d+:\{((?:i:\d+;(?:i:\d+;|s:\d+:"\d+";))+)\})/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				$offset = $match[0][1];
				foreach ( $match as $group => $capture ) {
					$match[ $group ] = is_array( $capture ) ? $capture[0] : $capture;
				}
				if ( ! self::is_media_key( $match[1] ) || ! self::bare_id_in_media_context( $text, $match[1], $offset ) ) {
					continue;
				}
				if ( isset( $match[2] ) && '' !== $match[2] ) {
					$ids[ (int) $match[2] ] = true;
				} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
					$add_ids( $match[3] );
				} elseif ( isset( $match[4] ) && preg_match_all( '/i:\d+;(?:i:(\d+);|s:\d+:"(\d+)";)/', $match[4], $mm, PREG_SET_ORDER ) ) {
					foreach ( $mm as $item ) {
						$ids[ (int) ( '' !== $item[1] ? $item[1] : $item[2] ) ] = true;
					}
				}
			}
		}
		/* Other plugins' attachment handles, e.g. "attachment:55859". */
		if ( preg_match_all( '/\battachment[:#](\d+)\b/i', $text, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		unset( $ids[0] );
		$out['basenames'] = array_keys( $basenames );
		$out['ids']       = array_map( 'intval', array_keys( $ids ) );
		return $out;
	}

	/**
	 * A bare `id` key is only an attachment reference in a media context: a
	 * Gutenberg block comment, or an object that also carries an image/uploads `url` (the
	 * Elementor/kit media control shape {"url":"..","id":12}). Other plugins'
	 * caches use `"id"` for template/remote/item numbers (e.g. Elementor's remote
	 * template library), which must not protect an unrelated attachment.
	 * Specific keys (image_id, mediaId, ids, ...) are always accepted.
	 *
	 * @param string $text Whole text.
	 * @param string $key Matched key.
	 * @param int    $offset Byte offset of the match.
	 * @return bool
	 */
	public static function bare_id_in_media_context( $text, $key, $offset ) {
		if ( 'id' !== strtolower( (string) $key ) ) {
			return true;
		}
		if ( false !== strpos( (string) $text, '<!-- wp:' ) ) {
			return true;
		}
		$start  = max( 0, (int) $offset - 300 );
		$window = substr( (string) $text, $start, 600 );
		/* The url must point at an image / uploads file, not a page or remote item. */
		return 1 === preg_match( '#(?:\\\\?"url\\\\?"\s*:\s*\\\\?"|s:3:"url";s:\d+:")[^"]*?(?:\.(?:jpe?g|jpe|png|gif|webp|avif|svg)|/uploads/)#i', $window );
	}
	/**
	 * Byte-safe basename (PHP's basename() is locale dependent for multibyte names).
	 *
	 * @param string $path Path or URL path.
	 * @return string
	 */
	public static function base_name( $path ) {
		$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		$pos  = strrpos( $path, '/' );
		return false === $pos ? $path : substr( $path, $pos + 1 );
	}
	/**
	 * ASCII-only lower-case. strtolower() on PHP < 8.2 follows the process
	 * locale and can rewrite UTF-8 bytes of Persian/Arabic file names.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function lower( $value ) {
		return strtr( (string) $value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}
	/**
	 * Decode JSON \uXXXX escapes (Elementor stores Persian file names that way).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function unescape_unicode( $text ) {
		return (string) preg_replace_callback(
			'/\\\\u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})|\\\\u([0-9a-fA-F]{4})/',
			static function ( $m ) {
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					$code = hexdec( $m[3] );
				} else {
					$code = 0x10000 + ( ( hexdec( $m[1] ) - 0xD800 ) << 10 ) + ( hexdec( $m[2] ) - 0xDC00 );
				}
				if ( $code < 0x80 ) {
					return chr( $code );
				}
				if ( $code < 0x800 ) {
					return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
				}
				if ( $code < 0x10000 ) {
					return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
				}
				return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
			},
			(string) $text
		);
	}

	/**
	 * Whether a meta key / option name / JSON or serialized array key / shortcode
	 * attribute names a media reference. Deliberately broad (a false hit only
	 * protects): `id`, `*_id(s)`, camelCase `*Id(s)`, and names ending in
	 * image/thumbnail/logo/gallery/banner/icon/bg/background/photo/media/
	 * attachment/avatar/cover/poster/slide/hero (singular or plural).
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_media_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return false;
		}
		if ( 1 === preg_match( '/(?:^|[_\-])ids?$/i', $key ) || 1 === preg_match( '/[a-z0-9]Ids?$/', $key ) ) {
			return true;
		}
		return 1 === preg_match( '/(?:image|img|thumbnail|thumb|logo|gallery|galleries|banner|icon|bg|background|photo|picture|poster|media|attachment|avatar|cover|slide|hero)s?$/i', $key );
	}

	/**
	 * Whether a meta key / option name belongs to an ACF image/gallery/file
	 * field: exact field name, a repeater/flexible sub-field (`rep_0_name`) or an
	 * options-page value (`options_name`).
	 *
	 * @param string $key Meta key.
	 * @param array  $acf_names Field name => true (image/gallery/file fields).
	 * @return bool
	 */
	public static function is_acf_media_key( $key, array $acf_names ) {
		$key = (string) $key;
		if ( '' === $key || empty( $acf_names ) ) {
			return false;
		}
		if ( isset( $acf_names[ $key ] ) ) {
			return true;
		}
		$pos = strrpos( $key, '_' );
		while ( false !== $pos && $pos > 0 ) {
			if ( isset( $acf_names[ substr( $key, $pos + 1 ) ] ) ) {
				return true;
			}
			$pos = strrpos( substr( $key, 0, $pos ), '_' );
		}
		return false;
	}

	/**
	 * Attachment IDs a key/value pair holds as a plain value: a numeric value,
	 * a CSV list, a serialized or JSON array of IDs. Only applied to media keys
	 * (is_media_key()) or ACF media fields; an unrelated numeric value (stock,
	 * price, counter) under another key is never read as an attachment ID.
	 *
	 * @param string $key Key.
	 * @param string $value Raw value.
	 * @param bool   $force Treat as media key (ACF field).
	 * @return int[]
	 */
	public static function extract_value_ids( $key, $value, $force = false ) {
		if ( ! $force && ! self::is_media_key( $key ) ) {
			return array();
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		if ( 1 === preg_match( '/^\d+(?:\s*,\s*\d+)*$/', $value ) ) {
			return self::parse_gallery( $value );
		}
		$ids = array();
		if ( 1 === preg_match( '/^a:\d+:\{/', $value ) && strlen( $value ) <= 1048576 ) {
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disabled; read-only parse.
			self::collect_numeric_leaves( $data, $ids, 0 );
		} elseif ( '[' === $value[0] && strlen( $value ) <= 1048576 ) {
			self::collect_numeric_leaves( json_decode( $value, true ), $ids, 0 );
		}
		unset( $ids[0] );
		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * Collect integer-like leaves of a decoded array (bounded depth/size).
	 *
	 * @param mixed $data Data.
	 * @param array $ids Accumulator id => true.
	 * @param int   $depth Depth.
	 * @return void
	 */
	private static function collect_numeric_leaves( $data, array &$ids, $depth ) {
		if ( $depth > 8 || count( $ids ) > 10000 ) {
			return;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				self::collect_numeric_leaves( $item, $ids, $depth + 1 );
			}
			return;
		}
		if ( is_int( $data ) || ( is_string( $data ) && 1 === preg_match( '/^\d{1,19}$/', $data ) ) ) {
			$ids[ (int) $data ] = true;
		}
	}

	/**
	 * Root of an image basename: WordPress size (`-300x300`), `-scaled`,
	 * `-rotated`, edit (`-e1700000000000`) and Elementor thumb hash suffixes are
	 * stripped, so a reference to any generated variant protects the original.
	 * The `-N` uniqueness suffix is kept (it names a different upload).
	 *
	 * @param string $basename Lower-cased basename.
	 * @return string
	 */
	public static function basename_root( $basename ) {
		$basename = self::lower( (string) $basename );
		$dot      = strrpos( $basename, '.' );
		if ( false === $dot ) {
			return $basename;
		}
		$stem = substr( $basename, 0, $dot );
		$ext  = substr( $basename, $dot );
		for ( $i = 0; $i < 4; $i++ ) {
			$next = preg_replace( '/-(?:\d{1,5}x\d{1,5}|scaled|rotated|e\d{10,}|[a-z0-9]{24,})$/', '', $stem );
			if ( $next === $stem || '' === $next ) {
				break;
			}
			$stem = $next;
		}
		return $stem . $ext;
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
