# Mobo Core 10.34.52 — MIGOPT08-PREFENCE-READS

Candidate build. Do not release before benchmark + hosting-chaos validation.

## Evidence from 10.34.51 FULL / test1

The clean `griffinstore.ir` fixture completed correctly with:

- 28 slices (down from 153 on 10.34.50)
- ~614.9s wall clock, of which ~360s is the three deliberate 120s grace windows
- ~253.5s active request time
- 1,200,108 DB queries (down from 1,916,265)
- peak PHP memory 142,606,336 bytes (~136 MiB; down 50%)
- Operational/Identity/Entity physical residue = 0
- Entity parity mismatch = 0
- no failed Entity meta/delete IDs and no cleanup rewind

The remaining measured query hot paths were still Backfill (~628k queries across the first 9 slices) and Operational proof (~485k queries across the following proof slices).

## Change

`Mobo_Core_Storage_Bridge` now has an explicit trusted migration-only pre-fence Legacy-read scope.

Only the terminal Operational retirement worker opts into this scope:
- while running its own bounded Backfill unit;
- while running one bounded Operational proof page;
- only before the terminal fence is active;
- while the existing migration mutex/slice safety is already in force.

Inside this scope, `operational_post_meta()` and `operational_meta_exists()` go directly to the Legacy authority instead of repeatedly calling `is_fenced()` for every field in the same pre-fence snapshot.

The scope is depth-counted and always unwound with `finally`, including early return/failure paths.

## Explicit non-changes

- No larger Backfill unit.
- No larger Operational proof page.
- No shorter grace period.
- No weaker fence semantics for runtime, webhook, API, admin or normal WordPress writes.
- No change to cursor/fence/grace/CAS/journal/parity/late-write/zero-residue rules.
- No WP-CLI dependency.
- No .NET change.

## Benchmark expectations

On the same restored fixture:
- slice/request count may remain similar to 10.34.51;
- Backfill and Operational proof DB query counts should fall materially;
- `proofNoopVerified` should still reach the same logical population and `proofMirrorWrites` should remain 0 on the healthy fixture;
- peak PHP memory should remain bounded near or below the 10.34.51 level;
- all final residues/parity/failure lists must remain zero.
