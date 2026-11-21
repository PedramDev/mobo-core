# Mobo Core 10.33.88 — Final Operational Postmeta Suppression Gate

10.33.88 graduates `mobo_sync_incomplete` into the same explicit, readiness-gated Custom-first write model already used for revision watermarks and canonical 32-hex source hashes. The seven operational keys remain opt-in through `mobo_core_storage_postmeta_write_suppression_keys`; no key is automatically enabled by the release.

## Authority-aware commit group

Once one operational key is both requested and runtime-eligible, its retained WordPress postmeta copy may intentionally become stale after a successful suppressed write. Later sibling mutations therefore distinguish between Custom-authoritative and still-Legacy-authoritative keys. Only non-retired siblings must match retained postmeta exactly; every mutation still CAS-checks the selected Custom row, metadata-presence bits, identity, parent relationship, and current incomplete state.

This closes the sequential-retirement case: revision suppression may be followed by source-hash suppression and then `mobo_sync_incomplete` suppression without forcing already-retired postmeta to reconverge.

For a still-Legacy-authoritative completion sibling, a present retained value must be the literal scalar `0` or `1`. Malformed present values are treated as commit-group drift and fail closed instead of being coerced to complete.

## `mobo_sync_incomplete` mutation semantics

The marker accepts only the persisted `0`/`1` scalar contract used by Product Sync. Product and Variation writes update `sync_incomplete_meta`, its presence bit, derived `sync_incomplete`, aggregate desired/applied revision, desired/applied hash, `sync_status`, dirty bit 1, and timestamps in one bounded compare-and-swap statement. Any missing Custom row, duplicate retained sibling meta, sibling parity mismatch, invalid identity, Variation parent mismatch, CAS exhaustion, or database failure returns to normal WordPress postmeta mutation immediately and trips the request-local primary breaker.

Direct REST mutations and add/delete metadata operations remain on the compatibility path. Product/Variation source callers already write the marker as the canonical strings `'0'` and `'1'`.

## Repair compatibility

Two Repair candidate queries previously filtered Product completion by joining `wp_postmeta` directly on `mobo_sync_incomplete='0'`. That would become incorrect after the marker is Custom-authoritative because the retained rollback row may be intentionally stale. 10.33.88 removes those SQL completion joins. Bounded candidate selection remains SQL-based, while completion proof is resolved with `get_post_meta()`, which follows Legacy in Legacy/Dual modes and the guarded Custom-primary read in Custom-Only.

The attachment cleanup path is intentionally unchanged: Product/Variation suppression does not apply to attachments, so attachment `mobo_sync_incomplete` remains Legacy metadata.

## Release boundary

10.33.88 does **not** physically delete retained operational postmeta. Physical retirement still requires sustained dual-site runtime evidence that all seven exact-key leases are healthy, no compatibility dependency is recorded, and no emergency suppression fallback occurs. The release makes the final Product/Variation operational marker technically suppressible while preserving immediate rollback behavior.
