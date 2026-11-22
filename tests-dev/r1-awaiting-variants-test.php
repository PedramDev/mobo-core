<?php
/**
 * Standalone regression test (R1, staging 2026-11-22): a product created from a
 * ProductUpdated payload whose Variants arrive in separate UpdateVariant pages must
 * never be purchasable as the temporary Simple parent (lowest Variant price, summed
 * stock). It is created blocked ("awaiting Variants") and released only when its
 * Variant topology is applied; a stale creation-time stock is never restored onto a
 * product without a purchasable topology, and existing products are never flagged.
 *
 * Run: php tests-dev/r1-awaiting-variants-test.php
 * No WordPress, no network, no database. PHP 7.4 compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_meta']     = array();
$GLOBALS['t_products'] = array();
$GLOBALS['t_saves']    = 0;
$GLOBALS['t_var_sync'] = array();

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return true; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ? $GLOBALS['t_meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['t_meta'][ $id ][ $key ] ); return true; }
function wc_delete_product_transients( $id ) { return true; }
function wc_get_product( $id ) { return isset( $GLOBALS['t_products'][ $id ] ) ? $GLOBALS['t_products'][ $id ]->reload() : false; }

/** Minimal WC_Product: stock props + in-memory meta that is persisted on save(). */
class WC_Product {
	public $id; public $parent_id = 0; public $type = 'simple'; public $children = array();
	public $p = array( 'manage' => false, 'qty' => null, 'status' => 'instock', 'backorders' => 'no' );
	public $meta = array();
	public function __construct( $id = 0 ) { $this->id = $id; }
	public function reload() {
		$this->meta = isset( $GLOBALS['t_meta'][ $this->id ] ) ? $GLOBALS['t_meta'][ $this->id ] : array();
		return $this;
	}
	public function get_id() { return $this->id; }
	public function get_parent_id( $c = 'view' ) { return $this->parent_id; }
	public function is_type( $t ) { return $this->type === $t; }
	public function get_children() { return $this->children; }
	public function get_manage_stock( $c = 'view' ) { return $this->p['manage']; }
	public function set_manage_stock( $v ) { $this->p['manage'] = (bool) $v; }
	public function get_stock_quantity( $c = 'view' ) { return $this->p['qty']; }
	public function set_stock_quantity( $v ) { $this->p['qty'] = '' === $v ? null : (int) $v; }
	public function get_stock_status( $c = 'view' ) { return $this->p['status']; }
	public function set_stock_status( $v ) { $this->p['status'] = $v; }
	public function get_backorders( $c = 'view' ) { return $this->p['backorders']; }
	public function set_backorders( $v ) { $this->p['backorders'] = $v; }
	public function get_meta( $k, $single = true, $c = 'view' ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() {
		$GLOBALS['t_saves']++;
		if ( $this->id <= 0 ) { $this->id = 1000 + count( $GLOBALS['t_products'] ); }
		$GLOBALS['t_meta'][ $this->id ]     = $this->meta;
		$GLOBALS['t_products'][ $this->id ] = $this;
		return $this->id;
	}
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {
	public $type = 'variable';
	public static function sync( $id ) { $GLOBALS['t_var_sync'][] = $id; }
}

require __DIR__ . '/../wp-content/plugins/mobo-core/includes/class-mobo-core-stock-policy.php';
require __DIR__ . '/../wp-content/plugins/mobo-core/includes/class-mobo-core-source-visibility.php';

$failures = 0;
function check( $cond, $label ) {
	global $failures;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$V = 'Mobo_Core_Source_Visibility';
$K = $V::META_AWAITING_VARIANTS;

/** Emulates Mobo_Core_Product_Sync::apply_api_stock(): natural Source stock through the Stock Policy. */
function apply_api_stock_like( $product, $qty ) {
	$natural = Mobo_Core_Stock_Policy::normalize_state( array( 'manage' => true, 'qty' => $qty, 'status' => $qty > 0 ? 'instock' : 'outofstock' ) );
	$blocked = Mobo_Core_Source_Visibility::is_blocked( $product );
	Mobo_Core_Source_Visibility::set_stock_props( $product, Mobo_Core_Stock_Policy::persisted_state( $natural, $blocked ) );
	if ( $blocked ) {
		Mobo_Core_Source_Visibility::remember_natural_stock( $product, $natural );
	} else {
		Mobo_Core_Source_Visibility::forget_natural_stock( $product );
	}
}

/** Creates a product the way upsert_parent_product_core() does for a new ProductUpdated. */
function create_like( $payload_can_have_variants, $qty ) {
	$product = new WC_Product_Simple();
	if ( Mobo_Core_Source_Visibility::should_await_variants( true, $product instanceof WC_Product_Simple && ! $product instanceof WC_Product_Variable, $payload_can_have_variants ) ) {
		Mobo_Core_Source_Visibility::mark_awaiting_variants( $product );
	}
	apply_api_stock_like( $product, $qty );
	$product->save();
	return $product;
}

// --- 1. decision: only NEW Simple objects whose payload proves variation attributes
check( $V::should_await_variants( true, true, true ), 'new Simple object + variation attributes => awaiting Variants' );
check( ! $V::should_await_variants( false, true, true ), 'existing product is never flagged (no hide/zero on partial data)' );
check( ! $V::should_await_variants( true, false, true ), 'new Variable object is not flagged' );
check( ! $V::should_await_variants( true, true, false ), 'payload without variation attributes (proven Simple) is not flagged' );

// --- 2. the R1 window: a new variable-topology product is not purchasable as Simple
$p = create_like( true, 96 );
$id = $p->get_id();
check( '1' === get_post_meta( $id, $K ), 'flag persisted with the new product' );
check( $V::is_blocked( wc_get_product( $id ) ), 'new product is blocked' );
check( false === $V::filter_purchasable( true, wc_get_product( $id ) ), 'not purchasable as the temporary Simple parent' );
check( 'outofstock' === $V::filter_stock_status( 'instock', wc_get_product( $id ) ), 'shown out of stock' );
check( 'outofstock' === $p->p['status'] && false === $p->p['manage'], 'persisted out of stock (DB/lookup/feeds agree)' );
check( '96' === (string) get_post_meta( $id, $V::META_NATURAL_QTY ), 'summed Source stock remembered as natural, not lost' );

// a newer ProductUpdated while still awaiting keeps it blocked and refreshes the natural stock
$p2 = wc_get_product( $id );
apply_api_stock_like( $p2, 80 );
$p2->save();
check( 'outofstock' === $p2->p['status'] && '80' === (string) get_post_meta( $id, $V::META_NATURAL_QTY ), 'later stock while awaiting: still blocked, natural refreshed' );

// --- 3. Variable topology applied (last authoritative page, children exist) => released, Source stock restored
$GLOBALS['t_products'][ $id ] = ( function ( $old ) {
	$v = new WC_Product_Variable( $old->id ); $v->p = $old->p; $v->children = array( 2001, 2002 ); return $v;
} )( $p2 );
$GLOBALS['t_products'][ $id ]->meta = $GLOBALS['t_meta'][ $id ];
check( true === $V::release_awaiting_variants( $id ), 'release after the Variable topology is applied' );
$after = wc_get_product( $id );
check( '' === get_post_meta( $id, $K ) && ! $V::is_blocked( $after ), 'flag removed, no longer blocked' );
check( true === $after->p['manage'] && 80 === $after->p['qty'] && 'instock' === $after->p['status'], 'latest Source stock restored on the Variable parent' );
check( '' === get_post_meta( $id, $V::META_STOCK_BLOCKED ), 'overlay bookkeeping removed' );
check( in_array( $id, $GLOBALS['t_var_sync'], true ), 'WC_Product_Variable::sync() run after restore' );
check( false === $V::release_awaiting_variants( $id ), 'second release is a no-op' );

// --- 4. proven Simple (mapped single Variant, stock written through the Stock Policy) => released with fresh stock
$s = create_like( true, 30 );
$sid = $s->get_id();
$sm = wc_get_product( $sid );
apply_api_stock_like( $sm, 7 ); // apply_simple_variant_to_product() writes the Variant stock while still blocked
$sm->save();
update_post_meta( $sid, '_mobo_simple_variant_mapped', '1' );
$V::release_awaiting_variants( $sid );
$sa = wc_get_product( $sid );
check( 7 === $sa->p['qty'] && 'instock' === $sa->p['status'] && ! $V::is_blocked( $sa ), 'mapped Simple Variant: released with the fresh Variant stock (7), not the creation value (30)' );

// --- 5. authoritative empty topology (no purchasable Variant) => stays out of stock, stale stock NOT restored
$e = create_like( true, 55 );
$eid = $e->get_id();
$ee = wc_get_product( $eid );
$ee->set_manage_stock( true ); $ee->set_stock_quantity( 0 ); $ee->set_stock_status( 'outofstock' ); // apply_authoritative_simple_without_purchase_variant()
$ee->save();
$V::release_awaiting_variants( $eid );
$ea = wc_get_product( $eid );
check( 'outofstock' === $ea->p['status'] && 0 === $ea->p['qty'], 'authoritative empty topology: stays out of stock (stale 55 never restored)' );
check( '' === get_post_meta( $eid, $V::META_STOCK_BLOCKED ) && '' === get_post_meta( $eid, $K ), 'flag and overlay bookkeeping dropped' );

// --- 6. Variable conversion without children yet (e.g. empty last page) => not restored
$z = create_like( true, 12 );
$zid = $z->get_id();
$GLOBALS['t_products'][ $zid ] = ( function ( $old ) { $v = new WC_Product_Variable( $old->id ); $v->p = $old->p; return $v; } )( wc_get_product( $zid ) );
$GLOBALS['t_products'][ $zid ]->meta = $GLOBALS['t_meta'][ $zid ];
$V::release_awaiting_variants( $zid );
check( 'outofstock' === wc_get_product( $zid )->p['status'], 'Variable without children: stays out of stock' );

// --- 7. another block (quarantine) still applies => no restore
$q = create_like( true, 9 );
$qid = $q->get_id();
update_post_meta( $qid, '_mobo_simple_variant_mapped', '1' );
update_post_meta( $qid, $V::META_QUARANTINE_BLOCKED, '1' );
$V::release_awaiting_variants( $qid );
check( 'outofstock' === wc_get_product( $qid )->p['status'], 'other Source block (quarantine) keeps it out of stock' );

// --- 8. existing product (never flagged): release is a no-op and touches nothing
$x = new WC_Product_Simple( 3000 );
$x->set_manage_stock( true ); $x->set_stock_quantity( 4 ); $x->set_stock_status( 'instock' );
$x->save();
$saves = $GLOBALS['t_saves'];
check( false === $V::release_awaiting_variants( 3000 ) && $saves === $GLOBALS['t_saves'], 'unflagged existing product: no-op, no save' );
check( 4 === wc_get_product( 3000 )->p['qty'] && 'instock' === wc_get_product( 3000 )->p['status'], 'existing product stock untouched' );

// --- 9. a new product proven Simple at creation (no variation attributes) is sellable immediately
$n = create_like( false, 5 );
check( ! $V::is_blocked( wc_get_product( $n->get_id() ) ) && 'instock' === $n->p['status'], 'new product without variation attributes: normal (not blocked)' );

echo PHP_EOL . ( $failures ? "FAILED: {$failures}" : 'ALL PASS' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
