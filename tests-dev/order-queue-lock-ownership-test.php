<?php
/**
 * Standalone test: the order-queue GET_LOCK() is owned by one MySQL session.
 * After a silent wpdb reconnect ("server has gone away" + auto-reconnect) the
 * lock is gone (or already held by another worker). Ownership must be re-proved
 * with IS_USED_LOCK() = CONNECTION_ID() before committing queue state; when it
 * was lost the worker stops safely and reports failure.
 *
 * Run: php tests-dev/order-queue-lock-ownership-test.php
 * No WordPress, no network, no database. PHP 7.4 compatible.
 */

require __DIR__ . '/stubs.php';

function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

/** Scripted MySQL advisory-lock semantics for one PHP request. */
final class Lock_WPDB {
	public $prefix = 'wp_';
	public $cid = 41;            // current session id (a reconnect changes it)
	public $holders = array();   // lock name => session id
	public $grant_cid = true;    // GET_LOCK row includes CONNECTION_ID()
	public $log = array();
	public function prepare( $q, ...$a ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'"; }, $q );
	}
	private function name( $q ) { return preg_match( "/'([^']+)'/", $q, $m ) ? $m[1] : ''; }
	public function reconnect( $other_holder = null ) {
		/* The server dropped the old session: its locks are released. */
		foreach ( $this->holders as $n => $c ) { if ( $c === $this->cid ) { unset( $this->holders[ $n ] ); } }
		$this->cid++;
		if ( null !== $other_holder ) { foreach ( array_keys( $this->last ) as $n ) { $this->holders[ $n ] = $other_holder; } }
	}
	public $last = array(); // locks requested by the last GET_LOCK
	public function get_row( $q, $mode = null ) {
		$this->log[] = $q;
		if ( false !== strpos( $q, 'GET_LOCK(' ) ) {
			$n = $this->name( $q );
			$this->last = array( $n => true );
			$free = ! isset( $this->holders[ $n ] ) || $this->holders[ $n ] === $this->cid;
			if ( $free ) { $this->holders[ $n ] = $this->cid; }
			return array( 'acquired' => $free ? '1' : '0', 'cid' => $this->grant_cid ? (string) $this->cid : null );
		}
		return null;
	}
	public function get_var( $q ) {
		$this->log[] = $q;
		$n = $this->name( $q );
		if ( false !== strpos( $q, 'IS_USED_LOCK(' ) ) { return isset( $this->holders[ $n ] ) ? (string) $this->holders[ $n ] : null; }
		if ( false !== strpos( $q, 'RELEASE_LOCK(' ) ) {
			if ( isset( $this->holders[ $n ] ) && $this->holders[ $n ] === $this->cid ) { unset( $this->holders[ $n ] ); return '1'; }
			return '0';
		}
		return null;
	}
}
$GLOBALS['wpdb'] = new Lock_WPDB();
final class Mobo_Core_Connection_Context {
	public static function get_effective_connection_id() { return 1; }
}

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-checkout-validator.php';

$class     = new ReflectionClass( 'Mobo_Core_Checkout_Validator' );
$validator = $class->newInstanceWithoutConstructor();
$with      = $class->getMethod( 'with_order_queue_state_lock' );
$with->setAccessible( true );
$owned     = $class->getMethod( 'order_queue_lock_still_owned' );
$owned->setAccessible( true );
$remove    = $class->getMethod( 'remove_order_queue_item_if_token_matches' );
$remove->setAccessible( true );
$db = $GLOBALS['wpdb'];

echo "Normal: lock owned for the whole mutation\n";
$wrote = false;
$r = $with->invoke( $validator, function () use ( $validator, $owned, &$wrote ) {
	if ( ! $owned->invoke( $validator ) ) { return false; }
	$wrote = true;
	return true;
} );
mobo_assert( true === $r && $wrote, 'mutation committed under a proven lock' );
mobo_assert( empty( $db->holders ), 'lock released afterwards' );
mobo_assert( false === $owned->invoke( $validator ), 'no ownership claimed outside the guarded section' );

echo "Reconnect before the write: stop safely\n";
$wrote = false;
$r = $with->invoke( $validator, function () use ( $validator, $owned, $db, &$wrote ) {
	$db->reconnect( 99 ); // wait_timeout killed the session; worker 99 took the lock
	if ( ! $owned->invoke( $validator ) ) { return false; }
	$wrote = true;
	return true;
} );
mobo_assert( false === $r && ! $wrote, 'no queue write once IS_USED_LOCK() names another session' );
mobo_assert( 1 === count( $db->holders ) && 99 === reset( $db->holders ), "the other worker's lock is never released by us" );
$db->holders = array();

echo "Reconnect after the write: report failure (not exclusive)\n";
$r = $with->invoke( $validator, function () use ( $db ) {
	$db->reconnect();
	return true;
} );
mobo_assert( false === $r, 'caller falls back to its durable recovery path' );

echo "Unprovable ownership is refused up front\n";
$db->grant_cid = false;
$ran = false;
$r = $with->invoke( $validator, function () use ( &$ran ) { $ran = true; return true; } );
mobo_assert( false === $r && ! $ran && empty( $db->holders ), 'no CONNECTION_ID(): callback never runs, lock released' );
$db->grant_cid = true;

echo "Queue removal (option-backed) under a lost lock\n";
$GLOBALS['mobo_test_options']['mobo_core_mobo_order_submission_queue'] = array( '700' => array( 'orderId' => 700, 'queueToken' => 'tok-700' ) );
$orig_queue = $GLOBALS['mobo_test_options']['mobo_core_mobo_order_submission_queue'];
$db->holders = array();
/* The session dies right before the pre-write ownership check (after the queue
 * was read under the lock); worker 55 takes the lock in the meantime. */
$GLOBALS['t_takeover'] = true;
final class Takeover_WPDB {
	private $inner; public $prefix = 'wp_';
	public function __construct( $inner ) { $this->inner = $inner; }
	public function prepare( $q, ...$a ) { return $this->inner->prepare( $q, ...$a ); }
	public function get_row( $q, $m = null ) { return $this->inner->get_row( $q, $m ); }
	public function get_var( $q ) {
		if ( ! empty( $GLOBALS['t_takeover'] ) && false !== strpos( $q, 'IS_USED_LOCK(' ) ) {
			$GLOBALS['t_takeover'] = false;
			$this->inner->reconnect( 55 );
		}
		return $this->inner->get_var( $q );
	}
}
$GLOBALS['wpdb'] = new Takeover_WPDB( $db );
$r = $remove->invoke( $validator, '700', 'tok-700', '' );
mobo_assert( false === $r, 'removal reports failure' );
mobo_assert( $orig_queue === $GLOBALS['mobo_test_options']['mobo_core_mobo_order_submission_queue'], 'queue state untouched (the other worker owns it now)' );
$GLOBALS['wpdb'] = $db;
$db->holders = array();
$r = $remove->invoke( $validator, '700', 'tok-700', '' );
mobo_assert( true === $r && array() === $GLOBALS['mobo_test_options']['mobo_core_mobo_order_submission_queue'], 'with a stable session the same removal commits' );

echo "Wiring (static)\n";
$src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-checkout-validator.php' );
mobo_assert( false !== strpos( $src, "SELECT GET_LOCK(%s, %d) AS acquired, CONNECTION_ID() AS cid" ), 'owner session recorded atomically with the acquisition' );
mobo_assert( false !== strpos( $src, "SELECT IS_USED_LOCK(%s)" ), 'ownership re-proved with IS_USED_LOCK()' );
mobo_assert( substr_count( $src, '$this->order_queue_lock_still_owned()' ) >= 6, 'every queue write path re-checks ownership before committing' );

mobo_finish();
