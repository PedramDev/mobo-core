# Mobo Core 10.34.49 — MIGOPT05-IDENTITY-NOOP

## Why this build exists

Runtime fixture telemetry on `test1` exposed a measured Identity-proof fan-out that static query review did not reveal. Consecutive proof slices issued roughly 62k DB queries while advancing one 500-object proof page. The set-based prefetch was working, but the proof loop still unconditionally replayed `Mobo_Core_Storage_Bridge::mirror_product_legacy_state()` / `mirror_variant_legacy_state()` for every object; every Variation also forced the Product parent through the same heavy bridge.

## Change

Identity proof now uses a verify-first recovery path:

1. Bulk-prefetch the bounded page plus Variation parents exactly as before.
2. Resolve identity from Legacy, Custom and Product Map exactly as before.
3. Compare the prefetched Custom identity with the resolved authoritative identity.
4. If it is already exact, count the object as `proofNoopVerified` and do not replay the heavy bridge.
5. If it is missing/mismatched, execute the original bridge, invalidate the snapshot for that object, and perform the same fresh verification before allowing cursor progress.
6. Variation parent materialization uses the same verify-first rule.

This is a performance optimization only. Fence, grace, CAS checkpoint, cursor, blocker, cleanup, late-write recovery, residue proof and fail-closed conflict behavior are unchanged. A process kill after a recovery mirror but before checkpoint can only replay the page; the bridge remains idempotent.

## Telemetry

Identity worker state now accumulates:

- `proofNoopVerified` — proof objects that were already exact and required no heavy bridge.
- `proofMirrorWrites` — proof objects that required the original recovery mirror path.

These counters make the optimization directly observable in the next fixture benchmark.

## Release gate

Do not release from static validation alone. Resume/restore the `test1` fixture and verify that Identity proof query count collapses while proof cursor, conflict blocking and final residue semantics remain correct. Then run the customer-host chaos contract.
