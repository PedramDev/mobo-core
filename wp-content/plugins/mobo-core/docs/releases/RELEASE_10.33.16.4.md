# Mobo Core 10.33.16.4 — Throughput & Async Side Effects

This release reduces the number of real synchronization executions and moves expensive side effects away from the desired-state path. It does not change the authoritative sync/repair contract and requires no new database migration.

## Highlights

- **Safe event coalescing:** newer single-entity `ProductUpdated` / `UpdateVariant` events supersede older rows only while those rows are still `pending`. Processing rows, multi-product payloads, and authoritative multi-variation snapshots are never collapsed.
- **Version-aware stale protection:** when both queued rows provide comparable numeric or ISO event/entity versions, a late older delivery retires itself instead of superseding the newer pending desired state; versionless/uncomparable rows retain arrival-order coalescing.
- **Frontend-dirty classification:** internal/source-hash convergence can repair Mobo identity metadata without a WooCommerce CRUD save when the actual storefront title/slug/price/stock/attributes/categories/variation state already matches desired state. No storefront mutation means no product cache purge/warmup.
- **Coalesced variable-parent finalization:** non-authoritative variation deltas set one parent dirty marker and queue the parent. The runner drains immediately-due webhook work first, then recalculates each touched parent once and performs one targeted product purge.
- **Retry-safe parent queue:** transient WooCommerce/third-party-hook failures use bounded 30–600 second retry backoff instead of causing a tight same-request loop.
- **True async image side effects:** non-blocking product synchronization now only writes desired image jobs. Remote downloads, media import and attachment work run later through the existing image queue.
- **Adaptive webhook batch:** bounded batches scale between 1 and 10 items based on due queue pressure and the available execution slice.
- **Queue pressure protection:** immediately-due desired-state work takes priority over image processing and exact-product cache warmup. Future retry rows do not count as pressure.
- **Warmup debounce:** repeated updates of the same product reset a short 15-second debounce window, resulting in one final anonymous same-origin GET for the current product permalink.

## Safety / semantics

- Repair mode keeps its authoritative behavior and does not rely on source-hash shortcuts.
- Event coalescing never supersedes an event already owned by a worker (`processing`).
- Multi-entity and authoritative paged variation payloads retain their existing order/completeness semantics.
- Exact product cache invalidation remains targeted; this release does not introduce full-site purge/preload.
- Image work remains persistent in the database-backed queue, so making the product path non-blocking does not drop desired images.

## Upgrade behavior

No new table or schema migration is required. The parent-finalize and cache-warmup queues use non-autoloaded WordPress options, while event coalescing uses the existing sync-event table.
