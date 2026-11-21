# MoboCore 10.34.02 — Identity proof recovery from canonical Custom Storage

## Why

Terminal identity-postmeta retirement could hard-block on an object such as `variation_guid_missing_<post_id>` when legacy identity aliases were incomplete even though the already-migrated Custom Storage row for that exact Woo object contained the canonical GUID.

## Change

The identity proof now resolves Product/Variation GUID candidates from both legacy identity metadata and the Custom Storage row bound to the exact Woo object ID. A missing legacy GUID may be recovered only when the Custom row is valid and, for Variations, its Woo parent and Product GUID are consistent with the actual parent and any other durable identity evidence.

Any disagreement remains fail-closed. The migration does not synthesize GUIDs, reassign an existing GUID, or overwrite conflicting identity. Recovered identities are counted in `customIdentityRecoveries` in the durable migration state.
