<?php
/**
 * Standalone tests: provider adapter contract (Provider = platform, e.g. portal.ir).
 * Pins the Portal.ir checkout body mapping that has been proven on the live
 * Source since the first release: sender in name/mobile, recipient NAME in
 * `phone`, recipient MOBILE in `email`.
 *
 * Run: php tests-dev/provider-contract-test.php
 */

require __DIR__ . '/stubs.php';
$inc = dirname( __DIR__ ) . '/wp-content/plugins/mobo-core/includes/';
require $inc . 'class-mobo-core-provider-interface.php';
require $inc . 'class-mobo-core-provider-portal.php';
require $inc . 'class-mobo-core-provider-registry.php';

echo "registry\n";
$p = Mobo_Core_Provider_Registry::get( ' Portal.IR ' );
mobo_assert( $p instanceof Mobo_Core_Provider_Portal, 'portal.ir resolves to the Portal adapter (case/space-insensitive)' );
mobo_assert( null === Mobo_Core_Provider_Registry::get( 'unknown.example' ), 'unknown provider resolves to null' );

echo "portal contact mapping\n";
$contact = array(
	'senderName' => 'Store', 'senderMobile' => '09120000000',
	'billingName' => 'Recipient Name', 'billingMobile' => '09351111111',
	'recipientName' => 'Recipient Name', 'recipientMobile' => '09351111111',
	'zipcode' => '1234567890', 'address' => 'Street 1', 'latitude' => 35.7, 'longitude' => 51.4,
);
$body = $p->build_order_contact_payload( $contact );
mobo_assert_same( 'Store', $body['name'], 'name = sender name' );
mobo_assert_same( '09120000000', $body['mobile'], 'mobile = sender mobile' );
mobo_assert_same( 'Recipient Name', $body['phone'], 'phone = recipient NAME (intentional Portal contract)' );
mobo_assert_same( '09351111111', $body['email'], 'email = recipient MOBILE (intentional Portal contract)' );
mobo_assert_same( '', $body['description'], 'description empty' );
mobo_assert( null === $body['country'] && null === $body['state'] && null === $body['city'], 'country/state/city null (ids come from location payload)' );
mobo_assert_same( array( 'description', 'name', 'mobile', 'phone', 'email', 'country', 'state', 'city', 'zipcode', 'address', 'latitude', 'longitude' ), array_keys( $body ), 'field order identical to 10.33.67' );

$legacy = $contact; unset( $legacy['recipientName'], $legacy['recipientMobile'] );
$body = $p->build_order_contact_payload( $legacy );
mobo_assert_same( 'Recipient Name', $body['phone'], 'legacy snapshot without recipient* falls back to billingName' );
mobo_assert_same( '09351111111', $body['email'], 'legacy snapshot falls back to billingMobile' );

echo "portal location + stage\n";
$loc = $p->build_order_location_payload( array( 'countryExternalId' => '1', 'stateExternalId' => '8', 'cityExternalId' => '301' ) );
mobo_assert_same( array( 'country_id' => 1, 'state_id' => 8, 'city_id' => 301 ), $loc, 'numeric external ids mapped' );
mobo_assert( is_wp_error( $p->build_order_location_payload( array( 'countryExternalId' => '1' ) ) ), 'missing ids rejected' );
$stage = $p->build_order_stage_payload( array( 'token' => 't' ), '17', 'Wallet', null );
mobo_assert_same( array( 'token' => 't', 'shipping_id' => 17, 'mode' => 'wallet', 'gateway_id' => null ), $stage, 'stage payload' );

mobo_finish();
