# Mobo Core 10.31.94

- Health reporting is now pull-only: Portal reads the authenticated `/mobo-core/v1/health` endpoint.
- Automatic cron-based outbound Health reports are disabled.
- The legacy `/health/report-now` route no longer performs an outbound request; it returns a local snapshot for backward compatibility.
- The plugin Health admin tab now describes the actual pull architecture and displays the Health endpoint.
- Existing Health telemetry, credential validation, cron status, queue state, Sync/Repair state, settings hash, and WebP diagnostics remain available.
- The 10.31.94 migration disables and clears legacy outbound Health-report state.
