# MoboCore 10.34.10 — Terminal Operational bridge authority preservation

## Why

After Operational postmeta retirement, Product/Variation runtime metadata is authoritative in Custom Storage and the physical Legacy rows are intentionally gone. Storage Bridge still rebuilt Product/Variation state during Product Map mirroring from raw Legacy postmeta. A terminal Product Map commit could therefore replace valid operational presence/value evidence with an absent Legacy snapshot and leave `mobo_sync_incomplete` inconsistent with its explicit metadata marker. Subsequent verified completion writes correctly failed closed.

## Change

Storage Bridge now reads managed Operational values and presence from a phase-aware authority:

- before the durable Operational terminal fence, the existing scoped Legacy snapshot path remains authoritative;
- after the fence, managed Product/Variation Operational keys are read through normal `get_post_meta()` / `metadata_exists()` so the terminal Custom-backed WordPress meta view supplies the current value and absent-vs-present state.

The change is limited to Operational keys. Identity snapshot behavior and its scoped Legacy bypass are unchanged.

## Safety

- no schema or migration-state change;
- no physical postmeta is recreated;
- pre-terminal Legacy/DUAL_WRITE behavior is unchanged;
- terminal Product Map mirroring preserves revisions, hashes and explicit `mobo_sync_incomplete` presence/value;
- completion transitions from `1` to `0` survive subsequent Product and Variation mirrors;
- inconsistent terminal rows continue to fail closed in `Storage_Postmeta_Write_Suppression`.
