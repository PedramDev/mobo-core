<?php
/**
 * Cross-request failure isolation for runner stages.
 *
 * Circuit state is derived from the bounded runtime diagnostics option and is
 * persisted only as part of that same once-per-request diagnostics write.
 * Critical webhook/order lanes degrade instead of being fully skipped.
 *
 * PHP 7.4 compatible.
 *
 * @package MoboCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Circuit_Breaker {

	const BASE_COOLDOWN_SECONDS = 60;
	const MAX_COOLDOWN_SECONDS  = 900;

	/** Build circuit state for the current request. */
	public static function build_profile() {
		$diagnostics = get_option( 'mobo_core_runtime_diagnostics', array() );
		$diagnostics = is_array( $diagnostics ) ? $diagnostics : array();
		$stages      = isset( $diagnostics['stages'] ) && is_array( $diagnostics['stages'] ) ? $diagnostics['stages'] : array();
		$previous    = isset( $diagnostics['circuitBreakers'] ) && is_array( $diagnostics['circuitBreakers'] ) ? $diagnostics['circuitBreakers'] : array();
		$previous_stages = isset( $previous['stages'] ) && is_array( $previous['stages'] ) ? $previous['stages'] : array();
		$now = time();

		$tracked = array(
			'webhookQueue', 'orderSubmissions', 'parentFinalize', 'imageQueue',
			'imageRefreshQueue', 'productSync', 'repriceQueue',
			'recategorizeQueue', 'cacheWarmup', 'maintenance',
		);
		$result = array(
			'generatedAt' => $now,
			'openCount'   => 0,
			'halfOpenCount' => 0,
			'stages'      => array(),
		);

		foreach ( $tracked as $stage ) {
			$metric = isset( $stages[ $stage ] ) && is_array( $stages[ $stage ] ) ? $stages[ $stage ] : array();
			$old    = isset( $previous_stages[ $stage ] ) && is_array( $previous_stages[ $stage ] ) ? $previous_stages[ $stage ] : array();
			$failure_streak = absint( isset( $metric['failureStreak'] ) ? $metric['failureStreak'] : 0 );
			$fail_permille  = absint( isset( $metric['ewmaFailPermille'] ) ? $metric['ewmaFailPermille'] : ( isset( $metric['recentFailPermille'] ) ? $metric['recentFailPermille'] : 0 ) );
			$processed      = absint( isset( $metric['processed'] ) ? $metric['processed'] : 0 );
			$last_failure   = absint( isset( $metric['lastFailureAt'] ) ? $metric['lastFailureAt'] : 0 );
			$old_state      = isset( $old['state'] ) ? sanitize_key( (string) $old['state'] ) : 'closed';
			$open_count     = absint( isset( $old['openCount'] ) ? $old['openCount'] : 0 );
			$open_until     = absint( isset( $old['openUntil'] ) ? $old['openUntil'] : 0 );
			$critical       = in_array( $stage, array( 'webhookQueue', 'orderSubmissions' ), true );

			$trip = $failure_streak >= 3 || ( $processed >= 3 && $fail_permille >= 600 );
			$state = 'closed';
			$reason = 'healthy';

			if ( in_array( $old_state, array( 'open', 'degraded' ), true ) && $open_until > $now ) {
				$state  = $critical ? 'degraded' : 'open';
				$reason = 'cooldown-active';
			} elseif ( in_array( $old_state, array( 'open', 'degraded' ), true ) && $open_until > 0 && $open_until <= $now ) {
				$state  = 'half-open';
				$reason = 'probe-after-cooldown';
			} elseif ( $trip ) {
				$open_count++;
				$cooldown = min( self::MAX_COOLDOWN_SECONDS, self::BASE_COOLDOWN_SECONDS * (int) pow( 2, min( 4, max( 0, $open_count - 1 ) ) ) );
				$open_until = $now + $cooldown;
				$state  = $critical ? 'degraded' : 'open';
				$reason = $failure_streak >= 3 ? 'failure-streak' : 'high-failure-rate';
			} elseif ( 'half-open' === $old_state && $failure_streak > 0 && $last_failure > 0 ) {
				$open_count++;
				$cooldown = min( self::MAX_COOLDOWN_SECONDS, self::BASE_COOLDOWN_SECONDS * (int) pow( 2, min( 4, max( 0, $open_count - 1 ) ) ) );
				$open_until = $now + $cooldown;
				$state  = $critical ? 'degraded' : 'open';
				$reason = 'half-open-probe-failed';
			} else {
				$open_until = 0;
				if ( 0 === $failure_streak && $fail_permille < 100 ) {
					$open_count = 0;
				}
			}

			if ( 'open' === $state || 'degraded' === $state ) {
				$result['openCount']++;
			} elseif ( 'half-open' === $state ) {
				$result['halfOpenCount']++;
			}

			$result['stages'][ $stage ] = array(
				'state'              => $state,
				'reason'             => $reason,
				'critical'           => $critical,
				'openCount'          => $open_count,
				'openUntil'          => $open_until,
				'failureStreak'      => $failure_streak,
				'failurePermille'    => $fail_permille,
				'lastFailureAt'      => $last_failure,
			);
		}

		return $result;
	}

	/** Whether a non-critical stage must be skipped in this request. */
	public static function should_skip( $profile, $stage ) {
		$profile = is_array( $profile ) ? $profile : array();
		$item = isset( $profile['stages'][ $stage ] ) && is_array( $profile['stages'][ $stage ] ) ? $profile['stages'][ $stage ] : array();
		return 'open' === ( isset( $item['state'] ) ? sanitize_key( (string) $item['state'] ) : 'closed' );
	}

	/** Whether the stage should run as a single conservative probe. */
	public static function is_probe( $profile, $stage ) {
		$profile = is_array( $profile ) ? $profile : array();
		$item = isset( $profile['stages'][ $stage ] ) && is_array( $profile['stages'][ $stage ] ) ? $profile['stages'][ $stage ] : array();
		return 'half-open' === ( isset( $item['state'] ) ? sanitize_key( (string) $item['state'] ) : 'closed' );
	}

	/** Critical lanes in degraded state keep running with reduced capacity. */
	public static function is_degraded( $profile, $stage ) {
		$profile = is_array( $profile ) ? $profile : array();
		$item = isset( $profile['stages'][ $stage ] ) && is_array( $profile['stages'][ $stage ] ) ? $profile['stages'][ $stage ] : array();
		return 'degraded' === ( isset( $item['state'] ) ? sanitize_key( (string) $item['state'] ) : 'closed' );
	}
}
