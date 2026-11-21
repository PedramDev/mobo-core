# Mobo Core 10.34.42 — PERF11 + PERF12 (indexed Parent queue ordering candidate)

2026-09-30. Baseline `10.34.40` already contains PERF01–PERF10. `10.34.41` is an internal PERF11 checkpoint; the distributed candidate is cumulative PERF01–PERF12 at `10.34.42`. This release changes only the WordPress plugin. Portal/.NET remains read-only and unchanged. The frozen `10.34.30` / r8.3.179 release-gate line remains independent.

## PERF11 — durable indexed `queuedAt` mirror

The shared `mobo_work_queue` schema advances to storage schema v13 and adds `queued_at_us BIGINT UNSIGNED NOT NULL DEFAULT 0` plus `KEY queue_order (connection_id,queue_type,queued_at_us,id)`. Queue write paths mirror payload `queuedAt` into this column. Existing rows are migrated lazily in bounded slices by `backfill_queued_at_us()`. The payload remains authoritative during migration.

Backfill is fail-closed and crash-safe at source level: each row validates Connection/queue/dedupe identity, payload SHA-256 and a positive payload ordering value; the update is CAS-bound to the observed payload hash and `queued_at_us=0`. A concurrent enqueue that wins the race is re-read and accepted only when its new payload/hash/indexed mirror are mutually consistent. Corrupt or unreadable rows stop the migration instead of being silently reordered.

`queued_at_us` is an indexed integer representation of the existing payload ordering contract. Current Parent payloads carry second-resolution `queuedAt`, so multiplying by 1,000,000 does **not** claim new microsecond precision. It only permits exact SQL ordering by the same historical value, with `id` as the deterministic tie-breaker.

## PERF12 — bounded indexed Parent Finalize batch

After PERF11 backfill reports complete for a Connection, Parent Finalize no longer materializes the entire queue at batch start. It selects only the bounded oldest due rows using `ORDER BY queued_at_us ASC,id ASC LIMIT ...`, then retains the existing per-row hash/token CAS claim, Woo side-effect and commit/retry fences.

Until backfill is complete, Parent Finalize deliberately uses the previous full-map/payload-`queuedAt` path, so an interrupted upgrade does not change queue semantics. The historical 5,000-item capacity/eviction edge also retains its existing map path. PERF07's strict aggregate remains the normal batch tail/due-status path.

## Migration and scope

- DDL change: **yes**, storage schema v13 adds one column and one composite index to `mobo_work_queue`.
- Data conversion: bounded lazy backfill per Connection/Parent queue; no bulk activation-time rewrite.
- WordPress/PHP only; no .NET code, route, schema or contract change.
- No change to payment/Wallet/order semantics.
- No claim of measured speedup until real MySQL/WAMP profiling.
- The extra global queue index has a write/storage cost that has not yet been benchmarked.
- Real dbDelta/index creation, interrupted upgrade/backfill, concurrent enqueue and utf8mb4 migration interaction remain runtime-gate items.
