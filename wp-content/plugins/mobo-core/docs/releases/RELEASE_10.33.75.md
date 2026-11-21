# Mobo Core 10.33.75 — Buffered Operational Metadata Commit Bridge

- Adds a bounded, request-deduplicated bridge for Mobo operational Product/Variation postmeta commits that bypass Product Map upserts.
- Observes retained identity/Portal IDs, seen/applied revision watermarks, source hashes and `mobo_sync_incomplete`.
- Mirrors the final authoritative Legacy snapshot in chunks of at most 100 unique Woo entities, plus a shutdown flush for the remainder.
- Unrelated WordPress/WooCommerce postmeta writes are rejected by a tiny bootstrap key filter before the bridge class autoloads.
- Mirror failures remain fail-open for the successful Legacy commit while incrementing the existing dual-write failure counter, which revokes Custom-Primary eligibility.
- Custom-Primary remains limited to Product/Variation identity lookup. Operational state reads remain Legacy-authoritative in this release.
- `custom-only` remains capped to guarded Custom-Primary with Legacy fallback.
