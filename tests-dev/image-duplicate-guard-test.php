<?php
/**
 * Standalone tests for the "images downloaded forever / name-N.webp" guards:
 * - H7: re-enqueue keeps the retry budget; a durable per-image ledger caps
 *   fresh reimports (= new attachments) and makes repeated readiness failures
 *   terminal;
 * - M10: the subsize policy also restricts WordPress' missing-subsize check.
 *
 * Run: php tests-dev/image-duplicate-guard-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_options'] = array();
$GLOBALS['t_meta']    = array();
$GLOBALS['t_writes']  = 0;
function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; $GLOBALS['t_writes']++; return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ? $GLOBALS['t_meta'][ $id ][ $key ] : ''; }
function apply_filters( $tag, $value ) { return $value; }
function wp_get_registered_image_subsizes() {
	return array( 'thumbnail' => array(), 'medium' => array(), 'large' => array(), 'woocommerce_thumbnail' => array(), 'woocommerce_single' => array(), 'woocommerce_gallery_thumbnail' => array(), 'theme_big' => array() );
}

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-image-reimport-ledger.php';
require $base . 'class-mobo-core-image-size-policy.php';
require $base . 'class-mobo-core-image-queue.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) { global $failures, $checks; $checks++; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; if ( ! $cond ) { $failures++; } }

$L = 'Mobo_Core_Image_Reimport_Ledger';
$Q = 'Mobo_Core_Image_Queue';

/* ---- H7: re-enqueue decision ---- */
check( 'keep' === $Q::requeue_decision( 'pending', false, false, 0 ), 'same source, pending row: retry budget/backoff kept' );
check( 'detach' === $Q::requeue_decision( 'pending', false, false, 55 ), 'same source, pending with incompatible attachment: detach only' );
check( 'reset' === $Q::requeue_decision( 'pending', true, false, 0 ), 'changed source: fresh budget' );
check( 'keep' === $Q::requeue_decision( 'done', false, true, 55 ), 'done + compatible: untouched' );
check( 'reset' === $Q::requeue_decision( 'done', false, false, 55 ), 'done but attachment vanished: re-download' );
check( 'reset' === $Q::requeue_decision( 'failed', false, false, 0, false ), 'failed (non-readiness, e.g. product recreated): revived' );
check( 'keep' === $Q::requeue_decision( 'failed', false, false, 0, true ), 'terminal readiness failure is NOT revived by re-enqueue' );
check( 'reset' === $Q::requeue_decision( 'failed', true, false, 0, true ), 'terminal readiness failure revived only by a new source file' );
check( 'reset' === $Q::requeue_decision( 'attaching', false, false, 9 ), 'attaching + incompatible keeps historical reset' );

/* Simulate the historical loop: 20 syncs re-enqueue a failing image. */
$try = 0; $status = 'pending';
for ( $sync = 0; $sync < 20; $sync++ ) {
	if ( 'reset' === $Q::requeue_decision( $status, false, false, 0 ) ) { $try = 0; }
	$try++; // one failed attempt per sync
}
check( 20 === $try, 'try_count survives 20 re-enqueues (was reset to 0 on every sync)' );

/* ---- H7: durable ledger ---- */
$k1 = $L::key( 1, 'GUID-A', 'https://cdn1.example.com/m/a.webp?sig=1' );
$k2 = $L::key( 1, 'guid-a', 'https://cdn2.example.com/m/a.webp?sig=2' );
$k3 = $L::key( 1, 'guid-a', 'https://cdn2.example.com/m/a-v2.webp' );
$k4 = $L::key( 2, 'guid-a', 'https://cdn2.example.com/m/a.webp' );
check( $k1 === $k2, 'ledger key ignores host/query/case (same image file)' );
check( $k1 !== $k3, 'a new source file gets a new ledger entry' );
check( $k1 !== $k4, 'ledger is Connection-scoped' );
check( true === $L::may_fresh_reimport( $L::get( $k1 ) ), 'first fresh reimport allowed' );
$L::record_fresh_reimport( $k1 );
check( false === $L::may_fresh_reimport( $L::get( $k1 ) ), 'second fresh reimport refused (max one extra attachment per image)' );
$L::reset_cache();
check( false === $L::may_fresh_reimport( $L::get( $k1 ) ), 'cap survives a new request (durable option, not queue try_count)' );
check( false === $L::is_terminal( array( 'r' => 0, 'f' => 2 ), 2 ), 'early readiness failures are retried' );
check( true === $L::is_terminal( array( 'r' => 1, 'f' => 3 ), 4 ), 'failure after the bounded reimport => terminal' );
check( false === $L::is_terminal( array( 'r' => 1, 'f' => 3 ), 1 ), 'queue reset to try 1 alone is not terminal...' );
check( true === $L::is_terminal( array( 'r' => 1, 'f' => 6 ), 1 ), '...but accumulated failures across resets are terminal' );
$e = array();
for ( $i = 0; $i < 6; $i++ ) { $e = $L::record_readiness_failure( $k3 ); }
check( 6 === $e['f'] && $L::is_terminal( $e, 1 ), 'readiness failures accumulate per image regardless of try_count resets' );
check( false !== strpos( 'Permanent: ' . $L::TERMINAL_MARKER . ' after ...', $L::TERMINAL_MARKER ), 'terminal marker is embedded in a Permanent message' );
$L::forget( $k3 );
check( 0 === $L::get( $k3 )['f'], 'forget() clears an identity' );
$now  = 1800000000;
$map  = array( 'old' => array( 'r' => 1, 'f' => 1, 't' => $now - $L::TTL_SECONDS - 10 ), 'new' => array( 'r' => 1, 'f' => 1, 't' => $now - 10 ), 'bad' => 'x' );
$pr   = $L::prune( $map, $now );
check( array( 'new' ) === array_keys( $pr ), 'expired and malformed entries pruned' );
$big = array();
for ( $i = 0; $i < $L::MAX_ENTRIES + 25; $i++ ) { $big[ 'k' . $i ] = array( 'r' => 0, 'f' => 1, 't' => $now - $i ); }
$pr = $L::prune( $big, $now );
check( $L::MAX_ENTRIES === count( $pr ) && isset( $pr['k0'] ) && ! isset( $pr[ 'k' . ( $L::MAX_ENTRIES + 24 ) ] ), 'ledger bounded; oldest entries dropped first' );

/* ---- M10: missing-subsize check follows the policy ---- */
$S = 'Mobo_Core_Image_Size_Policy';
$registered = array_keys( wp_get_registered_image_subsizes() );
$missing    = array( 'medium' => array(), 'large' => array(), 'theme_big' => array(), 'woocommerce_single' => array() );
$allowed    = $S::parse( $S::DEFAULT_SIZES );
check( array( 'woocommerce_single' ) === array_keys( $S::restrict_missing( $missing, $allowed, $registered ) ), 'only allowed sizes can be reported missing' );
check( array() === $S::restrict_missing( array( 'medium' => array(), 'large' => array() ), $allowed, $registered ), 'skipped sizes are not "missing" => no regeneration' );
check( $missing === $S::restrict_missing( $missing, null, $registered ), "policy 'all' leaves WordPress behaviour unchanged" );
check( $missing === $S::restrict_missing( $missing, array( 'not_registered' ), $registered ), 'misconfiguration never hides all sizes' );
$GLOBALS['t_meta'][ 501 ] = array( 'image_guid' => 'g-501' );
$GLOBALS['t_meta'][ 502 ] = array();
$GLOBALS['t_meta'][ 503 ] = array( 'image_guid' => 'g-503', '_mobo_shared_media_image_id' => 'x' );
check( array( 'woocommerce_single' ) === array_keys( $S::filter_missing_subsizes( $missing, array(), 501 ) ), 'filter applies to Mobo attachments' );
check( $missing === $S::filter_missing_subsizes( $missing, array(), 502 ), 'merchant attachments keep all WordPress sizes' );
check( $missing === $S::filter_missing_subsizes( $missing, array(), 503 ), 'Shared Media attachments untouched' );
$S::begin_sideload();
check( array( 'woocommerce_single' ) === array_keys( $S::filter_missing_subsizes( $missing, array(), 0 ) ), 'filter applies during a Mobo sideload' );
$S::end_sideload();

echo PHP_EOL . ( $failures ? "FAILED {$failures} of {$checks}" : "OK {$checks} checks" ) . PHP_EOL;
exit( $failures ? 1 : 0 );
