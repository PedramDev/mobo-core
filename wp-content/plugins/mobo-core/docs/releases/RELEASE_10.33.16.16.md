# Mobo Core 10.33.16.16 — Runtime Diagnostics & Health Intelligence

This release adds bounded operational observability after the throughput/performance optimization phases.

## Runtime diagnostics

- One compact rolling diagnostics option (24-hour window), persisted at most once per PHP request.
- Per-stage run count, aggregate time, maximum duration, processed/updated/failed counters.
- Runner duration and stop-reason aggregation.
- Product/Variation no-op vs real CRUD-save counters.
- Bounded recent slow-operation list (default threshold: 2000 ms).
- Latest priority-lane scheduler decision.

## Queue health

- Webhook summary includes pending, due, failed and oldest pending time.
- Image summary includes pending, due, attaching, failed, next retry and oldest pending time.
- Parent Finalize status includes oldest queued time.

## Health intelligence

`/wp-json/mobo-core/v1/health` now exposes `runtimeDiagnostics` with runtime metrics, queue status and recommendations. Recommendations cover persistent object cache, OPcache, HPOS, webhook pressure, image failures and runner memory pressure.

The WordPress Health tab renders these diagnostics without introducing a new database table or per-event telemetry log.

## Upgrade

No manual database migration is required. Existing sync, queue, Stage 7, Repair, cache and order state are preserved.
