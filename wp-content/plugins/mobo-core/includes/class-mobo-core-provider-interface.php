<?php
/**
 * Internal remote-commerce provider contract.
 *
 * Providers are implementation details of Mobo Core. Site administrators select
 * a Source/Connection; the Source Registry binds that source to one hard-coded
 * provider adapter. There is intentionally no customer-editable provider field.
 *
 * Cart mutation is intentionally replace-only. The contract exposes no
 * update_cart_item() or delete_cart_item() operation: checkout/order flows clear
 * the remote cart and rebuild the desired state from WooCommerce.
 *
 * PHP 7.4 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Mobo_Core_Provider_Interface {
	/** Stable internal provider key, e.g. portal.ir. @return string */
	public function get_key();

	/** Cart synchronization strategy. Current supported strategy: replace. @return string */
	public function get_cart_sync_strategy();

	/** Provider session/authentication cookie name. @return string */
	public function get_auth_cookie_name();

	/**
	 * Resolve a provider-specific protocol endpoint for one abstract operation.
	 *
	 * Supported operations are provider-internal protocol capabilities such as
	 * auth_preflight, auth_login, wallet_balance, cart_snapshot, cart_add,
	 * cart_clear_item, cart_checkout, cart_details, cart_shippings,
	 * cart_shipping and cart_wallet_payment.
	 *
	 * @param string $operation Abstract operation.
	 * @param array  $args Operation arguments.
	 * @return string
	 */
	public function get_endpoint( $operation, $args = array() );

	/** Build provider-specific sign-in request payload. @return array */
	public function build_login_payload( $username, $password );

	/** Build provider-specific add-to-cart payload. @return array */
	public function build_cart_add_payload( $variant_id, $quantity );

	/**
	 * Build provider-specific checkout location fields from opaque external IDs.
	 * Core never assumes numeric country/state/city identifiers.
	 *
	 * @param array $resolved Central resolver result.
	 * @return array|WP_Error
	 */
	public function build_order_location_payload( $resolved );

	/**
	 * Interpret provider order-details payload for positive reconciliation only.
	 *
	 * This method must never claim that a remote order/payment is absent merely
	 * because a field is missing or a response is incomplete. Core uses it to
	 * recover known remote success without replaying an ambiguous financial POST.
	 *
	 * @param array $payload Provider details response JSON.
	 * @param int   $expected_order_id Optional known remote order ID.
	 * @return array
	 */
	public function interpret_order_reconciliation_details( $payload, $expected_order_id = 0 );

	/**
	 * Map the generic order contact (sender store + recipient) onto the
	 * provider's checkout body fields. Field naming is platform knowledge and
	 * must never live in core checkout orchestration.
	 *
	 * @param array $contact Generic contact: senderName, senderMobile, recipientName,
	 *                       recipientMobile, zipcode, address, latitude, longitude.
	 * @return array Field => value merged into the provider checkout body.
	 */
	public function build_order_contact_payload( $contact );

	/**
	 * Build the body for a post-checkout stage (shipping selection, payment).
	 *
	 * @param array    $details     Provider cart details response.
	 * @param int      $shipping_id Selected provider shipping method id.
	 * @param string   $mode        Payment mode, e.g. wallet.
	 * @param int|null $gateway_id  Optional payment gateway id.
	 * @return array
	 */
	public function build_order_stage_payload( $details, $shipping_id, $mode, $gateway_id );
}
