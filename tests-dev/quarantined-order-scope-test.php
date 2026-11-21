<?php
/**
 * Standalone test (M4): an order line whose product/variation is Mobo-owned but
 * held in migration quarantine (no Custom ownership row) must never be treated
 * as "non-Mobo" and silently skipped; the order goes to manual review.
 *
 * Run: php tests-dev/quarantined-order-scope-test.php
 * No WordPress, no network, no database.
 */

require __DIR__ . '/stubs.php';

function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

class WC_Product {
	public function get_id() { return 1; }
}
class WC_Order_Item_Product {
	public $meta = array();
	public $product_id;
	public $variation_id;
	public $name;
	public function __construct( $product_id, $variation_id, $name, $meta = array() ) {
		$this->product_id = $product_id; $this->variation_id = $variation_id; $this->name = $name; $this->meta = $meta;
	}
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_product_id() { return $this->product_id; }
	public function get_variation_id() { return $this->variation_id; }
	public function get_product() { return new WC_Product(); }
	public function get_name() { return $this->name; }
}
class WC_Order {
	public $items = array();
	public function get_items( $type = 'line_item' ) { return $this->items; }
}
final class Mobo_Core_Migration_Quarantine {
	public static $marked = array();
	public static function count() { return count( self::$marked ); }
	public static function is_marked( $id ) { return isset( self::$marked[ (int) $id ] ); }
}

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-checkout-validator.php';

$class     = new ReflectionClass( 'Mobo_Core_Checkout_Validator' );
$validator = $class->newInstanceWithoutConstructor();
$scope_of  = $class->getMethod( 'get_order_mobo_item_scope' );
$scope_of->setAccessible( true );
$has_mobo  = $class->getMethod( 'order_scope_has_mobo_items' );
$has_mobo->setAccessible( true );
$submittable = $class->getMethod( 'order_scope_is_submittable' );
$submittable->setAccessible( true );
$message   = $class->getMethod( 'build_unsubmittable_order_message' );
$message->setAccessible( true );

$V = 'Mobo_Core_Checkout_Validator';
/* Identity captured at checkout as "not Mobo" (no Custom row while quarantined). */
$captured_non_mobo = array( $V::ITEM_META_IDENTITY_CAPTURED => 'yes', $V::ITEM_META_IS_MOBO => 'no' );
$captured_mobo     = array( $V::ITEM_META_IDENTITY_CAPTURED => 'yes', $V::ITEM_META_IS_MOBO => 'yes', $V::ITEM_META_CONNECTION_ID => 1 );

echo "M4: quarantined Mobo item at order time\n";
Mobo_Core_Migration_Quarantine::$marked = array( 55 => true );
$order = new WC_Order();
$order->items = array( new WC_Order_Item_Product( 50, 55, 'Shirt (quarantined variation)', $captured_non_mobo ) );
$scope = $scope_of->invoke( $validator, $order );
mobo_assert_same( 'quarantined', $scope['status'], 'quarantined variation => scope "quarantined", not "non_mobo"' );
mobo_assert_same( true, $has_mobo->invoke( $validator, $scope ), 'counts as Mobo-owned (never the silent non-Mobo path)' );
mobo_assert_same( false, $submittable->invoke( $validator, $scope ), 'never submitted automatically' );
$text = $message->invoke( $validator, $scope );
mobo_assert( false !== strpos( $text, 'قرنطینه' ) && false !== strpos( $text, 'Shirt' ) && false !== strpos( $text, 'دستی' ), 'clear note names the product and asks for manual handling' );

echo "M4: mixed with a normal Mobo item is blocked as a whole\n";
$order->items[] = new WC_Order_Item_Product( 60, 0, 'Hat', $captured_mobo );
$scope = $scope_of->invoke( $validator, $order );
mobo_assert( 'quarantined' === $scope['status'] && 1 === $scope['mobo'] && 1 === $scope['quarantined'], 'no partial purchase: whole order to review' );

echo "M4: quarantine released after checkout still routes to review\n";
Mobo_Core_Migration_Quarantine::$marked = array();
$order->items = array( new WC_Order_Item_Product( 50, 55, 'Shirt', $captured_non_mobo + array( $V::ITEM_META_QUARANTINED => 'yes' ) ) );
$scope = $scope_of->invoke( $validator, $order );
mobo_assert_same( 'quarantined', $scope['status'], 'checkout-time quarantine flag on the order item is honoured' );

echo "Unchanged behaviour\n";
$order->items = array( new WC_Order_Item_Product( 70, 0, 'Merchant product', $captured_non_mobo ) );
$scope = $scope_of->invoke( $validator, $order );
mobo_assert( 'non_mobo' === $scope['status'] && false === $has_mobo->invoke( $validator, $scope ), 'genuine non-Mobo order stays non-Mobo' );
$order->items = array( new WC_Order_Item_Product( 60, 0, 'Hat', $captured_mobo ) );
$scope = $scope_of->invoke( $validator, $order );
mobo_assert( 'all_mobo' === $scope['status'] && true === $submittable->invoke( $validator, $scope ), 'normal all-Mobo order still submittable' );

echo "Wiring (static)\n";
$src = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-checkout-validator.php' );
mobo_assert( false !== strpos( $src, "array( 'multi_connection', 'invalid', 'quarantined' )" ), 'quarantined scope sets _mobo_order_requires_review' );
mobo_assert( false !== strpos( $src, "'blocked_quarantined'" ), 'status-hook path flags the order for review' );

mobo_finish();
