# Mobo Core 10.33.55 — durable WooCommerce product-type transitions

This release closes a Simple↔Variable topology transition gap found during production Repair for a product whose Product state was applied but whose Variant state remained seen-not-applied.

## Root cause / contract correction

MoboCore pre-wrote the WooCommerce `product_type` taxonomy and then saved a typed `WC_Product_Simple` / `WC_Product_Variable` object. WooCommerce itself determines the old type inside its CRUD data store immediately before it writes the new type and fires `woocommerce_product_type_changed`. Pre-writing the taxonomy therefore hid the real old type from WooCommerce and bypassed the canonical type-transition hook.

## Fix

- Adds `Mobo_Core_Product_Type_Policy` as the single durable Simple↔Variable transition boundary.
- Removes direct Mobo `product_type` taxonomy pre-writes from Product Sync.
- Lets typed WooCommerce CRUD own the taxonomy mutation and native product-type transition hook.
- Refreshes the supported WordPress/WooCommerce read boundary after the typed save.
- Requires raw taxonomy, `WC_Product_Factory` resolution, and fresh product class to agree before acknowledging the transition.
- Returns the existing `mobo_core_variable_product_save_failed` / `mobo_core_simple_product_save_failed` codes on postcondition failure, now with structured read-back evidence attached to the `WP_Error`.
- Makes the existing Variable→Simple variation-preservation filter effective by scoping it around the real WooCommerce Variable→Simple transition instead of installing it after the old type had already been overwritten.
- Keeps Variation ownership/quarantine in `Mobo_Core_Variation_Lifecycle_Policy`; no broad deletion or DB repair heuristic was added.

## Policy alignment

Updates the internal desired-state topology policy to the current runtime contract: foreground UpdateVariant events are deltas unless explicitly authoritative, historical Mobo Variations are quarantined rather than hard-deleted, manual Variations are preserved, and Repair uses the same topology/durability boundaries as normal sync.

## Package hygiene

- Removes two historical `*.php.bak-*` source backup files that were accidentally carried in the release artifact/manifest. They were not runtime-loaded, but backup source files do not belong in the public plugin package.

No Portal schema or database migration is required.
