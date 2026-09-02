# Mobo Core 10.33.44.17 — Repair ownership alias convergence

This patch aligns integrity Repair ownership checks with the same durable Mobo identity policy used by runtime classification.

## Changes

- Adds `Mobo_Core_Product_Identity_Policy::identity_meta_keys()` and `is_mobo_object_id()` so maintenance code does not maintain a separate identity alias list.
- Extends duplicate price-meta Repair discovery to `portal_product_id`, `mobo_portal_product_id`, `_mobo_portal_product_id`, `portal_variant_id`, `mobo_portal_variant_id`, `_mobo_portal_variant_id`, plus product/variant GUIDs.
- Keeps excluded products fail-closed and unchanged.
- No destructive signature-only Variation repair was added. Report analysis proved the apparent duplicate-signature failures were one live canonical plus one Repair-quarantined Trash row per pair.

Deep Test Suite target: `10.33.44.17-r7.5`.
