<?php
/**
 * O6 (forced-update staging 2026-11-22): "image queue got zero passes while an
 * admin full reprice ran". The staging cron responses show that every one of
 * those rounds had the imageQueue circuit breaker OPEN (legacy rows pointing at
 * the production Backend were blocked by the staging egress guard, O4), and every
 * round with a closed circuit gave the image queue passes next to the reprice.
 * This test pins the scheduler guarantees that make that true:
 *  - an active reprice/recategorize never takes the image slot (capacity 3,
 *    admin jobs reserve at most two);
 *  - under webhook pressure (capacity 1) the image queue still wins a slot
 *    within the 10-minute hard starvation escape;
 *  - only an open image circuit removes the stage from the competition.
 *
 * Run: php tests-dev/fair-scheduler-image-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
final class Mobo_Core_Settings {
	public static $diag = array();
	public static function get( $k, $d = null ) { return 'mobo_core_runtime_diagnostics' === $k ? self::$diag : $d; }
}
$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-circuit-breaker.php';
require $inc . 'class-mobo-core-fair-scheduler.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$F = 'Mobo_Core_Fair_Scheduler';
/* Adaptive budget profile observed on staging during the reprice. */
$budget = array(
	'weights'          => array( 'parentFinalize' => 100, 'imageQueue' => 88, 'imageRefreshQueue' => 55, 'repriceQueue' => 90, 'recategorizeQueue' => 45, 'cacheWarmup' => 25, 'maintenance' => 18 ),
	'pressurePermille' => array( 'imageQueue' => 1000, 'repriceQueue' => 1000, 'maintenance' => 180 ),
);
$closed = array( 'stages' => array() );
$open   = array( 'stages' => array( 'imageQueue' => array( 'state' => 'open' ) ) );
$probe  = array( 'stages' => array( 'imageQueue' => array( 'state' => 'half-open' ) ) );

$all_rounds_have_image = true;
for ( $round = 1; $round <= 40; $round++ ) {
	$d = $F::decide( $round, array( 'imageQueue' => true, 'repriceQueue' => true, 'maintenance' => 1 === $round ), $budget, $closed, false );
	$all_rounds_have_image = $all_rounds_have_image && ! empty( $d['allow']['imageQueue'] ) && ! empty( $d['allow']['repriceQueue'] );
}
check( $all_rounds_have_image, 'reprice running, image circuit closed: image queue selected in every round (40/40)' );

$all = array( 'parentFinalize' => true, 'imageQueue' => true, 'imageRefreshQueue' => true, 'repriceQueue' => true, 'recategorizeQueue' => true, 'cacheWarmup' => true );
$d = $F::decide( 3, $all, $budget, $closed, false );
check( ! empty( $d['allow']['repriceQueue'] ) && ! empty( $d['allow']['recategorizeQueue'] ) && 3 === count( $d['selectedStages'] ), 'both admin jobs reserve two of three slots' );
check( 1 === count( array_diff( $d['selectedStages'], array( 'repriceQueue', 'recategorizeQueue' ) ) ), '  the third slot stays weighted/fair' );

Mobo_Core_Settings::$diag = array( 'schedulerStages' => array( 'imageQueue' => array( 'lastSelectedAt' => time() - 601 ), 'parentFinalize' => array( 'lastSelectedAt' => time() - 5 ), 'repriceQueue' => array( 'lastSelectedAt' => time() - 5 ) ) );
$d = $F::decide( 5, array( 'parentFinalize' => true, 'imageQueue' => true, 'repriceQueue' => true ), $budget, $closed, true );
check( 1 === $d['backgroundCapacity'] && array( 'imageQueue' ) === $d['selectedStages'], 'webhook pressure (capacity 1): image queue starved for 10 min wins the slot (hard escape)' );
Mobo_Core_Settings::$diag = array();

$d = $F::decide( 11, array( 'imageQueue' => true, 'repriceQueue' => true ), $budget, $open, false );
check( empty( $d['allow']['imageQueue'] ) && array( 'imageQueue' ) === $d['deferredStages'] && ! isset( $d['scores']['imageQueue'] ), 'open image circuit: stage deferred and unscored (the staging O6 rounds)' );
$d = $F::decide( 12, array( 'imageQueue' => true, 'repriceQueue' => true ), $budget, $probe, false );
check( ! empty( $d['allow']['imageQueue'] ) && $d['scores']['imageQueue'] > 2000, 'half-open image circuit: probe gets a slot next to the reprice' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
