<?php
/**
 * Standalone (no WordPress) test for Mobo_Core_Source_Visibility.
 *
 * Run: php tests-dev/source-visibility-test.php
 * PHP 7.4+ compatible.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mobo_test_meta']    = array();
$GLOBALS['mobo_test_filters'] = array();
$GLOBALS['mobo_test_saves']   = 0;

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['mobo_test_filters'][ $hook ] = $cb; return true; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['mobo_test_meta'][ $id ][ $key ] ) ? $GLOBALS['mobo_test_meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['mobo_test_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['mobo_test_meta'][ $id ][ $key ] ); return true; }

class WC_Product {
	public $id; public $parent_id = 0; public $catalog_visibility = 'visible';
	public function __construct( $id, $parent_id = 0 ) { $this->id = $id; $this->parent_id = $parent_id; }
	public function get_id() { return $this->id; }
	public function get_parent_id( $context = 'view' ) { return $this->parent_id; }
	public function get_catalog_visibility( $context = 'view' ) { return $this->catalog_visibility; }
	public function set_catalog_visibility( $v ) { $this->catalog_visibility = $v; }
	public function get_meta( $key, $single = true, $context = 'view' ) { return get_post_meta( $this->id, $key, true ); }
	public function save() { $GLOBALS['mobo_test_saves']++; return $this->id; }
}

$GLOBALS['mobo_test_products'] = array();
function wc_get_product( $id ) { return isset( $GLOBALS['mobo_test_products'][ $id ] ) ? $GLOBALS['mobo_test_products'][ $id ] : false; }

require __DIR__ . '/../wp-content/plugins/mobo-core/includes/class-mobo-core-source-visibility.php';

$failures = 0;
function check( $cond, $label ) {
	global $failures;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $failures++; }
}

$V = 'Mobo_Core_Source_Visibility';

// --- payload classification
check( '' === $V::payload_visibility( array( 'title' => 'legacy 10.33.67 payload' ) ), 'missing status fields => unknown (keep behavior)' );
check( '' === $V::payload_visibility( array() ), 'empty payload => unknown' );
check( '' === $V::payload_visibility( array( 'sourceStatus' => null ) ), 'null sourceStatus => unknown' );
check( 'hidden' === $V::payload_visibility( array( 'visibility' => 'hidden' ) ), 'visibility hidden' );
check( 'visible' === $V::payload_visibility( array( 'Visibility' => 'visible', 'sourceStatus' => 'draft' ) ), 'explicit visibility wins' );
check( 'hidden' === $V::payload_visibility( array( 'sourceStatus' => 'absent' ) ), 'absent => hidden' );
check( 'visible' === $V::payload_visibility( array( 'SourceStatus' => 'published' ) ), 'published => visible (PascalCase)' );
check( '' === $V::payload_visibility( array( 'sourceStatus' => 'weird' ) ), 'unrecognized => unknown' );
check( $V::payload_allows_creation( array( 'title' => 'x' ) ), 'legacy payload may create' );
check( ! $V::payload_allows_creation( array( 'visibility' => 'hidden', 'sourceStatus' => 'draft' ) ), 'not published => never created' );
check( $V::payload_allows_creation( array( 'visibility' => 'visible' ) ), 'published => may create' );
check( null === $V::payload_variant_purchasable( array( 'stock' => 3 ) ), 'legacy variant => unknown' );
check( false === $V::payload_variant_purchasable( array( 'purchasable' => false ) ), 'variant purchasable false' );
check( false === $V::payload_variant_purchasable( array( 'sourceStatus' => 'inactive' ) ), 'variant inactive => not purchasable' );
check( 'hidden' === $V::variant_page_parent_visibility( array( 'productVisibility' => 'hidden' ) ), 'variant page parent hidden' );
check( '' === $V::variant_page_parent_visibility( array( 'data' => array() ) ), 'variant page without parent fields => unknown' );

// --- apply / restore
$GLOBALS['mobo_test_products'][10] = new WC_Product( 10 );
$GLOBALS['mobo_test_products'][10]->catalog_visibility = 'search';
check( false === $V::apply_to_product( 10, '' ), 'unknown visibility changes nothing' );
check( empty( $GLOBALS['mobo_test_meta'][10] ), 'no meta written for unknown' );
check( true === $V::apply_to_product( 10, 'hidden', 'draft' ), 'hide existing product' );
check( 'hidden' === $GLOBALS['mobo_test_products'][10]->catalog_visibility, 'catalog visibility hidden' );
check( '1' === get_post_meta( 10, $V::META_HIDDEN ), 'hidden flag set' );
check( 'search' === get_post_meta( 10, $V::META_PREV_VISIBILITY ), 'previous visibility remembered' );
check( false === $V::apply_to_product( 10, 'hidden', 'draft' ), 'idempotent hide' );

$V::init();
$p = $GLOBALS['mobo_test_products'][10];
check( false === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_is_purchasable'], true, $p ), 'hidden product not purchasable' );
check( 'outofstock' === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_product_get_stock_status'], 'instock', $p ), 'hidden product out of stock' );
$child = new WC_Product( 11, 10 );
check( false === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_variation_is_purchasable'], true, $child ), 'variation of hidden parent not purchasable' );

check( true === $V::apply_to_product( 10, 'visible' ), 'restore on republish' );
check( 'search' === $GLOBALS['mobo_test_products'][10]->catalog_visibility, 'previous catalog visibility restored' );
check( '' === get_post_meta( 10, $V::META_HIDDEN ), 'hidden flag cleared' );
check( true === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_is_purchasable'], true, $p ), 'purchasable again' );
check( 'instock' === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_product_get_stock_status'], 'instock', $p ), 'stock status from desired state again' );

// Merchant changed visibility while hidden => keep merchant choice on restore.
$V::apply_to_product( 10, 'hidden' );
$GLOBALS['mobo_test_products'][10]->catalog_visibility = 'catalog';
$V::apply_to_product( 10, 'visible' );
check( 'catalog' === $GLOBALS['mobo_test_products'][10]->catalog_visibility, 'merchant visibility edit preserved on restore' );

// Inactive variant flag.
$var = new WC_Product( 21, 20 );
check( false === $V::apply_inactive_flag( 21, null ), 'unknown variant purchasability changes nothing' );
check( true === $V::apply_inactive_flag( 21, false ), 'inactive variant flagged' );
check( false === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_variation_is_purchasable'], true, $var ), 'inactive variant not purchasable' );
check( true === $V::apply_inactive_flag( 21, true ), 'active again' );
check( true === call_user_func( $GLOBALS['mobo_test_filters']['woocommerce_variation_is_purchasable'], true, $var ), 'variant purchasable again' );

// Empty / failed pull: there is no product payload, so nothing can be hidden.
check( '' === $V::payload_visibility( null ), 'null payload (failed pull) => unknown' );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILURE(S)' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
