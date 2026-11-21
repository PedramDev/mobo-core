# MoboCore 10.34.05 — Parent-map-backed stale trash Variation identity proof

## Why

After 10.34.04 safely skipped the first trashed parent-only Variation residue, terminal Identity proof advanced and exposed another production shape: a trashed Variation with no Variant GUID, no Portal Variant ID, no Variant State and no Variation Product Map. Its surviving Product GUID and Portal Product ID matched its published parent, but the parent Product State row had not yet been backfilled. The parent did have a singular durable Product Map binding plus matching parent legacy Product identity. Requiring Parent Product State specifically made proof depend on backfill ordering and produced a false `variation_guid_missing_<post_id>` blocker.

## Change

`trashed_variation_parent_only_residue_status()` now proves the parent through either Product State or the exact Product Map binding. At least one durable parent source is required. All available parent Product GUID evidence (State, Product Map and parent `product_guid` postmeta) must collapse to exactly one valid GUID. All available parent Portal Product ID evidence (State plus the managed parent aliases) must also be singular. The child must still be a trashed Variation under a published Product, have no Variant State, no Variation Product Map and no non-empty Variant-specific legacy identity. Every surviving Product-level identity value on the child must match the resolved parent identity.

## Fail-closed behavior retained

- live Variations are never skipped;
- Variant State or Variation Product Map evidence makes the row actionable, not stale;
- any Variant GUID or Portal Variant ID prevents bypass;
- malformed, duplicate or conflicting Product Map/State/legacy parent evidence blocks proof;
- child/parent Product GUID or Portal Product ID disagreement blocks proof;
- no GUID is generated and no identity is guessed;
- proof does not delete data; physical legacy postmeta cleanup remains after the terminal fence.
