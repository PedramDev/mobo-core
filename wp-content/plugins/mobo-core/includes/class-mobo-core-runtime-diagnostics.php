<?php
/**
 * Lightweight runtime diagnostics for Mobo Core.
 *
 * Metrics are accumulated in memory and persisted at most once per PHP request.
 * The persisted payload is deliberately compact and bounded so diagnostics never
 * become a new high-write or unbounded-history subsystem.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Runtime_Diagnostics {

	const OPTION_NAME       = 'mobo_core_runtime_diagnostics';
	const WINDOW_SECONDS    = DAY_IN_SECONDS;
	const MAX_SLOW_ITEMS    = 12;
	const MAX_STAGE_COUNT   = 24;

	/** @var array */
	private static $buffer = array();

	/** @var bool */
	private static $shutdown_registered = false;

	/** @var bool */
	private static $flushed = false;

	/** Register a shutdown flush only after the first metric is recorded. */
	private static function ensure_shutdown() {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function( array( __CLASS__, 'flush' ) );
	}

	/** Increment a compact named counter. */
	public static function increment( $key, $amount = 1 ) {
		$key    = sanitize_key( (string) $key );
		$amount = (int) $amount;
		if ( '' === $key || 0 === $amount ) {
			return;
		}
		self::ensure_shutdown();
		if ( ! isset( self::$buffer['counters'] ) || ! is_array( self::$buffer['counters'] ) ) {
			self::$buffer['counters'] = array();
		}
		self::$buffer['counters'][ $key ] = (int) ( isset( self::$buffer['counters'][ $key ] ) ? self::$buffer['counters'][ $key ] : 0 ) + $amount;
	}

	/** Record one bounded runner stage execution. */
	public static function record_stage( $stage, $elapsed_ms, $result = array() ) {
		$stage      = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $stage );
		$elapsed_ms = max( 0, (int) $elapsed_ms );
		$result     = is_array( $result ) ? $result : array();
		if ( '' === $stage ) {
			return;
		}

		self::ensure_shutdown();
		if ( ! isset( self::$buffer['stages'] ) || ! is_array( self::$buffer['stages'] ) ) {
			self::$buffer['stages'] = array();
		}
		if ( ! isset( self::$buffer['stages'][ $stage ] ) ) {
			self::$buffer['stages'][ $stage ] = array(
				'runs'      => 0,
				'totalMs'   => 0,
				'maxMs'     => 0,
				'processed' => 0,
				'updated'   => 0,
				'failed'    => 0,
				'deferred'  => 0,
				'samplesMs' => array(),
				'lastFailed'=> false,
			);
		}

		$metric =& self::$buffer['stages'][ $stage ];
		$processed = self::result_count( $result, array( 'processed', 'processedProducts', 'productSteps' ) );
		$failed    = self::result_count( $result, array( 'failed' ) );
		$deferred  = self::result_count( $result, array( 'deferred' ) );
		$status    = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '';
		$explicit_failure = array_key_exists( 'success', $result ) && false === $result['success'];
		$is_failure = $failed > 0 || $explicit_failure || in_array( $status, array( 'failed', 'exception', 'error' ), true );

		$metric['runs']      = absint( $metric['runs'] ) + 1;
		$metric['totalMs']   = absint( $metric['totalMs'] ) + $elapsed_ms;
		$metric['maxMs']     = max( absint( $metric['maxMs'] ), $elapsed_ms );
		$metric['processed'] = absint( $metric['processed'] ) + $processed;
		$metric['updated']   = absint( $metric['updated'] ) + self::result_count( $result, array( 'updated', 'finalized', 'warmed', 'success' ) );
		$metric['failed']    = absint( $metric['failed'] ) + $failed;
		$metric['deferred']  = absint( $metric['deferred'] ) + $deferred;
		$metric['lastFailed']= $is_failure;
		$metric['samplesMs'][] = $elapsed_ms;
		if ( count( $metric['samplesMs'] ) > 20 ) {
			$metric['samplesMs'] = array_slice( $metric['samplesMs'], -20 );
		}

		if ( $elapsed_ms >= self::slow_threshold_ms() ) {
			self::record_slow_operation(
				array(
					'type'      => 'stage',
					'stage'     => $stage,
					'elapsedMs' => $elapsed_ms,
					'status'    => isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : '',
					'processed' => self::result_count( $result, array( 'processed', 'processedProducts', 'productSteps' ) ),
					'failed'    => self::result_count( $result, array( 'failed' ) ),
					'deferred'  => self::result_count( $result, array( 'deferred' ) ),
					'at'        => time(),
				)
			);
		}
	}

	/** Record the final runner outcome. */
	public static function record_runner_result( $result ) {
		$result = is_array( $result ) ? $result : array();
		$runner = isset( $result['runner'] ) && is_array( $result['runner'] ) ? $result['runner'] : array();
		self::ensure_shutdown();
		if ( ! isset( self::$buffer['runner'] ) ) {
			self::$buffer['runner'] = array(
				'runs'       => 0,
				'totalMs'    => 0,
				'maxMs'      => 0,
				'stops'      => array(),
				'lastStopAt' => array(),
			);
		}
		$elapsed = absint( isset( $runner['elapsedMs'] ) ? $runner['elapsedMs'] : 0 );
		$reason  = sanitize_key( (string) ( isset( $runner['stopReason'] ) ? $runner['stopReason'] : 'unknown' ) );
		self::$buffer['runner']['runs']    = absint( self::$buffer['runner']['runs'] ) + 1;
		self::$buffer['runner']['totalMs'] = absint( self::$buffer['runner']['totalMs'] ) + $elapsed;
		self::$buffer['runner']['maxMs']   = max( absint( self::$buffer['runner']['maxMs'] ), $elapsed );
		if ( ! isset( self::$buffer['runner']['stops'][ $reason ] ) ) {
			self::$buffer['runner']['stops'][ $reason ] = 0;
		}
		self::$buffer['runner']['stops'][ $reason ]++;
		self::$buffer['runner']['lastStopAt'][ $reason ] = time();
	}

	/** Keep scheduler decisions and bounded per-stage fairness counters in memory. */
	public static function record_scheduler_decision( $round, $scheduler, $webhook_due = 0 ) {
		self::ensure_shutdown();
		$scheduler = is_array( $scheduler ) ? $scheduler : array();
		$selected  = isset( $scheduler['selectedStages'] ) && is_array( $scheduler['selectedStages'] ) ? array_values( $scheduler['selectedStages'] ) : array();
		$deferred  = isset( $scheduler['deferredStages'] ) && is_array( $scheduler['deferredStages'] ) ? array_values( $scheduler['deferredStages'] ) : array();
		self::$buffer['lastDecision'] = array(
			'at'                    => time(),
			'round'                 => absint( $round ),
			'webhookDue'            => absint( $webhook_due ),
			'webhookPressure'       => ! empty( $scheduler['webhookPressure'] ),
			'backgroundCapacity'    => absint( isset( $scheduler['backgroundCapacity'] ) ? $scheduler['backgroundCapacity'] : 0 ),
			'selectedStages'        => $selected,
			'deferredStages'        => $deferred,
			'scores'                => isset( $scheduler['scores'] ) && is_array( $scheduler['scores'] ) ? $scheduler['scores'] : array(),
		);

		if ( ! isset( self::$buffer['schedulerStages'] ) || ! is_array( self::$buffer['schedulerStages'] ) ) {
			self::$buffer['schedulerStages'] = array();
		}
		foreach ( $selected as $stage ) {
			$stage = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $stage );
			if ( '' === $stage ) {
				continue;
			}
			if ( ! isset( self::$buffer['schedulerStages'][ $stage ] ) ) {
				self::$buffer['schedulerStages'][ $stage ] = array( 'selected' => 0, 'deferred' => 0, 'lastSelectedAt' => 0 );
			}
			self::$buffer['schedulerStages'][ $stage ]['selected']++;
			self::$buffer['schedulerStages'][ $stage ]['lastSelectedAt'] = time();
		}
		foreach ( $deferred as $stage ) {
			$stage = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $stage );
			if ( '' === $stage ) {
				continue;
			}
			if ( ! isset( self::$buffer['schedulerStages'][ $stage ] ) ) {
				self::$buffer['schedulerStages'][ $stage ] = array( 'selected' => 0, 'deferred' => 0, 'lastSelectedAt' => 0 );
			}
			self::$buffer['schedulerStages'][ $stage ]['deferred']++;
		}
	}

	/** Keep the latest adaptive budget profile in the same bounded write. */
	public static function record_budget_profile( $profile ) {
		$profile = is_array( $profile ) ? $profile : array();
		if ( empty( $profile ) ) {
			return;
		}
		self::ensure_shutdown();
		self::$buffer['adaptiveBudget'] = $profile;
	}

	/** Keep the latest circuit-breaker profile in the same bounded write. */
	public static function record_circuit_profile( $profile ) {
		$profile = is_array( $profile ) ? $profile : array();
		if ( empty( $profile ) ) {
			return;
		}
		self::ensure_shutdown();
		self::$buffer['circuitBreakers'] = $profile;
	}

	/** Keep the latest adaptive execution profile in the same bounded diagnostics write. */
	public static function record_adaptive_profile( $profile ) {
		$profile = is_array( $profile ) ? $profile : array();
		if ( empty( $profile ) ) {
			return;
		}
		self::ensure_shutdown();
		self::$buffer['adaptiveTuning'] = $profile;
	}

	/** Return compact persisted diagnostics, enriched with live queue/environment checks. */
	public static function get_health_status() {
		self::flush();
		$data = get_option( self::OPTION_NAME, array() );
		$data = is_array( $data ) ? $data : array();
		$data['recommendations'] = self::build_recommendations( $data );
		$data['queueHealth']      = self::get_queue_health();
		$data['environment']      = self::get_environment_health();
		return $data;
	}

	/** Persist request-local metrics at most once. */
	public static function flush() {
		if ( self::$flushed || empty( self::$buffer ) ) {
			return;
		}
		self::$flushed = true;

		$now     = time();
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();
		$started = absint( isset( $current['windowStartedAt'] ) ? $current['windowStartedAt'] : 0 );
		if ( $started <= 0 || ( $now - $started ) >= self::WINDOW_SECONDS ) {
			$current = array(
				'windowStartedAt' => $now,
				'counters'        => array(),
				'stages'          => array(),
				'runner'          => array( 'runs' => 0, 'totalMs' => 0, 'maxMs' => 0, 'stops' => array(), 'lastStopAt' => array() ),
				'slowOperations'  => array(),
			);
		}

		$current['pluginVersion'] = defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : '';
		$current['updatedAt']     = $now;

		foreach ( (array) ( isset( self::$buffer['counters'] ) ? self::$buffer['counters'] : array() ) as $key => $value ) {
			$current['counters'][ $key ] = (int) ( isset( $current['counters'][ $key ] ) ? $current['counters'][ $key ] : 0 ) + (int) $value;
		}

		foreach ( (array) ( isset( self::$buffer['stages'] ) ? self::$buffer['stages'] : array() ) as $stage => $incoming ) {
			$old = isset( $current['stages'][ $stage ] ) && is_array( $current['stages'][ $stage ] ) ? $current['stages'][ $stage ] : array();
			$incoming_runs      = absint( isset( $incoming['runs'] ) ? $incoming['runs'] : 0 );
			$incoming_total_ms  = absint( isset( $incoming['totalMs'] ) ? $incoming['totalMs'] : 0 );
			$incoming_processed = absint( isset( $incoming['processed'] ) ? $incoming['processed'] : 0 );
			$incoming_failed    = absint( isset( $incoming['failed'] ) ? $incoming['failed'] : 0 );
			$incoming_deferred  = absint( isset( $incoming['deferred'] ) ? $incoming['deferred'] : 0 );
			$old_recent_ms      = absint( isset( $old['ewmaMsPerItem'] ) ? $old['ewmaMsPerItem'] : ( isset( $old['recentMsPerItem'] ) ? $old['recentMsPerItem'] : 0 ) );
			$old_recent_fail    = absint( isset( $old['ewmaFailPermille'] ) ? $old['ewmaFailPermille'] : ( isset( $old['recentFailPermille'] ) ? $old['recentFailPermille'] : 0 ) );
			$sample_ms          = $incoming_processed > 0 ? max( 1, (int) round( $incoming_total_ms / $incoming_processed ) ) : 0;
			$sample_fail        = $incoming_processed > 0 ? min( 1000, (int) round( 1000 * $incoming_failed / max( 1, $incoming_processed ) ) ) : 0;
			$recent_ms          = $sample_ms > 0 ? ( $old_recent_ms > 0 ? (int) round( ( $old_recent_ms * 3 + $sample_ms ) / 4 ) : $sample_ms ) : $old_recent_ms;
			$recent_fail        = $incoming_processed > 0 ? ( $old_recent_fail > 0 ? (int) round( ( $old_recent_fail * 3 + $sample_fail ) / 4 ) : $sample_fail ) : $old_recent_fail;
			$instant_trend      = $sample_ms > 0 && $old_recent_ms > 0 ? (int) round( 1000 * ( $sample_ms - $old_recent_ms ) / max( 1, $old_recent_ms ) ) : 0;
			$instant_trend      = max( -3000, min( 3000, $instant_trend ) );
			$old_trend          = (int) ( isset( $old['latencyTrendPermille'] ) ? $old['latencyTrendPermille'] : 0 );
			$latency_trend      = $sample_ms > 0 ? (int) round( ( $old_trend * 3 + $instant_trend * 2 ) / 5 ) : $old_trend;

			$last_failed = ! empty( $incoming['lastFailed'] );
			$failure_streak = $incoming_runs > 0 ? ( $last_failed ? absint( isset( $old['failureStreak'] ) ? $old['failureStreak'] : 0 ) + 1 : 0 ) : absint( isset( $old['failureStreak'] ) ? $old['failureStreak'] : 0 );
			$success_streak = $incoming_runs > 0 ? ( $last_failed ? 0 : absint( isset( $old['successStreak'] ) ? $old['successStreak'] : 0 ) + 1 ) : absint( isset( $old['successStreak'] ) ? $old['successStreak'] : 0 );

			$durations = isset( $old['recentDurationsMs'] ) && is_array( $old['recentDurationsMs'] ) ? $old['recentDurationsMs'] : array();
			foreach ( (array) ( isset( $incoming['samplesMs'] ) ? $incoming['samplesMs'] : array() ) as $duration ) {
				$durations[] = absint( $duration );
			}
			$durations = array_slice( $durations, -20 );

			$current['stages'][ $stage ] = array(
				'runs'                 => absint( isset( $old['runs'] ) ? $old['runs'] : 0 ) + $incoming_runs,
				'totalMs'              => absint( isset( $old['totalMs'] ) ? $old['totalMs'] : 0 ) + $incoming_total_ms,
				'maxMs'                => max( absint( isset( $old['maxMs'] ) ? $old['maxMs'] : 0 ), absint( isset( $incoming['maxMs'] ) ? $incoming['maxMs'] : 0 ) ),
				'processed'            => absint( isset( $old['processed'] ) ? $old['processed'] : 0 ) + $incoming_processed,
				'updated'              => absint( isset( $old['updated'] ) ? $old['updated'] : 0 ) + absint( isset( $incoming['updated'] ) ? $incoming['updated'] : 0 ),
				'failed'               => absint( isset( $old['failed'] ) ? $old['failed'] : 0 ) + $incoming_failed,
				'deferred'             => absint( isset( $old['deferred'] ) ? $old['deferred'] : 0 ) + $incoming_deferred,
				'recentMsPerItem'      => $recent_ms,
				'ewmaMsPerItem'        => $recent_ms,
				'recentFailPermille'   => $recent_fail,
				'ewmaFailPermille'     => $recent_fail,
				'latencyTrendPermille' => $latency_trend,
				'failureStreak'        => $failure_streak,
				'successStreak'        => $success_streak,
				'lastFailureAt'        => $incoming_runs > 0 && $last_failed ? $now : absint( isset( $old['lastFailureAt'] ) ? $old['lastFailureAt'] : 0 ),
				'lastSuccessAt'        => $incoming_runs > 0 && ! $last_failed ? $now : absint( isset( $old['lastSuccessAt'] ) ? $old['lastSuccessAt'] : 0 ),
				'recentDurationsMs'    => $durations,
				'p50Ms'                => self::percentile( $durations, 50 ),
				'p95Ms'                => self::percentile( $durations, 95 ),
				'lastMs'               => $incoming_runs > 0 ? (int) round( $incoming_total_ms / $incoming_runs ) : absint( isset( $old['lastMs'] ) ? $old['lastMs'] : 0 ),
				'lastAt'               => $incoming_runs > 0 ? $now : absint( isset( $old['lastAt'] ) ? $old['lastAt'] : 0 ),
			);
		}
		if ( count( $current['stages'] ) > self::MAX_STAGE_COUNT ) {
			$current['stages'] = array_slice( $current['stages'], -self::MAX_STAGE_COUNT, self::MAX_STAGE_COUNT, true );
		}

		if ( isset( self::$buffer['runner'] ) && is_array( self::$buffer['runner'] ) ) {
			$old_runner = isset( $current['runner'] ) && is_array( $current['runner'] ) ? $current['runner'] : array();
			$in_runner  = self::$buffer['runner'];
			$current['runner'] = array(
				'runs'       => absint( isset( $old_runner['runs'] ) ? $old_runner['runs'] : 0 ) + absint( isset( $in_runner['runs'] ) ? $in_runner['runs'] : 0 ),
				'totalMs'    => absint( isset( $old_runner['totalMs'] ) ? $old_runner['totalMs'] : 0 ) + absint( isset( $in_runner['totalMs'] ) ? $in_runner['totalMs'] : 0 ),
				'maxMs'      => max( absint( isset( $old_runner['maxMs'] ) ? $old_runner['maxMs'] : 0 ), absint( isset( $in_runner['maxMs'] ) ? $in_runner['maxMs'] : 0 ) ),
				'stops'      => isset( $old_runner['stops'] ) && is_array( $old_runner['stops'] ) ? $old_runner['stops'] : array(),
				'lastStopAt' => isset( $old_runner['lastStopAt'] ) && is_array( $old_runner['lastStopAt'] ) ? $old_runner['lastStopAt'] : array(),
			);
			foreach ( (array) ( isset( $in_runner['stops'] ) ? $in_runner['stops'] : array() ) as $reason => $count ) {
				$current['runner']['stops'][ $reason ] = absint( isset( $current['runner']['stops'][ $reason ] ) ? $current['runner']['stops'][ $reason ] : 0 ) + absint( $count );
			}
			foreach ( (array) ( isset( $in_runner['lastStopAt'] ) ? $in_runner['lastStopAt'] : array() ) as $reason => $timestamp ) {
				$current['runner']['lastStopAt'][ sanitize_key( (string) $reason ) ] = absint( $timestamp );
			}
		}

		$slow = isset( $current['slowOperations'] ) && is_array( $current['slowOperations'] ) ? $current['slowOperations'] : array();
		foreach ( (array) ( isset( self::$buffer['slowOperations'] ) ? self::$buffer['slowOperations'] : array() ) as $item ) {
			$slow[] = $item;
		}
		usort(
			$slow,
			static function ( $a, $b ) {
				return absint( isset( $b['at'] ) ? $b['at'] : 0 ) <=> absint( isset( $a['at'] ) ? $a['at'] : 0 );
			}
		);
		$current['slowOperations'] = array_slice( $slow, 0, self::MAX_SLOW_ITEMS );

		if ( isset( self::$buffer['lastDecision'] ) ) {
			$current['lastDecision'] = self::$buffer['lastDecision'];
		}
		if ( isset( self::$buffer['adaptiveTuning'] ) && is_array( self::$buffer['adaptiveTuning'] ) ) {
			$current['adaptiveTuning'] = self::$buffer['adaptiveTuning'];
		}
		if ( isset( self::$buffer['adaptiveBudget'] ) && is_array( self::$buffer['adaptiveBudget'] ) ) {
			$current['adaptiveBudget'] = self::$buffer['adaptiveBudget'];
		}
		if ( isset( self::$buffer['circuitBreakers'] ) && is_array( self::$buffer['circuitBreakers'] ) ) {
			$current['circuitBreakers'] = self::$buffer['circuitBreakers'];
		}
		if ( isset( self::$buffer['schedulerStages'] ) && is_array( self::$buffer['schedulerStages'] ) ) {
			$old_scheduler = isset( $current['schedulerStages'] ) && is_array( $current['schedulerStages'] ) ? $current['schedulerStages'] : array();
			foreach ( self::$buffer['schedulerStages'] as $stage => $incoming_scheduler ) {
				$old_stage = isset( $old_scheduler[ $stage ] ) && is_array( $old_scheduler[ $stage ] ) ? $old_scheduler[ $stage ] : array();
				$old_scheduler[ $stage ] = array(
					'selected'       => absint( isset( $old_stage['selected'] ) ? $old_stage['selected'] : 0 ) + absint( isset( $incoming_scheduler['selected'] ) ? $incoming_scheduler['selected'] : 0 ),
					'deferred'       => absint( isset( $old_stage['deferred'] ) ? $old_stage['deferred'] : 0 ) + absint( isset( $incoming_scheduler['deferred'] ) ? $incoming_scheduler['deferred'] : 0 ),
					'lastSelectedAt' => absint( isset( $incoming_scheduler['lastSelectedAt'] ) ? $incoming_scheduler['lastSelectedAt'] : ( isset( $old_stage['lastSelectedAt'] ) ? $old_stage['lastSelectedAt'] : 0 ) ),
				);
			}
			$current['schedulerStages'] = $old_scheduler;
		}

		update_option( self::OPTION_NAME, $current, false );
		self::$buffer = array();
	}

	private static function record_slow_operation( $item ) {
		if ( ! isset( self::$buffer['slowOperations'] ) || ! is_array( self::$buffer['slowOperations'] ) ) {
			self::$buffer['slowOperations'] = array();
		}
		self::$buffer['slowOperations'][] = $item;
		if ( count( self::$buffer['slowOperations'] ) > self::MAX_SLOW_ITEMS ) {
			self::$buffer['slowOperations'] = array_slice( self::$buffer['slowOperations'], -self::MAX_SLOW_ITEMS );
		}
	}

	/** Return an integer percentile from a small bounded sample. */
	private static function percentile( $values, $percentile ) {
		$values = array_values( array_filter( array_map( 'absint', is_array( $values ) ? $values : array() ), static function ( $value ) { return $value >= 0; } ) );
		if ( empty( $values ) ) {
			return 0;
		}
		sort( $values, SORT_NUMERIC );
		$percentile = max( 0, min( 100, absint( $percentile ) ) );
		$index = (int) ceil( ( $percentile / 100 ) * count( $values ) ) - 1;
		$index = max( 0, min( count( $values ) - 1, $index ) );
		return absint( $values[ $index ] );
	}

	private static function result_count( $result, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $result[ $key ] ) && is_numeric( $result[ $key ] ) ) {
				return absint( $result[ $key ] );
			}
		}
		return 0;
	}

	private static function slow_threshold_ms() {
		return max( 500, min( 10000, (int) apply_filters( 'mobo_core_slow_operation_threshold_ms', 2000 ) ) );
	}

	private static function get_queue_health() {
		$health = array(
			'webhook'        => array(),
			'image'          => array(),
			'parentFinalize' => array(),
		);
		if ( class_exists( 'Mobo_Core_Sync_Event_Store' ) && Mobo_Core_Sync_Event_Store::table_exists() ) {
			$store = new Mobo_Core_Sync_Event_Store();
			$health['webhook'] = $store->get_summary();
		}
		if ( class_exists( 'Mobo_Core_Image_Queue' ) && Mobo_Core_Image_Queue::table_exists() ) {
			$queue = new Mobo_Core_Image_Queue();
			$health['image'] = $queue->get_summary();
		}
		if ( class_exists( 'Mobo_Core_Parent_Finalize_Queue' ) ) {
			$health['parentFinalize'] = Mobo_Core_Parent_Finalize_Queue::get_status();
		}
		return $health;
	}

	private static function get_environment_health() {
		$hpos = null;
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && is_callable( array( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) ) {
			try {
				$hpos = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			} catch ( Throwable $e ) {
				$hpos = null;
			}
		}
		return array(
			'objectCacheEnabled' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
			'opCacheEnabled'     => function_exists( 'opcache_get_status' ) ? (bool) @opcache_get_status( false ) : null,
			'hposEnabled'        => $hpos,
			'realCronSeen'       => absint( get_option( 'mobo_core_real_cron_last_hit_at', 0 ) ) > 0,
		);
	}

	private static function build_recommendations( $data ) {
		$recommendations = array();
		$env = self::get_environment_health();
		$queue = self::get_queue_health();
		if ( empty( $env['objectCacheEnabled'] ) ) {
			$recommendations[] = array( 'severity' => 'info', 'code' => 'persistent-object-cache', 'message' => 'Persistent Object Cache شناسایی نشد؛ روی فروشگاه‌های بزرگ Redis/Object Cache می‌تواند خواندن‌های تکراری دیتابیس را کاهش دهد.' );
		}
		if ( false === $env['opCacheEnabled'] ) {
			$recommendations[] = array( 'severity' => 'warning', 'code' => 'opcache-disabled', 'message' => 'PHP OPcache غیرفعال است؛ فعال‌کردن آن هزینه parse/compile فایل‌های PHP را کاهش می‌دهد.' );
		}
		if ( false === $env['hposEnabled'] ) {
			$recommendations[] = array( 'severity' => 'info', 'code' => 'hpos-disabled', 'message' => 'HPOS ووکامرس فعال نیست؛ برای فروشگاه‌های سفارش‌محور، بعد از بررسی سازگاری افزونه‌ها فعال‌سازی آن را ارزیابی کنید.' );
		}
		$webhook = isset( $queue['webhook'] ) && is_array( $queue['webhook'] ) ? $queue['webhook'] : array();
		if ( absint( isset( $webhook['dueCount'] ) ? $webhook['dueCount'] : 0 ) >= 100 ) {
			$recommendations[] = array( 'severity' => 'warning', 'code' => 'webhook-backlog', 'message' => 'Backlog وب‌هوک‌های آماده زیاد است؛ Scheduler تا کاهش فشار، Desired State را در اولویت نگه می‌دارد.' );
		}
		$image = isset( $queue['image'] ) && is_array( $queue['image'] ) ? $queue['image'] : array();
		if ( absint( isset( $image['failed'] ) ? $image['failed'] : 0 ) > 0 ) {
			$recommendations[] = array( 'severity' => 'warning', 'code' => 'image-failures', 'message' => 'صف تصویر Job ناموفق دارد؛ قبل از افزایش throughput، آخرین خطای تصویر را بررسی کنید.' );
		}
		$runner = isset( $data['runner'] ) && is_array( $data['runner'] ) ? $data['runner'] : array();
		$stops  = isset( $runner['stops'] ) && is_array( $runner['stops'] ) ? $runner['stops'] : array();
		if ( absint( isset( $stops['memory-pressure'] ) ? $stops['memory-pressure'] : 0 ) > 0 ) {
			$recommendations[] = array( 'severity' => 'warning', 'code' => 'memory-pressure', 'message' => 'اجرای اخیر Runner به‌خاطر فشار حافظه متوقف شده؛ Adaptive Tuning ظرفیت batch را محافظه‌کارانه کاهش می‌دهد و در صورت تکرار، افزایش PHP memory_limit را بررسی کنید.' );
		}
		$adaptive = isset( $data['adaptiveTuning'] ) && is_array( $data['adaptiveTuning'] ) ? $data['adaptiveTuning'] : array();
		if ( ! empty( $adaptive['enabled'] ) && in_array( isset( $adaptive['mode'] ) ? (string) $adaptive['mode'] : '', array( 'conservative', 'cautious' ), true ) ) {
			$recommendations[] = array( 'severity' => 'info', 'code' => 'adaptive-tuning-limited', 'message' => 'Adaptive Tuning ظرفیت Worker را موقتاً کاهش داده است (' . sanitize_key( (string) $adaptive['reason'] ) . ')؛ این رفتار برای جلوگیری از timeout یا فشار حافظه خودکار است.' );
		}

		$circuits = isset( $data['circuitBreakers']['stages'] ) && is_array( $data['circuitBreakers']['stages'] ) ? $data['circuitBreakers']['stages'] : array();
		foreach ( $circuits as $stage => $circuit ) {
			$state = isset( $circuit['state'] ) ? sanitize_key( (string) $circuit['state'] ) : 'closed';
			if ( in_array( $state, array( 'open', 'degraded', 'half-open' ), true ) ) {
				$recommendations[] = array(
					'severity' => 'warning',
					'code'     => 'circuit-' . sanitize_key( (string) $stage ),
					'message'  => 'Circuit Breaker برای ' . sanitize_key( (string) $stage ) . ' در وضعیت ' . $state . ' است؛ علت: ' . sanitize_key( (string) ( isset( $circuit['reason'] ) ? $circuit['reason'] : 'unknown' ) ) . '.',
				);
			}
		}

		$scheduler = isset( $data['schedulerStages'] ) && is_array( $data['schedulerStages'] ) ? $data['schedulerStages'] : array();
		$now = time();
		foreach ( $scheduler as $stage => $stat ) {
			$selected = absint( isset( $stat['selected'] ) ? $stat['selected'] : 0 );
			$deferred = absint( isset( $stat['deferred'] ) ? $stat['deferred'] : 0 );
			$last_selected = absint( isset( $stat['lastSelectedAt'] ) ? $stat['lastSelectedAt'] : 0 );
			if ( $deferred >= 10 && $deferred > max( 5, $selected * 5 ) && ( 0 === $last_selected || ( $now - $last_selected ) >= 600 ) ) {
				$recommendations[] = array( 'severity' => 'warning', 'code' => 'scheduler-starvation-' . sanitize_key( (string) $stage ), 'message' => 'Stage ' . sanitize_key( (string) $stage ) . ' مدت زیادی defer شده است؛ Fair Scheduler در اجرای بعدی starvation boost اعمال می‌کند.' );
			}
		}

		return array_slice( $recommendations, 0, 10 );
	}
}
