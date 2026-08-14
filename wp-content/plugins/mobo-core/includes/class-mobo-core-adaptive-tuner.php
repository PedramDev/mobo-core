<?php
/**
 * Adaptive execution tuning for bounded Mobo Core workers.
 *
 * The tuner never persists batch-size changes. It derives conservative request-
 * local limits from the configured baseline, rolling runtime diagnostics and
 * current memory headroom. The 10.33.17 runtime keeps request-independent
 * stability guards through the already bounded diagnostics payload: confidence,
 * hysteresis and per-stage cooldown anchors prevent batch-size oscillation while
 * safety downshifts remain immediate.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Adaptive_Tuner {

	const DIAGNOSTICS_OPTION         = 'mobo_core_runtime_diagnostics';
	const COOLDOWN_SECONDS           = 600;
	const HYSTERESIS_PERMILLE        = 200;
	const UPSHIFT_CONFIDENCE         = 600;
	const DOWNSHIFT_CONFIDENCE       = 350;
	const MAX_UPSHIFT_STEP_PERMILLE  = 500;

	/**
	 * Build one immutable tuning profile for the current runner invocation.
	 *
	 * @param array $config Runner runtime configuration.
	 * @return array
	 */
	public static function build_profile( $config ) {
		$config  = is_array( $config ) ? $config : array();
		$enabled = Mobo_Core_Settings::enabled( 'mobo_core_adaptive_execution_enabled', '1' );

		$baseline = array(
			'webhookQueue'   => Mobo_Core_Settings::get_int( 'mobo_core_webhook_files_per_run', 4, 1, 10 ),
			'parentFinalize' => Mobo_Core_Settings::get_int( 'mobo_core_parent_finalize_batch_size', 10, 1, 50 ),
			'imageQueue'     => max( 1, Mobo_Core_Settings::get_int( 'mobo_core_images_per_run', 3, 0, 10 ) ),
			'repriceQueue'   => Mobo_Core_Settings::get_int( 'mobo_core_reprice_batch_size', 20, 1, 200 ),
			'recategorizeQueue' => Mobo_Core_Settings::get_int( 'mobo_core_recategorize_batch_size', 20, 1, 200 ),
			'cacheWarmup'    => Mobo_Core_Settings::get_int( 'mobo_core_cache_warmup_batch_size', 2, 1, 10 ),
			'productStepsPerRound' => max( 1, min( 20, absint( isset( $config['productStepsPerRound'] ) ? $config['productStepsPerRound'] : 3 ) ) ),
		);

		$profile = array(
			'enabled'      => $enabled,
			'mode'         => $enabled ? 'learning' : 'disabled',
			'reason'       => $enabled ? 'insufficient-history' : 'disabled-by-setting',
			'generatedAt'  => time(),
			'baseline'     => $baseline,
			'limits'       => $baseline,
			'samples'      => array(),
			'decisions'    => array(),
			'memory'       => self::memory_snapshot( $config ),
			'stability'    => array(
				'cooldownSeconds'          => self::COOLDOWN_SECONDS,
				'hysteresisPermille'       => self::HYSTERESIS_PERMILLE,
				'upshiftConfidencePermille'=> self::UPSHIFT_CONFIDENCE,
				'downshiftConfidencePermille' => self::DOWNSHIFT_CONFIDENCE,
				'lastChangedAt'            => array(),
				'heldStages'               => array(),
				'changedStages'            => array(),
			),
		);

		if ( ! $enabled ) {
			return $profile;
		}

		$diagnostics = get_option( self::DIAGNOSTICS_OPTION, array() );
		$diagnostics = is_array( $diagnostics ) ? $diagnostics : array();
		$stages      = isset( $diagnostics['stages'] ) && is_array( $diagnostics['stages'] ) ? $diagnostics['stages'] : array();
		$runner      = isset( $diagnostics['runner'] ) && is_array( $diagnostics['runner'] ) ? $diagnostics['runner'] : array();
		$previous    = isset( $diagnostics['adaptiveTuning'] ) && is_array( $diagnostics['adaptiveTuning'] ) ? $diagnostics['adaptiveTuning'] : array();
		$stops       = isset( $runner['stops'] ) && is_array( $runner['stops'] ) ? $runner['stops'] : array();
		$last_stop_at = isset( $runner['lastStopAt'] ) && is_array( $runner['lastStopAt'] ) ? $runner['lastStopAt'] : array();
		$runner_runs = absint( isset( $runner['runs'] ) ? $runner['runs'] : 0 );

		$now             = time();
		$memory_pressure = absint( isset( $stops['memory-pressure'] ) ? $stops['memory-pressure'] : 0 );
		$time_pressure   = absint( isset( $stops['time-budget-exhausted'] ) ? $stops['time-budget-exhausted'] : 0 );
		$memory_stop_at  = absint( isset( $last_stop_at['memory-pressure'] ) ? $last_stop_at['memory-pressure'] : 0 );
		$time_stop_at    = absint( isset( $last_stop_at['time-budget-exhausted'] ) ? $last_stop_at['time-budget-exhausted'] : 0 );
		$recent_memory_pressure = $memory_pressure > 0 && $memory_stop_at > 0 && ( $now - $memory_stop_at ) <= 2 * HOUR_IN_SECONDS;
		$recent_time_pressure   = $time_pressure > 0 && $time_stop_at > 0 && ( $now - $time_stop_at ) <= HOUR_IN_SECONDS;
		$memory          = $profile['memory'];
		$headroom_tight  = ! empty( $memory['limited'] ) && $memory['headroomBytes'] > 0 && $memory['reserveBytes'] > 0 && $memory['headroomBytes'] < ( $memory['reserveBytes'] * 2 );
		$time_pressure_rate = $runner_runs > 0 ? $time_pressure / $runner_runs : 0.0;

		$global_scale = 1.0;
		$mode         = 'balanced';
		$reason       = 'stable-runtime';

		if ( $headroom_tight || $recent_memory_pressure ) {
			$global_scale = 0.60;
			$mode         = 'conservative';
			$reason       = $headroom_tight ? 'low-memory-headroom' : 'recent-memory-pressure';
		} elseif ( $recent_time_pressure && $runner_runs >= 4 && $time_pressure_rate >= 0.35 ) {
			$global_scale = 0.75;
			$mode         = 'cautious';
			$reason       = 'frequent-time-budget-exhaustion';
		} elseif ( $runner_runs < 3 ) {
			$mode   = 'learning';
			$reason = 'insufficient-history';
		}

		$budget_ms = max( 3000, absint( isset( $config['effectiveTimeBudgetSeconds'] ) ? $config['effectiveTimeBudgetSeconds'] : 25 ) * 1000 );
		$targets   = array(
			'webhookQueue'      => min( 6500, max( 1600, (int) round( $budget_ms * 0.28 ) ) ),
			'parentFinalize'    => min( 3000, max( 900, (int) round( $budget_ms * 0.12 ) ) ),
			'imageQueue'        => min( 5000, max( 1400, (int) round( $budget_ms * 0.20 ) ) ),
			'repriceQueue'      => min( 4000, max( 1000, (int) round( $budget_ms * 0.16 ) ) ),
			'recategorizeQueue' => min( 4000, max( 1000, (int) round( $budget_ms * 0.16 ) ) ),
			'cacheWarmup'       => min( 3500, max( 1200, (int) round( $budget_ms * 0.14 ) ) ),
			'productSync'       => min( 6500, max( 1800, (int) round( $budget_ms * 0.24 ) ) ),
		);
		$hard_max = array(
			'webhookQueue'      => Mobo_Core_Settings::get_int( 'mobo_core_webhook_adaptive_batch_max', 10, 1, 10 ),
			'parentFinalize'    => 50,
			'imageQueue'        => 10,
			'repriceQueue'      => 200,
			'recategorizeQueue' => 200,
			'cacheWarmup'       => 10,
			'productSync'       => 20,
		);

		$learned              = 0;
		$product_sync_learned = false;
		foreach ( $targets as $stage => $target_ms ) {
			$minimum_samples = 'productSync' === $stage ? 4 : 2;
			$sample = self::stage_sample( isset( $stages[ $stage ] ) ? $stages[ $stage ] : array(), $minimum_samples, $now );
			$profile['samples'][ $stage ] = $sample;
			if ( $sample['msPerItem'] <= 0 || $sample['processed'] < $minimum_samples ) {
				continue;
			}

			$learned++;
			if ( 'productSync' === $stage ) {
				$product_sync_learned = true;
			}

			$limit_key = 'productSync' === $stage ? 'productStepsPerRound' : $stage;
			$base      = absint( $baseline[ $limit_key ] );
			$target_ms = max( 250, (int) round( $target_ms * $global_scale ) );
			$ideal     = max( 1, (int) floor( $target_ms / max( 1, $sample['msPerItem'] ) ) );
			$min_limit = 'productSync' === $stage ? 1 : max( 1, (int) ceil( $base * 0.50 ) );
			$max_limit = min( absint( $hard_max[ $stage ] ), max( $base, (int) floor( $base * 2.0 ) ) );
			if ( $global_scale < 1.0 ) {
				$max_limit = max( $min_limit, min( $max_limit, (int) floor( $base * $global_scale ) ) );
			}

			$recent_failure_guard = $sample['recentFailPermille'] >= 100 || ( $sample['failed'] > 0 && $sample['runs'] <= 3 );
			if ( $recent_failure_guard ) {
				$max_limit = min( $max_limit, $base );
			}

			$anchor = self::previous_limit( $previous, $baseline, $limit_key, $base );
			$anchor = max( $min_limit, min( $max_limit, $anchor ) );
			if ( isset( $sample['latencyTrendPermille'] ) && (int) $sample['latencyTrendPermille'] >= 500 ) {
				$max_limit = min( $max_limit, $anchor );
			}
			$candidate = max( $min_limit, min( $max_limit, $ideal ) );
			$previous_changed_at = self::previous_changed_at( $previous, $baseline, $limit_key, $base );
			$cooldown_active = $previous_changed_at > 0 && ( $now - $previous_changed_at ) < self::COOLDOWN_SECONDS;
			$predicted_ms = $anchor * $sample['msPerItem'];
			$hysteresis_low  = (int) round( $target_ms * ( 1000 - self::HYSTERESIS_PERMILLE ) / 1000 );
			$hysteresis_high = (int) round( $target_ms * ( 1000 + self::HYSTERESIS_PERMILLE ) / 1000 );
			$decision_reason = 'measured-target';

			/* When the current anchor already lands inside the target band, retain it
			 * even if integer division would suggest a neighbouring value. */
			if ( $anchor >= $min_limit && $anchor <= $max_limit && $predicted_ms >= $hysteresis_low && $predicted_ms <= $hysteresis_high ) {
				$candidate = $anchor;
				$decision_reason = 'hysteresis-hold';
			}

			$is_upshift = $candidate > $anchor;
			$is_downshift = $candidate < $anchor;
			$severe_slowdown = $predicted_ms > (int) round( $target_ms * 1.50 );
			$safety_downshift = $global_scale < 1.0 || $recent_failure_guard || $severe_slowdown;

			if ( $is_upshift && $sample['confidencePermille'] < self::UPSHIFT_CONFIDENCE ) {
				$candidate = $anchor;
				$decision_reason = 'low-confidence-upshift-hold';
			} elseif ( $is_downshift && ! $safety_downshift && $sample['confidencePermille'] < self::DOWNSHIFT_CONFIDENCE ) {
				$candidate = $anchor;
				$decision_reason = 'low-confidence-downshift-hold';
			}

			$is_upshift   = $candidate > $anchor;
			$is_downshift = $candidate < $anchor;
			if ( $cooldown_active && ( $is_upshift || ( $is_downshift && ! $safety_downshift ) ) ) {
				$candidate = $anchor;
				$decision_reason = 'cooldown-hold';
			}

			/* Ramp upward by at most 50% of the previous stable anchor per accepted
			 * decision. This keeps fast hosts from jumping from baseline to 2x in one run. */
			if ( $candidate > $anchor ) {
				$max_step = max( 1, (int) ceil( $anchor * self::MAX_UPSHIFT_STEP_PERMILLE / 1000 ) );
				$candidate = min( $candidate, $anchor + $max_step );
				if ( $candidate < $ideal ) {
					$decision_reason = 'bounded-upshift';
				}
			}

			$applied = max( $min_limit, min( $max_limit, $candidate ) );
			$profile['limits'][ $limit_key ] = $applied;
			if ( $applied !== $anchor ) {
				$profile['stability']['lastChangedAt'][ $limit_key ] = $now;
				$profile['stability']['changedStages'][] = $limit_key;
			} else {
				if ( $previous_changed_at > 0 ) {
					$profile['stability']['lastChangedAt'][ $limit_key ] = $previous_changed_at;
				}
				if ( false !== strpos( $decision_reason, 'hold' ) ) {
					$profile['stability']['heldStages'][] = $limit_key;
				}
			}

			$profile['decisions'][ $stage ] = array(
				'limitKey'            => $limit_key,
				'anchor'              => $anchor,
				'ideal'               => $ideal,
				'applied'             => $applied,
				'targetMs'            => $target_ms,
				'predictedMsAtAnchor' => $predicted_ms,
				'confidencePermille'  => $sample['confidencePermille'],
				'cooldownActive'      => $cooldown_active,
				'reason'              => $decision_reason,
			);
		}

		/* Preserve 10.33.16.17 pressure-only Product Sync downshift before enough
		 * Product Sync timing samples exist to make a measured decision. */
		if ( $global_scale < 1.0 && ! $product_sync_learned ) {
			$profile['limits']['productStepsPerRound'] = max( 1, (int) floor( $baseline['productStepsPerRound'] * $global_scale ) );
		}

		if ( 0 === $learned && 'balanced' === $mode ) {
			$mode   = 'learning';
			$reason = 'waiting-for-stage-samples';
		} elseif ( ( $learned >= 2 || $product_sync_learned ) && 'balanced' === $mode ) {
			$increased = false;
			$decreased = false;
			foreach ( $profile['limits'] as $key => $value ) {
				if ( ! isset( $baseline[ $key ] ) ) {
					continue;
				}
				$increased = $increased || absint( $value ) > absint( $baseline[ $key ] );
				$decreased = $decreased || absint( $value ) < absint( $baseline[ $key ] );
			}
			if ( $increased && ! $decreased ) {
				$mode   = 'accelerated';
				$reason = 'fast-stage-samples';
			} elseif ( $decreased ) {
				$mode   = 'cautious';
				$reason = 'slow-stage-samples';
			} elseif ( ! empty( $profile['stability']['heldStages'] ) ) {
				$reason = 'stability-guard-hold';
			}
		}

		$profile['mode']   = $mode;
		$profile['reason'] = $reason;
		$profile['learnedStageCount'] = $learned;

		return $profile;
	}

	/**
	 * Compact stage sample from rolling diagnostics, including bounded confidence.
	 *
	 * Confidence is intentionally asymmetric at decision time: scaling up needs a
	 * stronger sample than ordinary scaling down. Pressure/failure safety downshifts
	 * can still bypass confidence/cooldown.
	 *
	 * @param array $stage Stage metrics.
	 * @param int   $minimum_samples Minimum processed items for the stage.
	 * @param int   $now Current timestamp.
	 * @return array
	 */
	private static function stage_sample( $stage, $minimum_samples, $now ) {
		$stage = is_array( $stage ) ? $stage : array();
		$processed = absint( isset( $stage['processed'] ) ? $stage['processed'] : 0 );
		$total_ms  = absint( isset( $stage['totalMs'] ) ? $stage['totalMs'] : 0 );
		$recent    = absint( isset( $stage['ewmaMsPerItem'] ) ? $stage['ewmaMsPerItem'] : ( isset( $stage['recentMsPerItem'] ) ? $stage['recentMsPerItem'] : 0 ) );
		$runs      = absint( isset( $stage['runs'] ) ? $stage['runs'] : 0 );
		$last_at   = absint( isset( $stage['lastAt'] ) ? $stage['lastAt'] : 0 );
		$ms_per_item = $recent > 0 ? $recent : ( $processed > 0 ? max( 1, (int) round( $total_ms / $processed ) ) : 0 );

		$minimum_samples = max( 1, absint( $minimum_samples ) );
		$processed_factor = min( 1.0, $processed / max( 1, $minimum_samples * 3 ) );
		$runs_factor      = min( 1.0, $runs / 4 );
		$freshness_factor = 1.0;
		if ( $last_at > 0 ) {
			$age = max( 0, $now - $last_at );
			if ( $age > 12 * HOUR_IN_SECONDS ) {
				$freshness_factor = 0.60;
			} elseif ( $age > 6 * HOUR_IN_SECONDS ) {
				$freshness_factor = 0.75;
			} elseif ( $age > HOUR_IN_SECONDS ) {
				$freshness_factor = 0.90;
			}
		}
		$confidence = (int) round( 1000 * ( ( $processed_factor * 0.70 ) + ( $runs_factor * 0.30 ) ) * $freshness_factor );
		$confidence = max( 0, min( 1000, $confidence ) );

		return array(
			'runs'               => $runs,
			'processed'          => $processed,
			'failed'             => absint( isset( $stage['failed'] ) ? $stage['failed'] : 0 ),
			'msPerItem'          => $ms_per_item,
			'recentFailPermille' => absint( isset( $stage['ewmaFailPermille'] ) ? $stage['ewmaFailPermille'] : ( isset( $stage['recentFailPermille'] ) ? $stage['recentFailPermille'] : 0 ) ),
			'latencyTrendPermille' => (int) ( isset( $stage['latencyTrendPermille'] ) ? $stage['latencyTrendPermille'] : 0 ),
			'p95Ms'              => absint( isset( $stage['p95Ms'] ) ? $stage['p95Ms'] : 0 ),
			'lastAt'             => $last_at,
			'confidencePermille' => $confidence,
		);
	}

	/** Return a previous stage limit only while its configured baseline is unchanged. */
	private static function previous_limit( $previous, $baseline, $limit_key, $fallback ) {
		$previous = is_array( $previous ) ? $previous : array();
		$previous_baseline = isset( $previous['baseline'] ) && is_array( $previous['baseline'] ) ? $previous['baseline'] : array();
		$previous_limits   = isset( $previous['limits'] ) && is_array( $previous['limits'] ) ? $previous['limits'] : array();
		if ( ! isset( $previous_baseline[ $limit_key ], $baseline[ $limit_key ] ) || absint( $previous_baseline[ $limit_key ] ) !== absint( $baseline[ $limit_key ] ) ) {
			return absint( $fallback );
		}
		return isset( $previous_limits[ $limit_key ] ) ? max( 1, absint( $previous_limits[ $limit_key ] ) ) : absint( $fallback );
	}

	/** Return the last accepted capacity-change timestamp for one stage. */
	private static function previous_changed_at( $previous, $baseline, $limit_key, $fallback ) {
		$previous = is_array( $previous ) ? $previous : array();
		$previous_baseline = isset( $previous['baseline'] ) && is_array( $previous['baseline'] ) ? $previous['baseline'] : array();
		if ( ! isset( $previous_baseline[ $limit_key ], $baseline[ $limit_key ] ) || absint( $previous_baseline[ $limit_key ] ) !== absint( $baseline[ $limit_key ] ) ) {
			return 0;
		}
		$stability = isset( $previous['stability'] ) && is_array( $previous['stability'] ) ? $previous['stability'] : array();
		$changed   = isset( $stability['lastChangedAt'] ) && is_array( $stability['lastChangedAt'] ) ? $stability['lastChangedAt'] : array();
		if ( isset( $changed[ $limit_key ] ) ) {
			return absint( $changed[ $limit_key ] );
		}

		/* Bootstrap from 10.33.16.17/18: only a previously non-baseline adaptive
		 * limit is treated as a recent change. Baseline values do not create a fake
		 * cooldown on first 10.33.16.19 execution. */
		$limits = isset( $previous['limits'] ) && is_array( $previous['limits'] ) ? $previous['limits'] : array();
		if ( isset( $limits[ $limit_key ] ) && absint( $limits[ $limit_key ] ) !== absint( $fallback ) ) {
			return absint( isset( $previous['generatedAt'] ) ? $previous['generatedAt'] : 0 );
		}
		return 0;
	}

	/**
	 * Current memory headroom snapshot.
	 *
	 * @param array $config Runner config.
	 * @return array
	 */
	private static function memory_snapshot( $config ) {
		$limit   = absint( isset( $config['memoryLimitBytes'] ) ? $config['memoryLimitBytes'] : 0 );
		$reserve = absint( isset( $config['memoryReserveBytes'] ) ? $config['memoryReserveBytes'] : 0 );
		$usage   = function_exists( 'memory_get_usage' ) ? absint( memory_get_usage( true ) ) : 0;
		$headroom = $limit > 0 ? max( 0, $limit - $usage ) : 0;

		return array(
			'limited'       => $limit > 0,
			'usageBytes'    => $usage,
			'limitBytes'    => $limit,
			'reserveBytes'  => $reserve,
			'headroomBytes' => $headroom,
		);
	}
}
