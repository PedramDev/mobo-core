# Mobo Core 10.34.48 — MIGOPT04-QUERYSAFE

Status: **candidate only — do not release before clean-fixture benchmark and the mandatory customer-host chaos gate pass.**

WordPress plugin only. Portal/.NET is unchanged. No WP-CLI dependency is introduced. This candidate supersedes 10.34.47 for migration benchmark and failure-injection testing.

## Purpose

10.34.48 keeps the crash/replay model of 10.34.47 and optimizes the remaining migration query paths without allowing performance state to become correctness state. The target environment is customer/shared hosting where PHP, the web server, or MySQL may terminate or reconnect at any instruction.

The normative contract remains `docs/internal/migration-customer-hosting-chaos-contract.md`. The new query-path cases are `MIG-CHAOS-032` through `MIG-CHAOS-039`.

## Query-path changes

### Operational

- Unmapped Operational reconciliation now uses keyset `reconcileCursor` pagination rather than repeatedly scanning from the lowest Woo IDs.
- Each bounded reconciliation page bulk-prefetches the small Legacy identity/operational key set plus Product/Variant Custom State rows. This removes the normal per-object Legacy/Custom read fan-out.
- The set-based page is an optimization only: immediately before a new Product Map ownership row is created, the target object's cached evidence is discarded and Legacy/Custom identity is freshly re-read while holding the Product lock.
- `reconcileCursor` is reset after EOF so a repaired/blocking object behind the cursor receives a full re-pass before the exact proof stage.
- Operational physical cleanup now has a durable `cleanupCursor` based on `meta_id`; the cursor advances only after the atomic DELETE succeeds.
- If exact terminal residue proof finds a late row behind the cursor, cleanup rewinds to zero instead of completing.
- Boolean global blockers use existence probes (`SELECT 1 ... LIMIT 1`) instead of full exact counts where the count is not part of the correctness contract.

### Identity

- One proof page plus required parent objects is fetched as a bounded set: managed Legacy identity postmeta, Product State, Variant State, and Product Map rows are loaded in set-based queries and then resolved from request memory.
- Any Custom row changed by the proof path is invalidated before exact verification, so a prefetch snapshot is never accepted as proof of the just-written state.
- Product Map prefetch uses the durable Default Connection that owns installation-global Legacy identity.
- Identity cleanup now has a durable `cleanupCursor` and the same behind-cursor exact-residue rewind semantics as Operational/Entity.
- Duplicate/alias boolean blockers use existence probes instead of unnecessary aggregate counts.

### Entity

- Existing bulk upsert remains idempotent, but a logical copy page is now split into SQL chunks using both row count and an approximate **512 KiB prepared-payload target**.
- Cursor advancement still belongs to the logical page, not to individual SQL chunks. A kill after chunk N replays the page; already-written chunks converge through the same upsert keys.
- A single unusually large meta row is still processed alone rather than being silently skipped.

## Safety retained from 10.34.47

- connection-scoped MySQL advisory migration lock where available, with direct-DB CAS fallback lease;
- CAS worker checkpoints that reject stale owners;
- durable Entity `cleanupPending` journal;
- immutable `legacy_origin_meta_id` lineage;
- cursor/fence/grace/parity/late-write replay/zero-residue rules;
- post-current-version residue recovery;
- Cron-only accelerated batch policy and conservative normal web-request batches;
- no large migration-spanning transaction and no assumption that PHP receives a clean shutdown callback.

## Deliberately not enabled yet: adaptive batch growth

10.34.48 does **not** dynamically enlarge migration row batches based only on a fast previous batch. On heterogeneous customer hosting that can improve averages while worsening tail latency and increasing the chance that one SQL statement crosses a hard host timeout. The existing fixed accelerated ceilings plus byte-bounded Entity statements remain until `test1`/`test2` benchmark telemetry provides a safe envelope. This is a deliberate safety decision, not unfinished correctness work.

## Mandatory validation before release

1. Restore the pristine 10.33.67 fixture and benchmark 10.34.44 baseline.
2. Restore the fixture again and benchmark 10.34.48; never benchmark over a previously migrated database.
3. Repeat independently on `test1` and `test2`.
4. Compare request/slice count, worker calls, phase time, total active/wall time, PHP CPU/memory, total DB queries, slow query families, residue and preservation.
5. Execute all `MIG-CHAOS-001` through `MIG-CHAOS-039` cases with restart evidence.
6. Require exact zero terminal Legacy residue and no unrelated/Woo-native metadata loss.

No performance claim should be published until those runtime measurements exist.
