# Mobo Core 10.33.41 — Checkout shared-cart incident hotfix

## Incident

Stores with automatic Mobo order submission enabled could be blocked at WooCommerce Checkout by the message «آماده‌سازی سبد موبو برای بررسی سفارش انجام نشد», even when the merchant had left the optional Mobo cart validation setting disabled.

The cause was policy coupling: Auto Order implicitly forced the shared remote Mobo cart preflight into customer Checkout. That made transient remote cart/session/consistency failures a pre-payment blocker. It also unnecessarily increased contention around the shared Mobo cart.

## Fix

- Pre-payment Mobo cart validation is again controlled only by the Checkout validation master switch plus the dedicated Mobo-cart validation toggle.
- Auto Order no longer implicitly enables that optional Checkout mutation.
- Automatic Mobo order submission still performs the mandatory authenticated clear/rebuild/compare immediately before the Mobo order is created. No financial/order-submission safety check was removed.
- Admin status/help text now reflects the actual separation between optional Checkout validation and mandatory order-submission preflight.

## Database / UI

No schema migration and no new control are required. Existing settings are preserved.
