<?php
/**
 * Standalone (no WordPress) test for the 10.34.78 request-local Custom Storage
 * row cache: hit/miss accounting, precise and foreign-write invalidation,
 * verified-write read-back semantics, wp_cache_flush() detection, error
 * non-caching, entity-meta and Product repository integration.
 *
 * Run: php tests-dev/storage-row-cache-test.php
 * PHP 7.4+ compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t_filters']   = array();
$GLOBALS['t_options']   = array( 'mobo_core_entity_meta_terminal_fence_v1' => 1 );
$GLOBALS['t_wp_cache']  = array();
$GLOBALS['t_post_type'] = array( 10 => 'product', 11 => 'product', 20 => 'product_variation' );

class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->code; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['t_filters'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value ) {
	if ( empty( $GLOBALS['t_filters'][ $tag ] ) ) { return $value; }
	foreach ( $GLOBALS['t_filters'][ $tag ] as $cb ) { $value = call_user_func( $cb, $value ); }
	return $value;
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function get_post_type( $id ) { return isset( $GLOBALS['t_post_type'][ $id ] ) ? $GLOBALS['t_post_type'][ $id ] : false; }
function wp_cache_get( $k, $g = '' ) { return isset( $GLOBALS['t_wp_cache'][ $g ][ $k ] ) ? $GLOBALS['t_wp_cache'][ $g ][ $k ] : false; }
function wp_cache_set( $k, $v, $g = '' ) { $GLOBALS['t_wp_cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['t_wp_cache'][ $g ][ $k ] ); return true; }
function wp_cache_flush() { $GLOBALS['t_wp_cache'] = array(); return true; }
function maybe_unserialize( $v ) { return is_string( $v ) && preg_match( '/^(a|s|i|b|O):/', $v ) ? @unserialize( $v ) : $v; }
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }

final class Mobo_Core_Storage_Schema {
	public static function table_name( $key ) { return 'wp_mobo_' . $key; }
	public static function is_ready() { return true; }
}
final class Mobo_Core_Connection_Context {
	public static function get_effective_connection_id() { return 1; }
}
final class Mobo_Core_Remote_Identity_Policy {
	public static function is_valid( $v ) { return is_string( $v ) && (bool) preg_match( '/^[A-Za-z0-9\-]{6,}$/', $v ); }
}

/** Scripted fake $wpdb: pattern-matched SQL over in-memory rows; applies the `query` filter like wpdb::query(). */
class Fake_WPDB {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $options = 'wp_options';
	public $last_error = '';
	public $insert_id = 0;
	public $fail_next = false;
	public $log = array();
	public $entity = array();
	public $products = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	private function run( $sql ) {
		$sql = apply_filters( 'query', $sql );
		$this->log[] = $sql;
		$this->last_error = '';
		if ( $this->fail_next ) { $this->fail_next = false; $this->last_error = 'simulated failure'; return null; }
		return $sql;
	}
	public function count_matching( $needle ) {
		$n = 0;
		foreach ( $this->log as $sql ) { if ( false !== strpos( $sql, $needle ) ) { $n++; } }
		return $n;
	}
	public function get_results( $sql, $output = null ) {
		if ( null === $this->run( $sql ) ) { return null; }
		if ( preg_match( "/FROM wp_mobo_entity_meta WHERE woo_object_id=(\d+)( AND meta_key='([^']*)')?/", $sql, $m ) ) {
			$rows = array();
			foreach ( $this->entity as $row ) {
				if ( (int) $row['woo_object_id'] === (int) $m[1] && ( empty( $m[3] ) || $row['meta_key'] === $m[3] ) ) { $rows[] = $row; }
			}
			return $rows;
		}
		return array();
	}
	public function get_col( $sql ) { return null === $this->run( $sql ) ? null : array(); }
	public function get_var( $sql ) { return null === $this->run( $sql ) ? null : null; }
	public function get_row( $sql, $output = null ) {
		if ( null === $this->run( $sql ) ) { return null; }
		if ( preg_match( '/FROM wp_mobo_products WHERE connection_id=(\d+) AND woo_product_id=(\d+)/', $sql, $m ) ) {
			foreach ( $this->products as $row ) {
				if ( (int) $row['connection_id'] === (int) $m[1] && (int) $row['woo_product_id'] === (int) $m[2] ) { return $row; }
			}
		}
		return null;
	}
	public function query( $sql ) {
		if ( null === $this->run( $sql ) ) { return false; }
		if ( preg_match( "/^UPDATE wp_mobo_entity_meta SET meta_value='([^']*)'.* WHERE id IN \(([0-9,]+)\)/", $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[2] ) );
			$n = 0;
			foreach ( $this->entity as &$row ) {
				if ( in_array( (int) $row['id'], $ids, true ) ) { $row['meta_value'] = stripslashes( $m[1] ); $n++; }
			}
			unset( $row );
			return $n;
		}
		if ( preg_match( "/^UPDATE wp_mobo_products SET portal_product_id=(\d+) WHERE woo_product_id=(\d+)/", $sql, $m ) ) {
			foreach ( $this->products as &$row ) {
				if ( (int) $row['woo_product_id'] === (int) $m[2] ) { $row['portal_product_id'] = $m[1]; }
			}
			unset( $row );
			return 1;
		}
		return 0;
	}
}

$wpdb = new Fake_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->entity = array(
	array( 'id' => 1, 'woo_object_id' => 10, 'meta_key' => '_mobo_a', 'meta_value' => 'A1', 'sort_order' => 1, 'legacy_meta_id' => null ),
	array( 'id' => 2, 'woo_object_id' => 10, 'meta_key' => '_mobo_b', 'meta_value' => 'B1', 'sort_order' => 2, 'legacy_meta_id' => null ),
	array( 'id' => 3, 'woo_object_id' => 11, 'meta_key' => '_mobo_a', 'meta_value' => 'X1', 'sort_order' => 1, 'legacy_meta_id' => null ),
);
$wpdb->products = array(
	array( 'id' => 7, 'connection_id' => 1, 'product_guid' => 'guid-0001', 'woo_product_id' => 10, 'portal_product_id' => '5' ),
);

$base = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $base . 'class-mobo-core-storage-row-cache.php';
require $base . 'class-mobo-core-storage-entity-meta-retirement.php';
require $base . 'class-mobo-core-product-state-repository.php';

$failures = 0;
$checks   = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$E = 'Mobo_Core_Storage_Entity_Meta_Retirement';
$C = 'Mobo_Core_Storage_Row_Cache';
$OBJ_QUERY = 'FROM wp_mobo_entity_meta WHERE woo_object_id=10 ORDER BY';

// --- 1. Entity meta reads: one object query serves every key.
check( array( 'A1' ) === $E::filter_read( null, 10, '_mobo_a', true ), 'entity read returns stored value (single => one-item array)' );
check( array( 'B1' ) === $E::filter_read( null, 10, '_mobo_b', true ), 'second key of same object returned' );
check( '' === $E::filter_read( null, 10, '_mobo_missing', true ), 'absent key => empty string' );
check( 1 === $wpdb->count_matching( $OBJ_QUERY ), 'three reads of one object cost exactly one SELECT' );
check( $C::stats()['hits'] >= 2, 'cache hits recorded' );

// --- 2. Verified write: write path reads fresh, read-after-write hits DB.
$before_fresh = $wpdb->count_matching( "woo_object_id=10 AND meta_key='_mobo_a'" );
check( true === $E::filter_update( null, 10, '_mobo_a', 'A2' ), 'filter_update succeeds' );
check( $wpdb->count_matching( "woo_object_id=10 AND meta_key='_mobo_a'" ) === $before_fresh + 1, 'write decision used a fresh per-key SELECT (never the cache)' );
check( array( 'A2' ) === $E::filter_read( null, 10, '_mobo_a', true ), 'read after write returns the freshly written value' );
check( 2 === $wpdb->count_matching( $OBJ_QUERY ), 'read after write re-queried the database' );
check( array( 'X1' ) === $E::filter_read( null, 11, '_mobo_a', true ), 'other object read' );
$q11 = $wpdb->count_matching( 'woo_object_id=11 ORDER BY' );
$E::filter_update( null, 10, '_mobo_b', 'B2' );
$E::filter_read( null, 11, '_mobo_a', true );
check( $q11 === $wpdb->count_matching( 'woo_object_id=11 ORDER BY' ), 'precise (scoped) write on object 10 keeps object 11 cached' );

// --- 3. Foreign write from any code path flushes the bucket via the query filter.
$E::filter_read( null, 10, '_mobo_b', true );
$wpdb->query( "UPDATE wp_mobo_entity_meta SET meta_value='B3' WHERE id IN (2)" );
check( array( 'B3' ) === $E::filter_read( null, 10, '_mobo_b', true ), 'foreign UPDATE detected; read sees new DB value' );
$n = $wpdb->count_matching( 'woo_object_id=11 ORDER BY' );
$E::filter_read( null, 11, '_mobo_a', true );
check( $n + 1 === $wpdb->count_matching( 'woo_object_id=11 ORDER BY' ), 'foreign write flushed the whole entity bucket (conservative)' );

// --- 4. wp_cache_flush() detection.
$E::filter_read( null, 10, '_mobo_a', true );
$n = $wpdb->count_matching( $OBJ_QUERY );
wp_cache_flush();
$E::filter_read( null, 10, '_mobo_a', true );
check( $n + 1 === $wpdb->count_matching( $OBJ_QUERY ), 'wp_cache_flush() invalidates the request-local cache' );

// --- 5. Read errors are never cached.
$C::flush_all();
$wpdb->fail_next = true;
check( '' === $E::filter_read( null, 10, '_mobo_a', true ), 'read error fails closed (empty, no legacy fallback)' );
$n = $wpdb->count_matching( $OBJ_QUERY );
check( array( 'A2' ) === $E::filter_read( null, 10, '_mobo_a', true ), 'next read after error succeeds' );
check( $n + 1 === $wpdb->count_matching( $OBJ_QUERY ), 'failed read was not cached' );

// --- 6. SELECTs never flush; ROLLBACK/DDL flush everything.
$wpdb->get_results( 'SELECT * FROM wp_mobo_entity_meta WHERE 1=0' );
$n = $wpdb->count_matching( $OBJ_QUERY );
$E::filter_read( null, 10, '_mobo_a', true );
check( $n === $wpdb->count_matching( $OBJ_QUERY ), 'SELECT on the table does not invalidate' );
$wpdb->query( 'ROLLBACK' );
$E::filter_read( null, 10, '_mobo_a', true );
check( $n + 1 === $wpdb->count_matching( $OBJ_QUERY ), 'ROLLBACK flushes all buckets' );
$wpdb->query( 'UPDATE wp_unrelated SET x=1' );
$E::filter_read( null, 10, '_mobo_a', true );
check( $n + 1 === $wpdb->count_matching( $OBJ_QUERY ), 'writes to unrelated tables keep the cache' );

// --- 7. Product repository row cache.
$repo = new Mobo_Core_Product_State_Repository();
$PQ = 'FROM wp_mobo_products WHERE connection_id=1 AND woo_product_id=10';
$row = $repo->get_by_woo_product_id( 10 );
$repo->get_by_woo_product_id( 10 );
check( is_array( $row ) && '5' === $row['portal_product_id'], 'repository returns row' );
check( 1 === $wpdb->count_matching( $PQ ), 'repeated repository read costs one SELECT' );
$wpdb->query( 'UPDATE wp_mobo_products SET portal_product_id=9 WHERE woo_product_id=10' );
$row = $repo->get_by_woo_product_id( 10 );
check( '9' === $row['portal_product_id'], 'write to Products table invalidates repository cache (read-after-write = DB)' );
check( 2 === $wpdb->count_matching( $PQ ), 'repository re-queried after write' );
check( null === $repo->get_by_woo_product_id( 99 ) && null === $repo->get_by_woo_product_id( 99 ), 'known-missing row returns null' );
check( 1 === $wpdb->count_matching( 'woo_product_id=99' ), 'known-missing row is cached too' );
$wpdb->products[] = array( 'id' => 8, 'connection_id' => 1, 'product_guid' => 'guid-0099', 'woo_product_id' => 99, 'portal_product_id' => '1' );
$wpdb->query( "INSERT INTO wp_mobo_products (id) VALUES (8)" );
check( is_array( $repo->get_by_woo_product_id( 99 ) ), 'INSERT flushes the known-missing entry' );
$repo->get_by_woo_product_id( 10 );
$n = $wpdb->count_matching( $PQ );
$C::forget_object( 10 );
$repo->get_by_woo_product_id( 10 );
check( $n + 1 === $wpdb->count_matching( $PQ ), 'forget_object() drops repository entries of that object' );
$n = $wpdb->count_matching( $PQ );
$C::flush_all(); // what Mobo_Core_Lock::acquire() does
$repo->get_by_woo_product_id( 10 );
check( $n + 1 === $wpdb->count_matching( $PQ ), 'flush_all() (lock acquire) forces fresh reads' );

// --- 8. Scoped writes and bounded memory.
$C::set( 'demo', 'k1', 'v1', 5 );
$C::register_table( 'demo', 'wp_demo_table' );
$C::begin_scoped_write( 'demo' );
$wpdb->query( 'UPDATE wp_demo_table SET a=1' );
$C::end_scoped_write( 'demo' );
$hit = false;
check( 'v1' === $C::get( 'demo', 'k1', $hit ) && $hit, 'scoped write does not flush its bucket (caller invalidates precisely)' );
$wpdb->query( 'DELETE FROM wp_demo_table WHERE a=1' );
$C::get( 'demo', 'k1', $hit );
check( ! $hit, 'unscoped DELETE flushes bucket' );
for ( $i = 0; $i < Mobo_Core_Storage_Row_Cache::MAX_ENTRIES_PER_BUCKET + 10; $i++ ) { $C::set( 'big', 'k' . $i, $i ); }
check( $C::stats()['entries'] <= Mobo_Core_Storage_Row_Cache::MAX_ENTRIES_PER_BUCKET + 50, 'bucket size is bounded' );
$C::get( 'big', 'k' . ( Mobo_Core_Storage_Row_Cache::MAX_ENTRIES_PER_BUCKET + 9 ), $hit );
check( $hit, 'newest entry survives eviction' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
