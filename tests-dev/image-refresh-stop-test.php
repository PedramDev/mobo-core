<?php
/**
 * Standalone tests for the image refresh automation controls:
 * - B3: Stop/Pause really halts the workflow and permanent deletion needs an
 *   explicit approval bound to the current generation;
 * - B25: a completed cycle clears its generation, so later scans do not
 *   re-trigger full source re-downloads.
 *
 * Run: php tests-dev/image-refresh-stop-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_settings'] = array();
$GLOBALS['t_options']  = array();
$GLOBALS['t_calls']    = array( 'lock' => 0, 'repair' => 0, 'retry_failed' => 0 );
$GLOBALS['t_on_lock']  = null;

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_generate_uuid4() { static $n = 0; $n++; return sprintf( '00000000-0000-4000-8000-%012d', $n ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }

class Mobo_Core_Settings {
	public static function get( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['t_settings'] ) ? $GLOBALS['t_settings'][ $k ] : $d; }
	public static function update( $k, $v, $a = false ) { $GLOBALS['t_settings'][ $k ] = $v; return true; }
	public static function delete( $k ) { unset( $GLOBALS['t_settings'][ $k ] ); return true; }
	public static function enabled( $k, $d = '0' ) { return '1' === (string) self::get( $k, $d ); }
	public static function get_int( $k, $d, $min, $max ) { return max( $min, min( $max, (int) self::get( $k, $d ) ) ); }
}
class Mobo_Core_Lock {
	public static function acquire( $name, $ttl = 30 ) { $GLOBALS['t_calls']['lock']++; if ( is_callable( $GLOBALS['t_on_lock'] ) ) { call_user_func( $GLOBALS['t_on_lock'] ); } return 'tok'; }
	public static function release( $name, $token ) { return true; }
	public static function is_locked( $name ) { return false; }
}
class Mobo_Core_Product_Sync { public static function is_repair_completed() { $GLOBALS['t_calls']['repair']++; return true; } }
class Mobo_Core_Orphan_Image_Cleanup {
	const INCOMPLETE_COLLISION_RESULT_OPTION = 'collision_result';
	public function reset( $only = false ) { return 0; }
	public function get_status() { return array( 'failed' => 0 ); }
}
class Mobo_Core_Image_Refresh_Queue {
	public static function table_exists() { return true; }
	public function retry_failed() { $GLOBALS['t_calls']['retry_failed']++; return 0; }
	public function get_status() { return array( 'failed' => 0 ); }
	public function reset( $x = false ) { return 0; }
}

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-image-refresh-automation.php';
require $base . 'class-mobo-core-image-refresh-service.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) { global $failures, $checks; $checks++; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; if ( ! $cond ) { $failures++; } }
function call_private( $object, $method, $args = array() ) { $r = new ReflectionMethod( $object, $method ); $r->setAccessible( true ); return $r->invokeArgs( $object, $args ); }

$A = 'Mobo_Core_Image_Refresh_Automation';
$a = new Mobo_Core_Image_Refresh_Automation();

/* ---- start without approval: nothing destructive allowed ---- */
$r = $a->start( false );
$gen = Mobo_Core_Settings::get( $A::OPTION_GENERATION_ID );
check( ! empty( $r['success'] ) && 'started' === $r['status'], 'start() starts a generation' );
check( '' !== (string) $gen && '1' === Mobo_Core_Settings::get( $A::OPTION_ENABLED ), 'generation id created and workflow enabled' );
check( false === $A::destructive_cleanup_allowed(), 'without explicit approval no permanent deletion is allowed' );
check( false !== strpos( $r['message'], 'حذف نمی‌شود' ), 'start message states that nothing is deleted' );

$wait = call_private( $a, 'waiting_delete_approval', array( 7, 'delete-old', 12 ) );
check( 'waiting-delete-approval' === $wait['status'] && 'delete-old' === $wait['waitingApproval'] && false === $wait['progressed'] && false === $wait['needsContinuation'], 'destructive stage waits (no progress, no continuation) instead of deleting' );

/* ---- approval is explicit and generation-bound ---- */
$r = $a->approve_delete_old();
check( ! empty( $r['success'] ) && true === $A::destructive_cleanup_allowed(), 'explicit approval enables deletion for the current generation' );
Mobo_Core_Settings::update( $A::OPTION_GENERATION_ID, 'another-generation' );
check( false === $A::destructive_cleanup_allowed(), 'approval does not carry over to another generation' );
Mobo_Core_Settings::update( $A::OPTION_GENERATION_ID, $gen );
check( true === $A::destructive_cleanup_allowed(), 'approval valid again for its own generation' );
check( ! empty( $a->approve_delete_orphans()['success'] ), 'orphan approval uses the same explicit approval' );

/* ---- Stop/Pause really stops ---- */
$r = $a->pause( 'test' );
check( 'paused' === $r['status'] && $A::is_paused( true ), 'pause() sets the durable Stop flag' );
check( '0' === Mobo_Core_Settings::get( $A::OPTION_ENABLED ), 'pause() disables the workflow' );
check( false === $A::destructive_cleanup_allowed(), 'pause() revokes delete approval' );
check( '0' === Mobo_Core_Settings::get( 'mobo_core_image_refresh_delete_old' ) && '0' === get_option( 'mobo_core_orphan_image_cleanup_enabled' ), 'pause() returns destructive switches to fail-closed' );
check( false !== strpos( $r['message'], 'متوقف شد' ), 'pause() message is honest (stopped)' );
$locks_before = $GLOBALS['t_calls']['lock'];
$tick = $a->run_tick( 'test' );
check( 'paused' === $tick['status'] && false === $tick['progressed'], 'run_tick() refuses to work while paused' );
check( $locks_before === $GLOBALS['t_calls']['lock'], 'paused tick does not even acquire worker locks' );
check( true === $A::stop_requested(), 'stop_requested() visible to workers between units of work' );
Mobo_Core_Settings::update( $A::OPTION_ENABLED, '0' );
call_private( $a, 'pause_for_error', array( 'boom', 3, 'automation-exception' ) );
check( '0' === Mobo_Core_Settings::get( $A::OPTION_ENABLED ), 'an error in an in-flight tick cannot re-enable a stopped workflow' );
check( false === $a->approve_delete_old()['success'], 'approval is refused while stopped' );
check( 'paused' === $a->arm_delete_old_autodrain()['status'], 'Stage 7 auto-drain cannot be armed while stopped' );

/* ---- Stop pressed while a tick waits for the lock ---- */
$a->start( false );
check( false === $A::is_paused( true ) && '1' === Mobo_Core_Settings::get( $A::OPTION_ENABLED ), 'Resume (start) clears the Stop flag and keeps progress' );
check( $gen === Mobo_Core_Settings::get( $A::OPTION_GENERATION_ID ), 'Resume keeps the same generation (no re-download of finished work)' );
check( false === $A::destructive_cleanup_allowed(), 'Resume does not inherit the revoked approval' );
$repair_calls = $GLOBALS['t_calls']['repair'];
$GLOBALS['t_on_lock'] = function () { Mobo_Core_Settings::update( Mobo_Core_Image_Refresh_Automation::OPTION_PAUSED, '1' ); };
$tick = $a->run_tick( 'test' );
$GLOBALS['t_on_lock'] = null;
check( 'paused' === $tick['status'] && $repair_calls === $GLOBALS['t_calls']['repair'], 'Stop pressed while waiting for the lock: no stage runs' );
Mobo_Core_Settings::update( $A::OPTION_PAUSED, '0' );

/* ---- start(true) approves in the same explicit request ---- */
$a->pause();
$a->start( true );
check( true === $A::destructive_cleanup_allowed(), 'start with explicit approval checkbox allows deletion for this generation' );

/* ---- B25: completion clears the generation ---- */
check( true === Mobo_Core_Image_Refresh_Service::force_source_reimport_enabled(), 'during a cycle the full source re-import is active' );
$done = call_private( $a, 'complete' );
check( 'completed' === $done['status'], 'complete() reports completed' );
check( '' === (string) Mobo_Core_Settings::get( $A::OPTION_GENERATION_ID, '' ), 'B25: generation id cleared on completion' );
check( false === Mobo_Core_Image_Refresh_Service::force_source_reimport_enabled(), 'B25: later scans no longer force re-downloads of healthy WebP' );
check( false === $A::destructive_cleanup_allowed(), 'approval ends with the cycle' );
check( '0' === Mobo_Core_Settings::get( $A::OPTION_ENABLED ), 'workflow disabled after completion' );
$r = $a->start( false );
$gen2 = Mobo_Core_Settings::get( $A::OPTION_GENERATION_ID );
check( '' !== (string) $gen2 && $gen2 !== $gen, 'a new cycle after completion gets a new generation' );

echo PHP_EOL . ( $failures ? "FAILED {$failures} of {$checks}" : "OK {$checks} checks" ) . PHP_EOL;
exit( $failures ? 1 : 0 );
