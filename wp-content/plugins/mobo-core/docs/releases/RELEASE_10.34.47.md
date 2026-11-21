# Mobo Core 10.34.47 — MIGOPT03-SAFE

Status: **candidate only — do not release before fixture benchmark and customer-host chaos gate pass.**

WordPress plugin only. Portal/.NET is unchanged. No WP-CLI dependency is introduced. This candidate supersedes 10.34.46 for migration benchmark and failure-injection testing.

## Purpose

10.34.47 keeps the migration acceleration introduced by MIGOPT01/MIGOPT02, but changes the correctness model to assume that a customer host can terminate PHP, restart the host, drop/reconnect MySQL, overlap requests, or interrupt a batch at any instruction. Performance optimizations are accepted only when they remain replay-safe and fail closed.

The normative failure model and future test requirements are in `docs/internal/migration-customer-hosting-chaos-contract.md`. Those tests are a mandatory release gate.

## Migration execution safety

- Real/cPanel Cron keeps the bounded migration slice and Cron-only accelerated batches; normal web requests keep conservative batch sizes.
- A safety margin prevents the runner from intentionally starting a new batch at the hard edge of the nominal slice. An already-running SQL statement cannot be pre-empted by PHP.
- The preferred migration mutex is a MySQL/MariaDB connection-scoped advisory lock. Loss of the DB connection therefore loses ownership instead of leaving a trusted stale lease.
- The compatibility option lock uses direct uncached SQL and exact compare-and-swap reclaim/heartbeat/release semantics. A stale worker cannot delete or refresh a newer owner's lease.
- Operational, Identity, and Entity worker checkpoints use optimistic compare-and-swap on the exact persisted `state_json`. A worker with stale state cannot overwrite a newer checkpoint even if lock ownership changed.
- Detectable DB read/write errors, invalid persisted state, ambiguous mappings, or unknown outcomes fail closed.

## Entity crash recovery

Custom Storage schema is **v14**. `entity_meta` now has an immutable nullable `legacy_origin_meta_id` with a unique index. Existing source-linked rows are backfilled from `legacy_meta_id` during the v13→v14 schema upgrade.

Entity destructive cleanup now uses a durable two-phase journal:

1. Select a bounded keyset page after `cleanupCursor`.
2. Idempotently mirror/resolve the exact Custom descendants.
3. Persist `cleanupPending` containing exact Legacy IDs, exact Custom row IDs, affected post IDs, and the next cursor.
4. Detach/delete only the journaled records.
5. Verify the Legacy IDs are absent.
6. Re-read the same pending journal and only then advance/clear the checkpoint.

A hard kill at any boundary replays the same journal instead of guessing what completed. Runtime Custom updates detach `legacy_meta_id` in the same SQL statement that publishes the newer Custom value, while `legacy_origin_meta_id` survives so cleanup can identify the authoritative descendant exactly.
Late-mirror retry also checks that immutable origin first, so a failed Legacy retirement cannot later cause retry to overwrite a newer detached Custom value.

`cleanupCursor` is an accelerator, not the proof of completion. Exact terminal residue proof remains authoritative; if residue is found behind the cursor, cleanup rewinds/re-enters.

## Query-path improvements

- Entity copy/cleanup remains bounded bulk-upsert based instead of one INSERT per Legacy meta row.
- Entity candidate and cleanup scans use keyset pagination by `meta_id`; specialized/excluded keys are filtered in SQL before `LIMIT`, preventing false end-of-scan pages.
- Prefix filtering no longer uses `LEFT(meta_key, ...)`; anchored escaped `LIKE` predicates are used instead.
- Ordinary prerequisite/residue/parity gates use `SELECT 1 ... LIMIT 1` where only existence is required; exact counts remain for terminal/diagnostic proof.
- Operational and Identity proof batches prime bounded post/postmeta caches to reduce repeated WordPress lookups without changing retirement semantics.
- Repeated predecessor exact-residue counts are replaced with durable-completion + fast physical-residue checks.

Deep Identity/Operational repository reads are intentionally still benchmarked rather than aggressively rewritten before real fixture evidence; correctness takes priority over speculative query consolidation.

## Late writes after apparent completion

A persisted `complete` flag is no longer treated as sufficient physical proof. Each terminal retirement worker can detect physical residue after completion and re-enter cleanup while retaining the permanent fence. Real Cron performs a lightweight migration-needed probe even when `mobo_core_db_version` already equals the plugin version; if an ultra-late Legacy write appears, bounded recovery runs again without rolling back the schema version.

## Mandatory validation before release

1. Restore the pristine 10.33.67 fixture on `test1`; benchmark baseline 10.34.44.
2. Restore the same pristine fixture; benchmark 10.34.47.
3. Repeat on `test2`.
4. Compare Cron slices, worker calls/batches, per-phase time, total active/wall time, PHP CPU/memory, DB query count/pressure, correctness, residue, and preservation.
5. Execute the failure-injection cases in `docs/internal/migration-customer-hosting-chaos-contract.md`, including hard-kill boundaries, DB errors/reconnect, overlapping Cron/web requests, advisory/fallback lock loss, stale checkpoint CAS, pending Entity journal replay, late writes, cursor rewind, v13→v14 lineage backfill, and post-current-version recovery.
6. Require exact terminal zero residue and metadata preservation on both sites.

A normal shared-host failure that can be observed by PHP/MySQL must never be interpreted as migration success. Physical storage corruption after an acknowledged durable database commit is outside the plugin's recoverable software contract and is documented explicitly in the chaos contract.
