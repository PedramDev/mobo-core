<?php
/**
 * Standalone test (M8): a quarantined non-canonical duplicate copy must not be
 * purchasable with a frozen price/stock; trash/draft releases the quarantine
 * cleanly; the admin duplicates card lists post-migration quarantined copies with
 * the proven canonical owner.
 *
 * Run: php tests-dev/quarantine-sale-guard-test.php
 * No WordPress, no network, no database. PHP 7.4 compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MOBO_CORE_VERSION', '10.34.77' );

$GLOBALS['t_meta']    = array();
$GLOBALS['t_db']      = array();
$GLOBALS['t_options'] = array( 'mobo_core_db_version' => '10.34.77' );
$GLOBALS['t_status']  = array();
$GLOBALS['t_type']    = array();
$GLOBALS['t_actions'] = array();
$GLOBALS['t_var_sync'] = array();

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['t_actions'][ $hook ][] = $cb; return true; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { return add_action( $hook, $cb, $prio, $args ); }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ? $GLOBALS['t_meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['t_meta'][ $id ][ $key ] ); return true; }
function get_post_status( $id ) { return isset( $GLOBALS['t_status'][ $id ] ) ? $GLOBALS['t_status'][ $id ] : false; }
function get_post_type( $id ) { return isset( $GLOBALS['t_type'][ $id ] ) ? $GLOBALS['t_type'][ $id ] : false; }
function wc_delete_product_transients( $id ) { return true; }

/** Minimal WC_Product: props persist in t_db, meta in t_meta. */
class WC_Product {
	public $id; public $parent_id = 0; public $type = 'simple'; public $children = array();
	public $p = array( 'manage' => false, 'qty' => null, 'status' => 'instock', 'backorders' => 'no', 'catalog_visibility' => 'visible' );
	public $dirty = array();
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
	public function get_meta( $k, $single = true, $c = 'view' ) { return get_post_meta( $this->id, $k, true ); }
	public function update_meta_data( $k, $v ) { $this->dirty[ $k ] = array( 'set', $v ); }
	public function delete_meta_data( $k ) { $this->dirty[ $k ] = array( 'del', null ); }
	public function save() {
		if ( ! $this->p['manage'] ) { $this->p['qty'] = null; }
		$GLOBALS['t_db'][ $this->id ] = array( 'type' => $this->type, 'parent' => $this->parent_id, 'children' => $this->children, 'p' => $this->p );
		foreach ( $this->dirty as $k => $op ) {
			if ( 'set' === $op[0] ) { $GLOBALS['t_meta'][ $this->id ][ $k ] = $op[1]; } else { unset( $GLOBALS['t_meta'][ $this->id ][ $k ] ); }
		}
		$this->dirty = array();
		return $this->id;
	}
}
class WC_Product_Variable { public static function sync( $id ) { $GLOBALS['t_var_sync'][] = $id; } }
function t_create( $id, $status, $props = array(), $type = 'simple', $parent = 0, $children = array() ) {
	$p = new WC_Product( $id, $parent ); $p->type = $type; $p->children = $children;
	$p->p = array_merge( $p->p, $props );
	$p->save();
	$GLOBALS['t_status'][ $id ] = $status;
	$GLOBALS['t_type'][ $id ] = 'variation' === $type ? 'product_variation' : 'product';
	return $p;
}
function wc_get_product( $id ) {
	if ( ! isset( $GLOBALS['t_db'][ $id ] ) ) { return false; }
	$row = $GLOBALS['t_db'][ $id ];
	$p = new WC_Product( $id, $row['parent'] ); $p->type = $row['type']; $p->children = $row['children']; $p->p = $row['p'];
	return $p;
}

/** Fake quarantine store with the public API the guard uses. */
final class Mobo_Core_Migration_Quarantine {
	const OPTION = 'mobo_core_migration_quarantine_v1';
	public static $items = array();
	public static function all() { return self::$items; }
	public static function count() { return count( self::$items ); }
	public static function is_marked( $id ) { return isset( self::$items[ absint( $id ) ] ); }
	public static function unmark( $id ) { unset( self::$items[ absint( $id ) ] ); return true; }
	public static function reset_request_cache() {}
}
final class Mobo_Core_Product_State_Repository {
	public function get_by_woo_product_id( $id ) { return 10 === absint( $id ) ? array( 'product_guid' => 'GUID-CANON' ) : null; }
}

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-stock-policy.php';
require $inc . 'class-mobo-core-source-visibility.php';
require $inc . 'class-mobo-core-quarantine-sale-guard.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}
$G = 'Mobo_Core_Quarantine_Sale_Guard';
$V = 'Mobo_Core_Source_Visibility';
$copy_entry = static function ( $owner, $type = 'product', $parent = 0 ) {
	return array( 'w' => 'identity', 'r' => 'identity_owned_by_other_object', 'd' => '#' . $owner, 't' => $type, 'parent' => $parent, 'at' => 1, 'probes' => 0, 'probeAt' => 0, 'probe' => '' );
};

echo "Pure policy\n";
check( $G::is_copy_entry( $copy_entry( 10 ) ), 'identity_owned_by_other_object is a non-canonical copy' );
check( ! $G::is_copy_entry( array( 'r' => 'mirror_failed' ) ), 'mirror_failed (canonical object) is not treated as a copy' );
check( 10 === $G::owner_id( $copy_entry( 10 ) ) && 0 === $G::owner_id( array( 'd' => 'x' ) ), 'owner id parsed from "#<id>" only' );
check( 'block' === $G::decide( $copy_entry( 10 ), 'publish', false, true ), 'live copy => block' );
check( 'none' === $G::decide( $copy_entry( 10 ), 'publish', true, true ), 'already blocked => idempotent' );
check( 'release' === $G::decide( $copy_entry( 10 ), 'trash', true, true ), 'trashed copy after stamp => release' );
check( 'release' === $G::decide( $copy_entry( 10 ), 'draft', false, true ), 'drafted copy after stamp => release' );
check( 'none' === $G::decide( $copy_entry( 10 ), 'draft', true, false ), 'migration still running => stay marked (unmark unsafe before stamp)' );
check( 'none' === $G::decide( array( 'r' => 'malformed_identity' ), 'publish', false, true ), 'other quarantine reasons untouched' );

echo "Live copy is taken off sale (simple)\n";
t_create( 10, 'publish', array( 'manage' => true, 'qty' => 5, 'status' => 'instock' ) ); // canonical owner
t_create( 11, 'publish', array( 'manage' => true, 'qty' => 7, 'status' => 'instock', 'catalog_visibility' => 'visible' ) ); // copy
Mobo_Core_Migration_Quarantine::$items = array( 11 => $copy_entry( 10 ) );
$r = $G::reconcile( 25 );
$copy = wc_get_product( 11 );
check( 1 === $r['blocked'], 'reconcile blocked the copy' );
check( '1' === get_post_meta( 11, $G::META_BLOCKED, true ), 'sale-block flag set' );
check( 'outofstock' === $copy->get_stock_status() && false === $copy->get_manage_stock(), 'persisted out of stock (DB/lookup/feeds agree)' );
check( 'hidden' === $copy->get_catalog_visibility() && 'visible' === get_post_meta( 11, $G::META_PREV_VISIBILITY, true ), 'hidden from catalog/search, previous visibility remembered' );
check( false === $V::filter_purchasable( true, $copy ) && false === $V::filter_in_stock( true, $copy ) && 'outofstock' === $V::filter_stock_status( 'instock', $copy ), 'storefront guards: not purchasable, not in stock' );
check( 'publish' === get_post_status( 11 ), 'URL kept: post status unchanged, nothing deleted' );
$owner = wc_get_product( 10 );
check( true === $V::filter_purchasable( true, $owner ) && 'instock' === $owner->get_stock_status(), 'canonical owner keeps selling' );
check( 0 === $G::reconcile( 25 )['blocked'] && '1' === get_post_meta( 11, $G::META_BLOCKED, true ), 'second reconcile is idempotent' );

echo "Variable copy: variations follow the parent\n";
t_create( 21, 'publish', array( 'manage' => true, 'qty' => 3, 'status' => 'instock' ), 'variation', 20 );
t_create( 22, 'publish', array( 'manage' => false, 'status' => 'instock' ), 'variation', 20 );
t_create( 20, 'publish', array( 'status' => 'instock' ), 'variable', 0, array( 21, 22 ) );
Mobo_Core_Migration_Quarantine::$items[20] = $copy_entry( 10 );
Mobo_Core_Migration_Quarantine::$items[21] = $copy_entry( 99, 'product_variation', 20 );
$G::reconcile( 25 );
$v21 = wc_get_product( 21 ); $v22 = wc_get_product( 22 );
check( false === $V::filter_purchasable( true, $v22 ), 'unmarked variation of a blocked copy is not purchasable (parent flag)' );
check( 'outofstock' === $v21->get_stock_status() && 'outofstock' === $v22->get_stock_status(), 'every variation persisted out of stock' );
check( '3' === (string) get_post_meta( 21, $V::META_NATURAL_QTY, true ), 'natural stock remembered in the overlay bookkeeping' );

echo "Trash releases the quarantine cleanly (after stamp)\n";
$GLOBALS['t_status'][20] = 'trash';
$post = (object) array( 'ID' => 20, 'post_type' => 'product' );
$G::handle_transition( 'trash', 'publish', $post );
check( ! Mobo_Core_Migration_Quarantine::is_marked( 20 ) && ! Mobo_Core_Migration_Quarantine::is_marked( 21 ), 'copy and its marked variation unmarked' );
check( '' === get_post_meta( 20, $G::META_BLOCKED, true ) && 0 === strpos( (string) get_post_meta( 20, $G::META_RELEASED, true ), 'off_sale:trash@' ), 'flag dropped, audit marker written' );
$v21 = wc_get_product( 21 );
check( 'outofstock' === $v21->get_stock_status() && '' === get_post_meta( 21, $V::META_STOCK_BLOCKED, true ), 'stays out of stock; no stale stock can be restored later' );
check( 'hidden' === wc_get_product( 20 )->get_catalog_visibility(), 'stays hidden from catalog' );
$V::sync_persisted_stock( 20 );
check( 'outofstock' === wc_get_product( 21 )->get_stock_status(), 'a later stock convergence does not revive the stale quantity' );
$r = $G::reconcile( 25 );
check( 0 === $r['restored'], 'reconcile does not "restore" a guard-released copy' );

echo "Draft before the DB version is stamped keeps it marked\n";
$GLOBALS['t_options']['mobo_core_db_version'] = '10.34.70';
$GLOBALS['t_status'][11] = 'draft';
$G::handle_transition( 'draft', 'publish', (object) array( 'ID' => 11, 'post_type' => 'product' ) );
check( Mobo_Core_Migration_Quarantine::is_marked( 11 ), 'still marked while retirement fences are not active' );
check( false === $V::filter_purchasable( true, wc_get_product( 11 ) ), 'still not purchasable' );
check( ! empty( $GLOBALS['t_actions']['shutdown'] ), 'a reconcile is scheduled for later' );
$GLOBALS['t_options']['mobo_core_db_version'] = '10.34.77';
$r = $G::reconcile( 25 );
check( 1 === $r['released'] && ! Mobo_Core_Migration_Quarantine::is_marked( 11 ), 'released by reconcile once stamped' );
check( 'outofstock' === wc_get_product( 11 )->get_stock_status(), 'released draft copy stays out of stock' );

echo "Re-probe release (copy became the consistent owner)\n";
t_create( 31, 'publish', array( 'manage' => true, 'qty' => 4, 'status' => 'instock', 'catalog_visibility' => 'catalog' ) );
Mobo_Core_Migration_Quarantine::$items[31] = $copy_entry( 10 );
$G::reconcile( 25 );
check( 'hidden' === wc_get_product( 31 )->get_catalog_visibility(), 'blocked first' );
unset( Mobo_Core_Migration_Quarantine::$items[31] ); // reprobe released it
$r = $G::reconcile( 25 );
$p31 = wc_get_product( 31 );
check( 1 === $r['restored'] && 'catalog' === $p31->get_catalog_visibility(), 'catalog visibility restored' );
check( 'outofstock' === $p31->get_stock_status() && '1' === get_post_meta( 31, $V::META_STOCK_BLOCKED, true ), 'stock stays out until the next Source update writes fresh stock+price' );
check( true === $V::filter_purchasable( true, $p31 ), 'flag cleared: the next sync can make it purchasable again' );

echo "Admin duplicates card (post-migration)\n";
t_create( 41, 'publish' );
t_create( 42, 'draft' );
Mobo_Core_Migration_Quarantine::$items = array(
	41 => $copy_entry( 10 ),
	42 => $copy_entry( 10 ),
	43 => array( 'r' => 'mirror_failed', 't' => 'product', 'd' => '' ),
);
$groups = $G::duplicate_groups( 10 );
check( 1 === count( $groups ), 'one group per canonical owner' );
check( '10,41' === $groups[0]['ids'] && 10 === $groups[0]['canonical_id'] && 'GUID-CANON' === $groups[0]['product_guid'], 'owner first, live copy listed, drafted copy excluded' );
check( 1 === $G::count_duplicate_groups(), 'count matches' );

echo "Wiring (static)\n";
$root = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/';
$pc = file_get_contents( $root . 'includes/class-mobo-core-product-concurrency.php' );
check( false !== strpos( $pc, 'Mobo_Core_Quarantine_Sale_Guard::duplicate_groups' ) && false !== strpos( $pc, "Mobo_Core_Quarantine_Sale_Guard::count_duplicate_groups" ), 'duplicates card reads post-migration quarantines in custom mode' );
check( false !== strpos( $pc, "\$group['canonical_id']" ), 'resolve action keeps the proven canonical owner' );
$boot = file_get_contents( $root . 'mobo-core.php' );
check( false !== strpos( $boot, "add_action( 'transition_post_status', 'mobo_core_quarantine_sale_guard_transition'" ) && false !== strpos( $boot, "add_action( 'updated_option', 'mobo_core_quarantine_sale_guard_option_changed'" ), 'trash/draft and quarantine-change hooks registered' );
$cv = file_get_contents( $root . 'includes/class-mobo-core-checkout-validator.php' );
check( false !== strpos( $cv, "array( 'multi_connection', 'invalid', 'quarantined' )" ), 'orders containing quarantined items still go to manual review' );

echo "\n{$checks} checks, {$failures} failure(s) on PHP " . PHP_VERSION . "\n";
exit( $failures > 0 ? 1 : 0 );
