# Mobo Core 10.33.16.12 — Bootstrap & Concurrency Fast Path

## Goal

Reduce per-request plugin bootstrap cost on storefront traffic and prevent product-level contention from consuming bounded worker time or unnecessary remote payload requests.

## Changes

- Added a PHP 7.4-compatible class autoloader matching the existing `Mobo_Core_*` class/file convention.
- Removed eager loading of the full sync/image/migration/health/admin runtime from every request.
- Added a read-only storefront fast path for cache listeners while preserving WP Rocket hierarchical product-category validation behavior.
- Lazy REST, SMS and wallet component loading.
- Migration/deferred-repair conditional loading and one-time shipping cleanup bootstrap gating.
- Admin-only Variation Fields and request-context-aware Sync Settings Guard bootstrap.
- Shared Media adapter loads only when its server flag is explicitly enabled.
- Product MySQL named locks no longer block the worker after the atomic runtime lease; contention is fail-fast/deferred.
- Table webhook processing preflights known parent-product contention before lightweight payload HTTP pulls.
- `mobo-cron.php` defines cron context before loading WordPress, preserving mutation listeners with the lazy bootstrap.
- Dependency detection avoids loading WordPress admin plugin APIs when Persian WooCommerce runtime signals are already present.

## Compatibility

No new table or manual database migration is required. Existing Stage 7, Desired State, Repair, image queues, cache warmup, order submission and upgrade-barrier semantics remain unchanged.
