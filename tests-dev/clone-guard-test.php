<?php
/**
 * Standalone test (S1): a staging copy / clone / un-reprovisioned domain move of
 * a customer site must never place a real Source order or wallet payment.
 *
 * Run: php tests-dev/clone-guard-test.php
 * No WordPress, no network, no database. PHP 7.4 compatible.
 */

require __DIR__ . '/stubs.php';

$GLOBALS['t_env']     = 'production';
$GLOBALS['t_queries'] = array();
$GLOBALS['t_orders']  = array();

function delete_option( $name ) { unset( $GLOBALS['mobo_test_options'][ $name ] ); return true; }
function wp_get_environment_type() { return $GLOBALS['t_env']; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wc_get_order( $id ) { return isset( $GLOBALS['t_orders'][ $id ] ) ? $GLOBALS['t_orders'][ $id ] : false; }
function wp_generate_uuid4_unused() { return ''; }

/** Any GET_LOCK/queue write here would mean the clone path tried to queue a purchase. */
final class T_WPDB {
	public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $a ); }
	public function get_var( $q ) { $GLOBALS['t_queries'][] = $q; return null; }
	public function get_row( $q, $m = null ) { $GLOBALS['t_queries'][] = $q; return null; }
}
$GLOBALS['wpdb'] = new T_WPDB();

class WC_Product { public function get_id() { return 1; } }
class WC_Order_Item_Product {
	public $meta; public $product_id; public $variation_id; public $name;
	public function __construct( $p, $v, $n, $meta ) { $this->product_id = $p; $this->variation_id = $v; $this->name = $n; $this->meta = $meta; }
	public function get_meta( $k, $s = true ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function get_product_id() { return $this->product_id; }
	public function get_variation_id() { return $this->variation_id; }
	public function get_product() { return new WC_Product(); }
	public function get_name() { return $this->name; }
}
class WC_Order {
	public $id; public $status = 'processing'; public $items = array(); public $meta = array(); public $notes = array(); public $saves = 0;
	public function __construct( $id ) { $this->id = $id; $GLOBALS['t_orders'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_items( $t = 'line_item' ) { return $this->items; }
	public function get_meta( $k, $s = true ) { return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { $this->saves++; return $this->id; }
	public function save_meta_data() { $this->saves++; }
	public function add_order_note( $n ) { $this->notes[] = $n; }
}
final class Mobo_Core_Migration_Quarantine {
	public static function count() { return 0; }
	public static function is_marked( $id ) { return false; }
}
final class Mobo_Core_Connection_Context {
	public static function run( $id, $cb ) { return call_user_func( $cb ); }
	public static function get_effective_connection_id() { return 1; }
}
final class Mobo_Core_Order_Submission_Policy {
	public static function is_enabled() { return true; }
	public static function is_enabled_for_any_connection() { return true; }
}

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-clone-guard.php';
require $inc . 'class-mobo-core-checkout-validator.php';

$C = 'Mobo_Core_Clone_Guard';
$set_site = static function ( $home, $site = null ) {
	$GLOBALS['mobo_test_options']['home']    = $home;
	$GLOBALS['mobo_test_options']['siteurl'] = null === $site ? $home : $site;
	Mobo_Core_Clone_Guard::reset_cache();
};

echo "Site key normalization\n";
mobo_assert_same( 'shop.ir', $C::site_key( 'https://www.Shop.ir/' ), 'www and case ignored' );
mobo_assert_same( $C::site_key( 'http://shop.ir' ), $C::site_key( 'https://www.shop.ir/' ), 'http vs https of the same host is the same site' );
mobo_assert_same( 'shop.ir', $C::site_key( 'https://shop.ir:443' ), 'default port ignored' );
mobo_assert_same( 'localhost:8081', $C::site_key( 'http://localhost:8081' ), 'non-default port is part of the identity' );
mobo_assert_same( 'shop.ir/staging', $C::site_key( 'https://shop.ir/staging/' ), 'path is part of the identity (subdirectory staging)' );
mobo_assert( $C::site_key( 'https://staging.shop.ir' ) !== $C::site_key( 'https://shop.ir' ), 'staging subdomain differs' );

echo "Pure decision\n";
$binding = array( 'home' => 'shop.ir', 'site' => 'shop.ir', 'db' => 'aaa' );
$d = $C::evaluate( $binding, array( 'home' => 'https://www.shop.ir', 'site' => 'http://shop.ir/' ), 'production', false, 'aaa' );
mobo_assert( ! $d['purchaseBlocked'] && ! $d['cloneSuspected'], 'same host (www/https variations): normal site unaffected' );
$d = $C::evaluate( $binding, array( 'home' => 'https://staging.shop.ir', 'site' => 'https://staging.shop.ir' ), 'production', false, 'bbb' );
mobo_assert( $d['purchaseBlocked'] && $d['cloneSuspected'] && array( 'site_url_changed' ) === $d['reasons'], 'different domain => clone suspected, purchase blocked' );
mobo_assert( true === $d['databaseChanged'], 'database fingerprint change is reported' );
$d = $C::evaluate( $binding, array( 'home' => 'https://shop.ir', 'site' => 'https://shop.ir' ), 'production', false, 'bbb' );
mobo_assert( ! $d['purchaseBlocked'] && $d['databaseChanged'], 'DB change alone (hosting move) never blocks' );
$d = $C::evaluate( $binding, array( 'home' => 'https://shop.ir', 'site' => 'https://shop.ir' ), 'staging', false );
mobo_assert( $d['purchaseBlocked'] && ! $d['cloneSuspected'] && array( 'non_production_environment' ) === $d['reasons'], 'WP_ENVIRONMENT_TYPE=staging blocks purchase' );
$d = $C::evaluate( $binding, array( 'home' => 'https://shop.ir', 'site' => 'https://shop.ir' ), 'local', true );
mobo_assert( ! $d['purchaseBlocked'], 'developer override MOBO_CORE_ALLOW_NON_PRODUCTION_PURCHASE allows local test benches' );
$d = $C::evaluate( null, array( 'home' => 'https://shop.ir' ), 'production', false );
mobo_assert( ! $d['purchaseBlocked'] && ! $d['bound'], 'unbound production site is not blocked' );

echo "Live: adoption, clone detection, re-provisioning\n";
$set_site( 'https://shop.ir' );
mobo_assert( ! $C::is_purchase_blocked(), 'existing production install is not blocked' );
$b = $C::binding();
mobo_assert( is_array( $b ) && 'shop.ir' === $b['home'] && 'adopted' === $b['source'], 'current address adopted once (production only)' );
$set_site( 'https://staging.shop.ir' ); // DB copied to staging
mobo_assert( $C::is_purchase_blocked() && $C::is_clone_suspected(), 'copy at a new domain is blocked' );
mobo_assert( 'shop.ir' === $C::binding()['home'], 'binding is NOT updated automatically' );
$h = $C::health_payload();
mobo_assert( true === $h['cloneSuspected'] && 'staging.shop.ir' === $h['currentHome'] && 'shop.ir' === $h['boundHome'] && $h['detectedAt'] > 0, 'health reports cloneSuspected with both addresses' );
mobo_assert( false !== strpos( $C::blocked_message(), 'Provision' ) && false !== strpos( $C::blocked_message(), 'staging.shop.ir' ), 'Persian message names the address and the re-provision path' );
mobo_assert( true === $C::bind_current_site( 'provision' ) && ! $C::is_purchase_blocked(), 'Backend re-provisioning (domain move) re-binds and clears' );
mobo_assert( null === get_option( $C::OPTION_DETECTION, null ), 'detection record cleared on re-provision' );
$set_site( 'https://shop.ir' );
mobo_assert( $C::is_purchase_blocked(), 'after re-binding to the new domain, the old domain is the mismatch' );
$C::bind_current_site( 'provision' );
$GLOBALS['mobo_test_options'] = array( 'home' => 'https://dev.shop.local', 'siteurl' => 'https://dev.shop.local' );
$GLOBALS['t_env'] = 'local';
$C::reset_cache();
mobo_assert( null === $C::binding() || 'adopted' !== ( $C::binding()['source'] ?? '' ), 'no adoption on a non-production environment' );
mobo_assert( $C::is_purchase_blocked(), 'local environment without override is blocked' );
$GLOBALS['t_env'] = 'production';
$set_site( 'https://shop.ir' );
$C::bind_current_site( 'provision' );

echo "Order paths on a clone (no Source call, manual review)\n";
$class     = new ReflectionClass( 'Mobo_Core_Checkout_Validator' );
$validator = $class->newInstanceWithoutConstructor();
$call = static function ( $method, ...$args ) use ( $class, $validator ) {
	$m = $class->getMethod( $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $validator, $args );
};
$V = 'Mobo_Core_Checkout_Validator';
$mobo_item = static function () use ( $V ) {
	return new WC_Order_Item_Product( 60, 0, 'Hat', array( $V::ITEM_META_IDENTITY_CAPTURED => 'yes', $V::ITEM_META_IS_MOBO => 'yes', $V::ITEM_META_CONNECTION_ID => 1 ) );
};

mobo_assert_same( true, $call( 'fresh_checkout_route_preflight' ), 'normal site: Source route fence passes' );
$set_site( 'https://clone.shop.ir' );
$fence = $call( 'fresh_checkout_route_preflight' );
mobo_assert_error( 'mobo_core_clone_guard_blocked', $fence, 'clone: every Source request (login/cart/checkout/wallet/balance) refused' );
mobo_assert( is_wp_error( $fence ) && false === $fence->get_error_data()['requestMayHaveReachedServer'], 'refusal is provably pre-send' );
mobo_assert( false === $call( 'is_mobo_cart_validation_effective' ), 'clone checkout never rebuilds the original shared Source cart' );

$order = new WC_Order( 501 );
$order->items = array( $mobo_item() );
$GLOBALS['t_queries'] = array();
$validator->handle_order_status_changed( 501, 'pending', 'processing', $order );
mobo_assert( 'blocked_clone' === $order->get_meta( '_mobo_order_submit_status' ) && 'yes' === $order->get_meta( '_mobo_order_requires_review' ), 'paid order held for manual review' );
mobo_assert( 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'بررسی دستی' ), 'Persian order note written' );
mobo_assert( 0 === count( preg_grep( '/GET_LOCK/', $GLOBALS['t_queries'] ) ), 'nothing was queued for purchase' );
$call( 'hold_order_for_clone_review', $order, 'again' );
mobo_assert( 1 === count( $order->notes ), 'hold is idempotent (no duplicate notes)' );

$uncertain = new WC_Order( 502 );
$uncertain->items = array( $mobo_item() );
$uncertain->meta['_mobo_order_submit_status'] = 'uncertain';
$call( 'hold_order_for_clone_review', $uncertain, 'queue_processor' );
mobo_assert( 'uncertain' === $uncertain->get_meta( '_mobo_order_submit_status' ) && 'yes' === $uncertain->get_meta( '_mobo_order_clone_blocked' ), 'copied uncertain evidence is preserved, order flagged for review' );

$submit = $call( 'submit_order_to_mobo_in_connection', new WC_Order( 503 ), array(), 1 );
mobo_assert_error( 'mobo_core_clone_guard_blocked', $submit, 'submit entry fence (queue worker / admin retry) refuses' );
mobo_assert( 'blocked_clone' === $GLOBALS['t_orders'][503]->get_meta( '_mobo_order_submit_status' ), 'submit entry also holds the order' );
$GLOBALS['t_orders'][503]->items = array( $mobo_item() );
$state = $call( 'get_order_admin_submission_state', $GLOBALS['t_orders'][503] );
mobo_assert( false === $state['canRetry'] && false !== strpos( $state['label'], 'staging' ), 'admin view explains the clone block and offers no retry' );

echo "Wiring (static)\n";
$root = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/';
$cv = file_get_contents( $inc . 'class-mobo-core-checkout-validator.php' );
$p_hold = strpos( $cv, "\$this->hold_order_for_clone_review( \$order, 'queue_processor' );" );
$p_submit = strpos( $cv, '$submit = $this->submit_order_to_mobo( $order, $context );' );
mobo_assert( false !== $p_hold && false !== $p_submit && $p_hold < $p_submit, 'queue worker holds before any reconcile/submit' );
mobo_assert( false !== strpos( $cv, "array( 'failed', 'aborted', 'price_review', 'blocked_clone' )" ), 'blocked_clone is terminal for automation' );
$rest = file_get_contents( $inc . 'class-mobo-core-rest-controller.php' );
mobo_assert( false !== strpos( $rest, "Mobo_Core_Clone_Guard::bind_current_site( 'provision' )" ), 'provisioning re-binds the site' );
$health = file_get_contents( $inc . 'class-mobo-core-health-reporter.php' );
mobo_assert( false !== strpos( $health, "'cloneSuspected'" ) && false !== strpos( $health, "'siteBinding'" ), 'health payload carries cloneSuspected' );
$signer = file_get_contents( $inc . 'class-mobo-core-portal-request-signer.php' );
mobo_assert( false !== strpos( $signer, 'X-Mobo-Clone-Suspected' ) && false !== strpos( $signer, "'cloneSuspected' =>" ), 'Backend sees the hint on signed requests and the installation challenge' );
$boot = file_get_contents( $root . 'mobo-core.php' );
mobo_assert( false !== strpos( $boot, "array( 'Mobo_Core_Clone_Guard', 'render_admin_notice' )" ), 'admin notice registered' );

mobo_finish();
