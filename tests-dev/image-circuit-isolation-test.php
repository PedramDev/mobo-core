<?php
/**
 * Image-queue circuit breaker isolation (follow-up to O6, 2026-11-22).
 *
 * Per-item image failures (404/410/403, invalid or undecodable image, policy
 * blocked, permanent errors, one bad URL failing again and again) must never
 * count toward the image-stage circuit breaker. Only systemic signals do:
 * storage not ready, internal failures, and host-level transport/5xx failures
 * across several distinct hosts with no success in the pass. A failing host is
 * isolated by its own per-host breaker (distinct images counted, cooldown,
 * probe, escalation) so images of other hosts keep flowing. The per-item
 * backoff (try_count / next_retry_at / permanent ledger) is unchanged.
 *
 * [4] drives the real Image_Queue SQL (SQLite), the real pure policy helpers of
 * Mobo_Core_Image_Sync, Runtime_Diagnostics::record_stage/flush and
 * Circuit_Breaker::build_profile over many cron passes: a few failing rows plus
 * many healthy new rows -> every healthy row is processed, the stage never opens.
 *
 * Run: php tests-dev/image-circuit-isolation-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
require __DIR__ . '/sqlite-wpdb-stub.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_now']     = time();
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function esc_url_raw( $v ) { return trim( (string) $v ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s', $GLOBALS['t_now'] ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function apply_filters( $hook, $value ) { return $value; }
function add_filter() { return true; }
function add_action() { return true; }

final class Mobo_Core_Settings {
	public static function get( $k, $d = null ) { return get_option( $k, $d ); }
	public static function update( $k, $v, $a = false ) { return update_option( $k, $v, $a ); }
	public static function get_int( $k, $d, $min = 0, $max = PHP_INT_MAX ) { return (int) $d; }
	public static function enabled( $k, $d = '0' ) { return '1' === (string) get_option( $k, $d ); }
}
final class Mobo_Core_Connection_Context { public static function get_effective_connection_id() { return 1; } }
final class Mobo_Core_Environment { public static function on_shutdown( $cb ) { return true; } }

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-image-queue.php';
require $inc . 'class-mobo-core-image-sync.php';
require $inc . 'class-mobo-core-runtime-diagnostics.php';
require $inc . 'class-mobo-core-circuit-breaker.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$S = 'Mobo_Core_Image_Sync';

/* ------------------------------------------------------------------ */
echo "\n[1] failure classification\n";
$cases = array(
	'WordPress image sideload failed: wp_media_http_404'                       => 'item',
	'WordPress image sideload failed: wp_media_http_410'                       => 'item',
	'WordPress image sideload failed: wp_media_http_403'                       => 'item',
	'Image HTTP response was 404 or the response body was empty.'              => 'item',
	'Downloaded image failed physical MIME/file validation and was discarded before product linkage.' => 'item',
	'Image GUID or HTTP(S) source URL is invalid.'                             => 'item',
	'Product does not exist.'                                                  => 'item',
	'Local/private image URL is outside the trusted Mobo image origin policy.' => 'item',
	'Image file exists but final WebP/subsize validation failed: x'            => 'item',
	'Permanent: image is too large to process within memory_limit: 9000x9000'  => 'item',
	'Permanent: Image storage is not ready: quota'                             => 'item',
	'WordPress image sideload failed: wp_media_http_request_failed'            => 'host',
	'Image HTTP request failed: http_request_failed'                           => 'host',
	'WordPress image sideload failed: wp_media_http_502'                       => 'host',
	'WordPress image sideload failed: wp_media_http_503'                       => 'host',
	'WordPress image sideload failed: wp_media_http_429'                       => 'host',
	'Image HTTP response was 504 or the response body was empty.'              => 'host',
	'Image storage is not ready: Insufficient writable storage.'               => 'storage',
	'Could not create a temporary image file.'                                 => 'storage',
	'WebP readiness service is unavailable.'                                   => 'storage',
);
foreach ( $cases as $message => $expected ) {
	check( $expected === $S::classify_failure_message( $message ), $expected . ' <= ' . $message );
}
check( 'cdn.example.ir' === $S::image_source_host( 'https://CDN.Example.ir/a/b.jpg?x=1' ) && '' === $S::image_source_host( 'not a url' ), 'image_source_host() lower-cases and rejects non-URLs' );

/* ------------------------------------------------------------------ */
echo "\n[2] per-host breaker\n";
$t0 = 1800000000;
$st = array();
for ( $i = 0; $i < 10; $i++ ) {
	$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'img-bad-1', $t0 + $i * 60 );
}
check( 0 === $S::host_breaker_open_until( $st, 'a.test', $t0 + 600 ), 'one image timing out 10x never opens its host' );
$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'img-2', $t0 + 610 );
$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'img-3', $t0 + 620 );
$open = $S::host_breaker_open_until( $st, 'a.test', $t0 + 620 );
check( $open === $t0 + 620 + $S::HOST_BREAKER_BASE_COOLDOWN, 'three distinct images failing on a.test open it (base cooldown)' );
check( 0 === $S::host_breaker_open_until( $st, 'b.test', $t0 + 620 ), 'b.test unaffected' );
$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'img-4', $open + 1 );
check( $S::host_breaker_open_until( $st, 'a.test', $open + 1 ) === $open + 1 + 2 * $S::HOST_BREAKER_BASE_COOLDOWN, 'failed probe after cooldown reopens with an escalated cooldown' );
$st = $S::host_breaker_record( $st, 'a.test', 'success', '', $open + 400 );
check( 0 === $S::host_breaker_open_until( $st, 'a.test', $open + 400 ) && 0 === $st['a.test']['openCount'] && ! $st['a.test']['probe'], 'a success closes and resets the host' );
$st = array();
$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'x1', $t0 );
$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'x2', $t0 + 10 );
$st = $S::host_breaker_record( $st, 'a.test', 'host_failure', 'x3', $t0 + 10 + $S::HOST_BREAKER_WINDOW + 5 );
check( 0 === $S::host_breaker_open_until( $st, 'a.test', $t0 + 2000 ), 'failures outside the window are forgotten' );
$big = array();
for ( $i = 0; $i < 80; $i++ ) { $big = $S::host_breaker_record( $big, 'h' . $i . '.test', 'host_failure', 'k', $t0 + $i ); }
check( count( $big ) === $S::HOST_BREAKER_MAX_HOSTS && isset( $big['h79.test'] ) && ! isset( $big['h0.test'] ), 'state bounded to the most recent hosts' );

/* ------------------------------------------------------------------ */
echo "\n[3] stage circuit accounting\n";
check( 0 === $S::circuit_failed_count( 0, array(), array(), 0 ), 'only item failures => 0' );
check( 0 === $S::circuit_failed_count( 0, array( 'a.test' => 3 ), array(), 0 ), 'one failing host => isolated by its own breaker, 0 for the stage' );
check( 0 === $S::circuit_failed_count( 0, array( 'a.test' => 1, 'b.test' => 1 ), array( 'c.test' => true ), 0 ), 'two failing hosts but another host succeeded => 0' );
check( 2 === $S::circuit_failed_count( 0, array( 'a.test' => 1, 'b.test' => 1 ), array(), 0 ), 'several hosts failing, nothing succeeded (network/DNS outage) => systemic' );
check( 3 === $S::circuit_failed_count( 2, array(), array(), 1 ), 'storage + internal failures always count' );
/* record_stage honours circuitFailed. */
$ref = new ReflectionClass( 'Mobo_Core_Runtime_Diagnostics' );
$buf = $ref->getProperty( 'buffer' );
$buf->setAccessible( true );
$buf->setValue( null, array() );
Mobo_Core_Runtime_Diagnostics::record_stage( 'imageQueue', 100, array( 'processed' => 1, 'failed' => 2, 'circuitFailed' => 0 ) );
$b = $buf->getValue();
check( false === $b['stages']['imageQueue']['lastFailed'] && 0 === $b['stages']['imageQueue']['failed'] && 2 === $b['stages']['imageQueue']['itemFailed'], 'record_stage: item failures are not stage failures (kept as itemFailed)' );
Mobo_Core_Runtime_Diagnostics::record_stage( 'imageQueue', 100, array( 'processed' => 0, 'failed' => 1 ) );
$b = $buf->getValue();
check( true === $b['stages']['imageQueue']['lastFailed'], 'record_stage: results without circuitFailed (exceptions, other stages) unchanged' );
$buf->setValue( null, array() );

/* ------------------------------------------------------------------ */
echo "\n[4] few failing rows + many healthy rows over many cron passes\n";

/**
 * One simulated cron pass over the real queue, mirroring process_queue_rows():
 * claim -> per-host gate (defer_claimed_until) -> attempt -> per-item failure
 * (mark_failure_if_current: backoff ledger) or success -> pass result with
 * circuitFailed from the real policy helpers.
 */
function t_pass( $queue, $limit, $outcome_for, $use_circuit_failed ) {
	global $wpdb;
	$S = 'Mobo_Core_Image_Sync';
	$rows = $queue->claim_due_images( $limit, 300 );
	$processed = 0; $failed = 0; $deferred = 0; $storage = 0; $host_failed = array(); $ok_hosts = array(); $item = 0;
	$state = get_option( $S::HOST_BREAKER_OPTION, array() );
	foreach ( $rows as $row ) {
		$host = $S::image_source_host( $row['source_url'] );
		$open = $S::host_breaker_open_until( $state, $host, $GLOBALS['t_now'] );
		if ( $open > 0 && $queue->defer_claimed_until( $row['id'], $open, $row['_mobo_claim_token'] ) ) {
			$deferred++;
			$GLOBALS['t_deferred'][ $row['image_guid'] ] = ( $GLOBALS['t_deferred'][ $row['image_guid'] ] ?? 0 ) + 1;
			continue;
		}
		$GLOBALS['t_attempts'][ $row['image_guid'] ] = ( $GLOBALS['t_attempts'][ $row['image_guid'] ] ?? 0 ) + 1;
		$message = $outcome_for( $row );
		if ( '' === $message ) {
			$wpdb->query( "UPDATE wp_mobo_image_queue SET status = 'done', locked_until = NULL WHERE id = " . (int) $row['id'] );
			$processed++;
			$ok_hosts[ $host ] = true;
			if ( isset( $state[ $host ] ) ) { $state = $S::host_breaker_record( $state, $host, 'success', '', $GLOBALS['t_now'] ); }
			continue;
		}
		$queue->mark_failure_if_current( $row['id'], $message, absint( $row['try_count'] ) + 1, $row['product_id'], $row['image_guid'], $row['source_url'], 0 === strpos( $message, 'Permanent:' ), $row['_mobo_claim_token'] );
		$failed++;
		$class = $S::classify_failure_message( $message );
		if ( 'storage' === $class ) { $storage++; }
		elseif ( 'host' === $class ) {
			$host_failed[ $host ] = ( $host_failed[ $host ] ?? 0 ) + 1;
			$state = $S::host_breaker_record( $state, $host, 'host_failure', $row['image_guid'], $GLOBALS['t_now'] );
		} else { $item++; }
	}
	update_option( $S::HOST_BREAKER_OPTION, $state );
	$result = array( 'success' => true, 'status' => 'processed', 'processed' => $processed, 'failed' => $failed, 'deferred' => $deferred, 'remaining' => true );
	if ( $use_circuit_failed ) {
		$result['circuitFailed'] = $S::circuit_failed_count( $storage, $host_failed, $ok_hosts, 0 );
	}
	return $result;
}

/** Move every persisted timestamp $seconds into the past (simulated elapsed time). */
function t_shift( $seconds ) {
	global $wpdb;
	$m = '-' . (int) $seconds . ' seconds';
	$wpdb->pdo->exec( "UPDATE wp_mobo_image_queue SET next_retry_at = CASE WHEN next_retry_at IS NULL THEN NULL ELSE datetime(next_retry_at, '$m') END, updated_at = datetime(updated_at, '$m'), created_at = datetime(created_at, '$m')" );
	$hosts = get_option( Mobo_Core_Image_Sync::HOST_BREAKER_OPTION, array() );
	foreach ( $hosts as $h => $e ) {
		foreach ( array( 'openUntil', 'lastSuccessAt', 'lastEventAt' ) as $k ) { if ( ! empty( $e[ $k ] ) ) { $hosts[ $h ][ $k ] = $e[ $k ] - $seconds; } }
		foreach ( (array) $e['failures'] as $fk => $at ) { $hosts[ $h ]['failures'][ $fk ] = $at - $seconds; }
	}
	update_option( Mobo_Core_Image_Sync::HOST_BREAKER_OPTION, $hosts );
	$d = get_option( 'mobo_core_runtime_diagnostics', array() );
	foreach ( (array) ( $d['circuitBreakers']['stages'] ?? array() ) as $st => $e ) {
		foreach ( array( 'openUntil', 'lastFailureAt' ) as $k ) { if ( ! empty( $e[ $k ] ) ) { $d['circuitBreakers']['stages'][ $st ][ $k ] = $e[ $k ] - $seconds; } }
	}
	foreach ( (array) ( $d['stages'] ?? array() ) as $st => $e ) {
		foreach ( array( 'lastFailureAt', 'lastSuccessAt' ) as $k ) { if ( ! empty( $e[ $k ] ) ) { $d['stages'][ $st ][ $k ] = $e[ $k ] - $seconds; } }
	}
	if ( ! empty( $d['windowStartedAt'] ) ) { $d['windowStartedAt'] -= $seconds; }
	update_option( 'mobo_core_runtime_diagnostics', $d );
}

function t_run( $use_circuit_failed, $outcome_for, $passes = 60 ) {
	global $wpdb;
	$wpdb = new Sqlite_Test_WPDB();
	$GLOBALS['wpdb'] = $wpdb;
	$wpdb->pdo->exec( "CREATE TABLE wp_mobo_image_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER NOT NULL DEFAULT 0, queue_key TEXT, product_id INTEGER, product_guid TEXT DEFAULT '', image_guid TEXT DEFAULT '', source_url TEXT, position_index INTEGER DEFAULT 0, attachment_id INTEGER DEFAULT 0, status TEXT DEFAULT 'pending', try_count INTEGER DEFAULT 0, next_retry_at TEXT NULL, locked_until TEXT NULL, last_error TEXT NULL, created_at TEXT, updated_at TEXT)" );
	$GLOBALS['t_options'] = array();
	$GLOBALS['t_now']     = time();
	$GLOBALS['t_attempts'] = array();
	$GLOBALS['t_deferred'] = array();
	$qr = new ReflectionProperty( 'Mobo_Core_Image_Queue', 'table_exists_cache' );
	$qr->setAccessible( true );
	$qr->setValue( null, null );
	$rd = new ReflectionClass( 'Mobo_Core_Runtime_Diagnostics' );
	foreach ( array( 'buffer' => array(), 'flushed' => false ) as $p => $v ) { $pp = $rd->getProperty( $p ); $pp->setAccessible( true ); $pp->setValue( null, $v ); }

	/* Oldest first: 4 broken rows on the Backend image host, 3 rows on a dead CDN,
	 * then 40 healthy new rows on the Backend host and 5 on a third host. */
	$rows = array();
	foreach ( array( 'bad-404-a', 'bad-404-b', 'bad-410', 'bad-decode' ) as $g ) { $rows[] = array( $g, 'https://img.mobo.test/' . $g . '.jpg' ); }
	foreach ( array( 'dead-1', 'dead-2', 'dead-3' ) as $g ) { $rows[] = array( $g, 'https://cdn-down.test/' . $g . '.jpg' ); }
	for ( $i = 1; $i <= 40; $i++ ) { $rows[] = array( 'ok-' . $i, 'https://img.mobo.test/ok-' . $i . '.jpg' ); }
	for ( $i = 1; $i <= 5; $i++ ) { $rows[] = array( 'other-' . $i, 'https://other.test/other-' . $i . '.jpg' ); }
	$pid = 500;
	foreach ( $rows as $n => $r ) {
		$pid++;
		$wpdb->pdo->exec( "INSERT INTO wp_posts (ID, post_type) VALUES ($pid, 'product')" );
		$ts = gmdate( 'Y-m-d H:i:s', $GLOBALS['t_now'] - 1000 + $n );
		$wpdb->pdo->exec( "INSERT INTO wp_mobo_image_queue (connection_id, queue_key, product_id, image_guid, source_url, status, created_at, updated_at) VALUES (1, 'k$n', $pid, '{$r[0]}', '{$r[1]}', 'pending', '$ts', '$ts')" );
	}

	$queue = new Mobo_Core_Image_Queue();
	$opened = 0; $skipped = 0;
	for ( $pass = 1; $pass <= $passes; $pass++ ) {
		/* One minute passes between cron calls: PHP time() cannot be faked, so every
		 * persisted timestamp is moved one minute into the past instead. */
		t_shift( 60 );
		$GLOBALS['t_now'] = time();
		$profile = Mobo_Core_Circuit_Breaker::build_profile();
		Mobo_Core_Runtime_Diagnostics::record_circuit_profile( $profile );
		if ( Mobo_Core_Circuit_Breaker::should_skip( $profile, 'imageQueue' ) ) {
			$skipped++;
			Mobo_Core_Runtime_Diagnostics::flush();
			continue;
		}
		$result = t_pass( $queue, 3, $outcome_for, $use_circuit_failed );
		Mobo_Core_Runtime_Diagnostics::record_stage( 'imageQueue', 200, $result );
		Mobo_Core_Runtime_Diagnostics::flush();
	}
	$done = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_mobo_image_queue WHERE status = 'done'" );
	$healthy_done = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_mobo_image_queue WHERE status = 'done' AND (image_guid LIKE 'ok-%' OR image_guid LIKE 'other-%')" );
	$bad_rows = $wpdb->get_results( "SELECT image_guid, status, try_count, next_retry_at, last_error FROM wp_mobo_image_queue WHERE image_guid LIKE 'bad-%' OR image_guid LIKE 'dead-%' ORDER BY id", ARRAY_A );
	return array( 'skipped' => $skipped, 'done' => $done, 'healthy' => $healthy_done, 'bad' => $bad_rows, 'attempts' => $GLOBALS['t_attempts'], 'deferred' => $GLOBALS['t_deferred'], 'hosts' => get_option( Mobo_Core_Image_Sync::HOST_BREAKER_OPTION, array() ) );
}

$outcomes = static function ( $row ) {
	$g = $row['image_guid'];
	if ( 0 === strpos( $g, 'bad-404' ) ) { return 'WordPress image sideload failed: wp_media_http_404'; }
	if ( 'bad-410' === $g ) { return 'WordPress image sideload failed: wp_media_http_410'; }
	if ( 'bad-decode' === $g ) { return 'Downloaded image failed physical MIME/file validation and was discarded before product linkage.'; }
	if ( 0 === strpos( $g, 'dead-' ) ) { return 'WordPress image sideload failed: wp_media_http_request_failed'; }
	return '';
};

$new = t_run( true, $outcomes );
check( 0 === $new['skipped'], 'image stage never isolated by its circuit breaker (skipped passes: ' . $new['skipped'] . ')' );
check( 45 === $new['healthy'], 'all 45 healthy rows processed despite the failing rows (' . $new['healthy'] . '/45)' );
$all_pending_with_backoff = true;
foreach ( $new['bad'] as $r ) {
	if ( 0 === strpos( $r['image_guid'], 'bad-' ) ) {
		$all_pending_with_backoff = $all_pending_with_backoff && 'pending' === $r['status'] && (int) $r['try_count'] >= 1 && '' !== (string) $r['next_retry_at'] && '' !== (string) $r['last_error'];
	}
}
check( $all_pending_with_backoff, 'failing rows keep their per-item try count, last error and backoff' );
check( max( $new['attempts']['bad-404-a'] ?? 0, $new['attempts']['bad-410'] ?? 0 ) <= 8, 'per-item backoff bounds retries of a single bad URL over 60 passes (' . ( $new['attempts']['bad-404-a'] ?? 0 ) . ' attempts)' );
check( isset( $new['hosts']['cdn-down.test'] ) && (int) $new['hosts']['cdn-down.test']['openCount'] >= 1, 'dead CDN host isolated by its own breaker' );
check( ! isset( $new['hosts']['img.mobo.test'] ) || 0 === (int) $new['hosts']['img.mobo.test']['openCount'], 'Backend image host never isolated by item failures (404/410/invalid image)' );
check( ( ( $new['deferred']['dead-1'] ?? 0 ) + ( $new['deferred']['dead-2'] ?? 0 ) + ( $new['deferred']['dead-3'] ?? 0 ) ) > 0 && ( $new['attempts']['dead-1'] ?? 0 ) <= 4, 'dead-host rows are deferred, not hammered (dead-1 attempts: ' . ( $new['attempts']['dead-1'] ?? 0 ) . ')' );
$dead_try = 0;
foreach ( $new['bad'] as $r ) { if ( 'dead-1' === $r['image_guid'] ) { $dead_try = (int) $r['try_count']; } }
check( $dead_try === (int) ( $new['attempts']['dead-1'] ?? 0 ), 'host deferral never increments the item try count' );

/* Sensitivity: the same scenario with the old accounting (every failure counts). */
$old = t_run( false, $outcomes );
check( $old['skipped'] > 0, 'control: counting item failures opens the image circuit (' . $old['skipped'] . ' passes skipped)' );

/* Systemic signal still trips the stage breaker: storage not ready everywhere. */
$storage = t_run( true, static function ( $row ) { return 'Image storage is not ready: Insufficient writable storage.'; }, 20 );
check( $storage['skipped'] > 0, 'storage not ready on every image => image stage isolated (' . $storage['skipped'] . ' passes skipped)' );

/* ------------------------------------------------------------------ */
echo "\n[5] wiring in the real worker\n";
$src = (string) file_get_contents( $inc . 'class-mobo-core-image-sync.php' );
check( false !== strpos( $src, "'circuitFailed' => self::circuit_failed_count( \$storage_failed, \$host_failed, \$successful_hosts, \$internal_failed )" ), 'process_queue_rows() reports circuitFailed from the policy' );
check( false !== strpos( $src, '$queue->defer_claimed_until( $id, $host_open_until + ( $id % 30 ), $claim_token )' ), 'process_queue_rows() defers rows of an isolated host without an attempt' );
check( false !== strpos( $src, "self::host_breaker_record( \$host_state, \$image_host, 'host_failure'" ) && false !== strpos( $src, "self::host_breaker_record( \$host_state, \$image_host, 'success'" ), 'process_queue_rows() feeds host failures and successes to the per-host breaker' );
$cron_src = (string) file_get_contents( $inc . 'class-mobo-core-cron-runner.php' );
check( false !== strpos( $cron_src, "\$result['circuitFailed'] = absint( \$result['circuitFailed'] ) + absint( isset( \$prune_result['failed'] ) ? \$prune_result['failed'] : 0 );" ), 'cron image stage keeps prune failures systemic' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
