<?php
/**
 * Request-local row cache for Mobo Custom Storage reads.
 *
 * Product Sync reads the same Product/Variant/entity-meta rows dozens of times per
 * object (get_post_meta() on _mobo_* keys, typed identity reads, verified-write
 * read-backs). Before 10.34.78 every one of those reads issued a fresh SELECT.
 *
 * Correctness contract:
 * - Entries live only for the current PHP request (no persistent object cache),
 *   bucketed by table and keyed by Connection + object identity.
 * - Every write to a registered table invalidates that bucket. Known Mobo write
 *   paths invalidate precisely (one object) inside a scoped write; any other
 *   writer (direct $wpdb INSERT/UPDATE/DELETE/REPLACE from any file) is caught by
 *   the WordPress `query` filter and flushes the whole bucket. A read after a
 *   write therefore always hits the database or the freshly written value.
 * - ROLLBACK, schema statements and wp_cache_flush() flush everything.
 * - Acquiring a Mobo lock flushes everything, so a lock holder never reads a row
 *   cached before another process committed under that lock.
 * - Read errors are never cached.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Storage_Row_Cache {

	/** Max entries per bucket before the oldest are evicted (bounded memory on long runners). */
	const MAX_ENTRIES_PER_BUCKET = 2000;

	/** Group/key of the wp_cache sentinel used to detect wp_cache_flush(). */
	const SENTINEL_GROUP = 'mobo_core_row_cache';
	const SENTINEL_KEY   = 'epoch';

	/** @var array<string,array<string,mixed>> bucket => key => value */
	private static $data = array();

	/** @var array<string,array<int,array<string,bool>>> bucket => object id => keys */
	private static $object_index = array();

	/** @var array<string,string> bucket => physical table name (for write detection) */
	private static $tables = array();

	/** @var array<string,int> bucket => scoped write depth (precise invalidation in progress) */
	private static $scoped = array();

	/** @var bool */
	private static $hooked = false;

	/** @var string|null */
	private static $epoch = null;

	/** @var array<string,int> Telemetry counters (tests/diagnostics). */
	private static $stats = array( 'hits' => 0, 'misses' => 0, 'flushes' => 0 );

	/**
	 * Whether caching is enabled (filterable kill switch).
	 *
	 * @return bool
	 */
	public static function enabled() {
		static $enabled = null;
		if ( null === $enabled ) {
			$enabled = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'mobo_core_storage_row_cache_enabled', true ) : true;
		}
		return $enabled;
	}

	/**
	 * Register the physical table behind a bucket so foreign writes are detected.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $table Physical table name.
	 * @return void
	 */
	public static function register_table( $bucket, $table ) {
		$bucket = (string) $bucket;
		$table  = (string) $table;
		if ( '' === $bucket || '' === $table ) {
			return;
		}
		self::$tables[ $bucket ] = $table;
		self::hook();
	}

	/**
	 * Read one cached entry.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key Key.
	 * @param bool   $hit Set to true on a cache hit.
	 * @return mixed
	 */
	public static function get( $bucket, $key, &$hit = false ) {
		$hit = false;
		if ( ! self::enabled() ) {
			return null;
		}
		self::verify_epoch();
		$bucket = (string) $bucket;
		$key    = (string) $key;
		if ( isset( self::$data[ $bucket ] ) && array_key_exists( $key, self::$data[ $bucket ] ) ) {
			$hit = true;
			self::$stats['hits']++;
			return self::$data[ $bucket ][ $key ];
		}
		self::$stats['misses']++;
		return null;
	}

	/**
	 * Store one entry. Never call this with a value produced by a failed read.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key Key.
	 * @param mixed  $value Value (null allowed = known-missing row).
	 * @param int    $object_id Optional Woo object ID for precise invalidation.
	 * @return void
	 */
	public static function set( $bucket, $key, $value, $object_id = 0 ) {
		if ( ! self::enabled() ) {
			return;
		}
		self::verify_epoch();
		$bucket = (string) $bucket;
		$key    = (string) $key;
		if ( ! isset( self::$data[ $bucket ] ) ) {
			self::$data[ $bucket ] = array();
		}
		if ( count( self::$data[ $bucket ] ) >= self::MAX_ENTRIES_PER_BUCKET && ! array_key_exists( $key, self::$data[ $bucket ] ) ) {
			/* Drop the oldest quarter; the index may keep stale keys which are harmless. */
			self::$data[ $bucket ] = array_slice( self::$data[ $bucket ], (int) floor( self::MAX_ENTRIES_PER_BUCKET / 4 ), null, true );
		}
		self::$data[ $bucket ][ $key ] = $value;
		$object_id = (int) $object_id;
		if ( $object_id > 0 ) {
			self::$object_index[ $bucket ][ $object_id ][ $key ] = true;
		}
		self::hook();
	}

	/**
	 * Forget one key.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key Key.
	 * @return void
	 */
	public static function forget( $bucket, $key ) {
		unset( self::$data[ (string) $bucket ][ (string) $key ] );
	}

	/**
	 * Forget every entry tied to one Woo object, in every bucket (or one bucket).
	 *
	 * @param int         $object_id Woo object ID.
	 * @param string|null $bucket Optional bucket.
	 * @return void
	 */
	public static function forget_object( $object_id, $bucket = null ) {
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return;
		}
		$buckets = null === $bucket ? array_keys( self::$object_index ) : array( (string) $bucket );
		foreach ( $buckets as $name ) {
			if ( empty( self::$object_index[ $name ][ $object_id ] ) ) {
				continue;
			}
			foreach ( array_keys( self::$object_index[ $name ][ $object_id ] ) as $key ) {
				unset( self::$data[ $name ][ $key ] );
			}
			unset( self::$object_index[ $name ][ $object_id ] );
		}
	}

	/**
	 * Flush one bucket.
	 *
	 * @param string $bucket Bucket.
	 * @return void
	 */
	public static function flush_bucket( $bucket ) {
		$bucket = (string) $bucket;
		if ( ! empty( self::$data[ $bucket ] ) ) {
			self::$stats['flushes']++;
		}
		unset( self::$data[ $bucket ], self::$object_index[ $bucket ] );
	}

	/**
	 * Flush everything.
	 *
	 * @return void
	 */
	public static function flush_all() {
		if ( ! empty( self::$data ) ) {
			self::$stats['flushes']++;
		}
		self::$data         = array();
		self::$object_index = array();
	}

	/**
	 * Begin a known write whose caller invalidates precisely afterwards.
	 *
	 * @param string $bucket Bucket.
	 * @return void
	 */
	public static function begin_scoped_write( $bucket ) {
		$bucket = (string) $bucket;
		self::$scoped[ $bucket ] = ( isset( self::$scoped[ $bucket ] ) ? self::$scoped[ $bucket ] : 0 ) + 1;
	}

	/**
	 * End a known write.
	 *
	 * @param string $bucket Bucket.
	 * @return void
	 */
	public static function end_scoped_write( $bucket ) {
		$bucket = (string) $bucket;
		$depth  = ( isset( self::$scoped[ $bucket ] ) ? self::$scoped[ $bucket ] : 0 ) - 1;
		if ( $depth > 0 ) {
			self::$scoped[ $bucket ] = $depth;
		} else {
			unset( self::$scoped[ $bucket ] );
		}
	}

	/**
	 * WordPress `query` filter: detect writes to registered tables.
	 *
	 * Kept extremely cheap: SELECT/SHOW statements return after one character test.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public static function observe_query( $query ) {
		if ( ! is_string( $query ) || '' === $query || empty( self::$data ) ) {
			return $query;
		}
		$trimmed = ltrim( $query );
		$first   = strtoupper( substr( $trimmed, 0, 1 ) );
		if ( 'S' === $first || 'D' !== $first && 'I' !== $first && 'U' !== $first && 'R' !== $first && 'T' !== $first && 'A' !== $first && 'C' !== $first ) {
			return $query;
		}
		$verb = strtoupper( (string) strtok( $trimmed, " \t\r\n(" ) );
		if ( in_array( $verb, array( 'ROLLBACK', 'TRUNCATE', 'ALTER', 'DROP', 'CREATE', 'RENAME' ), true ) ) {
			self::flush_all();
			return $query;
		}
		if ( ! in_array( $verb, array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' ), true ) ) {
			return $query;
		}
		foreach ( self::$tables as $bucket => $table ) {
			if ( ! empty( self::$scoped[ $bucket ] ) || empty( self::$data[ $bucket ] ) ) {
				continue;
			}
			if ( false !== stripos( $query, $table ) ) {
				self::flush_bucket( $bucket );
			}
		}
		return $query;
	}

	/**
	 * Telemetry for tests/diagnostics.
	 *
	 * @return array
	 */
	public static function stats() {
		$entries = 0;
		foreach ( self::$data as $rows ) {
			$entries += count( $rows );
		}
		return array_merge( self::$stats, array( 'entries' => $entries ) );
	}

	/**
	 * Tests only.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$data         = array();
		self::$object_index = array();
		self::$scoped       = array();
		self::$epoch        = null;
		self::$stats        = array( 'hits' => 0, 'misses' => 0, 'flushes' => 0 );
	}

	/** @return void */
	private static function hook() {
		if ( self::$hooked || ! function_exists( 'add_filter' ) ) {
			return;
		}
		self::$hooked = true;
		add_filter( 'query', array( __CLASS__, 'observe_query' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Detect wp_cache_flush() (and object-cache restarts) through a sentinel entry.
	 *
	 * @return void
	 */
	private static function verify_epoch() {
		if ( ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) ) {
			return;
		}
		$current = wp_cache_get( self::SENTINEL_KEY, self::SENTINEL_GROUP );
		if ( null !== self::$epoch && $current === self::$epoch ) {
			return;
		}
		if ( null !== self::$epoch ) {
			/* Sentinel vanished or changed: the object cache was flushed. */
			self::flush_all();
		}
		if ( false === $current || ! is_string( $current ) || '' === $current ) {
			$current = uniqid( 'mobo', true );
			wp_cache_set( self::SENTINEL_KEY, $current, self::SENTINEL_GROUP );
		}
		self::$epoch = $current;
	}
}
