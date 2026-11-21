<?php
/**
 * Durable per-image attempt ledger for the image queue.
 *
 * The image queue row's try_count is reset by several legitimate paths (a
 * changed source, legacy failure recovery, an administrator retry). The
 * "fresh reimport" escape hatch (quarantine an incomplete attachment and
 * sideload the image again) therefore needs its own counter that survives
 * re-enqueue; otherwise a host on which WebP/subsize generation always fails
 * creates a new name-N.webp attachment on every sync, forever.
 *
 * One entry per (connection, image GUID, source path):
 *   r = fresh reimports performed, f = readiness failures, t = last activity.
 * Entries expire after TTL of inactivity and the map is bounded, so the option
 * cannot grow without limit. A changed source file (new path) gets a new entry.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Image_Reimport_Ledger {

	const OPTION                  = 'mobo_core_image_reimport_ledger';
	const MAX_FRESH_REIMPORTS     = 1;
	const MAX_READINESS_FAILURES  = 6;
	const TTL_SECONDS             = 7776000; // 90 days.
	const MAX_ENTRIES             = 5000;
	/** Marker inside a queue row's last_error for a terminal readiness failure. */
	const TERMINAL_MARKER         = 'image readiness failed repeatedly';
	/**
	 * Prefix of a transient storage/quota failure. It is never counted as a
	 * readiness failure, never triggers the fresh-reimport escape hatch and
	 * never becomes terminal: the row stays pending and is expedited as soon as
	 * the storage probe passes again.
	 */
	const STORAGE_NOT_READY_PREFIX = 'Image storage is not ready:';
	/** A terminal readiness failure is re-checked (bounded) after this long. */
	const REVIVE_AFTER_SECONDS    = 604800; // 7 days.

	/** @var array|null Request cache of the stored map. */
	private static $map = null;

	/**
	 * Ledger key for one image identity + source file.
	 *
	 * @param int    $connection_id Connection.
	 * @param string $image_guid Image GUID.
	 * @param string $url Source URL (only the path identifies the file).
	 * @return string
	 */
	public static function key( $connection_id, $image_guid, $url ) {
		$path = (string) parse_url( (string) $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure parsing.
		return absint( $connection_id ) . '|' . strtolower( trim( (string) $image_guid ) ) . '|' . substr( md5( $path ), 0, 12 );
	}

	/**
	 * Pure: may one more fresh reimport (= one more attachment) be created?
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	public static function may_fresh_reimport( $entry ) {
		$entry = is_array( $entry ) ? $entry : array();
		return absint( isset( $entry['r'] ) ? $entry['r'] : 0 ) < self::MAX_FRESH_REIMPORTS;
	}

	/**
	 * Pure: should a readiness failure now become terminal?
	 *
	 * Terminal once the bounded fresh reimport was used and the following
	 * attempt failed again, or once the identity accumulated too many readiness
	 * failures across any number of queue resets.
	 *
	 * @param array $entry Entry (after recording the current failure).
	 * @param int   $try_count Current queue attempt.
	 * @param int   $fresh_attempt Attempt at which a fresh reimport is prepared.
	 * @return bool
	 */
	public static function is_terminal( $entry, $try_count, $fresh_attempt = 3 ) {
		$entry    = is_array( $entry ) ? $entry : array();
		$reimport = absint( isset( $entry['r'] ) ? $entry['r'] : 0 );
		$failures = absint( isset( $entry['f'] ) ? $entry['f'] : 0 );
		if ( $failures >= self::MAX_READINESS_FAILURES ) {
			return true;
		}
		return $reimport >= self::MAX_FRESH_REIMPORTS && absint( $try_count ) > absint( $fresh_attempt );
	}

	/**
	 * Pure: does a failure message describe a temporary storage/quota/disk-full
	 * condition rather than a defect of the image itself?
	 *
	 * @param string $message Failure message (queue last_error or service message).
	 * @return bool
	 */
	public static function is_storage_transient_message( $message ) {
		$message = (string) $message;
		if ( '' === $message ) {
			return false;
		}
		if ( false !== strpos( $message, self::STORAGE_NOT_READY_PREFIX ) ) {
			return true;
		}
		$needles = array(
			'فضای ذخیره‌سازی', // Subsize storage gate (refresh service).
			'سهمیه فضا',
			'فضای آزاد uploads',
			'no space left',
			'disk full',
			'disk quota',
			'quota exceeded',
			'errno: 28',
			'errno 28',
			'is full',
		);
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $message, 'UTF-8' ) : strtolower( $message );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pure: what to do with a terminal ("Permanent") readiness failure row.
	 *
	 * - 'forget': the terminal decision was caused by a storage/quota failure
	 *   (rows written by releases that counted disk-full as permanent). Once the
	 *   storage probe passes the identity gets a completely fresh ledger entry.
	 * - 'reset_failures': the row has been terminal for REVIVE_AFTER_SECONDS.
	 *   The failure counter is cleared so the image is re-checked, but the
	 *   fresh-reimport counter is kept: no additional attachment can be created,
	 *   so a genuinely broken image becomes terminal again after a few tries.
	 * - 'keep': nothing to do yet.
	 *
	 * @param string $last_error Queue row last_error.
	 * @param int    $updated_at Unix time of the last row update.
	 * @param int    $now Unix time.
	 * @param bool   $storage_ready Whether the storage probe currently passes.
	 * @return string forget|reset_failures|keep
	 */
	public static function revival_action( $last_error, $updated_at, $now, $storage_ready ) {
		$last_error = (string) $last_error;
		if ( ! $storage_ready || false === strpos( $last_error, self::TERMINAL_MARKER ) ) {
			return 'keep';
		}
		if ( self::is_storage_transient_message( $last_error ) ) {
			return 'forget';
		}
		$updated_at = absint( $updated_at );
		if ( $updated_at > 0 && ( absint( $now ) - $updated_at ) >= self::REVIVE_AFTER_SECONDS ) {
			return 'reset_failures';
		}
		return 'keep';
	}

	/**
	 * Pure: drop expired entries and keep the newest MAX_ENTRIES.
	 *
	 * @param array $map Map.
	 * @param int   $now Unix time.
	 * @return array
	 */
	public static function prune( array $map, $now ) {
		$now = absint( $now );
		foreach ( $map as $key => $entry ) {
			$t = is_array( $entry ) ? absint( isset( $entry['t'] ) ? $entry['t'] : 0 ) : 0;
			if ( $t <= 0 || ( $now - $t ) > self::TTL_SECONDS ) {
				unset( $map[ $key ] );
			}
		}
		if ( count( $map ) > self::MAX_ENTRIES ) {
			uasort(
				$map,
				static function ( $a, $b ) {
					return absint( isset( $b['t'] ) ? $b['t'] : 0 ) <=> absint( isset( $a['t'] ) ? $a['t'] : 0 );
				}
			);
			$map = array_slice( $map, 0, self::MAX_ENTRIES, true );
		}
		return $map;
	}

	/**
	 * Read one entry.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function get( $key ) {
		$map = self::load();
		return isset( $map[ $key ] ) && is_array( $map[ $key ] ) ? $map[ $key ] : array( 'r' => 0, 'f' => 0, 't' => 0 );
	}

	/**
	 * Record one fresh reimport.
	 *
	 * @param string $key Key.
	 * @return array Updated entry.
	 */
	public static function record_fresh_reimport( $key ) {
		return self::bump( $key, 'r' );
	}

	/**
	 * Record one readiness failure.
	 *
	 * @param string $key Key.
	 * @return array Updated entry.
	 */
	public static function record_readiness_failure( $key ) {
		return self::bump( $key, 'f' );
	}

	/**
	 * Forget an identity after a successful import.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public static function forget( $key ) {
		$map = self::load();
		if ( isset( $map[ $key ] ) ) {
			unset( $map[ $key ] );
			self::store( $map );
		}
	}

	/**
	 * Clear only the readiness-failure counter of an identity (bounded revival).
	 * The fresh-reimport counter is kept so no extra attachment can be created.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public static function reset_failures( $key ) {
		$map = self::load();
		if ( isset( $map[ $key ] ) && is_array( $map[ $key ] ) ) {
			$map[ $key ]['f'] = 0;
			$map[ $key ]['t'] = time();
			self::store( $map );
		}
	}

	/** Reset request cache (tests). @return void */
	public static function reset_cache() {
		self::$map = null;
	}

	/**
	 * Increment one counter.
	 *
	 * @param string $key Key.
	 * @param string $field r|f.
	 * @return array
	 */
	private static function bump( $key, $field ) {
		$map   = self::load();
		$entry = isset( $map[ $key ] ) && is_array( $map[ $key ] ) ? $map[ $key ] : array( 'r' => 0, 'f' => 0, 't' => 0 );
		$entry[ $field ] = absint( isset( $entry[ $field ] ) ? $entry[ $field ] : 0 ) + 1;
		$entry['t']      = time();
		$map[ $key ]     = $entry;
		self::store( self::prune( $map, time() ) );
		return $entry;
	}

	/** @return array */
	private static function load() {
		if ( null === self::$map ) {
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( self::OPTION, 'options' );
			}
			$map       = get_option( self::OPTION, array() );
			self::$map = is_array( $map ) ? $map : array();
		}
		return self::$map;
	}

	/** @param array $map Map. @return void */
	private static function store( array $map ) {
		self::$map = $map;
		update_option( self::OPTION, $map, false );
	}
}
