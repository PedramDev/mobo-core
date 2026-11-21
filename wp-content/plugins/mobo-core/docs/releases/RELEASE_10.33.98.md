# Mobo Core 10.33.98

## Purpose

10.33.98 is a migration-bootstrap safety release on top of the completed Storage Schema v6 retirement and 10.33.97 compatibility surface. It does not add a schema migration or expand the retirement scope.

## Bootstrap-safe operational retirement

The strict 10.33.94 operational-retirement migration can run from `plugins_loaded`. `Mobo_Core_Storage_Bridge::mirror_product_legacy_state()` therefore must not instantiate WooCommerce product objects before WooCommerce has fired `woocommerce_init`.

10.33.98 removes the `wc_get_product()` / `WC_Product_Simple` dependency from that bridge. Legacy Simple-product Variant identity is already explicit in the canonical legacy Product snapshot (`simple_variant_guid` and `simple_portal_variant_id`), so the bridge mirrors those fields directly into the dedicated Product-state identity columns.

This prevents `wc_doing_it_wrong` / early translation notices during WP-CLI bootstrap and makes terminal migration convergence safe before WooCommerce runtime initialization.

## Scope

No Storage Schema change. Operational, identity, and generic entity-meta terminal fences, cleanup, compatibility bridges, and Finalizer gate v4 remain unchanged from 10.33.97.
