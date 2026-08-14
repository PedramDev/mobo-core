<?php
/**
 * Weighted fair scheduler for bounded background stages.
 *
 * Foreground lanes are never displaced. Background capacity is selected from
 * backlog/budget weights with a starvation boost based on the bounded runtime
 * diagnostics history.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Fair_Scheduler {

	/** Build a per-round allow/deferral decision. */
	public static function decide( $round_number, $active_stages, $budget_profile, $circuit_profile, $webhook_pressure ) {
		$round_number    = max( 1, absint( $round_number ) );
		$active_stages   = is_array( $active_stages ) ? $active_stages : array();
		$budget_profile  = is_array( $budget_profile ) ? $budget_profile : array();
		$circuit_profile = is_array( $circuit_profile ) ? $circuit_profile : array();
		$webhook_pressure = (bool) $webhook_pressure;

		$diagnostics = get_option( 'mobo_core_runtime_diagnostics', array() );
		$diagnostics = is_array( $diagnostics ) ? $diagnostics : array();
		$scheduler_stats = isset( $diagnostics['schedulerStages'] ) && is_array( $diagnostics['schedulerStages'] ) ? $diagnostics['schedulerStages'] : array();
		$weights = isset( $budget_profile['weights'] ) && is_array( $budget_profile['weights'] ) ? $budget_profile['weights'] : array();
		$pressure = isset( $budget_profile['pressurePermille'] ) && is_array( $budget_profile['pressurePermille'] ) ? $budget_profile['pressurePermille'] : array();

		$background = array( 'parentFinalize', 'imageQueue', 'imageRefreshQueue', 'repriceQueue', 'recategorizeQueue', 'cacheWarmup', 'reconciliation', 'maintenance' );
		$scores = array();
		$now = time();
		foreach ( $background as $stage ) {
			if ( empty( $active_stages[ $stage ] ) || Mobo_Core_Circuit_Breaker::should_skip( $circuit_profile, $stage ) ) {
				continue;
			}
			$stat = isset( $scheduler_stats[ $stage ] ) && is_array( $scheduler_stats[ $stage ] ) ? $scheduler_stats[ $stage ] : array();
			$last = absint( isset( $stat['lastSelectedAt'] ) ? $stat['lastSelectedAt'] : 0 );
			$age  = $last > 0 ? max( 0, $now - $last ) : 0;
			$score = absint( isset( $weights[ $stage ] ) ? $weights[ $stage ] : 20 );
			$score += (int) floor( absint( isset( $pressure[ $stage ] ) ? $pressure[ $stage ] : 0 ) / 4 );
			if ( $webhook_pressure && 'parentFinalize' === $stage ) {
				/* Converge touched variable parents before lower-value side effects. */
				$score += 300;
			}

			/* Ten minutes without a slot becomes a hard starvation escape. */
			if ( $age >= 600 ) {
				$score += 1000 + min( 1000, (int) floor( ( $age - 600 ) / 10 ) );
			}
			if ( Mobo_Core_Circuit_Breaker::is_probe( $circuit_profile, $stage ) ) {
				$score += 2000;
			}
			/* Stable tie rotation prevents one equal-score lane always winning. */
			$score += abs( crc32( $stage . ':' . $round_number ) ) % 17;
			$scores[ $stage ] = $score;
		}

		arsort( $scores, SORT_NUMERIC );
		$capacity = $webhook_pressure ? 1 : 3;
		$selected = array_slice( array_keys( $scores ), 0, $capacity );
		$allow = array();
		$deferred = array();
		foreach ( $background as $stage ) {
			$allow[ $stage ] = in_array( $stage, $selected, true );
			if ( ! empty( $active_stages[ $stage ] ) && ! $allow[ $stage ] ) {
				$deferred[] = $stage;
			}
		}

		return array(
			'webhookPressure'  => $webhook_pressure,
			'backgroundCapacity' => $capacity,
			'selectedStages'   => $selected,
			'deferredStages'   => $deferred,
			'allow'            => $allow,
			'scores'           => $scores,
			'round'            => $round_number,
		);
	}
}
