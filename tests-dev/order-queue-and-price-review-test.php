<?php
/**
 * Standalone developer test (no WordPress, no network) for:
 *  - Mobo_Core_Work_Queue_Store::first_due_rows_checked() claim ordering
 *    (head-of-line blocking fix), executed against an in-memory SQLite shim;
 *  - Mobo_Core_Order_Price_Review pure logic (backoff, decimal/unit handling,
 *    Source cart price extraction, per-line comparison, fingerprint).
 *
 * Run: php tests-dev/order-queue-and-price-review-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$plugin = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';

/* ---------------- minimal WordPress function shims ---------------- */
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function maybe_serialize( $d ) { return ( is_array( $d ) || is_object( $d ) ) ? serialize( $d ) : $d; }
function maybe_unserialize( $d ) {
	if ( is_string( $d ) && preg_match( '/^(a|O|s|i|b|d|N):/', $d ) ) { $r = @unserialize( $d ); return false === $r && 'b:0;' !== $d ? $d : $r; }
	return $d;
}

final class Mobo_Core_Connection_Context {
	public static $id = 1;
	public static function get_effective_connection_id() { return self::$id; }
}

/** Tiny $wpdb on SQLite, enough for the queue-store statements under test. */
final class Fake_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $options = 'wp_options';
	private $pdo;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->pdo->sqliteCreateFunction( 'UTC_TIMESTAMP', function () { return gmdate( 'Y-m-d H:i:s', $GLOBALS['fake_now'] ); }, 0 );
		$this->pdo->exec( "CREATE TABLE wp_mobo_work_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, queue_type TEXT, dedupe_key TEXT, dedupe_hash TEXT, object_id INTEGER, payload TEXT, payload_hash TEXT, queued_at_us INTEGER DEFAULT 0, available_at TEXT NULL, claim_token TEXT DEFAULT '', claim_expires_at TEXT NULL, attempt_count INTEGER DEFAULT 0, last_error TEXT DEFAULT '', created_at TEXT, updated_at TEXT, UNIQUE(connection_id,queue_type,dedupe_hash))" );
	}
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		$pdo = $this->pdo;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args, $pdo ) {
			$v = $args[ $i++ ];
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return $pdo->quote( null === $v ? '' : (string) $v );
		}, $query );
	}
	private function translate( $sql ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", trim( $sql ), $m ) ) {
			return "SELECT name FROM sqlite_master WHERE type='table' AND name='" . stripslashes( $m[1] ) . "'";
		}
		if ( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE id=id' ) ) {
			return str_replace( 'ON DUPLICATE KEY UPDATE id=id', 'ON CONFLICT(connection_id,queue_type,dedupe_hash) DO NOTHING', $sql );
		}
		if ( preg_match( '/ON DUPLICATE KEY UPDATE (.*)$/s', $sql, $m ) ) {
			$set = preg_replace( '/VALUES\((\w+)\)/', 'excluded.$1', $m[1] );
			return str_replace( $m[0], 'ON CONFLICT(connection_id,queue_type,dedupe_hash) DO UPDATE SET ' . $set, $sql );
		}
		return $sql;
	}
	private function run( $sql ) {
		$this->last_error = '';
		$stmt = $this->pdo->query( $this->translate( $sql ) );
		if ( false === $stmt ) { $e = $this->pdo->errorInfo(); $this->last_error = (string) $e[2]; }
		return $stmt;
	}
	public function get_results( $sql, $mode = null ) { $s = $this->run( $sql ); return $s ? $s->fetchAll( PDO::FETCH_ASSOC ) : null; }
	public function get_row( $sql, $mode = null ) { $s = $this->run( $sql ); if ( ! $s ) { return null; } $r = $s->fetch( PDO::FETCH_ASSOC ); return false === $r ? null : $r; }
	public function get_var( $sql ) { $s = $this->run( $sql ); if ( ! $s ) { return null; } $r = $s->fetch( PDO::FETCH_NUM ); return false === $r ? null : $r[0]; }
	public function query( $sql ) { $s = $this->run( $sql ); return $s ? $s->rowCount() : false; }
	public function exec_raw( $sql ) { return $this->pdo->exec( $sql ); }
	public function delete( $table, $where, $formats = null ) {
		$parts = array();
		foreach ( $where as $col => $val ) { $parts[] = $col . '=' . $this->pdo->quote( (string) $val ); }
		return $this->query( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $parts ) );
	}
}

$GLOBALS['fake_now'] = time();
$GLOBALS['wpdb']     = new Fake_WPDB();

require $plugin . 'class-mobo-core-work-queue-store.php';
require $plugin . 'class-mobo-core-order-price-review.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	if ( $cond ) { echo "  ok   {$label}\n"; return; }
	$failures++;
	echo "  FAIL {$label}\n";
}
function keys_of( $page ) { return 'ok' === $page['status'] ? array_map( 'strval', array_keys( $page['rows'] ) ) : array( 'ERROR' ); }

$Q  = 'order_submission';
$PR = 'Mobo_Core_Order_Price_Review';

echo "Queue claim ordering (head-of-line blocking)\n";
check( Mobo_Core_Work_Queue_Store::is_available(), 'store available on shim' );
$t = time();
check( Mobo_Core_Work_Queue_Store::put( $Q, '101', array( 'orderId' => 101, 'queueToken' => 'a', 'queuedAt' => $t ) ), 'enqueue order 101' );
check( Mobo_Core_Work_Queue_Store::put( $Q, '102', array( 'orderId' => 102, 'queueToken' => 'b', 'queuedAt' => $t ) ), 'enqueue order 102' );
check( Mobo_Core_Work_Queue_Store::put( $Q, '103', array( 'orderId' => 103, 'queueToken' => 'c', 'queuedAt' => $t ) ), 'enqueue order 103' );
check( array( '101', '102', '103' ) === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 ) ), 'all due rows in id order' );

/* Order 101 defers (location_resolution_pending): same row re-put with backoff. */
$delay = $PR::defer_backoff_seconds( 1 );
check( Mobo_Core_Work_Queue_Store::put( $Q, '101', array( 'orderId' => 101, 'queueToken' => 'a2', 'queuedAt' => $t, 'nextAttemptAt' => $t + $delay ) ), 'defer order 101 (same row, new revision)' );
check( array( '101', '102' ) === keys_of( Mobo_Core_Work_Queue_Store::first_rows_checked( $Q, 2 ) ),'legacy FIFO read still returns the deferred head (old behavior kept for other callers)' );
check( array( '102', '103' ) === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 ) ), 'due read skips backed-off order 101' );
check( array( '102' ) === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 1 ) ), 'limit 1 claims order 102, not the blocked head' );
$counts = Mobo_Core_Work_Queue_Store::pending_due_counts_strict( $Q );
check( is_array( $counts ) && 3 === $counts['pending'] && 2 === $counts['due'], 'pending=3 due=2 while 101 waits' );

/* Later orders drain; 101 still waits. */
Mobo_Core_Work_Queue_Store::delete_key( $Q, '102' );
Mobo_Core_Work_Queue_Store::delete_key( $Q, '103' );
check( array() === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 ) ), 'nothing due while 101 is in backoff' );
$counts = Mobo_Core_Work_Queue_Store::pending_due_counts_strict( $Q );
check( 1 === $counts['pending'] && 0 === $counts['due'], 'deferred row stays visible as pending (not lost)' );

/* Time passes: 101 becomes due again and is retried. New order 104 arrives (NULL available_at). */
$GLOBALS['fake_now'] = $t + $delay + 1;
check( array( '101' ) === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 ) ), 'order 101 retried once available_at is reached' );
Mobo_Core_Work_Queue_Store::put( $Q, '104', array( 'orderId' => 104, 'queueToken' => 'd', 'queuedAt' => $t + 100 ) );
check( array( '104', '101' ) === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 ) ), 'ORDER BY available_at,id: fresh (NULL) row first, then due deferred row' );

/* Other Connections are isolated. */
Mobo_Core_Connection_Context::$id = 2;
check( array() === keys_of( Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 ) ), 'connection 2 sees none of connection 1 rows' );
Mobo_Core_Connection_Context::$id = 1;

/* Corrupt payload still fails closed. */
$GLOBALS['wpdb']->exec_raw( "UPDATE wp_mobo_work_queue SET payload='x' WHERE dedupe_key='104'" );
check( 'error' === Mobo_Core_Work_Queue_Store::first_due_rows_checked( $Q, 16 )['status'], 'corrupt due row fails closed (status=error)' );

echo "Deferral backoff\n";
$expected_backoff = array( 1 => 60, 2 => 120, 3 => 240, 4 => 480, 5 => 960, 6 => 1920, 7 => 3600, 8 => 3600, 50 => 3600, 0 => 60, -3 => 60 );
foreach ( $expected_backoff as $n => $sec ) {
	check( $sec === $PR::defer_backoff_seconds( $n ), "backoff({$n}) = {$sec}s" );
}

echo "Decimal and unit handling\n";
check( '1250000' === $PR::canonical_decimal( '1250000.000000' ), 'snapshot "1250000.000000" -> 1250000' );
check( '1250000' === $PR::canonical_decimal( 1250000 ), 'int 1250000' );
check( '1250000' === $PR::canonical_decimal( 1250000.0 ), 'float 1250000.0' );
check( '129.99' === $PR::canonical_decimal( 129.99 ), 'float 129.99' );
check( '0' === $PR::canonical_decimal( '000' ), '"000" -> 0' );
check( null === $PR::canonical_decimal( '1,250,000' ), 'grouped string rejected' );
check( null === $PR::canonical_decimal( '-5' ), 'negative rejected' );
check( null === $PR::canonical_decimal( true ), 'bool rejected' );
check( null === $PR::canonical_decimal( '1e5' ), 'exponent rejected' );
check( '12500000' === $PR::convert_unit( '1250000', 'IRT', 'IRR' ), 'IRT->IRR x10' );
check( '125000' === $PR::convert_unit( '1250000', 'IRR', 'IRT' ), 'IRR->IRT /10' );
check( '12345.6' === $PR::convert_unit( '123456', 'IRR', 'Toman' ), 'IRR->Toman keeps exact fraction' );
check( '1250000' === $PR::convert_unit( '1250000', 'toman', 'IRT' ), 'Toman alias == IRT (identity)' );
check( '1250000' === $PR::convert_unit( '1250000', 'تومان', 'IRT' ), 'Persian "تومان" alias' );
check( null === $PR::convert_unit( '10', 'USD', 'IRT' ), 'unsupported pair -> null' );
check( 'IRR' === $PR::extract_snapshot_unit( array( 'cart' => array( 'currency' => 'rial', 'items' => array() ) ) ), 'declared cart unit rial -> IRR' );
check( '' === $PR::extract_snapshot_unit( array( 'cart' => array( 'items' => array() ) ) ), 'no declared unit -> contract unit is used' );
check( 'UNKNOWN_DECLARED' === $PR::extract_snapshot_unit( array( 'cart' => array( 'currency' => '$$', 'items' => array() ) ) ), 'unparseable declared unit is not silently ignored' );

echo "Source cart price extraction\n";
$row = array( 'id' => 9, 'quantity' => 2, 'price' => 999, 'product' => array( 'variant' => array( 'id' => 77, 'price' => 125000, 'compare_price' => 150000 ) ) );
$x = $PR::extract_remote_unit_price( $row );
check( '125000' === $x['price'] && 'product.variant.price' === $x['field'], 'variant.price preferred (same field as mobo_api_price sync)' );
$x = $PR::extract_remote_unit_price( array( 'unit_price' => '125000.00', 'product' => array( 'variant' => array( 'id' => 77 ) ) ) );
check( '125000' === $x['price'] && 'unit_price' === $x['field'], 'fallback unit_price' );
$x = $PR::extract_remote_unit_price( array( 'product' => array( 'variant' => array( 'id' => 77, 'price' => 'n/a' ) ) ) );
check( null === $x['price'] && 'product.variant.price' === $x['field'], 'malformed price stays unknown (fail closed)' );
$x = $PR::extract_remote_unit_price( array( 'product' => array( 'variant' => array( 'id' => 77 ) ) ) );
check( null === $x['price'] && '' === $x['field'], 'missing price -> unknown' );

echo "Per-line comparison\n";
$expected = array(
	'77' => array( 'name' => 'Shirt', 'expected' => '125000' ),
	'78' => array( 'name' => 'Hat', 'expected' => '50000.000000' ),
);
check( array() === $PR::compare_lines( $expected, array( '77' => array( '125000' ), '78' => array( '50000' ) ) ), 'equal per-unit prices -> no mismatch (quantity/shipping never compared)' );
$m = $PR::compare_lines( $expected, array( '77' => array( '130000' ), '78' => array( '50000' ) ) );
check( 1 === count( $m ) && '77' === $m[0]['variantId'] && 'price_differs' === $m[0]['reason'] && '125000' === $m[0]['expected'] && '130000' === $m[0]['remote'], 'one line differs -> exactly that line reported' );
$m = $PR::compare_lines( $expected, array( '77' => array( '120000' ), '78' => array( '50000' ) ) );
check( 1 === count( $m ), 'a LOWER Source price is a difference too (no tolerance)' );
$m = $PR::compare_lines( array( '77' => array( 'name' => 'Shirt', 'expected' => null ) ), array( '77' => array( '125000' ) ) );
check( 1 === count( $m ) && 'expected_unknown' === $m[0]['reason'], 'unknown expected price -> mismatch (conservative)' );
$m = $PR::compare_lines( $expected, array( '77' => array( null ), '78' => array( '50000' ) ) );
check( 1 === count( $m ) && 'remote_unknown' === $m[0]['reason'], 'unreadable Source price -> mismatch' );
$m = $PR::compare_lines( $expected, array( '77' => array( '125000', '126000' ), '78' => array( '50000' ) ) );
check( 1 === count( $m ) && '126000' === $m[0]['remote'], 'duplicate Source rows of one variant are each checked' );
check( array() === $PR::compare_lines( $expected, array( '78' => array( '50000' ) ) ), 'missing remote line is left to the existing cart comparison' );
/* Unit conversion end-to-end: Source cart declares IRR, contract/expected in IRT. */
$remote_irt = $PR::convert_unit( '1250000', 'IRR', 'IRT' );
check( array() === $PR::compare_lines( array( '77' => array( 'name' => 'Shirt', 'expected' => '125000' ) ), array( '77' => array( $remote_irt ) ) ), '1,250,000 IRR equals 125,000 IRT after exact conversion' );
check( $PR::looks_like_unit_confusion( '125000', '1250000' ), 'x10 difference flagged as possible Rial/Toman confusion' );
check( ! $PR::looks_like_unit_confusion( '125000', '130000' ), 'ordinary difference not flagged as unit confusion' );

echo "Review fingerprint and texts\n";
$a = array( array( 'variantId' => '77', 'remote' => '130000', 'expected' => '125000' ), array( 'variantId' => '78', 'remote' => '1', 'expected' => '2' ) );
$b = array( array( 'variantId' => '78', 'remote' => '1', 'expected' => '9' ), array( 'variantId' => '77', 'remote' => '130000', 'expected' => '1' ) );
$c = array( array( 'variantId' => '77', 'remote' => '131000', 'expected' => '125000' ), array( 'variantId' => '78', 'remote' => '1', 'expected' => '2' ) );
check( $PR::mismatch_fingerprint( $a ) === $PR::mismatch_fingerprint( $b ), 'fingerprint ignores order and expected side' );
check( $PR::mismatch_fingerprint( $a ) !== $PR::mismatch_fingerprint( $c ), 'fingerprint changes when the Source price changes again' );
check( '1,250,000' === $PR::format_amount( '1250000' ) && '999' === $PR::format_amount( 999 ) && '1,000.5' === $PR::format_amount( '1000.50' ), 'amount grouping' );
$lines = $PR::describe_mismatches( array( array( 'variantId' => '77', 'name' => 'Shirt', 'expected' => '125000', 'remote' => '1250000' ), array( 'variantId' => '78', 'name' => 'Hat', 'expected' => null, 'remote' => '5' ) ), 'IRT' );
check( 2 === count( $lines ) && false !== strpos( $lines[0], '125,000 تومان' ) && false !== strpos( $lines[0], '1,250,000 تومان' ) && false !== strpos( $lines[0], 'ریال/تومان' ), 'note line: name, expected, Source price, unit hint' );
check( false !== strpos( $lines[1], 'نامشخص' ), 'unknown expected price is labelled' );

echo "\n{$checks} checks, {$failures} failure(s) on PHP " . PHP_VERSION . "\n";
exit( $failures > 0 ? 1 : 0 );
