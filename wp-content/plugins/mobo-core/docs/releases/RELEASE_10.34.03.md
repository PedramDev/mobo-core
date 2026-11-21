# MoboCore 10.34.03 — Identity proof recovery from exact Product Map binding

## Why

10.34.02 could recover a missing legacy Product/Variation GUID only when the new Product/Variant State row already existed. A terminal migration could therefore remain blocked on `variation_guid_missing_<post_id>` when that State row was absent even though the durable `mobo_product_map` still contained the exact remote-to-Woo mapping used by sync and Storage Reconciler.

## Change

Identity proof now treats the Product Map as an additional durable evidence source before the identity fence. Recovery is conservative: the map is accepted only when the exact Woo object ID has exactly one row of the expected object type, its GUID is valid, and all Custom/legacy/map evidence agrees. Variations additionally require a real Woo parent and agreement across the Variation map parent GUID, Parent Product Map, Parent Product State, and any surviving legacy parent GUID.

The recovered identity is mirrored into Product/Variant State and then re-read by the existing exact parity proof. No GUID is generated, no existing map is rewritten, and ambiguous reverse mappings remain hard blockers.

## Telemetry

Terminal migration state now records `durableIdentityRecoveries`, `customIdentityRecoveries`, and `mapIdentityRecoveries`.

## Safety

- Multiple Product Map rows bound to one Woo object fail closed.
- Malformed map GUIDs fail closed.
- Parent identity disagreement fails closed.
- Existing Custom/legacy disagreement still fails closed.
- No synthetic GUID generation or destructive conflict resolution is introduced.

Identity proof also distinguishes a missing Variation GUID from a missing parent Product GUID in `lastError`, so a remaining blocker is actionable on the next bounded slice.
