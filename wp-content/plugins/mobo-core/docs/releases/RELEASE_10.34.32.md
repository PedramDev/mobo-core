# Mobo Core 10.34.32 — PERF01 / PERF02 (source-only optimization candidate)

Date: 2026-09-30. Branch base: frozen `10.34.30 / UX67`. Intermediate `10.34.31 / PERF01` was an internal checkpoint; this cumulative `10.34.32` includes PERF01 and PERF02. **Do not install over the WAMP sites currently running Plugin Gate r8.3.179.**

## PERF01 — strict single-key readback for `Work_Queue_Store::put()`

- Prior behavior: UPSERT one row, then `load_map()` of all rows for that Connection/queue to verify the one row. This parsed/hashed unrelated recovery items on every `put()`.
- New behavior: `read_one_checked()` distinguishes `ok` / `missing` / `error`, checks the owner-scoped dedupe hash AND full key, validates payload SHA-256 and array unserialization, then compares the exact post-write payload and hash. A read error, corrupt target, absent target or concurrent different payload fails closed.
- `read_one()` compatibility remains nullable for existing consumers; strict writers use `read_one_checked()`. `load_map()` / `replace_map()` continue to validate the entire map when their callers intentionally use it. A corrupt unrelated row is no longer read incidentally by `put()`; it is not deleted or repaired, and map-wide audit remains necessary.
- Existing `ON DUPLICATE KEY UPDATE` claim invalidation semantics of intentional `put()` remain unchanged. No schema/DDL/index adjustment.

## PERF02 — normal Parent enqueue + Variation signature index

### Parent Finalize

- When the row store is available, the existing Connection-scoped `parent_finalize_queue` mutex still guards admission. Legacy Default-only importer must finish successfully before a new direct row write (when fallback is allowed); invalid/failed row read and SQL count fail closed.
- `enqueue()` reads only its own existing key, retains `queuedAt` and prior GUID when appropriate, writes and verifies one row using PERF01, then uses `count_strict()` for the public pending count. A new enqueue invalidates a prior processing claim, so the old worker's `processingToken` cannot consume the new revision.
- **Capacity edge unchanged:** if a new key arrives at `MAX_ITEMS = 5000`, the previous payload `updatedAt` ranking and `replace_map()` eviction path are used. Non-row pre-cutover fallback remains. `process_batch()`, `has_due_work()` and `get_status()` still use the historical full-map processing; they are **not** represented as row-based in this tranche. The strict count is still SQL aggregate work, not constant-time DB access.
- One known non-atomic boundary remains: a PHP worker may outlive its finite queue mutex during external Woo synchronization. This is not resolved by this performance candidate; the old token check remains.

### Variation attribute signature

- Existing first-winner semantics on ascending Woo variation IDs remain. `variation_signature_reverse[parent][variation_id] = signature` tracks only current winners.
- `remember_variation_signature()` now removes the old winner via reverse lookup rather than scanning the parent-wide signature array for each changed variation. Overwriting an existing signature removes the displaced variation's reverse pointer; a displaced variation cannot erase a newer winner when later updated.
- Quarantine clears both request-local indexes for the parent. Index construction still bulk-primes meta once per parent.

## Isolated evidence

- `perf01_work_queue_row.php`: owner A/B, zero owner, missing-vs-SQL-error, corrupt digest, post-write competing generation; no full-map query.
- `perf02_parent_finalize.php`: normal enqueue/dedupe, no map read under capacity, pending marker, owner mismatch, corrupt row, SQL count, unfinished legacy import, enqueue during expensive finalization preserving new generation, final retry and 5000-item eviction fallback.
- `perf02_variation_reverse.php`: old winner removal, duplicate overwrite, displaced winner safety, empty signature, cross-parent isolation and quarantine invalidation.
- Replay prior UX64–UX67 isolated fixtures against this candidate; full live FAST/Plugin Gate must be re-pinned separately. PHP 7.4 compatibility has been kept in syntax but a PHP 7.4 executable is not available in the local audit environment.

## Excluded/next steps

- PERF03: verified postmeta no-op/bulk cache refresh (do not drop crash/read-back fences), bounded order recovery listing and Parent `process_batch()`/status row conversion with exact queuedAt ordering.
- PERF04: Reprice status COUNT and Warmup aggregate-query memoization after correctness constraints.
- No real Product, WooCommerce, Portal, MySQL EXPLAIN, 30K+30K dataset or speed percentage was used/claimed here.
