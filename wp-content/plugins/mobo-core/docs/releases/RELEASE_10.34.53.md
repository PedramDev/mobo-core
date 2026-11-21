# Mobo Core 10.34.53 — MIGOPT09-BACKFILL-CACHE

Candidate build. Do not release before benchmark + hosting-chaos validation.

## Evidence from 10.34.52 FULL / test1

The clean `griffinstore.ir` fixture completed correctly with:

- 25 slices
- 578.497s wall clock, including the deliberate grace windows
- 217.123s active request time
- 82.500s process CPU
- 959,936 DB queries
- peak PHP memory 157,286,400 bytes (~150 MiB)
- Operational/Identity/Entity physical residue = 0
- Entity parity mismatch = 0
- no failed Entity meta/delete IDs and no cleanup rewind
- Operational `proofNoopVerified=13277`, `proofMirrorWrites=0`

Compared with 10.34.51, DB queries and process CPU both fell by about 20%, but peak PHP memory increased by about 10%.

The memory peak occurred during accelerated Backfill, where many small durable units can complete inside one Cron request. Each object can lazy-populate WordPress' full `post_meta` cache, and completed units were not explicitly releasing those caches before the next unit started.

## Change

`Mobo_Core_Storage_Backfill::process_batch()` now:

1. keeps the existing 100-row migration unit;
2. bulk-primes WordPress postmeta once for the bounded unit with `update_meta_cache('post', $post_ids)`;
3. executes the existing Legacy snapshot + Custom mirror path unchanged;
4. releases that unit's `post_meta` cache from `finally`, including failure/early-return paths.

This is a cache-lifetime/query-shape optimization only. It does not change durable migration semantics.

## Explicit non-changes

- Backfill unit remains 100 rows.
- Operational proof page remains unchanged.
- Grace periods are unchanged.
- Cursor advancement and CAS publication are unchanged.
- Failed rows are still retried and never silently skipped.
- Fence/late-write recovery/parity/zero-residue proof are unchanged.
- No WP-CLI or .NET dependency/change.

## Benchmark expectations

On the same restored fixture:

- correctness must remain identical to 10.34.52;
- Backfill query count should decrease modestly because first-meta access is batch-primed instead of object-lazy;
- peak PHP memory should return toward or below the 10.34.51 level rather than growing with the number of Backfill units completed in one request;
- final residue/parity/failure lists must remain zero.
