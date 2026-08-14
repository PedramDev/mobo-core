# Mobo Core 10.33.16.5 — Database & Queue Fast Path

This release reduces database round trips in the highest-frequency synchronization paths without changing desired-state semantics, repair behavior, cache policy, or queue durability.

## Highlights

- **Webhook bulk claim:** due table-backed events are selected as a bounded ID set, claimed with one bulk UPDATE, then fetched once for processing. The old per-event lock UPDATE is retained only as a compatibility fallback.
- **Image bulk claim:** the image worker uses the same bounded claim/release model and releases any unhandled `processing` rows on safe upgrade/time boundaries.
- **Fast due checks:** runner pressure decisions use `SELECT ... LIMIT 1` instead of constructing full queue status/counter diagnostics.
- **One-query summaries:** webhook and image pending/due/failed/timing counters are aggregated once per request and invalidated after mutations.
- **Queue indexes:** compact `(status, next_retry_at, id)` and `(status, locked_until, id)` indexes are added to the event/image tables by the normal `dbDelta` schema refresh.
- **Throttled legacy sweep:** the fallback `progress_json LIKE '%waitingForParent%'` retirement scan runs at most every five minutes instead of every webhook pass; normally-due waiting rows continue to retire in their own event processing path.
- **Atomic product-map upsert:** map convergence no longer performs `SELECT` before every changed product/variation write.
- **Request-local map lookup cache:** repeated remote GUID resolution inside one sync request avoids duplicate mapping SELECTs while stale/trash validation remains enforced.
- **Bulk variation-map cleanup:** obsolete variation mapping rows are removed in chunks instead of one DELETE per row.
- **Image-set lookup batching:** enqueueing one product's desired image set resolves all existing queue keys in one query instead of one lookup per image.
- **Request-local table-existence cache:** repeated `SHOW TABLES LIKE` probes are eliminated inside a request.

## Safety / semantics

- Bulk claims are still bounded by the existing global worker leases. Claim predicates are repeated in the bulk UPDATE to remain race-safe against custom integrations.
- Any claimed row left in `processing` when a safe stop occurs is released to `pending`; rows already marked done/retry/failed are not touched.
- Image rows retain `attachment_id` across release/retry, so an imported attachment is reused rather than downloaded again.
- Event ordering, event coalescing rules, authoritative variation snapshots, Repair mode, Stage 7 convergence, exact-product purge/warmup, and async image semantics are unchanged.
- No application table is replaced and no queue data is reset.

## Upgrade behavior

No dedicated data migration is required. The normal Mobo Core schema refresh runs `dbDelta` and adds the two compact indexes to the existing sync-event and image-queue tables. Existing rows and statuses are retained.
