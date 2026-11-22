<?php
/**
 * S2 regression tests for the table-backed webhook/sync event queue:
 *  - every claim is an attempt (try_count + 1); deferral, partial progress and
 *    a claim released at a time budget give the attempt back;
 *  - stale (crashed) rows are reclaimed alone, never reset to try_count 0, and
 *    failed after CRASH_RECLAIM_LIMIT abnormal ends instead of for two days;
 *  - processing rows without a lease are reclaimed;
 *  - exponential backoff; transient failures (DB write error, disk full) are
 *    not final after 5 tries / ~5 minutes; permanent ones still are;
 *  - failed transient rows are re-armed automatically (bounded, not when
 *    superseded or too old) and a failed write never reports success;
 *  - UTC parsing does not depend on the PHP default timezone.
 *
 * The real Mobo_Core_Sync_Event_Store SQL runs against SQLite (sqlite-wpdb-stub.php).
 *
 * Run: php tests-dev/sync-event-retry-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t_options'] = array();
$GLOBALS['t_failures'] = 0;
$GLOBALS['t_checks']   = 0;
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_generate_uuid4() { return sprintf( '%08x-0000-4000-8000-%012x', mt_rand(), mt_rand() ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
class WP_Error { private $c; public function __construct( $c = '', $m = '' ) { $this->c = $c; } public function get_error_code() { return $this->c; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
final class Mobo_Core_Connection_Context { public static function get_effective_connection_id() { return 1; } }
final class Mobo_Core_Settings {
	public static function get_int( $k, $d, $min = 0, $max = PHP_INT_MAX ) { return (int) $d; }
	public static function get( $k, $d = '' ) { return $d; }
}

function t_check( $cond, $label ) {
	$GLOBALS['t_checks']++;
	echo ( $cond ? '  ok   - ' : '  FAIL - ' ) . $label . "\n";
	if ( ! $cond ) { $GLOBALS['t_failures']++; }
}
function t_same( $e, $a, $label ) { t_check( $e === $a, $label . ( $e === $a ? '' : ' (expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) . ')' ) ); }

require __DIR__ . '/sqlite-wpdb-stub.php';
$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-environment.php';
require $inc . 'class-mobo-core-ordering-policy.php';
require $inc . 'class-mobo-core-sync-event-store.php';

$wpdb = new Sqlite_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->pdo->exec( "CREATE TABLE wp_mobo_sync_events (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL DEFAULT 0, event_uuid TEXT NOT NULL, remote_event_id TEXT NOT NULL DEFAULT '', event_type TEXT NOT NULL, entity_type TEXT NOT NULL DEFAULT '', entity_guid TEXT NOT NULL DEFAULT '', sync_id TEXT NOT NULL DEFAULT '', event_version TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending', try_count INTEGER NOT NULL DEFAULT 0, next_retry_at TEXT NULL, locked_until TEXT NULL, claim_token TEXT NOT NULL DEFAULT '', expires_at TEXT NULL, payload_json TEXT NOT NULL DEFAULT '{}', progress_json TEXT NULL, last_error TEXT NULL, source TEXT NOT NULL DEFAULT 'webhook', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)" );

$S = 'Mobo_Core_Sync_Event_Store';
$T = 'wp_mobo_sync_events';
$store = new Mobo_Core_Sync_Event_Store();

function t_event( $fields = array() ) {
	global $wpdb;
	static $n = 0;
	$n++;
	$now = gmdate( 'Y-m-d H:i:s' );
	$row = array_merge(
		array( 'connection_id' => 1, 'event_uuid' => 'uuid-' . $n, 'event_type' => 'ProductUpdated', 'entity_type' => 'product', 'entity_guid' => 'g-' . $n, 'status' => 'pending', 'try_count' => 0, 'payload_json' => '{"a":1}', 'created_at' => $now, 'updated_at' => $now ),
		$fields
	);
	$wpdb->insert( 'wp_mobo_sync_events', $row );
	return (int) $wpdb->insert_id;
}
function t_reset_events() { global $wpdb; $wpdb->pdo->exec( 'DELETE FROM wp_mobo_sync_events' ); $wpdb->fail_pattern = ''; $GLOBALS['t_options'] = array(); }
function t_row( $id ) { global $wpdb; return $wpdb->row( 'wp_mobo_sync_events', $id ); }
function t_ago( $seconds ) { return gmdate( 'Y-m-d H:i:s', time() - $seconds ); }

echo "[1] pure retry policy\n";
t_check( $S::is_transient_failure( 'WordPress database error: The table wp_posts is full' ), 'full table is transient' );
t_check( $S::is_transient_failure( 'Could not write product map row (Disk full (errno: 28))' ), 'disk full / errno 28 is transient' );
t_check( $S::is_transient_failure( 'Payload pull failed: cURL error 28: Operation timed out' ), 'network timeout is transient' );
t_check( ! $S::is_transient_failure( 'Incomplete Mobo variant attributes.' ), 'payload defect is permanent' );
t_check( ! $S::is_transient_failure( 'Webhook event expired.' ), 'expiry is permanent' );
t_check( $S::retry_delay( 1, 0 ) >= 30 && $S::retry_delay( 1, 0 ) < 33, 'first retry ~30 s' );
$d = array();
for ( $i = 1; $i <= 10; $i++ ) { $d[ $i ] = $S::retry_delay( $i, 0 ); }
t_check( $d[2] >= 60 && $d[3] >= 120 && $d[4] >= 240 && $d[5] >= 480, 'backoff doubles (30/60/120/240/480 s)' );
t_check( $d[10] >= 3600 && $d[10] < 3600 * 1.1, 'backoff capped at one hour (+jitter)' );
$p = $S::failure_policy( 5, 5, true, 'Database error: Disk full (errno: 28)', 1 );
t_check( false === $p['final'] && $p['delay'] >= 480, 'transient failure at the 5-try budget keeps retrying with backoff' );
$p = $S::failure_policy( $S::TRANSIENT_MAX_TRY, 5, true, 'Database error: Disk full (errno: 28)', 1 );
t_check( true === $p['final'], 'transient failure becomes final only at TRANSIENT_MAX_TRY' );
$p = $S::failure_policy( 5, 5, true, 'Incomplete Mobo variant attributes.', 1 );
t_check( true === $p['final'], 'permanent failure is still final at the ordinary budget' );
$p = $S::failure_policy( 1, 5, true, 'Database error: deadlock found (ordering conflict)', 1 );
t_check( true === $p['final'], 'explicit non-retryable request before the budget is honoured' );
$total = 0;
for ( $i = 1; $i < $S::TRANSIENT_MAX_TRY; $i++ ) { $total += $S::retry_delay( $i, 0 ); }
t_check( $total > 3 * 3600, 'transient horizon is hours (' . round( $total / 3600, 1 ) . ' h), not ~5 minutes' );
t_same( 2, $S::reclaim_count( $S::reclaim_message( 2 ) ), 'reclaim counter round-trips through last_error' );
t_same( 0, $S::reclaim_count( 'Some other error' ), 'other errors carry no reclaim count' );

echo "\n[2] claim = attempt; non-failures give it back\n";
t_reset_events();
$id = t_event();
$rows = $store->claim_due_events( 5, 90 );
t_same( 1, count( $rows ), 'pending row claimed' );
t_same( 1, (int) t_row( $id )['try_count'], 'claim increments try_count in the database' );
t_same( 0, (int) $rows[0]['try_count'], 'returned row keeps the pre-claim count (caller computes +1 = this attempt)' );
t_same( 1, (int) $rows[0]['_mobo_claim_attempt'], 'claimed attempt number exposed' );
$token = $rows[0]['claim_token'];
t_check( $store->mark_pending_progress( $id, array( 'a' => 2 ), array( 'deferSeconds' => 15 ), $token ), 'deferral committed' );
$r = t_row( $id );
t_same( 0, (int) $r['try_count'], 'deferral / partial progress gives the attempt back' );
t_same( 'pending', $r['status'], '  row pending again' );
$wpdb->pdo->exec( "UPDATE wp_mobo_sync_events SET next_retry_at = NULL WHERE id = {$id}" );
$rows = $store->claim_due_events( 5, 90 );
$store->release_claimed_events( array( $id ), $rows[0]['claim_token'] );
$r = t_row( $id );
t_same( 0, (int) $r['try_count'], 'claim released at a time budget gives the attempt back' );
t_same( 'pending', $r['status'], '  released row is pending' );

echo "\n[3] crash loop: reclaimed alone, bounded, never reset to 0\n";
t_reset_events();
$poison = t_event( array( 'event_type' => 'ProductUpdated' ) );
$other1 = t_event();
$other2 = t_event();
$rows = $store->claim_due_events( 10, 90 );
t_same( 3, count( $rows ), 'first batch claims all three rows' );
/* PHP dies while processing: rows stay processing with an expired lease. */
$wpdb->pdo->exec( "UPDATE wp_mobo_sync_events SET locked_until = '" . t_ago( 5 ) . "'" );
$rows = $store->claim_due_events( 10, 90 );
t_same( 1, count( $rows ), 'a stale row is reclaimed alone, not with innocent rows' );
t_same( $poison, (int) $rows[0]['id'], '  oldest stale row first' );
t_same( 2, (int) t_row( $poison )['try_count'], '  reclaim counts as an attempt (try_count 2, not reset to 0)' );
t_same( 1, $S::reclaim_count( t_row( $poison )['last_error'] ), '  abnormal end recorded' );
for ( $crash = 2; $crash <= $S::CRASH_RECLAIM_LIMIT; $crash++ ) {
	$wpdb->pdo->exec( "UPDATE wp_mobo_sync_events SET locked_until = '" . t_ago( 5 ) . "' WHERE id = {$poison}" );
	$rows = $store->claim_due_events( 10, 90 );
}
t_same( $S::CRASH_RECLAIM_LIMIT, $S::reclaim_count( t_row( $poison )['last_error'] ), 'reclaimed up to CRASH_RECLAIM_LIMIT times' );
$wpdb->pdo->exec( "UPDATE wp_mobo_sync_events SET locked_until = '" . t_ago( 5 ) . "' WHERE id = {$poison}" );
$rows = $store->claim_due_events( 10, 90 );
$r = t_row( $poison );
t_same( 'failed', $r['status'], 'next abnormal end fails the poison event (minutes, not two days)' );
t_same( $S::CRASH_FAILED_MESSAGE, $r['last_error'], '  with an explicit crash message' );
t_check( ! in_array( $poison, array_map( 'intval', wp_list_ids( $rows ) ), true ), '  and it is not claimed again' );
function wp_list_ids( $rows ) { return array_map( static function ( $r ) { return $r['id']; }, (array) $rows ); }
$innocent_done = 0;
foreach ( array( $other1, $other2 ) as $other ) {
	$wpdb->pdo->exec( "UPDATE wp_mobo_sync_events SET locked_until = '" . t_ago( 5 ) . "' WHERE id = {$other} AND status = 'processing'" );
}
for ( $i = 0; $i < 4; $i++ ) {
	foreach ( (array) $store->claim_due_events( 10, 90 ) as $row ) {
		if ( $store->mark_done( (int) $row['id'], $row['claim_token'] ) ) { $innocent_done++; }
	}
}
t_same( 2, $innocent_done, 'innocent rows of the crashed batch are processed individually' );
t_check( (int) t_row( $other1 )['try_count'] <= 3, '  their attempt count grew only by the crashed batch' );

echo "\n[4] processing row without a lease is not stuck forever\n";
t_reset_events();
$orphan = t_event( array( 'status' => 'processing', 'locked_until' => null, 'updated_at' => t_ago( $S::NULL_LOCK_STALE_SECONDS + 60 ) ) );
$fresh  = t_event( array( 'status' => 'processing', 'locked_until' => null, 'updated_at' => t_ago( 10 ) ) );
t_check( $store->has_due_events(), 'has_due_events() sees the abandoned row' );
$rows = $store->claim_due_events( 10, 90 );
t_same( array( $orphan ), array_map( 'intval', wp_list_ids( $rows ) ), 'abandoned NULL-lease row reclaimed; a recent one is left alone' );

echo "\n[5] transient vs permanent failure transitions\n";
t_reset_events();
$id = t_event( array( 'status' => 'processing', 'claim_token' => 'tok', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'try_count' => 5 ) );
t_check( $store->mark_failure( $id, 'Product map write failed: Disk full (errno: 28)', 5, true, 'tok' ), 'transient failure committed' );
$r = t_row( $id );
t_same( 'pending', $r['status'], 'disk-full failure after 5 tries stays pending (was final failed)' );
$delay = strtotime( $r['next_retry_at'] . ' UTC' ) - time();
t_check( $delay >= 470 && $delay <= 540, 'backoff after the 5th try is ~8 minutes (got ' . $delay . ' s)' );
$id2 = t_event( array( 'status' => 'processing', 'claim_token' => 'tok2', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'try_count' => 5 ) );
$store->mark_failure( $id2, 'Incomplete Mobo variant attributes.', 5, true, 'tok2' );
t_same( 'failed', t_row( $id2 )['status'], 'permanent failure at the budget is final' );
$id3 = t_event( array( 'status' => 'processing', 'claim_token' => 'tok3', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'try_count' => 2 ) );
$store->mark_retry_now( $id3, 'Payload pull failed: HTTP 503', 2, false, 'tok3' );
t_same( null, t_row( $id3 )['next_retry_at'], 'payload-pull retry is immediate for the first attempts' );
$id4 = t_event( array( 'status' => 'processing', 'claim_token' => 'tok4', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'try_count' => 6 ) );
$store->mark_retry_now( $id4, 'Payload pull failed: HTTP 503', 6, true, 'tok4' );
$r = t_row( $id4 );
t_check( 'pending' === $r['status'] && null !== $r['next_retry_at'], 'repeated payload-pull outage backs off instead of failing within seconds' );
$wpdb->fail_pattern = '/^\s*UPDATE wp_mobo_sync_events\s+SET/';
$id5 = t_event( array( 'status' => 'processing', 'claim_token' => 'tok5', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
t_same( false, $store->mark_done( $id5, 'tok5' ), 'failed DB write: mark_done reports false (event is not acknowledged)' );
t_same( false, $store->mark_failure( $id5, 'x', 1, false, 'tok5' ), 'failed DB write: mark_failure reports false' );
t_same( array(), $store->claim_due_events( 5, 90 ), 'failed claim UPDATE claims nothing' );
$wpdb->fail_pattern = '';
t_same( 'processing', t_row( $id5 )['status'], '  row state unchanged by the failed writes' );

echo "\n[6] legacy stale-row recovery keeps attempts\n";
t_reset_events();
$stale = t_event( array( 'status' => 'processing', 'locked_until' => t_ago( 60 ), 'try_count' => 4 ) );
$stock = t_event( array( 'status' => 'pending', 'try_count' => 3, 'last_error' => 'Nullable stock value rejected' ) );
$store->recover_legacy_nullable_stock_blockers( 100 );
t_same( 4, (int) t_row( $stale )['try_count'], 'stale processing row is re-armed WITHOUT resetting try_count to 0' );
t_same( 'pending', t_row( $stale )['status'], '  and is pending again' );
t_same( 0, (int) t_row( $stock )['try_count'], 'legacy stock-bug row still gets its fresh budget' );

echo "\n[7] automatic retry of failed transient rows\n";
t_reset_events();
$transient = t_event( array( 'status' => 'failed', 'try_count' => 12, 'last_error' => 'WordPress database error: Disk full (errno: 28)', 'expires_at' => t_ago( 60 ), 'entity_guid' => 'p-1', 'event_version' => '5' ) );
$permanent = t_event( array( 'status' => 'failed', 'try_count' => 5, 'last_error' => 'Incomplete Mobo variant attributes.', 'entity_guid' => 'p-2' ) );
$old       = t_event( array( 'status' => 'failed', 'try_count' => 12, 'last_error' => 'Disk full', 'created_at' => t_ago( $S::AUTO_RETRY_MAX_AGE + 3600 ), 'entity_guid' => 'p-3' ) );
$superseded = t_event( array( 'status' => 'failed', 'try_count' => 12, 'last_error' => 'Disk full', 'entity_guid' => 'p-4', 'event_version' => '3' ) );
t_event( array( 'status' => 'done', 'entity_guid' => 'p-4', 'event_version' => '4' ) );
t_check( $store->has_due_events(), 'has_due_events() triggers the throttled auto retry' );
$r = t_row( $transient );
t_same( 'pending', $r['status'], 'failed transient row re-armed automatically after recovery' );
t_check( strtotime( $r['expires_at'] . ' UTC' ) > time() + 86400, '  its expiry is extended (still relevant)' );
t_same( 12, (int) $r['try_count'], '  attempt count kept (one attempt per auto-retry while still broken)' );
t_same( 'failed', t_row( $permanent )['status'], 'permanent failure is not auto-retried' );
t_same( 'failed', t_row( $old )['status'], 'auto retry is bounded to AUTO_RETRY_MAX_AGE' );
t_same( 'failed', t_row( $superseded )['status'], 'superseded failed row is not resurrected' );
$wpdb->pdo->exec( "UPDATE wp_mobo_sync_events SET status = 'failed' WHERE id = {$transient}" );
t_same( 0, $store->maybe_auto_retry_failed_events(), 'auto retry is throttled' );
$wpdb->fail_pattern = '/^\s*UPDATE wp_mobo_sync_events/';
t_same( 0, $store->auto_retry_failed_events(), 'while the database cannot write nothing is re-armed' );
$wpdb->fail_pattern = '';

echo "\n[8] UTC parsing independent of the PHP timezone\n";
$tz = date_default_timezone_get();
date_default_timezone_set( 'Asia/Tehran' );
t_same( 1767225600, $S::utc_timestamp( '2026-01-01 00:00:00' ), 'DB DATETIME parsed as UTC under Asia/Tehran' );
t_same( 1767225600, Mobo_Core_Environment::utc_strtotime( '2026-01-01T00:00:00' ), 'ISO without offset parsed as UTC' );
t_same( 1767225600, Mobo_Core_Environment::utc_strtotime( '2026-01-01T03:30:00+03:30' ), 'explicit offset respected' );
t_same( 7, $S::utc_timestamp( '0000-00-00 00:00:00', 7 ), 'zero date falls back' );
$item = $store->row_to_item( array( 'payload_json' => '{}', 'expires_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
t_same( 1767225600, $item['expiresAt'], 'row_to_item() expiry is UTC' );
date_default_timezone_set( $tz );

echo "\n" . $GLOBALS['t_checks'] . ' checks, ' . $GLOBALS['t_failures'] . ' failure(s) on PHP ' . PHP_VERSION . "\n";
exit( $GLOBALS['t_failures'] > 0 ? 1 : 0 );
