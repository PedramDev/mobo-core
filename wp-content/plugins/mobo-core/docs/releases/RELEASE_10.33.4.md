# Mobo Core 10.33.4 — 15-Minute Archive Cache Default

## Change

The default value of `mobo_core_cache_archive_purge_interval_minutes` is now `15`.

Available values remain:

- Disabled (`0`)
- `5`
- `10`
- `15`
- `20`
- `25`
- `30`
- `45`
- `60` minutes

## Upgrade behavior

Existing sites keep their explicitly stored value. This release does not force an existing `0`, `5`, `10`, `20`, etc. to `15`.

For legacy installations that still have the old boolean option:

- legacy ON → `15` minutes
- legacy OFF → disabled

## Rationale

A 15-minute default batches repeated product/category invalidations while keeping product-category, tag, Shop, and Home caches reasonably fresh on sync-heavy WooCommerce stores.
