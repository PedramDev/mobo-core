<?php
/**
 * Standalone test (M9): Checkout Block / Store API pre-payment validation.
 * - never runs (or throws) on checkout page load / draft updates (GET, PUT);
 * - runs once on the place-order POST and stops it with a Store API
 *   RouteException (HTTP 400 + Persian message), never a plain Exception (500);
 * - validates the destination SUBMITTED in that POST, not a stale order/session
 *   address; order_processed is a fallback only.
 *
 * Run: php tests-dev/store-api-checkout-test.php
 * No WordPress, no network, no database. PHP 7.4 compatible.
 */

require __DIR__ . '/stubs.php';

eval( 'namespace Automattic\WooCommerce\StoreApi\Exceptions; class RouteException extends \Exception {
	public $error_code; public $additional_data;
	public function __construct( $error_code, $message, $http_status_code = 500, $additional_data = array() ) {
		$this->error_code = $error_code; $this->additional_data = $additional_data;
		parent::__construct( $message, $http_status_code );
	}
	public function getErrorCode() { return $this->error_code; }
}' );

function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function WC() { return (object) array( 'cart' => null ); }

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
	public function save() { return 1; }
}
class WC_Order {
	public $id; public $items = array(); public $addr = array();
	public function __construct( $id, $items, $city ) {
		$this->id = $id; $this->items = $items;
		$this->addr = array( 'country' => 'IR', 'state' => 'THR', 'city' => $city, 'address_1' => 'Street 1' );
	}
	public function get_id() { return $this->id; }
	public function get_items( $t = 'line_item' ) { return $this->items; }
	public function get_shipping_country() { return $this->addr['country']; }
	public function get_shipping_state() { return $this->addr['state']; }
	public function get_shipping_city() { return $this->addr['city']; }
	public function get_shipping_address_1() { return $this->addr['address_1']; }
}
class T_Request {
	private $method; private $body;
	public function __construct( $method, $body ) { $this->method = $method; $this->body = $body; }
	public function get_method() { return $this->method; }
	public function get_json_params() { return $this->body; }
	public function get_params() { return $this->body; }
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
}
/** Destination preflight double: records what was validated; "Unmapped" is rejected. */
final class Mobo_Core_Checkout_Preflight {
	public static $calls = array();
	private static function answer( $city ) {
		return 'Unmapped' === $city ? new WP_Error( 'mobo_core_checkout_address_unresolvable', 'شهر انتخاب‌شده برای ارسال خودکار قابل شناسایی نیست؛ لطفاً شهر را اصلاح کنید.' ) : true;
	}
	public static function validate_order_destination_values( $order, $values ) {
		self::$calls[] = array( 'submitted', $values['city'] );
		return self::answer( $values['city'] );
	}
	public static function validate_order( $order, $group ) {
		self::$calls[] = array( 'order', $order->get_shipping_city() );
		return self::answer( $order->get_shipping_city() );
	}
	public static function validate_checkout_data( $data ) {
		self::$calls[] = array( 'classic', $data['billing_city'] ?? '' );
		return true;
	}
}

$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-checkout-validator.php';

$V = 'Mobo_Core_Checkout_Validator';
$mobo = static function ( $pid ) use ( $V ) {
	return new WC_Order_Item_Product( $pid, 0, 'Mobo ' . $pid, array( $V::ITEM_META_IDENTITY_CAPTURED => 'yes', $V::ITEM_META_IS_MOBO => 'yes', $V::ITEM_META_CONNECTION_ID => 1 ) );
};
$local = static function ( $pid ) use ( $V ) {
	return new WC_Order_Item_Product( $pid, 0, 'Local ' . $pid, array( $V::ITEM_META_IDENTITY_CAPTURED => 'yes', $V::ITEM_META_IS_MOBO => 'no' ) );
};
$body = static function ( $city ) {
	return array(
		'billing_address'  => array( 'country' => 'IR', 'state' => 'THR', 'city' => $city, 'address_1' => 'Street 9' ),
		'shipping_address' => array( 'country' => 'IR', 'state' => 'THR', 'city' => $city, 'address_1' => 'Street 9' ),
		'payment_method'   => 'cod',
	);
};
$attempt = static function ( callable $fn ) {
	try {
		$fn();
		return null;
	} catch ( Exception $e ) {
		return $e;
	}
};
$RE = 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException';

echo "Page load / draft updates never validate\n";
$validator = new Mobo_Core_Checkout_Validator();
$mixed = new WC_Order( 900, array( $mobo( 1 ), $local( 2 ) ), 'Tehran' );
$e = $attempt( function () use ( $validator, $mixed ) { $validator->snapshot_store_api_draft_order( $mixed ); } );
mobo_assert( null === $e && array() === Mobo_Core_Checkout_Preflight::$calls, 'update_order_meta (GET checkout load) only freezes identity: no throw, no check' );
$e = $attempt( function () use ( $validator, $mixed, $body ) { $validator->validate_store_api_checkout_request( $mixed, new T_Request( 'PUT', $body( 'Unmapped' ) ) ); } );
mobo_assert( null === $e && array() === Mobo_Core_Checkout_Preflight::$calls, 'PUT/PATCH draft update never blocks' );

echo "Place order POST: RouteException 400 with a Persian message\n";
$e = $attempt( function () use ( $validator, $mixed, $body ) { $validator->validate_store_api_checkout_request( $mixed, new T_Request( 'POST', $body( 'Tehran' ) ) ); } );
mobo_assert( $e instanceof $RE, 'mixed Mobo/non-Mobo order stopped with a Store API RouteException (not a plain Exception)' );
mobo_assert( $e instanceof $RE && 400 === $e->getCode() && 'mobo_core_checkout_order_scope' === $e->getErrorCode(), 'HTTP 400, stable error code (was HTTP 500 unknown_server_error)' );
mobo_assert( $e instanceof Exception && false !== strpos( $e->getMessage(), 'موبو' ), 'shopper sees the Persian reason' );

echo "Destination = the address submitted in the POST\n";
Mobo_Core_Checkout_Preflight::$calls = array();
$validator = new Mobo_Core_Checkout_Validator();
$stale_ok = new WC_Order( 901, array( $mobo( 1 ) ), 'Tehran' ); // stale session/order address is fine...
$e = $attempt( function () use ( $validator, $stale_ok, $body ) { $validator->validate_store_api_checkout_request( $stale_ok, new T_Request( 'POST', $body( 'Unmapped' ) ) ); } );
mobo_assert( $e instanceof $RE && 'mobo_core_checkout_destination' === $e->getErrorCode() && 400 === $e->getCode(), '...but the submitted city is unresolvable: blocked with 400' );
mobo_assert( array( array( 'submitted', 'Unmapped' ) ) === Mobo_Core_Checkout_Preflight::$calls, 'the submitted address was checked, not the stale one' );

Mobo_Core_Checkout_Preflight::$calls = array();
$validator = new Mobo_Core_Checkout_Validator();
$stale_bad = new WC_Order( 902, array( $mobo( 1 ) ), 'Unmapped' ); // stale address is bad...
$e = $attempt( function () use ( $validator, $stale_bad, $body ) { $validator->validate_store_api_checkout_request( $stale_bad, new T_Request( 'POST', $body( 'Isfahan' ) ) ); } );
mobo_assert( null === $e, '...the shopper corrected it in the form: checkout proceeds' );
$e = $attempt( function () use ( $validator, $stale_bad ) { $validator->validate_store_api_checkout_order( $stale_bad ); } );
mobo_assert( null === $e && 1 === count( Mobo_Core_Checkout_Preflight::$calls ), 'order_processed does not re-validate an order the POST hook already validated' );

echo "order_processed fallback\n";
Mobo_Core_Checkout_Preflight::$calls = array();
$validator = new Mobo_Core_Checkout_Validator();
$order = new WC_Order( 903, array( $mobo( 1 ) ), 'Unmapped' );
$e = $attempt( function () use ( $validator, $order ) { $validator->validate_store_api_checkout_order( $order ); } );
mobo_assert( $e instanceof $RE && 400 === $e->getCode() && array( array( 'order', 'Unmapped' ) ) === Mobo_Core_Checkout_Preflight::$calls, 'fallback validates the persisted (already submitted) order address' );
$order = new WC_Order( 904, array( $local( 3 ) ), 'Unmapped' );
$e = $attempt( function () use ( $validator, $order, $body ) { $validator->validate_store_api_checkout_request( $order, new T_Request( 'POST', $body( 'Unmapped' ) ) ); } );
mobo_assert( null === $e, 'non-Mobo order is never blocked' );

echo "Wiring (static)\n";
$root = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/';
$cv = file_get_contents( $inc . 'class-mobo-core-checkout-validator.php' );
mobo_assert( false !== strpos( $cv, "add_action( 'woocommerce_store_api_checkout_update_order_meta', array( \$this, 'snapshot_store_api_draft_order' ), 20, 1 );" ), 'update_order_meta only snapshots identity' );
mobo_assert( false !== strpos( $cv, "add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( \$this, 'validate_store_api_checkout_request' ), 30, 2 );" ), 'validation bound to the place-order request hook' );
mobo_assert( false !== strpos( $cv, "add_action( 'woocommerce_store_api_checkout_order_processed', array( \$this, 'validate_store_api_checkout_order' ), 3, 1 );" ), 'order_processed fallback runs before SMS/ledger listeners' );
mobo_assert( 0 === preg_match( '/function validate_store_api_checkout_order.*?throw new Exception\(/s', substr( $cv, strpos( $cv, 'function validate_store_api_checkout_order' ), 1200 ) ), 'no plain Exception thrown from the Store API hooks' );
mobo_assert( false !== strpos( $cv, "add_action( 'woocommerce_after_checkout_validation', array( \$this, 'validate_checkout_errors' ), 20, 2 );" ), 'classic checkout still validates on submit only (posted data)' );
$boot = file_get_contents( $root . 'mobo-core.php' );
mobo_assert( false !== strpos( $boot, "'cart_checkout_blocks'," ), 'cart_checkout_blocks compatibility declared via FeaturesUtil' );

echo "WooCommerce Store API contract (local copies, informational)\n";
$wc_roots = glob( 'C:/wamp64/www/hosts/*/wp-content/plugins/woocommerce/src/StoreApi', GLOB_ONLYDIR );
$seen = array();
foreach ( is_array( $wc_roots ) ? $wc_roots : array() as $wc ) {
	$main = dirname( dirname( $wc ) ) . '/woocommerce.php';
	$ver = is_readable( $main ) && preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $main ), $m ) ? $m[1] : '';
	if ( '' === $ver || isset( $seen[ $ver ] ) ) { continue; }
	$seen[ $ver ] = true;
	$trait = (string) @file_get_contents( $wc . '/Utilities/CheckoutTrait.php' );
	$route = (string) @file_get_contents( $wc . '/Routes/V1/Checkout.php' );
	$abstract = (string) @file_get_contents( $wc . '/Routes/V1/AbstractRoute.php' );
	/* Only the place-order (POST) flow: process_order() on WC 10+, get_route_post_response() before. */
	$post_at = strpos( $route, 'function process_order(' );
	$post_at = false !== $post_at ? $post_at : strpos( $route, 'function get_route_post_response(' );
	$route_post = false !== $post_at ? substr( $route, $post_at ) : '';
	$fires = false !== strpos( $trait, "do_action( 'woocommerce_store_api_checkout_update_order_from_request', \$order, \$request )" )
		|| false !== strpos( $trait, "do_action( 'woocommerce_store_api_checkout_update_order_from_request', \$this->order, \$request )" );
	$p_customer = strpos( $route_post, '$this->update_customer_from_request( $request );' );
	$p_from_req = strpos( $route_post, '$this->update_order_from_request( $request' );
	$catches = false !== strpos( $route, 'catch ( RouteException $error )' ) || false !== strpos( $abstract, 'catch ( RouteException $error )' );
	mobo_assert( $fires && false !== $p_customer && false !== $p_from_req && $p_customer < $p_from_req && $catches, "WC {$ver}: request hook gets \$request after the submitted address is applied; RouteException mapped to its status" );
}
if ( empty( $seen ) ) { echo "  skip - no local WooCommerce copies\n"; }

mobo_finish();
