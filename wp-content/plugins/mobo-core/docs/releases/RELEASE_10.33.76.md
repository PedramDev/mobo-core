# Mobo Core 10.33.76 — Direct Operational State Shadow Read

## Scope

This release does not promote operational state reads to Custom Storage. Legacy postmeta remains authoritative.

## Added

- `Mobo_Core_Storage_State_Parity` observes direct reads of Product/Variation revision, source-hash and `mobo_sync_incomplete` metadata.
- Observations are bounded to 32 unique entities per request with up to five mismatch samples.
- The observer returns no metadata value and never mutates Legacy or Custom state.
- Telemetry is aggregated into the custom `mobo_runtime` table.
- Promotion Gate policy version 3 requires at least 100 clean direct-state comparisons in addition to existing identity parity evidence.
- Any new direct-state mismatch resets the confidence window and can revoke a short-lived Custom-Primary identity lease after telemetry is persisted.

## Authority boundary

- Legacy Product Map/postmeta: authoritative identity fallback and operational state read.
- Custom Storage: mirror, parity target, and promotion-gated identity primary only.
- `custom-only`: still not independently enabled.
