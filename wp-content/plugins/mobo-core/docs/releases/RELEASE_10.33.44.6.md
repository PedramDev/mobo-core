# Mobo Core 10.33.44.6 — reconciliation runtime shutdown

## Runtime behavior

- Product-mutating Adaptive Reconciliation is disabled at build level.
- Real Cron cannot schedule the reconciliation stage even if an old database option still contains `1`.
- Direct, forced, and deep `run_tick()` calls return `disabled-by-build` without loading or applying a product snapshot.
- The admin enable switch and manual execution buttons are unavailable while the runtime switch is off.
- Webhook health bookkeeping remains enabled because it records outcomes but does not fetch or apply repair snapshots.

## Upgrade behavior

- The stored `mobo_core_auto_reconciliation_enabled` option is forced to `0`.
- Any cached in-flight reconciliation pending items are retired to an idle state so a future implementation cannot accidentally resume stale API snapshots.
- Product, variation, webhook queue, and sync-health rows are not deleted or modified by this migration.

## Deployment

- No database schema migration is required.
- The normal plugin version migration applies the runtime option/state shutdown automatically.
