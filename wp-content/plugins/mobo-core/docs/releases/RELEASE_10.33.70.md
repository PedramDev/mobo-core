# Mobo Core 10.33.70 — Bounded Custom Storage Backfill

## Scope

This release adds phase 3 of the custom-storage rollout: resumable backfill of existing Product/Variation operational state into `mobo_products` and `mobo_variants`. Runtime reads remain legacy-only and the effective capability ceiling remains `dual-write`.

## Cursor source

Backfill walks `mobo_product_map.id` in ascending bounded batches. It does not perform a catalog-wide `wp_postmeta` scan. Only metadata for the current mapped Woo object is read. Progress is stored in `mobo_migrations` under `product_variant_state_v1`.

## Scheduling and load boundary

- Default storage mode remains `legacy`; backfill is dormant there.
- In dual-write-capable mode the real runner processes at most one backfill batch per invocation.
- Webhook, product sync, image, order and cache queues take priority.
- Backfill does not request its own continuation chain; remaining rows wait for the next ordinary runner/heartbeat invocation.

## Failure semantics

- Stale/deleted/trash legacy map rows are skipped and cursor advancement is durable.
- Live rows with invalid/unresolvable identity are counted as gaps and remain visible as `completed-gaps`.
- A custom-storage mirror write failure stops before the failed row, records retry state and increments the migration attempt counter. The row is retried later and is never silently crossed.
- Legacy customer-facing sync behavior is unaffected.

## Observability

Site Health now exposes requested/effective storage mode, storage schema readiness/version and the durable backfill status/cursor/gap/error state.
