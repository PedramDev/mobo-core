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
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '' ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function delete_transient( $k ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
function get_post_meta( $id, $key = '', $single = false ) { return ''; }
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

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed (PHP " . PHP_VERSION . ")\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
