# Migration Customer-Hosting Chaos / Failure Contract

Status: **mandatory release-gate specification** for the terminal postmeta migrations.

This document is intentionally version-neutral. Future migration test suites MUST keep these cases and invariants even when implementation details, batch sizes, or Cron orchestration change.

## 1. Operating assumption

Mobo Core migration runs on customer-controlled hosting. The runtime must assume that a PHP request can disappear at any instruction and that infrastructure can fail independently of WordPress. A migration is not considered safe merely because the happy path completes.

The implementation and its tests MUST assume all of the following can happen:

- PHP/FPM/Apache/IIS process hard-kill with no `finally`, destructor, or shutdown cleanup guaranteed.
- Host/container/server shutdown or restart, including roughly 20 seconds after work begins or at any other point.
- `max_execution_time`, web-server timeout, reverse-proxy timeout, client disconnect, or scheduler termination.
- MySQL/MariaDB connection loss, reconnect, server restart, deadlock, lock-wait timeout, transient read/write error, or one SQL statement failing after earlier statements in the request succeeded.
- A SQL statement may run longer than the nominal 12-second migration slice. PHP cannot pre-empt an already-running SQL statement.
- Two external Cron invocations may overlap. A normal storefront/admin request may overlap a Cron migration request.
- MySQL advisory locks may be available, contended, lost after reconnect, or unavailable on an unusual host; the compatibility lock path must remain fail-safe.
- WordPress option/object cache may contain request-local stale values; migration lock ownership must not depend on cached option reads.
- A request that began before transition/fence activation may live beyond the normal grace period and attempt a late metadata mutation afterward.
- A batch may be replayed one or many times after an unknown partial outcome.
- A late/residual legacy row may be inserted with an ID behind a worker's logical phase boundary; terminal zero-residue proof must still detect it.
- Storage can be large enough that scan shape, indexes, and bounded batches materially affect request survival.

Hardware failure that violates durable database guarantees (for example physical corruption after an acknowledged commit) is outside the plugin's recoverable contract. The plugin must nevertheless fail closed on detectable database errors and must never infer success from an error/unknown result.

## 2. Non-negotiable invariants

The following are hard correctness gates and take precedence over migration speed:

1. **No false completion.** `mobo_core_db_version` must not be finalized until Operational, Identity, and Entity retirement have each reached their own durable terminal proof.
2. **No skipped legacy row.** A cursor may advance only after the work represented by that checkpoint is durably complete or is covered by a durable replay journal.
3. **Replay must be idempotent.** Re-running the same proof/copy/cleanup batch after a kill must converge to the same logical state without duplicate authoritative rows.
4. **No stale overwrite.** A newer Custom Storage mutation must never be overwritten by an older surviving Legacy row during crash recovery.
5. **No unsafe lock handoff.** A worker that has lost migration-lock ownership may not publish a new durable checkpoint. Any data writes completed immediately before ownership loss must be safe to replay.
6. **Entity lineage is immutable.** A Legacy-sourced Custom row retains `legacy_origin_meta_id` even after `legacy_meta_id` is detached, so recovery never guesses descendant identity from value/order heuristics.
7. **Entity destructive cleanup is journaled.** Exact Legacy IDs and their exact Custom descendants must be durably recorded before source-link detach/delete begins.
8. **Pending cleanup is recovered before new cleanup.** A journaled Entity batch must be completed idempotently before another cleanup page can be selected.
9. **Terminal proof is exact.** Fast `EXISTS/LIMIT 1` probes may be used for ordinary gates, but permanent completion requires the exact zero-residue proof retained by the worker.
10. **Cursor rewind closes behind-cursor writes.** If terminal proof finds residue after the cleanup cursor reached the end, cleanup must rewind/re-enter rather than complete.
11. **Fence/grace/parity/late-write protections remain mandatory.** Performance changes must not remove or weaken transition grace, parity proof, late-write replay, stale-source pruning, permanent fence, or zero-residue validation.
12. **Non-Mobo metadata is preserved.** WooCommerce native metadata, attachments, orders/order items, and unrelated postmeta must not be removed by Entity cleanup.
13. **Bounded work.** Cron may use accelerated batch sizes only inside its bounded migration slice; normal web requests retain conservative batches.
14. **No WP-CLI dependency.** Customer-host execution and recovery must work through WordPress/PHP HTTP/Cron execution alone.
15. **Fail closed on ambiguity.** Conflicting identity/source mappings, ambiguous detached descendants, or an unknown DB outcome must block/retry rather than guess.

## 3. Required crash points and expected recovery

Future automated suites MUST inject a hard stop or equivalent failure at each boundary below and then restart from the persisted database state.

| Test ID | Inject failure at | Required state after restart |
|---|---|---|
| MIG-CHAOS-001 | Before a migration batch reads rows | Same batch remains selectable; no checkpoint movement. |
| MIG-CHAOS-002 | After proof/copy reads but before any write | Replay is harmless; cursor unchanged. |
| MIG-CHAOS-003 | Mid proof/mirror work before checkpoint | Already-applied idempotent writes may exist; replay finishes them; cursor was not advanced. |
| MIG-CHAOS-004 | Immediately after proof/copy write but before cursor persist | Same batch replays without duplicate authoritative state. |
| MIG-CHAOS-005 | After Entity bulk mirror, before cleanup journal persist | Source-linked rows permit exact replay; no Legacy row was destructively retired yet. |
| MIG-CHAOS-006 | Immediately after Entity cleanup journal persist | Restart discovers `cleanupPending` and finishes that exact journal before scanning new rows. |
| MIG-CHAOS-007 | After one/all exact Custom source links are detached, before Legacy DELETE | Journal replay accepts already-detached rows and deletes only recorded Legacy IDs. |
| MIG-CHAOS-008 | After Legacy DELETE, before journal/cursor clear | Replay observes Legacy IDs already absent, then advances/clears the same journal exactly once. |
| MIG-CHAOS-009 | After cleanup journal clear/checkpoint | Next page begins strictly after the durable cursor. |
| MIG-CHAOS-010 | Late Legacy write behind `cleanupCursor` before terminal proof | Exact residue proof fails, cleanup cursor rewinds, residue is drained; no false completion. |
| MIG-CHAOS-011 | Runtime Custom update after source row existed, kill before Legacy retirement | Detached Custom descendant keeps immutable `legacy_origin_meta_id`; cleanup resolves it exactly and must not remirror stale Legacy over it. |
| MIG-CHAOS-012 | Runtime delete after Legacy-first retirement, kill before Custom delete | Operation remains incomplete but safe/retryable; migration must not resurrect deleted Legacy state. |
| MIG-CHAOS-013 | Pre-transition request survives beyond grace and commits a late Legacy write | Commit hook/replay or final residue loop converges; permanent completion cannot hide residue. |
| MIG-CHAOS-014 | MySQL connection drops while advisory lock is owned | Lost ownership is detected; stale worker cannot publish a checkpoint; later owner can resume. |
| MIG-CHAOS-015 | Fallback lease expires while old request is stalled | Any overlapping old-worker data effect is idempotent; stale owner cannot CAS-refresh/release/persist over the new owner. |
| MIG-CHAOS-016 | Two contenders reclaim the same stale fallback option | At most one CAS reclaim/acquire succeeds; loser backs off without deleting the winner's lease. |
| MIG-CHAOS-017 | Cached option value differs from DB lock value | Direct uncached DB ownership check wins; cached value cannot grant ownership. |
| MIG-CHAOS-018 | DB deadlock/lock timeout on any batch write | Worker records/fails closed, checkpoint does not skip work, next request can retry. |
| MIG-CHAOS-019 | DB error during terminal residue/parity query | Completion is rejected; error is not treated as zero/no-mismatch. |
| MIG-CHAOS-020 | SQL call extends past the 12-second nominal slice | No new batch starts after the work deadline; completed atomic SQL is safely checkpointed/replayed on next request. |
| MIG-CHAOS-021 | Two Cron requests plus one normal request overlap | Migration mutex/checkpoints remain coherent; normal request uses conservative batch policy. |
| MIG-CHAOS-022 | Process dies immediately after transition marker but before transition state persist | Marker reconstructs transition state on restart and restarts a conservative grace interval. |
| MIG-CHAOS-023 | Process dies immediately after permanent fence marker but before state persist | Restart re-proves physical zero residue before reconstructing terminal complete state. |
| MIG-CHAOS-024 | Entity page consists only of specialized/excluded keys | Scan does not mistake post-LIMIT filtering for end-of-scan; excluded keys are filtered in SQL. |
| MIG-CHAOS-025 | Detached-descendant lookup returns ambiguous Custom rows | Worker blocks/fails closed; it must not choose an arbitrary authoritative descendant. |
| MIG-CHAOS-026 | Same Entity pending journal is replayed repeatedly | `cleanedRows`/cursor do not corrupt correctness; source rows remain absent and Custom rows remain singular/authoritative. |
| MIG-CHAOS-027 | Host restarts repeatedly across every migration phase | Migration eventually converges from durable state with no manual reset. |
| MIG-CHAOS-028 | Schema v13 entity rows have `legacy_meta_id` but no origin column/value | v14 backfill copies exact unique lineage before storage readiness; any DDL/backfill conflict fails closed. |
| MIG-CHAOS-029 | Stale worker loads runtime state, loses lock, newer worker checkpoints, stale worker later persists | Exact `state_json` CAS rejects stale checkpoint; newer state cannot regress. |
| MIG-CHAOS-030 | DB version is already current, then an abnormally late Legacy row appears | Real Cron's lightweight terminal probe reopens only physical recovery and returns residue to exact zero without rolling DB version backward. |
| MIG-CHAOS-031 | A late Legacy mirror succeeded, Legacy retirement failed, then a newer Custom mutation detached that same origin before failed-mirror replay | Replay recognizes the immutable detached descendant and must not overwrite its newer Custom value with the surviving stale Legacy row; Legacy retirement may then finish. |
| MIG-CHAOS-032 | Operational cleanup DELETE commits, process dies before `cleanupCursor` checkpoint | Durable cursor remains old; replay may select the same range, already-deleted IDs are harmless, and terminal proof still converges to exact zero. |
| MIG-CHAOS-033 | Identity cleanup DELETE commits, process dies before `cleanupCursor` checkpoint | Durable cursor remains old; replay is harmless and no identity row may be skipped. |
| MIG-CHAOS-034 | A late Operational/Identity Legacy write lands below the current cleanup cursor | EOF is not completion: exact residue proof fails, cursor rewinds to zero, and the late row is retired before completion. |
| MIG-CHAOS-035 | Entity bulk copy is split by byte budget; process dies after an earlier SQL chunk but before all chunks finish | Copy cursor is unchanged; the whole logical page replays, prior chunks upsert idempotently, and no Legacy cleanup begins from an incomplete copy page. |
| MIG-CHAOS-036 | Identity proof set-based snapshot becomes stale because a concurrent mutation lands before checkpoint | Snapshot is only an optimization: authoritative mirror/write checks and terminal parity/residue gates must reject false completion; replay obtains a fresh page snapshot. |
| MIG-CHAOS-037 | Operational reconcile page snapshot is stale before a new Product Map ownership row is inserted | The target object's Legacy/Custom identity is invalidated and freshly re-read under the Product lock before ownership is created; stale snapshot evidence can never create a map row. |
| MIG-CHAOS-038 | Operational reconcile cursor reaches EOF while a previously blocked/repaired object exists below the cursor | Cursor is reset for a full re-pass before proof; no repaired/unmapped object is permanently skipped. |
| MIG-CHAOS-039 | Set-based prefetch query fails or returns an observable DB error | Worker fails closed, publishes no progress beyond already durable work, and retries the same logical page later. |

## 4. Query/performance contract

Performance optimization is allowed only when the invariants above remain unchanged.

Preferred optimization techniques:

- keyset/cursor pagination instead of `OFFSET`;
- bounded bulk reads and bulk upserts;
- `EXISTS` / `SELECT 1 ... LIMIT 1` for boolean gates;
- exact `COUNT(*)` only where an exact diagnostic/terminal value is required;
- index-friendly predicates instead of wrapping indexed columns in functions where practical;
- bounded cache priming/set-based fetches to reduce N+1 reads;
- cleanup/reconcile cursors that advance only after durable work, with exact terminal rewind for behind-cursor residue;
- byte-bounded SQL chunks in addition to row-count bounds, so a page with unusually large values does not create an unbounded bulk statement;
- fresh under-lock revalidation for ownership decisions even when the surrounding page uses a set-based snapshot;
- Cron-only accelerated batch sizes;
- safety margin before the slice deadline so a new batch is not intentionally started at the hard edge.

Forbidden shortcuts:

- advancing a cursor before the represented work is durable;
- making a large transaction span an entire 12-second slice;
- removing terminal residue/parity proofs because a cursor reached EOF;
- trusting RAM/request-local flags as the only proof of completion;
- unbounded batch growth merely to reduce request count;
- treating timeout/DB error/unknown affected-row result as success.

## 5. Fixture and benchmark requirements

Before release, restore the `10.33.67` fixtures from zero for each independent run on both `test1` and `test2`.

A clean current-candidate run must capture at least:

- Cron slice count;
- migration iterations/batches per slice;
- per-phase elapsed/active time;
- total elapsed time;
- PHP CPU/memory where observable;
- DB/query pressure and slowest query families where observable;
- correctness, exact residue, preservation, recovery, and terminal DB-version state.

Performance measurements are invalid if the run did not start from a clean restored fixture.

## 6. Release gate

A candidate MUST NOT be released merely because the normal migration reaches PASS. Release requires all of the following:

- ordinary static/lint/manifest/ZIP integrity gates pass;
- migration fixture correctness passes on both `test1` and `test2`;
- the chaos/failure cases above are automated or explicitly executed with evidence;
- terminal Legacy residue is exactly zero for every retired scope;
- unrelated/Woo-native metadata preservation passes;
- repeated restart/replay does not create duplicates or regress state;
- performance is practical for customer hosting and no newly observed query bottleneck threatens request survival.

If a future optimization conflicts with this contract, correctness wins and the optimization must be redesigned.


## Identity verify-first recovery cases (10.34.49+)

### MIG-CHAOS-040 — Exact Product Custom snapshot, no recovery mirror
Given a Product whose Legacy/Map/Custom identities agree, Identity proof must advance using the prefetched Custom snapshot without invoking the heavyweight Product Legacy bridge. A kill before checkpoint replays the same read-only proof safely.

### MIG-CHAOS-041 — Product mismatch requires recovery mirror
Given valid non-conflicting durable identity evidence but a missing/mismatched Product State identity, Identity proof must execute the original Product mirror, invalidate the request snapshot, re-read/verify the persisted row, and advance only after exact verification. Kill after mirror and before checkpoint must replay idempotently.

### MIG-CHAOS-042 — Exact Variation and exact parent are no-op verified
Given a Variation and parent Product whose Custom identities already match resolved durable evidence, neither the Variation bridge nor the parent Product bridge may be replayed solely for proof.

### MIG-CHAOS-043 — Variation parent mismatch is recovered before child proof
Given an exact Variation identity but missing/mismatched parent Product State, the parent must use the original recovery mirror + fresh verification path before the Variation can be proven. A parent conflict remains fail-closed.

### MIG-CHAOS-044 — Variation mismatch requires recovery mirror
Given valid resolved Variation/parent identity and a missing/mismatched Variant State identity, execute the original Variation mirror, invalidate that row snapshot and fresh-verify before cursor progress. Kill after mirror and before checkpoint must replay without corruption or skip.

### MIG-CHAOS-045 — No-op optimization must not bypass malformed/conflict gates
Malformed Legacy identity, alias disagreement, Product Map ambiguity, parent mismatch or durable identity conflict must still block before the no-op/mirror decision. Performance optimization is never correctness authority.

### MIG-CHAOS-046 — Telemetry distinguishes no-op and recovery paths
A healthy fixture page must increase `proofNoopVerified`; a deliberately missing Custom identity must increase `proofMirrorWrites`. Neither counter is authoritative for completion; terminal proof remains authoritative.


## Accelerated slice-loop control cases (10.34.50+)

### MIG-CHAOS-047 — Empty `lastError` must not terminate an active slice
Given an active terminal worker that makes durable progress and reports `lastError=""`, the accelerated Cron slice must not classify the serialized field name `lastError` as an error condition. The same request may continue with further bounded worker iterations until the work deadline, hard iteration cap, true waiting state, completion, or lock loss.

### MIG-CHAOS-048 — Genuine error still terminates the slice
Given a worker with non-empty `lastError` or an explicit error/blocked status, the current slice must stop starting new migration units. Durable prior checkpoints remain valid and the next request may retry according to the worker state machine.

### MIG-CHAOS-049 — Grace state must not busy-spin
Given a worker in `fenced-grace`, the accelerated request must stop after observing that waiting state rather than executing repeated no-op iterations until the wall-clock deadline.

### MIG-CHAOS-050 — Multi-iteration active slice remains crash-safe
Given multiple successful worker batches in one Cron slice, a hard process kill after any completed iteration must preserve every already-published checkpoint and replay only the first uncheckpointed logical batch on the next request. Slice-local iteration count is never correctness authority.

## Backfill / Operational verify-first cases (10.34.51+)

### MIG-CHAOS-051 — Running backfill is active bounded work
Given `status=waiting-backfill`, `backfillStatus=running`, and a durable state revision/cursor advance, the same real-Cron request may start another small backfill unit while it remains before the slice work deadline. The backfill unit size remains bounded independently of the slice iteration count.

### MIG-CHAOS-052 — Non-running backfill still stops fail-closed
If backfill reports retry, blocked, unavailable, schedule failure, owner failure, or any state other than `running`/terminal completion, the accelerated slice must stop starting new backfill units. It must not busy-spin or reinterpret failure as progress.

### MIG-CHAOS-053 — Kill between backfill units
If PHP/host termination occurs after one backfill unit durably checkpoints but before the next unit starts or finishes, restart resumes from the durable backfill cursor. Already mirrored rows may be re-read but must not be skipped or corrupted.

### MIG-CHAOS-054 — Exact Operational Custom snapshot is proof no-op
Given a valid Legacy Operational snapshot and an exact prefetched Custom Product/Variant state row, Operational proof advances without replaying the heavyweight Legacy bridge. A kill before proof checkpoint safely replays the same read-mostly page.

### MIG-CHAOS-055 — Operational mismatch uses original recovery path
Given missing/mismatched Custom Operational state, proof must execute the original Product/Variant mirror, invalidate the prefetched row, fresh-read the persisted Custom state, and advance only after exact verification. Kill after mirror and before checkpoint must remain idempotent.

### MIG-CHAOS-056 — Proof memory remains bounded across multi-iteration slices
Identity proof must not retain full WordPress postmeta pages that it does not use, and Operational proof must release the heavy postmeta cache for a completed page before another page is started in the same slice. A long multi-iteration Cron request must not accumulate page-by-page meta cache without bound.

### MIG-CHAOS-057 — Speed must not come from enlarged crash units
Backfill remains a small bounded unit (100 map rows in this build), and Operational proof remains a small bounded page. The accelerator may execute more completed units per request, but a single unit must not be enlarged merely to reduce request count on fast hardware.


## Trusted pre-fence Legacy-read scope cases (10.34.52+)

### MIG-CHAOS-058 — Trusted scope is migration-only
Normal runtime, webhook, API, admin and WordPress metadata paths must never enter the pre-fence Legacy-read scope. Their Operational reads must continue to evaluate the durable terminal fence using the normal runtime rules.

### MIG-CHAOS-059 — Scope always unwinds on failure/early return
If Backfill or Operational proof fails, throws, or returns early while the trusted pre-fence scope is active, `finally` must restore scope depth before control returns. A later read in the same PHP request must not remain pinned to Legacy accidentally.

### MIG-CHAOS-060 — Fence safety is unchanged
The trusted pre-fence scope may be entered only by the terminal Operational retirement worker before fence activation while its migration lock is valid. Once a fence is active/recovered, migration must not use this scope to bypass Custom authority. Batch sizes, grace, terminal residue proof and late-write recovery remain authoritative.

## Bounded Backfill cache-lifetime cases (10.34.53+)

### MIG-CHAOS-061 — Backfill meta cache is unit-bounded
A fast accelerated Cron slice may execute many durable Backfill units, but WordPress postmeta loaded for one completed unit must be released before the next unit can accumulate on top of it. Peak memory must scale with the bounded unit, not with total rows processed in the request.

### MIG-CHAOS-062 — Failure and early return still release Backfill meta cache
If a Backfill row fails to mirror and the unit returns `retry`, or an exception/early return occurs while the unit is active, `finally` must unwind the trusted Legacy-read scope and release the unit's `post_meta` caches before control returns.

### MIG-CHAOS-063 — Cache optimization cannot advance durability
Bulk meta priming and cache release are read/cache operations only. They must never advance the durable Backfill cursor, mark a row mirrored, or weaken replay. Cursor publication remains after successful row processing exactly as before; killing PHP before publication must safely replay the unit.


## 10.34.54 bounded convergence-residue drain

The pre-release convergence residue repair is a post-version proof repair, not a new automatic reconciler. It must remain bounded to 200 Product Map rows per unit, persist a map-id cursor, keep its pending intent until the candidate scan is exhausted, and be eligible for the existing real-Cron migration budget. Clearing `mobo_sync_incomplete` remains conditional on the historical variable-product completion-race proof (source/map hash, product+variant applied boundary, topology, event generation/order, authoritative final variant payload/count, and no active event owner). Unproven rows are skipped and remain release-visible. `_thumbnail_id` dedupe is limited to current Mobo product mappings, preserves the oldest/effective row, and verifies a single row remains.


## 10.34.55 durable convergence-residue proof

Release residue repair must remain bounded and resumable but must not depend on retained completed-event history. A heal requires exact current Product/Variant ordering evidence, source-hash agreement, authoritative desired-state completion, sane local variable topology, complete child Product Map ownership, and no active event owner. Singleton thumbnail normalization must preserve the value returned by WordPress' normal singleton meta API.
