<?php
/**
 * Per-site, per-host request cap for image downloads from the Source image host
 * (R5, 2026-11-22).
 *
 * Right after a forced update a site works off its image backlog. Without a cap
 * one site reached ~200 image requests/min against the Source image host; ~70
 * sites doing the same can trigger rate limiting or a ban of the owner there.
 *
 * - Every outgoing image download first reserves a slot here. The cap is a GCRA
 *   (leaky bucket): `ratePerMinute` requests per minute per host with a small
 *   burst. The state is one non-autoloaded wp_options row updated by an atomic
 *   compare-and-swap, so concurrent runners (cron, admin, inline product sync,
 *   Image Refresh) share one budget per host.
 * - A 429/503 response or a Retry-After header blocks the host for
 *   max(Retry-After, escalating cooldown 60s -> 30 min). After a block the host
 *   resumes with a single probe and then the normal pace. A successful download
 *   resets the escalation.
 * - Callers defer the work item without consuming its retry budget; nothing is
 *   failed or dropped. The cap only slows the backlog.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Image_Host_Rate_Limit {

	/** Shared state option (non-autoloaded, CAS-updated). */
	const STATE_OPTION = 'mobo_core_image_host_rate_state';
	/** Setting: image requests per minute per host for this site. */
	const RATE_OPTION = 'mobo_core_image_host_rate_per_minute';
	/** Setting: requests allowed back-to-back before pacing starts. */
	const BURST_OPTION = 'mobo_core_image_host_rate_burst';

	const DEFAULT_RATE = 30;
	const MIN_RATE     = 1;
	const MAX_RATE     = 120;
	const DEFAULT_BURST = 5;
	const MAX_BURST     = 10;

	const THROTTLE_BASE_COOLDOWN = 60;
	const THROTTLE_MAX_COOLDOWN  = 1800;
	const RETRY_AFTER_MAX        = 3600;
	const MAX_HOSTS              = 50;
	const CAS_ATTEMPTS           = 8;

	/** Error prefix of a download that was not started because of the cap. */
	const DEFERRED_PREFIX = 'Image host rate limit:';

	/**
	 * Configured requests per minute for one host.
	 *
	 * @param string $host Host.
	 * @return int
	 */
	public static function rate_per_minute( $host = '' ) {
		$rate = class_exists( 'Mobo_Core_Settings' )
			? Mobo_Core_Settings::get_int( self::RATE_OPTION, self::DEFAULT_RATE, self::MIN_RATE, self::MAX_RATE )
			: self::DEFAULT_RATE;
		$rate = function_exists( 'apply_filters' ) ? apply_filters( 'mobo_core_image_host_rate_per_minute', $rate, (string) $host ) : $rate;
		return max( self::MIN_RATE, min( self::MAX_RATE, absint( $rate ) ) );
	}

	/**
	 * Configured burst for one host.
	 *
	 * @param string $host Host.
	 * @return int
	 */
	public static function burst( $host = '' ) {
		$burst = class_exists( 'Mobo_Core_Settings' )
			? Mobo_Core_Settings::get_int( self::BURST_OPTION, self::DEFAULT_BURST, 1, self::MAX_BURST )
			: self::DEFAULT_BURST;
		$burst = function_exists( 'apply_filters' ) ? apply_filters( 'mobo_core_image_host_rate_burst', $burst, (string) $host ) : $burst;
		return max( 1, min( self::MAX_BURST, absint( $burst ) ) );
	}

	/**
	 * Pure GCRA decision for one host entry.
	 *
	 * @param array $entry Host entry (tat, blockedUntil, blockCount).
	 * @param float $now Unix time (float).
	 * @param int   $rate Requests per minute.
	 * @param int   $burst Burst size.
	 * @return array{allowed:bool,retryAt:float,reason:string,entry:array}
	 */
	public static function decide( $entry, $now, $rate, $burst ) {
		$entry    = self::normalize_entry( $entry );
		$now      = (float) $now;
		$interval = 60.0 / max( 1, (int) $rate );
		$tau      = ( max( 1, (int) $burst ) - 1 ) * $interval;

		if ( $entry['blockedUntil'] > $now ) {
			return array( 'allowed' => false, 'retryAt' => (float) $entry['blockedUntil'], 'reason' => 'throttled', 'entry' => $entry );
		}

		$tat = max( $entry['tat'], $now );
		if ( $tat - $now > $tau + 0.0005 ) {
			return array( 'allowed' => false, 'retryAt' => $tat - $tau, 'reason' => 'rate', 'entry' => $entry );
		}

		$entry['tat']    = round( $tat + $interval, 3 );
		$entry['lastAt'] = (int) floor( $now );
		return array( 'allowed' => true, 'retryAt' => $now, 'reason' => 'ok', 'entry' => $entry );
	}

	/**
	 * Pure: apply a throttling response (429/503/Retry-After) to a host entry.
	 * The host is blocked for max(Retry-After, escalating cooldown) and resumes
	 * with one probe request followed by the normal pace.
	 *
	 * @param array    $entry Host entry.
	 * @param int|null $retry_after Retry-After seconds (null when absent).
	 * @param float    $now Unix time.
	 * @param int      $rate Requests per minute.
	 * @param int      $burst Burst size.
	 * @return array New entry.
	 */
	public static function apply_throttle( $entry, $retry_after, $now, $rate, $burst ) {
		$entry = self::normalize_entry( $entry );
		$now   = (float) $now;
		$entry['blockCount']++;
		$cooldown = min( self::THROTTLE_MAX_COOLDOWN, self::THROTTLE_BASE_COOLDOWN * (int) pow( 2, min( 5, $entry['blockCount'] - 1 ) ) );
		$wait     = $cooldown;
		if ( null !== $retry_after ) {
			$wait = max( $cooldown, min( self::RETRY_AFTER_MAX, absint( $retry_after ) ) );
		}
		$until                 = (int) ceil( $now + $wait );
		$interval              = 60.0 / max( 1, (int) $rate );
		$tau                   = ( max( 1, (int) $burst ) - 1 ) * $interval;
		$entry['blockedUntil'] = max( $entry['blockedUntil'], $until );
		/* One probe at the end of the block, then the normal pace (no burst). */
		$entry['tat']          = round( max( $entry['tat'], $entry['blockedUntil'] + $tau ), 3 );
		$entry['lastThrottleAt'] = (int) floor( $now );
		$entry['lastAt']       = (int) floor( $now );
		return $entry;
	}

	/**
	 * Pure: Retry-After header value -> seconds (null when absent/invalid).
	 *
	 * @param mixed $value Header value (string or array of strings).
	 * @param int   $now Unix time.
	 * @return int|null
	 */
	public static function parse_retry_after( $value, $now ) {
		if ( is_array( $value ) ) {
			$value = end( $value );
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^\d{1,9}$/', $value ) ) {
			return min( self::RETRY_AFTER_MAX, (int) $value );
		}
		$at = strtotime( $value );
		if ( false === $at ) {
			return null;
		}
		return max( 0, min( self::RETRY_AFTER_MAX, $at - (int) $now ) );
	}

	/**
	 * Pure: whether an HTTP response is a throttling signal of the image host.
	 *
	 * @param int  $code HTTP status.
	 * @param bool $has_retry_after Whether a Retry-After header was present.
	 * @return bool
	 */
	public static function is_throttle_response( $code, $has_retry_after ) {
		$code = absint( $code );
		if ( 429 === $code || 503 === $code ) {
			return true;
		}
		return $has_retry_after && ( $code < 200 || $code >= 300 );
	}

	/**
	 * Reserve one download slot for a host (atomic across runners).
	 *
	 * @param string     $host Image host.
	 * @param float|null $now Unix time override (tests).
	 * @return array{allowed:bool,retryAt:float,reason:string,blockCount:int}
	 */
	public static function reserve( $host, $now = null ) {
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return array( 'allowed' => true, 'retryAt' => 0.0, 'reason' => 'no-host', 'blockCount' => 0 );
		}
		$rate  = self::rate_per_minute( $host );
		$burst = self::burst( $host );
		$last  = array( 'allowed' => false, 'retryAt' => microtime( true ) + 1, 'reason' => 'contention', 'blockCount' => 0 );

		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
			$t        = null === $now ? microtime( true ) : (float) $now;
			$raw      = self::read_raw();
			$state    = self::decode( $raw );
			$entry    = isset( $state['hosts'][ $host ] ) ? $state['hosts'][ $host ] : array();
			$decision = self::decide( $entry, $t, $rate, $burst );
			if ( ! $decision['allowed'] ) {
				return array(
					'allowed'    => false,
					'retryAt'    => (float) $decision['retryAt'],
					'reason'     => $decision['reason'],
					'blockCount' => absint( $decision['entry']['blockCount'] ),
				);
			}
			$state['hosts'][ $host ] = $decision['entry'];
			if ( self::write_if_unchanged( $raw, $state, $t ) ) {
				return array( 'allowed' => true, 'retryAt' => $t, 'reason' => 'ok', 'blockCount' => absint( $decision['entry']['blockCount'] ) );
			}
			$last = array( 'allowed' => false, 'retryAt' => $t + 1, 'reason' => 'contention', 'blockCount' => 0 );
			usleep( mt_rand( 2000, 15000 ) );
		}

		/* Persistent contention: fail closed (defer), never exceed the cap. */
		return $last;
	}

	/**
	 * Read-only view of a host: until when no slot is available (0 = now).
	 *
	 * @param string     $host Host.
	 * @param float|null $now Unix time override (tests).
	 * @return array{available:bool,retryAt:float,reason:string}
	 */
	public static function peek( $host, $now = null ) {
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return array( 'available' => true, 'retryAt' => 0.0, 'reason' => 'no-host' );
		}
		$t        = null === $now ? microtime( true ) : (float) $now;
		$state    = self::decode( self::read_raw() );
		$entry    = isset( $state['hosts'][ $host ] ) ? $state['hosts'][ $host ] : array();
		$decision = self::decide( $entry, $t, self::rate_per_minute( $host ), self::burst( $host ) );
		return array( 'available' => (bool) $decision['allowed'], 'retryAt' => (float) $decision['retryAt'], 'reason' => (string) $decision['reason'] );
	}

	/**
	 * Record a throttling response for a host.
	 *
	 * @param string     $host Host.
	 * @param int|null   $retry_after Retry-After seconds.
	 * @param float|null $now Unix time override (tests).
	 * @return int Unix time until which the host is blocked (0 when not stored).
	 */
	public static function record_throttle( $host, $retry_after = null, $now = null ) {
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return 0;
		}
		$rate  = self::rate_per_minute( $host );
		$burst = self::burst( $host );
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
			$t     = null === $now ? microtime( true ) : (float) $now;
			$raw   = self::read_raw();
			$state = self::decode( $raw );
			$entry = isset( $state['hosts'][ $host ] ) ? $state['hosts'][ $host ] : array();
			$entry = self::apply_throttle( $entry, $retry_after, $t, $rate, $burst );
			$state['hosts'][ $host ] = $entry;
			if ( self::write_if_unchanged( $raw, $state, $t ) ) {
				if ( class_exists( 'Mobo_Core_Logger' ) && method_exists( 'Mobo_Core_Logger', 'warning' ) ) {
					Mobo_Core_Logger::warning( 'Image host ' . $host . ' throttled image downloads; paused until ' . gmdate( 'H:i:s', $entry['blockedUntil'] ) . ' UTC (block #' . $entry['blockCount'] . ').' );
				}
				return (int) $entry['blockedUntil'];
			}
			usleep( mt_rand( 2000, 15000 ) );
		}
		return 0;
	}

	/**
	 * A successful download closes the escalation of a previously throttled host.
	 *
	 * @param string $host Host.
	 * @return void
	 */
	public static function record_success( $host ) {
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return;
		}
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++ ) {
			$raw   = self::read_raw();
			$state = self::decode( $raw );
			if ( empty( $state['hosts'][ $host ]['blockCount'] ) ) {
				return;
			}
			$state['hosts'][ $host ]['blockCount'] = 0;
			if ( self::write_if_unchanged( $raw, $state, microtime( true ) ) ) {
				return;
			}
			usleep( mt_rand( 2000, 15000 ) );
		}
	}

	/**
	 * Diagnostics snapshot (no secrets).
	 *
	 * @return array
	 */
	public static function snapshot() {
		$state = self::decode( self::read_raw() );
		$now   = time();
		$hosts = array();
		foreach ( $state['hosts'] as $host => $entry ) {
			$hosts[ $host ] = array(
				'blockedUntil' => $entry['blockedUntil'] > $now ? (int) $entry['blockedUntil'] : 0,
				'blockCount'   => (int) $entry['blockCount'],
				'lastAt'       => (int) $entry['lastAt'],
			);
		}
		return array( 'ratePerMinute' => self::rate_per_minute(), 'burst' => self::burst(), 'hosts' => $hosts );
	}

	/**
	 * @param mixed $entry Raw entry.
	 * @return array
	 */
	private static function normalize_entry( $entry ) {
		$entry = is_array( $entry ) ? $entry : array();
		return array(
			'tat'            => isset( $entry['tat'] ) ? (float) $entry['tat'] : 0.0,
			'blockedUntil'   => isset( $entry['blockedUntil'] ) ? absint( $entry['blockedUntil'] ) : 0,
			'blockCount'     => isset( $entry['blockCount'] ) ? absint( $entry['blockCount'] ) : 0,
			'lastAt'         => isset( $entry['lastAt'] ) ? absint( $entry['lastAt'] ) : 0,
			'lastThrottleAt' => isset( $entry['lastThrottleAt'] ) ? absint( $entry['lastThrottleAt'] ) : 0,
		);
	}

	/**
	 * @param string $host Host.
	 * @return string
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host, " \t\n\r\0\x0B[]" ) );
		return substr( $host, 0, 190 );
	}

	/**
	 * @param string|null $raw Raw option value.
	 * @return array{v:int,hosts:array}
	 */
	private static function decode( $raw ) {
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$data = is_array( $data ) ? $data : array();
		$out  = array( 'v' => isset( $data['v'] ) ? absint( $data['v'] ) : 0, 'hosts' => array() );
		if ( isset( $data['hosts'] ) && is_array( $data['hosts'] ) ) {
			foreach ( $data['hosts'] as $host => $entry ) {
				$host = self::normalize_host( $host );
				if ( '' !== $host ) {
					$out['hosts'][ $host ] = self::normalize_entry( $entry );
				}
			}
		}
		return $out;
	}

	/** @return string|null Raw option value straight from the database. */
	private static function read_raw() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::STATE_OPTION ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Compare-and-swap the state row. The version counter changes on every
	 * write, so two runners can never both succeed from the same snapshot.
	 *
	 * @param string|null $old_raw Value read before the decision (null = no row).
	 * @param array       $state New state.
	 * @param float       $now Unix time.
	 * @return bool
	 */
	private static function write_if_unchanged( $old_raw, $state, $now ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$hosts = isset( $state['hosts'] ) && is_array( $state['hosts'] ) ? $state['hosts'] : array();
		/* Bound the row: drop idle hosts first, keep at most MAX_HOSTS. */
		foreach ( $hosts as $host => $entry ) {
			if ( $entry['blockedUntil'] < $now && $entry['tat'] < $now - 3600 && $entry['lastAt'] < $now - 86400 ) {
				unset( $hosts[ $host ] );
			}
		}
		if ( count( $hosts ) > self::MAX_HOSTS ) {
			uasort(
				$hosts,
				static function ( $a, $b ) {
					return $a['lastAt'] - $b['lastAt'];
				}
			);
			$hosts = array_slice( $hosts, -self::MAX_HOSTS, null, true );
		}
		$new_raw = wp_json_encode( array( 'v' => ( isset( $state['v'] ) ? absint( $state['v'] ) : 0 ) + 1, 'hosts' => $hosts ) );
		if ( ! is_string( $new_raw ) ) {
			return false;
		}
		if ( null === $old_raw ) {
			$done = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::STATE_OPTION,
					$new_raw,
					'no'
				)
			);
		} else {
			$done = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$new_raw,
					self::STATE_OPTION,
					$old_raw
				)
			);
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::STATE_OPTION, 'options' );
		}
		return 1 === (int) $done;
	}
}
