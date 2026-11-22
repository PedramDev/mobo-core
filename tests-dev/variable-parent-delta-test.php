<?php
/**
 * B1 (forced-update staging 2026-11-22): product-delta-v1 must apply the
 * product-level stock of a variable parent that keeps parent-level stock
 * (_manage_stock=yes) exactly like the full snapshot path, and a hide -> unhide
 * delta must restore the parent's catalog visibility, stock and purchasability.
 * A variable parent whose stock is derived from its variations stays unmanaged.
 *
 * Drives the real Mobo_Core_Product_Sync::apply_product_delta_locked() with an
 * in-memory WooCommerce emulation (validate_props, deferred variable sync,
 * outofstock visibility term and lookup-table row written on save).
 *
 * Run: php tests-dev/variable-parent-delta-test.php   (PHP 7.4 and 8.x)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['t_meta']    = array();
$GLOBALS['t_db']      = array();
$GLOBALS['t_posts']   = array();
$GLOBALS['t_terms']   = array();
$GLOBALS['t_lookup']  = array();
$GLOBALS['t_options'] = array();
$GLOBALS['t_dirty_parents'] = array();

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function add_filter() { return true; }
function add_action() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function is_serialized( $data ) { return is_string( $data ) && ( 'N;' === $data || (bool) preg_match( '/^[aOsibd]:/', $data ) ); }
function maybe_serialize( $data ) { return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data; }
function maybe_unserialize( $data ) { return is_serialized( $data ) ? @unserialize( $data ) : $data; }
function wp_cache_delete() { return true; }
function get_post_meta( $id, $key, $single = false ) {
	if ( ! isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ) { return ''; }
	$v = $GLOBALS['t_meta'][ $id ][ $key ];
	return is_scalar( $v ) ? (string) $v : $v; // WordPress reads scalars back as strings.
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['t_meta'][ $id ][ $key ] ); return true; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['t_meta'][ $id ][ $key ] ); }
function get_post_type( $id ) { return isset( $GLOBALS['t_posts'][ $id ] ) ? $GLOBALS['t_posts'][ $id ]['type'] : false; }
function get_post_status( $id ) { return isset( $GLOBALS['t_posts'][ $id ] ) ? 'publish' : false; }
function wp_get_post_parent_id( $id ) { return isset( $GLOBALS['t_db'][ $id ] ) ? $GLOBALS['t_db'][ $id ]['parent'] : 0; }
function wc_delete_product_transients( $id ) { return true; }

/** Minimal WooCommerce product: props persist in t_db, meta in t_meta. */
class WC_Product {
	public $id; public $parent_id = 0; public $type = 'simple'; public $children = array();
	public $p = array( 'manage' => false, 'qty' => null, 'status' => 'instock', 'backorders' => 'no', 'catalog_visibility' => 'visible', 'price' => '' );
	public $meta = array(); public $dirty = array();
	public function __construct( $id, $parent_id = 0 ) { $this->id = $id; $this->parent_id = $parent_id; }
	public function get_id() { return $this->id; }
	public function get_parent_id( $c = 'view' ) { return $this->parent_id; }
	public function is_type( $t ) { return $this->type === $t; }
	public function get_children() { return $this->children; }
	public function get_catalog_visibility( $c = 'view' ) { return $this->p['catalog_visibility']; }
	public function set_catalog_visibility( $v ) { $this->p['catalog_visibility'] = $v; }
	public function get_manage_stock( $c = 'view' ) { return $this->p['manage']; }
	public function set_manage_stock( $v ) { $this->p['manage'] = (bool) $v; }
	public function get_stock_quantity( $c = 'view' ) { return $this->p['qty']; }
	public function set_stock_quantity( $v ) { $this->p['qty'] = '' === $v ? null : (int) $v; }
	public function get_stock_status( $c = 'view' ) { return $this->p['status']; }
	public function set_stock_status( $v ) { $this->p['status'] = $v; }
	public function get_backorders( $c = 'view' ) { return $this->p['backorders']; }
	public function set_backorders( $v ) { $this->p['backorders'] = $v; }
	public function get_price( $c = 'view' ) { return $this->p['price']; }
	public function get_meta( $k, $single = true, $c = 'view' ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; $this->dirty[ $k ] = true; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); $this->dirty[ $k ] = true; }
	/** WC_Product::validate_props() + data store: lookup table and outofstock term follow the saved status. */
	public function save() {
		if ( ! $this->p['manage'] ) {
			$this->p['qty'] = null;
			if ( 'variable' === $this->type ) {
				$this->p['status'] = t_any_child_in_stock( $this->children ) ? 'instock' : 'outofstock';
			}
		} else {
			$this->p['status'] = (int) $this->p['qty'] > 0 ? 'instock' : ( 'no' !== $this->p['backorders'] ? 'onbackorder' : 'outofstock' );
		}
		$GLOBALS['t_db'][ $this->id ] = array( 'type' => $this->type, 'parent' => $this->parent_id, 'children' => $this->children, 'p' => $this->p );
		foreach ( array_keys( $this->dirty ) as $k ) {
			if ( array_key_exists( $k, $this->meta ) ) { $GLOBALS['t_meta'][ $this->id ][ $k ] = $this->meta[ $k ]; } else { unset( $GLOBALS['t_meta'][ $this->id ][ $k ] ); }
		}
		$this->dirty = array();
		$GLOBALS['t_meta'][ $this->id ]['_stock_status']   = $this->p['status'];
		$GLOBALS['t_meta'][ $this->id ]['_manage_stock']   = $this->p['manage'] ? 'yes' : 'no';
		$GLOBALS['t_meta'][ $this->id ]['_stock']          = null === $this->p['qty'] ? '' : (string) $this->p['qty'];
		$GLOBALS['t_terms'][ $this->id ]['outofstock']      = 'outofstock' === $this->p['status'];
		$GLOBALS['t_terms'][ $this->id ]['exclude-from-catalog'] = in_array( $this->p['catalog_visibility'], array( 'hidden', 'search' ), true );
		$GLOBALS['t_lookup'][ $this->id ]                   = $this->p['status'];
		if ( $this->parent_id > 0 ) {
			$GLOBALS['t_dirty_parents'][ $this->parent_id ] = true; // wc_deferred_product_sync()
		}
		return $this->id;
	}
}
class WC_Product_Variation extends WC_Product {}
class WC_Product_Variable {
	public static function sync( $id ) {
		$p = wc_get_product( $id );
		if ( $p ) { $p->save(); }
	}
}
function t_any_child_in_stock( $children ) {
	foreach ( $children as $c ) {
		if ( isset( $GLOBALS['t_db'][ $c ] ) && 'instock' === $GLOBALS['t_db'][ $c ]['p']['status'] ) { return true; }
	}
	return false;
}
/** WooCommerce shutdown: deferred parent syncs of saved variations. */
function t_shutdown() {
	$parents = array_keys( $GLOBALS['t_dirty_parents'] );
	$GLOBALS['t_dirty_parents'] = array();
	foreach ( $parents as $id ) { WC_Product_Variable::sync( $id ); }
	$GLOBALS['t_dirty_parents'] = array();
}
function wc_get_product( $id ) {
	if ( ! isset( $GLOBALS['t_db'][ $id ] ) ) { return false; }
	$row = $GLOBALS['t_db'][ $id ];
	$p = 'variation' === $row['type'] ? new WC_Product_Variation( $id, $row['parent'] ) : new WC_Product( $id, $row['parent'] );
	$p->type = $row['type']; $p->children = $row['children']; $p->p = $row['p'];
	$p->meta = isset( $GLOBALS['t_meta'][ $id ] ) ? $GLOBALS['t_meta'][ $id ] : array();
	foreach ( array( '_stock_status', '_manage_stock', '_stock' ) as $k ) { unset( $p->meta[ $k ] ); }
	return $p;
}
function t_create( $id, $props, $type, $parent = 0, $children = array() ) {
	$GLOBALS['t_posts'][ $id ] = array( 'type' => 'variation' === $type ? 'product_variation' : 'product' );
	$p = 'variation' === $type ? new WC_Product_Variation( $id, $parent ) : new WC_Product( $id, $parent );
	$p->type = $type; $p->children = $children;
	$p->p = array_merge( $p->p, $props );
	$p->meta = isset( $GLOBALS['t_meta'][ $id ] ) ? $GLOBALS['t_meta'][ $id ] : array();
	$p->update_meta_data( '_mobo_stock_update_source', 'api-stock' );
	$p->save();
	$GLOBALS['t_dirty_parents'] = array();
	return $p;
}

final class Mobo_Core_Remote_Identity_Policy {
	public static function is_valid( $v ) { return is_string( $v ) && (bool) preg_match( '/^[A-Za-z0-9\-]{6,}$/', $v ); }
}
final class T_Rules {
	public function should_update_price() { return true; }
	public function should_update_compare_price() { return true; }
	public function should_update_stock() { return true; }
}

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-stock-policy.php';
require $inc . 'class-mobo-core-source-visibility.php';
require $inc . 'class-mobo-core-payload-field-policy.php';
require $inc . 'class-mobo-core-durable-state-policy.php';
require $inc . 'class-mobo-core-product-delta.php';
require $inc . 'class-mobo-core-product-sync.php';

/** Resolver backed by the in-memory catalogue (guid => id). */
class T_Sync extends Mobo_Core_Product_Sync {
	public $products = array(); public $variants = array();
	public function resolve_delta_product( $guid ) {
		$id = isset( $this->products[ $guid ] ) ? $this->products[ $guid ] : 0;
		if ( $id <= 0 ) { return array( 'id' => 0, 'state' => 'missing' ); }
		return array( 'id' => $id, 'state' => 'ok', 'type' => wc_get_product( $id )->is_type( 'variable' ) ? 'variable' : 'simple', 'excluded' => false );
	}
	public function resolve_delta_variant( $guid, $product ) {
		$id = isset( $this->variants[ $guid ] ) ? $this->variants[ $guid ] : 0;
		return $id > 0 ? array( 'id' => $id, 'state' => 'ok', 'context' => 'variation' ) : array( 'id' => 0, 'state' => 'missing' );
	}
}
$ref  = new ReflectionClass( 'T_Sync' );
$sync = $ref->newInstanceWithoutConstructor();
$rules = new ReflectionProperty( 'Mobo_Core_Product_Sync', 'rules' );
$rules->setAccessible( true );
$rules->setValue( $sync, new T_Rules() );
$apply = new ReflectionMethod( 'Mobo_Core_Product_Sync', 'apply_product_delta_locked' );
$apply->setAccessible( true );
function t_apply( $payload ) {
	global $apply, $sync;
	$r = $apply->invoke( $sync, $payload );
	t_shutdown();
	return $r;
}

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}
function t_db( $id ) { return $GLOBALS['t_db'][ $id ]['p']; }

$V = 'Mobo_Core_Source_Visibility';
$V::init();

/* ------------------------------------------------------------------ */
echo "\n[1] restock: parent-level stock 0 -> delta stock>0 -> instock\n";
t_create( 101, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock', 'price' => '1000' ), 'variation', 100 );
t_create( 102, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock', 'price' => '1000' ), 'variation', 100 );
t_create( 103, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock', 'price' => '1200' ), 'variation', 100 );
t_create( 100, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock', 'price' => '1000' ), 'variable', 0, array( 101, 102, 103 ) );
$sync->products['prod-000207'] = 100;
$sync->variants += array( 'var-0002071' => 101, 'var-0002072' => 102, 'var-0002073' => 103 );
check( 'outofstock' === t_db( 100 )['status'] && true === $GLOBALS['t_terms'][100]['outofstock'], 'fixture: parent out of stock with outofstock term' );
$r = t_apply( array(
	'delta' => true, 'productId' => 'prod-000207', 'revision' => 11, 'stock' => 30,
	'variants' => array(
		array( 'variantId' => 'var-0002071', 'stock' => 10 ),
		array( 'variantId' => 'var-0002072', 'stock' => 10 ),
		array( 'variantId' => 'var-0002073', 'stock' => 10 ),
	),
) );
check( ! empty( $r['success'] ) && ! empty( $r['data']['deltaApplied'] ), 'restock delta applied (' . $r['message'] . ')' );
$p = t_db( 100 );
check( true === $p['manage'] && 30 === $p['qty'] && 'instock' === $p['status'], 'B1: parent _manage_stock=yes, _stock=30, instock (was kept at 0/outofstock)' );
check( 'instock' === $GLOBALS['t_meta'][100]['_stock_status'] && '30' === $GLOBALS['t_meta'][100]['_stock'], 'B1: parent _stock_status/_stock meta persisted' );
check( 'instock' === $GLOBALS['t_lookup'][100], 'B1: wc_product_meta_lookup.stock_status = instock' );
check( false === $GLOBALS['t_terms'][100]['outofstock'], 'B1: outofstock product_visibility term removed' );
check( 'instock' === t_db( 101 )['status'] && 10 === t_db( 103 )['qty'], 'variations restocked' );
check( '1000' === $p['price'] && '1200' === t_db( 103 )['price'], 'variant-derived prices unchanged' );
check( 'api-stock' === (string) $GLOBALS['t_meta'][100]['_mobo_stock_update_source'] && '30' === (string) $GLOBALS['t_meta'][100]['_mobo_last_api_stock_quantity'], 'parent stock bookkeeping identical to the full path (apply_api_stock)' );
check( '11' === get_post_meta( 100, '_mobo_product_applied_revision', true ), 'product applied revision watermark persisted' );
check( true === $V::filter_purchasable( true, wc_get_product( 100 ) ), 'parent purchasable on the storefront' );

/* Sell-out delta -> back to out of stock, symmetric. */
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000207', 'revision' => 12, 'stock' => 0,
	'variants' => array( array( 'variantId' => 'var-0002071', 'stock' => 0 ), array( 'variantId' => 'var-0002072', 'stock' => 0 ), array( 'variantId' => 'var-0002073', 'stock' => 0 ) ) ) );
check( ! empty( $r['data']['deltaApplied'] ) && 0 === t_db( 100 )['qty'] && 'outofstock' === t_db( 100 )['status'] && true === $GLOBALS['t_terms'][100]['outofstock'], 'sell-out delta: parent back to 0 / outofstock with term' );

/* Product-only delta (no variants) still updates the parent. */
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000207', 'revision' => 13, 'stock' => 4 ) );
check( ! empty( $r['data']['deltaApplied'] ) && 4 === t_db( 100 )['qty'] && 'instock' === t_db( 100 )['status'], 'product-only stock delta updates the parent' );

/* Price on a variable parent is never staged by the delta. */
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000207', 'revision' => 14, 'price' => 5555, 'stock' => 4 ) );
check( ! empty( $r['data']['deltaApplied'] ), 'delta with a product-level price applied' );
check( '1000' === t_db( 100 )['price'], 'parent price still variant-derived (1000)' );

/* Invalid parent stock rejects the whole delta before any write. */
$before = $GLOBALS['t_db'];
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000207', 'revision' => 15, 'stock' => '1.5',
	'variants' => array( array( 'variantId' => 'var-0002071', 'stock' => 9 ) ) ) );
check( empty( $r['success'] ) && 'delta_validation_failed' === ( $r['data']['error'] ?? '' ) && $before === $GLOBALS['t_db'], 'invalid parent stock => delta rejected, nothing written' );

/* ------------------------------------------------------------------ */
echo "\n[2] hide (full path overlay) -> unhide delta restores the parent\n";
t_create( 201, array( 'manage' => true, 'qty' => 39 ), 'variation', 200 );
t_create( 202, array( 'manage' => true, 'qty' => 18 ), 'variation', 200 );
t_create( 203, array( 'manage' => true, 'qty' => 6 ), 'variation', 200 );
t_create( 200, array( 'manage' => true, 'qty' => 63, 'catalog_visibility' => 'catalog' ), 'variable', 0, array( 201, 202, 203 ) );
$sync->products['prod-000129'] = 200;
$sync->variants += array( 'var-0001291' => 201, 'var-0001292' => 202, 'var-0001293' => 203 );
/* Hide arrives as a delta too (visibility hidden, purchasable false, stock 0). */
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000129', 'revision' => 20, 'visibility' => 'hidden', 'purchasable' => false, 'stock' => 0,
	'variants' => array(
		array( 'variantId' => 'var-0001291', 'stock' => 0, 'purchasable' => false ),
		array( 'variantId' => 'var-0001292', 'stock' => 0, 'purchasable' => false ),
		array( 'variantId' => 'var-0001293', 'stock' => 0, 'purchasable' => false ),
	) ) );
$p = t_db( 200 );
check( ! empty( $r['data']['deltaApplied'] ) && 'hidden' === $p['catalog_visibility'] && 'outofstock' === $p['status'] && '1' === get_post_meta( 200, $V::META_HIDDEN, true ), 'hide delta: parent hidden from catalog and out of stock' );
check( false === $V::filter_purchasable( true, wc_get_product( 200 ) ) && false === $V::filter_purchasable( true, wc_get_product( 201 ) ), 'hide delta: parent and variations not purchasable' );
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000129', 'revision' => 21, 'visibility' => 'visible', 'purchasable' => true, 'stock' => 63,
	'variants' => array(
		array( 'variantId' => 'var-0001291', 'stock' => 39, 'purchasable' => true ),
		array( 'variantId' => 'var-0001292', 'stock' => 18, 'purchasable' => true ),
		array( 'variantId' => 'var-0001293', 'stock' => 6, 'purchasable' => true ),
	) ) );
$p = t_db( 200 );
check( ! empty( $r['data']['deltaApplied'] ), 'unhide delta applied (' . $r['message'] . ')' );
check( 'catalog' === $p['catalog_visibility'] && '' === get_post_meta( 200, $V::META_HIDDEN, true ), 'unhide: previous catalog visibility restored, hidden flag cleared' );
check( true === $p['manage'] && 63 === $p['qty'] && 'instock' === $p['status'], 'B1 unhide: parent _manage_stock=yes, _stock=63, instock' );
check( 'instock' === $GLOBALS['t_lookup'][200] && false === $GLOBALS['t_terms'][200]['outofstock'], 'B1 unhide: lookup table instock, outofstock term removed' );
check( null === $V::stored_natural_stock( wc_get_product( 200 ) ), 'unhide: blocked-stock overlay dropped' );
check( 39 === t_db( 201 )['qty'] && 'instock' === t_db( 203 )['status'] && '' === get_post_meta( 201, $V::META_INACTIVE, true ), 'unhide: variations restored and active' );
check( true === $V::filter_purchasable( true, wc_get_product( 200 ) ) && true === $V::filter_purchasable( true, wc_get_product( 202 ) ), 'unhide: parent and variations purchasable again' );

/* Unhide via the full-path overlay where the parent was hidden with qty 0 remembered,
 * and the recovery delta carries only visibility + stock. */
t_create( 251, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'variation', 250 );
t_create( 250, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'variable', 0, array( 251 ) );
$sync->products['prod-000250'] = 250;
$sync->variants['var-0002501'] = 251;
$V::apply_to_product( 250, 'hidden', 'draft' );
check( false === t_db( 250 )['manage'] && array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ) === $V::stored_natural_stock( wc_get_product( 250 ) ), 'fixture: hidden parent persists the unmanaged overlay, natural parent stock 0 remembered' );
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000250', 'revision' => 3, 'visibility' => 'visible', 'stock' => 7,
	'variants' => array( array( 'variantId' => 'var-0002501', 'stock' => 7 ) ) ) );
$p = t_db( 250 );
check( ! empty( $r['data']['deltaApplied'] ) && true === $p['manage'] && 7 === $p['qty'] && 'instock' === $p['status'], 'B1 unhide over the overlay: remembered parent stock replaced by the delta stock (7, instock)' );

/* Stock delta while still hidden: remembered, not exposed. */
t_create( 261, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'variation', 260 );
t_create( 260, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'variable', 0, array( 261 ) );
$sync->products['prod-000260'] = 260;
$V::apply_to_product( 260, 'hidden', 'draft' );
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000260', 'revision' => 3, 'stock' => 5 ) );
$p = t_db( 260 );
check( ! empty( $r['data']['deltaApplied'] ) && false === $p['manage'] && 'outofstock' === $p['status'] && 5 === $V::stored_natural_stock( wc_get_product( 260 ) )['qty'], 'stock delta on a still-hidden parent: stays blocked, new stock remembered for unhide' );

/* ------------------------------------------------------------------ */
echo "\n[3] variable product WITHOUT parent stock management is unaffected\n";
t_create( 301, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'variation', 300 );
t_create( 302, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'variation', 300 );
t_create( 300, array( 'manage' => false, 'qty' => null, 'status' => 'outofstock' ), 'variable', 0, array( 301, 302 ) );
$sync->products['prod-000300'] = 300;
$sync->variants += array( 'var-0003001' => 301, 'var-0003002' => 302 );
$meta_before = $GLOBALS['t_meta'][300];
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000300', 'revision' => 4, 'stock' => 12,
	'variants' => array( array( 'variantId' => 'var-0003001', 'stock' => 12 ), array( 'variantId' => 'var-0003002', 'stock' => 0 ) ) ) );
$p = t_db( 300 );
check( ! empty( $r['data']['deltaApplied'] ), 'delta applied' );
check( false === $p['manage'] && null === $p['qty'], 'unmanaged parent stays unmanaged (no parent quantity written)' );
check( 'instock' === $p['status'], 'unmanaged parent status derived from the in-stock variation' );
check( ! isset( $GLOBALS['t_meta'][300]['_mobo_last_api_stock_quantity'] ) && $meta_before['_mobo_stock_update_source'] === $GLOBALS['t_meta'][300]['_mobo_stock_update_source'], 'no parent stock bookkeeping written' );
/* Product-only stock delta on an unmanaged parent: nothing staged on the parent. */
$saved = $GLOBALS['t_db'][300];
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000300', 'revision' => 5, 'stock' => 99 ) );
check( ! empty( $r['data']['deltaApplied'] ) && $saved === $GLOBALS['t_db'][300], 'product-only stock delta leaves an unmanaged parent untouched' );

/* ------------------------------------------------------------------ */
echo "\n[4] simple product path unchanged\n";
t_create( 400, array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock' ), 'simple' );
$sync->products['prod-000400'] = 400;
$r = t_apply( array( 'delta' => true, 'productId' => 'prod-000400', 'revision' => 2, 'stock' => 3 ) );
check( ! empty( $r['data']['deltaApplied'] ) && 3 === t_db( 400 )['qty'] && 'instock' === t_db( 400 )['status'], 'simple product stock delta still applied' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
