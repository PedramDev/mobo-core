<?php
/**
 * One end-to-end wall-clock deadline for the current PHP request.
 *
 * Cheap shared hosts kill a request at max_execution_time (often 30 s, counted
 * from the start of the request, not from when Mobo code starts). Every budgeted
 * stage (migration slice, runner, health snapshot, dispatcher lease) therefore
 * derives its limit from the same request deadline instead of measuring from its
 * own start: deadline = REQUEST_TIME_FLOAT + min(25 s, 80% of the PHP limit).
 * An unknown/unlimited limit is treated as 30 s.
 *
 * Pure helpers are public for the standalone tests in tests-dev/.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Request_Deadline {

	/** PHP limit assumed when max_execution_time is 0/unknown (CLI, unusual SAPIs). */
	const ASSUMED_LIMIT_SECONDS = 30;

	/** Absolute ceiling of the work budget of one request. */
	const MAX_BUDGET_SECONDS = 25;

	/** Share of the PHP limit that may be spent (the rest is bootstrap/shutdown headroom). */
	const BUDGET_RATIO = 0.8;

	/** Dispatcher lease = PHP limit + this margin, bounded below/above. */
	const LEASE_MARGIN_SECONDS = 15;
	const LEASE_MIN_SECONDS    = 45;
	const LEASE_MAX_SECONDS    = 90;

	/** A REQUEST_TIME_FLOAT older than this is not trusted (long-lived CLI loops). */
	const MAX_REQUEST_AGE_SECONDS = 3600;

	/** @var array|null Memoized compute() result for this request. */
	private static $memo = null;

	/** @var float|null Fallback start when REQUEST_TIME_FLOAT is unusable. */
	private static $fallback_start = null;

	/**
	 * Effective PHP time limit in seconds (unknown/unlimited => 30).
	 *
	 * @param mixed $raw Raw ini value; null reads max_execution_time.
	 * @return int
	 */
	public static function php_time_limit( $raw = null ) {
		$raw   = null === $raw ? Mobo_Core_Environment::ini( 'max_execution_time', '0' ) : $raw;
		$limit = is_numeric( $raw ) ? (int) $raw : 0;
		return $limit > 0 ? $limit : self::ASSUMED_LIMIT_SECONDS;
	}

	/**
	 * Work budget for a request with the given PHP limit: min(25, 80% of limit).
	 *
	 * @param int $limit PHP limit in seconds (>0).
	 * @return float
	 */
	public static function budget_seconds( $limit ) {
		$limit = max( 1, absint( $limit ) );
		return (float) max( 1.0, min( (float) self::MAX_BUDGET_SECONDS, floor( $limit * self::BUDGET_RATIO * 10 ) / 10 ) );
	}

	/**
	 * Validate $_SERVER['REQUEST_TIME_FLOAT'].
	 *
	 * @param mixed $value Raw server value.
	 * @param float $now   Current microtime.
	 * @return float|null
	 */
	public static function resolve_request_start( $value, $now ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$start = (float) $value;
		$now   = (float) $now;
		if ( $start <= 0 || $start > $now + 1.0 || $start < $now - self::MAX_REQUEST_AGE_SECONDS ) {
			return null;
		}
		return $start;
	}

	/**
	 * Pure deadline computation.
	 *
	 * @param float|null $request_start Validated request start (null => $now).
	 * @param mixed      $raw_limit     Raw max_execution_time value.
	 * @param float      $now           Current microtime.
	 * @return array{start:float,limit:int,budget:float,deadline:float}
	 */
	public static function compute( $request_start, $raw_limit, $now ) {
		$start  = null === $request_start ? (float) $now : (float) $request_start;
		$limit  = self::php_time_limit( $raw_limit );
		$budget = self::budget_seconds( $limit );
		return array(
			'start'    => $start,
			'limit'    => $limit,
			'budget'   => $budget,
			'deadline' => $start + $budget,
		);
	}

	/** @return array Memoized request deadline data. */
	public static function current() {
		if ( null === self::$memo ) {
			$now = microtime( true );
			if ( null === self::$fallback_start ) {
				self::$fallback_start = $now;
			}
			$raw   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? $_SERVER['REQUEST_TIME_FLOAT'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numeric server timestamp, validated below.
			$start = self::resolve_request_start( $raw, $now );
			self::$memo = self::compute( null === $start ? self::$fallback_start : $start, Mobo_Core_Environment::ini( 'max_execution_time', '0' ), $now );
		}
		return self::$memo;
	}

	/** @return float Absolute microtime deadline of this request. */
	public static function deadline() {
		$current = self::current();
		return (float) $current['deadline'];
	}

	/**
	 * Seconds left before the request deadline (may be negative).
	 *
	 * @param float|null $now Optional microtime.
	 * @return float
	 */
	public static function remaining( $now = null ) {
		$now = null === $now ? microtime( true ) : (float) $now;
		return self::deadline() - $now;
	}

	/**
	 * Whether at least $reserve seconds remain.
	 *
	 * @param float $reserve Seconds.
	 * @return bool
	 */
	public static function has_time( $reserve = 0.0 ) {
		return self::remaining() > (float) $reserve;
	}

	/**
	 * Clamp a stage budget so it ends no later than the request deadline minus $reserve.
	 *
	 * @param float $seconds Desired budget.
	 * @param float $reserve Seconds to keep for the caller after the stage.
	 * @param float|null $now Optional microtime.
	 * @return float Possibly 0 when no time is left.
	 */
	public static function clamp_seconds( $seconds, $reserve = 0.0, $now = null ) {
		return (float) max( 0.0, min( (float) $seconds, self::remaining( $now ) - (float) $reserve ) );
	}

	/**
	 * Dispatcher lease TTL derived from the PHP limit: limit + 15 s, within [45, 90].
	 * A request killed by the host can therefore never lock the site out for more
	 * than ~1.5 minutes even when the shutdown release could not run.
	 *
	 * @param mixed $raw_limit Raw max_execution_time; null reads ini.
	 * @return int
	 */
	public static function lease_ttl( $raw_limit = null ) {
		$limit = self::php_time_limit( $raw_limit );
		return (int) max( self::LEASE_MIN_SECONDS, min( self::LEASE_MAX_SECONDS, $limit + self::LEASE_MARGIN_SECONDS ) );
	}

	/**
	 * Real-cron runner lock TTL: like the dispatcher lease (limit + 15 s within
	 * [45, 90]), but never shorter than the slice budget + 15 s so a healthy slice
	 * between two heartbeats can not lose its lock.
	 *
	 * @param int   $budget_seconds Effective runner budget.
	 * @param mixed $raw_limit      Raw max_execution_time; null reads ini.
	 * @return int
	 */
	public static function runner_lock_ttl( $budget_seconds = 0, $raw_limit = null ) {
		return (int) min( self::LEASE_MAX_SECONDS, max( self::lease_ttl( $raw_limit ), absint( $budget_seconds ) + self::LEASE_MARGIN_SECONDS ) );
	}

	/**
	 * Whether memory usage passed a share of the PHP memory limit (default 75 %).
	 * Disabled-function safe: unknown usage or limit never stops work.
	 *
	 * @param float    $ratio Share of the limit.
	 * @param int|null $usage Optional usage in bytes (tests).
	 * @param mixed    $limit Optional raw memory_limit (tests).
	 * @return bool
	 */
	public static function memory_pressure( $ratio = 0.75, $usage = null, $limit = null ) {
		if ( null === $usage ) {
			$usage = class_exists( 'Mobo_Core_Environment', false ) ? Mobo_Core_Environment::memory_usage( true ) : ( function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0 );
		}
		if ( null === $limit ) {
			$limit = class_exists( 'Mobo_Core_Environment', false ) ? Mobo_Core_Environment::ini( 'memory_limit', '' ) : ini_get( 'memory_limit' );
		}
		$bytes = self::parse_bytes( $limit );
		if ( $bytes <= 0 || absint( $usage ) <= 0 ) {
			return false;
		}
		return absint( $usage ) >= (int) floor( $bytes * max( 0.1, min( 0.99, (float) $ratio ) ) );
	}

	/**
	 * Free request-local caches (memory pressure only): Mobo's row cache, the
	 * WordPress runtime object cache (only when no persistent object cache is in
	 * use, so a shared Redis/Memcached is never wiped) and PHP cycles.
	 *
	 * @return void
	 */
	public static function release_request_memory() {
		if ( class_exists( 'Mobo_Core_Storage_Row_Cache', false ) ) {
			Mobo_Core_Storage_Row_Cache::flush_all();
		}
		$external = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		if ( ! $external && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}

	/**
	 * Memory guard for long per-item loops: at >= $soft of memory_limit release the
	 * request caches once; report true (stop now) only if still >= $hard afterwards.
	 *
	 * @param float $soft Share that triggers a cache release.
	 * @param float $hard Share that stops the loop.
	 * @return bool True when the caller must stop.
	 */
	public static function memory_guard( $soft = 0.6, $hard = 0.75 ) {
		if ( ! self::memory_pressure( $soft ) ) {
			return false;
		}
		self::release_request_memory();
		return self::memory_pressure( $hard );
	}

	/**
	 * php.ini shorthand bytes ("256M") to bytes; -1/empty/unparseable => 0 (unlimited/unknown).
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function parse_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value || ! preg_match( '/^(\d+)\s*([kmg]?)b?$/i', $value, $m ) ) {
			return 0;
		}
		$n    = (int) $m[1];
		$unit = strtolower( $m[2] );
		if ( 'g' === $unit ) { return $n * 1073741824; }
		if ( 'm' === $unit ) { return $n * 1048576; }
		if ( 'k' === $unit ) { return $n * 1024; }
		return $n;
	}

	/**
	 * What an ordinary (non mobo-cron.php) request may do about an unstamped
	 * database during plugins_loaded. Storefront/admin/REST page loads never run the
	 * heavy pipeline: 'nudge' only wakes the background runner; the budgeted worker
	 * routes do nothing here because their runner slice migrates within the request
	 * deadline; WP-CLI (no time limit) keeps the full pass.
	 *
	 * @param bool $is_cli          WP-CLI request.
	 * @param bool $is_worker_route /cron/run or /worker/run REST route.
	 * @return string full-pass|none|nudge
	 */
	public static function migration_bootstrap_action( $is_cli, $is_worker_route ) {
		if ( $is_cli ) {
			return 'full-pass';
		}
		return $is_worker_route ? 'none' : 'nudge';
	}

	/**
	 * Pure route check: /mobo-core/v1/cron/run or /mobo-core/v1/worker/run, either
	 * as pretty-permalink path or as ?rest_route=.
	 *
	 * @param string $request_uri REQUEST_URI.
	 * @param string $rest_route  ?rest_route= value.
	 * @return bool
	 */
	public static function is_budgeted_worker_route( $request_uri, $rest_route = '' ) {
		$path = (string) parse_url( (string) $request_uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WP dependency.
		foreach ( array( (string) $rest_route, $path ) as $candidate ) {
			$candidate = '/' . trim( strtolower( $candidate ), '/' );
			if ( preg_match( '#/mobo-core/v1/(cron|worker)/run$#', $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Test hook: forget the memoized deadline (optionally pin it).
	 *
	 * @param array|null $pinned Optional compute() result to use.
	 * @return void
	 */
	public static function reset( $pinned = null ) {
		self::$memo           = is_array( $pinned ) ? $pinned : null;
		self::$fallback_start = null;
	}
}
