# Mobo Core 10.33.83 — Controlled Product Map Write Suppression

- Adds an explicit `mobo_core_storage_legacy_write_suppression` rollout option. Default is `off`; the only implemented suppression surface is redundant Product Map no-op writes.
- Suppression is allowed only while effective storage mode is `custom-only`, schema is healthy, the Legacy Retirement Gate v2 has a fresh 15-minute eligible lease, and the current request has no Legacy read dependency or primary-read breaker.
- Product Sync Product/Variation commit boundaries switch from `Product Map -> Custom mirror` to `Custom mirror -> skip Product Map` only when the existing Product Map row is already canonical (same GUID/post/parent/hash/incomplete state and no reverse-map duplicate).
- New identities, identity migrations, parent/hash/incomplete changes, and reverse-map cleanup always retain the Legacy Product Map write. Any Custom mirror failure on an otherwise suppressible no-op immediately falls back to the Product Map for that same commit and disables suppression for the remainder of the request.
- Operational postmeta, Product Ledger, repair/migration/maintenance Product Map writes, and all existing Legacy data remain enabled.
- Adds request-buffered suppression/emergency-fallback telemetry to `mobo_runtime` and Site Health.
- No option is promoted automatically and no Legacy row is deleted by this release.
