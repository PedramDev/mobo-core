<?php
/**
 * Portal.ir storefront adapter.
 *
 * One or more provisioned Sources may be backed by this provider. Endpoint/payload knowledge
 * lives here instead of being selected by an administrator or hard-coded across
 * checkout orchestration.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mobo_Core_Provider_Portal implements Mobo_Core_Provider_Interface {
	const KEY = 'portal.ir';
	const CART_SYNC_REPLACE = 'replace';

	public function get_key() { return self::KEY; }

	public function get_cart_sync_strategy() { return self::CART_SYNC_REPLACE; }

	public function get_auth_cookie_name() { return 'userauth'; }

	public function get_endpoint( $operation, $args = array() ) {
		$operation = sanitize_key( (string) $operation );
		$args = is_array( $args ) ? $args : array();

		switch ( $operation ) {
			case 'auth_preflight':
				return '/site/signin';
			case 'auth_login':
				return '/site/api/v1/user/signin';
			case 'cart_page':
				return '/site/cart';
			case 'wallet_balance':
				return '/site/api/v1/user/billing/transactions/balance';
			case 'cart_snapshot':
				return '/site/api/v1/cart?update=' . ( ! empty( $args['update'] ) ? 'true' : 'false' );
			case 'cart_add':
				return '/site/api/v1/cart';
			case 'cart_clear_item':
				$id = absint( $args['cart_item_id'] ?? 0 );
				return $id > 0 ? '/site/api/v1/cart/' . rawurlencode( (string) $id ) : '';
			case 'cart_checkout':
				return '/site/api/v1/cart/checkout';
			case 'cart_details':
				$token = isset( $args['token'] ) ? (string) $args['token'] : '';
				return '' !== $token ? '/site/api/v1/cart/details?token=' . rawurlencode( $token ) : '';
			case 'cart_shippings':
				$token = isset( $args['token'] ) ? (string) $args['token'] : '';
				return '' !== $token ? '/site/api/v1/cart/shippings?token=' . rawurlencode( $token ) : '';
			case 'cart_shipping':
				return '/site/api/v1/cart/shipping';
			case 'cart_wallet_payment':
				return '/site/api/v1/cart/payment/wallet';
		}

		return '';
	}

	public function build_login_payload( $username, $password ) {
		return array(
			'return_url' => '',
			'username'   => (string) $username,
			'password'   => (string) $password,
		);
	}

	public function build_cart_add_payload( $variant_id, $quantity ) {
		$variant_id = absint( $variant_id );
		return array(
			'quantity'   => max( 0, (float) $quantity ),
			'variant_id' => $variant_id,
		);
	}


	private function parse_positive_id( $value ) {
		if ( is_int( $value ) ) { return $value > 0 ? $value : 0; }
		if ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', trim( $value ) ) ) {
			$number = (int) trim( $value );
			return $number > 0 ? $number : 0;
		}
		return 0;
	}

	private function parse_explicit_bool( $value ) {
		if ( is_bool( $value ) ) { return array( 'known' => true, 'value' => $value ); }
		if ( 0 === $value || 1 === $value ) { return array( 'known' => true, 'value' => 1 === $value ); }
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( 'true' === $normalized || '1' === $normalized ) { return array( 'known' => true, 'value' => true ); }
			if ( 'false' === $normalized || '0' === $normalized ) { return array( 'known' => true, 'value' => false ); }
		}
		return array( 'known' => false, 'value' => false );
	}

	public function interpret_order_reconciliation_details( $payload, $expected_order_id = 0 ) {
		$payload = is_array( $payload ) ? $payload : array();
		$details = isset( $payload['details'] ) && is_array( $payload['details'] ) ? $payload['details'] : $payload;

		$order_id = array_key_exists( 'id', $details ) ? $this->parse_positive_id( $details['id'] ) : 0;
		$expected_order_id = absint( $expected_order_id );
		$id_matches = $order_id > 0 && ( $expected_order_id <= 0 || $order_id === $expected_order_id );

		$paid = array( 'known' => false, 'value' => false );
		if ( array_key_exists( 'paid', $details ) ) {
			$paid = $this->parse_explicit_bool( $details['paid'] );
		} elseif ( array_key_exists( 'paid', $payload ) ) {
			$paid = $this->parse_explicit_bool( $payload['paid'] );
		}

		$shipping_id = 0;
		foreach ( array( 'shipping_id', 'shippingId' ) as $key ) {
			if ( array_key_exists( $key, $details ) ) {
				$shipping_id = $this->parse_positive_id( $details[ $key ] );
				break;
			}
		}

		return array(
			'remoteOrderPresent' => $order_id > 0,
			'remoteOrderId'      => $order_id,
			'idMatches'          => $id_matches,
			'paidKnown'          => ! empty( $paid['known'] ),
			'paid'               => ! empty( $paid['known'] ) && ! empty( $paid['value'] ),
			'shippingId'         => $shipping_id,
		);
	}

	public function build_order_location_payload( $resolved ) {
		$resolved = is_array( $resolved ) ? $resolved : array();
		$country_id = absint( $resolved['countryExternalId'] ?? 0 );
		$state_id   = absint( $resolved['stateExternalId'] ?? 0 );
		$city_id    = absint( $resolved['cityExternalId'] ?? 0 );
		if ( $country_id <= 0 || $state_id <= 0 || $city_id <= 0 ) {
			return new WP_Error( 'mobo_core_portal_location_identity_invalid', 'Portal.ir requires numeric country/state/city external IDs.' );
		}
		return array(
			'country_id' => $country_id,
			'state_id'   => $state_id,
			'city_id'    => $city_id,
		);
	}

	/**
	 * Portal.ir dropship checkout body (kept byte-identical to every release since
	 * the first one; proven against the live Source): name/mobile carry the SENDER
	 * (store), phone carries the RECIPIENT NAME and email carries the RECIPIENT
	 * MOBILE. This is intentional Portal.ir contract knowledge - never "fix" it and
	 * never copy it into another provider.
	 */
	public function build_order_contact_payload( $contact ) {
		$contact = is_array( $contact ) ? $contact : array();
		$recipient_name   = isset( $contact['recipientName'] ) ? $contact['recipientName'] : ( $contact['billingName'] ?? '' );
		$recipient_mobile = isset( $contact['recipientMobile'] ) ? $contact['recipientMobile'] : ( $contact['billingMobile'] ?? '' );
		return array(
			'description' => '',
			'name'        => $contact['senderName'] ?? '',
			'mobile'      => $contact['senderMobile'] ?? '',
			'phone'       => $recipient_name,
			'email'       => $recipient_mobile,
			'country'     => null,
			'state'       => null,
			'city'        => null,
			'zipcode'     => $contact['zipcode'] ?? '',
			'address'     => $contact['address'] ?? '',
			'latitude'    => $contact['latitude'] ?? null,
			'longitude'   => $contact['longitude'] ?? null,
		);
	}

	public function build_order_stage_payload( $details, $shipping_id, $mode, $gateway_id ) {
		$payload = is_array( $details ) ? $details : array();
		$payload['shipping_id'] = absint( $shipping_id );
		$payload['mode']        = sanitize_key( (string) $mode );
		$payload['gateway_id']  = null === $gateway_id ? null : absint( $gateway_id );
		return $payload;
	}
}
