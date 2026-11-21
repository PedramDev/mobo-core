# Mobo Core 10.34.61 — HARDEN14 Observability / Admin Health Correctness

## Production change

The administrator Health summary now consumes the same durable telemetry already exposed by `/mobo-core/v1/health` instead of hiding critical operational causes behind generic status cards.

The summary now reports:

- Source/WooCommerce monetary-contract compatibility and explicit mismatch/reprovision state.
- Per-dependency network failure class without conflating HTTP 403/authentication rejection with transport timeout.
- HTTP status, consecutive failure count and open-circuit state where available.
- Aggregate pending/recovery work from webhook, image and sync queues.
- A conservative stalled signal only when pending work exists **and** the cron runner is overdue.
- The last successful sync timestamp from the durable Health report.

## Safety properties

- Rendering remains read-only and does not execute probes, workers or network requests.
- Existing `/health` payloads and Backend DTOs are unchanged.
- Terminal upstream-invalid sync state is not relabeled as retry-pending work.
- A non-zero queue count alone is not labeled stalled.
- Sensitive response bodies/tokens are not surfaced in the Admin summary.

## Backend

No .NET Backend code change is required. HARDEN03 already preserves and evaluates `moneyContract` and `networkDependencies` from site Health reports.
