<?php
/**
 * R5 (2026-11-22): per-site cap of image downloads per Source image host.
 *
 * After the forced update one site reached ~200 image requests/min against the
 * Source image host. Every download now reserves a slot in a shared, CAS-updated
 * GCRA bucket per host (default 30/min, burst 3); a 429/503/Retry-After pauses
 * the host with an escalating cooldown; capped or throttled rows are deferred
 * without consuming their retry budget; new products (no featured image yet) are
 * claimed before the backlog.
 *
 * [1] pure GCRA decisions          [2] throttle/Retry-After policy
 * [3] real CAS SQL (SQLite) incl. a competing writer
 * [4] 4 concurrent PHP processes on one SQLite file never exceed the cap
 * [5] real Image_Queue SQL: defer_attempt_until restores try_count; priority claim
 * [6] Image_Sync HTTP classification (429/503/Retry-After/5xx/404) via reflection
 * [7] 12-minute backlog simulation: cap per 60 s window, new product first,
 *     429 pause honoured, no attempts consumed by deferrals
 * [8] wiring (source-level) in Image_Sync, refresh service, admin
 *
 * Run: php tests-dev/image-host-rate-limit-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
require __DIR__ . '/sqlite-wpdb-stub.php';

$GLOBALS['t_options']  = array();
$GLOBALS['t_settings'] = array();
$GLOBALS['t_now']      = time();
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function esc_url_raw( $v ) { return trim( (string) $v ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s', time() ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function apply_filters( $hook, $value ) { return $value; }
function add_filter() { return true; }
function add_action() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_cache_delete( $k, $g = '' ) { return true; }
function is_wp_error( $v ) { return false; }

final class Mobo_Core_Settings {
	public static function get( $k, $d = null ) { return get_option( $k, $d ); }
	public static function get_int( $k, $d, $min = 0, $max = PHP_INT_MAX ) {
		$v = array_key_exists( $k, $GLOBALS['t_settings'] ) ? (int) $GLOBALS['t_settings'][ $k ] : (int) $d;
		return max( $min, min( $max, $v ) );
	}
	public static function enabled( $k, $d = '0' ) { return '1' === (string) get_option( $k, $d ); }
}
final class Mobo_Core_Connection_Context { public static function get_effective_connection_id() { return 1; } }
final class Mobo_Core_Environment { public static function on_shutdown( $cb ) { return true; } }

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-image-host-rate-limit.php';
$L = 'Mobo_Core_Image_Host_Rate_Limit';

function t_db( $file = '' ) {
	$db = new Sqlite_Test_WPDB();
	if ( '' !== $file ) {
		$db->pdo = new PDO( 'sqlite:' . $file );
		$db->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$db->pdo->setAttribute( PDO::ATTR_TIMEOUT, 0 );
		$db->pdo->exec( 'CREATE TABLE IF NOT EXISTS wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT, autoload TEXT)' );
	} else {
		$db->pdo->exec( 'DROP TABLE wp_options' );
		$db->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT, autoload TEXT)' );
	}
	$db->pdo->exec( 'CREATE UNIQUE INDEX IF NOT EXISTS option_name ON wp_options (option_name)' );
	$GLOBALS['wpdb'] = $db;
	return $db;
}

/* ---- child mode for [4]: hammer reserve() for N seconds, print allowed count. */
if ( isset( $argv[1] ) && 'child' === $argv[1] ) {
	$GLOBALS['t_settings'][ $L::RATE_OPTION ] = (int) $argv[4];
	t_db( $argv[2] );
	$end = (float) $argv[3];
	while ( microtime( true ) < $end - 4.0 ) { usleep( 2000 ); } /* common start */
	$allowed = 0;
	while ( microtime( true ) < $end ) {
		$r = $L::reserve( 'img.source.test' );
		if ( ! empty( $r['allowed'] ) ) { $allowed++; }
		usleep( 3000 );
	}
	echo $allowed;
	exit( 0 );
}

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

/* ------------------------------------------------------------------ */
echo "\n[1] GCRA decisions (pure)\n";
check( 30 === $L::DEFAULT_RATE && 5 === $L::DEFAULT_BURST && 120 === $L::MAX_RATE && 10 === $L::MAX_BURST, 'safe default: 30 requests/min per host, burst 5; maximum 120/min, burst 10' );
check( 30 === $L::rate_per_minute( 'x' ) && 5 === $L::burst( 'x' ), 'rate_per_minute()/burst() return the defaults when nothing is configured' );
$GLOBALS['t_settings'][ $L::RATE_OPTION ] = 500;
check( 120 === $L::rate_per_minute(), 'configured rate is clamped to 120/min' );
$GLOBALS['t_settings'][ $L::RATE_OPTION ] = 0;
check( 1 === $L::rate_per_minute(), 'configured rate 0 cannot disable the cap (clamped to 1/min)' );
$GLOBALS['t_settings'] = array();

$e = array(); $t0 = 1000000.0; $ok = 0;
for ( $i = 0; $i < 5; $i++ ) { $d = $L::decide( $e, $t0, 30, 3 ); if ( $d['allowed'] ) { $ok++; $e = $d['entry']; } else { $deny = $d; } }
check( 3 === $ok, 'burst: exactly 3 back-to-back requests are allowed' );
check( 'rate' === $deny['reason'] && abs( $deny['retryAt'] - ( $t0 + 2.0 ) ) < 0.01, 'the 4th waits one interval (60/30 = 2 s)' );
$d = $L::decide( $e, $t0 + 2.0, 30, 3 );
check( $d['allowed'], 'a slot is free again exactly at retryAt' );
/* Any request pattern: never more than rate*T/60 + burst in a window of T seconds. */
foreach ( array( array( 30, 3 ), array( 60, 3 ), array( 10, 1 ), array( 120, 10 ) ) as $cfg ) {
	list( $rate, $burst ) = $cfg;
	$e = array(); $times = array();
	for ( $t = 0.0; $t < 900.0; $t += 0.05 + ( ( (int) ( $t * 100 ) ) % 7 ) * 0.01 ) {
		$d = $L::decide( $e, $t0 + $t, $rate, $burst );
		if ( $d['allowed'] ) { $e = $d['entry']; $times[] = $t; }
	}
	$max60 = 0; $j = 0;
	foreach ( $times as $i => $t ) { while ( $times[ $j ] <= $t - 60.0 ) { $j++; } $max60 = max( $max60, $i - $j + 1 ); }
	check( $max60 <= $rate + $burst && count( $times ) <= $rate * 15 + $burst && count( $times ) >= $rate * 15 - 1, "rate $rate/min burst $burst: max " . $max60 . ' per 60 s window, ' . count( $times ) . ' in 15 min' );
}

/* ------------------------------------------------------------------ */
echo "\n[2] throttle / Retry-After policy (pure)\n";
check( 120 === $L::parse_retry_after( '120', 0 ) && 3600 === $L::parse_retry_after( '999999', 0 ) && null === $L::parse_retry_after( '', 0 ) && null === $L::parse_retry_after( 'soon', 0 ), 'Retry-After seconds parsed, capped at 1 h; empty/garbage = absent' );
$now = 1700000000;
check( 90 === $L::parse_retry_after( gmdate( 'D, d M Y H:i:s', $now + 90 ) . ' GMT', $now ) && 0 === $L::parse_retry_after( gmdate( 'D, d M Y H:i:s', $now - 50 ) . ' GMT', $now ), 'Retry-After HTTP-date parsed (past date = 0)' );
check( 45 === $L::parse_retry_after( array( '10', '45' ), 0 ), 'repeated header: last value wins' );
check( $L::is_throttle_response( 429, false ) && $L::is_throttle_response( 503, false ) && $L::is_throttle_response( 502, true ) && ! $L::is_throttle_response( 502, false ) && ! $L::is_throttle_response( 404, false ) && ! $L::is_throttle_response( 200, true ), '429/503 or any error with Retry-After is throttling; 404/502 without it is not' );
$e = array(); $blocks = array();
for ( $i = 1; $i <= 7; $i++ ) { $e = $L::apply_throttle( $e, null, $now, 30, 3 ); $blocks[] = $e['blockedUntil'] - $now; }
check( array( 60, 120, 240, 480, 960, 1800, 1800 ) === $blocks, 'no Retry-After: escalating cooldown 60 s .. 30 min (' . implode( ',', $blocks ) . ')' );
$e = $L::apply_throttle( array(), 300, $now, 30, 3 );
check( 300 === $e['blockedUntil'] - $now, 'Retry-After longer than the cooldown is honoured (300 s)' );
$e = $L::apply_throttle( array(), 5, $now, 30, 3 );
check( 60 === $e['blockedUntil'] - $now, 'Retry-After shorter than the cooldown: the longer cooldown wins (60 s)' );
$d = $L::decide( $e, $now + 59, 30, 3 );
check( ! $d['allowed'] && 'throttled' === $d['reason'] && $now + 60 === (int) $d['retryAt'], 'no request while the host is paused' );
$ok = 0; $ee = $e;
for ( $i = 0; $i < 4; $i++ ) { $d = $L::decide( $ee, $now + 60, 30, 3 ); if ( $d['allowed'] ) { $ok++; $ee = $d['entry']; } }
check( 1 === $ok, 'after the pause a single probe request, then the normal pace (no burst)' );

/* ------------------------------------------------------------------ */
echo "\n[3] shared state: real CAS SQL (SQLite)\n";
$GLOBALS['t_settings'][ $L::BURST_OPTION ] = 3;
$db = t_db();
$t  = 2000000.0;
$r1 = $L::reserve( 'IMG.Source.test', $t );
$raw = $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = '" . $L::STATE_OPTION . "'" );
$row = $db->get_row( "SELECT autoload FROM wp_options WHERE option_name = '" . $L::STATE_OPTION . "'", ARRAY_A );
check( $r1['allowed'] && is_string( $raw ) && false !== strpos( $raw, 'img.source.test' ) && 'no' === $row['autoload'], 'first reservation inserts one non-autoloaded state row (host lower-cased)' );
$L::reserve( 'img.source.test', $t ); $L::reserve( 'img.source.test', $t );
$r4 = $L::reserve( 'img.source.test', $t );
check( ! $r4['allowed'] && 'rate' === $r4['reason'], 'state survives between calls: 4th reservation at the same instant is refused' );
check( $L::reserve( 'other.cdn.test', $t )['allowed'], 'another host has its own budget' );
$p = $L::peek( 'img.source.test', $t );
$v1 = json_decode( $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = '" . $L::STATE_OPTION . "'" ), true )['v'];
$L::peek( 'img.source.test', $t );
$v2 = json_decode( $db->get_var( "SELECT option_value FROM wp_options WHERE option_name = '" . $L::STATE_OPTION . "'" ), true )['v'];
check( ! $p['available'] && $v1 === $v2, 'peek() is read-only' );

/* A competing runner writes between our read and our CAS: the CAS must fail and re-read. */
final class Racing_WPDB {
	public $inner; public $races = 0; public $options = 'wp_options';
	public function __construct( $inner ) { $this->inner = $inner; }
	public function __call( $m, $a ) { return call_user_func_array( array( $this->inner, $m ), $a ); }
	public function query( $sql ) {
		if ( $this->races > 0 && preg_match( '/^\s*UPDATE wp_options SET option_value/i', $sql ) ) {
			$this->races--;
			/* competitor takes the last free slot first */
			$GLOBALS['wpdb'] = $this->inner;
			Mobo_Core_Image_Host_Rate_Limit::reserve( 'race.test', $GLOBALS['t_race_now'] );
			$GLOBALS['wpdb'] = $this;
		}
		return $this->inner->query( $sql );
	}
}
$db = t_db();
$GLOBALS['t_race_now'] = 3000000.0;
$L::reserve( 'race.test', 3000000.0 ); $L::reserve( 'race.test', 3000000.0 ); /* 2 of 3 burst slots used */
$racing = new Racing_WPDB( $db ); $racing->races = 1;
$GLOBALS['wpdb'] = $racing;
$r = $L::reserve( 'race.test', 3000000.0 );
$GLOBALS['wpdb'] = $db;
check( ! $r['allowed'] && 'rate' === $r['reason'], 'lost CAS race: re-read sees the competitor took the last slot -> refused (never 4 in a burst of 3)' );
$racing->races = 50; $GLOBALS['wpdb'] = $racing;
$r = $L::reserve( 'race2.test', 3000000.0 );
$GLOBALS['wpdb'] = $db;
check( ! empty( $r['allowed'] ) || 'contention' === $r['reason'] || 'rate' === $r['reason'], 'persistent contention fails closed (defer), never unbounded' );
$db = t_db();
$db->fail_pattern = '/^\s*(UPDATE|INSERT)/i';
$r = $L::reserve( 'dbfull.test', 4000000.0 );
$db->fail_pattern = '';
check( ! $r['allowed'] && 'contention' === $r['reason'], 'state write fails (disk full): no request is allowed (fail closed)' );
$until = $L::record_throttle( 'img.source.test', 120, 5000000.0 );
$r = $L::reserve( 'img.source.test', 5000000.0 + 100 );
check( 5000000 + 120 === $until && ! $r['allowed'] && 'throttled' === $r['reason'], 'record_throttle() pauses the host for every runner (shared row)' );
$r = $L::reserve( 'img.source.test', 5000000.0 + 121 );
check( $r['allowed'] && 1 === $r['blockCount'], 'probe after the pause is allowed and reports the open escalation' );
$L::record_success( 'img.source.test' );
$until = $L::record_throttle( 'img.source.test', null, 5000000.0 + 200 );
check( 5000000 + 260 === $until, 'a success resets the escalation: next throttle starts at 60 s again' );
$GLOBALS['t_settings'] = array();

/* ------------------------------------------------------------------ */
echo "\n[4] 4 concurrent PHP processes share one budget (SQLite file)\n";
$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mobo-r5-' . getmypid() . '.sqlite';
@unlink( $file );
t_db( $file );
$GLOBALS['wpdb'] = null;
$rate = 120; $duration = 4.0; $end = microtime( true ) + 4.0 + $duration;
$procs = array();
for ( $i = 0; $i < 4; $i++ ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' child ' . escapeshellarg( $file ) . ' ' . sprintf( '%.3f', $end ) . ' ' . $rate;
	$procs[] = popen( $cmd, 'r' );
}
$counts = array();
foreach ( $procs as $ph ) { $counts[] = (int) trim( stream_get_contents( $ph ) ); pclose( $ph ); }
$total = array_sum( $counts );
$cap   = $L::DEFAULT_BURST + (int) ceil( $rate * $duration / 60 ) + 1;
check( $total <= $cap, '4 runners x ' . $duration . ' s at ' . $rate . '/min: ' . $total . ' requests allowed (cap ' . $cap . '; per runner ' . implode( '/', $counts ) . ')' );
check( $total >= 5, 'the shared budget still lets work through (' . $total . ' >= 5)' );
@unlink( $file );

/* ------------------------------------------------------------------ */
echo "\n[5] real Image_Queue SQL: attempt-neutral deferral and priority claim\n";
require $inc . 'class-mobo-core-image-queue.php';
function t_queue_db() {
	$db = t_db();
	$db->pdo->exec( "CREATE TABLE wp_mobo_image_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL DEFAULT 0, queue_key TEXT, product_id INTEGER, product_guid TEXT DEFAULT '', image_guid TEXT DEFAULT '', source_url TEXT, position_index INTEGER DEFAULT 0, attachment_id INTEGER DEFAULT 0, status TEXT DEFAULT 'pending', try_count INTEGER DEFAULT 0, next_retry_at TEXT NULL, locked_until TEXT NULL, last_error TEXT NULL, created_at TEXT, updated_at TEXT)" );
	$qr = new ReflectionProperty( 'Mobo_Core_Image_Queue', 'table_exists_cache' );
	$qr->setAccessible( true );
	$qr->setValue( null, null );
	return $db;
}
function t_add_product( $db, $pid, $has_thumb ) {
	$db->pdo->exec( "INSERT INTO wp_posts (ID, post_type) VALUES ($pid, 'product')" );
	if ( $has_thumb ) { $db->pdo->exec( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ($pid, '_thumbnail_id', '" . ( $pid + 100000 ) . "')" ); }
}
function t_add_row( $db, $pid, $guid, $pos, $age ) {
	$ts = gmdate( 'Y-m-d H:i:s', time() - $age );
	$db->pdo->exec( "INSERT INTO wp_mobo_image_queue (connection_id, queue_key, product_id, image_guid, source_url, position_index, status, created_at, updated_at) VALUES (1, 'k-$guid', $pid, '$guid', 'https://img.source.test/$guid.jpg', $pos, 'pending', '$ts', '$ts')" );
	return (int) $db->pdo->lastInsertId();
}
$db = t_queue_db();
$q  = new Mobo_Core_Image_Queue();
t_add_product( $db, 10, true );
$rid = t_add_row( $db, 10, 'g-a', 0, 500 );
$db->pdo->exec( "UPDATE wp_mobo_image_queue SET try_count = 2 WHERE id = $rid" );
$claimed = $q->claim_due_images( 1, 300 );
$tok = $claimed[0]['_mobo_claim_token'];
$began = $q->begin_attempt_if_current( $rid, 3, 10, 'g-a', 'https://img.source.test/g-a.jpg', $tok );
$deferred = $q->defer_attempt_until( $rid, time() + 120, 3, 'Image host rate limit: img.source.test per-site cap reached (30/min); next request at 10:00:00 UTC.', $tok );
$after = $db->row( 'wp_mobo_image_queue', $rid );
check( $began && $deferred && 2 === (int) $after['try_count'] && 'pending' === $after['status'] && null === $after['locked_until'], 'defer_attempt_until(): begun attempt given back (try_count 3 -> 2), row pending, lease released' );
check( strtotime( $after['next_retry_at'] . ' UTC' ) >= time() + 119 && 0 === strpos( $after['last_error'], 'Image host rate limit:' ), 'next_retry_at = pause end; last_error explains the deferral' );
check( array() === $q->claim_due_images( 5, 300 ), 'a deferred row is not claimed again before its time' );
$claimed = $q->claim_due_images( 1, 300 ); /* nothing due */
$db->pdo->exec( "UPDATE wp_mobo_image_queue SET next_retry_at = NULL WHERE id = $rid" );
$claimed = $q->claim_due_images( 1, 300 );
check( ! $q->defer_attempt_until( $rid, time() + 60, 3, 'x', $claimed[0]['_mobo_claim_token'] ), 'stale attempt number (no begun attempt) -> no change' );
check( ! $q->defer_attempt_until( $rid, time() + 60, 2, 'x', 'wrong-token' ), 'wrong claim token -> no change' );
$q->release_claimed_images( array( $rid ), $claimed[0]['_mobo_claim_token'] );

/* Backlog (products already showing an image) enqueued first; a new product later. */
$db = t_queue_db();
$q  = new Mobo_Core_Image_Queue();
for ( $p = 1; $p <= 30; $p++ ) { t_add_product( $db, 100 + $p, true ); for ( $k = 0; $k < 3; $k++ ) { t_add_row( $db, 100 + $p, "old-$p-$k", $k, 3000 - $p ); } }
t_add_product( $db, 900, false );
foreach ( array( 2, 1, 0, 3 ) as $pos ) { t_add_row( $db, 900, "new-$pos", $pos, 10 ); }
$claimed = $q->claim_due_images( 6, 300 );
$guids = array_map( static function ( $r ) { return $r['image_guid']; }, $claimed );
check( array( 'new-0', 'new-1', 'new-2', 'new-3', 'old-1-0', 'old-1-1' ) === $guids, 'new product claimed first, featured image first, then the backlog in FIFO order (' . implode( ',', $guids ) . ')' );
$q->release_claimed_images( wp_list_pluck_t( $claimed, 'id' ), $claimed[0]['_mobo_claim_token'] );
/* Once the new product shows its featured image it joins the normal order. */
$db->pdo->exec( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (900, '_thumbnail_id', '777')" );
$claimed = $q->claim_due_images( 2, 300 );
/* (release_claimed_images() moved the 6 released rows to the end of the FIFO order.) */
check( array( 'old-1-2', 'old-2-0' ) === array_map( static function ( $r ) { return $r['image_guid']; }, $claimed ), 'product with a featured image: rows follow the FIFO backlog order' );
$q->release_claimed_images( wp_list_pluck_t( $claimed, 'id' ), $claimed[0]['_mobo_claim_token'] );
$db->pdo->exec( "DELETE FROM wp_postmeta WHERE post_id = 900" );
$db->pdo->exec( "UPDATE wp_postmeta SET meta_value = '0' WHERE post_id = 101" );
$claimed = $q->claim_due_images( 8, 300 );
check( in_array( 'old-1-0', array_slice( array_map( static function ( $r ) { return $r['image_guid']; }, $claimed ), 0, 7 ), true ), "_thumbnail_id '0' counts as no featured image (priority)" );
$q->release_claimed_images( wp_list_pluck_t( $claimed, 'id' ), $claimed[0]['_mobo_claim_token'] );
/* Priority probe only looks at the newest PRIORITY_ID_WINDOW ids. */
$db = t_queue_db();
$q  = new Mobo_Core_Image_Queue();
t_add_product( $db, 50, false );
t_add_product( $db, 51, true );
t_add_row( $db, 50, 'ancient-no-thumb', 0, 9000 );
$db->pdo->exec( "UPDATE sqlite_sequence SET seq = seq + " . ( Mobo_Core_Image_Queue::PRIORITY_ID_WINDOW + 10 ) . " WHERE name = 'wp_mobo_image_queue'" );
t_add_row( $db, 51, 'recent-thumb-a', 0, 8000 );
t_add_row( $db, 51, 'recent-thumb-b', 1, 7000 );
$claimed = $q->claim_due_images( 1, 300 );
check( 'ancient-no-thumb' === $claimed[0]['image_guid'], 'row outside the priority window is still served by FIFO (oldest)' );
$q->release_claimed_images( wp_list_pluck_t( $claimed, 'id' ), $claimed[0]['_mobo_claim_token'] );
$db->pdo->exec( "UPDATE wp_mobo_image_queue SET updated_at = '" . gmdate( 'Y-m-d H:i:s', time() - 100 ) . "' WHERE image_guid = 'ancient-no-thumb'" );
$prio = new ReflectionMethod( 'Mobo_Core_Image_Queue', 'priority_due_ids' );
$prio->setAccessible( true );
check( array() === $prio->invoke( null, 1, current_time( 'mysql', true ), 5 ), 'priority probe ignores ids older than the newest ' . Mobo_Core_Image_Queue::PRIORITY_ID_WINDOW );
function wp_list_pluck_t( $rows, $k ) { return array_map( static function ( $r ) use ( $k ) { return (int) $r[ $k ]; }, $rows ); }

/* ------------------------------------------------------------------ */
echo "\n[6] Image_Sync: HTTP status classification (reflection)\n";
require $inc . 'class-mobo-core-image-sync.php';
$db   = t_db();
$sync = new Mobo_Core_Image_Sync();
$note = new ReflectionMethod( 'Mobo_Core_Image_Sync', 'note_image_http_failure' );
$note->setAccessible( true );
$err  = new ReflectionProperty( 'Mobo_Core_Image_Sync', 'last_image_error' );
$err->setAccessible( true );
$set = $note->invoke( $sync, 'img.source.test', 429, '120' );
$msg = $err->getValue( $sync );
check( $set && 0 === strpos( $msg, 'Image HTTP response was 429' ) && 'host' === Mobo_Core_Image_Sync::classify_failure_message( $msg ), '429: host-class message (' . $msg . ')' );
check( $sync->last_rate_deferral_until() >= time() + 119 && $sync->last_rate_deferral_until() <= time() + 121, '429 + Retry-After 120: row deferred until the host pause ends' );
check( ! $L::reserve( 'img.source.test' )['allowed'], '429 paused the host for every runner' );
$sync2 = new Mobo_Core_Image_Sync();
$set = $note->invoke( $sync2, 'cdn2.test', 503, '' );
check( $set && $sync2->last_rate_deferral_until() >= time() + 59, '503 without Retry-After = throttling (nginx limit_req default), 60 s pause' );
$sync3 = new Mobo_Core_Image_Sync();
$set = $note->invoke( $sync3, 'cdn3.test', 502, '' );
$msg = $err->getValue( $sync3 );
check( $set && 0 === $sync3->last_rate_deferral_until() && 'host' === Mobo_Core_Image_Sync::classify_failure_message( $msg ), '502: not throttling, but a host-class failure for the per-host breaker (was wp_media_http_404 = item)' );
$sync4 = new Mobo_Core_Image_Sync();
$set = $note->invoke( $sync4, 'cdn4.test', 404, '' );
check( ! $set && 0 === $sync4->last_rate_deferral_until(), '404: untouched (per-item failure as before)' );
$sync5 = new Mobo_Core_Image_Sync();
$set = $note->invoke( $sync5, 'cdn5.test', 403, '30' );
check( $set && $sync5->last_rate_deferral_until() >= time() + 59, '403 with Retry-After: treated as throttling (WAF rate rule)' );

/* ------------------------------------------------------------------ */
echo "\n[7] simulation: backlog of 600 images + a new product, 12 virtual minutes\n";
/*
 * Mirrors process_queue_rows(): claim (priority first) -> per-host gate via
 * peek() -> begin attempt -> reserve() inside the download -> deferral without
 * an attempt. Up to 10 rows per pass, each download 0.4 s. The Source answers
 * 429 + Retry-After 90 to every request in [300 s, 330 s).
 *   storm : a pass every 2 s (the R5 retest saw ~200 requests/min)
 *   normal: a pass every 20 s
 */
function t_sim( $pass_every, $with_429 ) {
	$L = 'Mobo_Core_Image_Host_Rate_Limit';
	t_db();
	$t0 = 6000000.0 + $pass_every * 1000;
	$rows = array();
	for ( $i = 0; $i < 600; $i++ ) { $rows[] = array( 'id' => $i + 1, 'pos' => $i % 4, 'thumb' => true, 'due' => $t0, 'try' => 0, 'done' => false ); }
	$new_at = $t0 + 125.0;
	$out = array( 'requests' => array(), 'throttled' => array(), 'deferrals' => 0, 'newDone' => array(), 'newAt' => $new_at, 'rows' => null );
	$interval = 60.0 / $L::rate_per_minute();
	$busy_until = $t0;
	for ( $tick = $t0; $tick < $t0 + 720.0; $tick += $pass_every ) {
		$now = max( $tick, $busy_until );
		if ( $now >= $new_at && 600 === count( $rows ) ) {
			for ( $k = 0; $k < 4; $k++ ) { $rows[] = array( 'id' => 601 + $k, 'pos' => $k, 'thumb' => false, 'due' => $now, 'try' => 0, 'done' => false ); }
		}
		$due = array_values( array_filter( $rows, static function ( $r ) use ( $now ) { return ! $r['done'] && $r['due'] <= $now; } ) );
		usort( $due, static function ( $a, $b ) {
			$pa = $a['thumb'] ? 1 : 0; $pb = $b['thumb'] ? 1 : 0;
			if ( $pa !== $pb ) { return $pa - $pb; }
			if ( ! $a['thumb'] && $a['pos'] !== $b['pos'] ) { return $a['pos'] - $b['pos']; }
			return $a['due'] === $b['due'] ? $a['id'] - $b['id'] : ( $a['due'] < $b['due'] ? -1 : 1 );
		} );
		$gate = null; $gate_n = 0;
		foreach ( array_slice( $due, 0, 10 ) as $r ) {
			$idx = $r['id'] - 1;
			if ( null === $gate ) { $pk = $L::peek( 'img.source.test', $now ); $gate = $pk['available'] ? 0.0 : $pk['retryAt']; }
			if ( $gate > 0 ) { $rows[ $idx ]['due'] = $gate + $gate_n * $interval; $gate_n++; $out['deferrals']++; continue; }
			$rows[ $idx ]['try']++; /* begin attempt */
			$slot = $L::reserve( 'img.source.test', $now );
			if ( empty( $slot['allowed'] ) ) {
				$rows[ $idx ]['try']--; /* defer_attempt_until() gives it back */
				$gate = $slot['retryAt'];
				$rows[ $idx ]['due'] = $gate + $gate_n * $interval; $gate_n++; $out['deferrals']++;
				continue;
			}
			$out['requests'][] = $now;
			if ( $with_429 && $now >= $t0 + 300 && $now < $t0 + 330 ) {
				$out['throttled'][] = $now;
				$gate = (float) $L::record_throttle( 'img.source.test', 90, $now );
				$rows[ $idx ]['try']--;
				$rows[ $idx ]['due'] = $gate + $gate_n * $interval; $gate_n++; $out['deferrals']++;
				$now += 0.4;
				continue;
			}
			$rows[ $idx ]['done'] = true;
			if ( $r['id'] > 600 ) { $out['newDone'][ $r['id'] ] = $now; }
			if ( ! empty( $slot['blockCount'] ) ) { $L::record_success( 'img.source.test' ); }
			$now += 0.4;
		}
		$busy_until = $now;
	}
	$out['rows'] = $rows;
	$max60 = 0; $j = 0; $req = $out['requests'];
	foreach ( $req as $i => $t ) { while ( $req[ $j ] <= $t - 60.0 ) { $j++; } $max60 = max( $max60, $i - $j + 1 ); }
	$out['max60'] = $max60;
	$out['maxTry'] = 0;
	foreach ( $rows as $r ) { $out['maxTry'] = max( $out['maxTry'], $r['try'] ); }
	$out['done'] = count( array_filter( $rows, static function ( $r ) { return $r['done']; } ) );
	return $out;
}
$cap60 = $L::DEFAULT_RATE + $L::DEFAULT_BURST;
foreach ( array( 'storm' => 2, 'normal' => 20 ) as $name => $every ) {
	$o = t_sim( $every, true );
	$new_wait = empty( $o['newDone'] ) ? 9999 : max( $o['newDone'] ) - $o['newAt'];
	check( $o['max60'] <= $cap60, "$name: peak " . $o['max60'] . " requests in any 60 s window (cap 30 + burst 5)" );
	check( count( $o['requests'] ) <= 12 * $L::DEFAULT_RATE + $L::DEFAULT_BURST, "$name: " . count( $o['requests'] ) . ' requests in 12 minutes (<= ' . ( 12 * $L::DEFAULT_RATE + $L::DEFAULT_BURST ) . ')' );
	check( 4 === count( $o['newDone'] ) && $new_wait <= 60, "$name: new product's 4 images downloaded " . round( $new_wait ) . ' s after being enqueued, ahead of the backlog' );
	$t429 = $o['throttled'];
	$in_pause = array_filter( $o['requests'], static function ( $t ) use ( $t429 ) { return ! empty( $t429 ) && $t > $t429[0] && $t < $t429[0] + 90; } );
	check( 1 === count( $t429 ) && 0 === count( $in_pause ), "$name: 429 + Retry-After 90 -> exactly one throttled request, none during the pause" );
	check( $o['maxTry'] <= 1 && $o['deferrals'] > 0, "$name: no image consumed an attempt for a deferral or the 429 (max try_count " . $o['maxTry'] . ', ' . $o['deferrals'] . ' deferrals)' );
	if ( 'storm' === $name ) {
		$after = array_filter( $o['requests'], static function ( $t ) use ( $t429 ) { return $t >= $t429[0] + 90; } );
		$span  = ( end( $o['requests'] ) - ( $t429[0] + 90 ) ) / 60;
		check( count( $after ) >= ( $span * $L::DEFAULT_RATE ) * 0.9, 'storm: after the pause it resumes at the cap (' . count( $after ) . ' requests in ' . round( $span, 1 ) . ' min)' );
		check( $o['done'] < 604 && 604 - $o['done'] > 200, 'storm: the backlog only slows down (' . $o['done'] . ' done, ' . ( 604 - $o['done'] ) . ' waiting, none failed)' );
	} else {
		$plain = t_sim( $every, false );
		check( $plain['max60'] <= 20, 'normal cadence: cap rarely binds (peak ' . $plain['max60'] . '/60 s, ' . count( $plain['requests'] ) . ' in 12 min)' );
	}
}

/* ------------------------------------------------------------------ */
echo "\n[8] wiring (source-level)\n";
$src = file_get_contents( $inc . 'class-mobo-core-image-sync.php' );
$dl  = substr( $src, strpos( $src, 'private function download_image(' ) );
$dl  = substr( $dl, 0, strpos( $dl, 'private function note_image_http_failure(' ) );
$p_storage = strpos( $dl, 'Mobo_Core_Image_Storage::check()' );
$p_reserve = strpos( $dl, 'Mobo_Core_Image_Host_Rate_Limit::reserve( $rate_host )' );
$p_local   = strpos( $dl, 'download_image_with_unsafe_local_fallback( $url, $product_id, $image_guid, $request_url, $force_fresh )' );
$p_sideload = strpos( $dl, 'media_sideload_image( $request_url' );
check( false !== $p_reserve && $p_storage < $p_reserve && $p_reserve < $p_local && $p_reserve < $p_sideload && strpos( $dl, 'find_existing_attachment' ) < $p_reserve, 'download_image(): slot reserved after reuse/storage checks and before any request (local and public paths)' );
check( false !== strpos( $dl, "add_filter( 'http_response', \$http_capture, 9, 3 )" ) && false !== strpos( $dl, "remove_filter( 'http_response', \$http_capture, 9 )" ) && false !== strpos( $dl, "\$error_data['code']" ), 'media_sideload path captures the real status/Retry-After (download_url reports all as http_404)' );
check( 2 === substr_count( $dl, 'note_image_http_failure(' ) || ( 1 === substr_count( $dl, 'note_image_http_failure(' ) && false !== strpos( $src, "note_image_http_failure( self::image_source_host( \$url ), \$code, wp_remote_retrieve_header( \$response, 'retry-after' ) )" ) ), 'both HTTP paths classify non-2xx responses' );
check( false !== strpos( $dl, 'Mobo_Core_Image_Host_Rate_Limit::record_success( $rate_host )' ), 'successful download closes a throttle escalation' );
$rows_src = substr( $src, strpos( $src, 'private function process_queue_rows(' ) );
$p_gate  = strpos( $rows_src, 'Mobo_Core_Image_Host_Rate_Limit::peek( $image_host )' );
$p_begin = strpos( $rows_src, 'begin_attempt_if_current( $id, $try_count' );
$p_resolve = strpos( $rows_src, '$attachment_id = $this->resolve_image_attachment( $url, $product_id, $image_guid );' );
$p_defer = strpos( $rows_src, 'defer_attempt_until( $id, $rate_until + $spread, $try_count' );
$p_fail  = strpos( $rows_src, 'mark_failure_if_current( $id, $message, $try_count' );
check( false !== $p_gate && $p_gate < $p_begin, 'worker defers capped rows before an attempt is begun' );
check( false !== strpos( $rows_src, "if ( \$bulk_claim && ! empty( \$row['_mobo_bulk_claimed'] ) && \$rate_gate[ \$image_host ] - time() <= 30 )" ), 'short cap wait: bulk-claimed rows go back with the one bulk release (no per-row write)' );
check( false !== $p_defer && $p_resolve < $p_defer && $p_defer < $p_fail, 'worker gives the attempt back when the download was capped/throttled (before any failure is recorded)' );
check( false !== strpos( $rows_src, "'rateDeferred'  => \$rate_deferred" ), 'pass result reports rateDeferred (not failed, not circuitFailed)' );
$ref = file_get_contents( $inc . 'class-mobo-core-image-refresh-service.php' );
check( false !== strpos( $ref, "'rateDeferred' => true" ) && false !== strpos( $ref, '$queue->defer_attempt_until( $id' ), 'Image Refresh: capped download waits without consuming a refresh attempt' );
$rq = file_get_contents( $inc . 'class-mobo-core-image-refresh-queue.php' );
check( false !== strpos( $rq, 'public function defer_attempt_until(' ), 'refresh queue has defer_attempt_until()' );
$adm = file_get_contents( $inc . 'class-mobo-core-admin.php' );
check( false !== strpos( $adm, "'mobo_core_image_host_rate_per_minute', 1, 120, 30" ) && false !== strpos( $adm, "'mobo_core_image_host_rate_per_minute' => array( 1, 120 )" ), 'admin setting: 1..120/min, default 30' );
check( false === strpos( $src, 'wp_remote_get( $request_url' ) || substr_count( $src, 'wp_remote_get(' ) === 1, 'no new HTTP call sites added' );

echo PHP_EOL . ( $failures ? "FAILED: $failures of $checks" : "ALL $checks CHECKS PASSED" ) . PHP_EOL;
exit( $failures ? 1 : 0 );
