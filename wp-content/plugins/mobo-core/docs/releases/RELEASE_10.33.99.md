# Mobo Core 10.33.99

## Index contract hardening

10.33.99 upgrades Custom Storage to **Schema v7**. The data model and retirement scope are unchanged; this release aligns physical indexes with actual runtime query shapes and makes missing or stale performance-critical indexes fail readiness instead of silently passing.

### New aligned indexes

- `mobo_entity_meta.object_order (woo_object_id, sort_order, id)` backs all-meta reads that do not constrain `meta_key`.
- `mobo_image_queue.product_position (product_id, position_index, id)` backs deterministic image ordering for one WooCommerce product.

### Complete validation and repair

Storage Schema v7 validates every declared custom-storage index, including ordered columns, uniqueness, and deliberate prefix lengths. Same-name stale indexes are atomically replaced before readiness can pass. Auxiliary runtime tables now receive expanded index validation and automatic repair during plugin DB migration.

### Reduced write amplification

The old `(status,next_retry_at)` indexes on image queue, image-refresh queue, and sync-event store are exact left-prefix duplicates of `(status,next_retry_at,id)`. New DDL no longer creates them. Existing installations drop them only after the covering index is verified; failure to drop an extra index is non-fatal.

### Seen-set verification

Legacy Seen-Variant verification now orders by `id` and performs the canonical GUID sort in PHP, using the existing `(scope_hash,is_scope_marker,id)` index rather than adding another large high-churn GUID index.
