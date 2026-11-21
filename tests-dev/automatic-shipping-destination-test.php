<?php
/**
 * Standalone test (H4): Mobo_Core_Automatic_Shipping::build_package_context()
 * must not fatal when the Connection money contract cannot convert a line
 * total (normalize_destination() was called but never defined).
 *
 * Run: php tests-dev/automatic-shipping-destination-test.php
 * No WordPress, no network, no database.
 */

require __DIR__ . '/stubs.php';

class WC_Product {
	public function get_id() { return 10; }
	public function get_weight() { return ''; }
}

final class Mobo_Core_Product_Identity_Policy {
	public static function is_mobo_product( $product, $product_id, $variation_id ) { return true; }
}


final class Mobo_Core_Money_Policy {
	public static $api_price = null;
	public static $contract_ok = false;
	public static function get_mobo_api_price( $variation_id, $product_id ) { return self::$api_price; }
	public static function shipping_store_to_source( $amount ) { return self::$contract_ok ? (float) $amount : null; }
}

require dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/class-mobo-core-automatic-shipping.php';

$build = new ReflectionMethod( 'Mobo_Core_Automatic_Shipping', 'build_package_context' );
$build->setAccessible( true );
$normalize = new ReflectionMethod( 'Mobo_Core_Automatic_Shipping', 'normalize_destination' );
$normalize->setAccessible( true );

$package = array(
	'contents'    => array(
		array( 'data' => new WC_Product(), 'product_id' => 10, 'variation_id' => 11, 'quantity' => 2, 'line_total' => 500000 ),
	),
	'destination' => array( 'country' => 'ir', 'state' => ' THR ', 'city' => '<b>تهران</b>', 'postcode' => array( 'x' ), 'address_1' => 'خیابان ۱' ),
);

echo "H4: invalid money contract path (no mobo_api_price / API-price shipping disabled)\n";
Mobo_Core_Money_Policy::$api_price   = null;
Mobo_Core_Money_Policy::$contract_ok = false;
$context = null;
$error   = '';
try {
	$context = $build->invoke( null, $package );
} catch ( Throwable $e ) {
	$error = get_class( $e ) . ': ' . $e->getMessage();
}
mobo_assert_same( '', $error, 'build_package_context() does not throw (no undefined-method fatal)' );
mobo_assert( is_array( $context ) && ! empty( $context['moneyContractInvalid'] ) && 0 === $context['moboItemCount'], 'invalid contract fails closed (no Mobo items priced)' );
mobo_assert_same(
	array( 'country' => 'IR', 'state' => 'THR', 'postcode' => '', 'city' => 'تهران', 'address' => '', 'address_1' => 'خیابان ۱', 'address_2' => '' ),
	is_array( $context ) ? $context['destination'] : null,
	'destination normalized to sanitized strings with a stable shape'
);

update_option( 'mobo_core_mobo_shipping_use_api_price', '0' );
Mobo_Core_Money_Policy::$api_price = 1000.0;
$context = $build->invoke( null, $package );
mobo_assert( ! empty( $context['moneyContractInvalid'] ), 'API-price shipping disabled + invalid contract => same safe path' );
update_option( 'mobo_core_mobo_shipping_use_api_price', '1' );

echo "Valid contract path unchanged\n";
Mobo_Core_Money_Policy::$api_price   = null;
Mobo_Core_Money_Policy::$contract_ok = true;
$context = $build->invoke( null, $package );
mobo_assert( empty( $context['moneyContractInvalid'] ) && 1 === $context['moboItemCount'] && 500000.0 === (float) $context['moboSubtotal'], 'convertible line total is summed as before' );

echo "normalize_destination edge cases\n";
mobo_assert_same( '', $normalize->invoke( null, 'not-an-array' )['country'], 'non-array destination => empty strings' );
mobo_assert_same( 7, count( $normalize->invoke( null, array() ) ), 'always the 7 standard keys' );

mobo_finish();
