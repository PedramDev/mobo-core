<?php
/**
 * Data health ("سلامت داده") scanner + repairs, against the plugin's real SQL on
 * an in-memory SQLite database (tests-dev/sqlite-wpdb-stub.php).
 *
 *   php tests-dev/data-health-test.php      (PHP 7.4 and 8.x)
 *
 * Per corruption class: detection counts, dry-run findings (exact IDs, canonical,
 * class), automatic (a) repairs, admin-confirmed (b) apply, undo with
 * compare-and-swap, idempotency (rescan finds nothing, re-apply skips), backup
 * write failure (nothing changes), busy guard, Stop/resume, deadline-bounded
 * slices that resume to the same result, crash between findings insert and
 * state commit (no duplicate findings), memory guard, no-storage fallback, and
 * a counts-only health payload (no IDs/GUIDs/titles).
 *
 * No WordPress, no MySQL, no network.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MOBO_CORE_PLUGIN_DIR', dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/' );

$GLOBALS['t_options'] = array();
$GLOBALS['t_count']   = 0;
$GLOBALS['t_fail']    = 0;
$GLOBALS['t_locked']  = array();
$GLOBALS['t_dbdelta_broken'] = false;
$GLOBALS['t_upload_dir'] = sys_get_temp_dir() . '/mobo-dh-uploads-' . mt_rand( 100000, 999999 );

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { $s = strtolower( trim( rawurldecode( (string) $s ) ) ); return preg_replace( '/\s+/', '-', $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function apply_filters( $tag, $value ) { return $value; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['t_upload_dir'] ); }

/** dbDelta stand-in: MySQL DDL -> SQLite. */
function dbDelta( $sql ) {
	global $wpdb;
	if ( $GLOBALS['t_dbdelta_broken'] ) {
		return array();
	}
	if ( ! preg_match( '/CREATE TABLE (\S+) \((.*)\)\s*[^)]*;?\s*$/s', $sql, $m ) ) {
		return array();
	}
	$cols = array();
	foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^(PRIMARY KEY|KEY|UNIQUE KEY)/', $line ) ) {
			continue;
		}
		if ( preg_match( '/^id bigint/', $line ) ) {
			$cols[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT';
			continue;
		}
		$cols[] = preg_replace( '/\bunsigned\b|\bAUTO_INCREMENT\b/', '', $line );
	}
	$wpdb->pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . $m[1] . ' (' . implode( ', ', $cols ) . ')' );
	return array();
}

require __DIR__ . '/sqlite-wpdb-stub.php';

/** SQLite wpdb (shared stub, wrapped) with WordPress term tables and SHOW COLUMNS support. */
final class DH_Test_WPDB {
	public $terms = 'wp_terms';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	private $in;
	public function __construct() { $this->in = new Sqlite_Test_WPDB(); }
	public function __get( $name ) { return $this->in->$name; }
	public function __set( $name, $value ) { $this->in->$name = $value; }
	public function __isset( $name ) { return isset( $this->in->$name ); }
	public function __call( $method, $args ) { return call_user_func_array( array( $this->in, $method ), $args ); }
	public function esc_like( $text ) { return (string) $text; }
	public function get_col( $sql ) {
		if ( preg_match( '/^\s*SHOW COLUMNS FROM (\w+)/i', $sql, $m ) ) {
			$this->in->last_error = '';
			$st = $this->in->pdo->query( "SELECT name FROM pragma_table_info('" . $m[1] . "')" );
			return $st ? $st->fetchAll( PDO::FETCH_COLUMN ) : array();
		}
		return $this->in->get_col( $sql );
	}
}

/* Minimal collaborators (only what the data health code calls). */
final class Mobo_Core_Lock {
	public static $acquired = 0;
	public static function acquire( $name, $ttl = 30 ) { self::$acquired++; return 'tok'; }
	public static function release( $name, $token ) { return true; }
	public static function is_locked( $name ) { return ! empty( $GLOBALS['t_locked'][ $name ] ); }
	public static function recover_stale_locks( $names = array() ) {
		foreach ( $names as $name ) {
			$key = 'mobo_core_runtime_lock_' . $name;
			$GLOBALS['wpdb']->query( "DELETE FROM wp_options WHERE option_name='" . $key . "'" );
		}
		return array( 'recovered' => count( $names ) );
	}
}
final class Mobo_Core_Product_Map {
	public function upsert_product( $guid, $post_id ) { return $this->ins( $guid, $post_id, 'product', '' ); }
	public function upsert_variation( $guid, $post_id, $parent = '' ) { return $this->ins( $guid, $post_id, 'variation', $parent ); }
	private function ins( $guid, $id, $type, $parent ) {
		return false !== $GLOBALS['wpdb']->insert( 'wp_mobo_product_map', array( 'connection_id' => 1, 'remote_guid' => $guid, 'wp_post_id' => $id, 'object_type' => $type, 'parent_remote_guid' => $parent, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
	}
}

$inc = MOBO_CORE_PLUGIN_DIR . 'includes/';
require $inc . 'class-mobo-core-environment.php';
require $inc . 'class-mobo-core-request-deadline.php';
require $inc . 'class-mobo-core-table-cache.php';
require $inc . 'class-mobo-core-shutdown-guard.php';
require $inc . 'class-mobo-core-product-identity-policy.php';
require $inc . 'class-mobo-core-variation-identity-policy.php';
require $inc . 'class-mobo-core-data-health-store.php';
require $inc . 'class-mobo-core-data-health-detectors.php';
require $inc . 'class-mobo-core-data-health-repair.php';
require $inc . 'class-mobo-core-data-health.php';

function t_ok( $cond, $label ) {
	$GLOBALS['t_count']++;
	if ( $cond ) { echo "  ok   - {$label}\n"; return; }
	$GLOBALS['t_fail']++;
	echo "  FAIL - {$label}\n";
}
function t_same( $expected, $actual, $label ) {
	$ok = $expected === $actual;
	t_ok( $ok, $label . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

/* ------------------------------------------------------------------ */
/* Fixture                                                            */
/* ------------------------------------------------------------------ */

function q( $sql ) { return $GLOBALS['wpdb']->pdo->query( $sql ); }
function v( $sql ) { $st = q( $sql ); $r = $st ? $st->fetch( PDO::FETCH_NUM ) : false; return $r ? $r[0] : null; }
function post( $id, $type, $status = 'publish', $parent = 0, $title = 'T', $mime = '' ) {
	$GLOBALS['wpdb']->insert( 'wp_posts', array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_parent' => $parent, 'post_title' => $title, 'post_mime_type' => $mime ) );
}
function meta( $id, $k, $val ) { $GLOBALS['wpdb']->insert( 'wp_postmeta', array( 'post_id' => $id, 'meta_key' => $k, 'meta_value' => $val ) ); }
function rel( $oid, $tt ) { $GLOBALS['wpdb']->insert( 'wp_term_relationships', array( 'object_id' => $oid, 'term_taxonomy_id' => $tt, 'term_order' => 0 ) ); }
function mapr( $guid, $pid, $type ) { $GLOBALS['wpdb']->insert( 'wp_mobo_product_map', array( 'connection_id' => 1, 'remote_guid' => $guid, 'wp_post_id' => $pid, 'object_type' => $type, 'created_at' => 'x', 'updated_at' => 'x' ) ); }
function lookup( $pid, $status, $price = null ) { $GLOBALS['wpdb']->insert( 'wp_wc_product_meta_lookup', array( 'product_id' => $pid, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => $price, 'max_price' => $price, 'onsale' => 0, 'stock_quantity' => null, 'stock_status' => $status, 'rating_count' => 0, 'average_rating' => 0, 'total_sales' => 0, 'tax_status' => 'taxable', 'tax_class' => '' ) ); }
function metas( $id, $k ) { $out = array(); $st = q( "SELECT meta_value FROM wp_postmeta WHERE post_id=" . (int) $id . " AND meta_key='" . $k . "' ORDER BY meta_id" ); foreach ( $st ? $st->fetchAll( PDO::FETCH_COLUMN ) : array() as $x ) { $out[] = (string) $x; } return $out; }
function m1( $id, $k ) { $v = metas( $id, $k ); return isset( $v[0] ) ? $v[0] : ''; }
function status( $id ) { return (string) v( 'SELECT post_status FROM wp_posts WHERE ID=' . (int) $id ); }
function has_rel( $oid, $tt ) { return (int) v( "SELECT COUNT(*) FROM wp_term_relationships WHERE object_id={$oid} AND term_taxonomy_id={$tt}" ) > 0; }

function fresh_db( $with_map = true ) {
	$GLOBALS['wpdb'] = new DH_Test_WPDB();
	$GLOBALS['t_options'] = array();
	$GLOBALS['t_locked']  = array();
	$GLOBALS['t_dbdelta_broken'] = false;
	Mobo_Core_Data_Health_Store::reset_request_cache();
	Mobo_Core_Data_Health_Detectors::reset_request_cache();
	Mobo_Core_Table_Cache::reset();
	Mobo_Core_Shutdown_Guard::reset();
	Mobo_Core_Request_Deadline::reset();
	$pdo = $GLOBALS['wpdb']->pdo;
	$pdo->exec( 'DROP TABLE wp_posts' );
	$pdo->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT DEFAULT 'post', post_status TEXT DEFAULT 'publish', post_parent INTEGER DEFAULT 0, post_title TEXT DEFAULT '', post_mime_type TEXT DEFAULT '', post_content TEXT DEFAULT '', post_excerpt TEXT DEFAULT '', guid TEXT DEFAULT '')" );
	$pdo->exec( 'CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT)' );
	$pdo->exec( "CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, description TEXT DEFAULT '', parent INTEGER DEFAULT 0, count INTEGER DEFAULT 0)" );
	$pdo->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER, term_order INTEGER DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))' );
	$pdo->exec( 'CREATE TABLE wp_wc_product_meta_lookup (product_id INTEGER PRIMARY KEY, sku TEXT, global_unique_id TEXT, virtual INTEGER, downloadable INTEGER, min_price REAL, max_price REAL, onsale INTEGER, stock_quantity REAL, stock_status TEXT, rating_count INTEGER, average_rating REAL, total_sales INTEGER, tax_status TEXT, tax_class TEXT)' );
	$pdo->exec( 'CREATE TABLE wp_wc_product_attributes_lookup (product_id INTEGER, product_or_parent_id INTEGER, taxonomy TEXT, term_id INTEGER, is_variation_attribute INTEGER, in_stock INTEGER)' );
	if ( $with_map ) {
		$pdo->exec( "CREATE TABLE wp_mobo_product_map (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER DEFAULT 0, remote_guid TEXT, wp_post_id INTEGER, object_type TEXT, parent_remote_guid TEXT DEFAULT '', last_hash TEXT DEFAULT '', sync_incomplete INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)" );
	}
	$pdo->exec( "CREATE TABLE wp_mobo_sync_events (id INTEGER PRIMARY KEY, status TEXT, locked_until TEXT, updated_at TEXT, claim_token TEXT DEFAULT '')" );
	$pdo->exec( 'CREATE TABLE wp_mobo_image_queue (id INTEGER PRIMARY KEY, status TEXT, locked_until TEXT, updated_at TEXT)' );
	foreach ( array( array( 1, 'exclude-from-catalog', 'product_visibility' ), array( 2, 'exclude-from-search', 'product_visibility' ), array( 3, 'outofstock', 'product_visibility' ), array( 4, 'simple', 'product_type' ), array( 5, 'variable', 'product_type' ), array( 6, 'cat', 'product_cat' ), array( 7, '????', 'product_cat' ) ) as $t ) {
		$GLOBALS['wpdb']->insert( 'wp_terms', array( 'term_id' => $t[0], 'name' => $t[1], 'slug' => $t[1] ) );
		$GLOBALS['wpdb']->insert( 'wp_term_taxonomy', array( 'term_taxonomy_id' => $t[0], 'term_id' => $t[0], 'taxonomy' => $t[2] ) );
	}
}

/** The corrupted catalog: one or more objects per corruption class. */
function seed_catalog() {
	/* P100: canonical Mobo variable product (G1, mapped). */
	post( 100, 'product', 'publish', 0, 'Canonical phone case' ); meta( 100, 'product_guid', 'G1' ); meta( 100, '_stock_status', 'instock' ); rel( 100, 5 ); rel( 100, 6 ); mapr( 'G1', 100, 'product' ); lookup( 100, 'instock', 10 );
	post( 101, 'product_variation', 'publish', 100 ); meta( 101, 'variant_guid', 'VG1' ); meta( 101, 'product_guid', 'G1' ); meta( 101, 'attribute_pa_color', 'Red' ); meta( 101, '_stock_status', 'outofstock' ); meta( 101, '_price', '10' ); mapr( 'VG1', 101, 'variation' ); lookup( 101, 'instock', 10 );
	/* V102: same variant GUID + same attributes (value written as slug), unmapped. */
	post( 102, 'product_variation', 'publish', 100 ); meta( 102, 'variant_guid', 'VG1' ); meta( 102, 'attribute_pa_color', 'red' ); meta( 102, '_stock_status', 'instock' ); meta( 102, '_price', '9' ); lookup( 102, 'instock', 9 );
	post( 103, 'product_variation', 'publish', 100 ); meta( 103, 'variant_guid', 'VG3' ); meta( 103, 'attribute_pa_color', 'Blue' ); meta( 103, '_stock_status', 'instock' ); meta( 103, '_price', '11' ); mapr( 'VG3', 103, 'variation' ); lookup( 103, 'instock', 11 );
	/* V104: unmapped attribute duplicate of V103 with its own (different) GUID. */
	post( 104, 'product_variation', 'publish', 100 ); meta( 104, 'variant_guid', 'VG4' ); meta( 104, 'attribute_pa_color', 'blue' ); meta( 104, '_stock_status', 'instock' ); meta( 104, '_price', '11' ); lookup( 104, 'instock', 11 );
	/* P200: scheduled copy that carries P100's identity (no map row). */
	post( 200, 'product', 'future', 0, 'Copy of phone case' ); meta( 200, 'product_guid', 'G1' ); meta( 200, 'portal_product_id', '55' ); meta( 200, '_stock_status', 'instock' ); meta( 200, '_manage_stock', 'no' ); rel( 200, 4 ); lookup( 200, 'instock', 10 );
	/* P300: customer's own simple product with two identical variations. */
	post( 300, 'product', 'publish', 0, 'Own product' ); rel( 300, 4 ); lookup( 300, 'instock' );
	post( 301, 'product_variation', 'publish', 300 ); meta( 301, 'attribute_size', 'L' ); lookup( 301, 'instock' );
	post( 302, 'product_variation', 'publish', 300 ); meta( 302, 'attribute_size', 'L' ); lookup( 302, 'instock' );
	/* V400: orphan variation. */
	post( 400, 'product_variation', 'publish', 9999 ); meta( 400, '_stock_status', 'instock' ); lookup( 400, 'instock' );
	/* P500: unique Mobo GUID without a map row (lookup row missing). */
	post( 500, 'product', 'publish', 0, 'Unmapped' ); meta( 500, 'product_guid', 'G5' ); meta( 500, '_regular_price', '5' ); meta( 500, '_regular_price', '6' ); meta( 500, '_price', '5' ); meta( 500, '_stock_status', 'instock' ); rel( 500, 4 );
	/* Broken map row. */
	mapr( 'GX', 8888, 'product' );
	/* Orphan index rows. */
	rel( 7777, 6 ); rel( 7777, 3 ); lookup( 6666, 'instock', 1 );
	$GLOBALS['wpdb']->insert( 'wp_wc_product_attributes_lookup', array( 'product_id' => 5555, 'product_or_parent_id' => 5555, 'taxonomy' => 'pa_color', 'term_id' => 1, 'is_variation_attribute' => 1, 'in_stock' => 1 ) );
	$GLOBALS['wpdb']->insert( 'wp_wc_product_attributes_lookup', array( 'product_id' => 5555, 'product_or_parent_id' => 5555, 'taxonomy' => 'pa_size', 'term_id' => 2, 'is_variation_attribute' => 1, 'in_stock' => 1 ) );
	/* P600: thumbnail + gallery reference missing attachments. */
	post( 600, 'product', 'publish', 0, 'Media' ); meta( 600, '_thumbnail_id', '4444' ); meta( 600, '_product_image_gallery', '4445,10' ); rel( 600, 4 ); lookup( 600, 'instock' );
	post( 10, 'attachment', 'inherit', 0, 'a', 'image/jpeg' ); meta( 10, '_wp_attached_file', '2025/a.jpg' );
	post( 11, 'attachment', 'inherit', 0, 'b', 'image/jpeg' ); meta( 11, '_wp_attached_file', '2025/b.jpg' );
	post( 12, 'attachment', 'inherit', 0, 'c', 'image/jpeg' ); meta( 12, '_wp_attached_file', '2025/b.jpg' );
	/* P700: no product_type term, invalid stock status. P800: ???? title. P900: trashed Mobo product. */
	post( 700, 'product', 'publish', 0, 'No type' ); meta( 700, '_stock_status', 'weird' ); lookup( 700, 'weird' );
	post( 800, 'product', 'publish', 0, '???? ????' ); rel( 800, 4 ); lookup( 800, 'instock' );
	post( 900, 'product', 'trash', 0, 'Trashed' ); meta( 900, 'product_guid', 'G9' );
	/* Runtime state. */
	$GLOBALS['wpdb']->insert( 'wp_options', array( 'option_name' => 'mobo_core_runtime_lock_old_worker', 'option_value' => json_encode( array( 'token' => 'x', 'expires_at' => time() - 86400 ) ) ) );
	$GLOBALS['wpdb']->insert( 'wp_options', array( 'option_name' => 'mobo_core_runtime_lock_live_worker', 'option_value' => json_encode( array( 'token' => 'y', 'expires_at' => time() + 60 ) ) ) );
	$GLOBALS['wpdb']->insert( 'wp_mobo_sync_events', array( 'id' => 1, 'status' => 'processing', 'locked_until' => null, 'updated_at' => '2026-01-01 00:00:00', 'claim_token' => 'abc' ) );
	$GLOBALS['wpdb']->insert( 'wp_mobo_sync_events', array( 'id' => 2, 'status' => 'processing', 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 600 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	@mkdir( $GLOBALS['t_upload_dir'] . '/2025', 0777, true );
	file_put_contents( $GLOBALS['t_upload_dir'] . '/2025/b.jpg', 'JPEG' );
}

/** Each simulated request gets its own fresh 24 s deadline. */
function fresh_deadline() {
	$now = microtime( true );
	Mobo_Core_Request_Deadline::reset( array( 'start' => $now, 'limit' => 30, 'budget' => 24.0, 'deadline' => $now + 24.0 ) );
}
function run_until_idle( $budget = 20.0, $max = 200 ) {
	$slices = 0;
	do {
		fresh_deadline();
		$out = Mobo_Core_Data_Health::run_slice( $budget );
		$slices++;
	} while ( ( 'running' === $out['state']['status'] || 'running' === $out['job']['status'] ) && $slices < $max );
	return $slices;
}
function dcount( $id ) { $s = Mobo_Core_Data_Health::get_state(); return isset( $s['detectors'][ $id ] ) ? (int) $s['detectors'][ $id ]['count'] : -1; }
function dclass( $id, $c ) { $s = Mobo_Core_Data_Health::get_state(); return (int) $s['detectors'][ $id ]['byClass'][ $c ]; }
function findings( $detector, $status = '' ) { $s = Mobo_Core_Data_Health::get_state(); $r = Mobo_Core_Data_Health_Store::findings_page( $s['lastCompleteScan'], $detector, 0, 1000, $status ); return is_array( $r ) ? $r : array(); }
function ids_of( $rows, $class = '' ) { $out = array(); foreach ( $rows as $r ) { if ( '' === $class || $r['repair_class'] === $class ) { $out[] = (int) $r['object_id']; } } sort( $out ); return $out; }

/* ================================================================== */
echo "[1] Detection on a corrupted catalog (auto repair off)\n";
fresh_db();
seed_catalog();
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '0' );
$st = Mobo_Core_Data_Health::start_scan( 'test' );
t_ok( is_array( $st ) && 'running' === $st['status'] && true === $st['storage'], 'scan starts and creates its findings/backup tables' );
run_until_idle();
$state = Mobo_Core_Data_Health::get_state();
t_same( 'complete', $state['status'], 'scan completes' );
t_same( 1, dcount( 'dup_product_guid' ), 'C01 duplicate product GUID: 1 copy' );
t_same( array( 200 ), ids_of( findings( 'dup_product_guid' ), 'b' ), '  dry-run lists exactly the copy (P200) as admin-confirm' );
$f = findings( 'dup_product_guid' );
t_same( 100, (int) $f[0]['canonical_id'], '  canonical is the mapped owner P100' );
t_same( array( 102 ), ids_of( findings( 'dup_variant_guid' ), 'b' ), 'C02 duplicate variant GUID: V102 (canonical V101)' );
t_same( array( 102, 104 ), ids_of( findings( 'dup_attribute_set' ), 'b' ), 'C08 duplicate attribute set (sanitize_title-normalized): V102, V104 confirmable' );
t_same( array( 301, 302 ), ids_of( findings( 'dup_attribute_set' ), 'c' ), '  customer catalog duplicates (P300) are report-only' );
t_same( 3, (int) $state['detectors']['dup_attribute_set']['groups'], '  3 groups (2 under the Mobo parent, 1 under the customer parent)' );
t_same( array( 500 ), ids_of( findings( 'unmapped_object' ), 'a' ), 'C03 unique unmapped GUID (P500) is automatic seed' );
t_same( array( 104 ), ids_of( findings( 'unmapped_object', 'open' ), 'c' ), '  unmapped variation with an attribute twin (V104) is never adopted automatically' );
t_same( 2, (int) $state['detectors']['unmapped_object']['covered'], '  duplicate losers (P200, V102) are covered by the duplicate detectors, not double-counted' );
t_same( 1, dcount( 'broken_map_row' ), 'C05 broken map row' );
t_same( array( 400 ), ids_of( findings( 'orphan_variation' ), 'b' ), 'C06 orphan variation V400' );
t_same( 2, dcount( 'variation_under_simple' ), 'C07 variations under non-variable parent: 2 (weight = variations)' );
t_same( 2, dcount( 'orphan_term_relationship' ), 'C11 orphan product term relationships: 2' );
$lk = findings( 'lookup_missing_or_stale' );
t_same( array( 101, 500 ), ids_of( $lk ), 'C12 lookup rows to rebuild: missing (P500) + stale stock (V101)' );
t_ok( in_array( 500, ids_of( $lk ), true ) && in_array( 101, ids_of( $lk ), true ) && ! in_array( 102, ids_of( $lk ), true ), '  P500 (missing) and V101 (stock mismatch) found, healthy V102 not' );
t_same( 1, dcount( 'lookup_orphan' ), 'C12b orphan lookup row' );
t_same( 2, dcount( 'attr_lookup_orphan' ), 'C13 orphan attribute lookup rows: 2' );
t_same( array( 600 ), ids_of( findings( 'media_ref_missing' ) ), 'C14 dangling thumbnail/gallery reference (P600)' );
t_same( array( 10 ), ids_of( findings( 'attachment_file_missing' ) ), 'C15 attachment file missing on disk (#10), existing file not reported' );
t_same( 2, dcount( 'shared_attached_file' ), 'C20 shared physical file: 2 attachments' );
t_same( array( 700 ), ids_of( findings( 'product_type_term' ) ), 'C09 product without product_type' );
t_same( array( 500 ), ids_of( findings( 'duplicate_single_meta' ) ), 'C16 duplicate _regular_price rows' );
t_same( array( 700 ), ids_of( findings( 'stock_meta_invalid' ) ), 'C17 invalid stock status' );
t_same( array( 800 ), ids_of( findings( 'question_mark_text' ) ), 'C18 ???? title' );
t_same( 1, dcount( 'question_mark_terms' ), 'C18b ???? term name' );
t_same( array( 900 ), ids_of( findings( 'trashed_mobo_products' ) ), 'C19 trashed Mobo product' );
t_same( 1, dcount( 'stale_locks' ), 'C22 one expired runtime lock (live lock ignored)' );
t_same( 1, dcount( 'stale_processing_rows' ), 'C23 one stuck processing event (live claim ignored)' );
t_same( 0, dcount( 'false_success_timestamp' ), 'R6 detector is a no-op without migration/sync-health tables' );
$unions = array_filter( $GLOBALS['wpdb']->queries, static function ( $sql ) { return false !== strpos( $sql, 'UNION ALL' ); } );
$mixed  = array_filter( $unions, static function ( $sql ) { return 1 === preg_match( '/SELECT (?!BINARY)\w+\.\w+ AS g/', $sql ); } );
t_ok( count( $unions ) > 0 && 0 === count( $mixed ), 'identity UNIONs compare GUIDs as BINARY (no "Illegal mix of collations" between core and plugin tables)' );
$sum  = Mobo_Core_Data_Health::health_summary();
$json = json_encode( $sum );
t_ok( false === strpos( $json, 'G1' ) && false === strpos( $json, 'VG1' ) && false === strpos( $json, 'phone' ) && false === strpos( $json, '"samples"' ), 'health payload has counts only (no GUIDs, titles or sample IDs)' );
t_ok( $sum['byRepairClass']['automatic'] > 0 && $sum['byRepairClass']['adminConfirm'] > 0 && $sum['byRepairClass']['reportOnly'] > 0, 'health payload splits counts by repair class' );
t_same( 'idle', Mobo_Core_Data_Health::get_job()['status'], 'auto repair disabled: no job started' );

/* ================================================================== */
echo "\n[2] Automatic (a) repairs with backup\n";
$job = Mobo_Core_Data_Health::start_apply( array( 'unmapped_object', 'broken_map_row', 'orphan_term_relationship', 'lookup_missing_or_stale', 'lookup_orphan', 'attr_lookup_orphan', 'stale_locks' ), array( 'a' ), 'auto' );
t_ok( is_array( $job ) && 'running' === $job['status'], 'auto job created' );
$auto_batch = $job['batchId'];
run_until_idle();
$job = Mobo_Core_Data_Health::get_job();
t_same( 'complete', $job['status'], 'auto job completes' );
t_same( 0, (int) $job['failed'], '  no failures' );
t_same( 1, (int) v( "SELECT COUNT(*) FROM wp_mobo_product_map WHERE remote_guid='G5' AND wp_post_id=500" ), 'C03 map row seeded for P500' );
t_same( 0, (int) v( 'SELECT COUNT(*) FROM wp_mobo_product_map WHERE wp_post_id=8888' ), 'C05 broken map row removed' );
t_ok( ! has_rel( 7777, 6 ) && ! has_rel( 7777, 3 ), 'C11 orphan term relationships removed' );
t_same( 1, (int) v( 'SELECT count FROM wp_term_taxonomy WHERE term_taxonomy_id=6' ), '  product_cat count recounted (P100 only)' );
t_same( 'outofstock', (string) v( 'SELECT stock_status FROM wp_wc_product_meta_lookup WHERE product_id=101' ), 'C12 stale lookup stock fixed from meta (V101)' );
t_same( 5.0, (float) v( 'SELECT min_price FROM wp_wc_product_meta_lookup WHERE product_id=500' ), 'C12 missing lookup row built for P500 (min_price from _price)' );
t_same( 0, (int) v( 'SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=6666' ), 'C12b orphan lookup row removed' );
t_same( 0, (int) v( 'SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE product_id=5555' ), 'C13 orphan attribute lookup rows removed' );
t_same( 0, (int) v( "SELECT COUNT(*) FROM wp_options WHERE option_name='mobo_core_runtime_lock_old_worker'" ), 'C22 expired lock released' );
t_same( 1, (int) v( "SELECT COUNT(*) FROM wp_options WHERE option_name='mobo_core_runtime_lock_live_worker'" ), '  live lock untouched' );
t_same( 'publish', status( 102 ), 'automatic job never touches admin-confirm findings (V102 still published)' );
t_same( 'G1', m1( 200, 'product_guid' ), '  P200 still carries its identity' );
t_ok( (int) v( "SELECT COUNT(*) FROM wp_mobo_data_health_backup WHERE batch_id='{$auto_batch}' AND status='applied'" ) >= 9, 'every automatic change has an applied backup row' );

/* ================================================================== */
echo "\n[3] Admin-confirmed (b): duplicates, orphan, media, stuck events\n";
$GLOBALS['t_locked']['manual_sync'] = true;
$blocked = Mobo_Core_Data_Health::start_apply( array( 'dup_product_guid' ), array( 'b' ), 'admin' );
t_ok( is_wp_error( $blocked ) && 'mobo_core_data_health_blocked' === $blocked->get_error_code(), 'busy guard: no repair while Sync/Repair holds manual_sync' );
$GLOBALS['t_locked'] = array();
$job = Mobo_Core_Data_Health::start_apply( array( 'dup_product_guid' ), array( 'b' ), 'admin' );
$prod_batch = $job['batchId'];
run_until_idle();
t_same( 'future', status( 200 ), 'P200 keeps its post status/URL (never trashed/drafted)' );
t_same( '1', m1( 200, '_mobo_quarantine_sale_blocked' ), '  storefront sale guard flag set' );
t_same( 'outofstock', m1( 200, '_stock_status' ), '  persisted out of stock' );
t_ok( has_rel( 200, 1 ) && has_rel( 200, 2 ) && has_rel( 200, 3 ), '  hidden from catalog and search (product_visibility terms)' );
t_same( array(), metas( 200, 'product_guid' ), '  duplicate identity moved out of the GUID key' );
$retired = json_decode( m1( 200, '_mobo_data_health_retired_identity' ), true );
t_same( array( 'G1' ), $retired['identity']['product_guid'], '  ... into the forensic marker (with portal id alias)' );
t_same( array( '55' ), $retired['identity']['portal_product_id'], '  portal_product_id alias retired too' );
t_same( 'G1', m1( 100, 'product_guid' ), 'canonical P100 untouched' );
t_same( 'outofstock', (string) v( 'SELECT stock_status FROM wp_wc_product_meta_lookup WHERE product_id=200' ), '  lookup row rebuilt after the change' );

$job = Mobo_Core_Data_Health::start_apply( array( 'dup_variant_guid' ), array( 'b' ), 'admin' );
$var_batch = $job['batchId'];
run_until_idle();
t_same( 'private', status( 102 ), 'V102 disabled (private = WooCommerce "Enabled" off), not deleted' );
t_same( 'outofstock', m1( 102, '_stock_status' ), '  out of stock' );
t_same( array(), metas( 102, 'variant_guid' ), '  duplicate variant identity retired' );
t_same( 'publish', status( 101 ), '  canonical V101 untouched' );

$job = Mobo_Core_Data_Health::start_apply( array( 'dup_attribute_set', 'orphan_variation', 'media_ref_missing', 'stale_processing_rows' ), array( 'b' ), 'admin' );
$misc_batch = $job['batchId'];
run_until_idle();
$job = Mobo_Core_Data_Health::get_job();
t_same( 'private', status( 104 ), 'C08 V104 disabled (canonical V103 stays)' );
t_same( 'VG4', m1( 104, 'variant_guid' ), '  attribute duplicate keeps its own identity (only disabled)' );
t_ok( (int) $job['skipped'] >= 1, '  V102 (already disabled by C02) is skipped, not changed twice' );
t_same( 'publish', status( 301 ), '  customer catalog duplicate (report-only) untouched' );
t_same( 'private', status( 400 ), 'C06 orphan V400 disabled' );
t_same( array(), metas( 600, '_thumbnail_id' ), 'C14 dangling thumbnail removed' );
t_same( array( '10' ), metas( 600, '_product_image_gallery' ), '  gallery keeps the existing attachment only' );
t_same( 1, (int) v( "SELECT COUNT(*) FROM wp_posts WHERE ID=10 AND post_type='attachment'" ), '  no attachment deleted' );
t_same( 'pending', (string) v( 'SELECT status FROM wp_mobo_sync_events WHERE id=1' ), 'C23 stuck event reclaimed to pending' );
t_same( '', (string) v( 'SELECT claim_token FROM wp_mobo_sync_events WHERE id=1' ), '  stale claim token cleared' );
t_same( 'processing', (string) v( 'SELECT status FROM wp_mobo_sync_events WHERE id=2' ), '  live claim untouched' );

/* ================================================================== */
echo "\n[4] Idempotency\n";
$again = Mobo_Core_Data_Health::start_apply( array( 'dup_product_guid' ), array( 'b' ), 'admin' );
t_ok( is_array( $again ), 're-apply on the same scan can be started' );
run_until_idle();
t_same( 0, (int) Mobo_Core_Data_Health::get_job()['applied'], '  ... and changes nothing (findings no longer open)' );
Mobo_Core_Data_Health::start_scan( 'test' );
run_until_idle();
foreach ( array( 'dup_product_guid', 'dup_variant_guid', 'orphan_variation', 'broken_map_row', 'orphan_term_relationship', 'lookup_orphan', 'attr_lookup_orphan', 'media_ref_missing', 'stale_locks', 'stale_processing_rows' ) as $d ) {
	t_same( 0, dcount( $d ), "rescan: {$d} = 0" );
}
t_same( 0, dclass( 'dup_attribute_set', 'b' ), 'rescan: no confirmable attribute duplicates left' );
t_same( 0, dclass( 'unmapped_object', 'a' ), 'rescan: no unique unmapped objects left' );
t_same( 0, dclass( 'lookup_missing_or_stale', 'a' ), 'rescan: lookup rows consistent' );

/* ================================================================== */
echo "\n[5] Undo with compare-and-swap\n";
$u = Mobo_Core_Data_Health::start_undo( $var_batch, 'admin' );
t_ok( is_array( $u ), 'undo job created' );
run_until_idle();
t_same( 'publish', status( 102 ), 'V102 published again' );
t_same( array( 'VG1' ), metas( 102, 'variant_guid' ), '  identity restored' );
t_same( 'instock', m1( 102, '_stock_status' ), '  stock restored' );
t_ok( ! has_rel( 102, 3 ), '  outofstock term removed again' );
t_same( 1, (int) Mobo_Core_Data_Health::get_job()['undone'], '  1 row undone' );

/* Merchant edits P200 after the repair: undo must not overwrite it. */
q( "UPDATE wp_postmeta SET meta_value='instock' WHERE post_id=200 AND meta_key='_stock_status'" );
Mobo_Core_Data_Health::start_undo( $prod_batch, 'admin' );
run_until_idle();
$job = Mobo_Core_Data_Health::get_job();
t_same( 1, (int) $job['conflicts'], 'P200 changed after repair -> conflict' );
t_same( array(), metas( 200, 'product_guid' ), '  conflicting object left exactly as the merchant left it' );
$prod_status = '';
foreach ( Mobo_Core_Data_Health::get_batches() as $b ) { if ( $b['id'] === $prod_batch ) { $prod_status = $b['status']; } }
t_same( 'partially_undone', $prod_status, '  batch recorded as partially undone (the conflict row stays as the merchant left it)' );

$u = Mobo_Core_Data_Health::start_undo( $auto_batch, 'admin' );
run_until_idle();
t_ok( has_rel( 7777, 6 ) && has_rel( 7777, 3 ), 'automatic batch undo restores orphan term relationships' );
t_same( 1, (int) v( 'SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=6666' ), '  orphan lookup row restored' );
t_same( 2, (int) v( 'SELECT COUNT(*) FROM wp_wc_product_attributes_lookup WHERE product_id=5555' ), '  attribute lookup rows restored' );
t_same( 1, (int) v( 'SELECT COUNT(*) FROM wp_mobo_product_map WHERE wp_post_id=8888' ), '  broken map row restored' );
t_same( 0, (int) v( "SELECT COUNT(*) FROM wp_mobo_product_map WHERE remote_guid='G5'" ), '  seeded map row removed' );
Mobo_Core_Data_Health::start_scan( 'test' );
run_until_idle();
t_same( 2, dcount( 'orphan_term_relationship' ), '  rescan after undo finds the original orphan rows again' );

/* ================================================================== */
echo "\n[6] Backup write failure -> nothing changes\n";
$GLOBALS['wpdb']->fail_pattern = '/INSERT INTO wp_mobo_data_health_backup/';
Mobo_Core_Data_Health::start_apply( array( 'orphan_term_relationship' ), array( 'a' ), 'auto' );
run_until_idle( 20.0, 3 );
$GLOBALS['wpdb']->fail_pattern = '';
$job = Mobo_Core_Data_Health::get_job();
t_ok( (int) $job['failed'] >= 1 && 0 === (int) $job['applied'], 'apply reports failure' );
t_ok( has_rel( 7777, 6 ), '  orphan row NOT deleted without a verified backup' );

/* ================================================================== */
echo "\n[7] Stop / resume, crash between insert and commit\n";
fresh_db();
seed_catalog();
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '0' );
Mobo_Core_Data_Health::start_scan( 'test' );
Mobo_Core_Data_Health::request_stop();
t_same( 'stopped', Mobo_Core_Data_Health::get_state()['status'], 'Stop marks the scan stopped' );
fresh_deadline();
$out = Mobo_Core_Data_Health::run_slice( 5.0 );
t_same( '', $out['ran'], '  a stopped scan does not run' );
Mobo_Core_Data_Health::resume();
run_until_idle();
t_same( 'complete', Mobo_Core_Data_Health::get_state()['status'], '  resume completes it' );
$reference = array();
foreach ( Mobo_Core_Data_Health::get_state()['detectors'] as $id => $d ) { $reference[ $id ] = (int) $d['count']; }
$ref_rows = (int) v( 'SELECT COUNT(*) FROM wp_mobo_data_health_findings' );
/* Crash: rerun one step after its findings were written but the state was not committed. */
Mobo_Core_Data_Health::start_scan( 'test' );
fresh_deadline();
Mobo_Core_Data_Health::run_slice( 0.6 );
$snapshot = Mobo_Core_Data_Health::get_state();
for ( $i = 0; $i < 3; $i++ ) { fresh_deadline(); Mobo_Core_Data_Health::run_slice( 0.6 ); }
update_option( Mobo_Core_Data_Health::STATE_OPTION, $snapshot ); // roll the committed state back
run_until_idle();
$after = array();
foreach ( Mobo_Core_Data_Health::get_state()['detectors'] as $id => $d ) { $after[ $id ] = (int) $d['count']; }
t_same( $reference, $after, 'resumed-after-crash scan reports identical counts' );
t_same( $ref_rows, (int) v( 'SELECT COUNT(*) FROM wp_mobo_data_health_findings' ), '  and no duplicate findings rows' );

/* ================================================================== */
echo "\n[8] Deadline-bounded slices on a large sparse catalog\n";
fresh_db();
seed_catalog();
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '0' );
for ( $id = 20000; $id <= 1200000; $id += 20000 ) { post( $id, 'product', 'publish', 0, 'Filler' ); rel( $id, 4 ); lookup( $id, 'instock' ); }
Mobo_Core_Data_Health::start_scan( 'test' );
$slices = 0;
do {
	/* Pin a request that has 2.6 s left: the slice budget is clamped to ~0.6 s. */
	$now = microtime( true );
	Mobo_Core_Request_Deadline::reset( array( 'start' => $now, 'limit' => 30, 'budget' => 2.6, 'deadline' => $now + 2.6 ) );
	$t0  = microtime( true );
	$out = Mobo_Core_Data_Health::run_slice( 8.0 );
	$dt  = microtime( true ) - $t0;
	$slices++;
	if ( $dt > 2.4 ) { t_ok( false, "slice {$slices} overran the request deadline ({$dt}s)" ); }
} while ( 'running' === $out['state']['status'] && $slices < 400 );
Mobo_Core_Request_Deadline::reset();
t_same( 'complete', $out['state']['status'], 'scan completes across ' . $slices . ' bounded slices' );
t_ok( $slices > 1, '  work really was split (>1 slice)' );
t_same( 1, dcount( 'dup_product_guid' ), '  same result as an unbounded scan (C01)' );
t_same( 2, dcount( 'orphan_term_relationship' ), '  same result (C11)' );
t_ok( (float) $out['state']['maxSliceSeconds'] < 2.0, '  longest slice stayed inside the budget (' . $out['state']['maxSliceSeconds'] . 's)' );
Mobo_Core_Request_Deadline::reset( array( 'start' => microtime( true ) - 30, 'limit' => 30, 'budget' => 24, 'deadline' => microtime( true ) - 6 ) );
Mobo_Core_Data_Health::start_scan( 'test' );
$out = Mobo_Core_Data_Health::run_slice( 8.0 );
t_same( '', $out['ran'], 'no work at all when the request deadline has passed' );
Mobo_Core_Request_Deadline::reset();

/* ================================================================== */
echo "\n[9] Memory guard\n";
if ( Mobo_Core_Environment::can( 'ini_set' ) && Mobo_Core_Environment::can( 'ini_get' ) && Mobo_Core_Environment::can( 'memory_get_usage' ) ) {
	Mobo_Core_Data_Health::start_scan( 'test' );
	fresh_deadline();
	$old = ini_get( 'memory_limit' );
	ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 8 * 1048576 ) );
	$before = Mobo_Core_Data_Health::get_state();
	Mobo_Core_Data_Health::run_slice( 5.0 );
	$after = Mobo_Core_Data_Health::get_state();
	ini_set( 'memory_limit', $old );
	t_ok( (int) $after['memoryStops'] > (int) $before['memoryStops'] && (int) $after['steps'] === (int) $before['steps'], 'low memory headroom stops the slice before any step' );
	run_until_idle();
	t_same( 'complete', Mobo_Core_Data_Health::get_state()['status'], '  and the scan resumes once memory is available' );
} else {
	t_ok( true, 'memory guard: memory functions disabled on this host (headroom unknown -> unlimited)' );
}

/* ================================================================== */
echo "\n[9b] Shared runtime helpers: table cache, shutdown guard, per-item yield\n";
fresh_db();
seed_catalog();
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '0' );
Mobo_Core_Data_Health::start_scan( 'test' );
run_until_idle();
$probes = Mobo_Core_Table_Cache::$probes;
Mobo_Core_Data_Health_Detectors::reset_request_cache();
Mobo_Core_Data_Health_Store::reset_request_cache();
Mobo_Core_Table_Cache::reset();
Mobo_Core_Data_Health::start_scan( 'test' );
run_until_idle();
$absent = 0;
foreach ( array( 'custom_products', 'custom_variants', 'migrations', 'sync_health' ) as $k ) { $absent += (int) ( 0 === (int) v( "SELECT COUNT(*) FROM sqlite_master WHERE name='" . Mobo_Core_Data_Health_Detectors::table( $k ) . "'" ) ); }
t_same( $absent, Mobo_Core_Table_Cache::$probes, 'second request: existing tables come from Mobo_Core_Table_Cache; only absent tables are re-probed (negatives are never cached)' );
t_ok( $probes > 0, '  first request probed and persisted positive results' );
t_same( array(), Mobo_Core_Shutdown_Guard::tracked(), 'data_health lease untracked after a normal slice' );
Mobo_Core_Data_Health::record_fatal( array( 'type' => E_ERROR, 'message' => 'Allowed memory size exhausted' ) );
$st = Mobo_Core_Data_Health::get_state();
t_same( 1, (int) $st['fatalStops'], 'fatal during a slice is recorded for support' );
/* Per-item yield: a job step whose slice deadline already passed still handles exactly one item. */
Mobo_Core_Data_Health::start_apply( array( 'orphan_term_relationship' ), array( 'a' ), 'auto' );
fresh_deadline();
$m = new ReflectionMethod( 'Mobo_Core_Data_Health', 'apply_step' );
$m->setAccessible( true );
$after = $m->invoke( null, Mobo_Core_Data_Health::get_job(), microtime( true ) - 1 );
t_same( 1, (int) $after['applied'], 'per-item deadline: one item handled, the rest yields to the next slice' );
t_same( true, has_rel( 7777, 6 ) xor has_rel( 7777, 3 ), '  exactly one of the two orphan rows changed' );
update_option( Mobo_Core_Data_Health::JOB_OPTION, $after );
run_until_idle();
t_ok( ! has_rel( 7777, 6 ) && ! has_rel( 7777, 3 ), '  the next slice resumes from the cursor and finishes' );

/* ================================================================== */
echo "\n[10] Hosts that refuse CREATE TABLE\n";
fresh_db();
seed_catalog();
$GLOBALS['t_dbdelta_broken'] = true;
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '1' );
$st = Mobo_Core_Data_Health::start_scan( 'test' );
t_same( false, $st['storage'], 'storage tables unavailable' );
run_until_idle();
t_same( 'complete', Mobo_Core_Data_Health::get_state()['status'], '  scan still completes and counts' );
t_same( 1, dcount( 'dup_product_guid' ), '  counts available without tables' );
t_same( 'idle', Mobo_Core_Data_Health::get_job()['status'], '  no automatic job without backup storage' );
$r = Mobo_Core_Data_Health::start_apply( array( 'orphan_term_relationship' ), array( 'a' ), 'admin' );
t_ok( is_wp_error( $r ), '  explicit apply refused (no backup, no change)' );
t_ok( has_rel( 7777, 6 ), '  data untouched' );

/* ================================================================== */
echo "\n[11] Post-migration identity (Custom Storage, no Product Map)\n";
fresh_db( false );
$GLOBALS['wpdb']->pdo->exec( 'CREATE TABLE wp_mobo_products (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER DEFAULT 1, product_guid TEXT, woo_product_id INTEGER)' );
$GLOBALS['wpdb']->pdo->exec( 'CREATE TABLE wp_mobo_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER DEFAULT 1, variant_guid TEXT, product_guid TEXT, woo_variation_id INTEGER, woo_product_id INTEGER)' );
post( 100, 'product', 'publish' ); rel( 100, 4 ); $GLOBALS['wpdb']->insert( 'wp_mobo_products', array( 'product_guid' => 'G1', 'woo_product_id' => 100 ) );
post( 200, 'product', 'publish' ); rel( 200, 4 ); meta( 200, 'product_guid', 'G1' ); // quarantined legacy residue
post( 300, 'product_variation', 'publish', 100 ); $GLOBALS['wpdb']->insert( 'wp_mobo_variants', array( 'variant_guid' => 'V1', 'product_guid' => 'G1', 'woo_variation_id' => 300, 'woo_product_id' => 999 ) );
$GLOBALS['wpdb']->insert( 'wp_mobo_products', array( 'product_guid' => 'G8', 'woo_product_id' => 8888 ) );
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '0' );
Mobo_Core_Data_Health::start_scan( 'test' );
run_until_idle();
$f = findings( 'dup_product_guid' );
t_same( array( 200 ), ids_of( $f, 'b' ), 'residue copy found against the Custom Storage owner' );
t_same( 100, (int) $f[0]['canonical_id'], '  canonical from mobo_products' );
t_same( 2, dcount( 'custom_identity_dangling' ), 'custom rows: missing post + variant parent mismatch reported' );
Mobo_Core_Data_Health::start_apply( array( 'dup_product_guid' ), array( 'b' ), 'admin' );
run_until_idle();
t_same( array(), metas( 200, 'product_guid' ), '  residue identity retired after confirmation' );
t_same( 1, (int) v( "SELECT COUNT(*) FROM wp_mobo_products WHERE woo_product_id=100 AND product_guid='G1'" ), '  Custom Storage owner row untouched' );

/* ================================================================== */
echo "
[12] Admin tab renders (Persian UI, dry-run list, history) without PHP notices
";
function current_user_can( $cap ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'http://127.0.0.1/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function wp_create_nonce( $a ) { return 'n'; }
function wp_unslash( $v ) { return $v; }
function wp_date( $f, $ts ) { return gmdate( $f, $ts ); }
function checked( $c ) { echo $c ? ' checked' : ''; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function get_post( $id ) { $r = $GLOBALS['wpdb']->get_row( 'SELECT * FROM wp_posts WHERE ID=' . (int) $id ); return $r ? $r : null; }
function get_edit_post_link( $id ) { return 'http://127.0.0.1/wp-admin/post.php?post=' . (int) $id; }
function wp_html_excerpt( $s, $n, $more = '' ) { return mb_substr( (string) $s, 0, $n ); }
require $inc . 'class-mobo-core-data-health-admin.php';
fresh_db();
seed_catalog();
update_option( Mobo_Core_Data_Health::AUTO_OPTION, '1' );
Mobo_Core_Data_Health::start_scan( 'test' );
run_until_idle();
$_GET['dh_preview'] = 'dup_product_guid';
$notices = array();
set_error_handler( static function ( $no, $str, $file, $line ) use ( &$notices ) { $notices[] = $str . ' @' . basename( $file ) . ':' . $line; return true; } );
ob_start();
Mobo_Core_Data_Health_Admin::render_tab();
$html = ob_get_clean();
restore_error_handler();
unset( $_GET['dh_preview'] );
t_same( array(), $notices, 'render_tab raises no PHP warnings/notices' );
t_ok( false !== strpos( $html, 'سلامت داده محصولات' ) && false !== strpos( $html, 'نتیجه بررسی' ), 'tab shows the data health card and result table' );
t_ok( false !== strpos( $html, '#200' ) && false !== strpos( $html, 'Copy of phone case' ) && false !== strpos( $html, '#100' ), 'dry-run preview lists the exact copy and its canonical product' );
t_ok( false !== strpos( $html, 'mobo_core_data_health_apply' ) && false !== strpos( $html, 'confirm_reviewed' ), 'apply form requires the explicit review checkbox' );
t_ok( false !== strpos( $html, 'mobo_core_data_health_undo' ), 'history offers undo for the automatic batch' );

@unlink( $GLOBALS['t_upload_dir'] . '/2025/b.jpg' );
@rmdir( $GLOBALS['t_upload_dir'] . '/2025' );
@rmdir( $GLOBALS['t_upload_dir'] );
echo "\n" . $GLOBALS['t_count'] . ' assertions, ' . $GLOBALS['t_fail'] . ' failures (PHP ' . PHP_VERSION . ")\n";
exit( $GLOBALS['t_fail'] > 0 ? 1 : 0 );
