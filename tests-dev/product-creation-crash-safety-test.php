<?php
/**
 * Standalone tests for duplicate-product prevention:
 *  - H6: provisional creation marker written by wp_insert_post() (meta_input),
 *        adoption of an interrupted creation, bounded orphan scan decisions;
 *  - M11: quarantined legacy product found by its hidden legacy product_guid row;
 *  - M12: a Custom identity row pointing at a hard-deleted post is repaired
 *         (CAS re-point) instead of refusing every bind forever.
 *
 * Run: php tests-dev/product-creation-crash-safety-test.php
 * No WordPress, no network, no database (scripted fake $wpdb).
 */

require __DIR__ . '/stubs.php';

$GLOBALS['mobo_test_hooks'] = array();
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['mobo_test_hooks'][ $tag ][] = $cb; return true; }
function remove_filter( $tag, $cb, $priority = 10 ) {
	foreach ( $GLOBALS['mobo_test_hooks'][ $tag ] ?? array() as $i => $existing ) {
		if ( $existing === $cb ) { unset( $GLOBALS['mobo_test_hooks'][ $tag ][ $i ] ); }
	}
	return true;
}
function mobo_test_apply( $tag, $value ) {
	foreach ( $GLOBALS['mobo_test_hooks'][ $tag ] ?? array() as $cb ) { $value = call_user_func( $cb, $value ); }
	return $value;
}
$GLOBALS['mobo_test_deleted_meta'] = array();
$GLOBALS['mobo_test_updated_meta'] = array();
function delete_post_meta( $id, $key ) { $GLOBALS['mobo_test_deleted_meta'][] = array( $id, $key ); return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['mobo_test_updated_meta'][] = array( $id, $key, $value ); return true; }

class Mobo_Test_WPDB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $last_error = '';
	public $queries = array();
	public $var_results = array();   // substring => value
	public $results = array();
	public $rows = array();          // substring => row
	public $query_result = 1;
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$value = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
		}, $query );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		foreach ( $this->var_results as $needle => $value ) {
			if ( false !== strpos( $sql, $needle ) ) { return $value; }
		}
		return null;
	}
	public function get_col( $sql ) { $this->queries[] = $sql; return array(); }
	public function get_results( $sql, $output = null ) { $this->queries[] = $sql; return $this->results; }
	public function get_row( $sql, $output = null ) {
		$this->queries[] = $sql;
		foreach ( $this->rows as $needle => $row ) {
			if ( false !== strpos( $sql, $needle ) ) { return $row; }
		}
		return null;
	}
	public function query( $sql ) { $this->queries[] = $sql; return $this->query_result; }
}
$GLOBALS['wpdb'] = new Mobo_Test_WPDB();

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-product-creation-intent.php';
$I = 'Mobo_Core_Product_Creation_Intent';

echo "H6: provisional marker\n";
mobo_assert_same( 'v1|3|G-1', $I::marker_value( 3, 'G-1' ), 'marker carries Connection + GUID' );
mobo_assert_same( '', $I::marker_value( 0, 'G-1' ), 'no Connection => no marker' );
mobo_assert_same( array( 'connectionId' => 3, 'productGuid' => 'G|x' ), $I::parse_marker( 'v1|3|G|x' ), 'GUID may contain the separator' );
mobo_assert_same( null, $I::parse_marker( 'dup|v1|3|G' ), 'parked duplicate is not a live marker' );
mobo_assert( 0 !== strpos( $I::META_KEY, '_mobo_' ) && 0 !== strpos( $I::META_KEY, 'mobo_' ), 'marker key is outside the managed _mobo_/mobo_ prefixes (stays in wp_postmeta)' );
$args = $I::add_marker_to_insert_args( array( 'post_type' => 'product', 'meta_input' => array( 'x' => 1 ) ), 'v1|3|G-1' );
mobo_assert_same( array( 'x' => 1, $I::META_KEY => 'v1|3|G-1' ), $args['meta_input'], 'marker added to wp_insert_post meta_input (existing meta kept)' );

$seen = null;
$id = $I::save_with_marker( 3, 'G-1', function () use ( &$seen ) {
	/* What WC_Product_Data_Store_CPT::create() does before wp_insert_post(). */
	$seen = mobo_test_apply( 'woocommerce_new_product_data', array( 'post_type' => 'product', 'post_status' => 'publish' ) );
	return 901;
} );
mobo_assert_same( 901, $id, 'save result passed through' );
mobo_assert_same( 'v1|3|G-1', $seen['meta_input'][ $I::META_KEY ] ?? null, 'the INSERT itself carries the marker (written before any save hook)' );
mobo_assert_same( array(), array_values( $GLOBALS['mobo_test_hooks']['woocommerce_new_product_data'] ?? array() ), 'filter removed after the save' );
try {
	$I::save_with_marker( 3, 'G-2', function () { throw new RuntimeException( 'killed' ); } );
} catch ( RuntimeException $e ) {
	unset( $e );
}
mobo_assert_same( array(), array_values( $GLOBALS['mobo_test_hooks']['woocommerce_new_product_data'] ?? array() ), 'filter removed even when the save throws' );
$other = mobo_test_apply( 'woocommerce_new_product_data', array( 'post_type' => 'product' ) );
mobo_assert( ! isset( $other['meta_input'] ), 'unrelated (merchant) product inserts are never marked' );

echo "H6: adoption of an interrupted creation\n";
$wpdb = $GLOBALS['wpdb'];
$wpdb->var_results = array( "pm.meta_value = 'v1|3|G-1'" => '901' );
mobo_assert_same( 901, $I::find_adoptable( 3, 'G-1' ), 'next sync adopts the marked product instead of creating a duplicate' );
$sql = end( $wpdb->queries );
mobo_assert( false !== strpos( $sql, "'trash'" ) && false !== strpos( $sql, "post_type = 'product'" ) && false !== strpos( $sql, "'" . $I::META_KEY . "'" ), 'adoption excludes trashed products (merchant decision respected)' );
$wpdb->var_results = array();
mobo_assert_same( 0, $I::find_adoptable( 3, 'G-unknown' ), 'nothing marked => create normally' );
mobo_assert_same( 0, $I::find_adoptable( 0, 'G-1' ), 'no Connection => no adoption' );

echo "H6: bounded orphan scan\n";
$wpdb->results = array(
	array( 'post_id' => '901', 'meta_value' => 'v1|3|G-1' ),
	array( 'post_id' => '902', 'meta_value' => 'v1|4|G-9' ),
	array( 'post_id' => '903', 'meta_value' => 'garbage' ),
);
$marked = $I::list_marked( 3, 500 );
mobo_assert_same( array( array( 'postId' => 901, 'productGuid' => 'G-1' ) ), $marked, 'only this Connection\'s well-formed markers are returned' );
$sql = end( $wpdb->queries );
mobo_assert( false !== strpos( $sql, 'LIMIT 100' ) && false !== strpos( $sql, "LIKE 'v1|3|%'" ), 'scan is bounded (<= 100) and Connection-scoped' );
mobo_assert_same( 'adopt', $I::orphan_action( 901, 0 ), 'unbound GUID => bind the orphan' );
mobo_assert_same( 'clear', $I::orphan_action( 901, 901 ), 'already bound to the orphan => just clear the marker' );
mobo_assert_same( 'duplicate', $I::orphan_action( 901, 777 ), 'GUID bound elsewhere => park, never delete' );
$I::park_duplicate( 901, 'v1|3|G-1' );
mobo_assert_same( array( 901, $I::META_KEY, 'dup|v1|3|G-1' ), end( $GLOBALS['mobo_test_updated_meta'] ), 'parked duplicate keeps the product and leaves the scan set' );
$I::clear( 901 );
mobo_assert_same( array( 901, $I::META_KEY ), end( $GLOBALS['mobo_test_deleted_meta'] ), 'marker cleared once the identity is durable' );

echo "M11: quarantined legacy product without map/Custom rows\n";
final class Mobo_Core_Migration_Quarantine {
	public static $items = array();
	public static $default = 1;
	public static function all() { return self::$items; }
	public static function count() { return count( self::$items ); }
	public static function default_connection_id() { return self::$default; }
}
Mobo_Core_Migration_Quarantine::$items = array( 501 => array( 'r' => 'mirror_failed', 't' => 'product' ), 777 => array( 'r' => 'x', 't' => 'product_variation' ) );
$wpdb->var_results = array( "meta_key = 'product_guid' AND pm.meta_value = 'G-Q'" => '501' );
mobo_assert_same( 501, $I::find_quarantined_legacy_product( 'G-Q', 1 ), 'quarantined product found through its hidden legacy product_guid row (adopted, no duplicate)' );
$sql = end( $wpdb->queries );
mobo_assert( false !== strpos( $sql, 'IN (501)' ) && false === strpos( $sql, '777' ), 'only marked PRODUCTS are considered' );
mobo_assert_same( 0, $I::find_quarantined_legacy_product( 'G-Q', 2 ), 'secondary Connections never adopt legacy (Default-owned) identity rows' );
Mobo_Core_Migration_Quarantine::$items = array();
mobo_assert_same( 0, $I::find_quarantined_legacy_product( 'G-Q', 1 ), 'nothing quarantined => normal lookup result' );

echo "M12: identity row pointing at a hard-deleted post\n";
final class Mobo_Core_Connection_Context { public static function get_effective_connection_id() { return 1; } }
final class Mobo_Core_Remote_Identity_Policy { public static function is_valid( $g ) { return '' !== (string) $g; } }
final class Mobo_Core_Storage_Schema {
	public static function is_ready() { return true; }
	public static function table_name( $t ) { return 'wp_mobo_' . $t; }
}
require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-product-state-repository.php';
$repo = new Mobo_Core_Product_State_Repository();

$wpdb->queries = array();
$wpdb->var_results = array( 'FROM wp_posts WHERE ID=55' => null );
$wpdb->rows = array(
	'WHERE woo_product_id=910' => null,
	"SELECT woo_product_id FROM wp_mobo_products WHERE connection_id=1 AND product_guid='G-D'" => array( 'woo_product_id' => 55 ),
	"SELECT woo_product_id, portal_product_id FROM wp_mobo_products" => array( 'woo_product_id' => 910, 'portal_product_id' => 0 ),
);
mobo_assert_same( true, $repo->terminal_bind_product( 910, 'G-D' ), 'bind succeeds after repairing the leftover row' );
$repoint = array_values( array_filter( $wpdb->queries, function ( $q ) { return 0 === strpos( $q, 'UPDATE wp_mobo_products SET woo_product_id=910' ); } ) );
mobo_assert( 1 === count( $repoint ) && false !== strpos( $repoint[0], "product_guid='G-D' AND woo_product_id=55" ), 'repair is a CAS re-point of exactly the stale row' );

$wpdb->queries = array();
$wpdb->var_results = array( 'FROM wp_posts WHERE ID=55' => '55' );
mobo_assert_same( false, $repo->terminal_bind_product( 910, 'G-D' ), 'a live owner (even trashed) is never taken over' );
mobo_assert_same( array(), array_values( array_filter( $wpdb->queries, function ( $q ) { return 0 === strpos( $q, 'UPDATE' ); } ) ), 'no write when the owner post exists' );

$wpdb->var_results = array( 'FROM wp_posts WHERE ID=55' => null );
$wpdb->query_result = 0;
mobo_assert_same( false, $repo->terminal_bind_product( 910, 'G-D' ), 'lost CAS race => refuse (no blind overwrite)' );
$wpdb->query_result = 1;

echo "Wiring (static)\n";
$sync_src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-product-sync.php' );
mobo_assert( false !== strpos( $sync_src, 'Mobo_Core_Product_Creation_Intent::find_adoptable(' ) && false !== strpos( $sync_src, 'Mobo_Core_Product_Creation_Intent::save_with_marker(' ), 'H6: Product Sync adopts before creating and marks the first save' );
mobo_assert( false !== strpos( $sync_src, 'Mobo_Core_Product_Creation_Intent::clear( $product_id )' ) && false !== strpos( $sync_src, 'maybe_adopt_creation_orphans()' ), 'H6: marker cleared after bind; orphan scan wired' );
mobo_assert( false !== strpos( $sync_src, 'find_quarantined_legacy_product(' ), 'M11: lookup consults quarantined legacy identity before creating' );

mobo_finish();
