# Validation Report — Mobo Core 10.33.4

Date: 2026-08-08

## Scope

This release integrates the CollectionCase WP Rocket Product Category URL-validation compatibility fix into Mobo Core and replaces immediate related-archive purging with a persistent deferred interval queue.

It preserves the 10.33.2 cache mutation guard across Sync, Repair, webhook and independent product-mutation workers.

## Implemented

### Product Category URL validation

- Added a scoped `rocket_disable_url_validation` filter.
- The bypass is limited to WooCommerce Product Category requests.
- It recognizes the configured WooCommerce category permalink base with `product-category` fallback.
- Product, page, post, cart, checkout and unrelated taxonomy URLs are not globally exempted.
- The implementation remains PHP 7.4 compatible and does not use `str_starts_with()`.

### Deferred archive purge setting

Replaced legacy boolean:

`mobo_core_cache_purge_archives_on_product_update`

with:

`mobo_core_cache_archive_purge_interval_minutes`

Allowed values:

`0, 5, 10, 15, 20, 25, 30, 45, 60`

`0` means disabled.

### Immediate invalidation retained

The following remain immediate after Mobo-owned product mutations:

- changed Product page-cache URLs;
- WooCommerce product transients;
- WordPress post/object cache invalidation;
- custom exact URLs queued explicitly by Mobo extensions.

### Deferred archive targets

When the interval is enabled, Mobo persists and deduplicates:

- Product Category canonical URLs;
- Product Category hierarchical URLs;
- all Product Category ancestor archive URLs;
- Product Tag URLs;
- Shop archive;
- Home page.

For recategorization, old/new term taxonomy URLs can be captured so stale category membership is invalidated in the deferred window.

### Queue semantics

- Persistent WordPress option queue survives request boundaries.
- First mutation establishes the purge deadline.
- Additional mutations merge targets without sliding the deadline later.
- Queue has bounded URL/product/reason counts.
- A dedicated Mobo lock prevents concurrent queue mutation/processing.
- Failed cache integration execution schedules retry for the next configured window.
- Setting interval to Disabled clears a pending archive queue.
- Reducing an interval may move an existing deadline earlier; increasing it does not postpone an already queued deadline.

### Cron integration

`Mobo_Core_Cron_Runner::run_locked()` calls `Mobo_Core_Cache_Purger::process_due_archive_purge()` so the existing real Mobo cron cadence drives archive invalidation.

### Cache backends

- WP Rocket deferred Archive purge uses exact queued archive URLs and Home-specific purge, avoiding unnecessary second invalidation of already-refreshed Product pages.
- LiteSpeed Cache deferred Archive purge uses exact queued URLs.
- W3 Total Cache deferred Archive purge uses exact queued URLs.
- WP Super Cache uses deferred post-related invalidation because its supported integration in Mobo is post based.
- Redis/Object Cache is not treated as full-page archive cache and consistency invalidation remains immediate.

### Migration

- Legacy OFF → Disabled.
- Legacy ON → 5 minutes.
- Legacy option is removed after migration.
- No custom database table/schema migration is introduced.

## Checks completed

- All PHP files pass `php -l`.
- Standalone cache-purger harness verifies:
  - Product Category URL validation bypass is scoped;
  - hierarchical Product Category URL construction;
  - parent Product Category targets are captured;
  - due queue processing;
  - successful queue cleanup;
  - batching deadline does not slide later;
  - disabling the interval clears a pending queue.
- Plugin header, runtime constant and readme stable tag are aligned to `10.33.4`.
- Manifest is regenerated after all release files are finalized.

## Runtime verification after deployment

1. Remove/deactivate the separate CollectionCase WP Rocket Product Category cache helper after Mobo Core 10.33.4 is active.
2. Set archive interval to 5 minutes for testing.
3. Clear WP Rocket, open the Samsung category normally to build its cache file, and record its modification time.
4. Run a Mobo Sync/Repair/Webhook update for a Samsung product.
5. Confirm the Product cache is invalidated immediately while the Samsung archive cache remains immediately after the mutation.
6. After the configured interval and next real Mobo cron tick, confirm the queued archive cache is invalidated.
7. Open the category normally and confirm WP Rocket rebuilds the hierarchical `index-https.html`.
