<?php
/**
 * Standalone (no WordPress) tests for the 10.34.77 stock and purchase-address fixes:
 * - B13/B14/B16 stock decisions and the persisted Source-visibility stock overlay;
 * - address normalization (Persian/Arabic letters, ZWNJ, digits, phone, postcode);
 * - A1 numeric-city rule (Persian WooCommerce code table only when provable);
 * - B7 deferral escalation threshold (note + one email + admin notice);
 * - B6 pre-checkout decisions and fail-open behavior.
 *
 * Run: php tests-dev/stock-and-address-flows-test.php
 * PHP 7.4+ compatible. No network, no database.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MOBO_CORE_PLUGIN_DIR', __DIR__ . '/../wp-content/plugins/mobo-core/' );

$GLOBALS['t_meta']     = array();
$GLOBALS['t_db']       = array();
$GLOBALS['t_filters']  = array();
$GLOBALS['t_options']  = array();
$GLOBALS['t_mails']    = array();
$GLOBALS['t_api']      = array();
$GLOBALS['t_var_sync'] = array();

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function get_error_codes() { return '' === $this->code ? array() : array( $this->code ); }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_email( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
function wp_specialchars_decode( $v ) { return (string) $v; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function admin_url( $p ) { return 'https://shop.test/wp-admin/' . $p; }
function current_user_can( $c ) { return true; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['t_filters'][ $hook ][] = $cb; return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return add_filter( $hook, $cb, $prio, $args ); }
function remove_filter( $hook, $cb, $prio = 10 ) {
	foreach ( isset( $GLOBALS['t_filters'][ $hook ] ) ? $GLOBALS['t_filters'][ $hook ] : array() as $i => $registered ) {
		if ( $registered === $cb ) { unset( $GLOBALS['t_filters'][ $hook ][ $i ] ); }
	}
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['t_filters'][ $hook ] ) ? $GLOBALS['t_filters'][ $hook ] : array() as $cb ) {
		$value = call_user_func_array( $cb, array_merge( array( $value ), $args ) );
	}
	return $value;
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['t_mails'][] = array( $to, $subject, $body ); return true; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['t_meta'][ $id ][ $key ] ) ? $GLOBALS['t_meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['t_meta'][ $id ][ $key ] ); return true; }
function wc_delete_product_transients( $id ) { return true; }

/** Minimal WC_Product: props persist in $GLOBALS['t_db'], meta in $GLOBALS['t_meta']. */
class WC_Product {
	public $id; public $parent_id = 0; public $type = 'simple'; public $children = array();
	public $p = array( 'manage' => false, 'qty' => null, 'status' => 'instock', 'backorders' => 'no', 'catalog_visibility' => 'visible' );
	public $meta = array();
	public $saves = 0;
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
	public function get_meta( $k, $single = true, $c = 'view' ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public $dirty = array();
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; $this->dirty[ $k ] = true; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); $this->dirty[ $k ] = true; }
	/** Emulates WC_Product::validate_props() + persistence (only changed meta is written). */
	public function save() {
		if ( ! $this->p['manage'] ) {
			$this->p['qty'] = null; $this->p['backorders'] = 'no';
		} else {
			$this->p['status'] = (int) $this->p['qty'] > 0 ? 'instock' : ( 'no' !== $this->p['backorders'] ? 'onbackorder' : 'outofstock' );
		}
		$this->saves++;
		$GLOBALS['t_db'][ $this->id ] = array( 'class' => get_class( $this ), 'type' => $this->type, 'parent' => $this->parent_id, 'children' => $this->children, 'p' => $this->p );
		foreach ( array_keys( $this->dirty ) as $k ) {
			if ( array_key_exists( $k, $this->meta ) ) { $GLOBALS['t_meta'][ $this->id ][ $k ] = $this->meta[ $k ]; } else { unset( $GLOBALS['t_meta'][ $this->id ][ $k ] ); }
		}
		$this->dirty = array();
		$GLOBALS['t_meta'][ $this->id ]['_stock_status'] = $this->p['status'];
		return $this->id;
	}
}
class WC_Product_Variable { public static function sync( $id ) { $GLOBALS['t_var_sync'][] = $id; } }
function t_create( $id, $props, $type = 'simple', $parent = 0, $children = array() ) {
	$p = new WC_Product( $id, $parent ); $p->type = $type; $p->children = $children;
	$p->p = array_merge( $p->p, $props );
	$p->meta = isset( $GLOBALS['t_meta'][ $id ] ) ? $GLOBALS['t_meta'][ $id ] : array();
	$p->save();
	return $p;
}
function wc_get_product( $id ) {
	if ( ! isset( $GLOBALS['t_db'][ $id ] ) ) { return false; }
	$row = $GLOBALS['t_db'][ $id ];
	$p = new WC_Product( $id, $row['parent'] ); $p->type = $row['type']; $p->children = $row['children']; $p->p = $row['p'];
	$p->meta = isset( $GLOBALS['t_meta'][ $id ] ) ? $GLOBALS['t_meta'][ $id ] : array();
	unset( $p->meta['_stock_status'] );
	return $p;
}

/* Resolver transport stub: records payloads, returns the scripted answer. */
class Mobo_Core_API_Client {
	public function resolve_location( $payload ) {
		$GLOBALS['t_api'][] = $payload;
		$next = isset( $GLOBALS['t_api_next'] ) ? $GLOBALS['t_api_next'] : array();
		if ( $next instanceof Exception ) { throw $next; }
		return $next;
	}
}

/* WooCommerce countries stub. */
class T_Countries {
	public function get_countries() { return array( 'IR' => 'ایران' ); }
	public function get_states( $c ) { return 'IR' === $c ? array( 'THR' => 'تهران', 'ESF' => 'اصفهان' ) : array(); }
}
class T_WC { public $countries; public function __construct() { $this->countries = new T_Countries(); } }
function WC() { static $wc = null; if ( null === $wc ) { $wc = new T_WC(); } return $wc; }

class WC_Order {
	public $id; public $meta = array(); public $notes = array(); public $saves = 0;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_meta( $k, $single = true ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save() { $this->saves++; return $this->id; }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function get_edit_order_url() { return 'https://shop.test/wp-admin/post.php?post=' . $this->id . '&action=edit'; }
}

$dir = __DIR__ . '/../wp-content/plugins/mobo-core/includes/';
require $dir . 'class-mobo-core-stock-policy.php';
require $dir . 'class-mobo-core-source-visibility.php';
require $dir . 'class-mobo-core-address-normalizer.php';
require $dir . 'class-mobo-core-address-mapping.php';
require $dir . 'class-mobo-core-address-attention.php';
require $dir . 'class-mobo-core-checkout-preflight.php';

$failures = 0; $checks = 0;
function check( $cond, $label ) {
	global $failures, $checks;
	$checks++;
	if ( ! $cond ) { $failures++; echo 'FAIL ' . $label . PHP_EOL; }
}

$S = 'Mobo_Core_Stock_Policy';
$V = 'Mobo_Core_Source_Visibility';
$N = 'Mobo_Core_Address_Normalizer';

/* ---------------- B13 / B16 stock decisions ---------------- */
$n = $S::natural_state( 0, true, array() );
check( true === $n['manage'] && 0 === $n['qty'] && 'outofstock' === $n['status'], 'B13: source stock 0 => managed, qty 0, out of stock' );
$n = $S::natural_state( 7, false, array() );
check( true === $n['manage'] && 7 === $n['qty'] && 'instock' === $n['status'], 'numeric stock => managed in stock' );
$n = $S::natural_state( null, false, array( 'manage' => false, 'status' => 'instock' ) );
check( false === $n['manage'] && 'outofstock' === $n['status'] && $S::SOURCE_UNKNOWN_NEW === $n['source'], 'B16: unknown stock on NEW object => out of stock (never unlimited)' );
$n = $S::natural_state( null, true, array( 'manage' => true, 'qty' => 4, 'status' => 'instock' ) );
check( true === $n['manage'] && 4 === $n['qty'] && 'instock' === $n['status'] && $S::SOURCE_UNKNOWN_KEPT === $n['source'], 'B16: unknown stock on EXISTING object keeps last known state' );
$n = $S::natural_state( null, true, array( 'manage' => true, 'qty' => 0, 'status' => 'onbackorder' ) );
check( 'outofstock' === $n['status'], 'B13: kept onbackorder state is normalized to out of stock' );
check( array( 'manage' => false, 'qty' => null, 'status' => 'outofstock' ) === $S::persisted_state( array( 'manage' => true, 'qty' => 9, 'status' => 'instock' ), true ), 'B14: blocked => persisted unmanaged out of stock' );
check( ! $S::props_match( array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock', 'backorders' => 'notify' ), array( 'manage' => true, 'qty' => 0 ) ), 'B13: backorders != no never matches' );
check( $S::props_match( array( 'manage' => true, 'qty' => 0, 'status' => 'outofstock', 'backorders' => 'no' ), array( 'manage' => true, 'qty' => 0 ) ), 'managed zero with backorders no matches' );

/* set_stock_props forces backorders=no so WooCommerce cannot derive onbackorder. */
$p = t_create( 50, array( 'manage' => true, 'qty' => 3, 'backorders' => 'yes' ) );
$V::set_stock_props( $p, $S::persisted_state( $S::natural_state( 0, true, array() ), false ) );
$p->save();
check( 'outofstock' === $p->get_stock_status() && 'no' === $p->get_backorders(), 'B13: zero with merchant backorders=yes persists out of stock, backorders no' );

/* ---------------- B14 persisted overlay ---------------- */
$V::init();
$p = t_create( 10, array( 'manage' => true, 'qty' => 5, 'status' => 'instock' ) );
check( true === $V::apply_to_product( 10, 'hidden', 'draft' ), 'hide simple product' );
$db = wc_get_product( 10 );
check( false === $db->get_manage_stock() && 'outofstock' === $db->get_stock_status() && 'hidden' === $db->get_catalog_visibility(), 'B14: hidden product persisted out of stock in DB (feeds/lookup see it)' );
check( array( 'manage' => true, 'qty' => 5, 'status' => 'instock' ) === $V::stored_natural_stock( $db ), 'B14: Source stock remembered' );
$snapshot = $GLOBALS['t_db'][10];
check( false === $V::apply_to_product( 10, 'hidden', 'draft' ) && $snapshot === $GLOBALS['t_db'][10], 'B14: idempotent hide (no further writes)' );
check( true === $V::apply_to_product( 10, 'visible' ), 'republish' );
$db = wc_get_product( 10 );
check( true === $db->get_manage_stock() && 5 === $db->get_stock_quantity() && 'instock' === $db->get_stock_status() && null === $V::stored_natural_stock( $db ), 'B14: republish restores remembered Source stock' );

/* Upgrade: product hidden before this release (flag set, DB still instock). */
update_post_meta( 11, $V::META_HIDDEN, '1' );
$p = t_create( 11, array( 'manage' => true, 'qty' => 2, 'status' => 'instock', 'catalog_visibility' => 'hidden' ) );
$V::apply_to_product( 11, 'hidden', 'draft' );
check( 'outofstock' === wc_get_product( 11 )->get_stock_status(), 'B14: pre-existing hidden product converges on next payload' );

/* Inactive variation. */
t_create( 21, array( 'manage' => true, 'qty' => 4, 'status' => 'instock' ), 'variation', 20 );
check( true === $V::apply_inactive_flag( 21, false ), 'flag variation inactive' );
check( 'outofstock' === wc_get_product( 21 )->get_stock_status(), 'B14: inactive variation persisted out of stock' );
check( true === $V::apply_inactive_flag( 21, true ), 'reactivate variation' );
$db = wc_get_product( 21 );
check( 'instock' === $db->get_stock_status() && 4 === $db->get_stock_quantity(), 'B14: reactivated variation restored' );

/* Hidden variable parent => variations persisted out of stock; parent synced. */
t_create( 31, array( 'manage' => true, 'qty' => 3 ), 'variation', 30 );
t_create( 32, array( 'manage' => true, 'qty' => 0 ), 'variation', 30 );
t_create( 30, array(), 'variable', 0, array( 31, 32 ) );
$GLOBALS['t_var_sync'] = array();
$V::apply_to_product( 30, 'hidden', 'deleted' );
check( 'outofstock' === wc_get_product( 31 )->get_stock_status() && in_array( 30, $GLOBALS['t_var_sync'], true ), 'B14: variations of hidden parent persisted out of stock and parent synced' );
$V::apply_to_product( 30, 'visible' );
check( 'instock' === wc_get_product( 31 )->get_stock_status() && 'outofstock' === wc_get_product( 32 )->get_stock_status(), 'B14: unhide restores each variation (zero stays out of stock)' );
check( true === $V::filter_purchasable( true, wc_get_product( 31 ) ), 'storefront filter agrees after restore' );

/* ---------------- Address normalization ---------------- */
check( 'تهران' === $N::text( 'تهران' ), 'text keeps Persian' );
check( 'کرمان' === $N::text( 'كرمان' ), 'Arabic kaf => Persian kaf' );
check( 'ری' === $N::text( 'ري' ) && 'ری' === $N::text( 'رى' ), 'Arabic yeh / alef maksura => Persian yeh' );
check( "شهر\u{200C}ری" === $N::text( "  شهر \u{200C}\u{200C} ری  " ), 'ZWNJ runs + surrounding spaces compacted' );
check( 'شهر ری' === $N::text( "شهر\u{00A0}\u{00A0} ری" ), 'NBSP/space runs compacted' );
check( '09123456789' === $N::phone( '۰۹۱۲ ۳۴۵ ۶۷۸۹' ), 'Persian-digit mobile with spaces' );
check( '09123456789' === $N::phone( '+98 912-345-6789' ), '+98 => 0' );
check( '09123456789' === $N::phone( '0098٩١٢٣٤٥٦٧٨٩' ), '0098 + Arabic-Indic digits => 0' );
check( '09123456789' === $N::phone( '989123456789' ), '98 country prefix (12 digits) => 0' );
check( '09123456789' === $N::phone( '9123456789' ), '10-digit mobile gets leading 0' );
check( '+4915112345678' === $N::phone( '+49 151 12345678' ), 'non-Iranian international number kept' );
check( '1234567890' === $N::postcode( '۱۲۳۴۵-۶۷۸۹۰' ), 'postcode Persian digits + dash' );
check( $N::match_key( 'استان تهران' ) === $N::match_key( 'تهران' ), 'match: «استان» prefix ignored' );
check( $N::match_key( 'شهر ري' ) === $N::match_key( 'ری' ), 'match: «شهر» prefix + Arabic yeh' );
check( $N::match_key( "خرم\u{200C}آباد" ) === $N::match_key( 'خرم آباد' ), 'match: ZWNJ == space' );
check( $N::match_key( 'شهرکرد' ) !== $N::match_key( 'کرد' ), 'match: «شهر» only stripped as a separate word' );
check( $N::is_numeric_code( '۳۵۹۲' ) && ! $N::is_numeric_code( 'ورامین' ), 'numeric code detection with Persian digits' );

/* ---------------- A1 numeric city rule ---------------- */
$m = new Mobo_Core_Address_Mapping();
$GLOBALS['t_api'] = array();
$GLOBALS['t_api_next'] = array( 'success' => true, 'status' => 'resolved', 'location' => array( 'countryExternalId' => '1', 'stateExternalId' => '8', 'cityExternalId' => '101' ) );
$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'THR', 'city' => '3592' ) );
check( is_wp_error( $r ) && 'mobo_core_location_numeric_city_unknown' === $r->get_error_code() && empty( $GLOBALS['t_api'] ), 'A1: numeric city of unknown origin => pending, resolver NOT called (no guess)' );
check( is_wp_error( $r ) && 'defer' === $r->get_error_data()['action'], 'A1: numeric unknown defers the order' );
$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'استان تهران', 'city' => '۳۵۹۲' ) );
check( is_wp_error( $r ) && empty( $GLOBALS['t_api'] ), 'A1: Persian-digit numeric city also unknown' );

$optin = function () { return true; };
add_filter( 'mobo_core_numeric_city_is_persian_woocommerce_code', $optin );
$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'THR', 'city' => '3592' ) );
check( ! is_wp_error( $r ) && 1 === count( $GLOBALS['t_api'] ) && 'ورامین' === $GLOBALS['t_api'][0]['cityName'], 'A1: provable Persian WooCommerce code translated via bundled table' );
remove_filter( 'mobo_core_numeric_city_is_persian_woocommerce_code', $optin );

/* Conditional declarations: defined only when this line runs (not hoisted). */
check( ! function_exists( 'PW' ) && ! function_exists( 'PWS' ) && ! class_exists( 'Persian_Woocommerce_Address', false ), 'fixture: Persian WooCommerce absent so far' );
if ( true ) {
	class Persian_Woocommerce_Address {}
	class T_PW { public function get_options( $k, $d = '' ) { return 'yes'; } }
	function PW() { return new T_PW(); }
}
$GLOBALS['t_api'] = array();
$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'THR', 'city' => '3592' ) );
check( is_wp_error( $r ) && empty( $GLOBALS['t_api'] ), 'A1: Persian WooCommerce with default value index (names) => numeric is NOT its code' );
$index = function () { return 1; };
add_filter( 'iran_cities_value_index', $index );
$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'THR', 'city' => '3592' ) );
check( ! is_wp_error( $r ) && 'ورامین' === $GLOBALS['t_api'][0]['cityName'], 'A1: Persian WooCommerce storing codes (iran_cities_value_index=1) => translated' );$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'ESF', 'city' => '3592' ) );
check( is_wp_error( $r ), 'A1: code must belong to the selected province' );
if ( true ) {
	function PWS() { return null; }
}
$GLOBALS['t_api'] = array();
$r = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'THR', 'city' => '3592' ) );
check( is_wp_error( $r ) && empty( $GLOBALS['t_api'] ), 'A1: PWS active (own term IDs) => numeric never trusted' );
remove_filter( 'iran_cities_value_index', $index );

/* Normalized names are sent; Arabic letters folded. */
$GLOBALS['t_api'] = array();
$m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'تهران', 'city' => 'شهر  ري' ) );
check( 'THR' === $GLOBALS['t_api'][0]['stateCode'] && 'شهر ری' === $GLOBALS['t_api'][0]['cityName'], 'payload: state label matched to code, city Arabic yeh/space normalized' );

/* ---------------- B6 pre-checkout decisions ---------------- */
$GLOBALS['t_api_next'] = array( 'success' => false, 'status' => 'pending', 'matchType' => 'unresolved', 'resolutionKey' => 'k' );
$pending = $m->resolve_values_via_backend( array( 'country' => 'IR', 'state' => 'THR', 'city' => 'تهرن' ) );
check( '' !== Mobo_Core_Checkout_Preflight::address_error_message( $pending ), 'B6: Backend explicit pending => checkout error' );
check( '' === Mobo_Core_Checkout_Preflight::address_error_message( new WP_Error( 'mobo_core_payload_request_failed', 'transport' ) ), 'B6: Backend unreachable => fail open' );
check( '' === Mobo_Core_Checkout_Preflight::address_error_message( new WP_Error( 'mobo_core_payload_http_error', 'HTTP 503' ) ), 'B6: Backend HTTP error => fail open' );
check( '' === Mobo_Core_Checkout_Preflight::address_error_message( new WP_Error( 'mobo_core_location_resolution_pending', 'x', array( 'action' => 'defer', 'backendStatus' => '' ) ) ), 'B6: unexpected Backend payload => fail open' );
check( '' === Mobo_Core_Checkout_Preflight::address_error_message( new WP_Error( 'mobo_core_location_numeric_city_unknown', 'x' ) ), 'B6: numeric city (merchant config) => fail open, handled by deferral' );
check( '' === Mobo_Core_Checkout_Preflight::address_error_message( array( 'cityExternalId' => '1' ) ), 'B6: resolved => allow' );
check( '' !== Mobo_Core_Checkout_Preflight::shipping_error_message( new WP_Error( 'mobo_core_shipping_method_mapping_missing', 'x' ) ), 'B6: unmapped shipping method => checkout error' );
check( '' === Mobo_Core_Checkout_Preflight::shipping_error_message( new WP_Error( 'mobo_core_wc_shipping_method_ambiguous', 'x' ) ), 'B6: other shipping states => fail open' );
$throwing = function () { throw new RuntimeException( 'boom' ); };
check( true === Mobo_Core_Checkout_Preflight::evaluate( true, $throwing ), 'B6: resolver exception => fail open' );
check( true === Mobo_Core_Checkout_Preflight::evaluate( true, function () { return new WP_Error( 'mobo_core_payload_request_failed', 'down' ); } ), 'B6: outage => allow checkout' );
$blocked = Mobo_Core_Checkout_Preflight::evaluate( true, function () use ( $pending ) { return $pending; } );
check( is_wp_error( $blocked ) && 'mobo_core_checkout_address_unresolvable' === $blocked->get_error_code(), 'B6: pending => blocked with Persian message' );
$called = false;
$ship = Mobo_Core_Checkout_Preflight::evaluate( new WP_Error( 'mobo_core_shipping_method_mapping_missing', 'x' ), function () use ( &$called ) { $called = true; return array(); } );
check( is_wp_error( $ship ) && ! $called, 'B6: unmapped shipping blocks without a network call' );
check( empty( $GLOBALS['t_filters']['http_request_args'] ), 'B6: temporary resolver timeout cap removed afterwards' );
$values = Mobo_Core_Checkout_Preflight::checkout_destination_values( array( 'billing_city' => 'A', 'shipping_city' => 'B', 'ship_to_different_address' => 1 ) );
check( 'B' === $values['city'], 'B6: ship to different address uses shipping fields' );
$values = Mobo_Core_Checkout_Preflight::checkout_destination_values( array( 'billing_city' => 'A', 'shipping_city' => 'B' ) );
check( 'A' === $values['city'], 'B6: otherwise billing fields' );

/* ---------------- B7 deferral escalation ---------------- */
$A = 'Mobo_Core_Address_Attention';
$now = 1800000000;
check( ! $A::should_notify( 1, $now, $now, false ), 'B7: first deferral => no escalation' );
check( ! $A::should_notify( $A::ATTEMPT_THRESHOLD - 1, $now - 60, $now, false ), 'B7: below both thresholds => no escalation' );
check( $A::should_notify( $A::ATTEMPT_THRESHOLD, $now - 60, $now, false ), 'B7: attempt threshold => escalate' );
check( $A::should_notify( 2, $now - $A::AGE_THRESHOLD, $now, false ), 'B7: age threshold => escalate' );
check( ! $A::should_notify( 99, $now - 99999, $now, true ), 'B7: never escalate twice' );
$order = new WC_Order( 501 );
$GLOBALS['t_options']['admin_email'] = 'owner@shop.test';
check( true === $A::notify( $order, 'pending' ), 'B7: notify' );
check( false === $A::notify( $order, 'pending' ), 'B7: second notify is a no-op' );
check( 1 === count( $GLOBALS['t_mails'] ) && false !== strpos( $GLOBALS['t_mails'][0][2], 'post=501' ), 'B7: exactly one email with a direct order link' );
check( 1 === count( $order->notes ) && isset( $A::get_list()[501] ), 'B7: one order note + admin notice entry' );
ob_start(); $A::render_admin_notice(); $html = ob_get_clean();
check( false !== strpos( $html, 'post=501' ), 'B7: admin notice links the order' );
$A::clear( 501 );
check( empty( $A::get_list() ), 'B7: cleared after the order leaves the deferral path' );

echo PHP_EOL . $checks . ' checks, ' . $failures . ' failure(s) on PHP ' . PHP_VERSION . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
