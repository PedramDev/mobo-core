# MoboCore 10.34.11 — Terminal completion handoff consistency

## Why

After terminal Operational postmeta retirement, Product/Variation Map completion and the explicit `mobo_sync_incomplete` marker are persisted through separate verified steps. In 10.34.10, the Map could be committed clean immediately before the marker changed from `1` to `0`. Storage Bridge then rebuilt Custom state from the clean Map while still observing the explicit marker as `1`, producing the internally contradictory state `sync_incomplete_meta=1` with aggregate `sync_incomplete=0`. The terminal postmeta write-suppression layer correctly rejected further mutation of that inconsistent row, leaving the object stuck incomplete.

## Change

Storage Bridge now treats an explicitly present `mobo_sync_incomplete=1` marker as fail-closed during Product and Variation snapshots, even when the supplied Product Map flag has already become clean. The intermediate Custom row therefore remains consistently incomplete (`1/1`) until the normal verified marker mutation changes it to complete (`0/0`).

This preserves the crash-safe ordering already used by Product Sync: content and Map completion remain durable before the final explicit completion marker is cleared.

## Compatibility

- No database schema change.
- No Swagger/model rename.
- No Portal/provider protocol change.
- No migration fence reset or Legacy fallback.
- Portal V80-C1 remains unchanged.

## Validation target

Deep Test r8.3.135 adds/retains runtime coverage for Simple Product completion, Variable/Variation completion, terminal Operational bridge authority, historical pre-terminal Repair fixtures, and guarded one-time cleanup of the exact 10.34.10 completion-handoff residue.
