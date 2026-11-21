<?php
/**
 * Standalone harness for the 30-second-host runtime hardening:
 * request deadline math (REQUEST_TIME_FLOAT), dispatcher lease TTL / shutdown
 * release / safe takeover, storefront bootstrap decision, paged Sync Health scan,
 * Product Map seed completeness short-cut and the entity-fence negative cache.
 *
 * No WordPress, no database, no network. Run with:
 *   php tests-dev/runtime-deadline-test.php
 *
 * The lease-on-fatal case spawns a child PHP process that claims the dispatcher
 * and is then killed by max_execution_time; the parent verifies the shutdown
 * handler released the lease. PHP 7.4 compatible.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MOBO_CORE_VERSION', '10.34.77' );

$GLOBALS['__child_mode'] = isset( $argv[1] ) && '--child-claim-then-fatal' === $argv[1];
$GLOBALS['__child_oom']  = isset( $argv[1] ) && '--child-runner-lock-then-oom' === $argv[1];
if ( $GLOBALS['__child_oom'] ) { $GLOBALS['__child_mode'] = true; }
$GLOBALS['__store_file'] = $GLOBALS['__child_mode'] ? (string) $argv[2] : '';
$GLOBALS['__store']      = array( 'raw' => array(), 'opt' => array() );
if ( '' !== $GLOBALS['__store_file'] && is_file( $GLOBALS['__store_file'] ) ) {
	$GLOBALS['__store'] = json_decode( (string) file_get_contents( $GLOBALS['__store_file'] ), true );
}
function store_save() {
	if ( '' !== $GLOBALS['__store_file'] ) {
		file_put_contents( $GLOBALS['__store_file'], json_encode( $GLOBALS['__store'] ) );
	}
}

/* ---------------- WordPress stubs ---------------- */
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['__store']['opt'] ) ? $GLOBALS['__store']['opt'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['__store']['opt'][ $name ] = $value; store_save(); return true; }
function add_option( $name, $value, $dep = '', $autoload = null ) { if ( array_key_exists( $name, $GLOBALS['__store']['opt'] ) ) { return false; } return update_option( $name, $value ); }
function delete_option( $name ) { unset( $GLOBALS['__store']['opt'][ $name ] ); store_save(); return true; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $v ) ) ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-a%03x-%012x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xffffffffffff ) ); }
$GLOBALS['__wp_cache'] = array();
function wp_cache_get( $k, $g = '' ) { return isset( $GLOBALS['__wp_cache'][ $g ][ $k ] ) ? $GLOBALS['__wp_cache'][ $g ][ $k ] : false; }
function wp_cache_set( $k, $v, $g = '' ) { $GLOBALS['__wp_cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['__wp_cache'][ $g ][ $k ] ); return true; }
function delete_transient( $k ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
$GLOBALS['__slow_meta'] = array(); // product id => seconds a meta read takes (deadline-per-item tests)
function get_post_meta( $id, $key = '', $single = false ) {
	if ( isset( $GLOBALS['__slow_meta'][ $id ] ) ) { $until = microtime( true ) + $GLOBALS['__slow_meta'][ $id ]; while ( microtime( true ) < $until ) { /* busy wait: usleep may be disabled */ } }
	return '';
}
function update_meta_cache( $type, $ids ) { return true; }
function has_action( $tag ) { return false; }
function sanitize_textarea_field( $v ) { return (string) $v; }
function get_post_type( $id ) { return 'product'; }

/* Fake $wpdb: prepare() keeps SQL and args structured so the fake can answer. */
class Fake_WPDB {
	public $options = 'wp_options';
	public $posts = 'wp_posts';
	public $prefix = 'wp_';
	public $last_error = '';
	public $map_rows = array();
	public $page_queries = 0;
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		return '@@' . json_encode( array( 'sql' => $sql, 'args' => $args ) );
	}
	public function esc_like( $v ) { return $v; }
	private function decode( $q ) {
		if ( 0 === strpos( $q, '@@' ) ) { return json_decode( substr( $q, 2 ), true ); }
		return array( 'sql' => $q, 'args' => array() );
	}
	public function get_var( $q ) {
		$d = $this->decode( $q );
		if ( false !== strpos( $d['sql'], 'SELECT option_value FROM wp_options WHERE option_name = %s' ) ) {
			$raw = $GLOBALS['__store']['raw'];
			return array_key_exists( $d['args'][0], $raw ) ? $raw[ $d['args'][0] ] : null;
		}
		return null; // SHOW TABLES LIKE ... => table missing.
	}
	public function query( $q ) {
		$d = $this->decode( $q );
		$s = $d['sql'];
		$a = $d['args'];
		$seed = $this->seed_query( $d );
		if ( null !== $seed ) { return $seed; }
		$raw = &$GLOBALS['__store']['raw'];
		if ( 0 === strpos( $s, 'INSERT IGNORE INTO wp_options' ) ) {
			if ( array_key_exists( $a[0], $raw ) ) { return 0; }
			$raw[ $a[0] ] = $a[1];
			store_save();
			return 1;
		}
		if ( 0 === strpos( $s, 'UPDATE wp_options SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s' ) ) {
			if ( ! array_key_exists( $a[2], $raw ) || $raw[ $a[2] ] !== $a[3] ) { return 0; }
			$raw[ $a[2] ] = $a[0];
			store_save();
			return 1;
		}
		if ( 0 === strpos( $s, 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s' ) ) {
			if ( ! array_key_exists( $a[0], $raw ) || $raw[ $a[0] ] !== $a[1] ) { return 0; }
			unset( $raw[ $a[0] ] );
			store_save();
			return 1;
		}
		return 0;
	}
	public function get_results( $q, $output = null ) {
		$d = $this->decode( $q );
		if ( false !== strpos( $d['sql'], 'SELECT m.id AS map_id' ) ) {
			$this->page_queries++;
			list( $cid, $cursor, $limit ) = $d['args'];
			$out = array();
			foreach ( $this->map_rows as $row ) {
				if ( (int) $row['map_id'] > (int) $cursor && count( $out ) < (int) $limit ) { $out[] = $row; }
			}
			return $out;
		}
		return array();
	}
	/* Legacy seed fixtures: post_id => guid, and GUIDs already in the map. */
	public $legacy_products = array();
	public $mapped_guids = array();
	public $last_result = array();
	public function get_col( $q ) {
		$d = $this->decode( $q );
		if ( false !== strpos( $d['sql'], 'SELECT remote_guid FROM wp_mobo_product_map WHERE connection_id=%d AND object_type=%s AND remote_guid IN' ) ) {
			return array_values( array_intersect( array_slice( $d['args'], 2 ), $this->mapped_guids ) );
		}
		return array();
	}
	public function seed_query( $d ) {
		if ( false !== strpos( $d['sql'], "pm.meta_key = 'product_guid'" ) ) {
			list( $cursor, $limit ) = $d['args'];
			$out = array();
			foreach ( $this->legacy_products as $id => $guid ) {
				if ( $id > $cursor && count( $out ) < $limit ) { $out[] = (object) array( 'ID' => $id, 'remote_guid' => $guid ); }
			}
			$this->last_result = $out;
			return count( $out );
		}
		if ( false !== strpos( $d['sql'], "vm.meta_key = 'variant_guid'" ) ) {
			$this->last_result = array();
			return 0;
		}
		return null;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

class Mobo_Core_Connection_Context {
	public static function get_effective_connection_id() { return 1; }
}
class Mobo_Core_Connection_Repository {
	public static function get_default() { return array( 'id' => 1 ); }
}
$plugin = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $plugin . 'class-mobo-core-environment.php';
require $plugin . 'class-mobo-core-request-deadline.php';
require $plugin . 'class-mobo-core-lock.php';
require $plugin . 'class-mobo-core-self-runner.php';

/* ---------------- child: runner lock tracked by the early guard, then OOM ---------------- */
if ( $GLOBALS['__child_oom'] ) {
	require $plugin . 'class-mobo-core-shutdown-guard.php';
	Mobo_Core_Shutdown_Guard::register();               // like mobo-core.php: registered first
	register_shutdown_function( static function () {     // like WooCommerce's logger: re-fatals on OOM
		$again = array();
		while ( true ) { $again[] = str_repeat( 'y', 65536 ); }
	} );
	ini_set( 'memory_limit', '24M' );
	$token = Mobo_Core_Lock::acquire( 'real_cron_runner', 150 );
	update_option( 'child_locked', false !== $token ? 1 : 0 );
	Mobo_Core_Shutdown_Guard::track_lock( 'real_cron_runner', (string) $token );
	$hog = array();
	while ( true ) { $hog[] = str_repeat( 'x', 65536 ); } // Fatal: Allowed memory size exhausted.
}

/* ---------------- child: claim, then die by max_execution_time ---------------- */
if ( $GLOBALS['__child_mode'] ) {
	ini_set( 'max_execution_time', '30' );
	$claim = Mobo_Core_Self_Runner::claim_worker_request( '' );
	update_option( 'child_claimed', ! empty( $claim['success'] ) ? 1 : 0 );
	set_time_limit( 1 );
	$x = 0;
	while ( true ) { $x++; } // Fatal: Maximum execution time exceeded. finally blocks would not run.
}

require $plugin . 'class-mobo-core-sync-health.php';
require $plugin . 'class-mobo-core-storage-entity-meta-retirement.php';
require $plugin . 'class-mobo-core-product-map.php';
/* The fake DB has no SHOW TABLES; mark the map table as present. */
$map_cache = new ReflectionProperty( 'Mobo_Core_Product_Map', 'table_exists_cache' );
$map_cache->setAccessible( true );
$map_cache->setValue( null, true );

/* ---------------- tiny assertion runner ---------------- */
$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0;
function check( $label, $cond ) {
	if ( $cond ) { $GLOBALS['__pass']++; echo "  ok   - {$label}\n"; }
	else { $GLOBALS['__fail']++; echo "  FAIL - {$label}\n"; }
}
function lock_key() { return 'mobo_core_runtime_lock_worker_dispatcher'; }
function put_lease( $created, $heartbeat, $expires, $token = 'other-token' ) {
	$GLOBALS['__store']['raw'][ lock_key() ] = json_encode( array( 'token' => $token, 'created_at' => $created, 'heartbeat_at' => $heartbeat, 'expires_at' => $expires ), JSON_UNESCAPED_SLASHES );
}

echo 'PHP ' . PHP_VERSION . "\n";
$D = 'Mobo_Core_Request_Deadline';

echo "\n[1] request deadline math\n";
check( 'unknown/unlimited limit (0) is treated as 30 s', 30 === $D::php_time_limit( '0' ) && 30 === $D::php_time_limit( '' ) && 30 === $D::php_time_limit( false ) );
check( 'explicit limits are kept', 30 === $D::php_time_limit( '30' ) && 300 === $D::php_time_limit( 300 ) );
check( 'budget = 80% of a 30 s limit = 24 s', 24.0 === $D::budget_seconds( 30 ) );
check( 'budget capped at 25 s for 300 s hosts', 25.0 === $D::budget_seconds( 300 ) );
check( 'budget for a 10 s limit = 8 s', 8.0 === $D::budget_seconds( 10 ) );
$now = 1800000000.5;
check( 'valid REQUEST_TIME_FLOAT accepted', abs( $D::resolve_request_start( $now - 3.25, $now ) - ( $now - 3.25 ) ) < 1e-6 );
check( 'future REQUEST_TIME_FLOAT rejected', null === $D::resolve_request_start( $now + 10, $now ) );
check( 'ancient REQUEST_TIME_FLOAT rejected', null === $D::resolve_request_start( $now - 7200, $now ) );
check( 'garbage REQUEST_TIME_FLOAT rejected', null === $D::resolve_request_start( 'abc', $now ) && null === $D::resolve_request_start( 0, $now ) );
$c = $D::compute( $now - 6.0, '30', $now );
check( 'deadline measured from request start, not from stage start (start+24)', abs( $c['deadline'] - ( $now - 6.0 + 24.0 ) ) < 1e-6 );
check( 'bootstrap time is already spent: 18 s left after 6 s of bootstrap', abs( ( $c['deadline'] - $now ) - 18.0 ) < 1e-6 );
$c = $D::compute( null, '0', $now );
check( 'no request start -> now + 24 (assumed 30 s)', abs( $c['deadline'] - ( $now + 24.0 ) ) < 1e-6 );
ini_set( 'max_execution_time', '30' );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 5.0;
$D::reset();
$left = $D::remaining();
check( 'live: REQUEST_TIME_FLOAT 5 s ago on a 30 s host -> ~19 s left', $left > 18.5 && $left <= 19.01 );
check( 'clamp_seconds never exceeds what is left minus reserve', $D::clamp_seconds( 25, 2.0 ) <= 17.01 && $D::clamp_seconds( 3, 2.0 ) === 3.0 );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 40.0;
$D::reset();
check( 'request already past its deadline -> no time, clamp is 0', ! $D::has_time( 0 ) && 0.0 === $D::clamp_seconds( 10 ) );
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
$D::reset();

echo "\n[2] dispatcher lease TTL derived from the request budget\n";
check( '30 s host -> 45 s lease (was 600 s)', 45 === $D::lease_ttl( '30' ) );
check( '60 s host -> 75 s lease', 75 === $D::lease_ttl( '60' ) );
check( '300 s host -> capped at 90 s', 90 === $D::lease_ttl( '300' ) );
check( 'unknown limit -> 45 s', 45 === $D::lease_ttl( '0' ) );
check( 'active TTL always differs from the 180 s handoff window', Mobo_Core_Self_Runner::ACTIVE_WORKER_LEASE_TTL < Mobo_Core_Self_Runner::DISPATCH_LEASE_TTL );

echo "\n[3] claim / steal / 423 against the real Mobo_Core_Lock\n";
$S = 'Mobo_Core_Self_Runner';
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
$claim = $S::claim_worker_request( '' );
$status = Mobo_Core_Lock::get_status( 'worker_dispatcher' );
check( 'claim succeeds', ! empty( $claim['success'] ) && '' !== $claim['token'] );
check( 'claimed lease expires after ~45 s, not 600 s', $status['active'] && ( $status['expiresAt'] - $status['lastHeartbeatAt'] ) === 45 );
$second = $S::claim_worker_request( '' );
check( 'second claim -> 423 dispatcher-locked', empty( $second['success'] ) && 423 === $second['httpStatus'] );
check( '  with Retry-After bounded by the remaining lease', $second['retryAfter'] >= 1 && $second['retryAfter'] <= 45 );
check( 'claimed lease is not mistaken for an unclaimed handoff (upgrade cancel safety)', false === $S::is_unclaimed_handoff_snapshot( $status ) );
$S::release_worker_request( $claim['token'] );
check( 'release frees the lease', false === Mobo_Core_Lock::get_status( 'worker_dispatcher' )['active'] );
check( 'release after release is a harmless no-op (shutdown handler after finally)', true === $S::release_worker_request( $claim['token'] ) );

$t = time();
put_lease( $t - 700, $t - 700, $t - 100 );
$claim = $S::claim_worker_request( '' );
check( 'expired lease (killed request) is stolen safely by the next request', ! empty( $claim['success'] ) );
$S::release_worker_request( $claim['token'] );

put_lease( $t - 30, $t - 30, $t - 30 + 180 );
update_option( Mobo_Core_Self_Runner::OPTION_DISPATCHED_AT, $t - 30 );
check( 'unclaimed handoff detected', true === $S::is_unclaimed_handoff_snapshot( Mobo_Core_Lock::get_status( 'worker_dispatcher' ) ) );
$claim = $S::claim_worker_request( '' );
check( 'overdue unclaimed loopback handoff is taken over by real cron (no 180 s of 423)', ! empty( $claim['success'] ) && 'other-token' !== $claim['token'] );
check( 'a late worker with the old handoff token then gets 409', 409 === $S::claim_worker_request( 'other-token' )['httpStatus'] );
$S::release_worker_request( $claim['token'] );

put_lease( $t - 2, $t - 2, $t - 2 + 180 );
update_option( Mobo_Core_Self_Runner::OPTION_DISPATCHED_AT, $t - 2 );
$claim = $S::claim_worker_request( '' );
check( 'fresh handoff (worker about to arrive) is not taken over', empty( $claim['success'] ) && 423 === $claim['httpStatus'] );
put_lease( $t - 60, $t - 5, $t - 5 + 45 );
check( 'renewed (claimed) lease is never taken over', false === $S::handoff_takeover_allowed( Mobo_Core_Lock::get_status( 'worker_dispatcher' ), $t - 60, $t ) );

echo "\n[4] lease released by the shutdown handler when PHP dies (max_execution_time)\n";
$file = tempnam( sys_get_temp_dir(), 'mobo-lease-' );
file_put_contents( $file, json_encode( array( 'raw' => array(), 'opt' => array() ) ) );
$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child-claim-then-fatal ' . escapeshellarg( $file ) . ' 2>&1';
$child_output = (string) shell_exec( $cmd );
$after = json_decode( (string) file_get_contents( $file ), true );
@unlink( $file );
check( 'child claimed the dispatcher', 1 === (int) ( $after['opt']['child_claimed'] ?? 0 ) );
check( 'child really died by the execution time limit', false !== stripos( $child_output, 'Maximum execution time' ) );
check( 'lease row is gone after the fatal (no 10-minute 423 lockout)', ! isset( $after['raw'][ lock_key() ] ) );

echo "\n[5] storefront/admin never run the heavy migration pass\n";
check( 'storefront/admin/other REST page load -> nudge only', 'nudge' === $D::migration_bootstrap_action( false, false ) );
check( 'cron/worker route -> nothing in plugins_loaded (runner migrates within deadline)', 'none' === $D::migration_bootstrap_action( false, true ) );
check( 'WP-CLI (no time limit) keeps the full pass', 'full-pass' === $D::migration_bootstrap_action( true, false ) );
check( 'pretty /wp-json/mobo-core/v1/cron/run detected', $D::is_budgeted_worker_route( '/wp-json/mobo-core/v1/cron/run?token=x', '' ) );
check( 'pretty /wp-json/mobo-core/v1/worker/run detected', $D::is_budgeted_worker_route( '/sub/wp-json/mobo-core/v1/worker/run', '' ) );
check( 'plain ?rest_route=/mobo-core/v1/cron/run detected', $D::is_budgeted_worker_route( '/?rest_route=/mobo-core/v1/cron/run', '/mobo-core/v1/cron/run' ) );
check( 'product page is not a worker route', ! $D::is_budgeted_worker_route( '/product/some-phone/', '' ) );
check( 'health route is not a worker route', ! $D::is_budgeted_worker_route( '/wp-json/mobo-core/v1/health', '' ) );

echo "\n[6] Sync Health snapshot scan is paged with a durable cursor\n";
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
$rows = array();
for ( $i = 1; $i <= 450; $i++ ) {
	$rows[] = array( 'map_id' => $i * 2, 'remote_guid' => 'g-' . $i, 'wp_post_id' => 1000 + $i, 'map_last_hash' => '', 'map_sync_incomplete' => 0, 'map_updated_at' => '', 'health_id' => 0, 'health_wp_product_id' => 0, 'portal_revision' => 0, 'portal_version' => '', 'portal_hash' => '', 'sync_status' => '', 'last_error' => null, 'health_updated_at' => null );
}
$GLOBALS['wpdb']->map_rows = $rows;
$H = 'Mobo_Core_Sync_Health';
$far = microtime( true ) + 60;
$p1 = $H::run_operational_scan_page( 20, $far, 200, 1 );
$state = get_option( $H::SCAN_STATE_OPTION_PREFIX . '1' );
check( 'first slice processes one page and stays in progress', false === $p1['complete'] && 'in-progress' === $p1['status'] && 200 === $p1['processed'] );
check( '  cursor persisted at the last map id of the page', 400 === absint( $state['cursor'] ?? 0 ) && 200 === absint( $state['operational']['currentProducts'] ?? 0 ) );
check( '  scan in progress is visible to the cron refresh', $H::operational_scan_in_progress() );
$p2 = $H::run_operational_scan_page( 20, $far, 200, 1 );
check( 'second slice resumes after the cursor', false === $p2['complete'] && 200 === $p2['processed'] && 800 === $p2['cursor'] );
$p3 = $H::run_operational_scan_page( 20, $far, 200, 1 );
check( 'third slice finishes the catalog', true === $p3['complete'] && 50 === $p3['processed'] );
check( '  counters accumulated across requests (450 products, all converged)', 450 === $p3['result']['operational']['currentProducts'] && 450 === $p3['result']['operational']['converged'] && 0 === $p3['result']['operational']['accountingDelta'] );
check( '  status clear and state cleaned up', 'clear' === $p3['result']['operationalStatus'] && ! $H::operational_scan_in_progress() );
$GLOBALS['wpdb']->page_queries = 0;
$expired = $H::run_operational_scan_page( 20, microtime( true ) + 0.5, 200 );
check( 'no time left (deadline - 1 s passed) -> no page query, nothing processed', 0 === $GLOBALS['wpdb']->page_queries && 0 === $expired['processed'] && false === $expired['complete'] );
check( 'stale partial scan (> 1 h) restarts', false === $H::scan_state_resumable( array( 'connectionId' => 1, 'limit' => 20, 'operational' => array(), 'lists' => array(), 'startedAt' => time() - 7200 ), 1, 20, time() ) );
check( 'other Connection / other limit restarts', false === $H::scan_state_resumable( array( 'connectionId' => 2, 'limit' => 20, 'operational' => array(), 'lists' => array(), 'startedAt' => time() ), 1, 20, time() ) && false === $H::scan_state_resumable( array( 'connectionId' => 1, 'limit' => 15, 'operational' => array(), 'lists' => array(), 'startedAt' => time() ), 1, 20, time() ) );

echo "\n[7] Product Map seed: already-mapped GUIDs skip the per-row path\n";
$R = 'Mobo_Core_Product_Map';
check( 'exact intersection', array( 'a' => true, 'c' => true ) === $R::exact_guid_intersection( array( 'a', 'b', 'c' ), array( 'c', 'a' ) ) );
check( 'case/collation-only matches are not treated as mapped', array() === $R::exact_guid_intersection( array( 'abc' ), array( 'ABC', 'abc ' ) ) );
check( 'empty SQL result -> nothing skipped (safe per-row path)', array() === $R::exact_guid_intersection( array( 'a' ), array() ) );
/* casemobilee case: the map is already complete but the seed marker is missing.
 * Mobo_Core_Product_Concurrency is deliberately absent here: any row that took the
 * per-row lock path would stall the seed, so completion proves the short-cut. */
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
for ( $i = 1; $i <= 1200; $i++ ) { $GLOBALS['wpdb']->legacy_products[ $i ] = 'p-' . $i; }
$GLOBALS['wpdb']->mapped_guids = array_values( $GLOBALS['wpdb']->legacy_products );
$seed_map = new Mobo_Core_Product_Map();
$batches = 0;
do {
	$r = $seed_map->seed_from_legacy_meta( 500 );
	$batches++;
} while ( empty( $r['stalled'] ) && ( $r['products'] > 0 || $r['variations'] > 0 ) && $batches < 10 );
check( 'complete map re-walked in 500-row batches without stalling', empty( $r['stalled'] ) && 4 === $batches );
check( '  seed completion marker written', absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) > 0 );
check( '  cursor reached the last legacy product', 1200 === absint( get_option( 'mobo_core_product_map_product_cursor', 0 ) ) );
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
$GLOBALS['wpdb']->mapped_guids = array_slice( $GLOBALS['wpdb']->mapped_guids, 0, 700 );
$r = $seed_map->seed_from_legacy_meta( 500 );
$r = $seed_map->seed_from_legacy_meta( 500 );
check( 'an unmapped row still takes the safe per-row path (stalls here without locks)', ! empty( $r['stalled'] ) && 700 === absint( get_option( 'mobo_core_product_map_product_cursor', 0 ) ) );
check( '  and completion is not claimed', 0 === absint( get_option( 'mobo_core_product_map_seed_completed_at', 0 ) ) );

echo "\n[8] entity fence: negative results cached briefly per request\n";
$E = 'Mobo_Core_Storage_Entity_Meta_Retirement';
check( 'never read -> must query', false === $E::negative_state_cache_fresh( 0, 100.0 ) );
check( 'read 1 s ago -> reuse (no extra query per meta read)', true === $E::negative_state_cache_fresh( 99.0, 100.0 ) );
check( 'older than the TTL -> re-read (a flip by another process is seen)', false === $E::negative_state_cache_fresh( 100.0 - $E::NEGATIVE_STATE_TTL, 100.0 ) );
check( 'TTL far inside the 120 s transition grace', $E::NEGATIVE_STATE_TTL > 0 && $E::NEGATIVE_STATE_TTL * 10 <= $E::GRACE_SECONDS );
check( 'clock going backwards -> re-read', false === $E::negative_state_cache_fresh( 100.0, 90.0 ) );


/* ======================= runtime-30s (Run 3) additions ======================= */

function scan_rows( $n, $first_product = 1000 ) {
	$rows = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$rows[] = array( 'map_id' => $i * 2, 'remote_guid' => 'g-' . $i, 'wp_post_id' => $first_product + $i, 'map_last_hash' => '', 'map_sync_incomplete' => 0, 'map_updated_at' => '', 'health_id' => 0, 'health_wp_product_id' => 0, 'portal_revision' => 0, 'portal_version' => '', 'portal_hash' => '', 'sync_status' => '', 'last_error' => null, 'health_updated_at' => null );
	}
	return $rows;
}
/* classify_operational_rows() reads `mobo_sync_incomplete` once per product, so a
 * slow meta read stands in for a product with a huge variation structure. */

echo "\n[9] Sync Health scan: deadline checked per product, cursor persisted, chunk shrinks\n";
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
$GLOBALS['wpdb']->map_rows = scan_rows( 300 );
$GLOBALS['__slow_meta'] = array();
for ( $i = 1; $i <= 300; $i++ ) { $GLOBALS['__slow_meta'][ 1000 + $i ] = 0.05; } // 50 ms per product
$t0 = microtime( true );
$p  = $H::run_operational_scan_page( 20, microtime( true ) + 2.6, 200 );
$el = microtime( true ) - $t0;
$st = get_option( $H::SCAN_STATE_OPTION_PREFIX . '1' );
check( 'stops before the deadline inside a 200-row page (no overrun)', $el < 2.0 && $p['processed'] > 0 && $p['processed'] < 200 && 'in-progress' === $p['status'] );
check( '  cursor persisted at the last classified product', absint( $st['cursor'] ?? 0 ) === 2 * $p['processed'] && absint( $st['operational']['currentProducts'] ?? 0 ) === $p['processed'] );
check( '  next chunk shrinks to what fitted', absint( $st['chunk'] ?? 0 ) === $p['processed'] && absint( $p['chunk'] ?? 0 ) === $p['processed'] );
check( '  clean exit leaves no in-flight marker (no false stall)', ! isset( $st['inflight'] ) );
$before = $p['processed'];
$GLOBALS['__slow_meta'] = array();
$p2 = $H::run_operational_scan_page( 20, microtime( true ) + 30, 200 );
check( 'next slice resumes after the cursor and finishes (300 counted once)', true === $p2['complete'] && $before + $p2['processed'] === 300 && 300 === $p2['result']['operational']['currentProducts'] && 0 === $p2['result']['operational']['accountingDelta'] );

echo "\n[10] Sync Health scan: requests killed inside the scan never loop forever\n";
$dec = $H::scan_chunk_decision( array( 'chunk' => 200 ), 10, 200 );
check( 'clean previous slice -> chunk kept, no stall', false === $dec['died'] && 200 === $dec['chunk'] && 0 === $dec['stalls'] && false === $dec['skip'] );
$dec = $H::scan_chunk_decision( array( 'chunk' => 200, 'inflight' => array( 'startCursor' => 10 ) ), 10, 200 );
check( 'died without progress -> stall 1, chunk /4', true === $dec['died'] && 1 === $dec['stalls'] && 50 === $dec['chunk'] && false === $dec['skip'] );
$dec = $H::scan_chunk_decision( array( 'chunk' => 200, 'stalls' => 2, 'inflight' => array( 'startCursor' => 4 ) ), 10, 200 );
check( 'died after some progress -> stalls reset, chunk still shrinks', 0 === $dec['stalls'] && 50 === $dec['chunk'] );
$dec = $H::scan_chunk_decision( array( 'chunk' => 1, 'stalls' => 2, 'inflight' => array( 'startCursor' => 10 ) ), 10, 200 );
check( '3rd death at the same cursor with a 1-row chunk -> skip-and-mark', true === $dec['skip'] && 3 === $dec['stalls'] && 1 === $dec['chunk'] );
check( 'next_scan_chunk: deadline cut 200 after 37 -> 37', 37 === $H::next_scan_chunk( 200, 37, 200, 9.0, 200 ) );
check( 'next_scan_chunk: fast full chunk grows x2 up to the page size', 100 === $H::next_scan_chunk( 50, 50, 50, 0.5, 200 ) && 200 === $H::next_scan_chunk( 150, 150, 150, 0.5, 200 ) );
check( 'next_scan_chunk: slow full chunk keeps size; never below 1', 50 === $H::next_scan_chunk( 50, 50, 50, 5.0, 200 ) && 1 === $H::next_scan_chunk( 1, 0, 1, 9.0, 200 ) );
/* End to end: product #4 is a "poison" product that alone exceeds any request. */
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
$GLOBALS['wpdb']->map_rows = scan_rows( 10 );
$GLOBALS['__slow_meta'] = array();
update_option( $H::SCAN_STATE_OPTION_PREFIX . '1', array( 'connectionId' => 1, 'limit' => 20, 'startedAt' => time(), 'cursor' => 6, 'chunk' => 1, 'stalls' => 2, 'inflight' => array( 'startCursor' => 6, 'at' => time() - 40 ),
	'operational' => array( 'currentProducts' => 3, 'monitoredProducts' => 0, 'excludedByPolicy' => 0, 'converged' => 3, 'activeRetryOwner' => 0, 'terminalUpstreamInvalid' => 0, 'unownedConvergenceGap' => 0, 'unverifiedHealthGap' => 0, 'needsAttention' => 0, 'needsVerification' => 0, 'notFullyConverged' => 0, 'historicalHealthResidue' => 0, 'historicalNonLive' => 0, 'historicalExcluded' => 0 ),
	'lists' => array( 'unownedRows' => array(), 'verificationRows' => array(), 'activeRetryRows' => array(), 'terminalRows' => array() ) ) );
$p3 = $H::run_operational_scan_page( 20, microtime( true ) + 30, 200 );
check( 'poison product (3 deaths at its cursor) is skipped, the scan completes', true === $p3['complete'] && 1 === absint( $p3['skipped'] ?? 0 ) );
check( '  skipped product reported for verification, accounting still closed', 10 === $p3['result']['operational']['currentProducts'] && 1 === $p3['result']['operational']['unverifiedHealthGap'] && 0 === $p3['result']['operational']['accountingDelta']
	&& 'scan-skipped-timeout' === ( $p3['result']['verificationRows'][0]['classification'] ?? '' ) && 1004 === ( $p3['result']['verificationRows'][0]['wpProductId'] ?? 0 ) );
$GLOBALS['__slow_meta'] = array();

echo "\n[11] runner lock TTL follows the PHP limit; 'locked' answers carry Retry-After\n";
check( '30 s host -> 45 s runner lock (was 150 s from the option)', 45 === $D::runner_lock_ttl( 24, '30' ) );
check( 'never shorter than budget + 15 s', 55 === $D::runner_lock_ttl( 40, '30' ) );
check( '300 s host -> capped at 90 s', 90 === $D::runner_lock_ttl( 25, '300' ) && 90 === $D::runner_lock_ttl( 200, '300' ) );
require_once $plugin . 'class-mobo-core-cron-runner.php';
$C = 'Mobo_Core_Cron_Runner';
check( 'Retry-After = remaining lease, 1..90', 37 === $C::locked_retry_after( array( 'active' => true, 'remainingSeconds' => 37 ) ) && 1 === $C::locked_retry_after( array( 'active' => false ) ) && 90 === $C::locked_retry_after( array( 'active' => true, 'remainingSeconds' => 400 ) ) );
check( 'degradation: 0-1 deaths normal, 2-3 level 1, 4+ level 2', 0 === $C::degrade_level( 1 ) && 1 === $C::degrade_level( 2 ) && 1 === $C::degrade_level( 3 ) && 2 === $C::degrade_level( 4 ) );
check( 'level 1 skips only the stage the last dead slice was in', true === $C::degraded_skip( 'health-snapshot', 1, 'health-snapshot' ) && false === $C::degraded_skip( 'cache-warmup', 1, 'health-snapshot' ) && false === $C::degraded_skip( 'health-snapshot', 0, 'health-snapshot' ) );
check( 'level 2 skips every optional heavy stage, never critical work', true === $C::degraded_skip( 'health-snapshot', 2, '' ) && true === $C::degraded_skip( 'cache-warmup', 2, '' ) && false === $C::degraded_skip( 'webhook-queue', 2, 'webhook-queue' ) );

echo "\n[12] runner lock released by the EARLY guard after a memory fatal, even when a later shutdown handler re-fatals\n";
$file = tempnam( sys_get_temp_dir(), 'mobo-oom-' );
file_put_contents( $file, json_encode( array( 'raw' => array(), 'opt' => array() ) ) );
$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child-runner-lock-then-oom ' . escapeshellarg( $file ) . ' 2>&1';
$child_output = (string) shell_exec( $cmd );
$after = json_decode( (string) file_get_contents( $file ), true );
@unlink( $file );
check( 'child acquired the runner lock', 1 === (int) ( $after['opt']['child_locked'] ?? 0 ) );
check( 'child died by the memory limit', false !== stripos( $child_output, 'Allowed memory size' ) );
check( 'runner lock row is gone (no 150 s "locked" cron)', ! isset( $after['raw']['mobo_core_runtime_lock_real_cron_runner'] ) );

echo "\n[13] memory pressure helper (disabled-function safe)\n";
check( '200 MB of 256M >= 75 % -> pressure', true === $D::memory_pressure( 0.75, 200 * 1048576, '256M' ) );
check( '150 MB of 256M -> no pressure', false === $D::memory_pressure( 0.75, 150 * 1048576, '256M' ) );
check( 'unlimited (-1) or unknown usage (0) never stops work', false === $D::memory_pressure( 0.75, 999 * 1048576, '-1' ) && false === $D::memory_pressure( 0.75, 0, '256M' ) );
check( 'parse_bytes: G/M/K/plain/garbage', 1073741824 === $D::parse_bytes( '1G' ) && 268435456 === $D::parse_bytes( '256M' ) && 2048 === $D::parse_bytes( '2k' ) && 512 === $D::parse_bytes( '512' ) && 0 === $D::parse_bytes( 'lots' ) );
Mobo_Core_Environment::simulate_disabled( array( 'memory_get_usage' ) );
check( 'memory_get_usage disabled -> never pressure (no fatal)', false === $D::memory_pressure( 0.75, null, '1M' ) );
Mobo_Core_Environment::simulate_disabled( array() );

echo "\n[14] migration notice: cron running vs timing out vs not running\n";
require_once $plugin . 'class-mobo-core-migration-quarantine.php';
$Q = 'Mobo_Core_Migration_Quarantine';
$now = 1800000000;
check( 'completed migration-only slice 40 s ago -> healthy (no false "no successful run")', 'healthy' === $Q::classify_runtime_activity( $now - 40, array( 'active' => true, 'remainingSeconds' => 41 ), $now, $now - 40 )['state'] );
check( 'no completed slice for 15 min but cron hit 30 s ago -> timing-out', 'timing-out' === $Q::classify_runtime_activity( $now - 900, array(), $now, $now - 30 )['state'] );
check( 'no completed slice and no hit -> idle ("check Cron setup")', 'idle' === $Q::classify_runtime_activity( $now - 900, array(), $now, $now - 3600 )['state'] && 'idle' === $Q::classify_runtime_activity( 0, array(), $now )['state'] );
check( 'lease held and no success -> locked', 'locked' === $Q::classify_runtime_activity( 0, array( 'active' => true, 'remainingSeconds' => 20 ), $now, $now - 5 )['state'] );

echo "\n[15] identity/operational fence: negative state reads cached briefly per request\n";
require_once $plugin . 'class-mobo-core-storage-identity-meta-retirement.php';
require_once $plugin . 'class-mobo-core-storage-operational-meta-retirement.php';
foreach ( array( 'Mobo_Core_Storage_Identity_Meta_Retirement', 'Mobo_Core_Storage_Operational_Meta_Retirement' ) as $W ) {
	check( $W . ': read 1 s ago -> reuse', true === $W::negative_fence_fresh( 99.0, 100.0 ) );
	check( $W . ': never read / older than TTL / clock backwards -> re-read', false === $W::negative_fence_fresh( 0, 100.0 ) && false === $W::negative_fence_fresh( 100.0 - $W::NEGATIVE_FENCE_TTL, 100.0 ) && false === $W::negative_fence_fresh( 100.0, 90.0 ) );
}

echo "\n[16] table existence: one probe, then memo + persisted (no SHOW TABLES per call)\n";
require_once $plugin . 'class-mobo-core-table-cache.php';
class Probe_WPDB extends Fake_WPDB {
	public $tables = array( 'wp_mobo_connection_settings' );
	public function get_var( $q ) {
		$d = json_decode( substr( $q, 2 ), true );
		if ( 0 === strpos( $d['sql'], 'SHOW TABLES LIKE' ) ) { return in_array( $d['args'][0], $this->tables, true ) ? $d['args'][0] : null; }
		return parent::get_var( $q );
	}
}
$saved_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new Probe_WPDB();
$GLOBALS['__store'] = array( 'raw' => array(), 'opt' => array() );
$T = 'Mobo_Core_Table_Cache';
$T::reset();
$a = $T::exists( 'wp_mobo_connection_settings' ); $b = $T::exists( 'wp_mobo_connection_settings' ); $c = $T::exists( 'wp_mobo_connection_settings' );
check( 'first call probes once, repeated calls are memoized', $a && $b && $c && 1 === $T::$probes );
$T::reset();
check( 'a later request reuses the persisted positive result (0 probes)', $T::exists( 'wp_mobo_connection_settings' ) && 0 === $T::$probes );
check( 'missing table: not cached (may be created later in the same request)', false === $T::exists( 'wp_mobo_missing' ) && false === $T::exists( 'wp_mobo_missing' ) && 2 === $T::$probes );
update_option( 'mobo_core_db_version', '10.34.78' );
$T::reset();
$T::exists( 'wp_mobo_connection_settings' );
check( 'DB version change invalidates the persisted map', 1 === $T::$probes );
$T::flush();
check( 'flush() (schema install) forgets everything', ! is_array( get_option( $T::OPTION, null ) ) && $T::exists( 'wp_mobo_connection_settings' ) && 2 === $T::$probes );
$GLOBALS['wpdb'] = $saved_wpdb;


echo "\n[17] terminal Custom reads: one cached row per object instead of one SELECT per meta key\n";
if ( ! class_exists( 'Mobo_Core_Storage_Schema', false ) ) {
	class Mobo_Core_Storage_Schema { public static function table_name( $k ) { return 'wp_mobo_' . $k; } }
}
require_once $plugin . 'class-mobo-core-storage-row-cache.php';
require_once $plugin . 'class-mobo-core-storage-postmeta-write-suppression.php';
class Row_WPDB extends Fake_WPDB {
	public $rows = 0;
	public function get_row( $q, $output = null ) {
		$d = json_decode( substr( $q, 2 ), true );
		if ( false !== strpos( $d['sql'], 'FROM wp_mobo_products WHERE connection_id=%d AND woo_product_id=%d' ) ) {
			$this->rows++;
			return array( 'product_seen_revision' => 7, 'product_seen_revision_present' => 1, 'product_applied_revision' => 5, 'product_applied_revision_present' => 1,
				'variant_seen_revision' => 0, 'variant_seen_revision_present' => 0, 'variant_applied_revision' => 0, 'variant_applied_revision_present' => 0,
				'product_source_hash' => 'h1', 'product_source_hash_present' => 1, 'sync_incomplete_meta' => 1, 'sync_incomplete_meta_present' => 1 );
		}
		return null;
	}
}
$saved_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new Row_WPDB();
Mobo_Core_Storage_Row_Cache::reset();
$W = 'Mobo_Core_Storage_Postmeta_Write_Suppression';
$r1 = $W::terminal_current( 42, '_mobo_product_seen_revision' );
$r2 = $W::terminal_current( 42, '_mobo_product_source_hash' );
$r3 = $W::terminal_current( 42, 'mobo_sync_incomplete' );
check( 'values are the same as the per-key reads returned', 7 === $r1['value'] && true === $r1['present'] && 'h1' === $r2['value'] && '1' === $r3['value'] );
check( '3 keys of one product -> 1 SELECT', 1 === $GLOBALS['wpdb']->rows );
Mobo_Core_Storage_Row_Cache::observe_query( "UPDATE wp_mobo_products SET product_source_hash='h2' WHERE id=1" );
$W::terminal_current( 42, '_mobo_product_source_hash' );
check( 'a write to the table invalidates the cached row (fresh read after write)', 2 === $GLOBALS['wpdb']->rows );
$GLOBALS['wpdb'] = $saved_wpdb;
Mobo_Core_Storage_Row_Cache::reset();


echo "\n[18] memory guard used by the scan / migration loops\n";
$old_limit = Mobo_Core_Environment::ini( 'memory_limit', '256M' );
$usage     = Mobo_Core_Environment::memory_usage( true );
if ( $usage > 0 ) {
	ini_set( 'memory_limit', (string) (int) ceil( $usage * 1.1 ) );
	check( 'usage ~90 % of the limit -> guard says stop (after releasing caches)', true === $D::memory_guard( 0.6, 0.75 ) );
	ini_set( 'memory_limit', (string) (int) ceil( $usage * 4 ) );
	check( 'usage 25 % of the limit -> guard lets the loop continue', false === $D::memory_guard( 0.6, 0.75 ) );
	ini_set( 'memory_limit', $old_limit );
}
check( 'unlimited memory_limit -> never stops', ( ini_set( 'memory_limit', '-1' ) || true ) && false === $D::memory_guard( 0.6, 0.75 ) );
ini_set( 'memory_limit', $old_limit );

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed (PHP " . PHP_VERSION . ")\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
