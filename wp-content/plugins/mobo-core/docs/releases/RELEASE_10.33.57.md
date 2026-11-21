# Mobo Core 10.33.57

## Repair checkpoint upgrade recovery

- Preserves an existing customer `mobo_core_sync_state`; no manual SQL deletion/reset is required.
- Detects only the known legacy Repair failure `WooCommerce variable product could not be durably saved after type conversion.` while the Repair state is still `running`.
- Keeps the same Repair `syncId`, current product identity, product cursor and product payload, but rewinds the current product's Variant traversal to page 1 / cursor 0.
- Stores the pre-recovery Variant checkpoint in `legacyFailedVariantCheckpoint` for forensic evidence.
- Clears the legacy terminal error so the normal real-cron/manual worker can continue the same Repair generation with the current product-type policy.
- Does not auto-recover unrelated attribute/topology validation errors.

## Variant cursor durability

- `nextCursor` is no longer written to `currentVariantCursor` before the fetched Variant page is applied.
- The cursor is committed only after `process_update_variant_payload()` succeeds.
- This prevents a failed page from being skipped by stale recovery, plugin upgrade, or a later worker run.

## Customer-upgrade intent

The upgrade path is designed for the real deployment case: an older customer database may contain an in-progress/failed Repair checkpoint while the plugin files are replaced with the new build. The new build self-heals that exact unsafe checkpoint; operators do not delete `mobo_core_sync_state`.
