# Mobo Core 10.33.16.2 — Exact Product Cache Warmup

## Purpose

After a Mobo-linked WooCommerce product is updated and its targeted page cache is invalidated successfully, Mobo Core can now warm **only the current permalink of that product**.

## Behavior

- The existing targeted purge remains unchanged: no full-site purge is introduced.
- Only the parent/current WooCommerce product permalink is queued for warmup.
- Home, Shop, product category/tag archives, and the previous permalink are not warmup targets.
- Warmup is deferred to the existing Real Cron / Self Runner rather than blocking the sync request or shutdown.
- A persistent non-autoload queue deduplicates repeated updates to the same product URL.
- Processing is bounded and retries transient HTTP failures with backoff.
- Warmup URLs must belong to the current site host; arbitrary ports are rejected.
- Existing targeted integrations cover WP Rocket, LiteSpeed Cache, W3 Total Cache, and WP Super Cache. Custom integrations can opt in through the warmup filter.
- The Product settings tab exposes an on/off switch. New/existing installations default to enabled when the option is absent.
- Site Health exposes queue counts and last warmup outcome without exposing product IDs or URLs.

## Database

No new table or EF/SQL migration is required. The queue and last result use non-autoload WordPress options and are created only when needed.
