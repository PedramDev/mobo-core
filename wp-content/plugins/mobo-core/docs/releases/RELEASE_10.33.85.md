# Mobo Core 10.33.85 — Controlled Per-Key Postmeta Suppression

## Scope

This release enables the first real operational-postmeta write retirement step, limited to the four monotonic revision watermarks:

- `_mobo_product_seen_revision`
- `_mobo_product_applied_revision`
- `_mobo_variant_seen_revision`
- `_mobo_variant_applied_revision`

The feature is disabled by default. Exact keys must be explicitly listed in the non-autoloaded `mobo_core_storage_postmeta_write_suppression_keys` option.

## Safety contract

A write is suppressed only when all of the following are true:

1. Effective storage mode is `custom-only`.
2. The exact meta key has a fresh Postmeta Suppression Readiness v2 lease.
3. The request has no primary-read breaker or Legacy dependency.
4. The target Custom row is valid and mapped to the expected Woo object.
5. The incoming revision is monotonic.
6. A bounded compare-and-swap update commits the exact Custom revision vector first.

Only after the Custom commit succeeds does the `update_post_metadata` filter return success and skip the retained postmeta write.

Any Custom miss, identity/parent conflict, revision regression, database failure, or exhausted CAS retry restores the normal Legacy write path. Real suppression failures also trip the primary request breaker.

## Deliberately retained Legacy writes

10.33.85 does **not** suppress:

- `_mobo_product_source_hash`
- `_mobo_variant_source_hash`
- `mobo_sync_incomplete`
- REST metadata mutations
- `add_post_meta()`
- `delete_post_meta()`

Those surfaces require later commit-group/presence-specific retirement work.

## Readiness v2

Initial qualification still requires 250 fresh Custom reads, 50 exercised Legacy writes, and a 72-hour clean window per key. Once an already-qualified key is explicitly suppressed, fresh successful suppression telemetry may satisfy the write-evidence freshness check. A suppression fallback resets the affected key window.
