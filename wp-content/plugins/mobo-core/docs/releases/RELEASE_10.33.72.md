# Mobo Core 10.33.72 — Custom Storage Promotion Confidence Gate

This release adds the evidence gate for a future custom-primary rollout while keeping Legacy authoritative and the runtime capability ceiling at `shadow-read`.

Promotion eligibility requires all of the following:

- custom storage schema ready;
- effective mode is `shadow-read`;
- Product/Variation backfill is exactly `completed`, with zero gaps and no current backfill error;
- exact identity coverage between active Legacy Product Map rows and Custom Storage, including zero missing and zero orphan Product/Variation rows;
- at least 500 shadow comparisons collected after the current clean-window baseline;
- at least six hours elapsed in the same clean window;
- no new parity mismatch during the window;
- no new custom dual-write failure during the window;
- parity telemetry is no more than 24 hours old.

Historical mismatch/failure counters are baselined when a window starts. A new mismatch or dual-write failure revokes eligibility and starts a fresh confidence window. Counter resets also force a fresh window.

The gate persists only to the custom `mobo_runtime` table and is evaluated at low runner priority with a five-minute minimum interval. It never writes `mobo_core_storage_mode`, and `custom-primary`/`custom-only` requests continue to degrade safely to `shadow-read` in 10.33.72.
