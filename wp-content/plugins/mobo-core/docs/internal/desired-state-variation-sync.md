# Desired-State Product / Variation Topology Policy

Current contract: Mobo Core 10.33.94.

Portal is the authoritative source for Mobo-owned product topology only when the payload carries enough valid desired-state evidence to make the requested mutation safe. Runtime consumers must fail closed when topology evidence is absent, malformed, contradictory, stale, or only partially delivered.

## Variant event semantics

- Foreground `UpdateVariant` webhook messages are deltas by default. A one-Variant webhook must not imply that every other local Variant is missing.
- A Variant list is destructive/authoritative only when the payload explicitly proves full-snapshot semantics (`variantListAuthoritative`, `authoritativeVariantList`, `isFullVariantSnapshot`, `fullVariantSync`, or the supported aliases).
- Authoritative snapshots may be paged. Missing Mobo-owned Variations are considered only after the complete authoritative boundary has been delivered and finalized.
- Missing Mobo-owned Variations are retired through the shared `Mobo_Core_Variation_Lifecycle_Policy` quarantine/Trash path with identity and Product Map read-back verification. Normal desired-state convergence does not permanently hard-delete historical Variations.
- Manual/non-Mobo Variation children are outside Mobo topology ownership and must be preserved.

## Attribute and topology semantics

- Attribute order is not authoritative because Portal does not persist a stable attribute position.
- Attribute identity/count/selection changes may require topology rebuild, but destructive rebuild requires explicit valid Attributes plus explicit authoritative Variant evidence.
- Missing Attributes preserves current topology. Malformed or contradictory Attributes fail closed.
- When structure is compatible, Variations are resolved first by durable remote identity and then by normalized attribute selection; ambiguous identity never authorizes destructive mutation.

## WooCommerce product-type transitions

- Product type is owned by WooCommerce CRUD. Mobo must not pre-write the `product_type` taxonomy before a typed `WC_Product_*::save()` call.
- `Mobo_Core_Product_Type_Policy` owns Simple↔Variable type transitions. It lets WooCommerce observe the real old type, execute `woocommerce_product_type_changed`, refreshes the supported WordPress/WooCommerce read boundary, and verifies all of:
  - raw `product_type` taxonomy read-back;
  - `WC_Product_Factory` type resolution;
  - freshly loaded WooCommerce product class.
- A transition is acknowledged only after all three postconditions agree. Otherwise `mobo_sync_incomplete` remains set and the event/Repair stays retryable.
- Repair pagination is commit-after-apply: `currentVariantCursor` is advanced only after the authoritative Variant page succeeds. A failed page must remain replayable from its last committed boundary.
- Upgrade recovery for the legacy durable type-conversion failure preserves the Repair generation and current product identity, but rewinds that product's Variant pass to page/cursor zero so no failed page can be skipped.
- Variable→Simple lifecycle retirement remains owned by `Mobo_Core_Variation_Lifecycle_Policy`. The type policy only scopes WooCommerce's deletion-control filter so quarantined/manual child posts are not hard-deleted by the canonical type-change hook.
- Parent-level Simple Variant purchase identity is recovery evidence until a Simple→Variable transition has passed durable postcondition verification; it is cleared only afterwards.

## Ordering and crash consistency

- Product and Variant `seen` watermarks are persisted before mutation; `applied` watermarks are committed only after the corresponding durable mutation boundary succeeds.
- Fully stale Variant deltas are rejected before parent topology mutation.
- `mobo_sync_incomplete=1` is the crash/retry marker and must remain set across any partial Product/Variant topology state.
- Product completion and Variant completion are separate. A Variable product is not fully converged merely because its Product payload completed.
- Repair and foreground webhook writers use the same per-product concurrency boundary; stale/partial Repair snapshots may not overwrite a newer foreground result.

## Repair

- Repair uses the same Product Sync, Product Type Policy, identity, ordering, lifecycle and durability boundaries as normal runtime synchronization. It is not a separate destructive shortcut.
- Post-sync Repair cleanup is allowed only after the current Repair generation has a durable completion marker and the product still matches the captured ordering boundary.
- Excluded products, ambiguous ownership, malformed Variant attributes and unprovable Product Map state remain fail-closed.
