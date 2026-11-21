<?php
/**
 * Standalone test for the 10.34.78 adaptive tuner math: runtime overrides are
 * excluded from the learning fingerprint, proven-fast hosts may reach 4x with a
 * 120 s cooldown and 100% steps, other hosts stay at 2x / 50% / 300 s, errors and
 * tail-latency pressure back off fast, and no stage exceeds the request budget.
 * Also covers adaptive page-size scaling.
 *
 * Run: php tests-dev/adaptive-tuner-test.php
 * PHP 7.4+ compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function absint( $v ) { return abs( (int) $v ); }

final class Mobo_Core_Settings {
	public static $options   = array();
	public static $overrides = array();
	public static function get( $key, $fallback = null ) {
		if ( array_key_exists( $key, self::$overrides ) ) { return self::$overrides[ $key ]; }
		return array_key_exists( $key, self::$options ) ? self::$options[ $key ] : $fallback;
	}
	public static function get_int( $key, $default, $min, $max ) {
		$v = absint( self::get( $key, $default ) );
		return max( $min, min( $max, $v ) );
	}
	public static function get_int_configured( $key, $default, $min, $max ) {
		$v = absint( array_key_exists( $key, self::$options ) ? self::$options[ $key ] : $default );
		return max( $min, min( $max, $v ) );
	}
	public static function enabled( $key, $default = '1' ) { return '1' === (string) self::get( $key, $default ); }
}

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-adaptive-tuner.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$NOW = 2000000000;

/** Fast, confident, clean image-queue stage sample. */
function fast_stage( $ms, $now ) {
	return array( 'processed' => 200, 'totalMs' => 200 * $ms, 'ewmaMsPerItem' => $ms, 'runs' => 40, 'lastAt' => $now - 30, 'failed' => 0, 'ewmaFailPermille' => 0, 'latencyTrendPermille' => 0, 'p95Ms' => $ms * 2 );
}
function diag( $stages, $previous, $runs = 50 ) {
	return array( 'stages' => $stages, 'runner' => array( 'runs' => $runs, 'stops' => array(), 'lastStopAt' => array() ), 'adaptiveTuning' => $previous );
}
function profile_for( $stages, $previous, $now, $config = array() ) {
	return Mobo_Core_Adaptive_Tuner::build_profile( array_merge( array( 'effectiveTimeBudgetSeconds' => 25, 'productStepsPerRound' => 3, 'diagnosticsSnapshot' => diag( $stages, $previous ), 'nowOverride' => $now ), $config ) );
}

$T = 'Mobo_Core_Adaptive_Tuner';
Mobo_Core_Settings::$options = array( 'mobo_core_images_per_run' => 3 );

// --- 1. Proven-fast host ramps 3 -> 6 -> 12(capped 10) with 100% steps and 120 s cooldown.
$prev = array();
$p1 = profile_for( array( 'imageQueue' => fast_stage( 50, $NOW ) ), $prev, $NOW );
check( true === $p1['decisions']['imageQueue']['provenFast'], 'fast clean confident sample is proven fast' );
check( 6 === $p1['learnedLimits']['imageQueue'], 'first upshift doubles (100% step) on a proven-fast host: 3 -> 6' );
$p2 = profile_for( array( 'imageQueue' => fast_stage( 50, $NOW ) ), $p1, $NOW + 60 );
check( 6 === $p2['learnedLimits']['imageQueue'], '60 s later: fast cooldown (120 s) still holds' );
$p3 = profile_for( array( 'imageQueue' => fast_stage( 50, $NOW ) ), $p1, $NOW + 130 );
check( 10 === $p3['learnedLimits']['imageQueue'], 'after 120 s it ramps again, capped by the stage hard max (10)' );

// --- 4x ceiling on a stage with headroom (reprice base 20 -> max 80).
Mobo_Core_Settings::$options['mobo_core_reprice_batch_size'] = 20;
$prev = array( 'baseline' => array( 'repriceQueue' => 20 ), 'learnedLimits' => array( 'repriceQueue' => 60 ), 'stability' => array( 'lastChangedAt' => array( 'repriceQueue' => $NOW - 1000 ) ) );
$p = profile_for( array( 'repriceQueue' => fast_stage( 5, $NOW ) ), $prev, $NOW );
check( 80 === $p['decisions']['repriceQueue']['maxLimit'], 'proven-fast ceiling is 4x baseline (80)' );
check( 80 === $p['learnedLimits']['repriceQueue'], 'proven-fast host reaches 4x' );

// --- Non-proven host (few runs) stays at <= 2x and 50% steps, 300 s cooldown.
$slow_conf = fast_stage( 5, $NOW );
$slow_conf['runs'] = 4;
$slow_conf['processed'] = 20;
$prev = array( 'baseline' => array( 'repriceQueue' => 20 ), 'learnedLimits' => array( 'repriceQueue' => 20 ), 'stability' => array( 'lastChangedAt' => array() ) );
$p = profile_for( array( 'repriceQueue' => $slow_conf ), $prev, $NOW );
check( false === $p['decisions']['repriceQueue']['provenFast'], 'low-history sample is not proven fast' );
check( 40 === $p['decisions']['repriceQueue']['maxLimit'], 'non-proven ceiling stays 2x' );
check( $p['learnedLimits']['repriceQueue'] <= 30, 'non-proven upshift limited to +50% per step' );
$prev2 = array( 'baseline' => array( 'repriceQueue' => 20 ), 'learnedLimits' => array( 'repriceQueue' => 30 ), 'stability' => array( 'lastChangedAt' => array( 'repriceQueue' => $NOW - 200 ) ) );
$p = profile_for( array( 'repriceQueue' => $slow_conf ), $prev2, $NOW );
check( 30 === $p['learnedLimits']['repriceQueue'], 'non-proven host honours the 300 s cooldown' );

// --- 2. Runtime overrides do not discard learning; they only cap this invocation.
Mobo_Core_Settings::$options['mobo_core_images_per_run'] = 3;
Mobo_Core_Settings::$overrides = array( 'mobo_core_images_per_run' => 1 );
$learned = array( 'baseline' => array( 'imageQueue' => 3, 'productStepsPerRound' => 3 ), 'learnedLimits' => array( 'imageQueue' => 8, 'productStepsPerRound' => 9 ), 'stability' => array( 'lastChangedAt' => array( 'imageQueue' => $NOW - 50, 'productStepsPerRound' => $NOW - 50 ) ) );
$hb = profile_for( array( 'imageQueue' => fast_stage( 50, $NOW ) ), $learned, $NOW, array( 'productStepsPerRound' => 1 ) );
check( 3 === $hb['baseline']['imageQueue'], 'baseline fingerprint uses the configured setting, not the heartbeat override' );
check( 3 === $hb['baseline']['productStepsPerRound'], 'baseline productSteps uses the configured setting, not the runtime config' );
check( 1 === $hb['limits']['imageQueue'] && 1 === $hb['runtimeCaps']['imageQueue'], 'heartbeat override caps the applied limit' );
check( 8 === $hb['learnedLimits']['imageQueue'], 'learned image limit preserved through the heartbeat run' );
check( 1 === $hb['limits']['productStepsPerRound'], 'heartbeat productStepsPerRound=1 stays a hard cap' );
Mobo_Core_Settings::$overrides = array();
$after = profile_for( array( 'imageQueue' => fast_stage( 50, $NOW ) ), $hb, $NOW + 10 );
check( 8 === $after['limits']['imageQueue'], 'next cron run resumes from the learned anchor (no reset to baseline)' );

// --- 3. Fast backoff on errors / tail-latency pressure, bypassing cooldown.
$err = fast_stage( 50, $NOW );
$err['failed'] = 5;
$err['ewmaFailPermille'] = 250;
$prev = array( 'baseline' => array( 'repriceQueue' => 20 ), 'learnedLimits' => array( 'repriceQueue' => 80 ), 'stability' => array( 'lastChangedAt' => array( 'repriceQueue' => $NOW - 5 ) ) );
$p = profile_for( array( 'repriceQueue' => $err ), $prev, $NOW );
check( $p['learnedLimits']['repriceQueue'] <= 20, 'errors drop an elevated anchor back to <= baseline immediately (despite cooldown)' );
$tail = fast_stage( 5, $NOW );
$tail['p95Ms'] = 600; /* 600 ms x anchor 80 = 48 s > 60% of 25 s */
$p = profile_for( array( 'repriceQueue' => $tail ), $prev, $NOW );
check( $p['learnedLimits']['repriceQueue'] <= 40, 'tail-latency pressure halves the anchor at once' );
check( 'fast-backoff-time-pressure' === $p['decisions']['repriceQueue']['reason'], 'decision explains the fast backoff' );

// --- 4. Never exceed the request time budget.
$heavy = fast_stage( 9000, $NOW );
$prev = array( 'baseline' => array( 'repriceQueue' => 20 ), 'learnedLimits' => array( 'repriceQueue' => 20 ), 'stability' => array() );
$p = profile_for( array( 'repriceQueue' => $heavy ), $prev, $NOW );
check( $p['limits']['repriceQueue'] * 9000 <= 25000, 'stage limit x ms/item never exceeds the request budget' );

// --- 5. Page-size scaling.
check( 10 === $T::compute_page_size( array(), 10, 1, 20 ), 'unlearned host uses the default page size' );
check( 20 === $T::compute_page_size( array( 'baseline' => array( 'productStepsPerRound' => 3 ), 'learnedLimits' => array( 'productStepsPerRound' => 12 ) ), 10, 1, 20 ), 'fast host scales up, clamped to the ceiling' );
check( 6 === $T::compute_page_size( array( 'mode' => 'conservative', 'baseline' => array( 'productStepsPerRound' => 3 ), 'learnedLimits' => array( 'productStepsPerRound' => 3 ) ), 10, 1, 20 ), 'conservative mode damps page size (0.6x)' );
check( 3 === $T::compute_page_size( array( 'baseline' => array( 'productStepsPerRound' => 4 ), 'learnedLimits' => array( 'productStepsPerRound' => 1 ) ), 10, 1, 20 ), 'slow host scales down (0.25x)' );
check( 5 === $T::compute_page_size( array( 'baseline' => array( 'productStepsPerRound' => 4 ), 'learnedLimits' => array( 'productStepsPerRound' => 1 ) ), 10, 5, 20 ), 'merchant setting acts as the floor' );
check( 50 === $T::compute_page_size( array( 'baseline' => array( 'productStepsPerRound' => 3 ), 'learnedLimits' => array( 'productStepsPerRound' => 6 ) ), 25, 5, 100 ), 'variant page size scales 2x' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
