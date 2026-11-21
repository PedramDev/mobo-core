# Mobo Core 10.34.51 — MIGOPT07-OPBACKFILL

## Why this build exists

A clean `test1` production-backup migration on 10.34.50 completed correctly with exact zero terminal residue, Entity parity mismatch `0`, no failed Entity IDs and final DB version `10.34.50`. The run nevertheless exposed two remaining hot paths and one customer-host memory risk:

- Backfill occupied slices 1–132, consumed about 772k DB queries and ~166.9s of migration request time while each request stopped after one `waiting-backfill` unit.
- Operational proof occupied the next active proof slices, consumed about 1.03M DB queries and ~96.7s of Operational worker time. Its code still unconditionally replayed the heavyweight Legacy bridge for every map row, the same pattern previously removed from Identity proof.
- Peak PHP request memory reached about 272 MiB during later multi-iteration proof work because WordPress postmeta cache pages accumulated for the lifetime of the Cron request.

Identity and Entity were no longer the primary hot paths, so this build changes only Backfill/Operational control flow and proof-cache behavior.

## Changes

### Running backfill can consume the bounded Cron slice

`waiting-backfill` is no longer treated as an external wait when `backfillStatus=running`. Durable `stateRevision` and `backfillCursor` are included in the progress fingerprint, so the existing 12-second Cron slice may execute another *small* backfill unit only after durable progress is observed.

The backfill unit itself remains 100 rows. This deliberately avoids making one non-preemptible PHP unit larger on weak/shared customer hosting.

### Operational proof is verify-first

Operational proof now:

1. fetches the bounded Product Map page;
2. primes bounded Post/meta data and bulk-prefetches current Product/Variant Custom rows;
3. constructs the same authoritative Legacy snapshot;
4. treats an exact Custom row as a proof no-op;
5. runs the original mirror only for missing/mismatched Custom state;
6. invalidates the prefetched row and fresh-verifies after recovery mirror;
7. advances the proof cursor only after the page is verified.

New diagnostics: `proofNoopVerified` and `proofMirrorWrites`.

### Request memory is bounded

Identity proof no longer primes the full WordPress postmeta cache because it already maintains its own managed-key snapshot.

Operational proof releases the heavy WordPress postmeta cache for a completed proof page before another page may start in the same accelerated request. Operational proof uses the conservative 100-row page size; speed comes from verify-first + multi-iteration execution, not large pages.

### Backfill Post-object priming

Backfill primes only bounded Post objects to reduce repeated type/status/parent lookups. It does not preload full postmeta pages.

## Safety semantics retained

No change to:

- migration mutex / advisory-lock preference and fallback CAS lease;
- optimistic CAS state checkpoints;
- fence/grace sequencing;
- cleanup cursors and terminal rewind;
- Entity cleanup journal and immutable Legacy origin lineage;
- late-write recovery;
- parity and exact zero-residue completion proofs;
- no-WP-CLI customer-host execution model.

## Benchmark gate

Do not release from static validation. Restore the same `griffinstore.ir` production backup into `test1` and run 10.34.51 from zero with external Scheduled Task and internal Self Runner disabled.

Expected signals:

- backfill should require far fewer HTTP/Cron slices even though its durable unit remains 100 rows;
- Operational proof query count/time should fall sharply and `proofNoopVerified` should rise;
- `proofMirrorWrites` should remain limited to actual mismatch recovery;
- peak PHP memory should be materially below the 10.34.50 ~272 MiB observation;
- final Operational/Identity/Entity residue remains exactly zero and Entity parity remains zero.
