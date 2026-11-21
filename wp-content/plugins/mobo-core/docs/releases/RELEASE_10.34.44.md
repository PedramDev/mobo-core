# Mobo Core 10.34.44 — REL01 terminal Sync-Health evidence retention

2026-10-01. Cumulative from 10.34.42 (PERF01–PERF12). WordPress plugin only; Portal/.NET is unchanged.

## Confirmed release-gate failure

The 10.34.30 Release Gate demonstrated that periodic maintenance can delete `failed` sync-event rows after the 30-day retention window while `Mobo_Core_Sync_Health::get_operational_dashboard_status()` still relies on those rows to classify a current incomplete product as `terminal-upstream-invalid`. On the next gate run, 12 products lost that evidence and became `unowned convergence gap`.

## Correction

- Before deleting an old failed `UpdateVariant` row with terminal `Incomplete Mobo variant attributes.` evidence, Maintenance executes inside the row's durable Connection context and mirrors the event version/error into the existing Sync Health row.
- The Sync Health upsert ordering rules reject stale event versions, so archival cannot regress newer product state.
- Operational Sync Health accepts the persisted terminal error/version as an alternative to a still-present queue row, and applies the same current-boundary comparison.
- The old event is deleted only after preservation succeeds; a raced row that is no longer `failed` is not removed by the retention delete.
- Ordinary failed-event retention remains 30 days. No new table or schema column is introduced.

## Separate Test Suite drift

The Release Gate's Reprice UI/Cron handoff fixture also predates the UX55 policy fingerprint contract and manually seeds a running generation without `policyFingerprint`. That fixture must be corrected/re-pinned separately; the plugin intentionally invalidates fingerprint-less generations.

## Remaining runtime proof

Re-pin the Deep Test Suite to exact 10.34.44 bytes, test retention with a current terminal event across two Connections, verify failed-row cleanup after durable Health preservation, then run FAST / Plugin Gate / Release Gate.
