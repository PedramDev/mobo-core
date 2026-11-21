# Mobo Core 10.34.55 — REL02-DURABLE-RESIDUE-FINALIZER

## Scope

This is a WordPress-only pre-release hardening build. Portal/.NET contracts are unchanged.

## Why 10.34.55 exists

The 10.34.54 Plugin Gate showed that the historical convergence self-heal could exhaust its bounded scan without healing a 927-product cohort when the old completed ProductUpdated/UpdateVariant rows had already been retained/purged away. The current Product Map, Health row, applied checkpoints and local topology were still available, so event-history was an unnecessarily fragile part of the proof boundary.

The same Gate also retained one duplicate `_thumbnail_id` row pair on test2 because the values conflicted. `_thumbnail_id` is a singleton in WordPress/WooCommerce; the safe canonical value is the value returned by the normal singleton meta read.

## Changes

- Re-arms the convergence-residue scan on upgrades to 10.34.55.
- Keeps the existing bounded 50-product cursor model.
- Heals only when all current durable evidence agrees:
  - Product Map current hash equals Sync Health source hash.
  - Product and Variant applied revisions equal the current Health revision.
  - Applied event versions equal the current Health version when present.
  - Product source hash equals the current Health/Map hash.
  - authoritative desired-state completion exists and is not pending/incomplete.
  - the product is a sane variable product.
  - every live local variation has a complete Product Map child row under the same parent GUID.
  - no child Product Map row remains incomplete.
  - no active ProductUpdated/UpdateVariant event owns the product; UpdateVariant ownership is resolved from payload when its entity GUID is a variation GUID.
  - if an active relevant event cannot be attributed to a canonical product GUID, the repair fails closed for the batch.
- Historical completed event rows are not required for current-state proof.
- `_thumbnail_id` repair preserves the oldest physical row only when `get_post_meta(..., true)` resolves to the same value, then removes later physical duplicates and verifies the effective value is unchanged.

## Safety

No incomplete marker is cleared from Health state alone. No local-structure-only guess is accepted. Hash, ordering, desired-state and child-map evidence are all required. If the public singleton thumbnail read does not match the oldest row, the duplicate is left untouched.

