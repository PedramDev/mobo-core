# Mobo Core 10.32.6 — One-click WooCommerce Shipping Setup

## Summary

This release turns the complete Mobo shipping contract introduced in 10.32.5 into a native WooCommerce shipping runtime and a repeatable one-click installer.

The store manager no longer needs to recreate every Mobo rule manually or install a separate table-rate extension.

## One-click installer

The Checkout and Shipping settings page now contains **ساخت و ترمیم خودکار حمل‌ونقل موبو**.

A successful run:

1. synchronizes the latest active shipping methods from Portal;
2. creates or reuses the `محصولات موبو` WooCommerce shipping class;
3. finds WooCommerce zones that cover Iran, or creates a safe Iran fallback zone;
4. creates one real WooCommerce method instance per active Mobo shipping method in every relevant zone;
5. saves fixed `shipping_id` mappings for Mobo-only and mixed orders;
6. enables the shipping-only Mobo price context;
7. disables stale Mobo-managed instances whose source method is no longer active;
8. preserves unrelated WooCommerce zones and methods.

The installer is idempotent. Running it again repairs existing managed instances rather than creating duplicates.

## Native rate calculation

The new `mobo_core_shipping` WooCommerce method supports:

- `free` methods;
- `static` methods;
- `rules` methods with subtotal and weight ranges;
- method-level minimum and maximum subtotal/weight bounds;
- minimum, maximum, and rounded cost constraints;
- country, state, and city restrictions;
- Tehran-only title hints when the source has no explicit locality;
- source Toman to WooCommerce Rial conversion.

Only Mobo cart lines are included in the Mobo subtotal:

```text
mobo_api_price × quantity
```

The real WooCommerce product, cart item, discount, checkout total, payment amount, and order item values are not modified.

## Safe defaults

Operational methods that can expose an unsafe public checkout choice are created disabled for store-manager review. This includes previous-invoice merging, warehouse holding, and in-person pickup methods.

Methods that are suspended or non-approved in Portal are not created as active checkout methods. Existing Mobo-managed instances for removed methods are disabled, not deleted.

WooCommerce COD is never enabled automatically. When COD already has a non-empty shipping-method restriction list, supported Mobo instance IDs are merged into that list.

## Checkout and order compatibility

Each source method is represented by one normal WooCommerce shipping-method instance and a fixed rate ID in this form:

```text
mobo_core_shipping:{instance_id}
```

The selected Mobo `shipping_id` is also persisted on the WooCommerce order shipping item, with existing mapping options retained as compatibility fallbacks.

## Database and deployment

- No WordPress database migration is required.
- Existing manual mappings remain readable.
- No Portal change is required beyond the complete shipping contract already delivered with Portal v55.
- PHP 7.4 compatibility is retained.
