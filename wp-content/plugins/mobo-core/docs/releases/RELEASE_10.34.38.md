# Mobo Core 10.34.38 — PERF07 + PERF08 (cumulative source-only candidate)

2026-09-30. Baseline `10.34.36` already includes PERF01–PERF06, sourced from frozen `10.34.30/UX67`. PERF07's `10.34.37` is an internal checkpoint; the distributed candidate is cumulative PERF01–PERF08 at `10.34.38`. **Do not replace the two WAMP sites currently running the r8.3.179 Plugin Gate for 10.34.30.**

## PERF07 — Parent Finalize terminal summary without a second full map

- `Mobo_Core_Work_Queue_Store::pending_due_counts_strict()` returns only strict pending/due counts from row columns already maintained from `nextAttemptAt`, `processingToken` and `processingUntil`. SQL/unavailable storage returns `false`; it is never treated as an empty queue.
- `Parent_Finalize_Queue::process_batch()` retains its single authoritative full payload snapshot at batch start because exact ordering still lives in payload `queuedAt`. The final full-map read was replaced with the strict aggregate.
- `has_due_work()` uses the same bounded row-store aggregate when Custom Storage is available.
- The initial map read is intentionally **not** removed: using SQL `id`, `created_at` or `available_at` as a substitute for payload `queuedAt` would change ordering after retries/imports. Removing that remaining snapshot requires an explicit queued-at column/index migration and separate runtime proof.
- `get_status()` still reads payloads because its `oldestPendingAt` contract is based on payload `queuedAt`; this is a diagnostics path, not the hot process tail.

## PERF08 — Reprice owner-native candidate SQL

- Reprice total count now starts from the Connection-owned `mobo_products` and `mobo_variants` tables and uses `EXISTS` for `mobo_api_price` metadata. It no longer requires one wide posts/meta/owner `LEFT JOIN` followed by `COUNT(DISTINCT p.ID)`.
- Candidate paging uses two direct owner branches combined with `UNION ALL`, then the same global `ORDER BY ID ASC LIMIT` cursor.
- `UNION ALL` is valid because WordPress post IDs are globally unique and the current schema has unique `woo_product_id` / `woo_variation_id` keys.
- Product status, variation status, published parent requirement, active Connection ownership, non-empty API price, cursor and limit semantics are preserved in both the entity-meta-authoritative and legacy-postmeta paths.

## Scope / non-claims

No schema/index/DDL change, no payment/order behavior change, no automatic retry policy change and no throughput percentage is claimed. SQL-shape simplification is source-provable; actual MySQL optimizer cost and wall-clock gain still require runtime profiling. The frozen 10.34.30 Deep Test Suite is unchanged.
