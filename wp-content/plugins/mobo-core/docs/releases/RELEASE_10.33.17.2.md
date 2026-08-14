# Mobo Core 10.33.17.2 — Wallet SMS Reliability & Diagnostics

## Scope

This release hardens the integration between Mobo wallet/order notifications and Persian WooCommerce SMS without implementing or bypassing any SMS gateway itself.

## Changes

- Normalize Persian/Arabic mobile digits and common Iranian `+98`, `0098`, `98` and bare `9xxxxxxxxx` prefixes before PWSMS validation.
- Catch `Throwable` around PWSMS bootstrap, shortcode rendering, mobile validation and gateway dispatch so a third-party gateway failure cannot break Mobo checkout/order hooks.
- Preserve gateway failures as bounded `WP_Error` diagnostics and support explicit structured success results without treating arbitrary non-empty strings as success.
- Add an administrator-only wallet SMS transport test. The test uses the saved wallet recipient/template, does not modify wallet balance, and never consumes/resets the one-shot low-balance notification flag.
- Persist last SMS attempt, last successful dispatch, transport result and bounded transport error separately from Mobo wallet-balance API errors.
- Display transport diagnostics in both the SMS tab and the Mobo wallet dashboard card.

## Trigger semantics unchanged

Automatic wallet alerts still run only after a successful Mobo order submission/payment, when the fetched wallet balance is at or below the configured threshold, and while the one-shot reminder is armed.

## Database / Portal

No database schema migration and no Portal change are required.
