# Mobo Core 10.33.96

## Complete Mobo-owned Product/Variation postmeta retirement

This release completes the Product/Variation legacy-storage program started in 10.33.93–10.33.95. Storage Schema v6 adds a generic plugin-owned entity metadata table for the remaining Mobo-owned Product/Variation metadata that does not belong in the typed operational or identity state tables.

### Storage Schema v6

The new `mobo_entity_meta` table stores:

- Woo object ID and object type
- metadata key and raw serialized value
- SHA-256 value hash
- stable multi-row sort order
- source `wp_postmeta.meta_id` while migration is in progress
- creation/update timestamps

The source meta ID is unique, allowing exact parity proof and idempotent replay. Multi-row metadata is retained rather than collapsed into a single value.

### Terminal scope

The v6 generic retirement manages Product/Variation metadata whose key starts with `_mobo_` or `mobo_`, excluding the operational and identity keys already moved to their typed stores. The historical non-namespaced `published_at` field is also managed when the Product/Variation has authoritative Mobo identity.

Out of scope and retained in their native storage:

- WooCommerce-native metadata such as `_price`, `_regular_price`, `_sale_price`, stock and product internals
- attachment/media metadata
- orders and order-item metadata
- non-Mobo Product `published_at`
- WordPress metadata on other post types

### Concurrency-safe migration

The migration is resumable and fail-closed:

1. Schema v6 must validate, and the 10.33.94 operational plus 10.33.95 identity retirements must already be complete with zero residue.
2. Legacy rows are copied in bounded `meta_id` order and mirrored with exact raw value/source-ID parity.
3. Stale pre-transition mirrors are drained before proof.
4. A durable transition marker makes Custom Storage authoritative while a 120-second grace protects requests that began before the transition.
5. Post-meta commit actions mirror old-request writes. Late writes are immediately retired from `wp_postmeta` after a successful mirror.
6. After grace, stale mirrors are drained again. Every legacy row is re-mirrored immediately before physical deletion, closing the DB-commit/action-callback crash window.
7. Cleanup runs in bounded batches and stops fail-closed on any Custom write/read failure.
8. A final direct SQL residue proof must be exactly zero before the permanent fence is published and `mobo_core_db_version` can advance to `10.33.96`.

### Runtime metadata API

After terminal authority:

- WordPress `get/add/update/delete_post_meta()` for managed Product/Variation metadata use `mobo_entity_meta`.
- absent single and multi reads preserve WordPress-compatible empty-result semantics.
- multi-row order and value-qualified update/delete behavior are retained.
- serialized arrays/objects round-trip through raw serialized storage.
- global metadata deletion removes Custom Product/Variation rows and then allows WordPress to continue deleting matching out-of-scope rows such as attachment metadata.
- deleting a Product/Variation purges its generic Custom metadata.

### WooCommerce bridge

`WC_Product` bypasses ordinary `get_post_meta()` for some metadata reads and uses meta-ID mutations when saving loaded metadata. 10.33.96 therefore adds a dedicated bridge that:

- injects terminal Custom rows into WooCommerce's raw metadata read surface
- supplies stable synthetic positive meta IDs
- routes get/update/delete-by-meta-ID operations back to `mobo_entity_meta`
- prevents late/residual managed Product/Variation postmeta from becoming authoritative again

Product Sync and Image Sync typed identity/operational call sites are also terminal-aware and no longer depend on WooCommerce raw postmeta reads after the earlier typed retirements.

### Repair and direct-query consumers

Direct SQL and `meta_query` consumers that cannot benefit from WordPress metadata filters have terminal Custom Storage branches. This includes the post-sync Repair generation-marker scan and the previously converted identity/operational lookup paths. Attachment-oriented image metadata SQL remains intentionally in `wp_postmeta` because attachments are outside Product/Variation retirement.

### Combined health gate v4

The Storage Retirement Finalizer now requires all three scopes simultaneously:

- operational fence active, complete, residue = 0
- identity fence active, complete, residue = 0
- generic entity-meta fence active, complete, residue = 0

The terminal policy is `custom-only-physical-all-mobo-product-variation-postmeta-retirement`.
