<?php
/**
 * Standalone (no WordPress, no network, no database) test for the 10.34.77
 * cache-plugin integration hardening:
 *
 * - WP Rocket's per-save rocket_clean_post() listener (home + every archive +
 *   partial preload per product) is detached inside Mobo mutation scopes and
 *   during the shutdown object-cache purge.
 * - A burst of 5000 product updates yields a bounded number of purge calls,
 *   bounded URLs per call, no domain/home purge and no preload calls, while
 *   every product URL is still purged once through the coalesced queue.
 * - Exceptions thrown by cache-plugin callbacks never stop processing.
 * - Cache warmup is opt-in, bounded per slice, paused during bursts, disabled
 *   in the off mode and never admitted from storefront requests.
 *
 * Run: php tests-dev/cache-integration-test.php
 * PHP 7.4+ compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_PLUGIN_DIR', __DIR__ . '/__no_plugins__' );
define( 'MOBO_CORE_VERSION', '10.34.77-test' );
define( 'WP_ROCKET_VERSION', '3.10.8' );

$GLOBALS['t_options'] = array();
$GLOBALS['t_hooks']   = array();
$GLOBALS['t_rocket']  = array( 'clean_files' => array(), 'clean_home' => 0, 'clean_domain' => 0, 'clean_post' => 0, 'preload' => 0, 'rucss' => 0, 'throw' => false );
$GLOBALS['t_http']    = array();
$checks   = 0;
$failures = 0;

function check( $condition, $label ) {
	global $checks, $failures;
	$checks++;
	if ( $condition ) {
		echo '  ok   - ' . $label . PHP_EOL;
		return;
	}
	$failures++;
	echo '  FAIL - ' . $label . PHP_EOL;
}

/* ---------------- WordPress stubs ---------------- */
class WP_Error { public function get_error_code() { return 'err'; } public function get_error_message() { return 'err'; } }
class WP_Post { public $ID; public $post_type = 'product'; public $post_name = ''; public $post_status = 'publish'; }
class WP_Term {
	public $term_id; public $slug; public $taxonomy;
	public function __construct( $id, $taxonomy ) { $this->term_id = $id; $this->slug = 'cat' . $id; $this->taxonomy = $taxonomy; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return sanitize_key( $v ); }
function wp_unslash( $v ) { return $v; }
function esc_url_raw( $url ) { $url = trim( (string) $url ); return preg_match( '#^https?://#i', $url ) ? $url : ''; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function user_trailingslashit( $v ) { return trailingslashit( $v ); }
function home_url( $path = '' ) { return 'https://shop.test/' . ltrim( (string) $path, '/' ); }
function get_bloginfo( $k ) { return '6.6'; }
function is_multisite() { return false; }
function get_site_option( $k, $d = false ) { return $d; }
function get_file_data( $f, $h ) { return array(); }
function wp_generate_uuid4() { static $n = 0; $n++; return sprintf( '00000000-0000-4000-8000-%012d', $n ); }
function get_query_var( $k, $d = '' ) { return $d; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function add_option( $k, $v, $d = '', $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }

function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['t_hooks'][ $hook ][ (int) $priority ][] = $cb; return true; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return add_action( $hook, $cb, $priority, $args ); }
function t_same_cb( $a, $b ) { return is_object( $a ) || is_object( $b ) ? $a === $b : $a == $b; }
function has_action( $hook, $cb = false ) {
	if ( empty( $GLOBALS['t_hooks'][ $hook ] ) ) { return false; }
	if ( false === $cb ) { return true; }
	foreach ( $GLOBALS['t_hooks'][ $hook ] as $priority => $list ) {
		foreach ( $list as $existing ) { if ( t_same_cb( $existing, $cb ) ) { return $priority; } }
	}
	return false;
}
function has_filter( $hook, $cb = false ) { return has_action( $hook, $cb ); }
function remove_action( $hook, $cb, $priority = 10 ) {
	if ( empty( $GLOBALS['t_hooks'][ $hook ][ $priority ] ) ) { return false; }
	foreach ( $GLOBALS['t_hooks'][ $hook ][ $priority ] as $i => $existing ) {
		if ( t_same_cb( $existing, $cb ) ) {
			unset( $GLOBALS['t_hooks'][ $hook ][ $priority ][ $i ] );
			if ( empty( $GLOBALS['t_hooks'][ $hook ][ $priority ] ) ) { unset( $GLOBALS['t_hooks'][ $hook ][ $priority ] ); }
			if ( empty( $GLOBALS['t_hooks'][ $hook ] ) ) { unset( $GLOBALS['t_hooks'][ $hook ] ); }
			return true;
		}
	}
	return false;
}
function remove_filter( $hook, $cb, $priority = 10 ) { return remove_action( $hook, $cb, $priority ); }
function t_callbacks( $hook ) {
	if ( empty( $GLOBALS['t_hooks'][ $hook ] ) ) { return array(); }
	$by_priority = $GLOBALS['t_hooks'][ $hook ];
	ksort( $by_priority );
	$all = array();
	foreach ( $by_priority as $list ) { foreach ( $list as $cb ) { $all[] = $cb; } }
	return $all;
}
function do_action( $hook, ...$args ) { foreach ( t_callbacks( $hook ) as $cb ) { call_user_func_array( $cb, $args ); } }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( t_callbacks( $hook ) as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $args ) ); }
	return $value;
}
function did_action( $hook ) { return 1; }

/* Catalog: products 1..6000; categories 1..5; product_variation 100001+ (parent = id - 100000). */
function get_post_type( $id ) { $id = absint( $id ); return $id > 100000 ? 'product_variation' : ( $id > 0 ? 'product' : false ); }
function wp_get_post_parent_id( $id ) { return absint( $id ) > 100000 ? absint( $id ) - 100000 : 0; }
function get_post_status( $id ) { return 'publish'; }
function get_post_meta( $id, $key = '', $single = false ) { return 'product_guid' === $key ? 'guid-' . absint( $id ) : ( 'total_sales' === $key ? 3 : '' ); }
function get_permalink( $id ) { $id = is_object( $id ) ? $id->ID : absint( $id ); return 'https://shop.test/product/p' . $id . '/'; }
function wp_get_post_terms( $id, $taxonomy ) { return 'product_cat' === $taxonomy ? array( new WP_Term( ( absint( $id ) % 5 ) + 1, 'product_cat' ) ) : array(); }
function get_ancestors( $id, $taxonomy, $type = '' ) { return array(); }
function get_term( $id, $taxonomy ) { return new WP_Term( $id, $taxonomy ); }
function get_term_by( $field, $value, $taxonomy ) { return new WP_Term( $value, $taxonomy ); }
function get_term_link( $term, $taxonomy ) { return 'https://shop.test/product-category/' . $term->slug . '/'; }
function wc_get_page_permalink( $page ) { return 'https://shop.test/shop/'; }
function wc_delete_product_transients( $id ) { }
function clean_post_cache( $id ) { do_action( 'clean_post_cache', $id, null ); }

/* ---------------- WP Rocket stubs (behaviour of 3.10.8) ---------------- */
function rocket_clean_files( $urls ) {
	if ( $GLOBALS['t_rocket']['throw'] ) { throw new RuntimeException( 'Simulated WP Rocket filesystem failure' ); }
	$GLOBALS['t_rocket']['clean_files'][] = array_values( (array) $urls );
	if ( ! empty( $GLOBALS['t_during_clean'] ) ) {
		$cb = $GLOBALS['t_during_clean'];
		$GLOBALS['t_during_clean'] = null;
		$cb();
	}
}
function rocket_clean_home( $lang = '' ) { $GLOBALS['t_rocket']['clean_home']++; }
function rocket_clean_domain( $lang = '' ) { $GLOBALS['t_rocket']['clean_domain']++; }
function rocket_clean_post( $post_id, $post = null ) {
	/* Real WP Rocket: purge product + all archives + home + feeds, then queue a partial preload. */
	if ( get_post_type( $post_id ) !== 'product' ) { return false; }
	$GLOBALS['t_rocket']['clean_post']++;
	$GLOBALS['t_rocket']['clean_home']++;
	$GLOBALS['t_rocket']['preload']++;
	$GLOBALS['t_rocket']['rucss']++;
	return true;
}
function run_rocket_sitemap_preload() { $GLOBALS['t_rocket']['preload']++; }
function run_rocket_preload_cache() { $GLOBALS['t_rocket']['preload']++; }
function rocket_rucss_clean() { $GLOBALS['t_rocket']['rucss']++; }
add_action( 'clean_post_cache', 'rocket_clean_post' ); // As registered by wp-rocket/inc/common/purge.php.

/* ---------------- HTTP stubs (no network: recorded only) ---------------- */
function wp_remote_get( $url, $args = array() ) { $GLOBALS['t_http'][] = $url; return array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ) ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }

/* ---------------- Mobo dependencies ---------------- */
final class Mobo_Core_Settings {
	public static function get( $key, $fallback = null ) { return get_option( $key, $fallback ); }
	public static function enabled( $key, $default = '0' ) { return in_array( strtolower( (string) self::get( $key, $default ) ), array( '1', 'yes', 'true', 'on' ), true ); }
	public static function get_int( $key, $default, $min, $max ) { return max( $min, min( $max, absint( self::get( $key, $default ) ) ) ); }
	public static function sanitize_cache_archive_purge_interval( $value, $default = 0 ) {
		$value = absint( $value );
		return in_array( $value, array( 0, 5, 10, 15, 20, 25, 30, 45, 60 ), true ) ? $value : $default;
	}
}
final class Mobo_Core_Connection_Context {
	public static function get_effective_connection_id() { return 1; }
}
/** In-memory row queue with the subset of the Work Queue Store API the warmer uses. */
final class Mobo_Core_Work_Queue_Store {
	public static $rows = array();
	public static function reset() { self::$rows = array(); }
	public static function is_available() { return true; }
	public static function migrate_legacy_warmup_option( $o, $p = false ) { return true; }
	public static function read_one( $type, $key ) { return isset( self::$rows[ $type ][ $key ] ) ? self::$rows[ $type ][ $key ] : null; }
	public static function put_direct( $type, $key, $payload ) { self::$rows[ $type ][ $key ] = array( 'dedupe_key' => $key, 'payload' => $payload, 'payload_hash' => md5( serialize( $payload ) ), 'claim' => '' ); return true; }
	public static function delete_key( $type, $key ) { unset( self::$rows[ $type ][ $key ] ); return true; }
	public static function delete_other_object_keys( $type, $id, $key ) { return true; }
	public static function count_strict( $type ) { return isset( self::$rows[ $type ] ) ? count( self::$rows[ $type ] ) : 0; }
	public static function has_due_one( $type ) { return null !== self::next_due_one( $type ); }
	public static function next_due_one( $type ) {
		foreach ( isset( self::$rows[ $type ] ) ? self::$rows[ $type ] : array() as $row ) {
			if ( '' === $row['claim'] && absint( $row['payload']['nextAttemptAt'] ) <= time() ) { return $row; }
		}
		return null;
	}
	public static function claim_one( $type, $key, $hash, $token, $ttl ) {
		if ( ! isset( self::$rows[ $type ][ $key ] ) || self::$rows[ $type ][ $key ]['payload_hash'] !== $hash ) { return false; }
		self::$rows[ $type ][ $key ]['claim'] = $token;
		return true;
	}
	public static function finish_claim( $type, $key, $hash, $token, $payload = null ) {
		if ( ! isset( self::$rows[ $type ][ $key ] ) || self::$rows[ $type ][ $key ]['claim'] !== $token ) { return false; }
		if ( null === $payload ) { unset( self::$rows[ $type ][ $key ] ); return true; }
		self::put_direct( $type, $key, $payload );
		return true;
	}
	public static function queue_stats( $type ) {
		$n = self::count_strict( $type );
		return array( 'pending' => $n, 'due' => $n, 'claimed' => 0, 'retrying' => 0, 'oldestAt' => '', 'nextAt' => '' );
	}
}

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-cache-mutation-guard.php';
require $inc . 'class-mobo-core-cache-purger.php';
require $inc . 'class-mobo-core-cache-warmer.php';

$G = 'Mobo_Core_Cache_Mutation_Guard';
$P = 'Mobo_Core_Cache_Purger';

function reset_rocket() {
	$throw = $GLOBALS['t_rocket']['throw'];
	$GLOBALS['t_rocket'] = array( 'clean_files' => array(), 'clean_home' => 0, 'clean_domain' => 0, 'clean_post' => 0, 'preload' => 0, 'rucss' => 0, 'throw' => $throw );
}
function reset_cache_state() {
	foreach ( array( 'mobo_core_cache_purge_burst_window', 'mobo_core_cache_archive_purge_queue', 'mobo_core_cache_archive_purge_last_result', 'mobo_core_cache_purge_last_result' ) as $k ) { delete_option( $k ); }
	reset_rocket();
	Mobo_Core_Work_Queue_Store::reset();
	$GLOBALS['t_http'] = array();
}
/** Simulate one Mobo sync request: WooCommerce saves inside the guard, flush at shutdown. */
function sync_slice( $ids ) {
	Mobo_Core_Cache_Mutation_Guard::run(
		static function () use ( $ids ) {
			foreach ( $ids as $id ) {
				do_action( 'woocommerce_update_product', $id, null );
				clean_post_cache( $id ); // WC_Product_Data_Store_CPT::update() does this on every save.
			}
		},
		'webhook-product'
	);
	return Mobo_Core_Cache_Purger::flush();
}
function make_queue_due() {
	$q = get_option( 'mobo_core_cache_archive_purge_queue', array() );
	if ( is_array( $q ) && ! empty( $q ) ) { $q['dueAt'] = time() - 1; update_option( 'mobo_core_cache_archive_purge_queue', $q ); }
}
function drain_queue( $max_passes = 100 ) {
	$passes = 0;
	while ( $passes < $max_passes ) {
		$q = get_option( 'mobo_core_cache_archive_purge_queue', array() );
		if ( ! is_array( $q ) || empty( $q['urls'] ) ) { break; }
		make_queue_due();
		Mobo_Core_Cache_Purger::process_due_archive_purge( 'test' );
		$passes++;
	}
	return $passes;
}
function rocket_urls() {
	$all = array();
	foreach ( $GLOBALS['t_rocket']['clean_files'] as $call ) { foreach ( $call as $u ) { $all[ $u ] = true; } }
	return $all;
}
function max_urls_per_call() {
	$max = 0;
	foreach ( $GLOBALS['t_rocket']['clean_files'] as $call ) { $max = max( $max, count( $call ) ); }
	return $max;
}

$P::init();
update_option( 'active_plugins', array( 'wp-rocket/wp-rocket.php', 'woocommerce/woocommerce.php' ) );

echo "Storefront request (no Mobo mutation scope) never admits warmup" . PHP_EOL;
reset_cache_state();
update_option( 'mobo_core_cache_warmup_enabled', '1' );
update_option( 'mobo_core_cache_warmup_policy', 'all' );
add_filter( 'mobo_core_cache_warmup_product_tier', static function () { return 'hot'; } );
add_filter( 'mobo_core_cache_warmup_min_gap_seconds', static function () { return 0; } );
do_action( 'woocommerce_update_product', 7, null ); // e.g. checkout stock reduction
$r = $P::flush();
check( true === $r['flushed'] && 1 === $r['urls'], 'storefront change still purges its own product page' );
check( 'skipped-non-mobo-request' === $r['warmupStatus'], 'storefront request does not enqueue warmup' );
check( 0 === Mobo_Core_Work_Queue_Store::count_strict( 'cache_warmup_hot' ), 'warmup queue untouched by storefront request' );
check( false === $G::was_used_in_request(), 'purger-internal object-cache scope does not count as a Mobo mutation' );
delete_option( 'mobo_core_cache_warmup_enabled' );

echo "Guard detaches WP Rocket's per-save purge and restores it" . PHP_EOL;
reset_cache_state();
check( 10 === has_action( 'clean_post_cache', 'rocket_clean_post' ), 'WP Rocket listener registered before scope' );
$G::run(
	static function () use ( $G ) {
		check( false === has_action( 'clean_post_cache', 'rocket_clean_post' ), 'listener detached inside scope' );
		$G::run( static function () { clean_post_cache( 5 ); }, 'nested' );
		check( false === has_action( 'clean_post_cache', 'rocket_clean_post' ), 'nested scope exit does not re-attach early' );
	},
	'outer'
);
check( 10 === has_action( 'clean_post_cache', 'rocket_clean_post' ), 'listener restored once after the outermost scope' );
check( 1 === count( t_callbacks( 'clean_post_cache' ) ), 'listener not duplicated on restore' );
check( 0 === $GLOBALS['t_rocket']['clean_post'], 'no rocket_clean_post cascade from a guarded save' );
add_action( 'mobo_core_cache_mutation_guard_begin', $thrower = static function () { throw new RuntimeException( 'third-party guard boom' ); } );
$ran = false;
$G::run( static function () use ( &$ran ) { $ran = true; }, 'throwing-begin' );
remove_action( 'mobo_core_cache_mutation_guard_begin', $thrower );
check( $ran && ! $G::is_active(), 'throwing third-party begin hook neither aborts the mutation nor leaves the guard active' );
check( 10 === has_action( 'clean_post_cache', 'rocket_clean_post' ), 'listener restored after a throwing begin hook' );
clean_post_cache( 9 ); // outside any scope: normal WP Rocket behaviour preserved
check( 1 === $GLOBALS['t_rocket']['clean_post'], 'non-Mobo edits keep native WP Rocket behaviour' );

echo "Burst of 5000 product updates (safe mode, default)" . PHP_EOL;
reset_cache_state();
update_option( 'mobo_core_cache_warmup_enabled', '1' );
$slices = 0;
$product_urls = array();
for ( $start = 1; $start <= 5000; $start += 10 ) {
	$ids = range( $start, min( 5000, $start + 9 ) );
	foreach ( $ids as $id ) { $product_urls[] = get_permalink( $id ); }
	sync_slice( $ids );
	$slices++;
}
$during_calls = count( $GLOBALS['t_rocket']['clean_files'] );
check( 500 === $slices, '500 sync slices executed' );
check( $P::is_burst_active(), 'burst detected' );
check( $during_calls <= 10, 'immediate rocket_clean_files calls during the burst are bounded (' . $during_calls . ')' );
$warm_rows = Mobo_Core_Work_Queue_Store::count_strict( 'cache_warmup_hot' ) + Mobo_Core_Work_Queue_Store::count_strict( 'cache_warmup' );
check( $warm_rows <= 60, 'warmup admission stops once the burst is detected (' . $warm_rows . ' rows for 5000 updates)' );
$passes = drain_queue();
$total_calls = count( $GLOBALS['t_rocket']['clean_files'] );
$purged = rocket_urls();
$missing = 0;
foreach ( $product_urls as $u ) { if ( ! isset( $purged[ $u ] ) ) { $missing++; } }
check( $passes >= 20 && $passes <= 30, 'coalesced queue drained in bounded passes (' . $passes . ')' );
check( $total_calls <= 45, 'total rocket_clean_files calls bounded for 5000 updates (' . $total_calls . ')' );
check( max_urls_per_call() <= 200, 'URLs per purge call capped (' . max_urls_per_call() . ')' );
check( 0 === $missing, 'every changed product URL purged exactly via the coalesced queue (missing ' . $missing . ')' );
check( ! isset( $purged['https://shop.test/'] ), 'home URL never passed to rocket_clean_files (would wipe the whole domain cache)' );
check( 0 === $GLOBALS['t_rocket']['clean_domain'], 'no rocket_clean_domain' );
check( 0 === $GLOBALS['t_rocket']['clean_home'], 'no rocket_clean_home in safe mode' );
check( 0 === $GLOBALS['t_rocket']['clean_post'], 'no rocket_clean_post cascade (home + archives per product)' );
check( 0 === $GLOBALS['t_rocket']['preload'], 'no WP Rocket preload triggered' );
check( 0 === $GLOBALS['t_rocket']['rucss'], 'no RUCSS regeneration triggered' );
$health = $P::get_health_status();
check( 'safe' === $health['integrationMode'] && true === $health['burstActive'], 'health exposes mode and burst state' );
check( empty( get_option( 'mobo_core_cache_archive_purge_queue', array() ) ), 'queue fully drained' );

echo "Warmup is bounded, paused during bursts and disabled when off" . PHP_EOL;
$w = Mobo_Core_Cache_Warmer::process_batch( 10, 10, 'test' );
check( 'deferred-cache-burst' === $w['status'] && 0 === count( $GLOBALS['t_http'] ), 'no warmup GET while a burst is active' );
reset_cache_state(); // burst window cleared
update_option( 'mobo_core_cache_warmup_debounce_seconds', 0 );
sync_slice( array( 11, 12, 13, 14, 15 ) );
$queued = Mobo_Core_Work_Queue_Store::count_strict( 'cache_warmup_hot' );
check( 5 === $queued, 'small Mobo change queues its warmups (' . $queued . ')' );
$w = Mobo_Core_Cache_Warmer::process_batch( 10, 10, 'test' );
check( 2 === $w['processed'] && 2 === count( $GLOBALS['t_http'] ), 'safe mode warms at most 2 URLs per slice even when asked for 10 (' . count( $GLOBALS['t_http'] ) . ')' );
check( 3 === Mobo_Core_Work_Queue_Store::count_strict( 'cache_warmup_hot' ), 'remaining warmups stay queued for later slices' );
foreach ( $GLOBALS['t_http'] as $u ) { check( 0 === strpos( $u, 'https://shop.test/product/' ), 'warmup GET is an exact product page' ); }
update_option( 'mobo_core_cache_integration_mode', 'off' );
$GLOBALS['t_http'] = array();
$w = Mobo_Core_Cache_Warmer::process_batch( 10, 10, 'test' );
check( 'disabled-by-mode' === $w['status'] && 0 === count( $GLOBALS['t_http'] ), 'off mode disables warmup' );
delete_option( 'mobo_core_cache_warmup_enabled' );
$w = Mobo_Core_Cache_Warmer::process_batch( 10, 10, 'test' );
check( 'disabled' === $w['status'], 'warmup is opt-in (default off)' );

echo "Off mode: no page-cache calls, object cache still invalidated" . PHP_EOL;
reset_cache_state();
$objects_cleaned = 0;
add_action( 'clean_post_cache', $counter = static function () use ( &$objects_cleaned ) { $objects_cleaned++; } );
$r = sync_slice( array( 21, 22, 100021 ) );
remove_action( 'clean_post_cache', $counter );
check( 0 === count( $GLOBALS['t_rocket']['clean_files'] ) && 0 === $GLOBALS['t_rocket']['clean_post'], 'off mode makes no WP Rocket calls' );
check( $objects_cleaned >= 3, 'WordPress object cache invalidation still runs in off mode' );
check( empty( get_option( 'mobo_core_cache_archive_purge_queue', array() ) ), 'off mode queues nothing' );
$a = $P::process_due_archive_purge( 'test' );
check( 'disabled-by-mode' === $a['status'], 'deferred processor inert in off mode' );
delete_option( 'mobo_core_cache_integration_mode' );

echo "Cache plugin exceptions never stop processing" . PHP_EOL;
reset_cache_state();
$GLOBALS['t_rocket']['throw'] = true;
$completed = 0;
$threw = false;
try {
	for ( $start = 1; $start <= 50; $start += 10 ) { sync_slice( range( $start, $start + 9 ) ); $completed++; }
	drain_queue( 3 );
} catch ( Throwable $e ) {
	$threw = true;
}
check( ! $threw && 5 === $completed, 'all slices completed although rocket_clean_files throws' );
$archive_last = get_option( 'mobo_core_cache_archive_purge_last_result', array() );
check( isset( $archive_last['status'] ) && 'failed' === $archive_last['status'], 'deferred pass failure recorded and queue kept for retry' );
check( ! empty( get_option( 'mobo_core_cache_archive_purge_queue', array() ) ), 'failed deferred targets are retained, not lost' );
$last = get_option( 'mobo_core_cache_purge_last_result', array() );
check( isset( $last['integrations']['wpRocket']['status'] ) && 'failed' === $last['integrations']['wpRocket']['status'], 'failure recorded for health (wpRocket failed)' );
check( false !== strpos( (string) $last['lastError'], 'Simulated WP Rocket' ), 'bounded error message reported' );
check( ! $G::is_active() && 10 === has_action( 'clean_post_cache', 'rocket_clean_post' ), 'guard released and WP Rocket listener restored after failures' );
$GLOBALS['t_rocket']['throw'] = false;

echo "Below the burst threshold: immediate, capped per request" . PHP_EOL;
reset_cache_state();
$r = sync_slice( range( 1001, 1040 ) ); // 40 products in one request (< burst threshold 60)
check( 25 === $r['urls'], 'immediate purge capped at 25 URLs per request' );
check( 15 === $r['deferredUrls'], 'overflow deferred to the coalesced queue' );
drain_queue();
$purged = rocket_urls();
$missing = 0;
foreach ( range( 1001, 1040 ) as $id ) { if ( ! isset( $purged[ get_permalink( $id ) ] ) ) { $missing++; } }
check( 0 === $missing, 'capped overflow is still purged later' );

echo "Aggressive mode: home page only in the deferred pass, never the domain" . PHP_EOL;
reset_cache_state();
update_option( 'mobo_core_cache_integration_mode', 'aggressive' );
sync_slice( range( 2001, 2010 ) );
drain_queue();
check( 1 === $GLOBALS['t_rocket']['clean_home'], 'one home purge for the coalesced archive pass' );
check( 0 === $GLOBALS['t_rocket']['clean_domain'] && 0 === $GLOBALS['t_rocket']['preload'], 'still no domain purge or preload' );
delete_option( 'mobo_core_cache_integration_mode' );

echo "Enqueue during an in-flight pass keeps the claim (no starvation)" . PHP_EOL;
reset_cache_state();
add_filter( 'mobo_core_cache_burst_threshold_products', $low = static function () { return 1; } );
sync_slice( range( 3001, 3600 ) );
remove_filter( 'mobo_core_cache_burst_threshold_products', $low );
$GLOBALS['t_rocket']['clean_files'] = array();
$reentered = false;
$GLOBALS['t_during_clean'] = static function () use ( &$reentered ) {
	$reentered = true;
	sync_slice( array( 3001, 4001 ) ); // 3001 is in the claimed batch, 4001 is new
};
$passes = drain_queue();
$counts = array();
foreach ( $GLOBALS['t_rocket']['clean_files'] as $call ) { foreach ( $call as $u ) { $counts[ $u ] = isset( $counts[ $u ] ) ? $counts[ $u ] + 1 : 1; } }
check( $reentered, 'enqueue happened while a pass held the claim' );
check( isset( $counts[ get_permalink( 4001 ) ] ), 'URL enqueued during the claim is purged' );
check( isset( $counts[ get_permalink( 3001 ) ] ) && $counts[ get_permalink( 3001 ) ] >= 2, 'URL re-changed during its own pass is purged again' );
check( isset( $counts[ get_permalink( 3600 ) ] ), 'tail of the queue is reached (no starvation)' );
check( max( $counts ) <= 3, 'no URL purged repeatedly' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
