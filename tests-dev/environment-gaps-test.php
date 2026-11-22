<?php
/**
 * Regression tests for environment gaps found before 10.34.77:
 *  [1] mobo-cron.php exits with a clean 503 when WooCommerce is inactive;
 *  [2] rest_authentication_errors: security plugins blocking REST are bypassed
 *      only for this plugin's Backend routes with a valid machine credential;
 *  [3] revenue ledger ownership backfill finds HPOS-only orders (sync off);
 *  [4] health: heartbeat-only sites are not "cron not detected";
 *  [5] UTC date handling independent of the PHP default timezone;
 *  [6] no implicitly nullable parameters (PHP 8.4 deprecation);
 *  [7] delta failure path never acknowledges an event whose recovery
 *      evidence could not be written; memory helpers.
 *
 * Run: php tests-dev/environment-gaps-test.php   (PHP 7.4 and 8.x)
 */

$plugin_dir = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/wp-content/plugins/mobo-core/';
$inc        = $plugin_dir . 'includes/';
$mode       = isset( $argv[1] ) ? (string) $argv[1] : '';

/* ====================================================================== */
/* Child: run the real mobo-cron.php with/without WooCommerce.            */
if ( '--child-cron' === $mode ) {
	$wc = isset( $argv[2] ) && 'wc' === $argv[2];
	/* absint, sanitize_key, get_option, wp_json_encode: the file-level stubs below
	 * are hoisted by PHP and shared with this child. */
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['t_options'] = array();
	function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
	final class Mobo_Core_Cron_Runner {
		public static function record_real_cron_hit() { echo ''; }
		public function run() { return array( 'success' => true, 'status' => 'ok', 'ranWorker' => true ); }
	}
	final class Mobo_Core_Dependencies {
		public static function requirements_met() { return (bool) $GLOBALS['__wc']; }
	}
	$GLOBALS['__wc'] = $wc;
	if ( $wc ) {
		function WC() { return true; } // phpcs:ignore
		function wc_get_product() { return null; }
	}
	require $GLOBALS['plugin_dir'] . 'mobo-cron.php';
	exit( 9 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['t_options']  = array();
$GLOBALS['t_meta']     = array();
$GLOBALS['t_failures'] = 0;
$GLOBALS['t_checks']   = 0;
$GLOBALS['t_user']     = 5;
$GLOBALS['t_update_ok'] = true;

class WP_Error {
	private $c;
	public function __construct( $c = '', $m = '', $d = '' ) { $this->c = $c; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->c; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function rest_get_url_prefix() { return 'wp-json'; }
function rest_url( $p = '' ) { return 'https://shop.test/wp-json/' . ltrim( $p, '/' ); }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function current_time( $t, $g = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { if ( ! $GLOBALS['t_update_ok'] ) { return false; } $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_set_current_user( $id ) { $GLOBALS['t_user'] = (int) $id; }
function get_post_meta( $id, $k, $s = false ) { return isset( $GLOBALS['t_meta'][ $id ][ $k ] ) ? $GLOBALS['t_meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['t_meta'][ $id ][ $k ] = $v; return true; }
function maybe_serialize( $d ) { return ( is_array( $d ) || is_object( $d ) ) ? serialize( $d ) : $d; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }

function t_check( $cond, $label ) {
	$GLOBALS['t_checks']++;
	echo ( $cond ? '  ok   - ' : '  FAIL - ' ) . $label . "\n";
	if ( ! $cond ) { $GLOBALS['t_failures']++; }
}
function t_same( $e, $a, $label ) { t_check( $e === $a, $label . ( $e === $a ? '' : ' (expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) . ')' ) ); }

final class Mobo_Core_Settings {
	public static function get_int( $k, $d, $min = 0, $max = PHP_INT_MAX ) { return (int) $d; }
	public static function get( $k, $d = '' ) { return get_option( $k, $d ); }
	public static function update( $k, $v, $a = false ) { return update_option( $k, $v, $a ); }
	public static function enabled( $k, $d = '0' ) { return '1' === (string) get_option( $k, $d ); }
	public static function normalize_opaque_secret( $v, $max = 191 ) { $v = trim( (string) $v ); return strlen( $v ) <= $max ? $v : ''; }
}
final class Mobo_Core_Connection_Repository {
	public static $calls = 0;
	public static function table_exists() { return true; }
	public static function authenticate_inbound( $webhook_id, $provided ) { self::$calls++; return 'good-sec' === $provided ? array( 'id' => 1 ) : new WP_Error( 'mobo_core_security_invalid' ); }
	public static function get_by_license_token( $t ) { return 'lic-1' === $t ? array( 'id' => 1 ) : null; }
	public static function list_all() { return array( array( 'id' => 1 ) ); }
}
final class WP_REST_Server { public static $raw = ''; public static function get_raw_data() { return self::$raw; } }

require $inc . 'class-mobo-core-environment.php';

/* ====================================================================== */
echo "[1] WooCommerce inactive: mobo-cron.php\n";
if ( Mobo_Core_Environment::can( 'exec' ) ) {
	$run = static function ( $wc ) {
		$out = array();
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child-cron ' . ( $wc ? 'wc' : 'nowc' ) . ' 2>&1', $out, $code );
		return array( $code, implode( "\n", $out ) );
	};
	$GLOBALS['plugin_dir'] = $plugin_dir;
	list( $code, $out ) = $run( false );
	$json = json_decode( $out, true );
	t_check( is_array( $json ) && 'dependency-missing' === ( $json['status'] ?? '' ), 'WC inactive => JSON status dependency-missing, no fatal' . ( is_array( $json ) ? '' : ' (' . substr( $out, 0, 300 ) . ')' ) );
	t_check( false === stripos( $out, 'Fatal' ) && 1 === $code, '  exits cleanly (exit code 1, no PHP fatal)' );
	list( $code, $out ) = $run( true );
	$json = json_decode( $out, true );
	t_check( is_array( $json ) && ! empty( $json['ranWorker'] ), 'WC active => worker runs' );
} else {
	t_check( true, 'exec() disabled on this run: child cron scenario skipped (covered on the normal run)' );
}
$cron_src = (string) file_get_contents( $plugin_dir . 'mobo-cron.php' );
t_check( false !== strpos( $cron_src, "function_exists( 'wc_get_product' )" ) && false !== strpos( $cron_src, "'dependency-missing'" ), 'cron entry checks WooCommerce functions before running the worker' );

/* ====================================================================== */
echo "\n[2] REST blocked by security plugins: narrow bypass\n";
require $inc . 'class-mobo-core-rest-controller.php';
$R = 'Mobo_Core_Rest_Controller';
$block = new WP_Error( 'rest_disabled', 'REST API disabled by security plugin' );
$GLOBALS['wp'] = (object) array( 'query_vars' => array() );
$set = static function ( $route, $headers = array(), $get = array() ) {
	$GLOBALS['wp']->query_vars['rest_route'] = $route;
	foreach ( array_keys( $_SERVER ) as $k ) { if ( 0 === strpos( $k, 'HTTP_' ) ) { unset( $_SERVER[ $k ] ); } }
	foreach ( $headers as $k => $v ) { $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $k ) ) ] = $v; }
	$_REQUEST = $get;
	$GLOBALS['t_user'] = 5;
};
t_same( 'xsec', $R::backend_route_credential_kind( '/mobo-core/v1/health' ), 'health is an X-SEC Backend route' );
t_same( 'cron', $R::backend_route_credential_kind( '/mobo-core/v1/cron/run/' ), 'cron/run uses the cron token' );
t_same( '', $R::backend_route_credential_kind( '/mobo-core/v1/support/login' ), 'browser support/login is never bypassed' );
t_same( '', $R::backend_route_credential_kind( '/wp/v2/users' ), 'core routes are never bypassed' );
$set( '/mobo-core/v1/health', array( 'X-SEC' => 'good-sec' ) );
t_same( true, $R::filter_rest_authentication_errors( true ), 'an authenticated request is left alone' );
t_same( null, $R::filter_rest_authentication_errors( null ), 'no error => untouched' );
t_same( null, $R::filter_rest_authentication_errors( $block ), 'blocked Backend route with a valid X-SEC passes through' );
t_same( 0, $GLOBALS['t_user'], '  and never runs as a cookie user' );
$set( '/mobo-core/v1/health', array( 'X-SEC' => 'wrong' ) );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'invalid X-SEC keeps the security plugin error' );
$set( '/mobo-core/v1/health' );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'missing credential keeps the error' );
$set( '/wp/v2/users', array( 'X-SEC' => 'good-sec' ) );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'valid X-SEC does not open other routes' );
$set( '/mobo-core/v1/support/login', array( 'X-SEC' => 'good-sec' ) );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'valid X-SEC does not open support/login' );
$GLOBALS['t_options']['mobo_core_cron_token'] = 'cron-secret';
$set( '/mobo-core/v1/cron/run', array(), array( 'token' => 'cron-secret' ) );
t_same( null, $R::filter_rest_authentication_errors( $block ), 'cron/run with the valid cron token passes through' );
$set( '/mobo-core/v1/cron/run', array(), array( 'token' => 'nope' ) );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'wrong cron token keeps the error' );
$set( '/mobo-core/v1/cron/run', array( 'X-SEC' => 'good-sec' ) );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'cron route does not accept a Connection X-SEC instead of the cron token' );
$ts = (string) time();
WP_REST_Server::$raw = '{"token":"lic-1","x":1}';
$sig = hash_hmac( 'sha256', $ts . "\nn1\n" . WP_REST_Server::$raw, 'lic-1' );
$set( '/mobo-core/v1/connection/provision', array( 'X-Mobo-Provision-Timestamp' => $ts, 'X-Mobo-Provision-Nonce' => 'n1', 'X-Mobo-Provision-Signature' => $sig ) );
t_same( null, $R::filter_rest_authentication_errors( $block ), 'provision with a valid License-Token HMAC passes through' );
$set( '/mobo-core/v1/connection/provision', array( 'X-Mobo-Provision-Timestamp' => (string) ( time() - 900 ), 'X-Mobo-Provision-Nonce' => 'n1', 'X-Mobo-Provision-Signature' => $sig ) );
t_check( $block === $R::filter_rest_authentication_errors( $block ), 'expired provisioning signature keeps the error' );
unset( $GLOBALS['wp'] );
$_GET['rest_route'] = '/mobo-core/v1/heartbeat';
$set2 = $_SERVER;
$_SERVER['HTTP_X_SEC'] = 'good-sec';
t_same( null, $R::filter_rest_authentication_errors( $block ), 'plain permalinks (?rest_route=) are recognised' );
unset( $_GET['rest_route'] );
$_SERVER['REQUEST_URI'] = '/shop/wp-json/mobo-core/v1/webhook?x=1';
t_same( null, $R::filter_rest_authentication_errors( $block ), 'pretty permalinks in a sub-directory install are recognised' );
$rest_src = (string) file_get_contents( $inc . 'class-mobo-core-rest-controller.php' );
t_check( false !== strpos( $rest_src, "add_filter( 'rest_authentication_errors', array( __CLASS__, 'filter_rest_authentication_errors' ), 999 )" ), 'filter registered late (after security plugins)' );

/* ====================================================================== */
echo "\n[3] revenue ledger backfill with HPOS on and compatibility sync off\n";
require __DIR__ . '/sqlite-wpdb-stub.php';
$wpdb = new Sqlite_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->pdo->exec( 'CREATE TABLE wp_wc_orders_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, meta_key TEXT, meta_value TEXT)' );
eval( 'namespace Automattic\\WooCommerce\\Utilities; final class OrderUtil { public static function custom_orders_table_usage_is_enabled() { return ! empty( $GLOBALS["t_hpos"] ); } }' );
class WC_Order {
	public $id; public $meta = array();
	public function __construct( $id ) { $this->id = $id; }
	public function get_meta( $k, $single = true ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function get_items( $t ) { return array(); }
	public function save() { $GLOBALS['t_orders'][ $this->id ] = $this; return $this->id; }
}
function wc_get_order( $id ) { return isset( $GLOBALS['t_orders'][ $id ] ) ? clone $GLOBALS['t_orders'][ $id ] : false; }
require $inc . 'class-mobo-core-revenue-ledger.php';
$RL = 'Mobo_Core_Revenue_Ledger';
$GLOBALS['t_hpos'] = true;
$order = new WC_Order( 501 );
$order->meta[ $RL::META_LEDGER_RECORD ]  = array( 'schemaVersion' => $RL::LEDGER_VERSION, 'orderId' => 501, 'total' => '1000' );
$order->meta[ $RL::META_LEDGER_VERSION ] = (string) $RL::LEDGER_VERSION;
$GLOBALS['t_orders'] = array( 501 => $order );
/* HPOS-only evidence: nothing in wp_postmeta. */
$wpdb->insert( 'wp_wc_orders_meta', array( 'order_id' => 501, 'meta_key' => $RL::META_LEDGER_VERSION, 'meta_value' => (string) $RL::LEDGER_VERSION ) );
$wpdb->insert( 'wp_wc_orders_meta', array( 'order_id' => 501, 'meta_key' => $RL::META_LEDGER_RECORD, 'meta_value' => serialize( $order->meta[ $RL::META_LEDGER_RECORD ] ) ) );
t_same( 'wp_wc_orders_meta', $RL::hpos_meta_table(), 'HPOS meta table resolved when HPOS is authoritative' );
$ledger = ( new ReflectionClass( $RL ) )->newInstanceWithoutConstructor();
$first  = $ledger->backfill_legacy_connection_ownership( 100 );
t_same( 0, $first['inspected'], 'phase 1 (wp_postmeta) finds nothing for HPOS-only orders...' );
t_same( 1, $first['remaining'], '  ...and does not report completion' );
$second = $ledger->backfill_legacy_connection_ownership( 100 );
t_same( 1, $second['inspected'], 'phase 2 walks the HPOS meta table' );
t_same( 1, $second['updated'], '  HPOS-only order receives its Connection owner' );
t_same( 1, absint( $GLOBALS['t_orders'][501]->meta[ $RL::META_CONNECTION_ID ] ?? 0 ), '  via WooCommerce CRUD' );
t_same( '1000', $GLOBALS['t_orders'][501]->meta[ $RL::META_LEDGER_RECORD ]['total'], '  financial values untouched' );
t_same( 0, $second['remaining'], 'backfill reports completion after the HPOS phase' );
$GLOBALS['t_hpos'] = false;
t_same( '', $RL::hpos_meta_table(), 'HPOS off => legacy wp_postmeta discovery only' );
$sql = $RL::backfill_candidate_sql( 'hpos', 'wp_wc_orders_meta; DROP TABLE x' );
t_check( false === strpos( $sql['select'], ';' ), 'HPOS table name is sanitized in SQL' );
/* envA T12 (HPOS off): the CPT order store drops a nested meta_query (and logs
 * _doing_it_wrong), so the summary must use the meta_key/meta_value shortcut that
 * both stores honour, otherwise every shop_order is loaded in one REST request. */
$qa = $RL::summary_query_args( 200, 3 );
t_check( ! isset( $qa['meta_query'] ), 'revenue summary query has no nested meta_query (ignored by the CPT store)' );
t_same( $RL::META_LEDGER_VERSION, $qa['meta_key'] ?? null, '  filters on the ledger version meta key' );
t_same( (string) $RL::LEDGER_VERSION, $qa['meta_value'] ?? null, '  ...and value' );
t_same( array( 200, 3, 'ids', 'shop_order' ), array( $qa['limit'], $qa['page'], $qa['return'], $qa['type'] ), '  paged, ids only, orders only' );

/* ====================================================================== */
echo "\n[4] cron health on heartbeat-only sites\n";
require $inc . 'class-mobo-core-cron-runner.php';
$C = 'Mobo_Core_Cron_Runner';
$now = time();
t_same( 'portal_heartbeat', $C::classify_cron_mode( 0, 0, $now - 60, 'portal-heartbeat', $now ), 'heartbeat-only site => portal_heartbeat (was not_detected)' );
t_same( 'other_driver', $C::classify_cron_mode( 0, 0, $now - 60, 'worker-run', $now ), 'other driver recognised' );
t_same( 'not_detected', $C::classify_cron_mode( 0, 0, $now - 7200, 'portal-heartbeat', $now ), 'stale driver => not_detected' );
t_same( 'real_cron', $C::classify_cron_mode( 0, $now - 60, $now - 60, 'portal-heartbeat', $now ), 'real cron still reported as real_cron' );
$C::record_driver_run( 'portal-heartbeat' );
$st = $C::get_status();
t_check( ! empty( $st['driverActive'] ) && 'portal-heartbeat' === $st['lastDriverSource'] && 0 === $st['lastHitAt'], 'get_status() exposes the driver run while lastHitAt stays real-cron only' );
$runner_src = (string) file_get_contents( $inc . 'class-mobo-core-cron-runner.php' );
t_check( false !== strpos( $runner_src, 'self::record_driver_run( $source );' ), 'every runner start records its driver' );
$health_src = (string) file_get_contents( $inc . 'class-mobo-core-health-reporter.php' );
t_check( false !== strpos( $health_src, 'classify_cron_mode(' ) && false !== strpos( $health_src, "\$last_cron_hit : absint( \$cron_status['lastDriverRunAt'] ?? 0 )" ), 'health report uses the driver run for cronMode and lastCronHitAt' );

/* ====================================================================== */
echo "\n[5] UTC date handling under a non-UTC PHP timezone\n";
require $inc . 'class-mobo-core-iran-date.php';
require $inc . 'class-mobo-core-network-resilience.php';
$tz = date_default_timezone_get();
date_default_timezone_set( 'UTC' );
$utc_label = Mobo_Core_Iran_Date::format_value( '2026-01-01 00:00:00', 'Y/m/d H:i' );
date_default_timezone_set( 'Asia/Tehran' );
$teh_label = Mobo_Core_Iran_Date::format_value( '2026-01-01 00:00:00', 'Y/m/d H:i' );
t_same( $utc_label, $teh_label, 'stored UTC DATETIME renders the same whatever date_default_timezone_set() a plugin chose' );
t_same( 1767225600, Mobo_Core_Environment::utc_strtotime( '2026-01-01 00:00:00' ), 'utc_strtotime(): bare DATETIME is UTC' );
t_same( 1767225600, Mobo_Core_Environment::utc_strtotime( 'Thu, 01 Jan 2026 00:00:00 GMT' ), 'utc_strtotime(): HTTP date unchanged' );
t_same( false, Mobo_Core_Environment::utc_strtotime( '' ), 'utc_strtotime(): empty => false' );
$retry = Mobo_Core_Network_Resilience::parse_retry_after( gmdate( 'Y-m-d H:i:s', time() + 120 ) );
t_check( $retry >= 110 && $retry <= 130, 'Retry-After date without zone parsed as UTC (got ' . $retry . ' s, would be ~3.5 h off)' );
date_default_timezone_set( $tz );

/* ====================================================================== */
echo "\n[6] PHP 8.4: no implicitly nullable typed parameters\n";
$offenders = array();
$files = array_merge( array( $plugin_dir . 'mobo-core.php', $plugin_dir . 'mobo-cron.php', $plugin_dir . 'uninstall.php' ), glob( $inc . '*.php' ), glob( $inc . '*/*.php' ) );
foreach ( $files as $file ) {
	$code = (string) file_get_contents( $file );
	if ( preg_match_all( '/function\s+&?\w*\s*\(([^)]*)\)/', $code, $m ) ) {
		foreach ( $m[1] as $params ) {
			if ( preg_match( '/(?<![?\w\\\\|])(?:[A-Z][\w\\\\]*|array|string|int|float|bool|callable|iterable|object)\s+&?\$\w+\s*=\s*null\b/i', $params ) && ! preg_match( '/\?\s*[\w\\\\]+\s+&?\$\w+\s*=\s*null/', $params ) ) {
				$offenders[] = basename( $file ) . ': ' . trim( preg_replace( '/\s+/', ' ', $params ) );
			}
		}
	}
}
t_same( array(), $offenders, 'no `Type $x = null` signatures (implicit nullable is deprecated in PHP 8.4)' );

/* ====================================================================== */
echo "\n[7] failed writes are never acknowledged; memory helpers\n";
require $inc . 'class-mobo-core-product-sync.php';
$ps  = ( new ReflectionClass( 'Mobo_Core_Product_Sync' ) )->newInstanceWithoutConstructor();
$req = new ReflectionMethod( 'Mobo_Core_Product_Sync', 'record_delta_full_snapshot_request' );
$req->setAccessible( true );
$GLOBALS['t_options'] = array();
t_same( true, $req->invoke( $ps, 'guid-1', 0, 'delta_write_failed' ), 'snapshot request persisted => true' );
$GLOBALS['t_update_ok'] = false;
t_same( false, $req->invoke( $ps, 'guid-2', 0, 'delta_write_failed' ), 'snapshot request NOT persisted (disk full) => false' );
t_same( true, $req->invoke( $ps, 'guid-1', 0, 'delta_write_failed' ), 'unchanged value already stored => true' );
$GLOBALS['t_update_ok'] = true;
$ps_src = (string) file_get_contents( $inc . 'class-mobo-core-product-sync.php' );
t_check( 1 === preg_match( "/delta_write_failed_unrecorded'/", $ps_src ) && false !== strpos( $ps_src, "'deleteFile' => false, 'productGuid' => \$guid, 'productId' => \$product_id, 'error' => 'delta_write_failed_unrecorded'" ), 'delta write failure without durable recovery markers is a retryable failure (deleteFile=false), not a success' );
t_check( false !== strpos( $ps_src, "if ( \$marked && \$requested ) {\n\t\t\t\t\t/* Clear the crash marker only once its replacement evidence is durable. */" ), 'interrupted-delta sweep keeps its crash marker until the replacement evidence is durable' );
$E = 'Mobo_Core_Environment';
t_check( $E::memory_limit_bytes() >= 0, 'memory_limit_bytes() never fails' );
t_check( $E::memory_headroom_bytes() > 0, 'memory_headroom_bytes() positive (PHP_INT_MAX when unmeasurable)' );
$E::simulate_disabled( array( 'ini_get', 'memory_get_usage' ) );
t_same( PHP_INT_MAX, $E::memory_headroom_bytes(), 'memory helpers degrade safely with ini_get/memory_get_usage disabled' );
$E::simulate_disabled( array() );

echo "\n" . $GLOBALS['t_checks'] . ' checks, ' . $GLOBALS['t_failures'] . ' failure(s) on PHP ' . PHP_VERSION . "\n";
exit( $GLOBALS['t_failures'] > 0 ? 1 : 0 );
