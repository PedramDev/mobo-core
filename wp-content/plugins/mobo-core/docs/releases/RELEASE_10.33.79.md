# Mobo Core 10.33.79 — Custom-Only Readiness Gate

## Scope

This release does not enable Custom-Only reads. It adds bounded successful Custom-Primary usage telemetry and a stronger evidence gate for a future no-fallback cutover.

## Readiness thresholds

- Promotion Gate must remain runtime-eligible and effective mode must be `custom-primary`.
- At least 1,000 de-duplicated successful identity reads after the readiness baseline.
- At least 250 de-duplicated successful exact operational-state reads after the readiness baseline.
- At least 24 hours of clean Custom-Primary operation.
- Both identity and state usage evidence must be no older than six hours.
- No new Custom-Primary fallback and no new Custom dual-write failure in the window.

Any new fallback/failure resets the readiness window. Counter resets also fail closed and restart the baseline.

## Runtime behavior

`custom-only` is still capped to guarded `custom-primary` with Legacy fallback. The readiness gate is diagnostic/evidence-only and never writes the storage-mode option.

Successful usage telemetry is request-bounded and de-duplicated (64 identity + 64 state signatures maximum per request) and flushes at most two atomic increments into `mobo_runtime` at shutdown.
