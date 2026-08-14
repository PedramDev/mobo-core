# Mobo Core 10.33.16.6 — Variation Snapshot Fast Path

This release optimizes high-volume variable-product synchronization without changing Desired State semantics or requiring a database migration.

## Changes

- Bulk-prime remote Variant GUID mappings for each `UpdateVariant` page.
- Prime WordPress post/meta caches for mapped Variation IDs before field-level no-op checks.
- Persist authoritative `seen variants` once per page instead of once per Variation.
- Build a request-local Variation attribute-signature index once per parent directly from cached post meta, avoiding repeated child hydration/scans when a remote Variant is recreated under a new GUID with the same attribute signature.
- Treat a valid Product Map post status as authoritative for local trash/auto-draft checks; legacy meta-query fallback is used only when no valid map status exists.
- Cache parent Portal product identity and blocked-status decisions for the current request.
- Prime Variation meta before missing-Variation cleanup and attribute-rebuild deletion sweeps.
- Remove one duplicate `_mobo_portal_variant_id` compatibility meta write.

## Expected effect

For a page containing many Variations, GUID-map lookup changes from approximately N map SELECTs to one bounded bulk SELECT when mappings exist. Authoritative seen tracking changes from N growing option writes to one write per page. Signature-repair fallback changes from repeated full-child scans to one indexed scan per parent/request.

## Compatibility

- No new table.
- No manual migration.
- Stage 7, Repair, cache warmup, image refresh, event coalescing and authoritative snapshot behavior are unchanged.
- PHP 7.4 compatible.
