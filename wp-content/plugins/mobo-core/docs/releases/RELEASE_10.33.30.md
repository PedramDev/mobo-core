# Mobo Core 10.33.30 — Purchase / Cart Safety Hardening

This release hardens the WooCommerce → Mobo purchase boundary without changing the 10.33.29 parent-product retention/recovery policy.

## Core invariants

1. A WooCommerce order line freezes whether it is Mobo-owned and its product/variant/Portal identities before payment.
2. The shared Mobo cart must exactly equal the Mobo subset of the Woo order before checkout/payment. Unknown, malformed, stale, extra, or wrong-quantity rows fail closed.
3. Immediately before Wallet Payment, the live Woo order must still match the preflight business fingerprint (the complete Woo line-item structure, Mobo subset/quantities, financial/payment state, recipient/address/location, shipping lines/mapping, status, sender, and Mobo account config).
4. Wallet Payment is never automatically replayed. Any non-definitive acknowledgement after the irreversible POST becomes `uncertain`; only explicitly recognized `paid=false` is treated as definitive unpaid.
5. Once Mobo success is durably committed, later Woo/order mutations, status races, or post-success hook exceptions cannot trigger a second purchase; divergence is sticky and requires review.
6. Remote identifiers used at the purchase boundary are parsed strictly. Mixed strings, scientific notation, fractional IDs, and overflow values cannot be coerced into valid Cart/Variant/Shipping/Mobo Order IDs.
7. A legacy Woo order with an uncaptured line whose catalogue object was already deleted has unknown ownership and is blocked from partial Mobo purchase instead of being guessed.
8. Turning Auto Order off while a worker is already running is honored before every queued order and again immediately before Wallet; no new Wallet call starts after the switch is observed. Deferred orders resume promptly when the feature is re-enabled.
9. Fee, coupon, and tax line structure is part of the final business fingerprint, so offsetting edits that leave the grand total unchanged still force a fresh preflight.
10. A malformed authoritative shipping refresh cannot weaken destination restrictions: invalid remote IDs, nested status data, malformed location collections/rules, or non-finite numeric bounds reject the whole refresh and preserve the last known-good snapshot.
11. Opaque Mobo cart tokens and legacy cart-row maps are schema-checked before use; arrays/objects/control characters and permissively coercible remote identifiers fail closed.

## Checkout compatibility

- Classic checkout keeps authoritative preflight through `woocommerce_after_checkout_validation`.
- Checkout Blocks / Store API freezes order identity and performs the same Mobo preflight at `woocommerce_store_api_checkout_update_order_meta`, before payment.
- Store API Revenue source-cost snapshots are also taken before payment.
- When automatic Mobo purchase is enabled, third-party validation filters may add errors but cannot erase mandatory core Mobo/cart safety errors.

## Shipping

A Mobo purchase receives exactly one `shipping_id`. If a Woo order has multiple distinct real shipping methods/packages, submission stops with an actionable mapping error rather than selecting an arbitrary first method. Partial shipping addresses use billing consistently. Authoritative shipping snapshots are replacement-safe: malformed IDs/restrictions/rules cannot silently broaden eligibility or overwrite the last known-good snapshot.

## Payment / reconciliation

The Mobo order ID must be present and strictly valid before Wallet starts. `paid`, `success`, and other post-Wallet acknowledgements are not permissively coerced: null/pending/garbage values stay uncertain. The final guard also detects non-Mobo line changes, totals/refunds/payment-method changes, shipping cost changes, fee/coupon/tax structure changes, and account/config changes while an order is in flight. Losing the local per-order/shared-cart lease before the Wallet HTTP request is classified as a safe pre-request failure; once the Wallet request may have left the process, ambiguous outcomes remain `uncertain` and are never automatically replayed.

## Logging / privacy

Credential keys, customer contact/address fields, Mobo path query strings, and raw non-JSON response bodies are not persisted in copyable support logs.

## Database

No schema migration is required for 10.33.30.
