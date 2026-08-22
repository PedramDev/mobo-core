# Mobo Core 10.33.42 — Empty shared-cart snapshot compatibility hotfix

## Incident
Checkout validation could fail with `Mobo cart snapshot did not contain a valid cart object` even though the Mobo cart endpoint returned HTTP 200 and diagnostics showed `snapshot_success` with `itemCount: 0`.

## Root cause
The storefront API may represent an explicitly empty cart as `cart: null` (and equivalent explicit empty forms), while MoboCore required `cart` to always be an array containing `items`. The snapshot logger/map code already treated that response as empty, but the strict parser rejected it afterwards.

## Fix
Explicit empty cart forms are normalized to `cart.items = []`. Missing `cart` fields and malformed non-empty payloads still fail closed.
