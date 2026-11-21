<?php
/**
 * Standalone developer test (no WordPress, no network) for the pricing/money
 * fixes of the 10.34.77 flows review:
 *  B1  settings defaults for a new Connection,
 *  B2/B15 zero / empty / null Source price => not purchasable,
 *  B4  Persian (percent-encoded) exclusion URLs,
 *  B11 optional rounding after markup,
 *  B12 dynamic rule validation + runtime fallback,
 *  C1  store currency change detection + Reprice can correct prices,
 *  C2  IRR -> IRT conversion with documented rounding (up),
 *  C3  decimal Source prices keep precision,
 *  C4  revenue cost converted to order currency.
 *
 * Run: php tests-dev/pricing-money-test.php
 * PHP 7.4 compatible.
 */

require __DIR__ . '/pricing-test-stubs.php';
require_once $t_inc . 'class-mobo-core-revenue-ledger.php';
require_once $t_inc . 'class-mobo-core-admin.php';

function calc() {
	return new Mobo_Core_Price_Calculator( new Mobo_Core_Legacy_Rules() );
}

echo "B11 rounding after markup (store currency)\n";
t_same( 1420000.0, Mobo_Core_Price_Rounding::round_amount( 1419752.05, 1000, 'up' ), 'up    1,419,752.05 -> 1,420,000' );
t_same( 1419000.0, Mobo_Core_Price_Rounding::round_amount( 1419752.05, 1000, 'down' ), 'down  1,419,752.05 -> 1,419,000' );
t_same( 1419000.0, Mobo_Core_Price_Rounding::round_amount( 1419499, 1000, 'nearest' ), 'nearest 1,419,499 -> 1,419,000' );
t_same( 1420000.0, Mobo_Core_Price_Rounding::round_amount( 1419500, 1000, 'nearest' ), 'nearest 1,419,500 -> 1,420,000 (half up)' );
t_same( 123500.0, Mobo_Core_Price_Rounding::round_amount( 123401, 100, 'up' ), 'up to 100: 123,401 -> 123,500' );
t_same( 130000.0, Mobo_Core_Price_Rounding::round_amount( 120001, 10000, 'up' ), 'up to 10,000: 120,001 -> 130,000' );
t_same( 1000.0, Mobo_Core_Price_Rounding::round_amount( 1000.0000000001, 1000, 'up' ), 'float noise does not jump a whole step' );
t_same( 1419752.05, Mobo_Core_Price_Rounding::round_amount( 1419752.05, 0, 'up' ), 'step 0 (none) keeps the exact value' );
t_same( 0, Mobo_Core_Price_Rounding::normalize_step( 500 ), 'unsupported step 500 normalizes to none' );
$pair = Mobo_Core_Price_Rounding::round_pair( 1000100.0, 1000050.0, array( 'step' => 1000, 'mode' => 'up' ) );
t_same( array( 1001000.0, '' ), $pair, 'sale collapsing onto regular drops the sale (no fake discount)' );
$pair = Mobo_Core_Price_Rounding::round_pair( 1500000.0, 1234567.0, array( 'step' => 1000, 'mode' => 'up' ) );
t_same( array( 1500000.0, 1235000.0 ), $pair, 'distinct regular/sale keep sale < regular' );
t_same( array(), Mobo_Core_Price_Rounding::fingerprint_fragment( array( 'step' => 0, 'mode' => 'up' ) ), 'inactive rounding keeps historical hashes (empty fragment)' );

echo "\nB2/B15 Source price missing / zero / negative\n";
t_reset();
t_setting( 'mobo_price_type', 'static-price' );
t_setting( 'global_additional_price', '50000' );
foreach ( array( 'zero' => '0', 'zero-decimal' => '0.00', 'empty' => '', 'null' => null, 'negative' => '-5' ) as $label => $raw ) {
	$p = calc()->calculate_price_pair( 0, $raw, null, 'product' );
	t_check( ! empty( $p['unavailable'] ) && '' === $p['regular_price'] && '' === $p['sale_price'] && '' === $p['error'], "price {$label} => unavailable, empty prices (never 0 or markup-only 50,000)" );
}
$p = calc()->calculate_price_pair( 0, 'abc', null, 'product' );
t_check( '' !== $p['error'] && empty( $p['unavailable'] ), 'malformed text stays a fail-closed validation error' );
$p = calc()->calculate_price_pair( 0, '1000000', '0', 'product' );
t_same( '1050000', $p['regular_price'], 'compare 0 is ignored, price 1,000,000 + 50,000 = 1,050,000' );
t_same( '', $p['sale_price'], 'no sale from a zero compare price' );
t_check( Mobo_Core_Price_Calculator::is_unavailable_source_price( ' 0 ' ), 'is_unavailable_source_price(" 0 ")' );
t_check( ! Mobo_Core_Price_Calculator::is_unavailable_source_price( '10' ), '10 is a valid price' );

echo "\nC3 decimal Source prices keep precision (same unit)\n";
t_reset();
Mobo_Core_Connection_Repository::$rows = array( 1 => t_connection_row( 1, 'USD', 2 ) );
$GLOBALS['t_currency'] = 'USD';
$GLOBALS['t_decimals'] = 2;
t_setting( 'mobo_price_type', 'static-percentage' );
t_setting( 'global_additional_percentage', '20' );
$p = calc()->calculate_price_pair( 0, '19.99', null, 'product' );
t_same( '23.988', $p['regular_price'], '19.99 x 1.20 = 23.988 (was 22.8 after intval truncation)' );
t_setting( 'mobo_price_type', 'static-price' );
t_setting( 'global_additional_price', '5' );
$p = calc()->calculate_price_pair( 0, '19.99', null, 'product' );
t_same( '24.99', $p['regular_price'], '19.99 + 5 = 24.99' );
$p = calc()->calculate_price_pair( 0, '19.999', null, 'product' );
t_check( '' !== $p['error'], 'more fraction digits than the money contract (2) is rejected' );

echo "\nB11 rounding through the calculator (sync/reprice/override share format_pair)\n";
t_reset();
t_setting( 'mobo_price_type', 'static-percentage' );
t_setting( 'global_additional_percentage', '15' );
$p = calc()->calculate_price_pair( 0, '1234567', null, 'product' );
t_check( abs( (float) $p['regular_price'] - 1419752.05 ) < 0.000001, 'default none: 1,234,567 x 1.15 = 1,419,752.05 (unchanged behavior)' );
t_setting( 'mobo_core_price_rounding_step', 1000 );
t_setting( 'mobo_core_price_rounding_mode', 'up' );
$p = calc()->calculate_price_pair( 0, '1234567', null, 'product' );
t_same( '1420000', $p['regular_price'], 'step 1000 up => 1,420,000' );
t_setting( 'mobo_core_price_rounding_mode', 'down' );
$p = calc()->calculate_price_pair( 0, '1234567', null, 'product' );
t_same( '1419000', $p['regular_price'], 'step 1000 down => 1,419,000' );
$GLOBALS['t_meta'][501]['mobo_additional_price'] = '200000';
t_setting( 'mobo_core_price_rounding_mode', 'up' );
$p = calc()->calculate_price_pair( 501, '1234567', null, 'variation' );
t_same( '1435000', $p['regular_price'], 'variation fixed override 1,234,567 + 200,000 = 1,434,567 -> 1,435,000' );
t_same( 'object-additional-price', $p['policy_type'], 'override policy type kept' );

echo "\nB12 dynamic rules: validation + fallback instead of selling at cost\n";
t_reset();
$rows = array(
	array( 'is_active' => 'true', 'low' => 0, 'high' => 1000000, 'benefit_type' => 'static', 'benefit' => 50000 ),
	array( 'is_active' => 'false', 'low' => 1000001, 'high' => 5000000, 'benefit_type' => 'percentage', 'benefit' => 10 ),
	array( 'is_active' => 'true', 'low' => 5000001, 'high' => 0, 'benefit_type' => 'percentage', 'benefit' => 5 ),
);
$problems = Mobo_Core_Dynamic_Price_Rules::coverage_problems( $rows );
t_same( 1, count( $problems ), 'one coverage problem (disabled row 2)' );
t_check( false !== strpos( $problems[0], 'ردیف 2 غیرفعال' ), 'Persian error names the disabled row' );
$capped = array( array( 'is_active' => 'true', 'low' => 0, 'high' => 50000000, 'benefit_type' => 'static', 'benefit' => 50000 ) );
$problems = Mobo_Core_Dynamic_Price_Rules::coverage_problems( $capped );
t_check( 1 === count( $problems ) && false !== strpos( $problems[0], 'آخرین ردیف سقف' ), 'capped last row is reported in Persian' );
$valid = array(
	array( 'is_active' => 'true', 'low' => 0, 'high' => 1000000, 'benefit_type' => 'static', 'benefit' => 50000 ),
	array( 'is_active' => 'true', 'low' => 1000001, 'high' => 0, 'benefit_type' => 'percentage', 'benefit' => 10 ),
);
t_same( array(), Mobo_Core_Dynamic_Price_Rules::coverage_problems( $valid ), 'complete coverage => no errors' );
t_check( ! empty( Mobo_Core_Dynamic_Price_Rules::coverage_problems( array() ) ), 'empty rule set is rejected' );
t_setting( 'mobo_price_type', 'dynamic-price' );
t_setting( 'mobo_dynamic_price', json_encode( $rows ) );
$p = calc()->calculate_price_pair( 0, '500000', null, 'product' );
t_same( '550000', $p['regular_price'], 'in-range 500,000 + 50,000 = 550,000' );
t_same( 'dynamic-price', $p['policy_type'], 'matched rule policy type' );
$p = calc()->calculate_price_pair( 0, '2000000', null, 'product' );
t_same( '2050000', $p['regular_price'], 'disabled range 2,000,000 uses nearest active rule (+50,000), not cost' );
t_same( 'dynamic-price-fallback', $p['policy_type'], 'fallback is visible in policy type' );
t_setting( 'mobo_dynamic_price', json_encode( $capped ) );
$p = calc()->calculate_price_pair( 0, '60000000', null, 'product' );
t_same( '60050000', $p['regular_price'], 'above the last cap 60,000,000 still gets +50,000' );

echo "\nC2 IRR Source -> IRT store with rounding up\n";
$r = Mobo_Core_Money_Converter::convert_rounded( '1234565', 'IRR', 'IRT', 0, 0, 'up' );
t_same( '123457', $r['amount'], '1,234,565 IRR -> 123,457 IRT (up; was rejected and stale price kept)' );
t_check( ! empty( $r['rounded'] ), 'rounded flag set' );
t_same( '123457', Mobo_Core_Money_Converter::convert_rounded( '1234565', 'IRR', 'IRT', 0, 0, 'half_up' )['amount'], 'half_up 123,456.5 -> 123,457' );
t_same( '123456', Mobo_Core_Money_Converter::convert_rounded( '1234564', 'IRR', 'IRT', 0, 0, 'half_up' )['amount'], 'half_up 123,456.4 -> 123,456' );
t_same( '123456', Mobo_Core_Money_Converter::convert_rounded( '1234569', 'IRR', 'IRT', 0, 0, 'down' )['amount'], 'down 123,456.9 -> 123,456' );
$r = Mobo_Core_Money_Converter::convert_rounded( '1234560', 'IRR', 'IRT', 0, 0, 'up' );
t_check( '123456' === $r['amount'] && empty( $r['rounded'] ), 'exact multiple of 10 is not rounded' );
t_same( '123456.5', Mobo_Core_Money_Converter::convert_rounded( '1234565', 'IRR', 'IRT', 0, 2, 'up' )['amount'], 'store with 2 decimals keeps 123,456.5 exactly' );
t_same( '1234560', Mobo_Core_Money_Converter::convert_rounded( '123456', 'IRT', 'IRR', 0, 0, 'up' )['amount'], 'IRT -> IRR 123,456 x 10 = 1,234,560' );
t_same( '100000', Mobo_Core_Money_Converter::convert_rounded( '999991', 'IRR', 'IRT', 0, 0, 'up' )['amount'], 'carry: 99,999.1 -> 100,000' );
t_check( empty( Mobo_Core_Money_Converter::convert_exact( '1234565', 'IRR', 'IRT', 0, 0 )['ok'] ), 'convert_exact still rejects inexact (shipping/purchase unchanged)' );

t_reset();
Mobo_Core_Connection_Repository::$rows = array( 1 => t_connection_row( 1, 'IRR' ) );
$GLOBALS['t_currency'] = 'IRT';
update_option( Mobo_Core_Money_Target_Settings::OPTION, 'store' );
t_setting( 'mobo_price_type', 'static-price' );
t_setting( 'global_additional_price', '0' );
$p = calc()->calculate_price_pair( 0, '1234565', null, 'product' );
t_same( '123457', $p['regular_price'], 'calculator: 1,234,565 IRR -> 123,457 IRT storefront price' );
t_check( ! empty( $p['money_converted_once'] ), 'converted once before markup' );
$p = calc()->calculate_price_pair( 0, '1234565', '1500005', 'product' );
t_same( '150001', $p['regular_price'], 'compare 1,500,005 IRR -> 150,001 IRT regular' );
t_same( '123457', $p['sale_price'], 'price -> 123,457 IRT sale' );
$p = calc()->calculate_price_pair( 0, '0', null, 'product' );
t_check( ! empty( $p['unavailable'] ), 'zero IRR price in conversion path => unavailable' );

echo "\nC1 store currency changed after sync (IRT -> IRR)\n";
t_reset();
Mobo_Core_Connection_Repository::$rows = array( 1 => t_connection_row( 1, 'IRT' ) );
$GLOBALS['t_currency'] = 'IRR';
t_setting( 'mobo_price_type', 'static-price' );
t_setting( 'global_additional_price', '0' );
$p = calc()->calculate_price_pair( 0, '12000000', null, 'product' );
t_check( '' !== $p['error'], 'no preference and no detected change => fail-closed (historical behavior)' );
$event = Mobo_Core_Store_Currency_Guard::evaluate_change( 'IRT', 'IRR', null, 1000 );
t_same( 10.0, $event['factor'], 'IRT -> IRR factor x10' );
t_check( ! empty( $event['supported'] ), 'pair supported' );
$message = Mobo_Core_Store_Currency_Guard::message_fa( $event );
t_check( false !== strpos( $message, '۱۰ برابر ارزان‌تر' ), 'Persian notice explains prices are now 10x too cheap' );
t_check( false !== strpos( $message, 'Reprice' ), 'notice tells the admin to run Reprice' );
update_option( Mobo_Core_Store_Currency_Guard::OPTION_EVENT, $event );
t_check( Mobo_Core_Store_Currency_Guard::implicit_follow_store_allowed(), 'pending change allows following the Woo currency' );
$p = calc()->calculate_price_pair( 0, '12000000', null, 'product' );
t_same( '120000000', $p['regular_price'], 'Reprice now corrects 12,000,000 IRT -> 120,000,000 IRR' );
t_same( null, Mobo_Core_Store_Currency_Guard::evaluate_change( 'IRT', 'IRR', $event, 2000 ), 'same change is not re-recorded' );
$back = Mobo_Core_Store_Currency_Guard::evaluate_change( 'IRR', 'IRT', $event, 3000 );
t_check( ! empty( $back['resolved'] ), 'switching back to the priced unit resolves the event' );
t_same( 0.1, Mobo_Core_Store_Currency_Guard::factor( 'IRR', 'IRT' ), 'IRR -> IRT factor x0.1' );
$odd = Mobo_Core_Store_Currency_Guard::evaluate_change( 'IRT', 'USD', null, 1000 );
t_check( empty( $odd['supported'] ), 'IRT -> USD is reported as unsupported' );
t_same( null, Mobo_Core_Store_Currency_Guard::evaluate_change( 'TOMAN', 'IRT', null, 1000 ), 'TOMAN == IRT is not a change' );

echo "\nC4 revenue cost converted to the order currency\n";
$cost = Mobo_Core_Revenue_Ledger::convert_cost_to_order_currency( 10000000, 'IRR', 'IRT' );
t_same( 1000000.0, $cost, 'cost 10,000,000 IRR -> 1,000,000 IRT' );
$profit = 1200000 - $cost;
t_same( 200000.0, $profit, 'sale 1,200,000 IRT => profit 200,000 (was -8,800,000)' );
t_same( '16.67', number_format( $profit / 1200000 * 100, 2 ), 'margin 16.67% (was -733%)' );
t_same( 10000000.0, Mobo_Core_Revenue_Ledger::convert_cost_to_order_currency( 1000000, 'IRT', 'IRR' ), 'IRT -> IRR x10' );
t_same( 500.0, Mobo_Core_Revenue_Ledger::convert_cost_to_order_currency( 500, 'TOMAN', 'IRT' ), 'same unit unchanged' );
t_same( 500.0, Mobo_Core_Revenue_Ledger::convert_cost_to_order_currency( 500, '', 'IRT' ), 'unknown legacy unit unchanged' );
t_same( null, Mobo_Core_Revenue_Ledger::convert_cost_to_order_currency( 500, 'IRR', 'USD' ), 'unsupported pair => null (report error, not a wrong number)' );

echo "\nB4 Persian / percent-encoded exclusion URLs\n";
t_reset();
$encoded = 'https://source.ir/product/%DA%AF%D9%88%D8%B4%DB%8C-a12/';
$raw = '/product/گوشی-a12';
t_same( '/product/گوشی-a12', Mobo_Core_Product_Exclusions::normalize_url( $encoded ), 'encoded URL decodes to the Persian path (octets no longer stripped)' );
t_same( Mobo_Core_Product_Exclusions::normalize_url( $encoded ), Mobo_Core_Product_Exclusions::normalize_url( $raw ), 'encoded and raw Unicode spellings compare equal' );
t_same( Mobo_Core_Product_Exclusions::normalize_url( $encoded ), Mobo_Core_Product_Exclusions::normalize_url( strtolower( $encoded ) ), 'hex case does not matter' );
t_check( '/product/-a12' !== Mobo_Core_Product_Exclusions::normalize_url( $encoded ), 'no collapse to /product/-a12 (would exclude unrelated products)' );
$parsed = Mobo_Core_Product_Exclusions::parse_input( $encoded . "\n" . $raw . "\n/product/other" );
t_same( 2, count( $parsed['paths'] ), 'duplicate spellings collapse to one entry' );
t_setting( 'mobo_core_excluded_product_urls', $parsed['canonical'] );
t_check( Mobo_Core_Product_Exclusions::is_url_excluded( '/product/%da%af%d9%88%d8%b4%db%8c-a12?utm=1' ), 'payload with encoded URL + query is excluded' );
t_check( ! Mobo_Core_Product_Exclusions::is_url_excluded( '/product/-a12' ), 'stripped legacy form no longer matches the Persian entry' );
t_same( '/product/%DA%AFx', Mobo_Core_Product_Exclusions::sanitize_source_url( " /product/<b>%DA%AFx</b>\n" ), 'tags/control chars removed, %XX octets kept' );
t_same( array( '/product/-a12' ), Mobo_Core_Product_Exclusions::suspicious_paths( array( '/product/-a12', '/product/ok-1' ) ), 'damaged legacy entry is flagged for the admin' );
$GLOBALS['t_posts'][900] = new WP_Post( array( 'ID' => 900, 'post_type' => 'product', 'post_name' => '%da%af%d9%88%d8%b4%db%8c-a12' ) );
t_check( Mobo_Core_Product_Exclusions::local_slug_matches_exclusion( 900 ), 'local WooCommerce slug (stored encoded) matches the decoded entry' );
$GLOBALS['t_posts'][901] = new WP_Post( array( 'ID' => 901, 'post_type' => 'product', 'post_name' => 'a12' ) );
t_check( ! Mobo_Core_Product_Exclusions::local_slug_matches_exclusion( 901 ), 'different slug is not excluded' );
t_check( false !== strpos( Mobo_Core_Product_Exclusions::exclusion_warning_fa(), 'منجمد' ), 'B23 warning explains price/stock are frozen' );

echo "\nB1 second Connection: displayed defaults == effective values\n";
t_reset();
Mobo_Core_Connection_Repository::$rows[2] = t_connection_row( 2, 'IRT' );
Mobo_Core_Connection_Context::$id = 2;
// Rows created by the old Connection creation: only title/slug.
t_setting( 'global_product_auto_title', '0', 2 );
t_setting( 'global_product_auto_slug', '0', 2 );
t_same( '0', (string) Mobo_Core_Settings::get( 'global_product_auto_stock', '0' ), 'old bool_field read showed stock updates OFF (the bug)' );
$rules = new Mobo_Core_Legacy_Rules();
t_check( $rules->should_update_stock() && $rules->should_update_price() && $rules->should_update_images(), 'runtime really uses ON for stock/price/images' );
$product_keys = array( 'global_product_auto_stock', 'global_product_auto_price', 'global_product_auto_title', 'global_product_auto_slug', 'mobo_core_only_in_stock', 'global_product_auto_compare_price', 'global_update_images', 'global_product_auto_description' );
$expected = array( '1', '1', '0', '0', '0', '1', '1', '0' );
$shown = array();
foreach ( $product_keys as $key ) {
	$shown[] = Mobo_Core_Admin::effective_bool_value( $key );
}
t_same( $expected, $shown, 'product tab now displays the effective values' );
t_same( '1', Mobo_Core_Admin::effective_bool_value( 'mobo_core_category_mapping_enabled' ), 'category mapping shown ON (default)' );
t_same( '1', Mobo_Core_Admin::effective_bool_value( 'mobo_core_mobo_order_auto_complete_enabled' ), 'order auto-complete shown ON (default)' );
// Save the tab without changes: POST carries exactly what was displayed.
foreach ( $product_keys as $index => $key ) {
	Mobo_Core_Settings::update( $key, $shown[ $index ] );
}
$after = new Mobo_Core_Legacy_Rules();
t_check(
	$after->should_update_stock() === $rules->should_update_stock()
	&& $after->should_update_price() === $rules->should_update_price()
	&& $after->should_update_compare_price() === $rules->should_update_compare_price()
	&& $after->should_update_images() === $rules->should_update_images()
	&& $after->should_update_title() === $rules->should_update_title(),
	'saving without changes does not change runtime behavior'
);
Mobo_Core_Connection_Settings::seed_policy_defaults( 3, array( 'global_product_auto_title' => '0', 'global_product_auto_slug' => '0' ) );
$seeded = array();
foreach ( array( 'global_product_auto_stock', 'global_product_auto_price', 'global_product_auto_compare_price', 'global_update_images', 'global_update_categories', 'mobo_core_category_mapping_enabled', 'global_product_auto_title', 'global_product_auto_slug', 'mobo_price_type' ) as $key ) {
	$seeded[ $key ] = Mobo_Core_Connection_Settings::get( 3, $key, 'MISSING' );
}
t_same( array( 'global_product_auto_stock' => '1', 'global_product_auto_price' => '1', 'global_product_auto_compare_price' => '1', 'global_update_images' => '1', 'global_update_categories' => '1', 'mobo_core_category_mapping_enabled' => '1', 'global_product_auto_title' => '0', 'global_product_auto_slug' => '0', 'mobo_price_type' => 'static-price' ), $seeded, 'new Connection seeds every policy key explicitly' );
t_setting( 'global_product_auto_stock', '0', 4 );
Mobo_Core_Connection_Settings::seed_policy_defaults( 4 );
t_same( '0', Mobo_Core_Connection_Settings::get( 4, 'global_product_auto_stock' ), 'seeding never overwrites an existing Connection choice' );

echo "
Admin verified-save read-back (unchanged re-save of a wp_options int)
";
t_check( Mobo_Core_Admin::admin_setting_read_back_matches( '8', 8 ), 'DB string "8" matches posted int 8 (unchanged re-save is not a failure)' );
t_check( Mobo_Core_Admin::admin_setting_read_back_matches( '0', '0' ) && Mobo_Core_Admin::admin_setting_read_back_matches( 0, '0' ), 'bool-like "0" matches' );
t_check( ! Mobo_Core_Admin::admin_setting_read_back_matches( '7', 8 ), 'a different stored value is still a failure' );
t_check( ! Mobo_Core_Admin::admin_setting_read_back_matches( null, 8 ), 'missing read-back is a failure' );
t_check( Mobo_Core_Admin::admin_setting_read_back_matches( array( 'a' => 1 ), array( 'a' => 1 ) ) && ! Mobo_Core_Admin::admin_setting_read_back_matches( array( 'a' => '1' ), array( 'a' => 2 ) ), 'arrays keep serialized comparison' );

t_done();
