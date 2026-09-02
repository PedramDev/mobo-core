# Mobo Core 10.33.44.10 — Reprice / Recategorize queue starvation fix

## Root cause

Administrator-started Reprice and Recategorize are finite maintenance jobs, but they were executed after the image and Product Sync background lanes. On stores with a sustained image backlog or repeated runner time-budget exhaustion, the fair scheduler could select one of these queues yet the request deadline could be reached before its stage was entered. The queue state therefore remained `running` without meaningful cursor movement and appeared stuck.

Recategorize also had a separate concurrency edge case: when manual Product Sync owned the same product between product-lock windows, the eligibility check returned a generic `not-allowed` result. The queue treated that as permanent and advanced its cursor instead of retrying that product.

## Fix

- Reprice and Recategorize now execute immediately after Parent Finalize and before image/Product Sync background work.
- When no due webhook pressure exists, the fair scheduler reserves at most two of its three background slots for these active finite admin jobs so perpetual image/cache maintenance cannot starve them.
- Webhook pressure still reduces background capacity and the slot reservation is disabled in that condition.
- Recategorize now detects `is_manual_sync_busy_for_product()` before the product lock and returns `product-sync-active`; both `product-sync-active` and `product-lock-busy` defer the cursor to the next safe run.

## Safety boundaries preserved

- Product-level concurrency locks are unchanged.
- Reprice/Recategorize worker locks and TTLs are unchanged.
- Queue batch sizes, time budgets, durable checkpoints, failure retry limits and idempotent cursor semantics are unchanged.
- Upgrade barrier, cache-mutation guard, adaptive budget and circuit-breaker behavior remain in force.
- No product, variation, image, webhook, checkout or shipping mutation policy was changed.
- No schema migration or manual SQL is required.
