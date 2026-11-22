<?php
/**
 * Standalone (no WordPress) regression test for Mobo_Core_Store_Currency_Guard
 * driven through its real entry points (option hook, lazy detection, dismiss).
 *
 * Found by the legacy Deep Test Suite (mutation 1137, 2026-11-22): after the
 * store currency was changed and then switched back (IRT -> IRR -> IRT) the
 * "prices are 10x wrong, run Reprice" event stayed pending forever, and
 * dismissing it recorded IRR as the priced unit, which immediately raised a
 * reverse IRR -> IRT false alarm.
 *
 * Run: php tests-dev/store-currency-guard-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_options'] = array();
$GLOBALS['t_mails']   = 0;
$GLOBALS['t_catalog'] = true;

function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function sanitize_email( $v ) { return (string) $v; }
function home_url( $p = '/' ) { return 'http://shop.test/'; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['t_mails']++; return true; }

class Mobo_Core_Money_Policy {
	const UNIT_IRT = 'IRT';
	public static function normalize_unit( $value ) {
		$unit = strtoupper( trim( (string) $value ) );
		if ( in_array( $unit, array( 'TOMAN', 'TOMANS' ), true ) ) { return self::UNIT_IRT; }
		return preg_match( '/^[A-Z0-9_]{2,16}$/D', $unit ) ? $unit : '';
	}
	public static function store_unit() { return self::normalize_unit( get_option( 'woocommerce_currency', '' ) ); }
}
class Mobo_Core_Money_Target_Settings { const OPTION = 'mobo_core_money_target_preference'; const FOLLOW_WOO = 'store'; }
class Mobo_Core_Storage_Schema {
	public static function is_ready() { return true; }
	public static function table_name( $t ) { return 'wp_mobo_' . $t; }
}
class T_Wpdb {
	public $last_error = '';
	public function get_var( $sql ) { return $GLOBALS['t_catalog'] ? '1' : null; }
}
$GLOBALS['wpdb'] = new T_Wpdb();

require __DIR__ . '/../wp-content/plugins/mobo-core/includes/class-mobo-core-store-currency-guard.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	if ( $cond ) { echo "PASS {$label}\n"; } else { $failures++; echo "FAIL {$label}\n"; }
}
/** Simulate WooCommerce saving a new currency (fires the update_option hook). */
function set_currency( $new ) {
	$old = get_option( 'woocommerce_currency', '' );
	update_option( 'woocommerce_currency', $new );
	Mobo_Core_Store_Currency_Guard::on_currency_option_updated( $old, $new );
}
function reset_state( $currency ) {
	$GLOBALS['t_options'] = array( 'woocommerce_currency' => $currency, 'admin_email' => 'admin@shop.test', Mobo_Core_Store_Currency_Guard::OPTION_PRICED_UNIT => $currency );
	$GLOBALS['t_catalog'] = true;
}
$G = 'Mobo_Core_Store_Currency_Guard';
$resolve = new ReflectionMethod( $G, 'resolve' );
$resolve->setAccessible( true );

// 1. Plain change still raises the alarm (no regression).
reset_state( 'IRT' );
set_currency( 'IRR' );
$e = $G::current_event();
check( is_array( $e ) && 'IRT' === $e['from'] && 'IRR' === $e['to'] && 10.0 === (float) $e['factor'], 'IRT -> IRR records a x10 change event' );
check( $G::implicit_follow_store_allowed(), 'pending change lets pricing follow the Woo currency' );

// 2. Revert through the real option hook clears the event.
set_currency( 'IRT' );
check( null === $G::current_event(), 'switching back IRR -> IRT clears the pending event' );
check( 'IRT' === get_option( $G::OPTION_PRICED_UNIT ), 'priced unit stays IRT after the revert' );
check( ! $G::implicit_follow_store_allowed(), 'no implicit conversion after the revert' );

// 3. A stale event (left by an older build / direct DB revert) is cleared by lazy detection.
reset_state( 'IRT' );
update_option( $G::OPTION_EVENT, array( 'from' => 'IRT', 'to' => 'IRR', 'factor' => 10, 'supported' => true, 'detectedAt' => 1, 'emailedAt' => 1, 'repricedConnections' => array() ) );
$checked = new ReflectionProperty( $G, 'checked' );
$checked->setAccessible( true );
$checked->setValue( null, false );
$G::maybe_detect();
check( null === $G::current_event(), 'maybe_detect clears a stale event when store unit equals the priced unit' );

// 4. Dismissing a stale event never records the stale target as the priced unit.
reset_state( 'IRT' );
$stale = array( 'from' => 'IRT', 'to' => 'IRR', 'factor' => 10, 'supported' => true, 'detectedAt' => 1, 'emailedAt' => 1, 'repricedConnections' => array() );
update_option( $G::OPTION_EVENT, $stale );
$resolve->invoke( null, $stale );
check( 'IRT' === get_option( $G::OPTION_PRICED_UNIT ), 'resolve() records the current store unit, not the stale event target' );
$checked->setValue( null, false );
$G::maybe_detect();
check( null === $G::current_event(), 'no reverse IRR -> IRT false alarm after dismissing' );

// 5. Two hops keep the original priced unit; back to it resolves.
reset_state( 'IRT' );
set_currency( 'IRR' );
set_currency( 'USD' );
$e = $G::current_event();
check( is_array( $e ) && 'IRT' === $e['from'] && 'USD' === $e['to'] && empty( $e['supported'] ), 'second hop keeps original priced unit and marks unsupported pair' );
set_currency( 'IRT' );
check( null === $G::current_event(), 'returning to the original priced unit after two hops resolves' );

// 6. Normal resolution after Reprice in the new unit still records the new unit.
reset_state( 'IRT' );
set_currency( 'IRR' );
$resolve->invoke( null, $G::current_event() );
check( 'IRR' === get_option( $G::OPTION_PRICED_UNIT ), 'Reprice/dismiss after a real change records IRR as priced unit' );
check( 'store' === get_option( Mobo_Core_Money_Target_Settings::OPTION ), 'supported change persists follow-WooCommerce preference' );
set_currency( 'IRT' );
$e = $G::current_event();
check( is_array( $e ) && 'IRR' === $e['from'] && 'IRT' === $e['to'] && 0.1 === (float) $e['factor'], 'changing back after Reprice is a genuine new IRR -> IRT change' );

// 7. Without a synced catalog nothing can be wrong: no event, and a stale one is dropped.
reset_state( 'IRT' );
update_option( $G::OPTION_EVENT, $stale );
$GLOBALS['t_catalog'] = false;
set_currency( 'IRR' );
check( null === $G::current_event(), 'no catalog: currency change drops any event' );
check( 'IRR' === get_option( $G::OPTION_PRICED_UNIT ), 'no catalog: priced unit follows the store' );

// 8. Pure evaluate_change contract.
check( null === $G::evaluate_change( 'IRT', 'IRT', null, 1 ), 'no event + same unit is not a change' );
$r = $G::evaluate_change( 'IRT', 'IRT', $stale, 1 );
check( is_array( $r ) && ! empty( $r['resolved'] ), 'existing event + store back on priced unit resolves' );
check( null === $G::evaluate_change( 'IRT', 'IRR', $stale, 2 ), 'same pending change is not re-recorded' );

echo "{$checks} checks, {$failures} failure(s)\n";
exit( $failures > 0 ? 1 : 0 );
