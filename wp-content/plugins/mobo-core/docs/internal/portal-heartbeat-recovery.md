# Portal Heartbeat Recovery — Mobo Core 10.31.81

## Endpoint

`POST /wp-json/mobo-core/v1/heartbeat`

Authentication uses the existing `X-SEC` security code. The response is explicitly marked `no-store` and includes `X-Mobo-Heartbeat: 1`, preventing a normal page/CDN cache from replacing the WordPress/PHP execution.

## Execution model

The endpoint invokes a bounded slice of `Mobo_Core_Cron_Runner`. This is the same runner used by cPanel cron and the local self worker. The runner continues to call the existing desired-state product sync and Adaptive Reconciliation implementation.

Default heartbeat limits:

- Runtime budget: 12 seconds
- Maximum rounds: 2
- Product sync steps per round: 1
- Remote Portal/API timeout during heartbeat: 10 seconds

If work remains and progress was made, the existing continuation mechanism may dispatch another local worker slice.

## Local telemetry

The plugin stores:

- `mobo_core_portal_heartbeat_last_attempt_at`
- `mobo_core_portal_heartbeat_last_success_at`
- `mobo_core_portal_heartbeat_last_result`

The compact heartbeat state is also exposed under `portalHeartbeat` in the health report.
