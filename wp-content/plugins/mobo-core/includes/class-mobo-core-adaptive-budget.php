<?php
/**
 * Adaptive per-stage time budget allocator.
 *
 * The allocator is intentionally request-local. It receives one queue snapshot
 * captured by the runner, combines backlog pressure with persisted latency/failure
 * intelligence, and returns bounded stage budgets. No configuration is mutated.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Adaptive_Budget {

	const MIN_STAGE_MS = 350;

	/**
	 * Build one immutable budget profile.
	 *
	 * @param array $config Runner config.
	 * @param array $adaptive_tuning Adaptive batch profile.
	 * @param array $queue_snapshot Queue snapshot from runner.
	 * @return array
	 */
	public static function build_profile( $config, $adaptive_tuning = array(), $queue_snapshot = array() ) {
		$config          = is_array( $config ) ? $config : array();
		$adaptive_tuning = is_array( $adaptive_tuning ) ? $adaptive_tuning : array();
		$queue_snapshot  = is_array( $queue_snapshot ) ? $queue_snapshot : array();
		$enabled         = Mobo_Core_Settings::enabled( 'mobo_core_adaptive_execution_enabled', '1' );

		$total_ms = max( 3000, absint( isset( $config['effectiveTimeBudgetSeconds'] ) ? $config['effectiveTimeBudgetSeconds'] : 25 ) * 1000 );
		$safety_ms = max( 500, absint( isset( $config['safetyMarginSeconds'] ) ? $config['safetyMarginSeconds'] : 2 ) * 1000 );
		$pool_ms   = max( 2000, $total_ms - min( $safety_ms, (int) floor( $total_ms * 0.25 ) ) );

		$base_weights = array(
			'webhookQueue'      => 240,
			'orderSubmissions'  => 190,
			'productSync'       => 180,
			'parentFinalize'    => 100,
			'imageQueue'        => 80,
			'imageRefreshQueue' => 55,
			'repriceQueue'      => 45,
			'recategorizeQueue' => 45,
			'cacheWarmup'       => 25,
			'maintenance'       => 15,
		);

		$minimums = array(
			'webhookQueue'      => 1000,
			'orderSubmissions'  => 900,
			'productSync'       => 1000,
			'parentFinalize'    => 500,
			'imageQueue'        => 500,
			'imageRefreshQueue' => 400,
			'repriceQueue'      => 400,
			'recategorizeQueue' => 400,
			'cacheWarmup'       => 350,
			'maintenance'       => 350,
		);

		$diagnostics = get_option( 'mobo_core_runtime_diagnostics', array() );
		$diagnostics = is_array( $diagnostics ) ? $diagnostics : array();
		$stage_stats = isset( $diagnostics['stages'] ) && is_array( $diagnostics['stages'] ) ? $diagnostics['stages'] : array();
		$previous_budget = isset( $diagnostics['adaptiveBudget'] ) && is_array( $diagnostics['adaptiveBudget'] ) ? $diagnostics['adaptiveBudget'] : array();
		$previous_backlog = isset( $previous_budget['backlog'] ) && is_array( $previous_budget['backlog'] ) ? $previous_budget['backlog'] : array();

		$backlog   = array();
		$active    = array();
		$pressure  = array();
		$weights   = array();
		$trends    = array();

		foreach ( $base_weights as $stage => $base_weight ) {
			$item = isset( $queue_snapshot[ $stage ] ) && is_array( $queue_snapshot[ $stage ] ) ? $queue_snapshot[ $stage ] : array();
			$count = absint( isset( $item['backlog'] ) ? $item['backlog'] : ( isset( $previous_backlog[ $stage ] ) ? $previous_backlog[ $stage ] : 0 ) );
			$is_active = array_key_exists( 'active', $item ) ? ! empty( $item['active'] ) : $count > 0;
			$backlog[ $stage ] = $count;
			$active[ $stage ]  = $is_active;
			$pressure[ $stage ] = self::pressure_permille( $count );

			$old_count = absint( isset( $previous_backlog[ $stage ] ) ? $previous_backlog[ $stage ] : 0 );
			$trends[ $stage ] = $count - $old_count;

			$stats = isset( $stage_stats[ $stage ] ) && is_array( $stage_stats[ $stage ] ) ? $stage_stats[ $stage ] : array();
			$fail_permille = absint( isset( $stats['ewmaFailPermille'] ) ? $stats['ewmaFailPermille'] : ( isset( $stats['recentFailPermille'] ) ? $stats['recentFailPermille'] : 0 ) );
			$trend_permille = (int) ( isset( $stats['latencyTrendPermille'] ) ? $stats['latencyTrendPermille'] : 0 );

			/* Backlog can at most double the baseline weight. Rising latency/failures
			 * reduce discretionary budget so unhealthy stages cannot consume the runner.
			 * The existing adaptive-execution master switch disables these adjustments. */
			$weight = $base_weight;
			if ( $enabled ) {
				$weight += (int) round( $base_weight * $pressure[ $stage ] / 1000 );
				if ( $fail_permille >= 250 ) {
					$weight = (int) floor( $weight * 0.65 );
				} elseif ( $fail_permille >= 100 ) {
					$weight = (int) floor( $weight * 0.82 );
				}
				if ( $trend_permille >= 500 ) {
					$weight = (int) floor( $weight * 0.85 );
				}
			}
			$weights[ $stage ] = max( 5, $weight );
		}

		/* Critical lanes keep a reserve while they actually have work. */
		if ( ! empty( $active['webhookQueue'] ) ) {
			$weights['webhookQueue'] = max( $weights['webhookQueue'], 320 );
		}
		if ( ! empty( $active['orderSubmissions'] ) ) {
			$weights['orderSubmissions'] = max( $weights['orderSubmissions'], 260 );
		}
		if ( ! empty( $active['productSync'] ) ) {
			$weights['productSync'] = max( $weights['productSync'], 220 );
		}

		$active_stages = array();
		foreach ( $active as $stage => $is_active ) {
			if ( $is_active ) {
				$active_stages[] = $stage;
			}
		}
		if ( empty( $active_stages ) ) {
			$active_stages = array( 'webhookQueue', 'productSync' );
		}

		$min_total = 0;
		foreach ( $active_stages as $stage ) {
			$min_total += isset( $minimums[ $stage ] ) ? $minimums[ $stage ] : self::MIN_STAGE_MS;
		}
		$scale = $min_total > $pool_ms ? $pool_ms / max( 1, $min_total ) : 1.0;
		$budgets = array();
		$used    = 0;
		foreach ( $active_stages as $stage ) {
			$min_ms = isset( $minimums[ $stage ] ) ? $minimums[ $stage ] : self::MIN_STAGE_MS;
			$allocated = max( 250, (int) floor( $min_ms * $scale ) );
			$budgets[ $stage ] = $allocated;
			$used += $allocated;
		}

		$remaining = max( 0, $pool_ms - $used );
		$weight_total = 0;
		foreach ( $active_stages as $stage ) {
			$weight_total += absint( isset( $weights[ $stage ] ) ? $weights[ $stage ] : 1 );
		}
		if ( $remaining > 0 && $weight_total > 0 ) {
			foreach ( $active_stages as $stage ) {
				$share = (int) floor( $remaining * absint( $weights[ $stage ] ) / $weight_total );
				$budgets[ $stage ] += $share;
			}
		}

		/* Bound single-stage monopolization even with extreme backlog. */
		$max_single = max( 1000, (int) floor( $pool_ms * 0.42 ) );
		foreach ( $base_weights as $stage => $unused ) {
			if ( ! isset( $budgets[ $stage ] ) ) {
				$budgets[ $stage ] = 0;
			}
			$budgets[ $stage ] = min( $max_single, absint( $budgets[ $stage ] ) );
		}

		$budget_seconds = array();
		foreach ( $budgets as $stage => $milliseconds ) {
			$budget_seconds[ $stage ] = $milliseconds > 0 ? max( 1, min( 20, (int) ceil( $milliseconds / 1000 ) ) ) : 0;
		}

		return array(
			'enabled'              => $enabled,
			'generatedAt'          => time(),
			'totalBudgetMs'        => $total_ms,
			'distributableMs'      => $pool_ms,
			'weights'              => $weights,
			'backlog'              => $backlog,
			'backlogTrend'         => $trends,
			'pressurePermille'     => $pressure,
			'activeStages'         => $active_stages,
			'stageBudgetMs'        => $budgets,
			'stageBudgetSeconds'   => $budget_seconds,
			'foregroundReserved'   => array( 'webhookQueue', 'orderSubmissions', 'productSync' ),
			'adaptiveMode'         => isset( $adaptive_tuning['mode'] ) ? sanitize_key( (string) $adaptive_tuning['mode'] ) : '',
		);
	}

	/** Get a bounded seconds budget from a profile. */
	public static function seconds_for( $profile, $stage, $fallback, $min = 1, $max = 20 ) {
		$profile = is_array( $profile ) ? $profile : array();
		$seconds = isset( $profile['stageBudgetSeconds'][ $stage ] ) ? absint( $profile['stageBudgetSeconds'][ $stage ] ) : absint( $fallback );
		return max( absint( $min ), min( absint( $max ), max( 1, $seconds ) ) );
	}

	/** Convert backlog size to a bounded pressure signal. */
	private static function pressure_permille( $count ) {
		$count = absint( $count );
		if ( $count <= 0 ) {
			return 0;
		}
		if ( $count <= 5 ) {
			return 180;
		}
		if ( $count <= 20 ) {
			return 360;
		}
		if ( $count <= 100 ) {
			return 600;
		}
		if ( $count <= 500 ) {
			return 820;
		}
		return 1000;
	}
}
