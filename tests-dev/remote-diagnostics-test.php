<?php
/**
 * Remote diagnostics: error capture, scrubbing, ring buffer, correlation, job
 * diagnostics for Sync/Repair/Recategorize, bundle contract/size cap and the
 * installation-level auth of POST /support/diagnostics.
 *
 *   php tests-dev/remote-diagnostics-test.php
 *
 * The fatal-capture case runs a child PHP process that dies on an undefined
 * function inside a "plugin" file; the parent inspects the options it wrote.
 * No WordPress, no database, no network. PHP 7.4 compatible.
 */

$mode = isset( $argv[1] ) ? (string) $argv[1] : '';
$GLOBALS['__opts_file'] = '--child-fatal' === $mode ? (string) $argv[2] : '';
require __DIR__ . '/diagnostics-test-stubs.php';
if ( '' !== $GLOBALS['__opts_file'] && is_file( $GLOBALS['__opts_file'] ) ) {
	$GLOBALS['__opts'] = (array) json_decode( (string) file_get_contents( $GLOBALS['__opts_file'] ), true );
}

$inc = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/wp-content/plugins/mobo-core/includes/';

/* ---------------- child: fatal inside (or outside) the plugin ---------------- */
if ( '--child-fatal' === $mode ) {
	define( 'MOBO_CORE_PLUGIN_DIR', (string) $argv[3] );
	require $inc . 'class-mobo-core-environment.php';
	require $inc . 'class-mobo-core-correlation.php';
	require $inc . 'class-mobo-core-error-log.php';
	Mobo_Core_Error_Log::bootstrap();
	Mobo_Core_Correlation::push( 'order', 77 );
	require (string) $argv[4];
	mobo_test_boom();
	exit( 0 );
}

define( 'MOBO_CORE_VERSION', '10.34.77' );
define( 'MOBO_CORE_PLUGIN_DIR', str_replace( '\\', '/', dirname( __DIR__ ) ) . '/wp-content/plugins/mobo-core/' );

/* ---------------- collaborator stubs ---------------- */
class Mobo_Core_State_Store {
	public static function is_available() { return true; }
	public static function get( $k, $d = null ) { return array_key_exists( 'ss_' . $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ 'ss_' . $k ] : $d; }
	public static function set( $k, $v ) { $GLOBALS['__opts'][ 'ss_' . $k ] = $v; return true; }
	public static function delete( $k ) { unset( $GLOBALS['__opts'][ 'ss_' . $k ] ); return true; }
}
class Mobo_Core_Settings {
	public static function get_int( $k, $d, $a = 0, $b = 0 ) { return (int) $d; }
	public static function enabled( $k, $d = '1' ) { return true; }
	public static function get( $k, $d = '' ) { return $d; }
}
class Mobo_Core_Lock {
	public static $recovered = array();
	public static function get_status( $name ) { return array( 'active' => false, 'acquiredAt' => 0, 'lastHeartbeatAt' => 0, 'expiresAt' => 0, 'remainingSeconds' => 0 ); }
	public static function recover_stale_locks( $names ) { self::$recovered[] = $names; return array(); }
}
class Mobo_Core_Self_Runner {
	public static $kicks = array();
	public static function kick( $source, $blocking = false ) { self::$kicks[] = $source; return array( 'success' => true ); }
	public static function get_status() { return array( 'lastRunAt' => 0, 'workerToken' => 'SHOULD-NOT-LEAK-TOKEN' ); }
}
class Mobo_Core_Connection_Context {
	public static $current = 0;
	public static function get_current_connection_id() { return self::$current; }
	public static function get_effective_connection_id() { return self::$current; }
	public static function clear() {}
}
class Mobo_Core_Connection_Repository {
	public static $rows = array();
	public static $default = 0;
	public static function table_exists() { return true; }
	public static function get_by_id_fresh( $id ) { return isset( self::$rows[ $id ] ) ? self::$rows[ $id ] : null; }
	public static function get_default_fresh() { return isset( self::$rows[ self::$default ] ) ? self::$rows[ self::$default ] : null; }
}
$GLOBALS['__routes'] = array();
function register_rest_route( $ns, $route, $args ) { $GLOBALS['__routes'][ $ns . $route ] = $args; return true; }

require $inc . 'class-mobo-core-environment.php';
require $inc . 'class-mobo-core-correlation.php';
require $inc . 'class-mobo-core-error-log.php';
require $inc . 'class-mobo-core-environment-probe.php';
require $inc . 'class-mobo-core-job-diagnostics.php';
require $inc . 'class-mobo-core-diagnostics-bundle.php';
require $inc . 'class-mobo-core-rest-controller.php';

$L = 'Mobo_Core_Error_Log';
echo 'PHP ' . PHP_VERSION . "\n";

/** Everything persisted by the error log, as one JSON string. */
function stored_json() {
	$parts = array();
	foreach ( $GLOBALS['__opts'] as $k => $v ) {
		if ( 0 === strpos( $k, 'mobo_core_diag_' ) ) {
			$parts[ $k ] = $v;
		}
	}
	return json_encode( $parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

echo "\n[1] scrubbing: no secrets / PII reach storage or the bundle\n";
$L::clear();
/* Two messages, each shorter than MESSAGE_MAX, so nothing is hidden by clipping. */
$secret_message = 'url https://api.mobo.test/v1/orders/9?token=SECRETQUERY123&x=1 at C:\\hosting\\site42\\public_html\\wp-content\\plugins\\mobo-core\\includes\\class-mobo-core-checkout-validator.php '
	. 'Sync failed: license_token=abcd1234SECRETTOKENxyz X-SEC: s3cr3tXsecVALUE password=hunter2pass Cookie: wordpress_logged_in_9f=adminCOOKIE';
$secret_message_2 = 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJl customer ali.rezaei@example.com mobile 09123456789 '
	. 'card 6037-9918-1234-5678 IBAN IR820540102680020817909002 blob 9f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a3928';
t_ok( strlen( $secret_message ) < Mobo_Core_Error_Log::MESSAGE_MAX && strlen( $secret_message_2 ) < Mobo_Core_Error_Log::MESSAGE_MAX, 'probe messages are not clipped' );
$L::capture( 'error', 'source.http', $secret_message_2 );
$context = array(
	'licenseToken'     => 'CTX-LICENSE-TOKEN',
	'password'         => 'CTX-PASSWORD',
	'X-SEC'            => 'CTX-XSEC',
	'headers'          => array( 'Authorization' => 'Bearer CTX-HEADER' ),
	'cookie'           => 'CTX-COOKIE',
	'customer_name'    => 'Ali Rezaei',
	'billing_phone'    => '09121112233',
	'shipping_address' => 'Tehran Valiasr Street 12',
	'email'            => 'buyer@example.org',
	'walletBalance'    => 990000,
	'card_number'      => '6037991812345678',
	'signature'        => 'CTX-SIGNATURE',
	'itemId'           => 5,
	'nested'           => array( 'apiKey' => 'CTX-API-KEY', 'ok' => 'fine', 'note' => 'call 09351234567 now' ),
);
$L::capture( 'error', 'backend.http', $secret_message, $context, 'C:/hosting/site42/public_html/wp-content/plugins/mobo-core/includes/class-mobo-core-api-client.php', 1200 );
$L::capture_wp_error( 'backend', new WP_Error( 'mobo_core_payload_http_error', 'Portal API GET returned HTTP 500 for https://b.test/a?token=WPERR-SECRET', array( 'status' => 500 ) ), array( 'method' => 'GET', 'endpoint' => 'https://b.test/api/v1/products?token=ENDPOINT-SECRET&page=2' ) );
$GLOBALS['wpdb'] = (object) array( 'last_error' => "Duplicate entry 'buyer2@example.org' for key 'email'", 'last_query' => "INSERT INTO wp_mobo_core_map (email, phone) VALUES ('buyer2@example.org', '09129998877')" );
$L::capture_db_error( 'test', true );
$L::flush();
unset( $GLOBALS['wpdb'] );
$stored  = stored_json();
$recent  = json_encode( $L::recent(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$bundle  = json_encode( Mobo_Core_Diagnostics_Bundle::build( array( 'errors', 'events' ), 60 ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$leaks   = array( 'abcd1234SECRETTOKENxyz', 's3cr3tXsecVALUE', 'hunter2pass', 'adminCOOKIE', 'eyJhbGciOiJIUzI1NiJ9', 'ali.rezaei', 'example.com', '09123456789', '6037-9918-1234-5678', 'IR820540102680020817909002', 'SECRETQUERY123', 'site42', 'public_html', '9f8e7d6c5b4a3928170', 'CTX-LICENSE-TOKEN', 'CTX-PASSWORD', 'CTX-XSEC', 'CTX-HEADER', 'CTX-COOKIE', 'Ali Rezaei', '09121112233', 'Valiasr', 'buyer@example.org', '990000', '6037991812345678', 'CTX-SIGNATURE', 'CTX-API-KEY', '09351234567', 'WPERR-SECRET', 'ENDPOINT-SECRET', 'buyer2@example.org', '09129998877' );
$found = array();
foreach ( $leaks as $needle ) {
	if ( false !== strpos( $stored . $recent . $bundle, $needle ) ) {
		$found[] = $needle;
	}
}
t_same( array(), $found, 'no secret/PII value appears in stored options, recent() or the bundle' );
t_ok( false !== strpos( $recent, 'includes/class-mobo-core-checkout-validator.php' ), 'absolute plugin paths become plugin-relative' );
t_ok( false !== strpos( $recent, '/v1/orders/9?[query-removed]' ), 'URL query strings are removed' );
t_ok( false !== strpos( $recent, '"file":"mobo-core/includes/class-mobo-core-api-client.php"' ) || false !== strpos( $recent, '"file":"includes/class-mobo-core-api-client.php"' ) || false !== strpos( $recent, 'class-mobo-core-api-client.php' ), 'file:line kept (relative)' );
t_ok( false !== strpos( $recent, '"itemId":5' ) && false !== strpos( $recent, '"ok":"fine"' ), 'harmless context survives' );
t_ok( false !== strpos( $recent, 'A***ei' ) && false !== strpos( $recent, '"billing_phone":"0***33"' ), 'customer name / phone are masked, not dropped' );
t_ok( false !== strpos( $recent, '"endpoint":"/api/v1/products"' ), 'WP_Error endpoint stored as path only' );
t_ok( false !== strpos( $recent, '"status":500' ), 'WP_Error status kept' );
t_ok( false !== strpos( $recent, 'INSERT INTO wp_mobo_core_map (email, phone) VALUES (?, ?)' ), 'DB error query stored as a literal-free shape' );
t_ok( false !== strpos( $recent, '"correlationId":"req:' ), 'request correlation id attached by default' );
t_same( 'IR', substr( $L::scrub_text( 'IR' ), 0, 2 ), 'short text untouched' );
t_ok( false !== strpos( $L::scrub_text( 'event 3f2504e0-4f89-41d3-9a0c-0305e82c3301 failed' ), '3f2504e0-4f89-41d3-9a0c-0305e82c3301' ), 'UUID event ids are kept for correlation' );
t_ok( false === strpos( $L::scrub_text( '{"securityCode":"ABC-SECRET"}' ), 'ABC-SECRET' ), 'JSON securityCode redacted' );

echo "\n[2] ring buffer bounds and dedupe\n";
$L::clear();
$L::reset_runtime();
/** Unique alphabetic suffix (digits are normalized away by the signature). */
function alpha_id( $n ) { $s = ''; $n++; while ( $n > 0 ) { $n--; $s = chr( 97 + ( $n % 26 ) ) . $s; $n = (int) floor( $n / 26 ); } return $s; }
for ( $i = 0; $i < 1000; $i++ ) {
	$L::capture( 'error', 'ring.test', 'distinct failure ' . alpha_id( $i ), array( 'i' => $i ) );
	if ( 0 === ( $i + 1 ) % 25 ) {
		$L::flush();
	}
}
$L::flush();
$all = $L::recent( 0, '', '', 1000 );
t_ok( count( $all ) <= Mobo_Core_Error_Log::MAX_ENTRIES && count( $all ) >= Mobo_Core_Error_Log::MAX_ENTRIES - Mobo_Core_Error_Log::CHUNK_SIZE, 'after 1000 distinct errors the buffer holds 275..300 entries (got ' . count( $all ) . ')' );
$messages = array_map( static function ( $e ) { return $e['message']; }, $all );
t_ok( in_array( 'distinct failure ' . alpha_id( 999 ), $messages, true ), 'newest entry retained' );
t_ok( ! in_array( 'distinct failure ' . alpha_id( 0 ), $messages, true ), 'oldest entry evicted' );
$bytes = strlen( stored_json() );
t_ok( $bytes < 1000000, 'total stored size < 1 MB (' . $bytes . ' bytes)' );
$index = get_option( Mobo_Core_Error_Log::INDEX_OPTION );
t_same( count( $all ), count( $index['sigs'] ), 'index tracks exactly the stored entries' );
$L::clear();
for ( $i = 0; $i < 50; $i++ ) {
	$L::capture( 'warning', 'dedupe.test', 'product ' . ( 100 + $i ) . ' failed to sync' );
}
$L::flush();
for ( $i = 0; $i < 50; $i++ ) {
	$L::capture( 'warning', 'dedupe.test', 'product ' . ( 500 + $i ) . ' failed to sync' );
}
$L::flush();
$all = $L::recent();
t_same( 1, count( $all ), 'identical errors (numbers normalized) collapse into one entry' );
t_same( 100, $all[0]['count'], '  count accumulates across flushes' );
t_ok( $all[0]['firstSeen'] <= $all[0]['lastSeen'], '  firstSeen <= lastSeen' );
$L::clear();
$big = str_repeat( 'x', 900 );
for ( $i = 0; $i < 300; $i++ ) {
	$L::capture( 'error', 'big.test', 'big payload ' . alpha_id( $i ), array( 'a' => $big, 'b' => $big, 'c' => $big ) );
}
$L::flush();
$max_chunk = 0;
for ( $c = 0; $c < Mobo_Core_Error_Log::CHUNKS; $c++ ) {
	$max_chunk = max( $max_chunk, strlen( (string) json_encode( get_option( Mobo_Core_Error_Log::CHUNK_PREFIX . $c, array() ) ) ) );
}
t_ok( $max_chunk <= Mobo_Core_Error_Log::CHUNK_MAX_BYTES, 'every chunk option stays under ' . Mobo_Core_Error_Log::CHUNK_MAX_BYTES . ' bytes (max ' . $max_chunk . ')' );
t_ok( strlen( stored_json() ) < 1000000, 'oversized contexts keep the whole log under 1 MB' );
for ( $i = 0; $i < 150; $i++ ) {
	$L::record_event( 'webhook', 'evt-' . $i, 'done', 12 );
}
$L::flush();
t_same( Mobo_Core_Error_Log::MAX_EVENTS, count( $L::recent_events() ), 'recent events capped at 100' );
t_same( 'evt-149', $L::recent_events()[0]['id'], '  newest event first' );
$L::clear();
$writes = $GLOBALS['__writes'];
for ( $i = 0; $i < 10; $i++ ) {
	$L::capture( 'error', 'buffer.test', 'buffered ' . alpha_id( $i ) );
}
t_same( $writes, $GLOBALS['__writes'], 'captures are buffered (no option write per capture)' );
$L::on_shutdown();
t_ok( $GLOBALS['__writes'] > $writes && 10 === count( $L::recent() ), 'shutdown persists the buffer in one flush' );
$sum = $L::summary( array( array( 'key' => 'fn_scandir', 'feature' => 'files', 'severity' => 'warning' ) ) );
t_ok( 10 === $sum['errors24h'] && 'buffer.test' === $sum['lastErrorCode'] && 'buffer.test' === $sum['topErrors'][0]['code'] && 10 === $sum['topErrors'][0]['count'], 'summary: errors24h, lastErrorCode, topErrors' );
t_same( array( 'errors24h', 'lastErrorAt', 'lastErrorCode', 'topErrors', 'disabledFunctionsImpact' ), array_keys( $sum ), 'diagnosticsSummary keys' );
t_ok( strlen( (string) json_encode( $sum ) ) < 2048, 'diagnosticsSummary is small' );

echo "\n[3] fatal capture via subprocess\n";
$tmp = str_replace( '\\', '/', sys_get_temp_dir() ) . '/mobo-fatal-' . mt_rand( 100000, 999999 );
@mkdir( $tmp . '/includes', 0777, true );
$boom = $tmp . '/includes/class-mobo-core-boom.php';
file_put_contents( $boom, "<?php\nfunction mobo_test_boom() {\n\tmobo_undefined_function_for_test();\n}\n" );
foreach ( array( 'inside' => $tmp . '/', 'outside' => $tmp . '/elsewhere/' ) as $case => $root ) {
	$opts = $tmp . '/opts-' . $case . '.json';
	file_put_contents( $opts, '{}' );
	$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 ' . escapeshellarg( __FILE__ ) . ' --child-fatal ' . escapeshellarg( $opts ) . ' ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $boom ) . ' 2>&1';
	$lines = array();
	$code  = 0;
	exec( $cmd, $lines, $code );
	$written = (array) json_decode( (string) file_get_contents( $opts ), true );
	$fatal = null;
	foreach ( $written as $k => $v ) {
		if ( 0 === strpos( $k, Mobo_Core_Error_Log::CHUNK_PREFIX ) && is_array( $v ) ) {
			foreach ( $v as $entry ) {
				if ( 'fatal' === ( $entry['level'] ?? '' ) ) {
					$fatal = $entry;
				}
			}
		}
	}
	t_ok( 0 !== $code && false !== stripos( implode( "\n", $lines ), 'undefined function' ), "{$case}: child really died with a fatal error" );
	if ( 'inside' === $case ) {
		t_ok( is_array( $fatal ) && 'php_fatal' === $fatal['code'] && 'class-mobo-core-boom.php' === $fatal['file'] && 3 === $fatal['line'], 'fatal in a plugin file captured with file:line' );
		t_ok( is_array( $fatal ) && 'order:77' === $fatal['cid'], '  correlation id of the failing unit of work attached' );
		t_ok( is_array( $fatal ) && false !== strpos( $fatal['msg'], 'mobo_undefined_function_for_test' ) && false === strpos( $fatal['msg'], $tmp ), '  message kept, absolute path scrubbed' );
	} else {
		t_ok( null === $fatal, 'fatal outside the plugin directory is ignored' );
	}
	@unlink( $opts );
}
@unlink( $boom );
@rmdir( $tmp . '/includes' );
@rmdir( $tmp );

echo "\n[4] correlation + job diagnostics for Sync / Repair / Recategorize\n";
$L::clear();
Mobo_Core_Correlation::reset();
$t = Mobo_Core_Correlation::push( 'webhook', 'evt/<script>1' );
t_same( 'webhook:evtscript1', Mobo_Core_Correlation::current(), 'correlation ids are sanitized' );
$t2 = Mobo_Core_Correlation::push( 'order', 9 );
Mobo_Core_Correlation::pop( $t );
t_ok( 0 === strpos( Mobo_Core_Correlation::current(), 'req:' ), 'pop unwinds nested scopes back to the request id' );
$J = 'Mobo_Core_Job_Diagnostics';
$status = array( 'syncId' => 'SYNC-1', 'status' => 'running', 'repairMode' => false, 'processedProducts' => 5, 'productTotalCount' => 50, 'progressPercent' => 10, 'lastError' => '', 'source' => 'admin' );
$J::observe_manual_sync( $status );
$data = $J::open( 'sync' )->data();
t_ok( 'running' === $data['status'] && 'SYNC-1' === $data['runId'] && 'sync:SYNC-1' === $data['correlationId'] && 5 === $data['processed'], 'sync run observed: runId = syncId, correlation sync:SYNC-1' );
$extras = $J::manual_sync_extras( $status );
t_ok( 0 === strpos( $extras['statusLine'], 'در حال اجرا' ) && 'sync:SYNC-1' === $extras['correlationId'], 'Persian status line for a running sync' );
$status['lastError'] = 'Portal returned HTTP 502 token=LEAKME';
$J::observe_manual_sync( $status );
$L::flush();
$errors = $L::recent( 0, '', 'sync:SYNC-1' );
t_ok( 1 === count( $errors ) && 'job.sync' === $errors[0]['code'] && false === strpos( json_encode( $errors ), 'LEAKME' ), 'sync lastError captured once in the error log under the run correlation id (scrubbed)' );
$J::observe_manual_sync( $status );
$L::flush();
t_same( 1, $L::recent( 0, '', 'sync:SYNC-1' )[0]['count'], '  unchanged error is not re-captured on every observe' );
$repair = array( 'syncId' => 'REP-9', 'status' => 'running', 'repairMode' => true, 'processedProducts' => 0, 'productTotalCount' => 0, 'repairIntegrityComplete' => false, 'repairIntegrityPhase' => 'images', 'repairIntegrityCursor' => 40, 'lastError' => '' );
$J::observe_manual_sync( $repair );
$rdata = $J::open( 'repair' )->data();
t_ok( 'repair:REP-9' === $rdata['correlationId'] && 'integrity_images' === $rdata['step'] && 40 === $rdata['cursor'], 'repair mode observed as job "repair" with its integrity phase' );
/* Stall: no progress for longer than the stall window, cron silent. */
$stored = Mobo_Core_State_Store::get( 'job_diag_sync' );
$stored['lastProgressAt'] = time() - 3600;
Mobo_Core_State_Store::set( 'job_diag_sync', $stored );
Mobo_Core_Self_Runner::$kicks = array();
$tick = $J::generic_watchdog( 'sync', 'running', 'manual_sync', 'cron' );
t_ok( 'stalled' === $tick['health'] && 'cron_not_firing' === $tick['reason'] && 'kicked-worker' === $tick['performed'] && in_array( 'sync-watchdog', Mobo_Core_Self_Runner::$kicks, true ), 'watchdog: stalled sync with silent cron -> worker kicked' );
for ( $i = 0; $i < 6; $i++ ) {
	$tick = $J::generic_watchdog( 'sync', 'running', 'manual_sync', 'cron' );
}
t_same( 'stalled', $J::open( 'sync' )->data()['status'], 'watchdog gives up after MAX_AUTO_RECOVERIES and marks diagnostics stalled' );
t_ok( 0 === strpos( $J::manual_sync_extras( $status )['statusLine'], 'گیر کرده' ), '  status line explains the stall in Persian' );
$status['status'] = 'done';
$status['lastError'] = '';
$J::observe_manual_sync( $status );
t_same( 'done', $J::open( 'sync' )->data()['status'], 'terminal status finishes the run' );
$bundle = $J::job_bundle( 'sync', $status, 'manual_sync' );
t_ok( 'sync' === $bundle['job'] && isset( $bundle['diagnostics']['events'], $bundle['extra']['signals'] ), '«کپی گزارش عیب‌یابی» bundle for sync has events and watchdog signals' );
$hp = $J::health_payload_for( 'recategorize', 'running', 10, 40, 'recategorize_queue_worker' );
t_same( array( 'status', 'progressPct', 'lastProgressAt', 'stalledReason', 'correlationId', 'lastError' ), array_keys( $hp ), 'jobs.<name> health payload keys' );
t_same( 25.0, $hp['progressPct'], '  progress percent' );

echo "\n[5] bundle contract and 512 KB cap\n";
$req = Mobo_Core_Diagnostics_Bundle::normalize_request( array( 'sections' => array( 'errors', 'bogus', 'errors', 'cron' ), 'sinceMinutes' => 999999 ) );
t_same( array( 'errors', 'cron' ), $req['sections'], 'unknown/duplicate sections dropped' );
t_same( Mobo_Core_Diagnostics_Bundle::MAX_SINCE_MINUTES, $req['sinceMinutes'], 'sinceMinutes clamped' );
t_same( Mobo_Core_Diagnostics_Bundle::SECTIONS, Mobo_Core_Diagnostics_Bundle::normalize_request( array() )['sections'], 'empty body -> every section' );
$synthetic = array( 'generatedAt' => 'x', 'truncated' => false, 'errors' => array(), 'events' => array() );
for ( $i = 0; $i < 2000; $i++ ) {
	$synthetic['errors'][] = array( 'message' => 'err-' . $i . '-' . str_repeat( 'm', 400 ) );
	$synthetic['events'][] = array( 'id' => 'evt-' . $i, 'pad' => str_repeat( 'e', 100 ) );
}
$capped = Mobo_Core_Diagnostics_Bundle::cap( $synthetic );
t_ok( $capped['sizeBytes'] <= Mobo_Core_Diagnostics_Bundle::MAX_BYTES && Mobo_Core_Diagnostics_Bundle::size( $capped ) <= Mobo_Core_Diagnostics_Bundle::MAX_BYTES, 'oversized bundle capped at 512 KB (' . $capped['sizeBytes'] . ' bytes)' );
t_ok( true === $capped['truncated'] && 'err-0-' === substr( $capped['errors'][0]['message'], 0, 6 ) && count( $capped['errors'] ) < 2000, '  oldest entries dropped first (newest-first lists keep their head)' );
$L::clear();
for ( $i = 0; $i < 200; $i++ ) {
	$L::capture( 'error', 'cap.test', 'cap ' . alpha_id( $i ) . ' ' . str_repeat( 'z', 300 ) );
}
$small = Mobo_Core_Diagnostics_Bundle::build( array( 'errors', 'events' ), 60, 20000 );
t_ok( $small['sizeBytes'] <= 20000 && true === $small['truncated'] && count( $small['errors'] ) > 0, 'build() honours the byte cap and still returns the newest errors' );

/* Full contract with the default sections. */
$full = Mobo_Core_Diagnostics_Bundle::build( Mobo_Core_Diagnostics_Bundle::SECTIONS, 1440 );
$missing = array();
foreach ( array( 'generatedAt', 'plugin', 'environment', 'readiness', 'jobs', 'errors', 'events', 'queues', 'cron', 'integrity' ) as $key ) {
	if ( ! array_key_exists( $key, $full ) ) { $missing[] = $key; }
}
foreach ( array( 'version', 'dbVersion', 'migration' ) as $key ) { if ( ! array_key_exists( $key, $full['plugin'] ) ) { $missing[] = 'plugin.' . $key; } }
foreach ( array( 'php', 'sapi', 'limits', 'capabilities', 'wp', 'wc', 'theme', 'activePlugins', 'cachePlugins', 'objectCache', 'dbServer', 'charset' ) as $key ) { if ( ! array_key_exists( $key, (array) $full['environment'] ) ) { $missing[] = 'environment.' . $key; } }
foreach ( array( 'sync', 'repair', 'reprice', 'recategorize', 'migration', 'imageRefresh' ) as $key ) { if ( ! array_key_exists( $key, (array) $full['jobs'] ) ) { $missing[] = 'jobs.' . $key; } }
foreach ( array( 'webhook', 'image', 'order', 'priceReview' ) as $key ) { if ( ! array_key_exists( $key, (array) $full['queues'] ) ) { $missing[] = 'queues.' . $key; } }
foreach ( array( 'lastRun', 'realCron', 'selfRunner', 'loopback', 'lease' ) as $key ) { if ( ! array_key_exists( $key, (array) $full['cron'] ) ) { $missing[] = 'cron.' . $key; } }
foreach ( array( 'manifestOk', 'changedFiles' ) as $key ) { if ( ! array_key_exists( $key, (array) $full['integrity'] ) ) { $missing[] = 'integrity.' . $key; } }
t_same( array(), $missing, 'bundle carries every contract key' );
t_ok( false === strpos( json_encode( $full ), 'SHOULD-NOT-LEAK-TOKEN' ), 'self-runner status is sanitized (no worker token)' );
/* Integrity changed files. */
$root = str_replace( '\\', '/', sys_get_temp_dir() ) . '/mobo-manifest-' . mt_rand( 100000, 999999 ) . '/';
@mkdir( $root, 0777, true );
file_put_contents( $root . 'a.php', 'A' );
file_put_contents( $root . 'b.php', 'B-modified' );
file_put_contents( $root . 'mobo-core-manifest.json', json_encode( array( 'version' => '1', 'files' => array( 'a.php' => hash( 'sha256', 'A' ), 'b.php' => hash( 'sha256', 'B' ), 'c.php' => hash( 'sha256', 'C' ) ) ) ) );
$changed = Mobo_Core_Diagnostics_Bundle::changed_files( $root, '1', 100, true );
t_same( array( array( 'path' => 'b.php', 'status' => 'modified' ), array( 'path' => 'c.php', 'status' => 'missing' ) ), $changed, 'integrity.changedFiles lists modified and missing manifest files' );
@unlink( $root . 'a.php' ); @unlink( $root . 'b.php' ); @unlink( $root . 'mobo-core-manifest.json' ); @rmdir( $root );

echo "\n[6] POST /support/diagnostics requires installation-level auth\n";
class Test_Rest_Controller extends Mobo_Core_Rest_Controller {
	public $auth = true;
	public function check_security( $request ) { return true === $this->auth ? true : new WP_Error( 'mobo_core_unauthorized', 'Unauthorized.', array( 'status' => 401 ) ); }
}
class Test_Request {
	private $body;
	public function __construct( $body ) { $this->body = $body; }
	public function get_json_params() { return $this->body; }
	public function get_body_params() { return array(); }
	public function get_route() { return '/mobo-core/v1/support/diagnostics'; }
	public function get_method() { return 'POST'; }
}
$controller = new Test_Rest_Controller();
$controller->register_routes();
$route = $GLOBALS['__routes']['mobo-core/v1/support/diagnostics'] ?? null;
t_ok( is_array( $route ) && 'POST' === $route['methods'], 'route registered (POST)' );
t_ok( is_array( $route ) && array( $controller, 'check_installation_security' ) === $route['permission_callback'], 'permission callback is check_installation_security (not a Connection X-SEC check)' );
$controller->auth = false;
$denied = $controller->check_installation_security( new Test_Request( array() ) );
t_ok( is_wp_error( $denied ) && 'mobo_core_unauthorized' === $denied->get_error_code(), 'unauthenticated request rejected' );
$controller->auth = true;
Mobo_Core_Connection_Repository::$rows = array( 1 => array( 'id' => 1, 'is_enabled' => 1, 'is_default' => 1 ), 2 => array( 'id' => 2, 'is_enabled' => 1, 'is_default' => 0 ) );
Mobo_Core_Connection_Repository::$default = 1;
Mobo_Core_Connection_Context::$current = 2;
$scoped = $controller->check_installation_security( new Test_Request( array() ) );
t_ok( is_wp_error( $scoped ) && 'mobo_core_installation_scope_forbidden' === $scoped->get_error_code(), 'a non-controller Source Connection (valid X-SEC) is forbidden' );
Mobo_Core_Connection_Context::$current = 1;
t_same( true, $controller->check_installation_security( new Test_Request( array() ) ), 'installation controller Connection is allowed' );
$response = $controller->get_support_diagnostics( new Test_Request( array( 'sections' => array( 'errors' ), 'sinceMinutes' => 30 ) ) );
t_ok( is_array( $response ) && array( 'errors' ) === $response['sections'] && 30 === $response['sinceMinutes'] && isset( $response['plugin']['version'] ) && ! isset( $response['cron'] ), 'handler returns only the requested sections' );
$errors_route = $GLOBALS['__routes']['mobo-core/v1/health'] ?? null;
t_ok( is_array( $errors_route ) && array( $controller, 'check_installation_security' ) === $errors_route['permission_callback'], 'same auth as the other Backend support routes (health)' );

t_done();
