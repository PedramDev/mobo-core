# Validation Report — Mobo Core 10.31.84

Date: 2026-07-22

## Implemented

- Preserved Desired State Sync, historical variable-product Repair, Adaptive Reconciliation, Heartbeat recovery and Portal-driven remote deployment.
- Added a global Upgrade Barrier for real cron, heartbeat, manual Sync/Repair, reconciliation, webhook, image, reprice, recategorize, maintenance and self-runner work.
- Added observable product-level runtime leases alongside MySQL named locks so product/variation writes participate in drain detection.
- Added safe-boundary checks inside long-running webhook, image, reprice, recategorize and reconciliation batches.
- Preserved Sync/Repair cursors and durable queue state; no live lock is force-released during upgrade.
- Added retryable `blocked-site-busy` responses with active-lock diagnostics.
- Added local backup, package/manifest SHA-256 validation, rollback attempt and post-install disk-version verification.

## Checks completed

- All 48 PHP files passed `php -l`.
- Plugin header and `MOBO_CORE_VERSION` both report `10.31.84`.
- Upgrade Coordinator is loaded by the plugin bootstrap and required by package validation.
- Barrier acquisition race, active-lock enumeration, product-write visibility, queue safe-boundary checks and uninstall cleanup markers were inspected.
- The generated ZIP contains exactly one `mobo-core/` root.
- Every packaged file except the manifest is tracked by `mobo-core-manifest.json` and its SHA-256 was revalidated from the final ZIP.

## Bootstrap rule

Sites older than `10.31.82` still require one manual bootstrap installation. Portal v36 permits the first hop from `10.31.82` or `10.31.83` to `10.31.84` only after a legacy idle preflight. Every later upgrade uses the full WordPress-side barrier.
